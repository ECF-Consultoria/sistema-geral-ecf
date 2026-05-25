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
     * Exibe o formulário de cadastro de nova empresa.
     * Acesso: users com 'comercial.cadastrar_empresa' ou admin.
     */
    public function index()
    {
        abort_unless(
            auth()->user()->hasPermission('comercial.cadastrar_empresa') || auth()->user()->isAdmin(),
            403
        );

        return Inertia::render('Comercial/NovaEmpresa', []);
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
            'nome'         => 'required|string|max:255',
            'cnpj'         => 'nullable|string|max:20|unique:companies,cnpj',
            'service_type' => 'required|in:polos,assessoria,publicidade,gestao',
            'subtipo'      => 'nullable|in:polos,assessoria',
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
            $nome        = $validated['nome'];
            $cnpj        = $validated['cnpj'] ?? null;
            $serviceType = $validated['service_type'];
            $userId      = $request->user()->id;

            if ($serviceType === 'polos') {
                // POLOS: company + mlb_empresa (tipo=POLO) + mlb_implementacao
                $company = Company::create([
                    'name'         => $nome,
                    'cnpj'         => $cnpj,
                    'service_type' => 'polos',
                    'status'       => 'pendente',
                    'active'       => true,
                ]);

                $empresa = MlbEmpresa::create([
                    'nome'       => $nome,
                    'tipo'       => 'POLO',
                    'projeto'    => 'POLOS',
                    'fase'       => 'M0',
                    'estagio'    => 'Não Listado',
                    'company_id' => $company->id,
                    'criado_por' => $userId,
                ]);

                $this->criarImplementacaoPolo($empresa);

            } elseif ($serviceType === 'assessoria') {
                // ASSESSORIA: company + mlb_empresa (tipo=ASSESSORIA), sem implementacao
                $company = Company::create([
                    'name'         => $nome,
                    'cnpj'         => $cnpj,
                    'service_type' => 'assessoria',
                    'status'       => 'pendente',
                    'active'       => true,
                ]);

                MlbEmpresa::create([
                    'nome'       => $nome,
                    'tipo'       => 'ASSESSORIA',
                    'company_id' => $company->id,
                    'criado_por' => $userId,
                ]);

            } else {
                // PUBLICIDADE / GESTAO: apenas company
                $company = Company::create([
                    'name'         => $nome,
                    'cnpj'         => $cnpj,
                    'service_type' => $serviceType,
                    'status'       => 'pendente',
                    'active'       => true,
                ]);
            }
        });

        // (4) Activity log — fora da transaction para não afetar rollback
        activity('comercial')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $company->name, 'service_type' => $company->service_type])
            ->log('Empresa cadastrada pelo Comercial: "' . $company->name . '"');

        // (5) Notificação para líderes do setor de destino — fora da transaction
        //     (Armadilha 4 do RESEARCH.md — não disparar notificação dentro de transaction)
        $slugSetor = $this->resolverSlugSetor($company->service_type);
        $setor     = Setor::where('slug', $slugSetor)->first();

        if ($setor) {
            $lideres = $setor->lideres;
            if ($lideres->isNotEmpty()) {
                Notification::send(
                    $lideres,
                    new EmpresaCadastradaNotification($company->name, $company->service_type, $request->user()->id)
                );
            }
        }

        return back()->with('success', 'Empresa "' . $company->name . '" cadastrada com sucesso.');
    }

    // ─── Métodos privados ────────────────────────────────────────────────────

    /**
     * Resolve o slug do setor de destino com base no service_type da empresa.
     * Usado para buscar os líderes que devem ser notificados.
     *
     * polos/assessoria → publicacao (gerenciado pelo setor de Publicação)
     * outros           → slug derivado do service_type via Str::slug
     */
    private function resolverSlugSetor(string $serviceType): string
    {
        return match ($serviceType) {
            'polos', 'assessoria' => 'publicacao',
            default               => Str::slug($serviceType),
        };
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
