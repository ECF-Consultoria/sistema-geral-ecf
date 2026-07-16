<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\NpsEmailEnvio;
use App\Models\NpsPerguntaCustomizada;
use App\Models\NpsRespostaCustomizada;
use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use App\Models\NpsSurvey;
use App\Models\NpsSurveyEvent;
use App\Services\Nps\NpsTemplateService;
use App\Support\NpsTextRenderer;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Controller das pesquisas NPS.
 *
 * Phase 31 (Plan 02 + Plan 05) — Reescrito para a escala 1-5 com 3 dimensões
 * (estrategista / analista / empresa) e payload do form público com
 * `tem_analista` para a UI decidir mostrar/ocultar o campo de analista
 * (caso mentoria pura). O endpoint `nps.generate` (manual) preserva
 * `auto_generated=false` para back-compat (REQ-31-08).
 *
 * index() em Plan 05 passou a entregar: filtro por mês (default = mês corrente,
 * usando `month_reference` quando auto e `created_at` como fallback para
 * surveys manuais), 3 cards de média do mês (estrategista / analista / empresa),
 * série de 12 meses para LineChart e lista paginada das respostas.
 */
class NpsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // ─── Filtro de mês (default = mês corrente) ──────────────────────────
        // Aceita ?mes=YYYY-MM via query string. Inválido cai no mês atual.
        $mesFiltro = $request->input('mes', now()->format('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', (string) $mesFiltro)) {
            $mesFiltro = now()->format('Y-m');
        }
        $mesInicio = \Carbon\Carbon::parse($mesFiltro . '-01')->startOfMonth();
        $mesFim    = $mesInicio->copy()->endOfMonth();

        // ─── Quick task 260612-flt — filtros adicionais ─────────────────────
        // Empresa: filtra por company_id direto. Estrategista/Analista: filtra
        // por surveys cuja empresa tem o user atribuído no pivot company_users
        // com role correspondente (estrategista | consultor para analista).
        // Aplicam tanto na lista paginada quanto nos cards de média e na serie
        // 12 meses — coerencia visual entre todos os blocos da pagina.
        $empresaId      = $request->integer('empresa_id') ?: null;
        $estrategistaId = $request->integer('estrategista_id') ?: null;
        $analistaId     = $request->integer('analista_id') ?: null;
        // Ajuste 2026-07-13 · por padrão o dashboard NPS conta apenas o modelo
        // PRINCIPAL (is_default) — decisão de produto: só o principal alimenta
        // métricas. O <select> "Todos os modelos" envia template_id=__todos__
        // para ver tudo; um id numérico filtra por aquele modelo específico.
        // Ausência do parâmetro (carga inicial) cai no principal.
        $templateParam = $request->input('template_id');
        $templateTodos = ($templateParam === '__todos__');
        if ($templateTodos) {
            $templateId = null;                                   // sem filtro — todos
        } elseif (is_numeric($templateParam)) {
            $templateId = (int) $templateParam;                   // modelo específico
        } else {
            $templateId = \App\Models\NpsTemplate::principalId();  // default — principal
        }

        $aplicarFiltrosSurveys = function ($query) use ($empresaId, $estrategistaId, $analistaId, $templateId) {
            if ($empresaId) {
                $query->where('company_id', $empresaId);
            }
            if ($templateId) {
                $query->where('template_id', $templateId);
            }
            if ($estrategistaId) {
                $query->whereHas('company.users', function ($q) use ($estrategistaId) {
                    $q->where('users.id', $estrategistaId)
                      ->where('company_users.role', 'estrategista');
                });
            }
            if ($analistaId) {
                $query->whereHas('company.users', function ($q) use ($analistaId) {
                    $q->where('users.id', $analistaId)
                      ->where('company_users.role', 'consultor');
                });
            }
        };

        // ─── Audiência: surveys do mês selecionado ───────────────────────────
        // Surveys auto-geradas usam month_reference (semântica D-specifics).
        // Surveys manuais (month_reference=null) caem no mês via created_at.
        //
        // Bugfix 2026-07-08 — eager load expandido para `response.answers` +
        // `response.template.questions` (dual-path v15/legacy). Respostas v15
        // gravam apenas em `nps_response_answers` (snapshot per-row); as
        // colunas legadas `score_*` de `nps_responses` ficam null. Sem carregar
        // answers, os cards mostram 0 e a lista mostra só o nome do respondente.
        // Quick task 260715-pu0 — eager load das atribuições congeladas
        // (Fase 79, `nps_score_assignments`) + o `user` de cada uma, para o
        // modal de detalhe mostrar QUEM recebeu a nota. 2 queries a mais no
        // total da página (assignments por whereIn dos response_ids + users
        // por whereIn dos user_ids), não N+1 — a lista é paginada em 20.
        $baseQuery = NpsSurvey::with([
                'company',
                'generatedBy',
                'response.respostasCustomizadas',
                'response.answers',
                'response.survey.template',
                'response.scoreAssignments.user',
            ])
            ->where(function ($q) use ($mesInicio, $mesFim) {
                $q->whereBetween('month_reference', [$mesInicio->toDateString(), $mesFim->toDateString()])
                  ->orWhere(function ($qq) use ($mesInicio, $mesFim) {
                      $qq->whereNull('month_reference')
                         ->whereBetween('created_at', [$mesInicio, $mesFim]);
                  });
            })
            ->orderBy('created_at', 'desc');

        if (!$user->isAdmin()) {
            $companyIds = $user->companies()->pluck('companies.id');
            $baseQuery->whereIn('company_id', $companyIds);
        }

        // Quick task 260612-flt — filtros empresa/estrategista/analista.
        $aplicarFiltrosSurveys($baseQuery);

        // Bugfix 2026-07-08 — helper de leitura dual-path com arredondamento.
        // Para surveys v15 (survey.template_id != null): usa NpsScoreCalculator
        // sobre os answers snapshot (Phase 69-02). Sempre arredonda pra 2 casas
        // ANTES de mandar pro front (evita "3.6666666666666665" na UI).
        // Para surveys legacy: lê a coluna score_$dim direto do NpsResponse.
        $calculator = app(\App\Services\Nps\NpsScoreCalculator::class);
        $notaDe = function ($response, string $dimensao) use ($calculator) {
            if (! $response) {
                return null;
            }
            if ($response->survey && $response->survey->template_id !== null) {
                $v = $calculator->compute($response, $dimensao);
                return $v === null ? null : round((float) $v, 2);
            }
            $col = 'score_' . $dimensao;
            $v = $response->$col;
            return $v === null ? null : round((float) $v, 2);
        };

        // Bugfix 2026-07-08 — modal "Ver respostas" agora mostra TODAS as
        // answers do template v15 (as 16 perguntas, não só as de dimensão
        // 'geral'). O admin queixou: "não veio com todos os campos, na tela de
        // configura mostra que tem 16 campos existentes". Mantém ordem original
        // (por template_question_id) + concatena as respostas_customizadas v13
        // para compat com surveys legacy pré-Phase 68.
        $extrasDe = function ($response) {
            if (! $response) {
                return collect();
            }
            $legacy = $response->respostasCustomizadas->map(fn($r) => [
                'id'             => 'legacy_' . $r->id,
                'pergunta_id'    => $r->pergunta_id,
                'pergunta_texto' => $r->pergunta_texto_snapshot,
                'dimensao'       => 'geral',
                'tipo'           => $r->tipo_snapshot,
                'valor'          => $r->valor,
            ]);
            // v15: TODAS as answers do template (ordena por id do
            // template_question para preservar sequência da configuração).
            // 2026-07-08: incluir `peso` para o modal exibir "Label = peso".
            // 2026-07-13: answers texto_livre (option_peso_snapshot NULL)
            // reportam `tipo=texto_livre` + `valor` do `comentario` — o
            // modal renderiza como texto puro (Nps/Index RespostaExtraValor
            // cai no fallback).
            $v15 = $response->answers
                ->sortBy('template_question_id')
                ->map(function ($a) {
                    $ehTextoLivre = $a->option_peso_snapshot === null;
                    return [
                        'id'             => 'v15_' . $a->id,
                        'pergunta_id'    => $a->template_question_id,
                        'pergunta_texto' => $a->question_texto_snapshot,
                        'dimensao'       => $a->question_dimensao_snapshot,
                        'tipo'           => $ehTextoLivre ? 'texto_livre' : 'opcoes',
                        'valor'          => $ehTextoLivre
                            ? (string) ($a->comentario ?? '')
                            : $a->option_label_snapshot,
                        'peso'           => $a->option_peso_snapshot,
                    ];
                });
            return $legacy->concat($v15)->values();
        };

        // Quick task 260715-pu0 — nomes dos responsáveis (analista/estrategista)
        // que receberam a nota individual daquela resposta, direto de
        // `nps_score_assignments` (Fase 79, congelado no momento da resposta).
        //
        // PROIBIDO ler `$company->consultor`/`$company->estrategista`/
        // `consultorDoServico()`/`estrategistaDoServico()` aqui: essas relações
        // do pivot VIVO estão quebradas desde a Fase 76 (empresa pode ter 2
        // linhas do mesmo `role` com `servico_id` diferentes, e um `->first()`
        // pega a mais antiga — bug real em prod na tela /companies). A
        // atribuição congelada já resolveu isso por serviço no momento do
        // submit; aqui é leitura pura, sem fallback.
        //
        // Sem atribuição (survey legado, pré-Fase 79) → listas vazias, nunca
        // null — a UI não precisa de guard de tipo.
        $mapaDimensaoRole = [
            'consultor'    => 'analista',
            'estrategista' => 'estrategista',
        ];
        $responsaveisDe = function ($response) use ($mapaDimensaoRole) {
            $resultado = ['analista' => [], 'estrategista' => []];
            if (! $response) {
                return $resultado;
            }

            $vistos = ['analista' => [], 'estrategista' => []];
            foreach ($response->scoreAssignments as $a) {
                $chave = $mapaDimensaoRole[$a->role] ?? null;
                if ($chave === null || ! $a->user) {
                    continue; // role fora do mapa, ou usuário deletado
                }
                // Dedup por user_id — um template que cobre 2 serviços com o
                // mesmo responsável não deve repetir o nome na tela.
                if (in_array($a->user_id, $vistos[$chave], true)) {
                    continue;
                }
                $vistos[$chave][] = $a->user_id;
                $resultado[$chave][] = $a->user->name;
            }

            return $resultado;
        };

        $surveys = $baseQuery->paginate(20)->withQueryString()->through(fn($s) => [
            'id'                 => $s->id,
            'token'              => $s->token,
            'company_name'       => $s->company->name,
            'company_id'         => $s->company_id,
            'status'             => $s->status,
            'auto_generated'     => (bool) $s->auto_generated,
            'generated_by'       => $s->generatedBy?->name,
            'created_at'         => $s->created_at->format('d/m/Y H:i'),
            'expires_at'         => $s->expires_at?->format('d/m/Y'),
            'completed_at'       => $s->completed_at?->format('d/m/Y H:i'),
            'score_estrategista' => $notaDe($s->response, 'estrategista'),
            'score_analista'     => $notaDe($s->response, 'analista'),
            'score_empresa'      => $notaDe($s->response, 'empresa'),
            // Quick task 260715-pu0 — nomes de quem recebeu a nota (Fase 79).
            'responsaveis'       => $responsaveisDe($s->response),
            'respondent'         => $s->response?->respondent_name,
            'comment'            => $s->response?->comment,
            'link'               => route('nps.respond', $s->token),

            // Phase 33 Plan 33-04 + Bugfix 2026-07-08 — dual-path para o modal.
            'respostas_customizadas' => $extrasDe($s->response)->all(),
        ]);

        // ─── 3 cards de média (somente respostas do mês filtrado) ────────────
        // Bugfix 2026-07-08 — dual-path: como AVG(score_*) do SQL ignora
        // respostas v15 (colunas null), calculamos em PHP iterando os responses
        // e usando NpsScoreCalculator quando template_id != null.
        // Trade-off perf: ~150 responses/mês = O(150) em memória — aceitável.
        $responsesFilter = function ($q) use ($mesInicio, $mesFim, $user, $aplicarFiltrosSurveys) {
            $q->where(function ($qq) use ($mesInicio, $mesFim) {
                $qq->whereBetween('month_reference', [$mesInicio->toDateString(), $mesFim->toDateString()])
                   ->orWhere(function ($qqq) use ($mesInicio, $mesFim) {
                       $qqq->whereNull('month_reference')
                           ->whereBetween('created_at', [$mesInicio, $mesFim]);
                   });
            });
            if (!$user->isAdmin()) {
                $q->whereIn('company_id', $user->companies()->pluck('companies.id'));
            }
            // Quick task 260612-flt — propaga filtros para os cards.
            $aplicarFiltrosSurveys($q);
        };

        $responsesMes = NpsResponse::query()
            ->with(['survey', 'answers'])
            ->whereHas('survey', $responsesFilter)
            ->get();

        $agregarMedia = function ($responses, string $dimensao) use ($notaDe) {
            $notas = $responses->map(fn($r) => $notaDe($r, $dimensao))
                ->filter(fn($n) => $n !== null);
            return [
                'media' => $notas->isEmpty() ? 0 : round((float) $notas->avg(), 2),
                'total' => $notas->count(),
            ];
        };

        $cards = [
            'estrategista' => $agregarMedia($responsesMes, 'estrategista'),
            'analista'     => $agregarMedia($responsesMes, 'analista'),
            'empresa'      => $agregarMedia($responsesMes, 'empresa'),
        ];

        // ─── Série 12 meses para o LineChart ─────────────────────────────────
        // Bugfix 2026-07-08 — 1 query por mês carregando responses com answers,
        // agregação em PHP via helper dual-path. ~150 responses/mês × 12 = 1.8k
        // objetos em memória durante o request — dentro do budget.
        $serieMeses = [];
        $inicio12m  = now()->startOfMonth()->subMonths(11);
        for ($i = 0; $i < 12; $i++) {
            $m    = $inicio12m->copy()->addMonths($i);
            $mFim = $m->copy()->endOfMonth();

            $responsesM = NpsResponse::query()
                ->with(['survey', 'answers'])
                ->whereHas('survey', function ($qq) use ($m, $mFim, $user, $aplicarFiltrosSurveys) {
                    $qq->where(function ($qqq) use ($m, $mFim) {
                        $qqq->whereBetween('month_reference', [$m->toDateString(), $mFim->toDateString()])
                            ->orWhere(function ($qqqq) use ($m, $mFim) {
                                $qqqq->whereNull('month_reference')
                                     ->whereBetween('created_at', [$m, $mFim]);
                            });
                    });
                    if (!$user->isAdmin()) {
                        $qq->whereIn('company_id', $user->companies()->pluck('companies.id'));
                    }
                    // Quick task 260612-flt — propaga filtros na serie 12m.
                    $aplicarFiltrosSurveys($qq);
                })
                ->get();

            $serieMeses[] = [
                'mes'          => $m->locale('pt_BR')->isoFormat('MMM/YY'), // ex: 'jun./26'
                'mes_iso'      => $m->format('Y-m'),
                'estrategista' => $agregarMedia($responsesM, 'estrategista')['media'],
                'analista'     => $agregarMedia($responsesM, 'analista')['media'],
                'empresa'      => $agregarMedia($responsesM, 'empresa')['media'],
            ];
        }

        $companies = $user->isAdmin()
            ? Company::where('active', true)->get(['id', 'name'])
            : $user->companies()->get(['companies.id', 'companies.name']);

        // Quick task 260612-flt — listas para os Selects de filtro.
        // Estrategistas/analistas: users active que TÊM ao menos 1 empresa atribuída
        // pelo pivot role correspondente (não pega quem nunca foi vinculado).
        $estrategistas = \App\Models\User::where('active', true)
            ->whereHas('companies', fn($q) => $q->where('company_users.role', 'estrategista'))
            ->orderBy('name')
            ->get(['id', 'name']);
        $analistas = \App\Models\User::where('active', true)
            ->whereHas('companies', fn($q) => $q->where('company_users.role', 'consultor'))
            ->orderBy('name')
            ->get(['id', 'name']);

        // UAT 2026-07-07: só admin ou líder pode filtrar por estrategista/
        // analista específicos — analista/estrategista comum já vê apenas
        // NPS da sua carteira (linha 92-95), sem necessidade do filtro.
        $podeFiltrarPorPessoa = $user->isAdmin() || $user->isLider();

        // Ajuste 2026-07-13 · lista de modelos NPS pro <select> de filtro.
        // Só templates ATIVOS entram na lista de filtro (arquivados/desativados
        // aparecem só se estiverem sendo filtrados no momento — a UI lida com
        // isso via seleção controlada). Order alfabético pra facilitar busca.
        $templates = \App\Models\NpsTemplate::query()
            ->where('active', true)
            ->orderBy('nome')
            ->get(['id', 'nome']);

        return Inertia::render('Nps/Index', [
            'surveys'                => $surveys,
            'companies'              => $companies,
            'estrategistas'          => $estrategistas,
            'analistas'              => $analistas,
            'templates'              => $templates,
            'pode_filtrar_por_pessoa' => $podeFiltrarPorPessoa,
            'cards'          => $cards,
            'serie_12m'      => $serieMeses,
            'mes_filtro'     => $mesFiltro,
            'filtros'        => [
                'empresa_id'      => $empresaId,
                'estrategista_id' => $estrategistaId,
                'analista_id'     => $analistaId,
                'template_id'     => $templateId,
                // 2026-07-13 · estado do filtro de modelo pro <select> refletir
                // "Todos os modelos" vs. principal (default) vs. específico.
                'template_todos'  => $templateTodos,
            ],
            'principal_template_id' => \App\Models\NpsTemplate::principalId(),
        ]);
    }

    /**
     * Geração manual de link NPS (fluxo legacy preservado — REQ-31-08).
     *
     * Surveys criadas aqui ficam com `auto_generated=false` e
     * `month_reference=null`, distinguindo-as das surveys mensais
     * automatizadas (Plan 02 / Plan 04). `expires_at` continua em 7 dias
     * para manuais (vs. 30 dias para automáticas — D-12).
     */
    public function generate(Request $request, NpsTemplateService $templateService)
    {
        $user = $request->user();
        $data = $request->validate([
            'company_id'  => 'required|exists:companies,id',
            // Ajuste 2026-07-13 · admin pode escolher qual modelo NPS o link
            // vai usar. Nullable — se ausente, cai no resolveForCompany
            // (default por priority/serviço) preservando o comportamento
            // original.
            'template_id' => 'nullable|integer|exists:nps_templates,id',
        ]);

        // Auth: admin OR user com a empresa em qualquer role no pivot
        // company_users (inclui estrategista, consultor, mentor). Padrão
        // consolidado da Phase 62 — superset seguro que preserva REQ-31-08
        // (compat com generate manual atual) E admite estrategista da
        // carteira sem restringir os demais roles historicamente autorizados.
        if (!$user->isAdmin()) {
            $allowed = $user->companies()->pluck('companies.id');
            if (!$allowed->contains($data['company_id'])) {
                abort(403);
            }
        }

        // Phase 69 NPS-B-01: resolve o template NPS aplicável à empresa
        // (priority DESC → is_default fallback). O survey nasce já com
        // template_id populado — sem este bind, a dedup unique parcial
        // (Plan 68-04) e o snapshot per-row (Phase 68) ficam degradados.
        //
        // Quick task 260715-ndo (Bug A, ativo em produção): o modal
        // "Gerar link" (Fase 81) é modelo-first e já oferece o seletor para
        // QUALQUER usuário autorizado (não só admin) — o gate isAdmin() que
        // existia aqui era mentiroso: a UI dizia "escolha o modelo" e o
        // servidor, para não-admin, IGNORAVA silenciosamente a escolha e
        // caía no auto-resolve por priority. Resultado medido em produção:
        // 15 links do modelo errado gerados por não-admin (2 já respondidos,
        // notas atribuídas ao responsável do setor errado).
        //
        // Decisão de produto: empresa com múltiplos serviços (ex.: ML +
        // Shopee) deve poder gerar um NPS por serviço, cada um endereçado ao
        // responsável do seu setor — espelhando o disparo mensal multi-modelo
        // (nps:disparar-mensal). Por isso o override agora vale para
        // qualquer usuário que já passou pela autorização de empresa acima
        // (linhas anteriores, inalteradas) — só falta validar que o modelo
        // pedido REALMENTE se aplica a esta empresa (defesa em profundidade:
        // o modal já filtra por `empresasElegiveis`, mas se um dia divergir,
        // sem esta validação o servidor geraria um NPS Shopee para empresa
        // sem Shopee e a nota viraria órfã — NpsSnapshotService só loga
        // "responsável faltante" e segue).
        $company = Company::findOrFail($data['company_id']);

        if (!empty($data['template_id'])) {
            // Busca sem filtrar por `active` na query — precisamos distinguir
            // "não existe" (já coberto por exists:nps_templates,id do
            // validate acima) de "existe mas está inativo", para devolver a
            // mensagem certa em cada caso.
            $template = \App\Models\NpsTemplate::findOrFail($data['template_id']);

            if (!$template->active) {
                return back()->with('error', 'Este modelo de NPS está desativado e não pode gerar novos links.');
            }

            // Espelha os 2 ramos de `NpsTemplateController::empresasElegiveis`
            // (NÃO 3 — não existe rejeição por `active` da empresa aqui):
            //   (a) modelo COM serviços cobertos → exige contrato ATIVO da
            //       empresa em pelo menos um deles;
            //   (b) modelo SEM serviços cobertos (pivot vazio, ex.: NPS
            //       Padrão) → aceito para qualquer empresa (fallback).
            $servicoIds = $template->servicos()->pluck('servicos.id');
            if ($servicoIds->isNotEmpty()) {
                $temContratoAtivo = $company->contratosServico()
                    ->active()
                    ->whereIn('servico_id', $servicoIds)
                    ->exists();

                if (!$temContratoAtivo) {
                    return back()->with('error', 'Este modelo de NPS não se aplica a esta empresa — ele cobre serviços que a empresa não tem contratados no momento.');
                }
            }
        } else {
            // Sem template_id (ex.: consumidor antigo/API) → auto-resolve
            // por empresa, comportamento original preservado.
            $template = $templateService->resolveForCompany($company);
        }

        $survey = NpsSurvey::create([
            'token'          => Str::uuid()->toString(),
            'company_id'     => $data['company_id'],
            'generated_by'   => $user->id,
            'expires_at'     => now()->addDays(7),
            'status'         => 'pending',
            // REQ-31-08: explicita auto_generated=false em surveys manuais
            // para o admin filtrar "manual vs automatico" na UI (Plan 31-04).
            'auto_generated' => false,
            // Phase 69 NPS-B-01 — template resolvido via NpsTemplateService.
            'template_id'    => $template->id,
            // month_reference fica null para manuais (D-12) — só surveys
            // mensais automatizadas carregam o mês de referência semântico.
        ]);

        return back()->with([
            'success'  => 'Link NPS gerado com sucesso.',
            'nps_link' => route('nps.respond', $survey->token),
        ]);
    }

    /**
     * Form público de resposta — recebe o token e renderiza a UI Nps/Respond.jsx.
     *
     * Payload Inertia em Phase 31 (D-07): expõe `estrategista_name`,
     * `analista_name` (nullable) e `tem_analista` (bool). A UI usa
     * `tem_analista` para decidir se mostra o campo de analista (mentoria
     * pura omite). Chaves legacy `mentor_name`/`consultant_name` foram
     * removidas — Plan 31-03 reescreve Respond.jsx para consumir as
     * chaves novas.
     */
    public function respond(Request $request, string $token)
    {
        // Phase 71 Plan 01 — eager-load `template.questions.options` na MESMA
        // query do survey para o form dinâmico v15.0 (REQ NPS-D-01). Relations
        // `NpsTemplate::questions()` e `NpsTemplateQuestion::options()` (Phase 68)
        // já vêm ordenadas por (ordem ASC, id ASC) — nenhuma reordenação extra
        // no controller. Surveys legacy (template_id NULL) ficam com
        // $survey->template = null e caem no fluxo Phase 33 abaixo.
        $survey = NpsSurvey::with([
                'company',
                'generatedBy',
                'template.questions.options',
            ])
            ->where('token', $token)
            ->firstOrFail();

        // Phase 94 AB-94-1 — rastro de abertura (roda em TODO GET, mesmo
        // completed/expired: reaberturas de link vencido/já respondido são
        // sinal técnico relevante para a Fase 95). first_opened_at NUNCA é
        // sobrescrito (decisão travada do CONTEXT) — sempre `??` contra o
        // valor já persistido.
        $survey->update([
            'first_opened_at' => $survey->first_opened_at ?? now(),
            'last_opened_at'  => now(),
            'open_count'      => $survey->open_count + 1,
            'open_ip_address' => $request->ip(),
            'open_user_agent' => $request->userAgent(),
        ]);

        NpsSurveyEvent::create([
            'survey_id'  => $survey->id,
            'event_type' => NpsSurveyEvent::TYPE_OPENED,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'user_id'    => auth()->id(), // nullable — sessão interna coexistente (Regra 4 / Fase 95)
            'metadata'   => ['first_open' => $survey->open_count === 1],
        ]);

        if ($survey->status === 'completed') {
            return Inertia::render('Nps/AlreadyCompleted');
        }

        if ($survey->isExpired()) {
            $survey->update(['status' => 'expired']);

            // Phase 94 AB-94-3 — único lugar do codebase que transiciona o
            // status para 'expired' (expiração é lazy, não há job agendado).
            NpsSurveyEvent::create([
                'survey_id'  => $survey->id,
                'event_type' => NpsSurveyEvent::TYPE_EXPIRED,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'user_id'    => auth()->id(),
                'metadata'   => null,
            ]);

            return Inertia::render('Nps/Expired');
        }

        $estrategista = $survey->company->users()->wherePivot('role', 'estrategista')->first();
        $analista     = $survey->company->users()->wherePivot('role', 'consultor')->first();

        $estrategistaNome = $estrategista?->name;
        $analistaNome     = $analista?->name;
        $temAnalista      = $analista !== null;

        // ─── Phase 32 Plan 03: textos dinâmicos da página ────────────────────
        // Carrega os 6 textos das perguntas/labels editáveis em /nps/configuracao
        // e substitui os placeholders ANTES de mandar pro front (mesmo padrão do
        // NpsDispararMensal/NpsMonthlyMail — Mailable burro, render no backend).
        //
        // `mes_referencia` e `bloco_analista` não fazem sentido na página de
        // resposta (só no email), mas passamos string vazia para não deixar o
        // placeholder cru caso o admin tenha colocado algum por engano.
        $textosBrutos = NpsTextRenderer::getTextos();
        $varsPagina   = [
            'nome_estrategista' => $estrategistaNome ?? '',
            'nome_analista'     => $analistaNome ?? '',
            'nome_empresa'      => $survey->company->name,
            'mes_referencia'    => '',
            'bloco_analista'    => '',
        ];

        $textosRender = [
            'perg_estrategista'           => NpsTextRenderer::render($textosBrutos['perg_estrategista'], $varsPagina),
            'perg_analista'               => NpsTextRenderer::render($textosBrutos['perg_analista'], $varsPagina),
            'perg_empresa'                => NpsTextRenderer::render($textosBrutos['perg_empresa'], $varsPagina),
            'perg_comentario_label'       => NpsTextRenderer::render($textosBrutos['perg_comentario_label'], $varsPagina),
            'perg_comentario_placeholder' => NpsTextRenderer::render($textosBrutos['perg_comentario_placeholder'], $varsPagina),
            'perg_nome_label'             => NpsTextRenderer::render($textosBrutos['perg_nome_label'], $varsPagina),
        ];

        // ─── Phase 33 D-07 — perguntas customizadas ativas ───────────────────
        // Carrega as perguntas extras ativas para o front renderizar APOS as 3
        // perguntas fixas e ANTES do comentario (ordem D-03). Ordenacao por
        // `ordem` ASC com fallback em `id` ASC (criadas mais cedo primeiro).
        $perguntasExtras = NpsPerguntaCustomizada::where('ativa', true)
            ->orderBy('ordem')
            ->orderBy('id')
            ->get(['id', 'texto', 'tipo', 'opcoes', 'obrigatorio']);

        // ─── Phase 71 Plan 01 — Payload dinâmico v15.0 ────────────────────────
        // Se o survey tem template associado (Phase 68/69), monta o shape que o
        // `PreviewFormulario.jsx` do Phase 70-05 consome — `Respond.jsx` (Plan
        // 71-02) reaproveita esse mesmo componente puro e envolve-o com o hook
        // de submit.
        //
        // Surveys sem template_id (legacy pre-migration Phase 68 ou dispatch
        // manual antigo) recebem `$templatePayload = null` — `Respond.jsx` cai
        // no fluxo Phase 33 (form fixo com perguntas legadas + perguntas_extras).
        //
        // IMPLICAÇÃO CRÍTICA (research §5 + Phase 69-03): cada option DEVE
        // conter `id` — `submitResponseV15` valida `answers.<qid>` via
        // `Rule::in($optionIds)`. O `peso` viaja junto só para render local
        // (Phase 71-02 mapeia ID → peso no client apenas para UI).
        $templatePayload = null;
        if ($survey->template_id !== null && $survey->template) {
            // Bugfix 2026-07-08 — placeholders {nome_estrategista}/{nome_analista}/
            // {nome_empresa} nos textos das perguntas do template precisam ser
            // resolvidos antes de mandar pro front. O seed "NPS Padrão" grava textos
            // com placeholders literais para admitir renomeação de estrategista/
            // analista por empresa (research §1 seed 100004). $varsPagina já contém
            // os nomes reais (linha 348+); reusar mesmo pattern do NpsTextRenderer
            // aplicado aos textosRender legados.
            $templatePayload = [
                'id'        => $survey->template->id,
                'nome'      => $survey->template->nome,
                'descricao' => $survey->template->descricao,
                'perguntas' => $survey->template->questions->map(function ($q) use ($varsPagina) {
                    return [
                        'id'          => $q->id,
                        'ordem'       => $q->ordem,
                        'texto'       => NpsTextRenderer::render($q->texto, $varsPagina),
                        'tipo'        => $q->tipo,
                        'dimensao'    => $q->dimensao,
                        'obrigatoria' => $q->obrigatoria,
                        'options'     => $q->options->map(fn ($o) => [
                            'id'    => $o->id,
                            'ordem' => $o->ordem,
                            'label' => $o->label,
                            'peso'  => $o->peso,
                        ])->values()->all(),
                    ];
                })->values()->all(),
            ];
        }

        return Inertia::render('Nps/Respond', [
            'survey' => [
                'token'              => $survey->token,
                'company_name'       => $survey->company->name,
                'estrategista_name'  => $estrategistaNome,
                'analista_name'      => $analistaNome,
                'tem_analista'       => $temAnalista,
                'textos'             => $textosRender,
            ],
            'perguntas_extras' => $perguntasExtras,
            // Phase 71 Plan 01 — payload dinâmico v15.0 (null em surveys legacy).
            'template'         => $templatePayload,
        ]);
    }

    /**
     * Persiste a resposta NPS.
     *
     * Discriminacao por template_id (Phase 69 Plan 03):
     *
     *   - `template_id !== null` -> fluxo v15.0 dinamico via `submitResponseV15()`:
     *     rules derivadas do template snapshot (Rule::in nas options), gravacao
     *     em `nps_response_answers` com snapshot congelado
     *     (question_texto/dimensao/label/peso_snapshot — research §1) + guard
     *     QueryException 23000 do dedup unique parcial (research §2 + Plan 68-04)
     *     -> render `Nps/AlreadyCompleted` em colisao de duplicata mensal.
     *
     *   - `template_id === null` -> fluxo legacy Phase 31/33 preservado 100%
     *     via `submitResponseLegacy()`: score_estrategista/analista/empresa
     *     hardcoded + NpsRespostaCustomizada extras. Coexiste ate Phase 73
     *     (rows Phase 31/33 sem template_id continuam funcionando).
     *
     * Referencias:
     *   - .planning/research/v15-nps-templates-schema.md §1 (snapshot per-row)
     *   - .planning/research/v15-nps-templates-schema.md §2 (dedup 23000)
     *   - REQ NPS-B-03 (guard 23000) + REQ NPS-B-05 (validacao dinamica)
     */
    public function submitResponse(Request $request, string $token)
    {
        $survey = NpsSurvey::where('token', $token)->where('status', 'pending')->firstOrFail();

        if ($survey->isExpired()) {
            return response()->json(['error' => 'Pesquisa expirada.'], 422);
        }

        // Discriminador Phase 69 Plan 03: template_id populado -> fluxo v15.0
        // com validacao dinamica + snapshot per-row. NULL -> legacy Phase 31/33.
        if ($survey->template_id !== null) {
            return $this->submitResponseV15($request, $survey);
        }

        return $this->submitResponseLegacy($request, $survey);
    }

    /**
     * Fluxo v15.0 (Phase 69 Plan 03) — validacao dinamica derivada do template
     * snapshot associado ao survey + gravacao 1 NpsResponseAnswer por pergunta
     * respondida com snapshot congelado + guard QueryException 23000.
     *
     * Fonte de verdade das notas migra para `nps_response_answers.option_peso_snapshot`
     * — as colunas legacy `score_estrategista/analista/empresa` de NpsResponse
     * ficam NULL neste fluxo (Phase 68 Plan 01 tornou nullable justamente pra isso).
     * `NpsScoreCalculator::compute()` (Phase 69 Plan 02) le sempre das answers.
     */
    private function submitResponseV15(Request $request, NpsSurvey $survey)
    {
        // Eager load do template snapshot: perguntas + opcoes ordenadas.
        // Fonte da validacao dinamica (Rule::in) e do snapshot per-row.
        $survey->load('template.questions.options');

        if (!$survey->template) {
            // Consistencia quebrada: template_id != null mas relacionamento vazio
            // (FK apontando pra template deletado com nullOnDelete NAO deveria
            // acontecer porque nesse caso o proprio template_id ja teria virado
            // NULL — mas defesa em profundidade). Fallback 422 claro.
            return response()->json(['error' => 'Template do NPS nao encontrado.'], 422);
        }

        // ─── Constroi rules dinamicamente do template ────────────────────────
        // Cada pergunta gera 1 regra em answers.<qid>:
        //   - obrigatoria=true  -> required
        //   - obrigatoria=false -> nullable
        //   - Rule::in(<option_ids da question>) barra option_id de outro template
        $rules = [
            'respondent_name' => 'nullable|string|max:255',
            'comment'         => 'nullable|string|max:2000',
            'answers'         => 'nullable|array',
        ];

        $questionsById     = [];
        $optionsByQuestion = [];

        foreach ($survey->template->questions as $q) {
            $questionsById[$q->id]     = $q;
            $optionsByQuestion[$q->id] = $q->options->keyBy('id');

            $req = $q->obrigatoria ? 'required' : 'nullable';

            // Ajuste 2026-07-13 · tipo=texto_livre: aceita STRING em vez de
            // option_id. Não tem `Rule::in()` porque não existe conjunto
            // fechado de valores válidos (é campo aberto). Limite de 2000
            // char pra evitar payload gigante e alinha com `comment`.
            if ($q->tipo === \App\Models\NpsTemplateQuestion::TIPO_TEXTO_LIVRE) {
                $rules["answers.{$q->id}"] = [$req, 'string', 'max:2000'];
            } else {
                $optionIds = $q->options->pluck('id')->all();
                $rules["answers.{$q->id}"] = [$req, 'integer', Rule::in($optionIds)];
            }
        }

        $validated = $request->validate($rules);

        // ─── Persiste dentro de transacao — inclui update status='completed'
        // que pode disparar QueryException 23000 se o dedup unique parcial
        // (Plan 68-04) detectar duplicata (company_id, month_reference, template_id).
        try {
            DB::transaction(function () use ($survey, $validated, $questionsById, $optionsByQuestion) {
                // NpsResponse SEM score_* legados — fonte de verdade v15.0 e
                // nps_response_answers. Colunas legacy nullable desde Phase 68 Plan 01.
                $response = NpsResponse::create([
                    'survey_id'          => $survey->id,
                    'respondent_name'    => $validated['respondent_name'] ?? null,
                    'score_estrategista' => null,
                    'score_analista'     => null,
                    'score_empresa'      => null,
                    'comment'            => $validated['comment'] ?? null,
                ]);

                // 1 NpsResponseAnswer por pergunta respondida — snapshot congelado.
                foreach (($validated['answers'] ?? []) as $qid => $answerValue) {
                    if ($answerValue === null || $answerValue === '') {
                        continue; // pergunta opcional sem resposta
                    }

                    // Defensivo: Rule::in ja barrou tampering; se por race chegou
                    // aqui com id fora do map, pula silenciosamente.
                    $question = $questionsById[$qid] ?? null;
                    if (!$question) {
                        continue;
                    }

                    // Ajuste 2026-07-13 · tipo texto_livre grava o texto em
                    // `comentario` e deixa option_label/peso NULL. Não entra
                    // em AVG de score (peso NULL não conta em AVG do MySQL).
                    if ($question->tipo === \App\Models\NpsTemplateQuestion::TIPO_TEXTO_LIVRE) {
                        NpsResponseAnswer::create([
                            'response_id'                => $response->id,
                            'template_question_id'       => $question->id,
                            'template_option_id'         => null,
                            'question_texto_snapshot'    => $question->texto,
                            'question_dimensao_snapshot' => $question->dimensao,
                            'option_label_snapshot'      => null,
                            'option_peso_snapshot'       => null,
                            'comentario'                 => (string) $answerValue,
                        ]);
                        continue;
                    }

                    $option = $optionsByQuestion[$qid][$answerValue] ?? null;
                    if (!$option) {
                        continue;
                    }

                    NpsResponseAnswer::create([
                        'response_id'                => $response->id,
                        'template_question_id'       => $question->id,
                        'template_option_id'         => $option->id,
                        'question_texto_snapshot'    => $question->texto,
                        'question_dimensao_snapshot' => $question->dimensao,
                        'option_label_snapshot'      => $option->label,
                        'option_peso_snapshot'       => $option->peso,
                        'comentario'                 => null,
                    ]);
                }

                // Phase 79 v16.0 (DEC-79-D): congela o SNAPSHOT imutável — médias
                // por dimensão + serviços cobertos + atribuições por serviço. DEVE
                // rodar AQUI: depois do foreach das answers (senão o calculator leria
                // zero — Pitfall 3) e DENTRO desta transação (para reverter junto se
                // o dedup 23000 estourar no update abaixo). O service NÃO abre
                // transação própria. Bônus/legacy intactos (DEC-79-E).
                app(\App\Services\Nps\NpsSnapshotService::class)->registrar($response);

                // Marca survey como completed — pode disparar 23000 aqui pelo
                // partial unique index de dedup mensal (Plan 68-04).
                $survey->update([
                    'status'       => 'completed',
                    'completed_at' => now(),
                ]);
            });
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                // Phase 68 Plan 04: dedup unique parcial
                // (company_id, month_reference, template_id) bloqueou 2a
                // completacao no mesmo mes com o mesmo template. UX: renderiza
                // a mesma tela ja usada pelo GET quando survey.status=completed.
                return Inertia::render('Nps/AlreadyCompleted');
            }
            throw $e;
        }

        return Inertia::render('Nps/ThankYou');
    }

    /**
     * Fluxo legacy Phase 31/33 preservado 100% — usado quando o survey nao
     * tem template_id (rows criadas antes da Phase 69 seed retro do Plan 68-03,
     * ou fluxos que ainda nao migraram). Coexiste ate Phase 73.
     *
     * Comportamento identico ao NpsController::submitResponse pre-Phase 69:
     *   - Rules hardcoded: score_estrategista/analista/empresa 1..5
     *   - Perguntas customizadas Phase 33 (NpsPerguntaCustomizada) ativas
     *     geram rules dinamicas em respostas_extras.<pid> por tipo
     *   - Persiste NpsResponse com scores legados + NpsRespostaCustomizada
     *     por pergunta extra respondida
     */
    private function submitResponseLegacy(Request $request, NpsSurvey $survey)
    {
        // ─── Phase 33 D-07 — validacao dinamica de perguntas customizadas ────
        // Carrega TODAS as perguntas ativas no momento da submissao para montar
        // as rules dinamicamente conforme tipo (D-02). Perguntas inativas nao
        // sao validadas (mesmo que enviadas) — apenas as ativas atuais contam.
        $perguntas = NpsPerguntaCustomizada::where('ativa', true)->get()->keyBy('id');

        $rules = [
            'respondent_name'    => 'nullable|string|max:255',
            'score_estrategista' => 'required|integer|min:1|max:5',
            'score_analista'     => 'nullable|integer|min:1|max:5',
            'score_empresa'      => 'required|integer|min:1|max:5',
            'comment'            => 'nullable|string|max:2000',
            'respostas_extras'   => 'nullable|array',
        ];

        foreach ($perguntas as $p) {
            $base = "respostas_extras.{$p->id}";
            $req  = $p->obrigatorio ? 'required' : 'nullable';

            switch ($p->tipo) {
                case 'escala_1_5':
                    $rules[$base] = "{$req}|integer|min:1|max:5";
                    break;

                case 'texto':
                    $rules[$base] = "{$req}|string|max:2000";
                    break;

                case 'sim_nao':
                    $rules[$base] = [$req, Rule::in(['sim', 'nao'])];
                    break;

                case 'multipla':
                    $rules[$base] = [$req, Rule::in($p->opcoes ?? [])];
                    break;
            }
        }

        $validated = $request->validate($rules);

        // Atomicidade: NpsResponse + N respostas customizadas dentro da mesma
        // transacao. Se qualquer insert falhar, a survey nao e marcada completa.
        DB::transaction(function () use ($survey, $validated, $perguntas) {
            $response = NpsResponse::create([
                'survey_id'          => $survey->id,
                'respondent_name'    => $validated['respondent_name'] ?? null,
                'score_estrategista' => $validated['score_estrategista'],
                'score_analista'     => $validated['score_analista'] ?? null,
                'score_empresa'      => $validated['score_empresa'],
                'comment'            => $validated['comment'] ?? null,
            ]);

            // Persiste 1 NpsRespostaCustomizada por pergunta respondida, com
            // snapshot do texto/tipo da pergunta no momento da resposta.
            foreach (($validated['respostas_extras'] ?? []) as $perguntaId => $valor) {
                // Pula respostas vazias de perguntas opcionais.
                if ($valor === null || $valor === '') {
                    continue;
                }

                // Defensivo: ignora ids que nao casam com perguntas ativas
                // (poderiam vir de cliente desatualizado / tampering).
                $p = $perguntas[$perguntaId] ?? null;
                if (!$p) {
                    continue;
                }

                NpsRespostaCustomizada::create([
                    'response_id'             => $response->id,
                    'pergunta_id'             => $p->id,
                    'pergunta_texto_snapshot' => $p->texto,
                    'tipo_snapshot'           => $p->tipo,
                    'valor'                   => (string) $valor,
                ]);
            }

            $survey->update(['status' => 'completed', 'completed_at' => now()]);
        });

        return Inertia::render('Nps/ThankYou');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Customização de textos (Phase 32, Plan 02) — admin only via middleware
    // role:admin nas rotas. Toda a UI de edição vive em /nps/configuracao.
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Página admin de customização dos 5 textos do email NPS mensal.
     *
     * v15.5 (2026-07-08) — esta página passou a ser EXCLUSIVA de customização
     * do email. Toda a UI de edição do formulário NPS (perguntas fixas +
     * perguntas extras) foi removida daqui — o admin agora usa a nova UI
     * multi-template em `/nps/configuracao` para editar as perguntas.
     *
     * Carrega os 5 textos atuais + os defaults + a doc dos placeholders para
     * exibir no painel lateral. Endpoints irmãos: `atualizarConfiguracao`
     * (PUT) salva e `previewEmail` (POST) renderiza o template Blade.
     */
    public function configuracao()
    {
        // Documentação dos placeholders aceitos pelos textos do email.
        // `nome_analista` fica visível no corpo via `bloco_analista` (que vira
        // string vazia em mentoria pura). Mês/empresa/estrategista funcionam
        // em qualquer campo do email.
        $placeholdersDoc = [
            ['chave' => '{nome_estrategista}', 'descricao' => 'Nome do estrategista da empresa.'],
            ['chave' => '{nome_analista}',     'descricao' => 'Nome do analista (omitido quando mentoria pura).'],
            ['chave' => '{nome_empresa}',      'descricao' => 'Nome da empresa que está respondendo.'],
            ['chave' => '{mes_referencia}',    'descricao' => 'Mês de referência em formato pt-BR — ex: "junho/2026".'],
            ['chave' => '{bloco_analista}',    'descricao' => 'Bloco condicional " e o analista é **Igor**" no corpo do email (usar apenas em email_corpo); vira string vazia em mentoria pura.'],
        ];

        // Só os 5 textos do email chegam ao JSX — o resto do defaults()
        // (perg_*) segue existindo em NpsTextRenderer pra retro-compat do
        // RespondLegado.jsx (surveys legacy sem template_id).
        $textosCompletos = NpsTextRenderer::getTextos();
        $defaultsCompletos = NpsTextRenderer::defaults();

        $chavesEmail = ['email_assunto', 'email_saudacao', 'email_corpo', 'email_cta', 'email_assinatura'];

        return Inertia::render('Nps/ConfiguracaoLegado', [
            'textos'           => collect($textosCompletos)->only($chavesEmail)->toArray(),
            'defaults'         => collect($defaultsCompletos)->only($chavesEmail)->toArray(),
            'placeholders_doc' => $placeholdersDoc,
        ]);
    }

    /**
     * PUT /nps/configuracao — persiste os 5 textos do email em
     * `configuracoes.nps_textos` como JSON.
     *
     * v15.5 (2026-07-08) — só valida os 5 campos do email. Os campos legados
     * `perg_*` que ainda podem existir no JSON são PRESERVADOS via merge
     * (defesa para o RespondLegado.jsx que ainda serve surveys sem template_id).
     * Não há restrição de placeholders — o NpsTextRenderer aplica str_replace
     * silencioso, placeholders desconhecidos ficam no texto sem erro.
     */
    public function atualizarConfiguracao(Request $request)
    {
        $validated = $request->validate([
            'email_assunto'    => 'required|string|max:5000',
            'email_saudacao'   => 'required|string|max:5000',
            'email_corpo'      => 'required|string|max:5000',
            'email_cta'        => 'required|string|max:5000',
            'email_assinatura' => 'required|string|max:5000',
        ]);

        // Merge com o JSON existente — preserva `perg_*` legados usados pelo
        // RespondLegado.jsx quando a survey vem sem template_id (raro pós-Phase 71,
        // mas mantém back-compat até Phase 73 limpar de vez).
        $atual = NpsTextRenderer::getTextos();
        $novo  = array_merge($atual, $validated);

        Configuracao::set('nps_textos', json_encode($novo, JSON_UNESCAPED_UNICODE));

        return back()->with('success', 'Textos do email NPS atualizados.');
    }

    /**
     * PATCH /nps/configuracao/dia-cobranca — persiste config global "dia de
     * cobrança mensal" (Phase 72 Plan 01, NPS-E-01).
     *
     * Aceita int 1..31; qualquer valor fora do range é rejeitado com 422 com
     * mensagem pt-BR. Persistido em Configuracao::set('nps_dia_cobranca', ...)
     * como string (padrão do key-value store). O NpsPendingService::diaCobranca
     * cast de volta para int + clamp defensivo 1..31.
     *
     * Consumido pelo widget DiaCobrancaWidget em Nps/Configuracao.jsx
     * (Phase 72 Plan 01 T3).
     */
    public function atualizarDiaCobranca(Request $request)
    {
        $validated = $request->validate([
            'dia' => 'required|integer|min:1|max:31',
        ], [
            'dia.required' => 'Informe o dia de cobrança.',
            'dia.integer'  => 'O dia deve ser um número inteiro.',
            'dia.min'      => 'O dia deve ser entre 1 e 31.',
            'dia.max'      => 'O dia deve ser entre 1 e 31.',
        ]);

        // Persiste como string (padrão do key-value store Configuracao);
        // NpsPendingService::diaCobranca faz cast + clamp na leitura.
        Configuracao::set('nps_dia_cobranca', (string) $validated['dia']);

        return back()->with('success', "Dia de cobrança do NPS atualizado para {$validated['dia']}.");
    }

    /**
     * POST /nps/configuracao/preview — renderiza o template Blade do email
     * com os textos NÃO PERSISTIDOS vindos do form (permite preview sem salvar).
     *
     * Usa as mesmas vars de exemplo do CONTEXT D-05 para preservar consistência
     * com o que o admin verá ao receber o disparo real. O HTML retornado é
     * injetado num <iframe srcdoc> no frontend para isolar o CSS do email do
     * Tailwind do app.
     */
    public function previewEmail(Request $request)
    {
        // Valida só o que vai ser usado pra renderizar — mesma whitelist do PUT,
        // mas tudo opcional (preview funciona mesmo com campos vazios).
        $textos = $request->validate([
            'email_assunto'    => 'nullable|string|max:5000',
            'email_saudacao'   => 'nullable|string|max:5000',
            'email_corpo'      => 'nullable|string|max:5000',
            'email_cta'        => 'nullable|string|max:5000',
            'email_assinatura' => 'nullable|string|max:5000',
        ]);

        // Vars de exemplo fixas (D-05) — combinam com o tom usado no email real.
        // mes_referencia em minúsculo para casar com o default "satisfação ECF — junho/2026"
        // (decisão herdada do Plan 32-01).
        $varsExemplo = [
            'nome_estrategista' => 'Nathália',
            'nome_analista'     => 'Igor',
            'nome_empresa'      => 'Empresa Exemplo Ltda',
            'mes_referencia'    => 'junho/2026',
            'bloco_analista'    => ' e o analista é **Igor**',
        ];

        // Renderiza cada texto com os placeholders substituídos.
        // - render() para campos texto-puro (assunto, CTA)
        // - renderHtml() para campos que vão como HTML no template (saudação, corpo, assinatura)
        $assuntoRender    = NpsTextRenderer::render($textos['email_assunto']    ?? '', $varsExemplo);
        $saudacaoRender   = NpsTextRenderer::renderHtml($textos['email_saudacao']   ?? '', $varsExemplo);
        $corpoRender      = NpsTextRenderer::renderHtml($textos['email_corpo']      ?? '', $varsExemplo);
        $ctaRender        = NpsTextRenderer::render($textos['email_cta']        ?? '', $varsExemplo);
        $assinaturaRender = NpsTextRenderer::renderHtml($textos['email_assinatura'] ?? '', $varsExemplo);

        // URL falsa (não persistida) — só pra o botão CTA do preview ter um href.
        $linkPesquisa = url('/nps/preview-token-exemplo');

        $html = view('emails.nps.mensal', [
            'saudacaoRender'   => $saudacaoRender,
            'corpoRender'      => $corpoRender,
            'ctaRender'        => $ctaRender,
            'assinaturaRender' => $assinaturaRender,
            'linkPesquisa'     => $linkPesquisa,
            'mesReferencia'    => $varsExemplo['mes_referencia'],
        ])->render();

        return response()->json([
            'html'    => $html,
            'assunto' => $assuntoRender,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Página de envios (Phase 32, Plan 04) — admin only via middleware role:admin.
    // Lista paginada dos disparos do comando `nps:disparar-mensal` (Plan 32-01
    // grava `NpsEmailEnvio` por empresa elegível em cada execução).
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Página admin /nps/emails-enviados — lista paginada de envios NPS.
     *
     * Filtros (D-06 LOCKED — mínimos):
     *  - ?mes=YYYY-MM   (default = mês corrente; formato inválido → mês atual)
     *  - ?q=texto       (busca em company.name OR destinatario via LIKE)
     *
     * Paginação 25/pg + preserva query string (?mes e ?q) entre páginas.
     *
     * O front mostra status como badge (verde "Enviado" / vermelho "Falha"),
     * link pro survey gerado (`/nps/{token}`) em status=enviado e expande o
     * `erro_msg` quando status=falha.
     */
    /**
     * Quick task 260612-flt — DELETE /nps/{survey}/response.
     *
     * Admin exclusivo (route middleware role:admin). Apaga a resposta do cliente
     * (NpsResponse) e reverte o survey para `pending` — cliente pode responder
     * de novo se ainda estiver dentro do prazo. cascade onDelete em
     * nps_respostas_customizadas apaga as respostas extras automaticamente.
     */
    public function excluirResposta(Request $request, NpsSurvey $survey)
    {
        abort_unless($request->user()->isAdmin(), 403);

        if (!$survey->response) {
            return back()->with('error', 'Esta pesquisa ainda não foi respondida.');
        }

        $survey->response->delete();  // cascade apaga respostas_customizadas
        $survey->update(['status' => 'pending', 'completed_at' => null]);

        return back()->with('success', 'Resposta excluída. A pesquisa voltou para pendente.');
    }

    /**
     * 2026-07-13 — DELETE /nps/{survey}. Admin exclusivo (route middleware
     * role:admin + guard redundante). Apaga a pesquisa NPS inteira, de
     * QUALQUER status (inclusive `pending`, que a UI antiga não deixava
     * excluir). O cascade de FK no banco apaga em sequência:
     *   nps_responses → nps_respostas_customizadas / nps_response_answers
     *   nps_email_envios (survey_id cascadeOnDelete)
     * Usa $survey->delete() (não mass-delete) para disparar o activitylog
     * ('NPS excluído') e os cascades a nível de banco.
     */
    public function destroy(Request $request, NpsSurvey $survey)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $nome = $survey->company?->name ?? 'empresa';
        $survey->delete();

        return back()->with('success', "Pesquisa NPS de \"{$nome}\" excluída.");
    }

    /**
     * 2026-07-13 — DELETE /nps/surveys/bulk. Exclusão em massa a partir dos
     * checkboxes da listagem. Admin exclusivo. Itera com get()->each->delete()
     * (em vez de whereIn->delete()) para preservar activitylog + cascades por
     * row. Volumes esperados são baixos (página da listagem), então o custo é
     * aceitável.
     */
    public function bulkDestroy(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $validated = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $surveys = NpsSurvey::whereIn('id', $validated['ids'])->get();
        $count   = $surveys->count();
        $surveys->each->delete();

        return back()->with('success', $count === 1
            ? '1 pesquisa NPS excluída.'
            : "{$count} pesquisas NPS excluídas.");
    }

    public function emailsEnviados(Request $request)
    {
        // ─── Validação leve do parâmetro mes ─────────────────────────────────
        // Formato esperado YYYY-MM; qualquer coisa fora disso cai no mês atual.
        $mes = $request->input('mes') ?: now()->format('Y-m');
        try {
            $inicio = Carbon::createFromFormat('Y-m', $mes)->startOfMonth();
        } catch (\Exception $e) {
            $mes    = now()->format('Y-m');
            $inicio = now()->startOfMonth();
        }
        $fim = $inicio->copy()->endOfMonth();

        // ─── Query base com eager loading dos relacionamentos exibidos ──────
        // Campos enxutos pra reduzir payload Inertia (id+name da empresa, token
        // pro botão "Ver", status/company_id pro guard do botão).
        $query = NpsEmailEnvio::with([
                'company:id,name',
                'survey:id,token,status,company_id',
            ])
            ->whereBetween('created_at', [$inicio, $fim])
            ->orderByDesc('created_at');

        // Filtro de busca: nome da empresa OU destinatário. Usa `whereHas` no
        // relacionamento company para LIKE no nome, sem JOIN explícito.
        if ($q = trim((string) $request->input('q'))) {
            $query->where(function ($w) use ($q) {
                $w->whereHas('company', fn($c) => $c->where('name', 'like', "%{$q}%"))
                  ->orWhere('destinatario', 'like', "%{$q}%");
            });
        }

        $envios = $query->paginate(25)->withQueryString();

        // Total do mês (ignora filtro de busca quando contamos o "total do mês"
        // no header — usuário quer saber quantos envios houve no mês selecionado,
        // não apenas os que casam com a busca atual).
        $totalMes = NpsEmailEnvio::whereBetween('created_at', [$inicio, $fim])->count();

        // ─── Últimos 12 meses para popular o Select de filtro ───────────────
        // Formato pt-BR ("Jun/26"). `translatedFormat` usa o locale do app
        // (config/app.php => 'locale' => 'pt_BR' nesta base).
        $mesesDisponiveis = collect(range(0, 11))->map(fn($i) => [
            'value' => now()->subMonths($i)->format('Y-m'),
            'label' => now()->subMonths($i)->translatedFormat('M/y'),
        ])->values();

        return Inertia::render('Nps/EmailsEnviados', [
            'envios'            => $envios,
            'mes'               => $mes,
            'q'                 => $request->input('q', ''),
            'meses_disponiveis' => $mesesDisponiveis,
            'total_mes'         => $totalMes,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Perguntas customizadas (Phase 33, Plan 33-01) — admin only via middleware
    // role:admin nas rotas. Toda a UI vive na 3a tab de /nps/configuracao
    // ("Perguntas extras") implementada no Plan 33-02.
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * POST /nps/configuracao/perguntas — cria nova pergunta extra (D-06).
     *
     * Ordem inicial = max(ordem) + 1 → pergunta nova vai pro final da lista
     * automaticamente. Admin pode reordenar depois via moverPerguntaExtra().
     *
     * `opcoes` so tem semantica quando tipo=multipla; em outros tipos for_a-se null
     * mesmo se o cliente enviar algo (defesa contra payload sujo).
     */
    public function criarPerguntaExtra(Request $request)
    {
        // Quick fix 260612: o frontend manda `opcoes: []` mesmo quando tipo!=multipla
        // (defaults do useForm). Sem este merge, `min:2` falhava validacao em todos
        // os tipos que nao usam opcoes — request voltava com errors silenciosos e
        // a UI nao mostrava nada acontecendo.
        if ($request->input('tipo') !== 'multipla') {
            $request->merge(['opcoes' => null]);
        }

        $validated = $request->validate([
            'texto'       => 'required|string|max:500',
            'tipo'        => ['required', Rule::in(NpsPerguntaCustomizada::TIPOS)],
            'opcoes'      => ['nullable', 'array', 'required_if:tipo,multipla', 'min:2'],
            'opcoes.*'    => 'string|max:255',
            'obrigatorio' => 'boolean',
            'ativa'       => 'boolean',
        ]);

        // Forca opcoes=null quando tipo nao e multipla (defesa redundante apos o merge).
        $validated['opcoes'] = $validated['tipo'] === 'multipla'
            ? ($validated['opcoes'] ?? null)
            : null;

        // Posiciona a nova pergunta no final da lista — admin reordena depois.
        $validated['ordem'] = (NpsPerguntaCustomizada::max('ordem') ?? 0) + 1;

        NpsPerguntaCustomizada::create($validated);

        return back()->with('success', 'Pergunta extra criada.');
    }

    /**
     * PUT /nps/configuracao/perguntas/{pergunta} — edita pergunta existente.
     *
     * Todos os campos sao `sometimes` — admin pode mandar parcial. Se mandou
     * `tipo` mas o tipo novo nao e `multipla`, zera opcoes; se tipo continua
     * `multipla` mas nao mandou opcoes, preserva as atuais.
     */
    public function atualizarPerguntaExtra(Request $request, NpsPerguntaCustomizada $pergunta)
    {
        // Quick fix 260612: idem criarPerguntaExtra — neutraliza opcoes=[] quando
        // tipo efetivo nao e multipla, pra nao tropecar no min:2.
        $tipoEfetivoAntes = $request->input('tipo', $pergunta->tipo);
        if ($tipoEfetivoAntes !== 'multipla' && $request->has('opcoes')) {
            $request->merge(['opcoes' => null]);
        }

        $validated = $request->validate([
            'texto'       => 'sometimes|required|string|max:500',
            'tipo'        => ['sometimes', 'required', Rule::in(NpsPerguntaCustomizada::TIPOS)],
            'opcoes'      => ['sometimes', 'nullable', 'array', 'min:2'],
            'opcoes.*'    => 'string|max:255',
            'obrigatorio' => 'sometimes|boolean',
            'ativa'       => 'sometimes|boolean',
        ]);

        // Calcula tipo efetivo (novo ou atual) para decidir o que fazer com opcoes.
        $tipoEfetivo = $validated['tipo'] ?? $pergunta->tipo;
        if ($tipoEfetivo !== 'multipla') {
            $validated['opcoes'] = null;
        }

        $pergunta->update($validated);

        return back()->with('success', 'Pergunta atualizada.');
    }

    /**
     * DELETE /nps/configuracao/perguntas/{pergunta} — exclui ou desativa.
     *
     * Comportamento padrao (D-06):
     *  - Se a pergunta TEM respostas associadas e a query string `?force=1` NAO
     *    foi enviada, faz SOFT delete (ativa=false) — preserva historico.
     *  - Se `?force=1` OU a pergunta nao tem respostas, HARD delete. As respostas
     *    historicas perdem o vinculo (FK set null), mas o snapshot do texto/tipo
     *    sustenta o display no modal de detalhes.
     */
    public function excluirPerguntaExtra(Request $request, NpsPerguntaCustomizada $pergunta)
    {
        $temRespostas = NpsRespostaCustomizada::where('pergunta_id', $pergunta->id)->exists();

        if ($temRespostas && !$request->boolean('force')) {
            $pergunta->update(['ativa' => false]);

            return back()->with(
                'success',
                'Pergunta desativada (tem respostas associadas — use ?force=1 para deletar definitivamente).'
            );
        }

        $pergunta->delete();

        return back()->with('success', 'Pergunta removida.');
    }

    /**
     * POST /nps/configuracao/perguntas/{pergunta}/mover — troca ordem com vizinha.
     *
     * Estrategia: encontra a vizinha mais proxima na direcao desejada e SWAP de
     * valores de `ordem`. Se ja e a primeira (up) ou ultima (down), no-op.
     *
     * Vantagens vs. "shift all rows": O(1) updates, sem race condition complexa
     * em casos simples. Como nao temos concorrencia real (admin sozinho na UI),
     * suficiente.
     */
    public function moverPerguntaExtra(Request $request, NpsPerguntaCustomizada $pergunta)
    {
        $direcao = $request->validate([
            'direcao' => 'required|in:up,down',
        ])['direcao'];

        if ($direcao === 'up') {
            // Vizinha = maior ordem que ainda e menor que a minha (ASC desc).
            $vizinha = NpsPerguntaCustomizada::where('ordem', '<', $pergunta->ordem)
                ->orderByDesc('ordem')
                ->orderByDesc('id')
                ->first();
        } else {
            // Vizinha = menor ordem que e maior que a minha.
            $vizinha = NpsPerguntaCustomizada::where('ordem', '>', $pergunta->ordem)
                ->orderBy('ordem')
                ->orderBy('id')
                ->first();
        }

        // No-op se ja esta no extremo (primeira/ultima da lista).
        if (!$vizinha) {
            return back();
        }

        // Swap atomico das ordens entre as duas perguntas.
        DB::transaction(function () use ($pergunta, $vizinha) {
            $ordemTmp = $pergunta->ordem;
            $pergunta->update(['ordem' => $vizinha->ordem]);
            $vizinha->update(['ordem' => $ordemTmp]);
        });

        return back();
    }
}
