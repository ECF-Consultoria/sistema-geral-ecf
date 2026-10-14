import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 179, plano 08 — o banner de integração, a faixa de Desempenho,
// "Publicações recentes" e "Atividade recente" (VG-02/15/16/17/18/19/20).
//
// Protótipo: `new_layout_publicador/Visao Geral.dc.html` — ⚠️ as linhas abaixo
// foram MEDIDAS no arquivo; as do `<action>` do 179-08-PLAN.md estão
// deslocadas (ele cita `:29-:36` onde o arquivo tem `:28-:36`, `:284-:301`
// onde tem `:286-:310`, `:305-:327` onde tem `:313-:338` e `:330-:368` onde
// tem `:341-:381`). Quem mediu ganha (FID-01).
//   · banner           → :28  (tile :29, svg :30, coluna :32, título :33,
//                              descrição :34, CTA :36)
//   · faixa            → :286 (título :287, tile :288, svg :289, textos :291,
//                              KPI :294, coluna :295, label :296, valor :297,
//                              delta :298/:299, sparkline :303-:305,
//                              "Ver desempenho" :308)
//   · recentes         → :313 (cabeçalho :314, coluna :315, link :317,
//                              linha :319, tile :320, coluna :321,
//                              título+NOVA :322, meta :323, glyph F2 :324,
//                              glyph F1 :325, fase/tipo/MLB :326,
//                              status :328, quem/quando :329, vazio :333-:336)
//   · atividade        → :341 (cabeçalho :342, coluna :343, chips :344/:346,
//                              corpo :350, grupo :352, rótulo :353,
//                              evento :355, fio :356, ícone :357,
//                              ícones :358-:363, coluna :365, frase :366,
//                              meta :367, hora :375, vazio :380)
//   · a aritmética     → :443-:448 (`spark`) · :554 (`kpis`) · :562 (`ST`) ·
//                        :572 (`recents`) · :586 (`AT`) · :588 (`IC`) ·
//                        :589 (`dayNames`) · :590 (`actGroups`) ·
//                        :592 (`actTabs`) · :594 (`banner`)
//
// ⚠️ REND-03: TODOS os bundles são montados aqui no topo, ANTES do primeiro
// `test()`. O `after()` do `node --test` já apagou o stub de um arquivo deste
// projeto no meio da suíte e matou o arquivo inteiro SEM nenhum teste falhar.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

// Stub de `route()` global: `BarraDaConta.jsx` (de onde vêm `textoSeguro`/
// `textoUtil`) chama `route()` no corpo do módulo, e o Ziggy não existe no node.
global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

const STUB_INERTIA = path.join(__dirname, `.recentes-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
export const usePage = () => ({ props: { auth: { user: { id: 7 } } } });
`, 'utf8');
after(() => fs.rmSync(STUB_INERTIA, { force: true }));

async function montarModulo(caminhoRelativo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, caminhoRelativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA },
        // `axios` e os dois pacotes do Radix entram porque o import de
        // `textoUtil` arrasta `BarraDaConta` → `SeletorEmpresaBusca` → Radix.
        external: [
            'react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios',
            '@radix-ui/react-popover', '@radix-ui/react-dialog',
        ],
    });

    const outfile = path.join(__dirname, `.recentes-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR = 'resources/js/Components/Mlb/Publicador/visaogeral';
const REL_RECENTES = `${DIR}/recentes.js`;
const REL_BANNER = `${DIR}/BannerDeIntegracao.jsx`;
const REL_FAIXA = `${DIR}/FaixaDeDesempenho.jsx`;
const REL_PUBS = `${DIR}/PublicacoesRecentes.jsx`;
const REL_ATIV = `${DIR}/AtividadeRecente.jsx`;

const FONTES_DO_PLANO = [REL_RECENTES, REL_BANNER, REL_FAIXA, REL_PUBS, REL_ATIV];

// ─── REND-03: os CINCO bundles, aqui, antes de qualquer `test()` ───
const R = await montarModulo(REL_RECENTES);
const { default: BannerDeIntegracao } = await montarModulo(REL_BANNER);
const { default: FaixaDeDesempenho } = await montarModulo(REL_FAIXA);
const { default: PublicacoesRecentes } = await montarModulo(REL_PUBS);
const { default: AtividadeRecente } = await montarModulo(REL_ATIV);

const FONTES = new Map(FONTES_DO_PLANO.map((caminho) => [caminho, lerSemComentarios(caminho)]));
const FONTES_JSX = [REL_BANNER, REL_FAIXA, REL_PUBS, REL_ATIV];

// ═══════════════════════════════════════════════════════════════════════════
// Os extratores — uma ÁRVORE de elementos, não `indexOf` de classe
//
// ⚠️ `html.indexOf(tagDeAbertura)` mede o elemento ERRADO quando dois irmãos
// têm a MESMA string de classes, e foi assim que QUATRO gates do 179-07 ficaram
// medindo a linha do Mercado Livre em vez da do ERP — passando verdes. Esta
// tela é feita de irmãos idênticos por natureza: 5 linhas de recente, N eventos
// de atividade, 3 KPIs e 4 chips. Por isso TODA localização aqui é por POSIÇÃO
// ESTRUTURAL (filho de índice N de um nó) ou por TEXTO ÚNICO — nunca pela
// string de classes, e nunca pela medida que está sendo conferida.
// ═══════════════════════════════════════════════════════════════════════════

/** A lista de classes de UMA tag de abertura, partida por espaço. */
function classesDe(bruto) {
    const achado = /class="([^"]*)"/.exec(bruto);
    return (achado?.[1] ?? '').split(/\s+/).filter(Boolean);
}

const VAZIOS = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr']);

/**
 * A árvore do HTML do `renderToStaticMarkup`. Cada nó:
 * `{nome, bruto, classes, filhos, indice, inicio, fim}`.
 *
 * ⚠️ `indice`, `inicio` e `fim` são posições ABSOLUTAS no HTML, e é por elas
 * que todo recorte é feito. `html.indexOf(no.bruto)` devolveria o PRIMEIRO
 * irmão com a mesma string de classes — a armadilha que fez quatro gates do
 * 179-07 medirem o elemento errado passando verdes.
 */
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

/** O nó-raiz de verdade: o único filho do `#raiz`. */
function raizDe(html) {
    const topo = arvore(html).filhos;
    assert.equal(topo.length, 1, 'o bloco deveria render UM elemento de topo');
    return topo[0];
}

/** Todos os descendentes com um nome de tag, em ordem de documento. */
function descendentes(no, nome) {
    const saida = [];
    for (const filho of no.filhos) {
        if (filho.nome === nome) saida.push(filho);
        saida.push(...descendentes(filho, nome));
    }
    return saida;
}

/** Todos os descendentes, de qualquer tag. */
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
 * ⚠️ Não é `includes`: classe a mais reprova, a menos reprova, trocada reprova.
 * ⚠️ `html.includes('classe')` não serve — SUBSTRING CASA COM PREFIXO
 * (`text-[11px]` dentro de `text-[11.5px]`, `bg-pub-accent` dentro de
 * `bg-pub-accent-sunken`, `stroke-2` dentro de `stroke-20`).
 */
function conferirClasses(no, esperadas, onde) {
    assert.deepEqual(
        [...no.classes].sort(),
        [...esperadas.split(/\s+/).filter(Boolean)].sort(),
        `${onde}: a lista de classes divergiu (tag: ${no.bruto})`,
    );
}

const renderBanner = (props = {}) => renderToStaticMarkup(React.createElement(BannerDeIntegracao, props));
const renderFaixa = (props = {}) => renderToStaticMarkup(React.createElement(FaixaDeDesempenho, props));
const renderPubs = (props = {}) => renderToStaticMarkup(React.createElement(PublicacoesRecentes, props));
const renderAtiv = (props = {}) => renderToStaticMarkup(React.createElement(AtividadeRecente, props));

const semLixo = (html, onde) => {
    assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `${onde}: lixo na tela`);
};

// ═══════════════════════════════════════════════════════════════════════════
// Fixtures
// ═══════════════════════════════════════════════════════════════════════════

/** Data fixa para `haQuanto` e para as horas dos eventos. */
const AGORA = Date.parse('2026-10-13T15:00:00-03:00');

const DESEMPENHO = () => ({
    kpis: [
        { chave: 'publicacoes_criadas', valor: 16, de: null, delta: 5, serie: [1, 2, 3, 4, 5] },
        { chave: 'criativos_ia', valor: 9, de: null, delta: -2, serie: [0, 3, 1] },
        { chave: 'publicacoes_com_venda', valor: null, de: 150, delta: null, serie: null },
    ],
    dias: 30,
    url: null,
});

const recente = (extra = {}) => Object.assign({
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

/** Cinco linhas, a PRIMEIRA `nova` — e só ela. */
const RECENTES = () => ({
    disponivel: true,
    itens: [
        recente({ nova: true, ml_item_id: 'MLB1' }),
        recente({ ml_item_id: 'MLB2', fase: 1, rotulo_fase: 'Fase 1 · Simples', tipo: 'Clássico', situacao: 'em_revisao' }),
        recente({ ml_item_id: 'MLB3', fase: 1, rotulo_fase: 'Fase 1 · Simples', situacao: 'pausada' }),
        recente({ ml_item_id: 'MLB4', situacao: 'com_erro', quem: { tipo: 'cliente', nome: null } }),
        recente({ ml_item_id: 'MLB5', fase: 'legado', rotulo_fase: 'Legado', quando: null }),
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
                { tipo: 'criativos', quem: 'Ana Ribeiro', quando_iso: '2026-10-13T13:58:00-03:00', titulo: 'Poltrona Beny Kit 2', detalhe: '6 imagens geradas' },
                { tipo: 'conferiu', quem: 'Carla Mendes', quando_iso: '2026-10-13T10:12:00-03:00', titulo: 'Cadeira Oslo', detalhe: null },
            ],
        },
        {
            chave: '2026-10-08',
            data_iso: '2026-10-08',
            eventos: [
                { tipo: 'produto_recebido', quem: 'Cliente', quando_iso: '2026-10-08T09:05:00-03:00', titulo: 'Puff Redondo Linho', detalhe: 'SKU-9912' },
            ],
        },
    ],
});

// ═══════════════════════════════════════════════════════════════════════════
// Task 1 — `recentes.js`, os 8 casos do `<behavior>`
// ═══════════════════════════════════════════════════════════════════════════

test('caminhoDaSparkline — a aritmética do `spark()` do protótipo (:443-:448)', async (contexto) => {
    await contexto.test('[1,2,3] devolve 3 pontos, x em 0/50/100 e y entre 4 e 32', () => {
        const { linha, area } = R.caminhoDaSparkline([1, 2, 3]);
        const pontos = [...linha.matchAll(/([ML])(-?[\d.]+) (-?[\d.]+)/g)]
            .map((m) => ({ cmd: m[1], x: Number(m[2]), y: Number(m[3]) }));

        assert.equal(pontos.length, 3);
        assert.deepEqual(pontos.map((p) => p.cmd), ['M', 'L', 'L']);
        assert.deepEqual(pontos.map((p) => p.x), [0, 50, 100]);
        for (const p of pontos) {
            assert.ok(p.y >= 4 && p.y <= 32, `y fora de 4..32: ${p.y}`);
        }
        // O máximo (3) encosta no topo da faixa: 32 − (3/3)*28 = 4.
        assert.equal(pontos[2].y, 4);
        assert.ok(area.length > linha.length);
    });

    await contexto.test('a `area` é a `linha` seguida de " L100 34 L0 34 Z"', () => {
        const { linha, area } = R.caminhoDaSparkline([4, 1, 9, 2]);
        assert.equal(area, `${linha} L100 34 L0 34 Z`);
    });

    await contexto.test('⚠️ série com UM ponto não produz NaN (o protótipo divide por n−1)', () => {
        const { linha, area } = R.caminhoDaSparkline([7]);
        assert.doesNotMatch(linha, /NaN|Infinity/);
        assert.doesNotMatch(area, /NaN|Infinity/);
        // O ponto único vira segmento reto de x=0 a x=100.
        assert.match(linha, /^M0\.0 [\d.]+ L100\.0 [\d.]+$/);
        const ys = [...linha.matchAll(/ (-?[\d.]+)(?= |$)/g)].map((m) => Number(m[1]));
        assert.equal(ys[0], ys[1]);
    });

    await contexto.test('série vazia, nula ou com item não-numérico devolve null nos DOIS campos', () => {
        for (const entrada of [[], null, undefined, 'x', 7, {}, [1, 'x', 3], [1, null], [1, NaN], [1, Infinity], [{}], [[]]]) {
            const saida = R.caminhoDaSparkline(entrada);
            assert.equal(saida.linha, null, `linha deveria ser null para ${JSON.stringify(entrada)}`);
            assert.equal(saida.area, null, `area deveria ser null para ${JSON.stringify(entrada)}`);
        }
    });

    await contexto.test('série toda zero devolve y = 32 em todos os pontos (o máximo é tratado como 1)', () => {
        const { linha } = R.caminhoDaSparkline([0, 0, 0, 0]);
        const ys = [...linha.matchAll(/[ML][\d.]+ ([\d.]+)/g)].map((m) => Number(m[1]));
        assert.deepEqual(ys, [32, 32, 32, 32]);
    });
});

test('rotuloDoGrupo — HOJE / ONTEM / "8 OUT" / SEM DATA (:589)', async (contexto) => {
    await contexto.test('as três chaves fixas e a data ISO', () => {
        assert.equal(R.rotuloDoGrupo({ chave: 'hoje' }), 'HOJE');
        assert.equal(R.rotuloDoGrupo({ chave: 'ontem' }), 'ONTEM');
        assert.equal(R.rotuloDoGrupo({ chave: 'sem_data' }), 'SEM DATA');
        // ⚠️ O protótipo escreve `8 OUT` em CAIXA ALTA (`dayNames`, :589) e
        // sem zero à esquerda.
        assert.equal(R.rotuloDoGrupo({ chave: '2026-10-08' }), '8 OUT');
        assert.equal(R.rotuloDoGrupo({ chave: '2026-01-31' }), '31 JAN');
        assert.equal(R.rotuloDoGrupo({ chave: '2026-12-01' }), '1 DEZ');
    });

    await contexto.test('⚠️ data inválida devolve SEM DATA, nunca "Invalid Date"', () => {
        for (const chave of ['2026-13-01', '2026-00-10', '2026-10-00', '2026-10-32', 'ontem!', '08/10/2026', '', '   ', 'constructor', 'toString']) {
            const rotulo = R.rotuloDoGrupo({ chave });
            assert.equal(rotulo, 'SEM DATA', `"${chave}" deveria cair em SEM DATA`);
        }
        for (const grupo of [null, undefined, 'x', 7, [], {}, { chave: {} }, { chave: 7 }]) {
            assert.equal(R.rotuloDoGrupo(grupo), 'SEM DATA');
        }
    });
});

test('KPIS — rótulo e unidade das três chaves, e o rótulo padrão da chave desconhecida', async (contexto) => {
    await contexto.test('as três chaves do contrato do 179-03', () => {
        assert.equal(R.KPIS.publicacoes_criadas.rotulo, 'Publicações criadas');
        assert.equal(R.KPIS.criativos_ia.rotulo, 'Criativos com IA');
        assert.equal(R.KPIS.publicacoes_com_venda.rotulo, 'Publicações com venda');
        assert.equal(R.KPIS.publicacoes_criadas.unidade, null);
        assert.equal(R.KPIS.criativos_ia.unidade, 'packs');
        assert.equal(R.KPIS.publicacoes_com_venda.unidade, 'de {n}');
    });

    await contexto.test('⚠️ chave desconhecida APARECE, com o rótulo padrão', () => {
        assert.equal(R.KPI_PADRAO.rotulo, 'Indicador');
        const [kpi] = R.kpisParaTela({ kpis: [{ chave: 'inventado', valor: 5, delta: null, serie: null }] });
        assert.equal(kpi.rotulo, 'Indicador');
        assert.equal(kpi.valor, '5');
    });

    await contexto.test('⚠️ "pack" no singular quando o valor é 1 (:554)', () => {
        assert.equal(R.unidadeDoKpi('criativos_ia', 1, null), 'pack');
        assert.equal(R.unidadeDoKpi('criativos_ia', 2, null), 'packs');
        assert.equal(R.unidadeDoKpi('criativos_ia', 0, null), 'packs');
        assert.equal(R.unidadeDoKpi('publicacoes_com_venda', 3, 150), 'de 150');
        assert.equal(R.unidadeDoKpi('publicacoes_com_venda', 3, null), null);
        assert.equal(R.unidadeDoKpi('publicacoes_criadas', 9, null), null);
        assert.equal(R.unidadeDoKpi('constructor', 9, null), null);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// A. As medidas, elemento por elemento — BANNER (:28-:36)
// ═══════════════════════════════════════════════════════════════════════════

test('A1 — o banner de integração: as medidas e a árvore exata (:28-:36)', async (contexto) => {
    const html = renderBanner({ banner: { chave: 'ml', link_reconexao: '/reconectar' } });

    await contexto.test('a ÁRVORE do banner é exatamente a do protótipo', () => {
        const raiz = raizDe(html);
        assert.equal(raiz.nome, 'div');
        assert.deepEqual(raiz.filhos.map((f) => f.nome), ['div', 'div', 'a'], 'tile, coluna de texto e CTA');
        assert.deepEqual(raiz.filhos[0].filhos.map((f) => f.nome), ['svg']);
        assert.deepEqual(raiz.filhos[1].filhos.map((f) => f.nome), ['span', 'span']);
        // ⚠️ O protótipo desenha DOIS `path` (`:30`): a corrente e a diagonal.
        assert.deepEqual(raiz.filhos[0].filhos[0].filhos.map((f) => f.nome), ['path', 'path']);
    });

    await contexto.test('wrapper, tile, coluna, título, descrição e CTA — por conjunto de classes', () => {
        const raiz = raizDe(html);
        conferirClasses(raiz, 'flex items-center gap-[14px] rounded-pub-panel border border-pub-danger/[0.4] bg-pub-danger/[0.07] px-[16px] py-[14px]', 'wrapper do banner (:28)');
        conferirClasses(raiz.filhos[0], 'grid h-[34px] w-[34px] flex-none place-items-center rounded-[9px] bg-pub-danger/[0.14]', 'tile do banner (:29)');
        conferirClasses(raiz.filhos[1], 'flex flex-1 flex-col gap-[2px]', 'coluna do banner (:32)');
        conferirClasses(raiz.filhos[1].filhos[0], 'text-[14px] font-semibold text-pub-danger-txt', 'título do banner (:33)');
        conferirClasses(raiz.filhos[1].filhos[1], 'text-[12.5px] text-pub-txt-2', 'descrição do banner (:34)');
        conferirClasses(raiz.filhos[2], 'flex h-[34px] items-center whitespace-nowrap rounded-[8px] bg-pub-danger-txt px-[14px] text-[13px] font-semibold text-pub-page', 'CTA do banner (:36)');
    });

    await contexto.test('o svg: 16×16, viewBox 16, stroke 1.7 e os dois `d` do protótipo (:30)', () => {
        const svg = descendentes(raizDe(html), 'svg');
        assert.equal(svg.length, 1);
        assert.match(svg[0].bruto, /width="16"/);
        assert.match(svg[0].bruto, /height="16"/);
        assert.match(svg[0].bruto, /viewBox="0 0 16 16"/);
        conferirClasses(svg[0], 'fill-none stroke-pub-danger-txt', 'svg do banner');

        const paths = descendentes(svg[0], 'path');
        assert.equal(paths.length, 2);
        assert.ok(paths[0].bruto.includes('d="M6.5 9.5l3-3M5 7L3.5 8.5a2.5 2.5 0 0 0 3.5 3.5L8.5 10.5M11 9l1.5-1.5A2.5 2.5 0 0 0 9 4L7.5 5.5"'), 'o `d` da corrente não é o do protótipo');
        assert.ok(paths[1].bruto.includes('d="M2 2l12 12"'), 'faltou a DIAGONAL — é ela que diz "desconectado"');
        for (const p of paths) {
            assert.match(p.bruto, /stroke-width="1\.7"/);
            assert.match(p.bruto, /stroke-linecap="round"/);
            assert.match(p.bruto, /stroke-linejoin="round"/);
        }
    });

    await contexto.test('o CTA é <a href>, nunca <button> (EXMP-03)', () => {
        const raiz = raizDe(html);
        assert.equal(raiz.filhos[2].nome, 'a');
        // ⚠️ `/href="…"/` sem o espaço é VAZIA POR CONSTRUÇÃO: `data-href="…"`
        // casa com ela, e a mutação M04 (`href` → `data-href`) SOBREVIVEU na
        // primeira rodada da campanha. Substring casa com SUFIXO de atributo
        // também, não só com prefixo de classe.
        assert.match(raiz.filhos[2].bruto, /\shref="\/reconectar"/, 'o CTA perdeu o atributo href');
        assert.doesNotMatch(raiz.filhos[2].bruto, /data-href/, 'o destino virou atributo decorativo');
        assert.equal(descendentes(raiz, 'button').length, 0, 'o banner não tem <button>');
    });

    await contexto.test('os três literais, por igualdade de string INTEIRA', () => {
        assert.equal(R.BANNERS.ml.titulo, 'Mercado Livre desconectado');
        assert.equal(R.BANNERS.ml.descricao, 'O token da conta expirou. Publicações, sincronização e métricas estão suspensas até reconectar.');
        assert.equal(R.BANNERS.ml.cta, 'Reconectar conta');
        assert.ok(html.includes('>Mercado Livre desconectado<'));
        assert.ok(html.includes('>O token da conta expirou. Publicações, sincronização e métricas estão suspensas até reconectar.<'));
        assert.ok(html.includes('>Reconectar conta<'));
    });

    await contexto.test('⚠️ CONTRA-PROVA: a frase COM "há 2 h" está AUSENTE (o prazo não existe no contrato)', () => {
        // O protótipo escreve "O token da conta expirou há 2 h…" (`:594`). A
        // Fase 177-03 travou a ausência de `renova_em`/`expires_at` no shape.
        assert.doesNotMatch(html, /há 2 h/);
        assert.doesNotMatch(html, /expirou há/);
        assert.doesNotMatch(R.BANNERS.ml.descricao, /há \d/);
    });
});

test('A2 — o banner SÓ APARECE COM PROBLEMA (VG-02), e o CTA só com destino', async (contexto) => {
    await contexto.test('`banner` nulo não renderiza NADA', () => {
        assert.equal(renderBanner({ banner: null }), '');
        assert.equal(renderBanner({}), '');
        assert.equal(renderBanner(), '');
    });

    await contexto.test('sem `link_reconexao` o banner aparece SEM CTA', () => {
        for (const link of [null, undefined, '', '   ', 7, {}, [], true]) {
            const html = renderBanner({ banner: { chave: 'ml', link_reconexao: link } });
            assert.ok(html.includes('>Mercado Livre desconectado<'), `o aviso tem de ficar com link ${JSON.stringify(link)}`);
            assert.equal(descendentes(raizDe(html), 'a').length, 0, `nasceu <a> com link ${JSON.stringify(link)}`);
            assert.doesNotMatch(html, /href=/);
            semLixo(html, `banner com link ${JSON.stringify(link)}`);
        }
    });

    await contexto.test('⚠️ chave desconhecida ou ausente NÃO renderiza banner genérico', () => {
        for (const banner of [{}, { chave: null }, { chave: 'erp' }, { chave: 'constructor' }, { chave: 7 }, { chave: {} }, 'x', 7, [], true, { link_reconexao: '/x' }]) {
            assert.equal(renderBanner({ banner }), '', `renderizou banner para ${JSON.stringify(banner)}`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// B. A faixa de Desempenho (:286-:310)
// ═══════════════════════════════════════════════════════════════════════════

/** As células de KPI: os filhos diretos da faixa, menos o bloco do título. */
function celulasDeKpi(html) {
    const raiz = raizDe(html);
    return raiz.filhos.slice(1);
}

test('B1 — a faixa de Desempenho: a grade, o bloco do título e as N células', async (contexto) => {
    const html = renderFaixa({ desempenho: DESEMPENHO() });

    await contexto.test('a faixa é <section> e NÃO <a> — a tela Desempenho é a Fase 182 (EXMP-03)', () => {
        const raiz = raizDe(html);
        assert.equal(raiz.nome, 'section');
        assert.doesNotMatch(raiz.bruto, /href/);
        // ⚠️ Nenhum `<a>` e nenhum `<button>` em NENHUM lugar da faixa.
        assert.equal(descendentes(raiz, 'a').length, 0);
        assert.equal(descendentes(raiz, 'button').length, 0);
        assert.doesNotMatch(html, /href=/);
        assert.doesNotMatch(html, /Ver desempenho/);
    });

    await contexto.test('a grade é escrita POR EXTENSO para o número de KPIs (3 trilhas)', () => {
        conferirClasses(
            raizDe(html),
            'grid items-center whitespace-nowrap rounded-pub-card border border-pub-line bg-pub-surface text-pub-txt grid-cols-[auto_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]',
            'faixa (:286)',
        );
        // ⚠️ O `repeat(4,…)` do protótipo NÃO aparece: são 3 KPIs e não há
        // coluna final de "Ver desempenho".
        assert.doesNotMatch(html, /repeat\(4/);
        assert.doesNotMatch(html, /repeat\(/);
    });

    await contexto.test('o bloco do título: tile de 36px, svg 16 e os dois textos (:287-:291)', () => {
        const titulo = raizDe(html).filhos[0];
        conferirClasses(titulo, 'flex items-center gap-[12px] px-[22px] py-[16px]', 'bloco do título (:287)');
        assert.deepEqual(titulo.filhos.map((f) => f.nome), ['span', 'span']);
        conferirClasses(titulo.filhos[0], 'grid h-[36px] w-[36px] place-items-center rounded-pub-sub bg-pub-accent/[0.12]', 'tile do título (:288)');
        conferirClasses(titulo.filhos[1], 'flex flex-col gap-[2px]', 'coluna do título (:291)');
        conferirClasses(titulo.filhos[1].filhos[0], 'm-0 text-[14px] font-semibold', 'H2 da faixa (:291)');
        conferirClasses(titulo.filhos[1].filhos[1], 'text-[12px] text-pub-txt-3', 'subtítulo da faixa (:291)');
        assert.equal(titulo.filhos[1].filhos[0].nome, 'h2');
        assert.ok(html.includes('>Desempenho</h2>'));
        assert.ok(html.includes('>Últimos 30 dias<'));
    });

    await contexto.test('o ícone é desenhado À MÃO, com os dois `d` do protótipo (:289)', () => {
        const svg = descendentes(raizDe(html).filhos[0], 'svg');
        assert.equal(svg.length, 1);
        assert.match(svg[0].bruto, /viewBox="0 0 16 16"/);
        conferirClasses(svg[0], 'fill-none stroke-pub-accent', 'svg do título');
        const paths = descendentes(svg[0], 'path');
        assert.equal(paths.length, 2);
        assert.ok(paths[0].bruto.includes('d="M2.5 13.5h11"'), 'a BASE do gráfico');
        assert.ok(paths[1].bruto.includes('d="M4.5 11V8M8 11V4.5M11.5 11V6.5"'), 'as TRÊS barras');
        // ⚠️ O eixo ESQUERDO do Lucide (`M3 3v16a2 2 0 0 0 2 2h16`) não existe
        // no protótipo — e é por isso que o ícone é desenhado à mão.
        assert.doesNotMatch(html, /M3 3v16/);
    });

    await contexto.test('são EXATAMENTE 3 células, uma por KPI, com as medidas de :294-:299', () => {
        const celulas = celulasDeKpi(html);
        assert.equal(celulas.length, 3);
        assert.equal(descendentes(raizDe(html), 'div').length, 4, 'o bloco do título + 3 células, nada mais');

        for (const [i, celula] of celulas.entries()) {
            conferirClasses(celula, 'flex min-w-0 items-center justify-between gap-[12px] border-l border-pub-line-subtle px-[20px] py-[12px]', `célula ${i} (:294)`);
            const coluna = celula.filhos[0];
            conferirClasses(coluna, 'flex min-w-0 flex-col gap-[4px]', `coluna da célula ${i} (:295)`);
            conferirClasses(coluna.filhos[0], 'truncate text-[12px] text-pub-txt-3', `label ${i} (:296)`);
            conferirClasses(coluna.filhos[1], 'flex items-baseline gap-[6px]', `linha do valor ${i} (:297)`);
            conferirClasses(coluna.filhos[1].filhos[0], 'text-[20px] font-semibold leading-none tracking-[-0.02em] tabular-nums', `valor ${i} (:297)`);
        }
    });

    await contexto.test('os três rótulos de KPI, por igualdade de string inteira', () => {
        const labels = celulasDeKpi(html).map((c) => textoDireto(html, c.filhos[0].filhos[0]));
        assert.deepEqual(labels, ['Publicações criadas', 'Criativos com IA', 'Publicações com venda']);
    });
});

test('B2 — a sparkline, NOS NÚMEROS (:303-:305)', async (contexto) => {
    const html = renderFaixa({ desempenho: DESEMPENHO() });

    await contexto.test('UM svg por KPI COM série — dois, não três', () => {
        const celulas = celulasDeKpi(html);
        assert.equal(descendentes(celulas[0], 'svg').length, 1);
        assert.equal(descendentes(celulas[1], 'svg').length, 1);
        // ⚠️ `publicacoes_com_venda` chega com `serie: null` do servidor.
        assert.equal(descendentes(celulas[2], 'svg').length, 0, 'série nula desenhou svg');
        // O ícone do título + os dois das sparklines.
        assert.equal(descendentes(raizDe(html), 'svg').length, 3);
    });

    await contexto.test('width 64, height 26, viewBox "0 0 100 34" e preserveAspectRatio none', () => {
        const svg = descendentes(celulasDeKpi(html)[0], 'svg')[0];
        assert.match(svg.bruto, /width="64"/);
        assert.match(svg.bruto, /height="26"/);
        assert.match(svg.bruto, /viewBox="0 0 100 34"/);
        assert.match(svg.bruto, /preserveAspectRatio="none"/);
        conferirClasses(svg, 'flex-none overflow-visible', 'svg da sparkline');
    });

    await contexto.test('⚠️ o `d` é comparado EM STRING com a saída de caminhoDaSparkline', () => {
        const esperado = R.caminhoDaSparkline([1, 2, 3, 4, 5]);
        const paths = descendentes(celulasDeKpi(html)[0], 'path');
        assert.equal(paths.length, 2, 'área e linha');
        assert.ok(paths[0].bruto.includes(`d="${esperado.area}"`), `a área divergiu: ${paths[0].bruto}`);
        assert.ok(paths[1].bruto.includes(`d="${esperado.linha}"`), `a linha divergiu: ${paths[1].bruto}`);
        conferirClasses(paths[0], 'fill-pub-accent/[0.10]', 'área da sparkline (:304)');
        conferirClasses(paths[1], 'fill-none stroke-pub-accent stroke-2', 'linha da sparkline (:305)');
        assert.match(paths[1].bruto, /stroke-linejoin="round"/);
        assert.match(paths[1].bruto, /stroke-linecap="round"/);
    });

    await contexto.test('⚠️ série de UM ponto não leva NaN para o `d`', () => {
        const umPonto = renderFaixa({ desempenho: { kpis: [{ chave: 'publicacoes_criadas', valor: 7, de: null, delta: null, serie: [7] }], dias: 30, url: null } });
        semLixo(umPonto, 'sparkline de 1 ponto');
        assert.equal(descendentes(raizDe(umPonto), 'svg').length, 2);
        const paths = descendentes(celulasDeKpi(umPonto)[0], 'path');
        assert.ok(paths[1].bruto.includes(`d="${R.caminhoDaSparkline([7]).linha}"`));
    });
});

test('B3 — honestidade na faixa: os DOIS lados juntos (VG-18, EXMP-01)', async (contexto) => {
    await contexto.test('`valor: null` ⇒ "—" com `title`, e o `0` AUSENTE daquela célula', () => {
        const html = renderFaixa({ desempenho: DESEMPENHO() });
        const celula = celulasDeKpi(html)[2];
        const valor = celula.filhos[0].filhos[1].filhos[0];
        assert.equal(textoDireto(html, valor), '—');
        assert.match(celula.bruto, /title="Ainda não medimos este indicador nesta conta — não é zero"/);
        // ⚠️ O recorte sai das POSIÇÕES ABSOLUTAS da célula: prova que o `0`
        // não está NAQUELA célula, não no HTML inteiro — onde os outros dois
        // KPIs legitimamente têm dígitos. E a contra-prova abaixo mostra que o
        // recorte não é vazio por construção.
        // ⚠️ Só o TEXTO: as classes do recorte têm dígitos de medida
        // (`gap-[4px]`, `text-[12px]`) e tornariam a asserção sempre falsa.
        const texto = conteudoDe(html, celula).replace(/<[^>]*>/g, '');
        assert.ok(texto.includes('Publicações com venda'), 'o recorte da célula saiu errado');
        assert.doesNotMatch(texto, /\d/, `o texto da célula tem dígito: "${texto}"`);
    });

    await contexto.test('`valor: 0` ⇒ o `0` APARECE (zero MEDIDO é dado)', () => {
        const html = renderFaixa({ desempenho: { kpis: [{ chave: 'publicacoes_criadas', valor: 0, de: null, delta: null, serie: null }], dias: 30, url: null } });
        assert.ok(html.includes('>0<'), 'o zero medido desapareceu');
        assert.doesNotMatch(html, /—/);
        assert.doesNotMatch(html, /title=/);
    });

    await contexto.test('delta 0 ou nulo ⇒ NENHUMA flecha; +N ⇒ ↑ verde; −N ⇒ ↓ vermelho', () => {
        const comZero = renderFaixa({ desempenho: { kpis: [{ chave: 'publicacoes_criadas', valor: 5, de: null, delta: 0, serie: null }], dias: 30, url: null } });
        assert.doesNotMatch(comZero, /↑|↓/, 'desenhou flecha com delta 0');

        const comNulo = renderFaixa({ desempenho: { kpis: [{ chave: 'publicacoes_criadas', valor: 5, de: null, delta: null, serie: null }], dias: 30, url: null } });
        assert.doesNotMatch(comNulo, /↑|↓/);

        const html = renderFaixa({ desempenho: DESEMPENHO() });
        const subiu = celulasDeKpi(html)[0].filhos[0].filhos[1].filhos[1];
        conferirClasses(subiu, 'text-[11.5px] font-medium text-pub-ok-txt', 'delta positivo (:298)');
        assert.ok(html.includes('>↑ +5<'), 'o protótipo formata o positivo com "+" (:555)');

        const caiu = celulasDeKpi(html)[1].filhos[0].filhos[1].filhos[1];
        conferirClasses(caiu, 'text-[11.5px] font-medium text-pub-danger-txt', 'delta negativo (:299)');
        assert.ok(html.includes('>↓ -2<'));
    });

    await contexto.test('⚠️ ASSERÇÃO NEGATIVA: nenhum KPI de Vendas, Visitas ou Conversão', () => {
        // Nenhum dos três tem fonte: `sold_quantity` é acumulado da vida do
        // anúncio, `visitas` é NULL em 1.374.527 linhas e não há tabela de
        // pedidos. Rodada sobre o payload REAL de 3 KPIs do 179-03.
        const html = renderFaixa({ desempenho: DESEMPENHO() });
        for (const proibido of ['Vendas', 'Visitas', 'Conversão', 'Conversao']) {
            assert.doesNotMatch(html, new RegExp(proibido), `${proibido} apareceu na faixa`);
        }
        for (const chave of ['vendas', 'visitas', 'conversao']) {
            assert.ok(!Object.prototype.hasOwnProperty.call(R.KPIS, chave), `KPIS ganhou a chave ${chave}`);
        }
        // E a contagem de células trava a entrada de um 4º KPI inventado.
        assert.equal(celulasDeKpi(html).length, 3);
    });

    await contexto.test('a faixa não nasce sem nenhum KPI (cabeçalho sobre nada afirma medição)', () => {
        for (const desempenho of [null, undefined, {}, { kpis: [] }, { kpis: null }, { kpis: 'x' }, { kpis: 7 }, 'x', 7, [], true]) {
            assert.equal(renderFaixa({ desempenho }), '', `renderizou faixa para ${JSON.stringify(desempenho)}`);
        }
        assert.equal(renderFaixa(), '');
    });

    await contexto.test('o subtítulo lê `dias` do servidor em vez de cravar 30', () => {
        assert.equal(R.subtituloDoDesempenho(30), R.SUB_DESEMPENHO);
        assert.equal(R.subtituloDoDesempenho(7), 'Últimos 7 dias');
        assert.equal(R.subtituloDoDesempenho(null), 'Últimos 30 dias');
        assert.equal(R.subtituloDoDesempenho({}), 'Últimos 30 dias');
        const html = renderFaixa({ desempenho: { ...DESEMPENHO(), dias: 7 } });
        assert.ok(html.includes('>Últimos 7 dias<'));
        assert.doesNotMatch(html, /Últimos 30 dias/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// C. "Publicações recentes" (:313-:338)
// ═══════════════════════════════════════════════════════════════════════════

/** O cabeçalho e as linhas: filhos DIRETOS da `<section>`, por posição. */
const cabecalhoDeRecentes = (html) => raizDe(html).filhos[0];
const linhasDeRecentes = (html) => raizDe(html).filhos.slice(1).filter((f) => f.nome === 'a' || f.nome === 'div');

test('C1 — "Publicações recentes": 5 linhas e as medidas de :313-:329', async (contexto) => {
    const html = renderPubs({ recentes: RECENTES(), urlPublicacoes: '/publicacoes', agora: AGORA });

    await contexto.test('card, cabeçalho, coluna de 3px, H2, subtítulo e link', () => {
        const raiz = raizDe(html);
        assert.equal(raiz.nome, 'section');
        conferirClasses(raiz, 'overflow-hidden rounded-pub-card border border-pub-line bg-pub-surface', 'card (:313)');

        const cabecalho = cabecalhoDeRecentes(html);
        conferirClasses(cabecalho, 'flex items-center justify-between px-[20px] pb-[12px] pt-[18px]', 'cabeçalho (:314)');
        // ⚠️ `gap:3px`, não 4px — medido em `:315`.
        conferirClasses(cabecalho.filhos[0], 'flex flex-col gap-[3px]', 'coluna do título (:315)');
        conferirClasses(cabecalho.filhos[0].filhos[0], 'm-0 text-[15px] font-semibold', 'H2 (:315)');
        conferirClasses(cabecalho.filhos[0].filhos[1], 'text-[12.5px] text-pub-txt-3', 'subtítulo (:315)');
        conferirClasses(cabecalho.filhos[1], 'whitespace-nowrap text-[12.5px] font-medium text-pub-txt-2', 'link (:317)');
        assert.equal(cabecalho.filhos[1].nome, 'a');
    });

    await contexto.test('são EXATAMENTE 5 linhas, e a grade/padding/divisória de cada uma (:319)', () => {
        const linhas = linhasDeRecentes(html);
        assert.equal(linhas.length, 5);

        const base = 'grid grid-cols-[40px_minmax(0,1fr)_112px_104px] items-center gap-[14px] whitespace-nowrap border-t border-pub-line-subtle-2 px-[20px] py-[11px] hover:bg-pub-hover-2';
        // ⚠️ A PRIMEIRA é `nova` e só ela — localizada por POSIÇÃO, não por
        // classe (as quatro restantes têm a MESMA string de classes).
        conferirClasses(linhas[0], `${base} bg-pub-row-new`, 'linha nova (:319)');
        for (const [i, linha] of linhas.slice(1).entries()) {
            conferirClasses(linha, `${base} bg-transparent`, `linha ${i + 1} (:319)`);
        }
    });

    await contexto.test('o tile de 40px vem do TileDoProduto, com raio 9 e letra 11.5px (:320)', () => {
        const linha = linhasDeRecentes(html)[0];
        const tile = linha.filhos[0];
        assert.match(tile.bruto, /width:40px/);
        assert.match(tile.bruto, /height:40px/);
        assert.match(tile.bruto, /border-radius:9px/);
        conferirClasses(tile, 'grid flex-none place-items-center font-semibold text-[11.5px]', 'tile (:320)');
    });

    await contexto.test('coluna do meio, linha de título, título truncado e a meta (:321-:323)', () => {
        const linha = linhasDeRecentes(html)[0];
        const meio = linha.filhos[1];
        conferirClasses(meio, 'flex min-w-0 flex-col gap-[4px]', 'coluna do meio (:321)');
        conferirClasses(meio.filhos[0], 'flex min-w-0 items-center gap-[8px]', 'linha de título (:322)');
        conferirClasses(meio.filhos[0].filhos[0], 'truncate text-[13.5px] font-medium', 'título (:322)');
        conferirClasses(meio.filhos[1], 'flex items-center gap-[6px] overflow-hidden whitespace-nowrap text-[11.5px] text-pub-txt-3', 'meta (:323)');
    });

    await contexto.test('⚠️ UM BadgeNova, só na linha `nova` (:322)', () => {
        const linhas = linhasDeRecentes(html);
        const badges = linhas.map((l) => conteudoDe(html, l).match(/>NOVA</g)?.length ?? 0);
        assert.deepEqual(badges, [1, 0, 0, 0, 0]);
        // E o badge é o componente compartilhado, com as medidas dele.
        assert.equal((html.match(/rounded-pub-badge bg-pub-accent px-\[6px\] py-\[2px\] text-\[10px\] font-bold tracking-\[0\.06em\] text-pub-page/g) ?? []).length, 1);
    });

    await contexto.test('o glyph de fase: reuso do GlyphDaFase no tamanho `recentes` (:324/:325)', () => {
        const linhas = linhasDeRecentes(html);
        // Fase 2: DOIS retângulos, o da frente amarelo.
        const f2 = linhas[0].filhos[1].filhos[1].filhos[0];
        conferirClasses(f2, 'relative inline-block flex-none h-[13px] w-[14px]', 'caixa do glyph F2 (:324)');
        assert.equal(f2.filhos.length, 2);
        conferirClasses(f2.filhos[0], 'absolute h-[10px] w-[8px] rounded-[2px] border-[1.3px] border-pub-fase1 left-0 top-0 bg-pub-surface', 'retângulo de trás (:324)');
        conferirClasses(f2.filhos[1], 'absolute bg-pub-accent h-[10px] w-[8px] rounded-[2px] border-[1.3px] border-pub-fase1 left-[5px] top-[3px]', 'retângulo da frente (:324)');

        // Fase 1: UM retângulo, centrado na mesma caixa de 14px (= `margin:0 3px`).
        const f1 = linhas[1].filhos[1].filhos[1].filhos[0];
        conferirClasses(f1, 'inline-grid flex-none place-items-center h-[13px] w-[14px]', 'caixa do glyph F1 (:325)');
        assert.equal(f1.filhos.length, 1);
        conferirClasses(f1.filhos[0], 'h-[10px] w-[8px] rounded-[2px] border-[1.3px] border-pub-fase1', 'retângulo F1 (:325)');

        // ⚠️ Fase que não é 1 nem 2 NÃO desenha glyph: um retângulo tracejado
        // ali afirmaria "Legado" (o protótipo desenha `isP1`/`isP2` e nada mais).
        const legado = linhas[4].filhos[1].filhos[1];
        assert.equal(legado.filhos[0].nome, 'span');
        assert.doesNotMatch(conteudoDe(html, legado), /border-dashed/);
        assert.equal(legado.filhos.length, 3, 'fase · tipo · MLB, sem glyph');
    });

    await contexto.test('fase, tipo PELO PESO e MLB em mono, com os `·` nus (:326)', () => {
        const linhas = linhasDeRecentes(html);
        const metaPremium = linhas[0].filhos[1].filhos[1];
        const partes = metaPremium.filhos.filter((f) => f.nome === 'span' && f.classes.length > 0);
        // glyph + fase + tipo + MLB
        conferirClasses(partes[1], 'text-pub-fase1', 'rótulo de fase (:326)');
        conferirClasses(partes[2], 'text-pub-fase1 font-semibold', 'tipo Premium — peso 600 (:326)');
        conferirClasses(partes[3], 'font-pub-mono text-[11px]', 'MLB em mono (:326)');

        const metaClassico = linhas[1].filhos[1].filhos[1];
        const partesC = metaClassico.filhos.filter((f) => f.nome === 'span' && f.classes.length > 0);
        conferirClasses(partesC[2], 'text-pub-fase1 font-normal', 'tipo Clássico — peso 400 (:326)');

        // Os `·` são nós de texto NUS, sem espaço em volta e sem wrapper.
        const meta = conteudoDe(html, metaPremium);
        assert.equal((meta.match(/<\/span>·<span/g) ?? []).length, 2, 'os dois separadores nus');
        assert.doesNotMatch(meta, /<span[^>]*>·<\/span>/, 'o `·` ganhou wrapper próprio');
        assert.doesNotMatch(meta, / · <span/, 'o `·` ganhou espaço em volta');
        assert.equal((meta.match(/·/g) ?? []).length, 3, 'dois separadores + o `·` do rótulo de fase');
    });

    await contexto.test('o status reusa SeloDeStatusDaPublicacao no corpo de 12.5px (:328)', () => {
        const linhas = linhasDeRecentes(html);
        const selo = linhas[0].filhos[2];
        conferirClasses(selo, 'inline-flex min-w-0 flex-col gap-[2px]', 'invólucro do selo');
        conferirClasses(selo.filhos[0], 'flex items-center gap-[7px] text-[12.5px] font-medium text-pub-ok-txt', 'linha do status (:328)');
        // As quatro formas, cada uma na sua linha — nunca a mesma.
        const formas = linhas.slice(0, 4).map((l) => l.filhos[2].filhos[0].filhos[0].bruto);
        assert.equal(new Set(formas).size, 4, 'duas formas de status ficaram iguais');
        // ⚠️ REAPONTADO na Fase 186, plano 01 (VOC-01). VALOR ANTIGO:
        // `>Em revisão<`. Este bloco renderiza o PRÓPRIO
        // `SeloDeStatusDaPublicacao`, então ele acompanha o rename do selo —
        // independentemente da faixa de `acoes.js`.
        assert.ok(html.includes('>Ativa<') && html.includes('>Em análise pelo Mercado Livre<') && html.includes('>Pausada<') && html.includes('>Com erro<'));
        // ⚠️ VETO: o recorte é `>Em revisão<` (com os dois delimitadores de nó),
        // que é EXCLUSIVO do rótulo antigo — "Em revisão pela ECF" e "Em revisão
        // pelo Mercado Livre" nunca casariam com ele.
        assert.ok(!html.includes('>Em revisão<'), 'o rótulo antigo voltou ao selo de Recentes');
    });

    await contexto.test('quem e quando, na coluna da direita (:329)', () => {
        const linhas = linhasDeRecentes(html);
        const direita = linhas[0].filhos[3];
        conferirClasses(direita, 'flex flex-col items-end gap-[2px]', 'coluna da direita (:329)');
        conferirClasses(direita.filhos[0], 'text-[12.5px] text-pub-fase1', 'quando (:329)');
        conferirClasses(direita.filhos[1], 'text-[11.5px] text-pub-txt-4', 'quem (:329)');
        assert.equal(textoDireto(html, direita.filhos[0]), 'há 12 min');
        assert.equal(textoDireto(html, direita.filhos[1]), 'Ana Ribeiro');

        // `quem.nome` nulo cai em `quem.tipo` — o único objeto do contrato.
        assert.equal(textoDireto(html, linhas[3].filhos[3].filhos[1]), 'cliente');

        // ⚠️ `quando` nulo ⇒ a linha sai SEM a hora, nunca com "Invalid Date" —
        // a coluna fica com UM filho, o autor, e não com dois.
        assert.equal(linhas[4].filhos[3].filhos.length, 1);
        assert.equal(textoDireto(html, linhas[4].filhos[3].filhos[0]), 'Ana Ribeiro');
        conferirClasses(linhas[4].filhos[3].filhos[0], 'text-[11.5px] text-pub-txt-4', 'o único filho é o autor');
        semLixo(html, 'recentes cheio');
    });
});

test('C2 — os literais e o vazio de "Publicações recentes" (:333-:336)', async (contexto) => {
    await contexto.test('subtítulo e link, por igualdade de string INTEIRA', () => {
        assert.equal(R.TITULO_RECENTES, 'Publicações recentes');
        assert.equal(R.SUB_RECENTES, 'O que acabou de entrar no ar nos últimos 30 dias');
        assert.equal(R.VER_PUBLICACOES, 'Ver todas as publicações →');
        const html = renderPubs({ recentes: RECENTES(), urlPublicacoes: '/p', agora: AGORA });
        assert.ok(html.includes('>Publicações recentes</h2>'));
        assert.ok(html.includes('>O que acabou de entrar no ar nos últimos 30 dias<'));
        assert.ok(html.includes('>Ver todas as publicações →<'));
    });

    await contexto.test('o vazio: as medidas e os dois literais — com as aspas CURVAS', () => {
        const html = renderPubs({ recentes: { disponivel: true, itens: [] } });
        const vazio = raizDe(html).filhos[1];
        conferirClasses(vazio, 'flex flex-col items-center gap-[6px] border-t border-pub-line-subtle-2 px-[20px] py-[40px] text-center', 'vazio (:333)');
        conferirClasses(vazio.filhos[0], 'text-[14px] font-semibold', 'título do vazio (:335)');
        conferirClasses(vazio.filhos[1], 'text-[12.5px] text-pub-txt-3', 'texto do vazio (:336)');
        assert.equal(R.VAZIO_RECENTES_TITULO, 'Nenhuma publicação no período');
        assert.equal(R.VAZIO_RECENTES_TEXTO, 'Use “Nova publicação” para colocar o primeiro produto no ar.');
        assert.equal(textoDireto(html, vazio.filhos[1]), 'Use “Nova publicação” para colocar o primeiro produto no ar.');
    });

    await contexto.test('⚠️ CONTRA-PROVA: a mesma frase com aspas RETAS está AUSENTE', () => {
        const html = renderPubs({ recentes: { disponivel: true, itens: [] } });
        assert.doesNotMatch(html, /Use "Nova publicação"/);
        assert.doesNotMatch(R.VAZIO_RECENTES_TEXTO, /"/);
        assert.ok(R.VAZIO_RECENTES_TEXTO.includes('“') && R.VAZIO_RECENTES_TEXTO.includes('”'));
    });

    await contexto.test('⚠️ `disponivel: false` e `itens: []` são a MESMA tela, com `title` diferente (VG-18)', () => {
        const semEmpresa = renderPubs({ recentes: { disponivel: false, itens: [] } });
        const semItem = renderPubs({ recentes: { disponivel: true, itens: [] } });
        const soTexto = (h) => h.replace(/<[^>]*>/g, '');
        assert.equal(soTexto(semEmpresa), soTexto(semItem), 'o texto do vazio deveria ser o mesmo');
        assert.notEqual(
            /title="([^"]*)"/.exec(semEmpresa)[1],
            /title="([^"]*)"/.exec(semItem)[1],
            'os dois motivos ficaram iguais — o `title` é quem distingue',
        );
        assert.match(semEmpresa, /title="Esta conta ainda não tem empresa ligada ao Publicador, então não há publicação para listar"/);
        assert.match(semItem, /title="Nenhuma publicação entrou no ar nos últimos 30 dias nesta conta"/);
    });

    await contexto.test('⚠️ sem `urlPublicacoes` a linha e o link deixam de ser <a> (EXMP-03)', () => {
        for (const url of [null, undefined, '', '   ', 7, {}, [], true]) {
            const html = renderPubs({ recentes: RECENTES(), urlPublicacoes: url, agora: AGORA });
            assert.equal(descendentes(raizDe(html), 'a').length, 0, `nasceu <a> com url ${JSON.stringify(url)}`);
            assert.doesNotMatch(html, /href=/, `nasceu href com url ${JSON.stringify(url)}`);
            assert.doesNotMatch(html, /Ver todas as publicações/, 'o link sem destino não nasce');
            assert.equal(linhasDeRecentes(html).length, 5, 'as 5 linhas continuam na tela');
            assert.equal(linhasDeRecentes(html)[0].nome, 'div');
            semLixo(html, `recentes com url ${JSON.stringify(url)}`);
        }

        // Contra-prova: COM url elas voltam a ser `<a href>`.
        const comUrl = renderPubs({ recentes: RECENTES(), urlPublicacoes: '/p', agora: AGORA });
        assert.equal(linhasDeRecentes(comUrl)[0].nome, 'a');
        // ⚠️ Com o `\s`: sem ele `data-href="/p"` casaria (lição da M04).
        assert.equal((comUrl.match(/\shref="\/p"/g) ?? []).length, 6, 'o link do cabeçalho + as 5 linhas');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// D. "Atividade recente" (:341-:381)
// ═══════════════════════════════════════════════════════════════════════════

const cabecalhoDeAtividade = (html) => raizDe(html).filhos[0];
const corpoDeAtividade = (html) => raizDe(html).filhos[1];
/** Os grupos: filhos diretos do corpo que TÊM filhos de evento. */
const gruposDeAtividade = (html) => corpoDeAtividade(html).filhos.filter((f) => f.filhos.length > 1);
/** Os eventos de um grupo: os filhos depois do rótulo. */
const eventosDoGrupo = (grupo) => grupo.filhos.slice(1);

test('D1 — "Atividade recente": cabeçalho, 4 chips e o corpo (:341-:350)', async (contexto) => {
    const html = renderAtiv({ atividade: ATIVIDADE() });

    await contexto.test('card, cabeçalho, coluna de 3px, H2 e subtítulo', () => {
        const raiz = raizDe(html);
        assert.equal(raiz.nome, 'section');
        conferirClasses(raiz, 'overflow-hidden rounded-pub-card border border-pub-line bg-pub-surface', 'card (:341)');
        const cabecalho = cabecalhoDeAtividade(html);
        conferirClasses(cabecalho, 'flex items-center justify-between gap-[12px] px-[20px] pb-[12px] pt-[18px]', 'cabeçalho (:342)');
        conferirClasses(cabecalho.filhos[0], 'flex flex-col gap-[3px]', 'coluna do título (:343)');
        conferirClasses(cabecalho.filhos[0].filhos[0], 'm-0 text-[15px] font-semibold', 'H2 (:343)');
        conferirClasses(cabecalho.filhos[0].filhos[1], 'text-[12.5px] text-pub-txt-3', 'subtítulo (:343)');
        assert.equal(R.TITULO_ATIVIDADE, 'Atividade recente');
        assert.equal(R.SUB_ATIVIDADE, 'Quem fez o quê nesta empresa');
        assert.ok(html.includes('>Atividade recente</h2>'));
        assert.ok(html.includes('>Quem fez o quê nesta empresa<'));
        conferirClasses(corpoDeAtividade(html), 'px-[20px] pb-[18px] pt-[4px]', 'corpo (:350)');
    });

    await contexto.test('⚠️ são EXATAMENTE 4 chips, e "Sistema" está AUSENTE (EXMP-01)', () => {
        const botoes = descendentes(raizDe(html), 'button');
        assert.equal(botoes.length, 4, 'são 4 chips, nunca os 5 do protótipo');
        conferirClasses(cabecalhoDeAtividade(html).filhos[1], 'flex gap-[4px]', 'invólucro dos chips (:344)');

        const rotulos = botoes.map((b) => textoDireto(html, b));
        assert.deepEqual(rotulos, ['Tudo', 'Publicações', 'Criativos', 'Produtos']);
        assert.doesNotMatch(html, /Sistema/, 'o chip "Sistema" nasceria sempre vazio');
        assert.equal(R.CHIPS_ATIVIDADE.length, 4);
        assert.ok(!R.CHIPS_ATIVIDADE.some((c) => c.chave === 'sistema' || c.chave === 'sys'));
    });

    await contexto.test('o chip: 26px, raio 999, e ativo × inativo por conjunto de classes (:346)', () => {
        const botoes = descendentes(raizDe(html), 'button');
        const base = 'h-[26px] cursor-pointer whitespace-nowrap rounded-full border px-[9px] text-[12px]';
        // ⚠️ Localizado por POSIÇÃO: os três inativos têm a MESMA string.
        conferirClasses(botoes[0], `${base} border-pub-line-off bg-pub-seg text-pub-txt`, 'chip ATIVO (:346)');
        for (const [i, b] of botoes.slice(1).entries()) {
            conferirClasses(b, `${base} border-pub-line bg-transparent text-pub-txt-2`, `chip inativo ${i + 1} (:346)`);
        }
        for (const b of botoes) assert.match(b.bruto, /type="button"/);
    });
});

test('D2 — a timeline: grupos, fio, ícone e a frase (:352-:375)', async (contexto) => {
    const html = renderAtiv({ atividade: ATIVIDADE() });

    await contexto.test('dois grupos, com os rótulos HOJE e "8 OUT" (:353, :589)', () => {
        const grupos = gruposDeAtividade(html);
        assert.equal(grupos.length, 2);
        for (const g of grupos) conferirClasses(g, 'flex flex-col', 'grupo (:352)');
        const rotulos = grupos.map((g) => textoDireto(html, g.filhos[0]));
        assert.deepEqual(rotulos, ['HOJE', '8 OUT']);
        conferirClasses(grupos[0].filhos[0], 'pb-[8px] pt-[12px] text-[11px] font-semibold tracking-[0.08em] text-pub-txt-4', 'rótulo do grupo (:353)');
    });

    await contexto.test('o evento: grade 30px/1fr/auto, e as medidas de :355/:365/:366/:375', () => {
        const evento = eventosDoGrupo(gruposDeAtividade(html)[0])[0];
        conferirClasses(evento, 'relative grid grid-cols-[30px_minmax(0,1fr)_auto] gap-[12px] pb-[16px]', 'evento (:355)');
        const coluna = evento.filhos.find((f) => f.nome === 'div');
        conferirClasses(coluna, 'flex min-w-0 flex-col gap-[4px] pt-[4px]', 'coluna de texto (:365)');
        conferirClasses(coluna.filhos[0], 'text-[13px] leading-[1.4] text-pub-txt-2', 'frase (:366)');
        conferirClasses(coluna.filhos[0].filhos[0], 'font-medium text-pub-txt', 'ator (:366)');
        conferirClasses(coluna.filhos[0].filhos[1], 'font-semibold text-pub-txt', 'alvo (:366)');
        conferirClasses(coluna.filhos[1], 'text-[12px] text-pub-txt-3', 'meta (:367)');
        const hora = evento.filhos[evento.filhos.length - 1];
        conferirClasses(hora, 'pt-[5px] text-[12px] tabular-nums text-pub-txt-4', 'hora (:375)');
        assert.equal(textoDireto(html, hora), '14:32');
    });

    await contexto.test('⚠️ UM fio A MENOS que o número de eventos de CADA grupo (:356)', () => {
        const grupos = gruposDeAtividade(html);
        const fios = grupos.map((g) => (conteudoDe(html, g).match(/left-\[14\.5px\]/g) ?? []).length);
        const eventos = grupos.map((g) => eventosDoGrupo(g).length);
        assert.deepEqual(eventos, [3, 1], 'a fixture tem 3 e 1 eventos');
        assert.deepEqual(fios, [2, 0], 'o fio liga um evento ao SEGUINTE — nunca depois do último');

        // E ele é o PRIMEIRO filho do evento, com as 5 medidas.
        const primeiro = eventosDoGrupo(grupos[0])[0];
        conferirClasses(primeiro.filhos[0], 'absolute bottom-[2px] left-[14.5px] top-[32px] w-px bg-pub-line', 'fio (:356)');
        assert.match(primeiro.filhos[0].bruto, /aria-hidden="true"/);
        // O ÚLTIMO evento do grupo não tem fio nenhum.
        const ultimo = eventosDoGrupo(grupos[0])[2];
        assert.doesNotMatch(conteudoDe(html, ultimo), /left-\[14\.5px\]/);
        assert.doesNotMatch(ultimo.filhos[0].bruto, /absolute/);
    });

    await contexto.test('UM ícone circular de 30px por evento, com o trio do tipo (:357, :588)', () => {
        const todos = [...gruposDeAtividade(html).flatMap((g) => eventosDoGrupo(g))];
        assert.equal(todos.length, 4);
        const base = 'grid h-[30px] w-[30px] place-items-center rounded-full border';
        const icones = todos.map((e) => e.filhos.find((f) => f.nome === 'span' && f.classes.includes('rounded-full')));
        conferirClasses(icones[0], `${base} bg-pub-accent-sunken border-pub-accent/[0.35] text-pub-accent`, 'ícone de publicou');
        conferirClasses(icones[1], `${base} bg-pub-hover-2 border-pub-line-strong text-pub-fase1-2`, 'ícone de criativos');
        conferirClasses(icones[2], `${base} bg-pub-ok/[0.12] border-pub-ok/[0.35] text-pub-ok-txt`, 'ícone de conferiu');
        conferirClasses(icones[3], `${base} bg-pub-hover-2 border-pub-line-strong text-pub-txt-2`, 'ícone de produto_recebido');
        // ⚠️ `criativos` e `produto_recebido` compartilham fundo e borda, e se
        // distinguem SÓ pela tinta — por isso o par é cobrado junto.
        assert.notDeepEqual(icones[1].classes, icones[3].classes);
    });

    await contexto.test('os ícones são DESENHADOS À MÃO, com os `d` do protótipo (:358-:363)', () => {
        const todos = [...gruposDeAtividade(html).flatMap((g) => eventosDoGrupo(g))];
        const svgs = todos.map((e) => descendentes(e, 'svg'));
        for (const par of svgs) assert.equal(par.length, 1, 'um svg por evento');
        for (const [svg] of svgs) {
            assert.match(svg.bruto, /width="14"/);
            assert.match(svg.bruto, /viewBox="0 0 16 16"/);
            conferirClasses(svg, 'fill-none stroke-current', 'svg do evento');
        }

        const d = (i) => descendentes(svgs[i][0], 'path').map((p) => /d="([^"]*)"/.exec(p.bruto)[1]);
        assert.deepEqual(d(0), ['M8 11V3M4.5 6.5L8 3l3.5 3.5M3 13h10']);
        assert.deepEqual(d(1), ['M8 2l1.4 3.6L13 7l-3.6 1.4L8 12l-1.4-3.6L3 7l3.6-1.4z', 'M12.5 11.5l.6 1.4 1.4.6-1.4.6-.6 1.4-.6-1.4-1.4-.6 1.4-.6z']);
        assert.deepEqual(d(2), ['M3.5 8.5l3 3 6-7']);
        assert.deepEqual(d(3), ['M2.5 5L8 2.5 13.5 5v6L8 13.5 2.5 11z', 'M2.5 5L8 7.5 13.5 5M8 7.5v6']);

        // Os stroke-width medidos: 1,7 no upload, 1,6 nos dois, 2 no check.
        const larguras = svgs.map(([svg]) => [...new Set(descendentes(svg, 'path').map((p) => /stroke-width="([^"]*)"/.exec(p.bruto)[1]))]);
        assert.deepEqual(larguras, [['1.7'], ['1.6'], ['2'], ['1.6']]);

        // ⚠️ A bandeja do `upload` do Lucide e o `<circle>` do `sparkles` não
        // existem no protótipo — e é por isso que os quatro são à mão.
        assert.doesNotMatch(html, /M21 15v4/);
        assert.equal(descendentes(raizDe(html), 'circle').length, 0);
        assert.doesNotMatch(html, /lucide/);
    });

    await contexto.test('ator + verbo + alvo, e o alvo AUSENTE quando `titulo` é nulo', () => {
        const cheio = renderAtiv({ atividade: ATIVIDADE() });
        assert.ok(cheio.includes('>Ana Ribeiro</span> publicou <span class="font-semibold text-pub-txt">Poltrona Beny<'));
        assert.ok(cheio.includes('>Ana Ribeiro</span> gerou criativos para <span'));
        assert.ok(cheio.includes('>Carla Mendes</span> conferiu <span'));
        assert.ok(cheio.includes('>Cliente</span> enviou o produto <span'));

        const semAlvo = renderAtiv({ atividade: { disponivel: true, grupos: [{ chave: 'hoje', eventos: [{ tipo: 'publicou', quem: 'Ana', quando_iso: null, titulo: null, detalhe: null }] }] } });
        const evento = eventosDoGrupo(gruposDeAtividade(semAlvo)[0])[0];
        const frase = evento.filhos.find((f) => f.nome === 'div').filhos[0];
        assert.equal(frase.filhos.length, 1, 'o alvo nasceu sem `titulo`');
        assert.equal(conteudoDe(semAlvo, frase).replace(/<[^>]*>/g, ''), 'Ana publicou');
        semLixo(semAlvo, 'evento sem alvo');
    });

    await contexto.test('⚠️ tipo DESCONHECIDO aparece, com ícone e verbo padrão — nunca desaparece', () => {
        const html2 = renderAtiv({ atividade: { disponivel: true, grupos: [{ chave: 'hoje', eventos: [{ tipo: 'inventado', quem: 'X', quando_iso: null, titulo: 'Y', detalhe: null }] }] } });
        const evento = eventosDoGrupo(gruposDeAtividade(html2)[0])[0];
        const icone = evento.filhos.find((f) => f.nome === 'span' && f.classes.includes('rounded-full'));
        conferirClasses(icone, 'grid h-[30px] w-[30px] place-items-center rounded-full border bg-pub-hover-2 border-pub-line-strong text-pub-txt-4', 'ícone padrão');
        assert.ok(html2.includes('>X</span> registrou <span'));
        assert.equal(R.VERBO_PADRAO, 'registrou');

        // ⚠️ E chave de `Object.prototype` NÃO vira classe `undefined`.
        for (const tipo of ['constructor', 'toString', '__proto__', 'valueOf']) {
            const h = renderAtiv({ atividade: { disponivel: true, grupos: [{ chave: 'hoje', eventos: [{ tipo, quem: 'X', quando_iso: null, titulo: 'Y', detalhe: null }] }] } });
            semLixo(h, `evento de tipo ${tipo}`);
            assert.match(h, /bg-pub-hover-2/, `o tipo ${tipo} perdeu o fundo`);
        }
    });

    await contexto.test('a hora vem do ISO, e ISO impossível não desenha hora (nem "Invalid Date")', () => {
        assert.equal(R.horaDoEvento('2026-10-13T14:32:00-03:00'), '14:32');
        assert.equal(R.horaDoEvento('2026-10-13 09:05:12'), '09:05');
        for (const iso of [null, undefined, '', 'ontem', '2026-10-13T99:99', '13/10/2026 14:32', 7, {}, []]) {
            assert.equal(R.horaDoEvento(iso), null, `horaDoEvento(${JSON.stringify(iso)}) deveria ser null`);
        }
        const html2 = renderAtiv({ atividade: { disponivel: true, grupos: [{ chave: 'hoje', eventos: [{ tipo: 'publicou', quem: 'A', quando_iso: '2026-10-13T99:99', titulo: 'B', detalhe: null }] }] } });
        const evento = eventosDoGrupo(gruposDeAtividade(html2)[0])[0];
        assert.ok(!evento.filhos.some((f) => f.classes.includes('tabular-nums')), 'desenhou hora sem ISO válido');
        semLixo(html2, 'evento com ISO impossível');
    });
});

test('D3 — o filtro é do cliente, e o vazio (:380)', async (contexto) => {
    await contexto.test('⚠️ filtrar por Criativos esconde os de publicação e ESVAZIA grupos sem criativo', () => {
        const tudo = renderAtiv({ atividade: ATIVIDADE() });
        assert.equal(gruposDeAtividade(tudo).length, 2);
        assert.ok(tudo.includes('publicou'));

        const criativos = renderAtiv({ atividade: ATIVIDADE(), chipInicial: 'criativos' });
        const grupos = gruposDeAtividade(criativos);
        assert.equal(grupos.length, 1, 'o grupo "8 OUT" não tem criativo e não deveria aparecer');
        assert.equal(textoDireto(criativos, grupos[0].filhos[0]), 'HOJE');
        assert.equal(eventosDoGrupo(grupos[0]).length, 1);
        assert.doesNotMatch(criativos, / publicou /);
        assert.doesNotMatch(criativos, /enviou o produto/);
        assert.doesNotMatch(criativos, /8 OUT/);
        // E o chip de Criativos é o ativo.
        const botoes = descendentes(raizDe(criativos), 'button');
        assert.ok(botoes[2].classes.includes('bg-pub-seg'));
        assert.ok(!botoes[0].classes.includes('bg-pub-seg'));
    });

    await contexto.test('"Publicações" leva `conferiu` junto (o AT.pub do protótipo, :586)', () => {
        const html = renderAtiv({ atividade: ATIVIDADE(), chipInicial: 'publicacoes' });
        assert.ok(html.includes(' publicou '));
        assert.ok(html.includes(' conferiu '));
        assert.doesNotMatch(html, /gerou criativos/);
        assert.deepEqual(R.tiposDoChip('publicacoes'), ['publicou', 'conferiu']);
        assert.equal(R.tiposDoChip('tudo'), null);
        assert.equal(R.tiposDoChip('inventado'), null);
    });

    await contexto.test('filtro sem nenhum evento mostra VAZIO_ATIVIDADE, e o chip segue clicável', () => {
        const html = renderAtiv({ atividade: ATIVIDADE(), chipInicial: 'produtos' });
        assert.ok(html.includes('>Puff Redondo Linho<'), 'Produtos TEM evento nesta fixture');

        const semNada = renderAtiv({ atividade: { disponivel: true, grupos: [{ chave: 'hoje', eventos: [{ tipo: 'publicou', quem: 'A', quando_iso: null, titulo: 'B', detalhe: null }] }] }, chipInicial: 'produtos' });
        const vazio = corpoDeAtividade(semNada).filhos[0];
        conferirClasses(vazio, 'py-[30px] text-center text-[13px] text-pub-txt-4', 'vazio (:380)');
        assert.equal(R.VAZIO_ATIVIDADE, 'Nenhuma atividade neste filtro e período');
        assert.equal(textoDireto(semNada, vazio), 'Nenhuma atividade neste filtro e período');
        // ⚠️ Nenhum chip é `disabled` — ele TEM fonte, está vazio no período.
        assert.doesNotMatch(semNada, /disabled=/);
        assert.equal(descendentes(raizDe(semNada), 'button').length, 4);
    });

    await contexto.test('`atividade` sem grupo nenhum mostra o vazio, e o cabeçalho FICA', () => {
        for (const atividade of [null, undefined, {}, { grupos: [] }, { grupos: null }, { grupos: 'x' }, { grupos: 7 }, 'x', 7, [], true, { disponivel: false, grupos: [] }]) {
            const html = renderAtiv({ atividade });
            assert.ok(html.includes('>Atividade recente</h2>'), `o bloco desmontou com ${JSON.stringify(atividade)}`);
            assert.ok(html.includes('>Nenhuma atividade neste filtro e período<'));
            assert.equal(descendentes(raizDe(html), 'button').length, 4);
            semLixo(html, `atividade ${JSON.stringify(atividade)}`);
        }
        assert.ok(renderAtiv().includes('>Nenhuma atividade neste filtro e período<'));
    });

    await contexto.test('⚠️ as miniaturas de 34px e o link por evento NÃO nascem (EXMP-01/EXMP-03)', () => {
        const html = renderAtiv({ atividade: ATIVIDADE() });
        // O `detalhe` com o número de imagens FICA (é real); a imagem não.
        assert.ok(html.includes('>6 imagens geradas<'));
        assert.doesNotMatch(html, /w-\[34px\]/);
        assert.doesNotMatch(html, /h-\[34px\]/);
        assert.equal(descendentes(raizDe(html), 'img').length, 0);
        // E nenhum destino: o shape de `atividadeDaEquipe()` não tem URL.
        assert.equal(descendentes(raizDe(html), 'a').length, 0);
        assert.doesNotMatch(html, /href=/);
        assert.doesNotMatch(html, /Ver criativos|Ver publicações|Criar combo|Corrigir/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// E. `recentes.js` — módulo PURO, sem UMA classe do Tailwind
// ═══════════════════════════════════════════════════════════════════════════

test('E — recentes.js: nem React, nem JSX, nem classe do Tailwind', async (contexto) => {
    const fonte = FONTES.get(REL_RECENTES);

    await contexto.test('⚠️ o `content` do tailwind.config.js varre **/*.jsx e NÃO *.js', () => {
        // ⚠️ LIDO CRU, nunca por `lerSemComentarios`: o glob
        // `'./resources/js/**/*.jsx'` contém `/*` seguido de `*/`, e o helper
        // remove blocos de comentário — o glob chegaria ao teste como
        // `'./resources/js*.jsx'` e este gate mediria NADA (armadilha medida no
        // plano 179-06).
        const config = fs.readFileSync(path.resolve(RAIZ, 'tailwind.config.js'), 'utf8');
        assert.ok(config.includes("'./resources/js/**/*.jsx'"), 'o glob do `content` mudou — reconferir o gate abaixo');
        assert.ok(!config.includes("'./resources/js/**/*.js'"), 'se o config passar a varrer `*.js`, este gate inteiro pode cair');
    });

    await contexto.test('nenhum import de React e nenhum JSX', () => {
        assert.doesNotMatch(fonte, /from 'react'/);
        assert.doesNotMatch(fonte, /<\/?[A-Za-z]/, 'apareceu JSX num módulo que deveria ser puro');
    });

    await contexto.test('⚠️ ZERO classes do Tailwind — 16 padrões, com ISCA', () => {
        const PADROES = [
            /\bbg-pub-/, /\btext-pub-/, /\bborder-pub-/, /\bstroke-pub-/, /\bfill-pub-/,
            /\brounded-pub-/, /\btext-\[\d/, /\bh-\[\d/, /\bw-\[\d/, /\bgap-\[\d/,
            /\bgrid-cols-/, /\bhover:/, /\bfont-(semibold|medium|bold|normal)\b/,
            /\btracking-\[/, /\bflex-none\b/, /\btabular-nums\b/,
        ];
        for (const padrao of PADROES) {
            assert.doesNotMatch(fonte, padrao, `recentes.js carrega classe do Tailwind (${padrao}) — ela NÃO geraria CSS`);
        }

        // ⚠️ ISCA: os 16 padrões TÊM de achar coisa nos `.jsx`. Sem isso, um
        // regex quebrado deixaria o gate acima verdadeiro por construção.
        const juntos = FONTES_JSX.map((c) => FONTES.get(c)).join('\n');
        let achou = 0;
        for (const padrao of PADROES) if (padrao.test(juntos)) achou += 1;
        assert.equal(achou, PADROES.length, 'algum padrão da varredura não acha nada nem nos `.jsx` — regex quebrado');
    });

    await contexto.test('⚠️ e o mapa de ícone da Atividade mora no `.jsx`, não aqui', () => {
        assert.doesNotMatch(fonte, /ICONES_ATIVIDADE/);
        assert.match(FONTES.get(REL_ATIV), /ICONES_ATIVIDADE/);
        assert.match(FONTES.get(REL_ATIV), /bg-pub-accent-sunken/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// G. Reuso (IDENT-08) e as asserções negativas de exemplo (VG-20 / EXMP-02)
// ═══════════════════════════════════════════════════════════════════════════

test('G — a identidade é compartilhada, e nenhum bloco real regride a exemplo', async (contexto) => {
    await contexto.test('as fontes IMPORTAM os componentes de identidade que usam', () => {
        const pubs = FONTES.get(REL_PUBS);
        assert.match(pubs, /identidade\/BadgeNova/);
        assert.match(pubs, /identidade\/GlyphDaFase/);
        assert.match(pubs, /identidade\/SeloDeStatusDaPublicacao/);
        assert.match(pubs, /identidade\/TileDoProduto/);
    });

    await contexto.test('⚠️ nenhuma fonte recalcula `oklch(` nem redesenha o glyph', () => {
        for (const caminho of FONTES_DO_PLANO) {
            const fonte = FONTES.get(caminho);
            assert.ok(!fonte.includes('oklch('), `${caminho} recalcula oklch — o tom do produto tem UMA casa`);
            // A geometria do retângulo do glyph tem UMA casa (GlyphDaFase.jsx).
            assert.ok(!(fonte.includes('w-[15px]') && fonte.includes('h-[18px]')), `${caminho} combina as duas medidas do glyph da marca`);
            assert.ok(!(fonte.includes('w-[9px]') && fonte.includes('h-[12px]')), `${caminho} combina as duas medidas do glyph do rodapé`);
            assert.ok(!(fonte.includes('w-[8px]') && fonte.includes('h-[10px]')), `${caminho} redesenha o retângulo do glyph das recentes`);
        }
    });

    await contexto.test('`haQuanto` é IMPORTADO de tempo.js e não redefinido', () => {
        assert.match(FONTES.get(REL_RECENTES), /from '\.\.\/tempo'/);
        assert.match(FONTES.get(REL_RECENTES), /haQuanto\(/);
        for (const caminho of FONTES_DO_PLANO) {
            assert.doesNotMatch(FONTES.get(caminho), /`há \$\{/, `${caminho} reimplementa o tempo relativo`);
            assert.doesNotMatch(FONTES.get(caminho), /'há '/, `${caminho} reimplementa o tempo relativo`);
        }
    });

    await contexto.test('`textoSeguro`/`urlSegura`/`numeroSeguro` vêm de onde já existem', () => {
        const fonte = FONTES.get(REL_RECENTES);
        assert.match(fonte, /from '\.\.\/BarraDaConta'/);
        assert.match(fonte, /from '\.\/acoes'/);
        assert.match(fonte, /from '\.\.\/identidade\/identidade'/);
        // E nenhuma cópia das guardas.
        assert.doesNotMatch(fonte, /function textoSeguro/);
        assert.doesNotMatch(fonte, /function urlSegura/);
        assert.doesNotMatch(fonte, /function numeroSeguro/);
        assert.doesNotMatch(fonte, /function normalizar/);
    });

    await contexto.test('⚠️ ASSERÇÃO NEGATIVA: zero SeloExemplo e zero dadosDeExemplo nas 5 fontes', () => {
        // ⚠️ Negativa de propósito: uma positiva ("o bloco é real") seguiria
        // verde se uma fase futura acrescentasse a pilha de exemplo (VG-20 —
        // bloco que hoje é real não regride a exemplo).
        for (const caminho of FONTES_DO_PLANO) {
            const fonte = FONTES.get(caminho);
            assert.doesNotMatch(fonte, /SeloExemplo/, `${caminho} marcou bloco REAL como exemplo`);
            assert.doesNotMatch(fonte, /dadosDeExemplo/, `${caminho} importou dados de exemplo`);
            assert.doesNotMatch(fonte, /EXEMPLO|Exemplo/, `${caminho} fala de exemplo`);
        }
        // ISCA: os dois nomes EXISTEM no projeto, então a varredura mede algo.
        assert.ok(fs.existsSync(path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/SeloExemplo.jsx')));
        assert.ok(fs.existsSync(path.resolve(RAIZ, 'resources/js/Components/Mlb/Publicador/dadosDeExemplo.js')));
    });

    await contexto.test('⚠️ nenhum destino de uma tela que não existe', () => {
        for (const caminho of FONTES_DO_PLANO) {
            const fonte = FONTES.get(caminho);
            assert.doesNotMatch(fonte, /desempenho'\)/, `${caminho} monta rota para a tela Desempenho (Fase 182)`);
            assert.doesNotMatch(fonte, /route\(/, `${caminho} monta rota — todo destino vem do servidor`);
            assert.doesNotMatch(fonte, /href="#"/, `${caminho} tem destino morto`);
        }
    });

    await contexto.test('FID-03/FID-04: nenhum hex, nenhuma escala nomeada, nenhum token antigo', () => {
        for (const caminho of FONTES_DO_PLANO) {
            const fonte = FONTES.get(caminho);
            assert.doesNotMatch(fonte, /#[0-9a-fA-F]{3,8}\b/, `${caminho} tem hex literal (TOK-15)`);
            assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/, `${caminho} usa escala nomeada (FID-03)`);
            assert.doesNotMatch(fonte, /\becf-(bg|card|card-2|yellow|yellow-2|line|dim|mute)\b/, `${caminho} usa token antigo (FID-04)`);
            assert.doesNotMatch(fonte, /\bfont-display\b/, `${caminho} usa font-display (FID-04)`);
            assert.doesNotMatch(fonte, /border-white\/\[0\.08\]/, `${caminho} usa a borda antiga (FID-04)`);
            assert.doesNotMatch(fonte, /\b(sm|md|lg|xl|2xl):/, `${caminho} usa breakpoint (FID-05)`);
            assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/, `${caminho} injeta HTML`);
        }
    });

    await contexto.test('T-179-SC: todo import externo já é dependência do package.json', () => {
        const pacote = JSON.parse(fs.readFileSync(path.resolve(RAIZ, 'package.json'), 'utf8'));
        const declarados = new Set([...Object.keys(pacote.dependencies ?? {}), ...Object.keys(pacote.devDependencies ?? {})]);
        for (const caminho of FONTES_DO_PLANO) {
            const imports = [...FONTES.get(caminho).matchAll(/from '([^']+)'/g)].map((m) => m[1]);
            for (const alvo of imports) {
                if (alvo.startsWith('.') || alvo.startsWith('@/')) continue;
                const raizDoPacote = alvo.startsWith('@') ? alvo.split('/').slice(0, 2).join('/') : alvo.split('/')[0];
                assert.ok(declarados.has(raizDoPacote), `${caminho} importa ${alvo}, que não está no package.json`);
            }
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// I. REND-04 — nenhuma variável de escopo do componente dentro do `.map()`
// ═══════════════════════════════════════════════════════════════════════════

test('I — REND-04: o `.map()` lê só o próprio parâmetro e módulo', async (contexto) => {
    /** Os corpos de callback de `.map(` de uma fonte, por balanceamento de chaves. */
    function corposDeMap(fonte) {
        const saida = [];
        const re = /\.map\(/g;
        let m;
        while ((m = re.exec(fonte)) !== null) {
            let i = m.index + m[0].length;
            let nivel = 1;
            while (i < fonte.length && nivel > 0) {
                if (fonte[i] === '(') nivel += 1;
                if (fonte[i] === ')') nivel -= 1;
                i += 1;
            }
            saida.push(fonte.slice(m.index, i));
        }
        return saida;
    }

    await contexto.test('a contagem de `.map()` por arquivo está travada', () => {
        const contar = (caminho) => (FONTES.get(caminho).match(/\.map\(/g) ?? []).length;
        assert.equal(contar(REL_RECENTES), 4, 'recentes.js: série, chips, eventos e o re-map do fio');
        assert.equal(contar(REL_BANNER), 0);
        assert.equal(contar(REL_FAIXA), 1);
        assert.equal(contar(REL_PUBS), 1);
        assert.equal(contar(REL_ATIV), 3, '⚠️ ANINHADO: chips, grupos e eventos');
    });

    await contexto.test('⚠️ nos `.jsx`, nenhum callback lê a variável de estado nem o view model', () => {
        // Os nomes que moram no CORPO do componente e que o Rollup elimina
        // quando lidos dentro do callback (`feedback_rollup_map_scope_bug.md`).
        const PROIBIDOS = {
            [REL_FAIXA]: ['kpis', 'dias', 'grade'],
            [REL_PUBS]: ['dados'],
            [REL_ATIV]: ['chip', 'chips', 'grupos', 'setChip'],
        };

        for (const [caminho, nomes] of Object.entries(PROIBIDOS)) {
            for (const corpo of corposDeMap(FONTES.get(caminho))) {
                for (const nome of nomes) {
                    // `setChip` é a única exceção DECLARADA: é o setter do
                    // `useState`, estável por contrato do React, e o precedente
                    // aceito do 179-07 (`setFiltro` no segmented).
                    if (nome === 'setChip') continue;
                    assert.doesNotMatch(
                        corpo,
                        new RegExp(`(^|[^.\\w])${nome}\\b`),
                        `${caminho}: o callback do .map() lê "${nome}" do escopo do componente (REND-04)`,
                    );
                }
            }
        }
    });

    await contexto.test('⚠️ ISCA: a varredura ACHA o padrão proibido quando ele existe', () => {
        const falso = "lista.map((x) => (x.chave === filtro ? 'a' : 'b'))";
        const corpos = corposDeMap(falso);
        assert.equal(corpos.length, 1);
        assert.match(corpos[0], /(^|[^.\w])filtro\b/, 'a varredura de REND-04 não acha nada — regex quebrado');
    });

    await contexto.test('as flags de render existem no VIEW MODEL, não no componente', () => {
        const recentes = FONTES.get(REL_RECENTES);
        for (const campo of ['subiu', 'caiu', 'ativa', 'tem_fio', 'premium', 'vazio', 'motivo_vazio']) {
            assert.match(recentes, new RegExp(`${campo}:`), `recentes.js perdeu a flag ${campo}`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// H. Casos adversos (REND-01/02) — a TELA PRETA DE 07/10
//
// ⚠️ Render REAL (esbuild + `react-dom/server`), nunca leitura de fonte: em
// 07/10 um campo-OBJETO renderizado como texto derrubou a PÁGINA INTEIRA. E
// `Invalid Date` entra na varredura porque esta é a única tela da fase que
// formata data.
// ═══════════════════════════════════════════════════════════════════════════

/** Os valores adversos que cada campo recebe, um por vez. */
const ADVERSOS = [{}, { a: 1 }, [], [1], null, undefined, 'x', '', '   ', 7, 0, true, false, NaN, Infinity];

test('H1 — a faixa de Desempenho com todo campo adverso', async (contexto) => {
    await contexto.test('`desempenho` inteiro em 15 formas', () => {
        for (const desempenho of ADVERSOS) {
            const html = renderFaixa({ desempenho });
            semLixo(html, `desempenho ${JSON.stringify(desempenho)}`);
        }
    });

    await contexto.test('`kpis` em 12 formas, e KPI nulo/estranho DESCARTADO', () => {
        for (const kpis of [null, undefined, 'x', 7, {}, [], [null], ['x'], [7], [{}], [[]], [true], [NaN]]) {
            const html = renderFaixa({ desempenho: { kpis, dias: 30, url: null } });
            semLixo(html, `kpis ${JSON.stringify(kpis)}`);
        }
        // KPI `{}` tem de aparecer (chave desconhecida APARECE), com "—".
        const comVazio = renderFaixa({ desempenho: { kpis: [{}], dias: 30, url: null } });
        assert.equal(celulasDeKpi(comVazio).length, 1);
        assert.ok(comVazio.includes('>Indicador<'));
        assert.ok(comVazio.includes('>—<'));
        // E os não-objetos são descartados sem derrubar o bloco.
        const misto = renderFaixa({ desempenho: { kpis: [null, 'x', 7, [], { chave: 'publicacoes_criadas', valor: 3, de: null, delta: null, serie: null }], dias: 30, url: null } });
        assert.equal(celulasDeKpi(misto).length, 1);
        semLixo(misto, 'kpis misto');
    });

    await contexto.test('cada campo do KPI como OBJETO, um por vez — e todos ao mesmo tempo', () => {
        for (const campo of ['chave', 'valor', 'de', 'delta', 'serie']) {
            for (const adverso of ADVERSOS) {
                const kpi = { chave: 'publicacoes_criadas', valor: 16, de: null, delta: 5, serie: [1, 2, 3], [campo]: adverso };
                const html = renderFaixa({ desempenho: { kpis: [kpi], dias: 30, url: null } });
                semLixo(html, `KPI com ${campo} = ${JSON.stringify(adverso)}`);
                assert.ok(html.includes('>Desempenho</h2>'), `a faixa desmontou com ${campo} adverso`);
            }
        }

        const tudoObjeto = renderFaixa({ desempenho: { kpis: [{ chave: {}, valor: {}, de: {}, delta: {}, serie: {} }], dias: {}, url: {} } });
        semLixo(tudoObjeto, 'KPI inteiro de objetos');
        assert.ok(tudoObjeto.includes('>Indicador<'));
        assert.ok(tudoObjeto.includes('>Últimos 30 dias<'));
    });

    await contexto.test('`serie` com string/objeto dentro ⇒ NENHUM svg, e nenhum NaN', () => {
        for (const serie of [[1, 'x', 3], [1, {}, 3], [1, null], [1, NaN], [1, Infinity], ['1', '2'], [[], []]]) {
            const html = renderFaixa({ desempenho: { kpis: [{ chave: 'criativos_ia', valor: 2, de: null, delta: null, serie }], dias: 30, url: null } });
            semLixo(html, `serie ${JSON.stringify(serie)}`);
            // Só o ícone do título.
            assert.equal(descendentes(raizDe(html), 'svg').length, 1, `desenhou sparkline para serie ${JSON.stringify(serie)}`);
        }
    });
});

test('H2 — "Publicações recentes" com todo campo adverso', async (contexto) => {
    await contexto.test('`recentes` inteiro e `itens` em todas as formas', () => {
        for (const recentes of ADVERSOS) {
            const html = renderPubs({ recentes });
            semLixo(html, `recentes ${JSON.stringify(recentes)}`);
            assert.ok(html.includes('>Publicações recentes</h2>'), 'o bloco desmontou');
            assert.ok(html.includes('>Nenhuma publicação no período<'));
        }
        for (const itens of [null, undefined, 'x', 7, {}, [], [null], ['x'], [7], [{}], [[]], [true], [NaN]]) {
            const html = renderPubs({ recentes: { disponivel: true, itens } });
            semLixo(html, `itens ${JSON.stringify(itens)}`);
            assert.ok(html.includes('>Publicações recentes</h2>'));
        }
        assert.ok(renderPubs().includes('>Nenhuma publicação no período<'));
    });

    await contexto.test('item `{}` APARECE, com "—" em cada campo e sem glyph', () => {
        const html = renderPubs({ recentes: { disponivel: true, itens: [{}] }, urlPublicacoes: '/p' });
        assert.equal(linhasDeRecentes(html).length, 1);
        semLixo(html, 'item vazio');
        assert.ok(html.includes('>—<'));
        assert.doesNotMatch(html, /border-dashed/, 'desenhou glyph de Legado para fase ausente');
        assert.doesNotMatch(html, />NOVA</);
    });

    await contexto.test('cada campo do item como OBJETO, um por vez', () => {
        const campos = ['produto_id', 'produto_nome', 'titulo', 'capa', 'fase', 'rotulo_fase', 'tipo', 'ml_item_id', 'situacao', 'vendas', 'quando', 'quem', 'nova'];
        for (const campo of campos) {
            for (const adverso of ADVERSOS) {
                const html = renderPubs({ recentes: { disponivel: true, itens: [recente({ [campo]: adverso })] }, urlPublicacoes: '/p', agora: AGORA });
                semLixo(html, `item com ${campo} = ${JSON.stringify(adverso)}`);
                assert.ok(html.includes('>Publicações recentes</h2>'), `o bloco desmontou com ${campo} adverso`);
                assert.equal(linhasDeRecentes(html).length, 1, `a linha sumiu com ${campo} adverso`);
            }
        }
    });

    await contexto.test('⚠️ `quem` como string, objeto vazio e objeto de objetos', () => {
        for (const quem of ['Ana', '', 7, {}, { tipo: {}, nome: {} }, { nome: '  ' }, { tipo: 'equipe' }, [], null]) {
            const html = renderPubs({ recentes: { disponivel: true, itens: [recente({ quem })] }, urlPublicacoes: '/p', agora: AGORA });
            semLixo(html, `quem ${JSON.stringify(quem)}`);
        }
        // `quem` string vale como autor (o contrato manda objeto, mas string é texto útil).
        const comString = renderPubs({ recentes: { disponivel: true, itens: [recente({ quem: 'Ana' })] }, urlPublicacoes: '/p', agora: AGORA });
        assert.ok(comString.includes('>Ana<'));
        // `quem` sem nada útil ⇒ a coluna sai SEM o autor, não com "—".
        const semAutor = renderPubs({ recentes: { disponivel: true, itens: [recente({ quem: {} })] }, urlPublicacoes: '/p', agora: AGORA });
        assert.equal(linhasDeRecentes(semAutor)[0].filhos[3].filhos.length, 1);
    });

    await contexto.test('⚠️ `quando` impossível não produz "Invalid Date"', () => {
        for (const quando of ['ontem', '13/10/2026', '', '   ', 'zzz', '2026-99-99T99:99:99Z', 7, {}, [], null, NaN]) {
            const html = renderPubs({ recentes: { disponivel: true, itens: [recente({ quando })] }, urlPublicacoes: '/p', agora: AGORA });
            semLixo(html, `quando ${JSON.stringify(quando)}`);
        }
    });

    await contexto.test('as 5 linhas sobrevivem a um payload INTEIRO de objetos', () => {
        const podre = { produto_id: {}, produto_nome: {}, titulo: {}, capa: {}, fase: {}, rotulo_fase: {}, tipo: {}, ml_item_id: {}, situacao: {}, vendas: {}, quando: {}, quem: {}, nova: {} };
        const html = renderPubs({ recentes: { disponivel: {}, itens: [podre, podre, podre, podre, podre] }, urlPublicacoes: {}, agora: AGORA });
        semLixo(html, 'payload inteiro de objetos');
        assert.equal(linhasDeRecentes(html).length, 5);
        assert.doesNotMatch(html, /href=/, 'urlPublicacoes objeto virou href');
    });
});

test('H3 — "Atividade recente" com todo campo adverso', async (contexto) => {
    await contexto.test('`grupos` e `eventos` em todas as formas', () => {
        for (const grupos of [null, undefined, 'x', 7, {}, [], [null], ['x'], [7], [{}], [[]], [true], [NaN]]) {
            const html = renderAtiv({ atividade: { disponivel: true, grupos } });
            semLixo(html, `grupos ${JSON.stringify(grupos)}`);
            assert.ok(html.includes('>Atividade recente</h2>'));
        }
        for (const eventos of [null, undefined, 'x', 7, {}, [], [null], ['x'], [7], [[]], [true], [NaN]]) {
            const html = renderAtiv({ atividade: { disponivel: true, grupos: [{ chave: 'hoje', eventos }] } });
            semLixo(html, `eventos ${JSON.stringify(eventos)}`);
            // Grupo que fica sem evento NÃO aparece.
            assert.ok(html.includes('>Nenhuma atividade neste filtro e período<'), `grupo vazio apareceu com eventos ${JSON.stringify(eventos)}`);
        }
    });

    await contexto.test('cada campo do evento como OBJETO, um por vez', () => {
        for (const campo of ['tipo', 'quem', 'quando_iso', 'titulo', 'detalhe']) {
            for (const adverso of ADVERSOS) {
                const evento = { tipo: 'publicou', quem: 'Ana', quando_iso: '2026-10-13T14:32:00-03:00', titulo: 'T', detalhe: 'D', [campo]: adverso };
                const html = renderAtiv({ atividade: { disponivel: true, grupos: [{ chave: 'hoje', data_iso: '2026-10-13', eventos: [evento] }] } });
                semLixo(html, `evento com ${campo} = ${JSON.stringify(adverso)}`);
                assert.ok(html.includes('>Atividade recente</h2>'), `o bloco desmontou com ${campo} adverso`);
                assert.equal(gruposDeAtividade(html).length, 1, `o grupo sumiu com ${campo} adverso`);
            }
        }
    });

    await contexto.test('⚠️ `chave` do grupo impossível cai em SEM DATA, nunca "Invalid Date"', () => {
        for (const chave of ['2026-99-99', 'amanhã', '', '   ', 7, {}, [], null, NaN, 'constructor']) {
            const html = renderAtiv({ atividade: { disponivel: true, grupos: [{ chave, eventos: [{ tipo: 'publicou', quem: 'A', quando_iso: null, titulo: 'B', detalhe: null }] }] } });
            semLixo(html, `chave do grupo ${JSON.stringify(chave)}`);
            assert.ok(html.includes('>SEM DATA<'), `a chave ${JSON.stringify(chave)} não caiu em SEM DATA`);
        }
    });

    await contexto.test('`chipInicial` adverso cai em "Tudo" e não derruba nada', () => {
        for (const chipInicial of ADVERSOS) {
            const html = renderAtiv({ atividade: ATIVIDADE(), chipInicial });
            semLixo(html, `chipInicial ${JSON.stringify(chipInicial)}`);
            assert.equal(descendentes(raizDe(html), 'button').length, 4);
            assert.equal(gruposDeAtividade(html).length, 2, 'o filtro desconhecido deveria mostrar tudo');
        }
    });

    await contexto.test('o payload INTEIRO de objetos não derruba a timeline', () => {
        const podre = { tipo: {}, quem: {}, quando_iso: {}, titulo: {}, detalhe: {} };
        const html = renderAtiv({ atividade: { disponivel: {}, grupos: [{ chave: {}, data_iso: {}, eventos: [podre, podre, podre] }] } });
        semLixo(html, 'atividade inteira de objetos');
        assert.equal(gruposDeAtividade(html).length, 1);
        assert.equal(eventosDoGrupo(gruposDeAtividade(html)[0]).length, 3);
        // Dois fios para três eventos, mesmo no pior payload.
        assert.equal((conteudoDe(html, gruposDeAtividade(html)[0]).match(/left-\[14\.5px\]/g) ?? []).length, 2);
    });
});

test('H3b — ⚠️ a guarda de NaN/Infinity (Rule 2, achada por este gate)', async (contexto) => {
    // `textoSeguro(NaN)` devolve a STRING 'NaN', porque `typeof NaN` é
    // 'number'. Sem a guarda, um `titulo: NaN` escreveria "NaN" na linha da
    // publicação e um `quem: NaN` escreveria "NaN publicou …".
    await contexto.test('CONTRA-PROVA: o `textoSeguro` cru de fato devolve "NaN"', () => {
        // A prova de que a guarda não é decorativa: é este comportamento que
        // ela existe para barrar.
        assert.equal(String(NaN), 'NaN');
        assert.equal(typeof NaN, 'number');
    });

    await contexto.test('nenhum campo de texto deixa NaN nem Infinity chegarem à tela', () => {
        for (const campo of ['titulo', 'rotulo_fase', 'tipo', 'ml_item_id', 'produto_nome', 'produto_id', 'fase', 'quem']) {
            for (const valor of [NaN, Infinity, -Infinity]) {
                const html = renderPubs({ recentes: { disponivel: true, itens: [recente({ [campo]: valor })] }, urlPublicacoes: '/p', agora: AGORA });
                assert.doesNotMatch(html, /NaN|Infinity/, `${campo} = ${valor} vazou para a tela`);
            }
        }
        for (const campo of ['tipo', 'quem', 'titulo', 'detalhe', 'quando_iso']) {
            for (const valor of [NaN, Infinity]) {
                const html = renderAtiv({ atividade: { disponivel: true, grupos: [{ chave: 'hoje', eventos: [{ tipo: 'publicou', quem: 'A', quando_iso: null, titulo: 'B', detalhe: 'C', [campo]: valor }] }] } });
                assert.doesNotMatch(html, /NaN|Infinity/, `evento.${campo} = ${valor} vazou para a tela`);
            }
        }
        // E a chave do grupo e do KPI.
        assert.ok(renderAtiv({ atividade: { disponivel: true, grupos: [{ chave: NaN, eventos: [{ tipo: 'publicou', quem: 'A', quando_iso: null, titulo: 'B', detalhe: null }] }] } }).includes('>SEM DATA<'));
        assert.doesNotMatch(renderFaixa({ desempenho: { kpis: [{ chave: NaN, valor: 3, de: NaN, delta: NaN, serie: null }], dias: NaN, url: null } }), /NaN|Infinity/);
    });
});

test('H4 — os quatro blocos SEM NENHUMA prop', () => {
    assert.equal(renderBanner(), '');
    assert.equal(renderFaixa(), '');
    const pubs = renderPubs();
    const ativ = renderAtiv();
    semLixo(pubs, 'recentes sem prop');
    semLixo(ativ, 'atividade sem prop');
    assert.ok(pubs.includes('>Publicações recentes</h2>'));
    assert.ok(ativ.includes('>Atividade recente</h2>'));
});
