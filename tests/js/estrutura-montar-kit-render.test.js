import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';

// ═══════════════════════════════════════════════════════════════════════════
// Render de verdade (React no servidor) das telas do "Montar kit", do funil do
// Mapeamento e das duas páginas que os recebem (09/10/2026).
//
// POR QUE EXISTE: no 168-19 uma variável sem declarar deixou a tela PRETA e passou
// no build e nos gates que leem o código como texto (learnings §33). Aqui o módulo é
// compilado com os imports reais e desenhado com props no formato do servidor: um
// nome errado no caminho do render estoura o teste.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

global.route = (nome, params) => '/' + nome + (params && Object.keys(params).length ? '?' + new URLSearchParams(params).toString() : '');
global.window = {
    location: { href: 'http://localhost/portal/estrutura/sugestoes', search: '' },
    history: { state: null, replaceState: () => {}, go: () => {}, back: () => {} },
    addEventListener: () => {},
    removeEventListener: () => {},
};
global.__PROPS__ = {};

const STUB_INERTIA = path.join(__dirname, `.montar-kit-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, replace, preserveState, preserveScroll, only, method, as, data, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
const nada = () => () => {};
export const router = { get: () => {}, post: () => {}, put: () => {}, patch: () => {}, delete: () => {}, reload: () => {}, visit: () => {}, on: nada };
export function usePage() { return { props: globalThis.__PROPS__ }; }
export function Deferred({ data, fallback, children }) {
    const chaves = Array.isArray(data) ? data : [data];
    const prontas = chaves.every((k) => globalThis.__PROPS__[k] !== undefined);
    if (! prontas) return typeof fallback === 'function' ? fallback() : fallback;
    return typeof children === 'function' ? children() : children;
}
`, 'utf8');

const STUB_LAYOUT = path.join(__dirname, `.montar-kit-layout-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_LAYOUT, `
import React from 'react';
export default function PortalClienteLayout({ children, titulo }) {
    return React.createElement('main', { 'data-titulo': titulo }, children);
}
`, 'utf8');

// A janela do Radix não desenha no servidor (portal): aqui ela vira uma <section> com o título.
const STUB_JANELA = path.join(__dirname, `.montar-kit-janela-stub-${process.pid}.mjs`);
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

// ─── Props no formato do servidor (encolhidas de uma resposta real) ─────

const SUB = (chave, rotulo, extra = {}) => ({ chave, rotulo, url: `/portal/estrutura/${chave}`, ativo: false, em_breve: false, oculto: false, ...extra });
const MODULOS_CLIENTE = [
    { chave: 'inicio', rotulo: 'Início', submodulos: [] },
    { chave: 'estrutura', rotulo: 'Mapeamento Estrutural', ativo: true, submodulos: [
        SUB('produtos', 'Produtos'), SUB('sugestoes', 'Planejamento', { ativo: true }), SUB('precificacao', 'Precificação'), SUB('mapeamento', 'Mapeamento'),
    ] },
];
const MODULOS_EQUIPE = [
    { chave: 'estrutura', rotulo: 'Mapeamento Estrutural', ativo: true, submodulos: [
        SUB('produtos', 'Produtos'), SUB('sugestoes', 'Planejamento'), SUB('lista', 'Lista SKUs'), SUB('precificacao', 'Precificação'),
        SUB('anuncios', 'Anúncios'), SUB('planejamento', 'Cronograma'), SUB('mapeamento', 'Mapeamento', { ativo: true }),
    ] },
];
const VOCAB_SUG = { fases: { combo: 'Combo', kit: 'Kit', combit: 'Combit' }, logisticas: { me2_full: 'ME2 · Full', me2: 'ME2', me1: 'ME1', pendente: 'Pendente' } };

const PREVIA = {
    pronto: true, mensagem: null, fase: 'combit',
    itens: [
        { item: 'v1', variacao_id: 1, oferta_id: 1, produto_id: 1, produto_nome: 'Mesa Polo', valor: 'Natural', nome: 'Mesa Polo — Natural', sku: 'V101', quantidade: 1, tipo: 'mesa', tipo_nome: 'Mesa', familia: 'Polo', estoque: 3, custo: 300 },
        { item: 'v3', variacao_id: 3, oferta_id: 3, produto_id: 2, produto_nome: 'Cadeira Polo', valor: 'Natural', nome: 'Cadeira Polo — Natural', sku: 'V201', quantidade: 4, tipo: 'cadeira', tipo_nome: 'Cadeira', familia: 'Polo', estoque: 10, custo: 80 },
    ],
    ja_existe: null,
    sugerido: { nome: 'Mesa Polo + 4 Cadeiras — Natural', sku: 'CT4-V101-V201' },
    nome: 'Mesa Polo + 4 Cadeiras — Natural', sku: 'CT4-V101-V201',
    avisos: [], sku_repetido: false,
    logistica: { chave: 'me2', pacote: { c: 160, l: 90, a: 95, peso_real: 64 }, peso_faturado: 228, sem_medida: [] },
    frete: { valor: 89.9, origem: 'tabela_ecf' },
    custo: 620,
    estoque: { unidades: 2, limitante: { nome: 'Cadeira Polo — Natural', estoque: 10, por_unidade: 4 }, sem_informacao: [] },
    limites: { max_titulo: 60, max_sku: 120, max_componentes: 6 },
};

const CATALOGO = {
    max_componentes: 6,
    produtos: [
        { produto_id: 1, nome: 'Mesa Polo', familia: 'Polo', tipo_nome: 'Mesa', variacoes: [{ variacao_id: 1, valor: 'Natural', sku: 'V101', estoque: 3 }, { variacao_id: 2, valor: 'Preto', sku: 'V102', estoque: null }] },
        { produto_id: 2, nome: 'Cadeira Polo', familia: 'Polo', tipo_nome: 'Cadeira', variacoes: [{ variacao_id: 3, valor: 'Natural', sku: 'V201', estoque: 10 }] },
    ],
    avulsas: [{ oferta_id: 55, sku: 'AV-B', nome: 'Banco Antigo' }],
};

const ITEM_SUGESTAO = {
    chave: 'v1*1+v3*1', fase: 'kit', familia: { id: 1, nome: 'Polo' }, ambientes: ['Sala de jantar'],
    itens: [
        { variacao_id: 1, produto_id: 1, produto_nome: 'Mesa Polo', valor: 'Natural', sku: 'V101', quantidade: 1, tipo: 'mesa', tipo_nome: 'Mesa' },
        { variacao_id: 3, produto_id: 2, produto_nome: 'Cadeira Polo', valor: 'Natural', sku: 'V201', quantidade: 1, tipo: 'cadeira', tipo_nome: 'Cadeira' },
    ],
    nome: 'Mesa Polo + Cadeira Polo — Natural', sku: 'KT-V101-V201', porque: 'Mesma família: Polo. Par: mesa + cadeira.',
    avisos: [], sku_repetido: false,
    logistica: { chave: 'me1', pacote: { c: 160, l: 90, a: 35, peso_real: 46 }, peso_faturado: 84, sem_medida: [] },
    frete: { valor: null }, custo: 380, descartada_em: null,
};

const SUGESTOES = {
    aba: 'sugestoes', tem_produtos: true,
    contagens: { sugestoes: 1, sem_tipo: 0, descartadas: 0 },
    por_fase: { todas: 1, combo: 0, kit: 1, combit: 0 }, por_status: { todas: 1, prontas: 1, com_aviso: 0 },
    resumo: { total: 1, combo: 0, kit: 1, combit: 0 }, familia_ambientes: { 1: ['Sala de jantar'] }, gerado_em: '2026-10-09T12:00:00-03:00',
    familias: [{ valor: '1', nome: 'Polo', total: 1 }], tipos: [{ id: 1, slug: 'mesa', nome: 'Mesa', plural: 'Mesas', qtd_combo: '0', qtd_combit: '0' }],
    itens: [ITEM_SUGESTAO], produtos_sem_tipo: [],
    produtos: { 1: { id: 1, nome: 'Mesa Polo', tipo: 'mesa', tipo_nome: 'Mesa', candidatos: [], familia: 'Polo', ambientes: [], categoria: null } },
    familia_totais: { 1: 1 }, familia_continua: null,
    paginacao: { pagina: 1, paginas: 1, total: 1, blocos: 1, linhas: 1, por_pagina: 20 },
    grupos: [{ chave: '1', nome: 'Polo', combos: null }], combos_recolhidos: true, chaves_filtradas: [], excedeu_teto: false, teto: 5000,
    limites: { max_titulo: 60, max_sku: 120, lote: 100, por_pagina: 20 },
};

const FUNIL = {
    produtos: { total: 10, pendentes: 4, com_pendencia: 3, ficha_incompleta: 1 },
    planejamento: { sugestoes: 29, sem_tipo: 1 },
    precificacao: { total: 22, precificadas: 9, sem_custo: 9, sem_frete: 4, impossivel: 0, pendentes: 13 },
    venda: { ofertas: 22, a_venda: 3, completas: 1, sem_nada: 19 },
};

const PROIBIDO = /Mercado Livre|an[uú]ncio|Publicador|\bpublic(ar|ação|ado|ados)\b/i;

// ─── Janela do Montar kit ───────────────────────────────────────────────

test('MontarKitAMao desenha a escolha, a prévia e o "já existe" sem falar da plataforma', async () => {
    const mod = await montar('resources/js/Components/Portal/Estrutura/Sugestoes/MontarKitAMao.jsx', 'montar-kit');
    const MontarKitAMao = mod.default;
    global.__PROPS__ = { modulos: MODULOS_CLIENTE };

    const carregando = html(h(MontarKitAMao, { aberta: true, catalogo: null, onFechar: () => {}, onCarregar: () => {}, vocabulario: VOCAB_SUG }));
    assert.match(carregando, /Carregando seus produtos/);
    assert.match(carregando, /data-janela="Montar kit"/);

    const comCatalogo = texto(html(h(MontarKitAMao, { aberta: true, catalogo: CATALOGO, onFechar: () => {}, vocabulario: VOCAB_SUG })));
    for (const t of ['Mesa Polo', 'Natural', 'V102', 'sem estoque informado', '10 em estoque', 'Outras ofertas, sem produto cadastrado', 'Banco Antigo', 'Composição', 'Criar oferta']) {
        assert.ok(comCatalogo.includes(t), `faltou: ${t}`);
    }
    assert.equal(comCatalogo.match(PROIBIDO), null);
    assert.equal(html(h(MontarKitAMao, { aberta: false, catalogo: CATALOGO, onFechar: () => {} })), '');

    const previa = texto(html(h(mod.PreviaDoKit, { previa: PREVIA, calculando: false, nome: null, sku: null, onNome: () => {}, onSku: () => {}, vocabulario: VOCAB_SUG })));
    for (const t of ['Combit', 'Nome sugerido', 'SKU sugerido', 'ME2', 'Frete estimado', 'R$', 'Custo do conjunto', 'Terá estoque: dá para montar 2 unidades', 'Quem limita: Cadeira Polo — Natural']) {
        assert.ok(previa.includes(t), `faltou: ${t}`);
    }
    assert.equal(previa.match(PROIBIDO), null);

    const existe = texto(html(h(mod.PreviaDoKit, { previa: { ...PREVIA, ja_existe: { sku: 'MEU-KIT' }, sku_repetido: true, avisos: [{ codigo: 'titulo_longo', valor: 63 }] }, nome: 'x', sku: null, onNome: () => {}, onSku: () => {}, vocabulario: VOCAB_SUG })));
    assert.ok(existe.includes('Essa combinação já existe: SKU MEU-KIT'));
    assert.ok(existe.includes('Já existe uma oferta com este SKU') && existe.includes('o ideal é até 60') && existe.includes('Usar o sugerido'));

    const pendente = texto(html(h(mod.PreviaDoKit, {
        previa: { ...PREVIA, logistica: { chave: 'pendente', pacote: null, peso_faturado: null, sem_medida: [{ id: 3, nome: 'Banco Polo' }, { id: null, nome: 'Banco Antigo' }] }, custo: null, estoque: { unidades: null, limitante: null, sem_informacao: ['Banco Antigo'] } },
        nome: null, sku: null, onNome: () => {}, onSku: () => {}, vocabulario: VOCAB_SUG,
    })));
    assert.ok(pendente.includes('Faltam medidas em Banco Polo , Banco Antigo') || pendente.includes('Faltam medidas em Banco Polo, Banco Antigo'));
    assert.ok(pendente.includes('Falta o custo de algum item') && pendente.includes('Estoque não informado'));

    const incompleta = texto(html(h(mod.PreviaDoKit, { previa: { pronto: false, mensagem: 'Escolha os produtos que entram juntos.' }, onNome: () => {}, onSku: () => {} })));
    assert.ok(incompleta.includes('Escolha os produtos que entram juntos.'));
});

// ─── Funil do Mapeamento ────────────────────────────────────────────────

test('FunilDoMapeamento: quatro cartões e só os links das telas que a pessoa vê', async () => {
    const { default: Funil, FunilCarregando } = await montar('resources/js/Components/Portal/Estrutura/FunilDoMapeamento.jsx', 'funil');

    global.__PROPS__ = { modulos: MODULOS_CLIENTE };
    const cliente = html(h(Funil, { funil: FUNIL }));
    const t = texto(cliente);
    for (const s of ['O que falta', 'Produtos', 'com cadastro a completar', '3 com dados faltando', '1 com ficha técnica incompleta', '10 produtos no total',
        'Planejamento', '29', 'combinações para revisar', '1 produto sem tipo', 'Precificação', '13', 'ofertas sem preço fechado', '9 sem custo', '4 sem frete',
        'À venda', 'de 22 ofertas', '1 completa', '19 ainda não estão à venda']) {
        assert.ok(t.includes(s), `faltou: ${s}`);
    }
    assert.ok(cliente.includes('href="/portal.auth.estrutura.produtos"') && cliente.includes('href="/portal.auth.estrutura.sugestoes"') && cliente.includes('href="/portal.auth.estrutura.precificacao"'));
    assert.equal(t.match(PROIBIDO), null);

    // A ECF tirou a Precificação desta pessoa: o cartão fica, o link não.
    global.__PROPS__ = { modulos: [{ chave: 'estrutura', submodulos: [SUB('produtos', 'Produtos'), SUB('mapeamento', 'Mapeamento')] }] };
    const semPrecificacao = html(h(Funil, { funil: FUNIL }));
    assert.ok(! semPrecificacao.includes('portal.auth.estrutura.precificacao') && ! semPrecificacao.includes('portal.auth.estrutura.sugestoes'));
    assert.ok(semPrecificacao.includes('portal.auth.estrutura.produtos'));

    assert.equal(html(h(Funil, { funil: null })), '');
    assert.equal(html(h(Funil, { funil: { ...FUNIL, produtos: { ...FUNIL.produtos, total: 0 }, venda: { ...FUNIL.venda, ofertas: 0 } } })), '', 'empresa vazia não ganha funil de zeros');
    assert.match(html(h(FunilCarregando)), /data-funil-carregando/);
});

// ─── As páginas inteiras ────────────────────────────────────────────────

test('a página do Planejamento desenha com o Montar kit e a Lista só para quem a vê', async () => {
    const { default: Pagina } = await montar('resources/js/Pages/Portal/EstruturaSugestoes.jsx', 'pagina-sugestoes');
    const props = { empresa: { nome: 'Loja', iniciais: 'LO' }, modulos: MODULOS_CLIENTE, sugestoes: SUGESTOES,
        filtros: { aba: 'sugestoes', fase: null, familia: null, tipo: null, status: null, q: '', combos: [] }, ml_conectado: false, vocabulario: VOCAB_SUG, montagem: null };
    global.__PROPS__ = props;

    const saida = html(h(Pagina, props));
    assert.match(saida, /data-acao="montar-kit"/);
    assert.match(saida, /Mesa Polo \+ Cadeira Polo/);

    // Tudo revisado: o cliente vai à Precificação; quem vê a Lista, à Lista.
    const vazia = { ...SUGESTOES, itens: [], grupos: [], contagens: { sugestoes: 0, sem_tipo: 0, descartadas: 2 }, resumo: { total: 0, combo: 0, kit: 0, combit: 0 } };
    global.__PROPS__ = { ...props, sugestoes: vazia };
    const cliente = html(h(Pagina, { ...props, sugestoes: vazia }));
    assert.ok(cliente.includes('As ofertas aceitas já estão na Precificação.') && cliente.includes('Precificar agora') && cliente.includes('data-acao="montar-kit-vazio"'));
    assert.ok(! cliente.includes('portal.auth.estrutura.lista'));

    // Só ofertas importadas (sem produto): sem sugestões, mas dá para montar à mão.
    const semProdutos = { ...vazia, tem_produtos: false };
    global.__PROPS__ = { ...props, sugestoes: semProdutos, montar_disponivel: true };
    const importada = html(h(Pagina, { ...props, sugestoes: semProdutos, montar_disponivel: true }));
    assert.ok(importada.includes('Cadastre seus produtos primeiro') && importada.includes('data-acao="montar-kit-vazio"') && importada.includes('data-acao="montar-kit"'));
    global.__PROPS__ = { ...props, sugestoes: semProdutos, montar_disponivel: false };
    assert.ok(! html(h(Pagina, { ...props, sugestoes: semProdutos, montar_disponivel: false })).includes('montar-kit'), 'nada para juntar, nada de Montar kit');

    const comLista = [{ chave: 'estrutura', submodulos: [SUB('produtos', 'Produtos'), SUB('sugestoes', 'Planejamento', { ativo: true }), SUB('lista', 'Lista SKUs')] }];
    global.__PROPS__ = { ...props, modulos: comLista, sugestoes: vazia };
    const equipe = html(h(Pagina, { ...props, modulos: comLista, sugestoes: vazia }));
    assert.ok(equipe.includes('As ofertas aceitas já estão na Lista SKUs.') && equipe.includes('href="/portal.auth.estrutura.lista"'));
});

test('a página do Mapeamento desenha o funil adiado e não leva o cliente a telas escondidas', async () => {
    const { default: Pagina } = await montar('resources/js/Pages/Portal/EstruturaMapeamento.jsx', 'pagina-mapeamento');
    const estrutura = (ofertas) => ({
        painel: { ofertas, por_fase: { simples: ofertas, combo: 0, kit: 0, combit: 0 }, necessarios: ofertas * 2, publicados: 0, a_publicar: ofertas * 2, completas: 0, percentual: 0 },
        anuncios_resumo: { publicados: 0, planejados: 0, sem_titulo: 0 }, contadores: { todas: ofertas, publicar: ofertas, falta: 0, completas: 0 }, espera: 0,
        blocos: [], resumo_kits: { total: 0, ok: 0, falta: 0, publicar: 0, sem_agenda: 0 },
        proximo_passo: { tipo: 'agendar', quantidade: ofertas },
        agenda: { hoje_data: '2026-10-09', contagem: { atrasadas: 0, hoje: 1, jardinagem: 0 }, totais: { atrasadas: 0, hoje: 1, proximas: 0, concluidas: 0 }, itens: [] },
        paginacao: { pagina: 1, paginas: 1, blocos: 0, por_pagina: 25 },
    });
    const VOCAB = { tipos_curtos: { classico: 'Clássico', premium: 'Premium' }, situacoes: {}, dias_ate_jardinagem: 7, logisticas: {}, fases: {}, tipos: {}, status: {}, acoes: {}, motivos: {} };
    const base = { empresa: { nome: 'Loja', iniciais: 'LO' }, filtros: { situacao: 'todas', q: '' }, vocabulario: VOCAB, ml_conectado: false };

    // Cliente, empresa vazia: o começo é Produtos (a Lista SKUs está escondida); o funil ainda não chegou.
    const cliente = { ...base, modulos: MODULOS_CLIENTE, estrutura: estrutura(0) };
    global.__PROPS__ = cliente;
    const vazio = html(h(Pagina, cliente));
    assert.ok(vazio.includes('data-acao="ir-produtos"') && ! vazio.includes('data-acao="ir-lista"'));
    assert.match(vazio, /data-funil-carregando/);

    // Com ofertas e o funil: o "Próximo passo" da agenda some para quem não vê o Cronograma.
    const comFunil = { ...cliente, estrutura: estrutura(5), funil: FUNIL };
    global.__PROPS__ = comFunil;
    const pagina = html(h(Pagina, comFunil));
    assert.match(pagina, /data-funil="true"/);
    assert.ok(! pagina.includes('data-funil-carregando'), 'com a prop, o esqueleto sai');
    assert.ok(! pagina.includes('data-proximo-passo="agendar"'), 'sem Cronograma, sem passo de agenda');
    assert.ok(! pagina.includes('href="/portal.auth.estrutura.agenda'), 'nenhum link para o Cronograma escondido');

    // A equipe vê o Cronograma: o passo e os links continuam.
    const equipe = { ...comFunil, modulos: MODULOS_EQUIPE };
    global.__PROPS__ = equipe;
    const daEquipe = html(h(Pagina, equipe));
    assert.ok(daEquipe.includes('data-proximo-passo="agendar"') && daEquipe.includes('href="/portal.auth.estrutura.agenda'));
});
