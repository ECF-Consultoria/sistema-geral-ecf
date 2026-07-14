<?php

namespace Tests\Feature\V16;

use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Prova o ISOLAMENTO ML×Shopee das 3 escritas de atribuição (DEC-A3, prova "d"
 * da 76-VALIDATION):
 *  - ShopeeEmpresasController::bulkAssign grava/apaga só o slot servico_id shopee
 *    → nunca toca a linha ML (servico_id performance) e vice-versa;
 *  - CompanyController::bulkAssign e update sync gravam/apagam só o slot
 *    performance/consolidado → nunca tocam a linha Shopee;
 *  - re-atribuição consolidada (servico_id NULL) repetida NÃO deixa 2 linhas NULL
 *    do mesmo (company, role) — prova o whereNull do slot consolidado (Pitfall 1);
 *  - o guard anti-IDOR da Phase 75 continua rejeitando empresa fora do escopo
 *    Shopee com 422 sem gravar nada.
 *
 * Exercita as ROTAS reais (HTTP autenticado como admin) para cobrir validação +
 * guard. Comentários e mensagens em pt-BR, conforme convenção do projeto.
 */
class AtribuicaoPorServicoIsolamentoTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    /**
     * Loga um admin (role=admin tem permission implícita em todas as rotas).
     */
    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($admin);
        return $admin;
    }

    /**
     * Conta linhas do pivot para um slot específico (servico_id pode ser NULL).
     */
    private function contarSlot(int $companyId, string $role, ?int $servicoId): int
    {
        $q = DB::table('company_users')
            ->where('company_id', $companyId)
            ->where('role', $role);
        $servicoId === null ? $q->whereNull('servico_id') : $q->where('servico_id', $servicoId);
        return $q->count();
    }

    /**
     * Retorna o user_id gravado num slot específico (ou null se não existir).
     */
    private function userIdDoSlot(int $companyId, string $role, ?int $servicoId): ?int
    {
        $q = DB::table('company_users')
            ->where('company_id', $companyId)
            ->where('role', $role);
        $servicoId === null ? $q->whereNull('servico_id') : $q->where('servico_id', $servicoId);
        $val = $q->value('user_id');
        return $val === null ? null : (int) $val;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 1. Atribuir Shopee NÃO altera a linha ML (servico_id performance)
    // ═════════════════════════════════════════════════════════════════════════

    public function test_bulk_assign_shopee_nao_altera_linha_ml(): void
    {
        $this->actingAsAdmin();

        $cenario = $this->criarCenarioMlComResponsaveis();
        $company = $cenario['company'];
        $servicoPerf = $cenario['servicoPerf'];

        // Responsável Shopee inicial (role consultor) — cria contrato shopee ativo.
        $shopeeUserAntigo = User::factory()->create();
        $shopee = $this->inserirLinhaShopee($company->id, $shopeeUserAntigo->id, 'consultor');
        $servicoShopee = $shopee['servicoShopee'];

        // Novo responsável Shopee.
        $shopeeUserNovo = User::factory()->create();

        $response = $this->postJson('/shopee/empresas/bulk-assign', [
            'ids'     => [$company->id],
            'role'    => 'consultor',
            'user_id' => $shopeeUserNovo->id,
        ]);
        $response->assertStatus(302);

        // A linha ML (servico_id performance) permanece com o MESMO analista.
        $this->assertSame(
            $cenario['analista']->id,
            $this->userIdDoSlot($company->id, 'consultor', $servicoPerf),
            'Atribuir Shopee NÃO pode alterar o responsável ML (slot servico_id performance).'
        );
        // O slot Shopee foi trocado para o novo responsável (delete-then-attach).
        $this->assertSame(
            $shopeeUserNovo->id,
            $this->userIdDoSlot($company->id, 'consultor', $servicoShopee),
            'O slot Shopee deve apontar para o novo responsável.'
        );
        // Exatamente 1 linha por slot — sem duplicata.
        $this->assertSame(1, $this->contarSlot($company->id, 'consultor', $servicoPerf));
        $this->assertSame(1, $this->contarSlot($company->id, 'consultor', $servicoShopee));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 2. Atribuir performance (bulkAssign) NÃO altera a linha Shopee
    // ═════════════════════════════════════════════════════════════════════════

    public function test_company_bulk_assign_nao_altera_linha_shopee(): void
    {
        $this->actingAsAdmin();

        $cenario = $this->criarCenarioMlComResponsaveis();
        $company = $cenario['company'];
        $servicoPerf = $cenario['servicoPerf'];

        // Responsável Shopee (role consultor).
        $shopeeUser = User::factory()->create();
        $shopee = $this->inserirLinhaShopee($company->id, $shopeeUser->id, 'consultor');
        $servicoShopee = $shopee['servicoShopee'];

        // Novo responsável de performance.
        $perfUserNovo = User::factory()->create();

        $response = $this->postJson('/companies/bulk-assign', [
            'ids'     => [$company->id],
            'role'    => 'consultor',
            'user_id' => $perfUserNovo->id,
        ]);
        $response->assertStatus(302);

        // A linha Shopee permanece INTACTA.
        $this->assertSame(
            $shopeeUser->id,
            $this->userIdDoSlot($company->id, 'consultor', $servicoShopee),
            'Atribuir performance NÃO pode alterar o responsável Shopee (slot servico_id shopee).'
        );
        // O slot performance foi trocado para o novo responsável.
        $this->assertSame(
            $perfUserNovo->id,
            $this->userIdDoSlot($company->id, 'consultor', $servicoPerf),
            'O slot performance deve apontar para o novo responsável.'
        );
        $this->assertSame(1, $this->contarSlot($company->id, 'consultor', $servicoShopee));
        $this->assertSame(1, $this->contarSlot($company->id, 'consultor', $servicoPerf));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 3. update sync (detach escopado) NÃO apaga a linha Shopee
    // ═════════════════════════════════════════════════════════════════════════

    public function test_company_update_sync_nao_apaga_linha_shopee(): void
    {
        $this->actingAsAdmin();

        $cenario = $this->criarCenarioMlComResponsaveis();
        $company = $cenario['company'];
        $servicoPerf = $cenario['servicoPerf'];

        // Responsável Shopee (role estrategista, p/ variar o slot).
        $shopeeUser = User::factory()->create();
        $shopee = $this->inserirLinhaShopee($company->id, $shopeeUser->id, 'estrategista');
        $servicoShopee = $shopee['servicoShopee'];

        // Novos responsáveis de performance via tela de edição (update sync).
        $novoAnalista     = User::factory()->create();
        $novoEstrategista = User::factory()->create();

        $response = $this->putJson('/companies/' . $company->id, [
            'name'            => $company->name,
            'active'          => true,
            'consultor_id'    => $novoAnalista->id,
            'estrategista_id' => $novoEstrategista->id,
        ]);
        $response->assertStatus(302);

        // A linha Shopee (servico_id shopee, role estrategista) permanece INTACTA —
        // o detach() total foi substituído por detach escopado ao slot performance.
        $this->assertSame(
            $shopeeUser->id,
            $this->userIdDoSlot($company->id, 'estrategista', $servicoShopee),
            'update sync NÃO pode apagar o responsável Shopee (detach escopado, não total).'
        );
        // Os slots performance foram atualizados.
        $this->assertSame(
            $novoAnalista->id,
            $this->userIdDoSlot($company->id, 'consultor', $servicoPerf),
            'O slot performance (consultor) deve refletir o novo analista.'
        );
        $this->assertSame(
            $novoEstrategista->id,
            $this->userIdDoSlot($company->id, 'estrategista', $servicoPerf),
            'O slot performance (estrategista) deve refletir o novo estrategista.'
        );
        // Slot Shopee estrategista continua único.
        $this->assertSame(1, $this->contarSlot($company->id, 'estrategista', $servicoShopee));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 4. Re-atribuição consolidada (servico_id NULL) repetida não duplica
    // ═════════════════════════════════════════════════════════════════════════

    public function test_reatribuicao_consolidada_null_nao_duplica(): void
    {
        $this->actingAsAdmin();

        // Empresa ML PURA: sem contrato performance ativo → slot consolidado NULL.
        $company = \App\Models\Company::factory()->create();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // 2 atribuições consecutivas via bulkAssign (servico_id resolve p/ NULL).
        foreach ([$user1->id, $user2->id, $user2->id] as $uid) {
            $this->postJson('/companies/bulk-assign', [
                'ids'     => [$company->id],
                'role'    => 'consultor',
                'user_id' => $uid,
            ])->assertStatus(302);
        }

        // whereNull garante que cada atribuição APAGA a linha NULL anterior —
        // nunca sobram 2 linhas NULL do mesmo (company, role) (Pitfall 1/2).
        $this->assertSame(
            1,
            $this->contarSlot($company->id, 'consultor', null),
            'Slot consolidado (servico_id NULL) deve ter exatamente 1 linha após re-atribuições.'
        );
        $this->assertSame(
            $user2->id,
            $this->userIdDoSlot($company->id, 'consultor', null),
            'O slot consolidado deve apontar para o último responsável atribuído.'
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 5. Guard anti-IDOR (Phase 75) preservado — empresa fora do escopo → 422
    // ═════════════════════════════════════════════════════════════════════════

    public function test_guard_idor_shopee_rejeita_fora_do_escopo(): void
    {
        $this->actingAsAdmin();

        // Empresa ML pura (só contrato performance ativo, sem contrato shopee).
        $cenario = $this->criarCenarioMlComResponsaveis();
        $companyMlOnly = $cenario['company'];

        $user = User::factory()->create();

        $response = $this->postJson('/shopee/empresas/bulk-assign', [
            'ids'     => [$companyMlOnly->id],
            'role'    => 'consultor',
            'user_id' => $user->id,
        ]);
        $response->assertStatus(422);

        // Nenhuma gravação — guard fail-closed intacto.
        $this->assertDatabaseMissing('company_users', [
            'company_id' => $companyMlOnly->id,
            'user_id'    => $user->id,
        ]);
    }
}
