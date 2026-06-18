<?php

namespace Tests\Feature;

use App\Models\HubspotLineItemMapping;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Suite Feature — Phase 37 Plan 37-07 (REQ-37-02, REQ-37-09).
 *
 * Cobre o CRUD admin de mapeamentos HubSpot line_item → Servico em
 * /sistema/hubspot-line-items:
 *  - GET   /sistema/hubspot-line-items        → Inertia 'Sistema/HubspotLineItems'
 *  - POST  /sistema/hubspot-line-items        → store
 *  - PUT   /sistema/hubspot-line-items/{m}    → update
 *  - DELETE /sistema/hubspot-line-items/{m}   → destroy
 *
 * Cenarios:
 *  - Renderiza componente correto + lista mappings com servico embedado
 *  - Validacoes: unique line_item_name + exists+ativo servico_id
 *  - Activity log em pt-BR (log_name = 'sistema')
 *  - Autorizacao: nao-admin recebe 403 em qualquer endpoint
 *  - unique no update ignora self (PUT sem mudar nome nao falha)
 */
class Phase37HubspotLineItemMappingAdminTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Limpa estado do seed de migrations antes de cada teste — cada cenario
     * isolado (mesmo padrao do Phase37LineItemMappingTest do Plan 37-02).
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('hubspot_line_item_mapping')->delete();
        DB::table('servicos')->delete();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'name'     => 'Admin Phase37-07 ' . uniqid(),
            'email'    => 'admin.p37-07.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);
        return $admin;
    }

    private function actingAsConsultor(): User
    {
        $consultor = User::create([
            'name'     => 'Consultor Phase37-07 ' . uniqid(),
            'email'    => 'consultor.p37-07.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'consultor',
            'active'   => true,
        ]);
        $this->actingAs($consultor);
        return $consultor;
    }

    private function criarServico(string $nome, bool $ativo = true, string $setor = Servico::SETOR_OUTROS): Servico
    {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => 100,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => $ativo,
            'setor'         => $setor,
        ]);
    }

    private function criarMapping(string $nome, Servico $servico, bool $ativo = true): HubspotLineItemMapping
    {
        return HubspotLineItemMapping::create([
            'line_item_name' => $nome,
            'servico_id'     => $servico->id,
            'ativo'          => $ativo,
        ]);
    }

    // ─── 1. index() — render + payload ───────────────────────────────────────

    public function test_index_renderiza_componente_correto(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/sistema/hubspot-line-items');

        $response->assertOk();
        $response->assertInertia(fn ($page) =>
            $page->component('Sistema/HubspotLineItems')
                 ->has('mappings')
                 ->has('servicos_disponiveis')
        );
    }

    public function test_index_lista_mappings_com_servico_embedado(): void
    {
        $this->actingAsAdmin();

        $gestao = $this->criarServico('Gestão', true, Servico::SETOR_PERFORMANCE);
        $polos  = $this->criarServico('Polos', true, Servico::SETOR_PUBLICACAO);

        $this->criarMapping('MAP', $gestao);
        $this->criarMapping('Polo SP', $polos);

        $response = $this->get('/sistema/hubspot-line-items');

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(2, $props['mappings']);
        $nomes = array_column($props['mappings'], 'line_item_name');
        $this->assertContains('MAP', $nomes);
        $this->assertContains('Polo SP', $nomes);

        // Cada linha deve trazer servico_nome embedado
        foreach ($props['mappings'] as $m) {
            $this->assertNotEmpty($m['servico_nome'], 'servico_nome deveria estar embedado');
        }

        // servicos_disponiveis para alimentar o select da UI
        $this->assertCount(2, $props['servicos_disponiveis']);
    }

    // ─── 2. store() ──────────────────────────────────────────────────────────

    public function test_store_cria_mapping_valido(): void
    {
        $this->actingAsAdmin();

        $gestao = $this->criarServico('Gestão', true, Servico::SETOR_PERFORMANCE);

        $response = $this->post('/sistema/hubspot-line-items', [
            'line_item_name' => 'MAP PREMIUM',
            'servico_id'     => $gestao->id,
            'ativo'          => true,
            'observacoes'    => 'criado em teste',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('hubspot_line_item_mapping', [
            'line_item_name' => 'MAP PREMIUM',
            'servico_id'     => $gestao->id,
            'ativo'          => true,
        ]);

        // Activity log em pt-BR
        $this->assertDatabaseHas('activity_log', [
            'log_name'    => 'sistema',
            'description' => 'Mapeamento HubSpot criado: "MAP PREMIUM"',
        ]);
    }

    public function test_store_falha_line_item_name_duplicado(): void
    {
        $this->actingAsAdmin();

        $gestao = $this->criarServico('Gestão', true, Servico::SETOR_PERFORMANCE);
        $this->criarMapping('MAP', $gestao);

        $response = $this->post('/sistema/hubspot-line-items', [
            'line_item_name' => 'MAP',
            'servico_id'     => $gestao->id,
        ]);

        $response->assertSessionHasErrors(['line_item_name']);
        $this->assertSame(1, HubspotLineItemMapping::count(), 'duplicata nao deveria ter criado segunda row');
    }

    public function test_store_falha_servico_inativo(): void
    {
        $this->actingAsAdmin();

        $inativo = $this->criarServico('Servico Antigo', false);

        $response = $this->post('/sistema/hubspot-line-items', [
            'line_item_name' => 'OUTRO',
            'servico_id'     => $inativo->id,
        ]);

        $response->assertSessionHasErrors(['servico_id']);
        $this->assertSame(0, HubspotLineItemMapping::count());
    }

    public function test_store_falha_servico_inexistente(): void
    {
        $this->actingAsAdmin();

        $response = $this->post('/sistema/hubspot-line-items', [
            'line_item_name' => 'OUTRO',
            'servico_id'     => 999999,
        ]);

        $response->assertSessionHasErrors(['servico_id']);
    }

    // ─── 3. update() ─────────────────────────────────────────────────────────

    public function test_update_edita_campos(): void
    {
        $this->actingAsAdmin();

        $gestao   = $this->criarServico('Gestão', true, Servico::SETOR_PERFORMANCE);
        $polos    = $this->criarServico('Polos', true, Servico::SETOR_PUBLICACAO);
        $mapping  = $this->criarMapping('MAP', $gestao);

        $response = $this->put('/sistema/hubspot-line-items/' . $mapping->id, [
            'line_item_name' => 'MAP V2',
            'servico_id'     => $polos->id,
            'ativo'          => false,
            'observacoes'    => 'editado',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('hubspot_line_item_mapping', [
            'id'             => $mapping->id,
            'line_item_name' => 'MAP V2',
            'servico_id'     => $polos->id,
            'ativo'          => false,
            'observacoes'    => 'editado',
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name'    => 'sistema',
            'description' => 'Mapeamento HubSpot atualizado: "MAP V2"',
        ]);
    }

    public function test_unique_no_update_ignora_self(): void
    {
        $this->actingAsAdmin();

        $gestao   = $this->criarServico('Gestão', true, Servico::SETOR_PERFORMANCE);
        $mapping  = $this->criarMapping('MAP', $gestao);

        // PUT mantendo o mesmo line_item_name — NAO pode falhar por unique
        $response = $this->put('/sistema/hubspot-line-items/' . $mapping->id, [
            'line_item_name' => 'MAP',
            'servico_id'     => $gestao->id,
            'ativo'          => true,
            'observacoes'    => 'sem mudanca de nome',
        ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();
    }

    // ─── 4. destroy() ────────────────────────────────────────────────────────

    public function test_destroy_remove_mapping(): void
    {
        $this->actingAsAdmin();

        $gestao  = $this->criarServico('Gestão', true, Servico::SETOR_PERFORMANCE);
        $mapping = $this->criarMapping('MAP', $gestao);

        $response = $this->delete('/sistema/hubspot-line-items/' . $mapping->id);

        $response->assertRedirect();
        $this->assertDatabaseMissing('hubspot_line_item_mapping', [
            'id' => $mapping->id,
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name'    => 'sistema',
            'description' => 'Mapeamento HubSpot removido: "MAP"',
        ]);
    }

    // ─── 5. Autorizacao role:admin ───────────────────────────────────────────

    public function test_acesso_admin_apenas_index(): void
    {
        $this->actingAsConsultor();
        $this->get('/sistema/hubspot-line-items')->assertForbidden();
    }

    public function test_acesso_admin_apenas_store(): void
    {
        $this->actingAsConsultor();
        $gestao = $this->criarServico('Gestão', true, Servico::SETOR_PERFORMANCE);

        $this->post('/sistema/hubspot-line-items', [
            'line_item_name' => 'TENTATIVA',
            'servico_id'     => $gestao->id,
        ])->assertForbidden();

        $this->assertSame(0, HubspotLineItemMapping::count());
    }

    public function test_acesso_admin_apenas_update(): void
    {
        // Cria como admin, tenta editar como consultor.
        $admin   = $this->actingAsAdmin();
        $gestao  = $this->criarServico('Gestão', true, Servico::SETOR_PERFORMANCE);
        $mapping = $this->criarMapping('MAP', $gestao);

        $this->actingAsConsultor();
        $this->put('/sistema/hubspot-line-items/' . $mapping->id, [
            'line_item_name' => 'HACK',
            'servico_id'     => $gestao->id,
        ])->assertForbidden();

        $this->assertDatabaseHas('hubspot_line_item_mapping', [
            'id'             => $mapping->id,
            'line_item_name' => 'MAP',
        ]);
    }

    public function test_acesso_admin_apenas_destroy(): void
    {
        $admin   = $this->actingAsAdmin();
        $gestao  = $this->criarServico('Gestão', true, Servico::SETOR_PERFORMANCE);
        $mapping = $this->criarMapping('MAP', $gestao);

        $this->actingAsConsultor();
        $this->delete('/sistema/hubspot-line-items/' . $mapping->id)->assertForbidden();

        $this->assertDatabaseHas('hubspot_line_item_mapping', [
            'id' => $mapping->id,
        ]);
    }
}
