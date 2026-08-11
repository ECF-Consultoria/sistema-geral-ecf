<?php

namespace Tests\Unit\Phase134;

use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlAcervoMetricaDiaria;
use App\Models\MlToken;
use App\Services\Mlb\Acervo\AnuncioSaudeService;
use App\Services\Mlb\Acervo\MlAcervoDetalheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 134 (Plano 05) — camada CARA da coleta do acervo Mercado Livre.
 *
 * Trava os comportamentos que mais podem quebrar em silêncio:
 *   (1) a rotação cobre 100% do acervo ativo em N execuções, sem cauda
 *       longa permanentemente cega (D-23)
 *   (2) a fatia prioriza nunca-coletados e os mais antigos, sem cursor
 *       persistido — o próprio detalhe_coletado_em é o cursor
 *   (3) buy box não avaliado nunca vira motivo — nem "sem problema" nem
 *       "com problema" (D-18)
 *   (4) falha de coleta não avança o frescor nem inventa status — o item
 *       volta ao topo da fila (D-08)
 *   (5) zero write na API do ML (D-11)
 *   (6) a camada cara NUNCA apaga o que a camada barata gravou, e a
 *       triagem recomputada usa o pseudo-payload persistido, nunca vazio
 *       (T-134-26, espelho do plano 134-04)
 *   (7) performance do ML (D-21) não é chamado onde não se aplica
 *       (catálogo / encerrado)
 *   (8) performance_acoes guarda só variables PENDING, com title
 *       preservado exatamente como veio do ML
 *
 * Estratégia: RefreshDatabase (SQLite in-memory) + Http::fake — nunca ML
 * real, sempre fixtures reais de tests/fixtures/phase134/.
 *
 * @group phase134
 */
class RotacaoDetalheTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─── Teste 1 — D-23: cobertura completa em N execuções ─────────────────

    /** @test */
    public function rotacao_cobre_todo_o_acervo_em_n_execucoes(): void
    {
        [$company] = $this->criarFixture();

        $n = 7;
        $total = 70; // múltiplo exato de 7 — cada execução cobre exatamente 10, sem sobra

        for ($i = 1; $i <= $total; $i++) {
            $this->criarItem($company, $this->mlId($i), ['catalog_listing' => true]);
        }

        $this->fakeDetalhePadrao();

        $coletadosPorExecucao = [];

        for ($execucao = 1; $execucao <= $n; $execucao++) {
            $fatia = $this->service()->selecionarFatia($company, $n);
            $this->assertNotEmpty($fatia, "execução {$execucao} não pode devolver fatia vazia com itens ainda pendentes");

            $resumo = $this->service()->coletarDetalhe($company, $fatia);
            $this->assertSame(0, $resumo['falhas'], "execução {$execucao} não pode ter falha");

            $coletadosPorExecucao[$execucao] = $fatia;

            Carbon::setTestNow(now()->addDay());
        }

        $naoCobertos = MlAcervoItem::where('company_id', $company->id)->whereNull('detalhe_coletado_em')->count();
        $this->assertSame(0, $naoCobertos, 'D-23: todo item ativo precisa estar coberto ao fim de N execuções, sem cauda longa cega');

        $todosOsColetados = collect($coletadosPorExecucao)->flatten()->all();
        $this->assertCount(
            $total,
            array_unique($todosOsColetados),
            'nenhum item pode ser coletado 2x antes de todos terem sido coletados 1x'
        );
    }

    // ─── Teste 2 — fatia prioriza nunca-coletados e mais antigos ───────────

    /** @test */
    public function fatia_prioriza_o_mais_antigo_e_os_nunca_coletados(): void
    {
        [$company] = $this->criarFixture();

        $this->criarItem($company, 'MLB0000000001', ['detalhe_coletado_em' => now()->subDays(1)]);
        $this->criarItem($company, 'MLB0000000002', ['detalhe_coletado_em' => null]);
        $this->criarItem($company, 'MLB0000000003', ['detalhe_coletado_em' => now()->subDays(5)]);
        $this->criarItem($company, 'MLB0000000004', ['detalhe_coletado_em' => null]);
        $this->criarItem($company, 'MLB0000000005', ['detalhe_coletado_em' => now()->subDays(2)]);

        $fatia = $this->service()->selecionarFatia($company, 1); // n=1 → fatia cobre 100%

        $this->assertSame(
            ['MLB0000000002', 'MLB0000000004', 'MLB0000000003', 'MLB0000000005', 'MLB0000000001'],
            $fatia,
            'nulos primeiro (empate por ml_item_id), depois mais antigo primeiro'
        );
    }

    // ─── Teste 3 — D-18: buy box não avaliado nunca vira motivo ────────────

    /** @test */
    public function item_de_catalogo_nao_coberto_fica_nao_avaliado(): void
    {
        [$company] = $this->criarFixture();

        // Simula exatamente o caminho que MlAcervoDetalheService usaria: a
        // triagem é recomputada com buyboxStatus=null porque a rotação
        // ainda não chegou neste item.
        $triagem = app(AnuncioSaudeService::class)->triagem(
            ['status' => 'active', 'available_quantity' => 5],
            [],
            null
        );

        $item = $this->criarItem($company, 'MLB0000000099', [
            'catalog_listing' => true,
            'buybox_status' => null,
            'motivos' => $triagem['motivos'],
            'severidade' => $triagem['severidade'],
        ]);

        $this->assertTrue($item->naoAvaliadoBuyBox(), 'D-18: catálogo sem buybox_status é "não avaliado"');
        $this->assertNotContains(
            MlAcervoItem::MOTIVO_PERDENDO_CATALOGO,
            $item->motivos,
            'buybox não avaliado NUNCA vira motivo — nem "sem problema" nem "com problema"'
        );
        $this->assertSame(
            MlAcervoItem::SEVERIDADE_SAUDAVEL,
            $item->severidade,
            'severidade não sobe por causa de buy box não avaliado'
        );
    }

    // ─── Teste 4 — falha não avança frescor nem inventa status ─────────────

    /** @test */
    public function falha_na_camada_cara_nao_avanca_o_frescor_nem_inventa_status(): void
    {
        [$company] = $this->criarFixture();

        $item = $this->criarItem($company, 'MLB0000000042', [
            'catalog_listing' => true,
            'buybox_status' => null,
            'detalhe_coletado_em' => null,
        ]);

        Http::fake([
            'api.mercadolibre.com/items/*/visits*' => Http::response($this->fixture('visits.json'), 200),
            'api.mercadolibre.com/items/*/price_to_win*' => Http::response(['message' => 'erro simulado'], 500),
        ]);

        $resumo = $this->service()->coletarDetalhe($company, ['MLB0000000042']);

        $this->assertSame(0, $resumo['itens']);
        $this->assertSame(1, $resumo['falhas']);

        $item->refresh();
        $this->assertNull($item->buybox_status, 'erro não pode virar status inventado');
        $this->assertNull($item->detalhe_coletado_em, 'frescor não avança em falha — item continua "não avaliado"');
        $this->assertNull($item->visitas_30d, 'falha aborta o item inteiro — nem visitas (já obtidas) são gravadas');

        // O item continua no topo da fila (nulo primeiro) — auto-corretivo, sem cursor.
        $fatia = $this->service()->selecionarFatia($company, 5);
        $this->assertContains('MLB0000000042', $fatia);
    }

    // ─── Teste 5 — D-11: zero write na API do ML ────────────────────────────

    /** @test */
    public function camada_cara_nunca_escreve_na_api_do_ml(): void
    {
        [$company] = $this->criarFixture();

        $itemCatalogo = $this->criarItem($company, 'MLB0000000010', ['catalog_listing' => true]);
        $itemComum = $this->criarItem($company, 'MLB0000000011', ['catalog_listing' => false]);

        $this->fakeDetalhePadrao();

        $resumo = $this->service()->coletarDetalhe($company, [$itemCatalogo->ml_item_id, $itemComum->ml_item_id]);
        $this->assertSame(0, $resumo['falhas']);

        $requisicoesMl = Http::recorded(fn ($request) => str_contains($request->url(), 'mercadolibre.com'));
        $this->assertNotEmpty($requisicoesMl, 'sanity check — o teste precisa ter de fato disparado chamadas ao ML');

        foreach ($requisicoesMl as [$request, $response]) {
            $this->assertSame('GET', $request->method(), 'D-11: zero write na camada cara — ' . $request->url());
        }
    }

    // ─── Teste 6 — a camada cara NUNCA apaga a camada barata ───────────────

    /** @test */
    public function camada_cara_nao_apaga_dados_da_camada_barata(): void
    {
        [$company] = $this->criarFixture();
        $hoje = now()->toDateString();

        $item = $this->criarItem($company, 'MLB0000000077', [
            'title' => 'Anúncio ECF preservado',
            'status' => 'active',
            'available_quantity' => 0, // força MOTIVO_SEM_ESTOQUE na triagem legítima
            'sold_quantity' => 42,
            'nota_ecf' => 58,
            'nota_sinais' => ['titulo' => ['peso' => 12, 'ok' => true]],
            'motivos' => [MlAcervoItem::MOTIVO_SEM_ESTOQUE],
            'severidade' => MlAcervoItem::SEVERIDADE_CRITICA,
            'origem' => MlAcervoItem::ORIGEM_ECF,
            'rascunho_id' => 999,
            'coletado_em' => now(),
            'catalog_listing' => true, // exercita buy box (performance não se aplica a catálogo)
        ]);

        MlAcervoMetricaDiaria::create([
            'company_id' => $company->id,
            'ml_item_id' => $item->ml_item_id,
            'data' => $hoje,
            'sold_quantity' => 42,
            'nota_ecf' => 58,
        ]);

        $this->fakeDetalhePadrao();

        $resumo = $this->service()->coletarDetalhe($company, [$item->ml_item_id]);
        $this->assertSame(1, $resumo['itens']);
        $this->assertSame(0, $resumo['falhas']);

        $item->refresh();

        // Colunas da camada BARATA continuam exatamente com o valor semeado.
        $this->assertSame('Anúncio ECF preservado', $item->title);
        $this->assertSame(42, $item->sold_quantity);
        $this->assertSame(58, $item->nota_ecf);
        $this->assertSame(['titulo' => ['peso' => 12, 'ok' => true]], $item->nota_sinais);
        $this->assertSame(MlAcervoItem::ORIGEM_ECF, $item->origem);
        $this->assertSame(999, $item->rascunho_id);
        $this->assertTrue($item->coletado_em->isToday());

        // triagem() recebeu o pseudo-payload persistido, não um array vazio.
        $this->assertContains(MlAcervoItem::MOTIVO_SEM_ESTOQUE, $item->motivos);

        // Colunas da camada CARA foram de fato escritas.
        $this->assertNotNull($item->visitas_30d, 'visitas_30d foi gravado pela camada cara');
        $this->assertNotNull($item->buybox_status, 'buybox_status foi gravado (item catalog_listing=true)');
        $this->assertNotNull($item->detalhe_coletado_em);

        // Catálogo não pontua no ML (D-21) — performance nunca tocada aqui.
        $this->assertNull($item->performance_score);
        $this->assertNull($item->performance_level);

        // whereDate(), não where('data', '=', $hoje): a coluna é gravada com
        // componente de hora pelo cast `date` do Eloquent (ver comentário de
        // gravarVisitasSerieDiaria()) — uma igualdade direta com string sem
        // hora não bate.
        $serieHoje = MlAcervoMetricaDiaria::where('company_id', $company->id)
            ->where('ml_item_id', $item->ml_item_id)
            ->whereDate('data', $hoje)
            ->first();

        $this->assertSame(42, $serieHoje->sold_quantity, 'série do dia mantém sold_quantity da camada barata');
        $this->assertSame(58, $serieHoje->nota_ecf, 'série do dia mantém nota_ecf da camada barata');
        $this->assertSame($item->visitas_30d, $serieHoje->visitas, 'série do dia ganha visitas da camada cara');
    }

    // ─── Teste 7 — D-21: performance não é chamado onde não se aplica ──────

    /** @test */
    public function performance_nao_e_chamado_quando_nao_se_aplica(): void
    {
        [$company] = $this->criarFixture();

        $itemCatalogo = $this->criarItem($company, 'MLB0000000201', ['catalog_listing' => true, 'status' => 'active']);
        $itemEncerrado = $this->criarItem($company, 'MLB0000000202', ['catalog_listing' => false, 'status' => 'closed']);

        $this->fakeDetalhePadrao();

        $resumo = $this->service()->coletarDetalhe($company, [$itemCatalogo->ml_item_id, $itemEncerrado->ml_item_id]);
        $this->assertSame(0, $resumo['falhas']);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/performance'));

        $itemCatalogo->refresh();
        $itemEncerrado->refresh();
        $this->assertNull($itemCatalogo->performance_score, 'catálogo não pontua no ML (D-21)');
        $this->assertNull($itemEncerrado->performance_score, 'encerrado não pontua no ML (D-21)');
    }

    // ─── Teste 8 — performance grava só PENDING, title preservado ──────────

    /** @test */
    public function performance_grava_apenas_pending_com_title_preservado(): void
    {
        [$company] = $this->criarFixture();

        $item = $this->criarItem($company, 'MLB0000000301', ['catalog_listing' => false, 'status' => 'active']);

        $this->fakeDetalhePadrao();

        $resumo = $this->service()->coletarDetalhe($company, [$item->ml_item_id]);
        $this->assertSame(0, $resumo['falhas']);

        $item->refresh();

        $fixture = $this->fixture('performance-sondagem.json');
        $this->assertSame($fixture['score'], $item->performance_score);
        $this->assertSame($fixture['level'], $item->performance_level);

        $this->assertCount(1, $item->performance_acoes, 'só a variable com status=PENDING entra');
        $this->assertSame('UP_STOCK_AVAILABILITY_TIME', $item->performance_acoes[0]['key']);
        $this->assertSame(
            'Exclua o tempo de disponibilidade para que seu anúncio seja mais competitivo',
            $item->performance_acoes[0]['title'],
            'title preservado exatamente como veio do ML, em pt-BR'
        );
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function service(): MlAcervoDetalheService
    {
        return app(MlAcervoDetalheService::class);
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
            // 1 ano, não 6 dias como ColetaAcervoTest: o teste de rotação
            // avança Carbon::setTestNow() em até N dias dentro do próprio
            // teste (D-23), e um token expirando no meio da simulação
            // dispararia um refresh real (sem fake) e derrubaria o teste
            // por um motivo alheio ao que está sob prova.
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

    /** MLB id determinístico para os testes de rotação em lote. */
    private function mlId(int $i): string
    {
        return 'MLB' . str_pad((string) $i, 10, '0', STR_PAD_LEFT);
    }

    /** Lê uma fixture real de tests/fixtures/phase134/. */
    private function fixture(string $nome): array
    {
        $conteudo = file_get_contents(dirname(__DIR__, 2) . "/fixtures/phase134/{$nome}");

        return json_decode($conteudo, true);
    }

    /**
     * Http::fake padrão da camada cara: visits, price_to_win e performance
     * respondendo com as fixtures reais para QUALQUER item id — suficiente
     * para os testes em que o conteúdo exato da resposta não é o que está
     * sob teste, só o fato de a chamada ter sucesso.
     */
    private function fakeDetalhePadrao(): void
    {
        Http::fake([
            'api.mercadolibre.com/items/*/visits*' => Http::response($this->fixture('visits.json'), 200),
            'api.mercadolibre.com/items/*/price_to_win*' => Http::response($this->fixture('price-to-win.json'), 200),
            'api.mercadolibre.com/item/*/performance*' => Http::response($this->fixture('performance-sondagem.json'), 200),
        ]);
    }
}
