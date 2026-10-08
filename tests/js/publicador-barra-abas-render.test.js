import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 172, Plano 01 — render REAL (esbuild + react-dom/server) de
// `BarraDaConta.jsx` e `AbasDaConta.jsx`, mesma defesa de
// `publicador-identidade-render.test.js` (Fase 170): `empresa` vem do
// servidor e pode chegar em formato inesperado — nunca pode derrubar a tela
// ("Objects are not valid as a React child", tela preta de 07/10).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

// Stub de route() global — mesmo truque de outros testes de render deste
// projeto que dependem do helper Ziggy, sem o runtime do Inertia real.
global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

// `@inertiajs/react` real traz `qs`/`object-inspect`, que fazem `require('util')`
// condicional incompatível com o bundle ESM do esbuild ("Dynamic require of
// 'util' is not supported"). Como este teste só precisa de `Link` (renderiza
// `<a>`) e `router.get` (não é chamado em render estático), substitui o
// módulo por um stub inline via `alias` do esbuild — nada do Inertia real.
const STUB_INERTIA = path.join(__dirname, `.barra-abas-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
`, 'utf8');
after(() => fs.rmSync(STUB_INERTIA, { force: true }));

/** Compila o componente de verdade (JSX + imports reais) e devolve os exports. */
async function montarComponente(caminhoRelativo) {
    const entry = path.resolve(RAIZ, caminhoRelativo);
    const resultado = await esbuild.build({
        entryPoints: [entry],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios'],
    });

    // Precisa viver DENTRO da árvore do projeto — import ESM fora dela não acha node_modules.
    const outfile = path.join(__dirname, `.barra-abas-render-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const empresaBase = (overrides = {}) => ({
    chave: 'company-459',
    tipo: 'company',
    id: 459,
    nome: 'Kive Shop Eletrônicos',
    identificador: 'company-459',
    programa: 'polos',
    programa_rotulo: 'Polos',
    company_id: 459,
    token: 'ativo',
    link_reconexao: null,
    portal: { situacao: 'sincronizado', novas: 0, sincronizado_em: '2026-10-08T10:00:00Z' },
    conta_nome: null,
    conta_ml_id: null,
    ...overrides,
});

test('BarraDaConta — render real (esbuild + react-dom/server), não só estrutura de fonte', async (contexto) => {
    const { default: BarraDaConta } = await montarComponente('resources/js/Components/Mlb/Publicador/BarraDaConta.jsx');

    await contexto.test('empresa.nome string normal — aparece no h1, sem crash', () => {
        const html = renderToStaticMarkup(React.createElement(BarraDaConta, { empresa: empresaBase() }));
        assert.match(html, /<h1[^>]*>Kive Shop Eletr[ôo]nicos<\/h1>/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('empresa.nome ausente (undefined) — renderiza "—", sem crash', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(BarraDaConta, { empresa: empresaBase({ nome: undefined }) }));
        });
        assert.match(html, /<h1[^>]*>—<\/h1>/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('empresa.nome em formato inesperado (objeto) — nunca lança, nunca aparece cru', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(BarraDaConta, { empresa: empresaBase({ nome: { foo: 'bar' } }) }));
        });
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /foo/);
    });

    await contexto.test('empresa.identificador ausente — não quebra o mono da chave', () => {
        let html;
        assert.doesNotThrow(() => {
            const { identificador, ...resto } = empresaBase();
            html = renderToStaticMarkup(React.createElement(BarraDaConta, { empresa: resto }));
        });
        assert.match(html, /company-459/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('empresa.chave ausente — mono da chave cai pra "—", sem crash', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(BarraDaConta, { empresa: empresaBase({ chave: undefined }) }));
        });
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('liberada=false — inclui o AvisoContaTravada variante selo', () => {
        const html = renderToStaticMarkup(React.createElement(BarraDaConta, { empresa: empresaBase(), liberada: false }));
        assert.match(html, /Publica[çc][ãa]o ainda n[ãa]o liberada/);
    });

    await contexto.test('liberada=true (default) — não mostra o aviso de conta travada', () => {
        const html = renderToStaticMarkup(React.createElement(BarraDaConta, { empresa: empresaBase() }));
        assert.doesNotMatch(html, /Publica[çc][ãa]o ainda n[ãa]o liberada/);
    });

    await contexto.test('acoes=null (default) — não renderiza o slot de ações', () => {
        const html = renderToStaticMarkup(React.createElement(BarraDaConta, { empresa: empresaBase() }));
        assert.doesNotMatch(html, /slot-acoes-teste/);
    });

    await contexto.test('acoes preenchido — renderiza o conteúdo do slot', () => {
        const html = renderToStaticMarkup(React.createElement(BarraDaConta, {
            empresa: empresaBase(),
            acoes: React.createElement('span', null, 'slot-acoes-teste'),
        }));
        assert.match(html, /slot-acoes-teste/);
    });

    await contexto.test('empresa=undefined (shape inesperado por completo) — nunca lança', () => {
        assert.doesNotThrow(() => {
            const html = renderToStaticMarkup(React.createElement(BarraDaConta, { empresa: undefined }));
            assert.doesNotMatch(html, /\[object Object\]/);
        });
    });
});

test('AbasDaConta — render real (esbuild + react-dom/server), não só estrutura de fonte', async (contexto) => {
    const { default: AbasDaConta } = await montarComponente('resources/js/Components/Mlb/Publicador/AbasDaConta.jsx');

    await contexto.test('companyId=null e aba="produtos" — Publicações desabilitada com title D23, sem lançar', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(AbasDaConta, { aba: 'produtos', conta: 'company-459', companyId: null }));
        });
        assert.match(html, /aria-disabled="true"/);
        assert.match(html, /Dispon[íi]vel s[óo] para empresas cadastradas no sistema/);
    });

    await contexto.test('companyId=null — aba Produtos marcada ativa (aria-current)', () => {
        const html = renderToStaticMarkup(React.createElement(AbasDaConta, { aba: 'produtos', conta: 'company-459', companyId: null }));
        assert.match(html, /aria-current="page"/);
    });

    await contexto.test('aba="publicacoes" e companyId=10 — renderiza o segmentado com "No ar" e "Histórico"', () => {
        const html = renderToStaticMarkup(React.createElement(AbasDaConta, { aba: 'publicacoes', conta: 'company-459', companyId: 10, subPublicacoes: 'meus' }));
        assert.match(html, /role="radiogroup"/);
        assert.match(html, /No ar/);
        assert.match(html, /Hist[óo]rico/);
    });

    await contexto.test('aba="produtos" (não publicacoes) — NÃO renderiza o segmentado', () => {
        const html = renderToStaticMarkup(React.createElement(AbasDaConta, { aba: 'produtos', conta: 'company-459', companyId: 10 }));
        assert.doesNotMatch(html, /role="radiogroup"/);
    });

    await contexto.test('aba="publicacoes" mas companyId=null — NÃO renderiza o segmentado (D23 também aqui)', () => {
        const html = renderToStaticMarkup(React.createElement(AbasDaConta, { aba: 'publicacoes', conta: 'company-459', companyId: null }));
        assert.doesNotMatch(html, /role="radiogroup"/);
    });

    await contexto.test('contagemProdutos preenchido — mostra o badge na aba Produtos', () => {
        const html = renderToStaticMarkup(React.createElement(AbasDaConta, { aba: 'produtos', conta: 'company-459', companyId: 10, contagemProdutos: 42 }));
        assert.match(html, /42/);
    });

    await contexto.test('contagemProdutos=null (default) — não mostra badge', () => {
        const html = renderToStaticMarkup(React.createElement(AbasDaConta, { aba: 'produtos', conta: 'company-459', companyId: 10 }));
        assert.doesNotMatch(html, /tabular-nums/);
    });

    await contexto.test('conta e companyId ausentes (shape inesperado) — nunca lança', () => {
        assert.doesNotThrow(() => {
            const html = renderToStaticMarkup(React.createElement(AbasDaConta, { aba: 'produtos' }));
            assert.doesNotMatch(html, /\[object Object\]/);
        });
    });
});
