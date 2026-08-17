<?php

namespace Tests\Feature\Phase129;

use App\Models\Company;
use App\Models\ContratoLiberacao;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 129 Plano 04 (Task 1) — prova o schema de `contrato_liberacoes`:
 * colunas presentes, unicidade por (company_id, servico_id) via SQLSTATE
 * 23000 (nunca `errorInfo`), e que apagar o `User` que liberou não apaga a
 * liberação (D-05, FK `nullOnDelete`).
 */
class ContratoLiberacaoSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function servico(array $overrides = []): Servico
    {
        return Servico::create(array_merge([
            'nome'          => 'Servico de teste '.uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ], $overrides));
    }

    #[Test]
    public function tabela_tem_as_colunas_esperadas(): void
    {
        $colunas = [
            'id', 'company_id', 'servico_id', 'contrato_assinatura_id', 'via',
            'liberado_por_user_id', 'motivo', 'gerou_ficha', 'liberado_em',
            'created_at', 'updated_at',
        ];

        foreach ($colunas as $coluna) {
            $this->assertTrue(
                Schema::hasColumn('contrato_liberacoes', $coluna),
                "Coluna esperada ausente: {$coluna}"
            );
        }
    }

    #[Test]
    public function duas_liberacoes_para_o_mesmo_par_empresa_servico_violam_a_constraint_unica(): void
    {
        $company = Company::factory()->create();
        $servico = $this->servico();

        ContratoLiberacao::create([
            'company_id'  => $company->id,
            'servico_id'  => $servico->id,
            'via'         => ContratoLiberacao::VIA_WEBHOOK,
            'liberado_em' => now(),
        ]);

        $this->expectException(QueryException::class);

        try {
            ContratoLiberacao::create([
                'company_id'  => $company->id,
                'servico_id'  => $servico->id,
                'via'         => ContratoLiberacao::VIA_WEBHOOK,
                'liberado_em' => now(),
            ]);
        } catch (QueryException $e) {
            $this->assertSame('23000', (string) $e->getCode());
            throw $e;
        }
    }

    #[Test]
    public function apagar_o_user_deixa_a_liberacao_viva_com_liberado_por_nulo(): void
    {
        $company = Company::factory()->create();
        $servico = $this->servico();
        $user    = User::factory()->create();

        $liberacao = ContratoLiberacao::create([
            'company_id'            => $company->id,
            'servico_id'            => $servico->id,
            'via'                   => ContratoLiberacao::VIA_MANUAL,
            'liberado_por_user_id'  => $user->id,
            'motivo'                => 'Cliente confirmou por telefone.',
            'liberado_em'           => now(),
        ]);

        // forceDelete(), não delete(): User usa SoftDeletes (deleted_at) —
        // um delete() comum só marca a coluna e a FK `nullOnDelete` nunca
        // dispara, porque a linha continua existindo na tabela `users`.
        // Precedente: Phase125/ContratoAssinaturaSignatarioModelTest.php.
        $user->forceDelete();

        $liberacao->refresh();

        $this->assertNull($liberacao->liberado_por_user_id);
        $this->assertNotNull(ContratoLiberacao::find($liberacao->id));
    }
}
