<?php

namespace App\Services\Desempenho;

use App\Models\DesempenhoCompanyScoreSnapshot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Escritor idempotente da tabela `desempenho_company_score_snapshots`
 * (Fase 122, SNAP-02). Único ponto de escrita — os três comandos da Wave 2
 * (`ConsolidarMesDesempenho`, `SnapshotDesempenhoScores`, `WarmDesempenhoCache`)
 * chamam `sync()`, nunca `updateOrCreate` direto.
 *
 * Este serviço só PERSISTE o que recebe — não recalcula régua, nota ou
 * componente nenhum. A fonte da verdade do shape de cada linha é
 * `CompanyScoreService::computeEmpresasScore()` (Fase 119).
 *
 * ─── Decisões de design (122-01-PLAN.md) ──────────────────────────────────
 *  D-122-01 · `mes_referencia` é sempre `YYYY-MM-01` (competência, nunca um
 *  dia isolado) — normalizado aqui via `startOfMonth()`.
 *  D-122-02 · trava de congelamento por `origem`: escrita vinda de
 *  `snapshot_diario`/`warm_cache` NUNCA sobrescreve uma competência que já
 *  tem linha gravada por `consolidar_mes`. `consolidar_mes` ignora a trava
 *  de propósito — reconsolidar competência fechada é caminho oficial
 *  (SNAP-06).
 *  D-122-03 · `sync()` é upsert + prune numa transação, nunca insert-only:
 *  re-execução sobre a mesma competência CONVERGE para o mesmo conjunto de
 *  linhas — nunca duplica, e poda quem saiu da carteira/foi invalidado.
 *  D-122-08 · a consulta que decide o congelamento usa `lockForUpdate()`
 *  DENTRO da mesma transação da escrita — serializa
 *  `desempenho:warm-cache` (cron a cada 8min) contra
 *  `desempenho:consolidar-mes` (execução manual) sob colisão real, não
 *  hipotética. Em SQLite (testes) é no-op; em MariaDB (produção) é o que
 *  faz a trava valer sob concorrência.
 *
 * @see App\Services\Desempenho\CompanyScoreService::computeEmpresasScore()
 */
class CompanyScoreSnapshotWriter
{
    public const ORIGEM_CONSOLIDAR_MES  = 'consolidar_mes';
    public const ORIGEM_SNAPSHOT_DIARIO = 'snapshot_diario';
    public const ORIGEM_WARM_CACHE      = 'warm_cache';

    /**
     * @param  iterable<int, object|array>  $empresasScore  linhas no shape
     *         de `CompanyScoreService::computeEmpresasScore()` (ver
     *         interface documentada em 122-01-PLAN.md). Aceita `stdClass`
     *         (retorno direto de `compute()`) ou array (payload
     *         cacheado/JSON já decodificado).
     * @return array{upserted: int, pruned: int, congelado: bool}
     */
    public function sync(User $user, Carbon $mes, iterable $empresasScore, string $origem): array
    {
        $mes    = $mes->copy()->startOfMonth();
        $mesStr = $mes->toDateString();

        return DB::transaction(function () use ($user, $mesStr, $empresasScore, $origem) {
            // D-122-02/D-122-08 — trava de congelamento, com lock explícito
            // na leitura que decide. Só se aplica a origem que NÃO é
            // consolidar_mes; consolidar_mes ignora a trava de propósito.
            if ($origem !== self::ORIGEM_CONSOLIDAR_MES) {
                $congelado = DesempenhoCompanyScoreSnapshot::query()
                    ->where('user_id', $user->id)
                    ->whereDate('mes_referencia', $mesStr)
                    ->where('origem', self::ORIGEM_CONSOLIDAR_MES)
                    ->lockForUpdate()
                    ->exists();

                if ($congelado) {
                    return ['upserted' => 0, 'pruned' => 0, 'congelado' => true];
                }
            }

            // Upsert — uma row por company_id recebido.
            $idsAtuais = [];
            $upserted  = 0;

            foreach ($empresasScore as $linha) {
                $linhaArray = (array) $linha;

                $companyId = $linhaArray['company_id'] ?? null;
                if ($companyId === null) {
                    continue;
                }

                $idsAtuais[] = $companyId;

                $dados = [
                    'company_name'          => $linhaArray['company_name'] ?? null,
                    'fonte_financeira'      => $linhaArray['fonte_financeira'] ?? null,
                    'status'                => $linhaArray['status'] ?? null,
                    'nps_pontos'            => $linhaArray['nps_pontos'] ?? null,
                    'faturamento_atual'     => $linhaArray['faturamento_atual'] ?? null,
                    'faturamento_anterior'  => $linhaArray['faturamento_anterior'] ?? null,
                    'faturamento_var_pct'   => $linhaArray['faturamento_var_pct'] ?? null,
                    'faturamento_pontos'    => $linhaArray['faturamento_pontos'] ?? null,
                    'margem_pct_atual'      => $linhaArray['margem_pct_atual'] ?? null,
                    'margem_pct_anterior'   => $linhaArray['margem_pct_anterior'] ?? null,
                    'margem_var_pp'         => $linhaArray['margem_var_pp'] ?? null,
                    'margem_pontos'         => $linhaArray['margem_pontos'] ?? null,
                    'componentes_presentes' => $linhaArray['componentes_presentes'] ?? 0,
                    'nota_empresa'          => $linhaArray['nota_empresa'] ?? null,
                    'nota_empresa_parcial'  => $linhaArray['nota_empresa_parcial'] ?? null,
                    // quality grava o sub-array inteiro — o cast
                    // `array` do model serializa.
                    'quality'               => $linhaArray['quality'] ?? null,
                    'origem'                => $origem,
                    'gerado_em'             => now(),
                ];

                // Busca manual com `whereDate()` em vez de `updateOrCreate()`
                // com `mes_referencia` cru no array de busca: o cast `date`
                // do model grava a coluna como datetime completo
                // (`Y-m-d H:i:s`), e `updateOrCreate()` compara a string crua
                // `$mesStr` ('Y-m-d') contra esse valor já gravado — nunca
                // bate, e cada chamada tenta INSERT de novo, colidindo com a
                // chave única. `whereDate()` compara só a parte de data,
                // imune a essa diferença de formato (SQLite não trunca DATE
                // como o MariaDB de produção faria).
                $existente = DesempenhoCompanyScoreSnapshot::query()
                    ->where('user_id', $user->id)
                    ->where('company_id', $companyId)
                    ->whereDate('mes_referencia', $mesStr)
                    ->first();

                if ($existente) {
                    $existente->fill($dados)->save();
                } else {
                    DesempenhoCompanyScoreSnapshot::create($dados + [
                        'user_id'        => $user->id,
                        'company_id'     => $companyId,
                        'mes_referencia' => $mesStr,
                    ]);
                }

                $upserted++;
            }

            // D-122-03 — prune: apaga rows do (user, competência) fora do
            // conjunto recém-gravado. Condição de coleção vazia explícita —
            // não depender do comportamento implícito de whereNotIn([]).
            $pruneQuery = DesempenhoCompanyScoreSnapshot::query()
                ->where('user_id', $user->id)
                ->whereDate('mes_referencia', $mesStr);

            if ($idsAtuais === []) {
                $pruned = (clone $pruneQuery)->count();
                $pruneQuery->delete();
            } else {
                $pruneQuery->whereNotIn('company_id', $idsAtuais);
                $pruned = (clone $pruneQuery)->count();
                $pruneQuery->delete();
            }

            return ['upserted' => $upserted, 'pruned' => $pruned, 'congelado' => false];
        });
    }
}
