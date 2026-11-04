<?php

use App\Http\Controllers\Trilha\AjusteDaTrilhaController;
use App\Http\Controllers\Trilha\CadastroDaTrilhaController;
use App\Http\Controllers\Trilha\Cliente\FotosDoClienteController;
use App\Http\Controllers\Trilha\FotosDaTrilhaController;
use App\Http\Controllers\Trilha\MapeamentoDaTrilhaController;
use App\Http\Controllers\Trilha\MercadoLivreDaTrilhaController;
use App\Http\Controllers\Trilha\OndasDaTrilhaController;
use App\Http\Controllers\Trilha\PlanilhaDaTrilhaController;
use App\Http\Controllers\Trilha\PortalTrilhaController;
use App\Http\Controllers\Trilha\PrecoDaTrilhaController;
use App\Http\Controllers\Trilha\PreviaDaTrilhaController;
use App\Http\Controllers\Trilha\PublicacaoDaTrilhaController;
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
| e para quem pode escrever no Portal dela (os controllers conferem). Cada
| escrita tem o seu prefixo de throttle.
|
| 4ª entrega, 4f (04/11/2026): a porta do CLIENTE ganha a 1ª escrita — as
| fotos do produto enquanto ele está na Lista (enviar e excluir), nas rotas
| `portal.trilha.fotos.*`. São as ÚNICAS escritas no `portal.auth` da trilha
| (o `OndasDaTrilhaTest` confere pelo endereço).
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
    ->where(['company' => '[0-9]{1,18}', 'onda' => '[0-9]{1,18}', 'produto' => '[0-9]{1,18}', 'variacao' => '[0-9]{1,18}', 'imagem' => '[0-9]{1,18}'])
    ->group(function () {
        Route::get('/', [TrilhaController::class, 'index'])->name('index');
        Route::get('/{company}', [TrilhaController::class, 'empresa'])->name('empresa');

        // Cadastro (3ª entrega, 02/11/2026): criar o produto (nome + SKU), abrir a gaveta e gravar as caixas — pelos
        // serviços do Portal, sem o Portal. O controller confere as duas chaves, a escrita no Portal da empresa e o
        // produto do endereço. `POST produtos` é o endereço `escrita.produtos` que a tela já recebe.
        Route::post('/{company}/produtos', [CadastroDaTrilhaController::class, 'criar'])
            ->middleware('throttle:30,1,trilha.cadastro.criar')->name('cadastro.criar');
        Route::get('/{company}/produtos/{produto}/cadastro', [CadastroDaTrilhaController::class, 'abrir'])
            ->middleware('throttle:120,1,trilha.cadastro.abrir')->name('cadastro.abrir');
        Route::post('/{company}/produtos/{produto}/linhas', [CadastroDaTrilhaController::class, 'linhas'])
            ->middleware('throttle:120,1,trilha.cadastro.linhas')->name('cadastro.linhas');
        // A ficha técnica (F2): grava a ficha inteira da gaveta, incompleta se for o caso; e o "Preencher com IA" dela
        // (F2b), que só sugere — a IA é paga por clique, daí o teto baixo.
        Route::put('/{company}/produtos/{produto}/ficha-tecnica', [CadastroDaTrilhaController::class, 'ficha'])
            ->middleware('throttle:60,1,trilha.cadastro.ficha')->name('cadastro.ficha');
        Route::post('/{company}/produtos/{produto}/ficha-tecnica/ia', [CadastroDaTrilhaController::class, 'sugerirFicha'])
            ->middleware('throttle:10,1,trilha.cadastro.ia')->name('cadastro.ia');
        // Fotos e frete (F3): enviar (`imagens[]`, multipart) e excluir a foto da variação — a variação presa ao produto
        // do caminho e a imagem à variação —, e o "Conferir frete pela conta" (só leitura na conta da loja). O 413 do
        // envio grande demais vira 422 no `bootstrap/app.php`, como no Portal.
        Route::post('/{company}/produtos/{produto}/variacoes/{variacao}/fotos', [FotosDaTrilhaController::class, 'enviar'])
            ->middleware('throttle:30,1,trilha.cadastro.fotos')->name('cadastro.fotos');
        Route::delete('/{company}/produtos/{produto}/variacoes/{variacao}/fotos/{imagem}', [FotosDaTrilhaController::class, 'excluir'])
            ->middleware('throttle:60,1,trilha.cadastro.fotos.excluir')->name('cadastro.fotos.excluir');
        Route::post('/{company}/produtos/{produto}/frete', [CadastroDaTrilhaController::class, 'frete'])
            ->middleware('throttle:20,1,trilha.cadastro.frete')->name('cadastro.frete');
        // Excluir (F4): a cor ou o tamanho (a variação presa ao produto do caminho; a última só sai pelo "Excluir
        // produto") e o produto inteiro em dois passos — a prévia do que sai junto e a exclusão com as montadas
        // confirmadas (os dois no mesmo contador).
        Route::delete('/{company}/produtos/{produto}/variacoes/{variacao}', [CadastroDaTrilhaController::class, 'excluirVariacao'])
            ->middleware('throttle:30,1,trilha.cadastro.variacao')->name('cadastro.variacao');
        Route::post('/{company}/produtos/{produto}/exclusao/previa', [CadastroDaTrilhaController::class, 'previaDaExclusao'])
            ->middleware('throttle:10,1,trilha.cadastro.exclusao')->name('cadastro.exclusao.previa');
        Route::delete('/{company}/produtos/{produto}', [CadastroDaTrilhaController::class, 'excluirProduto'])
            ->middleware('throttle:10,1,trilha.cadastro.exclusao')->name('cadastro.exclusao');

        // A planilha por categoria (F7, desenho §17): baixar o modelo das categorias (`categorias[]`, 1 a 10), a prévia do
        // arquivo preenchido (nada grava) e o gravar (multipart `arquivo`; o gravar leva `versoes[{produto}]` da prévia).
        // Cada uma com o seu contador; o 413 do arquivo grande vira 422 `errors.arquivo` no `bootstrap/app.php`.
        Route::get('/{company}/planilha/modelo', [PlanilhaDaTrilhaController::class, 'modelo'])
            ->middleware('throttle:30,1,trilha.planilha.modelo')->name('planilha.modelo');
        Route::post('/{company}/planilha/previa', [PlanilhaDaTrilhaController::class, 'previa'])
            ->middleware('throttle:10,1,trilha.planilha.previa')->name('planilha.previa');
        Route::post('/{company}/planilha', [PlanilhaDaTrilhaController::class, 'gravar'])
            ->middleware('throttle:6,1,trilha.planilha.gravar')->name('planilha.gravar');

        // Importar do Mercado Livre (F8, desenho §18): a lista dos anúncios da loja (o acervo, sem chamar o ML), o pedido
        // (`mlbs[]`, 1 a 20 — um Job por anúncio, na fila da IA), o estado do lote (a tela pergunta a cada 2 s) e o
        // "limpar" do painel. Cada uma com o seu contador.
        Route::get('/{company}/mercado-livre/anuncios', [MercadoLivreDaTrilhaController::class, 'anuncios'])
            ->middleware('throttle:120,1,trilha.ml.anuncios')->name('ml.anuncios');
        Route::post('/{company}/mercado-livre/importar', [MercadoLivreDaTrilhaController::class, 'importar'])
            ->middleware('throttle:10,1,trilha.ml.importar')->name('ml.importar');
        Route::get('/{company}/mercado-livre/estado', [MercadoLivreDaTrilhaController::class, 'estado'])
            ->middleware('throttle:240,1,trilha.ml.estado')->name('ml.estado');
        Route::post('/{company}/mercado-livre/limpar', [MercadoLivreDaTrilhaController::class, 'limpar'])
            ->middleware('throttle:30,1,trilha.ml.limpar')->name('ml.limpar');

        // A prévia do anúncio (4ª entrega, 4a, desenho §19.4): o painel `v:{item}` da revisão — só leitura — e as fotos do
        // rascunho do item. `item` é a chave da linha (`s-12`, `c-12-2`, `o-55`). O contador NÃO é o `trilha.previa` da
        // prévia do "Mapear" (seriam o mesmo limite): `trilha.revisao.*`.
        Route::get('/{company}/ofertas/{item}/previa', [PreviaDaTrilhaController::class, 'previa'])
            ->where('item', 's-[0-9]{1,18}|c-[0-9]{1,18}-[0-9]{1,3}|o-[0-9]{1,18}')
            ->middleware('throttle:120,1,trilha.revisao.previa')->name('previa');
        Route::get('/{company}/ofertas/{item}/fotos/{imagem}', [PreviaDaTrilhaController::class, 'foto'])
            ->where('item', 's-[0-9]{1,18}|c-[0-9]{1,18}-[0-9]{1,3}|o-[0-9]{1,18}')
            ->middleware('throttle:600,1,trilha.revisao.foto')->name('previa.foto');
        // 4c (§19.11): o preço ajustável da prévia — grava só no rascunho do anúncio (`pub_variante_precos`), nunca na
        // Precificação. Contador `trilha.revisao.preco` (o nome da rota é o do desenho, `trilha.preco`).
        Route::put('/{company}/ofertas/{item}/preco', [PrecoDaTrilhaController::class, 'ajustar'])
            ->where('item', 's-[0-9]{1,18}|c-[0-9]{1,18}-[0-9]{1,3}|o-[0-9]{1,18}')
            ->middleware('throttle:30,1,trilha.revisao.preco')->name('preco');
        // 4d (§19.13): aprovar e publicar — confere e põe o anúncio na FILA de publicação da loja (quem publica é a fila);
        // só na #459, guarda NO CÓDIGO (`PublicacaoNaTrilha::LOJA`). E o acompanhamento (a tela pergunta a cada 3 s).
        // Contadores `trilha.revisao.publicar` e `trilha.revisao.publicacao` (os nomes das rotas são os do desenho).
        Route::post('/{company}/ofertas/{item}/publicar', [PublicacaoDaTrilhaController::class, 'publicar'])
            ->where('item', 's-[0-9]{1,18}|c-[0-9]{1,18}-[0-9]{1,3}|o-[0-9]{1,18}')
            ->middleware('throttle:10,1,trilha.revisao.publicar')->name('publicar');
        Route::get('/{company}/ofertas/{item}/publicacao', [PublicacaoDaTrilhaController::class, 'publicacao'])
            ->where('item', 's-[0-9]{1,18}|c-[0-9]{1,18}-[0-9]{1,3}|o-[0-9]{1,18}')
            ->middleware('throttle:240,1,trilha.revisao.publicacao')->name('publicacao');
        // 4b (§19.14): pedir ajuste ao robô — refaz o título (com o Modelo) e/ou a descrição pelo preparo do Publicador,
        // na fila da IA; sem texto livre. E o acompanhamento (a tela pergunta a cada 3 s). Contadores próprios,
        // `trilha.revisao.ajuste` e `trilha.revisao.ajuste.estado` (os nomes das rotas são os do desenho).
        Route::post('/{company}/ofertas/{item}/ajuste', [AjusteDaTrilhaController::class, 'pedir'])
            ->where('item', 's-[0-9]{1,18}|c-[0-9]{1,18}-[0-9]{1,3}|o-[0-9]{1,18}')
            ->middleware('throttle:10,1,trilha.revisao.ajuste')->name('ajuste');
        Route::get('/{company}/ofertas/{item}/ajuste', [AjusteDaTrilhaController::class, 'estado'])
            ->where('item', 's-[0-9]{1,18}|c-[0-9]{1,18}-[0-9]{1,3}|o-[0-9]{1,18}')
            ->middleware('throttle:240,1,trilha.revisao.ajuste.estado')->name('ajuste.estado');

        // Mapeamento: o painel "Mapear" do produto (opções, prévia ao vivo e o "Concluir").
        Route::get('/{company}/produtos/{produto}/mapeamento', [MapeamentoDaTrilhaController::class, 'opcoes'])
            ->middleware('throttle:60,1,trilha.mapeamento')->name('mapeamento.opcoes');
        Route::post('/{company}/produtos/{produto}/mapeamento/previa', [MapeamentoDaTrilhaController::class, 'previa'])
            ->middleware('throttle:120,1,trilha.previa')->name('mapeamento.previa');
        Route::post('/{company}/produtos/{produto}/mapeamento', [MapeamentoDaTrilhaController::class, 'concluir'])
            ->middleware('throttle:20,1,trilha.concluir')->name('mapeamento.concluir');
        // A foto da cor no "Mapear" e na galeria do cadastro (a miniatura; `?original=1`, o arquivo enviado): a rota de
        // imagem do Portal, no domínio do admin, leva ao do cliente (sem sessão).
        Route::get('/{company}/fotos/{variacao}/{imagem}', [FotosDaTrilhaController::class, 'ver'])
            ->middleware('throttle:600,1,trilha.fotos')->name('fotos');

        // Categoria e ficha técnica do cadastro (3ª entrega, F2): buscar pelo nome, navegar na árvore, os campos da
        // categoria e os termos do título — só leitura de dado público (APP token). O controller confere as duas chaves
        // e a escrita no Portal da empresa; `MLB` + até 17 dígitos no `where` (o resto é 404).
        Route::get('/{company}/categorias', [\App\Http\Controllers\Trilha\CategoriasDaTrilhaController::class, 'buscar'])
            ->middleware('throttle:60,1,trilha.categorias')->name('categorias');
        Route::get('/{company}/categorias/arvore', [\App\Http\Controllers\Trilha\CategoriasDaTrilhaController::class, 'arvore'])
            ->middleware('throttle:180,1,trilha.categorias.arvore')->name('categorias.arvore');
        Route::get('/{company}/categorias/{categoria}/campos', [\App\Http\Controllers\Trilha\CategoriasDaTrilhaController::class, 'campos'])
            ->where('categoria', 'MLB[0-9]{1,17}')
            ->middleware('throttle:60,1,trilha.categorias.campos')->name('categorias.campos');
        Route::get('/{company}/categorias/{categoria}/titulo', [\App\Http\Controllers\Trilha\CategoriasDaTrilhaController::class, 'titulo'])
            ->where('categoria', 'MLB[0-9]{1,17}')
            ->middleware('throttle:60,1,trilha.categorias.titulo')->name('categorias.titulo');

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

        // 4f (desenho §19.15, D57/D58): as fotos que o CLIENTE envia — o quadro de fotos do produto, o arquivo da foto
        // (só da empresa), enviar (`imagens[]`, multipart) e excluir, só enquanto o produto está na Lista (409 depois).
        // Controllers finos em `Trilha\Cliente\*`, que leem o `PortalContexto` e chamam os MESMOS serviços da equipe com
        // o ator CLIENTE. Cada rota com o seu contador (`trilha.cliente.fotos*`) e a sua linha em
        // `RestringeDominioDoPortal::PERMITIDO_COM_ID`; o 413 do envio grande demais vira 422 `errors.imagens` no
        // `bootstrap/app.php`. `[0-9]{1,18}`: um id de 19 dígitos estouraria o `int` do controller (500) em vez de 404.
        Route::where(['produto' => '[0-9]{1,18}', 'variacao' => '[0-9]{1,18}', 'imagem' => '[0-9]{1,18}'])
            ->name('portal.trilha.fotos.')
            ->group(function () {
                Route::get('/trilha/produtos/{produto}/fotos', [FotosDoClienteController::class, 'quadro'])
                    ->middleware('throttle:120,1,trilha.cliente.fotos')->name('quadro');
                Route::get('/trilha/fotos/{variacao}/{imagem}', [FotosDoClienteController::class, 'ver'])
                    ->middleware('throttle:600,1,trilha.cliente.fotos.ver')->name('ver');
                Route::post('/trilha/produtos/{produto}/variacoes/{variacao}/fotos', [FotosDoClienteController::class, 'enviar'])
                    ->middleware('throttle:30,1,trilha.cliente.fotos.enviar')->name('enviar');
                Route::delete('/trilha/produtos/{produto}/variacoes/{variacao}/fotos/{imagem}', [FotosDoClienteController::class, 'excluir'])
                    ->middleware('throttle:60,1,trilha.cliente.fotos.excluir')->name('excluir');
            });
    });
