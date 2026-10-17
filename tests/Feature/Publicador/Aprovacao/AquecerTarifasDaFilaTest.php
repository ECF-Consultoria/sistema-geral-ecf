<?php

namespace Tests\Feature\Publicador\Aprovacao;

use App\Jobs\AquecerTarifasDaFilaJob;
use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubAprovacaoOferta;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubRascunhoAlvo;
use App\Models\User;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Publicador\Aprovacao\Fila\AquecimentoDeTarifasService;
use App\Services\Publicador\DadosEfetivosService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Fase 187, plano 10 — O JOB NA FILA `high`, OS DOIS DISPAROS E O COMANDO MANUAL.
 *
 * ⚠️⚠️ DE CADA GATE DESTE ARQUIVO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR:
 * **"o que precisaria estar errado no mundo para ele falhar?"**
 *
 *   · **A FILA ERRADA** — alguém pôr o job na `default`. Em **16/09/2026**, **179 jobs de acervo
 *     do ML na `default`** seguraram um webhook da Clicksign **por horas** (a tabela `jobs` dá 0 e
 *     engana: a fila de produção é **Redis**). Este aquecimento é disparado por **alguém abrindo a
 *     tela** e o valor dele **expira com a visita** (cache de 1 h) — atrás de 179 jobs de acervo
 *     ele chegaria **depois** de a pessoa já ter esperado. A asserção é sobre a **FILA**, não sobre
 *     a classe.
 *   · **O DISPARO DERRUBANDO A TELA** — `meus()` é a lista de Publicações, que está **NO AR**.
 *     Redis fora do ar é problema de aquecimento, **não** motivo para ela parar de abrir. O caso
 *     com a fila de jobs INDISPONÍVEL exige **200** nas duas telas — e tem isca provando que, sem
 *     o `try/catch`, o dispatch de verdade **LANÇA** naquela configuração.
 *   · **F5 ENCHENDO A `high`** — sem trava, cada recarga empilharia um job.
 *   · **A TRAVA LONGA DEMAIS** — trava de 1 h ou mais impediria o **reaquecimento** depois de o
 *     cache esfriar: a pessoa abriria a tela, a trava diria "já aqueci", e a tela pagaria a
 *     consulta no request. ⚠️ A janela é cobrada como **estritamente menor** que o TTL de 1 h.
 *   · **O RETRY** — `tries > 1` empilharia chamadas ao Mercado Livre por um ganho já expirado.
 *   · **O CRON** — `dailyAt()` aqueceria às 6h e estaria **frio às 7h** (TTL de 1 h): agendamento
 *     **sem efeito**, que é a definição de decorativo. E `hourly()` faria 24 varreduras por dia de
 *     uma fila hoje **vazia**, gastando chamadas contra um limite que `adman:sync`, `ml:sync`,
 *     `adman:sync-margem`, `adman:warm-diff` e o warm do fechamento já disputam. ⚠️ Gate de
 *     AUSÊNCIA, com isca, e a razão escrita no docblock do comando.
 *   · **LÓGICA DUPLICADA NO COMANDO** — comando e job TÊM de compartilhar o mesmo serviço, senão
 *     o `--dry-run` mediria uma coisa e o job aqueceria outra.
 *   · **"0 TRIOS" PARECENDO ERRO** — nada cria `PubAprovacaoOferta` até a Fase 194, então `0` é o
 *     número honesto; gritar por um estado correto treina quem lê o log a ignorá-lo.
 *   · **UMA CONTA DERRUBANDO AS OUTRAS** — `Log then continue` por conta.
 *   · **O RECORTE** — o job de uma conta aquecendo a fila de outra.
 *
 * ⚠️ **ZERO REDE:** `Http::preventStrayRequests()` + `Http::fake` com gravador, em TODO caso.
 *
 * ⚠️ **A fila APROVA (e aprovar enfileira), NÃO PUBLICA.** Este plano só LÊ tarifa; a escrita no
 * Mercado Livre é da **Fase 193**. Nenhum caso deste arquivo publica nada.
 *
 * ⚠️ **Nenhum executor desta fase viu esta tela renderizada nem este job rodando em produção:** o
 * banco local não tem empresa com `ml_token` fora de fixture. A conferência é do usuário, em
 * produção, na conta **459**.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class AquecerTarifasDaFilaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ O commit de PARTIDA **deste plano**, capturado na PRIMEIRA ação do executor.
     *
     * Não é o da fase: o range da fase acusa o trabalho legítimo das outras waves e das sessões
     * paralelas. E ele sozinho não basta — ver {@see self::ESCOPO}.
     */
    private const PARTIDA = 'f75864d0';

    /**
     * O ESCOPO DO ASSUNTO deste plano.
     *
     * ⚠️ **O autor NÃO discrimina nada nesta árvore:** as três sessões rodam com o MESMO
     * `user.name`. O discriminador é o escopo — e há isca nas duas direções, inclusive contra o
     * plano **187-09**, que roda EM PARALELO nesta mesma árvore.
     */
    private const ESCOPO = '/\(187-10\)/';

    private const CLASSICO = 'gold_special';

    private const PREMIUM = 'gold_pro';

    private const CATEGORIA = 'MLB189007';

    /** O TTL da tarifa, em segundos — `config/publicador.php:97`. A trava tem de ser MENOR. */
    private const TTL_DA_TARIFA = 3600;

    /** Os arquivos de produção DESTE plano. */
    private const MEUS = [
        'app/Services/Publicador/Aprovacao/Fila/AquecimentoDeTarifasService.php',
        'app/Jobs/AquecerTarifasDaFilaJob.php',
        'app/Console/Commands/WarmTarifasDaFila.php',
    ];

    private int $sequencia = 0;

    private array $tarifas = [];

    /** @var list<array{caminho: string, query: array}> */
    private array $chamadas = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-17 09:00:00');
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Fixture
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{0: Company, 1: MlbEmpresa, 2: User} */
    private function conta(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = Company::factory()->create();

        MlToken::create([
            'company_id' => $company->id,
            'ml_user_id' => (string) random_int(100000000, 999999999),
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer',
            'scope' => 'read write offline_access',
            'expires_at' => now()->addDays(6),
            'last_refreshed_at' => now(),
            'status' => 'active',
            'connected_at' => now(),
        ]);

        $empresa = MlbEmpresa::create([
            'nome' => 'Empresa Aquecimento '.$company->id,
            'tipo' => 'ASSESSORIA',
            'company_id' => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        return [$company, $empresa, $admin];
    }

    /**
     * ⚠️⚠️ `categoria_id` vem PREENCHIDO, e é o invariante da fixture: sem ele o serviço da Fase
     * 186 recusa em `:470` **sem sair para a rede**, e todo gate de custo desta wave seria vazio
     * por construção — o modo de falhar MEDIDO em `FilaDeAprovacaoRotaTest:161`.
     *
     * @param  array{tipo?: string, sku?: string}  $o
     */
    private function oferta(Company $empresa, array $o = []): PubAprovacaoOferta
    {
        $this->sequencia++;
        $sku = $o['sku'] ?? ('JOB-'.$this->sequencia);

        $produto = PubProduto::create([
            'company_id' => $empresa->id,
            'sku' => $sku,
            'nome' => 'Cadeira '.$sku,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
        $rascunho = PubRascunho::create([
            'produto_id' => $produto->id,
            'categoria_id' => self::CATEGORIA,
        ]);
        $alvo = PubRascunhoAlvo::create([
            'rascunho_id' => $rascunho->id,
            'listing_type_id' => $o['tipo'] ?? self::CLASSICO,
            'posicao' => 0,
        ]);

        return PubAprovacaoOferta::create([
            'alvo_id' => $alvo->id,
            'rascunho_id' => $rascunho->id,
            'produto_id' => $produto->id,
            'company_id' => $empresa->id,
            'andamento' => PubAprovacaoOferta::AGUARDANDO_APROVACAO,
            'entrou_na_fila_em' => '2026-10-16 08:00:00',
        ]);
    }

    private function comDadosEfetivos(float $preco = 1200.0): object
    {
        $duble = new class(app(EstruturaPrecificacaoService::class)) extends DadosEfetivosService
        {
            public float $oPreco = 1200.0;

            /** @var array<string, float> */
            public array $precoPorSku = [];

            public function custosDoProduto(PubProduto $produto): array
            {
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

        $duble->oPreco = $preco;
        $this->app->instance(DadosEfetivosService::class, $duble);

        return $duble;
    }

    private function comTarifa(string $listingType = self::CLASSICO): void
    {
        $arquivo = base_path('tests/fixtures-ml/sondagem/publico/categorias/'.self::CATEGORIA."/listing_prices_{$listingType}.json");
        $this->assertFileExists($arquivo, 'fixture de tarifa ausente — o gate rodaria sobre zero casos');

        $this->tarifas[self::CATEGORIA.'|'.$listingType] = json_decode((string) file_get_contents($arquivo), true)['resposta'];
    }

    private function requisicoesDeTarifa(): int
    {
        return count(array_filter(
            $this->chamadas,
            static fn (array $c): bool => $c['caminho'] === '/sites/MLB/listing_prices',
        ));
    }

    /** Os tipos de anúncio que de fato saíram pela rede. */
    private function tiposRequisitados(): array
    {
        $tipos = [];
        foreach ($this->chamadas as $c) {
            if ($c['caminho'] === '/sites/MLB/listing_prices') {
                $tipos[] = (string) ($c['query']['listing_type_id'] ?? '');
            }
        }

        return array_values(array_unique($tipos));
    }

    private function versaoInertia(): string
    {
        return (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    }

    private function propsDePublicacoes(User $admin, Company $company): array
    {
        return $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->versaoInertia()])
            ->get(route('mlb.anuncios.meus', $company))
            ->assertOk()
            ->json('props');
    }

    private function raiz(): string
    {
        return rtrim(str_replace('\\', '/', base_path()), '/');
    }

    private function git(string $comando): string
    {
        return trim((string) shell_exec('cd '.escapeshellarg($this->raiz()).' && '.$comando.' 2>&1'));
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

    private function codigoSemComentario(string $relativo): string
    {
        $caminho = $this->raiz().'/'.$relativo;
        $this->assertFileExists($caminho, "arquivo inexistente: {$relativo} — o gate mediria NADA");

        return $this->semComentariosPhp((string) file_get_contents($caminho));
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (1) O JOB — a fila `high`, a tentativa única e a trava
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ A asserção é sobre a **FILA**, não sobre a classe. A mutação do plano (`'high'` →
     * `'default'`) reprova aqui.
     *
     * @group phase187
     */
    public function test_o_job_do_aquecimento_vai_para_a_fila_high_com_uma_tentativa_187_10(): void
    {
        Queue::fake();
        [$company] = $this->conta();

        $this->assertTrue(AquecerTarifasDaFilaJob::despacharParaAConta($company->id), 'o dispatch não aconteceu');

        Queue::assertPushedOn('high', AquecerTarifasDaFilaJob::class);

        // ⚠️ ISCA DA SONDA: a fila está LITERALMENTE em `high` no objeto, e não é `default` nem
        // `creative` (a `creative` é do Creative Engine, território de outro dev).
        $job = new AquecerTarifasDaFilaJob($company->id);
        $this->assertSame('high', $job->queue, 'o job saiu da fila `high`');
        $this->assertNotSame('default', $job->queue);
        $this->assertNotSame('creative', $job->queue);

        // ⚠️ UMA tentativa: se falhar, a próxima abertura da tela dispara de novo. Retry
        // empilharia chamadas ao Mercado Livre por um ganho que já expirou.
        $this->assertSame(1, $job->tries, '`tries > 1` empilha chamadas ao ML');
        $this->assertGreaterThan(0, $job->timeout, 'job de aquecimento sem timeout pode pendurar o worker');
    }

    /**
     * ⚠️ A trava existe para F5 repetido não encher a `high`; e a janela é **estritamente menor**
     * que o TTL de 1 h da tarifa, senão ela impediria o REAQUECIMENTO depois de o cache esfriar.
     *
     * @group phase187
     */
    public function test_a_trava_faz_duas_aberturas_despacharem_UMA_vez_187_10(): void
    {
        Queue::fake();
        [$company] = $this->conta();

        $this->assertTrue(AquecerTarifasDaFilaJob::despacharParaAConta($company->id), 'a 1ª abertura não despachou');
        $this->assertFalse(AquecerTarifasDaFilaJob::despacharParaAConta($company->id), 'a 2ª abertura dentro da janela despachou de novo');
        Queue::assertPushed(AquecerTarifasDaFilaJob::class, 1);

        // ── Passada a janela, despacha de novo ──
        Cache::lock(AquecerTarifasDaFilaJob::chaveDaTrava($company->id), 1)->forceRelease();
        $this->assertTrue(AquecerTarifasDaFilaJob::despacharParaAConta($company->id), 'passada a janela, a abertura deveria despachar');
        Queue::assertPushed(AquecerTarifasDaFilaJob::class, 2);

        // ⚠️ A trava é POR CONTA: uma conta aquecendo não tranca a fila de outra.
        [$outra] = $this->conta();
        $this->assertTrue(AquecerTarifasDaFilaJob::despacharParaAConta($outra->id), 'a trava de uma conta trancou a outra');
        $this->assertNotSame(
            AquecerTarifasDaFilaJob::chaveDaTrava($company->id),
            AquecerTarifasDaFilaJob::chaveDaTrava($outra->id),
            'a chave da trava não distingue conta — uma conta trancaria todas',
        );

        // ⚠️⚠️ A janela MENOR que o TTL de 1 h, e o número lido das duas pontas.
        $this->assertSame(self::TTL_DA_TARIFA, (int) config('publicador.alavancas.cache.tarifa'), 'o TTL da tarifa mudou — a janela da trava tem de ser reconferida');
        $this->assertLessThan(
            self::TTL_DA_TARIFA,
            AquecerTarifasDaFilaJob::TRAVA_SEGUNDOS,
            'a trava é MAIOR ou IGUAL ao TTL da tarifa: ela impediria o reaquecimento depois de o cache esfriar',
        );
    }

    /**
     * ⚠️ O recorte: o job de uma conta nunca aquece outra. A isca é o tipo de anúncio — a outra
     * conta só tem `gold_pro`, então se o recorte furasse apareceria uma requisição `gold_pro`.
     *
     * @group phase187
     */
    public function test_o_aquecimento_de_uma_conta_nunca_toca_outra_187_10(): void
    {
        $this->comTarifa(self::CLASSICO);
        $this->comTarifa(self::PREMIUM);
        $this->comDadosEfetivos();

        [$minha] = $this->conta();
        [$alheia] = $this->conta();
        $this->oferta($minha, ['tipo' => self::CLASSICO]);
        $this->oferta($alheia, ['tipo' => self::PREMIUM]);

        (new AquecerTarifasDaFilaJob($minha->id))->handle(app(AquecimentoDeTarifasService::class));

        $this->assertSame(1, $this->requisicoesDeTarifa(), 'saiu mais de uma requisição — a conta alheia entrou');
        $this->assertSame([self::CLASSICO], $this->tiposRequisitados(), 'a requisição da conta ALHEIA saiu');

        // ⚠️ ISCA: o job da OUTRA conta aquece o tipo dela. Sem esta linha, o `[CLASSICO]` acima
        // poderia ser um job que nunca aquece nada.
        (new AquecerTarifasDaFilaJob($alheia->id))->handle(app(AquecimentoDeTarifasService::class));
        $this->assertSame([self::CLASSICO, self::PREMIUM], $this->tiposRequisitados(), 'ISCA: o job da outra conta não aqueceu');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (2) OS DOIS DISPAROS — e o que acontece quando a fila de jobs não responde
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ É o disparo que de fato aquece ANTES do uso: `/mlb/anuncios/meus/{company}` é o passo
     * imediatamente anterior a clicar na aba "Aguardando aprovação".
     *
     * @group phase187
     */
    public function test_abrir_publicacoes_despacha_o_aquecimento_da_conta_187_10(): void
    {
        Queue::fake();
        [$company, , $admin] = $this->conta();

        $this->propsDePublicacoes($admin, $company);

        Queue::assertPushed(
            AquecerTarifasDaFilaJob::class,
            static fn (AquecerTarifasDaFilaJob $j): bool => $j->companyId === $company->id,
        );
        Queue::assertPushedOn('high', AquecerTarifasDaFilaJob::class);
    }

    /**
     * ⚠️ O segundo disparo cobre quem chega por URL direta ou recarrega: ele não ajuda AQUELE
     * request (a página já está calculando a composição no mesmo instante), mas aquece a PRÓXIMA
     * abertura dentro da hora.
     *
     * @group phase187
     */
    public function test_abrir_a_fila_tambem_despacha_o_aquecimento_187_10(): void
    {
        Queue::fake();
        [$company, , $admin] = $this->conta();

        $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->versaoInertia()])
            ->get(route('mlb.anuncios.meus.aprovacao', $company))
            ->assertOk();

        Queue::assertPushed(
            AquecerTarifasDaFilaJob::class,
            static fn (AquecerTarifasDaFilaJob $j): bool => $j->companyId === $company->id,
        );
        Queue::assertPushedOn('high', AquecerTarifasDaFilaJob::class);
    }

    /**
     * ⚠️⚠️ O caso que prova que o aquecimento é ACESSÓRIO: com a fila de jobs **indisponível**, as
     * duas telas respondem **200**.
     *
     * ⚠️ A ISCA é o que torna este caso uma medição e não uma afirmação: na MESMA configuração, o
     * `dispatch()` cru **LANÇA**. Sem ela, o 200 poderia ser um cenário em que nada deu errado.
     *
     * @group phase187
     */
    public function test_fila_de_jobs_indisponivel_nao_derruba_nenhuma_das_duas_telas_187_10(): void
    {
        [$company, , $admin] = $this->conta();

        // Conexão de fila que NÃO existe: o `QueueManager` lança ao resolvê-la.
        config(['queue.default' => 'inexistente-187-10']);

        // ⚠️ ISCA: o dispatch CRU lança nesta configuração.
        $lancou = false;
        try {
            AquecerTarifasDaFilaJob::dispatch($company->id);
        } catch (\Throwable $e) {
            $lancou = true;
        }
        $this->assertTrue($lancou, 'ISCA: o dispatch não lança nesta configuração — o caso não mede nada');

        // ⚠️ E o nosso despachante NÃO lança: ele devolve `false` e registra.
        $this->assertFalse(AquecerTarifasDaFilaJob::despacharParaAConta($company->id), 'o despachante deveria devolver false, não lançar');

        // ── As duas telas respondem 200 ──
        Cache::lock(AquecerTarifasDaFilaJob::chaveDaTrava($company->id), 1)->forceRelease();
        $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->versaoInertia()])
            ->get(route('mlb.anuncios.meus', $company))
            ->assertOk();

        Cache::lock(AquecerTarifasDaFilaJob::chaveDaTrava($company->id), 1)->forceRelease();
        $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->versaoInertia()])
            ->get(route('mlb.anuncios.meus.aprovacao', $company))
            ->assertOk();
    }

    /**
     * ⚠️ O disparo é ADITIVO: nenhuma prop muda e nenhuma entra. A contagem é FIXA, então prop
     * nova nesta tela é decisão visível e não acidente.
     *
     * @group phase187
     */
    public function test_publicacoes_continua_com_as_MESMAS_props_187_10(): void
    {
        Queue::fake();
        [$company, , $admin] = $this->conta();

        $props = $this->propsDePublicacoes($admin, $company);

        $minhas = array_values(array_filter(
            array_keys($props),
            static fn (string $k): bool => str_contains(strtolower($k), 'aquec') || str_contains(strtolower($k), 'tarifa'),
        ));
        $this->assertSame([], $minhas, 'este plano acrescentou prop à tela de Publicações — ele é só o disparo do job');

        foreach (['empresa', 'conta', 'cabecalho', 'sub', 'subTotais', 'anuncios', 'rascunhos', 'triagem',
            'filtros', 'abas', 'contagens', 'grupos', 'rodape', 'sincronizacao', 'faixaSucesso',
            'skuNaoColetado', 'defasagem', 'saudeMlDisponivel', 'rotacaoN'] as $chave) {
            $this->assertArrayHasKey($chave, $props, "a prop `{$chave}` desapareceu da tela de Publicações");
        }

        // ⚠️ ISCA DA SONDA de prop nova: ela PEGA um nome do assunto deste plano.
        $sintetico = array_keys(['empresa' => 1, 'aquecimentoDaTarifa' => 1]);
        $pegos = array_values(array_filter($sintetico, static fn (string $k): bool => str_contains(strtolower($k), 'aquec')));
        $this->assertSame(['aquecimentoDaTarifa'], $pegos, 'ISCA: a sonda de prop nova está cega');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // (3) O COMANDO — a forma do `adman:warm-diff`, o `--dry-run` e a ausência do cron
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️ O relatório é **linha de forma fixa** e o teste casa a linha INTEIRA (regex ancorada nas
     * duas pontas), não um pedaço: gate que casa um pedaço passa com a linha meio montada.
     *
     * @group phase187
     */
    public function test_o_comando_imprime_o_relatorio_em_linha_de_forma_fixa_187_10(): void
    {
        $this->comTarifa(self::CLASSICO);
        $this->comDadosEfetivos();

        [$company] = $this->conta();
        $this->oferta($company, ['tipo' => self::CLASSICO]);
        $this->oferta($company, ['tipo' => self::CLASSICO]);

        $codigo = Artisan::call('publicador:warm-tarifas', ['--empresa' => $company->id]);
        $saida = Artisan::output();

        $this->assertSame(0, $codigo, 'o comando não devolveu SUCCESS');
        $this->assertMatchesRegularExpression(
            '/^\[AquecimentoTarifas\] concluído em [0-9]+(\.[0-9]+)?s — contas=1 trios=1 OK=1 FAIL=0 pulados=0$/m',
            trim($saida),
            'a linha de forma fixa do relatório mudou',
        );
        $this->assertSame(1, $this->requisicoesDeTarifa(), 'duas ofertas do MESMO trio deveriam custar UMA requisição');

        // ⚠️ ISCA DA REGEX: ela recusa a linha com um campo faltando.
        $this->assertDoesNotMatchRegularExpression(
            '/^\[AquecimentoTarifas\] concluído em [0-9]+(\.[0-9]+)?s — contas=1 trios=1 OK=1 FAIL=0 pulados=0$/m',
            '[AquecimentoTarifas] concluído em 0.1s — contas=1 trios=1 OK=1 FAIL=0',
            'ISCA: a regex do relatório aceita linha incompleta',
        );
    }

    /**
     * ⚠️ `--dry-run` conta os trios e **não consulta nada** — e o `Http::preventStrayRequests()`
     * do `setUp` faria o caso EXPLODIR se uma requisição saísse.
     *
     * @group phase187
     */
    public function test_o_comando_com_dry_run_nao_faz_requisicao_nenhuma_187_10(): void
    {
        $this->comTarifa(self::CLASSICO);
        $this->comTarifa(self::PREMIUM);
        $this->comDadosEfetivos();

        [$company] = $this->conta();
        $this->oferta($company, ['tipo' => self::CLASSICO]);
        $this->oferta($company, ['tipo' => self::PREMIUM]);

        $codigo = Artisan::call('publicador:warm-tarifas', ['--empresa' => $company->id, '--dry-run' => true]);
        $saida = trim(Artisan::output());

        $this->assertSame(0, $codigo);
        $this->assertMatchesRegularExpression('/trios=2 OK=1 FAIL=0 pulados=0$/m', $saida, '`--dry-run` deveria CONTAR os dois trios');
        $this->assertSame(0, $this->requisicoesDeTarifa(), '`--dry-run` consultou o Mercado Livre');

        // ⚠️ ISCA: sem `--dry-run`, a MESMA fixture consulta. Sem ela, o ZERO acima poderia ser um
        // cenário em que não havia o que aquecer.
        Artisan::call('publicador:warm-tarifas', ['--empresa' => $company->id]);
        $this->assertSame(2, $this->requisicoesDeTarifa(), 'ISCA: sem `--dry-run` o comando também não consultou');
    }

    /**
     * ⚠️ Sem `--empresa`, o universo são as contas que TÊM oferta na fila. Varrer as ~84 empresas
     * do sistema para descobrir que nenhuma tem oferta seria gastar consulta para confirmar o
     * óbvio — e `pub_aprovacao_ofertas` está **vazia** hoje.
     *
     * @group phase187
     */
    public function test_o_comando_sem_empresa_varre_as_contas_com_oferta_187_10(): void
    {
        $this->comTarifa(self::CLASSICO);
        $this->comDadosEfetivos();

        [$comOferta1] = $this->conta();
        [$comOferta2] = $this->conta();
        [$semOferta] = $this->conta();
        $this->oferta($comOferta1);
        $this->oferta($comOferta2);

        Artisan::call('publicador:warm-tarifas');
        $this->assertMatchesRegularExpression('/contas=2 /', trim(Artisan::output()), 'o comando não varreu exatamente as duas contas com oferta');
        $this->assertNotNull($semOferta->id, 'a conta sem oferta precisa existir para o caso discriminar');

        // ── E com a fila VAZIA, nenhuma conta é varrida ──
        PubAprovacaoOferta::query()->delete();
        Artisan::call('publicador:warm-tarifas');
        $this->assertMatchesRegularExpression('/contas=0 trios=0 /', trim(Artisan::output()), 'com a fila vazia o comando varreu conta');
    }

    /**
     * ⚠️⚠️ "0 trios" é **SUCESSO**, e a saída não pode ter vocabulário de erro: nada cria
     * `PubAprovacaoOferta` até a Fase 194, então `0` é o número honesto — e gritar por um estado
     * correto treina quem lê o log a ignorá-lo.
     *
     * @group phase187
     */
    public function test_o_comando_com_fila_vazia_da_SUCCESS_e_nao_parece_erro_187_10(): void
    {
        $this->comDadosEfetivos();
        [$company] = $this->conta();

        $this->assertSame(0, PubAprovacaoOferta::query()->count(), 'a fila deveria estar vazia neste cenário');

        $codigo = Artisan::call('publicador:warm-tarifas', ['--empresa' => $company->id]);
        $saida = trim(Artisan::output());

        $this->assertSame(0, $codigo, '"0 trios" devolveu outra coisa que não SUCCESS');
        $this->assertMatchesRegularExpression(
            '/^\[AquecimentoTarifas\] concluído em [0-9]+(\.[0-9]+)?s — contas=1 trios=0 OK=1 FAIL=0 pulados=0$/m',
            $saida,
        );

        $vocabularioDeErro = ['falhou', 'falha', 'erro', 'exception'];
        $achados = array_values(array_filter(
            $vocabularioDeErro,
            static fn (string $p): bool => str_contains(mb_strtolower($saida), $p),
        ));
        $this->assertSame([], $achados, 'a saída de uma fila vazia tem vocabulário de erro');
        $this->assertSame(0, $this->requisicoesDeTarifa(), 'fila vazia consultou o Mercado Livre');

        // ⚠️ ISCA DA SONDA: ela PEGA os QUATRO termos quando eles existem.
        //
        // ⚠️ A primeira redação desta isca usava só *"a conta falhou de propósito"* e esperava
        // `['falhou', 'falha']` — **medido em 17/10/2026: ela REPROVOU**, porque `falha` **não** é
        // substring de `falhou` (`falho` + `u`). O conserto certo foi exercer os quatro termos
        // numa frase que os contém, não baixar a expectativa: isca que cobre metade da lista
        // deixa a outra metade sem prova.
        $pegos = array_values(array_filter(
            $vocabularioDeErro,
            static fn (string $p): bool => str_contains(mb_strtolower('[X] erro: a conta falhou, uma falha — exception no meio'), $p),
        ));
        $this->assertSame($vocabularioDeErro, $pegos, 'ISCA: a sonda de vocabulário de erro está cega');
    }

    /**
     * ⚠️ Uma conta que falha não interrompe as demais: o `try/catch` é POR CONTA. O dublê faz a
     * conta do meio LANÇAR — é a única forma honesta de exercer o `catch` do laço.
     *
     * @group phase187
     */
    public function test_uma_conta_que_falha_nao_interrompe_as_demais_187_10(): void
    {
        [$a] = $this->conta();
        [$b] = $this->conta();
        [$c] = $this->conta();

        $duble = new class(
            app(\App\Services\Publicador\Aprovacao\Fila\FilaDeAprovacaoService::class),
            app(\App\Services\Publicador\Aprovacao\ComposicaoFinanceiraService::class),
        ) extends AquecimentoDeTarifasService
        {
            public int $queExplode = 0;

            /** @var list<int> */
            public array $contasVistas = [];

            public array $universo = [];

            public function paraAConta(?int $companyId = null, ?int $mlbEmpresaId = null, bool $simular = false): array
            {
                $this->contasVistas[] = (int) $companyId;

                if ($companyId === $this->queExplode) {
                    throw new \RuntimeException('conta indisponível de propósito');
                }

                return ['trios' => 1, 'aquecidos' => 1, 'pulados' => 0, 'falhas' => 0, 'segundos' => 0.0];
            }

            public function contasComOferta(): array
            {
                return $this->universo;
            }
        };
        $duble->queExplode = $b->id;
        $duble->universo = [$a->id, $b->id, $c->id];
        $this->app->instance(AquecimentoDeTarifasService::class, $duble);

        $codigo = Artisan::call('publicador:warm-tarifas');
        $saida = trim(Artisan::output());

        $this->assertSame(0, $codigo, 'uma conta falhando derrubou o comando');
        $this->assertSame([$a->id, $b->id, $c->id], $duble->contasVistas, 'o laço parou na conta que falhou');
        $this->assertMatchesRegularExpression('/contas=3 trios=2 OK=2 FAIL=1 pulados=0$/m', $saida, 'o relatório não separou OK de FAIL');
    }

    /**
     * ⚠️⚠️ GATE DE AUSÊNCIA: não existe `Schedule::command('publicador:warm-tarifas')`.
     *
     * A razão é MEDIDA: o `adman:warm-diff` é agendado 2× por dia porque o cache dele é **DIÁRIO**
     * (*"auto-invalida à meia-noite"*); o cache da tarifa é de **1 HORA**. Um `dailyAt()` aqueceria
     * às 6h e estaria frio às 7h — agendamento **sem efeito**. Um `hourly()` faria 24 varreduras
     * por dia de uma fila hoje vazia, contra um limite de requisições já disputado por cinco
     * rotinas. ➡️ Quem resolve o caso real é o JOB, disparado por quem abre a tela.
     *
     * @group phase187
     */
    public function test_NAO_existe_agendamento_do_warm_tarifas_no_cron_187_10(): void
    {
        $cron = $this->codigoSemComentario('routes/console.php');

        $sonda = static fn (string $texto): bool => str_contains($texto, 'publicador:warm-tarifas');

        $this->assertFalse($sonda($cron), 'o comando foi agendado: com TTL de 1 h, `dailyAt()` é decorativo e `hourly()` gasta chamadas ao ML');

        // ⚠️ CONTROLE POSITIVO: a sonda lê o arquivo CERTO e a forma CERTA — o `adman:warm-diff`
        // ESTÁ agendado lá. Sem isto, o `false` acima poderia ser um arquivo vazio.
        $this->assertStringContainsString("Schedule::command('adman:warm-diff')", $cron, 'o controle positivo do cron desapareceu — a sonda não está lendo `routes/console.php`');

        // ⚠️ ISCA: a sonda PEGA um agendamento sintético.
        $this->assertTrue($sonda("Schedule::command('publicador:warm-tarifas')->hourly();"), 'ISCA: a sonda do agendamento está cega');

        // ⚠️ E A RAZÃO ESTÁ ESCRITA no docblock do comando — ausência sem razão escrita é
        // esquecimento disfarçado de decisão. (Lido com comentário, de propósito: é o docblock.)
        $comando = (string) file_get_contents($this->raiz().'/app/Console/Commands/WarmTarifasDaFila.php');
        foreach (['1 HORA', 'dailyAt', 'hourly', 'adman:warm-diff'] as $palavra) {
            $this->assertStringContainsString($palavra, $comando, "a razão da ausência do agendamento não menciona `{$palavra}`");
        }
    }

    /**
     * ⚠️ Comando e job compartilham o MESMO serviço: zero lógica duplicada. Se o comando tivesse a
     * sua própria, o `--dry-run` mediria uma coisa e o job aqueceria outra.
     *
     * @group phase187
     */
    public function test_o_comando_nao_duplica_a_logica_do_servico_187_10(): void
    {
        $comando = $this->codigoSemComentario('app/Console/Commands/WarmTarifasDaFila.php');
        $job = $this->codigoSemComentario('app/Jobs/AquecerTarifasDaFilaJob.php');
        $dono = $this->codigoSemComentario('app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php');

        // ── As três ausências no comando ──
        foreach (['->para(', 'Cache::', '/sites/MLB/'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $comando, "o comando tem `{$proibido}` — ele deveria só chamar o serviço");
        }

        // ⚠️ ISCA DAS TRÊS SONDAS, no arquivo onde elas DEVEM casar: o serviço da Fase 186 tem as
        // três. Sem isto, as ausências acima poderiam ser sondas cegas.
        foreach (['->para(', 'Cache::', '/sites/MLB/'] as $proibido) {
            $this->assertStringContainsString($proibido, $dono, "ISCA: a sonda de `{$proibido}` está cega");
        }

        // ── E os dois chamam o MESMO serviço ──
        $this->assertStringContainsString('AquecimentoDeTarifasService', $comando, 'o comando não usa o serviço do aquecimento');
        $this->assertStringContainsString('AquecimentoDeTarifasService', $job, 'o job não usa o serviço do aquecimento');

        // ⚠️ E o job também não monta nada: `Cache::` nele é **só a trava**, nunca a chave da
        // tarifa. A sonda é ANCORADA na chave da trava, não na palavra `Cache`.
        $this->assertStringContainsString("'aprovacao:aquecer:'", $job, 'a chave da trava do job mudou de forma');
        $this->assertStringNotContainsString('/sites/MLB/', $job, 'o job conhece a URL do Mercado Livre — ele deveria só chamar o serviço');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // TRANSVERSAIS — território por ESCOPO DO ASSUNTO e a bijeção ids ↔ métodos
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * ⚠️⚠️ **O GATE MEDE OS COMMITS DO PRÓPRIO ESCOPO, NÃO A ÁRVORE.** Nesta árvore há **três**
     * sessões (este plano, o **187-09 em paralelo** e o outro dev), e `git log --format=%an`
     * devolve **um** valor para todas: o autor **não discrimina NADA**. O discriminador é o
     * **ESCOPO DO ASSUNTO**, com isca nas duas direções.
     *
     * ⚠️ A contagem deste caso **NÃO depende de quantos commits existem no escopo**: nenhuma
     * asserção mora dentro de condicional, o `test -e` dos caminhos proibidos é **UMA** asserção
     * coletando fantasmas, e a prova de que o range não está vazio é asserção **COLETADA**, nunca
     * `return` antecipado.
     *
     * @group phase187
     */
    public function test_territorio_e_ausencias_do_plano_187_10(): void
    {
        // ── As ausências, lidas do código SEM COMENTÁRIO ──
        $fonte = '';
        foreach (self::MEUS as $rel) {
            $fonte .= $this->codigoSemComentario($rel);
        }

        // ⚠️⚠️ ZERO DEGRADAÇÃO (a decisão que o usuário tomou em 15/10/2026, recusando "limitar N
        // consultas por abertura"): nada aqui limita consultas por request nem inventa
        // "incompleto". ⚠️ As sondas são ANCORADAS na FORMA do defeito, não na palavra solta —
        // `limite`/`max` são palavras comuns e um gate sobre elas reprovaria trabalho correto.
        foreach (['Incompleto', 'array_slice(', 'take(', 'limit('] as $proibido) {
            $this->assertStringNotContainsString($proibido, $fonte, "`{$proibido}`: este plano NÃO limita consultas por request nem degrada a tela");
        }

        // ⚠️ ISCA DO LEITOR SEM COMENTÁRIO: a prosa dos três arquivos CITA "incompleto" e "cap por
        // request" de propósito, para explicar o que foi recusado. Se o leitor parasse de tirar
        // comentário, o gate reprovaria pela própria explicação — e o conserto barato seria apagá-la.
        $cru = (string) file_get_contents($this->raiz().'/'.self::MEUS[0]);
        $this->assertStringContainsString('incompleto', mb_strtolower($cru), 'a explicação do que foi RECUSADO saiu do arquivo');
        $this->assertStringNotContainsString('incompleto', mb_strtolower($this->codigoSemComentario(self::MEUS[0])), 'o leitor sem comentário parou de tirar comentário');

        // ⚠️ E o que o código REALMENTE usa está lá: o serviço da 186 é CHAMADO.
        $this->assertStringContainsString('ComposicaoFinanceiraService', $fonte, 'o aquecimento tem de CHAMAR o serviço da Fase 186');
        $this->assertStringContainsString('->para(', $fonte, 'o aquecimento tem de chamar `para()` — é ele que monta e usa a chave');

        // ⚠️ Nenhum HEX e nenhum `localStorage`: este plano não desenha tela nenhuma.
        $this->assertDoesNotMatchRegularExpression('/#[0-9A-Fa-f]{6}\b/', $fonte, 'hex no PHP de um plano que não desenha tela');
        $this->assertStringNotContainsString('localStorage', $fonte);
        $this->assertMatchesRegularExpression('/#[0-9A-Fa-f]{6}\b/', 'color: #FACC15;', 'a sonda de hex está cega');

        // ─── O ESCOPO DO ASSUNTO, com isca nas duas direções ───
        $this->assertSame(1, preg_match(self::ESCOPO, 'feat(187-10): x'), 'o escopo não casa o próprio assunto');
        $this->assertSame(0, preg_match(self::ESCOPO, 'feat(187-09): x'), 'o escopo casa a wave 9, que roda EM PARALELO — ele está largo');
        $this->assertSame(0, preg_match(self::ESCOPO, 'feat(187-08): x'), 'o escopo casa a wave 8 — ele está largo');
        $this->assertSame(0, preg_match(self::ESCOPO, 'fix(261016-mwe): x'), 'o escopo casa a outra máquina — ele está largo');

        $log = $this->git('git log --format=%h:%s '.self::PARTIDA.'..HEAD');
        $hashes = [];
        foreach (array_filter(explode("\n", $log)) as $linha) {
            $corte = strpos($linha, ':');
            if ($corte !== false && preg_match(self::ESCOPO, substr($linha, $corte + 1)) === 1) {
                $hashes[] = substr($linha, 0, $corte);
            }
        }

        // ⚠️ SEM `return` antecipado: a contagem NÃO pode depender de quantos commits existem.
        $arquivos = $hashes === []
            ? []
            : array_values(array_filter(array_map(
                'trim',
                explode("\n", $this->git('git show --name-only --format= '.implode(' ', $hashes))),
            )));

        $proibidos = [
            // Os 10 arquivos da Fase 186 em `Aprovacao/` (fora de `Fila/`) — SOMENTE LEITURA.
            'app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/VocabularioDoPortao.php',
            'app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php',
            'app/Services/Publicador/Aprovacao/ComposicaoDaOferta.php',
            'app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/RevisaoDeImagemService.php',
            'app/Services/Publicador/Aprovacao/AjustesDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/HistoricoDaOfertaService.php',
            // Os de `Fila/` das waves 2/5/6/7/8 — este plano só ACRESCENTA um arquivo novo lá.
            'app/Services/Publicador/Aprovacao/Fila/FilaDeAprovacaoService.php',
            'app/Services/Publicador/Aprovacao/Fila/ProximoARevisarService.php',
            'app/Services/Publicador/Aprovacao/Fila/FiltrosDaFilaService.php',
            'app/Services/Publicador/Aprovacao/Fila/GrupoDaFilaService.php',
            'app/Services/Publicador/Aprovacao/Fila/GaleriaDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/Fila/LinhaDaOfertaService.php',
            // ⚠️⚠️ `app/Services/Publicador/Aprovacao/Fila/ResumoRapidoService.php` **NÃO entra**:
            // o briefing o nomeia entre os serviços de `Fila/`, mas `test -e` nesta máquina em
            // 17/10/2026 diz que **ele não existe** — é o OITAVO caminho fantasma desta milestone
            // (a v26.0 teve seis, a Fase 186 achou o sétimo). Caminho fantasma na lista faz a
            // varredura dizer "território intocado" sobre um arquivo que nunca existiu, e é por
            // isso que a lista é conferida por `test -e` numa asserção única.
            // ⚠️⚠️ `resources/` INTEIRO: este plano NÃO desenha tela.
            'resources',
            // ⚠️ O território do 187-09, que roda EM PARALELO nesta mesma árvore.
            // ⚠️ `tests/gates/contagens-187-09.txt` **não entra** pelo mesmo motivo: ele ainda não
            // existe no disco (aquela wave não o commitou até aqui). Proibir caminho que não
            // existe é gate sobre zero casos.
            'app/Services/Publicador/Aprovacao/Fila/LoteDaFilaService.php',
            'routes/mlb_anuncios.php',
            // Creative Engine, acervo, MCP, Support, Portal e os de outras fases.
            'app/Services/Creative',
            'app/Services/Publicador/Criativos',
            'app/Services/Publicador/ImagemAssetService.php',
            'app/Support/Publicador',
            'app/Mcp',
            'app/Services/Portal',
            'app/Services/Bling',
            'app/Services/Mlb/Publicacao/MlPublicacaoService.php',
            'app/Http/Controllers/MlbPublicadorCriativoController.php',
            // Os gates INTOCÁVEIS e os manifestos de outras waves.
            'tests/js/estrutura-meus-anuncios.test.js',
            'tests/js/publicador-header-zindex-ordem.test.js',
            'tests/Unit/Publicador/Aprovacao/LinhaDaOfertaServiceTest.php',
            'tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php',
            'tests/gates/contagens-187-08.txt',
            'tests/gates/contagens-187-07.txt',
            // Dependências (T-187-SC), migrations e o planejamento travado.
            'package.json', 'package-lock.json', 'composer.json', 'composer.lock',
            'database/migrations', 'tailwind.config.js',
            '.planning/ROADMAP.md', '.planning/STATE.md',
        ];

        // ⚠️ UMA asserção coletando fantasmas (nunca uma por caminho — isso faria a contagem
        // crescer junto com a lista).
        $fantasmas = array_values(array_filter(
            $proibidos,
            fn (string $caminho): bool => ! file_exists($this->raiz().'/'.$caminho),
        ));
        $this->assertSame([], $fantasmas, 'caminho inexistente na lista de proibidos — o gate mediria NADA');
        $this->assertFalse(file_exists($this->raiz().'/app/Services/NaoExiste9z7'), 'a isca do fantasma virou caminho de verdade');

        $tocados = array_values(array_filter($arquivos, static function (string $a) use ($proibidos): bool {
            foreach ($proibidos as $p) {
                if ($a === $p || str_starts_with($a, $p.'/')) {
                    return true;
                }
            }

            return false;
        }));
        $this->assertSame([], $tocados, 'este plano saiu do território declarado');

        // ⚠️ ISCA DO PREFIXO: casa o segmento, não o irmão.
        $this->assertTrue(str_starts_with('app/Mcp/X.php', 'app/Mcp/'));
        $this->assertFalse(str_starts_with('app/McpOutraCoisa.php', 'app/Mcp/'));

        // ⚠️ A prova de que o range não está vazio por construção, COLETADA.
        $doPlano = ['AquecimentoDeTarifasService', 'AquecerTarifasDaFilaJob', 'WarmTarifasDaFila',
            'AquecimentoDeTarifasServiceTest', 'AquecerTarifasDaFilaTest', 'contagens-187-10',
            '187-10-SUMMARY'];
        $reconhece = static fn (string $a): bool => (bool) array_filter($doPlano, static fn (string $p): bool => str_contains($a, $p));

        $semProva = $hashes !== [] && ! array_filter($arquivos, $reconhece)
            ? ['os commits do escopo não tocaram arquivo deste plano: '.json_encode(array_slice($arquivos, 0, 8))]
            : [];
        $this->assertSame([], $semProva, 'o range do escopo está vazio por construção');

        $this->assertTrue($reconhece(self::MEUS[0]), 'a prova não reconhece o serviço deste plano');
        $this->assertFalse($reconhece('app/Services/Creative/CreativeJuiz.php'), 'a prova reconhece arquivo de OUTRA fase');
    }

    /**
     * ⚠️ A BIJEÇÃO ids ↔ métodos, nos DOIS sentidos: método sem id **NUNCA RODA** e id órfão é
     * gate sobre ZERO casos.
     *
     * ⚠️⚠️ Ela varre os **DOIS** arquivos de teste deste plano, lendo os nomes do **FONTE**:
     * `get_class_methods($this)` só veria ESTA classe e deixaria o `AquecimentoDeTarifasServiceTest`
     * sem bijeção **em silêncio** — justamente o furo que a bijeção existe para fechar.
     *
     * ⚠️ A contagem deste caso é de forma FIXA (limiares + dois `assertSame([])` coletando
     * infratores + a isca do nome que ninguém escreveu), então acrescentar id ao manifesto **NÃO**
     * mexe neste número.
     *
     * @group phase187
     */
    public function test_bijecao_do_manifesto_do_aquecimento_187_10(): void
    {
        $manifesto = $this->raiz().'/tests/gates/contagens-187-10.txt';
        $this->assertFileExists($manifesto, 'manifesto ausente — os gates deste plano rodariam sem régua');

        $linhas = array_values(array_filter(
            array_map('trim', explode("\n", (string) file_get_contents($manifesto))),
            static fn (string $l): bool => $l !== '' && ! str_starts_with($l, '#'),
        ));
        $this->assertGreaterThanOrEqual(6, count($linhas), 'o manifesto encolheu — algum id desapareceu');

        $filtrosDosIds = [];
        foreach ($linhas as $linha) {
            $partes = preg_split('/\s+/', $linha);
            if (($partes[1] ?? '') === 'php') {
                $filtrosDosIds[$partes[0]] = $partes[2];
            }
        }
        $this->assertNotSame([], $filtrosDosIds, 'nenhum id `php` no manifesto — a bijeção mediria NADA');

        // ⚠️ Os nomes vêm do FONTE dos DOIS arquivos, não de `get_class_methods($this)`.
        $arquivosDeTeste = [
            'tests/Unit/Publicador/Aprovacao/AquecimentoDeTarifasServiceTest.php',
            'tests/Feature/Publicador/Aprovacao/AquecerTarifasDaFilaTest.php',
        ];
        $metodos = [];
        foreach ($arquivosDeTeste as $rel) {
            $limpo = $this->codigoSemComentario($rel);
            preg_match_all('/public function (test_[A-Za-z0-9_]+)\s*\(/', $limpo, $m);
            foreach ($m[1] as $nome) {
                if (str_ends_with($nome, '_187_10')) {
                    $metodos[] = $nome;
                }
            }
        }
        $metodos = array_values(array_unique($metodos));
        $this->assertGreaterThanOrEqual(20, count($metodos), 'os métodos deste plano encolheram');

        $semId = [];
        foreach ($metodos as $metodo) {
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

        $orfaos = [];
        foreach ($filtrosDosIds as $id => $filtro) {
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

        // ⚠️ ISCA: o filtro do primeiro id NÃO casa um nome que ninguém escreveu.
        $this->assertSame(0, preg_match('/^'.reset($filtrosDosIds).'$/', 'test_metodo_que_ninguem_escreveu_187_10'));
    }
}
