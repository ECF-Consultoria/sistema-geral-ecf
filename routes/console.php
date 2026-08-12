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

// Aquece o cache do AdmanMetricDiffService (carteira/transparência/desempenho
// lêem daqui). O cache é DIÁRIO (auto-invalida à meia-noite), então sem este
// warm a 1ª carga de carteira do dia dispara N chamadas Adman ao vivo → lenta.
// 11:40 = logo após o sync D-1 (dado fresco); 06:00 = cobre a manhã cedo com o
// dado estável de D-1. Idempotente (cache-hit não custa). Plano de otimização §9.2.
Schedule::command('adman:warm-diff')
    ->dailyAt('11:40')
    ->name('warm-adman-diff-pos-sync')
    ->withoutOverlapping();
Schedule::command('adman:warm-diff')
    ->dailyAt('06:00')
    ->name('warm-adman-diff-manha')
    ->withoutOverlapping();

// Calcula resultados de metas diariamente às 11:45 BRT (depois do adman:sync D-1)
Schedule::command('goals:calculate')
    ->dailyAt('11:45')
    ->name('calculate-goal-results')
    ->withoutOverlapping();

// DESLIGADO 2026-07-20 — este prune diário apagava TODO link NPS pendente com
// >2 dias sem resposta (mass-delete, sem passar pelo Eloquent → sem activity_log).
// Efeito colateral em produção: links gerados e enviados ao cliente sumiam em 48h
// ("os gerados sumindo"), levando o cliente a uma página quebrada. Decisão do dev:
// nunca apagar pendentes automaticamente — expiram sozinhos por expires_at e o
// admin pode excluir manualmente pela UI (destroy/bulkDestroy). Mantido comentado
// como documentação; reative apenas com uma janela larga (ex.: subDays(30)) se um
// dia a limpeza voltar a fazer sentido.
// Schedule::call(function () {
//     \App\Models\NpsSurvey::where('status', 'pending')
//         ->where('created_at', '<', now()->subDays(2))
//         ->delete();
// })->daily()->name('prune-pending-nps-surveys');

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

// Fase 134 ("Meus Anúncios") — D-06: fan-out diário do acervo ML (camada
// barata + camada cara por fatia de rotação, D-23). 11:35 = depois do
// ml:sync (11:05) e do adman:sync-margem (11:20); empilhar um terceiro
// fan-out pesado de ML na mesma janela concentraria pressão de rate limit
// sem necessidade, e o token já foi renovado às 08:00 pelo
// ml:refresh-tokens. withoutOverlapping() é obrigatório: a coleta da base
// inteira leva mais de uma hora na camada cara, e sem isso duas execuções
// se sobreporiam num dia de fila atrasada.
Schedule::command('mlb:sync-acervo')
    ->dailyAt('11:35')
    ->name('sync-ml-acervo')
    ->withoutOverlapping();

// Sync direto Shopee (D-1) — 11:15, logo após o ml:sync.
// Só processa empresas com token Shopee ativo; grava em shopee_metrics.
Schedule::command('shopee:sync')
    ->dailyAt('11:15')
    ->name('sync-shopee-direct')
    ->withoutOverlapping();

// Sync de Ads Shopee (D-1) — 11:30, logo após o faturamento (shopee:sync).
// Só empresas com token ADS ativo; grava as colunas ad_* em shopee_metrics.
// A Shopee só entrega Ads dos últimos ~6 meses (o comando faz o clamp).
Schedule::command('shopee:sync-ads')
    ->dailyAt('11:30')
    ->name('sync-shopee-ads')
    ->withoutOverlapping();

// Fase 109 (SHOP-DES-01/02) — aquece o cache do ShopeeMetricDiffService
// (Carteira/Desempenho lêem daqui via MetricDiffDispatcher). Mesmo padrão do
// warm-diff Adman (§9.2) — cache DIÁRIO, auto-invalida à meia-noite. 11:35 =
// logo após o sync Shopee (shopee:sync 11:15 + shopee:sync-ads 11:30), antes
// do warm-diff Adman (11:40). Idempotente (cache-hit não custa).
Schedule::command('shopee:warm-diff')
    ->dailyAt('11:35')
    ->name('warm-shopee-diff-pos-sync')
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

// Fase 134 ("Meus Anúncios") — D-07: retenção da série diária enxuta do
// acervo ML (ml_acervo_metricas_diarias, ~90 dias). 03:40 = logo depois do
// mlb:sync-vendas-logs-cleanup das 03:20, agrupando as rotinas de retenção
// na mesma janela ociosa.
Schedule::command('mlb:acervo-cleanup')
    ->dailyAt('03:40')
    ->name('cleanup-ml-acervo')
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

// Fase 119.1 Plan 01 (D2) — DESLIGADO 2026-07-29 o agendamento diário às
// 09:00 BRT do disparo automático de NPS (era `Schedule::command('nps:disparar-mensal')`,
// desde a Phase 31 Plan 02). "Descontinuar o automático" = desligar SÓ este
// agendamento — o comando `nps:disparar-mensal` continua existindo e 100%
// funcional para uso manual em massa (`php artisan nps:disparar-mensal`).
//
// O que passa a NÃO acontecer mais sozinho, todo dia, sem humano no loop:
//  - nenhum link mensal nasce sozinho — gerar o link vira ato deliberado do
//    responsável (estrategista/analista/admin), nunca mais automático;
//  - o e-mail `NpsMonthlyMail` e o envio Digisac só saem quando alguém
//    invoca `nps:disparar-mensal` à mão;
//  - o gancho de materialização da nota 1 (`NpsImputationService::materializar()`)
//    que rodava DENTRO deste comando, por survey criado, também para. Coberto
//    pela rede de segurança que já existe: `NpsController::generate()` já
//    materializa no disparo manual, e a rotina diária logo abaixo (Fase 116,
//    09:30, materializa não respondidos) varre `nps_surveys`
//    independentemente de quem os criou — NÃO precisa do disparo automático
//    para fazer seu trabalho.
//
// Efeito de tela ESPERADO e ACEITO (não é bug): `NpsPendingService` passará a
// marcar praticamente toda a carteira como pendente depois do dia de
// cobrança, porque nenhum link nasce sozinho. É consequência direta do
// desligamento — a Fase 119.1 fecha o buraco financeiro correspondente via D1
// (empresa elegível sem nenhum link no mês também conta nota 1).
//
// @see app/Console/Commands/NpsDispararMensal.php
// @see app/Services/Nps/NpsElegibilidadeService.php
// @see .planning/phases/119.1-.../119.1-CONTEXT.md (D2)

// Fase 116 (Plan 06 · NPSFLOOR) — materializa diariamente o NPS não respondido
// como nota mínima (1). Continua às 09:30 BRT mesmo após o desligamento do
// `nps-disparar-mensal` (Fase 119.1 Plan 01, D2) — esta rotina NÃO depende
// dele, varre `nps_surveys` de qualquer origem (manual ou automática antiga).
// Roda bem antes do `desempenho-consolidar-mes` (último dia do mês, 14:00), de
// forma que o snapshot mensal do bônus sempre enxergue os não respondidos da
// competência antes de congelar. `--force` porque é execução não interativa
// (sem humano no loop para confirmar); o comando é idempotente por
// construção (a idempotência vive no `NpsImputationService`, não aqui).
//
// ─── JANELA RESTRITA: mês anterior + mês corrente (correção 2026-07-28) ───
// A versão original desta rotina rodava SEM `--mes`, o que varre o histórico
// INTEIRO. Combinado com `--force`, isso executaria sozinho o backfill
// retroativo de todas as competências fechadas — reescrevendo os
// `DesempenhoScoreSnapshot` congelados e mudando quem bateu o bônus — sem o
// relatório antes/depois que o usuário exige aprovar (D1). O backfill
// retroativo é operação MANUAL e ÚNICA, com gate humano; nunca rotina.
//
// A janela é de DOIS meses, não um: a promoção de `provisorio` para
// `definitivo` acontece dentro da varredura (`NpsImputationService::
// materializar`, gate `$competenciaFechada`). Se a rotina só olhasse o mês
// corrente, as linhas do mês recém-fechado jamais seriam promovidas e a
// regra "quando o mês passa, a nota fica valendo 1" (D2) nunca se
// concretizaria. Varrer o mês anterior também é barato e idempotente.
Schedule::call(function () {
    foreach ([now()->subMonthNoOverflow(), now()] as $mes) {
        Artisan::call('nps:materializar-nao-respondidos', [
            '--force' => true,
            '--mes'   => $mes->format('Y-m'),
        ]);
    }
})
    ->dailyAt('09:30')
    ->timezone('America/Sao_Paulo')
    ->name('nps-materializar-nao-respondidos')
    ->onOneServer()
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
// Phase 74 D-08 — motor internamente reescrito para o DesempenhoScoreService v2;
// snapshot diário grava mes_referencia=NULL (modo D-02).
Schedule::command('desempenho:snapshot-scores')
    ->dailyAt('13:30')
    ->timezone('America/Sao_Paulo')
    ->name('desempenho-snapshot-scores')
    ->onOneServer()
    ->withoutOverlapping();

// Phase 74 D-08 / DESEMP-09 — Consolida snapshot mensal fechado.
// v18.0 · Fase 105 (D2, NPSWIN-03): congela no ÚLTIMO DIA do mês de coleta
// (fim de M+1), consolidando a competência M cujo NPS acabou de ser
// coletado — não mais no dia 1 (início da coleta de M+1, quando quase
// nenhuma resposta existia ainda). Consequência aceita: o pagamento de
// junho desliza pro fim de julho / início de agosto (não começo de
// julho) — decisão D2, sem snapshot provisório + refechamento.
// Idempotente via updateOrCreate((user_id, mes_referencia)). Aceita
// --mes=YYYY-MM para reprocessamento manual (catch-up pós-incident).
// Users sem carteira são pulados (DESEMP-10).
Schedule::command('desempenho:consolidar-mes')
    ->lastDayOfMonth('14:00')
    ->timezone('America/Sao_Paulo')
    ->name('desempenho-consolidar-mes')
    ->onOneServer()
    ->withoutOverlapping();

// Ajuste 2026-07-10 (audit margem-luiz-ana) — sincroniza custo/margem via Adman
// para empresas com OAuth ML ativo. Após o cutover Adman→ML (commit c85b86f,
// 01/06), essas empresas ficaram sem `contribution_margin` porque a API do ML
// não expõe CMV. Roda 11:20 BRT, 15min após o ml:sync (11:05) — mesmo horário
// da cascata D-1, complementa sem sobrescrever revenue/ad_spend do ML.
Schedule::command('adman:sync-margem')
    ->dailyAt('11:20')
    ->timezone('America/Sao_Paulo')
    ->name('sync-adman-margem-oauth-ml')
    ->onOneServer()
    ->withoutOverlapping();

// Ajuste 2026-07-10 (audit performance-lentidao) — pré-aquece o cache Redis do
// compute() de desempenho a cada 8min. TTL do cache é 10min (mês em curso),
// então 8min garante 2min de margem antes de expirar. Faz o custo pesado
// (~70s cold, ~150+ HTTP calls ao ML) rodar em background e nenhum user cai
// em cold miss. Roda entre 7h e 22h (horário útil) — fora disso deixa o cache
// naturalmente expirar (sem gasto de rate limit).
Schedule::command('desempenho:warm-cache')
    ->cron('*/8 * * * *')
    ->between('7:00', '22:00')
    ->timezone('America/Sao_Paulo')
    ->name('desempenho-warm-cache')
    ->onOneServer()
    ->withoutOverlapping()
    ->runInBackground();

// Fase 135 Plano 07 — reavalia periodicamente os passos automáticos que
// ficaram esperando (aguardando_coleta/indeterminado/aberto-com-auto_fonte,
// D-05/D-09/D-11). Existe porque o resolver do passo 8 (acervo ML) DISPARA
// a coleta e devolve o controle na hora — a cadência de 10min é casada com
// dois limites: o timeout de 30min da camada barata do acervo
// (SyncMlAcervoCompanyJob), dando margem de sobra para reconferir depois
// que o job termina; e o rate limit de 10 rpm da Adman (o throttle de 7s do
// AdmanGrantResolver já respeita a chamada individual — esta cadência evita
// empilhar sondas na fila entre uma passada e outra). A trava de não
// sobreposição abaixo é obrigatória: uma passada atrasada não pode se
// sobrepor à seguinte.
Schedule::command('onboarding:reavaliar-passos')
    ->cron('*/10 * * * *')
    ->timezone('America/Sao_Paulo')
    ->name('onboarding-reavaliar-passos')
    ->withoutOverlapping();

// Debug hubspot-handoff-sem-contatos (2026-07-27) — fix sistêmico do race
// condition/eventual consistency do HubSpot: o webhook processa o deal
// fechado quase instantaneamente, mas as associações deal→company/contacts
// só ficam consultáveis via API segundos depois (7s–32s observado). Varre
// companies de origem HubSpot com hubspot_company_id/contact_id vazios e
// reprocessa via o mesmo mecanismo de replay do hubspot:reprocess-event.
// A cada 3min: tempo suficiente pro HubSpot assentar (janela mínima de 2min
// já embutida no comando) sem acumular atraso perceptível pro Comercial.
Schedule::command('hubspot:reenriquecer-handoff')
    ->everyThreeMinutes()
    ->timezone('America/Sao_Paulo')
    ->name('hubspot-reenriquecer-handoff')
    ->onOneServer()
    ->withoutOverlapping();
