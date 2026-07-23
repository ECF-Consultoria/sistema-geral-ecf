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

        // ─── Grade de anúncio em massa (Phase 82) — empresa fixada ANTES da grade ───
        // SHEET-01: grade editável por categoria; escopo por responsavel_id dormant
        // sob role:admin (mesmo padrão do wizard). Um lote = category_id + empresa.
        Route::get('/massa/{company}', [MlbAnuncioController::class, 'massa'])->name('massa');
        // SHEET-02/03: colunas base + só os obrigatórios da categoria + breadcrumb completo
        Route::get('/massa/meta/{categoryId}/colunas', [MlbAnuncioController::class, 'colunasCategoria'])
            ->where('categoryId', 'MLB[0-9]+')
            ->name('massa.colunas');
        // SHEET-01/04: lista completa do cliente p/ pré-preenchimento por linha da grade
        Route::get('/massa/{company}/produtos', [MlbAnuncioController::class, 'produtosDoClienteMassa'])
            ->name('massa.produtos');

        // ─── Histórico dos publicados (Phase 86) — 3ª aba, base do "Anunciar semelhante" ───
        // HIST-86-3: massa()/index() filtram whereIn([rascunho, validado, erro, publicando]);
        // 'publicado' fica de fora e o anúncio some da tela ao dar certo. Esta consulta o traz
        // de volta. Mesmo gate das outras (role:admin no grupo).
        Route::get('/historico/{company}', [MlbAnuncioController::class, 'historico'])->name('historico');

        // DRAFT-02: cria rascunho pré-preenchido a partir de produto da planilha do cliente (Phase 76)
        // Nome resolvido: mlb.anuncios.rascunho.por-produto (consumido pelo front em 76-02)
        Route::post('/wizard/{company}/rascunho-por-produto', [MlbAnuncioController::class, 'rascunhoPorProduto'])
            ->name('rascunho.por-produto');

        // Rascunho (autosave + ciclo de vida)
        Route::post('/rascunho', [MlbAnuncioController::class, 'salvarRascunho'])->name('rascunho.store');
        Route::put('/rascunho/{rascunho}', [MlbAnuncioController::class, 'atualizarRascunho'])->name('rascunho.update');
        // Excluir rascunho (limpa a lista de "Rascunhos recentes"); double-check de empresa no controller
        Route::delete('/rascunho/{rascunho}', [MlbAnuncioController::class, 'excluirRascunho'])->name('rascunho.destroy');
        // WIZ-05 (Phase 77 Plan 02): upload imediato de imagem por variação → devolve picture_id
        // T-77-04: double-check de empresa no controller antes de qualquer chamada ao ML
        Route::post('/rascunho/{rascunho}/imagem', [MlbAnuncioController::class, 'uploadImagem'])->name('rascunho.imagem');
        // WIZ-06 (Phase 77 Plan 03): lista grades de tamanho disponíveis no domínio da categoria
        // T-77-08: double-check de empresa; T-77-09: cacheado 1h no service; T-77-10: domain_id validado
        Route::get('/rascunho/{rascunho}/grades', [MlbAnuncioController::class, 'listarGrades'])->name('rascunho.grades');
        // SHIP-02 (Phase 78 Plan 01): cotação de frete por dimensões/peso — informativo, não bloqueia publicação
        // T-78-01: double-check de empresa no controller; degradação graciosa (null) se endpoint falhar
        Route::get('/rascunho/{rascunho}/frete', [MlbAnuncioController::class, 'cotarFrete'])->name('rascunho.frete');
        Route::post('/rascunho/{rascunho}/validar', [MlbAnuncioController::class, 'validar'])->name('validar');
        Route::post('/rascunho/{rascunho}/publicar', [MlbAnuncioController::class, 'publicar'])->name('publicar');
        // DUP-02 (Phase 79 Plan 01): cria rascunho do tier oposto (Clássico→Premium ou Premium→Clássico)
        // DUP-03: título com sufixo mínimo e strip idempotente (anti-duplicata ML)
        Route::post('/rascunho/{rascunho}/duplicar-tier', [MlbAnuncioController::class, 'duplicarTier'])
            ->name('rascunho.duplicar-tier');
        // UX-03 (Phase 81 Plan 01): duplica um anúncio publicado como template inicial
        // Mantém título/tier intactos, zera os três ml_item_ids → novo rascunho do zero
        Route::post('/rascunho/{rascunho}/duplicar-template', [MlbAnuncioController::class, 'duplicarComoTemplate'])
            ->name('rascunho.duplicar-template');
        // "Anunciar semelhante em massa" (ext. Phase 86): clona TODO um lote do histórico
        // como templates novos e devolve os ids; o front abre a grade (massa), onde os
        // clones (status rascunho, mesmo category_id) já aparecem pré-preenchidos por aba.
        Route::post('/empresa/{company}/duplicar-lote', [MlbAnuncioController::class, 'duplicarLoteComoTemplate'])
            ->name('empresa.duplicar-lote');
        // DUP-02 / DUP-04 (Phase 79 Plan 01): publica par Clássico+Premium em 1 chamada HTTP
        // Falha de um tier não aborta o outro — resposta traz resultado independente por tier
        Route::post('/rascunho/{rascunho}/publicar-duplo', [MlbAnuncioController::class, 'publicarDuplo'])
            ->name('publicar-duplo');
        // BULK-01/02 (Phase 80 Plan 01): enfileira N rascunhos da mesma empresa em lote
        // ShouldBeUnique no job + delay escalonado evitam duplicatas e 429 no ML
        Route::post('/empresa/{company}/publicar-lote', [MlbAnuncioController::class, 'publicarLote'])
            ->name('publicar-lote');

        // Metadados do wizard (JSON)
        Route::get('/meta/prever-categoria', [MlbAnuncioController::class, 'preverCategoria'])->name('meta.prever');
        Route::get('/meta/categoria/{categoryId}/atributos', [MlbAnuncioController::class, 'atributos'])
            ->where('categoryId', 'MLB[0-9]+')
            ->name('meta.atributos');
        Route::get('/meta/tipos-anuncio', [MlbAnuncioController::class, 'tiposAnuncio'])->name('meta.tipos');

        // AUTO-01: compatibilidades de autopeças — detecção + cascata de veículos (app token)
        Route::get('/meta/compat/categoria/{categoryId}', [MlbAnuncioController::class, 'compatCategoria'])
            ->where('categoryId', 'MLB[0-9]+')
            ->name('meta.compat.categoria');
        Route::get('/meta/compat/marcas', [MlbAnuncioController::class, 'compatMarcas'])->name('meta.compat.marcas');
        Route::get('/meta/compat/modelos', [MlbAnuncioController::class, 'compatModelos'])->name('meta.compat.modelos');
        Route::get('/meta/compat/anos', [MlbAnuncioController::class, 'compatAnos'])->name('meta.compat.anos');
    });
