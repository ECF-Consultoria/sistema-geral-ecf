<?php

namespace Tests\Feature\Phase141;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Configuracao;
use App\Models\ContratoServico;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\Servico;
use App\Services\Fechamento\FechamentoRegraTabela;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 141 Plano 04 — Tarefa 3: trava D-11 da Fase 137 sob a regra nova.
 *
 * Congela uma competência com a flag DESLIGADA, liga a flag, cadastra tabela
 * de empresa e altera as métricas de origem, e prova por RECONSULTA às
 * tabelas de snapshot que NADA daquela competência se move — nem
 * reconsolidar sem `--motivo=` é permitido, mesmo com a flag ligada.
 *
 * Se algum comportamento aqui falhar, o defeito é de produto (Passo 7/
 * writer), NUNCA da asserção — disciplina do CONTEXT da Fase 141.
 */
class Phase141CongeladoNaoMudaTest extends TestCase
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

    private function criarServicoGestao(): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update(['plataforma' => 'Mercado Livre', 'setor' => Servico::SETOR_PERFORMANCE]);

        return $servico->refresh();
    }

    /** Captura os campos que D-11 promete preservar, de uma linha de `fechamento_snapshots`/`fechamento_grupo_snapshots`. */
    private function capturar(object $linha): array
    {
        return [
            'faturamento_total' => (float) $linha->faturamento_total,
            'faixa_ordem'       => $linha->faixa_ordem === null ? null : (int) $linha->faixa_ordem,
            'valor_faixa'       => $linha->valor_faixa === null ? null : (float) $linha->valor_faixa,
            'cobranca_mensal'   => $linha->cobranca_mensal === null ? null : (float) $linha->cobranca_mensal,
            'estado'            => $linha->estado,
        ];
    }

    #[Test]
    public function competencia_congelada_com_a_flag_desligada_fica_imune_a_virada_de_regra(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        // ── Fixture: 1 grupo de 2 empresas + tabela do SERVIÇO (regra
        //    antiga) — congelado com a flag desligada.
        $gestao = $this->criarServicoGestao();
        $grupo  = CompanyGroup::create(['name' => 'Grupo Fase 141 Trava', 'color' => '#000']);

        $membroA = Company::factory()->create(['adman_account_id' => 'cust-trava-a', 'company_group_id' => $grupo->id]);
        $membroB = Company::factory()->create(['adman_account_id' => 'cust-trava-b', 'company_group_id' => $grupo->id]);

        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $membroA->id, 'ativo' => true, 'valor_contratado' => 0]);
        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $membroB->id, 'ativo' => true, 'valor_contratado' => 0]);

        AdmanMetric::create(['company_id' => $membroA->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $membroB->id, 'reference_date' => '2026-08-10', 'revenue' => 250_000.00]);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $snapshotAntesA     = DB::table('fechamento_snapshots')->where('company_id', $membroA->id)->first();
        $snapshotAntesB     = DB::table('fechamento_snapshots')->where('company_id', $membroB->id)->first();
        $snapshotGrupoAntes = DB::table('fechamento_grupo_snapshots')->where('company_group_id', $grupo->id)->first();

        $this->assertNotNull($snapshotAntesA);
        $this->assertNotNull($snapshotAntesB);
        $this->assertNotNull($snapshotGrupoAntes);

        $capturadoA     = $this->capturar($snapshotAntesA);
        $capturadoB     = $this->capturar($snapshotAntesB);
        $capturadoGrupo = $this->capturar($snapshotGrupoAntes);

        // ── Vira a flag, cadastra tabela de EMPRESA (que não existia antes)
        //    e altera as métricas de origem — tudo o que a regra nova faria
        //    diferente, se pudesse mexer no passado.
        $this->ligarFlag();

        foreach ([1, 2, 3] as $ordem) {
            EmpresaFaixaFaturamento::create([
                'company_id'      => $membroA->id,
                'ordem'           => $ordem,
                'limite_superior' => $ordem * 50_000,
                'valor'           => $ordem * 900,
                'valor_e_piso'    => false,
                'origem'          => EmpresaFaixaFaturamento::ORIGEM_MANUAL,
            ]);
        }

        AdmanMetric::create(['company_id' => $membroA->id, 'reference_date' => '2026-08-20', 'revenue' => 900_000.00]);
        AdmanMetric::create(['company_id' => $membroB->id, 'reference_date' => '2026-08-20', 'revenue' => 900_000.00]);

        // ── Reler direto das tabelas de snapshot — NUNCA pelo stdout do
        //    comando (disciplina de desempenho-bonificacao.md §4). Nenhum
        //    comando foi rodado de novo ainda: a virada de flag e a edição
        //    de dados de origem, sozinhas, não podem mexer em nada.
        $releituraA     = $this->capturar(DB::table('fechamento_snapshots')->where('company_id', $membroA->id)->first());
        $releituraB     = $this->capturar(DB::table('fechamento_snapshots')->where('company_id', $membroB->id)->first());
        $releituraGrupo = $this->capturar(DB::table('fechamento_grupo_snapshots')->where('company_group_id', $grupo->id)->first());

        $this->assertSame($capturadoA, $releituraA, 'Ligar a flag e cadastrar tabela própria, sozinhos, não podem mexer no snapshot congelado da empresa.');
        $this->assertSame($capturadoB, $releituraB);
        $this->assertSame($capturadoGrupo, $releituraGrupo, 'O mesmo vale para o snapshot do grupo.');

        // ── Reconsolidar SEM --motivo= continua recusado, mesmo com a flag
        //    ligada (D-12 nunca é contornado pela Fase 141).
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(1);

        $releituraApósRecusaA     = $this->capturar(DB::table('fechamento_snapshots')->where('company_id', $membroA->id)->first());
        $releituraApósRecusaB     = $this->capturar(DB::table('fechamento_snapshots')->where('company_id', $membroB->id)->first());
        $releituraApósRecusaGrupo = $this->capturar(DB::table('fechamento_grupo_snapshots')->where('company_group_id', $grupo->id)->first());

        $this->assertSame($capturadoA, $releituraApósRecusaA, 'Tentativa de reconsolidar sem --motivo= tem que ser recusada e não pode mudar nada.');
        $this->assertSame($capturadoB, $releituraApósRecusaB);
        $this->assertSame($capturadoGrupo, $releituraApósRecusaGrupo);

        $this->assertSame(0, DB::table('fechamento_reconsolidacoes')->count(), 'Sem --motivo=, nenhuma reconsolidação pode ter sido registrada.');
    }
}
