<?php

namespace App\Jobs;

use App\Models\DesempenhoScoreSnapshot;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreSnapshotWriter;
use App\Services\DesempenhoScoreService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Faz o lançamento manual de métrica REFLETIR na nota de Desempenho —
 * inclusive em competência já congelada (2026-08-31).
 *
 * ### Por que só uma parte dos casos precisa deste job
 * `PerformanceController` (linha ~216) escolhe a nota assim:
 * `if (! $ehMesEmCurso && $snap)` → lê o `breakdown_json` do snapshot mensal;
 * senão → `computeCached()` ao vivo. Consequência:
 *
 *  - competência SEM snapshot mensal (mês em curso, ou fechado que nunca
 *    consolidou) já reflete o lançamento assim que o cache cai — e o cache já
 *    cai em `DesempenhoMetricasManuaisController::bustarCacheDaEmpresa()`.
 *    Este job não tem nada a fazer ali, e o loop abaixo sai vazio de graça;
 *  - competência COM snapshot mensal lê um JSON congelado, que nenhuma
 *    invalidação de cache alcança. É o único caso que precisa de reescrita —
 *    e é o que fazia o usuário lançar o CMV e a nota não mudar.
 *
 * ### O gate FIXMARG-03 do `consolidar-mes` NÃO se aplica aqui, de propósito
 * Lá o gate existe porque o comando roda sozinho no cron: se a passada
 * coincidir com rate-limit da Adman, uma leitura degradada sobrescreveria o
 * snapshot bom sem ninguém olhando. Aqui a escrita é consequência direta de um
 * admin digitando um número naquela célula — recusar em silêncio reproduziria
 * exatamente a queixa que este job existe para resolver ("editei e não
 * refletiu"). A cobertura de margem vai para o log em toda execução, para a
 * degradação continuar auditável.
 *
 * A guarda que fica: nota nula ou carteira vazia NUNCA sobrescreve o snapshot.
 * Recomputo que não produziu nota é falha de leitura, não resultado novo.
 */
class AtualizarNotaAposMetricaManualJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;
    public int $tries   = 2;

    /** @param string $mes competência no formato 'Y-m-d' (dia 1) */
    public function __construct(
        public int $companyId,
        public string $mes,
    ) {
        // Fila `high`, NUNCA a `default`. Em produção quem consome `default`
        // são os 2 `ecf-worker`, que dividem espaço com os lotes de sync do
        // acervo ML — medido em 2026-08-31: 170 `SyncMlAcervoDetalheJob`
        // parados na fila, sem drenar em 12s de observação. A nota atrás
        // dessa fila chegaria minutos ou horas depois do lançamento, que para
        // quem está na tela é indistinguível de "não refletiu". O
        // `ecf-worker-high` escuta `high` com exclusividade e estava zerado.
        $this->onQueue('high');
    }

    public function handle(
        DesempenhoScoreService $scoreService,
        CompanyScoreSnapshotWriter $snapshotWriter,
    ): void {
        $mes    = Carbon::createFromFormat('Y-m-d', $this->mes)->startOfMonth();
        $mesStr = $mes->toDateString();

        // `distinct` obrigatório: `company_users` tem várias linhas por
        // (empresa, papel) desde a Fase 76 — uma por serviço.
        $userIds = DB::table('company_users')
            ->where('company_id', $this->companyId)
            ->distinct()
            ->pluck('user_id')
            ->all();

        if ($userIds === []) {
            return;
        }

        // Só quem TEM nota congelada nesta competência. Quem não tem já lê ao
        // vivo e não precisa de nada.
        $snapshots = DesempenhoScoreSnapshot::mensal()
            ->whereIn('user_id', $userIds)
            ->whereDate('mes_referencia', $mesStr)
            ->get()
            ->keyBy('user_id');

        if ($snapshots->isEmpty()) {
            return;
        }

        foreach ($snapshots as $userId => $snapshot) {
            $user = User::find($userId);

            if ($user === null) {
                continue;
            }

            try {
                // `compute()` puro (nunca `computeCached()`): o snapshot é o
                // registro canônico e não pode nascer de payload de até 7 dias.
                $resultado = $scoreService->compute($user, $mes, null, incluirEmpresasScore: true);

                if (($resultado['sem_carteira'] ?? false) === true || ($resultado['nota_final'] ?? null) === null) {
                    Log::warning('[MetricaManual] Recomputo sem nota — snapshot congelado PRESERVADO', [
                        'user_id'        => $userId,
                        'company_id'     => $this->companyId,
                        'mes_referencia' => $mesStr,
                        'sem_carteira'   => $resultado['sem_carteira'] ?? false,
                    ]);
                    continue;
                }

                $scoreAntes = (int) $snapshot->score;

                $snapshot->fill([
                    'score'                => (int) round(((float) $resultado['nota_final']) * 20),
                    'classificacao'        => $resultado['faixa_bonus'] ?? '',
                    'tem_base_comparativa' => $resultado['nota_final'] !== null,
                    'empresas_carteira'    => (int) ($resultado['empresas_carteira'] ?? 0),
                    'empresas_eligiveis'   => (int) ($resultado['empresas_com_baseline'] ?? 0),
                    'breakdown_json'       => $resultado,
                ])->save();

                // O detalhe por empresa precisa acompanhar o resumo, senão a
                // tela do profissional mostra nota nova com linhas velhas.
                // Origem `consolidar_mes` é a única que ignora a trava de
                // congelamento do writer — é o que permite reescrever a
                // competência fechada.
                if (array_key_exists('score_status_por_empresa', $resultado)) {
                    $snapshotWriter->sync(
                        $user,
                        $mes,
                        $resultado['empresas_score'] ?? [],
                        CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
                    );
                }

                Log::info('[MetricaManual] Nota congelada atualizada após lançamento manual', [
                    'user_id'         => $userId,
                    'user_name'       => $user->name,
                    'company_id'      => $this->companyId,
                    'mes_referencia'  => $mesStr,
                    'score_antes'     => $scoreAntes,
                    'score_depois'    => (int) $snapshot->score,
                    'faixa'           => $snapshot->classificacao,
                    // Auditabilidade da degradação que o gate FIXMARG-03
                    // vigiaria no cron (ver docblock da classe).
                    'margem_cobertura' => $resultado['margem_amostra']['legado']['cobertura']
                        ?? $resultado['margem_amostra']['cobertura'] ?? null,
                ]);
            } catch (\Throwable $e) {
                Log::error('[MetricaManual] Falha ao atualizar nota congelada', [
                    'user_id'        => $userId,
                    'company_id'     => $this->companyId,
                    'mes_referencia' => $mesStr,
                    'erro'           => $e->getMessage(),
                ]);
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[MetricaManual] Job de atualização de nota falhou em definitivo', [
            'company_id'     => $this->companyId,
            'mes_referencia' => $this->mes,
            'erro'           => $e->getMessage(),
        ]);
    }
}
