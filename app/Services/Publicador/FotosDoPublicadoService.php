<?php

namespace App\Services\Publicador;

use App\Models\PubImagem;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Support\Publicador\ContasLiberadas;
use App\Support\Publicador\Erros\RespostaMl;
use App\Support\Publicador\EstadoDoRascunho;
use App\Support\Publicador\Payload\ItemPlano;
use App\Support\Publicador\Payload\PayloadBuilderUserProducts;
use App\Support\Publicador\RegraViolada;
use Illuminate\Support\Facades\Log;

/**
 * As fotos de um produto JÁ PUBLICADO (28/10/2026). Pedido do usuário, com o print de um produto publicado: "ainda não
 * dá para fazer upload de novas fotos e nem mudar a posição". Até aqui o editor travava as fotos da variação publicada
 * — e não havia caminho nenhum para mandar foto nova a um anúncio que já está no ar.
 *
 * O desenho, por ser anúncio AO VIVO (muitas vezes de cliente): a equipe mexe nas fotos do rascunho como em qualquer
 * produto, e NADA vai ao Mercado Livre a cada clique. Quando a sequência do rascunho fica diferente da que está no ar,
 * a tela avisa ({@see situacao()}) e um botão manda a nova sequência para os anúncios daquela variação
 * ({@see atualizar()}) — `PUT /items/{id}` com `pictures`, no modelo User Products (o único que o Publicador atende,
 * D10: cada anúncio é uma variação e tem as próprias fotos).
 *
 * A sequência de cada anúncio sai do MESMO montador da publicação (`PayloadBuilderUserProducts`): fotos da cor, depois
 * as gerais, e no Premium a capa trocada com a ambientada (`OrdemCapaPorAlvo`). O que foi enviado fica em
 * `step_state.fotos_no_ml` (ids das fotos do rascunho, por anúncio); antes do primeiro envio vale o `payload` do
 * `POST /items` da publicação. Sem migration: é registro do editor, não do anúncio.
 */
final class FotosDoPublicadoService
{
    /** A chave do `step_state` com o que foi enviado a cada anúncio: ml_item_id → {fotos: list<id>, em: ISO}. */
    public const CHAVE = 'fotos_no_ml';

    public const NOME_TIPO = ['gold_special' => 'Clássico', 'gold_pro' => 'Premium'];

    public function __construct(
        private RascunhoRepository $repo,
        private DadosEfetivosService $efetivos,
        private EditorRascunhoService $editor,
        private ImagemAssetService $imagens,
        private ClienteMlPublicador $cliente,
    ) {}

    /**
     * Os anúncios no ar deste rascunho: o último criado de cada tipo × variação (um criado nunca é desfeito).
     *
     * @return array<string, PubPublicacaoItem> "tipo|variante" → item
     */
    public function noAr(PubRascunho $r): array
    {
        $saida = [];
        $itens = PubPublicacaoItem::query()
            ->whereIn('publicacao_id', $r->publicacoes()->select('id'))
            ->where('status', PubPublicacaoItem::CREATED)->whereNotNull('ml_item_id')
            ->orderBy('id')->get();
        foreach ($itens as $i) {
            $saida[$i->listing_type_id.'|'.$i->variante_chave] = $i;
        }

        return $saida;
    }

    /**
     * Para a tela do editor: quantos anúncios estão no ar e quais estão com fotos diferentes das do rascunho. Nulo =
     * nada a dizer (não publicado). Nunca lança: um problema aqui não pode derrubar o editor.
     *
     * @return ?array{anuncios: int, diferentes: list<array{ml_item_id: string, listing_type_id: string, tipo: string, variante: string}>}
     */
    public function situacao(PubRascunho $r): ?array
    {
        if (! in_array($r->status, [PubRascunho::PUBLISHED, PubRascunho::PARTIALLY_PUBLISHED], true)) {
            return null;
        }
        try {
            $noAr = $this->noAr($r);
            if ($noAr === []) {
                return null;
            }
            $planos = $this->planos($r);
            $porMl = $this->idsPorFotoDoMl($r);
            $diferentes = [];
            foreach ($noAr as $chave => $item) {
                $ids = $planos['ids'][$chave] ?? null;
                // Tipo ou variação que saiu do anúncio depois de publicar: o que está no ar fica como está.
                if ($ids !== null && $ids !== $this->enviadas($r, $item, $porMl)) {
                    $diferentes[] = self::descrever($item);
                }
            }

            return ['anuncios' => count($noAr), 'diferentes' => $diferentes];
        } catch (\Throwable $e) {
            Log::warning("[Publicador] rascunho {$r->id}: não deu para comparar as fotos com os anúncios no ar: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Manda a sequência do rascunho aos anúncios no ar que estão diferentes. Sobe antes as fotos que ainda não estão no
     * ML. Anúncio que falha não impede os outros; cada um diz por quê.
     *
     * @return array{atualizados: list<array<string, string>>, falhas: list<array<string, string>>, iguais: int}
     */
    public function atualizar(PubRascunho $r): array
    {
        $noAr = $this->noAr($r);
        if ($noAr === []) {
            throw new RegraViolada('V-FOTO-01', 'Este produto não tem anúncio no ar para atualizar as fotos.');
        }
        $conta = $r->produto->contaOuNula()
            ?? throw new RegraViolada('V-ACC-01', 'A conta do Mercado Livre desta empresa precisa ser reconectada. Conecte de novo pelo Onboarding e volte aqui.');
        ContasLiberadas::exigir($conta);

        $this->imagens->enviarPendentes($r, $conta);
        $r = $r->fresh(['produto']) ?? $r;
        $planos = $this->planos($r);
        $porMl = $this->idsPorFotoDoMl($r);

        $atualizados = [];
        $falhas = [];
        $iguais = 0;
        foreach ($noAr as $chave => $item) {
            $ids = $planos['ids'][$chave] ?? null;
            if ($ids === null) {
                continue;
            }
            if ($ids === $this->enviadas($r, $item, $porMl)) {
                $iguais++;

                continue;
            }
            $descricao = self::descrever($item);
            $doMl = $planos['ml'][$chave] ?? [];
            if ($ids === []) {
                $falhas[] = [...$descricao, 'mensagem' => 'Ficou sem foto: o Mercado Livre exige ao menos uma.'];

                continue;
            }
            if (count($doMl) !== count($ids)) {
                $falhas[] = [...$descricao, 'mensagem' => 'Alguma foto ainda não subiu ao Mercado Livre. Use "enviar de novo" na foto e tente outra vez.'];

                continue;
            }

            $resposta = $this->cliente->daConta($conta, 'PUT', "/items/{$item->ml_item_id}", corpo: ['pictures' => array_map(fn (string $id) => ['id' => $id], $doMl)]);
            if ($resposta->ok()) {
                $this->registrar($r, (string) $item->ml_item_id, $ids);
                $atualizados[] = $descricao;
                Log::info("[Publicador] fotos de {$item->ml_item_id} atualizadas no ML (rascunho {$r->id}, ".count($doMl).' fotos).');
            } else {
                $falhas[] = [...$descricao, 'mensagem' => self::mensagem($resposta)];
                Log::warning("[Publicador] fotos de {$item->ml_item_id} recusadas pelo ML (HTTP {$resposta->status}, rascunho {$r->id}).");
            }
        }

        return ['atualizados' => $atualizados, 'falhas' => $falhas, 'iguais' => $iguais];
    }

    /**
     * A sequência de fotos de cada anúncio pelo plano da publicação: em ids do rascunho (toda foto conta, como na
     * Prévia) e em ids do ML (só as que já subiram).
     *
     * @return array{ids: array<string, list<string>>, ml: array<string, list<string>>}
     */
    private function planos(PubRascunho $r): array
    {
        $snapshot = $this->repo->snapshot($r)->comEfetivosDe($this->efetivos->daProduto($r->produto));
        [$schema, $erro] = $this->editor->schemaDe($r, $snapshot);
        if ($schema === null) {
            throw new RegraViolada('V-FOTO-02', $erro ?? 'Escolha a categoria na etapa Produto antes de atualizar as fotos.');
        }
        $todas = $r->imagens()->pluck('id')->mapWithKeys(fn ($id) => [(string) $id => (string) $id])->all();
        $porChave = fn (array $itens) => collect($itens)->mapWithKeys(fn (ItemPlano $i) => [
            $i->listingTypeId.'|'.$i->varianteChave => array_map(fn (array $p) => (string) $p['id'], (array) ($i->payload['pictures'] ?? [])),
        ])->all();

        return [
            'ids' => $porChave(PayloadBuilderUserProducts::montar($snapshot, $schema, $todas)),
            'ml' => $porChave(PayloadBuilderUserProducts::montar($snapshot, $schema, $this->repo->fotosNoMl($r))),
        ];
    }

    /** @return array<string, string> ml_picture_id → id da foto no rascunho */
    private function idsPorFotoDoMl(PubRascunho $r): array
    {
        return $r->imagens()->whereNotNull('ml_picture_id')->get(['id', 'ml_picture_id'])
            ->mapWithKeys(fn (PubImagem $i) => [(string) $i->ml_picture_id => (string) $i->id])->all();
    }

    /**
     * O que está no ar no anúncio, em ids do rascunho: o último envio desta tela ou, antes dele, o da publicação. Foto
     * do ML que não está mais no rascunho vira `ml:<id>` — e a sequência fica diferente, como é.
     *
     * @param  array<string, string>  $porMl
     * @return list<string>
     */
    private function enviadas(PubRascunho $r, PubPublicacaoItem $item, array $porMl): array
    {
        $registro = ((array) ($r->step_state[self::CHAVE] ?? []))[(string) $item->ml_item_id] ?? null;
        if (is_array($registro) && is_array($registro['fotos'] ?? null)) {
            return array_map('strval', $registro['fotos']);
        }

        return array_map(function ($p) use ($porMl) {
            $id = (string) (is_array($p) ? ($p['id'] ?? '') : $p);

            return $porMl[$id] ?? 'ml:'.$id;
        }, array_values((array) ($item->payload['pictures'] ?? [])));
    }

    /** @param list<string> $ids */
    private function registrar(PubRascunho $r, string $mlItemId, array $ids): void
    {
        EstadoDoRascunho::alterar($r, function (array $estado) use ($mlItemId, $ids) {
            $estado[self::CHAVE] = [...(array) ($estado[self::CHAVE] ?? []), $mlItemId => ['fotos' => $ids, 'em' => now()->toIso8601String()]];

            return $estado;
        });
    }

    /** @return array{ml_item_id: string, listing_type_id: string, tipo: string, variante: string} */
    private static function descrever(PubPublicacaoItem $item): array
    {
        return [
            'ml_item_id' => (string) $item->ml_item_id,
            'listing_type_id' => (string) $item->listing_type_id,
            'tipo' => self::NOME_TIPO[$item->listing_type_id] ?? (string) $item->listing_type_id,
            'variante' => (string) $item->variante_chave,
        ];
    }

    private static function mensagem(RespostaMl $r): string
    {
        $doMl = collect($r->erros())->pluck('message')->filter()->first();

        return match ($r->classe) {
            RespostaMl::AUTH => 'A conta do Mercado Livre precisa ser reconectada.',
            RespostaMl::PERMISSION => 'O Mercado Livre não deixou mudar as fotos deste anúncio.'.($doMl ? " ({$doMl})" : ''),
            RespostaMl::RATE_LIMIT => 'O Mercado Livre limitou os pedidos agora. Aguarde um minuto e tente de novo.',
            RespostaMl::NETWORK, RespostaMl::SERVER => 'O Mercado Livre não respondeu. Tente de novo em instantes.',
            default => 'O Mercado Livre recusou as fotos'.($doMl ? ": {$doMl}" : '.'),
        };
    }
}
