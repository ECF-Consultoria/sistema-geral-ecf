<?php

namespace Tests\Feature\V16;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 80 Plan 03 (DEC-80-E) — os WIDGETS de apresentação do
 * `PerformanceController::dashboardCarteira` refletem as atribuições congeladas
 * da Fase 79.
 *
 * Contexto do sintoma que o usuário reportou: os planos 80-01/80-02 corrigiram o
 * BÔNUS (a nota do NPS Shopee da Decoral passou a somar no ranking e no headline
 * `nps.media`, que vêm do `DesempenhoScoreService`). Mas os três leitores de
 * apresentação — coluna NPS por empresa, últimas respostas e heatmap — fazem
 * query PRÓPRIA com `->principal()` e continuavam mentindo: a pessoa via a média
 * subir sem enxergar de onde veio.
 *
 * Esta suite prova o payload do Inertia, não o cálculo do bônus (esse é coberto
 * por `BonusAtribuicoesNpsTest` / `BonusDualPathRegressaoTest`). O helper do
 * controller é APRESENTAÇÃO: se ele divergir do service, o service ganha.
 *
 * As atribuições são geradas pelo FLUXO REAL (`POST /nps/{token}` →
 * `NpsSnapshotService::registrar`), não inseridas na mão — igual à suite do 80-01.
 *
 * @see .planning/phases/80-.../80-03-PLAN.md
 * @see .planning/phases/80-.../80-CONTEXT.md §DEC-80-E
 */
class WidgetNpsAtribuicoesTest extends TestCase
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

        // Congela o "agora": o submit grava `completed_at = now()`, então as
        // respostas caem dentro das 3 janelas dos widgets (60d / 4 últimas / 6 meses).
        Carbon::setTestNow(Carbon::parse('2026-08-01 14:05:00'));

        // `dashboardCarteira` chama `computeCached` → `compute` → var de faturamento,
        // que pode consultar o provider de métricas do ML. Nenhum teste de widget
        // deve depender de rede: qualquer chamada não prevista falha alto.
        Http::preventStrayRequests();
        Http::fake();

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-80-03',
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

    /**
     * User que o `DashboardController` roteia para o `dashboardCarteira`:
     * role 'consultor' (Analista), ativo, NÃO admin e NÃO líder.
     */
    private function criarUserComCargo(string $nome, int $cargoId): User
    {
        $user = User::factory()->create([
            'name'   => $nome,
            'role'   => 'consultor',
            'active' => true,
        ]);
        $this->atribuirCargo($user, $cargoId);

        return $user;
    }

    /**
     * Template v15 escala 1..5 com 1 pergunta por dimensão, cobrindo `$servicoIds`.
     * `$principal = true` marca como NPS Padrão (`is_default`) — desmarcando antes
     * o seed padrão (unique parcial) e resetando o cache do `principalId()`
     * (80-RESEARCH Pitfall 5).
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

    /** Payload de answers (question_id => option_id) com o mesmo peso em todas as perguntas. */
    private function payloadComPeso(NpsTemplate $template, int $peso): array
    {
        $answers = [];
        foreach ($template->questions as $q) {
            $answers[(string) $q->id] = $q->options->firstWhere('peso', $peso)->id;
        }

        return $answers;
    }

    /** Responde o survey pelo FLUXO REAL (passa pelo NpsSnapshotService). */
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
     * Cenário âncora — o caso REAL da Decoral (validado em prod 2026-07-14):
     * uma empresa com NPS Padrão (principal, cobre performance) respondido nota 5
     * e NPS Shopee (não-principal, cobre shopee) respondido nota 2, com
     * responsáveis DISTINTOS para cada serviço.
     *
     * @return array{company: Company, analistaMl: User, analistaShopee: User}
     */
    private function cenarioDecoral(): array
    {
        // Fase 119.1 (D1, 2026-07-29) — os templates seed "NPS Padrão"/"NPS
        // Shopee" (migrations 100004/79-02) nascem `envio_automatico_mensal
        // = true` e cobrem os serviços catalogados de performance/shopee.
        // Este teste (Fase 80) cria os SEUS PRÓPRIOS templates ad-hoc
        // (`criarTemplateEscopado`, sempre `envio_automatico_mensal =
        // false`) para exercitar SÓ a dedupe do widget — nunca teve a
        // intenção de testar elegibilidade de NPS. Sem neutralizar os
        // templates seed aqui, a Decoral (que reaproveita o serviço shopee
        // catalogado via `inserirLinhaShopee()`) fica GENUINAMENTE elegível
        // ao "NPS Shopee" seed, que nunca recebeu link nenhum neste
        // cenário — e o ramo (D) do widget (Plano 119.1-09) soma uma nota
        // fantasma de propósito, correta pela regra nova, mas fora do
        // escopo deste teste (que quer só a dedupe A/B/C). Neutralizado
        // aqui, não em produção — nenhuma regra de elegibilidade muda.
        NpsTemplate::query()->update(['envio_automatico_mensal' => false]);

        $cenario     = $this->criarCenarioMlComResponsaveis();
        $company     = $cenario['company'];
        $servicoPerf = $cenario['servicoPerf'];
        $analistaMl  = $cenario['analista'];

        $company->update(['name' => 'Decoral', 'active' => true]);

        // O analista de ML precisa do cargo (define a dimensão do ramo legado dele).
        $analistaMl->update(['role' => 'consultor', 'active' => true]);
        $this->atribuirCargo($analistaMl, $this->cargoAnalistaId);

        // Responsável APENAS pelo Shopee da MESMA empresa.
        $analistaShopee = $this->criarUserComCargo('Gustavo', $this->cargoAnalistaId);
        $slotShopee     = $this->inserirLinhaShopee($company->id, $analistaShopee->id, 'consultor');

        // NPS Padrão (PRINCIPAL, cobre performance) — peso 5 → 5.0 ao analista de ML.
        $tplPadrao = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$servicoPerf],
            principal: true,
        );
        $this->responder($company, $tplPadrao, 5);

        // NPS Shopee (NÃO-principal, cobre shopee) — peso 2 → 2.0 ao analista de Shopee.
        $tplShopee = $this->criarTemplateEscopado(
            [NpsTemplateQuestion::DIMENSAO_ANALISTA],
            [$slotShopee['servicoShopee']],
        );
        $this->responder($company, $tplShopee, 2);

        return [
            'company'        => $company->fresh(),
            'analistaMl'     => $analistaMl,
            'analistaShopee' => $analistaShopee,
        ];
    }

    /** Extrai o prop `nps` do payload Inertia do dashboard de carteira. */
    private function npsDoDashboard(User $user): array
    {
        $props = null;

        $this->actingAs($user)
            ->get('/dashboard/mercadolivre')
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $page->component('Performance/Dashboard');
                $props = $page->toArray()['props']['nps'] ?? null;
            });

        $this->assertIsArray($props, 'O payload Inertia deveria trazer o prop `nps`.');

        return $props;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 1 — ÂNCORA: a resposta do NPS Shopee aparece para quem é do Shopee
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_widget_lista_resposta_shopee_do_responsavel(): void
    {
        $cenario = $this->cenarioDecoral();

        $nps       = $this->npsDoDashboard($cenario['analistaShopee']);
        $respostas = collect($nps['respostas'] ?? []);

        // O que o usuário reportou como quebrado: a resposta do NPS Shopee
        // simplesmente NÃO existia na lista (morria no `->principal()`).
        $this->assertCount(
            1,
            $respostas,
            'O responsável Shopee deveria ver exatamente 1 resposta (a do NPS Shopee).',
        );

        $resposta = $respostas->first();

        $this->assertSame('Decoral', $resposta['empresa']);
        // Cast explícito: o payload trafega em JSON, que não distingue 2.0 de 2
        // (`json_encode` sem JSON_PRESERVE_ZERO_FRACTION emite `2`). O que importa
        // aqui é o VALOR da nota, não o tipo PHP depois do decode.
        $this->assertSame(2.0, (float) $resposta['nota'], 'A nota listada deve ser a da ATRIBUIÇÃO do Shopee.');
        $this->assertSame(
            Servico::SETOR_SHOPEE,
            $resposta['area'],
            'A resposta precisa se identificar como Shopee (o front traduz o slug para "Shopee").',
        );

        // Isolamento (T-80-10): a nota de ML da MESMA empresa não pode vazar para
        // ele — nem como item da lista, nem como valor.
        // `->map((float))` é OBRIGATÓRIO: `assertNotContains` compara por
        // IDENTIDADE, então 5.0 nunca casaria com o int 5 que volta do JSON e o
        // guard passaria vazio (verde sem provar nada).
        $this->assertNotContains(
            5.0,
            $respostas->pluck('nota')->map(fn ($n) => (float) $n)->all(),
            'O responsável Shopee NÃO pode ver a nota do NPS de ML da mesma empresa.',
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 2 — o sentido inverso: o analista de ML segue vendo só o ML
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_analista_ml_nao_ve_resposta_shopee_da_mesma_empresa(): void
    {
        $cenario = $this->cenarioDecoral();

        $nps       = $this->npsDoDashboard($cenario['analistaMl']);
        $respostas = collect($nps['respostas'] ?? []);

        $this->assertCount(
            1,
            $respostas,
            'O analista de ML deveria ver exatamente 1 resposta (a do NPS Padrão).',
        );

        $resposta = $respostas->first();

        $this->assertSame(5.0, (float) $resposta['nota'], 'A nota listada deve ser a do NPS de ML.');
        $this->assertSame(
            Servico::SETOR_PERFORMANCE,
            $resposta['area'],
            'A área precisa ser performance (o front traduz para "Mercado Livre").',
        );

        // Ver nota sobre identidade vs JSON no teste acima.
        $this->assertNotContains(
            2.0,
            $respostas->pluck('nota')->map(fn ($n) => (float) $n)->all(),
            'O analista de ML NÃO pode receber a nota do NPS Shopee da mesma empresa.',
        );
    }
}
