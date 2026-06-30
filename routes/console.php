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

// Phase 20 — Sincroniza grants via API HTTP do ECF Drive (substitui pipeline SFTP).
// O comando grants:sync-sftp permanece no repo como rollback safety por +1 fase
// mas não é mais invocado pelo schedule.
Schedule::command('grants:sync-ecf')
    ->dailyAt('03:00')
    ->name('sync-ml-grants-ecf')
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

// Aquece e PERSISTE o faturamento/ADS dos polos ativos (M2–M4) do mês corrente —
// alimenta o /polos. Roda 13:00 BRT, no fim da cascata D-1 (depois do adman:sync 11:00
// e do refresh de gross billing 12:45). de/ate null = mês corrente (default do Job).
// Sem este agendamento o /polos zerava ao virar o dia (a chave de cache da Adman inclui
// a data) e exigia clicar "Sincronizar" à mão; com ele + o snapshot durável, a página
// fica sempre populada e se atualiza sozinha após o sync diário.
Schedule::job(new \App\Jobs\SyncPolosFaturamentoJob)
    ->dailyAt('13:00')
    ->timezone('America/Sao_Paulo')
    ->name('sync-polos-faturamento-d1')
    ->withoutOverlapping();

// Sincroniza faturamento bruto mensal via Adman — depois do adman:sync D-1
Schedule::command('adman:sync-faturamento')
    ->dailyAt('11:30')
    ->name('sync-faturamento-mensal')
    ->withoutOverlapping();

// Renova tokens Mercado Livre próximos de expirar (janela: <2h)
Schedule::command('ml:refresh-tokens')
    ->dailyAt('08:00')
    ->name('refresh-ml-tokens')
    ->withoutOverlapping();

// Sync direto ML (D-1) — roda às 11:05 logo após o adman:sync, enquanto migração está em curso
// Só processa empresas com token OAuth ativo; as demais seguem via Adman
Schedule::command('ml:sync')
    ->dailyAt('11:05')
    ->name('sync-ml-direct')
    ->withoutOverlapping();

// Cleanup diário de notificações lidas com >30 dias (POLL-04 — Phase 12).
// Roda às 04:00, antes do calculate-goal-results (06:00) e do sync Adman.
Schedule::command('notifications:cleanup')
    ->dailyAt('04:00')
    ->name('notifications-cleanup')
    ->withoutOverlapping();

// Encerra logs de sync de vendas travados em "running" (worker reiniciado no meio)
// e poda logs encerrados com >30 dias — evita lista infinita no /dev/desenvolvimento.
Schedule::command('mlb:sync-vendas-logs-cleanup')
    ->dailyAt('03:20')
    ->name('cleanup-sync-vendas-logs')
    ->withoutOverlapping();

// Phase 30 Plan 30-04 — Sync de MLBs por adgroup pra tabela local (off-peak).
// Roda às 03:00 BRT: horário sem analistas trabalhando + Adman MCP descongestionada.
// Range default 30 dias (alinhado com revenue_30d do resto do sistema).
// Drilldown do Sugadores lê instantâneo do banco a partir desta tabela.
Schedule::command('sugadores:sync-adgroup-mlbs --all')
    ->dailyAt('03:00')
    ->timezone('America/Sao_Paulo')
    ->name('sync-adgroup-mlbs')
    ->withoutOverlapping();

// Phase 31 Plan 02 — Disparo mensal de pesquisa NPS no aniversário do
// cadastro da empresa (D-01/D-03). 09:00 BRT — fora dos horários de pico:
// adman:sync 11:00, sync-faturamento 11:30, calculate-goal-results 11:45,
// sugadores 12:00, gross billing 12:45. Idempotência via
// where(company_id, month_reference) — re-runs no mesmo dia são seguros.
Schedule::command('nps:disparar-mensal')
    ->dailyAt('09:00')
    ->timezone('America/Sao_Paulo')
    ->name('nps-disparar-mensal')
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

// Phase 40 — Shadow mode ML diário às 13h BRT (1h depois do sugadores:analyze das 12h).
// Só dispara pras empresas em SUGADORES_ML_SHADOW_COMPANIES (config/sugadores.php).
// NÃO escreve em `sugadores` — apenas em sugador_provider_runs/items (gate REQ-40-02).
Schedule::command('sugadores:shadow-ml --company=all --days=1')
    ->dailyAt('13:00')
    ->timezone('America/Sao_Paulo')
    ->name('sugadores-shadow-ml-daily')
    ->onOneServer()
    ->withoutOverlapping();

// Phase 46 — Snapshot diário do score de desempenho de cada analista/estrategista.
// Roda às 13:30 BRT, logo após a cascata D-1 terminar (SyncPolosFaturamentoJob 13:00).
// Lê dados já consolidados do dia + persiste em desempenho_score_snapshots para
// alimentar deltas (vs ontem / vs semana passada) e gráfico de evolução temporal.
Schedule::command('desempenho:snapshot-scores')
    ->dailyAt('13:30')
    ->timezone('America/Sao_Paulo')
    ->name('desempenho-snapshot-scores')
    ->onOneServer()
    ->withoutOverlapping();
