import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import { PREVIA_DO_ANUNCIO, PREVIA_DO_CLIENTE, PREVIA_NAO_PRONTA, PROPS_CLIENTE_COM_APROVACAO, PROPS_TRABALHO } from './_trilha-exemplo.js';
import { lerSemComentarios } from './_fonte.js';
import {
    DESTAQUES_A_VISTA,
    NOME_DA_VARIACAO,
    anuncioDaPagina,
    capaDaVariacao,
    caminhoDaPagina,
    condicaoDaPagina,
    destaquesDaPagina,
    escolhaDaPagina,
    estoqueDaPagina,
    fotosDaPagina,
    freteDaPagina,
    gruposDaPagina,
    miniaturaDaFoto,
    nomeDaEscolha,
    nomeDaVariacao,
    partesDoPreco,
    precoDaPagina,
    reais,
    textoDasParcelasDaPagina,
    textoDoEstoque,
    tiposDaPagina,
    tituloDaPagina,
    variacoesDaPagina,
    vendedorDaPagina,
} from '../../resources/js/lib/trilhaPaginaDoAnuncio.js';

// ═══════════════════════════════════════════════════════════════════════════
// Trilha de produtos — a página do anúncio "como o comprador vê" (05/11/2026).
//
// POR QUE EXISTE: o usuário achou a prévia da gaveta ruim e pediu a página do item
// IGUALZINHA à do canal de venda, como a do Publicador, em tela cheia — e o cliente
// também a vê. Os riscos: a página mostrar outra coisa que não o anúncio que vai ao
// ar (o preço da promoção que o canal costuma recusar, o título sem a variação do
// fim, um "sem estoque" que ninguém afirmou); a página do comprador levar o selo do
// agente ou o nome do canal; a tela cheia fechar a gaveta junto no Esc, ficar presa
// dentro dela (a animação com `transform`) ou deixar o Tab escapar; e o pacote do
// cliente passar a levar a gaveta da equipe. Aqui: as contas puras, a página e a
// tela cheia DESENHADAS (React no servidor) nas duas portas, o botão nas duas
// gavetas e as regras da fonte (portal, Esc, Tab, cores).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

global.route = (nome) => `/${nome}`;
global.window = {
    location: { href: 'http://localhost/trilha/459', pathname: '/trilha/459', search: '', hash: '' },
    history: { state: null, replaceState: () => {}, go: () => {}, back: () => {} },
    addEventListener: () => {},
    removeEventListener: () => {},
};

/** Monta os arquivos com o esbuild (o Inertia é de mentira). Tudo montado ANTES dos testes. */
async function montarTodos(arquivos) {
    const stub = path.join(__dirname, `.trilha-pagina-inertia-stub-${process.pid}.mjs`);
    fs.writeFileSync(stub, `
import React from 'react';
export function Head() { return null; }
export function Link({ href, children, className, replace, preserveState, preserveScroll, only, method, as, data, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, delete: () => {}, reload: (o) => o?.onFinish?.(), visit: () => {}, on: () => () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');
    const modulos = {};
    try {
        for (const [nome, relativo] of Object.entries(arquivos)) {
            const resultado = await esbuild.build({
                entryPoints: [path.resolve(RAIZ, relativo)],
                bundle: true,
                format: 'esm',
                platform: 'node',
                jsx: 'automatic',
                write: false,
                logLevel: 'silent',
                loader: { '.css': 'empty' },
                alias: { '@inertiajs/react': stub, '@': path.resolve(RAIZ, 'resources/js') },
                external: ['react', 'react-dom', 'react-dom/server', 'react/jsx-runtime', 'lucide-react', 'clsx', 'tailwind-merge', 'axios'],
            });
            const saida = path.join(__dirname, `.trilha-pagina-${nome}-${process.pid}-${Date.now()}.mjs`);
            fs.writeFileSync(saida, resultado.outputFiles[0].text, 'utf8');
            try {
                modulos[nome] = await import(pathToFileURL(saida).href);
            } finally {
                fs.rmSync(saida, { force: true });
            }
        }
    } finally {
        fs.rmSync(stub, { force: true });
    }

    return modulos;
}

const M = await montarTodos({
    pagina: 'resources/js/Components/Trilha/PaginaDoAnuncio.jsx',
    previa: 'resources/js/Components/Trilha/Revisao/PreviaDoAnuncio.jsx',
    cliente: 'resources/js/Components/Trilha/Cliente/AprovarAnuncio.jsx',
});

const { createElement: h } = await import('react');
const { renderToStaticMarkup } = await import('react-dom/server');

const texto = (s) => s
    .replace(/<[^>]+>/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&quot;/g, '"')
    .replace(/&#x27;/g, "'")
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/\s+/g, ' ');

const pagina = (previa, escolha = {}) => renderToStaticMarkup(h(M.pagina.PaginaDoAnuncio, { previa, escolha }));
const OFERTA_S2 = PROPS_TRABALHO.ofertas.find((o) => o.id === 's-2');
const OFERTA_S2_DO_CLIENTE = PROPS_CLIENTE_COM_APROVACAO.ofertas.find((o) => o.id === 's-2');

/** O que a página do COMPRADOR nunca diz: o selo do agente, o nome do canal, o MLB, o Portal e o Publicador. */
const PROIBIDAS_NA_PAGINA = /gerada pelo agente|\bagente\b|Mercado\s+Livre|\bMLB|Portal|Publicador|rob[ôo]/i;

// ═══ As contas puras ═════════════════════════════════════════════════════════

test('o preço como o comprador lê: milhar com ponto, os centavos à parte; nulo, negativo e texto não são preço', () => {
    assert.deepEqual(partesDoPreco(1234.5), { inteiro: '1.234', centavos: '50' });
    assert.deepEqual(partesDoPreco('899.9'), { inteiro: '899', centavos: '90' });
    assert.deepEqual(partesDoPreco(1000000), { inteiro: '1.000.000', centavos: '00' });
    for (const nada of [null, undefined, '', -1, 'abc', true]) assert.equal(partesDoPreco(nada), null, String(nada));
    assert.equal(reais(98.99), 'R$ 98,99');
    assert.equal(reais(null), '');
});

test('a escolha: tipo e variação que existem; o que não existe vira o 1º; trocar o tipo mantém a cor', () => {
    assert.deepEqual(tiposDaPagina(PREVIA_DO_ANUNCIO).map((t) => t.chave), ['classico', 'premium']);
    assert.deepEqual(variacoesDaPagina(PREVIA_DO_ANUNCIO, 'premium').map((v) => v.chave), ['v1', 'v2']);
    assert.deepEqual(escolhaDaPagina(PREVIA_DO_ANUNCIO), { tipo: 'classico', variante: 'v1' });
    assert.deepEqual(escolhaDaPagina(PREVIA_DO_ANUNCIO, { tipo: 'premium', variante: 'v2' }), { tipo: 'premium', variante: 'v2' });
    assert.deepEqual(escolhaDaPagina(PREVIA_DO_ANUNCIO, { tipo: 'nada', variante: 'zz' }), { tipo: 'classico', variante: 'v1' });
    // Um tipo sem anúncio não aparece (o Premium desligado).
    const soClassico = { ...PREVIA_DO_ANUNCIO, anuncios: PREVIA_DO_ANUNCIO.anuncios.filter((a) => a.tipo === 'classico') };
    assert.deepEqual(tiposDaPagina(soClassico).map((t) => t.chave), ['classico']);
    assert.deepEqual(escolhaDaPagina(soClassico, { tipo: 'premium', variante: 'v2' }), { tipo: 'classico', variante: 'v2' });
    assert.equal(anuncioDaPagina(PREVIA_DO_ANUNCIO, { tipo: 'premium', variante: 'v2' }).titulo_completo, 'Cadeira Jatobá Palhinha Madeira Maciça Para Sala De Jantar Mel');
    // Sem anúncio (a prévia não pronta): nada, sem quebrar.
    assert.deepEqual(escolhaDaPagina(PREVIA_NAO_PRONTA), { tipo: null, variante: null });
    assert.equal(anuncioDaPagina(PREVIA_NAO_PRONTA), null);
    assert.equal(anuncioDaPagina(null), null);
});

test('o título é o completo (com a variação no fim); sem ele, o do anúncio; a condição padrão é "Novo"', () => {
    assert.equal(tituloDaPagina({ titulo: 'Cadeira Jatobá', titulo_completo: 'Cadeira Jatobá Mel' }), 'Cadeira Jatobá Mel');
    assert.equal(tituloDaPagina({ titulo: 'Cadeira Jatobá', titulo_completo: null }), 'Cadeira Jatobá');
    assert.equal(tituloDaPagina({ titulo: '  ', titulo_completo: '' }), null);
    assert.equal(condicaoDaPagina({ condicao: 'Usado' }), 'Usado');
    assert.equal(condicaoDaPagina({}), 'Novo');
});

test('o preço da página é o CHEIO (a promoção da Central é só tentativa); as parcelas do Premium acompanham', () => {
    const classicoMel = anuncioDaPagina(PREVIA_DO_ANUNCIO, { tipo: 'classico', variante: 'v2' });
    assert.ok(classicoMel.promocao, 'controle: este anúncio tem a promoção que o sistema tenta depois');
    assert.deepEqual(precoDaPagina(classicoMel), { atual: 899.9, parcelas: null });
    const premium = anuncioDaPagina(PREVIA_DO_ANUNCIO, { tipo: 'premium', variante: 'v1' });
    assert.deepEqual(precoDaPagina(premium), { atual: 989.9, parcelas: { vezes: 10, valor: 98.99 } });
    assert.equal(textoDasParcelasDaPagina(precoDaPagina(premium).parcelas), 'em 10x R$ 98,99 sem juros');
    assert.equal(textoDasParcelasDaPagina(null), null);
    // 1x não é parcela; parcela sem valor também não.
    assert.equal(precoDaPagina({ preco: 50, parcelas: { sem_promocao: { vezes: 1, valor: 50 } } }).parcelas, null);
    assert.equal(precoDaPagina({ preco: 50, parcelas: { sem_promocao: { vezes: 6, valor: null } } }).parcelas, null);
    assert.deepEqual(precoDaPagina({ preco: null }), { atual: null, parcelas: null });
});

test('a ficha: grupos sem item vazio; os destaques são os primeiros do 1º grupo', () => {
    const grupos = gruposDaPagina({
        caracteristicas: [
            { grupo: 'Principais', itens: [{ nome: 'Marca', valor: 'Jatobá' }, { nome: 'Modelo', valor: '' }, { nome: '', valor: 'x' }, { nome: 'Altura', valor: 92 }] },
            { grupo: 'Vazio', itens: [{ nome: 'Cor', valor: null }] },
            { grupo: '', itens: [{ nome: 'Material', valor: 'Jatobá maciço' }] },
        ],
    });
    assert.deepEqual(grupos, [
        { grupo: 'Principais', itens: [{ nome: 'Marca', valor: 'Jatobá' }, { nome: 'Altura', valor: '92' }] },
        { grupo: 'Características', itens: [{ nome: 'Material', valor: 'Jatobá maciço' }] },
    ]);
    const muitos = [{ grupo: 'G', itens: Array.from({ length: 9 }, (_, i) => ({ nome: `N${i}`, valor: `V${i}` })) }];
    assert.equal(destaquesDaPagina(muitos).length, DESTAQUES_A_VISTA);
    assert.deepEqual(destaquesDaPagina([]), []);
});

test('o estoque: o que o plano diz (sem dado, a página não afirma "sem estoque")', () => {
    assert.equal(estoqueDaPagina({ estoque: null }), null);
    assert.equal(estoqueDaPagina({}), null);
    assert.equal(estoqueDaPagina({ estoque: -3 }), null);
    assert.deepEqual(estoqueDaPagina({ estoque: 0 }), { disponivel: false, quantidade: 0 });
    assert.deepEqual(estoqueDaPagina({ estoque: 12 }), { disponivel: true, quantidade: 12 });
    assert.equal(textoDoEstoque({ disponivel: true, quantidade: 12 }), '(12 disponíveis)');
    assert.equal(textoDoEstoque({ disponivel: true, quantidade: 1 }), '(1 disponível)');
    assert.equal(textoDoEstoque({ disponivel: false, quantidade: 0 }), null);
});

test('o frete da caixa de compra, pelo modo do plano', () => {
    assert.deepEqual(freteDaPagina({ modo: 'me2', frete_gratis: true }), { chave: 'gratis', titulo: 'Frete grátis', detalhe: 'Saiba os prazos de entrega e as formas de envio.', gratis: true });
    assert.equal(freteDaPagina({ modo: 'me2', frete_gratis: false }).chave, 'comprador');
    assert.equal(freteDaPagina(null).chave, 'comprador', 'sem envio: o padrão do canal');
    assert.equal(freteDaPagina({ modo: 'me1' }).detalhe, 'Pela transportadora do vendedor, com o custo que ele define.');
    assert.equal(freteDaPagina({ modo: 'custom' }).titulo, 'Envio pelo vendedor');
    assert.equal(freteDaPagina({ modo: 'not_specified' }).titulo, 'Entrega a combinar com o vendedor');
});

test('o nome da variação, a categoria, o vendedor e o nome da escolha', () => {
    assert.equal(nomeDaVariacao({ eixos: ['Cor', 'Tamanho'] }), 'Cor / Tamanho');
    assert.equal(nomeDaVariacao({ eixos: [] }), NOME_DA_VARIACAO);
    assert.equal(nomeDaVariacao({}), 'Cor');
    assert.deepEqual(caminhoDaPagina(PREVIA_DO_ANUNCIO), ['Casa, Móveis e Decoração', 'Sala de Jantar', 'Cadeiras']);
    assert.deepEqual(caminhoDaPagina({ categoria: null }), []);
    assert.equal(vendedorDaPagina(PREVIA_DO_ANUNCIO), 'CASAJATOBA');
    assert.equal(vendedorDaPagina({ vendedor: '  ' }), null);
    assert.equal(nomeDaEscolha(PREVIA_DO_ANUNCIO, { tipo: 'premium', variante: 'v2' }), 'Anúncio Premium · Mel');
    const umaCor = { ...PREVIA_DO_ANUNCIO, anuncios: PREVIA_DO_ANUNCIO.anuncios.filter((a) => a.variante === 'v1') };
    assert.equal(nomeDaEscolha(umaCor, { tipo: 'classico' }), 'Anúncio Clássico', 'uma cor só: o nome não a repete');
});

test('as fotos: só as com endereço; a miniatura usa a versão pequena quando vem (a equipe), senão a foto', () => {
    assert.deepEqual(fotosDaPagina({ fotos: [{ id: '1', url: '/a' }, { id: '2', url: '' }, null, { id: '3' }] }), [{ id: '1', url: '/a' }]);
    assert.equal(miniaturaDaFoto({ url: '/a', miniatura: '/a?miniatura=1' }), '/a?miniatura=1');
    assert.equal(miniaturaDaFoto({ url: '/a', miniatura: null }), '/a');
    assert.equal(capaDaVariacao(PREVIA_DO_ANUNCIO, 'premium', 'v2').id, PREVIA_DO_ANUNCIO.anuncios[3].fotos[0].id);
});

test('lib/trilhaPaginaDoAnuncio.js é pura: nenhum import, nenhuma classe, nenhum nome do canal, do Portal, do Publicador ou robô', () => {
    const fonte = lerSemComentarios('resources/js/lib/trilhaPaginaDoAnuncio.js');
    assert.equal(fonte.match(/^\s*import\b|\bimport\(/m), null, 'o pedaço do cliente a leva');
    assert.equal(fonte.match(/className|\b(?:bg|text|border)-/), null);
    assert.equal(fonte.match(/Mercado\s+Livre|Portal|\/portal|Publicador|rob[ôo]/i), null);
});

// ═══ A página desenhada ══════════════════════════════════════════════════════

test('a página do Premium Mel: o título completo, o preço com parcelas, a variação, a ficha, a descrição e a caixa de compra', () => {
    const html = pagina(PREVIA_DO_ANUNCIO, { tipo: 'premium', variante: 'v2' });
    const t = texto(html);
    assert.match(html, /data-pagina-do-anuncio="premium:v2"/);
    assert.match(t, /Casa, Móveis e Decoração > Sala de Jantar > Cadeiras/);
    assert.match(t, / Novo Cadeira Jatobá Palhinha Madeira Maciça Para Sala De Jantar Mel /);
    // "R$ 989" grande e os centavos à parte.
    assert.match(html, /<span>R\$ 989<\/span><span class="[^"]*">90<\/span>/);
    assert.match(t, /em 10x R\$ 98,99 sem juros/);
    assert.doesNotMatch(t, /Ver os meios de pagamento/);
    assert.match(t, /Cor: Mel/);
    assert.match(html, /data-pagina-variacao="v2" aria-pressed="true"|aria-pressed="true"[^>]*data-pagina-variacao="v2"/);
    assert.equal((html.match(/data-pagina-variacao=/g) ?? []).length, 2);
    assert.match(t, /O que você precisa saber sobre este produto/);
    assert.match(t, /Características do produto/);
    assert.match(t, /Descrição Olá! Que bom ter você na Casa Jatobá\./);
    assert.match(t, /Frete grátis Saiba os prazos de entrega/);
    // Estoque 0 no plano: "Sem estoque", sem a linha da quantidade.
    assert.match(html, /data-pagina-estoque="nao"/);
    assert.doesNotMatch(t, /Quantidade:/);
    assert.match(t, /Vendido por CASAJATOBA/);
    assert.match(t, /Garantia do vendedor: 3 meses/);
    assert.match(t, /Comprar agora/);
    // A capa do Premium Mel é a 1ª foto dele, e as miniaturas são as 4 dele, na ordem.
    const fotos = PREVIA_DO_ANUNCIO.anuncios[3].fotos;
    assert.match(html, new RegExp(`data-pagina-foto-grande="${fotos[0].id}"`));
    assert.equal((html.match(/data-pagina-miniatura=/g) ?? []).length, fotos.length);
    assert.match(t, / 1\/4 /);
    assert.doesNotMatch(t, PROIBIDAS_NA_PAGINA);
});

test('a página do Clássico Natural: o preço CHEIO (sem a promoção), "Ver os meios de pagamento" e o estoque disponível', () => {
    const t = texto(pagina(PREVIA_DO_ANUNCIO, { tipo: 'classico', variante: 'v2' }));
    assert.match(t, /R\$ 899 90/);
    assert.doesNotMatch(t, /809|% OFF/, 'a promoção que o canal costuma recusar não aparece como certa');
    const natural = texto(pagina(PREVIA_DO_ANUNCIO, { tipo: 'classico', variante: 'v1' }));
    assert.match(natural, /Cadeira Jatobá Assento Palhinha Madeira Maciça Sala De Jantar Natural/);
    assert.match(natural, /Ver os meios de pagamento/);
    assert.match(natural, /Estoque disponível Quantidade: 1 unidade \(12 disponíveis\)/);
    assert.doesNotMatch(natural, PROIBIDAS_NA_PAGINA);
});

test('sem os campos novos (servidor de antes), sem foto e sem ficha: a página não quebra nem inventa', () => {
    const velho = {
        ...PREVIA_DO_ANUNCIO,
        eixos: undefined,
        vendedor: undefined,
        descricao: null,
        anuncios: [{ ...PREVIA_DO_ANUNCIO.anuncios[0], titulo_completo: undefined, condicao: undefined, estoque: undefined, garantia: undefined, fotos: [], caracteristicas: [] }],
    };
    const html = pagina(velho);
    const t = texto(html);
    assert.match(t, / Novo Cadeira Jatobá Assento Palhinha Madeira Maciça Sala De Jantar /);
    assert.match(html, /data-pagina-sem-foto=""/);
    assert.match(t, /Nenhuma característica preenchida\./);
    assert.match(t, /Sem descrição\./);
    assert.doesNotMatch(t, /Vendido por|Estoque disponível|Sem estoque|Garantia/);
    assert.doesNotMatch(html, /data-pagina-variacoes/, 'uma variação só: sem seletor');
});

test('a porta do cliente: a mesma página, com as fotos pela porta dele', () => {
    const html = pagina(PREVIA_DO_CLIENTE, { tipo: 'premium' });
    const t = texto(html);
    assert.match(t, /Cadeira Jatobá Palhinha Madeira Maciça Para Sala De Jantar Natural/);
    assert.match(t, /Vendido por CASAJATOBA/);
    const fotos = [...html.matchAll(/<img src="([^"]+)"/g)].map((m) => m[1]);
    assert.ok(fotos.length > 0);
    for (const url of fotos) assert.match(url, /^\/portal\/459\/trilha\//, url);
    assert.doesNotMatch(t, PROIBIDAS_NA_PAGINA);
});

// ═══ A tela cheia e o botão ══════════════════════════════════════════════════

test('a tela cheia: um diálogo modal acima da gaveta, que abre na escolha da gaveta, com Clássico/Premium e [Fechar]', () => {
    const html = renderToStaticMarkup(h(M.pagina.TelaCheiaDoAnuncio, { previa: PREVIA_DO_ANUNCIO, inicial: { tipo: 'premium', variante: 'v2' } }));
    const t = texto(html);
    assert.match(html, /role="dialog"/);
    assert.match(html, /aria-modal="true"/);
    assert.match(html, /class="fixed inset-0 z-\[60\]/, 'acima da gaveta (z-[41])');
    assert.match(t, /Como o comprador vê Anúncio Premium · Mel/);
    assert.match(html, /aria-pressed="true"[^>]*data-tipo-da-pagina="premium"/);
    assert.match(html, /aria-pressed="false"[^>]*data-tipo-da-pagina="classico"/);
    assert.match(html, /data-pagina-do-anuncio="premium:v2"/);
    assert.match(html, /data-acao="fechar-pagina-do-anuncio"/);
    // Um tipo só: sem o seletor de tipo.
    const soClassico = { ...PREVIA_DO_ANUNCIO, anuncios: PREVIA_DO_ANUNCIO.anuncios.filter((a) => a.tipo === 'classico') };
    assert.doesNotMatch(renderToStaticMarkup(h(M.pagina.TelaCheiaDoAnuncio, { previa: soClassico })), /data-escolha-da-pagina="tipo"/);
});

test('o botão fechado é só o botão (a tela cheia só existe depois do clique)', () => {
    const html = renderToStaticMarkup(h(M.pagina.BotaoDaPagina, { previa: PREVIA_DO_ANUNCIO }));
    assert.match(texto(html), /Ver como o comprador vê/);
    assert.match(html, /aria-haspopup="dialog"/);
    assert.match(html, /data-acao="ver-como-o-comprador"/);
    assert.doesNotMatch(html, /role="dialog"/);
});

test('as duas gavetas mostram o botão na caixa "Anúncio": a prévia da equipe e a aprovação do cliente', () => {
    const equipe = renderToStaticMarkup(h(M.previa.VistaDaPrevia, { oferta: OFERTA_S2, previa: PREVIA_DO_ANUNCIO, trabalho: true }));
    const cliente = renderToStaticMarkup(h(M.cliente.VistaDaAprovacao, { oferta: OFERTA_S2_DO_CLIENTE, previa: PREVIA_DO_CLIENTE }));
    for (const [nome, html] of [['equipe', equipe], ['cliente', cliente]]) {
        const caixa = html.slice(html.indexOf('data-caixa="anuncio"'));
        assert.ok(caixa.length > 0, nome);
        assert.match(caixa, /data-acao="ver-como-o-comprador"/, nome);
        assert.equal((html.match(/data-acao="ver-como-o-comprador"/g) ?? []).length, 1, `${nome}: um botão só`);
    }
    // A prévia não pronta não tem a página.
    const naoPronta = renderToStaticMarkup(h(M.previa.VistaDaPrevia, { oferta: OFERTA_S2, previa: PREVIA_NAO_PRONTA, trabalho: true }));
    assert.doesNotMatch(naoPronta, /ver-como-o-comprador/);
});

test('as duas gavetas passam a escolha delas ao botão (a tela cheia abre no anúncio que a caixa mostra)', () => {
    for (const arquivo of ['resources/js/Components/Trilha/Revisao/PreviaDoAnuncio.jsx', 'resources/js/Components/Trilha/Cliente/AprovarAnuncio.jsx']) {
        const fonte = lerSemComentarios(arquivo);
        assert.match(fonte, /import \{ BotaoDaPagina \} from '@\/Components\/Trilha\/PaginaDoAnuncio';/, arquivo);
        assert.match(fonte, /<BotaoDaPagina previa=\{previa\} escolha=\{escolha\}/, arquivo);
    }
});

// ═══ As regras da fonte ══════════════════════════════════════════════════════

test('a tela cheia vai para o body por portal; o Esc fecha só ela (a gaveta não); o Tab gira dentro; o fundo não rola', () => {
    const fonte = lerSemComentarios('resources/js/Components/Trilha/PaginaDoAnuncio.jsx');
    assert.match(fonte, /import \{ createPortal \} from 'react-dom';/);
    assert.match(fonte, /typeof document === 'undefined' \? tela : createPortal\(tela, document\.body\)/);
    // A gaveta fecha no Esc pelo `document` (e respeita o `defaultPrevented`): a tela cheia trata antes e para ali.
    assert.match(fonte, /if \(evento\.key === 'Escape'\) \{\s*evento\.preventDefault\(\);\s*evento\.stopPropagation\(\);\s*onFechar\(\);/);
    assert.match(fonte, /if \(evento\.key !== 'Tab' \|\| !caixa\.current\) return;\s*evento\.stopPropagation\(\);/);
    assert.match(fonte, /document\.body\.style\.overflow = 'hidden';/);
    assert.match(fonte, /document\.body\.style\.overflow = antes;/);
    // O foco: no [Fechar] ao abrir, e de volta no botão ao fechar.
    assert.match(fonte, /fechar\.current\?\.focus\(\);/);
    assert.match(fonte, /botao\.current\?\.focus\(\);/);
});

test('as cores: a página é a do comprador (as do canal, fixas, nos dois temas); só a barra de cima usa as da trilha', () => {
    const fonte = lerSemComentarios('resources/js/Components/Trilha/PaginaDoAnuncio.jsx');
    const CANAL = ['#3483fa', '#00a650', '#ebebeb', '#f5f5f5', '#fff159', '#4189e6'];
    const hex = [...new Set([...fonte.matchAll(/\[(#[0-9a-fA-F]{3,8})\]/g)].map((m) => m[1].toLowerCase()))];
    assert.deepEqual(hex.filter((c) => !CANAL.includes(c)), [], 'só a paleta do canal');
    assert.equal(fonte.match(/\b(?:bg|text|border)-ecf-|[\w-]*\bpub-[\w-]*/), null, 'nada do ECF nem do Publicador');
    // A trilha só na barra da tela cheia (que começa no `TelaCheiaDoAnuncio`): a página não muda com o tema.
    const daPagina = fonte.slice(0, fonte.indexOf('export function TelaCheiaDoAnuncio'));
    assert.equal(daPagina.match(/\btrilha-[\w/-]+/), null, 'a página do comprador não usa as cores da trilha');
    // Os títulos com família e entrelinha explícitas (o app.css põe h1–h5 em Manrope 800, 1.08).
    for (const m of fonte.matchAll(/<h[1-3]\b[^>]*className=\{([^}]+)\}/g)) assert.match(m[1], /TITULO/, m[0]);
});

test('a trilha não importa nada do Publicador nem do Portal (vai para outro sistema)', () => {
    const fonte = lerSemComentarios('resources/js/Components/Trilha/PaginaDoAnuncio.jsx');
    const imports = [...fonte.matchAll(/from '([^']+)'/g)].map((m) => m[1]).sort();
    assert.deepEqual(imports, ['@/Components/Trilha/Pecas', '@/lib/trilhaPaginaDoAnuncio', '@/lib/utils', 'lucide-react', 'react', 'react-dom']);
});
