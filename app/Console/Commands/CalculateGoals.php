<?php

namespace App\Console\Commands;

use App\Jobs\CalculateGoalResults;
use App\Jobs\CalculatePortfolioGoalResults;
use Illuminate\Console\Command;

class CalculateGoals extends Command
{
    protected $signature = 'goals:calculate
        {--period= : Período YYYY-MM (padrão: mês anterior)}
        {--goal= : Calcular apenas uma meta de empresa específica (ID)}
        {--portfolio-goal= : Calcular apenas uma meta de carteira específica (ID)}
        {--portfolio : Calcular apenas metas de carteira}
        {--company : Calcular apenas metas por empresa}';

    protected $description = 'Calcula os resultados realizados de metas por empresa e por carteira';

    public function handle(): int
    {
        $period = $this->option('period') ?? now()->subMonth()->format('Y-m');

        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $this->error("Período inválido: {$period}. Use o formato YYYY-MM.");
            return self::FAILURE;
        }

        $onlyPortfolio = $this->option('portfolio');
        $onlyCompany   = $this->option('company');
        $goalId        = $this->option('goal') ? (int) $this->option('goal') : null;
        $pgId          = $this->option('portfolio-goal') ? (int) $this->option('portfolio-goal') : null;

        if (!$onlyPortfolio) {
            $this->info("Calculando metas por empresa — período {$period}...");
            CalculateGoalResults::dispatchSync($period, $goalId);
            $this->info('Metas por empresa: concluído.');
        }

        if (!$onlyCompany) {
            $this->info("Calculando metas de carteira — período {$period}...");
            CalculatePortfolioGoalResults::dispatchSync($period, $pgId);
            $this->info('Metas de carteira: concluído.');
        }

        return self::SUCCESS;
    }
}
