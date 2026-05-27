<?php

namespace Tests\Feature;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Testes de integração do comando `phase14:verificar-cobranca`.
 *
 * Verifica o pre-flight do drop das colunas legacy (Plan 14-06). O comando
 * itera todas as empresas comparando o cálculo legacy contra o novo modelo
 * (contratos_servico). Diverge > R$ 0,01 = abort se flag passada.
 *
 * Per Plan 14-01 Task 2 + CONTEXT.md D-08/D-10.
 */
class Phase14VerificarCobrancaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper: cria 1 serviço mensal no catálogo.
     */
    private function criarServicoMensal(string $nome, float $valorPadrao = 0.0): Servico
    {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => $valorPadrao,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);
    }

    /**
     * Helper: cria contrato vinculado.
     */
    private function criarContrato(Company $company, Servico $servico, float $valorContratado, bool $ativo = true): ContratoServico
    {
        return ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => $valorContratado,
            'data_contratacao' => Carbon::now()->toDateString(),
            'ativo'            => $ativo,
        ]);
    }

    /**
     * Helper: registra faturamento Adman do mês atual (faixa 1 → R$ 3.000 base).
     */
    private function registrarFaturamento(Company $company, float $revenue): void
    {
        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => Carbon::now()->toDateString(),
            'revenue'        => $revenue,
            'synced_at'      => now(),
            'raw_data'       => [],
        ]);
    }

    public function test_zero_divergencias_quando_dados_consistentes(): void
    {
        // Cenário: empresa com additional_service_price=200 (legacy) + 1 contrato
        // mensal ATIVO no novo modelo com valor_contratado=200. Ambos cálculos
        // batem (faixa + 200) — zero divergências esperadas.
        $company = Company::create([
            'name'                     => 'Empresa Consistente',
            'cnpj'                     => '99999999999999',
            'active'                   => true,
            'adman_account_id'         => 'ACC-CONSISTENTE',
            'additional_service_price' => 200.00,
        ]);

        $this->registrarFaturamento($company, 300000.00); // faixa_1 → R$ 3.000
        $servico = $this->criarServicoMensal('Treinamento', 200.00);
        $this->criarContrato($company, $servico, 200.00);

        $exitCode = Artisan::call('phase14:verificar-cobranca');

        $this->assertEquals(0, $exitCode, 'Exit code deve ser 0 quando não há divergências');
        $this->assertStringContainsString('0 divergências', Artisan::output());
    }

    public function test_aborta_com_divergencia(): void
    {
        // Cenário: empresa com additional_service_price=200 (legacy) mas SEM
        // contrato novo correspondente. Legacy soma 200; novo soma 0 → divergência.
        // Com --abort-on-divergence o exit code deve ser 1.
        $company = Company::create([
            'name'                     => 'Empresa Divergente',
            'cnpj'                     => '88888888888888',
            'active'                   => true,
            'adman_account_id'         => 'ACC-DIVERGENTE',
            'additional_service_price' => 200.00,
        ]);

        $this->registrarFaturamento($company, 300000.00); // faixa_1 → R$ 3.000

        $exitCode = Artisan::call('phase14:verificar-cobranca', ['--abort-on-divergence' => true]);

        $this->assertEquals(1, $exitCode, 'Exit code deve ser 1 quando há divergência e --abort-on-divergence está ativo');
        $this->assertStringContainsString('divergência', Artisan::output());
    }

    public function test_sem_abort_flag_retorna_zero_mesmo_com_divergencia(): void
    {
        // Cenário: mesma divergência do teste anterior, mas SEM a flag.
        // Comando reporta a divergência (mensagem de erro) mas exit code é 0.
        $company = Company::create([
            'name'                     => 'Empresa Divergente Sem Flag',
            'cnpj'                     => '77777777777777',
            'active'                   => true,
            'adman_account_id'         => 'ACC-NOFLAG',
            'additional_service_price' => 200.00,
        ]);

        $this->registrarFaturamento($company, 300000.00);

        $exitCode = Artisan::call('phase14:verificar-cobranca');

        $this->assertEquals(0, $exitCode, 'Exit code deve ser 0 quando há divergência mas flag --abort-on-divergence está ausente');
    }
}
