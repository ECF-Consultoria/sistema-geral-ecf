import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { estoqueDoProduto, estoqueInformado } from '../../resources/js/lib/estoqueDoProduto.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// "Estoque do produto" no topo da ficha do Portal (09/10/2026, decisão do usuário).
//
// POR QUE EXISTE: o cliente só tinha o estoque dentro do cartão de cada cor e
// deixou as 19 variações da #459 sem estoque. O topo mostra o estoque do produto:
// com UMA variação o campo é o dela (mesmo dado, sem coluna nova); com várias, a
// soma, só leitura. Vazio ("não informado") continua diferente de 0.
// ═══════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const DIR = 'resources/js/Components/Portal/Estrutura/Produtos';

async function montar(relativo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, relativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js') },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@inertiajs/react', '@radix-ui/*'],
    });
    const outfile = path.join(__dirname, `.estoque-produto-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

/** Acha, na árvore que o componente devolve, o 1º elemento que passa no teste. */
function achar(no, teste) {
    if (no === null || no === undefined || typeof no !== 'object') return null;
    if (Array.isArray(no)) {
        for (const filho of no) {
            const r = achar(filho, teste);
            if (r) return r;
        }

        return null;
    }
    if (teste(no)) return no;

    return achar(no.props?.children, teste);
}

const v = (k, estoque) => ({ _k: k, estoque });

test('estoqueInformado: 0 conta, vazio e texto que o servidor recusaria não contam', () => {
    assert.equal(estoqueInformado('0'), 0);
    assert.equal(estoqueInformado(' 12 '), 12);
    assert.equal(estoqueInformado(7), 7);
    for (const x of ['', '   ', null, undefined, 'abc', '-3', '1.000', '2,5']) {
        assert.equal(estoqueInformado(x), null, String(x));
    }
});

test('várias variações: soma só leitura; todas informadas não falam em parcial', () => {
    const r = estoqueDoProduto([v('a', '3'), v('b', '0'), v('c', '10')]);
    assert.equal(r.editavel, false);
    assert.equal(r.chave, null);
    assert.equal(r.valor, '13');
    assert.equal(r.informadas, 3);
    assert.equal(r.parcial, null);
});

test('várias variações: só algumas informaram → soma as informadas e diz N de M', () => {
    const r = estoqueDoProduto([v('a', '5'), v('b', ''), v('c', '2'), v('d', null)]);
    assert.equal(r.valor, '7');
    assert.equal(r.parcial, '2 de 4 variações informaram');
});

test('várias variações: nenhuma informou → vazio (não é 0); todas com 0 → "0"', () => {
    const vazio = estoqueDoProduto([v('a', ''), v('b', null)]);
    assert.equal(vazio.valor, '');
    assert.equal(vazio.informadas, 0);
    assert.equal(vazio.parcial, null);

    const zero = estoqueDoProduto([v('a', '0'), v('b', '0')]);
    assert.equal(zero.valor, '0', '0 é "sem estoque", diferente de vazio');
});

test('uma variação: o campo é o estoque dela (editável, mesma chave); 0 fica "0" e vazio fica vazio', () => {
    assert.deepEqual(estoqueDoProduto([v('v9', '4')]), { editavel: true, chave: 'v9', valor: '4', total: 1, informadas: 1, parcial: null });
    assert.equal(estoqueDoProduto([v('v9', '0')]).valor, '0');
    assert.equal(estoqueDoProduto([v('v9', '')]).valor, '');
    assert.equal(estoqueDoProduto([v('v9', null)]).valor, '');
});

test('troca 1 → 2 variações: o valor fica na 1ª e o topo vira a soma', () => {
    const uma = [v('v1', '8')];
    assert.equal(estoqueDoProduto(uma).editavel, true);

    // "Nova variação" nasce com estoque vazio (gate do hook em estrutura-produtos-estoque.test.js).
    const duas = [...uma, v('m1', '')];
    const r = estoqueDoProduto(duas);
    assert.equal(r.editavel, false);
    assert.equal(r.valor, '8', 'a soma é o que a 1ª já tinha');
    assert.equal(r.parcial, '1 de 2 variações informaram');
    assert.equal(duas[0].estoque, '8', 'a 1ª variação continua com o valor');
});

// Demo 23/10 (N19): o cliente via dois campos de estoque no produto novo ("Estoque do produto" e o
// "Estoque (un.)" da variação), que eram o MESMO dado. Com uma variação o bloco do topo some e fica só o
// campo do cartão da variação; antes este teste cobrava o campo editável aqui.
test('componente: uma variação → nada no topo (o estoque fica só no cartão da variação)', async () => {
    const { default: EstoqueDoProduto } = await montar(`${DIR}/EstoqueDoProduto.jsx`);
    const ficha = { vars: [v('v5', '3')], explicacoes: { estoque_produto: 'Quantas unidades.' }, alterar: () => assert.fail('o topo não grava') };

    assert.equal(renderToStaticMarkup(React.createElement(EstoqueDoProduto, { ficha })), '');
    assert.equal(renderToStaticMarkup(React.createElement(EstoqueDoProduto, { ficha: { ...ficha, vars: [] } })), '');
});

test('componente: várias variações → soma só leitura, sem campo, com a ajuda e o parcial', async () => {
    const { default: EstoqueDoProduto } = await montar(`${DIR}/EstoqueDoProduto.jsx`);
    const ficha = { vars: [v('a', '4'), v('b', ''), v('c', '6')], explicacoes: {}, alterar: () => assert.fail('soma não se edita') };

    const html = renderToStaticMarkup(React.createElement(EstoqueDoProduto, { ficha }));
    assert.match(html, /data-estoque-produto="soma"/);
    assert.doesNotMatch(html, /<input/);
    assert.match(html, /<output[^>]*>10<\/output>/);
    assert.ok(html.includes('soma das variações · 2 de 3 variações informaram'));

    const vazio = renderToStaticMarkup(React.createElement(EstoqueDoProduto, { ficha: { ...ficha, vars: [v('a', ''), v('b', '')] } }));
    assert.match(vazio, /<output[^>]*>—<\/output>/);
    assert.ok(vazio.includes('soma das variações<'), 'sem parcial quando nenhuma informou');
});

// 29/10/2026: a soma saiu dos dados gerais (com cara de campo, parecia editável) para o pé das variações.
test('gate: a ficha usa o componente no pé das variações e o texto é neutro (sigilo)', () => {
    const dados = lerSemComentarios(`${DIR}/FichaDadosGerais.jsx`);
    assert.doesNotMatch(dados, /EstoqueDoProduto/, 'fora dos dados gerais');
    assert.match(lerSemComentarios(`${DIR}/CoresDoProduto.jsx`), /<EstoqueDoProduto ficha=\{ficha\}/);

    const componente = lerSemComentarios(`${DIR}/EstoqueDoProduto.jsx`);
    const lib = lerSemComentarios('resources/js/lib/estoqueDoProduto.js');
    for (const fonte of [componente, lib]) {
        assert.doesNotMatch(fonte, /mercado|an[uú]ncio|public(ar|a[cç][aã]o|ador)|\bMLB?\b/i);
    }
    assert.match(componente, /explicacoes\?\.estoque_produto/);
    // N19: o topo não grava mais nada; uma variação = sem bloco.
    assert.doesNotMatch(componente, /ficha\.alterar\(/);
    assert.match(componente, /if \(estoque\.total <= 1\) return null;/);
});
