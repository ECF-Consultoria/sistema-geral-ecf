<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ContrataServicoNpsCoberto;
use Tests\TestCase;

/**
 * Bugfix 2026-08-14 — quem gera um link de NPS precisa conseguir VER o link
 * que gerou. Relatado em produção por mais de uma pessoa: geravam o link, viam
 * a mensagem de sucesso, e ele nunca aparecia na lista do `/nps`.
 *
 * Eram DOIS defeitos independentes no mesmo filtro (`$filtroPorPessoa`, o
 * escopo do não-admin em `NpsController::index()`), ambos com a mesma raiz: a
 * autorização de ESCRITA é mais ampla que a de LEITURA.
 *
 *  (1) `generate()` aceita QUALQUER papel no pivot `company_users`, mas a
 *      leitura exigia ser responsável PELO SERVIÇO que o modelo cobre —
 *      `generated_by` não entrava em nenhum dos dois ramos do filtro.
 *
 *  (2) Modelo SEM nenhum serviço coberto (pivot `nps_template_service_scopes`
 *      vazio — o caso do "NPS Padrão", e dos surveys legados com
 *      `template_id` NULL) nunca casava no `orWhereExists` de serviço. Quem
 *      tem vínculo com `servico_id` preenchido não via NENHUM survey desse
 *      modelo — o mais usado do sistema. `generate()` já tratava pivot vazio
 *      como "aceito para qualquer empresa"; a leitura não tinha contraparte.
 *
 * O teste 4 é o guarda-corpo do bugfix de 2026-07-22 (vazamento do NPS Shopee
 * para o responsável de ML): modelo COM escopo continua restrito.
 *
 * @see app/Http/Controllers/NpsController.php ($filtroPorPessoa e generate())
 */
class NpsVisibilidadeLinkGeradoTest extends TestCase
{
    use RefreshDatabase;
    use ContrataServicoNpsCoberto;

    /** Modelo com escopo de serviço declarado (ex.: "NPS Shopee"). */
    private function templateEscopado(int $servicoId): NpsTemplate
    {
        $template = NpsTemplate::factory()->create([
            'nome'       => 'NPS Escopado ' . uniqid(),
            'active'     => true,
            'is_default' => false,
        ]);
        $template->serviceScopes()->attach($servicoId);

        return $template;
    }

    /** Modelo SEM nenhum serviço coberto — o caso do "NPS Padrão". */
    private function templateSemEscopo(): NpsTemplate
    {
        return NpsTemplate::factory()->create([
            'nome'       => 'NPS Sem Escopo ' . uniqid(),
            'active'     => true,
            'is_default' => false,
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

    // ═══════════════════════════════════════════════════════════════════
    // 1 — quem gerou vê o link que gerou (caminho REAL: POST /nps/generate)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_quem_gerou_o_link_enxerga_o_link_na_listagem(): void
    {
        $company = Company::factory()->create();
        $luiz    = User::factory()->create(['name' => 'Luiz', 'role' => 'consultor', 'active' => true]);

        // Serviço A: o vínculo do Luiz. Serviço B: o que o modelo cobre.
        $servicoA = $this->contratarServicoNpsCoberto($company);
        $servicoB = DB::table('servicos')->insertGetId([
            'nome'          => 'Serviço Outro ' . uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        DB::table('contratos_servico')->insert([
            'company_id'       => $company->id,
            'servico_id'       => $servicoB,
            'valor_contratado' => 0,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // Luiz é responsável pelo serviço A — o que o autoriza a gerar.
        $this->vincular($company, $luiz, 'consultor', $servicoA);

        // O modelo, porém, cobre o serviço B: ele não é responsável por ele.
        $template = $this->templateEscopado($servicoB);

        $this->actingAs($luiz)
            ->post('/nps/generate', [
                'company_id'  => $company->id,
                'template_id' => $template->id,
            ])
            ->assertStatus(302);

        $survey = NpsSurvey::where('company_id', $company->id)->firstOrFail();
        $this->assertSame($luiz->id, $survey->generated_by);

        // Antes do bugfix esta lista vinha VAZIA — ele gerava e não via.
        $this->actingAs($luiz)
            ->get(route('nps.index', ['template_id' => '__todos__']))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->has('surveys.data', 1)
                ->where('surveys.data.0.id', $survey->id));
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2 — modelo SEM escopo de serviço é visível para o vínculo da empresa
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_modelo_sem_servico_coberto_aparece_para_quem_tem_vinculo_por_servico(): void
    {
        $company = Company::factory()->create();
        $ana     = User::factory()->create(['name' => 'Ana', 'role' => 'consultor', 'active' => true]);

        $servico = $this->contratarServicoNpsCoberto($company);
        $this->vincular($company, $ana, 'estrategista', $servico);

        // Survey de um modelo sem NENHUM serviço coberto — e sem resposta,
        // logo sem atribuição congelada: cai direto no ramo de serviço.
        $survey = NpsSurvey::factory()->create([
            'company_id'      => $company->id,
            'template_id'     => $this->templateSemEscopo()->id,
            'status'          => 'pending',
            'month_reference' => now()->startOfMonth(),
            'completed_at'    => null,
        ]);

        $this->actingAs($ana)
            ->get(route('nps.index', ['template_id' => '__todos__']))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->has('surveys.data', 1)
                ->where('surveys.data.0.id', $survey->id));
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3 — o filtro por PAPEL não vira "gerou" (não contamina estrategista_id)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_filtro_por_estrategista_nao_inclui_survey_que_a_pessoa_apenas_gerou(): void
    {
        $admin   = User::factory()->create(['role' => 'admin', 'active' => true]);
        $company = Company::factory()->create();
        $gerador = User::factory()->create(['name' => 'Só Gerou', 'active' => true]);
        $estrat  = User::factory()->create(['name' => 'Estrategista', 'active' => true]);

        $servico = $this->contratarServicoNpsCoberto($company);
        // O estrategista da empresa é OUTRA pessoa, não quem gerou.
        $this->vincular($company, $estrat, 'estrategista', $servico);

        NpsSurvey::factory()->create([
            'company_id'      => $company->id,
            'template_id'     => $this->templateSemEscopo()->id,
            'status'          => 'pending',
            'month_reference' => now()->startOfMonth(),
            'generated_by'    => $gerador->id,
            'completed_at'    => null,
        ]);

        // Filtrando por "estrategista = quem gerou": nada, porque ele só
        // disparou o link — não é o estrategista da empresa.
        $this->actingAs($admin)
            ->get(route('nps.index', [
                'estrategista_id' => $gerador->id,
                'template_id'     => '__todos__',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('surveys.data', 0));
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4 — REGRESSÃO do bugfix 2026-07-22: modelo ESCOPADO segue restrito
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_modelo_escopado_de_outro_servico_continua_invisivel(): void
    {
        $company  = Company::factory()->create();
        $nathalia = User::factory()->create(['name' => 'Nathalia', 'role' => 'consultor', 'active' => true]);

        $servicoMl     = $this->contratarServicoNpsCoberto($company);
        $servicoShopee = DB::table('servicos')->insertGetId([
            'nome'          => 'Shopee ' . uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Nathalia responde pelo ML da empresa.
        $this->vincular($company, $nathalia, 'estrategista', $servicoMl);

        // NPS do Shopee — modelo ESCOPADO em serviço que não é dela, e que ela
        // também não gerou.
        $outro = User::factory()->create(['active' => true]);
        NpsSurvey::factory()->create([
            'company_id'      => $company->id,
            'template_id'     => $this->templateEscopado($servicoShopee)->id,
            'status'          => 'pending',
            'month_reference' => now()->startOfMonth(),
            'generated_by'    => $outro->id,
            'completed_at'    => null,
        ]);

        $this->actingAs($nathalia)
            ->get(route('nps.index', ['template_id' => '__todos__']))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('surveys.data', 0));
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5 — atribuição congelada de OUTRA pessoa não vaza (ramo (a) intacto)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_resposta_atribuida_a_outra_pessoa_nao_vaza_para_quem_nao_gerou(): void
    {
        $company  = Company::factory()->create();
        $nathalia = User::factory()->create(['name' => 'Nathalia', 'role' => 'consultor', 'active' => true]);
        $outra    = User::factory()->create(['name' => 'Outra', 'active' => true]);

        $servico = $this->contratarServicoNpsCoberto($company);
        $this->vincular($company, $nathalia, 'estrategista', $servico);

        // Survey respondido e congelado no nome de OUTRA pessoa.
        $survey = NpsSurvey::factory()->create([
            'company_id'      => $company->id,
            'template_id'     => null,
            'status'          => 'completed',
            'month_reference' => now()->startOfMonth(),
            'generated_by'    => $outra->id,
            'completed_at'    => now(),
        ]);
        $response = NpsResponse::factory()->create(['survey_id' => $survey->id]);

        $scoreId = DB::table('nps_response_scores')->insertGetId([
            'nps_response_id' => $response->id,
            'company_id'      => $company->id,
            'dimensao'        => 'estrategista',
            'score_sum'       => 5,
            'question_count'  => 1,
            'average_score'   => 5,
            'calculated_at'   => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        DB::table('nps_score_assignments')->insert([
            'nps_response_id'       => $response->id,
            'nps_response_score_id' => $scoreId,
            'company_id'            => $company->id,
            'servico_id'            => null,
            'service_setor'         => Servico::SETOR_PERFORMANCE,
            'role'                  => 'estrategista',
            'user_id'               => $outra->id,
            'average_score'         => 5,
            'assigned_at'           => now(),
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        $this->actingAs($nathalia)
            ->get(route('nps.index', ['template_id' => '__todos__']))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('surveys.data', 0));
    }
}
