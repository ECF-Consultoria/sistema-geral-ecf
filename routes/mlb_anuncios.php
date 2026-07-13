<?php

use App\Http\Controllers\MlbAnuncioController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas do módulo "Anunciar Mercado Livre"
|--------------------------------------------------------------------------
|
| Em arquivo próprio (registrado no bootstrap/app.php via `then`) para não
| colidir com edições concorrentes em routes/web.php.
|
| Estrutura de acesso (Phase 75):
|   - TUDO admin-only (role:admin) — módulo "em Dev", acessado por URL, sem menu.
|   - O escopo por `responsavel_id` já está construído no controller (dormant
|     enquanto o gate é role:admin: todo admin vê todas). Quando o módulo abrir
|     à equipe de publicação, trocar role:admin → permission:mlb.anunciar aqui;
|     o filtro por responsavel_id passa a valer sem rework.
|
*/

Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('mlb/anuncios')
    ->name('mlb.anuncios.')
    ->group(function () {
        // ─── Momento 1: painel de cards ───
        // SEL-01: um card por empresa; escopo por responsavel_id no controller (SEL-02)
        Route::get('/', [MlbAnuncioController::class, 'index'])->name('index');

        // ─── Momento 2: wizard com empresa fixada (âncora = company com ml_token) ───
        Route::get('/wizard/{company}', [MlbAnuncioController::class, 'wizard'])->name('wizard');

        // DRAFT-02: cria rascunho pré-preenchido a partir de produto da planilha do cliente (Phase 76)
        // Nome resolvido: mlb.anuncios.rascunho.por-produto (consumido pelo front em 76-02)
        Route::post('/wizard/{company}/rascunho-por-produto', [MlbAnuncioController::class, 'rascunhoPorProduto'])
            ->name('rascunho.por-produto');

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
