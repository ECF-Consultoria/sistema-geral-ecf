<?php

namespace App\Jobs\Publicador;

use App\Contracts\ContaMercadoLivre;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Services\Publicador\ClienteMlPublicador;
use App\Support\Publicador\ContasLiberadas;
use App\Support\Publicador\SituacaoDoAnuncioMl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Demo 23/10/2026 (H21) — relê no Mercado Livre o estado de cada anúncio criado, pouco depois de publicar.
 *
 * O teste no navegador (#459, MGSTOREL): os 4 MLBs de um produto estavam "Anúncio pausado" no ML e a tela dizia
 * "Publicado". O estado era lido UMA vez, segundos depois do `POST /items` (`PublicacaoService::depoisDeCriar`),
 * e a moderação do ML chega depois disso. Aqui: uma leitura `ESPERA_PRIMEIRA` segundos depois de concluir e
 * outra `ESPERA_SEGUNDA` depois dela (`release`, não `dispatch` de dentro do `handle` — learnings §6). Cada
 * leitura regrava `pub_publicacao_itens.avisos.estado`, e a lista final mostra o que não estiver `active`.
 *
 * Só leitura, e só na conta liberada do MESMO vendedor que publicou. Uma leitura que falha não quebra nada:
 * fica no log e o próximo anúncio segue. Fila `high`, como os outros Jobs de pós-publicação do Publicador
 * (acervo, promoção, combos): a `default` já segurou job por horas em produção.
 *
 * R17 (pós-demo): os anúncios publicados ANTES de 22/10 nunca tiveram este Job e seguiam "Publicado" pausados
 * no ML. O comando `publicador:reler-publicados` (e a rodada diária do `routes/console.php`) agenda este mesmo
 * Job com a lista de itens a reler (`$itens`): aí é UMA leitura só — quem lê de novo é a rodada seguinte.
 */
class RelerEstadoDoPublicadoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Segundos entre concluir a publicação e a 1ª releitura. */
    public const ESPERA_PRIMEIRA = 90;

    /** Segundos entre a 1ª e a 2ª releitura. */
    public const ESPERA_SEGUNDA = 300;

    /** Duas leituras: a 2ª é o `release` da 1ª. */
    public int $tries = 2;

    public int $timeout = 60;

    /**
     * @param  list<int>|null  $itens  os `pub_publicacao_itens.id` a reler (releitura em lote, uma leitura só);
     *                                 nulo = todos os criados da publicação, com a 2ª leitura (logo depois de publicar)
     */
    public function __construct(public readonly int $publicacaoId, public readonly ?array $itens = null)
    {
        // No construtor porque `Queueable` já declara `$queue`.
        $this->onQueue('high');
    }

    public function handle(ClienteMlPublicador $cliente): void
    {
        try {
            $this->reler($cliente);
        } catch (\Throwable $e) {
            Log::warning("[Publicador] estado do publicado: publicação {$this->publicacaoId} não foi relida: {$e->getMessage()}");
        }

        // A releitura em lote é de uma leitura só: a 2ª fica para a rodada seguinte.
        if ($this->itens === null && $this->attempts() < $this->tries) {
            $this->release(self::ESPERA_SEGUNDA);
        }
    }

    /** @return int quantos anúncios foram relidos */
    public function reler(ClienteMlPublicador $cliente): int
    {
        $p = PubPublicacao::find($this->publicacaoId);
        $conta = $p === null ? null : self::contaQueLe($p);

        // A mesma conta que publicou, e liberada: fora disso não se lê nada (D26).
        if ($conta === null) {
            Log::info("[Publicador] estado do publicado: publicação {$this->publicacaoId} sem conta liberada do mesmo vendedor — nada relido.");

            return 0;
        }

        $relidos = 0;
        $itens = $p->itens()->where('status', PubPublicacaoItem::CREATED)->whereNotNull('ml_item_id')
            ->when($this->itens !== null, fn ($q) => $q->whereIn('id', array_map('intval', $this->itens)));
        foreach ($itens->orderBy('id')->get() as $item) {
            try {
                $resp = $cliente->daConta($conta, 'GET', "/items/{$item->ml_item_id}", ['attributes' => 'id,status,sub_status,tags,permalink,shipping']);
                if (! $resp->ok() || ! is_array($resp->corpo)) {
                    Log::warning("[Publicador] estado do publicado: {$item->ml_item_id} sem resposta do ML (HTTP {$resp->status}).");

                    continue;
                }
                $estado = SituacaoDoAnuncioMl::doCorpo($resp->corpo);
                $item->update(['avisos' => [...(array) $item->fresh()->avisos, 'estado' => $estado]]);
                $relidos++;
                if (($estado['status'] ?? null) !== 'active') {
                    Log::info("[Publicador] estado do publicado: {$item->ml_item_id} está {$estado['status']} (".implode(',', array_filter((array) $estado['sub_status'], 'is_string')).').');
                }
            } catch (\Throwable $e) {
                Log::warning("[Publicador] estado do publicado: {$item->ml_item_id} não foi relido: {$e->getMessage()}");
            }
        }

        return $relidos;
    }

    /**
     * A conta que pode reler os anúncios da publicação: a do produto, liberada e com o MESMO vendedor que publicou
     * (`ator.conta.seller`, fixado no clique em Publicar). Nula = nada se lê. O `publicador:reler-publicados` usa
     * a mesma régua para dizer, na simulação, o que fica de fora.
     */
    public static function contaQueLe(PubPublicacao $p): ?ContaMercadoLivre
    {
        $r = PubRascunho::find($p->rascunho_id);
        $produto = $r === null ? null : PubProduto::with(['mlbEmpresa.mlToken', 'company.mlToken'])->find($r->produto_id);
        $conta = $produto?->contaOuNula();
        $seller = (string) ($p->ator['conta']['seller'] ?? '');

        if ($conta === null || $seller === '' || ! ContasLiberadas::libera($conta) || (string) ($conta->mlToken?->ml_user_id ?? '') !== $seller) {
            return null;
        }

        return $conta;
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Publicador] estado do publicado: publicação {$this->publicacaoId} não foi relida: {$e->getMessage()}");
    }
}
