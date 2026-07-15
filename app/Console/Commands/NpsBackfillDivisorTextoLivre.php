<?php

namespace App\Console\Commands;

use App\Models\NpsResponseScore;
use App\Models\NpsScoreAssignment;
use App\Models\NpsTemplateQuestion;
use App\Services\Nps\NpsScoreCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfill idempotente das notas NPS congeladas afetadas pelo bug do divisor
 * de `texto_livre` — quick task 260715-kam v16.0.
 *
 * Corrige retroativamente `nps_response_scores.question_count`/`average_score`
 * (e propaga pra `nps_score_assignments.average_score`) das linhas gravadas
 * ANTES do fix de `NpsScoreCalculator::contarPerguntasComPeso()`, quando o
 * divisor ainda contava perguntas `texto_livre` (que nunca têm peso).
 *
 * NÃO é migration: o número corrigido vira valor de BÔNUS (13 linhas em
 * `nps_score_assignments` hoje em produção) — o operador precisa poder
 * inspecionar o diff antes de gravar, e o comando precisa ser re-executável
 * (rodar de novo depois de mais respostas chegarem não deve reprocessar o
 * que já está correto).
 *
 * Máquina de estados por linha de `nps_response_scores` (idempotência POR
 * CONSTRUÇÃO — nunca depende de flag nem de data de corte):
 *
 *   $alvo   = calculator->contarPerguntasComPeso($templateId, $dimensao) // divisor CORRETO hoje
 *   $nTotal = total de perguntas do template na dimensão (TODOS os tipos)  // divisor BUGADO de antes
 *
 *   - $alvo === 0             → PULADO 'sem_base' (dimensão sem pergunta com peso
 *                                HOJE no template vivo — gravar seria inventar dado).
 *   - $qcArmazenado === $alvo → JA_CORRIGIDO (no-op). Se `average_score` não bater
 *                                com `score_sum / $alvo` (execução parcial anterior
 *                                ou template sem texto_livre — $alvo == $nTotal),
 *                                corrige só o average.
 *   - $qcArmazenado === $nTotal → CORRIGÍVEL (estado bugado, template ÍNTEGRO desde
 *                                a resposta) → aplica question_count=$alvo e
 *                                average_score=score_sum/$alvo.
 *   - senão                   → DIVERGENTE → PULA + Log::warning. O template mudou
 *                                de estrutura desde a resposta — não dá pra provar
 *                                qual era o divisor histórico. Revisão manual.
 *
 * `score_sum` é PRESERVADO intacto em toda execução — o bug é só do
 * denominador (texto_livre grava peso NULL, o SUM sempre esteve certo).
 *
 * Uso:
 *   php artisan nps:backfill-divisor-texto-livre --dry-run   # só mostra o diff
 *   php artisan nps:backfill-divisor-texto-livre             # pede confirmação
 *   php artisan nps:backfill-divisor-texto-livre --force     # sem confirmação
 *
 * @see app/Services/Nps/NpsScoreCalculator.php (fonte única do divisor)
 * @see .planning/quick/260715-kam-nps-divisor-texto-livre/260715-kam-PLAN.md
 */
class NpsBackfillDivisorTextoLivre extends Command
{
    protected $signature = 'nps:backfill-divisor-texto-livre
        {--dry-run : Só mostra o diff, sem gravar}
        {--force   : Pula a confirmação interativa}';

    protected $description = 'Corrige retroativamente as notas NPS congeladas afetadas pelo bug do divisor de texto_livre';

    public function __construct(private NpsScoreCalculator $calculator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force  = (bool) $this->option('force');

        $stats = [
            'ja_corrigido'           => 0,
            'corrigidos'             => 0,
            'divergentes'            => 0,
            'sem_base'               => 0,
            'sem_template'           => 0,
            'assignments_atualizados' => 0,
            'erros'                  => 0,
        ];

        $this->info('[NPS Backfill] Analisando nps_response_scores...' . ($dryRun ? ' (DRY-RUN)' : ''));

        // ─── Passo 1: classificação (read-only, roda sempre) ─────────────
        $correcoes = $this->classificar($stats);

        // ─── Passo 2: relatório de auditoria (ANTES de gravar qualquer coisa) ──
        $this->exibirDiff($correcoes);
        $this->exibirStats($stats);

        if (empty($correcoes)) {
            $this->info('[NPS Backfill] Nada a corrigir.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info('[NPS Backfill] DRY-RUN — nada foi gravado.');

            return self::SUCCESS;
        }

        if (! $force) {
            $confirmado = $this->confirm(
                'Aplicar correção em ' . count($correcoes) . ' linha(s) de nps_response_scores '
                . '(e propagar pra nps_score_assignments)?',
                false
            );

            if (! $confirmado) {
                $this->warn('[NPS Backfill] Operação cancelada pelo operador — nada foi gravado.');

                return self::SUCCESS;
            }
        }

        // ─── Passo 3: gravação ─────────────────────────────────────────
        $this->aplicarCorrecoes($correcoes, $stats);

        $this->info('[NPS Backfill] Concluído:');
        $this->exibirStats($stats);

        Log::info('[NPS Backfill] execução concluída', $stats);

        return self::SUCCESS;
    }

    /**
     * Lê todas as linhas de `nps_response_scores` e classifica cada uma
     * segundo a máquina de estados do docblock da classe. Retorna apenas as
     * linhas CORRIGÍVEIS ou que precisam de ajuste de `average_score`
     * (ja_corrigido puro fica só nos $stats, não entra no array retornado).
     *
     * @return array<int, array{score_id:int, response_id:int, dimensao:string,
     *   qc_antes:int, qc_depois:int, media_antes:float, media_depois:float}>
     */
    private function classificar(array &$stats): array
    {
        $correcoes = [];

        NpsResponseScore::query()
            ->with('response.survey')
            ->orderBy('id')
            ->chunkById(200, function ($scores) use (&$correcoes, &$stats) {
                foreach ($scores as $score) {
                    try {
                        $this->classificarLinha($score, $correcoes, $stats);
                    } catch (\Throwable $e) {
                        $stats['erros']++;
                        Log::error('[NPS Backfill] falha ao classificar score', [
                            'score_id' => $score->id,
                            'erro'     => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $correcoes;
    }

    private function classificarLinha(NpsResponseScore $score, array &$correcoes, array &$stats): void
    {
        $survey = $score->response?->survey;

        if (! $survey || ! $survey->template_id) {
            // Fluxo legacy (sem template) — nada a corrigir, o snapshot nem
            // deveria existir nesse caso, mas não adivinhamos.
            $stats['sem_template']++;
            Log::warning('[NPS Backfill] score sem survey/template — pulado', [
                'score_id'        => $score->id,
                'nps_response_id' => $score->nps_response_id,
            ]);

            return;
        }

        $templateId = $survey->template_id;
        $dimensao   = $score->dimensao;

        $alvo = $this->calculator->contarPerguntasComPeso($templateId, $dimensao);

        if ($alvo === 0) {
            // Dimensão sem NENHUMA pergunta com peso no template VIVO hoje —
            // gravar uma nota aqui seria inventar dado. Pula e reporta.
            $stats['sem_base']++;
            Log::warning('[NPS Backfill] dimensão sem pergunta com peso no template vivo — pulado', [
                'score_id'    => $score->id,
                'template_id' => $templateId,
                'dimensao'    => $dimensao,
            ]);

            return;
        }

        $nTotal = NpsTemplateQuestion::query()
            ->where('template_id', $templateId)
            ->where('dimensao', $dimensao)
            ->count();

        $qcArmazenado = $score->question_count;

        if ($qcArmazenado === $alvo) {
            // question_count já está no divisor correto. Só falta garantir
            // que average_score bate — cobre re-execução parcial anterior E
            // o caso nTextoLivre==0 (nada a fazer nos dois casos).
            $mediaEsperada = $score->score_sum / $alvo;

            if (abs($score->average_score - $mediaEsperada) > 0.0001) {
                $correcoes[] = [
                    'score_id'    => $score->id,
                    'response_id' => $score->nps_response_id,
                    'dimensao'    => $dimensao,
                    'qc_antes'    => $qcArmazenado,
                    'qc_depois'   => $alvo,
                    'media_antes' => $score->average_score,
                    'media_depois' => $mediaEsperada,
                ];
                $stats['corrigidos']++;
            } else {
                $stats['ja_corrigido']++;
            }

            return;
        }

        if ($qcArmazenado === $nTotal) {
            // Estado BUGADO clássico: question_count foi gravado com o
            // divisor antigo (todas as perguntas, incluindo texto_livre) e o
            // template não mudou de estrutura desde então — corrigível.
            $mediaNova = $score->score_sum / $alvo;

            $correcoes[] = [
                'score_id'    => $score->id,
                'response_id' => $score->nps_response_id,
                'dimensao'    => $dimensao,
                'qc_antes'    => $qcArmazenado,
                'qc_depois'   => $alvo,
                'media_antes' => $score->average_score,
                'media_depois' => $mediaNova,
            ];
            $stats['corrigidos']++;

            return;
        }

        // DIVERGENTE: question_count não bate nem com o divisor correto nem
        // com o total bugado — o template mudou de estrutura depois da
        // resposta. Não dá pra provar qual era o divisor histórico. PULA.
        $stats['divergentes']++;
        Log::warning('[NPS Backfill] question_count divergente — template mudou de estrutura, pulado', [
            'score_id'       => $score->id,
            'template_id'    => $templateId,
            'dimensao'       => $dimensao,
            'qc_armazenado'  => $qcArmazenado,
            'alvo_hoje'      => $alvo,
            'total_hoje'     => $nTotal,
        ]);
    }

    /**
     * Aplica as correções dentro de uma transação e propaga o average_score
     * corrigido para nps_score_assignments (via nps_response_score_id).
     * Try/catch por linha — uma resposta corrompida não aborta o lote.
     */
    private function aplicarCorrecoes(array $correcoes, array &$stats): void
    {
        DB::transaction(function () use ($correcoes, &$stats) {
            foreach ($correcoes as $correcao) {
                try {
                    $score = NpsResponseScore::find($correcao['score_id']);

                    if (! $score) {
                        continue;
                    }

                    $score->update([
                        'question_count' => $correcao['qc_depois'],
                        'average_score'  => $correcao['media_depois'],
                    ]);

                    // Propaga só as linhas cujo valor de fato mudou.
                    $assignments = NpsScoreAssignment::where('nps_response_score_id', $score->id)
                        ->where('average_score', '!=', $score->average_score)
                        ->get();

                    foreach ($assignments as $assignment) {
                        $assignment->update(['average_score' => $score->average_score]);
                        $stats['assignments_atualizados']++;
                    }
                } catch (\Throwable $e) {
                    $stats['erros']++;
                    Log::error('[NPS Backfill] falha ao aplicar correção', [
                        'score_id' => $correcao['score_id'],
                        'erro'     => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    private function exibirDiff(array $correcoes): void
    {
        if (empty($correcoes)) {
            return;
        }

        $this->info('[NPS Backfill] Diff (ANTES de gravar):');
        $this->table(
            ['response_id', 'dimensao', 'qc_antes → qc_depois', 'media_antes → media_depois'],
            collect($correcoes)->map(fn ($c) => [
                $c['response_id'],
                $c['dimensao'],
                "{$c['qc_antes']} → {$c['qc_depois']}",
                number_format($c['media_antes'], 2) . ' → ' . number_format($c['media_depois'], 2),
            ])->toArray()
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
