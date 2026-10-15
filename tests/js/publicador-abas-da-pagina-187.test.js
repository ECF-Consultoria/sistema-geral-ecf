import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 03 — AS DUAS ABAS da seção A1 do cabeçalho de Publicações
// (ABA-01, ABA-02, ABA-05), a montagem delas na página e a prova de que a
// LISTA não mudou (ABA-06 / critério 4).
//
// ⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Publicacoes v2.dc.html`.
// A outra cópia (`new_layout_publicador/Publicacoes v2.dc.html`) é a versão
// ANTIGA — a que está NO AR (Fase 180) — e é a ERRADA para esta seção: ela
// ainda tem o SUBTÍTULO e não tem aba nenhuma. Medido por `diff` no 187-01: as
// duas diferem em 4 hunks, dois deles na A1.
//
// Linhas MEDIDAS POR SCRIPT neste arquivo (`sed -n '31,37p'` + a varredura de
// propriedades do `187-BASELINES.md` §5, rodada sobre a faixa `:31`-`:37`),
// 15/10/2026, sha256 `56e6c194b896151c`:
//   · `<header>`          → :31 (`border-bottom:1px solid #1F1F1F`)
//   · coluna do H1        → :32 (`gap:14px`, era `gap:6px`)
//   · H1 "Publicações"    → :33 (não muda)
//   · trilho das abas     → :34 (`display:flex;gap:20px;margin-bottom:-1px`)
//   · aba ATIVA `<span>`  → :35 (`white-space:nowrap;display:flex;align-items:center;`
//                                `gap:8px;height:40px;border-bottom:2px solid #FACC15;`
//                                `font-size:14px;font-weight:600`)
//                                + contador simples (`font-size:12px;color:#71717A`)
//   · aba INATIVA `<a>`   → :36 (`…;border-bottom:2px solid transparent;font-size:14px;`
//                                `font-weight:500;color:#8A8A90`)
//                                + pill (`min-width:22px;height:20px;padding:0 7px;`
//                                `border-radius:999px;background:#FACC15;color:#0A0A0A;`
//                                `display:grid;place-items:center;font-size:11.5px;`
//                                `font-weight:700`)
//
// ⚠️⚠️ `cursor` NÃO APARECE NENHUMA VEZ na seção A1 — medido pela varredura:
// `cursor:pointer ocorrencias 0`, `cursor (todas) ocorrencias 0`. A aba ativa é
// `<span>` e a inativa é `<a>`, que já tem o cursor por padrão do navegador.
// ⚠️ A tabela de fidelidade deste projeto erra por OMISSÃO **e por EXCESSO**:
// acrescentar `cursor-pointer` aqui seria "deixei igual aos outros botões", que
// é justificativa VETADA (FID-02). Há gate de AUSÊNCIA abaixo.
//
// ⚠️ REND-03: TODOS os bundles são montados AQUI NO TOPO, antes do primeiro
// `test()`. O `after()` do `node --test` dispara quando os testes registrados
// terminam e já matou um arquivo inteiro deste projeto SEM nenhum teste falhar.
//
// ⚠️ A pergunta obrigatória — *"o que precisaria estar errado no mundo para
// este gate falhar?"* — está respondida POR BLOCO. "Nada" é achado, não
// conforto.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

// ⚠️ O commit de PARTIDA deste plano, capturado na PRIMEIRA ação do executor.
// Ele é o começo do range do gate de território — e o range SOZINHO não basta:
// três sessões commitam nesta árvore e o `user.name` é o MESMO nas três. O
// discriminador é o ESCOPO DO ASSUNTO (`(187-03)`), nunca o autor. Range aberto
// com a isca "ninguém mais toca aqui" já acusou a sessão paralela seis vezes
// nesta milestone.
const PARTIDA = 'be122baf';
const ESCOPO = '(187-03)';

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});
global.window = {
    location: { search: '', href: 'http://localhost/mlb/anuncios' },
    addEventListener: () => {},
    removeEventListener: () => {},
    history: { state: null, replaceState: () => {} },
};

const STUB_INERTIA = path.join(__dirname, `.pub-abas187-inertia-stub-${process.pid}.mjs`);
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
        `.pub-abas187-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_PUB = 'resources/js/Components/Mlb/Publicador/publicacoes';
const REL_ABAS = `${DIR_PUB}/AbasDaPagina.jsx`;
const REL_CABECALHO = `${DIR_PUB}/CabecalhoDePublicacoes.jsx`;
const REL_CELULAS = `${DIR_PUB}/CelulasDaLinha.jsx`;
const REL_PAGINA = 'resources/js/Pages/Mlb/MeusAnuncios.jsx';
const PROTOTIPO = 'design_handoff_publicador/Publicacoes v2.dc.html';

// ⚠️ `test -e` ANTES de acreditar em qualquer leitura vazia. Seis caminhos
// fantasma já "provaram" território intocado nesta milestone.
for (const caminho of [DIR_PUB, REL_ABAS, REL_CABECALHO, REL_CELULAS, REL_PAGINA, PROTOTIPO]) {
    if (!fs.existsSync(path.resolve(RAIZ, caminho))) {
        throw new Error(`caminho inexistente: ${caminho} — gate mediria NADA`);
    }
}

const MOD_ABAS = await montar(REL_ABAS);
const MOD_CABECALHO = await montar(REL_CABECALHO);

const Abas = MOD_ABAS.default;
const Cabecalho = MOD_CABECALHO.default;

const FONTE_ABAS = lerSemComentarios(REL_ABAS);
const FONTE_PAGINA = lerSemComentarios(REL_PAGINA);
const FONTE_CELULAS = lerSemComentarios(REL_CELULAS);
const FONTE_CABECALHO = lerSemComentarios(REL_CABECALHO);

const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

// ⚠️ Os dois `href` do fixture NÃO levam aspas nem chaves: o
// `renderToStaticMarkup` escapa `"` como `&quot;` dentro de atributo, e a
// primeira versão deste gate comparava a string CRUA contra a ESCAPADA e
// reprovava o código certo. Estes são o formato real das URIs
// (`routes/mlb_anuncios.php:501`: `/meus/{company}/aprovacao`).
const HREF_FILA = '/mlb/anuncios/meus/9/aprovacao';
const HREF_LISTA = '/mlb/anuncios/meus/9';

const HTML_NA_LISTA = render(Abas, {
    ativa: 'publicadas',
    publicadas: 24,
    aguardando: 6,
    hrefAprovacao: HREF_FILA,
});

const HTML_NA_FILA = render(Abas, {
    ativa: 'aprovacao',
    publicadas: 24,
    aguardando: 6,
    hrefAprovacao: HREF_FILA,
    hrefLista: HREF_LISTA,
});

// ─────────────────────────────────────────────────────────────────────────
// Localização por ÁRVORE com índice absoluto / por TEXTO ÚNICO.
//
// ⚠️ NUNCA por lista de classes: `html.indexOf(tagDeAbertura)` pega o IRMÃO
// ERRADO quando dois elementos compartilham a mesma string de classes — e aqui
// as DUAS abas compartilham o grosso das classes de propósito.
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

/** A primeira tag `<nome` que começa DEPOIS da tag de abertura que está em `pos`. */
function primeiraTagApos(html, pos, nome) {
    const fim = html.indexOf('>', pos);
    assert.ok(fim !== -1, 'tag de referência sem fechamento');
    const achado = html.indexOf(`<${nome}`, fim);
    assert.ok(achado !== -1, `não achei <${nome}> depois do índice ${pos}`);
    return achado;
}

/** A tag de abertura do elemento que CONTÉM o texto `texto` (o mais próximo à esquerda). */
function tagDoElementoComTexto(html, texto, nome) {
    const iTexto = html.indexOf(texto);
    assert.ok(iTexto !== -1, `o texto "${texto}" não está no HTML`);
    return tagQueEnvolve(html, iTexto, nome);
}

/** As classes de uma tag de abertura, como LISTA. */
function classesDa(tag) {
    const m = tag.match(/class="([^"]*)"/);
    assert.ok(m, `tag sem class=: ${tag}`);
    return m[1].split(/\s+/).filter(Boolean);
}

/** Igualdade de CONJUNTO de classes — endurecimento: classe a mais reprova. */
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

/**
 * Descritor SEGURO para mensagem de asserção.
 *
 * ⚠️ Por que NUNCA `String(valor)` nem `JSON.stringify(valor)`:
 *  · `String({ toString: null })` LANÇA "Cannot convert object to primitive
 *    value" — e já matou 5 blocos de teste DENTRO da própria mensagem;
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

// As classes PRESCRITAS, uma vez só, lidas pelos gates de HTML e de CSS.
const CL_TRILHO = ['flex', 'gap-[20px]', 'mb-[-1px]'];
const CL_ABA_BASE = ['flex', 'h-[40px]', 'items-center', 'gap-[8px]', 'whitespace-nowrap', 'text-[14px]'];
const CL_ATIVA = ['border-b-[2px]', 'border-pub-accent', 'font-semibold'];
const CL_INATIVA = ['border-b-[2px]', 'border-transparent', 'font-medium', 'text-pub-txt-3'];
const CL_CONTADOR = ['text-[12px]', 'text-pub-txt-4'];
const CL_PILL = [
    'grid', 'h-[20px]', 'min-w-[22px]', 'place-items-center', 'rounded-full',
    'bg-pub-accent', 'px-[7px]', 'text-[11.5px]', 'font-bold', 'text-pub-page',
];

// ═══════════════════════════════════════════════════════════════════════════
// 1. OS LITERAIS, BYTE A BYTE
//
// O que precisaria estar errado para falhar: alguém parafrasear um dos dois
// rótulos ("Publicadas no ML", "Aguardando revisão", "Na fila") — que é a forma
// mais comum de o redesenho "adaptar ao sistema atual" sem ninguém notar.
// ═══════════════════════════════════════════════════════════════════════════

test('ABA-02 — os dois rótulos saem byte a byte, e vêm do protótipo de AUTORIDADE', async (contexto) => {
    await contexto.test('os literais exportados são exatamente os do protótipo :35/:36', () => {
        assert.equal(MOD_ABAS.PUBLICADAS, 'Publicadas no Mercado Livre');
        assert.equal(MOD_ABAS.AGUARDANDO, 'Aguardando aprovação');
    });

    await contexto.test('e eles SAEM no HTML renderizado, nos dois sentidos da prop `ativa`', () => {
        for (const [onde, html] of [['na lista', HTML_NA_LISTA], ['na fila', HTML_NA_FILA]]) {
            assert.ok(html.includes('Publicadas no Mercado Livre'), `${onde}: ${html}`);
            assert.ok(html.includes('Aguardando aprovação'), `${onde}: ${html}`);
        }
    });

    await contexto.test('⚠️ CONTROLE: os dois literais estão NO PROTÓTIPO DE AUTORIDADE, e o subtítulo NÃO', () => {
        // Sem este controle, os dois literais acima poderiam ser invenção
        // coerente — iguais no código e no teste, errados na tela.
        const proto = fs.readFileSync(path.resolve(RAIZ, PROTOTIPO), 'utf8');
        assert.ok(proto.includes('Publicadas no Mercado Livre'), 'o protótipo de autoridade mudou');
        assert.ok(proto.includes('Aguardando aprovação'), 'o protótipo de autoridade mudou');
        // E a prova de que é a cópia CERTA: a versão antiga tem o subtítulo, esta não.
        assert.ok(
            !proto.includes('Ofertas ativas e históricas desta empresa no Mercado Livre'),
            'este é o protótipo ANTIGO (new_layout_publicador) — a autoridade da A1 é design_handoff_publicador',
        );
    });

    await contexto.test('⚠️ ISCA do literal: uma paráfrase plausível NÃO está no HTML', () => {
        // ⚠️ A isca tem de ser plausível E ausente — `aprovad` não casaria
        // "aprovação" por UMA LETRA, e sonda cujo silêncio depende de uma letra
        // é sorte, não medida. Estas três foram conferidas como ausentes.
        for (const isca of ['Aguardando revisão', 'Publicadas no ML', 'Na fila']) {
            assert.ok(!HTML_NA_LISTA.includes(isca), `a paráfrase "${isca}" entrou na tela`);
            assert.ok(!HTML_NA_FILA.includes(isca), `a paráfrase "${isca}" entrou na tela`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. FID-01 — A GEOMETRIA, POR IGUALDADE DE CONJUNTO DE CLASSES
//
// O que precisaria estar errado para falhar: qualquer medida trocada por outra
// (40px→36px, 14px→`text-sm`, `gap:20px`→`gap:24px`, `mb-[-1px]` esquecido — e
// sem ele a borda amarela da aba ativa NÃO cobre a linha do header), qualquer
// classe A MAIS (igualdade de conjunto), ou o elemento mudar de tag.
// ═══════════════════════════════════════════════════════════════════════════

test('FID-01 — a geometria das abas bate com o protótipo de autoridade', async (contexto) => {
    await contexto.test('trilho :34 — flex, gap 20, margin-bottom -1', () => {
        assertClasses(tagEm(HTML_NA_LISTA, indiceDaTag(HTML_NA_LISTA, 'div')), CL_TRILHO, 'trilho :34');
    });

    await contexto.test('aba ATIVA :35 — <span>, base + borda amarela + peso 600', () => {
        const tag = tagDoElementoComTexto(HTML_NA_LISTA, 'Publicadas no Mercado Livre', 'span');
        assertClasses(tag, [...CL_ABA_BASE, ...CL_ATIVA], 'aba ATIVA :35');
    });

    await contexto.test('aba INATIVA :36 — <a>, base + borda transparente + peso 500 + cor neutra', () => {
        const tag = tagDoElementoComTexto(HTML_NA_LISTA, 'Aguardando aprovação', 'a');
        assertClasses(tag, [...CL_ABA_BASE, ...CL_INATIVA], 'aba INATIVA :36');
    });

    await contexto.test('contador simples :35 — 12px / txt-4, na aba das publicadas', () => {
        // Por ÁRVORE: a primeira <span> DEPOIS da tag da aba ativa é o contador dela.
        const iAba = HTML_NA_LISTA.indexOf('Publicadas no Mercado Livre');
        const iContador = HTML_NA_LISTA.indexOf('<span', iAba);
        assert.ok(iContador !== -1, 'a aba das publicadas não tem contador');
        assertClasses(tagEm(HTML_NA_LISTA, iContador), CL_CONTADOR, 'contador simples :35');
    });

    await contexto.test('pill :36 — 20px de altura, min-w 22, redonda, amarela, texto da página', () => {
        const iAba = HTML_NA_LISTA.indexOf('Aguardando aprovação');
        const iPill = HTML_NA_LISTA.indexOf('<span', iAba);
        assert.ok(iPill !== -1, 'a aba da fila não tem pill');
        assertClasses(tagEm(HTML_NA_LISTA, iPill), CL_PILL, 'pill :36');
    });

    await contexto.test('⚠️ AUSÊNCIA MEDIDA: `cursor-pointer` NÃO entra em nenhuma das duas abas', () => {
        // ⚠️ Medido por script na faixa `:31`-`:37` do protótipo de autoridade:
        // `cursor` aparece ZERO vezes. Em `Aprovacao.dc.html` o `cursor:pointer`
        // aparece 16 vezes e foi omitido ONZE vezes numa fase deste projeto —
        // por isso ele tem gate PRÓPRIO, nos dois sentidos. Aqui o sentido é o
        // EXCESSO: acrescentá-lo seria "deixei igual aos outros botões" (FID-02).
        assert.doesNotMatch(FONTE_ABAS, /cursor-pointer/, 'o protótipo da A1 não declara cursor em aba nenhuma');
        assert.ok(!HTML_NA_LISTA.includes('cursor-pointer'), HTML_NA_LISTA);
        // E o CONTROLE que prova que a sonda não está cega: o cabeçalho VIZINHO
        // tem `cursor-pointer` de propósito (`:39` do protótipo, botão Atualizar).
        assert.match(FONTE_CABECALHO, /cursor-pointer/, 'a sonda está cega: nem o controle positivo ela acha');
    });

    await contexto.test('FID-03/FID-04 — nenhuma escala nomeada, nenhum token da camada antiga, nenhum hex', () => {
        assert.doesNotMatch(FONTE_ABAS, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/, 'FID-03: text-sm é 14px, não é passo do protótipo');
        assert.doesNotMatch(FONTE_ABAS, /\becf-(bg|card|card-2|yellow|yellow-2|line|dim|mute)\b/, 'FID-04');
        assert.doesNotMatch(FONTE_ABAS, /\bfont-display\b/, 'FID-04');
        assert.doesNotMatch(FONTE_ABAS, /border-white\/\[0\.08\]/, 'FID-04');
        assert.doesNotMatch(FONTE_ABAS, /#[0-9a-fA-F]{3,8}\b/, 'TOK-15: a cor vem por token, nunca por hex');
    });

    await contexto.test('⚠️ as classes moram NESTE .jsx (o content do Tailwind não varre .js)', () => {
        // `tailwind.config.js` varre `./resources/js/**/*.jsx` e NÃO `*.js`:
        // classe escrita num `.js` não gera CSS e NÃO EXISTE PARA A TELA, com
        // todo teste de render passando.
        assert.ok(REL_ABAS.endsWith('.jsx'), 'o componente tem de ser .jsx para o Tailwind varrer as classes');
        for (const classe of [...CL_TRILHO, ...CL_ABA_BASE, ...CL_ATIVA, ...CL_INATIVA, ...CL_CONTADOR, ...CL_PILL]) {
            assert.ok(FONTE_ABAS.includes(classe), `a classe ${classe} não está na FONTE do .jsx`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. A TROCA DE ESTADO POR PROP — e a aba ativa que NÃO navega para si mesma
//
// O que precisaria estar errado para falhar: alguém cravar qual aba é a ativa
// (o componente é usado por DUAS páginas), ou dar `href` à aba ativa — link que
// recarrega a própria página é destino falso (D4).
// ═══════════════════════════════════════════════════════════════════════════

test('ABA-02 — `ativa` troca os dois estados, e a ativa é <span> sem href', async (contexto) => {
    await contexto.test('na LISTA: "Publicadas" é <span> sem href e "Aguardando" é <a> com href', () => {
        const ativa = tagDoElementoComTexto(HTML_NA_LISTA, 'Publicadas no Mercado Livre', 'span');
        assert.ok(ativa.startsWith('<span'), `a aba ativa tem de ser <span>, veio ${ativa}`);
        assert.equal(atributo(ativa, 'href'), null, 'a aba ativa navega para si mesma (D4)');
        assert.equal(atributo(ativa, 'aria-current'), 'page', 'a aba ativa não se anuncia como a página atual');

        const inativa = tagDoElementoComTexto(HTML_NA_LISTA, 'Aguardando aprovação', 'a');
        assert.ok(inativa.startsWith('<a'), `a aba inativa tem de ser <a>, veio ${inativa}`);
        assert.equal(atributo(inativa, 'href'), HREF_FILA);
    });

    await contexto.test('na FILA: os dois estados TROCAM e a aba da fila deixa de ter href', () => {
        const ativa = tagDoElementoComTexto(HTML_NA_FILA, 'Aguardando aprovação', 'span');
        assert.ok(ativa.startsWith('<span'), `a aba da fila tem de ser <span> quando ativa, veio ${ativa}`);
        assert.equal(atributo(ativa, 'href'), null, 'a aba ativa navega para si mesma (D4)');
        assertClasses(ativa, [...CL_ABA_BASE, ...CL_ATIVA], 'aba da fila ATIVA');

        const inativa = tagDoElementoComTexto(HTML_NA_FILA, 'Publicadas no Mercado Livre', 'a');
        assert.equal(atributo(inativa, 'href'), HREF_LISTA);
        assertClasses(inativa, [...CL_ABA_BASE, ...CL_INATIVA], 'aba da lista INATIVA');
    });

    await contexto.test('⚠️ o `href` NUNCA é `#` nem vazio — e a pill continua na aba da fila nos dois estados', () => {
        for (const [onde, html] of [['na lista', HTML_NA_LISTA], ['na fila', HTML_NA_FILA]]) {
            assert.ok(!html.includes('href="#"'), `${onde}: a aba aponta para o VAZIO (href="#")`);
            assert.ok(!html.includes('href=""'), `${onde}: href vazio`);
        }
        // A pill é da aba "Aguardando aprovação", não do ESTADO: ela é o sinal
        // de atenção daquela fila e o protótipo a pinta só lá.
        const iAba = HTML_NA_FILA.indexOf('Aguardando aprovação');
        assertClasses(tagEm(HTML_NA_FILA, HTML_NA_FILA.indexOf('<span', iAba)), CL_PILL, 'pill na fila');
    });

    await contexto.test('a fonte não CRAVA qual aba é a ativa — e não tem `#` como destino', () => {
        assert.doesNotMatch(FONTE_ABAS, /href="#"/, 'destino `#` é a definição de enfeite (D4)');
        assert.doesNotMatch(FONTE_ABAS, /href=\{'#'\}/, 'destino `#` é a definição de enfeite (D4)');
        // E o nome da rota NÃO mora aqui: quem sabe a rota é a página.
        assert.doesNotMatch(FONTE_ABAS, /meus\.aprovacao/, 'o componente monta rota — isso é da página');
        assert.doesNotMatch(FONTE_ABAS, /\broute\(/, 'o componente chama route() — isso é da página');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. O CONTADOR — pill só com número MEDIDO, e o zero que É um fato
//
// O que precisaria estar errado para falhar: alguém desenhar "0" quando o
// servidor não mandou nada (EXMP-01: número inventado), ou esconder a pill
// quando o servidor mandou 0 (a fila vazia é um fato, e esconder a aba foi
// recusado pelo usuário).
// ═══════════════════════════════════════════════════════════════════════════

test('ABA-05 — a pill só existe com número medido; `0` desenha, ausente não', async (contexto) => {
    await contexto.test('contador AUSENTE não desenha pill nenhuma, e nenhum "0" aparece', () => {
        const html = render(Abas, { ativa: 'publicadas', hrefAprovacao: HREF_FILA });
        assert.ok(html.includes('Aguardando aprovação'), 'a aba sumiu — esconder a aba foi RECUSADO pelo usuário');
        for (const classe of ['min-w-[22px]', 'bg-pub-accent', 'text-[12px]']) {
            assert.ok(!html.includes(classe), `sem número do servidor desenhou ${classe}: contador inventado`);
        }
        assert.doesNotMatch(html, />0</, 'desenhou "0" sem o servidor ter medido nada');
        assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, html);
    });

    await contexto.test('contador `0` DESENHA a pill com "0" — zero medido é um fato', () => {
        const html = render(Abas, { ativa: 'publicadas', publicadas: 0, aguardando: 0, hrefAprovacao: HREF_FILA });
        const iAba = html.indexOf('Aguardando aprovação');
        const pill = tagEm(html, html.indexOf('<span', iAba));
        assertClasses(pill, CL_PILL, 'pill com zero');
        assert.ok(html.includes('>0</span>'), html);
        // E a aba continua navegando: fila vazia não desabilita destino.
        assert.ok(html.includes(`href="${HREF_FILA}"`), 'a fila vazia tirou o destino da aba');
    });

    await contexto.test('⚠️ ISCA DO ZERO: a sonda `>0<` acha o zero quando ele existe', () => {
        // Sem esta isca, o `doesNotMatch(/>0</)` do caso acima poderia estar
        // cego (e passaria mesmo com um "0" inventado desenhado de outro jeito).
        const html = render(Abas, { ativa: 'publicadas', aguardando: 0, hrefAprovacao: HREF_FILA });
        assert.match(html, />0</, 'a sonda do zero está cega');
    });

    await contexto.test('o contador da aba ATIVA também é número do servidor — nenhum 24 cravado', () => {
        const html = render(Abas, { ativa: 'publicadas', publicadas: 7, aguardando: 3, hrefAprovacao: HREF_FILA });
        assert.ok(html.includes('>7</span>'), html);
        assert.ok(html.includes('>3</span>'), html);
        // ⚠️ O `24` e o `6` do protótipo são dado MOCK. Número fixo no JSX é
        // proibido (critério 2 / EXMP-01).
        assert.doesNotMatch(FONTE_ABAS, />\s*24\s*</, 'o 24 do protótipo entrou no JSX como literal');
        assert.doesNotMatch(FONTE_ABAS, /=\s*24\b/, 'o 24 do protótipo entrou no JSX como literal');
        assert.doesNotMatch(FONTE_ABAS, /=\s*6\b/, 'o 6 do protótipo entrou no JSX como literal');
        // ⚠️ ISCA DA SONDA: as mesmas regexes CASAM na forma que elas proíbem.
        assert.match('<span>24</span>', />\s*24\s*</, 'a sonda do literal 24 está cega');
        assert.match('const n = 24;', /=\s*24\b/, 'a sonda do literal 24 está cega');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. RENDER ADVERSO — a TELA PRETA de 07/10/2026 voltou QUATRO vezes na Fase 180
//
// O que precisaria estar errado para falhar: alguém chamar `String(valor)` num
// campo que chega `{toString:null}` (LANÇA), usar `obj[chave]` com a chave vinda
// do servidor (LANÇA), ou passar o contador direto para o JSX sem guarda de tipo
// (`[object Object]` na tela, ou um `NaN` com cara de número).
// ═══════════════════════════════════════════════════════════════════════════

const VALORES_ADVERSOS = [
    ['objeto com toString nulo', { toString: null }],
    ['objeto comum', { total: 3 }],
    ['nulo', null],
    ['ausente', undefined],
    ['string', '6'],
    ['string vazia', ''],
    ['NaN', Number.NaN],
    ['Infinity', Number.POSITIVE_INFINITY],
    ['array', [1, 2, 3]],
    ['booleano', true],
    ['fracionado', 2.5],
    ['negativo', -1],
];

test('REND-01/02/03 — nenhum campo adverso derruba a página nem vaza lixo', async (contexto) => {
    for (const [rotulo, valor] of VALORES_ADVERSOS) {
        await contexto.test(`aguardando = ${rotulo} (${descreve(valor)})`, () => {
            const html = render(Abas, { ativa: 'publicadas', publicadas: 24, aguardando: valor, hrefAprovacao: HREF_FILA });
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, html);
            assert.ok(html.includes('Aguardando aprovação'), 'a aba sumiu com valor adverso');
        });

        await contexto.test(`publicadas = ${rotulo} (${descreve(valor)})`, () => {
            const html = render(Abas, { ativa: 'publicadas', publicadas: valor, aguardando: 6, hrefAprovacao: HREF_FILA });
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, html);
            assert.ok(html.includes('Publicadas no Mercado Livre'), 'a aba sumiu com valor adverso');
        });

        await contexto.test(`ativa = ${rotulo} (${descreve(valor)})`, () => {
            // ⚠️ A chave vem de fora. `Object.prototype.hasOwnProperty.call(obj, chave)`
            // e QUALQUER `obj[chave]` LANÇAM quando a chave é `{toString:null}` —
            // guarda de tipo ANTES, sempre.
            const html = render(Abas, { ativa: valor, publicadas: 24, aguardando: 6, hrefAprovacao: HREF_FILA, hrefLista: HREF_LISTA });
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, html);
            assert.ok(html.includes('Publicadas no Mercado Livre'), 'a aba sumiu com `ativa` adversa');
            assert.ok(html.includes('Aguardando aprovação'), 'a aba sumiu com `ativa` adversa');
        });

        await contexto.test(`hrefAprovacao = ${rotulo} (${descreve(valor)})`, () => {
            const html = render(Abas, { ativa: 'publicadas', publicadas: 24, aguardando: 6, hrefAprovacao: valor });
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, html);
            // ⚠️ Sem destino conhecido a aba NÃO ganha `#`: ela deixa de ser
            // link. `#` seria destino FALSO, e é a mutação que este plano usa
            // como prova (ela tem de REPROVAR).
            assert.ok(!html.includes('href="#"'), 'href adverso virou `#` — destino falso (D4)');
            assert.ok(html.includes('Aguardando aprovação'), 'a aba sumiu com href adverso');
        });
    }

    await contexto.test('o componente inteiro sem prop nenhuma continua renderizando', () => {
        const html = render(Abas, {});
        assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, html);
        assert.ok(html.includes('Publicadas no Mercado Livre'));
        assert.ok(html.includes('Aguardando aprovação'));
    });

    await contexto.test('REND-03 — nem String() nem JSON.stringify sobre prop na FONTE', () => {
        assert.doesNotMatch(FONTE_ABAS, /String\(/, 'String({toString:null}) LANÇA e derruba a página');
        assert.doesNotMatch(FONTE_ABAS, /JSON\.stringify\(/, 'JSON.stringify MENTE: NaN vira null');
        assert.doesNotMatch(FONTE_ABAS, /hasOwnProperty/, 'hasOwnProperty.call LANÇA com chave {toString:null}');
        // ⚠️ ISCA DAS SONDAS: as três regexes casam na forma que elas proíbem.
        assert.match('const t = String(x);', /String\(/, 'a sonda de String() está cega');
        assert.match('JSON.stringify(x)', /JSON\.stringify\(/, 'a sonda de stringify está cega');
        assert.match('o.hasOwnProperty(k)', /hasOwnProperty/, 'a sonda de hasOwnProperty está cega');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. O CSS CONSTRUÍDO — a classe EXISTE com o valor certo, não só "está escrita"
//
// O que precisaria estar errado para falhar: a classe sair do `content` do
// Tailwind (num `.js`, num mapa, numa string montada), ou o valor arbitrário
// não compilar (`duration-[Nms]` é o precedente medido). As SETE normalizações
// do medidor estão aplicadas: seletor ESCAPADO, TODOS os metacaracteres
// escapados, token sólido em `rgb()` DECIMAL, zero comido depois do menos.
// ═══════════════════════════════════════════════════════════════════════════

/** Escapa TODOS os metacaracteres de regex. */
function escapaRegex(texto) {
    return texto.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** O seletor que o Tailwind emite: `.` + o nome com os metacaracteres de CSS escapados. */
function seletorDe(classe) {
    return `.${classe.replace(/[[\].:/%]/g, (ch) => `\\${ch}`)}`;
}

function carregarCssConstruido() {
    const manifestRel = 'public/build/manifest.json';
    const manifestAbs = path.resolve(RAIZ, manifestRel);
    if (!fs.existsSync(manifestAbs)) return { motivo: `${manifestRel} ausente — rode \`npm run build\`` };

    // ⚠️ Manifest SEMPRE pelo JSON, nunca por grep: o hash sai com HÍFEN
    // (`assets/app-Dv6-mbDf.js`, medido no 187-01) e o grep ingênuo não casa.
    // ⚠️ E `resources/css/app.css` NÃO é chave do manifest: o CSS entra por
    // `manifest['resources/js/app.jsx'].css[0]`.
    const manifest = JSON.parse(fs.readFileSync(manifestAbs, 'utf8'));
    const entrada = manifest['resources/js/app.jsx'];
    if (!entrada || !Array.isArray(entrada.css) || entrada.css.length === 0) {
        return { motivo: 'o manifest não aponta CSS para resources/js/app.jsx' };
    }

    const cssAbs = path.resolve(RAIZ, 'public/build', entrada.css[0]);
    if (!fs.existsSync(cssAbs)) return { motivo: `${entrada.css[0]} ausente — rode \`npm run build\`` };

    // ⚠️ O CSS tem de ser mais NOVO que as fontes, senão este bloco mediria um
    // build velho e reprovaria o código certo (ou aprovaria o errado).
    const mtimeCss = fs.statSync(cssAbs).mtimeMs;
    const mtimeFontes = Math.max(
        fs.statSync(path.resolve(RAIZ, REL_ABAS)).mtimeMs,
        fs.statSync(path.resolve(RAIZ, REL_CABECALHO)).mtimeMs,
        fs.statSync(path.resolve(RAIZ, REL_PAGINA)).mtimeMs,
    );
    if (mtimeCss < mtimeFontes) {
        return { motivo: `o CSS construído (${entrada.css[0]}) é mais antigo que as fontes — rode \`npm run build\`` };
    }

    return { arquivo: entrada.css[0], css: fs.readFileSync(cssAbs, 'utf8'), manifest };
}

const CSS = carregarCssConstruido();

test('FID — as classes das abas GERAM CSS com o valor certo', async (contexto) => {
    if (CSS.motivo) {
        // ⚠️ `skip` com razão ESCRITA (e `skipped N` aparece no sumário), nunca
        // `return` calado.
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

    await contexto.test('⚠️ ISCA: a sonda reporta AUSENTE o que não existe, e ACHA o que existe', () => {
        for (const isca of ['bg-pub-isca-187-03', 'text-[99.5px]', 'gap-[997px]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS.css.includes(isca), `a string da isca ${isca} aparece no CSS`);
        }
        assert.notEqual(regraDe('text-[14px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('o trilho — gap 20 e o margin-bottom NEGATIVO que cobre a linha do header', () => {
        assert.match(regraDe('gap-[20px]'), /gap:\s*20px/);
        const mb = regraDe('mb-[-1px]');
        assert.notEqual(mb, null, '`mb-[-1px]` não gerou CSS — sem ele a borda da aba ativa não cobre a linha');
        assert.match(mb, /margin-bottom:\s*-1px/);
    });

    await contexto.test('a aba — 40px de altura, 14px de fonte, borda de 2px', () => {
        assert.match(regraDe('h-[40px]'), /height:\s*40px/);
        assert.match(regraDe('text-[14px]'), /font-size:\s*14px/);
        assert.match(regraDe('gap-[8px]'), /gap:\s*8px/);
        const borda = regraDe('border-b-[2px]');
        assert.notEqual(borda, null, '`border-b-[2px]` não gerou CSS');
        assert.match(borda, /border-bottom-width:\s*2px/);
    });

    await contexto.test('(i) a borda amarela — token sólido sai em rgb() DECIMAL (#FACC15)', () => {
        const regra = regraDe('border-pub-accent');
        assert.notEqual(regra, null, 'a borda da aba ativa não gerou CSS');
        // 0xFA=250, 0xCC=204, 0x15=21 — token sólido NÃO compila para hex.
        assert.match(regra, /rgb\(\s*250\s+204\s+21\b/, regra);
        // CONTROLE DE VALOR: não é o amarelo de hover (#EAB308 → 234 179 8).
        assert.ok(!/rgb\(\s*234\s+179\s+8\b/.test(regra), 'a borda está com o amarelo de hover, não o #FACC15');
    });

    await contexto.test('a pill — min-width 22, altura 20, raio 999, fundo amarelo, texto da página', () => {
        assert.match(regraDe('min-w-[22px]'), /min-width:\s*22px/);
        assert.match(regraDe('h-[20px]'), /height:\s*20px/);
        assert.match(regraDe('px-[7px]'), /padding-left:\s*7px/);
        assert.match(regraDe('rounded-full'), /border-radius:\s*9999px/);
        assert.match(regraDe('text-[11.5px]'), /font-size:\s*11\.5px/);
        assert.match(regraDe('place-items-center'), /place-items:\s*center/);

        const fundo = regraDe('bg-pub-accent');
        assert.notEqual(fundo, null, 'o fundo da pill não gerou CSS');
        assert.match(fundo, /rgb\(\s*250\s+204\s+21\b/, fundo);

        const texto = regraDe('text-pub-page');
        assert.notEqual(texto, null, 'a cor do texto da pill não gerou CSS');
        // #0A0A0A → 10 10 10
        assert.match(texto, /rgb\(\s*10\s+10\s+10\b/, texto);
    });

    await contexto.test('as duas cores de texto das abas — txt-3 (#8A8A90) e txt-4 (#71717A)', () => {
        const t3 = regraDe('text-pub-txt-3');
        const t4 = regraDe('text-pub-txt-4');
        assert.notEqual(t3, null, 'a cor da aba inativa não gerou CSS');
        assert.notEqual(t4, null, 'a cor do contador simples não gerou CSS');
        // #8A8A90 → 138 138 144 ; #71717A → 113 113 122
        assert.match(t3, /rgb\(\s*138\s+138\s+144\b/, t3);
        assert.match(t4, /rgb\(\s*113\s+113\s+122\b/, t4);
        // ⚠️ E eles são DIFERENTES: a aba inativa e o contador simples têm cores
        // distintas no protótipo, e harmonizá-las é a adaptação que FID-02 veta.
        assert.notEqual(t3, t4, 'as duas cores viraram a mesma — o protótipo as pinta diferentes');
    });

    await contexto.test('a borda do <header> — border-pub-line-subtle (#1F1F1F)', () => {
        const regra = regraDe('border-pub-line-subtle');
        assert.notEqual(regra, null, 'a borda do cabeçalho não gerou CSS');
        // #1F1F1F → 31 31 31
        assert.match(regra, /rgb\(\s*31\s+31\s+31\b/, regra);
    });

    await contexto.test('a coluna do H1 — gap-[14px] gera CSS (e NÃO é o gap-[6px] de antes)', () => {
        assert.match(regraDe('gap-[14px]'), /gap:\s*14px/);
    });

    await contexto.test('⚠️ a PÁGINA continua no manifest do Vite — página fora dele SOME sem erro', () => {
        // ⚠️ Lido pelo JSON e com `Object.prototype.hasOwnProperty.call`, nunca
        // por grep. Uma página React fora do manifest desaparece da aplicação
        // sem levantar erro nenhum.
        assert.ok(
            Object.prototype.hasOwnProperty.call(CSS.manifest, REL_PAGINA),
            `${REL_PAGINA} saiu do manifest do Vite — a tela de Publicações SOME`,
        );
        // ISCA: uma entrada inexistente sai ausente, provando que a sonda não
        // responde "sim" para tudo.
        assert.ok(!Object.prototype.hasOwnProperty.call(CSS.manifest, 'resources/js/Pages/Mlb/NaoExiste9z7.jsx'));
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. A MONTAGEM NA PÁGINA — o contador vem do SERVIDOR e NADA é somado aqui
//
// O que precisaria estar errado para falhar: alguém somar o contador no cliente
// (o que exigiria mandar a fila inteira para uma tela que não a mostra, e faria
// o número depender do que a página carregou), cravar o `24` do protótipo, ou
// apontar a aba para outro lugar que não a rota do 187-02.
//
// ⚠️ ESCOPO DECLARADO: aqui a prova é sobre a FONTE da página, não sobre o
// render dela. Renderizar `MeusAnuncios.jsx` exigiria as 19 props do servidor e
// o banco local não tem empresa com `ml_token` (a rota devolve 404 nesta
// máquina). O comportamento adverso do contador está provado no COMPONENTE,
// que é quem decide desenhar a pill — seção 5.
// ═══════════════════════════════════════════════════════════════════════════

test('ABA-05 — a página monta as abas com a rota do 187-02 e o contador do servidor', async (contexto) => {
    await contexto.test('a página IMPORTA o componente das abas', () => {
        assert.match(
            FONTE_PAGINA,
            /import AbasDaPagina from '@\/Components\/Mlb\/Publicador\/publicacoes\/AbasDaPagina'/,
            'a página não importa AbasDaPagina',
        );
    });

    await contexto.test('o slot `abas` do cabeçalho recebe <AbasDaPagina ativa="publicadas" …>', () => {
        assert.match(FONTE_PAGINA, /<AbasDaPagina\b/, 'a página não monta AbasDaPagina');
        assert.match(FONTE_PAGINA, /abas=\{\s*<AbasDaPagina/, 'as abas não entram pelo slot `abas` do cabeçalho');
        assert.match(FONTE_PAGINA, /ativa="publicadas"/, 'esta página é a das PUBLICADAS — a aba ativa é ela');
        assert.doesNotMatch(FONTE_PAGINA, /ativa="aprovacao"/, 'a página da lista marcou a aba da FILA como ativa');
    });

    await contexto.test('⚠️ o href é a rota `mlb.anuncios.meus.aprovacao`, e NUNCA `#`', () => {
        assert.match(
            FONTE_PAGINA,
            /hrefAprovacao=\{route\('mlb\.anuncios\.meus\.aprovacao'/,
            'o href da aba não é a rota que o 187-02 registrou',
        );
        assert.doesNotMatch(FONTE_PAGINA, /hrefAprovacao=\{?['"]#['"]\}?/, 'a aba aponta para o VAZIO');
        assert.doesNotMatch(FONTE_PAGINA, /hrefAprovacao="#"/, 'a aba aponta para o VAZIO');
        // ⚠️ ISCA DA SONDA: as duas formas que ela proíbe, CASANDO.
        assert.match('hrefAprovacao={\'#\'}', /hrefAprovacao=\{?['"]#['"]\}?/, 'a sonda do `#` está cega');
        assert.match('hrefAprovacao="#"', /hrefAprovacao="#"/, 'a sonda do `#` está cega');
        // E a rota existe DE VERDADE no arquivo de rotas (não é nome inventado).
        const rotas = fs.readFileSync(path.resolve(RAIZ, 'routes/mlb_anuncios.php'), 'utf8');
        assert.match(rotas, /->name\('meus\.aprovacao'\)/, 'a rota `meus.aprovacao` não está registrada');
    });

    await contexto.test('⚠️ os DOIS contadores vêm do servidor — nenhum literal, nenhuma soma no cliente', () => {
        assert.match(FONTE_PAGINA, /aguardando=\{aguardando_aprovacao\}/, 'o contador da pill não é a prop do servidor');
        assert.match(FONTE_PAGINA, /publicadas=\{[^}]*subTotais/, 'o contador da aba ativa não vem de `subTotais`');

        // ⚠️ A prop TEM de estar declarada na assinatura: consumi-la sem
        // declarar a deixaria `undefined` para sempre, com a pill nunca
        // desenhada e nenhum teste de render reclamando.
        assert.match(FONTE_PAGINA, /aguardando_aprovacao\s*=\s*null/, 'a prop não está declarada na assinatura');

        // ⚠️ ZERO soma no cliente. O valor passado é a prop NUA: nada de `+`,
        // `reduce`, `filter(...).length` ou `Object.values` dentro do `aguardando=`.
        const m = FONTE_PAGINA.match(/aguardando=\{([^}]*)\}/);
        assert.ok(m, 'não achei o valor passado em `aguardando=`');
        assert.equal(m[1].trim(), 'aguardando_aprovacao', `o contador é calculado na página: ${m[1]}`);
        for (const proibido of ['+', 'reduce', 'filter', 'length', 'Object.values', 'Object.keys']) {
            assert.ok(!m[1].includes(proibido), `o contador da pill usa \`${proibido}\` — ABA-05 exige o número do SERVIDOR`);
        }

        // ⚠️ E a página não conhece `andamento`: os estados internos da fila
        // (`awaiting`/`ecf_review`/`changes`) vivem no servidor. Medido: 0
        // ocorrências na fonte da lista.
        assert.equal(
            (FONTE_PAGINA.match(/andamento/g) ?? []).length,
            0,
            'a página passou a falar de `andamento` — somar os estados no cliente é o que ABA-05 proíbe',
        );
        // ⚠️ ISCA: a mesma sonda ACHA a palavra quando ela existe.
        assert.equal(('const a = andamento;'.match(/andamento/g) ?? []).length, 1, 'a sonda de `andamento` está cega');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. A LISTA INTOCADA — D-11, D-22 e o corpo da tela de Publicações
//
// O que precisaria estar errado para falhar: a aba virar porta de ESCRITA
// (`router.visit` aceita `method:'post'`), a nota voltar a ser renormalizada
// para 100, ou o corpo da lista (busca, filtros, agrupamento, painel, lote,
// atalhos, header) mudar junto com o cabeçalho.
//
// ⚠️ FRONTEIRA DECLARADA: o D-11 vale para a LISTA, que nunca escreve no
// Mercado Livre. A FILA escreve — e esta fase APROVA, que ENFILEIRA; a escrita
// no ML é da Fase 193. Nada neste plano escreve: a aba NAVEGA, por `<Link>`,
// que renderiza `<a href>` e não põe nenhum dos literais proibidos na fonte.
// ═══════════════════════════════════════════════════════════════════════════

test('ABA-06 / critério 4 — D-11 e D-22 continuam intactos na tela que está NO AR', async (contexto) => {
    await contexto.test('D-11: nenhum verbo de escrita, e EXATAMENTE um router.post (meus.atualizar)', () => {
        assert.doesNotMatch(FONTE_PAGINA, /router\.put\(/, 'D-11: a lista nunca escreve no ML');
        assert.doesNotMatch(FONTE_PAGINA, /router\.delete\(/, 'D-11');
        assert.doesNotMatch(FONTE_PAGINA, /router\.patch\(/, 'D-11');
        assert.doesNotMatch(FONTE_PAGINA, /\baxios\b/, 'escrita por axios furaria o gate de router.*');
        assert.doesNotMatch(FONTE_PAGINA, /\bfetch\(/, 'escrita por fetch furaria o gate de router.*');
        assert.doesNotMatch(FONTE_PAGINA, /<form\b/, 'a tela não tem formulário');
        assert.doesNotMatch(FONTE_PAGINA, /router\.visit\(/, 'router.visit aceita method:"post" — porta lateral do D-11');

        const posts = FONTE_PAGINA.match(/router\.post\(/g) ?? [];
        assert.equal(posts.length, 1, 'só pode haver UM router.post nesta tela — o "Atualizar agora"');
        assert.match(FONTE_PAGINA, /router\.post\(\s*route\('mlb\.anuncios\.meus\.atualizar'/, 'o único post mudou de destino');
    });

    await contexto.test('D-22: a nota continua "de 86" no dono dela, e "de 100" não existe', () => {
        assert.match(FONTE_CELULAS, /de 86/, 'o literal "de 86" saiu do dono (CelulasDaLinha.jsx)');
        assert.doesNotMatch(FONTE_CELULAS, /de 100/, 'a nota nunca aparece "de 100" (D-22)');
        assert.doesNotMatch(FONTE_PAGINA, /de 100/, 'a nota nunca aparece "de 100" (D-22)');
    });

    await contexto.test('o corpo da lista continua montado — os 9 componentes da Fase 180', () => {
        // ⚠️ Esta é uma lista de NOMES, e lista de nomes não é prova de
        // comportamento — por isso ela anda JUNTO do gate de território por
        // range (seção 9), que é quem prova que os arquivos deles não mudaram.
        // Aqui a pergunta é outra: a cirurgia do cabeçalho não DESMONTOU nada.
        for (const componente of [
            'BuscaDePublicacoes', 'AbasDeStatus', 'LinhaDeControles', 'ChipsDeFiltro',
            'ChipsDeTriagem', 'CabecalhoDaTabela', 'LinhaDePublicacao',
            'LinhaDeGrupoDeProduto', 'RodapeDaTabela', 'VaziosDaLista',
            'RascunhosPainel', 'ModalDetalheAnuncio', 'PublicadorHeader',
        ]) {
            assert.match(FONTE_PAGINA, new RegExp(`<${componente}\\b`), `${componente} saiu da tela`);
        }
        // E os atalhos de teclado continuam ligados (LIST-18).
        assert.match(FONTE_PAGINA, /interpretarTecla\b/, 'os atalhos da lista saíram');
        assert.match(FONTE_PAGINA, /idsNaOrdemVisual\b/, 'a ordem visual dos atalhos saiu');
    });

    await contexto.test('o z-index do header continua 30 e nada deste plano cria camada nova', () => {
        // Ordem travada do projeto: filtros 10 < header 30 < menu lateral e painel 40.
        // ⚠️ O protótipo usa 45/50 e os números dele NÃO são copiados: o
        // remapeamento preserva a ORDEM relativa (D1).
        assert.doesNotMatch(FONTE_ABAS, /\bz-\[?\d/, 'as abas criaram camada própria — a A1 não tem z-index no protótipo');
        assert.doesNotMatch(FONTE_ABAS, /\bz-(0|10|20|30|40|50)\b/, 'as abas criaram camada própria');
    });

    await contexto.test('⚠️ NADA de localStorage, e nenhuma `<form>`/`action` no componente novo', () => {
        assert.doesNotMatch(FONTE_ABAS, /localStorage/, 'estado de tela em localStorage é proibido nesta milestone');
        assert.doesNotMatch(FONTE_ABAS, /sessionStorage/, 'estado de tela em sessionStorage é proibido nesta milestone');
        assert.doesNotMatch(FONTE_ABAS, /<form\b/, 'a aba NAVEGA, não escreve');
        assert.doesNotMatch(FONTE_ABAS, /router\./, 'a aba navega por <Link>, nunca por router.* (D-11)');
        assert.match(FONTE_ABAS, /from '@inertiajs\/react'/, 'a aba tem de navegar pelo <Link> do Inertia');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 9. TERRITÓRIO — por range FILTRADO PELO ESCOPO DO ASSUNTO
//
// ⚠️⚠️ O range SOZINHO acusa as SESSÕES PARALELAS. Três sessões commitam nesta
// árvore com o MESMO `user.name` — o autor NÃO discrimina. O discriminador é o
// ESCOPO do assunto (`(187-03)`), e um gate mais largo que o invariante já
// barrou um deploy nesta milestone.
//
// O que precisaria estar errado para falhar: um commit DESTE plano tocar um
// arquivo que não é dele — o corpo da lista, o vocabulário da 186, o Creative
// Engine, o editor, o `AppLayout`, um arquivo de dependência, ou PHP nenhum
// (este plano é só render).
// ═══════════════════════════════════════════════════════════════════════════

/** Os commits DESTE plano: range desde a partida, filtrado pelo escopo do assunto. */
function commitsDoPlano() {
    const saida = execFileSync('git', ['log', '--format=%H%x09%s', `${PARTIDA}..HEAD`], {
        cwd: RAIZ,
        encoding: 'utf8',
    });
    return saida
        .split('\n')
        .map((linha) => linha.trim())
        .filter((linha) => linha !== '')
        .map((linha) => {
            const [sha, ...resto] = linha.split('\t');
            return { sha, assunto: resto.join('\t') };
        })
        .filter((c) => c.assunto.includes(ESCOPO));
}

/** Os arquivos tocados pelos commits do plano, dentro de `pathspec`. */
function tocados(commits, pathspec) {
    const achados = new Set();
    for (const c of commits) {
        const saida = execFileSync(
            'git',
            ['show', '--numstat', '--format=', c.sha, '--', ...pathspec],
            { cwd: RAIZ, encoding: 'utf8' },
        );
        for (const linha of saida.split('\n')) {
            const partes = linha.trim().split('\t');
            if (partes.length === 3 && partes[2] !== '') achados.add(partes[2]);
        }
    }
    return [...achados].sort();
}

const COMMITS_DO_PLANO = commitsDoPlano();

// Os arquivos que ESTE plano pode tocar. Qualquer outro, dentro dos pathspecs
// abaixo, é invasão de território.
const MEUS_ARQUIVOS = [
    REL_ABAS,
    REL_CABECALHO,
    REL_PAGINA,
    'tests/js/publicador-abas-da-pagina-187.test.js',
    'tests/js/publicador-publicacoes-cabecalho.test.js',
    'tests/js/publicador-vocabulario-novo.test.js',
    'tests/gates/contagens-187-03.txt',
];

test('TERR-05 — o território do plano 187-03, por escopo de assunto', async (contexto) => {
    await contexto.test('⚠️ o gate NÃO é vazio por construção: há commit com o escopo deste plano', () => {
        // Sem esta asserção o gate inteiro passaria sobre ZERO casos — a
        // armadilha nº 4 da lista, e a razão de o range precisar de uma âncora
        // POSITIVA antes de qualquer negativa.
        assert.ok(
            COMMITS_DO_PLANO.length >= 1,
            `nenhum commit com o escopo ${ESCOPO} em ${PARTIDA}..HEAD — o gate mediria NADA`,
        );
    });

    await contexto.test('os 13 outros arquivos de `publicacoes/` têm diff VAZIO', () => {
        const outros = fs.readdirSync(path.resolve(RAIZ, DIR_PUB))
            .filter((nome) => nome.endsWith('.js') || nome.endsWith('.jsx'))
            .map((nome) => `${DIR_PUB}/${nome}`)
            .filter((caminho) => caminho !== REL_ABAS && caminho !== REL_CABECALHO);
        assert.ok(outros.length >= 13, `esperava ao menos 13 outros arquivos, achei ${outros.length}`);
        assert.deepEqual(tocados(COMMITS_DO_PLANO, outros), [], 'este plano mexeu no corpo da lista (ABA-06 / critério 4)');
    });

    await contexto.test('os diretórios vizinhos e os arquivos proibidos têm diff VAZIO', () => {
        const PROIBIDOS = [
            'resources/js/Components/Mlb/Publicador/identidade',
            'resources/js/Components/Mlb/Publicador/visaogeral',
            'resources/js/Components/Mlb/Publicador/header',
            'resources/js/Components/Mlb/Publicador/aprovacao',
            'resources/js/Components/Publicador/Mesa',
            'resources/js/Pages/Mlb/Publicador/Editor.jsx',
            'resources/js/Pages/Mlb/AnunciarML.jsx',
            'resources/js/Layouts/AppLayout.jsx',
            'tests/js/estrutura-meus-anuncios.test.js',
            'tests/js/publicador-header-zindex-ordem.test.js',
            'app',
            'package.json',
            'package-lock.json',
            'composer.json',
            'composer.lock',
        ];
        // ⚠️ `test -e` em CADA caminho: 6 caminhos fantasma neste projeto já
        // "provaram" território intocado medindo NADA.
        for (const caminho of PROIBIDOS) {
            assert.ok(fs.existsSync(path.resolve(RAIZ, caminho)), `caminho inexistente: ${caminho} — o gate mediria NADA`);
        }
        assert.deepEqual(tocados(COMMITS_DO_PLANO, PROIBIDOS), [], 'este plano saiu do território declarado');
    });

    await contexto.test('⚠️ ISCA DO TERRITÓRIO: o mesmo medidor ACHA os arquivos que são deste plano', () => {
        // Sem esta isca, os dois `deepEqual` vazios acima poderiam ser vazios
        // porque o medidor não mede nada — que é a forma nº 1 de gate verde
        // inútil neste projeto.
        const meus = tocados(COMMITS_DO_PLANO, MEUS_ARQUIVOS);
        assert.ok(meus.length >= 3, `o medidor não achou os arquivos deste plano: ${meus.join(', ')}`);
        assert.ok(meus.includes(REL_ABAS), 'o medidor não achou nem o componente novo — ele está cego');
    });

    await contexto.test('nenhum commit deste plano toca PHP (este plano é só render)', () => {
        const php = [];
        for (const c of COMMITS_DO_PLANO) {
            const saida = execFileSync('git', ['show', '--numstat', '--format=', c.sha], { cwd: RAIZ, encoding: 'utf8' });
            for (const linha of saida.split('\n')) {
                const partes = linha.trim().split('\t');
                if (partes.length === 3 && partes[2].endsWith('.php')) php.push(`${c.sha.slice(0, 8)}:${partes[2]}`);
            }
        }
        assert.deepEqual(php, [], 'este plano tocou PHP — ele é só render');
    });
});
