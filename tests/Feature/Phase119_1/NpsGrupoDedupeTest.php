<?php

namespace Tests\Feature\Phase119_1;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\NpsGroupSurvey;
use App\Models\NpsScoreAssignment;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 119.1 Plan 06 (Task 3) — prova das DUAS réguas de dedupe (a armadilha
 * mais perigosa da fase): uma resposta de NPS de grupo em N empresas NÃO
 * pode contar N vezes na média de quem cuida das N empresas do grupo — nem
 * contar de menos.
 *
 * Cenário âncora: grupo de 5 empresas, mesmo estrategista (Luis) e mesmo
 * analista (Ana) em todas, 1 link de grupo respondido com nota máxima.
 *
 * Réguas provadas:
 *  1. Bônus — `DesempenhoScoreService::computeNpsMedio()` (via reflection),
 *     dedupe por `(nps_response_id, role)`.
 *  2. Área NPS — `NpsController::index()` (via HTTP), dedupe por
 *     `(survey_id, dimensao)`.
 *  3. Equivalência — grupo de N × N links individuais respondidos com a
 *     mesma nota devem produzir o MESMO resultado nas duas réguas. Se
 *     divergir, o fan-out está errado (não é para "ajustar o número
 *     esperado" — é sinal de bug na modelagem).
 *  4. Replicação parcial — divergente fica sem nota real, mas conta 1 pelo
 *     ramo D1 (Plan 03) para quem DE FATO é responsável por ela.
 *
 * Nenhum arquivo de produção é tocado por este teste — só prova o
 * comportamento já entregue nos Plans 05/06.
 *
 * @see .planning/phases/119.1-nps-manual-sem-duplicidade-e-por-grupo-de-empresas/119.1-06-PLAN.md
 * @see app/Services/Nps/NpsGrupoReplicacaoService.php
 * @see app/Services/DesempenhoScoreService.php (notasPorAtribuicao — dedupe do bônus)
 */
class NpsGrupoDedupeTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Invoca `computeNpsMedio` (privado) por reflection — mesmo molde de
     * `tests/Feature/Phase116/NpsFloorDesempenhoTest.php`.
     */
    private function invocarComputeNpsMedio(User $user, Carbon $mes, ?Collection $invalidadas = null): float
    {
        $service = app(DesempenhoScoreService::class);
        $metodo  = new ReflectionMethod($service, 'computeNpsMedio');
        $metodo->setAccessible(true);

        return $metodo->invoke($service, $user, $mes, $invalidadas);
    }

    /**
     * Cria um template com 1 pergunta escala (dimensão informada, 5 opções
     * peso 1..5), cobrindo os serviços informados.
     */
    private function criarTemplateComPergunta(
        array $servicoIds,
        string $dimensao = NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA,
        array $overrides = [],
    ): NpsTemplate {
        $template = NpsTemplate::factory()->create(array_merge(['active' => true], $overrides));
        $template->serviceScopes()->attach($servicoIds);

        $question = NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'Pergunta dedupe ' . uniqid() . '?',
            'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
            'dimensao'    => $dimensao,
            'obrigatoria' => true,
            'ordem'       => 1,
        ]);

        for ($peso = 1; $peso <= 5; $peso++) {
            NpsTemplateOption::create([
                'question_id' => $question->id,
                'label'       => (string) $peso,
                'peso'        => $peso,
                'ordem'       => $peso,
            ]);
        }

        return $template->fresh(['questions.options']);
    }

    /** Cria uma empresa (avulsa ou de grupo) com contrato ativo + responsáveis atribuídos. */
    private function criarEmpresaComResponsaveis(
        int $servicoId,
        ?User $estrategista,
        ?User $analista = null,
        ?CompanyGroup $grupo = null,
        bool $active = true,
    ): Company {
        $empresa = Company::factory()->create(array_merge(
            ['active' => $active, 'name' => 'Empresa ' . uniqid()],
            $grupo ? ['company_group_id' => $grupo->id] : [],
        ));

        $this->criarContrato($empresa->id, $servicoId, true);

        if ($estrategista) {
            $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servicoId);
        }
        if ($analista) {
            $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoId);
        }

        return $empresa;
    }

    /** Gera o link de grupo direto via Eloquent — mesmo shape de `NpsGrupoController::generate()`. */
    private function criarGroupSurveyPendente(CompanyGroup $grupo, NpsTemplate $template, User $admin): NpsGroupSurvey
    {
        return NpsGroupSurvey::create([
            'token'            => (string) \Illuminate\Support\Str::uuid(),
            'company_group_id' => $grupo->id,
            'template_id'      => $template->id,
            'generated_by'     => $admin->id,
            'month_reference'  => now()->startOfMonth(),
            'expires_at'       => now()->endOfMonth(),
            'status'           => 'pending',
        ]);
    }

    /** Responde o link de grupo publicamente com a nota máxima na única pergunta do template. */
    private function responderLinkDeGrupoComNotaMaxima(NpsGroupSurvey $groupSurvey, string $nomeCliente): void
    {
        $questao = $groupSurvey->template->questions->first();
        $opcao   = $questao->options->firstWhere('peso', 5);

        $this->post(route('nps.grupo.submit', $groupSurvey->token), [
            'respondent_name' => $nomeCliente,
            'answers'         => [(string) $questao->id => $opcao->id],
        ])->assertOk();
    }

    /**
     * Gera e responde um link INDIVIDUAL (fluxo real, mesmas rotas
     * `nps.generate`/`/nps/{token}`) com a nota máxima — usado no cenário-
     * espelho de equivalência (item 3).
     */
    private function criarLinkIndividualRespondidoComNotaMaxima(User $admin, Company $empresa, NpsTemplate $template, string $nomeCliente): void
    {
        $this->actingAs($admin)->post(route('nps.generate'), [
            'company_id'  => $empresa->id,
            'template_id' => $template->id,
        ])->assertSessionHas('success');

        $survey = NpsSurvey::where('company_id', $empresa->id)
            ->where('template_id', $template->id)
            ->latest('id')
            ->firstOrFail();

        $questao = $template->questions->first();
        $opcao   = $questao->options->firstWhere('peso', 5);

        // Submit público — sem `actingAs()` (o `admin` já ficou "logado" pelo
        // `post()` acima; usamos o helper de teste `get()/post()` padrão do
        // Laravel, que não força logout automático — por isso resolvemos o
        // survey primeiro e respondemos como o PRÓPRIO admin aqui não importa
        // para o cálculo de nota real: `capturarRastroEAvaliarSuspeita` só
        // BLOQUEIA quando quem responde está autenticado, o que descartaria
        // a resposta. Fazemos logout explícito antes do submit público.
        auth()->logout();

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => $nomeCliente,
            'answers'         => [(string) $questao->id => $opcao->id],
        ])->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1 — Régua do BÔNUS: Luis vê exatamente 5 notas, uma por empresa
    //     (nem 10, nem 25, nem 1)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_regua_do_bonus_conta_exatamente_5_notas_para_o_estrategista_do_grupo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $admin   = $this->admin();
        $grupo   = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateComPergunta([$servico]);

        $luis = User::factory()->create(['name' => 'Luis']);
        $ana  = User::factory()->create(['name' => 'Ana Julia']);

        $empresas = collect(range(1, 5))->map(
            fn () => $this->criarEmpresaComResponsaveis($servico, $luis, $ana, $grupo)
        );

        $groupSurvey = $this->criarGroupSurveyPendente($grupo, $template, $admin);
        $this->responderLinkDeGrupoComNotaMaxima($groupSurvey, 'Cliente Camillo Parts');

        // "Nem mais" — contagem de linhas distintas de nps_score_assignments
        // por (nps_response_id, role) para Luis é exatamente 5.
        $distinctResponses = NpsScoreAssignment::where('user_id', $luis->id)
            ->where('role', 'estrategista')
            ->distinct('nps_response_id')
            ->pluck('nps_response_id');

        $this->assertCount(5, $distinctResponses, 'Luis deve ter exatamente 5 nps_response_id distintos — 1 por empresa do grupo.');
        $this->assertSame($empresas->count(), $distinctResponses->count());

        // "Nem menos" — a média do bônus reflete as 5 notas máximas.
        $media = $this->invocarComputeNpsMedio($luis, Carbon::parse('2026-07-01'));
        $this->assertSame(5.0, $media, 'média do bônus de Luis deve ser 5.0 — 5 notas máximas, sem dupla contagem nem falta.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2 — Régua da ÁREA NPS: as 5 empresas aparecem com nota, a média por
    //     dimensão considera 5 surveys distintos (não colapsa numa só)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_regua_da_area_nps_mostra_5_empresas_com_nota_sem_colapsar(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $admin    = $this->admin();
        $grupo    = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateComPergunta([$servico]);

        $luis = User::factory()->create(['name' => 'Luis']);
        $ana  = User::factory()->create(['name' => 'Ana Julia']);

        collect(range(1, 5))->each(
            fn () => $this->criarEmpresaComResponsaveis($servico, $luis, $ana, $grupo)
        );

        $groupSurvey = $this->criarGroupSurveyPendente($grupo, $template, $admin);
        $this->responderLinkDeGrupoComNotaMaxima($groupSurvey, 'Cliente Camillo Parts');

        $response = $this->actingAs($admin)->get(route('nps.index', [
            'mes'             => '2026-07',
            'estrategista_id' => $luis->id,
        ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('cards.estrategista.total', 5)
            ->where('cards.estrategista.media', 5)
        );

        $this->assertSame(
            5,
            NpsSurvey::where('group_survey_id', $groupSurvey->id)->where('status', 'completed')->count(),
            'dedupe por (survey_id, dimensao) não colapsa — 5 surveys distintos continuam distintos.',
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3 — EQUIVALÊNCIA: grupo de 5 × 5 links individuais com a mesma nota
    //     produzem o MESMO resultado nas duas réguas (a prova mais forte)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_grupo_de_5_e_5_links_individuais_produzem_resultado_identico(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $admin   = $this->admin();
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);

        // ─── Cenário GRUPO ───────────────────────────────────────────────
        $grupo        = CompanyGroup::create(['name' => 'Camillo Parts']);
        $templateGrupo = $this->criarTemplateComPergunta([$servico]);
        $luisGrupo    = User::factory()->create(['name' => 'Luis (grupo)']);
        $anaGrupo     = User::factory()->create(['name' => 'Ana (grupo)']);

        collect(range(1, 5))->each(
            fn () => $this->criarEmpresaComResponsaveis($servico, $luisGrupo, $anaGrupo, $grupo)
        );

        $groupSurvey = $this->criarGroupSurveyPendente($grupo, $templateGrupo, $admin);
        $this->responderLinkDeGrupoComNotaMaxima($groupSurvey, 'Cliente Grupo');

        // ─── Cenário-espelho INDIVIDUAL — 5 links reais, mesma nota ──────
        $templateIndividual = $this->criarTemplateComPergunta([$servico]);
        $luisIndividual      = User::factory()->create(['name' => 'Luis (individual)']);
        $anaIndividual       = User::factory()->create(['name' => 'Ana (individual)']);

        collect(range(1, 5))->each(function () use ($servico, $luisIndividual, $anaIndividual, $admin, $templateIndividual) {
            $empresa = $this->criarEmpresaComResponsaveis($servico, $luisIndividual, $anaIndividual);
            $this->criarLinkIndividualRespondidoComNotaMaxima($admin, $empresa, $templateIndividual, 'Cliente Individual');
        });

        // ─── Régua do bônus — resultado idêntico ─────────────────────────
        $mediaGrupo      = $this->invocarComputeNpsMedio($luisGrupo, Carbon::parse('2026-07-01'));
        $mediaIndividual = $this->invocarComputeNpsMedio($luisIndividual, Carbon::parse('2026-07-01'));

        $this->assertSame(5.0, $mediaGrupo);
        $this->assertSame(5.0, $mediaIndividual);
        $this->assertSame($mediaIndividual, $mediaGrupo, 'a média do bônus do grupo deve ser IDÊNTICA à dos 5 links individuais — não apenas "maior que zero".');

        $qtdAssignmentsGrupo = NpsScoreAssignment::where('user_id', $luisGrupo->id)
            ->where('role', 'estrategista')->distinct('nps_response_id')->pluck('nps_response_id')->count();
        $qtdAssignmentsIndividual = NpsScoreAssignment::where('user_id', $luisIndividual->id)
            ->where('role', 'estrategista')->distinct('nps_response_id')->pluck('nps_response_id')->count();

        $this->assertSame(5, $qtdAssignmentsGrupo);
        $this->assertSame(5, $qtdAssignmentsIndividual);
        $this->assertSame($qtdAssignmentsIndividual, $qtdAssignmentsGrupo, 'mesmo número de nps_response_id distintos nos dois cenários.');

        // ─── Régua da área NPS — resultado idêntico (mesmos valores fixos
        // nos dois cenários — a prova de igualdade É comparar contra a
        // MESMA constante, não "maior que zero").
        $this->actingAs($admin)->get(route('nps.index', [
            'mes' => '2026-07', 'estrategista_id' => $luisGrupo->id,
        ]))->assertInertia(fn ($page) => $page
            ->where('cards.estrategista.total', 5)
            ->where('cards.estrategista.media', 5)
        );

        $this->actingAs($admin)->get(route('nps.index', [
            'mes' => '2026-07', 'estrategista_id' => $luisIndividual->id,
        ]))->assertInertia(fn ($page) => $page
            ->where('cards.estrategista.total', 5)
            ->where('cards.estrategista.media', 5)
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4 — Replicação PARCIAL: grupo de 5 com 1 divergente — Luis vê 4
    //     notas reais, e a divergente conta 1 pelo ramo D1 (Plan 03) para
    //     quem de fato é responsável por ela (mês fechado)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_replicacao_parcial_luis_ve_4_notas_e_divergente_conta_1_pelo_d1_para_o_responsavel_real(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $admin   = $this->admin();
        $grupo   = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);

        // Template precisa de envio_automatico_mensal=true para a empresa
        // divergente ser ELEGÍVEL ao D1 (NpsElegibilidadeService::modelosAplicaveis).
        $template = $this->criarTemplateComPergunta([$servico], overrides: ['envio_automatico_mensal' => true]);

        $luis   = User::factory()->create(['name' => 'Luis']);
        $ana    = User::factory()->create(['name' => 'Ana Julia']);
        $marcos = User::factory()->create(['name' => 'Marcos']);

        collect(range(1, 4))->each(
            fn () => $this->criarEmpresaComResponsaveis($servico, $luis, $ana, $grupo)
        );
        // Divergente: MESMO analista (Ana), estrategista DIFERENTE (Marcos) — D6.
        $divergente = $this->criarEmpresaComResponsaveis($servico, $marcos, $ana, $grupo);

        $groupSurvey = $this->criarGroupSurveyPendente($grupo, $template, $admin);
        $this->responderLinkDeGrupoComNotaMaxima($groupSurvey, 'Cliente Camillo Parts');

        // A divergente nunca recebeu survey nenhum (nem individual, nem espelho).
        $this->assertSame(0, NpsSurvey::where('company_id', $divergente->id)->count());
        $this->assertSame(4, NpsSurvey::where('group_survey_id', $groupSurvey->id)->count());

        // Avança para o mês seguinte — a competência de julho fecha (D1 só
        // vale depois que a janela de coleta fecha).
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        $notaLuis = $this->invocarComputeNpsMedio($luis, Carbon::parse('2026-07-01'));
        $this->assertSame(5.0, $notaLuis, 'Luis enxerga só as 4 notas reais (5.0 de média) — a divergente NUNCA foi dele.');

        $qtdNotasLuis = NpsScoreAssignment::where('user_id', $luis->id)
            ->where('role', 'estrategista')->distinct('nps_response_id')->pluck('nps_response_id')->count();
        $this->assertSame(4, $qtdNotasLuis, 'nem mais (não 5), nem menos (não 0) — exatamente as 4 empresas concordantes.');

        // Marcos (responsável REAL da divergente) não recebeu nenhum link —
        // com o mês fechado, D1 conta 1 para ele (Plan 03), não zero.
        $notaMarcos = $this->invocarComputeNpsMedio($marcos, Carbon::parse('2026-07-01'));
        $this->assertSame(1.0, $notaMarcos, 'empresa elegível sem NENHUM link (nem grupo, nem individual) conta nota 1 para quem é responsável de fato (D1/Plan 03).');
    }
}
