<?php

namespace App\Services\Creative;

use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlAnuncioRascunho;
use App\Services\Mlb\Publicacao\MlCatalogoMetaService;

/**
 * Dono de `payload.pictures` quando o rascunho tem um kit de 7 (Fase 161,
 * Plano 03) — PUB-01/PUB-04.
 *
 * **Decisão 8 (161-03-PLAN.md) — reconstrói, nunca escreve por índice.**
 * `aplicarPictures()` monta a lista INTEIRA a partir dos criativos
 * `aprovado` do kit, ordenados por `slot_indice`, a cada chamada —
 * idempotente por construção (chamar duas vezes dá o mesmo resultado).
 * Escrever só na posição do slot aprovado (`pictures[$indice] = ...`)
 * criaria buracos: aprovar o slot 3 antes do 1 produziria as chaves `2`
 * sem `0`/`1`, e `array_values()` promoveria a foto do slot 3 a PRINCIPAL
 * — exatamente o defeito que ninguém veria até o anúncio publicado, porque
 * a ordem de aprovação do operador é imprevisível por natureza (ele revisa
 * as 7 e aprova na ordem que quiser). Reconstruir elimina a ordem de
 * aprovação como variável: o resultado só depende de QUAIS estão aprovadas
 * e de `slot_indice`, nunca de QUANDO cada uma foi aprovada.
 *
 * É a MESMA função que o 161-04 chama antes de publicar (PUB-03) — a
 * publicação não confia no que foi gravado na hora da aprovação, reconstrói
 * de novo a partir do banco.
 *
 * **Decisão 9 — PUB-04 é truncamento na origem, não validação na saída.**
 * O limite vem de `settings.max_pictures_per_item` da categoria
 * (`MlCatalogoMetaService::categoria()`, já cacheado e já usado neste
 * controller para o limite de título), com fallback 12 — o mesmo
 * `FOTOS_MAX_PADRAO` do front (`resources/js/lib/mlAnuncioRegras.js`).
 * `aplicarPictures()` corta a lista no limite MANTENDO os slots de menor
 * índice: o slot 1 é a imagem principal e nunca pode ser o cortado.
 */
class CreativeKitPublicacao
{
    public function __construct(private MlCatalogoMetaService $meta) {}

    /** Fallback quando a categoria não informa (ou a chamada falhou) — mesmo padrão do front. */
    private const LIMITE_PADRAO = 12;

    /**
     * Reconstrói `payload.pictures` do rascunho a partir dos criativos
     * `aprovado` do kit mais recente (que não esteja `erro`), em ordem de
     * `slot_indice`, truncado no limite da categoria. Sem kit (ou sem kit
     * elegível), NÃO FAZ NADA e devolve 0 — é o que garante que a
     * publicação sem Creative Engine continue exatamente como hoje
     * (`criativoAprovar()` do fluxo de 1 imagem, Fase 160, continua
     * escrevendo `pictures[0]` direto, sem passar por aqui).
     *
     * @return int quantidade de fotos escritas em `payload.pictures`
     */
    public function aplicarPictures(MlAnuncioRascunho $rascunho): int
    {
        $kit = MlAnuncioCriativoKit::where('rascunho_id', $rascunho->id)
            ->where('status', '!=', MlAnuncioCriativoKit::STATUS_ERRO)
            ->latest('id')
            ->first();

        if ($kit === null) {
            return 0;
        }

        $limite = $this->limiteDaCategoria($rascunho->category_id);

        $fotos = $kit->slots()
            ->where('status', MlAnuncioCriativo::STATUS_APROVADO)
            ->orderBy('slot_indice')
            ->get()
            ->filter(fn ($slot) => filled($slot->ml_picture_url))
            ->take($limite)
            ->map(fn ($slot) => ['source' => $slot->ml_picture_url])
            ->values()
            ->all();

        $payload = $rascunho->payload ?? [];
        $payload['pictures'] = $fotos;
        $rascunho->update(['payload' => $payload]);

        return count($fotos);
    }

    /**
     * Limite de fotos da categoria (`settings.max_pictures_per_item`),
     * fallback 12 — método público pequeno para o 161-04 e os testes não
     * repetirem o `data_get()`.
     */
    public function limiteDaCategoria(?string $categoryId): int
    {
        if ($categoryId === null) {
            return self::LIMITE_PADRAO;
        }

        return (int) data_get(
            $this->meta->categoria($categoryId),
            'settings.max_pictures_per_item',
            self::LIMITE_PADRAO,
        );
    }
}
