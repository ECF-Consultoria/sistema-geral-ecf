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
    public function __construct(
        private MlCatalogoMetaService $meta,
        private CreativeEngineAtivo $creativeAtivo,
    ) {}

    /** Fallback quando a categoria não informa (ou a chamada falhou) — mesmo padrão do front. */
    private const LIMITE_PADRAO = 12;

    /**
     * O kit deste rascunho, pelo MESMO critério usado por `aplicarPictures()`
     * e por `conferir()` — o mais recente que não esteja `erro` (um kit que
     * falhou não impede publicar o anúncio por outros meios). Extraído para
     * não existirem duas definições de "o kit deste rascunho" (161-04-PLAN.md).
     */
    private function kitDoRascunho(MlAnuncioRascunho $rascunho): ?MlAnuncioCriativoKit
    {
        return MlAnuncioCriativoKit::where('rascunho_id', $rascunho->id)
            ->where('status', '!=', MlAnuncioCriativoKit::STATUS_ERRO)
            ->latest('id')
            ->first();
    }

    /**
     * Gate de PUB-03 — chamar como primeira instrução DENTRO do `try` de
     * `MlPublicacaoService::publicar()` (Decisão 12 do 161-04-PLAN.md).
     *
     * Só leitura e lançamento: nunca chama o Mercado Livre nem escreve no
     * banco. Lança `\RuntimeException` com mensagem em pt-BR (sem id interno,
     * sem status técnico) quando a publicação não pode seguir.
     *
     * Quick 261007-kit2 (decisão de reunião, 2026-10-07): a publicação só
     * exige que o OPERADOR tenha decidido sobre o kit (aprovado ou não) —
     * `minimo_aprovadas` DEIXOU DE SER CONDIÇÃO aqui. As imagens por IA são
     * complemento às fotos reais, nunca trava: "serão duas imagens geradas
     * por IA e o restante serão imagens reais, então terá mais de duas
     * imagens no anúncio" (palavras do usuário). Isto vale inclusive para
     * kits antigos com `minimo_aprovadas` congelado em 3 — o valor nunca é
     * lido aqui para bloquear.
     *
     * @throws \RuntimeException quando há kit e ele ainda não foi aprovado pelo operador
     */
    public function conferir(MlAnuncioRascunho $rascunho): void
    {
        // Chave desligada: a publicação não ganha nenhuma conferência nova (OPS-03).
        if (! $this->creativeAtivo->ativa()) {
            return;
        }

        $kit = $this->kitDoRascunho($rascunho);

        // Sem kit (ou só com kit em erro, que este critério já ignora):
        // publica como sempre — é a não-regressão que este plano exige provar primeiro.
        if ($kit === null) {
            return;
        }

        if ($kit->status !== MlAnuncioCriativoKit::STATUS_APROVADO) {
            $aprovadas = $kit->aprovadas();
            $total     = $kit->totalSlots();

            throw new \RuntimeException(
                "Este anúncio tem um kit de criativos por IA ainda não aprovado ({$aprovadas} de {$total} imagens aprovadas). Aprove o kit antes de publicar."
            );
        }
    }

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
        $kit = $this->kitDoRascunho($rascunho);

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
