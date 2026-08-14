<?php

namespace Tests\Feature;

use App\Models\Company;
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
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Item 3 da spec de 2026-08-14 — cada resposta reflete no `/performance` na
 * hora, sem esperar o fechamento do NPS.
 *
 * O diagnóstico surpreendeu: o CÁLCULO já era ao vivo. `computeNpsWindow()`
 * devolve a média assim que existe resposta, mesmo com a janela de coleta
 * aberta. O que segurava a nota era o CACHE — `computeCached()` guarda 7 DIAS
 * para mês fechado, e o busting só rodava ao invalidar/revalidar uma resposta,
 * nunca quando o cliente respondia. A nota só aparecia quando o TTL vencia ou
 * o snapshot mensal era gravado: o "só aparece depois que o NPS fecha".
 *
 * Dois furos fechados:
 *  1. `submitResponseV15()` passou a bustar o cache do bônus, FORA da
 *     transação (esvaziar cache não é rollbackável) e com falha engolida — a
 *     resposta do cliente já está gravada e não pode cair por causa disso.
 *  2. `bustarCacheDoBonus()` ganhou fallback pelos responsáveis da empresa:
 *     ele dependia de `nps_score_assignments`, que em produção está zerado
 *     (os 3 modelos ativos não têm serviço coberto), então não derrubava nada.
 *
 * A competência bustada é `completed_at − 1 mês`: a resposta coletada em
 * agosto alimenta o bônus de julho. Bustar agosto seria esquecer uma chave que
 * ninguém lê.
 */
class NpsTempoRealDesempenhoTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param array<int,string> $dimensoes por padrão só `empresa`; a nota do
     *        PROFISSIONAL lê a dimensão do cargo dele (analista/estrategista),
     *        então quem testa a nota precisa pedir a dimensão certa.
     */
    private function templateComEmpresa(array $servicoIds = [], ?array $dimensoes = null): NpsTemplate
    {
        NpsTemplate::query()->update(['is_default' => false]);

        $template = NpsTemplate::factory()->create([
            'nome'       => 'Modelo tempo real ' . uniqid(),
            'active'     => true,
            'is_default' => true,
        ]);

        $ordem = 1;
        foreach ($dimensoes ?? [NpsTemplateQuestion::DIMENSAO_EMPRESA] as $dim) {
            $q = NpsTemplateQuestion::create([
                'template_id' => $template->id,
                'texto'       => "Como foi ({$dim})? " . uniqid(),
                'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                'dimensao'    => $dim,
                'obrigatoria' => true,
                'ordem'       => $ordem++,
            ]);

            for ($peso = 1; $peso <= 5; $peso++) {
                NpsTemplateOption::create([
                    'question_id' => $q->id,
                    'label'       => (string) $peso,
                    'peso'        => $peso,
                    'ordem'       => $peso,
                ]);
            }
        }

        foreach ($servicoIds as $sid) {
            $template->serviceScopes()->attach($sid);
        }

        return $template->fresh(['questions.options']);
    }

    private function responder(Company $empresa, NpsTemplate $template, Carbon $mesColeta, int $peso = 5): void
    {
        $survey = NpsSurvey::create([
            'token'           => Str::uuid()->toString(),
            'company_id'      => $empresa->id,
            'generated_by'    => null,
            'expires_at'      => $mesColeta->copy()->endOfMonth(),
            'status'          => 'pending',
            'template_id'     => $template->id,
            'month_reference' => $mesColeta->copy()->startOfMonth()->toDateString(),
            'auto_generated'  => true,
        ]);

        $answers = [];
        foreach ($template->questions as $q) {
            $answers[(string) $q->id] = $q->options->firstWhere('peso', $peso)->id;
        }

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente ' . uniqid(),
            'answers'         => $answers,
        ])->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1 — responder derruba o cache da COMPETÊNCIA certa (coleta − 1 mês)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_resposta_invalida_o_cache_do_desempenho_da_competencia_certa(): void
    {
        // Coleta em agosto ⇒ competência julho.
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $analista    = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $empresa     = Company::factory()->create(['active' => true]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        $template = $this->templateComEmpresa([$servicoPerf]);

        $scoreService = app(DesempenhoScoreService::class);
        $chaveJulho   = $scoreService->cacheKey($analista->id, Carbon::parse('2026-07-01'));
        $chaveAgosto  = $scoreService->cacheKey($analista->id, Carbon::parse('2026-08-01'));

        Cache::put($chaveJulho, ['valor' => 'stale'], now()->addDays(7));
        Cache::put($chaveAgosto, ['valor' => 'intocado'], now()->addDays(7));

        $this->responder($empresa, $template, Carbon::parse('2026-08-01'));

        $this->assertFalse(Cache::has($chaveJulho),
            'a resposta coletada em agosto alimenta a competência JULHO — é essa chave que tem de cair.');
        $this->assertTrue(Cache::has($chaveAgosto),
            'a competência de agosto não é alimentada por esta resposta; bustá-la seria esquecer chave alheia.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2 — funciona MESMO sem nps_score_assignments (o caso de produção)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_busting_funciona_mesmo_sem_atribuicao_congelada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $analista    = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $empresa     = Company::factory()->create(['active' => true]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        // Modelo SEM serviço coberto — exatamente o estado dos 3 modelos ativos
        // em produção. `registrar()` não gera assignment nenhum neste cenário.
        $template = $this->templateComEmpresa([]);

        $scoreService = app(DesempenhoScoreService::class);
        $chaveJulho   = $scoreService->cacheKey($analista->id, Carbon::parse('2026-07-01'));
        Cache::put($chaveJulho, ['valor' => 'stale'], now()->addDays(7));

        $this->responder($empresa, $template, Carbon::parse('2026-08-01'));

        $this->assertSame(0, DB::table('nps_score_assignments')->count(),
            'o cenário precisa MESMO estar sem atribuição — senão o teste não prova o fallback.');
        $this->assertFalse(Cache::has($chaveJulho),
            'sem atribuição congelada, o fallback pelos responsáveis da empresa tem de derrubar a chave.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3 — a nota recalculada já reflete a resposta, com a janela ABERTA
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_nota_do_nps_ja_reflete_a_resposta_antes_do_fechamento(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));

        $analista    = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $empresa     = Company::factory()->create(['active' => true]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);

        // A nota do PROFISSIONAL sai da dimensão do cargo dele (aqui,
        // analista) — um template só com a dimensão `empresa` não produz nota
        // nenhuma para ele, e o teste passaria a medir outra coisa.
        $template = $this->templateComEmpresa([$servicoPerf], [
            NpsTemplateQuestion::DIMENSAO_ANALISTA,
            NpsTemplateQuestion::DIMENSAO_EMPRESA,
        ]);
        $this->responder($empresa, $template, Carbon::parse('2026-08-01'));

        // Julho é competência FECHADA (estamos em agosto), mas a coleta de
        // agosto ainda corre — é o estado em que a nota antes não aparecia.
        $resultado = app(DesempenhoScoreService::class)
            ->compute($analista, Carbon::parse('2026-07-01'));

        $this->assertNotNull($resultado['componentes']['nps_medio'] ?? null,
            'com resposta na coleta corrente, o NPS de julho não pode vir vazio.');
        $this->assertGreaterThan(0, $resultado['componentes']['nps_medio'],
            'a nota real da resposta tem de estar valendo antes do fechamento.');
    }
}
