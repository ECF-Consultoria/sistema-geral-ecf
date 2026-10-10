import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';

// ═══════════════════════════════════════════════════════════════════════════
// Planejamento × Fase N, item C (decisões do usuário de 09/10/2026) — lado
// tela da lista de Produtos do Publicador.
//
// O produto ligado a uma oferta Combo/Kit/Combit do Portal chega com
// `composto` (`combo|kit|combit`) e o `rotulo_fase` do tipo ("Combo do
// Planejamento"). Ele NÃO é base de Fase 1:
// - a célula Fase mostra o rótulo dele, nunca "Fase 1";
// - o menu não oferece "Criar Fase 2" (o servidor recusa com KIT-06);
// - o filtro "Só base" não o mostra (fica em "Todas").
//
// Cada campo entra também como objeto/nulo/ausente: nada pode estourar
// ("Objects are not valid as a React child", 07/10/2026).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const PAGINA = path.resolve(RAIZ, 'resources/js/Pages/Mlb/Publicador/Produtos.jsx');
const MENU = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/MenuDeAcoesDoProduto.jsx');
const LAYOUT = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/layoutDaListaDeProdutos.js');

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});
global.window = {
    location: { search: '' },
    addEventListener: () => {},
    removeEventListener: () => {},
};

const STUB_INERTIA = path.join(__dirname, `.composto-planejamento-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');

const STUB_APPLAYOUT = path.join(__dirname, `.composto-planejamento-applayout-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_APPLAYOUT, `
import React from 'react';
export default function AppLayout({ children }) {
    return React.createElement('div', null, children);
}
`, 'utf8');

after(() => {
    fs.rmSync(STUB_INERTIA, { force: true });
    fs.rmSync(STUB_APPLAYOUT, { force: true });
});

/** Compila o módulo de verdade (JSX + imports reais) e devolve os exports. */
async function montar(entry, rotulo) {
    const resultado = await esbuild.build({
        entryPoints: [entry],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: {
            '@': path.resolve(RAIZ, 'resources/js'),
            '@inertiajs/react': STUB_INERTIA,
            '@/Layouts/AppLayout': STUB_APPLAYOUT,
        },
        external: [
            'react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios',
            '@radix-ui/react-popover', '@radix-ui/react-dialog', 'recharts',
        ],
    });

    const outfile = path.join(__dirname, `.${rotulo}-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const produto = (extra = {}) => ({
    id: 1, sku: 'CAD-PT-CB2', nome: 'Combo 2 Cadeira', origem: 'portal', oferta_id: 10,
    status: { chave: 'publicado', faltam: 0 }, anuncios: [], fase: 1, quantidade_kit: 1,
    produto_base_id: null, eh_kit: false, rotulo_fase: '1 unidade', composto: null,
    kits: [], base: null, sugestao_kit: null, url_produto: null,
    ...extra,
});

const LIXO = [{}, [], 7, true, { tipo: 'combo' }, ['combo'], ''];

test('layout — o composto do Planejamento mostra o rótulo do tipo, nunca "Fase 1"', async (contexto) => {
    const { textoDaFase, ehComposto } = await montar(LAYOUT, 'composto-layout');

    await contexto.test('combo, kit e combit', () => {
        assert.deepEqual(
            textoDaFase(produto({ composto: 'combo', rotulo_fase: 'Combo do Planejamento' })),
            { texto: 'Combo do Planejamento', titulo: 'Combo do Planejamento', ehKit: false },
        );
        assert.equal(textoDaFase(produto({ composto: 'kit', rotulo_fase: 'Kit do Planejamento' })).texto, 'Kit do Planejamento');
        assert.equal(textoDaFase(produto({ composto: 'combit', rotulo_fase: 'Combit do Planejamento' })).texto, 'Combit do Planejamento');
    });

    await contexto.test('sem rótulo do servidor, o texto neutro', () => {
        assert.equal(textoDaFase(produto({ composto: 'combo', rotulo_fase: null })).texto, 'Composto do Planejamento');
    });

    await contexto.test('o base e o kit da família não mudam', () => {
        assert.equal(textoDaFase(produto()).texto, 'Fase 1');
        assert.equal(textoDaFase(produto({ fase: 2, eh_kit: true, quantidade_kit: 2, rotulo_fase: 'Kit 2' })).texto, 'Fase 2 · Kit 2');
    });

    await contexto.test('`composto` fora de forma não é composto e nada estoura', () => {
        for (const lixo of LIXO) {
            assert.equal(ehComposto(produto({ composto: lixo })), false, JSON.stringify(lixo));
            assert.equal(textoDaFase(produto({ composto: lixo })).texto, 'Fase 1', JSON.stringify(lixo));
        }
        assert.equal(ehComposto(null), false);
        assert.equal(ehComposto('combo'), false);
        assert.equal(ehComposto(produto({ composto: 'combo' })), true);
    });
});

test('MenuDeAcoesDoProduto — composto publicado não oferece "Criar Fase 2"', async () => {
    const { itensDoMenu } = await montar(MENU, 'composto-menu');
    const chaves = (p) => itensDoMenu(p, null).map((i) => i.chave);

    assert.ok(!chaves(produto({ composto: 'combo' })).includes('fase2'));
    assert.ok(!chaves(produto({ composto: 'kit', status: { chave: 'parcial' } })).includes('fase2'));
    assert.ok(chaves(produto()).includes('fase2'), 'o base de verdade publicado continua oferecendo');
    for (const lixo of LIXO) {
        assert.ok(chaves(produto({ composto: lixo })).includes('fase2'), JSON.stringify(lixo));
    }
});

test('Produtos — "Só base" não mostra o composto; "Todas" mostra', async () => {
    const { montarLinhas } = await montar(PAGINA, 'composto-linhas');
    const base = produto({ id: 1, sku: 'CAD', composto: null });
    const combo = produto({ id: 2, sku: 'CAD-PT-CB2', composto: 'combo', rotulo_fase: 'Combo do Planejamento' });
    const kit = produto({ id: 3, sku: 'CAD-KIT2', fase: 2, eh_kit: true, quantidade_kit: 2, produto_base_id: 1, base: { id: 1, sku: 'CAD', nome: 'C' }, rotulo_fase: 'Kit 2' });
    const ids = (linhas) => linhas.map((l) => l.produto.id).sort();

    assert.deepEqual(ids(montarLinhas([base, combo, kit], { fase: 'so_base' })), [1]);
    assert.deepEqual(ids(montarLinhas([base, combo, kit], { fase: 'so_kits' })), [3]);
    assert.deepEqual(ids(montarLinhas([base, combo, kit], { fase: 'todas' })), [1, 2, 3]);
});
