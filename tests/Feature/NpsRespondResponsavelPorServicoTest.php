<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bugfix 2026-08-18 (relatado em produção) — o card "Quem cuida da sua conta"
 * da pesquisa pública nomeava o responsável ERRADO em empresa multi-serviço.
 *
 * Quem cuida da Shopee enviou o link de um NPS de Shopee e o cliente viu ali o
 * estrategista/analista do Mercado Livre. Causa: `NpsController::respond()`
 * resolvia os nomes com `wherePivot('role', ...)->first()` sobre a empresa
 * INTEIRA, e empresa com contrato de Performance E de Shopee tem duas linhas
 * por papel em `company_users` (uma por `servico_id`) — vinha a primeira
 * gravada, quase sempre a de ML.
 *
 * A resolução passa a seguir a régua do bônus (`NpsSnapshotService::registrar`):
 * serviços COBERTOS pelo modelo ∩ contratos ATIVOS da empresa, resolvidos por
 * `Company::responsavelDoServicoOuConsolidado()`.
 *
 * @see app/Http/Controllers/NpsController.php (responsaveisDoSurvey)
 */
class NpsRespondResponsavelPorServicoTest extends TestCase
{
    use RefreshDatabase;

    private function criarServico(string $setor): int
    {
        return DB::table('servicos')->insertGetId([
            'nome'          => 'Servico ' . $setor . ' ' . uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => $setor,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function contratar(Company $company, int $servicoId, bool $ativo = true): void
    {
        DB::table('contratos_servico')->insert([
            'company_id'       => $company->id,
            'servico_id'       => $servicoId,
            'valor_contratado' => 0,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => $ativo,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    private function vincular(Company $company, User $user, string $role, ?int $servicoId): void
    {
        DB::table('company_users')->insert([
            'company_id' => $company->id,
            'user_id'    => $user->id,
            'role'       => $role,
            'servico_id' => $servicoId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function template(?int $servicoId): NpsTemplate
    {
        $template = NpsTemplate::factory()->create([
            'nome'       => 'NPS ' . uniqid(),
            'active'     => true,
            'is_default' => false,
        ]);

        if ($servicoId !== null) {
            $template->serviceScopes()->attach($servicoId);
        }

        return $template;
    }

    private function survey(Company $company, NpsTemplate $template): NpsSurvey
    {
        return NpsSurvey::factory()->create([
            'company_id'   => $company->id,
            'template_id'  => $template->id,
            'status'       => 'pending',
            'expires_at'   => now()->addDays(10),
            'completed_at' => null,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1 — o caso relatado: NPS de Shopee nomeia o time de Shopee
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_nps_de_shopee_mostra_o_responsavel_de_shopee_e_nao_o_de_ml(): void
    {
        $company = Company::factory()->create();

        $performance = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $shopee      = $this->criarServico(Servico::SETOR_SHOPEE);
        $this->contratar($company, $performance);
        $this->contratar($company, $shopee);

        $estrategistaMl     = User::factory()->create(['name' => 'Luiz do ML']);
        $analistaMl         = User::factory()->create(['name' => 'Ana do ML']);
        $estrategistaShopee = User::factory()->create(['name' => 'Felipe da Shopee']);
        $analistaShopee     = User::factory()->create(['name' => 'Matheus da Shopee']);

        // ML entra PRIMEIRO na pivot — é exatamente essa ordem que fazia o
        // ->first() antigo devolver o time errado.
        $this->vincular($company, $estrategistaMl, 'estrategista', $performance);
        $this->vincular($company, $analistaMl, 'consultor', $performance);
        $this->vincular($company, $estrategistaShopee, 'estrategista', $shopee);
        $this->vincular($company, $analistaShopee, 'consultor', $shopee);

        $survey = $this->survey($company, $this->template($shopee));

        $this->get(route('nps.respond', $survey->token))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Nps/Respond')
                ->where('survey.estrategista_name', 'Felipe da Shopee')
                ->where('survey.analista_name', 'Matheus da Shopee'));
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2 — a recíproca: o NPS de Performance da MESMA empresa segue com ML
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_nps_de_performance_da_mesma_empresa_mostra_o_time_de_ml(): void
    {
        $company = Company::factory()->create();

        $performance = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $shopee      = $this->criarServico(Servico::SETOR_SHOPEE);
        $this->contratar($company, $performance);
        $this->contratar($company, $shopee);

        $estrategistaMl     = User::factory()->create(['name' => 'Luiz do ML']);
        $analistaMl         = User::factory()->create(['name' => 'Ana do ML']);
        $estrategistaShopee = User::factory()->create(['name' => 'Felipe da Shopee']);
        $analistaShopee     = User::factory()->create(['name' => 'Matheus da Shopee']);

        // Shopee primeiro desta vez: a resolução não pode depender da ordem.
        $this->vincular($company, $estrategistaShopee, 'estrategista', $shopee);
        $this->vincular($company, $analistaShopee, 'consultor', $shopee);
        $this->vincular($company, $estrategistaMl, 'estrategista', $performance);
        $this->vincular($company, $analistaMl, 'consultor', $performance);

        $survey = $this->survey($company, $this->template($performance));

        $this->get(route('nps.respond', $survey->token))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('survey.estrategista_name', 'Luiz do ML')
                ->where('survey.analista_name', 'Ana do ML'));
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3 — sem linha do serviço, vale o vínculo CONSOLIDADO (servico_id NULL)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_cai_no_responsavel_consolidado_quando_nao_ha_linha_do_servico(): void
    {
        $company = Company::factory()->create();
        $shopee  = $this->criarServico(Servico::SETOR_SHOPEE);
        $this->contratar($company, $shopee);

        $estrategista = User::factory()->create(['name' => 'Estrategista Consolidado']);
        $this->vincular($company, $estrategista, 'estrategista', null);

        $survey = $this->survey($company, $this->template($shopee));

        $this->get(route('nps.respond', $survey->token))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('survey.estrategista_name', 'Estrategista Consolidado')
                ->where('survey.analista_name', null)
                ->where('survey.tem_analista', false));
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4 — modelo SEM serviço coberto mantém o comportamento anterior
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_modelo_sem_servico_coberto_mantem_o_vinculo_da_empresa(): void
    {
        $company     = Company::factory()->create();
        $performance = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $this->contratar($company, $performance);

        $estrategista = User::factory()->create(['name' => 'Estrategista Unico']);
        $analista     = User::factory()->create(['name' => 'Analista Unico']);
        $this->vincular($company, $estrategista, 'estrategista', $performance);
        $this->vincular($company, $analista, 'consultor', $performance);

        $survey = $this->survey($company, $this->template(null));

        $this->get(route('nps.respond', $survey->token))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('survey.estrategista_name', 'Estrategista Unico')
                ->where('survey.analista_name', 'Analista Unico'));
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5 — ninguém cuida daquele serviço: o card fica sem nome, e NÃO com o
    //     nome de quem cuida do outro setor
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_nao_vaza_responsavel_de_outro_setor_quando_ninguem_cuida_do_servico(): void
    {
        $company     = Company::factory()->create();
        $performance = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $shopee      = $this->criarServico(Servico::SETOR_SHOPEE);
        $this->contratar($company, $performance);
        $this->contratar($company, $shopee);

        // Só o time de ML está vinculado — ninguém cuida da Shopee.
        $this->vincular($company, User::factory()->create(['name' => 'Luiz do ML']), 'estrategista', $performance);
        $this->vincular($company, User::factory()->create(['name' => 'Ana do ML']), 'consultor', $performance);

        $survey = $this->survey($company, $this->template($shopee));

        $this->get(route('nps.respond', $survey->token))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('survey.estrategista_name', null)
                ->where('survey.analista_name', null));
    }
}
