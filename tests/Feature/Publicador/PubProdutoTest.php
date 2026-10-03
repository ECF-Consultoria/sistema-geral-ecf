<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Support\Publicador\RegraViolada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Fase 164-01: a âncora de produto do Publicador (D15) e a oferta solta (D27). */
class PubProdutoTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $ancora, string $status = 'active'): MlToken
    {
        return MlToken::create($ancora + [
            'ml_user_id' => (string) random_int(1000, 999999),
            'access_token' => 'APP_USR-x',
            'refresh_token' => 'TG-x',
            'expires_at' => now()->addHours(5),
            'status' => $status,
        ]);
    }

    private function oferta(Company $company, string $sku = 'CAD-01', string $nome = 'Cadeira'): EstruturaOferta
    {
        return EstruturaOferta::create(['company_id' => $company->id, 'sku' => $sku, 'fase' => 'simples', 'nome' => $nome]);
    }

    public function test_token_ativo_na_mlb_empresa_vence_o_da_company(): void
    {
        $company = Company::factory()->create();
        $empresa = MlbEmpresa::create(['nome' => 'Loja Polos', 'projeto' => 'Incubadora']);
        $this->token(['company_id' => $company->id]);
        $this->token(['mlb_empresa_id' => $empresa->id]);

        $produto = PubProduto::create(['company_id' => $company->id, 'mlb_empresa_id' => $empresa->id, 'sku' => 'A', 'nome' => 'A']);

        $this->assertSame($empresa->chaveContaMl(), $produto->conta()->chaveContaMl());
    }

    public function test_sem_token_na_empresa_cai_na_company(): void
    {
        $company = Company::factory()->create();
        $empresa = MlbEmpresa::create(['nome' => 'Loja Sem Token', 'projeto' => 'Incubadora']);
        $this->token(['company_id' => $company->id]);

        $produto = PubProduto::create(['company_id' => $company->id, 'mlb_empresa_id' => $empresa->id, 'sku' => 'A', 'nome' => 'A']);

        $this->assertSame($company->chaveContaMl(), $produto->conta()->chaveContaMl());
    }

    public function test_token_revogado_e_ignorado_e_sem_token_lanca_v_acc_01(): void
    {
        $company = Company::factory()->create();
        $this->token(['company_id' => $company->id], 'revoked');
        $produto = PubProduto::create(['company_id' => $company->id, 'sku' => 'A', 'nome' => 'A']);

        $this->assertNull($produto->contaOuNula());
        try {
            $produto->conta();
            $this->fail('Esperava RegraViolada V-ACC-01');
        } catch (RegraViolada $e) {
            $this->assertSame('V-ACC-01', $e->regra);
        }
    }

    public function test_da_oferta_e_idempotente_e_de_origem_portal(): void
    {
        $company = Company::factory()->create();
        $oferta = $this->oferta($company);

        $a = PubProduto::daOferta($oferta);
        $b = PubProduto::daOferta($oferta);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(PubProduto::ORIGEM_PORTAL, $a->origem);
        $this->assertSame($company->id, $a->company_id);
        $this->assertSame('CAD-01', $a->sku);
        $this->assertSame(1, PubProduto::count());
    }

    public function test_sku_e_nome_exibidos_seguem_a_oferta_ao_vivo(): void
    {
        $oferta = $this->oferta(Company::factory()->create());
        $produto = PubProduto::daOferta($oferta);

        $oferta->update(['sku' => 'CAD-99', 'nome' => 'Cadeira Nova']);

        $this->assertSame('CAD-99', $produto->fresh()->skuExibido());
        $this->assertSame('Cadeira Nova', $produto->fresh()->nomeExibido());
    }

    public function test_apagar_a_oferta_solta_o_produto_e_o_exibido_vira_o_do_produto(): void
    {
        $oferta = $this->oferta(Company::factory()->create());
        $produto = PubProduto::daOferta($oferta);

        $oferta->delete();

        $produto = $produto->fresh();
        $this->assertNotNull($produto);
        $this->assertNull($produto->oferta_id);
        $this->assertSame('CAD-01', $produto->skuExibido());
        $this->assertSame('Cadeira', $produto->nomeExibido());
    }
}
