import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 180, plano 11 — gate dos ATALHOS DE TECLADO (LIST-18) e do ESTADO NA
// URL (LIST-19) da tela de Publicações.
//
// ⚠️⚠️ O QUE ESTE GATE PROVA DE VERDADE, E O QUE ELE SÓ LÊ NO LITERAL:
//
//   PROVADO POR EXECUÇÃO (funções puras, rodando):
//     · `proximoIndice` — travas de limite, `atual` fora da lista, lista suja;
//     · `interpretarTecla` — a tabela FID-01 caso a caso E a cascata do `Escape`;
//     · `interpretarTeclaDaBusca` — `↓`/`Enter` com e sem resultado;
//     · `estaDigitando` — a composição com `ignoraAtalho()` da Fase 177;
//     · `idsNaOrdemVisual` — a ordem visual e o grupo recolhido que não entra;
//     · `blocosDaLista` da página — grupo recolhido vira bloco sem item.
//
//   LIDO NO LITERAL DA FONTE (porque o render de servidor NÃO executa efeito de
//   React, não tem `window`, não dispara `keydown` e não mede `preventDefault`):
//     o `addEventListener('keydown'`, o `removeEventListener` do cleanup, o
//     `1300` do selo copiado, o `clearTimeout`, o `preserveState`/`replace`, o
//     `try` do `navigator.clipboard`, a AUSÊNCIA de `history.pushState`/
//     `replaceState`, o `page: 1` do reset de filtro e a contagem de
//     `router.post`/`put`/`delete`/`patch` (D-11).
//   ➡️ Isto está escrito na 180-11-SUMMARY como LACUNA, não como prova. Um
//   gate de fonte não sabe se o ouvinte faz a coisa certa — ele sabe que o
//   ouvinte existe.
//
// ⚠️ REND-03: TODOS os bundles são montados ANTES do primeiro `test()`. O
// `after()` do `node --test` dispara quando os testes registrados terminam e já
// matou um arquivo inteiro deste projeto sem nenhum teste falhar.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

const CAMINHO_ATALHOS = path.join(RAIZ, 'resources/js/Components/Mlb/Publicador/publicacoes/atalhosDaLista.js');
const CAMINHO_PAGINA = path.join(RAIZ, 'resources/js/Pages/Mlb/MeusAnuncios.jsx');

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

const STUB_INERTIA = path.join(__dirname, `.atalhos-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
const nada = () => () => {};
export const router = { get: () => {}, post: () => {}, put: () => {}, patch: () => {}, delete: () => {}, reload: () => {}, visit: () => {}, on: nada };
export const usePage = () => ({ props: { auth: { user: { id: 7 } } } });
`, 'utf8');

const STUB_LAYOUT = path.join(__dirname, `.atalhos-layout-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_LAYOUT, `
import React from 'react';
export default function AppLayout({ children, title }) {
    return React.createElement('main', { 'data-titulo': title }, children);
}
`, 'utf8');

after(() => {
    for (const f of [STUB_INERTIA, STUB_LAYOUT]) fs.rmSync(f, { force: true });
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
            '@/Layouts/AppLayout': STUB_LAYOUT,
            '@inertiajs/react': STUB_INERTIA,
            '@': path.resolve(RAIZ, 'resources/js'),
        },
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

// ─── REND-03: tudo montado aqui, no topo do módulo ───
const ATALHOS = await montar('resources/js/Components/Mlb/Publicador/publicacoes/atalhosDaLista.js', 'atalhos');

const {
    ACOES, estaDigitando, proximoIndice, interpretarTecla, interpretarTeclaDaBusca, idsNaOrdemVisual,
} = ATALHOS;

// ─── Fábricas de evento ────────────────────────────────────────────────────
//
// ⚠️ `tagName` em MAIÚSCULA porque é o que o DOM entrega e é o que
// `ignoraAtalho()` compara (ele faz `toUpperCase()`, mas o fixture não pode
// depender disso para não virar teste do fixture).

/** Um alvo que NÃO é campo de digitação (uma linha da tabela, por exemplo). */
function alvoDeCorpo() {
    return { tagName: 'DIV', isContentEditable: false, closest: () => null };
}

/** O `<input>` da busca de 54px. */
function alvoDeInput() {
    return { tagName: 'INPUT', isContentEditable: false, closest: () => null };
}

function evento(key, extras = {}) {
    return { key, target: alvoDeCorpo(), ...extras };
}

function eventoDigitando(key, extras = {}) {
    return { key, target: alvoDeInput(), ...extras };
}

const SEM_ESTADO = { menuAberto: false, temSelecao: false, temFoco: false };

// ═══════════════════════════════════════════════════════════════════════════
// CASO 1 — `proximoIndice`: as travas de limite e a lista suja
// ═══════════════════════════════════════════════════════════════════════════

test('180-11 caso 1: proximoIndice trava nos limites, não circula e nunca lança', () => {
    const ids = ['a', 'b', 'c'];

    // ⚠️ NÃO CIRCULA — é a asserção que a mutação "passar a circular" quebra.
    assert.equal(proximoIndice(ids, 'c', 1), 2, '↓ no último fica no último');
    assert.equal(proximoIndice(ids, 'a', -1), 0, '↑ no primeiro fica no primeiro');

    // O movimento normal, no meio.
    assert.equal(proximoIndice(ids, 'b', 1), 2);
    assert.equal(proximoIndice(ids, 'b', -1), 0);
    assert.equal(proximoIndice(ids, 'a', 1), 1);

    // ⚠️ `atual` ausente ou fora da lista vai para 0 — e NÃO para `0 + direcao`.
    // É a asserção que a mutação `i < 0 ? -1` quebra.
    assert.equal(proximoIndice(ids, null, 1), 0, 'sem atual → 0');
    assert.equal(proximoIndice(ids, undefined, -1), 0, 'atual ausente → 0');
    assert.equal(proximoIndice(ids, 'zzz', 1), 0, 'atual fora da lista → 0');
    assert.equal(proximoIndice(ids, 'zzz', -1), 0, 'atual fora da lista, subindo → 0');

    // Lista de UM item: as duas direções ficam nele.
    assert.equal(proximoIndice(['so-um'], 'so-um', 1), 0);
    assert.equal(proximoIndice(['so-um'], 'so-um', -1), 0);

    // Lista vazia / não-array → `null` (o chamador não faz nada).
    assert.equal(proximoIndice([], 'a', 1), null);
    assert.equal(proximoIndice(null, 'a', 1), null);
    assert.equal(proximoIndice(undefined, 'a', 1), null);
    assert.equal(proximoIndice('abc', 'a', 1), null, 'string não é lista de ids');
    assert.equal(proximoIndice({ 0: 'a', length: 1 }, 'a', 1), null, 'array-like não é array');

    // `direcao` suja → `null`, sem efeito.
    assert.equal(proximoIndice(ids, 'b', 0), null);
    assert.equal(proximoIndice(ids, 'b', NaN), null);
    assert.equal(proximoIndice(ids, 'b', Infinity), null);
    assert.equal(proximoIndice(ids, 'b', '1'), null);
    assert.equal(proximoIndice(ids, 'b', null), null);
    assert.equal(proximoIndice(ids, 'b', undefined), null);

    // ⚠️ Lista SUJA e `atual` adverso: `indexOf` não coage nada, então nem o
    // objeto com `toString: null` nem o número derrubam a função.
    const sujos = ['a', 42, null, { toString: null }, 'b'];
    assert.doesNotThrow(() => proximoIndice(sujos, { toString: null }, 1));
    assert.equal(proximoIndice(sujos, { toString: null }, 1), 0, 'atual objeto → 0');
    assert.equal(proximoIndice(sujos, 42, 1), 0, 'atual número não é chave → 0');
    assert.equal(proximoIndice(sujos, 'b', 1), 4, 'o índice é o da lista CRUA, não de uma lista filtrada');
    assert.equal(proximoIndice(sujos, 'a', 1), 1);
});

// ═══════════════════════════════════════════════════════════════════════════
// CASO 2 — `interpretarTecla`: a tabela FID-01 e a CASCATA do `Escape`
// ═══════════════════════════════════════════════════════════════════════════

test('180-11 caso 2: a tabela FID-01 linha por linha', () => {
    // `/` fora de campo → foca a busca, COM preventDefault.
    const barra = interpretarTecla(evento('/'), SEM_ESTADO);
    assert.equal(barra.tipo, ACOES.FOCAR_BUSCA);
    assert.equal(barra.preventDefault, true);

    // ↓ e `j` descem; ↑ e `k` sobem. Todos com preventDefault (o protótipo
    // chama `e.preventDefault()` nas quatro).
    for (const tecla of ['ArrowDown', 'j']) {
        const acao = interpretarTecla(evento(tecla), SEM_ESTADO);
        assert.equal(acao.tipo, ACOES.MOVER, `${tecla} move`);
        assert.equal(acao.direcao, 1, `${tecla} desce`);
        assert.equal(acao.preventDefault, true, `${tecla} cancela o default`);
    }
    for (const tecla of ['ArrowUp', 'k']) {
        const acao = interpretarTecla(evento(tecla), SEM_ESTADO);
        assert.equal(acao.tipo, ACOES.MOVER, `${tecla} move`);
        assert.equal(acao.direcao, -1, `${tecla} sobe`);
        assert.equal(acao.preventDefault, true, `${tecla} cancela o default`);
    }

    // Enter COM foco abre; Enter SEM foco não tem o que abrir.
    assert.equal(
        interpretarTecla(evento('Enter'), { ...SEM_ESTADO, temFoco: true }).tipo,
        ACOES.SELECIONAR_FOCO,
    );
    assert.equal(interpretarTecla(evento('Enter'), SEM_ESTADO).tipo, ACOES.NADA);

    // ⚠️ MEDIDO NO PROTÓTIPO: o `Enter` NÃO pede preventDefault (só as setas).
    assert.equal(
        interpretarTecla(evento('Enter'), { ...SEM_ESTADO, temFoco: true }).preventDefault,
        false,
        'o protótipo não chama preventDefault no Enter',
    );
});

test('180-11 caso 2b: a CASCATA do Escape, em cascata de verdade', () => {
    // ⚠️ Os 4 casos que o critério 11 mede. A ordem é menu → busca → seleção.

    // (1) menu + digitando + selecionado AO MESMO TEMPO → só o menu.
    assert.equal(
        interpretarTecla(eventoDigitando('Escape'), { menuAberto: true, temSelecao: true, temFoco: true }).tipo,
        ACOES.FECHAR_MENU,
        'com menu aberto, Escape fecha SÓ o menu — a seleção fica de pé',
    );

    // (2) sem menu, digitando + selecionado → sai da busca.
    assert.equal(
        interpretarTecla(eventoDigitando('Escape'), { menuAberto: false, temSelecao: true, temFoco: true }).tipo,
        ACOES.SAIR_DA_BUSCA,
    );

    // (3) só selecionado → limpa a seleção.
    assert.equal(
        interpretarTecla(evento('Escape'), { menuAberto: false, temSelecao: true, temFoco: false }).tipo,
        ACOES.LIMPAR_SELECAO,
    );

    // (4) nada aberto, nada selecionado, fora de campo → nada.
    assert.equal(interpretarTecla(evento('Escape'), SEM_ESTADO).tipo, ACOES.NADA);

    // ⚠️ `Escape` nunca pede preventDefault em nenhuma das rungs.
    for (const estado of [
        { menuAberto: true }, { temSelecao: true }, {},
    ]) {
        assert.equal(interpretarTecla(evento('Escape'), estado).preventDefault, false);
    }

    // ⚠️ `Escape` vale COM modificador (o protótipo não checa modificador no
    // Escape) — sem esta asserção, a guarda de modificador poderia ser posta
    // antes da cascata e matar o Escape de quem segura Shift.
    assert.equal(
        interpretarTecla({ key: 'Escape', target: alvoDeCorpo(), shiftKey: true, ctrlKey: true }, { menuAberto: true }).tipo,
        ACOES.FECHAR_MENU,
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// CASO 3 — `/` durante digitação não rouba o foco
// ═══════════════════════════════════════════════════════════════════════════

test('180-11 caso 3: a barra digitada DENTRO da busca é só um caractere', () => {
    const acao = interpretarTecla(eventoDigitando('/'), SEM_ESTADO);
    assert.equal(acao.tipo, ACOES.NADA, 'sem isso, digitar "/" na busca rouba o foco');
    // ⚠️ E SEM preventDefault: com ele a barra nem seria escrita no campo.
    assert.equal(acao.preventDefault, false);

    // A guarda larga da Fase 177 também cobre textarea/select/contenteditable.
    for (const tagName of ['TEXTAREA', 'SELECT']) {
        assert.equal(
            interpretarTecla({ key: '/', target: { tagName, closest: () => null } }, SEM_ESTADO).tipo,
            ACOES.NADA,
            `${tagName} também é campo de digitação`,
        );
    }
    assert.equal(
        interpretarTecla({ key: '/', target: { tagName: 'DIV', isContentEditable: true, closest: () => null } }, SEM_ESTADO).tipo,
        ACOES.NADA,
        'contenteditable também',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// CASO 4 — `j`/`k` durante digitação não navegam
// ═══════════════════════════════════════════════════════════════════════════

test('180-11 caso 4: digitar "jaqueta" não desce a lista', () => {
    for (const tecla of ['j', 'k', 'ArrowDown', 'ArrowUp', 'Enter']) {
        assert.equal(
            interpretarTecla(eventoDigitando(tecla), { ...SEM_ESTADO, temFoco: true }).tipo,
            ACOES.NADA,
            `${tecla} dentro da busca não mexe na lista`,
        );
    }
});

test('180-11 caso 4b: ⌘K/Ctrl+K continua sendo do header — `k` com modificador não sobe a lista', () => {
    // ⚠️ COLISÃO MEDIDA: o `PublicadorHeader` (Fase 177, em produção) usa
    // ⌘K/Ctrl+K e a tecla N. Sem a guarda de modificador, Ctrl+K abriria a
    // busca global E subiria uma linha nesta lista.
    for (const modificador of ['ctrlKey', 'metaKey', 'altKey']) {
        assert.equal(
            interpretarTecla({ key: 'k', target: alvoDeCorpo(), [modificador]: true }, SEM_ESTADO).tipo,
            ACOES.NADA,
            `k com ${modificador} é do header, não da lista`,
        );
        assert.equal(
            interpretarTecla({ key: '/', target: alvoDeCorpo(), [modificador]: true }, SEM_ESTADO).tipo,
            ACOES.NADA,
        );
    }

    // ⚠️ CONTROLE: sem modificador, `k` SOBE. Senão a asserção acima passaria
    // com uma função que devolve `nada` para tudo.
    assert.equal(interpretarTecla(evento('k'), SEM_ESTADO).tipo, ACOES.MOVER);

    // ⚠️ `N` não está nesta tabela — a tecla do header continua só dele.
    assert.equal(interpretarTecla(evento('n'), SEM_ESTADO).tipo, ACOES.NADA);
    assert.equal(interpretarTecla(evento('N'), SEM_ESTADO).tipo, ACOES.NADA);

    // ⚠️ Caixa: `Shift+J` escreve `J` e o protótipo compara sensível a caixa.
    assert.equal(interpretarTecla(evento('J'), SEM_ESTADO).tipo, ACOES.NADA);
    assert.equal(interpretarTecla(evento('K'), SEM_ESTADO).tipo, ACOES.NADA);
});

// ═══════════════════════════════════════════════════════════════════════════
// CASO 5 — `interpretarTeclaDaBusca`
// ═══════════════════════════════════════════════════════════════════════════

test('180-11 caso 5: ↓ e Enter dentro da busca vão ao primeiro resultado', () => {
    const baixo = interpretarTeclaDaBusca({ key: 'ArrowDown' }, true);
    assert.equal(baixo.tipo, ACOES.FOCAR_PRIMEIRO);
    assert.equal(baixo.preventDefault, true);

    const entra = interpretarTeclaDaBusca({ key: 'Enter' }, true);
    assert.equal(entra.tipo, ACOES.SELECIONAR_PRIMEIRO);
    assert.equal(entra.preventDefault, true);

    // ⚠️ SEM resultado, nenhuma das duas tira o foco do campo: o usuário ainda
    // está corrigindo o termo.
    assert.equal(interpretarTeclaDaBusca({ key: 'ArrowDown' }, false).tipo, ACOES.NADA);
    assert.equal(interpretarTeclaDaBusca({ key: 'Enter' }, false).tipo, ACOES.NADA);
    assert.equal(interpretarTeclaDaBusca({ key: 'Enter' }, 0).tipo, ACOES.NADA, 'só `true` libera');
    assert.equal(interpretarTeclaDaBusca({ key: 'Enter' }, 'sim').tipo, ACOES.NADA);
    assert.equal(interpretarTeclaDaBusca({ key: 'Enter' }, null).tipo, ACOES.NADA);

    // Outra tecla, mesmo com resultado → nada (a busca continua digitando).
    for (const tecla of ['ArrowUp', 'j', 'k', '/', 'Escape', 'a', 'F13']) {
        assert.equal(interpretarTeclaDaBusca({ key: tecla }, true).tipo, ACOES.NADA, `${tecla} na busca`);
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// CASO 6 — evento adverso: nada lança
// ═══════════════════════════════════════════════════════════════════════════

test('180-11 caso 6: evento nulo / vazio / sem target → nada, sem lançar', () => {
    const adversos = [
        null, undefined, {}, 'Enter', 42, [],
        { key: null }, { key: 42 }, { key: {} }, { key: { toString: null } },
        { key: 'Escape' }, { key: '/' }, { key: 'j' },
        { key: 'Escape', target: null }, { key: '/', target: null },
        { key: '/', target: 'INPUT' }, { key: 'j', target: {} },
        { key: 'j', target: { tagName: 42 } },
        { key: '/', target: { tagName: 'DIV', closest: () => { throw new Error('DOM parcial'); } } },
    ];

    for (const bruto of adversos) {
        const rotulo = `evento ${typeof bruto}${bruto === null ? ' (nulo)' : ''}`;
        assert.doesNotThrow(() => interpretarTecla(bruto, SEM_ESTADO), rotulo);
        assert.doesNotThrow(() => interpretarTeclaDaBusca(bruto, true), rotulo);
        assert.doesNotThrow(() => estaDigitando(bruto), rotulo);
        const acao = interpretarTecla(bruto, SEM_ESTADO);
        assert.equal(typeof acao.tipo, 'string', rotulo);
    }

    // Evento sem `key` legível devolve `nada` — nunca uma ação por acidente.
    assert.equal(interpretarTecla({ key: null, target: alvoDeCorpo() }, { menuAberto: true }).tipo, ACOES.NADA);
    assert.equal(interpretarTecla({}, { temSelecao: true }).tipo, ACOES.NADA);

    // `estado` adverso não derruba a cascata nem inventa rung.
    for (const estado of [null, undefined, 'menu', 42, [], { menuAberto: 'sim' }, { temSelecao: 1 }]) {
        assert.doesNotThrow(() => interpretarTecla(evento('Escape'), estado));
        assert.equal(
            interpretarTecla(evento('Escape'), estado).tipo,
            ACOES.NADA,
            'só `true` conta como menu aberto / seleção viva',
        );
    }

    // ⚠️ Alvo SEM `tagName` legível não é "digitando" — senão o Escape cairia
    // em `sairDaBusca` e a página tentaria `blur()` num alvo que não existe,
    // deixando a seleção de pé para sempre.
    assert.equal(estaDigitando({ key: 'Escape', target: null }), false);
    assert.equal(estaDigitando({ key: 'Escape', target: {} }), false);
    assert.equal(
        interpretarTecla({ key: 'Escape', target: null }, { temSelecao: true }).tipo,
        ACOES.LIMPAR_SELECAO,
    );

    // ⚠️ CONTROLE do `estaDigitando`: com um INPUT de verdade ele é `true`.
    assert.equal(estaDigitando(eventoDigitando('a')), true);
    assert.equal(estaDigitando(evento('a')), false);
});

// ═══════════════════════════════════════════════════════════════════════════
// CASO 10 — a ISCA: tecla inventada e o controle positivo
// ═══════════════════════════════════════════════════════════════════════════

test('180-11 caso 10: ISCA — tecla inventada devolve nada, e tecla da tabela NÃO devolve nada', () => {
    // ⚠️ A isca NEGATIVA: sem ela o gate não distingue "o código está certo" de
    // "a função devolve `nada` para tudo".
    for (const inventada of ['F13', 'F24', 'MediaPlayPause', 'BrowserHome', 'Dead', 'Unidentified', 'q', 'Tab', ' ']) {
        assert.equal(interpretarTecla(evento(inventada), { menuAberto: true, temSelecao: true, temFoco: true }).tipo,
            ACOES.NADA, `${inventada} não está na tabela FID-01`);
    }

    // ⚠️ A isca POSITIVA: cada tecla da tabela devolve algo ≠ `nada`.
    const positivas = [
        ['/', SEM_ESTADO, ACOES.FOCAR_BUSCA],
        ['Escape', { menuAberto: true }, ACOES.FECHAR_MENU],
        ['Escape', { temSelecao: true }, ACOES.LIMPAR_SELECAO],
        ['ArrowDown', SEM_ESTADO, ACOES.MOVER],
        ['ArrowUp', SEM_ESTADO, ACOES.MOVER],
        ['j', SEM_ESTADO, ACOES.MOVER],
        ['k', SEM_ESTADO, ACOES.MOVER],
        ['Enter', { temFoco: true }, ACOES.SELECIONAR_FOCO],
    ];
    for (const [tecla, estado, esperado] of positivas) {
        const acao = interpretarTecla(evento(tecla), estado);
        assert.notEqual(acao.tipo, ACOES.NADA, `${tecla} tem de fazer algo`);
        assert.equal(acao.tipo, esperado, `${tecla} → ${esperado}`);
    }

    // ⚠️ E o `sairDaBusca`, que só existe dentro de campo.
    assert.equal(interpretarTecla(eventoDigitando('Escape'), SEM_ESTADO).tipo, ACOES.SAIR_DA_BUSCA);

    // ⚠️ Os 9 tipos declarados em ACOES são TODOS alcançáveis — tipo que nunca
    // sai de função nenhuma é vocabulário morto.
    assert.equal(Object.keys(ACOES).length, 9);
});

// ═══════════════════════════════════════════════════════════════════════════
// CASO 11 — `idsNaOrdemVisual`: a ordem RENDERIZADA, não a de `anuncios.data`
// ═══════════════════════════════════════════════════════════════════════════

test('180-11 caso 11: a lista de ids é a ordem VISUAL e grupo recolhido não entra', () => {
    const chaveDoItem = (item) => (item !== null && typeof item === 'object' && typeof item.id === 'string' ? item.id : null);

    const blocos = [
        { itens: [{ id: 'b' }, { id: 'a' }] },
        { itens: [] },                             // grupo RECOLHIDO: zero ids
        { itens: [{ id: 'z' }] },
    ];
    assert.deepEqual(idsNaOrdemVisual(blocos, chaveDoItem), ['b', 'a', 'z']);

    // ⚠️ Item sem chave legível NÃO entra: `↓` nunca pode focar uma linha que o
    // teclado não sabe identificar depois.
    assert.deepEqual(
        idsNaOrdemVisual([{ itens: [{ id: 'a' }, { id: 42 }, null, {}, { id: '' }, { id: 'b' }] }], chaveDoItem),
        ['a', 'b'],
    );

    // Entrada adversa → `[]`, sem lançar.
    for (const bruto of [null, undefined, 'blocos', 42, {}]) {
        assert.doesNotThrow(() => idsNaOrdemVisual(bruto, chaveDoItem));
        assert.deepEqual(idsNaOrdemVisual(bruto, chaveDoItem), []);
    }
    assert.deepEqual(idsNaOrdemVisual(blocos, null), []);
    assert.deepEqual(idsNaOrdemVisual([null, 'x', 42, { itens: 'nao-lista' }, { itens: [{ id: 'ok' }] }], chaveDoItem), ['ok']);
});

// ═══════════════════════════════════════════════════════════════════════════
// CASO 7 — ZERO classe do Tailwind e ZERO `className` em `atalhosDaLista.js`
// ═══════════════════════════════════════════════════════════════════════════

test('180-11 caso 7: atalhosDaLista.js não tem uma única classe do Tailwind', () => {
    // ⚠️ RAZÃO: o `content` do `tailwind.config.js` varre `*.jsx` e **não**
    // `*.js`. Classe escrita aqui não é varrida, não gera CSS e NÃO EXISTE PARA
    // A TELA — com todos os testes de render passando, porque a classe está no
    // HTML. Já quase aconteceu duas vezes nesta milestone.
    const fonte = lerSemComentarios(CAMINHO_ATALHOS);

    assert.doesNotMatch(fonte, /className/, 'nenhum className num módulo .js');
    assert.doesNotMatch(fonte, /\bbg-pub-/, 'nenhuma classe de fundo do vocabulário pub-*');
    assert.doesNotMatch(fonte, /\btext-pub-/);
    assert.doesNotMatch(fonte, /\bborder-pub-/);
    assert.doesNotMatch(fonte, /\brounded-pub-/);
    assert.doesNotMatch(fonte, /\bfont-pub/);
    assert.doesNotMatch(fonte, /\b(?:flex|grid|block|inline-flex)\b.*\bitems-center\b/);
    assert.doesNotMatch(fonte, /\bh-\[\d/, 'nenhum valor arbitrário de altura');
    assert.doesNotMatch(fonte, /\btext-\[\d/);
    assert.doesNotMatch(fonte, /<[A-Za-z]/, 'nenhum JSX');
    assert.doesNotMatch(fonte, /\bcn\(/, 'nem o compositor de classes');
    assert.doesNotMatch(fonte, /@\/lib\/utils/);

    // ⚠️ D-11 também aqui: teclado não escreve no Mercado Livre.
    assert.doesNotMatch(fonte, /router\./, 'nenhum router.* no módulo de teclado');

    // ⚠️ ISCA: a sonda SABE achar texto neste arquivo. Sem isto, as 12
    // negativas acima passariam com o arquivo vazio.
    assert.match(fonte, /export function interpretarTecla\(/);
    assert.match(fonte, /export function proximoIndice\(/);
    assert.match(fonte, /export function interpretarTeclaDaBusca\(/);
    assert.match(fonte, /ignoraAtalho/, 'reusa a guarda da Fase 177, não reimplementa');
});
