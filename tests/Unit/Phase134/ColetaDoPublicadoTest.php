<?php

namespace Tests\Unit\Phase134;

use App\Jobs\Publicador\SincronizarAcervoDoPublicadoJob;
use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlToken;
use App\Services\Mlb\Acervo\MlAcervoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Quick 261010-nke — caminho ESTREITO de coleta do acervo
 * (`MlAcervoService::coletarItens()`) e o job que o consome
 * (`SincronizarAcervoDoPublicadoJob`).
 *
 * Por que este caminho existe: a publicação do Publicador grava em
 * `pub_publicacao_itens`, e a aba Publicações lê EXCLUSIVAMENTE
 * `ml_acervo_itens` (D-05). Nada ligava as duas coisas — medido em produção
 * em 10/10/2026 com `MLB5366398961` e `MLB5366495199` (Poltrona Beny), que
 * existem no Mercado Livre e NÃO existiam no acervo. Até a varredura do dia
 * seguinte o anúncio recém-publicado era invisível na tela.
 *
 * O que estes testes travam:
 *   (1) `coletarItens()` CRIA a linha dos ids pedidos (a camada cara só faz
 *       `update()` e nunca cria — por isso `SyncMlAcervoDetalheJob` não servia)
 *   (2) o caminho é ESTREITO: nenhuma varredura por `scroll_id` da conta
 *       inteira (seriam ~3.340 chamadas para mostrar um anúncio só)
 *   (3) `under_review` é gravado cru, como o ML devolve — é o status em que o
 *       anúncio novo nasce nessa conta (§21 dos learnings)
 *   (4) falha no multiget NÃO carimba `coleta_erro` em faixa: o carimbo de
 *       faixa inteira mentia na tela e realimentava o deadlock
 *       (`.planning/debug/resolved/acervo-deadlock-upsert.md`, E6)
 *   (5) lista vazia não toca em HTTP nem em banco
 *   (6) o job é interativo e vai para a fila `high` (§22 dos learnings)
 *   (7) empresa sem token ativo é PULADA com log, nunca lançada — a conta que
 *       publica pode ser `MlbEmpresa` sem Company
 *
 * Estratégia: RefreshDatabase (SQLite in-memory) + `Http::fake` só dos
 * endpoints usados — nunca ML real, nunca `Http::fake()` vazio (ver o aviso
 * do setUp de `ColetaAcervoTest`).
 *
 * @group phase134
 */
class ColetaDoPublicadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // App token pré-semeado → MlCatalogoMetaService::atributos() serve do
        // cache e não dispara o POST de client_credentials. Mesmo padrão de
        // ColetaAcervoTest.
        Cache::put('ml_app_token_coleta', 'fake-app-token', now()->addHour());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─── Teste 1 — a linha nasce: é o bug todo ──────────────────────────────

    /** @test */
    public function coleta_estreita_cria_a_linha_dos_ids_pedidos(): void
    {
        [$company] = $this->criarFixture();
        $this->fakeMultiget([
            $this->itemDoMl('MLB1', ['title' => 'Poltrona Beny Verde', 'status' => 'active', 'price' => 1299.9]),
            $this->itemDoMl('MLB2', ['title' => 'Poltrona Beny Azul', 'status' => 'active', 'price' => 1499.0]),
        ]);

        $resumo = app(MlAcervoService::class)->coletarItens($company, ['MLB1', 'MLB2']);

        $this->assertSame(2, $resumo['itens']);
        $this->assertSame(1, $resumo['lotes'], 'dois ids cabem num multiget só');
        $this->assertSame(0, $resumo['falhas']);

        $linhas = MlAcervoItem::where('company_id', $company->id)->get()->keyBy('ml_item_id');

        $this->assertCount(2, $linhas, 'as DUAS linhas têm que existir — hoje a publicação não cria nenhuma');
        $this->assertSame('Poltrona Beny Verde', $linhas['MLB1']->title);
        $this->assertSame('active', $linhas['MLB1']->status);
        $this->assertEquals(1299.9, (float) $linhas['MLB1']->price);
        $this->assertSame('Poltrona Beny Azul', $linhas['MLB2']->title);
        $this->assertEquals(1499.0, (float) $linhas['MLB2']->price);
        $this->assertNotNull($linhas['MLB1']->coletado_em);
    }

    // ─── Teste 2 — estreito de verdade: nada de varrer a conta inteira ──────

    /** @test */
    public function coleta_estreita_nao_varre_a_conta_inteira(): void
    {
        [$company] = $this->criarFixture();
        $this->fakeMultiget([$this->itemDoMl('MLB1')]);

        app(MlAcervoService::class)->coletarItens($company, ['MLB1']);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/items/search'));

        $scrolls = Http::recorded(fn ($request) => str_contains($request->url(), 'scroll_id'));
        $this->assertCount(0, $scrolls, 'o caminho estreito nunca pagina por scroll_id (seriam ~3.340 chamadas)');
    }

    // ─── Teste 3 — §21: o anúncio novo nasce `under_review` ────────────────

    /** @test */
    public function item_under_review_e_gravado_com_esse_status(): void
    {
        [$company] = $this->criarFixture();
        $this->fakeMultiget([
            $this->itemDoMl('MLB1', [
                'status'     => 'under_review',
                'sub_status' => ['waiting_for_patch'],
            ]),
        ]);

        app(MlAcervoService::class)->coletarItens($company, ['MLB1']);

        $linha = MlAcervoItem::where('company_id', $company->id)->where('ml_item_id', 'MLB1')->first();

        $this->assertNotNull($linha);
        $this->assertSame('under_review', $linha->status, 'o status cru do ML, nunca normalizado nem descartado');
        $this->assertContains('waiting_for_patch', (array) $linha->sub_status);
    }

    // ─── Teste 4 — E6: nenhum carimbo de `coleta_erro` em faixa ────────────

    /** @test */
    public function falha_no_multiget_nao_carimba_coleta_erro_em_linha_de_fora(): void
    {
        [$company] = $this->criarFixture();

        // Linha pré-existente da MESMA empresa, FORA da lista pedida.
        MlAcervoItem::create([
            'company_id'  => $company->id,
            'ml_item_id'  => 'MLB9999999999',
            'title'       => 'Anúncio antigo e saudável',
            'status'      => 'active',
            'coleta_erro' => null,
            'origem'      => MlAcervoItem::ORIGEM_LEGADO,
            'coletado_em' => now(),
        ]);

        Http::fake(['*/items?ids=*' => Http::response(['message' => 'internal error'], 500)]);

        try {
            app(MlAcervoService::class)->coletarItens($company, ['MLB1']);
            $this->fail('a falha de nível de empresa tem que propagar para o job retentar');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }

        $deFora = MlAcervoItem::where('company_id', $company->id)->where('ml_item_id', 'MLB9999999999')->first();

        $this->assertNull(
            $deFora->coleta_erro,
            'E6: carimbar coleta_erro em faixa inteira mentia na tela e realimentava o deadlock'
        );
    }

    // ─── Teste 5 — lista vazia é no-op total ───────────────────────────────

    /** @test */
    public function lista_vazia_nao_faz_chamada_nem_escreve(): void
    {
        [$company] = $this->criarFixture();
        $this->fakeMultiget([$this->itemDoMl('MLB1')]);

        $resumo = app(MlAcervoService::class)->coletarItens($company, []);

        $this->assertSame(['itens' => 0, 'lotes' => 0, 'falhas' => 0], $resumo);
        $this->assertSame(0, MlAcervoItem::count());
        Http::assertNothingSent();
    }

    // ─── Teste 6 — D-NKE-05: job interativo vai para a `high` ──────────────

    /** @test */
    public function job_declara_a_fila_high(): void
    {
        $job = new SincronizarAcervoDoPublicadoJob(1, ['MLB1']);

        $this->assertSame('high', $job->queue, 'a default fica atrás do Adman e do Acervo (§16/§22 dos learnings)');
    }

    // ─── Teste 7 — conta sem Company/token ativo é pulada, nunca lançada ───

    /** @test */
    public function handle_sem_token_ativo_nao_lanca_e_nao_escreve(): void
    {
        $company = Company::factory()->create(); // sem MlToken nenhum
        $this->fakeMultiget([$this->itemDoMl('MLB1')]);

        $job = new SincronizarAcervoDoPublicadoJob($company->id, ['MLB1']);
        $job->handle(app(MlAcervoService::class));

        $this->assertSame(0, MlAcervoItem::count(), 'sem token não há acervo para escrever');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'mercadolibre.com'));
    }

    /** @test */
    public function handle_de_empresa_inexistente_nao_lanca(): void
    {
        $this->fakeMultiget([$this->itemDoMl('MLB1')]);

        $job = new SincronizarAcervoDoPublicadoJob(987654, ['MLB1']);
        $job->handle(app(MlAcervoService::class));

        $this->assertSame(0, MlAcervoItem::count());
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /** Cria Company + MlToken ativo (mesmo padrão de ColetaAcervoTest). */
    private function criarFixture(): array
    {
        $company = Company::factory()->create();

        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => '436501796',
            'access_token'      => 'fake-access-token',
            'refresh_token'     => 'fake-refresh-token',
            'token_type'        => 'bearer',
            'scope'             => 'read write offline_access',
            'expires_at'        => now()->addDays(6),
            'last_refreshed_at' => now(),
            'status'            => 'active',
            'connected_at'      => now(),
        ]);

        return [$company];
    }

    /** Um `body` de item do multiget, só com o que a camada barata lê. */
    private function itemDoMl(string $mlItemId, array $overrides = []): array
    {
        return array_merge([
            'id'                 => $mlItemId,
            'title'              => 'Poltrona Beny',
            'status'             => 'active',
            'sub_status'         => [],
            'category_id'        => 'MLB193945',
            'listing_type_id'    => 'gold_special',
            'price'              => 1299.9,
            'available_quantity' => 3,
            'sold_quantity'      => 0,
            'permalink'          => "https://produto.mercadolivre.com.br/{$mlItemId}",
            'thumbnail'          => 'https://http2.mlstatic.com/x.jpg',
            'pictures'           => [['id' => 'PIC-1']],
            'variations'         => [],
            'catalog_listing'    => false,
            'shipping'           => ['mode' => 'me2'],
            'tags'               => [],
            'health'             => 0.9,
            'attributes'         => [],
        ], $overrides);
    }

    /**
     * `Http::fake` só dos endpoints que o caminho estreito usa: o multiget e
     * os atributos de categoria. O `/items/search` fica DE FORA de propósito —
     * se o caminho tentar varrer a conta, o teste 2 pega.
     *
     * @param  list<array> $bodies
     */
    private function fakeMultiget(array $bodies): void
    {
        Http::fake([
            '*/items?ids=*' => Http::response(
                array_map(fn (array $body) => ['code' => 200, 'body' => $body], $bodies),
                200
            ),
            'api.mercadolibre.com/categories/*/attributes' => Http::response([], 200),
        ]);
    }
}
