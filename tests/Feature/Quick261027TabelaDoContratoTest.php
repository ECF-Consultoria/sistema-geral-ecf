<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\Servico;
use App\Services\Clicksign\ContratoVariaveisModeloService;
use App\Services\Contratos\TabelaDoContratoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 261027-m1y — resolver / congelar / imprimir a tabela progressiva no contrato de Gestão.
 */
class Quick261027TabelaDoContratoTest extends TestCase
{
    use RefreshDatabase;

    private const NOVA = [
        [1, 500_000.00, 3_700.00, false],
        [2, 1_000_000.00, 5_500.00, false],
        [3, 2_000_000.00, 7_400.00, false],
        [4, 3_000_000.00, 9_250.00, false],
        [5, 5_000_000.00, 11_100.00, false],
        [6, null, 13_500.00, true],
    ];

    private function servico(string $nome, array $faixas = self::NOVA): Servico
    {
        $s = Servico::where('nome', $nome)->first() ?? Servico::create([
            'nome' => $nome, 'valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo' => true, 'setor' => Servico::SETOR_PERFORMANCE,
        ]);

        DB::table('servico_faixas_faturamento')->where('servico_id', $s->id)->delete();
        foreach ($faixas as [$ordem, $limite, $valor, $piso]) {
            DB::table('servico_faixas_faturamento')->insert([
                'servico_id' => $s->id, 'ordem' => $ordem, 'limite_superior' => $limite,
                'valor' => $valor, 'valor_e_piso' => $piso, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $s;
    }

    private function contrato(Servico $servico, ?Company $company = null): ContratoAssinatura
    {
        return ContratoAssinatura::factory()->comSnapshot([[
            'servico' => $servico->nome, 'valor_contratado' => 1500.0,
            'data_contratacao' => '2026-01-01', 'data_vencimento' => '2027-01-01', 'plataforma' => 'Mercado Livre',
        ]])->create([
            'company_id' => ($company ?? Company::factory()->create())->id,
            'servico_id' => $servico->id,
        ]);
    }

    private function tabelaDaEmpresa(Company $c, string $origem, float $valor = 999.00): void
    {
        EmpresaFaixaFaturamento::create([
            'company_id' => $c->id, 'ordem' => 1, 'limite_superior' => null, 'valor' => $valor,
            'valor_e_piso' => true, 'origem' => $origem,
        ]);
    }

    private function service(): TabelaDoContratoService
    {
        return app(TabelaDoContratoService::class);
    }

    #[Test]
    public function sem_tabela_propria_resolve_a_do_servico(): void
    {
        $gestao = $this->servico('Gestão');
        $company = Company::factory()->create();

        $r = $this->service()->resolver($company, $gestao);

        $this->assertSame('servico', $r['origem']);
        $this->assertSame($gestao->id, $r['servico_id']);
        $this->assertCount(6, $r['faixas']);
    }

    #[Test]
    public function presumida_servico_nunca_conta_como_especifica(): void
    {
        $gestao = $this->servico('Gestão');
        $company = Company::factory()->create();
        $this->tabelaDaEmpresa($company, EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO, 123.00);

        $r = $this->service()->resolver($company, $gestao);

        $this->assertSame('servico', $r['origem']);
        $this->assertSame(3700.0, $r['faixas'][0]['valor']);
    }

    #[Test]
    public function tabela_manual_ou_de_contrato_da_empresa_vence(): void
    {
        $gestao = $this->servico('Gestão');

        foreach ([EmpresaFaixaFaturamento::ORIGEM_MANUAL, EmpresaFaixaFaturamento::ORIGEM_CONTRATO] as $origem) {
            $company = Company::factory()->create();
            $this->tabelaDaEmpresa($company, $origem, 999.00);

            $r = $this->service()->resolver($company, $gestao);

            $this->assertSame($origem, $r['origem']);
            $this->assertCount(1, $r['faixas']);
            $this->assertSame(999.0, $r['faixas'][0]['valor']);
        }
    }

    #[Test]
    public function linhas_da_tabela_nova(): void
    {
        $snapshot = $this->service()->resolver(Company::factory()->create(), $this->servico('Gestão'));

        $l = TabelaDoContratoService::linhasParaContrato($snapshot);

        $this->assertCount(7, $l);
        $this->assertSame(['faturamento' => 'Até R$ 500.000,00', 'valor' => 'R$ 3.700,00'], $l[0]);
        $this->assertSame(['faturamento' => 'A partir de R$ 500.000,00', 'valor' => 'R$ 5.500,00'], $l[1]);
        $this->assertSame(['faturamento' => 'A partir de R$ 3.000.000,00', 'valor' => 'R$ 11.100,00'], $l[4]);
        $this->assertSame(['faturamento' => 'Acima de R$ 5.000.000,00', 'valor' => 'a partir de R$ 13.500,00'], $l[5]);
        $this->assertSame(['faturamento' => '', 'valor' => ''], $l[6]);
    }

    #[Test]
    public function limites_legados_com_99_imprimem_o_inicio_redondo(): void
    {
        $l = TabelaDoContratoService::linhasParaContrato(['faixas' => [
            ['ordem' => 1, 'limite_superior' => 499_999.99, 'valor' => 3000.0, 'valor_e_piso' => false],
            ['ordem' => 2, 'limite_superior' => 999_999.99, 'valor' => 4500.0, 'valor_e_piso' => false],
            ['ordem' => 3, 'limite_superior' => null, 'valor' => 6000.0, 'valor_e_piso' => true],
        ]]);

        $this->assertSame('Até R$ 499.999,99', $l[0]['faturamento']);
        $this->assertSame('A partir de R$ 500.000,00', $l[1]['faturamento']);
        $this->assertSame('Acima de R$ 1.000.000,00', $l[2]['faturamento']);
    }

    #[Test]
    public function snapshot_nulo_gera_sete_linhas_vazias(): void
    {
        $l = TabelaDoContratoService::linhasParaContrato(null);

        $this->assertCount(7, $l);
        $this->assertSame(['faturamento' => '', 'valor' => ''], $l[3]);
    }

    #[Test]
    public function oito_faixas_recusam_e_nao_gravam(): void
    {
        $faixas = [];
        for ($i = 1; $i <= 8; $i++) {
            $faixas[] = [$i, $i < 8 ? $i * 100_000.00 : null, 1000.0 * $i, $i === 8];
        }
        $gestao  = $this->servico('Gestão');
        $company = Company::factory()->create();
        foreach ($faixas as [$ordem, $limite, $valor, $piso]) {
            EmpresaFaixaFaturamento::create([
                'company_id' => $company->id, 'ordem' => $ordem, 'limite_superior' => $limite,
                'valor' => $valor, 'valor_e_piso' => $piso, 'origem' => EmpresaFaixaFaturamento::ORIGEM_MANUAL,
            ]);
        }
        $contrato = $this->contrato($gestao, $company);

        try {
            $this->service()->congelar($contrato);
            $this->fail('Deveria recusar tabela com 8 faixas.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('8 faixas', $e->getMessage());
        }

        $this->assertNull(DB::table('contrato_assinaturas')->where('id', $contrato->id)->value('tabela_progressiva_snapshot'));

        $this->expectException(\RuntimeException::class);
        TabelaDoContratoService::linhasParaContrato(['faixas' => array_map(
            fn ($i) => ['ordem' => $i, 'limite_superior' => 1.0 * $i, 'valor' => 1.0, 'valor_e_piso' => false],
            range(1, 8)
        )]);
    }

    #[Test]
    public function congelar_e_idempotente_e_edicao_posterior_nao_altera_o_contrato(): void
    {
        $gestao  = $this->servico('Gestão');
        $company = Company::factory()->create();
        $this->tabelaDaEmpresa($company, EmpresaFaixaFaturamento::ORIGEM_MANUAL, 999.00);
        $contrato = $this->contrato($gestao, $company);

        $this->service()->congelar($contrato);

        DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->update(['valor' => 5555.00]);
        $this->service()->congelar($contrato->fresh());

        $snap = json_decode(DB::table('contrato_assinaturas')->where('id', $contrato->id)->value('tabela_progressiva_snapshot'), true);
        $this->assertSame('manual', $snap['origem']);
        $this->assertSame(999.0, (float) $snap['faixas'][0]['valor']);
    }

    #[Test]
    public function servico_fora_da_lista_nao_congela(): void
    {
        $brigada  = $this->servico('Brigada');
        $contrato = $this->contrato($brigada);

        $this->service()->congelar($contrato);

        $this->assertNull(DB::table('contrato_assinaturas')->where('id', $contrato->id)->value('tabela_progressiva_snapshot'));
    }

    #[Test]
    public function montar_emite_as_14_variaveis(): void
    {
        $nomes = ContratoVariaveisModeloService::nomes();
        for ($n = 1; $n <= 7; $n++) {
            $this->assertContains("faixa_{$n}_faturamento", $nomes);
            $this->assertContains("faixa_{$n}_valor", $nomes);
        }

        $gestao   = $this->servico('Gestão');
        $contrato = $this->contrato($gestao);
        $this->service()->congelar($contrato);

        $v = app(ContratoVariaveisModeloService::class)->montar($contrato->fresh(['company', 'servico']))['variaveis'];

        $this->assertSame('Até R$ 500.000,00', $v['faixa_1_faturamento']);
        $this->assertSame('R$ 3.700,00', $v['faixa_1_valor']);
        $this->assertSame('Acima de R$ 5.000.000,00', $v['faixa_6_faturamento']);
        $this->assertSame('a partir de R$ 13.500,00', $v['faixa_6_valor']);
        $this->assertSame('', $v['faixa_7_faturamento']);
        $this->assertSame('', $v['faixa_7_valor']);
    }

    #[Test]
    public function contrato_sem_snapshot_emite_as_14_vazias(): void
    {
        $brigada  = $this->servico('Brigada');
        $contrato = $this->contrato($brigada);

        $v = app(ContratoVariaveisModeloService::class)->montar($contrato->fresh(['company', 'servico']))['variaveis'];

        for ($n = 1; $n <= 7; $n++) {
            $this->assertSame('', $v["faixa_{$n}_faturamento"]);
            $this->assertSame('', $v["faixa_{$n}_valor"]);
        }
    }
}
