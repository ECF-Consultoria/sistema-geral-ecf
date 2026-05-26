<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\MlbConfiguracao;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\Setor;
use App\Notifications\EmpresaCadastradaNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Módulo Comercial — ponto centralizado de cadastro de novas empresas.
 *
 * O setor Comercial é a única porta de entrada para criação de companies no
 * sistema. Com base no service_type selecionado, o controller cria os registros
 * necessários em DB::transaction() e notifica automaticamente os líderes do
 * setor de destino após a confirmação da transação.
 *
 * Rotas protegidas por middleware 'permission:comercial.cadastrar_empresa'.
 * Admin tem acesso via short-circuit (hasPermission retorna true para admin).
 */
class ComercialController extends Controller
{
    /**
     * Lista todas as empresas + expõe o formulário de cadastro embutido.
     * Acesso: users com 'comercial.cadastrar_empresa' ou admin.
     */
    public function empresas()
    {
        abort_unless(
            auth()->user()->hasPermission('comercial.cadastrar_empresa') || auth()->user()->isAdmin(),
            403
        );

        $companies = Company::where('active', true)
            ->orderByRaw("CASE WHEN status = 'pendente' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get(['id', 'name', 'cnpj', 'service_type', 'status', 'created_at', 'adman_account_id', 'ml_store_id', 'notes']);

        return Inertia::render('Comercial/Empresas', [
            'companies' => $companies,
        ]);
    }

    /**
     * @deprecated Mantido apenas para compatibilidade de redirect; use empresas().
     */
    public function index()
    {
        return redirect()->route('comercial.empresas');
    }

    /**
     * Processa o cadastro centralizado de uma nova empresa.
     *
     * Fluxo:
     *   (1) Validação dos campos obrigatórios
     *   (2) Guard de duplicata por nome (case-insensitive em companies + mlb_empresas)
     *   (3) DB::transaction com criação atômica por service_type
     *   (4) Activity log fora da transação
     *   (5) Notificação para líderes do setor de destino fora da transação
     */
    public function store(Request $request)
    {
        abort_unless(
            auth()->user()->hasPermission('comercial.cadastrar_empresa') || auth()->user()->isAdmin(),
            403
        );

        // (1) Validação
        $validated = $request->validate([
            'nome'           => 'required|string|max:255',
            'cnpj'           => 'nullable|string|max:20|unique:companies,cnpj',
            'notes'          => 'nullable|string|max:2000',
            'service_type'   => 'required|array|min:1',
            'service_type.*' => 'in:publicacao,publicidade,gestao,incubadora,mentoria,implantacao',
        ]);

        // (2) Guard de duplicata — verifica companies.name e mlb_empresas.nome
        $existeEmCompanies  = Company::whereRaw('LOWER(name) = LOWER(?)', [$validated['nome']])->exists();
        $existeEmMlbEmpresa = MlbEmpresa::whereRaw('LOWER(nome) = LOWER(?)', [$validated['nome']])->exists();

        if ($existeEmCompanies || $existeEmMlbEmpresa) {
            throw ValidationException::withMessages([
                'nome' => 'Já existe uma empresa com o nome "' . $validated['nome'] . '" no sistema.',
            ]);
        }

        // (3) Criação atômica via DB::transaction
        $company = null;

        DB::transaction(function () use ($validated, $request, &$company) {
            $nome   = $validated['nome'];
            $cnpj   = $validated['cnpj'] ?? null;
            $types  = $validated['service_type']; // array
            $userId = $request->user()->id;

            $company = Company::create([
                'name'         => $nome,
                'cnpj'         => $cnpj,
                'notes'        => $validated['notes'] ?? null,
                'service_type' => $types,
                'status'       => 'pendente',
                'active'       => true,
            ]);
        });

        // (4) Activity log — fora da transaction para não afetar rollback
        activity('comercial')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $company->name, 'service_type' => $company->service_type])
            ->log('Empresa cadastrada pelo Comercial: "' . $company->name . '"');

        // (5) Notificação para líderes de cada setor de destino — fora da transaction
        foreach ($this->resolverSlugsSetores($company->service_type ?? []) as $slug) {
            $setor = Setor::where('slug', $slug)->first();
            if ($setor) {
                $lideres = $setor->lideres;
                if ($lideres->isNotEmpty()) {
                    Notification::send(
                        $lideres,
                        new EmpresaCadastradaNotification(
                            $company->name,
                            implode('+', $company->service_type ?? []),
                            $request->user()->id
                        )
                    );
                }
            }
        }

        return back()->with('success', 'Empresa "' . $company->name . '" cadastrada com sucesso.');
    }

    /**
     * Atualiza campos de uma empresa existente, incluindo service_type.
     *
     * Ao mudar para polos/assessoria, cria automaticamente o registro mlb_empresa
     * correspondente caso ainda não exista (evita inconsistência sem forçar o
     * usuário a recriar a empresa).
     */
    public function update(Request $request, Company $company)
    {
        abort_unless(
            auth()->user()->hasPermission('comercial.cadastrar_empresa') || auth()->user()->isAdmin(),
            403
        );

        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'cnpj'           => 'nullable|string|max:20|unique:companies,cnpj,' . $company->id,
            'notes'          => 'nullable|string|max:2000',
            'service_type'   => 'nullable|array',
            'service_type.*' => 'in:publicacao,polos,assessoria,publicidade,gestao,incubadora,mentoria,implantacao',
        ]);

        $novosTipos = $validated['service_type'] ?? [];

        $company->update($validated);

        activity('comercial')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $company->name, 'service_type' => $novosTipos])
            ->log('Empresa editada pelo Comercial: "' . $company->name . '"');

        return back()->with('success', 'Empresa "' . $company->name . '" atualizada com sucesso.');
    }

    /**
     * Desativa uma empresa (active = false).
     * Não exclui fisicamente para preservar os registros relacionados (mlb_empresas,
     * sugadores, etc.) e permitir recuperação via admin se necessário.
     */
    public function destroy(Request $request, Company $company)
    {
        abort_unless(
            auth()->user()->hasPermission('comercial.cadastrar_empresa') || auth()->user()->isAdmin(),
            403
        );

        $nome = $company->name;
        $company->update(['active' => false]);

        activity('comercial')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $nome])
            ->log('Empresa removida pelo Comercial: "' . $nome . '"');

        return back()->with('success', 'Empresa "' . $nome . '" removida.');
    }

    // ─── Métodos privados ────────────────────────────────────────────────────

    /**
     * Resolve os slugs dos setores de destino a partir de um array de tipos.
     * Notifica um setor por tipo de serviço, sem duplicatas.
     *
     * polos/assessoria → publicacao
     * publicidade      → publicidade
     * gestao           → gestao
     *
     * @param  array<string>  $types
     * @return array<string>
     */
    private function resolverSlugsSetores(array $types): array
    {
        $slugs = [];
        if (array_intersect($types, ['polos', 'assessoria', 'publicacao'])) {
            $slugs[] = 'publicacao';
        }
        foreach (array_diff($types, ['polos', 'assessoria', 'publicacao']) as $type) {
            $slugs[] = Str::slug($type);
        }
        return array_unique($slugs);
    }

    /**
     * Cria uma MlbImplementacao para uma empresa POLO com os dados padrão
     * configurados em MlbConfiguracao::implementacaoPadroes().
     *
     * Lógica extraída de MlbImplementacaoController::criar() (linhas 192-207)
     * para reutilização no fluxo do Comercial sem duplicação de código (D-20).
     *
     * @param MlbEmpresa $empresa Empresa POLO recém-criada.
     * @return MlbImplementacao
     */
    private function criarImplementacaoPolo(MlbEmpresa $empresa): MlbImplementacao
    {
        $dados = MlbImplementacao::dadosPadrao();
        $p     = MlbConfiguracao::implementacaoPadroes();

        if ($p['tutorial_intro']) {
            $dados['tutorial_intro'] = $p['tutorial_intro'];
        }
        if (!empty($p['tutoriais'])) {
            $dados['tutoriais'] = array_merge($dados['tutoriais'], $p['tutoriais']);
        }
        if (!empty($p['links_admin_extra'])) {
            $dados['links_admin']['programa_decola'] = $p['links_admin_extra']['programa_decola'] ?? '';
        }

        return MlbImplementacao::create([
            'empresa_id' => $empresa->id,
            'token'      => Str::random(48),
            'dados'      => $dados,
        ]);
    }
}
