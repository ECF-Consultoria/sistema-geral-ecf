<?php

namespace Tests\Feature\V16;

use App\Models\Company;
use App\Models\NpsResponse;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 80 Plan 01 (DEC-80-A / B0 / B1 / B / D) — o BÔNUS lê as atribuições
 * congeladas da Fase 79 (`nps_score_assignments`).
 *
 * Área CRÍTICA: `computeNpsMedio` é a régua de bonificação de pessoas reais.
 * Esta suite trava os 4 comportamentos que a Fase 80 entrega:
 *
 *  1. **Ajuste 3 (DEC-80-A)** — a média da pessoa soma as atribuições de TODAS
 *     as áreas (ML + Shopee). NUNCA filtrar por um único `service_setor`.
 *  2. **Shopee acende** — a nota de um NPS Shopee (modelo NÃO-principal) entra
 *     na média de quem responde pelo Shopee. Hoje morre no `->principal()`.
 *  3. **Dedup (DEC-80-A)** — a mesma resposta+papel conta 1× mesmo com 2
 *     serviços cobertos (senão o bônus infla em silêncio).
 *  4. **Isolamento (DEC-80-B1 / D)** — nenhum responsável recebe a nota de um
 *     serviço que não é dele, nos DOIS sentidos.
 *
 * As atribuições são geradas pelo FLUXO REAL (`POST /nps/{token}` →
 * `NpsSnapshotService::registrar`), não inseridas na mão — assim o teste cobre
 * escrita→leitura de ponta a ponta (80-RESEARCH Pitfall 6).
 *
 * `computeNpsMedio` é privado e invocado por reflection — a assinatura
 * `(User, Carbon): float` é contrato de fato (80-RESEARCH "Anti-Patterns").
 *
 * @see .planning/phases/80-.../80-01-PLAN.md
 * @see .planning/phases/80-.../80-CONTEXT.md §DEC-80-A, B0, B1, B, D
 */
class BonusAtribuicoesNpsTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    /** IDs de setor/cargos para a pivot `user_setores` (fonte canônica da dimensão). */
    private int $setorId;
    private int $cargoAnalistaId;

    protected function setUp(): void
    {
        parent::setUp();

        // SQLite in-memory com o schema real (constraints + cascade) — padrão do projeto.
        DB::statement('PRAGMA foreign_keys = ON');

        // Congela o "agora" — o submit grava `completed_at = now()`, então todas
        // as respostas caem em agosto/2026, o mês computado nos asserts.
        Carbon::setTestNow(Carbon::parse('2026-08-01 14:05:00'));

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-80',
            'active'     => true,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->cargoAnalistaId = DB::table('cargos')->insertGetId([
            'setor_id'   => $this->setorId,
            'nome'       => 'Analista',
            'slug'       => 'analista',
            'active'     => true,
            'ordem'      => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════

    /** Mês de referência dos asserts — o mesmo do `completed_at` do submit. */
    private function mesReferencia(): Carbon
    {
        return Carbon::parse('2026-08-01');
    }

    /**
     * Invoca o método privado `computeNpsMedio` por reflection.
     * `computeNpsMedio` não toca o MetricsProviderFactory — resolver o service
     * pelo container não dispara nenhuma HTTP call ao ML/Adman.
     */
    private function invocarComputeNpsMedio(User $user): float
    {
        $service = app(DesempenhoScoreService::class);
        $metodo  = new ReflectionMethod($service, 'computeNpsMedio');
        $metodo->setAccessible(true);

        return $metodo->invoke($service, $user, $this->mesReferencia());
    }

    /** Vincula o user ao cargo informado (define `dimensaoNpsDesempenho`). */
    private function atribuirCargo(User $user, int $cargoId): void
    {
        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $this->setorId,
            'cargo_id'     => $cargoId,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function criarUserComCargo(string $nome, int $cargoId): User
    {
        $user = User::factory()->create(['name' => $nome, 'role' => 'consultor', 'active' => true]);
        $this->atribuirCargo($user, $cargoId);

        return $user;
    }

    /**
     * Template v15 escala 1..5 com 1 pergunta por dimensão, cobrindo `$servicoIds`.
     *
     * `$principal = true` marca como NPS Padrão (`is_default`) — desmarcando antes
     * o seed padrão (unique parcial em `is_default`) e resetando o cache do
     * `principalId()` (80-RESEARCH Pitfall 5: sem isso o ramo legado devolve
     * conjunto vazio e o diagnóstico do RED fica errado).
     */
    private function criarTemplateEscopado(array $dimensoes, array $servicoIds, bool $principal = false): NpsTemplate
    {
        if ($principal) {
            NpsTemplate::query()->update(['is_default' => false]);
        }

        $template = NpsTemplate::factory()->create([
            'nome'       => 'Template Escopado ' . uniqid(),
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

    private function criarSurveyPendente(Company $empresa, NpsTemplate $template): NpsSurvey
    {
        return NpsSurvey::create([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $empresa->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(30),
            'status'       => 'pending',
            'template_id'  => $template->id,
        ]);
    }

    /** Payload de answers (question_id => option_id) com o mesmo peso em todas as perguntas. */
    private function payloadComPeso(NpsTemplate $template, int $peso): array
    {
        $answers = [];
        foreach ($template->questions as $q) {
            $answers[(string) $q->id] = $q->options->firstWhere('peso', $peso)->id;
        }

        return $answers;
    }

    /**
     * Responde o survey pelo FLUXO REAL (passa pelo NpsSnapshotService) e devolve
     * a NpsResponse. `completed_at` = now() congelado = agosto/2026.
     */
    private function responder(Company $empresa, NpsTemplate $template, int $peso): NpsResponse
    {
        $survey = $this->criarSurveyPendente($empresa, $template);

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente ' . uniqid(),
            'answers'         => $this->payloadComPeso($template, $peso),
        ])->assertOk();

        return NpsResponse::where('survey_id', $survey->id)->firstOrFail();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 1 — ÂNCORA do Ajuste 3: a média soma ML + Shopee
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_media_soma_atribuicoes_ml_e_shopee(): void
    {
        // Cenário espelhando o Gustavo (validado em prod na Decoral): Analista
        // (role 'consultor') de uma empresa via serviço PERFORMANCE e de outra
        // via serviço SHOPEE. O NPS de ML dele TEM que agregar (DEC-80-A).
        $gustavo = $this->criarUserComCargo('Gustavo', $this->cargoAnalistaId);

        // Empresa A — ML: Gustavo é o Analista do serviço performance.
        $empresaMl   = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresaMl->id, $servicoPerf, true);
        $this->inserirPivot($empresaMl->id, $gustavo->id, 'consultor', $servicoPerf);

        // Empresa B — Shopee: Gustavo é o Analista do serviço shopee.
        $empresaShopee = Company::factory()->create();
        $slotShopee    = $this->inserirLinhaShopee($empresaShopee->id, $gustavo->id, 'consultor');

        // NPS que cobre performance — peso 4 → média analista 4.0.
        $tplMl = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf]);
        $this->responder($empresaMl, $tplMl, 4);

        // NPS que cobre shopee — peso 2 → média analista 2.0.
        $tplShopee = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$slotShopee['servicoShopee']]);
        $this->responder($empresaShopee, $tplShopee, 2);

        // Ajuste 3: as DUAS áreas somam — (4.0 + 2.0) / 2 = 3.0.
        // Sem filtro de `service_setor`; se filtrasse por shopee daria 2.0.
        $this->assertSame(3.0, $this->invocarComputeNpsMedio($gustavo));
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 2 — a nota do NPS Shopee entra na média de quem cuida do Shopee
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_nps_shopee_entra_na_media_da_pessoa(): void
    {
        // Mesma empresa com NPS Padrão (principal, cobre performance) e NPS
        // Shopee (não-principal, cobre shopee). Responsáveis ML e Shopee DISTINTOS.
        $cenario     = $this->criarCenarioMlComResponsaveis();
        $company     = $cenario['company'];
        $servicoPerf = $cenario['servicoPerf'];
        $analistaMl  = $cenario['analista'];
        $this->atribuirCargo($analistaMl, $this->cargoAnalistaId);

        $analistaShopee = $this->criarUserComCargo('Analista Shopee', $this->cargoAnalistaId);
        $slotShopee     = $this->inserirLinhaShopee($company->id, $analistaShopee->id, 'consultor');

        // NPS Padrão (PRINCIPAL) — peso 5 → atribui 5.0 ao analista de ML.
        $tplPadrao = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$servicoPerf],
            principal: true,
        );
        $this->responder($company, $tplPadrao, 5);

        // NPS Shopee (NÃO-principal) — peso 2 → atribui 2.0 ao analista de Shopee.
        $tplShopee = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$slotShopee['servicoShopee']],
        );
        $this->responder($company, $tplShopee, 2);

        // Hoje o `->principal()` mata a resposta Shopee e o analista Shopee
        // recebe 5.0 (a nota do NPS de ML). Depois da fase: 2.0, a nota dele.
        $this->assertSame(2.0, $this->invocarComputeNpsMedio($analistaShopee));
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 3 — dedup: 1× por (resposta, papel) mesmo com 2 serviços cobertos
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_dedup_conta_uma_vez_por_resposta_e_role(): void
    {
        $gustavo = $this->criarUserComCargo('Gustavo Dedup', $this->cargoAnalistaId);

        // Empresa A: Gustavo é o Analista de DOIS serviços (performance + shopee)
        // e o modelo cobre os DOIS → a MESMA resposta gera 2 linhas de atribuição
        // para o mesmo par (resposta, role='consultor'), com average_score idêntico.
        $empresaA    = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresaA->id, $servicoPerf, true);
        $this->inserirPivot($empresaA->id, $gustavo->id, 'consultor', $servicoPerf);
        $slotShopee = $this->inserirLinhaShopee($empresaA->id, $gustavo->id, 'consultor');

        $tplDuplo = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$servicoPerf, $slotShopee['servicoShopee']],
        );
        $respostaDupla = $this->responder($empresaA, $tplDuplo, 3);

        // Pré-condição do teste: o par duplicado existe de fato (2 linhas).
        $this->assertSame(
            2,
            NpsScoreAssignment::where('nps_response_id', $respostaDupla->id)
                ->where('role', 'consultor')
                ->where('user_id', $gustavo->id)
                ->count(),
            'o cenário precisa produzir 2 atribuições da MESMA (resposta, role) — uma por serviço coberto',
        );

        // Empresa B: 1 resposta com peso 5 → 1 única atribuição (5.0).
        // Ela é o contraste que torna o dedup OBSERVÁVEL: sem uma segunda nota
        // diferente, a média de [3.0, 3.0] seria 3.0 e a duplicata passaria batida.
        $empresaB     = Company::factory()->create();
        $servicoPerfB = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresaB->id, $servicoPerfB, true);
        $this->inserirPivot($empresaB->id, $gustavo->id, 'consultor', $servicoPerfB);

        $tplB = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerfB]);
        $this->responder($empresaB, $tplB, 5);

        // Deduped 1× por (resposta, papel): (3.0 + 5.0) / 2 = 4.0.
        // Se a resposta dupla pesasse 2×: (3.0 + 3.0 + 5.0) / 3 = 3.67 → bônus inflado.
        $this->assertSame(4.0, $this->invocarComputeNpsMedio($gustavo));
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 4 — ISOLAMENTO nos dois sentidos (prova do predicado DEC-80-B1)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_analista_shopee_nao_recebe_nota_ml_da_mesma_empresa(): void
    {
        // Mesma empresa, dois modelos, dois responsáveis distintos.
        // Nenhum dos dois pode receber a média 3.5 dos dois serviços.
        $cenario     = $this->criarCenarioMlComResponsaveis();
        $company     = $cenario['company'];
        $servicoPerf = $cenario['servicoPerf'];
        $analistaMl  = $cenario['analista'];
        $this->atribuirCargo($analistaMl, $this->cargoAnalistaId);

        $analistaShopee = $this->criarUserComCargo('Analista Shopee Isolado', $this->cargoAnalistaId);
        $slotShopee     = $this->inserirLinhaShopee($company->id, $analistaShopee->id, 'consultor');

        // NPS Padrão (PRINCIPAL, performance) — peso 5 → analista de ML.
        $tplPadrao = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$servicoPerf],
            principal: true,
        );
        $this->responder($company, $tplPadrao, 5);

        // NPS Shopee (não-principal, shopee) — peso 2 → analista de Shopee.
        $tplShopee = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$slotShopee['servicoShopee']],
        );
        $this->responder($company, $tplShopee, 2);

        // Sentido 1 — o analista de Shopee NÃO recebe a nota de ML (seria 3.5).
        // É o que o predicado do DEC-80-B1 garante: a resposta do NPS Padrão já
        // tem atribuição no papel 'consultor' (do analista de ML) → o snapshot é
        // AUTORITATIVO naquele papel → ela é pulada no ramo legado dele.
        $this->assertSame(2.0, $this->invocarComputeNpsMedio($analistaShopee));

        // Sentido 2 — o analista de ML NÃO recebe a nota do Shopee (seria 3.5).
        // É o `->principal()` PRESERVADO no ramo legado que garante este lado
        // (80-RESEARCH Pitfall 2). Removê-lo faz a nota Shopee vazar pra cá.
        $this->assertSame(5.0, $this->invocarComputeNpsMedio($analistaMl));
    }
}
