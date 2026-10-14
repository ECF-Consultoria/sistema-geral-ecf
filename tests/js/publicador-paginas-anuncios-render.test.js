import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import { lerSemComentarios } from './_fonte.js';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 08 — render REAL (esbuild + react-dom/server) das TRÊS
// páginas de `/mlb/anuncios` depois da troca do topo: `MeusAnuncios`,
// `AnunciosHistorico` e `AnunciarMassa`.
//
// POR QUE ESTE GATE EXISTE (T-177-35, REND-01/02): as três estão em produção e
// agora montam o `PublicadorHeader`, que lê o objeto `cabecalho` do servidor.
// `cabecalho` pode chegar **NULO** justamente nestas três (Company que não cai
// em nenhum programa do Publicador) e, numa recarga parcial do Inertia, pode
// chegar **ausente** ou com campo em outro formato. A tela preta de 07/10 deste
// projeto começou exatamente assim: campo-objeto renderizado como texto. Gate
// de FONTE não pega isso — só o render de verdade pega.
//
// O que este arquivo prova, e os outros não provam:
//   1. o header é o PRIMEIRO filho do `AppLayout`, FORA do container do corpo
//      (HEAD-14 — é dele o `-mx-6 -mt-6` que neutraliza o `p-6` do `<main>`);
//   2. o slot de ações de cada página chegou na linha 2 do header, com o MESMO
//      conteúdo de antes (HEAD-11);
//   3. as sub-abas **No ar | Histórico** continuam na TELA, e no CORPO (fora do
//      `<header>`) — a remoção delas é da Fase 180 (LIST-25), não desta;
//   4. as três sobrevivem a `cabecalho` completo, nulo, ausente e com campo
//      objeto.
//
// ⚠️ REND-03: TODOS os bundles são montados ANTES do primeiro `test()`. O
// `after()` do `node --test` já matou um arquivo inteiro sem nenhum teste
// falhar neste projeto.
//
// ⚠️ As asserções de posição partem o HTML por elemento (tag de abertura
// extraída, `<nav>` do header isolada, corpo = o que vem depois de
// `</header>`). Conferir se a classe existe "em algum lugar" do HTML não prova
// nada: foi assim que 4 mutações furaram o gate do plano 177-04 e 7 o do 177-06.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

const STUB_INERTIA = path.join(__dirname, `.paginas-anuncios-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
const nada = () => () => {};
export const router = { get: () => {}, post: () => {}, put: () => {}, patch: () => {}, delete: () => {}, reload: () => {}, visit: () => {}, on: nada };
export const usePage = () => ({ props: { auth: { user: { id: 7 } } } });
`, 'utf8');

const STUB_LAYOUT = path.join(__dirname, `.paginas-anuncios-layout-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_LAYOUT, `
import React from 'react';
export default function AppLayout({ children, title }) {
    return React.createElement('main', { 'data-titulo': title }, children);
}
`, 'utf8');

// A grade do AnunciarMassa é canvas puro (`@glideapps/glide-data-grid`) e
// importa um CSS no topo do módulo — bundle de CSS dentro de bundle ESM de
// Node não roda. Ela também NÃO é assunto deste gate: com `rascunhos: []` a
// página nem a monta (`aba` é null). O stub mantém o grafo de import honesto
// sem arrastar o canvas para dentro do teste.
const STUB_GRADE = path.join(__dirname, `.paginas-anuncios-grade-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_GRADE, `
import React from 'react';
export default function GradeAnuncioGlide() {
    return React.createElement('div', { 'data-grade-stub': 'true' });
}
`, 'utf8');

after(() => {
    for (const f of [STUB_INERTIA, STUB_LAYOUT, STUB_GRADE]) fs.rmSync(f, { force: true });
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
            '@/Pages/Mlb/GradeAnuncioGlide': STUB_GRADE,
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
// ⚠️ O MÓDULO inteiro, e não só o default: o plano 180-10 exporta
// `grupoAberto()` (a regra de LIST-09) para que ela tenha caso de teste — no
// render de servidor `colapsados` é sempre `[]` e a regra não é observável.
const MOD_MEUS = await montar('resources/js/Pages/Mlb/MeusAnuncios.jsx', 'pg-meus');
const MEUS = MOD_MEUS.default;
const HISTORICO = (await montar('resources/js/Pages/Mlb/AnunciosHistorico.jsx', 'pg-historico')).default;
const MASSA = (await montar('resources/js/Pages/Mlb/AnunciarMassa.jsx', 'pg-massa')).default;

// ─── Fixtures ──────────────────────────────────────────────────────────────

const cabecalhoCompleto = () => ({
    aba: 'publicacoes',
    empresa: {
        chave: 'company-459',
        tipo: 'company',
        id: 459,
        nome: 'Dev 02 Testes API',
        identificador: 'company-459',
        programa: 'polos',
        programa_rotulo: 'Polos',
        company_id: 459,
        token: 'ativo',
        link_reconexao: null,
        portal: { situacao: 'sincronizado', novas: 0, sincronizado_em: '2026-10-11T10:00:00Z' },
        conta_nome: 'ECFDEV02',
        conta_ml_id: '123456789',
    },
    company_id: 459,
    contadores: { produtos: 32, publicacoes: 58, publicacoes_pedem_acao: 2, alavancas: 4 },
    integracoes: {
        mercado_livre: { estado: 'ativo', conta_nome: 'ECFDEV02', conta_ml_id: '123456789', link_reconexao: null },
        erp: { declarado: 'Bling' },
        portal: { situacao: 'sincronizado', novas: 0, sincronizado_em: '2026-10-11T10:00:00Z' },
        coleta: { coletado_em: '2026-10-11T11:35:00Z', horas: 1, defasado: false, nunca_coletado: false },
    },
});

/** `cabecalho` com CAMPO OBJETO onde a tela espera texto — a tela preta de 07/10. */
const cabecalhoSujo = () => {
    const base = cabecalhoCompleto();
    return {
        ...base,
        empresa: { ...base.empresa, nome: { pt: 'objeto no lugar do nome' }, chave: { id: 9 }, programa_rotulo: ['a', 'b'] },
        contadores: { produtos: 'muitos', publicacoes: null, publicacoes_pedem_acao: Number.NaN },
    };
};

// ⚠️ Fase 180, plano 10 — o fixture GANHOU as props novas do layout de
// Publicações (`abas`, `contagens`, `grupos`, `rodape`, `sincronizacao`,
// `faixaSucesso`) e as 7 chaves novas de `filtros`. NENHUMA prop de hoje saiu
// daqui: é este objeto que prova o contrato do servidor com a tela, e tirar
// uma chave faria o gate passar sem ela chegar.
//
// ⚠️ `faixaSucesso: null` é o valor REAL de produção, medido no plano 180-04
// por três caminhos independentes — não é fixture preguiçoso. O caso com
// resumo preenchido existe no §6, para provar que a ligação é de verdade.
const propsMeus = (extras = {}) => ({
    empresa: { id: 459, nome: 'Dev 02 Testes API' },
    conta: { chave: 'company-459', nome: 'Dev 02 Testes API' },
    sub: 'publicados',
    subTotais: { publicados: 3, rascunhos: 1 },
    anuncios: { data: [], links: [] },
    rascunhos: { data: [], links: [] },
    triagem: { total: 0, chips: [] },
    filtros: {
        busca: '', status: 'acionaveis', motivo: null,
        com_venda: false, fase: null, tipo: null, catalogo: false,
        responsavel: [], periodo: null, visao: 'grupo', sel: null,
    },
    abas: [
        { chave: 'todos', rotulo: 'Todas', total: 3, comDot: false },
        { chave: 'ativos', rotulo: 'Ativas', total: 2, comDot: false },
        { chave: 'pausados', rotulo: 'Pausadas', total: 1, comDot: false },
        { chave: 'com_erro', rotulo: 'Com erro', total: 0, comDot: false },
        // ⚠️ REAPONTADO na Fase 186, plano 01 (ABA-03). VALOR ANTIGO:
        // `rotulo: 'Em revisão'`. Esta fixture ESPELHA a prop `abas` do servidor
        // (`MlbAnuncioController::meus()`); deixá-la no rótulo antigo faria o
        // teste render uma tela que o servidor não manda mais.
        // ⚠️ A CHAVE `em_revisao` não muda (TERR-08).
        { chave: 'em_revisao', rotulo: 'Em análise pelo ML', total: 0, comDot: false },
        { chave: 'rascunhos', rotulo: 'Rascunhos', total: 1, comDot: false },
    ],
    contagens: {
        todos: 3, acionaveis: 3, ativos: 2, pausados: 1,
        em_revisao: 0, encerrados: 0, com_erro: 0, rascunhos: 1,
    },
    grupos: [],
    rodape: { visiveis: 0, total: 3, produtos: 0 },
    sincronizacao: {
        coletado_em: '2026-10-13T11:35:00Z', horas: 2, defasado: false,
        nunca_coletado: false, rotulo: 'há 2 horas',
    },
    faixaSucesso: null,
    defasagem: { horas: 2, defasado: false, nunca_coletado: false, motivo: null },
    saudeMlDisponivel: true,
    rotacaoN: 16,
    skuNaoColetado: false,
    ...extras,
});

const propsHistorico = (extras = {}) => ({
    empresa: { id: 459, nome: 'Dev 02 Testes API' },
    conta: { chave: 'company-459', nome: 'Dev 02 Testes API' },
    grupos: { data: [], links: [] },
    resumo: { total_anuncios: 0, total_lotes: 0 },
    filtros: { busca: '' },
    ...extras,
});

const propsMassa = (extras = {}) => ({
    empresa: { id: 459, nome: 'Dev 02 Testes API' },
    rascunhos: [],
    produtos: [],
    conta: { chave: 'company-459', nome: 'Dev 02 Testes API' },
    ...extras,
});

// ⚠️ Fase 180, plano 10 — REAPONTE DE VALOR em duas colunas novas, e as duas
// são ENDURECIMENTO, não afrouxamento:
//
// · `headers`: a contagem esperada de `<header>` na página. Era **1** para as
//   três, implícita no `assert.equal(…, 1)` de dentro do laço. Em
//   `MeusAnuncios` passou a ser **2**, porque o cabeçalho da PÁGINA (LIST-01:
//   H1 "Publicações" + "● Sincronizado há X") é um `<header>`
//   semântico, como no protótipo (`Publicacoes v2.dc.html:31`). ⚠️ Sem o valor
//   por página, o laço reprovaria o código CERTO — e, pior, trocar o esperado
//   para 2 nas três deixaria as outras duas perderem o header global em
//   silêncio.
// · `container`: o literal que localiza o container do corpo. Era um só
//   (`mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8`) para as três.
//   `MeusAnuncios` passou para o container do protótipo (1600px de conteúdo com
//   36px de padding e `min-width:1240px`), que precisa do neutralizador do
//   `p-6` do `<main>` em DOIS elementos — `-mx-6` e `mx-auto` disputam a mesma
//   margem lateral. É o mesmo reaponte que o plano 179-09 fez na
//   `VisaoGeral.jsx` do gate de vocabulário.
const PAGINAS = [
    {
        nome: 'MeusAnuncios',
        Componente: MEUS,
        props: propsMeus,
        titulo: 'Meus Anúncios',
        headers: 2,
        container: '<div class="-mx-6 -mb-6">',
    },
    {
        nome: 'AnunciosHistorico',
        Componente: HISTORICO,
        props: propsHistorico,
        titulo: 'Histórico de anúncios',
        headers: 1,
        container: '<div class="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8',
    },
    {
        nome: 'AnunciarMassa',
        Componente: MASSA,
        props: propsMassa,
        titulo: 'Anunciar em massa',
        headers: 1,
        container: '<div class="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8',
    },
];

// ─── Ferramentas de partição do HTML (nunca `includes` global) ─────────────

const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

/** A tag de ABERTURA do `<header>` — é nela que moram as classes do HEAD-14. */
function tagDoHeader(html) {
    const achado = html.match(/<header[^>]*>/);
    assert.ok(achado, 'nenhum <header> na saída — a página não montou o PublicadorHeader');
    return achado[0];
}

/** Só o conteúdo da `<nav>` do header (linha 2: as 4 abas + o slot de ações). */
function navDoHeader(html) {
    const inicio = html.indexOf('<nav aria-label="Abas da conta"');
    assert.notEqual(inicio, -1, 'a <nav> do header não está na saída');
    const fim = html.indexOf('</nav>', inicio);
    assert.notEqual(fim, -1, '<nav> do header sem fechamento');
    return html.slice(inicio, fim);
}

/**
 * O bloco do SEGUNDO `<header>` da saída — o cabeçalho DA PÁGINA (LIST-01),
 * desenhado por `publicacoes/CabecalhoDePublicacoes.jsx`.
 *
 * ⚠️ Localização por ÁRVORE com índices ABSOLUTOS, e não por lista de classes
 * nem por `html.indexOf('<header')`: o primeiro `<header` é o GLOBAL do
 * Publicador, e pegar o irmão errado foi o furo que o learnings §4.7 registra
 * (quatro gates do ERP mediam a linha do Mercado Livre). `<header>` não
 * aninha, logo o fechamento do segundo é o segundo `</header>`.
 */
function headerDaPagina(html) {
    const primeiro = html.indexOf('<header');
    assert.notEqual(primeiro, -1, 'a saída não tem nenhum <header>');
    const inicio = html.indexOf('<header', primeiro + 1);
    assert.notEqual(inicio, -1, 'a saída não tem um SEGUNDO <header> — o cabeçalho da página (LIST-01) sumiu');
    const fim = html.indexOf('</header>', inicio);
    assert.notEqual(fim, -1, 'o segundo <header> não fecha');
    return html.slice(inicio, fim + '</header>'.length);
}

/** O CORPO da página: tudo o que vem depois do `</header>`. */
function corpoDaPagina(html) {
    const fim = html.indexOf('</header>');
    assert.notEqual(fim, -1, 'a saída não tem </header>');
    return html.slice(fim + '</header>'.length);
}

// ═══ 1. Posição do header nas 3 páginas (HEAD-14) ══════════════════════════

for (const { nome, Componente, props, titulo, headers, container } of PAGINAS) {
    test(`${nome} — o header é o PRIMEIRO filho do AppLayout, fora do container do corpo (HEAD-14)`, async (contexto) => {
        const html = render(Componente, props({ cabecalho: cabecalhoCompleto() }));

        await contexto.test('o <header> abre imediatamente dentro do <main>, sem nada antes', () => {
            assert.match(
                html,
                /^<main data-titulo="[^"]*"><header /,
                'algo entrou entre o <main> e o <header> — dentro do container do corpo o `-mx-6` brigaria com o `px-4` dele',
            );
            // ⚠️ REAPONTE DE VALOR (Fase 180, plano 10): era `1` fixo para as
            // três. Ver o comentário de `PAGINAS`. O valor por página é o que
            // mantém a asserção capaz de reprovar nas outras duas.
            assert.equal(
                (html.match(/<header/g) ?? []).length,
                headers,
                `esperados exatamente ${headers} <header> em ${nome}`,
            );
        });

        await contexto.test('o PRIMEIRO <header> é o GLOBAL (o que carrega o sticky e o -mx-6)', () => {
            // ⚠️ O ENDURECIMENTO que o reaponte acima exige: com 2 headers
            // permitidos em MeusAnuncios, "tem 2" não prova mais que o global
            // é o de cima. Esta asserção prova.
            assert.match(tagDoHeader(html), /sticky/,
                'o primeiro <header> da página não é o global do Publicador');
        });

        await contexto.test('o título da página continua chegando ao AppLayout', () => {
            assert.ok(html.startsWith(`<main data-titulo="${titulo}">`), `o title da página mudou: ${html.slice(0, 80)}`);
        });

        await contexto.test('as classes que neutralizam o `p-6` do <main> estão NA TAG do header', () => {
            const tag = tagDoHeader(html);
            // ⚠️ `z-30` desde 12/10/2026 (261012-mbw), NÃO `z-50` — ver
            // `publicador-header-zindex-ordem.test.js`.
            for (const classe of ['sticky', 'top-0', 'z-30', '-mx-6', '-mt-6', 'min-w-[1240px]']) {
                // `\b` não serve: `top-0` é prefixo de `top-0.5`, e `-mx-6` de
                // `-mx-6/5`. A classe tem de terminar em espaço ou em aspas.
                assert.match(tag, new RegExp(classe.replace(/[[\]/\\^$*+?.()|{}]/g, '\\$&') + '[\\s"]'),
                    `a classe ${classe} saiu da tag do <header> de ${nome}`);
            }
        });

        await contexto.test('o container do corpo vem IMEDIATAMENTE depois do </header>, com os breakpoints de hoje (HEAD-15)', () => {
            // ⚠️ REAPONTE DE VALOR (Fase 180, plano 10): o literal era único
            // para as três páginas. O que a asserção garante continua sendo o
            // MESMO — o container do corpo é o irmão SEGUINTE do header —, e o
            // que mudou é o literal com que cada página é localizada. Ver o
            // comentário de `PAGINAS`.
            assert.ok(
                html.includes(`</header>${container}`),
                `o container do corpo de ${nome} mudou de classe ou deixou de ser o irmão seguinte do header.`
                + ` Esperado logo depois de </header>: ${container}`,
            );
        });
    });
}

// ═══ 2. `cabecalho` nulo, ausente e sujo — as 3 páginas sobrevivem ═════════

for (const { nome, Componente, props, headers } of PAGINAS) {
    test(`${nome} — sobrevive a cabecalho completo, NULO, AUSENTE e com campo OBJETO (tela preta de 07/10)`, async (contexto) => {
        const casos = [
            ['completo', props({ cabecalho: cabecalhoCompleto() })],
            ['nulo', props({ cabecalho: null })],
            ['ausente', props()],
            ['campo objeto', props({ cabecalho: cabecalhoSujo() })],
            ['cabecalho em formato inesperado (string)', props({ cabecalho: 'nada disso' })],
        ];

        for (const [rotulo, entrada] of casos) {
            await contexto.test(`cabecalho ${rotulo} — não lança e não imprime [object Object]`, () => {
                let html;
                assert.doesNotThrow(() => { html = render(Componente, entrada); }, `cabecalho ${rotulo} derrubou ${nome}`);
                assert.doesNotMatch(html, /\[object Object\]/);
                assert.doesNotMatch(html, /NaN/, 'contador em formato inesperado não pode virar "NaN" na tela');
                // O header tem de continuar lá: sem conta ele desabilita os
                // botões e explica, nunca desaparece.
                // ⚠️ REAPONTE DE VALOR (Fase 180, plano 10): era `1` fixo —
                // ver o comentário de `PAGINAS`. Em `MeusAnuncios` são 2 (o
                // global + o cabeçalho da página, LIST-01), e o cabeçalho da
                // página também não pode desaparecer com `cabecalho` sujo.
                assert.equal(
                    (html.match(/<header/g) ?? []).length,
                    headers,
                    `o <header> sumiu (ou sobrou) com cabecalho ${rotulo}: esperados ${headers} em ${nome}`,
                );
            });
        }
    });
}

// ═══ 3. Slot de ações (HEAD-11) — o MESMO conteúdo de antes, na linha 2 ════

test('MeusAnuncios — "Atualizar agora" está no slot de ações do header, dentro da <nav> da linha 2', async (contexto) => {
    const html = render(MEUS, propsMeus({ cabecalho: cabecalhoCompleto() }));

    await contexto.test('o botão está DENTRO da <nav> do header, não solto no corpo', () => {
        assert.match(navDoHeader(html), /Atualizar agora/,
            'o slot de ações do header perdeu o "Atualizar agora" — era a ação que o BarraDaConta carregava');
    });

    await contexto.test('o <div> do slot de ações existe (o header só o desenha quando há ações)', () => {
        assert.match(navDoHeader(html), /<div class="flex flex-none items-center gap-2">/);
    });

    await contexto.test('o botão NÃO nasce desabilitado quando não há atualização em curso', () => {
        const nav = navDoHeader(html);
        const botao = nav.match(/<button[^>]*>(?:(?!<\/button>)[\s\S])*?Atualizar agora/);
        assert.ok(botao, 'o <button> do "Atualizar agora" não foi achado na <nav>');
        // `/disabled/` sem o `=` casaria a palavra dentro de qualquer classe —
        // asserção vazia (GATE-05).
        assert.doesNotMatch(botao[0], /disabled=/);
    });
});

// ═══ 3b. ⚠️ CIRURGIA 3 (plano 180-10B) — o cabeçalho DA PÁGINA perdeu o
// segundo botão "Atualizar", e o do header global ficou.
//
// Decisão do usuário em 14/10/2026 (checkpoint do 180-10): a tela mostrava
// DOIS botões de atualizar lado a lado com a MESMA ação (medido: os dois
// recebiam `atualizando`/`cooldown`/`onClick={atualizarAgora}` idênticos, e
// há UM `router.post` só). Sai o da página; fica o do header, que serve as 9
// páginas do módulo e é contrato gateado da Fase 177.
//
// O que precisaria estar errado para este teste falhar: o botão voltar ao
// cabeçalho da página; o cabeçalho da página perder o H1 ou o
// "● Sincronizado"; ou o header global perder o "Atualizar agora".
test('180-10B — o cabeçalho da PÁGINA não tem mais botão nenhum, e o do header global continua', async (contexto) => {
    const html = render(MEUS, propsMeusCheias());

    await contexto.test('⚠️ ISCA: a sonda do rótulo tem dentes (e não casa com "Atualizar agora")', () => {
        // `Atualizar` é PREFIXO de `Atualizar agora` e de `Atualizando…`:
        // `/Atualizar/` seria vazia por construção (learnings §4.4). A borda é
        // o `</button>`.
        const RE = /Atualizar<\/button>/;
        assert.match(renderToStaticMarkup(React.createElement('button', null, 'Atualizar')), RE,
            'a sonda não reconhece o rótulo que ela existe para proibir');
        assert.doesNotMatch(renderToStaticMarkup(React.createElement('button', null, 'Atualizar agora')), RE,
            'a sonda casaria com o botão que FICA — ela proibiria o certo');
        assert.doesNotMatch(renderToStaticMarkup(React.createElement('button', null, 'Atualizando…')), RE);
    });

    await contexto.test('ZERO <button> dentro do cabeçalho da página', () => {
        const cabecalho = headerDaPagina(html);
        assert.equal(
            (cabecalho.match(/<button/g) ?? []).length,
            0,
            'nasceu um botão no cabeçalho da página. O "Atualizar" de 36px do protótipo saiu em 14/10/2026 por'
            + ' decisão do usuário (eram dois botões com a mesma ação) — não restaurar sem decisão nova dele',
        );
        assert.doesNotMatch(cabecalho, /Atualizar<\/button>/);
    });

    await contexto.test('e o cabeçalho da página continua com o H1 e o "● Sincronizado" (só o botão saiu)', () => {
        const cabecalho = headerDaPagina(html);
        assert.match(cabecalho, /Publicações/, 'o H1 da tela saiu junto com o botão — ele FICA (LIST-01)');
        assert.match(cabecalho, /Sincronizado/,
            'o "● Sincronizado há X" saiu junto com o botão. Ele é INFORMAÇÃO, não ação, e fica');
        // E o dot verde, que é o "●" da frase.
        assert.match(cabecalho, /bg-pub-ok/, 'o dot verde do "● Sincronizado" sumiu');
    });

    await contexto.test('⚠️ o botão que FICA continua na <nav> do header global — não perdemos os dois', () => {
        assert.match(navDoHeader(html), /Atualizar agora/,
            'a decisão foi tirar o botão DA PÁGINA e manter o do header. Este sumiu');
    });
});

test('AnunciarMassa — "‹ Voltar aos produtos" está no slot de ações do header, com o MESMO destino', async (contexto) => {
    const html = render(MASSA, propsMassa({ cabecalho: cabecalhoCompleto() }));
    const nav = navDoHeader(html);

    await contexto.test('o link está dentro da <nav> do header', () => {
        assert.match(nav, /Voltar aos produtos/);
    });

    await contexto.test('o destino continua sendo a seleção de produtos da conta, com a chave da conta', () => {
        const ancora = nav.match(/<a [^>]*Voltar[^>]*>/) ?? nav.match(/<a [^>]*>(?=[^<]*Voltar)/);
        assert.ok(ancora || /mlb\.anuncios\.publicador\.produtos/.test(nav), 'a âncora do "Voltar aos produtos" sumiu');
        assert.match(nav, /mlb\.anuncios\.publicador\.produtos/);
        assert.match(nav, /company-459/, 'o link perdeu a chave da conta — iria para um destino inventado');
    });
});

test('AnunciosHistorico — nenhum slot de ações (a página não tem ação de cabeçalho)', () => {
    const html = render(HISTORICO, propsHistorico({ cabecalho: cabecalhoCompleto() }));
    assert.doesNotMatch(navDoHeader(html), /<div class="flex flex-none items-center gap-2">/,
        'esta página não tem ações; o header não deve desenhar o <div> do slot vazio');
});

// ═══ 4. As sub-abas No ar | Histórico CONTINUAM NA TELA (LIST-25) ═════════
//
// Elas saem na Fase 180, com a guarda de provar que o filtro de status alcança
// o que "Histórico" alcança. Até lá, estas asserções são o que impede que a
// capacidade seja perdida em silêncio junto com o `AbasDaConta`.

// ⚠️⚠️ CIRURGIA DA FASE 180 (plano 10) — `MeusAnuncios` SAIU deste array.
//
// VALOR ANTIGO: as DUAS páginas estavam aqui, e cada uma rodava os 4
// sub-testes abaixo (radiogroup no corpo, os 2 rótulos, a certa marcada, o
// caso sem Company).
// VALOR NOVO: só `AnunciosHistorico`, com os 4 sub-testes INTACTOS.
// POR QUE: LIST-25. O segmentado "No ar | Histórico" saiu da tela de
// Publicações em 13/10/2026, por decisão informada do usuário — entre TRÊS
// opções e com o aviso literal de que a rota do Histórico ficaria ÓRFÃ, sem
// nenhum link vivo, até a fase que a reconstruísse. Ele escolheu "sai agora,
// Histórico vira fase própria".
// ⚠️ NÃO RESTAURAR. E a ausência do atalho é cobrada por um teste PRÓPRIO,
// logo abaixo — com as props CHEIAS, porque ausência provada com dado vazio é
// vazia por construção.
const COM_SUBABAS = [
    { nome: 'AnunciosHistorico', Componente: HISTORICO, props: propsHistorico, marcada: 'Histórico', outra: 'No ar' },
];

for (const { nome, Componente, props, marcada, outra } of COM_SUBABAS) {
    test(`${nome} — as sub-abas No ar | Histórico continuam na tela, no CORPO, com a certa marcada`, async (contexto) => {
        const html = render(Componente, props({ cabecalho: cabecalhoCompleto() }));
        const corpo = corpoDaPagina(html);

        await contexto.test('o radiogroup está no corpo, nunca dentro do <header>', () => {
            assert.match(corpo, /role="radiogroup"/);
            assert.equal((html.match(/role="radiogroup"/g) ?? []).length, 1, 'esperado UM radiogroup de sub-abas');
            const antesDoFimDoHeader = html.slice(0, html.indexOf('</header>'));
            assert.doesNotMatch(antesDoFimDoHeader, /role="radiogroup"/,
                'as sub-abas entraram DENTRO do header — elas são corpo antigo (HEAD-15) e saem na Fase 180');
        });

        await contexto.test('os dois rótulos existem, e cada um num role="radio"', () => {
            const radios = corpo.match(/<button[^>]*role="radio"[^>]*>[^<]*/g) ?? [];
            assert.equal(radios.length, 2, 'esperados exatamente 2 radios (No ar e Histórico)');
            assert.ok(radios.some((r) => r.includes('No ar')), '"No ar" sumiu');
            assert.ok(radios.some((r) => r.includes('Histórico')), '"Histórico" sumiu');
        });

        await contexto.test(`"${marcada}" é a marcada e "${outra}" não é`, () => {
            const radios = corpo.match(/<button[^>]*role="radio"[^>]*>[^<]*/g) ?? [];
            const daMarcada = radios.find((r) => r.includes(marcada));
            const daOutra = radios.find((r) => r.includes(outra));
            assert.match(daMarcada, /aria-checked="true"/);
            assert.match(daOutra, /aria-checked="false"/);
        });

        await contexto.test('sem Company não há destino: o radiogroup não é desenhado (e a tela não quebra)', () => {
            let semCompany;
            assert.doesNotThrow(() => {
                semCompany = render(Componente, props({ cabecalho: cabecalhoCompleto(), empresa: {} }));
            });
            assert.doesNotMatch(semCompany, /role="radiogroup"/,
                'as duas rotas são por `company`; sem ela o botão levaria a um destino inventado');
            assert.equal((semCompany.match(/<header/g) ?? []).length, 1, 'o header sumiu junto — não deveria');
        });
    });
}

test('AnunciarMassa — não tem sub-abas de publicações (nunca teve)', () => {
    const html = render(MASSA, propsMassa({ cabecalho: cabecalhoCompleto() }));
    assert.doesNotMatch(html, /role="radiogroup"/);
});

test('180: MeusAnuncios NÃO tem mais o segmentado "No ar | Histórico" — remoção autorizada (LIST-25)', async (contexto) => {
    // ⚠️ VALOR ANTIGO: `assert.match(corpo, /role="radiogroup"/)` e a contagem
    // de radiogroups do HTML igual a **1**, com os dois rótulos num
    // `role="radio"` cada. VALOR NOVO: **0**.
    // POR QUE: LIST-25, decisão informada do usuário em 13/10/2026 (três
    // opções, com o aviso de que a rota ficaria órfã).
    // ⚠️ NÃO RESTAURAR sem uma decisão NOVA dele.
    //
    // ⚠️ As props vão CHEIAS (cabeçalho completo, `empresa.id` presente, abas,
    // grupos, rodapé): asserção de ausência rodada com dado vazio é vazia por
    // construção — foi o 10º modo de o medidor mentir nesta milestone.
    const html = render(MEUS, propsMeus({ cabecalho: cabecalhoCompleto() }));
    const corpo = corpoDaPagina(html);

    await contexto.test('zero radiogroup na página inteira, e zero no corpo', () => {
        assert.equal(
            (html.match(/role="radiogroup"/g) ?? []).length,
            0,
            'o segmentado "No ar | Histórico" voltou a MeusAnuncios. Ele foi removido DE PROPÓSITO em'
            + ' 13/10/2026 (LIST-25) — a contagem esperada era 1 até a Fase 179 e hoje é 0',
        );
        assert.doesNotMatch(corpo, /role="radio"/,
            'nenhum `role="radio"` sobrou no corpo: o trilho de status é `role="tablist"`/`role="tab"`');
    });

    await contexto.test('e os dois rótulos "No ar"/"Histórico" não aparecem mais na tela', () => {
        // ⚠️ A negativa é pelo CORPO e pelos literais EXCLUSIVOS do segmentado:
        // "Histórico" sozinho é palavra comum, mas aqui ele só existia naquele
        // controle (o header global não o tem, medido no plano 180-10).
        assert.doesNotMatch(corpo, />No ar</, 'o rótulo "No ar" voltou à tela');
        assert.doesNotMatch(corpo, />Histórico</, 'o rótulo "Histórico" voltou à tela');
    });

    await contexto.test('mas a página do Histórico CONTINUA montando o dela — o conteúdo não foi apagado', () => {
        // ⚠️ É o endurecimento que justifica a negativa: sem isto, apagar a
        // rota e a página inteira passaria por este gate, e foi exatamente o
        // que o usuário NÃO autorizou — ele tirou o ATALHO, não o conteúdo.
        const doHistorico = render(HISTORICO, propsHistorico({ cabecalho: cabecalhoCompleto() }));
        assert.equal((doHistorico.match(/role="radiogroup"/g) ?? []).length, 1,
            'a página do Histórico perdeu o segmentado — ela é o único lugar onde ele ainda vive');
        assert.match(doHistorico, /No ar/);
        assert.match(doHistorico, /Histórico/);
    });
});

// ═══ 5. O corpo de MeusAnuncios não perdeu nada (REG-02) ══════════════════

test('180: "Rascunhos" continua no corpo COM contador — na forma nova, dentro do trilho de status (LIST-25)', async (contexto) => {
    // ⚠️⚠️ CIRURGIA (Fase 180, plano 10) — REAPONTE DE FORMA, com veto.
    //
    // VALOR ANTIGO: o teste chamava-se "as sub-abas Publicados | Rascunhos
    // (D-14) continuam no corpo, com os contadores" e exigia os literais
    // `Publicados`, `Rascunhos`, `(3)` e `(1)` — o par era um segmentado
    // PRÓPRIO, com o contador entre PARÊNTESES.
    // VALOR NOVO:
    //   · `Rascunhos` continua na tela, agora como a 6ª opção DO MESMO trilho
    //     das abas de status, e o contador continua — na forma do protótipo,
    //     SEM parênteses (`Publicacoes v2.dc.html:86` interpola `tab.count`
    //     num `<span>` próprio);
    //   · `Publicados` deixou de ser um RÓTULO na tela: o atalho virou o
    //     próprio trilho de status. ⚠️ O DADO não saiu — `sub` segue na URL,
    //     `trocarSubAba` segue indo ao servidor e o contador segue sendo o
    //     MESMO `$totalDeRascunhos` do servidor (provado na fonte, em
    //     `estrutura-meus-anuncios.test.js`).
    // POR QUE: LIST-25, palavras literais do usuário em 11/10/2026 — *"ja o
    // rascunho adicione integrado no mesmo grupo de botões que tem abaixo da
    // barra de busca"*.
    // ⚠️ A forma `(3)`/`(1)` está VETADA: ela é a do controle que saiu.
    const html = render(MEUS, propsMeus({ cabecalho: cabecalhoCompleto() }));
    const corpo = corpoDaPagina(html);

    await contexto.test('"Rascunhos" está na tela, dentro do trilho (role="tablist"), com o contador', () => {
        assert.match(corpo, /Rascunhos/, '"Rascunhos" saiu da tela — o atalho mudou de lugar, não desapareceu');

        // ⚠️ Recorte à OPÇÃO, não ao HTML inteiro: "Rascunhos" solto passaria
        // com o rótulo vindo de qualquer outro lugar da tela. E o recorte é
        // por ELEMENTO (`role="tab"`), não por `indexOf` de classe — classe
        // compartilhada pega o irmão errado (é o 7º modo de o medidor mentir).
        assert.notEqual(corpo.indexOf('role="tablist"'), -1, 'o trilho de status (role="tablist") não está no corpo');

        const opcoes = corpo.match(/<button[^>]*role="tab"[\s\S]*?<\/button>/g) ?? [];
        assert.ok(opcoes.length >= 6, `esperadas ao menos 6 opções no trilho, achei ${opcoes.length}`);

        const deRascunhos = opcoes.filter((opcao) => opcao.includes('Rascunhos'));
        assert.equal(deRascunhos.length, 1, '"Rascunhos" não é UMA opção do trilho de status (LIST-25)');
        // O contador do fixture é 1 — e ele aparece no `<span>` da própria
        // opção, do lado do rótulo.
        assert.match(
            deRascunhos[0],
            /<span[^>]*>1<\/span>/,
            'o contador de "Rascunhos" (abas[].total, o mesmo $totalDeRascunhos do servidor) sumiu da opção',
        );
    });

    // ⚠️ VETO do rótulo antigo (Fase 186, plano 01 — VOC-01/ABA-03). Recortado à
    // OPÇÃO do trilho, não ao HTML inteiro, pelo mesmo motivo do teste acima.
    // O recorte `>Em revisão<` tem os dois delimitadores de nó e é EXCLUSIVO do
    // rótulo antigo: nem "Em revisão pela ECF" nem "Em revisão pelo Mercado
    // Livre" casariam com ele.
    await contexto.test('o trilho diz "Em análise pelo ML" e NÃO mais "Em revisão" (ABA-03)', () => {
        const opcoes = corpo.match(/<button[^>]*role="tab"[\s\S]*?<\/button>/g) ?? [];
        assert.ok(opcoes.length >= 6, `esperadas ao menos 6 opções no trilho, achei ${opcoes.length}`);
        assert.equal(
            opcoes.filter((opcao) => opcao.includes('>Em análise pelo ML<')).length,
            1,
            'o rótulo novo da aba (vindo do servidor, via abas[].rotulo) não chegou a UMA opção do trilho',
        );
        assert.equal(
            opcoes.filter((opcao) => opcao.includes('>Em revisão<')).length,
            0,
            'o rótulo antigo "Em revisão" voltou a uma opção do trilho',
        );
    });

    await contexto.test('a FORMA antiga do contador — "(3)" / "(1)" entre parênteses — está VETADA', () => {
        assert.doesNotMatch(
            corpo,
            /\(3\)/,
            'a forma antiga do contador voltou: o segmentado separado "Publicados | Rascunhos" usava parênteses'
            + ' e saiu em 13/10/2026 (LIST-25). O protótipo não usa parênteses',
        );
        assert.doesNotMatch(corpo, /\(1\)/, 'idem para o contador de Rascunhos');
    });

    await contexto.test('e "Publicados" deixou de ser rótulo na tela — o DADO ficou, o atalho mudou de lugar', () => {
        assert.doesNotMatch(
            corpo,
            />Publicados</,
            'o rótulo "Publicados" voltou como controle: o segmentado separado saiu (LIST-25) e quem mostra o'
            + ' acervo publicado agora é o próprio trilho de status',
        );
    });

    await contexto.test('com sub=rascunhos é a opção "Rascunhos" que fica marcada (e não um status)', () => {
        // ⚠️ Os dois sentidos: sem isto, "Rascunhos existe no trilho" passaria
        // com a opção nunca marcável — e a tela mostraria a lista de rascunhos
        // com "Ativas" marcada, que é mentira.
        const comRascunhos = corpoDaPagina(render(MEUS, propsMeus({
            cabecalho: cabecalhoCompleto(),
            sub: 'rascunhos',
            rascunhos: [],
        })));
        const marcada = comRascunhos.match(/<button[^>]*aria-selected="true"[^>]*>[\s\S]{0,200}?<\/button>/);
        assert.ok(marcada, 'nenhuma opção do trilho está marcada com sub=rascunhos');
        assert.match(marcada[0], /Rascunhos/, 'a opção marcada com sub=rascunhos não é "Rascunhos"');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. FASE 180 (plano 10) — A TELA NOVA NÃO PERDEU NADA
//
// ⚠️ POR QUE ESTE BLOCO EXISTE: o corpo de `MeusAnuncios` foi trocado inteiro
// pelo layout novo (14 componentes de `publicacoes/`). O critério 13 do
// ROADMAP nomeia OITO coisas que a tela de hoje entrega e que não podem
// desaparecer — e cada uma tem um caso de teste PRÓPRIO aqui, com render REAL
// da página COMPLETA. Gate de fonte não serve: a composição é o que pode
// quebrar, e a tela preta de 07/10/2026 só aparece renderizando.
//
// ⚠️ A ÚNICA exceção autorizada ao critério 13 é o segmentado
// "No ar | Histórico", removido por decisão informada do usuário em
// 13/10/2026 (LIST-25) — e a ausência dele tem teste próprio, acima. Desvio
// num item NÃO afrouxa os outros sete.
//
// ⚠️ A PRESCRIÇÃO DO PLANO FOI CORRIGIDA EM UM PONTO: ela pedia, no caso 1,
// *"'No ar'/'Histórico' presentes"*. Isso é anterior à decisão de 13/10 e é
// hoje o OPOSTO do exigido — a asserção correta é a de AUSÊNCIA, que vive no
// teste nominal acima. Registrado na 180-10-SUMMARY.
// ═══════════════════════════════════════════════════════════════════════════

/** Props com DADO de verdade — o vazio esconderia metade da tela. */
const propsMeusCheias = (extras = {}) => propsMeus({
    cabecalho: cabecalhoCompleto(),
    anuncios: {
        data: [
            {
                ml_item_id: 'MLB111', titulo: 'Poltrona Beny Linho', produto_id: 7,
                produto_nome: 'Poltrona Beny', produto_sku: 'PB-01', skus: ['PB-01'],
                status: 'active', fase: 1, tipo: 'classico', catalogo: false, estoque: 4,
                vendas: 12, visitas: 1340, nota_ecf: 71, nota_base: 86, motivos: ['sem_estoque'],
                origem: 'ecf', rascunho_id: 55, permalink: 'https://ml/MLB111',
                autor_nome: 'Maria', autor_tipo: 'equipe', publicada_em: '2026-10-12T10:00:00Z',
                preco: '309.90', thumbnail: null,
            },
            {
                ml_item_id: 'MLB222', titulo: 'Poltrona Beny Veludo', produto_id: 7,
                produto_nome: 'Poltrona Beny', status: 'paused', fase: 2, quantidade_kit: 2,
                tipo: 'premium', estoque: 0, vendas: 0, motivos: [], origem: 'legado',
                permalink: 'https://ml/MLB222', publicada_em: null, preco: null,
            },
        ],
        links: [
            { url: null, label: '&laquo; Previous', active: false },
            { url: '/meus?page=1', label: '1', active: true },
            { url: '/meus?page=2', label: '2', active: false },
            { url: '/meus?page=2', label: 'Next &raquo;', active: false },
        ],
        total: 248,
    },
    triagem: {
        total: 2,
        chips: [{ chave: 'sem_estoque', label: 'Sem estoque', count: 1, cor: 'red' }],
        nao_avaliado: 3,
    },
    grupos: [{
        produto_id: 7, produto_nome: 'Poltrona Beny', produto_sku: 'PB-01', estoque: 4,
        cobertura: { f1_classico: true, f1_premium: false, f2_classico: false, f2_premium: false },
        total_no_produto: 4, total_visivel: 2, sem_fase2: true,
        ver_produto_url: '/editor/7', criar_combo_url: '/produtos',
        ml_item_ids: ['MLB111', 'MLB222'],
    }],
    rodape: { visiveis: 2, total: 248, produtos: 1 },
    ...extras,
});

/**
 * O HTML sem os marcadores de fronteira de texto do React.
 *
 * ⚠️ MEDIDO, não presumido: o `renderToStaticMarkup` separa expressões de texto
 * adjacentes com `<!-- -->`, então "Última coleta há {dias} dia(s)" sai como
 * `Última coleta há <!-- -->2<!-- --> dia(s)`. Uma regex sobre a FRASE INTEIRA
 * reprova o código CERTO sem isto — foi o primeiro furo deste bloco.
 */
const semMarcadores = (html) => html.replace(/<!-- -->/g, '');

/** O card da tabela — recorte por ELEMENTO, nunca por classe compartilhada. */
function cardDaTabela(html) {
    const inicio = html.indexOf('<section class=');
    assert.notEqual(inicio, -1, 'o card da tabela (<section>) não está na saída');
    const fim = html.indexOf('</section>', inicio);
    assert.notEqual(fim, -1, '<section> do card sem fechamento');
    return html.slice(inicio, fim);
}

test('180: o critério 13 — as OITO coisas que a tela de hoje entrega continuam na tela', async (contexto) => {
    await contexto.test('1. banner de defasagem: texto, tom ÂMBAR (nunca vermelho) e a lista CONTINUA abaixo dele', () => {
        const html = render(MEUS, propsMeusCheias({
            defasagem: { horas: 52, defasado: true, nunca_coletado: false, motivo: 'token expirado' },
        }));
        const corpo = corpoDaPagina(html);

        // O nó do banner, recortado pelo TEXTO único dele (nunca por classe).
        const inicio = corpo.indexOf('Última coleta há');
        assert.notEqual(inicio, -1, 'o banner de defasagem (D-08) desapareceu da tela');
        const abertura = corpo.lastIndexOf('<div class=', inicio);
        const noDoBanner = corpo.slice(abertura, corpo.indexOf('</div>', inicio));

        // ⚠️ 52 horas → "há 2 dia(s)": é a conta da página
        // (`Math.max(1, Math.floor(horas / 24))`), e o `semMarcadores` existe
        // porque o React corta a frase com `<!-- -->` entre as expressões.
        assert.match(semMarcadores(corpo), /Última coleta há 2 dia\(s\), motivo: token expirado/,
            'o texto do banner mudou — ele diz há quantos dias foi a última coleta e o motivo');
        assert.match(corpo, /os dados abaixo podem estar desatualizados/);

        // ⚠️ Tom ÂMBAR: não é erro do usuário. O vermelho é de ERRO.
        assert.match(noDoBanner, /bg-pub-warn\/\[0\.05\]/, 'o banner perdeu o fundo âmbar');
        assert.match(noDoBanner, /border-pub-warn\/\[0\.30\]/, 'o banner perdeu a borda âmbar');
        assert.match(noDoBanner, /text-pub-warn-txt/, 'o banner perdeu o texto âmbar');
        assert.doesNotMatch(noDoBanner, /pub-danger/,
            'o banner virou VERMELHO — coleta velha não é erro do usuário (D-08)');

        // ⚠️ E ele NUNCA esconde a lista: as duas linhas continuam logo abaixo.
        const card = cardDaTabela(html);
        assert.match(card, /MLB111/, 'o banner escondeu a lista — ele nunca pode');
        assert.match(card, /MLB222/);

        // O botão de coleta vai NO banner (é a ação que resolve).
        assert.match(noDoBanner, /Atualizar agora/, 'o banner perdeu o "Atualizar agora"');
    });

    await contexto.test('2. "Ainda não coletamos os anúncios desta empresa." (nunca_coletado) SUBSTITUI a lista', () => {
        const html = render(MEUS, propsMeusCheias({
            defasagem: { horas: null, defasado: false, nunca_coletado: true, motivo: null },
        }));
        const corpo = corpoDaPagina(html);

        assert.match(corpo, /Ainda não coletamos os anúncios desta empresa\./,
            'o vazio honesto de "nunca coletado" desapareceu (EXMP-01: "nunca medimos" ≠ "medimos e é zero")');
        assert.match(corpo, /A primeira coleta roda automaticamente até amanhã/);
        assert.match(corpo, /Atualizar agora/, 'o caminho para resolver tem de estar junto do vazio');

        // ⚠️ E NADA de contador zerado: desenhar as 6 abas com 0 afirmaria que
        // medimos e deu zero. Nem trilho, nem busca, nem chips, nem tabela.
        assert.doesNotMatch(corpo, /role="tablist"/, 'o trilho de status apareceu sem nenhuma coleta — os zeros mentiriam');
        assert.doesNotMatch(corpo, /<input/, 'a busca apareceu sem nenhuma coleta — não há o que buscar');
        assert.doesNotMatch(corpo, /<section/, 'a tabela apareceu sem nenhuma coleta');
    });

    await contexto.test('3. o aviso de SKU ainda não coletado, com o "Atualizar agora" que a frase MANDA clicar', () => {
        const html = render(MEUS, propsMeusCheias({
            anuncios: { data: [], links: [] },
            grupos: [],
            filtros: { ...propsMeus().filtros, busca: 'PB-01' },
            skuNaoColetado: true,
        }));
        const corpo = corpoDaPagina(html);

        assert.match(corpo, /O SKU dos anúncios desta empresa ainda não foi coletado/,
            'o aviso de SKU não coletado (RIE-04) desapareceu — sem ele a tela mente por omissão');
        assert.match(corpo, /mesmo que o anúncio exista/,
            'é esta a frase que impede a tela de afirmar que o anúncio não existe');
        assert.match(corpo, /Clique em Atualizar agora e busque de novo/);
        // A frase manda clicar: o nó da ação não pode faltar.
        const card = cardDaTabela(html);
        assert.match(card, /Atualizar agora/, 'o aviso manda clicar num botão que não está na tela (EXMP-01)');
        // E o termo buscado aparece, em vez de um "não achei" genérico.
        assert.match(corpo, /Nada encontrado para “PB-01”\./);
    });

    await contexto.test('4. "Rascunhos" troca a LISTA (D-14): o painel entra e a tabela sai — e volta', () => {
        const comRascunhos = corpoDaPagina(render(MEUS, propsMeusCheias({ sub: 'rascunhos', rascunhos: [] })));
        assert.doesNotMatch(comRascunhos, /<section/, 'a tabela continuou na tela na fonte Rascunhos');
        assert.match(comRascunhos, /Nenhum rascunho aqui ainda\./, 'o painel de rascunhos não entrou');
        // ⚠️ O trilho FICA: é o caminho de volta. Sem ele a fonte Rascunhos
        // seria um beco sem saída.
        assert.match(comRascunhos, /role="tablist"/, 'sem o trilho não há como voltar da fonte Rascunhos');

        const publicados = corpoDaPagina(render(MEUS, propsMeusCheias()));
        assert.match(publicados, /<section/, 'a tabela não voltou na fonte Publicados');
        assert.doesNotMatch(publicados, /Nenhum rascunho aqui ainda\./, 'o painel de rascunhos vazou para Publicados');
    });

    await contexto.test('5. a paginação de 50 — os links do paginator, com as entidades DECODIFICADAS', () => {
        const rodape = render(MEUS, propsMeusCheias());
        const nav = rodape.match(/<nav aria-label="Paginação"[\s\S]*?<\/nav>/);
        assert.ok(nav, 'a paginação do servidor desapareceu do rodapé (critério 13)');

        // Os 4 links do fixture, com « e » em TEXTO (nunca via
        // dangerouslySetInnerHTML — T-134-20).
        assert.match(nav[0], /«\s*Previous/, 'o rótulo "&laquo; Previous" não foi decodificado');
        assert.match(nav[0], /Next\s*»/, 'o rótulo "Next &raquo;" não foi decodificado');
        assert.doesNotMatch(nav[0], /&laquo;|&raquo;/, 'a entidade HTML foi para a tela crua');
        // O link ativo é marcado, e o `url: null` NÃO virou âncora.
        assert.equal((nav[0].match(/<a /g) ?? []).length, 3, 'esperadas 3 âncoras (o "Previous" do fixture é url: null)');
        assert.match(nav[0], /border-pub-accent\/\[0\.55\]/, 'a página ativa deixou de ser destacada');
    });

    await contexto.test('6. a ordenação por gravidade (D-12) continua DITA na tela, e a tela não mente sobre ela', () => {
        const corpo = corpoDaPagina(render(MEUS, propsMeusCheias()));
        assert.match(corpo, /Mais graves primeiro/,
            'o rótulo da ordenação desapareceu — o servidor ordena por severidade (D-12) e a tela tem de dizer');
        // ⚠️ O protótipo escreve "Mais recentes primeiro" e MENTE sobre este
        // sistema: a ordenação é por gravidade. Decisão do plano 180-06.
        assert.doesNotMatch(corpo, /Mais recentes primeiro/,
            'a tela passou a dizer "Mais recentes primeiro", que é o protótipo MENTINDO sobre a ordenação real');
    });

    await contexto.test('7. a Nota ECF continua "de 86" NA TELA — nunca renormalizada para 100 (D-22/critério 15)', () => {
        const html = render(MEUS, propsMeusCheias());

        // ⚠️ Recorte ao NÓ: `doesNotMatch(html, /100/)` seria vazio por
        // construção (há 100 em rgba, em minmax e em classes).
        const inicio = html.indexOf('Nota ECF');
        assert.notEqual(inicio, -1, 'a Nota ECF desapareceu da tela (LIST-24 diz que ela MIGRA, não que é apagada)');
        const noDaNota = html.slice(Math.max(0, inicio - 120), inicio + 120);

        assert.match(noDaNota, /Nota ECF 71 de 86/, 'a nota deixou de aparecer como "de 86"');
        assert.doesNotMatch(noDaNota, /de 100/, 'a nota foi renormalizada para 100 — D-22 proíbe');
        assert.doesNotMatch(noDaNota, /82,6|82\.6/, 'a nota virou percentual (71/86) — D-22 proíbe');

        // E a nota sem valor diz que NÃO foi avaliada, nunca "0 de 86".
        assert.match(html, /Nota ECF ainda não avaliada/,
            'a linha sem nota deixou de dizer que a nota não foi avaliada (EXMP-01)');
    });

    await contexto.test('8. D-11 — a tela nunca escreve no ML: UM router.post, zero put/delete/patch', () => {
        // Pela FONTE sem comentários: o render não executa handler nenhum, e os
        // comentários desta página citam `router.post` e `D-11` de propósito.
        const fonte = lerSemComentarios('resources/js/Pages/Mlb/MeusAnuncios.jsx');

        assert.equal((fonte.match(/router\.post\(/g) ?? []).length, 1,
            'a contagem de router.post mudou — só pode haver UM, o "Atualizar agora", que ENFILEIRA');
        assert.match(fonte, /router\.post\(route\('mlb\.anuncios\.meus\.atualizar'/);
        for (const verbo of ['put', 'delete', 'patch']) {
            assert.doesNotMatch(fonte, new RegExp(`router\\.${verbo}\\(`),
                `a tela ganhou um router.${verbo} — ela NUNCA escreve no Mercado Livre (D-11)`);
        }
        // E nenhuma porta lateral: o <a> do permalink é link externo, não escrita.
        const html = render(MEUS, propsMeusCheias());
        assert.doesNotMatch(html, /<form/, 'a tela ganhou um formulário — a busca é round-trip por router.get');
    });
});

test('180: as decisões de honestidade da tela (EXMP-01) — nenhum número inventado, nenhum botão sem destino', async (contexto) => {
    await contexto.test('a faixa de sucesso NÃO aparece com o valor real do servidor (faixaSucesso: null)', () => {
        // ⚠️ MEDIDO no plano 180-04 por três caminhos: ninguém grava resumo de
        // publicação em sessão, a lista branca de `flash` não tem chave de
        // publicação, e a publicação é assíncrona. O protótipo traz
        // "2 publicações criadas agora · Poltrona Beny" e aquilo é MOCK.
        const corpo = corpoDaPagina(render(MEUS, propsMeusCheias()));
        assert.doesNotMatch(corpo, /criadas agora/, 'a faixa de sucesso apareceu com dado inventado (EXMP-01)');
        assert.doesNotMatch(corpo, /Localizar na lista/, 'o botão da faixa apareceu sem faixa');
    });

    await contexto.test('mas a LIGAÇÃO é real: com resumo do servidor a faixa aparece e o botão tem destino', () => {
        // ⚠️ Sem este caso, a asserção acima passaria também se a página
        // simplesmente não montasse o componente — "não aparece" não distingue
        // "não há dado" de "não está ligado".
        // ⚠️ A FORMA do resumo é a do componente (plano 180-05), medida na
        // fonte dele: `{total, produto_nome, fase_rotulo, tipos_rotulo,
        // autor_rotulo, busca_sugerida, ml_item_id}`. Inventar os nomes faria
        // a faixa não aparecer e o gate reprovar o código certo — foi o
        // segundo furo deste bloco.
        const corpo = corpoDaPagina(render(MEUS, propsMeusCheias({
            faixaSucesso: {
                total: 2,
                produto_nome: 'Poltrona Beny',
                fase_rotulo: 'Fase 2',
                tipos_rotulo: 'Clássico e Premium',
                autor_rotulo: 'publicadas por você',
                busca_sugerida: 'Beny',
                ml_item_id: 'MLB111',
            },
        })));
        assert.match(corpo, /criadas agora/, 'a faixa de sucesso não está LIGADA — a prop do servidor não chega nela');
        assert.match(corpo, /Localizar na lista/);
    });

    await contexto.test('o chip de "Responsável" NÃO é desenhado: a lista de responsáveis não existe como prop', () => {
        // ⚠️ Lacuna MEDIDA no plano 180-07 (varridos o controller e
        // `app/Services/Publicador/`). Inventar gente para o chip aparecer
        // seria pior que a ausência (EXMP-01).
        const corpo = corpoDaPagina(render(MEUS, propsMeusCheias()));
        assert.doesNotMatch(corpo, /Responsável/,
            'o chip de Responsável apareceu — a fonte dele não existe hoje e o conteúdo seria inventado');
        // E os outros cinco chips continuam lá (a negativa acima não pode ser
        // "nenhum chip é desenhado").
        for (const rotulo of ['Fase', 'Tipo', 'Em catálogo', 'Com vendas', 'Publicada']) {
            assert.ok(corpo.includes(rotulo), `o chip "${rotulo}" desapareceu da linha de filtros`);
        }
    });

    await contexto.test('a coluna Preço mostra "—" quando o servidor não manda preço, nunca um zero', () => {
        const card = cardDaTabela(render(MEUS, propsMeusCheias()));
        assert.match(card, /R\$ 309,90/, 'o preço que CHEGA deixou de ser mostrado');
        assert.match(card, /—/, 'a linha sem preço deixou de mostrar "—"');
        assert.doesNotMatch(card, /R\$ 0,00/, 'preço ausente virou R$ 0,00 — número inventado em tela financeira');
    });

    await contexto.test('a visão "Por produto" é a que abre, e "Lista" mostra as linhas planas (LIST-09/LIST-10)', () => {
        // Por produto (default do servidor): linha de grupo + recuo nas linhas.
        const porProduto = cardDaTabela(render(MEUS, propsMeusCheias()));
        assert.match(porProduto, /role="button"/, 'a linha de grupo da visão "Por produto" desapareceu');
        assert.match(porProduto, /PRODUTO/, 'o selo do produto na linha de grupo desapareceu');
        assert.match(porProduto, /pl-\[30px\]/, 'as linhas perderam o recuo de 30px da visão agrupada');

        // Lista: sem linha de grupo, com tile e o nome do produto no meta.
        const lista = cardDaTabela(render(MEUS, propsMeusCheias({
            filtros: { ...propsMeus().filtros, visao: 'lista' },
            grupos: [],
        })));
        assert.doesNotMatch(lista, /role="button"/, 'a visão Lista desenhou linha de grupo');
        assert.doesNotMatch(lista, /pl-\[30px\]/, 'a visão Lista manteve o recuo da visão agrupada');
        assert.match(lista, /Poltrona Beny/, 'a visão Lista perdeu o nome do produto no meta (LIST-10)');
    });

    await contexto.test('a busca ativa FORÇA os grupos abertos (LIST-09) — e a regra mora na página', () => {
        const comBusca = cardDaTabela(render(MEUS, propsMeusCheias({
            filtros: { ...propsMeus().filtros, busca: 'Beny' },
        })));
        assert.match(comBusca, /MLB111/, 'com busca ativa as linhas do grupo têm de estar visíveis (LIST-09)');
        assert.match(comBusca, /MLB222/);
    });

    await contexto.test('⚠️ a regra de LIST-09 nas QUATRO combinações — o render sozinho NÃO a prova', () => {
        // ⚠️ POR QUE ESTE CASO EXISTE: a prova por mutação deste plano inverteu
        // a regra no JSX (`buscaAtiva` → `!buscaAtiva`) e a mutação
        // SOBREVIVEU. Investigada em vez de presumida equivalente: no render de
        // servidor `colapsados` é SEMPRE `[]` (estado inicial), então
        // `!colapsados.includes(x)` é sempre verdadeiro e o `||` devolve `true`
        // de qualquer jeito — a regra NÃO É OBSERVÁVEL em render estático.
        // ➡️ Por isso ela virou a função pura `grupoAberto()`, exportada, e
        // estas são as quatro combinações.
        const { grupoAberto } = MOD_MEUS;
        assert.equal(typeof grupoAberto, 'function', 'a regra de LIST-09 deixou de ser exportada');

        assert.equal(grupoAberto(false, [], 'p7'), true, 'grupo não recolhido e sem busca tem de estar ABERTO');
        assert.equal(grupoAberto(false, ['p7'], 'p7'), false, 'grupo RECOLHIDO sem busca tem de estar fechado');
        assert.equal(grupoAberto(true, ['p7'], 'p7'), true,
            'com busca ativa o grupo recolhido tem de ABRIR — senão o resultado da busca fica escondido (LIST-09)');
        assert.equal(grupoAberto(false, ['outro'], 'p7'), true, 'recolher um grupo não pode fechar os outros');

        // E os formatos adversos: a regra não lança nem fecha tudo.
        assert.equal(grupoAberto(false, null, 'p7'), true, '`colapsados` nulo não pode fechar os grupos');
        assert.equal(grupoAberto(false, [], null), true, 'o grupo sem chave (Legado) não tem o que recolher');
        assert.equal(grupoAberto('sim', ['p7'], 'p7'), false, 'só o booleano `true` conta como busca ativa');
    });

    await contexto.test('a tela nunca esconde linha que o rodapé está contando (grupo nenhum do servidor)', () => {
        // ⚠️ Visão "Por produto" com `grupos: []` e linhas na página: sem o
        // caminho plano de reserva, a tabela sairia VAZIA dizendo "2 de 248".
        const card = cardDaTabela(render(MEUS, propsMeusCheias({ grupos: [] })));
        assert.match(card, /MLB111/, 'as linhas desapareceram com `grupos` vazio — a tela mentiria sobre o rodapé');
        assert.match(card, /2 de 248 publicações/);
    });
});

test('180: o vocabulário do corpo e as travas de segurança da composição', async (contexto) => {
    const fonte = lerSemComentarios('resources/js/Pages/Mlb/MeusAnuncios.jsx');

    await contexto.test('o Select do shadcn SAIU e nenhum dangerouslySetInnerHTML entrou', () => {
        assert.doesNotMatch(fonte, /@\/Components\/ui\/select/,
            'o Select do shadcn voltou: o filtro de status virou o trilho de abas (plano 180-06)');
        assert.doesNotMatch(fonte, /\bSelect(Trigger|Content|Item|Value)?\b/,
            'sobrou uso do Select do shadcn na página');
        assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/,
            'o rótulo do paginator voltou a ser injetado como HTML (T-134-20)');
        // E a função que existe para isso continua lá.
        assert.match(fonte, /function decodificarRotuloPaginacao/,
            'a decodificação segura dos rótulos do paginator desapareceu');
    });

    await contexto.test('a tabela antiga (Table do shadcn, 8 colunas) saiu — o corpo é o layout novo', () => {
        assert.doesNotMatch(fonte, /@\/Components\/ui\/table/, 'a tabela antiga continua montada');
        assert.match(fonte, /publicacoes\/LinhaDePublicacao/, 'a linha do layout novo não está importada');
        assert.match(fonte, /publicacoes\/CabecalhoDaTabela/, 'o cabeçalho do grid novo não está importado');
    });

    await contexto.test('os 14 componentes de `publicacoes/` são IMPORTADOS, nenhum recriado', () => {
        const DOZE_IMPORTS = [
            'CabecalhoDePublicacoes', 'FaixaDeSucesso', 'BuscaDePublicacoes', 'AbasDeStatus',
            'LinhaDeControles', 'ChipsDeFiltro', 'ChipsDeTriagem', 'CabecalhoDaTabela',
            'LinhaDePublicacao', 'LinhaDeGrupoDeProduto', 'RodapeDaTabela', 'VaziosDaLista',
        ];
        for (const nome of DOZE_IMPORTS) {
            assert.match(
                fonte,
                new RegExp(`from '@/Components/Mlb/Publicador/publicacoes/${nome}'`),
                `${nome} não é importado de publicacoes/ — ou a página o recriou`,
            );
        }
        // `MenuDeFiltro` e `CelulasDaLinha` são internos de `ChipsDeFiltro` e
        // `LinhaDePublicacao`: a página NÃO os monta (e não deve). Dela sai só
        // o `hojeEmSaoPaulo`, que é a data de hoje, uma vez por render.
        assert.doesNotMatch(fonte, /from '@\/Components\/Mlb\/Publicador\/publicacoes\/MenuDeFiltro'/);
        assert.match(fonte, /\{ hojeEmSaoPaulo \} from '@\/Components\/Mlb\/Publicador\/publicacoes\/CelulasDaLinha'/);
    });

    await contexto.test('o container do corpo tem as medidas do protótipo (FID-05)', () => {
        const html = render(MEUS, propsMeusCheias());
        // ⚠️ Recorte ANCORADO no `</header>` do header global e no
        // neutralizador: `/<div class="mx-auto[^"]*"/` solto pegava o IRMÃO
        // ERRADO — o container INTERNO DO HEADER, que também é
        // `mx-auto max-w-[1600px] px-[36px]`. Foi o terceiro furo deste bloco,
        // e é literalmente o 7º modo de o medidor mentir (indexOf/match de
        // classe compartilhada pega o irmão errado).
        const tag = html.match(/<\/header><div class="-mx-6 -mb-6"><div class="[^"]*"/);
        assert.ok(tag, 'o container interno do corpo não é o filho do neutralizador logo depois do header');

        for (const classe of ['mx-auto', 'flex', 'min-w-[1240px]', 'max-w-[1600px]', 'flex-col', 'gap-[18px]', 'px-[36px]', 'pb-[110px]', 'pt-[28px]']) {
            // ⚠️ `\b` não serve: `max-w-[1600px]` é prefixo de nada, mas
            // `flex` é prefixo de `flex-col` e `min-w-[` casa dentro de
            // `shadow-[…]`. A classe tem de terminar em espaço ou aspas.
            assert.match(
                tag[0],
                new RegExp(classe.replace(/[[\]/\\^$*+?.()|{}]/g, '\\$&') + '[\\s"]'),
                `a classe ${classe} saiu do container do corpo`,
            );
        }
    });

    await contexto.test('as três ligações que quebrariam em silêncio estão feitas', () => {
        // ⚠️ `nao_avaliado` (snake, do servidor) → `naoAvaliado` (camel, a
        // prop): um `{...triagem}` perderia o selo SEM nenhum erro.
        assert.match(fonte, /naoAvaliado=\{tri\.nao_avaliado\}/,
            'o selo "Não avaliado" perdeu a ligação snake→camel — ele sumiria da tela em silêncio');
        // ⚠️ `urlDoRascunho`: sem ela "Abrir rascunho" não é desenhado e
        // LIST-23 quebra em silêncio.
        assert.match(fonte, /urlDoRascunho=\{temRascunho/,
            'a linha perdeu `urlDoRascunho` — "Abrir rascunho" deixaria de existir (LIST-23)');
        assert.match(fonte, /rotulosDeMotivo=\{rotulosDeMotivo\}/,
            'a linha perdeu `rotulosDeMotivo` — a 2ª linha da célula Status não apareceria');
        assert.match(fonte, /hoje=\{hoje\}/, 'a linha perdeu `hoje` — a data cairia na forma absoluta');

        // E o resultado NA TELA: as três acendem com dado real.
        const html = render(MEUS, propsMeusCheias());

        // ⚠️ Recorte ao NÓ do selo pelo texto único dele: "3" solto no HTML
        // casaria com qualquer contador da tela.
        const inicioDoSelo = html.indexOf('Não avaliado');
        assert.notEqual(inicioDoSelo, -1, 'o selo "Não avaliado" não chegou à tela (ligação snake→camel)');
        const noDoSelo = html.slice(Math.max(0, inicioDoSelo - 200), inicioDoSelo + 20);
        assert.match(noDoSelo, />3</, 'o número do selo "Não avaliado" (triagem.nao_avaliado = 3) não chegou à tela');
        assert.match(html, /Sem estoque/, 'o rótulo do motivo não chegou à célula Status');
        assert.match(html, /Abrir rascunho/, 'o link "Abrir rascunho" não chegou à linha (LIST-23)');
        assert.match(html, /Ontem, 10:00|Hoje, 10:00|\d{1,2} \w{3}/, 'a data relativa não chegou à célula Publicada');
    });

    await contexto.test('o selo "copiado" tem o temporizador de 1,3 s — e o `preserveState` do round-trip', () => {
        // ⚠️ O QUE ESTE GATE NÃO PROVA, dito aqui em vez de maquiado: o
        // `renderToStaticMarkup` não executa efeito nem timer, então a
        // asserção é sobre o LITERAL na fonte, não sobre o comportamento. O
        // comportamento (o check verde que volta sozinho) é da validação
        // visual com o usuário.
        assert.match(fonte, /setTimeout\(\(\) => setCopiada\(null\), 1300\)/,
            'o temporizador de 1,3 s do selo "copiado" mudou de valor ou saiu da página');
        assert.match(fonte, /clearTimeout\(timer\)/, 'o cleanup do temporizador saiu — timer vazado em componente desmontado');
        assert.match(fonte, /\{ preserveState: true, replace: true \}/,
            'o round-trip da navegação perdeu `preserveState`/`replace` — o scroll e o foco saltariam a cada filtro');
    });
});

test('180: RENDER ADVERSO da página COMPLETA — a tela preta de 07/10 não volta por nenhuma prop', async (contexto) => {
    // ⚠️ POR QUE ESTE BLOCO É O MAIS IMPORTANTE DO ARQUIVO: em 07/10/2026 um
    // campo do presenter que chegou como OBJETO e foi renderizado como texto
    // derrubou a página inteira. Na Fase 178 GRUPO NULO derrubou o painel, e
    // só o render adverso pegou. No plano 180-07 a busca de chave com
    // `{toString: null}` derrubou dois componentes. Esta página é a ÚNICA que
    // renderiza a composição inteira — é aqui que a tela preta apareceria.
    //
    // ⚠️ A mensagem de asserção NUNCA usa `String(valor)` nem
    // `JSON.stringify(valor)`: os dois LANÇAM (ou mentem) com os valores
    // adversos — `String({toString: null})` é TypeError e `JSON.stringify(NaN)`
    // devolve 'null'. A mensagem é montada com o nome da prop + um rótulo fixo.
    const SUJO = { toString: null, valueOf: null };

    const ADVERSOS = [
        ['objeto hostil (toString e valueOf nulos)', SUJO],
        ['nulo', null],
        ['ausente', undefined],
        ['string onde se espera objeto', 'nada disso'],
        ['número', 42],
        ['NaN', Number.NaN],
        ['array vazio', []],
        ['array com objeto hostil', [SUJO]],
        ['objeto com chave desconhecida', { chaveQueNinguemEspera: SUJO }],
    ];

    const RE_SUJEIRA = /\[object Object\]|NaN|undefined|Invalid Date/;

    await contexto.test('⚠️ ISCA: o detector de sujeira PEGA o que existe', () => {
        // Sem isto, as 170+ asserções abaixo poderiam estar cegas.
        assert.match('valor: [object Object]', RE_SUJEIRA);
        assert.match('NaN publicações', RE_SUJEIRA);
        assert.match('undefined', RE_SUJEIRA);
        assert.match('Invalid Date', RE_SUJEIRA);
        assert.doesNotMatch('2 de 248 publicações · 1 produto', RE_SUJEIRA);
    });

    const PROPS_DO_CONTRATO = Object.keys(propsMeusCheias());

    await contexto.test('o contrato tem as 19 props de hoje — nenhuma entra nem sai calada', () => {
        // ⚠️ É esta asserção que impede o laço abaixo de varrer menos props do
        // que a página recebe: prop nova sem caso adverso é exatamente como a
        // tela preta de 07/10 começou.
        assert.deepEqual(PROPS_DO_CONTRATO.slice().sort(), [
            'abas', 'anuncios', 'cabecalho', 'conta', 'contagens', 'defasagem', 'empresa',
            'faixaSucesso', 'filtros', 'grupos', 'rascunhos', 'rodape', 'rotacaoN',
            'saudeMlDisponivel', 'sincronizacao', 'skuNaoColetado', 'sub', 'subTotais', 'triagem',
        ]);
    });

    for (const prop of PROPS_DO_CONTRATO) {
        await contexto.test(`prop "${prop}" — objeto hostil, nula, ausente e em 6 outros formatos`, () => {
            for (const [rotuloDoCaso, valor] of ADVERSOS) {
                const entrada = propsMeusCheias();
                if (valor === undefined) {
                    delete entrada[prop];
                } else {
                    entrada[prop] = valor;
                }

                let html;
                assert.doesNotThrow(
                    () => { html = render(MEUS, entrada); },
                    `a prop "${prop}" com ${rotuloDoCaso} DERRUBOU a página inteira`,
                );
                assert.doesNotMatch(
                    html,
                    RE_SUJEIRA,
                    `a prop "${prop}" com ${rotuloDoCaso} imprimiu sujeira na tela`,
                );
                // O header global nunca desaparece: sem conta ele desabilita e
                // explica.
                assert.ok(
                    (html.match(/<header/g) ?? []).length >= 1,
                    `a prop "${prop}" com ${rotuloDoCaso} fez o header global desaparecer`,
                );
            }
        });
    }

    await contexto.test('os formatos INTERNOS que já derrubaram tela neste projeto', () => {
        const CASOS = [
            ['anuncios sem `data`', { anuncios: { links: [] } }],
            ['anuncios.data nula', { anuncios: { data: null, links: [] } }],
            ['anuncios.data string', { anuncios: { data: 'MLB111', links: [] } }],
            ['anuncios.data número', { anuncios: { data: 7, links: [] } }],
            ['anuncios.data com item hostil', { anuncios: { data: [SUJO], links: [] } }],
            ['anuncios.data com item nulo', { anuncios: { data: [null], links: [] } }],
            ['anuncios.links nula', { anuncios: { data: [], links: null } }],
            ['anuncios.links sem url', { anuncios: { data: [], links: [{}, {}, {}, {}] } }],
            ['anuncios.links com url hostil', { anuncios: { data: [], links: [{ url: SUJO, label: SUJO }, {}, {}, {}] } }],
            ['grupos com grupo NULO (o bug da Fase 178)', { grupos: [null] }],
            ['grupos com grupo hostil', { grupos: [SUJO] }],
            ['grupos com produto_id hostil', { grupos: [{ produto_id: SUJO, ml_item_ids: [SUJO] }] }],
            ['grupos com ml_item_ids nulo', { grupos: [{ produto_id: 7, ml_item_ids: null }] }],
            ['triagem.chips nula', { triagem: { total: 2, chips: null, nao_avaliado: 1 } }],
            ['triagem.chips com chip hostil', { triagem: { total: 2, chips: [SUJO], nao_avaliado: 1 } }],
            ['triagem.chips com chave hostil', { triagem: { total: 2, chips: [{ chave: SUJO, label: SUJO }], nao_avaliado: 1 } }],
            ['triagem.total hostil', { triagem: { total: SUJO, chips: [], nao_avaliado: SUJO } }],
            ['filtros com visao/status/sel hostis', { filtros: { busca: SUJO, status: SUJO, visao: SUJO, sel: SUJO, motivo: SUJO, responsavel: SUJO } }],
            ['empresa sem id (o caso do destino inventado)', { empresa: {} }],
            ['empresa.id string', { empresa: { id: '459' } }],
            ['defasagem com horas/motivo hostis', { defasagem: { horas: SUJO, motivo: SUJO, defasado: true, nunca_coletado: false } }],
            ['defasagem.horas NaN', { defasagem: { horas: Number.NaN, motivo: null, defasado: true, nunca_coletado: false } }],
            ['rodape com valores hostis', { rodape: { visiveis: SUJO, total: SUJO, produtos: SUJO } }],
            ['sincronizacao com rotulo hostil', { sincronizacao: { rotulo: SUJO, nunca_coletado: false } }],
            ['abas com aba hostil', { abas: [SUJO, { chave: SUJO, rotulo: SUJO, total: SUJO }] }],
            ['contagens hostis', { contagens: { rascunhos: SUJO, acionaveis: SUJO } }],
            ['faixaSucesso com total hostil', { faixaSucesso: { total: SUJO, produto: SUJO } }],
            ['item com nota_ecf hostil', { anuncios: { data: [{ ml_item_id: 'MLB1', nota_ecf: SUJO, preco: SUJO, vendas: SUJO, publicada_em: SUJO }], links: [] }, grupos: [] }],
            ['item com datas inválidas', { anuncios: { data: [{ ml_item_id: 'MLB1', publicada_em: 'não é data' }], links: [] }, grupos: [] }],
            ['sub desconhecido', { sub: 'inventado' }],
        ];

        for (const [rotuloDoCaso, extras] of CASOS) {
            let html;
            assert.doesNotThrow(
                () => { html = render(MEUS, propsMeusCheias(extras)); },
                `${rotuloDoCaso} DERRUBOU a página inteira`,
            );
            assert.doesNotMatch(html, RE_SUJEIRA, `${rotuloDoCaso} imprimiu sujeira na tela`);
        }
    });

    await contexto.test('a página INTEIRA sem nenhuma prop, e com todas nulas', () => {
        let semNada;
        assert.doesNotThrow(() => { semNada = render(MEUS, {}); }, 'a página não sobrevive a props vazias');
        assert.doesNotMatch(semNada, RE_SUJEIRA);

        const todasNulas = {};
        for (const prop of PROPS_DO_CONTRATO) todasNulas[prop] = null;
        let comNulas;
        assert.doesNotThrow(() => { comNulas = render(MEUS, todasNulas); }, 'a página não sobrevive a todas as props nulas');
        assert.doesNotMatch(comNulas, RE_SUJEIRA);
        assert.equal((comNulas.match(/<header/g) ?? []).length, 2, 'o cabeçalho da página desapareceu com tudo nulo');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. AS MEDIDAS DO CONTAINER GERAM CSS COM O VALOR CERTO
//
// ⚠️ Os DOIS gates são necessários e não se substituem (limitação ESTRUTURAL
// medida no plano 180-05): o de HTML acima prova que o ELEMENTO tem as
// classes; este prova que a CLASSE EXISTE no CSS construído com o valor certo.
// Gate de CSS sozinho deixa passar classe removida do arquivo errado — a mesma
// classe pode ter outro dono no repositório.
//
// ⚠️ AS ARMADILHAS DO MEDIDOR, APLICADAS: o seletor sai ESCAPADO com barra
// invertida; escapar só a barra quebra em classe com colchete; e TODO
// metacaractere de regex tem de ser escapado (parênteses incluídos).
// ═══════════════════════════════════════════════════════════════════════════

/** Escapa TODOS os metacaracteres de regex. */
function escapaRegex(texto) {
    return texto.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** O seletor que o Tailwind emite: `.` + o nome com os metacaracteres de CSS escapados. */
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

    const mtimeCss = fs.statSync(cssAbs).mtimeMs;
    const mtimePagina = fs.statSync(path.resolve(RAIZ, 'resources/js/Pages/Mlb/MeusAnuncios.jsx')).mtimeMs;
    if (mtimeCss < mtimePagina) {
        return { motivo: `o CSS construído (${entrada.css[0]}) é mais antigo que a página — rode \`npm run build\`` };
    }

    return { arquivo: entrada.css[0], css: fs.readFileSync(cssAbs, 'utf8'), manifest };
}

const CSS_DO_BUILD = carregarCssConstruido();

test('180: as medidas do container e do banner GERAM CSS com o valor certo', async (contexto) => {
    if (CSS_DO_BUILD.motivo) {
        contexto.skip(CSS_DO_BUILD.motivo);
        return;
    }

    const regraDe = (classe) => {
        const re = new RegExp(`${escapaRegex(seletorDe(classe))}(?![\\w\\\\-])[^{}]*\\{([^}]*)\\}`);
        const m = CSS_DO_BUILD.css.match(re);
        return m ? m[1] : null;
    };

    await contexto.test('o CSS lido é o do manifest e tem tamanho de CSS de verdade', () => {
        assert.ok(CSS_DO_BUILD.css.length > 100000, `CSS suspeito de truncado: ${CSS_DO_BUILD.css.length} bytes`);
    });

    await contexto.test('⚠️ ISCA: a sonda reporta AUSENTE o que não existe', () => {
        // ⚠️ Iscas medidas como inexistentes no repositório inteiro. Sem elas a
        // sonda poderia estar cega e "aprovar" qualquer classe.
        for (const isca of ['min-w-[1241px]', 'max-w-[1601px]', 'bg-pub-isca-8h2', 'pt-[29px]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS_DO_BUILD.css.includes(isca), `a string ${isca} apareceu no CSS`);
        }
    });

    await contexto.test('as medidas do container do corpo (FID-05: 1600px de conteúdo, min 1240px)', () => {
        const ESPERADO = {
            'min-w-[1240px]': /min-width:\s*1240px/,
            'max-w-[1600px]': /max-width:\s*1600px/,
            'gap-[18px]': /gap:\s*18px/,
            'px-[36px]': /padding-left:\s*36px/,
            'pt-[28px]': /padding-top:\s*28px/,
            'pb-[110px]': /padding-bottom:\s*110px/,
        };
        for (const [classe, re] of Object.entries(ESPERADO)) {
            const corpo = regraDe(classe);
            assert.ok(corpo !== null, `a classe ${classe} não gerou CSS — ela não existe para a tela`);
            assert.match(corpo, re, `${classe} compilou com o valor ERRADO: {${corpo}}`);
        }
    });

    await contexto.test('o tom âmbar do banner de defasagem compila — e NÃO é o vermelho de erro', () => {
        // ⚠️ O modificador de opacidade em `background-color`/`border-color`
        // sai em HEX DE 8 DÍGITOS, e o byte do alfa é o que distingue 0,05 de
        // 0,30 — é o único jeito de provar a tinta certa.
        const fundo = regraDe('bg-pub-warn/[0.05]');
        assert.ok(fundo !== null, 'o fundo âmbar do banner não gerou CSS');
        assert.match(fundo, /#f59e0b0d/i, `o alfa do fundo âmbar mudou: {${fundo}}`);

        const borda = regraDe('border-pub-warn/[0.30]');
        assert.ok(borda !== null, 'a borda âmbar do banner não gerou CSS');
        assert.match(borda, /#f59e0b4d/i, `o alfa da borda âmbar mudou: {${borda}}`);

        // E o texto âmbar é o token de TEXTO (mais claro que o dot), sólido.
        const texto = regraDe('text-pub-warn-txt');
        assert.ok(texto !== null, 'o texto âmbar do banner não gerou CSS');
        assert.match(texto, /251 191 36/, `o texto do banner deixou de ser o âmbar de texto: {${texto}}`);
    });

    await contexto.test('⚠️ a página de Publicações está no manifest — página fora dele SOME da aplicação', () => {
        const chave = 'resources/js/Pages/Mlb/MeusAnuncios.jsx';
        assert.ok(
            Object.prototype.hasOwnProperty.call(CSS_DO_BUILD.manifest, chave),
            `${chave} não está no manifest do Vite: a rota renderizaria página inexistente`,
        );
        assert.match(CSS_DO_BUILD.manifest[chave].file, /^assets\/MeusAnuncios-[\w-]+\.js$/,
            'a entrada da página no manifest não aponta para um bundle com hash');
    });
});
