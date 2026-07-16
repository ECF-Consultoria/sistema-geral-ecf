<?php

namespace Tests\Feature\Phase82;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 82 Plan 04 — Feature tests da grade de anúncio em massa (endpoints do Plan 01).
 *
 * Blinda o contrato dos 3 endpoints do backend da grade em massa, seguindo o padrão
 * da Phase 80 (RefreshDatabase + SQLite in-memory + Http::fake — NUNCA ML real):
 *
 *   Tarefa 1 (SHEET-01) — escopo + 404:
 *     (a) admin acessa GET /massa/{company} → 200
 *     (b) consultor → 403 (middleware role:admin)
 *     (c) empresa SEM MlToken → 404 na rota massa e na rota produtos
 *     (d) admin acessa GET /massa/{company}/produtos → 200 { ok:true }
 *
 *   Tarefa 2 (SHEET-02, SHEET-03) — contrato de colunasCategoria:
 *     (e) obrigatorios contém só os obrigatórios reais (sem allow_variations, sem *GRID*)
 *     (f) caminho = breadcrumb de path_from_root (nomes, não MLBxxxx)
 *     (g) max_title_length reflete o settings simulado
 *     (h) Http::fake garante que nenhum ML real é chamado
 *
 *   Tarefa 3 (SHEET-01) — produtosDoClienteMassa lê mlb_implementacoes.dados:
 *     (i) produtos reflete os itens da planilha do cliente
 *     (j) produto com custo>0 tem tem_preco=true e preco_anunciado_c numérico
 *     (k) linha totalmente em branco é descartada
 *
 * Estratégia: RefreshDatabase (SQLite in-memory). App token pré-semeado no cache p/
 * evitar o POST de client_credentials; Http::fake para /categories/{id} e /attributes.
 * Nunca migrate completo (gotcha NPS no MariaDB local — em SQLite in-memory não roda).
 *
 * @group phase82
 */
class GradeMassaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // App token pré-semeado → MlColetaService::getAppToken() serve do cache e NÃO
        // dispara o POST de client_credentials. Cache::flush() no fim de cada teste
        // (via RefreshDatabase array driver) evita vazamento de metadados entre testes.
        Cache::put('ml_app_token_coleta', 'fake-app-token', now()->addHour());
    }

    // ─── Helpers de fixture (espelham a Phase 80) ───

    /** Cria usuário admin com e-mail verificado. */
    private function criarAdmin(): User
    {
        return User::factory()->create([
            'role'              => 'admin',
            'email_verified_at' => now(),
        ]);
    }

    /** Cria usuário consultor (sem role:admin). */
    private function criarConsultor(): User
    {
        return User::factory()->create([
            'role'              => 'consultor',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Cria Company + MlToken ativo + MlbEmpresa (responsavel_id=admin).
     * Retorna [$company, $empresa, $admin].
     */
    private function criarFixture(): array
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create();

        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => '1555596317',
            'access_token'      => 'fake-access-token',
            'refresh_token'     => 'fake-refresh-token',
            'token_type'        => 'bearer',
            'scope'             => 'read write offline_access',
            'expires_at'        => now()->addDays(6),
            'last_refreshed_at' => now(),
            'status'            => 'active',
            'connected_at'      => now(),
        ]);

        $empresa = MlbEmpresa::create([
            'nome'           => 'Empresa Massa Teste ' . $company->id,
            'tipo'           => 'ASSESSORIA',
            'company_id'     => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        return [$company, $empresa, $admin];
    }

    /**
     * Cria Company SEM MlToken (empresa não conectada ao ML).
     * Retorna [$company, $admin].
     */
    private function criarFixtureSemToken(): array
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create();

        return [$company, $admin];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Tarefa 1 — escopo (403 consultor) + 404 (empresa sem ML) — SHEET-01
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function massa_responde_200_para_admin(): void
    {
        // (a) admin com empresa conectada acessa a grade → 200
        [$company, , $admin] = $this->criarFixture();

        $this->actingAs($admin)
            ->get("/mlb/anuncios/massa/{$company->id}")
            ->assertOk();
    }

    /** @test */
    public function massa_responde_403_para_consultor_sem_role_admin(): void
    {
        // (b) middleware role:admin bloqueia consultor antes do controller
        [$company] = $this->criarFixture();
        $consultor = $this->criarConsultor();

        $this->actingAs($consultor)
            ->get("/mlb/anuncios/massa/{$company->id}")
            ->assertForbidden();
    }

    /** @test */
    public function massa_responde_404_quando_empresa_sem_conta_ml(): void
    {
        // (c) empresa sem MlToken → 404 (abort_unless mlToken)
        [$company, $admin] = $this->criarFixtureSemToken();

        $this->actingAs($admin)
            ->get("/mlb/anuncios/massa/{$company->id}")
            ->assertNotFound();
    }

    /** @test */
    public function produtos_responde_200_com_ok_true_para_admin(): void
    {
        // (d) admin acessa a lista de produtos do cliente → 200 { ok:true }
        [$company, , $admin] = $this->criarFixture();

        $this->actingAs($admin)
            ->getJson("/mlb/anuncios/massa/{$company->id}/produtos")
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    /** @test */
    public function produtos_responde_404_quando_empresa_sem_conta_ml(): void
    {
        // (c) empresa sem MlToken → 404 também na rota de produtos
        [$company, $admin] = $this->criarFixtureSemToken();

        $this->actingAs($admin)
            ->getJson("/mlb/anuncios/massa/{$company->id}/produtos")
            ->assertNotFound();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Tarefa 2 — contrato de colunasCategoria (só obrigatórios + breadcrumb)
    //            SHEET-02 / SHEET-03
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Http::fake dos 2 endpoints que o MlCatalogoMetaService consome:
     *   - /categories/{id}            → path_from_root (breadcrumb) + settings.max_title_length
     *   - /categories/{id}/attributes → mix de atributos p/ provar o filtro de obrigatórios
     *
     * O atributo com allow_variations=true e o SIZE_GRID_ID DEVEM ser excluídos dos
     * obrigatorios (idêntico ao filtro do wizard AnunciarML.jsx:1102).
     */
    private function fakeMetaDaCategoria(): void
    {
        Http::fake([
            // /attributes precisa vir ANTES do glob genérico (Http avalia na ordem)
            'api.mercadolibre.com/categories/*/attributes' => Http::response([
                // Obrigatório simples → DEVE entrar em obrigatorios
                [
                    'id'         => 'BRAND',
                    'name'       => 'Marca',
                    'value_type' => 'string',
                    'tags'       => ['required' => true],
                ],
                // Obrigatório + allow_variations → ENTRA (grade = anúncio simples; o ML
                // exige na lista `attributes` — erro 400 real de 2026-07-15)
                [
                    'id'         => 'COLOR',
                    'name'       => 'Cor',
                    'value_type' => 'list',
                    'tags'       => ['required' => true, 'allow_variations' => true],
                    'values'     => [['id' => '1', 'name' => 'Preto']],
                ],
                // Opcional puro → característica secundária (o ML pede e pesa na busca)
                [
                    'id'         => 'MATERIAL',
                    'name'       => 'Material',
                    'value_type' => 'string',
                    'tags'       => [],
                ],
                // Opcional mas OCULTO → não vira coluna (espelha o filtro do wizard)
                [
                    'id'         => 'ATTR_OCULTO',
                    'name'       => 'Oculto',
                    'value_type' => 'string',
                    'tags'       => ['hidden' => true],
                ],
                // Grade de moda (SIZE_GRID_ID) obrigatória → DEVE ser EXCLUÍDA
                [
                    'id'         => 'SIZE_GRID_ID',
                    'name'       => 'Grade de tamanho',
                    'value_type' => 'grid_id',
                    'tags'       => ['required' => true],
                ],
                // Opcional (required ausente) → NÃO entra em obrigatorios
                [
                    'id'         => 'GTIN',
                    'name'       => 'Código universal',
                    'value_type' => 'string',
                    'tags'       => [],
                ],
            ], 200),

            // /categories/{id} (sem /attributes) — breadcrumb + settings
            'api.mercadolibre.com/categories/*' => Http::response([
                'id'             => 'MLB123456',
                'name'           => 'Cadeiras para Escritório',
                'path_from_root' => [
                    ['id' => 'MLB1574', 'name' => 'Casa, Móveis e Decoração'],
                    ['id' => 'MLB1000', 'name' => 'Móveis para Escritório'],
                    ['id' => 'MLB123456', 'name' => 'Cadeiras para Escritório'],
                ],
                'settings'       => ['max_title_length' => 70],
            ], 200),
        ]);
    }

    /** @test */
    public function colunas_devolve_apenas_os_obrigatorios_reais_da_categoria(): void
    {
        // SHEET-02 + COL-85-2: TODOS os obrigatórios da categoria ativa viram coluna
        // (inclusive os allow_variations), menos os *GRID*.
        //
        // MUDANÇA DELIBERADA (2026-07-15): este teste exigia `assertNotContains('COLOR')`,
        // codificando a suposição de que atributo allow_variations nunca entra na grade
        // (espelhando o wizard, onde ele é preenchido dentro de cada variação).
        // O Mercado Livre refutou essa suposição numa publicação em massa real:
        //   400 item.attributes.missing_required — "The attributes [COLOR, SIZE] are
        //   required for category MLB108791 and channel marketplace"
        // A grade é 1 linha = 1 anúncio SIMPLES (sem variação), então esses atributos
        // precisam de coluna e vão na lista `attributes` do item — que é a saída que o
        // próprio erro aponta ("present in the attributes list OR in variation's
        // attribute_combination"). Sem coluna, o publicador não tem onde preenchê-los
        // e o lote inteiro é recusado.
        $this->fakeMetaDaCategoria();
        [, , $admin] = $this->criarFixture();

        $response = $this->actingAs($admin)
            ->getJson('/mlb/anuncios/massa/meta/MLB123456/colunas')
            ->assertOk();

        $ids = collect($response->json('obrigatorios'))->pluck('id')->all();

        // BRAND (obrigatório simples) presente
        $this->assertContains('BRAND', $ids, 'BRAND deve estar nos obrigatorios');
        // COLOR (obrigatório + allow_variations) AGORA ENTRA — sem ele o ML recusa (400)
        $this->assertContains('COLOR', $ids, 'COLOR e obrigatorio na categoria: precisa de coluna');
        // SIZE_GRID_ID EXCLUÍDO — grade de moda não é valor que se digita numa célula
        $this->assertNotContains('SIZE_GRID_ID', $ids, 'SIZE_GRID_ID não deve entrar');
        // GTIN (opcional) não é obrigatório
        $this->assertNotContains('GTIN', $ids, 'GTIN (opcional) não deve entrar nos obrigatorios');
    }

    /** @test */
    public function colunas_devolve_as_caracteristicas_secundarias_da_categoria(): void
    {
        // Paridade com o wizard: o ML pede as características secundárias (atributos
        // opcionais) no anúncio dele e elas pesam na qualidade/busca. A grade em massa
        // não as recebia — quem publica em lote não tinha como preenchê-las.
        // Filtro espelha AnunciarML.jsx:1258 (fora: required, variação, oculto,
        // read-only, GRID, e os que têm campo próprio: GTIN/SKU/CATALOG_PRODUCT_ID).
        $this->fakeMetaDaCategoria();
        [, , $admin] = $this->criarFixture();

        $response = $this->actingAs($admin)
            ->getJson('/mlb/anuncios/massa/meta/MLB123456/colunas')
            ->assertOk();

        $opcionais = collect($response->json('opcionais'))->pluck('id')->all();

        $this->assertContains('MATERIAL', $opcionais, 'atributo opcional deve virar caracteristica secundaria');
        // Não se repetem nos obrigatórios (senão a grade teria coluna duplicada)
        $this->assertNotContains('BRAND', $opcionais, 'obrigatorio nao pode aparecer tambem como secundario');
        $this->assertNotContains('COLOR', $opcionais, 'obrigatorio nao pode aparecer tambem como secundario');
        // Oculto/GRID/GTIN ficam fora (GTIN tem coluna própria na grade)
        $this->assertNotContains('ATTR_OCULTO', $opcionais, 'atributo hidden nao vira coluna');
        $this->assertNotContains('SIZE_GRID_ID', $opcionais, 'grade de moda nao vira coluna');
        $this->assertNotContains('GTIN', $opcionais, 'GTIN ja tem coluna base propria');
    }

    /** @test */
    public function colunas_devolve_o_breadcrumb_de_path_from_root(): void
    {
        // SHEET-03: caminho = nomes de path_from_root (resolve a queixa do MLBxxxx confuso)
        $this->fakeMetaDaCategoria();
        [, , $admin] = $this->criarFixture();

        $this->actingAs($admin)
            ->getJson('/mlb/anuncios/massa/meta/MLB123456/colunas')
            ->assertOk()
            ->assertJsonPath('caminho', [
                'Casa, Móveis e Decoração',
                'Móveis para Escritório',
                'Cadeiras para Escritório',
            ])
            ->assertJsonPath('max_title_length', 70);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Tarefa 3 — produtosDoClienteMassa lê mlb_implementacoes.dados — SHEET-01
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Cria fixture com MlbImplementacao vinculada, contendo dois produtos:
     *   - COMPLETO (nome+sku+dimensões+precificação com custo>0)
     *   - MÍNIMO   (só nome+sku, sem preço)
     *   - EM BRANCO (sem nome e sem sku → deve ser descartado)
     */
    private function criarFixtureComProdutos(): array
    {
        [$company, $empresa, $admin] = $this->criarFixture();

        MlbImplementacao::create([
            'empresa_id' => $empresa->id,
            'token'      => 'tok-massa-' . $empresa->id, // coluna NOT NULL (link público)
            'dados'      => [
                'itens' => [
                    'planilha_produtos' => [
                        'produtos' => [
                            [
                                'produto'      => 'Cadeira Gamer',
                                'sku'          => 'CAD-001',
                                'altura'       => '120',
                                'largura'      => '60',
                                'profundidade' => '60',
                                'peso_kg'      => '18',
                                'estoque'      => '10',
                                'descricao'    => 'Cadeira ergonômica',
                            ],
                            [
                                'produto' => 'Mesa Simples',
                                'sku'     => 'MES-002',
                            ],
                            // Linha totalmente em branco → montarProdutosDoCliente descarta
                            [
                                'produto' => '',
                                'sku'     => '',
                            ],
                        ],
                    ],
                    'precificacao' => [
                        'classico'  => ['comissao' => 0.115, 'imposto' => 0.19],
                        'premium'   => ['comissao' => 0.165, 'imposto' => 0.19],
                        'acrescimo' => 0.20,
                        'produtos'  => [
                            // Custo>0 só para o produto completo → tem_preco=true
                            ['sku' => 'CAD-001', 'custo' => 500, 'frete_classico' => 40, 'frete_premium' => 50],
                            // Mesa sem custo → tem_preco=false
                            ['sku' => 'MES-002', 'custo' => 0, 'frete_classico' => 0, 'frete_premium' => 0],
                        ],
                    ],
                ],
            ],
        ]);

        return [$company, $admin];
    }

    /** @test */
    public function produtos_reflete_os_itens_da_planilha_do_cliente(): void
    {
        // SHEET-01: a lista vem de mlb_implementacoes.dados via montarProdutosDoCliente
        [$company, $admin] = $this->criarFixtureComProdutos();

        $response = $this->actingAs($admin)
            ->getJson("/mlb/anuncios/massa/{$company->id}/produtos")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $produtos = $response->json('produtos');
        $skus     = collect($produtos)->pluck('sku')->all();

        // Os dois SKUs válidos aparecem; a linha em branco foi descartada (2 itens, não 3)
        $this->assertCount(2, $produtos, 'Linha totalmente em branco deve ser descartada');
        $this->assertContains('CAD-001', $skus);
        $this->assertContains('MES-002', $skus);
    }

    /** @test */
    public function produto_com_custo_positivo_tem_preco_e_preco_anunciado(): void
    {
        // SHEET-01: produto com custo>0 → tem_preco=true e preco_anunciado_c numérico
        [$company, $admin] = $this->criarFixtureComProdutos();

        $response = $this->actingAs($admin)
            ->getJson("/mlb/anuncios/massa/{$company->id}/produtos")
            ->assertOk();

        $produtos = collect($response->json('produtos'));

        $completo = $produtos->firstWhere('sku', 'CAD-001');
        $this->assertTrue($completo['tem_preco'], 'Produto com custo>0 deve ter tem_preco=true');
        $this->assertIsNumeric($completo['preco_anunciado_c'], 'preco_anunciado_c deve ser numérico');
        $this->assertGreaterThan(0, $completo['preco_anunciado_c']);

        $semPreco = $produtos->firstWhere('sku', 'MES-002');
        $this->assertFalse($semPreco['tem_preco'], 'Produto sem custo deve ter tem_preco=false');
    }
}
