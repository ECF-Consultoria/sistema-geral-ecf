<?php

namespace Tests\Feature\Phase126;

use App\Models\ContratoAssinatura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 126 (D-03/D-04). Prova ponta a ponta das duas colunas novas de
 * `contrato_assinaturas` (`pdf_path`, `pdf_assinado_path`) e dos dois novos
 * states da `ContratoAssinaturaFactory` que os planos do PDF (126-04/05)
 * vão consumir.
 */
class ContratoAssinaturaPdfPathsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function as_duas_colunas_existem_apos_migrar(): void
    {
        $this->assertTrue(
            Schema::hasColumn('contrato_assinaturas', 'pdf_path'),
            'Coluna `pdf_path` não existe após migrar — a migration da Fase 126 (D-03) não rodou como esperado.'
        );

        $this->assertTrue(
            Schema::hasColumn('contrato_assinaturas', 'pdf_assinado_path'),
            'Coluna `pdf_assinado_path` não existe após migrar — a migration da Fase 126 (D-03) não rodou como esperado.'
        );
    }

    #[Test]
    public function pdf_path_e_pdf_assinado_path_persistem_via_mass_assignment(): void
    {
        $contrato = ContratoAssinatura::factory()->create([
            'pdf_path'          => 'contratos/2026/08/empresa-1.pdf',
            'pdf_assinado_path' => 'contratos/assinados/2026/08/empresa-1.pdf',
        ]);

        $contrato->refresh();

        $this->assertSame(
            'contratos/2026/08/empresa-1.pdf',
            $contrato->pdf_path,
            'O mass assignment de `pdf_path` não persistiu — confira se a coluna está no $fillable de ContratoAssinatura (Fase 126, D-03).'
        );

        $this->assertSame(
            'contratos/assinados/2026/08/empresa-1.pdf',
            $contrato->pdf_assinado_path,
            'O mass assignment de `pdf_assinado_path` não persistiu — confira se a coluna está no $fillable de ContratoAssinatura (Fase 126, D-03).'
        );
    }

    #[Test]
    public function comsnapshot_devolve_array_com_pelo_menos_dois_servicos_no_formato_esperado(): void
    {
        $contrato = ContratoAssinatura::factory()->comSnapshot()->create();

        $contrato->refresh();

        $snapshot = $contrato->servicos_snapshot;

        $this->assertIsArray(
            $snapshot,
            'servicos_snapshot não voltou como array — o cast `array` do model deveria cobrir isto.'
        );

        $this->assertGreaterThanOrEqual(
            2,
            count($snapshot),
            'O state comSnapshot() precisa produzir pelo menos 2 serviços (régua do Success Criteria 3 — forma e volume de dado real).'
        );

        foreach ($snapshot as $servico) {
            $this->assertArrayHasKey('servico', $servico, 'Cada item do snapshot precisa ter a chave `servico` (nome legível).');
            $this->assertArrayHasKey('valor_contratado', $servico, 'Cada item do snapshot precisa ter a chave `valor_contratado`.');
            $this->assertArrayHasKey('data_contratacao', $servico, 'Cada item do snapshot precisa ter a chave `data_contratacao`.');
            $this->assertArrayHasKey('data_vencimento', $servico, 'Cada item do snapshot precisa ter a chave `data_vencimento`.');

            $this->assertNotEquals(
                0,
                fmod((float) $servico['valor_contratado'], 1.0),
                "O valor `{$servico['valor_contratado']}` de `{$servico['servico']}` é redondo — o state precisa de valores COM CENTAVOS para não mascarar bug de arredondamento no PDF."
            );

            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}$/',
                $servico['data_contratacao'],
                'data_contratacao precisa estar no formato Y-m-d, mesma forma de ContratoServico::$casts.'
            );

            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}$/',
                $servico['data_vencimento'],
                'data_vencimento precisa estar no formato Y-m-d, mesma forma de ContratoServico::$casts.'
            );
        }
    }

    #[Test]
    public function comempresadenomeextremo_produz_nome_com_mais_de_80_caracteres_e_acentuacao_preservada(): void
    {
        $contrato = ContratoAssinatura::factory()->comEmpresaDeNomeExtremo()->create();

        $contrato->refresh();

        $nome = $contrato->company->name;

        $this->assertGreaterThan(
            80,
            mb_strlen($nome),
            "O nome de empresa extremo tem apenas " . mb_strlen($nome) . ' caracteres — o teste de PDF-03 (plano 126-05) precisa de 80+.'
        );

        $this->assertMatchesRegularExpression(
            '/[ÇÃçã]/u',
            $nome,
            'O nome de empresa extremo perdeu a acentuação esperada (Ç/Ã) — confira a codificação do state comEmpresaDeNomeExtremo().'
        );

        $this->assertStringContainsString(
            '-',
            $nome,
            'O nome de empresa extremo perdeu o caractere especial (travessão/hífen) esperado.'
        );
    }
}
