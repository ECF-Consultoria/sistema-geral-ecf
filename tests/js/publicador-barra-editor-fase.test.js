import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 175, Plano 175-09 (§7 da ETAPA-3) — render REAL (esbuild +
// react-dom/server) de `BarraDoEditor.jsx` e `CartaoVariante.jsx`, mesmo
// harness de `publicador-painel-criativos-render.test.js` e de
// `publicador-produto-render.test.js`.
//
// Por que render REAL e não regex sobre a fonte: foi por essa fenda que
// `kit.estrategia` (um OBJETO do presenter) chegou a produção sendo
// renderizado cru e derrubou a árvore React inteira — "Objects are not valid
// as a React child", tela preta de 05-07/10/2026. Este plano acrescenta
// campos NOVOS ao `EditorRascunhoService::estado()`, que é exatamente um
// presenter consumido por React, então cada campo novo que a tela exibe é
// coberto aqui chegando certo, chegando como OBJETO e chegando AUSENTE.
//
// Os dois componentes compartilham um arquivo porque provam a mesma coisa:
// que o editor de um KIT se identifica e bloqueia só o estoque, e que o
// editor de quem NÃO é kit continua idêntico ao de antes.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

// `@inertiajs/react` real traz `qs`/`object-inspect` incompatíveis com o bundle
// ESM do esbuild ("Dynamic require of 'util' is not supported") — stub inline,
// mesmo tratamento da 173-06 e da 175-04.
const STUB_INERTIA = path.join(__dirname, `.barra-fase-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');
after(() => fs.rmSync(STUB_INERTIA, { force: true }));

/** Compila o componente de verdade (JSX + imports reais) e devolve os exports. */
async function montar(relativo, externosExtra = []) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, relativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', ...externosExtra],
    });

    // O arquivo precisa viver DENTRO da árvore do projeto: a resolução ESM do
    // Node para `import 'react'` sobe os diretórios até achar `node_modules`.
    const outfile = path.join(__dirname, `.barra-fase-render-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

// ─── O `pub` mínimo da barra (valor de `usePublicador`, não alterado neste plano) ───

const produtoDoEstado = (overrides = {}) => ({
    id: 11,
    sku: 'CAD-01-KIT2',
    nome: 'Kit 2 Cadeira',
    oferta_id: null,
    origem: 'publicador',
    mlb_empresa_id: null,
    company_id: 459,
    fase: 2,
    quantidade_kit: 2,
    eh_kit: true,
    rotulo_fase: 'Fase 2 · Kit 2',
    estoque_calculado: true,
    base: { id: 10, sku: 'CAD-01', nome: 'Cadeira', url: '/publicador/produtos/10' },
    aviso_base_apagado: false,
    aviso_estoque_ml: false,
    ...overrides,
});

const pubBase = (produto = produtoDoEstado()) => ({
    liberada: true,
    salvamento: { estado: 'salvo', mensagem: null },
    salvoEm: new Date(),
    m: { estado: { produto, rascunho: { status: 'DRAFT' } } },
});

const empresaBase = {
    chave: 'company-459', nome: 'Polo das Fases', programa: 'polos', programa_rotulo: 'Polos',
    identificador: '459', token: 'ativo',
};

const propsDaBarra = (produto = produtoDoEstado()) => ({
    pub: pubBase(produto),
    empresa: empresaBase,
    produto: { id: produto.id, nome: produto.nome },
    produtos: [],
    onTrocar: () => {},
    ia: { disparar: () => {}, estado: null, processando: false },
    onVoltar: () => {},
});

test('BarraDoEditor — a fase do kit, o link para o base e os dois avisos', async (contexto) => {
    const { default: BarraDoEditor } = await montar(
        'resources/js/Components/Publicador/Mesa/BarraDoEditor.jsx',
        ['@radix-ui/react-popover', '@radix-ui/react-dialog'],
    );
    const render = (produto) => renderToStaticMarkup(React.createElement(BarraDoEditor, propsDaBarra(produto)));

    await contexto.test('kit: "Fase 2 · Kit 2" e o link "Produto base" com o href do servidor', () => {
        let html;
        assert.doesNotThrow(() => { html = render(produtoDoEstado()); });
        assert.match(html, /Fase 2 · Kit 2/);
        assert.match(html, /Produto base/);
        assert.match(html, /href="\/publicador\/produtos\/10"/);
        assert.doesNotMatch(html, /\[object Object\]/);
        // Nada saiu da barra.
        assert.match(html, /Publicador/);
        assert.match(html, /Polo das Fases/);
    });

    await contexto.test('base.url nula não renderiza link nenhum (nunca href vazio)', () => {
        const html = render(produtoDoEstado({ base: { id: 10, sku: 'CAD-01', nome: 'Cadeira', url: null } }));
        assert.match(html, /Fase 2 · Kit 2/);
        assert.doesNotMatch(html, /Produto base/);
        assert.doesNotMatch(html, /href=""/);
    });

    await contexto.test('base ausente por completo também não derruba nem inventa link', () => {
        const html = render(produtoDoEstado({ base: null }));
        assert.match(html, /Fase 2 · Kit 2/);
        assert.doesNotMatch(html, /Produto base/);
    });

    await contexto.test('eh_kit false não renderiza NADA de novo — a trilha fica idêntica à de hoje', () => {
        const base = produtoDoEstado({
            id: 10, sku: 'CAD-01', nome: 'Cadeira', fase: 1, quantidade_kit: 1,
            eh_kit: false, rotulo_fase: null, estoque_calculado: false, base: null,
        });
        const html = render(base);
        assert.doesNotMatch(html, /Fase 1|Kit 1|Produto base|data-fase-do-editor|data-aviso-barra/);
        assert.match(html, /Polo das Fases/);
        assert.match(html, /Salvo h[áa]/);
    });

    await contexto.test('estado sem a chave `produto` (bundle novo, servidor antigo) não derruba a barra', () => {
        const props = propsDaBarra();
        props.pub.m.estado = { rascunho: { status: 'DRAFT' } };
        let html;
        assert.doesNotThrow(() => { html = renderToStaticMarkup(React.createElement(BarraDoEditor, props)); });
        assert.doesNotMatch(html, /data-fase-do-editor/);
        assert.match(html, /Polo das Fases/);
    });

    await contexto.test('aviso_base_apagado renderiza o aviso âmbar', () => {
        const html = render(produtoDoEstado({ aviso_base_apagado: true, base: null }));
        assert.match(html, /o produto base deste kit foi exclu[íi]do/);
        assert.match(html, /text-amber-300/);
        assert.match(html, /data-aviso-barra="base-apagado"/);
    });

    await contexto.test('aviso_estoque_ml renderiza "Estoque no ML difere do calculado", em âmbar', () => {
        const html = render(produtoDoEstado({ aviso_estoque_ml: true }));
        assert.match(html, /Estoque no ML difere do calculado/);
        assert.match(html, /data-aviso-barra="estoque-ml"/);
        assert.match(html, /text-amber-300/);
    });

    await contexto.test('nenhum aviso quando os dois são falsos', () => {
        const html = render(produtoDoEstado());
        assert.doesNotMatch(html, /data-aviso-barra/);
        assert.doesNotMatch(html, /difere do calculado/);
    });

    // ⚠️ O caso que derrubou produção: campo do presenter chegando como OBJETO.
    await contexto.test('rotulo_fase chegando como objeto não derruba a barra', () => {
        let html;
        assert.doesNotThrow(() => { html = render(produtoDoEstado({ rotulo_fase: { fase: 2, kit: 2 } })); });
        assert.doesNotMatch(html, /\[object Object\]/);
        // O link para o base continua, porque ele não depende do rótulo.
        assert.match(html, /Produto base/);
    });

    await contexto.test('base.url chegando como objeto não vira href', () => {
        let html;
        assert.doesNotThrow(() => {
            html = render(produtoDoEstado({ base: { id: 10, sku: 'CAD-01', nome: 'Cadeira', url: { href: '/x' } } }));
        });
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /Produto base/);
    });
});

// ─── O cartão de dados da variação (etapa Detalhes) ───

// ⚠️ A classe dos inputs do editor contém `disabled:cursor-not-allowed
// disabled:opacity-50` (variantes do Tailwind): um `/disabled/` cru casa com o
// ATRIBUTO e com a CLASSE. Só o atributo de verdade conta.
const DESABILITADO = /\sdisabled=""/;

const varianteBase = (overrides = {}) => ({
    chave: '__single__',
    rotulo: 'Produto',
    ativa: true,
    orfa: false,
    publicada: false,
    valores: {},
    estoque: 3,
    estoque_depositos: null,
    precos: {},
    precos_efetivos: {},
    atributos: { SELLER_SKU: { value_name: 'CAD-01-KIT2' } },
    ...overrides,
});

const mesaBase = (produto = produtoDoEstado(), overrides = {}) => ({
    disabled: false,
    estado: { produto, conta: null },
    schema: null,
    variantes: [varianteBase()],
    mudarVar: () => {},
    ...overrides,
});

test('CartaoVariante — estoque do kit somente leitura, SKU editável', async (contexto) => {
    const { default: CartaoVariante } = await montar('resources/js/Components/Publicador/Mesa/CartaoVariante.jsx');
    const render = (m, v = varianteBase()) => renderToStaticMarkup(
        React.createElement(CartaoVariante, { m, v, eixos: [], onTirar: null }),
    );

    await contexto.test('estoque_calculado true: campo de estoque disabled com "calculado do produto base"', () => {
        let html;
        assert.doesNotThrow(() => { html = render(mesaBase()); });
        assert.match(html, /calculado do produto base/);
        assert.match(html.match(/<input[^>]*data-estoque="__single__"[^>]*>/)?.[0] ?? '', DESABILITADO);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('o SKU do kit continua EDITÁVEL (só o estoque trava)', () => {
        const html = render(mesaBase());
        const sku = html.match(/<input[^>]*data-sku="__single__"[^>]*>/)?.[0] ?? '';
        assert.notEqual(sku, '', 'o campo de SKU tem de existir');
        assert.doesNotMatch(sku, DESABILITADO);
    });

    await contexto.test('estoque_calculado false mantém o estoque editável, inclusive em kit (combo vinculado)', () => {
        const html = render(mesaBase(produtoDoEstado({ estoque_calculado: false })));
        const estoque = html.match(/<input[^>]*data-estoque="__single__"[^>]*>/)?.[0] ?? '';
        assert.notEqual(estoque, '');
        assert.doesNotMatch(estoque, DESABILITADO);
        assert.doesNotMatch(html, /calculado do produto base/);
    });

    await contexto.test('produto que não é kit: nada muda no cartão', () => {
        const html = render(mesaBase(produtoDoEstado({ eh_kit: false, estoque_calculado: false, base: null, rotulo_fase: null })));
        assert.doesNotMatch(html, /calculado do produto base/);
        const estoque = html.match(/<input[^>]*data-estoque="__single__"[^>]*>/)?.[0] ?? '';
        assert.notEqual(estoque, '');
        assert.doesNotMatch(estoque, DESABILITADO);
    });

    await contexto.test('estado sem a chave `produto` não derruba o cartão nem trava o estoque', () => {
        let html;
        const m = mesaBase();
        m.estado = { conta: null };
        assert.doesNotThrow(() => { html = render(m); });
        assert.doesNotMatch(html, /calculado do produto base/);
    });

    await contexto.test('variante publicada continua travada em TUDO (comportamento de antes)', () => {
        const html = render(mesaBase(produtoDoEstado({ estoque_calculado: false })), varianteBase({ publicada: true }));
        const sku = html.match(/<input[^>]*data-sku="__single__"[^>]*>/)?.[0] ?? '';
        assert.match(sku, DESABILITADO);
        assert.match(html, /publicada/);
    });

    await contexto.test('conta multidepósito: cada caixa de depósito do kit também fica disabled', () => {
        const m = mesaBase();
        m.estado.conta = { multi_deposito: true, depositos: [{ store_id: 'A', nome: 'Depósito A' }, { store_id: 'B', nome: 'Depósito B' }] };
        const html = render(m, varianteBase({ estoque: 4, estoque_depositos: { A: 3, B: 1 } }));
        const caixas = html.match(/<input[^>]*data-estoque-deposito="[AB]"[^>]*>/g) ?? [];
        assert.equal(caixas.length, 2);
        for (const caixa of caixas) {
            assert.match(caixa, DESABILITADO);
        }
        assert.match(html, /calculado do produto base/);
    });
});
