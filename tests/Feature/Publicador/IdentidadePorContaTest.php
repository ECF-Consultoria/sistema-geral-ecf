<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\CreativeIdentidade;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 173-01 Task 3: `GET/PUT publicador/empresas/{conta}/identidade` —
 * `MlbPublicadorIdentidadeController::mostrarPorConta()/salvarPorConta()`.
 *
 * Mesmo registro (`creative_identidades_conta`) e mesma tabela da versão por
 * produto (Fase 170) — critério de aceite: identidade editada em Configurações
 * (por conta) aparece no editor (por produto) e vice-versa. Diferente da versão
 * por produto, a versão por conta NÃO exige `CreativeEngineAtivo` (decisão deste
 * plano — Configurações é tela geral, não parte do fluxo de criativos).
 */
class IdentidadePorContaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function produtoDaCompany(Company $company, string $sku = 'ID-01'): PubProduto
    {
        return PubProduto::create([
            'company_id' => $company->id,
            'sku' => $sku,
            'nome' => 'Produto Teste Identidade Conta',
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
    }

    private function rotaMostrarProduto(PubProduto $produto): string
    {
        return route('mlb.anuncios.publicador.identidade.mostrar', ['produto' => $produto->id]);
    }

    private function rotaSalvarProduto(PubProduto $produto): string
    {
        return route('mlb.anuncios.publicador.identidade.salvar', ['produto' => $produto->id]);
    }

    private function rotaMostrarConta(string $conta): string
    {
        return route('mlb.anuncios.publicador.conta.identidade.mostrar', ['conta' => $conta]);
    }

    private function rotaSalvarConta(string $conta): string
    {
        return route('mlb.anuncios.publicador.conta.identidade.salvar', ['conta' => $conta]);
    }

    public function test_gravar_por_produto_e_ler_pela_conta_devolve_o_mesmo_texto(): void
    {
        // Rota por produto exige CreativeEngineAtivo; a por conta, não.
        Configuracao::set('creative_engine_ativo', '1');
        $company = Company::factory()->create();
        $produto = $this->produtoDaCompany($company);
        $admin = $this->admin();
        $texto = 'Cor principal #0A2342, fonte Montserrat.';

        $this->actingAs($admin)->putJson($this->rotaSalvarProduto($produto), ['texto' => $texto])->assertOk();

        $this->actingAs($admin)->getJson($this->rotaMostrarConta('company-'.$company->id))
            ->assertOk()->assertJson(['texto' => $texto])
            ->assertJsonStructure(['texto', 'atualizado_em']);
    }

    public function test_gravar_pela_conta_e_ler_pelo_produto_devolve_o_mesmo_texto(): void
    {
        Configuracao::set('creative_engine_ativo', '1');
        $company = Company::factory()->create();
        $produto = $this->produtoDaCompany($company);
        $admin = $this->admin();
        $texto = 'Paleta escura, acabamento fosco.';

        $this->actingAs($admin)->putJson($this->rotaSalvarConta('company-'.$company->id), ['texto' => $texto])
            ->assertOk()->assertJson(['texto' => $texto]);

        $this->actingAs($admin)->getJson($this->rotaMostrarProduto($produto))
            ->assertOk()->assertExactJson(['texto' => $texto]);

        $this->assertSame(1, CreativeIdentidade::count(), 'é o MESMO registro, nunca dois');
    }

    public function test_gravar_e_ler_pela_conta_de_mlb_empresa_sem_company(): void
    {
        $e = MlbEmpresa::create(['nome' => 'Polo Teste', 'projeto' => 'POLOS']);
        MlToken::create([
            'mlb_empresa_id' => $e->id, 'ml_user_id' => '123456', 'access_token' => 'x',
            'refresh_token' => 'y', 'expires_at' => now()->addHours(5), 'status' => 'active',
        ]);
        $admin = $this->admin();
        $texto = 'Identidade da MlbEmpresa pura.';

        $this->actingAs($admin)->putJson($this->rotaSalvarConta('empresa-'.$e->id), ['texto' => $texto])
            ->assertOk()->assertJson(['texto' => $texto]);

        $this->actingAs($admin)->getJson($this->rotaMostrarConta('empresa-'.$e->id))
            ->assertOk()->assertJson(['texto' => $texto]);
    }

    public function test_conta_inexistente_da_404(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->getJson($this->rotaMostrarConta('empresa-999999'))->assertNotFound();
        $this->actingAs($admin)->getJson($this->rotaMostrarConta('company-999999'))->assertNotFound();
        $this->actingAs($admin)->putJson($this->rotaSalvarConta('empresa-999999'), ['texto' => 'x'])->assertNotFound();
    }

    public function test_conta_arquivada_da_404(): void
    {
        $e = MlbEmpresa::create(['nome' => 'Arquivada', 'projeto' => 'POLOS', 'arquivado_em' => now()]);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson($this->rotaMostrarConta('empresa-'.$e->id))->assertNotFound();
    }

    public function test_texto_acima_do_limite_e_rejeitado_com_422(): void
    {
        $company = Company::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson($this->rotaSalvarConta('company-'.$company->id), ['texto' => str_repeat('a', 4001)])
            ->assertStatus(422);
    }

    public function test_atualizado_em_vem_null_antes_de_qualquer_registro_e_preenchido_depois_de_salvar(): void
    {
        $company = Company::factory()->create();
        $admin = $this->admin();

        $antes = $this->actingAs($admin)->getJson($this->rotaMostrarConta('company-'.$company->id))->assertOk();
        $this->assertNull($antes->json('atualizado_em'));
        $this->assertNull($antes->json('texto'));

        $depois = $this->actingAs($admin)->putJson($this->rotaSalvarConta('company-'.$company->id), ['texto' => 'Texto novo'])
            ->assertOk();
        $this->assertNotNull($depois->json('atualizado_em'));

        $relido = $this->actingAs($admin)->getJson($this->rotaMostrarConta('company-'.$company->id))->assertOk();
        $this->assertNotNull($relido->json('atualizado_em'));
        $this->assertSame($depois->json('atualizado_em'), $relido->json('atualizado_em'));
    }

    public function test_identidade_por_conta_nao_exige_creative_engine_ativo(): void
    {
        // Decisão deste plano: diferente da versão por produto, a por conta NUNCA
        // checa CreativeEngineAtivo — Configuracao nem é setada aqui de propósito.
        $company = Company::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->getJson($this->rotaMostrarConta('company-'.$company->id))->assertOk();
        $this->actingAs($admin)->putJson($this->rotaSalvarConta('company-'.$company->id), ['texto' => 'x'])->assertOk();
    }

    public function test_nao_admin_recebe_403(): void
    {
        $company = Company::factory()->create();
        $consultor = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($consultor)->getJson($this->rotaMostrarConta('company-'.$company->id))->assertForbidden();
        $this->actingAs($consultor)->putJson($this->rotaSalvarConta('company-'.$company->id), ['texto' => 'x'])->assertForbidden();
    }
}
