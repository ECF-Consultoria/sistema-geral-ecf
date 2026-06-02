<?php

namespace Tests\Feature\Phase18_5;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Phase 18.5 W3-T1 — Cobertura do comando dashboard:import-marketplace-from-csv.
 *
 * Garante:
 *  1) Linha 'MercadoLibre' do CSV vira marketplace='meli' no model.
 *  2) Linha 'Shopee' vira marketplace='shopee'.
 *  3) CustId sem empresa correspondente e contabilizado sem UPDATE.
 *  4) --dry-run nao aplica UPDATE.
 *
 * Defesa: o comando NUNCA pode mexer em outros campos (nome, CNPJ,
 * adman_account_id, ml_store_id). Cada teste verifica esses campos.
 *
 * @group phase18_5
 */
class ImportMarketplaceFromCsvTest extends TestCase
{
    use RefreshDatabase;

    /** Gera um CSV temporario com cabecalho fixo e as linhas passadas. */
    private function gerarCsv(array $linhas): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mkt_');
        $f = fopen($path, 'w');

        // Cabecalho: replica exatamente o formato da Adman (30 colunas).
        // Apenas Nome (0), CustId (1) e Marketplace (29) sao usados pelo comando.
        $header = array_fill(0, 30, 'Col');
        $header[0]  = 'Nome';
        $header[1]  = 'CustId';
        $header[29] = 'Marketplace';
        fputcsv($f, $header);

        foreach ($linhas as $linha) {
            $row = array_fill(0, 30, '');
            $row[0]  = $linha['nome']        ?? '';
            $row[1]  = $linha['cust_id']     ?? '';
            $row[29] = $linha['marketplace'] ?? '';
            fputcsv($f, $row);
        }

        fclose($f);
        return $path;
    }

    /**
     * TEST 1 — Linha MercadoLibre mapeia para marketplace='meli'.
     */
    public function test_atualiza_marketplace_meli_quando_linha_csv_mercadolibre(): void
    {
        $empresa = Company::create([
            'name'             => 'Empresa Meli',
            'cnpj'             => '11111111000011',
            'active'           => true,
            'adman_account_id' => 'CUSTID_MELI',
            'marketplace'      => 'shopee', // partindo de algo diferente para garantir o flip
        ]);

        $csv = $this->gerarCsv([
            ['nome' => 'Empresa Meli', 'cust_id' => 'CUSTID_MELI', 'marketplace' => 'MercadoLibre'],
        ]);

        $exitCode = Artisan::call('dashboard:import-marketplace-from-csv', ['arquivo' => $csv]);
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);

        $empresa->refresh();
        $this->assertSame('meli', $empresa->marketplace);

        // Defesa: outros campos preservados.
        $this->assertSame('Empresa Meli', $empresa->name);
        $this->assertSame('CUSTID_MELI',  $empresa->adman_account_id);
        $this->assertSame('11111111000011', $empresa->cnpj);

        // Sumario mostra 1 atualizada.
        $this->assertStringContainsString('Empresas atualizadas', $output);

        @unlink($csv);
    }

    /**
     * TEST 2 — Linha Shopee mapeia para marketplace='shopee'.
     */
    public function test_atualiza_marketplace_shopee_quando_linha_csv_shopee(): void
    {
        $empresa = Company::create([
            'name'             => 'Loja Shopee',
            'cnpj'             => '22222222000022',
            'active'           => true,
            'adman_account_id' => 'CUSTID_SHOPEE',
            // Default 'meli' aplicado pela migration.
        ]);

        $csv = $this->gerarCsv([
            ['nome' => 'Loja Shopee', 'cust_id' => 'CUSTID_SHOPEE', 'marketplace' => 'Shopee'],
        ]);

        $exitCode = Artisan::call('dashboard:import-marketplace-from-csv', ['arquivo' => $csv]);
        $this->assertSame(0, $exitCode);

        $empresa->refresh();
        $this->assertSame('shopee', $empresa->marketplace);
        $this->assertSame('CUSTID_SHOPEE', $empresa->adman_account_id);

        @unlink($csv);
    }

    /**
     * TEST 3 — CustId nao encontrado: contabiliza, nao toca DB.
     */
    public function test_pula_e_contabiliza_quando_cust_id_nao_existe_no_db(): void
    {
        // Empresa existente serve so para garantir que NAO foi tocada.
        $outraEmpresa = Company::create([
            'name'             => 'Outra Empresa',
            'cnpj'             => '33333333000033',
            'active'           => true,
            'adman_account_id' => 'CUSTID_EXISTENTE',
            'marketplace'      => 'meli',
        ]);

        $csv = $this->gerarCsv([
            ['nome' => 'Empresa Fantasma', 'cust_id' => 'CUSTID_INEXISTENTE', 'marketplace' => 'Shopee'],
        ]);

        $exitCode = Artisan::call('dashboard:import-marketplace-from-csv', ['arquivo' => $csv]);
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);

        // Sumario contabilizou 1 nao encontrada.
        $this->assertStringContainsString('Empresas nao encontradas', $output);
        $this->assertStringContainsString('CUSTID_INEXISTENTE', $output);

        // Outra empresa nao foi tocada.
        $outraEmpresa->refresh();
        $this->assertSame('meli', $outraEmpresa->marketplace);
        $this->assertSame('CUSTID_EXISTENTE', $outraEmpresa->adman_account_id);

        @unlink($csv);
    }

    /**
     * TEST 4 — --dry-run nao aplica UPDATE.
     */
    public function test_dry_run_nao_aplica_update(): void
    {
        $empresa = Company::create([
            'name'             => 'Empresa Dry Run',
            'cnpj'             => '44444444000044',
            'active'           => true,
            'adman_account_id' => 'CUSTID_DRY',
            'marketplace'      => 'meli',
        ]);

        $csv = $this->gerarCsv([
            ['nome' => 'Empresa Dry Run', 'cust_id' => 'CUSTID_DRY', 'marketplace' => 'Shopee'],
        ]);

        $exitCode = Artisan::call('dashboard:import-marketplace-from-csv', [
            'arquivo'   => $csv,
            '--dry-run' => true,
        ]);
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);

        // Recarrega do banco — deve continuar 'meli'.
        $reload = Company::find($empresa->id);
        $this->assertSame('meli', $reload->marketplace, '--dry-run nao pode aplicar UPDATE no banco');

        // Output declara que estava em dry-run.
        $this->assertStringContainsString('DRY-RUN', $output);

        @unlink($csv);
    }
}
