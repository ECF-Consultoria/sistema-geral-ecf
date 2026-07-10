<?php

use App\Http\Controllers\MlbAnuncioController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas do módulo "Anunciar Mercado Livre"
|--------------------------------------------------------------------------
|
| Em arquivo próprio (registrado no bootstrap/app.php via `then`) para não
| colidir com edições concorrentes em routes/web.php. Por ora o módulo é
| ADMIN-ONLY (role:admin) — "em Dev", para teste isolado antes de liberar à
| equipe de publicação (quando abrir, trocar para permission:mlb.anunciar).
|
*/

Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('mlb/anuncios')
    ->name('mlb.anuncios.')
    ->group(function () {
        Route::get('/', [MlbAnuncioController::class, 'index'])->name('index');

        // Rascunho (autosave + ciclo de vida)
        Route::post('/rascunho', [MlbAnuncioController::class, 'salvarRascunho'])->name('rascunho.store');
        Route::put('/rascunho/{rascunho}', [MlbAnuncioController::class, 'atualizarRascunho'])->name('rascunho.update');
        Route::post('/rascunho/{rascunho}/validar', [MlbAnuncioController::class, 'validar'])->name('validar');
        Route::post('/rascunho/{rascunho}/publicar', [MlbAnuncioController::class, 'publicar'])->name('publicar');

        // Metadados do wizard (JSON)
        Route::get('/meta/prever-categoria', [MlbAnuncioController::class, 'preverCategoria'])->name('meta.prever');
        Route::get('/meta/categoria/{categoryId}/atributos', [MlbAnuncioController::class, 'atributos'])
            ->where('categoryId', 'MLB[0-9]+')
            ->name('meta.atributos');
        Route::get('/meta/tipos-anuncio', [MlbAnuncioController::class, 'tiposAnuncio'])->name('meta.tipos');
    });
