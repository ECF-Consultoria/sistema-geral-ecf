<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ContrataServicoNpsCoberto;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Bugfix 2026-07-21 — Faltantes é POR (empresa, modelo), não por empresa.
 *
 * Cenário reportado: "By Mobile teste" tem NPS de ML respondido no mês, mas o
 * Matheus Estrela é o responsável SHOPEE dela e o NPS Shopee nunca foi gerado.
 * No cálculo antigo (por empresa: exclui quem tem QUALQUER survey no mês) a
 * empresa sumia dos faltantes — inclusive para o Matheus. Agora o gap Shopee é
 * detectado mesmo com o survey de ML presente.
 */
class NpsFaltantesPorModeloTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;
    use ContrataServicoNpsCoberto;

    public function test_empresa_com_ml_respondido_aparece_como_faltante_shopee_pro_responsavel_shopee(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));

        $mlId     = (int) DB::table('nps_templates')->where('is_default', true)->value('id');            // "NPS Padrão"
        $shopeeId = (int) DB::table('nps_templates')->where('nome', 'NPS Shopee')->value('id');
        $this->assertNotSame(0, $mlId, 'Seed do NPS Padrão (is_default) deve existir.');
        $this->assertNotSame(0, $shopeeId, 'Seed do NPS Shopee deve existir.');

        $company = Company::factory()->create(['name' => 'By Mobile teste', 'active' => true]);
        $matheus = User::factory()->create(['name' => 'Matheus Estrela']);

        // Contrato performance coberto pelo NPS Padrão (ML).
        $this->contratarServicoNpsCoberto($company, $mlId);
        // Contrato shopee + Matheus como ESTRATEGISTA do serviço shopee.
        $shopee = $this->inserirLinhaShopee($company->id, $matheus->id, 'estrategista');
        // Garante o scope do serviço shopee no modelo "NPS Shopee" (defesa contra
        // divergência de seed entre ambientes).
        DB::table('nps_template_service_scopes')->updateOrInsert(
            ['template_id' => $shopeeId, 'servico_id' => $shopee['servicoShopee']],
            ['created_at' => now(), 'updated_at' => now()],
        );

        // NPS de ML respondido no mês corrente (SEM atribuição pro Matheus).
        $surveyMl = NpsSurvey::factory()->create([
            'company_id'      => $company->id,
            'template_id'     => $mlId,
            'status'          => 'completed',
            'month_reference' => now()->startOfMonth(),
            'completed_at'    => now(),
        ]);
        NpsResponse::factory()->create(['survey_id' => $surveyMl->id]);

        // Filtrando pelo Matheus (Shopee): a empresa aparece como FALTANTE do
        // modelo Shopee, e o survey de ML NÃO conta como respondido dele.
        $this->get(route('nps.index', [
            'estrategista_id' => $matheus->id,
            'template_id'     => '__todos__',
        ]))->assertInertia(function (Assert $p) use ($company) {
            $p->where('contadores.faltantes', 1)
              ->where('contadores.respondidos', 0)
              ->where('faltantes.0.company_id', $company->id)
              ->where('faltantes.0.modelo', 'NPS Shopee');
        });
    }

    public function test_sem_filtro_gap_por_modelo_aparece_mesmo_com_survey_de_outro_modelo(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));

        $mlId     = (int) DB::table('nps_templates')->where('is_default', true)->value('id');
        $shopeeId = (int) DB::table('nps_templates')->where('nome', 'NPS Shopee')->value('id');

        $company = Company::factory()->create(['active' => true]);
        $matheus = User::factory()->create();

        $this->contratarServicoNpsCoberto($company, $mlId);
        $shopee = $this->inserirLinhaShopee($company->id, $matheus->id, 'estrategista');
        DB::table('nps_template_service_scopes')->updateOrInsert(
            ['template_id' => $shopeeId, 'servico_id' => $shopee['servicoShopee']],
            ['created_at' => now(), 'updated_at' => now()],
        );

        $surveyMl = NpsSurvey::factory()->create([
            'company_id'      => $company->id,
            'template_id'     => $mlId,
            'status'          => 'completed',
            'month_reference' => now()->startOfMonth(),
            'completed_at'    => now(),
        ]);
        NpsResponse::factory()->create(['survey_id' => $surveyMl->id]);

        // Sem filtro: a empresa NÃO é faltante de ML (tem o survey), MAS é
        // faltante de Shopee (gap por modelo) — o cálculo antigo escondia isso.
        $this->get(route('nps.index', ['template_id' => '__todos__']))
            ->assertInertia(function (Assert $p) use ($company) {
                $p->where('contadores.faltantes', 1)
                  ->where('faltantes.0.company_id', $company->id)
                  ->where('faltantes.0.modelo', 'NPS Shopee');
            });
    }

    /**
     * O bug do "292": 2 modelos automáticos cobrindo o MESMO serviço de
     * performance NÃO podem duplicar a empresa nos faltantes — ela participa de
     * UM setor (Mercado Livre), logo aparece UMA vez.
     */
    public function test_dois_modelos_no_mesmo_setor_nao_duplicam_a_empresa(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));

        $mlId = (int) DB::table('nps_templates')->where('is_default', true)->value('id');

        $company     = Company::factory()->create(['active' => true]);
        $servicoPerf = $this->contratarServicoNpsCoberto($company, $mlId); // perf coberto pelo is_default

        // 2º modelo automático cobrindo O MESMO serviço de performance (espelha
        // "NPS Mentoria" de produção, que duplica o scope do "NPS Performance").
        $mentoria = \App\Models\NpsTemplate::create([
            'nome'                    => 'NPS Mentoria',
            'active'                  => true,
            'is_default'              => false,
            'priority'                => 5,
            'envio_automatico_mensal' => true,
        ]);
        DB::table('nps_template_service_scopes')->insert([
            'template_id' => $mentoria->id,
            'servico_id'  => $servicoPerf,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Quick task 260730-jzx (ajuste 4) — Faltantes agora exige estrategista
        // atribuído (NpsElegibilidadeService::empresasElegiveis()); sem esta
        // linha a empresa deste teste sairia da lista por não estar elegível,
        // e não pelo motivo que este teste prova (dedup de setor).
        DB::table('company_users')->insert([
            'company_id' => $company->id,
            'user_id'    => User::factory()->create()->id,
            'role'       => 'estrategista',
            'servico_id' => $servicoPerf,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sem survey no mês → a empresa é faltante de performance UMA vez só
        // (não uma por modelo). Todos = 0 surveys + 1 faltante = 1.
        $this->get(route('nps.index', ['template_id' => '__todos__']))
            ->assertInertia(function (Assert $p) use ($company) {
                $p->where('contadores.faltantes', 1)
                  ->where('contadores.todos', 1)
                  ->where('faltantes.0.company_id', $company->id);
            });
    }
}
