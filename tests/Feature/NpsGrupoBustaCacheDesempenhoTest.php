<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\NpsGroupSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Bug de 2026-08-18 — resposta de link de GRUPO não derrubava o cache do
 * desempenho, e as empresas ficavam em "Não entraram" no `/performance`.
 *
 * A spec de 2026-08-14 (item 3) fez o fluxo INDIVIDUAL bustar
 * `DesempenhoScoreService::computeCached()` ao receber a resposta
 * (`NpsTempoRealDesempenhoTest` cobre esse lado). O fluxo de GRUPO —
 * `NpsGrupoController::submitResponse()` → `NpsGrupoReplicacaoService` —
 * nunca ganhou o mesmo tratamento, e ele grava exatamente as mesmas coisas:
 * survey-espelho completo, resposta, snapshot congelado e atribuições.
 *
 * Sintoma medido em produção na competência 2026-07: 20 linhas de
 * `desempenho_company_score_snapshots` com `nps_pontos = NULL` e motivo
 * `nps_janela_aberta` em 4 profissionais, TODAS vindas dos links de grupo
 * respondidos naquela manhã — enquanto `NpsPorEmpresaService` recalculado ao
 * vivo devolvia nota 5,0 para as mesmas empresas.
 *
 * O que mascarava o problema: `WarmDesempenhoCache` reescreve o snapshot por
 * empresa a partir do payload CACHEADO, então o `gerado_em` da linha ficava
 * com a hora do último warm (mais recente que a resposta) carimbando conteúdo
 * velho. Com TTL de 7 dias em mês fechado, a tela só se corrigia por acaso —
 * quando alguém respondia um link individual da mesma carteira.
 *
 * @see app/Http/Controllers/NpsGrupoController.php::bustarCacheDoBonusDosEspelhos()
 * @see tests/Feature/NpsTempoRealDesempenhoTest.php (o mesmo contrato, fluxo individual)
 */
class NpsGrupoBustaCacheDesempenhoTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Modelo com 1 pergunta escala (pesos 1..5) na dimensão pedida. */
    private function criarTemplate(array $servicoIds, string $dimensao = NpsTemplateQuestion::DIMENSAO_EMPRESA): NpsTemplate
    {
        $template = NpsTemplate::factory()->create(['active' => true]);

        if ($servicoIds) {
            $template->serviceScopes()->attach($servicoIds);
        }

        $question = NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'Pergunta de grupo ' . uniqid() . '?',
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

    private function criarEmpresaDoGrupo(CompanyGroup $grupo, int $servicoId, User $estrategista, User $analista): Company
    {
        $empresa = Company::factory()->create([
            'active'           => true,
            'company_group_id' => $grupo->id,
            'name'             => 'Empresa ' . uniqid(),
        ]);

        $this->criarContrato($empresa->id, $servicoId, true);
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servicoId);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoId);

        return $empresa;
    }

    private function gerarLinkDeGrupo(CompanyGroup $grupo, NpsTemplate $template, User $admin): NpsGroupSurvey
    {
        // Criação direta (não via `actingAs()->post()`): `actingAs()` fixaria
        // a sessão do admin e o submit PÚBLICO logo abaixo cairia no guard
        // "usuário interno respondendo" (T-119.1-26). Mesmo motivo registrado
        // em `Phase119_1\NpsGrupoSurveyTest::gerarLinkDeGrupo()`.
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
    // 1 — o submit de grupo derruba a competência certa (coleta − 1 mês)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_resposta_de_grupo_invalida_o_cache_da_competencia_certa(): void
    {
        // Coleta em agosto ⇒ competência financeira julho. É a mesma régua do
        // fluxo individual: bustar agosto seria esquecer chave que ninguém lê.
        Carbon::setTestNow(Carbon::parse('2026-08-18 10:40:00'));

        $grupo   = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);

        $estrategista = User::factory()->create(['role' => 'mentor', 'active' => true]);
        $analista     = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $this->criarEmpresaDoGrupo($grupo, $servico, $estrategista, $analista);
        $this->criarEmpresaDoGrupo($grupo, $servico, $estrategista, $analista);

        $template = $this->criarTemplate([$servico]);
        $questao  = $template->questions->first();
        $opcao5   = $questao->options->firstWhere('peso', 5);

        $scoreService = app(DesempenhoScoreService::class);

        $chaves = [
            'analista_julho'      => $scoreService->cacheKey($analista->id, Carbon::parse('2026-07-01')),
            'estrategista_julho'  => $scoreService->cacheKey($estrategista->id, Carbon::parse('2026-07-01')),
            'analista_agosto'     => $scoreService->cacheKey($analista->id, Carbon::parse('2026-08-01')),
        ];

        foreach ($chaves as $chave) {
            Cache::put($chave, ['valor' => 'stale'], now()->addDays(7));
        }

        $groupSurvey = $this->gerarLinkDeGrupo($grupo, $template, User::factory()->create(['role' => 'admin']));

        $this->post(route('nps.grupo.submit', $groupSurvey->token), [
            'respondent_name' => 'Cliente do grupo',
            'answers'         => [(string) $questao->id => $opcao5->id],
        ])->assertOk();

        $this->assertFalse(Cache::has($chaves['analista_julho']),
            'a resposta de GRUPO coletada em agosto alimenta a competência julho do analista — a chave tem de cair.');
        $this->assertFalse(Cache::has($chaves['estrategista_julho']),
            'o estrategista das mesmas empresas também é alimentado pela resposta — a chave dele cai junto.');
        $this->assertTrue(Cache::has($chaves['analista_agosto']),
            'a competência de agosto não é alimentada por esta coleta; bustá-la seria esquecer chave alheia.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2 — funciona mesmo sem atribuição congelada (fallback por carteira)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_busting_de_grupo_funciona_sem_atribuicao_congelada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-18 10:40:00'));

        $grupo   = CompanyGroup::create(['name' => 'Camillo Parts']);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);

        $estrategista = User::factory()->create(['role' => 'mentor', 'active' => true]);
        $analista     = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $this->criarEmpresaDoGrupo($grupo, $servico, $estrategista, $analista);

        // Modelo SEM serviço coberto — o estado dos 3 modelos ativos de
        // produção. `NpsSnapshotService::registrar()` não cria assignment
        // nenhum aqui, então só o fallback por `company_users` salva.
        $template = $this->criarTemplate([]);
        $questao  = $template->questions->first();
        $opcao5   = $questao->options->firstWhere('peso', 5);

        $scoreService = app(DesempenhoScoreService::class);
        $chaveJulho   = $scoreService->cacheKey($analista->id, Carbon::parse('2026-07-01'));
        Cache::put($chaveJulho, ['valor' => 'stale'], now()->addDays(7));

        $groupSurvey = $this->gerarLinkDeGrupo($grupo, $template, User::factory()->create(['role' => 'admin']));

        $this->post(route('nps.grupo.submit', $groupSurvey->token), [
            'respondent_name' => 'Cliente do grupo',
            'answers'         => [(string) $questao->id => $opcao5->id],
        ])->assertOk();

        $this->assertSame(0, DB::table('nps_score_assignments')->count(),
            'o cenário precisa MESMO estar sem atribuição — senão o teste não prova o fallback.');
        $this->assertFalse(Cache::has($chaveJulho),
            'sem atribuição congelada, o fallback pelos responsáveis da empresa tem de derrubar a chave.');
    }
}
