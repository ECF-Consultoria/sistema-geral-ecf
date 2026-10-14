<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\EstruturaPrecificacaoParametros;
use App\Models\PubAprovacaoComposicao;
use App\Models\PubAprovacaoFrete;
use App\Models\PubAprovacaoOferta;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\PubVariante;
use App\Models\PubVariantePreco;
use App\Services\MercadoLivreService;
use App\Services\MlColetaService;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Publicador\Aprovacao\ComposicaoDaOferta;
use App\Services\Publicador\Aprovacao\ComposicaoFinanceiraService;
use App\Services\Publicador\Aprovacao\LinhaFinanceira;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\DadosEfetivosService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 186, plano 07 — A COMPOSIÇÃO FINANCEIRA contra o `fin()` do protótipo.
 *
 * ⚠️⚠️ **O PROTÓTIPO GANHA NA FÓRMULA, NOS RÓTULOS E NA ORDEM — NUNCA NOS NÚMEROS.**
 *
 * Correção de 14/10/2026 ao critério 7 do ROADMAP (commit `2bea797e`): a redação anterior
 * (*"a composição fecha com `fin()` nos 10 estados"*) **contradizia o critério 6 e era
 * ARITMETICAMENTE IMPOSSÍVEL**. O próprio protótipo rotula as taxas dele como **ficção**
 * (`Aprovacao.dc.html:1064` *"taxa de exemplo"*, `:1066` *"exemplo"*, `src: 'est'`), e com as
 * fontes reais o exemplo `o1` sai de **+R$ 70,82 / 14,46%** para **+R$ 12,03 / 2,46%** — ele
 * **inverte de aprovável para bloqueado**. Obedecer os números exigiria hardcodear
 * `0.12`/`0.17`/`0.06`/`6.75`, que o critério 6 **proíbe**: os dois gates seriam **mutuamente
 * exclusivos** e o conserto barato seria enfraquecer um.
 *
 * ➡️ **O que este arquivo afirma, então:** as **7 linhas na ORDEM**, os **rótulos**, a **origem por
 * linha**, a **propagação de `null`**, a composição de `somaFixa`, `vr = comissão% + imposto%`,
 * `equilibrio = c2(somaFixa/(1−vr))` e `precoAlvo = c2(somaFixa/(1−vr−mínimo%))` — com as taxas
 * **injetadas das fontes reais** (fixture do ML + parâmetros da empresa) e os esperados calculados
 * **pela MESMA fórmula**, lida do `.dc.html`.
 *
 * ⚠️⚠️ **E a seção F do README do desenho é PROSA: só `info()` decide bloqueio.** O `o3` que ela
 * chama de *"margem baixa"* dá **PREJUÍZO** pela cadeia exclusiva com as fontes reais. **Os
 * esperados NÃO saem das duas fontes:** os bloqueios são do plano 186-08, e a composição aqui só
 * afirma estrutura e invariantes.
 *
 * ⚠️ `design_handoff_publicador/` **não é versionada**. Sem o arquivo no disco, cada estado degrada
 * para **`skip` EXPLÍCITO com a razão** — nunca para "conferido" (GATE-10).
 *
 * ═══ ⚠️⚠️ DE CADA GATE, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR ═════════════════════════════
 *
 *   · **o split da tarifa** — alguém somar `gross_amount` **junto** das parcelas. Ele JÁ é o total,
 *     e a tarifa sairia **DOBRADA** (33,00 em vez de 16,50 na fixture real), calada.
 *   · **`listing_fee_details`** — alguém ler o bloco de nome parecido. É a tarifa de **ANÚNCIO**, e
 *     nas fixtures **os dois são zero**: um teste frouxo passaria nos dois casos. Este gate põe
 *     **999** nele e exige que a composição **ignore**.
 *   · **`percentage_fee` como fração** — alguém dividir por 100 duas vezes (ou nenhuma). Erra a
 *     composição por **100×**: 0,165 em vez de 16,50.
 *   · **`percentage_fee` como constante** — alguém hardcodear. Medi **11, 12, 14, 15 e 17** nas
 *     fixtures reais: o hardcode acertaria em algumas categorias e **erraria calado** nas outras.
 *   · **`fixed_fee: 0` virando `Pendente`** — travaria a composição de **16 das 17** respostas
 *     medidas, porque zero ali é **zero MEDIDO**.
 *   · **o fallback da margem mínima** — alguém "simplificar" para um dos dois lados.
 *     **Só-parâmetro** desliga o bloqueio em toda empresa que nunca salvou a Calculadora
 *     (`margem_contribuicao` default é **0.0**); **só-config** ignora quem configurou a própria
 *     exigência. **As duas falham em silêncio.**
 *   · **a simulação persistindo** — alguém gravar o preço digitado na revisão. Com
 *     aprovar-já-publica, um preço de teste iria ao ar.
 *   · **o preço por variante** — alguém pegar "a primeira". A variante mais barata pode dar
 *     prejuízo, e aprovar **já publica, sem desfazer**.
 *   · **o array dos parâmetros repassado inteiro** — `daEmpresa()` traz o campo homônimo do outro
 *     nome (VOC-03), e ele vazaria para dentro de `linhas` e daí para a tela.
 *   · **rede em teste** — `Http::preventStrayRequests()` faz qualquer chamada não fingida
 *     **explodir**, em vez de sair para a internet durante a suíte.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class ComposicaoContraOPrototipoTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    /** ⚠️ NÃO é versionada: ausente, cada estado dá `skip` EXPLÍCITO. */
    private const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

    /** As 7 chaves do `fin()` do protótipo → as 7 deste sistema. */
    private const DO_PROTOTIPO = [
        'cost' => 'custo',
        'comm' => 'comissao',
        'fixed' => 'tarifa_fixa',
        'freight' => 'frete',
        'tax' => 'imposto',
        'pack' => 'embalagem',
        'other' => 'outros',
    ];

    /** `"{categoria}|{listing_type}"` → corpo da resposta do `/sites/MLB/listing_prices`. */
    private array $tarifas = [];

    /** @var list<array{caminho: string, query: array}> */
    private array $chamadas = [];

    private ?DadosEfetivosService $efetivos = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00:00');

        // ⚠️ ZERO REDE: qualquer chamada não fingida explode, em vez de sair para a internet.
        Http::preventStrayRequests();
        Cache::put('ml_app_token_coleta', 'fake-app-token', 3600);

        Http::fake(function (Request $req) {
            $caminho = (string) parse_url($req->url(), PHP_URL_PATH);
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $query);
            $this->chamadas[] = ['caminho' => $caminho, 'query' => $query];

            if ($caminho === '/sites/MLB/listing_prices') {
                $chave = ($query['category_id'] ?? '').'|'.($query['listing_type_id'] ?? '');
                $corpo = $this->tarifas[$chave] ?? null;
                if ($corpo === null) {
                    return Http::response(['message' => 'sem stub de tarifa para '.$chave], 404);
                }
                if (isset($corpo['__status'])) {
                    return Http::response(['message' => 'erro de proposito'], (int) $corpo['__status']);
                }

                return Http::response($corpo, 200);
            }

            return Http::response(['message' => 'sem stub no teste'], 404);
        });

        $this->app->instance(ClienteMlPublicador::class, new ClienteMlPublicador(
            app(MercadoLivreService::class), app(MlColetaService::class), function (int $s) {}
        ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // O CENÁRIO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /** A resposta REAL do ML gravada em `tests/fixtures-ml/` (ou a sintética declarada). */
    private function fixtureListingPrices(string $categoria, string $listingType, string $sufixo = ''): array
    {
        $arquivo = base_path("tests/fixtures-ml/sondagem/publico/categorias/{$categoria}/listing_prices_{$listingType}{$sufixo}.json");
        $this->assertFileExists($arquivo, 'fixture de tarifa ausente — o gate rodaria sobre zero casos');

        return json_decode(file_get_contents($arquivo), true)['resposta'];
    }

    /**
     * Monta a oferta e troca `DadosEfetivosService` por uma subclasse que devolve o que o teste
     * declara. ⚠️ O serviço real é **SOMENTE LEITURA** nesta fase: ele é CHAMADO, nunca reescrito.
     *
     * @param  array{listing_type?: string, categoria?: string, unidades?: int, preco?: ?float, custo?: ?float, origem_custo?: ?string, imposto?: ?float, frete?: ?float, origem_frete?: ?string, company?: ?Company, rascunho?: ?PubRascunho}  $o
     */
    private function cenario(array $o = []): PubAprovacaoOferta
    {
        $listingType = $o['listing_type'] ?? self::CLASSICO;
        $categoria = $o['categoria'] ?? 'MLB189007';
        $empresa = $o['company'] ?? Company::factory()->create();

        $produto = PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => 'CMP-'.PubProduto::count().'-'.$listingType,
            'nome' => 'Poltrona da composição',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'quantidade_kit' => $o['unidades'] ?? 1,
        ]);
        $rascunho = $o['rascunho'] ?? PubRascunho::create(['produto_id' => $produto->id, 'categoria_id' => $categoria]);
        $alvo = PubRascunhoAlvo::create(['rascunho_id' => $rascunho->id, 'listing_type_id' => $listingType, 'posicao' => 0]);

        $oferta = PubAprovacaoOferta::create([
            'alvo_id' => $alvo->id,
            'rascunho_id' => $rascunho->id,
            'produto_id' => $produto->id,
            'company_id' => $empresa->id,
        ]);

        $this->efetivos = new class(app(EstruturaPrecificacaoService::class)) extends DadosEfetivosService
        {
            public array $osCustos = [];

            public array $osFretes = [];

            public array $osPrecos = [];

            public function custosDoProduto(PubProduto $produto): array
            {
                return $this->osCustos;
            }

            public function fretesDoProduto(PubProduto $produto): array
            {
                return $this->osFretes;
            }

            public function daProduto(PubProduto $produto): array
            {
                return $this->osPrecos;
            }
        };

        $this->efetivos->osCustos = [
            // ⚠️ `array_key_exists`, NUNCA `??`: `['custo' => null]` significa "a Precificação está
            // sem custo", e o `??` o devolveria ao default — o teste do custo ausente passaria
            // medindo o cenário COM custo. Mesma armadilha para `imposto` e `frete`.
            'custo' => array_key_exists('custo', $o) ? $o['custo'] : 200.0,
            'origem' => $o['origem_custo'] ?? 'digitado',
            // ⚠️ O imposto REAL é 19,0 (`EstruturaPrecificacaoParametros::PADROES`), NÃO os 6% do
            // README do desenho. Nenhum caso deste arquivo usa 6% como "o padrão".
            'imposto' => array_key_exists('imposto', $o) ? $o['imposto'] : EstruturaPrecificacaoParametros::PADROES['imposto'],
            'por_variante' => [],
        ];
        $this->efetivos->osFretes = ['por_tipo' => [
            $listingType => [
                'valor' => array_key_exists('frete', $o) ? $o['frete'] : 42.9,
                'origem' => array_key_exists('frete', $o) && $o['frete'] === null ? null : ($o['origem_frete'] ?? 'tabela'),
                'oferta_id' => null,
            ],
        ], 'por_variante' => []];
        $this->efetivos->osPrecos = ['precos' => [$listingType => $o['preco'] ?? 489.90]];

        $this->app->instance(DadosEfetivosService::class, $this->efetivos);

        return $oferta->fresh();
    }

    private function servico(): ComposicaoFinanceiraService
    {
        return new ComposicaoFinanceiraService(
            $this->efetivos ?? app(DadosEfetivosService::class),
            app(ClienteMlPublicador::class),
        );
    }

    /**
     * ⚠️ O cenário monta o dublê de `DadosEfetivosService` e **só então** o serviço pode ser
     * construído. Escrever `$this->servico()->para($this->cenario([…]))` numa linha só dava
     * **falso negativo silencioso**: o PHP avalia o objeto receptor ANTES do argumento, e o
     * serviço nascia com o `DadosEfetivosService` REAL — custo nulo, composição incompleta e um
     * gate verde por acidente. Este atalho existe para que a ordem não seja uma escolha.
     */
    private function composicaoDe(array $o): ComposicaoDaOferta
    {
        $oferta = $this->cenario($o);

        return $this->servico()->para($oferta);
    }

    private function comTarifa(string $categoria, string $listingType, array $corpo): void
    {
        $this->tarifas[$categoria.'|'.$listingType] = $corpo;
    }

    private static function teto(float $n): float
    {
        return ceil($n * 100 - 1e-6) / 100;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️ O SPLIT DA TARIFA — `sale_fee_details`, e só ele
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_o_split_sao_duas_linhas_e_o_gross_amount_nao_entra_como_terceira_parcela(): void
    {
        // Fixture REAL de 2026-10-01: price 150, percentage_fee 11, fixed_fee 0, gross_amount 16.5.
        $corpo = $this->fixtureListingPrices('MLB189007', self::CLASSICO);
        $this->assertSame(11, $corpo['sale_fee_details']['percentage_fee'], 'a fixture real mudou — o gate perderia a âncora');
        $this->assertSame(0, $corpo['sale_fee_details']['fixed_fee']);
        $this->assertSame(16.5, $corpo['sale_fee_details']['gross_amount']);
        $this->assertSame(16.5, $corpo['sale_fee_amount']);

        $this->comTarifa('MLB189007', self::CLASSICO, $corpo);
        $oferta = $this->cenario(['preco' => 150.0]);
        $c = $this->servico()->para($oferta);

        $comissao = $c->linha('comissao');
        $fixa = $c->linha('tarifa_fixa');

        // ⚠️ DUAS LINHAS SEPARADAS, as duas `Confirmado`.
        $this->assertSame(16.5, $comissao->valor, 'comissão = preço × percentage_fee/100');
        $this->assertSame(PubAprovacaoComposicao::CONFIRMADO, $comissao->origem);
        $this->assertSame(0.0, $fixa->valor, '`fixed_fee: 0` é ZERO MEDIDO, mostrado como R$ 0,00');
        $this->assertSame(PubAprovacaoComposicao::CONFIRMADO, $fixa->origem);
        $this->assertFalse($fixa->ehPendente(), '`fixed_fee: 0` NUNCA vira Pendente');

        // ⚠️ A IDENTIDADE DE CONFERÊNCIA: `comm + fixed == gross_amount == sale_fee_amount`.
        $this->assertSame(16.5, round($comissao->valor + $fixa->valor, 2));
        $this->assertSame($corpo['sale_fee_details']['gross_amount'], round($comissao->valor + $fixa->valor, 2));
        $this->assertSame((float) $corpo['sale_fee_amount'], round($comissao->valor + $fixa->valor, 2));

        // ⚠️⚠️ E `gross_amount` **NÃO** é somado junto: a tarifa total é 16,50, não 33,00.
        $this->assertNotSame(33.0, round($comissao->valor + $fixa->valor, 2), 'gross_amount entrou como TERCEIRA parcela e dobrou a tarifa');

        // A nota NOMEIA a fonte, com a categoria e o tipo.
        $this->assertStringContainsString('/sites/MLB/listing_prices', $comissao->nota);
        $this->assertStringContainsString('MLB189007', $comissao->nota);
        $this->assertStringContainsString(self::CLASSICO, $comissao->nota);
        $this->assertSame($comissao->nota, $fixa->nota, 'as duas linhas vêm da MESMA resposta e nomeiam a mesma fonte');
    }

    public function test_fixed_fee_diferente_de_zero_tem_as_duas_linhas_com_valor(): void
    {
        // Fixture SINTÉTICA criada neste plano, com a origem declarada DENTRO do arquivo: não há
        // captura real do Brasil com `fixed_fee != 0` (a única é documentação ARGENTINA).
        $arquivo = base_path('tests/fixtures-ml/sondagem/publico/categorias/MLB189007/listing_prices_gold_special_preco_baixo.json');
        $this->assertFileExists($arquivo);
        $bruto = json_decode(file_get_contents($arquivo), true);
        $this->assertSame('sintetica-fase-186-plano-07', $bruto['origem'], 'a fixture tem de DECLARAR que é sintética');
        $corpo = $bruto['resposta'];

        $this->comTarifa('MLB189007', self::CLASSICO, $corpo);
        $oferta = $this->cenario(['preco' => 60.0]);
        $c = $this->servico()->para($oferta);

        $this->assertSame(6.6, $c->linha('comissao')->valor, '60 × 11/100');
        $this->assertSame(5.0, $c->linha('tarifa_fixa')->valor, '`fixed_fee` != 0 aparece com valor');
        $this->assertSame(PubAprovacaoComposicao::CONFIRMADO, $c->linha('tarifa_fixa')->origem);
        $this->assertSame('Cobrada neste preço', $c->linha('tarifa_fixa')->detalhe);

        // A identidade, no SEGUNDO regime.
        $this->assertSame(11.6, round($c->linha('comissao')->valor + $c->linha('tarifa_fixa')->valor, 2));
        $this->assertSame((float) $corpo['sale_fee_details']['gross_amount'], round($c->linha('comissao')->valor + $c->linha('tarifa_fixa')->valor, 2));
        $this->assertSame((float) $corpo['sale_fee_amount'], round($c->linha('comissao')->valor + $c->linha('tarifa_fixa')->valor, 2));
    }

    public function test_percentage_fee_e_ponto_percentual_e_nao_fracao(): void
    {
        $this->comTarifa('MLB189007', self::CLASSICO, $this->fixtureListingPrices('MLB189007', self::CLASSICO));
        $c = $this->composicaoDe(['preco' => 150.0]);

        // ⚠️ Os DOIS dígitos travados: 16,50, não 0,165 (e não 1650,00).
        $this->assertSame(16.5, $c->linha('comissao')->valor);
        $this->assertNotSame(0.17, $c->linha('comissao')->valor);
        $this->assertNotSame(1650.0, $c->linha('comissao')->valor);
        $this->assertStringContainsString('11% do preço', $c->linha('comissao')->detalhe);
    }

    public function test_percentage_fee_variavel_por_categoria_o_servico_usa_o_que_chegou(): void
    {
        // Duas categorias REAIS com percentuais REAIS diferentes no MESMO tipo de anúncio.
        $daPrimeira = $this->fixtureListingPrices('MLB189007', self::CLASSICO);
        $daSegunda = $this->fixtureListingPrices('MLB31447', self::CLASSICO);
        $this->assertSame(11, $daPrimeira['sale_fee_details']['percentage_fee']);
        $this->assertSame(12, $daSegunda['sale_fee_details']['percentage_fee']);
        $this->assertNotSame($daPrimeira['sale_fee_details']['percentage_fee'], $daSegunda['sale_fee_details']['percentage_fee'],
            'ISCA CEGA: as duas fixtures têm o mesmo percentual — o gate não provaria variação nenhuma');

        $this->comTarifa('MLB189007', self::CLASSICO, $daPrimeira);
        $this->comTarifa('MLB31447', self::CLASSICO, $daSegunda);

        $uma = $this->composicaoDe(['preco' => 100.0, 'categoria' => 'MLB189007']);
        $outra = $this->composicaoDe(['preco' => 100.0, 'categoria' => 'MLB31447']);

        $this->assertSame(11.0, $uma->linha('comissao')->valor);
        $this->assertSame(12.0, $outra->linha('comissao')->valor);
        $this->assertNotSame($uma->linha('comissao')->valor, $outra->linha('comissao')->valor,
            'o serviço usa o percentual que CHEGOU, nunca um valor fixo');
    }

    public function test_listing_fee_details_e_ignorado_mesmo_valendo_999(): void
    {
        // ⚠️⚠️ A armadilha 5: o MESMO corpo traz `listing_fee_details` com os MESMOS nomes de
        // campo. Nas fixtures reais os DOIS são zero, então um teste frouxo passaria nos dois
        // casos. Aqui o bloco errado vale 999 e a composição tem de IGNORAR.
        $corpo = $this->fixtureListingPrices('MLB189007', self::CLASSICO);
        $corpo['listing_fee_amount'] = 999;
        $corpo['listing_fee_details'] = ['fixed_fee' => 999, 'gross_amount' => 999, 'percentage_fee' => 999];

        $this->comTarifa('MLB189007', self::CLASSICO, $corpo);
        $c = $this->composicaoDe(['preco' => 150.0]);

        $this->assertSame(16.5, $c->linha('comissao')->valor, 'a comissão saiu do bloco de ANÚNCIO em vez do de VENDA');
        $this->assertSame(0.0, $c->linha('tarifa_fixa')->valor, 'a tarifa fixa saiu do bloco de ANÚNCIO');
        $this->assertNotSame(999.0, $c->linha('tarifa_fixa')->valor);
        $this->assertNotSame(999.0, $c->linha('comissao')->valor);
        $this->assertStringNotContainsString('999', (string) $c->total);

        // ISCA DA SONDA: o 999 ESTAVA no corpo entregue ao serviço — senão este gate não mediu nada.
        $this->assertSame(999, $this->tarifas['MLB189007|'.self::CLASSICO]['listing_fee_details']['gross_amount'],
            'ISCA CEGA: o 999 não chegou ao stub — o gate rodou sobre o corpo limpo.');
    }

    public function test_resposta_sem_sale_fee_details_deixa_as_duas_linhas_pendentes_e_a_composicao_incompleta(): void
    {
        // ⚠️ RAMO DE ERRO DECLARADO, não o esperado. NUNCA cair em percentual fixo, e NUNCA
        // colapsar em "uma linha só" como comportamento normal.
        $corpo = $this->fixtureListingPrices('MLB189007', self::CLASSICO);
        unset($corpo['sale_fee_details']);

        $this->comTarifa('MLB189007', self::CLASSICO, $corpo);
        $c = $this->composicaoDe(['preco' => 150.0]);

        $this->assertTrue($c->linha('comissao')->ehPendente());
        $this->assertTrue($c->linha('tarifa_fixa')->ehPendente());
        $this->assertCount(7, $c->linhasDaConta(), 'as 7 linhas continuam existindo — nada colapsa em uma só');
        $this->assertTrue($c->incompleto);
        $this->assertNull($c->resultado);
        $this->assertNull($c->equilibrio);
        $this->assertStringContainsString('sem o detalhamento da tarifa', $c->linha('comissao')->nota);

        // O MESMO corpo sem `percentage_fee` (e COM o bloco) também é Pendente.
        $semPercentual = $this->fixtureListingPrices('MLB193945', self::CLASSICO);
        unset($semPercentual['sale_fee_details']['percentage_fee']);
        $this->comTarifa('MLB193945', self::CLASSICO, $semPercentual);
        $outra = $this->composicaoDe(['preco' => 150.0, 'categoria' => 'MLB193945']);
        $this->assertTrue($outra->linha('comissao')->ehPendente());
        $this->assertTrue($outra->incompleto);

        // E a API fora do ar: `Pendente`, nunca percentual fixo (T-186-27).
        $this->comTarifa('MLB47097', self::CLASSICO, ['__status' => 500]);
        $fora = $this->composicaoDe(['preco' => 150.0, 'categoria' => 'MLB47097']);
        $this->assertTrue($fora->linha('comissao')->ehPendente());
        $this->assertNull($fora->linha('comissao')->valor, 'API fora do ar NÃO pode virar número inventado');
        $this->assertTrue($fora->incompleto);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // AS 7 LINHAS, A ORIGEM E A NOTA QUE NOMEIA A FONTE
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_as_sete_linhas_vem_das_fontes_reais_com_origem_e_nota_que_nomeia_cada_uma(): void
    {
        $this->comTarifa('MLB189007', self::CLASSICO, $this->fixtureListingPrices('MLB189007', self::CLASSICO));
        $c = $this->composicaoDe(['preco' => 489.90]);

        $this->assertSame(['custo', 'comissao', 'tarifa_fixa', 'frete', 'imposto', 'embalagem', 'outros'],
            array_map(fn (LinhaFinanceira $l) => $l->chave, $c->linhasDaConta()));

        // Cada linha NOMEIA a fonte — nota genérica prova nada (DADO-13 / VOC-04).
        $esperado = [
            'custo' => ['Precificação do Portal', PubAprovacaoComposicao::CONFIRMADO],
            'comissao' => ['/sites/MLB/listing_prices', PubAprovacaoComposicao::CONFIRMADO],
            'tarifa_fixa' => ['/sites/MLB/listing_prices', PubAprovacaoComposicao::CONFIRMADO],
            'frete' => ['Precificação do Portal', PubAprovacaoComposicao::ESTIMADO],
            'imposto' => ['Parâmetros de preço da empresa', PubAprovacaoComposicao::CONFIRMADO],
            'embalagem' => ['custo de embalagem', PubAprovacaoComposicao::PENDENTE],
            'outros' => ['Cadastro do produto', PubAprovacaoComposicao::CONFIRMADO],
        ];
        foreach ($esperado as $chave => [$fonte, $origem]) {
            $linha = $c->linha($chave);
            $this->assertNotNull($linha, "falta a linha `{$chave}`");
            $this->assertStringContainsString($fonte, $linha->nota, "a nota de `{$chave}` não nomeia a fonte");
            $this->assertSame($origem, $linha->origem, "a origem de `{$chave}` não é a medida");
            $this->assertNotSame('', trim($linha->detalhe), "o detalhe de `{$chave}` está vazio");
        }

        // O imposto é o REAL (19,0), e o teste DECLARA o valor e a origem.
        $this->assertSame(19.0, EstruturaPrecificacaoParametros::PADROES['imposto'],
            'o imposto padrão deste sistema é 19,0 — nenhum caso usa os 6% do README do desenho');
        $this->assertSame(round(489.90 * 19.0 / 100, 2), $c->linha('imposto')->valor);
        $this->assertStringContainsString('19% do preço', $c->linha('imposto')->detalhe);

        // A 8ª linha, informativa (FIN-08).
        $this->assertCount(1, $c->linhasInformativas());
        $this->assertSame(ComposicaoFinanceiraService::LINHA_FRETE_COMPRADOR, $c->linhasInformativas()[0]->chave);
    }

    public function test_combo_multiplica_o_custo_pelas_unidades_com_a_nota_literal_de_fin_14(): void
    {
        $this->comTarifa('MLB189007', self::CLASSICO, $this->fixtureListingPrices('MLB189007', self::CLASSICO));
        $kit = $this->composicaoDe(['preco' => 749.90, 'unidades' => 2, 'custo' => 200.0]);
        $uma = $this->composicaoDe(['preco' => 749.90, 'unidades' => 1, 'custo' => 200.0]);

        $this->assertSame(400.0, $kit->linha('custo')->valor, '2 un. × R$ 200,00');
        $this->assertSame('2 un. × R$ 200,00 = R$ 400,00', $kit->linha('custo')->detalhe, 'FIN-14: a nota do combo é o literal');
        $this->assertSame(200.0, $uma->linha('custo')->valor);
        $this->assertSame('1 un. × R$ 200,00', $uma->linha('custo')->detalhe);
        $this->assertSame('Kit em 1 volume', $kit->linha('frete')->detalhe);
    }

    public function test_o_frete_informado_a_mao_vence_e_a_composicao_o_usa(): void
    {
        $this->comTarifa('MLB189007', self::CLASSICO, $this->fixtureListingPrices('MLB189007', self::CLASSICO));
        $oferta = $this->cenario(['preco' => 489.90, 'frete' => 42.9]);

        $semInforme = $this->servico()->para($oferta);
        $this->assertSame(42.9, $semInforme->linha('frete')->valor);
        $this->assertSame(PubAprovacaoComposicao::ESTIMADO, $semInforme->linha('frete')->origem);

        PubAprovacaoFrete::informar($oferta, 68.5, PubAprovacaoFrete::TABELA_CLIENTE);
        $comInforme = $this->servico()->para($oferta->fresh());

        $this->assertSame(68.5, $comInforme->linha('frete')->valor, 'o informado à mão VENCE a Precificação (MOD-03)');
        $this->assertSame(PubAprovacaoComposicao::CONFIRMADO, $comInforme->linha('frete')->origem);
        $this->assertStringContainsString('Tabela do cliente', $comInforme->linha('frete')->nota);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️ A EXCEÇÃO DA EMBALAGEM **E** A REGRA DO FRETE, no mesmo arquivo
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_embalagem_sem_fonte_nao_anula_mas_frete_ausente_anula_no_mesmo_cenario(): void
    {
        $this->comTarifa('MLB189007', self::CLASSICO, $this->fixtureListingPrices('MLB189007', self::CLASSICO));

        // ⚠️ A EXCEÇÃO (decisão do usuário, 14/10/2026): não há fonte de embalagem no sistema, e a
        // oferta COMPLETA no resto sai COM resultado, marcada estimada por baixo.
        $comEmbalagemPendente = $this->composicaoDe(['preco' => 489.90]);
        $this->assertTrue($comEmbalagemPendente->linha('embalagem')->ehPendente());
        $this->assertFalse($comEmbalagemPendente->incompleto, 'embalagem sem fonte NÃO anula o resultado');
        $this->assertNotNull($comEmbalagemPendente->resultado);
        $this->assertTrue($comEmbalagemPendente->estimadoPorBaixo);
        $this->assertNotNull($comEmbalagemPendente->equilibrio, 'o atalho do preço-alvo ficaria SEMPRE vazio');
        $this->assertNotNull($comEmbalagemPendente->precoAlvo);
        $this->assertStringContainsString('sem o custo de embalagem', $comEmbalagemPendente->notaDaMargem);

        // ⚠️ A REGRA, no MESMO teste: `frete` ausente ANULA tudo. É a diferença entre a exceção e a
        // regra que alguém vai querer uniformizar.
        $semFrete = $this->composicaoDe(['preco' => 489.90, 'frete' => null]);
        $this->assertTrue($semFrete->incompleto);
        $this->assertNull($semFrete->resultado);
        $this->assertNull($semFrete->total);
        $this->assertNull($semFrete->equilibrio);
        $this->assertNull($semFrete->precoAlvo);
        $this->assertSame('dados incompletos', $semFrete->notaDaMargem);

        // E o custo ausente também ANULA (é uma das CINCO).
        $semCusto = $this->composicaoDe(['preco' => 489.90, 'custo' => null]);
        $this->assertTrue($semCusto->incompleto);
        $this->assertNull($semCusto->resultado);
        $this->assertTrue($semCusto->linha('custo')->ehPendente());
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️ A MARGEM MÍNIMA — os QUATRO casos, cada um OBSERVÁVEL
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_os_quatro_casos_da_margem_minima_mudam_o_preco_alvo_e_a_nota_de_forma_observavel(): void
    {
        config(['publicador.aprovacao.margem_minima_percentual' => 12.0]);
        $this->comTarifa('MLB189007', self::CLASSICO, $this->fixtureListingPrices('MLB189007', self::CLASSICO));

        // ⚠️ O default do modelo é 0.0 — é ELE que tornaria "só-parâmetro" um bug silencioso.
        $this->assertSame(0.0, EstruturaPrecificacaoParametros::PADROES['margem_contribuicao']);

        $casos = [
            'parametro maior que zero' => [15.0, PubAprovacaoComposicao::MINIMO_PARAMETRO, 15.0],
            'parametro igual a zero' => [0.0, PubAprovacaoComposicao::MINIMO_CONFIG, 12.0],
            'parametro negativo' => [-5.0, PubAprovacaoComposicao::MINIMO_CONFIG, 12.0],
            'empresa sem linha' => [null, PubAprovacaoComposicao::MINIMO_CONFIG, 12.0],
        ];

        $vistos = [];
        foreach ($casos as $nome => [$parametro, $origemEsperada, $minimoEsperado]) {
            $empresa = Company::factory()->create();
            if ($parametro !== null) {
                EstruturaPrecificacaoParametros::create(['company_id' => $empresa->id, 'margem_contribuicao' => $parametro]);
            }

            $c = $this->composicaoDe(['preco' => 489.90, 'company' => $empresa]);

            $this->assertSame($minimoEsperado, $c->margemMinima, "caso `{$nome}`: o mínimo resolvido não é o esperado");
            $this->assertSame($origemEsperada, $c->margemMinimaOrigem, "caso `{$nome}`: o lado do fallback não é o esperado");
            // ⚠️ OBSERVÁVEL: o mínimo muda o preço-alvo e a frase.
            $this->assertStringContainsString('mínimo de '.(int) $minimoEsperado.'%', $c->notaDaMargem, "caso `{$nome}`: a frase não carrega o mínimo resolvido");
            $vistos[$nome] = $c->precoAlvo;
        }

        // ⚠️ O ZERO NÃO DESLIGA O BLOQUEIO: ele cai na config, e o preço-alvo do caso do zero é o
        // MESMO dos outros dois que caem na config — e DIFERENTE do caso do parâmetro.
        $this->assertSame($vistos['parametro igual a zero'], $vistos['empresa sem linha']);
        $this->assertSame($vistos['parametro igual a zero'], $vistos['parametro negativo']);
        $this->assertNotSame($vistos['parametro maior que zero'], $vistos['parametro igual a zero'],
            'o parâmetro da empresa não está mudando nada — o resolvedor não está sendo usado');
        $this->assertGreaterThan($vistos['parametro igual a zero'], $vistos['parametro maior que zero']);
        $this->assertNotNull($vistos['parametro igual a zero'], 'o zero NÃO pode virar "sem mínimo" (preço-alvo nulo)');
    }

    public function test_o_resolvedor_mora_em_um_lugar_so_e_os_dois_metodos_concordam(): void
    {
        config(['publicador.aprovacao.margem_minima_percentual' => 12.0]);
        $empresa = Company::factory()->create();
        EstruturaPrecificacaoParametros::create(['company_id' => $empresa->id, 'margem_contribuicao' => 18.5]);
        $this->cenario();
        $servico = $this->servico();

        $this->assertSame(18.5, $servico->margemMinimaDaEmpresa($empresa->id));
        $this->assertSame(['valor' => 18.5, 'origem' => PubAprovacaoComposicao::MINIMO_PARAMETRO], $servico->margemMinimaComOrigem($empresa->id));

        // Sem empresa, a config — e nunca uma exceção.
        $this->assertSame(12.0, $servico->margemMinimaDaEmpresa(null));
        $this->assertSame(PubAprovacaoComposicao::MINIMO_CONFIG, $servico->margemMinimaComOrigem(null)['origem']);

        // ⚠️ A config é LIDA, não embutida: mudá-la muda o resultado.
        config(['publicador.aprovacao.margem_minima_percentual' => 30.0]);
        $this->assertSame(30.0, $servico->margemMinimaDaEmpresa(null), 'o 12 está embutido no código em vez de vir da config');
    }

    public function test_a_composicao_seleciona_as_chaves_dos_parametros_e_nao_repassa_o_array(): void
    {
        // ⚠️ `daEmpresa()` devolve `array_map('floatval', …)` de TODAS as `PADROES`, inclusive o
        // campo homônimo do outro nome (VOC-03). Repassar o array vazaria aquele nome para
        // dentro de `linhas` e daí para a tela.
        $this->assertArrayHasKey('lucro_liquido', EstruturaPrecificacaoParametros::PADROES,
            'ISCA CEGA: o campo homônimo não existe mais — este gate deixou de medir algo');

        config(['publicador.aprovacao.margem_minima_percentual' => 12.0]);
        $this->comTarifa('MLB189007', self::CLASSICO, $this->fixtureListingPrices('MLB189007', self::CLASSICO));
        $oferta = $this->cenario(['preco' => 489.90]);

        $linha = $this->servico()->congelar($oferta, PubAprovacaoComposicao::APROVACAO);
        $json = json_encode($linha->fresh()->linhas, JSON_UNESCAPED_UNICODE);

        foreach (['lucro_liquido', 'comissao_classico', 'comissao_premium', 'acrescimo'] as $chave) {
            $this->assertStringNotContainsString($chave, (string) $json, "a chave `{$chave}` dos parâmetros vazou para a composição");
        }
        $this->assertStringContainsString('margem_minima_origem', (string) $json, 'ISCA CEGA: a sonda não acha nem a chave que DEVE estar lá');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // FIN-11 / FIN-12 — a simulação PURA, com isca
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_simular_nao_persiste_nada_e_a_isca_prova_que_a_contagem_sabe_subir(): void
    {
        $this->comTarifa('MLB189007', self::CLASSICO, $this->fixtureListingPrices('MLB189007', self::CLASSICO));
        $this->comTarifa('MLB189007', self::PREMIUM, $this->fixtureListingPrices('MLB189007', self::PREMIUM));
        $oferta = $this->cenario(['preco' => 489.90]);
        $servico = $this->servico();

        $antes = PubAprovacaoComposicao::count();
        $simulacao = $servico->simular($oferta, 549.90);
        $this->assertSame($antes, PubAprovacaoComposicao::count(), 'a simulação PERSISTIU — um preço de teste iria ao ar');

        $this->assertSame(489.90, $simulacao['preco_salvo']);
        $this->assertSame(549.90, $simulacao['preco_simulado']);
        $this->assertNotNull($simulacao['diferenca']);
        $this->assertSame(round($simulacao['simulada']->resultado - $simulacao['salva']->resultado, 2), $simulacao['diferenca']);
        $this->assertGreaterThan(0, $simulacao['diferenca'], 'preço maior tem de dar resultado maior neste cenário');

        // ⚠️ ISCA: a MESMA contagem SOBE quando o teste congela de propósito. Sem ela, um `count()`
        // que sempre devolve o mesmo número passaria o gate medindo nada.
        $servico->congelar($oferta, PubAprovacaoComposicao::APROVACAO);
        $this->assertSame($antes + 1, PubAprovacaoComposicao::count(), 'ISCA CEGA: nem o congelamento faz a contagem subir.');
    }

    public function test_congelar_grava_o_minimo_da_epoca_e_de_qual_lado_do_fallback_veio(): void
    {
        config(['publicador.aprovacao.margem_minima_percentual' => 12.0]);
        $this->comTarifa('MLB189007', self::CLASSICO, $this->fixtureListingPrices('MLB189007', self::CLASSICO));
        $empresa = Company::factory()->create();
        EstruturaPrecificacaoParametros::create(['company_id' => $empresa->id, 'margem_contribuicao' => 20.0]);
        $oferta = $this->cenario(['preco' => 489.90, 'company' => $empresa]);

        $linha = $this->servico()->congelar($oferta, PubAprovacaoComposicao::APROVACAO)->fresh();

        $this->assertSame(20.0, $linha->margemMinimaCongelada());
        $this->assertSame(PubAprovacaoComposicao::MINIMO_PARAMETRO, $linha->margem_minima_origem);
        $this->assertSame(PubAprovacaoComposicao::MINIMO_PARAMETRO, $linha->linhas['resolucao']['margem_minima_origem'],
            'a origem do mínimo também mora dentro de `linhas` — é esse bloco que a auditoria lê');
        $this->assertCount(8, $linha->linhas['linhas'], 'as 7 da conta mais a informativa');
        $this->assertSame(489.90, (float) $linha->preco);
        $this->assertFalse($linha->incompleto);
        $this->assertTrue($linha->estimado_por_baixo, 'sem fonte de embalagem, o congelado sai estimado por baixo');

        // ⚠️ A config muda DEPOIS do ato e o congelado NÃO muda — é essa a diferença entre
        // auditoria e recálculo (T-186-29).
        config(['publicador.aprovacao.margem_minima_percentual' => 40.0]);
        $this->assertSame(20.0, $linha->fresh()->margemMinimaCongelada());
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // FIN-13 — a oferta irmã; e FIN-04 — o card do editor
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_comparacao_com_a_irma_devolve_a_outra_modalidade_e_null_quando_nao_existe(): void
    {
        $this->comTarifa('MLB189007', self::CLASSICO, $this->fixtureListingPrices('MLB189007', self::CLASSICO));
        $this->comTarifa('MLB189007', self::PREMIUM, $this->fixtureListingPrices('MLB189007', self::PREMIUM));

        $classico = $this->cenario(['preco' => 489.90, 'listing_type' => self::CLASSICO]);
        $this->assertNull($this->servico()->comparacaoComAIrma($classico), 'sem irmã é `null`, NUNCA zeros');

        // A irmã mora no MESMO rascunho — o par é ESTRUTURAL, não combinado.
        $alvoPremium = PubRascunhoAlvo::create(['rascunho_id' => $classico->rascunho_id, 'listing_type_id' => self::PREMIUM, 'posicao' => 1]);
        $premium = PubAprovacaoOferta::create([
            'alvo_id' => $alvoPremium->id, 'rascunho_id' => $classico->rascunho_id,
            'produto_id' => $classico->produto_id, 'company_id' => $classico->company_id,
        ]);
        $this->efetivos->osPrecos = ['precos' => [self::CLASSICO => 489.90, self::PREMIUM => 529.90]];
        $this->efetivos->osFretes['por_tipo'][self::PREMIUM] = ['valor' => 42.9, 'origem' => 'tabela', 'oferta_id' => null];

        $comparacao = $this->servico()->comparacaoComAIrma($classico->fresh());
        $this->assertNotNull($comparacao);
        $this->assertSame((int) $premium->id, $comparacao['oferta_id']);
        $this->assertSame(self::PREMIUM, $comparacao['listing_type_id']);
        $this->assertSame(529.90, $comparacao['preco']);
        $this->assertNotNull($comparacao['resultado']);
        $this->assertSame(PubAprovacaoOferta::PREPARANDO, $comparacao['andamento']);
        $this->assertSame('Preparando', $comparacao['rotulo_do_andamento']);

        // E o caminho de volta: do Premium se vê o Clássico.
        $deVolta = $this->servico()->comparacaoComAIrma($premium->fresh());
        $this->assertSame((int) $classico->id, $deVolta['oferta_id']);
    }

    public function test_fin_04_o_painel_nao_discorda_do_card_do_editor_pela_parte_comum(): void
    {
        $this->comTarifa('MLB189007', self::CLASSICO, $this->fixtureListingPrices('MLB189007', self::CLASSICO));
        $oferta = $this->cenario(['preco' => 489.90]);
        $servico = $this->servico();

        $c = $servico->para($oferta);
        $recebe = $servico->voceRecebe($oferta);
        $this->assertNotNull($recebe);

        // A tarifa é a MESMA nas duas contas — é isso que FIN-04 exige.
        $this->assertSame(round($c->linha('comissao')->valor + $c->linha('tarifa_fixa')->valor, 2), $recebe['tarifa']);
        $this->assertSame($c->linha('frete')->valor, $recebe['frete']);
        $this->assertSame($c->preco, $recebe['preco']);

        // ⚠️ O DELTA é exatamente o que o card NÃO desconta: custo + imposto + embalagem + outros.
        $naoDescontado = round(
            ($c->linha('custo')->valor ?? 0.0) + ($c->linha('imposto')->valor ?? 0.0)
            + ($c->linha('embalagem')->valor ?? 0.0) + ($c->linha('outros')->valor ?? 0.0), 2);
        $this->assertSame($naoDescontado, round($recebe['voce_recebe'] - $c->resultado, 2),
            'o painel e o card divergem por algo que não é a lista declarada — um dos dois está com tarifa diferente');
        $this->assertGreaterThan(0, $naoDescontado, 'ISCA CEGA: o delta é zero — o gate não compara nada.');
    }

    public function test_preco_por_variante_manda_o_menor_ativo_e_a_tela_nomeia_a_variante(): void
    {
        $this->comTarifa('MLB189007', self::CLASSICO, $this->fixtureListingPrices('MLB189007', self::CLASSICO));
        $oferta = $this->cenario(['preco' => 489.90]);

        $cria = function (string $chave, float $preco, bool $ativa = true) use ($oferta) {
            $v = PubVariante::create(['rascunho_id' => $oferta->rascunho_id, 'combinacao_chave' => $chave,
                'combinacao_hash' => hash('sha256', $chave), 'ativa' => $ativa, 'posicao' => 0]);
            PubVariantePreco::create(['variante_id' => $v->id, 'alvo_id' => $oferta->alvo_id, 'preco' => $preco]);
        };

        // ⚠️ Decisão (5): o PIOR CASO. A mais barata é a que pode dar prejuízo, e aprovar JÁ
        // PUBLICA, sem desfazer.
        $cria('Cor: Cinza', 529.90);
        $cria('Cor: Bege', 449.90);
        $cria('Cor: Azul', 999.90, ativa: false);

        $servico = $this->servico();
        $doAlvo = $servico->precoDoAlvo($oferta);
        $this->assertSame(449.90, $doAlvo['preco'], 'manda o MENOR preço ativo, não a primeira variante');
        $this->assertSame('Cor: Bege', $doAlvo['variante']);
        $this->assertSame(2, $doAlvo['ativos'], 'a variante inativa não conta');

        $c = $servico->para($oferta);
        $this->assertSame(449.90, $c->preco);
        $this->assertSame('Cor: Bege', $c->precoDeVariante);
        $this->assertStringContainsString('pior caso entre 2 variantes', (string) $c->notaDoPreco);

        // ⚠️ Com UM preço só (o caso comum) NÃO há nota.
        $outra = $this->cenario(['preco' => 489.90]);
        $umaSo = $this->servico()->para($outra);
        $this->assertNull($umaSo->notaDoPreco);
        $this->assertNull($umaSo->precoDeVariante);
    }

    public function test_a_composicao_usa_o_preco_salvo_e_nao_o_antigo_do_aviso_de_alteracao(): void
    {
        // O caso `o3` do protótipo: preço alterado de 799,90 para 749,90. A composição é a do preço
        // SALVO; o antigo vive só no aviso de alteração não conferida.
        $this->comTarifa('MLB189007', self::CLASSICO, $this->fixtureListingPrices('MLB189007', self::CLASSICO));
        $oferta = $this->cenario(['preco' => 749.90, 'unidades' => 2]);
        $oferta->update(['preco_alterado' => ['de' => 799.90, 'para' => 749.90, 'por' => 'BL']]);

        $c = $this->servico()->para($oferta->fresh());

        $this->assertSame(749.90, $c->preco, 'a composição saiu do preço ANTIGO');
        $this->assertSame(round(749.90 * 11 / 100, 2), $c->linha('comissao')->valor);
        $this->assertSame(799.90, (float) $oferta->fresh()->preco_alterado['de'], 'o aviso do preço antigo continua lá');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ⚠️ O TABELADO: UM `test()` POR ESTADO DO PROTÓTIPO (nunca um loop — a contagem é o sinal)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /** O `.dc.html`, ou `null` quando o diretório não versionado não está no disco. */
    private function textoDoPrototipo(): ?string
    {
        $caminho = base_path(self::PROTOTIPO);

        return is_file($caminho) ? (string) file_get_contents($caminho) : null;
    }

    /**
     * O estado `$id` do `OFF` do protótipo, LIDO do arquivo.
     *
     * @return array{preco: float, frete: ?float, unidades: int, listing_type: string}
     */
    private function estadoDoPrototipo(string $id, string $texto): array
    {
        $this->assertSame(1, preg_match("/\{ id: '{$id}', set: '(\w+)', mod: '(\w+)', price: ([\d.]+), freight: (null|[\d.]+)/", $texto, $m),
            "não achei o estado `{$id}` no OFF do protótipo");
        [, $set, $mod, $preco, $frete] = $m;

        $this->assertSame(1, preg_match("/{$set}: \{ prod: '\w+', units: (\d+)/", $texto, $u), "não achei o conjunto `{$set}`");

        return [
            'preco' => (float) $preco,
            'frete' => $frete === 'null' ? null : (float) $frete,
            'unidades' => (int) $u[1],
            'listing_type' => $mod === 'premium' ? self::PREMIUM : self::CLASSICO,
        ];
    }

    /**
     * ⚠️ ESTRUTURA E INVARIANTES, com os NÚMEROS das fontes reais. A fórmula, os rótulos e a ordem
     * são LIDOS do `.dc.html`; os esperados são recalculados **pela mesma fórmula**.
     */
    private function conferirEstado(string $id): void
    {
        $texto = $this->textoDoPrototipo();
        if ($texto === null) {
            $this->markTestSkipped('`'.self::PROTOTIPO.'` não está no disco (o diretório do desenho NÃO é versionado): a fórmula, os rótulos e a ordem não podem ser LIDOS, e declarar "conferido" sem ler seria falso.');
        }

        // ── A ORDEM e os RÓTULOS, lidos do `fin()` ───────────────────────────────────────────
        $this->assertSame(1, preg_match('/fin\(o, p\) \{(.+?)\n  \}/s', $texto, $bloco), 'não achei o `fin()` no protótipo');
        preg_match_all("/\{ k: '(\w+)', label: '([^']+)'/", $bloco[1], $linhasDoPrototipo);

        $ordemDoPrototipo = array_map(fn (string $k) => self::DO_PROTOTIPO[$k] ?? $k, $linhasDoPrototipo[1]);
        $this->assertSame(array_keys(PubAprovacaoComposicao::LINHAS), $ordemDoPrototipo,
            'a ORDEM das 7 linhas deste sistema não é a do protótipo');

        // ── O estado, e a composição com as FONTES REAIS ─────────────────────────────────────
        $estado = $this->estadoDoPrototipo($id, $texto);
        $corpo = $this->fixtureListingPrices('MLB189007', $estado['listing_type']);
        $percentual = (float) $corpo['sale_fee_details']['percentage_fee'];
        $fixa = (float) $corpo['sale_fee_details']['fixed_fee'];
        $imposto = (float) EstruturaPrecificacaoParametros::PADROES['imposto'];
        $minimo = (float) config('publicador.aprovacao.margem_minima_percentual');

        $this->comTarifa('MLB189007', $estado['listing_type'], $corpo);
        $oferta = $this->cenario([
            'preco' => $estado['preco'], 'frete' => $estado['frete'], 'unidades' => $estado['unidades'],
            'listing_type' => $estado['listing_type'], 'custo' => 200.0,
        ]);
        $c = $this->servico()->para($oferta);

        // Os RÓTULOS: o do sistema é o do protótipo (o `comm` do protótipo concatena o tipo).
        foreach ($linhasDoPrototipo[1] as $i => $k) {
            $nossa = self::DO_PROTOTIPO[$k];
            $this->assertStringStartsWith(PubAprovacaoComposicao::LINHAS[$nossa], $linhasDoPrototipo[2][$i],
                "o rótulo de `{$nossa}` divergiu do protótipo");
            $this->assertSame($nossa, $c->linhasDaConta()[$i]->chave, "a linha #{$i} da composição não é `{$nossa}`");
            $this->assertSame(PubAprovacaoComposicao::LINHAS[$nossa], $c->linhasDaConta()[$i]->rotulo);
        }

        // ── A PROPAGAÇÃO DE `null` ───────────────────────────────────────────────────────────
        if ($estado['frete'] === null) {
            $this->assertTrue($c->incompleto, "o estado `{$id}` tem frete nulo e tem de ficar incompleto");
            foreach (['total', 'resultado', 'margem', 'somaFixa', 'equilibrio', 'precoAlvo'] as $campo) {
                $this->assertNull($c->$campo, "`{$campo}` do estado `{$id}` tem de ser null, nunca 0.0");
            }
            $this->assertSame('dados incompletos', $c->notaDaMargem);

            return;
        }

        // ── AS INVARIANTES, recalculadas pela MESMA fórmula ──────────────────────────────────
        $custo = round(200.0 * $estado['unidades'], 2);
        $comissao = round($estado['preco'] * $percentual / 100, 2);
        $tributo = round($estado['preco'] * $imposto / 100, 2);
        $total = round($custo + $comissao + $fixa + $estado['frete'] + $tributo + 0.0, 2);
        $somaFixa = $custo + $fixa + $estado['frete'] + 0.0;
        $vr = ($percentual + $imposto) / 100;

        $this->assertFalse($c->incompleto, "o estado `{$id}` tem todas as cinco e não devia estar incompleto");
        $this->assertSame($total, $c->total, "o total do estado `{$id}`");
        $this->assertSame(round($estado['preco'] - $total, 2), $c->resultado);
        $this->assertSame($somaFixa, $c->somaFixa, 'a `somaFixa` é custo + tarifa fixa + frete + embalagem + outros');
        $this->assertSame(self::teto($somaFixa / (1 - $vr)), $c->equilibrio);
        $this->assertSame(self::teto($somaFixa / (1 - $vr - $minimo / 100)), $c->precoAlvo);
        $this->assertSame(round($c->resultado / $estado['preco'] * 100, 6), round($c->margem, 6));

        // ⚠️ Embalagem sem fonte: o resultado existe e sai marcado estimado por baixo.
        $this->assertTrue($c->estimadoPorBaixo);
        $this->assertStringContainsString('com a tarifa real do Mercado Livre', $c->notaDaMargem);
    }

    public function test_estado_o1_do_prototipo_fecha_na_estrutura_e_nas_invariantes(): void
    {
        $this->conferirEstado('o1');
    }

    public function test_estado_o2_do_prototipo_fecha_na_estrutura_e_nas_invariantes(): void
    {
        $this->conferirEstado('o2');
    }

    public function test_estado_o3_do_prototipo_fecha_na_estrutura_e_nas_invariantes(): void
    {
        $this->conferirEstado('o3');
    }

    public function test_estado_o4_do_prototipo_tem_frete_nulo_e_nao_devolve_zero_em_nenhum_campo(): void
    {
        $this->conferirEstado('o4');
    }

    public function test_estado_o5_do_prototipo_fecha_na_estrutura_e_nas_invariantes(): void
    {
        $this->conferirEstado('o5');
    }

    public function test_estado_o6_do_prototipo_fecha_na_estrutura_e_nas_invariantes(): void
    {
        $this->conferirEstado('o6');
    }

    public function test_estado_o7_do_prototipo_fecha_na_estrutura_e_nas_invariantes(): void
    {
        $this->conferirEstado('o7');
    }

    public function test_estado_o8_do_prototipo_fecha_na_estrutura_e_nas_invariantes(): void
    {
        $this->conferirEstado('o8');
    }

    public function test_estado_o9_do_prototipo_fecha_na_estrutura_e_nas_invariantes(): void
    {
        $this->conferirEstado('o9');
    }

    public function test_estado_o10_do_prototipo_fecha_na_estrutura_e_nas_invariantes(): void
    {
        $this->conferirEstado('o10');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // ZERO REDE
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function test_nenhuma_chamada_deste_plano_sai_para_a_rede_e_a_isca_prova_a_trava(): void
    {
        $this->comTarifa('MLB189007', self::CLASSICO, $this->fixtureListingPrices('MLB189007', self::CLASSICO));
        $this->composicaoDe(['preco' => 489.90]);

        $this->assertNotEmpty($this->chamadas, 'ISCA CEGA: o serviço não chamou o ML nenhuma vez — o gate da rede não mediu nada.');
        foreach ($this->chamadas as $chamada) {
            $this->assertSame('/sites/MLB/listing_prices', $chamada['caminho'], 'o serviço chamou um caminho que este plano não declara');
        }

        // ⚠️ `preventStrayRequests()` está ligado no setUp: qualquer chamada NÃO fingida explode.
        // A isca prova a trava — um caminho sem stub devolve 404 do fake, não vai à internet.
        // ⚠️ SEM `Cache::flush()` aqui: ele apagaria também o app token fingido e o teste morreria
        // por outro motivo (medido). A chave da tarifa leva categoria + tipo + preço, então a
        // consulta abaixo não acerta a entrada anterior.
        $this->comTarifa('MLB47097', self::PREMIUM, ['__status' => 503]);
        $fora = $this->composicaoDe(['preco' => 489.90, 'categoria' => 'MLB47097', 'listing_type' => self::PREMIUM]);
        $this->assertTrue($fora->linha('comissao')->ehPendente());
    }
}
