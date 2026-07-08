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

        $aplicarFiltrosSurveys = function ($query) use ($empresaId, $estrategistaId, $analistaId) {
            if ($empresaId) {
                $query->where('company_id', $empresaId);
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
        // Eager load `response.respostasCustomizadas` evita N+1 quando o modal
        // "Abrir" do Plan 33-04 expande as respostas extras de cada survey.
        $baseQuery = NpsSurvey::with(['company', 'response.respostasCustomizadas', 'generatedBy'])
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
            'score_estrategista' => $s->response?->score_estrategista,
            'score_analista'     => $s->response?->score_analista,
            'score_empresa'      => $s->response?->score_empresa,
            'respondent'         => $s->response?->respondent_name,
            'comment'            => $s->response?->comment,
            'link'               => route('nps.respond', $s->token),

            // Phase 33 Plan 33-04 — payload do modal "Abrir". Usa o snapshot do
            // texto/tipo (preservado mesmo se a pergunta foi hard-deleted depois).
            'respostas_customizadas' => $s->response
                ? $s->response->respostasCustomizadas->map(fn($r) => [
                    'id'             => $r->id,
                    'pergunta_id'    => $r->pergunta_id,
                    'pergunta_texto' => $r->pergunta_texto_snapshot,
                    'tipo'           => $r->tipo_snapshot,
                    'valor'          => $r->valor,
                ])->values()
                : [],
        ]);

        // ─── 3 cards de média (somente respostas do mês filtrado) ────────────
        // Reusa a mesma lógica de pertencer-ao-mês (month_reference OR created_at)
        // via whereHas('survey', ...). Médias ignoram NULLs naturalmente (AVG SQL).
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

        $responsesQuery = NpsResponse::query()->whereHas('survey', $responsesFilter);

        $cards = [
            'estrategista' => [
                'media' => round((float) ((clone $responsesQuery)->avg('score_estrategista') ?? 0), 2),
                'total' => (clone $responsesQuery)->whereNotNull('score_estrategista')->count(),
            ],
            'analista' => [
                'media' => round((float) ((clone $responsesQuery)->avg('score_analista') ?? 0), 2),
                'total' => (clone $responsesQuery)->whereNotNull('score_analista')->count(),
            ],
            'empresa' => [
                'media' => round((float) ((clone $responsesQuery)->avg('score_empresa') ?? 0), 2),
                'total' => (clone $responsesQuery)->whereNotNull('score_empresa')->count(),
            ],
        ];

        // ─── Série 12 meses para o LineChart ─────────────────────────────────
        // Trade-off consciente: 12 iterações × 3 avg queries = ~36 queries.
        // Para o volume esperado (~150 empresas × 12 meses = 1800 respostas no
        // pior caso) é aceitável. Se virar gargalo, agregar via 1 query single
        // GROUP BY DATE_FORMAT(month_reference, '%Y-%m').
        $serieMeses = [];
        $inicio12m  = now()->startOfMonth()->subMonths(11);
        for ($i = 0; $i < 12; $i++) {
            $m    = $inicio12m->copy()->addMonths($i);
            $mFim = $m->copy()->endOfMonth();

            $q = NpsResponse::query()->whereHas('survey', function ($qq) use ($m, $mFim, $user, $aplicarFiltrosSurveys) {
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
            });

            $serieMeses[] = [
                'mes'          => $m->locale('pt_BR')->isoFormat('MMM/YY'), // ex: 'jun./26'
                'mes_iso'      => $m->format('Y-m'),
                'estrategista' => round((float) ((clone $q)->avg('score_estrategista') ?? 0), 2),
                'analista'     => round((float) ((clone $q)->avg('score_analista') ?? 0), 2),
                'empresa'      => round((float) ((clone $q)->avg('score_empresa') ?? 0), 2),
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

        return Inertia::render('Nps/Index', [
            'surveys'                => $surveys,
            'companies'              => $companies,
            'estrategistas'          => $estrategistas,
            'analistas'              => $analistas,
            'pode_filtrar_por_pessoa' => $podeFiltrarPorPessoa,
            'cards'          => $cards,
            'serie_12m'      => $serieMeses,
            'mes_filtro'     => $mesFiltro,
            'filtros'        => [
                'empresa_id'      => $empresaId,
                'estrategista_id' => $estrategistaId,
                'analista_id'     => $analistaId,
            ],
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
            'company_id' => 'required|exists:companies,id',
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
        $company  = Company::findOrFail($data['company_id']);
        $template = $templateService->resolveForCompany($company);

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
    public function respond(string $token)
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

        if ($survey->status === 'completed') {
            return Inertia::render('Nps/AlreadyCompleted');
        }

        if ($survey->isExpired()) {
            $survey->update(['status' => 'expired']);
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
            $templatePayload = [
                'id'        => $survey->template->id,
                'nome'      => $survey->template->nome,
                'descricao' => $survey->template->descricao,
                'perguntas' => $survey->template->questions->map(function ($q) {
                    return [
                        'id'          => $q->id,
                        'ordem'       => $q->ordem,
                        'texto'       => $q->texto,
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

            $optionIds = $q->options->pluck('id')->all();
            $req       = $q->obrigatoria ? 'required' : 'nullable';

            $rules["answers.{$q->id}"] = [$req, 'integer', Rule::in($optionIds)];
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
                foreach (($validated['answers'] ?? []) as $qid => $optionId) {
                    if ($optionId === null || $optionId === '') {
                        continue; // pergunta opcional sem resposta
                    }

                    // Defensivo: Rule::in ja barrou tampering; se por race chegou
                    // aqui com id fora do map, pula silenciosamente.
                    $question = $questionsById[$qid] ?? null;
                    $option   = $optionsByQuestion[$qid][$optionId] ?? null;
                    if (!$question || !$option) {
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
     * Página admin de customização dos 11 textos do fluxo NPS (D-03).
     *
     * Carrega os textos atuais (com fallback nos defaults) + os defaults canônicos
     * (usados pelo botão "Restaurar padrão") + a doc dos placeholders suportados
     * para exibir no painel lateral da página.
     *
     * Endpoints irmãos: `atualizarConfiguracao` (PUT) salva e `previewEmail`
     * (POST) renderiza o template Blade com vars de exemplo para o iframe srcdoc.
     */
    public function configuracao()
    {
        // Documentação dos placeholders aceitos pelos textos (D-03). Estrategista
        // e analista são variáveis dos textos do email e das perguntas; mês de
        // referência só faz sentido no assunto/corpo; bloco_analista é usado
        // exclusivamente em `email_corpo` (vira string vazia em mentoria pura).
        $placeholdersDoc = [
            ['chave' => '{nome_estrategista}', 'descricao' => 'Nome do estrategista da empresa.'],
            ['chave' => '{nome_analista}',     'descricao' => 'Nome do analista (omitido quando mentoria pura).'],
            ['chave' => '{nome_empresa}',      'descricao' => 'Nome da empresa que está respondendo.'],
            ['chave' => '{mes_referencia}',    'descricao' => 'Mês de referência em formato pt-BR — ex: "junho/2026".'],
            ['chave' => '{bloco_analista}',    'descricao' => 'Bloco condicional " e o analista é **Igor**" no corpo do email (usar apenas em email_corpo); vira string vazia em mentoria pura.'],
        ];

        // ─── Phase 33 Plan 02 — payload da 3a tab "Perguntas extras" ─────────
        // Carrega TODAS as perguntas (ativas + inativas) ordenadas. A UI admin
        // decide como agrupar; aqui apenas entregamos a lista canonica para o
        // form de edicao/listagem/reordenacao.
        $perguntasExtras = NpsPerguntaCustomizada::orderBy('ordem')
            ->orderBy('id')
            ->get();

        // Phase 70 Plan 05 (v15.0) — a página `Nps/Configuracao` foi reescrita
        // como UI multi-template. A UI de edição dos 11 textos + perguntas
        // extras (Phase 33) foi movida para `Nps/ConfiguracaoLegado.jsx` e
        // servida sob a subrota `/nps/configuracao/textos-legado`.
        return Inertia::render('Nps/ConfiguracaoLegado', [
            'textos'           => NpsTextRenderer::getTextos(),
            'defaults'         => NpsTextRenderer::defaults(),
            'placeholders_doc' => $placeholdersDoc,
            'perguntas_extras' => $perguntasExtras,
        ]);
    }

    /**
     * PUT /nps/configuracao — persiste os 11 textos editados em
     * `configuracoes.nps_textos` como JSON.
     *
     * Valida cada chave como string obrigatória (max 5000 chars — folga ampla
     * para corpos de email com markdown). Não há restrição de placeholders:
     * o admin pode optar por não usar nenhum, e os textos vão funcionar como
     * literais. O NpsTextRenderer aplica str_replace silencioso, então
     * placeholders desconhecidos simplesmente ficam no texto sem causar erro.
     */
    public function atualizarConfiguracao(Request $request)
    {
        $validated = $request->validate([
            'email_assunto'              => 'required|string|max:5000',
            'email_saudacao'             => 'required|string|max:5000',
            'email_corpo'                => 'required|string|max:5000',
            'email_cta'                  => 'required|string|max:5000',
            'email_assinatura'           => 'required|string|max:5000',
            'perg_estrategista'          => 'required|string|max:5000',
            'perg_analista'              => 'required|string|max:5000',
            'perg_empresa'               => 'required|string|max:5000',
            'perg_comentario_label'      => 'required|string|max:5000',
            'perg_comentario_placeholder'=> 'required|string|max:5000',
            'perg_nome_label'            => 'required|string|max:5000',
        ]);

        Configuracao::set('nps_textos', json_encode($validated, JSON_UNESCAPED_UNICODE));

        return back()->with('success', 'Textos NPS atualizados.');
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
