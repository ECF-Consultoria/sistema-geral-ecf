<?php

namespace App\Console\Commands;

use App\Jobs\SyncFaturamentoMensalJob;
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncFaturamentoMensal extends Command
{
    protected $signature   = 'adman:sync-faturamento {--mes= : Mês no formato YYYY-MM; padrão: mês corrente}';
    protected $description = 'Sincroniza faturamento bruto mensal de todas as empresas com integração Adman.';

    public function handle(): int
    {
        $mes = $this->option('mes')
            ? Carbon::createFromFormat('Y-m', $this->option('mes'))->format('Y-m')
            : Carbon::now()->format('Y-m');

        $companies = Company::where('active', true)
            ->where(function ($q) {
                $q->where(function ($q2) { $q2->whereNotNull('ml_store_id')->where('ml_store_id', '!=', ''); })
                  ->orWhere(function ($q2) { $q2->whereNotNull('adman_account_id')->where('adman_account_id', '!=', ''); });
            })
            ->get();

        $this->info("[Faturamento] Iniciando sync {$mes} — {$companies->count()} empresas.");

        foreach ($companies as $company) {
            SyncFaturamentoMensalJob::dispatch($company, $mes);
        }

        $this->info("[Faturamento] {$companies->count()} jobs despachados para {$mes}.");
        return Command::SUCCESS;
    }
}
