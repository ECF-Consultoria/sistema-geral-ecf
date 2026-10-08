<?php

use App\Http\Controllers\MlbAlavancasController;
use App\Http\Controllers\MlbAlavancasEscritaController;
use App\Http\Controllers\MlbAnuncioController;
use App\Http\Controllers\MlbPublicadorController;
use App\Http\Controllers\MlbPublicadorCriativoController;
use App\Http\Controllers\MlbPublicadorDescricaoController;
use App\Http\Controllers\MlbPublicadorEntradaController;
use App\Http\Controllers\MlbPublicadorIdentidadeController;
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
        // Fase 164: a entrada do módulo é o Publicador (D12/D13) — empresas por programa
        // (?programa=polos|incubadora|gestao), só admins (D17).
        Route::get('/', [MlbPublicadorEntradaController::class, 'index'])->name('index');

        // ─── Fase 164: Publicador interno — tela B (produtos da empresa) e casca do editor ───
        // {conta} = empresa-N | company-N; arquivada/sem programa dá 404 no resolver (T-164-23).
        Route::get('publicador/empresas/{conta}', [MlbPublicadorEntradaController::class, 'produtos'])
            ->where('conta', '(empresa|company)-[0-9]+')->name('publicador.produtos');
        Route::post('publicador/empresas/{conta}/sincronizar', [MlbPublicadorEntradaController::class, 'sincronizar'])
            ->where('conta', '(empresa|company)-[0-9]+')
            ->middleware('throttle:20,1,publicador.sincronizar')->name('publicador.sincronizar');
        Route::get('publicador/empresas/{conta}/sincronizar/{pedido}', [MlbPublicadorEntradaController::class, 'resumoDoSincronizar'])
            ->where('conta', '(empresa|company)-[0-9]+')->whereUuid('pedido')
            ->middleware('throttle:240,1,publicador.sincronizar.resumo')->name('publicador.sincronizar.resumo');
        Route::post('publicador/empresas/{conta}/produtos', [MlbPublicadorEntradaController::class, 'criarProduto'])
            ->where('conta', '(empresa|company)-[0-9]+')
            ->middleware('throttle:60,1,publicador.produtos.criar')->name('publicador.produtos.criar');
        Route::get('publicador/produtos/{produto}/editor', [MlbPublicadorEntradaController::class, 'editor'])
            ->whereNumber('produto')->name('publicador.editor');

        // ─── Fase 166: Alavancas (D-01/D-02/D-06) — só leitura aqui; escrita no 166-11 ───
        // {conta} = empresa-N | company-N; a conta vem do resolver, nunca do corpo (IDOR).
        Route::prefix('publicador/empresas/{conta}/alavancas')
            ->where(['conta' => '(empresa|company)-[0-9]+'])
            ->name('publicador.alavancas.')
            ->group(function () {
                Route::get('/', [MlbAlavancasController::class, 'index'])->name('index');
                Route::get('panorama', [MlbAlavancasController::class, 'panorama'])
                    ->middleware('throttle:60,1,alavancas.panorama')->name('panorama');
                Route::get('promocoes', [MlbAlavancasController::class, 'promocoes'])
                    ->middleware('throttle:60,1,alavancas.promocoes')->name('promocoes');
                Route::get('promocoes/{promocao}/itens', [MlbAlavancasController::class, 'itensDaPromocao'])
                    ->where('promocao', '[A-Za-z0-9-]{1,40}')
                    ->middleware('throttle:120,1,alavancas.promocoes.itens')->name('promocoes.itens');
                Route::get('produtos', [MlbAlavancasController::class, 'produtos'])
                    ->middleware('throttle:60,1,alavancas.produtos')->name('produtos');
                Route::get('produtos/{item}/promocoes', [MlbAlavancasController::class, 'promocoesDoItem'])
                    ->where('item', 'MLB[0-9]+')
                    ->middleware('throttle:120,1,alavancas.produtos.promocoes')->name('produtos.promocoes');
                Route::post('analise', [MlbAlavancasController::class, 'analise'])
                    ->middleware('throttle:20,1,alavancas.analise')->name('analise');
                Route::get('cupons', [MlbAlavancasController::class, 'cupons'])
                    ->middleware('throttle:60,1,alavancas.cupons')->name('cupons');
                Route::get('exclusao', [MlbAlavancasController::class, 'exclusao'])
                    ->middleware('throttle:60,1,alavancas.exclusao')->name('exclusao');
                Route::get('exclusao/{item}', [MlbAlavancasController::class, 'exclusaoDoItem'])
                    ->where('item', 'MLB[0-9]+')
                    ->middleware('throttle:120,1,alavancas.exclusao.item')->name('exclusao.item');
                Route::get('publicidade', [MlbAlavancasController::class, 'publicidade'])
                    ->middleware('throttle:30,1,alavancas.publicidade')->name('publicidade');
                Route::get('publicidade/ad-groups', [MlbAlavancasController::class, 'adGroups'])
                    ->middleware('throttle:30,1,alavancas.publicidade.ad-groups')->name('publicidade.ad-groups');
                Route::get('atacado', [MlbAlavancasController::class, 'atacado'])
                    ->middleware('throttle:30,1,alavancas.atacado')->name('atacado');
                Route::get('atacado/{item}', [MlbAlavancasController::class, 'atacadoDoItem'])
                    ->where('item', 'MLB[0-9]+')
                    ->middleware('throttle:60,1,alavancas.atacado.item')->name('atacado.item');
                Route::post('atacado/{item}/recomendacoes', [MlbAlavancasController::class, 'recomendacoes'])
                    ->where('item', 'MLB[0-9]+')
                    ->middleware('throttle:30,1,alavancas.recomendacoes')->name('atacado.recomendacoes');
                Route::get('historico', [MlbAlavancasController::class, 'historico'])
                    ->middleware('throttle:60,1,alavancas.historico')->name('historico');
                Route::get('historico/{escrita}', [MlbAlavancasController::class, 'historicoMostrar'])
                    ->whereNumber('escrita')
                    ->middleware('throttle:120,1,alavancas.historico.mostrar')->name('historico.mostrar');

                // 166-11: escrita (D-04) — prévia assinada, confirmação e acompanhamento do lote.
                Route::post('escritas/previa', [MlbAlavancasEscritaController::class, 'previa'])
                    ->middleware('throttle:60,1,alavancas.previa')->name('escritas.previa');
                Route::post('escritas', [MlbAlavancasEscritaController::class, 'confirmar'])
                    ->middleware('throttle:30,1,alavancas.escrever')->name('escritas.confirmar');
                Route::get('lotes/{lote}', [MlbAlavancasEscritaController::class, 'lote'])
                    ->whereUuid('lote')
                    ->middleware('throttle:240,1,alavancas.lotes')->name('lotes');
            });

        // ─── Fase 164 (plano 08): API JSON do editor interno, por produto (D12/D17) ───
        // Espelha o piloto do Portal (routes/web.php), com os mesmos throttles. Nomes: mlb.anuncios.publicador.<sufixo>.
        Route::get('publicador/categorias', [MlbPublicadorController::class, 'categorias'])
            ->middleware('throttle:60,1,publicador.categorias')->name('publicador.categorias');
        Route::prefix('publicador/produtos/{produto}')->whereNumber('produto')->name('publicador.')->group(function () {
            Route::get('/', [MlbPublicadorController::class, 'abrir'])
                ->middleware('throttle:120,1,publicador.abrir')->name('abrir');
            Route::put('/', [MlbPublicadorController::class, 'salvar'])
                ->middleware('throttle:180,1,publicador.salvar')->name('salvar');
            Route::put('/categoria', [MlbPublicadorController::class, 'categoria'])
                ->middleware('throttle:30,1,publicador.categoria')->name('categoria');
            Route::put('/eixos', [MlbPublicadorController::class, 'eixos'])
                ->middleware('throttle:120,1,publicador.eixos')->name('eixos');
            Route::put('/variantes', [MlbPublicadorController::class, 'variantes'])
                ->middleware('throttle:180,1,publicador.variantes')->name('variantes');
            Route::post('/fotos', [MlbPublicadorController::class, 'foto'])
                ->middleware('throttle:60,1,publicador.fotos')->name('fotos');
            Route::put('/fotos', [MlbPublicadorController::class, 'atribuirFotos'])
                ->middleware('throttle:180,1,publicador.fotos.atribuir')->name('fotos.atribuir');
            Route::delete('/fotos/{imagem}', [MlbPublicadorController::class, 'removerFoto'])
                ->whereNumber('imagem')->middleware('throttle:60,1,publicador.fotos.remover')->name('fotos.remover');
            Route::post('/fotos/{imagem}/reenviar', [MlbPublicadorController::class, 'reenviarFoto'])
                ->whereNumber('imagem')->middleware('throttle:30,1,publicador.fotos.reenviar')->name('fotos.reenviar');
            // D26: miniatura da foto guardada que ainda não subiu ao ML (conta não liberada).
            Route::get('/fotos/{imagem}/arquivo', [MlbPublicadorController::class, 'arquivoFoto'])
                ->whereNumber('imagem')->middleware('throttle:240,1,publicador.fotos.arquivo')->name('fotos.arquivo');
            Route::post('/condicionais', [MlbPublicadorController::class, 'condicionais'])
                ->middleware('throttle:60,1,publicador.condicionais')->name('condicionais');
            Route::post('/conferir', [MlbPublicadorController::class, 'conferir'])
                ->middleware('throttle:20,1,publicador.conferir')->name('conferir');
            Route::post('/publicar', [MlbPublicadorController::class, 'publicar'])
                ->middleware('throttle:10,1,publicador.publicar')->name('publicar');
            Route::post('/itens/{item}/descricao', [MlbPublicadorController::class, 'reenviarDescricao'])
                ->whereNumber('item')->middleware('throttle:20,1,publicador.descricao')->name('descricao');
            Route::get('/simular', [MlbPublicadorController::class, 'simular'])
                ->middleware('throttle:30,1,publicador.simular')->name('simular');
            // Melhoria de 03/10/2026: frete grátis obrigatório, termos mais buscados e a IA do Modelo/título.
            Route::get('/frete', [MlbPublicadorController::class, 'frete'])
                ->middleware('throttle:60,1,publicador.frete')->name('frete');
            Route::get('/termos', [MlbPublicadorController::class, 'termos'])
                ->middleware('throttle:60,1,publicador.termos')->name('termos');
            Route::post('/palavras-ia', [MlbPublicadorController::class, 'pedirPalavrasIa'])
                ->middleware('throttle:20,1,publicador.palavras-ia')->name('palavras-ia');
            Route::get('/palavras-ia/{alvo}', [MlbPublicadorController::class, 'palavrasIa'])
                ->middleware('throttle:240,1,publicador.palavras-ia.status')->name('palavras-ia.status');
            // Fase 172 — descrição do anúncio pelo MAG T8 a partir da descrição do cliente (D-09/D-11).
            Route::post('/descricao-ia', [MlbPublicadorDescricaoController::class, 'pedir'])
                ->middleware('throttle:20,1,publicador.descricao-ia')->name('descricao-ia');
            Route::get('/descricao-ia', [MlbPublicadorDescricaoController::class, 'estado'])
                ->middleware('throttle:240,1,publicador.descricao-ia.status')->name('descricao-ia.status');

            // Fase 165 — Creative Engine por produto do Publicador (D-03/D-09). Nomes:
            // mlb.anuncios.publicador.criativos.*. O kit vai pelo id numérico escopado;
            // nenhum token de 32 caracteres na URL nem no JSON (D-13) — ver docblock do
            // controller.
            Route::prefix('criativos')->name('criativos.')->group(function () {
                Route::get('/', [MlbPublicadorCriativoController::class, 'atual'])
                    ->middleware('throttle:240,1,publicador.criativos.status')->name('atual');
                Route::post('/kit', [MlbPublicadorCriativoController::class, 'planejar'])
                    ->middleware('throttle:creative-kit-planejar')->name('kit.planejar');
                Route::get('/kit/{kit}', [MlbPublicadorCriativoController::class, 'status'])
                    ->whereNumber('kit')->middleware('throttle:240,1,publicador.criativos.status')->name('kit.status');
                Route::post('/kit/{kit}/gerar', [MlbPublicadorCriativoController::class, 'gerar'])
                    ->whereNumber('kit')->middleware('throttle:creative-kit-gerar')->name('kit.gerar');
                Route::get('/kit/{kit}/referencias/{indice}', [MlbPublicadorCriativoController::class, 'referencia'])
                    ->whereNumber('kit')->whereNumber('indice')
                    ->middleware('throttle:240,1,publicador.criativos.arquivo')->name('kit.referencia');
                Route::get('/kit/{kit}/slots/{indice}/imagem', [MlbPublicadorCriativoController::class, 'imagem'])
                    ->whereNumber('kit')->whereNumber('indice')
                    ->middleware('throttle:240,1,publicador.criativos.arquivo')->name('slot.imagem');
                Route::post('/kit/{kit}/slots/{indice}/aprovar', [MlbPublicadorCriativoController::class, 'aprovar'])
                    ->whereNumber('kit')->whereNumber('indice')
                    ->middleware('throttle:30,1,publicador.criativos.aprovar')->name('slot.aprovar');
                // Fase 165-05 (CE165-06/09/11) — regenerar uma imagem do kit
                // e aprovar o kit inteiro, com os mesmos limitadores
                // nomeados do Creative Engine (teto de custo).
                Route::post('/kit/{kit}/slots/{indice}/regenerar', [MlbPublicadorCriativoController::class, 'regenerar'])
                    ->whereNumber('kit')->whereNumber('indice')
                    ->middleware('throttle:creative-regenerar')->name('slot.regenerar');
                Route::post('/kit/{kit}/aprovar', [MlbPublicadorCriativoController::class, 'aprovarKit'])
                    ->whereNumber('kit')
                    ->middleware('throttle:30,1,publicador.criativos.aprovar')->name('kit.aprovar');
            });

            // Fase 170 (D2, IDENT-01/04) — identidade visual da CONTA do produto aberto (texto
            // livre, sem logo). Irmão de 'criativos': não é por anúncio, só por conta.
            Route::prefix('identidade')->name('identidade.')->group(function () {
                Route::get('/', [MlbPublicadorIdentidadeController::class, 'mostrar'])
                    ->middleware('throttle:60,1,publicador.identidade.mostrar')->name('mostrar');
                Route::put('/', [MlbPublicadorIdentidadeController::class, 'salvar'])
                    ->middleware('throttle:30,1,publicador.identidade.salvar')->name('salvar');
            });
        });

        // ─── Fase 134: "Meus Anúncios" — saúde analítica do anúncio publicado ───
        // D-13: esta é a ABA INICIAL do módulo (acervo vivo da conta ML do
        // cliente). D-05: leitura 100% do banco, zero chamada síncrona ao ML
        // no request — a coleta roda em job (agendado ou via "Atualizar
        // agora"). Mesmo gate role:admin do grupo, sem middleware novo (D-15).
        Route::get('/meus/{company}', [MlbAnuncioController::class, 'meus'])->name('meus');
        Route::post('/meus/{company}/atualizar', [MlbAnuncioController::class, 'atualizarAgora'])->name('meus.atualizar');
        // Fase 134 Plano 10: detalhe de um anúncio — checklist de sinais (D-10/D-22)
        // e série de até 90 dias (D-07b). Restrição na PRÓPRIA ROTA (não só no
        // controller): mlItemId malformado nunca chega ao controller (T-134-18).
        Route::get('/meus/{company}/{mlItemId}/detalhe', [MlbAnuncioController::class, 'detalheAnuncio'])
            ->where('mlItemId', 'MLB[0-9]+')
            ->name('meus.detalhe');

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

        // ─── Anunciar por IA (metodologia MAG T8, Parte 1: Análise) ───
        // Assíncrono por necessidade: a geração levou 103s na medição de
        // 21/09/2026. O POST enfileira e devolve 202; o GET é o polling.
        // Throttle porque cada chamada consome cota de provedor gratuito —
        // um duplo-clique não pode virar duas gerações de 100 segundos.
        Route::post('/ia/analise', [MlbAnuncioController::class, 'iaAnaliseStore'])
            ->middleware('throttle:10,1')
            ->name('ia.analise.store');
        Route::get('/ia/analise/{analise}', [MlbAnuncioController::class, 'iaAnaliseStatus'])
            ->whereNumber('analise')
            ->name('ia.analise.status');

        // ─── Creative Engine (Fase 160) ───
        // OPS-03: atrás da chave liga/desliga, conferida no controller ANTES de
        // tudo (abort_unless 404) — com a chave desligada, estas duas rotas se
        // comportam como se não existissem. Throttle no upload: cada foto
        // consome disco e cada criativo vai consumir cota paga na 160-02.
        Route::post('/rascunho/{rascunho}/criativo/referencia', [MlbAnuncioController::class, 'criativoReferenciaStore'])
            ->middleware('throttle:20,1')
            ->name('criativo.referencia');
        Route::get('/criativo/{token}/referencia/{indice}', [MlbAnuncioController::class, 'criativoReferenciaVer'])
            ->where('token', '[A-Za-z0-9]{32}')
            ->whereNumber('indice')
            ->name('criativo.referencia.ver');

        // Fase 160 Plano 02 — geração assíncrona (GEN-01/02). Throttle:6,1 na
        // rota de disparo é DINHEIRO, não estilo: cada chamada ao provedor
        // custa ~US$ 0,101 (medição do spike 261001-nkx §16).
        Route::post('/criativo/{token}/gerar', [MlbAnuncioController::class, 'criativoGerar'])
            ->where('token', '[A-Za-z0-9]{32}')
            ->middleware('throttle:6,1')
            ->name('criativo.gerar');
        Route::get('/criativo/{token}', [MlbAnuncioController::class, 'criativoStatus'])
            ->where('token', '[A-Za-z0-9]{32}')
            ->name('criativo.status');
        Route::get('/criativo/{token}/imagem', [MlbAnuncioController::class, 'criativoImagem'])
            ->where('token', '[A-Za-z0-9]{32}')
            ->name('criativo.imagem');

        // Fase 160 Plano 03 — aprovação (APROV-05/PUB-01/PUB-02). Throttle:30,1
        // é só proteção de clique duplo (o botão já fica desabilitado durante a
        // chamada); o custo caro já foi pago na geração, não aqui.
        Route::post('/criativo/{token}/aprovar', [MlbAnuncioController::class, 'criativoAprovar'])
            ->where('token', '[A-Za-z0-9]{32}')
            ->middleware('throttle:30,1')
            ->name('criativo.aprovar');

        // Fase 161 Plano 01 — planejamento do kit de 7 (PLAN-01/02/03/04).
        // Quick 261003-l8o (correção 4): limitador NOMEADO `creative-kit-
        // planejar` (6 → 12/min) — no fluxo de um botão só, TODO clique
        // passa por planejar, e a chamada que encontra kit existente não
        // gasta NADA de cota de imagem (mas consumia slot de throttle
        // igual); 12/min para de barrar o operador com os cliques
        // idempotentes dele mesmo (causa mais provável do "Too many..."
        // relatado). Resposta em pt-BR com os segundos, ver
        // `AppServiceProvider::respostaLimiteCriativo()`.
        Route::post('/criativo/{token}/kit', [MlbAnuncioController::class, 'criativoKitPlanejar'])
            ->where('token', '[A-Za-z0-9]{32}')
            ->middleware('throttle:creative-kit-planejar')
            ->name('criativo.kit.planejar');

        // Fase 161 Plano 02 — geração das 7 imagens (GEN-01/02/03). Quick
        // 261003-l8o (correção 4): limitador NOMEADO `creative-kit-gerar`
        // (3 → 4/min) — o fluxo novo acrescenta UM motivo legítimo de 2ª
        // chamada no mesmo minuto (operador recusa a confirmação, revê e
        // confirma depois). 4 kits/min = teto de ≈ US$ 2,84/min; o teto que
        // de fato segura o dinheiro continua sendo por kit (`max_imagens`)
        // + os tetos de regeneração por asset/kit, nenhum dos dois mudou.
        Route::post('/criativo/kit/{kit}/gerar', [MlbAnuncioController::class, 'criativoKitGerar'])
            ->where('kit', '[A-Za-z0-9]{32}')
            ->middleware('throttle:creative-kit-gerar')
            ->name('criativo.kit.gerar');
        Route::get('/criativo/kit/{kit}', [MlbAnuncioController::class, 'criativoKitStatus'])
            ->where('kit', '[A-Za-z0-9]{32}')
            ->name('criativo.kit.status');

        // Fase 161 Plano 03 — regenera UM slot do kit (APROV-02). Quick
        // 261003-l8o (correção 4): limitador NOMEADO `creative-regenerar`,
        // MESMO número de sempre (12/min — já calibrado em US$ 0,101 por
        // chamada); só a MENSAGEM do 429 muda, de "Too many attempts." para
        // pt-BR com os segundos.
        Route::post('/criativo/{token}/regenerar', [MlbAnuncioController::class, 'criativoRegenerar'])
            ->where('token', '[A-Za-z0-9]{32}')
            ->middleware('throttle:creative-regenerar')
            ->name('criativo.regenerar');

        // Fase 161 Plano 03 — aprova o kit inteiro (APROV-03). Throttle:12,1 é
        // só proteção de clique duplo (o custo caro — as imagens — já foi
        // pago na geração, não aqui).
        Route::post('/criativo/kit/{kit}/aprovar', [MlbAnuncioController::class, 'criativoKitAprovar'])
            ->where('kit', '[A-Za-z0-9]{32}')
            ->middleware('throttle:12,1')
            ->name('criativo.kit.aprovar');
    });
