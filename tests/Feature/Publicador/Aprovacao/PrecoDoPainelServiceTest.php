<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaPrecificacaoParametros;
use App\Models\PubAprovacaoComposicao;
use App\Models\PubAprovacaoExcecao;
use App\Models\PubAprovacaoOferta;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\User;
use App\Services\MercadoLivreService;
use App\Services\MlColetaService;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Publicador\Aprovacao\ComposicaoDaOferta;
use App\Services\Publicador\Aprovacao\ComposicaoFinanceiraService;
use App\Services\Publicador\Aprovacao\Fila\LinhaDaOfertaService;
use App\Services\Publicador\Aprovacao\LinhaFinanceira;
use App\Services\Publicador\Aprovacao\Painel\PrecoDoPainelService;
use App\Services\Publicador\Aprovacao\VocabularioDoPortao;
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
 * Fase 188, plano 04 — O **SERVIDOR DA COLUNA DE PREÇO E RESULTADO** do painel de revisão (E2).
 *
 * Os 3 KPIs, a composição com **origem por linha**, a simulação que **nunca é salva**,
 * equilíbrio / preço-alvo, a oferta **irmã** e o **pior caso** por variação.
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"** — gate cuja resposta é "nada" é
 * decorativo e não entra (`feedback_gate_vazio_por_construcao`).
 *
 *   · **A EMBALAGEM ANULANDO O RESULTADO** — é a exceção **deliberada e ÚNICA** do usuário
 *     (14/10/2026). Sem ela **100%** das ofertas diriam "Incompleto" e **nada seria aprovável**;
 *     com ela "uniformizada", o portão fica inerte. ⚠️ **E o FRETE ausente, no MESMO cenário,
 *     ANULA** — é esse par que separa as duas regras. Um serviço que tratasse *"qualquer
 *     pendência"* como `Incompleto` **passaria em metade dos casos**: por isso o par é medido nos
 *     DOIS sentidos, na mesma fixture.
 *   · **A MARGEM SEM DIZER DE ONDE VEM** — decisão 9: há **DUAS** margens de propósito (aqui a
 *     tarifa REAL do ML; na visão rápida do lote o parâmetro da Precificação, em
 *     `app/Services/Publicador/Fila/ResumoRapidoService.php:384`-`:391`). **Deixar sem texto foi
 *     OFERECIDO e RECUSADO** — sem o texto, a divergência chega à pessoa como **bug de dado**. Há
 *     caso exigindo a substring, e ela é **LIDA** de `ComposicaoDaOferta:271`, não remontada.
 *   · **O `30%` DO TRAÇO CRAVADO** — `left:30%` (`:645`) existe **porque `MIN = 12`** (`:914`) e
 *     `12/40 = 30%`. Com o literal, a tela mentiria para a empresa que configurou outro mínimo.
 *     ➡️ A prova é **por FÓRMULA, com DOIS mínimos diferentes**.
 *   · **O CLAMP DA BARRA** — sem o inferior, margem negativa desenharia para a esquerda; sem o
 *     superior, 95% estouraria a trilha. `min(40, max(0, m))/40` (`:1127`), **as duas pontas**.
 *   · **A SIMULAÇÃO VIRANDO PERSISTÊNCIA** — crit. 7: ela **nunca** é persistida nem enviada ao
 *     ML, só é salvável no `live`, e salvar incrementa a versão. Aqui há gate de ausência de
 *     **toda** forma de escrita **e** de `congelar()`, ancorado no **operador de chamada** (a
 *     palavra `salvar` é legítima em `pode_salvar`), e prova de **zero requisição HTTP**.
 *   · **O SELO "SIMULAÇÃO · NÃO SALVA" SEMPRE VISÍVEL** — `simOn` exige `|sp − preço| > 0,001`
 *     (`:1129`). Com `>= 0` o selo apareceria com o preço IGUAL ao salvo e `pode_salvar` ficaria
 *     verdadeiro para salvar o que já está salvo (versão++ por nada).
 *   · **`(float) []` DANDO 500** — o preço vem da digitação da pessoa. `0`, negativo, vazio, texto
 *     **e array** caem no neutro **sem exceção**; é a família do `(int) []` que já foi 500 nesta
 *     tela.
 *   · **A ORDEM IMPLÍCITA DAS 7 LINHAS** — a lição medida na wave 188-03: ordem que vem do ÍNDICE
 *     divide MariaDB × SQLite e **nenhuma sonda pega**, porque texto e número concordam entre si
 *     apontando para o lugar errado. Aqui a ordem é **explícita** (uma constante só) **e a premissa
 *     está travada**: há asserção de que a ordem que CHEGA é a da constante.
 *   · **DUAS CORES DE ORIGEM IGUAIS** — passaria num teste de existência. As **seis** (texto e
 *     borda das três) são medidas **distintas aos pares**.
 *   · **A EXCEÇÃO LIBERANDO O QUE NÃO DEVE** — `PubAprovacaoExcecao::LIBERA` é lista **FECHADA**
 *     de DOIS. Lista de proibições deixaria permitido tudo que ninguém lembrou de proibir, e aqui
 *     "permitido a mais" significa **publicar um anúncio que ninguém conferiu**, sem desfazer. Os
 *     outros **seis** bloqueios são provados **um por um**.
 *   · **`ceil(alvo) − 0,1` OMITIDO** — `:1140` é contraintuitivo e fácil de perder: R$ 118,43
 *     preenche `118,90`. ⚠️ **E o ponteiro `:1139` da prosa do plano está DESLOCADO** — `:1139` é
 *     o `onSimBE`. Medido por `sed` nesta wave.
 *
 * ⚠️ **ZERO REDE:** `Http::preventStrayRequests()` + `Http::fake` com gravador. **O painel APROVA
 * (e aprovar ENFILEIRA), NÃO PUBLICA** — a escrita no Mercado Livre é da **Fase 193**, e há caso
 * provando a ausência por **ausência de requisição**, com isca.
 *
 * ⚠️ **ESTE ARQUIVO NÃO ALTERA NADA DAS FASES 186 E 187.** `ComposicaoFinanceiraService`,
 * `ComposicaoDaOferta`, `LinhaFinanceira`, `LinhaDaOfertaService`, `VocabularioDoPortao`,
 * `PubAprovacaoComposicao` e `PubAprovacaoExcecao` são **LIDOS e CHAMADOS**; o dublê de
 * `DadosEfetivosService` é **SUBCLASSE no teste**, nunca alteração no disco.
 *
 * ⚠️⚠️ **NENHUM EXECUTOR DESTA FASE VIU ESTA TELA RENDERIZADA.** O banco local não tem empresa com
 * `ml_token` (a rota dá **404** aqui), `pub_aprovacao_ofertas` está **vazia** e a fila nasce vazia
 * até a Fase 194. A conferência é do usuário, em produção — e a **conferência visual da Fase 187
 * continua ABERTA**, nada daqui a substitui.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class PrecoDoPainelServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O commit de PARTIDA desta wave — o HEAD no começo DESTE plano, capturado na primeira ação.
     *
     * ⚠️ **REFERÊNCIA DE LEITURA, nunca extremo de range confiável:** a 187-11 mediu 111 commits no
     * range, **43 da sessão paralela**, e o diff cru acusava **12 dos 60 alvos vetados**. ➡️ O que
     * discrimina território é o **ESCOPO DO ASSUNTO**, abaixo. ⚠️ Na wave 188-03 a régua se provou
     * ao vivo: o range dela tinha **5** commits e **UM não era do plano** (uma nota de coordenação
     * do orquestrador, **com o mesmo `user.name`**) — e o escopo o excluiu **sem olhar autor**.
     */
    private const PARTIDA = '9becc6e7';

    /**
     * O ESCOPO DO ASSUNTO deste plano.
     *
     * ⚠️ **O autor NÃO discrimina nada nesta árvore:** as três máquinas rodam com o MESMO
     * `user.name` (`MB.ECF-100376`), então `%an` é vazio por construção.
     */
    private const ESCOPO = '/\(188-04\)/';

    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    /** @var array<string, array<string, mixed>> */
    private array $tarifas = [];

    /** @var list<array{caminho: string, query: array}> */
    private array $chamadas = [];

    private ?DadosEfetivosService $efetivos = null;

    protected function setUp(): void
    {
        parent::setUp();

        // ⚠️ `Carbon::setTestNow()` com o agora PARADO: as duas falhas herdadas desta suíte
        // (`tests/Feature/Publicador/CockpitDaContaTest.php:966` e `:1318`, Fase 179, **fora** de
        // `Aprovacao/`) são fronteira de dia, e nenhum caso novo desta fase repete o mesmo erro.
        Carbon::setTestNow('2026-10-19 09:00:00');

        // ⚠️ O app token do `ClienteMlPublicador` é FINGIDO no cache. ⚠️ E nunca `Cache::flush()`.
        Cache::put('ml_app_token_coleta', 'fake-app-token', 3600);

        // ⚠️ ZERO REDE: qualquer chamada não fingida explode, em vez de sair para a internet.
        Http::preventStrayRequests();
        Http::fake(function (Request $req) {
            $caminho = (string) parse_url($req->url(), PHP_URL_PATH);
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $query);
            $this->chamadas[] = ['caminho' => $caminho, 'query' => $query];

            if ($caminho === '/sites/MLB/listing_prices') {
                $chave = ($query['category_id'] ?? '').'|'.($query['listing_type_id'] ?? '');
                $corpo = $this->tarifas[$chave] ?? null;

                return $corpo === null
                    ? Http::response(['message' => 'sem stub de tarifa para '.$chave], 404)
                    : Http::response($corpo, 200);
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

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Fixture — ⚠️ a MESMA forma de `ComposicaoContraOPrototipoTest` (Fase 186), com as mesmas
    // armadilhas declaradas lá: `array_key_exists` e nunca `??` (senão o caso do dado AUSENTE
    // mede o cenário COM dado), e o serviço construído **depois** do dublê.
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /** A resposta REAL do ML gravada em `tests/fixtures-ml/`. */
    private function tarifaReal(string $categoria, string $listingType): array
    {
        $arquivo = base_path("tests/fixtures-ml/sondagem/publico/categorias/{$categoria}/listing_prices_{$listingType}.json");
        $this->assertFileExists($arquivo, 'fixture de tarifa ausente — o gate rodaria sobre ZERO casos');

        return json_decode((string) file_get_contents($arquivo), true)['resposta'];
    }

    /**
     * Monta a oferta e troca `DadosEfetivosService` por uma subclasse que devolve o que o teste
     * declara. ⚠️ O serviço real é **SOMENTE LEITURA** nesta fase: é CHAMADO, nunca reescrito.
     *
     * @param  array{listing_type?: string, categoria?: string, unidades?: int, preco?: ?float, custo?: ?float, origem_custo?: ?string, imposto?: ?float, frete?: ?float, origem_frete?: ?string, company?: ?Company, rascunho?: ?PubRascunho, andamento?: string, minimo?: ?float, preco_alterado?: ?array, versao?: int}  $o
     */
    private function cenario(array $o = []): PubAprovacaoOferta
    {
        $listingType = $o['listing_type'] ?? self::CLASSICO;
        $categoria = $o['categoria'] ?? 'MLB189007';
        $empresa = $o['company'] ?? Company::factory()->create();

        $this->comTarifaReal($categoria, $listingType);

        if (array_key_exists('minimo', $o) && $o['minimo'] !== null) {
            // ⚠️ FIN-09 — a margem mínima é a ÚNICA configuração nova da milestone, e ela JÁ existe.
            // Aqui se usa o resolvedor da 186 (`margemMinimaComOrigem`), via o parâmetro da empresa.
            config(['publicador.aprovacao.margem_minima_percentual' => $o['minimo']]);
        }

        $produto = PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => 'PRC-'.PubProduto::count().'-'.$listingType,
            'nome' => 'Poltrona do preço',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'quantidade_kit' => $o['unidades'] ?? 1,
        ]);
        $rascunho = $o['rascunho'] ?? PubRascunho::create(['produto_id' => $produto->id, 'categoria_id' => $categoria]);
        $alvo = PubRascunhoAlvo::create([
            'rascunho_id' => $rascunho->id,
            'listing_type_id' => $listingType,
            'titulo' => 'Poltrona do preço Veludo',
            'posicao' => $listingType === self::CLASSICO ? 0 : 1,
        ]);

        $oferta = PubAprovacaoOferta::create([
            'alvo_id' => $alvo->id,
            'rascunho_id' => $rascunho->id,
            'produto_id' => $produto->id,
            'company_id' => $empresa->id,
            'andamento' => $o['andamento'] ?? PubAprovacaoOferta::AGUARDANDO_APROVACAO,
            'entrou_na_fila_em' => '2026-10-19 06:00:00',
            'versao' => $o['versao'] ?? 1,
            'preco_alterado' => $o['preco_alterado'] ?? null,
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

        // ⚠️ `array_key_exists`, NUNCA `??`: `['custo' => null]` significa "a Precificação está sem
        // custo", e o `??` o devolveria ao default — o caso do custo ausente passaria medindo o
        // cenário COM custo. Mesma armadilha para `imposto` e `frete`.
        $this->efetivos->osCustos = [
            'custo' => array_key_exists('custo', $o) ? $o['custo'] : 200.0,
            'origem' => $o['origem_custo'] ?? 'digitado',
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
        $this->efetivos->osPrecos = ['precos' => [$listingType => array_key_exists('preco', $o) ? $o['preco'] : 489.90]];

        $this->app->instance(DadosEfetivosService::class, $this->efetivos);

        return $oferta->fresh(['alvo', 'produto', 'rascunho']);
    }

    private function comTarifaReal(string $categoria, string $listingType): void
    {
        $this->tarifas[$categoria.'|'.$listingType] = $this->tarifaReal($categoria, $listingType);
    }

    private function financeira(): ComposicaoFinanceiraService
    {
        return new ComposicaoFinanceiraService(
            $this->efetivos ?? app(DadosEfetivosService::class),
            app(ClienteMlPublicador::class),
        );
    }

    private function servico(): PrecoDoPainelService
    {
        return new PrecoDoPainelService($this->financeira());
    }

    /**
     * ⚠️ O cenário monta o dublê e **só então** o serviço pode ser construído. Escrever
     * `$this->servico()->para($this->cenario([…]), …)` numa linha só dá **falso negativo
     * silencioso**: o PHP avalia o objeto receptor ANTES do argumento, e o serviço nasceria com o
     * `DadosEfetivosService` REAL — composição incompleta e gate verde por acidente. Este atalho
     * existe para que a ordem **não seja uma escolha**. (A armadilha está declarada em
     * `ComposicaoContraOPrototipoTest::composicaoDe()`, Fase 186.)
     *
     * @param  array<string, mixed>  $o
     * @return array{0: PubAprovacaoOferta, 1: ComposicaoDaOferta, 2: array<string, mixed>}
     */
    private function colunaDe(array $o): array
    {
        $oferta = $this->cenario($o);
        $financeira = $this->financeira();
        $composicao = $financeira->para($oferta);
        $coluna = (new PrecoDoPainelService($financeira))->para($oferta, $composicao);

        return [$oferta, $composicao, $coluna];
    }

    private function raiz(): string
    {
        return rtrim(str_replace('\\', '/', base_path()), '/');
    }

    private function git(string $comando): string
    {
        return trim((string) shell_exec('cd '.escapeshellarg($this->raiz()).' && '.$comando.' 2>&1'));
    }

    /** O arquivo CRU, **com** comentário. */
    private function cru(string $relativo): string
    {
        $caminho = $this->raiz().'/'.$relativo;
        $this->assertFileExists($caminho, "arquivo inexistente: {$relativo} — o gate mediria NADA");

        return (string) file_get_contents($caminho);
    }

    /**
     * O código PHP **sem comentário** — `token_get_all`, nunca regex.
     *
     * ⚠️ Palavra no DOCBLOCK que explica *"não uso isso"* **reprova pelo próprio conserto** (houve
     * TRÊS mutações abortando por isso na wave 187-04).
     */
    private function semComentarios(string $relativo): string
    {
        $limpo = '';
        foreach (token_get_all($this->cru($relativo)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $limpo .= is_array($token) ? $token[1] : $token;
        }

        return $limpo;
    }

    /** Quantas requisições de tarifa saíram até agora. */
    private function requisicoesDeTarifa(): int
    {
        return count(array_filter($this->chamadas, static fn (array $c): bool => $c['caminho'] === '/sites/MLB/listing_prices'));
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (1) OS 3 KPIs (`:642`-`:646`) — ordem, rótulos LIDOS e as TRÊS cores
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ O rótulo do KPI 2 é **LIDO** de `ComposicaoDaOferta::rotuloDoResultado()` — escrevê-lo à
     * mão seria a terceira redação do mesmo nome, e a que divergisse seria a que a pessoa lê.
     * ⚠️ **NUNCA "lucro líquido"** (VOC-03): há gate de ausência recortado neste arquivo.
     */
    public function test_os_tres_KPIs_saem_na_ordem_com_rotulos_LIDOS_e_as_tres_cores_188_04(): void
    {
        // ─── (a) COMPLETO, resultado POSITIVO ────────────────────────────────────────────────
        [, $completa, $positivo] = $this->colunaDe([]);

        $this->assertCount(3, $positivo['kpis'], 'os KPIs são TRÊS — nem 2 nem 4');
        $this->assertSame(
            PrecoDoPainelService::KPIS_EM_ORDEM,
            array_column($positivo['kpis'], 'chave'),
            'a ORDEM é o contrato: preço · resultado · margem',
        );

        $this->assertSame(PrecoDoPainelService::ROTULO_PRECO, $positivo['kpis'][0]['rotulo']);
        // ⚠️ LIDO do DTO, e o DTO lê de `PubAprovacaoComposicao::ROTULO_RESULTADO`.
        $this->assertSame($completa->rotuloDoResultado(), $positivo['kpis'][1]['rotulo']);
        $this->assertSame(PubAprovacaoComposicao::ROTULO_RESULTADO, $positivo['kpis'][1]['rotulo'], 'o rótulo travado mudou de casa');
        $this->assertSame(PrecoDoPainelService::ROTULO_MARGEM_KPI, $positivo['kpis'][2]['rotulo']);
        // ⚠️ O KPI 3 carrega os DOIS textos: o da pergunta (`:644`) e o do CONCEITO travado.
        $this->assertSame(PubAprovacaoComposicao::ROTULO_MARGEM, $positivo['kpis'][2]['rotulo_do_conceito']);
        $this->assertNotSame($positivo['kpis'][2]['rotulo'], $positivo['kpis'][2]['rotulo_do_conceito'], 'são textos distintos DE PROPÓSITO');

        $this->assertFalse($completa->incompleto, 'a fixture completa precisa estar completa, ou o caso mede o cenário errado');
        $this->assertGreaterThan(0.0, (float) $completa->resultado, 'FIXTURE QUE DISCRIMINA: este cenário tem de dar resultado POSITIVO');
        $this->assertSame(PrecoDoPainelService::COR_TEXTO, $positivo['kpis'][1]['cor'], 'resultado positivo vai em cor de TEXTO');

        // ⚠️⚠️ DECISÃO 9 — a frase da tarifa real, **LIDA** de `ComposicaoDaOferta:271`.
        $this->assertSame($completa->notaDaMargem, $positivo['kpis'][2]['nota_da_margem'], 'a nota da margem tem de ser LIDA do DTO');
        $this->assertStringContainsString(
            'com a tarifa real do Mercado Livre',
            $positivo['kpis'][2]['nota_da_margem'],
            'decisão 9: a margem do portão DIZ de onde vem — deixar sem texto foi RECUSADO',
        );
        // ⚠️ E ela é a MESMA frase que a fila usa, que mora em UMA casa.
        $this->assertSame(LinhaDaOfertaService::FONTE_DA_MARGEM, 'com a tarifa real do Mercado Livre', 'a frase mudou de casa na 187');

        // ─── (b) COMPLETO, resultado NEGATIVO ────────────────────────────────────────────────
        [, $comPrejuizo, $negativo] = $this->colunaDe(['preco' => 260.0]);
        $this->assertFalse($comPrejuizo->incompleto);
        $this->assertLessThan(0.0, (float) $comPrejuizo->resultado, 'FIXTURE QUE DISCRIMINA: este cenário tem de dar PREJUÍZO');
        $this->assertSame(PrecoDoPainelService::COR_VERMELHA, $negativo['kpis'][1]['cor'], 'prejuízo vai em VERMELHO');

        // ─── (c) INCOMPLETO (frete ausente) ──────────────────────────────────────────────────
        [, $semFrete, $incompleto] = $this->colunaDe(['frete' => null]);
        $this->assertTrue($semFrete->incompleto);
        // ⚠️ LIDO de `VocabularioDoPortao::INCOMPLETO`: custo/frete ausente **não vira zero**.
        $this->assertSame(VocabularioDoPortao::INCOMPLETO, $incompleto['kpis'][1]['valor']);
        $this->assertSame(PrecoDoPainelService::COR_AMBAR, $incompleto['kpis'][1]['cor'], 'Incompleto vai em ÂMBAR, que NÃO é a cor do prejuízo');
        // ⚠️ `—`, nunca `0%` — zero afirmaria margem zero. O travessão é LIDO da 187.
        $this->assertSame(LinhaDaOfertaService::MARGEM_SEM_NUMERO, $incompleto['kpis'][2]['valor']);
        $this->assertSame('—', LinhaDaOfertaService::MARGEM_SEM_NUMERO, 'o travessão mudou de casa na 187');

        // ⚠️⚠️ AS TRÊS CORES SÃO TRÊS — duas iguais passariam num teste de existência.
        $tres = [$positivo['kpis'][1]['cor'], $negativo['kpis'][1]['cor'], $incompleto['kpis'][1]['cor']];
        $this->assertCount(3, array_unique($tres), 'três ESTADOS do resultado, três CORES: '.json_encode($tres));

        // ⚠️ COR NUNCA É O ÚNICO SINAL (VOC-05): há TEXTO ao lado em todos os três.
        $semTexto = array_values(array_filter(
            [$positivo, $negativo, $incompleto],
            static fn (array $c): bool => trim((string) $c['kpis'][2]['nota_da_margem']) === '',
        ));
        $this->assertSame([], $semTexto, 'cor sem texto ao lado: a pessoa daltônica não lê a cor');

        // ⚠️ `<= 0` É AUSENTE, NÃO ZERO — `R$ 0,00` afirmaria que o produto é de graça. É a MESMA
        // correção já declarada em `LinhaDaOfertaService::preco()`, em produção.
        [, $semPreco, $sem] = $this->colunaDe(['preco' => 0.0]);
        $this->assertTrue($semPreco->incompleto, 'sem preço não há composição nenhuma');
        $this->assertSame(LinhaDaOfertaService::MARGEM_SEM_NUMERO, $sem['kpis'][0]['valor'], 'preço zero NÃO pode sair como R$ 0,00');
        $this->assertStringNotContainsString('R$ 0,00', (string) $sem['kpis'][0]['valor']);
    }

    /**
     * ⚠️⚠️ A BARRA É **FÓRMULA**, NÃO O `30%` LITERAL.
     *
     * `left:30%` (`:645`) existe **porque `MIN = 12`** (`:914`) e `12/40 = 30%`. ➡️ Provado com
     * **DOIS mínimos diferentes**; com o literal, o segundo caso reprova.
     * ⚠️ E o clamp é nas **DUAS** pontas (`:1127`): negativa não desenha para a esquerda, 95% não
     * estoura a trilha.
     */
    public function test_a_barra_da_margem_e_FORMULA_clampada_e_o_traco_segue_o_minimo_188_04(): void
    {
        // ─── Mínimo 12 ⇒ o traço em 30% (`12/40`) ────────────────────────────────────────────
        [, $com12, $doze] = $this->colunaDe(['minimo' => 12.0]);
        $this->assertSame(12.0, (float) $com12->margemMinima, 'o resolvedor da 186 não entregou o mínimo pedido');
        $this->assertSame('30%', $doze['kpis'][2]['barra']['minimo'], '12/40 = 30% — o literal do protótipo é CONSEQUÊNCIA disto');

        // ─── Mínimo 20 ⇒ o traço SE MOVE para 50% (`20/40`) ─────────────────────────────────
        [, $com20, $vinte] = $this->colunaDe(['minimo' => 20.0]);
        $this->assertSame(20.0, (float) $com20->margemMinima);
        $this->assertSame('50%', $vinte['kpis'][2]['barra']['minimo'], 'com o 30% cravado, ESTA linha reprova');
        $this->assertNotSame(
            $doze['kpis'][2]['barra']['minimo'],
            $vinte['kpis'][2]['barra']['minimo'],
            'o traço NÃO se moveu: o 30% está cravado',
        );

        // ⚠️ A fórmula é **CHAMADA** de `LinhaDaOfertaService::percentualDaBarra()` — `public
        // static` exatamente para isto ("duas cópias divergiriam, e a barra passaria a discordar do
        // próprio traço"). Aqui se prova que o painel usa a MESMA.
        $this->assertSame(
            LinhaDaOfertaService::percentualDaBarra(20.0),
            $vinte['kpis'][2]['barra']['minimo'],
            'o painel tem uma SEGUNDA fórmula da barra',
        );
        $this->assertSame(40.0, LinhaDaOfertaService::TETO_DA_BARRA, 'o teto da barra mudou de casa');

        // ⚠️⚠️ **DEFEITO REAL, achado pelo gate do 188-02 e consertado DO MEU LADO (Regra 1):** a
        // primeira redação devolvia `minimo_valor` e `teto` como **`float`**, e `float` de valor
        // inteiro **não sobrevive ao `json_encode`/`json_decode`** (`12.0` volta `12`). O payload
        // atravessa o Inertia, então `test_o_payload_tem_todas_as_chaves_…_188_02` estava CERTO ao
        // reprovar. ➡️ As duas chaves SAÍRAM, e aqui a própria coluna prova o round-trip.
        $this->assertSame(
            ['largura', 'cor', 'minimo'],
            array_keys($vinte['kpis'][2]['barra']),
            'a barra ganhou chave nova — conferir se ela sobrevive ao `json_encode`',
        );
        $json = json_encode($vinte);
        $this->assertIsString($json);
        $this->assertSame($vinte, json_decode((string) $json, true), 'a coluna de preço não sobrevive ao `json_encode` — algo nela não é dado');
        // ⚠️ ISCA: o round-trip PEGA o float inteiro — sem isto, a asserção acima seria cega.
        $this->assertNotSame(['x' => 12.0], json_decode((string) json_encode(['x' => 12.0]), true), 'a isca perdeu o sujeito: float inteiro passou a sobreviver');

        // ─── O CLAMP, nas DUAS pontas ───────────────────────────────────────────────────────
        // (a) margem NEGATIVA não desenha para a esquerda.
        [, $prejuizo, $negativa] = $this->colunaDe(['preco' => 260.0]);
        $this->assertLessThan(0.0, (float) $prejuizo->margem, 'FIXTURE QUE DISCRIMINA: a margem tem de ser NEGATIVA aqui');
        $this->assertSame('0%', $negativa['kpis'][2]['barra']['largura'], 'clamp INFERIOR: margem negativa desenhou para a esquerda');
        $this->assertSame(PrecoDoPainelService::COR_BARRA_BAIXA, $negativa['kpis'][2]['barra']['cor']);

        // (b) margem ACIMA do teto não estoura a trilha.
        [, $gorda, $acima] = $this->colunaDe(['custo' => 1.0, 'imposto' => 0.0, 'frete' => 0.0]);
        $this->assertGreaterThan(40.0, (float) $gorda->margem, 'FIXTURE QUE DISCRIMINA: a margem tem de passar de 40% aqui');
        $this->assertSame('100%', $acima['kpis'][2]['barra']['largura'], 'clamp SUPERIOR: a barra estourou a trilha');
        $this->assertSame(PrecoDoPainelService::COR_CLARA, $acima['kpis'][2]['barra']['cor']);

        // (c) margem NULA (incompleto) ⇒ barra em zero e trilha, nunca um número inventado.
        [, , $nula] = $this->colunaDe(['frete' => null]);
        $this->assertSame('0%', $nula['kpis'][2]['barra']['largura']);
        $this->assertSame(PrecoDoPainelService::COR_TRILHA, $nula['kpis'][2]['barra']['cor']);

        // ⚠️⚠️ AS CORES DA BARRA DO PAINEL **NÃO** SÃO AS DA FILA, e isso é MEDIDO no protótipo:
        // o painel usa `#D4D4D8` (`:1132`) e a fila `#A1A1AA` (`:1246`). `corDaBarra()` da 187
        // devolve `COR_SUAVE` e está **CERTO para a fila**.
        $this->assertNotSame(
            PrecoDoPainelService::COR_CLARA,
            LinhaDaOfertaService::COR_SUAVE,
            'as duas chaves colapsaram: a barra do painel passaria a usar a cor da FILA',
        );

        // ⚠️ ISCA DA PRÓPRIA FÓRMULA, nas duas direções.
        $this->assertSame('30%', LinhaDaOfertaService::percentualDaBarra(12.0), 'a isca perdeu o sujeito: 12/40 deixou de ser 30%');
        $this->assertSame('0%', LinhaDaOfertaService::percentualDaBarra(null), 'a fórmula não trata o nulo');
    }

    /**
     * ⚠️⚠️ A EXCEÇÃO DELIBERADA E ÚNICA: **"Embalagem" nasce `Pendente` e NÃO anula o resultado**;
     * o **FRETE** ausente, no MESMO cenário, **ANULA**.
     *
     * Sem a exceção, **100%** das ofertas diriam "Incompleto" e **nada seria aprovável**. ⚠️ E o
     * resultado sai marcado **estimado por baixo**, com **prejuízo/margem DISPARANDO sobre ele** —
     * o estimado é **otimista**, então a margem REAL é menor que a mostrada, e com aprovar-já-publica
     * e sem desfazer não bloquear seria o erro grave.
     * ⚠️ **"Outros custos" nasce R$ 0,00 `Confirmado`** — `0` MEDIDO ≠ ausente.
     */
    public function test_a_embalagem_NAO_anula_e_o_frete_ANULA_no_mesmo_cenario_188_04(): void
    {
        // ─── SENTIDO 1: embalagem pendente + frete PRESENTE ⇒ o resultado EXISTE ────────────
        [, $comEmbalagem, $coluna] = $this->colunaDe([]);

        $embalagem = $comEmbalagem->linha('embalagem');
        $this->assertInstanceOf(LinhaFinanceira::class, $embalagem);
        $this->assertTrue($embalagem->ehPendente(), 'FIXTURE QUE DISCRIMINA: a embalagem tem de estar PENDENTE aqui');
        $this->assertFalse($comEmbalagem->incompleto, 'a embalagem ANULOU o resultado — a exceção do usuário foi uniformizada');
        $this->assertTrue($comEmbalagem->estimadoPorBaixo, 'o resultado tem de sair marcado ESTIMADO POR BAIXO');
        $this->assertFalse($coluna['incompleto']);
        $this->assertTrue($coluna['estimado_por_baixo'], 'a coluna não carrega a marca do estimado por baixo');
        $this->assertNotSame(VocabularioDoPortao::INCOMPLETO, $coluna['kpis'][1]['valor'], 'com embalagem pendente o resultado EXISTE');

        // ⚠️ A embalagem NÃO está entre as CINCO que anulam — e a lista mora em UMA casa.
        $this->assertNotContains('embalagem', PubAprovacaoComposicao::ANULAM_O_RESULTADO);
        $this->assertNotContains('outros', PubAprovacaoComposicao::ANULAM_O_RESULTADO);
        $this->assertSame(
            ['custo', 'comissao', 'tarifa_fixa', 'frete', 'imposto'],
            PubAprovacaoComposicao::ANULAM_O_RESULTADO,
            'as CINCO que anulam mudaram: mexer nesta lista faz o painel dizer Incompleto em 100% das ofertas',
        );

        // ⚠️ **PREJUÍZO E MARGEM DISPARAM SOBRE O ESTIMADO** — é segurança, não detalhe.
        [, $prejuizoEstimado, $disparando] = $this->colunaDe(['preco' => 260.0]);
        $this->assertTrue($prejuizoEstimado->estimadoPorBaixo, 'FIXTURE QUE DISCRIMINA: tem de estar estimado por baixo');
        $this->assertFalse($prejuizoEstimado->incompleto);
        $this->assertLessThan(0.0, (float) $prejuizoEstimado->resultado, 'o prejuízo tem de DISPARAR sobre o estimado por baixo');
        $this->assertSame(PrecoDoPainelService::COR_VERMELHA, $disparando['kpis'][1]['cor']);
        $this->assertStringContainsString('prejuízo por venda', $disparando['kpis'][2]['nota_da_margem']);
        // ⚠️ E a nota DIZ que está sem o custo de embalagem — a tela não esconde a estimativa.
        $this->assertStringContainsString('sem o custo de embalagem', $disparando['kpis'][2]['nota_da_margem']);

        // ─── "Outros custos" nasce R$ 0,00 `Confirmado` ─────────────────────────────────────
        $outros = $comEmbalagem->linha('outros');
        $this->assertInstanceOf(LinhaFinanceira::class, $outros);
        $this->assertFalse($outros->ehPendente(), '"Outros custos" NÃO nasce pendente');
        $this->assertSame(0.0, $outros->valor, '"Outros custos" nasce R$ 0,00');
        $this->assertSame(PubAprovacaoComposicao::CONFIRMADO, $outros->origem, '0 MEDIDO ≠ ausente: ele é Confirmado');
        $linhaOutros = array_values(array_filter($coluna['composicao']['linhas'], static fn (array $l): bool => $l['chave'] === 'outros'))[0];
        $this->assertSame('R$ 0,00', $linhaOutros['valor'], 'zero sai SEM sinal');
        $this->assertSame(PrecoDoPainelService::COR_NEUTRA, $linhaOutros['valor_cor'], 'zero sai em NEUTRO');

        // ─── SENTIDO 2 (A ISCA QUE SEPARA AS DUAS): frete AUSENTE ⇒ o resultado NÃO EXISTE ──
        [, $semFrete, $anulada] = $this->colunaDe(['frete' => null]);
        $this->assertTrue($semFrete->linha('frete')?->ehPendente() === true, 'FIXTURE QUE DISCRIMINA: o frete tem de estar PENDENTE aqui');
        $this->assertTrue($semFrete->incompleto, 'o frete ausente tem de ANULAR — é a isca que separa as duas regras');
        $this->assertNull($semFrete->resultado);
        $this->assertSame(VocabularioDoPortao::INCOMPLETO, $anulada['kpis'][1]['valor']);
        $this->assertTrue($anulada['incompleto']);
        // ⚠️ E o frete ausente tem o DADO próprio: PRECISO, nunca o `missing` inteiro.
        $this->assertTrue($anulada['frete_ausente']);
        $this->assertSame(PrecoDoPainelService::FRETE_ROTULO_ACAO, $anulada['frete']['rotulo_acao']);
        $this->assertSame('Informar frete confirmado', PrecoDoPainelService::FRETE_ROTULO_ACAO);

        // ⚠️⚠️ A DIVERGÊNCIA **(2)**, PROVADA: `freightMissing` do protótipo (`:1143`) é o `missing`
        // INTEIRO. Aqui o CUSTO ausente deixa a composição incompleta **sem** acender o frete —
        // mandar a pessoa ao modal de frete quando falta o custo é a tela afirmando o que não é, e
        // a 186 já fez essa correção em `BloqueiosDaOfertaService::freteOuCusto()`.
        [, $semCusto, $soCusto] = $this->colunaDe(['custo' => null]);
        $this->assertTrue($semCusto->incompleto, 'FIXTURE QUE DISCRIMINA: sem custo a composição é incompleta');
        $this->assertTrue($soCusto['incompleto']);
        $this->assertFalse($soCusto['frete_ausente'], 'o frete NÃO falta aqui — o que falta é o custo');
        $this->assertNull($soCusto['frete'], 'sem frete faltando, nenhum rótulo de ação de frete');

        // ⚠️ E o destino NÃO é cravado aqui: `frete_ausente` tem DOIS destinos, já resolvidos por
        // `PainelDeRevisaoService::destino()`. O serviço do preço não decide modal nenhum.
        $doServico = $this->semComentarios('app/Services/Publicador/Aprovacao/Painel/PrecoDoPainelService.php');
        foreach (['MODAL_FRETE', 'MODAL_AJUSTES', 'MODAL_EXCECAO', 'DESTINO_MODAL'] as $cravado) {
            // ⚠️ Flag DENTRO do laço.
            $this->assertStringNotContainsString($cravado, $doServico, "o destino {$cravado} foi CRAVADO — ele é do 188-02/09");
        }
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (2) A COMPOSIÇÃO — as 7 linhas na ORDEM EXPLÍCITA, com origem e nota por linha
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **A ORDEM É EXPLÍCITA, E A PREMISSA FICA TRAVADA.**
     *
     * A lição é da wave 188-03: ordem que vem do ÍNDICE divide MariaDB × SQLite e **nenhuma sonda
     * pega**, porque texto e número concordam entre si apontando para o lugar errado. Aqui a
     * composição vem de um **array literal** (`ComposicaoFinanceiraService:239`-`:247`), então a
     * reordenação do painel é **inobservável hoje** — e, em vez de descartar o caso, a **premissa
     * está travada**: há asserção de que a ordem que CHEGA é a da constante. O dia em que ela
     * mudar, esta linha reprova e o conserto fica no lugar certo.
     */
    public function test_as_sete_linhas_saem_na_ORDEM_EXPLICITA_com_origem_e_nota_nao_vazia_188_04(): void
    {
        [, $composicao, $coluna] = $this->colunaDe([]);

        $chaves = array_column($coluna['composicao']['linhas'], 'chave');

        // ⚠️ Lista CONSTANTE, nunca `count()` — **piso não prova PRESENÇA** (a 187-11 mediu uma
        // sobrevivente exatamente aí: com 13 itens, perder um deixava 12 e o piso passava).
        $this->assertSame(
            ['custo', 'comissao', 'tarifa_fixa', 'frete', 'imposto', 'embalagem', 'outros'],
            $chaves,
            'as SETE linhas, nesta ordem',
        );
        // ⚠️ UMA lista só: a ordem do painel É `array_keys(PubAprovacaoComposicao::LINHAS)`.
        $this->assertSame(array_keys(PubAprovacaoComposicao::LINHAS), PrecoDoPainelService::ORDEM_DAS_LINHAS, 'duas listas da mesma verdade ficariam livres para divergir');
        $this->assertSame(PrecoDoPainelService::ORDEM_DAS_LINHAS, $chaves);

        // ⚠️⚠️ **A PREMISSA TRAVADA:** a ordem que CHEGA do serviço da 186 é a da constante.
        $this->assertSame(
            PrecoDoPainelService::ORDEM_DAS_LINHAS,
            array_map(static fn (LinhaFinanceira $l): string => $l->chave, $composicao->linhasDaConta()),
            'a ordem de ENTRADA deixou de ser a da constante: a reordenação do painel passou a ser OBSERVÁVEL e tem de ser medida por caso próprio',
        );

        // ─── Os rótulos são LIDOS, nunca recopiados ─────────────────────────────────────────
        $this->assertSame(
            array_values(PubAprovacaoComposicao::LINHAS),
            array_column($coluna['composicao']['linhas'], 'rotulo'),
            'os rótulos das 7 linhas são LIDOS de `PubAprovacaoComposicao::LINHAS`',
        );

        // ─── ⚠️ DADO-13 / VOC-04: a nota NOMEIA a fonte e NUNCA é vazia ─────────────────────
        $semNota = array_values(array_filter(
            array_merge($coluna['composicao']['linhas'], $coluna['composicao']['informativas']),
            static fn (array $l): bool => trim((string) $l['nota']) === '',
        ));
        $this->assertSame([], $semNota, 'DADO-13: nota vazia é ERRO, não decoração');
        // ⚠️ E a regra é do próprio DTO — construir sem nota LANÇA.
        $this->assertSame('DADO-13-NOTA-VAZIA', LinhaFinanceira::REGRA_NOTA_VAZIA, 'a regra da nota vazia mudou de casa');
        // ⚠️ A nota NOMEIA a fonte: a do custo cita a Precificação do Portal.
        $doCusto = array_values(array_filter($coluna['composicao']['linhas'], static fn (array $l): bool => $l['chave'] === 'custo'))[0];
        $this->assertStringContainsString('Precificação do Portal', $doCusto['nota'], 'a nota tem de NOMEAR a fonte, não dizer "origem automática"');

        // ─── ⚠️⚠️ AS TRÊS ORIGENS TÊM SEIS CORES, TODAS DISTINTAS AOS PARES ────────────────
        $this->assertSame(
            [PubAprovacaoComposicao::CONFIRMADO, PubAprovacaoComposicao::ESTIMADO, PubAprovacaoComposicao::PENDENTE],
            array_keys(PrecoDoPainelService::CORES_DA_ORIGEM),
            'as três origens, nesta ordem',
        );
        $textos = array_column(PrecoDoPainelService::CORES_DA_ORIGEM, 0);
        $bordas = array_column(PrecoDoPainelService::CORES_DA_ORIGEM, 1);
        $this->assertCount(3, array_unique($textos), 'duas cores de TEXTO iguais: `:1125` as tem distintas');
        $this->assertCount(3, array_unique($bordas), 'duas cores de BORDA iguais: `:1125` as tem distintas');
        // ⚠️ E texto ≠ borda em cada origem: a borda translúcida é outro valor.
        $colapsadas = [];
        foreach (PrecoDoPainelService::CORES_DA_ORIGEM as $origem => [$texto, $borda]) {
            // ⚠️ Flag DENTRO do laço.
            if ($texto === $borda) {
                $colapsadas[] = $origem;
            }
        }
        $this->assertSame([], $colapsadas, 'texto e borda colapsaram em alguma origem');

        // ─── O rótulo da origem é LIDO, e o selo bate com a linha ───────────────────────────
        foreach ($coluna['composicao']['linhas'] as $linha) {
            // ⚠️ Flag DENTRO do laço.
            $daComposicao = $composicao->linha((string) $linha['chave']);
            $this->assertInstanceOf(LinhaFinanceira::class, $daComposicao);
            $this->assertSame($daComposicao->rotuloDaOrigem(), $linha['origem_rotulo'], 'o rótulo da origem foi RECOPIADO em vez de LIDO');
            $this->assertSame($daComposicao->ehPendente(), $linha['pendente']);
        }
        $this->assertSame(
            ['Confirmado', 'Estimado', 'Pendente'],
            array_values(PubAprovacaoComposicao::ORIGENS_DA_LINHA),
            'os três rótulos de origem mudaram de casa',
        );

        // ─── ⚠️ A linha INFORMATIVA (FIN-08) aparece e fica FORA das linhas da conta ────────
        $this->assertCount(1, $coluna['composicao']['informativas'], 'a informativa do frete do comprador precisa existir');
        $informativa = $coluna['composicao']['informativas'][0];
        $this->assertSame(ComposicaoFinanceiraService::LINHA_FRETE_COMPRADOR, $informativa['chave']);
        $this->assertSame(ComposicaoFinanceiraService::ROTULO_FRETE_COMPRADOR, $informativa['rotulo']);
        $this->assertTrue($informativa['informativa']);
        $this->assertNotContains(ComposicaoFinanceiraService::LINHA_FRETE_COMPRADOR, $chaves, 'a informativa entrou nas linhas da CONTA');

        // ─── O cabeçalho e o rodapé ─────────────────────────────────────────────────────────
        $this->assertSame(PrecoDoPainelService::ROTULO_PRECO, $coluna['composicao']['cabecalho']['rotulo']);
        $this->assertSame($coluna['kpis'][0]['valor'], $coluna['composicao']['cabecalho']['valor'], 'o cabeçalho e o KPI mostram o MESMO preço');
        // ⚠️ O rodapé (`:663`) mostra o **RESULTADO**, e sai do MESMO KPI — não há dois números.
        $this->assertSame($composicao->rotuloDoResultado(), $coluna['composicao']['rodape']['rotulo']);
        $this->assertSame($coluna['kpis'][1]['valor'], $coluna['composicao']['rodape']['valor']);
        $this->assertSame($coluna['kpis'][1]['cor'], $coluna['composicao']['rodape']['cor']);

        // ⚠️⚠️ DIVERGÊNCIA **(1)** DECLARADA: `:665` tem DUAS frases e só a PRIMEIRA é reproduzida.
        // A segunda (*"Taxas de comissão são exemplos configuráveis."*) é disclaimer da ficção do
        // próprio protótipo (`:1064`, `src:'est'`); no produto a comissão é a tarifa REAL do ML
        // (decisão 9), então a frase seria a tela afirmando o que não é.
        $this->assertSame('Frete pago pelo comprador não entra no resultado.', $coluna['composicao']['nota']);
        $this->assertStringNotContainsString('exemplos configuráveis', $coluna['composicao']['nota'], 'a frase da ficção do protótipo entrou no produto');
    }

    /**
     * ⚠️⚠️ O VALOR DA LINHA TEM O **SINAL INVERTIDO** (`:1126`): custo positivo sai **negativo**
     * (é o que sai do preço), **zero sai SEM sinal** em neutro, e `null` sai como o **texto**
     * `Pendente` em vermelho.
     *
     * ⚠️ O negativo usa **U+2212 MINUS SIGN seguido de espaço** (`:1044`), que **não** é o
     * hífen-menos: trocar por `-` passa desapercebido na tela. Medido byte a byte nas quatro formas.
     */
    public function test_o_valor_da_linha_tem_o_sinal_invertido_e_as_tres_formas_188_04(): void
    {
        [, $composicao, $coluna] = $this->colunaDe(['custo' => 200.0, 'unidades' => 1]);

        $porChave = [];
        foreach ($coluna['composicao']['linhas'] as $linha) {
            // ⚠️ Flag DENTRO do laço.
            $porChave[$linha['chave']] = $linha;
        }

        // ─── (1) CUSTO POSITIVO ⇒ exibido NEGATIVO ──────────────────────────────────────────
        $this->assertSame(200.0, $composicao->linha('custo')?->valor, 'FIXTURE QUE DISCRIMINA: o custo tem de ser 200,00');
        $this->assertSame("\u{2212} R$ 200,00", $porChave['custo']['valor'], 'o sinal NÃO foi invertido, ou o menos não é U+2212');
        $this->assertSame(PrecoDoPainelService::COR_CLARA, $porChave['custo']['valor_cor']);
        // ⚠️ O menos é U+2212 (`E2 88 92`), **não** o hífen-menos (`2D`). Medido em BYTES.
        $this->assertSame('e28892', bin2hex(mb_substr((string) $porChave['custo']['valor'], 0, 1)), 'o sinal virou hífen-menos — U+2212 é o do protótipo');
        $this->assertStringStartsNotWith('-', (string) $porChave['custo']['valor']);

        // ─── (2) ZERO MEDIDO ⇒ SEM sinal, em neutro ─────────────────────────────────────────
        $this->assertSame('R$ 0,00', $porChave['outros']['valor'], 'zero com sinal: `r.v === 0 ? 0 : -r.v` (`:1126`)');
        $this->assertSame(PrecoDoPainelService::COR_NEUTRA, $porChave['outros']['valor_cor']);
        // ⚠️ E a tarifa fixa da fixture REAL do ML é `fixed_fee: 0` — zero MEDIDO, `Confirmado`.
        $this->assertSame(0.0, $composicao->linha('tarifa_fixa')?->valor, 'a fixture real do ML traz fixed_fee 0');
        $this->assertSame(PubAprovacaoComposicao::CONFIRMADO, $composicao->linha('tarifa_fixa')?->origem, 'zero MEDIDO não é Pendente');
        $this->assertSame('R$ 0,00', $porChave['tarifa_fixa']['valor']);

        // ─── (3) `null` ⇒ o TEXTO `Pendente`, em vermelho, LIDO do rótulo da origem ─────────
        $this->assertSame(PubAprovacaoComposicao::ORIGENS_DA_LINHA[PubAprovacaoComposicao::PENDENTE], $porChave['embalagem']['valor']);
        $this->assertSame('Pendente', $porChave['embalagem']['valor'], 'o texto do nulo mudou');
        $this->assertSame(PrecoDoPainelService::COR_VERMELHA, $porChave['embalagem']['valor_cor']);
        // ⚠️ E ele é o MESMO que o selo mostra — duas redações divergiriam.
        $this->assertSame($porChave['embalagem']['origem_rotulo'], $porChave['embalagem']['valor']);
        $this->assertStringNotContainsString('R$', (string) $porChave['embalagem']['valor'], 'a linha pendente mostrou um NÚMERO');

        // ─── (4) O MILHAR, em pt-BR ─────────────────────────────────────────────────────────
        [, , $caro] = $this->colunaDe(['custo' => 1234.56, 'preco' => 4000.0]);
        $doCusto = array_values(array_filter($caro['composicao']['linhas'], static fn (array $l): bool => $l['chave'] === 'custo'))[0];
        $this->assertSame("\u{2212} R$ 1.234,56", $doCusto['valor'], 'o separador de milhar em pt-BR é o PONTO');

        // ─── A NOTA DO COMBO: `N un. × R$ x = R$ y` (FIN-14), já montada pela 186 ───────────
        [, $combo, $doCombo] = $this->colunaDe(['unidades' => 3, 'custo' => 50.0]);
        $this->assertSame(150.0, $combo->linha('custo')?->valor, 'o combo multiplica o custo pelas unidades');
        $detalhe = array_values(array_filter($doCombo['composicao']['linhas'], static fn (array $l): bool => $l['chave'] === 'custo'))[0]['detalhe'];
        $this->assertSame('3 un. × R$ 50,00 = R$ 150,00', $detalhe, 'a nota do combo é a de FIN-14');
        // ⚠️ E com UMA unidade o detalhe é outro — são textos distintos de propósito.
        $this->assertSame('1 un. × R$ 200,00', $porChave['custo']['detalhe']);

        // ⚠️ ISCA DA SONDA DE BYTES, nas duas direções.
        $this->assertSame('2d', bin2hex('-'), 'a isca perdeu o sujeito: o hífen-menos é 0x2D');
        $this->assertNotSame(bin2hex('-'), bin2hex("\u{2212}"), 'os dois sinais colapsaram');
    }

    /**
     * ⚠️ DECISÃO 2 — **o preço manda o PIOR CASO** (o menor preço ativo), e a tela **NOMEIA** a
     * variação. ⚠️ **Com um preço só, SEM nota.**
     *
     * ⚠️ Os dois campos são **LIDOS** do DTO (`$precoDeVariante` / `$notaDoPreco`), nunca
     * recalculados: `ComposicaoFinanceiraService::precoDoAlvo()` já resolve o pior caso, e uma
     * segunda escolha de preço faria a coluna discordar do bloqueio que está ao lado.
     */
    public function test_o_preco_manda_o_PIOR_CASO_e_sem_nota_com_um_preco_so_188_04(): void
    {
        // ─── UM preço só (o caso comum) ⇒ SEM nota, SEM variante ────────────────────────────
        [, $uma, $comum] = $this->colunaDe([]);

        $this->assertNull($uma->notaDoPreco, 'com um preço só NÃO há nota (decisão 2)');
        $this->assertNull($uma->precoDeVariante);
        $this->assertNull($comum['preco']['nota'], 'a coluna inventou nota onde o DTO não tem');
        $this->assertNull($comum['preco']['variante']);
        $this->assertSame('R$ 489,90', $comum['preco']['valor']);

        // ─── O pior caso, montado DIRETO no DTO (o caminho de variantes é da 186, e é dela a
        //     consulta a `pub_variante_precos`; aqui prova-se que a coluna **LÊ** o que o DTO diz)
        $composicao = ComposicaoDaOferta::montar(
            preco: 180.0,
            linhas: [
                LinhaFinanceira::confirmada('custo', PubAprovacaoComposicao::LINHAS['custo'], '1 un.', 100.0, 'Precificação do Portal: custo digitado na Precificação'),
                LinhaFinanceira::confirmada('comissao', PubAprovacaoComposicao::LINHAS['comissao'], '11% do preço', 19.8, 'Mercado Livre · /sites/MLB/listing_prices'),
                LinhaFinanceira::confirmada('tarifa_fixa', PubAprovacaoComposicao::LINHAS['tarifa_fixa'], 'Não se aplica', 0.0, 'Mercado Livre · /sites/MLB/listing_prices'),
                LinhaFinanceira::confirmada('frete', PubAprovacaoComposicao::LINHAS['frete'], '1 volume', 20.0, 'Tabela de frete do produto'),
                LinhaFinanceira::confirmada('imposto', PubAprovacaoComposicao::LINHAS['imposto'], '19% do preço', 34.2, 'Parâmetros de preço da empresa no Portal'),
                LinhaFinanceira::pendente('embalagem', PubAprovacaoComposicao::LINHAS['embalagem'], 'Sem fonte no sistema', 'Nenhum cadastro informa o custo de embalagem'),
                LinhaFinanceira::confirmada('outros', PubAprovacaoComposicao::LINHAS['outros'], 'Nenhum informado', 0.0, 'Cadastro do produto'),
            ],
            comissaoPercentual: 11.0,
            impostoPercentual: 19.0,
            margemMinima: 12.0,
            margemMinimaOrigem: PubAprovacaoComposicao::MINIMO_CONFIG,
            precoDeVariante: 'cor:preto|tamanho:G',
            precosAtivos: 4,
        );

        $oferta = $this->cenario([]);
        $coluna = $this->servico()->para($oferta, $composicao);

        // ⚠️ LIDOS do DTO, byte a byte.
        $this->assertSame('cor:preto|tamanho:G', $coluna['preco']['variante']);
        $this->assertSame($composicao->notaDoPreco, $coluna['preco']['nota'], 'a nota do preço foi RECOPIADA em vez de LIDA');
        $this->assertStringContainsString('pior caso entre 4', (string) $coluna['preco']['nota'], 'a nota tem de NOMEAR quantos preços ativos existem');
        $this->assertStringContainsString('cor:preto|tamanho:G', (string) $coluna['preco']['nota'], 'a nota tem de NOMEAR a variação');
        $this->assertSame('R$ 180,00', $coluna['preco']['valor']);

        // ⚠️⚠️ DIVERGÊNCIA DE TEXTO DECLARADA, e NÃO consertada aqui: `ComposicaoDaOferta:286`
        // escreve **"variantes"** e `LinhaDaOfertaService:195` escreve **" variações · "** — as
        // DUAS estão em PRODUÇÃO e as duas são território SOMENTE LEITURA. A `188-FID01.md` §D e a
        // prosa dos planos escrevem *"pior caso entre N variações"*. ➡️ O plano manda **LER o DTO**,
        // e é o que se faz; inventar uma terceira redação seria o defeito. Fica registrado aqui e
        // na SUMMARY com `arquivo:linha`, para quem decidir unificar.
        $this->assertStringContainsString('variantes', (string) $coluna['preco']['nota'], 'o texto do DTO mudou — reconferir a divergência declarada');
        $this->assertSame(' variações · ', LinhaDaOfertaService::PIOR_CASO_SUFIXO, 'o texto da FILA mudou — reconferir a divergência declarada');
        $this->assertSame('pior caso entre ', LinhaDaOfertaService::PIOR_CASO_PREFIXO);

        // ⚠️ E `precoDoAlvo()` é o resolvedor — ele devolve o MENOR (o pior caso).
        $doAlvo = $this->financeira()->precoDoAlvo($oferta);
        $this->assertSame(['preco', 'variante', 'ativos'], array_keys($doAlvo), 'a forma de `precoDoAlvo()` mudou');
        $this->assertSame(489.90, $doAlvo['preco'], 'sem linha em `pub_variante_precos` cai no preço da Precificação');
        $this->assertSame(1, $doAlvo['ativos']);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (3) A SIMULAÇÃO — recalcula, mostra o selo, e NUNCA grava
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **CRITÉRIO 7:** a simulação **nunca é persistida nem enviada ao ML**, só é salvável com a
     * oferta `live`, e **salvar incrementa a versão, limpa o aviso de alteração e registra no
     * histórico** — e essa ESCRITA é do plano **188-06**, não daqui.
     *
     * ⚠️ O selo **"SIMULAÇÃO · NÃO SALVA"** só existe quando o digitado **difere** do salvo, com a
     * tolerância **literal** de `0,001` (`:1129`). Com `>= 0` ele apareceria com o preço IGUAL.
     */
    public function test_a_simulacao_recalcula_sem_gravar_e_o_selo_depende_da_tolerancia_188_04(): void
    {
        $oferta = $this->cenario([]);
        $servico = $this->servico();

        $antesDasLinhas = PubAprovacaoComposicao::count();
        $antesDaVersao = (int) $oferta->fresh()->versao;

        // ─── Preço DIFERENTE ⇒ simulando, com selo, resultado, margem e delta ───────────────
        $maisCaro = $servico->simular($oferta, '600,00');

        $this->assertTrue($maisCaro['simulando']);
        $this->assertSame(PrecoDoPainelService::SIMULACAO_SELO, $maisCaro['selo']);
        $this->assertSame('SIMULAÇÃO · NÃO SALVA', PrecoDoPainelService::SIMULACAO_SELO, 'o selo é LITERAL e mudou');
        $this->assertSame('R$ 600,00', $maisCaro['preco_simulado']);
        $this->assertSame('R$ 489,90', $maisCaro['preco_salvo']);
        // ⚠️ `fmtIn()` (`:1049`): o que volta para o campo é pt-BR e SEM separador de milhar.
        $this->assertSame('600,00', $maisCaro['preenche']);
        $this->assertStringContainsString('R$ ', (string) $maisCaro['resultado']);
        $this->assertStringContainsString('%', (string) $maisCaro['margem']);
        // ⚠️ `:1136` — o delta positivo leva `'+ '` e o sufixo `' vs. salvo'`.
        $this->assertStringStartsWith(PrecoDoPainelService::DELTA_PREFIXO_POSITIVO, (string) $maisCaro['delta']);
        $this->assertStringEndsWith(PrecoDoPainelService::DELTA_SUFIXO, (string) $maisCaro['delta']);
        $this->assertSame(' vs. salvo', PrecoDoPainelService::DELTA_SUFIXO, 'o sufixo do delta mudou');
        $this->assertSame(PrecoDoPainelService::COR_VERDE, $maisCaro['delta_cor']);

        // ─── Preço MAIS BARATO ⇒ delta NEGATIVO, em vermelho, com U+2212 ────────────────────
        $maisBarato = $servico->simular($oferta, '300,00');
        $this->assertTrue($maisBarato['simulando']);
        $this->assertSame(PrecoDoPainelService::COR_VERMELHA, $maisBarato['delta_cor']);
        $this->assertStringContainsString("\u{2212} R$ ", (string) $maisBarato['delta'], 'o delta negativo tem de usar U+2212');
        $this->assertStringEndsWith(PrecoDoPainelService::DELTA_SUFIXO, (string) $maisBarato['delta']);

        // ─── ⚠️⚠️ A TOLERÂNCIA: preço IGUAL ao salvo ⇒ NEUTRO, sem selo, sem salvar ─────────
        $igual = $servico->simular($oferta, '489,90');
        $this->assertFalse($igual['simulando'], 'com `>= 0` no lugar de `> 0,001` ESTA linha reprova');
        $this->assertNull($igual['selo'], 'o selo apareceu com o preço IGUAL ao salvo');
        $this->assertFalse($igual['pode_salvar'], 'pode_salvar verdadeiro para salvar o que JÁ está salvo (versão++ por nada)');
        $this->assertSame('', $igual['delta'], 'a tela não desenha texto de delta vazio');

        // Dentro da tolerância (1 décimo de centavo) ⇒ ainda NÃO é simulação.
        $this->assertFalse($servico->simular($oferta, '489,9005')['simulando'], 'a tolerância de 0,001 deixou de valer');
        // ⚠️ Fora dela (1 centavo) ⇒ É simulação. A isca nas DUAS direções.
        $this->assertTrue($servico->simular($oferta, '489,91')['simulando'], 'a tolerância ficou LARGA e engoliu um centavo');
        $this->assertSame(0.001, PrecoDoPainelService::TOLERANCIA_DO_SIMULADO, 'a tolerância literal do protótipo mudou');

        // ─── ⚠️⚠️ NADA FOI GRAVADO, E NADA SUBIU AO MERCADO LIVRE ──────────────────────────
        $this->assertSame($antesDasLinhas, PubAprovacaoComposicao::count(), 'a simulação CONGELOU composição — ela só pode LER');
        $this->assertSame($antesDaVersao, (int) $oferta->fresh()->versao, 'a simulação incrementou a VERSÃO — quem faz isso é o 188-06');
        $this->assertNull($oferta->fresh()->ml_item_id, 'a simulação publicou no ML — a escrita é da Fase 193');

        // ⚠️ **ZERO escrita no Mercado Livre:** as ÚNICAS requisições são de LEITURA de tarifa.
        $escritas = array_values(array_filter(
            $this->chamadas,
            static fn (array $c): bool => $c['caminho'] !== '/sites/MLB/listing_prices',
        ));
        $this->assertSame([], $escritas, 'saiu requisição que NÃO é a leitura de tarifa: o painel APROVA, não publica');
        // ⚠️ ISCA DO ESPIÃO: ele PEGA requisição. Sem isto, "[]" seria sonda cega.
        $this->assertGreaterThan(0, $this->requisicoesDeTarifa(), 'o espião não registrou NADA — ele está cego');

        // ⚠️ **T-188-33 — no máximo UMA requisição por trio `(categoria, tipo, preço)` distinto.**
        // Foram simulados 489,90 (= o salvo), 600, 300, 489,9005 e 489,91: o cache por centavos
        // garante que o preço repetido não gere requisição nova.
        $porPreco = [];
        foreach ($this->chamadas as $c) {
            // ⚠️ Flag DENTRO do laço.
            if ($c['caminho'] === '/sites/MLB/listing_prices') {
                $chave = ($c['query']['category_id'] ?? '').'|'.($c['query']['listing_type_id'] ?? '').'|'.(int) round(((float) ($c['query']['price'] ?? 0)) * 100);
                $porPreco[$chave] = ($porPreco[$chave] ?? 0) + 1;
            }
        }
        $repetidos = array_keys(array_filter($porPreco, static fn (int $n): bool => $n > 1));
        $this->assertSame([], $repetidos, 'o MESMO trio (categoria, tipo, preço) foi consultado mais de uma vez');
    }

    /**
     * ⚠️⚠️ O preço vem da **digitação da pessoa**: `0`, negativo, vazio, texto **e array** caem no
     * estado **neutro**, **sem exceção**. `(float) []` é a família do `(int) []` que já foi **500**
     * nesta tela — e a guarda de tipo vem **ANTES** de qualquer cast (REND-03).
     *
     * ⚠️ `pode_salvar` exige **OS DOIS EIXOS**: simulação ativa **E** oferta `live` (`:1142`).
     */
    public function test_os_precos_invalidos_caem_no_neutro_sem_excecao_e_pode_salvar_exige_live_188_04(): void
    {
        $oferta = $this->cenario([]);
        $servico = $this->servico();

        // ─── Os invalidos, um por um, COLETANDO os infratores ───────────────────────────────
        $invalidos = [
            'zero' => 0,
            'zero_texto' => '0',
            'zero_reais' => 'R$ 0,00',
            'negativo' => -10.0,
            'negativo_texto' => '-10,00',
            'vazio' => '',
            'so_espaco' => '   ',
            'texto' => 'de graça',
            'array' => [],
            'array_com_valor' => ['100'],
            'nulo' => null,
            'booleano' => true,
            'objeto' => new \stdClass,
            'nan_texto' => 'NaN',
        ];

        $vazaram = [];
        foreach ($invalidos as $rotulo => $bruto) {
            // ⚠️ Flag DENTRO do laço.
            $estado = $servico->simular($oferta, $bruto);
            if ($estado['simulando'] !== false || $estado['pode_salvar'] !== false || $estado['delta'] !== '' || $estado['selo'] !== null) {
                $vazaram[] = $rotulo;
            }
        }
        $this->assertSame([], $vazaram, 'preço inválido virou simulação: '.implode(', ', $vazaram));

        // ⚠️ E o saneador devolve `null` em todos eles, SEM lançar.
        $saneados = [];
        foreach ($invalidos as $rotulo => $bruto) {
            // ⚠️ Flag DENTRO do laço.
            if (PrecoDoPainelService::sanear($bruto) !== null) {
                $saneados[] = $rotulo;
            }
        }
        $this->assertSame([], $saneados, 'o saneador aceitou valor inválido: '.implode(', ', $saneados));

        // ⚠️⚠️ ISCA DO SANEADOR, nas DUAS direções — o que ele **TEM** de aceitar.
        $this->assertSame(489.9, PrecoDoPainelService::sanear('489,90'), 'o saneador recusa o formato pt-BR');
        $this->assertSame(1234.56, PrecoDoPainelService::sanear('1.234,56'), 'o saneador não tira o separador de MILHAR');
        $this->assertSame(1234.56, PrecoDoPainelService::sanear('R$ 1.234,56'), 'o saneador não tolera o prefixo R$');
        $this->assertSame(600.0, PrecoDoPainelService::sanear(600), 'o saneador recusa int');
        $this->assertSame(600.5, PrecoDoPainelService::sanear(600.5), 'o saneador recusa float');
        $this->assertSame(123.0, PrecoDoPainelService::sanear('123'), 'o saneador recusa inteiro em texto');
        // ⚠️ `num()` (`:1048`) só aceita `> 0`: é a régua, não um detalhe.
        $this->assertNull(PrecoDoPainelService::sanear('0,00'));

        // ⚠️⚠️ **DIVERGÊNCIA (3), MEDIDA NESTA WAVE:** o `num()` do protótipo tira o `-` **ANTES**
        // do `> 0`, então `'-10,00'` volta como **`10.0`** — este caso reprovou de verdade com
        // *"preço inválido virou simulação: negativo_texto"*, e o `> 0` dele só pega número
        // negativo, que um campo de TEXTO nunca produz. ➡️ O produto **RECUSA**: trocar `-10,00`
        // por `10,00` em silêncio simularia um preço **que a pessoa não digitou** e ofereceria
        // *"Salvar no anúncio"* sobre ele — e salvar troca o preço de verdade, sem desfazer.
        // ⚠️ É o que o `<behavior>` do plano e o **T-188-30** pedem, os dois, e é validação de
        // entrada numa fronteira de confiança (a `188-FID01.md` §3 dá ao protótipo a FÓRMULA, os
        // RÓTULOS e a ORDEM — não isto).
        $this->assertNull(PrecoDoPainelService::sanear('-10,00'), 'o menos foi ENGOLIDO: `-10,00` virou `10,00`');
        // ⚠️ E o U+2212 também — é o sinal que o próprio `brl()` imprime, logo o que a pessoa cola
        // de volta no campo.
        $this->assertNull(PrecoDoPainelService::sanear("\u{2212} R$ 10,00"), 'o U+2212 foi engolido');
        // ⚠️ ISCA DO RECORTE, nas duas direções: sem o menos, o MESMO texto é aceito.
        $this->assertSame(10.0, PrecoDoPainelService::sanear('R$ 10,00'), 'a recusa ficou LARGA e engoliu o valor legítimo');
        // ⚠️ E o `+` continua sendo TIRADO: ele não muda o valor que a pessoa quis dizer.
        $this->assertSame(10.0, PrecoDoPainelService::sanear('+10,00'), 'o `+` passou a recusar — ele não muda o valor');

        // ─── ⚠️ `pode_salvar`: OS DOIS EIXOS ───────────────────────────────────────────────
        $this->assertTrue($oferta->ehLive(), 'FIXTURE QUE DISCRIMINA: a oferta precisa estar `live` aqui');
        $this->assertTrue($servico->simular($oferta, '600,00')['pode_salvar'], 'no `live`, com simulação ativa, tem de poder salvar');
        $this->assertFalse($servico->simular($oferta, '489,90')['pode_salvar'], 'eixo 1: sem simulação ativa NÃO se salva');

        // Fora do `live` (aprovado / publicando / preparando) ⇒ NUNCA salva, nem simulando.
        $foraDoLive = [];
        foreach ([PubAprovacaoOferta::APROVADO, PubAprovacaoOferta::PUBLICANDO, PubAprovacaoOferta::PREPARANDO] as $andamento) {
            // ⚠️ Flag DENTRO do laço.
            $outra = $this->cenario(['andamento' => $andamento]);
            $este = $this->servico();
            $this->assertFalse($outra->ehLive(), "FIXTURE QUE DISCRIMINA: `{$andamento}` não pode ser live");
            if ($este->simular($outra, '600,00')['pode_salvar'] !== false) {
                $foraDoLive[] = $andamento;
            }
        }
        $this->assertSame([], $foraDoLive, 'eixo 2: fora do `live` NÃO se salva — '.implode(', ', $foraDoLive));

        // ⚠️ COL-04 — a versão viaja, para a tela detectar que a oferta mudou debaixo de quem revisa.
        $comVersao = $this->cenario(['versao' => 7]);
        $this->assertSame(7, $this->servico()->simular($comVersao, '600,00')['versao']);

        // ⚠️ E as CHAVES que o plano 188-06 declarou ler daqui existem TODAS — contrato entre waves.
        $doPlano06 = ['resultado', 'resultado_cor', 'margem', 'margem_cor', 'delta', 'delta_cor', 'simulando', 'pode_salvar', 'versao'];
        $faltando = array_values(array_diff($doPlano06, array_keys($servico->simular($oferta, '600,00'))));
        $this->assertSame([], $faltando, 'o transporte do 188-06 pediria chave que não existe: '.implode(', ', $faltando));
        // ⚠️ E elas existem TAMBÉM no estado neutro: a rota devolve a mesma forma sempre.
        $faltandoNeutro = array_values(array_diff($doPlano06, array_keys($servico->simular($oferta, 'de graça'))));
        $this->assertSame([], $faltandoNeutro, 'o neutro tem forma DIFERENTE — o cliente quebraria em metade das respostas');
    }

    /**
     * ⚠️⚠️ **ESTE SERVIÇO NÃO ESCREVE NADA, E NÃO CONGELA.**
     *
     * ⚠️ A sonda ancora no **OPERADOR DE CHAMADA**, não na palavra: `salvar` é legítimo em
     * `pode_salvar` e `SIMULACAO_SALVAR`, e um gate que procurasse a palavra reprovaria o rótulo
     * do botão. ⚠️ **E o limite do detector é o `;` do statement, não o `]`** — `updateOrCreate`
     * põe o campo no **SEGUNDO** array e `[^\]]` **não atravessa** o `]` que fecha o primeiro.
     */
    public function test_o_servico_de_preco_nao_escreve_nada_e_nao_congela_188_04(): void
    {
        $rel = 'app/Services/Publicador/Aprovacao/Painel/PrecoDoPainelService.php';
        $doServico = $this->semComentarios($rel);

        $this->assertNotSame('', trim($doServico), 'o leitor devolveu vazio — o gate mediria NADA');

        // ⚠️ Ancorada no OPERADOR DE CHAMADA / na ATRIBUIÇÃO, nunca na palavra.
        $sondaDeEscrita = '/->(?:save|update|updateOrCreate|fill|forceFill|delete|insert|push)\s*\('
            .'|::(?:create|updateOrCreate|insert|upsert|firstOrCreate)\s*\('
            .'|DB::table\s*\([^;]*->\s*(?:update|insert|delete)\s*\('
            .'|->(?:preco|andamento|versao|preco_alterado)\s*=(?!=)/';

        $this->assertSame(0, preg_match($sondaDeEscrita, $doServico), 'o serviço do preço ESCREVE — ele só pode LER');

        // ⚠️⚠️ ISCA DA SONDA, uma por FORMA que ela DEVE pegar e uma que NÃO pode pegar.
        $devePegar = [
            '$o->save();',
            '$o->update([1]);',
            "\$o->updateOrCreate(['a' => 1], ['preco' => 2]);",
            'PubAprovacaoComposicao::create([1]);',
            "PubAprovacaoComposicao::updateOrCreate(['oferta_id' => 1, 'versao' => 2], ['preco' => 3]);",
            "DB::table('x')->where('a', 1)->update(['preco' => 2]);",
            "\$o->preco = 1;",
            "\$o->versao = 2;",
            '$o->delete();',
        ];
        $cegas = [];
        foreach ($devePegar as $forma) {
            // ⚠️ Flag DENTRO do laço.
            if (preg_match($sondaDeEscrita, $forma) !== 1) {
                $cegas[] = $forma;
            }
        }
        $this->assertSame([], $cegas, 'a sonda de escrita está CEGA para: '.implode(' | ', $cegas));

        $naoPodePegar = [
            "return ['pode_salvar' => true];",
            "'rotulo_salvar' => self::SIMULACAO_SALVAR,",
            "\$v = \$o->preco == 1;",
            "\$x = \$o->versao;",
            "return \$composicao->preco <= 0.0;",
        ];
        $largas = [];
        foreach ($naoPodePegar as $forma) {
            // ⚠️ Flag DENTRO do laço.
            if (preg_match($sondaDeEscrita, $forma) !== 0) {
                $largas[] = $forma;
            }
        }
        $this->assertSame([], $largas, 'a sonda de escrita está LARGA e pega: '.implode(' | ', $largas));

        // ⚠️⚠️ **E NENHUMA chamada a `congelar()`** — quem grava a composição é o ato de aprovação.
        $this->assertStringNotContainsString('congelar', $doServico, 'o serviço do preço CONGELA composição');
        // ⚠️ ISCA: o método existe de verdade no serviço da 186, então a ausência tem SUJEITO.
        $this->assertStringContainsString(
            'public function congelar(',
            $this->cru('app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php'),
            'a isca perdeu o sujeito: `congelar()` mudou de nome',
        );

        // ⚠️ E a FRONTEIRA está DECLARADA no docblock (é o que impede o próximo executor de
        // "completar" a função). ⚠️ Aqui o docblock É o artefato sob teste, e a inversão é
        // deliberada — leitura CRUA, de propósito, como na wave 188-03.
        $comComentario = $this->cru($rel);
        foreach (['188-06', 'registrarPrecoSalvo', 'Fase 193', 'ResumoRapidoService'] as $declarado) {
            // ⚠️ Flag DENTRO do laço.
            $this->assertStringContainsString($declarado, $comComentario, "a fronteira `{$declarado}` saiu do docblock");
        }
        // ⚠️⚠️ O caminho da OUTRA margem está certo: `Fila/`, **NÃO** `Aprovacao/Fila/`.
        $this->assertStringContainsString('app/Services/Publicador/Fila/ResumoRapidoService.php', $comComentario, 'o caminho da outra margem está errado — confundi-lo APAGARIA a decisão 9');
        $this->assertFileExists($this->raiz().'/app/Services/Publicador/Fila/ResumoRapidoService.php', 'ANTI-FANTASMA: o caminho citado não existe');
        $this->assertStringNotContainsString('Aprovacao/Fila/ResumoRapidoService', $comComentario, 'o caminho FANTASMA entrou no docblock');

        // ⚠️ E as DUAS margens NÃO foram unificadas: o parâmetro da Precificação não aparece aqui.
        $this->assertStringNotContainsString('margem_contribuicao', $doServico, 'o serviço leu o parâmetro da Precificação: são perguntas DIFERENTES');
        $this->assertStringContainsString(
            'margem_contribuicao',
            $this->cru('app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php'),
            'a isca perdeu o sujeito: o campo do parâmetro mudou de nome',
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (4) OS ATALHOS, A EXCEÇÃO, A IRMÃ — e o território
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **Equilíbrio e preço-alvo só aparecem quando TODOS os dados existem** (`hasBE = !f.missing`,
     * `:1134`); faltando, vem a frase do indisponível (`:685`).
     *
     * ⚠️ O `12%` do protótipo (`:682`) é o `MIN` (`:914`), **não literal** — provado com um mínimo
     * diferente de 12. ⚠️ E `onSimTarget` preenche **`ceil(alvo) − 0,1`** (⚠️ **`:1140`**, e não o
     * `:1139` que a prosa do plano cita — `:1139` é o `onSimBE`; medido por `sed` nesta wave).
     */
    public function test_os_atalhos_so_existem_com_dado_completo_e_o_minimo_e_o_REAL_188_04(): void
    {
        // ─── COMPLETO ⇒ os DOIS atalhos, e nenhuma frase de indisponível ────────────────────
        [, $completa, $coluna] = $this->colunaDe(['minimo' => 12.0]);

        $this->assertFalse($completa->incompleto, 'FIXTURE QUE DISCRIMINA: tem de estar completa');
        $this->assertSame(
            [PrecoDoPainelService::ATALHO_EQUILIBRIO, PrecoDoPainelService::ATALHO_MARGEM_MINIMA],
            array_column($coluna['atalhos'], 'chave'),
            'os DOIS atalhos, nesta ordem (`:681` antes de `:682`)',
        );
        $this->assertNull($coluna['atalhos_indisponivel'], 'com dado completo NÃO há frase de indisponível');

        // ⚠️ Os rótulos, byte a byte, com os números do DTO.
        $this->assertNotNull($completa->equilibrio);
        $this->assertNotNull($completa->precoAlvo);
        $this->assertStringStartsWith(PrecoDoPainelService::ATALHO_EQUILIBRIO_PREFIXO, (string) $coluna['atalhos'][0]['rotulo']);
        $this->assertSame('Equilíbrio · ', PrecoDoPainelService::ATALHO_EQUILIBRIO_PREFIXO, 'o prefixo do equilíbrio mudou');
        $this->assertStringContainsString('12%', (string) $coluna['atalhos'][1]['rotulo'], 'com mínimo 12, o rótulo diz 12%');
        $this->assertStringStartsWith(PrecoDoPainelService::ATALHO_MINIMO_PREFIXO, (string) $coluna['atalhos'][1]['rotulo']);

        // ─── ⚠️⚠️ O MÍNIMO É O REAL: com 17,5 o rótulo acompanha e o `12%` cravado REPROVA ──
        [, $com175, $outro] = $this->colunaDe(['minimo' => 17.5]);
        $this->assertSame(17.5, (float) $com175->margemMinima);
        $this->assertStringContainsString('17,5%', (string) $outro['atalhos'][1]['rotulo'], 'com o `12%` cravado, ESTA linha reprova');
        $this->assertStringNotContainsString('12%', (string) $outro['atalhos'][1]['rotulo']);
        // ⚠️ E o mínimo tem UMA casa: o resolvedor da 186 (FIN-09), nunca uma segunda configuração.
        $this->assertSame(
            ['valor', 'origem'],
            array_keys($this->financeira()->margemMinimaComOrigem((int) $com175->margemMinima > 0 ? null : null)),
            'a forma de `margemMinimaComOrigem()` mudou',
        );
        $this->assertSame(PubAprovacaoComposicao::ORIGENS_DO_MINIMO[$com175->margemMinimaOrigem], $outro['kpis'][2]['minimo_origem'], 'de qual lado do fallback o mínimo veio tem de viajar');

        // ─── ⚠️⚠️ `ceil(alvo) − 0,1`: contraintuitivo e FÁCIL DE OMITIR (`:1140`) ───────────
        $alvo = (float) $completa->precoAlvo;
        $this->assertSame(
            PrecoDoPainelService::digitavel(ceil($alvo) - 0.1),
            $coluna['atalhos'][1]['preenche'],
            '`:1140` preenche `ceil(alvo) − 0,1`, NÃO o alvo',
        );
        $this->assertNotSame(
            PrecoDoPainelService::digitavel($alvo),
            $coluna['atalhos'][1]['preenche'],
            'o atalho preencheu o alvo cru — o ajuste de `:1140` foi omitido',
        );
        // ⚠️ E o terminal é `,90` por construção (o inteiro de cima menos 10 centavos).
        $this->assertStringEndsWith(',90', (string) $coluna['atalhos'][1]['preenche']);
        // ⚠️ O atalho de EQUILÍBRIO **NÃO** leva esse ajuste (`:1139`) — são dois comportamentos.
        $this->assertSame(PrecoDoPainelService::digitavel((float) $completa->equilibrio), $coluna['atalhos'][0]['preenche']);

        // ⚠️ `fmtIn()` é pt-BR e **sem** separador de milhar (o campo o removeria de novo).
        $this->assertSame('1234,56', PrecoDoPainelService::digitavel(1234.56), '`fmtIn()` (`:1049`) não leva milhar');
        $this->assertSame('489,90', PrecoDoPainelService::digitavel(489.9));

        // ─── INCOMPLETO ⇒ nenhum atalho, e a frase literal de `:685` ────────────────────────
        [, $semFrete, $sem] = $this->colunaDe(['frete' => null]);
        $this->assertTrue($semFrete->incompleto);
        $this->assertSame([], $sem['atalhos'], 'equilíbrio e preço-alvo só existem com TODOS os dados');
        $this->assertSame('Preço de equilíbrio indisponível até o frete ser confirmado.', $sem['atalhos_indisponivel']);
        $this->assertSame(PrecoDoPainelService::EQUILIBRIO_INDISPONIVEL, $sem['atalhos_indisponivel']);

        // ─── O AVISO DE ALTERAÇÃO (`:649`-`:651` / `:1310`) ─────────────────────────────────
        $this->assertNull($coluna['alteracao'], 'sem aviso gravado, nenhuma caixa de alteração');
        [, , $alterada] = $this->colunaDe(['preco_alterado' => [
            'de' => 520.0, 'para' => 489.9, 'quem' => 'Ana Ribeiro', 'quando' => '18/10/2026 14:02',
        ]]);
        $this->assertNotNull($alterada['alteracao']);
        $this->assertSame(PrecoDoPainelService::ALTERACAO_GLIFO, $alterada['alteracao']['glifo']);
        $this->assertSame('↻', PrecoDoPainelService::ALTERACAO_GLIFO);
        // ⚠️ O texto do PAINEL (`:1310`) traz quem e quando; o do BLOQUEIO (`:1095`) não. Dois
        // textos de propósito.
        $this->assertSame(
            'Preço alterado por Ana Ribeiro · 18/10/2026 14:02: R$ 520,00 → R$ 489,90',
            $alterada['alteracao']['texto'],
        );
        // ⚠️ O rótulo da correção é LIDO do vocabulário travado da 186.
        $this->assertSame(
            VocabularioDoPortao::CORRECOES[VocabularioDoPortao::ALTERACAO_NAO_CONFERIDA],
            $alterada['alteracao']['correcao'],
        );
        $this->assertSame('Conferir alteração', $alterada['alteracao']['correcao'], 'o rótulo mudou de casa na 186');
        // ⚠️ Aviso sem autor **não mente**: a frase diz que o registro não tem autor.
        [, , $semAutor] = $this->colunaDe(['preco_alterado' => ['de' => 1.0, 'para' => 2.0]]);
        $this->assertStringContainsString(PrecoDoPainelService::ALTERACAO_SEM_AUTOR, (string) $semAutor['alteracao']['texto']);
        $this->assertStringNotContainsString('por : ', (string) $semAutor['alteracao']['texto'], 'a frase saiu com "por" seguido de nada');
        // ⚠️ Aviso MALFORMADO (sem `de`/`para`) não derruba a coluna — REND-03.
        [, , $torto] = $this->colunaDe(['preco_alterado' => ['quem' => 'X']]);
        $this->assertNull($torto['alteracao'], 'aviso sem `de`/`para` tem de ser ignorado, não explodir');
    }

    /**
     * ⚠️⚠️ **A EXCEÇÃO LIBERA SÓ DOIS** (`PubAprovacaoExcecao::LIBERA`, lista FECHADA), e **"exceção
     * aguardando autorização" NÃO EXISTE** (decisão 6): ela só **REGISTRA quem liberou**.
     *
     * ⚠️ A irmã é **CLICÁVEL** (REVS-11 / FIN-13), e a ordem é **Clássico antes de Premium**
     * (⚠️ **`:1294`**, e não o `:1292` que a prosa do plano cita — `:1292` é o `.map()` das
     * imagens; medido por `sed` nesta wave).
     */
    public function test_a_excecao_libera_SO_dois_e_a_irma_e_clicavel_na_ordem_certa_188_04(): void
    {
        $empresa = Company::factory()->create();
        $quem = User::factory()->create(['name' => 'Rafael Nunes']);

        // ─── A IRMÃ: o par estrutural é o MESMO `rascunho_id` ───────────────────────────────
        // ⚠️ O PREMIUM é criado PRIMEIRO de propósito: se a ordem viesse de `orderBy('id')` ou da
        // ordem de criação, ele apareceria antes — e `:1294` manda o Clássico primeiro.
        $premium = $this->cenario(['company' => $empresa, 'listing_type' => self::PREMIUM]);
        $rascunho = $premium->rascunho;
        $this->comTarifaReal('MLB189007', self::CLASSICO);

        $classicoAlvo = PubRascunhoAlvo::create([
            'rascunho_id' => $rascunho->id,
            'listing_type_id' => self::CLASSICO,
            'titulo' => 'Poltrona do preço Veludo',
            'posicao' => 0,
        ]);
        $classico = PubAprovacaoOferta::create([
            'alvo_id' => $classicoAlvo->id,
            'rascunho_id' => $rascunho->id,
            'produto_id' => $premium->produto_id,
            'company_id' => $empresa->id,
            'andamento' => PubAprovacaoOferta::AGUARDANDO_APROVACAO,
            'entrou_na_fila_em' => '2026-10-19 06:00:00',
        ])->fresh(['alvo', 'produto', 'rascunho']);

        // ⚠️ O dublê de preço cobre os DOIS tipos (o Clássico foi criado fora do `cenario()`).
        $this->efetivos->osPrecos = ['precos' => [self::PREMIUM => 489.90, self::CLASSICO => 420.0]];
        $this->efetivos->osFretes = ['por_tipo' => [
            self::PREMIUM => ['valor' => 42.9, 'origem' => 'tabela', 'oferta_id' => null],
            self::CLASSICO => ['valor' => 42.9, 'origem' => 'tabela', 'oferta_id' => null],
        ], 'por_variante' => []];

        $financeira = $this->financeira();
        $servico = new PrecoDoPainelService($financeira);

        $doPremium = $servico->para($premium, $financeira->para($premium));

        // ⚠️⚠️ A ORDEM: Clássico primeiro, **mesmo tendo sido criado DEPOIS**.
        $this->assertCount(2, $doPremium['comparacao'], 'a grade de `:688` lista as DUAS modalidades');
        $this->assertSame(
            [EstruturaAnuncio::TIPOS[EstruturaAnuncio::TIPO_CLASSICO], EstruturaAnuncio::TIPOS[EstruturaAnuncio::TIPO_PREMIUM]],
            array_column($doPremium['comparacao'], 'rotulo'),
            'a ordem é Clássico → Premium (`:1294`), e o Clássico foi criado DEPOIS',
        );
        $this->assertSame(['Clássico', 'Premium'], array_column($doPremium['comparacao'], 'rotulo'), 'os dois rótulos mudaram de casa');
        $this->assertLessThan((int) $classico->id, (int) $premium->id, 'FIXTURE QUE DISCRIMINA: o Premium tem de ter id MENOR, senão `orderBy(id)` passaria por acidente');

        // ⚠️ O losango do Premium é um DADO, não uma classe.
        $this->assertSame([false, true], array_column($doPremium['comparacao'], 'premium'));

        // ⚠️ A célula ATUAL está marcada e **NÃO navega para si mesma** (`:1294`: `x.id !== o.id`).
        $atuais = array_values(array_filter($doPremium['comparacao'], static fn (array $c): bool => $c['atual']));
        $this->assertCount(1, $atuais, 'uma e só uma célula é a atual');
        $this->assertSame((int) $premium->id, $atuais[0]['oferta_id']);
        $this->assertNull($atuais[0]['href'], 'a célula atual não pode navegar para si mesma');

        // ─── ⚠️ A IRMÃ É CLICÁVEL, e o `href` é o `?oferta=` que o 188-02 já atende ─────────
        $this->assertNotNull($doPremium['irma']);
        $this->assertSame((int) $classico->id, $doPremium['irma']['oferta_id']);
        $this->assertSame('?oferta='.$classico->id, $doPremium['irma']['href'], 'REVS-11/FIN-13: a comparação é CLICÁVEL');
        $this->assertFalse($doPremium['irma']['atual']);
        // ⚠️ E ela traz rótulo, preço, resultado, margem e ANDAMENTO.
        foreach (['rotulo', 'preco', 'resultado', 'resultado_cor', 'margem', 'margem_cor', 'andamento_da_irma', 'rotulo_do_andamento'] as $campo) {
            // ⚠️ Flag DENTRO do laço.
            $this->assertArrayHasKey($campo, $doPremium['irma'], "a irmã saiu sem `{$campo}`");
        }
        $this->assertSame('R$ 420,00', $doPremium['irma']['preco'], 'a irmã tem de trazer o preço DELA, não o desta oferta');
        $this->assertSame((string) $classico->andamento, $doPremium['irma']['andamento_da_irma']);
        $this->assertSame($classico->rotulo(), $doPremium['irma']['rotulo_do_andamento'], 'o rótulo do andamento é LIDO do modelo');
        // ⚠️ `irma` é DERIVADA de `comparacao`: as duas chaves nunca podem discordar.
        $this->assertSame($doPremium['irma'], $doPremium['comparacao'][0], 'a irmã divergiu da própria grade');

        // ⚠️ E `comparacaoComAIrma()` é CHAMADO — ele parte do MESMO `rascunho_id`, e é isso que
        // impede o `href` de apontar para outra conta.
        $daIrma = $financeira->comparacaoComAIrma($premium);
        $this->assertNotNull($daIrma);
        $this->assertSame((int) $classico->id, (int) $daIrma['oferta_id']);

        // ─── SEM irmã ⇒ `irma` é `null` e a grade tem UMA célula ────────────────────────────
        $sozinha = $this->cenario([]);
        $financeiraSozinha = $this->financeira();
        $daSozinha = (new PrecoDoPainelService($financeiraSozinha))->para($sozinha, $financeiraSozinha->para($sozinha));
        $this->assertNull($daSozinha['irma'], 'sem irmã, `null` — nunca zeros, que a tela leria como verdade');
        $this->assertCount(1, $daSozinha['comparacao']);
        $this->assertTrue($daSozinha['comparacao'][0]['atual']);

        // ═══ ⚠️⚠️ A EXCEÇÃO ══════════════════════════════════════════════════════════════════
        $semExcecao = $servico->para($classico, $financeira->para($classico));
        $this->assertNull($semExcecao['excecao'], 'sem exceção vigente, `null`');

        PubAprovacaoExcecao::create([
            'oferta_id' => $classico->id,
            'tipo' => PubAprovacaoExcecao::RESULTADO_NEGATIVO,
            'justificativa' => 'Campanha de liquidação aprovada pela diretoria',
            'liberada_por' => $quem->id,
            'liberada_em' => '2026-10-18 16:30:00',
            // ⚠️ `versao` é **NOT NULL** no schema (DADO-13: a auditoria-depois precisa saber em
            // QUE versão a exceção foi dada). Fixture sem ela estoura na inserção — medido.
            'versao' => 1,
            'ativa' => true,
        ]);

        $comExcecao = $servico->para($classico->fresh(['alvo', 'produto', 'rascunho']), $financeira->para($classico));
        $this->assertNotNull($comExcecao['excecao']);
        // ⚠️ A frase é LIDA de `fraseDeExibicao()` — a FORMA de `:1142` com o glifo `§`.
        $this->assertStringStartsWith('§ Exceção autorizada por Rafael Nunes · 18/10/2026 16:30 · ', (string) $comExcecao['excecao']);
        $this->assertStringContainsString('Campanha de liquidação aprovada pela diretoria', (string) $comExcecao['excecao']);

        // ⚠️⚠️ **"EXCEÇÃO AGUARDANDO AUTORIZAÇÃO" NÃO EXISTE** — ancorado no SUJEITO, com acentos.
        $doServico = $this->semComentarios('app/Services/Publicador/Aprovacao/Painel/PrecoDoPainelService.php');
        foreach (['aguardando autoriza', 'aguardando aprova', 'pendente de autoriza'] as $inexistente) {
            // ⚠️ Flag DENTRO do laço.
            $this->assertStringNotContainsString($inexistente, mb_strtolower($doServico), "decisão 6: \"{$inexistente}\" não existe");
        }

        // ⚠️⚠️ **ELA LIBERA SÓ DOIS, E OS OUTROS SEIS SÃO PROVADOS UM POR UM.**
        $this->assertSame(
            [PubAprovacaoExcecao::RESULTADO_NEGATIVO, PubAprovacaoExcecao::MARGEM_ABAIXO_DO_MINIMO],
            PubAprovacaoExcecao::LIBERA,
            'a lista FECHADA mudou: "permitido a mais" aqui significa publicar o que ninguém conferiu',
        );
        $liberados = [];
        foreach ([
            VocabularioDoPortao::FOTOS_A_CONFERIR,
            VocabularioDoPortao::SEM_FOTOS,
            VocabularioDoPortao::FRETE_AUSENTE,
            VocabularioDoPortao::ALTERACAO_NAO_CONFERIDA,
            VocabularioDoPortao::AJUSTE_EM_ABERTO,
            VocabularioDoPortao::TITULO_LONGO,
        ] as $jamais) {
            // ⚠️ Flag DENTRO do laço.
            if (PubAprovacaoExcecao::libera($jamais)) {
                $liberados[] = $jamais;
            }
        }
        $this->assertSame([], $liberados, 'a exceção liberou o que NUNCA pode liberar: '.implode(', ', $liberados));
        // ⚠️ E os DOIS que ela libera, de verdade — isca nas duas direções.
        $this->assertTrue(PubAprovacaoExcecao::libera(VocabularioDoPortao::RESULTADO_NEGATIVO));
        $this->assertTrue(PubAprovacaoExcecao::libera(VocabularioDoPortao::MARGEM_ABAIXO_DO_MINIMO));
        // ⚠️ As chaves INGLESAS do protótipo são `false`: não há `||` aceitando as duas formas.
        $this->assertFalse(PubAprovacaoExcecao::libera('loss'));
        $this->assertFalse(PubAprovacaoExcecao::libera('margin'));
    }

    /**
     * ⚠️ TERRITÓRIO (por **ESCOPO DO ASSUNTO**, nunca por range cru nem por autor) e **A BIJEÇÃO
     * ids ↔ métodos nos DOIS sentidos**, com o homônimo conferido contra as Fases 186 **E** 187.
     *
     * ⚠️⚠️ **O SUFIXO `_188_04` NÃO É ZELO.** O `--filter` do PHPUnit é regex **NÃO ANCORADO**, e
     * método **superstring** de uma fase em produção faz o id daquela fase **SOMAR** e reprovar.
     */
    public function test_territorio_e_bijecao_do_manifesto_do_preco_188_04(): void
    {
        // ═══ (A) TERRITÓRIO — a lista CONSTANTE dos arquivos DESTE plano, nunca `app/` inteiro ══
        $meus = [
            'app/Services/Publicador/Aprovacao/Painel/PrecoDoPainelService.php',
            'app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php',
        ];

        $fonte = '';
        foreach ($meus as $rel) {
            // ⚠️ Flag DENTRO do laço.
            $fonte .= $this->semComentarios($rel);
        }

        // ⚠️ ISCA DO RECORTE: a lista não está vazia e cada entrada EXISTE no disco.
        $this->assertCount(2, $meus);
        $this->assertNotSame('', trim($fonte), 'o leitor devolveu vazio — o gate mediria NADA');

        // ─── ⚠️ VOC-03: "lucro líquido" é PROIBIDO, e o recorte é desta lista ───────────────
        // ⚠️ `lucro_liquido` é campo **LEGÍTIMO** do Portal (`EstruturaPrecificacaoParametros`), e
        // varrer `app/` inteiro **BARROU UM DEPLOY** na Fase 186.
        $this->assertStringNotContainsString('lucro', mb_strtolower($fonte), 'VOC-03: "lucro líquido" é proibido nesta coluna');
        $this->assertStringNotContainsString('localStorage', $fonte, 'COL-02: filtro e oferta aberta vivem na URL');
        // ⚠️ TOK-15 — nenhum HEX sai do PHP: o `content` do Tailwind NÃO varre `.php`.
        $this->assertDoesNotMatchRegularExpression('/#[0-9A-Fa-f]{6}\b/', $fonte, 'hex no PHP: a classe não seria varrida pelo Tailwind');

        // ⚠️⚠️ ISCA DO RECORTE, nas DUAS direções: o gate ACUSA no meu arquivo, e NÃO acusa o
        // campo legítimo do Portal.
        $this->assertStringContainsString('lucro', mb_strtolower('O lucro líquido do mês'), 'a sonda de lucro está cega');
        $doPortal = $this->cru('app/Models/EstruturaPrecificacaoParametros.php');
        $this->assertStringContainsString('lucro_liquido', $doPortal, 'a isca perdeu o sujeito: o campo do Portal mudou de nome');
        $this->assertNotContains('app/Models/EstruturaPrecificacaoParametros.php', $meus, 'o recorte engoliu o arquivo do Portal — o deploy reprovaria');
        $this->assertMatchesRegularExpression('/#[0-9A-Fa-f]{6}\b/', 'border: #FACC15;', 'a sonda de hex está cega');
        $this->assertDoesNotMatchRegularExpression('/#[0-9A-Fa-f]{6}\b/', 'self::COR_CLARA;', 'a sonda de hex acusa a chave semântica — ela está larga');

        // ─── ⚠️ FIN-02/FIN-03 (T-188-35): NENHUMA taxa fictícia como literal ───────────────
        // ⚠️ As taxas de `fin()` são **ficção declarada** (`:1064` *"taxa de exemplo"*, `src:'est'`),
        // e com as fontes reais o exemplo `o1` **inverte de aprovável para bloqueado**.
        $ficcao = [];
        foreach (['0.12', '0.17', '6.75', '* 0.12', '* 0.17', '/ (1 -'] as $taxa) {
            // ⚠️ Flag DENTRO do laço.
            if (str_contains($fonte, $taxa)) {
                $ficcao[] = $taxa;
            }
        }
        $this->assertSame([], $ficcao, 'taxa fictícia do protótipo entrou como literal: '.implode(', ', $ficcao));
        // ⚠️ ISCA: a sonda PEGA a forma que ela deve pegar.
        $this->assertTrue(str_contains('$comissao = $preco * 0.12;', '0.12'), 'a sonda de taxa fictícia está cega');
        $this->assertFalse(str_contains($fonte, '0.17'), 'a sonda de taxa fictícia perdeu o sujeito');

        // ─── APROVAR JÁ PUBLICA: a ausência de segundo passo, na FORMA ──────────────────────
        foreach (['desfazer', 'confirmar publica', 'cancelar aprova', 'segundo passo'] as $proibido) {
            // ⚠️ Flag DENTRO do laço.
            $this->assertStringNotContainsString($proibido, mb_strtolower($fonte), 'decisão do usuário 14/10/2026: aprovar JÁ PUBLICA');
        }
        $this->assertStringContainsString('desfazer', mb_strtolower('Botão de Desfazer'), 'a sonda de segundo passo está cega');

        // ─── ⚠️ O ACERVO (v28, R2 PRIVADO), o Creative Engine e o Support ficam INTOCADOS ──
        foreach (['ArquivosDeImagem', 'ImagemAssetService', 'Acervo', 'App\\Support\\Publicador'] as $vetado) {
            // ⚠️ Flag DENTRO do laço.
            $this->assertStringNotContainsString($vetado, $fonte, "território VETADO referenciado: {$vetado}");
        }
        $this->assertStringContainsString('Acervo', 'use App\\Services\\Publicador\\AcervoImagemService;', 'a sonda do acervo está cega');

        // ─── ⚠️ AS FASES 189 E 192 NÃO SÃO ANTECIPADAS ─────────────────────────────────────
        foreach (['Ampliar', 'Gerar novamente', '⤢', '✦'] as $deOutraFase) {
            // ⚠️ Flag DENTRO do laço.
            $this->assertStringNotContainsString($deOutraFase, $fonte, "a Fase 189/192 foi antecipada: {$deOutraFase}");
        }

        // ─── ⚠️ O construtor do painel tem CINCO parâmetros OBRIGATÓRIOS ───────────────────
        // ⚠️ Injeção com DEFAULT é ILUSÃO: `Container::resolveClass()`
        // (`vendor/laravel/framework/src/Illuminate/Container/Container.php:1327`-`:1331`) devolve o
        // **DEFAULT** quando ele existe e a classe não está explicitamente ligada.
        $construtor = new \ReflectionMethod(\App\Services\Publicador\Aprovacao\Painel\PainelDeRevisaoService::class, '__construct');
        $this->assertSame(5, $construtor->getNumberOfParameters(), 'o construtor do painel mudou de aridade');
        $this->assertSame(5, $construtor->getNumberOfRequiredParameters(), 'algum parâmetro ganhou DEFAULT: a injeção viraria ILUSÃO');
        $this->assertSame(
            PrecoDoPainelService::class,
            $construtor->getParameters()[4]->getType()?->getName(),
            'o 5º parâmetro tem de ser o serviço do preço',
        );
        // ⚠️ E o container injeta a instância REAL, não um default.
        $this->assertInstanceOf(
            \App\Services\Publicador\Aprovacao\Painel\PainelDeRevisaoService::class,
            app(\App\Services\Publicador\Aprovacao\Painel\PainelDeRevisaoService::class),
        );

        // ─── ⚠️ A 186/187 É CHAMADA, NUNCA RECOPIADA ──────────────────────────────────────
        $doServico = $this->semComentarios('app/Services/Publicador/Aprovacao/Painel/PrecoDoPainelService.php');
        foreach ([
            'rotuloDoResultado' => 'VOC-03: o rótulo tem de ser LIDO do DTO',
            'rotuloDaMargem' => 'VOC-03: o nome do conceito tem de ser LIDO do DTO',
            'notaDaMargem' => 'decisão 9: a nota tem de ser LIDA do DTO',
            'notaDoPreco' => 'decisão 2: a nota do preço tem de ser LIDA do DTO',
            'precoDeVariante' => 'decisão 2: a variante tem de ser LIDA do DTO',
            'percentualDaBarra' => 'a fórmula da barra tem de ser CHAMADA, nunca copiada',
            'comparacaoComAIrma' => 'a irmã tem de vir do serviço da 186',
            'fraseDeExibicao' => 'a frase da exceção tem de ser LIDA do modelo',
            'rotuloDaOrigem' => 'o rótulo da origem tem de ser LIDO do DTO',
            'ORDEM_DAS_LINHAS' => 'a ordem das 7 linhas tem de ser EXPLÍCITA',
        ] as $chamada => $porque) {
            // ⚠️ Flag DENTRO do laço.
            $this->assertStringContainsString($chamada, $doServico, $porque);
        }
        // ⚠️ E os literais da 186/187 NÃO foram recopiados.
        foreach ([
            "'Resultado estimado por venda'",
            "'Margem de contribuição estimada'",
            "'Incompleto'",
            "'Conferir alteração'",
            "'gold_pro'",
            "'gold_special'",
            "'Clássico'",
            "'Premium'",
            "'Confirmado'",
            "'Pendente'",
        ] as $recopiado) {
            // ⚠️ Flag DENTRO do laço.
            $this->assertStringNotContainsString($recopiado, $doServico, "literal RECOPIADO em vez de LIDO: {$recopiado}");
        }

        // ─── ⚠️⚠️ A CAMADA B DO GATE DE ANDAMENTO: este serviço NÃO é um terceiro escritor ──
        // A sonda larga do 186-09 casa `'andamento' =>` em **qualquer** array, inclusive de SAÍDA.
        // ➡️ As chaves aqui são `andamento_da_irma`/`rotulo_do_andamento` — **as MESMAS** que a 186
        // já escolheu em `ComposicaoFinanceiraService::comparacaoComAIrma()`, por essa exata razão.
        // ⚠️ **Não renomear de volta**: o nome mais específico descreve melhor o dado E mantém o
        // gate alheio intacto, sem remedir `contagens-186-09.txt` (decisão ADIADA para o 188-13).
        $sondaDoAndamento = "/'andamento'\s*=>|->andamento\s*=(?!=)/";
        $this->assertSame(0, preg_match($sondaDoAndamento, $doServico), 'o serviço do preço casaria a sonda da camada B — ele entraria na lista de EXPOSITORES');
        // ⚠️ ISCA, nas duas direções.
        $this->assertSame(1, preg_match($sondaDoAndamento, "return ['andamento' => \$o->andamento];"), 'a sonda da camada B está cega');
        $this->assertSame(0, preg_match($sondaDoAndamento, "return ['andamento_da_irma' => \$x];"), 'a sonda casa o nome específico — a escolha da 186 não protegeria nada');
        $this->assertStringContainsString('andamento_da_irma', $doServico, 'a chave da irmã mudou de nome');

        // ─── ⚠️ O ESCOPO DO ASSUNTO, com ISCA nas duas direções ────────────────────────────
        // ⚠️⚠️ **"Prova de território por RANGE" NÃO PASSA nesta árvore** — a 187-11 mediu 111
        // commits no range, **43 da sessão paralela**, e o diff cru acusava **12 dos 60 alvos
        // vetados**. ⚠️ **E NÃO se confere o AUTOR:** as três máquinas usam o MESMO `user.name`.
        $this->assertSame(1, preg_match(self::ESCOPO, 'feat(188-04): x'), 'o escopo não casa o próprio assunto');
        $this->assertSame(0, preg_match(self::ESCOPO, 'feat(188-03): x'), 'o escopo casa a wave 3 — ele está largo');
        $this->assertSame(0, preg_match(self::ESCOPO, 'feat(187-04): x'), 'o escopo casa a Fase 187 — ele está largo');
        $this->assertSame(0, preg_match(self::ESCOPO, 'docs(coordenacao): nota'), 'o escopo casa nota de coordenação — ele está largo');

        $log = $this->git('git log --format=%h:%s '.self::PARTIDA.'..HEAD');
        $hashes = [];
        foreach (array_filter(explode("\n", $log)) as $linha) {
            // ⚠️ Flag DENTRO do laço.
            $corte = strpos($linha, ':');
            if ($corte !== false && preg_match(self::ESCOPO, substr($linha, $corte + 1)) === 1) {
                $hashes[] = substr($linha, 0, $corte);
            }
        }

        // ⚠️ **SEM `return` antecipado:** a contagem deste caso NÃO pode depender de quantos
        // commits existem (a lição medida no 187-07, onde ela ia de 26 para 70).
        $arquivos = $hashes === []
            ? []
            : array_values(array_filter(array_map(
                'trim',
                explode("\n", $this->git('git show --name-only --format= '.implode(' ', $hashes))),
            )));

        $proibidos = [
            // Os 10 da Fase 186 em `Aprovacao/` (fora de `Fila/` e de `Painel/`).
            'app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/VocabularioDoPortao.php',
            'app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php',
            'app/Services/Publicador/Aprovacao/ComposicaoDaOferta.php',
            'app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/RevisaoDeImagemService.php',
            'app/Services/Publicador/Aprovacao/AjustesDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/HistoricoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/LinhaFinanceira.php',
            // Os 8 de `Fila/` da Fase 187.
            'app/Services/Publicador/Aprovacao/Fila/FilaDeAprovacaoService.php',
            'app/Services/Publicador/Aprovacao/Fila/ProximoARevisarService.php',
            'app/Services/Publicador/Aprovacao/Fila/FiltrosDaFilaService.php',
            'app/Services/Publicador/Aprovacao/Fila/GrupoDaFilaService.php',
            'app/Services/Publicador/Aprovacao/Fila/GaleriaDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/Fila/LinhaDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/Fila/LoteDaFilaService.php',
            'app/Services/Publicador/Aprovacao/Fila/AquecimentoDeTarifasService.php',
            // O serviço de fotos da wave 3 e os modelos do portão.
            'app/Services/Publicador/Aprovacao/Painel/FotosDoPainelService.php',
            'app/Models/PubAprovacaoComposicao.php',
            'app/Models/PubAprovacaoExcecao.php',
            'app/Models/PubAprovacaoImagem.php',
            // ⚠️ SOMENTE LEITURA — as duas margens e os parâmetros.
            'app/Services/Publicador/Fila/ResumoRapidoService.php',
            'app/Services/Publicador/EditorRascunhoService.php',
            'app/Services/Publicador/DadosEfetivosService.php',
            'app/Support/Publicador/Validacao/SimuladorVoceRecebe.php',
            'app/Services/Publicador/Alavancas/AnaliseAlavancasService.php',
            'app/Models/EstruturaPrecificacaoParametros.php',
            // Os `.jsx` NO AR e o editor.
            'resources/js/Components/Mlb/Publicador/aprovacao',
            'resources/js/Components/Mlb/Publicador/publicacoes',
            'resources/js/Components/Publicador/Mesa',
            'resources/js/Layouts/AppLayout.jsx',
            'resources/js/Pages/Mlb/Publicador/Editor.jsx',
            // ⚠️ O ACERVO (v28, R2 PRIVADO), o Creative Engine, o MCP, o Support e o Portal.
            'app/Services/Creative',
            'app/Services/Publicador/Criativos',
            'app/Support/Publicador',
            'app/Http/Controllers/MlbPublicadorController.php',
            'app/Http/Controllers/MlbPublicadorAprovacaoController.php',
            'app/Mcp',
            'app/Services/Portal',
            'app/Services/Mlb/Publicacao/MlPublicacaoService.php',
            'routes/mlb_anuncios.php',
            // Os gates INTOCÁVEIS.
            'tests/js/publicador-header-zindex-ordem.test.js',
            'tests/js/estrutura-meus-anuncios.test.js',
            'tests/js/publicador-fila-tokens-187.test.js',
            'tests/js/publicador-fila-proximo-187.test.js',
            'tests/gates/contagens-186-09.txt',
            // ⚠️ GATE-07 — nenhum destes pertence a plano nenhum; quem edita é o orquestrador.
            '.planning/ROADMAP.md',
            '.planning/REQUIREMENTS-v27.md',
            '.planning/STATE.md',
        ];

        // ⚠️⚠️ **UMA asserção coletando os infratores**, nunca uma por caminho.
        $tocados = [];
        foreach ($arquivos as $a) {
            // ⚠️ Flag DENTRO do laço.
            foreach ($proibidos as $p) {
                if ($a === $p || str_starts_with($a, rtrim($p, '/').'/')) {
                    $tocados[] = $a;
                }
            }
        }
        $this->assertSame([], array_values(array_unique($tocados)), 'commit do escopo (188-04) tocou alvo VETADO');

        // ⚠️ A prova de que o escopo não está vazio por construção, COLETADA.
        $doPlano = ['Aprovacao/Painel/PrecoDoPainel', 'Aprovacao/Painel/PainelDeRevisao',
            'PrecoDoPainelServiceTest', 'PainelDeRevisaoServiceTest', 'PainelDaOfertaRotaTest',
            'contagens-188-04', '188-04-SUMMARY', '188-FID01'];
        $reconhece = static fn (string $a): bool => (bool) array_filter($doPlano, static fn (string $p): bool => str_contains($a, $p));

        $semProva = $hashes !== [] && ! array_filter($arquivos, $reconhece)
            ? ['os commits do escopo não tocaram arquivo deste plano: '.json_encode(array_slice($arquivos, 0, 8))]
            : [];
        $this->assertSame([], $semProva, 'o range do escopo está vazio por construção');

        // ⚠️ ISCA DA PROVA, nas duas direções.
        $this->assertTrue($reconhece('app/Services/Publicador/Aprovacao/Painel/PrecoDoPainelService.php'), 'a prova não reconhece o serviço deste plano');
        $this->assertFalse($reconhece('app/Services/Creative/CreativeJuiz.php'), 'a prova reconhece arquivo de OUTRA fase');

        // ⚠️ E o ANTI-FANTASMA, que é o gate PERENE (`test -e` em cada alvo citado) — são NOVE
        // caminhos fantasmas nesta milestone, TRÊS vindos de briefing do orquestrador.
        $semDisco = array_values(array_filter(
            $proibidos,
            fn (string $p): bool => ! file_exists($this->raiz().'/'.$p) && ! str_contains($p, 'REQUIREMENTS-v27'),
        ));
        $this->assertSame([], $semDisco, 'caminho FANTASMA na lista de vetados — o gate dele mede NADA');

        // ⚠️ **T-188-SC — este plano NÃO instala nada**, provado por FORMA (nunca por `numstat` de
        // range): nenhum manifesto de dependência aparece entre os arquivos do escopo.
        $this->assertSame(
            [],
            array_values(array_filter($arquivos, static fn (string $a): bool => in_array($a, ['composer.json', 'composer.lock', 'package.json', 'package-lock.json'], true))),
            'T-188-SC: este plano não instala nada',
        );

        // ⚠️ E **NENHUMA MIGRATION**: esta fase não tem migration.
        $this->assertSame(
            [],
            array_values(array_filter($arquivos, static fn (string $a): bool => str_starts_with($a, 'database/migrations/'))),
            'esta fase NÃO tem migration',
        );

        // ═══ (B) A BIJEÇÃO ids ↔ métodos, nos DOIS sentidos ══════════════════════════════════
        $manifesto = $this->raiz().'/tests/gates/contagens-188-04.txt';
        $this->assertFileExists($manifesto, 'manifesto ausente — os gates deste plano rodariam sem régua');

        $linhas = array_values(array_filter(
            array_map('trim', explode("\n", (string) file_get_contents($manifesto))),
            static fn (string $l): bool => $l !== '' && ! str_starts_with($l, '#'),
        ));

        $filtrosDosIds = [];
        foreach ($linhas as $linha) {
            // ⚠️ Flag DENTRO do laço.
            $partes = preg_split('/\s+/', $linha);
            if (($partes[1] ?? '') === 'php') {
                $this->assertArrayNotHasKey($partes[0], $filtrosDosIds, 'id REPETIDO no manifesto: o harness pega o PRIMEIRO');
                $filtrosDosIds[$partes[0]] = $partes[2];
            }
        }

        // ⚠️ Lista CONSTANTE dos QUATRO, nunca `count()` — **piso não prova PRESENÇA**.
        $this->assertSame(
            ['kpis-e-incompleto', 'composicao-e-origem', 'simulacao-sem-salvar', 'atalhos-excecao-irma'],
            array_keys($filtrosDosIds),
            'os QUATRO ids deste plano, nesta ordem — id a mais ou a menos muda o que roda',
        );

        // ─── Os métodos, lidos do DISCO ────────────────────────────────────────────────────
        $rel = 'tests/Feature/Publicador/Aprovacao/PrecoDoPainelServiceTest.php';
        preg_match_all('/function (test_[A-Za-z0-9_]*_188_04)\s*\(/', $this->cru($rel), $m);
        $metodos = array_values(array_unique($m[1]));

        $this->assertCount(12, $metodos, 'os métodos `_188_04` deste plano encolheram ou cresceram');

        // ⚠️ SENTIDO 1 — método sem id **NUNCA RODA** em gate nenhum. UMA asserção coletando.
        $semId = [];
        foreach ($metodos as $metodo) {
            // ⚠️ Flag DENTRO do laço.
            $achou = false;
            foreach ($filtrosDosIds as $filtro) {
                if (preg_match('/^'.$filtro.'$/', $metodo) === 1) {
                    $achou = true;
                    break;
                }
            }
            if (! $achou) {
                $semId[] = $metodo;
            }
        }
        $this->assertSame([], $semId, 'método sem id no manifesto: ele NUNCA RODA em gate nenhum');

        // ⚠️ SENTIDO 2 — id órfão roda sobre **ZERO casos** e é indistinguível de gate verde.
        $orfaos = [];
        foreach ($filtrosDosIds as $id => $filtro) {
            // ⚠️ Flag DENTRO do laço.
            $casou = false;
            foreach ($metodos as $metodo) {
                if (preg_match('/^'.$filtro.'$/', $metodo) === 1) {
                    $casou = true;
                    break;
                }
            }
            if (! $casou) {
                $orfaos[] = $id;
            }
        }
        $this->assertSame([], $orfaos, 'id órfão no manifesto: o gate dele roda sobre ZERO casos');

        // ⚠️ **NENHUM id com mais de TRÊS métodos** (alternância grande equivale a filtro de classe,
        // passa dos 120 s do shell e o helper RECUSA). UMA asserção coletando.
        $gordos = [];
        foreach ($filtrosDosIds as $id => $filtro) {
            // ⚠️ Flag DENTRO do laço.
            $quantos = count(array_filter($metodos, static fn (string $mm): bool => preg_match('/^'.$filtro.'$/', $mm) === 1));
            if ($quantos > 3) {
                $gordos[] = $id.' ('.$quantos.')';
            }
        }
        $this->assertSame([], $gordos, 'id com mais de TRÊS métodos: o harness RECUSA e o shell estoura');

        // ⚠️ E nenhum id com `tests=0` / sem `tests=` (o harness ABORTA, de propósito).
        $semContagem = array_values(array_filter(
            $linhas,
            static fn (string $l): bool => ! str_contains($l, 'tests=') || str_contains($l, 'tests=0'),
        ));
        $this->assertSame([], $semContagem, 'linha sem `tests=` ou com `tests=0`: o harness ABORTA');

        // ⚠️ ISCA DO NOME QUE NINGUÉM ESCREVEU, nas duas direções.
        $casouFantasma = array_values(array_filter(
            $filtrosDosIds,
            static fn (string $f): bool => preg_match('/^'.$f.'$/', 'test_metodo_que_ninguem_escreveu_188_04') === 1,
        ));
        $this->assertSame([], $casouFantasma, 'ISCA: um filtro casa método que ninguém escreveu — ele está largo');
        $this->assertSame(
            1,
            preg_match('/^'.$filtrosDosIds['atalhos-excecao-irma'].'$/', 'test_territorio_e_bijecao_do_manifesto_do_preco_188_04'),
            'ISCA: o filtro não casa o próprio método',
        );

        // ⚠️⚠️ **E O SUFIXO PROTEGE DE VERDADE:** os homônimos das outras waves **existem**, e o meu
        // filtro **NÃO** os casa. Sem estas linhas, o sufixo seria zelo não medido.
        foreach (['test_territorio_e_ausencias_do_plano_188_02', 'test_territorio_e_ausencias_do_plano_188_03', 'test_territorio_e_ausencias_do_plano_187_08'] as $homonimo) {
            // ⚠️ Flag DENTRO do laço.
            foreach ($filtrosDosIds as $id => $filtro) {
                $this->assertSame(0, preg_match('/^'.$filtro.'$/', $homonimo), "o id {$id} casa o homônimo {$homonimo} — as contagens SOMARIAM");
            }
        }
        $this->assertStringContainsString(
            'test_territorio_e_ausencias_do_plano_188_03',
            $this->cru('tests/Feature/Publicador/Aprovacao/FotosDoPainelServiceTest.php'),
            'o homônimo da wave 3 mudou de nome — a isca perdeu o sujeito',
        );

        // ⚠️ E o 1º ARGUMENTO do harness é `188-04`: o número NU resolveria `contagens-186-04.txt`,
        // a fase ERRADA, em silêncio. O manifesto da 186 com esse número **existe**.
        $this->assertFileExists($this->raiz().'/tests/gates/contagens-186-04.txt', 'a isca do número nu perdeu o sujeito — a confusão deixaria de ser possível');
    }
}
