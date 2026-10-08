<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas do Publicador da Incubadora (01/10/2026 — descontinuado em 08/10/2026)
|--------------------------------------------------------------------------
|
| Fase 172 (Publicador, Etapa 1 — Navegação), decisão 2 do DECISOES.md:
| categoria sugerida e termos mais buscados já existem na etapa Produto do
| editor novo (ver EtapaProduto.jsx + TermosMaisBuscados.jsx). A tela antiga
| virou redirect — a URL continua respondendo (bookmarks/links salvos), só
| não renderiza mais nada próprio. `/categorias` e `/termos` foram removidas
| junto com o controller.
|
*/

Route::middleware(['auth', 'verified', 'modulo:incubadora.publicador'])
    ->prefix('incubadora/publicador')
    ->name('incubadora.publicador.')
    ->group(function () {
        Route::get('/', fn () => redirect()->route('mlb.anuncios.index', ['programa' => 'incubadora']))->name('index');
    });
