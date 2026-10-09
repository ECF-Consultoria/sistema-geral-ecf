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
const REL_SELO = 'resources/js/Components/Mlb/Publicador/SeloExemplo.jsx';
const REL_DADOS = 'resources/js/Components/Mlb/Publicador/dadosDeExemplo.js';
const CARTAO = path.resolve(RAIZ, REL_CARTAO);
const PAINEL = path.resolve(RAIZ, REL_PAINEL);
const SELO = path.resolve(RAIZ, REL_SELO);
const DADOS = path.resolve(RAIZ, REL_DADOS);

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

/** Só o texto visível: sem tags, sem os marcadores de hidratação do React. */
function semTags(html) {
    return html.replace(/<[^>]*>/g, '').replace(/<!--[\s\S]*?-->/g, '');
}

/**
 * A `<section>` inteira que contém um título — do `<section` que a abre até o
 * próximo `<section` (ou o fim). É assim que o gate prova que a pilha "exemplo"
 * está DENTRO do bloco certo, e não em qualquer lugar da tela.
 */
function secaoDe(html, titulo) {
    const indice = html.indexOf(titulo);
    assert.ok(indice > -1, `bloco "${titulo}" não encontrado`);
    const abertura = html.lastIndexOf('<section', indice);
    assert.ok(abertura > -1, `<section> de "${titulo}" não encontrada`);
    const proxima = html.indexOf('<section', indice);

    return html.slice(abertura, proxima > -1 ? proxima : html.length);
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
const seloModulo = await montar(SELO, 'selo-exemplo');
const dadosModulo = await montar(DADOS, 'dados-de-exemplo');
const painelModulo = await montar(PAINEL, 'painel-visao-geral-v2');

// ═══════════════════════════════════════════════════════════════════════════
// Quick 261010-t02b — Task 1: a convenção do dado de exemplo
//
// ⚠️ MUDANÇA DE RÉGUA (usuário, 10/10): o main/content passa a ser o do mockup
// INTEIRO. Onde o dado existe, é o dado da conta; onde não existe, é um valor
// de EXEMPLO — nunca mais um quadro vazio. O que torna isso reversível é a
// disciplina: todo valor fictício do módulo mora em `dadosDeExemplo.js` e todo
// bloco que o usa carrega a pilha `SeloExemplo`. Apagar aquele arquivo um dia
// mostra exatamente o que ainda era mentira.
// ═══════════════════════════════════════════════════════════════════════════

const SeloExemplo = seloModulo.default;

test('SeloExemplo — a pilha discreta renderiza com a palavra "exemplo"', () => {
    const html = renderToStaticMarkup(React.createElement(SeloExemplo, {}));

    assert.match(semTags(html), /exemplo/i);
    semLixoNoHtml(html, 'selo sem props');
});

test('SeloExemplo — sempre tem title explicando que o dado é fictício', () => {
    const padrao = renderToStaticMarkup(React.createElement(SeloExemplo, {}));
    assert.match(padrao, /title="[^"]*exemplo[^"]*"/i, 'sem title a pilha não explica nada');

    const proprio = renderToStaticMarkup(React.createElement(SeloExemplo, { title: 'Motivo próprio deste bloco' }));
    assert.match(proprio, /title="Motivo próprio deste bloco"/);
});

test('SeloExemplo — `title` e `className` NULOS (≠ ausentes) caem no padrão', () => {
    // O default de desestruturação só cobre `undefined`; prop nula chega como null
    // e já foi bug real em duas telas desta semana.
    const html = renderToStaticMarkup(React.createElement(SeloExemplo, { title: null, className: null }));

    assert.match(html, /title="[^"]*exemplo[^"]*"/i);
    semLixoNoHtml(html, 'selo com props nulas');
});

test('SeloExemplo — title/className em formato inesperado não vazam para o HTML', () => {
    for (const forma of [{ title: { a: 1 } }, { title: 42 }, { className: ['x'] }, { className: { y: 2 } }]) {
        const html = renderToStaticMarkup(React.createElement(SeloExemplo, forma));
        semLixoNoHtml(html, JSON.stringify(forma));
        assert.match(semTags(html), /exemplo/i);
    }
});

test('dadosDeExemplo — GATE: é só dado, nunca lógica disfarçada', () => {
    const fonte = fs.readFileSync(DADOS, 'utf8');

    // Nenhum import/require: o arquivo não pode depender de nada nem puxar o
    // módulo para dentro de si.
    assert.doesNotMatch(fonte, /^\s*import\s/m, 'dadosDeExemplo.js não pode importar nada');
    assert.doesNotMatch(fonte, /\brequire\s*\(/, 'dadosDeExemplo.js não pode usar require()');

    // Nenhuma função — nem declarada, nem arrow, nem método de objeto.
    assert.doesNotMatch(fonte, /\bfunction\b/, 'dadosDeExemplo.js não pode declarar função');
    assert.doesNotMatch(fonte, /=>/, 'dadosDeExemplo.js não pode ter arrow function');

    // E a prova em runtime: nenhum export é chamável.
    const exportados = Object.entries(dadosModulo).filter(([chave]) => chave !== 'default');
    assert.ok(exportados.length > 0, 'o arquivo precisa exportar as constantes dos blocos');
    for (const [chave, valor] of exportados) {
        assert.notEqual(typeof valor, 'function', `export chamável: ${chave}`);
    }
});

test('dadosDeExemplo — tem o comentário de topo dizendo o que é e quando sai', () => {
    const fonte = fs.readFileSync(DADOS, 'utf8');
    const topo = fonte.slice(0, fonte.indexOf('export'));

    assert.match(topo, /exemplo/i);
    assert.match(topo, /nada aqui vem desta conta|nenhum valor aqui vem/i, 'o topo precisa dizer que nada é da conta');
});

test('dadosDeExemplo — as constantes dos blocos do mockup existem', () => {
    for (const chave of ['CONTA_EXEMPLO', 'ERP_EXEMPLO', 'PERIODOS_EXEMPLO', 'ALERTAS_ML_EXEMPLO', 'ATIVIDADE_EXEMPLO', 'TRACAO_EXEMPLO', 'CONVERSAO_EXEMPLO']) {
        assert.ok(chave in dadosModulo, `constante ausente: ${chave}`);
    }
    assert.ok(Array.isArray(dadosModulo.CONVERSAO_EXEMPLO.pontos), 'o sparkline precisa de uma série');
    assert.ok(dadosModulo.CONVERSAO_EXEMPLO.pontos.every((n) => typeof n === 'number'));
});

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

// ═══════════════════════════════════════════════════════════════════════════
// Task 3 — o painel (`PainelVisaoGeral.jsx`), render REAL
//
// ⚠️ O painel está em produção desde 08/10. Os blocos podem mudar de lugar e
// de forma, NÃO de efeito — por isso o primeiro gate abaixo é o de regressão.
// ═══════════════════════════════════════════════════════════════════════════

const PainelVisaoGeral = painelModulo.default;

const indicadoresBase = (over = {}) => ({
    no_ar: 342,
    com_venda: 284,
    sem_oferta: 6,
    publicados_30d: 14,
    publicados_30d_pessoas: 2,
    acervo_disponivel: true,
    nunca_coletado: false,
    no_ar_por_fase: { fase1: 218, kits: 124 },
    criativos_packs: 48,
    tracao_pct: 83,
    ...over,
});

const alertasBase = (over = {}) => ({
    disponivel: true,
    total: 3,
    itens: [
        { chave: 'pausado', label: 'Pausado', cor: 'red', total: 2 },
        { chave: 'sem_estoque', label: 'Sem estoque', cor: 'red', total: 0 },
        { chave: 'ficha_incompleta', label: 'Ficha incompleta', cor: 'amber', total: 1 },
        { chave: 'perdendo_catalogo', label: 'Perdendo catálogo', cor: 'amber', total: 0 },
        { chave: 'foto_insuficiente', label: 'Foto insuficiente', cor: 'amber', total: 0 },
    ],
    ...over,
});

const propsPainel = (over = {}) => ({
    empresa: { chave: 'company-459', nome: 'Kive Shop Eletrônicos', identificador: '28.192.831/0001-94', token: 'ativo', link_reconexao: null },
    liberada: true,
    indicadores: indicadoresBase(),
    oQueFazerAgora: [
        { texto: 'Prontos para a Fase 2', numero: 12, destino: { rota: 'mlb.anuncios.publicador.produtos', params: { conta: 'company-459', filtro: 'publicados', fase: 'so_base' } } },
        { texto: 'Produtos com problema na publicação', numero: 3, destino: { rota: 'mlb.anuncios.publicador.produtos', params: { conta: 'company-459', filtro: 'com_problema' } } },
    ],
    situacaoProdutos: {
        rascunho: { numero: 1, rotulo: 'Rascunho' },
        conferidos: { numero: 2, rotulo: 'Conferidos' },
        publicados: { numero: 3, rotulo: 'Publicados' },
        com_problema: { numero: 0, rotulo: 'Com problema' },
    },
    produtosPorFase: {
        sem_oferta: { numero: 4, rotulo: 'Sem oferta' },
        fase1_publicada: { numero: 3, rotulo: 'Fase 1 publicada' },
        fase2_preparacao: { numero: 2, rotulo: 'Fase 2 em preparação' },
        fase2_publicada: { numero: 1, rotulo: 'Fase 2 publicada' },
        fase3_mais: { numero: 0, rotulo: 'Fase 3+' },
    },
    ultimasPublicacoes: {
        disponivel: true,
        itens: [{ titulo: 'Caneca azul 300ml', ml_item_id: 'MLB123456', tipo: 'classico', quem: { tipo: 'equipe', nome: 'Fulano' }, quando: '2026-10-08T10:00:00Z', vendas: 7, situacao: 'PUBLISHED', fase: 1, rotulo_fase: '1 unidade' }],
    },
    integracoes: {
        mercado_livre: { token: 'ativo' },
        publicacao_liberada: true,
        alavancas_liberada: false,
        portal: { situacao: 'sincronizado', novas: 0, sincronizado_em: '2026-10-08T10:00:00Z' },
        erp: { valor: 'Bling', rotulo: 'Bling' },
    },
    identidadeResumo: { tem_identidade: true, texto_resumo: ['Linha 1', 'Linha 2'] },
    quemPublicou: { equipe: [{ nome: 'Fulano', quantidade: 5, responsavel: true }], cliente: { quantidade: 1 }, origem_antiga: { quantidade: 3 } },
    alertas: alertasBase(),
    abas: { company_id: 459 },
    ...over,
});

const desenharPainel = (props) => renderToStaticMarkup(React.createElement(PainelVisaoGeral, props));

test('Painel — REGRESSÃO: tudo que a tela fazia desde 08/10 continua na tela', () => {
    const html = desenharPainel(propsPainel());

    for (const bloco of [
        'O que fazer agora', 'Situação dos produtos', 'Produtos por fase', 'Últimas publicações',
        'Integrações', 'Identidade visual', 'Quem publicou',
    ]) {
        assert.ok(html.includes(bloco), `bloco sumiu: ${bloco}`);
    }
    // Os rótulos dos cartões antigos continuam, com os mesmos nomes.
    for (const rotulo of ['No ar', 'Com venda', 'Publicados nos últimos 30 dias', 'Sem oferta']) {
        assert.ok(html.includes(rotulo), `rótulo sumiu: ${rotulo}`);
    }
    // Conteúdo dos blocos preservados.
    assert.match(html, /Produtos com problema na publicação/);
    assert.match(html, />Ver<\/button>/, 'o destino de cada linha da fila');
    assert.match(html, /Caneca azul 300ml/);
    assert.match(html, /MLB123456/);
    assert.match(html, /Bling/);
    assert.match(html, /Linha 1/);
    assert.match(html, /Fulano/);
    assert.match(html, /responsável/);
    assert.match(html, />Cliente<\/span>/);
    assert.match(html, /Origem antiga/);
    semLixoNoHtml(html, 'regressão');
});

test('Painel — os 6 KPIs do topo, com os números reais do servidor', () => {
    const html = desenharPainel(propsPainel());

    assert.match(semTags(cartaoDe(html, 'No ar')), /342/);
    assert.match(semTags(html), /218 produtos base/);
    assert.match(semTags(html), /124 produtos em kit/, 'rotulado "kits", nunca "Fase 2"');
    assert.match(semTags(cartaoDe(html, 'Aguardando ação')), /18/, '6 sem oferta + 12 prontos para a Fase 2');
    assert.match(html, /Prontos para a Fase 2/);
    assert.match(semTags(cartaoDe(html, 'Publicados nos últimos 30 dias')), /14/);
    assert.match(semTags(cartaoDe(html, 'Com venda')), /284/);
    assert.match(html, /83% do que está no ar já vendeu/);
    assert.match(semTags(cartaoDe(html, 'Criativos por IA')), /48/);
    assert.match(html, /Revisão humana/);
    semLixoNoHtml(html, '6 KPIs');
});

test('Painel — "kits" NUNCA é chamado de "Fase 2" no cartão do topo (o kit de 3 é Fase 3)', () => {
    const html = desenharPainel(propsPainel());
    const cartao = html.slice(html.indexOf('No ar'), html.indexOf('Aguardando ação'));

    assert.ok(cartao.includes('produtos em kit'));
    assert.ok(!cartao.includes('Fase 2'), 'o sub-número do cartão "No ar" não pode se chamar Fase 2');
});

test('Painel — Revisão humana nasce VAZIA e honesta: nunca um número inventado', () => {
    const html = desenharPainel(propsPainel());

    assert.match(semTags(cartaoDe(html, 'Revisão humana')), /—/);
    assert.doesNotMatch(semTags(cartaoDe(html, 'Revisão humana')), /\d/, 'não existe número de revisão humana');
    assert.match(html, /Não existe no sistema — nada passa por revisão manual hoje/);
});

// ⚠️ A régua INVERTEU em 10/10 (quick 261010-t02b). Até aqui este arquivo tinha
// um gate exigindo que "Platinum", "Estoque Baixo" e "imagens IA" NÃO
// aparecessem — era a régua antiga ("widget sem dado real nasce vazio"). O
// usuário olhou a tela em produção e pediu o contrário: o main/content tem de
// ser o do mockup inteiro, com dado de exemplo onde o real não existe. O gate
// abaixo substitui aquele: o conteúdo do mockup PODE aparecer, desde que venha
// de `dadosDeExemplo.js` e o bloco carregue a pilha.
test('Painel — a régua nova: o que é exemplo aparece, mas sempre marcado', () => {
    const html = desenharPainel(propsPainel());

    // Os blocos do mockup que não têm dado real agora existem na tela.
    assert.match(html, /Conta Líder Platinum/);
    assert.match(html, /Estoque Baixo no Bling/);
    assert.match(html, /Gerou 5 imagens IA/);
    assert.match(html, /Atributo Obrigatório Pendente/);
    assert.match(html, /Reotimizar com IA/);
    assert.match(html, /Ritmo de conversão diária/);

    // E cada um deles está num bloco com a pilha.
    for (const titulo of ['Alertas Meli', 'Atividade da equipe', 'Desempenho rápido das publicações']) {
        assert.match(secaoDe(html, titulo), />exemplo</, `bloco sem a pilha: ${titulo}`);
    }
    semLixoNoHtml(html, 'régua nova');
});

test('Painel — o bloco de dado REAL nunca leva a pilha de exemplo', () => {
    const html = desenharPainel(propsPainel());

    for (const titulo of ['O que fazer agora', 'Situação dos produtos', 'Produtos por fase', 'Últimas publicações', 'Integrações', 'Identidade visual']) {
        assert.doesNotMatch(secaoDe(html, titulo), />exemplo</, `bloco real marcado como exemplo: ${titulo}`);
    }
});

test('Painel — nenhum número fictício solto: todo exemplo vem de dadosDeExemplo.js', () => {
    const fonte = lerSemComentarios(REL_PAINEL);

    assert.match(fonte, /from '\.\/dadosDeExemplo'/, 'o painel precisa importar o arquivo de exemplo');
    // Os literais do mockup não podem estar escritos no JSX.
    for (const literal of ['Conta Líder Platinum', 'Platinum 100%', 'Sincronizado há 8 min', 'Estoque Baixo no Bling', 'imagens IA', 'Reotimizar com IA', 'Otimizar', 'pedidos/dia', '1.420']) {
        assert.ok(!fonte.includes(literal), `literal de exemplo solto no painel: ${literal}`);
    }
});

test('Painel — cabeçalho: nome e identificador são REAIS, a reputação é exemplo', () => {
    const html = desenharPainel(propsPainel());
    const texto = semTags(html);

    assert.match(texto, /Kive Shop Eletrônicos/, 'o nome da conta vem do servidor');
    assert.match(texto, /28\.192\.831\/0001-94/, 'o identificador (CNPJ) vem do servidor');
    assert.match(texto, /Mercado Livre:\s*Conectado/);
    assert.match(texto, /Platinum 100%/, 'a reputação é exemplo, mas aparece');
    assert.match(html, /Visão geral da conta/, 'o título antigo da tela continua');
    semLixoNoHtml(html, 'cabeçalho');
});

test('Painel — o ERP: o NOME é real, o "sincronizado há 8 min" é exemplo', () => {
    const comErp = desenharPainel(propsPainel());
    assert.match(semTags(comErp), /ERP Bling:\s*Sincronizado há 8 min/);

    // Sem ERP declarado não se inventa frescor de sincronização nenhum.
    const semErp = desenharPainel(propsPainel({
        integracoes: { ...propsPainel().integracoes, erp: { valor: null, rotulo: 'Não informado' } },
    }));
    assert.match(semTags(semErp), /ERP:\s*não informado/i);
    assert.doesNotMatch(semTags(semErp), /Sincronizado há 8 min/, 'sem ERP declarado não há frescor para mostrar');
});

test('Painel — ML desconectado não é "Conectado" nem ganha reputação inventada', () => {
    const html = desenharPainel(propsPainel({
        empresa: { chave: 'company-459', nome: 'Kive Shop Eletrônicos', identificador: 'CUST 77', token: 'expirado', link_reconexao: 'https://x' },
        integracoes: { ...propsPainel().integracoes, mercado_livre: { token: 'expirado' } },
    }));
    const cabecalho = html.slice(0, html.indexOf('No ar'));

    assert.doesNotMatch(semTags(cabecalho), /Mercado Livre:\s*Conectado/);
    assert.doesNotMatch(semTags(cabecalho), /Platinum 100%/, 'conta sem token não ganha reputação de exemplo');
    assert.match(semTags(cabecalho), /Conta Líder Platinum/, 'o selo segue sendo exemplo declarado');
});

test('Painel — "Catálogo SKU ativo" é REAL quando há produtos (soma da Situação)', () => {
    const html = desenharPainel(propsPainel());
    // 1 + 2 + 3 + 0 = 6 produtos no catálogo do Publicador.
    assert.match(semTags(html), /Catálogo SKU ativo:\s*6/);
    assert.doesNotMatch(semTags(html), /1\.420/, 'com contagem real o valor de exemplo não entra');
});

test('Painel — sem nenhum produto cadastrado o catálogo cai no valor de exemplo', () => {
    const html = desenharPainel(propsPainel({ situacaoProdutos: {} }));

    assert.match(semTags(html), /Catálogo SKU ativo:\s*1\.420/);
    semLixoNoHtml(html, 'catálogo de exemplo');
});

test('Painel — o seletor de período é VISUAL e diz isso no title', () => {
    const html = desenharPainel(propsPainel());

    for (const opcao of ['Hoje', 'Últimos 7 dias', 'Este mês']) {
        assert.ok(html.includes(opcao), `opção de período ausente: ${opcao}`);
    }
    const tag = tagDoBotao(html, 'Últimos 7 dias');
    assert.match(tag, /title="[^"]*não (está ligado|refiltra)[^"]*"/i, 'o seletor precisa dizer que não filtra');
});

test('Painel — botão de bloco de EXEMPLO não navega e o title diz isso', () => {
    const html = desenharPainel(propsPainel());

    for (const rotulo of ['Corrigir Atributo', 'Pausar Anúncios', 'Reotimizar com IA']) {
        const tag = tagDoBotao(html, rotulo);
        assert.match(tag, /disabled=/, `botão de exemplo navegável: ${rotulo}`);
        assert.match(tag, /title="[^"]*exemplo[^"]*"/i, `botão de exemplo sem title: ${rotulo}`);
    }
});

test('Painel — "Nova publicação direta" é desabilitado e marcado "Em breve"', () => {
    const html = desenharPainel(propsPainel());

    assert.match(html, /Nova publicação direta/);
    assert.match(html, /Em breve/);
    assert.match(tagDoBotao(html, 'Nova publicação direta'), /disabled=/);
});

test('Painel — Alertas saem da triagem: só motivo com número, cada um com destino', () => {
    const html = desenharPainel(propsPainel());

    assert.match(html, /Alertas Meli/, 'o título do mockup (261010-t02b)');
    assert.match(html, /Pausado/);
    assert.match(html, /Ficha incompleta/);
    assert.doesNotMatch(html, /Sem estoque/, 'motivo zerado não vira linha');
    assert.doesNotMatch(html, /Perdendo catálogo/);
    // O rodapé continua dizendo de onde vem o que NÃO é exemplo.
    assert.match(html, /vêm do acervo do Mercado Livre/);
    // O total é o de anúncios distintos (3), não a soma dos chips.
    assert.match(html, />3<\/span>|3 anúncios/);
});

test('Painel — alertas disponivel=false diz o motivo em vez de afirmar zero', () => {
    const html = desenharPainel(propsPainel({ alertas: { disponivel: false, total: 0, itens: [] } }));

    assert.match(html, /Alertas Meli/);
    assert.match(html, /Disponível só para empresas cadastradas no sistema/);
    assert.doesNotMatch(html, /Nenhum alerta no acervo/, '"sem Company" não é "sem alerta"');
});

test('Painel — alertas disponíveis e todos zerados dizem "nenhum alerta"', () => {
    const html = desenharPainel(propsPainel({
        alertas: alertasBase({ total: 0, itens: alertasBase().itens.map((i) => ({ ...i, total: 0 })) }),
    }));

    assert.match(html, /Nenhum alerta no acervo desta conta\./);
});

test('Painel — sem a prop `alertas` (servidor antigo) o bloco nem aparece', () => {
    const semAlertas = propsPainel();
    delete semAlertas.alertas;
    const html = desenharPainel(semAlertas);

    assert.doesNotMatch(html, /Alertas Meli/);
    assert.match(html, /O que fazer agora/, 'o resto da tela continua inteiro');
});

test('Painel — Desempenho rápido divide com venda × sem venda e não promete 30 dias', () => {
    const html = desenharPainel(propsPainel());

    assert.match(html, /Desempenho rápido das publicações/);
    assert.match(html, /Com venda registrada \(284 anúncios\)/);
    assert.match(html, /Sem venda registrada \(58 anúncios\)/);
    assert.match(html, /Venda acumulada do anúncio, não uma janela de 30 dias\./);
    assert.doesNotMatch(html, /Tração \(30/i, 'o rótulo do mockup afirmaria uma janela que o dado não tem');
    assert.match(html, /Ver alavancas desta conta/);
});

test('Painel — sem acervo coletado o Desempenho rápido diz o motivo, nunca 0%', () => {
    const html = desenharPainel(propsPainel({
        indicadores: indicadoresBase({ no_ar: null, com_venda: null, tracao_pct: null, nunca_coletado: true }),
    }));

    assert.match(html, /Acervo ainda não coletado/);
    // ⚠️ Escopado ao BLOCO desde o 261010-t02b: o cabeçalho passou a mostrar a
    // reputação de exemplo "(Platinum 100%)", e um `/0%/` solto casaria com os
    // dois últimos caracteres de "100%" — asserção que não prova mais nada.
    assert.doesNotMatch(secaoDe(html, 'Desempenho rápido das publicações'), /0%/, '"não medimos" nunca pode virar 0%');
    assert.match(html, /Atualizar agora/);
    assert.match(semTags(cartaoDe(html, 'No ar')), /—/);
});

test('Painel — acervo coletado com zero anúncio no ar é outra coisa: medido e vazio', () => {
    const html = desenharPainel(propsPainel({
        indicadores: indicadoresBase({ no_ar: 0, com_venda: 0, tracao_pct: null }),
    }));

    assert.match(html, /Nenhum anúncio no ar para medir\./);
    assert.doesNotMatch(html, /Acervo ainda não coletado/);
    assert.doesNotMatch(html, /Atualizar agora/);
});

test('Painel — sem Company (D23): motivo explícito e sem botão de atualizar', () => {
    const html = desenharPainel(propsPainel({
        indicadores: indicadoresBase({ no_ar: null, com_venda: null, tracao_pct: null, acervo_disponivel: false, no_ar_por_fase: { fase1: null, kits: null }, criativos_packs: 0 }),
        ultimasPublicacoes: { disponivel: false, itens: [] },
        alertas: { disponivel: false, total: 0, itens: [] },
        abas: { company_id: null },
    }));

    assert.match(html, /Disponível só para empresas cadastradas no sistema/);
    assert.doesNotMatch(html, /Atualizar agora/);
    semLixoNoHtml(html, 'D23');
});

test('Painel — o sub-número "Prontos para a Fase 2" some quando a linha não existe, sem virar 0', () => {
    const html = desenharPainel(propsPainel({ oQueFazerAgora: [] }));

    assert.match(semTags(cartaoDe(html, 'Aguardando ação')), /6/, 'sobra só o "sem oferta"');
    const cartao = html.slice(html.indexOf('Aguardando ação'), html.indexOf('Publicados nos últimos 30 dias'));
    assert.ok(!cartao.includes('Prontos para a Fase 2'));
});

// ═══════════════════════════════════════════════════════════════════════════
// Quick 261010-t02b — Task 3: coluna da direita e o card de Desempenho
// ═══════════════════════════════════════════════════════════════════════════

test('Painel — Alertas: os do acervo são reais, os dois do mockup são exemplo', () => {
    const html = desenharPainel(propsPainel());
    const bloco = secaoDe(html, 'Alertas Meli');

    // Reais (triagem do acervo) — continuam clicáveis.
    assert.match(bloco, /Pausado/);
    assert.match(bloco, /Ficha incompleta/);
    // De exemplo (não existem no sistema) — presentes e marcados.
    assert.match(bloco, /Atributo Obrigatório Pendente/);
    assert.match(bloco, /Estoque Baixo no Bling/);
    assert.match(bloco, />exemplo</);
    assert.match(bloco, /vêm do acervo do Mercado Livre/);
});

test('Painel — Atividade da equipe: as primeiras linhas são as publicações REAIS', () => {
    const html = desenharPainel(propsPainel());
    const bloco = secaoDe(html, 'Atividade da equipe');

    assert.match(bloco, /Tempo real/);
    // Real: sai de `ultimasPublicacoes` (quem + quando + título + fase).
    assert.match(bloco, /Fulano/);
    assert.match(bloco, /Caneca azul 300ml/);
    // Exemplo: os eventos que não existem como registro.
    assert.match(bloco, /Gerou 5 imagens IA/);
    assert.match(bloco, /aprovada para disparo/);
    assert.match(bloco, />exemplo</);
    // E o "Quem publicou" (real) continua inteiro dentro do bloco.
    assert.match(bloco, /Quem publicou/);
    assert.match(bloco, /responsável/);
    assert.match(bloco, /Ver histórico completo/);
});

test('Painel — sem publicação real a Atividade não finge: só as linhas de exemplo', () => {
    const html = desenharPainel(propsPainel({
        ultimasPublicacoes: { disponivel: true, itens: [] },
        quemPublicou: { equipe: [], cliente: { quantidade: 0 }, origem_antiga: { quantidade: 0 } },
    }));
    const bloco = secaoDe(html, 'Atividade da equipe');

    assert.match(bloco, /Nenhuma publicação nos últimos 30 dias\./);
    assert.match(bloco, /Gerou 5 imagens IA/, 'as linhas de exemplo seguem desenhando o bloco');
    assert.doesNotMatch(bloco, /Caneca azul/);
});

test('Painel — Desempenho: o número de dormentes é REAL, a recomendação é exemplo', () => {
    const html = desenharPainel(propsPainel());
    const bloco = secaoDe(html, 'Desempenho rápido das publicações');

    assert.match(bloco, /Alavanca Recomendada/i);
    assert.match(semTags(bloco), /Otimizar 58 anúncios dormentes/, '58 = 342 no ar − 284 com venda, número real');
    assert.match(bloco, /Reotimizar com IA/);
    assert.match(bloco, /Ritmo de conversão diária/);
    assert.match(bloco, /pedidos\/dia/);
    assert.match(bloco, /<(polyline|path|svg)/, 'o sparkline do mockup precisa existir');
    assert.match(bloco, />exemplo</);
    // E o que já existia continua.
    assert.match(bloco, /Com venda registrada \(284 anúncios\)/);
    assert.match(bloco, /Ver alavancas desta conta/);
});

test('Painel — sem número real de dormentes a alavanca não inventa um', () => {
    const html = desenharPainel(propsPainel({
        indicadores: indicadoresBase({ no_ar: null, com_venda: null, tracao_pct: null, nunca_coletado: true }),
    }));
    const bloco = secaoDe(html, 'Desempenho rápido das publicações');

    assert.match(semTags(bloco), /Otimizar os anúncios dormentes/);
    assert.doesNotMatch(semTags(bloco), /Otimizar \d+ anúncios/, 'sem acervo não há contagem de dormentes');
});

test('Painel — o par de cards Criativos / Identidade usa os números REAIS', () => {
    const html = desenharPainel(propsPainel());
    const par = secaoDe(html, '>Criativos<');

    assert.match(par, />Identidade</, 'o par do mockup tem os dois cards');
    assert.match(semTags(par), /48/, 'os packs são o número real do acervo');
    assert.match(par, /<svg|chevron|lucide/i, 'cada card do par tem a seta do mockup');
    assert.doesNotMatch(par, />exemplo</, 'o par usa número real, não leva pilha');
    assert.doesNotMatch(secaoDe(html, 'Identidade visual'), />exemplo</, 'card de dado real não leva pilha');
});

test('Painel — nenhuma flag de escopo do componente entra nos .map() novos', () => {
    const fonte = lerSemComentarios(REL_PAINEL);
    const proibidas = ['acervoIndisponivel', 'nuncaColetado', 'semAcervoOuNuncaColetado', 'identidadeTemTexto', 'ultimasDisponiveis', 'aguardandoAcao', 'tracaoPct', 'semVenda', 'companyIdAbas', 'contaChave', 'periodoEscolhido', 'catalogoTexto', 'mlConectado'];

    for (const marcador of ['PERIODOS_EXEMPLO.opcoes.map(', 'ALERTAS_ML_EXEMPLO.map(', 'ATIVIDADE_EXEMPLO.map(', 'atividadeReal.map(']) {
        const inicio = fonte.indexOf(marcador);
        assert.ok(inicio > -1, `.map() não encontrado: ${marcador}`);
        const trecho = fonte.slice(inicio, fonte.indexOf('})}', inicio));
        for (const flag of proibidas) {
            assert.ok(!trecho.includes(flag), `flag de escopo dentro de ${marcador}: ${flag}`);
        }
    }
});

test('Painel — TELA PRETA: os campos novos do cabeçalho como objeto, nulos e ausentes', () => {
    const formas = [
        { empresa: { chave: 'c', nome: { pt: 'x' }, identificador: { cnpj: '1' }, token: { t: 1 } } },
        { empresa: { chave: 'c', nome: null, identificador: null, token: null } },
        { empresa: null },
        { empresa: {} },
        { integracoes: { erp: { valor: { nome: 'Bling' } } } },
        { integracoes: { erp: null } },
        { integracoes: { mercado_livre: { token: { t: 'ativo' } } } },
        { situacaoProdutos: { a: { numero: { n: 1 } }, b: 'x', c: null } },
        { situacaoProdutos: [] },
        { ultimasPublicacoes: { disponivel: true, itens: [{ titulo: { t: 'x' }, quem: 'texto', quando: { d: 1 }, rotulo_fase: ['a'] }] } },
    ];

    for (const forma of formas) {
        const html = desenharPainel(propsPainel(forma));
        semLixoNoHtml(html, JSON.stringify(forma));
        assert.match(html, /Visão geral da conta/, JSON.stringify(forma));
        assert.match(html, /Atividade da equipe/, JSON.stringify(forma));
    }
});

test('Painel — numeroDaLinha e alertasSeguros recusam formas inesperadas', () => {
    assert.equal(painelModulo.numeroDaLinha(null, 'x'), null);
    assert.equal(painelModulo.numeroDaLinha('não é lista', 'x'), null);
    assert.equal(painelModulo.numeroDaLinha([null, { texto: 'x', numero: 4 }], 'x'), 4);
    assert.equal(painelModulo.numeroDaLinha([{ texto: 'x', numero: 'quatro' }], 'x'), null);
    assert.equal(painelModulo.numeroDaLinha([{ texto: { a: 1 }, numero: 4 }], 'x'), null);

    assert.equal(painelModulo.alertasSeguros(null).presente, false);
    assert.equal(painelModulo.alertasSeguros({ itens: [] }).presente, false, 'sem `disponivel` booleano é servidor antigo');
    assert.equal(painelModulo.alertasSeguros([]).presente, false);
    assert.equal(painelModulo.alertasSeguros({ disponivel: true, itens: 'x', total: 'y' }).total, 0);
    assert.deepEqual(painelModulo.alertasSeguros({ disponivel: true, itens: [null, { chave: 7 }, { chave: 'ok' }] }).itens, [{ chave: 'ok' }]);
});

test('Painel — a TELA PRETA de 07/10: cada campo novo como objeto, nulo e ausente', () => {
    const formas = [
        { no_ar_por_fase: { fase1: { total: 218 }, kits: ['124'] }, criativos_packs: { n: 48 }, tracao_pct: { pct: 83 } },
        { no_ar_por_fase: null, criativos_packs: null, tracao_pct: null },
        { no_ar_por_fase: 'não é objeto', criativos_packs: '48', tracao_pct: '83' },
        { no_ar_por_fase: [218, 124], criativos_packs: [48], tracao_pct: [83] },
        { no_ar_por_fase: { fase1: Number.NaN, kits: Number.NaN }, criativos_packs: Number.NaN, tracao_pct: Number.NaN },
    ];
    for (const forma of formas) {
        const html = desenharPainel(propsPainel({ indicadores: indicadoresBase(forma) }));
        semLixoNoHtml(html, JSON.stringify(forma));
        assert.match(html, /O que fazer agora/, JSON.stringify(forma));
    }

    // Chaves simplesmente AUSENTES (payload de antes deste plano).
    const antigos = indicadoresBase();
    delete antigos.no_ar_por_fase;
    delete antigos.criativos_packs;
    delete antigos.tracao_pct;
    const html = desenharPainel(propsPainel({ indicadores: antigos }));
    assert.match(semTags(cartaoDe(html, 'Criativos por IA')), /—/, 'sem a chave, "—" e o motivo; nunca 0');
    semLixoNoHtml(html, 'indicadores sem as chaves novas');
});

test('Painel — `alertas` em formato adverso nunca derruba a tela', () => {
    for (const alertas of [
        { disponivel: true, total: { n: 3 }, itens: [{ chave: 'pausado', label: { pt: 'x' }, cor: { c: 1 }, total: { t: 2 } }] },
        { disponivel: true, itens: [{ chave: 'pausado', label: 'Pausado', cor: 'red', total: 'dois' }] },
        { disponivel: true, itens: 'nem é lista' },
        'string',
        [],
        42,
    ]) {
        const html = desenharPainel(propsPainel({ alertas }));
        semLixoNoHtml(html, JSON.stringify(alertas));
        assert.match(html, /O que fazer agora/, JSON.stringify(alertas));
    }
});

test('Painel — `indicadores: null` e todas as props ausentes continuam renderizando', () => {
    const nulo = desenharPainel(propsPainel({ indicadores: null }));
    assert.match(nulo, /O que fazer agora/);
    semLixoNoHtml(nulo, 'indicadores null');

    const vazio = renderToStaticMarkup(React.createElement(PainelVisaoGeral, {}));
    assert.match(vazio, /Nada pendente nesta conta\./);
    assert.match(vazio, /Revisão humana/);
    semLixoNoHtml(vazio, 'sem props');

    for (const props of [
        { empresa: null, indicadores: null, oQueFazerAgora: null, situacaoProdutos: null, ultimasPublicacoes: null, integracoes: null, identidadeResumo: null, quemPublicou: null, alertas: null, abas: null },
        { empresa: 'x', indicadores: [], oQueFazerAgora: 'y', situacaoProdutos: 7, ultimasPublicacoes: 'z', integracoes: [], identidadeResumo: 3, quemPublicou: 'w', alertas: 0, abas: 'v' },
    ]) {
        const html = desenharPainel(props);
        semLixoNoHtml(html, JSON.stringify(props));
        assert.match(html, /Visão geral da conta/);
    }
});

test('Painel — nenhuma flag de escopo do componente é lida dentro do .map() dos alertas', () => {
    const fonte = lerSemComentarios(REL_PAINEL);
    const inicio = fonte.indexOf('alerta.itens.map(');
    assert.ok(inicio > -1, 'o .map() dos alertas não foi encontrado');
    const trecho = fonte.slice(inicio, fonte.indexOf('</section>', inicio));

    for (const flag of ['acervoIndisponivel', 'nuncaColetado', 'semAcervoOuNuncaColetado', 'identidadeTemTexto', 'ultimasDisponiveis', 'aguardandoAcao', 'tracaoPct', 'semVenda', 'companyIdAbas', 'contaChave']) {
        assert.ok(!trecho.includes(flag), `flag de escopo lida dentro do .map(): ${flag}`);
    }
});

for (const relativo of [REL_CARTAO, REL_PAINEL, REL_SELO]) {
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
