<?php

namespace Tests\Unit\Phase134;

use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlToken;
use App\Services\Mlb\Acervo\MlAcervoService;
use App\Services\Mlb\Acervo\SkusDoAnuncio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Quick 261010-rie — o SKU do anuncio no acervo ML.
 *
 * O usuario pediu, em 10/10/2026: "Quero poder buscar por SKU, Nome do
 * Anuncio ou MLB". Buscar por SKU nao era filtro faltando: `ml_acervo_itens`
 * nao tinha a coluna e o multiget da camada BARATA nao pedia
 * `seller_custom_field`. Este arquivo trava as tres coisas que mais podem
 * quebrar em silencio:
 *
 *   (a) schema/model — a coluna existe, o cast devolve array e `NULL`
 *       continua `NULL` (D-RIE-02: "ainda nao coletado" e estado de primeira
 *       classe, nunca `[]` por acidente);
 *   (b) o extrator — `SELLER_SKU` primeiro, `seller_custom_field` como
 *       fallback, e em anuncio com variacoes TODOS os SKUs distintos
 *       (D-RIE-01/D-RIE-05), sem perder nenhum e sem inventar nenhum;
 *   (c) a coleta — `seller_custom_field` pedido ao multiget, `skus` dentro do
 *       3o argumento do `upsert()` (sem isso LINHA EXISTENTE nunca receberia
 *       SKU, e o "sem backfill" do D-RIE-04 cairia junto) e nenhuma coluna da
 *       camada CARA entrando de carona na lista (T-134-26).
 *
 * Estrategia: RefreshDatabase (SQLite in-memory) + Http::fake so dos
 * endpoints usados — nunca ML real. Nunca um `Http::fake()` vazio: ele
 * esconderia chamada nao prevista (aviso do setUp de ColetaAcervoTest).
 *
 * @group phase134
 */
class SkuDoAcervoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // App token pre-semeado -> MlCatalogoMetaService nao dispara o POST de
        // client_credentials (mesmo padrao de ColetaAcervoTest).
        Cache::put('ml_app_token_coleta', 'fake-app-token', now()->addHour());
    }

    // ═══ Schema e model ═════════════════════════════════════════════════════

    /** @test T1 */
    public function tabela_do_acervo_tem_a_coluna_skus(): void
    {
        $this->assertTrue(
            Schema::hasColumn('ml_acervo_itens', 'skus'),
            'sem a coluna `skus` a busca por SKU nao tem onde morar'
        );
    }

    /** @test T2 */
    public function cast_devolve_array_e_null_continua_null(): void
    {
        $company = Company::factory()->create();

        $comSku = $this->criarItem($company, ['ml_item_id' => 'MLB-COM-SKU', 'skus' => ['A1', 'B2']]);
        $semSku = $this->criarItem($company, ['ml_item_id' => 'MLB-SEM-SKU']);

        $this->assertSame(['A1', 'B2'], $comSku->fresh()->skus, 'cast array na leitura');
        $this->assertNull(
            $semSku->fresh()->skus,
            'D-RIE-02: NULL e "ainda nao coletado" e NUNCA pode virar [] por acidente do cast'
        );
    }

    /** @test T3 */
    public function migration_nao_usa_after_nem_json(): void
    {
        $caminho = dirname(__DIR__, 3) . '/database/migrations/2026_10_10_170000_add_skus_to_ml_acervo_itens.php';

        $this->assertFileExists($caminho, 'a migration do `skus` tem de existir com o nome contratado');

        $fonte = file_get_contents($caminho);

        // D-RIE-03: sem `after()` (no MariaDB 10.4 local ele forca rebuild da
        // tabela) e sem `json()` (no MariaDB vira longtext + CHECK json_valid,
        // no MySQL 8 de producao vira tipo nativo — nao e portavel).
        $this->assertDoesNotMatchRegularExpression('/->after\(/', $fonte, 'D-RIE-03: a coluna entra no FIM da tabela, sem after()');
        $this->assertDoesNotMatchRegularExpression('/->json\(/', $fonte, 'D-RIE-03: longText nullable, nunca json()');
        $this->assertDoesNotMatchRegularExpression('/->index\(/', $fonte, 'D-RIE-06: nenhum indice novo');
        $this->assertStringContainsString('hasColumn', $fonte, 'migration idempotente');
    }

    // ═══ Extrator ═══════════════════════════════════════════════════════════

    /** @test T4 */
    public function extrai_sku_do_atributo_seller_sku(): void
    {
        $skus = SkusDoAnuncio::extrair([
            'id'         => 'MLB1',
            'attributes' => [['id' => 'BRAND', 'value_name' => 'ACME'], ['id' => 'SELLER_SKU', 'value_name' => 'ABC-1']],
        ]);

        $this->assertSame(['ABC-1'], $skus);
    }

    /** @test T5 */
    public function extrai_sku_do_fallback_seller_custom_field(): void
    {
        $skus = SkusDoAnuncio::extrair([
            'id'                  => 'MLB1',
            'attributes'          => [['id' => 'BRAND', 'value_name' => 'ACME']],
            'seller_custom_field' => 'XYZ-9',
        ]);

        $this->assertSame(['XYZ-9'], $skus);
    }

    /** @test T6 */
    public function atributo_vence_o_seller_custom_field(): void
    {
        $skus = SkusDoAnuncio::extrair([
            'id'                  => 'MLB1',
            'attributes'          => [['id' => 'SELLER_SKU', 'value_name' => 'DO-ATRIBUTO']],
            'seller_custom_field' => 'DO-CAMPO-LIVRE',
        ]);

        $this->assertSame(['DO-ATRIBUTO'], $skus, 'mesma precedencia de AnunciosMercadoLivreService::skuDoAnuncio()');
    }

    /** @test T7 */
    public function variacoes_com_skus_diferentes_entram_todas(): void
    {
        $skus = SkusDoAnuncio::extrair([
            'id'         => 'MLB1',
            'attributes' => [],
            'variations' => [
                ['id' => 1, 'attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'V1']]],
                ['id' => 2, 'seller_custom_field' => 'V2'],
                ['id' => 3, 'attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'V3']]],
            ],
        ]);

        $this->assertSame(
            ['V1', 'V2', 'V3'],
            $skus,
            'D-RIE-01: nenhum SKU de variacao pode se perder — o proposito e ACHAR o anuncio por qualquer um deles'
        );
    }

    /** @test T8 */
    public function variacoes_com_o_mesmo_sku_viram_uma_entrada(): void
    {
        $skus = SkusDoAnuncio::extrair([
            'id'         => 'MLB1',
            'variations' => [
                ['id' => 1, 'seller_custom_field' => 'IGUAL'],
                ['id' => 2, 'seller_custom_field' => 'IGUAL'],
                ['id' => 3, 'attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'IGUAL']]],
            ],
        ]);

        $this->assertSame(['IGUAL'], $skus, 'distintos, nunca repetidos');
    }

    /** @test T9 */
    public function sku_do_pai_vem_primeiro_e_as_variacoes_depois(): void
    {
        $skus = SkusDoAnuncio::extrair([
            'id'         => 'MLB1',
            'attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'PAI-1']],
            'variations' => [
                ['id' => 1, 'seller_custom_field' => 'VAR-A'],
                ['id' => 2, 'seller_custom_field' => 'VAR-B'],
            ],
        ]);

        $this->assertSame(['PAI-1', 'VAR-A', 'VAR-B'], $skus, 'a tela mostra o primeiro — tem de ser o do pai quando existe');
    }

    /** @test T10 */
    public function sem_sku_em_lugar_nenhum_devolve_lista_vazia(): void
    {
        $this->assertSame([], SkusDoAnuncio::extrair(['id' => 'MLB1', 'attributes' => [], 'variations' => []]));
        $this->assertSame([], SkusDoAnuncio::extrair([]), 'item sem nenhuma chave tambem nao pode explodir');
    }

    /** @test T11 */
    public function formato_inesperado_e_branco_sao_descartados_sem_erro(): void
    {
        $skus = SkusDoAnuncio::extrair([
            'id'                  => 'MLB1',
            'attributes'          => [['id' => 'SELLER_SKU', 'value_name' => ['nao' => 'e string']]],
            'seller_custom_field' => '   ',
            'variations'          => [
                ['id' => 1, 'seller_custom_field' => ''],
                ['id' => 2, 'attributes' => [['id' => 'SELLER_SKU', 'value_name' => ['a', 'b']]]],
                ['id' => 3, 'seller_custom_field' => '  OK-3  '],
                ['id' => 4, 'attributes' => 'isto nao e uma lista'],
            ],
        ]);

        $this->assertSame(['OK-3'], $skus, 'so texto util entra, e com trim — sem Array to string conversion');
    }

    // ═══ Coleta (camada BARATA) ═════════════════════════════════════════════

    /** @test T12 */
    public function multiget_pede_seller_custom_field(): void
    {
        $atributos = $this->constantePrivada('ATRIBUTOS_MULTIGET');

        $this->assertStringContainsString(
            'seller_custom_field',
            $atributos,
            'sem isso o fallback do SKU nunca chega — e vem de graca no MESMO payload'
        );
        $this->assertStringContainsString('attributes', $atributos, 'SELLER_SKU sai de attributes');
        $this->assertStringContainsString('variations', $atributos, 'o SKU por variacao sai de variations');
    }

    /** @test T13 */
    public function skus_entrou_no_terceiro_argumento_do_upsert_e_nada_da_camada_cara(): void
    {
        $colunas = $this->constantePrivada('COLUNAS_CAMADA_BARATA');

        $this->assertContains(
            'skus',
            $colunas,
            'armadilha no 1: sem `skus` no 3o argumento, LINHA EXISTENTE nunca recebe SKU — em silencio'
        );

        // T-134-26 re-travado agora que a lista mudou: nada da camada CARA
        // pode entrar de carona, senao a rotacao do D-23 e apagada todo dia.
        foreach (['buybox_status', 'visitas_30d', 'performance_score', 'performance_level', 'performance_acoes', 'detalhe_coletado_em'] as $daCamadaCara) {
            $this->assertNotContains($daCamadaCara, $colunas, "{$daCamadaCara} e da camada CARA e nao pode entrar no upsert da barata");
        }
    }

    /** @test T14 */
    public function coleta_grava_o_sku_do_atributo_na_linha(): void
    {
        $company = $this->criarCompanyComToken();

        $this->fakeMultiget([
            'id'                 => 'MLB1',
            'title'              => 'Produto com SKU',
            'status'             => 'active',
            'available_quantity' => 5,
            'sold_quantity'      => 1,
            'attributes'         => [['id' => 'SELLER_SKU', 'value_name' => 'ABC-1']],
        ]);

        app(MlAcervoService::class)->coletarItens($company, ['MLB1']);

        $linha = MlAcervoItem::where('company_id', $company->id)->where('ml_item_id', 'MLB1')->first();

        $this->assertNotNull($linha);
        $this->assertSame(['ABC-1'], $linha->skus);

        // E a chamada de fato pediu o campo novo.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'seller_custom_field'));
    }

    /** @test T15 */
    public function linha_pre_existente_sem_sku_recebe_o_sku_na_coleta_seguinte(): void
    {
        $company = $this->criarCompanyComToken();

        $this->criarItem($company, ['ml_item_id' => 'MLB1', 'title' => 'Linha antiga']);

        $this->assertNull(
            MlAcervoItem::where('ml_item_id', 'MLB1')->value('skus'),
            'ponto de partida: linha gravada antes desta mudanca'
        );

        $this->fakeMultiget([
            'id'                  => 'MLB1',
            'title'               => 'Linha antiga',
            'status'              => 'active',
            'available_quantity'  => 5,
            'attributes'          => [],
            'seller_custom_field' => 'DEPOIS-1',
        ]);

        app(MlAcervoService::class)->coletarItens($company, ['MLB1']);

        $this->assertSame(
            ['DEPOIS-1'],
            MlAcervoItem::where('company_id', $company->id)->where('ml_item_id', 'MLB1')->first()->skus,
            'D-RIE-04: e isto que torna o "sem backfill" honesto — a varredura diaria atualiza a linha existente'
        );
    }

    /** @test T16 */
    public function item_coletado_sem_sku_grava_lista_vazia_e_nao_null(): void
    {
        $company = $this->criarCompanyComToken();

        $this->fakeMultiget([
            'id'                 => 'MLB1',
            'title'              => 'Produto sem SKU',
            'status'             => 'active',
            'available_quantity' => 5,
            'attributes'         => [],
        ]);

        app(MlAcervoService::class)->coletarItens($company, ['MLB1']);

        $linha = MlAcervoItem::where('company_id', $company->id)->where('ml_item_id', 'MLB1')->first();

        $this->assertSame(
            [],
            $linha->skus,
            'D-RIE-02: "coletado e o anuncio nao tem SKU" e [] — diferente de NULL, que e "ainda nao coletado"'
        );
    }

    /** @test T17 */
    public function coleta_do_sku_nao_apaga_o_que_a_camada_cara_ja_coletou(): void
    {
        $company = $this->criarCompanyComToken();
        $ontem   = now()->subDay();

        $this->criarItem($company, [
            'ml_item_id'          => 'MLB1',
            'buybox_status'       => 'winning',
            'visitas_30d'         => 137,
            'detalhe_coletado_em' => $ontem,
        ]);

        $this->fakeMultiget([
            'id'                 => 'MLB1',
            'title'              => 'Produto com SKU',
            'status'             => 'active',
            'available_quantity' => 5,
            'attributes'         => [['id' => 'SELLER_SKU', 'value_name' => 'ABC-1']],
        ]);

        app(MlAcervoService::class)->coletarItens($company, ['MLB1']);

        $linha = MlAcervoItem::where('company_id', $company->id)->where('ml_item_id', 'MLB1')->first();

        $this->assertSame(['ABC-1'], $linha->skus, 'o SKU entrou');
        $this->assertSame('winning', $linha->buybox_status, 'T-134-26: buybox_status sobrevive');
        $this->assertSame(137, $linha->visitas_30d, 'T-134-26: visitas_30d sobrevive');
        $this->assertTrue($linha->detalhe_coletado_em->isSameDay($ontem), 'T-134-26: detalhe_coletado_em sobrevive');
    }

    // ═══ Helpers ════════════════════════════════════════════════════════════

    /** Le uma constante PRIVADA de MlAcervoService — o gate e sobre o contrato, nao sobre a saida. */
    private function constantePrivada(string $nome): mixed
    {
        return (new \ReflectionClass(MlAcervoService::class))->getConstant($nome);
    }

    /** Multiget de UM item, no envelope [{code, body}] que a API devolve. */
    private function fakeMultiget(array $body): void
    {
        Http::fake([
            '*/items?ids=*' => Http::response([['code' => 200, 'body' => $body]], 200),
        ]);
    }

    private function criarCompanyComToken(): Company
    {
        $company = Company::factory()->create();

        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => (string) random_int(100000000, 999999999),
            'access_token'      => 'fake-access-token',
            'refresh_token'     => 'fake-refresh-token',
            'token_type'        => 'bearer',
            'scope'             => 'read write offline_access',
            'expires_at'        => now()->addDays(6),
            'last_refreshed_at' => now(),
            'status'            => 'active',
            'connected_at'      => now(),
        ]);

        return $company;
    }

    private function criarItem(Company $company, array $overrides = []): MlAcervoItem
    {
        return MlAcervoItem::create(array_merge([
            'company_id'         => $company->id,
            'ml_item_id'         => 'MLB' . random_int(1000000000, 9999999999),
            'title'              => 'Produto de Teste',
            'status'             => 'active',
            'available_quantity' => 10,
            'sold_quantity'      => 0,
            'nota_ecf'           => 60,
            'motivos'            => [],
            'severidade'         => MlAcervoItem::SEVERIDADE_SAUDAVEL,
            'origem'             => MlAcervoItem::ORIGEM_LEGADO,
            'coletado_em'        => now(),
        ], $overrides));
    }
}
