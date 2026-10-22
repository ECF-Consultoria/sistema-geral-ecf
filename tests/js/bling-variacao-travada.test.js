import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 184-10 (CONTEXT §4.1) — Ref e Estoque travados na ficha, quando a variação está
// vinculada ao Bling da empresa. O bloco `bling` do servidor pode faltar numa recarga
// parcial do Inertia (precedente do `cabecalho ?? null` de Configuracoes.jsx, que já
// derrubou tela) — por isso o caso 3 (bling ausente) é o mais importante dos cinco.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const DIR = 'resources/js/Components/Portal/Estrutura/Produtos';

global.route = (nome, params) => '/' + nome + (params && Object.keys(params).length ? '?' + new URLSearchParams(params).toString() : '');

const STUB_INERTIA = path.join(__dirname, `.bling-travada-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
export function usePage() { return { props: { modulos: [] } }; }
export const router = { get: () => {}, post: () => {}, put: () => {}, patch: () => {}, delete: () => {}, reload: () => {}, visit: () => {}, on: () => () => {} };
`, 'utf8');

after(() => {
    fs.rmSync(STUB_INERTIA, { force: true });
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
        alias: { '@inertiajs/react': STUB_INERTIA, '@': path.resolve(RAIZ, 'resources/js') },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/*'],
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

// ─── Dobradiças mínimas (ficha mock + variação mock) ────────────────────────

function fichaMock() {
    return {
        caixasEdit: () => [],
        explicacoes: {},
        erros: {},
        eixos: ['Cor', 'Material'],
        alterar: () => {},
        definirImagens: () => {},
    };
}

function variacaoMock(bling) {
    return {
        _k: 'v1', id: 5, codigo: 'ABC-1', nome: 'Produto Teste', eixo: null, eixo_rotulo: null,
        valor: null, custo: 10, estoque: 7, imagens: [], oferta: null, volumes: [], bling,
    };
}

// ─── 1. sku_travado: sem <input> de Ref, com o texto de ajuda ───────────────

test('sku_travado: true → não há <input> de Ref; o texto de ajuda explica de onde vem', async () => {
    const { default: CartaoVariacao } = await montar(`${DIR}/CartaoVariacao.jsx`, 'cartao-sku-travado');
    const variacao = variacaoMock({ vinculado: true, removido: false, estoque_travado: false, sku_travado: true, saldo_lido_em: null });

    const saida = html(h(CartaoVariacao, { variacao, ficha: fichaMock(), podeExcluir: false, onExcluir: () => {} }));

    assert.doesNotMatch(saida, /<input id="ref-v1"/, 'a Ref não é mais um campo editável');
    assert.match(saida, /<output id="ref-v1"[^>]*>ABC-1<\/output>/);
    assert.ok(saida.includes('vem do Bling'), 'o texto de ajuda diz de onde o valor vem');
    // Teste 1 negativo PAR: o Estoque (não travado neste caso) continua editável.
    assert.match(saida, /<input id="estoque-v1"/);
});

// ─── 2. estoque_travado: sem <input> de estoque, com o texto de ajuda ───────

test('estoque_travado: true → não há <input> de estoque; o texto de ajuda explica de onde vem', async () => {
    const { default: CartaoVariacao } = await montar(`${DIR}/CartaoVariacao.jsx`, 'cartao-estoque-travado');
    const variacao = variacaoMock({ vinculado: true, removido: false, estoque_travado: true, sku_travado: true, saldo_lido_em: '2026-10-13T12:00:00Z' });

    const saida = html(h(CartaoVariacao, { variacao, ficha: fichaMock(), podeExcluir: false, onExcluir: () => {} }));

    assert.doesNotMatch(saida, /<input id="estoque-v1"/, 'o estoque não é mais um campo editável');
    assert.match(saida, /<output id="estoque-v1"[^>]*>7<\/output>/);
    assert.ok(saida.includes('vem do Bling'));
    assert.ok(saida.includes('atualizado em'), 'quando há saldo_lido_em, a ajuda diz quando foi lido');
});

// ─── 3. bling ausente (undefined): os dois editáveis, sem quebrar ──────────

test('bling ausente (undefined): os dois campos continuam editáveis e nada quebra (recarga parcial do Inertia)', async () => {
    const { default: CartaoVariacao } = await montar(`${DIR}/CartaoVariacao.jsx`, 'cartao-bling-ausente');
    const variacao = variacaoMock(undefined);

    const saida = html(h(CartaoVariacao, { variacao, ficha: fichaMock(), podeExcluir: false, onExcluir: () => {} }));

    assert.match(saida, /<input id="ref-v1"/);
    assert.match(saida, /<input id="estoque-v1"/);
    assert.doesNotMatch(saida, /data-aviso-bling-removido/);
});

// ─── 4. removido: true — os dois editáveis, com o aviso ────────────────────

test('removido: true → os dois campos editáveis de novo, com o aviso de que saiu do Bling', async () => {
    const { default: CartaoVariacao } = await montar(`${DIR}/CartaoVariacao.jsx`, 'cartao-removido');
    const variacao = variacaoMock({ vinculado: false, removido: true, estoque_travado: false, sku_travado: false, saldo_lido_em: null });

    const saida = html(h(CartaoVariacao, { variacao, ficha: fichaMock(), podeExcluir: false, onExcluir: () => {} }));

    assert.match(saida, /<input id="ref-v1"/);
    assert.match(saida, /<input id="estoque-v1"/);
    assert.match(saida, /data-aviso-bling-removido/);
    assert.ok(html(h(CartaoVariacao, { variacao, ficha: fichaMock(), podeExcluir: false, onExcluir: () => {} })).includes('não está mais no Bling'));
});

// ─── 5. nenhum texto novo tem jargão nem cita a plataforma de venda ────────

test('os textos novos não têm jargão (ERP/integração/sincronizado/API/token) nem citam a plataforma de venda', () => {
    for (const arquivo of [`${DIR}/CartaoVariacao.jsx`, `${DIR}/EstoqueDoProduto.jsx`]) {
        const fonte = lerSemComentarios(arquivo);
        assert.doesNotMatch(fonte, /\bERP\b|integra(ç|c)[ãa]o|sincroniza|\bAPI\b|\btoken\b/i, arquivo);
        assert.doesNotMatch(fonte, /mercado ?livre|mercadolivre|shopee|magalu|amazon/i, arquivo);
    }
});

// ─── 6. EstoqueDoProduto: com UMA variação o topo não mostra estoque nenhum ──
// Demo 23/10 (N19): o bloco do topo repetia o estoque da única variação (dois campos para o mesmo
// dado). Agora some com uma variação — travada pelo Bling ou não; o cartão da variação mostra o
// estoque travado com o texto do Bling (seção acima).

test('EstoqueDoProduto: uma variação travada pelo Bling não repete o estoque no topo', async () => {
    const { default: EstoqueDoProduto } = await montar(`${DIR}/EstoqueDoProduto.jsx`, 'estoque-produto-travado');
    const variacao = variacaoMock({ vinculado: true, removido: false, estoque_travado: true, sku_travado: true, saldo_lido_em: null });
    const ficha = { vars: [variacao], explicacoes: {}, alterar: () => assert.fail('travado pelo Bling não se edita aqui') };

    assert.equal(html(h(EstoqueDoProduto, { ficha })), '');
});

test('EstoqueDoProduto: uma variação SEM trava também não repete o estoque no topo (par)', async () => {
    const { default: EstoqueDoProduto } = await montar(`${DIR}/EstoqueDoProduto.jsx`, 'estoque-produto-editavel');
    const variacao = variacaoMock(undefined);
    const ficha = { vars: [variacao], explicacoes: {}, alterar: () => assert.fail('o topo não grava') };

    assert.equal(html(h(EstoqueDoProduto, { ficha })), '');
});
