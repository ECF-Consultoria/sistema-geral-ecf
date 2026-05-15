<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\MlbEmpresa;
use App\Models\Publicacao;
use Carbon\Carbon;

class ImportarPlanilhaMaycon extends Command
{
    protected $signature = 'mlb:importar-maycon {--dry-run : Mostra o que seria feito sem salvar}';
    protected $description = 'Importa empresas e MLBs da planilha do Maycon';

    public function handle(): int
    {
        $json = file_get_contents(base_path('maycon_data.json'));
        $companies = json_decode($json, true);

        $dryRun = $this->option('dry-run');
        $userId = 3; // Maycon Gomes
        $today = Carbon::today()->toDateString();

        $totalEmpresas = 0;
        $totalMlbs = 0;
        $skippedMlbs = 0;
        $skippedEmpresas = 0;

        foreach ($companies as $company) {
            $nome = $company['nome'];
            $custId = $company['cust_id'] ?: null;
            $mlbs = array_unique($company['mlbs']);

            // Find or create empresa
            $query = MlbEmpresa::query();
            if ($custId) {
                $query->where('cust_id', $custId);
            } else {
                $query->where('nome', $nome);
            }
            $empresa = $query->first();

            if ($empresa) {
                $this->line("  [EXISTE] Empresa: {$empresa->nome} (ID {$empresa->id})");
                $skippedEmpresas++;
            } else {
                $this->line("  [NOVA] Empresa: {$nome} | Cust ID: {$custId}");
                $totalEmpresas++;
                if (!$dryRun) {
                    $empresa = MlbEmpresa::create([
                        'nome'       => $nome,
                        'cust_id'    => $custId,
                        'estagio'    => 'Não Listado',
                        'criado_por' => $userId,
                    ]);
                }
            }

            $empresaId = $empresa?->id;

            foreach ($mlbs as $mlbCode) {
                $exists = Publicacao::where('mlb_code', $mlbCode)->exists();
                if ($exists) {
                    $this->line("    [SKIP] {$mlbCode} já existe");
                    $skippedMlbs++;
                    continue;
                }

                $this->line("    [ADD] {$mlbCode}");
                $totalMlbs++;
                if (!$dryRun) {
                    Publicacao::create([
                        'data'           => $today,
                        'user_id'        => $userId,
                        'empresa'        => $nome,
                        'cust_id'        => $custId,
                        'mlb_code'       => $mlbCode,
                        'vendido'        => false,
                        'revisado'       => false,
                        'mlb_empresa_id' => $empresaId,
                    ]);
                }
            }
        }

        $this->newLine();
        $this->info("Empresas criadas: {$totalEmpresas} | já existiam: {$skippedEmpresas}");
        $this->info("MLBs inseridos: {$totalMlbs} | já existiam: {$skippedMlbs}");

        if ($dryRun) {
            $this->warn('DRY RUN — nada foi salvo.');
        }

        return 0;
    }
}
