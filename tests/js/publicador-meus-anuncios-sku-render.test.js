import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// ═══════════════════════════════════════════════════════════════════════════
// Quick 261010-rie — render REAL (esbuild + react-dom/server) dos dois
// componentes novos de `MeusAnuncios.jsx`: `CelulaSku` (o SKU na linha da
// tabela) e `VazioDaListagem` (o vazio da busca que explica em vez de dizer
// "não achei").
//
// POR QUE EXISTE: o `skus` da linha e o `busca` do vazio vêm do SERVIDOR e
// podem chegar em formato inesperado (objeto, nulo, ausente). Gate de fonte
// não pega isso — foi um valor em formato inesperado que deixou a tela PRETA
// em 07/10. Aqui o módulo é compilado com os imports reais e desenhado de
// verdade: um erro no caminho do render estoura o teste.
//
// ⚠️ TODOS os bundles são montados ANTES do primeiro `test()`: o `after()` do
// `node --test` já matou um arquivo inteiro sem nenhum teste falhar.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

global.route = (nome, params) => '/' + nome + (params && Object.keys(params).length ? '?' + new URLSearchParams(params).toString() : '');

const STUB_INERTIA = path.join(__dirname, `.sku-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
const nada = () => () => {};
export const router = { get: () => {}, post: () => {}, put: () => {}, patch: () => {}, delete: () => {}, reload: () => {}, visit: () => {}, on: nada };
export function usePage() { return { props: {} }; }
`, 'utf8');

const STUB_LAYOUT = path.join(__dirname, `.sku-layout-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_LAYOUT, `
import React from 'react';
export default function AppLayout({ children, title }) {
    return React.createElement('main', { 'data-titulo': title }, children);
}
`, 'utf8');

after(() => {
    for (const f of [STUB_INERTIA, STUB_LAYOUT]) fs.rmSync(f, { force: true });
});

async function montar(relativo, rotulo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, relativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: {
            '@/Layouts/AppLayout': STUB_LAYOUT,
            '@inertiajs/react': STUB_INERTIA,
            '@': path.resolve(RAIZ, 'resources/js'),
        },
        // Pacotes de node_modules ficam de fora (o Node os carrega): CJS dentro
        // de bundle ESM quebra com "Dynamic require of react".
        external: ['react', 'react-dom', 'react-dom/server', 'react/jsx-runtime', 'lucide-react', 'axios', 'recharts',
            '@radix-ui/*', '@headlessui/*', '@floating-ui/*', 'react-remove-scroll', 'react-remove-scroll-bar', 'use-sidecar',
            'aria-hidden', 'clsx', 'tailwind-merge', 'class-variance-authority', 'date-fns', 'date-fns/*'],
    });

    const saida = path.join(__dirname, `.${rotulo}-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(saida, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(saida).href);
    } finally {
        fs.rmSync(saida, { force: true });
    }
}

// ─── Bundle montado ANTES de qualquer test() ───────────────────────────────
const MOD = await montar('resources/js/Pages/Mlb/MeusAnuncios.jsx', 'meus-anuncios-sku');

const html = (el) => renderToStaticMarkup(el);
const texto = (s) => s.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/&#x27;/g, "'").replace(/\s+/g, ' ');

// ═══ CelulaSku ═════════════════════════════════════════════════════════════

test('CelulaSku — um SKU aparece como texto', () => {
    const { CelulaSku } = MOD;
    const saida = html(React.createElement(CelulaSku, { skus: ['ABC-1'] }));

    assert.match(texto(saida), /ABC-1/);
    assert.doesNotMatch(saida, /\[object Object\]/);
});

test('CelulaSku — vários SKUs: mostra o primeiro E diz quantos são (nunca passa variação por "o" SKU)', () => {
    const { CelulaSku } = MOD;
    const saida = html(React.createElement(CelulaSku, { skus: ['V1', 'V2', 'V3'] }));
    const corpo = texto(saida);

    assert.match(corpo, /V1/, 'o primeiro aparece');
    assert.match(corpo, /\+2/, 'e a tela diz que há mais 2 — 3 no total');
    assert.doesNotMatch(saida, /\[object Object\]/);
});

test('CelulaSku — lista vazia não desenha nada (sem rótulo "SKU" órfão)', () => {
    const { CelulaSku } = MOD;

    assert.strictEqual(html(React.createElement(CelulaSku, { skus: [] })), '');
});

test('CelulaSku — skus nulo, ausente e OBJETO (formato inesperado do servidor): não lança, não imprime [object Object]', () => {
    const { CelulaSku } = MOD;

    for (const valor of [null, undefined, { foo: 'bar' }, 'ABC', 42]) {
        let saida;
        assert.doesNotThrow(() => {
            saida = html(React.createElement(CelulaSku, valor === undefined ? {} : { skus: valor }));
        }, `skus = ${JSON.stringify(valor)} derrubou o render`);
        assert.doesNotMatch(saida, /\[object Object\]/);
        assert.doesNotMatch(saida, /foo/);
    }
});

test('CelulaSku — lista suja (número, nulo, espaços, objeto): desenha só o que é texto útil', () => {
    const { CelulaSku } = MOD;
    const saida = html(React.createElement(CelulaSku, { skus: ['A', 123, null, '  ', { x: 1 }] }));
    const corpo = texto(saida);

    assert.match(corpo, /\bA\b/);
    assert.doesNotMatch(saida, /\[object Object\]/);
    assert.doesNotMatch(corpo, /\+[1-9]/, 'só um SKU é texto útil — nada de "+4"');
});

// ═══ VazioDaListagem ═══════════════════════════════════════════════════════

test('VazioDaListagem — sem busca: o texto de hoje, LITERAL', () => {
    const { VazioDaListagem } = MOD;
    const corpo = texto(html(React.createElement(VazioDaListagem, { busca: '', skuNaoColetado: false, acao: null })));

    assert.match(corpo, /Esta empresa não tem anúncios ativos no Mercado Livre\./);
});

test('VazioDaListagem — com busca: diz o que foi procurado e que a busca cobre os três campos, sem afirmar que o anúncio não existe', () => {
    const { VazioDaListagem } = MOD;
    const corpo = texto(html(React.createElement(VazioDaListagem, { busca: 'ABC-1', skuNaoColetado: false, acao: null })));

    assert.match(corpo, /ABC-1/, 'o termo procurado aparece');
    assert.match(corpo, /título/i);
    assert.match(corpo, /MLB/);
    assert.match(corpo, /SKU/);
    assert.doesNotMatch(corpo, /Esta empresa não tem anúncios ativos/, 'o texto sem busca não pode vazar para o caminho com busca');
});

test('VazioDaListagem — busca + skuNaoColetado: avisa que o SKU ainda não foi coletado e manda Atualizar agora (RIE-04)', () => {
    const { VazioDaListagem } = MOD;
    const corpo = texto(html(React.createElement(VazioDaListagem, { busca: 'ABC-1', skuNaoColetado: true, acao: null })));

    assert.match(corpo, /ainda não foi coletado/i);
    assert.match(corpo, /Atualizar agora/);
    assert.match(corpo, /mesmo que o anúncio exista/i, 'é esta a frase que impede a tela de mentir por omissão');
});

test('VazioDaListagem — busca em formato inesperado (objeto/número/array): não lança, não imprime [object Object]', () => {
    const { VazioDaListagem } = MOD;

    for (const valor of [{ foo: 'bar' }, 42, ['a', 'b'], null, undefined]) {
        let saida;
        assert.doesNotThrow(() => {
            saida = html(React.createElement(VazioDaListagem, { busca: valor, skuNaoColetado: false, acao: null }));
        }, `busca = ${JSON.stringify(valor)} derrubou o render`);
        assert.doesNotMatch(saida, /\[object Object\]/);
        assert.doesNotMatch(saida, /foo/);
    }
});

test('VazioDaListagem — o nó de ação recebido é desenhado (a página passa o BotaoAtualizar que já existe)', () => {
    const { VazioDaListagem } = MOD;
    const acao = React.createElement('button', { type: 'button' }, 'Atualizar agora');
    const saida = html(React.createElement(VazioDaListagem, { busca: 'ABC-1', skuNaoColetado: true, acao }));

    assert.match(saida, /<button[^>]*type="button"/);
});
