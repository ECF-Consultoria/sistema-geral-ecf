import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
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
const MEUS = (await montar('resources/js/Pages/Mlb/MeusAnuncios.jsx', 'pg-meus')).default;
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
        { chave: 'em_revisao', rotulo: 'Em revisão', total: 0, comDot: false },
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
//   H1 "Publicações" + "● Sincronizado há X" + "Atualizar") é um `<header>`
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
