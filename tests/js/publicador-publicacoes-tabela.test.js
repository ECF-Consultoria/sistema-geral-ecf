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
// Fase 180, plano 08 — o cabeçalho do grid, a LINHA com os cinco fundos e as
// SETE células, com render REAL (esbuild + `react-dom/server`).
//
// Protótipo: `new_layout_publicador/Publicacoes v2.dc.html`. Linhas MEDIDAS com
// `grep -n` no arquivo (não copiadas da tabela do plano):
//   · card           → :162   · cabeçalho     → :163
//   · checkbox       → :164   · check         → :165   · rótulos → :166
//   · a linha        → :202   · checkbox      → :203
//   · cél. Publicação→ :206   · tile          → :208   · coluna  → :210
//   · título         → :211   · meta          → :212   · MLB     → :213
//   · copiar         → :214   · copiado       → :215   · copiar  → :216
//   · NOVA           → :217   · nome do prod. → :218
//   · Metodologia    → :222   · glyphs :224-:232   · rótulo :233-:235
//   · Tipo           → :237   · losango :239  · Catálogo :242
//   · Status         → :244   · formas :246-:250  · motivo  :252
//   · Preço          → :254-:257   · Vendas   → :260-:262
//   · Publicada      → :264-:268   · ações    → :271-:275
//   · os 5 fundos    → :553   · as alturas    → :618
//   · `brl`          → :488   · `fmtDate`     → :492   · `phaseInfo` → :509
//
// ⚠️ REND-03: TODOS os bundles são montados AQUI NO TOPO, antes do primeiro
// `test()`. O `after()` do `node --test` dispara quando os testes registrados
// terminam e já matou um arquivo inteiro deste projeto SEM nenhum teste falhar
// (sintoma: 37 testes em vez de 94).
//
// ⚠️⚠️ ESTA TELA É O PIOR CASO DE IRMÃO IDÊNTICO DA FASE: N linhas com a MESMA
// string de classes, 9 colunas por linha, 5 estados de fundo. `html.indexOf(…)`
// de uma lista de classes pega o IRMÃO ERRADO — quatro gates do ERP mediram a
// linha do Mercado Livre por isso. ➡️ Aqui se renderizam SEMPRE **5 linhas** e
// toda localização é por ÁRVORE com índices absolutos: primeiro os índices de
// todas as `<div role="row">`, depois a identidade de cada uma confirmada pelo
// `ml_item_id`, que é ÚNICO por linha. Nunca pela lista de classes.
//
// ⚠️ A pergunta obrigatória — *"o que precisaria estar errado no código para
// este gate falhar?"* — está respondida POR TESTE, no comentário de cada bloco.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

const STUB_INERTIA = path.join(__dirname, `.pub-tabela-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, delete: () => {}, reload: () => {} };
export function usePage() { return { props: { auth: { user: { id: 7 } } } }; }
`, 'utf8');

after(() => {
    fs.rmSync(STUB_INERTIA, { force: true });
});

/** Compila um módulo de verdade (JSX + imports reais) e devolve os exports. */
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
        },
        external: [
            'react', 'react-dom', 'react-dom/server', 'react/jsx-runtime',
            'lucide-react', 'axios', 'recharts', 'date-fns', 'date-fns/*',
            'clsx', 'tailwind-merge', 'class-variance-authority',
            '@radix-ui/react-popover', '@radix-ui/react-dialog',
            '@radix-ui/*', '@headlessui/*',
        ],
    });

    const outfile = path.join(
        __dirname,
        `.pub-tabela-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_PUB = 'resources/js/Components/Mlb/Publicador/publicacoes';
const DIR_IDENT = 'resources/js/Components/Mlb/Publicador/identidade';
const REL_CABECALHO = `${DIR_PUB}/CabecalhoDaTabela.jsx`;
const REL_LINHA = `${DIR_PUB}/LinhaDePublicacao.jsx`;
const REL_CELULAS = `${DIR_PUB}/CelulasDaLinha.jsx`;

// ⚠️ `test -e` ANTES de acreditar em qualquer leitura vazia. CINCO caminhos
// inexistentes já "provaram" território intocado nesta milestone.
for (const caminho of [DIR_PUB, DIR_IDENT, REL_CABECALHO, REL_LINHA, REL_CELULAS]) {
    if (!fs.existsSync(path.resolve(RAIZ, caminho))) {
        throw new Error(`caminho inexistente: ${caminho} — gate mediria NADA`);
    }
}

const MOD_CABECALHO = await montar(REL_CABECALHO);
const MOD_LINHA = await montar(REL_LINHA);
const MOD_CELULAS = await montar(REL_CELULAS);

const Cabecalho = MOD_CABECALHO.default;
const Linha = MOD_LINHA.default;

const FONTE_CABECALHO = lerSemComentarios(REL_CABECALHO);
const FONTE_LINHA = lerSemComentarios(REL_LINHA);
const FONTE_CELULAS = lerSemComentarios(REL_CELULAS);

const FONTES = [
    [REL_CABECALHO, FONTE_CABECALHO],
    [REL_LINHA, FONTE_LINHA],
    [REL_CELULAS, FONTE_CELULAS],
];

/** Render de servidor. */
const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

// ─────────────────────────────────────────────────────────────────────────
// Localização por ÁRVORE com índices absolutos (o molde dos planos 180-06/07)
// ─────────────────────────────────────────────────────────────────────────

/** A tag de abertura inteira que começa em `pos`. */
function tagEm(html, pos) {
    assert.ok(pos !== -1 && pos < html.length, 'posição fora do HTML');
    const fim = html.indexOf('>', pos);
    assert.ok(fim !== -1, 'tag de abertura sem fechamento');
    return html.slice(pos, fim + 1);
}

/**
 * O BLOCO inteiro (abertura + conteúdo + fechamento) do elemento `nome` que
 * começa no índice absoluto `pos`, contando PROFUNDIDADE.
 *
 * ⚠️ É isto que torna a localização por árvore honesta: fatiar "do começo da tag
 * até o próximo `</div>`" fecharia no FILHO, não no pai — e esta linha tem
 * `<div>` dentro de `<div>` em cinco das nove colunas.
 */
function blocoEm(html, pos, nome) {
    assert.equal(html.slice(pos, pos + nome.length + 1), `<${nome}`, `o índice ${pos} não abre <${nome}>`);
    const re = new RegExp(`</?${nome}(?=[\\s/>])`, 'g');
    re.lastIndex = pos;
    let profundidade = 0;
    let m = re.exec(html);
    while (m !== null) {
        if (m[0][1] === '/') {
            profundidade -= 1;
            if (profundidade === 0) {
                const fim = html.indexOf('>', m.index);
                assert.ok(fim !== -1, 'fechamento sem `>`');
                return html.slice(pos, fim + 1);
            }
        } else {
            const fim = html.indexOf('>', m.index);
            assert.ok(fim !== -1, 'abertura sem `>`');
            if (html[fim - 1] !== '/') profundidade += 1;
        }
        m = re.exec(html);
    }
    throw new Error(`<${nome}> aberta em ${pos} nunca fecha`);
}

/** Os índices absolutos de TODAS as tags de abertura `<nome` dentro de `bloco`. */
function indicesDasTags(html, bloco, nome) {
    const base = html.indexOf(bloco);
    assert.ok(base !== -1, 'o bloco não pertence a este HTML');
    return [...bloco.matchAll(new RegExp(`<${nome}(?=[\\s/>])`, 'g'))].map((m) => base + m.index);
}

/**
 * Os índices absolutos das tags `<nome` INTERNAS a `bloco` — ou seja, SEM a
 * própria tag de abertura do bloco.
 *
 * ⚠️ ESTA RODADA MEDIU O FURO: `tagsInternas(html, celula, 'div')[0]` devolve
 * a CÉLULA, não o primeiro `<div>` de dentro dela — porque o bloco COMEÇA com a
 * tag dela. Quatro asserções deste gate estavam conferindo as classes do PAI
 * acreditando conferir as do FILHO, e **reprovaram o código certo**. É a mesma
 * família do `html.indexOf()` pegando o irmão errado: a localização parecia por
 * árvore e era por sorte.
 */
function tagsInternas(htmlCompleto, bloco, nome) {
    const todas = indicesDasTags(htmlCompleto, bloco, nome);
    const base = htmlCompleto.indexOf(bloco);
    return todas.filter((pos) => pos !== base);
}

/** Os índices absolutos de todas as `<div role="row">` do HTML. */
function indicesDasLinhas(html) {
    return [...html.matchAll(/<div role="row"/g)].map((m) => m.index);
}

/**
 * As NOVE células de uma linha, por índice absoluto, na ORDEM do grid.
 *
 * ⚠️ São os filhos DIRETOS da linha — e é por isso que a contagem de
 * profundidade importa: pegar "todas as `<div`" da linha devolveria os
 * wrappers internos também.
 */
function celulasDaLinha(html, posLinha) {
    const linha = blocoEm(html, posLinha, 'div');
    const base = html.indexOf(linha);
    const celulas = [];
    let i = 1; // pula o `<div role="row"` de abertura
    while (i < linha.length) {
        if (linha[i] === '<' && linha[i + 1] !== '/') {
            const nome = /^<([a-z]+)/.exec(linha.slice(i))?.[1];
            if (nome === undefined) break;
            const bloco = blocoEm(html, base + i, nome);
            celulas.push({ pos: base + i, nome, bloco });
            i += bloco.length;
        } else {
            i += 1;
        }
    }
    return celulas;
}

/** As classes de uma tag de abertura, como LISTA (nunca substring). */
function classesDa(tag) {
    const m = tag.match(/class="([^"]*)"/);
    assert.ok(m, `tag sem class=: ${tag}`);
    return m[1].split(/\s+/).filter(Boolean);
}

/**
 * Igualdade de CONJUNTO de classes.
 *
 * ⚠️ É este gate — e não o de CSS — que protege a fidelidade DESTES arquivos:
 * classe a mais reprova, classe a menos reprova, e o CSS do último build não
 * tem voto. (Limitação medida nos planos 180-05 e 180-06: a mesma classe pode
 * ter OUTRO dono no repositório, e aí tirá-la daqui não a tira do CSS.)
 */
function assertClasses(tag, esperadas, onde) {
    assert.deepEqual(
        [...classesDa(tag)].sort(),
        [...esperadas].sort(),
        `${onde}: classes divergentes em ${tag}`,
    );
}

/** Um atributo de uma tag de abertura, ou `null`. */
function atributo(tag, nome) {
    const m = tag.match(new RegExp(`\\s${nome}="([^"]*)"`));
    return m ? m[1] : null;
}

/** O texto visível de um bloco (sem tags), colapsado. */
function textoDe(bloco) {
    return bloco.replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
}

/**
 * Descritor SEGURO para mensagem de asserção.
 *
 * ⚠️ `String({toString: null})` LANÇA e mataria o gate DENTRO da mensagem (foi
 * o que aconteceu com 5 blocos no plano 180-05); `JSON.stringify` MENTE
 * (`NaN`/`Infinity` → `'null'`).
 */
function descreve(valor) {
    if (valor === null) return 'null';
    if (valor === undefined) return 'undefined';
    const tipo = typeof valor;
    if (tipo === 'number' || tipo === 'boolean' || tipo === 'bigint') return `${tipo}:${valor}`;
    if (tipo === 'string') return `string(${valor.length} chars)`;
    if (tipo === 'function') return 'function';
    if (Array.isArray(valor)) return `array(${valor.length})`;
    try {
        return `object{${Object.keys(valor).join(',')}}`;
    } catch {
        return 'object(ilegível)';
    }
}

/** O detector de sujeira de render — a tela preta de 07/10/2026. */
const RE_SUJEIRA = /\[object Object\]|NaN|undefined|Invalid Date/;

/** O travessão da ausência (U+2014), pelo CODE POINT e não pelo glifo colado. */
const TRAVESSAO = String.fromCharCode(0x2014);

// ─────────────────────────────────────────────────────────────────────────
// OS FIXTURES
//
// ⚠️ As chaves e os valores são os do SERVIDOR, conferidos no controller
// (`MlbAnuncioController.php:729`-`:805`): `status` cru do acervo
// (`active|paused|under_review|closed`), `tipo` em `['classico','premium']`
// (`:801`-`:803`), `motivos` como SLUG (`AcervoTriagemService::motivosDef()`),
// `autor_tipo` em `['pessoa','desconhecido']`
// (`PublicacoesDaListaService.php:79`-`:81`), `publicada_em` em ISO-8601 com
// offset local (`:543`-`:550`).
//
// ⚠️ `preco`, `margem` e `nova` NÃO vêm do servidor hoje (medido: o item tem 34
// chaves e nenhuma delas). Os fixtures os preenchem para que as asserções de
// AUSÊNCIA não sejam vazias por construção, e as lacunas estão na SUMMARY.
// ─────────────────────────────────────────────────────────────────────────

/** Um item CHEIO — nenhum campo nulo, para as negativas valerem algo. */
const ITEM_CHEIO = {
    ml_item_id: 'MLB4402230188',
    titulo: 'Kit 2 Fones de Ouvido Bluetooth XYZ Sem Fio Cancelamento de Ruído',
    thumbnail: null,
    permalink: 'https://www.mercadolivre.com.br/p/MLB4402230188',
    origem: 'ecf',
    rascunho_id: 31,
    produto_id: 12,
    produto_nome: 'Fone Bluetooth XYZ',
    produto_sku: 'FON-001',
    fase: 2,
    quantidade_kit: 2,
    tipo: 'premium',
    catalogo: true,
    status: 'paused',
    motivos: ['sem_estoque'],
    severidade: 2,
    nota_ecf: 72,
    nota_base: 86,
    estoque: 4,
    preco: 309.9,
    vendas: 4,
    visitas: 1904,
    visitas_dias: 3,
    autor_nome: 'Ana Rodrigues Silva',
    autor_tipo: 'pessoa',
    publicada_em: '2026-10-13T09:05:00-03:00',
    // ⚠️ ACRESCENTADO PELO PLANO 180-10B: o SKU por VARIAÇÃO voltou à linha por
    // decisão do usuário (14/10/2026). Está no fixture CHEIO de propósito —
    // assim (a) o laço de render adverso cobre a chave nova em 18 formas + a
    // ausência, com a contagem DERIVADA, e (b) as negativas do bloco do SKU
    // deixam de ser vazias por construção (learnings §4.10: asserção de
    // ausência rodada com dado vazio não prova nada).
    skus: ['FON-001-P', 'FON-001-G'],
};

const ROTULOS_DE_MOTIVO = {
    pausado: 'Pausado',
    sem_estoque: 'Sem estoque',
    ficha_incompleta: 'Ficha incompleta',
    perdendo_catalogo: 'Perdendo catálogo',
    foto_insuficiente: 'Foto insuficiente',
};

/** `hoje` FIXO: teste dependente de `now()` deste projeto já reprovou por horário. */
const HOJE = '2026-10-13';

const PROPS_BASE = {
    item: ITEM_CHEIO,
    hoje: HOJE,
    rotulosDeMotivo: ROTULOS_DE_MOTIVO,
    urlDoRascunho: '/mlb/anuncios/wizard/9?rascunho=31',
    onAbrir: () => {},
    onMarcar: () => {},
    onCopiar: () => {},
};

/**
 * CINCO linhas irmãs, cada uma num dos cinco estados de fundo, com
 * `ml_item_id` DISTINTO — é o que torna a localização por texto único honesta.
 */
const ESTADOS_DAS_CINCO = [
    { id: 'MLB1000000001', props: { selecionada: true }, fundo: 'bg-pub-row-sel' },
    { id: 'MLB1000000002', props: { comFoco: true }, fundo: 'bg-pub-row-focus' },
    { id: 'MLB1000000003', props: { marcada: true }, fundo: 'bg-pub-row-checked' },
    { id: 'MLB1000000004', props: { novaNoItem: true }, fundo: 'bg-pub-row-new' },
    { id: 'MLB1000000005', props: {}, fundo: 'bg-pub-surface' },
];

function Tabela({ linhas }) {
    return React.createElement(
        'div',
        null,
        React.createElement(Cabecalho, { todasMarcadas: false, onMarcarTodas: () => {} }),
        ...linhas.map((props, indice) => React.createElement(Linha, { key: indice, ...props })),
    );
}

const HTML_CINCO = renderToStaticMarkup(
    React.createElement(Tabela, {
        linhas: ESTADOS_DAS_CINCO.map(({ id, props }) => {
            const { novaNoItem, ...resto } = props;
            return {
                ...PROPS_BASE,
                item: { ...ITEM_CHEIO, ml_item_id: id, nova: novaNoItem === true },
                ...resto,
            };
        }),
    }),
);

/** Uma linha sozinha, com as props que o caso quiser. */
const umaLinha = (extra = {}) => render(Linha, { ...PROPS_BASE, ...extra });

const HTML_UMA = umaLinha();
const HTML_COMPACTA = umaLinha({ compacta: true });
const HTML_AGRUPADA = umaLinha({ agrupada: true });
const HTML_CABECALHO_OFF = render(Cabecalho, { todasMarcadas: false, onMarcarTodas: () => {} });
const HTML_CABECALHO_ON = render(Cabecalho, { todasMarcadas: true, onMarcarTodas: () => {} });

/** Acha a linha cujo `ml_item_id` é `id` — por TEXTO único, nunca por classe. */
function linhaDoItem(html, id) {
    const posicoes = indicesDasLinhas(html);
    const achadas = posicoes.filter((pos) => blocoEm(html, pos, 'div').includes(`>${id}<`));
    assert.equal(achadas.length, 1, `esperava UMA linha com ${id}, achei ${achadas.length}`);
    return achadas[0];
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. O GRID LITERAL — e a casa ÚNICA da string
//
// O que precisaria estar errado para falhar: um dos 9 valores trocado
// (`136px`→`140px`), a ordem das colunas mudada, a string DUPLICADA num segundo
// arquivo (que é o bug clássico: cabeçalho desalinhando das linhas), a classe
// montada por concatenação (que o scanner do Tailwind não vê) ou a constante
// mudando de arquivo para um `.js`, que o `content` do Tailwind NÃO varre.
// ═══════════════════════════════════════════════════════════════════════════

const GRID_LITERAL = 'grid-cols-[40px_minmax(300px,1fr)_136px_104px_136px_112px_68px_150px_64px]';

test('FID — o grid das 9 colunas é o literal do protótipo :163/:202', async (contexto) => {
    await contexto.test('a constante exportada é o literal, byte a byte', () => {
        assert.equal(MOD_CABECALHO.GRID_DA_TABELA, GRID_LITERAL);
        // E a linha reexporta a MESMA referência — não uma cópia.
        assert.equal(MOD_LINHA.GRID_DA_TABELA, MOD_CABECALHO.GRID_DA_TABELA);
    });

    await contexto.test('⚠️ o literal mora em UM arquivo só', () => {
        const comLiteral = FONTES.filter(([, fonte]) => fonte.includes(GRID_LITERAL));
        assert.deepEqual(
            comLiteral.map(([caminho]) => caminho),
            [REL_CABECALHO],
            'o `grid-template-columns` apareceu em mais de um arquivo — duas strings divergindo'
            + ' desalinha o cabeçalho das linhas e nenhum render acusa',
        );
        // E a linha usa o IDENTIFICADOR, não o literal.
        assert.match(FONTE_LINHA, /GRID_DA_TABELA/);
    });

    await contexto.test('⚠️ a constante mora num `.jsx` (o Tailwind não varre `.js`)', () => {
        assert.ok(REL_CABECALHO.endsWith('.jsx'), 'classe em `.js` de `resources/js` NÃO gera CSS');
        // E não é montada por concatenação: o scanner não vê classe concatenada.
        assert.match(
            FONTE_CABECALHO,
            /export const GRID_DA_TABELA =\s*\n?\s*'grid-cols-\[/,
            'a constante deixou de ser um literal único',
        );
    });

    await contexto.test('o cabeçalho E as 5 linhas carregam a classe, pela LISTA', () => {
        const cabecalho = tagEm(HTML_CINCO, HTML_CINCO.indexOf('<div role="row"'));
        assert.ok(classesDa(cabecalho).includes(GRID_LITERAL), 'o cabeçalho perdeu o grid');

        for (const { id } of ESTADOS_DAS_CINCO) {
            const tag = tagEm(HTML_CINCO, linhaDoItem(HTML_CINCO, id));
            assert.ok(classesDa(tag).includes(GRID_LITERAL), `a linha ${id} perdeu o grid`);
        }
    });

    await contexto.test('as 6 tags com `role="row"` existem (cabeçalho + 5 linhas)', () => {
        assert.equal(indicesDasLinhas(HTML_CINCO).length, 6);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. OS CINCO FUNDOS — e a precedência com as QUATRO condições verdadeiras
//
// O que precisaria estar errado para falhar: a ordem do ternário trocada (foco
// antes de selecionada), qualquer PAR de fundos uniformizado (`row-checked`
// virando `row-new`, `row-focus` virando `line-subtle-2`, que é o MESMO hex com
// outro nome), ou o fundo decidido por truthy em vez de `=== true` (e aí um
// campo-objeto pintaria a linha de selecionada).
// ═══════════════════════════════════════════════════════════════════════════

test('FID — os CINCO fundos de linha são distintos e a precedência é a do :553', async (contexto) => {
    await contexto.test('um caso por fundo, a linha localizada por `ml_item_id`', () => {
        for (const { id, fundo } of ESTADOS_DAS_CINCO) {
            const classes = classesDa(tagEm(HTML_CINCO, linhaDoItem(HTML_CINCO, id)));
            assert.ok(classes.includes(fundo), `a linha ${id} deveria ter ${fundo}`);

            // ⚠️ E NENHUM dos outros quatro: fundo a mais é fundo errado.
            for (const outro of ESTADOS_DAS_CINCO) {
                if (outro.fundo === fundo) continue;
                assert.ok(
                    !classes.includes(outro.fundo),
                    `a linha ${id} tem ${fundo} E ${outro.fundo} ao mesmo tempo`,
                );
            }
        }
    });

    await contexto.test('⚠️ os cinco nomes são CINCO (nenhum par uniformizado)', () => {
        const nomes = new Set(ESTADOS_DAS_CINCO.map(({ fundo }) => fundo));
        assert.equal(nomes.size, 5, 'dois fundos viraram o mesmo token');
        // E o `row-focus` não pode ter sido escrito como o token de BORDA do
        // mesmo hex: `pub-line-subtle-2` é #1C1C1C também, e usá-lo como fundo
        // é o nome mentindo sobre o uso (o próprio tailwind.config.js adverte).
        assert.ok(
            !FONTE_LINHA.includes('bg-pub-line-subtle-2'),
            'o fundo de foco virou o token de BORDA do mesmo hex',
        );
    });

    await contexto.test('⚠️ as QUATRO condições verdadeiras ao mesmo tempo → row-sel', () => {
        const html = umaLinha({
            item: { ...ITEM_CHEIO, nova: true },
            selecionada: true,
            comFoco: true,
            marcada: true,
        });
        const classes = classesDa(tagEm(html, indicesDasLinhas(html)[0]));
        assert.ok(classes.includes('bg-pub-row-sel'));
        for (const perdedor of ['bg-pub-row-focus', 'bg-pub-row-checked', 'bg-pub-row-new', 'bg-pub-surface']) {
            assert.ok(!classes.includes(perdedor), `${perdedor} venceu de row-sel`);
        }
    });

    await contexto.test('a precedência, par a par, pelo helper puro', () => {
        const f = MOD_LINHA.fundoDaLinha;
        assert.equal(f({ selecionada: true, comFoco: true }), 'bg-pub-row-sel');
        assert.equal(f({ comFoco: true, marcada: true }), 'bg-pub-row-focus');
        assert.equal(f({ marcada: true, nova: true }), 'bg-pub-row-checked');
        assert.equal(f({ nova: true }), 'bg-pub-row-new');
        assert.equal(f({}), 'bg-pub-surface');
        assert.equal(f(), 'bg-pub-surface');
    });

    await contexto.test('⚠️ truthy NÃO pinta: só `=== true`', () => {
        const f = MOD_LINHA.fundoDaLinha;
        for (const valor of [{}, 1, 'sim', [], 'false']) {
            assert.equal(
                f({ selecionada: valor }),
                'bg-pub-surface',
                `um ${descreve(valor)} em \`selecionada\` pintou a linha de selecionada`,
            );
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. 62 × 50 — `min-height`, nunca `height`
//
// O que precisaria estar errado para falhar: `min-h-[62px]` virando `h-[62px]`
// (e aí a célula Status de duas linhas estoura para fora da linha), as duas
// alturas trocadas, ou a compacta medindo outra coisa. ⚠️ A conferência é pela
// LISTA de classes: `min-h-[50px]` como substring casaria dentro de outra
// classe arbitrária.
// ═══════════════════════════════════════════════════════════════════════════

test('FID — a linha tem 62px no confortável e 50px na compacta (:618)', async (contexto) => {
    await contexto.test('confortável = min-h-[62px]', () => {
        const classes = classesDa(tagEm(HTML_UMA, indicesDasLinhas(HTML_UMA)[0]));
        assert.ok(classes.includes('min-h-[62px]'));
        assert.ok(!classes.includes('min-h-[50px]'));
        assert.ok(!classes.includes('h-[62px]'), '`height` no lugar de `min-height` estoura a Status de 2 linhas');
    });

    await contexto.test('compacta = min-h-[50px]', () => {
        const classes = classesDa(tagEm(HTML_COMPACTA, indicesDasLinhas(HTML_COMPACTA)[0]));
        assert.ok(classes.includes('min-h-[50px]'));
        assert.ok(!classes.includes('min-h-[62px]'));
        assert.ok(!classes.includes('h-[50px]'));
    });

    await contexto.test('as constantes são as duas medidas e nada mais', () => {
        assert.equal(MOD_LINHA.ALTURA_CONFORTAVEL, 'min-h-[62px]');
        assert.equal(MOD_LINHA.ALTURA_COMPACTA, 'min-h-[50px]');
    });

    await contexto.test('o conjunto COMPLETO de classes da linha (:202)', () => {
        const tag = tagEm(HTML_UMA, indicesDasLinhas(HTML_UMA)[0]);
        assertClasses(tag, [
            'grid', GRID_LITERAL, 'cursor-pointer', 'items-center',
            'border-t', 'border-pub-line-subtle', 'px-[16px]',
            'transition-colors', 'duration-200', 'min-h-[62px]', 'bg-pub-surface',
        ], 'a linha');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. O CABEÇALHO — 9 rótulos, na ordem, com as medidas do :163/:164/:166
//
// O que precisaria estar errado para falhar: um rótulo parafraseado
// ("Anúncio" por "Publicação"), a ordem trocada, `Preço`/`Vendas` perdendo o
// `text-right`, `Publicada` perdendo o `pl-[18px]`, a 9ª coluna deixando de
// existir (e aí as 9 colunas do grid teriam 8 filhos e TUDO desloca uma
// coluna), ou o checkbox mudando de medida/cor.
// ═══════════════════════════════════════════════════════════════════════════

test('FID — o cabeçalho do grid (:163-:166)', async (contexto) => {
    await contexto.test('o conjunto de classes do cabeçalho', () => {
        assertClasses(tagEm(HTML_CABECALHO_OFF, 0), [
            'grid', GRID_LITERAL, 'h-[40px]', 'items-center',
            'border-b', 'border-pub-line', 'bg-pub-sunken', 'px-[16px]',
            'text-[12px]', 'font-medium', 'text-pub-txt-3',
        ], 'o cabeçalho');
    });

    await contexto.test('⚠️ os 9 filhos, na ORDEM, com os literais byte a byte', () => {
        const celulas = celulasDaLinha(HTML_CABECALHO_OFF, 0);
        assert.equal(celulas.length, 9, `o cabeçalho tem ${celulas.length} colunas, não 9`);

        assert.equal(celulas[0].nome, 'button', 'a 1ª coluna é o checkbox');
        const esperados = ['Publicação', 'Metodologia', 'Tipo', 'Status', 'Preço', 'Vendas', 'Publicada', ''];
        esperados.forEach((texto, i) => {
            assert.equal(textoDe(celulas[i + 1].bloco), texto, `a coluna ${i + 2} deveria dizer "${texto}"`);
        });
    });

    await contexto.test('alinhamento e recuo dos rótulos (:166)', () => {
        const celulas = celulasDaLinha(HTML_CABECALHO_OFF, 0);
        assertClasses(tagEm(HTML_CABECALHO_OFF, celulas[5].pos), ['text-right'], 'o rótulo Preço');
        assertClasses(tagEm(HTML_CABECALHO_OFF, celulas[6].pos), ['text-right'], 'o rótulo Vendas');
        assertClasses(tagEm(HTML_CABECALHO_OFF, celulas[7].pos), ['pl-[18px]'], 'o rótulo Publicada');
        // A 9ª é vazia e sem classe — é o espaço da coluna de ações.
        assert.equal(atributo(tagEm(HTML_CABECALHO_OFF, celulas[8].pos), 'class'), null);
    });

    await contexto.test('o checkbox de 16px, ON e OFF (:164 / :605)', () => {
        const comum = ['grid', 'h-[16px]', 'w-[16px]', 'cursor-pointer', 'place-items-center', 'rounded-pub-badge', 'border'];
        const off = celulasDaLinha(HTML_CABECALHO_OFF, 0)[0];
        assertClasses(tagEm(HTML_CABECALHO_OFF, off.pos), [...comum, 'border-pub-line-off', 'bg-pub-hover'], 'checkbox OFF');

        const on = celulasDaLinha(HTML_CABECALHO_ON, 0)[0];
        assertClasses(tagEm(HTML_CABECALHO_ON, on.pos), [...comum, 'border-pub-accent', 'bg-pub-accent'], 'checkbox ON');
    });

    await contexto.test('⚠️ o checkbox é acessível e NÃO nasce desabilitado', () => {
        const tag = tagEm(HTML_CABECALHO_OFF, celulasDaLinha(HTML_CABECALHO_OFF, 0)[0].pos);
        assert.equal(atributo(tag, 'role'), 'checkbox');
        assert.equal(atributo(tag, 'aria-checked'), 'false');
        assert.equal(atributo(tag, 'aria-label'), 'Selecionar todas');
        assert.equal(atributo(tag, 'title'), 'Selecionar todas');
        // ⚠️ `/disabled/` casaria com a classe `disabled:opacity-40`; o `=` é o
        // que distingue o ATRIBUTO da variante.
        assert.doesNotMatch(tag, /disabled=/, 'o checkbox do cabeçalho nasceu desabilitado');
        assert.equal(atributo(tagEm(HTML_CABECALHO_ON, celulasDaLinha(HTML_CABECALHO_ON, 0)[0].pos), 'aria-checked'), 'true');
    });

    await contexto.test('o check de dentro: 10×10 viewBox 16, stroke 2.4 (:165)', () => {
        const svg = /<svg[^>]*>/.exec(HTML_CABECALHO_OFF)[0];
        assert.equal(atributo(svg, 'width'), '10');
        assert.equal(atributo(svg, 'height'), '10');
        assert.equal(atributo(svg, 'viewBox'), '0 0 16 16');
        assert.equal(atributo(svg, 'stroke-width'), '2.4');
        assert.equal(atributo(svg, 'stroke-linecap'), 'round');
        assert.equal(atributo(svg, 'stroke-linejoin'), 'round');
        assert.equal(atributo(svg, 'fill'), 'none');
        assert.ok(classesDa(svg).includes('stroke-pub-page'));
        assert.ok(HTML_CABECALHO_OFF.includes('d="M3.5 8.5l3 3 6-7"'));
    });

    await contexto.test('o card da tabela (:162) existe como constante', () => {
        assert.equal(
            MOD_CABECALHO.CLASSE_DO_CARD,
            'overflow-hidden rounded-pub-table border border-pub-line bg-pub-surface',
        );
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. O "—" DA MARGEM (LIST-12) — com o item TOTALMENTE preenchido
//
// O que precisaria estar errado para falhar: a 2ª linha do Preço virar
// "Margem 0,0%", "R$ 0,00" ou um número estimado quando `margem` não chegou.
// ⚠️ A asserção de ausência roda com o item CHEIO (preço, vendas, visitas,
// autor, tudo) — asserção de ausência com dado vazio é vazia por construção.
// ⚠️ E ela é fatiada na CÉLULA: `doesNotMatch(html, /%/)` seria vazio, porque há
// `%` em `100%`, em `minmax` e nas cores.
// ═══════════════════════════════════════════════════════════════════════════

/** A célula Preço (6ª coluna) de uma linha, pelo índice ABSOLUTO. */
function celulaPreco(html) {
    return celulasDaLinha(html, indicesDasLinhas(html)[0])[5].bloco;
}

test('LIST-12 — a célula Preço mostra `—` enquanto a margem não chega', async (contexto) => {
    await contexto.test('⚠️ item CHEIO, `margem` ausente → o travessão e NADA de margem', () => {
        const celula = celulaPreco(HTML_UMA);
        // O item está cheio de verdade — a negativa não é vazia.
        assert.ok(HTML_UMA.includes('R$ 309,90'), 'o fixture não está preenchido');
        assert.ok(HTML_UMA.includes('1.904 vis.'));
        assert.ok(HTML_UMA.includes('Ana Rodrigues Silva'));

        assert.ok(celula.includes(TRAVESSAO), 'a célula Preço não mostra o travessão U+2014');
        assert.ok(!celula.includes('Margem'), `a célula inventou margem: ${celula}`);
        assert.ok(!celula.includes('%'), 'a célula tem um percentual sem dado de margem');
        assert.ok(!celula.includes('0,0'), 'a célula escreveu um zero de margem');
    });

    await contexto.test('`margem: null` e `margem: 0` NÃO são a mesma coisa', () => {
        const nulo = celulaPreco(umaLinha({ item: { ...ITEM_CHEIO, margem: null } }));
        assert.ok(nulo.includes(TRAVESSAO) && !nulo.includes('Margem'));

        const zero = celulaPreco(umaLinha({ item: { ...ITEM_CHEIO, margem: 0 } }));
        assert.ok(zero.includes('Margem 0,0%'), 'margem MEDIDA em zero tem de aparecer');
    });

    await contexto.test('`preco: null` é `—`; `preco: 0` é `R$ 0,00`', () => {
        const nulo = celulaPreco(umaLinha({ item: { ...ITEM_CHEIO, preco: null } }));
        assert.ok(nulo.includes(TRAVESSAO));
        assert.ok(!nulo.includes('R$'), 'preço ausente virou moeda');

        const zero = celulaPreco(umaLinha({ item: { ...ITEM_CHEIO, preco: 0 } }));
        assert.ok(zero.includes('R$ 0,00'), 'zero é preço MEDIDO');
    });

    await contexto.test('o conjunto de classes da célula Preço (:254-:257)', () => {
        const html = umaLinha({ item: { ...ITEM_CHEIO, margem: 0.1234 } });
        const pos = celulasDaLinha(html, indicesDasLinhas(html)[0])[5].pos;
        assertClasses(tagEm(html, pos), ['flex', 'flex-col', 'items-end', 'gap-[2px]', 'tabular-nums'], 'célula Preço');

        const spans = tagsInternas(html, celulaPreco(html), 'span');
        assertClasses(tagEm(html, spans[0]), ['text-[13.5px]', 'font-medium'], 'o valor');
        assertClasses(tagEm(html, spans[1]), ['text-[11.5px]', 'text-pub-txt-3'], 'a margem');
    });

    await contexto.test('a moeda é pt-BR, e o negativo leva o MENOS do :488', () => {
        assert.equal(MOD_CELULAS.moedaBRL(309.9), 'R$ 309,90');
        assert.equal(MOD_CELULAS.moedaBRL(1234.5), 'R$ 1.234,50');
        assert.equal(MOD_CELULAS.moedaBRL(-1234.5), `${String.fromCharCode(0x2212)} R$ 1.234,50`);
        assert.equal(MOD_CELULAS.moedaBRL(0), 'R$ 0,00');
        // String numérica entra (o cast `decimal:2` do Eloquent serializa string).
        assert.equal(MOD_CELULAS.moedaBRL('1234.56'), 'R$ 1.234,56');
        // ⚠️ E forma inesperada NÃO vira zero.
        for (const lixo of ['', '   ', 'abacaxi', NaN, Infinity, {}, [], true, null, undefined]) {
            assert.equal(MOD_CELULAS.moedaBRL(lixo), null, `${descreve(lixo)} virou moeda`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. PREJUÍZO E MARGEM — forma + cor + texto, e a VÍRGULA decimal
//
// O que precisaria estar errado para falhar: o "Prejuízo" perder o triângulo e
// depender só de cor (o README proíbe: "avisos financeiros nunca dependem só de
// cor"), a margem sair com PONTO decimal, a unidade virar percentual em vez de
// fração, ou o triângulo mudar de cor.
// ═══════════════════════════════════════════════════════════════════════════

test('LIST-12 — Prejuízo tem forma + texto, e a margem tem vírgula', async (contexto) => {
    await contexto.test('`margem: -0.0512` → "Prejuízo" com o triângulo', () => {
        const celula = celulaPreco(umaLinha({ item: { ...ITEM_CHEIO, margem: -0.0512 } }));
        assert.ok(celula.includes('Prejuízo'), 'o texto do prejuízo não aparece');
        assert.ok(celula.includes('fill-pub-danger'), 'o prejuízo ficou só na cor do texto, sem forma');
        assert.ok(celula.includes('d="M8 1.5L15 14H1z"'), 'o triângulo do :256 mudou de forma');
        assert.ok(celula.includes('text-pub-danger'), 'o texto do prejuízo perdeu a cor semântica');
        assert.ok(!celula.includes('Margem'), 'prejuízo E margem ao mesmo tempo');
    });

    await contexto.test('`margem: 0.1234` → "Margem 12,3%", com VÍRGULA', () => {
        const celula = celulaPreco(umaLinha({ item: { ...ITEM_CHEIO, margem: 0.1234 } }));
        assert.ok(celula.includes('Margem 12,3%'), `esperava "Margem 12,3%", veio: ${textoDe(celula)}`);
        assert.ok(!celula.includes('12.3'), 'a margem saiu com ponto decimal');
        assert.ok(!celula.includes('Prejuízo'));
    });

    await contexto.test('⚠️ a UNIDADE é FRAÇÃO, não percentual', () => {
        assert.equal(MOD_CELULAS.percentualDaMargem(0.1234), '12,3%');
        assert.equal(MOD_CELULAS.percentualDaMargem(0), '0,0%');
        assert.equal(MOD_CELULAS.percentualDaMargem(-0.0512), '-5,1%');
        assert.equal(MOD_CELULAS.percentualDaMargem(1), '100,0%');
        for (const lixo of ['abacaxi', '', NaN, Infinity, {}, null, undefined, true]) {
            assert.equal(MOD_CELULAS.percentualDaMargem(lixo), null, `${descreve(lixo)} virou percentual`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. O AUTOR (LIST-13) — a tela DIZ quando não sabe
//
// O que precisaria estar errado para falhar: `iniciais()` sendo usado no autor
// desconhecido (e `Autor não registrado` virando "AN", que PARECE um nome), o
// literal do servidor virando `—`, o avatar deixando de usar `tomDoProduto`
// (IDENT-08), ou o nome de OUTRA linha vazando para esta.
// ═══════════════════════════════════════════════════════════════════════════

/** A célula Publicada (8ª coluna). */
function celulaPublicada(html) {
    return celulasDaLinha(html, indicesDasLinhas(html)[0])[7].bloco;
}

/** Os quatro literais de `PainelVisaoGeralService::QUEM_*`. */
const LITERAIS_DE_AUTOR = ['Equipe', 'Cliente', 'Origem antiga', 'Autor não registrado'];

test('LIST-13 — a célula Publicada diz quando o autor é desconhecido', async (contexto) => {
    await contexto.test('os 4 literais aparecem como TEXTO, não como placeholder', () => {
        for (const literal of LITERAIS_DE_AUTOR) {
            const html = umaLinha({ item: { ...ITEM_CHEIO, autor_nome: literal, autor_tipo: 'desconhecido' } });
            const celula = celulaPublicada(html);
            assert.ok(celula.includes(literal), `"${literal}" não chegou à tela`);
            assert.ok(
                !celula.includes(TRAVESSAO),
                `"${literal}" virou travessão — o servidor DIZ quem é, a tela não pode apagar`,
            );
        }
    });

    await contexto.test('⚠️ `autor_tipo: desconhecido` → avatar SEM letra', () => {
        const html = umaLinha({ item: { ...ITEM_CHEIO, autor_nome: 'Autor não registrado', autor_tipo: 'desconhecido' } });
        const celula = celulaPublicada(html);
        const avatar = tagEm(html, tagsInternas(html, celula, 'span')[0]);
        assert.ok(classesDa(avatar).includes('rounded-full'), 'o 1º span da célula não é o avatar');
        // `iniciais('Autor não registrado')` daria 'AN' — e 'AN' PARECE um nome.
        assert.ok(!celula.includes('>AN<'), 'as iniciais do literal viraram o avatar');
        assert.ok(celula.includes('lucide-user-round') || celula.includes('<svg'), 'o avatar ficou vazio em vez de genérico');
    });

    await contexto.test('`autor_tipo: pessoa` → as iniciais de `iniciais()` (IDENT-08)', () => {
        const celula = celulaPublicada(HTML_UMA);
        assert.ok(celula.includes('>AR<'), `esperava as iniciais AR, veio: ${textoDe(celula)}`);
        // ⚠️ E as letras vêm do helper, nunca de um campo do servidor: fixture
        // com o campo ERRADO preenchido.
        const comCampoErrado = umaLinha({ item: { ...ITEM_CHEIO, iniciais: 'ZZ' } });
        assert.ok(!celulaPublicada(comCampoErrado).includes('ZZ'), 'as iniciais viraram campo do servidor');
    });

    await contexto.test('o avatar usa `tomDoProduto` (`oklch`) e é decorativo', () => {
        const html = HTML_UMA;
        const avatar = tagEm(html, tagsInternas(html, celulaPublicada(html), 'span')[0]);
        assert.match(atributo(avatar, 'style') ?? '', /oklch\(/, 'o tom do avatar deixou de ser computado');
        assert.equal(atributo(avatar, 'aria-hidden'), 'true', 'o avatar não é decorativo e duplicaria o nome');
        assert.equal(atributo(avatar, 'title'), 'Ana Rodrigues Silva');
        assertClasses(avatar, ['grid', 'h-[24px]', 'w-[24px]', 'flex-none', 'place-items-center', 'rounded-full', 'text-[10px]', 'font-semibold'], 'o avatar');
    });

    await contexto.test('⚠️ o nome de OUTRA linha não vaza para esta', () => {
        const linhas = ESTADOS_DAS_CINCO.map(({ id }, i) => ({
            ...PROPS_BASE,
            item: { ...ITEM_CHEIO, ml_item_id: id, autor_nome: `Pessoa Numero ${i}` },
        }));
        const html = renderToStaticMarkup(React.createElement(Tabela, { linhas }));
        ESTADOS_DAS_CINCO.forEach(({ id }, i) => {
            const celula = celulasDaLinha(html, linhaDoItem(html, id))[7].bloco;
            assert.ok(celula.includes(`Pessoa Numero ${i}`), `a linha ${id} perdeu o seu autor`);
            for (let j = 0; j < 5; j += 1) {
                if (j === i) continue;
                assert.ok(!celula.includes(`Pessoa Numero ${j}`), `a linha ${id} mostrou o autor da linha ${j}`);
            }
        });
    });

    await contexto.test('`publicada_em` ausente → a data NÃO aparece (nem "—")', () => {
        const celula = celulaPublicada(umaLinha({ item: { ...ITEM_CHEIO, publicada_em: null } }));
        assert.ok(!celula.includes(TRAVESSAO));
        assert.doesNotMatch(celula, /Invalid Date|NaN/);
        assert.ok(celula.includes('Ana Rodrigues Silva'), 'o autor sumiu junto com a data');
        assert.ok(!celula.includes('Hoje'), 'sem data a tela disse "Hoje"');
    });

    await contexto.test('a data relativa é a `fmtDate()` do :492, por REGEX', () => {
        const d = MOD_CELULAS.dataRelativa;
        assert.equal(d('2026-10-13T09:05:00-03:00', HOJE), 'Hoje, 09:05');
        assert.equal(d('2026-10-12T09:10:00-03:00', HOJE), 'Ontem, 09:10');
        assert.equal(d('2026-09-28T13:08:00-03:00', HOJE), '28 set');
        assert.equal(d('2025-11-03T10:00:00-03:00', HOJE), '3 nov 2025');
        // ⚠️ `new Date('2026-10-13')` em BRT viraria o dia 12. Aqui não.
        assert.equal(d('2026-10-13', HOJE), 'Hoje');
        for (const lixo of ['abacaxi', '', '2026-99-99T99:99', 7, {}, null, undefined, NaN]) {
            assert.equal(d(lixo, HOJE), null, `${descreve(lixo)} virou data`);
        }
        // Sem referência de hoje a tela NÃO diz "Hoje".
        assert.equal(d('2026-10-13T09:05:00-03:00', null), '13 out 2026');
    });

    await contexto.test('`hoje` sai do fuso DECLARADO, não do navegador', () => {
        // 2026-10-13T02:30Z é ainda dia 12 em São Paulo (UTC−3).
        assert.equal(MOD_CELULAS.hojeEmSaoPaulo(Date.UTC(2026, 9, 13, 2, 30)), '2026-10-12');
        assert.equal(MOD_CELULAS.hojeEmSaoPaulo(Date.UTC(2026, 9, 13, 12, 0)), '2026-10-13');
        for (const lixo of [NaN, Infinity, '2026-10-13', {}, null]) {
            assert.equal(MOD_CELULAS.hojeEmSaoPaulo(lixo), null, `${descreve(lixo)} virou hoje`);
        }
    });

    await contexto.test('o conjunto de classes da célula Publicada (:264-:268)', () => {
        const pos = celulasDaLinha(HTML_UMA, indicesDasLinhas(HTML_UMA)[0])[7].pos;
        assertClasses(tagEm(HTML_UMA, pos), ['flex', 'min-w-0', 'items-center', 'gap-[8px]', 'pl-[18px]'], 'célula Publicada');

        const celula = celulaPublicada(HTML_UMA);
        const divs = tagsInternas(HTML_UMA, celula, 'div');
        assertClasses(tagEm(HTML_UMA, divs[0]), ['flex', 'min-w-0', 'flex-col', 'gap-[1px]'], 'a coluna do texto');
        const spans = tagsInternas(HTML_UMA, celula, 'span');
        assertClasses(tagEm(HTML_UMA, spans[1]), ['truncate', 'text-[12.5px]', 'font-medium'], 'a data');
        assertClasses(tagEm(HTML_UMA, spans[2]), ['truncate', 'text-[11.5px]', 'text-pub-txt-3'], 'o responsável');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. METODOLOGIA (LIST-20) — do dado real, e "Legado" por AUSÊNCIA
//
// O que precisaria estar errado para falhar: `fase` nula deixando de ser
// Legado, a metodologia sendo inferida do título, "Combo · NaN un." aparecendo,
// "Combo · 1 un." aparecendo (um combo de uma unidade não é combo), ou o glyph
// sendo REDESENHADO aqui em vez de reusado de `identidade/`.
// ═══════════════════════════════════════════════════════════════════════════

/** A célula Metodologia (3ª coluna). */
function celulaMetodologia(html) {
    return celulasDaLinha(html, indicesDasLinhas(html)[0])[2].bloco;
}

test('LIST-20 — a metodologia vem do dado real (:509)', async (contexto) => {
    await contexto.test('`fase: null` → Legado + "Fora do Publicador"', () => {
        const celula = celulaMetodologia(umaLinha({ item: { ...ITEM_CHEIO, fase: null } }));
        assert.ok(celula.includes('Legado'));
        assert.ok(celula.includes('Fora do Publicador'));
        assert.ok(celula.includes('border-dashed'), 'o glyph do Legado perdeu o tracejado');
    });

    await contexto.test('`fase` AUSENTE também é Legado', () => {
        const semFase = { ...ITEM_CHEIO };
        delete semFase.fase;
        const celula = celulaMetodologia(umaLinha({ item: semFase }));
        assert.ok(celula.includes('Legado') && celula.includes('Fora do Publicador'));
    });

    await contexto.test('`fase: 1` → "Fase 1" + "Simples · 1 un." (literais do :509)', () => {
        const celula = celulaMetodologia(umaLinha({ item: { ...ITEM_CHEIO, fase: 1 } }));
        assert.ok(celula.includes('Fase 1'));
        assert.ok(celula.includes('Simples · 1 un.'), `literal divergente: ${textoDe(celula)}`);
    });

    await contexto.test('`fase: 2, quantidade_kit: 3` → "Combo · 3 un."', () => {
        const celula = celulaMetodologia(umaLinha({ item: { ...ITEM_CHEIO, fase: 2, quantidade_kit: 3 } }));
        assert.ok(celula.includes('Fase 2'));
        assert.ok(celula.includes('Combo · 3 un.'), `literal divergente: ${textoDe(celula)}`);
    });

    await contexto.test('⚠️ `quantidade_kit` inválida → só "Combo", NUNCA NaN nem 1 un.', () => {
        for (const quantidade of [null, undefined, -1, 0, 1, NaN, Infinity, 'abacaxi', {}, [], true, 2.5]) {
            const celula = celulaMetodologia(umaLinha({ item: { ...ITEM_CHEIO, fase: 2, quantidade_kit: quantidade } }));
            const texto = textoDe(celula);
            assert.ok(texto.includes('Combo'), `perdeu o "Combo" com ${descreve(quantidade)}`);
            assert.ok(!texto.includes('un.'), `inventou unidades com ${descreve(quantidade)}: ${texto}`);
            assert.doesNotMatch(texto, /NaN|undefined/);
        }
        // E a válida (inclusive string numérica) entra.
        assert.equal(MOD_CELULAS.quantidadeDeCombo('3'), 3);
        assert.equal(MOD_CELULAS.quantidadeDeCombo(2), 2);
        assert.equal(MOD_CELULAS.quantidadeDeCombo(1), null);
    });

    await contexto.test('⚠️ `fase` em forma INESPERADA não é carimbada como Legado', () => {
        // Um `7` seria defeito do servidor; chamá-lo de "Legado" esconderia.
        assert.equal(MOD_CELULAS.faseDaLinha(7), null);
        assert.equal(MOD_CELULAS.faseDaLinha({}), null);
        assert.equal(MOD_CELULAS.faseDaLinha('legado'), null);
        assert.equal(MOD_CELULAS.faseDaLinha(null), 'legado');
        assert.equal(MOD_CELULAS.faseDaLinha(undefined), 'legado');
        assert.equal(MOD_CELULAS.faseDaLinha(1), 1);
        assert.equal(MOD_CELULAS.faseDaLinha('2'), 2);
    });

    await contexto.test('⚠️ nada é inferido do TÍTULO', () => {
        const celula = celulaMetodologia(umaLinha({
            item: { ...ITEM_CHEIO, fase: null, titulo: 'Kit 2 Combo Fase 2 Premium Catálogo' },
        }));
        assert.ok(celula.includes('Legado'), 'a metodologia foi lida do título');
        assert.ok(!celula.includes('Combo'));
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 9. O REUSO DOS COMPONENTES DE IDENTIDADE — nenhum redesenho
//
// O que precisaria estar errado para falhar: um dos 7 componentes sendo
// REDESENHADO aqui (a geometria do glyph, o losango, as formas de status, o
// badge), o `oklch(` sendo recalculado fora de `identidade.js` (IDENT-08), ou o
// import apontando para um caminho que não existe (e aí o gate mediria NADA).
// ═══════════════════════════════════════════════════════════════════════════

test('IDENT — os componentes de identidade são REUSADOS, não redesenhados', async (contexto) => {
    const DEPENDENCIAS = [
        'identidade/identidade',
        'identidade/BadgeNova',
        'identidade/RotuloDaFase',
        'identidade/SeloDeStatusDaPublicacao',
        'identidade/SeloDoTipo',
        'identidade/TileDoProduto',
    ];

    await contexto.test('os imports existem na fonte E no disco', () => {
        for (const dep of DEPENDENCIAS) {
            assert.ok(
                FONTE_CELULAS.includes(`'../${dep}'`),
                `${dep} não é importado — alguma célula está redesenhando`,
            );
            const nome = dep.split('/')[1];
            const alvo = path.resolve(RAIZ, DIR_IDENT, nome === 'identidade' ? 'identidade.js' : `${nome}.jsx`);
            assert.ok(fs.existsSync(alvo), `caminho fantasma: ${alvo}`);
        }
        // `GlyphDaFase` entra pelo `RotuloDaFase`, que já o compõe — importar os
        // dois renderizaria DOIS glyphs na coluna.
        assert.ok(!FONTE_CELULAS.includes("'../identidade/GlyphDaFase'"));
        assert.ok(
            celulaMetodologia(HTML_UMA).includes('rounded-[2.5px]'),
            'o glyph do RotuloDaFase não chegou ao HTML',
        );
    });

    await contexto.test('⚠️ nenhum dos 3 arquivos redesenha o glyph nem o `oklch`', () => {
        for (const [caminho, fonte] of FONTES) {
            assert.ok(!fonte.includes('oklch('), `${caminho} recalcula oklch — IDENT-08 só permite identidade.js`);
            assert.ok(
                !(fonte.includes('w-[15px]') && fonte.includes('h-[18px]')),
                `${caminho} redesenhou o retângulo do glyph`,
            );
            assert.ok(!fonte.includes('rotate-45'), `${caminho} redesenhou o losango do Premium`);
            assert.ok(!fonte.includes('NOVA'), `${caminho} redesenhou o badge NOVA`);
        }
    });

    await contexto.test('o tile da linha é 36px com raio 8 (:208)', () => {
        const celula = celulasDaLinha(HTML_UMA, indicesDasLinhas(HTML_UMA)[0])[1].bloco;
        const tile = tagEm(HTML_UMA, tagsInternas(HTML_UMA, celula, 'span')[0]);
        assert.match(atributo(tile, 'style') ?? '', /width:36px;height:36px;border-radius:8px/);
        assert.ok(classesDa(tile).includes('text-[11.5px]'), 'a letra do tile de 36px não é 11,5px');
        assert.ok(classesDa(tile).includes('flex-none'));
    });

    await contexto.test('a célula Tipo reusa o SeloDoTipo (:237-:242)', () => {
        const celula = celulasDaLinha(HTML_UMA, indicesDasLinhas(HTML_UMA)[0])[3].bloco;
        assert.ok(celula.includes('Premium'));
        assert.ok(celula.includes('rotate-45'), 'o losango do Premium não veio do SeloDoTipo');
        assert.ok(celula.includes('Catálogo'));
        // Tipo fora da lista fechada: sem losango e sem "Clássico".
        const desconhecido = celulasDaLinha(
            umaLinha({ item: { ...ITEM_CHEIO, tipo: 'gold' } }),
            indicesDasLinhas(umaLinha({ item: { ...ITEM_CHEIO, tipo: 'gold' } }))[0],
        )[3].bloco;
        assert.ok(!desconhecido.includes('Clássico'), 'tipo desconhecido caiu no Clássico (LIST-20)');
        assert.ok(!desconhecido.includes('rotate-45'), 'tipo desconhecido ganhou o losango de Premium');
        assert.ok(!desconhecido.includes('gold'), 'o slug cru do ML foi para a tela');
    });

    await contexto.test('a célula Status reusa o selo, com as 4 formas', () => {
        const porStatus = {
            active: ['Ativa', 'bg-pub-ok'],
            paused: ['Pausada', 'bg-pub-warn'],
            // ⚠️ REAPONTADO na Fase 186, plano 01 (VOC-01).
            // VALOR ANTIGO: 'Em revisão'. A forma e a classe não mudaram.
            under_review: ['Em análise pelo Mercado Livre', 'border-pub-neutral-dot'],
        };
        for (const [status, [rotulo, forma]] of Object.entries(porStatus)) {
            const html = umaLinha({ item: { ...ITEM_CHEIO, status } });
            const celula = celulasDaLinha(html, indicesDasLinhas(html)[0])[4].bloco;
            assert.ok(celula.includes(rotulo), `${status} deveria dizer "${rotulo}"`);
            assert.ok(celula.includes(forma), `${status} perdeu a FORMA e ficou só na cor`);
        }

        // ⚠️ VETO do rótulo antigo (Fase 186, plano 01), na célula de
        // `under_review` — a única que o usava. Positiva sozinha não pega
        // regressão: um mapa com os DOIS rótulos passaria no laço acima.
        const emAnalise = umaLinha({ item: { ...ITEM_CHEIO, status: 'under_review' } });
        assert.ok(
            !celulasDaLinha(emAnalise, indicesDasLinhas(emAnalise)[0])[4].bloco.includes('Em revisão'),
            'o rótulo antigo "Em revisão" voltou à célula Status',
        );
        // `closed` não tem chave no componente de identidade: entra como rótulo.
        const fechada = umaLinha({ item: { ...ITEM_CHEIO, status: 'closed' } });
        assert.ok(celulasDaLinha(fechada, indicesDasLinhas(fechada)[0])[4].bloco.includes('Encerrada'));
        // ⚠️ E o slug cru do ML NUNCA vai para a tela.
        for (const status of ['active', 'paused', 'under_review', 'closed']) {
            const html = umaLinha({ item: { ...ITEM_CHEIO, status } });
            const celula = celulasDaLinha(html, indicesDasLinhas(html)[0])[4].bloco;
            assert.ok(!celula.includes(status), `o slug "${status}" foi para a tela`);
        }
    });

    await contexto.test('o motivo sob o status sai do RÓTULO, nunca do slug', () => {
        const html = umaLinha();
        const celula = celulasDaLinha(html, indicesDasLinhas(html)[0])[4].bloco;
        assert.ok(celula.includes('Sem estoque'), 'o motivo traduzido não apareceu');
        assert.ok(!celula.includes('sem_estoque'), 'o SLUG do motivo foi para a tela (jargão)');

        // Sem o mapa de rótulos a 2ª linha não existe — é o que
        // `MeusAnuncios.jsx:596` já faz hoje (`if (!info) return null`).
        const semMapa = umaLinha({ rotulosDeMotivo: null });
        const celulaSemMapa = celulasDaLinha(semMapa, indicesDasLinhas(semMapa)[0])[4].bloco;
        assert.ok(!celulaSemMapa.includes('sem_estoque'));
        assert.ok(celulaSemMapa.includes('Pausada'), 'o status sumiu junto com o motivo');

        // `motivos` vazio: nenhuma linha vazia empurrando a altura.
        const semMotivo = umaLinha({ item: { ...ITEM_CHEIO, motivos: [] } });
        const celulaSemMotivo = celulasDaLinha(semMotivo, indicesDasLinhas(semMotivo)[0])[4].bloco;
        assert.ok(!celulaSemMotivo.includes('pl-[15px]'), 'nasceu uma 2ª linha vazia na Status');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 10. COPIAR O MLB (LIST-11) — os dois estados, sem depender de timer
//
// O que precisaria estar errado para falhar: o check de "copiado" perder o
// verde semântico, os dois ícones se confundirem, o botão nascer sem handler,
// ou o `stopPropagation` sumir (e aí copiar o MLB abriria o painel também).
// ═══════════════════════════════════════════════════════════════════════════

test('LIST-11 — copiar o MLB tem dois estados desenhados', async (contexto) => {
    await contexto.test('`copiada: false` → o ícone de copiar (:216)', () => {
        const celula = celulasDaLinha(HTML_UMA, indicesDasLinhas(HTML_UMA)[0])[1].bloco;
        assert.ok(celula.includes('<rect x="5" y="5" width="8.5" height="8.5" rx="1.5">'), 'o retângulo do :216 mudou');
        assert.ok(celula.includes('d="M3 10.5V3.5A1 1 0 0 1 4 2.5h6.5"'));
        assert.ok(!celula.includes('stroke-pub-ok-txt'), 'o verde de copiado apareceu sem copiar');
        // ⚠️ Medido no protótipo: este ícone tem `linejoin` e NÃO tem `linecap`.
        const svg = /<svg[^>]*stroke-width="1.6"[^>]*>/.exec(celula)[0];
        assert.equal(atributo(svg, 'stroke-linejoin'), 'round');
        assert.equal(atributo(svg, 'stroke-linecap'), null, 'o :216 não declara linecap — medido, não suposto');
    });

    await contexto.test('`copiada: true` → o check VERDE (:215)', () => {
        const html = umaLinha({ copiada: true });
        const celula = celulasDaLinha(html, indicesDasLinhas(html)[0])[1].bloco;
        assert.ok(celula.includes('stroke-pub-ok-txt'), 'o check de copiado não está no verde semântico');
        assert.ok(celula.includes('d="M3.5 8.5l3 3 6-7"'));
        assert.ok(!celula.includes('<rect'), 'os dois ícones apareceram juntos');
        const svg = /<svg[^>]*stroke-width="2.2"[^>]*>/.exec(celula)[0];
        assert.equal(atributo(svg, 'stroke-linecap'), 'round');
        assert.equal(atributo(svg, 'stroke-linejoin'), 'round');
        assert.equal(atributo(svg, 'fill'), 'none');
    });

    await contexto.test('o botão de copiar: 18×18 e NÃO desabilitado (:214)', () => {
        const celula = celulasDaLinha(HTML_UMA, indicesDasLinhas(HTML_UMA)[0])[1].bloco;
        const botao = tagEm(HTML_UMA, tagsInternas(HTML_UMA, celula, 'button')[0]);
        assertClasses(botao, [
            'grid', 'h-[18px]', 'w-[18px]', 'flex-none', 'cursor-pointer', 'place-items-center',
            'rounded-pub-badge', 'bg-transparent', 'text-pub-txt-4', 'hover:bg-pub-seg', 'hover:text-pub-txt',
        ], 'o botão de copiar');
        assert.equal(atributo(botao, 'title'), 'Copiar MLB');
        assert.doesNotMatch(botao, /disabled=/);
    });

    await contexto.test('⚠️ o clique interno PARA de subir (`stopPropagation`)', () => {
        assert.match(FONTE_CELULAS, /evento\.stopPropagation\(\)/);
        assert.match(FONTE_LINHA, /evento\.stopPropagation\(\)/);
        // Três lugares precisam dele: copiar, abrir no ML e abrir rascunho.
        assert.ok(
            (FONTE_CELULAS.match(/stopPropagation/g) ?? []).length >= 3,
            'faltou `stopPropagation` em alguma ação interna — ela abriria o painel também',
        );
    });

    await contexto.test('o temporizador de 1,3 s NÃO mora aqui', () => {
        for (const [caminho, fonte] of FONTES) {
            assert.ok(!fonte.includes('setTimeout'), `${caminho} criou timer — o 1,3 s é da PÁGINA (:557)`);
            assert.ok(!fonte.includes('1300'), `${caminho} cravou o 1300`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 11. AÇÕES (LIST-14 / LIST-23 / EXMP-01) — nenhum botão sem destino
//
// O que precisaria estar errado para falhar: o botão "abrir no ML" sendo
// desenhado com `permalink` nulo (ou com `7`, ou com `javascript:`), o
// `rel="noreferrer"` caindo (e aí a página do ML teria `window.opener`), ou o
// atalho "Abrir rascunho" DESAPARECENDO, que é LIST-23.
// ═══════════════════════════════════════════════════════════════════════════

/** A célula de ações (9ª coluna). */
function celulaAcoes(html) {
    return celulasDaLinha(html, indicesDasLinhas(html)[0])[8].bloco;
}

test('EXMP-01 — nenhum botão sem destino na célula de ações', async (contexto) => {
    await contexto.test('⚠️ `permalink` nulo → o botão NÃO é desenhado, com o item CHEIO', () => {
        const html = umaLinha({ item: { ...ITEM_CHEIO, permalink: null } });
        const celula = celulaAcoes(html);
        assert.ok(!celula.includes('Abrir no Mercado Livre'), 'o botão nasceu sem destino');
        assert.ok(!celula.includes('href="#"'), 'apareceu um href fantasma');
        // ⚠️ E a negativa não é vazia: com o permalink real ele ESTÁ lá.
        assert.ok(celulaAcoes(HTML_UMA).includes('Abrir no Mercado Livre'), 'a isca falhou');
        // E o resto da linha continua inteiro.
        assert.ok(html.includes('R$ 309,90') && html.includes('Ana Rodrigues Silva'));
    });

    await contexto.test('⚠️ `permalink` em forma inesperada também não vira href', () => {
        for (const lixo of [7, '', '   ', 'abacaxi', 'javascript:alert(1)', 'ftp://x', {}, [], true, NaN]) {
            const html = umaLinha({ item: { ...ITEM_CHEIO, permalink: lixo } });
            assert.ok(
                !celulaAcoes(html).includes('Abrir no Mercado Livre'),
                `${descreve(lixo)} virou destino do botão`,
            );
        }
        assert.equal(MOD_CELULAS.urlSegura('https://x.com/a'), 'https://x.com/a');
        assert.equal(MOD_CELULAS.urlSegura('http://x.com/a'), 'http://x.com/a');
        assert.equal(MOD_CELULAS.urlSegura('javascript:alert(1)'), null);
    });

    await contexto.test('o link do ML: 28×28, `target` e `rel="noreferrer"` (:272)', () => {
        const celula = celulaAcoes(HTML_UMA);
        const link = tagEm(HTML_UMA, tagsInternas(HTML_UMA, celula, 'a')[0]);
        assertClasses(link, [
            'grid', 'h-[28px]', 'w-[28px]', 'place-items-center', 'rounded-pub-control-sm',
            'text-pub-txt-3', 'hover:bg-pub-seg', 'hover:text-pub-txt',
        ], 'o link do ML');
        assert.equal(atributo(link, 'target'), '_blank');
        assert.equal(
            atributo(link, 'rel'),
            'noreferrer',
            'sem `rel=noreferrer` a aba do ML ganha `window.opener` (T-180-08-T)',
        );
        assert.equal(atributo(link, 'title'), 'Abrir no Mercado Livre');
        assert.equal(atributo(link, 'aria-label'), 'Abrir no Mercado Livre');
        const svg = /<svg[^>]*>/.exec(celula)[0];
        assert.equal(atributo(svg, 'width'), '14');
        assert.equal(atributo(svg, 'viewBox'), '0 0 16 16');
        assert.equal(atributo(svg, 'stroke-width'), '1.6');
        assert.equal(atributo(svg, 'fill'), 'none');
        assert.equal(atributo(svg, 'stroke'), 'currentColor');
    });

    await contexto.test('⚠️ LIST-23: "Abrir rascunho" FICA, e o chevron é que cede', () => {
        const comRascunho = celulaAcoes(HTML_UMA);
        assert.ok(comRascunho.includes('Abrir rascunho'), 'LIST-23 quebrado: o atalho desapareceu');
        assert.ok(!comRascunho.includes('d="M6 4l4 4-4 4"'), 'o chevron não cedeu e são 3 slots em 64px');
        assert.equal(tagsInternas(HTML_UMA, comRascunho, 'a').length, 2, 'a célula tem mais de 2 destinos');

        const semRascunho = celulaAcoes(umaLinha({ item: { ...ITEM_CHEIO, origem: 'ml' } }));
        assert.ok(!semRascunho.includes('Abrir rascunho'));
        assert.ok(semRascunho.includes('d="M6 4l4 4-4 4"'), 'sem rascunho o chevron deveria aparecer');
    });

    await contexto.test('"Abrir rascunho" exige origem, rascunho E destino', () => {
        const casos = [
            [{ origem: 'ml', rascunho_id: 31 }, '/x', false],
            [{ origem: 'ecf', rascunho_id: null }, '/x', false],
            [{ origem: 'ecf', rascunho_id: 31 }, null, false],
            [{ origem: 'ecf', rascunho_id: 31 }, '', false],
            [{ origem: 'ecf', rascunho_id: 31 }, 7, false],
            [{ origem: 'ecf', rascunho_id: 31 }, '/x', true],
        ];
        for (const [patch, url, esperado] of casos) {
            const html = umaLinha({ item: { ...ITEM_CHEIO, ...patch }, urlDoRascunho: url });
            assert.equal(
                celulaAcoes(html).includes('Abrir rascunho'),
                esperado,
                `origem=${patch.origem} rascunho=${patch.rascunho_id} url=${descreve(url)}`,
            );
        }
    });

    await contexto.test('o chevron é um `<span>` NÃO interativo (:274)', () => {
        const celula = celulaAcoes(umaLinha({ item: { ...ITEM_CHEIO, origem: 'ml' } }));
        assert.ok(!celula.includes('<button'), 'o chevron virou botão e promete o que não faz');
        const html = umaLinha({ item: { ...ITEM_CHEIO, origem: 'ml' } });
        const spans = tagsInternas(html, celulaAcoes(html), 'span');
        assert.equal(atributo(tagEm(html, spans[0]), 'aria-hidden'), 'true');
    });

    await contexto.test('o conjunto de classes da célula de ações (:271)', () => {
        const pos = celulasDaLinha(HTML_UMA, indicesDasLinhas(HTML_UMA)[0])[8].pos;
        assertClasses(tagEm(HTML_UMA, pos), ['flex', 'items-center', 'justify-end', 'gap-[2px]'], 'célula de ações');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 12. A NOTA ECF "DE 86" (D-22 / LIST-24 / critério 15)
//
// O que precisaria estar errado para falhar: o literal "de 86" virando
// "de 100", a nota sendo RENORMALIZADA (`/ 100`, `* 100 / 86`), a informação
// morando SÓ no `title` (inacessível para teclado e leitor de tela, que
// IDENT-07 proíbe), ou a nota ausente virando "0 de 86".
// ═══════════════════════════════════════════════════════════════════════════

test('D-22 — a Nota ECF continua "de 86" e nunca é renormalizada', async (contexto) => {
    await contexto.test('o literal `de 86` existe na célula Status', () => {
        const html = HTML_UMA;
        const celula = celulasDaLinha(html, indicesDasLinhas(html)[0])[4];
        assert.ok(celula.bloco.includes('de 86'), 'o literal "de 86" saiu da tela');
        assert.ok(celula.bloco.includes('Nota ECF 72 de 86'));
        assert.equal(atributo(tagEm(html, celula.pos), 'title'), 'Nota ECF 72 de 86');
    });

    await contexto.test('⚠️ a nota NÃO mora só no `title` (IDENT-07)', () => {
        const html = HTML_UMA;
        const celula = celulasDaLinha(html, indicesDasLinhas(html)[0])[4].bloco;
        // Texto de VERDADE no DOM, em `sr-only`: é lido em ACRÉSCIMO ao status.
        assert.match(celula, /<span class="sr-only">Nota ECF 72 de 86<\/span>/);
        // ⚠️ E NÃO por `aria-label`, que SUBSTITUIRIA "Pausada" e o motivo.
        assert.equal(atributo(tagEm(html, celulasDaLinha(html, indicesDasLinhas(html)[0])[4].pos), 'aria-label'), null);
        assert.ok(celula.includes('Pausada'), 'o status continua legível');
    });

    await contexto.test('⚠️ nenhuma renormalização para 100', () => {
        for (const [caminho, fonte] of FONTES) {
            assert.ok(!fonte.includes('de 100'), `${caminho} escreveu "de 100"`);
            // ⚠️ `!fonte.includes('/ 100')` era VAZIO AO CONTRÁRIO: casa dentro
            // de `Math.floor(anoDaEra / 100)`, o algoritmo de dias civis, e
            // **reprovava o código certo**. A negativa é ancorada na BASE 86.
            assert.doesNotMatch(fonte, /[*/]\s*86\b/, `${caminho} divide ou multiplica pela base 86`);
            assert.doesNotMatch(fonte, /\b86\s*[*/]/, `${caminho} divide ou multiplica pela base 86`);
            assert.doesNotMatch(fonte, /100\s*\)?\s*\/\s*86/, `${caminho} renormalizou a nota para 100`);
        }
        assert.ok(FONTE_CELULAS.includes('de 86'), 'o literal saiu da fonte');
    });

    await contexto.test('nota ausente DIZ que não foi avaliada (nunca "0 de 86")', () => {
        assert.equal(MOD_CELULAS.textoDaNotaEcf(72), 'Nota ECF 72 de 86');
        assert.equal(MOD_CELULAS.textoDaNotaEcf(0), 'Nota ECF 0 de 86');
        for (const vazio of [null, undefined, NaN, 'abacaxi', {}, '']) {
            assert.equal(
                MOD_CELULAS.textoDaNotaEcf(vazio),
                'Nota ECF ainda não avaliada',
                `${descreve(vazio)} virou nota`,
            );
        }
        const html = umaLinha({ item: { ...ITEM_CHEIO, nota_ecf: null } });
        const celula = celulasDaLinha(html, indicesDasLinhas(html)[0])[4].bloco;
        assert.ok(celula.includes('ainda não avaliada'));
        assert.ok(!celula.includes('0 de 86'));
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 13. VENDAS — zero MEDIDO contra ausência
//
// O que precisaria estar errado para falhar: `vendas: null` virando "0" (e aí
// "nunca medimos" ficaria igual a "medimos e é zero"), `vendas: 0` perdendo o
// cinza de desabilitado, ou a 2ª linha nascendo vazia sem visitas.
// ═══════════════════════════════════════════════════════════════════════════

test('A célula Vendas separa zero MEDIDO de ausência', async (contexto) => {
    const celulaVendas = (html) => celulasDaLinha(html, indicesDasLinhas(html)[0])[6].bloco;

    await contexto.test('`vendas: 4` → 4, e `visitas` → "1.904 vis."', () => {
        const celula = celulaVendas(HTML_UMA);
        assert.ok(celula.includes('>4<'));
        assert.ok(celula.includes('1.904 vis.'), `esperava o separador de milhar: ${textoDe(celula)}`);
    });

    await contexto.test('`vendas: 0` → `0` em `pub-txt-off`', () => {
        const html = umaLinha({ item: { ...ITEM_CHEIO, vendas: 0 } });
        const celula = celulaVendas(html);
        assert.ok(celula.includes('>0<'));
        assert.ok(celula.includes('text-pub-txt-off'), 'zero medido perdeu o cinza de desabilitado');
    });

    await contexto.test('⚠️ `vendas: null` → NADA (não "0")', () => {
        const html = umaLinha({ item: { ...ITEM_CHEIO, vendas: null } });
        const celula = celulaVendas(html);
        assert.ok(!celula.includes('>0<'), '"nunca medimos" virou "medimos e é zero"');
        assert.ok(celula.includes('1.904 vis.'), 'as visitas sumiram junto');
    });

    await contexto.test('`visitas: null` → a 2ª linha não aparece', () => {
        const html = umaLinha({ item: { ...ITEM_CHEIO, visitas: null } });
        const celula = celulaVendas(html);
        assert.ok(!celula.includes('vis.'));
        assert.ok(celula.includes('>4<'), 'as vendas sumiram junto');
    });

    await contexto.test('o conjunto de classes (:260-:262)', () => {
        const pos = celulasDaLinha(HTML_UMA, indicesDasLinhas(HTML_UMA)[0])[6].pos;
        assertClasses(tagEm(HTML_UMA, pos), ['flex', 'flex-col', 'items-end', 'gap-[2px]', 'tabular-nums'], 'célula Vendas');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 14. A CÉLULA PUBLICAÇÃO — e a visão agrupada
//
// O que precisaria estar errado para falhar: a visão "Por produto" mostrando o
// tile e o nome do produto (que o cabeçalho do grupo já diz), o recuo de 30px
// caindo, o título perdendo o `truncate` (e aí um título de 60 caracteres
// estouraria a coluna), ou o `·` separador aparecendo sem nome depois dele.
// ═══════════════════════════════════════════════════════════════════════════

test('FID — a célula Publicação, em Lista e Por produto (:206-:218)', async (contexto) => {
    const celulaPublicacao = (html) => celulasDaLinha(html, indicesDasLinhas(html)[0])[1];

    await contexto.test('Lista: recuo 0, tile e nome do produto', () => {
        const celula = celulaPublicacao(HTML_UMA);
        assertClasses(tagEm(HTML_UMA, celula.pos), ['flex', 'min-w-0', 'items-center', 'gap-[12px]', 'pr-[16px]', 'pl-0'], 'célula Publicação (lista)');
        assert.ok(celula.bloco.includes('Fone Bluetooth XYZ'));
        assert.match(celula.bloco, /oklch\(/, 'o tile não apareceu na visão Lista');
    });

    await contexto.test('Por produto: recuo 30px, SEM tile e SEM nome', () => {
        const celula = celulaPublicacao(HTML_AGRUPADA);
        assertClasses(tagEm(HTML_AGRUPADA, celula.pos), ['flex', 'min-w-0', 'items-center', 'gap-[12px]', 'pr-[16px]', 'pl-[30px]'], 'célula Publicação (agrupada)');
        assert.ok(!celula.bloco.includes('Fone Bluetooth XYZ'), 'o nome do produto repetiu dentro do grupo');
        assert.ok(!celula.bloco.includes('oklch('), 'o tile repetiu dentro do grupo');
        // E o resto da linha continua inteiro.
        assert.ok(celula.bloco.includes('MLB4402230188'));
    });

    await contexto.test('título, meta e MLB (:210-:213)', () => {
        const celula = celulaPublicacao(HTML_UMA).bloco;
        const divs = tagsInternas(HTML_UMA, celula, 'div');
        assertClasses(tagEm(HTML_UMA, divs[0]), ['flex', 'min-w-0', 'flex-col', 'gap-[3px]'], 'a coluna do título');
        const spans = tagsInternas(HTML_UMA, celula, 'span');
        assertClasses(tagEm(HTML_UMA, spans[1]), ['truncate', 'text-[13.5px]', 'font-medium'], 'o título');
        assertClasses(tagEm(HTML_UMA, spans[2]), ['flex', 'min-w-0', 'items-center', 'gap-[6px]', 'overflow-hidden', 'text-[11.5px]', 'text-pub-txt-3'], 'a meta');
        assertClasses(tagEm(HTML_UMA, spans[3]), ['font-pub-mono', 'flex-none'], 'o MLB');
    });

    await contexto.test('o `·` só existe COM algo depois dele (:218) — contagem EXATA nas 4 combinações', () => {
        // ⚠️⚠️ REAPONTE DE VALOR (plano 180-10B), e é ENDURECIMENTO.
        //
        // VALOR ANTIGO:
        //   assert.ok(comNome.includes('text-pub-txt-off'));
        //   assert.ok(!semNome.includes('text-pub-txt-off'));
        // POR QUE MUDOU: o SKU por variação voltou à linha (decisão do usuário
        // de 14/10/2026) e ele tem o SEU separador, com a MESMA classe do
        // `:218`. A negativa por SUBSTRING passaria a reprovar o código CERTO
        // (sem nome, mas com SKU, o separador do SKU existe e DEVE existir).
        // VALOR NOVO: a CONTAGEM exata nas quatro combinações nome × SKU — que
        // prova mais do que a antiga: ela pega separador a MAIS e a MENOS, e
        // pega os dois casos novos que a antiga não via.
        const separadores = (bloco) => (bloco.match(/text-pub-txt-off/g) ?? []).length;

        const comNomeComSku = celulaPublicacao(HTML_UMA).bloco;
        const semNomeComSku = celulaPublicacao(umaLinha({ item: { ...ITEM_CHEIO, produto_nome: null } })).bloco;
        const comNomeSemSku = celulaPublicacao(umaLinha({ item: { ...ITEM_CHEIO, skus: [] } })).bloco;
        const semNomeSemSku = celulaPublicacao(
            umaLinha({ item: { ...ITEM_CHEIO, produto_nome: null, skus: [] } }),
        ).bloco;

        assert.equal(separadores(comNomeComSku), 2, 'nome + SKU: são DOIS separadores (um antes de cada)');
        assert.equal(separadores(semNomeComSku), 1, 'só SKU: UM separador — e ele não é órfão, tem o SKU depois');
        assert.equal(separadores(comNomeSemSku), 1, 'só nome: UM separador, como no :218');
        assert.equal(separadores(semNomeSemSku), 0, 'sem nome e sem SKU: o separador ficaria órfão — não pode existir');
    });

    await contexto.test('`nova: true` → o BadgeNova; `false` → nada', () => {
        const com = celulaPublicacao(umaLinha({ item: { ...ITEM_CHEIO, nova: true } })).bloco;
        assert.ok(com.includes('NOVA'));
        assert.ok(com.includes('bg-pub-accent'));
        assert.ok(!celulaPublicacao(HTML_UMA).bloco.includes('NOVA'));
    });

    await contexto.test('⚠️ `ml_item_id` ausente → nem MLB nem botão de copiar', () => {
        // ⚠️⚠️ REAPONTE DE VALOR (plano 180-10B), e é ENDURECIMENTO.
        //
        // VALOR ANTIGO: `assert.ok(!celula.includes('font-pub-mono'))`.
        // POR QUE MUDOU: `font-pub-mono` era PROXY do MLB — e deixou de ser
        // exclusivo dele quando o SKU por variação voltou à linha (decisão do
        // usuário de 14/10/2026), porque o SKU usa a MESMA fonte mono, que é
        // o que o protótipo mede nos dois lugares em que desenha SKU (`:178`
        // e `:382`). A negativa antiga reprovaria o código CERTO.
        // VALOR NOVO: o LITERAL do MLB (que é exclusivo dele) + a CONTAGEM de
        // mono — que prova mais: ela pega o MLB reaparecendo com outra classe.
        const celula = celulaPublicacao(umaLinha({ item: { ...ITEM_CHEIO, ml_item_id: null } })).bloco;
        assert.ok(!celula.includes('MLB4402230188'), 'o MLB foi desenhado sem `ml_item_id`');
        assert.ok(!celula.includes('Copiar MLB'), 'sobrou um botão de copiar sem nada para copiar');
        assert.equal(
            (celula.match(/font-pub-mono/g) ?? []).length,
            1,
            'sem MLB sobra UM mono: o do SKU. Dois significa que o MLB voltou por outro caminho',
        );
        // E sem MLB E sem SKU não sobra mono nenhum — é a ISCA que prova que a
        // contagem de cima não é 1 por construção.
        const semOsDois = celulaPublicacao(
            umaLinha({ item: { ...ITEM_CHEIO, ml_item_id: null, skus: [] } }),
        ).bloco;
        assert.equal((semOsDois.match(/font-pub-mono/g) ?? []).length, 0);
        // E o título continua.
        assert.ok(celula.includes('Kit 2 Fones'));
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 14b. ⚠️ O SKU POR VARIAÇÃO VOLTOU À LINHA (plano 180-10B)
//
// DECISÃO DO USUÁRIO, 14/10/2026 (checkpoint do plano 180-10). Aquele plano
// declarou a perda: ao trocar a tabela antiga pelo layout novo, o SKU por
// variação saiu da linha. Ele pediu de volta.
//
// ⚠️ É DIVERGÊNCIA DO PROTÓTIPO, AUTORIZADA: `Publicacoes v2.dc.html` NÃO
// desenha SKU na linha da publicação (medido por `grep -ni sku`: `:70` e `:288`
// são textos da busca, `:178` é a linha de GRUPO e `:382` é o painel de 460px).
// ➡️ Mas a TIPOGRAFIA é a dele, dos dois lugares em que ele desenha SKU:
// Geist Mono + 11.5px + `#8A8A90`. Como a linha meta (`:212`) JÁ é
// `text-[11.5px] text-pub-txt-3`, o pedaço não redeclara tamanho nem cor —
// exatamente o que o MLB de `:213` faz.
//
// O que precisaria estar errado para este bloco falhar: o pedaço do SKU sair
// da célula; aparecer sem SKU (um `—`, uma string vazia, um separador órfão);
// perder o mono; mostrar o SKU do PRODUTO no lugar do da variação; inventar
// `+N` com um SKU só; contar duplicata como variação; ou deslocar os índices
// absolutos do título/meta/MLB que o bloco 14 trava.
// ═══════════════════════════════════════════════════════════════════════════

test('180-10B — o SKU por VARIAÇÃO na linha: de onde vem, como aparece, e quando NÃO aparece', async (contexto) => {
    const celulaPub = (html) => celulasDaLinha(html, indicesDasLinhas(html)[0])[1];
    const spansDa = (html, bloco) => tagsInternas(html, bloco, 'span');

    await contexto.test('a FONTE é `item.skus` (a lista por variação), nunca `produto_sku`', () => {
        // ⚠️ O fixture tem os DOIS, com valores DIFERENTES: `produto_sku` é
        // 'FON-001' e `skus` é ['FON-001-P','FON-001-G']. Sem essa distinção o
        // gate passaria com a célula mostrando o SKU do PRODUTO, que é o dado
        // da linha de GRUPO e seria mentir sobre a variação anunciada.
        assert.equal(ITEM_CHEIO.produto_sku, 'FON-001');
        const celula = celulaPub(HTML_UMA).bloco;
        assert.ok(celula.includes('FON-001-P'), 'o SKU da variação não apareceu na linha');
        assert.ok(!celula.includes('>FON-001<'), 'a linha mostrou o SKU do PRODUTO no lugar do da variação');
        // E a célula de GRUPO continua sendo a casa do SKU do produto — a prova
        // é que este arquivo não desenha `produto_sku` em lugar nenhum.
        assert.ok(!FONTE_CELULAS.includes('produto_sku'),
            'a célula passou a ler `produto_sku`: o SKU do produto é da linha de grupo (LinhaDeGrupoDeProduto)');
    });

    await contexto.test('as classes do pedaço, por igualdade de conjunto e por ÁRVORE', () => {
        // ⚠️ Localização por ÍNDICE ABSOLUTO de `<span>` dentro da célula, e a
        // CONTAGEM travada: inserir elemento no meio deslocaria o título, a
        // meta e o MLB (spans[1]/[2]/[3] do bloco 14) — o pedaço do SKU vai no
        // FIM de propósito, e esta contagem é o que prova que ele ficou lá.
        const celula = celulaPub(HTML_UMA).bloco;
        const spans = spansDa(HTML_UMA, celula);
        assert.equal(spans.length, 9,
            'a contagem de <span> da célula mudou: tile, título, meta, MLB, separador+nome, separador+SKU, mono');

        assertClasses(tagEm(HTML_UMA, spans[6]), ['flex-none', 'text-pub-txt-off'], 'o separador do SKU (:218)');
        assertClasses(tagEm(HTML_UMA, spans[7]), ['flex-none'], 'o pedaço do SKU');
        assertClasses(tagEm(HTML_UMA, spans[8]), ['font-pub-mono'], 'o código do SKU (Geist Mono, :178/:382)');

        // ⚠️ O pedaço NÃO redeclara tamanho nem cor: herda os 11.5px e o
        // `#8A8A90` da meta, como o MLB de `:213`. Redeclarar seria a
        // adaptação "por segurança" que o usuário recusou (FID-02).
        for (const vetada of ['text-[11.5px]', 'text-pub-txt-3', 'text-pub-neutral-txt']) {
            assert.ok(!classesDa(tagEm(HTML_UMA, spans[7])).includes(vetada), `o pedaço redeclarou ${vetada}`);
            assert.ok(!classesDa(tagEm(HTML_UMA, spans[8])).includes(vetada), `o código redeclarou ${vetada}`);
        }
        // E o `flex-none` é o do MLB: quem encolhe é o nome do produto, não o
        // SKU que o usuário pediu para VER.
        assert.ok(classesDa(tagEm(HTML_UMA, spans[3])).includes('flex-none'));
    });

    await contexto.test('o rótulo "SKU" + o código + o `+N` das outras variações', () => {
        const celula = celulaPub(HTML_UMA).bloco;
        assert.match(celula, /SKU <span class="font-pub-mono">FON-001-P<\/span> \+1/,
            'a forma do pedaço mudou: é o rótulo "SKU", o PRIMEIRO código em mono e o "+N" das outras');
        // ⚠️ O rótulo existe porque código nu ao lado do MLB é jargão (regra
        // permanente do projeto) — e é o que o `CelulaSku` de hoje já escrevia.
        // O protótipo mostra o código cru, mas num contexto onde a COLUNA diz
        // que aquilo é SKU; aqui não há coluna. Divergência declarada.
        assert.ok(celula.includes('SKU '), 'o rótulo "SKU" saiu — o código ficaria nu ao lado do MLB');
    });

    await contexto.test('UM SKU só → NENHUM `+N` (e duplicata não conta como variação)', () => {
        const um = celulaPub(umaLinha({ item: { ...ITEM_CHEIO, skus: ['SO-UM'] } })).bloco;
        assert.match(um, /SKU <span class="font-pub-mono">SO-UM<\/span><\/span>/, 'o pedaço mudou de forma com 1 SKU');
        assert.ok(!um.includes('+1'), 'um SKU só ganhou "+1" — seria afirmar uma variação que não existe');

        const duplicado = celulaPub(umaLinha({ item: { ...ITEM_CHEIO, skus: ['A-1', 'A-1', ' A-1 '] } })).bloco;
        assert.ok(!duplicado.includes('+1'), 'três vezes o MESMO SKU viraram "+2" — o dedup caiu');
        assert.ok(!duplicado.includes('+2'));
        assert.match(duplicado, /SKU <span class="font-pub-mono">A-1<\/span><\/span>/);
    });

    await contexto.test('⚠️ SEM SKU: NADA é desenhado — nem "—", nem vazio, nem separador solto', () => {
        // "nunca medimos" ≠ "medimos e é zero" (EXMP-01). O controller colapsa
        // `null` em `[]` (`MlbAnuncioController.php:740`); quem distingue
        // "ainda não coletado" é a prop `skuNaoColetado`, que vive na tela.
        const SEM = [
            ['lista vazia', []],
            ['nula', null],
            ['string em vez de lista', 'FON-001'],
            ['objeto', {}],
            ['só sujeira', ['', '   ', {}, 7, null, true, [], { toString: null }]],
        ];
        for (const [nome, valor] of SEM) {
            const celula = celulaPub(umaLinha({ item: { ...ITEM_CHEIO, skus: valor } })).bloco;
            assert.ok(!celula.includes('SKU '), `${nome}: o rótulo "SKU" apareceu sem SKU nenhum`);
            assert.ok(!celula.includes('font-pub-mono">—'), `${nome}: a célula inventou um "—" de SKU`);
            assert.doesNotMatch(celula, RE_SUJEIRA, `${nome}: sujou a tela`);
            assert.equal(
                (celula.match(/text-pub-txt-off/g) ?? []).length,
                1,
                `${nome}: sobrou (ou faltou) separador — com nome e sem SKU é UM`,
            );
            // E o resto da linha continua inteiro.
            assert.ok(celula.includes('MLB4402230188'), `${nome}: o MLB sumiu junto`);
            assert.ok(celula.includes('Fone Bluetooth XYZ'), `${nome}: o nome do produto sumiu junto`);
        }

        // A chave AUSENTE também.
        const semChave = { ...ITEM_CHEIO };
        delete semChave.skus;
        assert.ok(!celulaPub(umaLinha({ item: semChave })).bloco.includes('SKU '));

        // ⚠️ ISCA: o detector acima não é cego — com SKU de verdade ele ACHA.
        assert.ok(celulaPub(HTML_UMA).bloco.includes('SKU '), 'a sonda do rótulo está cega');
    });

    await contexto.test('lista SUJA: só as strings não vazias entram, na ordem', () => {
        const celula = celulaPub(umaLinha({
            item: { ...ITEM_CHEIO, skus: ['', '   ', {}, 7, null, { toString: null }, 'FON-9', ['x'], 'FON-10'] },
        })).bloco;
        assert.match(celula, /SKU <span class="font-pub-mono">FON-9<\/span> \+1/,
            'a lista suja não foi saneada para as DUAS strings válidas, na ordem');
        assert.doesNotMatch(celula, RE_SUJEIRA);
        assert.ok(!celula.includes('+7'), 'os valores inválidos entraram na contagem de variações');
    });

    await contexto.test('o `title` diz a lista INTEIRA (o contrato do CelulaSku de hoje)', () => {
        const spans = spansDa(HTML_UMA, celulaPub(HTML_UMA).bloco);
        assert.equal(
            atributo(tagEm(HTML_UMA, spans[7]), 'title'),
            '2 SKUs (um por variação): FON-001-P, FON-001-G',
            'o title perdeu a lista inteira — com variações, mostrar um só sem dizer os outros mentiria',
        );
        const umHtml = umaLinha({ item: { ...ITEM_CHEIO, skus: ['SO-UM'] } });
        const umSpans = spansDa(umHtml, celulaPub(umHtml).bloco);
        assert.equal(atributo(tagEm(umHtml, umSpans[7]), 'title'), 'SKU SO-UM');
    });

    await contexto.test('na visão "Por produto" o SKU da VARIAÇÃO aparece — o do produto é do grupo', () => {
        const celula = celulaPub(HTML_AGRUPADA).bloco;
        assert.ok(celula.includes('FON-001-P'),
            'o SKU da variação sumiu na visão agrupada. A linha de grupo diz o SKU do PRODUTO, que é outro dado');
        // E o que o grupo JÁ diz continua fora da linha (bloco 14).
        assert.ok(!celula.includes('Fone Bluetooth XYZ'), 'o nome do produto voltou a repetir dentro do grupo');
        const spans = spansDa(HTML_AGRUPADA, celula);
        assert.equal(spans.length, 6, 'agrupada: título, meta, MLB, separador, SKU e mono — sem tile e sem nome');
        assertClasses(tagEm(HTML_AGRUPADA, spans[5]), ['font-pub-mono'], 'o código do SKU na visão agrupada');
    });

    await contexto.test('`skuDaVariacao()` como função pura, nas formas adversas', () => {
        const f = MOD_CELULAS.skuDaVariacao;
        assert.deepEqual(f(['A', 'B']), { primeiro: 'A', outros: 1, lista: ['A', 'B'] });
        assert.deepEqual(f([' A ']), { primeiro: 'A', outros: 0, lista: ['A'] });
        assert.equal(f([]), null);
        assert.equal(f(null), null);
        assert.equal(f(undefined), null);
        assert.equal(f('A'), null, 'string não é lista — o servidor manda array (cast `array` do model)');
        assert.equal(f({ 0: 'A', length: 1 }), null, 'array-like não é array');
        assert.equal(f(['', '  ']), null);
        for (const [nome, valor] of FORMAS_ADVERSAS) {
            assert.doesNotThrow(() => f(valor), `skuDaVariacao(${nome}) lançou`);
            assert.doesNotThrow(() => f([valor]), `skuDaVariacao([${nome}]) lançou`);
            assert.doesNotThrow(() => MOD_CELULAS.tituloDoSku(f([valor])), `tituloDoSku com ${nome} lançou`);
        }
        assert.equal(MOD_CELULAS.tituloDoSku(null), undefined, 'sem SKU não há title — nem vazio');
    });

    await contexto.test('⚠️ o SKU aparece UMA vez: a página não monta o `CelulaSku` órfão', () => {
        // `CelulaSku` continua EXPORTADO de `MeusAnuncios.jsx` (órfão declarado
        // pelo plano 180-10, com gate próprio em
        // `publicador-meus-anuncios-sku-render.test.js`). Se alguém o montasse
        // agora, o SKU apareceria DUAS vezes na mesma linha.
        const pagina = lerSemComentarios('resources/js/Pages/Mlb/MeusAnuncios.jsx');
        assert.match(pagina, /export function CelulaSku/, 'o export órfão foi apagado — ele tem gate próprio');
        assert.doesNotMatch(pagina, /<CelulaSku/,
            'a página montou o `CelulaSku` órfão: o SKU apareceria DUAS vezes na linha (o do componente novo e o dele)');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 15. D-11 / REND-04 — a linha NÃO escreve no Mercado Livre
//
// O que precisaria estar errado para falhar: um `router.put/delete/patch/post`
// nascendo num dos 3 arquivos, um `axios`/`fetch`, um `new Date(`, um `.map()`
// com JSX inline (o buraco do Rollup), `String(`/`JSON.stringify` num valor
// adverso, ou um pacote novo.
// ═══════════════════════════════════════════════════════════════════════════

test('D-11 — zero escrita no ML e zero armadilha de render', async (contexto) => {
    await contexto.test('⚠️ nenhum `router.put/delete/patch/post`', () => {
        for (const [caminho, fonte] of FONTES) {
            for (const verbo of ['router.put(', 'router.delete(', 'router.patch(', 'router.post(']) {
                assert.ok(!fonte.includes(verbo), `${caminho} escreve no ML com ${verbo} — D-11`);
            }
            assert.ok(!fonte.includes('router.'), `${caminho} usa router — as ações são callbacks locais`);
            assert.ok(!fonte.includes('axios'), `${caminho} usa axios`);
            assert.ok(!fonte.includes('fetch('), `${caminho} usa fetch`);
            assert.ok(!fonte.includes('route('), `${caminho} monta rota — quem monta é a página`);
        }
    });

    await contexto.test('⚠️ nenhum `new Date(`', () => {
        for (const [caminho, fonte] of FONTES) {
            assert.ok(!fonte.includes('new Date('), `${caminho} usa new Date — fuso e Invalid Date`);
        }
        // E o `Intl` com fuso DECLARADO está lá.
        assert.match(FONTE_CELULAS, /timeZone: 'America\/Sao_Paulo'/);
    });

    await contexto.test('⚠️ nenhum `.map()` nos 3 arquivos (o buraco do Rollup)', () => {
        for (const [caminho, fonte] of FONTES) {
            assert.ok(
                !fonte.includes('.map('),
                `${caminho} ganhou um \`.map()\`: o Rollup elimina variável de escopo do componente`
                + ' usada dentro do callback (ReferenceError só no bundle). Se precisar mapear, TODA'
                + ' flag nasce DENTRO do callback — releia o learnings §7.',
            );
        }
    });

    await contexto.test('⚠️ nenhum `String(`/`JSON.stringify` em valor adverso', () => {
        for (const [caminho, fonte] of FONTES) {
            assert.ok(!fonte.includes('JSON.stringify'), `${caminho} usa JSON.stringify, que MENTE com NaN`);
            // ⚠️ `!fonte.includes('String(')` era VAZIO AO CONTRÁRIO: casa
            // dentro de `toLocale`**`String(`**`'pt-BR')` — a 3ª variante do
            // substring (o FIM do nome de OUTRO identificador, a mesma de
            // `w-[` dentro de `shadow-[…]`). **Reprovava o código certo.**
            // Trava por BORDA: nem caractere de palavra nem ponto antes.
            assert.doesNotMatch(
                fonte,
                /(^|[^\w.])String\(/,
                `${caminho} coage valor adverso — String({toString:null}) LANÇA`,
            );
            assert.ok(!fonte.includes('hasOwnProperty'), `${caminho} usa hasOwnProperty — coage a chave e LANÇA`);
        }
    });

    await contexto.test('⚠️ toda indexação por chave do servidor tem guarda de TIPO antes', () => {
        // `obj[chave]` com `chave = {toString: null}` LANÇA. A guarda é uma só.
        assert.match(
            FONTE_CELULAS,
            /function valorDoMapa\(mapa, chave\) \{\s*\n\s*if \(typeof chave !== 'string'\) return null;/,
            'a guarda de `valorDoMapa` saiu ou mudou de forma',
        );
        // E os 3 usos passam por ela.
        assert.equal((FONTE_CELULAS.match(/valorDoMapa\(/g) ?? []).length, 4, 'um uso passou a indexar direto');
    });

    await contexto.test('nenhum pacote novo, nenhum hex literal, nenhum `text-sm`', () => {
        for (const [caminho, fonte] of FONTES) {
            assert.doesNotMatch(fonte, /#[0-9A-Fa-f]{6}/, `${caminho} cravou hex literal — FID-04`);
            assert.ok(!fonte.includes('text-sm'), `${caminho} usa text-sm — FID-03`);
            for (const vetado of ['ecf-bg', 'ecf-card', 'ecf-yellow', 'font-display', 'border-white/[0.08]']) {
                assert.ok(!fonte.includes(vetado), `${caminho} usa ${vetado} — FID-04`);
            }
        }
        const pacote = JSON.parse(fs.readFileSync(path.resolve(RAIZ, 'package.json'), 'utf8'));
        for (const dep of ['lucide-react', 'clsx', 'tailwind-merge']) {
            assert.ok(dep in pacote.dependencies || dep in pacote.devDependencies, `${dep} não está instalado`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 16. RENDER ADVERSO — a tela preta de 07/10/2026
//
// O que precisaria estar errado para falhar: qualquer campo chegando objeto,
// nulo, ausente, `NaN`, `Infinity` ou `{toString: null}` e sendo renderizado
// como texto, ou derrubando o render. ⚠️ Foi este bloco que achou, nesta
// rodada, `textoSeguro(NaN)` devolvendo a STRING `'NaN'` em CINCO campos.
// ═══════════════════════════════════════════════════════════════════════════

const FORMAS_ADVERSAS = [
    ['objeto', {}],
    ['objeto com toString nulo', { toString: null }],
    ['objeto aninhado', { a: { b: 1 } }],
    ['nulo', null],
    ['array vazio', []],
    ['array de objeto', [{}]],
    ['NaN', NaN],
    ['Infinity', Infinity],
    ['-Infinity', -Infinity],
    ['string vazia', ''],
    ['só espaço', '   '],
    ['texto', 'abacaxi'],
    ['data suja', '2026-99-99T99:99'],
    ['número', 7],
    ['número negativo', -1],
    ['booleano', true],
    ['função', () => {}],
    ['zero', 0],
];

test('REND-01 — render adverso: nenhum campo derruba a linha', async (contexto) => {
    await contexto.test('cada CHAVE do item em 18 formas adversas + ausente', () => {
        let casos = 0;
        for (const chave of Object.keys(ITEM_CHEIO)) {
            for (const [nome, valor] of FORMAS_ADVERSAS) {
                casos += 1;
                const item = { ...ITEM_CHEIO, [chave]: valor };
                let html = '';
                assert.doesNotThrow(
                    () => { html = umaLinha({ item }); },
                    `${chave} = ${nome} (${descreve(valor)}) derrubou o render`,
                );
                assert.doesNotMatch(html, RE_SUJEIRA, `${chave} = ${nome} (${descreve(valor)}) sujou a tela`);
            }
            casos += 1;
            const semChave = { ...ITEM_CHEIO };
            delete semChave[chave];
            let html = '';
            assert.doesNotThrow(() => { html = umaLinha({ item: semChave }); }, `${chave} AUSENTE derrubou o render`);
            assert.doesNotMatch(html, RE_SUJEIRA, `${chave} AUSENTE sujou a tela`);
        }
        // ⚠️ A contagem EXATA e DERIVADA, não chutada: 26 chaves × (18 formas +
        // 1 ausente) = 494. O `>= 500` que eu havia escrito reprovava o laço
        // COMPLETO — medido nesta rodada, e é a terceira asserção minha que
        // reprovava o código certo.
        const esperado = Object.keys(ITEM_CHEIO).length * (FORMAS_ADVERSAS.length + 1);
        assert.equal(casos, esperado, `o laço rodou ${casos} casos, esperava ${esperado}`);
        assert.ok(Object.keys(ITEM_CHEIO).length >= 26, 'o fixture do item encolheu');
        assert.equal(FORMAS_ADVERSAS.length, 18);
    });

    await contexto.test('cada PROP da linha em 18 formas adversas', () => {
        const props = [
            'item', 'agrupada', 'selecionada', 'comFoco', 'marcada', 'compacta',
            'copiada', 'urlDoRascunho', 'rotulosDeMotivo', 'hoje', 'onAbrir',
            'onMarcar', 'onCopiar', 'className',
        ];
        for (const prop of props) {
            for (const [nome, valor] of FORMAS_ADVERSAS) {
                let html = '';
                assert.doesNotThrow(
                    () => { html = render(Linha, { ...PROPS_BASE, [prop]: valor }); },
                    `prop ${prop} = ${nome} (${descreve(valor)}) derrubou o render`,
                );
                assert.doesNotMatch(html, RE_SUJEIRA, `prop ${prop} = ${nome} sujou a tela`);
            }
        }
    });

    await contexto.test('a prop inteira nula, o item nulo e chave DESCONHECIDA', () => {
        assert.doesNotThrow(() => render(Linha, {}));
        assert.doesNotThrow(() => render(Linha, { item: null }));
        assert.doesNotThrow(() => render(Linha, { item: undefined, hoje: null }));
        assert.doesNotThrow(() => render(Cabecalho, {}));
        const html = umaLinha({ item: { ...ITEM_CHEIO, zzz_nunca_vista: { a: 1 }, 0: 'zero' } });
        assert.doesNotMatch(html, RE_SUJEIRA);
    });

    await contexto.test('o cabeçalho com `todasMarcadas` adverso', () => {
        for (const [nome, valor] of FORMAS_ADVERSAS) {
            let html = '';
            assert.doesNotThrow(
                () => { html = render(Cabecalho, { todasMarcadas: valor, onMarcarTodas: () => {} }); },
                `todasMarcadas = ${nome} derrubou o cabeçalho`,
            );
            assert.doesNotMatch(html, RE_SUJEIRA);
            // ⚠️ Truthy não marca: só `=== true`.
            if (valor !== true) {
                assert.ok(
                    classesDa(tagEm(html, 0)).length > 0 && html.includes('bg-pub-hover'),
                    `${nome} marcou o checkbox "todas" sem ser \`true\``,
                );
            }
        }
    });

    await contexto.test('⚠️ ISCA: o detector de sujeira PEGA o que existe', () => {
        assert.match(renderToStaticMarkup(React.createElement('div', null, `${{}}`)), RE_SUJEIRA);
        assert.match(renderToStaticMarkup(React.createElement('div', null, `${0 / 0}`)), RE_SUJEIRA);
        assert.match(renderToStaticMarkup(React.createElement('div', null, 'Invalid Date')), RE_SUJEIRA);
        // E `descreve()` não lança com o valor que mata `String()`.
        assert.doesNotThrow(() => descreve({ toString: null }));
    });

    await contexto.test('⚠️ `textoDaTela` recusa NaN (o bug que este bloco achou)', () => {
        assert.equal(MOD_CELULAS.textoDaTela(NaN), null, 'NaN virou a string "NaN" na tela');
        assert.equal(MOD_CELULAS.textoDaTela(Infinity), null);
        assert.equal(MOD_CELULAS.textoDaTela('Fone'), 'Fone');
        assert.equal(MOD_CELULAS.textoDaTela(7), '7');
        assert.equal(MOD_CELULAS.textoDaTela(''), null);
        assert.equal(MOD_CELULAS.textoDaTela({ toString: null }), null);
        // E `caminhoSeguro` é mais estrito: número NUNCA vira destino.
        assert.equal(MOD_CELULAS.caminhoSeguro(7), null);
        assert.equal(MOD_CELULAS.caminhoSeguro(' /x '), '/x');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 17. FIDELIDADE PELO CSS CONSTRUÍDO — presença de classe NÃO é fidelidade
//
// O que precisaria estar errado para falhar: a classe presente com o VALOR
// errado — um dos 9 valores do grid, o `62px` virando outro número, um dos
// CINCO fundos apontando para o hex de outro (e aqui o alfa/hex é o único jeito
// de distinguir `row-checked` de `row-new`), o raio 14 da tabela, ou o
// `stroke`/`fill` sólido mudando de cor.
//
// ⚠️ AS ARMADILHAS DO MEDIDOR, TODAS APLICADAS:
//   1. token sólido NÃO compila para hex — sai `rgb(... / var(--tw-...))`;
//   2. `stroke`/`fill` sólidos, ao contrário, SAEM EM HEX LITERAL;
//   3. o modificador de opacidade muda de FORMA conforme a propriedade;
//   4. o cssnano come o zero da frente (`0.6` → `.6`);
//   5. o seletor sai ESCAPADO com barra invertida;
//   6. na variante o prefixo faz parte do nome (`.hover\:bg-...:hover`);
//   7. escapar só a barra quebra em classe com colchete/ponto/PARÊNTESE.
// ⚠️ E a LIMITAÇÃO ESTRUTURAL: esta sonda prova que a classe EXISTE com o valor
// certo; que o ELEMENTO a tem é o gate de HTML acima (igualdade de conjunto).
// Os dois são necessários e não se substituem.
// ═══════════════════════════════════════════════════════════════════════════

/** Escapa TODOS os metacaracteres de regex (armadilha 7). */
function escapaRegex(texto) {
    return texto.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * O seletor que o Tailwind emite: `.` + o nome com os metacaracteres de CSS
 * escapados.
 *
 * ⚠️ `(` e `)` ENTRAM NA LISTA — foi a falta deles que fez a sonda do plano
 * 180-07 reportar `shadow-[…rgba(0,0,0,0.6)]` como AUSENTE com a regra presente.
 * ⚠️ E esta fase TEM valor arbitrário com parênteses: o próprio
 * `grid-cols-[… minmax(300px,1fr) …]`.
 */
function seletorDe(classe) {
    return `.${classe.replace(/[[\]().:/%,]/g, (ch) => `\\${ch}`)}`;
}

function carregarCssConstruido() {
    const manifestRel = 'public/build/manifest.json';
    const manifestAbs = path.resolve(RAIZ, manifestRel);
    if (!fs.existsSync(manifestAbs)) return { motivo: `${manifestRel} ausente — rode \`npm run build\`` };

    // ⚠️ Manifest SEMPRE pelo JSON, nunca por grep: o hash sai com hífen.
    const manifest = JSON.parse(fs.readFileSync(manifestAbs, 'utf8'));
    const entrada = manifest['resources/js/app.jsx'];
    if (!entrada || !Array.isArray(entrada.css) || entrada.css.length === 0) {
        return { motivo: 'o manifest não aponta CSS para resources/js/app.jsx' };
    }

    const cssAbs = path.resolve(RAIZ, 'public/build', entrada.css[0]);
    if (!fs.existsSync(cssAbs)) return { motivo: `${entrada.css[0]} ausente — rode \`npm run build\`` };

    // ⚠️ O CSS tem de ser mais NOVO que os componentes, senão este bloco mediria
    // um build velho e aprovaria o errado (ou reprovaria o certo).
    const mtimeCss = fs.statSync(cssAbs).mtimeMs;
    const mtimeFontes = Math.max(
        ...[REL_CABECALHO, REL_LINHA, REL_CELULAS].map((rel) => fs.statSync(path.resolve(RAIZ, rel)).mtimeMs),
    );
    if (mtimeCss < mtimeFontes) {
        return { motivo: `o CSS construído (${entrada.css[0]}) é mais antigo que os componentes — rode \`npm run build\`` };
    }

    return { arquivo: entrada.css[0], css: fs.readFileSync(cssAbs, 'utf8') };
}

const CSS = carregarCssConstruido();

test('FID — as classes GERAM CSS com o valor certo (não só existem)', async (contexto) => {
    if (CSS.motivo) {
        // ⚠️ `skip` com razão ESCRITA (e `skipped N` aparece no sumário).
        contexto.skip(CSS.motivo);
        return;
    }

    /** O corpo da regra da classe, ou `null`. */
    const regraDe = (classe) => {
        const re = new RegExp(`${escapaRegex(seletorDe(classe))}(?![\\w\\\\-])[^{}]*\\{([^}]*)\\}`);
        const m = CSS.css.match(re);
        return m ? m[1] : null;
    };

    await contexto.test('o CSS lido é o do manifest e tem tamanho de CSS de verdade', () => {
        assert.ok(CSS.css.length > 100000, `CSS suspeito de truncado: ${CSS.css.length} bytes`);
    });

    await contexto.test('⚠️ ISCA: a sonda reporta AUSENTE o que não existe', () => {
        // As três iscas foram medidas como inexistentes no repo inteiro
        // (`grep -rF` em `resources/`, `app/`, `tests/`, `tailwind.config.js`
        // → 0 ocorrências) ANTES de serem usadas. ⚠️ `min-h-[97px]` é de
        // propósito a MESMA FORMA do `min-h-[62px]` real: se a sonda estivesse
        // lendo a FORMA em vez do valor, ela acharia as duas.
        for (const isca of ['bg-pub-isca-8t2', 'min-h-[97px]', 'h-[31px]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS.css.includes(isca), `a string ${isca} apareceu no CSS`);
        }
        // E o controle: a sonda ACHA o que existe.
        assert.notEqual(regraDe('min-h-[62px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('⚠️ o GRID compila com os NOVE valores, na ordem', () => {
        const regra = regraDe(GRID_LITERAL);
        assert.notEqual(regra, null, 'a classe do grid não gerou CSS — o Tailwind não a varreu?');
        assert.match(
            regra,
            /grid-template-columns:\s*40px minmax\(300px,1fr\) 136px 104px 136px 112px 68px 150px 64px/,
            `o grid compilou com outros valores: ${regra}`,
        );
        // ⚠️ E um valor VIZINHO não existe: `140px` no lugar de `136px` passaria
        // num gate de presença de classe.
        assert.equal(
            regraDe('grid-cols-[40px_minmax(300px,1fr)_140px_104px_136px_112px_68px_150px_64px]'),
            null,
        );
    });

    await contexto.test('⚠️ os CINCO fundos compilam para CINCO cores distintas', () => {
        const esperado = {
            'bg-pub-row-sel': '34 29 7',
            'bg-pub-row-focus': '28 28 28',
            'bg-pub-row-checked': '24 22 12',
            'bg-pub-row-new': '20 18 10',
            'bg-pub-surface': '17 17 17',
        };
        const vistos = new Map();
        for (const [classe, rgb] of Object.entries(esperado)) {
            const regra = regraDe(classe);
            assert.notEqual(regra, null, `${classe} não gerou CSS`);
            // ⚠️ Token SÓLIDO sai em `rgb(... / var(--tw-bg-opacity, 1))`,
            // DECIMAL — esperar hex aqui deu 25 falsas faltas nesta milestone.
            assert.ok(
                regra.includes(`rgb(${rgb}`),
                `${classe} compilou para outra cor: ${regra} (esperava rgb(${rgb} …))`,
            );
            assert.ok(!vistos.has(rgb), `${classe} tem a MESMA cor de ${vistos.get(rgb)} — os 5 são distintos`);
            vistos.set(rgb, classe);
        }
        assert.equal(vistos.size, 5);
    });

    await contexto.test('as duas alturas e as medidas da linha', () => {
        assert.match(regraDe('min-h-[62px]'), /min-height:\s*62px/);
        assert.match(regraDe('min-h-[50px]'), /min-height:\s*50px/);
        assert.match(regraDe('px-[16px]'), /padding-left:\s*16px;padding-right:\s*16px/);
        assert.match(regraDe('h-[40px]'), /height:\s*40px/);
        assert.match(regraDe('border-pub-line-subtle'), /rgb\(31 31 31/);
        assert.match(regraDe('border-pub-line'), /rgb\(38 38 38/);
        assert.match(regraDe('bg-pub-sunken'), /rgb\(13 13 13/);
    });

    await contexto.test('o raio da tabela e o do badge', () => {
        assert.match(regraDe('rounded-pub-table'), /border-radius:\s*14px/);
        assert.match(regraDe('rounded-pub-badge'), /border-radius:\s*4px/);
        assert.match(regraDe('rounded-pub-control-sm'), /border-radius:\s*7px/);
    });

    await contexto.test('⚠️ `stroke`/`fill` sólidos SAEM EM HEX (2ª normalização)', () => {
        // Ao contrário de `background`, aqui o hex literal é o certo.
        assert.match(regraDe('stroke-pub-ok-txt'), /stroke:\s*#4ade80/i, 'o verde do "copiado" mudou');
        assert.match(regraDe('stroke-pub-page'), /stroke:\s*#0a0a0a/i);
        assert.match(regraDe('fill-pub-danger'), /fill:\s*#ef4444/i, 'o triângulo do Prejuízo mudou de cor');
    });

    await contexto.test('os corpos de texto da linha (FID-03: meio pixel conta)', () => {
        for (const [classe, valor] of [
            ['text-[13.5px]', '13.5px'], ['text-[12.5px]', '12.5px'],
            ['text-[11.5px]', '11.5px'], ['text-[13px]', '13px'],
            ['text-[12px]', '12px'], ['text-[10px]', '10px'],
        ]) {
            const regra = regraDe(classe);
            assert.notEqual(regra, null, `${classe} não gerou CSS`);
            assert.ok(regra.includes(`font-size:${valor}`), `${classe} compilou para ${regra}`);
        }
        // ⚠️ E o vizinho de meio pixel NÃO é o mesmo: `text-[11px]` como
        // substring casaria dentro de `text-[11.5px]`.
        assert.notEqual(regraDe('text-[11px]'), regraDe('text-[11.5px]'));
    });

    await contexto.test('os gaps e recuos medidos', () => {
        for (const [classe, prop, valor] of [
            ['gap-[12px]', 'gap', '12px'], ['gap-[10px]', 'gap', '10px'],
            ['gap-[8px]', 'gap', '8px'], ['gap-[6px]', 'gap', '6px'],
            ['gap-[4px]', 'gap', '4px'], ['gap-[3px]', 'gap', '3px'],
            ['gap-[2px]', 'gap', '2px'], ['gap-[1px]', 'gap', '1px'],
            ['pl-[30px]', 'padding-left', '30px'], ['pl-[18px]', 'padding-left', '18px'],
            ['pr-[16px]', 'padding-right', '16px'],
        ]) {
            const regra = regraDe(classe);
            assert.notEqual(regra, null, `${classe} não gerou CSS`);
            assert.ok(regra.includes(`${prop}:${valor}`), `${classe} compilou para ${regra}`);
        }
    });

    await contexto.test('⚠️ na VARIANTE o prefixo faz parte do nome (6ª normalização)', () => {
        const hover = CSS.css.match(
            new RegExp(`${escapaRegex(seletorDe('hover:bg-pub-seg'))}:hover[^{}]*\\{([^}]*)\\}`),
        );
        assert.notEqual(hover, null, 'o hover do botão de ação não gerou CSS');
        assert.match(hover[1], /rgb\(38 38 38/);
    });

    await contexto.test('⚠️ 180-10B: `font-pub-mono` compila com a Geist Mono (o SKU e o MLB)', () => {
        // ⚠️ A sonda de HTML acima prova que o ELEMENTO tem a classe; esta
        // prova que a CLASSE EXISTE COM O VALOR CERTO. Os dois gates são
        // necessários e não se substituem (learnings §3) — e aqui o valor é a
        // medida do protótipo nos dois lugares em que ele desenha SKU
        // (`:178` e `:382`): `font-family:'Geist Mono',monospace`.
        const regra = regraDe('font-pub-mono');
        assert.notEqual(regra, null, '`font-pub-mono` não gerou CSS — o Tailwind não varreu a classe do SKU?');
        assert.match(regra, /font-family:\s*Geist Mono/i, `o mono do SKU/MLB compilou para outra fonte: ${regra}`);
        // ⚠️ E NÃO é a `font-pub` (Geist, não Geist Mono): `font-pub` é
        // PREFIXO de `font-pub-mono`, e trocar uma pela outra no SKU passaria
        // num gate de substring.
        const proporcional = regraDe('font-pub');
        assert.notEqual(proporcional, null);
        assert.notEqual(regra, proporcional, '`font-pub` e `font-pub-mono` compilaram para a MESMA pilha');
        assert.doesNotMatch(proporcional, /Geist Mono/i, '`font-pub` virou mono — o texto da tela ficaria monoespaçado');
        // ISCA: classe inexistente no projeto inteiro.
        assert.equal(regraDe('font-pub-mono-sku-7z'), null);
    });

    await contexto.test('os 16/24/28/18 px dos elementos de interação', () => {
        assert.match(regraDe('h-[16px]'), /height:\s*16px/);
        assert.match(regraDe('w-[16px]'), /width:\s*16px/);
        assert.match(regraDe('h-[18px]'), /height:\s*18px/);
        assert.match(regraDe('h-[24px]'), /height:\s*24px/);
        assert.match(regraDe('h-[28px]'), /height:\s*28px/);
        assert.match(regraDe('w-[28px]'), /width:\s*28px/);
        assert.match(regraDe('h-[14px]'), /height:\s*14px/);
        assert.match(regraDe('h-[12px]'), /height:\s*12px/);
    });
});
