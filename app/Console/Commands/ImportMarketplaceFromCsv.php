<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 18.5 W1-T2 — Importa o marketplace oficial de cada empresa via CSV
 * exportado da Adman.
 *
 * O CSV vem com 30 colunas; aqui interessam apenas:
 *  - coluna 1 (CustId) → match com `companies.adman_account_id`
 *  - coluna 29/ultima (Marketplace) → mapeamento `MercadoLibre|Shopee|Amazon`
 *
 * Read-only para outros campos (nome, CNPJ, etc) — em hipotese alguma toca
 * em colunas que nao sao `marketplace`. Isso e proposital: a planilha
 * oficial pode ter divergencias com o nosso cadastro, mas para a Phase 18.5
 * o que importa e exclusivamente fixar o marketplace do AdmanService.
 *
 * Idempotente: linhas com marketplace ja igual ao CSV viram `skipped_iguais`
 * e nao geram activity log. Roda quantas vezes for necessario sem efeito
 * colateral.
 *
 * `--dry-run` mostra preview completo sem aplicar UPDATE — util para
 * revisar o output com o usuario antes de comitar em prod.
 *
 * @see database/migrations/2026_06_02_190000_add_marketplace_to_companies.php
 * @see app/Services/AdmanService.php
 */
class ImportMarketplaceFromCsv extends Command
{
    protected $signature = 'dashboard:import-marketplace-from-csv
        {arquivo : Caminho absoluto para o CSV exportado da Adman}
        {--dry-run : Mostra preview sem aplicar UPDATE}';

    protected $description = 'Importa companies.marketplace a partir do CSV oficial da Adman (Phase 18.5)';

    /** Mapeamento autoritativo entre nomes da Adman e ENUM do nosso schema. */
    private const MAPA_MARKETPLACE = [
        'mercadolibre' => 'meli',
        'shopee'       => 'shopee',
        'amazon'       => 'amazon',
    ];

    public function handle(): int
    {
        $arquivo = $this->argument('arquivo');
        $dryRun  = (bool) $this->option('dry-run');

        if (!is_file($arquivo) || !is_readable($arquivo)) {
            $this->error("Arquivo nao encontrado ou ilegivel: {$arquivo}");
            return self::FAILURE;
        }

        $this->info('[Marketplace Import] Iniciando' . ($dryRun ? ' (DRY-RUN)' : '') . " — arquivo={$arquivo}");
        $this->newLine();

        $handle = fopen($arquivo, 'r');
        if ($handle === false) {
            $this->error("Falha ao abrir arquivo: {$arquivo}");
            return self::FAILURE;
        }

        // Cabecalho: valida que as colunas autoritativas estao onde esperamos.
        $header = fgetcsv($handle);
        if ($header === false || count($header) < 2) {
            fclose($handle);
            $this->error('CSV vazio ou sem cabecalho.');
            return self::FAILURE;
        }

        // Coluna 0 deve ser "Nome", coluna 1 deve ser "CustId".
        // A coluna "Marketplace" e a ULTIMA do header (indice variavel, mas
        // historicamente 29). Aceita variacao de case e espacos.
        $colCustId       = strtolower(trim($header[1] ?? ''));
        $colUltima       = strtolower(trim(end($header) ?: ''));

        if ($colCustId !== 'custid' || $colUltima !== 'marketplace') {
            fclose($handle);
            $this->error("Cabecalho inesperado. Coluna 1='{$header[1]}' (esperava 'CustId'); ultima='" . end($header) . "' (esperava 'Marketplace').");
            return self::FAILURE;
        }

        $totalLinhas         = 0;
        $linhasInvalidas     = 0;
        $marketplaceDescon   = 0;
        $marketplaceDesconList = [];
        $encontradas         = 0;
        $skippedIguais       = 0;
        $atualizadas         = 0;
        $atualizadasBreakdown = ['meli' => 0, 'shopee' => 0, 'amazon' => 0];
        $naoEncontradas      = 0;
        $naoEncontradasList  = [];

        while (($row = fgetcsv($handle)) !== false) {
            $totalLinhas++;

            $custId          = trim($row[1] ?? '');
            $marketplaceCsv  = trim((string) end($row));

            // Linhas sem CustId ou sem marketplace declarado sao invalidas — pula.
            if ($custId === '' || $marketplaceCsv === '') {
                $linhasInvalidas++;
                continue;
            }

            $marketplaceKey  = strtolower($marketplaceCsv);
            $marketplaceEnum = self::MAPA_MARKETPLACE[$marketplaceKey] ?? null;

            if ($marketplaceEnum === null) {
                $marketplaceDescon++;
                if (count($marketplaceDesconList) < 5) {
                    $marketplaceDesconList[] = "{$custId} → '{$marketplaceCsv}'";
                }
                continue;
            }

            $company = Company::where('adman_account_id', $custId)->first();
            if (!$company) {
                $naoEncontradas++;
                if (count($naoEncontradasList) < 10) {
                    $naoEncontradasList[] = $custId;
                }
                continue;
            }

            $encontradas++;

            $atual = $company->marketplace;
            if ($atual === $marketplaceEnum) {
                $skippedIguais++;
                continue;
            }

            $atualizadas++;
            $atualizadasBreakdown[$marketplaceEnum] = ($atualizadasBreakdown[$marketplaceEnum] ?? 0) + 1;

            if (!$dryRun) {
                // UPDATE direto (sem chamar save em outras colunas) — garante
                // que nenhum outro campo seja tocado mesmo que o model tenha
                // mutators/casts em colunas adjacentes.
                DB::table('companies')
                    ->where('id', $company->id)
                    ->update(['marketplace' => $marketplaceEnum, 'updated_at' => now()]);

                activity()
                    ->performedOn($company)
                    ->withProperties([
                        'from'   => $atual,
                        'to'     => $marketplaceEnum,
                        'source' => 'csv',
                    ])
                    ->log('Marketplace atualizado via import CSV');

                Log::info("[Marketplace Import] empresa={$company->id} ({$company->name}) custId={$custId}: {$atual} → {$marketplaceEnum}");
            }
        }

        fclose($handle);

        // ─── Sumario ────────────────────────────────────────────────────────
        $this->newLine();
        $this->info('━━━ Sumario ' . ($dryRun ? '(DRY-RUN — nada aplicado)' : '(aplicado)') . ' ━━━');
        $linhasValidas = $totalLinhas - $linhasInvalidas - $marketplaceDescon;

        $this->table(
            ['Metrica', 'Valor'],
            [
                ['Total de linhas no CSV',          $totalLinhas],
                ['Linhas invalidas (sem CustId ou marketplace)', $linhasInvalidas],
                ['Marketplace desconhecido',        $marketplaceDescon],
                ['Linhas validas processadas',      $linhasValidas],
                ['Empresas encontradas no DB',      $encontradas],
                ['Empresas com marketplace ja igual (skip)', $skippedIguais],
                ['Empresas atualizadas',            $atualizadas],
                ['  └─ meli',                       $atualizadasBreakdown['meli']],
                ['  └─ shopee',                     $atualizadasBreakdown['shopee']],
                ['  └─ amazon',                     $atualizadasBreakdown['amazon']],
                ['Empresas nao encontradas',        $naoEncontradas],
            ]
        );

        if ($naoEncontradas > 0) {
            $this->warn('Primeiros CustIds nao encontrados no DB (ate 10):');
            foreach ($naoEncontradasList as $c) {
                $this->line("  - {$c}");
            }
        }

        if ($marketplaceDescon > 0) {
            $this->warn('Linhas com marketplace nao mapeado (ate 5):');
            foreach ($marketplaceDesconList as $m) {
                $this->line("  - {$m}");
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('[DRY-RUN] Nenhum UPDATE aplicado. Re-execute sem --dry-run para gravar.');
        }

        return self::SUCCESS;
    }
}
