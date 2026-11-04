// ─── Trilha de produtos — as escritas da porta da equipe (2ª entrega) ───────
//
// O Mapeamento e o Planejamento (ondas) passaram a ser feitos DENTRO da trilha.
// As escritas são JSON (como o Planejamento do Portal), pelos endereços que o
// servidor manda em `escrita` — só para a equipe com escrita (`pode_editar`); o
// cliente nunca recebe nenhum.
//
// A exceção, desde a 4ª entrega (4f): as fotos que o CLIENTE envia, pelo endereço
// à parte `cliente_escrita.fotos` (só a porta do cliente de verdade o recebe). As
// chamadas delas, no fim do arquivo, recebem a URL PRONTA da caixa.
//
// O axios já vai com o `X-Requested-With` e o `X-CSRF-TOKEN` (resources/js/bootstrap.js,
// renovado a cada resposta do Inertia em app.jsx). Depois de gravar, a tela
// recarrega SÓ os dados da trilha (`recarregar`), sem perder o painel aberto.
//
// Sem classe do Tailwind aqui (arquivo `.js`: o Tailwind só varre `.jsx`).

import axios from 'axios';
import { router } from '@inertiajs/react';
import { nomeDoArquivoBaixado, parametrosDoModelo } from './modeloCompleto.js';
import { ARQUIVO_DA_TRILHA, formularioDaPrevia, formularioDoGravar } from './trilhaPlanilha.js';

/** Erro da própria tela (endereço que não veio, p. ex.): o texto dele pode ir para a pessoa. */
export class ErroDaTrilha extends Error {}

const semBarraNoFim = (url) => String(url).replace(/\/+$/, '');

function endereco(escrita, campo) {
    const url = escrita?.[campo];
    if (!url) throw new ErroDaTrilha('Esta tela não pode gravar: recarregue a página.');

    return semBarraNoFim(url);
}

/** `{escrita.ondas}/{id}`. */
export const urlDaOnda = (escrita, ondaId) => `${endereco(escrita, 'ondas')}/${encodeURIComponent(ondaId)}`;

/** `{escrita.produtos}/{id}/mapeamento`. */
export const urlDoMapeamento = (escrita, produtoId) => `${endereco(escrita, 'produtos')}/${encodeURIComponent(produtoId)}/mapeamento`;

const corpo = (resposta) => resposta?.data ?? {};

// ─── Ondas ──────────────────────────────────────────────────────────────────

/** Cria a onda. → `{onda: {id, nome, data, comecou}, mensagem}` (201). */
export async function criarOnda(escrita, { nome, data }) {
    return corpo(await axios.post(endereco(escrita, 'ondas'), { nome, data }));
}

/** Renomeia e/ou muda a data (a data só muda se a onda não começou). → `{onda, mensagem}`. */
export async function editarOnda(escrita, ondaId, campos) {
    return corpo(await axios.put(urlDaOnda(escrita, ondaId), campos));
}

/** Apaga a onda (só a que não começou): as ofertas dela voltam para "Sem onda". → `{mensagem}`. */
export async function apagarOnda(escrita, ondaId) {
    return corpo(await axios.delete(urlDaOnda(escrita, ondaId)));
}

/**
 * Põe as ofertas da trilha (`s-12`, `c-12-2`, `o-55`) na onda, ou tira (`ondaId` null).
 * → `{mudados: [], avisos: [], mensagem}`. Pôr um combo sem a simples traz a simples junto (vem em `avisos`).
 */
export async function planejarItens(escrita, itens, ondaId) {
    return corpo(await axios.put(endereco(escrita, 'planejamento'), { itens, onda_id: ondaId ?? null }));
}

/**
 * R12 (04/11/2026): põe VÁRIAS ofertas numa onda de uma vez, em `{escrita.planejamento}/lote` — as regras de pôr uma,
 * a resposta por item. → `{onda, resultados: [{item, nome, situacao: entrou|ja_estava|recusado, motivo, junto}],
 * entraram, recusadas, avisos, mensagem}` (200 também com recusas).
 */
export async function planejarEmLote(escrita, itens, ondaId) {
    return corpo(await axios.put(`${endereco(escrita, 'planejamento')}/lote`, { itens, onda_id: ondaId }));
}

// ─── Mapeamento ─────────────────────────────────────────────────────────────

/** As opções do painel de mapear: o produto, os combos, os outros produtos e a marca de mapeado. */
export async function carregarMapeamento(escrita, produtoId) {
    return corpo(await axios.get(urlDoMapeamento(escrita, produtoId)));
}

/** O que vai ser criado com esta escolha — o servidor só calcula, não grava. */
export async function previaDoMapeamento(escrita, produtoId, escolha) {
    return corpo(await axios.post(`${urlDoMapeamento(escrita, produtoId)}/previa`, escolha));
}

/** "Concluir mapeamento": grava tudo ou nada. → `{criadas: [{chave, tipo, nome, skus}], descartadas, mensagem}`. */
export async function concluirMapeamento(escrita, produtoId, escolha) {
    return corpo(await axios.post(urlDoMapeamento(escrita, produtoId), escolha));
}

// ─── Cadastro do produto (3ª entrega) ───────────────────────────────────────
//
// A gaveta `e:` grava pelas rotas da equipe sob `escrita.produtos` (a mesma base do
// mapeamento). Toda escrita num produto que já andou responde 409 `{travado, motivo}`
// (pergunta 1 do desenho): a tela vira só leitura com o motivo.
//
// F6 (a aba velha): as escritas que mudam conteúdo — as linhas, a ficha, remover a
// variação e excluir o produto (a prévia também) — levam a `versao` do retrato que a
// gaveta tem, quando há uma. Diferente da atual, o servidor responde 409
// `{versao_atual, message}` SEM `travado`: a tela pede "Recarregar" e NÃO vira só
// leitura. Fotos e frete não levam versão (não mudam a versão).

/** `{escrita.produtos}/{id}` — a base das rotas do cadastro de um produto. */
export const urlDoProduto = (escrita, produtoId) => `${endereco(escrita, 'produtos')}/${encodeURIComponent(produtoId)}`;

/** O retrato da gaveta (a lista de campos do contrato, §6.3 do desenho): produto, variações, listas, situação, edição. */
export async function abrirCadastro(escrita, produtoId) {
    return corpo(await axios.get(`${urlDoProduto(escrita, produtoId)}/cadastro`));
}

/** "Criar produto": só nome e SKU; nascem o produto, a variação e a oferta simples. */
export async function criarProduto(escrita, { nome, codigo }) {
    return corpo(await axios.post(endereco(escrita, 'produtos'), { nome, codigo }));
}

/**
 * O "Salvar" das linhas do produto (até 200 por pedido). A `versao` (F6, contra a aba velha) só vai quando há
 * uma. → a resposta do `gravarLinhas` do Portal: `{linhas, erros, avisos, criadas_nas_listas, totais}`.
 */
export async function gravarLinhas(escrita, produtoId, linhas, { versao = null } = {}) {
    const dados = versao ? { linhas, versao } : { linhas };

    return corpo(await axios.post(`${urlDoProduto(escrita, produtoId)}/linhas`, dados));
}

/**
 * O 2º passo do "Salvar" (F2): a ficha técnica INTEIRA do que está na tela (`montarAtributos`) — o servidor
 * substitui a gravada. O obrigatório vazio grava (vira falta na Lista); valor inválido volta 422 por campo
 * (`atributos.<id>`). `categoria` é a que a gaveta tinha aberta ao montar a ficha: diferente da gravada (o passo
 * das linhas não levou a troca), o servidor recusa (422 em `categoria`) em vez de gravar uma ficha por cima da
 * outra. → o retrato novo da gaveta.
 */
export async function gravarFicha(escrita, produtoId, atributos, { categoria = null, versao = null } = {}) {
    const dados = { atributos };
    if (categoria) dados.categoria = categoria;
    if (versao) dados.versao = versao;

    return corpo(await axios.put(`${urlDoProduto(escrita, produtoId)}/ficha-tecnica`, dados));
}

/**
 * "Preencher com IA" (F2b): as sugestões para os campos VAZIOS, a partir do que está na tela (título, descrição e
 * a ficha ainda não salva). NÃO grava nada. 422 sem a categoria salva (ou com a da tela diferente da salva).
 * → `{sugestoes: [{id, nome, valor, valor_id, unidade}], faltavam}`.
 */
export async function fichaPelaIa(escrita, produtoId, { categoria, titulo, descricao, atributos }) {
    return corpo(await axios.post(`${urlDoProduto(escrita, produtoId)}/ficha-tecnica/ia`, {
        categoria: categoria ?? null,
        titulo: titulo ?? '',
        descricao: descricao ?? '',
        atributos: atributos ?? [],
    }));
}

// ─── Fotos e frete (F3): ações IMEDIATAS ────────────────────────────────────
//
// Enviar e excluir foto e conferir o frete gravam na hora (não esperam o "Salvar") e respondem o RETRATO novo da
// gaveta — quem chama junta a estrutura nova ao que está na tela sem apagar o que ainda não foi salvo
// (`aplicarResposta`). O produto que andou responde 409 `{travado, motivo}`, como as outras escritas.

/** `{escrita.produtos}/{p}/variacoes/{v}` — a variação que guarda as fotos (a "dona" da cor). */
export const urlDaVariacao = (escrita, produtoId, variacaoId) => `${urlDoProduto(escrita, produtoId)}/variacoes/${encodeURIComponent(variacaoId)}`;

/**
 * Envia fotos para a variação (multipart, um `imagens[]` por arquivo, na ordem escolhida). O servidor é tudo ou
 * nada: 1 a 12 por variação, JPG/PNG/WebP (conferido pelo conteúdo), até 10 MB cada; o 422 traz a mensagem de
 * cada arquivo (`errors["imagens.N"]`). → o retrato novo da gaveta.
 */
export async function enviarFotos(escrita, produtoId, variacaoId, arquivos) {
    const dados = new FormData();
    Array.from(arquivos ?? []).forEach((arquivo) => dados.append('imagens[]', arquivo));

    return corpo(await axios.post(`${urlDaVariacao(escrita, produtoId, variacaoId)}/fotos`, dados));
}

/** Exclui uma foto da variação (as outras são renumeradas: a 1ª é a capa). → o retrato novo da gaveta. */
export async function excluirFoto(escrita, produtoId, variacaoId, imagemId) {
    return corpo(await axios.delete(`${urlDaVariacao(escrita, produtoId, variacaoId)}/fotos/${encodeURIComponent(imagemId)}`));
}

/**
 * R6: a ordem das fotos da variação — `ordem` com TODAS as fotos dela, cada uma uma vez; a 1ª é a capa. A lista que não
 * bate com a galeria de agora (outra aba mexeu) volta 422 em `ordem` e nada muda. → o retrato novo da gaveta.
 */
export async function ordenarFotos(escrita, produtoId, variacaoId, ordem) {
    return corpo(await axios.put(`${urlDaVariacao(escrita, produtoId, variacaoId)}/fotos/ordem`, { ordem: [...(ordem ?? [])] }));
}

/**
 * "Conferir frete pela conta": o servidor lê a conta da LOJA (só leitura) e guarda a modalidade de envio de
 * verdade; sem conta conectada, fica a estimativa pelas caixas. → o retrato novo, com a logística de cada variação.
 */
export async function conferirFrete(escrita, produtoId) {
    return corpo(await axios.post(`${urlDoProduto(escrita, produtoId)}/frete`, {}));
}

// ─── Cores, tamanhos e exclusões (F4): ações IMEDIATAS ──────────────────────
//
// "+ Cor" e "+ Tamanho" vão pela mesma rota do "Salvar" (`gravarLinhas`, com as linhas sem `id`). Remover uma
// variação e excluir o produto têm rota própria. O produto que andou responde 409 `{travado, motivo}`.

/**
 * Remove UMA variação (uma cor de um tamanho só, ou um tamanho de uma cor). A peça de combo ou kit e a última
 * variação do produto voltam 422 com o texto do servidor (a última sai por "Excluir produto"). A `versao` (F6) vai
 * no corpo do DELETE, só quando há uma. → o retrato novo.
 */
export async function excluirVariacao(escrita, produtoId, variacaoId, { versao = null } = {}) {
    const url = urlDaVariacao(escrita, produtoId, variacaoId);

    return corpo(await (versao ? axios.delete(url, { data: { versao } }) : axios.delete(url)));
}

/**
 * O 1º passo de "Excluir produto": o que sai junto (as ofertas montadas que usam o produto — combos, kits, combits),
 * sem tocar em nada. Com a `versao` (F6), a aba velha já sabe aqui, antes de pedir a confirmação.
 * → `{produtos, montadas: [{id, sku, nome, fase, em_uso}], totais, …}`.
 */
export async function previaDaExclusao(escrita, produtoId, { versao = null } = {}) {
    return corpo(await axios.post(`${urlDoProduto(escrita, produtoId)}/exclusao/previa`, versao ? { versao } : {}));
}

/**
 * O 2º passo: exclui o produto com as ofertas montadas que a pessoa VIU na prévia (`montadas`, os ids). Apareceu
 * outra no meio do caminho: 422 em `montadas`, e nada sai (a tela mostra a prévia de novo). A `versao` (F6) vai
 * junto, só quando há uma.
 */
export async function excluirProduto(escrita, produtoId, montadas = [], { versao = null } = {}) {
    const dados = { montadas: [...(montadas ?? [])] };
    if (versao) dados.versao = versao;

    return corpo(await axios.delete(urlDoProduto(escrita, produtoId), { data: dados }));
}

// ─── Categoria e ficha técnica (F2): só leitura do catálogo público ──────────
//
// Sob `escrita.categorias` (só para quem escreve). O catálogo fora do ar NUNCA é erro HTTP: a resposta vem 200 com
// `indisponivel`; o 422 é só do pedido malformado (texto curto demais, por exemplo) — a tela distingue os dois.

/** `{escrita.categorias}` — a base das rotas de categoria. */
export const urlDasCategorias = (escrita) => endereco(escrita, 'categorias');

/** O texto na query (`?q=…`), sem depender de onde o axios põe os `params`. */
const comConsulta = (url, campos) => {
    const pares = Object.entries(campos).filter(([, v]) => v !== null && v !== undefined && v !== '');

    return pares.length ? `${url}?${pares.map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('&')}` : url;
};

/** As candidatas pelo nome do produto. → `{categorias: [{id, nome, caminho, caminho_texto, folha, anunciavel}], indisponivel}`. */
export async function buscarCategorias(escrita, q) {
    return corpo(await axios.get(comConsulta(urlDasCategorias(escrita), { q })));
}

/** Um nível da árvore (sem `pai`, o 1º). → `{atual, caminho, filhas, indisponivel}` (o formato de `lib/arvoreDeCategorias`). */
export async function nivelDaArvore(escrita, pai = null) {
    return corpo(await axios.get(comConsulta(`${urlDasCategorias(escrita)}/arvore`, { pai })));
}

/** Os campos da ficha técnica da categoria, em grupos. → `{grupos, indisponivel}`. */
export async function camposDaCategoria(escrita, categoriaId) {
    return corpo(await axios.get(`${urlDasCategorias(escrita)}/${encodeURIComponent(categoriaId)}/campos`));
}

/** Os termos mais buscados e o limite do título da categoria. → `{limite, indisponivel, termos?, acima?, …}`. */
export async function termosDoTitulo(escrita, categoriaId, nome = '') {
    return corpo(await axios.get(comConsulta(`${urlDasCategorias(escrita)}/${encodeURIComponent(categoriaId)}/titulo`, { nome })));
}

// ─── Planilha por categoria (F7) ────────────────────────────────────────────
//
// Sob `escrita.planilha = {modelo, previa, gravar}` (§17.3 do desenho; só na porta da equipe com escrita). O erro do
// ARQUIVO volta 422 `errors.arquivo`; a categoria recusada no download, 422 `errors.categorias` (dentro do blob).

function enderecoDaPlanilha(escrita, campo) {
    const url = escrita?.planilha?.[campo];
    if (!url) throw new ErroDaTrilha('A planilha não está disponível nesta tela: recarregue a página.');

    return semBarraNoFim(url);
}

/**
 * "Baixar planilha": o modelo vazio das categorias escolhidas (`categorias[]`, de 1 a 10), uma aba por categoria.
 * → `{arquivo: Blob, nome}` (o nome vem do servidor, pelo `Content-Disposition`).
 */
export async function baixarPlanilha(escrita, categorias) {
    const resposta = await axios.get(enderecoDaPlanilha(escrita, 'modelo'), { params: parametrosDoModelo(categorias), responseType: 'blob' });

    return { arquivo: resposta?.data ?? null, nome: nomeDoArquivoBaixado(resposta?.headers?.['content-disposition'], ARQUIVO_DA_TRILHA) };
}

/** "Enviar planilha": a prévia por produto (multipart `arquivo`) — nada grava, nada enfileira. → o JSON do §17.4. */
export async function previaDaPlanilha(escrita, arquivo) {
    return corpo(await axios.post(enderecoDaPlanilha(escrita, 'previa'), formularioDaPrevia(arquivo)));
}

/**
 * "Gravar N produtos": o MESMO arquivo da prévia e a versão de cada produto que já existia nela
 * (`versoes[produto_id]`). → o JSON do §17.4, com `gravado: true`.
 */
export async function gravarPlanilha(escrita, arquivo, previa) {
    return corpo(await axios.post(enderecoDaPlanilha(escrita, 'gravar'), formularioDoGravar(arquivo, previa)));
}

// ─── Importar os anúncios da loja (F8) ──────────────────────────────────────
//
// Sob `escrita.mercado_livre = {anuncios, importar, estado, limpar}` (§18.3 do desenho; só na porta da equipe com
// escrita). Listar lê o acervo (não chama o marketplace); importar enfileira um Job por anúncio e responde o ESTADO;
// o estado e o limpar respondem a mesma forma (§18.4). O 422 do importar vem em `errors.mlbs`.
//
// A busca chega PRONTA (`parametrosDaLista` de `lib/trilhaImportarMl.js`, que a gaveta chama): este arquivo é
// importado pela página (o cliente também baixa) e não pode puxar a lib da gaveta — ela traz os rótulos do
// importador do Portal (`produtosDoMl.js`) para o pacote da página.

function enderecoDaImportacao(escrita, campo) {
    const url = escrita?.mercado_livre?.[campo];
    if (!url) throw new ErroDaTrilha('A importação de anúncios não está disponível nesta tela: recarregue a página.');

    return semBarraNoFim(url);
}

/**
 * A lista dos anúncios da loja. `busca` = `{q, ordem, situacao, pagina, mostrar_na_lista}` (`parametrosDaLista`), na
 * ordem do §18.3; o que vem null ou vazio não vai. → `{conectado, atualizado_em, total, pagina, paginas, limite, itens}`.
 */
export async function anunciosDaLoja(escrita, busca = {}) {
    return corpo(await axios.get(comConsulta(enderecoDaImportacao(escrita, 'anuncios'), busca ?? {})));
}

/** "Importar N": os MLBs marcados (1 a 20). → o estado da importação (§18.4). */
export async function importarAnuncios(escrita, mlbs) {
    return corpo(await axios.post(enderecoDaImportacao(escrita, 'importar'), { mlbs: [...(mlbs ?? [])] }));
}

/** O estado da importação (a gaveta pergunta a cada 2 s enquanto há algo andando). */
export async function estadoDaImportacao(escrita) {
    return corpo(await axios.get(enderecoDaImportacao(escrita, 'estado')));
}

/** "Limpar": tira da gaveta a importação que já terminou. → o estado (vazio). */
export async function limparImportacao(escrita) {
    return corpo(await axios.post(enderecoDaImportacao(escrita, 'limpar'), {}));
}

// ─── A prévia do anúncio (4ª entrega, 4a; preço 4c; publicar 4d; ajuste 4b) ─

/**
 * A prévia do anúncio de uma oferta (§19.5 do desenho, só leitura). `url` chega PRONTA da gaveta (o
 * `escrita.revisao.previa` com o id do item no lugar do marcador): esta lib não monta nada da prévia — a página do
 * cliente a importa, e a lib da gaveta (`trilhaPrevia.js`) fica no pedaço que só a equipe baixa. O 409 (item fora da
 * revisão) chega como erro do axios; quem trata é a gaveta.
 */
export async function carregarPrevia(url) {
    if (!url) throw new ErroDaTrilha('A prévia não está disponível nesta tela: recarregue a página.');

    return corpo(await axios.get(url));
}

/**
 * O preço ajustado na prévia (4c, §19.11 do desenho). `url` chega PRONTA da gaveta (o `escrita.revisao.preco` com o id
 * do item no lugar do marcador) e `dados` também (`{revisao, precos: {classico?, premium?}, cor}`, montado pela lib da
 * caixa — esta não a importa, pelo mesmo motivo da prévia). → a prévia NOVA (a forma do §19.5, com o `preco` ampliado
 * e os `avisos`). O 409 (revisão velha; ou o item na fila, publicado ou fora da revisão) e o 422 chegam como erro do
 * axios; quem trata é a gaveta.
 */
export async function salvarPreco(url, dados) {
    if (!url) throw new ErroDaTrilha('O preço não pode ser salvo nesta tela: recarregue a página.');

    return corpo(await axios.put(url, dados));
}

/**
 * "Aprovar e publicar" (4d, §19.13 do desenho). `url` chega PRONTA da gaveta (o `escrita.revisao.publicar` com o id do
 * item no lugar do marcador) e `dados` também (`{revisao, ciente}`, montado pela lib do rodapé — esta não a importa,
 * pelo mesmo motivo da prévia). → 202 `{publicacao: {situacao: 'conferindo', mensagem, avisos, mlbs}}`. O 403 (loja
 * não liberada), o 409 (revisão velha, ou o item fora da revisão, na fila, publicado, não pronto) e o 422 chegam como
 * erro do axios; quem trata é a gaveta.
 */
export async function publicarAnuncio(url, dados) {
    if (!url) throw new ErroDaTrilha('A publicação não está disponível nesta tela: recarregue a página.');

    return corpo(await axios.post(url, dados));
}

/**
 * Como está a publicação pedida (4d): a gaveta pergunta a cada 3 s enquanto ela anda. `url` chega PRONTA (o
 * `escrita.revisao.publicacao` com o id). → `{publicacao: {...}}`, ou `{publicacao: null}` se nunca foi pedida.
 */
export async function estadoDaPublicacao(url) {
    if (!url) throw new ErroDaTrilha('O acompanhamento da publicação não está disponível nesta tela: recarregue a página.');

    return corpo(await axios.get(url));
}

/**
 * "Pedir ajuste ao robô" (4b, §19.14 do desenho). `url` chega PRONTA da gaveta (o `escrita.revisao.ajuste` com o id do
 * item no lugar do marcador) e `dados` também (`{revisao, refazer: ['titulo'|'descricao']}`, montado pela lib do
 * ajuste — esta não a importa, pelo mesmo motivo da prévia). → 202 `{ajuste: {situacao: 'fila', partes, restantes_hoje,
 * mensagem}}`. O 409 (revisão velha; ou o item fora da revisão, na fila, publicado, não pronto, com ajuste andando) e o
 * 422 (`errors.refazer`: o limite do dia) chegam como erro do axios; quem trata é a gaveta.
 */
export async function pedirAjuste(url, dados) {
    if (!url) throw new ErroDaTrilha('O ajuste pelo robô não está disponível nesta tela: recarregue a página.');

    return corpo(await axios.post(url, dados));
}

/**
 * R4 (retorno do 1º teste, 04/11/2026): o [Tentar de novo] da "Preparação automática". `url` chega PRONTA da caixa (o
 * `escrita.preparo` com o id do item no lugar do marcador — como a prévia, esta lib não monta o endereço). Sem corpo.
 * → 202 `{preparo: {situacao: 'fila', mensagem, etapas}}`. O 409 (nada parado no texto, ou já pedido) chega como erro
 * do axios; quem trata é a caixa.
 */
export async function tentarPreparoDeNovo(url) {
    if (!url) throw new ErroDaTrilha('A preparação não pode ser pedida de novo nesta tela: recarregue a página.');

    return corpo(await axios.post(url));
}

/**
 * Como está o ajuste pedido (4b): a gaveta pergunta a cada 3 s enquanto ele anda. `url` chega PRONTA (a MESMA do
 * pedido). → `{ajuste: {...}}`, ou `{ajuste: null}` se nunca foi pedido.
 */
export async function estadoDoAjuste(url) {
    if (!url) throw new ErroDaTrilha('O acompanhamento do ajuste não está disponível nesta tela: recarregue a página.');

    return corpo(await axios.get(url));
}

// ─── A aprovação do cliente (4ª entrega, 4e) ────────────────────────────────
//
// §19.16 do desenho. As URLs chegam PRONTAS (com o id do item no lugar do marcador, trocado pela gaveta — esta lib é
// importada pela página e não monta nada da aprovação) e os corpos também (`lib/trilhaAprovacao.js`). O 409 e o 422
// chegam como erro do axios; quem trata é a gaveta.

/**
 * [Mandar ao cliente] (a equipe, `escrita.revisao.cliente`): `dados` = `{revisao}`. → 200 a prévia NOVA (a forma do
 * §19.5, com `aprovacao` em `com_cliente`). 409: a chave desligada, a revisão velha (`revisao_atual`), fora da
 * revisão, na fila/publicando/publicado, o robô ajustando, já com o cliente; 422: o Premium igual ao Clássico.
 */
export async function mandarAoCliente(url, dados) {
    if (!url) throw new ErroDaTrilha('Mandar ao cliente não está disponível nesta tela: recarregue a página.');

    return corpo(await axios.post(url, dados));
}

/**
 * [Aprovar anúncio] (o cliente, `cliente_escrita.aprovacao.aprovar`): `dados` = `{revisao}`. → 200 `{aprovacao,
 * publicacao}`. 409: o anúncio mudou depois que a equipe mandou (D55), ou não está mais com ele.
 */
export async function aprovarPeloCliente(url, dados) {
    if (!url) throw new ErroDaTrilha('A aprovação não está disponível nesta tela: recarregue a página.');

    return corpo(await axios.post(url, dados));
}

/**
 * [Pedir ajuste] (o cliente, `cliente_escrita.aprovacao.ajuste`): `dados` = `{revisao, texto}` — o texto vai para a
 * EQUIPE, nunca a um robô (D52). → 200 `{aprovacao}`. 422 em `errors.texto`; 409 como o de aprovar.
 */
export async function pedirAjustePeloCliente(url, dados) {
    if (!url) throw new ErroDaTrilha('O pedido de ajuste não está disponível nesta tela: recarregue a página.');

    return corpo(await axios.post(url, dados));
}

// ─── As fotos que o cliente envia (4ª entrega, 4f) ──────────────────────────
//
// §19.15 do desenho: a 1ª escrita da porta do CLIENTE. As URLs chegam PRONTAS da caixa (a base
// `cliente_escrita.fotos` com o id no lugar do marcador, montada por `lib/trilhaFotosCliente.js` — esta lib não a
// importa: é importada pela página). As três respondem o QUADRO de fotos do produto; o 409 (o produto andou ou saiu
// da Lista) e o 422 (`errors.imagens`, o 413 também) chegam como erro do axios — quem trata é a caixa.

/** O quadro de fotos do produto (`GET`). → `{produto: {id, nome, editavel, motivo}, cores, limites}`. */
export async function carregarFotosDoCliente(url) {
    if (!url) throw new ErroDaTrilha('As fotos não estão disponíveis nesta tela: recarregue a página.');

    return corpo(await axios.get(url));
}

/**
 * Envia as fotos de UMA cor (multipart, um `imagens[]` por arquivo, na ordem escolhida). `aoProgredir` recebe o evento
 * de progresso do envio (o andamento da caixa). → o quadro novo, com `avisos`.
 */
export async function enviarFotosDoCliente(url, arquivos, { aoProgredir = null } = {}) {
    if (!url) throw new ErroDaTrilha('As fotos não podem ser enviadas nesta tela: recarregue a página.');
    const dados = new FormData();
    Array.from(arquivos ?? []).forEach((arquivo) => dados.append('imagens[]', arquivo));

    return corpo(await axios.post(url, dados, aoProgredir ? { onUploadProgress: (evento) => aoProgredir(evento) } : {}));
}

/** Exclui uma foto (`DELETE`). → o quadro novo. */
export async function excluirFotoDoCliente(url) {
    if (!url) throw new ErroDaTrilha('A foto não pode ser excluída nesta tela: recarregue a página.');

    return corpo(await axios.delete(url));
}

// ─── Erros ──────────────────────────────────────────────────────────────────

const SEM_CONEXAO = 'Não consegui falar com o servidor. Confira a conexão e tente de novo.';
const PADRAO = 'Não deu certo. Tente de novo.';

// O idioma do Laravel nesta instalação é o inglês: as mensagens padrão do framework (e as das regras de validação
// sem texto próprio) chegam em inglês. Essas não vão para a tela; as do serviço da trilha já vêm em português.
const DO_FRAMEWORK = /^(?:not found|forbidden|server error|unauthenticated|page expired|too many (?:attempts|requests)|this action is unauthorized|csrf token mismatch|the given data was invalid|no query results)\b|^the .+ (?:field|must|may|is)\b/i;

const texto = (m) => (typeof m === 'string' && m.trim() && !DO_FRAMEWORK.test(m.trim()) ? m.trim() : '');

/**
 * O texto do erro para a pessoa. O 422 traz a primeira mensagem de `errors`
 * (a regra do servidor, em português); os outros, o `message` — ou, quando ele
 * é o padrão do framework, um texto nosso pelo código.
 */
export function mensagemDoErro(erro) {
    if (erro instanceof ErroDaTrilha) return erro.message;
    const resposta = erro?.response;
    if (!resposta) return erro?.isAxiosError || erro?.request ? SEM_CONEXAO : PADRAO;

    const { status, data } = resposta;
    if (status === 422) {
        const primeira = Object.values(data?.errors ?? {}).flat().map(texto).find(Boolean);

        return primeira || texto(data?.message) || 'Confira o que foi preenchido e tente de novo.';
    }
    if (status === 419) return 'A sessão expirou. Recarregue a página e tente de novo.';
    if (status === 401) return 'Sua sessão terminou. Entre de novo para continuar.';
    if (status === 403) return texto(data?.message) || 'Você não tem permissão para mudar esta trilha.';
    if (status === 404) return 'Não achei o que você pediu: talvez tenha sido apagado. Recarregue a página.';
    // O produto que já andou (trava) ou que mudou depois de aberto (versão): o motivo vem do servidor, em português.
    if (status === 409) return texto(data?.motivo) || texto(data?.message) || 'Este produto não pode mais ser mudado daqui. Recarregue a página.';
    if (status === 429) return 'Muitos pedidos seguidos. Espere um minuto e tente de novo.';
    if (status >= 500) return 'O servidor falhou. Tente de novo em instantes.';

    return texto(data?.message) || PADRAO;
}

/**
 * Os erros do 422 por campo (`{codigo: 'Este SKU já existe…'}`), com a 1ª mensagem de cada um — para marcar o
 * campo do formulário em vez de só o rodapé. A mensagem do framework (em inglês) não vai; os outros códigos
 * não têm campo.
 *
 * @returns {Record<string, string>}
 */
export function errosDosCampos(erro) {
    const resposta = erro?.response;
    if (resposta?.status !== 422) return {};
    const saida = {};
    for (const [campo, mensagens] of Object.entries(resposta.data?.errors ?? {})) {
        const primeira = [mensagens].flat().map(texto).find(Boolean);
        if (primeira) saida[campo] = primeira;
    }

    return saida;
}

// ─── Recarga ────────────────────────────────────────────────────────────────

/** Os dados da trilha que mudam com uma escrita (o `gerado_em` vai junto, para o "Atualizado em" não mentir). */
export const CAMPOS_DA_RECARGA = ['produtos', 'ofertas', 'ondas', 'gerado_em'];

// A recarga em voo (só a última vale) e quem está esperando por ela.
let geracaoDaRecarga = 0;
let cancelarRecargaEmVoo = null;
let esperandoARecarga = [];

function terminarARecarga() {
    const todos = esperandoARecarga;
    esperandoARecarga = [];
    todos.forEach((resolver) => resolver());
}

/**
 * Recarrega os dados da trilha sem sair do lugar (o painel aberto e a etapa
 * escolhida ficam: o Inertia preserva o estado na recarga). Termina quando a
 * resposta chegou — quem precisa da onda nova nas props espera por ela.
 *
 * `replace: true`: a recarga é assíncrona e pede a URL de agora. Se a pessoa
 * trocar de painel antes da resposta (a tela grava a busca com `replaceState`),
 * a URL da resposta fica diferente da barra e o Inertia EMPILHARIA uma entrada
 * no histórico com a URL velha. Com `replace` ele só a substitui — e quem chama
 * regrava a URL de agora depois (`Trilha/Empresa`).
 *
 * Só a última vale: duas escritas seguidas pedem duas recargas, e o Inertia
 * aplica as respostas na ordem em que CHEGAM — a mais velha, chegando depois,
 * apagaria a mais nova. Por isso a recarga anterior ainda no ar é cancelada (o
 * Inertia entrega o `cancel` no `onCancelToken`, na hora do pedido), e quem
 * esperava por ela passa a esperar pela nova: todas as promessas terminam juntas,
 * quando a última resposta chega.
 *
 * @returns {Promise<void>}
 */
export function recarregar() {
    return new Promise((resolve) => {
        const minha = ++geracaoDaRecarga;
        // Avança a geração ANTES de cancelar: o `onFinish` que o cancelamento dispara já se sabe vencido.
        const anterior = cancelarRecargaEmVoo;
        cancelarRecargaEmVoo = null;
        anterior?.();
        esperandoARecarga.push(resolve);

        let saiu = false;
        router.reload({
            only: CAMPOS_DA_RECARGA,
            preserveScroll: true,
            replace: true,
            onCancelToken: ({ cancel }) => {
                saiu = true;
                // Uma recarga mais nova já saiu (não deveria: o Inertia chama isto na hora): esta nem vale.
                if (minha === geracaoDaRecarga) cancelarRecargaEmVoo = cancel;
                else cancel();
            },
            onFinish: () => {
                if (minha !== geracaoDaRecarga) return;
                cancelarRecargaEmVoo = null;
                terminarARecarga();
            },
        });

        // Nenhum pedido saiu (fora do navegador, ou uma visita barrada no `before`): ninguém fica esperando à toa.
        if (!saiu && minha === geracaoDaRecarga) terminarARecarga();
    });
}
