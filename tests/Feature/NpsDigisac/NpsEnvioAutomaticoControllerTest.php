<?php

namespace Tests\Feature\NpsDigisac;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\User;
use App\Services\Digisac\DigisacClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * v15.5 — Controller `NpsEnvioAutomaticoController` (admin-only).
 *
 * Cobre:
 *  T1. Não-admin não acessa (403 esperado do middleware role:admin)
 *  T2. Admin acessa e recebe props Inertia esperadas
 *  T3. PATCH config persiste toggles/IDs/mensagem em Configuracao
 *  T4. GET grupos proxy → 502 quando client Digisac falha; 200 quando ok
 *  T5. PUT mapeamento popula colunas + mapping_status='mapped'
 *  T6. DELETE mapeamento zera o vínculo
 */
class NpsEnvioAutomaticoControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function consultor(): User
    {
        return User::factory()->create(['role' => 'consultor']);
    }

    #[Test]
    public function test_nao_admin_recebe_403_na_pagina_envio_automatico(): void
    {
        $r = $this->actingAs($this->consultor())
            ->get(route('nps.envio-automatico.index'));
        $r->assertForbidden();
    }

    #[Test]
    public function test_admin_acessa_pagina_com_props_esperadas(): void
    {
        // Cria 1 empresa pra popular listagem de mapeamentos
        Company::factory()->create(['active' => true]);

        $r = $this->actingAs($this->admin())
            ->get(route('nps.envio-automatico.index'));

        $r->assertSuccessful();
        $r->assertInertia(fn ($page) =>
            $page->component('Nps/EnvioAutomatico')
                 ->has('config')
                 ->has('stats')
                 ->has('mapeamentos')
                 ->has('auditoria')
                 ->has('meses_disponiveis')
                 ->has('digisac_configurado')
        );
    }

    #[Test]
    public function test_patch_config_persiste_todos_os_campos_em_configuracao(): void
    {
        $payload = [
            'nps_envio_email_ativo'        => true,
            'nps_envio_digisac_ativo'      => true,
            'nps_digisac_service_id'       => 'svc-123',
            'nps_digisac_user_id'          => 'user-456',
            'nps_digisac_mensagem_default' => 'Nova mensagem {nome_empresa} — {link_nps}',
        ];

        $this->actingAs($this->admin())
            ->patch(route('nps.envio-automatico.config.update'), $payload)
            ->assertRedirect();

        $this->assertEquals('1',      Configuracao::get('nps_envio_email_ativo'));
        $this->assertEquals('1',      Configuracao::get('nps_envio_digisac_ativo'));
        $this->assertEquals('svc-123', Configuracao::get('nps_digisac_service_id'));
        $this->assertEquals('user-456', Configuracao::get('nps_digisac_user_id'));
        $this->assertStringContainsString('Nova mensagem', (string) Configuracao::get('nps_digisac_mensagem_default'));
    }

    #[Test]
    public function test_get_grupos_devolve_502_quando_client_falha(): void
    {
        $this->mock(DigisacClient::class, function ($mock) {
            $mock->shouldReceive('listGroups')
                ->once()
                ->andThrow(new RuntimeException('[Digisac] listGroups HTTP 401: unauthorized'));
        });

        $r = $this->actingAs($this->admin())
            ->getJson(route('nps.envio-automatico.digisac.grupos') . '?service_id=svc-x');

        $r->assertStatus(502);
        $r->assertJsonStructure(['message']);
    }

    #[Test]
    public function test_get_grupos_devolve_200_com_lista_quando_client_ok(): void
    {
        $this->mock(DigisacClient::class, function ($mock) {
            $mock->shouldReceive('listGroups')
                ->once()
                ->with('svc-x')
                ->andReturn([
                    ['id' => 'g1', 'name' => 'Grupo Um', 'raw' => []],
                    ['id' => 'g2', 'name' => 'Grupo Dois', 'raw' => []],
                ]);
        });

        $r = $this->actingAs($this->admin())
            ->getJson(route('nps.envio-automatico.digisac.grupos') . '?service_id=svc-x');

        $r->assertOk();
        $r->assertJsonPath('service_id', 'svc-x');
        $r->assertJsonCount(2, 'groups');
        $r->assertJsonPath('groups.0.id', 'g1');
    }

    #[Test]
    public function test_put_mapeamento_popula_colunas_e_status_mapped(): void
    {
        $empresa = Company::factory()->create(['active' => true]);

        $this->actingAs($this->admin())
            ->put(route('nps.envio-automatico.mapeamento.update', $empresa->id), [
                'digisac_service_id'          => 'svc-xyz',
                'digisac_group_contact_id'    => 'contact-abc',
                'digisac_group_name_snapshot' => 'Grupo Teste',
            ])
            ->assertRedirect();

        $empresa->refresh();
        $this->assertEquals('svc-xyz',      $empresa->digisac_service_id);
        $this->assertEquals('contact-abc',  $empresa->digisac_group_contact_id);
        $this->assertEquals('Grupo Teste',  $empresa->digisac_group_name_snapshot);
        $this->assertEquals('mapped',       $empresa->digisac_group_mapping_status);
        $this->assertNotNull($empresa->digisac_group_mapped_at);
    }

    #[Test]
    public function test_delete_mapeamento_zera_vinculo(): void
    {
        $empresa = Company::factory()->create([
            'active' => true,
            'digisac_group_contact_id'    => 'contact-abc',
            'digisac_group_name_snapshot' => 'Grupo Teste',
            'digisac_group_mapped_at'     => now(),
            'digisac_group_mapping_status' => 'mapped',
        ]);

        $this->actingAs($this->admin())
            ->delete(route('nps.envio-automatico.mapeamento.destroy', $empresa->id))
            ->assertRedirect();

        $empresa->refresh();
        $this->assertNull($empresa->digisac_group_contact_id);
        $this->assertNull($empresa->digisac_group_name_snapshot);
        $this->assertEquals('not_mapped', $empresa->digisac_group_mapping_status);
    }
}
