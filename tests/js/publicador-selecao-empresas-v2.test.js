import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import { renderToStaticMarkup } from 'react-dom/server';
import React from 'react';

// ═══════════════════════════════════════════════════════════════════════════
// Quick 261009-t01 — redesign da tela 01 (Seleção de Empresas) a partir do
// mockup do Stitch.
//
// ⚠️ Por que render REAL (esbuild + react-dom/server) e não só regex sobre a
// fonte: `AnunciosEmpresas.jsx` é a PORTA DE ENTRADA do módulo e está em
// produção. Foi por uma fenda assim que, em 07/10/2026, um campo do presenter
// chegou como OBJETO e foi renderizado cru — "Objects are not valid as a React
// child", tela preta. Este plano acrescenta à linha DUAS chaves novas (`erp` e
// `fases`), e cada uma entra aqui chegando como objeto, nula e ausente, além do
// caso `empresas: null` e do caso "nenhuma prop".
//
// ⚠️ `assert.match(tag, /disabled/)` seria uma ASSERÇÃO VAZIA neste projeto: as
// classes carregam `disabled:opacity-40` e casam sempre. A prova é `/disabled=/`
// e sempre dentro da tag do botão certo.
//
// ⚠️ Armadilha do Rollup deste projeto (feedback_rollup_map_scope_bug.md):
// variável de escopo do componente lida DENTRO de `.map()` já foi eliminada no
// bundle de produção. Por isso os gates de fonte abaixo cobram que tudo que o
// `.map()` usa seja calculado no próprio callback.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const CARTAO = path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/CartaoAcessoRapido.jsx');
const PAGINA = path.resolve(RAIZ, 'resources/js/Pages/Mlb/AnunciosEmpresas.jsx');

const REL_CARTAO = 'resources/js/Components/Mlb/Publicador/CartaoAcessoRapido.jsx';
const REL_PAGINA = 'resources/js/Pages/Mlb/AnunciosEmpresas.jsx';

// Stub de route() global — mesmo truque dos outros testes de render do módulo.
global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

// A página usa `window.localStorage` (Recentes). Sem DOM nos testes deste
// projeto, o `window` é um objeto mínimo; cada caso troca o localStorage antes
// de renderizar. `lanca: true` simula a janela privada, onde o acesso ESTOURA.
const localStorageFalso = (conteudo) => ({
    getItem: (chave) => (Object.prototype.hasOwnProperty.call(conteudo, chave) ? conteudo[chave] : null),
    setItem: () => {},
});
const localStorageQueEstoura = () => ({
    getItem: () => { throw new Error('SecurityError: janela privada'); },
    setItem: () => { throw new Error('SecurityError: janela privada'); },
});

global.window = {
    localStorage: localStorageFalso({}),
    addEventListener: () => {},
    removeEventListener: () => {},
};

// `@inertiajs/react` real traz `qs`/`object-inspect` incompatíveis com o bundle
// ESM do esbuild ("Dynamic require of 'util' is not supported") — mesmo stub
// inline da 173-06/175-04/175-07/175-10.
const STUB_INERTIA = path.join(__dirname, `.selecao-v2-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
export function usePage() { return { props: { auth: { user: { id: 7 } } } }; }
`, 'utf8');

// `AppLayout.jsx` é a casca inteira do app (sidebar, notificações, tema, Modo
// TV) — irrelevante para esta tela; stub mínimo, mesma ideia da 173-07/175-10.
const STUB_APPLAYOUT = path.join(__dirname, `.selecao-v2-applayout-stub-${process.pid}.mjs`);
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

    // Precisa viver DENTRO da árvore do projeto: a resolução ESM do Node para
    // `import 'react'` sobe os diretórios até achar um `node_modules`.
    const outfile = path.join(__dirname, `.${rotulo}-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

/** A fonte sem comentários (gate de fonte, molde de `publicador-entrada.test.js`). */
const lerSemComentarios = (relativo) => fs.readFileSync(path.resolve(RAIZ, relativo), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .split(/\r?\n/)
    .map((linha) => linha.replace(/(^|[^:])\/\/.*$/, '$1'))
    .join('\n');

/** Nenhum campo pode escapar como `[object Object]` nem como `NaN` no HTML. */
function semLixoNoHtml(html, rotulo) {
    assert.doesNotMatch(html, /\[object Object\]/, `${rotulo}: objeto renderizado cru`);
    assert.doesNotMatch(html, /\bNaN\b/, `${rotulo}: NaN na tela`);
    assert.doesNotMatch(html, /\bundefined\b/, `${rotulo}: undefined na tela`);
}

/** Recorta a tag de abertura do botão cujo texto contém `rotulo`. */
function tagDoBotao(html, rotulo) {
    const indice = html.indexOf(rotulo);
    assert.ok(indice > -1, `botão "${rotulo}" não encontrado`);
    const abertura = html.lastIndexOf('<button', indice);
    assert.ok(abertura > -1, `tag <button> de "${rotulo}" não encontrada`);

    return html.slice(abertura, html.indexOf('>', abertura) + 1);
}

// ⚠️ Os DOIS bundles são montados aqui, ANTES de registrar qualquer `test()`.
// Não é estilo: o `node --test` roda os testes à medida que são registrados e
// dispara o `after()` quando os registrados acabam. Com o `montar()` da página
// depois do primeiro bloco de testes, o `after()` apagava os arquivos de stub
// no meio do segundo esbuild — e o arquivo inteiro morria com um "test failed"
// sem teste nenhum falhando, só na suíte completa (nunca rodando sozinho).
const cartao = await montar(CARTAO, 'cartao-acesso-rapido');
const paginaModulo = await montar(PAGINA, 'pagina-selecao-empresas');

// ═══════════════════════════════════════════════════════════════════════════
// Task 2 — CartaoAcessoRapido
// ═══════════════════════════════════════════════════════════════════════════

const CartaoAcessoRapido = cartao.default;

const itemRecente = (extra = {}) => ({
    chave: 'empresa-12',
    nome: 'Boutique Têxtil Brasil',
    identificador: '48.910.201/0001-92',
    programa: 'polos',
    ...extra,
});

const linhaViva = (extra = {}) => ({
    chave: 'empresa-12',
    tipo: 'mlb_empresa',
    id: 12,
    nome: 'Boutique Têxtil Brasil',
    identificador: '48.910.201/0001-92',
    company_id: 90,
    tem_token: true,
    token_expirado: false,
    token: 'ativo',
    link_reconexao: null,
    produtos: 148,
    publicados: 312,
    prontos: 4,
    liberada: true,
    publicados_mes: 7,
    portal: { situacao: 'sincronizado', novas: 0, sincronizado_em: '2026-10-09T10:00:00Z' },
    erp: { nome: 'Bling' },
    fases: { kits: 12 },
    ...extra,
});

const desenhar = (props) => renderToStaticMarkup(React.createElement(CartaoAcessoRapido, props));

test('Cartão — completo: nome, identificador, ERP declarado, números e selo da conta', () => {
    const html = desenhar({ item: itemRecente(), dados: linhaViva(), onAbrir: () => {} });

    assert.match(html, /Boutique Têxtil Brasil/);
    assert.match(html, /48\.910\.201\/0001-92/);
    assert.match(html, / · ERP Bling/);
    assert.match(html, /148 produtos · 312 anúncios/);
    assert.match(html, /Conectada/, 'o SeloConta da linha viva precisa aparecer');
    assert.match(html, />BT</, 'iniciais da empresa');
    assert.doesNotMatch(tagDoBotao(html, 'Acessar'), /disabled=/);
    semLixoNoHtml(html, 'cartão completo');
});

test('Cartão — ERP é sempre DECLARADO: nunca "conectado" nem "sincronizado"', () => {
    const html = desenhar({ item: itemRecente(), dados: linhaViva(), onAbrir: () => {} });

    assert.doesNotMatch(html, /ERP[^<]{0,40}(conectad|sincronizad)/i);
    assert.match(html, /declarado no onboarding/, 'o título precisa dizer que é só declaração');
});

test('Cartão — sem a linha viva mostra só o que é verdade, sem inventar número', () => {
    const html = desenhar({ item: itemRecente(), dados: null, onAbrir: () => {} });

    assert.match(html, /Boutique Têxtil Brasil/);
    assert.match(html, /Números aparecem ao abrir a conta\./);
    assert.doesNotMatch(html, /produtos ·/);
    assert.doesNotMatch(html, /Conectada/, 'sem linha viva não há estado de conta para afirmar');
    semLixoNoHtml(html, 'cartão sem linha viva');
});

test('Cartão — item antigo do localStorage, sem os campos novos, não estoura', () => {
    const html = desenhar({ item: { chave: 'empresa-12' }, dados: null, onAbrir: () => {} });

    assert.match(html, /empresa-12/);
    assert.match(html, /Sem identificador/);
    semLixoNoHtml(html, 'item antigo');
});

test('Cartão — todo campo chegando como OBJETO cai no fallback, nada vira [object Object]', () => {
    const html = desenhar({
        item: { chave: 'empresa-12', nome: { pt: 'Objeto' }, identificador: ['x'], programa: {} },
        dados: linhaViva({
            nome: { pt: 'Objeto' },
            identificador: { a: 1 },
            token: { estado: 'ativo' },
            produtos: { total: 148 },
            publicados: null,
            erp: { nome: { marca: 'Bling' } },
            portal: 'não é objeto',
            fases: 'nem isto',
        }),
        onAbrir: () => {},
    });

    semLixoNoHtml(html, 'campos como objeto');
    assert.match(html, /empresa-12/, 'sem nome utilizável, sobra a chave');
});

test('Cartão — ERP chegando como string vazia não desenha o sufixo de ERP', () => {
    const html = desenhar({ item: itemRecente(), dados: linhaViva({ erp: { nome: '' } }), onAbrir: () => {} });

    assert.doesNotMatch(html, /ERP/);
    semLixoNoHtml(html, 'ERP vazio');
});

test('Cartão — item nulo ainda desenha e o botão fica desabilitado (sem chave, não há o que abrir)', () => {
    const html = desenhar({ item: null, dados: null, onAbrir: () => {} });

    assert.match(html, /Empresa/);
    assert.match(tagDoBotao(html, 'Acessar'), /disabled=/);
    semLixoNoHtml(html, 'item nulo');
});

test('Cartão — sem nenhuma prop (nem onAbrir) continua renderizando', () => {
    const html = renderToStaticMarkup(React.createElement(CartaoAcessoRapido, {}));

    assert.match(html, /Acessar/);
    assert.match(tagDoBotao(html, 'Acessar'), /disabled=/);
    semLixoNoHtml(html, 'sem props');
});

test('Cartão — iniciais: duas palavras, uma palavra, vazio e forma inesperada', () => {
    assert.equal(cartao.iniciaisDe('Boutique Têxtil Brasil'), 'BT');
    assert.equal(cartao.iniciaisDe('TechStore'), 'TE');
    assert.equal(cartao.iniciaisDe('  '), '—');
    assert.equal(cartao.iniciaisDe(null), '—');
    assert.equal(cartao.iniciaisDe({ nome: 'x' }), '—');
});

test('Cartão — textoSeguro e numeroSeguro recusam objeto, array e NaN', () => {
    assert.equal(cartao.textoSeguro({ a: 1 }, 'cai'), 'cai');
    assert.equal(cartao.textoSeguro(['a'], 'cai'), 'cai');
    assert.equal(cartao.textoSeguro('ok', 'cai'), 'ok');
    assert.equal(cartao.textoSeguro(12, 'cai'), '12');
    assert.equal(cartao.numeroSeguro(Number.NaN), null);
    assert.equal(cartao.numeroSeguro('12'), null);
    assert.equal(cartao.numeroSeguro({ n: 1 }), null);
    assert.equal(cartao.numeroSeguro(0), 0);
});

// ═══════════════════════════════════════════════════════════════════════════
// Task 3 — a tela (`Pages/Mlb/AnunciosEmpresas.jsx`), render REAL
// ═══════════════════════════════════════════════════════════════════════════

const AnunciosEmpresas = paginaModulo.default;

const segunda = () => linhaViva({
    chave: 'empresa-13',
    id: 13,
    nome: 'TechStore Eletrônicos Ltda',
    identificador: '31.844.912/0001-44',
    company_id: 91,
    tem_token: false,
    token: 'sem_token',
    token_expirado: false,
    link_reconexao: '/implementacao/abc/conectar-ml',
    produtos: 412,
    publicados: 890,
    prontos: 0,
    liberada: false,
    erp: { nome: null },
    fases: { kits: 0 },
    portal: { situacao: 'novas', novas: 3, sincronizado_em: null },
});

const propsPagina = (over = {}) => ({
    programa: 'polos',
    programas: { polos: 6, incubadora: 2, gestao: 3 },
    indicadores: { empresas: 6, com_portal: 4, pct_sincronizado: 75, prontos: 9, publicados_mes: 12 },
    empresas: [linhaViva(), segunda()],
    paginacao: { pagina: 1, por_pagina: 50, total: 2, de: 1, ate: 2 },
    filtros: { busca: '', filtro: 'todos' },
    ...over,
});

/** Renderiza a tela com o localStorage que o caso pedir. */
function desenharPagina(props, armazenamento = localStorageFalso({})) {
    global.window.localStorage = armazenamento;
    try {
        return renderToStaticMarkup(React.createElement(AnunciosEmpresas, props));
    } finally {
        global.window.localStorage = localStorageFalso({});
    }
}

const recentesEm = (lista) => localStorageFalso({ 'publicador.recentes.7': JSON.stringify(lista) });

test('Tela — cabeçalho do mockup: título, "Conectar nova empresa" desabilitado com "Em breve"', () => {
    const html = desenharPagina(propsPagina());

    assert.match(html, /Seleção de Empresas/);
    assert.match(html, /Publicador Mercado Livre/, 'o nome do módulo continua visível');
    assert.match(html, /Conectar nova empresa/);
    assert.match(html, /Em breve/);
    assert.match(tagDoBotao(html, 'Conectar nova empresa'), /disabled=/);
    semLixoNoHtml(html, 'cabeçalho');
});

test('Tela — as 7 colunas, com Portal E ERP convivendo (decisão 1)', () => {
    const html = desenharPagina(propsPagina());

    for (const coluna of ['Empresa', 'Conta ML', 'Portal', 'ERP', 'Catálogo', 'Anúncios', 'Fases']) {
        assert.ok(html.includes(coluna), `coluna ausente: ${coluna}`);
    }
    // Portal continua com o selo de verdade (situação + tempo), que o mockup tinha tirado.
    assert.match(html, /Sincronizado/);
    assert.match(html, /3<\/span><span>ofertas novas/);
});

test('Tela — ERP declarado na linha; sem declaração escreve "não informado"', () => {
    const html = desenharPagina(propsPagina());

    assert.match(html, /Bling/);
    assert.match(html, /declarado/);
    assert.match(html, /não informado/);
});

test('Tela — NADA afirma ERP conectado ou sincronizado (decisão 8 do handoff)', () => {
    const html = desenharPagina(propsPagina());

    assert.doesNotMatch(html, /ERP[^<]{0,60}(conectad|sincronizad)/i);
    assert.doesNotMatch(html, /(conectad|sincronizad)[^<]{0,20}\bERP\b/i);
});

test('Tela — Fases/pendências só mostra o que é real: kits e prontos', () => {
    const html = desenharPagina(propsPagina());

    assert.match(html, /12 kits/);
    assert.match(html, /4 prontos/);
});

test('Tela — os 4 filtros continuam existindo, com os mesmos rótulos', () => {
    const html = desenharPagina(propsPagina({ filtros: { busca: '', filtro: 'atencao' } }));

    for (const rotulo of ['Todos', 'Prontos para publicar', 'Precisam de atenção', 'Nunca sincronizado']) {
        assert.ok(html.includes(rotulo), `filtro ausente: ${rotulo}`);
    }
    assert.match(tagDoBotao(html, 'Precisam de atenção'), /aria-pressed="true"/);
});

test('Tela — o que já existia continua na tela (nada sumiu)', () => {
    const html = desenharPagina(propsPagina());

    assert.match(html, /role="radiogroup"/, 'SeletorPrograma');
    assert.match(html, /Prontos para publicar/, 'IndicadoresDoPrograma');
    assert.match(html, /Buscar/, 'busca');
    assert.match(html, /Mostrando 1–2 de 2/, 'paginação');
    assert.match(html, /Sincronizar/, 'BotaoSincronizarPortal');
    assert.match(html, /Como funciona/, 'PainelComoFunciona');
    assert.match(html, /Falta reconectar|Reconectar/, 'SeloConta');
    assert.match(html, /Publicação ainda não liberada/, 'AvisoContaTravada da conta não liberada');
    assert.match(html, /navegador DELE/, 'LinkReconexao da conta expirada');
    assert.match(html, /class="h-14/, 'a linha focável de 56px');
});

test('Tela — Acesso rápido desenha os Recentes do localStorage', () => {
    const html = desenharPagina(propsPagina(), recentesEm([
        { chave: 'empresa-12', nome: 'Boutique Têxtil Brasil', identificador: '48.910.201/0001-92', programa: 'polos' },
        { chave: 'empresa-99', nome: 'Fora Desta Página', identificador: '00.000.000/0001-00', programa: 'polos' },
    ]));

    assert.match(html, /Acesso rápido/);
    // A que está na página exibida ganha os números da linha viva…
    assert.match(html, /148 produtos · 312 anúncios/);
    // …e a que não está diz a verdade, em vez de inventar número.
    assert.match(html, /Fora Desta Página/);
    assert.match(html, /Números aparecem ao abrir a conta\./);
    semLixoNoHtml(html, 'acesso rápido');
});

test('Tela — sem Recentes o bloco de Acesso rápido simplesmente não existe', () => {
    const html = desenharPagina(propsPagina());

    assert.doesNotMatch(html, /Acesso rápido/);
});

test('Tela — localStorage que ESTOURA (janela privada) não derruba a tela', () => {
    const html = desenharPagina(propsPagina(), localStorageQueEstoura());

    assert.match(html, /Seleção de Empresas/);
    assert.doesNotMatch(html, /Acesso rápido/);
});

test('Tela — Recentes corrompido, não-array e com item sem chave são descartados sem quebrar', () => {
    for (const bruto of ['{{{', '"só uma string"', '42', JSON.stringify([null, { nome: 'sem chave' }, { chave: 7 }])]) {
        const html = desenharPagina(propsPagina(), localStorageFalso({ 'publicador.recentes.7': bruto }));
        assert.match(html, /Seleção de Empresas/, `localStorage: ${bruto}`);
        semLixoNoHtml(html, `localStorage: ${bruto}`);
    }
});

test('Tela — `empresas: null` não derruba a página', () => {
    const html = desenharPagina(propsPagina({ empresas: null }));

    assert.match(html, /Seleção de Empresas/);
    semLixoNoHtml(html, 'empresas null');
});

test('Tela — props nulas/ausentes em bloco não derrubam a página', () => {
    const vazios = [
        {},
        { empresas: undefined, paginacao: null, filtros: null, indicadores: null, programas: null },
        { empresas: 'não é lista', paginacao: 'nem isto', filtros: 7, indicadores: [], programas: 'x' },
    ];
    for (const props of vazios) {
        const html = desenharPagina(props);
        assert.match(html, /Seleção de Empresas/, JSON.stringify(props));
        semLixoNoHtml(html, JSON.stringify(props));
    }
});

test('Tela — as chaves NOVAS chegando como objeto, nulas e ausentes (a tela preta de 07/10)', () => {
    const formas = [
        { erp: { nome: { marca: 'Bling' } }, fases: { kits: { total: 12 } } },
        { erp: null, fases: null },
        { erp: 'Bling', fases: 'kits' },
        { erp: ['Bling'], fases: [12] },
    ];
    for (const forma of formas) {
        const linha = linhaViva(forma);
        delete linha.erp_inexistente;
        const html = desenharPagina(propsPagina({ empresas: [linha] }));
        semLixoNoHtml(html, JSON.stringify(forma));
    }

    // Chaves simplesmente AUSENTES (payload de antes deste plano).
    const antiga = linhaViva();
    delete antiga.erp;
    delete antiga.fases;
    const html = desenharPagina(propsPagina({ empresas: [antiga] }));
    assert.match(html, /não informado/);
    semLixoNoHtml(html, 'linha sem as chaves novas');
});

test('Tela — campos ANTIGOS da linha chegando como objeto também caem no fallback', () => {
    const html = desenharPagina(propsPagina({
        empresas: [linhaViva({
            nome: { pt: 'Objeto' },
            identificador: { a: 1 },
            produtos: { total: 1 },
            publicados: null,
            prontos: 'quatro',
            token: { estado: 'ativo' },
            portal: 'não é objeto',
        })],
    }));

    semLixoNoHtml(html, 'campos antigos como objeto');
});

test('Tela — os dois estados vazios continuam, com o mesmo texto', () => {
    const semEmpresa = desenharPagina(propsPagina({
        empresas: [], indicadores: { empresas: 0 }, paginacao: { pagina: 1, por_pagina: 50, total: 0, de: 0, ate: 0 },
    }));
    assert.match(semEmpresa, /Nenhuma empresa de Polos com conta do Mercado Livre\./);

    const semResultado = desenharPagina(propsPagina({
        empresas: [], filtros: { busca: 'abc', filtro: 'todos' },
        paginacao: { pagina: 1, por_pagina: 50, total: 0, de: 0, ate: 0 },
    }));
    assert.match(semResultado, /Nenhuma empresa encontrada para/);
    assert.match(semResultado, /Limpar busca/);
});

test('Tela — rodapé: três cards, e os dois com número leem número REAL', () => {
    const html = desenharPagina(propsPagina());

    // 1 conta a reconectar entre as 2 exibidas (a de token expirado).
    assert.match(html, /1 conta/);
    // `prontos` do programa inteiro vem dos indicadores (9), não da página.
    assert.match(html, /9 produtos/);
    // 12 kits na primeira + 0 na segunda.
    assert.match(html, /12 kits/);
    // O card do ⌘K do mockup não promete atalho que não existe.
    assert.doesNotMatch(html, /⌘K|Ctrl\+K/);
});

test('Tela — a ordenação do cliente é nativa (sem Radix) e tem as opções declaradas', () => {
    const html = desenharPagina(propsPagina());

    assert.match(html, /<select/);
    for (const rotulo of ['Nome (A–Z)', 'Mais produtos', 'Mais anúncios', 'Prontos primeiro']) {
        assert.ok(html.includes(rotulo), `ordenação sem a opção: ${rotulo}`);
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// Gates de fonte do cartão — o mesmo vocabulário visual do módulo
// ═══════════════════════════════════════════════════════════════════════════

test('Tela — nenhuma flag booleana de escopo do componente é lida dentro do .map() das linhas', () => {
    const fonte = lerSemComentarios(REL_PAGINA);
    const inicio = fonte.indexOf('linhas.map(');
    assert.ok(inicio > -1, 'o .map() das linhas não foi encontrado');
    const trecho = fonte.slice(inicio, fonte.indexOf('</tbody>', inicio));

    // As flags da tela existem — mas nenhuma delas pode ser LIDA aqui dentro
    // (feedback_rollup_map_scope_bug.md: o Rollup já eliminou uma e o bundle
    // de produção estourou com ReferenceError).
    for (const flag of ['precisaPaginar', 'vazioDoPrograma', 'temBuscaOuFiltro', 'carregando', 'erroCarga']) {
        assert.ok(!trecho.includes(flag), `flag de escopo lida dentro do .map(): ${flag}`);
    }
});

test('Tela — o seletor de ordenação não usa o select do Radix (gate do módulo)', () => {
    assert.doesNotMatch(lerSemComentarios(REL_PAGINA), /@\/Components\/ui\/select/);
});

for (const relativo of [REL_CARTAO, REL_PAGINA]) {
    const fonte = lerSemComentarios(relativo);

    test(`${relativo} — tipografia: só 24/15/13/11px`, () => {
        const usados = [...fonte.matchAll(/text-\[(\d+(?:\.\d+)?)px\]/g)].map((m) => m[1]);
        for (const t of usados) assert.ok(['24', '15', '13', '11'].includes(t), `tamanho fora do vocabulário: ${t}px`);
        assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/);
    });

    test(`${relativo} — peso: só 400 e 700`, () => {
        assert.doesNotMatch(fonte, /font-(thin|extralight|light|medium|semibold|extrabold|black)\b/);
    });

    test(`${relativo} — amarelo só translúcido (o mockup usa sólido; aqui é gate do módulo)`, () => {
        assert.doesNotMatch(fonte, /\bbg-ecf-yellow(?!\/)/);
    });
}
