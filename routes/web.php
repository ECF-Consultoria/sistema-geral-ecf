<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AdmanController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DevController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\MlbController;
use App\Http\Controllers\MlbImplementacaoController;
use App\Http\Controllers\GoogleCalendarController;
use App\Http\Controllers\GrantController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\NpsController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\PpaController;
use App\Http\Controllers\PpaTaskController;
use App\Http\Controllers\ProfileController;
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

// PPA Workspace público (sem autenticação) — cliente acessa pelo token
Route::get('/ppa/workspace/{token}', [PpaController::class, 'workspace'])->name('ppa.workspace');
Route::patch('/ppa/workspace/{token}/tasks/{task}', [PpaTaskController::class, 'clientUpdate'])->name('ppa.workspace.task.update');

// Implementação MLB público (sem autenticação) — cliente preenche via token
Route::get('/implementacao/{token}', [MlbImplementacaoController::class, 'workspace'])->name('implementacao.workspace');
Route::patch('/implementacao/{token}', [MlbImplementacaoController::class, 'salvarItem'])->name('implementacao.salvar');

// Visão do publicador (sem autenticação, somente leitura)
Route::get('/implementacao/{token}/publicador', [MlbImplementacaoController::class, 'publicador'])->name('implementacao.publicador');

// Google OAuth (público — sem autenticação durante o callback)
Route::get('/google/connect', [GoogleCalendarController::class, 'connect'])
    ->middleware(['auth', 'verified'])
    ->name('google.connect');
Route::get('/google/callback', [GoogleCalendarController::class, 'callback'])->name('google.callback');

// Gerar link NPS (DEVE ficar antes da rota pública /nps/{token} para não colidir)
Route::post('/nps/generate', [NpsController::class, 'generate'])
    ->middleware(['auth', 'verified'])
    ->name('nps.generate');

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

    // Adman sync manual (admin apenas)
    Route::post('/adman/sync', [AdmanController::class, 'syncNow'])
        ->middleware('role:admin')
        ->name('adman.sync');
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
    Route::patch('/sugadores/{sugador}/status',     [SugadorController::class, 'updateStatus'])->name('sugadores.update-status');
    Route::post('/sugadores/analyze',               [SugadorController::class, 'analyzeAll'])->name('sugadores.analyze-all');
    Route::post('/sugadores/companies/{company}/analyze', [SugadorController::class, 'analyzeCompany'])->name('sugadores.analyze-company');

    // Config de detecção por empresa (admin only via Policy::manage)
    Route::get('/companies/{company}/sugador-config', [SugadorConfigController::class, 'show'])->name('sugadores.config.show');
    Route::put('/companies/{company}/sugador-config', [SugadorConfigController::class, 'update'])->name('sugadores.config.update');

    Route::middleware('role:admin')->group(function () {
        // Log de atividades
        Route::get('/activity-log', [ActivityLogController::class, 'index'])->name('activity-log.index');

        // Dev — area interna de projetos em desenvolvimento
        Route::get('/dev/desenvolvimento', [DevController::class, 'index'])
            ->name('dev.desenvolvimento');
        Route::post('/dev/adman/{company}/sync', [DevController::class, 'dispatchSync'])
            ->name('dev.adman.sync');

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
        Route::delete('/users-opcao-setor', [UserController::class, 'destroyOpcaoSetor'])->name('users.opcao-setor.destroy');

        Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
        Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
        Route::get('/companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
        Route::put('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
        Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])->name('companies.destroy');
    });
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
    Route::get('/empresas',   [AdminController::class, 'empresas'])->name('empresas');
    Route::get('/relatorio',  [AdminController::class, 'relatorio'])->name('relatorio');
    Route::get('/financeiro',                      [AdminController::class, 'fechamento'])->name('financeiro');
    Route::patch('/financeiro/{company}',          [AdminController::class, 'updateFechamento'])->name('financeiro.update');
    Route::post('/financeiro/{company}/recebido',  [AdminController::class, 'toggleRecebido'])->name('financeiro.recebido');
    Route::get('/inventario',              [AdminController::class, 'inventario'])->name('inventario');
});

require __DIR__.'/auth.php';

