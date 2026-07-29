<?php

namespace Tests\Feature\Phase119_1;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\NpsGroupSurvey;
use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 119.1 Plan 06 — `NpsGrupoController`: prévia de cobertura, geração do
 * link de grupo (Task 1) e o fluxo público completo de resposta com fan-out
 * em N surveys-espelho REAIS (Task 2).
 *
 * Caso âncora do usuário: grupo "Camillo Parts", Luis (estrategista) e Ana
 * Julia (analista) cuidam de todas as empresas. Enviam o NPS do grupo, o
 * cliente responde 5 em todos os quesitos → todas as empresas do grupo
 * ficam com 5 naquele mês.
 *
 * @see .planning/phases/119.1-nps-manual-sem-duplicidade-e-por-grupo-de-empresas/119.1-06-PLAN.md
 * @see app/Http/Controllers/NpsGrupoController.php
 * @see app/Services/Nps/NpsGrupoReplicacaoService.php
 */
class NpsGrupoSurveyTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Fixtures
    // ═══════════════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Cria um modelo NPS cobrindo os serviços informados. */
    private function criarTemplateCobrindo(array $servicoIds, array $overrides = []): NpsTemplate
    {
        $template = NpsTemplate::factory()->create(array_merge(['active' => true], $overrides));
        $template->serviceScopes()->attach($servicoIds);

        return $template;
    }

    /**
     * Cria uma empresa do grupo, com contrato ativo no serviço informado e
     * os responsáveis (estrategista/analista) atribuídos NAQUELE serviço.
     */
    private function criarEmpresaDoGrupo(
        CompanyGroup $grupo,
        int $servicoId,
        ?User $estrategista,
        ?User $analista = null,
        bool $active = true,
    ): Company {
        $empresa = Company::factory()->create([
            'active'           => $active,
            'company_group_id' => $grupo->id,
            'name'             => 'Empresa ' . uniqid(),
        ]);

        $this->criarContrato($empresa->id, $servicoId, true);

        if ($estrategista) {
            $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servicoId);
        }
        if ($analista) {
            $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoId);
        }

        return $empresa;
    }

    /** Cria um modelo cobrindo os serviços informados, com 1 pergunta escala (5 opções peso 1..5). */
    private function criarTemplateComPergunta(array $servicoIds, string $dimensao = NpsTemplateQuestion::DIMENSAO_EMPRESA): NpsTemplate
    {
        $template = NpsTemplate::factory()->create(['active' => true]);
        $template->serviceScopes()->attach($servicoIds);

        $question = NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'Pergunta grupo ' . uniqid() . '?',
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

    /**
     * Cria o NpsGroupSurvey (registro-âncora) direto via Eloquent — mesmo
     * shape do que `NpsGrupoController::generate()` grava (Task 1, já
     * provado em HTTP acima). Criação direta (não via `actingAs()->post()`)
     * de propósito: `actingAs()` fixa o usuário autenticado para o RESTO do
     * teste, e os testes desta seção precisam de requisições PÚBLICAS
     * (guest) de `respond()`/`submitResponse()` logo em seguida — usar o
     * fluxo HTTP aqui vazaria a sessão autenticada do admin para essas
     * chamadas guest, fazendo `auth()->check()` retornar true indevidamente.
     */
    private function gerarLinkDeGrupo(CompanyGroup $grupo, NpsTemplate $template, ?User $admin = null): NpsGroupSurvey
    {
        $admin ??= $this->admin();

        return NpsGroupSurvey::create([
            'token'            => (string) Str::uuid(),
            'company_group_id' => $grupo->id,
            'template_id'      => $template->id,
            'generated_by'     => $admin->id,
            'month_reference'  => now()->startOfMonth(),
            'expires_at'       => now()->endOfMonth(),
            'status'           => 'pending',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Task 1.1 — prévia devolve incluídas/excluídas com motivo
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_previa_de_cobertura_devolve_incluidas_e_excluidas_com_motivo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $grupo    = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateCobrindo([$servico]);

        $luis     = User::factory()->create(['name' => 'Luis']);
        $anaJulia = User::factory()->create(['name' => 'Ana Julia']);
        $outro    = User::factory()->create(['name' => 'Outro Estrategista']);

        $e1 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);
        $e2 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);
        $divergente = $this->criarEmpresaDoGrupo($grupo, $servico, $outro, $anaJulia);

        $response = $this->actingAs($this->admin())->getJson(
            route('nps.grupo.cobertura', ['grupo' => $grupo->id, 'template' => $template->id])
        );

        $response->assertOk();
        $json = $response->json();

        $idsIncluidas = collect($json['incluidas'])->pluck('company_id')->sort()->values()->all();
        $this->assertSame(collect([$e1->id, $e2->id])->sort()->values()->all(), $idsIncluidas);

        $this->assertCount(1, $json['excluidas']);
        $this->assertSame($divergente->id, $json['excluidas'][0]['company_id']);
        $this->assertSame('responsavel_diferente', $json['excluidas'][0]['motivo']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Task 1.2 — geração cria 1 NpsGroupSurvey
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_geracao_do_link_de_grupo_cria_1_nps_group_survey(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $grupo    = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateCobrindo([$servico]);

        $luis     = User::factory()->create(['name' => 'Luis']);
        $anaJulia = User::factory()->create(['name' => 'Ana Julia']);
        $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);
        $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);

        $response = $this->actingAs($this->admin())->post(route('nps.grupo.generate'), [
            'company_group_id' => $grupo->id,
            'template_id'      => $template->id,
        ]);

        $response->assertSessionHas('success', 'Link de NPS do grupo gerado com sucesso.');
        $response->assertSessionHas('nps_link');

        $this->assertSame(1, NpsGroupSurvey::where('company_group_id', $grupo->id)->count());

        $groupSurvey = NpsGroupSurvey::where('company_group_id', $grupo->id)->firstOrFail();
        $this->assertSame($template->id, $groupSurvey->template_id);
        $this->assertSame('pending', $groupSurvey->status);
        $this->assertNotNull($groupSurvey->month_reference);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Task 1.3 — segunda geração no mesmo mês não cria outro
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_segunda_geracao_no_mesmo_mes_nao_cria_outro_e_devolve_o_link_existente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $grupo    = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateCobrindo([$servico]);

        $luis     = User::factory()->create(['name' => 'Luis']);
        $anaJulia = User::factory()->create(['name' => 'Ana Julia']);
        $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $anaJulia);

        $admin = $this->admin();

        $primeira = $this->actingAs($admin)->post(route('nps.grupo.generate'), [
            'company_group_id' => $grupo->id,
            'template_id'      => $template->id,
        ]);
        $primeira->assertSessionHas('success');

        $primeiroGroupSurvey = NpsGroupSurvey::where('company_group_id', $grupo->id)->firstOrFail();

        $segunda = $this->actingAs($admin)->post(route('nps.grupo.generate'), [
            'company_group_id' => $grupo->id,
            'template_id'      => $template->id,
        ]);

        $this->assertSame(1, NpsGroupSurvey::where('company_group_id', $grupo->id)->count());

        $segunda->assertSessionHas('nps_link_existente', route('nps.grupo.respond', $primeiroGroupSurvey->token));
        $segunda->assertSessionHas('error');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Task 1.4 — grupo sem nenhuma empresa qualificada não cria link
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_grupo_sem_nenhuma_empresa_qualificada_nao_cria_link(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $grupo    = CompanyGroup::create(['name' => 'Grupo Vazio']);
        $servico  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateCobrindo([$servico]);

        // Única empresa do grupo: inativa — ninguém se qualifica.
        $this->criarEmpresaDoGrupo($grupo, $servico, User::factory()->create(), null, active: false);

        $response = $this->actingAs($this->admin())->post(route('nps.grupo.generate'), [
            'company_group_id' => $grupo->id,
            'template_id'      => $template->id,
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, NpsGroupSurvey::where('company_group_id', $grupo->id)->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // Task 2.1 — GET público renderiza Nps/Respond com o nome do GRUPO,
    //            sem listar as empresas cobertas (T-119.1-23)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_get_respond_grupo_renderiza_nome_do_grupo_e_nao_lista_empresas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $grupo    = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateComPergunta([$servico]);

        $luis = User::factory()->create(['name' => 'Luis']);
        $ana  = User::factory()->create(['name' => 'Ana Julia']);
        $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $ana);
        $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $ana);

        $groupSurvey = $this->gerarLinkDeGrupo($grupo, $template);

        $response = $this->get(route('nps.grupo.respond', $groupSurvey->token));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Nps/Respond')
            ->where('survey.company_name', 'Camillo Parts')
            ->where('survey.is_grupo', true)
            ->where('survey.submit_url', route('nps.grupo.submit', $groupSurvey->token))
            ->missing('incluidas')
            ->missing('excluidas')
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Task 2.2 — CASO ÂNCORA: 3 empresas, mesmos responsáveis, nota máxima
    //            → as 3 ficam com a nota, via 3 espelhos REAIS
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_resposta_do_grupo_replica_nota_maxima_para_todas_as_empresas_cobertas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $grupo        = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico      = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template     = $this->criarTemplateComPergunta([$servico]);
        $questao      = $template->questions->first();
        $opcaoMaxima  = $questao->options->firstWhere('peso', 5);

        $luis = User::factory()->create(['name' => 'Luis']);
        $ana  = User::factory()->create(['name' => 'Ana Julia']);
        $e1 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $ana);
        $e2 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $ana);
        $e3 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $ana);

        $groupSurvey = $this->gerarLinkDeGrupo($grupo, $template);

        $response = $this->post(route('nps.grupo.submit', $groupSurvey->token), [
            'respondent_name' => 'Cliente Camillo Parts',
            'answers'         => [(string) $questao->id => $opcaoMaxima->id],
        ]);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Nps/ThankYou'));

        $groupSurvey->refresh();
        $this->assertSame('completed', $groupSurvey->status);
        $this->assertNotNull($groupSurvey->completed_at);

        $espelhos = NpsSurvey::where('group_survey_id', $groupSurvey->id)->get();
        $this->assertCount(3, $espelhos, 'as 3 empresas cobertas recebem 1 espelho cada — nenhuma fica sem nota.');
        $this->assertSame(['completed'], $espelhos->pluck('status')->unique()->values()->all());

        $idsEmpresas = $espelhos->pluck('company_id')->sort()->values()->all();
        $this->assertSame(collect([$e1->id, $e2->id, $e3->id])->sort()->values()->all(), $idsEmpresas);

        foreach ($espelhos as $espelho) {
            $resposta = NpsResponse::where('survey_id', $espelho->id)->first();
            $this->assertNotNull($resposta, "empresa {$espelho->company_id} deveria ter NpsResponse própria (espelho real, não linha compartilhada).");

            $answer = NpsResponseAnswer::where('response_id', $resposta->id)->first();
            $this->assertNotNull($answer);
            $this->assertSame(5, (int) $answer->option_peso_snapshot);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // Task 2.3 — empresa com responsável divergente NÃO ganha survey nem
    //            response (D4 — replicação parcial é regra, não erro)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_empresa_com_responsavel_divergente_nao_ganha_survey_nem_response(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $grupo    = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateComPergunta([$servico]);
        $questao  = $template->questions->first();
        $opcao    = $questao->options->firstWhere('peso', 4);

        $luis  = User::factory()->create(['name' => 'Luis']);
        $ana   = User::factory()->create(['name' => 'Ana Julia']);
        $outro = User::factory()->create(['name' => 'Outro Estrategista']);

        $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $ana);
        $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $ana);
        $divergente = $this->criarEmpresaDoGrupo($grupo, $servico, $outro, $ana);

        $groupSurvey = $this->gerarLinkDeGrupo($grupo, $template);

        $this->post(route('nps.grupo.submit', $groupSurvey->token), [
            'respondent_name' => 'Cliente',
            'answers'         => [(string) $questao->id => $opcao->id],
        ])->assertOk();

        $this->assertSame(2, NpsSurvey::where('group_survey_id', $groupSurvey->id)->count());
        $this->assertSame(
            0,
            NpsSurvey::where('company_id', $divergente->id)->count(),
            'empresa divergente continua sem nenhum survey — segue valendo 1 até receber link individual.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Task 2.4 — cobertura é RECALCULADA no submit: empresa que ganhou link
    //            individual depois da prévia fica de fora (ja_tem_link)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_cobertura_e_recalculada_no_submit_empresa_com_link_individual_fica_de_fora(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $grupo    = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateComPergunta([$servico]);
        $questao  = $template->questions->first();
        $opcao    = $questao->options->firstWhere('peso', 3);

        $luis = User::factory()->create(['name' => 'Luis']);
        $ana  = User::factory()->create(['name' => 'Ana Julia']);
        $e1 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $ana);
        $e2 = $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $ana);

        $groupSurvey = $this->gerarLinkDeGrupo($grupo, $template);

        // Depois da geração do link de grupo, e2 ganha um link INDIVIDUAL do
        // MESMO modelo/mês — a cobertura recalculada no submit deve excluí-la
        // (nunca confiar na prévia estática, T-119.1-25).
        NpsSurvey::factory()->for($e2)->create([
            'template_id'     => $template->id,
            'month_reference' => $groupSurvey->month_reference,
        ]);

        $this->post(route('nps.grupo.submit', $groupSurvey->token), [
            'respondent_name' => 'Cliente',
            'answers'         => [(string) $questao->id => $opcao->id],
        ])->assertOk();

        $this->assertSame(1, NpsSurvey::where('group_survey_id', $groupSurvey->id)->count());
        $espelho = NpsSurvey::where('group_survey_id', $groupSurvey->id)->firstOrFail();
        $this->assertSame($e1->id, $espelho->company_id, 'só e1 recebe espelho — e2 já tinha link individual no mês.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Task 2.5 — submit em sessão autenticada de usuário interno é bloqueado
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_submit_em_sessao_autenticada_e_bloqueado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $grupo    = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateComPergunta([$servico]);
        $questao  = $template->questions->first();
        $opcao    = $questao->options->firstWhere('peso', 5);

        $luis = User::factory()->create(['name' => 'Luis']);
        $ana  = User::factory()->create(['name' => 'Ana Julia']);
        $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $ana);

        $groupSurvey = $this->gerarLinkDeGrupo($grupo, $template);

        $usuarioInterno = User::factory()->create();

        $response = $this->actingAs($usuarioInterno)->post(route('nps.grupo.submit', $groupSurvey->token), [
            'respondent_name' => 'Tentativa Interna',
            'answers'         => [(string) $questao->id => $opcao->id],
        ]);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Nps/Blocked'));

        $groupSurvey->refresh();
        $this->assertSame('pending', $groupSurvey->status);
        $this->assertSame(0, NpsSurvey::where('group_survey_id', $groupSurvey->id)->count());
    }

    // ═══════════════════════════════════════════════════════════════════
    // Task 2.6 — segundo submit no mesmo link renderiza AlreadyCompleted e
    //            NÃO cria espelhos novos
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_segundo_submit_no_mesmo_link_renderiza_already_completed_sem_criar_espelhos_novos(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $grupo    = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $template = $this->criarTemplateComPergunta([$servico]);
        $questao  = $template->questions->first();
        $opcao    = $questao->options->firstWhere('peso', 5);

        $luis = User::factory()->create(['name' => 'Luis']);
        $ana  = User::factory()->create(['name' => 'Ana Julia']);
        $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $ana);
        $this->criarEmpresaDoGrupo($grupo, $servico, $luis, $ana);

        $groupSurvey = $this->gerarLinkDeGrupo($grupo, $template);

        $payload = [
            'respondent_name' => 'Cliente',
            'answers'         => [(string) $questao->id => $opcao->id],
        ];

        $this->post(route('nps.grupo.submit', $groupSurvey->token), $payload)->assertOk();

        $totalEspelhosAntes = NpsSurvey::where('group_survey_id', $groupSurvey->id)->count();

        $segundo = $this->post(route('nps.grupo.submit', $groupSurvey->token), $payload);

        $segundo->assertOk();
        $segundo->assertInertia(fn ($page) => $page->component('Nps/AlreadyCompleted'));

        $this->assertSame(
            $totalEspelhosAntes,
            NpsSurvey::where('group_survey_id', $groupSurvey->id)->count(),
            'segundo submit não cria espelhos novos.'
        );
    }
}
