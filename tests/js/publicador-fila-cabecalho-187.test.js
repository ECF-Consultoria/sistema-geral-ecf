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
// Fase 187, plano 04 — A PÁGINA DA FILA: o cabeçalho com a aba ATIVA, a linha
// da margem mínima e a FAIXA DE 9 COLUNAS que filtra (FILA-01, FILA-02,
// FILA-03, AND-09, ABA-02, COL-03, FID-01..04, REND-01..04).
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas, sha256 `68624883ce35547e`). A seção E1 é `:26`-`:205`.
//
// ⚠️⚠️ AS LINHAS DO PLANO ESTAVAM DESLOCADAS EM SETE DOS DOZE PONTEIROS, e as
// corretas abaixo foram medidas por `grep -n` nesta sessão (15/10/2026). É
// literalmente o modo de falhar que a tabela FID-01 existe para evitar — a
// tabela de fidelidade deste projeto não erra VALOR, erra por OMISSÃO e por
// REFERÊNCIA:
//
//   elemento                     plano    MEDIDO
//   raiz (min-height:100vh)      :25      :25   ✓
//   <main>                       :29      :29   ✓
//   <header> do bloco            :30      :30   ✓
//   coluna do H1                 :31      :31   ✓
//   H1 "Publicações"             :32      :32   ✓
//   bloco da DIREITA             :37      :38   ⚠️ deslocado
//   o ⓘ                          :38      :39   ⚠️ deslocado
//   o valor "12%" e o "·"        :39      :40   ⚠️ deslocado
//   grid da faixa                :43      :44   ⚠️ deslocado
//   <button> do card             :45      :46   ⚠️ deslocado
//   rótulo + dot do card         :46      :47   ⚠️ deslocado
//   número do card               :47      :48   ⚠️ deslocado
//   cores ativo/inativo (`stages`) :1217  :1217 ✓
//
// ⚠️⚠️ A DIVERGÊNCIA DO SEPARADOR, medida e registrada (`:40`): o protótipo NÃO
// tem espaço em volta do `·`. O literal é
// `Margem mínima: <span>12%</span><span>·</span>Toda oferta precisa …` — o
// espaçamento de 8px vem do `gap:8px` do bloco PAI (`:38`), que é `display:flex`
// e transforma cada nó de texto em item anônimo. O `<behavior>` do plano pede
// `" · "` COM espaços; escrever os espaços produziria 8px de gap MAIS dois
// espaços tipográficos. ➡️ **O protótipo ganha**: cinco itens flex, sem espaço
// literal nenhum, e o gate mede a ORDEM dos itens e cada literal.
//
// ⚠️⚠️ A DIVERGÊNCIA DO PESO 800, medida e registrada (`:39`): o ⓘ é
// `font-weight:800`. O plano prescreve `font-extrabold`, mas
// `publicador-vocabulario-novo.test.js` (GATE-03, Fase 177) PROÍBE a escala
// NOMEADA `font-extrabold` em todo arquivo de `ARQUIVOS_NOVOS` — e o E1 tem
// `800` MEDIDO (`187-BASELINES.md` §5: `font-weight | 500 600 700 800`).
// ➡️ A classe é `font-[800]`: o MESMO peso computado, por valor arbitrário, sem
// a escala nomeada que o GATE-03 veta e sem arredondar para `font-bold` (700),
// que seria FID-03 literal. Há asserção no CSS CONSTRUÍDO provando que ela
// compila para `font-weight:800` — valor arbitrário que não compila é o
// precedente medido de `duration-[Nms]`.
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
// discriminador é o ESCOPO DO ASSUNTO (`(187-04)`), nunca o autor.
const PARTIDA = 'a47a8c39';
const ESCOPO = '(187-04)';

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});
global.window = {
    location: { search: '', href: 'http://localhost/mlb/anuncios/meus/9/aprovacao' },
    addEventListener: () => {},
    removeEventListener: () => {},
    history: { state: null, replaceState: () => {} },
};

const STUB_INERTIA = path.join(__dirname, `.pub-fila187-inertia-stub-${process.pid}.mjs`);
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
        `.pub-fila187-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';
const REL_CABECALHO = `${DIR_APROV}/CabecalhoDaFila.jsx`;
const REL_ANDAMENTO = `${DIR_APROV}/andamentoDoPortao.jsx`;
const REL_ABAS = 'resources/js/Components/Mlb/Publicador/publicacoes/AbasDaPagina.jsx';
const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

// ⚠️ `test -e` ANTES de acreditar em qualquer leitura vazia. Seis caminhos
// fantasma já "provaram" território intocado nesta milestone.
for (const caminho of [DIR_APROV, REL_CABECALHO, REL_ANDAMENTO, REL_ABAS, PROTOTIPO]) {
    if (!fs.existsSync(path.resolve(RAIZ, caminho))) {
        throw new Error(`caminho inexistente: ${caminho} — gate mediria NADA`);
    }
}

const MOD_CABECALHO = await montar(REL_CABECALHO);
const Cabecalho = MOD_CABECALHO.default;

const FONTE_CABECALHO = lerSemComentarios(REL_CABECALHO);
const TEXTO_PROTOTIPO = fs.readFileSync(path.resolve(RAIZ, PROTOTIPO), 'utf8');

const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

// Os dois `href` no formato REAL das URIs (`routes/mlb_anuncios.php:501`).
const HREF_FILA = '/mlb/anuncios/meus/9/aprovacao';
const HREF_LISTA = '/mlb/anuncios/meus/9';

const HTML_CABECALHO = render(Cabecalho, {
    contador: 6,
    publicadas: 24,
    margemMinima: { valor: 12, origem: 'config' },
    hrefAprovacao: HREF_FILA,
    hrefLista: HREF_LISTA,
});

// ─────────────────────────────────────────────────────────────────────────
// Localização por ÁRVORE com índice absoluto / por TEXTO ÚNICO.
//
// ⚠️ NUNCA por lista de classes: `html.indexOf(tagDeAbertura)` pega o IRMÃO
// ERRADO quando dois elementos compartilham a mesma string de classes.
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

/** A tag de abertura do elemento que CONTÉM o texto `texto`. */
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

/** Os valores adversos que já derrubaram tela neste projeto. */
const ADVERSOS = [
    null,
    undefined,
    0,
    -1,
    NaN,
    Infinity,
    '',
    '12',
    'doze',
    [],
    [1, 2],
    {},
    { valor: 12 },
    { toString: null },
    { valueOf: null },
    true,
];

// As classes PRESCRITAS, uma vez só, lidas pelos gates de HTML e de CSS.
const CL_HEADER = ['flex', 'items-end', 'justify-between', 'gap-[24px]', 'border-b', 'border-pub-line-subtle'];
const CL_COLUNA = ['flex', 'flex-col', 'gap-[14px]'];
const CL_H1 = ['m-0', 'text-[28px]', 'font-semibold', 'leading-none', 'tracking-[-0.025em]'];
const CL_DIREITA = [
    'flex', 'items-center', 'gap-[8px]', 'whitespace-nowrap', 'pb-[12px]',
    'text-[12.5px]', 'text-pub-txt-3',
];
const CL_INFO = [
    'grid', 'h-[16px]', 'w-[16px]', 'place-items-center', 'rounded-full',
    'bg-pub-line-subtle', 'text-[10px]', 'font-[800]', 'text-pub-fase1',
];
const CL_VALOR = ['font-semibold', 'text-pub-txt'];
const CL_SEPARADOR = ['text-pub-line-off'];

// ═══════════════════════════════════════════════════════════════════════════
// 1. OS LITERAIS, BYTE A BYTE — e o H1 que é o MESMO da lista
//
// O que precisaria estar errado para falhar: alguém dar à fila um H1 próprio
// ("Aprovações", "Fila de aprovação"), que faria a tela parecer outra PÁGINA
// quando ela é outra ABA da mesma; ou parafrasear a frase da margem mínima.
// ═══════════════════════════════════════════════════════════════════════════

test('FILA-02 — os literais do cabeçalho da fila saem byte a byte do protótipo', async (contexto) => {
    await contexto.test('o H1 é "Publicações" — o MESMO literal da lista, porque é a mesma página', () => {
        assert.equal(MOD_CABECALHO.TITULO, 'Publicações');
        assert.ok(HTML_CABECALHO.includes('Publicações'), HTML_CABECALHO);
    });

    await contexto.test('os três literais da linha da margem mínima', () => {
        assert.equal(MOD_CABECALHO.PREFIXO_MARGEM, 'Margem mínima:');
        assert.equal(MOD_CABECALHO.SEPARADOR, '·');
        assert.equal(MOD_CABECALHO.FRASE_DA_APROVACAO, 'Toda oferta precisa de aprovação humana antes de publicar');
    });

    await contexto.test('⚠️ CONTROLE: os quatro literais estão NO PROTÓTIPO (`:32` e `:40`)', () => {
        // Sem este controle os literais acima poderiam ser invenção coerente —
        // iguais no código e no teste, errados na tela.
        for (const literal of [
            'Publicações',
            'Margem mínima:',
            'Toda oferta precisa de aprovação humana antes de publicar',
        ]) {
            assert.ok(TEXTO_PROTOTIPO.includes(literal), `o protótipo não tem "${literal}" — a referência mudou`);
        }
        // E a prova de que é o protótipo CERTO: a faixa de 9 só existe nele.
        assert.ok(TEXTO_PROTOTIPO.includes('repeat(9,minmax(0,1fr))'), 'este não é o protótipo da FILA');
    });

    await contexto.test('⚠️⚠️ DIVERGÊNCIA MEDIDA `:40` — o protótipo NÃO tem espaço em volta do `·`', () => {
        // O `<behavior>` do plano pede `" · "` COM espaços. Medido no protótipo:
        // o separador é um `<span>` irmão, e o espaçamento sai do `gap:8px` do
        // bloco pai (`:38`). Escrever os espaços daria 8px MAIS dois espaços
        // tipográficos. O protótipo ganha, e a divergência fica registrada.
        assert.ok(
            TEXTO_PROTOTIPO.includes('</span><span style="color:#3F3F46">·</span>Toda oferta'),
            'o protótipo mudou: o `·` deixou de ser span irmão sem espaço',
        );
        assert.ok(
            !TEXTO_PROTOTIPO.includes(' · Toda oferta'),
            'o protótipo passou a ter espaço em volta do `·` — remeça a divergência',
        );
        // E a nossa linha segue o protótipo: sem espaço literal em volta do `·`.
        assert.ok(HTML_CABECALHO.includes('</span>Toda oferta precisa'), HTML_CABECALHO);
    });

    await contexto.test('⚠️ ISCA do literal: paráfrases plausíveis NÃO entram na tela', () => {
        // ⚠️ Cada isca é plausível E foi conferida como ausente. Sonda cujo
        // silêncio depende de UMA LETRA é sorte, não medida.
        for (const isca of [
            'Aprovações',
            'Fila de aprovação',
            'Margem mínima de',
            'Toda oferta precisa de aprovação antes de publicar',
            'Nenhuma oferta pode ser publicada sem aprovação',
        ]) {
            assert.ok(!HTML_CABECALHO.includes(isca), `a paráfrase "${isca}" entrou na tela`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. AS ABAS VÊM DO 187-03 — mesmo componente, com a da FILA ativa
//
// O que precisaria estar errado para falhar: alguém recriar as duas abas aqui
// dentro. Duas cópias divergiriam (um rótulo mudado num lado só), e é por isso
// que esta wave vem DEPOIS da 187-03.
// ═══════════════════════════════════════════════════════════════════════════

test('ABA-02 — o cabeçalho da fila IMPORTA `publicacoes/AbasDaPagina.jsx`, não recria as abas', async (contexto) => {
    await contexto.test('o import é do componente do 187-03, por caminho', () => {
        assert.match(
            FONTE_CABECALHO,
            /import AbasDaPagina from '@\/Components\/Mlb\/Publicador\/publicacoes\/AbasDaPagina'/,
            'o cabeçalho da fila tem de IMPORTAR as abas do 187-03 — duas cópias divergiriam',
        );
    });

    await contexto.test('e os dois rótulos NÃO estão escritos neste arquivo (nenhuma cópia à mão)', () => {
        // ⚠️ A prova de que não há segunda verdade: os literais moram em
        // `AbasDaPagina.jsx` e só lá.
        assert.ok(
            !FONTE_CABECALHO.includes('Publicadas no Mercado Livre'),
            'o rótulo da aba da lista foi COPIADO para o cabeçalho da fila',
        );
        assert.ok(
            !FONTE_CABECALHO.includes('Aguardando aprovação'),
            'o rótulo da aba da fila foi COPIADO para o cabeçalho da fila',
        );
        // ⚠️ CONTROLE: a sonda enxerga — os dois estão no arquivo do 187-03.
        const fonteAbas = lerSemComentarios(REL_ABAS);
        assert.ok(fonteAbas.includes('Publicadas no Mercado Livre'), 'a sonda está cega');
        assert.ok(fonteAbas.includes('Aguardando aprovação'), 'a sonda está cega');
    });

    await contexto.test('a aba ATIVA aqui é a da FILA (`ativa="aprovacao"`), e sai sem href', () => {
        // Por ÁRVORE: o `<span>` que contém o rótulo da fila é a aba ativa.
        const tag = tagDoElementoComTexto(HTML_CABECALHO, 'Aguardando aprovação', 'span');
        assert.equal(atributo(tag, 'href'), null, 'a aba ativa virou link para si mesma — destino falso');
        assert.equal(atributo(tag, 'aria-current'), 'page', `a aba ativa não se anuncia: ${tag}`);
    });

    await contexto.test('a aba INATIVA aqui é a da LISTA, com href para `mlb.anuncios.meus`', () => {
        const tag = tagDoElementoComTexto(HTML_CABECALHO, 'Publicadas no Mercado Livre', 'a');
        assert.equal(atributo(tag, 'href'), HREF_LISTA, `a aba da lista não aponta para a lista: ${tag}`);
    });

    await contexto.test('⚠️ as DUAS telas levam ao lugar certo: aqui a ativa é a fila, lá é a lista', () => {
        // ⚠️ A prova do SENTIDO INVERSO mora no gate do 187-03 (a lista com
        // `ativa="publicadas"`). Aqui provamos que o MESMO componente, com a
        // outra prop, inverte os papéis — e que a prop vem do cabeçalho da fila.
        assert.match(FONTE_CABECALHO, /ativa="aprovacao"/, 'o cabeçalho da fila não marca a aba da fila como ativa');
        assert.ok(!FONTE_CABECALHO.includes('ativa="publicadas"'), 'o cabeçalho da fila marca a aba da LISTA como ativa');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. FID-01 — A GEOMETRIA, POR IGUALDADE DE CONJUNTO DE CLASSES
//
// O que precisaria estar errado para falhar: qualquer medida trocada
// (`pb-[12px]` esquecido — e é ele que distingue a direita da FILA da direita
// da LISTA, D2 —, `12.5px`→`text-sm`, `gap-[8px]`→`gap-[10px]`, que é o da
// lista), qualquer classe A MAIS (igualdade de conjunto), ou o ⓘ virar ícone.
// ═══════════════════════════════════════════════════════════════════════════

test('FID-01 — a geometria do cabeçalho da fila bate com o protótipo', async (contexto) => {
    await contexto.test('<header> :30 — flex, items-end, space-between, gap 24, borda de baixo', () => {
        assertClasses(tagEm(HTML_CABECALHO, indiceDaTag(HTML_CABECALHO, 'header')), CL_HEADER, '<header> :30');
    });

    await contexto.test('coluna do H1 :31 — flex-col com gap 14', () => {
        assertClasses(tagEm(HTML_CABECALHO, indiceDaTag(HTML_CABECALHO, 'div')), CL_COLUNA, 'coluna :31');
    });

    await contexto.test('H1 :32 — 28px / 600 / leading 1 / tracking -0.025em / margem zero', () => {
        assertClasses(tagEm(HTML_CABECALHO, indiceDaTag(HTML_CABECALHO, 'h1')), CL_H1, 'H1 :32');
    });

    await contexto.test('⚠️ bloco da DIREITA :38 — e o `pb-[12px]` que a LISTA não tem (D2)', () => {
        const tag = tagDoElementoComTexto(HTML_CABECALHO, 'Margem mínima:', 'div');
        assertClasses(tag, CL_DIREITA, 'direita :38');
        // ⚠️ A direita da LISTA é `gap:10px` e **sem** `padding-bottom`
        // (`design_handoff_publicador/Publicacoes v2.dc.html:39`). Harmonizar as
        // duas é FID-02 literal — e é exatamente por isso que este é ARQUIVO
        // PRÓPRIO em vez de `CabecalhoDePublicacoes` parametrizado.
        assert.ok(classesDa(tag).includes('pb-[12px]'), 'o `padding-bottom:12px` da FILA sumiu');
        assert.ok(!classesDa(tag).includes('gap-[10px]'), 'o `gap:10px` é da LISTA, não da FILA');
    });

    await contexto.test('⚠️⚠️ o ⓘ :39 é um <span> redondo com a letra `i`, NUNCA um ícone (D4)', () => {
        const tag = tagDoElementoComTexto(HTML_CABECALHO, 'Margem mínima:', 'div');
        // O ⓘ é a primeira <span> dentro do bloco da direita.
        const iDireita = HTML_CABECALHO.indexOf(tag);
        const iInfo = HTML_CABECALHO.indexOf('<span', iDireita);
        assert.ok(iInfo !== -1, 'o bloco da direita não tem <span> nenhuma');
        assertClasses(tagEm(HTML_CABECALHO, iInfo), CL_INFO, 'o ⓘ :39');

        // ⚠️ É a LETRA `i`, não um SVG: trocar por `Info` do Lucide mudaria o
        // desenho E o `viewBox` (`0 0 24 24` contra os `0 0 16 16` do protótipo,
        // o que exigiria `strokeWidth × 1,5`).
        assert.ok(HTML_CABECALHO.includes('>i</span>'), `o ⓘ não é a letra "i": ${HTML_CABECALHO}`);
        assert.ok(!HTML_CABECALHO.includes('<svg'), 'o ⓘ virou ícone — D4 proíbe');
        assert.ok(!FONTE_CABECALHO.includes('lucide-react'), 'o cabeçalho da fila importou ícone do Lucide');
    });

    await contexto.test('o valor e o separador :40 — 600 sobre `txt`, e o `·` em `line-off`', () => {
        const tagValor = tagDoElementoComTexto(HTML_CABECALHO, '12%', 'span');
        assertClasses(tagValor, CL_VALOR, 'o valor :40');
        const tagSep = tagDoElementoComTexto(HTML_CABECALHO, '·', 'span');
        assertClasses(tagSep, CL_SEPARADOR, 'o separador :40');
    });

    await contexto.test('⚠️ AUSÊNCIA MEDIDA: `cursor` aparece ZERO vezes neste bloco do protótipo', () => {
        // ⚠️ Medido por script na faixa `:30`-`:41` do protótipo: nenhum
        // `cursor:`. O cabeçalho da fila não tem nada clicável — as abas são
        // `<span>`/`<a>` e a linha da margem é texto. Acrescentar
        // `cursor-pointer` seria "deixei igual aos outros blocos" (FID-02).
        const bloco = TEXTO_PROTOTIPO.split(/\r?\n/).slice(29, 41).join('\n');
        assert.ok(bloco.includes('Margem mínima:'), 'a fatia medida não é a do cabeçalho — remeça');
        assert.equal((bloco.match(/cursor:/g) ?? []).length, 0, 'o protótipo passou a ter cursor no cabeçalho');
        assert.doesNotMatch(FONTE_CABECALHO, /cursor-pointer/, 'o cabeçalho da fila ganhou cursor que o protótipo não tem');

        // ⚠️ CONTROLE POSITIVO da sonda de `cursor`: ela ACHA os 16 do E1.
        const e1 = TEXTO_PROTOTIPO.split(/\r?\n/).slice(25, 205).join('\n');
        assert.equal((e1.match(/cursor:pointer/g) ?? []).length, 16, 'a sonda de cursor está cega (ou o E1 mudou)');
    });

    await contexto.test('FID-03/FID-04 — nenhuma escala nomeada, nenhum token antigo, nenhum hex', () => {
        assert.doesNotMatch(FONTE_CABECALHO, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/, 'FID-03: text-sm é 14px, não é passo do protótipo');
        assert.doesNotMatch(FONTE_CABECALHO, /\becf-(bg|card|card-2|yellow|yellow-2|line|dim|mute)\b/, 'FID-04');
        assert.doesNotMatch(FONTE_CABECALHO, /\bfont-display\b/, 'FID-04');
        assert.doesNotMatch(FONTE_CABECALHO, /border-white\/\[0\.08\]/, 'FID-04');
        assert.doesNotMatch(FONTE_CABECALHO, /#[0-9a-fA-F]{3,8}\b/, 'TOK-15: a cor vem por token, nunca por hex');
        // ⚠️ E a escala NOMEADA do peso 800 também não: `font-extrabold` é
        // proibida pelo GATE-03 e o valor arbitrário `font-[800]` é o caminho.
        assert.doesNotMatch(FONTE_CABECALHO, /font-(thin|extralight|light|extrabold|black)\b/, 'GATE-03: escala nomeada de peso');
        assert.match(FONTE_CABECALHO, /font-\[800\]/, 'o peso 800 do ⓘ (`:39`) desapareceu');
    });

    await contexto.test('⚠️ as classes moram NESTE .jsx (o content do Tailwind não varre .js)', () => {
        assert.ok(REL_CABECALHO.endsWith('.jsx'), 'o componente tem de ser .jsx para o Tailwind varrer as classes');
        for (const classe of [...CL_HEADER, ...CL_COLUNA, ...CL_H1, ...CL_DIREITA, ...CL_INFO, ...CL_VALOR, ...CL_SEPARADOR]) {
            assert.ok(FONTE_CABECALHO.includes(classe), `a classe ${classe} não está na FONTE do .jsx`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. O VALOR VEM DA PROP, E A ORIGEM VIAJA COM ELE
//
// O que precisaria estar errado para falhar: alguém cravar o `12` do protótipo
// (é dado de exemplo — `MIN = 12` em `:914`), que é EXMP-01 literal, ou perder
// a origem, sem a qual o número não diz de onde vem (FIN-09).
// ═══════════════════════════════════════════════════════════════════════════

test('FIN-09 — o mínimo vem da prop, com a origem, e nunca de literal', async (contexto) => {
    await contexto.test('o valor da prop é o que aparece — e muda quando a prop muda', () => {
        const html20 = render(Cabecalho, { margemMinima: { valor: 20, origem: 'parametro_da_empresa' } });
        assert.ok(html20.includes('20%'), html20);
        assert.ok(!html20.includes('12%'), 'o 12 do protótipo está cravado no componente');

        const html85 = render(Cabecalho, { margemMinima: { valor: 8.5, origem: 'config' } });
        // ⚠️ Decimal em pt-BR: vírgula, nunca ponto.
        assert.ok(html85.includes('8,5%'), html85);
    });

    await contexto.test('⚠️ GATE DE AUSÊNCIA do literal `12` como percentual, com os DOIS controles', () => {
        // A sonda: `12%`, `= 12`, `: 12`, `0.12` — com `\b` nas duas pontas,
        // para `120` e `1.12` não casarem (learnings §4 caso 4).
        //
        // ⚠️ O `}` na classe de terminadores NÃO é zelo: o CONTROLE POSITIVO
        // `const p = { margem: 12 };` REPROVOU a primeira redação desta sonda,
        // porque `12` ali é seguido de ` }` e a classe só tinha `;,)]`. É o
        // achado da armadilha 3 — *teste toda regex contra um caso que DEVE
        // casar* — e a forma mais comum de literal em objeto de props no JSX.
        const sonda = /\b0\.12\b|\b12(?:\.0+)?\s*%|(?:=>|=|:)\s*12(?:\.0+)?\s*[;,)\]}]/;
        assert.doesNotMatch(FONTE_CABECALHO, sonda, 'FIN-09: o 12% virou literal no componente');

        // ⚠️ CONTROLE POSITIVO — a sonda ACUSA as formas que ela proíbe.
        for (const sintetico of ['const m = 0.12;', 'const f = "margem de 12%";', 'const p = { margem: 12 };', 'const x = 12.0;']) {
            assert.match(sintetico, sonda, `CONTROLE POSITIVO: a sonda não acha o literal em: ${sintetico}`);
        }
        // ⚠️ CONTROLE NEGATIVO — texto que CONTÉM "12" legitimamente passa.
        for (const inocente of ['const l = "Aprovacao.dc.html:120";', 'const v = 1.12;', 'const n = 112;', 'const d = "12.345.678/0001-99";']) {
            assert.doesNotMatch(inocente, sonda, `CONTROLE NEGATIVO: a sonda acusou um inocente: ${inocente}`);
        }
    });

    await contexto.test('a `origem` chega à tela como texto auxiliar (`title`), em português', () => {
        const daConfig = render(Cabecalho, { margemMinima: { valor: 12, origem: 'config' } });
        const tagConfig = tagDoElementoComTexto(daConfig, '12%', 'span');
        const tituloConfig = atributo(tagConfig, 'title');
        assert.ok(tituloConfig !== null && tituloConfig !== '', `o valor da config não diz de onde vem: ${tagConfig}`);

        const doParametro = render(Cabecalho, { margemMinima: { valor: 15, origem: 'parametro_da_empresa' } });
        const tagParam = tagDoElementoComTexto(doParametro, '15%', 'span');
        const tituloParam = atributo(tagParam, 'title');
        assert.ok(tituloParam !== null && tituloParam !== '', `o valor do parâmetro não diz de onde vem: ${tagParam}`);

        // ⚠️ E as duas origens dizem COISAS DIFERENTES: um `title` único para as
        // duas não diria de onde o número veio — seria decoração.
        assert.notEqual(tituloConfig, tituloParam, 'as duas origens produzem o MESMO texto auxiliar');

        // ⚠️ VOC-06 / "evitar jargão": a chave de dado não aparece crua para as
        // origens CONHECIDAS.
        assert.ok(!tituloConfig.includes('parametro_da_empresa'), tituloConfig);
        assert.ok(!tituloParam.includes('parametro_da_empresa'), tituloParam);
    });

    await contexto.test('⚠️ `origem` DESCONHECIDA não derruba nada e aparece COMO CHEGOU', () => {
        // Precedente literal do projeto (`SeloDeStatusDaPublicacao`): valor fora
        // das chaves aparece COMO CHEGOU — visível e corrigível é melhor que
        // invisível. O que não se faz é inventar rótulo nem apagar a linha.
        const html = render(Cabecalho, { margemMinima: { valor: 12, origem: 'veio_de_marte' } });
        assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, html);
        assert.ok(html.includes('12%'), html);
        const titulo = atributo(tagDoElementoComTexto(html, '12%', 'span'), 'title');
        assert.ok(titulo !== null && titulo.includes('veio_de_marte'), `a origem desconhecida foi engolida: ${titulo}`);
    });

    await contexto.test('⚠️ `origem` NÃO-STRING não vira `title` nenhum (e não lança)', () => {
        for (const origem of [null, undefined, 0, NaN, {}, [], { toString: null }, true]) {
            const html = render(Cabecalho, { margemMinima: { valor: 12, origem } });
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `origem ${descreve(origem)}: ${html}`);
            assert.ok(html.includes('12%'), `origem ${descreve(origem)}: o valor sumiu`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. O MÍNIMO AUSENTE NÃO INVENTA ZERO — a linha INTEIRA não aparece (D4)
//
// O que precisaria estar errado para falhar: um `?? 0` no caminho do valor, que
// faria a tela afirmar "Margem mínima: 0%" — uma mentira sobre o dinheiro do
// cliente. ⚠️ E o zero NÃO é "bloqueio desligado": o resolvedor único
// (`ComposicaoFinanceiraService::margemMinimaComOrigem`) cai na config quando o
// parâmetro é zero, então zero na prop é dado que não deveria existir.
// ═══════════════════════════════════════════════════════════════════════════

test('D4 / EXMP-01 — sem valor medido, a linha da margem mínima NÃO aparece', async (contexto) => {
    await contexto.test('nem o prefixo, nem o `·`, nem a frase, nem o ⓘ: a linha inteira sai', () => {
        for (const margemMinima of [null, undefined, {}, { valor: null }, { valor: 0 }, { valor: -3 }, { valor: '12' }, { valor: NaN }, { valor: Infinity }, { valor: {} }, [], 'doze', 12]) {
            const html = render(Cabecalho, { margemMinima });
            const onde = `margemMinima ${descreve(margemMinima)}`;
            assert.ok(!html.includes('Margem mínima:'), `${onde}: o prefixo apareceu sem valor`);
            assert.ok(!html.includes('Toda oferta precisa'), `${onde}: a frase apareceu sem valor`);
            assert.ok(!html.includes('>·<'), `${onde}: o separador ficou solto`);
            assert.ok(!html.includes('>i<'), `${onde}: o ⓘ ficou decorativo`);
            assert.ok(!html.includes('0%'), `${onde}: zero inventado na tela`);
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `${onde}: ${html}`);
        }
    });

    await contexto.test('⚠️ ISCA INVERTIDA: com valor medido a linha APARECE inteira', () => {
        // Sem esta isca o bloco acima seria vazio por construção — uma guarda
        // que devolvesse `null` SEMPRE passaria em todos os casos de cima.
        const html = render(Cabecalho, { margemMinima: { valor: 12, origem: 'config' } });
        assert.ok(html.includes('Margem mínima:'), html);
        assert.ok(html.includes('12%'), html);
        assert.ok(html.includes('Toda oferta precisa de aprovação humana antes de publicar'), html);
        assert.ok(html.includes('>·</span>'), html);
        assert.ok(html.includes('>i</span>'), html);
    });

    await contexto.test('e o H1 e as abas CONTINUAM, mesmo sem a linha da direita', () => {
        // O cabeçalho não desaparece junto: a navegação é o que a pessoa precisa.
        const html = render(Cabecalho, { margemMinima: null, contador: 0, hrefLista: HREF_LISTA });
        assert.ok(html.includes('Publicações'), html);
        assert.ok(html.includes('Aguardando aprovação'), html);
        assert.ok(html.includes('Publicadas no Mercado Livre'), html);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. REND-01/02/03 — O RENDER ADVERSO, campo por campo
//
// ⚠️⚠️ A TELA PRETA de 07/10/2026 voltou QUATRO vezes na Fase 180, e só o
// render REAL a achou: `String(valor)`, `hasOwnProperty` e **qualquer
// `obj[chave]`** LANÇAM com `{toString:null}`; `textoSeguro(NaN)` devolve a
// STRING `'NaN'`; e prop de NÓ chegando objeto comum faz o React LANÇAR e
// derruba a página inteira.
// ═══════════════════════════════════════════════════════════════════════════

test('REND-01/02/03 — nenhum campo adverso derruba o cabeçalho nem vaza lixo', async (contexto) => {
    await contexto.test('`contador` com os 16 valores adversos', () => {
        for (const contador of ADVERSOS) {
            const html = render(Cabecalho, { contador, margemMinima: { valor: 12, origem: 'config' } });
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `contador ${descreve(contador)}: ${html}`);
            assert.ok(html.includes('Aguardando aprovação'), `contador ${descreve(contador)}: a aba sumiu`);
        }
    });

    await contexto.test('`publicadas` com os 16 valores adversos', () => {
        for (const publicadas of ADVERSOS) {
            const html = render(Cabecalho, { publicadas, margemMinima: { valor: 12, origem: 'config' } });
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `publicadas ${descreve(publicadas)}: ${html}`);
        }
    });

    await contexto.test('`margemMinima` com os 16 valores adversos', () => {
        for (const margemMinima of ADVERSOS) {
            const html = render(Cabecalho, { margemMinima });
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `margemMinima ${descreve(margemMinima)}: ${html}`);
            assert.ok(html.includes('Publicações'), `margemMinima ${descreve(margemMinima)}: o H1 sumiu`);
        }
    });

    await contexto.test('os dois `href` com os 16 valores adversos', () => {
        for (const valor of ADVERSOS) {
            const a = render(Cabecalho, { hrefLista: valor, hrefAprovacao: valor, margemMinima: null });
            assert.doesNotMatch(a, /\[object Object\]|NaN|undefined|Invalid Date/, `href ${descreve(valor)}: ${a}`);
            // E nenhum destino FALSO: `#` recarrega a própria página (D4).
            assert.ok(!a.includes('href="#"'), `href ${descreve(valor)}: destino falso`);
        }
    });

    await contexto.test('o componente inteiro sem prop nenhuma continua renderizando', () => {
        const html = render(Cabecalho, {});
        assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, html);
        assert.ok(html.includes('Publicações'), html);
        assert.ok(html.includes('Aguardando aprovação'), html);
    });

    await contexto.test('REND-03 — nem String() nem JSON.stringify nem hasOwnProperty na FONTE', () => {
        assert.doesNotMatch(FONTE_CABECALHO, /String\(/, 'String({toString:null}) LANÇA e derruba a página');
        assert.doesNotMatch(FONTE_CABECALHO, /JSON\.stringify\(/, 'JSON.stringify MENTE: NaN vira null');
        assert.doesNotMatch(FONTE_CABECALHO, /hasOwnProperty/, 'hasOwnProperty.call LANÇA com chave {toString:null}');
        // ⚠️ ISCA DAS SONDAS: as três regexes casam na forma que elas proíbem.
        assert.match('const t = String(x);', /String\(/, 'a sonda de String() está cega');
        assert.match('JSON.stringify(x)', /JSON\.stringify\(/, 'a sonda de stringify está cega');
        assert.match('o.hasOwnProperty(k)', /hasOwnProperty/, 'a sonda de hasOwnProperty está cega');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. O CSS CONSTRUÍDO — a classe EXISTE com o valor certo, não só "está escrita"
//
// O que precisaria estar errado para falhar: a classe sair do `content` do
// Tailwind (num `.js`, num mapa, numa string montada), ou o valor arbitrário
// não compilar — `duration-[Nms]` é o precedente MEDIDO neste projeto, e é
// justamente por isso que o `font-[800]` desta tela tem asserção própria aqui.
//
// As SETE normalizações do medidor estão aplicadas: seletor ESCAPADO, TODOS os
// metacaracteres de regex escapados, token sólido em `rgb()` DECIMAL, e o zero
// comido depois do menos (`letter-spacing:-.025em`, medido nesta máquina).
// ═══════════════════════════════════════════════════════════════════════════

/** Escapa TODOS os metacaracteres de regex. */
function escapaRegex(texto) {
    return texto.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** O seletor que o Tailwind emite: `.` + o nome com os metacaracteres de CSS escapados. */
function seletorDe(classe) {
    return `.${classe.replace(/[[\].:/%]/g, (ch) => `\\${ch}`)}`;
}

/** Os caminhos cujo `mtime` o CSS tem de superar (ver o `skip` NOMEADO abaixo). */
const FONTES_DO_CSS = [REL_CABECALHO];

function carregarCssConstruido() {
    const manifestRel = 'public/build/manifest.json';
    const manifestAbs = path.resolve(RAIZ, manifestRel);
    if (!fs.existsSync(manifestAbs)) return { motivo: `${manifestRel} ausente — rode \`npm run build\`` };

    // ⚠️ Manifest SEMPRE pelo JSON, nunca por grep: o hash sai com HÍFEN
    // (`assets/app-Dv6-mbDf.js`, medido no 187-01) e o grep ingênuo não casa.
    // ⚠️ E `resources/css/app.css` NÃO é chave do manifest (divergência N5 do
    // `187-BASELINES.md`): o CSS entra por `manifest['resources/js/app.jsx'].css[0]`.
    const manifest = JSON.parse(fs.readFileSync(manifestAbs, 'utf8'));
    const entrada = manifest['resources/js/app.jsx'];
    if (!entrada || !Array.isArray(entrada.css) || entrada.css.length === 0) {
        return { motivo: 'o manifest não aponta CSS para resources/js/app.jsx' };
    }

    const cssAbs = path.resolve(RAIZ, 'public/build', entrada.css[0]);
    if (!fs.existsSync(cssAbs)) return { motivo: `${entrada.css[0]} ausente — rode \`npm run build\`` };

    // ⚠️ O CSS tem de ser mais NOVO que as fontes, senão este bloco mediria um
    // build velho e reprovaria o código certo (ou aprovaria o errado). ⚠️ É esta
    // guarda que produz os DOIS REGIMES de contagem do manifesto: mutar uma
    // fonte a deixa mais nova que o CSS e esta camada PULA.
    const mtimeCss = fs.statSync(cssAbs).mtimeMs;
    const mtimeFontes = Math.max(
        ...FONTES_DO_CSS.map((rel) => fs.statSync(path.resolve(RAIZ, rel)).mtimeMs),
    );
    if (mtimeCss < mtimeFontes) {
        return { motivo: `o CSS construído (${entrada.css[0]}) é mais antigo que as fontes — rode \`npm run build\`` };
    }

    return { arquivo: entrada.css[0], css: fs.readFileSync(cssAbs, 'utf8'), manifest };
}

const CSS = carregarCssConstruido();

test('FID — as classes do cabeçalho da fila GERAM CSS com o valor certo', async (contexto) => {
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
        for (const isca of ['bg-pub-isca-187-04', 'text-[99.5px]', 'gap-[997px]', 'font-[333]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS.css.includes(isca), `a string da isca ${isca} aparece no CSS`);
        }
        assert.notEqual(regraDe('text-[12.5px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('o <header> e a coluna — gap 24, gap 14 e a borda de 1px', () => {
        assert.match(regraDe('gap-[24px]'), /gap:\s*24px/);
        assert.match(regraDe('gap-[14px]'), /gap:\s*14px/);
        assert.match(regraDe('items-end'), /align-items:\s*flex-end/);
        assert.match(regraDe('border-b'), /border-bottom-width:\s*1px/);
        const borda = regraDe('border-pub-line-subtle');
        assert.notEqual(borda, null, 'a borda do cabeçalho não gerou CSS');
        // #1F1F1F → 31 31 31 (token sólido sai em `rgb()` DECIMAL, nunca hex).
        assert.match(borda, /rgb\(\s*31\s+31\s+31\b/, borda);
    });

    await contexto.test('o H1 — 28px, leading 1 e o tracking com o ZERO COMIDO pelo cssnano', () => {
        assert.match(regraDe('text-[28px]'), /font-size:\s*28px/);
        assert.match(regraDe('leading-none'), /line-height:\s*1\b/);
        // ⚠️ `-0.025em` sai `-.025em`: o cssnano come o zero depois do menos. É
        // uma das sete normalizações do medidor — comparar com `-0.025em`
        // reprovaria o código CERTO.
        assert.match(regraDe('tracking-[-0.025em]'), /letter-spacing:\s*-\.025em/);
    });

    await contexto.test('⚠️ a DIREITA — e o `pb-[12px]` que só a FILA tem (D2)', () => {
        assert.match(regraDe('text-[12.5px]'), /font-size:\s*12\.5px/);
        assert.match(regraDe('gap-[8px]'), /gap:\s*8px/);
        const pb = regraDe('pb-[12px]');
        assert.notEqual(pb, null, '`pb-[12px]` não gerou CSS — é ele que distingue a direita da FILA da da LISTA');
        assert.match(pb, /padding-bottom:\s*12px/);
        assert.match(regraDe('whitespace-nowrap'), /white-space:\s*nowrap/);
        // #8A8A90 → 138 138 144
        assert.match(regraDe('text-pub-txt-3'), /rgb\(\s*138\s+138\s+144\b/);
    });

    await contexto.test('⚠️⚠️ o ⓘ — 16×16, redondo, e o `font-[800]` COMPILANDO para 800', () => {
        assert.match(regraDe('h-[16px]'), /height:\s*16px/);
        assert.match(regraDe('w-[16px]'), /width:\s*16px/);
        assert.match(regraDe('rounded-full'), /border-radius:\s*9999px/);
        assert.match(regraDe('place-items-center'), /place-items:\s*center/);
        assert.match(regraDe('text-[10px]'), /font-size:\s*10px/);

        // ⚠️⚠️ A ASSERÇÃO QUE JUSTIFICA A DIVERGÊNCIA DO PESO 800. O plano pedia
        // `font-extrabold`, que o GATE-03 proíbe; a saída é o valor arbitrário.
        // Valor arbitrário que NÃO compila é o precedente medido de
        // `duration-[Nms]`, e sem esta asserção o ⓘ sairia com o peso herdado.
        const peso = regraDe('font-[800]');
        assert.notEqual(peso, null, '`font-[800]` não gerou CSS — o ⓘ perderia o peso 800 de `:39`');
        assert.match(peso, /font-weight:\s*800/, peso);
        // E o CONTROLE DE VALOR: não é 700 (o `font-bold` para o qual FID-03
        // proíbe arredondar).
        assert.ok(!/font-weight:\s*700/.test(peso), 'o peso do ⓘ virou 700 — FID-03 proíbe arredondar');

        // #1F1F1F → 31 31 31 (o fundo) e #D4D4D8 → 212 212 216 (a letra).
        assert.match(regraDe('bg-pub-line-subtle'), /rgb\(\s*31\s+31\s+31\b/);
        assert.match(regraDe('text-pub-fase1'), /rgb\(\s*212\s+212\s+216\b/);
    });

    await contexto.test('o valor e o separador — 600 sobre `txt` (#F4F4F5) e o `·` em `line-off` (#3F3F46)', () => {
        assert.match(regraDe('font-semibold'), /font-weight:\s*600/);
        const valor = regraDe('text-pub-txt');
        const separador = regraDe('text-pub-line-off');
        assert.notEqual(valor, null, 'a cor do valor não gerou CSS');
        assert.notEqual(separador, null, 'a cor do separador não gerou CSS');
        // #F4F4F5 → 244 244 245 ; #3F3F46 → 63 63 70
        assert.match(valor, /rgb\(\s*244\s+244\s+245\b/, valor);
        assert.match(separador, /rgb\(\s*63\s+63\s+70\b/, separador);
        // ⚠️ E as duas são DIFERENTES: harmonizá-las apagaria o separador do
        // protótipo, que é de propósito mais apagado que o número.
        assert.notEqual(valor, separador, 'o valor e o separador viraram a mesma cor');
    });
});
