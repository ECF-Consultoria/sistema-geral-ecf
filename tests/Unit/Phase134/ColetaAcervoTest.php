<?php

namespace Tests\Unit\Phase134;

use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlAcervoMetricaDiaria;
use App\Models\MlAnuncioRascunho;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\Publicacao;
use App\Models\User;
use App\Services\Mlb\Acervo\MlAcervoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 134 (Plano 04) — camada BARATA da coleta do acervo Mercado Livre.
 *
 * Trava os 5 comportamentos que mais podem quebrar em silêncio:
 *   (1) varredura usa scroll_id, nunca offset (D-20) — offset estoura em
 *       ~1000-1100 itens e a maior conta tem 66.747
 *   (2) conta grande (>1000 itens) é varrida por completo
 *   (3) item com variações vira UMA linha, com o agregado do item-pai (D-17)
 *   (4) selo de origem cobre os 3 casos — ecf/time/legado (D-04), e o scope
 *       considerado() NÃO entra na listagem
 *   (5) zero write na API do ML (D-11)
 *   (6) a camada barata NUNCA apaga o que a camada cara já coletou
 *       (buybox_status/visitas_30d/detalhe_coletado_em) — D-23/D-18
 *
 * Estratégia: RefreshDatabase (SQLite in-memory) + Http::fake — nunca ML
 * real. App token da MlCatalogoMetaService pré-semeado no cache (mesmo
 * padrão de tests/Feature/Phase82/GradeMassaTest.php) para evitar o POST de
 * client_credentials.
 *
 * @group phase134
 */
class ColetaAcervoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // NUNCA um Http::fake() vazio aqui: um fake "pega tudo" registrado no
        // setUp seria avaliado ANTES dos fakes específicos de cada teste (a
        // fila de stubs do Laravel resolve na ordem de registro), silenciando
        // qualquer pattern mais específico declarado depois. Cada teste monta
        // o próprio Http::fake([...]) com exatamente os endpoints que usa.

        // App token pré-semeado → MlColetaService::getAppToken() serve do
        // cache e não dispara o POST de client_credentials (usado por
        // MlCatalogoMetaService::atributos(), chamado dentro de avaliar()).
        Cache::put('ml_app_token_coleta', 'fake-app-token', now()->addHour());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─── Teste 1 — D-20: scroll_id, nunca offset ───────────────────────────

    /** @test */
    public function varredura_usa_scroll_e_nao_offset(): void
    {
        [$company] = $this->criarFixture();

        Http::fake([
            '*/items/search*' => Http::sequence()
                ->push($this->fixture('scroll-pagina-1.json'))
                ->push($this->fixture('scroll-pagina-2.json'))
                ->push($this->fixture('scroll-pagina-3.json'))
                ->push(['results' => [], 'scroll_id' => null, 'paging' => ['limit' => 50, 'total' => 150]]),
        ]);

        $ids = iterator_to_array($this->service()->enumerarIds($company));

        $this->assertCount(150, array_unique($ids), '3 páginas de 50 ids, sem repetição');

        $requisicoes = Http::recorded(fn ($request) => str_contains($request->url(), '/items/search'));
        $this->assertNotEmpty($requisicoes);

        foreach ($requisicoes as [$request, $response]) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $this->assertSame('scan', $query['search_type'] ?? null, 'toda chamada de scroll usa search_type=scan');
            $this->assertArrayNotHasKey('offset', $query, 'D-20: scroll nunca usa offset');
        }
    }

    // ─── Teste 2 — conta com mais de mil itens é varrida por completo ──────

    /** @test */
    public function conta_com_mais_de_mil_itens_e_varrida_por_completo(): void
    {
        [$company] = $this->criarFixture();

        $totalEsperado = 25 * 50; // 1.250 — acima do penhasco real do offset (~1000-1100)
        $sequence = Http::sequence();

        for ($pagina = 0; $pagina < 25; $pagina++) {
            $ids = [];
            for ($i = 0; $i < 50; $i++) {
                $ids[] = 'MLB' . str_pad((string) ($pagina * 50 + $i + 1), 10, '0', STR_PAD_LEFT);
            }
            $sequence->push(['results' => $ids, 'scroll_id' => "scroll-fake-{$pagina}", 'paging' => ['limit' => 50, 'total' => $totalEsperado]]);
        }
        $sequence->push(['results' => [], 'scroll_id' => null, 'paging' => ['limit' => 50, 'total' => $totalEsperado]]);

        Http::fake(['*/items/search*' => $sequence]);

        $ids = iterator_to_array($this->service()->enumerarIds($company));

        $this->assertCount($totalEsperado, array_unique($ids), 'a varredura por scroll_id não pode perder itens acima do penhasco do offset');
    }

    // ─── Teste 3 — D-17: variação vira UMA linha, agregado do item-pai ─────

    /** @test */
    public function item_com_variacoes_grava_uma_linha_com_o_agregado(): void
    {
        [$company] = $this->criarFixture();

        $itemComVariacoes = $this->fixture('multiget-item-com-variacoes.json');

        Http::fake([
            '*/items/search*' => Http::sequence()
                ->push(['results' => [$itemComVariacoes['id']], 'scroll_id' => 'scroll-1', 'paging' => ['limit' => 50, 'total' => 1]])
                ->push(['results' => [], 'scroll_id' => null, 'paging' => ['limit' => 50, 'total' => 1]]),
            '*/items?ids=*' => Http::response([['code' => 200, 'body' => $itemComVariacoes]], 200),
            'api.mercadolibre.com/categories/*/attributes' => Http::response([], 200),
        ]);

        $this->service()->coletarCamadaBarata($company);

        $linhas = MlAcervoItem::where('company_id', $company->id)
            ->where('ml_item_id', $itemComVariacoes['id'])
            ->get();

        $this->assertCount(1, $linhas, 'anúncio com variação vira UMA linha, não N');
        $this->assertTrue((bool) $linhas[0]->has_variations);
        $this->assertCount(count($itemComVariacoes['variations']), $linhas[0]->variations);
        $this->assertSame(
            $itemComVariacoes['available_quantity'],
            $linhas[0]->available_quantity,
            'D-17: agregado do item-pai, nunca soma das variações feita à mão'
        );
        $this->assertSame($itemComVariacoes['sold_quantity'], $linhas[0]->sold_quantity);
    }

    // ─── Teste 4 — D-04: selo de origem cobre os 3 casos ───────────────────

    /** @test */
    public function selo_de_origem_cobre_os_tres_casos(): void
    {
        [$company] = $this->criarFixture();
        $this->fakeColetaPadrao();

        $ids = $this->idsDoMultigetLote();

        $rascunho = MlAnuncioRascunho::create([
            'company_id' => $company->id,
            'user_id' => User::factory()->create()->id,
            'category_id' => 'MLB123456',
            'status' => MlAnuncioRascunho::STATUS_PUBLICADO,
            'ml_item_id' => $ids[0],
            'payload' => ['title' => 'Anúncio nascido no ECF'],
        ]);

        $mlbEmpresa = MlbEmpresa::create([
            'nome' => 'Empresa Coleta Teste ' . $company->id,
            'tipo' => 'ASSESSORIA',
            'company_id' => $company->id,
        ]);

        Publicacao::create([
            'data' => now(),
            'user_id' => User::factory()->create()->id,
            'empresa' => 'Empresa Coleta Teste',
            'mlb_code' => $ids[1],
            'mlb_empresa_id' => $mlbEmpresa->id,
            'vendas_qty' => 7,
            // Desconsiderado=true e AINDA ASSIM tem que aparecer — o scope
            // considerado() não entra na listagem (D-04, Publicacao.php:171).
            'desconsiderado' => true,
        ]);

        $this->service()->coletarCamadaBarata($company);

        $linhaEcf = MlAcervoItem::where('company_id', $company->id)->where('ml_item_id', $ids[0])->first();
        $linhaTime = MlAcervoItem::where('company_id', $company->id)->where('ml_item_id', $ids[1])->first();
        $linhaLegado = MlAcervoItem::where('company_id', $company->id)->where('ml_item_id', $ids[2])->first();

        $this->assertNotNull($linhaEcf);
        $this->assertSame(MlAcervoItem::ORIGEM_ECF, $linhaEcf->origem);
        $this->assertSame($rascunho->id, $linhaEcf->rascunho_id);

        $this->assertNotNull($linhaTime);
        $this->assertSame(MlAcervoItem::ORIGEM_TIME, $linhaTime->origem);
        $this->assertSame(7, $linhaTime->publicacao_vendas_qty);
        $this->assertTrue(
            (bool) $linhaTime->publicacao_desconsiderado,
            'desconsiderado=true ainda assim aparece na listagem — considerado() não entra aqui'
        );

        $this->assertNotNull($linhaLegado);
        $this->assertSame(MlAcervoItem::ORIGEM_LEGADO, $linhaLegado->origem);
        $this->assertNull($linhaLegado->rascunho_id);
    }

    // ─── Teste 5 — série diária só grava quando algo muda ──────────────────

    /** @test */
    public function serie_diaria_so_grava_quando_algo_muda(): void
    {
        [$company] = $this->criarFixture();

        $ids = $this->idsDoMultigetLote();
        $loteOriginal = $this->fixture('multiget-lote.json');

        $loteAlterado = $loteOriginal;
        $loteAlterado[0]['body']['sold_quantity'] = $loteOriginal[0]['body']['sold_quantity'] + 50;

        $pagina = ['results' => $ids, 'scroll_id' => 'scroll-fake', 'paging' => ['limit' => 50, 'total' => count($ids)]];
        $terminador = ['results' => [], 'scroll_id' => null, 'paging' => ['limit' => 50, 'total' => count($ids)]];

        // Um único Http::fake() para as 3 execuções (calls acumulam via
        // sequence — refazer o fake no meio do teste NÃO sobrescreve o
        // anterior, o stub mais antigo continuaria respondendo primeiro).
        Http::fake([
            '*/items/search*' => Http::sequence()
                ->push($pagina)->push($terminador) // execução 1 (dia 1)
                ->push($pagina)->push($terminador) // execução 2 (dia 1, mesma data)
                ->push($pagina)->push($terminador), // execução 3 (dia 2)
            '*/items?ids=*' => Http::sequence()
                ->push($loteOriginal) // execução 1
                ->push($loteOriginal) // execução 2 — nada muda
                ->push($loteAlterado), // execução 3 — sold_quantity do item[0] mudou
            'api.mercadolibre.com/categories/*/attributes' => Http::response([], 200),
        ]);

        $servico = $this->service();

        $servico->coletarCamadaBarata($company);
        $servico->coletarCamadaBarata($company);

        $this->assertSame(
            count($ids),
            MlAcervoMetricaDiaria::where('company_id', $company->id)->count(),
            'duas execuções no mesmo dia atualizam a MESMA linha, não duplicam'
        );

        Carbon::setTestNow(now()->addDay());
        $servico->coletarCamadaBarata($company);

        $this->assertSame(
            count($ids) + 1,
            MlAcervoMetricaDiaria::where('company_id', $company->id)->count(),
            'só o item que mudou (sold_quantity) ganha linha nova no dia seguinte — os outros 19 continuam com buraco'
        );
    }

    /**
     * @test
     *
     * Contar linhas não basta. O bug real encontrado no plano 134-05 era um
     * `updateOrCreate(['data' => $hoje])` que NUNCA achava a linha do dia (a
     * coluna tem cast `date` mas é gravada como `Y-m-d H:i:s`, então a
     * comparação SQL não bate) e caía num INSERT que colidia com o UNIQUE —
     * engolido pelo `try/catch` fail-open de `processarLote()`. A contagem
     * continuava certa **pelo motivo errado**: a linha existia, só nunca era
     * atualizada. Este teste olha o VALOR, não a contagem.
     */
    public function serie_diaria_atualiza_de_verdade_a_linha_do_mesmo_dia(): void
    {
        [$company] = $this->criarFixture();

        $ids = $this->idsDoMultigetLote();
        $loteOriginal = $this->fixture('multiget-lote.json');

        $vendidoInicial = (int) $loteOriginal[0]['body']['sold_quantity'];
        $vendidoDepois  = $vendidoInicial + 7;

        $loteAlterado = $loteOriginal;
        $loteAlterado[0]['body']['sold_quantity'] = $vendidoDepois;

        $pagina = ['results' => $ids, 'scroll_id' => 'scroll-fake', 'paging' => ['limit' => 50, 'total' => count($ids)]];
        $terminador = ['results' => [], 'scroll_id' => null, 'paging' => ['limit' => 50, 'total' => count($ids)]];

        Http::fake([
            '*/items/search*' => Http::sequence()
                ->push($pagina)->push($terminador)
                ->push($pagina)->push($terminador),
            '*/items?ids=*' => Http::sequence()
                ->push($loteOriginal)
                ->push($loteAlterado),
            'api.mercadolibre.com/categories/*/attributes' => Http::response([], 200),
        ]);

        $servico = $this->service();

        $servico->coletarCamadaBarata($company);
        $servico->coletarCamadaBarata($company); // mesmo dia, valor diferente

        $linhas = MlAcervoMetricaDiaria::where('company_id', $company->id)
            ->where('ml_item_id', $ids[0])
            ->get();

        $this->assertCount(1, $linhas, 'ainda é UMA linha para o dia — não duplicou');
        $this->assertSame(
            $vendidoDepois,
            $linhas->first()->sold_quantity,
            'a linha do dia precisa carregar o valor da ÚLTIMA coleta; se vier o valor antigo, '
            . 'o update silenciosamente não aconteceu e a evolução do D-07 está congelada'
        );
    }

    // ─── Teste 6 — D-11: zero write na API do ML ───────────────────────────

    /** @test */
    public function coleta_nunca_escreve_na_api_do_ml(): void
    {
        [$company] = $this->criarFixture();
        $this->fakeColetaPadrao();

        $this->service()->coletarCamadaBarata($company);

        $requisicoesMl = Http::recorded(fn ($request) => str_contains($request->url(), 'mercadolibre.com'));
        $this->assertNotEmpty($requisicoesMl, 'sanity check — o teste precisa ter de fato disparado chamadas ao ML');

        foreach ($requisicoesMl as [$request, $response]) {
            $this->assertSame('GET', $request->method(), 'D-11: zero write no caminho de coleta — ' . $request->url());
        }
    }

    // ─── Teste 7 — D-23/D-18: preserva o que a camada cara já coletou ──────

    /** @test */
    public function camada_barata_preserva_dados_da_camada_cara_ja_coletados(): void
    {
        [$company] = $this->criarFixture();
        $this->fakeColetaPadrao();

        $ids = $this->idsDoMultigetLote();
        $alvo = $ids[0];
        $ontem = now()->subDay();

        MlAcervoItem::create([
            'company_id' => $company->id,
            'ml_item_id' => $alvo,
            'sold_quantity' => 1, // propositalmente velho — a fixture traz outro valor
            'buybox_status' => 'winning',
            'visitas_30d' => 137,
            'detalhe_coletado_em' => $ontem,
            'coletado_em' => $ontem,
            'origem' => MlAcervoItem::ORIGEM_LEGADO,
        ]);

        $servico = $this->service();
        $servico->coletarCamadaBarata($company);

        $linha = MlAcervoItem::where('company_id', $company->id)->where('ml_item_id', $alvo)->first();

        $this->assertSame('winning', $linha->buybox_status, 'buybox_status sobrevive intacto');
        $this->assertSame(137, $linha->visitas_30d, 'visitas_30d sobrevive intacto');
        $this->assertTrue($linha->detalhe_coletado_em->isSameDay($ontem), 'detalhe_coletado_em sobrevive intacto');
        $this->assertNotSame(1, $linha->sold_quantity, 'sold_quantity foi de fato atualizado pela camada barata');
        $this->assertTrue($linha->coletado_em->isToday(), 'coletado_em foi atualizado por esta camada');

        // A rotação do D-23 vive de execuções repetidas — o estrago de um
        // upsert sem 3º argumento apareceria já na 1ª passagem, mas o gate
        // real é sobreviver a MÚLTIPLAS.
        $servico->coletarCamadaBarata($company);

        $linha->refresh();
        $this->assertSame('winning', $linha->buybox_status);
        $this->assertSame(137, $linha->visitas_30d);
        $this->assertTrue($linha->detalhe_coletado_em->isSameDay($ontem));
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function service(): MlAcervoService
    {
        return app(MlAcervoService::class);
    }

    /** Cria Company + MlToken ativo (mesmo padrão de tests/Feature/Phase86/HistoricoAnunciosTest.php). */
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
            'expires_at' => now()->addDays(6),
            'last_refreshed_at' => now(),
            'status' => 'active',
            'connected_at' => now(),
        ]);

        return [$company];
    }

    /** Lê uma fixture real de tests/fixtures/phase134/. */
    private function fixture(string $nome): array
    {
        $conteudo = file_get_contents(dirname(__DIR__, 2) . "/fixtures/phase134/{$nome}");

        return json_decode($conteudo, true);
    }

    /** Os 20 MLB ids reais que compõem multiget-lote.json, na ordem do arquivo. */
    private function idsDoMultigetLote(): array
    {
        return collect($this->fixture('multiget-lote.json'))->pluck('body.id')->all();
    }

    /**
     * Http::fake padrão para os testes que rodam coletarCamadaBarata() por
     * inteiro: 1 página de scroll com os 20 ids reais de multiget-lote.json
     * + terminador vazio, multiget devolvendo a fixture real, e atributos de
     * categoria vazios (sem obrigatórios/opcionais — a nota ECF não é o que
     * estes testes verificam).
     *
     * O scroll é resolvido por CLOSURE (não Http::sequence()), verificando
     * se a querystring já leva scroll_id — assim o fake sobrevive a
     * múltiplas chamadas de coletarCamadaBarata() no mesmo teste (Teste 7
     * roda 2x: uma sequence finita esgotaria e lançaria OutOfBoundsException
     * na 2ª rodada).
     */
    private function fakeColetaPadrao(): void
    {
        $ids = $this->idsDoMultigetLote();

        Http::fake([
            '*/items/search*' => function ($request) use ($ids) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                if (array_key_exists('scroll_id', $query)) {
                    return Http::response(['results' => [], 'scroll_id' => null, 'paging' => ['limit' => 50, 'total' => count($ids)]], 200);
                }

                return Http::response(['results' => $ids, 'scroll_id' => 'scroll-fake-1', 'paging' => ['limit' => 50, 'total' => count($ids)]], 200);
            },
            '*/items?ids=*' => Http::response($this->fixture('multiget-lote.json'), 200),
            'api.mercadolibre.com/categories/*/attributes' => Http::response([], 200),
        ]);
    }
}
