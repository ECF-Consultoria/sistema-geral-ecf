<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sync diário Adman às 11:00 BRT (cascata D-1).
// API Adman é D-1: publica dados consolidados às 10h BRT; sync 1h depois
// dá margem ao processamento Adman. Cron resultante: 0 11 * * *.
// No Hostinger: configure o cron: * * * * * php /home/user/public_html/ecf-admin/artisan schedule:run
Schedule::command('adman:sync')
    ->dailyAt('11:00')
    ->name('adman-sync-d1')
    ->withoutOverlapping();

// Calcula resultados de metas diariamente às 11:45 BRT (depois do adman:sync D-1)
Schedule::command('goals:calculate')
    ->dailyAt('11:45')
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

// Detecta sugadores (campanhas/anúncios drenando investimento) — depois do adman:sync D-1
Schedule::command('sugadores:analyze')
    ->dailyAt('12:00')
    ->name('analyze-sugadores')
    ->withoutOverlapping();

// Fecha sugadores cuja campanha foi movida pra quarentena (SGI/Sugadores)
// ou pausada — analista já tratou. Roda depois do analyze pra pegar
// adgroups que entraram em quarentena entre a detecção e o cron.
Schedule::command('sugadores:cleanup-quarentena')
    ->dailyAt('12:30')
    ->name('cleanup-sugadores-quarentena')
    ->withoutOverlapping();

// Calcula resultados das metas de setor (publicacoes_mes, etc) — diariamente
Schedule::job(new \App\Jobs\CalculateSetorGoalResults)
    ->dailyAt('11:55')
    ->name('calculate-setor-goal-results')
    ->withoutOverlapping();

// Pre-aquece cache de faturamento bruto (Adman /performance) das 30d.
// Roda 1×/dia às 12:45 BRT (cascata D-1) — antes era 30min/30min, mas com
// throttle de 7s e ~168 empresas o loop leva ~20min e só faz sentido depois
// do adman:sync diário ter rodado. Cache TTL alinhado em 24h (W1-T3).
Schedule::job(new \App\Jobs\RefreshGrossBillingCacheJob)
    ->dailyAt('12:45')
    ->name('refresh-gross-billing-cache-d1')
    ->withoutOverlapping();

// Sincroniza faturamento bruto mensal via Adman — depois do adman:sync D-1
Schedule::command('adman:sync-faturamento')
    ->dailyAt('11:30')
    ->name('sync-faturamento-mensal')
    ->withoutOverlapping();

// Cleanup diário de notificações lidas com >30 dias (POLL-04 — Phase 12).
// Roda às 04:00, antes do calculate-goal-results (06:00) e do sync Adman.
Schedule::command('notifications:cleanup')
    ->dailyAt('04:00')
    ->name('notifications-cleanup')
    ->withoutOverlapping();

// Envio automático mensal do relatório de fechamento.
// Roda a cada minuto para respeitar o dia e hora configurados dinamicamente pelo admin.
// Só dispara quando ativo=1, hoje == dia configurado, hora:minuto == hora configurada.
Schedule::call(function () {
    $ativo = \App\Models\Configuracao::get('email_envio_auto_ativo', '0');
    if ($ativo !== '1') {
        return;
    }

    $dia  = (int) \App\Models\Configuracao::get('email_envio_auto_dia', '5');
    $hora = \App\Models\Configuracao::get('email_envio_auto_hora', '09:00');

    // Compara usando o horário de Brasília — o servidor roda em UTC
    $agora = now()->setTimezone('America/Sao_Paulo');

    if ($agora->day === $dia && $agora->format('H:i') === $hora) {
        \App\Jobs\EnviarRelatorioFechamentoJob::dispatch($agora->format('Y-m'), null);
    }
})->everyMinute()->name('checa-envio-relatorio-fechamento');
