<?php

namespace Tests\Feature\Phase118;

use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use App\Services\Desempenho\NpsPorEmpresaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 118 Plano 02 (Task 2) — NPSE-04: a invalidação por competência
 * (`BonusInvalidacao`) roda ANTES do piso da D-04, e o gap de atribuição do
 * responsável consolidado para de ser silencioso (T-118-03).
 *
 * @see .planning/phases/118-nps-por-empresa-v21-0/118-02-PLAN.md
 * @see .planning/phases/118-nps-por-empresa-v21-0/118-CONTEXT.md
 * @see app/Models/BonusInvalidacao.php
 */
class NpsPorEmpresaInvalidacaoTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de fixture (molde de NpsFloorRegressaoTest / NpsPorEmpresaRamosTest)
    // ═══════════════════════════════════════════════════════════════════

    private function service(): NpsPorEmpresaService
    {
        return app(NpsPorEmpresaService::class);
    }

    private function criarTemplateEscopado(array $dimensoes, array $servicoIds, bool $principal = false): NpsTemplate
    {
        if ($principal) {
            NpsTemplate::query()->update(['is_default' => false]);
        }

        $template = NpsTemplate::factory()->create([
            'nome'       => 'Template 118-02 Invalidacao ' . uniqid(),
            'active'     => true,
            'is_default' => $principal,
        ]);

        $ordem = 1;
        foreach ($dimensoes as $dim) {
            $question = NpsTemplateQuestion::create([
                'template_id' => $template->id,
                'texto'       => 'Pergunta ' . $dim . ' ' . uniqid() . '?',
                'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                'dimensao'    => $dim,
                'obrigatoria' => true,
                'ordem'       => $ordem++,
            ]);

            for ($peso = 1; $peso <= 5; $peso++) {
                NpsTemplateOption::create([
                    'question_id' => $question->id,
                    'label'       => (string) $peso,
                    'peso'        => $peso,
                    'ordem'       => $peso,
                ]);
            }
        }

        foreach ($servicoIds as $sid) {
            $template->serviceScopes()->attach($sid);
        }

        if ($principal) {
            NpsTemplate::resetPrincipalCache();
        }

        return $template->fresh(['questions.options']);
    }

    private function payloadComPeso(NpsTemplate $template, int $peso): array
    {
        $answers = [];
        foreach ($template->questions as $q) {
            $answers[(string) $q->id] = $q->options->firstWhere('peso', $peso)->id;
        }

        return $answers;
    }

    /** Responde o survey pelo FLUXO REAL (`POST /nps/{token}`) — gera as atribuições da Fase 79. */
    private function responder(Company $empresa, NpsTemplate $template, int $peso): NpsResponse
    {
        $survey = NpsSurvey::create([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $empresa->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(30),
            'status'       => 'pending',
            'template_id'  => $template->id,
        ]);

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente 118-02',
            'answers'         => $this->payloadComPeso($template, $peso),
        ])->assertOk();

        return NpsResponse::where('survey_id', $survey->id)->firstOrFail();
    }

    /**
     * Cenário-base: 1 empresa, serviço Performance ativo, 1 analista com
     * vínculo ESPECÍFICO em Performance, 1 resposta real nota 5. Montado com
     * `Carbon::setTestNow` na janela de COLETA (julho); cada teste avança o
     * tempo depois de montar, conforme precisa.
     *
     * @return array{empresa: Company, analista: User, s1: int}
     */
    private function montarCenarioComVinculo(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa = Company::factory()->create(['active' => true, 'name' => 'Empresa 118-02 Invalidacao ' . uniqid()]);
        $s1      = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $s1, true);

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $s1);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$s1]);
        $this->responder($empresa, $template, 5);

        return compact('empresa', 'analista', 's1');
    }

    /**
     * Cenário-base SEM nenhum survey — mesma estrutura de carteira, para os
     * casos "sem nenhuma nota" (D-04 genuína / empresa invalidada sem nota).
     *
     * @return array{empresa: Company, analista: User, s1: int}
     */
    private function montarCenarioSemSurvey(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa = Company::factory()->create(['active' => true, 'name' => 'Empresa 118-02 Sem Survey ' . uniqid()]);
        $s1      = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $s1, true);

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $s1);

        // Fase 119.1 (D1) — desde que o D-04 passou a filtrar por
        // elegibilidade (`NpsElegibilidadeService`), o cenário "sem_nps"
        // precisa ser uma empresa REALMENTE elegível: estrategista atribuído
        // + modelo automático aplicável ao serviço contratado. Sem isto, a
        // empresa cairia em `nao_elegivel` em vez de `sem_nps` — o gate
        // correto de NPSMAN-07, mas não o que este teste quer provar.
        $estrategista = User::factory()->create(['active' => true]);
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', null);
        $modelo = NpsTemplate::factory()->create(['active' => true, 'envio_automatico_mensal' => true]);
        $modelo->serviceScopes()->attach($s1);

        return compact('empresa', 'analista', 's1');
    }

    // ═══════════════════════════════════════════════════════════════════
    // NPSE-04 — invalidação antes do piso da D-04
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_controle_sem_invalidacao_a_empresa_entra_com_a_nota_real(): void
    {
        // Este teste NÃO é decorativo: sem ele, os testes seguintes
        // passariam mesmo que a empresa estivesse ausente por qualquer
        // outro motivo (decisão 6 do plano) — é o controle que prova que a
        // AUSÊNCIA nos próximos testes tem a causa certa.
        $c = $this->montarCenarioComVinculo();

        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00'));
        $mes        = Carbon::parse('2026-06-01');
        $invalidadas = BonusInvalidacao::companyIdsInvalidadas($mes);

        $notas = $this->service()->notasNpsPorEmpresa($c['analista'], $mes, true, $invalidadas);
        $linha = $notas->get($c['empresa']->id);

        $this->assertNotNull($linha);
        $this->assertSame(5.0, $linha->nota);
    }

    #[Test]
    public function test_empresa_invalidada_na_competencia_sai_do_nps_por_empresa(): void
    {
        $c = $this->montarCenarioComVinculo();

        BonusInvalidacao::create([
            'company_id'     => $c['empresa']->id,
            'competencia'    => '2026-06-01',
            'invalidated_by' => null,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00'));
        $mes        = Carbon::parse('2026-06-01');
        $invalidadas = BonusInvalidacao::companyIdsInvalidadas($mes);

        $notas = $this->service()->notasNpsPorEmpresa($c['analista'], $mes, true, $invalidadas);

        // Nem a nota real, nem o piso 1.0 da D-04 — a empresa sai de TUDO.
        $this->assertFalse($notas->has($c['empresa']->id));
    }

    #[Test]
    public function test_empresa_invalidada_sem_nenhuma_nota_nao_recebe_o_piso_da_d04(): void
    {
        // Pitfall 3 do RESEARCH: se a D-04 rodasse ANTES da exclusão, a
        // empresa sairia de "excluída do bônus" para "punida com nota
        // mínima" — o oposto exato do propósito da invalidação.
        $c = $this->montarCenarioSemSurvey();

        BonusInvalidacao::create([
            'company_id'     => $c['empresa']->id,
            'competencia'    => '2026-06-01',
            'invalidated_by' => null,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00'));
        $mes        = Carbon::parse('2026-06-01');
        $invalidadas = BonusInvalidacao::companyIdsInvalidadas($mes);

        $notas = $this->service()->notasNpsPorEmpresa($c['analista'], $mes, true, $invalidadas);

        $this->assertFalse($notas->has($c['empresa']->id));
    }

    #[Test]
    public function test_invalidacao_usa_a_competencia_financeira_m_e_nao_o_mes_de_coleta(): void
    {
        // A chave é a competência FINANCEIRA M, nunca deslocada — regra do
        // docblock de `BonusInvalidacao` (linhas 11-20) e de
        // `computeNpsWindow:757-759`. Uma linha gravada no mês de COLETA
        // (M+1) não pode invalidar a competência M.
        $c = $this->montarCenarioComVinculo();

        BonusInvalidacao::create([
            'company_id'     => $c['empresa']->id,
            'competencia'    => '2026-07-01', // mês de COLETA — chave ERRADA de propósito
            'invalidated_by' => null,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00'));
        $mes        = Carbon::parse('2026-06-01'); // competência financeira M
        $invalidadas = BonusInvalidacao::companyIdsInvalidadas($mes);

        $notas = $this->service()->notasNpsPorEmpresa($c['analista'], $mes, true, $invalidadas);
        $linha = $notas->get($c['empresa']->id);

        $this->assertNotNull($linha, 'a invalidação gravada no mês de coleta não pode remover a empresa da competência M.');
        $this->assertSame(5.0, $linha->nota);
    }

    // ═══════════════════════════════════════════════════════════════════
    // T-118-03 — o log que quebra o silêncio do gap de atribuição
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_gap_de_atribuicao_e_logado_quando_houve_survey_mas_nao_houve_nota(): void
    {
        // Empresa com Performance (vínculo do analista) E Shopee (survey
        // respondido, mas SEM vínculo do analista nesse serviço) — houve
        // disparo e resposta na empresa, mas nenhuma das 3 fontes produz
        // nota para ESTE usuário. Distingue o gap de atribuição (memória
        // `project_nps_assignment_consolidado_gap`) de uma D-04 genuína.
        Log::spy();

        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $empresa = Company::factory()->create(['active' => true, 'name' => 'Empresa 118-02 Gap Atribuicao']);
        $s1      = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $s2      = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->criarContrato($empresa->id, $s1, true);
        $this->criarContrato($empresa->id, $s2, true);

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $s1); // SEM vínculo em s2

        // Fase 119.1 (D1) — desde que o D-04 passou a filtrar por
        // elegibilidade (`NpsElegibilidadeService`), esta empresa precisa ser
        // REALMENTE elegível: estrategista atribuído + modelo automático
        // aplicável a algum serviço contratado. Sem isto, ela cairia em
        // `nao_elegivel` em vez de `sem_nps` — correto por NPSMAN-07, mas não
        // o que este teste quer provar (o gap de atribuição, não a elegibilidade).
        $estrategista = User::factory()->create(['active' => true]);
        $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', null);
        $modeloAutomatico = NpsTemplate::factory()->create(['active' => true, 'envio_automatico_mensal' => true]);
        $modeloAutomatico->serviceScopes()->attach($s1);

        $templateShopee = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$s2]);
        $this->responder($empresa, $templateShopee, 4);

        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00'));
        $mes = Carbon::parse('2026-06-01');

        $notas = $this->service()->notasNpsPorEmpresa($analista, $mes, true, collect());
        $linha = $notas->get($empresa->id);

        $this->assertSame(1.0, $linha->nota);
        $this->assertSame('sem_nps', $linha->origem);
        $this->assertTrue($linha->houve_survey);

        // Nota: `NpsSnapshotService::registrar()` também emite um
        // `Log::warning` PRÓPRIO (tag `[NPS Snapshot]`) quando o survey de
        // Shopee é respondido, pois o analista não tem responsável ali —
        // por isso o filtro é pela tag `[NPS por Empresa]`, não por "só 1
        // warning no teste inteiro".
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $mensagem, array $contexto) use ($empresa, $analista) {
                return str_contains($mensagem, '[NPS por Empresa]')
                    && ($contexto['company_id'] ?? null) === $empresa->id
                    && ($contexto['user_id'] ?? null) === $analista->id;
            });
    }

    #[Test]
    public function test_empresa_sem_nenhum_survey_nao_gera_log(): void
    {
        // Sem este par, o log viraria ruído em toda carteira e seria
        // desligado pelo primeiro dev que o visse.
        Log::spy();

        $c = $this->montarCenarioSemSurvey();

        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00'));
        $mes = Carbon::parse('2026-06-01');

        $notas = $this->service()->notasNpsPorEmpresa($c['analista'], $mes, true, collect());
        $linha = $notas->get($c['empresa']->id);

        $this->assertSame(1.0, $linha->nota);
        $this->assertSame('sem_nps', $linha->origem);
        $this->assertFalse($linha->houve_survey);

        Log::shouldNotHaveReceived('warning');
    }
}
