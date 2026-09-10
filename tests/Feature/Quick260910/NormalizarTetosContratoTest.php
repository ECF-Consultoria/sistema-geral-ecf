<?php

namespace Tests\Feature\Quick260910;

use App\Models\Company;
use App\Models\EmpresaFaixaFaturamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 260910-l7k — `fechamento:normalizar-tetos-contrato`, a correção dos 75 tetos que já
 * entraram em produção com o valor redondo do contrato.
 *
 * Dry-run é o padrão; `--aplicar` escreve pela porta única. Toda asserção de persistência é por
 * RECONSULTA ao banco.
 */
class NormalizarTetosContratoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Uma tabela de contrato com os tetos como o parser gravava: valor redondo, terminando em ",00".
     */
    private function tabelaDeContratoForaDaConvencao(Company $company, ?int $servicoOrigemId = null): void
    {
        EmpresaFaixaFaturamento::create([
            'company_id'        => $company->id,
            'ordem'             => 1,
            'limite_superior'   => 500_000.00,
            'valor'             => 3_000.00,
            'valor_e_piso'      => false,
            'origem'            => EmpresaFaixaFaturamento::ORIGEM_CONTRATO,
            'servico_origem_id' => $servicoOrigemId,
        ]);

        EmpresaFaixaFaturamento::create([
            'company_id'        => $company->id,
            'ordem'             => 2,
            'limite_superior'   => null,
            'valor'             => 5_000.00,
            'valor_e_piso'      => true,
            'origem'            => EmpresaFaixaFaturamento::ORIGEM_CONTRATO,
            'servico_origem_id' => $servicoOrigemId,
        ]);
    }

    #[Test]
    public function sem_aplicar_nao_escreve_nada(): void
    {
        $company = Company::factory()->create();
        $this->tabelaDeContratoForaDaConvencao($company);

        $this->artisan('fechamento:normalizar-tetos-contrato')->assertSuccessful();

        $this->assertSame(
            500000.00,
            (float) DB::table('empresa_faixas_faturamento')
                ->where('company_id', $company->id)->where('ordem', 1)->value('limite_superior')
        );
        $this->assertSame(0, DB::table('activity_log')->where('log_name', 'faixa_faturamento_tabela')->count());
    }

    #[Test]
    public function com_aplicar_o_teto_entra_na_convencao_sem_mexer_no_resto(): void
    {
        $company = Company::factory()->create();
        $this->tabelaDeContratoForaDaConvencao($company);

        $this->artisan('fechamento:normalizar-tetos-contrato --aplicar')->assertSuccessful();

        $linhas = DB::table('empresa_faixas_faturamento')
            ->where('company_id', $company->id)->orderBy('ordem')->get();

        $this->assertCount(2, $linhas);
        $this->assertSame(499999.99, (float) $linhas[0]->limite_superior);
        $this->assertNull($linhas[1]->limite_superior);

        // Só o teto muda.
        $this->assertSame(3000.00, (float) $linhas[0]->valor);
        $this->assertSame(5000.00, (float) $linhas[1]->valor);
        $this->assertFalse((bool) $linhas[0]->valor_e_piso);
        $this->assertTrue((bool) $linhas[1]->valor_e_piso);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_CONTRATO, $linhas[0]->origem);
    }

    #[Test]
    public function o_vinculo_com_o_servico_de_origem_e_preservado(): void
    {
        $servico = \App\Models\Servico::create([
            'nome'          => 'Gestão Teste '.uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => \App\Models\Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => \App\Models\Servico::SETOR_PERFORMANCE,
            'plataforma'    => 'Mercado Livre',
        ]);
        $company = Company::factory()->create();
        $this->tabelaDeContratoForaDaConvencao($company, $servico->id);

        $this->artisan('fechamento:normalizar-tetos-contrato --aplicar')->assertSuccessful();

        $servicoOrigem = DB::table('empresa_faixas_faturamento')
            ->where('company_id', $company->id)->pluck('servico_origem_id')->unique()->all();

        $this->assertSame([$servico->id], array_values($servicoOrigem));
    }

    #[Test]
    public function grava_uma_unica_entrada_de_auditoria_por_empresa(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->tabelaDeContratoForaDaConvencao($companyA);
        $this->tabelaDeContratoForaDaConvencao($companyB);

        $this->artisan('fechamento:normalizar-tetos-contrato --aplicar')->assertSuccessful();

        $entradas = DB::table('activity_log')->where('log_name', 'faixa_faturamento_tabela')->get();
        $this->assertCount(2, $entradas);

        $propriedades = json_decode($entradas[0]->properties, true);
        $this->assertSame('normalizacao_teto', $propriedades['feito_de']);
        $this->assertSame(500000.00, (float) $propriedades['antes'][0]['limite_superior']);
        $this->assertSame(499999.99, (float) $propriedades['depois'][0]['limite_superior']);
    }

    #[Test]
    public function tabela_ja_na_convencao_nao_e_tocada(): void
    {
        $company = Company::factory()->create();

        EmpresaFaixaFaturamento::create([
            'company_id'      => $company->id,
            'ordem'           => 1,
            'limite_superior' => 499_999.99,
            'valor'           => 3_000.00,
            'valor_e_piso'    => false,
            'origem'          => EmpresaFaixaFaturamento::ORIGEM_CONTRATO,
        ]);

        $idAntes = DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->value('id');

        $this->artisan('fechamento:normalizar-tetos-contrato --aplicar')->assertSuccessful();

        $linha = DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->first();
        $this->assertSame($idAntes, $linha->id, 'a tabela não deveria ter sido reescrita');
        $this->assertSame(499999.99, (float) $linha->limite_superior);
        $this->assertSame(0, DB::table('activity_log')->where('log_name', 'faixa_faturamento_tabela')->count());
    }

    #[Test]
    public function tabela_de_outra_procedencia_nao_e_tocada(): void
    {
        $company = Company::factory()->create();

        EmpresaFaixaFaturamento::create([
            'company_id'      => $company->id,
            'ordem'           => 1,
            'limite_superior' => 500_000.00,
            'valor'           => 3_000.00,
            'valor_e_piso'    => false,
            'origem'          => EmpresaFaixaFaturamento::ORIGEM_MANUAL,
        ]);

        $this->artisan('fechamento:normalizar-tetos-contrato --aplicar')->assertSuccessful();

        $this->assertSame(
            500000.00,
            (float) DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->value('limite_superior')
        );
        $this->assertSame(0, DB::table('activity_log')->where('log_name', 'faixa_faturamento_tabela')->count());
    }

    #[Test]
    public function tabela_fora_de_ordem_e_pulada_e_nomeada_sem_ser_corrigida_pela_metade(): void
    {
        // A forma da MAXIGOLD (#234)/EZIOFREDIANI (#256), medida em produção.
        $company = Company::factory()->create(['name' => 'MAXIGOLD EXEMPLO']);

        foreach ([
            ['ordem' => 1, 'limite_superior' => 48_000.00, 'valor' => 1_500.00, 'valor_e_piso' => false],
            ['ordem' => 2, 'limite_superior' => 25_000.00, 'valor' => 2_000.00, 'valor_e_piso' => false],
            ['ordem' => 3, 'limite_superior' => null, 'valor' => 4_000.00, 'valor_e_piso' => true],
        ] as $faixa) {
            EmpresaFaixaFaturamento::create($faixa + [
                'company_id' => $company->id,
                'origem'     => EmpresaFaixaFaturamento::ORIGEM_CONTRATO,
            ]);
        }

        $this->artisan('fechamento:normalizar-tetos-contrato --aplicar')
            ->expectsOutputToContain('MAXIGOLD EXEMPLO')
            ->assertSuccessful();

        $linhas = DB::table('empresa_faixas_faturamento')
            ->where('company_id', $company->id)->orderBy('ordem')->get();

        $this->assertSame(48000.00, (float) $linhas[0]->limite_superior);
        $this->assertSame(25000.00, (float) $linhas[1]->limite_superior);
        $this->assertSame(0, DB::table('activity_log')->where('log_name', 'faixa_faturamento_tabela')->count());
    }

    #[Test]
    public function rodar_duas_vezes_nao_tira_outro_centavo(): void
    {
        $company = Company::factory()->create();
        $this->tabelaDeContratoForaDaConvencao($company);

        $this->artisan('fechamento:normalizar-tetos-contrato --aplicar')->assertSuccessful();
        $this->artisan('fechamento:normalizar-tetos-contrato --aplicar')->assertSuccessful();

        $this->assertSame(
            499999.99,
            (float) DB::table('empresa_faixas_faturamento')
                ->where('company_id', $company->id)->where('ordem', 1)->value('limite_superior')
        );
        // A segunda rodada não tinha nada a fazer — só uma entrada de auditoria ao todo.
        $this->assertSame(1, DB::table('activity_log')->where('log_name', 'faixa_faturamento_tabela')->count());
    }
}
