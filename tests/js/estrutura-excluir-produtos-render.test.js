import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';

// ═══════════════════════════════════════════════════════════════════════════
// Render de verdade (React no servidor) do "Excluir produtos" do Produtos (10/10/2026): a
// confirmação, a barra da seleção, a caixa nos dois cartões e a página inteira da lista.
//
// POR QUE EXISTE: uma variável sem declarar deixa a tela PRETA e passa no build e nos gates que
// leem o código como texto (learnings §33). Aqui os módulos são compilados com os imports reais e
// desenhados com props no formato do servidor: um nome errado no caminho do render estoura o teste.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

global.route = (nome, params) => '/' + nome + (params !== undefined && params !== null && typeof params !== 'object' ? `/${params}` : '');
global.window = {
    location: { href: 'http://localhost/portal/estrutura/produtos', search: '' },
    history: { state: null, replaceState: () => {}, go: () => {}, back: () => {} },
    addEventListener: () => {},
    removeEventListener: () => {},
};
global.__PROPS__ = {};

const STUB_INERTIA = path.join(__dirname, `.excluir-produtos-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, replace, preserveState, preserveScroll, only, method, as, data, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
const nada = () => () => {};
export const router = { get: () => {}, post: () => {}, put: () => {}, patch: () => {}, delete: () => {}, reload: () => {}, visit: () => {}, replace: () => {}, on: nada };
export function usePage() { return { props: globalThis.__PROPS__ }; }
`, 'utf8');

const STUB_LAYOUT = path.join(__dirname, `.excluir-produtos-layout-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_LAYOUT, `
import React from 'react';
export default function PortalClienteLayout({ children, titulo }) {
    return React.createElement('main', { 'data-titulo': titulo }, children);
}
`, 'utf8');

// A janela do Radix não desenha no servidor (portal): aqui ela vira uma <section> com o título.
const STUB_JANELA = path.join(__dirname, `.excluir-produtos-janela-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_JANELA, `
import React from 'react';
export default function Janela({ aberta, titulo, descricao, children }) {
    if (! aberta) return null;
    return React.createElement('section', { 'data-janela': titulo }, React.createElement('h2', null, titulo), descricao ? React.createElement('p', null, descricao) : null, children);
}
`, 'utf8');

after(() => {
    for (const f of [STUB_INERTIA, STUB_LAYOUT, STUB_JANELA]) fs.rmSync(f, { force: true });
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
            '@/Layouts/PortalClienteLayout': STUB_LAYOUT,
            '@/Components/Portal/Estrutura/Janela': STUB_JANELA,
            '@inertiajs/react': STUB_INERTIA,
            '@': path.resolve(RAIZ, 'resources/js'),
        },
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

const { createElement: h } = await import('react');
const { renderToStaticMarkup } = await import('react-dom/server');
const html = (el) => renderToStaticMarkup(el);
const texto = (s) => s.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ');
const contar = (s, re) => (s.match(re) ?? []).length;

const PROIBIDO = /an[uú]ncio|Publicador|\bpublic(ar|ação|ado|ados)\b/i;

// ─── Props no formato do servidor ───────────────────────────────────────

const SUB = (chave, rotulo, extra = {}) => ({ chave, rotulo, url: `/portal/estrutura/${chave}`, ativo: false, em_breve: false, oculto: false, ...extra });
const MODULOS = [
    { chave: 'estrutura', rotulo: 'Mapeamento Estrutural', ativo: true, submodulos: [
        SUB('produtos', 'Produtos', { ativo: true }), SUB('sugestoes', 'Planejamento'), SUB('precificacao', 'Precificação'), SUB('mapeamento', 'Mapeamento'),
    ] },
];
const VOCAB = {
    pendencias: { medidas: 'medidas', custo: 'custo', categoria: 'categoria' },
    logisticas: { me2_full: 'ME2 · Full', me2: 'ME2', me1: 'ME1', pendente: 'Pendente' },
};

/** Uma linha como `ProdutoLinhas::linha` devolve. */
const linha = (id, produtoId, nome, codigo, valor, primeira = true) => ({
    id, produto_id: produtoId, codigo, grupo: codigo.split('-')[0], nome, eixo: 'cor', eixo_rotulo: 'Cor', valor, ordem: 0, primeira,
    familia: 'Polo', ambientes: ['Sala de jantar'], categoria_ml_id: 'MLB1', categoria_ml_nome: 'Cadeiras', categoria_ml_caminho: 'Casa > Móveis > Cadeiras',
    categoria_estado: 'confirmada', volumes: [{ c: 80, l: 50, a: 10, kg: 8 }], volumes_texto: '80x50x10 8kg', n_volumes: 1, peso_total: 8,
    custo: 120, estoque: 5, pacote: { c: 80, l: 50, a: 10 }, peso_cubado: 6.7, peso_faturado: 8, cubado_cobrado: false, logistica: 'me2',
    frete: null, pendencias: [], capa: null, oferta: { id: id + 100, sku: codigo, anuncios: 0, usada_em: [] },
});

const PREVIA = {
    produtos: [
        { id: 1, nome: 'Mesa Polo', codigo: 'MESA', variacoes: 1, em_uso: true },
        { id: 2, nome: 'Cadeira Polo', codigo: 'CAD', variacoes: 2, em_uso: false },
    ],
    montadas: [
        { id: 9, sku: 'CAD-NT-CB2', nome: 'Kit 2 Cadeiras Polo', fase: 'combo', em_uso: false },
        { id: 10, sku: 'KT-MESA-CAD', nome: 'Mesa Polo + Cadeira Polo', fase: 'kit', em_uso: false },
    ],
    totais: { produtos: 2, variacoes: 3, montadas: 2, em_uso: 1 },
    nao_encontrados: 1,
};

// ─── A confirmação ──────────────────────────────────────────────────────

test('a confirmação lista os produtos, o que sai junto e o aviso de uso pela equipe', async () => {
    const mod = await montar('resources/js/Components/Portal/Estrutura/Produtos/JanelaExcluirProdutos.jsx', 'excluir-janela');
    global.__PROPS__ = { modulos: MODULOS };

    const varios = texto(html(h(mod.ConfirmacaoDaExclusao, { previa: PREVIA })));
    for (const t of ['Mesa Polo', 'Cadeira Polo', 'Os produtos saem com 3 variações', 'Não dá para desfazer', '2 ofertas montadas usam estes produtos e saem junto:',
        'Combo', 'CAD-NT-CB2', 'Kit', 'KT-MESA-CAD', 'já está em uso pela equipe da ECF', '1 produto marcado não existe mais e ficou de fora.']) {
        assert.ok(varios.includes(t), `faltou: ${t}`);
    }
    assert.equal(varios.match(PROIBIDO), null);

    const um = html(h(mod.ConfirmacaoDaExclusao, {
        previa: { produtos: [PREVIA.produtos[1]], montadas: [], totais: { produtos: 1, variacoes: 2, montadas: 0, em_uso: 0 }, nao_encontrados: 0 },
    }));
    assert.ok(texto(um).includes('O produto sai com 2 variações'));
    assert.ok(! um.includes('data-produtos-a-excluir') && ! um.includes('data-montadas-que-saem') && ! um.includes('data-em-uso'), 'um só, sem kit e sem uso: só a frase');

    // A janela: fechada não desenha; aberta, antes de a prévia chegar, não oferece excluir.
    assert.equal(html(h(mod.default, { aberta: false, ids: [1], onFechar: () => {}, onExcluidos: () => {} })), '');
    assert.equal(html(h(mod.default, { aberta: true, ids: [], onFechar: () => {}, onExcluidos: () => {} })), '', 'sem produto, sem janela');
    const abrindo = html(h(mod.default, { aberta: true, ids: [1, 2], onFechar: () => {}, onExcluidos: () => {} }));
    assert.match(abrindo, /data-janela="Excluir produtos"/);
    assert.ok(! abrindo.includes('confirmar-exclusao-produtos') && abrindo.includes('data-acao="fechar-exclusao"'));
});

// ─── A seleção na lista ─────────────────────────────────────────────────

test('a barra da seleção some sem nada marcado e conta o que está marcado', async () => {
    const mod = await montar('resources/js/Components/Portal/Estrutura/Produtos/BarraDeSelecao.jsx', 'excluir-barra');

    assert.equal(html(h(mod.default, { quantidade: 0, onLimpar: () => {}, onExcluir: () => {} })), '');
    const barra = html(h(mod.default, { quantidade: 3, onLimpar: () => {}, onExcluir: () => {} }));
    for (const t of ['3 produtos selecionados', 'Limpar seleção', 'Excluir selecionados']) assert.ok(texto(barra).includes(t), `faltou: ${t}`);
    assert.ok(barra.includes('data-acao="excluir-selecionados"') && barra.includes('data-acao="limpar-selecao"'));

    assert.match(html(h(mod.SelecionarPagina, { marcada: true, onAlternar: () => {} })), /role="checkbox" aria-checked="true"/);
    assert.match(html(h(mod.SelecionarPagina, { marcada: false, onAlternar: () => {} })), /role="checkbox" aria-checked="false"/);
});

test('os dois cartões ganham a caixa de seleção e o cartão marcado se distingue', async () => {
    const { linhaDoServidor } = await import('../../resources/js/lib/produtosEstrutura.js');
    global.__PROPS__ = { modulos: MODULOS };
    const variacoes = [linha(11, 2, 'Cadeira Polo', 'CAD-NT', 'Natural'), linha(12, 2, 'Cadeira Polo', 'CAD-PT', 'Preto', false)].map((l) => linhaDoServidor(l, VOCAB.pendencias));

    for (const arquivo of ['CartaoProdutoGrande', 'CartaoProdutoLinha']) {
        const { default: Cartao } = await montar(`resources/js/Components/Portal/Estrutura/Produtos/${arquivo}.jsx`, `excluir-${arquivo}`);
        const base = { produtoId: 2, variacoes, vocabulario: VOCAB, consultando: new Set(), onAbrir: () => {} };

        const semSelecao = html(h(Cartao, base));
        assert.ok(! semSelecao.includes('data-selecionar-produto') && ! semSelecao.includes('data-selecionado'), `${arquivo}: sem a seleção, o cartão é o de antes`);

        const desmarcado = html(h(Cartao, { ...base, onSelecionar: () => {}, onExcluir: () => {} }));
        assert.match(desmarcado, /role="checkbox" aria-checked="false" aria-label="Selecionar Cadeira Polo" data-nao-abrir="true" data-selecionar-produto="true"/);
        assert.ok(! desmarcado.includes('data-selecionado'));

        const marcado = html(h(Cartao, { ...base, selecionado: true, onSelecionar: () => {}, onExcluir: () => {} }));
        assert.match(marcado, /aria-checked="true"/);
        assert.match(marcado, /data-selecionado="sim"/);
        assert.ok(texto(marcado).includes('Cadeira Polo') && texto(marcado).includes('CAD-NT') && texto(marcado).includes('CAD-PT'));
    }
});

// ─── A página inteira ───────────────────────────────────────────────────

test('a página de Produtos desenha com a seleção, sem a barra enquanto nada está marcado', async () => {
    const { default: Pagina } = await montar('resources/js/Pages/Portal/EstruturaProdutos.jsx', 'excluir-pagina');
    global.__PROPS__ = { modulos: MODULOS, flash: {} };
    const props = {
        empresa: { id: 1, nome: 'Loja Teste' }, modulos: MODULOS,
        produtos: {
            linhas: [linha(10, 1, 'Mesa Polo', 'MESA-1', 'Natural'), linha(11, 2, 'Cadeira Polo', 'CAD-NT', 'Natural'), linha(12, 2, 'Cadeira Polo', 'CAD-PT', 'Preto', false)],
            paginacao: { pagina: 1, paginas: 1, total: 2 }, tem_produtos: true,
        },
        filtros: { q: '' }, vocabulario: VOCAB, ml_conectado: false, frete_tabela: { vigente_desde: '2026-08-24', reputacao: 'verde' },
        limites: { colar: 200, arquivo_mb: 2, linhas_arquivo: 1000 }, listas: { familias: [], ambientes: [] },
    };

    const pagina = html(h(Pagina, props));
    assert.ok(texto(pagina).includes('Selecionar todos desta página'));
    assert.equal(contar(pagina, /data-selecionar-produto="true"/g), 2, 'uma caixa por produto (a cadeira tem duas cores, um cartão)');
    assert.ok(! pagina.includes('data-barra-selecao') && ! pagina.includes('data-janela="Excluir'), 'nada marcado: sem barra e sem janela');

    // Sem produtos não há o que selecionar.
    const vazia = html(h(Pagina, { ...props, produtos: { linhas: [], paginacao: { pagina: 1, paginas: 1, total: 0 }, tem_produtos: false } }));
    assert.ok(! vazia.includes('selecionar-pagina') && ! vazia.includes('data-selecionar-produto'));
    assert.ok(texto(vazia).includes('Cadastre seus produtos uma vez'));
});
