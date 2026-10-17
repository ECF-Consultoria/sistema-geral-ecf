<?php

namespace App\Jobs;

use App\Services\Publicador\Aprovacao\Fila\AquecimentoDeTarifasService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Aquece a tarifa do Mercado Livre da fila "Aguardando aprovação" **fora do request**, chamando
 * {@see AquecimentoDeTarifasService::paraAConta()} — que, por sua vez, chama
 * `ComposicaoFinanceiraService::para()`. **Nenhuma chave de cache é montada em lugar nenhum deste
 * caminho**; quem a monta é o serviço da Fase 186, por dentro.
 *
 * ⚠️⚠️ **FILA `high`, E A RAZÃO NÃO É MINHA: ESTÁ ESCRITA NO PROJETO.** O docblock de
 * {@see AquecerPedidosMlEstruturaJob}, um job de aquecimento com a **mesma forma** deste, diz:
 *
 *  > *"Fila `high`: é disparado ao abrir a página, com alguém olhando — na `default` de produção
 *  > ficaria atrás do sync do acervo. Uma tentativa: se falhar, a próxima abertura da página
 *  > dispara de novo (a trava expira em 5 min)."*
 *
 * E a medição de produção confirma o risco da outra escolha: em **16/09/2026**, **179 jobs de
 * acervo do ML na `default`** seguraram um webhook da Clicksign **por horas** (⚠️ a tabela `jobs`
 * dá 0 e engana — a fila de produção é **Redis**). ➡️ Este aquecimento tem a propriedade que
 * decide: ele é disparado por **alguém abrindo a tela**, e o valor dele **expira com a visita**
 * (o cache da tarifa é de 1 h). Atrás de 179 jobs de acervo ele chegaria **depois** de a pessoa
 * já ter esperado a tela — ou seja, não serviria para nada. **`high`.**
 *
 * ⚠️ **NÃO** é `creative` (aquela fila é do Creative Engine, território de outro dev) e **NÃO** é
 * `default`.
 *
 * ═══ ⚠️ UMA TENTATIVA SÓ ═══════════════════════════════════════════════════════════════════════
 *
 * `$tries = 1`: aquecimento que falhou não é trabalho perdido — a **próxima abertura da tela**
 * dispara de novo. Retry empilharia chamadas ao Mercado Livre por um ganho que já expirou.
 *
 * ═══ ⚠️⚠️ A TRAVA — 5 MIN, E O NÚMERO TEM RAZÃO MEDIDA ═════════════════════════════════════════
 *
 * {@see self::TRAVA_SEGUNDOS} = **300 s**, o mesmo da forma do precedente. Ela existe para F5
 * repetido não encher a `high`.
 *
 * ⚠️ **A janela é MENOR que o TTL de 1 h da tarifa, de propósito** (3600 s em
 * `config/publicador.php:97`, `'tarifa' => 3600`). Trava de 1 h ou mais impediria o
 * **reaquecimento** depois de o cache esfriar: a pessoa abriria a tela, a trava diria "já
 * aqueci", e a tela pagaria a consulta no request — exatamente o que este job existe para evitar.
 * Com 300 s, no pior caso 12 aberturas dentro da hora disparam o job, e **onze delas custam
 * nada** (cache-hit idempotente).
 *
 * ═══ ⚠️⚠️ O DISPARO NUNCA PODE DERRUBAR A TELA ═════════════════════════════════════════════════
 *
 * {@see self::despacharParaAConta()} embrulha trava + `dispatch()` em `try/catch (\Throwable)` +
 * `Log::warning`, pelo motivo literal do `CLAUDE.md` (*"Catch `\Throwable` … Log then
 * continue"*): **Redis fora do ar é problema de aquecimento, não motivo para a lista de
 * Publicações parar de abrir.** A tela de Publicações está **NO AR**.
 *
 * ⚠️ E este job **não publica nada no Mercado Livre**: ele só LÊ tarifa. A fila APROVA (e aprovar
 * enfileira); a escrita no ML é da **Fase 193**.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class AquecerTarifasDaFilaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * A janela da trava, em segundos.
     *
     * ⚠️ **MENOR que o TTL de 1 h da tarifa** — ver o docblock da classe.
     */
    public const TRAVA_SEGUNDOS = 300;

    public function __construct(public int $companyId, public ?int $mlbEmpresaId = null)
    {
        $this->onQueue('high');
    }

    /** A chave da trava, por conta. Uma conta aquecendo não tranca a fila de outra. */
    public static function chaveDaTrava(int $companyId): string
    {
        return 'aprovacao:aquecer:'.$companyId;
    }

    /**
     * Despacha o aquecimento **sem nunca derrubar a tela que chamou**.
     *
     * ⚠️⚠️ **Todo o corpo é `try/catch (\Throwable)`.** Fila de jobs indisponível (Redis fora do
     * ar, driver mal configurado) vira `Log::warning` e a action segue respondendo **200**. Há
     * teste com o dispatch LANÇANDO que exige 200 nas duas telas.
     *
     * @return bool despachou (true) ou a trava/uma falha o impediu (false)
     */
    public static function despacharParaAConta(int $companyId, ?int $mlbEmpresaId = null): bool
    {
        try {
            // ⚠️ A trava é curta e **expira sozinha** — nunca é liberada à mão. Soltá-la no fim
            // do job devolveria o F5 repetido à `high`; e soltá-la aqui a tornaria decorativa.
            if (! Cache::lock(self::chaveDaTrava($companyId), self::TRAVA_SEGUNDOS)->get()) {
                return false;
            }

            self::dispatch($companyId, $mlbEmpresaId);

            return true;
        } catch (\Throwable $e) {
            Log::warning(sprintf(
                '%s não consegui enfileirar o aquecimento da empresa %d: %s',
                AquecimentoDeTarifasService::TAG,
                $companyId,
                $e->getMessage(),
            ));

            return false;
        }
    }

    public function handle(AquecimentoDeTarifasService $servico): void
    {
        try {
            $r = $servico->paraAConta($this->companyId, $this->mlbEmpresaId);

            Log::info(sprintf(
                '%s empresa %d aquecida em %ss — trios=%d OK=%d pulados=%d falhas=%d',
                AquecimentoDeTarifasService::TAG,
                $this->companyId,
                $r['segundos'],
                $r['trios'],
                $r['aquecidos'],
                $r['pulados'],
                $r['falhas'],
            ));
        } catch (\Throwable $e) {
            // ⚠️ O job não relança: aquecimento é ACESSÓRIO. Falhar aqui não pode virar
            // `failed_jobs` com alarme — a próxima abertura da tela dispara de novo.
            Log::warning(sprintf(
                '%s o aquecimento da empresa %d falhou: %s',
                AquecimentoDeTarifasService::TAG,
                $this->companyId,
                $e->getMessage(),
            ));
        }
    }
}
