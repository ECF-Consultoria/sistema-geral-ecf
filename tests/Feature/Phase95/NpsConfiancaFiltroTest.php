<?php

namespace Tests\Feature\Phase95;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Phase 95 Plan 01 — Task 2 (AB-95-3).
 *
 * Prova o filtro server-side `?confianca=todos|confiavel|atencao|suspeita`:
 * afeta a paginação para admin (whitelist estrita, fallback silencioso para
 * valor inválido) e é IGNORADO SILENCIOSAMENTE para não-admin — nunca 403/422,
 * nunca uma resposta diferente da ausência do parâmetro (Pitfall 4 do
 * RESEARCH: um erro denunciaria que a feature existe).
 */
class NpsConfiancaFiltroTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
        Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    /** GET /nps com querystring extra, sempre incluindo template_id=__todos__ (armadilha do molde V16). */
    private function propsDoIndex(User $user, string $queryExtra = ''): array
    {
        $props = null;
        $url = '/nps?template_id=__todos__' . ($queryExtra !== '' ? '&' . $queryExtra : '');

        $this->actingAs($user)
            ->get($url)
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $page->component('Nps/Index');
                $props = $page->toArray()['props'];
            });

        $this->assertIsArray($props, 'O payload Inertia deveria trazer props.');

        return $props;
    }

    private function tokensDaLista(array $props): array
    {
        return collect($props['surveys']['data'] ?? [])->pluck('token')->all();
    }

    private function criarSurveyCompleto(Company $company, array $overrides = []): NpsSurvey
    {
        return NpsSurvey::create(array_merge([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $company->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(7),
            'status'       => 'completed',
            'completed_at' => now(),
            'template_id'  => null,
        ], $overrides));
    }

    private function criarResponse(NpsSurvey $survey, array $overrides = []): NpsResponse
    {
        return NpsResponse::create(array_merge([
            'survey_id'          => $survey->id,
            'respondent_name'    => 'Cliente Teste',
            'score_estrategista' => 4,
            'score_analista'     => 4,
            'score_empresa'      => 5,
            'comment'            => null,
            'response_ip_address'       => '203.0.113.10',
            'response_user_agent'       => 'Mozilla/5.0 (teste)',
            'response_duration_seconds' => 300,
            'is_suspicious'             => false,
            'suspicion_reasons'         => null,
        ], $overrides));
    }

    /**
     * Monta o cenário fixo: 3 surveys completed (limpo/media/alta) + 1 pending,
     * todos da mesma empresa. Retorna os tokens indexados por rótulo.
     *
     * @return array{company: Company, limpo: string, media: string, alta: string, pendente: string}
     */
    private function montarCenario(): array
    {
        $company = Company::factory()->create(['active' => true]);

        $surveyLimpo = $this->criarSurveyCompleto($company);
        $this->criarResponse($surveyLimpo, ['suspicion_reasons' => null]);

        $surveyMedia = $this->criarSurveyCompleto($company);
        $this->criarResponse($surveyMedia, [
            'is_suspicious'     => true,
            'suspicion_reasons' => [
                'reasons'  => ['Resposta enviada em menos de 1 minuto após geração do link.'],
                'severity' => 'media',
            ],
        ]);

        $surveyAlta = $this->criarSurveyCompleto($company);
        $this->criarResponse($surveyAlta, [
            'is_suspicious'     => true,
            'suspicion_reasons' => [
                'reasons'  => ['Resposta enviada a partir da rede interna da ECF.'],
                'severity' => 'alta',
            ],
        ]);

        $surveyPendente = NpsSurvey::create([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $company->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(7),
            'status'       => 'pending',
            'template_id'  => null,
        ]);

        return [
            'company'  => $company,
            'limpo'    => $surveyLimpo->token,
            'media'    => $surveyMedia->token,
            'alta'     => $surveyAlta->token,
            'pendente' => $surveyPendente->token,
        ];
    }

    #[Test]
    public function test_admin_filtra_confiavel_traz_so_o_limpo(): void
    {
        $cenario = $this->montarCenario();

        $props  = $this->propsDoIndex($this->admin(), 'confianca=confiavel');
        $tokens = $this->tokensDaLista($props);

        $this->assertSame([$cenario['limpo']], $tokens);
    }

    #[Test]
    public function test_admin_filtra_atencao_traz_so_o_media(): void
    {
        $cenario = $this->montarCenario();

        $props  = $this->propsDoIndex($this->admin(), 'confianca=atencao');
        $tokens = $this->tokensDaLista($props);

        $this->assertSame([$cenario['media']], $tokens);
    }

    #[Test]
    public function test_admin_filtra_suspeita_traz_so_o_alta(): void
    {
        $cenario = $this->montarCenario();

        $props  = $this->propsDoIndex($this->admin(), 'confianca=suspeita');
        $tokens = $this->tokensDaLista($props);

        $this->assertSame([$cenario['alta']], $tokens);
    }

    #[Test]
    public function test_admin_filtro_todos_e_ausencia_de_parametro_trazem_os_4_surveys(): void
    {
        $cenario = $this->montarCenario();
        $admin   = $this->admin();

        $propsTodos  = $this->propsDoIndex($admin, 'confianca=todos');
        $propsSemParam = $this->propsDoIndex($admin);

        $this->assertCount(4, $this->tokensDaLista($propsTodos));
        $this->assertCount(4, $this->tokensDaLista($propsSemParam));
        $this->assertEqualsCanonicalizing(
            $this->tokensDaLista($propsTodos),
            $this->tokensDaLista($propsSemParam)
        );
    }

    #[Test]
    public function test_admin_valor_invalido_cai_no_fallback_todos_sem_erro(): void
    {
        $cenario = $this->montarCenario();
        $admin   = $this->admin();

        $propsInvalido = $this->propsDoIndex($admin, 'confianca=xpto');
        $propsTodos    = $this->propsDoIndex($admin, 'confianca=todos');

        $this->assertEqualsCanonicalizing(
            $this->tokensDaLista($propsTodos),
            $this->tokensDaLista($propsInvalido)
        );
    }

    #[Test]
    public function test_admin_props_refletem_o_filtro_aplicado(): void
    {
        $this->montarCenario();
        $admin = $this->admin();

        $props = $this->propsDoIndex($admin, 'confianca=atencao');

        $this->assertArrayHasKey('confianca', $props['filtros']);
        $this->assertSame('atencao', $props['filtros']['confianca']);
    }

    #[Test]
    public function test_nao_admin_com_filtro_suspeita_recebe_200_identico_ao_get_sem_parametro(): void
    {
        $cenario = $this->montarCenario();

        $naoAdmin = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->inserirPivot($cenario['company']->id, $naoAdmin->id, 'consultor', null);

        $propsComFiltro  = $this->propsDoIndex($naoAdmin, 'confianca=suspeita');
        $propsSemFiltro  = $this->propsDoIndex($naoAdmin);

        $this->assertEqualsCanonicalizing(
            $this->tokensDaLista($propsSemFiltro),
            $this->tokensDaLista($propsComFiltro)
        );
        // Não-admin não recebe nem a chave 'confianca' dentro de 'filtros' —
        // o filtro é invisível, não só inócuo.
        $this->assertArrayNotHasKey('confianca', $propsComFiltro['filtros']);
    }
}
