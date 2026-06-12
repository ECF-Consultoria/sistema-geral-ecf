<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite Feature — Phase 34 Plan 34-01.
 *
 * Cobre:
 *  T1. POST /companies/{c}/marcar-visto (admin) zera empresa_nova + grava visto_em/visto_por
 *  T2. POST /companies/{c}/marcar-visto (consultor) → 403
 *  T3. /companies index → empresa nova aparece com pendencia 'empresa_nova'
 *  T4. /companies index → pendencia 'sem_email_colaborador' agora olha
 *      email_colaborador (D-07 bug fix): empresa com email_cliente populado
 *      mas email_colaborador vazio AINDA mostra a pendencia
 *  T5. Casts: empresa_nova=>bool, marketplaces_extras=>array
 *  T6. Company recem-criada por factory tem empresa_nova=true (default DB)
 */
class Phase34CompaniesCloseFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function criarAdmin(): User
    {
        return User::create([
            'name'     => 'Admin Phase34 ' . uniqid(),
            'email'    => 'admin.p34.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
    }

    private function criarConsultor(): User
    {
        return User::create([
            'name'     => 'Consultor Phase34 ' . uniqid(),
            'email'    => 'consultor.p34.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'consultor',
            'active'   => true,
        ]);
    }

    private function criarEmpresa(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name'   => 'Empresa P34 ' . uniqid(),
            'cnpj'   => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true,
        ], $overrides));
    }

    public function test_company_default_tem_empresa_nova_true(): void
    {
        $empresa = $this->criarEmpresa();
        $empresa->refresh();

        $this->assertTrue($empresa->empresa_nova, 'Default da coluna deve ser true (1)');
        $this->assertNull($empresa->empresa_nova_visto_em);
        $this->assertNull($empresa->empresa_nova_visto_por);
    }

    public function test_marcar_visto_admin_atualiza_campos(): void
    {
        $admin   = $this->criarAdmin();
        $empresa = $this->criarEmpresa();

        // Refresh para hidratar o default da coluna (SQLite nao retorna defaults
        // no insert — o atributo no objeto recem-criado pode ficar null ate o reload).
        $empresa->refresh();
        $this->assertTrue($empresa->empresa_nova);

        $response = $this->actingAs($admin)
            ->post("/companies/{$empresa->id}/marcar-visto");

        $response->assertStatus(302);
        $response->assertSessionHas('success');

        $empresa->refresh();
        $this->assertFalse($empresa->empresa_nova);
        $this->assertNotNull($empresa->empresa_nova_visto_em);
        $this->assertSame($admin->id, $empresa->empresa_nova_visto_por);
    }

    public function test_marcar_visto_403_para_nao_admin(): void
    {
        $consultor = $this->criarConsultor();
        $empresa   = $this->criarEmpresa();

        $response = $this->actingAs($consultor)
            ->post("/companies/{$empresa->id}/marcar-visto");

        // O middleware role:admin pode responder 403 OU 302 dependendo da impl.
        // EnsureUserHasRole no projeto abort(403) — confirma 403.
        $response->assertStatus(403);

        $empresa->refresh();
        // Estado inalterado.
        $this->assertTrue($empresa->empresa_nova);
        $this->assertNull($empresa->empresa_nova_visto_em);
        $this->assertNull($empresa->empresa_nova_visto_por);
    }

    public function test_pendencia_empresa_nova_aparece_quando_true(): void
    {
        $admin   = $this->criarAdmin();
        $empresa = $this->criarEmpresa([
            'email_cliente'     => 'cliente@empresa.com',
            'email_colaborador' => 'colaborador@empresa.com',
            'adman_account_id'  => '123456',
        ]);

        $response = $this->actingAs($admin)->get('/companies');
        $response->assertStatus(200);

        $companies = $response->viewData('page')['props']['companies'];
        $alvo = collect($companies)->firstWhere('id', $empresa->id);

        $this->assertNotNull($alvo, 'Empresa criada deveria estar no payload');
        $this->assertContains('empresa_nova', $alvo['pendencias'],
            'Pendencia empresa_nova deve aparecer quando empresa_nova=true');
        $this->assertTrue($alvo['empresa_nova'], 'Payload empresa_nova bool deve refletir DB');
    }

    public function test_pendencia_empresa_nova_some_apos_marcar_visto(): void
    {
        $admin   = $this->criarAdmin();
        $empresa = $this->criarEmpresa([
            'email_cliente'     => 'cliente@empresa.com',
            'email_colaborador' => 'colaborador@empresa.com',
            'adman_account_id'  => '123456',
        ]);

        // Marca como visto.
        $this->actingAs($admin)->post("/companies/{$empresa->id}/marcar-visto")
            ->assertStatus(302);

        // Re-fetch a listagem.
        $response = $this->actingAs($admin)->get('/companies');
        $alvo = collect($response->viewData('page')['props']['companies'])
            ->firstWhere('id', $empresa->id);

        $this->assertNotContains('empresa_nova', $alvo['pendencias'],
            'Pendencia empresa_nova nao deve aparecer apos marcar visto');
        $this->assertFalse($alvo['empresa_nova']);
    }

    public function test_pendencia_sem_email_colaborador_olha_campo_correto(): void
    {
        $admin = $this->criarAdmin();

        // BUG D-07: empresa COM email_cliente preenchido MAS sem email_colaborador.
        // Antes do fix, isto NAO mostrava pendencia (olhava email_cliente);
        // depois do fix, deve mostrar.
        $empresa = $this->criarEmpresa([
            'email_cliente'     => 'proprietario@cliente.com', // populado pelo autopop Drive
            'email_colaborador' => null,                        // ECF ainda nao criou
            'adman_account_id'  => '123456',
        ]);

        $response = $this->actingAs($admin)->get('/companies');
        $alvo = collect($response->viewData('page')['props']['companies'])
            ->firstWhere('id', $empresa->id);

        $this->assertContains('sem_email_colaborador', $alvo['pendencias'],
            'Pendencia sem_email_colaborador deve aparecer quando email_colaborador eh null '.
            '(independente de email_cliente estar populado — D-07 fix)');
    }

    public function test_payload_inclui_campos_de_close(): void
    {
        $admin   = $this->criarAdmin();
        $empresa = $this->criarEmpresa([
            'nicho'               => 'Moda feminina',
            'dor'                 => 'Vendas estagnadas',
            'vende_ml'            => true,
            'faturamento_mensal'  => 50000.75,
            'marketplaces_extras' => ['shopee', 'amazon'],
            'email_colaborador'   => 'colab@empresa.com',
        ]);

        $response = $this->actingAs($admin)->get('/companies');
        $alvo = collect($response->viewData('page')['props']['companies'])
            ->firstWhere('id', $empresa->id);

        $this->assertSame('Moda feminina', $alvo['nicho']);
        $this->assertSame('Vendas estagnadas', $alvo['dor']);
        // vende_ml cast=>bool, deve refletir true.
        $this->assertTrue((bool) $alvo['vende_ml']);
        // faturamento_mensal castado para decimal:2; payload converte para float.
        $this->assertEqualsWithDelta(50000.75, (float) $alvo['faturamento_mensal'], 0.001);
        $this->assertSame(['shopee', 'amazon'], $alvo['marketplaces_extras']);
        $this->assertSame('colab@empresa.com', $alvo['email_colaborador']);
    }

    public function test_marketplaces_extras_castado_para_array(): void
    {
        $empresa = $this->criarEmpresa([
            'marketplaces_extras' => ['shopee', 'magalu', 'temu'],
        ]);
        $empresa->refresh();

        $this->assertIsArray($empresa->marketplaces_extras);
        $this->assertCount(3, $empresa->marketplaces_extras);
        $this->assertContains('magalu', $empresa->marketplaces_extras);
    }
}
