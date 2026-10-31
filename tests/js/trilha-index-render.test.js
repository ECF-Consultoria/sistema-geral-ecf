import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Trilha de produtos — o índice da equipe, `Trilha/Index` (30/10/2026).
//
// POR QUE EXISTE: render de verdade (esbuild + react-dom/server, com o
// TrilhaLayout real e só o Inertia de mentira), como em
// estrutura-montar-kit-render.test.js — variável sem declarar no caminho do
// render passa no build e deixa a tela branca. Cobre o cartão da empresa (link,
// logo ou iniciais, a contagem de cada etapa na cor dela) e a lista vazia, que
// tem de dizer POR QUE está vazia (chave `TRILHA_EMPRESAS` vazia, ou com ids que
// não são empresa).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

// O TrilhaLayout lê o tema salvo no render. O Node 26 tem `localStorage` próprio, que
// sem `--localstorage-file` só avisa no console: aqui ele é um armazenamento vazio.
Object.defineProperty(globalThis, 'localStorage', {
    value: { getItem: () => null, setItem: () => {}, removeItem: () => {} },
    configurable: true,
    writable: true,
});

const STUB_INERTIA = path.join(__dirname, `.trilha-index-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, preserveState, preserveScroll, replace, only, method, as, data, ...props }) {
    return React.createElement('a', { href, ...props }, children);
}
export function Head({ title }) { return React.createElement('title', null, title); }
export const router = { get() {}, reload() {}, visit() {} };
export function usePage() { return { props: {} }; }
`, 'utf8');

after(() => fs.rmSync(STUB_INERTIA, { force: true }));

async function montar(relativo, rotulo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, relativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        // O TrilhaLayout importa o trilha.css: no teste ele não vira nada.
        loader: { '.css': 'empty' },
        alias: { '@inertiajs/react': STUB_INERTIA, '@': path.resolve(RAIZ, 'resources/js') },
        external: ['react', 'react-dom', 'react-dom/server', 'react/jsx-runtime', 'lucide-react', 'clsx', 'tailwind-merge'],
    });
    const saida = path.join(__dirname, `.${rotulo}-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(saida, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(saida).href);
    } finally {
        fs.rmSync(saida, { force: true });
    }
}

const { createElement: h } = await import('react');
const { renderToStaticMarkup } = await import('react-dom/server');
const html = (el) => renderToStaticMarkup(el);
const texto = (s) => s.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ');

const VOLTAR = { url: '/dashboard', rotulo: 'Voltar ao sistema' };
const JATOBA = {
    id: 459,
    nome: 'Casa Jatobá',
    logo_url: null,
    url: '/trilha/459',
    contagem: { lista: 4, mapa: 2, plano: 2, robo: 2, revisao: 3, lanc: 3 },
};

const { default: Index } = await montar('resources/js/Pages/Trilha/Index.jsx', 'trilha-index');

test('o índice desenha um cartão por empresa ligada: link da trilha, iniciais e a contagem de cada etapa na cor dela', () => {
    const saida = html(h(Index, { empresas: [JATOBA], ligadas: [459], voltar: VOLTAR }));
    const t = texto(saida);

    // A moldura real da trilha (tema claro por padrão), o título da aba e o "voltar" da equipe.
    assert.match(saida, /<title>Trilha de produtos<\/title>/);
    assert.match(saida, /class="trilha [^"]*" data-tema="claro"/);
    assert.match(saida, /href="\/dashboard"/);

    assert.match(saida, /<a href="\/trilha\/459" data-empresa="459"/);
    for (const s of ['Trilha de produtos', 'Escolha a empresa', 'Casa Jatobá', 'CJ', '3 de 10 ofertas no ar', 'Voltar ao sistema']) {
        assert.ok(t.includes(s), `faltou: ${s}`);
    }
    const etapas = [...saida.matchAll(/data-etapa="(\w+)"[^>]*>.*?<b[^>]*>(\d+)<\/b><span[^>]*>([^<]+)<\/span>/g)].map((m) => `${m[1]}=${m[2]} ${m[3]}`);
    assert.deepEqual(etapas, ['lista=4 Lista', 'mapa=2 Mapeamento', 'plano=2 Planejamento', 'robo=2 Robô', 'revisao=3 Revisão', 'lanc=3 No ar']);
    // Cada etapa na cor do Mapa da loja (por style: a cor é variável do tema).
    for (const k of ['lista', 'mapa', 'plano', 'robo', 'rev', 'lanc']) assert.match(saida, new RegExp(`background:rgb\\(var\\(--t-s-${k}\\)\\)`));
    assert.match(saida, /title="Lista de produtos: 4 produtos"/);
    assert.ok(!saida.includes('data-sem-empresas'));
});

test('o cartão usa o logo quando há, junta as iniciais sem "de/da", e não quebra sem contagem', () => {
    const varias = html(h(Index, {
        empresas: [
            { ...JATOBA, logo_url: 'https://cdn.exemplo/logo.png' },
            { id: 12, nome: 'Casa da Mesa', logo_url: null, url: '/trilha/12', contagem: { lista: 1, mapa: 0, plano: 0, robo: 0, revisao: 0, lanc: 1 } },
            { id: 13, nome: 'Loja Nova', logo_url: null, url: '/trilha/13' },
        ],
        ligadas: [459, 12, 13],
        voltar: VOLTAR,
    }));
    const t = texto(varias);

    assert.match(varias, /<img src="https:\/\/cdn\.exemplo\/logo\.png" alt=""/);
    assert.equal((varias.match(/data-empresa="/g) ?? []).length, 3);
    assert.match(varias, />CM</, '"Casa da Mesa" → CM');
    assert.match(varias, />LN</);
    // Singular e plural certos, e a empresa sem contagem fica com zeros.
    assert.ok(t.includes('1 de 1 oferta no ar'));
    assert.match(varias, /title="Lista de produtos: 1 produto"/);
    assert.match(varias, /title="Protocolo e Jardinagem: 1 oferta"/);
    assert.equal((t.match(/Nenhuma oferta na trilha ainda/g) ?? []).length, 1);
    assert.match(varias, /title="Revisão e publicação: 0 ofertas"/);
});

test('lista vazia: diz que nenhuma empresa está ligada e por quê (chave vazia ou ids que não são empresa)', () => {
    const vazia = texto(html(h(Index, { empresas: [], ligadas: [], voltar: VOLTAR })));
    for (const s of ['Nenhuma empresa está ligada à trilha.', 'TRILHA_EMPRESAS', 'ela está vazia']) assert.ok(vazia.includes(s), `faltou: ${s}`);

    const dois = texto(html(h(Index, { empresas: [], ligadas: [459, 12], voltar: VOLTAR })));
    assert.ok(dois.includes('liga os ids 459, 12') && dois.includes('nenhum deles é uma empresa cadastrada'), dois);

    const um = texto(html(h(Index, { empresas: [], ligadas: [459], voltar: VOLTAR })));
    assert.ok(um.includes('liga o id 459') && um.includes('ele não é uma empresa cadastrada'), um);

    // Sem props nenhuma (não deveria acontecer): a tela vazia, não a branca.
    const semNada = html(h(Index, {}));
    assert.ok(semNada.includes('data-sem-empresas') && !semNada.includes('data-empresa='));
});

test('o índice só usa as cores da trilha (nada de branco fixo, ecf-*, pub-* nem hex)', () => {
    const fonte = lerSemComentarios('resources/js/Pages/Trilha/Index.jsx');

    assert.equal(fonte.match(/\b(?:text|bg|border|fill|stroke|ring|outline)-white\b/), null);
    assert.equal(fonte.match(/\b[a-z]+-ecf-/), null);
    assert.equal(fonte.replace(/font-pub-mono/g, '').match(/\bpub-/), null);
    assert.equal(fonte.match(/#[0-9a-fA-F]{3,8}\b/), null);
});
