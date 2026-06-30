<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AlertasController;
use App\Http\Controllers\EcfWebhookController;
use App\Http\Controllers\ComercialController;
use App\Http\Controllers\ConcentracaoController;
use App\Http\Controllers\EmpresaAnaliseEcfController;
use App\Http\Controllers\Admin\CargoController;
use App\Http\Controllers\Admin\SetorController;
use App\Http\Controllers\Admin\SetorGoalController;
use App\Http\Controllers\Admin\SetorMembroController;
use App\Http\Controllers\AdmanController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyGroupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Dev\SugadoresMlOnboardingController;
use App\Http\Controllers\DevController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\LiderancaController;
use App\Http\Controllers\MlbController;
use App\Http\Controllers\MlbImplementacaoController;
use App\Http\Controllers\GoogleCalendarController;
use App\Http\Controllers\MercadoLivreOAuthController;
use App\Http\Controllers\GrantController;
use App\Http\Controllers\ManualController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\NotificacaoController;
use App\Http\Controllers\NpsController;
use App\Http\Controllers\PainelExecutivoController;
use App\Http\Controllers\PolosController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\PpaController;
use App\Http\Controllers\PpaTaskController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ServicoController;
use App\Http\Controllers\Sistema\HubspotLineItemMappingController;
use App\Http\Controllers\SugadorConfigController;
use App\Http\Controllers\SugadorController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Redireciona raiz para dashboard
Route::get('/', fn() => redirect()->route('dashboard'));

// Endpoint interno para sincronização de grants (curl fire-and-forget — sem CSRF)
Route::post('/internal/grants/sync/run', [GrantController::class, 'syncRun'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->name('grants.sync.run');

// ─── Receiver de webhooks ECF Drive (Phase 26) ───────────────────────────────
// URL configurada no painel ECF Drive: https://admin.ecfconsultoria.com.br/api/webhooks/ecf
// Sem autenticação de sessão — autenticação via HMAC SHA256 (X-ECF-Signature).
// Dupla proteção CSRF: bootstrap/app.php (except 'api/webhooks/*') + withoutMiddleware (D-A do PLAN).
// Rate limit: throttle:ecf-webhook (600 req/min por IP, registrado em AppServiceProvider).
Route::post('/api/webhooks/ecf', [EcfWebhookController::class, 'handle'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->middleware('throttle:ecf-webhook')
    ->name('ecf-webhook.receive');

// ─── Receiver de webhooks HubSpot (Phase 34 Plan 34-04) ──────────────────────
// URL configurada no painel HubSpot: https://admin.ecfconsultoria.com.br/api/webhooks/hubspot
// Autenticação via HMAC v3 (X-HubSpot-Signature-v3 + X-HubSpot-Request-Timestamp).
// CSRF isento por bootstrap/app.php (api/webhooks/*) + withoutMiddleware (defensivo).
// Rate limit: throttle:60,1 (HubSpot legitimo manda muito menos — defesa contra spam).
Route::post('/api/webhooks/hubspot', [\App\Http\Controllers\Api\HubspotWebhookController::class, 'receive'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->middleware('throttle:60,1')
    ->name('webhooks.hubspot');

// PPA Workspace público (sem autenticação) — cliente acessa pelo token
Route::get('/ppa/workspace/{token}', [PpaController::class, 'workspace'])->name('ppa.workspace');
Route::patch('/ppa/workspace/{token}/tasks/{task}', [PpaTaskController::class, 'clientUpdate'])->name('ppa.workspace.task.update');

// Implementação MLB público (sem autenticação) — cliente preenche via token
Route::get('/implementacao/{token}', [MlbImplementacaoController::class, 'workspace'])->name('implementacao.workspace');
Route::patch('/implementacao/{token}', [MlbImplementacaoController::class, 'salvarItem'])->name('implementacao.salvar');

// Visão do publicador (sem autenticação, somente leitura)
Route::get('/implementacao/{token}/publicador', [MlbImplementacaoController::class, 'publicador'])->name('implementacao.publicador');

// ML OAuth — callback público (o cliente autoriza fora do painel)
Route::get('/oauth/mercadolivre/callback', [MercadoLivreOAuthController::class, 'callback'])
    ->name('ml.oauth.callback');

// Google OAuth (público — sem autenticação durante o callback)
Route::get('/google/connect', [GoogleCalendarController::class, 'connect'])
    ->middleware(['auth', 'verified'])
    ->name('google.connect');
Route::get('/google/callback', [GoogleCalendarController::class, 'callback'])->name('google.callback');

// Gerar link NPS (DEVE ficar antes da rota pública /nps/{token} para não colidir)
Route::post('/nps/generate', [NpsController::class, 'generate'])
    ->middleware(['auth', 'verified'])
    ->name('nps.generate');

// ─── Customização NPS (Phase 32 Plan 02) ────────────────────────────────────
// Páginas admin-only (role:admin) para editar os 11 textos do fluxo NPS +
// endpoint de preview server-rendered do email (D-05). DEVEM ficar ANTES da
// rota pública /nps/{token} para evitar colisão com o parâmetro dinâmico.
Route::middleware(['auth', 'verified', 'role:admin'])->group(function () {
    Route::get('/nps/configuracao',          [NpsController::class, 'configuracao'])->name('nps.configuracao.index');
    Route::put('/nps/configuracao',          [NpsController::class, 'atualizarConfiguracao'])->name('nps.configuracao.update');
    Route::post('/nps/configuracao/preview', [NpsController::class, 'previewEmail'])->name('nps.configuracao.preview');

    // Phase 32 Plan 04 — Página de envios NPS (log do nps:disparar-mensal).
    // Tem que ficar dentro deste grupo ANTES de /nps/{token} para nao colidir
    // com o parametro dinamico da rota publica de resposta.
    Route::get('/nps/emails-enviados', [NpsController::class, 'emailsEnviados'])->name('nps.emails-enviados.index');

    // ─── Phase 33 Plan 33-01 — Perguntas customizadas (CRUD admin) ───────────
    // Endpoints REST sob /nps/configuracao/perguntas. A UI vive na 3a tab da
    // pagina /nps/configuracao (Plan 33-02). DEVEM ficar neste grupo para
    // herdar middleware role:admin + ANTES da rota publica /nps/{token}.
    Route::post  ('/nps/configuracao/perguntas',                   [NpsController::class, 'criarPerguntaExtra'])->name('nps.configuracao.perguntas.criar');
    Route::put   ('/nps/configuracao/perguntas/{pergunta}',        [NpsController::class, 'atualizarPerguntaExtra'])->name('nps.configuracao.perguntas.atualizar');
    Route::delete('/nps/configuracao/perguntas/{pergunta}',        [NpsController::class, 'excluirPerguntaExtra'])->name('nps.configuracao.perguntas.excluir');
    Route::post  ('/nps/configuracao/perguntas/{pergunta}/mover',  [NpsController::class, 'moverPerguntaExtra'])->name('nps.configuracao.perguntas.mover');

    // Quick task 260612-flt — admin exclui resposta de uma pesquisa NPS
    // (reverte survey para pending). Antes da rota publica /nps/{token}.
    Route::delete('/nps/{survey}/response', [NpsController::class, 'excluirResposta'])->name('nps.responses.destroy');
});

// NPS público (sem autenticação) — token vem DEPOIS do /generate
Route::get('/nps/{token}', [NpsController::class, 'respond'])->name('nps.respond');
Route::post('/nps/{token}', [NpsController::class, 'submitResponse'])->name('nps.submit');

// Política de Privacidade pública (HTML estático — usado p/ aprovação Chrome Web Store)
Route::view('/privacidade/painel-ecf', 'privacidade.painel-ecf')->name('privacidade.painel-ecf');

Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Perfil
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Phase 21 — Manual do Sistema (artigos para usuários não-técnicos).
    // Acesso liberado a TODOS os autenticados — admin, consultor, mentor, gestor MLB,
    // publicador, líder, analista. NÃO inclui tokens públicos /implementacao/*.
    Route::get('/manual',        [ManualController::class, 'index'])->name('manual.index');
    Route::get('/manual/{slug}', [ManualController::class, 'show'])->name('manual.show');

    // Notificações — leitura/contador/marcação (Phase 9 + recentes/abas da Phase 10)
    Route::get('/api/notificacoes/contador',           [NotificacaoController::class, 'contador'])->name('notificacoes.contador');
    Route::get('/api/notificacoes/recentes',           [NotificacaoController::class, 'recentes'])->name('notificacoes.recentes');
    Route::get('/notificacoes',                        [NotificacaoController::class, 'index'])->name('notificacoes.index');
    Route::patch('/notificacoes/{id}/marcar-lida',     [NotificacaoController::class, 'marcarLida'])->name('notificacoes.marcar-lida');
    Route::post('/notificacoes/marcar-todas-lidas',    [NotificacaoController::class, 'marcarTodasLidas'])->name('notificacoes.marcar-todas-lidas');

    // Envio manual de notificação — gated por permission `notificacoes.criar` (Phase 12).
    // Admin sempre tem; líderes ganham via AUTO_LIDERANCA; outros users só com a key explícita em setor_permissoes.
    Route::middleware('permission:notificacoes.criar')->group(function () {
        Route::get('/notificacoes/nova',  [NotificacaoController::class, 'nova'])->name('notificacoes.nova');
        Route::post('/notificacoes/nova', [NotificacaoController::class, 'criar'])->name('notificacoes.criar');
    });

    // Módulo Comercial — cadastro centralizado de empresas (Phase 13)
    // Admin sempre tem acesso via short-circuit isAdmin(); membros do setor Comercial
    // ganham via setor_permissoes com permission_key='comercial.cadastrar_empresa'.
    Route::middleware('permission:comercial.cadastrar_empresa')
         ->prefix('comercial')
         ->name('comercial.')
         ->group(function () {
             // Phase 36 Plan 36-01 (D-01) — listagem antiga descontinuada.
             // /comercial/empresas vira redirect 302 pra /comercial/empresas/novo
             // (cadastro de empresa). Mantém o nome 'comercial.empresas' vivo pra
             // não quebrar links existentes (sidebar, bookmarks). Listagem geral
             // de empresas continua em /companies e /mlb/empresas.
             Route::get('/empresas', function () {
                 return redirect()->route('comercial.empresas.novo');
             })->name('empresas');
             Route::get('/empresas/novo',          [ComercialController::class, 'create'])->name('empresas.novo');
             Route::post('/empresas',              [ComercialController::class, 'store'])->name('empresas.store');
             Route::put('/empresas/{company}',     [ComercialController::class, 'update'])->name('empresas.update');
             Route::delete('/empresas/{company}',  [ComercialController::class, 'destroy'])->name('empresas.destroy');

             // Hotfix 2026-06-19 — editar/desativar contratos a partir da listagem
             // Comercial. Espelha as rotas admin-only de CompanyController mas com
             // permission='comercial.cadastrar_empresa' (mesmo gating do resto do
             // grupo) — Comercial precisa ajustar valor de contrato quando o line
             // item HubSpot vem com valor errado, sem virar admin.
             Route::put('/empresas/{company}/contratos-servico/{contrato}',
                 [ComercialController::class, 'updateContrato']
             )->name('empresas.contratos.update');
             Route::delete('/empresas/{company}/contratos-servico/{contrato}',
                 [ComercialController::class, 'destroyContrato']
             )->name('empresas.contratos.destroy');

             // Phase 36 Plan 36-02 (D-03) — página dedicada de atribuir serviço
             // a uma empresa existente. Migrada de /administrativo/empresas (modal
             // Admin/Empresas.jsx — D-06). Pré-resolve a Company via route model
             // binding; renderiza Inertia Comercial/AtribuirServico com header
             // contextual + histórico de contratos + form de novo contrato com
             // máscara BRL (D-07) e default data_vencimento +1 ano (D-08).
             Route::get('/atribuir-servico/{company}', [ComercialController::class, 'atribuirServico'])
                 ->name('atribuir-servico');

             // Phase 37 Plan 37-05 (REQ-37-05/06/08/10) — Listagem unificada do Comercial.
             // Cobre TODAS as empresas (todos os setores) com filtros snake_case empilháveis,
             // 5 cards de pendência comercial (calculadas apenas para origem HubSpot, REQ-37-10)
             // e aba de Grupos (CRUD via rotas company-groups.* admin-only — non-admin ve read-only).
             Route::get('/empresas/listagem', [ComercialController::class, 'listagem'])
                 ->name('empresas.listagem');
         });

    // Reuniões (todos)
    Route::get('/meetings', [MeetingController::class, 'index'])->name('meetings.index');
    Route::post('/meetings', [MeetingController::class, 'store'])->name('meetings.store');
    Route::put('/meetings/{meeting}', [MeetingController::class, 'update'])->name('meetings.update');
    Route::delete('/meetings/{meeting}', [MeetingController::class, 'destroy'])->name('meetings.destroy');

    // NPS (todos)
    Route::get('/nps', [NpsController::class, 'index'])->name('nps.index');

    // PPA (mentor + admin)
    Route::get('/ppa', [PpaController::class, 'index'])->name('ppa.index');
    Route::post('/ppa', [PpaController::class, 'store'])->name('ppa.store');
    Route::put('/ppa/{ppa}', [PpaController::class, 'update'])->name('ppa.update');
    Route::delete('/ppa/{ppa}', [PpaController::class, 'destroy'])->name('ppa.destroy');
    Route::get('/ppa/{ppa}/kanban', [PpaController::class, 'kanban'])->name('ppa.kanban');
    Route::post('/ppa/{ppa}/workspace-link', [PpaController::class, 'generateWorkspaceLink'])->name('ppa.workspace.generate');

    // PPA Tasks (mentor + admin)
    Route::post('/ppa/{ppa}/tasks', [PpaTaskController::class, 'store'])->name('ppa.tasks.store');
    Route::put('/ppa/tasks/{task}', [PpaTaskController::class, 'update'])->name('ppa.tasks.update');
    Route::delete('/ppa/tasks/{task}', [PpaTaskController::class, 'destroy'])->name('ppa.tasks.destroy');

    // Google Calendar sync (todos os usuários)
    Route::post('/google/sync', [GoogleCalendarController::class, 'sync'])->name('google.sync');
    Route::delete('/google/disconnect', [GoogleCalendarController::class, 'disconnect'])->name('google.disconnect');

    // Ranking de desempenho — quick 260623 — agora gated por permission
    // core.performance (admin tem implicito; lider de Performance via
    // AUTO_LIDERANCA_PERFORMANCE). Antes era role:admin estrito.
    Route::get('/performance', [PerformanceController::class, 'index'])
        ->middleware('permission:core.performance')
        ->name('performance.index');
    Route::get('/performance/{user}', [PerformanceController::class, 'show'])
        ->middleware('permission:core.performance')
        ->name('performance.show');

    // Phase 49 Wave 2 — Ranking de desempenho do setor Publicações (rota própria no menu)
    Route::get('/publicacao/desempenho', [PerformanceController::class, 'indexPublicacao'])
        ->middleware('permission:mlb.dashboard')
        ->name('publicacao.desempenho.index');

    // Phase 46 Plan 46-02 — endpoint JSON da curva de evolucao do score de um user
    // ao longo dos ultimos N dias (default 30, clamp 7..365). Consumido por fetch
    // no frontend (drawer/grafico individual entregue na Wave 3). Mesmo gate da
    // pagina /performance (permission core.performance).
    Route::get('/api/performance/{user}/evolucao', [PerformanceController::class, 'evolucao'])
        ->middleware('permission:core.performance')
        ->name('performance.evolucao');

    // Adman: leitura do ultimo sync (admin apenas).
    // Sync manual via POST /adman/sync REMOVIDO na Phase 16 (SC-5):
    // API Adman e D-1, sync ocorre 1x/dia via scheduler (11h BRT).
    Route::get('/adman/last-sync', [AdmanController::class, 'lastSync'])
        ->middleware('role:admin')
        ->name('adman.last-sync');

    // Metas — leitura para todos, escrita apenas admin
    Route::get('/goals', [GoalController::class, 'index'])->name('goals.index');

    // Carteira (portfólio) — cada usuário vê a própria
    Route::get('/portfolio', [PortfolioController::class, 'own'])->name('portfolio.own');
    // Quick 260623 — portfolio.show acessível pra admin (todos) e líder de setor
    // (apenas users do setor liderado). Autorização granular no controller.
    Route::get('/admin/users/{user}/portfolio', [PortfolioController::class, 'show'])->name('portfolio.show');

    // Quick 260623 — GET /companies + /companies/{c} agora gateados por
    // permission core.empresas (antes role:admin). Lider de Performance ganha
    // a permission via AUTO_LIDERANCA_PERFORMANCE; admin tem implicito.
    // Mutations (PUT/DELETE/POST) ficam no grupo role:admin abaixo.
    Route::middleware('permission:core.empresas')->group(function () {
        Route::get('/companies',            [CompanyController::class, 'index'])->name('companies.index');
        Route::get('/companies/{company}',  [CompanyController::class, 'show'])->name('companies.show');
    });

    // ─── Sugadores ──────────────────────────────────────────────────────────
    // Leitura/escrita gated por SugadorPolicy (admin/gestor/lider veem global;
    // consultor/mentor/analista veem só carteira). Análise on-demand e config
    // restritas a admin (Policy::manage).
    Route::get('/sugadores',                        [SugadorController::class, 'index'])->name('sugadores.index');
    Route::get('/sugadores/{sugador}',              [SugadorController::class, 'show'])->name('sugadores.show');
    Route::get('/sugadores/{sugador}/mlbs',         [SugadorController::class, 'mlbs'])->name('sugadores.mlbs');
    // Phase 30 Plan 30-04 — Botão "Forçar atualização" do drilldown.
    Route::post('/sugadores/refresh-adgroup-mlbs',  [SugadorController::class, 'refreshAdgroupMlbs'])->name('sugadores.refresh-adgroup-mlbs');
    Route::patch('/sugadores/{sugador}/status',     [SugadorController::class, 'updateStatus'])->name('sugadores.update-status');
    Route::post('/sugadores/{sugador}/move',        [SugadorController::class, 'move'])->name('sugadores.move');
    Route::post('/sugadores/bulk-move',             [SugadorController::class, 'bulkMove'])->name('sugadores.bulk-move');
    Route::post('/sugadores/analyze',               [SugadorController::class, 'analyzeAll'])->name('sugadores.analyze-all');
    Route::post('/sugadores/companies/{company}/analyze', [SugadorController::class, 'analyzeCompany'])->name('sugadores.analyze-company');
    Route::get('/sugadores/companies/{company}/sgi-campaigns', [SugadorController::class, 'sgiCampaigns'])->name('sugadores.sgi-campaigns');
    // Phase 19 W1-T4 — endpoint agregado de MLBs por empresa (modo cards, botão "Copiar MLBs").
    // Reusa Cache::lock por custId do W1-T3; 1º adgroup paga o custo MCP, demais leem cache.
    Route::get('/sugadores/companies/{company}/mlbs-todos', [SugadorController::class, 'mlbsByCompany'])->name('sugadores.mlbs-by-company');

    // Config de detecção por empresa (admin/gestor/lider via Policy::manage).
    // Rota antiga /companies/.../sugador-config mantida (compat); rota nova
    // /sugadores/configs/{company} é a entrada via a aba Sugadores (analistas
    // não têm acesso à aba Empresas).
    Route::get('/sugadores/configs/{company}',        [SugadorConfigController::class, 'show'])->name('sugadores.config.show');
    Route::get('/companies/{company}/sugador-config', [SugadorConfigController::class, 'show'])->name('sugadores.config.show-legacy');
    Route::put('/companies/{company}/sugador-config', [SugadorConfigController::class, 'update'])->name('sugadores.config.update');

    Route::middleware('role:admin')->group(function () {
        // Log de atividades
        Route::get('/activity-log', [ActivityLogController::class, 'index'])->name('activity-log.index');

        // Dev — area interna de projetos em desenvolvimento
        Route::get('/dev/desenvolvimento', [DevController::class, 'index'])
            ->name('dev.desenvolvimento');
        // Re-sync manual de empresa Adman via diagnóstico (admin only)
        Route::post('/dev/resync', [DevController::class, 'resyncCompany'])
            ->name('dev.resync');

        // Phase 41 Plan 41-05 — UI admin de onboarding ML por empresa.
        // 4 rotas (1 GET + 3 POST). Acoes inline disparam comandos async via
        // Artisan::queue (sugadores:ml-smoke, sugadores:shadow-ml) e toggle do
        // shadow_enabled em sugador_ml_company_config (Plan 41-01).
        Route::prefix('dev/sugadores-ml-onboarding')->name('dev.sugadores_ml_onboarding.')->group(function () {
            Route::get('/',                                       [SugadoresMlOnboardingController::class, 'index'])->name('index');
            Route::get('/{company}',                              [SugadoresMlOnboardingController::class, 'show'])->name('show');
            Route::get('/{company}/adgroups/{adgroupId}/mlbs',    [SugadoresMlOnboardingController::class, 'adgroupMlbs'])->name('adgroup_mlbs');
            Route::post('/{company}/smoke',                       [SugadoresMlOnboardingController::class, 'runSmoke'])->name('smoke');
            Route::post('/{company}/shadow',                      [SugadoresMlOnboardingController::class, 'runShadow'])->name('shadow');
            Route::post('/{company}/toggle-shadow',               [SugadoresMlOnboardingController::class, 'toggleShadow'])->name('toggle_shadow');
        });

        Route::post('/goals', [GoalController::class, 'store'])->name('goals.store');
        Route::put('/goals/{goal}', [GoalController::class, 'update'])->name('goals.update');
        Route::delete('/goals/{goal}', [GoalController::class, 'destroy'])->name('goals.destroy');

        // Grants (admin only) — cadastro manual removido; lista vem do ML via SFTP
        Route::get('/grants', [GrantController::class, 'index'])->name('grants.index');
        Route::post('/grants/sync', [GrantController::class, 'syncNow'])->name('grants.sync');
        Route::get('/grants/sync/status', [GrantController::class, 'syncStatus'])->name('grants.sync.status');
        Route::put('/grants/{grant}', [GrantController::class, 'update'])->name('grants.update');
        Route::delete('/grants/{grant}', [GrantController::class, 'destroy'])->name('grants.destroy');
        Route::post('/grants/{grant}/regrant', [GrantController::class, 'regrant'])->name('grants.regrant');

        // Carteira por profissional (admin) — CRUD de PortfolioGoals fica restrito
        // ao grupo admin; portfolio.show (a tela em si) foi movida pra fora porque
        // líder de setor também precisa acessar carteira de membros do seu setor
        // (quick 260623). A autorização granular acontece em PortfolioController::show.
        Route::post('/admin/users/{user}/portfolio-goals', [PortfolioController::class, 'storeGoal'])->name('portfolio.goals.store');
        Route::put('/portfolio-goals/{goal}', [PortfolioController::class, 'updateGoal'])->name('portfolio.goals.update');
        Route::delete('/portfolio-goals/{goal}', [PortfolioController::class, 'destroyGoal'])->name('portfolio.goals.destroy');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::post('/users/{id}/restore', [UserController::class, 'restore'])->name('users.restore');
        Route::delete('/users/{id}/force', [UserController::class, 'forceDestroy'])->name('users.force-destroy');

        // GET /companies + /companies/{c} migrados pra grupo permission:core.empresas
        // acima (quick 260623) — lider de Performance precisa acessar.
        // Cadastro de empresa removido de /companies — entrada é exclusiva por /comercial/empresas.
        Route::put('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
        Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])->name('companies.destroy');
        // Reativa empresa desativada pelo Comercial (active=false → true)
        Route::post('/companies/{company}/ativar', [CompanyController::class, 'ativar'])->name('companies.ativar');
        // Phase 34 Plan 34-01 (D-06) — "Marcar como visto" remove o badge "Empresa nova".
        Route::post('/companies/{company}/marcar-visto', [CompanyController::class, 'marcarVisto'])->name('companies.marcar-visto');
        // Atribui/remove a empresa de um grupo nomeado (sem efeitos colaterais do update completo)
        Route::put('/companies/{company}/group', [CompanyController::class, 'setGroup'])->name('companies.set-group');
        // Ações em massa da aba Pendências (excluir / atribuir analista|estrategista)
        Route::post('/companies/bulk-destroy', [CompanyController::class, 'bulkDestroy'])->name('companies.bulk-destroy');
        Route::post('/companies/bulk-assign', [CompanyController::class, 'bulkAssign'])->name('companies.bulk-assign');

        // Grupos nomeados de empresas (tipo carteira) — gestão a partir de /companies
        Route::post('/company-groups', [CompanyGroupController::class, 'store'])->name('company-groups.store');
        Route::put('/company-groups/{group}', [CompanyGroupController::class, 'update'])->name('company-groups.update');
        Route::delete('/company-groups/{group}', [CompanyGroupController::class, 'destroy'])->name('company-groups.destroy');
        // Atribui um serviço (contrato) a todas as empresas do grupo
        Route::post('/company-groups/{group}/atribuir-servico', [CompanyGroupController::class, 'atribuirServico'])->name('company-groups.atribuir-servico');

        // ML OAuth — painel dedicado + ações por empresa (admin only, herdado do grupo)
        Route::get('/ml-oauth',                            [MercadoLivreOAuthController::class, 'adminIndex'])->name('ml.oauth.index');
        Route::post('/companies/{company}/ml/initiate',    [MercadoLivreOAuthController::class, 'initiate'])->name('ml.oauth.initiate');
        Route::delete('/companies/{company}/ml/disconnect',[MercadoLivreOAuthController::class, 'disconnect'])->name('ml.oauth.disconnect');
        Route::post('/companies/{company}/ml/sync-now',   [MercadoLivreOAuthController::class, 'syncNow'])->name('ml.sync.now');
        // Sync global: dispara fan-out D-1 de todas as empresas com token ML ativo
        Route::post('/ml-oauth/sync-all',                  [MercadoLivreOAuthController::class, 'syncAll'])->name('ml.oauth.sync-all');

        // ─── Módulo Serviços (Frente A) ──────────────────────────────────
        // Catálogo de serviços + contratos por empresa. Acesso admin-only
        // herdado do grupo pai. Frente B fará data migration + drop legacy.
        Route::resource('servicos', ServicoController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        Route::post('/empresas/{company}/contratos-servico',                  [CompanyController::class, 'storeContrato'])->name('empresas.contratos.store');
        Route::put('/empresas/{company}/contratos-servico/{contrato}',        [CompanyController::class, 'updateContrato'])->name('empresas.contratos.update');
        Route::delete('/empresas/{company}/contratos-servico/{contrato}',     [CompanyController::class, 'destroyContrato'])->name('empresas.contratos.destroy');

        // ─── Phase 37 Plan 37-07 (REQ-37-02) ─────────────────────────────────
        // UI admin para mapping HubSpot line_item → Servico. Consumido pelo
        // HubspotWebhookController (Plan 37-04) via HubspotLineItemMapping::paraNome.
        // Admin pode cadastrar/editar mapeamentos novos sem precisar de deploy
        // quando o Comercial cria nomes de line item novos no HubSpot.
        Route::get   ('/sistema/hubspot-line-items',            [HubspotLineItemMappingController::class, 'index'])  ->name('sistema.hubspot-line-items.index');
        Route::post  ('/sistema/hubspot-line-items',            [HubspotLineItemMappingController::class, 'store'])  ->name('sistema.hubspot-line-items.store');
        Route::put   ('/sistema/hubspot-line-items/{mapping}',  [HubspotLineItemMappingController::class, 'update']) ->name('sistema.hubspot-line-items.update');
        Route::delete('/sistema/hubspot-line-items/{mapping}',  [HubspotLineItemMappingController::class, 'destroy'])->name('sistema.hubspot-line-items.destroy');
    });
});

// ─── Alertas Estratégicos (Phase 23) ────────────────────────────────────────
// Consome /signals da API ECF Drive (Phase 22 wrapper). Caixa de entrada
// do comercial — acessível a admin, consultor e mentor (CONTEXT D-04).
// EnsureUserHasRole aceita varargs via separador '|' (D-01 do PLAN).
Route::middleware(['auth', 'verified', 'role:admin,consultor,mentor'])
     ->prefix('alertas-estrategicos')
     ->name('alertas.')
     ->group(function () {
         Route::get('/',           [AlertasController::class, 'index'])->name('index');
         Route::post('/{id}/ack',  [AlertasController::class, 'ack'])->name('ack');
     });

// ─── Painel Executivo Carteira ECF (Phase 24) ─────────────────────────────────
// Consome /carteira/* da API ECF Drive (Phase 22 wrapper). Dashboard
// estratégico complementar ao /dashboard operacional. Apenas admin
// (CONTEXT D-02).
Route::middleware(['auth', 'verified', 'role:admin'])
     ->prefix('painel-executivo')
     ->name('painel-executivo.')
     ->group(function () {
         Route::get('/', [PainelExecutivoController::class, 'index'])->name('index');
     });

// ─── Concentração de Receita e Forecast 90d (Phase 27) ──────────────────────
// Análise estratégica complementar ao Painel Executivo (Phase 24).
// Matriz programa × cluster + forecast 90d + vacas leiteiras silenciosas.
// Apenas admin — visão estratégica restrita (CONTEXT D-01).
Route::middleware(['auth', 'verified', 'role:admin'])
     ->group(function () {
         Route::get('/concentracao', [ConcentracaoController::class, 'show'])->name('concentracao.index');
     });

// ─── Polos — Faturamento por Polo vs Meta (Phase 38) ─────────────────────────
// Consome o CSV POLOS MENSAL via EcfDriveService (listFiles + fileJson).
// Agrega TGMV_LC por LOCALIDADE, calcula meta = ativos × R$/empresa (default 3000,
// configurável por polo via Configuracao). Grade de donuts por polo. Apenas admin.
Route::middleware(['auth', 'verified', 'role:admin'])
     ->prefix('polos')
     ->name('polos.')
     ->group(function () {
         Route::get('/', [PolosController::class, 'index'])->name('index');
         // Visão completa em tabela (abre em aba própria).
         Route::get('/empresas', [PolosController::class, 'todasEmpresas'])->name('empresas');
         // Botão "Sincronizar": aquece o cache da Adman do mês selecionado (background).
         Route::post('/sync', [PolosController::class, 'sync'])->name('sync');
         // Detalhe semanal de 1 empresa (AJAX, sob demanda ao clicar no card).
         Route::get('/empresa/{cust}/semanal', [PolosController::class, 'semanal'])->name('empresa.semanal');
     });

// ─── Análise por Empresa via ECF Drive (Phase 25) ────────────────────────────
// Ficha 360° de 1 empresa. Consome /sellers/{custId}/* da API ECF Drive
// (Phase 22 wrapper). Acessível a admin, consultor e mentor (CONTEXT D-03).
// Auth fina por carteira é feita inline no controller (abort 403).
Route::middleware(['auth', 'verified', 'role:admin,consultor,mentor'])
     ->group(function () {
         Route::get('/empresas/{company}/analise-ecf',
             [EmpresaAnaliseEcfController::class, 'show'])
             ->name('empresas.analise-ecf');
     });

// ─── Módulo MLB — Controle de Publicações ────────────────────────────────────
Route::middleware(['auth', 'verified'])->prefix('mlb')->name('mlb.')->group(function () {
    Route::get('/dashboard',      [MlbController::class, 'dashboard'])->name('dashboard');
    Route::get('/projetos',       [MlbController::class, 'projetos'])->name('projetos');
    // Visão de empresas POLOS agrupadas por fase M (grid de cards, item do grupo Polos no menu)
    Route::get('/polos-empresas', [MlbController::class, 'polosEmpresas'])->name('polos-empresas');
    Route::get('/treinamentos',   [MlbController::class, 'treinamentos'])->name('treinamentos');
    Route::post('/treinamentos',  [MlbController::class, 'storeTreinamento'])->name('treinamentos.store');
    Route::put('/treinamentos/{treinamento}',    [MlbController::class, 'updateTreinamento'])->name('treinamentos.update');
    Route::delete('/treinamentos/{treinamento}', [MlbController::class, 'destroyTreinamento'])->name('treinamentos.destroy');
    Route::post('/config',        [MlbController::class, 'salvarConfig'])->name('config');
    Route::get('/meu-painel',  [MlbController::class, 'meuPainel'])->name('meu-painel');
    Route::get('/publicacoes',  [MlbController::class, 'publicacoes'])->name('publicacoes');
    Route::post('/publicacoes', [MlbController::class, 'store'])->name('store');
    Route::get('/vendas',       [MlbController::class, 'vendas'])->name('vendas');
    Route::post('/sync-vendas-pub', [MlbController::class, 'syncVendasPublicador'])->name('sync-vendas-pub');
    Route::get('/historico',   [MlbController::class, 'historico'])->name('historico');
    Route::get('/revisao',     [MlbController::class, 'revisao'])->name('revisao');

    Route::patch('/pub/{pub}/vendido',           [MlbController::class, 'marcarVendido'])->name('vendido');
    Route::patch('/pub/{pub}/revisado',          [MlbController::class, 'marcarRevisado'])->name('revisado');
    Route::patch('/pub/{pub}/comentario',        [MlbController::class, 'salvarComentario'])->name('comentario');
    Route::patch('/pub/{pub}/resolver',          [MlbController::class, 'resolverComentario'])->name('resolver');
    Route::patch('/pub/{pub}/problema',          [MlbController::class, 'marcarProblema'])->name('problema');
    Route::delete('/pub/{pub}',                  [MlbController::class, 'destroy'])->name('destroy');

    // Empresas MLB (Analista / Líder / Gestor)
    Route::get('/empresas',                       [MlbController::class, 'empresas'])->name('empresas');
    Route::post('/empresas',                      [MlbController::class, 'storeEmpresa'])->name('empresas.store');
    Route::post('/empresas/pendente/{company}/ativar', [MlbController::class, 'ativarEmpresaPendente'])->name('empresas.pendente.ativar');
    Route::put('/empresas/{empresa}',             [MlbController::class, 'updateEmpresa'])->name('empresas.update');
    Route::delete('/empresas/{empresa}',          [MlbController::class, 'destroyEmpresa'])->name('empresas.destroy');
    Route::patch('/empresas/{empresa}/sku',       [MlbController::class, 'marcarSku'])->name('empresas.sku');
    Route::post('/opcao-campo',                   [MlbController::class, 'storeOpcaoCampo'])->name('opcao-campo.store');
    Route::delete('/opcao-campo',                 [MlbController::class, 'destroyOpcaoCampo'])->name('opcao-campo.destroy');
    Route::patch('/empresas/{empresa}/problema',  [MlbController::class, 'marcarProblemaEmpresa'])->name('empresas.problema');
    Route::post('/empresas/{empresa}/sync-vendas', [MlbController::class, 'syncVendasAdman'])->name('empresas.sync-vendas');
    Route::get('/empresas/{empresa}/debug-sync',  [MlbController::class, 'debugSyncVendas'])->name('empresas.debug-sync');
    Route::post('/sync-vendas',                   [MlbController::class, 'syncTodasVendasAdman'])->name('sync-vendas');

    // Metas por mês (gestor/admin)
    Route::get('/metas',           [MlbController::class, 'metasIndex'])->name('metas.index');
    Route::post('/metas',          [MlbController::class, 'storeMeta'])->name('metas.store');
    Route::delete('/metas/{id}',   [MlbController::class, 'destroyMeta'])->name('metas.destroy');

    // Coleta de dados ML (inteligência de anúncios) — Phase 17
    Route::get('/coleta',             [MlbController::class, 'coletaIndex'])->name('coleta.index');
    Route::post('/coleta',            [MlbController::class, 'coletaStore'])->name('coleta.store');
    Route::get('/coleta/{id}',        [MlbController::class, 'coletaShow'])->name('coleta.show');
    Route::get('/coleta/{id}/status', [MlbController::class, 'coletaStatus'])->name('coleta.status');

    // Implementação MLB (admin)
    // ATENÇÃO: /implementacao/indicadores DEVE vir antes de qualquer rota com {impl}
    // para evitar que Laravel capture "indicadores" como parâmetro (Pitfall 2 do RESEARCH).
    Route::get('/implementacao/indicadores',                  [MlbImplementacaoController::class, 'indicadores'])->name('implementacao.indicadores');
    Route::get('/implementacao',                              [MlbImplementacaoController::class, 'index'])->name('implementacao.index');
    Route::post('/implementacao',                             [MlbImplementacaoController::class, 'criar'])->name('implementacao.criar');
    Route::post('/implementacao-padroes',                     [MlbImplementacaoController::class, 'salvarPadroes'])->name('implementacao.padroes');

    // ─── Ficha de Onboarding (Frente 3 — ONB-02/05/06) ──────────────────────
    // {impl}/ficha vem DEPOIS de /indicadores para não capturar "indicadores" como {impl}.
    Route::get  ('/implementacao/{impl}/ficha',               [MlbImplementacaoController::class, 'ficha'])->name('implementacao.ficha');
    Route::patch('/implementacao/{impl}/bloco/identificacao', [MlbImplementacaoController::class, 'salvarBlocoIdentificacao'])->name('implementacao.bloco.identificacao');
    Route::patch('/implementacao/{impl}/bloco/acessos',       [MlbImplementacaoController::class, 'salvarBlocoAcessos'])->name('implementacao.bloco.acessos');
    Route::patch('/implementacao/{impl}/bloco/produtos',      [MlbImplementacaoController::class, 'salvarBlocoProdutos'])->name('implementacao.bloco.produtos');
    Route::patch('/implementacao/{impl}/bloco/logistica',     [MlbImplementacaoController::class, 'salvarBlocoLogistica'])->name('implementacao.bloco.logistica');

    Route::post('/implementacao/{empresa}/gerar',             [MlbImplementacaoController::class, 'gerarLink'])->name('implementacao.gerar');
    Route::post('/implementacao/{impl}/tutoriais',            [MlbImplementacaoController::class, 'atualizarTutoriais'])->name('implementacao.tutoriais');
    Route::post('/implementacao/{impl}/sincronizar-skus',     [MlbImplementacaoController::class, 'sincronizarSkus'])->name('implementacao.sincronizar-skus');
    Route::delete('/implementacao/{impl}',                    [MlbImplementacaoController::class, 'destroy'])->name('implementacao.destroy');

    // ─── Rastreio de envio do link e responsável (ONB-ENVIO-LINK / ONB-RESPONSAVEL) ──
    Route::post ('/implementacao/{impl}/marcar-enviado', [MlbImplementacaoController::class, 'marcarLinkEnviado'])->name('implementacao.marcar-enviado');
    Route::post ('/implementacao/{impl}/desfazer-envio', [MlbImplementacaoController::class, 'desfazerEnvio'])->name('implementacao.desfazer-envio');
    Route::patch('/implementacao/{impl}/responsavel',    [MlbImplementacaoController::class, 'atribuirResponsavel'])->name('implementacao.responsavel');

    // ─── Card "Dados do ML" na ficha de Onboarding (Phase 36) ───────────────────
    // Endpoint async — chamado pelo front via fetch após a ficha carregar.
    // NÃO usa Inertia — retorna JSON diretamente (ONB-12/13/14).
    Route::get('/implementacao/{impl}/dados-ml',
        [MlbImplementacaoController::class, 'dadosMl'])
        ->name('implementacao.dados-ml');
});

// ─── Módulo Administrativo ───────────────────────────────────────────────────
Route::middleware(['auth', 'verified', 'role:admin'])->prefix('administrativo')->name('admin.')->group(function () {
    Route::get('/empresas',              [AdminController::class, 'empresas'])->name('empresas');
    Route::patch('/empresas/{company}',  [AdminController::class, 'updateEmpresa'])->name('empresas.update');
    Route::get('/relatorio',  [AdminController::class, 'relatorio'])->name('relatorio');
    // ATENÇÃO: rotas específicas de /configuracoes/financeiro e /financeiro/relatorio-geral/enviar
    // devem vir ANTES de /financeiro/{company} para evitar colisão com o parâmetro dinâmico.
    Route::get('/configuracoes/financeiro',  [AdminController::class, 'configuracoesFinanceiro'])->name('configuracoes.financeiro');
    Route::post('/configuracoes/financeiro', [AdminController::class, 'salvarConfiguracoesFinanceiro'])->name('configuracoes.financeiro.salvar');
    Route::get('/financeiro',                         [AdminController::class, 'fechamento'])->name('financeiro');
    Route::get('/financeiro/relatorio-geral',         [AdminController::class, 'gerarRelatorioGeral'])->name('financeiro.relatorio.geral');
    Route::post('/financeiro/relatorio-geral/enviar', [AdminController::class, 'enviarRelatorioGeral'])->name('financeiro.relatorio.enviar');
    Route::post('/financeiro/sync-faturamento',       [AdminController::class, 'syncFaturamento'])->name('financeiro.sync');
    Route::patch('/financeiro/{company}',             [AdminController::class, 'updateFechamento'])->name('financeiro.update');
    Route::post('/financeiro/{company}/recebido',     [AdminController::class, 'toggleRecebido'])->name('financeiro.recebido');
    Route::get('/financeiro/{company}/relatorio',     [AdminController::class, 'gerarRelatorio'])->name('financeiro.relatorio');
    Route::get('/inventario',              [AdminController::class, 'inventario'])->name('inventario');

    // ── Setores / Cargos / Permissões / Líderes / Membros / Metas ────────────
    Route::get   ('/setores',                                  [SetorController::class, 'index'])  ->name('setores.index');
    Route::post  ('/setores',                                  [SetorController::class, 'store'])  ->name('setores.store');
    Route::get   ('/setores/{setor}',                          [SetorController::class, 'show'])   ->name('setores.show');
    Route::put   ('/setores/{setor}',                          [SetorController::class, 'update']) ->name('setores.update');
    Route::delete('/setores/{setor}',                          [SetorController::class, 'destroy'])->name('setores.destroy');
    Route::put   ('/setores/{setor}/permissoes',               [SetorController::class, 'syncPermissoes'])->name('setores.permissoes.sync');

    Route::post  ('/setores/{setor}/cargos',                   [CargoController::class, 'store'])  ->name('setores.cargos.store');
    Route::put   ('/cargos/{cargo}',                           [CargoController::class, 'update']) ->name('cargos.update');
    Route::delete('/cargos/{cargo}',                           [CargoController::class, 'destroy'])->name('cargos.destroy');

    Route::post  ('/setores/{setor}/membros',                  [SetorMembroController::class, 'storeMembro'])  ->name('setores.membros.store');
    Route::delete('/setores/{setor}/membros/{user}',           [SetorMembroController::class, 'destroyMembro'])->name('setores.membros.destroy');
    Route::post  ('/setores/{setor}/lideres',                  [SetorMembroController::class, 'storeLider'])  ->name('setores.lideres.store');
    Route::delete('/setores/{setor}/lideres/{user}',           [SetorMembroController::class, 'destroyLider'])->name('setores.lideres.destroy');

    Route::post  ('/setores/{setor}/metas',                    [SetorGoalController::class, 'store'])  ->name('setores.metas.store');
    Route::put   ('/setores-metas/{meta}',                     [SetorGoalController::class, 'update']) ->name('setores.metas.update');
    Route::delete('/setores-metas/{meta}',                     [SetorGoalController::class, 'destroy'])->name('setores.metas.destroy');
});

// ─── Liderança (acesso: admin ou líder de pelo menos 1 setor) ────────────────
Route::middleware(['auth', 'verified', 'permission:lideranca.dashboard_setor'])->prefix('lideranca')->name('lideranca.')->group(function () {
    Route::get('/setor', [LiderancaController::class, 'indexOrFirst'])->name('index');
    Route::get('/setor/{setor:slug}', [LiderancaController::class, 'show'])->name('setor');
});

require __DIR__.'/auth.php';

