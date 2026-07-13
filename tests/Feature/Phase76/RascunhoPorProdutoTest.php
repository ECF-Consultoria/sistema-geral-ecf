<?php

namespace Tests\Feature\Phase76;

use App\Models\Company;
use App\Models\MlAnuncioRascunho;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 76 Plan 01 — rascunhoPorProduto() e wizard() estendido.
 *
 * A rota usa route model binding de Company:
 *   POST /mlb/anuncios/wizard/{company}/rascunho-por-produto
 *   GET  /mlb/anuncios/wizard/{company}
 *
 * O {company} é o id da Company (não da MlbEmpresa). A company DEVE ter MlToken
 * para que o controller não retorne 404 de imediato.
 * A MlbEmpresa ligada (via company_id) fornece os produtos via MlbImplementacao.
 *
 * Usa SQLite in-memory com RefreshDatabase.
 *
 * @group phase76
 */
class RascunhoPorProdutoTest extends TestCase
{
    use RefreshDatabase;

    // ─── Dados fixos de seed ───

    /**
     * Shape de dados fixos com 2 produtos:
     * - Produto 1 (SKU01): com preço, com dimensões
     * - Produto 2 (SKU02): sem preço (custo 0), com dimensões
     */
    private function dadosFixos(): array
    {
        return [
            'itens' => [
                'planilha_produtos' => [
                    'produtos' => [
                        [
                            'sku'            => 'SKU01',
                            'produto'        => 'Cadeira Escritório',
                            'curva'          => 'A',
                            'altura'         => '95',
                            'largura'        => '55',
                            'profundidade'   => '55',
                            'peso_kg'        => '2.5',
                            'estoque'        => '8',
                            'especificacoes' => 'Giratória, com rodinhas',
                            'descricao'      => 'Cadeira ergonômica para escritório',
                        ],
                        [
                            'sku'            => 'SKU02',
                            'produto'        => 'Mesa Gamer',
                            'curva'          => 'B',
                            'altura'         => '75',
                            'largura'        => '140',
                            'profundidade'   => '60',
                            'peso_kg'        => '12',
                            'estoque'        => '3',
                            'especificacoes' => 'Com suporte para monitor',
                            'descricao'      => 'Mesa ideal para gamers',
                        ],
                    ],
                ],
                'precificacao' => [
                    'classico'            => ['comissao' => 0.115, 'imposto' => 0.19],
                    'premium'             => ['comissao' => 0.165, 'imposto' => 0.19],
                    'margem_contribuicao' => 0,
                    'lucro_liquido'       => 0,
                    'acrescimo'           => 0.20,
                    'produtos'            => [
                        [
                            'sku'             => 'SKU01',
                            'custo'           => '200',
                            'frete_classico'  => '30',
                            'frete_premium'   => '40',
                        ],
                        // SKU02 sem entrada de preço → custo = 0
                    ],
                ],
            ],
        ];
    }

    // ─── Helpers de seed ───

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function criarPublicador(): User
    {
        return User::factory()->create(['role' => 'consultor']);
    }

    /**
     * Cria uma Company com MlToken + MlbEmpresa ligada (company_id=company.id) +
     * MlbImplementacao com dados de produtos.
     *
     * O wizard e o rascunhoPorProduto usam {company} → company.id na rota.
     * A MlbEmpresa é o vínculo opcional que fornece os dados do cliente (planilha).
     *
     * @return array{company: Company, mlbEmpresa: MlbEmpresa}
     */
    private function criarCompanyComImplementacao(?array $dados = null): array
    {
        $company = Company::factory()->create();

        // MlToken obrigatório: o controller aborta 404 se ml_token for null
        MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => 'USER_' . uniqid(),
            'access_token'  => 'token_test',
            'refresh_token' => 'refresh_test',
            'status'        => 'active',
            'expires_at'    => now()->addDays(30),
            'connected_at'  => now()->subDays(5),
        ]);

        // MlbEmpresa ligada por company_id — fonte dos dados do cliente (planilha/precificação)
        $mlbEmpresa = MlbEmpresa::create([
            'nome'       => 'Empresa Teste',
            'tipo'       => 'ASSESSORIA',
            'company_id' => $company->id,
        ]);

        MlbImplementacao::create([
            'empresa_id' => $mlbEmpresa->id,
            'token'      => 'tok_' . uniqid(),  // NOT NULL UNIQUE na migration
            'dados'      => $dados ?? $this->dadosFixos(),
        ]);

        return compact('company', 'mlbEmpresa');
    }

    /**
     * Cria uma Company com MlToken mas SEM MlbEmpresa ligada.
     *
     * Wizard não deve quebrar — produtos deve ser [].
     */
    private function criarCompanySemMlbEmpresa(): Company
    {
        $company = Company::factory()->create();

        MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => 'USER_' . uniqid(),
            'access_token'  => 'token_test',
            'refresh_token' => 'refresh_test',
            'status'        => 'active',
            'expires_at'    => now()->addDays(30),
            'connected_at'  => now()->subDays(5),
        ]);

        return $company;
    }

    // ─── Testes de rascunhoPorProduto() ───

    /**
     * Caso principal: cria rascunho com SELLER_PACKAGE_* e price corretos.
     *
     * SKU01: peso_kg=2.5 → SELLER_PACKAGE_WEIGHT='2500 g'
     *        altura=95 cm, largura=55 cm, profundidade=55 cm (→ LENGTH)
     *        custo=200, frete_classico=30, acrescimo=0.20
     *        preco_classico = (200+30)/0.695 ≈ 331.01
     *        preco_anunciado_c = round(331.01 * 1.20, 2) = 397.21 (aprox.)
     */
    public function test_cria_rascunho_com_seller_package_em_gramas_e_cm(): void
    {
        $admin   = $this->criarAdmin();
        ['company' => $company] = $this->criarCompanyComImplementacao();

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/wizard/{$company->id}/rascunho-por-produto", [
                'sku'  => 'SKU01',
                'tier' => 'classico',
            ]);

        $response->assertOk()->assertJsonPath('ok', true);

        $rascunho = $response->json('rascunho');
        $payload  = $rascunho['payload'];

        // Verifica SELLER_PACKAGE_WEIGHT em gramas (2.5 kg → 2500 g)
        $attrs = collect($payload['attributes']);
        $peso  = $attrs->firstWhere('id', 'SELLER_PACKAGE_WEIGHT');
        $this->assertNotNull($peso, 'SELLER_PACKAGE_WEIGHT deve estar nos atributos');
        $this->assertSame('2500 g', $peso['value_name']);

        // Verifica dimensões em cm
        $altura = $attrs->firstWhere('id', 'SELLER_PACKAGE_HEIGHT');
        $this->assertSame('95 cm', $altura['value_name']);

        $largura = $attrs->firstWhere('id', 'SELLER_PACKAGE_WIDTH');
        $this->assertSame('55 cm', $largura['value_name']);

        // profundidade → LENGTH
        $comprimento = $attrs->firstWhere('id', 'SELLER_PACKAGE_LENGTH');
        $this->assertSame('55 cm', $comprimento['value_name']);
    }

    /**
     * Verifica que o price no payload é o preco_anunciado_c (preco_classico * (1+acrescimo)).
     */
    public function test_payload_price_igual_a_preco_anunciado(): void
    {
        $admin   = $this->criarAdmin();
        ['company' => $company] = $this->criarCompanyComImplementacao();

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/wizard/{$company->id}/rascunho-por-produto", [
                'sku'  => 'SKU01',
                'tier' => 'classico',
            ]);

        $response->assertOk();
        $payload = $response->json('rascunho.payload');

        // preco_classico = (200+30) / (1 - 0.115 - 0.19) = 230/0.695 ≈ 331.01
        // preco_anunciado_c = round(331.01 * 1.20, 2) = 397.21 (aprox, delta 0.10)
        $this->assertNotNull($payload['price']);
        $this->assertEqualsWithDelta(397.21, $payload['price'], 0.10);
    }

    /**
     * Verifica que sku_origem e listing_tier são salvos corretamente no banco.
     */
    public function test_sku_origem_e_listing_tier_gravados_no_banco(): void
    {
        $admin   = $this->criarAdmin();
        ['company' => $company] = $this->criarCompanyComImplementacao();

        $this->actingAs($admin)
            ->postJson("/mlb/anuncios/wizard/{$company->id}/rascunho-por-produto", [
                'sku'  => 'SKU01',
                'tier' => 'classico',
            ])
            ->assertOk();

        $this->assertDatabaseHas('ml_anuncio_rascunhos', [
            'sku_origem'   => 'SKU01',
            'listing_tier' => 'classico',
            'company_id'   => $company->id,
            'user_id'      => $admin->id,
            'status'       => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);
    }

    /**
     * Estoque e descrição do produto são gravados no payload.
     */
    public function test_estoque_e_descricao_no_payload(): void
    {
        $admin   = $this->criarAdmin();
        ['company' => $company] = $this->criarCompanyComImplementacao();

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/wizard/{$company->id}/rascunho-por-produto", [
                'sku'  => 'SKU01',
                'tier' => 'classico',
            ])
            ->assertOk();

        $payload = $response->json('rascunho.payload');
        $this->assertSame(8, $payload['available_quantity']);
        $this->assertSame('Cadeira ergonômica para escritório', $payload['description']);
    }

    /**
     * Produto sem preço (custo 0): price é null, rascunho criado, preco_indisponivel=true.
     */
    public function test_produto_sem_preco_cria_rascunho_com_price_null(): void
    {
        $admin   = $this->criarAdmin();
        ['company' => $company] = $this->criarCompanyComImplementacao();

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/wizard/{$company->id}/rascunho-por-produto", [
                'sku'  => 'SKU02',
                'tier' => 'classico',
            ])
            ->assertOk();

        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('preco_indisponivel', true);

        $payload = $response->json('rascunho.payload');
        $this->assertNull($payload['price']);

        // Rascunho ainda deve ter sido criado no banco
        $this->assertDatabaseHas('ml_anuncio_rascunhos', [
            'sku_origem' => 'SKU02',
            'status'     => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);
    }

    /**
     * Não-admin (role consultor) é bloqueado pelo middleware role:admin → 403.
     *
     * Admin sempre passa (isAdmin()===true no abort_unless interno).
     */
    public function test_nao_admin_bloqueado_pelo_middleware(): void
    {
        $admin      = $this->criarAdmin();
        $publicador = $this->criarPublicador();
        ['company' => $company] = $this->criarCompanyComImplementacao();

        // Admin passa normalmente
        $this->actingAs($admin)
            ->postJson("/mlb/anuncios/wizard/{$company->id}/rascunho-por-produto", [
                'sku'  => 'SKU01',
                'tier' => 'classico',
            ])
            ->assertOk();

        // Consultor (não-admin) é barrado pelo middleware role:admin
        $this->actingAs($publicador)
            ->postJson("/mlb/anuncios/wizard/{$company->id}/rascunho-por-produto", [
                'sku'  => 'SKU01',
                'tier' => 'classico',
            ])
            ->assertForbidden();
    }

    /**
     * SKU inexistente na planilha do cliente → 422 com mensagem clara.
     */
    public function test_sku_inexistente_retorna_422(): void
    {
        $admin   = $this->criarAdmin();
        ['company' => $company] = $this->criarCompanyComImplementacao();

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/wizard/{$company->id}/rascunho-por-produto", [
                'sku'  => 'SKU_INEXISTENTE',
                'tier' => 'classico',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false);

        // Mensagem deve ser informativa
        $this->assertStringContainsString('Produto não encontrado', $response->json('erro'));
    }

    /**
     * meta_campos no payload com origem 'cliente' para os campos auto-preenchidos.
     * Consumido por 76-02 para distinção visual no wizard.
     */
    public function test_payload_tem_meta_campos_com_origem_cliente(): void
    {
        $admin   = $this->criarAdmin();
        ['company' => $company] = $this->criarCompanyComImplementacao();

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/wizard/{$company->id}/rascunho-por-produto", [
                'sku'  => 'SKU01',
                'tier' => 'classico',
            ])
            ->assertOk();

        $metaCampos = $response->json('rascunho.payload.meta_campos');
        $this->assertNotNull($metaCampos, 'meta_campos deve estar no payload');

        // Cada campo individual com origem 'cliente'
        foreach (['title', 'price', 'available_quantity', 'description',
                  'pesoG', 'alturaCm', 'larguraCm', 'comprimentoCm'] as $campo) {
            $this->assertSame('cliente', $metaCampos[$campo] ?? null,
                "meta_campos[{$campo}] deve ser 'cliente'");
        }
    }

    // ─── Testes de wizard() estendido ───

    /**
     * wizard() passa prop 'produtos' (array) vinda de montarProdutosDoCliente.
     *
     * Company com MlToken + MlbEmpresa ligada com implementação → 2 produtos nos dados fixos.
     */
    public function test_wizard_passa_produtos_ao_front(): void
    {
        $admin   = $this->criarAdmin();
        ['company' => $company] = $this->criarCompanyComImplementacao();

        $this->actingAs($admin)
            ->get("/mlb/anuncios/wizard/{$company->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Mlb/AnunciarML')
                ->has('produtos')
                ->has('produtos', 2)  // 2 produtos nos dados fixos
            );
    }

    /**
     * Company com MlToken mas SEM MlbEmpresa ligada → wizard não quebra, produtos = [].
     */
    public function test_wizard_company_sem_mlb_empresa_produtos_vazio(): void
    {
        $admin   = $this->criarAdmin();
        $company = $this->criarCompanySemMlbEmpresa();

        $this->actingAs($admin)
            ->get("/mlb/anuncios/wizard/{$company->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Mlb/AnunciarML')
                ->has('produtos')
                ->where('produtos', [])
            );
    }

    /**
     * Company com MlbEmpresa + implementação mas sem produtos na planilha → produtos = [].
     */
    public function test_wizard_implementacao_sem_produtos_retorna_vazio(): void
    {
        $admin   = $this->criarAdmin();
        $dados   = ['itens' => ['planilha_produtos' => ['produtos' => []], 'precificacao' => []]];
        ['company' => $company] = $this->criarCompanyComImplementacao($dados);

        $this->actingAs($admin)
            ->get("/mlb/anuncios/wizard/{$company->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('produtos', [])
            );
    }

    /**
     * rascunhoPorProduto() com company SEM MlbEmpresa ligada → produtos = [] → 422 (sku inexistente).
     *
     * Degrada graciosamente: sem MlbEmpresa não há planilha, então qualquer SKU
     * será "não encontrado" e retorna 422 com mensagem clara (não 500).
     */
    public function test_rascunho_por_produto_sem_mlb_empresa_retorna_422(): void
    {
        $admin   = $this->criarAdmin();
        $company = $this->criarCompanySemMlbEmpresa();

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/wizard/{$company->id}/rascunho-por-produto", [
                'sku'  => 'SKU01',
                'tier' => 'classico',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertStringContainsString('Produto não encontrado', $response->json('erro'));
    }
}
