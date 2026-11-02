<?php

use App\Http\Controllers\Trilha\MapeamentoDaTrilhaController;
use App\Http\Controllers\Trilha\OndasDaTrilhaController;
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
| chave `trilha.empresas` responde 404 (o controller confere).
|
| 2ª entrega (02/11/2026): a porta da EQUIPE ganha as escritas do Mapeamento
| e do Planejamento (JSON), só para empresa também em `trilha.trabalho.empresas`
| e para quem pode escrever no Portal dela (os controllers conferem). A porta
| do CLIENTE continua só leitura: nenhuma rota de escrita no `portal.auth`.
| Cada escrita tem o seu prefixo de throttle.
|
*/

// ─── Porta da equipe ────────────────────────────────────────────────────
// `modulo:trilha.produtos`: enquanto o módulo está oculto no Controle Dev, só
// o Dev entra; os demais recebem 404, como se a rota não existisse.
// `[0-9]{1,18}` e não `whereNumber`: um id de 19 dígitos estouraria o `int`
// do controller (500) em vez de responder 404.
Route::middleware(['auth', 'verified', 'modulo:trilha.produtos'])
    ->prefix('trilha')
    ->name('trilha.')
    ->where(['company' => '[0-9]{1,18}', 'onda' => '[0-9]{1,18}', 'produto' => '[0-9]{1,18}'])
    ->group(function () {
        Route::get('/', [TrilhaController::class, 'index'])->name('index');
        Route::get('/{company}', [TrilhaController::class, 'empresa'])->name('empresa');

        // Mapeamento: o painel "Mapear" do produto (opções, prévia ao vivo e o "Concluir").
        Route::get('/{company}/produtos/{produto}/mapeamento', [MapeamentoDaTrilhaController::class, 'opcoes'])
            ->middleware('throttle:60,1,trilha.mapeamento')->name('mapeamento.opcoes');
        Route::post('/{company}/produtos/{produto}/mapeamento/previa', [MapeamentoDaTrilhaController::class, 'previa'])
            ->middleware('throttle:120,1,trilha.previa')->name('mapeamento.previa');
        Route::post('/{company}/produtos/{produto}/mapeamento', [MapeamentoDaTrilhaController::class, 'concluir'])
            ->middleware('throttle:20,1,trilha.concluir')->name('mapeamento.concluir');

        // Planejamento: as ondas e quem está em cada uma.
        Route::post('/{company}/ondas', [OndasDaTrilhaController::class, 'criar'])
            ->middleware('throttle:30,1,trilha.ondas')->name('ondas.criar');
        Route::put('/{company}/ondas/{onda}', [OndasDaTrilhaController::class, 'editar'])
            ->middleware('throttle:30,1,trilha.ondas')->name('ondas.editar');
        Route::delete('/{company}/ondas/{onda}', [OndasDaTrilhaController::class, 'excluir'])
            ->middleware('throttle:30,1,trilha.ondas')->name('ondas.excluir');
        Route::put('/{company}/planejamento', [OndasDaTrilhaController::class, 'planejar'])
            ->middleware('throttle:30,1,trilha.ondas')->name('planejamento');
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
