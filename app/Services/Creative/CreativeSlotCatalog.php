<?php

namespace App\Services\Creative;

use App\Services\Creative\Dto\ProductTruth;

/**
 * Biblioteca de tipos de criativo do kit (Fase 161, §8.4) — cada tipo
 * com o requisito de fato que o Product Truth precisa sustentar para ele
 * ser elegível, e a prioridade de preenchimento quando falta slot.
 *
 * Quick 261007-kit2 (2026-10-07): o tamanho do kit passou de 7 para 2 (a
 * quantidade é `$quantidade` do chamador, via `config('services.creative.
 * kit.slots')`) — a ordem abaixo (hero → COM_FATO elegíveis → resto dos
 * SEM_FATO) é o que faz emergir, sozinho, o comportamento pedido: com fato,
 * principal + a melhor com texto; sem fato, principal + um visual.
 *
 * DUAS listas (Decisão 2/3 do 161-01-PLAN.md):
 *   - SEM_FATO: puramente visuais, SEMPRE elegíveis — nenhum afirma nada
 *     sobre o produto além do que as fotos de referência já mostram. São 8
 *     — o piso que garante slots suficientes para QUALQUER produto, mesmo
 *     sem atributo nenhum cadastrado (o piso original era dimensionado
 *     para PLAN-01/mínimo 7 da Fase 161; a quantidade pedida hoje é bem
 *     menor, mas o piso de 8 tipos visuais continua cobrindo-a com folga).
 *   - COM_FATO: só elegíveis quando o Product Truth sustenta o requisito —
 *     cada requisito é um padrão FECHADO de id de atributo ou contagem
 *     mínima de fatos (mesma disciplina de
 *     `ProductTruthBuilder::PADRAO_ID_CONTAGEM`), nunca busca de substring
 *     em texto livre.
 *
 * Ordem de `elegiveis()`: hero primeiro (slot 1, PLAN-02), depois os
 * COM_FATO elegíveis (são os melhores — entram substituindo os genéricos
 * quando o fato existe, Decisão 3), depois o resto dos SEM_FATO.
 *
 * Quick 261007-amb (2026-10-07): categoria de MÓVEIS troca QUEM ocupa a
 * posição 1 — `lifestyle` (Ambientação) em vez de `hero` (fundo branco),
 * a pedido do usuário. `elegiveis(bool $categoriaMoveis)` só decide ISSO:
 * troca o item na frente da lista, sem tocar em mais nada — os COM_FATO
 * elegíveis continuam entrando na mesma posição (2ª, 3ª…) de sempre, e o
 * `hero` (quando não é o primeiro) volta a disputar como qualquer outro
 * SEM_FATO. Ver `CreativeCategoriaMobiliarioService` para a detecção por
 * `path_from_root` (a API não expõe nenhuma flag "é móvel").
 */
class CreativeSlotCatalog
{
    /** Puramente visuais — sempre elegíveis, nesta ordem de prioridade. */
    private const PRIORIDADE_SEM_FATO = [
        'hero', 'white_background', 'angles', 'detail',
        'lifestyle', 'lifestyle_uso', 'detail_textura', 'composicao',
    ];

    /** Só elegíveis quando o Truth sustenta o requisito — nesta ordem de prioridade. */
    private const PRIORIDADE_COM_FATO = [
        'dimensions', 'package_content', 'specifications',
        'benefits', 'feature_highlight', 'how_to_use',
    ];

    /** Tipos com `aceita_texto = true` — os demais (inclusive todo SEM_FATO) nunca aceitam texto. */
    private const ACEITAM_TEXTO = [
        'benefits', 'specifications', 'dimensions',
        'package_content', 'feature_highlight', 'how_to_use',
    ];

    /**
     * Layout da imagem de MEDIDAS (quick 261007-amb), inspirado num print
     * real de anúncio do Mercado Livre mandado pelo usuário — só o tipo
     * `dimensions` usa este layout. É FORMA, nunca conteúdo: os valores
     * que entram na imagem continuam vindo exclusivamente do
     * `ProductTruth` via `CreativePromptBuilder`/`validarTexto()` — este
     * texto não contém nenhum número, nenhuma medida.
     *
     * `CreativePlanner::montarSlotAceito()` FORÇA este texto como `cena`
     * sempre que o tipo é `dimensions`, mesmo que o LLM proponha outra
     * cena — o layout é pedido do usuário, não espaço de criatividade do
     * modelo (mesmo motivo de PLAN-02 forçar `hero`/`lifestyle` na
     * posição 1).
     */
    private const LAYOUT_MEDIDAS = 'Fundo claro e liso. Cabeçalho curto em caixa alta com ícone simples '
        .'de régua (ex.: "TAMANHO DO PRODUTO"). Ao lado do cabeçalho, a lista das medidas com marcadores '
        .'quadrados. Produto centralizado, em ângulo que mostre bem suas dimensões, com linhas de cota '
        .'finas sobre ele — seta nas duas pontas de cada linha, valor da medida rotulado ao lado. '
        .'Miniatura do produto em outro ângulo no canto superior. Tipografia sans-serif, texto escuro '
        .'sobre fundo claro.';

    /**
     * Layout da imagem de TÓPICOS (quick 261007-amb), inspirado noutro
     * print real de anúncio — usado pelos demais tipos COM_FATO que
     * aceitam texto (`package_content`, `specifications`, `benefits`,
     * `feature_highlight`, `how_to_use`). Mesma disciplina de
     * `LAYOUT_MEDIDAS`: é FORMA, nunca conteúdo, e é FORÇADO como `cena`
     * pelo `CreativePlanner`, nunca a critério do LLM.
     */
    private const LAYOUT_TOPICOS = 'Fundo branco. Um ou dois recortes circulares com close-up de uma '
        .'parte do produto. De cada círculo, uma linha fina horizontal até um rótulo curto em negrito, '
        .'com uma linha de apoio menor abaixo. Barra vertical fina de cor escura na borda esquerda da '
        .'imagem.';

    /**
     * id de atributo casando dimensão (largura/altura/comprimento/profundidade),
     * por sufixo OU nome exato — nunca substring livre.
     *
     * ⚠️ Casa TAMBÉM `SELLER_PACKAGE_WIDTH`/`PACKAGE_HEIGHT` etc. (medida da
     * CAIXA) — por isso nunca usar este padrão isolado para `dimensions`; ver
     * `PADRAO_ID_EMBALAGEM` e `temAtributoDeDimensaoDoProduto()`.
     */
    private const PADRAO_ID_DIMENSAO = '/_(WIDTH|HEIGHT|LENGTH|DEPTH)$|^(WIDTH|HEIGHT|LENGTH|DEPTH)$/';

    /**
     * id de atributo de embalagem/frete — mede a CAIXA, nunca o PRODUTO.
     * Prefixo fechado, nunca substring livre. Os dois prefixos usados de fato
     * neste projeto (conferidos em `ClassificadorAtributos`,
     * `AnuncioSaudeService::ATRIBUTOS_DIMENSAO` e fixtures de categoria):
     *   - `SELLER_PACKAGE_*` — a medida que o VENDEDOR informa (peso/altura/
     *     largura/comprimento da caixa declarada).
     *   - `PACKAGE_*` — o atributo de SISTEMA do Mercado Livre para a mesma
     *     medida de pacote (ex. `PACKAGE_WEIGHT`, `PACKAGE_HEIGHT`).
     * `SHIPPING_*` existe no projeto só como `SHIPPING_ORIGIN` (fixture de
     * sale_terms, sem relação com medida) — por isso fica de fora da lista.
     *
     * Achado em produção (quick 261007-ifa, rascunho 8, categoria MLB31578):
     * produto só com `SELLER_PACKAGE_*` (todos 12 cm) tornava `dimensions`
     * elegível com a medida da caixa, não da mesa anunciada.
     */
    private const PADRAO_ID_EMBALAGEM = '/^(SELLER_PACKAGE_|PACKAGE_)/';

    /** id de atributo de conteúdo de kit/acessórios — prefixo fechado. */
    private const PADRAO_ID_CONTEUDO_KIT = '/^(KIT_|INCLUDED_|ACCESSORIES)/';

    /** id de atributo de instalação/montagem — prefixo fechado. */
    private const PADRAO_ID_INSTALACAO = '/^(INSTALLATION|ASSEMBLY|MOUNTING)/';

    /** @return array<string, array{rotulo: string, objetivo_padrao: string, cena_padrao: string, aceita_texto: bool}> */
    private function catalogo(): array
    {
        return [
            'hero' => [
                'rotulo'          => 'Imagem principal',
                'objetivo_padrao' => 'Imagem de capa do anúncio — produto isolado, sem texto nem logo (regra de moderação do Mercado Livre).',
                'cena_padrao'     => 'Produto centralizado, fundo branco limpo, luz de estúdio, enquadramento 1:1.',
                'aceita_texto'    => false,
            ],
            'white_background' => [
                'rotulo'          => 'Fundo branco',
                'objetivo_padrao' => 'Complementar o hero com outro ângulo, mesmo fundo branco limpo.',
                'cena_padrao'     => 'Produto em ângulo diferente do hero, fundo branco contínuo, luz uniforme.',
                'aceita_texto'    => false,
            ],
            'angles' => [
                'rotulo'          => 'Ângulos',
                'objetivo_padrao' => 'Dar sensação de volume mostrando o produto em múltiplos ângulos.',
                'cena_padrao'     => 'Três quartos, lateral e traseira do produto, fundo neutro.',
                'aceita_texto'    => false,
            ],
            'detail' => [
                'rotulo'          => 'Detalhe',
                'objetivo_padrao' => 'Aproximar de um acabamento visível nas fotos de referência.',
                'cena_padrao'     => 'Close-up num detalhe de acabamento do produto.',
                'aceita_texto'    => false,
            ],
            'lifestyle' => [
                'rotulo'          => 'Ambientação',
                'objetivo_padrao' => 'Mostrar o produto num ambiente real, sem alterar sua aparência.',
                'cena_padrao'     => 'Produto posicionado num ambiente coerente com seu uso.',
                'aceita_texto'    => false,
            ],
            'lifestyle_uso' => [
                'rotulo'          => 'Em uso',
                'objetivo_padrao' => 'Mostrar o produto sendo usado, sem inventar funcionalidade.',
                'cena_padrao'     => 'Produto em uso, num contexto plausível para o seu propósito.',
                'aceita_texto'    => false,
            ],
            'detail_textura' => [
                'rotulo'          => 'Textura',
                'objetivo_padrao' => 'Aproximar do material/textura do produto visível nas fotos.',
                'cena_padrao'     => 'Close-up na superfície do material do produto.',
                'aceita_texto'    => false,
            ],
            'composicao' => [
                'rotulo'          => 'Composição',
                'objetivo_padrao' => 'Compor o produto com elementos neutros de cenário, sem alterar o produto.',
                'cena_padrao'     => 'Produto centralizado com elementos de cenário neutros ao redor.',
                'aceita_texto'    => false,
            ],
            'dimensions' => [
                'rotulo'          => 'Dimensões',
                'objetivo_padrao' => 'Mostrar as medidas reais do produto, só com os valores confirmados no cadastro.',
                'cena_padrao'     => self::LAYOUT_MEDIDAS,
                'aceita_texto'    => true,
            ],
            'package_content' => [
                'rotulo'          => 'Conteúdo da embalagem',
                'objetivo_padrao' => 'Mostrar as peças que vêm na caixa, exatamente a contagem confirmada no cadastro.',
                'cena_padrao'     => self::LAYOUT_TOPICOS,
                'aceita_texto'    => true,
            ],
            'specifications' => [
                'rotulo'          => 'Especificações',
                'objetivo_padrao' => 'Resumir as especificações técnicas confirmadas no cadastro.',
                'cena_padrao'     => self::LAYOUT_TOPICOS,
                'aceita_texto'    => true,
            ],
            'benefits' => [
                'rotulo'          => 'Benefícios',
                'objetivo_padrao' => 'Destacar os benefícios que o cadastro sustenta, sem inventar.',
                'cena_padrao'     => self::LAYOUT_TOPICOS,
                'aceita_texto'    => true,
            ],
            'feature_highlight' => [
                'rotulo'          => 'Destaque de funcionalidade',
                'objetivo_padrao' => 'Dar zoom numa funcionalidade específica confirmada no cadastro.',
                'cena_padrao'     => self::LAYOUT_TOPICOS,
                'aceita_texto'    => true,
            ],
            'how_to_use' => [
                'rotulo'          => 'Como instalar/montar',
                'objetivo_padrao' => 'Mostrar o passo de instalação/montagem confirmado no cadastro.',
                'cena_padrao'     => self::LAYOUT_TOPICOS,
                'aceita_texto'    => true,
            ],
        ];
    }

    /** @return array<int, string> todos os tipos conhecidos, SEM_FATO + COM_FATO. */
    public function tipos(): array
    {
        return array_keys($this->catalogo());
    }

    public function existe(string $tipo): bool
    {
        return array_key_exists($tipo, $this->catalogo());
    }

    public function aceitaTexto(string $tipo): bool
    {
        return in_array($tipo, self::ACEITAM_TEXTO, true);
    }

    /** @return array{rotulo: string, objetivo_padrao: string, cena_padrao: string, aceita_texto: bool}|null */
    public function padraoDe(string $tipo): ?array
    {
        return $this->catalogo()[$tipo] ?? null;
    }

    /**
     * Tipos elegíveis para ESTE Truth, em ordem de prioridade: o primeiro
     * slot ganha, depois os COM_FATO cujo requisito o Truth sustenta,
     * depois o resto dos SEM_FATO. Nunca inclui um tipo COM_FATO cujo
     * requisito falte (PLAN-03).
     *
     * `$categoriaMoveis` (quick 261007-amb) decide QUEM é o primeiro slot:
     * `lifestyle` (Ambientação) para categoria de móvel, `hero` (fundo
     * branco) para qualquer outra — default `false` preserva o
     * comportamento de sempre (PLAN-02) para todo chamador que ainda não
     * sabe sobre móveis.
     *
     * @return array<int, string>
     */
    public function elegiveis(ProductTruth $truth, bool $categoriaMoveis = false): array
    {
        $primeiro = $categoriaMoveis ? 'lifestyle' : 'hero';

        $comFatoElegiveis = array_values(array_filter(
            self::PRIORIDADE_COM_FATO,
            fn (string $tipo) => $this->satisfaz($tipo, $truth)
        ));

        $semFatoSemPrimeiro = array_values(array_diff(self::PRIORIDADE_SEM_FATO, [$primeiro]));

        return array_merge([$primeiro], $comFatoElegiveis, $semFatoSemPrimeiro);
    }

    /** O requisito de cada tipo COM_FATO (PLAN-03) — nunca busca em texto livre. */
    private function satisfaz(string $tipo, ProductTruth $truth): bool
    {
        return match ($tipo) {
            'dimensions'        => $this->temAtributoDeDimensaoDoProduto($truth),
            'package_content'   => $this->temContagemDeKit($truth) || $this->temAtributoCasando($truth, self::PADRAO_ID_CONTEUDO_KIT),
            'specifications'    => count($truth->fatosVerificados) >= 2,
            'benefits'          => count($truth->fatosVerificados) >= 3,
            'feature_highlight' => count($truth->fatosVerificados) >= 1,
            'how_to_use'        => $this->temAtributoCasando($truth, self::PADRAO_ID_INSTALACAO),
            default             => false,
        };
    }

    /**
     * `true` quando algum tipo que aceita texto (`ACEITAM_TEXTO`, igual a
     * `PRIORIDADE_COM_FATO` hoje) é elegível para este Truth — reaproveita
     * `elegiveis()`, não duplica a lógica de prioridade.
     *
     * Usado por `CreativePlanner::planejar()` para gravar, junto do plano do
     * kit, se nenhuma imagem vai poder ter texto — a tela avisa o operador a
     * partir dessa informação (`PublicadorCriativoKitPresenter::paraTela()`).
     */
    public function algumAceitaTexto(ProductTruth $truth): bool
    {
        return array_intersect($this->elegiveis($truth), self::ACEITAM_TEXTO) !== [];
    }

    /**
     * O que falta para habilitar texto em algum slot, em pt-BR — vazio
     * quando `algumAceitaTexto()` já é `true`. Nunca sugere afrouxar
     * TRUTH-02/03: só aponta o caminho que já existe (completar o cadastro
     * no Mercado Livre), nunca "inventar"/"afrouxar" a régua de fato.
     *
     * @return array<int, string>
     */
    public function faltamParaTexto(ProductTruth $truth): array
    {
        if ($this->algumAceitaTexto($truth)) {
            return [];
        }

        $faltamBeneficios = max(1, 3 - count($truth->fatosVerificados));

        return [
            "Confirme mais {$faltamBeneficios} ponto(s) forte(s) do produto no cadastro do Mercado Livre para habilitar texto no slot de benefícios.",
            'Confirme uma medida do produto no cadastro do Mercado Livre para habilitar texto no slot de dimensões.',
        ];
    }

    private function temAtributoCasando(ProductTruth $truth, string $padrao): bool
    {
        foreach (array_keys($truth->atributosIds) as $id) {
            if (preg_match($padrao, (string) $id) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Igual a `temAtributoCasando($truth, PADRAO_ID_DIMENSAO)`, mas descarta
     * primeiro qualquer id de embalagem/frete (`PADRAO_ID_EMBALAGEM`) — medida
     * da CAIXA nunca satisfaz `dimensions` (achado 261007-ifa). Produto com
     * medida própria (`WIDTH`/`HEIGHT`/`DEPTH`/`LENGTH` ou `*_WIDTH` que não
     * seja de embalagem) continua elegível exatamente como antes.
     */
    private function temAtributoDeDimensaoDoProduto(ProductTruth $truth): bool
    {
        foreach (array_keys($truth->atributosIds) as $id) {
            $id = (string) $id;

            if (preg_match(self::PADRAO_ID_EMBALAGEM, $id) === 1) {
                continue;
            }

            if (preg_match(self::PADRAO_ID_DIMENSAO, $id) === 1) {
                return true;
            }
        }

        return false;
    }

    /** `peca` da contagem menciona kit/peças — texto do CADASTRO (ver `ProductTruthBuilder::PECA_POR_ID`), não inferência. */
    private function temContagemDeKit(ProductTruth $truth): bool
    {
        foreach ($truth->contagens as $contagem) {
            $peca = mb_strtolower((string) ($contagem['peca'] ?? ''));

            if (str_contains($peca, 'kit') || str_contains($peca, 'peça') || str_contains($peca, 'pecas')) {
                return true;
            }
        }

        return false;
    }
}
