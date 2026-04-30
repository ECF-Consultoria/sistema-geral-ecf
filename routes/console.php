<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sync automático a cada 5 minutos (cron: * * * * *)
// No Hostinger: configure o cron: * * * * * php /home/user/public_html/ecf-admin/artisan schedule:run
Schedule::command('adman:sync')->everyFiveMinutes()->withoutOverlapping();

// Calcula resultados de metas diariamente às 06:00 (usa dados mais recentes do Adman)
Schedule::command('goals:calculate')
    ->dailyAt('06:00')
    ->name('calculate-goal-results')
    ->withoutOverlapping();

// Remove links NPS pendentes com mais de 2 dias sem resposta
Schedule::call(function () {
    \App\Models\NpsSurvey::where('status', 'pending')
        ->where('created_at', '<', now()->subDays(2))
        ->delete();
})->daily()->name('prune-pending-nps-surveys');

// Sincroniza lista de grants do Mercado Livre via SFTP
Schedule::command('grants:sync-sftp')
    ->dailyAt('03:00')
    ->name('sync-ml-grants-sftp')
    ->withoutOverlapping();
