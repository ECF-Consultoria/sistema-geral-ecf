import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { readdirSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 179, plano 09 — a COMPOSIÇÃO da Visão geral (VG-01/03/19/20, EXMP-02,
// GATE-01/03, REG-01/02, FID-05, REND-01/02/03).
//
// Protótipo: `new_layout_publicador/Visao Geral.dc.html`. ⚠️ As linhas abaixo
// foram MEDIDAS no arquivo com `grep -n`; as do `<action>` do plano estão
// deslocadas (ele cita `:23`/`:26` para o container e `:161`/`:304` para as
// grades, onde o arquivo tem `:22`/`:25`-`:26` e `:164`/`:312`). Quem mediu
// ganha (FID-01) — e o placar da fase é 76 divergências, todas a favor do
// protótipo.
//
//   · raiz da página   → :22  (`min-height:100vh;min-width:1240px`)
//   · `<main>`         → :25  (`max-width:1600px;margin:0 auto;
//                              padding:24px 36px 80px;display:flex;
//                              flex-direction:column;gap:20px`)
//   · banner           → :27  (dentro de `sc-if hasBanner`)
//   · Jornada          → :40
//   · grade 3          → :164 (`minmax(0,1fr) 392px`, `gap:20px`, `align-items:start`)
//   · coluna lateral   → :234 (`white-space:nowrap;flex column;gap:20px`)
//   · faixa Desempenho → :286
//   · grade 5          → :312 (`minmax(0,1fr) minmax(0,1fr)`, `gap:20px`, `align-items:start`)
//   · fim da grade 5   → :383 · `</main>` → :384 — **nada depois dela**
//
// ⚠️ REND-03: TODOS os bundles são montados aqui no topo, ANTES do primeiro
// `test()`. O `after()` do `node --test` já apagou o stub de um arquivo deste
// projeto no meio da suíte e matou o arquivo inteiro SEM nenhum teste falhar.
//
// ⚠️ Este é o ÚNICO gate da fase que renderiza a PÁGINA COMPLETA — é aqui que a
// tela preta de 07/10/2026 (campo-objeto renderizado como texto derrubando a
// página inteira) apareceria.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

// Sem DOM nos testes deste projeto: `window` mínimo. O header lê
// `location.search` na montagem e registra ouvintes de teclado.
global.window = {
    location: { search: '', href: 'http://localhost/mlb/anuncios' },
    addEventListener: () => {},
    removeEventListener: () => {},
    history: { state: null, replaceState: () => {} },
};

const STUB_INERTIA = path.join(__dirname, `.cockpit-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, delete: () => {}, reload: () => {} };
export function usePage() { return { props: { auth: { user: { id: 7 } } } }; }
`, 'utf8');

const STUB_APPLAYOUT = path.join(__dirname, `.cockpit-applayout-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_APPLAYOUT, `
import React from 'react';
export default function AppLayout({ children }) {
    return React.createElement('div', { id: 'app-layout' }, children);
}
`, 'utf8');

after(() => {
    fs.rmSync(STUB_INERTIA, { force: true });
    fs.rmSync(STUB_APPLAYOUT, { force: true });
});

/**
 * Compila um módulo de verdade (JSX + imports reais) e devolve os exports.
 *
 * ⚠️ `'@radix-ui/react-dialog'` é OBRIGATÓRIO no `external`: este bundle
 * alcança o `PublicadorHeader`, que arrasta o `ModalNovaPublicacao` → o Dialog
 * do Radix → `react-remove-scroll`, que é CJS e estoura em
 * `Dynamic require of "react"` quando empacotado (medido na Fase 178).
 */
async function montar(caminhoRelativo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, caminhoRelativo)],
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
            'react', 'react-dom', 'react-dom/server', 'react/jsx-runtime',
            'lucide-react', 'axios', 'recharts', 'date-fns', 'date-fns/*',
            'clsx', 'tailwind-merge', 'class-variance-authority',
            '@radix-ui/react-popover', '@radix-ui/react-dialog',
            '@radix-ui/*', '@headlessui/*',
        ],
    });

    const outfile = path.join(__dirname, `.cockpit-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR = 'resources/js/Components/Mlb/Publicador/visaogeral';
const REL_PAGINA = 'resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx';
const REL_COCKPIT = `${DIR}/CockpitDaConta.jsx`;

/** Os 12 arquivos novos da fase 179 — a MESMA lista de `ARQUIVOS_NOVOS`. */
const DOZE_ARQUIVOS = [
    `${DIR}/jornada.js`,
    `${DIR}/JornadaDosProdutos.jsx`,
    `${DIR}/acoes.js`,
    `${DIR}/OQueFazerAgora.jsx`,
    `${DIR}/SaudeDaConta.jsx`,
    `${DIR}/PublicacoesNoMercadoLivre.jsx`,
    `${DIR}/recentes.js`,
    `${DIR}/BannerDeIntegracao.jsx`,
    `${DIR}/FaixaDeDesempenho.jsx`,
    `${DIR}/PublicacoesRecentes.jsx`,
    `${DIR}/AtividadeRecente.jsx`,
    REL_COCKPIT,
];

/** As 13 fontes que esta tela coloca no ar: os 12 novos + a página. */
const TREZE_FONTES = [...DOZE_ARQUIVOS, REL_PAGINA];

// ─── REND-03: os bundles, AQUI, antes de qualquer `test()` ───
const { default: Pagina } = await montar(REL_PAGINA);
const C = await montar(REL_COCKPIT);
const J = await montar(`${DIR}/jornada.js`);
const A = await montar(`${DIR}/acoes.js`);
const R = await montar(`${DIR}/recentes.js`);

const FONTES = new Map(TREZE_FONTES.map((caminho) => [caminho, lerSemComentarios(caminho)]));

// ═══════════════════════════════════════════════════════════════════════════
// Os extratores — uma ÁRVORE de elementos com índices ABSOLUTOS
//
// ⚠️ `html.indexOf(tagDeAbertura)` mede o elemento ERRADO quando dois irmãos
// têm a MESMA string de classes, e foi assim que QUATRO gates do 179-07
// ficaram medindo a linha do Mercado Livre em vez da do ERP — passando verdes.
// Esta página é feita de irmãos idênticos (2 grades, 4 cards de estágio, 5
// linhas de recente, N eventos). Toda localização aqui é por POSIÇÃO
// ESTRUTURAL ou por TEXTO ÚNICO.
// ═══════════════════════════════════════════════════════════════════════════

function classesDe(bruto) {
    const achado = /class="([^"]*)"/.exec(bruto);
    return (achado?.[1] ?? '').split(/\s+/).filter(Boolean);
}

const VAZIOS = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr']);

function arvore(html) {
    const raiz = { nome: '#raiz', bruto: '', classes: [], filhos: [], indice: 0, inicio: 0, fim: html.length };
    const pilha = [raiz];
    const re = /<(\/)?([a-zA-Z][\w-]*)\b([^>]*?)(\/)?>/g;
    let m;

    while ((m = re.exec(html)) !== null) {
        if (m[1] === '/') {
            if (pilha.length > 1) pilha.pop().fim = m.index;
            continue;
        }

        const fim = m.index + m[0].length;
        const no = {
            nome: m[2],
            bruto: m[0],
            classes: classesDe(m[0]),
            filhos: [],
            indice: m.index,
            inicio: fim,
            fim,
        };
        pilha[pilha.length - 1].filhos.push(no);
        if (m[4] !== '/' && !VAZIOS.has(m[2])) pilha.push(no);
    }

    return raiz;
}

/** O texto DIRETO de um nó (o que vem logo depois da tag de abertura). */
function textoDireto(html, no) {
    const conteudo = html.slice(no.inicio, no.fim);
    const corte = conteudo.indexOf('<');
    return corte === -1 ? conteudo : conteudo.slice(0, corte);
}

/** O HTML de DENTRO de um nó, pelas posições absolutas. */
const conteudoDe = (html, no) => html.slice(no.inicio, no.fim);

/** Todos os descendentes, de qualquer tag, em ordem de documento. */
function todosOsNos(no) {
    const saida = [];
    for (const filho of no.filhos) {
        saida.push(filho);
        saida.push(...todosOsNos(filho));
    }
    return saida;
}

/**
 * Compara a lista de classes de UM elemento por igualdade de CONJUNTO.
 * ⚠️ Não é `includes`: classe a mais reprova, a menos reprova, trocada
 * reprova. `html.includes('classe')` não serve — SUBSTRING CASA COM PREFIXO
 * (`gap-[20px]` dentro de `gap-[20px0]`, `max-w-[1600px]` dentro de
 * `max-w-[1600px0]`).
 */
function conferirClasses(no, esperadas, onde) {
    assert.deepEqual(
        [...no.classes].sort(),
        [...esperadas.split(/\s+/).filter(Boolean)].sort(),
        `${onde}: a lista de classes divergiu (tag: ${no.bruto})`,
    );
}

const semLixo = (html, onde) => {
    assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `${onde}: lixo na tela`);
};

/** Conta ocorrências de um LITERAL (nunca regex: `Fase 3+` tem `+`). */
const contar = (texto, literal) => texto.split(literal).length - 1;

// ═══════════════════════════════════════════════════════════════════════════
// Os três nós do esqueleto: o layout, o wrapper da neutralização e o container
// ═══════════════════════════════════════════════════════════════════════════

function esqueleto(html) {
    const layout = arvore(html).filhos[0];
    assert.equal(layout.nome, 'div', 'o AppLayout stub deveria render um <div> de topo');

    const [header, externo] = layout.filhos;
    assert.equal(header.nome, 'header', 'o PublicadorHeader deveria ser o PRIMEIRO filho (HEAD-14)');
    assert.equal(layout.filhos.length, 2, `o layout deveria ter exatamente 2 filhos (header + corpo), achei ${layout.filhos.length}`);
    assert.equal(externo.nome, 'div');

    assert.equal(externo.filhos.length, 1, 'o wrapper da neutralização deveria ter UM filho (o container)');
    const interno = externo.filhos[0];

    return { layout, header, externo, interno };
}

// ═══════════════════════════════════════════════════════════════════════════
// Fixtures — as 23 props do contrato do 179-05
//
// ⚠️ `produtosPorFase` e `quemPublicou` vêm PREENCHIDAS de propósito: é a
// única forma de a asserção negativa dos dois blocos removidos provar algo.
// Com elas vazias a ausência seria vazia POR CONSTRUÇÃO — a mesma classe de
// furo do `git diff --stat` sem range.
// ═══════════════════════════════════════════════════════════════════════════

const itemDaJornada = (id, nome, sku) => ({
    produto_id: id,
    nome,
    sku,
    // ⚠️ `nota` NÃO carrega "Fase 1 publicada" aqui: aquele literal é o rótulo
    // do estágio `ready` da própria Jornada (`jornada.js:73`) e a colisão
    // atrapalharia a medição do bloco removido (ver o teste H).
    nota: 'Publicada · 2 no ar',
    capa: null,
    cobertura: { f1_classico: true, f1_premium: true, f2_classico: false, f2_premium: false },
    cta_chave: 'criar_combo',
    url: `/editor/${id}`,
});

const JORNADA = () => ({
    total: 32,
    publicados: 24,
    pct: 75,
    tem_produtos: true,
    proximo_passo: 'ready',
    estagios: [
        { chave: 'none', total: 6, share_pct: 19, itens: [itemDaJornada(1, 'Espelho Redondo Bisotê 60 cm', 'ESP-RD-60')], ver_todos_url: '/produtos?f=none' },
        { chave: 'draft', total: 2, share_pct: 6, itens: [itemDaJornada(2, 'Sofá Retrátil Lumi', 'SOFA-LUMI-3')], ver_todos_url: '/produtos?f=draft' },
        { chave: 'ready', total: 13, share_pct: 41, itens: [itemDaJornada(3, 'Cadeira Oslo Premium', 'CAD-OSLO')], ver_todos_url: '/produtos?f=ready' },
        { chave: 'done', total: 11, share_pct: 34, itens: [itemDaJornada(9, 'Poltrona Beny Veludo', 'BENY-01')], ver_todos_url: '/produtos?f=done' },
    ],
});

const JORNADA_VAZIA = () => ({
    total: 0, publicados: 0, pct: 0, tem_produtos: false, proximo_passo: null, estagios: [],
});

const itemDaAcao = (nome, meta, motivo) => ({ nome, meta, motivo });

const ACOES = () => ({
    acoes: [
        {
            chave: 'com_problema',
            severidade: 'critico',
            titulo: 'Produtos com problema na publicação',
            descricao: 'Imagem principal recusada · Título excede 60 caracteres',
            numero: 2,
            recomendada: true,
            itens: [itemDaAcao('Cadeira Office Ergo', 'F1 · Premium', 'Imagem principal recusada')],
            destino: { rota: 'mlb.anuncios.meus', params: { company: 459 } },
        },
        {
            chave: 'pausados',
            severidade: 'atencao',
            titulo: 'Anúncios pausados ou sem estoque',
            descricao: '4 por falta de estoque · 3 pausadas manualmente',
            numero: 7,
            recomendada: false,
            itens: [],
            destino: { rota: 'mlb.anuncios.meus', params: { company: 459, motivo: 'pausado' } },
        },
    ],
    contagens: { todas: 2, critico: 1, atencao: 1, oportunidade: 0 },
    adiadas: [],
    volta_em_iso: '2026-10-14T08:00:00-03:00',
});

const SAUDE = (o = {}) => ({
    geral: 'tudo_conectado',
    linhas: [
        { chave: 'mercado_livre', estado: 'ativo', conta_nome: 'ECFDEV02', conta_ml_id: '2015132968', problema: false, link_reconexao: null },
        { chave: 'erp', declarado: 'Bling', problema: false },
        { chave: 'sincronizacao', coletado_em: '2026-10-13T09:00:00-03:00', horas: 4, defasado: false, nunca_coletado: false, atualizar_url: '/mlb/anuncios/meus/459/atualizar' },
    ],
    ...o,
});

const SAUDE_ML_FORA = () => SAUDE({
    geral: 'requer_acao',
    linhas: [
        { chave: 'mercado_livre', estado: 'sem_token', conta_nome: null, conta_ml_id: null, problema: true, link_reconexao: '/mlb/reconectar/459' },
        { chave: 'erp', declarado: 'Bling', problema: false },
        { chave: 'sincronizacao', coletado_em: null, horas: null, defasado: false, nunca_coletado: true, atualizar_url: null },
    ],
});

const PUBS_ML = () => ({
    disponivel: true,
    total: 58,
    faixas: [
        { chave: 'ativas', total: 49, url: '/p?status=ativos' },
        { chave: 'pausadas', total: 7, url: '/p?status=pausados' },
        { chave: 'em_revisao', total: 2, url: '/p?status=em_revisao' },
        { chave: 'encerradas', total: 0, url: '/p?status=encerrados' },
    ],
    outras: 0,
    ativas_pct: 84,
    pedem_acao: { total: 3, url: '/p?pedem=1' },
});

const ACERVO = () => ({ imagens: 128, packs: 9 });

const DESEMPENHO = () => ({
    kpis: [
        { chave: 'publicacoes_criadas', valor: 16, de: null, delta: 5, serie: [1, 2, 3, 4, 5] },
        { chave: 'criativos_ia', valor: 9, de: null, delta: -2, serie: [0, 3, 1] },
        { chave: 'publicacoes_com_venda', valor: null, de: 150, delta: null, serie: null },
    ],
    dias: 30,
    url: null,
});

const umRecente = (extra = {}) => Object.assign({
    produto_id: 11,
    produto_nome: 'Poltrona Beny',
    titulo: 'Kit 2 Poltronas Beny Veludo Cinza',
    capa: null,
    fase: 2,
    rotulo_fase: 'Fase 2 · Combo ×2',
    tipo: 'Premium',
    ml_item_id: 'MLB4410293817',
    situacao: 'ativa',
    vendas: 0,
    quando: '2026-10-13T14:48:00-03:00',
    quem: { tipo: 'equipe', nome: 'Ana Ribeiro' },
    nova: false,
}, extra);

const RECENTES = () => ({
    disponivel: true,
    itens: [
        umRecente({ nova: true, ml_item_id: 'MLB1' }),
        umRecente({ ml_item_id: 'MLB2', fase: 1, rotulo_fase: 'Fase 1 · Simples', tipo: 'Clássico', situacao: 'em_revisao' }),
    ],
});

const ATIVIDADE = () => ({
    disponivel: true,
    grupos: [
        {
            chave: 'hoje',
            data_iso: '2026-10-13',
            eventos: [
                { tipo: 'publicou', quem: 'Ana Ribeiro', quando_iso: '2026-10-13T14:32:00-03:00', titulo: 'Poltrona Beny', detalhe: 'Fase 2 · Combo ×2' },
                { tipo: 'conferiu', quem: 'Carla Mendes', quando_iso: '2026-10-13T10:12:00-03:00', titulo: 'Cadeira Oslo', detalhe: null },
            ],
        },
    ],
});

const EMPRESA = () => ({
    chave: 'company-459',
    nome: 'Dev 02 Testes API',
    identificador: '459',
    programa: 'polos',
    programa_rotulo: 'Polos',
    company_id: 459,
    token: 'ativo',
    portal: { situacao: 'sincronizado', novas: 0 },
    link_reconexao: null,
});

const CABECALHO = () => ({
    aba: 'visao-geral',
    empresa: {
        chave: 'company-459',
        nome: 'Dev 02 Testes API',
        identificador: '459',
        programa: 'polos',
        programa_rotulo: 'Polos',
        token: 'ativo',
        portal: { situacao: 'sincronizado', novas: 0, sincronizado_em: '2026-10-12T10:00:00+00:00' },
    },
    company_id: 459,
    contadores: { produtos: 32, publicacoes: 58, publicacoes_pedem_acao: 3, alavancas: 2 },
    integracoes: {
        mercado_livre: { token: 'ativo' },
        erp: { declarado: 'Bling' },
        portal: { situacao: 'sincronizado', novas: 0, sincronizado_em: '2026-10-12T10:00:00+00:00' },
        coleta: { nunca_coletado: false, horas: 4 },
    },
});

/**
 * ⚠️ Os 5 buckets de "Produtos por fase", com os rótulos LITERAIS que
 * `PainelVisaoGeralService::produtosPorFase()` (`:703-:707`) devolve. Eles só
 * existem aqui para a asserção negativa do teste H ter o que não achar.
 */
const POR_FASE = () => ({
    sem_oferta: { numero: 6, rotulo: 'Sem oferta' },
    fase1_publicada: { numero: 13, rotulo: 'Fase 1 publicada' },
    fase2_preparacao: { numero: 2, rotulo: 'Fase 2 em preparação' },
    fase2_publicada: { numero: 9, rotulo: 'Fase 2 publicada' },
    fase3_mais: { numero: 2, rotulo: 'Fase 3+' },
});

/** ⚠️ Idem para "Quem publicou", com o `Origem antiga` de `:988`. */
const QUEM_PUBLICOU = () => ([
    { quem: 'Ana Ribeiro', total: 7, responsavel: true },
    { quem: 'Origem antiga', total: 3, responsavel: false },
]);

/** As 23 props, todas presentes e preenchidas. */
const TODAS = (o = {}) => ({
    empresa: EMPRESA(),
    liberada: true,
    indicadores: { no_ar: 49, com_venda: 12, sem_oferta: 6, publicados_30d: 16, tracao_pct: 24, criativos_packs: 9 },
    oQueFazerAgora: [],
    alertas: [],
    situacaoProdutos: { rascunho: { numero: 2, rotulo: 'Rascunho' } },
    produtosPorFase: POR_FASE(),
    ultimasPublicacoes: [],
    atividadeEquipe: [],
    integracoes: { publicacao_liberada: true, alavancas_liberada: false, portal: { situacao: 'sincronizado' }, erp: { valor: 'Bling', rotulo: 'Bling' } },
    identidadeResumo: null,
    quemPublicou: QUEM_PUBLICOU(),
    abas: { company_id: 459, alavancas_pendentes: 2 },
    cabecalho: CABECALHO(),
    jornada: JORNADA(),
    acoes: ACOES(),
    banner: null,
    saude: SAUDE(),
    publicacoesMl: PUBS_ML(),
    acervoImagens: ACERVO(),
    desempenho: DESEMPENHO(),
    recentes: RECENTES(),
    atividade: ATIVIDADE(),
    ...o,
});

/** Os nomes das 23 props — a lista FECHADA do contrato do 179-05. */
const AS_23_PROPS = Object.keys(TODAS());

const BANNER_ML = () => ({ chave: 'ml', link_reconexao: '/mlb/reconectar/459' });

const render = (props) => renderToStaticMarkup(React.createElement(Pagina, props));

// ═══════════════════════════════════════════════════════════════════════════
// A. A ORDEM VERTICAL — e que a grade 5 é o ÚLTIMO bloco
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Como cada slot é RECONHECIDO. ⚠️ Nunca pela posição (isso seria circular):
 * cada bloco é identificado pelo que ele É — a tinta do banner, o título do
 * card, o conjunto de classes da grade — e aí a POSIÇÃO é conferida.
 */
const SLOTS = [
    { nome: 'banner de integração', reconhece: (no, html) => no.classes.includes('bg-pub-danger/[0.07]') && conteudoDe(html, no).includes(R.BANNERS.ml.titulo) },
    { nome: 'Jornada dos produtos', reconhece: (no, html) => no.nome === 'section' && conteudoDe(html, no).includes(J.TITULO) },
    { nome: 'legenda do recorte', reconhece: (no, html) => no.nome === 'p' && textoDireto(html, no) === C.LEGENDA_DO_RECORTE },
    { nome: 'grade 1fr|392px', reconhece: (no) => no.classes.includes('grid-cols-[minmax(0,1fr)_392px]') },
    { nome: 'faixa de Desempenho', reconhece: (no, html) => no.nome === 'section' && conteudoDe(html, no).includes(R.TITULO_DESEMPENHO) && conteudoDe(html, no).includes(R.SUB_DESEMPENHO) },
    { nome: 'grade 1fr|1fr', reconhece: (no) => no.classes.includes('grid-cols-[minmax(0,1fr)_minmax(0,1fr)]') },
];

/**
 * O nó de um slot, localizado por IDENTIDADE (o `reconhece` de `SLOTS`) e não
 * por índice fixo. ⚠️ Índice fixo quebraria nos cenários em que o banner não
 * nasce (conta saudável) — e pior, mediria o bloco ERRADO em silêncio. O teste
 * A é o único que cobra a POSIÇÃO; os outros cobram o CONTEÚDO.
 */
function pegaSlot(interno, html, nome) {
    const slot = SLOTS.find((s) => s.nome === nome);
    assert.ok(slot, `slot "${nome}" não está em SLOTS`);
    const achados = interno.filhos.filter((no) => slot.reconhece(no, html));
    assert.equal(achados.length, 1, `esperava UM nó para o slot "${nome}", achei ${achados.length}`);
    return achados[0];
}

test('A — a ordem vertical do protótipo, e a grade 1fr|1fr é o ÚLTIMO bloco', async (contexto) => {
    const html = render(TODAS({ banner: BANNER_ML(), saude: SAUDE_ML_FORA() }));
    const { interno } = esqueleto(html);

    await contexto.test('o container tem os 6 blocos como filhos DIRETOS, um por slot', () => {
        // ⚠️ Esta contagem é o que prova que o `CockpitDaConta` NÃO embrulha os
        // blocos num wrapper próprio: um wrapper faria `filhos.length === 1` e
        // criaria um SEGUNDO nível de `gap`, quebrando o ritmo de 20px.
        assert.equal(
            interno.filhos.length,
            SLOTS.length,
            `esperava ${SLOTS.length} filhos diretos no container, achei ${interno.filhos.length} (${interno.filhos.map((f) => f.nome).join(', ')})`,
        );
    });

    for (const [posicao, slot] of SLOTS.entries()) {
        await contexto.test(`slot ${posicao + 1} é "${slot.nome}"`, () => {
            const no = interno.filhos[posicao];
            assert.ok(
                slot.reconhece(no, html),
                `o filho ${posicao + 1} do container não é "${slot.nome}" (tag: ${no.bruto.slice(0, 160)})`,
            );
        });
    }

    await contexto.test('⚠️ nada depois da grade 1fr|1fr: ela é o ÚLTIMO filho', () => {
        const ultimo = interno.filhos[interno.filhos.length - 1];
        assert.ok(
            ultimo.classes.includes('grid-cols-[minmax(0,1fr)_minmax(0,1fr)]'),
            `o último bloco da página deveria ser a grade 1fr|1fr (protótipo: fecha em :383 e o </main> é :384), mas é ${ultimo.bruto.slice(0, 160)}`,
        );
    });

    await contexto.test('as posições ABSOLUTAS crescem na ordem dos slots', () => {
        const indices = interno.filhos.map((f) => f.indice);
        assert.deepEqual(indices, [...indices].sort((a, b) => a - b));
        // E nenhum slot compartilha posição com outro (irmão idêntico).
        assert.equal(new Set(indices).size, indices.length);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// B. AS DUAS GRADES — por igualdade de CONJUNTO de classes
// ═══════════════════════════════════════════════════════════════════════════

test('B — as duas grades do protótipo, medida por medida', async (contexto) => {
    const html = render(TODAS({ banner: BANNER_ML() }));
    const { interno } = esqueleto(html);

    await contexto.test('grade 3 (`:164`): minmax(0,1fr) 392px · gap 20 · items-start', () => {
        const grade = pegaSlot(interno, html, 'grade 1fr|392px');
        conferirClasses(grade, 'grid grid-cols-[minmax(0,1fr)_392px] items-start gap-[20px]', 'grade 3');
    });

    await contexto.test('grade 3 tem DOIS filhos: as ações e a coluna lateral', () => {
        const grade = pegaSlot(interno, html, 'grade 1fr|392px');
        assert.equal(grade.filhos.length, 2);
        assert.equal(grade.filhos[0].nome, 'section');
        assert.ok(conteudoDe(html, grade.filhos[0]).includes(A.TITULO), 'a 1ª coluna da grade 3 deveria ser "O que fazer agora"');
        assert.equal(grade.filhos[1].nome, 'aside');
    });

    await contexto.test('a coluna lateral (`:234`): aside com nowrap, flex-col e gap 20', () => {
        const aside = pegaSlot(interno, html, 'grade 1fr|392px').filhos[1];
        conferirClasses(aside, 'flex flex-col gap-[20px] whitespace-nowrap', 'coluna lateral');
        assert.equal(aside.filhos.length, 2, 'a coluna lateral tem 2 cards: Saúde e Publicações no ML');
        assert.ok(conteudoDe(html, aside.filhos[0]).includes(A.TITULO_SAUDE), 'o 1º card da lateral deveria ser "Saúde da conta"');
        assert.ok(conteudoDe(html, aside.filhos[1]).includes(A.TITULO_PUBS), 'o 2º card da lateral deveria ser "Publicações no Mercado Livre"');
    });

    await contexto.test('grade 5 (`:312`): minmax(0,1fr) minmax(0,1fr) · gap 20 · items-start', () => {
        const grade = pegaSlot(interno, html, 'grade 1fr|1fr');
        conferirClasses(grade, 'grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)] items-start gap-[20px]', 'grade 5');
    });

    await contexto.test('grade 5 tem DOIS filhos: recentes e atividade, nesta ordem', () => {
        const grade = pegaSlot(interno, html, 'grade 1fr|1fr');
        assert.equal(grade.filhos.length, 2);
        assert.ok(conteudoDe(html, grade.filhos[0]).includes(R.TITULO_RECENTES));
        assert.ok(conteudoDe(html, grade.filhos[1]).includes(R.TITULO_ATIVIDADE));
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// C. O CONTAINER — a aritmética do `p-6` do AppLayout
// ═══════════════════════════════════════════════════════════════════════════

test('C — o container de 1600px e o `p-6` do AppLayout neutralizado NA PÁGINA', async (contexto) => {
    const html = render(TODAS());
    const { header, externo, interno } = esqueleto(html);

    await contexto.test('o wrapper externo só neutraliza: -mx-6 -mb-6, nada mais', () => {
        // ⚠️ Dois elementos e não um: `-mx-6` e `mx-auto` disputam a mesma
        // margem lateral, e `mx-auto` precisa da margem automática para
        // centrar os 1600px.
        conferirClasses(externo, '-mx-6 -mb-6', 'wrapper da neutralização');
    });

    await contexto.test('o container interno tem as medidas do protótipo (`:25`-`:26`)', () => {
        conferirClasses(
            interno,
            'mx-auto flex max-w-[1600px] min-w-[1240px] flex-col gap-[20px] px-[36px] pt-[24px] pb-[80px]',
            'container do corpo',
        );
    });

    await contexto.test('⚠️ o `gap-[20px]` mora em UM lugar só na cadeia do container', () => {
        assert.equal(externo.classes.filter((c) => c.startsWith('gap-')).length, 0, 'o wrapper externo não pode ter gap');
        assert.equal(
            interno.classes.filter((c) => c === 'gap-[20px]').length,
            1,
            'o container deveria declarar `gap-[20px]` exatamente uma vez',
        );
    });

    await contexto.test('o `pt-6` do <main> é cancelado pelo header, não pela página (HEAD-14)', () => {
        // O header é quem carrega o `-mt-6`; a página só cancela as laterais e
        // o rodapé. Se a página passasse a cancelar o topo também, o
        // `pt-[24px]` deixaria de ser medido da borda inferior do header.
        assert.ok(header.classes.includes('-mt-6'), 'o PublicadorHeader perdeu o -mt-6 que cancela o pt-6 do <main>');
        assert.ok(!externo.classes.includes('-mt-6'), 'a página não pode cancelar o topo: o header já o cancela');
    });

    await contexto.test('FID-05: o piso de 1240px existe e não há breakpoint novo na página', () => {
        assert.ok(interno.classes.includes('min-w-[1240px]'));
        assert.doesNotMatch(FONTES.get(REL_PAGINA), /\b(sm|md|lg|xl|2xl):/);
        assert.doesNotMatch(FONTES.get(REL_COCKPIT), /\b(sm|md|lg|xl|2xl):/);
    });

    await contexto.test('a página NÃO importa mais o PainelVisaoGeral — mas o arquivo segue no disco', () => {
        const fonte = FONTES.get(REL_PAGINA);
        assert.doesNotMatch(fonte, /import[^;]*from\s*'[^']*\/PainelVisaoGeral'/);
        assert.doesNotMatch(fonte, /<PainelVisaoGeral[\s/>]/);
        assert.match(fonte, /<CockpitDaConta\b/);
        // ⚠️ ÓRFÃO DECLARADO, não apagado: os dois gates que o renderizam
        // direto (`publicador-visao-geral-render` e `publicador-dashboard-v2`)
        // continuam verdes sem cirurgia.
        assert.ok(
            fs.existsSync(path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/PainelVisaoGeral.jsx')),
            'PainelVisaoGeral.jsx foi APAGADO — nada é apagado nesta fase (GATE-01)',
        );
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// D. A LEGENDA DO RECORTE (VG-03 / critério 1 do ROADMAP)
// ═══════════════════════════════════════════════════════════════════════════

const LEGENDA_ESPERADA =
    'Jornada, Saúde e Ações mostram o estado atual. Recentes e Atividade mostram os últimos 30 dias.';

test('D — a UI DIZ o recorte de cada bloco, byte a byte', async (contexto) => {
    const html = render(TODAS({ banner: BANNER_ML() }));
    const { interno } = esqueleto(html);

    await contexto.test('a constante do cockpit é a frase do plano, literal', () => {
        assert.equal(C.LEGENDA_DO_RECORTE, LEGENDA_ESPERADA);
    });

    await contexto.test('a legenda está na tela, como irmã direta no topo da grade 3', () => {
        const legenda = pegaSlot(interno, html, 'legenda do recorte');
        assert.equal(legenda.nome, 'p');
        assert.equal(textoDireto(html, legenda), LEGENDA_ESPERADA);
        conferirClasses(legenda, 'm-0 text-[12px] text-pub-txt-4', 'legenda do recorte');
        // E vem ANTES da grade 3 (é "o topo" dela).
        assert.ok(legenda.indice < pegaSlot(interno, html, 'grade 1fr|392px').indice);
    });

    await contexto.test('contra-prova: sem acento NÃO conta como a frase', () => {
        // ⚠️ `Saude`/`Acoes`/`ultimos` sem acento seriam outra frase. A
        // asserção acima é por igualdade, mas esta deixa a razão escrita.
        const semAcento = 'Jornada, Saude e Acoes mostram o estado atual. Recentes e Atividade mostram os ultimos 30 dias.';
        assert.notEqual(LEGENDA_ESPERADA, semAcento);
        assert.ok(!html.includes(semAcento), 'a tela escreveu a legenda sem acento');
    });

    await contexto.test('os outros dois recortes já vêm dos blocos (179-06 e 179-08)', () => {
        // A Jornada diz "independe do período" e "Publicações recentes" diz
        // "…nos últimos 30 dias": a legenda cobre o que FALTAVA (Saúde, Ações,
        // Atividade), não o que já estava dito.
        assert.ok(html.includes(R.SUB_RECENTES), 'o subtítulo de "Publicações recentes" sumiu');
        assert.equal(J.NOTA_PERIODO, 'Estado atual · independe do período');
        assert.ok(
            conteudoDe(html, pegaSlot(interno, html, 'Jornada dos produtos')).includes(J.NOTA_PERIODO),
            'a Jornada deixou de dizer que o estado atual independe do período',
        );
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// E. O SELETOR DE PERÍODO SAIU (critério 1)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * ⚠️ As opções do seletor de período do PROTÓTIPO (`periodNames`, `:596`:
 * `{today: 'Hoje', '7d': 'Últimos 7 dias', '30d': 'Últimos 30 dias'}`). O
 * protótipo CALCULA `periods` (`:601`) mas nunca o renderiza — não há seletor
 * de período nesta tela, nem lá nem aqui.
 *
 * ⚠️ `Últimos 30 dias` NÃO entra nesta lista: é o subtítulo REAL da faixa de
 * Desempenho (`recentes.js:181`). Medido, não presumido.
 */
const OPCOES_DE_PERIODO = ['Hoje', 'Últimos 7 dias', 'Este mês', '7 dias'];

test('E — não existe seletor de período nesta página', async (contexto) => {
    const html = render(TODAS());
    const { externo } = esqueleto(html);
    const corpo = conteudoDe(html, externo);

    for (const opcao of OPCOES_DE_PERIODO) {
        await contexto.test(`o corpo da página não oferece "${opcao}"`, () => {
            assert.equal(contar(corpo, opcao), 0, `"${opcao}" apareceu no corpo — o seletor de período voltou?`);
        });
    }

    await contexto.test('⚠️ ISCA: a asserção acima não é vazia — a tela SIM escreve rótulos de data', () => {
        // Sem esta isca, "não contém Hoje" passaria também numa tela que não
        // renderiza data nenhuma. O grupo de hoje da timeline escreve `HOJE`
        // em CAIXA ALTA (`recentes.js:424`), e é por isso que as asserções
        // acima são sensíveis a caixa DE PROPÓSITO.
        assert.equal(R.ROTULOS_FIXOS_DO_GRUPO.hoje, 'HOJE');
        assert.ok(corpo.includes('HOJE'), 'a timeline deveria rotular o grupo de hoje como HOJE');
        assert.ok(corpo.includes(R.SUB_DESEMPENHO), 'a faixa de Desempenho deveria dizer "Últimos 30 dias"');
    });

    await contexto.test('nem a página nem o cockpit importam `dadosDeExemplo`', () => {
        for (const caminho of [REL_PAGINA, REL_COCKPIT]) {
            assert.doesNotMatch(FONTES.get(caminho), /dadosDeExemplo/, caminho);
            assert.doesNotMatch(FONTES.get(caminho), /PERIODOS_EXEMPLO/, caminho);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// F. EXMP-02 — todo bloco novo é REAL, então nenhum carrega pilha de exemplo
// ═══════════════════════════════════════════════════════════════════════════

test('F — EXMP-02: zero `dadosDeExemplo` e zero `SeloExemplo` nos 12 arquivos novos', async (contexto) => {
    // Sem isto, um glob/lista vazia passaria por omissão.
    assert.equal(DOZE_ARQUIVOS.length, 12, `a lista dos arquivos novos deveria ter 12, tem ${DOZE_ARQUIVOS.length}`);

    for (const caminho of DOZE_ARQUIVOS) {
        await contexto.test(`${caminho} — nem importa exemplo nem renderiza selo`, () => {
            const fonte = FONTES.get(caminho);
            assert.ok(fonte, `${caminho} não foi lido`);
            // ⚠️ `lerSemComentarios`: `FaixaDeDesempenho.jsx:35` MENCIONA
            // `SeloExemplo` num comentário que explica justamente por que ela
            // não leva pilha. Ler cru reprovaria pelo motivo errado.
            assert.doesNotMatch(fonte, /dadosDeExemplo/, `${caminho} importou dado de exemplo`);
            assert.doesNotMatch(
                fonte,
                /SeloExemplo/,
                `${caminho} renderizou SeloExemplo. ⚠️ Se um bloco PASSAR a mostrar exemplo, ele tem de ganhar UMA pilha e esta asserção tem de ser REAPONTADA (lista de exceções com o motivo medido) — NUNCA apagada.`,
            );
        });
    }

    await contexto.test('⚠️ ISCA: os dois módulos de exemplo EXISTEM no disco', () => {
        // Se `dadosDeExemplo.js`/`SeloExemplo.jsx` fossem apagados, as 24
        // asserções acima passariam por construção.
        for (const rel of ['resources/js/Components/Mlb/Publicador/dadosDeExemplo.js', 'resources/js/Components/Mlb/Publicador/SeloExemplo.jsx']) {
            assert.ok(fs.existsSync(path.resolve(RAIZ, rel)), `${rel} não existe — a asserção de EXMP-02 virou vazia`);
        }
        // E alguém ainda os usa (o painel antigo), senão a camada estaria morta.
        assert.match(lerSemComentarios('resources/js/Components/Mlb/Publicador/PainelVisaoGeral.jsx'), /SeloExemplo/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// G. O INVENTÁRIO REG-01 — cada bloco de hoje tem uma casa amanhã
// ═══════════════════════════════════════════════════════════════════════════

/**
 * A tabela do plano, como CONSTANTE. Uma asserção por linha.
 *
 * `home` = o bloco novo que passa a mostrar aquilo (tem de estar NA TELA).
 * `outroCaminho` = a tela canônica que já mostra aquilo (e aí o H2 antigo
 * **não** pode estar nesta tela, senão ficariam dois blocos dizendo o mesmo).
 * `removido` = decisão informada do usuário em 12/10/2026 (teste H).
 */
const INVENTARIO_REG01 = [
    {
        bloco: 'os 6 KPIs do topo',
        tipo: 'home',
        prova: (html) => html.includes(R.TITULO_DESEMPENHO) && html.includes(A.TITULO_PUBS),
        onde: '`publicacoesMl` (no ar) · `desempenho` (criadas/com venda/criativos) · estágio `none` da `jornada` · `acervoImagens` (packs)',
    },
    {
        bloco: 'O que fazer agora',
        tipo: 'home',
        prova: (html) => html.includes(A.TITULO),
        onde: '`acoes`, com contagem exata (provada no 179-02)',
    },
    {
        bloco: 'Situação dos produtos',
        tipo: 'outroCaminho',
        h2Antigo: 'Situação dos produtos',
        onde: 'a tela Produtos recebe `contagens` e tem os mesmos filtros com os mesmos números',
    },
    {
        bloco: 'Alertas Meli & ERP',
        tipo: 'outroCaminho',
        h2Antigo: 'Alertas Meli',
        onde: '`MeusAnuncios.jsx` tem os chips da triagem com total e filtro — é a casa canônica',
    },
    {
        bloco: 'Integrações',
        tipo: 'outroCaminho',
        h2Antigo: 'Integrações',
        onde: '`saude` (ML · ERP · coleta) + "Configurações da conta" recebe `conexoes` = o MESMO `integracoes()`',
    },
    {
        bloco: 'Identidade visual',
        tipo: 'outroCaminho',
        h2Antigo: 'Identidade visual',
        onde: '"Configurações da conta" recebe o texto COMPLETO, não o resumo',
    },
    {
        bloco: 'Últimas publicações',
        tipo: 'home',
        prova: (html) => html.includes(R.TITULO_RECENTES),
        onde: '`recentes` (mais rico: capa, fase, status, quem/quando)',
    },
    {
        bloco: 'Atividade da equipe',
        tipo: 'home',
        prova: (html) => html.includes(R.TITULO_ATIVIDADE),
        onde: '`atividade` (as 3 fontes reais + a 4ª), agrupada por dia',
    },
    {
        bloco: 'Produtos por fase',
        tipo: 'removido',
        onde: '⚠️ REMOVIDO da tela — decisão informada do usuário, 12/10/2026 (ver o teste H)',
    },
    {
        bloco: 'Quem publicou',
        tipo: 'removido',
        onde: '⚠️ REMOVIDO da tela — decisão informada do usuário, 12/10/2026 (ver o teste H)',
    },
    {
        bloco: 'seletor de período',
        tipo: 'removido',
        onde: 'removido pelo critério 1 do ROADMAP, e a UI passou a DIZER o recorte (teste D/E)',
    },
];

test('G — REG-01: o inventário bloco por bloco, com uma asserção por linha', async (contexto) => {
    const html = render(TODAS({ banner: BANNER_ML() }));
    const { externo } = esqueleto(html);
    const corpo = conteudoDe(html, externo);

    await contexto.test('a tabela cobre os 11 blocos de hoje', () => {
        assert.equal(INVENTARIO_REG01.length, 11);
        assert.equal(INVENTARIO_REG01.filter((l) => l.tipo === 'home').length, 4);
        assert.equal(INVENTARIO_REG01.filter((l) => l.tipo === 'outroCaminho').length, 4);
        assert.equal(INVENTARIO_REG01.filter((l) => l.tipo === 'removido').length, 3);
        // Cada linha tem exatamente um dos três tipos, e nenhum tipo inventado.
        assert.deepEqual(
            [...new Set(INVENTARIO_REG01.map((l) => l.tipo))].sort(),
            ['home', 'outroCaminho', 'removido'],
        );
    });

    for (const linha of INVENTARIO_REG01.filter((l) => l.tipo === 'home')) {
        await contexto.test(`"${linha.bloco}" tem home NOVA na tela: ${linha.onde}`, () => {
            assert.ok(linha.prova(corpo), `"${linha.bloco}" não encontrou sua home nova no corpo da tela`);
        });
    }

    for (const linha of INVENTARIO_REG01.filter((l) => l.tipo === 'outroCaminho')) {
        await contexto.test(`"${linha.bloco}" vive em OUTRO caminho — e NÃO ficou duplicado aqui`, () => {
            // ⚠️ É a prova de que a informação não sumiu do sistema E de que a
            // tela nova não ficou com dois blocos dizendo a mesma coisa.
            assert.equal(
                contar(corpo, linha.h2Antigo),
                0,
                `"${linha.h2Antigo}" apareceu na tela nova — a casa canônica é: ${linha.onde}`,
            );
        });
    }

    await contexto.test('⚠️ ISCA: os 3 literais de "outro caminho" EXISTEM no painel antigo', () => {
        // Sem isto, a ausência deles poderia ser porque o literal nunca
        // existiu (asserção vazia por construção).
        const antigo = lerSemComentarios('resources/js/Components/Mlb/Publicador/PainelVisaoGeral.jsx');
        for (const linha of INVENTARIO_REG01.filter((l) => l.tipo === 'outroCaminho')) {
            assert.ok(
                antigo.includes(linha.h2Antigo),
                `"${linha.h2Antigo}" não existe em PainelVisaoGeral.jsx — o literal do inventário está errado`,
            );
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// H. ⚠️ OS DOIS BLOCOS REMOVIDOS — a asserção mais importante deste gate
//
// "Produtos por fase" e "Quem publicou" saíram da tela por DECISÃO INFORMADA
// DO USUÁRIO EM 12/10/2026, escolhida entre três opções (preservar no fim da
// página · mover para Configurações da conta · tirar os dois) e com o aviso
// explícito de que os dois são o ÚNICO caminho desses dados no sistema.
//
// ⚠️ Se você está vendo um destes testes FALHAR, o conserto é PERGUNTAR ao
// usuário — não restaurar o bloco. LIST-25 foi CUMPRIDO (a fase parou e
// perguntou); a decisão de perder o dado é do dono do produto.
//
// ⚠️ As props vêm PREENCHIDAS: com elas vazias a ausência seria vazia POR
// CONSTRUÇÃO e a asserção não provaria nada.
// ═══════════════════════════════════════════════════════════════════════════

/** Os literais que SÓ a tela antiga escrevia — medidos, não presumidos. */
const LITERAIS_REMOVIDOS = [
    'Produtos por fase',
    'Quem publicou',
    'Sem oferta',
    'Fase 2 em preparação',
    'Fase 2 publicada',
    'Fase 3+',
];

test('H — "Produtos por fase" e "Quem publicou" NÃO nascem na tela nova (decisão do usuário, 12/10/2026)', async (contexto) => {
    const props = TODAS({ banner: BANNER_ML(), saude: SAUDE_ML_FORA() });
    const html = render(props);
    const { externo } = esqueleto(html);
    const corpo = conteudoDe(html, externo);

    await contexto.test('⚠️ as duas props estão PRESENTES e PREENCHIDAS (senão a ausência é vazia)', () => {
        assert.equal(Object.keys(props.produtosPorFase).length, 5, 'os 5 buckets têm de estar na prop');
        assert.equal(props.quemPublicou.length, 2, '"Quem publicou" tem de ter linhas na prop');
        // E os literais estão de fato DENTRO do payload que entrou na página.
        const payload = JSON.stringify({ produtosPorFase: props.produtosPorFase, quemPublicou: props.quemPublicou });
        for (const literal of [...LITERAIS_REMOVIDOS.slice(2), 'Fase 1 publicada', 'Origem antiga']) {
            assert.ok(payload.includes(literal), `o fixture não carrega "${literal}" — a asserção negativa dele seria vazia`);
        }
    });

    for (const literal of LITERAIS_REMOVIDOS) {
        await contexto.test(`o corpo da tela NÃO contém "${literal}"`, () => {
            assert.equal(
                contar(corpo, literal),
                0,
                `"${literal}" voltou para a tela. ⚠️ A remoção é DECISÃO INFORMADA DO USUÁRIO EM 12/10/2026 (3 opções apresentadas, ele escolheu tirar os dois). O conserto é PERGUNTAR a ele, não restaurar o bloco.`,
            );
        });
    }

    await contexto.test('⚠️ "Fase 1 publicada": as ÚNICAS ocorrências são o estágio 03 da Jornada', () => {
        // ⚠️ MEDIDO: `jornada.js:73` dá ao estágio `ready` o rótulo
        // "Fase 1 publicada" — o MESMO literal do bucket `fase1_publicada` de
        // `produtosPorFase` (`PainelVisaoGeralService:704`). Uma asserção
        // negativa cega aqui seria VAZIA AO CONTRÁRIO: ela reprovaria a
        // Jornada legítima. O que prova algo é a POSIÇÃO: toda ocorrência tem
        // de cair DENTRO do nó da Jornada.
        assert.equal(J.ESTAGIOS.ready.rotulo, 'Fase 1 publicada', 'o rótulo do estágio 03 mudou — remeça esta asserção');

        const { interno } = esqueleto(html);
        const jornada = pegaSlot(interno, html, 'Jornada dos produtos');
        const ocorrencias = [];
        let i = -1;
        while ((i = html.indexOf('Fase 1 publicada', i + 1)) !== -1) ocorrencias.push(i);

        assert.ok(ocorrencias.length > 0, 'nem a Jornada escreveu "Fase 1 publicada" — a asserção virou vazia');
        for (const posicao of ocorrencias) {
            assert.ok(
                posicao > jornada.indice && posicao < jornada.fim,
                `"Fase 1 publicada" apareceu FORA da Jornada (posição ${posicao}, Jornada ${jornada.indice}..${jornada.fim}) — é o bucket removido voltando`,
            );
        }
    });

    await contexto.test('⚠️ "Origem antiga" só existiria pelo bloco removido — e não aparece', () => {
        // ⚠️ MEDIDO: "Origem antiga" também é valor LEGÍTIMO de `atividade`
        // (`CockpitDaContaService:576`) e de `recentes`. O fixture escolhe
        // `quem: 'Ana Ribeiro'`/`'Carla Mendes'` nos dois DE PROPÓSITO, para
        // que a única fonte possível do literal seja `quemPublicou`.
        const payloadNovo = JSON.stringify({ atividade: props.atividade, recentes: props.recentes, jornada: props.jornada, acoes: props.acoes });
        assert.ok(!payloadNovo.includes('Origem antiga'), 'o fixture dos blocos NOVOS passou a trazer "Origem antiga" — a asserção abaixo deixou de provar algo');
        assert.ok(JSON.stringify(props.quemPublicou).includes('Origem antiga'));

        assert.equal(contar(corpo, 'Origem antiga'), 0, '"Quem publicou" está sendo renderizado (decisão do usuário de 12/10/2026)');
    });

    await contexto.test('as props CONTINUAM chegando: prop órfã aqui é DELIBERADA', () => {
        // A decisão do usuário é de TELA; payload não é tela. Parar de enviar
        // exigiria cirurgia em 14 asserções de `ListaPorFaseTest`/
        // `VisaoGeralTest`, uma delas um teste cujo propósito é provar que o
        // contrato não perde prop.
        assert.ok(AS_23_PROPS.includes('produtosPorFase'));
        assert.ok(AS_23_PROPS.includes('quemPublicou'));
        assert.equal(AS_23_PROPS.length, 23, `o contrato do 179-05 tem 23 props, o fixture tem ${AS_23_PROPS.length}`);
        // E o cockpit NÃO as desestrutura: elas chegam e param ali.
        assert.doesNotMatch(FONTES.get(REL_COCKPIT), /produtosPorFase/);
        assert.doesNotMatch(FONTES.get(REL_COCKPIT), /quemPublicou/);
    });

    await contexto.test('não há apêndice de bloco preservado depois da grade 5', () => {
        const { interno } = esqueleto(html);
        assert.equal(interno.filhos.length, 6, 'apareceu um 7º bloco no container — apêndice depois da grade 5?');
        assert.ok(!html.includes('BlocosPreservados'), 'o componente de blocos preservados não nasce (decisão de 12/10/2026)');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// I. ⚠️ A TELA PRETA DE 07/10/2026 — render REAL com prop objeto, nula e ausente
// ═══════════════════════════════════════════════════════════════════════════

test('I — REND-01/02: a página sobrevive a cada uma das 23 props nula e ausente', async (contexto) => {
    await contexto.test('o mínimo que `publicador-header-nas-paginas` manda (9 props novas AUSENTES)', () => {
        const html = render({ empresa: EMPRESA(), liberada: true, abas: { company_id: 459 } });
        semLixo(html, 'página com o mínimo');
        esqueleto(html);
    });

    await contexto.test('props vazias de verdade: NADA além de um objeto vazio', () => {
        const html = render({});
        semLixo(html, 'página sem nenhuma prop');
        esqueleto(html);
    });

    await contexto.test('`cabecalho: null` — o caminho que já derrubou tela neste projeto', () => {
        const html = render(TODAS({ cabecalho: null }));
        semLixo(html, 'cabecalho nulo');
        esqueleto(html);
    });

    for (const prop of AS_23_PROPS) {
        await contexto.test(`${prop}: objeto · nula · ausente — nenhuma exceção, nenhum lixo`, () => {
            // 1. o caminho normal (objeto completo) já é o fixture.
            semLixo(render(TODAS()), `${prop} objeto`);

            // 2. NULA.
            semLixo(render(TODAS({ [prop]: null })), `${prop} nula`);

            // 3. AUSENTE (a página antiga em cache, ou um controller atrasado).
            const semAProp = TODAS();
            delete semAProp[prop];
            semLixo(render(semAProp), `${prop} ausente`);

            // 4. E a forma EXATA do acidente de 07/10: campo-objeto no lugar
            //    de escalar, array no lugar de objeto, escalar no lugar de
            //    objeto.
            for (const lixo of [{ a: 1 }, [1, 2], 7, 'x', true]) {
                semLixo(render(TODAS({ [prop]: lixo })), `${prop} = ${JSON.stringify(lixo)}`);
            }
        });
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// J. VG-19 — os 3 cenários que são da COMPOSIÇÃO
// ═══════════════════════════════════════════════════════════════════════════

test('J — VG-19: conta saudável · ML desconectado · sem produtos', async (contexto) => {
    await contexto.test('conta saudável: sem banner, e o vazio das ações no lugar da lista', () => {
        const html = render(TODAS({
            banner: null,
            acoes: { acoes: [], contagens: { todas: 0, critico: 0, atencao: 0, oportunidade: 0 }, adiadas: [], volta_em_iso: null },
        }));
        const { interno } = esqueleto(html);

        assert.equal(interno.filhos.length, 5, 'sem banner o container tem 5 filhos');
        assert.equal(
            interno.filhos.filter((no) => no.classes.includes('bg-pub-danger/[0.07]')).length,
            0,
            'o banner nasceu sem problema de integração',
        );
        assert.ok(!html.includes(R.BANNERS.ml.titulo), 'o texto do banner apareceu sem problema de integração');
        assert.ok(html.includes(A.VAZIO_TITULO), 'o vazio de "O que fazer agora" não apareceu');
        // E os outros blocos continuam na tela.
        assert.ok(html.includes(J.TITULO) && html.includes(R.TITULO_RECENTES) && html.includes(R.TITULO_ATIVIDADE));
        semLixo(html, 'conta saudável');
    });

    await contexto.test('ML desconectado: o banner em cima E a linha do ML com tint na Saúde', () => {
        const html = render(TODAS({ banner: BANNER_ML(), saude: SAUDE_ML_FORA() }));
        const { interno } = esqueleto(html);

        const banner = interno.filhos[0];
        assert.ok(banner.classes.includes('bg-pub-danger/[0.07]'), 'o banner não é o primeiro bloco');
        assert.equal(banner, pegaSlot(interno, html, 'banner de integração'));
        assert.ok(conteudoDe(html, banner).includes(R.BANNERS.ml.titulo));
        assert.match(conteudoDe(html, banner), /\shref="[^"]+"/, 'o CTA "Reconectar conta" ficou sem destino');

        // A linha do ML da Saúde, localizada por POSIÇÃO (1ª linha do 1º card
        // da coluna lateral) e não por string de classes — `bg-pub-danger`
        // aparece em vários irmãos.
        const saude = pegaSlot(interno, html, 'grade 1fr|392px').filhos[1].filhos[0];
        const comTint = todosOsNos(saude).filter((n) => n.classes.includes('bg-pub-danger/[0.07]'));
        assert.equal(comTint.length, 1, `esperava UMA linha com tint de problema na Saúde, achei ${comTint.length}`);
        semLixo(html, 'ML desconectado');
    });

    await contexto.test('sem produtos: empty state na Jornada, e os outros blocos de pé', () => {
        const html = render(TODAS({ jornada: JORNADA_VAZIA() }));
        const { interno } = esqueleto(html);

        assert.ok(
            conteudoDe(html, pegaSlot(interno, html, 'Jornada dos produtos')).includes(J.VAZIO_TITULO),
            'o empty state da Jornada não apareceu',
        );
        // Os outros blocos continuam de pé.
        for (const nome of ['legenda do recorte', 'grade 1fr|392px', 'faixa de Desempenho', 'grade 1fr|1fr']) {
            pegaSlot(interno, html, nome);
        }
        semLixo(html, 'sem produtos');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// K. z-index — nada desta página passa de 30 (a ordem global do header)
// ═══════════════════════════════════════════════════════════════════════════

test('K — nenhuma das 13 fontes desta tela passa de z-30', async (contexto) => {
    await contexto.test('a lista tem as 13 fontes e todas existem no disco', () => {
        assert.equal(TREZE_FONTES.length, 13);
        for (const caminho of TREZE_FONTES) {
            assert.ok(fs.existsSync(path.resolve(RAIZ, caminho)), `${caminho} não existe`);
        }
    });

    for (const caminho of TREZE_FONTES) {
        await contexto.test(`${caminho} — todo z-* é ≤ 30`, () => {
            const fonte = FONTES.get(caminho);
            // ⚠️ O lookahead de `]`, espaço, aspa ou fim é obrigatório: `z-3`
            // casa DENTRO de `z-30` e a extração leria 3 onde o valor é 30.
            const valores = [
                ...[...fonte.matchAll(/\bz-(\d+)(?=["'\s}]|$)/g)].map((m) => Number(m[1])),
                ...[...fonte.matchAll(/\bz-\[(\d+)\](?=["'\s}]|$)/g)].map((m) => Number(m[1])),
            ];
            for (const valor of valores) {
                assert.ok(valor <= 30, `${caminho}: z-${valor} passa de 30 (filtros 10 < header 30 < menu lateral e painel 40)`);
            }
        });
    }

    await contexto.test('⚠️ ISCA: o extrator lê 30 em `z-30`, e não 3', () => {
        const amostra = 'class="sticky top-0 z-30 flex" outro="z-[25]" terceiro="z-40"';
        const valores = [
            ...[...amostra.matchAll(/\bz-(\d+)(?=["'\s}]|$)/g)].map((m) => Number(m[1])),
            ...[...amostra.matchAll(/\bz-\[(\d+)\](?=["'\s}]|$)/g)].map((m) => Number(m[1])),
        ];
        assert.deepEqual(valores.sort((a, b) => a - b), [25, 30, 40]);
    });

    await contexto.test('o header continua em z-30 e o corpo não compete com ele', () => {
        const html = render(TODAS({ banner: BANNER_ML() }));
        const { header, externo } = esqueleto(html);
        assert.ok(header.classes.includes('z-30'));
        for (const no of todosOsNos(externo)) {
            for (const classe of no.classes) {
                const achado = /^z-\[?(\d+)\]?$/.exec(classe);
                if (achado) assert.ok(Number(achado[1]) <= 30, `o corpo renderizou ${classe}`);
            }
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// L. O diretório `visaogeral/` não tem arquivo fora da lista dos 12
// ═══════════════════════════════════════════════════════════════════════════

test('L — a lista dos 12 arquivos novos cobre o diretório inteiro', () => {
    const noDisco = readdirSync(path.resolve(RAIZ, DIR))
        .filter((nome) => nome.endsWith('.js') || nome.endsWith('.jsx'))
        .map((nome) => `${DIR}/${nome}`)
        .sort();

    assert.deepEqual(
        noDisco,
        [...DOZE_ARQUIVOS].sort(),
        '⚠️ apareceu (ou sumiu) arquivo em `visaogeral/` que não está na lista dos 12. Arquivo fora da lista fica SEM conferência contra `text-sm`, `ecf-*`, `font-display`, hex literal e breakpoint — e o gate de vocabulário passaria POR OMISSÃO.',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// M. AS DUAS URLs — montadas no cliente, num lugar só, e nunca inventadas
//
// ⚠️ `urlProdutos` e `urlPublicacoes` NÃO existem nas 23 props do servidor: o
// cockpit as monta por `route()` do Ziggy. Sem esta prova, trocar o nome da
// rota ou deixar de descer a prop passaria calado — e `/href="…"/` SEM o `\s`
// é asserção VAZIA, porque `data-href="…"` casa com ela (uma mutação do 179-08
// sobreviveu provando isso).
// ═══════════════════════════════════════════════════════════════════════════

/** Os `href` de um nó, lidos da TAG (com o `\s` obrigatório antes do atributo). */
function hrefsDe(html, no) {
    return [...conteudoDe(html, no).matchAll(/\shref="([^"]*)"/g)].map((m) => m[1]);
}

test('M — as duas URLs do cliente: nome da rota, parâmetro e a ausência que vira `null`', async (contexto) => {
    const html = render(TODAS({ banner: BANNER_ML() }));
    const { interno } = esqueleto(html);

    await contexto.test('`urlProdutos` usa a rota `mlb.anuncios.publicador.produtos` com a chave da conta', () => {
        assert.equal(C.urlDeProdutos({ chave: 'company-459' }), '/mlb.anuncios.publicador.produtos{"conta":"company-459"}');
        const jornada = pegaSlot(interno, html, 'Jornada dos produtos');
        const hrefs = hrefsDe(html, jornada);
        assert.ok(hrefs.length > 0, 'a Jornada não renderizou nenhum href — a asserção virou vazia');
        assert.ok(
            hrefs.some((h) => h.includes('mlb.anuncios.publicador.produtos') && h.includes('company-459')),
            `a Jornada não tem o destino de Produtos. hrefs: ${JSON.stringify(hrefs)}`,
        );
    });

    await contexto.test('`urlPublicacoes` usa a rota `mlb.anuncios.meus` com o `abas.company_id`', () => {
        assert.equal(C.urlDePublicacoes({ company_id: 459 }), '/mlb.anuncios.meus{"company":459}');
        const grade5 = pegaSlot(interno, html, 'grade 1fr|1fr');
        const hrefs = hrefsDe(html, grade5.filhos[0]);
        assert.ok(hrefs.length > 0, '"Publicações recentes" não renderizou href — a asserção virou vazia');
        assert.ok(
            hrefs.some((h) => h.includes('mlb.anuncios.meus') && h.includes('459')),
            `"Publicações recentes" não recebeu urlPublicacoes. hrefs: ${JSON.stringify(hrefs)}`,
        );
        // E a coluna lateral também recebe (é a MESMA URL, montada uma vez).
        const lateral = pegaSlot(interno, html, 'grade 1fr|392px').filhos[1].filhos[1];
        assert.ok(
            hrefsDe(html, lateral).some((h) => h.includes('mlb.anuncios.meus')),
            '"Publicações no Mercado Livre" não recebeu urlPublicacoes',
        );
    });

    await contexto.test('⚠️ sem destino ÚTIL a prop desce `null` — nunca um destino inventado', () => {
        // `textoUtil` e não `textoSeguro`: `''`/`'   '` viraria
        // `route('…', {conta: ''})`, e número viraria nome de conta plausível.
        // ⚠️ `7` está nesta lista porque `textoUtil(7)` devolve `'7'`: foi este
        // caso que pegou o furo e virou a guarda canónica (ver o [Rule 2] no
        // `CockpitDaConta.jsx`).
        for (const chave of [null, undefined, '', '   ', 7, {}, [], true, 'company', 'company-', '459', 'empresa-abc', 'loja-7', 'company-459x']) {
            assert.equal(C.urlDeProdutos({ chave }), null, `chave ${JSON.stringify(chave)} inventou destino`);
        }
        // E as DUAS formas canónicas (as mesmas que a rota aceita) passam.
        assert.equal(C.chaveDeContaSegura('empresa-12'), 'empresa-12');
        assert.equal(C.chaveDeContaSegura('company-459'), 'company-459');
        // `TrimStrings` do framework apara a borda; aqui também.
        assert.equal(C.chaveDeContaSegura('  company-459  '), 'company-459');
        for (const empresa of [null, undefined, 'x', 7, []]) {
            assert.equal(C.urlDeProdutos(empresa), null, `empresa ${JSON.stringify(empresa)} inventou destino`);
        }
        for (const company_id of [null, undefined, 0, -3, '', 'abc', {}, []]) {
            assert.equal(C.urlDePublicacoes({ company_id }), null, `company_id ${JSON.stringify(company_id)} inventou destino`);
        }
        for (const abas of [null, undefined, 'x', 7, []]) {
            assert.equal(C.urlDePublicacoes(abas), null, `abas ${JSON.stringify(abas)} inventou destino`);
        }
    });

    await contexto.test('D23: conta sem Company renderiza tudo, só sem os destinos de publicação', () => {
        const semCompany = TODAS({ abas: {}, banner: BANNER_ML() });
        const h = render(semCompany);
        const { interno: dentro } = esqueleto(h);
        semLixo(h, 'conta sem Company');
        // Os 6 blocos continuam de pé.
        assert.equal(dentro.filhos.length, 6);
        // E nenhum href aponta para `mlb.anuncios.meus` montado pelo cockpit
        // com company vazio.
        assert.ok(!h.includes('mlb.anuncios.meus{}'), 'montou destino de publicações sem company');
        assert.ok(!h.includes('mlb.anuncios.publicador.produtos{}'), 'montou destino de produtos sem conta');
    });

    await contexto.test('nenhuma URL é concatenada à mão no cockpit', () => {
        const fonte = FONTES.get(REL_COCKPIT);
        // Só `route(...)` monta destino aqui: nada de `'/mlb/' +` nem template
        // string com barra.
        assert.doesNotMatch(fonte, /['"`]\/mlb\//, 'o cockpit escreveu uma URL à mão');
        assert.equal((fonte.match(/\broute\(/g) ?? []).length, 2, 'o cockpit deveria chamar `route()` exatamente 2 vezes');
    });
});
