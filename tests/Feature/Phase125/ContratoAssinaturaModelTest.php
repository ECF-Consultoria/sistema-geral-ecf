<?php

namespace Tests\Feature\Phase125;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Prova dos 7 estados, do cast array de `servicos_snapshot` (D-10) e do
 * guard de código da D-01 (Fase 125, DADOS-01, DADOS-04).
 */
class ContratoAssinaturaModelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function factory_cria_contrato_valido_sem_argumento(): void
    {
        $contrato = ContratoAssinatura::factory()->create();

        $this->assertNotNull($contrato->company_id);
        $this->assertSame(ContratoAssinatura::STATUS_RASCUNHO, $contrato->status);
    }

    #[Test]
    public function os_sete_estados_persistem_e_voltam_iguais(): void
    {
        $this->assertCount(7, ContratoAssinatura::STATUS_TODOS);

        foreach (ContratoAssinatura::STATUS_TODOS as $status) {
            $contrato = ContratoAssinatura::factory()->create(['status' => $status]);

            $this->assertSame($status, $contrato->fresh()->status);
        }
    }

    #[Test]
    public function recusado_e_expirado_nunca_resolvem_para_cancelado_nem_erro(): void
    {
        $this->assertNotSame(ContratoAssinatura::STATUS_RECUSADO, ContratoAssinatura::STATUS_CANCELADO);
        $this->assertNotSame(ContratoAssinatura::STATUS_RECUSADO, ContratoAssinatura::STATUS_ERRO);
        $this->assertNotSame(ContratoAssinatura::STATUS_EXPIRADO, ContratoAssinatura::STATUS_CANCELADO);
        $this->assertNotSame(ContratoAssinatura::STATUS_EXPIRADO, ContratoAssinatura::STATUS_ERRO);

        $recusado  = ContratoAssinatura::factory()->create(['status' => ContratoAssinatura::STATUS_RECUSADO]);
        $expirado  = ContratoAssinatura::factory()->create(['status' => ContratoAssinatura::STATUS_EXPIRADO]);
        $cancelado = ContratoAssinatura::factory()->create(['status' => ContratoAssinatura::STATUS_CANCELADO]);
        $erro      = ContratoAssinatura::factory()->create(['status' => ContratoAssinatura::STATUS_ERRO]);

        $valores = [
            $recusado->fresh()->status,
            $expirado->fresh()->status,
            $cancelado->fresh()->status,
            $erro->fresh()->status,
        ];

        $this->assertSame(4, count(array_unique($valores)));
    }

    #[Test]
    public function round_trip_de_servicos_snapshot_via_cast_array(): void
    {
        // Valores com casa decimal não-inteira de propósito: um float "redondo"
        // (ex.: 1500.00) perde a marca decimal no round-trip json_encode/decode
        // e volta como int — não é bug do cast, é limitação conhecida de PHP.
        $payload = [
            ['servico' => 'Performance ML', 'valor_mensal' => 1500.75, 'servico_id' => 3],
            ['servico' => 'Shopee', 'valor_mensal' => 900.50, 'servico_id' => 7],
        ];

        $contrato = ContratoAssinatura::factory()->create(['servicos_snapshot' => $payload]);

        $this->assertSame($payload, $contrato->fresh()->servicos_snapshot);
    }

    #[Test]
    public function empresa_nao_pode_ter_dois_contratos_em_andamento(): void
    {
        $company = Company::factory()->create();

        ContratoAssinatura::factory()->emAndamento()->create(['company_id' => $company->id]);

        $this->expectException(QueryException::class);

        ContratoAssinatura::factory()->emAndamento()->create(['company_id' => $company->id]);
    }

    #[Test]
    public function empresa_pode_ter_novo_contrato_depois_de_um_encerrado(): void
    {
        $company = Company::factory()->create();

        $primeiro = ContratoAssinatura::factory()->emAndamento()->create(['company_id' => $company->id]);
        $primeiro->status = ContratoAssinatura::STATUS_RECUSADO;
        $primeiro->save();

        ContratoAssinatura::factory()->emAndamento()->create(['company_id' => $company->id]);

        $this->assertSame(2, $company->contratoAssinaturas()->count());
        $this->assertNull($primeiro->fresh()->company_id_em_andamento);
    }

    #[Test]
    public function guard_de_codigo_encontra_o_contrato_em_andamento(): void
    {
        $company        = Company::factory()->create();
        $outraEmpresa   = Company::factory()->create();

        $this->assertNull(ContratoAssinatura::emAndamentoDaEmpresa($outraEmpresa->id));

        $contrato = ContratoAssinatura::factory()->emAndamento()->create(['company_id' => $company->id]);

        $encontrado = ContratoAssinatura::emAndamentoDaEmpresa($company->id);

        $this->assertNotNull($encontrado);
        $this->assertSame($contrato->id, $encontrado->id);
    }

    #[Test]
    public function company_expoe_os_contratos_de_assinatura(): void
    {
        $company = Company::factory()->create();

        // D-01 barra dois contratos EM ANDAMENTO da mesma empresa — o
        // segundo aqui precisa ser de um status encerrado para não colidir
        // com o índice único da Task 1.
        ContratoAssinatura::factory()->create(['company_id' => $company->id]);
        ContratoAssinatura::factory()->create([
            'company_id' => $company->id,
            'status'     => ContratoAssinatura::STATUS_CANCELADO,
        ]);

        $this->assertCount(2, $company->contratoAssinaturas);
    }
}
