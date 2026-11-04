import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import axios from 'axios';
import { CONFLITO_DA_PREVIA, PREVIA_DO_ANUNCIO, PREVIA_NAO_PRONTA, PROPS_EQUIPE, PROPS_TRABALHO, paraCliente } from './_trilha-exemplo.js';
import { lerSemComentarios } from './_fonte.js';
import {
    ANTES_DA_REVISAO,
    MARCADOR_DO_ITEM,
    MOTIVO_FORA_DA_REVISAO,
    MOTIVO_SEM_PREVIA,
    ROTULO_DA_GERADA,
    agruparAnuncios,
    ajustarEscolha,
    anuncioEscolhido,
    bloqueiosDaPrevia,
    caminhoDaCategoria,
    capaDoAnuncio,
    descricaoDaPrevia,
    escolhaInicial,
    etapaDaPrevia,
    fmtPreco,
    fotosDoAnuncio,
    gruposDaFicha,
    linhasDoPreco,
    motivoDaPrevia,
    parcelasDoAnuncio,
    preparoDaPrevia,
    previaDoConflito,
    previaPronta,
    rotuloDaFoto,
    statusDaPrevia,
    textoDaPromocao,
    textoDasGeradas,
    textoDasParcelas,
    textoDoAjustado,
    textoDoEnvio,
    tiposDaPrevia,
    urlDaPrevia,
    variantesDaPrevia,
} from '../../resources/js/lib/trilhaPrevia.js';

// ═══════════════════════════════════════════════════════════════════════════
// Trilha de produtos — 4ª entrega, fatia 4a: a prévia do anúncio (a TELA).
//
// POR QUE EXISTE: a gaveta `v:{item}` lê o JSON do §19.5 do desenho (o servidor,
// `App\Services\Trilha\PreviaNaTrilha`, é feito em paralelo) pela URL
// `escrita.revisao.previa`, com o marcador `__ITEM__` no lugar do id. Um nome de
// campo trocado num lado só faria a gaveta mostrar "o robô ainda prepara" para
// um anúncio pronto, o Premium sem parcelas, a foto do robô sem o selo — ou o
// cartão com o nome do marketplace entrar no pacote do cliente. Aqui: as regras
// puras (`lib/trilhaPrevia.js`), a chamada (com o axios de mentira), a gaveta
// DESENHADA com o JSON do contrato (pronta, não pronta, 409, carregando, erro,
// antes da revisão), a chave `v:` na URL (só para a equipe com
// `escrita.revisao`), o "Revisar" da linha, o [Ver prévia] do painel da oferta e
// o `lazy` com o 2º caminho (learnings §30: sem ele a página some do manifest do
// Vite e o build sai 0).
//
// Não há DOM nos testes deste projeto: o pedido ao abrir (o efeito do hook) é
// coberto pelas funções puras que ele usa (`urlDaPrevia`, `previaDoConflito`) e
// por teste de fonte.
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

/**
 * Monta os arquivos da tela com o esbuild (o Inertia é de mentira). O axios fica de FORA: é o mesmo que este teste
 * importa, e é nele que as chamadas são trocadas. Tudo montado ANTES dos testes.
 */
async function montarTodos(arquivos) {
    const stub = path.join(__dirname, `.trilha-previa-inertia-stub-${process.pid}.mjs`);
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
            const saida = path.join(__dirname, `.trilha-previa-${nome}-${process.pid}-${Date.now()}.mjs`);
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
    escrita: 'resources/js/lib/trilhaEscrita.js',
    estado: 'resources/js/Components/Trilha/estadoDaTela.js',
    trilha: 'resources/js/lib/trilha.js',
    linha: 'resources/js/Components/Trilha/LinhaDoItem.jsx',
    previa: 'resources/js/Components/Trilha/Revisao/PreviaDoAnuncio.jsx',
    pagina: 'resources/js/Pages/Trilha/Empresa.jsx',
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
    .replace(/ /g, ' ')
    .replace(/\s+/g, ' ');

/** O `R$ 899,90` do `toLocaleString` leva espaço sem quebra; os testes comparam com espaço comum. */
const reais = (n) => fmtPreco(n).replace(/ /g, ' ');

/** Todas as tags `<nome …>` que contêm `marca`. */
const tags = (html, marca, nome = 'button') => (html.match(new RegExp(`<${nome}\\b[^>]*>`, 'g')) ?? []).filter((t) => t.includes(marca));
const tag = (html, marca, nome = 'button') => tags(html, marca, nome)[0] ?? '';

/** O pedaço do HTML de um elemento com `atributo="valor"`, até o próximo do mesmo atributo. */
function pedaco(html, atributo, valor) {
    const inicio = html.indexOf(`${atributo}="${valor}"`);
    assert.ok(inicio >= 0, `${atributo}=${valor}`);
    const proximo = html.indexOf(`${atributo}=`, inicio + 1);

    return html.slice(inicio, proximo > 0 ? proximo : undefined);
}

/** Desenha a página como se a URL tivesse a busca `busca`. */
function desenharPagina(props, busca = '') {
    global.window.location.search = busca;
    global.window.location.href = `http://localhost/trilha/459${busca}`;

    return renderToStaticMarkup(h(M.pagina.default, props));
}

/** O pedaço do HTML a partir do painel lateral (ele é desenhado depois da página). */
function painelLateral(html, chave) {
    const inicio = html.indexOf(`data-painel-lateral="${chave}"`);
    assert.ok(inicio >= 0, `o painel lateral de ${chave} devia estar aberto`);

    return html.slice(inicio);
}

const ESCRITA = PROPS_TRABALHO.escrita;
const OFERTA_S2 = PROPS_TRABALHO.ofertas.find((o) => o.id === 's-2');
const OFERTA_S4 = PROPS_TRABALHO.ofertas.find((o) => o.id === 's-4');
const OFERTA_S1 = PROPS_TRABALHO.ofertas.find((o) => o.id === 's-1');
const OFERTA_S3 = PROPS_TRABALHO.ofertas.find((o) => o.id === 's-3');
const SEM_REVISAO = { ...PROPS_TRABALHO, escrita: { ...ESCRITA, revisao: undefined } };

/** O nome do marketplace, o Portal e o "agente" (D59): nenhum deles no cartão. */
const PROIBIDO = /Mercado\s+Livre|Portal|\/portal|\bagente\b/i;

const desenharVista = (props = {}) => renderToStaticMarkup(h(M.previa.VistaDaPrevia, { oferta: OFERTA_S2, previa: PREVIA_DO_ANUNCIO, trabalho: true, ...props }));

// ─── As regras puras ────────────────────────────────────────────────────────

test('a URL: o id do item no lugar do marcador (§19.4); sem endereço, sem marcador ou sem id, nada', () => {
    assert.equal(MARCADOR_DO_ITEM, '__ITEM__');
    assert.equal(urlDaPrevia(ESCRITA, 's-12'), '/trilha/459/ofertas/s-12/previa');
    assert.equal(urlDaPrevia(ESCRITA, 'c-12-2'), '/trilha/459/ofertas/c-12-2/previa');
    assert.equal(urlDaPrevia(ESCRITA, 'o-55'), '/trilha/459/ofertas/o-55/previa');
    // O id vai codificado (nunca vira outro caminho).
    assert.equal(urlDaPrevia(ESCRITA, 's-1/../x'), '/trilha/459/ofertas/s-1%2F..%2Fx/previa');
    assert.equal(urlDaPrevia({ revisao: { previa: 'https://admin.x/trilha/459/ofertas/__ITEM__/previa?__ITEM__' } }, 's-1'), 'https://admin.x/trilha/459/ofertas/s-1/previa?s-1');
    for (const escrita of [null, {}, { revisao: null }, { revisao: {} }, { revisao: { previa: '/trilha/459/ofertas/s-1/previa' } }]) {
        assert.equal(urlDaPrevia(escrita, 's-1'), null, JSON.stringify(escrita));
    }
    for (const id of [null, undefined, '', '  ']) assert.equal(urlDaPrevia(ESCRITA, id), null, String(id));
});

test('pronta ou não: `pronta: true` com anúncio; o motivo do servidor, ou o neutro do §19.5', () => {
    assert.equal(previaPronta(PREVIA_DO_ANUNCIO), true);
    assert.equal(previaPronta(PREVIA_NAO_PRONTA), false);
    assert.equal(previaPronta({ ...PREVIA_DO_ANUNCIO, anuncios: [] }), false, 'pronta sem anúncio não desenha cartão vazio');
    assert.equal(previaPronta({ ...PREVIA_DO_ANUNCIO, pronta: 'sim' }), false);
    assert.equal(previaPronta(null), false);
    assert.equal(motivoDaPrevia(PREVIA_NAO_PRONTA), 'O robô ainda está preparando este anúncio.');
    assert.equal(motivoDaPrevia({ pronta: false, motivo: '  Falta a categoria deste anúncio.  ' }), 'Falta a categoria deste anúncio.');
    for (const p of [{ pronta: false }, { pronta: false, motivo: '' }, null]) assert.equal(motivoDaPrevia(p), MOTIVO_SEM_PREVIA);
    assert.equal(MOTIVO_SEM_PREVIA, 'O robô ainda está preparando este anúncio.');
    // Onde está e Situação: a prévia é mais nova que a oferta da página.
    assert.equal(etapaDaPrevia(PREVIA_DO_ANUNCIO, { etapa: 'robo' }), 'revisao');
    assert.equal(etapaDaPrevia(null, { etapa: 'robo' }), 'robo');
    assert.equal(statusDaPrevia(PREVIA_DO_ANUNCIO, OFERTA_S4), 'Pré-pronto · esperando a revisão');
    assert.equal(statusDaPrevia(null, OFERTA_S4), 'Faltam 2 itens para publicar');
});

test('o 409 (item fora da revisão) vira prévia "não pronta" com a mensagem do servidor; os outros erros, não', () => {
    const erro = (status, data = {}) => Object.assign(new Error('x'), { isAxiosError: true, response: { status, data } });
    assert.deepEqual(previaDoConflito(erro(409, CONFLITO_DA_PREVIA)), { pronta: false, motivo: 'Este anúncio não está mais na revisão.', anuncios: [], conflito: true });
    // A mensagem padrão do framework (em inglês) e o 409 sem corpo: o texto nosso.
    for (const data of [{}, { message: '' }, { message: 'Conflict' }, { message: 'Conflict.' }, null]) {
        assert.equal(previaDoConflito(erro(409, data)).motivo, MOTIVO_FORA_DA_REVISAO, JSON.stringify(data));
    }
    for (const outro of [erro(404), erro(403, { message: 'Sem acesso' }), erro(422, { errors: {} }), erro(500), new Error('rede'), null]) {
        assert.equal(previaDoConflito(outro), null);
    }
    // E a "não pronta" que ele monta desenha como a do 200 com `pronta: false`.
    assert.equal(previaPronta(previaDoConflito(erro(409, CONFLITO_DA_PREVIA))), false);
});

test('tipos e cores: agrupados por tipo × cor; só o que tem anúncio vira aba; a escolha começa no Clássico da 1ª cor', () => {
    const grupos = agruparAnuncios(PREVIA_DO_ANUNCIO);
    assert.deepEqual(Object.keys(grupos), ['classico', 'premium']);
    assert.deepEqual(Object.keys(grupos.classico), ['v1', 'v2']);
    assert.equal(grupos.premium.v2.preco, 989.9);
    assert.deepEqual(tiposDaPrevia(PREVIA_DO_ANUNCIO), [{ chave: 'classico', nome: 'Clássico' }, { chave: 'premium', nome: 'Premium' }]);
    assert.deepEqual(variantesDaPrevia(PREVIA_DO_ANUNCIO), [{ chave: 'v1', rotulo: 'Natural' }, { chave: 'v2', rotulo: 'Mel' }]);
    assert.deepEqual(escolhaInicial(PREVIA_DO_ANUNCIO), { tipo: 'classico', variante: 'v1' });

    // Só o Premium ligado: uma aba só; a cor que só um tipo tem não aparece no outro.
    const soPremium = { ...PREVIA_DO_ANUNCIO, anuncios: PREVIA_DO_ANUNCIO.anuncios.filter((a) => a.tipo === 'premium') };
    assert.deepEqual(tiposDaPrevia(soPremium).map((t) => t.chave), ['premium']);
    const semMelNoPremium = { ...PREVIA_DO_ANUNCIO, anuncios: PREVIA_DO_ANUNCIO.anuncios.filter((a) => !(a.tipo === 'premium' && a.variante === 'v2')) };
    assert.deepEqual(variantesDaPrevia(semMelNoPremium, 'premium').map((v) => v.chave), ['v1']);
    assert.deepEqual(variantesDaPrevia(semMelNoPremium, 'classico').map((v) => v.chave), ['v1', 'v2']);

    // Trocar de tipo mantém a cor; a cor que o tipo não tem cai na 1ª dele; o que não existe volta ao começo.
    assert.deepEqual(ajustarEscolha(PREVIA_DO_ANUNCIO, { tipo: 'premium', variante: 'v2' }), { tipo: 'premium', variante: 'v2' });
    assert.deepEqual(ajustarEscolha(semMelNoPremium, { tipo: 'premium', variante: 'v2' }), { tipo: 'premium', variante: 'v1' });
    assert.deepEqual(ajustarEscolha(PREVIA_DO_ANUNCIO, { tipo: 'diamante', variante: 'v9' }), { tipo: 'classico', variante: 'v1' });
    assert.deepEqual(ajustarEscolha(PREVIA_NAO_PRONTA, {}), { tipo: null, variante: null });

    assert.equal(anuncioEscolhido(PREVIA_DO_ANUNCIO, { tipo: 'premium', variante: 'v2' }), PREVIA_DO_ANUNCIO.anuncios[3]);
    assert.equal(anuncioEscolhido(PREVIA_DO_ANUNCIO, { tipo: 'premium', variante: 'v9' }), PREVIA_DO_ANUNCIO.anuncios[2], 'sem o par, o 1º do tipo');
    assert.equal(anuncioEscolhido(PREVIA_DO_ANUNCIO, {}), PREVIA_DO_ANUNCIO.anuncios[0]);
    assert.equal(anuncioEscolhido(PREVIA_NAO_PRONTA, {}), null);

    // Dado antigo: sem a lista `tipos`/`variantes`, e a cor na forma do retrato de origem (`{chave, rotulo}`).
    const antigo = { anuncios: [{ tipo: 'premium', variante: { chave: 'v7', rotulo: 'Preto' } }, { tipo: 'classico', variante: { chave: 'v7', rotulo: 'Preto' } }] };
    assert.deepEqual(tiposDaPrevia(antigo), [{ chave: 'premium', nome: 'Premium' }, { chave: 'classico', nome: 'Clássico' }]);
    assert.deepEqual(variantesDaPrevia(antigo), [{ chave: 'v7', rotulo: 'Preto' }]);
});

test('fotos: a ordem do anúncio (a capa do Premium é a do robô), `gerada` booleano, o texto das geradas e o alternativo', () => {
    const [classicoNatural, , premiumNatural] = PREVIA_DO_ANUNCIO.anuncios;
    const fotos = fotosDoAnuncio(classicoNatural);
    assert.deepEqual(fotos.map((f) => [f.id, f.gerada]), [['881', false], ['882', false], ['883', false], ['890', true], ['891', true]]);
    assert.equal(fotos[0].url, '/trilha/459/ofertas/s-2/fotos/881', 'a URL é a da rota da trilha (D42)');
    assert.equal(capaDoAnuncio(classicoNatural).id, '881');
    assert.equal(capaDoAnuncio(premiumNatural).id, '890');
    assert.equal(capaDoAnuncio(premiumNatural).gerada, true);
    assert.equal(capaDoAnuncio({ fotos: [] }), null);
    // Sem URL, fora; `gerada` que não é `true` vira falso. R3: sem `miniatura` (a porta do cliente, dado antigo), a grade
    // usa a própria foto; com ela, a versão pequena.
    assert.deepEqual(fotosDoAnuncio({ fotos: [{ id: 1, url: '' }, { id: 2, url: '/x.jpg', gerada: 1 }, null] }), [{ id: '2', url: '/x.jpg', miniatura: '/x.jpg', gerada: false }]);
    assert.equal(fotosDoAnuncio({ fotos: [{ id: 3, url: '/y.jpg', miniatura: ' /y.jpg?miniatura=1 ' }] })[0].miniatura, '/y.jpg?miniatura=1');
    assert.equal(fotosDoAnuncio({ fotos: [{ id: 3, url: '/y.jpg', miniatura: null }] })[0].miniatura, '/y.jpg');

    assert.equal(ROTULO_DA_GERADA, 'gerada pelo robô');
    assert.equal(textoDasGeradas(fotos), '2 das 5 fotos foram geradas pelo robô.');
    assert.equal(textoDasGeradas(fotosDoAnuncio(PREVIA_DO_ANUNCIO.anuncios[1])), '1 das 4 fotos foi gerada pelo robô.');
    assert.equal(textoDasGeradas([{ gerada: true }]), 'A foto foi gerada pelo robô.');
    assert.equal(textoDasGeradas([{ gerada: true }, { gerada: true }]), 'As 2 fotos foram geradas pelo robô.');
    assert.equal(textoDasGeradas([{ gerada: false }]), null);
    assert.equal(textoDasGeradas([]), null);
    assert.equal(rotuloDaFoto(fotos[3], 3, 5), 'Foto 4 de 5, gerada pelo robô');
    assert.equal(rotuloDaFoto(fotos[0], 0, 5), 'Foto 1 de 5');
});

test('preço: o valor, as parcelas do Premium (as duas formas), a promoção prevista e o "ajustado" da Precificação', () => {
    assert.equal(reais(899.9), 'R$ 899,90');
    assert.equal(reais(1234.5), 'R$ 1.234,50');
    assert.equal(fmtPreco(null), '—');
    assert.equal(fmtPreco('x'), '—');

    // O Clássico não parcela; o Premium vem na forma do retrato de origem — ou direta.
    assert.equal(parcelasDoAnuncio(PREVIA_DO_ANUNCIO.anuncios[0]), null);
    assert.deepEqual(parcelasDoAnuncio(PREVIA_DO_ANUNCIO.anuncios[2]), { vezes: 10, valor: 98.99 });
    assert.deepEqual(parcelasDoAnuncio({ parcelas: { vezes: 6, valor: '50.5' } }), { vezes: 6, valor: 50.5 });
    for (const parcelas of [{ sem_promocao: null }, { vezes: 0, valor: 10 }, { vezes: 3 }, 'x']) assert.equal(parcelasDoAnuncio({ parcelas }), null, JSON.stringify(parcelas));
    assert.equal(textoDasParcelas({ vezes: 10, valor: 98.99 }).replace(/ /g, ' '), 'em 10× R$ 98,99 sem juros');
    assert.equal(textoDasParcelas(null), null);

    assert.equal(textoDaPromocao(PREVIA_DO_ANUNCIO.anuncios[1]).replace(/ /g, ' '), 'Depois de publicar, o sistema tenta uma promoção: R$ 809,91 (10% de desconto).');
    assert.equal(textoDaPromocao({ promocao: { preco: 100, percentual: 16.67 } }).replace(/ /g, ' '), 'Depois de publicar, o sistema tenta uma promoção: R$ 100,00 (16% de desconto).');
    assert.equal(textoDaPromocao(PREVIA_DO_ANUNCIO.anuncios[0]), null);

    const linhas = linhasDoPreco(PREVIA_DO_ANUNCIO);
    assert.deepEqual(linhas, [
        { chave: 'classico', nome: 'Clássico', valor: 899.9, daPrecificacao: 879.9, ajustado: true },
        { chave: 'premium', nome: 'Premium', valor: 989.9, daPrecificacao: 989.9, ajustado: false },
    ]);
    assert.equal(textoDoAjustado(linhas[0]).replace(/ /g, ' '), 'na Precificação: R$ 879,90');
    assert.equal(textoDoAjustado(linhas[1]), 'igual à Precificação');
    // Sem a bandeira, a régua do meio centavo; sem o da Precificação (a porta do cliente, 4e), nenhuma nota.
    const semBandeira = linhasDoPreco({ tipos: [], preco: { classico: { valor: 100, da_precificacao: 100.004 }, premium: { valor: 110, da_precificacao: 100 } } });
    assert.deepEqual(semBandeira.map((l) => [l.chave, l.ajustado]), [['classico', false], ['premium', true]]);
    const doCliente = linhasDoPreco({ ...PREVIA_DO_ANUNCIO, preco: { classico: { valor: 899.9, ajustado: true } } });
    assert.deepEqual(doCliente.map((l) => [l.nome, l.ajustado, textoDoAjustado(l)]), [['Clássico', true, null]]);
    // O tipo desligado vem `null` (só o Clássico ligado); fora da prévia pronta, o `preco` inteiro vem `null`.
    const soClassico = linhasDoPreco({ ...PREVIA_DO_ANUNCIO, preco: { classico: PREVIA_DO_ANUNCIO.preco.classico, premium: null } });
    assert.deepEqual(soClassico.map((l) => l.chave), ['classico']);
    assert.equal(PREVIA_NAO_PRONTA.preco, null);
    assert.deepEqual(linhasDoPreco(PREVIA_NAO_PRONTA), []);
});

test('o resto do cartão: categoria, descrição, ficha agrupada, entrega, preparo e bloqueios', () => {
    assert.equal(caminhoDaCategoria(PREVIA_DO_ANUNCIO), 'Casa, Móveis e Decoração › Sala de Jantar › Cadeiras');
    assert.equal(caminhoDaCategoria(PREVIA_NAO_PRONTA), null);
    assert.ok(descricaoDaPrevia(PREVIA_DO_ANUNCIO).startsWith('Olá! Que bom ter você na Casa Jatobá.\n'));
    assert.equal(descricaoDaPrevia({ descricao: '   ' }), null);

    assert.deepEqual(gruposDaFicha(PREVIA_DO_ANUNCIO.anuncios[1]).map((g) => [g.grupo, g.itens.length]), [['Principais', 3], ['Dimensões', 3]]);
    assert.deepEqual(gruposDaFicha(PREVIA_DO_ANUNCIO.anuncios[1])[0].itens[2], { nome: 'Cor', valor: 'Mel' });
    // Grupo vazio e item sem nome ou valor saem; número vira texto.
    assert.deepEqual(gruposDaFicha({ caracteristicas: [{ grupo: 'Vazio', itens: [] }, { itens: [{ nome: 'Peso', valor: 7 }, { nome: '', valor: 'x' }, { nome: 'Cor' }] }] }), [
        { grupo: 'Características', itens: [{ nome: 'Peso', valor: '7' }] },
    ]);

    assert.equal(textoDoEnvio(PREVIA_DO_ANUNCIO.anuncios[0].envio), 'Entrega pelo ME2, com frete grátis para o comprador.');
    assert.equal(textoDoEnvio({ modo: 'me2', frete_gratis: false, aviso: null }), 'Entrega pelo ME2.');
    assert.equal(textoDoEnvio({ modo: 'me1', frete_gratis: true }), 'Entrega por transportadora (ME1), com frete grátis para o comprador.');
    assert.equal(textoDoEnvio({ modo: 'not_specified', aviso: 'O frete sai pela conta.' }), 'Entrega a combinar com o comprador. O frete sai pela conta.');
    assert.equal(textoDoEnvio({ modo: 'outro', frete_gratis: true }), null);
    assert.equal(textoDoEnvio(null), null);

    assert.deepEqual(preparoDaPrevia(PREVIA_DO_ANUNCIO), [
        { campo: 'titulo', rotulo: 'Título', tom: 'ok', estado: 'pronto' },
        { campo: 'descricao', rotulo: 'Descrição', tom: 'ok', estado: 'pronto' },
        { campo: 'imagens', rotulo: 'Imagens', tom: 'ok', estado: 'pronto' },
    ]);
    // Campo nulo fica de fora; o estado desconhecido vai como veio; a porta do cliente (4e) não manda `preparo`.
    assert.deepEqual(preparoDaPrevia(PREVIA_NAO_PRONTA).map((p) => p.campo), ['titulo', 'descricao']);
    assert.deepEqual(preparoDaPrevia({ preparo: { titulo: 'erro', descricao: 'refazendo' } }).map((p) => [p.tom, p.estado]), [['bad', 'erro'], ['neutro', 'refazendo']]);
    assert.deepEqual(preparoDaPrevia({}), []);

    assert.deepEqual(bloqueiosDaPrevia(PREVIA_DO_ANUNCIO), []);
    assert.deepEqual(bloqueiosDaPrevia({ bloqueios: ['Falta a garantia', { mensagem: 'Preço do Premium igual ao Clássico' }, { texto: '' }, 7] }), ['Falta a garantia', 'Preço do Premium igual ao Clássico']);
});

test('lib/trilhaPrevia.js é pura: sem React, sem axios, sem classe do Tailwind, sem o marketplace, o Portal, o Publicador nem o "agente"', () => {
    const fonte = lerSemComentarios('resources/js/lib/trilhaPrevia.js');
    assert.equal(fonte.match(/^\s*import\b/m), null, 'nenhum import: o node a carrega direto');
    assert.equal(fonte.match(/className|\b(?:bg|text|border|fill|stroke)-trilha-/), null);
    assert.equal(fonte.match(/Mercado\s+Livre|Portal|\/portal|Publicador|\bagente\b/i), null);
});

// ─── A chamada ao servidor ─────────────────────────────────────────────────

test('carregar: GET na URL pronta (a gaveta troca o marcador); sem URL nada sai e o erro chega legível', async () => {
    const pedidos = [];
    const original = axios.get;
    axios.get = async (url, corpo) => {
        pedidos.push({ url, corpo });

        return { data: PREVIA_DO_ANUNCIO };
    };
    try {
        assert.deepEqual(await M.escrita.carregarPrevia(urlDaPrevia(ESCRITA, 's-2')), PREVIA_DO_ANUNCIO);
        await assert.rejects(M.escrita.carregarPrevia(urlDaPrevia({ ...ESCRITA, revisao: undefined }, 's-2')), (erro) => {
            assert.equal(M.escrita.mensagemDoErro(erro), 'A prévia não está disponível nesta tela: recarregue a página.');

            return true;
        });
    } finally {
        axios.get = original;
    }
    assert.deepEqual(pedidos, [{ url: '/trilha/459/ofertas/s-2/previa', corpo: undefined }]);
});

test('lib/trilhaEscrita.js NÃO importa a lib da prévia (a página do cliente importa trilhaEscrita)', () => {
    const escrita = lerSemComentarios('resources/js/lib/trilhaEscrita.js');
    assert.equal(escrita.match(/from ['"][^'"]*trilhaPrevia[^'"]*['"]/), null);
    assert.equal(escrita.match(/__ITEM__/), null, 'o marcador é trocado na gaveta, não aqui');
});

// ─── A gaveta desenhada ─────────────────────────────────────────────────────

test('a prévia pronta (§19.5): Onde está, o cartão do anúncio, o Preço com o "ajustado" e a Situação', () => {
    const saida = desenharVista();
    const t = texto(saida);
    for (const s of [
        'Simples · Prévia do anúncio', 'Cadeira jatobá assento palhinha', 'CJ-211 = 1× CJ-211',
        'Onde está', 'Revisão e publicação', 'Anúncio', 'Casa, Móveis e Decoração › Sala de Jantar › Cadeiras',
        'Cadeira Jatobá Assento Palhinha Madeira Maciça Sala De Jantar', 'R$ 899,90', 'Entrega pelo ME2, com frete grátis para o comprador.',
        'Descrição', 'Olá! Que bom ter você na Casa Jatobá.', 'CUIDADOS E RECOMENDAÇÕES',
        'Ficha técnica', 'Principais', 'Material da estrutura', 'Jatobá maciço', 'Dimensões', 'Altura', '92 cm',
        '2 das 5 fotos foram geradas pelo robô.',
        'Preço', 'Clássico', 'ajustado', 'na Precificação: R$ 879,90', 'Premium', 'R$ 989,90', 'igual à Precificação',
        'Situação', 'Pré-pronto · esperando a revisão', 'Preparo do robô:', 'Título · pronto', 'Descrição · pronto', 'Imagens · pronto',
        'Ver a oferta',
    ]) {
        assert.ok(t.includes(s), `faltou: ${s}`);
    }
    // A ordem do §19.7: Onde está → Anúncio → Preço → Situação.
    const ordem = ['data-caixa="onde-esta"', 'data-caixa="anuncio"', 'data-caixa="preco"', 'data-caixa="situacao"'].map((m) => saida.indexOf(m));
    assert.ok(ordem.every((i, j) => i >= 0 && (j === 0 || i > ordem[j - 1])), JSON.stringify(ordem));
    // O Clássico abre marcado, na 1ª cor; as duas abas de cada.
    assert.ok(tag(pedaco(saida, 'data-escolha', 'tipo'), 'data-opcao="classico"').includes('aria-pressed="true"'));
    assert.ok(tag(pedaco(saida, 'data-escolha', 'tipo'), 'data-opcao="premium"').includes('aria-pressed="false"'));
    assert.ok(texto(pedaco(saida, 'data-escolha', 'tipo')).includes('Clássico') && texto(pedaco(saida, 'data-escolha', 'tipo')).includes('Premium'));
    assert.ok(tag(saida, 'data-opcao="v1"').includes('aria-pressed="true"') && tag(saida, 'data-opcao="v2"').includes('aria-pressed="false"'));
    assert.match(saida, /data-anuncio="classico:v1"/);
    // No Clássico não há parcelas nem promoção (a da cor Mel não aparece na Natural).
    assert.ok(!saida.includes('data-parcelas') && !saida.includes('data-promocao'));
    // O "ajustado" só no Clássico.
    assert.ok(pedaco(saida, 'data-preco', 'classico').includes('data-ajustado') && !pedaco(saida, 'data-preco', 'premium').includes('data-ajustado'));
    // R10 (04/11/2026): nenhum link do Publicador — nem com o endereço velho na oferta de exemplo (`url_publicador`).
    assert.ok(OFERTA_S2.url_publicador, 'o exemplo ainda traz o endereço');
    assert.ok(!saida.includes('data-link="publicador"') && !saida.includes('/mlb/anuncios/publicador') && !t.includes('Abrir no Publicador'));
    // Nada do marketplace, do Portal nem do "agente" (D59).
    assert.equal(t.match(PROIBIDO), null);
});

test('a galeria: a capa grande, as miniaturas, as do robô com o selo "gerada pelo robô" em roxo e toda foto com `loading="lazy"`', () => {
    const saida = desenharVista();
    const galeria = pedaco(saida, 'data-galeria', '');
    const imagens = galeria.match(/<img\b[^>]*>/g) ?? [];
    // A grande (a capa) + 5 miniaturas; mais as capas das cores no seletor.
    assert.equal(imagens.length, 6);
    for (const img of saida.match(/<img\b[^>]*>/g)) assert.ok(img.includes('loading="lazy"'), img);
    assert.ok(imagens[0].includes('src="/trilha/459/ofertas/s-2/fotos/881"') && imagens[0].includes('alt="Foto 1 de 5"'), imagens[0]);
    assert.match(galeria, /data-capa=""/);
    // A capa do Clássico é do cliente: sem selo na foto grande.
    assert.ok(!pedaco(saida, 'data-foto-grande', '881').slice(0, pedaco(saida, 'data-foto-grande', '881').indexOf('</figure>')).includes('data-selo-gerada'));
    // As miniaturas do robô: marcadas e com o rótulo acessível.
    const geradas = tags(galeria, 'data-gerada=""');
    assert.deepEqual(geradas.map((b) => b.match(/data-foto="(\d+)"/)[1]), ['890', '891']);
    assert.ok(geradas.every((b) => b.includes('border-trilha-roxo/60') && /aria-label="Foto \d de 5, gerada pelo robô"/.test(b)), geradas.join('\n'));
    assert.ok(tag(galeria, 'data-foto="881"').includes('aria-pressed="true"'), 'a capa é a escolhida ao abrir');
    // As cores com a capa de cada uma.
    assert.match(pedaco(saida, 'data-escolha', 'variante'), /<img src="\/trilha\/459\/ofertas\/s-2\/fotos\/884" alt="" loading="lazy"/);
});

test('R3: a grade e a bolinha da cor pedem a MINIATURA; só a foto em destaque é a grande', () => {
    // A prévia da equipe traz `miniatura` em cada foto (o mesmo endereço com `?miniatura=1`).
    const comMiniatura = {
        ...PREVIA_DO_ANUNCIO,
        anuncios: PREVIA_DO_ANUNCIO.anuncios.map((a) => ({ ...a, fotos: a.fotos.map((f) => ({ ...f, miniatura: `${f.url}?miniatura=1` })) })),
    };
    const saida = desenharVista({ previa: comMiniatura });
    const galeria = pedaco(saida, 'data-galeria', '');
    const grande = pedaco(saida, 'data-foto-grande', '881');
    assert.match(grande, /<img src="\/trilha\/459\/ofertas\/s-2\/fotos\/881" /, 'a foto em destaque é a inteira');
    const grade = (galeria.match(/<button[^>]*data-foto="\d+"[\s\S]*?<\/button>/g) ?? []).map((b) => b.match(/<img src="([^"]+)"/)[1]);
    assert.equal(grade.length, 5);
    assert.ok(grade.every((src) => src.endsWith('?miniatura=1')), grade.join(' '));
    assert.match(pedaco(saida, 'data-escolha', 'variante'), /<img src="\/trilha\/459\/ofertas\/s-2\/fotos\/884\?miniatura=1" alt="" loading="lazy"/);
});

test('o Premium (outra escolha): a capa é a do robô, com o selo; o preço com as parcelas sem juros', () => {
    const saida = desenharVista({ inicial: { tipo: 'premium', variante: 'v2' } });
    const t = texto(saida);
    assert.match(saida, /data-anuncio="premium:v2"/);
    assert.ok(tag(saida, 'data-opcao="premium"').includes('aria-pressed="true"') && tag(saida, 'data-opcao="v2"').includes('aria-pressed="true"'));
    const grande = pedaco(saida, 'data-foto-grande', '892');
    assert.ok(grande.includes('alt="Foto 1 de 4, gerada pelo robô"'), grande.slice(0, 300));
    assert.ok(grande.slice(0, grande.indexOf('</figure>')).includes('data-selo-gerada'), 'a capa do robô leva o selo');
    assert.ok(texto(grande.slice(0, grande.indexOf('</figure>'))).includes('gerada pelo robô'));
    assert.ok(t.includes('Cadeira Jatobá Palhinha Madeira Maciça Para Sala De Jantar'));
    assert.ok(t.includes('R$ 989,90') && t.includes('em 10× R$ 98,99 sem juros') && t.includes('estimativa pela faixa de preço'), t);
    assert.match(saida, /data-parcelas=""/);
    // A cor Mel do Clássico tem a promoção prevista: ela aparece só lá.
    const melClassico = texto(desenharVista({ inicial: { tipo: 'classico', variante: 'v2' } }));
    assert.ok(melClassico.includes('Depois de publicar, o sistema tenta uma promoção: R$ 809,91 (10% de desconto).'), melClassico);
    assert.ok(melClassico.includes('1 das 4 fotos foi gerada pelo robô.'));
});

test('uma cor só e um tipo só: sem as abas que não escolhem nada; anúncio sem foto e sem descrição avisa', () => {
    const umSo = {
        ...PREVIA_DO_ANUNCIO,
        variantes: [{ chave: 'v1', rotulo: 'Natural' }],
        anuncios: [{ ...PREVIA_DO_ANUNCIO.anuncios[0], fotos: [], titulo: null }],
        descricao: null,
    };
    const saida = desenharVista({ previa: umSo });
    const t = texto(saida);
    assert.ok(!saida.includes('data-escolha="tipo"') && !saida.includes('data-escolha="variante"'));
    assert.ok(saida.includes('data-sem-fotos') && t.includes('Este anúncio ainda não tem foto.'));
    assert.ok(t.includes('Sem título ainda') && t.includes('Sem descrição ainda.'));
    // A Preço segue mostrando os dois tipos que o servidor mandou.
    assert.ok(saida.includes('data-preco="classico"') && saida.includes('data-preco="premium"'));
});

test('sem prévia (200 `pronta: false`) e o 409: o motivo neutro no lugar do cartão, sem Preço e sem "Tentar de novo"', () => {
    const naoPronta = desenharVista({ oferta: OFERTA_S4, previa: PREVIA_NAO_PRONTA });
    const t = texto(naoPronta);
    assert.ok(t.includes('O robô ainda está preparando este anúncio.'), t);
    assert.ok(naoPronta.includes('data-previa-indisponivel') && !naoPronta.includes('data-galeria') && !naoPronta.includes('data-caixa="preco"'));
    assert.ok(!naoPronta.includes('data-acao="tentar-previa-de-novo"') && !naoPronta.includes('role="alert"'));
    // A Situação continua: o status da prévia e o preparo dela.
    assert.ok(t.includes('Faltam 2 itens para publicar') && t.includes('Título · pronto') && !t.includes('Imagens ·'));
    assert.ok(naoPronta.includes('data-caixa="situacao"'));

    const conflito = previaDoConflito({ response: { status: 409, data: CONFLITO_DA_PREVIA } });
    const doConflito = desenharVista({ oferta: OFERTA_S4, previa: conflito });
    assert.ok(texto(doConflito).includes('Este anúncio não está mais na revisão.'));
    assert.ok(!doConflito.includes('data-galeria') && !doConflito.includes('data-acao="tentar-previa-de-novo"'));
    // Sem a prévia do servidor, a Situação é a da oferta da página.
    assert.ok(texto(doConflito).includes('Faltam 2 itens para publicar'));
    assert.equal(texto(naoPronta + doConflito).match(PROIBIDO), null);
});

test('carregando, erro e antes da revisão (D44)', () => {
    const carregando = desenharVista({ ...M.previa.PREVIA_CARREGANDO });
    assert.match(carregando, /aria-busy="true"/);
    assert.ok(texto(carregando).includes('Abrindo a prévia…') && carregando.includes('data-carregando-previa'));
    assert.ok(!carregando.includes('data-caixa="situacao"') && !carregando.includes('data-galeria'));

    const erro = desenharVista({ previa: null, erro: 'O servidor falhou. Tente de novo em instantes.' });
    assert.match(erro, /role="alert"/);
    assert.ok(texto(erro).includes('Não deu para abrir a prévia.') && texto(erro).includes('O servidor falhou. Tente de novo em instantes.'));
    assert.ok(erro.includes('data-acao="tentar-previa-de-novo"') && !erro.includes('data-galeria'));

    const antes = desenharVista({ oferta: OFERTA_S3, previa: null, antes: true });
    assert.ok(texto(antes).includes(ANTES_DA_REVISAO), texto(antes));
    assert.equal(ANTES_DA_REVISAO, 'O robô ainda prepara este anúncio: a prévia aparece quando ele chegar à revisão.');
    assert.ok(texto(antes).includes('Preparação automática') && texto(antes).includes(OFERTA_S3.status));
});

test('a gaveta de verdade (com o hook): na revisão abre "Abrindo a prévia…"; antes dela, nem pergunta ao servidor', () => {
    const naRevisao = renderToStaticMarkup(h(M.previa.default, { oferta: OFERTA_S2, escrita: ESCRITA, trabalho: true }));
    assert.ok(naRevisao.includes('data-carregando-previa') && naRevisao.includes('aria-busy="true"'));
    // A oferta com o robô (D44): o texto, sem "carregando" — o efeito do pedido nem chega a rodar.
    const antes = renderToStaticMarkup(h(M.previa.default, { oferta: OFERTA_S3, escrita: ESCRITA, trabalho: true }));
    assert.ok(texto(antes).includes(ANTES_DA_REVISAO) && !antes.includes('data-carregando-previa'));
    // Publicada: tem prévia (conferência).
    const publicada = renderToStaticMarkup(h(M.previa.default, { oferta: OFERTA_S1, escrita: ESCRITA, trabalho: true }));
    assert.ok(publicada.includes('data-carregando-previa'));

    // O pedido ao abrir: `urlDaPrevia` com o id da oferta, o 409 pelo `previaDoConflito`, o resto pelo texto comum.
    const fonte = lerSemComentarios('resources/js/Components/Trilha/Revisao/PreviaDoAnuncio.jsx');
    assert.match(fonte, /urlDaPrevia\(escrita, oferta\?\.id\)/);
    assert.match(fonte, /carregarPrevia\(url\)/);
    assert.match(fonte, /previaDoConflito\(falha\)/);
    assert.match(fonte, /mensagemDoErro\(falha\)/);
    assert.match(fonte, /if \(antes\) return undefined;/);
});

test('o rodapé: "Ver a oferta" abre o painel `o:`; nenhum link do Publicador, nem com o endereço na oferta (R10)', () => {
    const abertos = [];
    // A oferta de exemplo ainda traz `url_publicador` (o servidor deixou de mandar): mesmo assim, só o [Ver a oferta].
    assert.ok(OFERTA_S2.url_publicador);
    const miolo = M.previa.VistaDaPrevia({ oferta: OFERTA_S2, previa: PREVIA_DO_ANUNCIO, onAbrir: (chave) => abertos.push(chave) });
    assert.equal(miolo.props.rodape.length, 1);
    const [verOferta] = miolo.props.rodape;
    verOferta.props.onClick();
    assert.deepEqual(abertos, ['o:s-2']);
    const comLink = renderToStaticMarkup(h(M.previa.VistaDaPrevia, { oferta: OFERTA_S2, previa: PREVIA_DO_ANUNCIO }));
    assert.ok(comLink.includes('data-acao="ver-oferta"') && !comLink.includes('data-link="publicador"'));
    // (O "Publicador" que sobra no texto é o dono da etapa 5 no "Onde está" — o de `lib/trilha.js`, que já existia.)
    assert.ok(!texto(comLink).includes('Abrir no Publicador') && !comLink.includes('/mlb/anuncios/publicador'));
});

// ─── A chave `v:` (só para a equipe com `escrita.revisao`) ─────────────────

test('a chave `v:`: acha a oferta (s-, c- e o-); só abre com `comPrevia`; etapa da linha e volta do foco para a oferta', () => {
    const { acharAberto, chaveDaPrevia, temPrevia, ETAPAS_DA_PREVIA } = M.trilha;
    const { painelDaChave, lerEstadoDaUrl, etapaDoItem, candidatasDaVolta, podeVerPrevia } = M.estado;
    assert.equal(chaveDaPrevia(OFERTA_S2), 'v:s-2');
    for (const id of ['s-2', 'c-2-4', 'o-31']) {
        const achado = acharAberto(PROPS_TRABALHO, `v:${id}`);
        assert.equal(achado.painel, 'previa');
        assert.equal(achado.oferta.id, id);
    }
    assert.equal(acharAberto(PROPS_TRABALHO, 'v:s-999'), null);
    assert.deepEqual(ETAPAS_DA_PREVIA, ['revisao', 'lanc']);
    assert.deepEqual(PROPS_TRABALHO.ofertas.filter(temPrevia).map((o) => o.id), ['s-1', 's-2', 's-4', 's-10', 's-11', 'o-40']);

    // Só com escrita E com a prévia: o resto não abre nada (nem cai no painel da oferta).
    assert.equal(painelDaChave(PROPS_TRABALHO, 'v:s-2', { podeEditar: true, comPrevia: true }).painel, 'previa');
    for (const quem of [{}, { podeEditar: true }, { comPrevia: true }]) {
        assert.equal(painelDaChave(PROPS_TRABALHO, 'v:s-2', quem), null, JSON.stringify(quem));
        assert.equal(lerEstadoDaUrl('?abrir=v%3As-2', PROPS_TRABALHO, quem).abrir, null);
    }
    assert.equal(lerEstadoDaUrl('?abrir=v%3As-2&etapa=revisao', PROPS_TRABALHO, { podeEditar: true, comPrevia: true }).abrir, 'v:s-2');
    assert.equal(lerEstadoDaUrl('?abrir=v:s-2', PROPS_TRABALHO, { podeEditar: true, comPrevia: true }).abrir, 'v:s-2', 'a forma escrita à mão também');
    assert.equal(etapaDoItem(PROPS_TRABALHO, 'v:s-2'), 'revisao');
    assert.equal(etapaDoItem(PROPS_TRABALHO, 'v:s-1'), 'lanc');
    assert.deepEqual(candidatasDaVolta('v:s-2'), ['v:s-2', 'o:s-2']);
    assert.deepEqual(candidatasDaVolta('v:c-2-4'), ['v:c-2-4', 'o:c-2-4']);

    // Quem vê: a equipe que escreve e recebeu o endereço; nunca o cliente nem o "Ver como: Cliente".
    assert.equal(podeVerPrevia(PROPS_TRABALHO), true);
    assert.equal(podeVerPrevia(PROPS_TRABALHO, 'cliente'), false);
    assert.equal(podeVerPrevia(SEM_REVISAO), false);
    assert.equal(podeVerPrevia({ ...PROPS_TRABALHO, escrita: { ...ESCRITA, revisao: {} } }), false);
    assert.equal(podeVerPrevia(paraCliente(PROPS_TRABALHO)), false);
    assert.equal(podeVerPrevia({ ...PROPS_TRABALHO, pode_editar: false }), false);
    assert.equal(podeVerPrevia({ ...PROPS_EQUIPE, escrita: ESCRITA }), false, 'sem a trilha de trabalho');
});

test('gaveta `v:` pela URL: a equipe com `escrita.revisao` vê a prévia (o JS chega por lazy); o cliente, o "Ver como" e os outros, nada', () => {
    const saida = desenharPagina(PROPS_TRABALHO, '?abrir=v%3As-2&etapa=revisao');
    const gaveta = painelLateral(saida, 'v:s-2');
    assert.match(gaveta, /role="dialog" aria-modal="true"/);
    // O 1º render mostra o fallback do `lazy`; no bundle de teste os seguintes podem já desenhar a gaveta carregando:
    // as duas dizem o mesmo.
    assert.ok(texto(gaveta).includes('Simples · Prévia do anúncio') && texto(gaveta).includes('Cadeira jatobá assento palhinha'), texto(gaveta));
    assert.ok(texto(gaveta).includes('Abrindo a prévia…'));
    assert.match(gaveta, /aria-busy="true"/);
    // O id do Combo e do Kit também abrem.
    assert.ok(desenharPagina(PROPS_TRABALHO, '?abrir=v:c-2-4').includes('data-painel-lateral="v:c-2-4"'));

    for (const [nome, props] of [
        ['cliente', paraCliente(PROPS_TRABALHO)],
        ['sem escrita', { ...PROPS_TRABALHO, pode_editar: false, escrita: null }],
        ['sem escrita.revisao (servidor antigo)', SEM_REVISAO],
        ['sem a trilha de trabalho', PROPS_EQUIPE],
    ]) {
        const html = desenharPagina(props, '?abrir=v:s-2');
        assert.ok(!html.includes('role="dialog"') && !html.includes('Prévia do anúncio'), nome);
    }
    assert.ok(!desenharPagina(PROPS_TRABALHO, '?abrir=v:s-999').includes('role="dialog"'), 'oferta que não existe');
    global.window.location.search = '?abrir=v:s-2';
    const comoCliente = renderToStaticMarkup(h(M.pagina.TelaDaTrilha, { ...PROPS_TRABALHO, verComo: 'cliente', onVerComo: () => {} }));
    assert.ok(!comoCliente.includes('role="dialog"'), 'Ver como: Cliente');
    global.window.location.search = '';
});

test('o "Revisar" da linha em revisão abre `v:` (a linha continua sendo a da oferta); os outros, o painel `o:`', () => {
    const abriu = (oferta, props = {}) => {
        const chaves = [];
        const botao = M.linha.default({
            entrada: { tipo: 'o', chave: `o:${oferta.id}`, etapa: oferta.etapa, item: oferta }, visao: 'equipe', trabalho: true, podeEditar: true, comPrevia: true,
            onAbrir: (chave) => chaves.push(chave), ...props,
        });
        botao.props.onClick({ currentTarget: null });
        const pilula = botao.props.children.at(-1);

        return [botao.props['data-chave'], chaves[0], pilula.props['data-acao-da-linha']];
    };
    assert.deepEqual(abriu(OFERTA_S2), ['o:s-2', 'v:s-2', 'Revisar']);
    assert.deepEqual(abriu(OFERTA_S4), ['o:s-4', 'v:s-4', 'Revisar']);
    // Sem a prévia, sem escrita ou o cliente: o painel da oferta, como antes.
    assert.deepEqual(abriu(OFERTA_S2, { comPrevia: false }), ['o:s-2', 'o:s-2', 'Revisar']);
    assert.deepEqual(abriu(OFERTA_S2, { podeEditar: false }), ['o:s-2', 'o:s-2', 'Revisar']);
    assert.deepEqual(abriu(OFERTA_S2, { podeEditar: false, comPrevia: false, visao: 'cliente' }), ['o:s-2', 'o:s-2', 'Acompanhar']);
    // A publicada ("No ar") e a do robô abrem o painel da oferta: o [Ver prévia] da publicada fica lá.
    assert.deepEqual(abriu(OFERTA_S1), ['o:s-1', 'o:s-1', 'No ar']);
    assert.deepEqual(abriu(OFERTA_S3), ['o:s-3', 'o:s-3', 'Ver preparo']);

    // A página passa `comPrevia` às linhas: pelo `propsDoPainel` até o `LinhaDoItem` do "Ver tudo" e de cada etapa.
    const pagina = lerSemComentarios('resources/js/Pages/Trilha/Empresa.jsx');
    assert.match(pagina, /const propsDoPainel = \{[^}]*\bcomPrevia\b[^}]*\};/);
    const etapa = lerSemComentarios('resources/js/Components/Trilha/PainelDaEtapa.jsx');
    assert.equal(etapa.match(/comPrevia=\{comPrevia\}/g)?.length, 4, 'Linhas → LinhaDoItem, PainelGeral → Linhas, PainelDaEtapa → Linhas e (R12) PainelDaEtapa → PlanejamentoEmLote');
    // R12 (04/11/2026): o Planejamento em lote desenha as mesmas linhas — e passa `comPrevia` adiante.
    assert.match(lerSemComentarios('resources/js/Components/Trilha/PlanejamentoEmLote.jsx'), /<LinhaDoItem[^>]*comPrevia=\{comPrevia\}/);
    assert.match(etapa, /export function PainelGeral\(\{[^}]*comPrevia = false[^}]*\}\)/);
    assert.match(etapa, /export function PainelDaEtapa\(\{[^}]*comPrevia = false[^}]*\}\)/);
});

test('o painel da oferta (`o:`) ganha [Ver prévia]: principal na revisão, secundário na publicada; nunca antes, nem para os outros', () => {
    const daOferta = (props, chave) => painelLateral(desenharPagina(props, `?abrir=o%3A${chave}`), `o:${chave}`);
    const botaoPrevia = (html) => tag(html, 'data-acao="ver-previa"');
    const linkPublicador = (html) => tag(html, 'data-link="publicador"', 'a');

    const revisao = daOferta(PROPS_TRABALHO, 's-2');
    assert.ok(botaoPrevia(revisao).includes('bg-trilha-navy'), 'na revisão é a ação principal');
    assert.ok(texto(revisao).includes('Ver prévia'));
    // R10 (04/11/2026): nenhum link do Publicador no painel da oferta — nem com o endereço velho nas props do exemplo.
    assert.equal(linkPublicador(revisao), '', 'R10: sem o link do Publicador');

    const publicada = daOferta(PROPS_TRABALHO, 's-1');
    assert.ok(botaoPrevia(publicada) && !botaoPrevia(publicada).includes('bg-trilha-navy'), 'publicada: conferência, secundária');
    assert.equal(linkPublicador(publicada), '');
    assert.ok(!texto(publicada).includes('Abrir no Publicador'));

    assert.equal(botaoPrevia(daOferta(PROPS_TRABALHO, 's-3')), '', 'com o robô: ainda não');
    assert.equal(botaoPrevia(daOferta(PROPS_TRABALHO, 'c-2-4')), '', 'no planejamento: ainda não');
    for (const [nome, props] of [
        ['cliente', paraCliente(PROPS_TRABALHO)],
        ['sem escrita.revisao', SEM_REVISAO],
        ['sem escrita', { ...PROPS_TRABALHO, pode_editar: false, escrita: null }],
        ['sem a trilha de trabalho', PROPS_EQUIPE],
    ]) {
        const html = daOferta(props, 's-2');
        assert.equal(botaoPrevia(html), '', nome);
        assert.ok(!texto(html).includes('Ver prévia'), nome);
    }
    // Sem [Ver prévia], o Publicador também não volta (antes da 4a ele era o principal).
    assert.equal(linkPublicador(daOferta(SEM_REVISAO, 's-2')), '');
});

// ─── O `lazy`, o pacote do cliente e as cores ───────────────────────────────

test('o `lazy` da prévia: na página E no 2º caminho (`CadastroSobDemanda.jsx`), nunca import estático', () => {
    // Learnings §30: um `lazy` sem o 2º caminho tira `Pages/Trilha/Empresa.jsx` do manifest do Vite (o build sai 0 e a
    // rota cai com "Unable to locate file in Vite manifest").
    const pagina = lerSemComentarios('resources/js/Pages/Trilha/Empresa.jsx');
    assert.match(pagina, /const PreviaDoAnuncio = lazy\(\(\) => import\('@\/Components\/Trilha\/Revisao\/PreviaDoAnuncio'\)\);/);
    assert.equal(pagina.match(/^\s*import\b[^;]*Revisao\//m), null);
    assert.equal(pagina.match(/trilhaPrevia/), null, 'a página não importa a lib da prévia');
    const sobDemanda = lerSemComentarios('resources/js/Pages/Trilha/CadastroSobDemanda.jsx');
    assert.match(sobDemanda, /PreviaDoAnuncio: \(\) => import\('@\/Components\/Trilha\/Revisao\/PreviaDoAnuncio'\),/);
    assert.equal(sobDemanda.match(/^\s*import\b/m), null);
    // A gaveta usa o fallback próprio (o JS chega depois) e só abre no painel `previa`.
    assert.match(pagina, /painel\?\.painel === 'previa'[\s\S]*?<Suspense key=\{chaveDoConteudo\} fallback=\{<AbrindoAPrevia oferta=\{painel\.oferta\} \/>\}>/);
    // O fallback (no pacote do cliente) não cita o marketplace, o Portal nem o Publicador.
    const fallback = pagina.slice(pagina.indexOf('function AbrindoAPrevia'), pagina.indexOf('const buscaDaUrl'));
    assert.ok(fallback.includes('Abrindo a prévia…'));
    assert.equal(fallback.match(/Mercado\s+Livre|Portal|Publicador/i), null);
});

test('a pasta Revisao/: só cores trilha-*, toda foto com `loading="lazy"`, nenhum marketplace, Portal, "agente" nem Publicador (R10)', () => {
    const pasta = path.resolve(RAIZ, 'resources/js/Components/Trilha/Revisao');
    const arquivos = fs.readdirSync(pasta).map((f) => path.join('resources/js/Components/Trilha/Revisao', f));
    assert.ok(arquivos.length >= 1);
    for (const arquivo of arquivos) {
        const fonte = lerSemComentarios(arquivo);
        assert.equal(fonte.match(/Mercado\s+Livre|Portal|\/portal|\bagente\b/i), null, arquivo);
        // R10 (04/11/2026): o "Abrir no Publicador" que morava na gaveta saiu — nenhuma peça da pasta cita o Publicador
        // nem lê o endereço dele.
        assert.equal(fonte.match(/url_publicador/), null, arquivo);
        const doPublicador = [];
        assert.deepEqual([...fonte.matchAll(/[^\n]*Publicador[^\n]*/g)].map((m) => m[0].trim()), doPublicador, arquivo);
        assert.equal(fonte.match(/\btext-white\b|\bbg-white\/|\b(?:bg|text|border)-ecf-|\[#[0-9a-fA-F]{3,8}\]/), null, arquivo);
        const pub = [...fonte.matchAll(/[\w-]*\bpub-[\w-]*/g)].map((m) => m[0]).filter((c) => c !== 'font-pub-mono');
        assert.deepEqual(pub, [], `${arquivo}: classe pub-*`);
        // Nada do cartão do Publicador (classes `pub-*`, textos do marketplace): componente próprio (D43).
        assert.equal(fonte.match(/AnuncioNoMl|Components\/Publicador/), null, arquivo);
        for (const img of fonte.match(/<img\b[^>]*>/g) ?? []) assert.match(img, /loading="lazy"/, `${arquivo}: ${img}`);
    }
});
