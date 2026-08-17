<?php

namespace Tests\Feature\Phase130;

use App\Models\Company;
use App\Models\ContratoLiberacao;
use App\Models\Servico;
use App\Models\User;
use App\Services\Operacional\EmpresaOperacionalRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 130 Plano 01 (Task 1) — prova a fundação da terceira via de
 * liberação (`reconciliacao`, D-07) e da lista fechada de motivos da
 * liberação manual (D-12). Fundação apenas: nenhuma lógica de negócio nova
 * é exercitada aqui, só schema + constantes + o parâmetro `motivoSlug`
 * gravando slug e detalhe em colunas separadas.
 */
class FundacaoContratoLiberacaoTest extends TestCase
{
    use RefreshDatabase;

    private function servico(string $nome): Servico
    {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);
    }

    #[Test]
    public function via_todas_contem_exatamente_as_tres_vias(): void
    {
        $this->assertSame(
            ['webhook', 'manual', 'reconciliacao'],
            ContratoLiberacao::VIA_TODAS
        );
    }

    #[Test]
    public function motivos_manuais_tem_quatro_slugs_com_label_cada(): void
    {
        $this->assertCount(4, ContratoLiberacao::MOTIVOS_MANUAIS);

        foreach (ContratoLiberacao::MOTIVOS_MANUAIS as $slug) {
            $this->assertArrayHasKey(
                $slug,
                ContratoLiberacao::MOTIVOS_MANUAIS_LABELS,
                "O slug {$slug} precisa ter um rótulo em MOTIVOS_MANUAIS_LABELS"
            );
        }
    }

    #[Test]
    public function coluna_motivo_slug_existe_na_tabela(): void
    {
        $this->assertTrue(Schema::hasColumn('contrato_liberacoes', 'motivo_slug'));
    }

    #[Test]
    public function liberacao_manual_grava_via_autor_motivo_e_motivo_slug(): void
    {
        $company = Company::factory()->create();
        $servico = $this->servico('Assessoria');
        $user    = User::factory()->create();

        app(EmpresaOperacionalRouter::class)->liberarEmpresa(
            $company,
            $servico,
            ContratoLiberacao::VIA_MANUAL,
            liberadoPorUserId: $user->id,
            motivo: 'detalhe X',
            motivoSlug: ContratoLiberacao::MOTIVO_WEBHOOK_NAO_CHEGOU,
        );

        $liberacao = ContratoLiberacao::where('company_id', $company->id)
            ->where('servico_id', $servico->id)
            ->first();

        $this->assertNotNull($liberacao);
        $this->assertSame(ContratoLiberacao::VIA_MANUAL, $liberacao->via);
        $this->assertSame($user->id, $liberacao->liberado_por_user_id);
        $this->assertSame('detalhe X', $liberacao->motivo);
        $this->assertSame(ContratoLiberacao::MOTIVO_WEBHOOK_NAO_CHEGOU, $liberacao->motivo_slug);
    }

    #[Test]
    public function liberacao_via_reconciliacao_grava_via_correta_e_motivo_slug_nulo(): void
    {
        $company = Company::factory()->create();
        $servico = $this->servico('Polos');

        app(EmpresaOperacionalRouter::class)->liberarEmpresa(
            $company,
            $servico,
            ContratoLiberacao::VIA_RECONCILIACAO,
        );

        $liberacao = ContratoLiberacao::where('company_id', $company->id)
            ->where('servico_id', $servico->id)
            ->first();

        $this->assertNotNull($liberacao);
        $this->assertSame('reconciliacao', $liberacao->via);
        $this->assertNull($liberacao->motivo_slug);
    }
}
