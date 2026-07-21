<?php

namespace App\Console\Commands;

use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use App\Models\NpsResponseScore;
use App\Models\NpsScoreAssignment;
use App\Models\NpsTemplateQuestion;
use App\Services\Nps\NpsScoreCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Troca a DIMENSÃO de uma pergunta de modelo NPS e aplica a mudança
 * RETROATIVAMENTE às respostas já dadas — quick task 260721.
 *
 * Caso de uso concreto: a pergunta 21 do modelo Performance (id=45) estava em
 * `analista` e a diretoria decidiu que ela deve contar para Analista E
 * Estrategista (dimensão `ambos`, quick task 260716-jps). Setar a dimensão pela
 * UI de /nps/configuracao só afeta respostas NOVAS — o histórico congelado
 * (`nps_response_answers.question_dimensao_snapshot` + `nps_response_scores` +
 * `nps_score_assignments`) continua com a dimensão antiga. Este comando alinha
 * o passado.
 *
 * O que faz, numa única transação:
 *  1. `nps_template_questions.dimensao = <para>`  (dimensão VIVA da pergunta —
 *     é o denominador lido pelo NpsScoreCalculator; sem isso a soma da nova
 *     dimensão não teria divisor).
 *  2. `UPDATE nps_response_answers SET question_dimensao_snapshot=<para>`
 *     para TODAS as answers dessa pergunta — a foto congelada por-resposta que
 *     é a FONTE de verdade do numerador (cálculo ao vivo, bônus legado e
 *     re-freeze do snapshot leem daqui).
 *  3. Atualiza CIRURGICAMENTE as respostas que JÁ tinham snapshot (Fase 79+):
 *     recalcula `score_sum`/`question_count`/`average_score` de cada
 *     `nps_response_scores` EXISTENTE (via `NpsScoreCalculator`, a fonte única)
 *     e propaga o novo `average_score` para as `nps_score_assignments` do papel
 *     correspondente — SEM re-derivar responsável nem serviços cobertos.
 *     Respostas LEGADAS (sem snapshot) NÃO são tocadas além do passo 2 — leem
 *     do cálculo ao vivo, que já reflete a nova dimensão sozinho.
 *
 * POR QUE NÃO usar `NpsSnapshotService::registrar()` (re-freeze completo): ele
 * RECONSTRÓI as atribuições a partir da carteira ATUAL (`company_users`) e dos
 * contratos ATIVOS de HOJE. Um contrato que expirou ou um responsável trocado
 * desde a resposta APAGARIA/moveria a atribuição congelada — exatamente o que a
 * Fase 79 existe para impedir. A atualização cirúrgica preserva o responsável
 * congelado e só corrige o NÚMERO afetado pela mudança de dimensão.
 * NÃO cria linha de score/atribuição nova (dimensão que não existia na resposta
 * fica para o cálculo ao vivo) — assim nunca precisa adivinhar responsável.
 *
 * Padrão diff→confirma→grava (dry-run faz rollback) copiado de
 * `NpsRemigrarModeloResposta` — mesma malha de segurança.
 *
 * Uso:
 *   php artisan nps:pergunta-set-dimensao --pergunta=45 --para=ambos --dry-run
 *   php artisan nps:pergunta-set-dimensao --pergunta=45 --para=ambos --force
 *
 * @see app/Services/Nps/NpsSnapshotService.php (motor reusado)
 * @see app/Console/Commands/NpsRemigrarModeloResposta.php (molde do padrão)
 * @see app/Models/NpsTemplateQuestion.php (const DIMENSOES + dimensoesFonte)
 */
class NpsPerguntaSetDimensao extends Command
{
    protected $signature = 'nps:pergunta-set-dimensao
        {--pergunta=* : IDs de nps_template_questions a alterar (obrigatório)}
        {--para=      : Dimensão alvo (estrategista|analista|ambos|empresa|geral) (obrigatório)}
        {--dry-run    : Só mostra o diff, sem gravar}
        {--force      : Pula a confirmação interativa}';

    protected $description = 'Troca a dimensão de perguntas NPS e reaplica retroativamente ao histórico (rewrite snapshot + recálculo cirúrgico dos scores/atribuições existentes)';

    public function __construct(private NpsScoreCalculator $calculator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $perguntaIds = array_values(array_unique(array_map('intval', (array) $this->option('pergunta'))));
        $para   = (string) $this->option('para');
        $dryRun = (bool) $this->option('dry-run');
        $force  = (bool) $this->option('force');

        if (empty($perguntaIds) || $para === '') {
            $this->error('[NPS SetDim] --pergunta e --para são obrigatórios. Ex.: --pergunta=45 --para=ambos');

            return self::FAILURE;
        }

        if (! in_array($para, NpsTemplateQuestion::DIMENSOES, true)) {
            $this->error('[NPS SetDim] Dimensão inválida: ' . $para . '. Válidas: ' . implode(', ', NpsTemplateQuestion::DIMENSOES));

            return self::FAILURE;
        }

        $stats = [
            'perguntas'          => 0,
            'answers_reescritas' => 0,
            'respostas_recongeladas' => 0,
            'respostas_legado'   => 0,
        ];

        $diffs = [];

        $this->info('[NPS SetDim] ' . count($perguntaIds) . ' pergunta(s) → dimensão "' . $para . '"' . ($dryRun ? ' (DRY-RUN)' : '') . '...');

        DB::beginTransaction();

        try {
            foreach ($perguntaIds as $perguntaId) {
                $this->processarPergunta($perguntaId, $para, $stats, $diffs);
            }

            $this->exibirDiff($diffs);
            $this->exibirStats($stats);

            if ($stats['perguntas'] === 0) {
                $this->warn('[NPS SetDim] Nenhuma pergunta válida processada.');
                DB::rollBack();

                return self::SUCCESS;
            }

            if ($dryRun) {
                $this->info('[NPS SetDim] DRY-RUN — nada foi gravado.');
                DB::rollBack();

                return self::SUCCESS;
            }

            if (! $force) {
                $confirmado = $this->confirm(
                    'Aplicar a mudança de dimensão + recongelamento de ' . $stats['respostas_recongeladas']
                    . ' resposta(s) com snapshot (o bônus pode mudar)?',
                    false
                );

                if (! $confirmado) {
                    $this->warn('[NPS SetDim] Operação cancelada — nada foi gravado.');
                    DB::rollBack();

                    return self::SUCCESS;
                }
            }

            DB::commit();

            $this->info('[NPS SetDim] Concluído.');
            Log::info('[NPS SetDim] execução concluída', $stats + ['para' => $para, 'perguntas' => $perguntaIds]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('[NPS SetDim] Falha — nada foi gravado: ' . $e->getMessage());
            Log::error('[NPS SetDim] falha na execução', [
                'erro'      => $e->getMessage(),
                'perguntas' => $perguntaIds,
                'para'      => $para,
            ]);

            return self::FAILURE;
        }
    }

    /**
     * Processa 1 pergunta: seta dimensão viva, reescreve o snapshot das answers
     * e recongela as respostas que já tinham snapshot. Roda DENTRO da transação
     * aberta em handle() — nada aqui commita.
     */
    private function processarPergunta(int $perguntaId, string $para, array &$stats, array &$diffs): void
    {
        $pergunta = NpsTemplateQuestion::find($perguntaId);

        if (! $pergunta) {
            $this->warn("[NPS SetDim] Pergunta #{$perguntaId} inexistente — pulada.");

            return;
        }

        $dimAntes = $pergunta->dimensao;

        if ($dimAntes === $para) {
            $this->line("[NPS SetDim] Pergunta #{$perguntaId} já está em '{$para}' na dimensão viva — seguindo mesmo assim para alinhar o histórico.");
        }

        // Respostas afetadas = as que responderam esta pergunta.
        $respIds = NpsResponseAnswer::where('template_question_id', $perguntaId)
            ->pluck('response_id')
            ->unique()
            ->values();

        // Snapshot-cobertas (Fase 79+) = têm ao menos 1 linha em nps_response_scores.
        $comSnapshot = NpsResponseScore::whereIn('nps_response_id', $respIds)
            ->pluck('nps_response_id')
            ->unique()
            ->values();

        $legadas = $respIds->diff($comSnapshot);

        // 'ANTES' das atribuições (só faz sentido para as com snapshot) — captura
        // antes de qualquer mutação de re-freeze; setar a dimensão viva e
        // reescrever o snapshot das answers NÃO altera as linhas de assignment.
        $antesPorResp = [];
        foreach ($comSnapshot as $rid) {
            $antesPorResp[$rid] = $this->snapshotAtribuicoes($rid);
        }

        // 1. Dimensão VIVA da pergunta (denominador do calculator).
        $pergunta->update(['dimensao' => $para]);

        // 2. Reescreve a foto congelada por-resposta (numerador / fonte de verdade).
        $reescritas = NpsResponseAnswer::where('template_question_id', $perguntaId)
            ->update(['question_dimensao_snapshot' => $para]);

        // 3. Atualização CIRÚRGICA das respostas com snapshot (sem re-atribuir).
        foreach ($comSnapshot as $rid) {
            $fresh = NpsResponse::with('survey')->find($rid);
            if (! $fresh || ! $fresh->survey || ! $fresh->survey->template_id) {
                // Sem template vivo → não há divisor confiável; o cálculo ao vivo
                // ainda reflete o passo 2. Pula o recálculo do snapshot.
                continue;
            }

            $this->recalcularSnapshot($fresh);

            $diffs[] = [
                'pergunta'    => $perguntaId,
                'response_id' => $rid,
                'antes'       => $antesPorResp[$rid] ?? [],
                'depois'      => $this->snapshotAtribuicoes($rid),
            ];
        }

        $stats['perguntas']++;
        $stats['answers_reescritas']     += (int) $reescritas;
        $stats['respostas_recongeladas'] += $comSnapshot->count();
        $stats['respostas_legado']       += $legadas->count();

        $this->line(
            "[NPS SetDim] Pergunta #{$perguntaId} (tpl {$pergunta->template_id}): '{$dimAntes}' → '{$para}' | "
            . "{$reescritas} answers | " . $comSnapshot->count() . " c/ snapshot | " . $legadas->count() . ' legado'
        );
    }

    /**
     * Recalcula CIRURGICAMENTE o snapshot de UMA resposta: atualiza o valor de
     * cada `nps_response_scores` EXISTENTE e propaga o novo `average_score` às
     * `nps_score_assignments` do papel correspondente. NÃO cria nem apaga linhas
     * — o responsável e os serviços cobertos congelados ficam intactos (só o
     * número muda). Dimensão que não tem linha na resposta fica para o cálculo
     * ao vivo (evita adivinhar responsável de dimensão nova).
     *
     * Roda DENTRO da transação de handle(). Pressupõe que a dimensão viva da
     * pergunta e o `question_dimensao_snapshot` das answers JÁ foram atualizados
     * (passos 1 e 2) — o calculator lê desses dois.
     */
    private function recalcularSnapshot(NpsResponse $response): void
    {
        $templateId = (int) $response->survey->template_id;

        // Papel da pivot por dimensão de score (idêntico ao NpsSnapshotService):
        // analista→consultor, estrategista→estrategista. empresa não tem papel.
        $dimensaoRole = [
            NpsTemplateQuestion::DIMENSAO_ANALISTA     => 'consultor',
            NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA => 'estrategista',
        ];

        // Só as dimensões de score que JÁ existem nesta resposta.
        $scores = NpsResponseScore::where('nps_response_id', $response->id)->get();

        foreach ($scores as $scoreRow) {
            $dim = $scoreRow->dimensao;

            $novoAvg   = $this->calculator->compute($response, $dim);
            $novoCount = $this->calculator->contarPerguntasComPeso($templateId, $dim);

            if ($novoAvg === null || $novoCount === 0) {
                // Não deveria acontecer (a linha existe porque havia base), mas
                // se acontecer preservamos a linha como está — nunca zeramos.
                Log::warning('[NPS SetDim] recálculo devolveu vazio — linha preservada', [
                    'response_id' => $response->id,
                    'dimensao'    => $dim,
                ]);

                continue;
            }

            $novoSum = (float) $response->answers()
                ->whereIn('question_dimensao_snapshot', NpsTemplateQuestion::dimensoesFonte($dim))
                ->sum('option_peso_snapshot');

            $scoreRow->update([
                'score_sum'      => $novoSum,
                'question_count' => $novoCount,
                'average_score'  => $novoAvg,
                'calculated_at'  => now(),
            ]);

            // Propaga o novo average às atribuições EXISTENTES do papel — sem
            // mexer em user_id/servico_id (responsável congelado preservado).
            $role = $dimensaoRole[$dim] ?? null;
            if ($role !== null) {
                NpsScoreAssignment::where('nps_response_id', $response->id)
                    ->where('role', $role)
                    ->update(['average_score' => $novoAvg]);
            }
        }
    }

    /**
     * Lê as atribuições atuais de uma resposta (role, serviço, responsável,
     * média arredondada na escala decimal(5,2)) — igual ao NpsRemigrarModeloResposta.
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
            $this->line('[NPS SetDim] Nenhuma resposta com snapshot para recongelar (só legado ou nenhuma).');

            return;
        }

        $this->info('[NPS SetDim] Diff das atribuições recongeladas (ANTES de gravar):');
        $this->table(
            ['pergunta', 'response_id', 'de quem → para quem'],
            collect($diffs)->map(function (array $d) {
                $formata = fn (array $atribuicoes) => collect($atribuicoes)
                    ->map(fn (array $a) => "{$a['user']} ({$a['servico']}/{$a['role']}) = {$a['average']}")
                    ->implode(' | ') ?: '(nenhuma)';

                return [
                    $d['pergunta'],
                    $d['response_id'],
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
