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
// Fase 188, plano 10 — AS PENDÊNCIAS COM O SEU BOTÃO DE CORREÇÃO e o RODAPÉ
// FIXO DE 66px, onde vive o `disabled=` de "Aprovar para publicação".
// Critérios 8, 9, 10 e a metade tela do 16.
//
// ⚠️⚠️ DE CADA BLOCO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR: "o que
// precisaria estar errado no mundo para ele falhar?" — **"Nada" é ACHADO.**
//
//  1. DESTINO     → a tela decidindo para onde vai cada correção (um `switch`
//                   por chave de bloqueio), em vez de OBEDECER o `destino` que o
//                   servidor resolveu. A divergente só apareceria NO CLIQUE.
//  2. `null`      → PERGUNTA-188-B. Destino `null` tem de dar **ZERO botão** e
//                   **o TEXTO continuando a aparecer**. ⚠️ Botão sem destino é D4
//                   violado; `onClick` vazio e `disabled` decorativo são a mesma
//                   dívida com outra roupa, e as duas foram RECUSADAS.
//  3. DUAS CORES  → `fotos_a_conferir` é VERMELHA com alerta e ÂMBAR sem
//                   (`:1295`). ⚠️ É o ramo mais fácil de omitir, porque os outros
//                   três são por chave FIXA — por isso a MESMA chave é testada
//                   nas DUAS cores.
//  4. `live`      → `hasFix = !!b.fix && inf.live` (`:1295`). ⚠️ A fixture manda
//                   destino PREENCHIDO com `live: false` (payload CONTRADITÓRIO):
//                   sem ela o gate mediria o SERVIDOR, não a tela — é a lição da
//                   *fixture que discrimina* da wave 188-09.
//  5. ⚠️⚠️ `disabled=` → **E NUNCA `/disabled/`**, que casa com a classe
//                   `disabled:opacity-40`. ⚠️ E o atributo é lido no `<button>` DE
//                   APROVAR, localizado por **ÁRVORE COM ÍNDICE ABSOLUTO** —
//                   regex sobre o HTML do rodapé inteiro é gate LARGO: um
//                   `disabled=""` em "Salvar revisão" a satisfaria com o botão de
//                   aprovar HABILITADO. ⚠️ `els[0]` mede o irmão errado (QUATRO
//                   gates deste projeto mediam o irmão errado).
//  6. CLIENTE     → `aprovavel` calculado na tela. ⚠️ A trava real é
//                   `AprovacaoDaOfertaService::aprovar()`; duas verdades
//                   divergiriam e a divergente seria descoberta por alguém
//                   CLICANDO e sendo recusado.
//  7. MOTIVO      → recopiar o `join(' · ')` de **`:1297`** (TODAS as
//                   abreviações) em vez de EXIBIR `resumoDoRodape()` (o PRIMEIRO
//                   + ` +N`). ⚠️ Seria a SEGUNDA VERDADE da mesma frase na MESMA
//                   tela — o rodapé da fila e o do painel divergiriam.
//  8. LITERAL     → o verde `Fotos, preço e conteúdo conferidos` copiado à mão em
//                   vez de LIDO de `vocabularioDoPortao.jsx`. Duas cópias
//                   divergem no primeiro ajuste.
//  9. `isLive`    → os TRÊS botões de ação fora do `live` (`:713`/**`:718`**), ou
//                   `Próximo ↓` desaparecendo com o `live` (ele é FORA dos
//                   `sc-if`). ⚠️ Um gate que só teste o caso `live` passa com um
//                   rodapé que nunca esconde nada.
// 10. RÓTULOS     → "confirmar publicação" / "desfazer" / "segundo passo" /
//                   "cancelar aprovação". ⚠️ Decisão 1: **aprovar JÁ PUBLICA**.
//                   Leitura SEM COMENTÁRIO (o docblock que os PROÍBE os cita, e
//                   TRÊS mutações da 187-04 abortaram por isso).
// 11. HELPER      → escrita sem passar por `escreverNoPainel`. ⚠️
//                   `versao_esperada` é OPCIONAL no corpo, então OMITI-LA faz
//                   `exigirVersao()` receber `null` e **LIBERAR** a escrita —
//                   *"sobrescrever em silêncio"*, que é o critério 17.
// 12. ADVERSO     → qualquer campo chegando objeto/`NaN`/array/nulo, a prop
//                   inteira nula, **e no CLIQUE**: a TELA PRETA voltou SEIS vezes
//                   e a última foi NO CLIQUE. `renderToStaticMarkup` **não
//                   dispara `onClick`**.
// 13. TERRITÓRIO  → `localStorage`, `String(`, hex cru, peça nova em `.js`, as
//                   Fases 189/192 antecipadas, os INTOCÁVEIS tocados.
// 14. LISTAS      → `.jsx` fora de `ARQUIVOS_NOVOS` (o GATE-03 passaria POR
//                   OMISSÃO) ou fora de `JSX_DEPOIS_DA_187`.
//
// ⚠️ Todos os nomes de teste terminam em `188_10`: nome genérico COLIDE no
// `--filter`, que **SOMA** as contagens. (Este plano não tem id `php`, então o
// `--filter` não roda sobre ele — a régua do sufixo vale para o dia em que
// rodar.)
// ⚠️ `design_handoff_publicador/` **NÃO é versionado**: o bloco que depende dele
// degrada para `skip` EXPLÍCITO e NOMEADO, nunca passa calado.
// ⚠️⚠️ **TODOS os bundles são montados ANTES do primeiro `test()`** — o `after()`
// do `node --test` já matou um arquivo inteiro **sem nenhum teste falhar**.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

// ⚠️ O nome do stub carrega o PID: a árvore é compartilhada com outras sessões, e
// um stub de nome fixo seria apagado pelo `after()` da sessão vizinha no meio da
// execução desta.
const STUB_INERTIA = path.join(__dirname, `.pub-rodape188-inertia-stub-${process.pid}.mjs`);

// ⚠️⚠️ O `router` do stub **GRAVA** as chamadas. ⚠️ E o gravador vive no
// `globalThis`: o `montar()` usa `bundle: true`, então o esbuild **INLINA o stub
// dentro do bundle** — o array de dentro do bundle e o de um `import()` do stub
// seriam DOIS objetos diferentes, e o gate leria um array sempre vazio (conserto
// MEDIDO na wave 188-07).
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
globalThis.CHAMADAS_RODAPE_188_10 = globalThis.CHAMADAS_RODAPE_188_10 ?? [];
const CHAMADAS = globalThis.CHAMADAS_RODAPE_188_10;
export { CHAMADAS };
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = {
    get: (...a) => { CHAMADAS.push(['get', ...a]); },
    post: (...a) => { CHAMADAS.push(['post', ...a]); },
    put: (...a) => { CHAMADAS.push(['put', ...a]); },
    delete: (...a) => { CHAMADAS.push(['delete', ...a]); },
    visit: (...a) => { CHAMADAS.push(['visit', ...a]); },
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

// ⚠️ `route` é o global que o Ziggy injeta no navegador — mesma forma das waves
// 187-05/06 e 188-07/08/09.
global.route = (nome, params) => `/${nome}${JSON.stringify(params ?? {})}`;

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
        `.pub-rodape188-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';
const REL_PENDENCIAS = `${DIR_APROV}/PendenciasDoPainel.jsx`;
const REL_RODAPE = `${DIR_APROV}/RodapeDoPainel.jsx`;
const REL_PAGINA = 'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx';
const REL_PAINEL = `${DIR_APROV}/PainelDeRevisao.jsx`;
const REL_COLUNA_DE_FOTOS = `${DIR_APROV}/ColunaDeFotos.jsx`;
const REL_KPIS = `${DIR_APROV}/KpisDoPainel.jsx`;
const REL_VOCABULARIO = `${DIR_APROV}/vocabularioDoPortao.jsx`;
const REL_RODAPE_DA_FILA = `${DIR_APROV}/RodapeDaFila.jsx`;
const REL_ANDAMENTO = `${DIR_APROV}/andamentoDoPortao.jsx`;
const REL_GATE_ZINDEX = 'tests/js/publicador-header-zindex-ordem.test.js';
const REL_GATE_VOCABULARIO = 'tests/js/publicador-vocabulario-novo.test.js';
const REL_GATE_FECHAMENTO = 'tests/js/publicador-fila-fechamento-187.test.js';
const REL_SERVICO_PAINEL = 'app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php';
const REL_SERVICO_BLOQUEIOS = 'app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php';
const REL_VOCABULARIO_PHP = 'app/Services/Publicador/Aprovacao/VocabularioDoPortao.php';
const REL_ROTAS = 'routes/mlb_anuncios.php';
const REL_MANIFESTO = 'tests/gates/contagens-188-10.txt';
const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

// ⚠️⚠️ ANTI-FANTASMA — `test -e` em CADA caminho citado. São NOVE caminhos
// fantasmas nesta milestone, TRÊS deles vindos de briefing do orquestrador.
// Sonda de AUSÊNCIA sobre arquivo inexistente passa CALADA.
const CAMINHOS_CITADOS = [
    REL_PENDENCIAS, REL_RODAPE, REL_PAGINA, REL_PAINEL, REL_COLUNA_DE_FOTOS, REL_KPIS,
    REL_VOCABULARIO, REL_ANDAMENTO,
    REL_RODAPE_DA_FILA, REL_GATE_ZINDEX, REL_GATE_VOCABULARIO, REL_GATE_FECHAMENTO,
    REL_SERVICO_PAINEL, REL_SERVICO_BLOQUEIOS, REL_VOCABULARIO_PHP, REL_ROTAS, REL_MANIFESTO,
];

// ⚠️ As fontes SEM comentário: a prosa em pt-BR deste projeto cita os próprios
// identificadores e os próprios literais, e **palavra no docblock reprova pelo
// próprio conserto** (TRÊS mutações abortadas por isso na wave 187-04).
const FONTE_PENDENCIAS = lerSemComentarios(REL_PENDENCIAS);
const FONTE_RODAPE = lerSemComentarios(REL_RODAPE);
const FONTE_PAGINA = lerSemComentarios(REL_PAGINA);

/**
 * ⚠️⚠️ A LISTA CONSTANTE dos `.jsx` DESTE plano — o recorte de TODA sonda de
 * ausência.
 *
 * Por que CONSTANTE e não glob: glob pegaria arquivo de outra wave e a sonda
 * cobraria vocabulário de trabalho que não é deste plano. ⚠️ E por que RECORTADA:
 * `lucro_liquido` é campo **legítimo** do Portal (8 arquivos), e varrer `app/`
 * **barrou um deploy** na Fase 186.
 */
const FONTES_DO_PLANO = [
    [REL_PENDENCIAS, FONTE_PENDENCIAS],
    [REL_RODAPE, FONTE_RODAPE],
];

const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

const nada = () => {};

/**
 * ⚠️⚠️ A ÁRVORE VAZIA do git — o objeto que todo repositório tem, sempre.
 *
 * É a ISCA DETERMINÍSTICA da sonda de `numstat`: contra ela **todo arquivo
 * rastreado** produz uma linha, então a sonda prova que a invocação funciona e
 * que o pathspec resolve. ⚠️ Usar "um arquivo que esta wave alterou" como isca
 * seria **vazio por construção depois do commit** (`git diff HEAD` fica limpo),
 * e o gate viraria falso positivo no dia seguinte.
 */
const ARVORE_VAZIA = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';

const numstatDe = (rel, base) => execFileSync(
    'git',
    ['diff', '--numstat', base ?? 'HEAD', '--', rel],
    { cwd: RAIZ, encoding: 'utf8' },
);

/**
 * O elemento de índice ABSOLUTO na árvore de tags de abertura do HTML.
 *
 * ⚠️⚠️ **NUNCA `indexOf(tagDeAbertura)`**: ele pega o IRMÃO ERRADO quando dois
 * elementos têm a MESMA string de classes — **quatro gates deste projeto estavam
 * medindo o irmão errado**, e a wave 188-08 caiu nisso.
 * ⚠️⚠️ **E `desabilitado` é `disabled=` por ATRIBUTO, nunca `/disabled/`**: a
 * segunda forma casa com a CLASSE `disabled:opacity-40` e o gate passaria com o
 * botão HABILITADO. A armadilha vem escrita no próprio critério 8.
 */
function tagsDeAbertura(html) {
    return [...html.matchAll(/<(div|span|section|aside|button|input|a)\b([^>]*)>/g)].map((m) => ({
        tag: m[1],
        atributos: m[2],
        classe: (m[2].match(/class="([^"]*)"/) || [])[1] ?? '',
        titulo: (m[2].match(/title="([^"]*)"/) || [])[1] ?? null,
        estilo: (m[2].match(/style="([^"]*)"/) || [])[1] ?? null,
        // ⚠️ `disabled=` com o IGUAL, e `\s` ou `>` logo depois do valor: é o
        // ATRIBUTO. `disabled:opacity-40` dentro de `class="…"` **não** casa,
        // porque ali o que segue o `disabled` é `:`, não `=`.
        desabilitado: /\bdisabled=/.test(m[2]),
    }));
}

/** As classes de um elemento como CONJUNTO — igualdade, nunca `includes`. */
function classesDe(elemento) {
    return new Set(elemento.classe.split(/\s+/).filter((c) => c !== ''));
}

/** O conteúdo de texto, sem tags — para ler o que a tela DIZ. */
function texto(html) {
    return html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
}

/** Os `<button>` na ORDEM da árvore, com o rótulo de texto de cada um. */
function botoesDe(html) {
    return [...html.matchAll(/<button\b([^>]*)>([\s\S]*?)<\/button>/g)].map((m) => ({
        atributos: m[1],
        rotulo: texto(m[2]),
        classe: (m[1].match(/class="([^"]*)"/) || [])[1] ?? '',
        desabilitado: /\bdisabled=/.test(m[1]),
    }));
}

// ═══ AS FIXTURES — ⚠️⚠️ ELAS DISCRIMINAM DE PROPÓSITO ═════════════════════
//
// ⚠️ Na wave 187-06 as duas mutações sobreviventes eram falha da FIXTURE, não da
// asserção. A 188-04 criou o Premium PRIMEIRO, a 188-08 pôs
// `com_alerta_restantes = 3` com UMA foto na lista, e a 188-09 mandou o selo
// PREENCHIDO com `simulando: false` (o payload contraditório que a tela não pode
// acreditar).
// ➡️ Aqui: `fotos_a_conferir` aparece nas DUAS cores com a MESMA chave; os dois
// `null` da PERGUNTA-188-B vêm COM texto; e o caso `live: false` manda
// `destino`/`correcao` **PREENCHIDOS** — sem isso o gate mediria o SERVIDOR (que
// já os zera) em vez da TELA.

/** (2) `photos` COM alerta — ⚠️ VERMELHA, o ramo mais fácil de omitir (`:1295`). */
const P_FOTOS_COM_ALERTA = {
    chave: 'fotos_a_conferir',
    texto: '3 de 6 fotos aprovadas · 1 com alerta automático',
    correcao: 'Conferir fotos',
    severidade: 'vermelho',
    glifo: '!',
    destino: { tipo: 'foto', imagem_id: 910 },
};

/** (2) `photos` SEM alerta — ÂMBAR, **a MESMA chave**. É o par que discrimina. */
const P_FOTOS_SEM_ALERTA = {
    ...P_FOTOS_COM_ALERTA,
    texto: '3 de 6 fotos aprovadas',
    severidade: 'ambar',
    destino: { tipo: 'foto', imagem_id: 911 },
};

/** (3) `nophoto` — ⚠️ `imagem_id` **null** de propósito: não há imagem nenhuma. */
const P_SEM_FOTOS = {
    chave: 'sem_fotos',
    texto: 'Nenhuma foto no conjunto final',
    correcao: 'Adicionar fotos',
    severidade: 'vermelho',
    glifo: '!',
    destino: { tipo: 'foto', imagem_id: null },
};

/** (4a) `freight` — o PRIMEIRO destino: o modal de frete. */
const P_FRETE = {
    chave: 'frete_ausente',
    texto: 'Frete do vendedor não confirmado · resultado indisponível',
    correcao: 'Informar frete',
    severidade: 'vermelho',
    glifo: '!',
    destino: { tipo: 'modal', modal: 'frete' },
};

/**
 * (4b) `freight` — o SEGUNDO destino, **MEDIDO pela wave 188-05**: quando o que
 * falta é o CUSTO do produto, a 186 troca a correção para "Solicitar ajuste" e o
 * destino acompanha. ⚠️ **Cravar o modal de frete nesta chave faria o rótulo
 * apontar para um lugar e o destino para outro.**
 */
const P_FRETE_OU_CUSTO = {
    ...P_FRETE,
    texto: 'Custo do produto não confirmado · resultado indisponível',
    correcao: 'Solicitar ajuste',
    destino: { tipo: 'modal', modal: 'ajustes' },
};

/** (5) `loss` — a exceção, com o TIPO do que se autoriza. */
const P_PREJUIZO = {
    chave: 'resultado_negativo',
    texto: 'Resultado negativo: − R$ 8,10 por venda',
    correcao: 'Solicitar exceção',
    severidade: 'vermelho',
    glifo: '!',
    destino: { tipo: 'modal', modal: 'excecao', excecao: 'resultado_negativo' },
};

/** (6) `margin` — a MESMA porta, outro TIPO. ⚠️ A exceção libera SÓ estes dois. */
const P_MARGEM = {
    chave: 'margem_abaixo_do_minimo',
    texto: 'Margem de 7,4% abaixo do mínimo de 12%',
    correcao: 'Solicitar exceção',
    severidade: 'ambar',
    glifo: '!',
    destino: { tipo: 'modal', modal: 'excecao', excecao: 'margem_abaixo_do_minimo' },
};

/** (7) `change` — ⚠️ confere **SEM alterar o preço** (`:1176`). */
const P_ALTERACAO = {
    chave: 'alteracao_nao_conferida',
    texto: 'Preço alterado por Marina · 18/10 14:02: R$ 139,90 → R$ 149,90',
    correcao: 'Conferir alteração',
    severidade: 'ambar',
    glifo: '!',
    destino: { tipo: 'acao', acao: 'conferir_alteracao' },
};

/** (8) `adjust` — ⚠️⚠️ PERGUNTA-188-B: `null`, **e o TEXTO fica**. */
const P_AJUSTE_SEM_ROTA = {
    chave: 'ajuste_em_aberto',
    texto: 'Ajuste solicitado em aberto · aguardando quem pediu',
    correcao: null,
    severidade: 'ambar',
    glifo: '!',
    destino: null,
};

/** (9) `title` — ⚠️⚠️ PERGUNTA-188-B: `null`, **e o TEXTO fica**. */
const P_TITULO_SEM_ROTA = {
    chave: 'titulo_longo',
    texto: 'Título com 71 caracteres · acima do limite de 60',
    correcao: null,
    severidade: 'ambar',
    glifo: '!',
    destino: null,
};

/** (1) `prep` — o protótipo já dá `fix: ''` (`:1089`): não há nada a fazer. */
const P_EM_PREPARACAO = {
    chave: 'em_preparacao',
    texto: 'Sistema ainda preparando as fotos',
    correcao: null,
    severidade: 'ambar',
    glifo: '!',
    destino: null,
};

/** OS NOVE, na ordem literal de `info()` (`:1089`-`:1097`). */
const OS_NOVE = [
    P_EM_PREPARACAO, P_FOTOS_COM_ALERTA, P_SEM_FOTOS, P_FRETE,
    P_PREJUIZO, P_MARGEM, P_ALTERACAO, P_AJUSTE_SEM_ROTA, P_TITULO_SEM_ROTA,
];

/** As ações que a página fornece. ⚠️ Montadas por caso, para medir cada ponta. */
function acoesEspiao(registro) {
    return {
        focarFoto: (id) => registro.push(['foto', id]),
        abrirModal: (modal, excecao) => registro.push(['modal', modal, excecao ?? null]),
        conferirAlteracao: () => registro.push(['acao', 'conferir_alteracao']),
    };
}

/** Os props completos da seção, com empresa/oferta/versão de VERDADE. */
function propsDaSecao(extra) {
    return {
        pendencias: OS_NOVE,
        live: true,
        empresaId: 459,
        ofertaId: 7701,
        versao: 4,
        onFocarFoto: nada,
        onAbrirModal: nada,
        ...(extra ?? {}),
    };
}

/**
 * ⚠️⚠️ As 17 FORMAS ADVERSAS. `typeof NaN` é `'number'`, `null` e array são os
 * dois `typeof 'object'`, e `{toString: null}` **LANÇA** em `String(valor)`, em
 * `hasOwnProperty` e em **qualquer `obj[chave]`**.
 */
const ADVERSOS = [
    null, undefined, {}, { toString: null }, { valueOf: null }, [], [1, 2], 0, -1, 1.5,
    NaN, Infinity, '', '   ', true, false, () => {},
];

// ═══ OS BUNDLES — ⚠️ TODOS montados ANTES do primeiro `test()` ═════════════

const M_PENDENCIAS = await montar(REL_PENDENCIAS);
const M_RODAPE = await montar(REL_RODAPE);
const M_VOCABULARIO = await montar(REL_VOCABULARIO);
const M_ANDAMENTO = await montar(REL_ANDAMENTO);

const PendenciasDoPainel = M_PENDENCIAS.default;
const { ItemDePendencia, SemPendencia, acaoDoDestino, escritaDaPendencia } = M_PENDENCIAS;

const RodapeDoPainel = M_RODAPE.default;
const { BotaoDeAprovar, BotaoDoRodape, MotivoDoRodape, escritasDoRodape } = M_RODAPE;

// ═══ ⚠️⚠️ A SONDA DO `disabled=`, ANCORADA NO BOTÃO DE APROVAR ════════════
//
// ⚠️⚠️ **`/disabled=/` e NUNCA `/disabled/`:** a segunda forma casa com a CLASSE
// `disabled:opacity-40` e o gate passa com o botão **HABILITADO**. A armadilha
// vem escrita no próprio critério 8, e é a mesma família do `/0%/` que casava
// "100%" e do `includes('h-[64px]')` que casava `min-h-[64px]`.
//
// ⚠️⚠️ **E A REGEX SOZINHA NÃO BASTA.** Lida sobre o HTML do RODAPÉ INTEIRO ela é
// gate LARGO: um `disabled=""` em **"Salvar revisão"** a satisfaria com o botão
// de aprovar HABILITADO e bloqueio de pé. ➡️ O elemento é localizado por
// **ÁRVORE COM ÍNDICE ABSOLUTO** de `<button>` e conferido pelo seu **RÓTULO
// ÚNICO**, e só então o atributo é lido. ⚠️ `els[0]` mede o irmão errado — a wave
// 188-08 caiu nisso, e QUATRO gates deste projeto mediam o irmão errado por
// localizar o elemento pela lista de classes.
/**
 * O `<button>` de aprovar, pelo índice ABSOLUTO na árvore de botões, conferido
 * pelo rótulo. `null` = ele não foi desenhado.
 */
function botaoDeAprovarEm(html) {
    const todos = botoesDe(html);
    const indice = todos.findIndex((b) => b.rotulo === 'Aprovar para publicação');

    return indice < 0 ? null : { ...todos[indice], indice, total: todos.length };
}

/** As chamadas que o stub gravou — ⚠️ lidas do `globalThis`, não de um import. */
const CHAMADAS = globalThis.CHAMADAS_RODAPE_188_10 ?? [];

// ═══════════════════════════════════════════════════════════════════════════
// 1. ANTI-FANTASMA
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ ANTI-FANTASMA: todo caminho citado por este gate existe no disco 188_10', () => {
    const fantasmas = CAMINHOS_CITADOS.filter((rel) => !fs.existsSync(path.resolve(RAIZ, rel)));
    assert.deepEqual(fantasmas, [], 'caminho citado que não existe — toda sonda de ausência sobre ele passa CALADA');

    // ⚠️ ISCA: a sonda PEGA um caminho que não existe.
    assert.equal(fs.existsSync(path.resolve(RAIZ, `${DIR_APROV}/NaoExiste188x10.jsx`)), false, 'a isca do anti-fantasma virou arquivo de verdade');
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. OS NOVE DESTINOS — ⚠️⚠️ A TELA OBEDECE, ELA NÃO DECIDE (critério 9)
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ CRITÉRIO 9: os QUATRO tipos de destino levam ao LUGAR CERTO, lidos do payload 188_10', () => {
    // (a) `foto` → seleciona AQUELA imagem (a resolvida pelo SERVIDOR).
    const r1 = [];
    acaoDoDestino(P_FOTOS_COM_ALERTA.destino, acoesEspiao(r1))();
    assert.deepEqual(r1, [['foto', 910]], 'o destino `foto` não levou à imagem que o servidor resolveu');

    // ⚠️ E a outra pendência de foto leva a OUTRA imagem: se a tela ignorasse o
    // `imagem_id` e sempre abrisse a primeira, este caso passaria no de cima.
    const r2 = [];
    acaoDoDestino(P_FOTOS_SEM_ALERTA.destino, acoesEspiao(r2))();
    assert.deepEqual(r2, [['foto', 911]], 'o destino `foto` ignorou o `imagem_id` do payload');

    // (b) `modal:frete` → o modal de frete.
    const r3 = [];
    acaoDoDestino(P_FRETE.destino, acoesEspiao(r3))();
    assert.deepEqual(r3, [['modal', 'frete', null]], 'o destino `modal:frete` não abriu o frete');

    // ⚠️⚠️ (b2) O SEGUNDO destino de `frete_ausente` — MEDIDO pela wave 188-05.
    // Cravar o modal de frete nesta chave faria o rótulo "Solicitar ajuste"
    // apontar para o modal de FRETE.
    const r4 = [];
    acaoDoDestino(P_FRETE_OU_CUSTO.destino, acoesEspiao(r4))();
    assert.deepEqual(r4, [['modal', 'ajustes', null]], 'o SEGUNDO destino de `frete_ausente` foi cravado no modal de frete');

    // (c) `modal:excecao` → a exceção, **com o TIPO**.
    const r5 = [];
    acaoDoDestino(P_PREJUIZO.destino, acoesEspiao(r5))();
    assert.deepEqual(r5, [['modal', 'excecao', 'resultado_negativo']], 'o destino de prejuízo não levou o TIPO da exceção');

    const r6 = [];
    acaoDoDestino(P_MARGEM.destino, acoesEspiao(r6))();
    assert.deepEqual(r6, [['modal', 'excecao', 'margem_abaixo_do_minimo']], 'o destino de margem não levou o TIPO da exceção');

    // (d) `acao:conferir_alteracao` → a ação, **sem alterar o preço**.
    const r7 = [];
    acaoDoDestino(P_ALTERACAO.destino, acoesEspiao(r7))();
    assert.deepEqual(r7, [['acao', 'conferir_alteracao']], 'o destino `acao` não chamou a conferência');

    // ⚠️ `sem_fotos` manda `imagem_id: null` DE PROPÓSITO (não há imagem): o
    // destino continua sendo a coluna, e ela DIZ que está vazia (IMG-12).
    const r8 = [];
    acaoDoDestino(P_SEM_FOTOS.destino, acoesEspiao(r8))();
    assert.deepEqual(r8, [['foto', null]], '`sem_fotos` deixou de levar à coluna de fotos com `imagem_id` nulo');
});

test('⚠️⚠️ A TELA NÃO TEM `switch` POR CHAVE DE BLOQUEIO — ela obedece o `destino` 188_10', () => {
    // ⚠️⚠️ Um `switch`/`if` pela CHAVE do bloqueio aqui seria a SEGUNDA VERDADE
    // sobre "para onde vai cada correção", e a divergente só apareceria NO
    // CLIQUE. ➡️ A sonda procura as NOVE chaves de bloqueio na fonte SEM
    // COMENTÁRIO: elas não podem aparecer no código desta tela.
    const CHAVES_DE_BLOQUEIO = [
        'fotos_a_conferir', 'sem_fotos', 'frete_ausente', 'resultado_negativo',
        'margem_abaixo_do_minimo', 'alteracao_nao_conferida', 'ajuste_em_aberto',
        'titulo_longo', 'em_preparacao',
    ];
    const infratores = CHAVES_DE_BLOQUEIO.filter((chave) => FONTE_PENDENCIAS.includes(chave));
    assert.deepEqual(infratores, [], 'a tela nomeia chave de BLOQUEIO — isso é o `switch` que o servidor existe para evitar');

    // ⚠️ ISCA NAS DUAS DIREÇÕES: a sonda pega o que DEVE pegar (o servidor as
    // nomeia, e é lá que elas moram) e a leitura não está cega.
    const servico = fs.readFileSync(path.resolve(RAIZ, REL_VOCABULARIO_PHP), 'utf8');
    const noServidor = CHAVES_DE_BLOQUEIO.filter((chave) => servico.includes(chave));
    assert.equal(noServidor.length, CHAVES_DE_BLOQUEIO.length, 'a sonda está cega: nem no servidor ela acha as chaves');

    // ⚠️ E o que a tela PODE nomear são os TIPOS de destino, que são o contrato.
    for (const tipo of ['foto', 'modal', 'acao', 'conferir_alteracao']) {
        assert.ok(FONTE_PENDENCIAS.includes(`'${tipo}'`), `o tipo de destino \`${tipo}\` saiu da tela — ela não saberia obedecer`);
        assert.ok(
            fs.readFileSync(path.resolve(RAIZ, REL_SERVICO_PAINEL), 'utf8').includes(`'${tipo}'`),
            `o tipo \`${tipo}\` saiu do SERVIDOR — a tela obedeceria um contrato que não existe`,
        );
    }
});

test('⚠️⚠️ PERGUNTA-188-B: destino `null` dá ZERO botão, e o TEXTO CONTINUA aparecendo 188_10', () => {
    const html = render(PendenciasDoPainel, propsDaSecao({ ...acoesEspiao([]) }));
    const conteudo = texto(html);
    const botoes = botoesDe(html);

    // ⚠️⚠️ O TEXTO dos DOIS fica. Sem ele a pessoa não saberia o que trava.
    assert.ok(conteudo.includes(P_AJUSTE_SEM_ROTA.texto), 'o TEXTO de `ajuste_em_aberto` desapareceu junto com o botão');
    assert.ok(conteudo.includes(P_TITULO_SEM_ROTA.texto), 'o TEXTO de `titulo_longo` desapareceu junto com o botão');
    assert.ok(conteudo.includes(P_EM_PREPARACAO.texto), 'o TEXTO de `em_preparacao` desapareceu junto com o botão');

    // ⚠️ E NENHUM dos três produziu botão: são SEIS botões (os seis com destino),
    // nunca nove.
    assert.equal(botoes.length, 6, `o número de botões não é 6: ${botoes.map((b) => b.rotulo).join(' | ')}`);

    // ⚠️⚠️ ZERO `onClick` vazio e ZERO `disabled` decorativo — as duas formas
    // foram OFERECIDAS e RECUSADAS (PERGUNTA-188-B3).
    const decorativos = botoes.filter((b) => b.desabilitado);
    assert.deepEqual(decorativos.map((b) => b.rotulo), [], 'botão de correção nasceu `disabled` — `disabled` decorativo é D4 violado');
    assert.doesNotMatch(FONTE_PENDENCIAS, /onClick=\{\(\)\s*=>\s*\{\s*\}\}/, '`onClick` vazio na tela — é a mesma dívida do botão sem destino');
    assert.doesNotMatch(FONTE_PENDENCIAS, /\bdisabled\b/, 'a seção de pendências passou a usar `disabled` — sem destino o botão NÃO É DESENHADO');

    // ⚠️ ISCA: a mesma pendência COM destino produz botão. Sem esta direção o
    // caso acima passaria com a tela nunca desenhando botão nenhum.
    const comRota = render(PendenciasDoPainel, propsDaSecao({
        pendencias: [{ ...P_AJUSTE_SEM_ROTA, correcao: 'Ver ajustes', destino: { tipo: 'modal', modal: 'ajustes' } }],
        ...acoesEspiao([]),
    }));
    assert.equal(botoesDe(comRota).length, 1, 'a isca falhou: a tela não desenha botão nem COM destino — a sonda está cega');
    assert.equal(botoesDe(comRota)[0].rotulo, 'Ver ajustes', 'o rótulo do botão não é o `correcao` do servidor');
});

test('⚠️⚠️ `:1295`: `fotos_a_conferir` é VERMELHA com alerta e ÂMBAR sem — a MESMA chave 188_10', () => {
    const comAlerta = render(ItemDePendencia, { pendencia: P_FOTOS_COM_ALERTA, acoes: acoesEspiao([]) });
    const semAlerta = render(ItemDePendencia, { pendencia: P_FOTOS_SEM_ALERTA, acoes: acoesEspiao([]) });

    // ⚠️ O GLIFO é o elemento 1 da árvore de cada item (índice ABSOLUTO), nunca
    // localizado por `indexOf` de classes.
    const glifoCom = tagsDeAbertura(comAlerta)[1];
    const glifoSem = tagsDeAbertura(semAlerta)[1];

    assert.ok(classesDe(glifoCom).has('text-pub-danger-txt'), '`fotos_a_conferir` COM alerta não ficou vermelha (`:1295`)');
    assert.ok(classesDe(glifoCom).has('bg-pub-danger/[0.14]'), 'o FUNDO do glifo vermelho não é `rgba(239,68,68,0.14)`');
    assert.ok(classesDe(glifoSem).has('text-pub-warn-txt'), '`fotos_a_conferir` SEM alerta não ficou âmbar');
    assert.ok(classesDe(glifoSem).has('bg-pub-warn/[0.14]'), 'o FUNDO do glifo âmbar não é `rgba(245,158,11,0.14)`');

    // ⚠️⚠️ E as DUAS cores são DISTINTAS: duas iguais apagariam a diferença entre
    // "há alerta automático nesta foto" e "só falta conferir".
    assert.notDeepEqual(
        [...classesDe(glifoCom)].sort(),
        [...classesDe(glifoSem)].sort(),
        'as duas severidades de `fotos_a_conferir` saíram com as MESMAS classes — a distinção sumiu',
    );

    // ⚠️ E a chave é a MESMA nas duas: se a tela recalculasse a cor pela CHAVE,
    // as duas sairiam iguais e este caso cairia.
    assert.equal(P_FOTOS_COM_ALERTA.chave, P_FOTOS_SEM_ALERTA.chave, 'a fixture perdeu o par que discrimina');

    // ⚠️ A severidade é LIDA, não recalculada: a palavra `severidade` aparece e
    // nenhuma lista de "sempre vermelhos" mora nesta tela.
    assert.ok(FONTE_PENDENCIAS.includes('severidade'), 'a tela deixou de ler a severidade do payload');
    assert.doesNotMatch(FONTE_PENDENCIAS, /VERMELHOS|comAlerta|alerts\.length/, 'a tela recalcula a severidade — é a segunda verdade da MESMA cor');
});

test('⚠️⚠️ `hasFix && live`: fora do `live` NENHUM botão, com o payload CONTRADITÓRIO 188_10', () => {
    // ⚠️⚠️ A FIXTURE QUE DISCRIMINA: `destino` e `correcao` chegam PREENCHIDOS
    // com `live: false`. O servidor já os zera fora do `live` — sem este payload
    // contraditório o gate mediria o SERVIDOR, não a TELA, e a guarda daqui
    // ficaria INOBSERVÁVEL (foi exatamente a sobrevivente da wave 188-09).
    const html = render(PendenciasDoPainel, propsDaSecao({ live: false, ...acoesEspiao([]) }));
    assert.deepEqual(botoesDe(html).map((b) => b.rotulo), [], 'fora do `live` a tela ofereceu botão de correção sobre payload contraditório');

    // ⚠️ E os TEXTOS continuam: oferta publicada continua dizendo o que travava.
    const conteudo = texto(html);
    for (const p of OS_NOVE) {
        assert.ok(conteudo.includes(p.texto), `o texto de \`${p.chave}\` desapareceu fora do \`live\``);
    }

    // ⚠️ ISCA: COM `live` os seis botões voltam.
    const vivo = render(PendenciasDoPainel, propsDaSecao({ live: true, ...acoesEspiao([]) }));
    assert.equal(botoesDe(vivo).length, 6, 'a isca falhou: a tela não desenha botão nem COM `live`');

    // ⚠️ E `live` por FORMA: só `true` vale. `'true'`, `1` e `{}` são verdadeiros
    // em JS e NÃO podem destravar a tela.
    for (const quase of ['true', 1, {}, [], 'sim']) {
        const h = render(PendenciasDoPainel, propsDaSecao({ live: quase, ...acoesEspiao([]) }));
        assert.deepEqual(botoesDe(h).map((b) => b.rotulo), [], `\`live\` aceitou um valor que não é \`true\` (${typeof quase})`);
    }
});

test('⚠️⚠️ D7: TODO item tem TEXTO, e item SEM texto não é desenhado 188_10', () => {
    // ⚠️ Cor nunca é o único sinal: o TEXTO é o sinal, e o glifo acompanha.
    for (const p of OS_NOVE) {
        const html = render(ItemDePendencia, { pendencia: p, acoes: acoesEspiao([]) });
        assert.ok(texto(html).includes(p.texto), `o item de \`${p.chave}\` saiu SEM o texto — sobraria só a cor`);
        // ⚠️ E o glifo vem junto, nas DUAS severidades.
        assert.ok(texto(html).startsWith(p.glifo), `o item de \`${p.chave}\` perdeu o glifo \`${p.glifo}\``);
    }

    // ⚠️ Item sem texto **não é desenhado**: círculo colorido sozinho não diz
    // nada a quem não distingue âmbar de vermelho.
    for (const semTexto of [null, undefined, '', '   ', {}, [], 0, NaN]) {
        const html = render(ItemDePendencia, { pendencia: { ...P_FRETE, texto: semTexto }, acoes: acoesEspiao([]) });
        assert.equal(html, '', `item com texto \`${typeof semTexto}\` foi desenhado — sobraria só a cor`);
    }
});

test('⚠️ `:708`: sem pendência nenhuma, a mensagem VERDE com o glifo ✓ 188_10', () => {
    const html = render(PendenciasDoPainel, propsDaSecao({ pendencias: [], ...acoesEspiao([]) }));

    assert.ok(texto(html).includes(M_PENDENCIAS.SEM_PENDENCIA_TEXTO), 'a mensagem verde de `:708` não apareceu');
    assert.ok(texto(html).includes('✓'), 'o glifo ✓ de `:708` não apareceu');
    assert.deepEqual(botoesDe(html).map((b) => b.rotulo), [], 'sem pendência a tela desenhou botão de correção');

    // ⚠️ E ela NÃO aparece quando há pendência: afirmar "nenhuma pendência" com a
    // oferta travada seria a tela mentindo numa tela em que aprovar JÁ PUBLICA.
    const comPendencia = render(PendenciasDoPainel, propsDaSecao({ ...acoesEspiao([]) }));
    assert.ok(!texto(comPendencia).includes(M_PENDENCIAS.SEM_PENDENCIA_TEXTO), 'a mensagem verde apareceu COM pendência de pé');

    // ⚠️⚠️ CHAVE AUSENTE × LISTA VAZIA são casos DIFERENTES. Sem a lista a seção
    // **não é desenhada** (não se sabe); com a lista vazia ela DIZ que não há.
    for (const ausente of [null, undefined, {}, 'nada', 7, NaN]) {
        const h = render(PendenciasDoPainel, propsDaSecao({ pendencias: ausente, ...acoesEspiao([]) }));
        assert.equal(h, '', `\`pendencias\` ${typeof ausente} desenhou a seção — afirmaria "nada bloqueia" sem dado`);
    }

    // ⚠️⚠️ E o literal de `:708` NÃO é o do rodapé: são DUAS frases, em dois
    // lugares da MESMA tela. Confundi-las apagaria uma das duas.
    assert.notEqual(M_PENDENCIAS.SEM_PENDENCIA_TEXTO, M_VOCABULARIO.TUDO_CONFERIDO, 'a frase de `:708` virou a do rodapé — o protótipo as escreve distintas');
    assert.ok(!FONTE_PENDENCIAS.includes(M_VOCABULARIO.TUDO_CONFERIDO), 'a seção de pendências copiou o literal do RODAPÉ');
});

test('⚠️⚠️ T-188-92: `tipo` desconhecido dá ZERO botão, nunca um que chama `undefined` 188_10', () => {
    const registro = [];
    const acoes = acoesEspiao(registro);

    // ⚠️ `tipo` fora dos três conhecidos.
    for (const tipo of ['lightbox', 'rota', 'detalhada', 'FOTO', 'foto ', '', null, 7, {}, []]) {
        assert.equal(acaoDoDestino({ tipo }, acoes), null, `o tipo \`${String(typeof tipo)}\` produziu ação`);
    }

    // ⚠️ `modal` fora da lista FECHADA — inclusive o quase-igual.
    for (const modal of ['frete2', 'fret', 'excecoes', 'ajuste', 'FRETE', '', null, {}]) {
        assert.equal(acaoDoDestino({ tipo: 'modal', modal }, acoes), null, `o modal \`${String(modal)}\` abriu alguma coisa`);
    }

    // ⚠️ `acao` fora da única conhecida.
    for (const acao of ['conferir', 'conferir_alteracao2', 'aprovar', '', null, {}]) {
        assert.equal(acaoDoDestino({ tipo: 'acao', acao }, acoes), null, `a ação \`${String(acao)}\` virou botão`);
    }

    // ⚠️ `destino` adverso inteiro.
    for (const adverso of ADVERSOS) {
        assert.doesNotThrow(() => acaoDoDestino(adverso, acoes), `\`acaoDoDestino\` LANÇOU com destino ${typeof adverso}`);
        assert.equal(acaoDoDestino(adverso, acoes), null, `destino ${typeof adverso} produziu ação`);
    }

    // ⚠️ E NADA foi chamado em nenhum dos casos acima.
    assert.deepEqual(registro, [], 'um destino desconhecido disparou ação');

    // ⚠️ ISCA: os três tipos CERTOS continuam produzindo ação. Sem esta direção
    // o caso acima passaria com `acaoDoDestino` devolvendo `null` para tudo.
    assert.equal(typeof acaoDoDestino(P_FOTOS_COM_ALERTA.destino, acoes), 'function', 'a isca falhou: nem o destino certo produz ação');
    assert.equal(typeof acaoDoDestino(P_FRETE.destino, acoes), 'function', 'a isca falhou: nem o modal certo produz ação');
    assert.equal(typeof acaoDoDestino(P_ALTERACAO.destino, acoes), 'function', 'a isca falhou: nem a ação certa produz ação');

    // ⚠️ E sem HANDLER, nenhuma ação: os modais são das waves 188-11/12, e sem
    // eles o botão NÃO É DESENHADO (D4).
    assert.equal(acaoDoDestino(P_FRETE.destino, {}), null, 'o modal abriu sem handler — botão sem destino');
    assert.equal(acaoDoDestino(P_FOTOS_COM_ALERTA.destino, {}), null, 'a foto focou sem handler');
    assert.equal(acaoDoDestino(P_ALTERACAO.destino, {}), null, 'a ação disparou sem handler');
    for (const adverso of ADVERSOS) {
        assert.doesNotThrow(() => acaoDoDestino(P_FRETE.destino, adverso), `\`acaoDoDestino\` LANÇOU com \`acoes\` ${typeof adverso}`);
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. O HELPER ÚNICO DE ESCRITA — ⚠️⚠️ CRITÉRIO 17 / COL-04 / W11
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ CRITÉRIO 17: a escrita de BLOQ-07 passa por `escreverNoPainel` COM `versao_esperada` 188_10', () => {
    CHAMADAS.length = 0;

    const acao = escritaDaPendencia({ empresaId: 459, ofertaId: 7701, versao: 4 }).conferirAlteracao;
    assert.equal(typeof acao, 'function', 'a escrita de BLOQ-07 não nasceu com empresa, oferta e versão');
    assert.equal(acao(), true, 'o helper recusou a escrita com os três dados presentes');

    assert.equal(CHAMADAS.length, 1, `a escrita não saiu exatamente uma vez: ${CHAMADAS.length}`);
    const [metodo, url, corpo] = CHAMADAS[0];
    assert.equal(metodo, 'post', 'a escrita saiu por outro verbo');
    assert.ok(url.includes('conferir-alteracao'), `a rota não é a de BLOQ-07: ${url}`);

    // ⚠️⚠️ `versao_esperada` SEMPRE no corpo. OMITI-LA faz `exigirVersao()`
    // receber `null` e **LIBERAR** a escrita — *"sobrescrever em silêncio"*.
    assert.equal(corpo.versao_esperada, 4, 'a escrita saiu SEM `versao_esperada` — é sobrescrever em silêncio');

    // ⚠️ Sem empresa, sem oferta ou sem versão **não há handler** — e sem handler
    // o botão não é desenhado. ⚠️ E `0` NÃO vale: é id plausível, e a escrita
    // sairia para uma rota que não existe.
    for (const faltando of [
        { empresaId: null, ofertaId: 7701, versao: 4 },
        { empresaId: 459, ofertaId: null, versao: 4 },
        { empresaId: 459, ofertaId: 7701, versao: null },
        { empresaId: 0, ofertaId: 7701, versao: 4 },
        { empresaId: 459, ofertaId: 0, versao: 4 },
        { empresaId: 459, ofertaId: 7701, versao: 0 },
        { empresaId: -1, ofertaId: 7701, versao: 4 },
        { empresaId: '459', ofertaId: 7701, versao: 4 },
        { empresaId: 459, ofertaId: 7701, versao: 1.5 },
    ]) {
        assert.equal(
            escritaDaPendencia(faltando).conferirAlteracao,
            null,
            `a escrita nasceu com dado inválido: ${JSON.stringify(faltando)}`,
        );
    }

    // ⚠️ E com pedido adverso ela não LANÇA.
    for (const adverso of ADVERSOS) {
        assert.doesNotThrow(() => escritaDaPendencia(adverso), `\`escritaDaPendencia\` LANÇOU com ${typeof adverso}`);
        assert.equal(escritaDaPendencia(adverso).conferirAlteracao, null, `a escrita nasceu com pedido ${typeof adverso}`);
    }

    // ⚠️ Nenhuma outra chamada saiu durante as recusas.
    assert.equal(CHAMADAS.length, 1, 'uma das recusas disparou escrita');
    CHAMADAS.length = 0;
});

test('⚠️⚠️ NENHUM `router.post` direto: a lista é CONSTANTE, com isca nas duas direções 188_10', () => {
    // ⚠️ `router.post` direto fura o helper e deixa `versao_esperada` de fora.
    const diretos = FONTES_DO_PLANO.filter(([, fonte]) => /\brouter\s*\.\s*(post|put|delete|patch)\s*\(/.test(fonte));
    assert.deepEqual(diretos.map(([rel]) => rel), [], '`router.post` direto — a escrita tem de ir pelo helper `escreverNoPainel`');

    // ⚠️ E a tela USA o helper: ausência sem presença é gate sobre zero casos.
    assert.match(FONTE_PENDENCIAS, /escreverNoPainel\(\{/, 'a seção de pendências deixou de usar o helper único de escrita');

    // ⚠️ ISCA: a sonda PEGA a forma que ela deve pegar.
    assert.ok(/\brouter\s*\.\s*(post|put|delete|patch)\s*\(/.test('router.post(route("x"), {})'), 'a sonda de `router.post` está furada');
    assert.ok(/\brouter\s*\.\s*(post|put|delete|patch)\s*\(/.test('router . post ( x )'), 'a sonda não tolera espaço — `router . post(` passaria');

    // ⚠️ E o helper continua pondo a chave: se `CHAVE_DA_VERSAO` mudar de nome,
    // a prova acima mediria outra coisa.
    assert.equal(M_PENDENCIAS.ROTA_CONFERIR_ALTERACAO, 'mlb.anuncios.meus.aprovacao.painel.conferir-alteracao', 'o nome da rota de BLOQ-07 mudou');
    const rotas = fs.readFileSync(path.resolve(RAIZ, REL_ROTAS), 'utf8');
    assert.ok(rotas.includes("name('meus.aprovacao.painel.conferir-alteracao')"), 'a rota de BLOQ-07 saiu de `routes/mlb_anuncios.php` — o botão apontaria para o nada');
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. A GEOMETRIA — ⚠️ IGUALDADE DE CONJUNTO, por ÁRVORE COM ÍNDICE ABSOLUTO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ FID: a GEOMETRIA de `:699`-`:708`, por IGUALDADE e com isca do quase-igual 188_10', () => {
    const html = render(PendenciasDoPainel, propsDaSecao({ pendencias: [P_FRETE], ...acoesEspiao([]) }));
    const arvore = tagsDeAbertura(html);

    // ⚠️ ÍNDICE ABSOLUTO: 0 `<section>` · 1 título · 2 item · 3 glifo · 4 texto.
    assert.equal(arvore[0].tag, 'section', 'a raiz da seção não é `<section>` (`:699`)');
    assert.deepEqual(
        [...classesDe(arvore[0])].sort(),
        ['bg-pub-surface', 'border', 'border-pub-line', 'flex', 'flex-col', 'gap-[8px]', 'p-[14px]', 'rounded-pub-table'],
        'as classes de `:699` divergiram',
    );
    assert.deepEqual([...classesDe(arvore[1])].sort(), ['font-semibold', 'text-[15px]'], 'as classes do título de `:700` divergiram');
    assert.deepEqual(
        [...classesDe(arvore[2])].sort(),
        ['flex', 'gap-[10px]', 'items-center', 'min-h-[34px]'],
        'as classes do item de `:702` divergiram',
    );

    // ⚠️⚠️ `min-h-[34px]` CONTÉM a substring `h-[34px]` — ANCORE NO COMEÇO. A
    // 187-08 caiu nisso com `min-h-[64px]`, e a primeira redação daquele gate
    // REPROVOU CÓDIGO CERTO.
    const doItem = classesDe(arvore[2]);
    assert.ok(doItem.has('min-h-[34px]'), 'o item perdeu o `min-height:34px` de `:702`');
    assert.equal(doItem.has('h-[34px]'), false, '⚠️ o item ganhou `h-[34px]` — ALTURA FIXA em vez de MÍNIMA: texto de duas linhas seria cortado');
    // ⚠️ E a prova de que a ancoragem distingue as duas: `includes` NÃO distingue.
    assert.ok('min-h-[34px]'.includes('h-[34px]'), 'a premissa da armadilha caiu — remeça a sonda');

    // ⚠️ O GLIFO (`:703`) — `grid place-items-center` é OMISSÃO MEDIDA da §E.
    assert.deepEqual(
        [...classesDe(arvore[3])].sort(),
        ['bg-pub-danger/[0.14]', 'flex-none', 'font-[800]', 'grid', 'h-[18px]', 'place-items-center', 'rounded-full', 'text-[10.5px]', 'text-pub-danger-txt', 'w-[18px]'],
        'as classes do glifo de `:703` divergiram — `grid place-items-center` é o que CENTRA o `!`',
    );
    // ⚠️ `font-[800]` e NÃO `font-extrabold`: o GATE-03 fecha a escala nomeada.
    assert.equal(classesDe(arvore[3]).has('font-extrabold'), false, '`font-extrabold` entrou — o GATE-03 proíbe a escala nomeada');

    assert.deepEqual([...classesDe(arvore[4])].sort(), ['flex-1', 'text-[13px]', 'text-pub-fase1-2'], 'as classes do texto de `:704` divergiram');

    // ⚠️ O BOTÃO (`:705`) — `white-space:nowrap` é a PRIMEIRA propriedade da
    // linha, e ele foi omitido SETE vezes na Fase 187.
    const botao = tagsDeAbertura(html).find((e) => e.tag === 'button');
    assert.deepEqual(
        [...classesDe(botao)].sort(),
        ['bg-pub-hover-2', 'border', 'border-pub-line-strong', 'cursor-pointer', 'h-[28px]', 'hover:border-pub-line-off', 'px-[10px]', 'rounded-pub-control-sm', 'text-[12px]', 'text-pub-txt', 'whitespace-nowrap'],
        'as classes do botão de `:705` divergiram',
    );

    // ⚠️⚠️ `:705` e `:590` são MEDIDOS IDÊNTICOS byte a byte no protótipo, e as
    // DUAS constantes ficam PRÓPRIAS de propósito: importar a do vizinho faria
    // uma mudança em `:590` reescrever `:705` EM SILÊNCIO. Esta asserção é o que
    // torna uma divergência futura VISÍVEL.
    const M_FOTOS_CLASSES = lerSemComentarios(REL_COLUNA_DE_FOTOS).match(/CLASSES_SEM_ALERTA\s*=\s*([\s\S]*?);\n/);
    assert.ok(M_FOTOS_CLASSES, 'não achei `CLASSES_SEM_ALERTA` em `ColunaDeFotos.jsx` — a leitura está cega');
    const doVizinho = new Set([...M_FOTOS_CLASSES[1].matchAll(/'([^']*)'/g)].flatMap((m) => m[1].trim().split(/\s+/)).filter((c) => c !== ''));
    assert.deepEqual(
        [...classesDe(botao)].sort(),
        [...doVizinho].sort(),
        '⚠️ `:705` e `:590` DIVERGIRAM. Os dois foram MEDIDOS idênticos no protótipo: confira qual dos dois mudou, e remeça — NÃO iguale sem medir',
    );

    // ⚠️ A MENSAGEM VERDE (`:708`) e o glifo dela.
    const verde = tagsDeAbertura(render(PendenciasDoPainel, propsDaSecao({ pendencias: [], ...acoesEspiao([]) })));
    assert.deepEqual([...classesDe(verde[2])].sort(), ['flex', 'gap-[10px]', 'items-center', 'text-[13px]', 'text-pub-ok-txt'], 'as classes da mensagem de `:708` divergiram');
    assert.deepEqual(
        [...classesDe(verde[3])].sort(),
        ['bg-pub-ok', 'font-[800]', 'grid', 'h-[18px]', 'place-items-center', 'rounded-full', 'text-[10.5px]', 'text-pub-page', 'w-[18px]'],
        'as classes do glifo ✓ de `:708` divergiram — `grid place-items-center` é o que CENTRA o ✓',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. A TELA PRETA — ⚠️⚠️ RENDER REAL, E TAMBÉM NO CLIQUE
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ REND: nenhum campo adverso das pendências derruba a página 188_10', () => {
    const CAMPOS = ['chave', 'texto', 'correcao', 'severidade', 'glifo', 'destino'];
    const SUJEIRA = /\[object Object\]|NaN|undefined|Invalid Date/;

    for (const campo of CAMPOS) {
        for (const adverso of ADVERSOS) {
            const pendencia = { ...P_FRETE, [campo]: adverso };
            let html = '';
            // ⚠️ **NUNCA `String()` nem `JSON.stringify()` na mensagem**: os dois
            // LANÇAM/MENTEM com `{toString: null}`. A mensagem diz o CAMPO e o
            // `typeof`, que são seguros.
            assert.doesNotThrow(
                () => { html = render(ItemDePendencia, { pendencia, acoes: acoesEspiao([]) }); },
                `o item LANÇOU com \`${campo}\` do tipo ${typeof adverso}`,
            );
            assert.doesNotMatch(html, SUJEIRA, `o item vazou sujeira com \`${campo}\` do tipo ${typeof adverso}`);
        }
    }

    // ⚠️ A LISTA inteira adversa, inclusive com objeto comum no meio: **prop de
    // NÓ chegando objeto comum faz o React LANÇAR** e mata a página.
    const LISTAS = [
        null, undefined, 'nada', 7, NaN, {}, [], [null], [undefined], [{}], [[]],
        [P_FRETE, {}, P_MARGEM], [P_FRETE, null, 'texto', 9, P_ALTERACAO],
        [{ toString: null }], [{ ...P_FRETE, destino: { toString: null } }],
    ];
    for (const pendencias of LISTAS) {
        let html = '';
        assert.doesNotThrow(
            () => { html = render(PendenciasDoPainel, propsDaSecao({ pendencias, ...acoesEspiao([]) })); },
            `a seção LANÇOU com a lista do tipo ${typeof pendencias}`,
        );
        assert.doesNotMatch(html, SUJEIRA, `a seção vazou sujeira com a lista do tipo ${typeof pendencias}`);
    }

    // ⚠️ E a PROP INTEIRA nula, mais cada handler adverso.
    assert.doesNotThrow(() => render(PendenciasDoPainel, {}), 'a seção LANÇOU sem prop nenhuma');
    for (const adverso of ADVERSOS) {
        assert.doesNotThrow(
            () => render(PendenciasDoPainel, propsDaSecao({ onFocarFoto: adverso, onAbrirModal: adverso, empresaId: adverso, ofertaId: adverso, versao: adverso, onConflito: adverso })),
            `a seção LANÇOU com handlers/ids do tipo ${typeof adverso}`,
        );
    }

    // ⚠️ E `String(` NÃO aparece na tela: ele LANÇA com `{toString: null}`, e há
    // gate da 187-04 que já o proíbe na página — está CERTO.
    assert.doesNotMatch(FONTE_PENDENCIAS, /\bString\(/, '`String(` na tela — ele LANÇA com `{toString: null}`');
    assert.doesNotMatch(FONTE_PENDENCIAS, /JSON\.stringify\(/, '`JSON.stringify(` na tela');
});

test('⚠️⚠️ REND: o CLIQUE de cada botão de correção não derruba a página 188_10', () => {
    // ⚠️⚠️ `renderToStaticMarkup` **NÃO DISPARA `onClick`** — a 187-08 exportou o
    // handler por isso, e a TELA PRETA da 187-06 foi **no clique**. ➡️ As ações
    // são exercidas DIRETO.
    const registro = [];
    const acoes = acoesEspiao(registro);

    for (const p of OS_NOVE) {
        const acao = acaoDoDestino(p.destino, acoes);
        if (acao === null) continue;
        assert.doesNotThrow(() => acao(), `o CLIQUE de \`${p.chave}\` LANÇOU`);
    }
    assert.equal(registro.length, 6, `o clique de 6 destinos não produziu 6 ações: ${registro.length}`);

    // ⚠️ E com `imagem_id`/`modal`/`excecao` adversos o clique também não cai.
    for (const adverso of ADVERSOS) {
        for (const destino of [
            { tipo: 'foto', imagem_id: adverso },
            { tipo: 'modal', modal: 'excecao', excecao: adverso },
            { tipo: 'modal', modal: 'frete', excecao: adverso },
            { tipo: 'acao', acao: 'conferir_alteracao', extra: adverso },
        ]) {
            const acao = acaoDoDestino(destino, acoes);
            if (acao === null) continue;
            assert.doesNotThrow(() => acao(), `o CLIQUE LANÇOU com campo do tipo ${typeof adverso}`);
        }
    }

    // ⚠️ E o handler que LANÇA não é engolido em silêncio: ele propaga, porque
    // engolir deixaria a pessoa achando que a correção aconteceu.
    const explosivo = { focarFoto: () => { throw new Error('boom'); }, abrirModal: nada, conferirAlteracao: nada };
    assert.throws(() => acaoDoDestino(P_FOTOS_COM_ALERTA.destino, explosivo)(), /boom/, 'a tela ENGOLIU o erro do handler — a pessoa acharia que a correção aconteceu');
});

test('⚠️⚠️ ROLLUP: nenhuma flag de escopo do componente é lida dentro do `.map()` 188_10', () => {
    // ⚠️⚠️ O Rollup ELIMINA variável de escopo do componente usada dentro de
    // `.map()`, e o bundle sai com `ReferenceError`
    // (`feedback_rollup_map_scope_bug`). A correção MEDIDA na 187-09: a expressão
    // do `.map()` só pode ler **parâmetro** ou **nome de MÓDULO**.
    const corpoDoMap = FONTE_PENDENCIAS.match(/\.map\(\(([^)]*)\)\s*=>\s*\(([\s\S]*?)\n\s*\)\);/);
    assert.ok(corpoDoMap, 'não achei o `.map()` das pendências — a leitura está cega');

    const parametros = corpoDoMap[1].split(',').map((p) => p.trim());
    assert.deepEqual(parametros, ['pendencia', 'indice'], 'os parâmetros do `.map()` mudaram — remeça esta sonda');

    // Os identificadores que a expressão lê, tirando JSX e strings.
    const lidos = new Set([...corpoDoMap[2].matchAll(/\{([A-Za-z_$][\w$]*)\}/g)].map((m) => m[1]));
    const PERMITIDOS = new Set([...parametros, 'acoes']);
    const forasteiros = [...lidos].filter((id) => !PERMITIDOS.has(id));
    assert.deepEqual(forasteiros, [], 'o callback do `.map()` lê identificador que não é parâmetro — o Rollup o elide');

    // ⚠️ `acoes` é computado ANTES do `.map()` e passado como PROP, que é a forma
    // segura: o valor atravessa como atributo de elemento, não como closure sobre
    // variável que o bundler pode elidir.
    assert.match(FONTE_PENDENCIAS, /acoes=\{acoes\}/, '`acoes` deixou de viajar por prop');

    // ⚠️ E o BUNDLE de verdade renderiza: é o que prova que nada foi elidido.
    assert.doesNotThrow(() => render(PendenciasDoPainel, propsDaSecao({ ...acoesEspiao([]) })), 'o bundle LANÇOU — alguma variável foi elidida');
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. TERRITÓRIO, LISTAS E MANIFESTO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ TERR: nada de localStorage, hex cru, nem peça nova em `.js` 188_10', () => {
    const infratores = [];
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        if (/localStorage|sessionStorage/.test(fonte)) infratores.push(`${rel} storage`);
        if (/Restaurar dados de exemplo/.test(fonte)) infratores.push(`${rel} dados de exemplo`);
        // ⚠️ TOK-15 — a cor vem por nome de token, nunca por hex.
        if (/#[0-9a-fA-F]{3,8}\b/.test(fonte)) infratores.push(`${rel} hex cru`);
        // ⚠️ FID-03 / FID-04.
        if (/\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/.test(fonte)) infratores.push(`${rel} escala nomeada`);
        if (/\becf-(bg|card|card-2|yellow|yellow-2|line|dim|mute)\b/.test(fonte)) infratores.push(`${rel} token ecf-*`);
        if (/\bfont-display\b/.test(fonte)) infratores.push(`${rel} font-display`);
        if (/\bfont-(thin|extralight|light|extrabold|black)\b/.test(fonte)) infratores.push(`${rel} peso fora dos 4`);
    }
    assert.deepEqual(infratores, [], 'a seção de pendências furou o território');

    // ⚠️ ISCA: as sondas PEGAM o que devem pegar.
    assert.ok(/#[0-9a-fA-F]{3,8}\b/.test('color: #F87171'), 'a sonda de hex está furada');
    assert.ok(/\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/.test('class="text-sm"'), 'a sonda de escala nomeada está furada');
    assert.ok(/\bfont-(thin|extralight|light|extrabold|black)\b/.test('font-extrabold'), 'a sonda de peso está furada');
    // ⚠️ E ela NÃO pode pegar o que é legítimo: `font-[800]` é o precedente da casa.
    assert.equal(/\bfont-(thin|extralight|light|extrabold|black)\b/.test('font-[800]'), false, 'a sonda de peso reprova `font-[800]`, que é o precedente CERTO');

    // ⚠️ **O invariante CERTO é "nenhuma PEÇA nova sob `resources/` é `.js`"** — a
    // 187-11 mediu que *"nenhum arquivo novo é `.js`"* era literalmente FALSO (há
    // 9 `.js`, **todos testes**).
    const pecasEmJs = FONTES_DO_PLANO.filter(([rel]) => rel.startsWith('resources/') && rel.endsWith('.js'));
    assert.deepEqual(pecasEmJs, [], 'peça nova sob `resources/` em `.js` — a classe não geraria CSS e a tela sairia sem geometria');
    assert.ok(FONTES_DO_PLANO.every(([rel]) => rel.endsWith('.jsx')), 'arquivo deste plano não é `.jsx`');
});

test('⚠️ TERR: as Fases 189/192 e as waves 11/12 NÃO são antecipadas 188_10', () => {
    const infratores = [];
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        // ⚠️ Fase 189 — o lightbox. Fase 192 — o gerador.
        if (/Ampliar/.test(fonte)) infratores.push(`${rel} ⤢ Ampliar (Fase 189)`);
        if (/Gerar novamente/.test(fonte)) infratores.push(`${rel} ✦ Gerar novamente (Fase 192)`);
        // ⚠️⚠️ DECISÃO 1 do usuário — **aprovar JÁ PUBLICA**, sem segundo clique.
        // A leitura é SEM COMENTÁRIO: o docblock que os PROÍBE os CITA, e TRÊS
        // mutações da wave 187-04 abortaram por isso.
        for (const vetado of ['confirmar publicação', 'segundo passo', 'cancelar aprovação', 'Desfazer', 'desfazer']) {
            if (fonte.includes(vetado)) infratores.push(`${rel} ${vetado}`);
        }
    }
    assert.deepEqual(infratores, [], 'a seção de pendências antecipou trabalho de outra fase/wave');

    // ⚠️ ISCA: as sondas PEGAM os literais que deveriam pegar.
    assert.ok(/Ampliar/.test('⤢ Ampliar'), 'a sonda de Ampliar está furada');
    assert.ok(/Gerar novamente/.test('✦ Gerar novamente com IA'), 'a sonda de Gerar novamente está furada');
    assert.ok('confirmar publicação e desfazer'.includes('confirmar publicação'), 'a sonda dos rótulos vetados está furada');

    // ⚠️⚠️ E a leitura SEM COMENTÁRIO é o que distingue o conserto do defeito: o
    // docblock do arquivo CITA os rótulos vetados para explicar a decisão, e a
    // leitura CRUA os acharia — reprovando pelo próprio conserto.
    const cru = fs.readFileSync(path.resolve(RAIZ, REL_PENDENCIAS), 'utf8');
    assert.ok(cru.includes('onClick') && cru.includes('decorativo'), 'o docblock deixou de declarar a régua do D4 — a próxima pessoa teria de achar por bisseção');
    assert.ok(cru.includes('Fase 193'), 'o docblock deixou de declarar que aprovar ENFILEIRA e que a escrita no ML é da Fase 193');
    assert.ok(cru.includes('PERGUNTA-188-B'), 'o docblock deixou de declarar a PERGUNTA-188-B — o `null` pareceria omissão');
});

test('⚠️ TERR: os INTOCÁVEIS não foram tocados, com isca da sonda 188_10', () => {
    // ⚠️⚠️ **SÓ ENTRA AQUI O QUE NENHUMA WAVE IRMÃ DESTA FASE TOCA** — conserto
    // MEDIDO na wave 188-09: `git diff HEAD` **NÃO ATRIBUI AUTORIA** (ele mostra
    // a árvore inteira, e nesta árvore há outra sessão escrevendo), e conferir o
    // AUTOR também não serviria porque as três máquinas commitam com o MESMO
    // `user.name`.
    const INTOCAVEIS = [
        REL_GATE_ZINDEX,
        'tests/js/estrutura-meus-anuncios.test.js',
        REL_VOCABULARIO,
        `${DIR_APROV}/andamentoDoPortao.jsx`,
        `${DIR_APROV}/SeloDeAndamentoInterno.jsx`,
        `${DIR_APROV}/CascaDeModal.jsx`,
        `${DIR_APROV}/ToastDaFila.jsx`,
        `${DIR_APROV}/BarraDeMargem.jsx`,
        REL_RODAPE_DA_FILA,
        REL_COLUNA_DE_FOTOS,
        REL_KPIS,
        'resources/js/Layouts/AppLayout.jsx',
        'resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx',
        // ⚠️ GATE-07 — nenhum dos três pertence a plano nenhum.
        '.planning/ROADMAP.md',
        '.planning/STATE.md',
        '.planning/REQUIREMENTS-v27.md',
        // ⚠️ T-188-SC — esta wave não instala nada.
        'package.json',
        'package-lock.json',
    ];

    // ⚠️⚠️ OS QUE A SONDA DE DIFF **NÃO PODE** MEDIR, e por quê — declarado em
    // vez de silenciosamente omitido. A prova deles é o CONTRATO.
    const SOMENTE_POR_CONTRATO = [REL_SERVICO_PAINEL, REL_SERVICO_BLOQUEIOS, REL_ROTAS, REL_PAINEL];
    const colididos = SOMENTE_POR_CONTRATO.filter((rel) => INTOCAVEIS.includes(rel));
    assert.deepEqual(colididos, [], 'arquivo de wave irmã entrou na lista de diff — a sonda voltaria a reprovar trabalho correto de outra sessão');

    // ⚠️ E o contrato que esta seção LÊ continua de pé:
    const doPainel = fs.readFileSync(path.resolve(RAIZ, REL_SERVICO_PAINEL), 'utf8');
    for (const contrato of ['DESTINO_FOTO', 'DESTINO_MODAL', 'DESTINO_ACAO', 'MODAL_FRETE', 'MODAL_EXCECAO', 'MODAL_AJUSTES', 'ACAO_CONFERIR_ALTERACAO', 'SEM_ROTA_ATE_A_FASE_190']) {
        assert.ok(doPainel.includes(contrato), `o contrato \`${contrato}\` saiu de PainelDeRevisaoService — a tela obedeceria um destino que não existe`);
    }

    // ⚠️ ANTI-FANTASMA antes do diff: `numstat` de caminho inexistente sai VAZIO
    // e a sonda passaria CALADA.
    const fantasmas = INTOCAVEIS.filter((rel) => !fs.existsSync(path.resolve(RAIZ, rel)));
    assert.deepEqual(fantasmas, [], 'caminho intocável que não existe — o `numstat` dele sairia vazio por construção');

    const tocados = INTOCAVEIS.filter((rel) => numstatDe(rel).trim() !== '');
    assert.deepEqual(tocados, [], 'arquivo INTOCÁVEL alterado por esta wave');

    // ⚠️⚠️ ISCA DETERMINÍSTICA, e NÃO "um arquivo que esta wave alterou": contra a
    // ÁRVORE VAZIA todo arquivo rastreado produz linha, então a sonda prova que a
    // invocação funciona HOJE e continua provando DEPOIS DO COMMIT.
    const cegos = INTOCAVEIS.filter((rel) => numstatDe(rel, ARVORE_VAZIA).trim() === '');
    assert.deepEqual(cegos, [], 'a sonda de `numstat` está CEGA para estes caminhos — o pathspec não resolve');

    assert.ok(INTOCAVEIS.includes('package-lock.json'), 'o lockfile saiu da lista de intocáveis (T-188-SC)');
    assert.equal(SOMENTE_POR_CONTRATO.length, 4, 'a lista dos medidos por CONTRATO mudou de tamanho — declare por quê');
});

test('⚠️⚠️ LISTAS: os `.jsx` desta wave estão DECLARADOS nas DUAS escriturações 188_10', () => {
    // ⚠️⚠️ São DUAS escriturações para `aprovacao/`, e isso é DELIBERADO: o último
    // conserto da família RECUSOU criar uma terceira. Declarar numa e esquecer a
    // outra **REPROVA**, e é esse o comportamento certo.
    const DESTA_WAVE = FONTES_DO_PLANO.map(([rel]) => rel);

    const doVocabulario = lerSemComentarios(REL_GATE_VOCABULARIO);
    const blocoVocabulario = doVocabulario.match(/const\s+ARQUIVOS_NOVOS\s*=\s*\[([\s\S]*?)\n\];/);
    assert.ok(blocoVocabulario, `não achei o literal de ARQUIVOS_NOVOS em ${REL_GATE_VOCABULARIO} — a leitura está cega`);

    const doFechamento = lerSemComentarios(REL_GATE_FECHAMENTO);
    const blocoFechamento = doFechamento.match(/const\s+JSX_DEPOIS_DA_187\s*=\s*\[([\s\S]*?)\n\];/);
    assert.ok(blocoFechamento, `não achei o literal de JSX_DEPOIS_DA_187 em ${REL_GATE_FECHAMENTO} — a leitura está cega`);

    // ⚠️ SONDA NÃO-CEGA primeiro: bloco vazio casaria com qualquer `includes`.
    assert.ok(blocoVocabulario[1].includes('DIR_APROV'), 'o bloco de ARQUIVOS_NOVOS não cita `DIR_APROV` — a leitura está cega');
    assert.ok(blocoFechamento[1].includes('DIR_APROV'), 'o bloco de JSX_DEPOIS_DA_187 não cita `DIR_APROV` — a leitura está cega');

    const faltandoVoc = DESTA_WAVE.filter((rel) => !blocoVocabulario[1].includes(rel.replace(`${DIR_APROV}/`, '')));
    assert.deepEqual(faltandoVoc, [], `.jsx desta wave FORA de ARQUIVOS_NOVOS — o GATE-03 passaria POR OMISSÃO (VOC-07)`);

    const faltandoFech = DESTA_WAVE.filter((rel) => !blocoFechamento[1].includes(rel.replace(`${DIR_APROV}/`, '')));
    assert.deepEqual(faltandoFech, [], `.jsx desta wave FORA de JSX_DEPOIS_DA_187 — o conjunto do diretório divergiria`);

    // ⚠️ ISCA: a leitura NÃO acha um nome que não está lá.
    assert.equal(blocoVocabulario[1].includes('NaoExiste188x10.jsx'), false, 'a isca da leitura de ARQUIVOS_NOVOS virou entrada de verdade');
    assert.equal(blocoFechamento[1].includes('NaoExiste188x10.jsx'), false, 'a isca da leitura de JSX_DEPOIS_DA_187 virou entrada de verdade');

    // ⚠️ E o que esta wave NÃO mexeu, DECLARADO em vez de silenciosamente omitido:
    //   · `SLOTS_DE_NO_DEPOIS_DA_187` (três pontas) — ela não abre
    //     `isValidElement(` novo **na página**;
    //   · `ESTADO_LOCAL_DA_PAGINA` — ela não acrescenta `useState(` na página;
    //   · `QUEM_USA_SHADOW_PUB_PAINEL` — nenhum `.jsx` desta wave usa o token;
    //   · `LIBERADOS_DEPOIS_DA_187` — NENHUM tamanho novo. Há asserção abaixo
    //     cobrando isso por MEDIÇÃO dos `text-[Npx]` realmente escritos.
    const TAMANHOS_JA_NA_LISTA = new Set([9.5, 10, 10.5, 11, 11.5, 12, 12.5, 13, 13.5, 14, 15, 16, 20, 21, 22, 8.5]);
    const usados = new Set(FONTES_DO_PLANO.flatMap(([, fonte]) => [...fonte.matchAll(/text-\[(\d+(?:\.\d+)?)px\]/g)].map((m) => Number(m[1]))));
    assert.ok(usados.size > 0, 'a sonda de tamanhos está cega: nenhum `text-[Npx]` nesta wave');
    const novos = [...usados].filter((n) => !TAMANHOS_JA_NA_LISTA.has(n));
    assert.deepEqual(novos, [], 'tamanho NOVO usado sem entrar em `LIBERADOS_DEPOIS_DA_187` — declare-o MEDINDO');
    assert.equal(FONTES_DO_PLANO.some(([, f]) => /useState\(/.test(f)), false, 'a seção de pendências ganhou estado local — o estado desta tela vive na URL (COL-03)');
    assert.equal(FONTES_DO_PLANO.some(([, f]) => /shadow-pub-painel/.test(f)), false, '`shadow-pub-painel` entrou sem declarar em `QUEM_USA_SHADOW_PUB_PAINEL`');
});

test('⚠️ MANIFESTO: este arquivo está declarado em `contagens-188-10.txt`, e os nomes são ÚNICOS 188_10', () => {
    const manifesto = fs.readFileSync(path.resolve(RAIZ, REL_MANIFESTO), 'utf8');
    const linhas = manifesto.split(/\r?\n/).filter((l) => l.trim() !== '' && !l.trim().startsWith('#'));
    assert.ok(linhas.length > 0, 'o manifesto não tem nenhuma linha de id — o harness ABORTA');

    const esteArquivo = 'tests/js/publicador-painel-rodape-188.test.js';
    const aLinha = linhas.find((l) => l.includes(esteArquivo));
    assert.ok(aLinha, `${esteArquivo} não está declarado no manifesto — o gate NUNCA RODA e é indistinguível de verde`);
    assert.match(aLinha, /^pendencias-e-rodape\s+js\s/, 'o id do gate deste plano não é `pendencias-e-rodape`');
    assert.match(aLinha, /::\s*tests=\d+(,\d+)?\s*$/, 'a linha do id não traz `tests=N[,N-mutado]` — manifesto incompleto ABORTA');

    // ⚠️⚠️ `vocabulario-tamanhos` em LINHA PRÓPRIA, forma `>=`, N MEDIDO. A regra
    // é **quem roda, declara** (`188-REGUA.md` §6), e manifesto sem a linha
    // ABORTA. ⚠️ O proibido é UMA coisa só: embuti-lo num id que rode OUTROS
    // arquivos — e o id COMPOSTO foi o que fez a wave 187-05 reprovar dois ids de
    // outras waves.
    const aLinhaDoVoc = linhas.find((l) => l.startsWith('vocabulario-tamanhos'));
    assert.ok(aLinhaDoVoc, '`vocabulario-tamanhos` não está em LINHA PRÓPRIA no manifesto — o harness ABORTA');
    assert.match(aLinhaDoVoc, /::\s*tests=>=\d+\s*$/, 'a linha do GATE-03 não está na forma `>=N` — a contagem CRESCE por construção');
    assert.equal(
        (aLinhaDoVoc.match(/tests\/js\/\S+/g) ?? []).length,
        1,
        'o id `vocabulario-tamanhos` roda MAIS DE UM arquivo — é o id COMPOSTO que a 187-05 provou nocivo',
    );

    // ⚠️ Nenhum id REPETIDO: o harness pega o PRIMEIRO, e o segundo seria gate
    // decorativo por construção.
    const ids = linhas.map((l) => l.trim().split(/\s+/)[0]);
    assert.deepEqual([...new Set(ids)].sort(), [...ids].sort(), `id repetido no manifesto: ${ids.join(', ')}`);

    // ⚠️ **UM ID POR ARQUIVO DE GATE, no modo `js`**: o harness roda o ARQUIVO
    // INTEIRO, então dois ids sobre o mesmo arquivo dariam a MESMA contagem duas
    // vezes e NENHUM mediria a sua metade (medido na 187-08).
    const porArquivo = {};
    for (const l of linhas) {
        for (const arq of l.match(/tests\/js\/\S+/g) ?? []) {
            porArquivo[arq] = (porArquivo[arq] ?? 0) + 1;
        }
    }
    const duplicados = Object.entries(porArquivo).filter(([, n]) => n > 1).map(([a]) => a);
    assert.deepEqual(duplicados, [], 'dois ids sobre o MESMO arquivo de gate — gate decorativo por construção');

    // ⚠️ **Nenhum id `php` neste manifesto**, e isso é DECLARADO: o `--filter` do
    // PHPUnit (regex NÃO ANCORADO) só existe no modo `php`, então a colisão de
    // homônimo **não tem onde acontecer** aqui. O que é VERDADE no lugar disso:
    // todo nome de teste deste arquivo termina em `188_10`.
    const comPhp = linhas.filter((l) => /\s+php\s+/.test(l));
    assert.deepEqual(comPhp, [], 'entrou id `php` neste manifesto — a sonda de homônimo do `--filter` tem de passar a existir');

    const fonteDoGate = fs.readFileSync(path.resolve(RAIZ, esteArquivo), 'utf8');
    const nomes = [...fonteDoGate.matchAll(/^test\('([^']*)'/gm)].map((m) => m[1]);
    assert.ok(nomes.length > 0, 'a leitura dos nomes de teste está cega');
    const semSufixo = nomes.filter((n) => !n.endsWith('188_10'));
    assert.deepEqual(semSufixo, [], 'nome de teste sem o sufixo `188_10` — nome genérico COLIDE no `--filter`, que SOMA');
    assert.deepEqual([...new Set(nomes)].sort(), [...nomes].sort(), 'nome de teste repetido neste arquivo');

    // ⚠️ E o expansor de ALTERNÂNCIA: `/test_[A-Za-z0-9_]+/` **NÃO casa**
    // `test_(a|b|c)`, que é a forma de TODOS os manifestos desta fase — o furo
    // MEDIDO pela wave 188-05, que deu `SEM_ID=14` acusando trabalho CORRETO.
    const expandir = (filtro) => {
        const m = filtro.match(/^(.*?)\(([^)]*)\)(.*)$/);
        return m === null ? [filtro] : m[2].split('|').map((meio) => `${m[1]}${meio}${m[3]}`);
    };
    assert.deepEqual(expandir('test_(a|b)_188_10'), ['test_a_188_10', 'test_b_188_10'], 'o expansor de alternância está furado — ele acusaria trabalho CORRETO');
    assert.deepEqual(expandir('test_simples_188_10'), ['test_simples_188_10'], 'o expansor quebrou a forma sem alternância');
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. O RODAPÉ DE 66px — ⚠️⚠️ CRITÉRIOS 8, 10 E A METADE TELA DO 16
// ═══════════════════════════════════════════════════════════════════════════

/** Sem bloqueio e `live`: aprovável. ⚠️ O motivo é o LITERAL do vocabulário. */
const R_SEM_BLOQUEIO = {
    aprovavel: true,
    motivo: M_VOCABULARIO.TUDO_CONFERIDO,
    motivo_cor: 'verde',
    botoes: { salvar: true, ajustes: true, aprovar: true, proximo: true },
};

/**
 * Com bloqueio e `live`: **NÃO** aprovável.
 *
 * ⚠️⚠️ O motivo é o do PRODUTO (`resumoDoRodape()`): o PRIMEIRO abreviado + ` +N`
 * dos DEMAIS. ⚠️ **NÃO** é o `join(' · ')` de TODAS as abreviações de `:1297`.
 */
const R_COM_BLOQUEIO = {
    aprovavel: false,
    motivo: `${M_VOCABULARIO.BLOQUEADO_PREFIXO}${M_VOCABULARIO.ABREVIACAO_DO_BLOQUEIO.frete_ausente} +2`,
    motivo_cor: 'vermelho',
    botoes: { salvar: true, ajustes: true, aprovar: true, proximo: true },
};

/**
 * Fora do `live`: um dos 5 textos de `:1297`, e a cor é a do ANDAMENTO.
 *
 * ⚠️ `botoes.salvar/ajustes/aprovar` saem `false` e `proximo` sai `true` —
 * `:721` está FORA dos dois `<sc-if value="{{ P.isLive }}">` (`:713` e **`:718`**).
 */
const R_FORA_DO_LIVE = {
    aprovavel: false,
    motivo: 'Aprovado na versão 4 por Marina · aguardando publicação',
    motivo_cor: 'andamento',
    botoes: { salvar: false, ajustes: false, aprovar: false, proximo: true },
};

/** Os props completos do rodapé, com empresa/oferta/versão de VERDADE. */
function propsDoRodape(extra) {
    return {
        rodape: R_SEM_BLOQUEIO,
        andamento: 'aguardando_aprovacao',
        proximaId: 7702,
        onIr: nada,
        onSolicitarAjustes: nada,
        empresaId: 459,
        ofertaId: 7701,
        versao: 4,
        ...(extra ?? {}),
    };
}

test('⚠️⚠️ CRITÉRIO 8: o `disabled=` é ATRIBUTO no botão de APROVAR, por índice ABSOLUTO 188_10', () => {
    // ── A direção "bloqueado": o atributo ESTÁ lá. ───────────────────────────
    const bloqueado = render(RodapeDoPainel, propsDoRodape({ rodape: R_COM_BLOQUEIO }));
    const aprovarBloqueado = botaoDeAprovarEm(bloqueado);
    assert.ok(aprovarBloqueado, 'o botão "Aprovar para publicação" não foi desenhado com a oferta `live`');
    assert.equal(aprovarBloqueado.desabilitado, true, 'com bloqueio de pé o botão de APROVAR saiu SEM o atributo `disabled`');
    assert.ok(/\bcursor-not-allowed\b/.test(aprovarBloqueado.classe), 'o botão bloqueado não tem `cursor-not-allowed` (`:1307`)');

    // ── A direção "liberado": o atributo NÃO pode estar lá. ──────────────────
    const liberado = render(RodapeDoPainel, propsDoRodape({ rodape: R_SEM_BLOQUEIO }));
    const aprovarLiberado = botaoDeAprovarEm(liberado);
    assert.ok(aprovarLiberado, 'o botão de aprovar não foi desenhado com zero bloqueios e `live`');
    assert.equal(aprovarLiberado.desabilitado, false, 'com zero bloqueios e `live` o botão saiu `disabled` — ninguém conseguiria aprovar');
    assert.ok(/\bcursor-pointer\b/.test(aprovarLiberado.classe), 'o botão habilitado não tem `cursor-pointer` (`:1307`)');

    // ⚠️⚠️ ISCA (a) — um botão com a CLASSE `disabled:opacity-40` e **SEM** o
    // atributo **não** pode ser lido como desabilitado. É literalmente a
    // armadilha que o critério 8 traz escrita.
    const comClasse = '<button type="button" class="h-[38px] disabled:opacity-40">Aprovar para publicação</button>';
    assert.equal(botaoDeAprovarEm(comClasse).desabilitado, false, '⚠️ a sonda leu a CLASSE `disabled:opacity-40` como se fosse o atributo — é `/disabled=/`, nunca `/disabled/`');
    // ⚠️ E a prova de que a armadilha é REAL: `/disabled/` casaria.
    assert.ok(/disabled/.test(comClasse), 'a premissa caiu: `/disabled/` deixou de casar a classe — remeça a sonda');
    assert.equal(/\bdisabled=/.test(comClasse), false, 'a premissa caiu: `/disabled=/` passou a casar a classe');

    // ⚠️⚠️ ISCA (b) — o atributo no botão de aprovar É lido. Sem esta direção a
    // sonda poderia estar simplesmente cega.
    const comAtributo = '<button type="button" class="h-[38px]" disabled="">Aprovar para publicação</button>';
    assert.equal(botaoDeAprovarEm(comAtributo).desabilitado, true, 'a sonda está cega: nem o atributo de verdade ela acha');

    // ⚠️⚠️ ISCA (c) — **A QUE PROVA A ANCORAGEM.** Um `disabled=""` em OUTRO
    // botão do rodapé **NÃO** pode satisfazer o gate. A redação anterior lia a
    // regex sobre o HTML do rodapé INTEIRO, e este caso a satisfaria com o botão
    // de aprovar HABILITADO e bloqueio de pé.
    const noIrmao = '<div class="flex">'
        + '<button type="button" class="h-[36px]" disabled="">Salvar revisão</button>'
        + '<button type="button" class="h-[36px]">Solicitar ajustes</button>'
        + '<span class="ml-auto">Bloqueado: frete pendente +2</span>'
        + '<button type="button" class="h-[38px]">Aprovar para publicação</button>'
        + '<button type="button" class="h-[38px]">Próximo ↓</button>'
        + '</div>';
    assert.ok(/\bdisabled=/.test(noIrmao), 'a premissa caiu: o HTML da isca (c) não tem `disabled=` em lugar nenhum');
    assert.equal(
        botaoDeAprovarEm(noIrmao).desabilitado,
        false,
        '⚠️⚠️ a sonda aceitou um `disabled=""` em OUTRO botão — ela NÃO está ancorada no botão de aprovar',
    );
    // ⚠️ E o índice ABSOLUTO dele é 2, não 0: `els[0]` mediria "Salvar revisão",
    // que é EXATAMENTE o botão que carrega o `disabled=""` na isca (c).
    assert.equal(botaoDeAprovarEm(noIrmao).indice, 2, 'o botão de aprovar não está no índice que a árvore diz — `els[0]` mediria o irmão errado');
    assert.equal(botoesDe(noIrmao)[0].rotulo, 'Salvar revisão', 'a premissa do irmão errado caiu');
    assert.equal(botoesDe(noIrmao)[0].desabilitado, true, 'a isca (c) perdeu o `disabled=""` no IRMÃO — ela não provaria a ancoragem');

    // ⚠️ E no rodapé de VERDADE ele também não é o primeiro.
    assert.equal(aprovarBloqueado.indice, 2, `o botão de aprovar mudou de posição na árvore: ${aprovarBloqueado.indice}`);
    assert.equal(aprovarBloqueado.total, 4, `o rodapé não tem 4 botões: ${botoesDe(bloqueado).map((b) => b.rotulo).join(' | ')}`);
    assert.equal(botoesDe(bloqueado)[0].rotulo, 'Salvar revisão', '`els[0]` do rodapé real deixou de ser "Salvar revisão" — remeça o índice');
});

test('⚠️⚠️ T-188-90: `aprovavel` NUNCA é calculado no cliente, e só `true` libera 188_10', () => {
    // ⚠️⚠️ A trava REAL é `AprovacaoDaOfertaService::aprovar()` (plano 188-05,
    // provado chamando a rota DIRETO). O `disabled=` é conforto.
    //
    // ⚠️⚠️ **CONSERTO MEDIDO NESTA EXECUÇÃO — a 1ª redação desta sonda era MAIS
    // LARGA QUE O INVARIANTE e REPROVOU CÓDIGO CERTO**, nas DUAS pontas:
    //   · `/\.length === 0/` sobre TODOS os arquivos do plano pegava o
    //     `reais.length === 0` de `PendenciasDoPainel`, que é a decisão *"nenhuma
    //     pendência"* — nada a ver com aprovabilidade;
    //   · `/aprovavel\s*=\s*[^=]/` pegava o **default de parâmetro**
    //     (`aprovavel = false,` na desestruturação), que é legítimo.
    // ➡️ **ANCORADA NO COMPORTAMENTO, e recortada ao arquivo que DESENHA o
    // botão** (a 11ª ocorrência da família "gate mais largo que o invariante"
    // nesta milestone, e a 3ª que reprovou trabalho correto).
    const infratores = [];

    // (1) ⚠️ O arquivo que desenha o botão **não pode nem VER** a lista de
    // bloqueios: sem a lista, não há do que derivar.
    for (const palavra of ['pendencias', 'bloqueios', 'blocks', '.filter(', '.length']) {
        if (FONTE_RODAPE.includes(palavra)) infratores.push(`${REL_RODAPE} lê \`${palavra}\``);
    }

    // (2) ⚠️⚠️ **LISTA CONSTANTE (ALLOW-LIST) das DUAS formas aceitas** — e ela é
    // constante, não "piso", porque piso NÃO prova presença (learnings §4b.2,
    // regra 5). As duas são de LEITURA; qualquer outro lado direito (`live &&
    // zero`, `b.length === 0`, …) é DERIVAÇÃO e reprova.
    //
    // ⚠️ **As duas que a 1ª e a 2ª redação desta sonda acusaram por engano, e são
    // legítimas:** o DEFAULT DE PARÂMETRO da desestruturação e a LEITURA por
    // atributo JSX. A 2ª redação ainda falhava porque a desestruturação é numa
    // LINHA SÓ (`{ aprovavel = false, onAprovar = null }`) e o atributo JSX casa
    // o mesmo `=`.
    const FORMAS_DE_LEITURA = [
        'aprovavel = false',            // o default do parâmetro de `BotaoDeAprovar`
        'aprovavel={dados.aprovavel}',  // a leitura do payload, por atributo JSX
    ];
    const ehLeitura = (trecho) => FORMAS_DE_LEITURA.some((ok) => trecho.replace(/\s+/g, ' ').startsWith(ok));
    const derivacoes = [...FONTE_RODAPE.matchAll(/\baprovavel\s*=(?!=)[\s\S]{0,24}/g)]
        .map((m) => m[0])
        .filter((trecho) => !ehLeitura(trecho));
    for (const valor of derivacoes) infratores.push(`${REL_RODAPE} deriva aprovavel de \`${valor.replace(/\s+/g, ' ')}\``);

    // (3) ⚠️ E a seção de pendências nem nomeia `aprovavel`: ela não decide nada
    // sobre publicar.
    if (FONTE_PENDENCIAS.includes('aprovavel')) infratores.push(`${REL_PENDENCIAS} nomeia aprovavel`);

    assert.deepEqual(infratores, [], 'a tela calcula `aprovavel` — duas verdades, e a divergente é descoberta por alguém CLICANDO e sendo recusado');

    // ⚠️ ISCA NAS DUAS DIREÇÕES, cada uma testada contra a sonda DE VERDADE.
    const derivacoesEm = (texto_) => [...texto_.matchAll(/\baprovavel\s*=(?!=)[\s\S]{0,24}/g)]
        .map((m) => m[0])
        .filter((trecho) => !ehLeitura(trecho));

    // (a) A sonda PEGA as TRÊS formas de derivação que ela existe para pegar.
    for (const cebo of [
        'const aprovavel = live && bloqueios.length === 0;',
        'const aprovavel = pendencias.length === 0;',
        'aprovavel = inf.live && zero',
    ]) {
        assert.equal(derivacoesEm(cebo).length, 1, `a sonda da derivação está furada — ela não pega \`${cebo}\``);
    }

    // (b) ⚠️ E ela **NÃO** pega as formas LEGÍTIMAS — sem esta direção a sonda
    // reprovaria o próprio conserto, e foi isso que ela fez DUAS vezes nesta
    // execução antes de ser ancorada.
    for (const legitimo of [
        'export function BotaoDeAprovar({ aprovavel = false, onAprovar = null }) {',
        '            <BotaoDeAprovar aprovavel={dados.aprovavel} onAprovar={aprovar} />',
        'const liberado = aprovavel === true;',
        'assert.equal(el.aprovavel, true);',
    ]) {
        assert.deepEqual(derivacoesEm(legitimo), [], `a sonda reprova a forma LEGÍTIMA \`${legitimo.trim()}\``);
    }

    // (c) E a sonda (1) PEGA quem lê a lista, e NÃO pega quem não lê.
    assert.ok('const zero = bloqueios.length === 0;'.includes('bloqueios'), 'a sonda da lista está furada');
    assert.equal('const liberado = dados.aprovavel === true;'.includes('bloqueios'), false, 'a sonda da lista pega a leitura legítima');

    // ⚠️ E a tela LÊ `rodape.aprovavel` do servidor.
    assert.match(FONTE_RODAPE, /aprovavel=\{dados\.aprovavel\}/, 'o rodapé deixou de LER `rodape.aprovavel` do servidor');
    const doServico = fs.readFileSync(path.resolve(RAIZ, REL_SERVICO_PAINEL), 'utf8');
    assert.ok(doServico.includes("'aprovavel' =>"), '`aprovavel` saiu do payload do servidor — a tela leria o nada');
    const dosBloqueios = fs.readFileSync(path.resolve(RAIZ, REL_SERVICO_BLOQUEIOS), 'utf8');
    assert.ok(dosBloqueios.includes('aprovavel'), '`aprovavel` saiu de BloqueiosDaOfertaService — a trava real sumiu');

    // ⚠️⚠️ Só `true` LIBERA. `'true'`, `1`, `{}` e `[]` são verdadeiros em JS e
    // **não podem** acender um botão que PUBLICA sem desfazer.
    for (const quase of ['true', 1, {}, [], 'sim', -1, 'false']) {
        const el = botaoDeAprovarEm(render(BotaoDeAprovar, { aprovavel: quase, onAprovar: nada }));
        assert.ok(el, `o botão não foi desenhado com \`aprovavel\` ${typeof quase}`);
        assert.equal(el.desabilitado, true, `\`aprovavel\` aceitou um valor que não é \`true\` (${typeof quase}) e o botão ACENDEU`);
    }
    assert.equal(botaoDeAprovarEm(render(BotaoDeAprovar, { aprovavel: true, onAprovar: nada })).desabilitado, false, 'a isca falhou: nem com `true` o botão acende');

    // ⚠️ D4 — SEM AÇÃO, SEM BOTÃO: `disabled` sem caminho nenhum é decorativo, e
    // essa forma foi OFERECIDA e RECUSADA (PERGUNTA-188-B3).
    for (const semAcao of [null, undefined, {}, 'x', 7]) {
        assert.equal(render(BotaoDeAprovar, { aprovavel: false, onAprovar: semAcao }), '', `o botão nasceu com handler ${typeof semAcao} — \`disabled\` sem caminho é decorativo`);
    }
});

test('⚠️⚠️ CRITÉRIO 10: o literal verde é LIDO do módulo, e o motivo vem do SERVIDOR 188_10', () => {
    // ── Sem bloqueio: o literal VERDE. ──────────────────────────────────────
    const verde = render(RodapeDoPainel, propsDoRodape({ rodape: R_SEM_BLOQUEIO }));
    assert.ok(texto(verde).includes(M_VOCABULARIO.TUDO_CONFERIDO), 'o literal de "tudo conferido" não apareceu no rodapé');
    assert.equal(M_VOCABULARIO.TUDO_CONFERIDO, 'Fotos, preço e conteúdo conferidos', 'o literal de `TUDO_CONFERIDO` mudou — remeça os dois lados');

    const spanVerde = tagsDeAbertura(verde).find((e) => e.tag === 'span');
    assert.ok(classesDe(spanVerde).has('text-pub-ok-txt'), 'o motivo sem bloqueio não saiu VERDE');

    // ── Com bloqueio: o PRIMEIRO abreviado + ` +N`, em VERMELHO. ─────────────
    const vermelho = render(RodapeDoPainel, propsDoRodape({ rodape: R_COM_BLOQUEIO }));
    assert.ok(texto(vermelho).includes(R_COM_BLOQUEIO.motivo), 'o motivo com bloqueio não apareceu');
    const spanVermelho = tagsDeAbertura(vermelho).find((e) => e.tag === 'span');
    assert.ok(classesDe(spanVermelho).has('text-pub-danger-txt'), 'o motivo com bloqueio não saiu VERMELHO');

    // ⚠️⚠️ **A DIVERGÊNCIA DECLARADA CONTRA `:1297`.** O protótipo junta TODAS as
    // abreviações com `' · '`; o produto usa `resumoDoRodape()` (o PRIMEIRO +
    // ` +N`). ➡️ A tela **EXIBE** `rodape.motivo` e não monta frase nenhuma:
    // recopiar o `join` criaria a SEGUNDA VERDADE da mesma frase na MESMA tela.
    const infratores = [];
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        if (/join\(' · '\)|join\("\s·\s"\)/.test(fonte)) infratores.push(`${rel} join do motivo`);
        // ⚠️ Os literais do motivo não podem ser escritos à mão na tela.
        if (fonte.includes(M_VOCABULARIO.BLOQUEADO_PREFIXO)) infratores.push(`${rel} prefixo "Bloqueado: "`);
        if (fonte.includes(M_VOCABULARIO.TUDO_CONFERIDO)) infratores.push(`${rel} literal de tudo conferido`);
        if (/ \+' \+|'\s*\+\s*N/.test(fonte)) infratores.push(`${rel} monta o " +N"`);
    }
    assert.deepEqual(infratores, [], 'a tela MONTA o motivo em vez de exibir o do servidor — é a 2ª verdade da mesma frase');

    // ⚠️ ISCA: as sondas PEGAM o que devem pegar.
    assert.ok(/join\(' · '\)/.test("inf.B.map(b => SHORT[b.k]).join(' · ')"), 'a sonda do `join` está furada');
    assert.ok('Bloqueado: frete pendente'.includes(M_VOCABULARIO.BLOQUEADO_PREFIXO), 'a sonda do prefixo está furada');

    // ⚠️⚠️ E as DUAS formas existem NO PRÓPRIO PROTÓTIPO: `:1238` (a FILA) já é a
    // forma do produto (`SHORT[b0.k] + ' +N'`) e `:1297` (o painel) é o `join`.
    // ➡️ A divergência é INTERNA ao protótipo, e o produto escolheu a que o
    // critério 10 manda. Isto fica asserido no bloco do protótipo, abaixo.
    const doServidor = fs.readFileSync(path.resolve(RAIZ, REL_SERVICO_BLOQUEIOS), 'utf8');
    assert.ok(doServidor.includes('resumoDoRodape'), '`resumoDoRodape()` saiu de BloqueiosDaOfertaService — o motivo perderia o dono');
    assert.ok(doServidor.includes('TUDO_CONFERIDO'), 'o servidor deixou de usar `TUDO_CONFERIDO` — duas cópias divergiriam');

    // ── Fora do `live`: um dos 5 textos, com a COR DO ANDAMENTO. ─────────────
    const foraDoLive = render(RodapeDoPainel, propsDoRodape({ rodape: R_FORA_DO_LIVE, andamento: 'aprovado' }));
    assert.ok(texto(foraDoLive).includes(R_FORA_DO_LIVE.motivo), 'o motivo fora do `live` não apareceu');
    const spanAndamento = tagsDeAbertura(foraDoLive).find((e) => e.tag === 'span');
    assert.ok(
        classesDe(spanAndamento).has(M_ANDAMENTO.andamentoDe('aprovado').texto),
        'a cor do motivo fora do `live` não é a do ANDAMENTO (`:1308`) — recopiar as 8 cores seria a 2ª verdade',
    );
    // ⚠️ E ela MUDA com o andamento: uma cor fixa passaria no caso de cima.
    const comFalha = render(RodapeDoPainel, propsDoRodape({ rodape: { ...R_FORA_DO_LIVE, motivo: 'Falha na publicação · versão aprovada 4 preservada' }, andamento: 'falha_na_publicacao' }));
    const spanFalha = tagsDeAbertura(comFalha).find((e) => e.tag === 'span');
    assert.notDeepEqual(
        [...classesDe(spanAndamento)].sort(),
        [...classesDe(spanFalha)].sort(),
        'a cor do motivo NÃO muda com o andamento — ela está cravada',
    );
    // ⚠️ E `andamentoDe()` NUNCA devolve `null`: chave adversa não apaga a cor.
    for (const adverso of ADVERSOS) {
        const h = render(MotivoDoRodape, { motivo: 'x', cor: 'andamento', andamento: adverso });
        assert.notEqual(h, '', `o motivo desapareceu com andamento ${typeof adverso}`);
        assert.ok(/class="[^"]*text-pub-/.test(h), `o motivo saiu SEM cor com andamento ${typeof adverso}`);
    }

    // ⚠️ Sem motivo, nenhum `<span>` vazio: um retângulo sem texto é invisível.
    for (const vazio of [null, undefined, '', '   ', {}, [], 7, NaN]) {
        assert.equal(render(MotivoDoRodape, { motivo: vazio, cor: 'verde' }), '', `o motivo ${typeof vazio} desenhou um span vazio`);
    }
});

test('⚠️⚠️ `:713`/`:718`: os TRÊS botões só com `live`; "Próximo ↓" NÃO depende do `live` 188_10', () => {
    // ── COM `live`: os quatro. ───────────────────────────────────────────────
    const vivo = botoesDe(render(RodapeDoPainel, propsDoRodape({ rodape: R_SEM_BLOQUEIO })));
    assert.deepEqual(
        vivo.map((b) => b.rotulo),
        ['Salvar revisão', 'Solicitar ajustes', 'Aprovar para publicação', 'Próximo ↓'],
        'a ORDEM ou o conjunto dos botões do rodapé divergiu de `:714`/`:715`/`:719`/`:721`',
    );

    // ── FORA do `live`: SÓ o "Próximo ↓". ────────────────────────────────────
    const parado = botoesDe(render(RodapeDoPainel, propsDoRodape({ rodape: R_FORA_DO_LIVE, andamento: 'aprovado' })));
    assert.deepEqual(parado.map((b) => b.rotulo), ['Próximo ↓'], 'fora do `live` o rodapé não ficou só com "Próximo ↓"');

    // ⚠️⚠️ E um gate que SÓ testasse o caso `live` passaria com um rodapé que
    // nunca esconde nada — é por isso que os DOIS lados estão aqui.
    assert.notDeepEqual(vivo.map((b) => b.rotulo), parado.map((b) => b.rotulo), 'os dois estados do rodapé saíram iguais — o `isLive` não governa nada');

    // ⚠️ `botoes.X` **É** o `$aoVivo` do servidor, e só `true` vale.
    for (const quase of ['true', 1, {}, [], 'sim']) {
        const h = botoesDe(render(RodapeDoPainel, propsDoRodape({
            rodape: { ...R_SEM_BLOQUEIO, botoes: { salvar: quase, ajustes: quase, aprovar: quase, proximo: true } },
        })));
        assert.deepEqual(h.map((b) => b.rotulo), ['Próximo ↓'], `\`botoes\` aceitou um valor que não é \`true\` (${typeof quase})`);
    }

    // ⚠️⚠️ "Existe SEMPRE" quer dizer "não depende do `live`" — e **não** "é
    // desenhado sem destino". Sem `proxima_id` ou sem `onIr` ele some, pela MESMA
    // régua do `SetaDoPainel` da 188-07: botão sem destino é D4 violado.
    for (const semDestino of [null, undefined, 0, -1, '7702', 1.5, {}, NaN]) {
        const h = botoesDe(render(RodapeDoPainel, propsDoRodape({ rodape: R_FORA_DO_LIVE, proximaId: semDestino })));
        assert.equal(h.length, 0, `"Próximo ↓" foi desenhado sem destino válido (${typeof semDestino})`);
    }
    assert.equal(botoesDe(render(RodapeDoPainel, propsDoRodape({ rodape: R_FORA_DO_LIVE, onIr: null }))).length, 0, '"Próximo ↓" foi desenhado sem handler');

    // ⚠️ B2 — "Solicitar ajustes" só com `live` **E** com handler: o modal é da
    // wave **188-11**. ⚠️ **Nada de `onClick` vazio, nada de `disabled`
    // decorativo** (D4).
    const semAjuste = botoesDe(render(RodapeDoPainel, propsDoRodape({ onSolicitarAjustes: null })));
    assert.equal(semAjuste.some((b) => b.rotulo === 'Solicitar ajustes'), false, '"Solicitar ajustes" foi desenhado sem handler — botão sem destino');
    assert.equal(semAjuste.some((b) => b.rotulo === 'Salvar revisão'), true, 'a isca falhou: "Salvar revisão" também desapareceu');
    for (const naoFuncao of ['x', 7, {}, [], true]) {
        const h = botoesDe(render(RodapeDoPainel, propsDoRodape({ onSolicitarAjustes: naoFuncao })));
        assert.equal(h.some((b) => b.rotulo === 'Solicitar ajustes'), false, `"Solicitar ajustes" aceitou um handler que não é função (${typeof naoFuncao})`);
    }

    // ⚠️ E nenhum dos botões do rodapé nasce `disabled` por decoração — só o de
    // aprovar carrega o atributo, e ele carrega INFORMAÇÃO (o motivo ao lado).
    const comBloqueio = botoesDe(render(RodapeDoPainel, propsDoRodape({ rodape: R_COM_BLOQUEIO })));
    assert.deepEqual(
        comBloqueio.filter((b) => b.desabilitado).map((b) => b.rotulo),
        ['Aprovar para publicação'],
        'outro botão do rodapé nasceu `disabled` — `disabled` decorativo é D4 violado',
    );
});

test('⚠️⚠️ DECISÃO 1: APROVAR JÁ PUBLICA — os quatro rótulos proibidos, com isca 188_10', () => {
    // ⚠️⚠️ O rótulo é **"Aprovar para publicação"**, INALTERADO. E **nada de
    // "confirmar publicação", "desfazer", "segundo passo" nem "cancelar
    // aprovação"**: as quatro foram OFERECIDAS e RECUSADAS, e o gate vale das
    // Fases 187 a 194.
    const VETADOS = [
        'confirmar publicação', 'Confirmar publicação', 'confirmar a publicação',
        'desfazer', 'Desfazer',
        'segundo passo', 'Segundo passo',
        'cancelar aprovação', 'Cancelar aprovação',
    ];
    const infratores = [];
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        for (const vetado of VETADOS) {
            if (fonte.includes(vetado)) infratores.push(`${rel} ${vetado}`);
        }
    }
    assert.deepEqual(infratores, [], 'rótulo de segundo passo/desfazer na tela — aprovar JÁ PUBLICA (decisão 1)');

    // ⚠️⚠️ **E A LEITURA É SEM COMENTÁRIO, que é o que distingue o conserto do
    // defeito:** o docblock deste arquivo CITA os quatro para explicar a decisão,
    // e a leitura CRUA os acharia — reprovando pelo PRÓPRIO CONSERTO. Foram TRÊS
    // mutações abortadas por isso na wave 187-04.
    const cru = fs.readFileSync(path.resolve(RAIZ, REL_RODAPE), 'utf8');
    const citadosNoDocblock = VETADOS.filter((v) => cru.includes(v));
    assert.ok(citadosNoDocblock.length > 0, '⚠️ o docblock deixou de CITAR os rótulos vetados — a razão da ausência virou invisível, e a próxima pessoa a reintroduziria');
    assert.ok(cru.includes('Fase 193'), 'o docblock deixou de declarar que aprovar ENFILEIRA e que a escrita no ML é da Fase 193');
    assert.ok(cru.includes('não há desfazer'), 'o docblock deixou de declarar que não há desfazer');

    // ⚠️ ISCA: a sonda PEGA o que ela deve pegar.
    assert.ok('clique em confirmar publicação'.includes('confirmar publicação'), 'a sonda dos vetados está furada');

    // ⚠️ E o rótulo CERTO está na tela, com o byte certo (o `ç` e o `ã`).
    assert.equal(M_RODAPE.ROTULO_APROVAR, 'Aprovar para publicação', 'o rótulo de aprovar mudou — a decisão 1 o quer INALTERADO');
    assert.equal(M_RODAPE.ROTULO_SALVAR, 'Salvar revisão', 'o rótulo de salvar mudou');
    assert.equal(M_RODAPE.ROTULO_AJUSTES, 'Solicitar ajustes', 'o rótulo de ajustes mudou');
    assert.equal(M_RODAPE.ROTULO_PROXIMO, 'Próximo ↓', 'o rótulo de "Próximo ↓" mudou — a seta é U+2193 e faz parte dele');
});

test('⚠️⚠️ CRITÉRIO 16/17: as DUAS escritas do rodapé passam pelo helper COM `versao_esperada` 188_10', () => {
    CHAMADAS.length = 0;

    const escritas = escritasDoRodape({ empresaId: 459, ofertaId: 7701, versao: 4 });
    assert.equal(typeof escritas.salvar, 'function', '"Salvar revisão" não nasceu com empresa, oferta e versão');
    assert.equal(typeof escritas.aprovar, 'function', '"Aprovar" não nasceu com empresa, oferta e versão');

    assert.equal(escritas.salvar(), true, 'o helper recusou a escrita de "Salvar revisão"');
    assert.equal(escritas.aprovar(), true, 'o helper recusou a escrita de "Aprovar"');
    assert.equal(CHAMADAS.length, 2, `as duas escritas não saíram exatamente uma vez cada: ${CHAMADAS.length}`);

    const [salvar, aprovar] = CHAMADAS;
    assert.equal(salvar[0], 'post', '"Salvar revisão" saiu por outro verbo');
    assert.ok(salvar[1].includes('salvar-revisao'), `"Salvar revisão" não chamou a rota do plano 188-05: ${salvar[1]}`);
    assert.ok(aprovar[1].includes('aprovacao.painel.aprovar'), `"Aprovar" não chamou a rota de aprovação: ${aprovar[1]}`);

    // ⚠️⚠️ `versao_esperada` SEMPRE no corpo, nas DUAS. OMITI-LA faz
    // `exigirVersao()` receber `null` e **LIBERAR** a escrita — e numa tela em que
    // aprovar JÁ PUBLICA e não há desfazer isso é o pior resultado possível.
    assert.equal(salvar[2].versao_esperada, 4, '"Salvar revisão" saiu SEM `versao_esperada`');
    assert.equal(aprovar[2].versao_esperada, 4, '"Aprovar" saiu SEM `versao_esperada` — é sobrescrever em silêncio');

    // ⚠️ Sem os três dados **não há handler** — e sem handler o botão não é
    // desenhado. ⚠️ `0` NÃO vale: é id plausível.
    for (const faltando of [
        { empresaId: null, ofertaId: 7701, versao: 4 },
        { empresaId: 459, ofertaId: null, versao: 4 },
        { empresaId: 459, ofertaId: 7701, versao: null },
        { empresaId: 0, ofertaId: 7701, versao: 4 },
        { empresaId: 459, ofertaId: 0, versao: 4 },
        { empresaId: 459, ofertaId: 7701, versao: 0 },
        { empresaId: 459, ofertaId: 7701, versao: '4' },
    ]) {
        const r = escritasDoRodape(faltando);
        assert.equal(r.salvar, null, `"Salvar revisão" nasceu com dado inválido: ${Object.keys(faltando).join(',')}`);
        assert.equal(r.aprovar, null, '"Aprovar" nasceu com dado inválido');
    }
    for (const adverso of ADVERSOS) {
        assert.doesNotThrow(() => escritasDoRodape(adverso), `\`escritasDoRodape\` LANÇOU com ${typeof adverso}`);
        assert.equal(escritasDoRodape(adverso).aprovar, null, `"Aprovar" nasceu com pedido ${typeof adverso}`);
    }
    assert.equal(CHAMADAS.length, 2, 'uma das recusas disparou escrita');

    // ⚠️ E SEM os ids o botão de aprovar **não é desenhado** (D4): `disabled` sem
    // caminho nenhum é decoração.
    const semIds = botoesDe(render(RodapeDoPainel, propsDoRodape({ empresaId: null })));
    assert.equal(semIds.some((b) => b.rotulo === 'Aprovar para publicação'), false, 'o botão de aprovar nasceu sem os ids — não haveria caminho nenhum');
    assert.equal(semIds.some((b) => b.rotulo === 'Salvar revisão'), false, '"Salvar revisão" nasceu sem os ids');

    // ⚠️⚠️ **ZERO `localStorage`** — o critério 16 é explícito: a revisão parcial
    // fica no SERVIDOR, para outra pessoa continuar de onde a primeira parou.
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        assert.doesNotMatch(fonte, /localStorage|ecf-aprovacao-v1/, `${rel} guarda revisão no cliente — o critério 16 a quer no SERVIDOR`);
    }

    // ⚠️ E o CLIQUE de verdade dispara a escrita: o `disabled` do DOM não é
    // exercido por `renderToStaticMarkup`, então a ação é chamada DIRETO.
    CHAMADAS.length = 0;
    const doBotao = escritasDoRodape({ empresaId: 459, ofertaId: 7701, versao: 9 });
    assert.doesNotThrow(() => doBotao.aprovar(), 'o CLIQUE de aprovar LANÇOU');
    assert.equal(CHAMADAS[0][2].versao_esperada, 9, 'a versão do clique não é a que a tela viu');
    CHAMADAS.length = 0;
});

test('⚠️ FID: a GEOMETRIA de `:712`-`:721`, por IGUALDADE e com isca do quase-igual 188_10', () => {
    const html = render(RodapeDoPainel, propsDoRodape({ rodape: R_COM_BLOQUEIO }));
    const arvore = tagsDeAbertura(html);

    // ⚠️ ÍNDICE ABSOLUTO: 0 o `<div>` do rodapé.
    assert.equal(arvore[0].tag, 'div', 'a raiz do rodapé não é um `<div>` (`:712`)');
    assert.deepEqual(
        [...classesDe(arvore[0])].sort(),
        ['bg-pub-surface', 'border-pub-line-subtle', 'border-t', 'flex', 'flex-none', 'gap-[10px]', 'h-[66px]', 'items-center', 'px-[22px]'],
        'as classes do rodapé de `:712` divergiram',
    );

    // ⚠️ `h-[66px]` e **não** `min-h-[66px]`: o protótipo mede `height:66px`. É a
    // armadilha do `min-h-[64px]` da 187-08, pela outra ponta.
    assert.ok(classesDe(arvore[0]).has('h-[66px]'), 'o rodapé perdeu o `height:66px`');
    assert.equal(classesDe(arvore[0]).has('min-h-[66px]'), false, 'o rodapé ganhou `min-h-[66px]` — o protótipo mede ALTURA FIXA');
    assert.ok(classesDe(arvore[0]).has('flex-none'), 'o rodapé perdeu o `flex:none` — ele encolheria junto com o corpo');

    const porRotulo = (rotulo) => {
        const todos = botoesDe(html);
        const i = todos.findIndex((b) => b.rotulo === rotulo);
        assert.ok(i >= 0, `o botão "${rotulo}" não está no rodapé`);

        return new Set(todos[i].classe.split(/\s+/).filter((c) => c !== ''));
    };

    const SECUNDARIO = ['bg-pub-hover-2', 'border', 'border-pub-line-strong', 'cursor-pointer', 'font-medium', 'h-[36px]', 'hover:border-pub-line-off', 'px-[14px]', 'rounded-pub-control', 'text-[13px]', 'text-pub-txt', 'whitespace-nowrap'];
    assert.deepEqual([...porRotulo('Salvar revisão')].sort(), SECUNDARIO, 'as classes de `:714` divergiram');
    // ⚠️ `:715` é "idem E9" na §E — MEDIDO idêntico por `sed` nesta wave.
    assert.deepEqual([...porRotulo('Solicitar ajustes')].sort(), SECUNDARIO, 'as classes de `:715` divergiram de `:714` — a §E diz "idem"');

    assert.deepEqual(
        [...porRotulo('Aprovar para publicação')].sort(),
        ['bg-pub-line-subtle', 'border', 'border-pub-line-strong', 'cursor-not-allowed', 'font-semibold', 'h-[38px]', 'px-[18px]', 'rounded-pub-control', 'text-[13.5px]', 'text-pub-txt-4', 'whitespace-nowrap'],
        'as classes do par NÃO APROVÁVEL de `:719`/`:1307` divergiram',
    );
    const liberado = render(RodapeDoPainel, propsDoRodape({ rodape: R_SEM_BLOQUEIO }));
    const doLiberado = botoesDe(liberado).find((b) => b.rotulo === 'Aprovar para publicação');
    assert.deepEqual(
        [...new Set(doLiberado.classe.split(/\s+/).filter((c) => c !== ''))].sort(),
        ['bg-pub-accent', 'border', 'border-pub-accent', 'cursor-pointer', 'font-semibold', 'h-[38px]', 'px-[18px]', 'rounded-pub-control', 'text-[13.5px]', 'text-pub-page', 'whitespace-nowrap'],
        'as classes do par APROVÁVEL de `:719`/`:1307` divergiram',
    );
    // ⚠️⚠️ OS QUATRO PARES SÃO DISTINTOS AOS PARES: duas cores iguais apagariam a
    // diferença entre "pode publicar" e "não pode".
    assert.notDeepEqual(
        [...porRotulo('Aprovar para publicação')].sort(),
        [...new Set(doLiberado.classe.split(/\s+/))].sort(),
        'os dois estados do botão de aprovar saíram com as MESMAS classes',
    );
    // ⚠️ E `:719` **não tem `style-hover`**: inventar um seria acrescentar o que o
    // desenho não pediu (FID-02 pelo avesso).
    assert.equal([...porRotulo('Aprovar para publicação')].some((c) => c.startsWith('hover:')), false, 'o botão de aprovar ganhou `hover:` — `:719` NÃO tem `style-hover`');

    assert.deepEqual(
        [...porRotulo('Próximo ↓')].sort(),
        ['bg-transparent', 'border', 'border-pub-line-strong', 'cursor-pointer', 'h-[38px]', 'hover:bg-pub-hover-2', 'px-[14px]', 'rounded-pub-control', 'text-[13px]', 'text-pub-fase1', 'whitespace-nowrap'],
        'as classes de `:721` divergiram',
    );

    // ⚠️⚠️ O MOTIVO (**`:717`**) — e `max-w-[420px]` é do PAINEL. O da FILA é
    // `max-w-[560px]` (`:539`), e são medidas DIFERENTES de propósito:
    // *"deixei igual à outra tela"* é FID-02 literal.
    const motivo = tagsDeAbertura(html).find((e) => e.tag === 'span');
    const doMotivo = classesDe(motivo);
    assert.deepEqual(
        [...doMotivo].sort(),
        ['font-medium', 'leading-[1.35]', 'max-w-[420px]', 'ml-auto', 'text-[12.5px]', 'text-pub-danger-txt', 'text-right'],
        'as classes do motivo de `:717` divergiram',
    );
    assert.equal(doMotivo.has('max-w-[560px]'), false, '⚠️ o motivo ganhou o `max-w-[560px]` da FILA — são medidas diferentes DE PROPÓSITO');
    assert.ok(doMotivo.has('ml-auto'), 'o motivo perdeu o `margin-left:auto` — os quatro botões ficariam colados à esquerda');
    // ⚠️ E a prova de que a fila mede OUTRO valor: um gate que exigisse o mesmo
    // nos dois reprovaria código certo.
    const daFila = fs.readFileSync(path.resolve(RAIZ, REL_RODAPE_DA_FILA), 'utf8');
    assert.equal(daFila.includes('max-w-[420px]'), false, 'o rodapé da FILA passou a medir 420px — remeça os dois');
});

test('⚠️⚠️ REND: nenhum campo adverso do rodapé derruba a página, nem no CLIQUE 188_10', () => {
    const SUJEIRA = /\[object Object\]|NaN|undefined|Invalid Date/;
    const CAMPOS = ['aprovavel', 'motivo', 'motivo_cor', 'botoes'];

    for (const campo of CAMPOS) {
        for (const adverso of ADVERSOS) {
            let html = '';
            assert.doesNotThrow(
                () => { html = render(RodapeDoPainel, propsDoRodape({ rodape: { ...R_COM_BLOQUEIO, [campo]: adverso } })); },
                `o rodapé LANÇOU com \`${campo}\` do tipo ${typeof adverso}`,
            );
            assert.doesNotMatch(html, SUJEIRA, `o rodapé vazou sujeira com \`${campo}\` do tipo ${typeof adverso}`);
        }
    }

    // ⚠️ Os quatro sub-campos de `botoes`, cada um adverso.
    for (const chave of ['salvar', 'ajustes', 'aprovar', 'proximo']) {
        for (const adverso of ADVERSOS) {
            let html = '';
            assert.doesNotThrow(
                () => { html = render(RodapeDoPainel, propsDoRodape({ rodape: { ...R_SEM_BLOQUEIO, botoes: { ...R_SEM_BLOQUEIO.botoes, [chave]: adverso } } })); },
                `o rodapé LANÇOU com \`botoes.${chave}\` do tipo ${typeof adverso}`,
            );
            assert.doesNotMatch(html, SUJEIRA, `o rodapé vazou sujeira com \`botoes.${chave}\` do tipo ${typeof adverso}`);
        }
    }

    // ⚠️ A PROP INTEIRA adversa, e cada handler/id adverso.
    for (const adverso of ADVERSOS) {
        assert.doesNotThrow(() => render(RodapeDoPainel, { rodape: adverso }), `o rodapé LANÇOU com \`rodape\` ${typeof adverso}`);
        assert.doesNotThrow(
            () => render(RodapeDoPainel, propsDoRodape({ onIr: adverso, onSolicitarAjustes: adverso, empresaId: adverso, ofertaId: adverso, versao: adverso, onConflito: adverso, andamento: adverso, proximaId: adverso })),
            `o rodapé LANÇOU com handlers/ids do tipo ${typeof adverso}`,
        );
    }
    assert.doesNotThrow(() => render(RodapeDoPainel, {}), 'o rodapé LANÇOU sem prop nenhuma');
    assert.equal(render(RodapeDoPainel, {}), '', 'o rodapé desenhou casca de 66px sem dado — D4 violado');

    // ⚠️⚠️ **E NO CLIQUE.** `renderToStaticMarkup` **não dispara `onClick`**, e a
    // última tela preta deste projeto foi NO CLIQUE.
    CHAMADAS.length = 0;
    const acoes = escritasDoRodape({ empresaId: 459, ofertaId: 7701, versao: 4 });
    assert.doesNotThrow(() => acoes.salvar(), 'o CLIQUE de "Salvar revisão" LANÇOU');
    assert.doesNotThrow(() => acoes.aprovar(), 'o CLIQUE de "Aprovar" LANÇOU');
    for (const adverso of ADVERSOS) {
        assert.doesNotThrow(() => render(BotaoDoRodape, { rotulo: adverso, classes: adverso, onClicar: adverso }), `\`BotaoDoRodape\` LANÇOU com ${typeof adverso}`);
        assert.doesNotThrow(() => render(BotaoDeAprovar, { aprovavel: adverso, onAprovar: adverso }), `\`BotaoDeAprovar\` LANÇOU com ${typeof adverso}`);
        assert.doesNotThrow(() => render(MotivoDoRodape, { motivo: adverso, cor: adverso, andamento: adverso }), `\`MotivoDoRodape\` LANÇOU com ${typeof adverso}`);
    }
    CHAMADAS.length = 0;

    // ⚠️ E `String(`/`JSON.stringify(` não aparecem: os dois LANÇAM com
    // `{toString: null}`, e há gate da 187-04 que já proíbe `String(` na página.
    assert.doesNotMatch(FONTE_RODAPE, /\bString\(/, '`String(` no rodapé — ele LANÇA com `{toString: null}`');
    assert.doesNotMatch(FONTE_RODAPE, /JSON\.stringify\(/, '`JSON.stringify(` no rodapé');
});

test('⚠️⚠️ O RODAPÉ É IRMÃO DO CORPO ROLÁVEL, não filho dele 188_10', () => {
    // ⚠️⚠️ **MEDIDO por script no protótipo** (ver o bloco do protótipo abaixo):
    // `:562` (o corpo) abre na profundidade 2→3 e **`:710` o FECHA**; `:712` (o
    // rodapé) abre em 2→3 de novo, dentro do `<aside>`. ➡️ Dentro do corpo
    // (`flex-1 overflow-y-auto`) o rodapé **ROLARIA PARA FORA DA TELA**, e
    // `flex:none` não o salvaria — ele seria item de um contêiner que rola.
    // ⚠️ Com todo teste de render passando.
    const fontePainel = lerSemComentarios(REL_PAINEL);

    // ⚠️ O slot do rodapé está FORA do `<CorpoDoPainel>`, e o das pendências
    // DENTRO — provado pela POSIÇÃO no texto da fonte, entre marcas únicas.
    const abreCorpo = fontePainel.indexOf('<CorpoDoPainel>');
    const fechaCorpo = fontePainel.indexOf('</CorpoDoPainel>');
    const noPendencias = fontePainel.indexOf('noPendencias,');
    const noRodape = fontePainel.indexOf('{noRodape}');
    assert.ok(abreCorpo > 0 && fechaCorpo > abreCorpo, 'não achei o `<CorpoDoPainel>` — a leitura está cega');
    assert.ok(noPendencias > abreCorpo && noPendencias < fechaCorpo, 'as pendências saíram de DENTRO do corpo rolável — `:699` abre dentro de `:562`');
    assert.ok(noRodape > fechaCorpo, '⚠️⚠️ o rodapé entrou DENTRO do corpo rolável — ele ROLARIA PARA FORA DA TELA');
    // ⚠️ E ele vem DEPOIS do `{corpo}` dentro do `<aside>`, como em `:712` depois
    // de `:710`.
    assert.ok(fontePainel.indexOf('{corpo}') < noRodape, 'o rodapé subiu para cima do corpo — ele apareceria no topo do painel');
    assert.ok(fontePainel.indexOf('</aside>') > noRodape, 'o rodapé saiu de dentro do `<aside>`');

    // ⚠️ Os DOIS slots são de NÓ e passam por `filhosSeguros`: **prop de NÓ
    // chegando objeto comum faz o React LANÇAR** e mata a página inteira.
    assert.match(fontePainel, /const noPendencias = filhosSeguros\(pendencias\)/, 'o slot `pendencias` deixou de passar por `filhosSeguros`');
    assert.match(fontePainel, /const noRodape = filhosSeguros\(rodape\)/, 'o slot `rodape` deixou de passar por `filhosSeguros`');

    // ⚠️⚠️ E A PÁGINA LIGA OS DOIS. Sem o fio, as duas peças existiriam no disco e
    // **não apareceriam na tela** — e os critérios 8, 9 e 10 falam do que a tela
    // MOSTRA. ⚠️ Nenhuma wave desta fase (11, 12 ou 13) tem a página no `files`,
    // então não há dono depois desta.
    // ⚠️ Os nomes carregam o sufixo `DoPainel` porque a página JÁ TEM um
    // `noRodape` — o da TABELA DA FILA (`RodapeDaFila`, Fase 187). ⚠️ O esbuild
    // REPROVOU com *"The symbol 'noRodape' has already been declared"*, e o
    // conserto é o sufixo, não renomear o da fila (que está NO AR).
    assert.match(FONTE_PAGINA, /pendencias=\{noPendenciasDoPainel\}/, 'a página não liga o slot `pendencias` — a seção não apareceria na tela');
    assert.match(FONTE_PAGINA, /rodape=\{noRodapeDoPainel\}/, 'a página não liga o slot `rodape` — o rodapé não apareceria na tela');
    assert.match(FONTE_PAGINA, /const noRodape = isValidElement\(rodape\)/, 'o `noRodape` da TABELA DA FILA (Fase 187) foi renomeado — ele está NO AR');
    assert.match(FONTE_PAGINA, /<PendenciasDoPainel/, 'a página não monta `PendenciasDoPainel`');
    assert.match(FONTE_PAGINA, /<RodapeDoPainel/, 'a página não monta `RodapeDoPainel`');

    // ⚠️ E os handlers das waves 11/12 ficam `null`, DECLARADOS: sem eles o botão
    // não é desenhado (D4), e **nada de `onClick` vazio**.
    assert.match(FONTE_PAGINA, /onAbrirModal=\{null\}/, 'a página passou a mandar um `onAbrirModal` — se a wave 188-11/12 fechou, religue pelo helper');
    assert.match(FONTE_PAGINA, /onSolicitarAjustes=\{null\}/, 'a página passou a mandar um `onSolicitarAjustes` — se a wave 188-11 fechou, religue');

    // ⚠️ A página traduz o `imagem_id` do destino para o ÍNDICE da galeria, e ela
    // lê `imagens[*].id` (a linha de REVISÃO), **não** `imagens[*].imagem_id` (o
    // `pub_imagens.id`): são tabelas diferentes, e confundi-las abriria a foto
    // errada.
    assert.match(FONTE_PAGINA, /onFocarFoto=\{focarFoto\}/, 'a página deixou de ligar `onFocarFoto`');
    assert.match(FONTE_PAGINA, /imagens\.findIndex/, 'a página deixou de traduzir o `imagem_id` para o índice da galeria');
    assert.doesNotMatch(FONTE_PAGINA, /objetoSeguro\(imagem\)\?\.imagem_id/, 'a página traduz pelo `pub_imagens.id` — ela abriria a foto errada');
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. O PROTÓTIPO — ⚠️ `skip` EXPLÍCITO e NOMEADO se o pacote não está no disco
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ FID: os literais e os ponteiros de `:699`-`:721` medidos contra o protótipo 188_10', async (contexto) => {
    const abs = path.resolve(RAIZ, PROTOTIPO);
    if (!fs.existsSync(abs)) {
        // ⚠️ `skip` com razão ESCRITA (e `skipped N` no sumário), nunca `return`
        // calado: `design_handoff_publicador/` NÃO é versionado.
        contexto.skip(`${PROTOTIPO} ausente — o pacote de handoff não é versionado`);

        return;
    }

    // ⚠️ `split(/\r?\n/)` dá 1433 e não 1432 (a última linha vazia) — MEDIDO
    // nesta fase, e a régua avisa.
    const linhas = fs.readFileSync(abs, 'utf8').split(/\r?\n/);
    const em = (n) => linhas[n - 1] ?? '';

    await contexto.test('o protótipo não se moveu (1432 linhas úteis)', () => {
        assert.ok(linhas.length === 1432 || linhas.length === 1433, `o protótipo mudou de tamanho: ${linhas.length}`);
        assert.match(em(700), /Pendências/, '`:700` deixou de ser o título — os ponteiros se moveram');
    });

    await contexto.test('⚠️⚠️ os PONTEIROS do §E, reconferidos por LEITURA DA LINHA', () => {
        assert.match(em(699), /background:#111111;border:1px solid #262626;border-radius:14px;padding:14px/, '`:699` não é a `<section>` de Pendências');
        assert.match(em(700), /font-size:15px;font-weight:600/, '`:700` não é o título de 15px');
        assert.match(em(702), /min-height:34px/, '`:702` não é o item com `min-height:34px`');
        assert.match(em(703), /width:18px;height:18px;border-radius:50%/, '`:703` não é o glifo de 18px');
        // ⚠️⚠️ OMISSÃO MEDIDA NESTA WAVE: a §E **não cita** `display:grid;place-items:center`.
        assert.match(em(703), /display:grid;place-items:center/, '`:703` perdeu o `grid place-items-center` — é ele que CENTRA o `!`');
        assert.match(em(703), /font-weight:800/, '`:703` perdeu o peso 800');
        assert.match(em(704), /flex:1;font-size:13px;color:#E4E4E7/, '`:704` não é o texto da pendência');
        assert.match(em(705), /height:28px;padding:0 10px;border:1px solid #2E2E2E;border-radius:7px/, '`:705` não é o botão de correção');
        assert.match(em(705), /style-hover="border-color:#3F3F46"/, '`:705` perdeu o `style-hover`');
        assert.match(em(708), /color:#4ADE80/, '`:708` não é a mensagem verde');
        // ⚠️⚠️ A SEGUNDA OMISSÃO MEDIDA: o glifo `✓` também tem `place-items`.
        assert.match(em(708), /display:grid;place-items:center/, '`:708` perdeu o `grid place-items-center` do glifo ✓');
        assert.ok(em(708).includes('Nenhuma pendência · fotos aprovadas, composição financeira completa e margem dentro da regra'), '`:708` perdeu o literal da mensagem verde');

        // ⚠️⚠️ OS PONTEIROS DO VIEW-MODEL, da região que NENHUMA passada de
        // HTML/CSS re-mediu (`188-FID01.md` §J) — e eles estavam DESLOCADOS na
        // prosa. Reconferidos por leitura da linha:
        //   · `hasFix` e as CORES: a prosa dizia `:1304`; é **`:1295`**;
        //   · o `reason` (e o `join`): a prosa dizia `:1307`; é **`:1297`**;
        //   · os 4 pares de cor do aprovar: a §E dizia `:1143`; é **`:1307`**.
        assert.match(em(1295), /hasFix: !!b\.fix && inf\.live/, '`:1295` não é o `hasFix` — a prosa dizia `:1304`, que é o `shared`/`safeCount`');
        assert.match(em(1295), /b\.k === 'photos' && inf\.alerts\.length/, '`:1295` perdeu o ramo de `photos` COM alerta');
        assert.match(em(1297), /const reason = inf\.live/, '`:1297` não é o `reason` — a prosa dizia `:1307`');
        assert.match(em(1297), /join\(' · '\)/, '`:1297` perdeu o `join` que o produto NÃO reproduz');
        assert.match(em(1307), /approveBg: inf\.approvable \? '#FACC15' : '#1F1F1F'/, '`:1307` não traz os 4 pares de cor — a §E dizia `:1143`, que é o `freightMissing`');
        assert.match(em(1143), /freightMissing: f\.missing/, '`:1143` deixou de ser o `freightMissing` — a §L/§O mediram isso');
        assert.match(em(1099), /approvable: live && B\.length === 0/, '`:1099` não é o `approvable`');
        assert.match(em(1089), /k: 'prep'.*fix: ''/, '`:1089` não é o `prep` com `fix` vazio');
        assert.match(em(1092), /else if|if \(f\.missing\)/, '`:1092` não é o começo da cadeia exclusiva');
        assert.match(em(1171), /fix\(o, k\) \{/, '`:1171` não é a ABERTURA do `fix()` — a expressão mora em `:1172`');
        assert.match(em(1172), /inf\.alerts\[0\] \|\| inf\.pend\[0\]/, '`:1172` não traz `alerts[0] || pend[0]`');
    });

    await contexto.test('⚠️ `cursor` / `white-space` / `text-overflow`, coluna por coluna', () => {
        // ⚠️ MEDIDO POR SCRIPT nesta wave, nas 23 linhas de `:699`-`:721`.
        // `white-space:nowrap` foi omitido SETE vezes na Fase 187, TRÊS delas como
        // PRIMEIRA propriedade da linha — e aqui ele é a primeira de CINCO.
        const COM_CURSOR = [705, 714, 715, 721];
        const SEM_CURSOR = [699, 700, 702, 703, 704, 708, 712, 717];
        const COM_NOWRAP = [705, 714, 715, 719, 721];
        const SEM_NOWRAP = [699, 700, 702, 703, 704, 708, 712, 717];

        for (const n of COM_CURSOR) assert.ok(em(n).includes('cursor:pointer'), `\`:${n}\` perdeu o \`cursor:pointer\``);
        for (const n of SEM_CURSOR) assert.ok(!em(n).includes('cursor:pointer'), `\`:${n}\` ganhou \`cursor:pointer\` — remeça`);
        for (const n of COM_NOWRAP) assert.ok(em(n).includes('white-space:nowrap'), `\`:${n}\` perdeu o \`white-space:nowrap\``);
        for (const n of SEM_NOWRAP) assert.ok(!em(n).includes('white-space:nowrap'), `\`:${n}\` ganhou \`white-space:nowrap\` — remeça`);

        // ⚠️⚠️ `:719` tem `cursor:{{ P.approveCursor }}`, que é VARIÁVEL — ele não
        // casa `cursor:pointer` literal, e por isso está FORA das duas listas de
        // `cursor`. Afirmá-lo numa delas seria medir o irmão errado.
        assert.match(em(719), /cursor:\{\{ P\.approveCursor \}\}/, '`:719` perdeu o `cursor` variável — ele é um dos 4 pares de E12');
        assert.equal(em(719).includes('cursor:pointer'), false, '`:719` ganhou `cursor:pointer` FIXO — o cursor dele é variável');

        // ⚠️ NENHUMA das 23 tem `text-overflow` — e isso é afirmado com a lista
        // INTEIRA, não com uma amostra.
        for (const n of [699, 700, 702, 703, 704, 705, 708, 712, 714, 715, 717, 719, 721]) {
            assert.ok(!em(n).includes('text-overflow'), `\`:${n}\` ganhou \`text-overflow\` — remeça`);
        }

        // ⚠️ ISCA NAS DUAS DIREÇÕES: a varredura ACHA o que existe e NÃO acha o
        // que não existe. Sem ela, "0 divergências" é indistinguível de sonda
        // morta (`188-FID01.md`, as iscas das três passadas).
        assert.ok(em(705).includes('cursor:'), 'a isca falhou: `:705` tem `cursor:` e a sonda não achou');
        assert.equal(em(699).includes('cursor:'), false, 'a isca falhou: `:699` NÃO tem `cursor:` e a sonda achou');
    });

    await contexto.test('⚠️⚠️ os PONTEIROS do RODAPÉ (`:712`-`:721`) e as duas medidas do motivo', () => {
        assert.match(em(712), /height:66px;padding:0 22px;border-top:1px solid #1F1F1F;background:#111111;flex:none/, '`:712` não é o rodapé de 66px');
        assert.match(em(714), /height:36px;padding:0 14px/, '`:714` não é "Salvar revisão"');
        assert.ok(em(714).includes('Salvar revisão'), '`:714` perdeu o rótulo "Salvar revisão"');
        assert.ok(em(715).includes('Solicitar ajustes'), '`:715` perdeu o rótulo "Solicitar ajustes"');
        // ⚠️ A §E diz "idem E9" para `:715` — MEDIDO: os dois `style` são iguais.
        const estilo = (n) => (em(n).match(/ style="([^"]*)"/) || [])[1] ?? null;
        assert.equal(estilo(715), estilo(714), '`:715` divergiu de `:714` — a §E diz "idem E9"');

        // ⚠️⚠️ **E11 ESTÁ EM `:717`, NÃO EM `:718`** (divergência R8 da §I.1).
        assert.match(em(717), /margin-left:auto;font-size:12\.5px;font-weight:500/, '`:717` não é o motivo');
        assert.match(em(717), /max-width:420px;line-height:1\.35/, '`:717` perdeu o `max-width:420px` do PAINEL');
        assert.match(em(718), /<sc-if value="\{\{ P\.isLive \}\}">/, '`:718` deixou de ser o `<sc-if>` do botão Aprovar — era ele que a tabela confundia com E11');
        assert.match(em(716), /<\/sc-if>/, '`:716` deixou de ser o `</sc-if>` de FECHAMENTO — era ele que a prosa confundia com o 2º `isLive`');
        assert.match(em(713), /<sc-if value="\{\{ P\.isLive \}\}">/, '`:713` não é o PRIMEIRO `<sc-if>` do rodapé');

        // ⚠️ E o `max-width` da FILA é OUTRO: `:539` mede 560px, e um gate que
        // exigisse o mesmo nos dois reprovaria código certo.
        assert.match(em(539), /max-width:560px/, '`:539` (o motivo da FILA) deixou de medir 560px — remeça os dois');

        assert.match(em(719), /height:38px;padding:0 18px/, '`:719` não é "Aprovar para publicação"');
        assert.match(em(719), /disabled="\{\{ P\.notApprovable \}\}"/, '`:719` perdeu o `disabled=` — ele É o critério 8');
        assert.ok(em(719).includes('Aprovar para publicação'), '`:719` perdeu o rótulo "Aprovar para publicação"');
        assert.equal(em(719).includes('style-hover'), false, '`:719` ganhou `style-hover` — o protótipo NÃO dá hover a este botão');
        assert.match(em(721), /background:transparent;color:#D4D4D8/, '`:721` não é "Próximo ↓"');
        assert.ok(em(721).includes('Próximo ↓'), '`:721` perdeu o rótulo "Próximo ↓"');
        assert.match(em(721), /style-hover="background:#161616"/, '`:721` perdeu o `style-hover` de FUNDO');

        // ⚠️⚠️ **AS DUAS FORMAS DO MOTIVO EXISTEM NO PRÓPRIO PROTÓTIPO**, e é por
        // isso que a divergência de E11 não é decisão de executor: `:1238` (a
        // FILA) já é a forma do PRODUTO (o PRIMEIRO + ` +N`) e `:1297` (o painel)
        // é o `join` de TODAS. O produto escolheu a que o critério 10 manda.
        assert.match(em(1238), /this\.SHORT\[b0\.k\] \+ \(inf\.B\.length > 1 \? ' \+' \+ \(inf\.B\.length - 1\)/, '`:1238` deixou de trazer a forma do PRODUTO — a divergência interna do protótipo era a prova');
        assert.match(em(1297), /inf\.B\.map\(b => this\.SHORT\[b\.k\]\)\.join\(' · '\)/, '`:1297` deixou de trazer o `join` — remeça a divergência');
        assert.match(em(974), /SHORT = \{ photos:/, '`:974` não é o mapa `SHORT` das 9 abreviações');
    });

    await contexto.test('⚠️⚠️ O RODAPÉ É IRMÃO DO CORPO — profundidade MEDIDA por script', () => {
        // ⚠️⚠️ A medição que justifica o slot `rodape` SEPARADO do `children`:
        // conta tags de abertura menos as de fechamento, linha por linha, de
        // `:547` até `:724`.
        const TAGS = /<(div|aside|section|span|button|sc-if|sc-for)\b/g;
        const FECHA = /<\/(div|aside|section|span|button|sc-if|sc-for)>/g;
        const profundidade = {};
        let d = 0;
        for (let n = 547; n <= 724; n += 1) {
            const linha = em(n);
            const antes = d;
            d += (linha.match(TAGS) ?? []).length - (linha.match(FECHA) ?? []).length;
            profundidade[n] = { antes, depois: d };
        }

        // `:562` (o corpo) e `:712` (o rodapé) abrem na MESMA profundidade: são
        // IRMÃOS, filhos do `<aside>` de `:550`.
        assert.equal(profundidade[562].antes, 2, '`:562` (o corpo) não abre na profundidade 2 — a medição se moveu');
        assert.equal(profundidade[712].antes, 2, '⚠️⚠️ `:712` (o rodapé) não abre na MESMA profundidade do corpo — ele deixou de ser IRMÃO dele');
        assert.equal(profundidade[710].depois, 2, '`:710` não fecha o corpo — o rodapé estaria DENTRO dele');

        // ⚠️ E `:699` (as Pendências) abre na profundidade 3: DENTRO do corpo,
        // irmão do wrapper das colunas (`:586`, fechado por `:697`).
        assert.equal(profundidade[699].antes, 3, '`:699` (as Pendências) não abre DENTRO do corpo');
        assert.equal(profundidade[697].depois, 3, '`:697` não fecha o wrapper das colunas');

        // ⚠️ ISCA: a sonda distingue as duas profundidades. Se tudo saísse igual,
        // ela não mediria nada.
        assert.notEqual(profundidade[699].antes, profundidade[712].antes, 'a sonda de profundidade NÃO distingue o corpo do rodapé — ela está cega');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. O CSS CONSTRUÍDO — ⚠️ com as OITO normalizações e as iscas MEDIDAS
// ═══════════════════════════════════════════════════════════════════════════

/** Escapa TODOS os metacaracteres de regex. */
function escapaRegex(texto_) {
    return texto_.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * O seletor que o Tailwind emite. ⚠️ A 8ª normalização: o escape é **GERAL**
 * (tudo que não é `[A-Za-z0-9_-]`), senão classe com `(`/`,`/`[`/`/` sai AUSENTE
 * estando presente.
 */
function seletorDe(classe) {
    return `.${[...classe].map((ch) => (/[A-Za-z0-9_-]/.test(ch) ? ch : `\\${ch}`)).join('')}`;
}

/**
 * ⚠️ É esta guarda de `mtime` que produz os DOIS REGIMES de contagem do
 * manifesto: mutar uma fonte a deixa mais nova que o CSS e a camada cai em
 * `skip`. ⚠️ E `cp` ATUALIZA o `mtime`, então `npm run build` vem DEPOIS da
 * mutação (`cp -p` preserva, e aí o regime pristino volta sem build).
 */
const FONTES_DO_CSS = [REL_PENDENCIAS, REL_RODAPE];

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

test('⚠️ FID: as classes das pendências GERAM CSS com o valor certo 188_10', async (contexto) => {
    if (CSS.motivo) {
        contexto.skip(CSS.motivo);

        return;
    }

    const regraDe = (classe) => {
        const re = new RegExp(`${escapaRegex(seletorDe(classe))}(?![\\w\\\\-])[^{}]*\\{([^}]*)\\}`);
        const m = CSS.css.match(re);

        return m ? m[1] : null;
    };

    await contexto.test('o CSS lido é o do manifest, e a PÁGINA está no manifest', () => {
        assert.ok(CSS.css.length > 100000, `CSS suspeito de truncado: ${CSS.css.length} bytes`);
        const tem = (k) => Object.prototype.hasOwnProperty.call(CSS.manifest, k);
        assert.ok(tem('resources/js/Pages/Mlb/Publicador/Aprovacao.jsx'), 'a página da fila saiu do manifest — a tela desaparece sem erro nenhum');
        assert.equal(tem('resources/js/Pages/Mlb/NaoExiste8k3.jsx'), false, 'a isca do manifest virou entrada de verdade');
    });

    await contexto.test('⚠️ ISCA: a sonda reporta AUSENTE o que não existe, e ACHA o que existe', () => {
        // ⚠️⚠️ Isca tem de ser classe inexistente no projeto INTEIRO. A wave 188-08
        // mediu que `grid-cols-6` EXISTE (outros donos) e que `left-[37.5%]` foi
        // gerada pelo PRÓPRIO COMENTÁRIO de um docblock — **o scanner do Tailwind
        // lê comentário**. Estas quatro foram medidas AUSENTES contra o CSS de
        // verdade, nesta wave.
        for (const isca of ['bg-pub-isca-188-10', 'text-[94.5px]', 'w-[1979px]', 'top-[61.5%]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS.css.includes(isca), `a string da isca ${isca} aparece no CSS`);
        }
        assert.notEqual(regraDe('text-[13px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('⚠️⚠️ `duration-[Nms]` continua NÃO COMPILANDO, e `duration-200` compila', () => {
        assert.equal(regraDe('duration-[180ms]'), null, '`duration-[180ms]` passou a compilar — remeça a regra');
        assert.match(regraDe('duration-200'), /transition-duration:\s*\.?2s/, 'o CONTROLE da escala nomeada sumiu');
    });

    await contexto.test('⚠️⚠️ a GEOMETRIA das pendências, com as OITO normalizações', () => {
        // ⚠️ 1ª/2ª — token SÓLIDO não compila para hex: sai `rgb(R G B / var(…))`.
        assert.match(regraDe('bg-pub-surface'), /rgb\(17 17 17/, '`bg-pub-surface` não é #111111 (`:699`)');
        assert.match(regraDe('border-pub-line'), /rgb\(38 38 38/, '`border-pub-line` não é #262626 (`:699`)');
        assert.match(regraDe('text-pub-fase1-2'), /rgb\(228 228 231/, '`text-pub-fase1-2` não é #E4E4E7 (`:704`)');
        assert.match(regraDe('bg-pub-hover-2'), /rgb\(22 22 22/, '`bg-pub-hover-2` não é #161616 (`:705`)');
        assert.match(regraDe('border-pub-line-strong'), /rgb\(46 46 46/, '`border-pub-line-strong` não é #2E2E2E (`:705`)');
        assert.match(regraDe('text-pub-txt'), /rgb\(244 244 245/, '`text-pub-txt` não é #F4F4F5 (`:705`)');
        assert.match(regraDe('text-pub-ok-txt'), /rgb\(74 222 128/, '`text-pub-ok-txt` não é #4ADE80 (`:708`)');
        assert.match(regraDe('bg-pub-ok'), /rgb\(34 197 94/, '`bg-pub-ok` não é #22C55E (o glifo ✓ de `:708`)');
        assert.match(regraDe('text-pub-page'), /rgb\(10 10 10/, '`text-pub-page` não é #0A0A0A (o ✓ sobre verde)');
        assert.match(regraDe('text-pub-danger-txt'), /rgb\(248 113 113/, '`text-pub-danger-txt` não é #F87171 (`:1295`)');
        assert.match(regraDe('text-pub-warn-txt'), /rgb\(251 191 36/, '`text-pub-warn-txt` não é #FBBF24 (`:1295`)');

        // ⚠️ 3ª — o modificador de opacidade muda de FORMA conforme a
        // PROPRIEDADE: em `background` sai **hex de 8 dígitos**, e o byte do alfa
        // PROVA a opacidade. `0.14` → `0x24`.
        assert.match(regraDe('bg-pub-danger/[0.14]'), /#ef444424/, 'o fundo do glifo vermelho não é `rgba(239,68,68,0.14)` (`:1295`)');
        assert.match(regraDe('bg-pub-warn/[0.14]'), /#f59e0b24/, 'o fundo do glifo âmbar não é `rgba(245,158,11,0.14)` (`:1295`)');

        // ⚠️⚠️ `min-h-[34px]` e `h-[18px]` são DOIS seletores diferentes, e o
        // escape GERAL é o que faz os colchetes resolverem.
        assert.match(regraDe('min-h-[34px]'), /min-height:\s*34px/, 'o `min-height:34px` de `:702` não compilou');
        assert.match(regraDe('h-[18px]'), /height:\s*18px/, 'o `height:18px` de `:703` não compilou');
        assert.match(regraDe('h-[28px]'), /height:\s*28px/, 'o `height:28px` de `:705` não compilou');

        // ⚠️ `font-[800]` é o valor ARBITRÁRIO que entrega o peso do protótipo sem
        // furar o GATE-03 — e aqui está a prova de que ele COMPILA.
        assert.match(regraDe('font-[800]'), /font-weight:\s*800/, '`font-[800]` não compilou — os glifos sairiam no peso errado');

        // ⚠️ `grid` + `place-items-center` são as DUAS omissões medidas da §E.
        assert.match(regraDe('grid'), /display:\s*grid/, '`grid` não compilou — o `!` sairia no canto');
        assert.match(regraDe('place-items-center'), /place-items:\s*center/, '`place-items-center` não compilou — o `!` sairia no canto');

        // ⚠️ E o `hover:` é VARIANTE: na variante o prefixo faz parte do nome
        // (6ª normalização).
        assert.match(regraDe('hover:border-pub-line-off'), /rgb\(63 63 70/, 'o `style-hover` de `:705` não compilou');

        // O resto das medidas, numa asserção COLETANDO os infratores.
        const semRegra = [
            'rounded-pub-table', 'rounded-pub-control-sm', 'rounded-full',
            'flex', 'flex-col', 'flex-1', 'flex-none', 'items-center', 'border',
            'cursor-pointer', 'whitespace-nowrap', 'font-semibold',
            'w-[18px]', 'p-[14px]', 'px-[10px]', 'gap-[8px]', 'gap-[10px]',
            'text-[10.5px]', 'text-[13px]', 'text-[15px]', 'text-[12px]',
        ].filter((c) => regraDe(c) === null);
        assert.deepEqual(semRegra, [], 'classe das pendências NÃO gerou CSS — num `.js` isso aconteceria com todo teste de render passando');
    });

    await contexto.test('⚠️⚠️ a GEOMETRIA do RODAPÉ e os QUATRO pares de cor de `:1307`', () => {
        // ⚠️ 1ª/2ª — token SÓLIDO sai em `rgb(R G B / var(…))`, não em hex.
        assert.match(regraDe('border-pub-line-subtle'), /rgb\(31 31 31/, '`border-pub-line-subtle` não é #1F1F1F (o `border-top` de `:712`)');
        // ⚠️⚠️ O par NÃO APROVÁVEL: fundo `#1F1F1F` (um token de BORDA usado como
        // FUNDO — precedente de `KpisDoPainel.jsx:243`, a trilha de `:645`) e
        // texto `#71717A`.
        assert.match(regraDe('bg-pub-line-subtle'), /rgb\(31 31 31/, 'o FUNDO do botão apagado não é #1F1F1F (`:1307`)');
        assert.match(regraDe('text-pub-txt-4'), /rgb\(113 113 122/, 'o TEXTO do botão apagado não é #71717A (`:1307`)');
        // ⚠️ O par APROVÁVEL: `#FACC15` no fundo E na borda, `#0A0A0A` no texto.
        assert.match(regraDe('bg-pub-accent'), /rgb\(250 204 21/, 'o FUNDO do botão aprovável não é #FACC15 (`:1307`)');
        assert.match(regraDe('border-pub-accent'), /rgb\(250 204 21/, 'a BORDA do botão aprovável não é #FACC15 (`:1307`)');
        assert.match(regraDe('text-pub-fase1'), /rgb\(212 212 216/, '`text-pub-fase1` não é #D4D4D8 (o "Próximo ↓" de `:721`)');

        // ⚠️ Os DOIS cursores do par de `:1307` — `pointer` e `not-allowed`.
        assert.match(regraDe('cursor-not-allowed'), /cursor:\s*not-allowed/, '`cursor-not-allowed` não compilou — o botão apagado pareceria clicável');

        // ⚠️ As medidas arbitrárias do rodapé, com o escape GERAL.
        assert.match(regraDe('h-[66px]'), /height:\s*66px/, 'o `height:66px` de `:712` não compilou');
        assert.match(regraDe('max-w-[420px]'), /max-width:\s*420px/, 'o `max-width:420px` de `:717` não compilou');
        // ⚠️ 4ª normalização: o cssnano COME o zero da frente — `1.35` fica `1.35`
        // mas um `0.x` sairia `.x`. Aqui o valor é > 1, e a sonda aceita as duas.
        assert.match(regraDe('leading-[1.35]'), /line-height:\s*1\.35/, 'o `line-height:1.35` de `:717` não compilou');
        assert.match(regraDe('text-[13.5px]'), /font-size:\s*13\.5px/, 'o `text-[13.5px]` de `:719` não compilou — `text-sm` é 14px e mudaria o layout');
        assert.match(regraDe('text-[12.5px]'), /font-size:\s*12\.5px/, 'o `text-[12.5px]` de `:717` não compilou');

        // ⚠️ E o `hover:` do "Próximo ↓" é de FUNDO, não de borda (`:721`).
        assert.match(regraDe('hover:bg-pub-hover-2'), /rgb\(22 22 22/, 'o `style-hover` de FUNDO de `:721` não compilou');

        const semRegra = [
            'border-t', 'bg-transparent', 'ml-auto', 'text-right', 'font-medium',
            'h-[36px]', 'h-[38px]', 'px-[14px]', 'px-[18px]', 'px-[22px]',
            'rounded-pub-control', 'text-pub-page', 'text-pub-txt',
        ].filter((c) => regraDe(c) === null);
        assert.deepEqual(semRegra, [], 'classe do rodapé NÃO gerou CSS — num `.js` isso aconteceria com todo teste de render passando');

        // ⚠️ ISCA DE AUSÊNCIA MEDIDA: o `max-w-[560px]` da FILA **existe** no CSS
        // (outro dono: `RodapeDaFila`/`LinhaDaOferta`), então ele **não serve como
        // isca** — e isso é exatamente o achado do `grid-cols-6` da 188-08: a
        // sonda de CSS **não protege a fidelidade de UM arquivo**. ➡️ A prova de
        // que o PAINEL não usa 560px mora na FONTE, não aqui.
        assert.notEqual(regraDe('max-w-[560px]'), null, 'o `max-w-[560px]` saiu do CSS — a razão desta isca não valer era ele EXISTIR por outro dono');
        assert.equal(FONTE_RODAPE.includes('max-w-[560px]'), false, 'o rodapé do PAINEL usa o `max-w-[560px]` da FILA — a prova está na FONTE');
    });
});
