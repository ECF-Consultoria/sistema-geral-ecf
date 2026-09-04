<?php

namespace Tests\Feature\Phase139;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoServico;
use App\Models\FechamentoGrupoSnapshot;
use App\Models\FechamentoSnapshot;
use App\Models\Servico;
use App\Models\User;
use App\Services\Fechamento\FechamentoComparativoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 139 Plano 01 (D-04) — o risco número 1 da fase: `faixa_ordem_anterior`,
 * `valor_faixa_anterior`, `subiu_de_faixa` e `ganho_faixa` têm que chegar nos
 * QUATRO caminhos que montam linha em `AdminController::fechamento()` (empresa
 * ao vivo, empresa congelada — dois literais — grupo ao vivo, grupo congelado).
 * Nesta linha de trabalho já aconteceu três vezes de um dado atravessar quase
 * todo o caminho e morrer no último trecho — este arquivo é a trava.
 *
 * Duas frentes:
 *  (a) Tarefa 1 — `FechamentoComparativoService` isolado, via
 *      `app(FechamentoComparativoService::class)`, com linhas de snapshot
 *      criadas na mão.
 *  (b) Tarefa 3 — os quatro caminhos via HTTP em `/administrativo/financeiro`,
 *      comparando ao vivo x congelado no MESMO cenário.
 */
class Phase139ComparativoFaixaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * A migration `2026_09_02_100003_seed_faixas_faturamento_iniciais` já
     * semeia as 7 faixas de "Gestão" — mesmo padrão dos demais testes da fase.
     */
    private function criarServicoGestao(): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update(['plataforma' => 'Mercado Livre', 'setor' => Servico::SETOR_PERFORMANCE]);

        return $servico->refresh();
    }

    private function criarEmpresaComContrato(Servico $servico, array $overrides = []): Company
    {
        $company = Company::factory()->create(array_merge([
            'adman_account_id' => 'cust-'.uniqid(),
        ], $overrides));

        ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 0,
            'data_contratacao' => Carbon::now()->toDateString(),
            'ativo'            => true,
        ]);

        return $company;
    }

    private function criarEmpresaSnapshot(Carbon $mes, array $overrides = []): FechamentoSnapshot
    {
        $company = Company::factory()->create();

        return FechamentoSnapshot::create(array_merge([
            'company_id'     => $company->id,
            'company_name'   => $company->name,
            'mes_referencia' => $mes->copy()->startOfMonth()->toDateString(),
            'faixa_ordem'    => 2,
            'faixa_aplicada' => 'faixa_2',
            'valor_faixa'    => 4_500.00,
            'evolucao'       => 'subiu',
            'estado'         => FechamentoSnapshot::ESTADO_OK,
            'origem'         => FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES,
            'gerado_em'      => now(),
        ], $overrides));
    }

    private function criarGrupoSnapshot(Carbon $mes, array $overrides = []): FechamentoGrupoSnapshot
    {
        $grupo = CompanyGroup::create(['name' => 'Grupo Teste '.uniqid()]);

        return FechamentoGrupoSnapshot::create(array_merge([
            'company_group_id' => $grupo->id,
            'grupo_name'       => $grupo->name,
            'mes_referencia'   => $mes->copy()->startOfMonth()->toDateString(),
            'faixa_ordem'      => 3,
            'faixa_aplicada'   => 'faixa_3',
            'valor_faixa'      => 6_000.00,
            'evolucao'         => 'desceu',
            'estado'           => FechamentoSnapshot::ESTADO_OK,
            'origem'           => FechamentoGrupoSnapshot::ORIGEM_CONSOLIDAR_MES,
            'empresas_count'   => 2,
            'gerado_em'        => now(),
        ], $overrides));
    }

    // ─── (a) Tarefa 1 — FechamentoComparativoService isolado ─────────────

    #[Test]
    public function anteriores_por_empresa_le_o_snapshot_do_mes_anterior_com_faixa_e_valor(): void
    {
        $snap = $this->criarEmpresaSnapshot(Carbon::create(2026, 8, 1), ['faixa_ordem' => 1, 'valor_faixa' => 3_000.00]);

        $resultado = app(FechamentoComparativoService::class)->anterioresPorEmpresa('2026-09-01');

        $this->assertArrayHasKey($snap->company_id, $resultado, 'A empresa com snapshot fechado em agosto precisa aparecer na leitura do mês anterior a setembro.');
        $this->assertSame(1, $resultado[$snap->company_id]['faixa_ordem']);
        $this->assertEqualsWithDelta(3_000.00, $resultado[$snap->company_id]['valor_faixa'], 0.01);
    }

    #[Test]
    public function anteriores_por_grupo_le_fechamento_grupo_snapshots_do_mes_anterior(): void
    {
        $snap = $this->criarGrupoSnapshot(Carbon::create(2026, 8, 1), ['faixa_ordem' => 2, 'valor_faixa' => 4_500.00]);

        $resultado = app(FechamentoComparativoService::class)->anterioresPorGrupo('2026-09-01');

        $this->assertArrayHasKey($snap->company_group_id, $resultado);
        $this->assertSame(2, $resultado[$snap->company_group_id]['faixa_ordem']);
        $this->assertEqualsWithDelta(4_500.00, $resultado[$snap->company_group_id]['valor_faixa'], 0.01);
    }

    #[Test]
    public function mes_anterior_sem_nenhum_fechamento_devolve_array_vazio_nunca_null(): void
    {
        $resultadoEmpresa = app(FechamentoComparativoService::class)->anterioresPorEmpresa('2026-09-01');
        $resultadoGrupo    = app(FechamentoComparativoService::class)->anterioresPorGrupo('2026-09-01');

        $this->assertIsArray($resultadoEmpresa);
        $this->assertSame([], $resultadoEmpresa, 'Sem fechamento nenhum em agosto, o array precisa ser vazio — nunca null.');
        $this->assertIsArray($resultadoGrupo);
        $this->assertSame([], $resultadoGrupo);
    }

    #[Test]
    public function empresa_com_faixa_ordem_null_no_mes_anterior_entra_no_array_com_as_duas_chaves_em_null(): void
    {
        $snap = $this->criarEmpresaSnapshot(Carbon::create(2026, 8, 1), [
            'faixa_ordem'    => null,
            'faixa_aplicada' => null,
            'valor_faixa'    => null,
            'evolucao'       => null,
            'estado'         => FechamentoSnapshot::ESTADO_SEM_TABELA,
        ]);

        $resultado = app(FechamentoComparativoService::class)->anterioresPorEmpresa('2026-09-01');

        $this->assertArrayHasKey($snap->company_id, $resultado, 'Estado sem_tabela ainda gera linha no snapshot — precisa aparecer no array, só com os valores em null.');
        $this->assertNull($resultado[$snap->company_id]['faixa_ordem']);
        $this->assertNull($resultado[$snap->company_id]['valor_faixa']);
    }

    #[Test]
    public function vira_de_ano_janeiro_le_dezembro_do_ano_anterior(): void
    {
        $snap = $this->criarEmpresaSnapshot(Carbon::create(2025, 12, 1), ['faixa_ordem' => 4, 'valor_faixa' => 7_500.00]);

        $resultado = app(FechamentoComparativoService::class)->anterioresPorEmpresa('2026-01-01');

        $this->assertArrayHasKey($snap->company_id, $resultado, 'Janeiro/2026 precisa ler dezembro/2025 (subMonthNoOverflow), sem estourar de ano.');
        $this->assertSame(4, $resultado[$snap->company_id]['faixa_ordem']);
        $this->assertEqualsWithDelta(7_500.00, $resultado[$snap->company_id]['valor_faixa'], 0.01);
    }

    #[Test]
    public function ignora_snapshot_de_origem_diferente_de_consolidar_mes(): void
    {
        $snap = $this->criarEmpresaSnapshot(Carbon::create(2026, 8, 1), ['origem' => 'preview']);

        $resultado = app(FechamentoComparativoService::class)->anterioresPorEmpresa('2026-09-01');

        $this->assertArrayNotHasKey($snap->company_id, $resultado, 'Só origem consolidar_mes conta como fechamento real do mês anterior — mesma trava de competencia_fechada.');
    }

    #[Test]
    public function cada_metodo_faz_exatamente_uma_consulta_independente_da_quantidade_de_linhas(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->criarEmpresaSnapshot(Carbon::create(2026, 8, 1));
        }
        for ($i = 0; $i < 5; $i++) {
            $this->criarGrupoSnapshot(Carbon::create(2026, 8, 1));
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        app(FechamentoComparativoService::class)->anterioresPorEmpresa('2026-09-01');
        $logEmpresa = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();

        app(FechamentoComparativoService::class)->anterioresPorGrupo('2026-09-01');
        $logGrupo = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertCount(1, $logEmpresa, 'anterioresPorEmpresa() precisa fazer exatamente 1 consulta, nunca uma por empresa (o problema que este serviço substitui).');
        $this->assertCount(1, $logGrupo, 'anterioresPorGrupo() precisa fazer exatamente 1 consulta, nunca uma por grupo.');
    }

    // ─── (b) Tarefa 3 — os quatro caminhos via HTTP ───────────────────────

    #[Test]
    public function toda_linha_de_companies_traz_as_quatro_chaves_do_comparativo_de_faixa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $c1     = $this->criarEmpresaComContrato($gestao);
        $c2     = $this->criarEmpresaComContrato($gestao);

        AdmanMetric::create(['company_id' => $c1->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);
        // $c2 fica sem nenhum faturamento no mês — precisa ter as chaves do
        // mesmo jeito, com valores null.

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $companies = $response->viewData('page')['props']['companies'];
        $this->assertNotEmpty($companies, 'Precisa ter pelo menos uma linha pra esta asserção fazer sentido.');

        foreach ($companies as $linha) {
            foreach (['faixa_ordem_anterior', 'valor_faixa_anterior', 'subiu_de_faixa', 'ganho_faixa'] as $chave) {
                $this->assertArrayHasKey($chave, $linha, "Sem a chave '{$chave}' em toda linha, a tela não sabe se mostra ou esconde o widget de upgrade pra essa empresa ('{$linha['name']}').");
            }
        }
    }

    #[Test]
    public function empresa_que_subiu_de_faixa_mostra_os_mesmos_valores_ao_vivo_e_congelado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        // Agosto: R$300k → faixa 1 (até R$499.999,99), mensalidade R$3.000.
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        // Setembro: R$600k → faixa 2 (até R$999.999,99), mensalidade R$4.500.
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 600_000.00]);

        // ─── AO VIVO (setembro ainda aberto) ───
        $respAoVivo   = $this->actingAs($admin)->get('/administrativo/financeiro');
        $respAoVivo->assertOk();
        $linhaAoVivo = collect($respAoVivo->viewData('page')['props']['companies'])->firstWhere('id', $company->id);

        $this->assertNotNull($linhaAoVivo);
        $this->assertSame(1, $linhaAoVivo['faixa_ordem_anterior'], 'Agosto fechou na faixa 1 (R$300k) — sem isso o widget não sabe "Faixa 1 → 2".');
        $this->assertEqualsWithDelta(3_000.00, (float) $linhaAoVivo['valor_faixa_anterior'], 0.01);
        $this->assertTrue($linhaAoVivo['subiu_de_faixa']);
        $this->assertEqualsWithDelta(1_500.00, (float) $linhaAoVivo['ganho_faixa'], 0.01, 'Faixa 2 (R$4.500) − faixa 1 (R$3.000) = R$1.500 de ganho.');

        // ─── CONGELADO (fecha setembro e reconsulta) ───
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-09'])->assertExitCode(0);

        $respCongelado = $this->actingAs($admin)->get('/administrativo/financeiro');
        $respCongelado->assertOk();
        $linhaCongelada = collect($respCongelado->viewData('page')['props']['companies'])->firstWhere('id', $company->id);

        $this->assertNotNull($linhaCongelada);
        $this->assertSame($linhaAoVivo['faixa_ordem_anterior'], $linhaCongelada['faixa_ordem_anterior'], 'sem faixa_ordem_anterior no ramo congelado o widget de upgrades some ao fechar o mês.');
        $this->assertEqualsWithDelta((float) $linhaAoVivo['valor_faixa_anterior'], (float) $linhaCongelada['valor_faixa_anterior'], 0.01);
        $this->assertSame($linhaAoVivo['subiu_de_faixa'], $linhaCongelada['subiu_de_faixa']);
        $this->assertEqualsWithDelta((float) $linhaAoVivo['ganho_faixa'], (float) $linhaCongelada['ganho_faixa'], 0.01, 'O congelado reconstrói pelo mês anterior — não pode zerar o ganho que o ao vivo já mostrava.');
    }

    #[Test]
    public function empresa_que_desceu_de_faixa_nao_conta_como_ganho(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        // Agosto: R$600k → faixa 2. Setembro: R$300k → faixa 1 (desceu).
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 600_000.00]);
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 300_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();
        $linha = collect($response->viewData('page')['props']['companies'])->firstWhere('id', $company->id);

        $this->assertNotNull($linha);
        $this->assertSame(2, $linha['faixa_ordem_anterior']);
        $this->assertFalse($linha['subiu_de_faixa'], 'Desceu de faixa 2 pra 1 — não pode contar como upgrade.');
        $this->assertNull($linha['ganho_faixa'], 'Queda não pode virar ganho na tela — nunca inferir de evolução, e nunca 0.');
    }

    #[Test]
    public function empresa_sem_fechamento_no_mes_anterior_tem_valores_null_e_ganho_nunca_zero(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        // Sem nenhum AdmanMetric em agosto e sem fechamento algum — empresa
        // pode ter entrado na carteira agora.
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();
        $linha = collect($response->viewData('page')['props']['companies'])->firstWhere('id', $company->id);

        $this->assertNotNull($linha);
        $this->assertNull($linha['faixa_ordem_anterior']);
        $this->assertNull($linha['valor_faixa_anterior']);
        $this->assertFalse($linha['subiu_de_faixa']);
        $this->assertNull($linha['ganho_faixa'], 'Sem fechamento anterior é "não sabemos", nunca 0 — zero significa "subiu e não mudou de preço".');
    }

    #[Test]
    public function linha_de_grupo_ao_vivo_e_congelada_trazem_os_mesmos_valores_do_comparativo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $grupo   = CompanyGroup::create(['name' => 'Grupo Upgrade '.uniqid(), 'color' => '#000']);
        $membroA = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $membroB = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);

        // Agosto: soma R$300k (200k + 100k) → faixa 1, mensalidade R$3.000.
        AdmanMetric::create(['company_id' => $membroA->id, 'reference_date' => '2026-08-05', 'revenue' => 200_000.00]);
        AdmanMetric::create(['company_id' => $membroB->id, 'reference_date' => '2026-08-05', 'revenue' => 100_000.00]);
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        // Setembro: soma R$700k (400k + 300k) → faixa 2, mensalidade R$4.500.
        AdmanMetric::create(['company_id' => $membroA->id, 'reference_date' => '2026-09-05', 'revenue' => 400_000.00]);
        AdmanMetric::create(['company_id' => $membroB->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);

        // ─── AO VIVO ───
        $respAoVivo = $this->actingAs($admin)->get('/administrativo/financeiro');
        $respAoVivo->assertOk();
        $linhaAoVivo = collect($respAoVivo->viewData('page')['props']['companies'])->firstWhere('tipo', 'grupo');

        $this->assertNotNull($linhaAoVivo, 'Grupo ao vivo precisa aparecer nas linhas de companies.');
        foreach (['faixa_ordem_anterior', 'valor_faixa_anterior', 'subiu_de_faixa', 'ganho_faixa'] as $chave) {
            $this->assertArrayHasKey($chave, $linhaAoVivo, "Linha de grupo AO VIVO sem a chave '{$chave}'.");
        }
        $this->assertSame(1, $linhaAoVivo['faixa_ordem_anterior']);
        $this->assertTrue($linhaAoVivo['subiu_de_faixa']);
        $this->assertEqualsWithDelta(1_500.00, (float) $linhaAoVivo['ganho_faixa'], 0.01);

        // ─── CONGELADO ───
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-09'])->assertExitCode(0);

        $respCongelado = $this->actingAs($admin)->get('/administrativo/financeiro');
        $respCongelado->assertOk();
        $linhaCongelada = collect($respCongelado->viewData('page')['props']['companies'])->firstWhere('tipo', 'grupo');

        $this->assertNotNull($linhaCongelada, 'Grupo congelado precisa aparecer nas linhas de companies.');
        foreach (['faixa_ordem_anterior', 'valor_faixa_anterior', 'subiu_de_faixa', 'ganho_faixa'] as $chave) {
            $this->assertArrayHasKey($chave, $linhaCongelada, "Linha de grupo CONGELADA sem a chave '{$chave}' — o dado morreu no último trecho.");
        }
        $this->assertSame($linhaAoVivo['faixa_ordem_anterior'], $linhaCongelada['faixa_ordem_anterior']);
        $this->assertEqualsWithDelta((float) $linhaAoVivo['valor_faixa_anterior'], (float) $linhaCongelada['valor_faixa_anterior'], 0.01);
        $this->assertSame($linhaAoVivo['subiu_de_faixa'], $linhaCongelada['subiu_de_faixa']);
        $this->assertEqualsWithDelta((float) $linhaAoVivo['ganho_faixa'], (float) $linhaCongelada['ganho_faixa'], 0.01);
    }
}
