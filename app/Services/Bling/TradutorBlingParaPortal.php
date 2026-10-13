<?php

namespace App\Services\Bling;

use App\Services\Portal\Estrutura\Produtos\NormalizadorDeLinha;

/**
 * Traduz o produto do Bling (`GET /produtos/{id}`) para linhas no contrato de entrada do
 * `NormalizadorDeLinha` — o mesmo que a planilha e a grade usam.
 *
 * Função pura: **sem** banco e **sem** HTTP. Só assim ela se prova por teste sem rede
 * (`tests/Unit/Bling/TradutorBlingParaPortalTest.php`). Quem grava é o importador (plano 08), que
 * chama `ProdutoCadastroService::gravarLinhas(..., MODO_IMPORTACAO)` com o que sai de `linhas`.
 *
 * Contrato de saída de `paraLinhas()`:
 * - `linhas`: list<array> — cada item no formato que `NormalizadorDeLinha::normalizar()` lê.
 * - `recusadas`: list<array{bling_produto_id: ?string, codigo: ?string, motivo: string}> — produto
 *   ou variação que não pôde virar linha, com o motivo em pt-BR (nunca descarte silencioso).
 * - `avisos`: list<string> — degradação que não impede a linha (corte de texto, eixo "outro"...).
 * - `vinculos`: list<array{bling_produto_id: string, bling_pai_id: ?string, codigo_bling: ?string, formato: string, gtin: ?string}>
 *   — o que o importador grava em `bling_vinculos`; este tradutor só monta, nunca toca no banco.
 *
 * Regra de "chave ausente = não mexer" (mesma do `NormalizadorDeLinha`): quando o Bling não trouxe
 * o dado, a chave correspondente da linha é **omitida**, nunca mandada como `''` ou `0`.
 *
 * `preco` e `categoria.id` do Bling **nunca** entram na linha: o preço é calculado pela
 * Precificação (ECF), e a árvore de categoria do Bling é do cliente — não casa com a do Mercado
 * Livre. Não existe, nesta classe, nenhum caminho de código que leia o preço ou a categoria do
 * produto do Bling para popular a linha.
 */
final class TradutorBlingParaPortal
{
    /** @return array{linhas: list<array<string, mixed>>, recusadas: list<array{bling_produto_id: ?string, codigo: ?string, motivo: string}>, avisos: list<string>, vinculos: list<array{bling_produto_id: string, bling_pai_id: ?string, codigo_bling: ?string, formato: string, gtin: ?string}>} */
    public static function paraLinhas(array $produto): array
    {
        $formato = $produto['formato'] ?? null;

        return match ($formato) {
            'S' => self::traduzirSimples($produto),
            default => self::recusarProdutoInteiro($produto, self::motivoFormato($formato)),
        };
    }

    private static function motivoFormato(mixed $formato): string
    {
        if ($formato === null || $formato === '') {
            return 'Produto do Bling sem "formato" informado.';
        }

        return "Formato de produto do Bling não suportado: \"{$formato}\".";
    }

    /** @return array{linhas: list<array<string, mixed>>, recusadas: list<array{bling_produto_id: ?string, codigo: ?string, motivo: string}>, avisos: list<string>, vinculos: list<array{bling_produto_id: string, bling_pai_id: ?string, codigo_bling: ?string, formato: string, gtin: ?string}>} */
    private static function recusarProdutoInteiro(array $produto, string $motivo): array
    {
        return [
            'linhas'    => [],
            'recusadas' => [[
                'bling_produto_id' => self::idComoTexto($produto['id'] ?? null),
                'codigo'           => self::codigoOuNull($produto['codigo'] ?? null),
                'motivo'           => $motivo,
            ]],
            'avisos'    => [],
            'vinculos'  => [],
        ];
    }

    /** `formato === 'S'`: 1 produto do Bling vira exatamente 1 linha (grupo de um só). */
    private static function traduzirSimples(array $produto): array
    {
        $blingProdutoId = self::idComoTexto($produto['id'] ?? null);
        $codigo         = trim((string) ($produto['codigo'] ?? ''));

        if ($codigo === '') {
            return self::recusarProdutoInteiro($produto, 'Produto sem código no Bling — o código (Ref) é obrigatório.');
        }

        if (mb_strlen($codigo) > 120) {
            return self::recusarProdutoInteiro($produto, 'O código do Bling tem mais de 120 caracteres.');
        }

        $avisos = [];

        [$nome, $avisoNome] = self::nomeCortado($produto['nome'] ?? '');
        if ($avisoNome !== null) {
            $avisos[] = $avisoNome;
        }

        $linha = [
            'codigo' => $codigo,
            'nome'   => $nome,
            'grupo'  => $codigo,
        ];

        [$descricao, $avisoDescricao] = self::descricaoSemHtml($produto['descricaoCurta'] ?? null);
        if ($avisoDescricao !== null) {
            $avisos[] = $avisoDescricao;
        }
        if ($descricao !== null) {
            $linha['descricao'] = $descricao;
        }

        $custo = self::custoDoProduto($produto);
        if ($custo !== null) {
            $linha['custo'] = $custo;
        }

        [$volumes, $avisoVolumes] = self::volumesDoProduto($produto);
        if ($avisoVolumes !== null) {
            $avisos[] = $avisoVolumes;
        }
        if ($volumes !== null) {
            $linha['volumes'] = $volumes;
        }

        return [
            'linhas'    => [$linha],
            'recusadas' => [],
            'avisos'    => $avisos,
            'vinculos'  => [[
                'bling_produto_id' => $blingProdutoId,
                'bling_pai_id'     => null,
                'codigo_bling'     => $codigo,
                'formato'          => 'S',
                'gtin'             => self::textoOuNull($produto['gtin'] ?? null),
            ]],
        ];
    }

    /** @return array{0: string, 1: ?string} [nome cortado, aviso ou null] */
    private static function nomeCortado(mixed $nomeBruto): array
    {
        $nome = trim((string) $nomeBruto);
        if (mb_strlen($nome) <= 255) {
            return [$nome, null];
        }

        return [mb_substr($nome, 0, 255), 'Nome do Bling cortado em 255 caracteres.'];
    }

    /**
     * `descricaoCurta` vem em HTML; o Portal guarda texto puro, até 5.000 caracteres.
     * `descricaoComplementar` nunca é lida — é ignorada por decisão do plano (184-02).
     *
     * @return array{0: ?string, 1: ?string} [descrição ou null quando ausente, aviso ou null]
     */
    private static function descricaoSemHtml(mixed $descricaoCurtaBruta): array
    {
        if (! is_string($descricaoCurtaBruta) || trim($descricaoCurtaBruta) === '') {
            return [null, null];
        }

        $semTags  = strip_tags($descricaoCurtaBruta);
        $decodada = html_entity_decode($semTags, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $colapsada = trim((string) preg_replace('/\s+/u', ' ', $decodada));

        if ($colapsada === '') {
            return [null, null];
        }

        if (mb_strlen($colapsada) > NormalizadorDeLinha::MAX_DESCRICAO) {
            return [mb_substr($colapsada, 0, NormalizadorDeLinha::MAX_DESCRICAO), 'Descrição do Bling cortada em '.NormalizadorDeLinha::MAX_DESCRICAO.' caracteres.'];
        }

        return [$colapsada, null];
    }

    /**
     * `custo` vem de `fornecedor.precoCusto`, com reserva em `precoCusto` no nível do produto.
     * Ausente ou não numérico → `null` (a chave sai omitida da linha — "chave ausente = não mexer").
     */
    private static function custoDoProduto(array $produto): ?float
    {
        $custo = $produto['fornecedor']['precoCusto'] ?? $produto['precoCusto'] ?? null;

        if (! is_numeric($custo)) {
            return null;
        }

        return (float) $custo;
    }

    /**
     * Monta a lista de `volumes` (contrato cm/kg do `NormalizadorDeLinha`) a partir de
     * `dimensoes` + `pesoBruto` (reserva `pesoLiquido`) do pai/produto simples.
     *
     * Só o caminho `unidadeMedida === 1` (cm) está implementado nesta tarefa — metro (`0`) e
     * milímetro (`2`) são `TODO(184-05)`: a suposição 7 do RESEARCH §5 (se a medida é do produto
     * embalado) também fica para lá. `volumes > 1` do Bling é outro caso de borda do plano 05: por
     * ora é tratado como medida incompleta (omite a chave, com aviso), nunca como fato inventado.
     *
     * @return array{0: ?list<array{c: float, l: float, a: float, kg: float}>, 1: ?string}
     */
    private static function volumesDoProduto(array $produto): array
    {
        $qtdVolumes = $produto['volumes'] ?? null;
        if ($qtdVolumes !== null && (int) $qtdVolumes > 1) {
            // TODO(184-05): produto com mais de 1 volume — medida não entra, por decisão do plano.
            return [null, 'Produto do Bling com mais de 1 volume: a medida não entra nesta fase.'];
        }

        $dimensoes = $produto['dimensoes'] ?? null;
        if (! is_array($dimensoes)) {
            return [null, null];
        }

        $unidade = $dimensoes['unidadeMedida'] ?? null;
        $largura      = self::numeroOuNull($dimensoes['largura'] ?? null);
        $altura       = self::numeroOuNull($dimensoes['altura'] ?? null);
        $profundidade = self::numeroOuNull($dimensoes['profundidade'] ?? null);
        $peso         = self::numeroOuNull($produto['pesoBruto'] ?? $produto['pesoLiquido'] ?? null);

        if ($largura === null || $altura === null || $profundidade === null || $peso === null || $largura <= 0 || $altura <= 0 || $profundidade <= 0 || $peso <= 0) {
            return [null, 'Medida ou peso incompletos no Bling: o volume não entra (não é erro de produto).'];
        }

        if ($unidade !== 1) {
            // TODO(184-05): unidadeMedida 0 (metro) e 2 (milímetro) — converter para cm.
            // Nesta tarefa só 1 (cm) está provado; unidade desconhecida segue sem conversão.
        }

        return [[[
            'c'  => $profundidade, // dimensoes.profundidade É o comprimento do Portal
            'l'  => $largura,
            'a'  => $altura,
            'kg' => $peso,
        ]], null];
    }

    private static function numeroOuNull(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private static function idComoTexto(mixed $id): ?string
    {
        return ($id === null || $id === '') ? null : (string) $id;
    }

    private static function codigoOuNull(mixed $codigo): ?string
    {
        $texto = trim((string) ($codigo ?? ''));

        return $texto === '' ? null : $texto;
    }

    private static function textoOuNull(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $texto = trim((string) $v);

        return $texto === '' ? null : $texto;
    }
}
