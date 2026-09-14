<?php

namespace Tests\Feature\Phase143;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Configuracao;
use App\Models\ContratoServico;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use App\Services\Fechamento\FechamentoRegraTabela;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 143 Plano 01 — Tarefa 3: `fechamento:consolidar-mes` agrega pela RAIZ
 * da árvore de grupos.
 *
 * Molde: `Tests\Feature\Phase138\Phase138ConsolidarGrupoTabelaTest`. Toda
 * asserção é por RECONSULTA às tabelas de snapshot — nunca pela saída de
 * texto do comando (.planning/learnings/desempenho-bonificacao.md §4).
 *
 * O caso que abriu a fase (143-CONTEXT, D-02), medido em produção em
 * 2026-09-14: MPozenato + DRossi + Gran Belo + Lyam são QUATRO grupos no
 * cadastro, 10 empresas, R$ 12.679.411,83 em ago/2026 — e saíam em quatro
 * linhas de cobrança somando R$ 33.500/mês. Como um cliente só, a tabela do
 * MPozenato (origem contrato) cobra R$ 21.000.
 */
class Phase143ConsolidarPelaRaizTest extends TestCase
{
    use RefreshDatabase;

    /** Faturamento por empresa, por grupo — soma exata dos números medidos em produção. */
    private const FATURAMENTO_MPOZENATO = [3_000_000.00, 812_487.89];   // 3.812.487,89
    private const FATURAMENTO_GRAN_BELO = [5_000_000.00, 977_697.79];   // 5.977.697,79
    private const FATURAMENTO_DROSSI    = [400_000.00, 400_000.00, 400_000.00, 38_304.17]; // 1.238.304,17
    private const FATURAMENTO_LYAM      = [1_000_000.00, 650_921.98];   // 1.650.921,98

    private const TOTAL_DO_CLIENTE = 12_679_411.83;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function criarServicoGestao(): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update(['plataforma' => 'Mercado Livre', 'setor' => Servico::SETOR_PERFORMANCE]);

        return $servico->refresh();
    }

    /** Empresa com integração Adman, contrato ativo, grupo e faturamento de ago/2026. */
    private function criarMembro(Servico $servico, CompanyGroup $grupo, float $faturamento): Company
    {
        $company = Company::factory()->create([
            'adman_account_id' => 'cust-'.uniqid(),
            'company_group_id' => $grupo->id,
        ]);

        ContratoServico::factory()->paraServico($servico)->create([
            'company_id' => $company->id,
            'ativo'      => true,
        ]);

        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-08-10',
            'revenue'        => $faturamento,
        ]);

        return $company;
    }

    /**
     * A tabela do MPozenato: até R$ 5 mi cobra R$ 12.000; acima disso,
     * R$ 21.000 (faixa aberta, piso). É a tabela de origem CONTRATO do
     * CONTEXT — a que governa o cliente inteiro quando ele vira um grupo só.
     */
    private function criarTabelaDaRaiz(CompanyGroup $raiz): void
    {
        GrupoFaixaFaturamento::create([
            'company_group_id' => $raiz->id, 'ordem' => 1,
            'limite_superior'  => 5_000_000.00, 'valor' => 12_000.00, 'valor_e_piso' => false,
        ]);
        GrupoFaixaFaturamento::create([
            'company_group_id' => $raiz->id, 'ordem' => 2,
            'limite_superior'  => null, 'valor' => 21_000.00, 'valor_e_piso' => true,
        ]);
    }

    /**
     * Monta os quatro grupos e as 10 empresas. `$comPai` decide se os três
     * subgrupos penduram no MPozenato (a correção) ou ficam soltos (o de
     * hoje) — o MESMO fixture nos dois lados, que é o que torna a
     * comparação honesta.
     *
     * @return array{0: CompanyGroup, 1: array<string, CompanyGroup>}
     */
    private function montarCasoMPozenato(bool $comPai): array
    {
        $gestao = $this->criarServicoGestao();

        $raiz = CompanyGroup::create(['name' => 'MPozenato', 'color' => '#000']);

        $subgrupos = [];
        foreach (['DRossi', 'Gran Belo', 'Lyam'] as $nome) {
            $subgrupos[$nome] = CompanyGroup::create(array_filter([
                'name'      => $nome,
                'color'     => '#000',
                'parent_id' => $comPai ? $raiz->id : null,
            ]));
        }

        foreach (self::FATURAMENTO_MPOZENATO as $valor) {
            $this->criarMembro($gestao, $raiz, $valor);
        }
        foreach (self::FATURAMENTO_DROSSI as $valor) {
            $this->criarMembro($gestao, $subgrupos['DRossi'], $valor);
        }
        foreach (self::FATURAMENTO_GRAN_BELO as $valor) {
            $this->criarMembro($gestao, $subgrupos['Gran Belo'], $valor);
        }
        foreach (self::FATURAMENTO_LYAM as $valor) {
            $this->criarMembro($gestao, $subgrupos['Lyam'], $valor);
        }

        $this->criarTabelaDaRaiz($raiz);

        return [$raiz, $subgrupos];
    }

    // ─── Regressão zero: sem pai, tudo como hoje ──────────────────────────

    #[Test]
    public function sem_nenhum_pai_o_fechamento_sai_exatamente_como_hoje_quatro_linhas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        [$raiz, $subgrupos] = $this->montarCasoMPozenato(comPai: false);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $linhasGrupo = DB::table('fechamento_grupo_snapshots')->get();

        $this->assertCount(
            4,
            $linhasGrupo,
            'Enquanto ninguém tem pai, cada grupo continua sendo a própria raiz — quatro linhas de cobrança, idêntico ao de antes da Fase 143.'
        );

        $porGrupo = $linhasGrupo->keyBy('company_group_id');

        // A raiz (que aqui é só mais um grupo solto) leva 2 empresas e
        // R$ 3.812.487,89 — cai na faixa 1 da PRÓPRIA tabela: R$ 12.000.
        $this->assertSame(2, (int) $porGrupo[$raiz->id]->empresas_count);
        $this->assertEqualsWithDelta(3_812_487.89, (float) $porGrupo[$raiz->id]->faturamento_total, 0.01);
        $this->assertSame(1, (int) $porGrupo[$raiz->id]->faixa_ordem);
        $this->assertEqualsWithDelta(12_000.00, (float) $porGrupo[$raiz->id]->valor_faixa, 0.01);

        // Os três subgrupos aparecem como grupos independentes, cada um com
        // a sua soma — nenhum enxerga a tabela do MPozenato.
        $this->assertSame(4, (int) $porGrupo[$subgrupos['DRossi']->id]->empresas_count);
        $this->assertEqualsWithDelta(1_238_304.17, (float) $porGrupo[$subgrupos['DRossi']->id]->faturamento_total, 0.01);
        $this->assertEqualsWithDelta(5_977_697.79, (float) $porGrupo[$subgrupos['Gran Belo']->id]->faturamento_total, 0.01);
        $this->assertEqualsWithDelta(1_650_921.98, (float) $porGrupo[$subgrupos['Lyam']->id]->faturamento_total, 0.01);
        $this->assertSame(
            'servico',
            $porGrupo[$subgrupos['Lyam']->id]->tabela_origem,
            'Sem pai, o subgrupo não alcança a tabela da raiz — continua caindo na régua do serviço, como hoje.'
        );

        // A linha de cada empresa aponta para o grupo direto dela.
        $this->assertSame(
            4,
            DB::table('fechamento_snapshots')->where('company_group_id', $subgrupos['DRossi']->id)->count()
        );
    }

    // ─── A correção: com pai, UMA linha de cobrança ───────────────────────

    #[Test]
    public function com_os_subgrupos_pendurados_sai_uma_unica_linha_de_cobranca(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        [$raiz, $subgrupos] = $this->montarCasoMPozenato(comPai: true);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $linhasGrupo = DB::table('fechamento_grupo_snapshots')->get();

        $this->assertCount(
            1,
            $linhasGrupo,
            'É o ponto da fase: quatro grupos, 10 empresas, UMA linha de cobrança — cobrar em pedaços apaga o desconto por volume da tabela progressiva.'
        );

        $linha = $linhasGrupo->first();

        $this->assertSame($raiz->id, (int) $linha->company_group_id, 'A linha é da RAIZ, não de nenhum subgrupo.');
        $this->assertSame('MPozenato', $linha->grupo_name, 'O nome da linha é o do grupo de cobrança, nunca o do subgrupo da âncora.');
        $this->assertSame(10, (int) $linha->empresas_count);
        $this->assertEqualsWithDelta(self::TOTAL_DO_CLIENTE, (float) $linha->faturamento_total, 0.01);

        // R$ 12,68 mi > R$ 5 mi → faixa 2 da tabela da raiz: R$ 21.000.
        $this->assertSame('grupo', $linha->tabela_origem);
        $this->assertNull($linha->servico_id);
        $this->assertSame(2, (int) $linha->faixa_ordem);
        $this->assertEqualsWithDelta(21_000.00, (float) $linha->valor_faixa, 0.01);
        $this->assertFalse(
            (bool) $linha->tabelas_divergentes,
            'Com a tabela da raiz valendo para os 10 membros, não há divergência de tabela entre eles.'
        );

        // Nenhum subgrupo vira linha cobrável (143-CONTEXT, D-05 item 2).
        foreach ($subgrupos as $nome => $sub) {
            $this->assertSame(
                0,
                DB::table('fechamento_grupo_snapshots')->where('company_group_id', $sub->id)->count(),
                "O subgrupo {$nome} não pode gerar linha de cobrança própria."
            );
        }

        // As 10 linhas de empresa apontam para a RAIZ — é por essa coluna
        // que `fechamento:verificar-consolidacao` casa membro com grupo.
        $this->assertSame(
            10,
            DB::table('fechamento_snapshots')->where('company_group_id', $raiz->id)->count()
        );
        $this->assertSame(
            0,
            DB::table('fechamento_snapshots')->whereIn('company_group_id', collect($subgrupos)->pluck('id'))->count()
        );

        // ⛔ O NPS não sente nada: `companies.company_group_id` continua
        // apontando para o SUBGRUPO — é ele que o link de NPS de grupo usa.
        $this->assertSame(
            4,
            Company::where('company_group_id', $subgrupos['DRossi']->id)->count(),
            'A árvore é aditiva: nenhuma empresa foi remanejada de grupo.'
        );
    }

    #[Test]
    public function a_consolidacao_pela_raiz_passa_no_verificador_de_consistencia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $this->montarCasoMPozenato(comPai: true);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        // LINHAS_ORFAS / DIVERGENCIA_SOMA_GRUPO / DIVERGENCIA_CONTAGEM
        // disparariam se a linha da empresa guardasse o subgrupo e a do
        // grupo guardasse a raiz — as duas PRECISAM usar a mesma chave.
        $this->artisan('fechamento:verificar-consolidacao', ['--mes' => '2026-08'])->assertExitCode(0);
    }

    #[Test]
    public function com_a_regra_nova_ligada_a_mensalidade_do_cliente_e_a_da_faixa_da_soma(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');

        [$raiz] = $this->montarCasoMPozenato(comPai: true);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $linha = DB::table('fechamento_grupo_snapshots')->where('company_group_id', $raiz->id)->first();

        $this->assertNotNull($linha);
        $this->assertEqualsWithDelta(
            21_000.00,
            (float) $linha->cobranca_mensal,
            0.01,
            'Fase 141 (D-03): a mensalidade é o valor da faixa da SOMA, e só isso — nenhum contrato de empresa-membro entra por cima.'
        );
    }
}
