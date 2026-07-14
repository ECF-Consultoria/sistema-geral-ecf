<?php

namespace Tests\Feature\V16;

use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Phase 78 (DEC-78-1): a aba /shopee/empresas lista nos selects SOMENTE os
 * analistas/estrategistas do Setor Shopee, e a pendência sem_responsavel é
 * POR-SERVIÇO Shopee (não consolidada).
 */
class ShopeeSelectsEscopadosTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    /**
     * O Setor Shopee + cargos analista/estrategista JÁ são criados pela migration
     * da Phase 77 (roda no RefreshDatabase). Aqui só resolvemos os ids.
     * Retorna [setorId, cargoAnalista, cargoEstrategista].
     */
    private function criarSetorShopee(): array
    {
        $setorId = (int) DB::table('setores')->where('slug', 'shopee')->value('id');
        $ana = (int) DB::table('cargos')->where('setor_id', $setorId)->where('slug', 'analista')->value('id');
        $est = (int) DB::table('cargos')->where('setor_id', $setorId)->where('slug', 'estrategista')->value('id');
        return [$setorId, $ana, $est];
    }

    /** Setor Performance (idempotente — pode ou não já existir no banco de teste). */
    private function setorPerformance(): array
    {
        $setorId = (int) DB::table('setores')->where('slug', 'performance')->value('id');
        if (! $setorId) {
            $setorId = (int) DB::table('setores')->insertGetId([
                'nome' => 'Performance', 'slug' => 'performance', 'active' => true, 'is_system' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $ana = (int) DB::table('cargos')->where('setor_id', $setorId)->where('slug', 'analista')->value('id');
        if (! $ana) {
            $ana = (int) DB::table('cargos')->insertGetId([
                'setor_id' => $setorId, 'nome' => 'Analista', 'slug' => 'analista', 'ordem' => 0, 'active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return [$setorId, $ana];
    }

    private function vincularAoSetor(int $userId, int $setorId, int $cargoId): void
    {
        DB::table('user_setores')->insert([
            'user_id' => $userId, 'setor_id' => $setorId, 'cargo_id' => $cargoId, 'is_principal' => false,
            'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_selects_listam_apenas_profissionais_do_setor_shopee(): void
    {
        [$setorShopee, $cargoAna] = $this->criarSetorShopee();

        // Setor Performance com cargo analista (para o contraste).
        [$setorPerf, $cargoAnaPerf] = $this->setorPerformance();

        $analistaShopee = User::factory()->create(['active' => true, 'name' => 'Ana Shopee']);
        $this->vincularAoSetor($analistaShopee->id, $setorShopee, $cargoAna);

        $analistaSoPerf = User::factory()->create(['active' => true, 'name' => 'Bruno Perf']);
        $this->vincularAoSetor($analistaSoPerf->id, $setorPerf, $cargoAnaPerf);

        // Uma empresa Shopee para a aba existir.
        $company = Company::factory()->create();
        $servShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->criarContrato($company->id, $servShopee, true);

        $this->actingAs($this->admin())
            ->get(route('shopee.empresas.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shopee/Empresas')
                ->where('analistas', fn ($a) => collect($a)->pluck('id')->contains($analistaShopee->id)
                    && ! collect($a)->pluck('id')->contains($analistaSoPerf->id))
            );
    }

    public function test_pendencia_sem_responsavel_e_por_servico_shopee(): void
    {
        $this->criarSetorShopee();

        // Empresa ML com analista/estrategista ML (servico_id performance).
        $cenario = $this->criarCenarioMlComResponsaveis();
        $company = $cenario['company'];

        // Adiciona contrato Shopee SEM responsável Shopee → deve aparecer pendente.
        // (usar o MESMO servico_id no contrato e depois na pivot — há um serviço
        // "Shopee" pré-semeado que não deve ser confundido).
        $servShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->criarContrato($company->id, $servShopee, true);

        $this->actingAs($this->admin())
            ->get(route('shopee.empresas.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('companies', function ($companies) use ($company) {
                    $e = collect($companies)->firstWhere('id', $company->id);
                    return $e !== null && in_array('sem_responsavel', $e['pendencias'], true);
                })
            );

        // Agora atribui um analista Shopee (MESMO servico_id do contrato) → pendência some.
        $this->inserirPivot($company->id, $cenario['analista']->id, 'consultor', $servShopee);

        $this->actingAs($this->admin())
            ->get(route('shopee.empresas.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('companies', function ($companies) use ($company) {
                    $e = collect($companies)->firstWhere('id', $company->id);
                    return $e !== null && ! in_array('sem_responsavel', $e['pendencias'], true);
                })
            );
    }
}
