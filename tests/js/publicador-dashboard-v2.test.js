import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import { renderToStaticMarkup } from 'react-dom/server';
import React from 'react';

// ═══════════════════════════════════════════════════════════════════════════
// Quick 261009-t02 — redesign da tela 02 (Dashboard do Publicador, a Visão
// geral da conta) a partir do mockup do Stitch.
//
// ⚠️ Por que render REAL (esbuild + react-dom/server) e não só regex sobre a
// fonte: este painel está em produção desde 08/10 e expõe dezenas de campos do
// servidor de uma vez. Foi por uma fenda assim que, em 07/10/2026, um campo
// chegou como OBJETO e foi renderizado cru — "Objects are not valid as a React
// child", tela preta. Cada chave nova entra aqui chegando como objeto, nula e
// ausente, mais `indicadores: null` e o caso "nenhuma prop".
//
// ⚠️ `assert.match(tag, /disabled/)` seria uma ASSERÇÃO VAZIA neste projeto: as
// classes carregam `disabled:opacity-40` e casam sempre. A prova é `/disabled=/`.
//
// ⚠️ Armadilha do Rollup deste projeto (feedback_rollup_map_scope_bug.md):
// variável de escopo do componente lida DENTRO de `.map()` já foi eliminada no
// bundle de produção. Por isso os gates de fonte cobram que tudo que o `.map()`
// usa seja calculado no próprio callback.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

const REL_CARTAO = 'resources/js/Components/Mlb/Publicador/CartaoKpi.jsx';
const REL_PAINEL = 'resources/js/Components/Mlb/Publicador/PainelVisaoGeral.jsx';
const CARTAO = path.resolve(RAIZ, REL_CARTAO);
const PAINEL = path.resolve(RAIZ, REL_PAINEL);

// Stub de route() global — mesmo truque dos outros testes de render do módulo.
global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

// `@inertiajs/react` real traz `qs`/`object-inspect` incompatíveis com o bundle
// ESM do esbuild ("Dynamic require of 'util' is not supported") — mesmo stub
// inline da 173-06/175-10/261009-t01.
const STUB_INERTIA = path.join(__dirname, `.dashboard-v2-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');

after(() => fs.rmSync(STUB_INERTIA, { force: true }));

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
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA },
        // `PainelVisaoGeral.jsx` importa `textoSeguro` de `BarraDaConta.jsx`, o que
        // arrasta o módulo inteiro (Radix popover do "Trocar empresa" e axios do
        // `SeletorEmpresaBusca.jsx`) mesmo sem nunca renderizar a barra aqui.
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/react-popover'],
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

/** Nenhum campo pode escapar como `[object Object]`, `NaN` ou `undefined` no HTML. */
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

/** O trecho do rótulo de um KPI até o fim do parágrafo do número logo abaixo. */
function cartaoDe(html, rotulo) {
    const regex = new RegExp(`${rotulo}</p>[\\s\\S]*?</p>`);
    const achado = html.match(regex);
    assert.ok(achado, `cartão "${rotulo}" não encontrado`);

    return achado[0];
}

// ⚠️ Os DOIS bundles são montados aqui, ANTES de registrar qualquer `test()`.
// Não é estilo: o `node --test` roda os testes à medida que são registrados e
// dispara o `after()` quando os registrados acabam. Com o `montar()` do segundo
// módulo depois do primeiro bloco de testes, o `after()` apagava o stub no meio
// do segundo esbuild — e o arquivo inteiro morria com um "test failed" sem teste
// nenhum falhando, e só na suíte completa (nunca rodando sozinho). Aconteceu de
// verdade na tela 01, em 08/10.
const cartaoModulo = await montar(CARTAO, 'cartao-kpi');
const painelModulo = await montar(PAINEL, 'painel-visao-geral-v2');

// ═══════════════════════════════════════════════════════════════════════════
// Task 2 — `CartaoKpi.jsx`
// ═══════════════════════════════════════════════════════════════════════════

const CartaoKpi = cartaoModulo.default;
const desenharCartao = (props) => renderToStaticMarkup(React.createElement(CartaoKpi, props));

test('CartaoKpi — completo: rótulo, número, par de sub-números e selo de destaque', () => {
    const html = desenharCartao({
        rotulo: 'Aguardando ação',
        numero: 18,
        destaque: 'atencao',
        destaqueTexto: 'Prioritário',
        subs: [{ rotulo: 'Sem oferta', valor: 6 }, { rotulo: 'Prontos para a Fase 2', valor: 12 }],
    });

    assert.match(html, /Aguardando ação/);
    assert.match(html, />18/);
    assert.match(html, /Prioritário/);
    assert.match(html, /Sem oferta/);
    assert.match(html, /Prontos para a Fase 2/);
    assert.match(html, />6</);
    assert.match(html, />12</);
    semLixoNoHtml(html, 'cartão completo');
});

test('CartaoKpi — número NULO diz o motivo e mostra "—", nunca 0', () => {
    const html = desenharCartao({ rotulo: 'Revisão humana', numero: null, motivoVazio: 'Não existe no sistema ainda' });

    assert.match(cartaoDe(html, 'Revisão humana'), /—/, 'sem dado o número é "—"');
    assert.doesNotMatch(cartaoDe(html, 'Revisão humana'), /\b0\b/, '"não sabemos" nunca pode virar zero');
    assert.match(html, /Não existe no sistema ainda/);
    semLixoNoHtml(html, 'número nulo');
});

test('CartaoKpi — zero MEDIDO continua sendo 0, não vira "—"', () => {
    const html = desenharCartao({ rotulo: 'Criativos por IA', numero: 0, nota: 'packs gerados' });

    assert.match(cartaoDe(html, 'Criativos por IA'), />0</);
    assert.match(html, /packs gerados/);
});

test('CartaoKpi — número como OBJETO cai no vazio honesto, nunca [object Object]', () => {
    const html = desenharCartao({
        rotulo: { pt: 'Objeto' },
        numero: { total: 42 },
        nota: { texto: 'nota' },
        destaqueTexto: { x: 1 },
        destaque: { y: 2 },
        motivoVazio: { z: 3 },
        barraPct: { pct: 50 },
        subs: 'nem é lista',
    });

    semLixoNoHtml(html, 'tudo como objeto');
    assert.match(html, /Indicador/, 'sem rótulo utilizável, sobra o fallback');
    assert.doesNotMatch(html, /42/, 'número em formato inesperado não pode vazar');
});

test('CartaoKpi — sub-números ausentes, nulos e em formato inesperado não quebram nada', () => {
    for (const subs of [undefined, null, [], 'texto', [null, 'x', { rotulo: null, valor: 1 }, { rotulo: 'Base', valor: null }]]) {
        const html = desenharCartao({ rotulo: 'No ar', numero: 7, subs });
        semLixoNoHtml(html, `subs: ${JSON.stringify(subs)}`);
        assert.match(cartaoDe(html, 'No ar'), />7</);
    }
});

test('CartaoKpi — sem nenhuma prop continua renderizando', () => {
    const html = renderToStaticMarkup(React.createElement(CartaoKpi, {}));

    assert.match(html, /Indicador/);
    assert.match(cartaoDe(html, 'Indicador'), /—/);
    assert.match(html, /Ainda não medimos/);
    semLixoNoHtml(html, 'sem props');
});

test('CartaoKpi — com botão de ação não é ele mesmo um <button> (nada de botão dentro de botão)', () => {
    const html = desenharCartao({ rotulo: 'No ar', numero: null, botaoTexto: 'Atualizar agora', onBotao: () => {}, onClick: () => {} });

    assert.match(html, /Atualizar agora/);
    const abertura = html.indexOf('<button');
    const fechamento = html.indexOf('</button>');
    assert.ok(abertura > -1 && fechamento > abertura, 'o botão de ação precisa existir');
    assert.equal(html.slice(abertura + 1, fechamento).includes('<button'), false, '<button> dentro de <button>');
});

test('CartaoKpi — selo de destaque só aparece quando HÁ número (nunca em cima de "—")', () => {
    const comNumero = desenharCartao({ rotulo: 'Pendentes', numero: 5, destaque: 'critico', destaqueTexto: 'Pendentes' });
    assert.match(comNumero, /Pendentes/);

    const semNumero = desenharCartao({ rotulo: 'Revisão humana', numero: null, destaque: 'critico', destaqueTexto: 'Pendentes' });
    assert.doesNotMatch(semNumero, /Pendentes<\/span>/, 'selo de urgência sobre dado inexistente é mentira');
});

test('CartaoKpi — barra fica entre 0 e 100 mesmo com percentual fora da faixa', () => {
    for (const [pct, esperado] of [[-20, '0%'], [150, '100%'], [83, '83%']]) {
        const html = desenharCartao({ rotulo: 'Com venda', numero: 10, barraPct: pct });
        assert.ok(html.includes(`width:${esperado}`), `barra com ${pct} deveria virar ${esperado}`);
    }
});

test('CartaoKpi — textoSeguro e numeroSeguro recusam objeto, array e NaN', () => {
    assert.equal(cartaoModulo.textoSeguro({ a: 1 }, 'cai'), 'cai');
    assert.equal(cartaoModulo.textoSeguro(['a'], 'cai'), 'cai');
    assert.equal(cartaoModulo.textoSeguro('ok', 'cai'), 'ok');
    assert.equal(cartaoModulo.textoSeguro(12, 'cai'), '12');
    assert.equal(cartaoModulo.numeroSeguro(Number.NaN), null);
    assert.equal(cartaoModulo.numeroSeguro('12'), null);
    assert.equal(cartaoModulo.numeroSeguro({ n: 1 }), null);
    assert.equal(cartaoModulo.numeroSeguro(0), 0);
});

// ═══════════════════════════════════════════════════════════════════════════
// Gates de fonte — o mesmo vocabulário visual do módulo
// ═══════════════════════════════════════════════════════════════════════════

for (const relativo of [REL_CARTAO]) {
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

    test(`${relativo} — sem select do Radix e sem HTML injetado`, () => {
        assert.doesNotMatch(fonte, /@\/Components\/ui\/select/);
        assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/);
    });
}
