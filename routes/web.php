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
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\PpaController;
use App\Http\Controllers\PpaTaskController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ServicoController;
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
             Route::get('/empresas',               [ComercialController::class, 'empresas'])->name('empresas');
             Route::get('/empresas/novo',          [ComercialController::class, 'create'])->name('empresas.novo');
             Route::post('/empresas',              [ComercialController::class, 'store'])->name('empresas.store');
             Route::put('/empresas/{company}',     [ComercialController::class, 'update'])->name('empresas.update');
             Route::delete('/empresas/{company}',  [ComercialController::class, 'destroy'])->name('empresas.destroy');
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

    // Ranking de desempenho (admin apenas)
    Route::get('/performance', [PerformanceController::class, 'index'])
        ->middleware('role:admin')
        ->name('performance.index');
    Route::get('/performance/{user}', [PerformanceController::class, 'show'])
        ->middleware('role:admin')
        ->name('performance.show');

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

        // Carteira por profissional (admin)
        Route::get('/admin/users/{user}/portfolio', [PortfolioController::class, 'show'])->name('portfolio.show');
        Route::post('/admin/users/{user}/portfolio-goals', [PortfolioController::class, 'storeGoal'])->name('portfolio.goals.store');
        Route::put('/portfolio-goals/{goal}', [PortfolioController::class, 'updateGoal'])->name('portfolio.goals.update');
        Route::delete('/portfolio-goals/{goal}', [PortfolioController::class, 'destroyGoal'])->name('portfolio.goals.destroy');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::post('/users/{id}/restore', [UserController::class, 'restore'])->name('users.restore');
        Route::delete('/users/{id}/force', [UserController::class, 'forceDestroy'])->name('users.force-destroy');

        Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
        // Cadastro de empresa removido de /companies — entrada é exclusiva por /comercial/empresas.
        Route::get('/companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
        Route::put('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
        Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])->name('companies.destroy');
        // Reativa empresa desativada pelo Comercial (active=false → true)
        Route::post('/companies/{company}/ativar', [CompanyController::class, 'ativar'])->name('companies.ativar');
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
    Route::get('/implementacao/indicadores',                  [MlbImplementacaoController::class, 'indicadores'])->name('implementacao.indicadores');
    Route::get('/implementacao',                              [MlbImplementacaoController::class, 'index'])->name('implementacao.index');
    Route::post('/implementacao',                             [MlbImplementacaoController::class, 'criar'])->name('implementacao.criar');
    Route::post('/implementacao-padroes',                     [MlbImplementacaoController::class, 'salvarPadroes'])->name('implementacao.padroes');
    Route::post('/implementacao/{empresa}/gerar',             [MlbImplementacaoController::class, 'gerarLink'])->name('implementacao.gerar');
    Route::post('/implementacao/{impl}/tutoriais',            [MlbImplementacaoController::class, 'atualizarTutoriais'])->name('implementacao.tutoriais');
    Route::post('/implementacao/{impl}/sincronizar-skus',     [MlbImplementacaoController::class, 'sincronizarSkus'])->name('implementacao.sincronizar-skus');
    Route::delete('/implementacao/{impl}',                    [MlbImplementacaoController::class, 'destroy'])->name('implementacao.destroy');
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

