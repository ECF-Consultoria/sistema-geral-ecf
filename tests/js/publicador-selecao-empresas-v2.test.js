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

// ═══════════════════════════════════════════════════════════════════════════
// Task 2 — CartaoAcessoRapido
// ═══════════════════════════════════════════════════════════════════════════

const cartao = await montar(CARTAO, 'cartao-acesso-rapido');
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
    nome: 'Boutique Têxtil Brasil',
    identificador: '48.910.201/0001-92',
    token: 'ativo',
    produtos: 148,
    publicados: 312,
    prontos: 4,
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
// Gates de fonte do cartão — o mesmo vocabulário visual do módulo
// ═══════════════════════════════════════════════════════════════════════════

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
