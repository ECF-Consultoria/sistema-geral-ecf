<?php

namespace App\Jobs;

use App\Models\MlAnuncioIaAnalise;
use App\Services\Ia\AnaliseAnuncioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Gera a análise MAG T8 em três etapas, salvando cada uma assim que fica pronta.
 *
 * POR QUE EM ETAPAS. A primeira versão pedia tudo numa chamada e o provedor
 * devolvia 503 no prompt inteiro (medido em produção, 21/09/2026), enquanto a
 * mesma conta entregava os títulos sozinhos em 30s. Além de funcionar, isto
 * faz o publicador ver a análise aparecer enquanto os títulos ainda saem.
 *
 * SALVAR PARCIAL É O PONTO. Se a descrição falhar, análise e títulos ficam —
 * refazer a chamada inteira por causa da última etapa desperdiça minutos.
 */
class GerarAnaliseAnuncioIaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 2 tentativas. A 2ª retoma da etapa que falhou (o parcial fica salvo) e
     * o serviço já troca de modelo sozinho — mais tentativas só esticavam o
     * "Gerando…" na tela sem chance real de mudar o resultado.
     */
    public int $tries = 2;

    public array $backoff = [20];

    /** Teto do worker: o da `high` permite 600s. */
    public int $timeout = 580;

    /**
     * Prazo que o SERVIÇO respeita, abaixo do `$timeout`. É ele que faz a
     * etapa falhar com mensagem em vez de o worker matar o processo — morte
     * por timeout não roda o `handle` até o fim e deixava a análise em
     * "rodando" para sempre.
     */
    public const PRAZO_S = 540;

    /** Se mesmo assim o worker matar, é falha definitiva — status vira erro. */
    public bool $failOnTimeout = true;

    public function __construct(public int $analiseId)
    {
        // Fila `high`, NUNCA a `default`. Medido em produção em 21/09/2026: a
        // `default` tinha 170 jobs represados (sync da Adman) e a análise ficou
        // 395s sem ninguém pegar — o publicador viu "Gerando…" e achou que
        // tinha travado. A `high` tem worker dedicado e vive ociosa.
        //
        // Definido no construtor porque `Queueable` já declara `$queue` e
        // redeclarar a propriedade é erro fatal de PHP.
        $this->onQueue('high');
    }

    public function handle(AnaliseAnuncioService $ia): void
    {
        $analise = MlAnuncioIaAnalise::find($this->analiseId);

        if (! $analise) {
            Log::warning("[IA] Análise {$this->analiseId} sumiu antes de rodar.");

            return;
        }

        if ($analise->status === MlAnuncioIaAnalise::STATUS_CONCLUIDO) {
            return;
        }

        // A tela já desistiu desta (passou do limite): não gastar cota numa
        // geração que ninguém vai ver.
        $analise->encerrarSeTravada();
        if ($analise->status === MlAnuncioIaAnalise::STATUS_ERRO) {
            return;
        }

        // O menor entre o prazo desta tentativa e o limite total da análise:
        // a 2ª tentativa não pode seguir gerando depois que a tela já mostrou
        // "encerrada por tempo".
        $ia->comPrazo(min(
            microtime(true) + self::PRAZO_S,
            $analise->created_at->getTimestamp() + MlAnuncioIaAnalise::LIMITE_MINUTOS * 60 - 30,
        ));

        $analise->update([
            'status'     => MlAnuncioIaAnalise::STATUS_RODANDO,
            'started_at' => $analise->started_at ?? now(),
            'tentativas' => $analise->tentativas + 1,
        ]);

        $produto = $analise->produto;
        $loja    = (string) $analise->loja;
        $specs   = (string) $analise->specs;

        // O que já existe de uma tentativa anterior é reaproveitado: não
        // refazer etapa que já deu certo só porque a seguinte falhou.
        $r     = $analise->resultado ?? [];
        $meta  = [];
        $t0    = microtime(true);

        // ─── Etapa 1: Análise Estratégica ───
        if (empty($r['analise'])) {
            $analise->update(['etapa' => 'analise']);
            $p = $ia->analise($produto, $loja, $specs);
            $r['analise'] = $p['dados'];
            $meta = $p['meta'];
            $analise->update(['resultado' => $r]);
        }

        // ─── Etapa 2: Títulos ───
        if (empty($r['titulos'])) {
            $analise->update(['etapa' => 'titulos']);
            $p = $ia->titulos($produto, $loja, $specs, $r['analise']);
            $r['titulos'] = $p['dados'];
            $meta = $p['meta'] ?: $meta;
            $analise->update(['resultado' => $r]);
        }

        // ─── Etapa 3: Descrição ───
        if (empty($r['descricao'])) {
            $analise->update(['etapa' => 'descricao']);
            $p = $ia->descricao($produto, $loja, $specs, $r['analise']);
            $r['descricao'] = $p['dados'];
            $meta = $p['meta'] ?: $meta;
            $analise->update(['resultado' => $r]);
        }

        $analise->update([
            'status'         => MlAnuncioIaAnalise::STATUS_CONCLUIDO,
            'etapa'          => null,
            'resultado'      => $r,
            'modelo'         => $meta['modelo'] ?? $analise->modelo,
            'tokens_entrada' => $meta['tokens_entrada'] ?? $analise->tokens_entrada,
            'tokens_saida'   => $meta['tokens_saida'] ?? $analise->tokens_saida,
            'duracao_ms'     => (int) round((microtime(true) - $t0) * 1000),
            'erro_mensagem'  => null,
            'finished_at'    => now(),
        ]);

        Log::info("[IA] Análise {$analise->id} concluída para '{$produto}'", [
            'modelo'  => $analise->modelo,
            'titulos' => count($r['titulos'] ?? []),
        ]);
    }

    /**
     * Só marca erro quando o Laravel desiste de vez — antes disso o job ainda
     * vai tentar, e dizer "falhou" na tela seria mentira.
     *
     * O que já foi gerado FICA: `resultado` não é limpo aqui. Meia análise vale
     * mais que nenhuma, e a tela mostra o que tem.
     */
    public function failed(\Throwable $e): void
    {
        $analise = MlAnuncioIaAnalise::find($this->analiseId);

        $analise?->update([
            'status'        => MlAnuncioIaAnalise::STATUS_ERRO,
            'etapa'         => null,
            'erro_mensagem' => $e->getMessage(),
            'finished_at'   => now(),
        ]);

        Log::error("[IA] Análise {$this->analiseId} falhou em definitivo: {$e->getMessage()}");
    }
}
