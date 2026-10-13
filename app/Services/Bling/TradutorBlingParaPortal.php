<?php

namespace App\Services\Bling;

use App\Services\Portal\Estrutura\Produtos\NormalizadorDeLinha;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;

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
            'V' => self::traduzirComVariacoes($produto),
            // Kit do Bling é fora de escopo declarado (§3 do CONTEXT, Fase 185). Recusar com
            // motivo, nunca descartar em silêncio — silêncio esconderia produto do cliente.
            'E' => self::recusarProdutoInteiro($produto, 'Kit do Bling não entra nesta fase.'),
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

    /**
     * `formato === 'V'`: 1 produto do Bling com `variacoes[]` vira 1 linha por variação, todas no
     * mesmo `grupo`. Uma variação ruim (código vazio ou repetido) não derruba as outras — ela sai
     * em `recusadas` e o resto segue.
     */
    private static function traduzirComVariacoes(array $produto): array
    {
        $blingPaiId      = self::idComoTexto($produto['id'] ?? null);
        $variacoesBrutas = $produto['variacoes'] ?? [];

        if (! is_array($variacoesBrutas) || $variacoesBrutas === []) {
            return self::recusarProdutoInteiro($produto, 'Produto com variações sem nenhuma variação no Bling.');
        }

        // Grupo = código do pai; pai sem código usa a Ref da 1ª variação que tiver código
        // (learnings portal §39). Nenhuma variação com código → recusa o produto inteiro.
        $grupo = trim((string) ($produto['codigo'] ?? ''));
        if ($grupo === '') {
            foreach ($variacoesBrutas as $variacaoBruta) {
                $candidato = trim((string) ($variacaoBruta['codigo'] ?? ''));
                if ($candidato !== '') {
                    $grupo = $candidato;
                    break;
                }
            }
        }
        if ($grupo === '') {
            return self::recusarProdutoInteiro($produto, 'Produto com variações sem nenhum código no Bling.');
        }

        [$nomePai, $avisoNomePai] = self::nomeCortado($produto['nome'] ?? '');
        $avisos = $avisoNomePai !== null ? [$avisoNomePai] : [];

        [$descricao, $avisoDescricao] = self::descricaoSemHtml($produto['descricaoCurta'] ?? null);
        if ($avisoDescricao !== null) {
            $avisos[] = $avisoDescricao;
        }

        $custo = self::custoDoProduto($produto);

        // custo/descrição/volumes vêm do PAI e vão para todas as linhas — mesma regra do ramo S.
        [$volumes, $avisoVolumes] = self::volumesDoProduto($produto);
        if ($avisoVolumes !== null) {
            $avisos[] = $avisoVolumes;
        }

        $linhas        = [];
        $recusadas     = [];
        $vinculos      = [];
        $codigosVistos = [];

        foreach ($variacoesBrutas as $variacaoBruta) {
            $idVariacao  = self::idComoTexto($variacaoBruta['id'] ?? null);
            $codigoVar   = trim((string) ($variacaoBruta['codigo'] ?? ''));

            if ($codigoVar === '') {
                $recusadas[] = [
                    'bling_produto_id' => $idVariacao,
                    'codigo'           => null,
                    'motivo'           => 'Variação sem código no Bling — o código (Ref) é obrigatório.',
                ];
                continue;
            }

            // Mesma régua de comparação do cadastro: minúsculas, sem acento, sem espaço nas
            // pontas. Chamar o método estático do serviço, não reimplementar (acceptance criteria).
            $chave = ProdutoCadastroService::chaveCodigo($codigoVar);
            if (isset($codigosVistos[$chave])) {
                $recusadas[] = [
                    'bling_produto_id' => $idVariacao,
                    'codigo'           => $codigoVar,
                    'motivo'           => 'Código repetido entre variações do mesmo produto no Bling.',
                ];
                continue;
            }
            $codigosVistos[$chave] = true;

            [$eixo, $valor, $avisoEixo] = self::eixoEValorDaVariacao($variacaoBruta['variacao']['nome'] ?? null, $codigoVar);
            if ($avisoEixo !== null) {
                $avisos[] = $avisoEixo;
            }

            $linha = [
                'codigo' => $codigoVar,
                // A 1ª linha redefine o produto no ProdutoCadastroService; usar o nome do PAI
                // em todas (produto existente mantém o nome atual do Portal).
                'nome'   => $nomePai,
                'grupo'  => $grupo,
            ];

            $ordemBruta = $variacaoBruta['variacao']['ordem'] ?? null;
            if (is_numeric($ordemBruta)) {
                $linha['ordem'] = (int) $ordemBruta;
            }

            if ($eixo !== null) {
                $linha['eixo'] = $eixo;
            }
            if ($valor !== null) {
                $linha['valor'] = $valor;
            }
            if ($descricao !== null) {
                $linha['descricao'] = $descricao;
            }
            if ($custo !== null) {
                $linha['custo'] = $custo;
            }
            if ($volumes !== null) {
                $linha['volumes'] = $volumes;
            }

            $linhas[] = $linha;
            $vinculos[] = [
                'bling_produto_id' => $idVariacao,
                'bling_pai_id'     => $blingPaiId,
                'codigo_bling'     => $codigoVar,
                'formato'          => 'V',
                'gtin'             => self::textoOuNull($variacaoBruta['gtin'] ?? $produto['gtin'] ?? null),
            ];
        }

        return [
            'linhas'    => $linhas,
            'recusadas' => $recusadas,
            'avisos'    => $avisos,
            'vinculos'  => $vinculos,
        ];
    }

    /**
     * `variacao.nome` chega como `"Eixo:Valor"` (`"Cor:Azul"`) ou, por vezes, com dois atributos
     * separados por `;` (`"Cor:Preto;Tamanho:M"`). O Portal tem só 1 eixo por variação.
     *
     * TODO(184-05): dois ou mais pares ainda não têm tratamento definitivo — por ora o primeiro
     * par vence, sem o aviso de "dois atributos" (isso é tarefa do plano 05, não desta).
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} [eixo, valor, aviso ou null]
     */
    private static function eixoEValorDaVariacao(mixed $nomeVariacaoBruto, string $codigoVariacao): array
    {
        $nome = trim((string) $nomeVariacaoBruto);
        if ($nome === '' || ! str_contains($nome, ':')) {
            return [null, null, null];
        }

        $pares = array_values(array_filter(array_map('trim', explode(';', $nome)), fn (string $p) => $p !== ''));
        if ($pares === []) {
            return [null, null, null];
        }

        // TODO(184-05): usar só $pares[0] enquanto o plano 05 não resolve 2+ atributos.
        [$rotulo, $valor] = array_pad(array_map('trim', explode(':', $pares[0], 2)), 2, null);
        if ($rotulo === null || $valor === null || $valor === '') {
            return [null, null, null];
        }

        $eixo = NormalizadorDeLinha::eixo($rotulo);
        if ($eixo === null) {
            return ['outro', $valor, "Variação do código \"{$codigoVariacao}\": o tipo \"{$rotulo}\" não está na lista, usamos \"Outro\"."];
        }

        return [$eixo, $valor, null];
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
