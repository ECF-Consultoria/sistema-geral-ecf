import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';
import {
    FILTROS_DA_VITRINE, faixaDePreco, familiasDaVitrine, filtrar, filtroInicial, fotoDoCartao, reais, resumoDaSelecao, rotuloDaFase, tempoDesde, textoDosAnuncios,
} from '../../resources/js/Components/Mlb/Publicador/vitrine/vitrine.js';

// ═══════════════════════════════════════════════════════════════════════════
// Vitrine de produtos (20/10/2026, protótipo atrás de `publicador.vitrine`).
// As regras da tela (`vitrine.js`) e o render REAL do cartão (esbuild +
// react-dom/server): cheio, sem foto e com campo estragado do servidor.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

const STUB_INERTIA = path.join(__dirname, `.vitrine-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
`, 'utf8');
after(() => fs.rmSync(STUB_INERTIA, { force: true }));

async function montarModulo(caminhoRelativo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, caminhoRelativo)],
        bundle: true, format: 'esm', platform: 'node', jsx: 'automatic', write: false, logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react'],
    });
    const outfile = path.join(__dirname, `.vitrine-render-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    after(() => fs.rmSync(outfile, { force: true }));

    return import(pathToFileURL(outfile).href);
}

const { default: CartaoDaVitrine } = await montarModulo('resources/js/Components/Mlb/Publicador/vitrine/CartaoDaVitrine.jsx');

const POLTRONA = {
    produto_id: 2, nome: 'Poltrona Decorativa Veludo', sku: 'POL-VEL-02', do_portal: true, fase: 'Fase 1', grupo: 'pendencia',
    chip: { texto: 'Rosa: falta foto', tom: 'ambar', detalhe: 'Falta 1 item no total' },
    cores: [
        { chave: 'COLOR=txt:verde', rotulo: 'Verde', foto: '/fotos/verde.png', pendencias: [] },
        { chave: 'COLOR=txt:rosa', rotulo: 'Rosa', foto: null, pendencias: ['falta foto'] },
    ],
    foto: '/fotos/verde.png',
    precos: { gold_special: { min: 353.96, max: 353.96 }, gold_pro: { min: 381.4, max: 399.9 } },
    anuncios: 4, atualizado_em: new Date(Date.now() - 8 * 60000).toISOString(), url_previa: '/editor/2?etapa=previa', selecionavel: true,
};
const MESA = { produto_id: 3, nome: 'Mesa Lateral', sku: 'MESA-RD-03', grupo: 'prontos', chip: { texto: 'Pronto', tom: 'verde' }, cores: [], foto: null, precos: {}, anuncios: 2, selecionavel: true };
const BANQUETA = { produto_id: 5, nome: 'Banqueta', sku: 'BANQ', grupo: 'publicados', chip: { texto: 'Publicado', tom: 'verde' }, cores: [], anuncios: 2, selecionavel: false };
const render = (props) => renderToStaticMarkup(React.createElement(CartaoDaVitrine, { cartao: POLTRONA, ...props }));
const contar = (html, agulha) => html.split(agulha).length - 1;

// ─── vitrine.js ───

test('filtros — prontos primeiro quando há; todos, quando não; chave desconhecida não vale', () => {
    assert.deepEqual(FILTROS_DA_VITRINE.map((f) => f.chave), ['prontos', 'pendencia', 'publicados', 'todos']);
    assert.equal(filtroInicial({ prontos: 2 }), 'prontos');
    assert.equal(filtroInicial({ prontos: 0 }), 'todos');
    assert.equal(filtroInicial(null), 'todos');
    assert.equal(filtroInicial({ prontos: 3 }, 'publicados'), 'publicados');
    assert.equal(filtroInicial({ prontos: 3 }, 'inventado'), 'prontos');
    const lista = [POLTRONA, MESA, BANQUETA, null, { nome: 'sem id' }];
    assert.deepEqual(filtrar(lista, 'prontos').map((c) => c.produto_id), [3]);
    assert.deepEqual(filtrar(lista, 'pendencia').map((c) => c.produto_id), [2]);
    assert.deepEqual(filtrar(lista, 'todos').map((c) => c.produto_id), [2, 3, 5]);
    assert.deepEqual(filtrar('x', 'todos'), []);
});

test('preço — um valor, ou mín – máx quando as cores diferem; sem preço é nulo', () => {
    assert.equal(reais(1597.12), 'R$ 1.597,12');
    assert.equal(faixaDePreco({ min: 353.96, max: 353.96 }), 'R$ 353,96');
    assert.equal(faixaDePreco({ min: 381.4, max: 399.9 }), 'R$ 381,40 – R$ 399,90');
    assert.equal(faixaDePreco({ min: null, max: null }), null);
    assert.equal(faixaDePreco(undefined), null);
});

test('tempo, anúncios e a seleção (só o que dá para marcar conta)', () => {
    const agora = Date.parse('2026-10-20T12:00:00Z');
    assert.equal(tempoDesde('2026-10-20T11:52:00Z', agora), 'há 8 min');
    assert.equal(tempoDesde('2026-10-20T09:00:00Z', agora), 'há 3 h');
    assert.equal(tempoDesde('2026-10-19T09:00:00Z', agora), 'ontem');
    assert.equal(tempoDesde('2026-10-15T09:00:00Z', agora), 'há 5 dias');
    assert.equal(tempoDesde(null, agora), null);
    assert.equal(textoDosAnuncios(POLTRONA), '2 cores · 4 anúncios');
    assert.equal(textoDosAnuncios(MESA), '2 anúncios');
    assert.equal(textoDosAnuncios({ anuncios: 1 }), '1 anúncio');
    assert.deepEqual(resumoDaSelecao([POLTRONA, MESA, BANQUETA], new Set([2, 3, 5])), { produtos: 2, anuncios: 6, ids: [2, 3] });
});

test('fotoDoCartao — a cor sob o mouse troca a foto; cor sem foto mantém a capa', () => {
    assert.equal(fotoDoCartao(POLTRONA, null).url, '/fotos/verde.png');
    assert.equal(fotoDoCartao(POLTRONA, null).emFoco, null);
    assert.equal(fotoDoCartao({ ...POLTRONA, cores: [{ chave: 'a', rotulo: 'Azul', foto: '/fotos/azul.png' }] }, 'a').url, '/fotos/azul.png');
    const rosa = fotoDoCartao(POLTRONA, 'COLOR=txt:rosa');
    assert.equal(rosa.emFoco.rotulo, 'Rosa');
    assert.equal(rosa.url, '/fotos/verde.png', 'a Rosa não tem foto própria: fica a capa');
    assert.equal(fotoDoCartao(null, 'x').url, null);
});

// ─── CartaoDaVitrine.jsx ───

test('cartão — foto, bolinhas por cor (a pendente marcada), chip com a cor, preços e Ver prévia', () => {
    const html = render({});
    assert.match(html, /data-vitrine-cartao="2"/);
    assert.match(html, /<img src="\/fotos\/verde.png" alt="Poltrona Decorativa Veludo"/);
    assert.equal(contar(html, 'data-vitrine-cor='), 2);
    assert.match(html, /title="Rosa: falta foto"/);
    assert.match(html, /data-vitrine-anuncios="true">2 cores · 4 anúncios</);
    assert.match(html, /data-vitrine-chip="ambar">Rosa: falta foto</);
    assert.match(html, /title="Falta 1 item no total"/);
    assert.match(html, /data-vitrine-preco="gold_special">R\$ 353,96</);
    assert.match(html, /data-vitrine-preco="gold_pro">R\$ 381,40 – R\$ 399,90</);
    assert.match(html, /href="\/editor\/2\?etapa=previa"/);
    assert.match(html, /Atualizado há 8 min/);
    assert.match(html, /data-vitrine-marcar/);
    assert.match(html, /> Portal<\/span>/);
    assert.doesNotMatch(html, /undefined|NaN|\[object Object\]/);
});

test('cartão — marcado ganha a borda amarela; publicado não tem caixa de marcar; sem foto e sem preço não quebram', () => {
    assert.match(render({ marcado: true }), /border-pub-accent shadow-pub-cta/);
    assert.match(render({ marcado: true }), /aria-pressed="true"/);
    const mesa = renderToStaticMarkup(React.createElement(CartaoDaVitrine, { cartao: MESA }));
    assert.match(mesa, /Sem foto/);
    assert.equal(contar(mesa, 'data-vitrine-cor='), 0, 'uma cor só não tem bolinha');
    assert.match(mesa, /sem preço/);
    const banqueta = renderToStaticMarkup(React.createElement(CartaoDaVitrine, { cartao: BANQUETA }));
    assert.doesNotMatch(banqueta, /data-vitrine-marcar/);
    for (const estragado of [{ ...POLTRONA, cores: 'x', precos: null, chip: null }, { ...POLTRONA, cores: [null, { rotulo: 'sem chave' }] }]) {
        let html = '';
        assert.doesNotThrow(() => { html = renderToStaticMarkup(React.createElement(CartaoDaVitrine, { cartao: estragado })); });
        assert.doesNotMatch(html, /undefined|NaN|\[object Object\]/);
    }
});

// ─── Fase 2 e 3 no mesmo cartão (20/10/2026) ───

const BASE = { ...MESA, produto_id: 10, familia_id: 10, fase_numero: 1, fase: 'Fase 1', grupo: 'prontos', nome: 'Cadeira' };
const KIT2 = { ...MESA, produto_id: 11, familia_id: 10, fase_numero: 2, fase: 'Fase 2 · Kit 2', grupo: 'prontos', nome: 'Kit 2 Cadeiras' };
const KIT3 = { ...MESA, produto_id: 12, familia_id: 10, fase_numero: 3, fase: 'Fase 3 · Kit 3', grupo: 'pendencia', nome: 'Kit 3 Cadeiras',
    chip: { texto: 'Azul: falta foto', tom: 'ambar' } };

test('famílias — o kit vai no cartão do produto base, em ordem de fase; o filtro abre na fase que entrou', () => {
    const lista = [KIT3, POLTRONA, KIT2, BASE];
    const todas = familiasDaVitrine(lista, 'todos');
    assert.deepEqual(todas.map((f) => [f.id, f.fases.map((x) => x.produto_id), f.inicial]), [[10, [10, 11, 12], 0], [2, [2], 0]]);
    // "Com pendência": a cadeira entra pelo Kit 3 e o cartão abre NELE.
    const pendencia = familiasDaVitrine(lista, 'pendencia');
    assert.deepEqual(pendencia.map((f) => [f.id, f.inicial]), [[10, 2], [2, 0]]);
    assert.deepEqual(familiasDaVitrine(lista, 'publicados'), []);
    assert.equal(rotuloDaFase(KIT2), 'Kit 2');
    assert.equal(rotuloDaFase(BASE), 'Fase 1');
    assert.equal(rotuloDaFase({}), 'Fase 1');
});

test('cartão da família — pílulas das fases, setas e o trilho na fase inicial; cada fase com a própria prévia', () => {
    const html = renderToStaticMarkup(React.createElement(CartaoDaVitrine, { fases: [BASE, KIT2, KIT3], inicial: 2, selecao: new Set([11]) }));
    assert.match(html, /data-fases="3"/);
    assert.equal(contar(html, 'data-vitrine-pilula-fase='), 3);
    assert.match(html, /aria-current="true"[^>]*data-vitrine-pilula-fase="2"/);
    assert.match(html, />Kit 2</);
    assert.match(html, /translateX\(calc\(-200% \+ 0px\)\)/, 'abre no Kit 3');
    assert.equal(contar(html, 'data-vitrine-face='), 3);
    assert.match(html, /data-vitrine-anterior/);
    assert.match(html, /aria-label="marcada"/, 'o Kit 2 marcado aparece na pílula');
    assert.match(html, /border-pub-accent shadow-pub-cta/, 'família com fase marcada ganha a borda');
    // Um produto sem kit não tem pílula nem seta.
    const so = renderToStaticMarkup(React.createElement(CartaoDaVitrine, { fases: [BASE] }));
    assert.doesNotMatch(so, /data-vitrine-pilula-fase|data-vitrine-anterior|data-vitrine-proxima/);
});

// ─── Fonte da página ───

test('página — publica a seleção pela fila em lote, um só botão amarelo no corpo, sem faixa nem busca repetidas', () => {
    const f = lerSemComentarios('resources/js/Pages/Mlb/Publicador/ProdutosVitrine.jsx');
    assert.match(f, /<Link href=\{destinoDoLote\(empresa\.chave, selecionados\.ids\)\}/);
    assert.match(f, /data-acao="publicar-selecionados"/);
    assert.match(f, /data-acao="marcar-prontos"/);
    assert.equal((f.match(/bg-pub-accent px-5/g) ?? []).length, 1, 'o botão amarelo é só o de publicar');
    assert.doesNotMatch(f, /AvisoContaTravada|Confortável|Compacto|Buscar SKU/);
    assert.match(f, /<PublicadorHeader/);
});
