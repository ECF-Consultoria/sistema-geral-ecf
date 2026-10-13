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
// Fase 180, plano 07 — a linha de chips de FILTRO (LIST-07), a casca dos dois
// popovers e o bloco de TRIAGEM (LIST-22/LIST-23), com render REAL
// (esbuild + `react-dom/server`).
//
// Protótipo: `new_layout_publicador/Publicacoes v2.dc.html`. Linhas MEDIDAS com
// `grep -n` neste arquivo (não copiadas da tabela do plano):
//   · linha de chips    → :102  · grupo "Fase"     → :103 / :104 / :106
//   · grupo "Tipo"      → :109 / :110 / :112
//   · "Em catálogo"     → :114  · ícone            → :116
//   · "Com vendas"      → :118  · ícone            → :119
//   · wrapper relative  → :121 e :139
//   · "Responsável"     → :122  · sufixo → :123  · chevron → :124
//   · popover resp      → :127  · item   → :129  · checkbox → :130
//   · check do checkbox → :131  · avatar → :133
//   · "Publicada"       → :140  · sufixo → :142  · chevron → :143
//   · popover período   → :146  · item   → :148  · check   → :150
//   · "Limpar filtros"  → :156 (o `sc-if`) e :157
//   · backdrop          → :307
//   · `seg()`           → :535  · `respOpts` → :605  · `periods` → :606
//   · `periodOpts`      → :607  · `on(b)`    → :608  · `anyFilter` → :610
//   · os dois sufixos   → :635
//
// ⚠️ REND-03: TODOS os bundles são montados AQUI NO TOPO, antes do primeiro
// `test()`. O `after()` do `node --test` dispara quando os testes registrados
// terminam e já matou um arquivo inteiro deste projeto SEM nenhum teste falhar.
//
// ⚠️ ESTA LINHA É O PIOR CASO DE IRMÃO IDÊNTICO DA FASE: 11 botões, dos quais
// 6 (as opções dos dois segmentados) têm a MESMA string de classes e 4 (os
// chips-toggle) também. `html.indexOf(listaDeClasses)` mediria o irmão errado —
// quatro gates do ERP já mediram a linha do Mercado Livre por isso. Toda
// localização aqui é por ÁRVORE com índices absolutos, e a IDENTIDADE de cada
// botão é confirmada pelo TEXTO antes de a classe ser conferida.
//
// ⚠️ A pergunta obrigatória — *"o que precisaria estar errado no código para
// este gate falhar?"* — está respondida POR TESTE, no comentário de cada bloco.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

const STUB_INERTIA = path.join(__dirname, `.pub-filtros-inertia-stub-${process.pid}.mjs`);
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
        `.pub-filtros-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_PUB = 'resources/js/Components/Mlb/Publicador/publicacoes';
const REL_CHIPS = `${DIR_PUB}/ChipsDeFiltro.jsx`;
const REL_MENU = `${DIR_PUB}/MenuDeFiltro.jsx`;
const REL_TRIAGEM = `${DIR_PUB}/ChipsDeTriagem.jsx`;

// ⚠️ `test -e` ANTES de acreditar em qualquer leitura vazia. CINCO caminhos
// inexistentes já "provaram" território intocado nesta milestone.
for (const caminho of [DIR_PUB, REL_CHIPS, REL_MENU, REL_TRIAGEM]) {
    if (!fs.existsSync(path.resolve(RAIZ, caminho))) {
        throw new Error(`caminho inexistente: ${caminho} — gate mediria NADA`);
    }
}

const MOD_CHIPS = await montar(REL_CHIPS);
const MOD_MENU = await montar(REL_MENU);
const MOD_TRIAGEM = await montar(REL_TRIAGEM);

const Chips = MOD_CHIPS.default;
const Menu = MOD_MENU.default;
const Triagem = MOD_TRIAGEM.default;

const FONTE_CHIPS = lerSemComentarios(REL_CHIPS);
const FONTE_MENU = lerSemComentarios(REL_MENU);
const FONTE_TRIAGEM = lerSemComentarios(REL_TRIAGEM);

const FONTES = [
    [REL_CHIPS, FONTE_CHIPS],
    [REL_MENU, FONTE_MENU],
    [REL_TRIAGEM, FONTE_TRIAGEM],
];

/** Render de servidor. */
const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

// ─────────────────────────────────────────────────────────────────────────
// Localização por ÁRVORE com índices absolutos (o molde do plano 180-06).
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
 * ⚠️ É isto que torna a localização por árvore honesta: fatiar "do começo da
 * tag até o próximo `</div>`" fecharia no filho, não no pai.
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
 * tem voto. (Limitação medida nos planos 180-05 e 180-06.)
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

// ─────────────────────────────────────────────────────────────────────────
// OS FIXTURES
//
// ⚠️ As chaves e os valores são os do SERVIDOR, conferidos no controller:
// `fase` em `[1, 2]` como INT (`MlbAnuncioController.php:596`), `tipo` em
// `['classico', 'premium']` (`:602`), `periodo` em `['hoje','7d','30d']`
// (`:636`), `responsavel` como `array<int,int>` positivos (`:618`-`:626`).
// ─────────────────────────────────────────────────────────────────────────

const RESPONSAVEIS = [
    { id: 7, nome: 'Ana Rodrigues Silva' },
    { id: 9, nome: 'Bruno Lima Costa' },
    { id: 11, nome: 'Carla Mendes Souza' },
];

/** Todos os 6 filtros LIGADOS, com `algumAtivo` verdadeiro. */
const PROPS_CHEIAS = {
    fase: { valor: 1, onTrocar: () => {} },
    tipo: { valor: 'premium', onTrocar: () => {} },
    catalogo: { ligado: true, onAlternar: () => {} },
    comVendas: { ligado: true, onAlternar: () => {} },
    responsavel: { selecionados: [7, 11], opcoes: RESPONSAVEIS, onAlternar: () => {} },
    periodo: { valor: '7d', onTrocar: () => {} },
    algumAtivo: true,
    onLimparFiltros: () => {},
};

/** Todos os 6 DESLIGADOS — é o estado inicial da tela. */
const PROPS_VAZIAS = {
    fase: { valor: null, onTrocar: () => {} },
    tipo: { valor: null, onTrocar: () => {} },
    catalogo: { ligado: false, onAlternar: () => {} },
    comVendas: { ligado: false, onAlternar: () => {} },
    responsavel: { selecionados: [], opcoes: RESPONSAVEIS, onAlternar: () => {} },
    periodo: { valor: null, onTrocar: () => {} },
    algumAtivo: false,
    onLimparFiltros: () => {},
};

const HTML_CHIPS_CHEIO = render(Chips, PROPS_CHEIAS);
const HTML_CHIPS_VAZIO = render(Chips, PROPS_VAZIAS);

/** ⚠️ Caso 4: TUDO ligado, mas `algumAtivo: false` — o 9º dos nove. */
const HTML_CHIPS_SEM_ALGUM = render(Chips, { ...PROPS_CHEIAS, algumAtivo: false });

/** ⚠️ Caso 5: sem opção de responsável, com TODO o resto cheio. */
const HTML_CHIPS_SEM_RESP = render(Chips, {
    ...PROPS_CHEIAS,
    responsavel: { selecionados: [7], opcoes: [], onAlternar: () => {} },
});

const HTML_MENU_FECHADO = render(Menu, {
    aberto: false,
    largura: MOD_MENU.LARGURA_RESPONSAVEL,
    onFechar: () => {},
    children: React.createElement('button', { type: 'button' }, 'um item'),
});
const HTML_MENU_RESP = render(Menu, {
    aberto: true,
    largura: MOD_MENU.LARGURA_RESPONSAVEL,
    onFechar: () => {},
    children: React.createElement('button', { type: 'button' }, 'um item'),
});
const HTML_MENU_PUB = render(Menu, {
    aberto: true,
    largura: MOD_MENU.LARGURA_PUBLICADA,
    onFechar: () => {},
    children: React.createElement('button', { type: 'button' }, 'um item'),
});

/** Os 5 motivos de `motivosDef()` (`AcervoTriagemService.php:383`-`:389`). */
const CHIPS_5 = [
    { chave: 'pausado', label: 'Pausado', count: 3, cor: 'red' },
    { chave: 'sem_estoque', label: 'Sem estoque', count: 5, cor: 'red' },
    { chave: 'ficha_incompleta', label: 'Ficha incompleta', count: 2, cor: 'amber' },
    { chave: 'perdendo_catalogo', label: 'Perdendo catálogo', count: 1, cor: 'amber' },
    { chave: 'foto_insuficiente', label: 'Foto insuficiente', count: 4, cor: 'amber' },
];

const PROPS_TRIAGEM = {
    total: 11,
    chips: CHIPS_5,
    naoAvaliado: 6,
    motivoAtivo: 'pausado',
    onFiltrarMotivo: () => {},
};

const HTML_TRIAGEM = render(Triagem, PROPS_TRIAGEM);
const HTML_TRIAGEM_ZERO = render(Triagem, { ...PROPS_TRIAGEM, total: 0 });
const HTML_TRIAGEM_NULO = render(Triagem, { ...PROPS_TRIAGEM, total: null });

/** ⚠️ Caso 8 (LIST-22): total 7 com chips que somam 8. */
const HTML_TRIAGEM_SOMA = render(Triagem, {
    total: 7,
    chips: [
        { chave: 'pausado', label: 'Pausado', count: 3, cor: 'red' },
        { chave: 'sem_estoque', label: 'Sem estoque', count: 5, cor: 'red' },
    ],
    naoAvaliado: null,
    motivoAtivo: null,
    onFiltrarMotivo: () => {},
});

// ─────────────────────────────────────────────────────────────────────────
// OS BOTÕES DA LINHA, POR ÁRVORE
// ─────────────────────────────────────────────────────────────────────────

/** O bloco da linha de chips, localizado pela classe EXPORTADA (ocorrência única). */
function blocoDaLinha(html) {
    const marca = `<div class="${MOD_CHIPS.CLASSE_DA_LINHA}"`;
    assert.equal(
        (html.match(new RegExp(marca.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g')) ?? []).length,
        1,
        'a linha de chips não é única no HTML — a localização por árvore ficaria ambígua',
    );
    return blocoEm(html, html.indexOf(marca), 'div');
}

/** Os `<button>` da linha, EM ORDEM, com posição absoluta e bloco próprio. */
function botoesDaLinha(html) {
    const bloco = blocoDaLinha(html);
    return indicesDasTags(html, bloco, 'button').map((pos) => ({
        pos,
        tag: tagEm(html, pos),
        bloco: blocoEm(html, pos, 'button'),
    }));
}

/** As `<div>` da linha, EM ORDEM (grupo Fase, grupo Tipo, os dois wrappers). */
function divsDaLinha(html) {
    const bloco = blocoDaLinha(html);
    // A primeira é a própria linha; as seguintes são os filhos.
    return indicesDasTags(html, bloco, 'div').slice(1).map((pos) => ({
        pos,
        tag: tagEm(html, pos),
        bloco: blocoEm(html, pos, 'div'),
    }));
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. OS LITERAIS, BYTE A BYTE
//
// O que precisaria estar errado para falhar: alguém parafrasear um dos 6
// rótulos de família, trocar "1 · Simples" por "Fase 1", traduzir "Qualquer
// data" para "Todas as datas", mudar "Limpar filtros", perder o `·` (U+00B7) do
// sufixo, ou escrever o jargão do servidor (`classico`, `7d`, `pausado`) na
// tela. É a forma mais comum de o redesenho "adaptar ao sistema atual".
// ═══════════════════════════════════════════════════════════════════════════

test('LIST-07 — os literais dos chips de filtro estão byte a byte', async (contexto) => {
    await contexto.test('as 3 opções de "Fase" (:631) e as 3 de "Tipo" (:632)', () => {
        assert.deepEqual(MOD_CHIPS.OPCOES_DE_FASE.map((o) => o.rotulo), ['Todas', '1 · Simples', '2 · Combo']);
        assert.deepEqual(MOD_CHIPS.OPCOES_DE_TIPO.map((o) => o.rotulo), ['Todos', 'Clássico', 'Premium']);
        assert.deepEqual(MOD_CHIPS.OPCOES_DE_FASE.map((o) => o.valor), ['todas', '1', '2']);
        assert.deepEqual(MOD_CHIPS.OPCOES_DE_TIPO.map((o) => o.valor), ['todos', 'classico', 'premium']);

        for (const rotulo of ['Todas', '1 · Simples', '2 · Combo', 'Todos', 'Clássico', 'Premium']) {
            assert.ok(HTML_CHIPS_VAZIO.includes(`>${rotulo}</button>`), `rótulo ausente: ${rotulo}`);
        }
        // O `·` é U+00B7 MIDDLE DOT e não um hífen ou um ponto comum.
        assert.equal('1 · Simples'.codePointAt(2), 0x00b7);
    });

    await contexto.test('as 4 opções de "Publicada" (:606), com os valores do servidor', () => {
        assert.deepEqual(
            MOD_CHIPS.OPCOES_DE_PERIODO.map((o) => o.rotulo),
            ['Qualquer data', 'Hoje', 'Últimos 7 dias', 'Últimos 30 dias'],
        );
        assert.deepEqual(MOD_CHIPS.OPCOES_DE_PERIODO.map((o) => o.valor), ['qualquer', 'hoje', '7d', '30d']);
    });

    await contexto.test('os 5 rótulos de família e o "Limpar filtros" (:157)', () => {
        assert.equal(MOD_CHIPS.ROTULO_FASE, 'Fase');
        assert.equal(MOD_CHIPS.ROTULO_TIPO, 'Tipo');
        assert.equal(MOD_CHIPS.ROTULO_CATALOGO, 'Em catálogo');
        assert.equal(MOD_CHIPS.ROTULO_COM_VENDAS, 'Com vendas');
        assert.equal(MOD_CHIPS.ROTULO_RESPONSAVEL, 'Responsável');
        assert.equal(MOD_CHIPS.ROTULO_PUBLICADA, 'Publicada');
        assert.equal(MOD_CHIPS.LIMPAR_FILTROS, 'Limpar filtros');

        for (const rotulo of ['Fase', 'Tipo', 'Em catálogo', 'Com vendas', 'Responsável', 'Publicada']) {
            assert.ok(HTML_CHIPS_CHEIO.includes(rotulo), `família ausente: ${rotulo}`);
        }
        assert.ok(HTML_CHIPS_CHEIO.includes('Limpar filtros'));
    });

    await contexto.test('os dois sufixos (:635): contagem no Responsável, rótulo no Publicada', () => {
        assert.equal(MOD_CHIPS.sufixoDeResponsavel([7, 11]), '· 2');
        assert.equal(MOD_CHIPS.sufixoDeResponsavel([]), '');
        assert.equal(MOD_CHIPS.sufixoDePeriodo('7d'), '· Últimos 7 dias');
        assert.equal(MOD_CHIPS.sufixoDePeriodo('qualquer'), '');

        assert.ok(HTML_CHIPS_CHEIO.includes('>· 2</span>'), HTML_CHIPS_CHEIO);
        assert.ok(HTML_CHIPS_CHEIO.includes('>· Últimos 7 dias</span>'));
    });

    await contexto.test('⚠️ T-180-07-I: o sufixo é CONTAGEM — nenhum NOME de pessoa fora do menu', () => {
        // Os menus estão FECHADOS nos dois renders (estado inicial), logo
        // nenhum nome pode aparecer. ⚠️ Asserção de ausência rodada com dado
        // PREENCHIDO: as 3 opções e 2 selecionados estão nas props.
        for (const nome of ['Ana Rodrigues Silva', 'Bruno Lima Costa', 'Carla Mendes Souza']) {
            assert.ok(!HTML_CHIPS_CHEIO.includes(nome), `nome de pessoa vazou no chip: ${nome}`);
        }
        // E a ISCA: o nome aparece quando o item do menu é renderizado.
        const itens = MOD_CHIPS.montarResponsaveis(RESPONSAVEIS, [7], () => {});
        const htmlDoItem = renderToStaticMarkup(MOD_CHIPS.itemDeResponsavel(itens[0]));
        assert.ok(htmlDoItem.includes('Ana Rodrigues Silva'), 'o item do menu deixou de mostrar o nome');
    });

    await contexto.test('⚠️ nenhum jargão do servidor na tela', () => {
        // ⚠️ `premium` e `classico` NÃO entram nesta lista: "Premium" e
        // "Clássico" são os RÓTULOS do protótipo (`:632`) — foi o primeiro
        // furo deste gate, que acusava o rótulo legítimo numa comparação
        // insensível a caixa. O que é jargão de verdade são os códigos do
        // Mercado Livre e as chaves de banda da URL.
        const texto = textoDe(HTML_CHIPS_CHEIO).toLowerCase();
        for (const jargao of ['gold_pro', 'gold_special', 'under_review', 'catalog_listing', 'buybox', '7d', '30d']) {
            assert.ok(!texto.includes(jargao), `jargão na tela: ${jargao} em "${texto}"`);
        }
        // ISCA: o detector acha o que existe — os rótulos legítimos estão lá.
        assert.ok(texto.includes('premium'));
        assert.ok(texto.includes('clássico'));
    });
});

test('LIST-22/LIST-23 — os literais da triagem estão byte a byte', async (contexto) => {
    await contexto.test('a ressalva, o estado sem pendência e o selo', () => {
        assert.equal(MOD_TRIAGEM.RESSALVA, '(um anúncio pode aparecer em mais de um motivo)');
        assert.equal(MOD_TRIAGEM.SEM_PENDENCIA, 'Nenhum anúncio precisa de atenção agora');
        assert.equal(MOD_TRIAGEM.NAO_AVALIADO, 'Não avaliado');

        assert.ok(HTML_TRIAGEM.includes('(um anúncio pode aparecer em mais de um motivo)'), HTML_TRIAGEM);
        assert.ok(HTML_TRIAGEM.includes('Não avaliado'));
        assert.ok(HTML_TRIAGEM_ZERO.includes('Nenhum anúncio precisa de atenção agora'));
    });

    await contexto.test('⚠️ a ressalva SÓ aparece com pendência (dado preenchido nos outros)', () => {
        // `total: 0` e `total: null` têm os MESMOS 5 chips e o MESMO
        // `naoAvaliado` — a ausência não é vazia por construção.
        assert.ok(!HTML_TRIAGEM_ZERO.includes('mais de um motivo'), HTML_TRIAGEM_ZERO);
        assert.ok(!HTML_TRIAGEM_NULO.includes('mais de um motivo'), HTML_TRIAGEM_NULO);
        assert.ok(HTML_TRIAGEM_ZERO.includes('Pausado'), 'os chips desapareceram: a negativa ficaria vazia');
    });

    await contexto.test('os 5 rótulos de motivosDef() chegam do servidor e são desenhados', () => {
        for (const label of ['Pausado', 'Sem estoque', 'Ficha incompleta', 'Perdendo catálogo', 'Foto insuficiente']) {
            assert.ok(HTML_TRIAGEM.includes(`${label}</button>`), `motivo ausente: ${label}`);
        }
        // ⚠️ A lista NÃO é reimplementada no cliente: nenhuma das 5 chaves nem
        // dos 5 rótulos está cravada na fonte.
        for (const chave of ['pausado', 'sem_estoque', 'ficha_incompleta', 'perdendo_catalogo', 'foto_insuficiente']) {
            assert.ok(!FONTE_TRIAGEM.includes(chave), `a fonte cravou o motivo ${chave} — a fonte única é motivosDef()`);
        }
        assert.ok(!FONTE_TRIAGEM.includes('Sem estoque'), 'a fonte cravou um rótulo de motivo');
    });

    await contexto.test('⚠️ o título no SINGULAR e no plural', () => {
        assert.equal(MOD_TRIAGEM.sufixoDoTotal(1), 'anúncio precisa de você');
        assert.equal(MOD_TRIAGEM.sufixoDoTotal(2), 'anúncios precisam de você');
        const um = render(Triagem, { ...PROPS_TRIAGEM, total: 1 });
        assert.ok(um.includes('anúncio precisa de você'), um);
        assert.ok(!um.includes('anúncios precisam'), 'com total 1 a tela escreveu o plural');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. OS 11 IRMÃOS, LOCALIZADOS POR ÁRVORE — UMA ASSERÇÃO POR CHIP
//
// O que precisaria estar errado para falhar: qualquer medida trocada (32→30,
// raio 8→7, 12.5→`text-sm`, 11→12 de padding), QUALQUER CLASSE A MAIS ou A
// MENOS (igualdade de conjunto), o `cursor-pointer` sumir (o protótipo o
// declara em todos), a borda OFF virar `pub-line-strong`, o ON do segmentado
// ganhar borda, o ON do chip-toggle perder a borda, ou o estado ativo cair no
// irmão ERRADO — cada um dos 11 é conferido na SUA PRÓPRIA tag, achada por
// índice absoluto e confirmada pelo TEXTO.
// ═══════════════════════════════════════════════════════════════════════════

const CLASSES_DA_LINHA = ['flex', 'flex-wrap', 'items-center', 'gap-[8px]'];
const CLASSES_DO_GRUPO = [
    'flex', 'h-[32px]', 'items-center', 'overflow-hidden', 'rounded-[8px]',
    'border', 'border-pub-line-strong', 'bg-pub-surface',
];
const CLASSES_DO_ROTULO = [
    'flex', 'h-full', 'items-center', 'border-r', 'border-pub-line',
    'px-[10px]', 'text-[12.5px]', 'text-pub-txt-3',
];
const CLASSES_DA_OPCAO = ['h-full', 'cursor-pointer', 'px-[11px]', 'text-[12.5px]', 'text-pub-txt'];
const CLASSES_OPCAO_ATIVA = [...CLASSES_DA_OPCAO, 'bg-pub-accent/[0.16]', 'font-semibold'];
const CLASSES_OPCAO_INATIVA = [...CLASSES_DA_OPCAO, 'bg-transparent', 'font-normal'];
const CLASSES_DO_CHIP = [
    'flex', 'h-[32px]', 'cursor-pointer', 'items-center', 'gap-[6px]',
    'rounded-[8px]', 'border', 'px-[11px]', 'text-[12.5px]', 'text-pub-txt',
];
const CLASSES_CHIP_ON = [...CLASSES_DO_CHIP, 'border-pub-accent/[0.55]', 'bg-pub-accent/[0.12]'];
const CLASSES_CHIP_OFF = [...CLASSES_DO_CHIP, 'border-pub-line-off-2', 'bg-pub-surface'];
const CLASSES_DE_LIMPAR = [
    'h-[32px]', 'cursor-pointer', 'px-[10px]', 'text-[12.5px]',
    'text-pub-neutral-txt', 'underline', 'underline-offset-[3px]',
];

test('FID — a geometria da linha e dos dois grupos segmentados (:102/:103/:104)', async (contexto) => {
    await contexto.test('a linha de chips (:102)', () => {
        const pos = HTML_CHIPS_CHEIO.indexOf('<div');
        assertClasses(tagEm(HTML_CHIPS_CHEIO, pos), CLASSES_DA_LINHA, 'linha :102');
        assert.equal(MOD_CHIPS.CLASSE_DA_LINHA, CLASSES_DA_LINHA.join(' '));
    });

    await contexto.test('os DOIS grupos têm a mesma geometria (:103 = :109), e o rótulo certo', () => {
        const divs = divsDaLinha(HTML_CHIPS_CHEIO);
        // ⚠️ IDENTIDADE pelo texto do rótulo ANTES da classe: dois grupos com a
        // mesma string de classes é exatamente o caso em que `indexOf` mede o
        // irmão errado.
        const grupos = divs.filter((d) => classesDa(d.tag).includes('overflow-hidden'));
        assert.equal(grupos.length, 2, 'esperados 2 grupos segmentados na linha');
        assert.ok(grupos[0].bloco.includes('>Fase</span>'), 'o 1º grupo não é "Fase"');
        assert.ok(grupos[1].bloco.includes('>Tipo</span>'), 'o 2º grupo não é "Tipo"');

        for (const [i, grupo] of grupos.entries()) {
            assertClasses(grupo.tag, CLASSES_DO_GRUPO, `grupo ${i} :103`);
            const posSpan = grupo.pos + grupo.bloco.indexOf('<span');
            assertClasses(tagEm(HTML_CHIPS_CHEIO, posSpan), CLASSES_DO_ROTULO, `rótulo ${i} :104`);
        }
    });
});

test('FID — as 6 opções segmentadas, uma asserção por irmã (:106 + seg() :535)', async (contexto) => {
    await contexto.test('a ORDEM e o TEXTO das 6, por índice absoluto', () => {
        const botoes = botoesDaLinha(HTML_CHIPS_CHEIO);
        assert.equal(botoes.length, 11, `esperados 11 botões na linha cheia, achei ${botoes.length}`);
        assert.deepEqual(
            botoes.slice(0, 6).map((b) => textoDe(b.bloco)),
            ['Todas', '1 · Simples', '2 · Combo', 'Todos', 'Clássico', 'Premium'],
        );
    });

    await contexto.test('⚠️ a ATIVA é a certa: `fase: 1` marca a 2ª e `tipo: premium` a 6ª', () => {
        const botoes = botoesDaLinha(HTML_CHIPS_CHEIO);
        const esperado = [false, true, false, false, false, true];

        for (let i = 0; i < 6; i += 1) {
            assertClasses(
                botoes[i].tag,
                esperado[i] ? CLASSES_OPCAO_ATIVA : CLASSES_OPCAO_INATIVA,
                `opção ${i} (${textoDe(botoes[i].bloco)})`,
            );
            assert.equal(atributo(botoes[i].tag, 'aria-pressed'), String(esperado[i]));
        }

        // ⚠️ "existe UM bg-pub-accent/[0.16] no HTML" passaria com a marca em
        // qualquer irmã: aqui a contagem está travada em 2 (uma por grupo).
        assert.equal((HTML_CHIPS_CHEIO.match(/bg-pub-accent\/\[0\.16\]/g) ?? []).length, 2);
    });

    await contexto.test('⚠️ sem filtro: a 1ª de cada grupo é a ativa, e são SÓ duas', () => {
        const botoes = botoesDaLinha(HTML_CHIPS_VAZIO);
        const esperado = [true, false, false, true, false, false];
        for (let i = 0; i < 6; i += 1) {
            assertClasses(
                botoes[i].tag,
                esperado[i] ? CLASSES_OPCAO_ATIVA : CLASSES_OPCAO_INATIVA,
                `opção ${i} sem filtro`,
            );
        }
        assert.equal((HTML_CHIPS_VAZIO.match(/bg-pub-accent\/\[0\.16\]/g) ?? []).length, 2);
    });

    await contexto.test('⚠️ a opção ativa NÃO ganha borda (seg() :535 não tem borda)', () => {
        const botoes = botoesDaLinha(HTML_CHIPS_CHEIO);
        for (let i = 0; i < 6; i += 1) {
            const classes = classesDa(botoes[i].tag);
            assert.ok(!classes.includes('border'), `opção ${i} ganhou borda: seg() não declara nenhuma`);
            assert.ok(
                !classes.some((c) => c.startsWith('border-pub-accent')),
                `opção ${i} ganhou a borda do CHIP — são DUAS regras de ativo, não uma`,
            );
        }
    });

    await contexto.test('⚠️ `fase` INT e `fase` string resolvem igual (o servidor manda int)', () => {
        const comInt = botoesDaLinha(render(Chips, { ...PROPS_VAZIAS, fase: { valor: 2, onTrocar: () => {} } }));
        const comStr = botoesDaLinha(render(Chips, { ...PROPS_VAZIAS, fase: { valor: '2', onTrocar: () => {} } }));
        assertClasses(comInt[2].tag, CLASSES_OPCAO_ATIVA, 'fase int 2');
        assertClasses(comStr[2].tag, CLASSES_OPCAO_ATIVA, 'fase string "2"');
        // E valor fora da lista cai em "Todas", sem lançar.
        const forinha = botoesDaLinha(render(Chips, { ...PROPS_VAZIAS, fase: { valor: 9, onTrocar: () => {} } }));
        assertClasses(forinha[0].tag, CLASSES_OPCAO_ATIVA, 'fase 9 (inexistente) → Todas');
    });
});

test('FID — os 4 chips-toggle, um por um (:114/:118/:122/:140 + on(b) :608)', async (contexto) => {
    await contexto.test('a ORDEM e o TEXTO dos 4, por índice absoluto', () => {
        const botoes = botoesDaLinha(HTML_CHIPS_CHEIO);
        assert.equal(textoDe(botoes[6].bloco), 'Em catálogo');
        assert.equal(textoDe(botoes[7].bloco), 'Com vendas');
        assert.equal(textoDe(botoes[8].bloco), 'Responsável· 2');
        assert.equal(textoDe(botoes[9].bloco), 'Publicada· Últimos 7 dias');
        assert.equal(textoDe(botoes[10].bloco), 'Limpar filtros');
    });

    await contexto.test('⚠️ LIGADOS: borda 0,55 E fundo 0,12 — nos 4, na tag de cada um', () => {
        const botoes = botoesDaLinha(HTML_CHIPS_CHEIO);
        for (const i of [6, 7, 8, 9]) {
            assertClasses(botoes[i].tag, CLASSES_CHIP_ON, `chip ${i} (${textoDe(botoes[i].bloco)}) ON`);
            assert.equal(atributo(botoes[i].tag, 'aria-pressed'), 'true');
        }
        assert.equal((HTML_CHIPS_CHEIO.match(/border-pub-accent\/\[0\.55\]/g) ?? []).length, 4);
        assert.equal((HTML_CHIPS_CHEIO.match(/bg-pub-accent\/\[0\.12\]/g) ?? []).length, 4);
    });

    await contexto.test('⚠️ DESLIGADOS: `border-pub-line-off-2` e NUNCA `pub-line-strong`', () => {
        const botoes = botoesDaLinha(HTML_CHIPS_VAZIO);
        for (const i of [6, 7, 8, 9]) {
            assertClasses(botoes[i].tag, CLASSES_CHIP_OFF, `chip ${i} (${textoDe(botoes[i].bloco)}) OFF`);
            assert.equal(atributo(botoes[i].tag, 'aria-pressed'), 'false');
            // ⚠️ Substring casaria com o PREFIXO: `border-pub-line-off` está
            // DENTRO de `border-pub-line-off-2`. Por isso a lista completa.
            const classes = classesDa(botoes[i].tag);
            assert.ok(classes.includes('border-pub-line-off-2'), `chip ${i} perdeu a borda OFF medida`);
            assert.ok(!classes.includes('border-pub-line-off'), `chip ${i} usou o token de checkbox vazio`);
            assert.ok(!classes.includes('border-pub-line-strong'), `chip ${i} usou a borda de INPUT (FID-02)`);
        }
    });

    await contexto.test('⚠️ o chip-toggle NÃO muda o peso da fonte (:114 não declara font-weight)', () => {
        for (const html of [HTML_CHIPS_CHEIO, HTML_CHIPS_VAZIO]) {
            const botoes = botoesDaLinha(html);
            for (const i of [6, 7, 8, 9]) {
                const classes = classesDa(botoes[i].tag);
                assert.ok(!classes.includes('font-semibold'), `chip ${i} virou 600 — isso é do seg()`);
                assert.ok(!classes.includes('font-medium'), `chip ${i} virou 500`);
            }
        }
    });

    await contexto.test('⚠️ o SPAN do sufixo existe SEMPRE, inclusive vazio (:123/:142)', () => {
        const botoes = botoesDaLinha(HTML_CHIPS_VAZIO);
        for (const i of [8, 9]) {
            assert.ok(
                botoes[i].bloco.includes('<span class="text-pub-neutral-txt"></span>'),
                `chip ${i} omitiu o span vazio do sufixo — o gap:6px conta com ele e o chip ficaria 6px mais estreito: ${botoes[i].bloco}`,
            );
        }
        // E os outros dois NÃO têm span de sufixo nenhum.
        for (const i of [6, 7]) {
            assert.ok(
                !botoes[i].bloco.includes('text-pub-neutral-txt'),
                `chip ${i} ganhou sufixo — só os dois com menu têm`,
            );
        }
    });

    await contexto.test('o ícone de 13px com o viewBox do protótipo, e o chevron de 12px', () => {
        const botoes = botoesDaLinha(HTML_CHIPS_CHEIO);
        for (const i of [6, 7]) {
            assert.match(botoes[i].bloco, /<svg [^>]*width="13" height="13" viewBox="0 0 16 16"/, `ícone do chip ${i}`);
            assert.match(botoes[i].bloco, /stroke-width="1.6"/);
            assert.ok(botoes[i].bloco.includes('fill-none stroke-current'), botoes[i].bloco);
            // ⚠️ Não é o Lucide: `0 0 24 24` exigiria strokeWidth × 1,5.
            assert.ok(!botoes[i].bloco.includes('0 0 24 24'), `o ícone do chip ${i} virou Lucide`);
        }
        for (const i of [8, 9]) {
            assert.match(botoes[i].bloco, /<svg [^>]*width="12" height="12" viewBox="0 0 16 16"/, `chevron ${i}`);
            assert.match(botoes[i].bloco, /stroke-width="1.7"/);
            assert.ok(botoes[i].bloco.includes('stroke-pub-txt-3'));
        }
    });

    await contexto.test('aria dos dois com menu: expanded false e haspopup true (fechados)', () => {
        const botoes = botoesDaLinha(HTML_CHIPS_CHEIO);
        for (const i of [8, 9]) {
            assert.equal(atributo(botoes[i].tag, 'aria-expanded'), 'false');
            assert.equal(atributo(botoes[i].tag, 'aria-haspopup'), 'true');
        }
        // E os dois SEM menu não têm nenhum dos dois.
        for (const i of [6, 7]) {
            assert.equal(atributo(botoes[i].tag, 'aria-expanded'), null, `chip ${i} ganhou aria-expanded sem menu`);
            assert.equal(atributo(botoes[i].tag, 'aria-haspopup'), null);
        }
    });

    await contexto.test('"Limpar filtros" (:157): sublinhado com offset 3 e sem borda', () => {
        const botoes = botoesDaLinha(HTML_CHIPS_CHEIO);
        assertClasses(botoes[10].tag, CLASSES_DE_LIMPAR, 'limpar :157');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. OS DOIS WRAPPERS `relative` E OS POPOVERS (:121/:139/:127/:146/:307)
//
// O que precisaria estar errado para falhar: o wrapper perder o `relative` (e
// o `top:38px` passar a medir da página), o popover nascer com `z-40` do
// protótipo (invertendo a ordem que o gate do header trava), a largura virar a
// do outro menu, a sombra mudar, o backdrop sumir ou nascer sem handler.
// ═══════════════════════════════════════════════════════════════════════════

const CLASSES_DO_POPOVER_BASE = [
    'absolute', 'left-0', 'top-[38px]', 'z-10', 'rounded-pub-sub', 'border',
    'border-pub-line-strong', 'bg-pub-surface', 'p-[6px]',
    'shadow-[0_16px_40px_rgba(0,0,0,0.6)]',
];

test('FID — os wrappers e os dois popovers', async (contexto) => {
    await contexto.test('os dois chips com menu vivem num wrapper `relative` (:121/:139)', () => {
        const divs = divsDaLinha(HTML_CHIPS_CHEIO);
        const wrappers = divs.filter((d) => classesDa(d.tag).join(' ') === 'relative');
        assert.equal(wrappers.length, 2, 'esperados 2 wrappers relative (Responsável e Publicada)');
        assert.ok(wrappers[0].bloco.includes('Responsável'), 'o 1º wrapper não é o de Responsável');
        assert.ok(wrappers[1].bloco.includes('Publicada'), 'o 2º wrapper não é o de Publicada');
    });

    await contexto.test('fechado: NADA no HTML (nem moldura, nem backdrop invisível)', () => {
        assert.equal(HTML_MENU_FECHADO, '', `menu fechado devolveu HTML: ${HTML_MENU_FECHADO}`);
        // ⚠️ ISCA: com `aberto: true` ele existe — a negativa não é vazia.
        assert.notEqual(HTML_MENU_RESP, '');
    });

    await contexto.test('aberto: backdrop `fixed inset-0 z-10` ANTES do popover, com handler', () => {
        const posBackdrop = HTML_MENU_RESP.indexOf('<div');
        const tagBackdrop = tagEm(HTML_MENU_RESP, posBackdrop);
        assertClasses(tagBackdrop, ['fixed', 'inset-0', 'z-10'], 'backdrop :307');
        assert.equal(atributo(tagBackdrop, 'aria-hidden'), 'true');
        assert.equal(MOD_MENU.CLASSE_DO_BACKDROP, 'fixed inset-0 z-10');

        // ⚠️ A ORDEM importa: mesmo z-10, quem pinta por cima é quem vem
        // depois. Backdrop depois do popover cobriria os itens.
        const posPopover = HTML_MENU_RESP.indexOf('<div', posBackdrop + 1);
        assert.ok(posPopover > posBackdrop, 'o popover vem ANTES do backdrop e ficaria coberto');

        // E o backdrop tem clique: a fonte registra o `onClick={fechar}`.
        assert.match(FONTE_MENU, /onClick=\{fechar\}/, 'o backdrop nasceu sem handler de clique-fora');
    });

    await contexto.test('⚠️ sem `onFechar` o backdrop NÃO nasce (véu que não fecha travaria a tela)', () => {
        const semHandler = render(Menu, {
            aberto: true,
            largura: MOD_MENU.LARGURA_RESPONSAVEL,
            children: React.createElement('button', { type: 'button' }, 'um item'),
        });
        assert.ok(!semHandler.includes('fixed inset-0'), semHandler);
        // ISCA: com handler ele está lá.
        assert.ok(HTML_MENU_RESP.includes('fixed inset-0'));
    });

    await contexto.test('as DUAS larguras medidas, cada uma no seu popover (:127/:146)', () => {
        assert.equal(MOD_MENU.LARGURA_RESPONSAVEL, 220);
        assert.equal(MOD_MENU.LARGURA_PUBLICADA, 190);

        const posResp = HTML_MENU_RESP.indexOf('<div role="menu"');
        assertClasses(
            tagEm(HTML_MENU_RESP, posResp),
            [...CLASSES_DO_POPOVER_BASE, 'w-[220px]'],
            'popover de Responsável :127',
        );
        const posPub = HTML_MENU_PUB.indexOf('<div role="menu"');
        assertClasses(
            tagEm(HTML_MENU_PUB, posPub),
            [...CLASSES_DO_POPOVER_BASE, 'w-[190px]'],
            'popover de Publicada :146',
        );
    });

    await contexto.test('⚠️ a largura é classe LITERAL, nunca concatenada', () => {
        // ⚠️ Classe montada por concatenação o scanner do Tailwind NÃO vê: o
        // popover nasceria sem largura com todos os renders passando.
        // ⚠️ `!fonte.includes('w-[')` seria um gate ERRADO (foi o 2º furo
        // desta rodada): o próprio mapa fechado contém os dois literais. O que
        // se prova é que eles são DOIS e que nenhum é interpolado.
        // ⚠️⚠️ O DELIMITADOR NÃO É ZELO: `w-[` casa DENTRO de `shado`**`w-[`**
        // `0_16px_40px_…]`, e a contagem crua dava 3 em vez de 2 — o gate
        // reprovava o código CERTO. Foi o 4º furo desta rodada e é um caso NOVO
        // da família "substring casa com sufixo", agora dentro de outra classe.
        assert.equal(
            (FONTE_MENU.match(/['"\s]w-\[/g) ?? []).length,
            2,
            'apareceu uma 3ª largura no arquivo',
        );
        assert.doesNotMatch(FONTE_MENU, /w-\[\$\{/, 'a largura virou template literal — o scanner não a vê');
        assert.doesNotMatch(FONTE_MENU, /'w-\[' ?\+/, 'a largura virou concatenação — o scanner não a vê');
        assert.deepEqual(Object.values(MOD_MENU.CLASSE_DA_LARGURA).sort(), ['w-[190px]', 'w-[220px]']);
        // Largura desconhecida cai na de Responsável, sem lançar e sem classe inventada.
        const estranha = render(Menu, {
            aberto: true,
            largura: 333,
            onFechar: () => {},
            children: React.createElement('b', null, 'x'),
        });
        assert.ok(estranha.includes('w-[220px]'), estranha);
        assert.ok(!estranha.includes('w-[333px]'), 'largura inventada virou classe');
    });

    await contexto.test('⚠️ popover sem conteúdo NÃO nasce (EXMP-01)', () => {
        for (const vazio of [null, undefined, '', '   ', [], [null], {}, 7, NaN]) {
            const html = render(Menu, { aberto: true, onFechar: () => {}, children: vazio });
            assert.equal(html, '', `children ${descreve(vazio)} desenhou moldura vazia: ${html}`);
        }
        // ISCA: com um elemento de verdade ele nasce.
        assert.notEqual(HTML_MENU_RESP, '');
    });

    await contexto.test('NÃO é portal: o conteúdo aparece no HTML do servidor', () => {
        assert.ok(HTML_MENU_RESP.includes('um item'), HTML_MENU_RESP);
        assert.ok(!FONTE_MENU.includes('createPortal'), 'virou portal — o corpo não apareceria no HTML');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. ESC E CLIQUE FORA — e a LACUNA, declarada em vez de maquiada
//
// O que precisaria estar errado para falhar: alguém tirar o
// `removeEventListener` do cleanup (listener vazando entre navegações do
// Inertia, T-180-07-D2), ou trocar a tecla.
//
// ⚠️ LACUNA DECLARADA: `renderToStaticMarkup` não roda `useEffect` nem despacha
// `keydown` — não há DOM aqui. O comportamento EM SI é conferido na validação
// visual. Este bloco prova a FONTE: que o listener é registrado e que o
// cleanup o remove. **Não finge provar o runtime.**
// ═══════════════════════════════════════════════════════════════════════════

test('T-180-07-D2 — o listener de Esc TEM cleanup (prova de FONTE, não de runtime)', async (contexto) => {
    await contexto.test('registra `keydown` no document', () => {
        assert.match(FONTE_MENU, /document\.addEventListener\('keydown'/);
    });

    await contexto.test('⚠️ e o cleanup do useEffect o REMOVE', () => {
        assert.match(FONTE_MENU, /return \(\) => document\.removeEventListener\('keydown'/);
        // As duas contagens batem: um add, um remove.
        assert.equal((FONTE_MENU.match(/addEventListener/g) ?? []).length, 1);
        assert.equal((FONTE_MENU.match(/removeEventListener/g) ?? []).length, 1);
    });

    await contexto.test('a tecla é `Escape` e está numa constante exportada', () => {
        assert.equal(MOD_MENU.TECLA_DE_FECHAR, 'Escape');
        assert.match(FONTE_MENU, /evento\.key === TECLA_DE_FECHAR/);
        // ⚠️ `Esc` (o nome antigo do IE) não serve em navegador atual.
        assert.ok(!FONTE_MENU.includes("'Esc'"), 'a fonte usa o nome antigo da tecla');
    });

    await contexto.test('⚠️ os outros dois arquivos NÃO registram listener nenhum', () => {
        // O atalho de teclado da página é do plano 180-11; chip não escuta o
        // documento.
        assert.ok(!FONTE_CHIPS.includes('addEventListener'), 'ChipsDeFiltro passou a escutar o documento');
        assert.ok(!FONTE_TRIAGEM.includes('addEventListener'), 'ChipsDeTriagem passou a escutar o documento');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. Z-INDEX — A ORDEM DO HEADER CONTINUA VALENDO
//
// O que precisaria estar errado para falhar: transpor o `z-index:40` cru do
// protótipo, que aqui empataria o popover com o menu lateral do `AppLayout`
// (z-40) e o poria ACIMA do header (z-30), invertendo a ordem que
// `publicador-header-zindex-ordem.test.js` trava.
//
// ⚠️ Extração com LOOKAHEAD de espaço/aspa: `z-3` casa DENTRO de `z-30` e
// `z-30` dentro de `z-300`. É como o gate do header faz.
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ z-index — nada acima de 10 nos 3 arquivos desta fase', async (contexto) => {
    await contexto.test('os z-index encontrados são exatamente dois, e os dois são 10', () => {
        const todos = [];
        for (const [caminho, fonte] of FONTES) {
            for (const m of fonte.matchAll(/\bz-(\d+)(?=["'\s])/g)) {
                todos.push({ caminho, valor: Number(m[1]) });
            }
        }
        assert.ok(todos.length > 0, 'nenhum z-index encontrado — o gate mediria NADA');
        for (const achado of todos) {
            assert.equal(
                achado.valor,
                10,
                `${achado.caminho}: z-${achado.valor} — o slot "filtros" é z-10 e o header é z-30`,
            );
        }
        // ⚠️ ISCA do extrator: ele acha o que existe.
        assert.equal(todos.length, 2, `esperados 2 z-index (popover e backdrop), achei ${todos.length}`);
    });

    await contexto.test('no HTML renderizado, popover e backdrop estão em z-10', () => {
        assert.equal((HTML_MENU_RESP.match(/z-10(?=["\s])/g) ?? []).length, 2);
        for (const proibido of ['z-20', 'z-30', 'z-40', 'z-50', 'z-\\[', 'z-auto']) {
            assert.doesNotMatch(HTML_MENU_RESP, new RegExp(proibido), `z-index proibido no popover: ${proibido}`);
        }
    });

    await contexto.test('⚠️ nenhum dos 3 arquivos cita o z-index do protótipo', () => {
        for (const [caminho, fonte] of FONTES) {
            assert.ok(!/\bz-40\b/.test(fonte), `${caminho}: transpôs o z-index:40 cru do protótipo`);
            assert.ok(!/\bz-30\b/.test(fonte), `${caminho}: empatou com o header (z-30)`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. "LIMPAR FILTROS" — AUSÊNCIA PROVADA COM DADO PREENCHIDO
//
// O que precisaria estar errado para falhar: alguém recalcular `algumAtivo` no
// cliente a partir das 6 props (duplicando a regra do servidor), ou desenhar o
// "Limpar" sem handler, ou desenhá-lo desabilitado.
//
// ⚠️ É o 9º dos nove: a negativa roda com TODOS os outros filtros PREENCHIDOS e
// LIGADOS. Com props vazias ela passaria por construção.
// ═══════════════════════════════════════════════════════════════════════════

test('LIST-07 — "Limpar filtros" só com `algumAtivo`, e ele vem de FORA', async (contexto) => {
    await contexto.test('⚠️ `algumAtivo: false` com TUDO ligado → "Limpar filtros" AUSENTE', () => {
        // O HTML tem os 4 chips ON e as 2 opções marcadas; só o `algumAtivo`
        // está falso. Se alguém recalcular, este caso reprova.
        assert.ok(HTML_CHIPS_SEM_ALGUM.includes('bg-pub-accent/[0.12]'), 'os chips não estão ON: caso vazio');
        assert.ok(HTML_CHIPS_SEM_ALGUM.includes('bg-pub-accent/[0.16]'), 'as opções não estão ativas: caso vazio');
        assert.ok(
            !HTML_CHIPS_SEM_ALGUM.includes('Limpar filtros'),
            '"Limpar filtros" apareceu com algumAtivo falso — alguém recalculou no cliente',
        );
        assert.equal(botoesDaLinha(HTML_CHIPS_SEM_ALGUM).length, 10, 'o botão de limpar continuou na linha');
    });

    await contexto.test('`algumAtivo: true` → presente (a negativa acima não é vazia)', () => {
        assert.ok(HTML_CHIPS_CHEIO.includes('Limpar filtros'));
        assert.equal(botoesDaLinha(HTML_CHIPS_CHEIO).length, 11);
    });

    await contexto.test('⚠️ `algumAtivo` é LIDO e nunca derivado: 2 ocorrências na fonte', () => {
        // Uma na desestruturação (com o default) e uma no `=== true`. Qualquer
        // recálculo acrescenta ocorrências.
        assert.equal(
            (FONTE_CHIPS.match(/algumAtivo/g) ?? []).length,
            2,
            'ChipsDeFiltro passou a mexer em `algumAtivo` em mais de um lugar',
        );
        assert.match(FONTE_CHIPS, /algumAtivo === true/);
    });

    await contexto.test('⚠️ sem handler o "Limpar" não nasce, e NUNCA desabilitado', () => {
        const semHandler = render(Chips, { ...PROPS_CHEIAS, onLimparFiltros: null });
        assert.ok(!semHandler.includes('Limpar filtros'), semHandler);
        // ⚠️ `/disabled/` casaria com a classe `disabled:opacity-40`. Com o `=`.
        for (const html of [HTML_CHIPS_CHEIO, HTML_CHIPS_VAZIO, HTML_TRIAGEM]) {
            assert.doesNotMatch(html, /disabled=/, 'nasceu botão desabilitado em vez de ausente');
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. "RESPONSÁVEL" SEM OPÇÃO — O CHIP NÃO NASCE
//
// O que precisaria estar errado para falhar: desenhar o chip com `opcoes: []`
// (um popover vazio é um botão sem destino, EXMP-01), ou deixar a opção sem
// `id` válido / sem nome legível entrar na lista.
// ═══════════════════════════════════════════════════════════════════════════

test('EXMP-01 — "Responsável" sem opção não é desenhado', async (contexto) => {
    await contexto.test('⚠️ `opcoes: []` com TODO o resto cheio → chip ausente', () => {
        assert.ok(!HTML_CHIPS_SEM_RESP.includes('Responsável'), HTML_CHIPS_SEM_RESP);
        // E o resto continua lá: a negativa não é vazia por construção.
        assert.ok(HTML_CHIPS_SEM_RESP.includes('Em catálogo'));
        assert.ok(HTML_CHIPS_SEM_RESP.includes('Publicada'));
        assert.ok(HTML_CHIPS_SEM_RESP.includes('Limpar filtros'));
        assert.equal(botoesDaLinha(HTML_CHIPS_SEM_RESP).length, 10);
        // ⚠️ E só UM wrapper relative sobra.
        const wrappers = divsDaLinha(HTML_CHIPS_SEM_RESP).filter((d) => classesDa(d.tag).join(' ') === 'relative');
        assert.equal(wrappers.length, 1);
    });

    await contexto.test('opção sem id inteiro positivo, sem nome, ou repetida: fora da lista', () => {
        const montadas = MOD_CHIPS.montarResponsaveis(
            [
                { id: 7, nome: 'Ana Rodrigues Silva' },
                { id: 7, nome: 'Ana de novo Silva' },
                { id: 0, nome: 'Zero Nunca Entra' },
                { id: -3, nome: 'Negativo Nunca Entra' },
                { id: 2.5, nome: 'Fracionario Nunca Entra' },
                { id: '9', nome: 'String Nunca Entra' },
                { id: 9 },
                { id: 11, nome: '   ' },
                { id: 13, nome: { toString: null } },
                null,
                'nada',
                [],
            ],
            [7],
            () => {},
        );
        assert.deepEqual(montadas.map((m) => m.id), [7]);
        assert.equal(montadas[0].nome, 'Ana Rodrigues Silva');
        assert.equal(montadas[0].marcada, true);
    });

    await contexto.test('⚠️ `selecionados` com STRING não conta como id', () => {
        assert.deepEqual(MOD_CHIPS.idsSelecionados(['7', 7, 0, -1, 2.5, null, {}, NaN]), [7]);
        assert.equal(MOD_CHIPS.sufixoDeResponsavel(['7', '9']), '', 'string virou contagem de seleção');
        const soString = render(Chips, {
            ...PROPS_VAZIAS,
            responsavel: { selecionados: ['7'], opcoes: RESPONSAVEIS, onAlternar: () => {} },
        });
        const botoes = botoesDaLinha(soString);
        assertClasses(botoes[8].tag, CLASSES_CHIP_OFF, 'chip de Responsável com seleção só de string');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. OS ITENS DOS DOIS POPOVERS (:129-:134 e :148-:150)
//
// ⚠️ POR QUE SÃO RENDERIZADOS DIRETO: o menu abre por estado LOCAL e
// `renderToStaticMarkup` só vê o estado INICIAL — os itens NUNCA aparecem no
// HTML de `ChipsDeFiltro`. Os dois helpers são exportados exatamente para que
// este bloco não mediria NADA sem eles (a saída que a Fase 178 já usou).
//
// O que precisaria estar errado para falhar: o checkbox mudar de 15px ou de
// raio, o ON do checkbox virar outro amarelo, o avatar deixar de usar
// `tomDoProduto`, o gap de 10px sumir, o `justify-between` do item de
// "Publicada" cair, ou o check do ativo virar o escuro do checkbox.
// ═══════════════════════════════════════════════════════════════════════════

const CLASSES_ITEM_RESP = [
    'flex', 'h-[34px]', 'w-full', 'cursor-pointer', 'items-center', 'gap-[10px]',
    'rounded-pub-control-sm', 'bg-transparent', 'px-[8px]', 'text-left',
    'text-[13px]', 'text-pub-txt', 'hover:bg-pub-page',
];
const CLASSES_ITEM_PERIODO = [
    'flex', 'h-[34px]', 'w-full', 'cursor-pointer', 'items-center', 'justify-between',
    'rounded-pub-control-sm', 'px-[10px]', 'text-left', 'text-[13px]', 'text-pub-txt',
    'hover:bg-pub-page',
];

test('FID — os itens do popover de "Responsável" (:129)', async (contexto) => {
    const itens = MOD_CHIPS.montarResponsaveis(RESPONSAVEIS, [7], () => {});
    const html = renderToStaticMarkup(
        React.createElement('div', null, itens.map((item) => MOD_CHIPS.itemDeResponsavel(item))),
    );
    const botoes = indicesDasTags(html, html, 'button').map((pos) => ({
        pos, tag: tagEm(html, pos), bloco: blocoEm(html, pos, 'button'),
    }));

    await contexto.test('os 3 itens, cada um na sua tag, com a geometria medida', () => {
        assert.equal(botoes.length, 3);
        assert.deepEqual(
            botoes.map((b) => textoDe(b.bloco)),
            ['ARAna Rodrigues Silva', 'BLBruno Lima Costa', 'CMCarla Mendes Souza'],
        );
        for (const [i, botao] of botoes.entries()) {
            assertClasses(botao.tag, CLASSES_ITEM_RESP, `item ${i} :129`);
            assert.equal(atributo(botao.tag, 'role'), 'menuitemcheckbox');
        }
    });

    await contexto.test('⚠️ o MARCADO é o certo: só o id 7, e `aria-checked` reflete', () => {
        assert.deepEqual(botoes.map((b) => atributo(b.tag, 'aria-checked')), ['true', 'false', 'false']);
        assert.equal((html.match(/aria-checked="true"/g) ?? []).length, 1);
        // O checkbox ON é amarelo cheio; os OFF, cinza vazado.
        assert.ok(botoes[0].bloco.includes('border-pub-accent bg-pub-accent'), botoes[0].bloco);
        assert.ok(botoes[1].bloco.includes('border-pub-line-off bg-pub-hover'), botoes[1].bloco);
        // E o check escuro só existe dentro do marcado.
        assert.equal((html.match(/stroke-pub-page/g) ?? []).length, 1);
        assert.match(botoes[0].bloco, /<svg [^>]*width="10" height="10" viewBox="0 0 16 16"/);
        assert.match(botoes[0].bloco, /stroke-width="2.4"/);
    });

    await contexto.test('o checkbox de 15px raio 4 e o avatar de 22px redondo (:130/:133)', () => {
        const spans = [...botoes[0].bloco.matchAll(/<span [^>]*>/g)].map((m) => m[0]);
        assert.equal(spans.length, 2, 'esperados 2 spans no item: checkbox e avatar');
        assertClasses(
            spans[0],
            ['grid', 'h-[15px]', 'w-[15px]', 'place-items-center', 'rounded-pub-badge', 'border',
                'border-pub-accent', 'bg-pub-accent'],
            'checkbox :130',
        );
        assertClasses(
            spans[1],
            ['grid', 'h-[22px]', 'w-[22px]', 'place-items-center', 'rounded-full', 'text-[10px]', 'font-semibold'],
            'avatar :133',
        );
    });

    await contexto.test('⚠️ IDENT-08: o tom do avatar vem de `tomDoProduto`, não recalculado', () => {
        // O `oklch` calculado tem UMA casa (`identidade.js`) e chega por
        // `style` — classe do Tailwind não comporta cor computada.
        assert.match(botoes[0].bloco, /style="background:oklch\([^"]*\);color:oklch\([^"]*\)"/, botoes[0].bloco);
        assert.match(FONTE_CHIPS, /from '\.\.\/identidade\/identidade'/);
        assert.match(FONTE_CHIPS, /tomDoProduto\(/);
        assert.match(FONTE_CHIPS, /iniciais\(/);
        for (const [caminho, fonte] of FONTES) {
            assert.ok(!fonte.includes('oklch('), `${caminho} recalcula oklch — o tom tem UMA casa`);
        }
    });

    await contexto.test('⚠️ as iniciais saem do helper, nunca de um campo do servidor', () => {
        // ⚠️ Dado de fixture com o campo errado PREENCHIDO: se o componente
        // lesse `opcao.iniciais`, apareceria "ZZ".
        const comCampo = MOD_CHIPS.montarResponsaveis(
            [{ id: 7, nome: 'Ana Rodrigues Silva', iniciais: 'ZZ' }],
            [],
            () => {},
        );
        assert.equal(comCampo[0].letras, 'AR');
        assert.ok(!FONTE_CHIPS.includes('opcao.iniciais'), 'o componente lê um campo `iniciais` do servidor');
    });
});

test('FID — os itens do popover de "Publicada" (:148)', async (contexto) => {
    const itens = MOD_CHIPS.montarPeriodos('7d', () => {});
    const html = renderToStaticMarkup(
        React.createElement('div', null, itens.map((item) => MOD_CHIPS.itemDePeriodo(item))),
    );
    const botoes = indicesDasTags(html, html, 'button').map((pos) => ({
        pos, tag: tagEm(html, pos), bloco: blocoEm(html, pos, 'button'),
    }));

    await contexto.test('as 4 opções, na ordem, com a geometria medida', () => {
        assert.equal(botoes.length, 4);
        assert.deepEqual(
            botoes.map((b) => textoDe(b.bloco)),
            ['Qualquer data', 'Hoje', 'Últimos 7 dias', 'Últimos 30 dias'],
        );
        for (const [i, botao] of botoes.entries()) {
            const esperadas = i === 2
                ? [...CLASSES_ITEM_PERIODO, 'bg-pub-page']
                : [...CLASSES_ITEM_PERIODO, 'bg-transparent'];
            assertClasses(botao.tag, esperadas, `item de período ${i} :148`);
            assert.equal(atributo(botao.tag, 'role'), 'menuitemradio');
        }
    });

    await contexto.test('⚠️ o ATIVO é o certo (`7d` é o 3º) e só ele tem o check amarelo', () => {
        assert.deepEqual(botoes.map((b) => atributo(b.tag, 'aria-checked')), ['false', 'false', 'true', 'false']);
        assert.equal((html.match(/stroke-pub-accent/g) ?? []).length, 1);
        assert.match(botoes[2].bloco, /<svg [^>]*width="12" height="12" viewBox="0 0 16 16"/);
        assert.match(botoes[2].bloco, /stroke-width="2"/);
        // ⚠️ O check do item ativo é AMARELO; o escuro é do checkbox do outro menu.
        assert.ok(!html.includes('stroke-pub-page'), 'o check do período virou o escuro do checkbox');
    });

    await contexto.test('⚠️ NENHUM `role="radiogroup"` novo (o gate da página trava em 1)', () => {
        // `publicador-paginas-anuncios-render.test.js:354` assere UM radiogroup
        // na página; um segundo reprovaria aquele gate, que não é desta fase.
        for (const alvo of [html, HTML_CHIPS_CHEIO, HTML_MENU_RESP, HTML_TRIAGEM]) {
            assert.doesNotMatch(alvo, /role="radiogroup"/);
        }
        for (const [caminho, fonte] of FONTES) {
            assert.ok(!fonte.includes('radiogroup'), `${caminho} criou um radiogroup`);
        }
        // ISCA: o detector acha o que existe — há `role="menu"` e `menuitem*`.
        assert.equal((HTML_MENU_RESP.match(/role="menu"/g) ?? []).length, 1);
        assert.equal((html.match(/role="menuitemradio"/g) ?? []).length, 4);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 9. ⚠️ LIST-22 — O TOTAL VEM DO SERVIDOR, NUNCA DA SOMA DOS CHIPS
//
// O que precisaria estar errado para falhar: alguém escrever
// `chips.reduce((a, c) => a + c.count, 0)` no título. Com `total: 7` e chips
// `[3, 5]`, a soma daria 8 — e a tela mentiria.
//
// ⚠️ `doesNotMatch(html, /8/)` seria VAZIO por construção: há "8" em
// `gap-[8px]`, em `rounded-[8px]`, em `px-[8px]` e em `0.08`. O número é
// localizado NO NÓ DO TÍTULO, por árvore.
// ═══════════════════════════════════════════════════════════════════════════

/** O `<b>` do título, por árvore: o bloco do `<h2>` e o `<b>` dentro dele. */
function numeroDoTitulo(html) {
    const pos = html.indexOf('<h2');
    assert.ok(pos !== -1, 'não achei o <h2> do título');
    const bloco = blocoEm(html, pos, 'h2');
    const m = bloco.match(/<b class="tabular-nums">([^<]*)<\/b>/);
    return { bloco, numero: m === null ? null : m[1] };
}

test('⚠️ LIST-22 — o total é `triagem.total`, nunca a soma dos chips', async (contexto) => {
    await contexto.test('total 7 com chips 3+5: a tela mostra 7, e o 8 NÃO está no título', () => {
        const { bloco, numero } = numeroDoTitulo(HTML_TRIAGEM_SOMA);
        assert.equal(numero, '7', `o título mostrou ${numero} em vez de 7: ${bloco}`);
        assert.ok(!bloco.includes('8'), `o 8 (a soma) apareceu no título: ${bloco}`);
        // E os chips continuam mostrando os próprios números.
        assert.ok(HTML_TRIAGEM_SOMA.includes('>3</b>'), 'o chip perdeu o próprio count');
        assert.ok(HTML_TRIAGEM_SOMA.includes('>5</b>'));
    });

    await contexto.test('total 11 com chips que somam 15: a tela mostra 11', () => {
        const { numero } = numeroDoTitulo(HTML_TRIAGEM);
        // ⚠️ 3+5+2+1+4 = 15, e a asserção é sobre o CONTEÚDO do `<b>`, não
        // sobre o bloco do `<h2>`: o bloco contém `text-[15px]` na classe, e
        // `bloco.includes('15')` reprovava o código CERTO. Foi o 3º furo desta
        // rodada e é a mesma família do `doesNotMatch(/0%/)` casando em "100%".
        assert.equal(numero, '11');
        assert.notEqual(numero, '15', 'o título virou a soma dos chips');
    });

    await contexto.test('⚠️ a fonte não tem NENHUM acumulador sobre os chips', () => {
        for (const proibido of ['reduce(', 'chips.length', '+= ', 'count +']) {
            assert.ok(
                !FONTE_TRIAGEM.includes(proibido),
                `ChipsDeTriagem passou a acumular (${proibido}) — LIST-22 proíbe somar os chips`,
            );
        }
        // E `total` é lido da prop em exatamente um lugar.
        assert.match(FONTE_TRIAGEM, /numeroMedido\(total\)/);
    });

    await contexto.test('⚠️ `total: null` não mostra número NENHUM — nem "0"', () => {
        const { bloco, numero } = numeroDoTitulo(HTML_TRIAGEM_NULO);
        assert.equal(numero, null, `com total nulo apareceu um número: ${bloco}`);
        assert.equal(textoDe(bloco), MOD_TRIAGEM.TITULO_SEM_TOTAL);
        assert.ok(!bloco.includes('0'), `o 0 inventado apareceu: ${bloco}`);
        assert.ok(!bloco.includes('—'), 'virou travessão em vez de frase honesta');
    });

    await contexto.test('⚠️ `total: 0` é "medido e é zero" → o estado sem pendência', () => {
        const { bloco } = numeroDoTitulo(HTML_TRIAGEM_ZERO);
        assert.equal(textoDe(bloco), MOD_TRIAGEM.SEM_PENDENCIA);
        assert.ok(!HTML_TRIAGEM_ZERO.includes(MOD_TRIAGEM.TITULO_SEM_TOTAL), 'o 0 caiu no caso do nulo');
        assert.ok(HTML_TRIAGEM_ZERO.includes('text-pub-ok-txt'), 'o check verde não apareceu');
        // E com pendência é o alerta, não o check.
        assert.ok(!HTML_TRIAGEM.includes('text-pub-ok-txt'));
        assert.ok(HTML_TRIAGEM.includes('text-pub-accent'));
    });

    await contexto.test('⚠️ total string/NaN/Infinity/fracionário/negativo NÃO vira contagem', () => {
        for (const mau of ['7', NaN, Infinity, -Infinity, 2.5, -1, {}, [], true]) {
            const html = render(Triagem, { ...PROPS_TRIAGEM, total: mau });
            const { bloco, numero } = numeroDoTitulo(html);
            assert.equal(numero, null, `total ${descreve(mau)} virou número: ${bloco}`);
            assert.doesNotMatch(bloco, RE_SUJEIRA, `sujeira com total ${descreve(mau)}`);
        }
        assert.equal(MOD_TRIAGEM.numeroMedido(0), 0, 'o zero medido tem de PASSAR');
        assert.equal(MOD_TRIAGEM.numeroMedido(null), null);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 10. A GEOMETRIA DOS CHIPS DE TRIAGEM — desvio DECLARADO
//
// O que precisaria estar errado para falhar: o chip de triagem ficar com outra
// altura que a do chip de filtro (duas alturas na mesma região da tela), a cor
// `red` e a `amber` trocarem de alfa, o ON e o OFF se confundirem, o selo
// perder o tracejado, ou o chip sem contagem inventar um "0".
// ═══════════════════════════════════════════════════════════════════════════

const GEOMETRIA_DO_CHIP = ['flex', 'h-[32px]', 'items-center', 'gap-[6px]', 'rounded-[8px]', 'border', 'px-[11px]', 'text-[12.5px]'];

test('FID — os chips de triagem usam a MESMA geometria do chip de filtro', async (contexto) => {
    await contexto.test('⚠️ a geometria tem UMA casa: é importada, não copiada', () => {
        assert.match(FONTE_TRIAGEM, /from '\.\/ChipsDeFiltro'/);
        assert.match(FONTE_TRIAGEM, /CLASSE_DO_CHIP/);
        // ⚠️ Se alguém copiar a string, estas medidas aparecem na fonte.
        for (const copiada of ['h-[32px]', 'rounded-[8px]', 'px-[11px]', 'text-[12.5px]']) {
            assert.ok(
                !FONTE_TRIAGEM.includes(copiada),
                `ChipsDeTriagem copiou ${copiada} em vez de importar CLASSE_DO_CHIP`,
            );
        }
    });

    await contexto.test('os 5 chips de motivo, um por um, com a cor da sua severidade', () => {
        const bloco = blocoEm(HTML_TRIAGEM, HTML_TRIAGEM.indexOf('<div class="mt-[12px]'), 'div');
        const botoes = indicesDasTags(HTML_TRIAGEM, bloco, 'button').map((pos) => ({
            tag: tagEm(HTML_TRIAGEM, pos), bloco: blocoEm(HTML_TRIAGEM, pos, 'button'),
        }));
        assert.equal(botoes.length, 5);
        assert.deepEqual(
            botoes.map((b) => textoDe(b.bloco)),
            ['3Pausado', '5Sem estoque', '2Ficha incompleta', '1Perdendo catálogo', '4Foto insuficiente'],
        );

        // `pausado` está ATIVO; os outros 4 não. 2 vermelhos, 3 âmbares.
        const esperadas = [
            [...GEOMETRIA_DO_CHIP, 'cursor-pointer', 'text-pub-danger-txt', 'border-pub-danger/[0.50]', 'bg-pub-danger/[0.15]'],
            [...GEOMETRIA_DO_CHIP, 'cursor-pointer', 'text-pub-danger-txt', 'border-pub-danger/[0.30]', 'bg-pub-danger/[0.05]'],
            [...GEOMETRIA_DO_CHIP, 'cursor-pointer', 'text-pub-warn-txt', 'border-pub-warn/[0.30]', 'bg-pub-warn/[0.05]'],
            [...GEOMETRIA_DO_CHIP, 'cursor-pointer', 'text-pub-warn-txt', 'border-pub-warn/[0.30]', 'bg-pub-warn/[0.05]'],
            [...GEOMETRIA_DO_CHIP, 'cursor-pointer', 'text-pub-warn-txt', 'border-pub-warn/[0.30]', 'bg-pub-warn/[0.05]'],
        ];
        for (const [i, botao] of botoes.entries()) {
            assertClasses(botao.tag, esperadas[i], `chip de motivo ${i}`);
            assert.equal(atributo(botao.tag, 'aria-pressed'), i === 0 ? 'true' : 'false');
        }
        // ⚠️ A trava: exatamente UM chip ON. "Existe um bg-danger/[0.15]"
        // passaria com a marca em qualquer irmão.
        assert.equal((HTML_TRIAGEM.match(/bg-pub-danger\/\[0\.15\]/g) ?? []).length, 1);
        assert.equal((HTML_TRIAGEM.match(/aria-pressed="true"/g) ?? []).length, 1);
    });

    await contexto.test('⚠️ `motivoAtivo` liga o chip CERTO — rodado para cada um dos 5', () => {
        for (const [i, chip] of CHIPS_5.entries()) {
            const html = render(Triagem, { ...PROPS_TRIAGEM, motivoAtivo: chip.chave });
            const bloco = blocoEm(html, html.indexOf('<div class="mt-[12px]'), 'div');
            const tags = indicesDasTags(html, bloco, 'button').map((pos) => tagEm(html, pos));
            for (const [j, tag] of tags.entries()) {
                assert.equal(
                    atributo(tag, 'aria-pressed'),
                    String(i === j),
                    `motivoAtivo=${chip.chave} marcou o chip ${j}`,
                );
            }
        }
        // Motivo desconhecido não marca nada.
        const nenhum = render(Triagem, { ...PROPS_TRIAGEM, motivoAtivo: 'inventado' });
        assert.equal((nenhum.match(/aria-pressed="true"/g) ?? []).length, 0);
    });

    await contexto.test('⚠️ `count` nulo desenha o chip SEM número (nunca "0")', () => {
        const html = render(Triagem, {
            ...PROPS_TRIAGEM,
            chips: [
                { chave: 'pausado', label: 'Pausado', count: null, cor: 'red' },
                { chave: 'sem_estoque', label: 'Sem estoque', count: 0, cor: 'red' },
            ],
        });
        const bloco = blocoEm(html, html.indexOf('<div class="mt-[12px]'), 'div');
        const blocos = indicesDasTags(html, bloco, 'button').map((pos) => blocoEm(html, pos, 'button'));
        assert.equal(textoDe(blocos[0]), 'Pausado', `o chip sem contagem inventou número: ${blocos[0]}`);
        assert.ok(!blocos[0].includes('tabular-nums'), 'nasceu o <b> do número sem número');
        // ⚠️ E o 0 MEDIDO aparece: `null` é "não medimos", `0` é "é zero".
        assert.equal(textoDe(blocos[1]), '0Sem estoque');
    });

    await contexto.test('o selo "Não avaliado": tracejado, discreto, e só com número > 0', () => {
        const bloco = blocoEm(HTML_TRIAGEM, HTML_TRIAGEM.indexOf('<div class="mt-[12px]'), 'div');
        const spans = [...bloco.matchAll(/<span [^>]*>/g)].map((m) => m[0]);
        assert.equal(spans.length, 1, 'esperado exatamente 1 span (o selo) na linha de chips');
        assertClasses(
            spans[0],
            [...GEOMETRIA_DO_CHIP, 'ml-auto', 'cursor-default', 'border-dashed', 'border-pub-line-off', 'text-pub-txt-off'],
            'selo Não avaliado',
        );
        assert.ok(bloco.includes('>6</b>'), 'o número do selo não apareceu');

        for (const mau of [null, 0, '6', NaN, -2, {}]) {
            const html = render(Triagem, { ...PROPS_TRIAGEM, naoAvaliado: mau });
            assert.ok(!html.includes('Não avaliado'), `selo apareceu com naoAvaliado ${descreve(mau)}`);
            // ISCA: os chips continuam lá — a negativa não é vazia.
            assert.ok(html.includes('Pausado'));
        }
    });

    await contexto.test('⚠️ `chips` vazio e sem selo: NENHUMA linha de chips (moldura vazia)', () => {
        const html = render(Triagem, { ...PROPS_TRIAGEM, chips: [], naoAvaliado: null });
        assert.ok(!html.includes('mt-[12px]'), `linha vazia desenhada: ${html}`);
        // Mas com selo a linha nasce: o número medido não pode sumir.
        const comSelo = render(Triagem, { ...PROPS_TRIAGEM, chips: [], naoAvaliado: 6 });
        assert.ok(comSelo.includes('mt-[12px]'));
        assert.ok(comSelo.includes('Não avaliado'));
    });

    await contexto.test('⚠️ sem handler o chip nasce SELO, não botão desabilitado', () => {
        const html = render(Triagem, { ...PROPS_TRIAGEM, onFiltrarMotivo: null });
        assert.doesNotMatch(html, /<button/, 'nasceu botão sem destino');
        assert.doesNotMatch(html, /disabled=/);
        // E a informação (as contagens) continua na tela.
        assert.ok(html.includes('>3</b>'));
        assert.ok(html.includes('Pausado'));
        assert.ok(html.includes('cursor-default'));
    });

    await contexto.test('o card e o título (desvio declarado: o protótipo não tem o bloco)', () => {
        const tag = tagEm(HTML_TRIAGEM, HTML_TRIAGEM.indexOf('<div'));
        assertClasses(tag, ['rounded-pub-table', 'border', 'border-pub-line', 'bg-pub-surface', 'p-[16px]'], 'card');
        const posH2 = HTML_TRIAGEM.indexOf('<h2');
        assertClasses(tagEm(HTML_TRIAGEM, posH2), ['text-[15px]', 'font-semibold', 'text-pub-txt'], 'título');
        assert.match(HTML_TRIAGEM, /<p class="mt-\[4px\] text-\[11\.5px\] text-pub-txt-3">/);
    });

    await contexto.test('cor desconhecida cai em `amber`, nunca em `red`', () => {
        const html = render(Triagem, {
            ...PROPS_TRIAGEM,
            chips: [{ chave: 'x', label: 'Motivo novo', count: 1, cor: 'roxo' }],
        });
        assert.ok(html.includes('text-pub-warn-txt'), html);
        assert.ok(!html.includes('pub-danger'), 'cor desconhecida virou CRÍTICO sem base');
        assert.equal(MOD_TRIAGEM.COR_PADRAO, 'amber');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 11. RENDER ADVERSO — a tela preta de 07/10/2026
//
// O que precisaria estar errado para falhar: qualquer prop ou campo chegando
// objeto / `NaN` / nulo / ausente e sendo renderizado como texto. Em 07/10/2026
// um campo-objeto do presenter derrubou a PÁGINA INTEIRA.
//
// ⚠️ A mensagem de asserção usa `descreve()`: `String({toString: null})` LANÇA
// (mataria o gate DENTRO da mensagem) e `JSON.stringify` MENTE (`NaN` → 'null').
// ═══════════════════════════════════════════════════════════════════════════

const ADVERSOS = [
    null, undefined, 0, 1, -1, 2.5, NaN, Infinity, -Infinity, '', 'x', '7',
    true, false, {}, [], [null], [{}], { toString: null },
    { valor: {} }, { ligado: 'true' }, { opcoes: 'nao e array' },
    { selecionados: 'nao e array' }, { chave: {} }, () => {},
];

test('REND — render adverso em TODA prop dos 3 componentes', async (contexto) => {
    await contexto.test('ChipsDeFiltro: cada família com 25 formas adversas', () => {
        for (const chave of Object.keys(PROPS_CHEIAS)) {
            for (const mau of ADVERSOS) {
                const props = { ...PROPS_CHEIAS, [chave]: mau };
                let html = null;
                assert.doesNotThrow(
                    () => { html = render(Chips, props); },
                    `ChipsDeFiltro LANÇOU com ${chave} = ${descreve(mau)}`,
                );
                assert.doesNotMatch(html, RE_SUJEIRA, `sujeira com ${chave} = ${descreve(mau)}`);
            }
        }
    });

    await contexto.test('ChipsDeFiltro: campos adversos DENTRO de cada família', () => {
        const CAMPOS = {
            fase: ['valor', 'onTrocar'],
            tipo: ['valor', 'onTrocar'],
            catalogo: ['ligado', 'onAlternar'],
            comVendas: ['ligado', 'onAlternar'],
            responsavel: ['selecionados', 'opcoes', 'onAlternar'],
            periodo: ['valor', 'onTrocar'],
        };
        for (const [familia, campos] of Object.entries(CAMPOS)) {
            for (const campo of campos) {
                for (const mau of ADVERSOS) {
                    const props = {
                        ...PROPS_CHEIAS,
                        [familia]: { ...PROPS_CHEIAS[familia], [campo]: mau },
                    };
                    let html = null;
                    assert.doesNotThrow(
                        () => { html = render(Chips, props); },
                        `LANÇOU com ${familia}.${campo} = ${descreve(mau)}`,
                    );
                    assert.doesNotMatch(html, RE_SUJEIRA, `sujeira com ${familia}.${campo} = ${descreve(mau)}`);
                }
            }
        }
    });

    await contexto.test('ChipsDeFiltro: `opcoes` com item quebrado, e chave desconhecida', () => {
        const QUEBRADAS = [
            [{ id: 7, nome: {} }],
            [{ id: {}, nome: 'Ana Rodrigues Silva' }],
            [{ id: 7, nome: 7 }],
            [{ id: NaN, nome: 'Ana Rodrigues Silva' }],
            [{ nome: 'Ana Rodrigues Silva' }],
            [{ id: 7 }],
            ['string solta'],
            [[]],
            [undefined],
            [{ id: 7, nome: 'Ana Rodrigues Silva', inventada: {} }],
        ];
        for (const opcoes of QUEBRADAS) {
            const props = {
                ...PROPS_CHEIAS,
                responsavel: { selecionados: [7], opcoes, onAlternar: () => {} },
            };
            let html = null;
            assert.doesNotThrow(() => { html = render(Chips, props); }, `LANÇOU com opcoes ${descreve(opcoes)}`);
            assert.doesNotMatch(html, RE_SUJEIRA, `sujeira com opcoes ${descreve(opcoes)}`);
        }
    });

    await contexto.test('ChipsDeTriagem: cada prop e cada campo dos chips', () => {
        for (const chave of Object.keys(PROPS_TRIAGEM)) {
            for (const mau of ADVERSOS) {
                const props = { ...PROPS_TRIAGEM, [chave]: mau };
                let html = null;
                assert.doesNotThrow(
                    () => { html = render(Triagem, props); },
                    `ChipsDeTriagem LANÇOU com ${chave} = ${descreve(mau)}`,
                );
                assert.doesNotMatch(html, RE_SUJEIRA, `sujeira com ${chave} = ${descreve(mau)}`);
            }
        }
        for (const campo of ['chave', 'label', 'count', 'cor']) {
            for (const mau of ADVERSOS) {
                const props = {
                    ...PROPS_TRIAGEM,
                    chips: [{ ...CHIPS_5[0], [campo]: mau }, CHIPS_5[1]],
                };
                let html = null;
                assert.doesNotThrow(
                    () => { html = render(Triagem, props); },
                    `LANÇOU com chip.${campo} = ${descreve(mau)}`,
                );
                assert.doesNotMatch(html, RE_SUJEIRA, `sujeira com chip.${campo} = ${descreve(mau)}`);
            }
        }
    });

    await contexto.test('MenuDeFiltro: `children`, `largura` e `aberto` adversos', () => {
        for (const chave of ['aberto', 'largura', 'onFechar', 'children']) {
            for (const mau of ADVERSOS) {
                const props = {
                    aberto: true,
                    largura: 220,
                    onFechar: () => {},
                    children: React.createElement('b', null, 'x'),
                    [chave]: mau,
                };
                let html = null;
                assert.doesNotThrow(
                    () => { html = render(Menu, props); },
                    `MenuDeFiltro LANÇOU com ${chave} = ${descreve(mau)}`,
                );
                assert.doesNotMatch(html, RE_SUJEIRA, `sujeira com ${chave} = ${descreve(mau)}`);
            }
        }
    });

    await contexto.test('a prop INTEIRA nula ou vazia nos 3', () => {
        for (const [nome, Componente] of [['ChipsDeFiltro', Chips], ['ChipsDeTriagem', Triagem], ['MenuDeFiltro', Menu]]) {
            for (const props of [null, undefined, {}]) {
                let html = null;
                assert.doesNotThrow(() => { html = render(Componente, props); }, `${nome} LANÇOU com props ${descreve(props)}`);
                assert.doesNotMatch(html, RE_SUJEIRA, `${nome}: sujeira com props ${descreve(props)}`);
            }
        }
    });

    await contexto.test('⚠️ ISCA do detector de sujeira: ele PEGA [object Object] e NaN', () => {
        // Sem isto o bloco acima poderia estar com a regex cega.
        assert.match(renderToStaticMarkup(React.createElement('b', null, `${{}}`)), RE_SUJEIRA);
        assert.match(renderToStaticMarkup(React.createElement('b', null, `${NaN}`)), RE_SUJEIRA);
        assert.match(renderToStaticMarkup(React.createElement('b', null, `${undefined}`)), RE_SUJEIRA);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 12. D-11 / REND-04 / FID — as regras estruturais
//
// O que precisaria estar errado para falhar: `router.*` ou `fetch(` nascer num
// dos 3 (navegação é da página), `new Date()` aparecer, JSX inline no `.map()`
// (que o Rollup já transformou em `ReferenceError` em tela neste projeto), hex
// literal, ou os botões virarem blocos copiados.
// ═══════════════════════════════════════════════════════════════════════════

test('D-11/REND-04 — as regras estruturais dos 3 arquivos', async (contexto) => {
    await contexto.test('D-11: nenhuma navegação e nenhuma chamada de rede', () => {
        for (const [caminho, fonte] of FONTES) {
            for (const proibido of ['router.', 'axios', 'fetch(', '@inertiajs/react', 'window.location']) {
                assert.ok(!fonte.includes(proibido), `${caminho} usa ${proibido} — navegar é da página`);
            }
        }
    });

    await contexto.test('nenhuma data calculada no cliente', () => {
        for (const [caminho, fonte] of FONTES) {
            for (const proibido of ['new Date(', 'Date.now(', 'toLocale', 'getTime(']) {
                assert.ok(!fonte.includes(proibido), `${caminho} calcula data — o servidor manda pronto`);
            }
        }
    });

    await contexto.test('⚠️ REND-04: o `.map()` SÓ chama helper, nunca JSX inline', () => {
        // Variável de escopo do componente usada dentro de um `.map()` já virou
        // `ReferenceError` no bundle de produção deste projeto — defeito que
        // `renderToStaticMarkup` JAMAIS pegaria.
        assert.match(FONTE_CHIPS, /\{opcoes\.map\(\(item\) => opcaoSegmentada\(item\)\)\}/);
        assert.match(FONTE_CHIPS, /\{opcoesDeResponsavel\.map\(\(item\) => itemDeResponsavel\(item\)\)\}/);
        assert.match(FONTE_CHIPS, /\{opcoesDePeriodo\.map\(\(item\) => itemDePeriodo\(item\)\)\}/);
        assert.match(FONTE_TRIAGEM, /\{motivos\.map\(\(item\) => chipDeMotivo\(item\)\)\}/);
    });

    await contexto.test('os botões saem de HELPERS, nunca de blocos copiados', () => {
        // 5 em ChipsDeFiltro: opção segmentada, chip-toggle, item de
        // responsável, item de período e "Limpar filtros".
        assert.equal(
            (FONTE_CHIPS.match(/<button/g) ?? []).length,
            5,
            'ChipsDeFiltro tem um `<button` a mais — os 11 botões começariam a divergir de aparência',
        );
        // 1 em ChipsDeTriagem (o chip de motivo; o selo é `<span>`).
        assert.equal((FONTE_TRIAGEM.match(/<button/g) ?? []).length, 1);
        // 0 em MenuDeFiltro: ele é só a casca.
        assert.equal((FONTE_MENU.match(/<button/g) ?? []).length, 0);
    });

    await contexto.test('TOK-15/FID-03/FID-04: nenhum hex, nenhuma escala nomeada, nada de ecf-*', () => {
        for (const [caminho, fonte] of FONTES) {
            assert.doesNotMatch(fonte, /#[0-9a-fA-F]{3,8}\b/, `${caminho} escreveu hex literal`);
            assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/, `${caminho} usou escala nomeada`);
            assert.doesNotMatch(fonte, /\becf-(bg|card|yellow|line|dim|mute)\b/, `${caminho} usou token antigo`);
            assert.doesNotMatch(fonte, /\bfont-display\b/);
            assert.doesNotMatch(fonte, /border-white\/\[0\.08\]/);
            assert.doesNotMatch(fonte, /\b(sm|md|lg|xl|2xl):/, `${caminho} criou breakpoint`);
            assert.doesNotMatch(fonte, /@\/Components\/ui\/select/);
            assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/);
        }
    });

    await contexto.test('⚠️ nenhum pacote novo: os popovers são `div` + `button`', () => {
        for (const [caminho, fonte] of FONTES) {
            for (const proibido of ['@radix-ui', '@headlessui', 'react-remove-scroll']) {
                assert.ok(!fonte.includes(proibido), `${caminho} importou ${proibido} — nenhum pacote novo nesta fase`);
            }
        }
    });

    await contexto.test('⚠️ o único `String(` é o guardado por `Number.isInteger`', () => {
        // `String(valor)` LANÇA com `{toString: null}` — um dos casos do
        // próprio render adverso. Aqui ele só roda sobre inteiro já provado.
        assert.equal((FONTE_CHIPS.match(/String\(/g) ?? []).length, 1);
        assert.match(FONTE_CHIPS, /Number\.isInteger\(bruto\) \? String\(bruto\) : null/);
        assert.ok(!FONTE_TRIAGEM.includes('String('), 'ChipsDeTriagem passou a coagir valor adverso');
        assert.ok(!FONTE_MENU.includes('String('));
        for (const [caminho, fonte] of FONTES) {
            assert.ok(!fonte.includes('JSON.stringify'), `${caminho} usa JSON.stringify, que MENTE com NaN`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 13. FIDELIDADE PELO CSS CONSTRUÍDO — presença de classe NÃO é fidelidade
//
// O que precisaria estar errado para falhar: a classe estar presente com o
// VALOR errado — o alfa 0,55 virar 0,5, o 0,12 virar 0,1, o `pub-line-off-2`
// apontar para outro cinza, o raio 8 virar 7, a sombra mudar de 16/40.
//
// ⚠️ AS ARMADILHAS DO MEDIDOR, TODAS APLICADAS — e uma NOVA, medida nesta
// rodada: o `seletorDe` herdado dos planos 180-05/180-06 **não escapava
// parênteses**, e por isso reportava `shadow-[0_16px_40px_rgba(0,0,0,0.6)]`
// como AUSENTE com a regra presente e correta no CSS. Qualquer valor arbitrário
// com `rgba(...)` cairia no mesmo buraco.
// ═══════════════════════════════════════════════════════════════════════════

/** Escapa TODOS os metacaracteres de regex (armadilha vii). */
function escapaRegex(texto) {
    return texto.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * O seletor que o Tailwind emite: `.` + o nome com os metacaracteres de CSS
 * escapados.
 *
 * ⚠️ `(` e `)` ENTRAM NA LISTA — foi a falta deles que fez a sonda mentir nesta
 * rodada. O Tailwind emite `.shadow-\[0_16px_40px_rgba\(0\,0\,0\,0\.6\)\]`.
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
        ...[REL_CHIPS, REL_MENU, REL_TRIAGEM].map((rel) => fs.statSync(path.resolve(RAIZ, rel)).mtimeMs),
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
        // As duas iscas foram medidas como inexistentes no repo inteiro
        // (`grep -rn -F` em `resources/`, `app/`, `tests/`,
        // `tailwind.config.js` → 0 ocorrências) ANTES de serem usadas. ⚠️
        // `top-[93px]` é de propósito a MESMA FORMA do `top-[38px]` real: se a
        // sonda estivesse lendo a forma em vez do valor, ela acharia os dois.
        for (const isca of ['bg-pub-isca-7q4', 'top-[93px]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS.css.includes(isca), `a string ${isca} apareceu no CSS`);
        }
        // E o controle: a sonda ACHA o que existe.
        assert.notEqual(regraDe('top-[38px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('⚠️ o ALFA do chip-toggle, pelo BYTE: 0,55 e 0,12 (on(b) :608)', () => {
        // Medido: aqui o modificador de opacidade sai em HEX DE 8 DÍGITOS, e o
        // último byte é a opacidade — é o único jeito de distinguir 0,55 de 0,5.
        const borda = regraDe('border-pub-accent/[0.55]');
        assert.notEqual(borda, null, 'a regra da borda ON não existe no CSS');
        assert.match(borda, /border-color:\s*#facc158c/i, borda);

        const fundo = regraDe('bg-pub-accent/[0.12]');
        assert.match(fundo, /background-color:\s*#facc151f/i, fundo);

        // ⚠️ E as formas vizinhas NÃO existem — é o que a mutação testa.
        assert.equal(regraDe('border-pub-accent/[0.5]'), null, '0.5 existe e passaria por 0.55 num gate de presença');
        assert.equal(regraDe('bg-pub-accent/[0.1]'), null);
    });

    await contexto.test('⚠️ e o ALFA da opção segmentada é OUTRO: 0,16 (seg() :535)', () => {
        const fundo = regraDe('bg-pub-accent/[0.16]');
        assert.match(fundo, /background-color:\s*#facc1529/i, fundo);
        // ⚠️ 0x29 ≠ 0x1f: são DUAS regras de ativo, e o byte prova.
        assert.ok(!/#facc151f/i.test(fundo), 'o 0,16 do segmentado virou o 0,12 do chip');
    });

    await contexto.test('⚠️ `pub-line-off-2` é #2A2A2A em DECIMAL (token sólido não sai em hex)', () => {
        const regra = regraDe('border-pub-line-off-2');
        assert.match(regra, /border-color:\s*rgb\(42 42 42/, regra);
        // E não é o `line-strong` (46 46 46) nem o `line-off` (63 63 70).
        assert.match(regraDe('border-pub-line-strong'), /rgb\(46 46 46/);
        assert.match(regraDe('border-pub-line-off'), /rgb\(63 63 70/);
    });

    await contexto.test('⚠️ a sombra do popover — com os PARÊNTESES escapados', () => {
        const regra = regraDe('shadow-[0_16px_40px_rgba(0,0,0,0.6)]');
        assert.notEqual(
            regra,
            null,
            'a sonda voltou a não escapar parênteses — foi assim que ela mentiu nesta rodada',
        );
        // ⚠️ E o cssnano COMEU o zero da frente: `0.6` saiu `.6`.
        assert.match(regra, /--tw-shadow:\s*0 16px 40px rgba\(0,0,0,\.6\)/, regra);
    });

    await contexto.test('a geometria do chip e do popover', () => {
        assert.match(regraDe('h-[32px]'), /height:\s*32px/);
        assert.match(regraDe('rounded-[8px]'), /border-radius:\s*8px/);
        assert.match(regraDe('px-[11px]'), /padding-left:\s*11px;padding-right:\s*11px/);
        assert.match(regraDe('gap-[8px]'), /gap:\s*8px/);
        assert.match(regraDe('gap-[6px]'), /gap:\s*6px/);
        assert.match(regraDe('gap-[10px]'), /gap:\s*10px/);
        assert.match(regraDe('top-[38px]'), /top:\s*38px/);
        assert.match(regraDe('z-10'), /z-index:\s*10/);
        assert.match(regraDe('w-[220px]'), /width:\s*220px/);
        assert.match(regraDe('w-[190px]'), /width:\s*190px/);
        assert.match(regraDe('rounded-pub-sub'), /border-radius:\s*10px/);
        assert.match(regraDe('p-[6px]'), /padding:\s*6px/);
        assert.match(regraDe('h-[34px]'), /height:\s*34px/);
        assert.match(regraDe('rounded-pub-control-sm'), /border-radius:\s*7px/);
        assert.match(regraDe('rounded-pub-badge'), /border-radius:\s*4px/);
        assert.match(regraDe('underline-offset-[3px]'), /text-underline-offset:\s*3px/);
    });

    await contexto.test('⚠️ substring não prova medida: 12.5 ≠ 12, 11.5 ≠ 11', () => {
        assert.match(regraDe('text-[12.5px]'), /font-size:\s*12\.5px/);
        assert.match(regraDe('text-[12px]'), /font-size:\s*12px/);
        assert.ok(!/font-size:\s*12\.5px/.test(regraDe('text-[12px]')));
        assert.match(regraDe('text-[11.5px]'), /font-size:\s*11\.5px/);
        assert.match(regraDe('text-[15px]'), /font-size:\s*15px/);
        assert.match(regraDe('text-[13px]'), /font-size:\s*13px/);
        assert.match(regraDe('text-[10px]'), /font-size:\s*10px/);
    });

    await contexto.test('(ii) `stroke` sólido SAI EM HEX LITERAL — os 3 strokes dos SVG', () => {
        assert.match(regraDe('stroke-pub-txt-3'), /stroke:\s*#8a8a90/i);
        assert.match(regraDe('stroke-pub-page'), /stroke:\s*#0a0a0a/i);
        assert.match(regraDe('stroke-pub-accent'), /stroke:\s*#facc15/i);
        assert.match(regraDe('stroke-current'), /stroke:\s*currentColor/);
    });

    await contexto.test('os 8 alfas dos chips de triagem, byte a byte', () => {
        // 0,30 → 4d · 0,05 → 0d · 0,50 → 80 · 0,15 → 26
        assert.match(regraDe('border-pub-danger/[0.30]'), /border-color:\s*#ef44444d/i);
        assert.match(regraDe('bg-pub-danger/[0.05]'), /background-color:\s*#ef44440d/i);
        assert.match(regraDe('border-pub-danger/[0.50]'), /border-color:\s*#ef444480/i);
        assert.match(regraDe('bg-pub-danger/[0.15]'), /background-color:\s*#ef444426/i);
        assert.match(regraDe('border-pub-warn/[0.30]'), /border-color:\s*#f59e0b4d/i);
        assert.match(regraDe('bg-pub-warn/[0.05]'), /background-color:\s*#f59e0b0d/i);
        assert.match(regraDe('border-pub-warn/[0.50]'), /border-color:\s*#f59e0b80/i);
        assert.match(regraDe('bg-pub-warn/[0.15]'), /background-color:\s*#f59e0b26/i);
        // ⚠️ O ON e o OFF NÃO podem compartilhar byte: 0x80 ≠ 0x4d, 0x26 ≠ 0x0d.
        assert.ok(!/#ef44444d/i.test(regraDe('border-pub-danger/[0.50]')));
        assert.ok(!/#ef44440d/i.test(regraDe('bg-pub-danger/[0.15]')));
    });

    await contexto.test('as cores de texto e o tracejado do selo', () => {
        assert.match(regraDe('text-pub-danger-txt'), /color:\s*rgb\(248 113 113/);
        assert.match(regraDe('text-pub-warn-txt'), /color:\s*rgb\(251 191 36/);
        assert.match(regraDe('text-pub-txt-off'), /color:\s*rgb\(82 82 91/);
        assert.match(regraDe('text-pub-ok-txt'), /color:\s*rgb\(74 222 128/);
        assert.match(regraDe('border-dashed'), /border-style:\s*dashed/);
        assert.match(regraDe('cursor-default'), /cursor:\s*default/);
        assert.match(regraDe('rounded-pub-table'), /border-radius:\s*14px/);
        assert.match(regraDe('p-[16px]'), /padding:\s*16px/);
        // (vi) na variante o PREFIXO faz parte do nome da classe.
        assert.match(regraDe('hover:bg-pub-page'), /background-color:\s*rgb\(10 10 10/);
    });
});
