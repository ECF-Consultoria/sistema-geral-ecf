<?php

namespace App\Services\Mlb\Acervo;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ponto ÚNICO por onde passa toda escrita em `ml_acervo_itens` — existe para
 * resolver os deadlocks constantes (~20/dia desde 12/08/2026) diagnosticados
 * em `.planning/debug/acervo-deadlock-upsert.md`.
 *
 * ─── Por que este arquivo existe ────────────────────────────────────────────
 *
 * As DUAS camadas do acervo escrevem na MESMA tabela, nas MESMAS linhas e em
 * parte nas MESMAS colunas indexadas (`severidade`, `motivos`) da MESMA
 * empresa, ao mesmo tempo:
 *
 *   - camada BARATA  → `MlAcervoService::processarLote()`, upsert de 20 linhas
 *   - camada CARA    → `MlAcervoDetalheService::coletarItem()`, update de 1 linha
 *
 * `ShouldBeUnique` NÃO separa as duas. A chave do lock de unicidade do Laravel
 * é `'laravel_unique_job:' . get_class($job) . ':' . $uniqueId`
 * (`Illuminate\Bus\UniqueLock::getKey()`), então `SyncMlAcervoCompanyJob:42` e
 * `SyncMlAcervoDetalheJob:42:<hash>` são chaves DISTINTAS — a unicidade vale
 * por classe de job, nunca entre classes. É exatamente essa a lacuna: nada, em
 * lugar nenhum, impedia as duas camadas da mesma empresa de escreverem em
 * paralelo. (O delay de 2s do fan-out em `SyncMlAcervo` também não separa: a
 * camada barata roda por até 1800s.)
 *
 * ─── O que este helper garante ──────────────────────────────────────────────
 *
 * 1. Lock de aplicação nomeado POR EMPRESA, compartilhado pelas duas classes
 *    de job — a peça que `ShouldBeUnique` estruturalmente não entrega. Duas
 *    escritas na mesma empresa passam a ser serializadas.
 * 2. Retry automático de erro de concorrência, via
 *    `DB::transaction($cb, $tentativas)`: o retry de SQLSTATE 40001 do Laravel
 *    (`Connection::handleTransactionException()` → `causedByConcurrencyError`)
 *    SÓ existe dentro de `DB::transaction()` com `$attempts > 1`. Antes disso
 *    todas as escritas do acervo eram autocommit puro e o deadlock subia cru,
 *    matando o job inteiro.
 *
 * O lock cobre a causa; o retry cobre o resíduo que o lock não alcança
 * (colisão entre EMPRESAS diferentes no gap de fronteira do índice, que segue
 * teoricamente possível e não é coberta pela serialização por empresa).
 *
 * ─── Duas regras que NÃO podem ser afrouxadas ───────────────────────────────
 *
 * • O callback deve conter UM ÚNICO statement de escrita, sempre em
 *   `ml_acervo_itens`. Hoje as escritas do acervo são autocommit
 *   independentes, e por isso um statement nunca segura lock em duas tabelas —
 *   o que torna deadlock cross-table impossível por construção. Enfiar a série
 *   diária (`ml_acervo_metricas_diarias`) dentro da mesma transação criaria
 *   essa classe de deadlock, que hoje não existe. Não fazer.
 *
 * • Falha ao obter o lock NÃO pode abortar a coleta. Perder dado por causa de
 *   uma trava que é só otimização de concorrência seria pior que o deadlock
 *   original. Por isso o timeout degrada para "escreve mesmo assim", ainda
 *   protegido pelo retry de transação.
 */
final class AcervoEscritaLock
{
    use DetectsConcurrencyErrors;

    /**
     * Segundos de espera pelo lock. Folga enorme de propósito: cada escrita
     * protegida daqui é UM statement, na ordem de milissegundos. Estourar 10s
     * significa lock vazado, não fila legítima.
     */
    private const ESPERA_SEGUNDOS = 10;

    /**
     * TTL do lock. Curto pelo mesmo motivo: o lock protege um statement, não a
     * coleta inteira (que leva até 30 min e nunca deve segurar a tabela).
     */
    private const TTL_SEGUNDOS = 30;

    /** Tentativas do retry de concorrência dentro da transação. */
    private const TENTATIVAS = 3;

    /**
     * Executa uma escrita em `ml_acervo_itens` serializada por empresa e com
     * retry de deadlock.
     *
     * @template T
     *
     * @param  \Closure(): T  $escrita  UM statement de escrita em `ml_acervo_itens`
     * @return T
     */
    public static function naEmpresa(int $companyId, \Closure $escrita)
    {
        $comRetry = static fn () => DB::transaction($escrita, self::TENTATIVAS);

        try {
            return Cache::lock(self::chave($companyId), self::TTL_SEGUNDOS)
                ->block(self::ESPERA_SEGUNDOS, $comRetry);
        } catch (LockTimeoutException) {
            // Degradação graciosa — ver a segunda regra no docblock da classe.
            Log::warning(
                "[MLB Anuncios] lock de escrita do acervo não obtido em "
                . self::ESPERA_SEGUNDOS . "s (empresa {$companyId}); gravando sem serialização"
            );

            return $comRetry();
        }
    }

    /**
     * Chave compartilhada pelas duas camadas. Trocar este formato sem trocar
     * nos dois lados reabre o deadlock em silêncio — a serialização é
     * justamente as duas classes concordarem com a MESMA string.
     */
    public static function chave(int $companyId): string
    {
        return "acervo-escrita-empresa-{$companyId}";
    }

    /**
     * Erro de concorrência (deadlock / serialization failure) é TRANSITÓRIO:
     * a coleta seguinte resolve sozinha. Serve para o tratamento de erro não
     * tratar um deadlock como se fosse defasagem real do acervo — ver o
     * comentário em `MlAcervoService::coletarCamadaBarata()`.
     */
    public static function ehErroDeConcorrencia(\Throwable $e): bool
    {
        return (new self)->causedByConcurrencyError($e);
    }
}
