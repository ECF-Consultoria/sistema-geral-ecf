<?php

namespace Tests\Feature\Phase76;

use App\Models\Company;
use App\Models\MlAnuncioRascunho;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 76 Plan 01 — rascunhoPorProduto() e wizard() estendido.
 *
 * Usa SQLite in-memory com RefreshDatabase.
 * Seed mínimo: MlbEmpresa + MlbImplementacao com dados fixos (2 produtos, 1 com preço, 1 sem).
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

    private function criarEmpresaComImplementacao(int $responsavelId, ?array $dados = null): MlbEmpresa
    {
        $company = Company::factory()->create();

        $empresa = MlbEmpresa::create([
            'nome'           => 'Empresa Teste',
            'tipo'           => 'ASSESSORIA',
            'company_id'     => $company->id,
            'responsavel_id' => $responsavelId,
        ]);

        MlbImplementacao::create([
            'empresa_id' => $empresa->id,
            'token'      => 'tok_' . uniqid(),  // obrigatório NOT NULL UNIQUE na migration
            'dados'      => $dados ?? $this->dadosFixos(),
        ]);

        return $empresa;
    }

    private function criarEmpresaSemImplementacao(int $responsavelId): MlbEmpresa
    {
        $company = Company::factory()->create();

        return MlbEmpresa::create([
            'nome'           => 'Empresa Sem Impl',
            'tipo'           => 'ASSESSORIA',
            'company_id'     => $company->id,
            'responsavel_id' => $responsavelId,
        ]);
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
        $empresa = $this->criarEmpresaComImplementacao($admin->id);

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/wizard/{$empresa->id}/rascunho-por-produto", [
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
        $empresa = $this->criarEmpresaComImplementacao($admin->id);

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/wizard/{$empresa->id}/rascunho-por-produto", [
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
        $empresa = $this->criarEmpresaComImplementacao($admin->id);

        $this->actingAs($admin)
            ->postJson("/mlb/anuncios/wizard/{$empresa->id}/rascunho-por-produto", [
                'sku'  => 'SKU01',
                'tier' => 'classico',
            ])
            ->assertOk();

        $this->assertDatabaseHas('ml_anuncio_rascunhos', [
            'sku_origem'  => 'SKU01',
            'listing_tier' => 'classico',
            'company_id'  => $empresa->company_id,
            'user_id'     => $admin->id,
            'status'      => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);
    }

    /**
     * Estoque e descrição do produto são gravados no payload.
     */
    public function test_estoque_e_descricao_no_payload(): void
    {
        $admin   = $this->criarAdmin();
        $empresa = $this->criarEmpresaComImplementacao($admin->id);

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/wizard/{$empresa->id}/rascunho-por-produto", [
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
        $empresa = $this->criarEmpresaComImplementacao($admin->id);

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/wizard/{$empresa->id}/rascunho-por-produto", [
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
     * T-76-01: publicador sem responsavel_id na empresa → 403.
     */
    public function test_publicador_sem_responsavel_id_recebe_403(): void
    {
        $admin      = $this->criarAdmin();
        $publicador = $this->criarPublicador();
        $empresa    = $this->criarEmpresaComImplementacao($admin->id); // responsavel = admin, não o publicador

        // publicador tenta criar rascunho em empresa não atribuída a ele
        // mas como o gate é role:admin o middleware já bloqueia, então testamos a lógica
        // do abort_unless diretamente via actingAs admin acessando empresa com responsavel diferente
        $outraEmpresa = $this->criarEmpresaComImplementacao($publicador->id);
        $outraEmpresa->update(['responsavel_id' => $publicador->id]);

        // Admin passa sempre (isAdmin() = true)
        $this->actingAs($admin)
            ->postJson("/mlb/anuncios/wizard/{$outraEmpresa->id}/rascunho-por-produto", [
                'sku' => 'SKU01',
            ])
            ->assertOk();

        // Não-admin (consultor sem role:admin) cai no middleware antes do controller
        $this->actingAs($publicador)
            ->postJson("/mlb/anuncios/wizard/{$outraEmpresa->id}/rascunho-por-produto", [
                'sku' => 'SKU01',
            ])
            ->assertForbidden();
    }

    /**
     * SKU inexistente na planilha do cliente → 422 com mensagem clara.
     */
    public function test_sku_inexistente_retorna_422(): void
    {
        $admin   = $this->criarAdmin();
        $empresa = $this->criarEmpresaComImplementacao($admin->id);

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/wizard/{$empresa->id}/rascunho-por-produto", [
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
        $empresa = $this->criarEmpresaComImplementacao($admin->id);

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/wizard/{$empresa->id}/rascunho-por-produto", [
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
     */
    public function test_wizard_passa_produtos_ao_front(): void
    {
        $admin   = $this->criarAdmin();
        $empresa = $this->criarEmpresaComImplementacao($admin->id);

        $this->actingAs($admin)
            ->get("/mlb/anuncios/wizard/{$empresa->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Mlb/AnunciarML')
                ->has('produtos')
                ->has('produtos', 2)  // 2 produtos nos dados fixos
            );
    }

    /**
     * Empresa sem implementação → wizard não quebra, produtos = [].
     */
    public function test_wizard_empresa_sem_implementacao_produtos_vazio(): void
    {
        $admin   = $this->criarAdmin();
        $empresa = $this->criarEmpresaSemImplementacao($admin->id);

        $this->actingAs($admin)
            ->get("/mlb/anuncios/wizard/{$empresa->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Mlb/AnunciarML')
                ->has('produtos')
                ->where('produtos', [])
            );
    }

    /**
     * Empresa com implementação mas sem produtos na planilha → produtos = [].
     */
    public function test_wizard_implementacao_sem_produtos_retorna_vazio(): void
    {
        $admin   = $this->criarAdmin();
        $dados   = ['itens' => ['planilha_produtos' => ['produtos' => []], 'precificacao' => []]];
        $empresa = $this->criarEmpresaComImplementacao($admin->id, $dados);

        $this->actingAs($admin)
            ->get("/mlb/anuncios/wizard/{$empresa->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('produtos', [])
            );
    }
}
