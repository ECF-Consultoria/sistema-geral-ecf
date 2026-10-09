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

/** Só o texto visível: sem tags, sem os marcadores de hidratação do React. */
function semTags(html) {
    return html.replace(/<[^>]*>/g, '').replace(/<!--[\s\S]*?-->/g, '');
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
    empresa: { chave: 'company-459', nome: 'Kive Shop Eletrônicos', token: 'ativo', link_reconexao: null },
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

test('Painel — nada afirma estoque de ERP, reputação de conta nem revisão aprovada', () => {
    const html = desenharPainel(propsPainel());

    assert.doesNotMatch(html, /Platinum|Conta Líder|reputaç/i);
    assert.doesNotMatch(html, /Estoque Bling|Estoque Baixo|estoque sincronizado/i);
    assert.doesNotMatch(html, /Revisão de Qualidade|revisão aprovada|imagens IA/i);
    assert.doesNotMatch(html, /Giro Alto|Volume Alto|demanda orgânica com/i);
    // E o que o mockup prometia sobre o ERP é desmentido explicitamente.
    assert.match(html, /Não lemos estoque do ERP/);
});

test('Painel — "Nova publicação direta" é desabilitado e marcado "Em breve"', () => {
    const html = desenharPainel(propsPainel());

    assert.match(html, /Nova publicação direta/);
    assert.match(html, /Em breve/);
    assert.match(tagDoBotao(html, 'Nova publicação direta'), /disabled=/);
});

test('Painel — Alertas saem da triagem: só motivo com número, cada um com destino', () => {
    const html = desenharPainel(propsPainel());

    assert.match(html, /Alertas do acervo/);
    assert.match(html, /Pausado/);
    assert.match(html, /Ficha incompleta/);
    assert.doesNotMatch(html, /Sem estoque/, 'motivo zerado não vira linha');
    assert.doesNotMatch(html, /Perdendo catálogo/);
    // O título não promete ERP, e o rodapé diz de onde vem.
    assert.doesNotMatch(html, /Alertas Meli & ERP/);
    assert.match(html, /Nada aqui vem do ERP/);
    // O total é o de anúncios distintos (3), não a soma dos chips.
    assert.match(html, />3<\/span>|3 anúncios/);
});

test('Painel — alertas disponivel=false diz o motivo em vez de afirmar zero', () => {
    const html = desenharPainel(propsPainel({ alertas: { disponivel: false, total: 0, itens: [] } }));

    assert.match(html, /Alertas do acervo/);
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

    assert.doesNotMatch(html, /Alertas do acervo/);
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
    assert.doesNotMatch(html, /0%/, '"não medimos" nunca pode virar 0%');
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

for (const relativo of [REL_CARTAO, REL_PAINEL]) {
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
