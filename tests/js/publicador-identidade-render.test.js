import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 170, Plano 02 — render REAL (esbuild + react-dom/server) de
// `IdentidadeDaConta.jsx`, mesma defesa de `publicador-painel-criativos-render.test.js`
// (261007): o campo `texto` vem do servidor e pode chegar em formato
// inesperado (objeto em vez de string) — nunca pode derrubar a tela
// ("Objects are not valid as a React child", tela preta do kit id 2).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const ENTRY = path.resolve(RAIZ, 'resources/js/Components/Publicador/Mesa/IdentidadeDaConta.jsx');

/** Compila o componente de verdade (JSX + imports reais) e devolve os exports. */
async function montarIdentidadeDaConta() {
    const resultado = await esbuild.build({
        entryPoints: [ENTRY],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js') },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios'],
    });

    // Precisa viver DENTRO da árvore do projeto — import ESM fora dela não acha node_modules.
    const outfile = path.join(__dirname, `.identidade-render-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const propsBase = (overrides = {}) => ({
    texto: null,
    carregando: false,
    salvando: false,
    erro: null,
    onSalvar: () => {},
    ...overrides,
});

test('IdentidadeDaConta — render real (esbuild + react-dom/server), não só estrutura de fonte', async (contexto) => {
    const mod = await montarIdentidadeDaConta();
    const { CampoIdentidade, default: IdentidadeDaConta } = mod;

    await contexto.test('texto string (JSON real do controller) aparece no value da textarea, sem crashar', () => {
        const texto = 'Cor principal #0A2342, cor secundária #FFC107, fonte Montserrat, acabamento fosco.';
        const html = renderToStaticMarkup(React.createElement(CampoIdentidade, propsBase({ texto })));

        assert.match(html, /<textarea[^>]*>Cor principal #0A2342, cor secund[áa]ria #FFC107, fonte Montserrat, acabamento fosco\.<\/textarea>/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('texto null — textarea vazia, sem crashar', () => {
        const html = renderToStaticMarkup(React.createElement(CampoIdentidade, propsBase({ texto: null })));

        assert.match(html, /<textarea[^>]*><\/textarea>/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('texto em formato inesperado (objeto em vez de string) — nunca lança, nunca aparece cru', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(CampoIdentidade, propsBase({ texto: { foo: 'bar' } })));
        });

        assert.match(html, /<textarea[^>]*><\/textarea>/);
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /foo/);
    });

    await contexto.test('texto em outros formatos inesperados (array, número, booleano) — nunca lança', () => {
        for (const textoInvalido of [['a', 'b'], 42, true]) {
            assert.doesNotThrow(() => {
                const html = renderToStaticMarkup(React.createElement(CampoIdentidade, propsBase({ texto: textoInvalido })));
                assert.doesNotMatch(html, /\[object Object\]/);
            });
        }
    });

    await contexto.test('carregando=true mostra esqueleto, não a textarea, sem crashar', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(CampoIdentidade, propsBase({ carregando: true })));
        });
        assert.doesNotMatch(html, /<textarea/);
    });

    await contexto.test('erro aparece quando presente', () => {
        const html = renderToStaticMarkup(React.createElement(CampoIdentidade, propsBase({ erro: 'Não foi possível salvar. Tente de novo.' })));
        assert.match(html, /Não foi possível salvar\. Tente de novo\./);
    });

    await contexto.test('título "Identidade visual da conta" sempre aparece', () => {
        const html = renderToStaticMarkup(React.createElement(CampoIdentidade, propsBase()));
        assert.match(html, /Identidade visual da conta/);
    });

    await contexto.test('o componente default (produtoId) monta sem crashar — a chamada ao servidor só ocorre em efeito, não no render', () => {
        assert.doesNotThrow(() => {
            const html = renderToStaticMarkup(React.createElement(IdentidadeDaConta, { produtoId: 7 }));
            assert.doesNotMatch(html, /\[object Object\]/);
        });
    });
});
