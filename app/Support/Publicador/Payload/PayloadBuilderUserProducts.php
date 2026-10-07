<?php

namespace App\Support\Publicador\Payload;

use App\Support\Publicador\Imagem\OpcoesImagem;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\RegraViolada;
use App\Support\Publicador\Schema\SchemaClassificado;
use App\Support\Publicador\Schema\ValorAtributo;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\Variante;

/**
 * Payloads do modelo User Products (`01` §4.2, `05` §9, `10` §5): um item por
 * alvo × variante ativa. Medido em 01/10 (`12`): o ML recusa `title`
 * (`body.invalid_fields`) e `variations` (374), e exige `family_name` (369).
 *
 * Atributos de cada item, sem repetir id (o que vem depois prevalece):
 *   1. os do produto (sem os de sistema e sem os que viraram eixo — RN-14, RN-51);
 *   2. os valores dos eixos como atributos comuns (eixo customizado: `{name, value_name}`);
 *   3. os dados da variante (SELLER_SKU, GTIN, EMPTY_GTIN_REASON…);
 *   4. `ITEM_CONDITION` = Recondicionado, quando for o caso (H-04).
 *
 * Mesmo `family_name`, categoria, condição e atributos de produto em todos os
 * itens de um alvo, por construção (RN-46, V-VAR-19). Função pura.
 */
final class PayloadBuilderUserProducts
{
    /**
     * @param  array<string, string>  $fotosMl  id da foto no rascunho → `ml_picture_id` já enviado
     * @return list<ItemPlano>
     */
    public static function montar(RascunhoSnapshot $r, SchemaClassificado $schema, array $fotosMl): array
    {
        $eixos = Eixo::ordenar($r->eixos);
        $fotos = ResolvedorGruposImagem::resolver($r->variantes, $eixos, $r->imagens, new OpcoesImagem(
            OpcoesImagem::UP,
            $schema->limites['max_pictures_per_item'] ?? null,
            $schema->limites['max_pictures_per_item_var'] ?? null,
            $r->fotosPorVariante,
            $r->incluirGeral,
        ))->porVariante;

        $doProduto = self::atributosDoProduto($r, $schema, $eixos);
        $condicao = self::condicao($r, $schema);

        $itens = [];
        foreach ($r->alvosAtivos() as $indiceAlvo => $alvo) {
            foreach ($r->variantesAtivas() as $v) {
                $daVariante = OrdemCapaPorAlvo::aplicar($fotos[$v->chave] ?? [], $indiceAlvo);
                $pendentes = array_values(array_filter($daVariante, fn ($id) => ! isset($fotosMl[$id])));

                $payload = array_filter([
                    'family_name' => $alvo->titulo,
                    'category_id' => $r->categoriaId,
                    'price' => isset($v->dados['precos'][$alvo->listingTypeId]) ? (float) $v->dados['precos'][$alvo->listingTypeId] : null,
                    'currency_id' => 'BRL',
                    // Obrigatório no corpo mesmo quando o ML o ignora (conta multidepósito, N-19).
                    'available_quantity' => isset($v->dados['estoque']) ? (int) $v->dados['estoque'] : null,
                    'buying_mode' => 'buy_it_now',
                    'listing_type_id' => $alvo->listingTypeId,
                    'condition' => $condicao['condition'],
                    'pictures' => array_values(array_map(fn ($id) => ['id' => $fotosMl[$id]], array_filter($daVariante, fn ($id) => isset($fotosMl[$id])))),
                    'attributes' => array_values([
                        ...$doProduto,
                        ...self::atributosDosEixos($v, $eixos, $schema),
                        ...self::atributosDaVariante($v, $schema),
                        ...$condicao['atributos'],
                    ]),
                    'sale_terms' => self::termosDeVenda($r),
                    'shipping' => self::envio($r),
                ], fn ($x) => $x !== null && $x !== []);

                self::garantirFormato($payload);
                $itens[] = new ItemPlano(count($itens), $alvo->listingTypeId, $v->chave, $v->rotulo($eixos), $payload, $pendentes);
            }
        }

        return $itens;
    }

    /** A guarda do RN-03 e do H-02: nada de `variations`, nada de `title`, antes de qualquer chamada (TC-90). */
    public static function garantirFormato(array $payload): void
    {
        foreach (['variations', 'title'] as $proibido) {
            if (array_key_exists($proibido, $payload)) {
                throw new \LogicException("Payload User Products com '{$proibido}': o Mercado Livre recusa (RN-03 / H-02).");
            }
        }
    }

    /** @return array<string, array> chave de dedup → atributo */
    private static function atributosDoProduto(RascunhoSnapshot $r, SchemaClassificado $schema, array $eixos): array
    {
        $deEixo = array_flip(array_map(fn (Eixo $e) => $e->chave, $eixos));
        $saida = [];
        // Na ordem do schema, não na do rascunho: o mesmo rascunho dá sempre o mesmo payload.
        foreach ($schema->atributos as $id => $a) {
            if (! isset($r->atributos[$id]) || ! $a->editavel() || isset($deEixo[$id]) || $id === 'ITEM_CONDITION') {
                continue;
            }
            if ($p = ValorAtributo::paraPayload($a, $r->atributos[$id])) {
                $saida[$id] = $p;
            }
        }

        return $saida;
    }

    private static function atributosDosEixos(Variante $v, array $eixos, SchemaClassificado $schema): array
    {
        $saida = [];
        foreach ($eixos as $e) {
            $valor = $v->valores[$e->chave] ?? null;
            if ($valor === null) {
                continue;
            }
            if ($e->ehCustomizado()) {
                $saida[ChaveCanonica::EIXO_CUSTOM] = ['name' => $e->nome, 'value_name' => $valor->valueName];

                continue;
            }
            $a = $schema->atributo($e->chave);
            $p = $a ? ValorAtributo::paraPayload($a, ['value_id' => $valor->valueId, 'value_name' => $valor->valueName]) : null;
            $saida[$e->chave] = $p ?? ($valor->valueId !== null ? ['id' => $e->chave, 'value_id' => $valor->valueId] : ['id' => $e->chave, 'value_name' => $valor->valueName]);
        }

        return $saida;
    }

    private static function atributosDaVariante(Variante $v, SchemaClassificado $schema): array
    {
        $saida = [];
        foreach ((array) ($v->dados['atributos'] ?? []) as $id => $valor) {
            $a = $schema->atributo((string) $id);
            if ($a && $a->editavel() && ($p = ValorAtributo::paraPayload($a, (array) $valor))) {
                $saida[$id] = $p;
            }
        }

        return $saida;
    }

    /** Recondicionado vai como `new` + `ITEM_CONDITION` (H-04, aceito pelo validate em 01/10). */
    private static function condicao(RascunhoSnapshot $r, SchemaClassificado $schema): array
    {
        if ($r->condicao !== 'refurbished') {
            return ['condition' => $r->condicao, 'atributos' => []];
        }

        foreach ($schema->atributo('ITEM_CONDITION')?->valores ?? [] as $valor) {
            if (str_contains(ChaveCanonica::texto($valor['name']), 'recondicionad')) {
                return ['condition' => 'new', 'atributos' => ['ITEM_CONDITION' => ['id' => 'ITEM_CONDITION', 'value_id' => $valor['id']]]];
            }
        }

        throw new RegraViolada('H-04', 'Esta categoria não aceita produto recondicionado.');
    }

    /** Garantia por `value_id` do `sale_terms` (H-09); sem tempo quando é "sem garantia". */
    private static function termosDeVenda(RascunhoSnapshot $r): array
    {
        $g = $r->garantia;
        if (empty($g['tipo'])) {
            return [];
        }

        $termos = [['id' => 'WARRANTY_TYPE', 'value_id' => (string) $g['tipo']]];
        if (! empty($g['tempo'])) {
            $termos[] = ['id' => 'WARRANTY_TIME', 'value_name' => ((int) $g['tempo']).' '.($g['unidade'] ?? 'dias')];
        }

        return $termos;
    }

    private static function envio(RascunhoSnapshot $r): array
    {
        $e = $r->envio;

        return array_filter([
            'mode' => (string) ($e['modo'] ?? 'me2'),
            'local_pick_up' => (bool) ($e['retirada'] ?? false),
            'free_shipping' => (bool) ($e['frete_gratis'] ?? false),
            'logistic_type' => $e['logistic_type'] ?? null,
        ], fn ($x) => $x !== null);
    }
}
