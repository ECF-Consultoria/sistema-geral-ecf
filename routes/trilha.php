<?php

use App\Http\Controllers\Trilha\PortalTrilhaController;
use App\Http\Controllers\Trilha\TrilhaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Trilha de produtos (30/10/2026) — duas portas, um código só
|--------------------------------------------------------------------------
|
| A mesma página (`Trilha/Empresa`) abre por dois endereços: a EQUIPE em
| `admin…/trilha/{empresa}` e o CLIENTE em `cliente…/portal/{empresa}/trilha`.
| Arquivo próprio (registrado no `then:` do bootstrap/app.php, no grupo `web`)
| pelo mesmo motivo do mlb_anuncios.php: módulo novo fora do web.php, que
| vive em conflito de merge entre as sessões.
|
| Escondida nas duas: nenhum item de menu aponta para cá, e empresa fora da
| chave `trilha.empresas` responde 404 (o controller confere). Só leitura —
| nenhuma rota aqui grava nada.
|
*/

// ─── Porta da equipe ────────────────────────────────────────────────────
// `modulo:trilha.produtos`: enquanto o módulo está oculto no Controle Dev, só
// o Dev entra; os demais recebem 404, como se a rota não existisse.
Route::middleware(['auth', 'verified', 'modulo:trilha.produtos'])
    ->prefix('trilha')
    ->name('trilha.')
    ->group(function () {
        Route::get('/', [TrilhaController::class, 'index'])->name('index');
        Route::get('/{company}', [TrilhaController::class, 'empresa'])->whereNumber('company')->name('empresa');
    });

// ─── Porta do cliente ───────────────────────────────────────────────────
// O MESMO grupo do Portal autenticado do routes/web.php (`portal.auth`, a
// empresa no caminho conferida a cada requisição). O grupo de lá não aplica
// mais nada; o throttle com prefixo próprio é o mesmo cuidado do GET do
// Planejamento (`estrutura.sugestoes`): a página regera as sugestões a cada
// abertura. Sem o prefixo, dividiria o contador com as outras rotas.
// A linha `portal/trilha` em `RestringeDominioDoPortal::PERMITIDO` é o que a
// deixa existir no domínio do cliente — `/trilha` (a da equipe) continua 404 lá.
Route::middleware('portal.auth')
    ->prefix('portal/{portalEmpresa}')
    ->where(['portalEmpresa' => '[0-9]+'])
    ->group(function () {
        Route::get('/trilha', [PortalTrilhaController::class, 'mostrar'])
            ->middleware('throttle:60,1,trilha')->name('portal.auth.trilha');
    });
