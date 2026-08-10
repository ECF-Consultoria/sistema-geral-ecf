<?php

namespace App\Services\Mlb\Acervo;

use App\Models\MlAcervoItem;

/**
 * Nota ECF (D-10/D-22) e triagem (D-09/D-12/D-18) de um anúncio JÁ PUBLICADO.
 *
 * Port PHP de calcularScore() (resources/js/lib/mlAnuncioRegras.js:263),
 * aplicado ao payload do item vindo do multiget /items?ids= em vez do
 * formulário do wizard. A base é 86, não 100: dos 8 sinais originais, só
 * "descrição ≥120 chars" (14 pontos) fica de fora, porque exigiria
 * GET /items/{id}/description — 1 chamada por item, sem lote (D-22). A nota
 * NUNCA é renormalizada para 100 — renormalizar seria recalibrar a régua por
 * conta própria (proibido) e faria a nota não fechar com o breakdown
 * mostrado na tela, o mesmo modo de falha já vivido com `nps_medio` ≠
 * `pontos_componentes.nps` (.planning/learnings/desempenho-bonificacao.md).
 *
 * Serviço PURO: nenhum HTTP, nenhum Eloquent, nenhum acesso a banco/cache —
 * é isso que torna o teste de concordância PHP×JS possível sem
 * Http::fake(). Todo acesso ao payload usa coalescência com default
 * (`?? `/`is_array()`), nunca índice direto — item malformado (sem
 * `attributes`, sem `pictures`) produz sinal falso, não exceção (T-134-09):
 * o job de coleta não pode morrer por causa de um item excêntrico.
 */
final class AnuncioSaudeService
{
    /**
     * Os 7 sinais computáveis na camada barata. Espelho parcial de
     * calcularScore() em resources/js/lib/mlAnuncioRegras.js:263 — aquele
     * arquivo é a fonte da régua, este é o port; o 8º sinal do wizard
     * (descrição ≥120 chars, 14 pontos) fica DE FORA por D-22.
     *
     * Não existe hoje mecanismo técnico de config compartilhada entre PHP e
     * JS neste projeto (134-PATTERNS.md item 5) — a convenção estabelecida
     * é espelhar manualmente. A garantia real de concordância entre os dois
     * scorers não é a estrutura deste array, é o teste de regressão sobre
     * fixture compartilhado
     * (tests/Unit/Phase134/NotaEcfFecharComContaTest.php +
     * tests/js/notaEcfConcordancia.test.js) — se um lado mudar um peso sem
     * o outro, um dos dois quebra.
     */
    public const PESOS = [
        'titulo'            => 12,
        'categoria'         => 12,
        'ficha_obrigatoria' => 20,
        'ficha_opcional'    => 14,
        'foto'              => 16,
        'dimensoes'         => 8,
        'preco'             => 4,
    ];

    // array_sum(PESOS) — NUNCA renormalizar a nota para 100. Exibida sempre como "X de 86".
    public const BASE = 86;

    // O sinal do wizard deliberadamente ausente (custaria 1 call/item, sem lote).
    public const PESO_DESCRICAO_FORA = 14;

    /** Ids excluídos de "opcional" por já serem tratados em outro lugar da ficha (AnunciarML.jsx:1450). */
    private const OPCIONAIS_EXCLUIDOS = ['CATALOG_PRODUCT_ID', 'GTIN', 'SELLER_SKU'];

    /** Os 4 atributos de dimensão do pacote exigidos pelo sinal `dimensoes`. */
    private const ATRIBUTOS_DIMENSAO = ['PACKAGE_WEIGHT', 'PACKAGE_LENGTH', 'PACKAGE_WIDTH', 'PACKAGE_HEIGHT'];

    /**
     * @param  array $item                payload de um item vindo de GET /items?ids= (o 'body' do multiget)
     * @param  array $atributosCategoria  resposta crua de GET /categories/{id}/attributes
     * @return array{nota:int, sinais:array<string,array{peso:int,ok:bool}>}
     */
    public function avaliar(array $item, array $atributosCategoria): array
    {
        // Asserção defensiva: se alguém mexer num peso sem mexer na base, a
        // execução para aqui em vez de exibir uma nota errada em silêncio.
        if (array_sum(self::PESOS) !== self::BASE) {
            throw new \LogicException(
                'AnuncioSaudeService::PESOS soma ' . array_sum(self::PESOS) . ', diferente de BASE (' . self::BASE . '). '
                . 'Alterar um peso é decisão explícita (D-22) — atualize BASE junto.'
            );
        }

        $itemAttrs = $this->comoArray($item['attributes'] ?? null);

        $sinais = [
            'titulo'            => $this->sinal('titulo', $this->sinalTitulo($item)),
            'categoria'         => $this->sinal('categoria', $this->sinalCategoria($item)),
            'ficha_obrigatoria' => $this->sinal('ficha_obrigatoria', $this->sinalFichaObrigatoria($itemAttrs, $atributosCategoria)),
            'ficha_opcional'    => $this->sinal('ficha_opcional', $this->sinalFichaOpcional($itemAttrs, $atributosCategoria)),
            'foto'              => $this->sinal('foto', $this->sinalFoto($item)),
            'dimensoes'         => $this->sinal('dimensoes', $this->sinalDimensoes($itemAttrs)),
            'preco'             => $this->sinal('preco', $this->sinalPreco($item)),
        ];

        // Soma direta dos pesos dos sinais verdadeiros — nunca min()/clamp: a
        // soma já é limitada por construção (array_sum(PESOS) === BASE), e um
        // clamp aqui mascararia erro de peso em vez de estourar o teste acima.
        $nota = 0;
        foreach ($sinais as $s) {
            if ($s['ok']) {
                $nota += $s['peso'];
            }
        }

        return ['nota' => $nota, 'sinais' => $sinais];
    }

    /**
     * @param  array       $item          mesmo payload do multiget
     * @param  array       $sinais        saída de avaliar()['sinais']
     * @param  string|null $buyboxStatus  winning|sharing_first_place|losing|listed|null
     * @return array{motivos:string[], severidade:int}
     */
    public function triagem(array $item, array $sinais, ?string $buyboxStatus = null): array
    {
        $status = (string) ($item['status'] ?? '');

        // Anúncio encerrado: não há o que fazer, não gera motivo algum.
        if ($status === 'closed') {
            return ['motivos' => [], 'severidade' => MlAcervoItem::SEVERIDADE_SAUDAVEL];
        }

        $motivos = [];

        if ($status === 'paused') {
            $motivos[] = MlAcervoItem::MOTIVO_PAUSADO;
        } elseif ($status === 'active' && (int) ($item['available_quantity'] ?? 0) === 0) {
            // Pausado já é o problema maior — não acumula com sem-estoque
            // (D-09 pede leitura acionável, não acúmulo de rótulos).
            $motivos[] = MlAcervoItem::MOTIVO_SEM_ESTOQUE;
        }

        if (! ($sinais['ficha_obrigatoria']['ok'] ?? true)) {
            $motivos[] = MlAcervoItem::MOTIVO_FICHA_INCOMPLETA;
        }

        if (! ($sinais['foto']['ok'] ?? true)) {
            $motivos[] = MlAcervoItem::MOTIVO_FOTO_INSUFICIENTE;
        }

        // D-18: buy box não avaliado ($buyboxStatus === null) NUNCA vira
        // motivo, mesmo que catalog_listing seja verdadeiro — ausência de
        // avaliação não é "sem problema" nem "com problema".
        if ($buyboxStatus !== null && in_array($buyboxStatus, ['losing', 'sharing_first_place'], true)) {
            $motivos[] = MlAcervoItem::MOTIVO_PERDENDO_CATALOGO;
        }

        $severidade = MlAcervoItem::SEVERIDADE_SAUDAVEL;
        if (in_array(MlAcervoItem::MOTIVO_PAUSADO, $motivos, true) || in_array(MlAcervoItem::MOTIVO_SEM_ESTOQUE, $motivos, true)) {
            $severidade = MlAcervoItem::SEVERIDADE_CRITICA;
        } elseif ($motivos !== []) {
            $severidade = MlAcervoItem::SEVERIDADE_ATENCAO;
        }

        return ['motivos' => $motivos, 'severidade' => $severidade];
    }

    // ─── Sinais individuais (cada um espelha um add(...) de calcularScore()) ───

    private function sinalTitulo(array $item): bool
    {
        $titulo = trim((string) ($item['title'] ?? ''));

        return mb_strlen($titulo) >= 20;
    }

    private function sinalCategoria(array $item): bool
    {
        $categoryId = $item['category_id'] ?? null;

        return $categoryId !== null && $categoryId !== '';
    }

    /**
     * Espelha completudeFicha() de mlAnuncioRegras.js:135, com os
     * obrigatórios filtrados igual a AnunciarML.jsx:1420 (exclui
     * allow_variations e grade de moda) e o predicado de "preenchido" de
     * AnunciarML.jsx:1994.
     */
    private function sinalFichaObrigatoria(array $itemAttrs, array $atributosCategoria): bool
    {
        $obrigatorios = $this->obrigatorios($atributosCategoria);

        if ($obrigatorios === []) {
            return true;
        }

        foreach ($obrigatorios as $atributo) {
            if (! $this->atributoPreenchido($itemAttrs, (string) ($atributo['id'] ?? ''))) {
                return false;
            }
        }

        return true;
    }

    /** Espelha o filtro de "características secundárias" de AnunciarML.jsx:1450. */
    private function sinalFichaOpcional(array $itemAttrs, array $atributosCategoria): bool
    {
        $opcionais = $this->opcionais($atributosCategoria);

        if ($opcionais === []) {
            return true;
        }

        $preenchidos = 0;
        foreach ($opcionais as $atributo) {
            if ($this->atributoPreenchido($itemAttrs, (string) ($atributo['id'] ?? ''))) {
                $preenchidos++;
            }
        }

        $percentual = (int) round(($preenchidos / count($opcionais)) * 100);

        return $percentual >= 60;
    }

    /** Mesma bifurcação de calcularScore(): variações usam picture_ids, item simples usa pictures[]. */
    private function sinalFoto(array $item): bool
    {
        $variations = $this->comoArray($item['variations'] ?? null);

        if ($variations !== []) {
            foreach ($variations as $variacao) {
                if ($this->comoArray($variacao['picture_ids'] ?? null) !== []) {
                    return true;
                }
            }

            return false;
        }

        return $this->comoArray($item['pictures'] ?? null) !== [];
    }

    /** Os 4 atributos de dimensão, valor extraído ignorando o sufixo de unidade ("500 g" → 500.0). */
    private function sinalDimensoes(array $itemAttrs): bool
    {
        foreach (self::ATRIBUTOS_DIMENSAO as $id) {
            $valor = $this->valorNumericoAtributo($itemAttrs, $id);
            if ($valor === null || $valor <= 0) {
                return false;
            }
        }

        return true;
    }

    private function sinalPreco(array $item): bool
    {
        $preco = $item['price'] ?? null;

        return $preco !== null && (float) $preco > 0;
    }

    // ─── Helpers de payload — sempre com default, nunca índice direto (T-134-09) ───

    /** @return array<int,array> */
    private function obrigatorios(array $atributosCategoria): array
    {
        return array_values(array_filter($atributosCategoria, function (array $a): bool {
            $tags = $this->comoArray($a['tags'] ?? null);
            $id = (string) ($a['id'] ?? '');

            return ($tags['required'] ?? false) === true
                && ($tags['allow_variations'] ?? false) !== true
                && $id !== 'SIZE_GRID_ID'
                && ! str_contains($id, 'GRID');
        }));
    }

    /** @return array<int,array> */
    private function opcionais(array $atributosCategoria): array
    {
        return array_values(array_filter($atributosCategoria, function (array $a): bool {
            $tags = $this->comoArray($a['tags'] ?? null);
            $id = (string) ($a['id'] ?? '');

            return ($tags['required'] ?? false) !== true
                && ($tags['allow_variations'] ?? false) !== true
                && ($tags['hidden'] ?? false) !== true
                && ($tags['read_only'] ?? false) !== true
                && $id !== 'SIZE_GRID_ID'
                && ! str_contains($id, 'GRID')
                && ! in_array($id, self::OPCIONAIS_EXCLUIDOS, true);
        }));
    }

    private function atributoPreenchido(array $itemAttrs, string $id): bool
    {
        foreach ($itemAttrs as $attr) {
            if (($attr['id'] ?? null) === $id) {
                $valueId = $attr['value_id'] ?? null;
                $valueName = $attr['value_name'] ?? null;

                return ($valueId !== null && $valueId !== '') || ($valueName !== null && $valueName !== '');
            }
        }

        return false;
    }

    private function valorNumericoAtributo(array $itemAttrs, string $id): ?float
    {
        foreach ($itemAttrs as $attr) {
            if (($attr['id'] ?? null) === $id) {
                $valueName = $attr['value_name'] ?? null;

                return $valueName !== null && $valueName !== '' ? (float) $valueName : null;
            }
        }

        return null;
    }

    /** @return array{peso:int,ok:bool} */
    private function sinal(string $chave, bool $ok): array
    {
        return ['peso' => self::PESOS[$chave], 'ok' => $ok];
    }

    private function comoArray(mixed $valor): array
    {
        return is_array($valor) ? $valor : [];
    }
}
