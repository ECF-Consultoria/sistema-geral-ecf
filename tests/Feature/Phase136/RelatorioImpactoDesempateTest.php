<?php

namespace Tests\Feature\Phase136;

use App\Models\Company;
use App\Models\DesempenhoMetricaManual;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 136, Plano 06 (D-11) — prova de que
 * `desempenho:relatorio-impacto-fonte` é READ-ONLY e de que o EXIT CODE é o
 * veredito.
 *
 * Duas disciplinas travadas aqui, nesta ordem de importância:
 *
 *  1. **Read-only pela via mais forte disponível.** O setUp liga
 *     `Http::preventStrayRequests()` e **não** registra NENHUM `Http::fake()`
 *     — qualquer tentativa de chamada HTTP (ou seja, qualquer caminho que
 *     recalculasse nota e disparasse a API da Adman) quebra o teste em vez de
 *     passar silenciosamente. Além disso, as contagens de 5 tabelas são
 *     comparadas antes e depois da execução.
 *
 *  2. **O veredito é o exit code, nunca o texto impresso.** As asserções de
 *     resultado usam `assertExitCode()` e o `--json`; nenhuma depende da
 *     tabela humana. A armadilha de shell de learnings §4
 *     (`comando | tail; echo $?` devolve o exit code do `tail`) é evitada por
 *     construção: o comando roda pelos helpers do Laravel, nunca por shell.
 *
 * Privacidade (learnings §11): há um teste dedicado a asseverar que nenhum
 * item de `profissionais_afetados` carrega chave de resultado individual de
 * bonificação — o relatório versiona contador, não pagamento.
 *
 * @see app/Console/Commands/RelatorioImpactoFonteDesempenho.php
 * @see .planning/phases/136-m-tricas-manuais-por-empresa-m-s-no-desempenho-override-api-/136-06-PLAN.md
 */
class RelatorioImpactoDesempateTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private const MES = '2026-07';

    private const MES_REFERENCIA = '2026-07-01';

    /** Tabelas cuja contagem NÃO pode mudar com a execução do comando. */
    private const TABELAS_VIGIADAS = [
        'companies',
        'company_users',
        'desempenho_company_score_snapshots',
        'desempenho_metricas_manuais',
        'activity_log',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00'));

        // Sem NENHUM Http::fake() de propósito: o comando é read-only e não
        // pode disparar HTTP em caminho nenhum. Se disparar, o teste falha.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    /**
     * Empresa com carteira MISTA (vínculo performance + vínculo shopee) para
     * o mesmo profissional. `$custId` nulo reproduz o caso Interior Magazine
     * (learnings §0.04): sob a regra revogada resolvia 'adman' e ficava em
     * branco; sob D-10 resolve 'shopee'.
     *
     * @return array{company: Company, user: User}
     */
    private function criarEmpresaMista(?string $admanAccountId = null): array
    {
        $company = Company::factory()->create([
            'active'           => true,
            'adman_account_id' => $admanAccountId,
            'ml_store_id'      => null,
        ]);

        $user = User::factory()->create(['active' => true]);

        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);
        $this->inserirPivot($company->id, $user->id, 'consultor', $servicoPerf);

        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->criarContrato($company->id, $servicoShopee, true);
        $this->inserirPivot($company->id, $user->id, 'consultor', $servicoShopee);

        return ['company' => $company, 'user' => $user];
    }

    /**
     * Empresa com UMA única fonte (só shopee) — não tem desempate a resolver,
     * logo não pode aparecer no relatório sob nenhuma circunstância.
     *
     * @return array{company: Company, user: User}
     */
    private function criarEmpresaFonteUnica(): array
    {
        $company = Company::factory()->create(['active' => true]);
        $user    = User::factory()->create(['active' => true]);

        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->criarContrato($company->id, $servicoShopee, true);
        $this->inserirPivot($company->id, $user->id, 'consultor', $servicoShopee);

        return ['company' => $company, 'user' => $user];
    }

    /** Roda o comando com `--json` e devolve o relatório já decodificado. */
    private function relatorioJson(): array
    {
        $exit   = Artisan::call('desempenho:relatorio-impacto-fonte', ['--mes' => self::MES, '--json' => true]);
        $saida  = Artisan::output();
        $dados  = json_decode($saida, true);

        $this->assertIsArray($dados, "A saída --json precisa ser parseável por inteiro. Exit code: {$exit}. Saída: {$saida}");

        return $dados;
    }

    /** @return array<string, int> */
    private function contagens(): array
    {
        $contagens = [];

        foreach (self::TABELAS_VIGIADAS as $tabela) {
            $contagens[$tabela] = DB::table($tabela)->count();
        }

        return $contagens;
    }

    // ─── Exit code é o veredito ──────────────────────────────────────────

    #[Test]
    public function test_cenario_sem_divergencia_e_sem_celula_manual_devolve_exit_code_zero(): void
    {
        // Empresa mista COM conta Adman de fato: a regra antiga e a nova
        // concordam ('adman'), logo não há nada a decidir.
        $this->criarEmpresaMista('CUST-136-06-ADMAN');
        $this->criarEmpresaFonteUnica();

        $this->artisan('desempenho:relatorio-impacto-fonte', ['--mes' => self::MES])
            ->assertExitCode(0);
    }

    #[Test]
    public function test_cenario_com_empresa_mista_sem_cust_id_devolve_exit_code_um(): void
    {
        $this->criarEmpresaMista(null);

        $this->artisan('desempenho:relatorio-impacto-fonte', ['--mes' => self::MES])
            ->assertExitCode(1);
    }

    #[Test]
    public function test_celula_manual_sozinha_ja_derruba_o_exit_code_para_um(): void
    {
        // Nenhuma empresa divergente — só um lançamento manual ativo. O
        // veredito continua sendo "existe impacto a decidir".
        $cenario = $this->criarEmpresaMista('CUST-136-06-ADMAN');

        DesempenhoMetricaManual::create([
            'company_id'     => $cenario['company']->id,
            'mes_referencia' => self::MES_REFERENCIA,
            'metrica'        => DesempenhoMetricaManual::METRICA_FATURAMENTO,
            'valor'          => 1000.00,
            'ativo'          => true,
            'lancado_por'    => $cenario['user']->id,
            'lancado_em'     => now(),
        ]);

        $this->artisan('desempenho:relatorio-impacto-fonte', ['--mes' => self::MES])
            ->assertExitCode(1);
    }

    // ─── Detecção correta e ausência de falso positivo ───────────────────

    #[Test]
    public function test_empresa_divergente_traz_fonte_antiga_adman_fonte_nova_shopee_e_cust_id_ausente(): void
    {
        $cenario = $this->criarEmpresaMista(null);

        $relatorio = $this->relatorioJson();

        $this->assertCount(1, $relatorio['fonte_divergente']);

        $item = $relatorio['fonte_divergente'][0];

        $this->assertSame($cenario['company']->id, $item['company_id']);
        $this->assertSame('adman', $item['fonte_antiga']);
        $this->assertSame('shopee', $item['fonte_nova']);
        $this->assertFalse($item['cust_id_presente']);
        $this->assertFalse($item['tem_adman_account_id']);
        $this->assertFalse($item['tem_ml_store_id']);
    }

    #[Test]
    public function test_empresa_mista_com_adman_account_id_nao_e_reportada_como_divergente(): void
    {
        $this->criarEmpresaMista('CUST-136-06-ADMAN');

        $relatorio = $this->relatorioJson();

        $this->assertSame([], $relatorio['fonte_divergente']);
        $this->assertSame(0, $relatorio['resumo']['total_empresas_divergentes']);
    }

    #[Test]
    public function test_empresa_com_fonte_unica_nunca_entra_no_relatorio(): void
    {
        $unica     = $this->criarEmpresaFonteUnica();
        $divergente = $this->criarEmpresaMista(null);

        $relatorio = $this->relatorioJson();

        $ids = array_column($relatorio['fonte_divergente'], 'company_id');

        $this->assertContains($divergente['company']->id, $ids);
        $this->assertNotContains($unica['company']->id, $ids,
            'Empresa com uma única fonte não tem desempate a resolver — não pode mudar de resultado.');
    }

    // ─── Profissionais afetados: contador, nunca pagamento ───────────────

    #[Test]
    public function test_profissional_afetado_aparece_por_contador_e_sem_resultado_individual_de_bonificacao(): void
    {
        $cenario = $this->criarEmpresaMista(null);

        $relatorio = $this->relatorioJson();

        $this->assertCount(1, $relatorio['profissionais_afetados']);

        $item = $relatorio['profissionais_afetados'][0];

        $this->assertSame($cenario['user']->id, $item['user_id']);
        $this->assertSame($cenario['user']->name, $item['user_name']);
        $this->assertSame(1, $item['empresas_afetadas']);
        $this->assertSame([$cenario['company']->id], $item['company_ids']);

        // learnings §11 — nome pareado com resultado individual de
        // bonificação não pode ser versionado. A ausência é asseverada por
        // padrão, não por lista fechada de chaves.
        foreach (array_keys($item) as $chave) {
            $this->assertDoesNotMatchRegularExpression(
                '/nota|faixa|bonific/i',
                $chave,
                "O relatório não pode expor '{$chave}' pareado com o nome do profissional (learnings §11)."
            );
        }
    }

    // ─── Contagem de células manuais ─────────────────────────────────────

    #[Test]
    public function test_celulas_manuais_agrupa_por_metrica_e_ignora_linha_inativa(): void
    {
        $cenario = $this->criarEmpresaMista('CUST-136-06-ADMAN');
        $outra   = $this->criarEmpresaMista('CUST-136-06-ADMAN-2');

        DesempenhoMetricaManual::create([
            'company_id'     => $cenario['company']->id,
            'mes_referencia' => self::MES_REFERENCIA,
            'metrica'        => DesempenhoMetricaManual::METRICA_FATURAMENTO,
            'valor'          => 1000.00,
            'ativo'          => true,
            'lancado_por'    => $cenario['user']->id,
            'lancado_em'     => now(),
        ]);

        DesempenhoMetricaManual::create([
            'company_id'     => $cenario['company']->id,
            'mes_referencia' => self::MES_REFERENCIA,
            'metrica'        => DesempenhoMetricaManual::METRICA_MARGEM_CMV,
            'valor'          => 600.00,
            'ativo'          => true,
            'lancado_por'    => $cenario['user']->id,
            'lancado_em'     => now(),
        ]);

        // Revertida para auto (D-02: a linha nunca é deletada) — não conta.
        DesempenhoMetricaManual::create([
            'company_id'     => $outra['company']->id,
            'mes_referencia' => self::MES_REFERENCIA,
            'metrica'        => DesempenhoMetricaManual::METRICA_FATURAMENTO,
            'valor'          => 500.00,
            'ativo'          => false,
            'lancado_por'    => $outra['user']->id,
            'lancado_em'     => now(),
        ]);

        $relatorio = $this->relatorioJson();
        $celulas   = $relatorio['celulas_manuais'];

        $this->assertSame(2, $celulas['total']);
        $this->assertSame(1, $celulas['por_metrica'][DesempenhoMetricaManual::METRICA_FATURAMENTO]);
        $this->assertSame(1, $celulas['por_metrica'][DesempenhoMetricaManual::METRICA_MARGEM_CMV]);
        $this->assertSame(1, $celulas['empresas_distintas']);
        $this->assertFalse($celulas['tabela_ausente']);
        $this->assertSame(2, $relatorio['resumo']['total_celulas_manuais']);
    }

    #[Test]
    public function test_celula_manual_de_outra_competencia_nao_conta(): void
    {
        $cenario = $this->criarEmpresaMista('CUST-136-06-ADMAN');

        DesempenhoMetricaManual::create([
            'company_id'     => $cenario['company']->id,
            'mes_referencia' => '2026-06-01',
            'metrica'        => DesempenhoMetricaManual::METRICA_FATURAMENTO,
            'valor'          => 1000.00,
            'ativo'          => true,
            'lancado_por'    => $cenario['user']->id,
            'lancado_em'     => now(),
        ]);

        $relatorio = $this->relatorioJson();

        $this->assertSame(0, $relatorio['celulas_manuais']['total']);
        $this->assertSame([], $relatorio['celulas_manuais']['por_metrica']);
    }

    // ─── Read-only e contrato de saída ───────────────────────────────────

    #[Test]
    public function test_comando_nao_escreve_em_nenhuma_das_cinco_tabelas_vigiadas(): void
    {
        $cenario = $this->criarEmpresaMista(null);
        $this->criarEmpresaFonteUnica();

        DesempenhoMetricaManual::create([
            'company_id'     => $cenario['company']->id,
            'mes_referencia' => self::MES_REFERENCIA,
            'metrica'        => DesempenhoMetricaManual::METRICA_MARGEM_CMV,
            'valor'          => 400.00,
            'ativo'          => true,
            'lancado_por'    => $cenario['user']->id,
            'lancado_em'     => now(),
        ]);

        $antes = $this->contagens();

        // Roda nas duas saídas — humana e JSON — para cobrir os dois caminhos.
        Artisan::call('desempenho:relatorio-impacto-fonte', ['--mes' => self::MES]);
        Artisan::call('desempenho:relatorio-impacto-fonte', ['--mes' => self::MES, '--json' => true]);

        $depois = $this->contagens();

        $this->assertSame($antes, $depois,
            'O comando é READ-ONLY: nenhuma das tabelas vigiadas pode mudar de contagem.');
    }

    #[Test]
    public function test_saida_json_e_inteiramente_parseavel_e_traz_as_quatro_secoes(): void
    {
        $this->criarEmpresaMista(null);

        $relatorio = $this->relatorioJson();

        foreach (['fonte_divergente', 'profissionais_afetados', 'celulas_manuais', 'resumo'] as $secao) {
            $this->assertArrayHasKey($secao, $relatorio);
        }

        $this->assertSame(self::MES, $relatorio['resumo']['mes']);
        $this->assertSame(self::MES_REFERENCIA, $relatorio['mes_referencia']);
    }

    #[Test]
    public function test_mes_invalido_falha_sem_montar_relatorio(): void
    {
        $this->artisan('desempenho:relatorio-impacto-fonte', ['--mes' => 'julho'])
            ->assertExitCode(1);
    }
}
