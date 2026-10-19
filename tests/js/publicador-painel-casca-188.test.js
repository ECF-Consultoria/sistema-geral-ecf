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
// Fase 188, plano 07 — A CASCA DO PAINEL DE 1140px: PORTAL, TOPO, TECLADO E AS
// DUAS COLUNAS DE MESMO PESO.
//
// ⚠️⚠️ **DE CADA BLOCO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR: "o que
// precisaria estar errado no mundo para ele falhar?"** — "Nada" é ACHADO.
//
//  1. PORTAL      → alguém tirar o `createPortal` e o painel voltar a ser
//                   descendente do header, que tem `backdrop-filter` e **cria
//                   containing block para `position:fixed`**. ⚠️⚠️ E o inverso,
//                   que é o furo PERIGOSO: o renderizador de SERVIDOR do React
//                   **não renderiza portal**, então um gate que renderizasse o
//                   COMPOSTO veria HTML vazio e passaria **POR OMISSÃO**, verde,
//                   provando nada. ➡️ Aqui o composto é renderizado e tem de sair
//                   **VAZIO** (é a prova de que o portal existe), e a geometria é
//                   medida nas **named exports**, direto.
//  2. GUARDA      → tirar `typeof document === 'undefined'` e o render de
//                   servidor passa a **LANÇAR** (`document is not defined`).
//  3. `font-pub`  → tirá-lo da RAIZ do portado e o painel inteiro perde a Geist
//                   **em silêncio** (REND-05, medido na Fase 178): conteúdo
//                   portado volta a herdar do `document.body`, que é Inter.
//  4. Z-INDEX     → copiar os números do protótipo (69/70, que existem porque lá
//                   o header é 50) e cobrir o menu lateral — o bug que o usuário
//                   relatou em 12/10/2026. ⚠️ E o gate errado aqui é o de LISTA
//                   LARGA de números: a 187-09 mediu que **`45` é o número do
//                   POPOVER** e o toast legitimamente ficou em `z-[45]`. ➡️ A
//                   sonda é de **ORDEM**, com isca que distingue **inversão** de
//                   simples **troca de número**.
//  5. TOPO        → o quinto elemento ("Abrir revisão detalhada ↗") nascer nesta
//                   fase. **PERGUNTA-188-B3**, respondida pelo usuário em
//                   18/10/2026: ele é da **Fase 190/191**. ⚠️ `onClick` vazio e
//                   `disabled` decorativo são a mesma dívida com outra roupa, e
//                   "desabilitado com explicação" foi OFERECIDO e RECUSADO.
//  6. `title`     → `title=""` num dos três botões de GLIFO puro: elemento sem
//                   nome acessível **passa em render e desaparece na tela**.
//  7. COLUNAS     → `minmax(0,1fr) minmax(0,2fr)` ou `grid-cols-2`. As duas
//                   colunas têm o MESMO PESO por **decisão de desenho**
//                   (README E2, REVS-06: *"Não reequilibrar"*), e *"caberia
//                   melhor"* é **FID-02 literal**.
//  8. POSIÇÃO     → "0 de N na fila" voltar: a tela afirmando uma posição que não
//                   existe. Com `pos = null` o bloco **não** é desenhado.
//  9. VERSÃO      → `versao_esperada` sair de ALGUMA escrita, ou o 422 de versão
//                   divergente ser **engolido** — que é "sobrescreve em silêncio"
//                   com outra roupa, e pior: deixa a pessoa achando que aprovou,
//                   num fluxo em que **aprovar JÁ PUBLICA e não há desfazer**.
// 10. ADVERSO     → qualquer campo chegando objeto/`NaN`/array/nulo: a TELA PRETA
//                   voltou SEIS vezes neste projeto, e uma delas foi **NO
//                   CLIQUE** (187-06) — `renderToStaticMarkup` **não dispara
//                   `onClick`**, então os handlers são exercidos direto.
// 11. CSS         → classe que **não compila**. `duration-[Nms]` é o precedente
//                   MEDIDO deste projeto, e é a ISCA deste bloco.
// 12. TERRITÓRIO  → `localStorage`, "Restaurar dados de exemplo", `String(`,
//                   peça nova em `.js`, as Fases 189/192 antecipadas, e o
//                   `publicador-header-zindex-ordem.test.js` ou o
//                   `PublicadorHeader.jsx` tocados.
//
// ⚠️ Todos os nomes de teste terminam em `188_07`: nome genérico COLIDE no
// `--filter` do harness, que **SOMA** as contagens.
// ⚠️ `design_handoff_publicador/` **NÃO é versionado**: o bloco que depende dele
// degrada para `skip` EXPLÍCITO e NOMEADO, nunca passa calado.
// ⚠️⚠️ **TODOS os bundles são montados ANTES do primeiro `test()`** — o `after()`
// do `node --test` já matou um arquivo inteiro **sem nenhum teste falhar** (37
// testes em vez de 94).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

// ⚠️ O nome do stub carrega o PID: a árvore é compartilhada com outras sessões, e
// um stub de nome fixo seria apagado pelo `after()` da sessão vizinha no meio da
// execução desta.
const STUB_INERTIA = path.join(__dirname, `.pub-casca188-inertia-stub-${process.pid}.mjs`);

// ⚠️⚠️ O `router` do stub **GRAVA** as chamadas: é assim que o gate prova que
// `versao_esperada` entra em TODAS as escritas, e que o 422 de versão divergente
// chama `onConflito` em vez de ser engolido.
//
// ⚠️⚠️ **O GRAVADOR VIVE NO `globalThis`, E ISSO É CONSERTO MEDIDO nesta sessão.**
// O `montar()` usa `bundle: true`, então o esbuild **INLINA o stub dentro do
// bundle** — o `CHAMADAS` de dentro do bundle e o de um `import()` do próprio
// arquivo de stub são **DOIS objetos DIFERENTES**. ➡️ Com um `export const` o
// gate lia um array sempre VAZIO, e a asserção `length === 1` reprovava por um
// motivo que **não é o código**. O `globalThis` é a única referência que as duas
// cópias do módulo compartilham.
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
globalThis.CHAMADAS_CASCA_188_07 = globalThis.CHAMADAS_CASCA_188_07 ?? [];
const CHAMADAS = globalThis.CHAMADAS_CASCA_188_07;
export { CHAMADAS };
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = {
    get: (...a) => { CHAMADAS.push(['get', ...a]); },
    post: (...a) => { CHAMADAS.push(['post', ...a]); },
    put: (...a) => { CHAMADAS.push(['put', ...a]); },
    delete: (...a) => { CHAMADAS.push(['delete', ...a]); },
    reload: (...a) => { CHAMADAS.push(['reload', ...a]); },
};
export function usePage() { return { props: { auth: { user: { id: 7 } } } }; }
export function Head({ children, title }) { return React.createElement('title', null, title ?? children); }
export function useForm(inicial) { return { data: inicial ?? {}, setData: () => {}, post: () => {}, get: () => {}, processing: false, errors: {}, reset: () => {} }; }
export function useRemember(v) { return [v, () => {}]; }
export const Deferred = ({ children }) => children;
export const WhenVisible = ({ children }) => children;
`, 'utf8');

after(() => {
    fs.rmSync(STUB_INERTIA, { force: true });
});

// ⚠️ `route` é o global que o Ziggy injeta no navegador. A forma do stub é a
// MESMA das waves 187-05/06 (`publicador-fila-proximo-187.test.js:70`).
global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

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
        `.pub-casca188-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';
const REL_PAINEL = `${DIR_APROV}/PainelDeRevisao.jsx`;
const REL_TOPO = `${DIR_APROV}/TopoDoPainel.jsx`;
const REL_IDENT = `${DIR_APROV}/IdentificacaoDaOferta.jsx`;
const REL_ANDAMENTO = `${DIR_APROV}/andamentoDoPortao.jsx`;
const REL_PAGINA = 'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx';
const REL_ROTAS = 'routes/mlb_anuncios.php';
const REL_CASCA_MODAL = `${DIR_APROV}/CascaDeModal.jsx`;
const REL_TOAST = `${DIR_APROV}/ToastDaFila.jsx`;
const REL_BARRA = `${DIR_APROV}/BarraDeSelecao.jsx`;
const REL_HEADER = 'resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx';
const REL_ZINDEX_ORDEM = 'tests/js/publicador-header-zindex-ordem.test.js';
const REL_LAYOUT = 'resources/js/Layouts/AppLayout.jsx';
const REL_MODAL_NOVA = 'resources/js/Components/Mlb/Publicador/header/ModalNovaPublicacao.jsx';
const REL_SERVICO_APROVACAO = 'app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php';
const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

// ⚠️⚠️ **ANTI-FANTASMA — `test -e` em CADA caminho citado.** São NOVE caminhos
// fantasmas nesta milestone, e TRÊS vieram de briefing. Um caminho que não existe
// faz `lerSemComentarios` LANÇAR, mas uma sonda de AUSÊNCIA sobre arquivo
// inexistente passaria calada.
const CAMINHOS_CITADOS = [
    REL_PAINEL, REL_TOPO, REL_IDENT, REL_CASCA_MODAL, REL_TOAST, REL_BARRA, REL_HEADER,
    REL_ZINDEX_ORDEM, REL_LAYOUT, REL_MODAL_NOVA, REL_SERVICO_APROVACAO,
    REL_ANDAMENTO, REL_PAGINA, REL_ROTAS,
];

// ⚠️ As fontes SEM comentário: a prosa em pt-BR deste projeto cita os próprios
// identificadores e os próprios literais, e **palavra no docblock reprova pelo
// próprio conserto** (TRÊS mutações da wave 187-04 abortaram por isso).
const FONTE_PAINEL = lerSemComentarios(REL_PAINEL);
const FONTE_TOPO = lerSemComentarios(REL_TOPO);
const FONTE_IDENT = lerSemComentarios(REL_IDENT);
const FONTE_PAGINA = lerSemComentarios(REL_PAGINA);

// ⚠️⚠️ E as fontes CRUAS, para o único bloco que lê COMENTÁRIO DE PROPÓSITO: o
// docblock **é** o artefato sob teste nos três motivos do portal (a próxima pessoa
// tem de achar a razão por `grep`, não por bisseção). A inversão está DECLARADA
// aqui; "consertá-la" para `semComentarios()` faria aquele bloco medir NADA.
const CRUA_PAINEL = fs.readFileSync(path.resolve(RAIZ, REL_PAINEL), 'utf8');
const CRUA_TOPO = fs.readFileSync(path.resolve(RAIZ, REL_TOPO), 'utf8');

/** A lista CONSTANTE dos arquivos DESTE plano — o recorte das sondas de território. */
const FONTES_DO_PLANO = [
    [REL_PAINEL, FONTE_PAINEL],
    [REL_TOPO, FONTE_TOPO],
    [REL_IDENT, FONTE_IDENT],
];

const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

const nada = () => {};

/**
 * O elemento de índice ABSOLUTO na árvore de tags de abertura do HTML.
 *
 * ⚠️⚠️ **NUNCA `indexOf(tagDeAbertura)`**: ele pega o IRMÃO ERRADO quando dois
 * elementos têm a MESMA string de classes — **quatro gates deste projeto estavam
 * medindo o irmão errado**. Aqui a localização é por POSIÇÃO na árvore.
 */
function tagsDeAbertura(html) {
    return [...html.matchAll(/<(div|span|section|aside|button|img)\b([^>]*)>/g)].map((m) => ({
        tag: m[1],
        atributos: m[2],
        classe: (m[2].match(/class="([^"]*)"/) || [])[1] ?? '',
        titulo: (m[2].match(/title="([^"]*)"/) || [])[1] ?? null,
        estilo: (m[2].match(/style="([^"]*)"/) || [])[1] ?? null,
        desabilitado: /\bdisabled\b/.test(m[2]),
    }));
}

/** A lista de classes de um elemento, como CONJUNTO — igualdade, nunca `includes`. */
function classesDe(elemento) {
    return new Set(elemento.classe.split(/\s+/).filter((c) => c !== ''));
}

// ─── A fixture: o payload REAL de `PainelDeRevisaoService::para()` ───
//
// ⚠️⚠️ **ELA DISCRIMINA DE PROPÓSITO.** Na wave 187-06 as duas mutações
// sobreviventes eram falha da **FIXTURE**, não da asserção (uma oferta só não
// distinguia nada). Aqui a navegação tem as DUAS pontas e o MEIO, para que a
// ausência de uma seta na ponta seja distinguível da ausência das duas.
const NAV_MEIO = {
    ids: [11, 12, 13],
    anterior_id: 11,
    proxima_id: 13,
    posicao: 2,
    total: 3,
    pos: '2 de 3 na fila',
};

const NAV_PRIMEIRA = { ...NAV_MEIO, anterior_id: null, proxima_id: 13, posicao: 1, pos: '1 de 3 na fila' };
const NAV_ULTIMA = { ...NAV_MEIO, anterior_id: 11, proxima_id: null, posicao: 3, pos: '3 de 3 na fila' };

// ⚠️ A oferta em `preparando`: FORA da navegação. `posicao` e `pos` são `null`, e
// a tela **não desenha** a frase — *"0 de N na fila"* seria a tela afirmando uma
// posição que não existe (divergência medida e declarada pelo plano 188-02).
const NAV_PREPARANDO = { ids: [11, 12, 13], anterior_id: null, proxima_id: null, posicao: null, total: 3, pos: null };

const PAINEL = { oferta_id: 12, versao: 3, navegacao: NAV_MEIO };

// ─── Os bundles: TODOS montados ANTES do primeiro `test()` ───
const M_PAINEL = await montar(REL_PAINEL);
const M_TOPO = await montar(REL_TOPO);
const M_IDENT = await montar(REL_IDENT);
const M_ANDAMENTO = await montar(REL_ANDAMENTO);
// ⚠️ O gravador compartilhado — ver a razão no bloco do stub, acima. Importar o
// arquivo de stub NÃO serve: o esbuild o inlina no bundle e as duas cópias teriam
// arrays diferentes.
const STUB = { get CHAMADAS() { return globalThis.CHAMADAS_CASCA_188_07; } };

// ═══════════════════════════════════════════════════════════════════════════
// 1. O ANTI-FANTASMA
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ ANTI-FANTASMA: todo caminho citado neste gate EXISTE no disco 188_07', () => {
    // ⚠️ UMA asserção coletando os ausentes, nunca uma por caminho.
    const ausentes = CAMINHOS_CITADOS.filter((rel) => !fs.existsSync(path.resolve(RAIZ, rel)));
    assert.deepEqual(ausentes, [], 'caminho citado não existe — sonda de ausência sobre fantasma passa calada');

    // ⚠️ ISCA: a sonda acusa um caminho que eu SEI que não existe. Sem ela, um
    // `existsSync` que devolvesse `true` para tudo deixaria o bloco vazio.
    assert.equal(fs.existsSync(path.resolve(RAIZ, `${DIR_APROV}/NaoExiste9z7.jsx`)), false, 'a isca do anti-fantasma virou arquivo de verdade');
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. O PORTAL — os três motivos, a guarda, e a prova de que o gate NÃO passa
//    por omissão
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ PORTAL: `createPortal` para o `document.body`, com a guarda de ambiente ANTES 188_07', () => {
    // ⚠️ Lido SEM comentário: a prosa deste arquivo cita `createPortal` de
    // propósito, e um gate cru passaria pelo próprio conserto.
    assert.match(FONTE_PAINEL, /createPortal\(/, 'o `createPortal` saiu — o painel volta a ser descendente do header, que tem `backdrop-filter`');
    assert.match(FONTE_PAINEL, /document\.body/, 'o destino do portal deixou de ser o `document.body`');
    assert.match(FONTE_PAINEL, /import \{ createPortal \} from 'react-dom'/, 'o import de `createPortal` saiu');

    const guarda = FONTE_PAINEL.indexOf("typeof document === 'undefined'");
    const portal = FONTE_PAINEL.indexOf('createPortal(');
    assert.ok(guarda >= 0, 'a guarda `typeof document === \'undefined\'` saiu — o render de SERVIDOR passa a LANÇAR');
    assert.ok(portal >= 0, 'o `createPortal(` saiu');
    assert.ok(
        guarda < portal,
        `a guarda (${guarda}) tem de vir ANTES do \`createPortal\` (${portal}); depois dele ela não protege nada`,
    );

    // ⚠️ `!document` LANÇA no mesmo lugar que a guarda existe para evitar.
    assert.doesNotMatch(FONTE_PAINEL, /if \(!document\)/, '`!document` LANÇA — a guarda tem de ser `typeof`');
});

test('⚠️⚠️ PORTAL: o COMPOSTO sai VAZIO no render de servidor, e a RAIZ sai CHEIA 188_07', () => {
    // ⚠️⚠️ **ESTE É O BLOCO QUE IMPEDE O GATE DE PASSAR POR OMISSÃO.** O
    // renderizador de SERVIDOR do React **não renderiza portal**. Se alguém
    // medisse a geometria pelo COMPOSTO, veria HTML vazio e tudo passaria verde
    // provando NADA. ➡️ O composto SAI VAZIO (é a prova de que o portal existe) e
    // a geometria é medida na **named export**, direto.
    const composto = render(M_PAINEL.default, { aberto: true, painel: PAINEL, onFechar: nada, onIr: nada });
    assert.equal(composto, '', 'o composto renderizou no servidor — ou o portal sumiu, ou a guarda sumiu');

    // ⚠️ E a contraprova, sem a qual o `equal('')` acima seria vazio por
    // construção: a RAIZ renderiza de verdade.
    const raiz = render(M_PAINEL.RaizDoPainel, { navegacao: NAV_MEIO, onFechar: nada, onIr: nada });
    assert.ok(raiz.length > 200, `a RaizDoPainel saiu curta demais (${raiz.length} bytes) — a named export não está desenhando`);
    assert.match(raiz, /<aside/, 'o `<aside>` do painel desapareceu da raiz');
});

test('⚠️ PORTAL: `aberto !== true` e `painel` ausente NÃO desenham nada 188_07', () => {
    // ⚠️ Backdrop invisível capturaria o clique da tela inteira sem ninguém ver
    // por quê. ⚠️ E casca de 1140px sem oferta é D4 violado.
    for (const props of [
        {},
        { aberto: false, painel: PAINEL },
        { aberto: true, painel: null },
        { aberto: 'true', painel: PAINEL },
        { aberto: 1, painel: PAINEL },
        { aberto: true, painel: [] },
        { aberto: true, painel: 'x' },
    ]) {
        assert.equal(render(M_PAINEL.default, props), '', `o painel desenhou algo com ${JSON.stringify(Object.keys(props))}`);
    }
});

test('⚠️⚠️ REND-05: `font-pub` está na RAIZ do que é PORTADO 188_07', () => {
    // ⚠️⚠️ Conteúdo portado **NÃO HERDA A FONTE**: ele sai da subárvore do módulo
    // e volta a herdar do `document.body`, que é Inter.
    // `ModalNovaPublicacao.jsx:95`-`:97` declara isso textualmente, e foi MEDIDO
    // na Fase 178. ⚠️ É **exatamente no limite do portal** que a herança quebra —
    // num filho a classe não serviria.
    const classes = M_PAINEL.CLASSES_PAINEL.split(/\s+/);
    assert.ok(classes.includes('font-pub'), '`font-pub` saiu da raiz do portado — o painel perde a Geist EM SILÊNCIO');
    assert.ok(classes.includes('text-pub-txt'), '`text-pub-txt` saiu da raiz do portado');

    // ⚠️ E ela tem de estar no ELEMENTO, não só na constante: a sonda de constante
    // não prova que o render a usa.
    const raiz = render(M_PAINEL.RaizDoPainel, { navegacao: NAV_MEIO, onFechar: nada, onIr: nada });
    const aside = tagsDeAbertura(raiz).find((e) => e.tag === 'aside');
    assert.ok(aside !== undefined, 'o `<aside>` não foi achado no HTML');
    assert.ok(classesDe(aside).has('font-pub'), '`font-pub` não chegou ao `<aside>` renderizado');

    // ⚠️ ISCA DO PRECEDENTE: `ModalNovaPublicacao.jsx` declara `font-pub` na caixa
    // portada e `CascaDeModal.jsx` **NÃO** declara — e está CERTO, porque ela não
    // é portada e herda. Se os dois fatos invertessem, a razão escrita aqui estaria
    // errada e este gate passaria pelo motivo errado.
    assert.match(lerSemComentarios(REL_MODAL_NOVA), /font-pub/, 'o precedente do portal (ModalNovaPublicacao) perdeu o `font-pub`');
    assert.doesNotMatch(
        lerSemComentarios(REL_CASCA_MODAL).match(/export const CLASSES_CAIXA[^;]*;/)?.[0] ?? '',
        /font-pub/,
        'a `CascaDeModal` (que NÃO é portada) passou a declarar `font-pub` — a razão escrita neste gate precisa ser remedida',
    );
});

test('⚠️⚠️ PORTAL: o docblock DECLARA os TRÊS motivos (lido COM comentário, de propósito) 188_07', () => {
    // ⚠️⚠️ **INVERSÃO DECLARADA:** este é o único bloco deste arquivo que lê
    // COMENTÁRIO. O docblock **é** o artefato sob teste — a próxima pessoa que vir
    // o painel preso ao header, ou sem a Geist, tem de achar a razão por `grep`,
    // não por bisseção. "Consertar" para `semComentarios()` faria este bloco
    // medir NADA.
    const EXIGIDOS = [
        // motivo 1 — por que portal
        'backdrop-filter',
        'containing block',
        'header/PublicadorHeader.jsx:171',
        // motivo 2 — por que a guarda e por que named exports
        'não renderiza portal',
        'POR OMISSÃO',
        'NAMED EXPORTS',
        // motivo 3 — por que `font-pub` na raiz
        'REND-05',
        'ModalNovaPublicacao.jsx:95',
        'não herda a fonte',
    ];

    // ⚠️ UMA asserção coletando os ausentes, nunca uma por item (learnings §4b).
    const faltando = EXIGIDOS.filter((trecho) => !CRUA_PAINEL.toLowerCase().includes(trecho.toLowerCase()));
    assert.deepEqual(faltando, [], 'o docblock do portal deixou de declarar motivo(s) — sem eles a causa só se acha por bisseção');

    // ⚠️ ISCA NAS DUAS DIREÇÕES: o docblock completo PASSA (acima) e um trecho que
    // eu SEI que não está lá REPROVA. Sem esta linha, um `includes` que devolvesse
    // `true` para tudo deixaria o bloco vazio.
    assert.equal(CRUA_PAINEL.includes('motivo-que-ninguem-escreveu-9z7'), false, 'a isca do docblock virou texto de verdade');

    // ⚠️ E a prova de que `lerSemComentarios` de fato TIRA o que a fonte crua tem:
    // sem ela, a inversão declarada acima não seria inversão nenhuma.
    assert.ok(CRUA_PAINEL.length > FONTE_PAINEL.length, 'a fonte crua não é maior que a sem comentário — a leitura está quebrada');
});

test('⚠️⚠️ PORTAL: TODAS as peças internas são NAMED EXPORTS (lista CONSTANTE) 188_07', () => {
    // ⚠️⚠️ **LISTA CONSTANTE, e não `>= N`:** piso **NÃO prova PRESENÇA** — com 12
    // itens, perder um deixa 11 e o piso passa (a 187-11 mediu exatamente isso).
    const DO_PAINEL = [
        'default', 'RaizDoPainel', 'BackdropDoPainel', 'CorpoDoPainel', 'ColunasDoPainel',
        'ColunaDoPainel', 'filhosSeguros', 'escreverNoPainel', 'objetoDoPainel', 'inteiroDoPainel',
        'CLASSES_BACKDROP', 'CLASSES_PAINEL', 'CLASSES_CORPO', 'CLASSES_COLUNAS', 'CLASSES_COLUNA',
        'CHAVE_DA_VERSAO', 'REGRA_VERSAO_DIVERGENTE',
        'resolverAtalhoDoPainel', 'TECLAS', 'TAGS_DE_CAMPO', 'ACOES',
    ];
    const DO_IDENT = [
        'default', 'CaixaDeFalha', 'chipsDaOferta', 'temIdentificacao', 'textoDaOferta', 'objetoDaOferta',
        'CLASSES_LINHA', 'CLASSES_COLUNA_ESQUERDA', 'CLASSES_PRODUTO', 'CLASSES_SKU', 'SEPARADOR',
        'CLASSES_TITULO', 'CLASSES_FILA_DE_CHIPS', 'CLASSES_CHIP', 'CLASSES_CHIP_MODALIDADE',
        'CLASSES_LOSANGO', 'CLASSES_CHIP_CONTORNO', 'CLASSES_CHIP_ANDAMENTO', 'CLASSES_DOT',
        'CLASSES_LADO_DIREITO', 'CLASSES_RESPONSAVEL', 'CLASSES_FALHA', 'CLASSES_GLIFO_FALHA',
        'GLIFO_FALHA', 'CLASSES_FALHA_TEXTO', 'CLASSES_FALHA_TITULO', 'TITULO_DA_FALHA',
        'CLASSES_FALHA_MENSAGEM', 'CLASSES_FALHA_ACAO',
    ];
    const DO_TOPO = [
        'default', 'SetaDoPainel', 'textoDoTopo', 'idDoTopo',
        'CLASSES_TOPO', 'CLASSES_ROTULO', 'CLASSES_POSICAO', 'CLASSES_WRAPPER_SETAS',
        'CLASSES_SETA', 'CLASSES_FECHAR', 'ROTULO_DO_PAINEL',
        'GLIFO_ANTERIOR', 'GLIFO_PROXIMO', 'GLIFO_FECHAR',
        'TITULO_ANTERIOR', 'TITULO_PROXIMO', 'TITULO_FECHAR',
    ];

    const faltando = [
        ...DO_PAINEL.filter((nome) => M_PAINEL[nome] === undefined).map((n) => `${REL_PAINEL}#${n}`),
        ...DO_TOPO.filter((nome) => M_TOPO[nome] === undefined).map((n) => `${REL_TOPO}#${n}`),
        ...DO_IDENT.filter((nome) => M_IDENT[nome] === undefined).map((n) => `${REL_IDENT}#${n}`),
    ];
    assert.deepEqual(faltando, [], 'named export sumiu — sem ela o gate mede o COMPOSTO, que o render de servidor devolve VAZIO');

    // ⚠️ ISCA: um nome que eu SEI que não é exportado tem de dar `undefined`.
    assert.equal(M_PAINEL.NaoExisteExport9z7, undefined, 'a isca das named exports virou export de verdade');
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. Z-INDEX — a ORDEM, com isca que distingue INVERSÃO de troca de número
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Lê o z de uma âncora, EXIGINDO exatamente uma ocorrência.
 *
 * ⚠️ Extração que devolve `null` em silêncio quando a classe é renomeada é o
 * jeito clássico de um gate virar decoração: ele continua verde porque não achou
 * nada para comparar.
 * ⚠️ **SUBSTRING CASA COM PREFIXO** — `z-[39]` casaria dentro de `z-[390]` sem o
 * `\]`, e este projeto já foi mordido CINCO vezes por isso.
 */
function zDe(fonte, ancora, nome) {
    const casos = [...fonte.matchAll(ancora)];
    assert.equal(casos.length, 1, `a âncora de "${nome}" casou ${casos.length} vez(es), esperava 1 — conserte a âncora, NÃO apague a asserção`);
    const valor = Number(casos[0][1]);
    assert.ok(Number.isInteger(valor), `o z de "${nome}" não é inteiro: ${casos[0][1]}`);

    return valor;
}

/**
 * A ordem relativa que o critério 1 exige.
 *
 * ⚠️⚠️ **ANCORADA NO COMPORTAMENTO (a desigualdade), NUNCA numa lista de números
 * proibidos.** A 187-09 mediu que **`45` é o número do POPOVER** do protótipo e o
 * toast legitimamente ficou em `z-[45]`: lista larga **reprova código certo**.
 */
function ordemOk(z) {
    return z.header < z.backdrop && z.backdrop < z.painel && z.painel < z.modal && z.modal < z.toast;
}

test('⚠️⚠️ Z-INDEX: header < backdrop < painel < modal < toast, lidos DO CÓDIGO 188_07', () => {
    const z = {
        header: zDe(lerSemComentarios(REL_HEADER), /<header className="sticky top-0 z-(\d+)(?=[\s"])/g, 'header'),
        backdrop: zDe(FONTE_PAINEL, /CLASSES_BACKDROP = 'fixed inset-0 z-\[(\d+)\]/g, 'backdrop do painel'),
        painel: zDe(FONTE_PAINEL, /CLASSES_PAINEL =\s*\n?\s*'fixed inset-y-0 right-0 z-\[(\d+)\]/g, 'painel'),
        modal: zDe(lerSemComentarios(REL_CASCA_MODAL), /CLASSES_BACKDROP = 'fixed inset-0 z-\[(\d+)\]/g, 'casca de modal'),
        toast: zDe(lerSemComentarios(REL_TOAST), /z-\[(4\d)\]/g, 'toast'),
    };

    assert.ok(ordemOk(z), `a ordem de empilhamento quebrou: ${JSON.stringify(z)}`);

    // ⚠️ E a barra de lote, que fica ENTRE o header e o backdrop do painel: o
    // painel tem de cobri-la (ela é da fila, que fica ATRÁS da revisão).
    const barra = zDe(lerSemComentarios(REL_BARRA), /z-\[(\d+)\]/g, 'barra de seleção');
    assert.ok(z.header < barra && barra < z.backdrop, `a barra de lote (${barra}) saiu de entre o header (${z.header}) e o backdrop (${z.backdrop})`);
});

test('⚠️⚠️ Z-INDEX: a ISCA distingue INVERSÃO de simples TROCA DE NÚMERO 188_07', () => {
    const BASE = { header: 30, backdrop: 39, painel: 40, modal: 42, toast: 45 };

    // ⚠️ A ponta POSITIVA, sem a qual o bloco seria vazio por construção.
    assert.equal(ordemOk(BASE), true, 'a sonda está cega: nem a ordem de verdade ela aceita');

    // ⚠️⚠️ CASO 1 — **INVERSÃO**: backdrop em 41 passa a cobrir o painel. REPROVA.
    assert.equal(ordemOk({ ...BASE, backdrop: 41 }), false, 'backdrop ACIMA do painel passou — o painel ficaria atrás do próprio fundo');
    assert.equal(ordemOk({ ...BASE, backdrop: 29 }), false, 'backdrop ABAIXO do header passou — o header não seria escurecido');
    assert.equal(ordemOk({ ...BASE, painel: 43 }), false, 'painel ACIMA do modal passou — o modal abriria ATRÁS do painel');

    // ⚠️⚠️ CASO 2 — **TROCA DE NÚMERO SEM INVERSÃO**: 41 continua entre 39 e 42.
    // **NÃO pode reprovar por si** — só a ordem importa, e um gate de lista larga
    // de números reprovaria este código CERTO.
    assert.equal(ordemOk({ ...BASE, painel: 41 }), true, 'troca de número que PRESERVA a ordem foi reprovada — o gate está mais largo que o invariante');
    assert.equal(ordemOk({ header: 20, backdrop: 25, painel: 26, modal: 27, toast: 28 }), true, 'a escala inteira deslocada, com a MESMA ordem, foi reprovada');
});

test('⚠️ Z-INDEX: a extração não casa por PREFIXO (z-[39] não casa dentro de z-[390]) 188_07', () => {
    const pegar = (texto) => {
        const m = [...texto.matchAll(/CLASSES_BACKDROP = 'fixed inset-0 z-\[(\d+)\]/g)];

        return m.length === 1 ? Number(m[0][1]) : null;
    };
    assert.equal(pegar("export const CLASSES_BACKDROP = 'fixed inset-0 z-[39] bg-black/[0.55]';"), 39);
    // ⚠️ O caso que mata gate por substring: 390 NÃO pode ser lido como 39.
    assert.equal(pegar("export const CLASSES_BACKDROP = 'fixed inset-0 z-[390] bg-black/[0.55]';"), 390);
    assert.equal(pegar("export const CLASSES_BACKDROP = 'fixed inset-0 z-[3] bg-black/[0.55]';"), 3);
});

test('⚠️⚠️ TERR-06: o teste de z-index existente e o HEADER saem INTACTOS 188_07', () => {
    // ⚠️⚠️ **O CRITÉRIO 1 EXIGE OS DOIS INTACTOS.** `git diff --numstat` tem de
    // sair VAZIO nos dois: o invariante `filtros 10 < header 30 < menu lateral =
    // painel 40` é medido de um bug que o usuário relatou em 12/10/2026, e o
    // header é contrato da Fase 177.
    const numstat = (rel) => execFileSync('git', ['diff', '--numstat', 'HEAD', '--', rel], { cwd: RAIZ, encoding: 'utf8' }).trim();

    const sujos = [REL_ZINDEX_ORDEM, REL_HEADER, REL_LAYOUT, REL_CASCA_MODAL, REL_TOAST, REL_BARRA]
        .filter((rel) => numstat(rel) !== '');
    assert.deepEqual(sujos, [], 'arquivo INTOCÁVEL foi modificado — este plano COMPÕE, não reescreve');

    // ⚠️ ISCA: o `numstat` do arquivo deste plano NÃO é vazio enquanto ele não está
    // commitado. Sem esta linha, um `numstat` que devolvesse `''` sempre deixaria
    // o bloco acima vazio por construção. ⚠️ Depois do commit o `numstat` de
    // `HEAD` fica vazio para ele também — e é por isso que a isca aceita as DUAS
    // formas, nomeando a razão.
    // ⚠️⚠️ ISCA: a sonda PRECISA devolver não-vazio quando há mudança de verdade.
    // ⚠️ **A isca NÃO pode ser `numstat` de um arquivo deste plano:** antes do
    // commit ele é **untracked** (e `git diff` não vê untracked, então sai vazio);
    // depois do commit ele fica **idêntico ao HEAD** (e sai vazio também). ➡️ A
    // isca é um RANGE de commits, que sempre tem conteúdo — é a mesma família do
    // *"gate vazio por construção"*: `git diff --stat -- <dir>` sem range compara
    // a ÁRVORE e sai vazio SEMPRE.
    const doUltimoCommit = execFileSync('git', ['diff', '--numstat', 'HEAD~1', 'HEAD'], { cwd: RAIZ, encoding: 'utf8' }).trim();
    assert.notEqual(doUltimoCommit, '', 'o `numstat` saiu vazio para o ÚLTIMO COMMIT — a sonda está cega e o bloco acima não prova nada');

    // ⚠️ E o invariante do arquivo intocado continua LEGÍVEL: se a âncora dele
    // mudasse, o gate dele reprovaria e este aqui não veria nada.
    assert.match(lerSemComentarios(REL_ZINDEX_ORDEM), /assert\.equal\(z\.menu, z\.painel/, 'a asserção central do gate de ordem travada desapareceu');
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. O TOPO DE 54px — os QUATRO elementos, e a ausência do quinto
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ TOPO: os QUATRO elementos, por ÁRVORE com índice ABSOLUTO 188_07', () => {
    const html = render(M_TOPO.default, { navegacao: NAV_MEIO, onIr: nada, onFechar: nada });
    const arvore = tagsDeAbertura(html);

    // ⚠️ Índice ABSOLUTO na árvore: 0 = topo, 1 = rótulo, 2 = posição,
    // 3 = wrapper ↑↓, 4 = ↑, 5 = ↓, 6 = ×. ⚠️ **Nunca `indexOf` de classes.**
    assert.equal(arvore.length, 7, `a árvore do topo tem ${arvore.length} elementos, esperava 7 — a montagem mudou`);

    // ⚠️ IGUALDADE DE CONJUNTO de classes, nunca `includes`: `includes('h-[64px]')`
    // casava `min-h-[64px]`, e `w-[560px]` é substring de `max-w-[560px]`.
    assert.deepEqual(classesDe(arvore[0]), new Set(M_TOPO.CLASSES_TOPO.split(/\s+/)), 'as classes do topo de 54px divergiram');
    assert.deepEqual(classesDe(arvore[1]), new Set(M_TOPO.CLASSES_ROTULO.split(/\s+/)), 'as classes do rótulo divergiram');
    assert.deepEqual(classesDe(arvore[2]), new Set(M_TOPO.CLASSES_POSICAO.split(/\s+/)), 'as classes da posição divergiram');
    assert.deepEqual(classesDe(arvore[3]), new Set(M_TOPO.CLASSES_WRAPPER_SETAS.split(/\s+/)), 'as classes do wrapper ↑↓ divergiram');
    assert.deepEqual(classesDe(arvore[4]), new Set(M_TOPO.CLASSES_SETA.split(/\s+/)), 'as classes da seta ↑ divergiram');
    assert.deepEqual(classesDe(arvore[5]), new Set(M_TOPO.CLASSES_SETA.split(/\s+/)), 'as classes da seta ↓ divergiram');
    assert.deepEqual(classesDe(arvore[6]), new Set(M_TOPO.CLASSES_FECHAR.split(/\s+/)), 'as classes do × divergiram');

    // Os quatro TEXTOS, na ordem do protótipo.
    assert.match(html, /REVISÃO SIMPLES/, 'o rótulo "REVISÃO SIMPLES" (`:552`) desapareceu');
    assert.match(html, /2 de 3 na fila/, 'a posição na fila (`:553`) desapareceu');
    assert.ok(html.indexOf('REVISÃO SIMPLES') < html.indexOf('2 de 3 na fila'), 'a ordem rótulo → posição inverteu');
    assert.match(html, />↑</, 'a seta ↑ (`:555`) desapareceu');
    assert.match(html, />↓</, 'a seta ↓ (`:556`) desapareceu');
    assert.match(html, />×</, 'o × (`:559`) desapareceu');
});

test('⚠️ TOPO: a GEOMETRIA literal do protótipo, valor por valor 188_07', () => {
    // ⚠️ Medidas de `:551`-`:559`, re-conferidas por `sed` nesta sessão.
    // ⚠️ `padding:0 14px 0 22px` são DOIS lados diferentes — `p-[14px]` seria errado.
    const ESPERADO = {
        CLASSES_TOPO: ['flex', 'flex-none', 'items-center', 'gap-[10px]', 'h-[54px]', 'pl-[22px]', 'pr-[14px]', 'border-b', 'border-pub-line-subtle'],
        CLASSES_ROTULO: ['text-[11px]', 'font-bold', 'tracking-[0.08em]', 'text-pub-txt-3'],
        CLASSES_POSICAO: ['text-[12px]', 'text-pub-txt-4'],
        CLASSES_WRAPPER_SETAS: ['flex', 'gap-[2px]', 'ml-[6px]'],
        // ⚠️ `whitespace-nowrap` é a PRIMEIRA propriedade de `:555`/`:556`/`:559`,
        // e `cursor-pointer` está nas três — as DUAS famílias de omissão que a
        // Fase 187 mediu (sete e onze vezes).
        CLASSES_SETA: ['w-[30px]', 'h-[30px]', 'cursor-pointer', 'whitespace-nowrap', 'rounded-pub-control-sm', 'text-[14px]', 'text-pub-txt-2', 'hover:bg-pub-hover-3'],
        CLASSES_FECHAR: ['ml-auto', 'w-[32px]', 'h-[32px]', 'cursor-pointer', 'whitespace-nowrap', 'rounded-[8px]', 'text-[16px]', 'text-pub-txt-2', 'hover:bg-pub-hover-3'],
    };

    const divergentes = Object.keys(ESPERADO).filter((chave) => {
        // ⚠️ Flag DENTRO do callback: o Rollup elimina variável de escopo do
        // componente usada dentro de `.filter()`.
        const noDisco = new Set(M_TOPO[chave].split(/\s+/).filter((c) => c !== ''));

        return noDisco.size !== ESPERADO[chave].length || ESPERADO[chave].some((c) => !noDisco.has(c));
    });
    assert.deepEqual(divergentes, [], 'a geometria do topo divergiu do protótipo — o protótipo GANHA em VALORES, RÓTULOS e ORDEM');

    // ⚠️ FID-04 — os tokens VETADOS não aparecem.
    const vetados = ['ecf-bg', 'ecf-card', 'ecf-yellow', 'font-display', 'border-white/[0.08]'];
    const achados = FONTES_DO_PLANO.flatMap(([rel, fonte]) => vetados.filter((t) => fonte.includes(t)).map((t) => `${rel}:${t}`));
    assert.deepEqual(achados, [], 'token VETADO (FID-04) apareceu nos arquivos deste plano');

    // ⚠️ FID-03 — `text-sm`/`text-xs`/`text-base` são a adaptação que o usuário
    // recusou textualmente em 11/10/2026.
    const arredondados = FONTES_DO_PLANO.flatMap(([rel, fonte]) => ['text-sm', 'text-xs', 'text-base', 'text-lg'].filter((t) => new RegExp(`\\b${t}\\b`).test(fonte)).map((t) => `${rel}:${t}`));
    assert.deepEqual(arredondados, [], 'tamanho ARREDONDADO (FID-03) apareceu — o passo do Tailwind não tem meio pixel');
});

test('⚠️⚠️ PERGUNTA-188-B3: "Abrir revisão detalhada ↗" NÃO é desenhado 188_07', () => {
    // ⚠️⚠️ Decisão do usuário, 18/10/2026: ele nasce na **Fase 190/191**, junto com
    // a tela. Razão dele: *"é a mesma régua do aviso de tela vazia — sem botão,
    // porque não há o que fazer com ele"*. ⚠️ "Desenhar desabilitado com
    // explicação" foi OFERECIDO e RECUSADO.
    // ⚠️ Lido SEM comentário: a prosa dos dois arquivos cita o literal DE
    // PROPÓSITO (é o que explica a ausência), e três mutações da wave 187-04
    // abortaram exatamente por docblock casando com o próprio gate.
    const PROIBIDOS = [
        /Abrir revis[ãa]o detalhada/i,
        // ⚠️ O `↗` como GLIFO DE TELA: entre `>` e `<`, ou dentro de string. A
        // forma estreita (procurar a frase inteira) deixaria passar um botão só
        // com a flecha.
        /['">]\s*↗/,
        // ⚠️ `onClick` vazio e `disabled` decorativo são a mesma dívida.
        /onClick=\{\(\)\s*=>\s*\{\s*\}\}/,
        /onClick=\{null\}/,
        // ⚠️⚠️ `/disabled=/` e **NUNCA** `/disabled/`, que casa com
        // `disabled:opacity-40` — a armadilha que o critério 8 já traz escrita.
        /disabled=/,
    ];

    const achados = FONTES_DO_PLANO.flatMap(([rel, fonte]) => PROIBIDOS.filter((re) => re.test(fonte)).map((re) => `${rel}:${re.source}`));
    assert.deepEqual(achados, [], 'o botão da Fase 190/191 foi antecipado, ou nasceu `onClick` vazio/`disabled` decorativo');

    // ⚠️⚠️ ISCA NAS DUAS DIREÇÕES — cada regex é testada contra um caso que ela
    // **DEVE** casar. As waves desta fase acharam 2+1+2+4+3+9 furos nas PRÓPRIAS
    // sondas; sem esta prova, "nenhum achado" pode ser a sonda cega.
    const CEBO = `
        <button onClick={() => {}} title="x">Abrir revisão detalhada<span>↗</span></button>
        <button onClick={null} disabled={true}>y</button>
    `;
    const cegas = PROIBIDOS.filter((re) => !re.test(CEBO));
    assert.deepEqual(cegas, [], 'regex que não casa o próprio cebo — a sonda está CEGA e "nenhum achado" não significa nada');

    // ⚠️ E a ponta que prova que o literal EXISTE no protótipo (`:558`): sem ela, a
    // ausência poderia ser "o protótipo nunca teve isso", que é outro assunto.
    const abs = path.resolve(RAIZ, PROTOTIPO);
    if (!fs.existsSync(abs)) {
        // ⚠️ `design_handoff_publicador/` **NÃO é versionado**: degrada para razão
        // NOMEADA, nunca para "conferido".
        assert.ok(true, `SKIP NOMEADO: ${PROTOTIPO} ausente (pacote de handoff não versionado) — a ponta do protótipo não foi medida`);
    } else {
        const linhas = fs.readFileSync(abs, 'utf8').split(/\r?\n/);
        assert.match(linhas[557] ?? '', /Abrir revisão detalhada/, 'o literal saiu de `:558` do protótipo — remeça a referência');
    }
});

test('⚠️ TOPO: os três `title` são NÃO-VAZIOS e nomeiam o atalho 188_07', () => {
    // ⚠️ Os três botões são GLIFO puro. `title` vazio produz **elemento sem nome
    // acessível**, que passa em render e DESAPARECE para quem usa leitor de tela —
    // foi o conserto do anel na 187-08.
    const html = render(M_TOPO.default, { navegacao: NAV_MEIO, onIr: nada, onFechar: nada });
    const botoes = tagsDeAbertura(html).filter((e) => e.tag === 'button');
    assert.equal(botoes.length, 3, `o topo tem ${botoes.length} botões, esperava 3 (↑, ↓, ×)`);

    const semTitulo = botoes.filter((b) => typeof b.titulo !== 'string' || b.titulo.trim() === '');
    assert.deepEqual(semTitulo.map((b) => b.atributos), [], 'botão de glifo sem `title` — elemento sem nome acessível');

    assert.deepEqual(botoes.map((b) => b.titulo), ['Anterior (↑)', 'Próximo (↓)', 'Fechar (Esc)'], 'os `title` divergiram do protótipo (`:555`/`:556`/`:559`)');

    // ⚠️ E as constantes NÃO são vazias na origem: um `title` montado de prop
    // poderia chegar vazio do servidor.
    const vazias = ['TITULO_ANTERIOR', 'TITULO_PROXIMO', 'TITULO_FECHAR'].filter((n) => typeof M_TOPO[n] !== 'string' || M_TOPO[n].trim() === '');
    assert.deepEqual(vazias, [], 'constante de `title` virou vazia');
});

test('⚠️ TOPO: a posição NÃO é desenhada com `pos = null`, e a seta sem destino também não 188_07', () => {
    // ⚠️⚠️ *"0 de N na fila"* é a tela afirmando uma posição que NÃO EXISTE — o
    // critério 2 manda que *"a tela não inventa posição"*. O protótipo PERDE aqui
    // (`:1318` deixa o `indexOf` devolver −1), e a divergência foi medida e
    // declarada pelo plano 188-02.
    const preparando = render(M_TOPO.default, { navegacao: NAV_PREPARANDO, onIr: nada, onFechar: nada });
    assert.doesNotMatch(preparando, /na fila/, 'a frase de posição apareceu para quem está FORA da navegação');
    assert.doesNotMatch(preparando, /0 de/, '"0 de N" voltou — a tela inventaria uma posição');

    // ⚠️ Sem nenhuma das duas pontas, o WRAPPER de `:554` também não é desenhado:
    // casca de 6px de margem sem conteúdo é D4 violado.
    assert.doesNotMatch(preparando, />↑</, 'a seta ↑ foi desenhada sem destino');
    assert.doesNotMatch(preparando, />↓</, 'a seta ↓ foi desenhada sem destino');
    const soFechar = tagsDeAbertura(preparando).filter((e) => e.tag === 'button');
    assert.equal(soFechar.length, 1, `fora da navegação o topo tem ${soFechar.length} botões, esperava 1 (só o ×)`);
    assert.equal(soFechar[0].titulo, 'Fechar (Esc)', 'o botão que sobrou não é o ×');

    // ⚠️ AS DUAS PONTAS, uma a uma — e elas se DISTINGUEM: na primeira falta o ↑ e
    // sobra o ↓; na última, o contrário. Uma fixture só não distinguiria nada.
    const primeira = render(M_TOPO.default, { navegacao: NAV_PRIMEIRA, onIr: nada, onFechar: nada });
    assert.doesNotMatch(primeira, />↑</, 'na PRIMEIRA da fila o ↑ foi desenhado sem destino');
    assert.match(primeira, />↓</, 'na PRIMEIRA da fila o ↓ desapareceu — ele TEM destino');
    assert.match(primeira, /1 de 3 na fila/, 'a posição da primeira desapareceu');

    const ultima = render(M_TOPO.default, { navegacao: NAV_ULTIMA, onIr: nada, onFechar: nada });
    assert.match(ultima, />↑</, 'na ÚLTIMA da fila o ↑ desapareceu — ele TEM destino');
    assert.doesNotMatch(ultima, />↓</, 'na ÚLTIMA da fila o ↓ foi desenhado sem destino');

    // ⚠️ E sem handler nenhuma seta é desenhada, mesmo com os dois ids presentes:
    // botão sem destino é D4 violado.
    const semHandler = render(M_TOPO.default, { navegacao: NAV_MEIO, onIr: null, onFechar: nada });
    assert.doesNotMatch(semHandler, />↑</, 'a seta foi desenhada sem handler');
});

test('⚠️ TOPO: o CLIQUE nas setas e no × chama o handler com o ID do servidor 188_07', () => {
    // ⚠️⚠️ `renderToStaticMarkup` **NÃO DISPARA `onClick`** — a tela preta da
    // 187-06 foi **no clique** —, então a peça é exercida pela named export.
    const vistos = [];
    const seta = M_TOPO.SetaDoPainel({
        destino: 13,
        glifo: M_TOPO.GLIFO_PROXIMO,
        titulo: M_TOPO.TITULO_PROXIMO,
        onIr: (id) => vistos.push(id),
    });
    seta.props.onClick();
    assert.deepEqual(vistos, [13], 'o clique na seta não levou o id de destino do SERVIDOR');

    // ⚠️ E a seta sem destino devolve `null` — não um botão sem `onClick`.
    assert.equal(M_TOPO.SetaDoPainel({ destino: null, glifo: '↓', titulo: 'x', onIr: nada }), null, 'seta sem destino devolveu elemento');
    assert.equal(M_TOPO.SetaDoPainel({ destino: 13, glifo: '↓', titulo: '', onIr: nada }), null, 'seta com `title` VAZIO devolveu elemento');
    assert.equal(M_TOPO.SetaDoPainel({ destino: 13, glifo: '', titulo: 'x', onIr: nada }), null, 'seta sem glifo devolveu elemento');
    assert.equal(M_TOPO.SetaDoPainel({ destino: 13, glifo: '↓', titulo: 'x', onIr: null }), null, 'seta sem handler devolveu elemento');

    // ⚠️ `0` não é id: `Number('')` é 0, e um id 0 montaria navegação inventada.
    assert.equal(M_TOPO.idDoTopo(0), 0, '`idDoTopo(0)` deixou de ser inteiro — a guarda mudou de significado');
    for (const lixo of [null, undefined, '', '12', 12.5, NaN, {}, [], { toString: null }]) {
        assert.equal(M_TOPO.idDoTopo(lixo), null, `\`idDoTopo\` aceitou lixo (${typeof lixo})`);
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. A CASCA: backdrop, `<aside>`, corpo e as DUAS COLUNAS DE MESMO PESO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ CASCA: a geometria literal de `:549`/`:550`/`:562`, por ÁRVORE e por CONJUNTO 188_07', () => {
    const html = render(M_PAINEL.RaizDoPainel, { navegacao: NAV_MEIO, onIr: nada, onFechar: nada });
    const arvore = tagsDeAbertura(html);

    // ⚠️ Índice ABSOLUTO: 0 = backdrop, 1 = `<aside>`, 2 = topo…
    assert.deepEqual(classesDe(arvore[0]), new Set(M_PAINEL.CLASSES_BACKDROP.split(/\s+/)), 'as classes do backdrop divergiram');
    assert.equal(arvore[1].tag, 'aside', `o segundo elemento é <${arvore[1].tag}>, esperava <aside> (prototipo :550)`);
    assert.deepEqual(classesDe(arvore[1]), new Set(M_PAINEL.CLASSES_PAINEL.split(/\s+/)), 'as classes do `<aside>` divergiram');

    const ESPERADO = {
        // `:549` — `position:fixed;inset:0;background:rgba(0,0,0,0.55)`
        CLASSES_BACKDROP: ['fixed', 'inset-0', 'z-[39]', 'bg-black/[0.55]'],
        // `:550` — a geometria completa. ⚠️ `shadow-pub-painel` é o TOKEN com o
        // valor exato `-24px 0 64px rgba(0,0,0,0.6)`; escrever o arbitrário seria a
        // SEGUNDA cópia do mesmo valor. ⚠️ `bg-pub-sunken` é `#0D0D0D` — **não**
        // `pub-page` (#0A0A0A) nem `pub-surface` (#111111).
        CLASSES_PAINEL: [
            'fixed', 'inset-y-0', 'right-0', 'z-[40]', 'flex', 'w-[min(1140px,calc(100vw-100px))]',
            'flex-col', 'border-l', 'border-pub-line-strong', 'bg-pub-sunken', 'font-pub',
            'text-pub-txt', 'shadow-pub-painel',
        ],
        // `:562` — `flex:1;overflow-y:auto;padding:18px 22px 24px;gap:16px`
        CLASSES_CORPO: ['flex', 'flex-1', 'flex-col', 'gap-[16px]', 'overflow-y-auto', 'pl-[22px]', 'pr-[22px]', 'pt-[18px]', 'pb-[24px]'],
        // ⚠️⚠️ `:586` — AS DUAS COLUNAS DE MESMO PESO.
        CLASSES_COLUNAS: ['grid', 'grid-cols-[minmax(0,1fr)_minmax(0,1fr)]', 'items-start', 'gap-[16px]'],
        // `:587`/`:639` — `background:#111111;border:1px solid #262626;radius:14px;padding:14px;gap:12px`
        CLASSES_COLUNA: ['flex', 'flex-col', 'gap-[12px]', 'rounded-pub-table', 'border', 'border-pub-line', 'bg-pub-surface', 'p-[14px]'],
    };

    const divergentes = Object.keys(ESPERADO).filter((chave) => {
        const noDisco = new Set(M_PAINEL[chave].split(/\s+/).filter((c) => c !== ''));

        return noDisco.size !== ESPERADO[chave].length || ESPERADO[chave].some((c) => !noDisco.has(c));
    });
    assert.deepEqual(divergentes, [], 'a geometria da casca divergiu do protótipo');
});

test('⚠️⚠️ CRITÉRIO 3: as duas colunas têm o MESMO PESO, por IGUALDADE com isca do quase-igual 188_07', () => {
    // ⚠️⚠️ **DECISÃO DE DESENHO DECLARADA:** o README E2 diz *"Duas colunas de
    // mesmo peso, para dar o mesmo destaque a fotos e preço"*, e **REVS-06 diz
    // "Não reequilibrar"**. *"Deixei a de fotos mais estreita porque caberia
    // melhor"* é **FID-02 literal**.
    const classes = M_PAINEL.CLASSES_COLUNAS.split(/\s+/);

    // ⚠️ IGUALDADE DE STRING, não `includes`: `includes('minmax(0,1fr)')` casaria
    // `minmax(0,1fr)_minmax(0,2fr)` também — a família que já mordeu CINCO vezes.
    assert.ok(
        classes.includes('grid-cols-[minmax(0,1fr)_minmax(0,1fr)]'),
        `a grade das colunas não é a de \`:586\`: ${M_PAINEL.CLASSES_COLUNAS}`,
    );

    // ⚠️ ISCA DO QUASE-IGUAL, nas duas direções: as formas desequilibradas NÃO
    // podem passar, e a certa passa.
    const pesoOk = (classe) => classe.split(/\s+/).includes('grid-cols-[minmax(0,1fr)_minmax(0,1fr)]');
    assert.equal(pesoOk('grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)] gap-[16px]'), true, 'a sonda está cega: nem a forma certa ela aceita');
    assert.equal(pesoOk('grid grid-cols-[minmax(0,1fr)_minmax(0,2fr)] gap-[16px]'), false, '`minmax(0,2fr)` passou — a coluna de preço ficaria com o dobro do peso');
    assert.equal(pesoOk('grid grid-cols-[minmax(0,2fr)_minmax(0,1fr)] gap-[16px]'), false, '`minmax(0,2fr)` na primeira passou');
    assert.equal(pesoOk('grid grid-cols-2 gap-[16px]'), false, '`grid-cols-2` passou — ele não tem o `minmax(0,…)` que permite o conteúdo encolher');
    assert.equal(pesoOk('grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)] gap-[16px]'), false, 'TRÊS colunas passaram');
});

test('⚠️ CASCA: slot sem nó NÃO desenha casca vazia (D4) 188_07', () => {
    // ⚠️ A coluna de FOTOS é do plano 188-08 e a de PREÇO do 188-09. Até lá os
    // dois slots chegam vazios — e **slot sem nó não desenha casca, não desenha
    // borda, não desenha "nenhum item"**.
    assert.equal(render(M_PAINEL.ColunasDoPainel, {}), '', 'a grade das colunas desenhou casca vazia');
    assert.equal(render(M_PAINEL.ColunasDoPainel, { colunaDeFotos: null, colunaDePreco: null }), '', 'a grade desenhou com os dois slots nulos');

    // ⚠️ E com UM nó a grade existe — sem esta ponta o `equal('')` acima seria
    // vazio por construção.
    const comUm = render(M_PAINEL.ColunasDoPainel, { colunaDeFotos: React.createElement('b', null, 'fotos') });
    assert.match(comUm, /grid-cols-\[minmax\(0,1fr\)_minmax\(0,1fr\)\]/, 'com um nó a grade não apareceu');
    assert.match(comUm, /<b>fotos<\/b>/, 'o nó de fotos não chegou à grade');

    // ⚠️ E o `<aside>` do painel NÃO carrega corpo nenhum quando não há filhos: a
    // casca de 1140px não desenha bloco vazio.
    const semCorpo = render(M_PAINEL.RaizDoPainel, { navegacao: NAV_MEIO, onIr: nada, onFechar: nada });
    assert.doesNotMatch(semCorpo, /overflow-y-auto/, 'o corpo rolável foi desenhado sem conteúdo nenhum');
});

test('⚠️ CASCA: o clique no BACKDROP fecha, e sem handler não há `onClick` 188_07', () => {
    let fechou = 0;
    const comHandler = M_PAINEL.BackdropDoPainel({ onFechar: () => { fechou += 1; } });
    comHandler.props.onClick();
    assert.equal(fechou, 1, 'o clique fora não fechou o painel (critério 1)');

    // ⚠️ `undefined` NÃO é `onClick` vazio: o React não registra listener nenhum.
    // É o precedente MEDIDO de `CascaDeModal.jsx:216`, em produção.
    assert.equal(M_PAINEL.BackdropDoPainel({ onFechar: null }).props.onClick, undefined, 'sem handler o backdrop ganhou `onClick`');
    assert.equal(M_PAINEL.BackdropDoPainel({ onFechar: 'x' }).props.onClick, undefined, 'handler que não é função virou `onClick`');
});

test('⚠️ CASCA: filho que é OBJETO COMUM é filtrado antes de chegar ao React 188_07', () => {
    // ⚠️⚠️ **Prop de NÓ chegando objeto comum faz o React LANÇAR** (*"Objects are
    // not valid as a React child"*) e **MATA A PÁGINA INTEIRA**. É o conserto
    // MEDIDO de `CascaDeModal.jsx:197`-`:207`, e a razão de o filtro ser elemento
    // a elemento: um array de filhos pode ter um objeto no meio.
    assert.equal(M_PAINEL.filhosSeguros({}), null, 'objeto comum passou como filho');
    assert.equal(M_PAINEL.filhosSeguros({ toString: null }), null, 'objeto adverso passou como filho');
    assert.equal(M_PAINEL.filhosSeguros([{}, {}]), null, 'array de objetos passou como filho');
    assert.deepEqual(M_PAINEL.filhosSeguros(['ok', {}, 7]), ['ok', 7], 'o objeto no MEIO do array não foi filtrado');
    assert.equal(M_PAINEL.filhosSeguros(null), null, '`null` deixou de ser filho legítimo');
    assert.equal(M_PAINEL.filhosSeguros('texto'), 'texto', 'string deixou de ser filho legítimo');

    // ⚠️ E o render de verdade NÃO lança com cada forma adversa.
    for (const filho of [{}, [{}], { toString: null }, [1, {}, 'x'], NaN, undefined]) {
        assert.doesNotThrow(
            () => render(M_PAINEL.CorpoDoPainel, { children: filho }),
            `o corpo LANÇOU com filho adverso (${typeof filho})`,
        );
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. COL-04 / W11 — o helper único de escrita, e o 422 que NÃO é engolido
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ COL-04: `versao_esperada` entra em TODA escrita, e o corpo não a sobrescreve 188_07', () => {
    STUB.CHAMADAS.length = 0;

    assert.equal(M_PAINEL.CHAVE_DA_VERSAO, 'versao_esperada', 'a chave da versão mudou de nome — o servidor não a leria');

    // ⚠️ A regra é LIDA do serviço da 186, não inventada: dois literais para a
    // mesma regra divergiriam na primeira edição.
    const servico = fs.readFileSync(path.resolve(RAIZ, REL_SERVICO_APROVACAO), 'utf8');
    assert.match(servico, new RegExp(`REGRA_VERSAO_DIVERGENTE = '${M_PAINEL.REGRA_VERSAO_DIVERGENTE}'`), 'a regra do cliente divergiu da do servidor');

    const ok = M_PAINEL.escreverNoPainel({ rota: 'r.aprovar', parametros: { company: 9 }, corpo: { oferta: 12 }, versao: 3 });
    assert.equal(ok, true, 'a escrita com versão não foi disparada');
    assert.equal(STUB.CHAMADAS.length, 1, `a escrita disparou ${STUB.CHAMADAS.length} chamadas, esperava 1`);
    assert.equal(STUB.CHAMADAS[0][0], 'post', 'a escrita não foi um POST');
    assert.deepEqual(STUB.CHAMADAS[0][2], { oferta: 12, versao_esperada: 3 }, 'a versão não entrou no corpo');

    // ⚠️⚠️ A versão entra DEPOIS do espalhamento: um corpo que trouxesse
    // `versao_esperada` por engano **não pode** vencer a versão que a tela viu.
    STUB.CHAMADAS.length = 0;
    M_PAINEL.escreverNoPainel({ rota: 'r.aprovar', corpo: { versao_esperada: 99, x: 1 }, versao: 3 });
    assert.deepEqual(STUB.CHAMADAS[0][2], { versao_esperada: 3, x: 1 }, 'o corpo sobrescreveu a versão que a tela viu');

    // ⚠️ SEM versão NADA é enviado — `exigirVersao()` recebendo `null` LIBERA a
    // escrita (o ramo de uso interno/console), que é literalmente "sobrescrever em
    // silêncio".
    for (const versao of [null, undefined, '3', 3.5, NaN, {}, []]) {
        STUB.CHAMADAS.length = 0;
        assert.equal(M_PAINEL.escreverNoPainel({ rota: 'r.aprovar', versao }), false, `a escrita passou com versão inválida (${typeof versao})`);
        assert.equal(STUB.CHAMADAS.length, 0, 'uma chamada foi disparada sem versão válida');
    }

    // ⚠️ E sem rota também não.
    for (const rota of [null, '', '   ', 7, {}, []]) {
        STUB.CHAMADAS.length = 0;
        assert.equal(M_PAINEL.escreverNoPainel({ rota, versao: 3 }), false, `a escrita passou com rota inválida (${typeof rota})`);
        assert.equal(STUB.CHAMADAS.length, 0, 'uma chamada foi disparada sem rota válida');
    }

    // ⚠️ Pedido inteiro adverso não derruba nada.
    for (const pedido of [null, undefined, 'x', 7, [], { toString: null }]) {
        assert.doesNotThrow(() => M_PAINEL.escreverNoPainel(pedido), `o helper LANÇOU com pedido adverso (${typeof pedido})`);
    }
});

test('⚠️⚠️ COL-04: o 422 de versão divergente chama `onConflito` e NUNCA é engolido 188_07', () => {
    STUB.CHAMADAS.length = 0;
    const vistos = [];
    M_PAINEL.escreverNoPainel({ rota: 'r.aprovar', versao: 3, onConflito: (m) => vistos.push(m) });

    const opcoes = STUB.CHAMADAS[0][3];
    assert.equal(typeof opcoes.onError, 'function', 'o helper não registrou `onError` — o 422 seria ENGOLIDO');

    // ⚠️ A mensagem é a do SERVIDOR (ela diz as DUAS versões). O texto nunca é
    // remontado no cliente.
    const DO_SERVIDOR = `${M_PAINEL.REGRA_VERSAO_DIVERGENTE}: Esta oferta mudou enquanto você revisava (você viu a versão 3, a atual é 4).`;
    opcoes.onError({ versao_esperada: DO_SERVIDOR });
    assert.deepEqual(vistos, [DO_SERVIDOR], 'o conflito de versão não chegou ao `onConflito` — a pessoa acharia que aprovou');

    // ⚠️ E um erro QUALQUER também não é engolido: a primeira mensagem sobe.
    vistos.length = 0;
    opcoes.onError({ preco: 'O preço é obrigatório.' });
    assert.deepEqual(vistos, ['O preço é obrigatório.'], 'erro comum foi engolido');

    // ⚠️ Sacola adversa não derruba o clique — a tela preta da 187-06 foi NO CLIQUE.
    vistos.length = 0;
    for (const sacola of [null, undefined, 'x', 7, [], {}, { a: 7 }, { toString: null }, { a: { toString: null } }]) {
        assert.doesNotThrow(() => opcoes.onError(sacola), `o \`onError\` LANÇOU com sacola adversa (${typeof sacola})`);
    }
    // ⚠️ `null` é um aviso legítimo de "deu erro e não sei qual" — nunca silêncio.
    assert.ok(vistos.length >= 1, 'nenhuma das sacolas adversas chamou `onConflito` — o erro foi engolido');

    // ⚠️ Sem `onConflito` o helper não LANÇA (mas também não engole o que não há
    // para quem avisar): o chamador é que decide se oferece a ação.
    STUB.CHAMADAS.length = 0;
    M_PAINEL.escreverNoPainel({ rota: 'r.aprovar', versao: 3 });
    assert.doesNotThrow(() => STUB.CHAMADAS[0][3].onError({ x: 'y' }), 'o `onError` LANÇOU sem `onConflito`');
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. RENDER ADVERSO — a TELA PRETA, em TODOS os campos e TAMBÉM no clique
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ REND-01: nenhum campo adverso derruba a casca, nem no render nem no CLIQUE 188_07', () => {
    // ⚠️⚠️ A TELA PRETA voltou **SEIS** vezes neste projeto — quatro na Fase 180 e
    // uma na 187-06, essa **NO CLIQUE**. `String(valor)` LANÇA com
    // `{toString: null}`; `hasOwnProperty` LANÇA; **qualquer `obj[chave]`** com
    // chave adversa LANÇA; e `textoSeguro(NaN)` devolve a STRING `'NaN'`.
    const ADVERSOS = [{}, null, undefined, NaN, [], { toString: null }, 0, '', true, -1, 1e99, { a: { toString: null } }];
    const CAMPOS = ['ids', 'anterior_id', 'proxima_id', 'posicao', 'total', 'pos'];

    for (const campo of CAMPOS) {
        for (const valor of ADVERSOS) {
            // ⚠️ Flag DENTRO do laço.
            const nav = { ...NAV_MEIO, [campo]: valor };
            let html = '';
            assert.doesNotThrow(
                () => { html = render(M_TOPO.default, { navegacao: nav, onIr: nada, onFechar: nada }); },
                // ⚠️ **NUNCA `String()`/`JSON.stringify()` na mensagem**: eles
                // LANÇAM com o próprio valor adverso, ou mentem (`NaN` vira `null`).
                `o topo LANÇOU com ${campo} adverso (typeof ${typeof valor})`,
            );
            assert.doesNotMatch(
                html,
                /\[object Object\]|NaN|undefined|Invalid Date/,
                `o topo imprimiu lixo com ${campo} adverso (typeof ${typeof valor})`,
            );
        }
    }

    // ⚠️ A prop INTEIRA adversa.
    for (const nav of ADVERSOS) {
        let html = '';
        assert.doesNotThrow(() => { html = render(M_TOPO.default, { navegacao: nav, onIr: nada, onFechar: nada }); }, `o topo LANÇOU com navegacao adversa (typeof ${typeof nav})`);
        assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `o topo imprimiu lixo com navegacao adversa (typeof ${typeof nav})`);
        // ⚠️ E o × continua lá: fechar tem de ser sempre possível.
        assert.match(html, />×</, 'o × desapareceu com navegacao adversa — o painel ficaria sem saída visível');
    }

    // ⚠️ A RAIZ do painel, com o `painel` inteiro adverso.
    for (const nav of ADVERSOS) {
        let html = '';
        assert.doesNotThrow(() => { html = render(M_PAINEL.RaizDoPainel, { navegacao: nav, onIr: nada, onFechar: nada }); }, `a raiz LANÇOU com navegacao adversa (typeof ${typeof nav})`);
        assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `a raiz imprimiu lixo com navegacao adversa (typeof ${typeof nav})`);
    }

    // ⚠️⚠️ E **NO CLIQUE**: os handlers exportados são chamados com cada forma
    // adversa. `renderToStaticMarkup` não dispara `onClick`.
    for (const valor of ADVERSOS) {
        assert.doesNotThrow(() => M_TOPO.SetaDoPainel({ destino: valor, glifo: '↓', titulo: 'x', onIr: nada }), `a seta LANÇOU com destino adverso (typeof ${typeof valor})`);
        assert.doesNotThrow(() => M_PAINEL.BackdropDoPainel({ onFechar: valor }), `o backdrop LANÇOU com handler adverso (typeof ${typeof valor})`);
        assert.doesNotThrow(() => M_PAINEL.objetoDoPainel(valor), 'o guarda de objeto LANÇOU');
        assert.doesNotThrow(() => M_PAINEL.inteiroDoPainel(valor), 'o guarda de inteiro LANÇOU');
        assert.doesNotThrow(() => M_TOPO.textoDoTopo(valor), 'o guarda de texto LANÇOU');
    }

    // ⚠️ `textoDoTopo(NaN)` NÃO pode devolver `'NaN'` — `typeof NaN` é `'number'`,
    // e a armadilha é exatamente essa.
    assert.equal(M_TOPO.textoDoTopo(NaN), null, '`textoDoTopo(NaN)` devolveu algo — a string "NaN" iria para a tela');
    assert.equal(M_TOPO.textoDoTopo(0), null, '`textoDoTopo(0)` devolveu algo');
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. TERRITÓRIO — as ausências, recortadas à lista CONSTANTE deste plano
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ TERRITÓRIO: as ausências deste plano, recortadas aos SEUS arquivos 188_07', () => {
    // ⚠️⚠️ **GATE MAIS LARGO QUE O INVARIANTE — DOZE ocorrências neste projeto**,
    // uma delas **BARROU UM DEPLOY** (`lucro_liquido` é campo LEGÍTIMO do Portal).
    // ➡️ O recorte é a lista CONSTANTE dos arquivos DESTE plano.
    const PROIBIDOS = [
        // COL-02 / critério 13 — o estado NÃO mora no navegador.
        [/localStorage/, 'localStorage — a oferta aberta vive na URL e a revisão parcial no SERVIDOR'],
        [/sessionStorage/, 'sessionStorage — mesma regra'],
        [/Restaurar dados de exemplo/i, '"Restaurar dados de exemplo" é recurso de PROTÓTIPO'],
        [/ecf-aprovacao-v1/, 'a chave de `localStorage` do protótipo (`:914`)'],
        // ⚠️ `String(` LANÇA com `{toString:null}`; há gate da 187-04 cobrando na página.
        [/\bString\(/, '`String(` LANÇA com `{toString: null}`'],
        [/hasOwnProperty/, '`hasOwnProperty` LANÇA — a busca de chave coage para string'],
        [/JSON\.stringify/, '`JSON.stringify` mente (`NaN` vira `null`) e LANÇA em cíclico'],
        // VOC-03 — o vocabulário travado.
        [/lucro\s+l[ií]quido/i, '"lucro líquido" — o travado é "Resultado estimado por venda"'],
        [/lucro_l[ií]quido/i, 'idem, na forma de identificador'],
        // Fase 189 (lightbox) e 192 (gerar com IA) NÃO são antecipadas.
        [/⤢/, 'o "⤢ Ampliar" é da Fase 189'],
        [/Ampliar/, 'idem'],
        [/Gerar novamente/i, 'o "✦ Gerar novamente com IA" é da Fase 192'],
        // Decisão 3 do usuário: APROVAR JÁ PUBLICA, sem segundo clique.
        [/Confirmar publica[çc][ãa]o/i, 'segundo passo — aprovar JÁ PUBLICA, sem desfazer'],
        [/Desfazer/i, 'idem'],
        [/Cancelar aprova[çc][ãa]o/i, 'idem'],
        // Território PROIBIDO desta fase.
        [/ArquivosDeImagem|ImagemAssetService|Acervo/, 'o acervo está VETADO (v28 virou para o R2 privado)'],
        [/MlPublicacaoService/, 'a escrita no ML é da Fase 193'],
        [/useCriativosDoPublicador/, 'o Creative Engine está VETADO'],
    ];

    const achados = FONTES_DO_PLANO.flatMap(([rel, fonte]) => PROIBIDOS.filter(([re]) => re.test(fonte)).map(([re, por]) => `${rel}: ${re.source} (${por})`));
    assert.deepEqual(achados, [], 'ausência violada nos arquivos deste plano');

    // ⚠️⚠️ ISCA NAS DUAS DIREÇÕES: cada regex contra um caso que ela DEVE casar.
    const CEBO = `
        localStorage.getItem('ecf-aprovacao-v1'); sessionStorage.clear();
        <button>Restaurar dados de exemplo</button>
        String(x); Object.prototype.hasOwnProperty.call(o, k); JSON.stringify(o);
        'lucro líquido'; 'lucro_liquido';
        <button>⤢ Ampliar</button> <button>✦ Gerar novamente com IA</button>
        <button>Confirmar publicação</button> <button>Desfazer</button> <button>Cancelar aprovação</button>
        import { ArquivosDeImagem } from 'x'; ImagemAssetService; Acervo; MlPublicacaoService;
        import x from './useCriativosDoPublicador';
    `;
    const cegas = PROIBIDOS.filter(([re]) => !re.test(CEBO)).map(([re]) => re.source);
    assert.deepEqual(cegas, [], 'regex que não casa o próprio cebo — "nenhum achado" não significa nada');

    // ⚠️ E o RECORTE tem isca também: a lista varrida não está vazia e tem os DOIS
    // arquivos. Sonda sobre lista vazia é vazia por construção.
    // ⚠⚠ **LISTA CONSTANTE, nunca uma CONTAGEM.** A primeira redação deste
    // bloco dizia `length === 2`, e a tarefa 2 (que criou o terceiro `.jsx`) o
    // REPROVOU — é a régua que sobe a cada tarefa, e que treina o executor
    // seguinte a ajustar número em vez de consertar código (learnings §4b.2).
    assert.deepEqual(
        FONTES_DO_PLANO.map(([rel]) => rel),
        [REL_PAINEL, REL_TOPO, REL_IDENT],
        'a lista varrida deixou de ser EXATAMENTE os três arquivos deste plano',
    );
    const vazias = FONTES_DO_PLANO.filter(([, f]) => f.trim().length < 500).map(([rel]) => rel);
    assert.deepEqual(vazias, [], 'fonte lida veio vazia/curta — a leitura está quebrada e as ausências passariam caladas');
});

test('⚠️ TERRITÓRIO: nenhuma PEÇA nova sob `resources/` é `.js`, e nada foi instalado 188_07', () => {
    // ⚠️⚠️ **O INVARIANTE CERTO É "nenhuma PEÇA nova é `.js`"** — a 187-11 mediu
    // que *"nenhum arquivo novo é `.js`"* era **literalmente FALSO** (há 9 `.js`,
    // **todos TESTES**). `tailwind.config.js` varre `*.jsx` e **NÃO** `*.js`:
    // classe num `.js` não gera CSS e **não existe para a tela**, com render verde.
    const novos = execFileSync('git', ['status', '--porcelain', '--', 'resources/'], { cwd: RAIZ, encoding: 'utf8' })
        .split(/\r?\n/)
        .map((l) => l.slice(3).trim())
        .filter((c) => c !== '');

    const pecasEmJs = novos.filter((c) => c.endsWith('.js') && c.startsWith('resources/'));
    assert.deepEqual(pecasEmJs, [], 'peça nova sob `resources/` em `.js` — a classe não geraria CSS');

    // ⚠️ T-188-SC — este plano **não instala nada**. Sonda por FORMA.
    const dependencias = execFileSync('git', ['diff', '--numstat', 'HEAD', '--', 'package.json', 'package-lock.json'], { cwd: RAIZ, encoding: 'utf8' }).trim();
    assert.equal(dependencias, '', 'package.json/package-lock.json foram tocados — este plano não instala nada');

    // ⚠️ E nenhuma migration: esta fase não tem migration nenhuma.
    const migrations = execFileSync('git', ['status', '--porcelain', '--', 'database/migrations'], { cwd: RAIZ, encoding: 'utf8' }).trim();
    assert.equal(migrations, '', 'migration apareceu — esta fase NÃO tem migration');
});

test('⚠️ TERRITÓRIO: o ESCOPO DO ASSUNTO `(188-07)`, em duas passadas 188_07', () => {
    // ⚠️⚠️ **"Prova de território por RANGE" NÃO PASSA nesta árvore** — a 187-11
    // mediu 111 commits no range, **43 da sessão paralela**, e o diff cru acusava
    // **12 dos 60 alvos vetados**. ⚠️ **E o AUTOR não discrimina nada:** as três
    // máquinas desta árvore commitam com o MESMO `user.name` (`MB.ECF-100376`).
    // ➡️ A prova é por **ESCOPO DO ASSUNTO**: só os commits cujo assunto traz
    // `(188-07)`.
    const log = execFileSync('git', ['log', '--format=%H%x09%s', '-80'], { cwd: RAIZ, encoding: 'utf8' })
        .split(/\r?\n/)
        .filter((l) => l.trim() !== '')
        .map((l) => { const [h, ...s] = l.split('\t'); return { hash: h, assunto: s.join('\t') }; });

    const meus = log.filter((c) => c.assunto.includes('(188-07)'));

    // ⚠️ PASSADA 1: cada commit do assunto só toca arquivo deste plano.
    const PERMITIDOS = [
        REL_PAINEL, REL_TOPO, `${DIR_APROV}/IdentificacaoDaOferta.jsx`,
        'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx',
        'tests/js/publicador-vocabulario-novo.test.js',
        'tests/js/publicador-painel-casca-188.test.js',
        'tests/gates/contagens-188-07.txt',
        '.planning/phases/188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece/188-07-SUMMARY.md',
        '.planning/phases/188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece/188-FID01.md',
    ];
    const foraDoEscopo = meus.flatMap((c) => execFileSync('git', ['show', '--name-only', '--format=', c.hash], { cwd: RAIZ, encoding: 'utf8' })
        .split(/\r?\n/)
        .map((f) => f.trim())
        .filter((f) => f !== '' && !PERMITIDOS.includes(f))
        .map((f) => `${c.hash.slice(0, 8)}: ${f}`));
    assert.deepEqual(foraDoEscopo, [], 'commit do assunto `(188-07)` tocou arquivo fora deste plano');

    // ⚠️ PASSADA 2, INDEPENDENTE: nenhum arquivo INTOCÁVEL aparece em commit deste
    // assunto. É a mesma pergunta por outro caminho — se as duas passadas
    // discordassem, uma delas estaria cega.
    const INTOCAVEIS = [REL_HEADER, REL_LAYOUT, REL_ZINDEX_ORDEM, REL_CASCA_MODAL, REL_TOAST, REL_BARRA, 'tests/js/estrutura-meus-anuncios.test.js', '.planning/ROADMAP.md', '.planning/STATE.md'];
    const tocados = meus.flatMap((c) => execFileSync('git', ['show', '--name-only', '--format=', c.hash], { cwd: RAIZ, encoding: 'utf8' })
        .split(/\r?\n/)
        .map((f) => f.trim())
        .filter((f) => INTOCAVEIS.includes(f))
        .map((f) => `${c.hash.slice(0, 8)}: ${f}`));
    assert.deepEqual(tocados, [], 'commit do assunto `(188-07)` tocou arquivo INTOCÁVEL');

    // ⚠️⚠️ ISCA NAS DUAS DIREÇÕES. Direção 1: o escopo ACHA commits de outros
    // assuntos quando perguntado por eles (prova de que o `git log` não veio
    // vazio). Direção 2: um assunto que não existe dá ZERO.
    assert.ok(log.length > 10, `o \`git log\` devolveu ${log.length} commits — a sonda está cega`);
    assert.equal(log.filter((c) => c.assunto.includes('(188-zz)')).length, 0, 'a isca do escopo casou um assunto que não existe');
    assert.ok(
        log.filter((c) => /\(188-0\d\)/.test(c.assunto)).length >= 1,
        'nenhum commit desta FASE no log — a sonda do escopo não discrimina nada',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 8b. OS ATALHOS DE TECLADO — pela FUNÇÃO PURA, com o runtime DECLARADO
//     NÃO PROVADO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ TECLADO: o `Esc` fecha o MODAL primeiro, e dispara TAMBÉM digitando 188_07', () => {
    // ⚠️⚠️ **O RUNTIME NÃO É PROVADO:** não há navegador nesta máquina, e
    // `renderToStaticMarkup` não dispara evento nenhum. O que está provado é a
    // FUNÇÃO que decide. Esta fase **não pode afirmar "a tecla funciona"**.
    const r = M_PAINEL.resolverAtalhoDoPainel;

    // `:1014` — a cadeia é `modal → painel` (o degrau do lightbox é da Fase 189 e
    // está DECLARADO no docblock, não antecipado).
    assert.deepEqual(r({ tecla: 'Escape', temModal: true }), { acao: M_PAINEL.ACOES.FECHAR_MODAL, oferta: null, imagem: null }, 'com modal aberto o Esc tem de fechar O MODAL primeiro');
    assert.deepEqual(r({ tecla: 'Escape', temModal: false }), { acao: M_PAINEL.ACOES.FECHAR_PAINEL, oferta: null, imagem: null }, 'sem modal o Esc tem de fechar o PAINEL');

    // ⚠️⚠️ **DIVERGÊNCIA DECLARADA DO PLANO, e o PROTÓTIPO GANHA.** O `<behavior>`
    // do plano diz que os QUATRO atalhos não disparam em campo de texto. Medido
    // por `sed`: `:1015` (`if (typing) return;`) vem **DEPOIS** do bloco do
    // `Escape` (`:1014`), então o `Escape` dispara **também digitando**. O
    // contrário TRANCARIA a pessoa dentro de um campo de texto de modal.
    assert.deepEqual(
        r({ tecla: 'Escape', temModal: true, alvoEhCampo: true }),
        { acao: M_PAINEL.ACOES.FECHAR_MODAL, oferta: null, imagem: null },
        'o Esc foi barrado em campo de texto — `:1015` vem DEPOIS de `:1014`, e sem ele a pessoa fica trancada no campo',
    );
});

test('⚠️ TECLADO: ↑/↓ leem o ID do SERVIDOR e param nas pontas 188_07', () => {
    const r = M_PAINEL.resolverAtalhoDoPainel;
    const base = { anteriorId: 11, proximaId: 13 };

    // ⚠️⚠️ O destino é **LIDO** de `anterior_id`/`proxima_id` (plano 188-02) — a
    // tela **NUNCA recalcula a fila** nem filtra `preparando` no cliente, que é a
    // segunda verdade que aquele plano existe para impedir.
    // ⚠️ `preparando` sai da NAVEGAÇÃO mas CONTINUA nos `ids` do LOTE (divergência
    // D9 da Fase 187, já no ar): são listas diferentes, e não se unificam.
    assert.deepEqual(r({ tecla: 'ArrowUp', ...base }), { acao: M_PAINEL.ACOES.IR_PARA_OFERTA, oferta: 11, imagem: null }, 'o ↑ não levou o `anterior_id` do servidor');
    assert.deepEqual(r({ tecla: 'ArrowDown', ...base }), { acao: M_PAINEL.ACOES.IR_PARA_OFERTA, oferta: 13, imagem: null }, 'o ↓ não levou o `proxima_id` do servidor');

    // ⚠️ AS DUAS PONTAS, e elas se DISTINGUEM: `null` é "a tecla não é desta peça",
    // então nem `preventDefault` acontece.
    assert.equal(r({ tecla: 'ArrowUp', anteriorId: null, proximaId: 13 }), null, 'na PRIMEIRA da fila o ↑ navegou');
    assert.equal(r({ tecla: 'ArrowDown', anteriorId: 11, proximaId: null }), null, 'na ÚLTIMA da fila o ↓ navegou');
    assert.notEqual(r({ tecla: 'ArrowDown', anteriorId: null, proximaId: 13 }), null, 'na PRIMEIRA o ↓ deveria navegar — a fixture não distingue as pontas');

    // ⚠️ A oferta em `preparando`: FORA da navegação, as duas pontas nulas.
    assert.equal(r({ tecla: 'ArrowUp', anteriorId: null, proximaId: null }), null, 'fora da navegação o ↑ navegou');
    assert.equal(r({ tecla: 'ArrowDown', anteriorId: null, proximaId: null }), null, 'fora da navegação o ↓ navegou');

    // ⚠️ `:1017` exige `!S.modal`: navegar a fila por trás de um modal aberto
    // trocaria a oferta que o modal está confirmando.
    assert.equal(r({ tecla: 'ArrowUp', temModal: true, ...base }), null, 'o ↑ navegou com modal aberto');
    assert.equal(r({ tecla: 'ArrowDown', temModal: true, ...base }), null, 'o ↓ navegou com modal aberto');

    // ⚠️ `:1015` — campo de texto engole as setas.
    for (const tecla of ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight']) {
        assert.equal(r({ tecla, alvoEhCampo: true, ...base, imagemAtual: 0, totalDeImagens: 5 }), null, `o ${tecla} disparou com o foco num campo de texto`);
    }

    // ⚠️ ISCA: a guarda de campo é por lista FECHADA e as três tags estão nela.
    assert.deepEqual(M_PAINEL.TAGS_DE_CAMPO, ['INPUT', 'TEXTAREA', 'SELECT'], 'a lista de tags de `:1013` mudou');
});

test('⚠️⚠️ TECLADO: ←/→ trocam a foto, CLAMPADAS nas DUAS pontas 188_07', () => {
    const r = M_PAINEL.resolverAtalhoDoPainel;

    assert.deepEqual(r({ tecla: 'ArrowRight', imagemAtual: 0, totalDeImagens: 5 }), { acao: M_PAINEL.ACOES.TROCAR_IMAGEM, oferta: null, imagem: 1 }, 'o → não avançou a foto');
    assert.deepEqual(r({ tecla: 'ArrowLeft', imagemAtual: 3, totalDeImagens: 5 }), { acao: M_PAINEL.ACOES.TROCAR_IMAGEM, oferta: null, imagem: 2 }, 'o ← não voltou a foto');

    // ⚠️⚠️ AS DUAS PONTAS. O protótipo clampa em DOIS lugares diferentes: `:1018`
    // clampa só o PISO (`Math.max(0, …)`) e o TETO é clampado na LEITURA (`:1293`,
    // `imgs[Math.min(selIdx, imgs.length - 1)] || null`). Resolver os dois aqui é
    // o que impede índice fora da lista — que daria bloco VAZIO na tela.
    assert.equal(r({ tecla: 'ArrowLeft', imagemAtual: 0, totalDeImagens: 5 }), null, 'o ← passou do PISO (`:1018`)');
    assert.equal(r({ tecla: 'ArrowRight', imagemAtual: 4, totalDeImagens: 5 }), null, 'o → passou do TETO (`:1293`)');

    // ⚠⚠ **Índice JÁ fora da lista volta para DENTRO, nunca para fora** — e isto
    // foi conserto da própria sonda: a primeira redação esperava `null` no → a
    // partir de 99, e o CÓDIGO estava certo. Índice 99 numa galeria de 5 é estado
    // inválido; a tecla tem de trazer a seleção para a última foto que EXISTE, em
    // vez de não fazer nada e deixar a pessoa olhando um bloco vazio. É o clamp de
    // LEITURA do protótipo (`:1293`) aplicado nas DUAS direções.
    const doAlto = { acao: M_PAINEL.ACOES.TROCAR_IMAGEM, oferta: null, imagem: 4 };
    assert.deepEqual(r({ tecla: 'ArrowRight', imagemAtual: 99, totalDeImagens: 5 }), doAlto, 'índice acima do teto não foi trazido para dentro pelo →');
    assert.deepEqual(r({ tecla: 'ArrowLeft', imagemAtual: 99, totalDeImagens: 5 }), doAlto, 'índice acima do teto não foi trazido para dentro pelo ←');
    // ⚠ E índice negativo volta para o PISO, pelo mesmo caminho.
    assert.deepEqual(r({ tecla: 'ArrowLeft', imagemAtual: -7, totalDeImagens: 5 }), { acao: M_PAINEL.ACOES.TROCAR_IMAGEM, oferta: null, imagem: 0 }, 'índice negativo não foi trazido para o piso');

    // ⚠️ Galeria vazia: não há o que trocar (IMG-12 é bloqueio, não índice).
    for (const total of [0, null, undefined, -1, NaN, {}, [], '5']) {
        assert.equal(r({ tecla: 'ArrowRight', imagemAtual: 0, totalDeImagens: total }), null, `o → disparou com total adverso (${typeof total})`);
    }

    // ⚠️ Com modal aberto, nada (`:1018` exige `!S.modal`).
    assert.equal(r({ tecla: 'ArrowRight', temModal: true, imagemAtual: 0, totalDeImagens: 5 }), null, 'o → trocou a foto com modal aberto');

    // ⚠️ Tecla que não é desta peça devolve `null` — nada de `preventDefault`, que
    // tiraria a rolagem da pessoa sem motivo.
    for (const tecla of ['Enter', 'Tab', ' ', 'a', 'PageDown', 'Home', null, undefined, 7, {}, []]) {
        assert.equal(r({ tecla, anteriorId: 11, proximaId: 13, imagemAtual: 0, totalDeImagens: 5 }), null, `a tecla ${typeof tecla} foi consumida indevidamente`);
    }

    // ⚠️ Evento inteiro adverso não derruba o CLIQUE/TECLA.
    for (const evento of [null, undefined, 'x', 7, [], { toString: null }, { tecla: { toString: null } }]) {
        assert.doesNotThrow(() => r(evento), `o resolvedor LANÇOU com evento adverso (${typeof evento})`);
        assert.equal(r(evento), null, `o resolvedor devolveu ação com evento adverso (${typeof evento})`);
    }
});

test('⚠️ TECLADO: o runtime está DECLARADO como não provado, e o lightbox NÃO foi antecipado 188_07', () => {
    // ⚠️⚠️ Bloco que lê COMENTÁRIO DE PROPÓSITO (a inversão está declarada no topo
    // deste arquivo): a declaração de que o runtime não é provado **é** o artefato,
    // porque afirmar "a tecla funciona" é o que esta fase não pode fazer.
    const EXIGIDOS = ['NÃO ESTÁ PROVADO', 'não há navegador', 'função pura', 'Fase 189'];
    const faltando = EXIGIDOS.filter((t) => !CRUA_PAINEL.toLowerCase().includes(t.toLowerCase()));
    assert.deepEqual(faltando, [], 'a declaração de que o runtime do teclado NÃO é provado saiu do docblock');

    // ⚠️ E o degrau do lightbox é PREVISTO, nunca IMPLEMENTADO: a ação não existe.
    assert.equal(Object.values(M_PAINEL.ACOES).includes('fechar-lightbox'), false, 'o lightbox da Fase 189 foi antecipado');
    assert.deepEqual(
        Object.keys(M_PAINEL.ACOES).sort(),
        ['FECHAR_MODAL', 'FECHAR_PAINEL', 'IR_PARA_OFERTA', 'TROCAR_IMAGEM'],
        'a lista de ações do resolvedor mudou — ela é FECHADA de propósito',
    );

    // ⚠️ E o listener existe no composto (ele não é prova de runtime, mas a sua
    // AUSÊNCIA seria prova de que nada escuta).
    assert.match(FONTE_PAINEL, /window\.addEventListener\('keydown'/, 'ninguém escuta o teclado');
    assert.match(FONTE_PAINEL, /window\.removeEventListener\('keydown'/, 'o listener não é removido — cada abertura somaria um');

    // ═══ ⚠⚠ A PREMISSA TRAVADA, porque o runtime É INOBSERVÁVEL AQUI ══════
    //
    // ⚠⚠ **ACHADO DA CAMPANHA DE MUTAÇÃO desta wave, declarado em vez de
    // escondido.** A guarda de `tagName` tem DUAS metades: a **decisão** (`if
    // (e.alvoEhCampo === true) return null;`), que é função PURA e está provada
    // acima, e a **composição** (ler `evento.target.tagName` e casar com
    // `TAGS_DE_CAMPO`), que vive dentro do `useEffect` e **só roda no navegador**.
    // ➡️ Mutar a composição **SOBREVIVERIA**: sem navegador, nenhum teste de
    // comportamento a alcança.
    // ⚠ **Sobrevivente INOBSERVÁVEL não se descarta: TRAVA-SE.** Isto aqui é
    // **proxy de FONTE** — e proxy de fonte **NÃO prova comportamento**. O que ele
    // prova é que a composição não foi SILENCIOSAMENTE desfeita; quem prova o
    // comportamento é a conferência humana na conta 459. **Está declarado para
    // ninguém confundir as duas coisas.**
    assert.match(
        FONTE_PAINEL,
        /alvoEhCampo: TAGS_DE_CAMPO\.includes\(typeof tag === 'string' \? tag : ''\)/,
        'a composição da guarda de `tagName` foi desfeita — as setas passariam a navegar por baixo de quem digita, e o runtime NÃO é provado nesta máquina',
    );
    // ⚠ E o `|| ''` do protótipo (`:1013`) tem equivalente: `e.target.tagName` pode
    // vir `undefined`.
    assert.match(FONTE_PAINEL, /const tag = alvo === null \|\| typeof alvo !== 'object' \? '' : alvo\.tagName;/, 'a guarda de tipo do `tagName` saiu — `undefined.tagName` LANÇA');
});

// ═══════════════════════════════════════════════════════════════════════════
// 8c. A IDENTIFICAÇÃO — os 5 chips, o losango só do Premium, e a caixa de falha
// ═══════════════════════════════════════════════════════════════════════════

// ⚠️⚠️ A FIXTURE do bloco `identificacao` — o payload REAL de
// `PainelDeRevisaoService::identificacao()`. **Ela DISCRIMINA**: Premium (para o
// losango existir), combo (para o formato ser o de `×N`) e um andamento do
// `live` (para as cores do chip serem as do estado, não as do neutro).
const IDENT = {
    produto: 'Poltrona Beny Decorativa',
    sku: 'PBD-114-K2',
    titulo: 'Poltrona Beny Decorativa Veludo Cinza Pés Madeira',
    chips: {
        modalidade: { rotulo: 'Premium', premium: true },
        formato: 'Combo ×2 · 2 un. por oferta',
        origem: 'Preparado automaticamente',
        versao: 'Versão 3',
        andamento: { chave: 'aguardando_aprovacao', rotulo: 'Aguardando aprovação' },
    },
    responsavel: 'Mariana Alves',
    espera: 'aguardando há 3 dias',
};

const IDENT_CLASSICO = {
    ...IDENT,
    chips: { ...IDENT.chips, modalidade: { rotulo: 'Clássico', premium: false } },
};

const FALHA = { mensagem: 'O atributo BRAND é obrigatório para esta categoria.', rotulo_acao: 'Tentar publicar novamente' };

test('⚠️ IDENTIFICAÇÃO: os CINCO chips, NA ORDEM de `:568`-`:572` 188_07', () => {
    const chips = M_IDENT.chipsDaOferta(IDENT.chips);
    assert.deepEqual(
        chips.map((c) => c.chave),
        ['modalidade', 'formato', 'origem', 'versao', 'andamento'],
        'a ORDEM dos cinco chips divergiu do protótipo — ele GANHA em ORDEM',
    );

    const html = render(M_IDENT.default, { identificacao: IDENT, falha: null });

    // ⚠️ Os cinco RÓTULOS, e a ORDEM deles no HTML.
    const rotulos = ['Premium', 'Combo ×2 · 2 un. por oferta', 'Preparado automaticamente', 'Versão 3', 'Aguardando aprovação'];
    const faltando = rotulos.filter((r) => !html.includes(r));
    assert.deepEqual(faltando, [], 'rótulo de chip desapareceu da tela');
    const posicoes = rotulos.map((r) => html.indexOf(r));
    assert.deepEqual(posicoes, [...posicoes].sort((a, b) => a - b), 'a ordem dos chips no HTML não é a de `:568`-`:572`');

    // ⚠️ Produto, SKU (em monoespaçada), título, responsável e espera.
    assert.match(html, /Poltrona Beny Decorativa/, 'o produto desapareceu (`:565`)');
    assert.match(html, /font-pub-mono[^"]*">PBD-114-K2</, 'o SKU não está em monoespaçada (`:565`)');
    assert.match(html, /Mariana Alves/, 'o responsável desapareceu (`:575`)');
    assert.match(html, /aguardando há 3 dias/, 'a espera desapareceu (`:575`)');

    // ⚠️ Chip sem rótulo NÃO entra — chip vazio de 24px é D4 violado. ⚠️ Mas o de
    // ANDAMENTO entra sempre, porque `andamentoDe()` nunca devolve texto vazio.
    assert.deepEqual(M_IDENT.chipsDaOferta({ formato: 'x' }).map((c) => c.chave), ['formato', 'andamento'], 'chip sem rótulo entrou, ou o andamento (que NUNCA é vazio) saiu');
    assert.deepEqual(M_IDENT.chipsDaOferta(null), [], 'chips nulos devolveram lista');
});

test('⚠️⚠️ IDENTIFICAÇÃO: o losango é SÓ do Premium, por CHAVE e nunca por truthiness 188_07', () => {
    const premium = render(M_IDENT.default, { identificacao: IDENT, falha: null });
    const classico = render(M_IDENT.default, { identificacao: IDENT_CLASSICO, falha: null });

    // ⚠️⚠️ **A diferença entre os dois é SÓ `premium: true/false`** — se o código
    // usasse `if (modalidade)` (truthiness genérica), o losango apareceria nos DOIS
    // e este par não distinguiria nada. Foi asserção da 187-08.
    assert.match(premium, new RegExp(escapaRegex(M_IDENT.CLASSES_LOSANGO)), 'o losango do Premium (`:568`) desapareceu');
    assert.doesNotMatch(classico, new RegExp(escapaRegex(M_IDENT.CLASSES_LOSANGO)), 'o losango apareceu no CLÁSSICO — truthiness genérica');
    assert.match(classico, /Clássico/, 'o rótulo Clássico desapareceu');

    // ⚠️ E a chave tem de ser exatamente `true`: `1`, `'true'` e `{}` não valem.
    for (const valor of [1, 'true', {}, [], 'sim', NaN]) {
        const chips = M_IDENT.chipsDaOferta({ ...IDENT.chips, modalidade: { rotulo: 'Premium', premium: valor } });
        assert.equal(chips[0].premium, false, `\`premium: ${typeof valor}\` virou Premium — truthiness genérica`);
    }
    assert.equal(M_IDENT.chipsDaOferta(IDENT.chips)[0].premium, true, 'a sonda está cega: nem o Premium de verdade ela aceita');
});

test('⚠️⚠️ IDENTIFICAÇÃO: as cores do andamento são LIDAS do módulo da 186, e o rótulo NUNCA é vazio 188_07', () => {
    // ⚠️⚠️ Duas tabelas de cor para o mesmo andamento divergiriam na primeira
    // edição. Aqui as classes são comparadas com o que `andamentoDe()` devolve.
    const doModulo = M_ANDAMENTO.andamentoDe('aguardando_aprovacao');
    const html = render(M_IDENT.default, { identificacao: IDENT, falha: null });
    const ausentes = [doModulo.borda, doModulo.fundo, doModulo.texto].filter((c) => !html.includes(c));
    assert.deepEqual(ausentes, [], 'a cor do chip de andamento não veio de `andamentoDe()` — foi recopiada');

    // ⚠️⚠️ `andamentoDe()` **NUNCA devolve `null`**, e o selo exige TEXTO
    // NÃO-VAZIO: guarda que devolve `null` produz elemento vazio, que **passa em
    // render e DESAPARECE na tela**. UMA asserção coletando.
    const semRotulo = [null, undefined, '', '  ', 'chave_inventada_9z7', 7, {}, [], NaN, { toString: null }]
        .filter((chave) => {
            // ⚠️ Flag DENTRO do callback.
            const chips = M_IDENT.chipsDaOferta({ andamento: { chave } });
            const andamento = chips.find((c) => c.chave === 'andamento');

            return andamento === undefined || typeof andamento.rotulo !== 'string' || andamento.rotulo.trim() === '';
        });
    assert.deepEqual(semRotulo, [], 'chave de andamento adversa produziu chip SEM TEXTO — retângulo colorido vazio na tela');

    // ⚠️ E a chave DESCONHECIDA aparece COMO CHEGOU (forma neutra), nunca apagada.
    const inventada = M_IDENT.chipsDaOferta({ andamento: { chave: 'chave_inventada_9z7' } }).find((c) => c.chave === 'andamento');
    assert.equal(inventada.rotulo, 'chave_inventada_9z7', 'a chave desconhecida foi apagada em vez de aparecer como chegou');
});

test('⚠️⚠️ FALHA: a mensagem É A REAL do ML, e o botão SÓ existe com destino 188_07', () => {
    const html = render(M_IDENT.CaixaDeFalha, { falha: FALHA, onTentarDeNovo: null });

    // ⚠️ PUB-05 — a mensagem é o erro REAL, nunca um "algo deu errado": sem a frase
    // real a pessoa não sabe se tenta de novo ou conserta o cadastro.
    assert.match(html, /O atributo BRAND é obrigatório para esta categoria\./, 'a mensagem REAL do Mercado Livre desapareceu');
    assert.match(html, /Falha na publicação/, 'o título de `:581` desapareceu');
    assert.match(html, />!</, 'o glifo `!` de `:580` desapareceu');

    // ⚠️⚠️ **SEM HANDLER O BOTÃO NÃO NASCE** — nada de `onClick` vazio nem
    // `disabled` decorativo. É a régua de PERGUNTA-188-B (destino `null` → SEM
    // BOTÃO, e o TEXTO continua), e vale para sempre: é o COMPONENTE que a garante,
    // independentemente de a rota existir ou não.
    assert.doesNotMatch(html, /<button/, 'o botão nasceu sem destino — D4 violado');
    assert.doesNotMatch(html, /Tentar publicar novamente/, 'o rótulo da ação apareceu sem destino');

    // ═══ ⚠️⚠️ A ROTA CHEGOU NO MEIO DESTA WAVE, E O GATE FOI QUEM AVISOU ══════
    //
    // ⚠️⚠️ **HISTÓRICO MEDIDO, 19/10/2026, e ele fica escrito porque é a prova de
    // que a ausência nunca foi arbitrária.** Quando este bloco nasceu,
    // `grep tentarDeNovo routes/` saía VAZIO **no HEAD** e o botão não era
    // desenhado — a instrução literal do plano. ⚠️ Naquele momento havia **434
    // linhas NÃO COMMITADAS** em `MlbPublicadorAprovacaoController.php` e **71** em
    // `routes/mlb_anuncios.php`, de OUTRA SESSÃO (o plano **188-05**), e por isso
    // este bloco passou a ler **`git show HEAD:`** e **não** a árvore: um gate que
    // lesse a árvore ficaria vermelho/verde **conforme o vizinho salva um arquivo**,
    // e o conserto barato seria ligar a página a uma rota que ainda podia ser
    // reescrita.
    // ➡️ **O plano 188-05 COMMITOU a rota, a condição caiu, e este gate REPROVOU
    // com a mensagem que ele mesmo carregava** (*"ligue `onTentarDeNovo` na
    // página"*). O botão está ligado, e a asserção virou de PRESENÇA.
    // ⚠️ **Continua lendo o HEAD de propósito:** é o único estado determinístico em
    // relação ao código, e é o que impede o gate de oscilar com o WIP do vizinho.
    const rotas = execFileSync('git', ['show', `HEAD:${REL_ROTAS}`], { cwd: RAIZ, encoding: 'utf8' });
    assert.match(rotas, /name\('meus\.aprovacao\.painel\.tentar-de-novo'\)/, 'a rota de `tentarDeNovo` saiu do HEAD — então o botão TEM de voltar a não ser desenhado (D4), e esta asserção volta a ser de AUSÊNCIA');
    // ⚠️ ISCA: a sonda ACHA as outras rotas que EXISTEM no HEAD **e não acha** uma
    // que eu sei que não existe — senão o `match` acima passaria por leitura certa
    // e tudo o mais por acidente.
    assert.match(rotas, /name\('meus\.aprovacao'\)/, 'a rota da fila desapareceu do HEAD — a leitura de `routes/` está quebrada');
    assert.match(rotas, /name\('meus\.aprovacao\.lote'\)/, 'a rota do lote desapareceu do HEAD');
    assert.doesNotMatch(rotas, /name\('meus\.aprovacao\.painel\.nao-existe-9z7'\)/, 'a isca da rota virou rota de verdade');
    assert.ok(rotas.length > 10000, `o \`git show HEAD:\` devolveu ${rotas.length} bytes — a leitura está quebrada`);

    // ═══ ⚠️⚠️ A PÁGINA LIGA O BOTÃO, E PELA PORTA ÚNICA DE ESCRITA ════════════
    //
    // ⚠️ A escrita vai por `escreverNoPainel()` (COL-04/W11), que põe
    // `versao_esperada` em TODA chamada. Ligar o botão com um `router.post` cru
    // aqui fugiria do helper — e é exatamente o que o critério 17 existe para
    // impedir.
    assert.match(FONTE_PAGINA, /onTentarDeNovo=\{tentarDeNovo\}/, 'a página deixou de ligar o botão de "tentar publicar novamente"');
    assert.match(FONTE_PAGINA, /escreverNoPainel\(\{/, 'a página não usa o helper único de escrita — `versao_esperada` deixaria de viajar');
    assert.match(FONTE_PAGINA, /rota: 'mlb\.anuncios\.meus\.aprovacao\.painel\.tentar-de-novo'/, 'o nome da rota divergiu do que está no HEAD');
    // ⚠️ E **sem empresa, sem oferta ou sem versão o handler é `null`** — e aí o
    // botão não nasce. Nunca uma rota inventada, nunca escrita sobre versão que a
    // tela não viu.
    assert.match(FONTE_PAGINA, /empresaId === null \|\| ofertaDoPainel === null \|\| versaoDoPainel === null/, 'a guarda do handler (empresa, oferta e versão) saiu da página');
    // ⚠️ E `router.post` cru NÃO aparece para esta ação (o do lote é outro, e é da
    // 187-09): a contagem é EXATA, não um piso.
    assert.equal(
        (FONTE_PAGINA.match(/router\.post\(/g) ?? []).length,
        1,
        'apareceu um `router.post` cru a mais na página — a escrita do painel tem de ir pelo helper único (o único legítimo é o do LOTE, da 187-09)',
    );

    // ⚠️ COM handler o botão nasce E o clique chama — sem esta ponta, o
    // `doesNotMatch` acima seria vazio por construção (talvez o botão nunca nasça).
    let tentou = 0;
    const comHandler = render(M_IDENT.CaixaDeFalha, { falha: FALHA, onTentarDeNovo: () => { tentou += 1; } });
    assert.match(comHandler, /Tentar publicar novamente/, 'com handler o botão não nasceu — a ausência acima não prova nada');
    const arvore = M_IDENT.CaixaDeFalha({ falha: FALHA, onTentarDeNovo: () => { tentou += 1; } });
    const botao = arvore.props.children.find((filho) => filho !== null && typeof filho === 'object' && filho.type === 'button');
    assert.ok(botao !== undefined, 'o botão não foi achado na árvore');
    botao.props.onClick();
    assert.equal(tentou, 1, 'o clique no botão não chamou o handler');

    // ⚠️ Sem falha, NADA. E com falha SEM mensagem a caixa **continua aparecendo**:
    // a falha É o fato, e esconder a caixa esconderia trabalho.
    assert.equal(render(M_IDENT.CaixaDeFalha, { falha: null }), '', 'a caixa de falha apareceu sem falha');
    const semMensagem = render(M_IDENT.CaixaDeFalha, { falha: { mensagem: null, rotulo_acao: 'x' } });
    assert.match(semMensagem, /Falha na publicação/, 'a caixa desapareceu sem mensagem — esconder a caixa esconde trabalho');
    assert.doesNotMatch(semMensagem, /\[object Object\]|NaN|undefined/, 'a caixa imprimiu lixo sem mensagem');
});

test('⚠️ IDENTIFICAÇÃO: a GEOMETRIA literal, e as QUATRO omissões medidas da tabela FID-01 188_07', () => {
    const ESPERADO = {
        // `:563` / `:564` — ⚠️ `min-w-0` é o que permite o título truncar no grid.
        CLASSES_LINHA: ['flex', 'items-start', 'justify-between', 'gap-[20px]'],
        CLASSES_COLUNA_ESQUERDA: ['flex', 'min-w-0', 'flex-col', 'gap-[8px]'],
        CLASSES_PRODUTO: ['text-[12px]', 'text-pub-txt-3'],
        CLASSES_SKU: ['font-pub-mono', 'text-[11.5px]'],
        CLASSES_TITULO: ['text-[20px]', 'font-semibold', 'leading-[1.25]', 'tracking-[-0.015em]', 'text-pretty'],
        // `:567` — ⚠️ `flex-wrap` é o que impede os 5 chips de vazarem.
        CLASSES_FILA_DE_CHIPS: ['flex', 'flex-wrap', 'gap-[6px]'],
        // ⚠️ OMISSÃO 4: `display:flex;align-items:center` não estão nas medidas-chave
        // de B14/B15/B16 — sem eles o chip não centra o conteúdo na altura de 24px.
        CLASSES_CHIP: ['flex', 'items-center', 'h-[24px]', 'px-[9px]', 'rounded-pub-chip', 'whitespace-nowrap', 'text-[12px]'],
        CLASSES_CHIP_MODALIDADE: ['gap-[6px]', 'bg-pub-line-subtle', 'font-semibold'],
        CLASSES_LOSANGO: ['h-[7px]', 'w-[7px]', 'flex-none', 'rotate-45', 'rounded-[1px]', 'bg-pub-txt'],
        CLASSES_CHIP_CONTORNO: ['border', 'border-pub-line-strong'],
        CLASSES_CHIP_ANDAMENTO: ['gap-[6px]', 'border', 'font-semibold'],
        CLASSES_DOT: ['h-[6px]', 'w-[6px]', 'flex-none', 'rounded-full', 'bg-current'],
        CLASSES_LADO_DIREITO: ['flex', 'flex-none', 'flex-col', 'items-end', 'gap-[3px]', 'whitespace-nowrap', 'text-[12px]', 'text-pub-txt-3'],
        CLASSES_RESPONSAVEL: ['text-pub-fase1'],
        // ⚠️ OMISSÃO 3: `display:flex;align-items:center` não estão na medida de B18.
        CLASSES_FALHA: ['flex', 'items-center', 'gap-[12px]', 'rounded-pub-sub', 'border', 'border-pub-danger/[0.4]', 'bg-pub-danger/[0.07]', 'px-[14px]', 'py-[12px]'],
        // ⚠️⚠️ OMISSÃO 1: `display:grid;place-items:center` de `:580` NÃO está na
        // célula do documento — é o que CENTRA o `!` no círculo de 22px.
        // ⚠️ `font-[800]` e não `font-extrabold`: o GATE-03 proíbe a escala nomeada,
        // e o valor arbitrário entrega o MESMO peso (precedente de
        // `AnelDeProgresso.jsx:67`, em produção).
        CLASSES_GLIFO_FALHA: ['grid', 'h-[22px]', 'w-[22px]', 'flex-none', 'place-items-center', 'rounded-full', 'bg-pub-danger', 'text-[12px]', 'font-[800]', 'text-pub-page'],
        // ⚠️⚠️ OMISSÃO 2: o WRAPPER de `:581` não é nomeado na tabela — é o `flex-1`
        // dele que empurra o botão de `:582` para a direita.
        CLASSES_FALHA_TEXTO: ['flex', 'flex-1', 'flex-col', 'gap-[2px]'],
        CLASSES_FALHA_TITULO: ['text-[13px]', 'font-semibold', 'text-pub-danger-txt'],
        CLASSES_FALHA_MENSAGEM: ['text-[12.5px]', 'text-pub-fase1'],
        CLASSES_FALHA_ACAO: ['h-[32px]', 'flex-none', 'cursor-pointer', 'whitespace-nowrap', 'rounded-[8px]', 'bg-pub-accent', 'px-[14px]', 'text-[12.5px]', 'font-semibold', 'text-pub-page'],
    };

    const divergentes = Object.keys(ESPERADO).filter((chave) => {
        // ⚠️ Flag DENTRO do callback.
        const noDisco = new Set(M_IDENT[chave].split(/\s+/).filter((c) => c !== ''));

        return noDisco.size !== ESPERADO[chave].length || ESPERADO[chave].some((c) => !noDisco.has(c));
    });
    assert.deepEqual(divergentes, [], 'a geometria da identificação divergiu do protótipo');

    // ⚠️ Os literais travados.
    assert.equal(M_IDENT.TITULO_DA_FALHA, 'Falha na publicação', 'o literal de `:581` mudou');
    assert.equal(M_IDENT.GLIFO_FALHA, '!', 'o glifo de `:580` mudou');
    assert.equal(M_IDENT.SEPARADOR, '·', 'o separador de `:565` não é o U+00B7');
});

test('⚠️ IDENTIFICAÇÃO: nada para mostrar NÃO desenha wrapper, e nenhum campo adverso derruba 188_07', () => {
    // ⚠️ Linha de 20px de gap sem conteúdo é D4 violado.
    assert.equal(render(M_IDENT.default, {}), '', 'a identificação desenhou casca vazia');
    assert.equal(render(M_IDENT.default, { identificacao: {}, falha: null }), '', 'a identificação desenhou com bloco vazio');
    assert.equal(M_IDENT.temIdentificacao(null, null), false, '`temIdentificacao` disse que há conteúdo onde não há');
    assert.equal(M_IDENT.temIdentificacao(IDENT, null), true, '`temIdentificacao` não viu o conteúdo de verdade');
    assert.equal(M_IDENT.temIdentificacao(null, FALHA), true, '`temIdentificacao` ignorou a falha');

    // ⚠️⚠️ A TELA PRETA, campo por campo.
    const ADVERSOS = [{}, null, undefined, NaN, [], { toString: null }, 0, '', true, { a: { toString: null } }];
    for (const campo of ['produto', 'sku', 'titulo', 'responsavel', 'espera', 'chips']) {
        for (const valor of ADVERSOS) {
            const id = { ...IDENT, [campo]: valor };
            let html = '';
            assert.doesNotThrow(() => { html = render(M_IDENT.default, { identificacao: id, falha: null }); }, `a identificação LANÇOU com ${campo} adverso (${typeof valor})`);
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `a identificação imprimiu lixo com ${campo} adverso (${typeof valor})`);
        }
    }
    for (const campo of ['modalidade', 'formato', 'origem', 'versao', 'andamento']) {
        for (const valor of ADVERSOS) {
            const id = { ...IDENT, chips: { ...IDENT.chips, [campo]: valor } };
            let html = '';
            assert.doesNotThrow(() => { html = render(M_IDENT.default, { identificacao: id, falha: null }); }, `o chip ${campo} LANÇOU (${typeof valor})`);
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `o chip ${campo} imprimiu lixo (${typeof valor})`);
        }
    }
    for (const valor of ADVERSOS) {
        let html = '';
        assert.doesNotThrow(() => { html = render(M_IDENT.default, { identificacao: valor, falha: valor }); }, `a identificação LANÇOU com as props inteiras adversas (${typeof valor})`);
        assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `imprimiu lixo com as props inteiras adversas (${typeof valor})`);
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 8d. A PÁGINA — o slot `painelNo`/`painel` e o recarregamento PARCIAL (COL-04)
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ PÁGINA: o slot `painelNo` (NÓ) × `painel` (DADO), e a oferta vive na URL 188_07', () => {
    // ⚠️ **Prop com DOIS sentidos foi achada como defeito grave na Fase 186.** É o
    // QUARTO par da mesma família: `proximo`/`proximo_a_revisar`,
    // `filtros`/`filtros_ativos`, `tabela`/`grupos` e agora `painelNo`/`painel`.
    assert.match(FONTE_PAGINA, /isValidElement\(painelNo\)/, 'o slot de NÓ do painel não é resolvido por `isValidElement`');
    assert.match(FONTE_PAGINA, /painelNo = null/, 'a prop de NÓ `painelNo` saiu da página');
    assert.match(FONTE_PAGINA, /painel = null/, 'a prop de DADO `painel` saiu da página');
    assert.match(FONTE_PAGINA, /<PainelDeRevisao/, 'a página deixou de montar o painel do dado');

    // ⚠️⚠️ **COL-04 — a navegação ↑/↓ É RECARREGAMENTO PARCIAL**, e é ele que faz a
    // `versao` voltar FRESCA do servidor. Sem ir ao servidor, a tela poderia aprovar
    // uma oferta que mudou debaixo de quem revisa — e **aprovar JÁ PUBLICA**.
    assert.match(FONTE_PAGINA, /only: \['painel'\]/, 'a navegação ↑/↓ deixou de ser recarregamento PARCIAL — a `versao` não volta fresca (COL-04)');
    assert.match(FONTE_PAGINA, /oferta: id/, 'a oferta aberta saiu da URL (COL-03)');

    // ⚠️ **COL-02 — NADA de `localStorage` na página**: filtro/oferta que mora no
    // navegador não sobrevive a um link compartilhado, e a revisão parcial fica no
    // SERVIDOR (critério 16).
    assert.doesNotMatch(FONTE_PAGINA, /localStorage|sessionStorage/, '`localStorage` voltou à página');
    // ⚠️ Há gate da wave 187-04 que PROÍBE `String(` nesta página — está certo.
    assert.doesNotMatch(FONTE_PAGINA, /\bString\(/, '`String(` voltou à página — ele LANÇA com `{toString:null}`');

    // ⚠️ ISCA: a leitura da página não veio vazia (senão os `doesNotMatch` acima
    // seriam vazios por construção).
    // ⚠ A ÂNCORA é o que prova a leitura; o piso de bytes é só o segundo sinal, e
    // foi **MEDIDO** (14.644 bytes SEM comentário em 19/10/2026 — a página tem 797
    // linhas CRUAS, mas `lerSemComentarios` tira a prosa em pt-BR).
    assert.match(FONTE_PAGINA, /export default function Aprovacao\(/, 'a página da fila desapareceu da fonte lida — os `doesNotMatch` acima seriam vazios');
    assert.ok(FONTE_PAGINA.length > 10000, `a fonte da página veio com ${FONTE_PAGINA.length} bytes — a leitura está quebrada`);
});

test('⚠️ CASCA: o corpo monta identificação + colunas, e o composto segue VAZIO no servidor 188_07', () => {
    const html = render(M_PAINEL.RaizDoPainel, {
        navegacao: NAV_MEIO,
        identificacao: IDENT,
        falha: FALHA,
        onIr: nada,
        onFechar: nada,
        colunaDeFotos: React.createElement('b', null, 'fotos'),
    });

    assert.match(html, /overflow-y-auto/, 'o corpo rolável de `:562` desapareceu');
    assert.match(html, /Poltrona Beny Decorativa/, 'a identificação não chegou ao corpo');
    assert.match(html, /Falha na publicação/, 'a caixa de falha não chegou ao corpo');
    assert.match(html, /grid-cols-\[minmax\(0,1fr\)_minmax\(0,1fr\)\]/, 'a grade das duas colunas não chegou ao corpo');
    assert.match(html, /<b>fotos<\/b>/, 'o slot de fotos não chegou à grade');

    // ⚠️ A ORDEM vertical: identificação → falha → colunas (`:563` → `:579` → `:586`).
    assert.ok(html.indexOf('Poltrona Beny') < html.indexOf('Falha na publica'), 'a falha subiu acima da identificação');
    assert.ok(html.indexOf('Falha na publica') < html.indexOf('grid-cols-'), 'as colunas subiram acima da falha');

    // ⚠️⚠️ E o COMPOSTO continua VAZIO no servidor: o portal segue de pé depois da
    // tarefa 2. Sem esta linha, uma edição que tirasse o portal passaria calada.
    assert.equal(
        render(M_PAINEL.default, { aberto: true, painel: { ...PAINEL, identificacao: IDENT }, onFechar: nada, onIr: nada }),
        '',
        'o composto renderizou no servidor — o portal caiu na tarefa 2',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 9. O CSS CONSTRUÍDO — com as OITO normalizações, e as iscas MEDIDAS
// ═══════════════════════════════════════════════════════════════════════════

/** Escapa TODOS os metacaracteres de regex. */
function escapaRegex(texto) {
    return texto.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * O seletor que o Tailwind emite: `.` + o nome com os metacaracteres escapados.
 *
 * ⚠️⚠️ A REGRA É **GERAL** — escapa tudo que não é `[A-Za-z0-9_-]`. A forma
 * herdada escapava só `[].:/%`, e com ela classe com `(`/`,` saía **AUSENTE**
 * estando presente e compilada (a **OITAVA** normalização). É exatamente o caso
 * de `w-[min(1140px,calc(100vw-100px))]` e de
 * `grid-cols-[minmax(0,1fr)_minmax(0,1fr)]`.
 */
function seletorDe(classe) {
    return `.${[...classe].map((ch) => (/[A-Za-z0-9_-]/.test(ch) ? ch : `\\${ch}`)).join('')}`;
}

/**
 * Os caminhos cujo `mtime` o CSS tem de superar.
 *
 * ⚠️ **É esta guarda que produz os DOIS REGIMES de contagem do manifesto**: mutar
 * uma fonte a deixa mais nova que o CSS e a camada cai em `skip`. Sem os dois
 * regimes a campanha de mutação **aborta na 1ª execução** ou marca "SOBREVIVEU"
 * por um motivo que **NÃO é o código** (learnings §4 caso 11).
 */
const FONTES_DO_CSS = [REL_PAINEL, REL_TOPO, REL_IDENT];

function carregarCssConstruido() {
    const manifestRel = 'public/build/manifest.json';
    const manifestAbs = path.resolve(RAIZ, manifestRel);
    if (!fs.existsSync(manifestAbs)) return { motivo: `${manifestRel} ausente — rode \`npm run build\`` };

    // ⚠️ Manifest SEMPRE pelo JSON, nunca por grep: o hash sai com HÍFEN
    // (`assets/app--5yuaubs.js`) e o grep ingênuo não casa.
    const manifest = JSON.parse(fs.readFileSync(manifestAbs, 'utf8'));
    const entrada = manifest['resources/js/app.jsx'];
    if (!entrada || !Array.isArray(entrada.css) || entrada.css.length === 0) {
        return { motivo: 'o manifest não aponta CSS para resources/js/app.jsx' };
    }

    const cssAbs = path.resolve(RAIZ, 'public/build', entrada.css[0]);
    if (!fs.existsSync(cssAbs)) return { motivo: `${entrada.css[0]} ausente — rode \`npm run build\`` };

    const mtimeCss = fs.statSync(cssAbs).mtimeMs;
    const mtimeFontes = Math.max(...FONTES_DO_CSS.map((rel) => fs.statSync(path.resolve(RAIZ, rel)).mtimeMs));
    if (mtimeCss < mtimeFontes) {
        return { motivo: `o CSS construído (${entrada.css[0]}) é mais antigo que as fontes — rode \`npm run build\`` };
    }

    return { arquivo: entrada.css[0], css: fs.readFileSync(cssAbs, 'utf8'), manifest };
}

const CSS = carregarCssConstruido();

test('⚠️ FID: as classes da casca GERAM CSS com o valor certo 188_07', async (contexto) => {
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

    await contexto.test('o CSS lido é o do manifest, e a PÁGINA está no manifest', () => {
        assert.ok(CSS.css.length > 100000, `CSS suspeito de truncado: ${CSS.css.length} bytes`);

        // ⚠️ Página React FORA do manifest **desaparece da aplicação sem erro
        // nenhum**. ⚠️ `hasOwnProperty.call` sobre chave CONSTANTE (nunca do
        // servidor) é seguro aqui.
        const tem = (k) => Object.prototype.hasOwnProperty.call(CSS.manifest, k);
        assert.ok(tem('resources/js/Pages/Mlb/Publicador/Aprovacao.jsx'), 'a página da fila saiu do manifest — a tela desaparece sem erro');
        assert.equal(tem('resources/js/Pages/Mlb/NaoExiste9z7.jsx'), false, 'a isca do manifest virou entrada de verdade');
    });

    await contexto.test('⚠️ ISCA: a sonda reporta AUSENTE o que não existe, e ACHA o que existe', () => {
        // ⚠️ **Isca tem de ser classe inexistente no projeto INTEIRO** — `w-[13px]`
        // parecia inventada e saiu PRESENTE; `h-[64px]` e `left-[30%]` EXISTEM.
        // Estas foram medidas contra o CSS de verdade nesta sessão.
        for (const isca of ['bg-pub-isca-188-07', 'text-[97.5px]', 'w-[1993px]', 'h-[467px]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS.css.includes(isca), `a string da isca ${isca} aparece no CSS`);
        }
        assert.notEqual(regraDe('text-[12px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('⚠️⚠️ `duration-[Nms]` continua NÃO COMPILANDO, e `duration-200` compila', () => {
        // ⚠️ É o precedente MEDIDO deste projeto, e é a ISCA deste bloco: sem o
        // controle positivo, o gate não distingue "o arbitrário não compila" de "a
        // sonda está cega".
        assert.equal(regraDe('duration-[180ms]'), null, '`duration-[180ms]` passou a compilar — remeça a regra');
        assert.match(regraDe('duration-200'), /transition-duration:\s*\.?2s/, 'o CONTROLE da escala nomeada sumiu');
    });

    await contexto.test('⚠️⚠️ a GEOMETRIA da casca, com as OITO normalizações do CSS construído', () => {
        // ⚠️ 1ª/2ª — token SÓLIDO **não** compila para hex: sai
        // `rgb(13 13 13 / var(--tw-bg-opacity, 1))`. Esperar hex deu **25 falsas
        // faltas**.
        assert.match(regraDe('bg-pub-sunken'), /rgb\(13 13 13/, '`bg-pub-sunken` não é #0D0D0D — `pub-page` (#0A0A0A) e `pub-surface` (#111111) são os parecidos VETADOS (FID-02)');
        assert.match(regraDe('border-pub-line-strong'), /rgb\(46 46 46/, '`border-pub-line-strong` não é #2E2E2E');
        assert.match(regraDe('border-pub-line-subtle'), /rgb\(31 31 31/, '`border-pub-line-subtle` não é #1F1F1F');
        assert.match(regraDe('border-pub-line'), /rgb\(38 38 38/, '`border-pub-line` não é #262626');

        // ⚠️ 3ª — o modificador de opacidade muda de FORMA conforme a PROPRIEDADE:
        // em `background-color` sai **hex de 8 dígitos**, e o byte do alfa PROVA a
        // opacidade. `0.55` → `0x8c`.
        assert.match(regraDe('bg-black/[0.55]'), /#0000008c/, 'o backdrop não é `rgba(0,0,0,0.55)` (`:549`)');

        // ⚠️ 5ª — o seletor vem ESCAPADO (`.text-\[13\.5px\]`): 54 falsas faltas.
        // ⚠️ 8ª — o escape é GERAL: sem ele, estas duas sairiam AUSENTES estando
        // presentes (parênteses e vírgulas).
        assert.match(regraDe('w-[min(1140px,calc(100vw-100px))]'), /min\(1140px\s*,\s*calc\(100vw\s*-\s*100px\)\)/, 'a largura de `:550` não compilou');
        assert.match(regraDe('grid-cols-[minmax(0,1fr)_minmax(0,1fr)]'), /minmax\(0\s*,\s*1fr\)\s+minmax\(0\s*,\s*1fr\)/, 'as duas colunas de MESMO PESO de `:586` não compilaram');

        // ⚠️ `shadow-pub-painel` é o TOKEN com o valor exato de `:550`.
        assert.match(regraDe('shadow-pub-painel'), /-24px 0 64px/, '`shadow-pub-painel` perdeu o valor de `:550`');

        // ⚠️ 4ª — o cssnano tira o zero da frente INCLUSIVE depois do sinal de
        // menos. `rounded-pub-table` é 14px e não tem decimal, então a prova é
        // direta; `tracking-[0.08em]` é o caso com decimal.
        assert.match(regraDe('rounded-pub-table'), /14px/, '`rounded-pub-table` deixou de ser 14px (`:587`)');
        assert.match(regraDe('tracking-[0.08em]'), /\.08em/, 'o `letter-spacing:0.08em` de `:552` não compilou (o cssnano come o zero da frente)');

        // ⚠️ 6ª — na VARIANTE, o prefixo faz parte do nome
        // (`.hover\:bg-pub-hover-3:hover`).
        assert.match(regraDe('hover:bg-pub-hover-3'), /rgb\(26 26 26/, 'o hover `#1A1A1A` de `:555`/`:556`/`:559` não compilou');

        // ⚠⚠ A 3ª normalização OUTRA VEZ, agora em `border-color`: `0.4` sai como
        // **hex de 8 dígitos** e o byte do alfa PROVA a opacidade (`0.4` → `0x66`).
        // É a borda da caixa de falha de `:579`, `rgba(239,68,68,0.4)`.
        assert.match(regraDe('border-pub-danger/[0.4]'), /#ef444466/, 'a borda da caixa de falha não é `rgba(239,68,68,0.4)` (`:579`)');
        // ⚠ E `0.07` → `0x12` no fundo da mesma caixa.
        assert.match(regraDe('bg-pub-danger/[0.07]'), /#ef444412/, 'o fundo da caixa de falha não é `rgba(239,68,68,0.07)` (`:579`)');

        // ⚠⚠ `font-[800]` — o peso de `:580`, por valor ARBITRÁRIO. O **GATE-03**
        // proíbe a escala NOMEADA `font-extrabold` em todo arquivo de
        // `ARQUIVOS_NOVOS` (só 400/500/600/700), e o arbitrário entrega o MESMO peso
        // computado — precedente de `AnelDeProgresso.jsx:67`, em produção. ⚠️ Sem
        // esta prova, `font-[800]` poderia simplesmente NÃO COMPILAR e o `!` sairia
        // com o peso herdado, com todo teste de render passando.
        assert.match(regraDe('font-[800]'), /font-weight:\s*800/, '`font-[800]` não compilou para `font-weight:800` — o glifo de `:580` sairia com o peso errado');

        // ⚠ O `letter-spacing:-0.015em` de `:566`: o cssnano tira o zero da frente
        // **inclusive depois do sinal de menos** (4ª normalização).
        assert.match(regraDe('tracking-[-0.015em]'), /-\.015em/, 'o `letter-spacing:-0.015em` de `:566` não compilou (o cssnano come o zero após o sinal)');

        // ⚠ `rotate-45` é o `transform:rotate(45deg)` do losango de `:568`.
        assert.notEqual(regraDe('rotate-45'), null, 'o `rotate-45` do losango de `:568` não compilou');

        // As medidas restantes do topo, da casca e da identificação, numa asserção
        // coletando.
        const semRegra = [
            'h-[54px]', 'pl-[22px]', 'pr-[14px]', 'gap-[10px]', 'text-[11px]', 'text-[12px]',
            'gap-[2px]', 'ml-[6px]', 'w-[30px]', 'h-[30px]', 'rounded-pub-control-sm', 'text-[14px]',
            'ml-auto', 'w-[32px]', 'h-[32px]', 'rounded-[8px]', 'text-[16px]',
            'inset-y-0', 'right-0', 'border-l', 'font-pub', 'text-pub-txt',
            'pt-[18px]', 'pb-[24px]', 'gap-[16px]', 'items-start', 'gap-[12px]', 'p-[14px]',
            'text-pub-txt-2', 'text-pub-txt-3', 'text-pub-txt-4', 'bg-pub-surface',
            'z-[39]', 'z-[40]',
            // ─── a identificação (`:563`-`:582`) ───
            'gap-[20px]', 'min-w-0', 'gap-[8px]', 'font-pub-mono', 'text-[11.5px]',
            'text-[20px]', 'leading-[1.25]', 'text-pretty', 'flex-wrap', 'gap-[6px]',
            'h-[24px]', 'px-[9px]', 'rounded-pub-chip', 'bg-pub-line-subtle',
            'h-[7px]', 'w-[7px]', 'rounded-[1px]', 'bg-pub-txt', 'h-[6px]', 'w-[6px]',
            'bg-current', 'gap-[3px]', 'items-end', 'text-pub-fase1',
            'rounded-pub-sub', 'px-[14px]', 'py-[12px]', 'h-[22px]', 'w-[22px]',
            'place-items-center', 'bg-pub-danger', 'text-pub-page', 'gap-[2px]',
            'text-[13px]', 'text-pub-danger-txt', 'text-[12.5px]', 'bg-pub-accent',
        ].filter((c) => regraDe(c) === null);
        assert.deepEqual(semRegra, [], 'classe da casca NÃO gerou CSS — num `.js` isso aconteceria com todo teste de render passando');
    });
});
