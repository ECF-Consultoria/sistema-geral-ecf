<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 111 Plan 03 — HUB-SCHEMA-01 / HUB-SCHEMA-02.
 *
 * Estratégia TDD: RED antes das migrations das Tasks 2/3 (as colunas ainda
 * não existem, então os 3 métodos abaixo FALHAM por coluna/tabela ausente).
 * Isso evita o falso-verde de `php artisan test --filter=<classe-inexistente>`
 * (que imprime "No tests found." e sai com exit code 0 sem provar nada).
 *
 * Após as Tasks 2/3 (migrations + $fillable/casts em Company/ContratoServico),
 * a suite fica 100% GREEN — colunas nascem nullable e SEM uso no fluxo legado.
 */
class Phase111HubspotSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_companies_tem_colunas_hubspot(): void
    {
        $colunas = [
            'hubspot_deal_id',
            'hubspot_company_id',
            'hubspot_contact_id',
            'nome_contato',
            'cargo_contato',
            'hubspot_domain',
            'hubspot_observacao',
            'hubspot_snapshot',
        ];

        foreach ($colunas as $coluna) {
            $this->assertTrue(
                Schema::hasColumn('companies', $coluna),
                "companies deveria ter a coluna {$coluna}"
            );
        }

        $company = Company::create([
            'name' => 'Empresa Teste HubSpot',
            'hubspot_deal_id' => 'deal_123',
            'hubspot_snapshot' => ['deal' => ['amount' => '36000']],
        ]);

        $company->refresh();

        $this->assertSame('deal_123', $company->hubspot_deal_id);
        $this->assertIsArray($company->hubspot_snapshot);
        $this->assertSame('36000', $company->hubspot_snapshot['deal']['amount']);
    }

    public function test_contratos_tem_colunas_hubspot(): void
    {
        $colunas = [
            'hubspot_line_item_id',
            'hubspot_product_id',
            'hubspot_billing_frequency',
            'hubspot_billing_period',
            'hubspot_currency',
            'hubspot_valor_original',
            'hubspot_valor_original_tipo',
            'hubspot_valor_normalizado_mensal',
            'hubspot_valor_confidence',
            'hubspot_valor_warning',
            'hubspot_snapshot',
        ];

        foreach ($colunas as $coluna) {
            $this->assertTrue(
                Schema::hasColumn('contratos_servico', $coluna),
                "contratos_servico deveria ter a coluna {$coluna}"
            );
        }

        $company = Company::create(['name' => 'Empresa Contrato Teste']);
        $servico = Servico::create([
            'nome' => 'Servico Teste HubSpot',
            'valor_padrao' => 100,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
        ]);

        $contrato = ContratoServico::create([
            'company_id' => $company->id,
            'servico_id' => $servico->id,
            'valor_contratado' => 3000,
            'data_contratacao' => now()->toDateString(),
            'ativo' => true,
            'hubspot_line_item_id' => 'li_456',
            'hubspot_valor_original' => 36000,
            'hubspot_valor_normalizado_mensal' => 3000,
            'hubspot_snapshot' => ['line_item' => ['amount' => '36000']],
        ]);

        $contrato->refresh();

        $this->assertSame('li_456', $contrato->hubspot_line_item_id);
        $this->assertSame('36000.00', $contrato->hubspot_valor_original);
        $this->assertSame('3000.00', $contrato->hubspot_valor_normalizado_mensal);
        $this->assertIsArray($contrato->hubspot_snapshot);
        $this->assertSame('36000', $contrato->hubspot_snapshot['line_item']['amount']);
    }

    public function test_colunas_nascem_nullable_e_sem_uso(): void
    {
        // Fluxo legado: cria Company e ContratoServico SEM nenhum campo HubSpot.
        // Prova que as novas colunas nascem nullable e não quebram o cadastro atual.
        $company = Company::create(['name' => 'Empresa Sem HubSpot']);
        $servico = Servico::create([
            'nome' => 'Servico Sem HubSpot',
            'valor_padrao' => 50,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
        ]);

        $contrato = ContratoServico::create([
            'company_id' => $company->id,
            'servico_id' => $servico->id,
            'valor_contratado' => 50,
            'data_contratacao' => now()->toDateString(),
            'ativo' => true,
        ]);

        $this->assertNull($company->fresh()->hubspot_deal_id);
        $this->assertNull($contrato->fresh()->hubspot_line_item_id);
    }
}
