<?php

namespace Tests\Feature\Phase121;

use App\Models\Company;
use App\Models\DesempenhoComparadorProfissional;
use App\Models\Servico;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreService;
use App\Services\Metrics\MetricDiffDispatcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Gate nº 2 do `121-VALIDATION.md` — prova a decomposição do delta (D-02) do
 * comando `desempenho:comparar-score-empresa`: as três parcelas isolam uma
 * variável por vez (margem pp × relativa, régua-por-empresa × régua-da-média,
 * denominador), o resíduo da interação é SEMPRE persistido — inclusive
 * quando domina — e `maior_causa_delta` nomeia corretamente a maior
 * magnitude, com `interacao_nao_decomposta` quando é o resíduo.
 *
 * IMPORTANTE (o que este arquivo NÃO prova): que `parcelas + residuo` soma
 * exatamente o `delta` é uma IDENTIDADE da própria fórmula do resíduo
 * (`residuo = delta − soma(parcelas)`) — não uma coincidência estatística. O
 * que este arquivo prova é que a decomposição é CALCULADA E PERSISTIDA
 * corretamente a partir de insumos conhecidos à mão, e que o resíduo aparece
 * como número visível (inclusive não-zero) em vez de ser escondido ou
 * fatiado para "fechar 100%" à força.
 *
 * CORREÇÃO PÓS-PLAN-CHECK (D-06/D-07, `<correcao_pos_plan_check>` do
 * `121-03-PLAN.md`): este teste NÃO cobre equivalência de réguas espelho —
 * nenhuma foi criada. `reguaFaturamento()`/`reguaMargem()` de
 * `DesempenhoScoreService` viraram PÚBLICAS (D-07) e são chamadas direto
 * pelo comando; a equivalência delas contra a cópia privada de
 * `CompanyScoreService` continua coberta por
 * `tests/Feature/Phase119/CompanyScoreServiceReguasTest.php` (obrigatória
 * enquanto a cópia existir — não duplicada aqui). A faixa usa
 * `BonusFaixa::classificar()` direto (D-06), que também não é uma cópia.
 *
 * Estratégia de fixture: a nota ANTIGA (legada) roda pelo pipeline REAL de
 * `DesempenhoScoreService::compute()` (carteira real via
 * `CarteiraContextService`), com `MetricDiffDispatcher` mockado por
 * `company_id` — o MESMO mock intercepta tanto a agregação legada quanto a
 * releitura interleaved do comando. A nota NOVA (por empresa) roda 100% dos
 * números fornecidos via mock de `CompanyScoreService::computeEmpresasScore()`
 * — isso permite escolher os dois lados do delta à mão e conferir cada
 * parcela por leitura.
 */
class DecomposicaoDeltaTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private int $setorId;
    private int $cargoAnalistaId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');

        // Agosto/2026 é "hoje" — julho/2026 (a competência alvo) fica fechada.
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        Http::preventStrayRequests();

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-121-03',
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

    // ═══ Helpers ══════════════════════════════════════════════════════════

    private function criarUserComCargo(string $nome): User
    {
        $user = User::factory()->create(['name' => $nome, 'role' => 'consultor', 'active' => true]);

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $this->setorId,
            'cargo_id'     => $this->cargoAnalistaId,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $user;
    }

    /**
     * Empresa da carteira REAL do profissional — alimenta a nota ANTIGA
     * (legada) via `DesempenhoScoreService::compute()`. `$diffMargemLegado`
     * é o valor que o dispatcher mockado devolve para esta empresa
     * (`reguaMargem($diffMargemLegado)` vira a nota antiga inteira, já que
     * faturamento/NPS ficam ausentes por construção desta fixture).
     */
    private function criarCompanyLegado(User $user): Company
    {
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $company     = Company::factory()->create();
        $this->criarContrato($company->id, $servicoPerf, true);
        $this->inserirPivot($company->id, $user->id, 'consultor', $servicoPerf);

        return $company;
    }

    /**
     * Monta uma linha `object` no shape de
     * `CompanyScoreService::computeEmpresasScore()` — só os campos que o
     * comando/decomposição leem.
     */
    private function linha(
        int $companyId,
        ?string $fonteFinanceira,
        string $status,
        ?float $npsPontos,
        ?float $faturamentoVarPct,
        ?float $faturamentoPontos,
        ?float $margemVarPp,
        ?float $margemPontos,
        ?float $notaEmpresa,
        ?float $notaEmpresaParcial
    ): object {
        return (object) [
            'company_id'            => $companyId,
            'company_name'          => "Empresa {$companyId}",
            'fonte_financeira'      => $fonteFinanceira,
            'nps_pontos'            => $npsPontos,
            'faturamento_atual'     => 1000.0,
            'faturamento_anterior'  => 900.0,
            'faturamento_var_pct'   => $faturamentoVarPct,
            'faturamento_pontos'    => $faturamentoPontos,
            'margem_pct_atual'      => 20.0,
            'margem_pct_anterior'   => 18.0,
            'margem_var_pp'         => $margemVarPp,
            'margem_pontos'         => $margemPontos,
            'componentes_presentes' => $notaEmpresa !== null ? 3 : 0,
            'nota_empresa'          => $notaEmpresa,
            'nota_empresa_parcial'  => $notaEmpresaParcial,
            'status'                => $status,
            'quality'               => [
                'revenue_diff_source' => 'adman_diff',
                'margin_diff_source'  => 'adman_diff',
                'margin_source'       => null,
                'motivos'             => [],
            ],
        ];
    }

    /**
     * Substitui `CompanyScoreService::computeEmpresasScore()` pela coleção
     * fornecida (nota NOVA) e `MetricDiffDispatcher::compute()` por um dublê
     * que devolve `contribution_margin_pct.diff_pct` por `company_id`
     * (`$margemDiffPorCompanyId`) — o MESMO dublê alimenta a agregação
     * legada real (nota ANTIGA) e a releitura interleaved do comando
     * (nota NOVA · P1). `revenue.diff_pct` fica sempre `null` — mantém o
     * componente de faturamento fora da nota legada, deixando-a
     * inteiramente definida pela margem (fixture hand-computável).
     *
     * @param  array<int, ?float>  $margemDiffPorCompanyId
     */
    private function instalarMocks(Collection $linhasEmpresasScore, array $margemDiffPorCompanyId): void
    {
        $this->mock(CompanyScoreService::class, function ($mock) use ($linhasEmpresasScore) {
            $mock->shouldReceive('computeEmpresasScore')->andReturn($linhasEmpresasScore);
        });

        $this->mock(MetricDiffDispatcher::class, function ($mock) use ($margemDiffPorCompanyId) {
            $mock->shouldReceive('compute')
                ->andReturnUsing(function (Company $company, array $periodo, string $source) use ($margemDiffPorCompanyId) {
                    return [
                        'metrics' => [
                            'revenue'                 => ['diff_pct' => null],
                            'contribution_margin_pct' => ['diff_pct' => $margemDiffPorCompanyId[$company->id] ?? null],
                        ],
                    ];
                });
        });
    }

    private function rodarComandoEBuscarLinha(User $user): DesempenhoComparadorProfissional
    {
        $exitCode = Artisan::call('desempenho:comparar-score-empresa', [
            '--mes'       => '2026-07',
            '--historico' => 0,
        ]);

        $this->assertSame(0, $exitCode);

        return DesempenhoComparadorProfissional::query()
            ->where('user_id', $user->id)
            ->where('periodo_key', '2026-07')
            ->firstOrFail();
    }

    // ═══ Cenário 1 — margem domina (P1) ══════════════════════════════════

    #[Test]
    public function test_maior_causa_e_margem_quando_p1_domina_e_soma_das_parcelas_bate_com_o_delta(): void
    {
        $user   = $this->criarUserComCargo('Decomposicao Margem');
        $legado = $this->criarCompanyLegado($user);

        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        // A: nps=5,fat=5,margem_var_pp=2.0(=>4.0) -> nota_empresa=(5+5+4)/3=4,67
        // B: nps=3,fat=3,margem_var_pp=2.0(=>4.0) -> nota_empresa=(3+3+4)/3=3,33
        // nota_nova = (4,67+3,33)/2 = 4,00
        $linhas = collect([
            $companyA->id => $this->linha($companyA->id, 'adman', 'complete', 5.0, 8.0, 5.0, 2.0, 4.0, 4.67, 4.67),
            $companyB->id => $this->linha($companyB->id, 'adman', 'complete', 3.0, 0.0, 3.0, 2.0, 4.0, 3.33, 3.33),
        ]);

        $this->instalarMocks($linhas, [
            $legado->id   => 0.0,   // reguaMargem(0.0) = 3.0 -> nota_antiga = 3.0 (único componente presente)
            $companyA->id => -6.0,  // reguaMargem(-6.0) = 1.0 -> P1 recalcula bem abaixo do original (4.0)
            $companyB->id => -6.0,  // idem
        ]);

        $linha = $this->rodarComandoEBuscarLinha($user);

        $this->assertEqualsWithDelta(3.0, $linha->nota_antiga, 0.01);
        $this->assertEqualsWithDelta(4.0, $linha->nota_nova, 0.01);
        $this->assertEqualsWithDelta(1.0, $linha->delta, 0.01);

        $decomposicao = $linha->decomposicao;
        $this->assertIsArray($decomposicao);

        $p1      = $decomposicao['parcelas']['margem_pp_vs_relativa'];
        $p2      = $decomposicao['parcelas']['regua_por_empresa_vs_regua_da_media'];
        $p3      = $decomposicao['parcelas']['denominador'];
        $residuo = $decomposicao['residuo'];

        $this->assertEqualsWithDelta(1.0, $p1, 0.01, 'P1 = nota_nova(4,00) - nota_alt1(3,00) = 1,00.');
        $this->assertEqualsWithDelta(0.0, $p2, 0.01, 'P2 neutro — régua-da-média bate com média-das-réguas nesta fixture.');
        $this->assertEqualsWithDelta(0.0, $p3, 0.01, 'P3 neutro — universo total é o mesmo conjunto C.');
        $this->assertEqualsWithDelta(0.0, $residuo, 0.01);

        // Gate nº 2 — soma das parcelas + resíduo = delta_total.
        $this->assertEqualsWithDelta($decomposicao['delta_total'], $p1 + $p2 + $p3 + $residuo, 0.01);

        $this->assertSame('margem_pp_vs_relativa', $linha->maior_causa_delta);
        $this->assertSame(0, $decomposicao['avisos']['p1_empresas_sem_diff_pct']);
    }

    // ═══ Cenário 2 — denominador domina (P3) ═════════════════════════════

    #[Test]
    public function test_maior_causa_e_denominador_quando_p3_domina(): void
    {
        $user   = $this->criarUserComCargo('Decomposicao Denominador');
        $legado = $this->criarCompanyLegado($user);

        $companyD = Company::factory()->create();
        $companyE = Company::factory()->create();
        $companyF = Company::factory()->create();

        // D e E: gêmeas, nps=4,fat=4,margem_var_pp=2.0(=>4.0) -> nota_empresa=4,00 cada.
        // F: sem_fonte, só NPS=1,0 presente -> nota_empresa_parcial=1,0, FORA de C.
        $linhas = collect([
            $companyD->id => $this->linha($companyD->id, 'adman', 'complete', 4.0, 3.0, 4.0, 2.0, 4.0, 4.0, 4.0),
            $companyE->id => $this->linha($companyE->id, 'adman', 'complete', 4.0, 3.0, 4.0, 2.0, 4.0, 4.0, 4.0),
            $companyF->id => $this->linha($companyF->id, null, 'sem_fonte', 1.0, null, null, null, null, null, 1.0),
        ]);

        $this->instalarMocks($linhas, [
            $legado->id   => 0.0,  // nota_antiga = reguaMargem(0.0) = 3.0
            $companyD->id => 2.0,  // = margem_var_pp -> P1 neutro para D
            $companyE->id => 2.0,  // = margem_var_pp -> P1 neutro para E
        ]);

        $linha = $this->rodarComandoEBuscarLinha($user);

        $this->assertEqualsWithDelta(3.0, $linha->nota_antiga, 0.01);
        $this->assertEqualsWithDelta(4.0, $linha->nota_nova, 0.01);
        $this->assertEqualsWithDelta(1.0, $linha->delta, 0.01);

        $decomposicao = $linha->decomposicao;
        $p1      = $decomposicao['parcelas']['margem_pp_vs_relativa'];
        $p2      = $decomposicao['parcelas']['regua_por_empresa_vs_regua_da_media'];
        $p3      = $decomposicao['parcelas']['denominador'];
        $residuo = $decomposicao['residuo'];

        $this->assertEqualsWithDelta(0.0, $p1, 0.01, 'diff_pct = margem_var_pp para D e E -> P1 não muda nada.');
        $this->assertEqualsWithDelta(0.0, $p2, 0.01, 'D e E são gêmeas -> régua-da-média = média-das-réguas.');
        $this->assertEqualsWithDelta(1.0, $p3, 0.01, 'nota_alt3 = média(4,00; 4,00; 1,00) = 3,00; P3 = 4,00 - 3,00 = 1,00.');
        $this->assertEqualsWithDelta(0.0, $residuo, 0.01);

        $this->assertEqualsWithDelta($decomposicao['delta_total'], $p1 + $p2 + $p3 + $residuo, 0.01);
        $this->assertSame('denominador', $linha->maior_causa_delta);
    }

    // ═══ Cenário 3 — resíduo domina (interação não decomposta) ══════════

    #[Test]
    public function test_residuo_aparece_diferente_de_zero_e_domina_como_maior_causa(): void
    {
        $user   = $this->criarUserComCargo('Decomposicao Residuo');
        $legado = $this->criarCompanyLegado($user);

        $companyD = Company::factory()->create();
        $companyE = Company::factory()->create();

        // D e E gêmeas (mesma fixture do cenário 2, sem F) -> P1=P2=P3=0.
        $linhas = collect([
            $companyD->id => $this->linha($companyD->id, 'adman', 'complete', 4.0, 3.0, 4.0, 2.0, 4.0, 4.0, 4.0),
            $companyE->id => $this->linha($companyE->id, 'adman', 'complete', 4.0, 3.0, 4.0, 2.0, 4.0, 4.0, 4.0),
        ]);

        $this->instalarMocks($linhas, [
            $legado->id   => -6.0, // reguaMargem(-6.0) = 1.0 -> nota_antiga = 1.0 (bem distante da nota nova)
            $companyD->id => 2.0,
            $companyE->id => 2.0,
        ]);

        $linha = $this->rodarComandoEBuscarLinha($user);

        $this->assertEqualsWithDelta(1.0, $linha->nota_antiga, 0.01);
        $this->assertEqualsWithDelta(4.0, $linha->nota_nova, 0.01);
        $this->assertEqualsWithDelta(3.0, $linha->delta, 0.01);

        $decomposicao = $linha->decomposicao;
        $p1      = $decomposicao['parcelas']['margem_pp_vs_relativa'];
        $p2      = $decomposicao['parcelas']['regua_por_empresa_vs_regua_da_media'];
        $p3      = $decomposicao['parcelas']['denominador'];
        $residuo = $decomposicao['residuo'];

        $this->assertEqualsWithDelta(0.0, $p1, 0.01);
        $this->assertEqualsWithDelta(0.0, $p2, 0.01);
        $this->assertEqualsWithDelta(0.0, $p3, 0.01);

        // O resíduo NUNCA é escondido — aparece como número visível mesmo
        // sendo, neste cenário, TODA a explicação do delta (as 3 parcelas
        // isoladas não explicam nada, porque a nota antiga vem de um
        // pipeline metodologicamente diferente do que produz as parcelas).
        $this->assertNotEquals(0.0, $residuo, 'Resíduo tem que ser comprovadamente diferente de zero neste cenário.');
        $this->assertEqualsWithDelta(3.0, $residuo, 0.01);

        $this->assertEqualsWithDelta($decomposicao['delta_total'], $p1 + $p2 + $p3 + $residuo, 0.01);
        $this->assertSame('interacao_nao_decomposta', $linha->maior_causa_delta);
    }

    // ═══ P1 exclui empresa Adman sem diff_pct e conta em avisos ══════════

    #[Test]
    public function test_empresa_adman_sem_diff_pct_sai_de_p1_e_aparece_em_avisos_sem_virar_zero(): void
    {
        $user   = $this->criarUserComCargo('Decomposicao Sem Diff Pct');
        $legado = $this->criarCompanyLegado($user);

        $companyI = Company::factory()->create();

        // Única empresa de C, sem margem_diff_pct — sai inteira de P1.
        $linhas = collect([
            $companyI->id => $this->linha($companyI->id, 'adman', 'complete', 4.0, 3.0, 4.0, 2.0, 4.0, 4.0, 4.0),
        ]);

        $this->instalarMocks($linhas, [
            $legado->id => 0.0, // nota_antiga = 3.0
            // $companyI->id ausente do mapa -> margem_diff_pct persistido como null.
        ]);

        $linha = $this->rodarComandoEBuscarLinha($user);

        $this->assertEqualsWithDelta(3.0, $linha->nota_antiga, 0.01);
        $this->assertEqualsWithDelta(4.0, $linha->nota_nova, 0.01);

        $decomposicao = $linha->decomposicao;

        $this->assertNull(
            $decomposicao['parcelas']['margem_pp_vs_relativa'],
            'Nenhuma empresa sobrou para recalcular P1 — null, NUNCA 0.0.'
        );
        $this->assertSame(1, $decomposicao['avisos']['p1_empresas_sem_diff_pct']);
    }

    // ═══ Aviso de escopo — empresas fora de C que ainda entrariam no
    //     agregado legado (achado do debug residuo-delta-douglas-danilo,
    //     2026-07-31) ══════════════════════════════════════════════════════

    #[Test]
    public function test_aviso_de_escopo_reporta_empresas_fora_de_c_com_fat_ou_margem_presentes(): void
    {
        $user   = $this->criarUserComCargo('Decomposicao Aviso Escopo');
        $legado = $this->criarCompanyLegado($user);

        $companyG = Company::factory()->create();
        $companyH = Company::factory()->create();
        $companyI = Company::factory()->create();

        // G: única empresa de C -> nota_nova = nota_empresa de G.
        // H: 'partial' (sem margem_var_pp), mas com faturamento_var_pct
        //    EXTREMO (250%, ex.: baseline quase-zero no mês anterior) — o
        //    tipo de empresa que entraria sem filtro na média legada de
        //    `computeVarFaturamento()`, mas nunca aparece em nenhuma parcela.
        // I: 'partial' também, com faturamento_var_pct presente mas NÃO
        //    extremo — conta no aviso de escopo, não no de extremo.
        $linhas = collect([
            $companyG->id => $this->linha($companyG->id, 'adman', 'complete', 4.0, 3.0, 4.0, 2.0, 4.0, 4.0, 4.0),
            $companyH->id => $this->linha($companyH->id, 'adman', 'partial', 1.0, 250.0, null, null, null, null, 2.0),
            $companyI->id => $this->linha($companyI->id, 'adman', 'partial', 1.0, -3.0, null, null, null, null, 1.5),
        ]);

        $this->instalarMocks($linhas, [
            $legado->id   => 0.0,  // nota_antiga = reguaMargem(0.0) = 3.0
            $companyG->id => 2.0,  // = margem_var_pp -> P1 neutro para G
        ]);

        $linha = $this->rodarComandoEBuscarLinha($user);

        $this->assertEqualsWithDelta(3.0, $linha->nota_antiga, 0.01);
        $this->assertEqualsWithDelta(4.0, $linha->nota_nova, 0.01);

        $decomposicao = $linha->decomposicao;
        $this->assertIsArray($decomposicao);

        // Gate nº 2 — identidade continua valendo mesmo com o novo aviso.
        $p1      = $decomposicao['parcelas']['margem_pp_vs_relativa'];
        $p2      = $decomposicao['parcelas']['regua_por_empresa_vs_regua_da_media'];
        $p3      = $decomposicao['parcelas']['denominador'];
        $residuo = $decomposicao['residuo'];
        $this->assertEqualsWithDelta($decomposicao['delta_total'], $p1 + $p2 + $p3 + $residuo, 0.01);

        // H e I estão fora de C mas têm faturamento_var_pct presente -> 2.
        $this->assertSame(2, $decomposicao['avisos']['empresas_fora_de_c_no_agregado_legado']);
        // Só H tem |faturamento_var_pct| > 200 -> 1.
        $this->assertSame(1, $decomposicao['avisos']['empresas_fora_de_c_fat_extremo']);
    }

    // ═══ Delta indefinido — guard nunca trata null como zero ════════════

    #[Test]
    public function test_delta_indefinido_persiste_motivo_e_maior_causa_delta_fica_null(): void
    {
        $user   = $this->criarUserComCargo('Decomposicao Delta Indefinido');
        $legado = $this->criarCompanyLegado($user);

        $companyF = Company::factory()->create();

        // Nenhuma empresa 'complete' -> nota_nova fica null -> delta null.
        $linhas = collect([
            $companyF->id => $this->linha($companyF->id, null, 'sem_fonte', 1.0, null, null, null, null, null, 1.0),
        ]);

        $this->instalarMocks($linhas, [
            $legado->id => 0.0, // nota_antiga = 3.0 (não-null, mas nota_nova é que falta)
        ]);

        $linha = $this->rodarComandoEBuscarLinha($user);

        $this->assertEqualsWithDelta(3.0, $linha->nota_antiga, 0.01);
        $this->assertNull($linha->nota_nova);
        $this->assertNull($linha->delta);

        $this->assertSame(['motivo' => 'delta_indefinido'], $linha->decomposicao);
        $this->assertNull($linha->maior_causa_delta);
    }
}
