<?php

use App\Http\Controllers\MlbAlavancasController;
use App\Http\Controllers\MlbAlavancasEscritaController;
use App\Http\Controllers\MlbAnuncioController;
use App\Http\Controllers\MlbPublicadorAcervoController;
use App\Http\Controllers\MlbPublicadorAgenteController;
use App\Http\Controllers\MlbPublicadorAprovacaoController;
use App\Http\Controllers\MlbPublicadorBlingController;
use App\Http\Controllers\MlbPublicadorController;
use App\Http\Controllers\MlbPublicadorCriativoController;
use App\Http\Controllers\MlbPublicadorDescricaoController;
use App\Http\Controllers\MlbPublicadorEntradaController;
use App\Http\Controllers\MlbPublicadorFaseController;
use App\Http\Controllers\MlbPublicadorIdentidadeController;
use App\Http\Controllers\MlbPublicadorLoteController;
use App\Http\Controllers\MlbPublicadorTarefasController;
use App\Models\PubTarefa;
use App\Support\Permissions;
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

        // 173-01 (D-06): busca de empresas pro seletor "Trocar empresa" — rota literal
        // `empresas-busca`, nunca bate no regex `(empresa|company)-[0-9]+` de `{conta}`
        // abaixo (não tem o hífen seguido de dígitos), mas fica ANTES por clareza de leitura.
        Route::get('publicador/empresas-busca', [MlbPublicadorEntradaController::class, 'buscaEmpresas'])
            ->middleware('throttle:60,1,publicador.empresas-busca')->name('publicador.empresas-busca');

        // 177-03 (HEAD-05): as contas do dropdown de empresa do header global, com o TOTAL
        // real ("N empresas sob gestão"). Rota LITERAL, pelo mesmo motivo da de cima (não tem
        // hífen seguido de dígitos, logo nunca casa o regex `(empresa|company)-[0-9]+` de
        // `{conta}`), e fica ANTES dela por clareza de leitura. `120,1` porque o dropdown é
        // aberto várias vezes por sessão e o payload é pequeno. Sem parâmetro de entrada: o
        // escopo é do usuário autenticado (T-177-08).
        Route::get('publicador/empresas-header', [MlbPublicadorEntradaController::class, 'empresasDoHeader'])
            ->middleware('throttle:120,1,publicador.empresas-header')->name('publicador.empresas-header');

        // 178-01 (NOVA-08): os três grupos do modal "Nova publicação" — o próximo passo de
        // cada produto da conta. Endpoint LAZY (só na abertura do modal): pendurar isto na
        // prop `cabecalho` faria as 9 páginas pagarem `produtosParaTela()` em todo request.
        // `nova-publicacao` NÃO colide com `publicador/empresas/{conta}/produtos/{produto}`
        // nem com `publicador/empresas-header`: não casa `[0-9]+` e não tem hífen seguido de
        // dígitos. O `where` da conta é a mesma trava das rotas vizinhas — `{conta}` fora do
        // padrão morre na rota, antes do controller. Throttle nomeado, como todos os vizinhos.
        Route::get('publicador/empresas/{conta}/nova-publicacao', [MlbPublicadorEntradaController::class, 'novaPublicacao'])
            ->where('conta', '(empresa|company)-[0-9]+')
            ->middleware('throttle:120,1,publicador.nova-publicacao')->name('publicador.nova-publicacao');

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
        // 10/10/2026 — excluir produtos que nunca foram publicados: a prévia (só leitura) e a exclusão.
        Route::post('publicador/empresas/{conta}/produtos/exclusao/previa', [MlbPublicadorEntradaController::class, 'previaDaExclusao'])
            ->where('conta', '(empresa|company)-[0-9]+')
            ->middleware('throttle:60,1,publicador.produtos.exclusao.previa')->name('publicador.produtos.exclusao.previa');
        Route::post('publicador/empresas/{conta}/produtos/exclusao', [MlbPublicadorEntradaController::class, 'excluirProdutos'])
            ->where('conta', '(empresa|company)-[0-9]+')
            ->middleware('throttle:30,1,publicador.produtos.exclusao')->name('publicador.produtos.exclusao');
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
        // 11/10/2026 — navegar pela árvore de categorias, nível a nível (um clique = uma leitura).
        Route::get('publicador/categorias/arvore', [MlbPublicadorController::class, 'categoriasArvore'])
            ->middleware('throttle:180,1,publicador.categorias.arvore')->name('publicador.categorias.arvore');
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
            // 11/10/2026: o frete que o Mercado Livre respondeu na conferência vai para a Precificação do Portal.
            Route::post('/frete-para-a-precificacao', [MlbPublicadorController::class, 'freteParaAPrecificacao'])
                ->middleware('throttle:20,1,publicador.frete-precificacao')->name('frete-precificacao');
            Route::post('/itens/{item}/frete-para-a-precificacao', [MlbPublicadorController::class, 'freteDoItemParaAPrecificacao'])
                ->whereNumber('item')->middleware('throttle:20,1,publicador.frete-precificacao.item')->name('itens.frete-precificacao');
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
            // 09/10/2026 — sinal de "editor aberto": o preparo pela IA (salvar no Portal) não escreve por trás da tela.
            Route::post('/presenca', [MlbPublicadorController::class, 'presenca'])
                ->middleware('throttle:30,1,publicador.presenca')->name('presenca');
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

            // Fase 171 (D4, ACERVO-01..05) — acervo navegável das imagens já geradas desta CONTA.
            // Endereçado por {produto} só para resolver a conta (igual à identidade, Fase 170) e
            // por {criativo} numérico (nunca token, D-13) — escopado por company_id/mlb_empresa_id,
            // nunca por pub_rascunho_id sozinho (o acervo CRUZA rascunhos da mesma conta de
            // propósito, inclusive órfãos).
            Route::prefix('acervo')->name('acervo.')->group(function () {
                Route::get('/', [MlbPublicadorAcervoController::class, 'listar'])
                    ->middleware('throttle:120,1,publicador.acervo.listar')->name('listar');
                Route::get('/{criativo}/imagem', [MlbPublicadorAcervoController::class, 'imagem'])
                    ->whereNumber('criativo')->middleware('throttle:240,1,publicador.acervo.arquivo')->name('imagem');
                Route::post('/{criativo}/usar', [MlbPublicadorAcervoController::class, 'usar'])
                    ->whereNumber('criativo')->middleware('throttle:30,1,publicador.acervo.usar')->name('usar');

                // v28.0 Fase 198 — acervo da conta por ATIVO (`acervo_imagens`): uploads + IA aprovada,
                // filtros e paginação. Sem a chave do Creative Engine; {ativo} numérico, 404 fora da conta.
                Route::prefix('ativos')->name('ativos.')->group(function () {
                    Route::get('/', [MlbPublicadorAcervoController::class, 'ativos'])
                        ->middleware('throttle:120,1,publicador.acervo.ativos')->name('listar');
                    Route::get('/{ativo}/miniatura', [MlbPublicadorAcervoController::class, 'ativoMiniatura'])
                        ->whereNumber('ativo')->middleware('throttle:600,1,publicador.acervo.miniatura')->name('miniatura');
                    Route::get('/{ativo}/original', [MlbPublicadorAcervoController::class, 'ativoOriginal'])
                        ->whereNumber('ativo')->middleware('throttle:240,1,publicador.acervo.original')->name('original');
                    Route::post('/{ativo}/usar', [MlbPublicadorAcervoController::class, 'ativoUsar'])
                        ->whereNumber('ativo')->middleware('throttle:30,1,publicador.acervo.ativo-usar')->name('usar');

                    // Fase 199 — exclusão segura: bloqueia se em anúncio não publicado; o objeto sai por job.
                    Route::get('/{ativo}/exclusao', [MlbPublicadorAcervoController::class, 'ativoExclusao'])
                        ->whereNumber('ativo')->middleware('throttle:120,1,publicador.acervo.exclusao')->name('exclusao');
                    Route::delete('/{ativo}', [MlbPublicadorAcervoController::class, 'ativoExcluir'])
                        ->whereNumber('ativo')->middleware('throttle:60,1,publicador.acervo.excluir')->name('excluir');
                    Route::post('/excluir-lote', [MlbPublicadorAcervoController::class, 'ativosExcluirLote'])
                        ->middleware('throttle:10,1,publicador.acervo.excluir-lote')->name('excluir-lote');
                });
            });
        });

        // Fase 173, Plano 01 (VISG-05/CONF-01) — identidade visual por CONTA, pra tela
        // "Configurações da conta". Mesmo controller e throttles nomeados da versão por
        // produto (Fase 170) — reusar os MESMOS nomes é proposital: a conta tem um teto
        // só, não dois, se for acessada pelos dois caminhos ao mesmo tempo. Nome de rota
        // DIFERENTE de mlb.anuncios.publicador.identidade.* (nested em
        // publicador/produtos/{produto}/identidade) pra não colidir no route() do Ziggy.
        Route::get('publicador/empresas/{conta}/identidade', [MlbPublicadorIdentidadeController::class, 'mostrarPorConta'])
            ->where('conta', '(empresa|company)-[0-9]+')
            ->middleware('throttle:60,1,publicador.identidade.mostrar')->name('publicador.conta.identidade.mostrar');
        Route::put('publicador/empresas/{conta}/identidade', [MlbPublicadorIdentidadeController::class, 'salvarPorConta'])
            ->where('conta', '(empresa|company)-[0-9]+')
            ->middleware('throttle:30,1,publicador.identidade.salvar')->name('publicador.conta.identidade.salvar');

        // Fase 173, Plano 04 (VISG-03..08/CONF-02/03) — Visão geral e Configurações da
        // conta: só leitura agregada do que já está gravado, ZERO chamada ao Mercado
        // Livre no request. Fonte única de publicação (pub_publicacoes).
        Route::get('publicador/empresas/{conta}/visao-geral', [MlbPublicadorEntradaController::class, 'visaoGeral'])
            ->where('conta', '(empresa|company)-[0-9]+')->name('publicador.visao-geral');
        Route::get('publicador/empresas/{conta}/configuracoes', [MlbPublicadorEntradaController::class, 'configuracoes'])
            ->where('conta', '(empresa|company)-[0-9]+')->name('publicador.configuracoes');

        // Agente ECF Publicador (AG-07, 20/10/2026) — Rotina e fila, Configuração essencial e Atividade da loja.
        // Só leitura do banco no GET; as escritas não chamam ML nem IA (o "Preparar agora" só admite a execução).
        // `agente` é literal próprio: não casa com `identidade`/`visao-geral`/`configuracoes`/`bling`/`acoes`.
        Route::prefix('publicador/empresas/{conta}/agente')->where(['conta' => '(empresa|company)-[0-9]+'])
            ->name('publicador.agente')->group(function () {
                Route::get('/', [MlbPublicadorAgenteController::class, 'mostrar'])->name('');
                Route::put('configuracao', [MlbPublicadorAgenteController::class, 'salvarConfiguracao'])
                    ->middleware('throttle:30,1,publicador.agente.configuracao')->name('.configuracao');
                Route::post('executar', [MlbPublicadorAgenteController::class, 'executar'])
                    ->middleware('throttle:30,1,publicador.agente.executar')->name('.executar');
                Route::post('pausar', [MlbPublicadorAgenteController::class, 'pausar'])
                    ->middleware('throttle:30,1,publicador.agente.controle')->name('.pausar');
                Route::post('suspender', [MlbPublicadorAgenteController::class, 'suspender'])
                    ->middleware('throttle:30,1,publicador.agente.controle')->name('.suspender');
                Route::post('retomar', [MlbPublicadorAgenteController::class, 'retomar'])
                    ->middleware('throttle:30,1,publicador.agente.controle')->name('.retomar');
            });

        // Fase 184, Plano 11 (BLING-20, BLING-27, BLING-31) — as sete ações do bloco do
        // Bling nas Configurações da loja (plano 12 é a tela).
        //
        // ⚠️ Throttle de prefixo PRÓPRIO por ação (`publicador.bling.<ação>`), nunca um
        // prefixo compartilhado: um prefixo só faria a chamada de uma conta consumir a
        // cota do THROTTLE de outra conta diferente, mascarando o limite de uma empresa
        // específica que precisa de mais tentativas. `leitura`, `importar` e `estoque`
        // têm cota BAIXA (6/min) de propósito: cada chamada consome a cota do Bling DO
        // CLIENTE, não a nossa — gastar essa cota à toa é o risco, não o nosso servidor.
        //
        // `{conta}` com a MESMA trava das vizinhas (`where('conta', '(empresa|company)-
        // [0-9]+')`), repetida em CADA rota (convenção do arquivo — `identidade`,
        // `visao-geral`, `configuracoes` e `acoes/adiar` fazem o mesmo, cada uma com o
        // próprio `->where()`): fora do padrão, morre na ROTA, antes do controller
        // (T-184-75).
        //
        // Nenhuma colisão com rota vizinha: `publicador/empresas/{conta}` (tela B) só
        // casa a URI sem segmento extra, `identidade`/`visao-geral`/`configuracoes`/
        // `acoes/adiar` têm os próprios literais, e `bling` não existe em nenhuma delas
        // — a mesma conferência que o comentário de `acoes/adiar` já registrou aqui.
        Route::get('publicador/empresas/{conta}/bling', [MlbPublicadorBlingController::class, 'estado'])
            ->where('conta', '(empresa|company)-[0-9]+')
            ->middleware('throttle:60,1,publicador.bling.estado')->name('publicador.bling.estado');
        Route::post('publicador/empresas/{conta}/bling/link', [MlbPublicadorBlingController::class, 'gerarLink'])
            ->where('conta', '(empresa|company)-[0-9]+')
            ->middleware('throttle:10,1,publicador.bling.link')->name('publicador.bling.link');
        Route::delete('publicador/empresas/{conta}/bling', [MlbPublicadorBlingController::class, 'desconectar'])
            ->where('conta', '(empresa|company)-[0-9]+')
            ->middleware('throttle:10,1,publicador.bling.desconectar')->name('publicador.bling.desconectar');
        Route::post('publicador/empresas/{conta}/bling/leitura', [MlbPublicadorBlingController::class, 'iniciarLeitura'])
            ->where('conta', '(empresa|company)-[0-9]+')
            ->middleware('throttle:6,1,publicador.bling.leitura')->name('publicador.bling.leitura');
        Route::get('publicador/empresas/{conta}/bling/leitura/{rodada}', [MlbPublicadorBlingController::class, 'progresso'])
            ->where('conta', '(empresa|company)-[0-9]+')
            ->middleware('throttle:120,1,publicador.bling.progresso')->name('publicador.bling.leitura.progresso');
        Route::post('publicador/empresas/{conta}/bling/importar', [MlbPublicadorBlingController::class, 'importar'])
            ->where('conta', '(empresa|company)-[0-9]+')
            ->middleware('throttle:6,1,publicador.bling.importar')->name('publicador.bling.importar');
        Route::post('publicador/empresas/{conta}/bling/estoque', [MlbPublicadorBlingController::class, 'atualizarEstoque'])
            ->where('conta', '(empresa|company)-[0-9]+')
            ->middleware('throttle:6,1,publicador.bling.estoque')->name('publicador.bling.estoque');

        // 179-05 (VG-11): adiar uma ação do bloco "O que fazer agora" até amanhã às
        // 8h, para QUEM adiou nesta conta. Fica ao lado da `visao-geral` porque é a
        // única tela que a dispara — mesmo grupo, mesmo padrão das vizinhas.
        //
        // `acoes/adiar` NÃO colide com nenhuma rota do módulo: `publicador/empresas/
        // {conta}/produtos/{produto}` tem `produtos` literal e `{produto}` numérico,
        // `publicador/empresas/{conta}/sincronizar/{pedido}` tem `sincronizar` literal
        // e `{pedido}` UUID, e `publicador/empresas/{conta}` (a tela B) só casa a URI
        // sem segmento extra. Nenhuma rota existente mudou de nome, verbo, URI ou
        // ordem (REG-05).
        //
        // ⚠️ O `where` da conta é a MESMA trava das vizinhas: `{conta}` fora do padrão
        // morre na ROTA, antes do controller (T-179-17). Throttle NOMEADO: 60/min
        // porque adiar é clique de rotina (uma por ação, até 12 por conta), bem acima
        // do `20,1` do Sincronizar, que faz trabalho de verdade.
        Route::post('publicador/empresas/{conta}/acoes/adiar', [MlbPublicadorEntradaController::class, 'adiarAcao'])
            ->where('conta', '(empresa|company)-[0-9]+')
            ->middleware('throttle:60,1,publicador.acoes.adiar')->name('publicador.acoes.adiar');

        // ─── Fase 175, Plano 04 (§3 da ETAPA-3) — a tela do Produto ───
        // Nome `publicador.produto` (SINGULAR): `publicador.produtos` já é a lista da
        // tela B e `publicador.editor` já é o editor por produto — sem colisão no Ziggy.
        // Vem DEPOIS de `publicador.produtos.criar` (que é POST no mesmo caminho, sem
        // colidir) e nunca é confundida com a rota literal `publicador/empresas-busca`
        // (que não tem hífen seguido de dígitos). T-175-15: {conta} fora do padrão e
        // {produto} não numérico morrem na própria rota, antes do controller; nenhum
        // {conta} desta fase vem do corpo da requisição (D-13).
        Route::get('publicador/empresas/{conta}/produtos/{produto}', [MlbPublicadorFaseController::class, 'mostrar'])
            ->where('conta', '(empresa|company)-[0-9]+')->whereNumber('produto')->name('publicador.produto');

        // ─── Fase 175, Plano 05 (§4 da ETAPA-3) — o painel "Criar Fase N" ───
        // A prévia LÊ (nunca grava) e a criação grava numa transação. As duas têm
        // throttle NOMEADO: a criação com o mesmo teto do `publicador.produtos.criar`
        // (a §8 manda seguir o padrão dele) e a prévia mais folgada, porque o painel
        // a chama a cada mudança de quantidade. T-175-19/T-175-20.
        Route::get('publicador/empresas/{conta}/produtos/{produto}/fases/previa', [MlbPublicadorFaseController::class, 'previa'])
            ->where('conta', '(empresa|company)-[0-9]+')->whereNumber('produto')
            ->middleware('throttle:120,1,publicador.fases.previa')->name('publicador.fases.previa');
        Route::post('publicador/empresas/{conta}/produtos/{produto}/fases', [MlbPublicadorFaseController::class, 'criar'])
            ->where('conta', '(empresa|company)-[0-9]+')->whereNumber('produto')
            ->middleware('throttle:60,1,publicador.fases.criar')->name('publicador.fases.criar');

        // ─── Fase 175, Plano 06 (§4) — "Sugerir com IA" do painel "Criar Fase N" ───
        // Só TÍTULO e DESCRIÇÃO (`SugestaoKitIaService::ALVOS`), assíncrono: o POST
        // enfileira e responde 202, o GET é o polling. Mesmos tetos do
        // `publicador.palavras-ia` (20/min para gastar IA, 240/min para perguntar o
        // estado) — a IA custa dinheiro, o polling não. O `{alvo}` morre na própria
        // rota e `{conta}` vem SEMPRE do resolver, nunca do corpo (D-13).
        Route::post('publicador/empresas/{conta}/produtos/{produto}/fases/ia', [MlbPublicadorFaseController::class, 'pedirIa'])
            ->where('conta', '(empresa|company)-[0-9]+')->whereNumber('produto')
            ->middleware('throttle:20,1,publicador.fases.ia')->name('publicador.fases.ia');
        Route::get('publicador/empresas/{conta}/produtos/{produto}/fases/ia/{alvo}', [MlbPublicadorFaseController::class, 'iaStatus'])
            ->where('conta', '(empresa|company)-[0-9]+')->whereNumber('produto')->where('alvo', 'titulo|descricao')
            ->middleware('throttle:240,1,publicador.fases.ia.status')->name('publicador.fases.ia.status');

        // ─── Fase 175, Plano 08 (§6) — o vínculo de combo JÁ cadastrado ───
        // Vincular NÃO cria nada: grava `produto_base_id`, `quantidade_kit` e `fase`
        // (mais `estoque_calculado = false`) e deixa rascunho, SKU, estoque,
        // publicações e MLBs intocados — o contrário do `fases.criar`, que clona.
        // Mesmo teto do `publicador.produtos.criar` (a §8 manda seguir o padrão dele).
        // ⚠️ T-175-32: `base_id` é o ÚNICO id de entidade que vem do CORPO nesta fase;
        // o controller o resolve DENTRO do escopo da conta e devolve 404 fora dele.
        Route::put('publicador/empresas/{conta}/produtos/{produto}/vinculo', [MlbPublicadorFaseController::class, 'vincular'])
            ->where('conta', '(empresa|company)-[0-9]+')->whereNumber('produto')
            ->middleware('throttle:60,1,publicador.vinculo')->name('publicador.vinculo.salvar');
        Route::delete('publicador/empresas/{conta}/produtos/{produto}/vinculo', [MlbPublicadorFaseController::class, 'desvincular'])
            ->where('conta', '(empresa|company)-[0-9]+')->whereNumber('produto')
            ->middleware('throttle:60,1,publicador.vinculo')->name('publicador.vinculo.remover');
        // "Não é kit": carimbo de decisão humana, por isso tem throttle próprio.
        Route::post('publicador/empresas/{conta}/produtos/{produto}/vinculo/recusar', [MlbPublicadorFaseController::class, 'recusarSugestao'])
            ->where('conta', '(empresa|company)-[0-9]+')->whereNumber('produto')
            ->middleware('throttle:60,1,publicador.vinculo.recusar')->name('publicador.vinculo.recusar');
        // Quick 261009-uec (§6): "Usar estoque calculado" — a ação explícita que
        // faz ESTE kit passar a acompanhar o estoque do base. Mesmo teto e mesmo
        // balde do vínculo: é a mesma família de ação, decidida pela pessoa no
        // cartão da fase. Nada recalcula sozinho — o recálculo automático segue
        // pulando quem tem `estoque_calculado = false`.
        Route::post('publicador/empresas/{conta}/produtos/{produto}/estoque-calculado', [MlbPublicadorFaseController::class, 'usarEstoqueCalculado'])
            ->where('conta', '(empresa|company)-[0-9]+')->whereNumber('produto')
            ->middleware('throttle:60,1,publicador.vinculo')->name('publicador.vinculo.estoque-calculado');

        // ─── 10/10/2026 — Publicação em lote: visão rápida, "Conferir selecionados" e a fila com intervalo ───
        // A conta vem da rota (resolver), nunca do corpo; produto de outra conta é ignorado e item de outra
        // conta é 404. Quem anda a fila é o `publicador:fila-publicacao` (routes/console.php), não o request.
        Route::prefix('publicador/empresas/{conta}/lote')
            ->where(['conta' => '(empresa|company)-[0-9]+'])
            ->name('publicador.lote.')
            ->group(function () {
                Route::get('/', [MlbPublicadorLoteController::class, 'index'])->name('index');
                Route::get('dados', [MlbPublicadorLoteController::class, 'dados'])
                    ->middleware('throttle:120,1,publicador.lote.dados')->name('dados');
                Route::post('conferir', [MlbPublicadorLoteController::class, 'conferir'])
                    ->middleware('throttle:10,1,publicador.lote.conferir')->name('conferir');
                Route::post('agendar', [MlbPublicadorLoteController::class, 'agendar'])
                    ->middleware('throttle:20,1,publicador.lote.agendar')->name('agendar');
                Route::post('pausar', [MlbPublicadorLoteController::class, 'pausar'])
                    ->middleware('throttle:30,1,publicador.lote.fila')->name('pausar');
                Route::post('retomar', [MlbPublicadorLoteController::class, 'retomar'])
                    ->middleware('throttle:30,1,publicador.lote.fila')->name('retomar');
                Route::post('cancelar', [MlbPublicadorLoteController::class, 'cancelar'])
                    ->middleware('throttle:30,1,publicador.lote.fila')->name('cancelar');
                Route::delete('itens/{item}', [MlbPublicadorLoteController::class, 'removerItem'])
                    ->whereNumber('item')->middleware('throttle:60,1,publicador.lote.fila')->name('itens.remover');
                // 10/10/2026 — garantia padrão da conta (o Portal não pergunta; o ML não publica sem).
                Route::put('garantia', [MlbPublicadorLoteController::class, 'garantia'])
                    ->middleware('throttle:20,1,publicador.lote.garantia')->name('garantia');
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

        // ─── Fase 187 (plano 02): a fila "Aguardando aprovação" ───────────────
        //
        // A decisão estrutural da milestone é que **a aba e a rota nascem juntas**: uma aba
        // com contador real que não leva a lugar nenhum é a definição de enfeite. Esta rota
        // nasce no plano 02 e a aba que leva a ela no plano 03 — nenhum momento da fase tem
        // aba apontando para o vazio.
        //
        // ⚠️⚠️ DIVERGÊNCIA DECLARADA E MEDIDA. ABA-06 e o `design_handoff_publicador/
        // README-v2.md` dizem `…/publicacoes` (lista) e `…/publicacoes/aprovacao` (fila). A
        // rota real da lista neste sistema é `/mlb/anuncios/meus/{company}` (a linha `:445`
        // acima, `name('meus')`), e o README chama as dele de *"Rotas sugeridas"*. Renomear a
        // rota da lista quebraria (a) o critério 4 desta fase ("a lista continua como está"),
        // (b) TERR-08 (URL e filtro salvo continuam funcionando) e (c) 22 testes de
        // `tests/Feature/Phase134/MeusAnunciosTest.php`, que usam o nome `mlb.anuncios.meus`.
        // ➡️ **A fila pendura na rota que EXISTE.** A revisão detalhada
        // (`…/aprovacao/{oferta}/{aba}`) é da Fase 190/191 e NÃO nasce aqui.
        //
        // ⚠️ Mesmo gate `role:admin` do grupo, **sem middleware novo** (T-187-12).
        //
        // ⚠️ Esta URI tem DOIS segmentos depois de `meus`; `meus.detalhe` tem TRÊS — as duas
        // não podem colidir por estrutura. Mesmo assim há gate resolvendo as duas **pelo
        // router** (`FilaDeAprovacaoRotaTest::test_rota_da_fila_nao_rouba_o_detalhe_do_
        // anuncio_187_02`), porque a pergunta que continua podendo ter a resposta errada
        // amanhã é "qual rota ganha esta URI" — uma curinga de dois segmentos registrada
        // antes desta linha roubaria a fila em silêncio.
        Route::get('/meus/{company}/aprovacao', [MlbPublicadorAprovacaoController::class, 'index'])
            ->name('meus.aprovacao');

        // ─── Fase 187 (plano 09): APROVAR EM LOTE — a PRIMEIRA ESCRITA da fila ────
        //
        // ⚠️⚠️ **APROVAR JÁ PUBLICA, SEM SEGUNDO CLIQUE** (decisão 3 do usuário, 14/10/2026):
        // não há rota de "confirmar", de "desfazer" nem de "cancelar aprovação", e isso é
        // desenho, não omissão. O que existe é o VEREDICTO antes do clique — o modal lista
        // todas as selecionadas com ✓/× e o motivo de cada exclusão.
        //
        // ⚠️⚠️ **A FRONTEIRA DO D-11, DECLARADA.** `tests/js/estrutura-meus-anuncios.test.js`
        // prova que **a LISTA de Publicações nunca escreve no Mercado Livre**, e isso continua
        // verdade: aquele gate é sobre os arquivos da lista, que este plano não toca. **A FILA
        // escreve**, e esta rota é a primeira escrita dela. ⚠️ E nesta fase a escrita é **só no
        // nosso banco**: `aprovarEmLote` move o andamento e congela a composição; o gancho
        // `aoAprovar`, que publica, está VAZIO e é da **Fase 193**. Há caso de teste provando
        // por AUSÊNCIA de requisição HTTP, não por leitura de código.
        //
        // ⚠️ Mesmo gate `role:admin` do grupo, **sem middleware novo** (T-187-91) — mais a
        // trava de `ml_token` (404) no controller e a conferência das ofertas contra
        // `FilaDeAprovacaoService::paraAConta()` ANTES de qualquer gravação. CSRF é o normal
        // do módulo: rota POST sem exceção.
        //
        // ⚠️ `throttle:30,1` nomeado, no padrão do módulo. Aprovação em lote é ação humana
        // deliberada e sem desfazer — 30 por minuto é folgado para o uso e estreito para
        // script.
        Route::post('/meus/{company}/aprovacao/lote', [MlbPublicadorAprovacaoController::class, 'aprovarLote'])
            ->middleware('throttle:30,1,publicador.aprovacao.lote')->name('meus.aprovacao.lote');

        // ─── Fase 188 (plano 05): AS SEIS ESCRITAS DO PAINEL DE REVISÃO ─────────────────
        //
        // Três de imagem (coluna de fotos), duas de oferta (rodapé) e a de retry. Todas no
        // MESMO padrão das linhas `:527`-`:528` acima: gate `role:admin` do grupo, **sem
        // middleware novo**, `throttle:30,1` nomeado, CSRF normal do módulo.
        //
        // ⚠️⚠️ **APROVAR JÁ PUBLICA, SEM SEGUNDO CLIQUE** (decisão 1 do usuário). Não existe
        // rota de confirmação, de desfazer, de segundo passo nem de cancelamento de
        // aprovação, e isso é DESENHO, não omissão. Há gate de ausência com isca lendo o
        // código sem comentário, válido para as Fases 187-194.
        //
        // ⚠️⚠️ **E APROVAR ENFILEIRA: a escrita no Mercado Livre é da Fase 193** (critério
        // 18). `AprovacaoDaOfertaService::aprovar()` move o andamento pela única porta, grava
        // os três campos de aprovação e congela a composição — tudo no NOSSO banco. O gancho
        // `aoAprovar`, que fala com o ML, está VAZIO e é daquela fase. Há caso provando por
        // AUSÊNCIA de requisição de escrita, com isca.
        //
        // ⚠️ A TRAVA REAL É DO SERVIDOR. O `disabled=` do botão (plano 188-10) é conforto; a
        // recusa com bloqueio de pé ou fora do `live` é de `aprovar()`, e há caso chamando
        // estas rotas DIRETO, sem passar pela tela.
        //
        // ⚠️ A ordem importa: `/aprovacao/lote` tem DOIS segmentos depois de `meus` e estas
        // têm TRÊS ou mais, então não há como `{oferta}` capturar `lote`. Mesmo assim
        // `whereNumber('oferta')` entra — o invariante que pode mudar amanhã é "qual rota
        // ganha esta URI", e há gate resolvendo as seis pelo ROUTER.
        //
        // ⚠️ `{imagem}` é `pub_aprovacao_imagens.id` (a linha de REVISÃO), nunca
        // `pub_imagens.id` — são tabelas diferentes e confundi-las escreveria na foto errada.
        Route::post('/meus/{company}/aprovacao/{oferta}/imagem/{imagem}/aprovar', [MlbPublicadorAprovacaoController::class, 'aprovarImagem'])
            ->whereNumber(['oferta', 'imagem'])
            ->middleware('throttle:30,1,publicador.aprovacao.painel.imagem-aprovar')->name('meus.aprovacao.painel.imagem.aprovar');

        // ⚠️⚠️ SEM `{conjunto}` NA URI, E ISSO É MEDIDO, NÃO ESTILO. O rótulo "Aprovar N sem
        // alerta" vem de `FotosDoPainelService::para()` → `sem_alerta.quantidade`, que sai de
        // `BloqueiosDaOfertaService::contagemDeFotos()` — e aquela contagem cobre **TODOS os
        // grupos do rascunho** (`PubAprovacaoConjunto::doRascunho()`). `RevisaoDeImagemService::
        // aprovarSemAlerta()` recebe UM conjunto por chamada, então a action PERCORRE os
        // conjuntos e soma. Uma rota por conjunto aprovaria MENOS do que o botão promete, e o
        // admin veria "Aprovar 3" deixando 1 de pé.
        // ⚠️ E casa com a decisão 5 do usuário: o bloqueio de fotos cobre TODOS os grupos —
        // aprovação cor por cor foi RECUSADA.
        Route::post('/meus/{company}/aprovacao/{oferta}/fotos/aprovar-sem-alerta', [MlbPublicadorAprovacaoController::class, 'aprovarFotosSemAlerta'])
            ->whereNumber('oferta')
            ->middleware('throttle:30,1,publicador.aprovacao.painel.sem-alerta')->name('meus.aprovacao.painel.fotos.sem-alerta');

        // ⚠️ MOTIVO OBRIGATÓRIO (IMG-10). A trava real é `RevisaoDeImagemService::reprovar()`,
        // que faz `trim()` antes de conferir e é no-op completo sem motivo.
        Route::post('/meus/{company}/aprovacao/{oferta}/imagem/{imagem}/reprovar', [MlbPublicadorAprovacaoController::class, 'reprovarImagem'])
            ->whereNumber(['oferta', 'imagem'])
            ->middleware('throttle:30,1,publicador.aprovacao.painel.imagem-reprovar')->name('meus.aprovacao.painel.imagem.reprovar');

        // ⚠️ COL-01/COL-02/AND-06 — a revisão parcial fica NO SERVIDOR, nunca em
        // `localStorage`: outra pessoa continua de onde a primeira parou.
        Route::post('/meus/{company}/aprovacao/{oferta}/salvar-revisao', [MlbPublicadorAprovacaoController::class, 'salvarRevisao'])
            ->whereNumber('oferta')
            ->middleware('throttle:30,1,publicador.aprovacao.painel.salvar')->name('meus.aprovacao.painel.salvar-revisao');

        Route::post('/meus/{company}/aprovacao/{oferta}/aprovar', [MlbPublicadorAprovacaoController::class, 'aprovar'])
            ->whereNumber('oferta')
            ->middleware('throttle:30,1,publicador.aprovacao.painel.aprovar')->name('meus.aprovacao.painel.aprovar');

        // ⚠️⚠️ REVS-05 / BLOQ-11 — "TENTAR PUBLICAR NOVAMENTE". Medido antes de escrever:
        // `grep tentarDeNovo routes/` saía VAZIO — o serviço da Fase 186 existe
        // (`AprovacaoDaOfertaService::tentarDeNovo()`) e NENHUMA rota o chamava. Sem rota a
        // caixa de falha teria botão sem destino, que é D4 violado.
        // ⚠️ E ela também NÃO publica: move o andamento pela única porta e registra. A escrita
        // no Mercado Livre segue sendo da Fase 193.
        Route::post('/meus/{company}/aprovacao/{oferta}/tentar-de-novo', [MlbPublicadorAprovacaoController::class, 'tentarDeNovo'])
            ->whereNumber('oferta')
            ->middleware('throttle:30,1,publicador.aprovacao.painel.retry')->name('meus.aprovacao.painel.tentar-de-novo');

        // ─── Fase 188 (plano 06): PREÇO, CONFERIR ALTERAÇÃO E FRETE ────────────────────
        //
        // Cinco rotas, no MESMO padrão das linhas `:527`-`:528` e das seis do plano 188-05:
        // gate `role:admin` do grupo, **sem middleware novo**, throttle nomeado, CSRF normal,
        // trava de `ml_token` (404) e a oferta conferida contra `FilaDeAprovacaoService::
        // paraAConta()` ANTES de qualquer gravação.
        //
        // ⚠️⚠️ **DUAS DELAS NÃO GRAVAM NADA** — `preco/simular` e `frete/previa`. Elas existem
        // porque o critério 6 (simular preço) e o 14 (ver o resultado ANTES de confirmar o
        // frete) **não tinham caminho nenhum**: o cálculo é do servidor (o plano 188-09 PROÍBE
        // aritmética de taxa no cliente) e nenhuma rota recebia o valor digitado. Há gate de
        // ausência de ESCRITA nas duas, ancorado no **operador de chamada**.
        //
        // ⚠️ **POST, e não GET, de propósito, nas duas de leitura:** o valor é entrada da
        // pessoa e **não é bookmarkável**. Preço simulado na URL faria um link compartilhado
        // abrir a tela com a simulação de outra pessoa, e a decisão de *"gesto não vai para a
        // URL"* já está tomada neste módulo (o modal de lote, `Aprovacao.jsx:316`-`:317`).
        //
        // ⚠️ `throttle` MAIS FOLGADO nas duas de leitura (`120,1`): a pessoa digita e vê, e um
        // `30,1` apagaria a prévia no meio da digitação. As três que GRAVAM ficam em `30,1`,
        // como as seis do 188-05 — ato humano deliberado e sem desfazer.
        //
        // ⚠️ A ordem das rotas: `/preco` tem TRÊS segmentos depois de `meus` e
        // `/preco/simular` tem QUATRO, então não há como uma capturar a outra; o mesmo vale
        // para `/frete` e `/frete/previa`. Mesmo assim `whereNumber('oferta')` entra — o
        // invariante que pode mudar amanhã é "qual rota ganha esta URI", e há gate resolvendo
        // as cinco pelo ROUTER.
        Route::post('/meus/{company}/aprovacao/{oferta}/preco', [MlbPublicadorAprovacaoController::class, 'salvarPreco'])
            ->whereNumber('oferta')
            ->middleware('throttle:30,1,publicador.aprovacao.painel.preco')->name('meus.aprovacao.painel.preco');

        // ⚠️⚠️ O TRANSPORTE DA SIMULAÇÃO (FIN-11/FIN-12). `PrecoDoPainelService::simular()`
        // existe desde o plano 188-04 e **nenhuma rota recebia o preço digitado** — era o
        // BLOCKER do verificador. ⚠️ Ela é **LEITURA PURA com entrada no corpo**: nada é
        // persistido, nada vai ao Mercado Livre além da LEITURA de tarifa (decisão 9).
        Route::post('/meus/{company}/aprovacao/{oferta}/preco/simular', [MlbPublicadorAprovacaoController::class, 'simularPreco'])
            ->whereNumber('oferta')
            ->middleware('throttle:120,1,publicador.aprovacao.painel.preco-simular')->name('meus.aprovacao.painel.preco.simular');

        // ⚠️⚠️ BLOQ-07 — "CONFERIR ALTERAÇÃO" **NÃO ALTERA O PREÇO**, e isso é contraintuitivo:
        // o botão se chama "Conferir alteração" e **não reaplica nada** (`:1170`:
        // `D.offers[id].priceChanged = null` e só). Quem confere está dizendo *"eu vi que
        // mudou"*, e é isso que faz o bloqueio `alteracao_nao_conferida` sair.
        Route::post('/meus/{company}/aprovacao/{oferta}/conferir-alteracao', [MlbPublicadorAprovacaoController::class, 'conferirAlteracao'])
            ->whereNumber('oferta')
            ->middleware('throttle:30,1,publicador.aprovacao.painel.conferir-alteracao')->name('meus.aprovacao.painel.conferir-alteracao');

        // ⚠️ MOD-03/DADO-13 — confirmar o frete grava valor + origem `Confirmado` + a nota que
        // **nomeia a fonte e a data**, e INCREMENTA a versão. As TRÊS origens são lista
        // FECHADA (`PubAprovacaoFrete::ORIGENS`): valor fora dela é 422, com o id estável
        // `MOD-03-ORIGEM-DESCONHECIDA` do próprio modelo.
        Route::post('/meus/{company}/aprovacao/{oferta}/frete', [MlbPublicadorAprovacaoController::class, 'confirmarFrete'])
            ->whereNumber('oferta')
            ->middleware('throttle:30,1,publicador.aprovacao.painel.frete')->name('meus.aprovacao.painel.frete');

        // ⚠️⚠️ A PRÉVIA DO FRETE (critério 14): *"mostra a prévia do resultado e da margem
        // ANTES de confirmar"*. ⚠️ **Ela NÃO GRAVA** — gate de ausência de escrita, com isca.
        Route::post('/meus/{company}/aprovacao/{oferta}/frete/previa', [MlbPublicadorAprovacaoController::class, 'previaDoFrete'])
            ->whereNumber('oferta')
            ->middleware('throttle:120,1,publicador.aprovacao.painel.frete-previa')->name('meus.aprovacao.painel.frete.previa');

        // ─── Fase 188 (plano 06, tarefa 2): AJUSTE COM ESCOPO, RESOLVER E EXCEÇÃO ──────
        //
        // As TRÊS que mexem no ANDAMENTO, e **sempre pela ÚNICA PORTA**
        // (`AndamentoDaOfertaService::mover`, por dentro de `AjustesDaOfertaService`): nenhuma
        // action escreve a coluna, e nenhuma delas devolve o andamento numa chave de saída.
        //
        // ⚠️⚠️ **CRITÉRIO 12 — O ESCOPO DECIDE QUANTAS OFERTAS SAEM DA FILA**, e é a parte que
        // erra em silêncio: `offer` move só aquela, `set` move as DUAS do rascunho (o conjunto de
        // fotos é COMPARTILHADO) e `product` move TODAS as ofertas do produto. Se um pedido de
        // conjunto movesse só o Clássico, **o Premium iria ao ar com a foto reprovada** — e
        // aprovar já publica, sem desfazer.
        //
        // ⚠️ AND-07 — resolver o **ÚLTIMO** ajuste aberto devolve a oferta para
        // `aguardando_aprovacao`; havendo **outro** aberto, ela **continua** em
        // `ajustes_solicitados`. Os dois sentidos têm caso próprio.
        //
        // ⚠️⚠️ **CRITÉRIO 11 / DECISÃO 6 DO USUÁRIO — A EXCEÇÃO SÓ REGISTRA QUEM LIBEROU.** Não
        // existe estado de espera, não existe segundo passo, não existe rota de autorização
        // posterior: o controle é **auditoria DEPOIS, não barreira ANTES**. E ela libera **SÓ**
        // resultado negativo e margem abaixo do mínimo — nunca fotos, frete, alteração, ajuste ou
        // título. As duas travas são de `AjustesDaOfertaService`/`PubAprovacaoExcecao`, que estão
        // EM PRODUÇÃO desde a Fase 186.
        //
        // ⚠️ `throttle:30,1` nomeado nas três, como as seis do 188-05: ato humano deliberado.
        // Pedir ajuste em massa por script é o T-188-57, e é por isso que o teto é estreito.
        Route::post('/meus/{company}/aprovacao/{oferta}/ajuste', [MlbPublicadorAprovacaoController::class, 'pedirAjuste'])
            ->whereNumber('oferta')
            ->middleware('throttle:30,1,publicador.aprovacao.painel.ajuste')->name('meus.aprovacao.painel.ajuste');

        // ⚠️ `{ajuste}` é conferido contra **os ajustes que afetam ESTA oferta**
        // (`AjustesDaOfertaService::abertosQueAfetam()`), não contra a tabela inteira: ajuste de
        // outra conta dá **404, nunca 403** (T-188-58) — um 403 confirmaria que o id existe.
        Route::post('/meus/{company}/aprovacao/{oferta}/ajuste/{ajuste}/resolver', [MlbPublicadorAprovacaoController::class, 'resolverAjuste'])
            ->whereNumber(['oferta', 'ajuste'])
            ->middleware('throttle:30,1,publicador.aprovacao.painel.ajuste-resolver')->name('meus.aprovacao.painel.ajuste.resolver');

        Route::post('/meus/{company}/aprovacao/{oferta}/excecao', [MlbPublicadorAprovacaoController::class, 'registrarExcecao'])
            ->whereNumber('oferta')
            ->middleware('throttle:30,1,publicador.aprovacao.painel.excecao')->name('meus.aprovacao.painel.excecao');

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

/*
|--------------------------------------------------------------------------
| Tarefas pós-publicação — "Publicados aguardando alavancas" (09/10/2026)
|--------------------------------------------------------------------------
|
| A ÚNICA parte deste arquivo FORA do `role:admin`, de propósito: quem usa as
| alavancas (o "Caio" da reunião) pode não ser admin. A chave própria
| `mlb.alavancas` (liberada por setor) dá VER e OPERAR a fila — pegar, marcar o
| checklist, concluir, observação. Nada aqui fala com o Mercado Livre; escrever
| nas Alavancas continua no grupo admin acima, e o responsável padrão só o
| admin escolhe. Admin passa por qualquer chave (`User::hasPermission`).
|
*/
Route::middleware(['auth', 'verified', 'permission:'.Permissions::MLB_ALAVANCAS])
    ->prefix('mlb/anuncios/publicador/tarefas')
    ->name('mlb.anuncios.publicador.tarefas.')
    ->group(function () {
        Route::get('/', [MlbPublicadorTarefasController::class, 'index'])->name('index');
        Route::put('responsavel-padrao', [MlbPublicadorTarefasController::class, 'responsavelPadrao'])
            ->middleware(['role:admin', 'throttle:30,1,publicador.tarefas.responsavel'])->name('responsavel-padrao');
        Route::post('{tarefa}/pegar', [MlbPublicadorTarefasController::class, 'pegar'])
            ->whereNumber('tarefa')->middleware('throttle:120,1,publicador.tarefas.acao')->name('pegar');
        Route::put('{tarefa}/itens/{chave}', [MlbPublicadorTarefasController::class, 'marcar'])
            ->whereNumber('tarefa')->whereIn('chave', array_keys(PubTarefa::CHECKLIST_ALAVANCAS))
            ->middleware('throttle:120,1,publicador.tarefas.acao')->name('marcar');
        Route::post('{tarefa}/concluir', [MlbPublicadorTarefasController::class, 'concluir'])
            ->whereNumber('tarefa')->middleware('throttle:120,1,publicador.tarefas.acao')->name('concluir');
        Route::put('{tarefa}/observacao', [MlbPublicadorTarefasController::class, 'observacao'])
            ->whereNumber('tarefa')->middleware('throttle:120,1,publicador.tarefas.acao')->name('observacao');
    });
