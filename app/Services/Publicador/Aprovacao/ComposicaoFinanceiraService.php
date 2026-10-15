<?php

namespace App\Services\Publicador\Aprovacao;

use App\Models\EstruturaPrecificacaoParametros;
use App\Models\PubAprovacaoComposicao;
use App\Models\PubAprovacaoFrete;
use App\Models\PubAprovacaoOferta;
use App\Models\PubProduto;
use App\Models\PubVariantePreco;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\DadosEfetivosService;
use App\Support\Publicador\Validacao\SimuladorVoceRecebe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A COMPOSIÇÃO FINANCEIRA DO PORTÃO — as 7 linhas, cada uma com **valor, origem e nota que NOMEIA
 * a fonte REAL**; o resultado estimado por venda; a margem; o equilíbrio; o preço-alvo; a simulação
 * que **nunca** é persistida; e a comparação com a oferta irmã.
 *
 * ═══ ⚠️⚠️ AS 7 LINHAS → A FONTE REAL (medida e conferida por `grep` em 15/10/2026) ═════════════
 *
 * | linha         | fonte real                                                                      |
 * |---------------|---------------------------------------------------------------------------------|
 * | `custo`       | {@see DadosEfetivosService::custosDoProduto()} `['custo']` + `['origem']`,       |
 * |               | **× `PubProduto::$quantidade_kit`** (a nota do combo é "N un. × R$ x = R$ y")    |
 * | `comissao`    | **API do ML** `/sites/MLB/listing_prices` → `sale_fee_details.percentage_fee`    |
 * | `tarifa_fixa` | **API do ML** `/sites/MLB/listing_prices` → `sale_fee_details.fixed_fee`         |
 * | `frete`       | {@see DadosEfetivosService::fretesDoProduto()}, **vencido** por                  |
 * |               | `PubAprovacaoFrete::vigenteDe()`; a montagem é                                   |
 * |               | {@see PubAprovacaoComposicao::linhaDeFrete()} (lido, nunca recopiado)            |
 * | `imposto`     | `custosDoProduto()['imposto']` — já aplica a **exceção da oferta** vencendo o    |
 * |               | parâmetro da empresa (`DadosEfetivosService:216-232`)                           |
 * | `embalagem`   | ⚠️ **NÃO TEM FONTE NO REPOSITÓRIO** (medido) ⇒ `Pendente` que **não** anula      |
 * | `outros`      | ⚠️ **NÃO TEM FONTE NO REPOSITÓRIO** (medido) ⇒ **R$ 0,00 `Confirmado`**          |
 *
 * E uma **8ª linha informativa** (FIN-08): "Frete pago pelo comprador · não entra" — ela aparece e
 * **nunca** entra na soma.
 *
 * ═══ ⚠️⚠️ AS CINCO ARMADILHAS DE `sale_fee_details`, TODAS MEDIDAS EM FIXTURE REAL ═════════════
 *
 * Fonte citável: `tests/fixtures-ml/sondagem/publico/categorias/MLB189007/listing_prices_gold_special.json`
 * (captura de 2026-10-01, `price: 150`) → `sale_fee_amount: 16.5`,
 * `sale_fee_details: { fixed_fee: 0, gross_amount: 16.5, percentage_fee: 11 }`.
 *
 *  1. ⚠️ `percentage_fee` é **PONTO PERCENTUAL** (`11`), **não fração** (`0,11`). Dividir errado
 *     aqui erra a composição por **100×**. Sempre `percentage_fee / 100`.
 *  2. ⚠️ `gross_amount` **JÁ É O TOTAL** (`preço × percentage_fee/100 + fixed_fee`; conferido nos
 *     dois casos disponíveis). Ele serve de **CONFERÊNCIA**, **nunca** de terceira parcela —
 *     somá-lo com as partes **duplicaria** a tarifa.
 *  3. ⚠️ `percentage_fee` **VARIA**: medidos **11, 12, 14, 15 e 17** nas fixtures reais, por
 *     categoria e tipo de anúncio. Os `12`/`17` do README do desenho **coincidem** com dois valores
 *     reais — o que torna o hardcode ainda mais tentador e ainda mais errado: ele acertaria em
 *     algumas categorias e **erraria calado** nas outras.
 *  4. ⚠️ `fixed_fee: 0` é **ZERO MEDIDO**, não campo morto: o preço sondado está acima do limiar do
 *     ML. Das **17** ocorrências, **16** são zero, e a única diferente é documentação **ARGENTINA**
 *     (`tests/fixtures-ml/alavancas/doc/analise/listing_prices.json`, `origem: "doc-oficial"`,
 *     `currency_id: "ARS"`, site MLA). ⇒ `0` é **`Confirmado` mostrando R$ 0,00**, nunca `Pendente`.
 *  5. ⚠️⚠️ A MESMA resposta traz **`listing_fee_details`** com os MESMOS nomes `fixed_fee` e
 *     `gross_amount` — é a tarifa de **ANÚNCIO**, outra coisa, e nas fixtures ela é `0`. Ler o bloco
 *     errado dá **zero SEMPRE, calado**, e como os dois são zero na fixture um teste frouxo passaria
 *     nos dois casos. ⚠️ **É `sale_fee_details`, e só.** A fixture de doc traz ainda
 *     `meli_percentage_fee: 13`, que as respostas reais do MLB não têm: o total é `percentage_fee`.
 *
 * ⚠️ **Ramo DEFENSIVO, e é ramo de ERRO — não o esperado:** resposta sem `sale_fee_details` (ou sem
 * `percentage_fee`) ⇒ a linha de tarifa nasce **`Pendente`** com a nota real e a composição fica
 * `incompleto`. **NUNCA** cair em percentual fixo, e **NUNCA** colapsar em "uma linha só".
 *
 * ═══ ⚠️ (a) O QUE EXISTE E **NÃO** SE USA, com o motivo ════════════════════════════════════════
 *
 * `EstruturaPrecificacaoParametros::PADROES` tem **`comissao_classico => 11.5`** e
 * **`comissao_premium => 16.5`** — perto o bastante de `12`/`17` para alguém achar que achou a
 * fonte. ⚠️ **NÃO SÃO A FONTE DESTA COMPOSIÇÃO.** Eles são os parâmetros com que o **Portal
 * PRECIFICA** (outro eixo). A composição do portão usa o **número real do ML**, porque FIN-04 exige
 * que o painel **nunca discorde** do card "Quanto você recebe" do editor para o mesmo preço — e
 * aquele card usa a API ({@see SimuladorVoceRecebe}).
 *
 * ⚠️ E o **imposto real é `19.0`** (`PADROES`), não os 6% do README do desenho. Nenhum número de
 * taxa mora neste arquivo: nem literal, nem `const`, nem config.
 *
 * ═══ ⚠️⚠️ (a-0) DECISÃO (9) DO USUÁRIO, 14/10/2026 — CADA MARGEM DIZ DE ONDE VEM ═══════════════
 *
 * Este projeto tem **DUAS** margens, **de propósito**:
 *
 *  · **o portão e o editor** usam a **tarifa REAL do Mercado Livre** (`sale_fee_amount` /
 *    `sale_fee_details`) — é esta classe e o `EditorRascunhoService::simular()`;
 *  · **a visão rápida do lote** usa o **parâmetro da Precificação**
 *    (`app/Services/Publicador/Fila/ResumoRapidoService.php:384-391` — ⚠️ o caminho é em `Fila/`).
 *
 * O docblock de `EditorRascunhoService:370-373` diz textualmente *"diferente DE PROPÓSITO […] São
 * perguntas diferentes; não unificar"*, e as Alavancas seguem o caminho da API. ➡️ **A escolha do
 * número real do ML está certa e NÃO muda** (FIN-04).
 *
 * ⚠️⚠️ **MAS A TELA NOMEIA A FONTE:** no portão, a nota da margem diz *"com a tarifa real do
 * Mercado Livre"*. ⚠️ **E está declarado aqui que o MESMO produto mostra número DIFERENTE na visão
 * rápida do lote** (lá a frase é *"estimada pela sua Precificação"*). **Isso é desenho, não bug** —
 * sem este texto a divergência chega ao usuário como **bug de dado**, contra a regra-mestra de
 * acertividade do projeto.
 *
 * ═══ ⚠️⚠️ (b) A MARGEM MÍNIMA TEM **FALLBACK**, E ELE É DELIBERADO (14/10/2026) ════════════════
 *
 * ```
 * margem mínima = parâmetros da empresa `margem_contribuicao`   quando > 0
 *               senão  config('publicador.aprovacao.margem_minima_percentual')   (padrão 12,0)
 * ```
 *
 * ⚠️ **O FALLBACK É O PONTO INTEIRO DA DECISÃO.** O `PADROES` daquele modelo tem
 * `margem_contribuicao => 0.0`, então **só-parâmetro DESLIGARIA o bloqueio** em toda empresa que
 * nunca salvou a Calculadora de Custo, e **só-config IGNORARIA** quem configurou a própria
 * exigência. **As duas simplificações falham em SILÊNCIO.** ➡️ **O zero NÃO desliga o bloqueio** —
 * ele cai na config.
 *
 * ⚠️ O resolvedor é {@see self::margemMinimaComOrigem()} e mora **em UM lugar só**: ele é lido pelo
 * bloqueio de margem (plano 186-08), pela frase de tela e pelo congelamento. Duplicar a regra
 * deixaria as duas cópias livres para divergir, e o zero voltaria a desligar o bloqueio em um dos
 * caminhos. ⚠️ E a **origem** (`parametro_da_empresa` | `config`) é **gravada**: sem ela a
 * auditoria-depois não reconstrói a decisão, porque a config pode ter mudado.
 *
 * ⚠️ **`daEmpresa()` NÃO é repassado inteiro:** ele devolve `array_map('floatval', …)` de **todas**
 * as `PADROES`, inclusive o campo homônimo do outro nome (o que promete descontar despesa fixa e
 * devolução). ➡️ A chave usada é **SELECIONADA** aqui, nunca o array.
 *
 * ═══ ⚠️ DECISÃO (7) — A EXCEÇÃO DELIBERADA DA EMBALAGEM ════════════════════════════════════════
 *
 * A razão completa está no docblock de {@see ComposicaoDaOferta}. Em uma linha: sem a exceção o
 * painel diria "Incompleto" em **100%** das ofertas e **nada poderia ser aprovado**.
 *
 * ═══ ⚠️ DECISÃO (5) — PREÇO POR VARIANTE: MANDA O **PIOR CASO** ════════════════════════════════
 *
 * `pub_variante_precos` é unique (`variante_id`, `alvo_id`), então **um alvo pode ter vários
 * preços** (um por variante) enquanto a composição produz **um** resultado por oferta.
 * ➡️ **Manda o MENOR preço ativo do alvo.** Razão: **aprovar já publica e não há desfazer** — se a
 * variante mais barata dá prejuízo, a oferta tem de **bloquear**. "A primeira variante" deixaria
 * passar prejuízo numa cor; a média esconderia as duas pontas. ⚠️ E a tela **NOMEIA a variante**
 * ("pior caso entre N variantes"); com um preço só, **não há nota**.
 *
 * ═══ ⚠️ DESEMPENHO: O CACHE DE 1 h DA TARIFA ═══════════════════════════════════════════════════
 *
 * Chamada síncrona de ML na requisição é o que fez o dashboard deste projeto demorar **70 s**. A
 * tarifa usa o **mesmo cache** de `AnaliseAlavancasService` (`publicador.alavancas.cache.tarifa`),
 * com a chave por categoria + tipo + preço em centavos.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class ComposicaoFinanceiraService
{
    /** A linha informativa de FIN-08 — aparece e **nunca** entra na soma. */
    public const LINHA_FRETE_COMPRADOR = 'frete_comprador';

    public const ROTULO_FRETE_COMPRADOR = 'Frete pago pelo comprador';

    /**
     * ⚠️ A origem viva do custo da Precificação → a frase legível. `digitado` e `produto` são
     * números de gente ou do cadastro; `componentes` é a soma dos componentes do combo, todos eles
     * digitados. Origem fora destas, havendo valor, nasce `Estimado` e a nota diz isso.
     */
    public const ORIGEM_VIVA_DO_CUSTO = [
        'digitado' => 'custo digitado na Precificação',
        'produto' => 'custo do cadastro do produto',
        'componentes' => 'somado dos componentes do combo',
    ];

    public function __construct(
        private DadosEfetivosService $efetivos,
        private ClienteMlPublicador $cliente,
    ) {}

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // A MARGEM MÍNIMA — o resolvedor, em UM lugar só
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * O mínimo que vale para esta empresa **e de qual lado do fallback ele veio**.
     *
     * ⚠️ Ver o bloco (b) do docblock da classe: o fallback é DELIBERADO, e o **zero do parâmetro
     * NÃO desliga o bloqueio**.
     *
     * @return array{valor: float, origem: string}
     */
    public function margemMinimaComOrigem(?int $companyId): array
    {
        $daConfig = [
            'valor' => (float) config('publicador.aprovacao.margem_minima_percentual'),
            'origem' => PubAprovacaoComposicao::MINIMO_CONFIG,
        ];

        if ($companyId === null) {
            return $daConfig;
        }

        // ⚠️ SELECIONA a chave. O array inteiro traz o campo homônimo do Portal (ver o docblock).
        $doParametro = EstruturaPrecificacaoParametros::daEmpresa($companyId)['margem_contribuicao'] ?? null;

        if (is_numeric($doParametro) && (float) $doParametro > 0) {
            return ['valor' => (float) $doParametro, 'origem' => PubAprovacaoComposicao::MINIMO_PARAMETRO];
        }

        return $daConfig;
    }

    /** O mesmo resolvedor, quando quem chama só precisa do número. */
    public function margemMinimaDaEmpresa(?int $companyId): float
    {
        return $this->margemMinimaComOrigem($companyId)['valor'];
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // A COMPOSIÇÃO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * A composição desta oferta ao preço salvo — ou ao `$precoForcado` da simulação.
     *
     * ⚠️ Nada é gravado aqui. Quem grava é {@see self::congelar()}.
     */
    public function para(PubAprovacaoOferta $oferta, ?float $precoForcado = null): ComposicaoDaOferta
    {
        $produto = $oferta->produto;
        $alvo = $oferta->alvo;
        $listingType = (string) ($alvo->listing_type_id ?? '');
        $minimo = $this->margemMinimaComOrigem($oferta->company_id === null ? null : (int) $oferta->company_id);

        $doAlvo = $this->precoDoAlvo($oferta);
        $preco = $precoForcado ?? $doAlvo['preco'];

        // Sem preço não há composição nenhuma: toda linha percentual seria zero, e zero é número.
        if ($preco === null || $preco <= 0) {
            return ComposicaoDaOferta::montar(
                preco: 0.0,
                linhas: $this->linhasSemPreco($produto, $oferta, $listingType),
                comissaoPercentual: null, impostoPercentual: null,
                margemMinima: $minimo['valor'], margemMinimaOrigem: $minimo['origem'],
            );
        }

        $custos = $this->efetivos->custosDoProduto($produto);
        $tarifa = $this->tarifaDoMl($oferta, $listingType, $preco);

        $linhas = [
            $this->linhaDeCusto($produto, $custos),
            $this->linhaDeComissao($preco, $listingType, $tarifa),
            $this->linhaDeTarifaFixa($tarifa),
            $this->linhaDeFrete($produto, $oferta, $listingType),
            $this->linhaDeImposto($preco, $custos),
            $this->linhaDeEmbalagem(),
            $this->linhaDeOutros(),
            $this->linhaInformativaDoFreteDoComprador(),
        ];

        return ComposicaoDaOferta::montar(
            preco: $preco,
            linhas: $linhas,
            comissaoPercentual: $tarifa['percentual'],
            impostoPercentual: $custos['imposto'] === null ? null : (float) $custos['imposto'],
            margemMinima: $minimo['valor'],
            margemMinimaOrigem: $minimo['origem'],
            precoDeVariante: $doAlvo['variante'],
            precosAtivos: $doAlvo['ativos'],
        );
    }

    /**
     * FIN-11 / FIN-12 — a simulação de outro preço.
     *
     * ⚠️ **PURA: NADA é persistido.** Devolve a composição recalculada, a do preço salvo e a
     * diferença de resultado em relação a ele.
     *
     * @return array{preco_salvo: float, preco_simulado: float, salva: ComposicaoDaOferta, simulada: ComposicaoDaOferta, diferenca: ?float}
     */
    public function simular(PubAprovacaoOferta $oferta, float $precoNovo): array
    {
        $salva = $this->para($oferta);
        $simulada = $this->para($oferta, $precoNovo);

        return [
            'preco_salvo' => $salva->preco,
            'preco_simulado' => $simulada->preco,
            'salva' => $salva,
            'simulada' => $simulada,
            'diferenca' => $salva->resultado === null || $simulada->resultado === null
                ? null
                : round($simulada->resultado - $salva->resultado, 2),
        ];
    }

    /**
     * FIN-13 — a outra modalidade do mesmo alvo-par (Clássico ↔ Premium).
     *
     * ⚠️ A ligação é `rascunho_id`, o mesmo dos conjuntos de foto: o par é **estrutural**, não
     * combinado. Sem irmã devolve **`null`** — nunca zeros, que a tela leria como verdade.
     *
     * @return array{oferta_id: int, listing_type_id: ?string, preco: float, resultado: ?float, margem: ?float, incompleto: bool, andamento: ?string, rotulo_do_andamento: ?string}|null
     */
    public function comparacaoComAIrma(PubAprovacaoOferta $oferta): ?array
    {
        $irma = PubAprovacaoOferta::query()
            ->where('rascunho_id', $oferta->rascunho_id)
            ->where('id', '!=', $oferta->id)
            ->orderBy('id')
            ->first();

        if ($irma === null) {
            return null;
        }

        $composicao = $this->para($irma);

        return [
            'oferta_id' => (int) $irma->id,
            'listing_type_id' => $irma->alvo?->listing_type_id,
            'preco' => $composicao->preco,
            'resultado' => $composicao->resultado,
            'margem' => $composicao->margem,
            'incompleto' => $composicao->incompleto,
            'andamento' => $irma->andamento,
            'rotulo_do_andamento' => $irma->rotulo(),
        ];
    }

    /**
     * FIN-04 — "Quanto você recebe" pela MESMA conta do card do editor.
     *
     * ⚠️ É {@see SimuladorVoceRecebe} chamado, não recopiado: preço − tarifa do ML − frete do
     * vendedor. A diferença contra o `resultado` do painel é exatamente o que o card **não**
     * desconta (custo, imposto, embalagem e outros custos), e é por isso que os dois **não
     * discordam** — eles respondem perguntas diferentes com a mesma tarifa.
     *
     * @return array{preco: float, tarifa: float, frete: ?float, voce_recebe: float, percentual: float, frete_conhecido: bool}|null
     */
    public function voceRecebe(PubAprovacaoOferta $oferta, ?float $precoForcado = null): ?array
    {
        $composicao = $this->para($oferta, $precoForcado);
        $comissao = $composicao->linha('comissao');
        $fixa = $composicao->linha('tarifa_fixa');

        if ($composicao->preco <= 0 || $comissao === null || $comissao->valor === null || $fixa === null || $fixa->valor === null) {
            return null;
        }

        return SimuladorVoceRecebe::calcular(
            $composicao->preco,
            round($comissao->valor + $fixa->valor, 2),
            $composicao->linha('frete')?->valor,
        );
    }

    /**
     * Congela a composição no ATO (`aprovacao` ou `excecao`), com a margem mínima **da época** e de
     * qual lado do fallback ela veio.
     *
     * ⚠️ A auditoria-depois precisa do valor da época **e** da origem: a config pode ter mudado, e
     * sem a origem ninguém saberia se o mínimo veio do parâmetro ou do padrão (DADO-13 / T-186-29).
     */
    public function congelar(PubAprovacaoOferta $oferta, string $momento): PubAprovacaoComposicao
    {
        $composicao = $this->para($oferta);

        $linha = PubAprovacaoComposicao::updateOrCreate(
            ['oferta_id' => $oferta->id, 'versao' => (int) $oferta->versao, 'momento' => $momento],
            [
                'preco' => $composicao->preco,
                'linhas' => [
                    'linhas' => $composicao->linhasCongeladas(),
                    'resolucao' => [
                        // ⚠️ A origem do mínimo mora TAMBÉM aqui, junto das linhas: é este bloco
                        // que a auditoria lê para reconstruir a decisão do ato.
                        'margem_minima' => $composicao->margemMinima,
                        'margem_minima_origem' => $composicao->margemMinimaOrigem,
                        'equilibrio' => $composicao->equilibrio,
                        'preco_alvo' => $composicao->precoAlvo,
                        'soma_fixa' => $composicao->somaFixa,
                        'nota_da_margem' => $composicao->notaDaMargem,
                        'preco_de_variante' => $composicao->precoDeVariante,
                        'nota_do_preco' => $composicao->notaDoPreco,
                    ],
                ],
                'total' => $composicao->total,
                'resultado' => $composicao->resultado,
                'margem' => $composicao->margem,
                'incompleto' => $composicao->incompleto,
                'estimado_por_baixo' => $composicao->estimadoPorBaixo,
                'margem_minima' => $composicao->margemMinima,
                'margem_minima_origem' => $composicao->margemMinimaOrigem,
            ]
        );

        Log::info('[Publicador Aprovação] composição congelada da oferta '.$oferta->id
            .' ('.($oferta->alvo?->listing_type_id ?? 'sem alvo').') no momento '.$momento
            .' · mínimo '.$composicao->margemMinima.' de '.$composicao->margemMinimaOrigem);

        return $linha;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // O PREÇO — decisão (5): o PIOR CASO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * O preço que manda neste alvo: o **MENOR** preço ativo (o pior caso), quantos preços ativos
     * existem e qual variante é essa.
     *
     * Sem linha em `pub_variante_precos`, cai no preço da Precificação do Portal — o caso comum do
     * produto de uma variante só, e nele **não há nota**.
     *
     * @return array{preco: ?float, variante: ?string, ativos: int}
     */
    public function precoDoAlvo(PubAprovacaoOferta $oferta): array
    {
        $ativos = PubVariantePreco::query()
            ->join('pub_variantes', 'pub_variantes.id', '=', 'pub_variante_precos.variante_id')
            ->where('pub_variante_precos.alvo_id', $oferta->alvo_id)
            ->where('pub_variantes.ativa', true)
            ->whereNotNull('pub_variante_precos.preco')
            ->where('pub_variante_precos.preco', '>', 0)
            ->orderBy('pub_variante_precos.preco')
            ->orderBy('pub_variantes.posicao')
            ->get(['pub_variante_precos.preco as preco', 'pub_variantes.combinacao_chave as chave']);

        if ($ativos->isNotEmpty()) {
            $pior = $ativos->first();

            return [
                'preco' => round((float) $pior->preco, 2),
                'variante' => $ativos->count() > 1 ? (string) $pior->chave : null,
                'ativos' => $ativos->count(),
            ];
        }

        $daPrecificacao = $this->efetivos->daProduto($oferta->produto)['precos'][(string) $oferta->alvo?->listing_type_id] ?? null;

        return [
            'preco' => $daPrecificacao === null ? null : round((float) $daPrecificacao, 2),
            'variante' => null,
            'ativos' => $daPrecificacao === null ? 0 : 1,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // A TARIFA DO MERCADO LIVRE — o split
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * `sale_fee_details` do `/sites/MLB/listing_prices`, partido em **duas** linhas.
     *
     * ⚠️ Ver as CINCO armadilhas no docblock da classe. Em especial: `percentage_fee` é **ponto
     * percentual**, `gross_amount` **já é o total** (conferência, nunca parcela) e
     * **`listing_fee_details` é OUTRA COISA** — a tarifa de anúncio.
     *
     * @return array{percentual: ?float, fixa: ?float, bruto: ?float, informado: ?float, categoria: ?string, divergencia: ?string, nota: string}
     */
    private function tarifaDoMl(PubAprovacaoOferta $oferta, string $listingType, float $preco): array
    {
        $categoria = (string) ($oferta->rascunho?->categoria_id ?? '');
        $semFonte = fn (string $nota): array => [
            'percentual' => null, 'fixa' => null, 'bruto' => null, 'informado' => null,
            'categoria' => $categoria === '' ? null : $categoria, 'divergencia' => null, 'nota' => $nota,
        ];

        if ($categoria === '' || $listingType === '') {
            return $semFonte('O anúncio ainda não tem categoria nem tipo para consultar a tarifa no Mercado Livre.');
        }

        $chave = 'aprovacao:tarifa:'.$categoria.':'.$listingType.':'.(int) round($preco * 100);
        $segundos = (int) config('publicador.alavancas.cache.tarifa', 3600);

        $corpo = Cache::remember($chave, $segundos, function () use ($categoria, $listingType, $preco) {
            $resposta = $this->cliente->publico('/sites/MLB/listing_prices', [
                'price' => $preco, 'category_id' => $categoria,
                'listing_type_id' => $listingType, 'currency_id' => 'BRL',
            ]);

            return $resposta->ok() && is_array($resposta->corpo) ? $resposta->corpo : null;
        });

        if (! is_array($corpo)) {
            Log::warning("[Publicador Aprovação] tarifa indisponível na oferta {$oferta->id} ({$categoria}/{$listingType})");

            return $semFonte('O Mercado Livre não respondeu a tarifa deste anúncio.');
        }

        // O ML devolve um objeto na consulta por tipo e uma lista na documentação. Aceita os dois.
        if (array_is_list($corpo)) {
            $achado = collect($corpo)->first(fn ($e) => is_array($e) && ($e['listing_type_id'] ?? null) === $listingType)
                ?? collect($corpo)->first(fn ($e) => is_array($e));
            $corpo = is_array($achado) ? $achado : [];
        }

        // ⚠️⚠️ `sale_fee_details`, e SÓ. `listing_fee_details` tem os MESMOS nomes de campo e é a
        // tarifa de ANÚNCIO: ler o bloco errado dá zero SEMPRE, calado.
        $detalhe = $corpo['sale_fee_details'] ?? null;

        if (! is_array($detalhe) || ! isset($detalhe['percentage_fee']) || ! is_numeric($detalhe['percentage_fee'])) {
            Log::warning("[Publicador Aprovação] resposta sem sale_fee_details.percentage_fee na oferta {$oferta->id} ({$categoria}/{$listingType})");

            return $semFonte('O Mercado Livre respondeu sem o detalhamento da tarifa deste anúncio.');
        }

        // ⚠️ PONTO PERCENTUAL (`11`), não fração: dividir errado erra por 100×.
        $percentual = (float) $detalhe['percentage_fee'];
        $fixa = isset($detalhe['fixed_fee']) && is_numeric($detalhe['fixed_fee']) ? (float) $detalhe['fixed_fee'] : null;

        // ⚠️ CONFERÊNCIA, nunca terceira parcela: `gross_amount` JÁ é o total.
        $bruto = isset($detalhe['gross_amount']) && is_numeric($detalhe['gross_amount']) ? (float) $detalhe['gross_amount'] : null;
        $informado = isset($corpo['sale_fee_amount']) && is_numeric($corpo['sale_fee_amount']) ? (float) $corpo['sale_fee_amount'] : null;

        $divergencia = null;
        if ($fixa !== null) {
            $somado = round($preco * $percentual / 100 + $fixa, 2);
            foreach (['gross_amount' => $bruto, 'sale_fee_amount' => $informado] as $campo => $valor) {
                if ($valor !== null && abs($somado - round($valor, 2)) > 0.02) {
                    $divergencia = "o Mercado Livre respondeu {$campo} = ".round($valor, 2)." e as parcelas somam {$somado}";
                    Log::error("[Publicador Aprovação] tarifa divergente na oferta {$oferta->id} ({$categoria}/{$listingType}): {$divergencia}");
                    break;
                }
            }
        }

        return [
            'percentual' => $percentual, 'fixa' => $fixa, 'bruto' => $bruto, 'informado' => $informado,
            'categoria' => $categoria, 'divergencia' => $divergencia,
            'nota' => 'Mercado Livre · /sites/MLB/listing_prices · '.$categoria.' · '.$listingType,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // AS LINHAS
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /** @param  array{custo: ?float, origem: ?string, imposto: ?float, por_variante: array}  $custos */
    private function linhaDeCusto(PubProduto $produto, array $custos): LinhaFinanceira
    {
        $rotulo = PubAprovacaoComposicao::LINHAS['custo'];
        $unidades = max(1, (int) ($produto->quantidade_kit ?? 1));
        $unitario = $custos['custo'] === null ? null : (float) $custos['custo'];

        if ($unitario === null) {
            return LinhaFinanceira::pendente('custo', $rotulo, 'Sem custo na Precificação do Portal',
                'A Precificação do Portal está sem o custo deste produto.');
        }

        $total = round($unitario * $unidades, 2);

        // FIN-14 — a nota do combo é o literal "N un. × R$ x = R$ y".
        $detalhe = $unidades > 1
            ? $unidades.' un. × '.self::brl($unitario).' = '.self::brl($total)
            : '1 un. × '.self::brl($unitario);

        $viva = (string) ($custos['origem'] ?? '');
        $frase = self::ORIGEM_VIVA_DO_CUSTO[$viva] ?? null;
        $nota = 'Precificação do Portal: '.($frase ?? 'origem não registrada');

        return $frase === null
            ? LinhaFinanceira::estimada('custo', $rotulo, $detalhe, $total, $nota)
            : LinhaFinanceira::confirmada('custo', $rotulo, $detalhe, $total, $nota);
    }

    /** @param  array{percentual: ?float, nota: string, divergencia: ?string}  $tarifa */
    private function linhaDeComissao(float $preco, string $listingType, array $tarifa): LinhaFinanceira
    {
        $rotulo = PubAprovacaoComposicao::LINHAS['comissao'];

        if ($tarifa['percentual'] === null) {
            return LinhaFinanceira::pendente('comissao', $rotulo, 'Tarifa ainda não lida no Mercado Livre', $tarifa['nota']);
        }

        $nota = $tarifa['nota'].($tarifa['divergencia'] === null ? '' : ' · ⚠️ '.$tarifa['divergencia']);

        return LinhaFinanceira::confirmada('comissao', $rotulo,
            self::percentual((float) $tarifa['percentual']).' do preço · respondido pelo Mercado Livre',
            round($preco * (float) $tarifa['percentual'] / 100, 2), $nota);
    }

    /** @param  array{fixa: ?float, nota: string}  $tarifa */
    private function linhaDeTarifaFixa(array $tarifa): LinhaFinanceira
    {
        $rotulo = PubAprovacaoComposicao::LINHAS['tarifa_fixa'];

        if ($tarifa['fixa'] === null) {
            return LinhaFinanceira::pendente('tarifa_fixa', $rotulo, 'Tarifa ainda não lida no Mercado Livre', $tarifa['nota']);
        }

        // ⚠️ `0` é ZERO MEDIDO: `Confirmado` mostrando R$ 0,00, nunca `Pendente`.
        $detalhe = (float) $tarifa['fixa'] > 0 ? 'Cobrada neste preço' : 'Não se aplica a este preço';

        return LinhaFinanceira::confirmada('tarifa_fixa', $rotulo, $detalhe, (float) $tarifa['fixa'], $tarifa['nota']);
    }

    private function linhaDeFrete(PubProduto $produto, PubAprovacaoOferta $oferta, string $listingType): LinhaFinanceira
    {
        $vivo = $this->efetivos->fretesDoProduto($produto)['por_tipo'][$listingType]
            ?? ['valor' => null, 'origem' => null, 'oferta_id' => null];

        // ⚠️ LIDO de `PubAprovacaoComposicao`, nunca recopiado: o informado à mão VENCE (MOD-03) e
        // a tradução da origem viva mora lá.
        $montada = PubAprovacaoComposicao::linhaDeFrete($vivo, PubAprovacaoFrete::vigenteDe($oferta));

        $unidades = max(1, (int) ($produto->quantidade_kit ?? 1));
        $detalhe = match (true) {
            $montada['valor'] === null => 'Valor ainda não confirmado',
            $unidades > 1 => 'Kit em 1 volume',
            default => '1 volume',
        };

        return new LinhaFinanceira(
            chave: 'frete',
            rotulo: PubAprovacaoComposicao::LINHAS['frete'],
            detalhe: $detalhe,
            valor: $montada['valor'],
            fonteRespondeu: $montada['valor'] !== null,
            nota: $montada['nota'],
            estimada: $montada['origem'] === PubAprovacaoComposicao::ESTIMADO,
        );
    }

    /** @param  array{imposto: ?float}  $custos */
    private function linhaDeImposto(float $preco, array $custos): LinhaFinanceira
    {
        $rotulo = PubAprovacaoComposicao::LINHAS['imposto'];
        $pct = $custos['imposto'] === null ? null : (float) $custos['imposto'];

        if ($pct === null) {
            return LinhaFinanceira::pendente('imposto', $rotulo, 'Sem parâmetro fiscal desta empresa',
                'Os parâmetros de preço desta empresa não trazem o imposto.');
        }

        return LinhaFinanceira::confirmada('imposto', $rotulo, self::percentual($pct).' do preço',
            round($preco * $pct / 100, 2),
            'Parâmetros de preço da empresa no Portal (a exceção da oferta vence, quando existe)');
    }

    /**
     * ⚠️⚠️ A EXCEÇÃO DELIBERADA — decisão do usuário de 14/10/2026.
     *
     * **Não há fonte de custo de embalagem no repositório** (medido). A linha nasce `Pendente`
     * **VISÍVEL** e, por não estar entre as CINCO de
     * {@see PubAprovacaoComposicao::ANULAM_O_RESULTADO}, **não anula o resultado** — ela marca o
     * resultado como **estimado por baixo** (o custo real é maior, logo o resultado real é MENOR).
     *
     * ⚠️ Pela regra crua, 100% das ofertas sairiam "Incompleto" e **nada poderia ser aprovado**. É
     * a ÚNICA exceção; "uniformizar" para o `null` deixa a fase inerte.
     */
    private function linhaDeEmbalagem(): LinhaFinanceira
    {
        return LinhaFinanceira::pendente('embalagem', PubAprovacaoComposicao::LINHAS['embalagem'],
            'Sem fonte no sistema',
            'Nenhum cadastro do sistema informa o custo de embalagem — o resultado sai estimado por baixo.');
    }

    /**
     * ⚠️ Decisão do usuário de 14/10/2026: **R$ 0,00 `Confirmado`**, nota "Cadastro do produto".
     *
     * É o que o protótipo faz (`Aprovacao.dc.html:1070`: `other: {v: 0, src: 'conf', detail:
     * 'Nenhum informado', note: 'Cadastro do produto'}`) e cai na régua **0 MEDIDO ≠ ausente**: o
     * cadastro existe e **não lista** outros custos — é uma afirmação, não uma lacuna.
     */
    private function linhaDeOutros(): LinhaFinanceira
    {
        return LinhaFinanceira::confirmada('outros', PubAprovacaoComposicao::LINHAS['outros'],
            'Nenhum informado', 0.0, 'Cadastro do produto');
    }

    /** FIN-08 — aparece e **não** entra na soma. Não há fonte deste valor, e a nota diz isso. */
    private function linhaInformativaDoFreteDoComprador(): LinhaFinanceira
    {
        return LinhaFinanceira::informativa(self::LINHA_FRETE_COMPRADOR, self::ROTULO_FRETE_COMPRADOR,
            'Não entra na conta', null,
            'O Mercado Livre cobra este frete do comprador; o sistema não lê o valor e ele não entra no resultado.');
    }

    /** @return list<LinhaFinanceira> as 7 + a informativa, todas `Pendente`, quando não há preço */
    private function linhasSemPreco(PubProduto $produto, PubAprovacaoOferta $oferta, string $listingType): array
    {
        $custos = $this->efetivos->custosDoProduto($produto);
        $semPreco = 'Sem preço salvo neste anúncio';

        return [
            $this->linhaDeCusto($produto, $custos),
            LinhaFinanceira::pendente('comissao', PubAprovacaoComposicao::LINHAS['comissao'], $semPreco, 'A comissão depende do preço, e o anúncio ainda não tem preço.'),
            LinhaFinanceira::pendente('tarifa_fixa', PubAprovacaoComposicao::LINHAS['tarifa_fixa'], $semPreco, 'A tarifa fixa depende do preço, e o anúncio ainda não tem preço.'),
            $this->linhaDeFrete($produto, $oferta, $listingType),
            LinhaFinanceira::pendente('imposto', PubAprovacaoComposicao::LINHAS['imposto'], $semPreco, 'O imposto depende do preço, e o anúncio ainda não tem preço.'),
            $this->linhaDeEmbalagem(),
            $this->linhaDeOutros(),
            $this->linhaInformativaDoFreteDoComprador(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────

    private static function brl(float $n): string
    {
        return 'R$ '.number_format($n, 2, ',', '.');
    }

    private static function percentual(float $n): string
    {
        $casas = abs($n - round($n)) < 0.0005 ? 0 : 1;

        return number_format($n, $casas, ',', '.').'%';
    }
}
