<?php

namespace Tests\Feature\PlanejadorFull;

use App\Jobs\ColetarPlanejadorFullJob;
use App\Models\Company;
use App\Models\FullColeta;
use App\Models\FullConfiguracao;
use App\Models\FullEnvio;
use App\Models\FullItem;
use App\Models\FullProduto;
use App\Models\FullSku;
use App\Models\FullVendaDiaria;
use App\Models\MlToken;
use App\Models\User;
use App\Services\PlanejadorFull\FullColetaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Planejador FULL — coleta na API do ML (simulada) e a tela que a lê.
 *
 * Os formatos das respostas falsas são os medidos ao vivo na conta WENUS em
 * 15/10/2026 (items/search scan, multiget, stock/fulfillment, operations/search,
 * orders/search). Nenhuma chamada real ao ML.
 */
class PlanejadorFullTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'consultor']);
    }

    private function empresaComToken(string $nome = 'RELOJOARIA WENUS'): Company
    {
        $company = Company::factory()->create(['name' => $nome, 'active' => true]);
        MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => '570267839',
            'access_token'  => 'APP_USR-x',
            'refresh_token' => 'TG-x',
            'expires_at'    => now()->addHours(5),
            'status'        => 'active',
        ]);

        return $company->fresh();
    }

    /** API do ML falsa: um roteador só (o primeiro stub do Http::fake vence). */
    private function fakeMl(array $sobrescreve = []): void
    {
        $respostas = $sobrescreve + [
            'items/search' => ['results' => ['MLB1', 'MLB2', 'MLB3'], 'scroll_id' => 'S1', 'paging' => ['total' => 3]],
            'items' => [
                ['code' => 200, 'body' => [
                    'id' => 'MLB1', 'title' => 'Relógio Casio W-59', 'status' => 'active', 'inventory_id' => 'OKFW19104',
                    'seller_custom_field' => null, 'available_quantity' => 207, 'variations' => [],
                    'attributes' => [['id' => 'BRAND', 'value_name' => 'Casio'], ['id' => 'SELLER_SKU', 'value_name' => 'RPCMD-W-59']],
                ]],
                ['code' => 200, 'body' => [
                    'id' => 'MLB2', 'title' => 'Relógio Orient', 'status' => 'active', 'inventory_id' => null, 'available_quantity' => 837,
                    'attributes' => [['id' => 'BRAND', 'value_name' => 'Orient']],
                    'variations' => [['id' => 77, 'inventory_id' => 'HHCQ98890', 'seller_custom_field' => null, 'available_quantity' => 837, 'attribute_combinations' => [['value_name' => 'Azul']]]],
                ]],
                ['code' => 200, 'body' => [
                    'id' => 'MLB3', 'title' => 'Relógio antigo', 'status' => 'closed', 'inventory_id' => 'ZZZZ00001',
                    'seller_custom_field' => 'VELHO', 'available_quantity' => 0, 'variations' => [], 'attributes' => [],
                ]],
            ],
            'stock' => [
                'OKFW19104' => ['available_quantity' => 207, 'not_available_detail' => [['status' => 'lost', 'quantity' => 2]]],
                'HHCQ98890' => ['available_quantity' => 837, 'not_available_detail' => [['status' => 'transfer', 'quantity' => 49]]],
            ],
            'operations' => ['results' => [], 'paging' => ['total' => 0, 'scroll' => null]],
            // Um pedido por dia: 2 un. do Casio e 1 un. do Orient (variação 77).
            'orders' => ['results' => [['order_items' => [
                ['item' => ['id' => 'MLB1', 'variation_id' => null, 'seller_sku' => 'RPCMD-W-59'], 'quantity' => 2, 'unit_price' => 100],
                ['item' => ['id' => 'MLB2', 'variation_id' => 77, 'seller_sku' => 'ROPFA-AZ'], 'quantity' => 1, 'unit_price' => 300],
            ]]], 'paging' => ['total' => 1]],
        ];

        Http::fake(function (HttpRequest $req) use ($respostas) {
            $url = $req->url();

            if (str_contains($url, '/items/search')) {
                // 2ª página do scroll vem vazia.
                return Http::response(str_contains($url, 'scroll_id=') ? ['results' => [], 'scroll_id' => 'S1'] : $respostas['items/search']);
            }
            if (preg_match('#/inventories/([A-Z0-9]+)/stock/fulfillment#', $url, $m)) {
                return Http::response($respostas['stock'][$m[1]] ?? ['available_quantity' => 0, 'not_available_detail' => []]);
            }
            if (str_contains($url, '/stock/fulfillment/operations/search')) {
                return Http::response(str_contains($url, 'scroll=') ? ['results' => [], 'paging' => ['total' => 0]] : $respostas['operations']);
            }
            if (str_contains($url, '/orders/search')) {
                return Http::response($respostas['orders']);
            }
            if (str_contains($url, '/items')) {
                return Http::response($respostas['items']);
            }

            return Http::response(['message' => 'não simulado: ' . $url], 404);
        });
    }

    // ═══ Coleta ══════════════════════════════════════════════════════════════

    public function test_coleta_grava_codigos_ml_estoque_e_vendas_dos_ultimos_30_dias(): void
    {
        $company = $this->empresaComToken();
        $this->fakeMl();

        $coleta = app(FullColetaService::class)->coletar($company, 'manual', CarbonImmutable::parse('2026-10-15 06:30:00'));

        $this->assertSame(FullColeta::STATUS_OK, $coleta->status);
        $this->assertSame(3, $coleta->produtos);
        $this->assertSame(30, $coleta->pedidos);

        $casio = FullProduto::where('inventory_id', 'OKFW19104')->first();
        $this->assertSame('RPCMD-W-59', $casio->sku);           // do atributo SELLER_SKU
        $this->assertSame('Casio', $casio->marca);
        $this->assertSame(207, $casio->aptas);
        $this->assertSame(2, $casio->indisponivel);
        $this->assertNotNull($casio->estoque_em);

        $orient = FullProduto::where('inventory_id', 'HHCQ98890')->first();
        $this->assertSame('Relógio Orient · Azul', $orient->titulo);
        $this->assertSame('ROPFA-AZ', $orient->sku);            // veio do pedido (seller_sku)
        $this->assertSame(49, $orient->transferencia);

        // Encerrado, zerado e sem venda: entra no catálogo mas não gasta chamada de estoque.
        $this->assertNull(FullProduto::where('inventory_id', 'ZZZZ00001')->first()->estoque_em);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'ZZZZ00001/stock'));

        $this->assertSame(60, (int) FullVendaDiaria::where('inventory_id', 'OKFW19104')->sum('unidades'));
        $this->assertSame(30, FullVendaDiaria::where('inventory_id', 'HHCQ98890')->count());
        $this->assertSame('2026-09-15', FullVendaDiaria::min('data'));
        $this->assertSame('2026-10-14', FullVendaDiaria::max('data'));
    }

    public function test_recoleta_troca_a_janela_e_remove_codigo_que_saiu_do_full_sem_tocar_nos_ajustes(): void
    {
        $company = $this->empresaComToken();
        FullProduto::create(['company_id' => $company->id, 'inventory_id' => 'SAIU00001', 'titulo' => 'Saiu']);
        FullItem::create(['company_id' => $company->id, 'inventory_id' => 'SAIU00001', 'fora' => true]);
        FullVendaDiaria::create(['company_id' => $company->id, 'inventory_id' => 'SAIU00001', 'data' => '2026-08-01', 'unidades' => 5]);
        $this->fakeMl();

        app(FullColetaService::class)->coletar($company, 'agendada', CarbonImmutable::parse('2026-10-15 06:30:00'));

        $this->assertNull(FullProduto::where('inventory_id', 'SAIU00001')->first());
        $this->assertSame(0, FullVendaDiaria::where('inventory_id', 'SAIU00001')->count());
        $this->assertTrue(FullItem::where('inventory_id', 'SAIU00001')->first()->fora);
    }

    public function test_empresa_sem_anuncio_no_full_nao_ganha_coleta_e_perde_os_produtos_antigos(): void
    {
        $company = $this->empresaComToken('LOJA SEM FULL');
        FullProduto::create(['company_id' => $company->id, 'inventory_id' => 'VELHO0001', 'titulo' => 'x']);
        $this->fakeMl(['items/search' => ['results' => [], 'scroll_id' => null, 'paging' => ['total' => 0]]]);

        $this->assertNull(app(FullColetaService::class)->coletar($company));
        $this->assertSame(0, FullColeta::count());
        $this->assertSame(0, FullProduto::count());
    }

    public function test_erro_na_api_fica_registrado_na_coleta(): void
    {
        $company = $this->empresaComToken();
        Http::fake(fn (HttpRequest $r) => str_contains($r->url(), '/items/search')
            ? Http::response(['results' => ['MLB1'], 'scroll_id' => null])
            : Http::response(['message' => 'boom'], 500));

        try {
            app(FullColetaService::class)->coletar($company);
            $this->fail('a coleta deveria ter estourado');
        } catch (\RuntimeException) {
        }

        $coleta = FullColeta::first();
        $this->assertSame(FullColeta::STATUS_ERRO, $coleta->status);
        $this->assertStringContainsString('500', $coleta->erro);
    }

    public function test_coleta_abate_o_que_chegou_e_fecha_o_envio_completo(): void
    {
        $company = $this->empresaComToken();
        $envio = FullEnvio::create(['company_id' => $company->id, 'data_despacho' => '2026-10-10', 'status' => FullEnvio::STATUS_A_CAMINHO]);
        $envio->itens()->create(['inventory_id' => 'OKFW19104', 'quantidade' => 10]);
        $parcial = FullEnvio::create(['company_id' => $company->id, 'data_despacho' => '2026-10-12', 'status' => FullEnvio::STATUS_A_CAMINHO, 'inbound_ml' => '999']);
        $parcial->itens()->create(['inventory_id' => 'OKFW19104', 'quantidade' => 50]);

        $this->fakeMl(['operations' => ['results' => [
            ['date_created' => '2026-10-11T10:00:00Z', 'type' => 'INBOUND_RECEPTION', 'detail' => ['available_quantity' => 10], 'external_references' => [['type' => 'inbound_id', 'value' => '111']]],
            ['date_created' => '2026-10-13T10:00:00Z', 'type' => 'INBOUND_RECEPTION', 'detail' => ['available_quantity' => 20], 'external_references' => [['type' => 'inbound_id', 'value' => '999']]],
        ], 'paging' => ['total' => 2, 'scroll' => 'X']]]);

        app(FullColetaService::class)->coletar($company, 'agendada', CarbonImmutable::parse('2026-10-15 06:30:00'));

        $this->assertSame(FullEnvio::STATUS_RECEBIDO, $envio->fresh()->status);
        $this->assertSame(10, $envio->itens()->first()->recebido);
        $this->assertSame(FullEnvio::STATUS_A_CAMINHO, $parcial->fresh()->status);
        $this->assertSame(20, $parcial->itens()->first()->recebido);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'operations/search') && str_contains($r->url(), 'type=INBOUND_RECEPTION'));
    }

    public function test_comando_enfileira_uma_coleta_por_empresa_com_token_ativo(): void
    {
        Queue::fake();
        $this->empresaComToken('A');
        $this->empresaComToken('B');
        Company::factory()->create(['name' => 'Sem token', 'active' => true]);

        $this->artisan('full:coletar')->assertSuccessful();

        Queue::assertPushed(ColetarPlanejadorFullJob::class, 2);
    }

    // ═══ Tela ════════════════════════════════════════════════════════════════

    private function empresaColetada(): Company
    {
        $company = $this->empresaComToken();
        FullColeta::create(['company_id' => $company->id, 'status' => 'ok', 'origem' => 'agendada', 'iniciado_em' => now(), 'concluido_em' => now(), 'produtos' => 2, 'pedidos' => 10]);
        FullProduto::create(['company_id' => $company->id, 'inventory_id' => 'OKFW19104', 'sku' => 'RPCMD-W-59', 'titulo' => 'Casio W-59', 'aptas' => 207, 'anuncios' => ['MLB1']]);
        FullProduto::create(['company_id' => $company->id, 'inventory_id' => 'ZZZZ00001', 'sku' => 'VELHO', 'titulo' => 'Parado', 'aptas' => 0]);
        FullVendaDiaria::create(['company_id' => $company->id, 'inventory_id' => 'OKFW19104', 'data' => now()->subDay()->toDateString(), 'unidades' => 77, 'faturamento' => 7700]);

        return $company;
    }

    public function test_visitante_vai_para_o_login(): void
    {
        $this->get(route('planejador-full.index'))->assertRedirect(route('login'));
    }

    public function test_qualquer_logado_ve_a_lista_e_o_planejador_da_empresa(): void
    {
        $company = $this->empresaColetada();

        $this->actingAs($this->user)->get(route('planejador-full.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PlanejadorFull/Index')
                ->where('empresas.0.nome', 'RELOJOARIA WENUS')
                ->where('empresas.0.codigos', 2));

        $this->actingAs($this->user)->get(route('planejador-full.show', $company))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PlanejadorFull/Show')
                ->has('produtos', 1)   // o parado (sem estoque, entrada nem venda) fica fora
                ->where('produtos.0.cod', 'OKFW19104')
                ->where('produtos.0.vendas', 77)
                ->where('config.preparo', 2)
                ->where('temToken', true));
    }

    public function test_pendente_dos_envios_a_caminho_chega_na_tela(): void
    {
        $company = $this->empresaColetada();
        $envio = FullEnvio::create(['company_id' => $company->id, 'data_despacho' => now()->toDateString(), 'status' => 'a_caminho']);
        $envio->itens()->create(['inventory_id' => 'ZZZZ00001', 'quantidade' => 40, 'recebido' => 15]);

        $this->actingAs($this->user)->get(route('planejador-full.show', $company))
            ->assertInertia(fn (Assert $page) => $page
                ->has('produtos', 2)
                ->where('produtos.1.cod', 'ZZZZ00001')
                ->where('produtos.1.pendente', 25)
                ->where('envios.0.recebido', 15));
    }

    public function test_salva_ajustes_e_fornecedores_e_renomear_leva_os_skus_junto(): void
    {
        $company = $this->empresaColetada();
        FullSku::create(['company_id' => $company->id, 'sku' => 'RPCMD-W-59', 'fornecedor' => 'Casio']);

        $config = ['preparo' => 1, 'agenda' => 2, 'transito' => 3, 'recebimento' => 4, 'proximoDespacho' => '2026-10-20', 'intervaloEnvios' => 7,
            'segA' => 9, 'segB' => 6, 'segC' => 3, 'fatorSazonal' => 1.3, 'limiteExcesso' => 60, 'leadTimeFornecedor' => 20, 'intervaloCompras' => 15,
            'abcA' => 0.8, 'abcB' => 0.95, 'lixo' => 'x'];

        $this->actingAs($this->user)->putJson(route('planejador-full.config', $company), [
            'config' => $config,
            'fornecedores' => [['nome' => 'Casio Brasil', 'leadTime' => 25, 'ciclo' => null, 'observacoes' => 'contato']],
            'renomear' => ['de' => 'Casio', 'para' => 'Casio Brasil'],
        ])->assertOk();

        $salvo = FullConfiguracao::where('company_id', $company->id)->first();
        $this->assertSame(1.3, (float) $salvo->config['fatorSazonal']);
        $this->assertArrayNotHasKey('lixo', $salvo->config);
        $this->assertSame('Casio Brasil', $salvo->fornecedores[0]['nome']);
        $this->assertSame('Casio Brasil', FullSku::first()->fornecedor);
    }

    public function test_config_invalida_volta_422(): void
    {
        $company = $this->empresaColetada();

        $this->actingAs($this->user)->putJson(route('planejador-full.config', $company), [
            'config' => ['preparo' => -1, 'intervaloEnvios' => 0, 'fatorSazonal' => 0],
            'fornecedores' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['config.preparo', 'config.intervaloEnvios', 'config.fatorSazonal']);
    }

    public function test_salva_skus_e_itens_em_lote_e_apaga_item_sem_ajuste(): void
    {
        $company = $this->empresaColetada();

        $this->actingAs($this->user)->putJson(route('planejador-full.skus', $company), [
            'skus' => ['RPCMD-W-59' => ['galpao' => 120, 'multiplo' => 6], '#OKFW19104' => ['aberto' => 30, 'abertoData' => '2026-11-01']],
        ])->assertOk();

        $this->assertSame(120, FullSku::where('sku', 'RPCMD-W-59')->first()->galpao);
        $this->assertSame('2026-11-01', FullSku::where('sku', '#OKFW19104')->first()->aberto_data->toDateString());

        $this->actingAs($this->user)->putJson(route('planejador-full.itens', $company), ['itens' => ['OKFW19104' => ['qtdManual' => 50, 'ajuste' => 0.2]]])->assertOk();
        $this->assertSame(50, FullItem::first()->qtd_manual);

        $this->actingAs($this->user)->putJson(route('planejador-full.itens', $company), ['itens' => ['OKFW19104' => ['qtdManual' => null, 'ajuste' => 0]]])->assertOk();
        $this->assertSame(0, FullItem::count());
    }

    public function test_registra_envio_e_recusa_codigo_de_outra_empresa(): void
    {
        $company = $this->empresaColetada();

        $this->actingAs($this->user)->postJson(route('planejador-full.envios.store', $company), [
            'dataDespacho' => '2026-10-20', 'itens' => [['cod' => 'OUTR00001', 'quantidade' => 5]],
        ])->assertStatus(422);

        $this->actingAs($this->user)->postJson(route('planejador-full.envios.store', $company), [
            'dataDespacho' => '2026-10-20', 'inboundMl' => '75714964', 'itens' => [['cod' => 'OKFW19104', 'quantidade' => 300]],
        ])->assertOk()->assertJsonPath('pendentes.OKFW19104', 300)->assertJsonPath('envios.0.inboundMl', '75714964');

        $envio = FullEnvio::first();
        $this->assertSame($this->user->id, $envio->criado_por);

        $this->actingAs($this->user)->patchJson(route('planejador-full.envios.update', [$company, $envio]), ['status' => 'recebido'])
            ->assertOk()->assertJsonPath('pendentes', []);
        $this->assertNotNull($envio->fresh()->recebido_em);
    }

    public function test_envio_de_outra_empresa_da_404(): void
    {
        $company = $this->empresaColetada();
        $outra = Company::factory()->create();
        $envio = FullEnvio::create(['company_id' => $outra->id, 'data_despacho' => '2026-10-20']);

        $this->actingAs($this->user)->deleteJson(route('planejador-full.envios.destroy', [$company, $envio]))->assertNotFound();
        $this->assertNotNull($envio->fresh());
    }

    public function test_importa_entrada_pendente_da_planilha_de_estoque_do_ml(): void
    {
        Queue::fake();
        $company = $this->empresaColetada();

        $arquivo = $this->planilhaEstoque([
            ['OKFW19104', 50],
            ['ZZZZ00001', 0],
            ['NAOE00001', 99],   // não é desta empresa
        ]);

        $this->actingAs($this->user)->post(route('planejador-full.envios.planilha', $company), ['arquivo' => $arquivo], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('importados', 1)
            ->assertJsonPath('ignorados', 1)
            ->assertJsonPath('pendentes.OKFW19104', 50);

        $envio = FullEnvio::first();
        $this->assertSame(FullEnvio::ORIGEM_PLANILHA, $envio->origem);
        // "Atualizado em 15 de outubro às 08h45" — as chegadas contam só depois disso.
        $this->assertSame('2026-10-15 08:45', $envio->chegadas_desde->setTimezone(config('app.timezone'))->format('Y-m-d H:i'));
        Queue::assertPushed(ColetarPlanejadorFullJob::class);
    }

    public function test_importa_galpao_por_sku_sem_diferenciar_maiusculas(): void
    {
        $company = $this->empresaColetada();
        $csv = UploadedFile::fake()->createWithContent('galpao.csv', "SKU;Estoque\nrpcmd-w-59;35\nNAO-EXISTE;9\n");

        $this->actingAs($this->user)->post(route('planejador-full.galpao', $company), ['arquivo' => $csv], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('atualizados', 1)->assertJsonPath('ignorados', 1);

        $this->assertSame(35, FullSku::where('sku', 'RPCMD-W-59')->first()->galpao);
    }

    public function test_atualizar_agora_enfileira_na_fila_de_cliques(): void
    {
        Queue::fake();
        $company = $this->empresaColetada();

        $this->actingAs($this->user)->postJson(route('planejador-full.atualizar', $company))->assertOk()->assertJsonStructure(['mensagem']);

        Queue::assertPushedOn('high', ColetarPlanejadorFullJob::class);
    }

    public function test_exporta_xlsx_com_as_linhas_calculadas_pela_tela(): void
    {
        $company = $this->empresaColetada();

        $resp = $this->actingAs($this->user)->post(route('planejador-full.exportar', $company), [
            'tipo' => 'envio', 'colunas' => ['Código ML', 'Quantidade'], 'linhas' => [['OKFW19104', 30]], 'rodape' => ['Total 30'],
        ]);

        $resp->assertOk();
        $this->assertStringContainsString('envio-full-relojoaria-wenus', $resp->headers->get('content-disposition'));
        $this->assertStringStartsWith('PK', $resp->streamedContent());
    }

    public function test_serie_de_vendas_preenche_dias_sem_venda_com_zero(): void
    {
        $company = $this->empresaColetada();
        $base = now()->toDateString();

        $resp = $this->actingAs($this->user)->getJson(route('planejador-full.vendas', [$company, 'OKFW19104', 'base' => $base]))->assertOk();

        $this->assertCount(30, $resp->json('serie'));
        $this->assertSame(77, $resp->json('serie.29.unidades'));
        $this->assertSame(0, $resp->json('serie.0.unidades'));
    }

    /** Monta um "Relatório geral de estoque" mínimo, no formato do ML (cabeçalho em grupo). */
    private function planilhaEstoque(array $linhas): UploadedFile
    {
        $p = new Spreadsheet();
        $f = $p->getActiveSheet();
        $f->setTitle('Resumo');
        $f->setCellValue('A1', 'Atualizado em 15 de outubro às 08h45 h.');
        $f->setCellValue('B4', 'Código ML');
        $f->setCellValue('P4', 'Entrada pendente');
        $r = 6;
        foreach ($linhas as [$cod, $pend]) {
            $f->setCellValue("B{$r}", $cod);
            $f->setCellValue("P{$r}", $pend);
            $r++;
        }

        $caminho = tempnam(sys_get_temp_dir(), 'full') . '.xlsx';
        (new Xlsx($p))->save($caminho);

        return new UploadedFile($caminho, 'stock_general_full_teste.xlsx', null, null, true);
    }
}
