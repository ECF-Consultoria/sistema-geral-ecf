<?php

namespace Tests\Feature\Phase141;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Configuracao;
use App\Models\ContratoServico;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\FechamentoSnapshot;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Services\Fechamento\FechamentoRegraTabela;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 141 Plano 04 — Tarefa 2: a consolidação passa a cobrar pela regra
 * nova (`fechamento:consolidar-mes` atrás da flag `fechamento_tabela_por_empresa_ativa`).
 *
 * Toda asserção de resultado é por RECONSULTA ao banco — nunca por
 * `expectsOutput` (disciplina de `.planning/learnings/desempenho-bonificacao.md` §4).
 */
class Phase141ConsolidarRegraNovaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function ligarFlag(): void
    {
        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');
    }

    /** Serviço "Gestão" (ML) já semeado com 7 faixas pela migration da Fase 137. */
    private function criarServicoGestao(bool $usaTabelaProgressiva = true): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update([
            'plataforma'             => 'Mercado Livre',
            'setor'                  => Servico::SETOR_PERFORMANCE,
            'usa_tabela_progressiva' => $usaTabelaProgressiva,
        ]);

        return $servico->refresh();
    }

    /** Serviço "Gestão de ADS Shopee" — sem faixa própria (usa tabela de empresa/grupo neste plano). */
    private function criarServicoShopee(bool $usaTabelaProgressiva = true): Servico
    {
        return Servico::create([
            'nome'                    => 'Gestão de ADS Shopee '.uniqid(),
            'valor_padrao'            => 0,
            'tipo_cobranca'           => Servico::TIPO_MENSAL,
            'ativo'                   => true,
            'setor'                   => Servico::SETOR_SHOPEE,
            'plataforma'              => 'Shopee',
            'usa_tabela_progressiva'  => $usaTabelaProgressiva,
        ]);
    }

    /** Serviço sem tabela progressiva (o caso de Mentoria, D-02/D-03). */
    private function criarServicoMentoria(): Servico
    {
        return Servico::create([
            'nome'                    => 'Mentoria '.uniqid(),
            'valor_padrao'            => 0,
            'tipo_cobranca'           => Servico::TIPO_MENSAL,
            'ativo'                   => true,
            'setor'                   => Servico::SETOR_PERFORMANCE,
            'usa_tabela_progressiva'  => false,
        ]);
    }

    /** As 7 faixas reais de Gestão (mesma tabela usada na tabela própria da BARAOSHOP). */
    private function faixasGestaoReais(): array
    {
        return [
            [1, 499_999.99, 3_000.00, false],
            [2, 999_999.99, 4_500.00, false],
            [3, 1_999_999.99, 6_000.00, false],
            [4, 2_999_999.99, 7_500.00, false],
            [5, 3_999_999.99, 9_000.00, false],
            [6, 4_999_999.99, 10_500.00, false],
            [7, null, 12_000.00, true],
        ];
    }

    private function criarTabelaPropriaComFaixasReais(Company $company): void
    {
        foreach ($this->faixasGestaoReais() as [$ordem, $limiteSuperior, $valor, $valorEPiso]) {
            EmpresaFaixaFaturamento::create([
                'company_id'      => $company->id,
                'ordem'           => $ordem,
                'limite_superior' => $limiteSuperior,
                'valor'           => $valor,
                'valor_e_piso'    => $valorEPiso,
                'origem'          => EmpresaFaixaFaturamento::ORIGEM_MANUAL,
            ]);
        }
    }

    // ─── Cenário BARAOSHOP — ANTES × DEPOIS na mesma classe (D-03) ──────
    //
    // ⚠️ ANTES e DEPOIS são dois testes SEPARADOS (não dois `artisan()` na
    // mesma execução): o console Kernel de teste memoiza a instância do
    // comando entre chamadas de `$this->artisan()` dentro do MESMO método —
    // como `FechamentoRegraTabela::ativa()` é memoizado por instância
    // (`esquecer()` só existe para quem segura a MESMA instância, e o
    // comando resolvido pelo container não é o mesmo objeto que
    // `app(FechamentoRegraTabela::class)` devolveria depois), ligar a flag
    // no meio do mesmo teste e rodar `artisan()` de novo leria a flag
    // ANTIGA (memoizada na primeira chamada) — falso positivo de teste, não
    // comportamento real (produção roda cada `artisan` como processo novo).
    // Os dois valores (ANTES × DEPOIS) continuam afirmados na MESMA CLASSE.

    private function montarFixtureBaraoshop(): array
    {
        $gestao = $this->criarServicoGestao();
        $shopee = $this->criarServicoShopee();

        $company = Company::factory()->create(['adman_account_id' => 'cust-baraoshop']);

        // Gestão: contrato mensal SEM valor extra — a mensalidade histórica
        // da BARAOSHOP vinha inteira da faixa (D-03 do CONTEXT).
        ContratoServico::factory()->paraServico($gestao)->create([
            'company_id'       => $company->id,
            'ativo'            => true,
            'valor_contratado' => 0,
        ]);

        // Shopee: contrato mensal de R$ 2.500 — é ele que a regra ANTIGA
        // soma por cima da faixa (o bug que abriu a Fase 141).
        ContratoServico::factory()->paraServico($shopee)->create([
            'company_id'       => $company->id,
            'ativo'            => true,
            'valor_contratado' => 2_500.00,
        ]);

        $this->criarTabelaPropriaComFaixasReais($company);

        // Faturamento real medido: R$ 488.262,90, dividido entre as duas
        // plataformas — soma bate com o caso real relatado (CONTEXT D-03).
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 350_262.90]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 138_000.00]);

        $somaEsperada = 350_262.90 + 138_000.00;
        $this->assertEqualsWithDelta(488_262.90, $somaEsperada, 0.01, 'A soma das duas plataformas precisa bater com o caso real da BARAOSHOP.');

        return [$company, $somaEsperada];
    }

    #[Test]
    public function cenario_baraoshop_antes_cobra_a_faixa_mais_o_contrato_de_shopee_por_cima(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        [$company, $somaEsperada] = $this->montarFixtureBaraoshop();

        // ANTES (flag desligada): faixa (R$ 3.000, faixa 1) + R$ 2.500 do
        // contrato de Shopee = R$ 5.500,00 — o bug que abriu a Fase 141.
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $gravadoAntes = DB::table('fechamento_snapshots')->where('company_id', $company->id)->first();
        $this->assertNotNull($gravadoAntes);
        $this->assertEqualsWithDelta($somaEsperada, (float) $gravadoAntes->faturamento_total, 0.01);
        $this->assertSame(1, (int) $gravadoAntes->faixa_ordem);
        $this->assertEqualsWithDelta(3_000.00, (float) $gravadoAntes->valor_faixa, 0.01);
        $this->assertEqualsWithDelta(5_500.00, (float) $gravadoAntes->cobranca_mensal, 0.01, 'ANTES (bug D-03): faixa + contrato de Shopee somado por cima.');
    }

    #[Test]
    public function cenario_baraoshop_depois_cobra_so_o_valor_da_faixa_da_soma(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        $this->ligarFlag();

        [$company, $somaEsperada] = $this->montarFixtureBaraoshop();

        // DEPOIS (flag ligada): só o valor da faixa da SOMA — R$ 3.000,00.
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $gravadoDepois = DB::table('fechamento_snapshots')->where('company_id', $company->id)->first();
        $this->assertNotNull($gravadoDepois);
        $this->assertEqualsWithDelta($somaEsperada, (float) $gravadoDepois->faturamento_total, 0.01, 'DEPOIS: faturamento continua sendo a soma das duas plataformas contratadas.');
        $this->assertSame(1, (int) $gravadoDepois->faixa_ordem);
        $this->assertEqualsWithDelta(3_000.00, (float) $gravadoDepois->valor_faixa, 0.01);
        $this->assertEqualsWithDelta(3_000.00, (float) $gravadoDepois->cobranca_mensal, 0.01, 'DEPOIS (D-03): a mensalidade é o valor da faixa, e só isso — nunca mais faixa + contrato de Shopee.');
    }

    // ─── Faixa classificada sobre a SOMA das plataformas ────────────────

    #[Test]
    public function flag_ligada_faixa_e_classificada_sobre_a_soma_e_nao_sobre_ml_isolado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        $this->ligarFlag();

        $gestao = $this->criarServicoGestao();
        $shopee = $this->criarServicoShopee();

        $company = Company::factory()->create(['adman_account_id' => 'cust-soma-faixa']);

        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $company->id, 'ativo' => true]);
        ContratoServico::factory()->paraServico($shopee)->create(['company_id' => $company->id, 'ativo' => true]);

        $this->criarTabelaPropriaComFaixasReais($company);

        // ML sozinho (R$ 300.000) cairia na faixa 1 (até R$ 499.999,99).
        // Shopee (R$ 300.000) some por cima — soma R$ 600.000, cai na
        // faixa 2 (até R$ 999.999,99). O teste afirma a faixa da SOMA.
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $gravado = DB::table('fechamento_snapshots')->where('company_id', $company->id)->first();
        $this->assertNotNull($gravado);
        $this->assertEqualsWithDelta(600_000.00, (float) $gravado->faturamento_total, 0.01);
        $this->assertSame(2, (int) $gravado->faixa_ordem, 'ML sozinho (300k) cairia na faixa 1 — a soma com Shopee (600k) precisa cair na faixa 2.');
        $this->assertEqualsWithDelta(4_500.00, (float) $gravado->valor_faixa, 0.01);
    }

    // ─── Empresa sem tabela — valor fixo ou sem_tabela ───────────────────

    #[Test]
    public function flag_ligada_empresa_sem_tabela_e_com_contrato_mensal_grava_valor_fixo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        $this->ligarFlag();

        $mentoria = $this->criarServicoMentoria();
        $company  = Company::factory()->create(['adman_account_id' => 'cust-mentoria']);

        ContratoServico::factory()->paraServico($mentoria)->create([
            'company_id'       => $company->id,
            'ativo'            => true,
            'valor_contratado' => 1_800.00,
        ]);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $gravado = DB::table('fechamento_snapshots')->where('company_id', $company->id)->first();
        $this->assertNotNull($gravado);
        $this->assertSame(FechamentoSnapshot::ESTADO_VALOR_FIXO, $gravado->estado);
        $this->assertNull($gravado->faixa_ordem);
        $this->assertEqualsWithDelta(1_800.00, (float) $gravado->cobranca_mensal, 0.01);
    }

    #[Test]
    public function flag_ligada_empresa_sem_tabela_e_sem_contrato_mensal_continua_sem_tabela(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        $this->ligarFlag();

        // Serviço COM plataforma elegível (usa_tabela_progressiva=true, ML)
        // mas cobrança ÚNICA (não mensal) — a empresa fatura de verdade
        // (entra na soma), mas não tem tabela nenhuma resolvida (sem grupo,
        // sem própria) NEM contrato mensal que justifique ESTADO_VALOR_FIXO.
        // É o contraste com o teste anterior: aqui SEM_TABELA precisa
        // vencer, não SEM_FATURAMENTO nem VALOR_FIXO.
        $servicoUnico = Servico::create([
            'nome'                    => 'Serviço Único Sem Régua '.uniqid(),
            'valor_padrao'            => 0,
            'tipo_cobranca'           => Servico::TIPO_UNICA,
            'ativo'                   => true,
            'setor'                   => Servico::SETOR_PERFORMANCE,
            'plataforma'              => 'Mercado Livre',
            'usa_tabela_progressiva'  => true,
        ]);

        $company = Company::factory()->create(['adman_account_id' => 'cust-sem-nada']);

        ContratoServico::factory()->paraServico($servicoUnico)->create([
            'company_id'       => $company->id,
            'ativo'            => true,
            'valor_contratado' => 1_800.00,
        ]);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);

        // ESTADO_SEM_TABELA continua entrando no denominador do gate de
        // cobertura (só ESTADO_VALOR_FIXO sai) — companheiras de fatura com
        // faturamento real garantem cobertura acima do mínimo (0,7) para o
        // teste focar no estado desta empresa, não no gate.
        $gestao = $this->criarServicoGestao();
        foreach (['cust-cobertura-1', 'cust-cobertura-2', 'cust-cobertura-3'] as $custId) {
            $c = Company::factory()->create(['adman_account_id' => $custId]);
            ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $c->id, 'ativo' => true]);
            $this->criarTabelaPropriaComFaixasReais($c);
            AdmanMetric::create(['company_id' => $c->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);
        }

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $gravado = DB::table('fechamento_snapshots')->where('company_id', $company->id)->first();
        $this->assertNotNull($gravado);
        $this->assertNotNull($gravado->faturamento_total, 'A empresa fatura de verdade — o teste precisa isolar SEM_TABELA de SEM_FATURAMENTO.');
        $this->assertSame(FechamentoSnapshot::ESTADO_SEM_TABELA, $gravado->estado);
        $this->assertNull($gravado->faixa_ordem);
        $this->assertNull($gravado->cobranca_mensal);
    }

    // ─── Grupo com tabela própria — só a faixa da soma, nunca contrato ──

    #[Test]
    public function flag_ligada_grupo_com_tabela_propria_cobra_so_a_faixa_da_soma(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        $this->ligarFlag();

        $gestao = $this->criarServicoGestao();
        $grupo  = CompanyGroup::create(['name' => 'Grupo Fase 141', 'color' => '#000']);

        foreach ($this->faixasGestaoReais() as [$ordem, $limiteSuperior, $valor, $valorEPiso]) {
            GrupoFaixaFaturamento::create([
                'company_group_id' => $grupo->id,
                'ordem'             => $ordem,
                'limite_superior'   => $limiteSuperior,
                'valor'             => $valor,
                'valor_e_piso'      => $valorEPiso,
            ]);
        }

        $membroA = Company::factory()->create(['adman_account_id' => 'cust-grupo-a', 'company_group_id' => $grupo->id]);
        $membroB = Company::factory()->create(['adman_account_id' => 'cust-grupo-b', 'company_group_id' => $grupo->id]);

        // Contratos mensais das empresas-membro NÃO podem somar por cima.
        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $membroA->id, 'ativo' => true, 'valor_contratado' => 900.00]);
        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $membroB->id, 'ativo' => true, 'valor_contratado' => 700.00]);

        AdmanMetric::create(['company_id' => $membroA->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $membroB->id, 'reference_date' => '2026-08-10', 'revenue' => 250_000.00]);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $grupoGravado = DB::table('fechamento_grupo_snapshots')->where('company_group_id', $grupo->id)->first();
        $this->assertNotNull($grupoGravado);
        $this->assertEqualsWithDelta(550_000.00, (float) $grupoGravado->faturamento_total, 0.01);
        $this->assertSame(2, (int) $grupoGravado->faixa_ordem);
        $this->assertEqualsWithDelta(4_500.00, (float) $grupoGravado->valor_faixa, 0.01);
        $this->assertEqualsWithDelta(4_500.00, (float) $grupoGravado->cobranca_mensal, 0.01, 'A cobrança do grupo é só o valor da faixa da soma — os R$ 900 + R$ 700 dos contratos-membro não podem entrar.');
    }

    // ─── Gate de cobertura não recusa mais por empresa sem régua ────────

    #[Test]
    public function flag_ligada_empresas_sem_regua_saem_do_denominador_do_gate_de_cobertura(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        $this->ligarFlag();

        $gestao   = $this->criarServicoGestao();
        $mentoria = $this->criarServicoMentoria();

        // 5 empresas de Mentoria — SEM tabela, SEM plataforma elegível
        // (faturamento_total fica null), mas COM contrato mensal, então
        // gravam ESTADO_VALOR_FIXO. Sem a exclusão do plano 04, essas 5
        // entrariam no denominador e derrubariam a cobertura para bem
        // abaixo do mínimo de 0,7.
        for ($i = 0; $i < 5; $i++) {
            $c = Company::factory()->create(['adman_account_id' => 'cust-mentoria-'.$i]);
            ContratoServico::factory()->paraServico($mentoria)->create([
                'company_id'       => $c->id,
                'ativo'            => true,
                'valor_contratado' => 1_500.00,
            ]);
        }

        // 2 empresas de Gestão, com tabela própria e faturamento — cobertura
        // real 2/2 = 1.0 dentro do universo que DEVE contar.
        foreach (['cust-gestao-a', 'cust-gestao-b'] as $custId) {
            $c = Company::factory()->create(['adman_account_id' => $custId]);
            ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $c->id, 'ativo' => true]);
            $this->criarTabelaPropriaComFaixasReais($c);
            AdmanMetric::create(['company_id' => $c->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);
        }

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $this->assertSame(7, DB::table('fechamento_snapshots')->whereDate('mes_referencia', '2026-08-01')->count(), 'Todas as 7 linhas precisam ter sido gravadas — o gate não pode recusar o lote.');
        $this->assertSame(5, DB::table('fechamento_snapshots')->where('estado', FechamentoSnapshot::ESTADO_VALOR_FIXO)->count());
    }
}
