import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Explicação ao passar o mouse em TODO campo do editor do Publicador (pedido do
// usuário, 08/10/2026: "ao colocar o cursor do mouse em cima pelo menos explicar
// o que é — isso para tudo, não apenas para siglas"). Render REAL do `Campo`
// (esbuild + react-dom/server) e gates de fonte de quem passa a explicação.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const BASE = 'resources/js/Components/Publicador';

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
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios'],
    });
    const outfile = path.join(__dirname, `.explicacao-render-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

test('Campo com explicação — ícone ao lado do rótulo, balão ligado por aria-describedby, fora do <label>', async () => {
    const { Campo } = await montar(`${BASE}/Mesa/comum.jsx`);
    const texto = 'Código da peça no catálogo do fabricante (part number). Em móveis normalmente não existe — pode deixar vazio.';
    const html = renderToStaticMarkup(React.createElement(Campo, { rotulo: 'MPN', htmlFor: 'mpn', explicacao: texto }, React.createElement('input', { id: 'mpn' })));

    assert.match(html, /data-explicacao="true"/);
    assert.ok(html.includes(texto), 'o texto vem no balão');
    assert.match(html, /aria-label="O que é MPN\?"/);
    const descrito = html.match(/aria-describedby="([^"]+)"/)?.[1];
    const balao = html.match(/role="tooltip" id="([^"]+)"/)?.[1];
    assert.ok(descrito && descrito === balao, 'o botão é descrito pelo balão (leitor de tela)');
    // O botão não mora dentro do rótulo (clicar no ícone não pode focar o campo).
    const rotulo = html.match(/<label[^>]*>([\s\S]*?)<\/label>/)?.[1] ?? '';
    assert.doesNotMatch(rotulo, /<button/);
    assert.match(html, /<label for="mpn" class="text-\[13px\] font-bold text-white\/90">MPN<\/label>/);
});

test('Campo sem explicação (ou com texto vazio) — nenhum ícone', async () => {
    const { Campo } = await montar(`${BASE}/Mesa/comum.jsx`);
    for (const explicacao of [null, undefined, '', '   ']) {
        const html = renderToStaticMarkup(React.createElement(Campo, { rotulo: 'Estoque', htmlFor: 'e', explicacao }, React.createElement('input', { id: 'e' })));
        assert.doesNotMatch(html, /data-explicacao/, String(explicacao));
    }
});

test('Campo com rótulo que não é texto — `nome` dá o nome ao leitor de tela', async () => {
    const { Campo } = await montar(`${BASE}/Mesa/comum.jsx`);
    const html = renderToStaticMarkup(React.createElement(Campo, { rotulo: React.createElement('span', null, 'AGID'), nome: 'AGID', htmlFor: 'a', explicacao: 'Outro código.' }, null));
    assert.match(html, /aria-label="O que é AGID\?"/);
    const semNome = renderToStaticMarkup(React.createElement(Campo, { rotulo: React.createElement('span', null, 'AGID'), htmlFor: 'a', explicacao: 'Outro código.' }, null));
    assert.match(semNome, /aria-label="O que é este campo\?"/);
});

test('Explicacao.jsx — Info 14px, abre no hover E no foco do teclado, só 13px/400, sem title nativo', () => {
    const f = lerSemComentarios('resources/js/Components/Explicacao.jsx');
    assert.match(f, /import \{ Info \} from 'lucide-react'/);
    assert.match(f, /<Info size=\{14\} aria-hidden="true" \/>/);
    assert.match(f, /group-hover\/explicacao:visible/);
    assert.match(f, /group-focus-within\/explicacao:visible/);
    assert.match(f, /focus-visible:ring-2 focus-visible:ring-ecf-yellow/);
    assert.doesNotMatch(f, /\btitle=/, 'o title nativo somaria um segundo balão');
    assert.doesNotMatch(f, /\buppercase\b|font-(medium|semibold)|text-\[(?!13px\])[0-9.]+px\]/);
});

test('Todo campo de atributo do editor passa a explicação (ficha, mais características, variação, embalagem, eixos, nova variação)', () => {
    const c = (arquivo) => lerSemComentarios(`${BASE}/${arquivo}`);
    // Ficha técnica (características principais e "mais características" usam o mesmo campo).
    assert.match(c('Mesa/EtapaDetalhes.jsx'), /explicacao=\{a\.explicacao\} nome=\{rotulo \?\? a\.nome\}/);
    // Cartão da variação: atributos extras (AGID, MPN…), Estoque, SKU e Código universal.
    const cartao = c('Mesa/CartaoVariante.jsx');
    assert.match(cartao, /erro=\{erro\} explicacao=\{a\.explicacao\} nome=\{a\.nome\}/);
    assert.match(cartao, /explicacao=\{schema\?\.explicacoes_campos\?\.\[multi \? 'estoque_por_deposito' : 'estoque'\]\}/);
    assert.match(cartao, /explicacao=\{schema\?\.atributos\?\.SELLER_SKU\?\.explicacao\}/);
    assert.match(cartao, /explicacao=\{schema\.atributos\.GTIN\.explicacao\}/);
    // Embalagem: as medidas convertidas e o campo genérico.
    const pacote = c('Mesa/MedidasDoPacote.jsx');
    assert.equal((pacote.match(/explicacao=\{a\.explicacao\}/g) ?? []).length, 2);
    // Cor principal, valor da nova variação e o nome do eixo.
    assert.match(c('Mesa/CorPrincipal.jsx'), /explicacao=\{atributo\?\.explicacao\}/);
    assert.match(c('Mesa/NovaVariacao.jsx'), /explicacao=\{atributo\?\.explicacao\}/);
    assert.match(c('EditorDeEixos.jsx'), /<Explicacao texto=\{atributo\.explicacao\} nome=\{e\.nome\} \/>/);
    // O tooltip cru do ML saiu do rótulo (ele agora chega pela explicação; evita dois balões).
    assert.doesNotMatch(c('CampoAtributo.jsx'), /title=\{atributo\.tooltip/);
});
