<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 173-01 Task 2: `GET publicador/empresas-busca?q=` — seletor "Trocar empresa" (D-06).
 * Reusa `ProgramasPublicadorService::empresas()` já usado por `index()`, mesmo filtro
 * por nome/identificador; NUNCA devolve token de acesso/refresh.
 */
class EmpresasBuscaTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/mlb/anuncios/publicador/empresas-busca';

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function empresa(string $nome, array $attrs = [], bool $comToken = true): MlbEmpresa
    {
        $e = MlbEmpresa::create($attrs + ['nome' => $nome, 'projeto' => 'POLOS'])->fresh();
        if ($comToken) {
            MlToken::create([
                'mlb_empresa_id' => $e->id, 'ml_user_id' => (string) random_int(1000, 99999999),
                'access_token' => 'APP_USR-segredo', 'refresh_token' => 'TG-segredo',
                'expires_at' => now()->addHours(5), 'status' => 'active',
            ]);
        }

        return $e;
    }

    public function test_busca_por_trecho_do_nome_devolve_so_empresas_que_casam(): void
    {
        $this->empresa('Loja Azul');
        $this->empresa('Loja Verde');
        $this->empresa('Mercadinho Central');

        $r = $this->actingAs($this->admin())->getJson(self::URL.'?q=loja')->assertOk();

        $nomes = collect($r->json())->pluck('nome')->all();
        $this->assertEqualsCanonicalizing(['Loja Azul', 'Loja Verde'], $nomes);
    }

    public function test_busca_vazia_devolve_lista_vazia_nao_a_conta_toda(): void
    {
        $this->empresa('Loja Azul');
        $admin = $this->admin();

        $this->actingAs($admin)->getJson(self::URL)->assertOk()->assertJson([]);
        $this->actingAs($admin)->getJson(self::URL.'?q=')->assertOk()->assertJson([]);
        $this->actingAs($admin)->getJson(self::URL.'?q='.rawurlencode('   '))->assertOk()->assertJson([]);
    }

    public function test_nenhum_campo_de_token_de_acesso_aparece_no_json(): void
    {
        $this->empresa('Loja Azul');

        $r = $this->actingAs($this->admin())->getJson(self::URL.'?q=loja')->assertOk();

        $this->assertStringNotContainsString('segredo', $r->getContent());
        foreach ($r->json() as $item) {
            $this->assertArrayNotHasKey('access_token', $item);
            $this->assertArrayNotHasKey('refresh_token', $item);
            $this->assertSame(['chave', 'nome', 'identificador', 'company_id', 'programa', 'programa_rotulo', 'token'], array_keys($item));
        }
    }

    public function test_item_de_empresa_sem_company_tem_company_id_null_explicito(): void
    {
        $this->empresa('Loja Sem Company');

        $r = $this->actingAs($this->admin())->getJson(self::URL.'?q=loja')->assertOk();

        $this->assertArrayHasKey('company_id', $r->json()[0]);
        $this->assertNull($r->json()[0]['company_id']);
    }

    public function test_busca_cruza_os_3_programas_e_devolve_programa_e_rotulo(): void
    {
        $this->empresa('Conta Polos', ['projeto' => 'POLOS']);
        $this->empresa('Conta Incubadora', ['projeto' => 'Incubadora']);
        $c = Company::factory()->create(['name' => 'Conta Gestao']);
        MlToken::create(['company_id' => $c->id, 'ml_user_id' => '1', 'access_token' => 'x', 'refresh_token' => 'y',
            'expires_at' => now()->addHours(5), 'status' => 'active']);

        $r = $this->actingAs($this->admin())->getJson(self::URL.'?q=conta')->assertOk();

        $porNome = collect($r->json())->keyBy('nome');
        $this->assertSame('polos', $porNome['Conta Polos']['programa']);
        $this->assertSame('Polos', $porNome['Conta Polos']['programa_rotulo']);
        $this->assertSame('incubadora', $porNome['Conta Incubadora']['programa']);
        $this->assertSame('Incubadora', $porNome['Conta Incubadora']['programa_rotulo']);
        $this->assertSame('gestao', $porNome['Conta Gestao']['programa']);
        $this->assertSame('Gestão', $porNome['Conta Gestao']['programa_rotulo']);
    }

    public function test_busca_limitada_a_20_resultados(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->empresa(sprintf('Loja %02d', $i));
        }

        $r = $this->actingAs($this->admin())->getJson(self::URL.'?q=loja')->assertOk();

        $this->assertCount(20, $r->json());
    }

    public function test_busca_por_identificador_tambem_casa(): void
    {
        $e = $this->empresa('Sem Nome Facil');
        $e->forceFill(['cust_id' => 'ACHE-ME-123'])->save();

        $r = $this->actingAs($this->admin())->getJson(self::URL.'?q=ACHE-ME')->assertOk();

        $this->assertCount(1, $r->json());
        $this->assertSame('Sem Nome Facil', $r->json()[0]['nome']);
    }

    public function test_nao_admin_recebe_403(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->getJson(self::URL.'?q=loja')->assertForbidden();
    }
}
