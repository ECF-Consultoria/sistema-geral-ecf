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
// Fase 180, plano 05 — o cabeçalho de Publicações (LIST-01) e a faixa de
// sucesso (LIST-02), com render REAL (esbuild + `react-dom/server`).
//
// Protótipo: `new_layout_publicador/Publicacoes v2.dc.html`. As linhas abaixo
// foram MEDIDAS com `grep -n` neste arquivo, não copiadas da tabela do plano —
// e desta vez a tabela do planejador bateu com o protótipo em TODAS as medidas
// (ele varreu por script; ver a 180-05-SUMMARY):
//
//   · `<main>`             → :30  (container, do plano 180-10 e NÃO deste)
//   · `<header>`           → :31   · coluna do título → :32
//   · H1 "Publicações"     → :33   · subtítulo        → :34
//   · bloco da direita     → :36   · "Sincronizado X" → :37
//   · dot verde            → :38   · botão "Atualizar"→ :39
//   · spinner              → :41   · ícone de refresh → :44
//   · rótulos do botão     → :619 (`'Atualizando…' : 'Atualizar'`)
//   · faixa de sucesso     → :52-:66 (`sc-if showToast`)
//
// ⚠️ REND-03: TODOS os bundles são montados AQUI NO TOPO, antes do primeiro
// `test()`. O `after()` do `node --test` dispara quando os testes registrados
// terminam e já matou um arquivo inteiro deste projeto SEM nenhum teste falhar
// (sintoma: 37 testes em vez de 94).
//
// ⚠️ A pergunta obrigatória — *"o que precisaria estar errado no código para
// este gate falhar?"* — está respondida POR TESTE, no comentário de cada
// bloco. Gate sem essa resposta é decorativo, e cada uma das 11 waves da Fase
// 179 pegou um gate PRÓPRIO vazio por construção com ela.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});
global.window = {
    location: { search: '', href: 'http://localhost/mlb/anuncios' },
    addEventListener: () => {},
    removeEventListener: () => {},
    history: { state: null, replaceState: () => {} },
};

const STUB_INERTIA = path.join(__dirname, `.pub-cab-inertia-stub-${process.pid}.mjs`);
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

/**
 * Compila um módulo de verdade (JSX + imports reais) e devolve os exports.
 *
 * ⚠️ `'@radix-ui/react-dialog'` e `'@radix-ui/react-popover'` ficam no
 * `external` porque estes dois componentes importam `textoUtil` de
 * `BarraDaConta`, e aquele módulo arrasta o Popover do Radix →
 * `react-remove-scroll` (CJS) → `Dynamic require of "react"` quando empacotado
 * (medido na Fase 178).
 */
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
        `.pub-cab-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_PUB = 'resources/js/Components/Mlb/Publicador/publicacoes';
const REL_CABECALHO = `${DIR_PUB}/CabecalhoDePublicacoes.jsx`;
const REL_FAIXA = `${DIR_PUB}/FaixaDeSucesso.jsx`;

// ⚠️ `test -e` ANTES de acreditar em qualquer leitura vazia. Dois caminhos
// inexistentes já "provaram" território intocado nesta milestone, e o 5º
// caminho fantasma foi medido em 13/10/2026.
for (const caminho of [DIR_PUB, REL_CABECALHO, REL_FAIXA]) {
    if (!fs.existsSync(path.resolve(RAIZ, caminho))) {
        throw new Error(`caminho inexistente: ${caminho} — gate mediria NADA`);
    }
}

const MOD_CABECALHO = await montar(REL_CABECALHO);
const MOD_FAIXA = await montar(REL_FAIXA);

const Cabecalho = MOD_CABECALHO.default;
const BotaoAtualizar = MOD_CABECALHO.BotaoAtualizarDoCabecalho;
const Faixa = MOD_FAIXA.default;

const FONTE_CABECALHO = lerSemComentarios(REL_CABECALHO);
const FONTE_FAIXA = lerSemComentarios(REL_FAIXA);

/** Render de servidor, sem comentários `<!-- -->` entre nós de texto. */
const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

// ─────────────────────────────────────────────────────────────────────────
// Localização por ÁRVORE com índices absolutos / por TEXTO ÚNICO.
//
// ⚠️ NUNCA por lista de classes: `html.indexOf(tagDeAbertura)` pega o IRMÃO
// ERRADO quando dois elementos compartilham a mesma string de classes — quatro
// gates do ERP estavam medindo a linha do Mercado Livre por isso.
// ─────────────────────────────────────────────────────────────────────────

/** A tag de abertura inteira que começa em `pos`. */
function tagEm(html, pos) {
    assert.ok(pos !== -1 && pos < html.length, 'posição fora do HTML');
    const fim = html.indexOf('>', pos);
    assert.ok(fim !== -1, 'tag de abertura sem fechamento');
    return html.slice(pos, fim + 1);
}

/** Índice absoluto da `ocorrencia`-ésima tag de abertura `<nome`. */
function indiceDaTag(html, nome, ocorrencia = 1) {
    let pos = -1;
    for (let i = 0; i < ocorrencia; i += 1) {
        pos = html.indexOf(`<${nome}`, pos + 1);
        assert.ok(pos !== -1, `não achei a ${i + 1}ª <${nome}> no HTML`);
    }
    return pos;
}

/** A tag `<nome` que ENVOLVE o índice `pos` (a última aberta antes dele). */
function tagQueEnvolve(html, pos, nome) {
    assert.ok(pos !== -1, 'índice de referência não encontrado');
    const inicio = html.lastIndexOf(`<${nome}`, pos);
    assert.ok(inicio !== -1, `não achei <${nome}> envolvendo o índice ${pos}`);
    return tagEm(html, inicio);
}

/**
 * As classes de uma tag de abertura, como LISTA.
 *
 * ⚠️ Toda asserção de classe é sobre a lista completa, nunca por substring:
 * `text-[11px]` casa dentro de `text-[11.5px]`, `z-3` dentro de `z-30` e
 * `font-pub` dentro de `font-pub-mono`.
 */
function classesDa(tag) {
    const m = tag.match(/class="([^"]*)"/);
    assert.ok(m, `tag sem class=: ${tag}`);
    return m[1].split(/\s+/).filter(Boolean);
}

/** Igualdade de CONJUNTO de classes — endurecimento: classe a mais reprova. */
function assertClasses(tag, esperadas, onde) {
    assert.deepEqual([...classesDa(tag)].sort(), [...esperadas].sort(), `${onde}: classes divergentes em ${tag}`);
}

/** Um atributo de uma tag de abertura. */
function atributo(tag, nome) {
    const m = tag.match(new RegExp(`\\s${nome}="([^"]*)"`));
    return m ? m[1] : null;
}

/** A primeira tag `<nome` que começa DEPOIS da tag de abertura que está em `pos`. */
function primeiraTagApos(html, pos, nome) {
    const fim = html.indexOf('>', pos);
    assert.ok(fim !== -1, 'tag de referência sem fechamento');
    const achado = html.indexOf(`<${nome}`, fim);
    assert.ok(achado !== -1, `não achei <${nome}> depois do índice ${pos}`);
    return achado;
}

/**
 * Descritor SEGURO para mensagem de asserção.
 *
 * ⚠️ Por que não `String(valor)` nem `JSON.stringify(valor)`:
 *  · `String({ toString: null })` LANÇA "Cannot convert object to primitive
 *    value" — o próprio gate derrubava a si mesmo (medido nesta rodada);
 *  · `JSON.stringify` MENTE: `NaN` e `Infinity` viram `'null'`.
 */
function descreve(valor) {
    if (valor === null) return 'null';
    if (valor === undefined) return 'undefined';
    const tipo = typeof valor;
    if (tipo === 'number' || tipo === 'boolean' || tipo === 'bigint') return `${tipo}:${valor}`;
    if (tipo === 'string') return `string:"${valor}"`;
    if (tipo === 'function') return 'function';
    if (Array.isArray(valor)) return `array(${valor.length})`;
    try {
        return `object{${Object.keys(valor).join(',')}}`;
    } catch {
        return 'object(ilegível)';
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Os fixtures. ⚠️ Nenhum nome de produto do protótipo ("Poltrona Beny") entra
// no CÓDIGO; aqui ele é fixture de teste, e é outra coisa.
// ─────────────────────────────────────────────────────────────────────────

const SINC_OK = { coletado_em: '2026-10-13T12:00:00Z', horas: 0.4, rotulo: 'há 25 min', nunca_coletado: false };

const RESUMO_CHEIO = {
    total: 2,
    produto_nome: 'Poltrona de teste',
    fase_rotulo: 'Fase 2 · Combo 2 unidades',
    tipos_rotulo: 'Clássico e Premium',
    autor_rotulo: 'publicadas por você',
    busca_sugerida: 'Poltrona de teste',
    ml_item_id: 'MLB1234567890',
};

const HTML_CAB_CHEIO = render(Cabecalho, {
    sincronizacao: SINC_OK,
    acaoAtualizar: React.createElement(BotaoAtualizar, { onClick: () => {} }),
});

const HTML_CAB_GIRANDO = render(Cabecalho, {
    sincronizacao: { rotulo: 'agora', nunca_coletado: false },
    acaoAtualizar: React.createElement(BotaoAtualizar, { onClick: () => {}, atualizando: true }),
});

const HTML_FAIXA_CHEIA = render(Faixa, {
    resumo: RESUMO_CHEIO,
    onLocalizar: () => {},
    onDispensar: () => {},
});

// ═══════════════════════════════════════════════════════════════════════════
// 1. OS LITERAIS, BYTE A BYTE
//
// O que precisaria estar errado para falhar: alguém parafrasear o H1, o
// subtítulo do README, o prefixo "Sincronizado", os dois rótulos do botão ou o
// "Localizar na lista" — que é a forma mais comum de o redesenho "adaptar ao
// sistema atual" sem ninguém notar.
// ═══════════════════════════════════════════════════════════════════════════

test('LIST-01/02 — os literais de tela estão byte a byte', async (contexto) => {
    await contexto.test('o H1 e o subtítulo do README', () => {
        assert.ok(HTML_CAB_CHEIO.includes('Publicações'), HTML_CAB_CHEIO);
        assert.ok(
            HTML_CAB_CHEIO.includes('Ofertas ativas e históricas desta empresa no Mercado Livre'),
            'subtítulo parafraseado — o literal é o do README §2 e do protótipo :34',
        );
    });

    await contexto.test('"Sincronizado" + o rótulo PRONTO do servidor, numa frase só', () => {
        assert.ok(
            HTML_CAB_CHEIO.includes('Sincronizado há 25 min'),
            'o prefixo e o rótulo têm de sair contíguos (protótipo :38: `Sincronizado {{ syncedLabel }}`)',
        );
        assert.ok(HTML_CAB_GIRANDO.includes('Sincronizado agora'));
    });

    await contexto.test('"Atualizar" fora, "Atualizando…" durante', () => {
        assert.ok(HTML_CAB_CHEIO.includes('Atualizar'), 'rótulo do botão em repouso');
        assert.ok(HTML_CAB_GIRANDO.includes('Atualizando…'), 'rótulo do botão girando (protótipo :619)');
        // ⚠️ ASSERÇÃO NEGATIVA — e "Atualizar" é PREFIXO de "Atualizando…", logo
        // `!includes('Atualizar')` no HTML girando seria FALSO por construção.
        // A negativa certa é a inversa, que é exclusiva:
        assert.ok(
            !HTML_CAB_CHEIO.includes('Atualizando…'),
            'o rótulo de "girando" apareceu em repouso',
        );
    });

    await contexto.test('"Localizar na lista" e o title "Dispensar" da faixa', () => {
        assert.ok(HTML_FAIXA_CHEIA.includes('Localizar na lista'));
        assert.ok(HTML_FAIXA_CHEIA.includes('title="Dispensar"'), 'protótipo :62');
    });

    await contexto.test('a frase da faixa é montada das props — nenhum nome de produto no código', () => {
        assert.ok(HTML_FAIXA_CHEIA.includes('2 publicações criadas agora · Poltrona de teste'));
        assert.ok(HTML_FAIXA_CHEIA.includes('Fase 2 · Combo 2 unidades · Clássico e Premium · publicadas por você'));
        // ⚠️ EXMP-01: o nome do protótipo é dado MOCK e não pode estar na fonte.
        for (const [nome, fonte] of [[REL_CABECALHO, FONTE_CABECALHO], [REL_FAIXA, FONTE_FAIXA]]) {
            assert.ok(!fonte.includes('Poltrona Beny'), `${nome} fixou o nome de produto do protótipo`);
        }
    });

    await contexto.test('plural correto no singular e no plural', () => {
        const um = render(Faixa, { resumo: { total: 1 }, onDispensar: () => {} });
        assert.ok(um.includes('1 publicação criada agora'), um);
        assert.ok(!um.includes('publicações criadas'), 'plural usado para 1');

        const tres = render(Faixa, { resumo: { total: 3 }, onDispensar: () => {} });
        assert.ok(tres.includes('3 publicações criadas agora'), tres);
        // ⚠️ `'publicação criada'` é substring de nada aqui, mas provo a
        // exclusividade: o singular NÃO pode aparecer no render de 3.
        assert.ok(!tres.includes('publicação criada agora'), 'singular usado para 3');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. FID-01 — A GEOMETRIA, POR IGUALDADE DE CONJUNTO DE CLASSES
//
// O que precisaria estar errado para falhar: qualquer medida trocada por outra
// (28px→24px, 13.5px→`text-sm`, raio 12→16, `bg-pub-row-new`→`bg-pub-surface`),
// qualquer classe A MAIS (igualdade de conjunto), ou o elemento mudar de tag.
// É este bloco que impede a "adaptação ao sistema atual".
// ═══════════════════════════════════════════════════════════════════════════

test('FID-01 — a geometria do cabeçalho bate com o protótipo', async (contexto) => {
    await contexto.test('<header> :31 — flex-end, space-between, gap 24', () => {
        assert.equal((HTML_CAB_CHEIO.match(/<header/g) ?? []).length, 1, 'o cabeçalho tem UM <header>');
        assertClasses(
            tagEm(HTML_CAB_CHEIO, indiceDaTag(HTML_CAB_CHEIO, 'header')),
            ['flex', 'items-end', 'justify-between', 'gap-[24px]'],
            '<header> :31',
        );
    });

    await contexto.test('H1 :33 — 28px / 600 / leading-none / tracking -0.025em / margin 0', () => {
        assert.equal((HTML_CAB_CHEIO.match(/<h1/g) ?? []).length, 1, 'a tela tem UM H1');
        assertClasses(
            tagEm(HTML_CAB_CHEIO, indiceDaTag(HTML_CAB_CHEIO, 'h1')),
            ['m-0', 'text-[28px]', 'font-semibold', 'leading-none', 'tracking-[-0.025em]'],
            'H1 :33',
        );
    });

    await contexto.test('coluna do título :32 — gap 6', () => {
        // Localizada pelo H1, não por classes: é a tag que o envolve.
        const h1 = indiceDaTag(HTML_CAB_CHEIO, 'h1');
        assertClasses(
            tagQueEnvolve(HTML_CAB_CHEIO, h1, 'div'),
            ['flex', 'flex-col', 'gap-[6px]'],
            'coluna do título :32',
        );
    });

    await contexto.test('subtítulo :34 — 13.5px e a cor neutra (nunca text-sm)', () => {
        // Por ÁRVORE: a primeira <span> dentro da coluna do título.
        const coluna = tagQueEnvolve(HTML_CAB_CHEIO, indiceDaTag(HTML_CAB_CHEIO, 'h1'), 'div');
        assert.ok(coluna, 'coluna do título não localizada');
        const iSub = primeiraTagApos(HTML_CAB_CHEIO, indiceDaTag(HTML_CAB_CHEIO, 'h1'), 'span');
        // E ela É a do subtítulo — conferido pelo texto que vem logo depois.
        assert.ok(
            HTML_CAB_CHEIO.indexOf('Ofertas ativas e históricas') > iSub,
            'a <span> achada não é a do subtítulo',
        );
        assertClasses(tagEm(HTML_CAB_CHEIO, iSub), ['text-[13.5px]', 'text-pub-neutral-txt'], 'subtítulo :34');
    });

    await contexto.test('bloco da direita :36 — gap 10', () => {
        // ⚠️ Por ÁRVORE com índice ABSOLUTO: é o 2º <div> do cabeçalho (o 1º é
        // a coluna do título). Localizar por classes pegaria o irmão errado.
        assertClasses(
            tagEm(HTML_CAB_CHEIO, indiceDaTag(HTML_CAB_CHEIO, 'div', 2)),
            ['flex', 'items-center', 'gap-[10px]'],
            'bloco da direita :36',
        );
    });

    await contexto.test('"Sincronizado X" :37 — 12.5px, gap 6, pr 4', () => {
        // ⚠️ NÃO dá para achar esta <span> pelo texto com `lastIndexOf('<span')`:
        // o DOT é uma <span> que vem ANTES do texto, dentro dela — e foi
        // exatamente esse "irmão errado" que a primeira versão deste gate
        // mediu. O caminho certo é a árvore: 2º <div> → primeira <span>.
        const iSinc = primeiraTagApos(HTML_CAB_CHEIO, indiceDaTag(HTML_CAB_CHEIO, 'div', 2), 'span');
        assert.ok(HTML_CAB_CHEIO.indexOf('Sincronizado há 25 min') > iSinc, 'a <span> achada não é a de sincronização');
        assertClasses(
            tagEm(HTML_CAB_CHEIO, iSinc),
            ['flex', 'items-center', 'gap-[6px]', 'pr-[4px]', 'text-[12.5px]', 'text-pub-neutral-txt'],
            '"Sincronizado X" :37',
        );
    });

    await contexto.test('dot verde :38 — 6×6, redondo, bg-pub-ok', () => {
        // O dot é a primeira <span> DENTRO da span de sincronização.
        const iSinc = primeiraTagApos(HTML_CAB_CHEIO, indiceDaTag(HTML_CAB_CHEIO, 'div', 2), 'span');
        const dot = tagEm(HTML_CAB_CHEIO, primeiraTagApos(HTML_CAB_CHEIO, iSinc, 'span'));
        assertClasses(
            dot,
            ['h-[6px]', 'w-[6px]', 'flex-none', 'rounded-full', 'bg-pub-ok'],
            'dot verde :38',
        );
        assert.equal(atributo(dot, 'aria-hidden'), 'true', 'o dot é decorativo');
    });

    await contexto.test('botão "Atualizar" :39 — 36px, raio 9, borda e fundo do protótipo', () => {
        const botao = tagEm(HTML_CAB_CHEIO, indiceDaTag(HTML_CAB_CHEIO, 'button'));
        assertClasses(
            botao,
            [
                'flex', 'h-[36px]', 'flex-none', 'items-center', 'gap-[7px]', 'rounded-pub-control',
                'border', 'border-pub-line-strong', 'bg-pub-surface', 'px-[14px]', 'text-[13px]',
                'font-medium', 'text-pub-txt', 'hover:bg-pub-hover-2', 'cursor-pointer',
            ],
            'botão "Atualizar" :39',
        );
        assert.equal(atributo(botao, 'type'), 'button', 'botão sem type="button" submete formulário');
        // ⚠️ `/disabled/` casaria com a classe `disabled:opacity-40` e seria
        // asserção vazia. Com o `=` ela mede o ATRIBUTO.
        assert.doesNotMatch(botao, /disabled=/, 'o botão em repouso não está desabilitado');
    });

    await contexto.test('o botão girando :41 — spinner 13×13, borda 1.6, topo amarelo, disabled=', () => {
        const botao = tagEm(HTML_CAB_GIRANDO, indiceDaTag(HTML_CAB_GIRANDO, 'button'));
        assert.match(botao, /disabled=/, 'girando, o botão TEM de estar desabilitado');
        assert.ok(classesDa(botao).includes('cursor-wait'), botao);

        const spinner = tagEm(
            HTML_CAB_GIRANDO,
            primeiraTagApos(HTML_CAB_GIRANDO, indiceDaTag(HTML_CAB_GIRANDO, 'button'), 'span'),
        );
        assertClasses(
            spinner,
            [
                'h-[13px]', 'w-[13px]', 'flex-none', 'animate-pub-spin', 'rounded-full',
                'border-[1.6px]', 'border-pub-line-off', 'border-t-pub-accent',
            ],
            'spinner :41',
        );
        // E girando NÃO há ícone de refresh (o protótipo troca um pelo outro).
        assert.ok(!HTML_CAB_GIRANDO.includes('<svg'), 'o ícone e o spinner apareceram juntos');
    });

    await contexto.test('ícone de refresh :44 — viewBox do protótipo e stroke-width 1.6', () => {
        const svg = tagEm(HTML_CAB_CHEIO, indiceDaTag(HTML_CAB_CHEIO, 'svg'));
        // ⚠️ viewBox do protótipo é 16; o do Lucide é 24 e exigiria ×1,5 no
        // stroke. SVG inline mantém a medida literal.
        assert.equal(atributo(svg, 'viewBox'), '0 0 16 16', 'viewBox do Lucide (24) no lugar do protótipo (16)');
        assert.equal(atributo(svg, 'stroke-width'), '1.6');
        assert.equal(atributo(svg, 'width'), '14');
        assert.equal(atributo(svg, 'height'), '14');
        assert.equal(atributo(svg, 'aria-hidden'), 'true');
    });
});

test('FID-01 — a geometria da faixa de sucesso bate com o protótipo', async (contexto) => {
    await contexto.test('a faixa :53 — #14120A, borda amarela 35%, raio 12, padding 12/14/12/16', () => {
        assertClasses(
            tagEm(HTML_FAIXA_CHEIA, indiceDaTag(HTML_FAIXA_CHEIA, 'div')),
            [
                'flex', 'items-center', 'gap-[14px]', 'rounded-pub-panel', 'border',
                'border-pub-accent/[0.35]', 'bg-pub-row-new', 'py-[12px]', 'pl-[16px]', 'pr-[14px]',
            ],
            'a faixa :53',
        );
    });

    await contexto.test('círculo do check :54 — 26×26 amarelo, e o check :55 em stroke-pub-page', () => {
        const circulo = tagEm(HTML_FAIXA_CHEIA, indiceDaTag(HTML_FAIXA_CHEIA, 'span'));
        assertClasses(
            circulo,
            ['grid', 'h-[26px]', 'w-[26px]', 'flex-none', 'place-items-center', 'rounded-full', 'bg-pub-accent'],
            'círculo do check :54',
        );
        const svg = tagEm(HTML_FAIXA_CHEIA, indiceDaTag(HTML_FAIXA_CHEIA, 'svg'));
        assert.equal(atributo(svg, 'viewBox'), '0 0 16 16');
        assert.equal(atributo(svg, 'stroke-width'), '2.2');
        assert.equal(atributo(svg, 'width'), '13');
        assert.deepEqual([...classesDa(svg)].sort(), ['fill-none', 'stroke-pub-page'].sort());
    });

    await contexto.test('coluna do texto :57 e as duas linhas :58/:59', () => {
        // ⚠️ Por ÁRVORE com índices ABSOLUTOS: <span> 1 = o círculo do check,
        // 2 = a linha 1, 3 = a linha 2. As duas linhas têm tags idênticas em
        // tudo menos a classe, então localizar por classe seria circular.
        const iLinha1 = indiceDaTag(HTML_FAIXA_CHEIA, 'span', 2);
        const iLinha2 = indiceDaTag(HTML_FAIXA_CHEIA, 'span', 3);
        // Conferência de que a árvore é a que eu penso, pelo texto que segue.
        assert.ok(HTML_FAIXA_CHEIA.indexOf('2 publicações criadas agora') > iLinha1);
        assert.ok(HTML_FAIXA_CHEIA.indexOf('Fase 2 · Combo') > iLinha2);

        assertClasses(tagEm(HTML_FAIXA_CHEIA, iLinha1), ['text-[13.5px]', 'font-semibold'], 'linha 1 :58');
        assertClasses(
            tagEm(HTML_FAIXA_CHEIA, iLinha2),
            ['text-[12.5px]', 'text-pub-neutral-txt'],
            'linha 2 :59',
        );

        // A coluna é o 2º <div> (o 1º é a faixa inteira).
        assertClasses(
            tagEm(HTML_FAIXA_CHEIA, indiceDaTag(HTML_FAIXA_CHEIA, 'div', 2)),
            ['flex', 'min-w-0', 'flex-1', 'flex-col', 'gap-[2px]'],
            'coluna do texto :57',
        );
    });

    await contexto.test('"Localizar na lista" :61 — 32px, raio 8 LITERAL, amarelo com texto escuro', () => {
        const botao = tagQueEnvolve(HTML_FAIXA_CHEIA, HTML_FAIXA_CHEIA.indexOf('Localizar na lista'), 'button');
        assertClasses(
            botao,
            [
                'h-[32px]', 'flex-none', 'cursor-pointer', 'rounded-[8px]', 'bg-pub-accent',
                'px-[14px]', 'text-[12.5px]', 'font-medium', 'text-pub-page',
            ],
            '"Localizar na lista" :61',
        );
        // ⚠️ 8px é medida avulsa: não existe token de 8. `rounded-pub-control-sm`
        // (7px) e `rounded-pub-sub` (10px) seriam arredondamento (FID-03).
        const classes = classesDa(botao);
        assert.ok(!classes.includes('rounded-pub-control-sm'));
        assert.ok(!classes.includes('rounded-pub-sub'));
        assert.doesNotMatch(botao, /disabled=/, '"Localizar" desenhado desabilitado — ou tem destino ou não nasce');
    });

    await contexto.test('o "×" :62 — 32×32, raio 8, hover do toast, e nome acessível', () => {
        const pos = HTML_FAIXA_CHEIA.indexOf('title="Dispensar"');
        const botao = tagQueEnvolve(HTML_FAIXA_CHEIA, pos, 'button');
        assertClasses(
            botao,
            [
                'grid', 'h-[32px]', 'w-[32px]', 'flex-none', 'cursor-pointer', 'place-items-center',
                'rounded-[8px]', 'text-pub-txt-3', 'hover:bg-pub-hover-toast',
            ],
            'o "×" :62',
        );
        // IDENT-07: o title e o nome acessível são o MESMO texto.
        assert.equal(atributo(botao, 'title'), 'Dispensar');
        assert.equal(atributo(botao, 'aria-label'), 'Dispensar');

        const svg = tagEm(HTML_FAIXA_CHEIA, indiceDaTag(HTML_FAIXA_CHEIA, 'svg', 2));
        assert.equal(atributo(svg, 'viewBox'), '0 0 16 16');
        assert.equal(atributo(svg, 'stroke-width'), '1.7');
        assert.equal(atributo(svg, 'width'), '14');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. EXMP-01 — NENHUM BOTÃO SEM DESTINO, E "NUNCA MEDIMOS" ≠ "É ZERO"
//
// O que precisaria estar errado para falhar: desenhar "Localizar na lista" sem
// handler ou sem `busca_sugerida`/`ml_item_id` (botão morto); desenhar o "×"
// sem `onDispensar`; ou escrever "Sincronizado há —"/"nunca" quando NINGUÉM
// mediu a coleta.
//
// ⚠️ Toda asserção de ausência aqui roda com as OUTRAS props preenchidas — é o
// 10º caso da lista do learnings: ausência medida com dado vazio é vazia por
// construção.
// ═══════════════════════════════════════════════════════════════════════════

test('EXMP-01 — nenhum enfeite: botão sem destino não nasce', async (contexto) => {
    await contexto.test('sem onLocalizar o botão NÃO existe — com todo o resto cheio', () => {
        const html = render(Faixa, { resumo: RESUMO_CHEIO, onDispensar: () => {} });
        assert.ok(html.includes('Fase 2 · Combo'), 'a faixa inteira desapareceu — o caso não prova nada');
        assert.ok(!html.includes('Localizar na lista'), html);
        assert.ok(html.includes('title="Dispensar"'), 'o "×" também sumiu — a ausência não é do botão certo');
    });

    await contexto.test('sem busca_sugerida (ou sem ml_item_id) o botão NÃO existe', () => {
        for (const chave of ['busca_sugerida', 'ml_item_id']) {
            const resumo = { ...RESUMO_CHEIO, [chave]: null };
            const html = render(Faixa, { resumo, onLocalizar: () => {}, onDispensar: () => {} });
            assert.ok(html.includes('2 publicações criadas agora'), `faixa sumiu com ${chave} nulo`);
            assert.ok(!html.includes('Localizar na lista'), `${chave} nulo e o botão nasceu sem destino`);
        }
        // ⚠️ ISCA da asserção acima: com as DUAS chaves em forma de string o
        // botão aparece. Sem esta linha, as negativas passariam por construção.
        assert.ok(HTML_FAIXA_CHEIA.includes('Localizar na lista'));
    });

    await contexto.test('⚠️ número no lugar da string NÃO vale como destino (`textoUtil(7)` → "7")', () => {
        const html = render(Faixa, {
            resumo: { ...RESUMO_CHEIO, ml_item_id: 1234567890 },
            onLocalizar: () => {},
            onDispensar: () => {},
        });
        assert.ok(!html.includes('Localizar na lista'), 'id numérico virou string e montaria destino inválido');
    });

    await contexto.test('sem onDispensar o "×" NÃO existe — com todo o resto cheio', () => {
        const html = render(Faixa, { resumo: RESUMO_CHEIO, onLocalizar: () => {} });
        assert.ok(html.includes('Localizar na lista'), 'a faixa inteira desapareceu');
        assert.ok(!html.includes('title="Dispensar"'), html);
    });

    await contexto.test('sem onClick o botão "Atualizar" NÃO é desenhado', () => {
        assert.equal(render(BotaoAtualizar, {}), '');
        assert.equal(render(BotaoAtualizar, { onClick: null, atualizando: true }), '');
        // ISCA: com onClick função ele existe.
        assert.match(render(BotaoAtualizar, { onClick: () => {} }), /<button/);
    });
});

test('LIST-01 — "nunca medimos" não é "medimos e é zero"', async (contexto) => {
    await contexto.test('nunca_coletado = true → o bloco não aparece, mas o botão continua', () => {
        // ⚠️ `rotulo` vem PREENCHIDO de propósito: é o que prova que a ausência
        // vem da regra e não da falta de dado.
        const html = render(Cabecalho, {
            sincronizacao: { rotulo: 'há 3 h', horas: 3, coletado_em: '2026-10-13T09:00:00Z', nunca_coletado: true },
            acaoAtualizar: React.createElement(BotaoAtualizar, { onClick: () => {} }),
        });
        assert.ok(html.includes('Publicações'), 'o cabeçalho inteiro desapareceu — o caso não prova nada');
        assert.ok(!html.includes('Sincronizado'), html);
        assert.ok(!html.includes('há 3 h'), 'o rótulo apareceu mesmo com nunca_coletado');
        assert.ok(!html.includes('—'), 'travessão de "não medimos" na tela');
        assert.ok(html.includes('Atualizar'), 'o botão que RESOLVE a falta de coleta também sumiu');
    });

    await contexto.test('sincronizacao nula/ausente → mesma coisa', () => {
        for (const props of [{ sincronizacao: null }, {}, { sincronizacao: {} }]) {
            const html = render(Cabecalho, props);
            assert.ok(html.includes('Publicações'), `cabeçalho vazio em ${JSON.stringify(props)}`);
            assert.ok(!html.includes('Sincronizado'), html);
        }
        // ISCA: com `rotulo` string o bloco aparece — a negativa não é vazia.
        assert.ok(HTML_CAB_CHEIO.includes('Sincronizado'));
    });

    await contexto.test('⚠️ DATA POR REGEX: rótulo com cara de ISO não vai para a tela', () => {
        for (const rotulo of ['2026-10-13', '2026-10-13T12:00:00Z', 'sincronizado em 2026-10-13']) {
            const html = render(Cabecalho, { sincronizacao: { rotulo, nunca_coletado: false } });
            assert.ok(!html.includes('Sincronizado'), `o carimbo cru "${rotulo}" foi para a tela`);
            assert.ok(!html.includes('2026-'), html);
        }
        // E o rótulo humano continua passando.
        assert.ok(render(Cabecalho, { sincronizacao: { rotulo: 'ontem' } }).includes('Sincronizado ontem'));
    });

    await contexto.test('nenhum new Date() nos dois arquivos (nem Date.now, nem toLocale*)', () => {
        for (const [nome, fonte] of [[REL_CABECALHO, FONTE_CABECALHO], [REL_FAIXA, FONTE_FAIXA]]) {
            assert.doesNotMatch(fonte, /new Date\(/, `${nome}: data montada no cliente`);
            assert.doesNotMatch(fonte, /Date\.now\(/, nome);
            assert.doesNotMatch(fonte, /toLocaleDateString|toLocaleString|Intl\.DateTimeFormat/, nome);
        }
    });
});

test('LIST-02 — a faixa não existe sem resumo (e o resumo é só o do servidor)', async (contexto) => {
    await contexto.test('resumo nulo/ausente → HTML VAZIO, com os dois handlers cheios', () => {
        for (const props of [
            { resumo: null, onLocalizar: () => {}, onDispensar: () => {} },
            { onLocalizar: () => {}, onDispensar: () => {} },
        ]) {
            assert.equal(render(Faixa, props), '', 'a faixa nasceu sem resumo');
        }
        // ISCA: com resumo ela existe — a asserção de vazio não é por construção.
        assert.match(HTML_FAIXA_CHEIA, /<div/);
    });

    await contexto.test('sem contagem confiável a faixa não anuncia nada', () => {
        for (const total of ['2', 0, -3, 2.5, NaN, Infinity, null, undefined, {}, [2]]) {
            const html = render(Faixa, {
                resumo: { ...RESUMO_CHEIO, total },
                onLocalizar: () => {},
                onDispensar: () => {},
            });
            // ⚠️ `JSON.stringify` mente aqui: NaN e Infinity viram 'null'.
            assert.equal(html, '', `total inválido aceito: ${descreve(total)} (typeof ${typeof total})`);
        }
        // ISCA: 2 (inteiro) passa.
        assert.notEqual(render(Faixa, { resumo: { total: 2 } }), '');
    });

    await contexto.test('pedaço ausente é OMITIDO, nunca virado em "—"', () => {
        const html = render(Faixa, {
            resumo: { total: 2, produto_nome: 'P', fase_rotulo: null, tipos_rotulo: 'Clássico', autor_rotulo: '   ' },
            onDispensar: () => {},
        });
        assert.ok(html.includes('2 publicações criadas agora · P'), html);
        assert.ok(html.includes('Clássico'), html);
        assert.ok(!html.includes('—'), 'travessão de preenchimento na faixa');
        assert.ok(!html.includes('· ·'), 'separador duplo de pedaço omitido');
        assert.ok(!html.includes('Clássico ·'), 'separador sobrando no fim da linha 2');
    });

    await contexto.test('sem NENHUM rótulo, a linha 2 não é desenhada', () => {
        const html = render(Faixa, { resumo: { total: 1 }, onDispensar: () => {} });
        assert.equal((html.match(/<span class="text-\[12\.5px\]/g) ?? []).length, 0, html);
        // ISCA: com rótulo ela existe.
        assert.equal((HTML_FAIXA_CHEIA.match(/<span class="text-\[12\.5px\]/g) ?? []).length, 1);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. REND — A TELA PRETA DE 07/10/2026
//
// O que precisaria estar errado para falhar: qualquer campo do servidor
// chegando como OBJETO (ou `NaN`, ou ausente) e sendo renderizado como texto.
// Foi assim que uma página inteira deste projeto ficou preta, e na Fase 178
// "grupo nulo derrubava o painel inteiro" — só o render adverso pegou.
// ═══════════════════════════════════════════════════════════════════════════

/** As formas inesperadas que o Inertia já entregou neste projeto. */
const FORMAS_ADVERSAS = [
    undefined, null, {}, [], 0, -1, 1, NaN, Infinity, '', 'texto', true, false,
    { a: 1 }, [{ a: 1 }], { toString: null }, () => {},
];

const SUJO = /\[object Object\]|NaN|undefined|Invalid Date/;

test('REND — render adverso: objeto, nulo e ausente em TODA prop', async (contexto) => {
    await contexto.test('CabecalhoDePublicacoes — cada prop, cada forma', () => {
        for (const forma of FORMAS_ADVERSAS) {
            for (const props of [
                { sincronizacao: forma },
                { acaoAtualizar: forma },
                { sincronizacao: forma, acaoAtualizar: forma },
            ]) {
                let saida = null;
                assert.doesNotThrow(
                    () => { saida = render(Cabecalho, props); },
                    `derrubou com ${descreve(forma)} (typeof ${typeof forma})`,
                );
                assert.doesNotMatch(saida, SUJO, `sujeira na tela com ${descreve(forma)}`);
                // O cabeçalho NUNCA deixa de desenhar o título.
                assert.ok(saida.includes('Publicações'), `H1 sumiu com ${descreve(forma)}`);
            }
        }
    });

    await contexto.test('CabecalhoDePublicacoes — cada CAMPO de sincronizacao', () => {
        const CAMPOS = ['coletado_em', 'horas', 'rotulo', 'nunca_coletado', 'chave_desconhecida'];
        for (const campo of CAMPOS) {
            for (const forma of FORMAS_ADVERSAS) {
                const props = { sincronizacao: { ...SINC_OK, [campo]: forma } };
                let saida = null;
                assert.doesNotThrow(
                    () => { saida = render(Cabecalho, props); },
                    `derrubou com ${campo}=${descreve(forma)}`,
                );
                assert.doesNotMatch(saida, SUJO, `${campo}=${descreve(forma)} sujou a tela`);
            }
        }
        // ISCA: a chave desconhecida não apaga o bloco que o resto sustenta.
        assert.ok(
            render(Cabecalho, { sincronizacao: { ...SINC_OK, bla: { x: 1 } } }).includes('Sincronizado há 25 min'),
        );
    });

    await contexto.test('BotaoAtualizarDoCabecalho — cada prop, cada forma', () => {
        for (const forma of FORMAS_ADVERSAS) {
            for (const props of [
                { onClick: forma },
                { onClick: () => {}, atualizando: forma },
                { onClick: () => {}, cooldown: forma },
            ]) {
                let saida = null;
                assert.doesNotThrow(() => { saida = render(BotaoAtualizar, props); }, descreve(forma));
                assert.doesNotMatch(saida, SUJO, `sujeira no botão com ${descreve(forma)}`);
            }
        }
    });

    await contexto.test('FaixaDeSucesso — cada prop, cada forma', () => {
        for (const forma of FORMAS_ADVERSAS) {
            for (const props of [
                { resumo: forma },
                { resumo: RESUMO_CHEIO, onLocalizar: forma, onDispensar: forma },
                { resumo: forma, onLocalizar: forma, onDispensar: forma },
            ]) {
                let saida = null;
                assert.doesNotThrow(() => { saida = render(Faixa, props); }, descreve(forma));
                assert.doesNotMatch(saida, SUJO, `sujeira na faixa com ${descreve(forma)}`);
            }
        }
    });

    await contexto.test('FaixaDeSucesso — cada CAMPO do resumo', () => {
        const CAMPOS = [
            'total', 'produto_nome', 'fase_rotulo', 'tipos_rotulo', 'autor_rotulo',
            'busca_sugerida', 'ml_item_id', 'chave_desconhecida',
        ];
        for (const campo of CAMPOS) {
            for (const forma of FORMAS_ADVERSAS) {
                const props = {
                    resumo: { ...RESUMO_CHEIO, [campo]: forma },
                    onLocalizar: () => {},
                    onDispensar: () => {},
                };
                let saida = null;
                assert.doesNotThrow(
                    () => { saida = render(Faixa, props); },
                    `derrubou com ${campo}=${descreve(forma)}`,
                );
                assert.doesNotMatch(saida, SUJO, `${campo}=${descreve(forma)} sujou a faixa`);
            }
        }
    });

    await contexto.test('⚠️ ISCA do detector de sujeira: ele PEGA [object Object] e NaN', () => {
        // Sem isto, `doesNotMatch(SUJO)` passaria mesmo com o regex quebrado.
        assert.match(renderToStaticMarkup(React.createElement('span', {}, String({ a: 1 }))), SUJO);
        assert.match(renderToStaticMarkup(React.createElement('span', {}, String(NaN))), SUJO);
        assert.match(renderToStaticMarkup(React.createElement('span', {}, String(new Date('x')))), SUJO);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. D-11 + REND-04 — a tela não escreve, e nenhuma flag nasce fora do callback
//
// O que precisaria estar errado para falhar: alguém pôr `router.put/post/...`
// nestes dois arquivos (a tela de Publicações tem UM `router.post`, o
// `meus.atualizar` que ENFILEIRA, e ele mora na PÁGINA), ou trazer de volta o
// vocabulário antigo.
// ═══════════════════════════════════════════════════════════════════════════

test('D-11 — nenhum dos dois componentes escreve, nem navega por conta própria', async (contexto) => {
    for (const [nome, fonte] of [[REL_CABECALHO, FONTE_CABECALHO], [REL_FAIXA, FONTE_FAIXA]]) {
        await contexto.test(`${nome} — zero router.* e zero fetch/axios`, () => {
            assert.doesNotMatch(fonte, /\brouter\s*\./, `${nome} navega/escreve por conta própria`);
            assert.doesNotMatch(fonte, /from '@inertiajs\/react'/, `${nome} importou o router`);
            assert.doesNotMatch(fonte, /\baxios\b|\bfetch\(/, nome);
        });

        await contexto.test(`${nome} — nenhum .map() (REND-04: flag de escopo e o Rollup)`, () => {
            // ⚠️ O Rollup ELIMINA variável de escopo do componente usada dentro
            // de `.map()` → `ReferenceError` só no bundle de produção. Nenhum
            // dos dois usa `.map()`; se um passar a usar, a regra volta a
            // valer e esta asserção obriga quem mexer a reler o learnings.
            assert.doesNotMatch(fonte, /\.map\(/, `${nome} ganhou um .map() — toda flag nasce DENTRO do callback`);
        });

        await contexto.test(`${nome} — importa textoUtil da casa dela, não recria`, () => {
            assert.match(
                fonte,
                /import \{ textoUtil \} from '@\/Components\/Mlb\/Publicador\/BarraDaConta'/,
                `${nome} recriou o textoSeguro/textoUtil — a casa dela é BarraDaConta desde a Fase 172`,
            );
        });
    }

    await contexto.test('a página de hoje segue com UM router.post (meus.atualizar) — nada migrou para cá', () => {
        // ⚠️ Território: `MeusAnuncios.jsx` é do plano 180-10 e NÃO foi tocado.
        // Esta asserção existe para provar que o `router.post` continua LÁ —
        // sem ela, "zero router aqui" passaria também se a tela tivesse perdido
        // o Atualizar de vez.
        const pagina = lerSemComentarios('resources/js/Pages/Mlb/MeusAnuncios.jsx');
        assert.equal((pagina.match(/router\.post\(/g) ?? []).length, 1, 'a página perdeu (ou ganhou) router.post');
        assert.match(pagina, /mlb\.anuncios\.meus\.atualizar/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. O GATE CONTRA CLASSE EM `.js`
//
// ⚠️ RAZÃO: `tailwind.config.js:12` varre `./resources/js/**/*.jsx` e **NÃO**
// `*.js`. Classe do Tailwind num `.js` de `resources/js` NÃO gera CSS e NÃO
// EXISTE PARA A TELA — com todos os testes de render passando, porque a classe
// está no HTML. Obedecer um plano que mandava o contrário quase deixou o anel
// da Jornada sem cor na Fase 179.
//
// ⚠️ Hoje `publicacoes/` tem ZERO `.js`, então a varredura é VAZIA POR
// CONSTRUÇÃO — e é por isso que este bloco tem (a) a asserção de que o
// diretório foi achado e (b) uma ISCA chamando a função direto, sem escrever no
// repo. O diretório ganha `atalhosDaLista.js` no plano 180-11.
// ═══════════════════════════════════════════════════════════════════════════

const RE_CLASSE_TAILWIND = /\b(?:text|bg|border|rounded|flex|grid|h|w|gap|p[xytblr]?|m[xytblr]?)-\[/;

/** `true` se o conteúdo tem cara de carregar classe do Tailwind. */
function acusaClasse(conteudo) {
    return RE_CLASSE_TAILWIND.test(conteudo) || /className/.test(conteudo);
}

test('TAILWIND — nenhum .js de publicacoes/ carrega classe (o content só varre .jsx)', async (contexto) => {
    const abs = path.resolve(RAIZ, DIR_PUB);

    await contexto.test('o diretório EXISTE (sem isto a varredura mediria nada)', () => {
        assert.ok(fs.existsSync(abs), `${DIR_PUB} não existe — o caminho está errado?`);
        const jsx = fs.readdirSync(abs).filter((n) => n.endsWith('.jsx'));
        assert.ok(jsx.length >= 2, `esperava ao menos 2 .jsx em ${DIR_PUB}, achei ${jsx.length}`);
    });

    await contexto.test('⚠️ ISCA: a função de varredura ACUSA classe e className', () => {
        assert.ok(acusaClasse("const MAPA = { ok: 'text-[13.5px] bg-pub-ok' };"), 'isca de classe não acusada');
        assert.ok(acusaClasse('export const x = <div className="a" />;'), 'isca de className não acusada');
        assert.ok(acusaClasse("const b = 'rounded-[8px]';"));
        // E não acusa um módulo limpo — senão ela acusaria tudo.
        assert.ok(!acusaClasse('export const TOTAL = 2;\nexport function somar(a, b) { return a + b; }'));
    });

    const jsDoDiretorio = fs.readdirSync(abs).filter((nome) => nome.endsWith('.js'));

    await contexto.test(`os ${jsDoDiretorio.length} módulo(s) .js do diretório estão limpos`, () => {
        for (const nome of jsDoDiretorio) {
            const fonte = fs.readFileSync(path.join(abs, nome), 'utf8');
            assert.ok(
                !acusaClasse(fonte),
                `${DIR_PUB}/${nome}: classe do Tailwind num .js — o content não varre .js, a classe NÃO gera CSS`,
            );
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. FIDELIDADE PELO CSS CONSTRUÍDO — presença de classe NÃO é fidelidade
//
// O que precisaria estar errado para falhar: a classe estar presente com o
// VALOR errado (`bg-pub-row-new` apontando para outro hex, a opacidade 0.35
// virando 0.3, o `13.5px` virando 14px) — nada disso um gate de presença pega.
//
// ⚠️ As SETE armadilhas do medidor, todas aplicadas abaixo:
//  (i)   token sólido NÃO sai em hex — sai `rgb(20 18 10 / var(--tw-bg-opacity, 1))`, decimal;
//  (ii)  `stroke`/`fill` sólidos, ao contrário, SAEM EM HEX LITERAL;
//  (iii) o modificador de opacidade sai em HEX DE 8 DÍGITOS, e o byte do alfa é
//        o único jeito de distinguir 0.35 (0x59) de 0.3 (0x4d);
//  (iv)  o cssnano tira o zero da frente, inclusive depois do menos
//        (`-0.025em` → `-.025em`);
//  (v)   o seletor vem ESCAPADO com barra invertida (`.text-\[13\.5px\]`);
//  (vi)  na variante o prefixo faz parte do nome (`.hover\:bg-pub-hover-toast:hover`);
//  (vii) escapar SÓ a barra quebra em classe com colchete e ponto — escapamos
//        TODOS os metacaracteres de regex.
// ═══════════════════════════════════════════════════════════════════════════

/** Escapa TODOS os metacaracteres de regex (armadilha vii). */
function escapaRegex(texto) {
    return texto.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** O seletor que o Tailwind emite: `.` + o nome com os metacaracteres de CSS escapados (armadilhas v/vi). */
function seletorDe(classe) {
    return `.${classe.replace(/[[\].:/%]/g, (ch) => `\\${ch}`)}`;
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
    // um build velho e reprovaria o código certo (ou aprovaria o errado).
    const mtimeCss = fs.statSync(cssAbs).mtimeMs;
    const mtimeFontes = Math.max(
        fs.statSync(path.resolve(RAIZ, REL_CABECALHO)).mtimeMs,
        fs.statSync(path.resolve(RAIZ, REL_FAIXA)).mtimeMs,
    );
    if (mtimeCss < mtimeFontes) {
        return { motivo: `o CSS construído (${entrada.css[0]}) é mais antigo que os componentes — rode \`npm run build\`` };
    }

    return { arquivo: entrada.css[0], css: fs.readFileSync(cssAbs, 'utf8') };
}

const CSS = carregarCssConstruido();

test('FID — as classes GERAM CSS com o valor certo (não só existem)', async (contexto) => {
    if (CSS.motivo) {
        // ⚠️ `skip` com razão ESCRITA (e `skipped N` aparece no sumário do
        // `node --test`, então não se esconde), em vez de `return` calado.
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
        // (0 ocorrências em resources/, app/, tests/, tailwind.config.js) —
        // é a lição do `w-[13px]`, que parecia inventado e saiu presente.
        for (const isca of ['bg-pub-isca-9q7', 'text-[99.5px]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS.css.includes(isca), `a string da isca ${isca} aparece no CSS`);
        }
        // E a sonda ACHA o que existe — sem isto ela poderia estar sempre cega.
        assert.notEqual(regraDe('text-[13.5px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('(v) subtítulo — .text-\\[13\\.5px\\] = font-size:13.5px', () => {
        assert.match(regraDe('text-[13.5px]'), /font-size:\s*13\.5px/);
    });

    await contexto.test('H1 — 28px e (iv) o zero comido depois do menos', () => {
        assert.match(regraDe('text-[28px]'), /font-size:\s*28px/);
        const tracking = regraDe('tracking-[-0.025em]');
        assert.notEqual(tracking, null, 'tracking-[-0.025em] não gerou CSS — o H1 perde o letter-spacing');
        // ⚠️ `-0.025em` sai `-.025em`. Esperar o zero dá falsa falta.
        assert.match(tracking, /letter-spacing:\s*-\.?0?\.?025em/);
        assert.ok(/-\.025em|-0\.025em/.test(tracking), tracking);
    });

    await contexto.test('botão — .h-\\[36px\\] = height:36px', () => {
        assert.match(regraDe('h-[36px]'), /height:\s*36px/);
    });

    await contexto.test('(i) a faixa — bg-pub-row-new sai em rgb() DECIMAL (#14120A)', () => {
        const regra = regraDe('bg-pub-row-new');
        assert.notEqual(regra, null, 'o fundo da faixa não gerou CSS');
        // 0x14=20, 0x12=18, 0x0A=10 — token sólido NÃO compila para hex.
        assert.match(regra, /rgb\(\s*20\s+18\s+10\b/, regra);
        // CONTROLE de valor: não é o `bg-pub-surface` (#111111 → 17 17 17).
        assert.ok(!/rgb\(\s*17\s+17\s+17\b/.test(regra), 'a faixa está com o fundo de card, não o #14120A');
    });

    await contexto.test('(iii) a borda — o BYTE DO ALFA prova 0.35 e não 0.3', () => {
        const regra = regraDe('border-pub-accent/[0.35]');
        assert.notEqual(regra, null, 'a borda amarela de 35% não gerou CSS');
        // round(0.35 × 255) = 89 = 0x59. 0.3 daria 0x4d — é o único jeito de distinguir.
        assert.match(regra, /#facc1559\b/i, regra);
        assert.ok(!/#facc154d\b/i.test(regra), 'a opacidade caiu para 0.3');
    });

    await contexto.test('(vi) o "×" — a variante leva o prefixo no NOME da classe', () => {
        const regra = regraDe('hover:bg-pub-hover-toast');
        assert.notEqual(regra, null, 'o hover do "×" não gerou CSS — e ele era 0 no build anterior a esta fase');
        // #26220F → 38 34 15
        assert.match(regra, /rgb\(\s*38\s+34\s+15\b/, regra);
        // E o seletor emitido traz o `:hover`.
        assert.ok(
            CSS.css.includes('.hover\\:bg-pub-hover-toast:hover'),
            'o seletor da variante não tem o :hover',
        );
    });

    await contexto.test('(ii) o check — stroke sólido SAI EM HEX LITERAL (#0A0A0A)', () => {
        const regra = regraDe('stroke-pub-page');
        assert.notEqual(regra, null, 'o stroke do check não gerou CSS');
        assert.match(regra, /stroke:\s*#0a0a0a/i, regra);
    });

    await contexto.test('o raio 8 LITERAL da faixa gera CSS (não é token)', () => {
        assert.match(regraDe('rounded-[8px]'), /border-radius:\s*8px/);
        // E o token de 12px da faixa.
        assert.match(regraDe('rounded-pub-panel'), /border-radius:\s*12px/);
    });
});
