<?php

namespace Tests\Feature\Phase75;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Suite Feature — Phase 75 Plan 75-04 (DEC-2 / DEC-4).
 *
 * Cobre o backend da aba "Empresas" da Shopee (ShopeeEmpresasController):
 * filtro por contrato de setor 'shopee', payload enxuto (sem métrica/cust_id/
 * grant), pendências mínimas voltadas ao NPS, atribuição de responsáveis no
 * pivot company_users e o gate RBAC dedicado (permission:shopee.empresas).
 *
 * Molde: Phase37CompaniesPerformanceFilterTest (aba ML), adaptado para Shopee
 * SEM whereDoesntHave('mlbEmpresa') — empresa multi-marketplace aparece nas
 * DUAS abas (comportamento correto, não bug).
 *
 * Cenários cobertos:
 *   1. Filtro — contrato shopee ativo APARECE
 *   2. Contrato só publicacao NÃO aparece
 *   3. Contrato shopee INATIVO NÃO aparece
 *   4. Empresa sem contratos NÃO aparece
 *   5. Multi-marketplace (performance + shopee) APARECE na aba Shopee
 *   6. Payload enxuto — sem chaves de métrica/cust_id/grant
 *   7. Pendências completas — [sem_responsavel, sem_contato, empresa_nova]
 *   8. Pendências resolvidas — com responsável + contato não retornam
 *   9. sem_contato só quando email E digisac ambos vazios
 *  10. Atribuição — role=consultor grava pivot consultor
 *  11. Atribuição — role=estrategista grava pivot estrategista
 *  12. Atribuição — role inválida → 422
 *  13. Guard anti-IDOR (W1/T-75-10) — ID fora do escopo shopee → 422, sem pivot
 *  14. Gate — 403 sem a key
 *  15. Gate — 200 para admin
 *  16. Gate — 200 para user de setor com a key shopee.empresas
 */
class Phase75ShopeeEmpresasTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'name'     => 'Admin P75-04 ' . uniqid(),
            'email'    => 'admin.p75-04.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);
        return $admin;
    }

    private function criarServico(string $nome, string $setor, float $valor = 1500.0): Servico
    {
        return Servico::create([
            'nome'          => $nome . ' ' . uniqid(),
            'valor_padrao'  => $valor,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => $setor,
        ]);
    }

    /**
     * Empresa "Shopee" — SEM dados ML (adman_account_id/ml_store_id nulos),
     * sem contato e não-nova por padrão (defaults controlados pelos testes de
     * pendência). Overrides ajustam email_cliente/digisac/empresa_nova.
     */
    private function criarEmpresa(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name'                     => 'Empresa Shopee ' . uniqid(),
            'cnpj'                     => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active'                   => true,
            'status'                   => 'ativo',
            'email_cliente'            => null,
            'digisac_group_contact_id' => null,
            'empresa_nova'             => false,
        ], $overrides));
    }

    private function criarContrato(Company $c, Servico $s, bool $ativo = true): ContratoServico
    {
        return ContratoServico::create([
            'company_id'       => $c->id,
            'servico_id'       => $s->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => $ativo,
        ]);
    }

    /**
     * GET /shopee/empresas como requisição Inertia XHR — retorna JSON com as
     * props, SEM renderizar o blade (que exige o asset da página no manifest
     * Vite; a página React é o Plan 75-05, este plan é backend-only).
     */
    private function getEmpresas()
    {
        $version = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
        return $this->withHeaders([
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => (string) $version,
        ])->get('/shopee/empresas');
    }

    private function payloadCompanies($response): \Illuminate\Support\Collection
    {
        return collect($response->json('props.companies'));
    }

    /**
     * Cria um user (não-admin) membro de um setor que possui a permission key
     * shopee.empresas — prova o gate positivo sem depender de admin.
     */
    private function criarUserComSetorShopee(): User
    {
        $setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Shopee',
            'slug'       => 'shopee',
            'active'     => true,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Libera a permission key ao setor.
        DB::table('setor_permissoes')->insert([
            'setor_id'       => $setorId,
            'permission_key' => 'shopee.empresas',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $cargoId = DB::table('cargos')->insertGetId([
            'setor_id'   => $setorId,
            'nome'       => 'Analista Shopee',
            'slug'       => 'analista',
            'active'     => true,
            'ordem'      => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::create([
            'name'     => 'User Shopee ' . uniqid(),
            'email'    => 'user.shopee.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'consultor',
            'active'   => true,
        ]);

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $setorId,
            'cargo_id'     => $cargoId,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $user;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 1. Filtro — contrato shopee ativo APARECE
    // ═════════════════════════════════════════════════════════════════════════

    public function test_empresa_com_contrato_shopee_aparece(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Shopee', Servico::SETOR_SHOPEE);
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, true);

        $response = $this->getEmpresas();
        $response->assertStatus(200);

        $ids = $this->payloadCompanies($response)->pluck('id');
        $this->assertContains($empresa->id, $ids->all(),
            'Empresa com contrato Shopee ativo deve aparecer na aba Shopee');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 2. Contrato só publicacao NÃO aparece
    // ═════════════════════════════════════════════════════════════════════════

    public function test_empresa_com_contrato_publicacao_nao_aparece(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Publicidade ML', Servico::SETOR_PUBLICACAO);
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, true);

        $response = $this->getEmpresas();
        $ids = $this->payloadCompanies($response)->pluck('id');

        $this->assertNotContains($empresa->id, $ids->all(),
            'Empresa só com contrato Publicacao NÃO deve aparecer na aba Shopee');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 3. Contrato shopee INATIVO NÃO aparece
    // ═════════════════════════════════════════════════════════════════════════

    public function test_empresa_com_contrato_shopee_inativo_nao_aparece(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Shopee', Servico::SETOR_SHOPEE);
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, false); // inativo

        $response = $this->getEmpresas();
        $ids = $this->payloadCompanies($response)->pluck('id');

        $this->assertNotContains($empresa->id, $ids->all(),
            'Empresa com contrato Shopee INATIVO NÃO deve aparecer (filtro exige ativo=true)');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 4. Empresa sem contratos NÃO aparece
    // ═════════════════════════════════════════════════════════════════════════

    public function test_empresa_sem_contratos_nao_aparece(): void
    {
        $this->actingAsAdmin();
        $empresa = $this->criarEmpresa();

        $response = $this->getEmpresas();
        $ids = $this->payloadCompanies($response)->pluck('id');

        $this->assertNotContains($empresa->id, $ids->all(),
            'Empresa sem contratos NÃO deve aparecer (filtro exige >=1 contrato Shopee ativo)');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 5. Multi-marketplace (performance + shopee) APARECE na aba Shopee
    // ═════════════════════════════════════════════════════════════════════════

    public function test_empresa_multi_marketplace_aparece_na_aba_shopee(): void
    {
        $this->actingAsAdmin();
        $performance = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);
        $shopee      = $this->criarServico('Shopee', Servico::SETOR_SHOPEE);

        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $performance, true);
        $this->criarContrato($empresa, $shopee, true);

        $response = $this->getEmpresas();
        $ids = $this->payloadCompanies($response)->pluck('id');

        $this->assertContains($empresa->id, $ids->all(),
            'Empresa com contrato ML (performance) E Shopee DEVE aparecer na aba Shopee '
            . '(sem whereDoesntHave mlbEmpresa — comportamento correto multi-marketplace)');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 6. Payload enxuto — sem chaves de métrica/cust_id/grant
    // ═════════════════════════════════════════════════════════════════════════

    public function test_payload_enxuto_sem_chaves_ml(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Shopee', Servico::SETOR_SHOPEE);
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, true);

        $response = $this->getEmpresas();
        $alvo = $this->payloadCompanies($response)->firstWhere('id', $empresa->id);

        $this->assertNotNull($alvo, 'Empresa deve estar no payload');
        foreach (['cust_id_status', 'adman_account_id', 'adman_store_id', 'ml_store_id', 'ml_token_status', 'grants_ativos_count'] as $chave) {
            $this->assertArrayNotHasKey($chave, $alvo,
                "Payload da aba Shopee NÃO deve conter a chave '{$chave}' (ML-free)");
        }
        // Sanity — chaves essenciais preservadas
        foreach (['id', 'name', 'email_cliente', 'empresa_nova', 'pendencias'] as $chave) {
            $this->assertArrayHasKey($chave, $alvo, "Payload deve preservar '{$chave}'");
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 7. Pendências completas — [sem_responsavel, sem_contato, empresa_nova]
    // ═════════════════════════════════════════════════════════════════════════

    public function test_pendencias_completas_da_dec2(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Shopee', Servico::SETOR_SHOPEE);
        // Sem responsável, sem email/digisac, empresa nova.
        $empresa = $this->criarEmpresa(['empresa_nova' => true]);
        $this->criarContrato($empresa, $servico, true);

        $response = $this->getEmpresas();
        $alvo = $this->payloadCompanies($response)->firstWhere('id', $empresa->id);

        $this->assertNotNull($alvo);
        $this->assertSame(['sem_responsavel', 'sem_contato', 'empresa_nova'], $alvo['pendencias'],
            'Pendências devem ser exatamente as 3 da DEC-2 na ordem do array_filter');
        // NUNCA pendências de métrica/cust_id/grant
        $this->assertNotContains('sem_cust_id', $alvo['pendencias']);
        $this->assertNotContains('sem_grant_ativo', $alvo['pendencias']);
        $this->assertNotContains('sem_email_colaborador', $alvo['pendencias']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 8. Pendências resolvidas — com responsável + contato não retornam
    // ═════════════════════════════════════════════════════════════════════════

    public function test_pendencias_resolvidas_nao_retornam(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Shopee', Servico::SETOR_SHOPEE);
        $empresa = $this->criarEmpresa(['email_cliente' => 'cliente@ecf.test']);
        $this->criarContrato($empresa, $servico, true);

        // Estrategista atribuído
        $estrategista = User::create([
            'name' => 'Estr ' . uniqid(), 'email' => 'estr.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'consultor', 'active' => true,
        ]);
        $empresa->users()->attach($estrategista->id, ['role' => 'estrategista', 'assigned_at' => now()->toDateString()]);

        $response = $this->getEmpresas();
        $alvo = $this->payloadCompanies($response)->firstWhere('id', $empresa->id);

        $this->assertNotNull($alvo);
        $this->assertNotContains('sem_responsavel', $alvo['pendencias'],
            'Com estrategista atribuído não deve ter sem_responsavel');
        $this->assertNotContains('sem_contato', $alvo['pendencias'],
            'Com email_cliente preenchido não deve ter sem_contato');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 9. sem_contato só quando email E digisac ambos vazios
    // ═════════════════════════════════════════════════════════════════════════

    public function test_sem_contato_so_quando_ambos_vazios(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Shopee', Servico::SETOR_SHOPEE);

        // Só email preenchido → NÃO tem sem_contato
        $comEmail = $this->criarEmpresa(['email_cliente' => 'a@ecf.test']);
        $this->criarContrato($comEmail, $servico, true);

        // Só digisac preenchido → NÃO tem sem_contato
        $comDigisac = $this->criarEmpresa(['digisac_group_contact_id' => 'grp-123']);
        $this->criarContrato($comDigisac, $servico, true);

        // Ambos vazios → TEM sem_contato
        $semNada = $this->criarEmpresa();
        $this->criarContrato($semNada, $servico, true);

        $response = $this->getEmpresas();
        $payload = $this->payloadCompanies($response);

        $this->assertNotContains('sem_contato', $payload->firstWhere('id', $comEmail->id)['pendencias'],
            'email preenchido resolve sem_contato');
        $this->assertNotContains('sem_contato', $payload->firstWhere('id', $comDigisac->id)['pendencias'],
            'digisac preenchido resolve sem_contato');
        $this->assertContains('sem_contato', $payload->firstWhere('id', $semNada->id)['pendencias'],
            'email E digisac vazios → sem_contato presente');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 10 + 11. Atribuição — pivot consultor / estrategista
    // ═════════════════════════════════════════════════════════════════════════

    public function test_bulk_assign_grava_pivot_consultor(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Shopee', Servico::SETOR_SHOPEE);
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, true);

        $analista = User::create([
            'name' => 'Analista ' . uniqid(), 'email' => 'an.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'consultor', 'active' => true,
        ]);

        $response = $this->postJson('/shopee/empresas/bulk-assign', [
            'ids'     => [$empresa->id],
            'role'    => 'consultor',
            'user_id' => $analista->id,
        ]);
        $response->assertStatus(302);

        $this->assertDatabaseHas('company_users', [
            'company_id' => $empresa->id,
            'user_id'    => $analista->id,
            'role'       => 'consultor',
        ]);
    }

    public function test_bulk_assign_grava_pivot_estrategista(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Shopee', Servico::SETOR_SHOPEE);
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, true);

        $estrategista = User::create([
            'name' => 'Estr ' . uniqid(), 'email' => 'estr.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'consultor', 'active' => true,
        ]);

        $response = $this->postJson('/shopee/empresas/bulk-assign', [
            'ids'     => [$empresa->id],
            'role'    => 'estrategista',
            'user_id' => $estrategista->id,
        ]);
        $response->assertStatus(302);

        $this->assertDatabaseHas('company_users', [
            'company_id' => $empresa->id,
            'user_id'    => $estrategista->id,
            'role'       => 'estrategista',
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 12. Atribuição — role inválida → 422
    // ═════════════════════════════════════════════════════════════════════════

    public function test_bulk_assign_role_invalida_rejeitada(): void
    {
        $this->actingAsAdmin();
        $servico = $this->criarServico('Shopee', Servico::SETOR_SHOPEE);
        $empresa = $this->criarEmpresa();
        $this->criarContrato($empresa, $servico, true);

        $user = User::create([
            'name' => 'X ' . uniqid(), 'email' => 'x.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'consultor', 'active' => true,
        ]);

        $response = $this->postJson('/shopee/empresas/bulk-assign', [
            'ids'     => [$empresa->id],
            'role'    => 'admin', // fora de in:consultor,estrategista
            'user_id' => $user->id,
        ]);
        $response->assertStatus(422);
        $this->assertDatabaseMissing('company_users', ['company_id' => $empresa->id, 'user_id' => $user->id]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 13. Guard anti-IDOR (W1/T-75-10) — ID fora do escopo shopee → 422, sem pivot
    // ═════════════════════════════════════════════════════════════════════════

    public function test_bulk_assign_rejeita_empresa_fora_do_escopo_shopee(): void
    {
        $this->actingAsAdmin();
        $shopeeServ = $this->criarServico('Shopee', Servico::SETOR_SHOPEE);
        $perfServ   = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);

        // Empresa Shopee legítima
        $empresaShopee = $this->criarEmpresa();
        $this->criarContrato($empresaShopee, $shopeeServ, true);

        // Empresa ML-only (só contrato performance, sem contrato shopee)
        $empresaMl = $this->criarEmpresa();
        $this->criarContrato($empresaMl, $perfServ, true);

        $user = User::create([
            'name' => 'Y ' . uniqid(), 'email' => 'y.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'consultor', 'active' => true,
        ]);

        // Request com ID forjado de empresa ML-only misturado → rejeição total (422)
        $response = $this->postJson('/shopee/empresas/bulk-assign', [
            'ids'     => [$empresaShopee->id, $empresaMl->id],
            'role'    => 'estrategista',
            'user_id' => $user->id,
        ]);
        $response->assertStatus(422);

        // Nenhum pivot criado — nem para a Shopee válida (semântica fail-closed:
        // request inteiro rejeitado quando qualquer ID está fora do escopo).
        $this->assertDatabaseMissing('company_users', ['company_id' => $empresaMl->id, 'user_id' => $user->id]);
        $this->assertDatabaseMissing('company_users', ['company_id' => $empresaShopee->id, 'user_id' => $user->id]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 14/15/16. Gate RBAC (permission:shopee.empresas)
    // ═════════════════════════════════════════════════════════════════════════

    public function test_gate_403_sem_a_key(): void
    {
        $semKey = User::create([
            'name' => 'Sem Key ' . uniqid(), 'email' => 'semkey.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'consultor', 'active' => true,
        ]);

        $this->actingAs($semKey)->get('/shopee/empresas')->assertStatus(403);
    }

    public function test_gate_200_para_admin(): void
    {
        $this->actingAsAdmin();
        $this->getEmpresas()->assertStatus(200);
    }

    public function test_gate_200_para_user_de_setor_com_a_key(): void
    {
        $user = $this->criarUserComSetorShopee();
        $this->actingAs($user);
        $this->getEmpresas()->assertStatus(200);
    }
}
