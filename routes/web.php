<?php

use Inertia\Inertia;
use App\Http\Controllers\ContratoAdminController;
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
use App\Http\Controllers\DesempenhoMetricasManuaisController;
use App\Http\Controllers\Dev\SugadoresMlOnboardingController;
use App\Http\Controllers\DevController;
use App\Http\Controllers\DevModulosController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\LiderancaController;
use App\Http\Controllers\MlbController;
use App\Http\Controllers\MlbImplementacaoController;
use App\Http\Controllers\GoogleCalendarController;
use App\Http\Controllers\MercadoLivreOAuthController;
use App\Http\Controllers\ShopeeOAuthController;
use App\Http\Controllers\GrantController;
use App\Http\Controllers\ManualController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\NotificacaoController;
use App\Http\Controllers\NpsCicloController;
use App\Http\Controllers\NpsController;
use App\Http\Controllers\NpsEnvioAutomaticoController;
use App\Http\Controllers\NpsGrupoController;
use App\Http\Controllers\NpsTemplateController;
use App\Http\Controllers\NpsTemplateOptionController;
use App\Http\Controllers\NpsTemplateQuestionController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OnboardingPublicoController;
use App\Http\Controllers\PainelExecutivoController;
use App\Http\Controllers\PortalAuthController;
use App\Http\Controllers\PortalClienteController;
use App\Http\Controllers\PpaColunaController;
use App\Http\Controllers\PortalPpaController;
use App\Http\Controllers\PortalUsuarioController;
use App\Http\Controllers\PolosController;
use App\Http\Controllers\PolosPpaController;
use App\Http\Controllers\BonusAuditoriaController;
use App\Http\Controllers\RelatorioBonificacaoController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\PpaController;
use App\Http\Controllers\PpaTaskController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ServicoController;
use App\Http\Controllers\ShopeeEmpresasController;
use App\Http\Controllers\Sistema\HubspotLineItemMappingController;
use App\Http\Controllers\SugadorConfigController;
use App\Http\Controllers\SugadorController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Redireciona raiz para dashboard
// A raiz serve os dois domínios. No do Portal do Cliente, mandar para o
// `dashboard` levaria o cliente à tela de login do sistema interno — que é
// exatamente o que o `RestringeDominioDoPortal` existe para impedir. Lá ele
// recebe a página de entrada do portal.
Route::get('/', function () {
    $dominio = config('portal.dominio_cliente');

    if ($dominio && request()->getHost() === $dominio) {
        return redirect()->route('portal.entrada');
    }

    return redirect()->route('dashboard');
})->name('raiz');

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

// ─── Receiver de webhooks Clicksign (Fase 129 Plano 129-03) ──────────────────
// URL de produção: https://admin.ecfconsultoria.com.br/api/webhooks/clicksign
// Autenticação via HMAC (header Content-Hmac, fórmula MEDIDA no gate A1 —
// ver App\Support\Clicksign\ClicksignHmacVarredura::FORMULA_CONFIRMADA).
// CSRF isento por bootstrap/app.php (api/webhooks/*) + withoutMiddleware
// (defensivo, mesma disciplina de /api/webhooks/hubspot).
// Rate limit: throttle:60,1 (defesa contra spam; o trabalho pesado nem
// chega a rodar na janela síncrona — vai para ProcessarEventoClicksignJob).
Route::post('/api/webhooks/clicksign', [\App\Http\Controllers\Api\ClicksignWebhookController::class, 'receive'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->middleware('throttle:60,1')
    ->name('webhooks.clicksign');

// ─── Download do PDF assinado (Fase 129 Plano 129-06, CLICK-11, D-13) ────────
// O arquivo é evidência jurídica: vive em disco PRIVADO (storage/app) e só
// sai do servidor por esta rota, com login de admin. `role:admin` é o
// controle CORRETO até a permissão dedicada `admin.contratos` (UI-05) existir
// na Fase 131 — não é um atalho provisório, é a trava certa hoje.
Route::get('/admin/contratos/{contratoAssinatura}/pdf-assinado', [\App\Http\Controllers\ContratoPdfAssinadoController::class, 'download'])
    ->middleware(['auth', 'role:admin'])
    ->name('contratos.pdf-assinado');

// PPA Workspace público (sem autenticação) — cliente acessa pelo token
Route::get('/ppa/workspace/{token}', [PpaController::class, 'workspace'])->name('ppa.workspace');
Route::patch('/ppa/workspace/{token}/tasks/{task}', [PpaTaskController::class, 'clientUpdate'])->name('ppa.workspace.task.update');

// Implementação MLB público (sem autenticação) — cliente preenche via token
Route::get('/implementacao/{token}', [MlbImplementacaoController::class, 'workspace'])->name('implementacao.workspace');
Route::patch('/implementacao/{token}', [MlbImplementacaoController::class, 'salvarItem'])->name('implementacao.salvar');

// Visão do publicador (sem autenticação) — leitura + check-in por SKU
Route::get('/implementacao/{token}/publicador', [MlbImplementacaoController::class, 'publicador'])->name('implementacao.publicador');
Route::patch('/implementacao/{token}/publicador/checkin', [MlbImplementacaoController::class, 'checkinPublicador'])->name('implementacao.publicador.checkin');
// Frete de UMA linha da precificação. Rota própria porque o publicador não pode
// mandar a lista inteira: a tela dele é de leitura com um campo editável, e
// reescrever o array todo já reverteu o que outra pessoa tinha preenchido.
Route::patch('/implementacao/{token}/publicador/frete', [MlbImplementacaoController::class, 'salvarFretePublicador'])->name('implementacao.publicador.frete');

// ═══ Portal do Cliente — ACESSO AUTENTICADO ═════════════════════════════════
//
// A porta nova: a pessoa entra com e-mail e código, e a empresa sai do
// USUÁRIO autenticado — nunca da URL. Vive ao lado das rotas por token, que
// continuam de pé enquanto os clientes existentes migram. Quando todos
// tiverem entrado ao menos uma vez, o bloco por token abaixo é removido e o
// acesso por posse de link morre.
//
// Sem token nas URLs — era esse o incômodo original: o cliente não conseguia
// guardar nem digitar o endereço.
//
// O prefixo `/portal` NÃO é enfeite: `/ppa` e `/onboarding` JÁ pertencem ao
// sistema interno (`ppa.index`, `onboarding.painel.index`). Sem o prefixo, as
// rotas do cliente eram silenciosamente sobrescritas pelas do admin — o
// route:list mostrava as internas respondendo naquelas URIs.
//
// `/entrar` e `/sair` ficam na raiz de propósito: são as que o cliente digita.
Route::middleware('portal.auth')->prefix('portal')->group(function () {
    Route::get('/inicio',     [PortalClienteController::class, 'inicioAutenticado'])->name('portal.auth.inicio');
    Route::get('/onboarding', [OnboardingPublicoController::class, 'workspaceAutenticado'])->name('portal.auth.onboarding');
    Route::get('/ppa',        [PortalPpaController::class, 'indexAutenticado'])->name('portal.auth.ppa');

    Route::patch('/ppa/tarefas/{task}', [PortalPpaController::class, 'moverTarefaAutenticado'])
        ->middleware('throttle:60,1')
        ->name('portal.auth.ppa.tarefa');

    // Para quem responde por mais de uma empresa. O vínculo é conferido no
    // servidor: mandar company_id de outro cliente aqui dá 403 e vira
    // registro de auditoria.
    Route::post('/empresa', [PortalAuthController::class, 'trocarEmpresa'])->name('portal.auth.empresa');
});

// Sair fica fora do prefixo — a sessão morre no mesmo lugar em que nasceu.
Route::post('/sair', [PortalAuthController::class, 'sair'])
    ->middleware('portal.auth')
    ->name('portal.sair');

// Entrada e login. Fora do grupo autenticado, por motivo óbvio.
Route::get('/entrar', [PortalAuthController::class, 'entrada'])->name('portal.entrada');
Route::post('/entrar/codigo', [PortalAuthController::class, 'enviarCodigo'])
    ->middleware('throttle:portal-codigo')
    ->name('portal.codigo');
Route::post('/entrar', [PortalAuthController::class, 'validarCodigo'])
    ->middleware('throttle:portal-validar')
    ->name('portal.validar');

// ─── Portal do Cliente (`/portal-cliente/{token}`) ──────────────────────────
//
// Nasceu na Fase 135 Plano 11 como portal de ONBOARDING (`/onboarding-cliente`,
// D-06) e virou o ambiente da empresa em 21/08/2026: o Onboarding é hoje UM dos
// módulos, ao lado do Início e do PPA. O catálogo de módulos vive em
// `App\Support\Portal\ModulosPortal` — módulo novo entra lá, ganha um controller
// que resolve o token pelo `PortalClienteService`, e uma rota neste grupo.
//
// Prefixo NOVO e distinto de 'implementacao/*' (Polos, D-02) — NUNCA reusar
// aquele prefixo. O token vive na EMPRESA (não no onboarding): uma empresa pode
// ter mais de um serviço com onboarding ativo ao mesmo tempo (D-08) e o cliente
// recebe um único link. Sem middleware 'auth' — acesso é por posse do token
// (mesmo risco já aceito no precedente do Polos: Str::random(48), unique() no
// banco, sem expiração). CSRF isento via bootstrap/app.php.
//
// Os NOMES das rotas de onboarding seguem `onboarding.publico.*`. Só a URL
// mudou: renomeá-los arrastaria dezenas de call-sites e testes sem ganhar nada,
// e o nome continua descrevendo com precisão o que a rota faz — o módulo de
// onboarding, dentro do portal. Módulo novo usa o namespace `portal.*`.
Route::prefix('portal-cliente/{token}')->group(function () {
    Route::get('/', [PortalClienteController::class, 'inicio'])->name('portal.inicio');

    // ── Módulo Onboarding ──────────────────────────────────────────────────
    Route::get('/onboarding', [OnboardingPublicoController::class, 'workspace'])
        ->name('portal.onboarding');
    Route::patch('/onboarding/passo', [OnboardingPublicoController::class, 'marcarFeito'])
        ->middleware('throttle:20,1')
        ->name('onboarding.publico.passo');
    // O cliente desfaz o que marcou — sem isto, clique errado era definitivo.
    Route::patch('/onboarding/passo/desmarcar', [OnboardingPublicoController::class, 'desmarcarPasso'])
        ->middleware('throttle:20,1')
        ->name('onboarding.publico.passo.desmarcar');
    // Porta pública para o OAuth do Mercado Livre. O callback continua sendo o
    // mesmo de sempre (`ml.oauth.callback`, logo abaixo) — o que muda é que ele
    // devolve o cliente ao portal quando o fluxo começou aqui.
    Route::get('/onboarding/conectar/ml', [OnboardingPublicoController::class, 'conectarMercadoLivre'])
        ->middleware('throttle:20,1')
        ->name('onboarding.publico.conectar-ml');
    // §13.2 e §16 — o cliente informa quem acionamos e quem participa das
    // reuniões. Só ADICIONA: editar e remover ficam do lado interno, porque este
    // é um link sem senha e apagar cadastro de terceiros é mais poder do que
    // "informe quem participa".
    Route::post('/onboarding/pessoas', [OnboardingPublicoController::class, 'salvarPessoa'])
        ->name('onboarding.publico.pessoas');
    // Mapeamento inicial: o cliente pede a busca dos dados e confere o apurado.
    // Throttle mais apertado no sincronizar — cada clique vira sonda contra a
    // Adman, que tem ADMAN_RATE_LIMIT_RPM = 10 (o cooldown do service é a
    // segunda rede).
    Route::post('/onboarding/mapeamento/sincronizar', [OnboardingPublicoController::class, 'sincronizarMapeamento'])
        ->middleware('throttle:6,1')
        ->name('onboarding.publico.mapeamento.sincronizar');
    Route::post('/onboarding/mapeamento/confirmar', [OnboardingPublicoController::class, 'confirmarMapeamento'])
        ->middleware('throttle:20,1')
        ->name('onboarding.publico.mapeamento.confirmar');

    // NÃO existe rota para o cliente pedir reunião. Quem define data e hora
    // somos nós, no painel interno, e o portal só mostra a data para o cliente
    // se organizar — decisão de negócio de 19/08. A rota antiga
    // (`onboarding.publico.reuniao`) foi removida junto com o botão "Solicitar
    // reunião": deixá-la de pé sem botão seria manter aberto um endpoint
    // público que rebaixa o status da reunião.

    // ── Módulo PPA ─────────────────────────────────────────────────────────
    // Mesmas linhas de `ppas`/`ppa_tasks` que a equipe gerencia em /ppa e
    // /polos-ppa. Não há cópia, espelho nem sincronização.
    Route::get('/ppa', [PortalPpaController::class, 'index'])->name('portal.ppa');
    Route::patch('/ppa/tarefas/{task}', [PortalPpaController::class, 'moverTarefa'])
        ->middleware('throttle:60,1')
        ->name('portal.ppa.tarefa');
});

// Links já enviados a clientes (WhatsApp, e-mail, mensagem antiga) apontam para
// `/onboarding-cliente/{token}` e precisam continuar funcionando — não há como
// recolher um link que já está com o cliente. 301 permanente para o módulo de
// Onboarding do portal, que é a tela que aquele link sempre abriu.
//
// Só o GET tem redirect: as demais rotas antigas eram POST/PATCH disparados de
// dentro da própria página, e a página agora é servida já com as URLs novas.
Route::get('/onboarding-cliente/{token}', function (string $token) {
    return redirect()->route('portal.onboarding', $token, 301);
})->name('portal.legado.onboarding');
// ML OAuth — callback público (o cliente autoriza fora do painel)
Route::get('/oauth/mercadolivre/callback', [MercadoLivreOAuthController::class, 'callback'])
    ->name('ml.oauth.callback');

// Shopee OAuth — landing guiada (link único assinado que o admin manda ao cliente:
// Passo 1 ERP → Passo 2 Ads) + callbacks públicos dos dois apps.
Route::get('/shopee/conectar/{company}', [ShopeeOAuthController::class, 'connectLanding'])
    ->name('shopee.connect.landing')->middleware('signed');
Route::get('/oauth/shopee/callback', [ShopeeOAuthController::class, 'callback'])
    ->name('shopee.oauth.callback');
Route::get('/oauth/shopee/ads/callback', [ShopeeOAuthController::class, 'adsCallback'])
    ->name('shopee.oauth.ads.callback');

// Google OAuth (público — sem autenticação durante o callback)
Route::get('/google/connect', [GoogleCalendarController::class, 'connect'])
    ->middleware(['auth', 'verified'])
    ->name('google.connect');
Route::get('/google/callback', [GoogleCalendarController::class, 'callback'])->name('google.callback');

// Gerar link NPS (DEVE ficar antes da rota pública /nps/{token} para não colidir)
Route::post('/nps/generate', [NpsController::class, 'generate'])
    ->middleware(['auth', 'verified'])
    ->name('nps.generate');

// ─── Fase 119.1 Plan 06 — NPS de GRUPO ───────────────────────────────────────
// Mesmo grupo autenticado de nps.generate (auth+verified, SEM role:admin —
// quem gera link individual da própria carteira também gera de grupo).
// Prévia de cobertura consultável ANTES de enviar (D4/NPSMAN-08). DEVEM
// ficar antes das rotas públicas /nps/grupo/{token} para não colidir.
Route::post('/nps/grupo/generate', [NpsGrupoController::class, 'generate'])
    ->middleware(['auth', 'verified'])
    ->name('nps.grupo.generate');

Route::get('/nps/grupo/{grupo}/modelo/{template}/cobertura', [NpsGrupoController::class, 'previewCobertura'])
    ->middleware(['auth', 'verified'])
    ->name('nps.grupo.cobertura');

// ─── Phase 81 Plan 02 — Empresas elegíveis por modelo (DEC-81-3) ─────────────
// Endpoint JSON que alimenta o modal "Gerar link" modelo-first (Plan 81-04):
// dado um modelo, retorna as empresas elegíveis (serviços cobertos ∩ contratos
// ativos; modelo sem scopes → todas as ativas). Fica no grupo ['auth','verified']
// — NÃO role:admin — porque o gerar-link é usado por consultor/não-admin, que só
// enxerga a própria carteira (escopo dentro do controller). Espelha nps.generate
// e DEVE ficar antes da rota pública /nps/{token} para não colidir.
Route::get('/nps/configuracao/templates/{template}/empresas-elegiveis', [NpsTemplateController::class, 'empresasElegiveis'])
    ->middleware(['auth', 'verified'])
    ->name('nps.configuracao.templates.empresas-elegiveis');

// ─── Customização NPS (Phase 32 Plan 02) ────────────────────────────────────
// Páginas admin-only (role:admin) para editar os 11 textos do fluxo NPS +
// endpoint de preview server-rendered do email (D-05). DEVEM ficar ANTES da
// rota pública /nps/{token} para evitar colisão com o parâmetro dinâmico.
//
// Phase 70 Plan 05 (v15.0) — REMAPEAMENTO:
//   `/nps/configuracao`                       → NpsTemplateController@index (nova UI multi-template)
//   `/nps/configuracao/textos-legado`         → NpsController@configuracao (Phase 33 preservada)
//   `/nps/configuracao/textos-legado (PUT)`   → NpsController@atualizarConfiguracao
//   `/nps/configuracao/textos-legado/preview` → NpsController@previewEmail
// O nome `nps.configuracao.index` é herdado pela rota nova para preservar
// compatibilidade com links de menu/breadcrumbs no AppLayout.
Route::middleware(['auth', 'verified', 'role:admin'])->group(function () {
    // Spec 2026-08-14 (item 2) — fechamento MANUAL do ciclo de NPS. Admin-only:
    // encerrar um ciclo bloqueia novos links e novas respostas do mês inteiro.
    // Precisa vir ANTES da rota pública `/nps/{token}` para não ser engolida
    // pelo parâmetro dinâmico — mesmo cuidado de `nps.generate`.
    Route::post('/nps/ciclo/fechar',  [NpsCicloController::class, 'fechar'])->name('nps.ciclo.fechar');
    Route::post('/nps/ciclo/reabrir', [NpsCicloController::class, 'reabrir'])->name('nps.ciclo.reabrir');

    // Rotas legadas (Phase 33) — movidas para subpath `/textos-legado`.
    // A UI é a mesma (agora servida via ConfiguracaoLegado.jsx) e continua
    // permitindo edição dos 11 textos + perguntas extras enquanto v15.0 roda.
    Route::get ('/nps/configuracao/textos-legado',         [NpsController::class, 'configuracao'])->name('nps.configuracao.textos-legado');
    Route::put ('/nps/configuracao/textos-legado',         [NpsController::class, 'atualizarConfiguracao'])->name('nps.configuracao.textos-legado.update');
    Route::post('/nps/configuracao/textos-legado/preview', [NpsController::class, 'previewEmail'])->name('nps.configuracao.textos-legado.preview');

    // Aliases preservados para os POST/PUT antigos (o form legado usa
    // `route('nps.configuracao.update')` e `route('nps.configuracao.preview')`).
    // Mantidos como aliases duplicados sob o path `/textos-legado` para não
    // quebrar o JS de ConfiguracaoLegado.jsx durante a migração — trocar os
    // nomes exigiria varredura ampla no JS legado. Cleanup fica para Phase 73.
    Route::put ('/nps/configuracao/textos-legado/update',  [NpsController::class, 'atualizarConfiguracao'])->name('nps.configuracao.update');
    Route::post('/nps/configuracao/textos-legado/preview-alias', [NpsController::class, 'previewEmail'])->name('nps.configuracao.preview');

    // Nova rota principal (v15.0) — UI multi-template Configuracao.jsx.
    // Herdou o nome canônico `nps.configuracao.index` para preservar links
    // que dependiam da rota antiga (menu, breadcrumbs, redirects).
    Route::get('/nps/configuracao', [NpsTemplateController::class, 'index'])->name('nps.configuracao.index');

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

    // ─── Phase 70 Plan 01 — CRUD templates NPS v15.0 ─────────────────────
    // Backend REST dos templates (base multi-template) — consumido pela
    // Plan 70-05 (rewrite Configuracao.jsx). Perguntas e opções vêm em
    // routes aninhadas nas Plans 70-02/03. NÃO tem DELETE — templates só
    // são desativados via toggle-active (invariante do seed NPS Padrão).
    Route::get   ('/nps/configuracao/templates',                          [NpsTemplateController::class, 'index'])
        ->name('nps.configuracao.templates.index');
    Route::post  ('/nps/configuracao/templates',                          [NpsTemplateController::class, 'store'])
        ->name('nps.configuracao.templates.store');
    Route::put   ('/nps/configuracao/templates/{template}',               [NpsTemplateController::class, 'update'])
        ->name('nps.configuracao.templates.update');
    Route::patch ('/nps/configuracao/templates/{template}/toggle-active', [NpsTemplateController::class, 'toggleActive'])
        ->name('nps.configuracao.templates.toggle-active');
    // 2026-07-13 — promove o template a "modelo principal" (único que conta nas
    // métricas + é enviado no disparo automático mensal). Troca atômica do
    // is_default respeitando o unique parcial.
    Route::patch ('/nps/configuracao/templates/{template}/set-principal', [NpsTemplateController::class, 'setPrincipal'])
        ->name('nps.configuracao.templates.set-principal');

    // ─── Phase 81 Plan 01 — Duplicar / Excluir modelo NPS (v16.0) ───────
    // Duplicar: clona um modelo completo (config+perguntas+opções+scopes) num
    // novo template is_default=false (DEC-81-1). Excluir: remove um modelo
    // descartável com guardas (não apaga o principal nem modelos com respostas
    // — DEC-81-2). Mesmo grupo role:admin do CRUD de templates.
    Route::post  ('/nps/configuracao/templates/{template}/duplicar',      [NpsTemplateController::class, 'duplicate'])
        ->name('nps.configuracao.templates.duplicate');
    Route::delete('/nps/configuracao/templates/{template}',               [NpsTemplateController::class, 'destroy'])
        ->name('nps.configuracao.templates.destroy');

    // ─── Phase 70 Plan 02 — CRUD perguntas dos templates ────────────────
    // Rotas aninhadas sob {template}. scopeBindings() faz o Laravel resolver
    // {pergunta} via NpsTemplateQuestion::where('template_id', $template->id)
    // -> 404 direto se a pergunta não pertence ao template (segurança em
    // profundidade + guard interno abort_if no controller). Store não usa
    // scopeBindings porque não recebe {pergunta} (só cria).
    Route::post  ('/nps/configuracao/templates/{template}/perguntas',
        [NpsTemplateQuestionController::class, 'store'])
        ->name('nps.configuracao.templates.perguntas.store');

    Route::put   ('/nps/configuracao/templates/{template}/perguntas/{pergunta}',
        [NpsTemplateQuestionController::class, 'update'])
        ->scopeBindings()
        ->name('nps.configuracao.templates.perguntas.update');

    Route::delete('/nps/configuracao/templates/{template}/perguntas/{pergunta}',
        [NpsTemplateQuestionController::class, 'destroy'])
        ->scopeBindings()
        ->name('nps.configuracao.templates.perguntas.destroy');

    Route::post  ('/nps/configuracao/templates/{template}/perguntas/{pergunta}/mover',
        [NpsTemplateQuestionController::class, 'mover'])
        ->scopeBindings()
        ->name('nps.configuracao.templates.perguntas.mover');

    // Ajuste 2026-07-13 — duplicar pergunta preservando texto/tipo/dimensão
    // e todas as opções. Insere o clone logo após a pergunta original via
    // SHIFT +1 nas ordens posteriores (ver NpsTemplateQuestionController::duplicar).
    Route::post  ('/nps/configuracao/templates/{template}/perguntas/{pergunta}/duplicar',
        [NpsTemplateQuestionController::class, 'duplicar'])
        ->scopeBindings()
        ->name('nps.configuracao.templates.perguntas.duplicar');

    // ─── Phase 70 Plan 03 — CRUD opções das perguntas ───────────────────
    // Rotas triplo-aninhadas sob {template}/perguntas/{pergunta}. scopeBindings()
    // resolve {pergunta} scoped por template_id E {opcao} scoped por question_id
    // -> 404 automático se qualquer vínculo estiver quebrado. Guard interno
    // abort_if no controller é defesa em profundidade (belt-and-suspenders).
    // Todas as 4 rotas — inclusive `store` — usam scopeBindings() porque já
    // recebem {pergunta} na URL e precisam garantir que pertence ao template.
    Route::post  ('/nps/configuracao/templates/{template}/perguntas/{pergunta}/opcoes',
        [NpsTemplateOptionController::class, 'store'])
        ->scopeBindings()
        ->name('nps.configuracao.templates.perguntas.opcoes.store');

    Route::put   ('/nps/configuracao/templates/{template}/perguntas/{pergunta}/opcoes/{opcao}',
        [NpsTemplateOptionController::class, 'update'])
        ->scopeBindings()
        ->name('nps.configuracao.templates.perguntas.opcoes.update');

    Route::delete('/nps/configuracao/templates/{template}/perguntas/{pergunta}/opcoes/{opcao}',
        [NpsTemplateOptionController::class, 'destroy'])
        ->scopeBindings()
        ->name('nps.configuracao.templates.perguntas.opcoes.destroy');

    Route::post  ('/nps/configuracao/templates/{template}/perguntas/{pergunta}/opcoes/{opcao}/mover',
        [NpsTemplateOptionController::class, 'mover'])
        ->scopeBindings()
        ->name('nps.configuracao.templates.perguntas.opcoes.mover');

    // ─── Phase 70 Plan 04 — Service scopes + empresas afetadas + preview ─
    // 3 endpoints complementares da UI de Configuração admin:
    //  - PUT servicos: sincroniza pivot nps_template_service_scopes (REQ NPS-C-05)
    //  - GET empresas-afetadas: simula quais empresas em carteira receberiam este
    //    template dado o pivot atual (feedback visual do REQ NPS-C-05)
    //  - POST preview: renderiza preview live SEM PERSISTIR — payload não amarrado
    //    a {template} porque a rota é stateless (REQ NPS-C-06).
    Route::put ('/nps/configuracao/templates/{template}/servicos',
        [NpsTemplateController::class, 'syncServicos'])
        ->name('nps.configuracao.templates.servicos.sync');

    Route::get ('/nps/configuracao/templates/{template}/empresas-afetadas',
        [NpsTemplateController::class, 'empresasAfetadas'])
        ->name('nps.configuracao.templates.empresas-afetadas');

    Route::post('/nps/configuracao/templates/preview',
        [NpsTemplateController::class, 'preview'])
        ->name('nps.configuracao.templates.preview');

    // ─── Phase 72 Plan 01 — Config global NPS (dia de cobrança) ─────────
    // PATCH admin-only para persistir Configuracao::nps_dia_cobranca (int 1..31).
    // Consumido pelo widget DiaCobrancaWidget em Nps/Configuracao.jsx e lido
    // pelo NpsPendingService::diaCobranca() (Phase 72 Plan 01).
    Route::patch('/nps/configuracao/dia-cobranca',
        [NpsController::class, 'atualizarDiaCobranca'])
        ->name('nps.configuracao.dia-cobranca.update');

    // ─── Phase 96 Plan 02 — IPs/CIDRs internos configuráveis pela UI (AB-96-2) ──
    // PATCH admin-only para persistir Configuracao::nps_internal_ips/
    // nps_internal_cidrs. `.env` (ECF_INTERNAL_IPS/ECF_INTERNAL_CIDRS) segue
    // valendo como fallback — NpsSuspicionService::isInternalIp() lê a UNIÃO
    // (.env ∪ UI). Consumido pelo widget IpsInternosWidget em Nps/Configuracao.jsx.
    Route::patch('/nps/configuracao/ips-internos',
        [NpsController::class, 'atualizarIpsInternos'])
        ->name('nps.configuracao.ips-internos.update');

    // ─── v15.5 — Envio automático NPS (email + WhatsApp/Digisac) ────────
    // Página dedicada admin-only para gerenciar canais, mapeamento Digisac
    // e auditoria unificada. Deve ficar ANTES de /nps/{token}.
    Route::get   ('/nps/envio-automatico',
        [NpsEnvioAutomaticoController::class, 'index'])
        ->name('nps.envio-automatico.index');

    Route::patch ('/nps/envio-automatico/config',
        [NpsEnvioAutomaticoController::class, 'atualizarConfig'])
        ->name('nps.envio-automatico.config.update');

    Route::get   ('/nps/envio-automatico/digisac/grupos',
        [NpsEnvioAutomaticoController::class, 'listarGrupos'])
        ->name('nps.envio-automatico.digisac.grupos');

    Route::put   ('/nps/envio-automatico/empresas/{company}/mapeamento',
        [NpsEnvioAutomaticoController::class, 'mapearGrupo'])
        ->name('nps.envio-automatico.mapeamento.update');

    Route::delete('/nps/envio-automatico/empresas/{company}/mapeamento',
        [NpsEnvioAutomaticoController::class, 'desmapearGrupo'])
        ->name('nps.envio-automatico.mapeamento.destroy');

    // Quick task 260612-flt — admin exclui resposta de uma pesquisa NPS
    // (reverte survey para pending). Antes da rota publica /nps/{token}.
    Route::delete('/nps/{survey}/response', [NpsController::class, 'excluirResposta'])->name('nps.responses.destroy');

    // Phase 96 Plan 03 (AB-96-3) — admin invalida/revalida uma resposta
    // suspeita SEM apagar nada (flag invalidated_at/invalidated_by,
    // reversível). Diferente de excluirResposta() acima: NÃO reverte o
    // survey para pending (evita ambiguidade no hasOne, ver NpsResponse::
    // scopeValida()/96-RESEARCH Pitfall 2). Antes da rota pública /nps/{token}.
    Route::patch('/nps/{survey}/response/invalidar', [NpsController::class, 'invalidarResposta'])
        ->name('nps.responses.invalidar');
    Route::patch('/nps/{survey}/response/revalidar', [NpsController::class, 'revalidarResposta'])
        ->name('nps.responses.revalidar');

    // 2026-07-13 — admin exclui a pesquisa NPS INTEIRA (qualquer status, inclusive
    // pendente) + exclusão em massa via checkboxes da listagem. O bulk é
    // registrado ANTES de /nps/{survey} para que "surveys/bulk" não caia no
    // route model binding. `whereNumber` protege o binding de segmentos não
    // numéricos (configuracao, emails-enviados, etc.). Antes de /nps/{token}.
    Route::delete('/nps/surveys/bulk', [NpsController::class, 'bulkDestroy'])->name('nps.surveys.bulk-destroy');
    Route::delete('/nps/{survey}',     [NpsController::class, 'destroy'])->whereNumber('survey')->name('nps.surveys.destroy');

    // ─── Phase 74 D-10/D-12 — Configuração da régua de bônus do módulo Desempenho ──
    // Admin edita faixas de bônus (BonusFaixa) via UI dedicada. Validação de
    // sobreposição + range [0,5] via UpdateBonusFaixaRequest. Toggle-active
    // preserva histórico sem apagar row. Route model binding em `{faixa}`
    // resolve para App\Models\BonusFaixa implicitamente. Reaproveita o grupo
    // `role:admin` existente para consistência com nps.configuracao.*.
    Route::get   ('/desempenho/configuracao',
        [\App\Http\Controllers\DesempenhoConfigController::class, 'index'])
        ->name('desempenho.configuracao.index');
    Route::patch ('/desempenho/configuracao/faixas/{faixa}',
        [\App\Http\Controllers\DesempenhoConfigController::class, 'updateFaixa'])
        ->name('desempenho.configuracao.faixas.update');
    Route::patch ('/desempenho/configuracao/faixas/{faixa}/toggle-active',
        [\App\Http\Controllers\DesempenhoConfigController::class, 'toggleActive'])
        ->name('desempenho.configuracao.faixas.toggle');
});

// NPS de GRUPO público (sem autenticação) — Fase 119.1 Plan 06. DEVE vir
// ANTES de /nps/{token}: `/nps/grupo/{token}` tem 2 segmentos e por si só já
// não colide com `/nps/{token}` (1 segmento), mas declarar antes remove
// qualquer ambiguidade de casamento entre as duas rotas públicas de token.
// Os handlers (respond/submitResponse) chegam na Task 2 do Plan 06.
Route::get('/nps/grupo/{token}', [NpsGrupoController::class, 'respond'])->name('nps.grupo.respond');
Route::post('/nps/grupo/{token}', [NpsGrupoController::class, 'submitResponse'])->name('nps.grupo.submit');

// NPS público (sem autenticação) — token vem DEPOIS do /generate
Route::get('/nps/{token}', [NpsController::class, 'respond'])->name('nps.respond');
Route::post('/nps/{token}', [NpsController::class, 'submitResponse'])->name('nps.submit');

// Política de Privacidade pública (HTML estático — usado p/ aprovação Chrome Web Store)
Route::view('/privacidade/painel-ecf', 'privacidade.painel-ecf')->name('privacidade.painel-ecf');

Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Phase 58 v13.0 — Rotas Dashboard multi-marketplace. `/dashboard` legacy
    // preservada como canonical fallback (CONTEXT §5).
    Route::get('/dashboard/ecf',          [DashboardController::class, 'ecf'])->name('ecf.dashboard');
    Route::get('/dashboard/mercadolivre', [DashboardController::class, 'mercadolivre'])->name('mercadolivre.dashboard');
    Route::get('/dashboard/shopee',       [DashboardController::class, 'shopee'])->name('shopee.dashboard');
    Route::get('/dashboard/amazon',       [DashboardController::class, 'amazon'])->name('amazon.dashboard');

    // Phase 56 v13.0 — Placeholder para marketplaces em desenvolvimento
    // (Shopee, Amazon, Magazine Luiza, etc). Sidebar (AppLayout.jsx) aponta
    // stubs pra ca com query param ?marketplace=<slug>. Sem permission
    // dedicada — visivel a todos autenticados (mesmo padrao do Manual).
    Route::get('/em-desenvolvimento', function () {
        return Inertia::render('EmDesenvolvimento', [
            'marketplace' => request()->query('marketplace'),
        ]);
    })->name('em-desenvolvimento');

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
    // Destino do arraste no quadro: status + coluna extra + ordem da coluna.
    // Separada de `ppa.tasks.update` porque é a única que reordena e a única
    // que responde JSON — a tela já moveu o card na hora, e uma resposta
    // Inertia faria o quadro piscar a cada arraste.
    Route::patch('/ppa/tasks/{task}/mover', [PpaTaskController::class, 'mover'])->name('ppa.tasks.mover');

    // Colunas EXTRAS do quadro ("Aguardando Cliente", "Em Revisão"...). As três
    // fixas são o ENUM `ppa_tasks.status` e NÃO passam por estas rotas — não há
    // caminho aqui capaz de renomear, mover ou apagar uma delas. Servem os dois
    // escopos: a coluna pertence ao PPA, não ao escopo.
    Route::post('/ppa/{ppa}/colunas', [PpaColunaController::class, 'store'])->name('ppa.colunas.store');
    Route::put('/ppa/colunas/{coluna}', [PpaColunaController::class, 'update'])->name('ppa.colunas.update');
    Route::delete('/ppa/colunas/{coluna}', [PpaColunaController::class, 'destroy'])->name('ppa.colunas.destroy');

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
    // Gate mlb.meu_painel: o publicador comum (que já acessa "Meu Painel") enxerga
    // o MESMO dashboard de desempenho da equipe — não só líderes/gestores (mlb.dashboard).
    Route::get('/publicacao/desempenho', [PerformanceController::class, 'indexPublicacao'])
        ->middleware('permission:mlb.meu_painel')
        ->name('publicacao.desempenho.index');

    // Phase 46 Plan 46-02 — endpoint JSON da curva de evolucao do score de um user
    // ao longo dos ultimos N dias (default 30, clamp 7..365). Consumido por fetch
    // no frontend (drawer/grafico individual entregue na Wave 3). Mesmo gate da
    // pagina /performance (permission core.performance).
    Route::get('/api/performance/{user}/evolucao', [PerformanceController::class, 'evolucao'])
        ->middleware('permission:core.performance')
        ->name('performance.evolucao');

    // Auditoria de pagamento de bônus (item 3/4 · 2026-07-21) — admin-only.
    // Invalida o resultado de uma empresa para bônus numa competência (empresa
    // sem custo preenchido infla margem injustamente). Ver BonusAuditoriaController.
    Route::get('/desempenho/auditoria-bonus', [BonusAuditoriaController::class, 'index'])
        ->middleware('role:admin')
        ->name('desempenho.auditoria-bonus');
    Route::post('/desempenho/auditoria-bonus/toggle', [BonusAuditoriaController::class, 'toggle'])
        ->middleware('role:admin')
        ->name('desempenho.auditoria-bonus.toggle');

    // Fase 107 — Relatório de bonificação (MVP · admin-only). Consolida, por
    // competência (mês fechado), quem atingiu o bônus + nota de cada parâmetro.
    // Página Inertia + export PDF (dompdf). Lê o snapshot mensal do fechamento
    // (mesma fonte do ranking/auditoria). Ver RelatorioBonificacaoController.
    Route::get('/desempenho/relatorio-bonificacao', [RelatorioBonificacaoController::class, 'index'])
        ->middleware('role:admin')
        ->name('desempenho.relatorio-bonificacao');
    Route::get('/desempenho/relatorio-bonificacao/pdf', [RelatorioBonificacaoController::class, 'pdf'])
        ->middleware('role:admin')
        ->name('desempenho.relatorio-bonificacao.pdf');

    // Fase 136 — Lançamento manual de métricas financeiras (admin-only). Grade
    // empresa × mês para lançar faturamento e CMV à mão quando a API não
    // entrega o dado (loja Shopee sem CMV, empresa sem OAuth), alimentando o
    // motor de Desempenho. Competência já consolidada continua listada, porém
    // READ-ONLY: a trava de congelamento vale sem exceção (D-09). Autorização
    // em camada dupla — `role:admin` por rota (aqui) + o `authorize()` do
    // StoreMetricaManualRequest. Ver DesempenhoMetricasManuaisController.
    Route::get('/desempenho/metricas-manuais', [DesempenhoMetricasManuaisController::class, 'index'])
        ->middleware('role:admin')
        ->name('desempenho.metricas-manuais.index');
    Route::post('/desempenho/metricas-manuais/lancar', [DesempenhoMetricasManuaisController::class, 'lancar'])
        ->middleware('role:admin')
        ->name('desempenho.metricas-manuais.lancar');

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
    // Fase 3 do plano de otimização (2026-07-21) — carteira de transparência
    // (§8.3), página NOVA/aditiva. Autorização granular no controller (mesma
    // regra de portfolio.show: admin/self/líder-do-setor).
    Route::get('/portfolio/transparencia/{user}', [PortfolioController::class, 'transparencia'])->name('portfolio.transparencia');

    // Detalhe da empresa: admin/lider com core.empresas veem qualquer empresa;
    // analista/estrategista veem apenas empresas da propria carteira.
    // A autorizacao fina acontece no controller para nao bloquear carteira no middleware.
    Route::get('/companies/{company}', [CompanyController::class, 'show'])->name('companies.show');

    // Criacao de meta de empresa: admin ou estrategista vinculado a empresa.
    // Remocao continua restrita ao admin no grupo abaixo.
    Route::post('/goals', [GoalController::class, 'store'])->name('goals.store');

    // Edicao de meta (META-04): admin OR estrategista vinculado a empresa (auth no controller).
    Route::put('/goals/{goal}', [GoalController::class, 'update'])->name('goals.update');
    // Historico de alteracoes da meta (drawer META-04): admin ou qualquer user
    // vinculado a empresa pode ler (auth no controller). Retorna JSON.
    Route::get('/goals/{goal}/history', [GoalController::class, 'history'])->name('goals.history');

    // ML OAuth: qualquer usuario com acesso ao detalhe da empresa pode gerar
    // link de conexao; desconectar/sync manual seguem admin-only.
    Route::post('/companies/{company}/ml/initiate', [MercadoLivreOAuthController::class, 'initiate'])->name('ml.oauth.initiate');

    // Quick 260623 — GET /companies agora gateado por permission core.empresas
    // (antes role:admin). Lider de Performance ganha a permission via
    // AUTO_LIDERANCA_PERFORMANCE; admin tem implicito.
    // Mutations (PUT/DELETE/POST) ficam no grupo role:admin abaixo.
    Route::middleware('permission:core.empresas')->group(function () {
        Route::get('/companies',            [CompanyController::class, 'index'])->name('companies.index');
    });

    // ─── Shopee · Empresas (Phase 75 Plan 75-04 — DEC-4) ─────────────────────
    // Aba enxuta das empresas atendidas na Shopee (habilita NPS). Gate DEDICADO
    // permission:shopee.empresas — NUNCA core.empresas (T-75-09 EoP). bulkAssign
    // tem guard de escopo anti-IDOR (T-75-10) dentro do controller.
    Route::middleware('permission:shopee.empresas')->group(function () {
        Route::get('/shopee/empresas',                  [ShopeeEmpresasController::class, 'index'])->name('shopee.empresas.index');
        Route::post('/shopee/empresas/bulk-assign',     [ShopeeEmpresasController::class, 'bulkAssign'])->name('shopee.empresas.bulk-assign');
        // Phase 78 (DEC-78-2/4): resolver pendência (atribui responsáveis Shopee + email)
        // e cancelar só o serviço Shopee. Ambos com guard de escopo no controller.
        Route::post('/shopee/empresas/resolver',        [ShopeeEmpresasController::class, 'resolver'])->name('shopee.empresas.resolver');
        Route::post('/shopee/empresas/cancelar-servico', [ShopeeEmpresasController::class, 'cancelarServico'])->name('shopee.empresas.cancelar-servico');
    });

    // ─── Sugadores ──────────────────────────────────────────────────────────
    // Leitura/escrita gated por SugadorPolicy (admin/gestor/lider veem global;
    // consultor/mentor/analista veem só carteira). Análise on-demand e config
    // restritas a admin (Policy::manage).
    Route::get('/sugadores',                        [SugadorController::class, 'index'])->name('sugadores.index');
    // Phase 52 Wave 3.5 (52-03B) — drilldown por empresa. Restaura o alvo do
    // click no CompanyCard removido na Wave 2 (Opcao Z). IMPORTANTE: declarada
    // ANTES de /sugadores/{sugador} pra que /sugadores/empresa/{company} nao
    // seja capturada pelo model binding do sugador (que aceita numerico).
    Route::get('/sugadores/empresa/{company}',      [SugadorController::class, 'porEmpresa'])->name('sugadores.empresa.listagem');
    Route::get('/sugadores/{sugador}',              [SugadorController::class, 'show'])->name('sugadores.show');
    Route::get('/sugadores/{sugador}/mlbs',         [SugadorController::class, 'mlbs'])->name('sugadores.mlbs');
    // Phase 52 A5 — endpoint neutro (fonte local AdgroupMlbMapRepository).
    // Substitui `sugadores.mlbs` no botao "Copiar MLBs" da listagem — resolve
    // 422 para empresas ML-only (adman_account_id=NULL). Shape estavel 200.
    Route::get('/sugadores/{sugador}/mlbs-hint',    [SugadorController::class, 'mlbsHint'])->name('sugadores.mlbs-hint');
    // Quick 2026-07-03 — Métricas Product Ads POR MLB (chips clicks/cost/units na secao "MLBs neste adgroup").
    Route::get('/sugadores/{sugador}/mlbs-metrics-ml', [SugadorController::class, 'mlbsMetricsMl'])->name('sugadores.mlbs-metrics-ml');
    // Phase 30 Plan 30-04 — Botão "Forçar atualização" do drilldown.
    Route::post('/sugadores/refresh-adgroup-mlbs',  [SugadorController::class, 'refreshAdgroupMlbs'])->name('sugadores.refresh-adgroup-mlbs');
    Route::patch('/sugadores/{sugador}/status',     [SugadorController::class, 'updateStatus'])->name('sugadores.update-status');
    Route::post('/sugadores/{sugador}/move',        [SugadorController::class, 'move'])->name('sugadores.move');
    Route::post('/sugadores/bulk-move',             [SugadorController::class, 'bulkMove'])->name('sugadores.bulk-move');
    // Phase 52 A6 — acao em massa "Copiar MLBs dos selecionados" via checkboxes.
    // Autoriza view por item (pattern bulkMove) + agrega via AdgroupMlbMapRepository.
    Route::post('/sugadores/bulk-copy-mlbs',        [SugadorController::class, 'bulkCopyMlbs'])->name('sugadores.bulk-copy-mlbs');
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

        // Tela de controle de visibilidade dos módulos no menu.
        // Gate real isAdminDev() dentro do controller (admin comum não acessa).
        // Conceder o cargo Dev é feito em /users (quick 260727-mx3).
        Route::get('/dev/modulos', [DevModulosController::class, 'index'])
            ->name('dev.modulos.index');
        Route::patch('/dev/modulos/{module}/visibilidade', [DevModulosController::class, 'updateVisibilidade'])
            ->name('dev.modulos.visibilidade');
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


        // ─── Metas do Dev — REMOVIDO de main (2a vez, 2026-08-18) ─────────────
        // As rotas `dev/metas` apontavam para MetasDevController e
        // MetasDevGestorController, que NUNCA foram versionados: existem so na
        // arvore de trabalho da sessao que os escreve. Em producao isso mata
        // `php artisan route:list` inteiro e da 500 em /dev/metas.
        //
        // Voltaram porque eu copiei o routes/web.php da arvore principal por
        // cima da worktree, desfazendo a remocao anterior — copia cega de
        // arquivo compartilhado apaga correcao que so existe de um lado.
        //
        // Para reativar: commitar controllers, models, migrations e FormRequests
        // JUNTO com este bloco, num commit so.
        // PUT /goals/{goal} movida pra fora do grupo (META-04) — estrategista pode
        // editar. DELETE segue restrita ao admin.
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
        Route::post('/users/{user}/avatar', [UserController::class, 'updateAvatar'])->name('users.avatar.update');
        Route::delete('/users/{user}/avatar', [UserController::class, 'destroyAvatar'])->name('users.avatar.destroy');
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

        // Logo da empresa — a marca que o CLIENTE vê no topo do menu do Portal
        // (`/portal-cliente/{token}`). Fica no grupo admin junto de
        // `companies.update`: é edição do cadastro da empresa, e trocar a marca
        // que o cliente enxerga não é operação de rotina.
        // ─── Acessos do Portal do Cliente ────────────────────────────────
        // Quem da EQUIPE cadastra e revoga o acesso dos clientes. Sob o mesmo
        // `role:admin` do resto do grupo: dar acesso ao portal de uma empresa
        // não é operação de rotina.
        // Fora do prefixo `portal/`, que pertence às rotas do CLIENTE. Ter as
        // duas coisas sob o mesmo prefixo obrigaria a allowlist do
        // `RestringeDominioDoPortal` a distinguir uma da outra por curinga — e
        // foi assim que `/portal/usuarios` acabou respondendo no domínio do
        // cliente.
        Route::get('/acessos-portal',  [PortalUsuarioController::class, 'index'])->name('portal.usuarios.index');
        Route::post('/acessos-portal', [PortalUsuarioController::class, 'store'])->name('portal.usuarios.store');
        Route::put('/acessos-portal/{portalUsuario}', [PortalUsuarioController::class, 'update'])->name('portal.usuarios.update');
        Route::post('/acessos-portal/{portalUsuario}/empresas', [PortalUsuarioController::class, 'vincular'])->name('portal.usuarios.vincular');
        Route::delete('/acessos-portal/{portalUsuario}/empresas/{company}', [PortalUsuarioController::class, 'desvincular'])->name('portal.usuarios.desvincular');

        Route::post('/companies/{company}/logo', [CompanyController::class, 'updateLogo'])->name('companies.logo.update');
        Route::delete('/companies/{company}/logo', [CompanyController::class, 'destroyLogo'])->name('companies.logo.destroy');
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
        Route::delete('/companies/{company}/ml/disconnect',[MercadoLivreOAuthController::class, 'disconnect'])->name('ml.oauth.disconnect');
        Route::post('/companies/{company}/ml/sync-now',   [MercadoLivreOAuthController::class, 'syncNow'])->name('ml.sync.now');
        // Sync global: dispara fan-out D-1 de todas as empresas com token ML ativo
        Route::post('/ml-oauth/sync-all',                  [MercadoLivreOAuthController::class, 'syncAll'])->name('ml.oauth.sync-all');

        // Shopee OAuth — painel dedicado + ações por empresa.
        // Ver painel + gerar link: gated por permission:sistema.shopee_oauth
        // (admin herda; também usado pela conta de review da Shopee no Go Live).
        // Desconectar + sync manual permanecem admin-only (herdado do grupo).
        Route::get('/shopee-oauth',                            [ShopeeOAuthController::class, 'adminIndex'])
            ->withoutMiddleware('role:admin')->middleware('permission:sistema.shopee_oauth')->name('shopee.oauth.index');
        Route::post('/companies/{company}/shopee/initiate',   [ShopeeOAuthController::class, 'initiate'])
            ->withoutMiddleware('role:admin')->middleware('permission:sistema.shopee_oauth')->name('shopee.oauth.initiate');
        Route::delete('/companies/{company}/shopee/disconnect',[ShopeeOAuthController::class, 'disconnect'])->name('shopee.oauth.disconnect');
        Route::post('/companies/{company}/shopee/sync-now',   [ShopeeOAuthController::class, 'syncNow'])->name('shopee.sync.now');
        // Sync forçado que PERSISTE em shopee_metrics (via fila) — por loja e geral.
        // Gate sistema.shopee_oauth (igual ao "Gerar link"): líder Shopee + admin.
        Route::post('/companies/{company}/shopee/sync',       [ShopeeOAuthController::class, 'sync'])
            ->withoutMiddleware('role:admin')->middleware('permission:sistema.shopee_oauth')->name('shopee.sync.run');
        Route::post('/shopee/sync-all',                       [ShopeeOAuthController::class, 'syncAll'])
            ->withoutMiddleware('role:admin')->middleware('permission:sistema.shopee_oauth')->name('shopee.sync.all');

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

// ─── Fase 135 Plano 09 — Painel operacional de Onboarding geral (D-01) ──────
// Gate DEDICADO permission:core.onboarding — NÃO role:admin: o CRUD de
// template é que é admin-only (D-04), mas a coordenação de onboarding
// (confirmar responsável, concluir passo) provavelmente envolve
// consultor/estrategista, não só admin (135-RESEARCH.md, Open Question 2;
// decisão registrada no 135-09-PLAN.md). Admin passa pelo short-circuit em
// User::hasPermission() (EnsurePermission) — não precisa da key explícita
// em setor_permissoes.
//
// ATENÇÃO DE ROTEAMENTO: 'GET /onboarding/{onboarding}' captura QUALQUER
// segmento. Rota nova com caminho literal sob '/onboarding/...' precisa ser
// registrada ANTES deste grupo, senão o literal vira o parâmetro.
Route::middleware(['auth', 'verified', 'permission:core.onboarding'])
    ->group(function () {
        Route::get('/onboarding', [OnboardingController::class, 'index'])
            ->name('onboarding.painel.index');
        Route::get('/onboarding/{onboarding}', [OnboardingController::class, 'show'])
            ->name('onboarding.painel.show');
        Route::post('/onboarding/{onboarding}/responsavel', [OnboardingController::class, 'confirmarResponsavel'])
            ->name('onboarding.responsavel.confirmar');
        // Estrategista E analista (R-01) — é o "iniciar onboarding" da aba
        // Onboarding de /companies e também o caminho de volta para preencher
        // depois o papel que faltava. Convive com a rota de cima, que é o
        // botão de um clique do painel antigo.
        Route::post('/onboarding/{onboarding}/responsaveis', [OnboardingController::class, 'definirResponsaveis'])
            ->name('onboarding.responsaveis.definir');

        // ─── Respostas do checklist (fluxo consolidado de 19/08) ──────────
        // Cada assunto grava na tabela própria, nunca em
        // `onboarding_passos.valor`: aquela coluna só é escrita quando o passo
        // fecha, e é limpa ao desmarcar — resposta rascunhada sumiria.
        Route::post('/onboarding/{onboarding}/confirmacao', [OnboardingController::class, 'responderConfirmacao'])
            ->name('onboarding.confirmacao.responder');
        Route::put('/onboarding/{onboarding}/investimento', [OnboardingController::class, 'salvarInvestimento'])
            ->name('onboarding.investimento.salvar');
        Route::put('/onboarding/{onboarding}/agenda', [OnboardingController::class, 'salvarAgenda'])
            ->name('onboarding.agenda.salvar');
        Route::post('/onboarding/{onboarding}/contatos', [OnboardingController::class, 'salvarContato'])
            ->name('onboarding.contatos.salvar');
        // Edição e remoção são por LINHA (id próprio), nunca pela lista inteira.
        Route::put('/onboarding/contatos/{contato}', [OnboardingController::class, 'atualizarContato'])
            ->name('onboarding.contatos.atualizar');
        Route::delete('/onboarding/contatos/{contato}', [OnboardingController::class, 'removerContato'])
            ->name('onboarding.contatos.remover');
        Route::post('/onboarding/passos/{passo}/concluir', [OnboardingController::class, 'concluirPasso'])
            ->name('onboarding.passos.concluir');
        // Desmarcar — o caminho de volta que faltava.
        Route::post('/onboarding/passos/{passo}/reabrir', [OnboardingController::class, 'reabrirPasso'])
            ->name('onboarding.passos.reabrir');
        // Data e hora da reunião — a volta da informação que o cliente pediu.
        Route::post('/onboarding/{onboarding}/reuniao', [OnboardingController::class, 'agendarReuniao'])
            ->name('onboarding.reuniao.agendar');
        // Mapeamento inicial pelo lado de quem opera: "Sincronizar agora" (em
        // vez de esperar o cron de 10 min) e conferência assistida em call.
        Route::post('/onboarding/{onboarding}/mapeamento/sincronizar', [OnboardingController::class, 'sincronizarMapeamento'])
            ->name('onboarding.mapeamento.sincronizar');
        Route::post('/onboarding/{onboarding}/mapeamento/confirmar', [OnboardingController::class, 'confirmarMapeamento'])
            ->name('onboarding.mapeamento.confirmar');

        // ─── Fase 135 Plano 11 — geração do link único por empresa (D-06) ──
        // Ação interna: a Coordenação gera/copia o token do portal público
        // do cliente. Mesmo gate deste bloco (permission:core.onboarding) —
        // o cliente nunca chega a esta rota, só ao prefixo público
        // 'portal-cliente/{token}/*' registrado fora do grupo 'auth' acima.
        Route::post('/onboarding/empresas/{company}/link', [OnboardingController::class, 'gerarLink'])
            ->name('onboarding.link.gerar');
        // Relatório inicial (PDF §3): gerar monta o retrato factual; salvar
        // grava as três seções que só uma pessoa escreve.
        // Link do App ECF e e-mail do colaborador, por EMPRESA. Nao ha rota de
        // padrao global: os dois valores sao de cada cliente, e um padrao unico
        // mandaria a base inteira para o mesmo endereco.
        Route::put('/onboarding/{onboarding}/acessos', [OnboardingController::class, 'salvarAcessosDaEmpresa'])
            ->name('onboarding.acessos.empresa');

        Route::post('/onboarding/{onboarding}/relatorio', [OnboardingController::class, 'gerarRelatorio'])
            ->name('onboarding.relatorio.gerar');
        Route::put('/onboarding/{onboarding}/relatorio', [OnboardingController::class, 'salvarRelatorio'])
            ->name('onboarding.relatorio.salvar');
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
// configurável por polo via Configuracao). Grade de donuts por polo.
// Acesso: admin OU permissão mlb.faturamento_polos (setor Polos) — gate inline no controller
// (RF: liberar o setor Polos ver o financeiro sem ser admin). Antes: role:admin no grupo.
Route::middleware(['auth', 'verified'])
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
    // Painel unificado de Polos (aba ADITIVA): operacional p/ quem tem mlb.projetos;
    // camada financeira admin-only montada só nas props (gate em PolosController::painel).
    // Registrado AQUI (grupo mlb.*), NÃO no grupo role:admin do PolosController (RF-1/RF-8).
    Route::get('/polos-painel', [PolosController::class, 'painel'])->name('polos-painel');
    // Camada financeira do Painel (JSON assíncrono, admin-only — gate em painelFinanceiro).
    Route::get('/polos-painel/financeiro', [PolosController::class, 'painelFinanceiro'])->name('polos-painel.financeiro');
    // Edição em MASSA do Painel (JSON; mesmo gate operacional de painel()).
    Route::post('/polos-painel/bulk', [PolosController::class, 'painelBulk'])->name('polos-painel.bulk');
    // Meta de entrantes por região × mês (aba Metas; JSON; mesmo gate operacional).
    Route::post('/polos-painel/meta-entrada', [PolosController::class, 'salvarMetaEntrada'])->name('polos-painel.meta-entrada');
    // Meta ÚNICA de faturamento Polos (card "% Geral da meta"; JSON; admin-only).
    Route::post('/polos-painel/meta-faturamento', [PolosController::class, 'salvarMetaFaturamento'])->name('polos-painel.meta-faturamento');
    // Arquivar / desarquivar empresa Polos (aba "Arquivados"; mesmo gate operacional).
    Route::post('/polos-painel/{empresa}/arquivar',    [PolosController::class, 'arquivar'])->name('polos-painel.arquivar');
    Route::post('/polos-painel/{empresa}/desarquivar', [PolosController::class, 'desarquivar'])->name('polos-painel.desarquivar');
    // PPA Polos (quick 260805-dzu): mesmo módulo PPA recortado nas empresas POLOS.
    // Divide a tabela `ppas` (coluna escopo), as tarefas (ppa.tasks.*) e o workspace
    // público do cliente (ppa.workspace, por token) com o PPA de carteira.
    Route::middleware('permission:mlb.projetos')->group(function () {
        Route::get('/polos-ppa',                    [PolosPpaController::class, 'index'])->name('polos-ppa.index');
        Route::post('/polos-ppa',                   [PolosPpaController::class, 'store'])->name('polos-ppa.store');
        Route::put('/polos-ppa/{ppa}',              [PolosPpaController::class, 'update'])->name('polos-ppa.update');
        Route::delete('/polos-ppa/{ppa}',           [PolosPpaController::class, 'destroy'])->name('polos-ppa.destroy');
        Route::get('/polos-ppa/{ppa}/kanban',       [PolosPpaController::class, 'kanban'])->name('polos-ppa.kanban');
        Route::post('/polos-ppa/{ppa}/workspace-link', [PolosPpaController::class, 'generateWorkspaceLink'])->name('polos-ppa.workspace.generate');
    });
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

    // ─── Revisão: veredicto do líder e ciclo de pendências ───
    // "Aprovado" = conferido E correto. "Em ajuste" = bola com o publicador.
    // "Reconferir" = publicador corrigiu, bola de volta com o líder.
    Route::patch('/pub/{pub}/aprovar',            [MlbController::class, 'aprovarRevisao'])->name('revisao.aprovar');
    Route::patch('/pub/{pub}/reverter',           [MlbController::class, 'reverterRevisao'])->name('revisao.reverter');
    Route::post('/pub/{pub}/pendencia',           [MlbController::class, 'abrirPendencia'])->name('pendencia.abrir');
    Route::post('/revisao/aprovar-lote',          [MlbController::class, 'aprovarLote'])->name('revisao.aprovar-lote');
    // Fora da metodologia: o anúncio continua existindo, mas para de contar
    // em vendas, meta, conversão e score.
    Route::patch('/pub/{pub}/desconsiderar',      [MlbController::class, 'desconsiderarPublicacao'])->name('revisao.desconsiderar');
    Route::patch('/pendencia/{pendencia}/corrigida', [MlbController::class, 'corrigirPendencia'])->name('pendencia.corrigida');
    Route::patch('/pendencia/{pendencia}/resolver',  [MlbController::class, 'resolverPendencia'])->name('pendencia.resolver');
    Route::patch('/pendencia/{pendencia}/reabrir',   [MlbController::class, 'reabrirPendencia'])->name('pendencia.reabrir');

    // Rotas antigas — mantidas enquanto Publicações/Meu Painel não migram.
    // Delegam ao RevisaoService (ver adaptadores no MlbController).
    Route::patch('/pub/{pub}/revisado',          [MlbController::class, 'marcarRevisado'])->name('revisado');
    Route::patch('/pub/{pub}/comentario',        [MlbController::class, 'salvarComentario'])->name('comentario');
    Route::patch('/pub/{pub}/resolver',          [MlbController::class, 'resolverComentario'])->name('resolver');
    Route::patch('/pub/{pub}/problema',          [MlbController::class, 'marcarProblema'])->name('problema');
    // Plano de Metas — pontualidade (prazo por anúncio) e absenteísmo (por publicador/mês).
    Route::patch('/pub/{pub}/prazo',             [MlbController::class, 'salvarPrazo'])->name('pub.prazo');
    Route::put('/absenteismo',                   [MlbController::class, 'salvarAbsenteismo'])->name('absenteismo');
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
    Route::patch('/empresas/{empresa}/cust-id',   [MlbController::class, 'updateCustIdEmpresa'])->name('empresas.cust-id');
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

// ─── Contratos administrativos (Fase 131, UI-01/UI-05, D-09/D-10) ────────────
// FORA do grupo `role:admin` acima DE PROPÓSITO: `admin.contratos` é
// permissão PRÓPRIA, refinável na tela de setores sem deploy (D-09). Se
// estas rotas entrarem no grupo `role:admin`, um usuário que receba
// `admin.contratos` via setor continua batendo 403 — a UI-05 vira letra
// morta (ContratoAdminPermissaoTest fica vermelho se isso acontecer).
// D-10: esta tela ABSORVEU a liberação manual da Fase 130 (plano 131-06) —
// `liberacao-manual` abaixo é a ação nova; a rota antiga foi removida (ver
// bloco acima).
Route::middleware(['auth', 'verified', 'permission:admin.contratos'])->prefix('administrativo/contratos')->name('admin.contratos.')->group(function () {
    Route::get('/', [ContratoAdminController::class, 'index'])->name('index');
    // Plano 131-04 (D-01/ADM-01/ADM-02/UI-02) — detalhe da empresa: onde o
    // Administrativo completa o cadastro e dispara a geração do contrato.
    Route::get('/empresa/{company}',            [ContratoAdminController::class, 'show'])            ->name('show');
    Route::patch('/empresa/{company}/cadastro', [ContratoAdminController::class, 'atualizarCadastro'])->name('cadastro');
    Route::post('/empresa/{company}/gerar',     [ContratoAdminController::class, 'gerarContrato'])    ->name('gerar');
    // Plano 131-05 (CLICK-07/CLICK-10, D-13) — reenviar aviso e registrar
    // cancelamento (registra aqui, cancela no painel da Clicksign).
    Route::post('/{contratoAssinatura}/signatarios/{signatario}/reenviar', [ContratoAdminController::class, 'reenviar'])
        ->name('reenviar');
    Route::post('/{contratoAssinatura}/cancelamento', [ContratoAdminController::class, 'registrarCancelamento'])
        ->name('cancelamento');
    // Plano 131-06 (D-10) — absorve ContratoLiberacaoManualController::store()
    // (Fase 130). Ação disparada de dentro do detalhe da empresa.
    Route::post('/liberacao-manual', [ContratoAdminController::class, 'liberarManual'])->name('liberacao-manual');
});

// ─── Liderança (acesso: admin ou líder de pelo menos 1 setor) ────────────────
Route::middleware(['auth', 'verified', 'permission:lideranca.dashboard_setor'])->prefix('lideranca')->name('lideranca.')->group(function () {
    Route::get('/setor', [LiderancaController::class, 'indexOrFirst'])->name('index');
    Route::get('/setor/{setor:slug}', [LiderancaController::class, 'show'])->name('setor');
});

require __DIR__.'/auth.php';
