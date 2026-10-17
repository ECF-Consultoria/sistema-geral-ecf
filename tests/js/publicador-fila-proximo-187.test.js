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
// Fase 187, plano 05 — A FAIXA "PRÓXIMO A REVISAR": a regra de TRÊS DEGRAUS
// chegando pronta do servidor, a geometria de `:54`-`:62` e o botão que nunca
// nasce sem destino (FILA-04, AND-05, AND-10, FID-01..04, REND-01..03).
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas, sha256 `68624883ce35547e`). A seção E1 é `:26`-`:205`.
//
// ⚠️⚠️ OS OITO PONTEIROS DO PLANO ESTAVAM DESLOCADOS EM +1, e os corretos abaixo
// foram medidos com `grep -n` nesta sessão (16/10/2026). É literalmente o modo
// de falhar que a tabela FID-01 existe para evitar:
//
//   elemento        plano   MEDIDO
//   `<sc-if>`       —       :53   (`{{ Q.hasNext }}`)
//   faixa           :53     :54   ⚠️
//   miniatura       :54     :55   ⚠️
//   coluna          :55     :56   ⚠️
//   eyebrow         :56     :57   ⚠️
//   nome            :57     :58   ⚠️
//   sub             :58     :59   ⚠️
//   estado + dot    :59     :61   ⚠️ (+2: `:60` fecha a coluna)
//   botão           :60     :62   ⚠️ (+2)
//   `nextO`/`descr`/`next`  :1222/:1223/:1224  →  :1220/:1221/:1222  ⚠️
//   `wait(m)`       —       :1054
//
// ⚠️⚠️ OMISSÃO MEDIDA NA TABELA DO PLANO: o botão declara `white-space:nowrap`
// (a PRIMEIRA propriedade de `:62`) e a classe prescrita no plano não a tem. A
// tabela de fidelidade deste projeto não erra VALOR: erra por OMISSÃO e por
// REFERÊNCIA — e aqui foi por omissão.
//
// ⚠️ REND-03: TODOS os bundles são montados AQUI NO TOPO, antes do primeiro
// `test()`. O `after()` do `node --test` dispara quando os testes registrados
// terminam e já matou um arquivo inteiro deste projeto SEM nenhum teste falhar.
//
// ⚠️ A pergunta obrigatória — *"o que precisaria estar errado no mundo para
// este gate falhar?"* — está respondida POR BLOCO. "Nada" é achado, não
// conforto.
//
// ⚠️ **ESCOPO DECLARADO:** a PÁGINA não é renderizada aqui, pelo mesmo recorte
// do 187-04: montar `Pages/Mlb/Publicador/Aprovacao.jsx` exigiria o `AppLayout`
// e o `PublicadorHeader` inteiros (que leem `usePage()`, Radix e o
// `localStorage` das contas recentes), e o que isso mediria é a casca da Fase
// 177. O comportamento adverso está provado no COMPONENTE, que é quem decide
// desenhar. ⚠️ **Nenhum executor desta fase viu esta tela renderizada:** o banco
// local não tem empresa com `ml_token` (a rota dá 404 aqui). A conferência é do
// usuário, em produção.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

// ⚠️ O commit de PARTIDA deste plano, capturado na PRIMEIRA ação do executor.
// O range SOZINHO não basta: as sessões commitam nesta árvore com o MESMO
// `user.name`, então o AUTOR não discrimina nada. O discriminador é o ESCOPO DO
// ASSUNTO, e há isca nas duas direções.
const PARTIDA = '1dceb28f';
const ESCOPO = '(187-05)';

global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});
global.window = {
    location: { search: '', href: 'http://localhost/mlb/anuncios/meus/9/aprovacao' },
    addEventListener: () => {},
    removeEventListener: () => {},
    history: { state: null, replaceState: () => {} },
};

const STUB_INERTIA = path.join(__dirname, `.pub-prox187-inertia-stub-${process.pid}.mjs`);
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
        `.pub-prox187-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';
const REL_PROXIMO = `${DIR_APROV}/ProximoARevisar.jsx`;
const REL_PAGINA = 'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx';
const REL_SERVICO = 'app/Services/Publicador/Aprovacao/Fila/ProximoARevisarService.php';
const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

// ⚠️ `test -e` ANTES de acreditar em qualquer leitura vazia. Seis caminhos
// fantasma já "provaram" território intocado nesta milestone, medindo NADA.
for (const caminho of [DIR_APROV, REL_PROXIMO, REL_PAGINA, REL_SERVICO, PROTOTIPO]) {
    if (!fs.existsSync(path.resolve(RAIZ, caminho))) {
        throw new Error(`caminho inexistente: ${caminho} — gate mediria NADA`);
    }
}

const MOD = await montar(REL_PROXIMO);
const Proximo = MOD.default;

const FONTE_PROXIMO = lerSemComentarios(REL_PROXIMO);
const FONTE_PAGINA = lerSemComentarios(REL_PAGINA);
const TEXTO_PROTOTIPO = fs.readFileSync(path.resolve(RAIZ, PROTOTIPO), 'utf8');

const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

/** O payload que `ProximoARevisarService::para()` devolve — as 7 chaves reais. */
const DO_SERVIDOR = {
    id: 42,
    nome: 'Poltrona Beny — Poltrona Beny Decorativa Veludo Cinza',
    sub: 'Clássico · Unidade · aguardando há 2 h · responsável Ana Ribeiro',
    estado: 'Pronto para aprovar',
    aprovavel: true,
    pendencias: 0,
    espera_minutos: 120,
};

const COM_PENDENCIA = {
    ...DO_SERVIDOR,
    estado: '3 pendências',
    aprovavel: false,
    pendencias: 3,
};

const HTML = render(Proximo, { proximo: DO_SERVIDOR, onRevisar: () => {} });
const HTML_PENDENTE = render(Proximo, { proximo: COM_PENDENCIA, onRevisar: () => {} });

// ─────────────────────────────────────────────────────────────────────────
// Localização por ÁRVORE com índice absoluto / por TEXTO ÚNICO.
//
// ⚠️ NUNCA por lista de classes: `html.indexOf(tagDeAbertura)` pega o IRMÃO
// ERRADO quando dois elementos compartilham a mesma string de classes — foram
// QUATRO gates do ERP medindo a linha errada nesta milestone.
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

/** Igualdade de CONJUNTO de classes — endurecimento: classe A MAIS reprova. */
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
 *  · `JSON.stringify` MENTE: `NaN` e `Infinity` viram `'null'`;
 *  · `Object.prototype.hasOwnProperty.call(obj, chave)` LANÇA com a chave
 *    `{toString:null}`, porque a busca de chave COAGE para string.
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
    '42',
    'quarenta',
    [],
    [1, 2],
    {},
    { id: 42 },
    { toString: null },
    { valueOf: null },
    true,
];

const MARCADORES = /\[object Object\]|NaN|undefined|Invalid Date/;

// As classes PRESCRITAS, uma vez só, lidas pelos gates de HTML e de CSS.
const CL_FAIXA = [
    'flex', 'items-center', 'gap-[14px]', 'rounded-pub-panel', 'border',
    'border-pub-accent/[0.35]', 'bg-pub-row-new', 'px-[12px]', 'py-[10px]',
];
const CL_MINIATURA = [
    'relative', 'h-[46px]', 'w-[46px]', 'flex-none', 'overflow-hidden',
    'rounded-pub-control', 'bg-pub-hover-3', 'text-pub-txt-off',
];
const CL_COLUNA = ['flex', 'min-w-0', 'flex-col', 'gap-[3px]'];
const CL_EYEBROW = ['text-[10.5px]', 'font-bold', 'tracking-[0.08em]', 'text-pub-accent'];
const CL_NOME = ['text-[14px]', 'font-semibold'];
const CL_SUB = ['text-[12.5px]', 'text-pub-txt-2'];
const CL_ESTADO = ['ml-auto', 'flex', 'items-center', 'gap-[7px]', 'whitespace-nowrap', 'text-[12.5px]', 'font-medium'];
const CL_DOT = ['h-[7px]', 'w-[7px]', 'rounded-full'];
const CL_BOTAO = [
    'h-[36px]', 'cursor-pointer', 'whitespace-nowrap', 'rounded-pub-control',
    'bg-pub-accent', 'px-[16px]', 'text-[13px]', 'font-semibold', 'text-pub-page',
    'hover:bg-pub-accent-hover',
];

// ═══════════════════════════════════════════════════════════════════════════
// 1. OS LITERAIS, BYTE A BYTE — e os codepoints que a paráfrase não acerta
//
// O que precisaria estar errado para falhar: o eyebrow virar "PRÓXIMA A
// REVISAR"/"A SEGUIR", o botão virar "Revisar"/"Abrir", ou o travessão da
// frase virar hífen. O `—` (U+2014) e o `·` (U+00B7) são INDISTINGUÍVEIS a
// olho dos seus vizinhos de teclado — é assim que paráfrase é pega.
// ═══════════════════════════════════════════════════════════════════════════

test('FILA-04 — os literais da faixa saem byte a byte do protótipo', async (contexto) => {
    await contexto.test('o eyebrow é "PRÓXIMO A REVISAR", exportado e na tela', () => {
        assert.equal(MOD.EYEBROW, 'PRÓXIMO A REVISAR');
        assert.ok(HTML.includes('PRÓXIMO A REVISAR'), HTML);
    });

    await contexto.test('o botão é "Revisar agora"', () => {
        assert.equal(MOD.ROTULO_BOTAO, 'Revisar agora');
        assert.ok(HTML.includes('Revisar agora'), HTML);
    });

    await contexto.test('⚠️ CONTROLE: os dois literais estão NO PROTÓTIPO (`:57` e `:62`)', () => {
        // Sem este controle os literais acima poderiam ser invenção coerente —
        // iguais no código e no teste, errados na tela.
        for (const literal of ['PRÓXIMO A REVISAR', 'Revisar agora']) {
            assert.ok(TEXTO_PROTOTIPO.includes(literal), `o protótipo não tem "${literal}" — a referência mudou`);
        }
        // E a prova de que é o protótipo CERTO: a faixa de 9 só existe nele.
        assert.ok(TEXTO_PROTOTIPO.includes('repeat(9,minmax(0,1fr))'), 'este não é o protótipo da FILA');
    });

    await contexto.test('⚠️ ISCA do literal: paráfrases plausíveis NÃO entram na tela', () => {
        // ⚠️ Cada isca é plausível E foi conferida como ausente. Sonda cujo
        // silêncio depende de UMA LETRA é sorte, não medida.
        for (const isca of [
            'PRÓXIMA A REVISAR',
            'A SEGUIR',
            'PRÓXIMO DA FILA',
            'Próxima da fila',
            'Abrir revisão',
            'Revisar fotos',
        ]) {
            assert.ok(!HTML.includes(isca), `a paráfrase "${isca}" entrou na tela`);
        }
        // ⚠️ CONTROLE: a sonda ACHA um literal que a tela TEM — senão as seis
        // asserções acima poderiam passar com o HTML vazio.
        assert.ok(HTML.includes('Revisar agora'));
    });

    await contexto.test('⚠️⚠️ os codepoints da frase do servidor chegam INTACTOS ao HTML', () => {
        // ⚠️ O travessão é U+2014 e o separador é U+00B7. Renderizar um e
        // escrever outro é o modo de falhar que nenhuma leitura pega.
        assert.ok(HTML.includes('—'), 'o travessão (U+2014) do nome não chegou ao HTML');
        assert.ok(HTML.includes('·'), 'o separador (U+00B7) da frase não chegou ao HTML');
        assert.ok(!HTML.includes(' - Poltrona'), 'o travessão virou hífen no caminho');

        // ⚠️ E o protótipo declara os dois: `:1222` (o nome) e `:1221` (a frase).
        assert.ok(TEXTO_PROTOTIPO.includes(".name + ' — ' + nextO.title"), 'o protótipo mudou o travessão do nome');
        assert.ok(TEXTO_PROTOTIPO.includes("' · aguardando há '"), 'o protótipo mudou o separador da frase');
    });

    await contexto.test('⚠️ a faixa NÃO monta frase nenhuma: ela só exibe o que o servidor mandou', () => {
        // ⚠️ `new Date()` no JSX já mordeu este projeto duas vezes (`Invalid
        // Date` e o fuso de data-only em BRT). A espera chega como RÓTULO.
        for (const proibido of [/new Date\(/, /toLocaleDateString/, /toLocaleString/, /Intl\./, /date-fns/]) {
            assert.doesNotMatch(FONTE_PROXIMO, proibido, `a faixa monta data no cliente (${proibido})`);
        }
        // ⚠️ E nenhum dos literais da frase mora no `.jsx`: eles são do SERVIÇO.
        for (const doServidor of ['aguardando há', 'Pronto para aprovar', 'pendência', 'Combo ×', 'Unidade', 'Clássico', 'Premium']) {
            assert.ok(
                !FONTE_PROXIMO.includes(doServidor),
                `"${doServidor}" foi COPIADO para o .jsx — a frase tem UMA casa (ProximoARevisarService)`,
            );
        }
        // ⚠️ CONTROLE: os sete estão, de verdade, no SERVIÇO. Sem isto as sete
        // asserções acima seriam vazias por construção.
        const fonteServico = fs.readFileSync(path.resolve(RAIZ, REL_SERVICO), 'utf8');
        for (const doServidor of ['aguardando há', 'Pronto para aprovar', 'pendência', 'Combo ×', 'Unidade']) {
            assert.ok(fonteServico.includes(doServidor), `CONTROLE: "${doServidor}" não está no serviço — a sonda está cega`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. FID-01 — A GEOMETRIA, POR IGUALDADE DE CONJUNTO DE CLASSES
//
// O que precisaria estar errado para falhar: qualquer medida trocada (a
// miniatura de 46px virar os 44px da LINHA da oferta, que é outro bloco —
// `:138` —, `12.5px` virar `text-sm`, o raio de 12px virar 9px), qualquer
// classe A MAIS (igualdade de conjunto), ou o `whitespace-nowrap` do botão
// sair e "Revisar agora" quebrar em duas linhas dentro de 36px fixos.
// ═══════════════════════════════════════════════════════════════════════════

test('FID-01 — a geometria da faixa, elemento por elemento, por ÁRVORE', async (contexto) => {
    await contexto.test('a faixa (`:54`): flex, gap 14px, raio 12px, borda amarela a 35% e fundo `row-new`', () => {
        assertClasses(tagEm(HTML, indiceDaTag(HTML, 'div', 1)), CL_FAIXA, 'faixa');
    });

    await contexto.test('a miniatura (`:55`): 46×46 com raio 9px — NÃO os 44px da linha da oferta', () => {
        const tag = tagEm(HTML, indiceDaTag(HTML, 'span', 1));
        assertClasses(tag, CL_MINIATURA, 'miniatura');
        // ⚠️ A medida da LINHA é 44px (`:138`) e este bloco é OUTRO. "Deixei
        // igual" é FID-02, justificativa VETADA.
        assert.ok(!HTML.includes('h-[44px]'), 'a miniatura da faixa virou a de 44px da linha da oferta');
        assert.ok(!HTML.includes('w-[44px]'), 'a miniatura da faixa virou a de 44px da linha da oferta');
        // ⚠️ E o protótipo declara os DOIS tamanhos, distintos: sem este
        // controle a asserção acima afirmaria uma diferença que não existe.
        assert.ok(TEXTO_PROTOTIPO.includes('width:46px;height:46px'), 'o protótipo mudou a miniatura da faixa');
        assert.ok(TEXTO_PROTOTIPO.includes('width:44px;height:44px'), 'o protótipo mudou a miniatura da LINHA');
    });

    await contexto.test('a coluna (`:56`): flex-col, gap 3px e `min-w-0`', () => {
        assertClasses(tagEm(HTML, indiceDaTag(HTML, 'div', 2)), CL_COLUNA, 'coluna');
    });

    await contexto.test('o eyebrow (`:57`): 10.5px/700, tracking 0.08em, amarelo', () => {
        assertClasses(tagDoElementoComTexto(HTML, MOD.EYEBROW, 'span'), CL_EYEBROW, 'eyebrow');
    });

    await contexto.test('o nome (`:58`): 14px/600', () => {
        assertClasses(tagDoElementoComTexto(HTML, DO_SERVIDOR.nome, 'span'), CL_NOME, 'nome');
    });

    await contexto.test('o sub (`:59`): 12.5px em `txt-2`', () => {
        assertClasses(tagDoElementoComTexto(HTML, DO_SERVIDOR.sub, 'span'), CL_SUB, 'sub');
    });

    await contexto.test('o estado (`:61`): `ml-auto`, gap 7px, 12.5px/500 e `nowrap`', () => {
        // ⚠️⚠️ POR ÍNDICE ABSOLUTO, e NÃO por `tagQueEnvolve(texto)`: o bloco do
        // estado tem o DOT como primeiro filho, então `lastIndexOf('<span')`
        // antes do texto devolve a tag do DOT — o IRMÃO ERRADO. Foi medido nesta
        // sessão, e é exatamente a armadilha nº 7 do learnings §4 (quatro gates
        // do ERP mediam a linha do Mercado Livre por isso).
        // A árvore é: 1=miniatura, 2=eyebrow, 3=nome, 4=sub, 5=estado, 6=dot.
        assertClasses(tagEm(HTML, indiceDaTag(HTML, 'span', 5)), [...CL_ESTADO, 'text-pub-ok-txt'], 'estado aprovável');
        // ⚠️ E a prova de que o 5 é o do estado: o texto dele vem DEPOIS da tag
        // e ANTES do fechamento do bloco.
        assert.ok(HTML.indexOf(DO_SERVIDOR.estado) > indiceDaTag(HTML, 'span', 5), 'o índice 5 não é o bloco do estado');
    });

    await contexto.test('o dot (`:61`): 7×7 redondo, e ele está DENTRO do bloco do estado', () => {
        // ⚠️ Por ÁRVORE com índice absoluto: 1=miniatura, 2=eyebrow, 3=nome,
        // 4=sub, 5=estado, 6=dot. Localizar pela lista de classes pegaria o
        // irmão errado se outro elemento compartilhasse as mesmas classes.
        const iEstado = indiceDaTag(HTML, 'span', 5);
        const iDot = indiceDaTag(HTML, 'span', 6);
        assert.ok(iDot > iEstado, 'o dot não está dentro do bloco do estado');
        assertClasses(tagEm(HTML, iDot), [...CL_DOT, 'bg-pub-ok-txt'], 'dot aprovável');
        assert.equal(atributo(tagEm(HTML, iDot), 'aria-hidden'), 'true', 'o dot não é decorativo para o leitor de tela');
    });

    await contexto.test('⚠️ o botão (`:62`): 36px, raio 9px, amarelo, `cursor-pointer` E `whitespace-nowrap`', () => {
        const tag = tagEm(HTML, indiceDaTag(HTML, 'button', 1));
        assertClasses(tag, CL_BOTAO, 'botão');
        assert.equal(atributo(tag, 'type'), 'button', 'o botão sem `type` submete formulário por padrão');

        // ⚠️ `cursor:pointer` foi OMITIDO onze vezes numa única fase deste
        // projeto. A asserção é própria, não só parte do conjunto.
        assert.ok(classesDa(tag).includes('cursor-pointer'), 'o `cursor-pointer` do botão (`:62`) saiu');
        // ⚠️ E o `white-space:nowrap`, que a TABELA DO PLANO omitiu.
        assert.ok(classesDa(tag).includes('whitespace-nowrap'), 'o `whitespace-nowrap` do botão (`:62`) saiu');
        assert.ok(
            TEXTO_PROTOTIPO.includes('white-space:nowrap;height:36px;padding:0 16px;border:none;border-radius:9px;background:#FACC15'),
            'o protótipo mudou o botão — remeça a omissão da tabela',
        );
    });

    await contexto.test('⚠️ `cursor:pointer` aparece UMA vez no recorte da faixa, e no BOTÃO', () => {
        // Medido por script no protótipo (`:53`-`:63`): 1 `cursor:pointer`,
        // 1 `onClick`, 1 `<button>`. A regra é uniforme no E1 — nenhum elemento
        // com `onClick` fica sem cursor —, então um clicável sem cursor na
        // implementação é divergência, nunca "o protótipo também não tem".
        const recorte = TEXTO_PROTOTIPO.split('\n').slice(52, 63).join('\n');
        assert.equal((recorte.match(/cursor:pointer/g) ?? []).length, 1, 'o recorte da faixa mudou de forma');
        assert.equal((recorte.match(/onClick=/g) ?? []).length, 1);
        assert.equal((recorte.match(/<button/g) ?? []).length, 1);
        // E na nossa faixa: 1 botão, e só ele tem `cursor-pointer`.
        assert.equal((HTML.match(/<button/g) ?? []).length, 1);
        assert.equal((HTML.match(/cursor-pointer/g) ?? []).length, 1);
    });

    await contexto.test('⚠️ FID-03/FID-04 na fonte: nenhuma escala nomeada, nenhum token antigo, nenhum hex', () => {
        assert.doesNotMatch(FONTE_PROXIMO, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/, 'FID-03');
        assert.doesNotMatch(FONTE_PROXIMO, /\becf-(bg|card|card-2|yellow|yellow-2|line|dim|mute)\b/, 'FID-04');
        assert.doesNotMatch(FONTE_PROXIMO, /\bfont-display\b/, 'FID-04');
        assert.doesNotMatch(FONTE_PROXIMO, /border-white\/\[0\.08\]/, 'FID-04');
        assert.doesNotMatch(FONTE_PROXIMO, /#[0-9a-fA-F]{3,8}\b/, 'TOK-15: hex literal na tela');
        assert.doesNotMatch(FONTE_PROXIMO, /\b(sm|md|lg|xl|2xl):/, 'FID-05: breakpoint novo');
        assert.doesNotMatch(FONTE_PROXIMO, /String\(/, 'String({toString:null}) LANÇA e derruba a página');
        assert.doesNotMatch(FONTE_PROXIMO, /JSON\.stringify\(/, 'JSON.stringify MENTE: NaN vira null');
        // ⚠️ `duration-[Nms]`/`delay-[…]`/`ease-[…]` NÃO COMPILAM neste projeto
        // (o plugin `tailwindcss-animate` os torna ambíguos): a classe fica no
        // HTML, todo teste de render passa, e a transição não acontece.
        assert.doesNotMatch(FONTE_PROXIMO, /\b(duration|delay|ease)-\[/, 'valor arbitrário de animação não compila aqui');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. O ESTADO — cor NUNCA é o único sinal
//
// O que precisaria estar errado para falhar: alguém pintar o dot e tirar o
// texto (cor como único sinal, regra do usuário), ou trocar a cor do
// aprovável pela do pendente — "Pronto para aprovar" em âmbar e "3
// pendências" em verde é pior que não ter cor.
// ═══════════════════════════════════════════════════════════════════════════

test('AND-05 — o estado diz o estado em TEXTO, e a cor acompanha', async (contexto) => {
    await contexto.test('aprovável: texto + dot em `ok-txt`', () => {
        assert.ok(HTML.includes('Pronto para aprovar'), HTML);
        // ⚠️ Índice absoluto (5=estado, 6=dot): localizar o bloco pelo TEXTO
        // devolveria a tag do dot, que é o primeiro filho dele.
        assert.ok(classesDa(tagEm(HTML, indiceDaTag(HTML, 'span', 5))).includes('text-pub-ok-txt'));
        assert.ok(classesDa(tagEm(HTML, indiceDaTag(HTML, 'span', 6))).includes('bg-pub-ok-txt'));
    });

    await contexto.test('com pendência: texto + dot em `warn-txt`', () => {
        assert.ok(HTML_PENDENTE.includes('3 pendências'), HTML_PENDENTE);
        assert.ok(classesDa(tagEm(HTML_PENDENTE, indiceDaTag(HTML_PENDENTE, 'span', 5))).includes('text-pub-warn-txt'));
        assert.ok(classesDa(tagEm(HTML_PENDENTE, indiceDaTag(HTML_PENDENTE, 'span', 6))).includes('bg-pub-warn-txt'));
        // ⚠️ E as duas cores NÃO se misturam: o aprovável não leva âmbar.
        assert.ok(!HTML.includes('text-pub-warn-txt'), 'o aprovável saiu em âmbar');
        assert.ok(!HTML_PENDENTE.includes('text-pub-ok-txt'), 'o pendente saiu em verde');
    });

    await contexto.test('⚠️ as duas cores são as de `:1222` do protótipo', () => {
        assert.ok(
            TEXTO_PROTOTIPO.includes("stateColor: nextO.inf.approvable ? '#4ADE80' : '#FBBF24'"),
            'o protótipo mudou as cores do estado — remeça o par ok-txt/warn-txt',
        );
    });

    await contexto.test('⚠️⚠️ SEM TEXTO o bloco inteiro não aparece — dot sozinho seria cor como único sinal', () => {
        for (const estado of ADVERSOS) {
            const html = render(Proximo, { proximo: { ...DO_SERVIDOR, estado }, onRevisar: () => {} });
            const temDot = html.includes('h-[7px]');
            const temTexto = typeof estado === 'string' && estado.trim() !== '';
            assert.equal(temDot, temTexto, `estado ${descreve(estado)}: dot e texto discordaram`);
            assert.doesNotMatch(html, MARCADORES, `estado ${descreve(estado)}`);
        }
        // ⚠️ CONTROLE POSITIVO: com texto de verdade o dot ESTÁ lá. Sem isto a
        // igualdade acima seria satisfeita por "nunca desenha dot".
        assert.ok(HTML.includes('h-[7px]'), 'o dot não aparece nem com estado válido — a sonda está cega');
    });

    await contexto.test('⚠️ `aprovavel` só é verdade com `true` ESTRITO', () => {
        // Dizer "Pronto para aprovar" por truthiness (`'sim'`, `1`, `{}`) seria
        // uma afirmação FALSA sobre uma oferta que ninguém conferiu.
        for (const aprovavel of ['sim', 1, {}, [], 'true', 'false', 0, null, undefined]) {
            const html = render(Proximo, { proximo: { ...COM_PENDENCIA, aprovavel }, onRevisar: () => {} });
            assert.ok(
                html.includes('text-pub-warn-txt'),
                `aprovavel ${descreve(aprovavel)}: virou aprovável por truthiness`,
            );
            assert.doesNotMatch(html, MARCADORES, `aprovavel ${descreve(aprovavel)}`);
        }
        // ⚠️ CONTROLE: com `true` ele É aprovável.
        assert.ok(HTML.includes('text-pub-ok-txt'));
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. O BOTÃO — e o destino que ele tem HOJE
//
// O que precisaria estar errado para falhar: o botão nascer sem destino
// (`href="#"`, `onClick` vazio, ou desabilitado "por enquanto" — a mesma
// dívida com outra roupa). A régua do usuário foi exercida TRÊS vezes na
// milestone anterior: peça que não leva a nada não é desenhada.
// ═══════════════════════════════════════════════════════════════════════════

test('AND-10 — "Revisar agora" nunca é desenhado sem destino', async (contexto) => {
    await contexto.test('SEM `onRevisar` função, o botão NÃO existe — e o resto da faixa CONTINUA', () => {
        for (const onRevisar of ADVERSOS) {
            const html = render(Proximo, { proximo: DO_SERVIDOR, onRevisar });
            assert.ok(!html.includes('<button'), `onRevisar ${descreve(onRevisar)}: virou botão sem destino`);
            assert.ok(!html.includes('Revisar agora'), `onRevisar ${descreve(onRevisar)}: rótulo sem botão`);
            assert.ok(!html.includes('cursor-pointer'), `onRevisar ${descreve(onRevisar)}: cursor de clique sem clique`);
            // ⚠️ E a informação MEDIDA fica: a faixa não é o botão.
            assert.ok(html.includes('PRÓXIMO A REVISAR'), `onRevisar ${descreve(onRevisar)}: a faixa inteira sumiu`);
            assert.ok(html.includes(DO_SERVIDOR.sub), `onRevisar ${descreve(onRevisar)}: a frase sumiu`);
            assert.doesNotMatch(html, MARCADORES, `onRevisar ${descreve(onRevisar)}`);
        }
        // ⚠️ CONTROLE POSITIVO: com função o botão APARECE.
        assert.ok(HTML.includes('<button'), 'o botão não aparece nem com handler — a sonda está cega');
    });

    await contexto.test('⚠️ `id` que não é inteiro NÃO vira botão (a rota precisaria inventar o destino)', () => {
        for (const id of ['42', null, undefined, NaN, 1.5, Infinity, -1, {}, []]) {
            const html = render(Proximo, { proximo: { ...DO_SERVIDOR, id }, onRevisar: () => {} });
            assert.ok(!html.includes('<button'), `id ${descreve(id)}: botão com destino inventado`);
            assert.doesNotMatch(html, MARCADORES, `id ${descreve(id)}`);
        }
        // ⚠️ CONTROLE: com inteiro o botão aparece; e o ZERO é inteiro válido
        // (não existe oferta com id 0, mas recusar por valor seria regra nova).
        assert.ok(render(Proximo, { proximo: { ...DO_SERVIDOR, id: 0 }, onRevisar: () => {} }).includes('<button'));
    });

    await contexto.test('⚠️ o clique manda o ID da oferta, nunca o nome nem o índice', () => {
        assert.match(FONTE_PROXIMO, /onRevisar\(id\)/, 'o clique não manda o id da oferta');
        assert.ok(!FONTE_PROXIMO.includes('onRevisar(nome'), 'o clique manda o NOME para a URL');
    });

    await contexto.test('⚠️⚠️ nenhum destino FALSO: nem `href="#"`, nem `onClick` vazio, nem `disabled`', () => {
        for (const falso of [/href="#"/, /href='#'/, /onClick=\{\(\) => \{\}\}/, /\bdisabled\b/, /aria-disabled/, /pointer-events-none/]) {
            assert.doesNotMatch(FONTE_PROXIMO, falso, `destino/estado FALSO na faixa (${falso})`);
        }
        assert.ok(!HTML.includes('href'), 'a faixa virou link — o protótipo usa <button> (`:62`)');
        // ⚠️ CONTROLE da sonda do `#`: ela SABE acusar.
        assert.match('<a href="#">x</a>', /href="#"/, 'a sonda do destino falso não casa nem o caso óbvio');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. A FAIXA INTEIRA DESAPARECE — nunca uma casca amarela vazia
//
// O que precisaria estar errado para falhar: alguém desenhar a borda e o
// eyebrow com o miolo vazio quando não há próxima. É D4 (nada nasce
// decorativo) e é o `<sc-if value="{{ Q.hasNext }}">` do protótipo (`:53`).
// ═══════════════════════════════════════════════════════════════════════════

test('FILA-04 — sem `proximo` a faixa INTEIRA não aparece', async (contexto) => {
    await contexto.test('`proximo` nulo/ausente/não-objeto: HTML VAZIO, zero borda, zero eyebrow', () => {
        for (const proximo of [null, undefined, 0, -1, NaN, Infinity, '', 'x', [], [1, 2], true, false]) {
            const html = render(Proximo, { proximo, onRevisar: () => {} });
            assert.equal(html, '', `proximo ${descreve(proximo)}: a faixa desenhou casca`);
        }
    });

    await contexto.test('⚠️ e sem NENHUMA prop também — o default é não desenhar', () => {
        assert.equal(render(Proximo, {}), '');
    });

    await contexto.test('⚠️ CONTROLE: com o payload do servidor ela APARECE inteira', () => {
        // Sem este controle as 13 asserções de ausência acima seriam satisfeitas
        // por um componente que NUNCA desenha nada.
        assert.ok(HTML.includes('border-pub-accent/[0.35]'), 'a borda amarela da faixa não aparece');
        assert.ok(HTML.includes('bg-pub-row-new'), 'o fundo `#14120A` da faixa não aparece');
        assert.ok(HTML.includes('PRÓXIMO A REVISAR'));
        assert.ok(HTML.includes('<button'));
    });

    await contexto.test('⚠️ o protótipo REALMENTE condiciona a faixa (`:53`)', () => {
        assert.ok(
            TEXTO_PROTOTIPO.includes('<sc-if value="{{ Q.hasNext }}"'),
            'o protótipo deixou de condicionar a faixa — remeça a regra',
        );
    });

    await contexto.test('⚠️ e a faixa NÃO escreve estado vazio: isso é do plano 187-09 (decisão 16)', () => {
        // Decisão 16 do usuário: os DOIS vazios honestos (com e sem filtro) são
        // do 187-09. Escrever "Nenhuma oferta neste filtro" sem filtro nenhum é
        // a mentira que aquela decisão proíbe — e esta faixa não é o lugar.
        for (const literal of [
            'Nenhuma oferta neste filtro',
            'ainda não há ofertas aguardando aprovação',
            'Nenhuma oferta',
            'Limpar filtros',
            'Nada por aqui',
            'Nada a revisar',
        ]) {
            assert.ok(!FONTE_PROXIMO.includes(literal), `a faixa escreveu o estado VAZIO "${literal}" — ele é do 187-09`);
        }
        // ⚠️ CONTROLE: a sonda acha o que a fonte TEM.
        assert.ok(FONTE_PROXIMO.includes('PRÓXIMO A REVISAR'), 'a sonda não acha nem o eyebrow — ela lê o arquivo errado');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. REND-02 — O RENDER ADVERSO, campo por campo e a prop INTEIRA
//
// O que precisaria estar errado para falhar: um campo do servidor chegando
// OBJETO e sendo renderizado como texto. Foi a TELA PRETA de 07/10/2026, que
// voltou QUATRO vezes na Fase 180, e só o render adverso pegou. Em particular:
// `String({toString:null})` LANÇA e `obj[chave]` com chave objeto TAMBÉM.
// ═══════════════════════════════════════════════════════════════════════════

test('REND-02 — render adverso: 6 campos × 16 valores, e a prop inteira', async (contexto) => {
    const CAMPOS = ['id', 'nome', 'sub', 'estado', 'aprovavel', 'pendencias', 'espera_minutos', 'miniatura'];

    for (const campo of CAMPOS) {
        await contexto.test(`\`${campo}\` adverso não derruba nem vaza marcador`, () => {
            for (const valor of ADVERSOS) {
                for (const handler of [() => {}, null]) {
                    const html = render(Proximo, { proximo: { ...DO_SERVIDOR, [campo]: valor }, onRevisar: handler });
                    assert.doesNotMatch(
                        html,
                        MARCADORES,
                        `${campo} = ${descreve(valor)} vazou marcador no HTML`,
                    );
                    // ⚠️ A faixa continua desenhando: campo quebrado não apaga a
                    // tela, ele apaga a LINHA daquele campo.
                    assert.ok(html.includes('PRÓXIMO A REVISAR'), `${campo} = ${descreve(valor)}: a faixa inteira sumiu`);
                }
            }
        });
    }

    await contexto.test('⚠️ chave DESCONHECIDA e chaves de protótipo (`toString`, `constructor`) não derrubam', () => {
        for (const chave of ['toString', 'constructor', 'valueOf', 'hasOwnProperty', '__proto__', 'zzz9z7']) {
            const html = render(Proximo, { proximo: { ...DO_SERVIDOR, [chave]: { toString: null } }, onRevisar: () => {} });
            assert.doesNotMatch(html, MARCADORES, `chave "${chave}"`);
            assert.ok(html.includes('PRÓXIMO A REVISAR'));
        }
    });

    await contexto.test('⚠️ a prop INTEIRA objeto comum sem nenhuma chave conhecida', () => {
        // Prop de NÓ chegando objeto comum derruba a página — e aqui `proximo`
        // é DADO, então o componente tem de aceitar objeto sem as chaves.
        const html = render(Proximo, { proximo: { qualquer: 'coisa' }, onRevisar: () => {} });
        assert.doesNotMatch(html, MARCADORES);
        assert.ok(html.includes('PRÓXIMO A REVISAR'), 'objeto sem chave conhecida apagou a faixa');
        assert.ok(!html.includes('<button'), 'objeto sem `id` desenhou botão sem destino');
    });

    await contexto.test('⚠️ o payload REAL do servidor, com `miniatura` AUSENTE (é o caso de hoje)', () => {
        // ⚠️ O servidor desta wave NÃO manda `miniatura`: a foto vem do acervo, e
        // `ImagemAssetService`/`Acervo*` são território PROIBIDO (a v28 acabou de
        // virar o acervo para o R2 em produção).
        assert.doesNotMatch(HTML, MARCADORES);
        assert.ok(!HTML.includes('<img'), 'a faixa desenhou <img> sem fonte de imagem');
        assert.ok(!HTML.includes('src='), 'a faixa desenhou `src=` sem fonte de imagem');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. A MINIATURA — o lugar vazio do protótipo, e nunca `<img src="undefined">`
//
// O que precisaria estar errado para falhar: alguém montar `<img src={...}>`
// sem guarda e pintar o ícone de imagem quebrada; ou aceitar `javascript:` /
// `data:` num `src` vindo do servidor, que é execução de conteúdo de terceiro
// na nossa página.
// ═══════════════════════════════════════════════════════════════════════════

test('IMG — a miniatura ausente desenha o lugar vazio de `:55`', async (contexto) => {
    await contexto.test('sem miniatura: o quadrado de 46px com `bg-pub-hover-3` e `text-pub-txt-off`, e ZERO <img>', () => {
        const classes = classesDa(tagEm(HTML, indiceDaTag(HTML, 'span', 1)));
        assert.ok(classes.includes('bg-pub-hover-3'), 'o fundo do lugar vazio (`#1A1A1A`) saiu');
        assert.ok(classes.includes('text-pub-txt-off'), 'a cor do lugar vazio (`#52525B`) saiu');
        assert.ok(!HTML.includes('<img'));
    });

    await contexto.test('⚠️ o lugar vazio É o protótipo (`:55`), não um placeholder inventado', () => {
        assert.ok(
            TEXTO_PROTOTIPO.includes('overflow:hidden;flex:none;background:#1A1A1A;color:#52525B'),
            'o protótipo mudou o quadrado da miniatura',
        );
    });

    await contexto.test('com `https://` a foto é desenhada, dentro do MESMO quadrado', () => {
        const html = render(Proximo, {
            proximo: { ...DO_SERVIDOR, miniatura: 'https://http2.mlstatic.com/D_NQ_NP_9z7-O.jpg' },
            onRevisar: () => {},
        });
        const img = tagEm(html, indiceDaTag(html, 'img', 1));
        assert.equal(atributo(img, 'src'), 'https://http2.mlstatic.com/D_NQ_NP_9z7-O.jpg');
        assert.equal(atributo(img, 'alt'), '', 'a foto é decorativa: o nome do produto vem escrito ao lado');
        assert.equal(atributo(img, 'loading'), 'lazy');
        assertClasses(img, ['h-full', 'w-full', 'object-cover'], 'imagem');
        // ⚠️ E o quadrado de 46px continua sendo o pai: a foto não vaza.
        assert.ok(classesDa(tagEm(html, indiceDaTag(html, 'span', 1))).includes('overflow-hidden'));
    });

    await contexto.test('⚠️⚠️ endereço que não é `https://` NÃO desenha <img> — nem `javascript:`, nem `data:`', () => {
        for (const miniatura of [
            'javascript:alert(1)',
            'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=',
            'http://insegura.example/foto.jpg',
            '//http2.mlstatic.com/foto.jpg',
            '/storage/foto.jpg',
            'foto.jpg',
            ' https://com-espaco.example/f.jpg',
            '',
            null,
            undefined,
            42,
            {},
            [],
            { toString: null },
        ]) {
            const html = render(Proximo, { proximo: { ...DO_SERVIDOR, miniatura }, onRevisar: () => {} });
            assert.ok(!html.includes('<img'), `miniatura ${descreve(miniatura)}: desenhou <img>`);
            assert.ok(!html.includes('src='), `miniatura ${descreve(miniatura)}: vazou um src`);
            assert.doesNotMatch(html, MARCADORES, `miniatura ${descreve(miniatura)}`);
            // ⚠️ E o lugar vazio continua: a miniatura quebrada não apaga o bloco.
            assert.ok(html.includes('h-[46px]'), `miniatura ${descreve(miniatura)}: o quadrado sumiu`);
        }
        // ⚠️ CONTROLE POSITIVO: o `https://` legítimo PASSA. Sem ele, as 14
        // asserções acima seriam satisfeitas por "nunca desenha <img>".
        assert.ok(
            render(Proximo, { proximo: { ...DO_SERVIDOR, miniatura: 'https://x.example/f.jpg' }, onRevisar: () => {} }).includes('<img'),
        );
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. A PÁGINA — o slot preenchido, e o destino REAL na URL
//
// ⚠️ ESCOPO: aqui a prova é sobre a FONTE da página (ver o docblock do topo).
//
// O que precisaria estar errado para falhar: o slot `proximo` ficar vazio (a
// faixa nunca apareceria, com todo o serviço pronto), o contrato de NÓ do
// 187-04 ser quebrado (os 4 `isValidElement`), a ordem vertical de FILA-01
// inverter, ou o destino do botão sair da URL para um `#`.
// ═══════════════════════════════════════════════════════════════════════════

test('FILA-01 — a página preenche o slot `proximo` e dá ao botão um destino REAL', async (contexto) => {
    await contexto.test('a página IMPORTA a faixa desta wave, por caminho', () => {
        assert.match(
            FONTE_PAGINA,
            /import ProximoARevisar from '@\/Components\/Mlb\/Publicador\/aprovacao\/ProximoARevisar'/,
            'a página não importa a faixa — o slot continuaria vazio',
        );
        assert.match(FONTE_PAGINA, /<ProximoARevisar\b/, 'a faixa é importada e não é montada');
        assert.equal((FONTE_PAGINA.match(/<ProximoARevisar\b/g) ?? []).length, 1, 'duas faixas na mesma página');
    });

    await contexto.test('⚠️ o CONTRATO DE NÓ do 187-04 fica INTACTO: exatamente 4 `isValidElement`', () => {
        // ⚠️ Este número é cobrado por DOIS gates do plano 187-04 (um `.js` e um
        // PHP). Trocar o slot de NÓ por dado derrubaria para 3 e reprovaria
        // trabalho CORRETO daquela wave — por isso o dado entrou em prop PRÓPRIA
        // (`proximo_a_revisar`) e o nó continua sendo o `proximo`.
        assert.equal(
            (FONTE_PAGINA.match(/isValidElement\(/g) ?? []).length,
            4,
            'os quatro slots de NÓ do 187-04 têm de continuar passando por `isValidElement`',
        );
        for (const slot of ['proximo', 'filtros', 'tabela', 'rodape']) {
            assert.ok(FONTE_PAGINA.includes(slot), `o slot \`${slot}\` saiu da página`);
        }
        // ⚠️ As duas props têm UM significado cada: nó × dado.
        assert.match(FONTE_PAGINA, /proximo_a_revisar = null/, 'a prop de DADO do servidor não existe');
        assert.match(FONTE_PAGINA, /proximo=\{proximo_a_revisar\}/, 'o dado do servidor não chega à faixa');
    });

    await contexto.test('⚠️ a ORDEM VERTICAL de FILA-01 continua, medida por POSIÇÃO na fonte', () => {
        // ⚠️⚠️ **O `{noRodape}` SAIU DO NÍVEL DA PÁGINA NO PLANO 187-09, E ISSO É O
        // PROTÓTIPO.** Medido: o rodapé da legenda é `:191` e o `</section>` da
        // tabela é `:202` em `design_handoff_publicador/Aprovacao.dc.html` — ele
        // vive **DENTRO** da seção. `TabelaDaFila.jsx` já nascera (187-07) com os
        // slots `vazio` e `rodape` para isso. A ordem vertical **não mudou**; o que
        // mudou é onde a peça é montada, e a ordem real é medida na TABELA.
        const posicoes = ['<CabecalhoDaFila', '<FaixaDeAndamento', '{noProximo}', '{noFiltros}', '{noTabela}', 'rodape={noRodape}']
            .map((marca) => ({ marca, pos: FONTE_PAGINA.indexOf(marca) }));
        for (const { marca, pos } of posicoes) {
            assert.ok(pos !== -1, `${marca} não está na página`);
        }
        // ⚠️ O sexto é PROP do `<TabelaDaFila`: a cadeia vale para os cinco
        // primeiros, e o rodapé é conferido onde ele mora.
        for (let i = 1; i < posicoes.length - 1; i += 1) {
            assert.ok(
                posicoes[i].pos > posicoes[i - 1].pos,
                `${posicoes[i].marca} vem ANTES de ${posicoes[i - 1].marca} — a ordem vertical de FILA-01 inverteu`,
            );
        }
        const fonteDaTabela = lerSemComentarios('resources/js/Components/Mlb/Publicador/aprovacao/TabelaDaFila.jsx');
        assert.ok(
            fonteDaTabela.indexOf('lista.map(') < fonteDaTabela.indexOf('{noRodape}'),
            'o rodapé da tabela subiu para cima das linhas — a legenda apareceria ACIMA das ofertas',
        );
    });

    await contexto.test('⚠️⚠️ O DESTINO DE HOJE: a própria fila com `?oferta=<id>` (COL-03)', () => {
        // ⚠️ O painel de revisão é a Fase 188 e a detalhada a 190/191: nenhuma
        // existe. O destino é uma rota que EXISTE e responde 200 — e é a PÁGINA
        // que o conhece, não o componente.
        assert.match(FONTE_PAGINA, /function irParaOferta\(/, 'a página não tem o destino do "Revisar agora"');
        assert.match(FONTE_PAGINA, /route\('mlb\.anuncios\.meus\.aprovacao'/, 'o destino não usa a rota nomeada da fila');
        assert.match(FONTE_PAGINA, /oferta: ofertaId/, 'a oferta não vai para a URL');
        // ⚠️ E a etapa viaja junto: o clique não apaga o filtro em que a pessoa está.
        assert.match(FONTE_PAGINA, /etapa: etapaAtiva, oferta: ofertaId/, 'o clique apaga o filtro atual');
        assert.match(FONTE_PAGINA, /onRevisar=\{irParaOferta\(empresaId, etapaAtiva\)\}/, 'o handler não chega à faixa');
    });

    await contexto.test('⚠️⚠️ o destino está no caminho VIVO da função, não em código morto', () => {
        // ⚠️⚠️ ESTE SUB-TESTE NASCEU DE UMA MUTAÇÃO. As asserções de fonte acima
        // provam que `oferta: ofertaId` EXISTE no arquivo, e não que ela é
        // ALCANÇADA. Medido em 16/10/2026: inserir `return () => {};` ANTES do
        // handler deixa o `router.get` inteiro em código MORTO — o botão é
        // desenhado, o clique não faz nada, e todas as asserções de fonte
        // continuam verdes. É botão decorativo passando por gate de literal, e é
        // exatamente o que a régua do usuário proíbe.
        //
        // ⚠️ O recorte é o CORPO da função, e a prova é ESTRUTURAL: ele tem
        // EXATAMENTE dois `return` (o `null` sem empresa e o handler), e o
        // handler é a ÚLTIMA coisa do corpo — então não há nada depois dele, nem
        // nada antes dele devolvendo outra coisa.
        const i = FONTE_PAGINA.indexOf('function irParaOferta(');
        assert.ok(i !== -1, 'a função do destino não está na página');
        const fim = FONTE_PAGINA.indexOf('\n}', i);
        assert.ok(fim > i, 'não achei o fim da função do destino');
        const corpo = FONTE_PAGINA.slice(i, fim);

        const RETORNOS = /\breturn\b/g;
        const HANDLER_VIVO = /return \(ofertaId\) => \{[\s\S]*router\.get\([\s\S]*oferta: ofertaId[\s\S]*\};\s*$/;

        assert.equal(
            (corpo.match(RETORNOS) ?? []).length,
            2,
            `\`irParaOferta\` tem de ter exatamente 2 \`return\` (o null e o handler). Corpo:\n${corpo}`,
        );
        assert.match(
            corpo,
            HANDLER_VIVO,
            'o handler que navega não é a última coisa do corpo — há código depois dele, ou ele é inalcançável',
        );
        assert.doesNotMatch(corpo, /return \(\) => \{\s*\}/, 'a função devolve um handler VAZIO');

        // ⚠️⚠️ AS DUAS ISCAS, porque o código morto tem DUAS FORMAS e cada uma é
        // pega por uma asserção DIFERENTE — medido ao escrever este gate:
        //
        //   FORMA A — trocar o handler por um vazio e rebaixar o original a
        //   `const`. O número de `return` continua **2** (o `return` do original
        //   foi consumido pela troca), então a contagem NÃO pega; quem pega é a
        //   regex estrutural, porque `return (ofertaId) =>` desapareceu.
        //
        //   FORMA B — INSERIR um `return () => {};` antes, deixando o handler
        //   intacto e inalcançável. A regex estrutural CONTINUA casando (o
        //   handler segue sendo a última coisa do corpo), então ela não pega;
        //   quem pega é a contagem, que vai a **3**.
        //
        // ⚠️ Era este o buraco: cada asserção sozinha deixaria passar uma das
        // duas formas. As duas juntas fecham as duas.
        const mortoA = corpo.replace(
            'return (ofertaId) => {',
            'return () => {};\n    const morto = (ofertaId) => {',
        );
        assert.notEqual(mortoA, corpo, 'ISCA A: a montagem do caso morto não mudou nada');
        assert.equal((mortoA.match(RETORNOS) ?? []).length, 2, 'ISCA A: a forma A deixa 2 `return`, por construção');
        assert.doesNotMatch(mortoA, HANDLER_VIVO, 'ISCA A: a regex estrutural aceita o handler SUBSTITUÍDO');

        const mortoB = corpo.replace(
            'return (ofertaId) => {',
            'return () => {};\n    return (ofertaId) => {',
        );
        assert.equal((mortoB.match(RETORNOS) ?? []).length, 3, 'ISCA B: a forma B acrescenta um `return`');
        assert.match(mortoB, HANDLER_VIVO, 'ISCA B: a regex estrutural NÃO pega a forma B — é a contagem que pega');
    });

    await contexto.test('⚠️ sem `empresaId` NÃO há destino: o handler é `null` e o botão não é desenhado', () => {
        assert.match(FONTE_PAGINA, /if \(empresaId === null\) return null;/, 'a página monta rota sem empresa');
    });

    await contexto.test('⚠️ nenhum destino FALSO na página, e nada de `localStorage`', () => {
        for (const falso of [/href="#"/, /onClick=\{\(\) => \{\}\}/, /localStorage/, /sessionStorage/]) {
            assert.doesNotMatch(FONTE_PAGINA, falso, `destino falso ou filtro no navegador (${falso})`);
        }
        // ⚠️⚠️ **A ESCRITA COMEÇOU NO 187-09, E A RÉGUA FOI INVERTIDA EM VEZ DE
        // AFROUXADA** (item 6 da régua: o comentário original já nomeava o plano que
        // a tornaria falsa). O invariante permanente é **"a ÚNICA escrita da página
        // é o POST do LOTE"** — aprovar JÁ PUBLICA e não tem desfazer, então um
        // segundo caminho de escrita nascendo aqui em silêncio é o que precisa doer.
        assert.equal((FONTE_PAGINA.match(/router\.post\(/g) ?? []).length, 1, 'a página tem mais de UM `router.post` — a única escrita dela é o lote');
        for (const escrita of [/router\.put\(/, /router\.patch\(/, /router\.delete\(/, /axios/, /fetch\(/, /<form/]) {
            assert.doesNotMatch(FONTE_PAGINA, escrita, `a página escreve por ${escrita} — a única escrita dela é o POST do lote`);
        }
    });

    await contexto.test('⚠️ a prop do servidor existe no CONTROLLER, com o mesmo nome', () => {
        // ⚠️ Nome divergente entre PHP e JSX deixaria a faixa VAZIA para sempre,
        // sem erro nenhum — a página leria `undefined` e não desenharia.
        const controller = fs.readFileSync(
            path.resolve(RAIZ, 'app/Http/Controllers/MlbPublicadorAprovacaoController.php'),
            'utf8',
        );
        assert.match(controller, /'proximo_a_revisar' => \$proximo->para\(\$base\)/, 'o controller não manda a faixa');
        assert.match(controller, /ProximoARevisarService \$proximo/, 'o serviço não é injetado na action');
        // ⚠️ E ela é calculada sobre a `$base` (todos os filtros MENOS a etapa),
        // igual à faixa de 9: filtrar um recorte não apaga a próxima da fila.
        assert.ok(
            controller.indexOf("'faixa' => $fila->faixa($base)") !== -1,
            'CONTROLE: a faixa de 9 deixou de ler a $base — remeça o invariante de FILA-03',
        );
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 9. O TERRITÓRIO, POR ESCOPO DE ASSUNTO
//
// ⚠️ Range aberto com a isca "ninguém mais toca aqui" vale UM plano, e o AUTOR
// não discrimina nada nesta árvore (o `user.name` é o mesmo nas sessões).
// ═══════════════════════════════════════════════════════════════════════════

test('TERR — o território do plano 187-05, por escopo de assunto', async (contexto) => {
    const commitsDoPlano = () => execFileSync('git', ['log', '--no-merges', '--format=%H%x09%s', `${PARTIDA}..HEAD`], { cwd: RAIZ, encoding: 'utf8' })
        .split('\n')
        .map((linha) => linha.trim())
        .filter((linha) => linha !== '')
        .map((linha) => {
            const [sha, assunto] = linha.split('\t');
            return { sha, assunto: assunto ?? '' };
        })
        .filter((c) => c.assunto.includes(ESCOPO));

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
        assert.ok(
            COMMITS.length >= 1,
            `nenhum commit com o escopo ${ESCOPO} em ${PARTIDA}..HEAD — o gate mediria NADA`,
        );
        assert.ok(ARQUIVOS.includes(REL_SERVICO), `o medidor não acha nem ${REL_SERVICO} — ele está cego`);
    });

    await contexto.test('⚠️ o escopo DISCRIMINA: há commits no range que NÃO são deste plano', () => {
        const todos = execFileSync('git', ['log', '--no-merges', '--format=%H', `${PARTIDA}..HEAD`], { cwd: RAIZ, encoding: 'utf8' })
            .split('\n').map((l) => l.trim()).filter(Boolean);
        assert.ok(todos.length >= COMMITS.length, 'o filtro de escopo achou MAIS commits que o range inteiro');
        assert.ok('feat(187-05): a faixa PROXIMO A REVISAR'.includes(ESCOPO), 'ISCA: a sonda não acha o escopo deste plano');
        assert.ok(!'feat(187-04): a pagina da fila'.includes(ESCOPO), 'ISCA: a sonda acusa a wave anterior');
        assert.ok(!'feat(200): acervo no R2'.includes(ESCOPO), 'ISCA: a sonda acusa commit de OUTRA sessão');
    });

    await contexto.test('os arquivos SOMENTE LEITURA têm diff VAZIO', () => {
        const PROIBIDOS = [
            // Os cinco `.jsx` de `aprovacao/` que já estão no ar (Fases 186 e 187-04).
            `${DIR_APROV}/andamentoDoPortao.jsx`,
            `${DIR_APROV}/vocabularioDoPortao.jsx`,
            `${DIR_APROV}/SeloDeAndamentoInterno.jsx`,
            `${DIR_APROV}/CabecalhoDaFila.jsx`,
            `${DIR_APROV}/FaixaDeAndamento.jsx`,
            'resources/js/Components/Mlb/Publicador/publicacoes/AbasDaPagina.jsx',
            // O corpo da LISTA e o header global, que estão NO AR.
            'resources/js/Pages/Mlb/MeusAnuncios.jsx',
            'resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx',
            'resources/js/Layouts/AppLayout.jsx',
            // Território do outro dev, e o acervo que a v28 virou para o R2.
            'resources/js/Pages/Mlb/AnunciarML.jsx',
            'resources/js/Pages/Mlb/Publicador/Editor.jsx',
            'resources/js/Components/Publicador/Mesa',
            'app/Services/Creative',
            'app/Services/Publicador/Criativos',
            'app/Services/Publicador/ImagemAssetService.php',
            'app/Mcp',
            // Os serviços da Fase 186 e o da wave 2.
            'app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php',
            'app/Services/Publicador/Aprovacao/Fila/FilaDeAprovacaoService.php',
            // Os gates INTOCÁVEIS e os de outras waves.
            'tests/js/publicador-header-zindex-ordem.test.js',
            'tests/js/estrutura-meus-anuncios.test.js',
            'tests/js/publicador-fila-cabecalho-187.test.js',
            'tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php',
            // Dependências (T-187-SC) e migrations (esta fase NÃO tem).
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

        // ⚠️ ISCA: a comparação de prefixo SABE casar, e NÃO casa o irmão.
        assert.ok('app/Mcp/X.php'.startsWith('app/Mcp/'));
        assert.ok(!'app/McpOutraCoisa.php'.startsWith('app/Mcp/'));
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 10. O CSS CONSTRUÍDO — a classe EXISTE com o valor certo, não só "está escrita"
//
// O que precisaria estar errado para falhar: a classe sair do `content` do
// Tailwind (num `.js`, num mapa, numa string montada), ou o valor arbitrário
// não compilar — `duration-[Nms]` é o precedente MEDIDO neste projeto.
//
// As OITO normalizações do medidor estão aplicadas: seletor ESCAPADO com
// escape GERAL (a 8ª, achada na wave 4: sem ela, classe com parêntese/vírgula
// sai AUSENTE estando presente), token sólido em `rgb()` DECIMAL, e a
// opacidade em `border-color` saindo como hex de 8 DÍGITOS.
// ═══════════════════════════════════════════════════════════════════════════

/** Escapa TODOS os metacaracteres de regex. */
function escapaRegex(texto) {
    return texto.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * O seletor que o Tailwind emite: `.` + o nome com os metacaracteres de CSS
 * escapados.
 *
 * ⚠️ A REGRA É GERAL — *escapa tudo que não é `[A-Za-z0-9_-]`*. A forma herdada
 * das waves 1-3 escapava só `[].:/%`, e com ela
 * `grid-cols-[repeat(9,minmax(0,1fr))]` saía **AUSENTE** no CSS construído,
 * embora a classe esteja lá e compilada (medido na wave 4). ⚠️ Vale para
 * qualquer classe com parêntese ou vírgula — e nesta faixa é o caso de
 * `border-pub-accent/[0.35]`.
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
const FONTES_DO_CSS = [REL_PROXIMO, REL_PAGINA];

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

test('FID — as classes da faixa GERAM CSS com o valor certo', async (contexto) => {
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
        for (const isca of ['bg-pub-isca-187-05', 'text-[99.5px]', 'gap-[997px]', 'h-[461px]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS.css.includes(isca), `a string da isca ${isca} aparece no CSS`);
        }
        assert.notEqual(regraDe('text-[12.5px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('⚠️⚠️ a 8ª normalização: a classe com PARÊNTESE e VÍRGULA é achada', () => {
        // `border-pub-accent/[0.35]` tem `/`, `[`, `]` e `.`; e o controle do
        // parêntese+vírgula é o grid de 9 da faixa de andamento, que existe no
        // mesmo CSS. Com o escape antigo (só `[].:/%`) ele saía AUSENTE.
        assert.notEqual(
            regraDe('grid-cols-[repeat(9,minmax(0,1fr))]'),
            null,
            'CONTROLE da 8ª normalização: o escape do seletor voltou a ser parcial',
        );
    });

    await contexto.test('a FAIXA: gap 14px, raio 12px, padding 10/12 e os dois tons', () => {
        assert.match(regraDe('gap-[14px]'), /gap:\s*14px/);
        assert.match(regraDe('rounded-pub-panel'), /border-radius:\s*12px/);
        assert.match(regraDe('px-[12px]'), /padding-left:\s*12px/);
        assert.match(regraDe('py-[10px]'), /padding-top:\s*10px/);
        // ⚠️ Token SÓLIDO não compila para hex: sai `rgb(20 18 10 / …)`, DECIMAL.
        assert.match(regraDe('bg-pub-row-new'), /rgb\(\s*20\s+18\s+10\b/, '`#14120A` em decimal');
        // ⚠️ A opacidade em `border-color` sai como hex de 8 DÍGITOS, e o byte do
        // alfa é o que PROVA os 35% (`0.35 * 255 = 89 = 0x59`).
        const borda = regraDe('border-pub-accent/[0.35]');
        assert.notEqual(borda, null, '`border-pub-accent/[0.35]` não compilou');
        assert.match(borda, /border-color:\s*#facc1559/i, `o alfa de 35% não bate: ${borda}`);
    });

    await contexto.test('a MINIATURA: 46px e raio 9px, com os dois tons', () => {
        assert.match(regraDe('h-[46px]'), /height:\s*46px/);
        assert.match(regraDe('w-[46px]'), /width:\s*46px/);
        assert.match(regraDe('rounded-pub-control'), /border-radius:\s*9px/);
        assert.match(regraDe('bg-pub-hover-3'), /rgb\(\s*26\s+26\s+26\b/, '`#1A1A1A` em decimal');
        assert.match(regraDe('text-pub-txt-off'), /rgb\(\s*82\s+82\s+91\b/, '`#52525B` em decimal');
        // ⚠️ E 44px NÃO é a medida desta faixa — mas a classe EXISTE no CSS (a
        // linha da oferta a usa noutra tela), então a prova de que não é nossa é
        // o gate de HTML, não este. (Limitação estrutural do medidor de CSS.)
        assert.notEqual(regraDe('h-[44px]'), null, 'CONTROLE: `h-[44px]` existe no CSS — a prova do 46 é por HTML');
    });

    await contexto.test('o EYEBROW: 10.5px, 700, tracking 0.08em e o amarelo', () => {
        assert.match(regraDe('text-[10.5px]'), /font-size:\s*10\.5px/);
        assert.match(regraDe('font-bold'), /font-weight:\s*700/);
        assert.match(regraDe('tracking-[0.08em]'), /letter-spacing:\s*\.?0?8em/);
        assert.match(regraDe('text-pub-accent'), /rgb\(\s*250\s+204\s+21\b/, '`#FACC15` em decimal');
    });

    await contexto.test('o NOME e o SUB: 14px/600 e 12.5px em `txt-2`', () => {
        assert.match(regraDe('text-[14px]'), /font-size:\s*14px/);
        assert.match(regraDe('font-semibold'), /font-weight:\s*600/);
        assert.match(regraDe('text-pub-txt-2'), /rgb\(\s*161\s+161\s+170\b/, '`#A1A1AA` em decimal');
    });

    await contexto.test('o ESTADO e o DOT: gap 7px, 500, 7×7 redondo e as duas cores', () => {
        assert.match(regraDe('gap-[7px]'), /gap:\s*7px/);
        assert.match(regraDe('font-medium'), /font-weight:\s*500/);
        assert.match(regraDe('h-[7px]'), /height:\s*7px/);
        assert.match(regraDe('w-[7px]'), /width:\s*7px/);
        assert.match(regraDe('rounded-full'), /border-radius:\s*9999px/);
        assert.match(regraDe('text-pub-ok-txt'), /rgb\(\s*74\s+222\s+128\b/, '`#4ADE80` em decimal');
        assert.match(regraDe('text-pub-warn-txt'), /rgb\(\s*251\s+191\s+36\b/, '`#FBBF24` em decimal');
        assert.match(regraDe('bg-pub-ok-txt'), /rgb\(\s*74\s+222\s+128\b/);
        assert.match(regraDe('bg-pub-warn-txt'), /rgb\(\s*251\s+191\s+36\b/);
    });

    await contexto.test('o BOTÃO: 36px, 13px, amarelo sobre `page`, e o HOVER com o prefixo no seletor', () => {
        assert.match(regraDe('h-[36px]'), /height:\s*36px/);
        assert.match(regraDe('px-[16px]'), /padding-left:\s*16px/);
        assert.match(regraDe('text-[13px]'), /font-size:\s*13px/);
        assert.match(regraDe('bg-pub-accent'), /rgb\(\s*250\s+204\s+21\b/);
        assert.match(regraDe('text-pub-page'), /rgb\(\s*10\s+10\s+10\b/, '`#0A0A0A` em decimal');
        assert.match(regraDe('cursor-pointer'), /cursor:\s*pointer/);
        assert.match(regraDe('whitespace-nowrap'), /white-space:\s*nowrap/);
        // ⚠️ Na variante, o PREFIXO faz parte do nome da classe:
        // `.hover\:bg-pub-accent-hover:hover`.
        const hover = regraDe('hover:bg-pub-accent-hover');
        assert.notEqual(hover, null, 'o `hover:bg-pub-accent-hover` não compilou');
        assert.match(hover, /rgb\(\s*234\s+179\s+8\b/, '`#EAB308` em decimal');
    });
});
