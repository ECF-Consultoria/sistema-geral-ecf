<?php

namespace App\Console\Commands;

use App\Models\NpsResponse;
use App\Models\NpsScoreAssignment;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Services\Nps\NpsSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Remigração cirúrgica do modelo (template) de respostas NPS ESPECÍFICAS —
 * quick task 260715-ndo v16.0.
 *
 * Corrige respostas que já nasceram com o modelo ERRADO por causa do Bug A
 * (`NpsController::generate` ignorava o `template_id` escolhido por
 * não-admin — ver a Tarefa 2 desta quick task). Troca `nps_surveys.template_id`
 * e RECONGELA o snapshot inteiro (`nps_response_scores` /
 * `nps_response_covered_services` / `nps_score_assignments`) via o MESMO
 * motor do fluxo de produção — `NpsSnapshotService::registrar()` — em vez de
 * reimplementar a regra de atribuição na mão.
 *
 * DEC-NDO-5 — SEM seletor genérico por `template_id`: a assinatura exige
 * `--survey=` explícito. Um seletor "todo survey com template=X" é uma
 * bomba-relógio: a partir do fix do Bug A (Tarefa 2), vão existir respostas
 * LEGÍTIMAS sob o modelo antigo (empresa com múltiplos serviços gera um NPS
 * por serviço, de propósito), e uma re-execução do comando com seletor
 * genérico migraria essas respostas legítimas também — corrompendo
 * exatamente o dado que este comando existe para proteger.
 *
 * DEC-NDO-4 — as FKs `template_question_id`/`template_option_id` das
 * `nps_response_answers` NÃO são re-apontadas para o template alvo. As
 * colunas snapshot (`question_texto_snapshot`, `question_dimensao_snapshot`,
 * `option_peso_snapshot`) são a fonte de verdade lida pelo
 * `NpsScoreCalculator` e pelo display — re-apontar a FK não muda nenhum
 * número nem texto na tela. O único leitor da FK viva é a ordenação do
 * detalhe da resposta (`NpsController::show`, `->sortBy('template_question_id')`)
 * — com as FKs intactas (apontando pro template ANTIGO), a ordenação
 * continua correta. Risco residual ACEITO: se o template antigo for
 * excluído no futuro, o `nullOnDelete` zera essas FKs e a ordenação do
 * detalhe dessas respostas degrada (texto/nota seguem intactos, vindo do
 * snapshot) — impacto cosmético, e a decisão de produto é MANTER o modelo
 * antigo vivo e gerável (não seria excluído).
 *
 * DEC-NDO-6 — o `average_score` recongelado PODE mudar em relação ao valor
 * antigo, e isso É ESPERADO: `registrar()` recalcula via `NpsScoreCalculator`
 * VIVO, que já contém o fix do divisor de `texto_livre` (quick task
 * 260715-kam) cujo backfill das linhas antigas ainda não rodou em produção.
 * Não é regressão deste comando — é o mesmo efeito que
 * `nps:backfill-divisor-texto-livre` produziria. O `--dry-run` mostra o
 * diff para o operador conferir antes de aplicar em produção.
 *
 * Máquina de estados por survey (idempotência POR CONSTRUÇÃO — nunca
 * depende de flag nem de data de corte):
 *   - `--survey`/`--para` ausentes, ou `--para` inexistente/inativo → erro
 *     de OPERAÇÃO (é engano do operador, não linha ruim) → `self::FAILURE`,
 *     nada é processado.
 *   - survey inexistente OU sem `response` → `sem_resposta`, pula +
 *     `Log::warning`.
 *   - `survey->template_id === $para` → `ja_migrado`, no-op.
 *   - senão → `migravel`.
 *
 * Diff + confirmação ANTES de gravar de verdade: todo o processamento roda
 * dentro de UMA transação (`DB::beginTransaction()`), o diff é exibido a
 * partir do estado JÁ GRAVADO na transação aberta (assim o "depois" reflete
 * o resultado real de `registrar()`, sem duplicar a lógica de atribuição em
 * modo simulação) — e só então o comando decide: `--dry-run` → `rollBack()`
 * (zero escrita); confirmado/`--force` → `commit()`; recusado → `rollBack()`.
 *
 * Uso real desta quick task (DEC-NDO-5 — IDs explícitos, survey #102 e #106):
 *   php artisan nps:remigrar-modelo-resposta --survey=102 --survey=106 --para=2 --dry-run
 *   php artisan nps:remigrar-modelo-resposta --survey=102 --survey=106 --para=2 --force
 *
 * @see app/Services/Nps/NpsSnapshotService.php (motor reusado)
 * @see app/Console/Commands/NpsBackfillDivisorTextoLivre.php (molde do padrão diff→confirma→grava)
 * @see .planning/quick/260715-ndo-nps-modelo-escolhido-e-migracao/260715-ndo-PLAN.md
 */
class NpsRemigrarModeloResposta extends Command
{
    protected $signature = 'nps:remigrar-modelo-resposta
        {--survey=* : IDs dos nps_surveys a remigrar (obrigatório)}
        {--para=    : ID do template alvo (obrigatório)}
        {--dry-run  : Só mostra o diff, sem gravar}
        {--force    : Pula a confirmação interativa}';

    protected $description = 'Remigra respostas NPS específicas para outro modelo (troca template_id + recongela o snapshot via NpsSnapshotService)';

    public function __construct(private NpsSnapshotService $snapshotService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $surveyIds = array_values(array_unique(array_map('intval', (array) $this->option('survey'))));
        $paraOption = $this->option('para');
        $dryRun = (bool) $this->option('dry-run');
        $force  = (bool) $this->option('force');

        if (empty($surveyIds) || empty($paraOption)) {
            $this->error('[NPS Remigrar] --survey e --para são obrigatórios. Ex.: --survey=102 --survey=106 --para=2');

            return self::FAILURE;
        }

        $paraId = (int) $paraOption;
        $templateAlvo = NpsTemplate::find($paraId);

        if (! $templateAlvo || ! $templateAlvo->active) {
            $this->error("[NPS Remigrar] Template alvo #{$paraId} inexistente ou inativo — confira o id (--para) antes de rodar de novo.");

            return self::FAILURE;
        }

        $stats = [
            'migravel'     => 0,
            'ja_migrado'   => 0,
            'sem_resposta' => 0,
        ];

        $diffs = [];

        $this->info('[NPS Remigrar] Processando ' . count($surveyIds) . ' survey(s) → template #' . $paraId . ($dryRun ? ' (DRY-RUN)' : '') . '...');

        DB::beginTransaction();

        try {
            foreach ($surveyIds as $surveyId) {
                $this->processarSurvey($surveyId, $templateAlvo, $stats, $diffs);
            }

            $this->exibirDiff($diffs);
            $this->exibirStats($stats);

            if (empty($diffs)) {
                $this->info('[NPS Remigrar] Nada a migrar.');
                DB::rollBack();

                return self::SUCCESS;
            }

            if ($dryRun) {
                $this->info('[NPS Remigrar] DRY-RUN — nada foi gravado.');
                DB::rollBack();

                return self::SUCCESS;
            }

            if (! $force) {
                $confirmado = $this->confirm(
                    'Aplicar a remigração em ' . count($diffs) . ' resposta(s) — troca de template + '
                    . 're-atribuição de responsáveis?',
                    false
                );

                if (! $confirmado) {
                    $this->warn('[NPS Remigrar] Operação cancelada pelo operador — nada foi gravado.');
                    DB::rollBack();

                    return self::SUCCESS;
                }
            }

            DB::commit();

            $this->info('[NPS Remigrar] Concluído — ' . count($diffs) . ' resposta(s) remigrada(s).');
            Log::info('[NPS Remigrar] execução concluída', $stats + ['para' => $paraId, 'surveys' => $surveyIds]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('[NPS Remigrar] Falha — nada foi gravado: ' . $e->getMessage());
            Log::error('[NPS Remigrar] falha na execução', [
                'erro'    => $e->getMessage(),
                'surveys' => $surveyIds,
                'para'    => $paraId,
            ]);

            return self::FAILURE;
        }
    }

    /**
     * Classifica e (se migrável) processa 1 survey: troca template_id, apaga
     * o snapshot antigo e recongela via NpsSnapshotService::registrar().
     * Roda DENTRO da transação aberta em handle() — nada aqui commita.
     */
    private function processarSurvey(int $surveyId, NpsTemplate $templateAlvo, array &$stats, array &$diffs): void
    {
        $survey = NpsSurvey::with('response')->find($surveyId);

        if (! $survey || ! $survey->response) {
            $stats['sem_resposta']++;
            $this->warn("[NPS Remigrar] Survey #{$surveyId} inexistente ou sem resposta — pulado.");
            Log::warning('[NPS Remigrar] survey sem resposta — pulado', ['survey_id' => $surveyId]);

            return;
        }

        if ((int) $survey->template_id === $templateAlvo->id) {
            $stats['ja_migrado']++;
            $this->line("[NPS Remigrar] Survey #{$surveyId} já está no template #{$templateAlvo->id} — no-op (idempotência).");

            return;
        }

        $response = $survey->response;
        $templateAntesId = (int) $survey->template_id;

        // Estado ANTES — snapshot de atribuição que estava valendo, para o
        // diff mostrar "de quem → para quem" com nome + média (DEC-NDO-7:
        // arredonda na escala da coluna decimal(5,2), nunca tolerância 0.0001).
        $antes = $this->snapshotAtribuicoes($response->id);

        // 1. Troca o template do survey.
        $survey->update(['template_id' => $templateAlvo->id]);

        // 2. Apaga o snapshot antigo — registrar() usa create(), duplicaria
        //    se não apagarmos primeiro. Ordem: assignments (FK
        //    nps_response_score_id) → covered_services → scores.
        $response->scoreAssignments()->delete();
        $response->coveredServices()->delete();
        $response->scores()->delete();

        // 3. Re-hidrata SEM relação em cache — registrar() lê
        //    $response->survey->template, e um model stale traria o template
        //    ANTIGO da relação já carregada acima.
        $fresh = NpsResponse::with('survey.template')->find($response->id);

        if (! $fresh || (int) $fresh->survey->template_id !== $templateAlvo->id) {
            throw new \RuntimeException("Falha ao re-hidratar a resposta #{$response->id} com o template alvo #{$templateAlvo->id} antes de recongelar.");
        }

        // 4. Recongela via o MESMO motor do fluxo de produção — não
        //    reimplementa a regra de atribuição na mão.
        $this->snapshotService->registrar($fresh);

        // Estado DEPOIS.
        $depois = $this->snapshotAtribuicoes($response->id);

        $stats['migravel']++;
        $diffs[] = [
            'survey_id'       => $surveyId,
            'response_id'     => $response->id,
            'template_antes'  => $templateAntesId,
            'template_depois' => $templateAlvo->id,
            'antes'           => $antes,
            'depois'          => $depois,
        ];
    }

    /**
     * Lê as atribuições atuais de uma resposta (role, serviço, responsável,
     * média arredondada na escala da coluna decimal(5,2) — DEC-NDO-7).
     *
     * @return array<int, array{role:string, servico:string, user:string, average:float}>
     */
    private function snapshotAtribuicoes(int $responseId): array
    {
        return NpsScoreAssignment::where('nps_response_id', $responseId)
            ->with('user')
            ->get()
            ->map(fn (NpsScoreAssignment $a) => [
                'role'    => $a->role,
                'servico' => $a->service_setor,
                'user'    => $a->user->name ?? "user#{$a->user_id}",
                'average' => round((float) $a->average_score, 2),
            ])
            ->all();
    }

    private function exibirDiff(array $diffs): void
    {
        if (empty($diffs)) {
            return;
        }

        $this->info('[NPS Remigrar] Diff (ANTES de gravar):');
        $this->table(
            ['survey_id', 'response_id', 'template antes → depois', 'de quem → para quem'],
            collect($diffs)->map(function (array $d) {
                $formata = fn (array $atribuicoes) => collect($atribuicoes)
                    ->map(fn (array $a) => "{$a['user']} ({$a['servico']}/{$a['role']}) = {$a['average']}")
                    ->implode(' | ') ?: '(nenhuma)';

                return [
                    $d['survey_id'],
                    $d['response_id'],
                    "{$d['template_antes']} → {$d['template_depois']}",
                    $formata($d['antes']) . "\n→ " . $formata($d['depois']),
                ];
            })->toArray()
        );
    }

    private function exibirStats(array $stats): void
    {
        $this->table(
            ['Métrica', 'Valor'],
            collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->toArray()
        );
    }
}
