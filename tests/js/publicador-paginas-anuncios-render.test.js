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

const propsMeus = (extras = {}) => ({
    empresa: { id: 459, nome: 'Dev 02 Testes API' },
    conta: { chave: 'company-459', nome: 'Dev 02 Testes API' },
    sub: 'publicados',
    subTotais: { publicados: 3, rascunhos: 1 },
    anuncios: { data: [], links: [] },
    rascunhos: { data: [], links: [] },
    triagem: { total: 0, chips: [] },
    filtros: { busca: '', status: 'acionaveis', motivo: null },
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

const PAGINAS = [
    { nome: 'MeusAnuncios', Componente: MEUS, props: propsMeus, titulo: 'Meus Anúncios' },
    { nome: 'AnunciosHistorico', Componente: HISTORICO, props: propsHistorico, titulo: 'Histórico de anúncios' },
    { nome: 'AnunciarMassa', Componente: MASSA, props: propsMassa, titulo: 'Anunciar em massa' },
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

for (const { nome, Componente, props, titulo } of PAGINAS) {
    test(`${nome} — o header é o PRIMEIRO filho do AppLayout, fora do container do corpo (HEAD-14)`, async (contexto) => {
        const html = render(Componente, props({ cabecalho: cabecalhoCompleto() }));

        await contexto.test('o <header> abre imediatamente dentro do <main>, sem nada antes', () => {
            assert.match(
                html,
                /^<main data-titulo="[^"]*"><header /,
                'algo entrou entre o <main> e o <header> — dentro do container do corpo o `-mx-6` brigaria com o `px-4` dele',
            );
            assert.equal((html.match(/<header/g) ?? []).length, 1, 'esperado exatamente UM <header> na página');
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
            assert.match(
                html,
                /<\/header><div class="mx-auto max-w-\[1600px\] px-4 py-6 sm:px-6 lg:px-8/,
                'o container do corpo mudou de classe ou deixou de ser o irmão seguinte do header',
            );
        });
    });
}

// ═══ 2. `cabecalho` nulo, ausente e sujo — as 3 páginas sobrevivem ═════════

for (const { nome, Componente, props } of PAGINAS) {
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
                assert.equal((html.match(/<header/g) ?? []).length, 1, `o <header> sumiu com cabecalho ${rotulo}`);
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

const COM_SUBABAS = [
    { nome: 'MeusAnuncios', Componente: MEUS, props: propsMeus, marcada: 'No ar', outra: 'Histórico' },
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

// ═══ 5. O corpo de MeusAnuncios não perdeu nada (REG-02) ══════════════════

test('MeusAnuncios — as sub-abas Publicados | Rascunhos (D-14) continuam no corpo, com os contadores', () => {
    const html = render(MEUS, propsMeus({ cabecalho: cabecalhoCompleto() }));
    const corpo = corpoDaPagina(html);

    // São OUTRAS sub-abas, dentro da própria página — não se confundem com as
    // de No ar | Histórico e NÃO saem na Fase 180.
    assert.match(corpo, /Publicados/);
    assert.match(corpo, /Rascunhos/);
    assert.match(corpo, /\(3\)/, 'o contador de Publicados (subTotais) sumiu');
    assert.match(corpo, /\(1\)/, 'o contador de Rascunhos (subTotais) sumiu');
});
