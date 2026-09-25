<?php

namespace Tests\Feature\PortalCliente\Estrutura;

use App\Models\EstruturaAnuncio;
use App\Models\EstruturaAnuncioEspera;
use App\Models\EstruturaOferta;
use App\Models\MlAcervoItem;
use App\Models\MlToken;
use App\Jobs\ImportarAnunciosMlEstruturaJob;
use App\Services\Portal\Estrutura\AnunciosMercadoLivreService;
use App\Services\Portal\Estrutura\EstruturaConjunto;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Os anúncios que a empresa já tem no ML, trazidos pelo OAuth.
 *
 * Os corpos de anúncio são REAIS — `tests/fixtures/phase134/multiget-lote.json`,
 * capturado pela sondagem da Fase 134 (`Http::fake()` nunca sobre shape
 * inventado). Nesse lote, os pares Clássico + Premium do mesmo produto têm o
 * MESMO `SELLER_SKU` (`1808`, `1301-UN-NA`) e `user_product_id` diferentes:
 * é por isso que o casamento é pelo SKU.
 *
 * O lote tem 20 anúncios: 5 encerrados e sem SKU (ficam de fora) e 15 vivos em
 * 11 SKUs. Por venda: 1808 (4926), 1300 (767), 1808 (659), 1303-UN-BC (562),
 * 1304 (329), 1304 (312), 1305 (274), 1304-UN-MDN (255), 1809 (151),
 * 1300-UN-BC (81), 1301-UN-NA (78), 1301-UN-NA (69), 2110 (40),
 * 1302-UN-MDN (34), 1808 (5). Pares Clássico + Premium: 1808 e 1301-UN-NA.
 */
class AnunciosMercadoLivreTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private function fixture(string $nome): array
    {
        return json_decode(file_get_contents(base_path("tests/fixtures/phase134/{$nome}")), true);
    }

    private function conectar($empresa): void
    {
        MlToken::create([
            'company_id' => $empresa->id, 'ml_user_id' => '436501796',
            'access_token' => 'fake-access-token', 'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer', 'scope' => 'read write offline_access',
            'expires_at' => now()->addDays(6), 'last_refreshed_at' => now(),
            'status' => 'active', 'connected_at' => now(),
        ]);
    }

    /**
     * A conta do lote real, respondendo à busca por SKU (`seller_sku`) com os
     * anúncios que têm aquele SKU. O acervo local recebe todos, MENOS
     * `$foraDoAcervo` — o anúncio publicado depois do sync da madrugada, que
     * tem de vir pelo multiget.
     */
    private function fingirConta($empresa, array $foraDoAcervo = [], array $semSku = []): void
    {
        $lote = $this->fixture('multiget-lote.json');
        foreach ($lote as &$w) {
            if (in_array($w['body']['id'], $semSku, true)) {
                $w['body']['attributes'] = array_values(array_filter($w['body']['attributes'], fn ($a) => $a['id'] !== 'SELLER_SKU'));
                $w['body']['seller_custom_field'] = null;
            }
        }
        unset($w);
        $idsPorSku = [];
        foreach ($lote as $w) {
            $sku = AnunciosMercadoLivreService::skuDoAnuncio($w['body']);
            if ($sku) {
                $idsPorSku[$sku][] = $w['body']['id'];
            }
            if (! in_array($w['body']['id'], $foraDoAcervo, true)) {
                MlAcervoItem::create([
                    'company_id' => $empresa->id, 'ml_item_id' => $w['body']['id'], 'title' => $w['body']['title'],
                    'listing_type_id' => $w['body']['listing_type_id'], 'status' => $w['body']['status'],
                    'catalog_listing' => (bool) ($w['body']['catalog_listing'] ?? false),
                    'sold_quantity' => $w['body']['sold_quantity'],
                ]);
            }
        }

        Http::fake([
            '*/items/search*' => function ($request) use ($idsPorSku) {
                $ids = $idsPorSku[$request->data()['seller_sku'] ?? ''] ?? [];

                return Http::response(['results' => $ids, 'paging' => ['total' => count($ids), 'offset' => 0, 'limit' => 50]]);
            },
            '*/items?*' => Http::response($lote),
        ]);
    }

    public function test_cada_anuncio_real_vira_uma_linha_com_sku_tipo_e_status(): void
    {
        $linhas = array_map(fn ($w) => AnunciosMercadoLivreService::linhaDoAnuncio($w['body']), $this->fixture('multiget-lote.json'));
        $porMlb = collect($linhas)->keyBy('mlb');

        $this->assertSame(['sku' => '1808', 'mlb' => 'MLB5318502460', 'tipo' => 'Premium', 'status' => 'Ativo'],
            array_intersect_key($porMlb['MLB5318502460'], array_flip(['sku', 'mlb', 'tipo', 'status'])));
        $this->assertSame('Clássico', $porMlb['MLB5317224904']['tipo']);
        $this->assertSame('Pausado', $porMlb['MLB5316608806']['status']);
        $this->assertSame('Inativo', $porMlb['MLB7046783144']['status']);   // closed
        $this->assertTrue($porMlb['MLB4009839421']['catalogo']);
        $this->assertNull($porMlb['MLB7046783144']['sku']);                  // veio sem SELLER_SKU
        $this->assertSame(15, collect($linhas)->whereNotNull('sku')->count());
    }

    /** Anúncio com variações: o SKU vem delas só quando TODAS concordam. */
    public function test_sku_das_variacoes_so_quando_todas_concordam(): void
    {
        $item = ['id' => 'MLB1', 'listing_type_id' => 'gold_pro', 'variations' => [
            ['attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'X-1']]],
            ['attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'X-1 ']]],
        ]];
        $this->assertSame('X-1', AnunciosMercadoLivreService::skuDoAnuncio($item));

        $item['variations'][1]['attributes'][0]['value_name'] = 'X-2';
        $this->assertNull(AnunciosMercadoLivreService::skuDoAnuncio($item));

        // Anúncio grátis ou outro tipo não entra: o método só trabalha com Clássico e Premium.
        $this->assertNull(AnunciosMercadoLivreService::linhaDoAnuncio(['id' => 'MLB2', 'listing_type_id' => 'free']));
    }

    private function ofertasNovas(array $estado): array
    {
        return array_column($estado['ofertas_novas']['itens'], 'sku');
    }

    /**
     * O fluxo do cliente: módulo vazio, "Puxar do Mercado Livre". Cada SKU vira
     * uma oferta com TODOS os seus anúncios Clássico e Premium; a prévia mostra
     * quais ofertas têm o par e nada é gravado antes de confirmar.
     */
    public function test_puxar_cria_uma_oferta_por_sku_juntando_classico_e_premium(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        $this->fingirConta($empresa);
        $sessao = $this->entrarNoPortal($empresa);

        // Sob a fila `sync` dos testes, o Job roda dentro do POST.
        $sessao->postJson(route('portal.auth.estrutura.importacao.iniciar'))->assertOk();
        $estado = $sessao->getJson(route('portal.auth.estrutura.importacao.estado'))->assertOk()->json();

        $this->assertSame('pronto', $estado['estado']);
        $this->assertSame(['1808', '1300', '1303-UN-BC', '1304', '1305', '1304-UN-MDN', '1809', '1300-UN-BC', '1301-UN-NA', '2110', '1302-UN-MDN'],
            $this->ofertasNovas($estado), 'uma oferta por SKU, na ordem dos mais vendidos');
        $this->assertSame([15, 2, true], [$estado['total'], $estado['ofertas_novas']['pares'], $estado['acabou']]);
        $this->assertSame(['novos' => 15, 'atualizados' => 0, 'espera' => 0, 'erros' => 0, 'removidos' => 0], $estado['previa']['totais'],
            'anúncio de oferta que ainda vai nascer NÃO aparece como "aguardando oferta"');

        $armario = collect($estado['ofertas_novas']['itens'])->firstWhere('sku', '1808');
        $this->assertSame([2, 1, 'mercado_envios', 'Mercado Envios'],
            [$armario['classicos'], $armario['premiums'], $armario['logistica'], $armario['logistica_rotulo']]);
        $this->assertNotNull($armario['nome'], 'o nome vem do anúncio mais vendido do SKU');
        $this->assertNull(collect($estado['ofertas_novas']['itens'])->firstWhere('sku', '1300')['logistica'], 'not_specified não vira chute');
        $this->assertSame(0, EstruturaOferta::count(), 'a prévia não grava');

        // Uma leitura dos candidatos (15 cabem num multiget) e uma busca por SKU.
        Http::assertSentCount(1 + 11);

        $sessao->post(route('portal.auth.estrutura.importacao.aplicar'))->assertSessionHasNoErrors();

        $this->assertSame([11, 15, 0], [EstruturaOferta::count(), EstruturaAnuncio::count(), EstruturaAnuncioEspera::count()]);
        $oferta = EstruturaOferta::where('sku', '1808')->sole();
        $this->assertSame(['simples', 'mercado_envios', $armario['nome']], [$oferta->fase, $oferta->logistica, $oferta->nome]);
        $conjunto = EstruturaConjunto::daEmpresa($empresa);
        $this->assertSame([2, 1, 'ok'], [$conjunto->oferta($oferta->id)['classicos'], $conjunto->oferta($oferta->id)['premiums'], $conjunto->oferta($oferta->id)['situacao']]);
        $this->assertSame('falta_premium', $conjunto->oferta(EstruturaOferta::where('sku', '1304')->value('id'))['situacao']);

        // Puxar de novo: tudo já está aqui — nada duplica, e a tela diz que acabou.
        $sessao->postJson(route('portal.auth.estrutura.importacao.iniciar'))->assertOk();
        $deNovo = $sessao->getJson(route('portal.auth.estrutura.importacao.estado'))->json();
        $this->assertSame([0, 0, true], [$deNovo['total'], $deNovo['ofertas_novas']['total'], $deNovo['acabou']]);
        $this->assertSame(15, EstruturaAnuncio::count());
    }

    /** Conta grande: cada "Puxar" traz um lote, e o próximo continua de onde o anterior parou. */
    public function test_puxar_em_lotes_continua_dos_mais_vendidos(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $this->conectar($empresa);
        $this->fingirConta($empresa);
        $svc = app(AnunciosMercadoLivreService::class);

        $svc->iniciar($empresa, loteSkus: 4);
        $primeiro = $svc->estado($empresa);
        $this->assertSame(['1808', '1300', '1303-UN-BC', '1304'], $this->ofertasNovas($primeiro));
        $this->assertFalse($primeiro['acabou']);
        $svc->aplicar($empresa, $ator);

        $svc->iniciar($empresa, loteSkus: 4);
        $this->assertSame(['1305', '1304-UN-MDN', '1809', '1300-UN-BC'], $this->ofertasNovas($svc->estado($empresa)));
    }

    /**
     * Quem cadastrou a oferta à mão antes de puxar recebe os anúncios dela; o
     * resto da conta vira oferta nova. O anúncio publicado depois do sync da
     * madrugada (fora do acervo) vem pela busca do SKU e passa pelo multiget.
     */
    public function test_oferta_cadastrada_a_mao_recebe_os_seus_anuncios(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $this->conectar($empresa);
        $svc = app(EstruturaOfertaService::class);
        [$armario] = $svc->criar($empresa, ['sku' => '1808', 'fase' => 'simples', 'nome' => 'Armário Aéreo'], $ator);
        [$balcao] = $svc->criar($empresa, ['sku' => '1301-UN-NA', 'fase' => 'simples', 'nome' => 'Balcão Cooktop'], $ator);
        [$turim] = $svc->criar($empresa, ['sku' => '1304', 'fase' => 'simples', 'nome' => 'Balcão Turim'], $ator);
        $svc->criar($empresa, ['sku' => 'SEM-NADA-NO-ML', 'fase' => 'simples'], $ator);
        $this->fingirConta($empresa, foraDoAcervo: ['MLB5317120924']);
        $sessao = $this->entrarNoPortal($empresa);

        $sessao->postJson(route('portal.auth.estrutura.importacao.iniciar'))->assertOk();
        $estado = $sessao->getJson(route('portal.auth.estrutura.importacao.estado'))->json();

        $this->assertSame([15, 12, 1, 8], [$estado['total'], $estado['skus'], $estado['sem_anuncio'], $estado['ofertas_novas']['total']]);
        $this->assertNotContains('1808', $this->ofertasNovas($estado), 'a oferta já existe: não nasce outra');
        // Candidatos (1 multiget), uma busca por SKU (12), e o multiget do que faltava no acervo.
        Http::assertSentCount(1 + 12 + 1);

        $sessao->post(route('portal.auth.estrutura.importacao.aplicar'))->assertSessionHasNoErrors();

        $conjunto = EstruturaConjunto::daEmpresa($empresa);
        $this->assertSame([2, 1, 'ok'], [$conjunto->oferta($armario->id)['classicos'], $conjunto->oferta($armario->id)['premiums'], $conjunto->oferta($armario->id)['situacao']]);
        $this->assertSame('ok', $conjunto->oferta($balcao->id)['situacao']);
        $this->assertSame('falta_premium', $conjunto->oferta($turim->id)['situacao']);
        $this->assertSame([12, 15], [EstruturaOferta::count(), EstruturaAnuncio::count()]);
    }

    /** Anúncio sem SKU não junta com nada: fica aguardando oferta, e não volta no próximo "Puxar". */
    public function test_anuncio_sem_sku_fica_aguardando_oferta(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $this->conectar($empresa);
        $this->fingirConta($empresa, semSku: ['MLB5308780432']);   // o 1300, 2º mais vendido
        $svc = app(AnunciosMercadoLivreService::class);

        $svc->iniciar($empresa);
        $estado = $svc->estado($empresa);
        $this->assertNotContains('1300', $this->ofertasNovas($estado));
        $this->assertSame([1, 1], [$estado['sem_sku'], $estado['previa']['totais']['espera']]);
        $svc->aplicar($empresa, $ator);

        $espera = EstruturaAnuncioEspera::sole();
        $this->assertSame(['MLB5308780432', 'sem_sku'], [$espera->codigo_mlb, $espera->motivo]);

        $svc->iniciar($empresa);
        $this->assertSame(0, $svc->estado($empresa)['total']);
    }

    /**
     * Em fatias: a fila reentrega Job acima de 90 s, então cada Job trabalha
     * um orçamento e passa a vez. Com orçamento zero, cada fatia faz uma
     * unidade — e o resultado é o mesmo da leitura de uma vez.
     */
    public function test_leitura_em_fatias_e_uma_rodada_por_vez(): void
    {
        Queue::fake();
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        $this->fingirConta($empresa);
        $svc = app(AnunciosMercadoLivreService::class);

        $svc->iniciar($empresa);
        $svc->iniciar($empresa);   // segundo clique com a leitura em andamento
        Queue::assertPushed(ImportarAnunciosMlEstruturaJob::class, 1);
        $rodada = Queue::pushed(ImportarAnunciosMlEstruturaJob::class)->first()->rodada;

        $this->assertTrue($svc->passo($empresa, 'rodada-antiga', 0), 'rodada que não é a atual para sem mexer em nada');
        $this->assertSame(0, $svc->estado($empresa)['lidos']);

        $fatias = 1;
        while (! $svc->passo($empresa, $rodada, 0)) {
            $fatias++;
            $lendo = $svc->estado($empresa);
            $this->assertSame(['estado', 'etapa', 'lidos', 'skus', 'procurados'], array_keys($lendo), 'a lista de MLBs não vai para o navegador');
        }

        // 2 de procura (15 candidatos + a que descobre o fim), 11 SKUs, 1 que fecha.
        $this->assertSame(14, $fatias);
        $this->assertCount(11, $this->ofertasNovas($svc->estado($empresa)));
    }

    public function test_logistica_no_vocabulario_da_planilha(): void
    {
        $l = fn (array $envio) => AnunciosMercadoLivreService::logisticaDoAnuncio(['shipping' => $envio]);

        $this->assertSame('full', $l(['mode' => 'me2', 'logistic_type' => 'fulfillment']));
        $this->assertSame('flex', $l(['mode' => 'me2', 'logistic_type' => 'self_service']));
        $this->assertSame('transportadora_me1', $l(['mode' => 'me1']));
        $this->assertSame('mercado_envios', $l(['mode' => 'me2', 'logistic_type' => 'cross_docking']));
        $this->assertNull($l(['mode' => 'not_specified', 'logistic_type' => 'not_specified']));
        foreach (['full', 'flex', 'transportadora_me1', 'mercado_envios'] as $chave) {
            $this->assertArrayHasKey($chave, EstruturaOferta::LOGISTICAS);
        }
    }

    public function test_sem_conta_conectada_nao_importa(): void
    {
        $empresa = $this->empresaDoGabarito();

        $this->entrarNoPortal($empresa)
            ->postJson(route('portal.auth.estrutura.importacao.iniciar'))
            ->assertStatus(422);
    }

    // ═══ A exceção: buscar e ligar ══════════════════════════════════════════

    private function acervo($empresa, string $mlb, string $titulo, string $tipo = 'gold_special', string $status = 'active'): MlAcervoItem
    {
        return MlAcervoItem::create([
            'company_id' => $empresa->id, 'ml_item_id' => $mlb, 'title' => $titulo,
            'listing_type_id' => $tipo, 'status' => $status, 'catalog_listing' => false,
        ]);
    }

    public function test_busca_so_mostra_anuncios_da_empresa_e_diz_onde_ja_estao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator);          // MLB0000000001 está na CAD-01
        $this->acervo($empresa, 'MLB0000000001', 'Cadeira de Jantar Estofada');
        $this->acervo($empresa, 'MLB0000000099', 'Cadeira Jantar Premium Veludo', 'gold_pro');
        $this->acervo($empresa, 'MLB0000000098', 'Cadeira grátis', 'free');
        $outra = $this->empresaDoGabarito();
        $this->acervo($outra, 'MLB0000000097', 'Cadeira de outra empresa');

        $r = $this->entrarNoPortal($empresa)
            ->getJson(route('portal.auth.estrutura.anuncios_ml.buscar', ['q' => 'cadeira']))
            ->assertOk()->json();

        $this->assertEqualsCanonicalizing(['MLB0000000001', 'MLB0000000099'], array_column($r['itens'], 'mlb'));
        $porMlb = collect($r['itens'])->keyBy('mlb');
        $this->assertSame('CAD-01', $porMlb['MLB0000000001']['ligado_a']);
        $this->assertNull($porMlb['MLB0000000099']['ligado_a']);

        // Filtrada pelo tipo — no "Concluir Premium" da agenda.
        $soPremium = $this->entrarNoPortal($empresa)
            ->getJson(route('portal.auth.estrutura.anuncios_ml.buscar', ['q' => 'cadeira', 'tipo' => 'premium']))->json('itens');
        $this->assertSame(['MLB0000000099'], array_column($soPremium, 'mlb'));
    }

    public function test_ligar_usa_o_registro_do_acervo_e_recusa_o_que_ja_esta_em_outra_oferta(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->anunciosDoGabarito($ofertas, $ator);
        $this->acervo($empresa, 'MLB0000000099', 'Kit 2 Cadeiras Premium', 'gold_pro', 'paused');
        $this->acervo($empresa, 'MLB0000000001', 'Cadeira de Jantar Estofada');
        $sessao = $this->entrarNoPortal($empresa);

        $sessao->post(route('portal.auth.estrutura.anuncios_ml.ligar', $ofertas['CAD-01-CB2']->id), ['ml_item_id' => 'MLB0000000099'])
            ->assertSessionHasNoErrors();

        $ligado = EstruturaAnuncio::where('codigo_mlb', 'MLB0000000099')->sole();
        $this->assertSame([$ofertas['CAD-01-CB2']->id, 'premium', 'pausado', 'Kit 2 Cadeiras Premium'],
            [$ligado->oferta_id, $ligado->tipo, $ligado->status, $ligado->titulo]);

        // Já está na CAD-01: ligar à CB3 é recusado.
        $sessao->post(route('portal.auth.estrutura.anuncios_ml.ligar', $ofertas['CAD-01-CB3']->id), ['ml_item_id' => 'MLB0000000001'])
            ->assertSessionHasErrors('ml_item_id');
    }

    public function test_ligar_tira_da_espera_e_nao_enxerga_outra_empresa(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $ofertas = $this->listaDoGabarito($empresa, $ator);
        $this->acervo($empresa, 'MLB0000000050', 'Mesa Marfim Clássico');
        EstruturaAnuncioEspera::create(['company_id' => $empresa->id, 'sku_colado' => 'MESA-ANTIGA', 'motivo' => 'sem_oferta',
            'tipo' => 'classico', 'status' => 'ativo', 'codigo_mlb' => 'MLB0000000050']);
        $outra = $this->empresaDoGabarito();
        $this->acervo($outra, 'MLB0000000060', 'Alheio');
        $sessao = $this->entrarNoPortal($empresa);

        $sessao->post(route('portal.auth.estrutura.anuncios_ml.ligar', $ofertas['MSA-MR']->id), ['ml_item_id' => 'MLB0000000050'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, EstruturaAnuncioEspera::count());
        // Ganhou o Clássico que estava na espera; o Premium ainda falta.
        $this->assertSame('falta_premium', EstruturaConjunto::daEmpresa($empresa)->oferta($ofertas['MSA-MR']->id)['situacao']);

        $sessao->post(route('portal.auth.estrutura.anuncios_ml.ligar', $ofertas['MSA-MR']->id), ['ml_item_id' => 'MLB0000000060'])
            ->assertNotFound();
    }
}
