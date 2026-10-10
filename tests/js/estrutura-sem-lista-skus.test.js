import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import { lerSemComentarios } from './_fonte.js';
import { textoDaVariacao } from '../../resources/js/lib/portalSubmodulos.js';

// ═══════════════════════════════════════════════════════════════════════════
// Textos do Portal para quem não vê a Lista SKUs, e um padrão só de nome/SKU (10/10/2026).
//
// POR QUE EXISTE: desde 09/10 o cliente vê só Produtos, Planejamento, Precificação e
// Mapeamento (`VisibilidadeDoMapeamento`). Tela que manda o cliente "para a Lista SKUs"
// aponta para um lugar que ele não tem — o vazio da Precificação, a frase "cada variação
// vira uma oferta na Lista SKUs" de Produtos e da ficha. E o "Como funciona" e o combo da
// Lista mostravam o padrão antigo de nome/SKU, diferente do que o Planejamento gera.
//
// As páginas são desenhadas de verdade (React no servidor, imports reais): uma variável
// sem declarar no caminho do render estoura aqui, não na tela preta (learnings §33).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

global.route = (nome, params) => '/' + nome + (params && Object.keys(params).length ? '?' + new URLSearchParams(params).toString() : '');
global.window = {
    location: { href: 'http://localhost/portal/estrutura/precificacao', search: '' },
    history: { state: null, replaceState: () => {}, go: () => {}, back: () => {} },
    addEventListener: () => {},
    removeEventListener: () => {},
};
global.__PROPS__ = {};

const STUB_INERTIA = path.join(__dirname, `.sem-lista-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, replace, preserveState, preserveScroll, only, method, as, data, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
const nada = () => () => {};
export const router = { get: () => {}, post: () => {}, put: () => {}, patch: () => {}, delete: () => {}, reload: () => {}, visit: () => {}, on: nada };
export function usePage() { return { props: globalThis.__PROPS__ }; }
`, 'utf8');

const STUB_LAYOUT = path.join(__dirname, `.sem-lista-layout-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_LAYOUT, `
import React from 'react';
export default function PortalClienteLayout({ children, titulo }) {
    return React.createElement('main', { 'data-titulo': titulo }, children);
}
`, 'utf8');

const STUB_JANELA = path.join(__dirname, `.sem-lista-janela-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_JANELA, `
import React from 'react';
export default function Janela({ aberta, titulo, children }) {
    if (! aberta) return null;
    return React.createElement('section', { 'data-janela': titulo }, children);
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
        // Pacotes de node_modules ficam de fora (o Node os carrega): os CJS dentro do bundle ESM
        // quebram com "Dynamic require of react".
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

// ─── Quem vê o quê (a prop `modulos` como o servidor manda) ─────────────────

const SUB = (chave, rotulo, extra = {}) => ({ chave, rotulo, url: `/portal/estrutura/${chave}`, ativo: false, em_breve: false, oculto: false, ...extra });
const menu = (subs) => [{ chave: 'inicio', rotulo: 'Início', submodulos: [] }, { chave: 'estrutura', rotulo: 'Mapeamento Estrutural', ativo: true, submodulos: subs }];
const CLIENTE = menu([SUB('produtos', 'Produtos'), SUB('sugestoes', 'Planejamento'), SUB('precificacao', 'Precificação'), SUB('mapeamento', 'Mapeamento')]);
const EQUIPE = menu([SUB('produtos', 'Produtos'), SUB('sugestoes', 'Planejamento'), SUB('lista', 'Lista SKUs'), SUB('precificacao', 'Precificação'),
    SUB('anuncios', 'Anúncios'), SUB('planejamento', 'Cronograma'), SUB('mapeamento', 'Mapeamento')]);
// A Lista aberta por link: aparece no menu só porque a pessoa está nela, e não conta como vista.
const CLIENTE_NA_LISTA = menu([SUB('produtos', 'Produtos'), SUB('lista', 'Lista SKUs', { oculto: true, ativo: true })]);

// Sigilo do Portal: nada da plataforma no que o cliente lê.
const PROIBIDO = /Mercado Livre|mercado livre|an[uú]ncio|Publicador|\bpublic(ar|ação|ações|ado|ados|ada)\b|\bMLB?\b/i;

// ─── 2. A frase da variação ─────────────────────────────────────────────────

test('a frase da variação cita a Lista SKUs só para quem a vê; para o cliente, Planejamento e Precificação', () => {
    assert.deepEqual(textoDaVariacao(EQUIPE), {
        frase: 'Cada variação vira uma oferta na Lista SKUs.',
        passo: 'Cada variação já vira uma oferta na Lista SKUs.',
    }, 'quem vê a Lista mantém a frase de antes');

    const cliente = textoDaVariacao(CLIENTE);
    assert.equal(cliente.frase, 'Cada variação vira uma oferta que você precifica em Precificação.');
    assert.equal(cliente.passo, 'Cada variação já vira uma oferta: monte os kits no Planejamento e calcule o preço em Precificação.');
    for (const t of Object.values(cliente)) {
        assert.doesNotMatch(t, /Lista SKUs/);
        assert.doesNotMatch(t, PROIBIDO);
    }
    assert.deepEqual(textoDaVariacao(CLIENTE_NA_LISTA), cliente, 'a Lista escondida não conta como vista');
    assert.deepEqual(textoDaVariacao(undefined), cliente);
});

// ─── 1. Precificação vazia ──────────────────────────────────────────────────

const PARAMETROS = { comissao_classico: 12, comissao_premium: 17, imposto: 6, margem_contribuicao: 10, lucro_liquido: 5, acrescimo: 0 };
const precificacaoVazia = (modulos) => ({
    empresa: { nome: 'Loja', iniciais: 'LO' }, modulos,
    estrutura: { painel: { ofertas: 0 }, blocos: [], paginacao: { pagina: 1, paginas: 1, blocos: 0, por_pagina: 25 } },
    precificacao: { parametros: PARAMETROS, padroes: PARAMETROS, resumo: { total: 0, precificadas: 0, sem_custo: 0, sem_frete: 0, impossivel: 0 }, por_oferta: {} },
    filtros: { q: '' }, ml_conectado: false, frete_tabela: { vigente_desde: '2026-08-24' },
});

test('Precificação vazia: o cliente vai a Produtos e ao Planejamento; quem vê a Lista, à Lista', async () => {
    const { default: Pagina } = await montar('resources/js/Pages/Portal/EstruturaPrecificacao.jsx', 'pagina-precificacao');

    const props = precificacaoVazia(CLIENTE);
    global.__PROPS__ = props;
    const cliente = html(h(Pagina, props));
    assert.ok(cliente.includes('data-vazio'), 'empresa sem oferta desenha o vazio');
    assert.ok(texto(cliente).includes('Os produtos vêm de Produtos e do Planejamento'));
    assert.ok(texto(cliente).includes('Cadastre os produtos e monte os kits no Planejamento; cada oferta aparece aqui para você informar custo e frete.'));
    assert.match(cliente, /<a href="\/portal\.auth\.estrutura\.produtos"[^>]*data-acao="ir-produtos"[^>]*>\s*Ir para Produtos\s*<\/a>/);
    assert.ok(! cliente.includes('Lista SKUs') && ! cliente.includes('portal.auth.estrutura.lista'), 'nada da Lista para quem não a vê');

    const naLista = precificacaoVazia(CLIENTE_NA_LISTA);
    global.__PROPS__ = naLista;
    assert.ok(html(h(Pagina, naLista)).includes('data-acao="ir-produtos"'), 'a Lista escondida não conta como vista');

    const equipe = precificacaoVazia(EQUIPE);
    global.__PROPS__ = equipe;
    const daEquipe = html(h(Pagina, equipe));
    assert.ok(texto(daEquipe).includes('Os produtos vêm da Lista SKUs'), 'quem vê a Lista mantém a versão de antes');
    assert.match(daEquipe, /<a href="\/portal\.auth\.estrutura\.lista"[^>]*data-acao="ir-lista"[^>]*>\s*Ir para a Lista SKUs\s*<\/a>/);
    assert.ok(! daEquipe.includes('data-acao="ir-produtos"'));
});

test('o texto novo da Precificação vazia não fala da plataforma', () => {
    const fonte = lerSemComentarios('resources/js/Pages/Portal/EstruturaPrecificacao.jsx');
    const vazio = fonte.slice(fonte.indexOf('function EstadoVazio'), fonte.indexOf('export default function EstruturaPrecificacao'));
    assert.ok(vazio.length > 0 && vazio.includes("route('portal.auth.estrutura.produtos')"));
    assert.equal(vazio.match(PROIBIDO), null, `o vazio cita "${vazio.match(PROIBIDO)?.[0]}"`);
});

// ─── 2. Produtos e a ficha ──────────────────────────────────────────────────

const produtosVazio = (modulos) => ({
    empresa: { nome: 'Loja', iniciais: 'LO' }, modulos,
    produtos: { linhas: [], tem_produtos: false, paginacao: { pagina: 1, paginas: 1, total: 0 } },
    filtros: { q: '' }, vocabulario: { pendencias: {}, logisticas: {}, eixos: {} }, ml_conectado: false,
    frete_tabela: { vigente_desde: '2026-08-24', reputacao: 'verde' }, limites: {}, listas: { familias: [], ambientes: [] },
});

test('Produtos: o cabeçalho e o vazio dizem onde a variação vai parar, pela régua de quem vê', async () => {
    const { default: Pagina } = await montar('resources/js/Pages/Portal/EstruturaProdutos.jsx', 'pagina-produtos');

    const props = produtosVazio(CLIENTE);
    global.__PROPS__ = props;
    const cliente = texto(html(h(Pagina, props)));
    assert.ok(cliente.includes('Cadastre cada produto uma vez, com medidas, peso e custo. Cada variação vira uma oferta que você precifica em Precificação.'));
    assert.ok(cliente.includes('Aqui ficam os produtos que você vende, com medidas, peso e custo. Cada variação vira uma oferta que você precifica em Precificação. Cadastre um produto por vez'));
    assert.ok(! cliente.includes('Lista SKUs'), 'nada da Lista para o cliente');

    const equipe = produtosVazio(EQUIPE);
    global.__PROPS__ = equipe;
    const daEquipe = texto(html(h(Pagina, equipe)));
    assert.ok(daEquipe.includes('com medidas, peso e custo. Cada variação vira uma oferta na Lista SKUs.'), 'quem vê a Lista mantém a frase');
});

test('o "Como funciona" de Produtos e a ficha usam a mesma frase, sem citar a Lista fixo', () => {
    const produtos = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutos.jsx');
    assert.ok(produtos.includes("import { textoDaVariacao } from '@/lib/portalSubmodulos'"));
    assert.ok(produtos.includes('const variacaoVira = textoDaVariacao(modulos);'));
    assert.ok(produtos.includes('`3. ${variacaoVira.passo}`'), 'o passo 3 do "Como funciona" segue a régua');

    const ficha = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutoFicha.jsx');
    assert.ok(ficha.includes("import { textoDaVariacao } from '@/lib/portalSubmodulos'"));
    assert.ok(ficha.includes('const variacaoVira = textoDaVariacao(modulos);'));
    assert.ok(ficha.includes('Preencha o produto uma vez. {variacaoVira.frase}'));
    assert.ok(ficha.includes('<span className="hidden sm:inline">{variacaoVira.frase}</span>'));

    for (const [nome, fonte] of [['Produtos', produtos], ['ficha', ficha]]) {
        assert.ok(! fonte.includes('vira uma oferta na Lista SKUs'), `${nome}: a frase fixa da Lista saiu`);
    }
});

// ─── 3. Exemplos do "Como funciona" ─────────────────────────────────────────

test('o "Como funciona" mostra os exemplos no padrão do Planejamento, não no da planilha', () => {
    const aula = lerSemComentarios('resources/js/Components/Portal/Estrutura/ComoFunciona.jsx');
    // Padrão antigo: "A+B-KIT" e "-CBT{n}" — o sistema não gera mais.
    assert.doesNotMatch(aula, /\+CAD-01-KIT|-KIT'|-CBT\d|\bCBT\b/);
    // Combo `-CB{n}`, Kit `KT-…`, Combit `CT{n}-…` (o NomesSugeridosTest confere contra a função).
    for (const [nome, sku] of [
        ['Kit 2 Cadeiras 01', 'CAD-01-CB2'],
        ['Mesa Marfim + Cadeira 01', 'KT-MSA-MR-CAD-01'],
        ['Mesa Marfim + 4 Cadeiras', 'CT4-MSA-MR-CAD-01'],
    ]) {
        assert.ok(aula.includes(`'${nome}', '${sku}'`), `faltou o exemplo ${nome} · ${sku}`);
    }
});

// ─── 4. O combo de uma quantidade da Lista pede o nome ao servidor ──────────

test('Lista SKUs: o combo de uma quantidade pede nome e SKU à prévia do Planejamento', () => {
    const form = lerSemComentarios('resources/js/Components/Portal/Estrutura/FormOferta.jsx');
    // A mesma prévia do Kit/Combit, com o produto como único item (o servidor resolve a oferta).
    assert.ok(form.includes('corpoDaSugestaoKit([{ id: base.id, quantidade: qtds[0] }])'));
    assert.equal((form.match(/route\('portal\.auth\.estrutura\.sugestoes\.montar\.previa'\)/g) ?? []).length, 2, 'combo e kit pela mesma prévia');
    assert.ok(form.includes('if (! nomeMexido) setNome(data.sugerido.nome);'));
    // A resposta velha não pisa no que a pessoa digitou: o efeito reage ao "mexido" e se cancela.
    assert.ok(form.includes('}, [qtdCombo, aberta, skuMexido, nomeMexido]);'));
    // Até o servidor responder, o SKU `-CB{n}` já aparece (é o mesmo do servidor).
    assert.ok(form.includes('setSku(`${base.sku}-CB${qtds[0]}`)'));
});
