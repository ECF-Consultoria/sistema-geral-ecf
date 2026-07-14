<?php

namespace Tests\Feature\Phase75;

use App\Http\Controllers\ComercialController;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\MlbEmpresa;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Suite Feature — Phase 75 Plan 75-03 (DEC-1).
 *
 * PROVA que a origem única de cadastro (`ComercialController::store`) cria uma
 * empresa atendida APENAS na Shopee — sem nenhum dado ML — sem quebrar:
 *
 *  A. POST /comercial/empresas com o serviço "Shopee" cria 1 Company com
 *     adman_account_id/ml_store_id NULOS e status='pendente'.
 *  B. Cria exatamente 1 ContratoServico ATIVO cujo servico.setor === 'shopee'.
 *  C. NENHUMA MlbEmpresa é criada para essa company (zero fluxo de implementação ML).
 *  D. Regressão do Pitfall 3 — o nome EXATO "Shopee" NÃO casa nenhum prefixo ML:
 *     servicoDisparaImplementacao('Shopee') e slugSetorParaServico('Shopee') → null.
 *
 * O serviço "Shopee" é semeado pela migration 2026_07_14_100002_seed_servico_shopee
 * (roda no RefreshDatabase). Nenhuma edição de comportamento no controller é
 * esperada (a PATTERNS aponta que os helpers já retornam null para "Shopee").
 */
class Phase75CadastroShopeeTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'name'     => 'Admin Phase75-03 ' . uniqid(),
            'email'    => 'admin.p75-03.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);
        return $admin;
    }

    /**
     * Recupera o serviço "Shopee" semeado pela migration de seed (setor 'shopee').
     * firstOrCreate garante presença mesmo se a ordem de execução variar.
     */
    private function servicoShopee(): Servico
    {
        return Servico::firstOrCreate(
            ['nome' => 'Shopee'],
            [
                'valor_padrao'  => 0,
                'tipo_cobranca' => Servico::TIPO_MENSAL,
                'ativo'         => true,
                'setor'         => Servico::SETOR_SHOPEE,
            ],
        );
    }

    /**
     * Payload mínimo aceito por ComercialController::store() com o serviço Shopee.
     */
    private function payloadShopee(array $overrides = []): array
    {
        return array_merge([
            'nome'     => 'Empresa Shopee P75-03 ' . uniqid(),
            'servicos' => [
                ['servico_id' => $this->servicoShopee()->id],
            ],
        ], $overrides);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // A. Company criada SEM dado ML (adman_account_id/ml_store_id nulos)
    // ═════════════════════════════════════════════════════════════════════════

    public function test_cadastro_shopee_cria_company_sem_dado_ml(): void
    {
        $this->actingAsAdmin();

        $payload = $this->payloadShopee();

        $response = $this->post(route('comercial.empresas.store'), $payload);

        // Sem exception — o store completa e redireciona de volta com sucesso.
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        $company = Company::where('name', $payload['nome'])->first();
        $this->assertNotNull($company, 'A Company Shopee deveria ter sido criada.');
        $this->assertNull($company->adman_account_id, 'adman_account_id deve ser NULO para empresa Shopee.');
        $this->assertNull($company->ml_store_id, 'ml_store_id deve ser NULO para empresa Shopee.');
        $this->assertSame('pendente', $company->status, "status inicial deve ser 'pendente'.");
    }

    // ═════════════════════════════════════════════════════════════════════════
    // B. Exatamente 1 ContratoServico ativo de setor 'shopee'
    // ═════════════════════════════════════════════════════════════════════════

    public function test_cadastro_shopee_cria_um_contrato_ativo_setor_shopee(): void
    {
        $this->actingAsAdmin();

        $payload = $this->payloadShopee();

        $this->post(route('comercial.empresas.store'), $payload)->assertSessionHasNoErrors();

        $company = Company::where('name', $payload['nome'])->firstOrFail();

        $contratos = ContratoServico::where('company_id', $company->id)->get();
        $this->assertCount(1, $contratos, 'Deve existir exatamente 1 ContratoServico.');

        $contrato = $contratos->first();
        $this->assertTrue((bool) $contrato->ativo, 'O contrato deve estar ativo.');
        $this->assertSame(
            Servico::SETOR_SHOPEE,
            $contrato->servico->setor,
            "O serviço do contrato deve ser do setor 'shopee'.",
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // C. NENHUMA MlbEmpresa criada para a company Shopee
    // ═════════════════════════════════════════════════════════════════════════

    public function test_cadastro_shopee_nao_cria_mlb_empresa(): void
    {
        $this->actingAsAdmin();

        $payload = $this->payloadShopee();

        $this->post(route('comercial.empresas.store'), $payload)->assertSessionHasNoErrors();

        $company = Company::where('name', $payload['nome'])->firstOrFail();

        $this->assertSame(
            0,
            MlbEmpresa::where('company_id', $company->id)->count(),
            'Empresa Shopee NÃO deve gerar nenhuma MlbEmpresa (fluxo de implementação ML não dispara).',
        );
        $this->assertDatabaseMissing('mlb_empresas', ['company_id' => $company->id]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // D. Regressão Pitfall 3 — nome exato "Shopee" não casa prefixos ML
    // ═════════════════════════════════════════════════════════════════════════

    public function test_helpers_de_roteamento_ml_retornam_null_para_shopee(): void
    {
        // Helper público estático — dispara criação de MlbEmpresa quando != null.
        $this->assertNull(
            ComercialController::servicoDisparaImplementacao('Shopee'),
            "servicoDisparaImplementacao('Shopee') deve retornar null (não dispara MlbEmpresa).",
        );

        // Helper privado — notifica líder de setor ML quando != null. Reflection
        // pois é private; a asserção trava a regressão do nome (renomear no futuro).
        $ref = new ReflectionMethod(ComercialController::class, 'slugSetorParaServico');
        $ref->setAccessible(true);
        $slug = $ref->invoke(new ComercialController(), 'Shopee');

        $this->assertNull(
            $slug,
            "slugSetorParaServico('Shopee') deve retornar null (nenhum setor ML notificado).",
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Guard extra — o serviço "Shopee" está disponível no wizard do Comercial
    // ═════════════════════════════════════════════════════════════════════════

    public function test_servico_shopee_aparece_em_servicos_disponiveis(): void
    {
        $this->actingAsAdmin();

        $response = $this->get(route('comercial.empresas.novo'));
        $response->assertOk();

        $servicos = collect($response->viewData('page')['props']['servicos_disponiveis']);
        $this->assertTrue(
            $servicos->contains(fn($s) => $s['nome'] === 'Shopee'),
            "O serviço 'Shopee' deve aparecer em servicos_disponiveis do wizard.",
        );
    }
}
