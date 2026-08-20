<?php

namespace Tests\Unit\Phase134;

use App\Jobs\SyncMlAcervoCompanyJob;
use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlToken;
use App\Services\Mlb\Acervo\AcervoEscritaLock;
use App\Services\Mlb\Acervo\MlAcervoDetalheService;
use App\Services\Mlb\Acervo\MlAcervoService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Correção dos deadlocks do acervo — `.planning/debug/acervo-deadlock-upsert.md`.
 *
 * ⚠️ LIMITE DESTA SUÍTE, declarado de propósito:
 * **nenhum teste aqui prova que o deadlock acabou, e nenhum poderia.** A suíte
 * roda em SQLite in-memory, que não tem o motor de locks do InnoDB nem
 * detecção de deadlock entre transações concorrentes. Um teste que alegasse
 * "prova que o deadlock foi corrigido" seria falso, e falso é pior que
 * ausente: daria confiança onde não há evidência.
 *
 * O que estes testes travam é o COMPORTAMENTO da correção — as propriedades
 * que, se regredirem, reabrem o deadlock em produção sem ninguém perceber:
 *
 *   1. a escrita da camada BARATA acontece sob o lock por empresa
 *   2. a escrita da camada CARA acontece sob o lock por empresa
 *   3. as duas usam a MESMA chave de lock (é isso, e só isso, que serializa)
 *   4. o lote chega ao upsert ordenado por (company_id, ml_item_id)
 *   5. deadlock NÃO carimba `coleta_erro` em massa (fim da realimentação)
 *   6. erro comum CONTINUA carimbando (a correção não pode cegar o D-08)
 *
 * A validação de que o deadlock de fato cessou é EMPÍRICA e só existe em
 * produção: taxa de deadlock por dia, antes (~20/dia) vs. depois.
 *
 * @group phase134
 */
class SerializacaoEscritaAcervoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // App token pré-semeado — mesmo motivo de ColetaAcervoTest::setUp().
        Cache::put('ml_app_token_coleta', 'fake-app-token', now()->addHour());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─── 1) Camada barata escreve sob o lock da empresa ────────────────────

    /** @test */
    public function escrita_da_camada_barata_acontece_sob_o_lock_da_empresa(): void
    {
        [$company] = $this->criarFixture();
        $this->fakeColetaBarata();

        $lockTomadoDurante = $this->observarLockDurante(
            $company,
            'insert',
            fn () => app(MlAcervoService::class)->coletarCamadaBarata($company)
        );

        $this->assertTrue(
            $lockTomadoDurante,
            'o upsert da camada barata precisa rodar dentro do lock por empresa — sem isso ele volta a '
            . 'competir com o update da camada cara, que é a causa do deadlock'
        );
    }

    // ─── 2) Camada cara escreve sob o lock da empresa ──────────────────────

    /** @test */
    public function escrita_da_camada_cara_acontece_sob_o_lock_da_empresa(): void
    {
        [$company] = $this->criarFixture();
        $item = $this->criarItem($company, 'MLB0000000001');
        $this->fakeDetalhePadrao();

        $lockTomadoDurante = $this->observarLockDurante(
            $company,
            'update',
            fn () => app(MlAcervoDetalheService::class)->coletarDetalhe($company, [$item->ml_item_id])
        );

        $this->assertTrue(
            $lockTomadoDurante,
            'o update da camada cara precisa rodar dentro do lock por empresa — é a outra ponta do par que colide'
        );
    }

    // ─── 3) A MESMA chave nas duas camadas ─────────────────────────────────

    /** @test */
    public function as_duas_camadas_disputam_a_mesma_chave_de_lock(): void
    {
        [$company] = $this->criarFixture();

        // Os testes 1 e 2 já provaram, cada um, que a escrita da sua camada
        // roda com ESTA chave tomada. O que este teste trava é o contrato: a
        // chave depende só da empresa, nunca da classe do job. Se alguém
        // "melhorar" a chave incluindo a camada (ou o id do lote), as duas
        // deixam de se excluir e o deadlock volta em silêncio — foi
        // exatamente esse o modo de falha do ShouldBeUnique, cuja chave
        // inclui get_class($job).
        $this->assertSame(
            AcervoEscritaLock::chave($company->id),
            AcervoEscritaLock::chave($company->id),
            'a chave precisa ser função APENAS do company_id'
        );

        $this->assertStringContainsString((string) $company->id, AcervoEscritaLock::chave($company->id));
        $this->assertNotSame(
            AcervoEscritaLock::chave($company->id),
            AcervoEscritaLock::chave($company->id + 1),
            'empresas diferentes não podem compartilhar lock — isso serializaria a base inteira'
        );
    }

    /** @test */
    public function escrita_serializada_libera_o_lock_ao_terminar(): void
    {
        [$company] = $this->criarFixture();

        AcervoEscritaLock::naEmpresa($company->id, fn () => true);

        $this->assertTrue(
            Cache::lock(AcervoEscritaLock::chave($company->id), 5)->get(),
            'lock vazado deixaria toda escrita seguinte da empresa esperando 10s e caindo na degradação'
        );
    }

    // ─── 4) Ordenação determinística do lote (higiene, não a correção) ─────

    /** @test */
    public function lote_chega_ao_upsert_ordenado_por_chave(): void
    {
        [$company] = $this->criarFixture();
        $this->fakeColetaBarata();

        $idsNoUpsert = [];

        DB::listen(function ($query) use (&$idsNoUpsert) {
            if (! str_contains($query->sql, 'ml_acervo_itens') || ! str_starts_with(strtolower(trim($query->sql)), 'insert')) {
                return;
            }

            // 10 dígitos = ml_item_id. `category_id` também começa com MLB
            // (MLB1234 no fake), por isso o padrão é ancorado no comprimento.
            foreach ($query->bindings as $binding) {
                if (is_string($binding) && preg_match('/^MLB\d{10}$/', $binding)) {
                    $idsNoUpsert[] = $binding;
                }
            }
        });

        app(MlAcervoService::class)->coletarCamadaBarata($company);

        $this->assertNotEmpty($idsNoUpsert, 'sanity check — o teste precisa ter capturado o upsert');

        $ordenados = $idsNoUpsert;
        sort($ordenados);

        $this->assertSame(
            $ordenados,
            $idsNoUpsert,
            'o lote precisa chegar ao upsert ordenado por (company_id, ml_item_id): a ordem de retorno do '
            . 'multiget do ML varia entre execuções'
        );
    }

    // ─── 5) Deadlock não carimba coleta_erro em massa ──────────────────────

    /** @test */
    public function deadlock_nao_carimba_coleta_erro_em_massa(): void
    {
        [$company] = $this->criarFixture();
        $this->criarItem($company, 'MLB0000000001');
        $this->criarItem($company, 'MLB0000000002');

        (new SyncMlAcervoCompanyJob($company))->failed($this->deadlock());

        $this->assertSame(
            0,
            MlAcervoItem::where('company_id', $company->id)->whereNotNull('coleta_erro')->count(),
            'deadlock é transitório: carimbar o acervo INTEIRO da empresa mente na tela (foi o que produziu os '
            . '136.432 itens "com erro") e ainda realimenta o próprio deadlock ao travar a faixa toda do índice'
        );
    }

    // ─── 6) Erro comum continua carimbando ─────────────────────────────────

    /** @test */
    public function erro_comum_continua_carimbando_coleta_erro(): void
    {
        [$company] = $this->criarFixture();
        $this->criarItem($company, 'MLB0000000001');
        $this->criarItem($company, 'MLB0000000002');

        (new SyncMlAcervoCompanyJob($company))->failed(new \RuntimeException('[MercadoLivre] token inválido'));

        $this->assertSame(
            2,
            MlAcervoItem::where('company_id', $company->id)->whereNotNull('coleta_erro')->count(),
            'a correção do deadlock não pode cegar o banner de defasagem do D-08 para falha REAL de coleta'
        );
    }

    /** @test */
    public function detector_de_concorrencia_separa_deadlock_de_erro_comum(): void
    {
        $this->assertTrue(AcervoEscritaLock::ehErroDeConcorrencia($this->deadlock()));
        $this->assertFalse(AcervoEscritaLock::ehErroDeConcorrencia(new \RuntimeException('[MercadoLivre] Erro 503 em /items')));
    }

    // ═══ Helpers ═══════════════════════════════════════════════════════════

    /**
     * Roda `$acao` observando, no instante EXATO em que o statement de escrita
     * do tipo `$tipo` roda contra `ml_acervo_itens`, se o lock da empresa já
     * está tomado.
     *
     * `DB::listen` dispara ainda dentro do callback do lock (a liberação só
     * acontece depois que o callback retorna), então é o ponto de observação
     * correto para isto.
     */
    private function observarLockDurante(Company $company, string $tipo, \Closure $acao): bool
    {
        $lockTomado = false;

        DB::listen(function ($query) use ($company, $tipo, &$lockTomado) {
            if (! str_contains($query->sql, 'ml_acervo_itens') || ! str_starts_with(strtolower(trim($query->sql)), $tipo)) {
                return;
            }

            // get() devolve false quando o lock já pertence a outro dono —
            // que é o próprio AcervoEscritaLock, um quadro de pilha acima.
            if (! Cache::lock(AcervoEscritaLock::chave($company->id), 5)->get()) {
                $lockTomado = true;
            }
        });

        $acao();

        return $lockTomado;
    }

    /** QueryException fiel ao erro 1213 visto em produção. */
    private function deadlock(): QueryException
    {
        return new QueryException(
            'mysql',
            'insert into ml_acervo_itens (...) values (...)',
            [],
            new \PDOException(
                'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; '
                . 'try restarting transaction'
            )
        );
    }

    /** Cria Company + MlToken ativo (mesmo padrão de ColetaAcervoTest::criarFixture()). */
    private function criarFixture(): array
    {
        $company = Company::factory()->create();

        MlToken::create([
            'company_id' => $company->id,
            'ml_user_id' => '436501796',
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer',
            'scope' => 'read write offline_access',
            'expires_at' => now()->addYear(),
            'last_refreshed_at' => now(),
            'status' => 'active',
            'connected_at' => now(),
        ]);

        return [$company];
    }

    /** Semeia uma linha de ml_acervo_itens direto — sem passar pela camada barata. */
    private function criarItem(Company $company, string $mlId, array $attrs = []): MlAcervoItem
    {
        return MlAcervoItem::create(array_merge([
            'company_id' => $company->id,
            'ml_item_id' => $mlId,
            'status' => 'active',
            'available_quantity' => 5,
            'catalog_listing' => false,
            'origem' => MlAcervoItem::ORIGEM_LEGADO,
        ], $attrs));
    }

    /**
     * Http::fake da camada barata com ids DESORDENADOS de propósito: é assim
     * que o multiget do ML devolve, e é o que o teste de ordenação precisa ver
     * para não passar por acidente.
     */
    private function fakeColetaBarata(): void
    {
        $ids = ['MLB0000000009', 'MLB0000000002', 'MLB0000000007', 'MLB0000000001', 'MLB0000000005'];

        $multiget = array_map(static fn ($id) => [
            'code' => 200,
            'body' => [
                'id' => $id,
                'title' => "Anúncio {$id}",
                'status' => 'active',
                'category_id' => 'MLB1234',
                'price' => 99.9,
                'available_quantity' => 3,
                'sold_quantity' => 1,
                'permalink' => "https://produto.mercadolivre.com.br/{$id}",
                'thumbnail' => 'https://http2.mlstatic.com/fake.jpg',
                'pictures' => [['id' => '1'], ['id' => '2']],
                'variations' => [],
                'catalog_listing' => false,
                'tags' => [],
                'sub_status' => [],
                'shipping' => [],
                'listing_type_id' => 'gold_special',
                'health' => 0.8,
            ],
        ], $ids);

        Http::fake([
            '*/items/search*' => function ($request) use ($ids) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                if (array_key_exists('scroll_id', $query)) {
                    return Http::response(['results' => [], 'scroll_id' => null, 'paging' => ['limit' => 50, 'total' => count($ids)]], 200);
                }

                return Http::response(['results' => $ids, 'scroll_id' => 'scroll-fake-1', 'paging' => ['limit' => 50, 'total' => count($ids)]], 200);
            },
            '*/items?ids=*' => Http::response($multiget, 200),
            'api.mercadolibre.com/categories/*/attributes' => Http::response([], 200),
        ]);
    }

    /** Http::fake padrão da camada cara (mesmo de RotacaoDetalheTest). */
    private function fakeDetalhePadrao(): void
    {
        Http::fake([
            'api.mercadolibre.com/items/*/visits*' => Http::response($this->fixture('visits.json'), 200),
            'api.mercadolibre.com/items/*/price_to_win*' => Http::response($this->fixture('price-to-win.json'), 200),
            'api.mercadolibre.com/item/*/performance*' => Http::response($this->fixture('performance-sondagem.json'), 200),
        ]);
    }

    /** Lê uma fixture real de tests/fixtures/phase134/. */
    private function fixture(string $nome): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2) . "/fixtures/phase134/{$nome}"), true);
    }
}
