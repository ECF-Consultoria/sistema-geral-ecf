<?php

namespace Tests\Feature\Phase140;

use App\Models\Company;
use App\Models\ContratoTabelaProposta;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 140 Plano 04 (TAB-07) — schema de `contrato_tabela_propostas`: o que a leitura automática
 * do Clicksign guarda para conferência humana. Cobre as seis verdades do `must_haves.truths` do
 * plano: unique do envelope, `company_id` sobrevive à empresa apagada, casts, auditoria e a
 * situação nascendo `pendente`.
 *
 * Conferência sempre por reconsulta ao banco (`DB::table`), nunca pelo retorno do save/create.
 */
class Phase140PropostaSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_tabela_existe_com_as_colunas_declaradas(): void
    {
        $this->assertTrue(Schema::hasTable('contrato_tabela_propostas'));

        foreach ([
            'id', 'clicksign_envelope_id', 'nome_envelope', 'envelope_situacao', 'envelope_data',
            'company_id', 'confianca', 'pontuacao', 'ambiguo', 'candidatos',
            'tipo_cobranca', 'valor_fixo', 'faixas', 'cnpj_lido', 'razao_social_lida', 'motivo',
            'situacao', 'confirmado_por', 'confirmado_em', 'created_at', 'updated_at',
        ] as $coluna) {
            $this->assertTrue(
                Schema::hasColumn('contrato_tabela_propostas', $coluna),
                "contrato_tabela_propostas deve ter a coluna {$coluna}."
            );
        }
    }

    #[Test]
    public function o_mesmo_envelope_nao_vira_duas_propostas(): void
    {
        ContratoTabelaProposta::factory()->create(['clicksign_envelope_id' => 'env-duplicado']);

        $this->expectException(QueryException::class);

        DB::table('contrato_tabela_propostas')->insert([
            'clicksign_envelope_id' => 'env-duplicado',
            'nome_envelope'         => 'Segunda tentativa',
            'envelope_situacao'     => 'closed',
            'confianca'             => ContratoTabelaProposta::CONFIANCA_INCERTO,
            'tipo_cobranca'         => ContratoTabelaProposta::TIPO_INDEFINIDO,
            'situacao'              => ContratoTabelaProposta::SITUACAO_PENDENTE,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
    }

    #[Test]
    public function apagar_a_empresa_deixa_a_proposta_viva_com_company_id_nulo(): void
    {
        $company = Company::factory()->create();

        $proposta = ContratoTabelaProposta::factory()->create(['company_id' => $company->id]);

        $company->delete();

        // Reconsulta direta ao banco — nunca confiar no model já carregado em memória.
        $linha = DB::table('contrato_tabela_propostas')->where('id', $proposta->id)->first();

        $this->assertNotNull($linha, 'A proposta não pode sumir quando a empresa é apagada.');
        $this->assertNull($linha->company_id);
    }

    #[Test]
    public function faixas_e_candidatos_voltam_como_array_php_ja_decodificado(): void
    {
        $proposta = ContratoTabelaProposta::factory()->create([
            'faixas'     => [['ordem' => 1, 'limite_superior' => 500000.00, 'valor' => 3000.00, 'valor_e_piso' => false]],
            'candidatos' => [['company_id' => 1, 'nome' => 'Empresa X', 'pontuacao' => 61.0]],
        ]);

        $proposta->refresh();

        $this->assertIsArray($proposta->faixas);
        $this->assertSame(1, $proposta->faixas[0]['ordem']);
        $this->assertIsArray($proposta->candidatos);
        $this->assertSame('Empresa X', $proposta->candidatos[0]['nome']);
    }

    #[Test]
    public function a_situacao_nasce_pendente(): void
    {
        $proposta = ContratoTabelaProposta::create([
            'clicksign_envelope_id' => 'env-nascimento',
            'nome_envelope'         => 'Contrato de teste',
            'envelope_situacao'     => 'closed',
            'confianca'             => ContratoTabelaProposta::CONFIANCA_INCERTO,
            'tipo_cobranca'         => ContratoTabelaProposta::TIPO_INDEFINIDO,
        ]);

        // Reconsulta ao banco — nunca confiar só no atributo em memória.
        $linha = DB::table('contrato_tabela_propostas')->where('id', $proposta->id)->first();

        $this->assertSame(ContratoTabelaProposta::SITUACAO_PENDENTE, $linha->situacao);
    }

    #[Test]
    public function alterar_uma_proposta_gera_registro_no_log_de_atividade(): void
    {
        $proposta = ContratoTabelaProposta::factory()->create();

        $proposta->update(['situacao' => ContratoTabelaProposta::SITUACAO_CONFIRMADA]);

        $logs = DB::table('activity_log')
            ->where('log_name', 'tabela_proposta')
            ->where('subject_type', ContratoTabelaProposta::class)
            ->where('subject_id', $proposta->id)
            ->orderBy('id')
            ->get();

        $this->assertGreaterThanOrEqual(2, $logs->count(), 'Deve haver pelo menos 1 log de created e 1 de updated.');
        $this->assertContains('created', $logs->pluck('event')->all());
        $this->assertContains('updated', $logs->pluck('event')->all());
    }

    #[Test]
    public function relacoes_company_e_confirmado_por_funcionam(): void
    {
        $company = Company::factory()->create();
        $user    = User::factory()->create();

        $proposta = ContratoTabelaProposta::factory()->create([
            'company_id'     => $company->id,
            'situacao'       => ContratoTabelaProposta::SITUACAO_CONFIRMADA,
            'confirmado_por' => $user->id,
            'confirmado_em'  => now(),
        ]);

        $this->assertTrue($proposta->company->is($company));
        $this->assertTrue($proposta->confirmadoPor->is($user));
    }

    #[Test]
    public function scope_pendentes_so_devolve_situacao_pendente(): void
    {
        ContratoTabelaProposta::factory()->create(['situacao' => ContratoTabelaProposta::SITUACAO_PENDENTE]);
        ContratoTabelaProposta::factory()->confirmada()->create();

        $this->assertSame(1, ContratoTabelaProposta::pendentes()->count());
    }
}
