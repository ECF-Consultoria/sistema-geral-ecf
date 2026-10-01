<?php

namespace App\Support\Publicador\Schema;

use App\Support\Publicador\Schema\AtributoClassificado as A;

/**
 * Classifica cada atributo da categoria para um rascunho (`03` §4) — o
 * algoritmo que faz o MESMO código montar o formulário da cadeira, da
 * furadeira e da camiseta (TC-70). Nenhuma categoria tem código próprio.
 *
 * Papel:
 *   read_only | inferred | fixed  → SYSTEM (vence `required`: o ML preenche — N-08)
 *   escolhido como eixo           → VARIATION_AXIS
 *   variation_attribute           → VARIANT_DATA
 *   senão                         → PRODUCT
 *
 * Obrigatoriedade efetiva:
 *   required | new_required ∧ produto novo | conditional_required ∧ devolvido pelo /attributes/conditional → REQUIRED
 *   catalog_required (sem required) → RECOMMENDED (o `relevance` não serve de sinal — H-27, refutada em 01/10)
 *   senão → OPTIONAL
 *
 * Agrupamento: só o `technical_specs/input` agrupa — em `/attributes`, todo
 * atributo vem com `attribute_group_id = OTHERS` (N-04).
 *
 * "Aceita texto livre": `ui_config.allow_custom_value` do componente (H-07,
 * confirmada: falso → erro 3510). Sem o sinal, lista e booleano só por id.
 *
 * Catálogo obrigatório (V-CAT-04) ainda não tem sinal identificado na API — a
 * sondagem de 01/10 não achou um (`12`): fica fora dos bloqueios até aparecer.
 */
final class ClassificadorAtributos
{
    private const SISTEMA = ['read_only', 'inferred', 'fixed'];

    public function classificar(CategorySchema $schema, ContextoClassificacao $ctx): SchemaClassificado
    {
        $specs = $this->indexarTechnicalSpecs($schema->technicalSpecs);

        $atributos = [];
        foreach ($schema->atributos as $bruto) {
            $id = (string) ($bruto['id'] ?? '');
            if ($id !== '') {
                $atributos[$id] = $this->classificarUm($bruto, $specs[$id] ?? null, $ctx);
            }
        }

        // Ordem da tela: a do technical_specs; o que não estiver lá vai no fim.
        $ordem = array_flip(array_keys($specs));
        uksort($atributos, fn ($a, $b) => ($ordem[$a] ?? PHP_INT_MAX) <=> ($ordem[$b] ?? PHP_INT_MAX));

        $settings = $schema->settings();
        $flags = [
            'folha' => $schema->folha(),
            'listing_allowed' => ($settings['listing_allowed'] ?? false) === true,
            'needs_size_grid' => $this->exigeTabelaDeMedidas($schema->atributos),
            'imagem_obrigatoria' => (bool) array_filter($atributos, fn (A $a) => $a->valueType === 'picture_id' && $a->obrigatorio()),
        ];

        return new SchemaClassificado(
            categoriaId: $schema->categoriaId,
            dominio: $schema->dominio(),
            caminho: $schema->caminho(),
            schemaHash: $schema->hash(),
            atributos: $atributos,
            grupos: $this->grupos($schema->technicalSpecs, $atributos),
            limites: $this->limites($settings),
            flags: $flags,
            bloqueiosFase2: $this->bloqueiosFase2($flags),
            garantia: $this->garantia($schema),
        );
    }

    private function classificarUm(array $bruto, ?array $spec, ContextoClassificacao $ctx): A
    {
        $id = (string) $bruto['id'];
        $tags = self::tags($bruto['tags'] ?? []);
        $tem = fn (string $tag) => in_array($tag, $tags, true);
        $valueType = (string) ($bruto['value_type'] ?? 'string');
        $valores = array_values(array_map(fn ($v) => ['id' => (string) $v['id'], 'name' => (string) ($v['name'] ?? $v['id'])], (array) ($bruto['values'] ?? [])));

        $papel = match (true) {
            (bool) array_intersect(self::SISTEMA, $tags) => A::SYSTEM,
            in_array($id, $ctx->eixos, true) => A::VARIATION_AXIS,
            $tem('variation_attribute') => A::VARIANT_DATA,
            default => A::PRODUCT,
        };

        $obrigatoriedade = match (true) {
            $papel === A::SYSTEM => A::OPTIONAL,
            $tem('required'),
            $tem('new_required') && $ctx->contaComoNovo(),
            $tem('conditional_required') && in_array($id, $ctx->condicionais, true) => A::REQUIRED,
            $tem('catalog_required') => A::RECOMMENDED,
            default => A::OPTIONAL,
        };

        $grupo = $spec['grupo'] ?? null;
        $secao = match (true) {
            $papel === A::SYSTEM => A::SECAO_OCULTO,
            $id === 'ITEM_CONDITION' => A::SECAO_CONDICAO,
            str_starts_with($id, 'SELLER_PACKAGE_') => A::SECAO_EMBALAGEM,
            $tem('used_hidden') && $ctx->condicao === 'used' => A::SECAO_OCULTO,
            $papel === A::VARIATION_AXIS => A::SECAO_EIXO,
            $papel === A::VARIANT_DATA => A::SECAO_VARIANTE,
            $tem('hidden') => A::SECAO_AVANCADO,
            $grupo === 'MAIN' => A::SECAO_PRINCIPAIS,
            default => A::SECAO_FICHA,
        };

        $ui = (array) ($spec['ui_config'] ?? []);
        $aceitaTextoLivre = match (true) {
            $valores === [] => true,
            is_bool($ui['allow_custom_value'] ?? null) => $ui['allow_custom_value'],
            default => ! in_array($valueType, ['list', 'boolean'], true),
        };

        return new A(
            id: $id,
            nome: (string) ($bruto['name'] ?? $id),
            papel: $papel,
            obrigatoriedade: $obrigatoriedade,
            secao: $secao,
            grupo: $grupo,
            valueType: $valueType,
            valores: $valores,
            unidades: array_values(array_map(fn ($u) => (string) $u['id'], (array) ($bruto['allowed_units'] ?? []))),
            unidadePadrao: isset($bruto['default_unit']) ? (string) $bruto['default_unit'] : null,
            aceitaTextoLivre: $aceitaTextoLivre,
            // N/A só em atributo do produto que não é obrigatório: no obrigatório o ML
            // responde 100 (H-06), e eixo não aceita N/A (RN-15).
            aceitaNaoSeAplica: $papel === A::PRODUCT && $obrigatoriedade !== A::REQUIRED,
            podeSerEixo: $tem('allow_variations') && $papel !== A::SYSTEM,
            definePicture: $tem('defines_picture'),
            multivalor: $tem('multivalued'),
            maxLength: (int) ($bruto['value_max_length'] ?? 255),
            dica: self::texto($ui['hint'] ?? $bruto['hint'] ?? null),
            exemplo: self::texto($ui['example'] ?? $bruto['example'] ?? null),
            tooltip: self::texto($ui['tooltip'] ?? $bruto['tooltip'] ?? null),
            componente: $spec['componente'] ?? null,
            tags: $tags,
        );
    }

    /**
     * attribute_id → grupo, rótulo do grupo, componente e ui_config, na ordem
     * em que aparecem. Um componente pode ter mais de um atributo (o
     * `COLOR_INPUT` junta COLOR e MAIN_COLOR — N-05).
     *
     * @return array<string, array{grupo: string, componente: ?string, ui_config: array}>
     */
    private function indexarTechnicalSpecs(array $ts): array
    {
        $indice = [];
        foreach ((array) ($ts['groups'] ?? []) as $g) {
            foreach ((array) ($g['components'] ?? []) as $c) {
                foreach ((array) ($c['attributes'] ?? []) as $a) {
                    $id = (string) ($a['id'] ?? '');
                    if ($id !== '' && ! isset($indice[$id])) {
                        $indice[$id] = ['grupo' => (string) ($g['id'] ?? 'OTHER'), 'componente' => $c['component'] ?? null, 'ui_config' => (array) ($c['ui_config'] ?? [])];
                    }
                }
            }
        }

        return $indice;
    }

    /**
     * Os blocos da tela, na ordem do technical_specs, só com o que a pessoa
     * edita como característica do produto. Os ocultos editáveis vão para
     * "Avançado", no fim.
     *
     * @param  array<string, A>  $atributos
     */
    private function grupos(array $ts, array $atributos): array
    {
        $visivel = fn (A $a) => in_array($a->secao, [A::SECAO_PRINCIPAIS, A::SECAO_FICHA], true);

        $grupos = [];
        foreach ((array) ($ts['groups'] ?? []) as $g) {
            $id = (string) ($g['id'] ?? 'OTHER');
            $ids = array_keys(array_filter($atributos, fn (A $a) => $a->grupo === $id && $visivel($a)));
            if ($ids) {
                $grupos[] = ['id' => $id, 'label' => (string) ($g['label'] ?? $id), 'atributos' => $ids];
            }
        }

        $semGrupo = array_keys(array_filter($atributos, fn (A $a) => $a->grupo === null && $visivel($a)));
        if ($semGrupo) {
            $grupos[] = ['id' => 'OUTROS', 'label' => 'Outras características', 'atributos' => $semGrupo];
        }

        $avancado = array_keys(array_filter($atributos, fn (A $a) => $a->secao === A::SECAO_AVANCADO));
        if ($avancado) {
            $grupos[] = ['id' => 'AVANCADO', 'label' => 'Avançado', 'atributos' => $avancado];
        }

        return $grupos;
    }

    private function limites(array $s): array
    {
        $inteiro = fn ($v) => is_numeric($v) ? (int) $v : null;
        $decimal = fn ($v) => is_numeric($v) ? (float) $v : null;

        return [
            'max_title_length' => $inteiro($s['max_title_length'] ?? null),
            'max_pictures_per_item' => $inteiro($s['max_pictures_per_item'] ?? null),
            'max_pictures_per_item_var' => $inteiro($s['max_pictures_per_item_var'] ?? null),
            // N-01: o domínio pode dizer outro número; vale o da categoria (o menor, nos casos medidos).
            'max_variations_allowed' => $inteiro($s['max_variations_allowed'] ?? null),
            'max_description_length' => $inteiro($s['max_description_length'] ?? null),
            'minimum_price' => $decimal($s['minimum_price'] ?? null),
            'maximum_price' => $decimal($s['maximum_price'] ?? null),
            'item_conditions' => array_values((array) ($s['item_conditions'] ?? [])),
            'currencies' => array_values((array) ($s['currencies'] ?? [])),
            'buying_modes' => array_values((array) ($s['buying_modes'] ?? [])),
        ];
    }

    private function exigeTabelaDeMedidas(array $atributos): bool
    {
        foreach ($atributos as $a) {
            if (in_array('grid_template_required', self::tags($a['tags'] ?? []), true)
                || in_array($a['value_type'] ?? '', ['grid_id', 'grid_row_id'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{motivo: string, mensagem: string}> */
    private function bloqueiosFase2(array $flags): array
    {
        $bloqueios = [];
        if ($flags['needs_size_grid']) {
            $bloqueios[] = ['motivo' => 'tabela_de_medidas', 'mensagem' => 'Esta categoria exige tabela de medidas, que o Publicador ainda não suporta. Publique pelo Mercado Livre por enquanto.'];
        }
        if ($flags['imagem_obrigatoria']) {
            $bloqueios[] = ['motivo' => 'atributo_imagem', 'mensagem' => 'Esta categoria exige uma imagem como característica (ex.: selo), o que o Publicador ainda não suporta.'];
        }

        return $bloqueios;
    }

    /** Tipos e unidades de garantia que a categoria aceita (H-09, confirmada: `sale_terms`). */
    private function garantia(CategorySchema $schema): array
    {
        $tipo = $schema->termoDeVenda('WARRANTY_TYPE');
        $tempo = $schema->termoDeVenda('WARRANTY_TIME');

        return [
            'tipos' => array_values(array_map(fn ($v) => ['id' => (string) $v['id'], 'name' => (string) $v['name']], (array) ($tipo['values'] ?? []))),
            'unidades' => array_values(array_map(fn ($u) => (string) $u['id'], (array) ($tempo['allowed_units'] ?? []))),
        ];
    }

    /** As tags vêm como objeto em `/attributes` e como lista no `technical_specs` (N-04). */
    public static function tags(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }
        if (array_is_list($tags)) {
            return array_values(array_map('strval', $tags));
        }

        return array_values(array_map('strval', array_keys(array_filter($tags))));
    }

    private static function texto(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
