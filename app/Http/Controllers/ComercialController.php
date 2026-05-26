<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\MlbConfiguracao;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\Servico;
use App\Models\Setor;
use App\Notifications\EmpresaCadastradaNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Módulo Comercial — ponto centralizado de cadastro de novas empresas.
 *
 * O setor Comercial é a única porta de entrada para criação de companies no
 * sistema. Com base nos serviços selecionados (catálogo da Frente A), o
 * controller cria os registros necessários em DB::transaction() e notifica
 * automaticamente os líderes do setor de destino após a confirmação da
 * transação.
 *
 * Phase 14 (Plan 14-04 — Frente B): cadastro passou a aceitar `servicos[]`
 * (lista de servico_id + valor_contratado opcional) em vez do enum legacy
 * preservado via helper estático `servicoDisparaImplementacao()`.
 *
 * Rotas protegidas por middleware 'permission:comercial.cadastrar_empresa'.
 * Admin tem acesso via short-circuit (hasPermission retorna true para admin).
 */
class ComercialController extends Controller
{
    /**
     * Roteia o NOME de um serviço (catálogo Frente A) para o slug de
     * implementação que dispara criação de `mlb_empresas`/`mlb_implementacao`.
     *
     * Helper PURO — testável sem precisar do container Laravel. Substitui o
     *
     * Critério: `str_contains` case-sensitive nos prefixos canônicos. Nomes
     * vindos do catálogo são normalizados em Title Case (D-02), então
     * 'Polos', 'Polos SP', 'Assessoria Premium' batem. Variantes em
     * lowercase ('polos') retornam null por design (não entram no catálogo
     * via fluxo normal).
     *
     * @return 'polos'|'assessoria'|'incubadora'|null
     */
    public static function servicoDisparaImplementacao(string $nome): ?string
    {
        return match (true) {
            str_contains($nome, 'Polos')      => 'polos',
            str_contains($nome, 'Assessoria') => 'assessoria',
            str_contains($nome, 'Incubadora') => 'incubadora',
            default                            => null,
        };
    }

    /**
     * Lista todas as empresas + expõe o formulário de cadastro embutido.
     * Acesso: users com 'comercial.cadastrar_empresa' ou admin.
     *
     * `servicos_contratados[]` (nomes pt-BR) a partir de `contratosServico`
     * para preservar compat com a UI `Comercial/Empresas.jsx` (refator final
     * essa reconstrução vira a única fonte de verdade até o Plan 14-07
     */
    public function empresas()
    {
        abort_unless(
            auth()->user()->hasPermission('comercial.cadastrar_empresa') || auth()->user()->isAdmin(),
            403
        );

        $companies = Company::where('active', true)
            ->with(['contratosServico' => fn($q) => $q->where('ativo', true)->with('servico')])
            ->orderByRaw("CASE WHEN status = 'pendente' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get(['id', 'name', 'cnpj', 'status', 'created_at', 'adman_account_id', 'ml_store_id', 'notes']);

        $companies->transform(function ($c) {
            $nomes = $c->contratosServico
                ->where('ativo', true)
                ->pluck('servico.nome')
                ->filter()
                ->values();

            // servicos_contratados[] — lista nominal já no formato novo
            $c->servicos_contratados = $nomes->toArray();

            return $c;
        });

        $servicosDisponiveis = Servico::where('ativo', true)
            ->orderBy('nome')
            ->get(['id', 'nome', 'valor_padrao', 'tipo_cobranca']);

        return Inertia::render('Comercial/Empresas', [
            'companies' => $companies,
            'servicos_disponiveis' => $servicosDisponiveis,
        ]);
    }

    /**
     * Form GET para cadastro de nova empresa.
     *
     * Phase 14 Plan 14-04: substitui o antigo `index()` (que era apenas um
     * redirect noop) — agora retorna a página `Comercial/NovaEmpresa` com o
     * catálogo ativo de serviços para popular o seletor multi da UI.
     */
    public function create()
    {
        abort_unless(
            auth()->user()->hasPermission('comercial.cadastrar_empresa') || auth()->user()->isAdmin(),
            403
        );

        $servicos = Servico::where('ativo', true)
            ->orderBy('nome')
            ->get(['id', 'nome', 'valor_padrao', 'tipo_cobranca']);

        return Inertia::render('Comercial/NovaEmpresa', [
            'servicos_disponiveis' => $servicos,
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
     * Fluxo (Phase 14 Plan 14-04):
     *   (1) Validação dos campos obrigatórios + `servicos[]` no catálogo
     *   (2) Guard de duplicata por nome (case-insensitive em companies + mlb_empresas)
     *   (3) DB::transaction:
     *       (a) Cria company com status='pendente'
     *       (b) Cria 1 contrato_servico por servico selecionado
     *       (c) Roteamento por nome → cria (opcional) mlb_empresas + mlb_implementacao
     *   (4) Activity log fora da transação
     *   (5) Notificação para líderes do setor de destino fora da transação
     */
    public function store(Request $request)
    {
        abort_unless(
            auth()->user()->hasPermission('comercial.cadastrar_empresa') || auth()->user()->isAdmin(),
            403
        );

        // (1) Validação — aceita servicos[] do catálogo Frente A
        $validated = $request->validate([
            'nome'                        => 'required|string|max:255',
            'cnpj'                        => 'nullable|string|max:20|unique:companies,cnpj',
            'notes'                       => 'nullable|string|max:2000',
            'servicos'                    => 'required|array|min:1',
            'servicos.*.servico_id'       => [
                'required',
                'integer',
                Rule::exists('servicos', 'id')->where('ativo', true),
            ],
            'servicos.*.valor_contratado' => 'nullable|numeric|min:0',
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
        $company         = null;
        $servicosCriados = collect();

        DB::transaction(function () use ($validated, &$company, &$servicosCriados) {
            // (a) Cria company com status pendente
            $company = Company::create([
                'name'   => $validated['nome'],
                'cnpj'  => $validated['cnpj'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => 'pendente',
                'active' => true,
            ]);

            // (b) Cria 1 contrato_servico por servico selecionado
            // O `valor_contratado` cai no `valor_padrao` do catálogo se o cliente
            // não enviar override explícito.
            foreach ($validated['servicos'] as $item) {
                $servico = Servico::find($item['servico_id']);
                if (!$servico) {
                    continue;
                }

                ContratoServico::create([
                    'company_id'       => $company->id,
                    'servico_id'       => $servico->id,
                    'valor_contratado' => isset($item['valor_contratado'])
                        ? (float) $item['valor_contratado']
                        : (float) $servico->valor_padrao,
                    'data_contratacao' => now()->toDateString(),
                    'data_vencimento'  => null,
                    'ativo'            => true,
                ]);

                $servicosCriados->push($servico);
            }

            // (c) Roteamento Phase 13 PRESERVADO — inspeciona NOMES dos serviços
            // (e não slugs legacy) via helper estático.
            $tiposImplementacao = $servicosCriados
                ->map(fn($s) => self::servicoDisparaImplementacao($s->nome))
                ->filter()
                ->unique()
                ->values();

            foreach ($tiposImplementacao as $tipo) {
                if ($tipo === 'polos') {
                    $mlbEmp = MlbEmpresa::create([
                        'nome'       => $company->name,
                        'tipo'       => 'POLO',
                        'projeto'    => 'POLOS',
                        'company_id' => $company->id,
                    ]);
                    $this->criarImplementacaoPolo($mlbEmp);
                } elseif ($tipo === 'assessoria') {
                    MlbEmpresa::create([
                        'nome'       => $company->name,
                        'tipo'       => 'ASSESSORIA',
                        'company_id' => $company->id,
                    ]);
                } elseif ($tipo === 'incubadora') {
                    MlbEmpresa::create([
                        'nome'       => $company->name,
                        'tipo'       => 'INCUBADORA',
                        'company_id' => $company->id,
                    ]);
                }
                // Publicidade/Gestão/Publicação (helper retorna null) — sem
                // mlb_empresas, apenas company (COM-06 preservado).
            }
        });

        // (4) Activity log — fora da transaction para não afetar rollback
        $nomesServicos = $servicosCriados->pluck('nome')->toArray();

        activity('comercial')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $company->name, 'servicos' => $nomesServicos])
            ->log('Empresa cadastrada pelo Comercial: "' . $company->name . '"');

        // (5) Notificação para líderes dos setores de destino — fora da transaction
        $slugsSetores = $servicosCriados
            ->map(fn($s) => $this->slugSetorParaServico($s->nome))
            ->filter()
            ->unique()
            ->values();

        foreach ($slugsSetores as $slug) {
            $setor = Setor::where('slug', $slug)->first();
            if ($setor && $setor->lideres->isNotEmpty()) {
                Notification::send(
                    $setor->lideres,
                    new EmpresaCadastradaNotification(
                        $company->name,
                        $nomesServicos,  // array (não mais string com implode '+')
                        $request->user()->id,
                    ),
                );
            }
        }

        return back()->with('success', 'Empresa "' . $company->name . '" cadastrada com sucesso.');
    }

    /**
     * Atualiza campos básicos de uma empresa existente.
     *
     * Phase 14 Plan 14-04: validação limpa de campos legacy. A gestão de
     * serviços é feita via rotas `/empresas/{company}/contratos-servico`
     * (Frente A). Plan 14-06 dropa as 6 colunas legacy do schema; este
     * método já não persiste nada legacy, então não precisará de mudanças
     * adicionais no Plan 14-06.
     *
     * ainda ser enviados pelo cliente Inertia (Comercial/Empresas.jsx
     * refator final no Plan 14-07), mas o controller os IGNORA silenciosa
     * e seguramente: campos não validados não chegam em `$validated`.
     */
    public function update(Request $request, Company $company)
    {
        abort_unless(
            auth()->user()->hasPermission('comercial.cadastrar_empresa') || auth()->user()->isAdmin(),
            403
        );

        // Validação enxuta: APENAS name/cnpj/notes são persistidos.
        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            'cnpj'  => 'nullable|string|max:20|unique:companies,cnpj,' . $company->id,
            'notes' => 'nullable|string|max:2000',
        ]);

        $company->update($validated);

        activity('comercial')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $company->name])
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
     * Mapeia o NOME de um serviço (catálogo Frente A) para o slug do setor
     *
     * Polos/Assessoria/Publicação → setor 'publicacao'
     * Publicidade                 → setor 'publicidade'
     * Gestão                      → setor 'gestao'
     * Incubadora                  → setor 'incubadora'
     *
     * Outros nomes arbitrários retornam null (não notifica setor algum).
     */
    private function slugSetorParaServico(string $nome): ?string
    {
        return match (true) {
            str_contains($nome, 'Polos')      => 'publicacao',
            str_contains($nome, 'Assessoria') => 'publicacao',
            str_contains($nome, 'Publicação') => 'publicacao',
            str_contains($nome, 'Publicidade') => 'publicidade',
            str_contains($nome, 'Gestão')      => 'gestao',
            str_contains($nome, 'Incubadora')  => 'incubadora',
            default                             => null,
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
