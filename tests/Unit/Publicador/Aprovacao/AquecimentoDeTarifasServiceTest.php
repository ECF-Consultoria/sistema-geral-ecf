<?php

namespace Tests\Unit\Publicador\Aprovacao;

use App\Models\Company;
use App\Models\PubAprovacaoOferta;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Publicador\Aprovacao\ComposicaoDaOferta;
use App\Services\Publicador\Aprovacao\ComposicaoFinanceiraService;
use App\Services\Publicador\Aprovacao\Fila\AquecimentoDeTarifasService;
use App\Services\Publicador\DadosEfetivosService;
use App\Support\Publicador\RegraViolada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 187, plano 10 — O PRÉ-AQUECIMENTO DA TARIFA DO MERCADO LIVRE, FORA DO REQUEST.
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"** — gate cuja resposta é "nada" é
 * decorativo e não entra.
 *
 *   · **A CHAVE DIVERGENTE** — alguém montar a chave de cache deste lado ("quase igual"), e o
 *     aquecimento passar a aquecer o lugar errado **em silêncio**: a tela continuaria fria e
 *     ninguém saberia. Três gates independentes cobrem isso: o de COMPORTAMENTO (`para()` depois
 *     do aquecimento faz ZERO requisição), a ISCA DA CHAVE (aquecer com outro preço **não**
 *     serve) e o ESTRUTURAL (o literal da chave mora em **UM** arquivo de `app/`, com isca nas
 *     duas direções).
 *   · **O CUSTO** — mais de uma requisição por trio `(categoria, tipo, preço)` distinto. É a
 *     classe do incidente de **30/07/2026**, quando o `cache:clear` no VPS fez o dashboard
 *     esperar a API, as requisições lentas ocuparam os workers do php-fpm e **até o login parou**.
 *   · **A FIXTURE VAZIA POR CONSTRUÇÃO** — ⚠️⚠️ este é o modo de falhar MEDIDO nesta fase:
 *     `FilaDeAprovacaoRotaTest::test_a_fila_nao_chama_o_mercado_livre_187_02` está verde porque a
 *     fixture dele cria o `PubRascunho` **SEM `categoria_id`** (`FilaDeAprovacaoRotaTest:161`:
 *     `PubRascunho::create(['produto_id' => $produto->id])`), e aí `tarifaDoMl()` devolve "sem
 *     fonte" em `:470` **sem sair para a rede**. Aquele gate NÃO prova que a fila nunca chama o
 *     ML — ele prova que aquela fixture não tem categoria. ➡️ **Toda fixture deste arquivo tem
 *     `categoria_id` preenchido**, e os casos de "pulada" o deixam nulo **de propósito, com a
 *     contagem exigida**.
 *   · **A DEGRADAÇÃO** — alguém pôr cap por request, "sem tarifa por timeout" ou "incompleto"
 *     aqui. Foi **oferecido e RECUSADO** pelo usuário em 15/10/2026, porque incompleto **bloqueia
 *     a aprovação**: limitar protegeria o servidor **travando o trabalho**.
 *   · **FILA VAZIA PARECENDO ERRO** — `trios=0` é o número honesto hoje (nada cria
 *     `PubAprovacaoOferta` até a Fase 194) e não pode virar falha.
 *   · **UMA OFERTA DERRUBANDO AS OUTRAS** — `Log then continue` por oferta, não por varredura.
 *   · **GRAVAÇÃO INESPERADA** — `para()` é leitura pura (*"Nada é gravado aqui. Quem grava é
 *     `congelar()`"*), e um aquecimento que gravasse viraria escrita em massa fora do request.
 *
 * ⚠️ **ZERO REDE:** `Http::preventStrayRequests()` + `Http::fake` com gravador, em TODO caso.
 * **Nenhuma chamada à API real do Mercado Livre sai daqui.**
 *
 * ⚠️ **ESTE ARQUIVO NÃO ALTERA NADA DA FASE 186.** `ComposicaoFinanceiraService` é **CHAMADO** —
 * e, nos casos de custo, **SUBCLASSADO no teste**, nunca alterado no disco.
 *
 * ⚠️ **Nenhum executor desta fase viu esta tela renderizada nem este job rodando em produção:** o
 * banco local não tem empresa com `ml_token`. A conferência é do usuário, em produção, na conta
 * 459.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class AquecimentoDeTarifasServiceTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    /** A categoria com fixture de tarifa no repositório. */
    private const CATEGORIA = 'MLB189007';

    /**
     * ⚠️⚠️ O literal da chave de cache da tarifa, que mora em **UM** arquivo de `app/`.
     *
     * Ele está aqui, em `tests/`, porque a sonda precisa procurá-lo — e a varredura cobre
     * **`app/` apenas**, nunca `tests/`, senão o gate reprovaria por causa desta própria linha.
     */
    private const LITERAL_DA_CHAVE = "'aprovacao:tarifa:'";

    /** O único arquivo de `app/` que pode conter o literal — o serviço da Fase 186. */
    private const DONO_DA_CHAVE = 'app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php';

    /** Os arquivos de produção DESTE plano — nenhum deles pode conter o literal. */
    private const MEUS = [
        'app/Services/Publicador/Aprovacao/Fila/AquecimentoDeTarifasService.php',
        'app/Jobs/AquecerTarifasDaFilaJob.php',
        'app/Console/Commands/WarmTarifasDaFila.php',
    ];

    private int $sequencia = 0;

    /** `"{categoria}|{listing_type}"` → corpo do `/sites/MLB/listing_prices`. */
    private array $tarifas = [];

    /** @var list<array{caminho: string, query: array}> */
    private array $chamadas = [];

    private ?object $efetivos = null;

    protected function setUp(): void
    {
        parent::setUp();

        // ⚠️ O app token do `ClienteMlPublicador` é FINGIDO no cache: sem ele a primeira chamada
        // pública tenta `/oauth/token` e LANÇA. ⚠️ E nunca `Cache::flush()` aqui.
        Cache::put('ml_app_token_coleta', 'fake-app-token', 3600);

        Http::preventStrayRequests();
        Http::fake(function ($req) {
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
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Fixture
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * ⚠️⚠️ `categoria` vem PREENCHIDA por padrão, e isso é o invariante da fixture deste arquivo:
     * sem `categoria_id` o serviço da Fase 186 recusa em `:470` e **nenhum** gate de custo deste
     * plano mediria coisa alguma — foi exatamente o que tornou um gate alheio vazio por
     * construção. Quem quiser o caso "pulada" passa `['categoria' => null]` **de propósito**.
     *
     * @param  array{tipo?: string, categoria?: ?string, sku?: string}  $o
     */
    private function oferta(Company $empresa, array $o = []): PubAprovacaoOferta
    {
        $this->sequencia++;
        $sku = $o['sku'] ?? ('AQ-'.$this->sequencia);

        $produto = PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => $sku,
            'nome' => 'Poltrona '.$sku,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'quantidade_kit' => 1,
        ]);

        $rascunho = PubRascunho::create([
            'produto_id' => $produto->id,
            'categoria_id' => array_key_exists('categoria', $o) ? $o['categoria'] : self::CATEGORIA,
        ]);

        $alvo = PubRascunhoAlvo::create([
            'rascunho_id' => $rascunho->id,
            'listing_type_id' => $o['tipo'] ?? self::CLASSICO,
            'titulo' => 'Poltrona '.$sku,
            'posicao' => 0,
        ]);

        return PubAprovacaoOferta::create([
            'alvo_id' => $alvo->id,
            'rascunho_id' => $rascunho->id,
            'produto_id' => $produto->id,
            'company_id' => $empresa->id,
            'andamento' => PubAprovacaoOferta::AGUARDANDO_APROVACAO,
            'entrou_na_fila_em' => '2026-10-17 07:00:00',
        ])->fresh(['alvo', 'produto', 'rascunho']);
    }

    /**
     * Instala o dublê de `DadosEfetivosService` para a composição REAL chegar à tarifa do ML.
     *
     * ⚠️ É isto que torna os casos de custo uma MEDIÇÃO e não uma afirmação: o preço, o custo e o
     * frete entram de verdade, e o `ComposicaoFinanceiraService` da Fase 186 faz a conta.
     *
     * ⚠️ O preço é **POR SKU**, porque é o que permite montar trios DISTINTOS na mesma rodada —
     * sem isso a isca "trio diferente não dedupa" não teria como existir.
     */
    private function comDadosEfetivos(float $precoPadrao = 1200.0): object
    {
        $duble = new class(app(EstruturaPrecificacaoService::class)) extends DadosEfetivosService
        {
            public float $oPreco = 1200.0;

            /** @var array<string, float> */
            public array $precoPorSku = [];

            /** @var list<string> */
            public array $skusQueExplodem = [];

            public function custosDoProduto(PubProduto $produto): array
            {
                if (in_array((string) $produto->sku, $this->skusQueExplodem, true)) {
                    throw new \RuntimeException('custo indisponível de propósito para '.$produto->sku);
                }

                return ['custo' => 200.0, 'origem' => 'digitado', 'imposto' => 19.0, 'por_variante' => []];
            }

            public function fretesDoProduto(PubProduto $produto): array
            {
                return ['por_tipo' => [
                    'gold_special' => ['valor' => 42.9, 'origem' => 'tabela', 'oferta_id' => null],
                    'gold_pro' => ['valor' => 42.9, 'origem' => 'tabela', 'oferta_id' => null],
                ], 'por_variante' => []];
            }

            public function daProduto(PubProduto $produto): array
            {
                $p = $this->precoPorSku[(string) $produto->sku] ?? $this->oPreco;

                return ['precos' => ['gold_special' => $p, 'gold_pro' => $p]];
            }
        };

        $duble->oPreco = $precoPadrao;
        $this->app->instance(DadosEfetivosService::class, $duble);
        $this->efetivos = $duble;

        return $duble;
    }

    private function comTarifa(string $listingType = self::CLASSICO): void
    {
        $arquivo = base_path('tests/fixtures-ml/sondagem/publico/categorias/'.self::CATEGORIA."/listing_prices_{$listingType}.json");
        $this->assertFileExists($arquivo, 'fixture de tarifa ausente — o gate rodaria sobre zero casos');

        $this->tarifas[self::CATEGORIA.'|'.$listingType] = json_decode((string) file_get_contents($arquivo), true)['resposta'];
    }

    /**
     * O ESPIÃO de `para()` — **SUBCLASSE**, não mock de string.
     *
     * ⚠️ Ele herda a assinatura real, então renomear `para()` no serviço da Fase 186 quebra este
     * teste em vez de deixá-lo passar calado.
     */
    private function comEspiaoDaComposicao(): object
    {
        $espiao = new class(app(DadosEfetivosService::class), app(\App\Services\Publicador\ClienteMlPublicador::class)) extends ComposicaoFinanceiraService
        {
            public int $chamadas = 0;

            /** @var list<int> */
            public array $ofertasVistas = [];

            public function para(PubAprovacaoOferta $oferta, ?float $precoForcado = null): ComposicaoDaOferta
            {
                $this->chamadas++;
                $this->ofertasVistas[] = (int) $oferta->id;

                return parent::para($oferta, $precoForcado);
            }
        };

        $this->app->instance(ComposicaoFinanceiraService::class, $espiao);

        return $espiao;
    }

    private function servico(): AquecimentoDeTarifasService
    {
        return app(AquecimentoDeTarifasService::class);
    }

    /** Quantas requisições a `/sites/MLB/listing_prices` saíram até agora. */
    private function requisicoesDeTarifa(): int
    {
        return count(array_filter(
            $this->chamadas,
            static fn (array $c): bool => $c['caminho'] === '/sites/MLB/listing_prices',
        ));
    }

    /** Os trios DISTINTOS que de fato saíram pela rede, lidos da query de cada requisição. */
    private function triosRequisitados(): array
    {
        $trios = [];
        foreach ($this->chamadas as $c) {
            if ($c['caminho'] !== '/sites/MLB/listing_prices') {
                continue;
            }
            $trios[] = ($c['query']['category_id'] ?? '').'|'
                .($c['query']['listing_type_id'] ?? '').'|'
                .($c['query']['price'] ?? '');
        }

        return $trios;
    }

    private function raiz(): string
    {
        return rtrim(str_replace('\\', '/', base_path()), '/');
    }

    /** O código PHP **sem comentário** — `token_get_all`, nunca regex. */
    private function semComentariosPhp(string $conteudo): string
    {
        $fora = '';
        foreach (token_get_all($conteudo) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $fora .= $t[1];

                continue;
            }
            $fora .= $t;
        }

        return $fora;
    }

    /**
     * A SONDA do literal da chave: quais destes arquivos têm o literal **no CÓDIGO**.
     *
     * ⚠️ Ela lê o código **sem comentário** de propósito. Palavra no docblock que EXPLICA *"eu não
     * monto esta chave"* casaria com o gate que conta a chave, e o conserto barato do executor
     * seguinte seria **apagar a explicação** em vez de consertar a sonda. Há ISCA INVERTIDA
     * provando que o comentário NÃO conta.
     *
     * @param  list<string>  $absolutos
     * @return list<string>
     */
    private function arquivosComOLiteral(array $absolutos): array
    {
        $achados = [];
        foreach ($absolutos as $abs) {
            $cru = (string) file_get_contents($abs);

            // Filtro barato primeiro: só vale tokenizar quem tem alguma chance.
            if (! str_contains($cru, self::LITERAL_DA_CHAVE)) {
                continue;
            }

            if (str_contains($this->semComentariosPhp($cru), self::LITERAL_DA_CHAVE)) {
                $achados[] = $abs;
            }
        }

        return $achados;
    }

    /** @return list<string> todos os `.php` de `app/`, em caminho absoluto com `/`. */
    private function phpDeApp(): array
    {
        $saida = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->raiz().'/app'));
        foreach ($it as $arquivo) {
            /** @var \SplFileInfo $arquivo */
            if ($arquivo->isFile() && strtolower($arquivo->getExtension()) === 'php') {
                $saida[] = str_replace('\\', '/', $arquivo->getPathname());
            }
        }
        sort($saida);

        return $saida;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (1) O CUSTO — uma requisição por trio distinto, com isca nas DUAS direções
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** o aquecimento consultar o Mercado Livre
     * uma vez por OFERTA em vez de uma por TRIO (a fila de 30 linhas viraria 30 requisições); ou o
     * cache do serviço da 186 furar; ou — na outra direção — um cache que devolvesse sempre a
     * mesma coisa fazendo dois trios DIFERENTES custarem uma requisição só, o que daria tarifa
     * errada na tela.
     *
     * @group phase187
     */
    public function test_uma_requisicao_por_trio_distinto_com_isca_nas_duas_direcoes_187_10(): void
    {
        $this->comTarifa(self::CLASSICO);
        $this->comTarifa(self::PREMIUM);
        $duble = $this->comDadosEfetivos();
        $espiao = $this->comEspiaoDaComposicao();

        // ─── DIREÇÃO 1: quatro ofertas, DOIS trios (o preço é o mesmo) ───
        $contaA = Company::factory()->create();
        foreach ([self::CLASSICO, self::CLASSICO, self::PREMIUM, self::PREMIUM] as $tipo) {
            $this->oferta($contaA, ['tipo' => $tipo]);
        }

        $r = $this->servico()->paraAConta($contaA->id);

        $this->assertSame(2, $r['trios'], 'quatro ofertas em dois trios deveriam dar DOIS trios');
        $this->assertSame(2, $r['aquecidos']);
        $this->assertSame(0, $r['pulados']);
        $this->assertSame(0, $r['falhas']);
        $this->assertSame(2, $this->requisicoesDeTarifa(), 'saiu mais de UMA requisição por trio distinto');

        // ⚠️ E `para()` foi chamado UMA vez por TRIO, não por oferta: a mutação "aquecer todas as
        // ofertas" não muda a contagem de requisições (o cache dedupa) e muda ESTA.
        $this->assertSame(2, $espiao->chamadas, '`para()` deveria ser chamado uma vez por TRIO');
        $this->assertSame(
            count($espiao->ofertasVistas),
            count(array_unique($espiao->ofertasVistas)),
            '`para()` foi chamado DUAS vezes para a mesma oferta',
        );

        // ─── DIREÇÃO 2 (A ISCA): quatro ofertas, QUATRO trios (quatro preços distintos) ───
        $contaB = Company::factory()->create();
        $antes = $this->requisicoesDeTarifa();
        foreach ([111.0, 222.0, 333.0, 444.0] as $i => $preco) {
            $oferta = $this->oferta($contaB, ['tipo' => self::CLASSICO, 'sku' => 'ISCA-'.$i]);
            $duble->precoPorSku['ISCA-'.$i] = $preco;
            $this->assertNotNull($oferta->id);
        }

        $rB = $this->servico()->paraAConta($contaB->id);

        $this->assertSame(4, $rB['trios'], 'quatro preços distintos deveriam dar QUATRO trios');
        $this->assertSame(4, $this->requisicoesDeTarifa() - $antes, 'trio DIFERENTE não gerou requisição nova — o cache está devolvendo a mesma coisa para tudo');

        // ⚠️ ISCA DO GRAVADOR: ele conta de verdade e os trios são realmente distintos na rede.
        $trios = $this->triosRequisitados();
        $this->assertSame(6, count($trios), 'o gravador perdeu requisição');
        $this->assertSame(6, count(array_unique($trios)), 'duas requisições saíram para o MESMO trio');

        // ⚠️ ISCA DO ESPIÃO: ele CONTA. Sem isto, "2" poderia ser o valor de um espião cego.
        $antesDoEspiao = $espiao->chamadas;
        $espiao->para($this->oferta($contaA, ['tipo' => self::CLASSICO]));
        $this->assertSame($antesDoEspiao + 1, $espiao->chamadas, 'ISCA: o espião não conta');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (2) A CHAVE É A MESMA — provada por COMPORTAMENTO, com a isca do preço
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** o aquecimento montar a chave do próprio
     * lado. Aí `para()`, depois dele, voltaria a consultar — e o aquecimento teria aquecido o
     * lugar errado **em silêncio**, com a tela continuando fria e ninguém sabendo.
     *
     * ⚠️ A idempotência é a outra metade: o precedente do projeto escreve a razão —
     * *"em cache-miss popula; em cache-hit não custa nada (idempotente)"*.
     *
     * @group phase187
     */
    public function test_o_aquecimento_e_idempotente_e_a_composicao_depois_nao_consulta_187_10(): void
    {
        $this->comTarifa(self::CLASSICO);
        $this->comDadosEfetivos();

        $conta = Company::factory()->create();
        $ofertas = [$this->oferta($conta), $this->oferta($conta), $this->oferta($conta)];

        $r = $this->servico()->paraAConta($conta->id);
        $this->assertSame(1, $r['trios'], 'três ofertas iguais são UM trio');
        $this->assertSame(1, $this->requisicoesDeTarifa());

        // ── Idempotente: a segunda rodada não custa nada ──
        $segunda = $this->servico()->paraAConta($conta->id);
        $this->assertSame(1, $segunda['trios']);
        $this->assertSame(1, $segunda['aquecidos'], 'a segunda rodada deveria contar o trio como aquecido mesmo em cache-hit');
        $this->assertSame(1, $this->requisicoesDeTarifa(), 'a segunda rodada consultou o ML de novo');

        // ⚠️⚠️ A PROVA DA CHAVE, POR COMPORTAMENTO: a composição que a TELA chama, para as MESMAS
        // ofertas, faz ZERO requisição depois do aquecimento.
        $financeira = app(ComposicaoFinanceiraService::class);
        foreach ($ofertas as $oferta) {
            $financeira->para($oferta);
        }
        $this->assertSame(1, $this->requisicoesDeTarifa(), 'a tela voltou a consultar o ML — a chave do aquecimento NÃO é a mesma');

        // ⚠️ ISCA: a composição de um trio que NINGUÉM aqueceu **consulta**. Sem esta linha, o
        // "ZERO" acima poderia ser um `para()` que não consulta nada nunca.
        $this->comTarifa(self::PREMIUM);
        $financeira->para($this->oferta($conta, ['tipo' => self::PREMIUM]));
        $this->assertSame(2, $this->requisicoesDeTarifa(), 'ISCA: trio não aquecido deveria consultar');
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** alguém aquecer com um preço "quase igual"
     * ao da oferta — arredondando diferente, usando o preço da precificação em vez do
     * `precoDoAlvo()`, ou aquecendo um preço de referência. O cache é por
     * `(categoria, tipo, preço)`, então aquecer o preço errado **não serve para nada** e a tela
     * continuaria pagando a consulta no request.
     *
     * ⚠️ É esta a isca que separa "o aquecimento chama `para()`" de "o aquecimento é parecido com
     * `para()`".
     *
     * @group phase187
     */
    public function test_ISCA_DA_CHAVE_aquecer_com_outro_preco_nao_serve_187_10(): void
    {
        $this->comTarifa(self::CLASSICO);
        $this->comDadosEfetivos(1200.0);

        $conta = Company::factory()->create();
        $oferta = $this->oferta($conta);

        $financeira = app(ComposicaoFinanceiraService::class);

        // ── O aquecimento ERRADO: o mesmo método, com OUTRO preço ──
        $financeira->para($oferta, 1201.0);
        $this->assertSame(1, $this->requisicoesDeTarifa(), 'o aquecimento errado não consultou — o cenário não mediu nada');

        // ⚠️ E ele NÃO serve: a tela volta a consultar.
        $financeira->para($oferta);
        $this->assertSame(2, $this->requisicoesDeTarifa(), 'o preço NÃO entra na chave — a isca não discrimina nada');

        // ── O aquecimento CERTO, pelo serviço deste plano, em outro trio ──
        $this->comTarifa(self::PREMIUM);
        $premium = $this->oferta($conta, ['tipo' => self::PREMIUM]);
        $antes = $this->requisicoesDeTarifa();

        $this->servico()->paraAConta($conta->id);
        $this->assertGreaterThan($antes, $this->requisicoesDeTarifa(), 'o aquecimento certo não consultou nada');

        $depois = $this->requisicoesDeTarifa();
        $financeira->para($premium);
        $this->assertSame($depois, $this->requisicoesDeTarifa(), 'depois do aquecimento CERTO a tela ainda consulta');
    }

    /**
     * ⚠️⚠️ O GATE ESTRUTURAL DA CHAVE: o literal mora em **UM** arquivo de `app/`, e **nenhum**
     * arquivo deste plano o contém.
     *
     * ⚠️ **O que precisaria estar errado para falhar:** alguém recopiar o literal para cá algum
     * dia — a porta dos fundos pela qual a chave divergiria sem nenhum teste de comportamento
     * acusar, porque uma chave construída "quase igual" ainda produz um valor de cache válido,
     * só no lugar errado.
     *
     * ⚠️ E a razão de o gate ser ESTRUTURAL e não por inspeção: o método que monta a chave é
     * `private` e o **único chamador dele é `para()`** — medido nesta sessão, `grep -n` daquele
     * nome devolve **duas** ocorrências no arquivo (declaração e chamada). Não existe caminho para
     * montar aquela chave de fora.
     *
     * @group phase187
     */
    public function test_o_literal_da_chave_mora_em_UM_arquivo_so_de_app_187_10(): void
    {
        $todos = $this->phpDeApp();
        $this->assertGreaterThan(200, count($todos), 'a varredura de `app/` achou poucos arquivos — ela mediria quase nada');

        $comLiteral = $this->arquivosComOLiteral($todos);

        $this->assertSame(
            [$this->raiz().'/'.self::DONO_DA_CHAVE],
            $comLiteral,
            'o literal da chave da tarifa apareceu fora do serviço da Fase 186 — a chave foi RECOPIADA e as duas verdades vão divergir em silêncio',
        );

        // ── E os arquivos deste plano, nomeados um a um, com ZERO ocorrência ──
        $meusAbsolutos = array_map(fn (string $rel): string => $this->raiz().'/'.$rel, self::MEUS);
        $fantasmas = array_values(array_filter($meusAbsolutos, static fn (string $a): bool => ! file_exists($a)));
        $this->assertSame([], $fantasmas, 'arquivo deste plano inexistente — o gate mediria NADA');
        $this->assertSame([], $this->arquivosComOLiteral($meusAbsolutos), 'um arquivo DESTE plano monta a chave à mão');

        // ⚠️ ISCA DIREÇÃO 1 — a sonda PEGA o literal quando ele está no CÓDIGO.
        $tmp = tempnam(sys_get_temp_dir(), 'isca187_10_').'.php';
        file_put_contents($tmp, "<?php\n\$chave = ".self::LITERAL_DA_CHAVE.".\$cat;\n");
        $this->assertSame([str_replace('\\', '/', $tmp)], array_map(
            static fn (string $a): string => str_replace('\\', '/', $a),
            $this->arquivosComOLiteral([$tmp]),
        ), 'ISCA: a sonda do literal está CEGA — ela não pega uma cópia sintética');

        // ⚠️⚠️ ISCA DIREÇÃO 2 (A INVERTIDA) — o literal num COMENTÁRIO **não** conta. Sem ela, um
        // docblock que explicasse *"eu não monto esta chave"* reprovaria o trabalho certo, e o
        // conserto barato seria apagar a explicação.
        $tmpComentado = tempnam(sys_get_temp_dir(), 'isca187_10c_').'.php';
        file_put_contents($tmpComentado, "<?php\n// eu NAO monto a chave ".self::LITERAL_DA_CHAVE." aqui\n\$x = 1;\n");
        $this->assertSame([], $this->arquivosComOLiteral([$tmpComentado]), 'ISCA INVERTIDA: o leitor sem comentário parou de tirar comentário');

        @unlink($tmp);
        @unlink($tmpComentado);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (3) OS CASOS DE BORDA — pulada, fila vazia, falha de uma, nada gravado, sem conta
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** oferta sem categoria (ou sem tipo, ou sem
     * preço) virar requisição ao Mercado Livre — ou, pior, ser contada como TRIO e dar a impressão
     * de que a fila está aquecida quando não está. O serviço da Fase 186 já recusa em `:470` sem
     * sair para a rede; aqui a conta é **PULADA**, que é a leitura honesta.
     *
     * @group phase187
     */
    public function test_oferta_sem_categoria_ou_sem_tipo_e_contada_como_pulada_187_10(): void
    {
        $this->comTarifa(self::CLASSICO);
        $duble = $this->comDadosEfetivos();

        $conta = Company::factory()->create();

        // ⚠️ SEM `categoria_id` — o caso que deixou um gate alheio vazio por construção.
        $this->oferta($conta, ['categoria' => null, 'sku' => 'SEM-CATEGORIA']);
        // ⚠️ SEM tipo de anúncio (string vazia: a coluna não é anulável).
        $this->oferta($conta, ['tipo' => '', 'sku' => 'SEM-TIPO']);
        // ⚠️ SEM preço — toda linha percentual seria zero, e zero é número.
        $this->oferta($conta, ['sku' => 'SEM-PRECO']);
        $duble->precoPorSku['SEM-PRECO'] = 0.0;

        $r = $this->servico()->paraAConta($conta->id);

        $this->assertSame(0, $r['trios'], 'oferta sem fonte virou trio');
        $this->assertSame(3, $r['pulados'], 'as três ofertas sem fonte deveriam ser PULADAS');
        $this->assertSame(0, $r['aquecidos']);
        $this->assertSame(0, $r['falhas'], 'pular não é falhar');
        $this->assertSame(0, $this->requisicoesDeTarifa(), 'saiu requisição ao ML para oferta sem fonte');

        // ⚠️ ISCA: a MESMA fixture, COM categoria, tipo e preço, aquece de verdade. Sem ela este
        // caso seria satisfeito por um serviço que nunca aquece nada.
        $this->oferta($conta, ['sku' => 'COMPLETA']);
        $comFonte = $this->servico()->paraAConta($conta->id);
        $this->assertSame(1, $comFonte['trios'], 'ISCA: a fixture completa também não aqueceu — a fixture não discrimina nada');
        $this->assertSame(3, $comFonte['pulados']);
        $this->assertSame(1, $this->requisicoesDeTarifa());
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** alguém tratar fila vazia como erro.
     * Medido em 15/10/2026: **nada** cria `PubAprovacaoOferta` hoje (quem passa a criar é a Fase
     * 194), então `trios=0` é o número honesto — e gritar por um estado correto treina quem lê o
     * log a ignorá-lo.
     *
     * @group phase187
     */
    public function test_fila_vazia_devolve_zero_trios_e_isso_nao_e_erro_187_10(): void
    {
        $this->comDadosEfetivos();
        $conta = Company::factory()->create();

        $this->assertSame(0, PubAprovacaoOferta::query()->count(), 'a fila deveria estar vazia neste cenário');

        $r = $this->servico()->paraAConta($conta->id);

        $this->assertSame(0, $r['trios']);
        $this->assertSame(0, $r['aquecidos']);
        $this->assertSame(0, $r['pulados']);
        $this->assertSame(0, $r['falhas']);
        $this->assertArrayHasKey('segundos', $r, 'o relatório perdeu o tempo gasto');
        $this->assertSame(0, $this->requisicoesDeTarifa(), 'fila vazia consultou o Mercado Livre');

        // ⚠️ A forma do relatório é FECHADA: chave nova entra por decisão, não por acidente.
        $this->assertSame(
            ['trios', 'aquecidos', 'pulados', 'falhas', 'segundos'],
            array_keys($r),
            'a forma do relatório mudou — o comando e o job leem estas chaves',
        );
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** o `try/catch` ser da VARREDURA e não da
     * OFERTA. Aí uma oferta com dado ruim deixaria **todos os outros trios frios**, e a tela
     * pagaria a consulta no request de todos eles — degradação pela porta dos fundos.
     *
     * @group phase187
     */
    public function test_falha_de_uma_oferta_nao_interrompe_as_outras_187_10(): void
    {
        $this->comTarifa(self::CLASSICO);
        $this->comTarifa(self::PREMIUM);
        $duble = $this->comDadosEfetivos();

        $conta = Company::factory()->create();
        $this->oferta($conta, ['tipo' => self::CLASSICO, 'sku' => 'BOA-1']);
        $this->oferta($conta, ['tipo' => self::PREMIUM, 'sku' => 'RUIM']);
        $this->oferta($conta, ['tipo' => self::CLASSICO, 'sku' => 'BOA-2']);
        $duble->precoPorSku['BOA-2'] = 999.0;
        $duble->skusQueExplodem = ['RUIM'];

        $r = $this->servico()->paraAConta($conta->id);

        $this->assertSame(3, $r['trios'], 'os três trios deveriam ter sido contados');
        $this->assertSame(1, $r['falhas'], 'a oferta que explode deveria contar UMA falha');
        $this->assertSame(2, $r['aquecidos'], 'as duas boas deveriam ter aquecido mesmo com a terceira falhando');
        $this->assertSame(0, $r['pulados'], 'falhar não é pular');

        // ⚠️ E as duas boas foram DE FATO à rede, em trios distintos.
        $trios = array_unique($this->triosRequisitados());
        $this->assertSame(2, count($trios), 'as duas boas não consultaram os dois trios');
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** alguém trocar `para()` por `congelar()`
     * (ou acrescentar uma gravação "de passagem") e o aquecimento virar **escrita em massa fora do
     * request**. O docblock da Fase 186 é explícito: *"Nada é gravado aqui. Quem grava é
     * `congelar()`"*.
     *
     * ⚠️ E a segunda metade: `--dry-run` conta os trios e **não consulta nada** — garantido por
     * `Http::preventStrayRequests()`, que faria o caso EXPLODIR se uma requisição saísse.
     *
     * @group phase187
     */
    public function test_o_aquecimento_nao_grava_nada_e_o_simular_nao_consulta_187_10(): void
    {
        $this->comTarifa(self::CLASSICO);
        $this->comTarifa(self::PREMIUM);
        $this->comDadosEfetivos();

        $conta = Company::factory()->create();
        $this->oferta($conta, ['tipo' => self::CLASSICO]);
        $this->oferta($conta, ['tipo' => self::PREMIUM]);

        $tabelas = ['pub_aprovacao_ofertas', 'pub_aprovacao_composicoes', 'pub_aprovacao_eventos'];
        $antes = [];
        foreach ($tabelas as $t) {
            $antes[$t] = DB::table($t)->count();
        }
        $this->assertSame(2, $antes['pub_aprovacao_ofertas'], 'o cenário não tem as duas ofertas — o gate mediria tabela vazia');

        $r = $this->servico()->paraAConta($conta->id);
        $this->assertSame(2, $r['aquecidos'], 'o aquecimento não rodou — o gate de escrita seria vazio');

        $depois = [];
        foreach ($tabelas as $t) {
            $depois[$t] = DB::table($t)->count();
        }
        $this->assertSame($antes, $depois, 'o aquecimento GRAVOU algo — `para()` deveria ser leitura pura');

        // ── `--dry-run`: conta os trios e não consulta nada ──
        $conta2 = Company::factory()->create();
        $this->oferta($conta2, ['tipo' => self::CLASSICO, 'sku' => 'SECO-1']);
        $this->oferta($conta2, ['tipo' => self::PREMIUM, 'sku' => 'SECO-2']);
        $antesDaRede = $this->requisicoesDeTarifa();

        $seco = $this->servico()->paraAConta($conta2->id, null, true);

        $this->assertSame(2, $seco['trios'], '`--dry-run` deveria CONTAR os trios');
        $this->assertSame(0, $seco['aquecidos'], '`--dry-run` aqueceu alguma coisa');
        $this->assertSame($antesDaRede, $this->requisicoesDeTarifa(), '`--dry-run` consultou o Mercado Livre');
    }

    /**
     * ⚠️ **O que precisaria estar errado para falhar:** o aquecimento engolir a `RegraViolada` das
     * duas âncoras nulas e varrer a fila de **todas** as empresas — gastando chamadas ao Mercado
     * Livre por conta própria, num endpoint cujo limite já é disputado por cinco rotinas
     * agendadas. Não existe "aquecer a fila de todo mundo".
     *
     * @group phase187
     */
    public function test_sem_recorte_de_conta_o_aquecimento_propaga_a_regra_violada_187_10(): void
    {
        $this->comDadosEfetivos();

        $this->expectException(RegraViolada::class);

        $this->servico()->paraAConta(null, null);
    }
}
