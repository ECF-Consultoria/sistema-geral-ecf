<?php

namespace Tests\Feature\Quick261006;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\Servico;
use App\Models\ServicoFaixaFaturamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 261006-gf5 — `fechamento:tabelas-de-contratos-assinados`, o retroativo dos contratos que
 * já estavam assinados antes de o carimbo existir (5 contratos em 5 empresas, medido em produção
 * em 2026-10-06).
 *
 * Prova o que mais importa num comando que escreve cobrança: **dry-run é o padrão** e não grava
 * nada (provado por RECONSULTA ao banco, nunca pelo stdout), `--aplicar` grava, a segunda rodada é
 * idempotente, `--company=` limita de fato o escopo, e tabela confirmada por humano continua
 * intocada.
 */
class Quick261006RetroativoCommandTest extends TestCase
{
    use RefreshDatabase;

    private function servicoComTabela(string $nome = 'Gestão'): Servico
    {
        $servico = Servico::create([
            'nome'          => $nome.' '.uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
            'plataforma'    => 'Mercado Livre',
        ]);

        ServicoFaixaFaturamento::create([
            'servico_id' => $servico->id, 'ordem' => 1, 'limite_superior' => 300_000.00, 'valor' => 1_500.00, 'valor_e_piso' => false,
        ]);
        ServicoFaixaFaturamento::create([
            'servico_id' => $servico->id, 'ordem' => 2, 'limite_superior' => null, 'valor' => 3_000.00, 'valor_e_piso' => true,
        ]);

        return $servico;
    }

    private function empresaComContratoAssinado(Servico $servico): Company
    {
        $company = Company::factory()->create();

        ContratoAssinatura::factory()->assinado()->create([
            'company_id'        => $company->id,
            'servico_id'        => $servico->id,
            'servicos_snapshot' => [[
                'servico'          => $servico->nome,
                'valor_contratado' => 1_847.32,
                'data_contratacao' => '2026-01-15',
                'data_vencimento'  => '2027-01-15',
            ]],
        ]);

        return $company;
    }

    private function contarFaixas(Company $company): int
    {
        return DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->count();
    }

    // ── 1. dry-run é o padrão e não escreve nada ─────────────────────────

    #[Test]
    public function sem_aplicar_nada_e_gravado(): void
    {
        $servico = $this->servicoComTabela();
        $company = $this->empresaComContratoAssinado($servico);

        $antes = $this->contarFaixas($company);

        $saida = Artisan::call('fechamento:tabelas-de-contratos-assinados');

        $this->assertSame(0, $saida);
        $this->assertSame($antes, $this->contarFaixas($company), 'Dry-run e o PADRAO: reconsulta ao banco tem que mostrar a mesma contagem');
        $this->assertSame(0, $this->contarFaixas($company));
        $this->assertStringContainsString('Simulação (dry-run)', Artisan::output());
    }

    #[Test]
    public function dry_run_explicito_vence_o_aplicar(): void
    {
        $servico = $this->servicoComTabela();
        $company = $this->empresaComContratoAssinado($servico);

        // Entre "gravou sem querer" e "nao gravou achando que gravou", o segundo e o erro barato.
        Artisan::call('fechamento:tabelas-de-contratos-assinados', ['--aplicar' => true, '--dry-run' => true]);

        $this->assertSame(0, $this->contarFaixas($company));
    }

    // ── 2. `--aplicar` grava, e a segunda rodada é idempotente ───────────

    #[Test]
    public function com_aplicar_a_tabela_passa_a_confirmada_por_contrato(): void
    {
        $servico = $this->servicoComTabela();
        $company = $this->empresaComContratoAssinado($servico);

        $saida = Artisan::call('fechamento:tabelas-de-contratos-assinados', ['--aplicar' => true]);

        $this->assertSame(0, $saida);

        $linhas = DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->orderBy('ordem')->get();

        $this->assertCount(2, $linhas);
        foreach ($linhas as $linha) {
            $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_CONTRATO, $linha->origem);
            $this->assertSame($servico->id, (int) $linha->servico_origem_id);
        }

        // Segunda rodada: cai no guard de "já confirmada", não duplica nem reescreve.
        Artisan::call('fechamento:tabelas-de-contratos-assinados', ['--aplicar' => true]);

        $this->assertSame(2, $this->contarFaixas($company));
    }

    // ── 3. tabela confirmada por humano fica intocada ────────────────────

    #[Test]
    public function tabela_manual_nao_e_tocada_pelo_retroativo(): void
    {
        $servico = $this->servicoComTabela();
        $company = $this->empresaComContratoAssinado($servico);

        EmpresaFaixaFaturamento::create([
            'company_id'      => $company->id,
            'ordem'           => 1,
            'limite_superior' => null,
            'valor'           => 4_242.00,
            'valor_e_piso'    => false,
            'origem'          => EmpresaFaixaFaturamento::ORIGEM_MANUAL,
        ]);

        Artisan::call('fechamento:tabelas-de-contratos-assinados', ['--aplicar' => true]);

        $linhas = DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->get();

        $this->assertCount(1, $linhas);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_MANUAL, $linhas[0]->origem);
        $this->assertSame(4_242.00, (float) $linhas[0]->valor);
    }

    // ── 4. `--company=` limita o escopo de verdade ───────────────────────

    #[Test]
    public function company_limita_a_varredura_a_uma_empresa(): void
    {
        $servico = $this->servicoComTabela();
        $alvo    = $this->empresaComContratoAssinado($servico);
        $outra   = $this->empresaComContratoAssinado($servico);

        Artisan::call('fechamento:tabelas-de-contratos-assinados', ['--aplicar' => true, '--company' => $alvo->id]);

        $this->assertSame(2, $this->contarFaixas($alvo));
        $this->assertSame(0, $this->contarFaixas($outra), '--company tem que limitar o escopo de ESCRITA, nao so o do relatorio');
    }

    // ── 5. contrato não assinado nunca entra na varredura ────────────────

    #[Test]
    public function contrato_aguardando_assinatura_nao_entra_na_varredura(): void
    {
        $servico = $this->servicoComTabela();
        $company = Company::factory()->create();

        ContratoAssinatura::factory()->emAndamento()->create([
            'company_id' => $company->id,
            'servico_id' => $servico->id,
        ]);

        Artisan::call('fechamento:tabelas-de-contratos-assinados', ['--aplicar' => true]);

        $this->assertSame(0, $this->contarFaixas($company));
        $this->assertStringContainsString('examinados: 0', Artisan::output());
    }

    // ── 6. o relatório do dry-run diz o que PASSARIA a ter ───────────────

    #[Test]
    public function o_relatorio_json_do_dry_run_anuncia_o_que_passaria_a_ter(): void
    {
        $servico = $this->servicoComTabela();
        $company = $this->empresaComContratoAssinado($servico);

        Artisan::call('fechamento:tabelas-de-contratos-assinados', ['--json' => true]);

        $relatorio = json_decode(trim(Artisan::output()), true);

        $this->assertFalse($relatorio['aplicou']);
        $this->assertSame(1, $relatorio['contratos']);
        $this->assertSame('simulado', $relatorio['linhas'][0]['motivo']);
        $this->assertSame($company->id, $relatorio['linhas'][0]['company_id']);
        $this->assertSame(0, $relatorio['linhas'][0]['faixas_antes']);
        $this->assertSame(2, $relatorio['linhas'][0]['faixas_depois']);

        // E o dry-run segue sem escrever nada.
        $this->assertSame(0, $this->contarFaixas($company));
    }
}
