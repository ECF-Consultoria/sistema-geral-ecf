<?php

namespace Tests\Feature\Quick261006;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\Servico;
use App\Models\ServicoFaixaFaturamento;
use App\Models\User;
use App\Services\Fechamento\TabelaDeContratoAssinadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 261006-gf5 — **a prova de que este quick muda o SELO, não o VALOR**.
 *
 * É o caso exato que o usuário relatou em 2026-10-06: a MADERATTO MÓVEIS (#446) teve o contrato
 * GERADO POR ESTE SISTEMA e assinado em 2026-08-27, mas o fechamento mostrava a tabela dela como
 * PRESUMIDA — porque a materialização da Fase 141 (`fechamento:materializar-tabelas`, rodada em
 * 2026-09-09 21:02) copiou a tabela do serviço com `origem = 'presumida_servico'`, selo que por
 * decisão da Fase 141 (D-04/D-05) nunca passa por confirmado.
 *
 * O teste mede o fechamento ANTES e DEPOIS, pelo MESMO endpoint da tela
 * (`/administrativo/financeiro`, harness de `Phase139LastroTabelaTest`):
 *  - `tabela_confirmada` vira `true` e `procedencia_tabela` vira `contrato`;
 *  - `cobranca_mensal` é **idêntida ao centavo** — as faixas gravadas são as mesmas presumidas.
 *
 * ⛔ Se algum dia este teste acusar diferença de valor, a regressão é no quick, não no teste: o
 * selo nunca pode ser um caminho lateral para mudar cobrança.
 */
class Quick261006ValorDeCobrancaNaoMudaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * "Gestão" é semeada com as 7 faixas pela migration
     * `2026_09_02_100003_seed_faixas_faturamento_iniciais` — mesmo padrão de
     * `Phase139LastroTabelaTest::criarServicoGestao()`. É também o serviço do contrato real da
     * Maderatto.
     */
    private function servicoGestao(): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update(['plataforma' => 'Mercado Livre', 'setor' => Servico::SETOR_PERFORMANCE]);

        return $servico->refresh();
    }

    /** @return array<string, mixed>|null */
    private function linhaDoFechamento(User $admin, Company $company): ?array
    {
        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        return collect($response->viewData('page')['props']['companies'])->firstWhere('id', $company->id);
    }

    #[Test]
    public function presumida_vira_confirmada_sem_mexer_em_um_centavo_da_cobranca(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = User::factory()->create(['role' => 'admin', 'active' => true]);
        $gestao  = $this->servicoGestao();
        $company = Company::factory()->create(['adman_account_id' => 'cust-'.uniqid()]);

        ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $gestao->id,
            'valor_contratado' => 0,
            'data_contratacao' => Carbon::now()->toDateString(),
            'ativo'            => true,
        ]);

        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-09-05',
            'revenue'        => 100_000.00,
        ]);

        // O estado real de produção: tabela própria, mas carimbada como PRESUNÇÃO da tabela do
        // serviço (o que a materialização da Fase 141 deixou).
        $faixasDoServico = ServicoFaixaFaturamento::where('servico_id', $gestao->id)->ordenadas()->get();
        $this->assertTrue($faixasDoServico->isNotEmpty(), 'A migration de seed das faixas de Gestao e premissa deste teste');

        foreach ($faixasDoServico as $faixa) {
            EmpresaFaixaFaturamento::create([
                'company_id'        => $company->id,
                'ordem'             => $faixa->ordem,
                'limite_superior'   => $faixa->limite_superior,
                'valor'             => $faixa->valor,
                'valor_e_piso'      => $faixa->valor_e_piso,
                'origem'            => EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO,
                'servico_origem_id' => $gestao->id,
            ]);
        }

        // Contrato gerado e assinado PELO PRÓPRIO SISTEMA.
        $contrato = ContratoAssinatura::create([
            'company_id'        => $company->id,
            'servico_id'        => $gestao->id,
            'status'            => ContratoAssinatura::STATUS_ASSINADO,
            'assinado_em'       => Carbon::parse('2026-08-27'),
            'servicos_snapshot' => [[
                'servico'          => $gestao->nome,
                'valor_contratado' => 1_847.32,
                'data_contratacao' => '2026-02-01',
                'data_vencimento'  => '2027-02-01',
            ]],
        ]);

        // ── ANTES: a queixa do usuário, reproduzida ──────────────────────
        $antes = $this->linhaDoFechamento($admin, $company);

        $this->assertNotNull($antes);
        $this->assertSame('propria', $antes['tabela_origem']);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO, $antes['procedencia_tabela']);
        $this->assertFalse(
            $antes['tabela_confirmada'],
            'Reproducao da queixa de 2026-10-06: contrato assinado pelo sistema e tabela ainda aparecia como presumida'
        );
        $this->assertNotNull($antes['cobranca_mensal']);
        $this->assertGreaterThan(0.0, (float) $antes['cobranca_mensal']);

        // ── O quick ──────────────────────────────────────────────────────
        $resultado = app(TabelaDeContratoAssinadoService::class)->aplicar($contrato);
        $this->assertTrue($resultado['gravou']);

        // ── DEPOIS: selo mudou, valor não ────────────────────────────────
        $depois = $this->linhaDoFechamento($admin, $company);

        $this->assertNotNull($depois);
        $this->assertSame('propria', $depois['tabela_origem']);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_CONTRATO, $depois['procedencia_tabela']);
        $this->assertTrue($depois['tabela_confirmada'], 'Contrato assinado pelo proprio sistema confirma a tabela da empresa');

        $this->assertSame(
            (float) $antes['cobranca_mensal'],
            (float) $depois['cobranca_mensal'],
            'O quick muda o SELO da tabela, nunca o valor cobrado — se este assert falhar, a regressao e no quick'
        );
        $this->assertSame($antes['faixa_label'] ?? null, $depois['faixa_label'] ?? null);
        $this->assertSame($antes['estado'] ?? null, $depois['estado'] ?? null);

        // E a tabela no banco continua com as MESMAS faixas, só com outra origem.
        $noBanco = DB::table('empresa_faixas_faturamento')
            ->where('company_id', $company->id)
            ->orderBy('ordem')
            ->get();

        $this->assertCount($faixasDoServico->count(), $noBanco);
        foreach ($noBanco as $i => $linha) {
            $this->assertSame((float) $faixasDoServico[$i]->valor, (float) $linha->valor);
            $this->assertSame(
                $faixasDoServico[$i]->limite_superior !== null ? (float) $faixasDoServico[$i]->limite_superior : null,
                $linha->limite_superior !== null ? (float) $linha->limite_superior : null
            );
            $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_CONTRATO, $linha->origem);
        }
    }
}
