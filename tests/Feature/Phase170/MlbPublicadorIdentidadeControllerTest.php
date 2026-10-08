<?php

namespace Tests\Feature\Phase170;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\CreativeIdentidade;
use App\Models\PubProduto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 170, Plano 02, Task 1 — `MlbPublicadorIdentidadeController::mostrar()/salvar()`.
 *
 * Cenário mínimo (sem `CenarioCadeira` — não precisa de ML/HTTP fake, só de
 * `PubProduto` ancorado em `company_id`): a identidade é da CONTA, nunca do
 * produto, e `empresaDoProduto()` já resolve uma Company sozinha (sem
 * `MlbEmpresa` ligada) como programa 'gestao' — ver
 * `ProgramasPublicadorService::resolver()`.
 */
class MlbPublicadorIdentidadeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Configuracao::set('creative_engine_ativo', '1');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function produtoDaCompany(Company $company, string $sku = 'ID-01'): PubProduto
    {
        return PubProduto::create([
            'company_id' => $company->id,
            'sku' => $sku,
            'nome' => 'Produto Teste Identidade',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
    }

    private function rotaMostrar(PubProduto $produto): string
    {
        return route('mlb.anuncios.publicador.identidade.mostrar', ['produto' => $produto->id]);
    }

    private function rotaSalvar(PubProduto $produto): string
    {
        return route('mlb.anuncios.publicador.identidade.salvar', ['produto' => $produto->id]);
    }

    public function test_get_sem_identidade_cadastrada_devolve_texto_null(): void
    {
        $company = Company::factory()->create();
        $produto = $this->produtoDaCompany($company);

        $resp = $this->actingAs($this->admin())->getJson($this->rotaMostrar($produto));

        $resp->assertOk();
        $resp->assertExactJson(['texto' => null]);
    }

    public function test_put_com_texto_depois_get_do_mesmo_produto_devolve_o_texto_salvo(): void
    {
        $company = Company::factory()->create();
        $produto = $this->produtoDaCompany($company);
        $admin = $this->admin();
        $texto = 'Cor principal #0A2342, cor secundaria #FFC107, fonte Montserrat.';

        $this->actingAs($admin)->putJson($this->rotaSalvar($produto), ['texto' => $texto])
            ->assertOk()->assertExactJson(['texto' => $texto]);

        $this->actingAs($admin)->getJson($this->rotaMostrar($produto))
            ->assertOk()->assertExactJson(['texto' => $texto]);
    }

    public function test_put_num_produto_e_get_em_outro_produto_da_mesma_empresa_devolve_o_mesmo_texto(): void
    {
        $company = Company::factory()->create();
        $produtoA = $this->produtoDaCompany($company, 'ID-01');
        $produtoB = $this->produtoDaCompany($company, 'ID-02');
        $admin = $this->admin();
        $texto = 'Paleta escura, acabamento fosco.';

        $this->actingAs($admin)->putJson($this->rotaSalvar($produtoA), ['texto' => $texto])->assertOk();

        $this->actingAs($admin)->getJson($this->rotaMostrar($produtoB))
            ->assertOk()->assertExactJson(['texto' => $texto]);
    }

    public function test_get_put_em_produto_de_empresa_diferente_nunca_cruza_conta(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $produtoA = $this->produtoDaCompany($companyA, 'ID-A');
        $produtoB = $this->produtoDaCompany($companyB, 'ID-B');
        $admin = $this->admin();

        $this->actingAs($admin)->putJson($this->rotaSalvar($produtoA), ['texto' => 'Identidade da empresa A'])->assertOk();

        // Antes de qualquer PUT na empresa B, o GET já devolve null (nunca o texto da A).
        $this->actingAs($admin)->getJson($this->rotaMostrar($produtoB))
            ->assertOk()->assertExactJson(['texto' => null]);

        $this->actingAs($admin)->putJson($this->rotaSalvar($produtoB), ['texto' => 'Identidade da empresa B'])->assertOk();

        // Depois do PUT da B, a A continua com o próprio texto — nunca cruza.
        $this->actingAs($admin)->getJson($this->rotaMostrar($produtoA))
            ->assertOk()->assertExactJson(['texto' => 'Identidade da empresa A']);
        $this->actingAs($admin)->getJson($this->rotaMostrar($produtoB))
            ->assertOk()->assertExactJson(['texto' => 'Identidade da empresa B']);

        $this->assertSame(2, CreativeIdentidade::count());
    }

    public function test_produto_inexistente_devolve_404(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->getJson(route('mlb.anuncios.publicador.identidade.mostrar', ['produto' => 999999]))
            ->assertStatus(404);
    }

    public function test_chave_desligada_devolve_404(): void
    {
        Configuracao::set('creative_engine_ativo', '0');
        $company = Company::factory()->create();
        $produto = $this->produtoDaCompany($company);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson($this->rotaMostrar($produto))->assertStatus(404);
        $this->actingAs($admin)->putJson($this->rotaSalvar($produto), ['texto' => 'x'])->assertStatus(404);
    }

    public function test_usuario_sem_role_admin_e_bloqueado_antes_do_controller(): void
    {
        $company = Company::factory()->create();
        $produto = $this->produtoDaCompany($company);
        $consultor = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($consultor)->getJson($this->rotaMostrar($produto))->assertStatus(403);
        $this->actingAs($consultor)->putJson($this->rotaSalvar($produto), ['texto' => 'x'])->assertStatus(403);
    }

    public function test_put_com_texto_vazio_ou_null_grava_null(): void
    {
        $company = Company::factory()->create();
        $produto = $this->produtoDaCompany($company);
        $admin = $this->admin();

        $this->actingAs($admin)->putJson($this->rotaSalvar($produto), ['texto' => null])
            ->assertOk()->assertExactJson(['texto' => null]);
    }

    public function test_put_com_texto_acima_do_limite_devolve_422(): void
    {
        $company = Company::factory()->create();
        $produto = $this->produtoDaCompany($company);
        $admin = $this->admin();

        $this->actingAs($admin)->putJson($this->rotaSalvar($produto), ['texto' => str_repeat('a', 4001)])
            ->assertStatus(422);
    }
}
