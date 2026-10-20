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
    FILTROS_DA_VITRINE, faixaDePreco, familiasDaVitrine, filtrar, filtroInicial, fotoDoCartao, numeroDaFase, pilulasDasFases, reais, resumoDaSelecao, rotuloDaFase,
    tempoDesde, textoDosAnuncios,
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

// ─── As fases no mesmo cartão (20/10/2026): 1 Simples, 2 Combo, 3 Kit, 4 Combit ───

const FACE = { ...MESA, url_previa: '/editor?etapa=previa' };
const CADEIRA = { ...FACE, produto_id: 10, familias: [10], familia_id: 10, tipo: 'simples', fase_numero: 1, fase: 'Fase 1', grupo: 'prontos', nome: 'Cadeira' };
const COMBO2 = { ...FACE, produto_id: 11, familias: [10], tipo: 'combo', fase_numero: 2, quantidade: 2, fase: 'Fase 2 · Combo 2 un', grupo: 'prontos', nome: 'Combo 2 Cadeiras' };
const COMBO4 = { ...FACE, produto_id: 12, familias: [10], tipo: 'combo', fase_numero: 2, quantidade: 4, fase: 'Fase 2 · Combo 4 un', grupo: 'pendencia', nome: 'Combo 4 Cadeiras',
    chip: { texto: 'Azul: falta foto', tom: 'ambar' } };
const MESA_RD = { ...FACE, produto_id: 20, familias: [20], tipo: 'simples', fase_numero: 1, fase: 'Fase 1', grupo: 'publicados', selecionavel: false, nome: 'Mesa Redonda' };
const KIT = { ...FACE, produto_id: 30, familias: [20, 10], tipo: 'kit', fase_numero: 3, fase: 'Fase 3 · Kit', grupo: 'prontos', nome: 'Mesa + Cadeira', composicao: 'Mesa Redonda + Cadeira' };
const COMBIT = { ...FACE, produto_id: 31, familias: [20, 10], tipo: 'combit', fase_numero: 4, fase: 'Fase 4 · Combit', grupo: 'pendencia', nome: 'Mesa + 4 Cadeiras',
    composicao: 'Mesa Redonda + 4× Cadeira' };
const ESPERA3 = { chave: 'planejado-10-3', planejado: true, familia_id: 10, familias: [10], tipo: 'combo', fase_numero: 2, quantidade: 3, fase: 'Fase 2 · Combo 3 un',
    nome: 'Cadeira', composicao: '3 unidades do mesmo produto', foto: null,
    chip: { texto: 'Espera a Fase 1 ser publicada', tom: 'neutro', detalhe: 'O Combo de 3 está no Planejamento do Portal.' } };
const ESPERA_ORFA = { ...ESPERA3, chave: 'planejado-99-2', familia_id: 99, familias: [99] };
const verFamilias = (familias) => familias.map((f) => [f.id, f.fases.map((x) => x.produto_id ?? x.chave), f.inicial]);

test('famílias — todo combo é Fase 2 do produto; kit e combit vão no cartão de CADA componente; espera só em família da tela', () => {
    const lista = [COMBIT, POLTRONA, COMBO4, KIT, MESA_RD, COMBO2, CADEIRA];
    const todas = familiasDaVitrine(lista, 'todos', [ESPERA3, ESPERA_ORFA, null, 'x']);
    assert.deepEqual(verFamilias(todas), [
        [20, [20, 30, 31], 0],
        [10, [10, 11, 'planejado-10-3', 12, 30, 31], 0],
        [2, [2], 0],
    ]);
    // O kit é o MESMO objeto nos dois cartões: a seleção é pelo id, marcar num marca no outro.
    assert.equal(todas[0].fases[1], todas[1].fases[4]);
    // "Com pendência": a mesa entra pelo Combit e a cadeira pelo Combo 4 (a face de espera não conta).
    assert.deepEqual(familiasDaVitrine(lista, 'pendencia', [ESPERA3]).map((f) => [f.id, f.inicial]), [[20, 2], [10, 3], [2, 0]]);
    assert.deepEqual(familiasDaVitrine(lista, 'publicados', [ESPERA3]).map((f) => [f.id, f.inicial]), [[20, 0]]);
    assert.deepEqual(familiasDaVitrine(lista, 'todos', 'lixo').map((f) => f.id), [20, 10, 2]);
    assert.equal(rotuloDaFase(COMBO4), 'Fase 2');
    assert.equal(rotuloDaFase(COMBIT), 'Fase 4');
    assert.equal(rotuloDaFase({ fase: 'Fase 3 · Kit' }), 'Fase 3');
    assert.equal(rotuloDaFase({}), 'Fase 1');
    assert.equal(numeroDaFase({ fase_numero: 'x', fase: 'lixo' }), 1);
});

test('pílulas — uma por fase, com as faces de cada uma e se alguma tem pendência', () => {
    const faces = familiasDaVitrine([CADEIRA, COMBO2, COMBO4, KIT, COMBIT], 'todos', [ESPERA3])[0].fases;
    assert.deepEqual(pilulasDasFases(faces).map((p) => [p.rotulo, p.primeira, p.indices.length, p.pendencia]),
        [['Fase 1', 0, 1, false], ['Fase 2', 1, 3, true], ['Fase 3', 4, 1, false], ['Fase 4', 5, 1, true]]);
    assert.deepEqual(pilulasDasFases(null), []);
});

test('cartão da família — pílulas por fase, contagem na Fase 2, composição e a face de espera sem marcar nem prévia', () => {
    const faces = familiasDaVitrine([CADEIRA, COMBO2, COMBO4, KIT, COMBIT], 'todos', [ESPERA3])[0].fases;
    const html = renderToStaticMarkup(React.createElement(CartaoDaVitrine, { fases: faces, inicial: 3, selecao: new Set([11]) }));
    assert.match(html, /data-fases="6"/);
    assert.equal(contar(html, 'data-vitrine-pilula-fase='), 4);
    assert.match(html, /aria-current="true"[^>]*data-vitrine-pilula-fase="2"/, 'abre no Combo 4, da Fase 2');
    assert.match(html, /data-vitrine-pilula-quantas="true">3</);
    assert.match(html, />Fase 4</);
    assert.match(html, />Fase 2 · Combo 4 un</);
    assert.match(html, /translateX\(calc\(-300% \+ 0px\)\)/, 'abre na 4ª face');
    assert.equal(contar(html, 'data-vitrine-face='), 6);
    assert.equal(contar(html, 'data-vitrine-planejada="true"'), 1);
    assert.match(html, /Espera a Fase 1 ser publicada/);
    assert.match(html, /Aceito no Planejamento/);
    assert.match(html, /Mesa Redonda \+ 4× Cadeira/);
    assert.equal(contar(html, 'data-vitrine-previa'), 5, 'a face de espera não abre prévia');
    assert.equal(contar(html, 'data-vitrine-marcar'), 5, 'nem se marca');
    assert.match(html, /aria-label="marcada"/, 'o Combo 2 marcado aparece na pílula da Fase 2');
    assert.match(html, /border-pub-accent shadow-pub-cta/, 'família com fase marcada ganha a borda');
    // Uma face só (20/10/2026): a linha das fases existe igual, só com a pílula da Fase 1 — a foto de todos os
    // cartões começa na mesma altura. Seta não há: não tem para onde passar.
    const so = renderToStaticMarkup(React.createElement(CartaoDaVitrine, { fases: [CADEIRA] }));
    assert.equal(contar(so, 'data-vitrine-pilula-fase='), 1);
    assert.match(so, /aria-current="true"[^>]*data-vitrine-pilula-fase="1"/);
    assert.match(so, />Fase 1</);
    assert.doesNotMatch(so, /data-vitrine-anterior|data-vitrine-proxima/);
    // Um cartão simples (sem `fases`) também ganha a linha: é o caminho do produto sem nenhuma outra fase.
    assert.equal(contar(renderToStaticMarkup(React.createElement(CartaoDaVitrine, { cartao: MESA })), 'data-vitrine-pilula-fase='), 1);
    // A linha tem altura fixa e não quebra: num cartão estreito as pílulas rolam de lado.
    assert.match(html, /data-vitrine-fases/);
    assert.match(html, /class="flex h-9 shrink-0 flex-nowrap[^"]*overflow-x-auto/);
    // Face de espera estragada não quebra o cartão.
    for (const estragada of [{ planejado: true }, { ...ESPERA3, chip: null, nome: null, composicao: { x: 1 }, fase_numero: 'x' }]) {
        let h = '';
        assert.doesNotThrow(() => { h = renderToStaticMarkup(React.createElement(CartaoDaVitrine, { fases: [CADEIRA, estragada] })); });
        assert.doesNotMatch(h, /undefined|NaN|\[object Object\]/);
    }
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
    assert.match(f, /familiasDaVitrine\(lista, filtro, planejados\)/);
    assert.equal((f.match(/only: \['cartoes', 'planejados', 'contagens'/g) ?? []).length, 2, 'o recarregar traz as faces de espera junto');
});
