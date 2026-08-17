<?php

namespace Tests\Feature\Phase125;

use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Prova de schema da tabela `contrato_assinaturas` (Fase 125, DADOS-01,
 * DADOS-04) — colunas, índices nomeados à mão e a garantia de unicidade da
 * D-01. Este arquivo é schema puro: usa `Schema`/`DB::table()` e
 * `Company::factory()`, e não referencia o model `ContratoAssinatura` (ele
 * só nasce na Task 2).
 */
class ContratoAssinaturaSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function tabela_existe_com_todas_as_colunas_esperadas(): void
    {
        $this->assertTrue(Schema::hasTable('contrato_assinaturas'));

        $this->assertTrue(Schema::hasColumns('contrato_assinaturas', [
            'id',
            'company_id',
            'status',
            'company_id_em_andamento',
            'clicksign_envelope_id',
            'clicksign_document_id',
            'enviado_em',
            'assinado_em',
            'liberado_em',
            'erro_mensagem',
            'servicos_snapshot',
            'created_at',
            'updated_at',
        ]));
    }

    #[Test]
    public function indices_tem_nome_explicito_e_cabem_em_64_caracteres(): void
    {
        $indices = Schema::getIndexes('contrato_assinaturas');
        $nomes   = array_map(fn (array $idx) => $idx['name'], $indices);

        // Fase 127-01 (D-06): `ca_company_andamento_uniq` (1 coluna) foi
        // substituído por `ca_empresa_servico_andamento_uniq` (chave
        // composta empresa+serviço) — a Task 2 da Fase 127-01 dropa
        // exatamente o índice antigo. As outras duas asserções e o laço de
        // 64 caracteres (armadilha 1059) seguem intactos.
        $this->assertContains('ca_empresa_servico_andamento_uniq', $nomes);
        $this->assertContains('ca_clicksign_envelope_uniq', $nomes);
        $this->assertContains('ca_company_status_idx', $nomes);

        foreach ($nomes as $nome) {
            $this->assertLessThanOrEqual(64, strlen($nome), "Índice '{$nome}' estoura 64 caracteres (armadilha 1059).");
        }
    }

    /**
     * Fase 127-01 (D-06, 12/08/2026): renomeado de
     * `indice_unico_barra_segundo_contrato_em_andamento`. A trava deixou de
     * ser por EMPRESA e passou a ser por (EMPRESA + SERVIÇO) — este teste
     * não é afrouxamento: prova que a trava composta barra dois contratos
     * do MESMO serviço, exercitando a coluna nova (`servico_id_em_andamento`)
     * também via `DB::table()->insert()` cru, de propósito — o valor deste
     * teste é provar que a trava é do BANCO, não do hook do model.
     */
    #[Test]
    public function indice_unico_barra_segundo_contrato_do_mesmo_servico(): void
    {
        $company = Company::factory()->create();
        $servico = \App\Models\Servico::query()->value('id');

        DB::table('contrato_assinaturas')->insert([
            'company_id'               => $company->id,
            'servico_id'               => $servico,
            'status'                   => 'aguardando_assinaturas',
            'company_id_em_andamento'  => $company->id,
            'servico_id_em_andamento'  => $servico,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('contrato_assinaturas')->insert([
            'company_id'               => $company->id,
            'servico_id'               => $servico,
            'status'                   => 'rascunho',
            'company_id_em_andamento'  => $company->id,
            'servico_id_em_andamento'  => $servico,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);
    }

    /**
     * Fase 127-01 (D-06, 12/08/2026): prova executável de que a D-06
     * permite N contratos por empresa (um por serviço) — o objetivo
     * inteiro desta migration. Mesmo `company_id_em_andamento`, mas
     * `servico_id_em_andamento` DIFERENTE nos dois inserts: nenhuma
     * exceção, os dois passam.
     */
    #[Test]
    public function dois_contratos_da_mesma_empresa_em_servicos_diferentes_convivem_em_andamento(): void
    {
        $company = Company::factory()->create();
        $servicos = \App\Models\Servico::query()->orderBy('id')->take(2)->pluck('id')->all();

        if (count($servicos) < 2) {
            $setorExistente = \App\Models\Servico::query()->value('setor') ?? \App\Models\Servico::SETOR_OUTROS;

            while (count($servicos) < 2) {
                $servicos[] = \App\Models\Servico::create([
                    'nome'          => 'Serviço de teste '.uniqid(),
                    'valor_padrao'  => 0,
                    'tipo_cobranca' => \App\Models\Servico::TIPO_MENSAL,
                    'ativo'         => true,
                    'setor'         => $setorExistente,
                ])->id;
            }
        }

        [$servicoA, $servicoB] = $servicos;

        DB::table('contrato_assinaturas')->insert([
            'company_id'               => $company->id,
            'servico_id'               => $servicoA,
            'status'                   => 'rascunho',
            'company_id_em_andamento'  => $company->id,
            'servico_id_em_andamento'  => $servicoA,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        DB::table('contrato_assinaturas')->insert([
            'company_id'               => $company->id,
            'servico_id'               => $servicoB,
            'status'                   => 'rascunho',
            'company_id_em_andamento'  => $company->id,
            'servico_id_em_andamento'  => $servicoB,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $this->assertSame(2, DB::table('contrato_assinaturas')
            ->where('company_id', $company->id)
            ->count());
    }

    #[Test]
    public function varios_contratos_encerrados_da_mesma_empresa_convivem(): void
    {
        $company = Company::factory()->create();

        foreach (['recusado', 'expirado', 'cancelado'] as $status) {
            DB::table('contrato_assinaturas')->insert([
                'company_id'               => $company->id,
                'status'                   => $status,
                'company_id_em_andamento'  => null,
                'created_at'               => now(),
                'updated_at'               => now(),
            ]);
        }

        $this->assertSame(3, DB::table('contrato_assinaturas')
            ->where('company_id', $company->id)
            ->count());
    }

    #[Test]
    public function status_aceita_string_arbitraria_sem_tipo_restrito(): void
    {
        $company = Company::factory()->create();

        DB::table('contrato_assinaturas')->insert([
            'company_id' => $company->id,
            'status'     => 'um_status_qualquer_nao_previsto',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('contrato_assinaturas')->where('company_id', $company->id)->first();

        $this->assertSame('um_status_qualquer_nao_previsto', $row->status);
    }
}
