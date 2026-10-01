<?php

use App\Http\Controllers\IncubadoraPublicadorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas do Publicador da Incubadora (01/10/2026)
|--------------------------------------------------------------------------
|
| Em arquivo próprio (registrado no bootstrap/app.php via `then`) para os dois
| devs trabalharem no módulo sem colidir em routes/web.php.
|
| Oculto: sem item de menu, e `modulo:incubadora.publicador` responde 404 para
| quem não é Dev — inclusive com a URL na mão. Abrir para mais gente = ligar o
| módulo em Dev → Controle Dev (depois do `modules:sync`), sem deploy.
|
*/

Route::middleware(['auth', 'verified', 'modulo:incubadora.publicador'])
    ->prefix('incubadora/publicador')
    ->name('incubadora.publicador.')
    ->group(function () {
        Route::get('/', [IncubadoraPublicadorController::class, 'index'])->name('index');

        // Chamam a API do ML (app token): throttle para uma tela aberta não
        // virar rajada contra a cota do app.
        Route::middleware('throttle:60,1')->group(function () {
            Route::get('/categorias', [IncubadoraPublicadorController::class, 'categorias'])->name('categorias');
            Route::get('/categorias/{categoria}/termos', [IncubadoraPublicadorController::class, 'termos'])
                ->where('categoria', 'MLB\d+')
                ->name('termos');
        });
    });
