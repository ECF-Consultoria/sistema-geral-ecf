<?php

namespace App\Http\Controllers;

use App\Models\CompanyGroup;
use App\Models\NpsGroupSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateQuestion;
use App\Models\User;
use App\Services\Nps\NpsGrupoCoberturaService;
use App\Services\Nps\NpsGrupoReplicacaoService;
use App\Services\Nps\NpsSuspicionService;
use App\Support\NpsTextRenderer;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Controller do NPS de GRUPO — Fase 119.1 Plan 06.
 *
 * Controller NOVO de propósito: `NpsController.php` já é o arquivo mais
 * disputado do módulo NPS (tocado pelas Fases 116/118 e pelos Plans 02/04
 * desta mesma fase) — empilhar aqui geraria conflito de mesclagem sem
 * ganho nenhum, já que o fluxo de grupo tem regras próprias (cobertura,
 * fan-out em espelhos) que não fazem sentido dentro de `NpsController`.
 *
 * Fluxo: prévia de cobertura (`previewCobertura`) → geração do link
 * (`generate`, com o MESMO espírito do guard de duplicidade do individual —
 * Plan 02, só que na tabela do link de grupo) → formulário público
 * (`respond`) → resposta única que a `NpsGrupoReplicacaoService` espalha em
 * N surveys-espelho REAIS, um por empresa coberta (`submitResponse`).
 * Os dois últimos métodos chegam no Plan 06 Task 2 — as rotas públicas já
 * são declaradas nesta task (Task 1), apontando para eles.
 *
 * @see app/Services/Nps/NpsGrupoCoberturaService.php (Plan 05 — quem é coberto e por quê)
 */
class NpsGrupoController extends Controller
{
    public function __construct(
        private NpsGrupoCoberturaService $coberturaService,
        private NpsGrupoReplicacaoService $replicacaoService,
    ) {
    }

    /**
     * Prévia de cobertura — GET autenticado. Mostra, ANTES de qualquer link
     * ser gerado, quais empresas entram e quais ficam de fora (e por quê),
     * consultando a MESMA fonte que o `generate()` vai usar (D4/NPSMAN-08).
     */
    public function previewCobertura(CompanyGroup $grupo, NpsTemplate $template)
    {
        $this->autorizarAcessoAoGrupo($grupo, request()->user());

        $cobertura = $this->coberturaService->calcular($grupo, $template, now()->startOfMonth());

        return response()->json([
            'referencia' => $cobertura['referencia'],
            'incluidas'  => $cobertura['incluidas'],
            'excluidas'  => $cobertura['excluidas'],
        ]);
    }

    /**
     * Gera o link de NPS do grupo — mesmo espírito do guard de duplicidade
     * do link individual (Plan 02), aplicado à tabela do link de GRUPO:
     * (grupo, modelo, mês) só pode ter 1 registro vivo.
     */
    public function generate(Request $request)
    {
        $data = $request->validate([
            'company_group_id' => 'required|exists:company_groups,id',
            'template_id'      => 'required|exists:nps_templates,id',
        ]);

        $user     = $request->user();
        $grupo    = CompanyGroup::findOrFail($data['company_group_id']);
        $template = NpsTemplate::findOrFail($data['template_id']);

        $this->autorizarAcessoAoGrupo($grupo, $user);

        if (!$template->active) {
            return back()->with('error', 'Este modelo de pesquisa está desativado e não pode gerar novos links.');
        }

        $mes = now()->startOfMonth();

        // Guard de duplicidade — mesmo espírito do Plan 02 ($jaExiste em
        // NpsController::generate()), mas na tabela do link de GRUPO:
        // (grupo, modelo, mês) só pode ter 1 registro. Diferente do link
        // individual, aqui NÃO precisamos do fallback `?? created_at` —
        // `month_reference` do link de grupo é SEMPRE preenchido (a origem
        // é rastreável, D-12 não se aplica — ver interfaces do plano).
        $existente = NpsGroupSurvey::where('company_group_id', $grupo->id)
            ->where('template_id', $template->id)
            ->whereDate('month_reference', $mes)
            ->first();

        if ($existente) {
            return back()->with([
                'nps_link_existente' => route('nps.grupo.respond', $existente->token),
                'error'              => 'Este grupo já tem um link deste modelo de pesquisa neste mês. '
                    .'Enviar um segundo link pode fazer o cliente responder duas vezes. Use o link que já existe.',
            ]);
        }

        // Cobertura calculada AGORA — decide se vale a pena gerar o link.
        // É recalculada de novo no submit (NpsGrupoReplicacaoService, Task 2,
        // nunca confia nesta prévia estática — T-119.1-25).
        $cobertura = $this->coberturaService->calcular($grupo, $template, $mes);

        if (empty($cobertura['incluidas'])) {
            return back()->with('error', 'Nenhuma empresa deste grupo pode receber este link agora. Veja o motivo de cada uma na lista.');
        }

        $groupSurvey = NpsGroupSurvey::create([
            'token'            => Str::uuid()->toString(),
            'company_group_id' => $grupo->id,
            'template_id'      => $template->id,
            'generated_by'     => $user->id,
            'month_reference'  => $mes,
            'expires_at'       => now()->endOfMonth(),
            'status'           => 'pending',
        ]);

        return back()->with([
            'success'  => 'Link de NPS do grupo gerado com sucesso.',
            'nps_link' => route('nps.grupo.respond', $groupSurvey->token),
        ]);
    }

    /**
     * Form público do link de grupo — MESMO shape de payload do individual
     * (`NpsController::respond()`), trocando o nome da empresa pelo nome do
     * GRUPO. NUNCA lista as empresas cobertas/excluídas no payload público
     * (T-119.1-23) — essa lista só existe no endpoint autenticado
     * `previewCobertura()`.
     */
    public function respond(Request $request, string $token)
    {
        $groupSurvey = NpsGroupSurvey::with(['grupo', 'template.questions.options'])
            ->where('token', $token)
            ->where('status', 'pending')
            ->firstOrFail();

        if ($groupSurvey->isExpired()) {
            $groupSurvey->update(['status' => 'expired']);

            return Inertia::render('Nps/Expired');
        }

        // Mesma proteção do fluxo individual (Phase 96 AB-96-1) — usuário
        // interno autenticado não pode abrir/responder o próprio NPS de
        // grupo (T-119.1-26: uma nota inflada aqui contamina N empresas de
        // uma vez só, não apenas uma).
        if (auth()->check()) {
            return Inertia::render('Nps/Blocked');
        }

        $nomeGrupo = $groupSurvey->grupo->name;

        // ─── Textos dinâmicos — mesmo NpsTextRenderer do fluxo individual,
        // com o nome do GRUPO no lugar do nome da empresa nos placeholders.
        $textosBrutos = NpsTextRenderer::getTextos();
        $varsPagina   = [
            'nome_estrategista' => '',
            'nome_analista'     => '',
            'nome_empresa'      => $nomeGrupo,
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

        $templatePayload = null;
        if ($groupSurvey->template) {
            $templatePayload = [
                'id'        => $groupSurvey->template->id,
                'nome'      => $groupSurvey->template->nome,
                'descricao' => $groupSurvey->template->descricao,
                'perguntas' => $groupSurvey->template->questions->map(function ($q) use ($varsPagina) {
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
                'token'             => $groupSurvey->token,
                // Nome do GRUPO no lugar do nome da empresa — nunca a lista
                // de empresas cobertas (T-119.1-23).
                'company_name'      => $nomeGrupo,
                'estrategista_name' => null,
                'analista_name'     => null,
                'tem_analista'      => false,
                'textos'            => $textosRender,
                // Plan 07 (UI) consome estas duas chaves para saber que é um
                // submit de grupo e para onde postar o formulário.
                'submit_url'        => route('nps.grupo.submit', $token),
                'is_grupo'          => true,
            ],
            'perguntas_extras' => collect(),
            'template'         => $templatePayload,
        ]);
    }

    /**
     * Submit do link de grupo — o núcleo da fase: 1 resposta vira N surveys-
     * espelho REAIS via `NpsGrupoReplicacaoService::replicar()`.
     */
    public function submitResponse(Request $request, string $token)
    {
        $groupSurvey = NpsGroupSurvey::with('template.questions.options')
            ->where('token', $token)
            ->firstOrFail();

        if ($groupSurvey->status === 'completed') {
            // Segundo submit no mesmo link — não cria espelhos novos.
            return Inertia::render('Nps/AlreadyCompleted');
        }

        if ($groupSurvey->isExpired()) {
            return response()->json(['error' => 'Pesquisa expirada.'], 422);
        }

        // Mesma proteção do fluxo individual (Phase 96 AB-96-1). Replicada
        // aqui (e não reusada via `NpsController`, que é `private` e é o
        // arquivo mais disputado do módulo — ver docblock da classe) —
        // T-119.1-26: um usuário interno respondendo o próprio link de
        // grupo infla a nota em N empresas de uma vez.
        if (auth()->check()) {
            return Inertia::render('Nps/Blocked');
        }

        if (!$groupSurvey->template) {
            return response()->json(['error' => 'Template do NPS não encontrado.'], 422);
        }

        // ─── Rules dinâmicas — MESMO shape de NpsController::submitResponseV15() ──
        $rules = [
            'respondent_name' => 'nullable|string|max:255',
            'comment'         => 'nullable|string|max:2000',
            'answers'         => 'nullable|array',
        ];

        foreach ($groupSurvey->template->questions as $q) {
            $req = $q->obrigatoria ? 'required' : 'nullable';

            if ($q->tipo === NpsTemplateQuestion::TIPO_TEXTO_LIVRE) {
                $rules["answers.{$q->id}"] = [$req, 'string', 'max:2000'];
            } else {
                $optionIds = $q->options->pluck('id')->all();
                $rules["answers.{$q->id}"] = [$req, 'integer', Rule::in($optionIds)];
            }
        }

        $validated = $request->validate($rules);

        // Fase 94 AB-94-2/AB-94-4 — mesmo rastro/veredito de suspeita do
        // fluxo individual, só que medindo a duração desde a GERAÇÃO DO
        // LINK DE GRUPO (ver método abaixo — a lógica de negócio real
        // continua 100% em NpsSuspicionService, nada é duplicado além do
        // empacotamento trivial do array).
        $rastro = $this->capturarRastroEAvaliarSuspeita($request, $groupSurvey);

        try {
            $this->replicacaoService->replicar(
                $groupSurvey,
                $validated['answers'] ?? [],
                $rastro,
                $validated['respondent_name'] ?? null,
                $validated['comment'] ?? null,
            );
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                // Fase 68 Plan 04 — o dedup unique parcial pode disparar
                // numa empresa coberta que já tenha resposta completa no
                // mês (race entre o link de grupo e um link individual
                // respondido no meio tempo).
                return Inertia::render('Nps/AlreadyCompleted');
            }

            throw $e;
        }

        return Inertia::render('Nps/ThankYou');
    }

    /**
     * Rastro técnico + veredito de suspeita — MESMO padrão de
     * `NpsController::capturarRastroEAvaliarSuspeita()` (privado lá, e
     * portanto não reusável entre controllers sem tocar no arquivo mais
     * disputado do módulo — decisão registrada aqui, não no controller
     * individual). A lógica de negócio de "o que é suspeito" continua
     * 100% dentro de `NpsSuspicionService` (já compartilhado) — nada além
     * do empacotamento trivial do array de retorno é duplicado aqui, e a
     * única diferença real é a origem da duração: `NpsGroupSurvey::created_at`
     * (o momento em que o link de GRUPO foi gerado), não `NpsSurvey::created_at`.
     */
    private function capturarRastroEAvaliarSuspeita(Request $request, NpsGroupSurvey $groupSurvey): array
    {
        $duracao = (int) $groupSurvey->created_at->diffInSeconds(now());

        $veredito = app(NpsSuspicionService::class)->evaluate(
            ip: $request->ip(),
            durationSeconds: $duracao,
            isAuthenticatedSession: auth()->check(),
        );

        return [
            'response_ip_address'       => $request->ip(),
            'response_user_agent'       => $request->userAgent(),
            'response_duration_seconds' => $duracao,
            'is_suspicious'             => $veredito['is_suspicious'],
            'suspicion_reasons'         => $veredito['is_suspicious']
                ? ['reasons' => $veredito['reasons'], 'severity' => $veredito['severity']]
                : null,
        ];
    }

    /**
     * Mesma verificação de acesso de `NpsController::generate()` (Plan 02),
     * adaptada para GRUPO: não-admin só pode gerar/consultar cobertura de
     * um grupo se TODAS as empresas do grupo estiverem na carteira dele —
     * nunca parcial, para não vazar (nem cobertura, nem existência) de
     * empresa fora do escopo do usuário.
     */
    private function autorizarAcessoAoGrupo(CompanyGroup $grupo, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $permitidas = $user->companies()->pluck('companies.id');
        $doGrupo    = $grupo->companies()->pluck('companies.id');

        if ($doGrupo->isEmpty() || $doGrupo->diff($permitidas)->isNotEmpty()) {
            abort(403);
        }
    }
}
