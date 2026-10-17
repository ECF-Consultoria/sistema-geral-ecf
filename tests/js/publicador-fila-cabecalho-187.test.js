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
const REL_FAIXA = `${DIR_APROV}/FaixaDeAndamento.jsx`;
const REL_ANDAMENTO = `${DIR_APROV}/andamentoDoPortao.jsx`;
const REL_ABAS = 'resources/js/Components/Mlb/Publicador/publicacoes/AbasDaPagina.jsx';
const REL_PAGINA = 'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx';
const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

// ⚠️ `test -e` ANTES de acreditar em qualquer leitura vazia. Seis caminhos
// fantasma já "provaram" território intocado nesta milestone.
for (const caminho of [DIR_APROV, REL_CABECALHO, REL_FAIXA, REL_ANDAMENTO, REL_ABAS, REL_PAGINA, PROTOTIPO]) {
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

/**
 * O seletor que o Tailwind emite: `.` + o nome com os metacaracteres de CSS
 * escapados.
 *
 * ⚠️⚠️ A REGRA É GERAL — *escapa tudo que não é `[A-Za-z0-9_-]`* —, e isso é
 * CORREÇÃO DE UM FURO MEDIDO NESTA SESSÃO, não zelo. A forma herdada das waves
 * anteriores escapava só `[].:/%`, e com ela
 * `grid-cols-[repeat(9,minmax(0,1fr))]` saía **AUSENTE** no CSS construído —
 * embora a classe esteja lá, compilada, com o seletor
 * `.grid-cols-\[repeat\(9\,minmax\(0\,1fr\)\)\]` (medido no
 * `app-C34qCe1a.css`). ➡️ O gate reprovava o código CERTO, e o conserto barato
 * seria tirar a asserção do grid — perdendo justamente a classe mais cheia de
 * metacaracteres da tela.
 *
 * É a OITAVA normalização do medidor de CSS construído, e vale para qualquer
 * classe com parêntese ou vírgula (`calc(…)`, `repeat(…,…)`, `minmax(…)`).
 */
function seletorDe(classe) {
    return `.${[...classe].map((ch) => (/[A-Za-z0-9_-]/.test(ch) ? ch : `\\${ch}`)).join('')}`;
}

/**
 * Os caminhos cujo `mtime` o CSS tem de superar (ver o `skip` NOMEADO abaixo).
 *
 * ⚠️ TODOS os `.jsx` deste plano entram: se um ficasse de fora, mutá-lo deixaria
 * a camada de CSS medir um build velho e o gate aprovaria o errado.
 */
const FONTES_DO_CSS = [REL_CABECALHO, REL_FAIXA, REL_PAGINA];

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

        // ⚠️⚠️ CONTROLE DA OITAVA NORMALIZAÇÃO: uma classe com PARÊNTESE e
        // VÍRGULA tem de ser ACHADA. Com o escape antigo (só `[].:/%`) ela saía
        // AUSENTE e o gate reprovava o código certo — ver `seletorDe`.
        assert.notEqual(
            regraDe('grid-cols-[repeat(9,minmax(0,1fr))]'),
            null,
            'a sonda está cega para classe com parêntese/vírgula — é o furo medido nesta sessão',
        );
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

// ═══════════════════════════════════════════════════════════════════════════
// 8. A FAIXA DE 9 COLUNAS — a peça com mais literal da tela
//
// Linhas MEDIDAS com `grep -n` nesta sessão (⚠️ as QUATRO do plano estavam
// deslocadas em 1):
//   · grid           → :44  (`display:grid;grid-template-columns:repeat(9,minmax(0,1fr));gap:6px`)
//                            ⚠️ o plano cita :43
//   · `<button>`     → :46  (`flex-direction:column;align-items:flex-start;gap:8px;`
//                            `padding:11px 12px;border:1px solid …;border-radius:10px;`
//                            `color:#F4F4F5;cursor:pointer;text-align:left;min-width:0`
//                            + `style-hover="border-color:#3F3F46"`)  ⚠️ o plano cita :45
//   · rótulo + dot   → :47  (`gap:6px;font-size:11.5px;font-weight:500;min-height:28px;`
//                            `line-height:1.2` + dot `7×7;border-radius:50%;flex:none`)
//                            ⚠️ o plano cita :46
//   · número         → :48  (`font-size:22px;line-height:1;font-weight:600;`
//                            `letter-spacing:-0.02em;font-variant-numeric:tabular-nums`)
//                            ⚠️ o plano cita :47
//   · ativo/inativo  → :1217 (`bg: on ? '#181818' : '#111111'`,
//                             `border: on ? '#3F3F46' : '#1F1F1F'`,
//                             `labelColor: on ? '#F4F4F5' : '#A1A1AA'`,
//                             `countColor: cnt[k] ? '#F4F4F5' : '#52525B'`,
//                             `dot: k === 'all' ? '#F4F4F5' : m[4]`)  ✓ bate
//
// ⚠️⚠️ OS 9 RÓTULOS E A ORDEM SÃO **LIDOS** DE `andamentoDoPortao.jsx`, nunca
// recopiados: a Fase 186 deixou aquele módulo como fonte única de propósito, e
// uma segunda cópia divergiria num rótulo sem nenhum teste de render acusar. Há
// gate de AUSÊNCIA dos 9 literais na fonte do componente, com controle positivo
// no módulo da 186.
// ═══════════════════════════════════════════════════════════════════════════

const MOD_FAIXA = await montar(REL_FAIXA);
const MOD_ANDAMENTO = await montar(REL_ANDAMENTO);

const Faixa = MOD_FAIXA.default;
const FONTE_FAIXA = lerSemComentarios(REL_FAIXA);
const FONTE_ANDAMENTO = lerSemComentarios(REL_ANDAMENTO);

/** A faixa como o servidor a manda (`FilaDeAprovacaoService::faixa`). */
const FAIXA_DO_SERVIDOR = MOD_ANDAMENTO.FAIXA_DE_ANDAMENTO.map((entrada, indice) => ({
    chave: entrada.chave,
    rotulo: entrada.rotulo,
    // Totais DISTINTOS e um ZERO no meio, para os dois ramos de cor saírem no
    // mesmo render: `publicando` (índice 6) é o zero.
    total: indice === 6 ? 0 : (indice + 1) * 3,
}));

const HTML_FAIXA = renderToStaticMarkup(React.createElement(Faixa, {
    faixa: FAIXA_DO_SERVIDOR,
    ativa: 'aguardando_aprovacao',
    onEtapa: () => {},
}));

const HTML_FAIXA_SEM_CLIQUE = renderToStaticMarkup(React.createElement(Faixa, {
    faixa: FAIXA_DO_SERVIDOR,
    ativa: 'todas',
}));

// As classes PRESCRITAS da faixa.
const CL_GRID = ['grid', 'grid-cols-[repeat(9,minmax(0,1fr))]', 'gap-[6px]'];
const CL_CARD = [
    'flex', 'min-w-0', 'flex-col', 'items-start', 'gap-[8px]', 'rounded-pub-sub',
    'border', 'px-[12px]', 'py-[11px]', 'text-left', 'text-pub-txt', 'hover:border-pub-line-off',
];
const CL_CARD_CLICAVEL = ['cursor-pointer'];
const CL_CARD_ATIVO = ['bg-pub-raised-2', 'border-pub-line-off'];
const CL_CARD_INATIVO = ['bg-pub-surface', 'border-pub-line-subtle'];
const CL_ROTULO = ['flex', 'min-h-[28px]', 'items-center', 'gap-[6px]', 'text-[11.5px]', 'font-medium', 'leading-[1.2]'];
const CL_ROTULO_ATIVO = ['text-pub-txt'];
const CL_ROTULO_INATIVO = ['text-pub-txt-2'];
const CL_DOT = ['h-[7px]', 'w-[7px]', 'flex-none', 'rounded-full'];
const CL_NUMERO = ['text-[22px]', 'font-semibold', 'leading-none', 'tracking-[-0.02em]', 'tabular-nums'];

/** O dot de "todas" (`:1217`: `k === 'all' ? '#F4F4F5'`) — não é estado. */
const DOT_DE_TODAS = 'bg-pub-txt';

/** Todas as tags de abertura `<nome` do HTML, em ordem. */
function todasAsTags(html, nome) {
    const fora = [];
    let pos = html.indexOf(`<${nome}`);
    while (pos !== -1) {
        fora.push(tagEm(html, pos));
        pos = html.indexOf(`<${nome}`, pos + 1);
    }
    return fora;
}

/**
 * Conta o literal COMPLETO num corpo, nunca a substring.
 *
 * ⚠️⚠️ ELA EXISTE PORQUE O `includes` REPROVOU O CÓDIGO CERTO. A primeira
 * redação do gate de AUSÊNCIA dos 9 rótulos usava `FONTE.includes(rotulo)` e
 * acusou `'Todas'` — porque a flag `ehTodas`, declarada dentro do callback do
 * `.map()`, **contém a substring**. É a família *"substring casa com prefixo"*
 * (learnings §4 caso 4), a mesma que a sonda dos eixos de
 * `publicador-vocabulario-novo.test.js` já tinha medido em `chaveAtiva` ⊃
 * `Ativa`. Forma copiada de lá, com `\p{L}` para os acentos.
 */
function contarLiteralCompleto(corpo, literal) {
    const padrao = new RegExp(
        String.raw`(?<![\p{L}\p{N}_])` + escapaLiteral(literal) + String.raw`(?![\p{L}\p{N}_])`,
        'gu',
    );
    return (corpo.match(padrao) ?? []).length;
}

/** Escapa um literal para uso dentro de regex. */
function escapaLiteral(texto) {
    return texto.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** O pedaço de HTML do card que contém o rótulo `rotulo` (até o fechamento dele). */
function cardDe(html, rotulo, nome) {
    const iRotulo = html.indexOf(`>${rotulo}<`);
    assert.ok(iRotulo !== -1, `o rótulo "${rotulo}" não está no HTML como texto próprio`);
    const inicio = html.lastIndexOf(`<${nome}`, iRotulo);
    assert.ok(inicio !== -1, `não achei <${nome}> envolvendo "${rotulo}"`);
    const fim = html.indexOf(`</${nome}>`, iRotulo);
    assert.ok(fim !== -1, `o <${nome}> de "${rotulo}" não fecha`);
    return html.slice(inicio, fim + nome.length + 3);
}

test('AND-09 — a faixa é um grid de 9 colunas com os 9 cards do protótipo', async (contexto) => {
    await contexto.test('grid :44 — `repeat(9,minmax(0,1fr))` literal e `gap-[6px]`', () => {
        assertClasses(tagEm(HTML_FAIXA, indiceDaTag(HTML_FAIXA, 'div')), CL_GRID, 'grid :44');
        // ⚠️ O literal tem PARÊNTESE e VÍRGULA — os dois são metacaracteres de
        // regex e de seletor CSS, e é por isso que o medidor de CSS escapa TODOS.
        assert.ok(FONTE_FAIXA.includes('grid-cols-[repeat(9,minmax(0,1fr))]'), 'o grid de 9 não está na FONTE');
        assert.ok(!FONTE_FAIXA.includes('grid-cols-9'), 'FID-03: `grid-cols-9` é o passo do Tailwind, não o literal do protótipo');
    });

    await contexto.test('saem exatamente 9 cards, e eles são a lista INTEIRA do grid', () => {
        assert.equal(todasAsTags(HTML_FAIXA, 'button').length, 9, HTML_FAIXA);
        // ⚠️ Card a mais fora do `.map()` (uma casca falsa, por exemplo) sairia
        // como 10º elemento.
        assert.equal((HTML_FAIXA.match(/<button/g) ?? []).length, 9);
    });

    await contexto.test('⚠️⚠️ os 9 rótulos e a ORDEM são LIDOS de `andamentoDoPortao.jsx`', () => {
        // ⚠️ A ordem é conferida pela POSIÇÃO no HTML, não só pela presença:
        // uma lista reordenada tem os mesmos 9 rótulos.
        const esperados = MOD_ANDAMENTO.FAIXA_DE_ANDAMENTO.map((e) => e.rotulo);
        assert.equal(esperados.length, 9, 'o módulo da 186 deixou de ter 9 entradas');

        let anterior = -1;
        for (const rotulo of esperados) {
            const pos = HTML_FAIXA.indexOf(`>${rotulo}<`);
            assert.ok(pos !== -1, `o rótulo "${rotulo}" não saiu no HTML`);
            assert.ok(pos > anterior, `o rótulo "${rotulo}" saiu FORA da ordem de FAIXA_DE_ANDAMENTO`);
            anterior = pos;
        }

        // ⚠️ E o último é "Publicado hoje", um recorte DO DIA — não o estado
        // inteiro. Trocá-lo por "Publicado" faria a faixa prometer o histórico
        // completo e entregar o do dia.
        assert.equal(esperados[8], 'Publicado hoje');
        assert.equal(MOD_ANDAMENTO.FAIXA_DE_ANDAMENTO[8].chave, 'publicado');
    });

    await contexto.test('⚠️ AUSÊNCIA: nenhum dos 9 rótulos está COPIADO na fonte do componente', () => {
        // Segunda cópia divergiria num rótulo sem nenhum teste de render acusar.
        //
        // ⚠️ Pelo literal COMPLETO, nunca por `includes`: a flag `ehTodas` do
        // callback do `.map()` CONTÉM `'Todas'`, e o `includes` reprovou o
        // código certo na primeira rodada deste gate.
        const copiados = MOD_ANDAMENTO.FAIXA_DE_ANDAMENTO
            .map((e) => e.rotulo)
            .filter((rotulo) => contarLiteralCompleto(FONTE_FAIXA, rotulo) > 0);
        assert.deepEqual(copiados, [], 'rótulo da faixa COPIADO à mão para o componente');

        // ⚠️ CONTROLE POSITIVO: a MESMA sonda acha os 9 onde eles moram.
        const naFonte = MOD_ANDAMENTO.FAIXA_DE_ANDAMENTO
            .map((e) => e.rotulo)
            .filter((rotulo) => contarLiteralCompleto(FONTE_ANDAMENTO, rotulo) > 0);
        assert.equal(naFonte.length, 9, 'a sonda está cega: não acha os 9 nem em andamentoDoPortao.jsx');

        // ⚠️ AUTO-TESTE DA SONDA, nas duas direções — ela acha o literal inteiro
        // e NÃO conta a substring que derrubou a primeira redação.
        assert.equal(contarLiteralCompleto('o card diz Todas agora', 'Todas'), 1, 'a sonda não acha o literal inteiro');
        assert.equal(contarLiteralCompleto('const ehTodas = x;', 'Todas'), 0, 'a sonda conta SUBSTRING — ela reprovaria o código certo');
        assert.equal(contarLiteralCompleto('Publicado hoje e Publicado', 'Publicado hoje'), 1, 'a sonda não respeita a borda do literal composto');

        // E o import é do módulo, por caminho relativo ao diretório.
        assert.match(FONTE_FAIXA, /import \{[^}]*FAIXA_DE_ANDAMENTO[^}]*\} from '\.\/andamentoDoPortao'/);
        assert.match(FONTE_FAIXA, /import \{[^}]*andamentoDe[^}]*\} from '\.\/andamentoDoPortao'/);
    });
});

test('FID-01 — a geometria dos 9 cards bate com o protótipo', async (contexto) => {
    await contexto.test('card :46 — coluna, gap 8, 11×12 de padding, raio 10, borda, texto à esquerda', () => {
        const card = tagDoElementoComTexto(HTML_FAIXA, 'Aguardando aprovação', 'button');
        assertClasses(card, [...CL_CARD, ...CL_CARD_CLICAVEL, ...CL_CARD_ATIVO], 'card ATIVO :46');
        assert.equal(atributo(card, 'type'), 'button', 'o card não é `type="button"` — num <form> ele submeteria');
    });

    await contexto.test('card ATIVO :1217 — `#181818` e borda `#3F3F46`; INATIVO `#111111` e `#1F1F1F`', () => {
        const ativo = tagDoElementoComTexto(HTML_FAIXA, 'Aguardando aprovação', 'button');
        const inativo = tagDoElementoComTexto(HTML_FAIXA, 'Todas', 'button');
        assertClasses(ativo, [...CL_CARD, ...CL_CARD_CLICAVEL, ...CL_CARD_ATIVO], 'card ATIVO');
        assertClasses(inativo, [...CL_CARD, ...CL_CARD_CLICAVEL, ...CL_CARD_INATIVO], 'card INATIVO');
        // ⚠️ E exatamente UM card é o ativo: dois ativos mentiriam sobre o filtro.
        const ativos = todasAsTags(HTML_FAIXA, 'button').filter((t) => classesDa(t).includes('bg-pub-raised-2'));
        assert.equal(ativos.length, 1, `${ativos.length} cards ativos no mesmo render`);
    });

    await contexto.test('rótulo :47 — 11.5px / 500 / min-height 28 / leading 1.2, e as duas cores', () => {
        // ⚠️ POR ÁRVORE COM ÍNDICE, e não por "a <span> que envolve o texto":
        // o dot é o PRIMEIRO filho do rótulo (`:47`), então a última `<span>`
        // aberta antes do texto é o DOT, não o rótulo. A primeira redação deste
        // gate lia o dot e reprovava o código certo — o mesmo modo de falhar que
        // `tagQueEnvolve` existe para evitar quando há irmão intercalado.
        const rotuloDoCard = (rotulo) => todasAsTags(cardDe(HTML_FAIXA, rotulo, 'button'), 'span')[0];
        assertClasses(rotuloDoCard('Aguardando aprovação'), [...CL_ROTULO, ...CL_ROTULO_ATIVO], 'rótulo ATIVO :47');
        assertClasses(rotuloDoCard('Todas'), [...CL_ROTULO, ...CL_ROTULO_INATIVO], 'rótulo INATIVO :47');
    });

    await contexto.test('⚠️⚠️ dot :47 — a classe de cor é LIDA de `andamentoDe(chave).dot`', () => {
        for (const entrada of MOD_ANDAMENTO.FAIXA_DE_ANDAMENTO) {
            const card = cardDe(HTML_FAIXA, entrada.rotulo, 'button');
            const iRotulo = card.indexOf('<span');
            assert.ok(iRotulo !== -1, `o card "${entrada.rotulo}" não tem <span> nenhuma`);
            // A primeira <span> do card é o rótulo; a SEGUNDA é o dot (ele é
            // filho do rótulo, `:47`).
            const iDot = card.indexOf('<span', iRotulo + 1);
            assert.ok(iDot !== -1, `o card "${entrada.rotulo}" não tem dot`);

            // ⚠️ `todas` NÃO está em `ANDAMENTOS` (não é estado): o dot dela é
            // `bg-pub-txt` (`:1217`, `k === 'all' ? '#F4F4F5'`), e isso vai
            // ESCRITO, não deduzido.
            const esperada = entrada.chave === 'todas'
                ? DOT_DE_TODAS
                : MOD_ANDAMENTO.andamentoDe(entrada.chave).dot;
            assert.ok(esperada, `não há classe de dot para "${entrada.chave}"`);
            assertClasses(tagEm(card, iDot), [...CL_DOT, esperada], `dot de "${entrada.rotulo}"`);
        }
    });

    await contexto.test('⚠️ e nenhuma classe de dot está COPIADA na fonte (elas vêm do módulo)', () => {
        const doModulo = MOD_ANDAMENTO.CHAVES_DE_ANDAMENTO.map((c) => MOD_ANDAMENTO.andamentoDe(c).dot);
        assert.equal(doModulo.length, 8, 'o módulo da 186 deixou de ter 8 estados');
        const copiadas = [...new Set(doModulo)].filter((classe) => FONTE_FAIXA.includes(classe));
        assert.deepEqual(copiadas, [], 'classe de dot COPIADA à mão — duas verdades para a mesma cor');
        // ⚠️ CONTROLE POSITIVO: a sonda acha as distintas onde elas moram.
        const noModulo = [...new Set(doModulo)].filter((classe) => FONTE_ANDAMENTO.includes(classe));
        assert.equal(noModulo.length, new Set(doModulo).size, 'a sonda está cega');
        // ⚠️ E a ÚNICA escrita aqui é a de `todas`, com a linha do protótipo.
        assert.ok(FONTE_FAIXA.includes(DOT_DE_TODAS), '`bg-pub-txt` (o dot de "todas") saiu da fonte');
    });

    await contexto.test('número :48 — 22px / 600 / leading 1 / tracking -0.02em / tabular', () => {
        const card = cardDe(HTML_FAIXA, 'Aguardando aprovação', 'button');
        const tags = todasAsTags(card, 'span');
        // As três <span> do card: rótulo, dot e número.
        assert.equal(tags.length, 3, `o card tem ${tags.length} <span>, esperava 3 (rótulo, dot, número)`);
        assertClasses(tags[2], [...CL_NUMERO, 'text-pub-txt'], 'número :48');
        // ⚠️ `text-[22px]` depende da liberação do 187-01. `text-xl` (20px) e
        // `text-[24px]` seriam FID-03 literal.
        assert.ok(FONTE_FAIXA.includes('text-[22px]'), 'o 22px do número virou outro passo');
        assert.doesNotMatch(FONTE_FAIXA, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/, 'FID-03');
    });

    await contexto.test('número ZERO :1217 — `text-pub-txt-off`; maior que zero, `text-pub-txt`', () => {
        // O zero do fixture é `publicando` (índice 6).
        const zero = cardDe(HTML_FAIXA, 'Publicando', 'button');
        const tagsZero = todasAsTags(zero, 'span');
        assertClasses(tagsZero[2], [...CL_NUMERO, 'text-pub-txt-off'], 'número ZERO');
        assert.ok(zero.includes('>0</span>'), `o zero não saiu como texto: ${zero}`);

        const cheio = cardDe(HTML_FAIXA, 'Todas', 'button');
        const tagsCheio = todasAsTags(cheio, 'span');
        assertClasses(tagsCheio[2], [...CL_NUMERO, 'text-pub-txt'], 'número NÃO-zero');

        // ⚠️ E as duas cores são DIFERENTES no mesmo render — sem isto, as duas
        // asserções acima poderiam estar lendo a mesma classe.
        assert.notDeepEqual(classesDa(tagsZero[2]).sort(), classesDa(tagsCheio[2]).sort());
    });

    await contexto.test('⚠️ `cursor-pointer` está nos 9 cards — `:46` o DECLARA (16 ocorrências no E1)', () => {
        // ⚠️ Aqui o sentido é a OMISSÃO: o `cursor:pointer` foi omitido ONZE
        // vezes numa única fase deste projeto. O protótipo o declara no
        // `<button>` de cada card.
        const semCursor = todasAsTags(HTML_FAIXA, 'button').filter((t) => !classesDa(t).includes('cursor-pointer'));
        assert.deepEqual(semCursor, [], 'card clicável sem `cursor-pointer` — a omissão nº 1 deste projeto');
    });

    await contexto.test('⚠️ as classes moram NESTE .jsx (o content do Tailwind não varre .js)', () => {
        assert.ok(REL_FAIXA.endsWith('.jsx'), 'o componente tem de ser .jsx para o Tailwind varrer as classes');
        for (const classe of [
            ...CL_GRID, ...CL_CARD, ...CL_CARD_CLICAVEL, ...CL_CARD_ATIVO, ...CL_CARD_INATIVO,
            ...CL_ROTULO, ...CL_ROTULO_ATIVO, ...CL_ROTULO_INATIVO, ...CL_DOT, ...CL_NUMERO,
            'text-pub-txt-off',
        ]) {
            assert.ok(FONTE_FAIXA.includes(classe), `a classe ${classe} não está na FONTE do .jsx`);
        }
    });

    await contexto.test('FID-04 — nenhum token da camada antiga, nenhum hex, nenhum breakpoint', () => {
        assert.doesNotMatch(FONTE_FAIXA, /\becf-(bg|card|card-2|yellow|yellow-2|line|dim|mute)\b/, 'FID-04');
        assert.doesNotMatch(FONTE_FAIXA, /\bfont-display\b/, 'FID-04');
        assert.doesNotMatch(FONTE_FAIXA, /border-white\/\[0\.08\]/, 'FID-04');
        assert.doesNotMatch(FONTE_FAIXA, /#[0-9a-fA-F]{3,8}\b/, 'TOK-15');
        assert.doesNotMatch(FONTE_FAIXA, /\b(sm|md|lg|xl|2xl):/, 'FID-05: nenhum breakpoint');
    });
});

test('COL-03 — o clique filtra, e card sem destino NÃO é botão', async (contexto) => {
    await contexto.test('com `onEtapa` função, os 9 são <button type="button">', () => {
        assert.equal(todasAsTags(HTML_FAIXA, 'button').length, 9);
        for (const tag of todasAsTags(HTML_FAIXA, 'button')) {
            assert.equal(atributo(tag, 'type'), 'button', `card sem type="button": ${tag}`);
        }
    });

    await contexto.test('⚠️ SEM `onEtapa` função: nenhum <button>, nenhum `cursor-pointer`', () => {
        // Botão sem destino é PROIBIDO (EXMP-01). Mas o número é INFORMAÇÃO e
        // continua na tela — esconder a faixa esconderia dado medido.
        assert.equal(todasAsTags(HTML_FAIXA_SEM_CLIQUE, 'button').length, 0, HTML_FAIXA_SEM_CLIQUE);
        assert.ok(!HTML_FAIXA_SEM_CLIQUE.includes('cursor-pointer'), HTML_FAIXA_SEM_CLIQUE);
        // E os 9 rótulos CONTINUAM.
        for (const entrada of MOD_ANDAMENTO.FAIXA_DE_ANDAMENTO) {
            assert.ok(HTML_FAIXA_SEM_CLIQUE.includes(`>${entrada.rotulo}<`), `"${entrada.rotulo}" sumiu sem o clique`);
        }
    });

    await contexto.test('`onEtapa` NÃO-função não vira botão (os 16 valores adversos)', () => {
        for (const onEtapa of ADVERSOS) {
            const html = renderToStaticMarkup(React.createElement(Faixa, { faixa: FAIXA_DO_SERVIDOR, onEtapa }));
            assert.equal(
                todasAsTags(html, 'button').length,
                0,
                `onEtapa ${descreve(onEtapa)}: virou botão sem destino`,
            );
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `onEtapa ${descreve(onEtapa)}`);
        }
    });

    await contexto.test('⚠️ o clique chama `onEtapa` com a CHAVE, nunca com o rótulo', () => {
        // A chave é o que vai para a URL; o rótulo é texto de tela. Mandar o
        // rótulo montaria `?etapa=Aguardando%20aprova%C3%A7%C3%A3o`.
        assert.match(FONTE_FAIXA, /onEtapa\(entrada\.chave\)/, 'o clique não manda a chave');
        assert.ok(!FONTE_FAIXA.includes('onEtapa(entrada.rotulo'), 'o clique manda o RÓTULO para a URL');
    });

    await contexto.test('⚠️ NADA de `localStorage` nem `router` aqui — o destino é da PÁGINA', () => {
        // `localStorage` é artefato do protótipo (`KEY='ecf-aprovacao-v1'`,
        // `:914`) e o critério 13 o proíbe: o filtro vive na URL.
        assert.doesNotMatch(FONTE_FAIXA, /localStorage/, 'critério 13: o filtro vive na URL, não no localStorage');
        assert.doesNotMatch(FONTE_FAIXA, /sessionStorage/, 'mesma regra do localStorage');
        // ⚠️ E nenhum `router.*` no componente: quem sabe para onde o clique vai
        // é a PÁGINA. É o mesmo contrato dos slots do 180-05.
        assert.doesNotMatch(FONTE_FAIXA, /router\./, 'o componente navega por conta própria — o destino é da página');
    });
});

test('REND-01/02/04 — a faixa adversa não derruba a página, e as flags nascem no `.map()`', async (contexto) => {
    await contexto.test('`faixa` com os 16 valores adversos: os 9 cards CONTINUAM', () => {
        for (const faixa of ADVERSOS) {
            const html = renderToStaticMarkup(React.createElement(Faixa, { faixa, onEtapa: () => {} }));
            const onde = `faixa ${descreve(faixa)}`;
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `${onde}: ${html}`);
            // ⚠️ Os 9 cards são a ESTRUTURA (vêm do módulo), não o dado: sem
            // faixa nenhuma a navegação continua, com o total em "—".
            assert.equal(todasAsTags(html, 'button').length, 9, `${onde}: a faixa perdeu cards`);
        }
    });

    await contexto.test('`faixa` com ENTRADAS adversas (sem chave, total objeto, item nulo)', () => {
        const adversas = [
            [null, undefined, 0, 'x', [], {}],
            [{ chave: 'todas' }, { total: 5 }, { chave: null, total: 5 }],
            [{ chave: 'todas', total: {} }, { chave: 'aprovado', total: NaN }, { chave: 'falha', total: '7' }],
            [{ chave: { toString: null }, total: 1 }, { chave: 'todas', total: Infinity }],
            [{ chave: 'todas', total: -5 }, { chave: 'todas', total: 1.5 }],
        ];
        for (const faixa of adversas) {
            const html = renderToStaticMarkup(React.createElement(Faixa, { faixa, onEtapa: () => {} }));
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, html);
            assert.equal(todasAsTags(html, 'button').length, 9, html);
            // ⚠️ E NENHUM card sai VAZIO: card sem texto passa em render e
            // DESAPARECE na tela — foi assim que o bug S3 quase chegou em produção.
            for (const entrada of MOD_ANDAMENTO.FAIXA_DE_ANDAMENTO) {
                const card = cardDe(html, entrada.rotulo, 'button');
                const miolo = card.replace(/<[^>]*>/g, '').trim();
                assert.ok(miolo !== '', `card "${entrada.rotulo}" saiu VAZIO: ${card}`);
            }
        }
    });

    await contexto.test('`ativa` desconhecida não marca card nenhum, e `{toString:null}` não lança', () => {
        for (const ativa of [...ADVERSOS, 'nao_existe']) {
            const html = renderToStaticMarkup(React.createElement(Faixa, { faixa: FAIXA_DO_SERVIDOR, ativa, onEtapa: () => {} }));
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `ativa ${descreve(ativa)}`);
            const ativos = todasAsTags(html, 'button').filter((t) => classesDa(t).includes('bg-pub-raised-2'));
            assert.ok(ativos.length <= 1, `ativa ${descreve(ativa)}: ${ativos.length} cards ativos`);
        }
        // ⚠️ `all`/`awaiting`/… são chaves do PROTÓTIPO (inglês) e NÃO são chave
        // de dado: nenhuma delas pode marcar card. Aceitar "as duas formas" num
        // `||` foi achado como defeito GRAVE na verificação da Fase 186.
        for (const doPrototipo of ['all', 'awaiting', 'ecf_review', 'changes', 'published']) {
            const html = renderToStaticMarkup(React.createElement(Faixa, { faixa: FAIXA_DO_SERVIDOR, ativa: doPrototipo, onEtapa: () => {} }));
            const ativos = todasAsTags(html, 'button').filter((t) => classesDa(t).includes('bg-pub-raised-2'));
            assert.equal(ativos.length, 0, `a chave INGLESA "${doPrototipo}" marcou um card — ela não é chave de dado`);
        }
    });

    await contexto.test('⚠️ ISCA INVERTIDA: com faixa preenchida, a lista é NÃO-vazia e os números SAEM', () => {
        // Sem esta isca, as asserções de ausência acima rodariam sobre dado
        // vazio e seriam vazias por construção.
        assert.equal(todasAsTags(HTML_FAIXA, 'button').length, 9, HTML_FAIXA);
        for (const entrada of FAIXA_DO_SERVIDOR) {
            const card = cardDe(HTML_FAIXA, entrada.rotulo, 'button');
            assert.ok(card.includes(`>${entrada.total}</span>`), `o total ${entrada.total} de "${entrada.rotulo}" não saiu: ${card}`);
        }
    });

    await contexto.test('⚠️⚠️ REND-04 — NENHUMA flag nasce fora do callback do `.map()`', () => {
        // O Rollup elimina variável de escopo do componente usada dentro de um
        // `.map()`, e o `ReferenceError` só aparece no bundle de PRODUÇÃO. São 9
        // cards mapeados.
        const iMap = FONTE_FAIXA.indexOf('.map(');
        assert.ok(iMap !== -1, 'não achei o `.map(` — a faixa deixou de mapear');
        const iComponente = FONTE_FAIXA.indexOf('export default function');
        assert.ok(iComponente !== -1 && iComponente < iMap, 'não achei o componente antes do `.map(`');
        const antes = FONTE_FAIXA.slice(iComponente, iMap);

        const foraDoCallback = ['ehAtiva', 'ehTodas', 'classesDoCard', 'classesDoRotulo', 'corDoDot', 'corDoNumero', 'texto', 'total']
            .filter((nome) => new RegExp(`const\\s+${nome}\\b`).test(antes));
        assert.deepEqual(foraDoCallback, [], 'flag por card declarada FORA do `.map()` — ReferenceError só no bundle');

        // ⚠️ ISCA DA SONDA: ela ACHA a declaração quando ela está lá.
        assert.match('export default function X() { const ehAtiva = 1; return a.map(', /const\s+ehAtiva\b/, 'a sonda de flag está cega');
    });

    await contexto.test('REND-03 — nem String() nem JSON.stringify nem hasOwnProperty na FONTE', () => {
        assert.doesNotMatch(FONTE_FAIXA, /String\(/, 'String({toString:null}) LANÇA e derruba a página');
        assert.doesNotMatch(FONTE_FAIXA, /JSON\.stringify\(/, 'JSON.stringify MENTE: NaN vira null');
        assert.doesNotMatch(FONTE_FAIXA, /hasOwnProperty/, 'hasOwnProperty.call LANÇA com chave {toString:null}');
        // ⚠️ E o lookup do total é por `Map.get`, que NUNCA coage a chave —
        // `objeto[chaveAdversa]` LANÇA com `{toString:null}` e, com chave do
        // protótipo (`'toString'`), devolve uma FUNÇÃO.
        assert.match(FONTE_FAIXA, /new Map\(/, 'o lookup do total não é por Map — `obj[chave]` LANÇA');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 12. O CSS CONSTRUÍDO DA FAIXA — o `repeat(9,…)` com PARÊNTESE e VÍRGULA
//
// O que precisaria estar errado para falhar: o `grid-cols-[repeat(9,minmax(0,1fr))]`
// não compilar (é o valor arbitrário mais cheio de metacaracteres de toda a
// tela), o `leading-[1.2]` virar `line-height:1.2px`, ou uma das cinco cores de
// `:1217` sair com o hex de outra.
//
// ⚠️ O seletor deste literal tem PARÊNTESE, VÍRGULA e COLCHETE — e os três são
// metacaracteres. É o caso que o escape de TODOS os metacaracteres existe para
// cobrir; uma regex montada à mão aqui casaria qualquer coisa.
// ═══════════════════════════════════════════════════════════════════════════

test('FID — as classes da faixa de 9 GERAM CSS com o valor certo', async (contexto) => {
    if (CSS.motivo) {
        contexto.skip(CSS.motivo);
        return;
    }

    const regraDe = (classe) => {
        const re = new RegExp(`${escapaRegex(seletorDe(classe))}(?![\\w\\\\-])[^{}]*\\{([^}]*)\\}`);
        const m = CSS.css.match(re);
        return m ? m[1] : null;
    };

    await contexto.test('⚠️⚠️ o grid de 9 colunas — o literal com parêntese e vírgula COMPILA', () => {
        const grid = regraDe('grid-cols-[repeat(9,minmax(0,1fr))]');
        assert.notEqual(grid, null, '`grid-cols-[repeat(9,minmax(0,1fr))]` não gerou CSS — a faixa perderia as 9 colunas');
        assert.match(grid, /grid-template-columns:\s*repeat\(9,\s*minmax\(0,\s*1fr\)\)/, grid);
        assert.match(regraDe('gap-[6px]'), /gap:\s*6px/);

        // ⚠️ ISCA: o mesmo medidor reporta AUSENTE um grid de 8 — a mutação que
        // a campanha deste plano aplica.
        assert.equal(regraDe('grid-cols-[repeat(8,minmax(0,1fr))]'), null, 'a isca do grid de 8 virou classe de verdade');
    });

    await contexto.test('o card — raio 10 (`rounded-pub-sub`), padding 11×12 e `min-w-0`', () => {
        assert.match(regraDe('rounded-pub-sub'), /border-radius:\s*10px/);
        assert.match(regraDe('px-[12px]'), /padding-left:\s*12px/);
        assert.match(regraDe('py-[11px]'), /padding-top:\s*11px/);
        assert.match(regraDe('min-w-0'), /min-width:\s*0/);
        assert.match(regraDe('cursor-pointer'), /cursor:\s*pointer/);
    });

    await contexto.test('as DUAS superfícies de `:1217` — ativo #181818 e inativo #111111', () => {
        const ativo = regraDe('bg-pub-raised-2');
        const inativo = regraDe('bg-pub-surface');
        assert.notEqual(ativo, null, 'o fundo do card ATIVO não gerou CSS');
        assert.notEqual(inativo, null, 'o fundo do card INATIVO não gerou CSS');
        // #181818 → 24 24 24 ; #111111 → 17 17 17
        assert.match(ativo, /rgb\(\s*24\s+24\s+24\b/, ativo);
        assert.match(inativo, /rgb\(\s*17\s+17\s+17\b/, inativo);
        // ⚠️ E são DIFERENTES: o protótipo pinta os dois distintos, e "usar o
        // token parecido que já existe" é justificativa vetada (FID-02).
        assert.notEqual(ativo, inativo, 'as duas superfícies viraram a mesma — o card ativo deixa de se ver');
    });

    await contexto.test('o rótulo — 11.5px, peso 500, min-height 28 e `leading-[1.2]` SEM unidade', () => {
        assert.match(regraDe('text-[11.5px]'), /font-size:\s*11\.5px/);
        assert.match(regraDe('font-medium'), /font-weight:\s*500/);
        assert.match(regraDe('min-h-[28px]'), /min-height:\s*28px/);
        const leading = regraDe('leading-[1.2]');
        assert.notEqual(leading, null, '`leading-[1.2]` não gerou CSS');
        // ⚠️ SEM unidade: `line-height:1.2px` seria 1,2 pixel de altura de linha.
        assert.match(leading, /line-height:\s*1\.2\s*$|line-height:\s*1\.2[^p]/, leading);
        assert.ok(!/line-height:\s*1\.2px/.test(leading), 'o leading saiu em PIXEL — a linha ficaria com 1,2px');
        // As duas cores de rótulo: #F4F4F5 → 244 244 245 ; #A1A1AA → 161 161 170
        assert.match(regraDe('text-pub-txt'), /rgb\(\s*244\s+244\s+245\b/);
        assert.match(regraDe('text-pub-txt-2'), /rgb\(\s*161\s+161\s+170\b/);
    });

    await contexto.test('o dot — 7×7 e redondo', () => {
        assert.match(regraDe('h-[7px]'), /height:\s*7px/);
        assert.match(regraDe('w-[7px]'), /width:\s*7px/);
        assert.match(regraDe('flex-none'), /flex:\s*none/);
    });

    await contexto.test('o número — 22px, tracking -0.02em e `tabular-nums`', () => {
        const vinteEDois = regraDe('text-[22px]');
        assert.notEqual(vinteEDois, null, '`text-[22px]` não gerou CSS — o 22 do 187-01 não chegou à tela');
        assert.match(vinteEDois, /font-size:\s*22px/);
        // ⚠️ O zero depois do menos é comido pelo cssnano: `-.02em`.
        assert.match(regraDe('tracking-[-0.02em]'), /letter-spacing:\s*-\.02em/);
        assert.match(regraDe('tabular-nums'), /font-variant-numeric:/);
        // O zero: #52525B → 82 82 91
        assert.match(regraDe('text-pub-txt-off'), /rgb\(\s*82\s+82\s+91\b/);
    });

    await contexto.test('⚠️ o hover da borda — `:46` declara `border-color:#3F3F46` no `style-hover`', () => {
        // ⚠️ Classe de VARIANTE: o seletor do Tailwind é `.hover\:border-pub-line-off:hover`.
        // Uma sonda que procurasse só `.hover\:border-pub-line-off` reportaria
        // ausente e reprovaria o código certo.
        assert.ok(
            CSS.css.includes('.hover\\:border-pub-line-off:hover'),
            'o hover da borda do card não gerou CSS — `:46` o declara em `style-hover`',
        );
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 13. A PÁGINA — a ordem vertical de FILA-01, os slots VAZIOS e o manifest
//
// ⚠️⚠️ ESCOPO DECLARADO: aqui a prova é sobre a FONTE da página e sobre o
// MANIFEST, **não sobre o render dela**. Renderizar `Pages/Mlb/Publicador/
// Aprovacao.jsx` exigiria montar o `AppLayout` e o `PublicadorHeader` inteiros
// (que leem `usePage()`, Radix e o `localStorage` das contas recentes) — e o
// que isso mediria seria a casca da Fase 177, não esta wave. O comportamento
// adverso está provado nos DOIS COMPONENTES, que são quem decide desenhar: as
// seções 4 a 11 deste arquivo. É o mesmo recorte declarado pelo gate do 187-03.
//
// ⚠️ E o que NÃO pode ser afirmado está escrito: **nenhum executor desta fase
// viu esta tela renderizada**. O banco local não tem empresa com `ml_token` e
// `ml_acervo_itens` está vazia (a rota dá 404 nesta máquina). A conferência é do
// usuário, em produção.
// ═══════════════════════════════════════════════════════════════════════════

const FONTE_PAGINA = lerSemComentarios(REL_PAGINA);

test('FILA-01 — a página monta cabeçalho e faixa na ordem vertical, e os slots nascem VAZIOS', async (contexto) => {
    await contexto.test('a página IMPORTA os dois componentes desta wave e o header da Fase 177', () => {
        assert.match(
            FONTE_PAGINA,
            /import CabecalhoDaFila from '@\/Components\/Mlb\/Publicador\/aprovacao\/CabecalhoDaFila'/,
        );
        assert.match(
            FONTE_PAGINA,
            /import FaixaDeAndamento from '@\/Components\/Mlb\/Publicador\/aprovacao\/FaixaDeAndamento'/,
        );
        assert.match(
            FONTE_PAGINA,
            /import PublicadorHeader from '@\/Components\/Mlb\/Publicador\/header\/PublicadorHeader'/,
        );
    });

    await contexto.test('⚠️ a ORDEM VERTICAL de FILA-01, medida por POSIÇÃO na fonte', () => {
        // cabeçalho → faixa → PRÓXIMO → filtros → tabela (que CONTÉM o rodapé).
        //
        // ⚠️⚠️ **O `{noRodape}` SAIU DO NÍVEL DA PÁGINA NO PLANO 187-09, E ISSO É O
        // PROTÓTIPO, NÃO DESVIO.** Medido por script em `design_handoff_publicador/
        // Aprovacao.dc.html`: o rodapé da legenda é `:191` e o `</section>` da tabela
        // é `:202` — ele vive **DENTRO** da seção, não como irmão dela. E
        // `TabelaDaFila.jsx` já nascera (no 187-07) com os slots `vazio` e `rodape`
        // justamente para isso, com o comentário *"Os DOIS slots do plano 187-09"*.
        //
        // ➡️ **A ordem vertical NÃO mudou** (a legenda continua embaixo das linhas);
        // o que mudou é ONDE ela é montada. Medir a posição do token `{noRodape}` no
        // nível da página passou a medir uma coisa que não existe mais — e isso é o
        // item 6 da régua: *asserção escrita quando a peça ainda não tinha dono*.
        // ⚠️ A ordem REAL é medida ONDE ELA MORA, logo abaixo, em `TabelaDaFila.jsx`
        // — estritamente mais forte que a posição de um token nesta página.
        const posicoes = ['<CabecalhoDaFila', '<FaixaDeAndamento', '{noProximo}', '{noFiltros}', '{noTabela}', 'rodape={noRodape}']
            .map((marca) => ({ marca, pos: FONTE_PAGINA.indexOf(marca) }));

        for (const { marca, pos } of posicoes) {
            assert.ok(pos !== -1, `${marca} não está na página`);
        }
        // ⚠️ `rodape={noRodape}` é PROP do `<TabelaDaFila`: ele aparece DEPOIS da
        // abertura do elemento, então a cadeia de posições vale para os CINCO
        // primeiros. O sexto é conferido na tabela.
        for (let i = 1; i < posicoes.length - 1; i += 1) {
            assert.ok(
                posicoes[i].pos > posicoes[i - 1].pos,
                `${posicoes[i].marca} vem ANTES de ${posicoes[i - 1].marca} — a ordem vertical de FILA-01 inverteu`,
            );
        }
        // ⚠️⚠️ E A ORDEM DE VERDADE, onde ela mora: dentro da `<section>` o rodapé
        // vem DEPOIS do vazio, que vem depois das linhas. Invertido, a legenda
        // apareceria ACIMA das ofertas.
        const fonteDaTabela = lerSemComentarios('resources/js/Components/Mlb/Publicador/aprovacao/TabelaDaFila.jsx');
        assert.ok(
            fonteDaTabela.indexOf('{noVazio}') < fonteDaTabela.indexOf('{noRodape}'),
            'o rodapé da tabela subiu para cima do vazio',
        );
        assert.ok(
            fonteDaTabela.indexOf('lista.map(') < fonteDaTabela.indexOf('{noRodape}'),
            'o rodapé da tabela subiu para cima das linhas — a legenda apareceria ACIMA das ofertas',
        );

        // ⚠️ E o header global vem ANTES do container do corpo (HEAD-14): é ele
        // que carrega o `-mx-6 -mt-6` que neutraliza o `p-6` do `<main>`.
        assert.ok(
            FONTE_PAGINA.indexOf('<PublicadorHeader') < FONTE_PAGINA.indexOf('mx-auto flex max-w-[1600px]'),
            'HEAD-14: o header nasceu DENTRO do container do corpo',
        );
    });

    await contexto.test('⚠️ os 4 slots passam por `isValidElement`, e são exatamente 4', () => {
        assert.equal(
            (FONTE_PAGINA.match(/isValidElement\(/g) ?? []).length,
            4,
            'os quatro slots têm de passar por `isValidElement`: nó inválido como filho faz o React LANÇAR',
        );
        for (const slot of ['proximo', 'filtros', 'tabela', 'rodape']) {
            assert.ok(FONTE_PAGINA.includes(slot), `o slot \`${slot}\` não está nomeado na página`);
        }
    });

    await contexto.test('⚠️ as props do servidor são REPASSADAS, nenhuma inventada', () => {
        // O contador, a faixa e a margem mínima vêm do SERVIDOR (ABA-05/FIN-09).
        assert.match(FONTE_PAGINA, /contador=\{contador_da_aba\}/, 'o contador da aba não vem do servidor');
        assert.match(FONTE_PAGINA, /margemMinima=\{margem_minima\}/, 'a margem mínima não vem do servidor');
        assert.match(FONTE_PAGINA, /faixa=\{faixa\}/, 'a faixa não vem do servidor');
        assert.match(FONTE_PAGINA, /cabecalho=\{cabecalho \?\? null\}/, 'HEAD-14: o `?? null` explícito saiu');
        // ⚠️ E a página NÃO soma nada: o contador é do servidor, e `reduce(` aqui
        // faria o número depender do que a tela carregou.
        assert.doesNotMatch(FONTE_PAGINA, /reduce\s*\(/, 'ABA-05: a página SOMA andamento — o contador tem de vir do servidor');
    });

    await contexto.test('⚠️ o filtro vai para a URL, e nada de `localStorage` (critério 13)', () => {
        assert.match(FONTE_PAGINA, /router\.get\(/, 'COL-03: o clique no card tem de filtrar pela URL');
        assert.match(FONTE_PAGINA, /etapa: chave/, 'o `router.get` não manda a etapa');
        assert.doesNotMatch(FONTE_PAGINA, /localStorage/, 'critério 13: o filtro vive na URL');
        assert.doesNotMatch(FONTE_PAGINA, /sessionStorage/, 'mesma regra do localStorage');
        // ⚠️⚠️ **A ESCRITA COMEÇOU NO PLANO 187-09, E ESTA RÉGUA FOI INVERTIDA EM
        // VEZ DE AFROUXADA.** O comentário original desta negativa dizia
        // textualmente *"a escrita começa no 187-09 (o lote)"* — era uma asserção
        // cuja própria mensagem nomeava o plano que a tornaria FALSA (item 6 da
        // régua). ➡️ O invariante PERMANENTE não é *"a página não escreve"*: é
        // **"a ÚNICA escrita da página é o POST do LOTE"**. Aprovar JÁ PUBLICA e não
        // tem desfazer, então um segundo caminho de escrita nascendo aqui em
        // silêncio é exatamente o que precisa doer — e a forma nova pega isso, que a
        // negativa antiga nunca ia pegar depois de o lote existir.
        assert.match(FONTE_PAGINA, /route\('mlb\.anuncios\.meus\.aprovacao\.lote'/, 'a página perdeu o POST do lote (plano 187-09)');
        assert.equal((FONTE_PAGINA.match(/router\.post\(/g) ?? []).length, 1, 'a página tem mais de UM `router.post` — a única escrita dela é o lote');
        for (const escrita of [/router\.put\(/, /router\.patch\(/, /router\.delete\(/, /axios/, /fetch\(/, /<form/]) {
            assert.doesNotMatch(FONTE_PAGINA, escrita, `a página escreve por ${escrita} — a única escrita dela é o POST do lote`);
        }
    });

    await contexto.test('⚠️ o header global NÃO é tocado, e a aba é `publicacoes`', () => {
        // ⚠️ `CabecalhoPublicadorService::ABAS` é lista FECHADA de cinco e a fila
        // é aba INTERNA da tela de Publicações (decisão do 187-02). Mandar
        // `'aprovacao'` faria o header global perder o destaque de Publicações.
        assert.match(FONTE_PAGINA, /aba="publicacoes"/, 'a página não marca a aba global certa');
        assert.ok(!FONTE_PAGINA.includes('aba="aprovacao"'), 'a página inventou uma 6ª aba no menu global');
        assert.equal((FONTE_PAGINA.match(/<PublicadorHeader\b/g) ?? []).length, 1, 'mais de um header na página');
    });

    await contexto.test('⚠️ a geometria PRÓPRIA da fila (D2), e NENHUM dos três valores da LISTA', () => {
        for (const daFila of ['max-w-[1600px]', 'min-w-[1240px]', 'gap-[16px]', 'px-[36px]', 'pt-[24px]', 'pb-[120px]', '-mx-6', '-mb-6']) {
            assert.ok(FONTE_PAGINA.includes(daFila), `a página perdeu \`${daFila}\` (\`Aprovacao.dc.html:29\`)`);
        }
        for (const daLista of ['gap-[18px]', 'pt-[28px]', 'pb-[110px]']) {
            assert.ok(
                !FONTE_PAGINA.includes(daLista),
                `\`${daLista}\` é da LISTA (\`Publicacoes v2.dc.html:29\`) — D2 proíbe harmonizar`,
            );
        }
        // ⚠️ CONTROLE: os três da LISTA estão, de verdade, na página da LISTA —
        // senão as três asserções de ausência acima seriam vazias por construção.
        const fonteDaLista = lerSemComentarios('resources/js/Pages/Mlb/MeusAnuncios.jsx');
        for (const daLista of ['gap-[18px]', 'pt-[28px]', 'pb-[110px]']) {
            assert.ok(fonteDaLista.includes(daLista), `CONTROLE: \`${daLista}\` saiu da página da LISTA — remeça a D2`);
        }
    });

    await contexto.test('⚠️ FID-03/FID-04 na página — nenhuma escala nomeada, nenhum token antigo, nenhum hex', () => {
        assert.doesNotMatch(FONTE_PAGINA, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/, 'FID-03');
        assert.doesNotMatch(FONTE_PAGINA, /\becf-(bg|card|card-2|yellow|yellow-2|line|dim|mute)\b/, 'FID-04');
        assert.doesNotMatch(FONTE_PAGINA, /\bfont-display\b/, 'FID-04');
        assert.doesNotMatch(FONTE_PAGINA, /border-white\/\[0\.08\]/, 'FID-04');
        assert.doesNotMatch(FONTE_PAGINA, /#[0-9a-fA-F]{3,8}\b/, 'TOK-15');
        assert.doesNotMatch(FONTE_PAGINA, /String\(/, 'String({toString:null}) LANÇA e derruba a página');
        assert.doesNotMatch(FONTE_PAGINA, /JSON\.stringify\(/, 'JSON.stringify MENTE: NaN vira null');
    });

    await contexto.test('⚠️⚠️ A JANELA FECHOU: a página está no manifest do Vite, lida pelo JSON', () => {
        // ⚠️ Esta é a asserção que o 187-02 declarou como pendência desta wave.
        // Uma página React FORA do manifest DESAPARECE da aplicação sem levantar
        // erro nenhum, e `resources/views/app.blade.php:38` monta
        // `@vite([... "resources/js/Pages/{$page['component']}.jsx"])` — então
        // até este arquivo existir, um GET sem `X-Inertia` nesta rota ERRAVA.
        //
        // ⚠️ Lido pelo JSON e com `Object.prototype.hasOwnProperty.call`, NUNCA
        // por grep: o hash sai com HÍFEN (`assets/app-Dv6-mbDf.js`, medido no
        // 187-01) e o grep ingênuo não casa.
        const manifestAbs = path.resolve(RAIZ, 'public/build/manifest.json');
        assert.ok(fs.existsSync(manifestAbs), 'manifest ausente — rode `npm run build`');
        const manifest = JSON.parse(fs.readFileSync(manifestAbs, 'utf8'));

        assert.ok(
            Object.prototype.hasOwnProperty.call(manifest, REL_PAGINA),
            `${REL_PAGINA} NÃO está no manifest do Vite — a página da fila SOME sem erro nenhum`,
        );
        // ⚠️ ISCA: uma entrada inexistente sai ausente, provando que a sonda não
        // responde "sim" para tudo.
        assert.ok(
            !Object.prototype.hasOwnProperty.call(manifest, 'resources/js/Pages/Mlb/NaoExiste9z7.jsx'),
            'a isca do manifest virou entrada de verdade — troque a isca',
        );
        // ⚠️ E a página da LISTA continua lá: esta wave não a tirou de circulação.
        assert.ok(
            Object.prototype.hasOwnProperty.call(manifest, 'resources/js/Pages/Mlb/MeusAnuncios.jsx'),
            'a página da LISTA saiu do manifest — a tela que está NO AR desapareceria',
        );
    });

    await contexto.test('⚠️ DIVERGÊNCIA DECLARADA: a raiz do protótipo (`:25`) não existe nesta montagem', () => {
        // O protótipo tem `min-height:100vh;min-width:1240px;background:#0A0A0A`
        // na raiz — mas lá aquele `<div>` É O DOCUMENTO. Aqui a tela vive dentro
        // do `AppLayout`, que já dá altura e cor de fundo, e `AppLayout.jsx` é
        // território PROIBIDO nesta fase.
        //
        // ⚠️ O `min-width:1240px` FOI preservado (ele está no container do corpo,
        // como nas duas irmãs). O que não entrou foi `min-h-screen`/`bg-pub-page`
        // — e o PRECEDENTE é medido aqui, para ninguém "consertar" de volta sem
        // decisão nova.
        for (const daRaiz of ['min-h-screen', 'bg-pub-page']) {
            assert.ok(!FONTE_PAGINA.includes(daRaiz), `\`${daRaiz}\` entrou na página da fila`);
            for (const irma of ['resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx', 'resources/js/Pages/Mlb/MeusAnuncios.jsx']) {
                assert.ok(
                    !lerSemComentarios(irma).includes(daRaiz),
                    `a irmã ${irma} declara \`${daRaiz}\` — o precedente medido mudou, remeça a divergência`,
                );
            }
        }
        // ⚠️ CONTROLE: as duas irmãs TÊM o par de containers que esta página copiou.
        for (const irma of ['resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx', 'resources/js/Pages/Mlb/MeusAnuncios.jsx']) {
            assert.ok(lerSemComentarios(irma).includes('-mx-6'), `a irmã ${irma} deixou de usar o \`-mx-6\``);
        }
        // ⚠️ E o protótipo REALMENTE declara os dois na raiz: sem este controle a
        // divergência poderia estar sendo afirmada sobre algo que não existe.
        assert.ok(TEXTO_PROTOTIPO.includes('min-height:100vh;min-width:1240px;background:#0A0A0A'), 'o protótipo mudou');
    });
});

test('TERR — o território do plano 187-04, por escopo de assunto', async (contexto) => {
    /** Os commits DESTE plano: range `PARTIDA..HEAD` FILTRADO pelo escopo do assunto. */
    const commitsDoPlano = () => execFileSync('git', ['log', '--no-merges', '--format=%H%x09%s', `${PARTIDA}..HEAD`], { cwd: RAIZ, encoding: 'utf8' })
        .split('\n')
        .map((linha) => linha.trim())
        .filter((linha) => linha !== '')
        .map((linha) => {
            const [sha, assunto] = linha.split('\t');
            return { sha, assunto: assunto ?? '' };
        })
        .filter((c) => c.assunto.includes(ESCOPO));

    /** Os arquivos tocados por esses commits. */
    const arquivosDoPlano = () => {
        const vistos = new Set();
        for (const c of commitsDoPlano()) {
            const saida = execFileSync('git', ['show', '--name-only', '--format=', c.sha], { cwd: RAIZ, encoding: 'utf8' });
            for (const nome of saida.split('\n').map((l) => l.trim()).filter(Boolean)) vistos.add(nome);
        }
        return [...vistos];
    };

    const COMMITS = commitsDoPlano();
    const ARQUIVOS = arquivosDoPlano();

    await contexto.test('⚠️ o gate NÃO é vazio por construção: há commit com o escopo deste plano', () => {
        // ⚠️ Sem esta âncora POSITIVA o gate inteiro passaria sobre ZERO casos —
        // a armadilha nº 4, e a razão de o range precisar de âncora antes de
        // qualquer negativa.
        assert.ok(
            COMMITS.length >= 1,
            `nenhum commit com o escopo ${ESCOPO} em ${PARTIDA}..HEAD — o gate mediria NADA`,
        );
        assert.ok(ARQUIVOS.includes(REL_CABECALHO), `o medidor não acha nem ${REL_CABECALHO} — ele está cego`);
    });

    await contexto.test('⚠️ o escopo DISCRIMINA: há commits no range que NÃO são deste plano', () => {
        // Três sessões commitam nesta árvore e o `user.name` é o MESMO nas três:
        // autor NÃO discrimina. Range aberto com a isca "ninguém mais toca aqui"
        // vale UM plano.
        const todos = execFileSync('git', ['log', '--no-merges', '--format=%H', `${PARTIDA}..HEAD`], { cwd: RAIZ, encoding: 'utf8' })
            .split('\n').map((l) => l.trim()).filter(Boolean);
        assert.ok(todos.length >= COMMITS.length, 'o filtro de escopo achou MAIS commits que o range inteiro');
        assert.ok('feat(187-04): a pagina da fila'.includes(ESCOPO), 'ISCA: a sonda de escopo não acha o escopo deste plano');
        assert.ok(!'feat(187-03): as duas abas'.includes(ESCOPO), 'ISCA: a sonda acusa a wave anterior');
        assert.ok(!'feat(197): acervo_imagens'.includes(ESCOPO), 'ISCA: a sonda acusa commit de OUTRA sessão');
    });

    await contexto.test('os arquivos SOMENTE LEITURA têm diff VAZIO', () => {
        const PROIBIDOS = [
            // Os três `.jsx` do portão (Fase 186) e a aba do 187-03.
            `${DIR_APROV}/andamentoDoPortao.jsx`,
            `${DIR_APROV}/vocabularioDoPortao.jsx`,
            `${DIR_APROV}/SeloDeAndamentoInterno.jsx`,
            REL_ABAS,
            // O corpo da LISTA e o header global, que estão NO AR.
            'resources/js/Pages/Mlb/MeusAnuncios.jsx',
            'resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx',
            'resources/js/Layouts/AppLayout.jsx',
            // Território do outro dev.
            'resources/js/Pages/Mlb/AnunciarML.jsx',
            'resources/js/Pages/Mlb/Publicador/Editor.jsx',
            'resources/js/Components/Publicador/Mesa',
            'app/Services/Creative',
            'app/Mcp',
            // Os dois gates INTOCÁVEIS.
            'tests/js/publicador-header-zindex-ordem.test.js',
            'tests/js/estrutura-meus-anuncios.test.js',
            // Dependências (T-187-SC) e migrations (esta fase não tem).
            'package.json',
            'package-lock.json',
            'composer.json',
            'composer.lock',
            'database/migrations',
        ];

        // ⚠️ `test -e` em CADA caminho: seis caminhos fantasma já "provaram"
        // território intocado nesta milestone, medindo NADA.
        for (const caminho of PROIBIDOS) {
            assert.ok(fs.existsSync(path.resolve(RAIZ, caminho)), `caminho inexistente: ${caminho} — o gate mediria NADA`);
        }

        // Prefixo de SEGMENTO, nunca `includes`: `app/Mcp` não pode casar
        // `app/McpOutraCoisa.php`.
        const tocados = ARQUIVOS.filter(
            (a) => PROIBIDOS.some((p) => a === p || a.startsWith(`${p}/`)),
        );
        assert.deepEqual(tocados, [], 'este plano saiu do território declarado');
    });
});
