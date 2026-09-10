<?php

namespace Tests\Feature\Quick260910;

use App\Models\Company;
use App\Models\ContratoTabelaProposta;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 260910-l7k — a confirmação da leitura do contrato normaliza o teto e valida a tabela
 * ANTES de gravar.
 *
 * Duas coisas medidas em produção em 2026-09-10 motivam este arquivo: 75 tetos gravados com ",00"
 * (fora da convenção da casa) e duas tabelas malformadas que a validação do cadastro manual teria
 * recusado — mas que entraram porque a confirmação não validava nada.
 *
 * Toda asserção de persistência é por RECONSULTA ao banco (`DB::table`) — mesma disciplina de
 * `Phase137FaixasCrudTest` e `Phase140ConfirmacaoTabelaTest`.
 */
class ConfirmacaoTetoEValidacaoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    // ── Normalização do teto ────────────────────────────────────────────

    #[Test]
    public function teto_lido_do_contrato_com_00_e_gravado_na_convencao_da_casa(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();

        $proposta = ContratoTabelaProposta::factory()->create([
            'tipo_cobranca' => ContratoTabelaProposta::TIPO_TABELA,
            'faixas'        => [
                ['ordem' => 1, 'limite_superior' => 500_000.00, 'valor' => 3_000.00, 'valor_e_piso' => false],
                ['ordem' => 2, 'limite_superior' => 1_000_000.00, 'valor' => 4_000.00, 'valor_e_piso' => false],
                ['ordem' => 3, 'limite_superior' => null, 'valor' => 5_000.00, 'valor_e_piso' => true],
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id])
            ->assertSessionHas('success');

        $linhas = DB::table('empresa_faixas_faturamento')
            ->where('company_id', $company->id)->orderBy('ordem')->get();

        $this->assertCount(3, $linhas);
        $this->assertSame(499999.99, (float) $linhas[0]->limite_superior);
        $this->assertSame(999999.99, (float) $linhas[1]->limite_superior);
        $this->assertNull($linhas[2]->limite_superior);

        // Só o teto muda — valor e "valor é piso" passam intactos.
        $this->assertSame(3000.00, (float) $linhas[0]->valor);
        $this->assertSame(5000.00, (float) $linhas[2]->valor);
        $this->assertTrue((bool) $linhas[2]->valor_e_piso);

        $this->assertSame(
            ContratoTabelaProposta::SITUACAO_CONFIRMADA,
            DB::table('contrato_tabela_propostas')->where('id', $proposta->id)->value('situacao')
        );
    }

    #[Test]
    public function teto_que_ja_veio_na_convencao_nao_perde_outro_centavo(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();

        $proposta = ContratoTabelaProposta::factory()->create([
            'tipo_cobranca' => ContratoTabelaProposta::TIPO_TABELA,
            'faixas'        => [
                ['ordem' => 1, 'limite_superior' => 499_999.99, 'valor' => 3_000.00, 'valor_e_piso' => false],
                ['ordem' => 2, 'limite_superior' => null, 'valor' => 5_000.00, 'valor_e_piso' => true],
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id]);

        $this->assertSame(
            499999.99,
            (float) DB::table('empresa_faixas_faturamento')
                ->where('company_id', $company->id)->where('ordem', 1)->value('limite_superior')
        );
    }

    // ── Validação antes de gravar ───────────────────────────────────────

    #[Test]
    public function tabela_com_teto_fora_de_ordem_nao_grava_nada_e_a_proposta_segue_pendente(): void
    {
        // A forma da MAXIGOLD (#234), medida em produção: penúltima linha com teto MENOR que a
        // anterior e a faixa aberta com o MENOR preço da tabela.
        $admin   = $this->admin();
        $company = Company::factory()->create();

        $proposta = ContratoTabelaProposta::factory()->create([
            'tipo_cobranca' => ContratoTabelaProposta::TIPO_TABELA,
            'faixas'        => [
                ['ordem' => 1, 'limite_superior' => 48_000.00, 'valor' => 1_500.00, 'valor_e_piso' => false],
                ['ordem' => 2, 'limite_superior' => 25_000.00, 'valor' => 2_000.00, 'valor_e_piso' => false],
                ['ordem' => 3, 'limite_superior' => null, 'valor' => 4_000.00, 'valor_e_piso' => true],
            ],
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('empresa_faixas_faturamento')->count());
        $this->assertSame(
            ContratoTabelaProposta::SITUACAO_PENDENTE,
            DB::table('contrato_tabela_propostas')->where('id', $proposta->id)->value('situacao')
        );
        $this->assertSame(0, DB::table('activity_log')->where('log_name', 'faixa_faturamento_tabela')->count());
    }

    #[Test]
    public function tabela_malformada_nao_derruba_a_tabela_que_a_empresa_ja_tinha(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();

        EmpresaFaixaFaturamento::create([
            'company_id'      => $company->id,
            'ordem'           => 1,
            'limite_superior' => null,
            'valor'           => 7_000.00,
            'valor_e_piso'    => true,
            'origem'          => EmpresaFaixaFaturamento::ORIGEM_MANUAL,
        ]);

        $proposta = ContratoTabelaProposta::factory()->create([
            'tipo_cobranca' => ContratoTabelaProposta::TIPO_TABELA,
            'faixas'        => [
                ['ordem' => 1, 'limite_superior' => 36_000.00, 'valor' => 1_200.00, 'valor_e_piso' => false],
                ['ordem' => 2, 'limite_superior' => 12_000.00, 'valor' => 1_500.00, 'valor_e_piso' => false],
                ['ordem' => 3, 'limite_superior' => null, 'valor' => 3_000.00, 'valor_e_piso' => true],
            ],
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id])
            ->assertStatus(422);

        $linhas = DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->get();
        $this->assertCount(1, $linhas);
        $this->assertSame(7000.00, (float) $linhas[0]->valor);
    }

    #[Test]
    public function tabela_com_duas_faixas_sem_teto_e_recusada(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();

        $proposta = ContratoTabelaProposta::factory()->create([
            'tipo_cobranca' => ContratoTabelaProposta::TIPO_TABELA,
            'faixas'        => [
                ['ordem' => 1, 'limite_superior' => null, 'valor' => 1_000.00, 'valor_e_piso' => true],
                ['ordem' => 2, 'limite_superior' => null, 'valor' => 2_000.00, 'valor_e_piso' => true],
            ],
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('empresa_faixas_faturamento')->count());
    }

    #[Test]
    public function tabela_sem_faixa_nenhuma_e_recusada_sem_estourar(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();

        $proposta = ContratoTabelaProposta::factory()->create([
            'tipo_cobranca' => ContratoTabelaProposta::TIPO_TABELA,
            'faixas'        => [],
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('empresa_faixas_faturamento')->count());
        $this->assertSame(
            ContratoTabelaProposta::SITUACAO_PENDENTE,
            DB::table('contrato_tabela_propostas')->where('id', $proposta->id)->value('situacao')
        );
    }

    // ── Ramos que não têm faixa continuam iguais ────────────────────────

    #[Test]
    public function confirmar_valor_fixo_continua_sem_faixa_e_sem_recusa(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();

        $proposta = ContratoTabelaProposta::factory()->valorFixo()->create();

        $this->actingAs($admin)
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id])
            ->assertSessionHas('success');

        $this->assertSame(0, DB::table('empresa_faixas_faturamento')->count());
        $this->assertSame(
            ContratoTabelaProposta::SITUACAO_CONFIRMADA,
            DB::table('contrato_tabela_propostas')->where('id', $proposta->id)->value('situacao')
        );
    }
}
