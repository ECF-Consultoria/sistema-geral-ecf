<?php

namespace Tests\Feature\V16;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 80 Plan 02 (DEC-80-B / DEC-80-C / DEC-80-E) — REGRESSÃO do dual-path.
 *
 * O plano 80-01 mexeu na régua que paga pessoas reais. Esta suite é a PROVA de
 * que ele não alterou bônus de nenhum mês sem atribuição, de que o mês de
 * transição soma corretamente, e de que o cache parou de servir a nota antiga.
 *
 * Enquanto a `BonusAtribuicoesNpsTest` (80-01) prova o que MUDOU, esta prova o
 * que NÃO PODE ter mudado:
 *
 *  1. **Regressão histórica (DEC-80-B)** — mês SEM atribuição alguma produz nota
 *     100% do ramo legado. Se o dual-path vazou, o histórico de bônus mudou.
 *  2. **Mês misto (DEC-80-B)** — o mês do deploy tem respostas dos DOIS mundos.
 *     Cada uma conta exatamente 1×; a união é DISJUNTA.
 *  3. **`0.0` preservado (DESEMP-03)** — sem respostas o user é penalizado com
 *     zero, nunca com null.
 *  4. **Guard de carteira** — o guard desceu para `notasLegado` no 80-01; quem
 *     tem atribuição e carteira vazia recebe a média das atribuições.
 *  5. **Cache v3 (DEC-80-C)** — o bump é o que faz a correção APARECER em prod.
 *
 * `computeNpsMedio` é privado e invocado por reflection — a assinatura
 * `(User, Carbon): float` é contrato de fato (80-RESEARCH "Anti-Patterns").
 *
 * @see .planning/phases/80-.../80-02-PLAN.md
 * @see .planning/phases/80-.../80-CONTEXT.md §DEC-80-B, DEC-80-C, DEC-80-E
 */
class BonusDualPathRegressaoTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    /** IDs de setor/cargo para a pivot `user_setores` (fonte canônica da dimensão). */
    private int $setorId;
    private int $cargoAnalistaId;

    protected function setUp(): void
    {
        parent::setUp();

        // SQLite in-memory com o schema real (constraints + cascade) — padrão do projeto.
        DB::statement('PRAGMA foreign_keys = ON');

        // Congela o "agora" — o submit real grava `completed_at = now()`, então
        // toda resposta cai em agosto/2026, o mês computado nos asserts.
        Carbon::setTestNow(Carbon::parse('2026-08-01 14:05:00'));

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-80-02',
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
     * `computeNpsMedio` não toca o `MetricsProviderFactory` — resolver o service
     * pelo container não dispara nenhuma HTTP call ao ML/Adman.
     */
    private function invocarComputeNpsMedio(User $user): float
    {
        $service = app(DesempenhoScoreService::class);
        $metodo  = new ReflectionMethod($service, 'computeNpsMedio');
        $metodo->setAccessible(true);

        return $metodo->invoke($service, $user, $this->mesReferencia());
    }

    private function criarUserComCargo(string $nome, int $cargoId): User
    {
        $user = User::factory()->create(['name' => $nome, 'role' => 'consultor', 'active' => true]);

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $this->setorId,
            'cargo_id'     => $cargoId,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $user;
    }

    /**
     * Template v15 escala 1..5 com 1 pergunta por dimensão, cobrindo `$servicoIds`.
     *
     * `$principal = true` marca como NPS Padrão (`is_default`) desmarcando antes o
     * seed padrão (unique parcial em `is_default`) e resetando o cache do
     * `principalId()` (80-RESEARCH Pitfall 5): sem o reset, o `->principal()` do
     * ramo legado devolve conjunto vazio e o teste "passa" pelo motivo errado —
     * o legado não acharia survey nenhum e a regressão histórica não seria provada.
     */
    private function criarTemplateEscopado(array $dimensoes, array $servicoIds, bool $principal = false): NpsTemplate
    {
        if ($principal) {
            NpsTemplate::query()->update(['is_default' => false]);
        }

        $template = NpsTemplate::factory()->create([
            'nome'       => 'Template 80-02 ' . uniqid(),
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
     * Responde o survey pelo FLUXO REAL (`POST /nps/{token}` → `NpsSnapshotService`)
     * — gera as atribuições da Fase 79. `completed_at` = now() congelado = agosto/2026.
     */
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
            'respondent_name' => 'Cliente ' . uniqid(),
            'answers'         => $this->payloadComPeso($template, $peso),
        ])->assertOk();

        return NpsResponse::where('survey_id', $survey->id)->firstOrFail();
    }

    /**
     * Cria uma resposta do jeito PRÉ-FASE 79: survey + response + answers por factory
     * direta, **sem** passar pelo `NpsSnapshotService` → ZERO linhas em
     * `nps_score_assignments`. É a única forma fiel de simular o histórico: o que já
     * está no banco de prod nunca viu o snapshot.
     *
     * `month_reference` fica NULL de propósito (DEC-80-B0): é o mês do DISPARO, é NULL
     * em muitas linhas reais, e o mês do bônus NUNCA sai dele — sai de `completed_at`.
     * `completed_at` é explícito para cair no mesmo mês do submit real (mês misto).
     */
    private function criarRespostaLegado(
        Company $empresa,
        NpsTemplate $template,
        int $peso,
        ?Carbon $completedAt = null,
    ): NpsResponse {
        $completedAt ??= $this->mesReferencia()->copy()->setDay(10)->setTime(9, 0);

        $survey = NpsSurvey::factory()
            ->for($empresa)
            ->completed()
            ->create([
                'template_id'     => $template->id,
                'month_reference' => null,
                'completed_at'    => $completedAt,
            ]);

        $response = NpsResponse::factory()->create(['survey_id' => $survey->id]);

        foreach ($template->questions as $q) {
            $option = $q->options->firstWhere('peso', $peso);

            NpsResponseAnswer::create([
                'response_id'                => $response->id,
                'template_question_id'       => $q->id,
                'template_option_id'         => $option->id,
                'question_texto_snapshot'    => $q->texto,
                'question_dimensao_snapshot' => $q->dimensao,
                'option_label_snapshot'      => (string) $peso,
                'option_peso_snapshot'       => $peso,
            ]);
        }

        return $response;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 1 — REGRESSÃO HISTÓRICA: mês sem atribuição = nota legada intacta
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_mes_sem_atribuicoes_mantem_nota_legada(): void
    {
        // Cenário do PASSADO: analista com 2 empresas no slot consolidado
        // (`servico_id = NULL`, como ficou o backfill das empresas sem contrato
        // performance ativo) e 2 respostas do modelo principal criadas por factory,
        // sem NUNCA passar pelo NpsSnapshotService — exatamente o que está no banco
        // de prod para todo mês anterior à Fase 79.
        $analista = $this->criarUserComCargo('Analista Legado', $this->cargoAnalistaId);

        $empresaA = Company::factory()->create();
        $empresaB = Company::factory()->create();
        $this->inserirPivot($empresaA->id, $analista->id, 'consultor', null);
        $this->inserirPivot($empresaB->id, $analista->id, 'consultor', null);

        $tplPrincipal = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [],
            principal: true,
        );

        $this->criarRespostaLegado($empresaA, $tplPrincipal, 4);
        $this->criarRespostaLegado($empresaB, $tplPrincipal, 2);

        // Sem esta asserção o teste não prova o que o nome dele diz: se existisse
        // qualquer atribuição, a média poderia estar vindo do ramo novo.
        $this->assertSame(0, NpsScoreAssignment::count(),
            'o cenário histórico precisa ter ZERO atribuições — senão não é o ramo legado que está sendo medido');

        // Média 100% do ramo legado: (4.0 + 2.0) / 2 = 3.0 — o MESMO valor que o
        // código pré-Fase 80 produzia. Qualquer divergência aqui significa que o
        // dual-path vazou e o bônus histórico de alguém mudou.
        $this->assertSame(3.0, $this->invocarComputeNpsMedio($analista),
            'mês sem atribuição deve produzir nota IDÊNTICA à do cálculo legado');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 2 — MÊS MISTO: soma os dois caminhos, cada resposta 1×
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_mes_misto_soma_os_dois_caminhos_sem_duplicar(): void
    {
        // O mês do deploy é MISTO: respostas anteriores ao deploy não têm
        // atribuição (ramo legado) e as posteriores têm (ramo novo). Corte por mês
        // inteiro perderia as antigas — por isso a união é POR RESPOSTA (DEC-80-B).
        $analista = $this->criarUserComCargo('Analista Transicao', $this->cargoAnalistaId);

        // Empresa PÓS-Fase 79: responsável por-serviço preenchido → o submit real
        // gera a atribuição.
        $empresaNova = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresaNova->id, $servicoPerf, true);
        $this->inserirPivot($empresaNova->id, $analista->id, 'consultor', $servicoPerf);

        // Empresa PRÉ-Fase 79: slot consolidado (`servico_id = NULL`) → nunca gera
        // atribuição, sempre cai no legado (fallback PERMANENTE, não ponte).
        $empresaAntiga = Company::factory()->create();
        $this->inserirPivot($empresaAntiga->id, $analista->id, 'consultor', null);

        // O modelo é o PRINCIPAL e cobre performance — é isso que torna a duplicação
        // DETECTÁVEL: a resposta nova ENTRA na query do ramo legado (principal +
        // carteira + mês) e só não conta 2× por causa do skip por papel (DEC-80-B1).
        // Com um modelo não-principal o `->principal()` a filtraria antes e o skip
        // nunca seria exercitado — o teste passaria sem provar nada.
        $tplPrincipal = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$servicoPerf],
            principal: true,
        );

        // (A) resposta NOVA — fluxo real, peso 2 → atribuição 2.0.
        $respostaNova = $this->responder($empresaNova, $tplPrincipal, 2);

        // (B) resposta ANTIGA — factory direta, peso 5 → legado 5.0.
        $respostaAntiga = $this->criarRespostaLegado($empresaAntiga, $tplPrincipal, 5);

        // ── Pré-condições: o cenário é mesmo MISTO ───────────────────────────
        $this->assertSame(1,
            NpsScoreAssignment::where('nps_response_id', $respostaNova->id)
                ->where('user_id', $analista->id)
                ->count(),
            'a resposta nova precisa ter gerado exatamente 1 atribuição para o analista');

        $this->assertSame(0,
            NpsScoreAssignment::where('nps_response_id', $respostaAntiga->id)->count(),
            'a resposta antiga precisa ter ZERO atribuição — é o lado legado do mês misto');

        // As duas respostas TÊM que cair no mesmo mês computado, senão o teste
        // mediria dois meses distintos e o "misto" seria fictício.
        foreach ([$respostaNova, $respostaAntiga] as $r) {
            $this->assertSame('2026-08', $r->survey->completed_at->format('Y-m'),
                'ambas as respostas precisam ter completed_at dentro do mês computado');
        }

        // Deduped: (2.0 + 5.0) / 2 = 3.5.
        // Se a resposta nova contasse 2× (união NÃO disjunta): (2.0 + 2.0 + 5.0) / 3 = 3.0.
        // Delta 0.01 — o legado não arredonda e a atribuição vem de `decimal(5,2)`
        // (80-RESEARCH Pitfall 4); comparar float estrito é frágil por construção.
        $this->assertEqualsWithDelta(3.5, $this->invocarComputeNpsMedio($analista), 0.01,
            'mês misto deve somar os dois caminhos com cada resposta contando exatamente 1×');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 3 — DESEMP-03: sem respostas no mês → 0.0 (penaliza)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_nps_medio_e_zero_sem_respostas_no_mes_com_carteira(): void
    {
        // DESEMP-03 — decisão da diretoria: a ausência de resposta PENALIZA. O
        // dual-path não pode ter transformado o 0.0 em null (o `null` sairia da
        // média em `computeNotaFinal` e a pessoa deixaria de ser penalizada).
        $analista = $this->criarUserComCargo('Analista Sem Resposta', $this->cargoAnalistaId);

        $empresa = Company::factory()->create();
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', null);

        // Principal marcado (Pitfall 5): o ramo legado roda de verdade e devolve
        // vazio por não haver resposta — não por não haver modelo principal.
        $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [],
            principal: true,
        );

        // `assertSame` estrito: exige float 0.0 — recusa `null` e recusa `int` 0.
        $this->assertSame(0.0, $this->invocarComputeNpsMedio($analista),
            'user com carteira e sem respostas no mês continua recebendo 0.0 (nunca null)');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 4 — o guard de carteira vazia não zera mais quem tem atribuição
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_nps_medio_usa_atribuicao_mesmo_sem_carteira_ativa(): void
    {
        // O 80-01 desceu o guard de carteira vazia de `computeNpsMedio` para
        // `notasLegado`. Este teste trava essa mudança: antes, um `return 0.0`
        // no topo zerava o resultado INTEIRO — inclusive as atribuições, que não
        // dependem da carteira viva (é o congelamento da Fase 79).
        $analista = $this->criarUserComCargo('Analista Sem Carteira Ativa', $this->cargoAnalistaId);

        // Empresa INATIVA: sai da carteira (`->where('active', true)`) mas a pivot
        // por-serviço continua lá → o submit gera a atribuição normalmente.
        $empresa     = Company::factory()->create(['active' => false]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        $tpl = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$servicoPerf],
            principal: true,
        );
        $this->responder($empresa, $tpl, 4);

        // ── Pré-condições: carteira ativa REALMENTE vazia + atribuição existe ──
        $this->assertSame(0, $analista->companies()->where('active', true)->count(),
            'a carteira ativa precisa estar vazia — é a condição que o guard testava');
        $this->assertSame(1, NpsScoreAssignment::where('user_id', $analista->id)->count(),
            'a atribuição precisa existir mesmo com a empresa inativa (congelamento da Fase 79)');

        // Recebe a média da atribuição, não 0.0.
        $this->assertSame(4.0, $this->invocarComputeNpsMedio($analista),
            'user sem carteira ativa mas COM atribuição no mês recebe a média da atribuição');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 6 — Fase 96 Plan 04 (AB-96-3): resposta invalidada não conta em
    // NENHUM dos dois ramos (atribuições nem legado) — regressão do bônus.
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_resposta_invalidada_nao_conta_em_nenhum_dos_dois_ramos(): void
    {
        // Prova que a invalidação (AB-96-3) não regrediu o cálculo do bônus
        // para respostas VÁLIDAS: um analista com 1 resposta válida em cada
        // ramo (atribuições + legado) e 1 resposta invalidada em cada ramo
        // deve receber a média SÓ das válidas — exatamente como se as
        // invalidadas nunca tivessem existido.
        $analista = $this->criarUserComCargo('Analista Invalidacao Dual', $this->cargoAnalistaId);

        // ── Ramo (A) atribuições: 1 válida (peso 4) + 1 invalidada (peso 2) ──
        $empresaNova = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresaNova->id, $servicoPerf, true);
        $this->inserirPivot($empresaNova->id, $analista->id, 'consultor', $servicoPerf);

        $tplPrincipal = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$servicoPerf],
            principal: true,
        );

        $respostaAtribuidaValida = $this->responder($empresaNova, $tplPrincipal, 4);
        $respostaAtribuidaInvalida = $this->responder($empresaNova, $tplPrincipal, 2);
        $respostaAtribuidaInvalida->update([
            'invalidated_at' => now(),
            'invalidated_by' => User::factory()->create(['role' => 'admin'])->id,
        ]);

        // ── Ramo (B) legado: 1 válida (peso 5) + 1 invalidada (peso 1) ──
        $empresaAntiga = Company::factory()->create();
        $this->inserirPivot($empresaAntiga->id, $analista->id, 'consultor', null);

        $respostaLegadaValida = $this->criarRespostaLegado($empresaAntiga, $tplPrincipal, 5,
            $this->mesReferencia()->copy()->setDay(5)->setTime(9, 0));
        $respostaLegadaInvalida = $this->criarRespostaLegado($empresaAntiga, $tplPrincipal, 1,
            $this->mesReferencia()->copy()->setDay(20)->setTime(9, 0));
        $respostaLegadaInvalida->update([
            'invalidated_at' => now(),
            'invalidated_by' => User::factory()->create(['role' => 'admin'])->id,
        ]);

        // ── Pré-condições ────────────────────────────────────────────────
        $this->assertSame(1,
            NpsScoreAssignment::where('nps_response_id', $respostaAtribuidaValida->id)->count(),
            'a resposta atribuída válida precisa ter gerado exatamente 1 atribuição');
        $this->assertSame(1,
            NpsScoreAssignment::where('nps_response_id', $respostaAtribuidaInvalida->id)->count(),
            'a resposta atribuída invalidada TAMBÉM gerou atribuição — o filtro precisa excluí-la na leitura, não na gravação');
        $this->assertSame(0,
            NpsScoreAssignment::where('nps_response_id', $respostaLegadaValida->id)
                ->orWhere('nps_response_id', $respostaLegadaInvalida->id)
                ->count(),
            'as respostas legadas não têm atribuição — é o ramo legado que está sendo medido');

        // Se qualquer invalidada ainda contasse: (4+2+5+1)/4 = 3.0.
        // Só as válidas: (4.0 + 5.0) / 2 = 4.5.
        $this->assertSame(4.5, $this->invocarComputeNpsMedio($analista),
            'resposta invalidada não pode contar em NENHUM dos dois ramos — só as válidas entram na média do bônus');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 5 — Fase 102 (BON-04/T-102-03): o cache foi bumpado para v5
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_cache_bumpado_para_v5(): void
    {
        // O bump é o que faz a correção APARECER. Sem ele o código novo sobe em
        // prod e o Redis continua devolvendo a nota antiga por até 7 dias no mês
        // fechado — o ranking /performance, os widgets e o PortfolioController
        // serviriam bônus errado sem nenhum sinal de erro.
        //
        // Nota 2026-07-20 (Fase 102 · BON-01/02/03): o PAR de versões avança mais
        // um passo — a v4 agora é a chave que fica órfã (representa a régua de
        // baseline calendário + margem R$ absoluta, pré-MetricPeriodResolver/
        // AdmanMetricDiffService), e a v5 é o alvo de gravação do cálculo por
        // competência oficial. A chave passa a incluir `period_key` — a chave
        // esperada vem do helper público `cacheKey()` (nunca hardcoded aqui),
        // pois `$mesReferencia()` == mês corrente nesta suíte
        // (`Carbon::setTestNow` congela agosto/2026) → period_key='current_month',
        // não 'YYYY-MM'.
        //
        // User sem carteira de propósito: `compute()` corta cedo no shape
        // sem_carteira e NÃO toca o MetricsProviderFactory — zero HTTP ao ML/Adman.
        $analista = $this->criarUserComCargo('Analista Cache', $this->cargoAnalistaId);

        $mes     = $this->mesReferencia();
        $chaveV4 = sprintf('desempenho.compute.v4.%d.%s', $analista->id, $mes->format('Y-m'));

        /** @var DesempenhoScoreService $service */
        $service = app(DesempenhoScoreService::class);
        $chaveV5 = $service->cacheKey($analista->id, $mes);

        // Lixo RECONHECÍVEL na chave antiga — representa o valor que prod tem hoje
        // no Redis (nota calculada sob a régua de baseline/margem pré-Fase 102).
        // Se `computeCached` ainda apontasse para a v4, ele devolveria este array
        // em vez de recalcular.
        Cache::put($chaveV4, ['nota_final' => 99.9], 600);

        $resultado = $service->computeCached($analista, $mes);

        // O valor v4 é IGNORADO: o retorno é o shape recalculado, não o lixo.
        $this->assertNotSame(99.9, $resultado['nota_final'] ?? null,
            'computeCached NÃO pode devolver o valor cacheado sob a chave v4');
        $this->assertNull($resultado['nota_final'],
            'o retorno deve ser o shape recalculado (sem carteira → nota_final null)');
        $this->assertTrue($resultado['sem_carteira']);
        $this->assertSame($analista->id, $resultado['user_id']);

        // A v5 passou a existir — é onde o valor novo é gravado a partir de agora.
        $this->assertTrue(Cache::has($chaveV5),
            'computeCached deve gravar o resultado sob a chave desempenho.compute.v5 (via helper cacheKey())');

        // A v4 fica intacta e ÓRFÃ: expira sozinha por TTL. Nenhum `cache:clear`
        // é adicionado a script algum, e o WarmDesempenhoCache (cron 8min)
        // repopula a v5 sozinho.
        $this->assertSame(['nota_final' => 99.9], Cache::get($chaveV4),
            'a chave v4 não é apagada — vira órfã e expira por TTL');
    }
}
