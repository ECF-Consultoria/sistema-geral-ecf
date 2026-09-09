<?php

namespace Tests\Feature\Phase141;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\FechamentoSnapshot;
use App\Models\Servico;
use App\Support\CobrancaCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 141, Plano 02, Tarefa 2 — `CobrancaCalculator::mensalidade()`.
 *
 * D-03 do CONTEXT: a mensalidade de quem tem faixa é o valor da faixa, e
 * só isso — nunca faixa + soma de contratos. Prova o caso concreto que
 * abriu a fase (BARAOSHOP) comparando ANTES (`novo()`) × DEPOIS
 * (`mensalidade()`) lado a lado.
 */
class Phase141MensalidadeCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function servicoMensal(string $nome = 'Gestão de ADS Shopee'): Servico
    {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);
    }

    private function servicoUnico(string $nome = 'Serviço avulso'): Servico
    {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_UNICA,
            'ativo'         => true,
        ]);
    }

    private function contrato(Company $company, Servico $servico, float $valor, bool $ativo = true): ContratoServico
    {
        return ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => $valor,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => $ativo,
        ]);
    }

    /**
     * Caso concreto que abriu a Fase 141: BARAOSHOP VARIEDADES, agosto/2026
     * — faturamento R$ 488.262,90, faixa 1 (R$ 3.000), contrato mensal de
     * Shopee de R$ 2.500. A regra ANTIGA (`novo()`) cobra R$ 5.500 (faixa +
     * contrato). A regra NOVA (`mensalidade()`) cobra só a faixa: R$ 3.000.
     */
    #[Test]
    public function caso_baraoshop_antes_e_depois_lado_a_lado(): void
    {
        $company = Company::factory()->create(['name' => 'Baraoshop Variedades']);
        $shopee  = $this->servicoMensal('Gestão de ADS Shopee');
        $this->contrato($company, $shopee, 2500.00);

        $classificacao = [
            'ordem'           => 1,
            'label'           => 'faixa_1',
            'valor'           => 3000.00,
            'valor_e_piso'    => false,
            'limite_inferior' => 0.0,
            'limite_superior' => 500000.0,
        ];

        $contratos = ContratoServico::where('company_id', $company->id)->with('servico')->get();

        $faixaDataLegado = ['faixa' => 'faixa_1', 'valor' => 3000.00];

        $antes  = CobrancaCalculator::novo($faixaDataLegado, $contratos);
        $depois = CobrancaCalculator::mensalidade($classificacao, $contratos);

        $this->assertSame(5500.0, $antes, 'ANTES (regra atual): faixa R$ 3.000 + contrato Shopee R$ 2.500 = R$ 5.500 — o bug que abriu a fase.');
        $this->assertSame(3000.0, $depois, 'DEPOIS (D-03): mensalidade é só o valor da faixa, nunca soma o contrato por cima.');
    }

    #[Test]
    public function com_faixa_e_sem_contrato_mensal_mensalidade_e_o_valor_da_faixa(): void
    {
        $classificacao = [
            'ordem' => 2, 'label' => 'faixa_2', 'valor' => 4500.00,
            'valor_e_piso' => false, 'limite_inferior' => 500000.0, 'limite_superior' => 1000000.0,
        ];

        $resultado = CobrancaCalculator::mensalidade($classificacao, []);

        $this->assertSame(4500.0, $resultado);
    }

    #[Test]
    public function sem_faixa_mensalidade_e_a_soma_dos_contratos_mensais_ativos(): void
    {
        $company = Company::factory()->create();
        $servicoA = $this->servicoMensal('Gestão');
        $servicoB = $this->servicoMensal('Mentoria');
        $this->contrato($company, $servicoA, 1200.00);
        $this->contrato($company, $servicoB, 800.00);

        $contratos = ContratoServico::where('company_id', $company->id)->with('servico')->get();

        $resultado = CobrancaCalculator::mensalidade(null, $contratos);

        $this->assertSame(2000.0, $resultado);
    }

    #[Test]
    public function sem_faixa_e_sem_contrato_mensal_ativo_mensalidade_e_null_nunca_zero(): void
    {
        $company = Company::factory()->create();
        $servicoUnico = $this->servicoUnico();
        $servicoMensalInativo = $this->servicoMensal('Serviço cancelado');
        $this->contrato($company, $servicoUnico, 500.00);
        $this->contrato($company, $servicoMensalInativo, 900.00, ativo: false);

        $contratos = ContratoServico::where('company_id', $company->id)->with('servico')->get();

        $resultado = CobrancaCalculator::mensalidade(null, $contratos);

        $this->assertNull($resultado, '"Não sei quanto cobrar" é estado diferente de "cobrar zero".');
    }

    #[Test]
    public function contrato_inativo_e_tipo_unica_nao_entram_na_soma_mesmo_com_faixa_presente(): void
    {
        $company = Company::factory()->create();
        $servicoMensal = $this->servicoMensal();
        $servicoUnico  = $this->servicoUnico();
        $this->contrato($company, $servicoMensal, 1000.00, ativo: false);
        $this->contrato($company, $servicoUnico, 300.00);

        $contratos = ContratoServico::where('company_id', $company->id)->with('servico')->get();

        $classificacao = [
            'ordem' => 1, 'label' => 'faixa_1', 'valor' => 3000.00,
            'valor_e_piso' => false, 'limite_inferior' => 0.0, 'limite_superior' => 500000.0,
        ];

        // Com faixa presente, mensalidade() nem olha pros contratos — mas o
        // teste confirma que a saída continua sendo só a faixa.
        $resultado = CobrancaCalculator::mensalidade($classificacao, $contratos);

        $this->assertSame(3000.0, $resultado);
    }

    #[Test]
    public function legacy_e_novo_continuam_com_o_comportamento_de_hoje(): void
    {
        $faixaData = ['faixa' => 'faixa_1', 'valor' => 3000.00];

        $this->assertSame(3200.0, CobrancaCalculator::legacy($faixaData, 200.00));

        $company = Company::factory()->create();
        $servico = $this->servicoMensal();
        $this->contrato($company, $servico, 2500.00);
        $contratos = ContratoServico::where('company_id', $company->id)->with('servico')->get();

        $this->assertSame(5500.0, CobrancaCalculator::novo($faixaData, $contratos));
    }

    #[Test]
    public function estado_valor_fixo_existe_e_cabe_na_coluna_de_20_caracteres(): void
    {
        $this->assertSame('valor_fixo', FechamentoSnapshot::ESTADO_VALOR_FIXO);
        $this->assertLessThanOrEqual(20, strlen(FechamentoSnapshot::ESTADO_VALOR_FIXO));
    }
}
