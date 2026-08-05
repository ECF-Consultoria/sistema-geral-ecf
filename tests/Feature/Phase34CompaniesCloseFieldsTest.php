<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
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
 *  T5. Cast empresa_nova=>bool
 *  T6. Company recem-criada por factory tem empresa_nova=true (default DB)
 *
 * Quick task 260805-eqk — a cobertura dos campos de close foi reduzida a
 * `email_colaborador`: nicho, dor, vende_ml, faturamento_mensal e
 * marketplaces_extras deixaram de existir (properties inexistentes no HubSpot
 * / substituída por company_marketplaces).
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

    /**
     * Phase 37 Plan 37-06 (REQ-37-07) — /companies passou a filtrar empresas
     * por contrato Performance ativo. Helper garante que a empresa tenha um
     * contrato Performance, pra continuar aparecendo no payload em testes
     * que validam pendencias/campos de close (foco diferente do filtro).
     */
    private function attachPerformanceContract(Company $c): ContratoServico
    {
        $servico = Servico::create([
            'nome'          => 'Gestao P34 ' . uniqid(),
            'valor_padrao'  => 1500,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);
        return ContratoServico::create([
            'company_id'       => $c->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);
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
        // Phase 37 Plan 37-06 — empresa precisa de contrato Performance pra
        // aparecer em /companies (REQ-37-07). Foco do teste eh pendencia, nao filtro.
        $this->attachPerformanceContract($empresa);

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
        // Phase 37 Plan 37-06 — contrato Performance pra empresa aparecer em /companies.
        $this->attachPerformanceContract($empresa);

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
        // Phase 37 Plan 37-06 — contrato Performance pra empresa aparecer em /companies.
        $this->attachPerformanceContract($empresa);

        $response = $this->actingAs($admin)->get('/companies');
        $alvo = collect($response->viewData('page')['props']['companies'])
            ->firstWhere('id', $empresa->id);

        $this->assertContains('sem_email_colaborador', $alvo['pendencias'],
            'Pendencia sem_email_colaborador deve aparecer quando email_colaborador eh null '.
            '(independente de email_cliente estar populado — D-07 fix)');
    }

    /**
     * Quick task 260805-eqk — dos campos do close sobrou apenas
     * `email_colaborador`; nicho/dor/vende_ml/faturamento_mensal/
     * marketplaces_extras foram removidos do payload e do banco.
     */
    public function test_payload_inclui_email_colaborador(): void
    {
        $admin   = $this->criarAdmin();
        $empresa = $this->criarEmpresa([
            'email_colaborador' => 'colab@empresa.com',
        ]);
        // Phase 37 Plan 37-06 — contrato Performance pra empresa aparecer em /companies.
        $this->attachPerformanceContract($empresa);

        $response = $this->actingAs($admin)->get('/companies');
        $alvo = collect($response->viewData('page')['props']['companies'])
            ->firstWhere('id', $empresa->id);

        $this->assertSame('colab@empresa.com', $alvo['email_colaborador']);

        foreach (['nicho', 'dor', 'vende_ml', 'faturamento_mensal', 'marketplaces_extras'] as $removido) {
            $this->assertArrayNotHasKey($removido, $alvo, "Campo morto {$removido} não deveria voltar ao payload");
        }
    }

    /**
     * Quick task 260805-eqk — as 5 colunas mortas sumiram do schema, mas
     * `email_colaborador` continua existindo (decisão do usuário: 6 gmails
     * reais de onboarding, sem duplicata em nenhum outro lugar).
     */
    public function test_colunas_mortas_sumiram_e_email_colaborador_permanece(): void
    {
        foreach (['nicho', 'dor', 'vende_ml', 'faturamento_mensal', 'marketplaces_extras'] as $removido) {
            $this->assertFalse(
                \Illuminate\Support\Facades\Schema::hasColumn('companies', $removido),
                "Coluna {$removido} deveria ter sido dropada",
            );
        }

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('companies', 'email_colaborador'));
    }
}
