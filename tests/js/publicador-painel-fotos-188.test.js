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
// Fase 188, plano 08 — A COLUNA DE FOTOS DO PAINEL: o progresso, "Aprovar N sem
// alerta", a imagem 1:1 com os MARCADORES x/y, a grade de 6 colunas e o bloco da
// imagem selecionada.
//
// ⚠️⚠️ DE CADA BLOCO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR: "o que
// precisaria estar errado no mundo para ele falhar?" — "Nada" é ACHADO.
//
//  1. COORDENADA  → alguém trocar `style={{left}}` por uma classe montada por
//                   template literal. O scanner do Tailwind **não vê** classe
//                   concatenada: ela fica no HTML, todo render passa, e **o
//                   marcador não sai do canto**. ⚠️ E a sonda tem de ser na FONTE
//                   SEM COMENTÁRIO, porque o scanner do Tailwind lê **comentário
//                   também** — medido nesta wave: a classe de exemplo escrita na
//                   prosa deste plano **virou regra de CSS de verdade**.
//  2. FAIXA       → `typeof NaN === 'number'`, então uma guarda por `typeof`
//                   desenharia marcador em `NaN%`. É `Number.isFinite` + faixa.
//  3. ORDINAL     → renumerar na tela. O número do marcador e o do item de texto
//                   saem do MESMO array no servidor; duas numerações divergiriam,
//                   e o texto diria "2" com o marcador 2 em OUTRO defeito — **a
//                   pessoa reprova a foto errada**.
//  4. BORDA       → a precedência invertida, ou o 5º ramo faltando. ⚠️ Só se prova
//                   com CONFLITO: selecionada **E** com alerta; com alerta **E**
//                   aprovada. Um estado por vez passaria com a ordem trocada.
//  5. SELEÇÃO     → usar o `borda`/`anel` do payload crus. Eles estão **presos na
//                   foto 0** (`PainelDeRevisaoService.php:310` chama
//                   `FotosDoPainelService::para($oferta)` **sem índice**), e o
//                   anel amarelo ficaria na primeira miniatura para sempre.
//  6. GRADE       → `grid-cols-6` ou `repeat(6,1fr)` em vez de
//                   `repeat(6,minmax(0,1fr))`. ⚠️ Igualdade de STRING com isca do
//                   quase-igual: `includes` casa prefixo **e sufixo de outro
//                   nome**, e já mordeu cinco vezes neste projeto.
//                   ⚠️⚠️ E a isca **não pode ser no CSS**: `grid-cols-6` EXISTE no
//                   CSS construído (outros donos) e compila para o MESMO valor —
//                   medido nesta wave. A isca é na FONTE.
//  7. SELO         → selo de estado com texto VAZIO (passa em render e desaparece
//                   na tela), ou chave desconhecida caindo em "Aprovada" —
//                   afirmar aprovação que não existe é o pior lado do erro.
//  8. CABEÇALHO    → PERGUNTA-188-A: o cabeçalho sair, ou a forma COLADA
//                   `"Verificação automática2 · "` voltar, ou o cabeçalho nascer
//                   sozinho sobre lista vazia (casca que diz "APOIO" sem apoiar).
//  9. AVISO        → PERGUNTA-188-D: um SÉTIMO toast nascer, ou o número do aviso
//                   ser recontado na tela em vez de lido de
//                   `fotos.com_alerta_restantes`, ou o aviso aparecer com 0.
// 10. BOTÃO        → "Aprovar N sem alerta" com N que não é o do servidor, ou
//                   desenhado fora do `live`/com N = 0 (botão sem destino, D4).
// 11. VERSÃO       → escrita sem `versao_esperada`: `exigirVersao()` recebe `null`
//                   e **LIBERA** a escrita — "sobrescreve em silêncio" numa tela
//                   em que aprovar ENFILEIRA e não há desfazer.
// 12. ADVERSO      → qualquer campo chegando objeto/`NaN`/array/nulo, a coleção
//                   inteira nula/string/número, **e no CLIQUE**: a TELA PRETA
//                   voltou SEIS vezes, e uma foi NO CLIQUE (187-06).
//                   `renderToStaticMarkup` **não dispara `onClick`**.
// 13. FASES        → "✦ Gerar novamente com IA" (192) antecipada; a coluna de
//                   preço (188-09), as pendências (188-10) ou os modais
//                   (188-11/12) aparecendo como provisório.
//                   ⚠️⚠️ **REAPONTADO COM VETO pela Fase 189, plano 06
//                   (D-189-3b), em 20/10/2026.** O "⤢ Ampliar" (`:607`) É da
//                   Fase 189 e passou a ser ENTREGUE: o veto virou CONDICIONAL —
//                   **sem** `onAmpliar` ele continua PROIBIDO (botão sem destino
//                   é D4 violado), **com** `onAmpliar` função ele é EXIGIDO. A
//                   ausência de "Gerar novamente" **não foi tocada**, e há isca
//                   sintética provando que ela continua acusando.
//                   (O critério de aceitação do plano dono escreveu "Fase 189,
//                   plano 05" por engano de dígito; o dono do reaponte é o
//                   **06**, e esta linha diz a verdade.)
// 14. TERRITÓRIO   → `localStorage`, `String(`, peça nova em `.js`, hex cru, e os
//                   arquivos INTOCÁVEIS tocados.
//
// ⚠️ Todos os nomes de teste terminam em `188_08`: nome genérico COLIDE no
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
const STUB_INERTIA = path.join(__dirname, `.pub-fotos188-inertia-stub-${process.pid}.mjs`);

// ⚠️⚠️ O `router` do stub **GRAVA** as chamadas: é assim que o gate prova que
// `versao_esperada` entra em TODAS as escritas desta coluna.
// ⚠️⚠️ O GRAVADOR VIVE NO `globalThis`, e isso é conserto MEDIDO na 188-07: o
// `montar()` usa `bundle: true`, então o esbuild **INLINA o stub dentro do
// bundle** — o array de dentro do bundle e o de um `import()` do stub são DOIS
// objetos DIFERENTES, e o gate lia um array sempre vazio.
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
globalThis.CHAMADAS_FOTOS_188_08 = globalThis.CHAMADAS_FOTOS_188_08 ?? [];
const CHAMADAS = globalThis.CHAMADAS_FOTOS_188_08;
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

// ⚠️ `route` é o global que o Ziggy injeta no navegador — mesma forma das waves
// 187-05/06 e 188-07.
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
        `.pub-fotos188-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';
const REL_COLUNA = `${DIR_APROV}/ColunaDeFotos.jsx`;
const REL_IMAGEM = `${DIR_APROV}/ImagemGrandeDoPainel.jsx`;
const REL_GRADE = `${DIR_APROV}/GradeDeMiniaturas.jsx`;
const REL_BLOCO = `${DIR_APROV}/BlocoDaImagemSelecionada.jsx`;
const REL_PAINEL = `${DIR_APROV}/PainelDeRevisao.jsx`;
const REL_VOCABULARIO = `${DIR_APROV}/vocabularioDoPortao.jsx`;
const REL_QUADRADOS = `${DIR_APROV}/QuadradosDeFoto.jsx`;
const REL_CASCA_MODAL = `${DIR_APROV}/CascaDeModal.jsx`;
const REL_PAGINA = 'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx';
const REL_SERVICO_FOTOS = 'app/Services/Publicador/Aprovacao/Painel/FotosDoPainelService.php';
const REL_SERVICO_PAINEL = 'app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php';
const REL_ROTAS = 'routes/mlb_anuncios.php';
const REL_GATE_VOCABULARIO = 'tests/js/publicador-vocabulario-novo.test.js';
const REL_GATE_FECHAMENTO = 'tests/js/publicador-fila-fechamento-187.test.js';
const REL_MANIFESTO = 'tests/gates/contagens-188-08.txt';
const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

// ⚠️⚠️ ANTI-FANTASMA — `test -e` em CADA caminho citado. São NOVE caminhos
// fantasmas nesta milestone, TRÊS deles vindos de briefing. Sonda de AUSÊNCIA
// sobre arquivo inexistente passa CALADA.
const CAMINHOS_CITADOS = [
    REL_COLUNA, REL_IMAGEM, REL_GRADE, REL_BLOCO, REL_PAINEL, REL_VOCABULARIO,
    REL_QUADRADOS, REL_CASCA_MODAL, REL_PAGINA, REL_SERVICO_FOTOS, REL_SERVICO_PAINEL,
    REL_ROTAS, REL_GATE_VOCABULARIO, REL_GATE_FECHAMENTO, REL_MANIFESTO,
];

// ⚠️ As fontes SEM comentário: a prosa em pt-BR deste projeto cita os próprios
// identificadores e os próprios literais, e **palavra no docblock reprova pelo
// próprio conserto**.
const FONTE_COLUNA = lerSemComentarios(REL_COLUNA);
const FONTE_IMAGEM = lerSemComentarios(REL_IMAGEM);
const FONTE_GRADE = lerSemComentarios(REL_GRADE);
const FONTE_BLOCO = lerSemComentarios(REL_BLOCO);
const FONTE_PAGINA = lerSemComentarios(REL_PAGINA);

/** ⚠️ A lista CONSTANTE dos quatro `.jsx` DESTE plano — o recorte de toda sonda. */
const FONTES_DO_PLANO = [
    [REL_IMAGEM, FONTE_IMAGEM],
    [REL_GRADE, FONTE_GRADE],
    [REL_BLOCO, FONTE_BLOCO],
    [REL_COLUNA, FONTE_COLUNA],
];

const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

const nada = () => {};

/**
 * O elemento de índice ABSOLUTO na árvore de tags de abertura do HTML.
 *
 * ⚠️⚠️ **NUNCA `indexOf(tagDeAbertura)`**: ele pega o IRMÃO ERRADO quando dois
 * elementos têm a MESMA string de classes — **quatro gates deste projeto estavam
 * medindo o irmão errado**.
 */
function tagsDeAbertura(html) {
    return [...html.matchAll(/<(div|span|section|aside|button|img)\b([^>]*)>/g)].map((m) => ({
        tag: m[1],
        atributos: m[2],
        classe: (m[2].match(/class="([^"]*)"/) || [])[1] ?? '',
        titulo: (m[2].match(/title="([^"]*)"/) || [])[1] ?? null,
        estilo: (m[2].match(/style="([^"]*)"/) || [])[1] ?? null,
        src: (m[2].match(/src="([^"]*)"/) || [])[1] ?? null,
        desabilitado: /\bdisabled\b/.test(m[2]),
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

// ═══ AS FIXTURES — ⚠️⚠️ ELAS DISCRIMINAM DE PROPÓSITO ═════════════════════
//
// ⚠️ Na wave 187-06 as duas mutações sobreviventes eram falha da FIXTURE, não da
// asserção (uma oferta só não distinguia nada). E a 188-04 criou o Premium
// PRIMEIRO (id MENOR) porque `orderBy('id')` passaria por acidente.
// ➡️ Aqui: quatro imagens, cada uma num ESTADO diferente, com alerta e sem, IA e
// original, principal e não, COM e SEM `url_arquivo`, e os ordinais fora de
// ordem alfabética para que "ler o ordinal" seja distinguível de "contar".

/** As verificações da foto 1: um `ok` (SEM ordinal) e dois alertas numerados. */
const VERIFICACOES = [
    { severidade: 'ok', texto: 'Fundo limpo e sem ruído', glifo: '✓', cor: 'verde', ordinal: null },
    { severidade: 'alto', texto: 'Texto sobreposto ilegível no canto', glifo: '!', cor: 'vermelho', ordinal: 1 },
    { severidade: 'medio', texto: 'Recorte corta a alça do produto', glifo: '!', cor: 'ambar', ordinal: 2 },
];

/**
 * Os marcadores da foto 1 — os MESMOS ordinais das verificações, e `high` × `med`
 * com cores DIFERENTES. ⚠️ x/y são PERCENTUAL (0,00–100,00), não pixel.
 */
const MARCADORES = [
    { ordinal: 1, x: 37.5, y: 12.25, cor: 'vermelho' },
    { ordinal: 2, x: 80, y: 66.5, cor: 'ambar' },
];

const FOTO_PENDENTE_COM_ALERTA = {
    id: 901,
    imagem_id: 5001,
    posicao: 1,
    posicao_rotulo: '#1',
    rotulo: 'Capa',
    titulo: 'Capa · a conferir',
    de_ia: true,
    tipo_rotulo: 'Gerada por IA',
    principal: true,
    grupo: 'COLOR=id:52049',
    grupo_geral: false,
    estado: 'pending',
    estado_rotulo: 'A conferir',
    estado_cor: 'ambar',
    estado_dot: 'ambar',
    com_alerta: true,
    aprovada: false,
    aceita_acao: true,
    borda: 'selecionada',
    anel: true,
    url_arquivo: '/fotos/901/arquivo',
    marcadores: MARCADORES,
    verificacoes: VERIFICACOES,
};

const FOTO_APROVADA = {
    ...FOTO_PENDENTE_COM_ALERTA,
    id: 902,
    imagem_id: 5002,
    posicao: 2,
    posicao_rotulo: '#2',
    rotulo: 'Foto 2',
    titulo: 'Foto 2 · aprovada',
    de_ia: false,
    tipo_rotulo: 'Original',
    principal: false,
    grupo: 'GENERAL',
    grupo_geral: true,
    estado: 'approved',
    estado_rotulo: 'Aprovada',
    estado_cor: 'verde',
    estado_dot: 'verde',
    com_alerta: false,
    aprovada: true,
    borda: 'aprovada',
    anel: false,
    url_arquivo: '/fotos/902/arquivo',
    marcadores: [],
    verificacoes: [VERIFICACOES[0]],
};

/** ⚠️ `url_arquivo: null` é caso REAL: `PubImagem.caminho` nulo → a rota dá 404. */
const FOTO_PENDENTE_SEM_ARQUIVO = {
    ...FOTO_APROVADA,
    id: 903,
    imagem_id: null,
    posicao: 3,
    posicao_rotulo: '#3',
    rotulo: 'Foto 3',
    titulo: 'Foto 3 · a conferir',
    estado: 'pending',
    estado_rotulo: 'A conferir',
    estado_cor: 'ambar',
    estado_dot: 'ambar',
    aprovada: false,
    borda: 'pendente',
    url_arquivo: null,
    verificacoes: [],
};

/** ⚠️ `regenerating` — o 5º ramo da borda (`#3F3F46`), que o REVS-09 OMITE. */
const FOTO_REGENERANDO = {
    ...FOTO_APROVADA,
    id: 904,
    imagem_id: 5004,
    posicao: 4,
    posicao_rotulo: '#4',
    rotulo: 'Foto 4',
    titulo: 'Foto 4 · a conferir',
    estado: 'regenerating',
    estado_rotulo: 'Regenerando',
    estado_cor: 'neutra',
    estado_dot: 'neutra',
    aprovada: false,
    borda: 'neutra',
    url_arquivo: '/fotos/904/arquivo',
    verificacoes: [],
};

const IMAGENS = [FOTO_PENDENTE_COM_ALERTA, FOTO_APROVADA, FOTO_PENDENTE_SEM_ARQUIVO, FOTO_REGENERANDO];

const FOTOS = {
    progresso: '1 de 4 aprovadas',
    progresso_cor: 'vermelho',
    total: 4,
    aprovadas: 1,
    pendentes: 3,
    com_alerta: 1,
    com_alerta_restantes: 1,
    nota_de_conjunto: 'Mesmas fotos de Clássico · a aprovação vale para as duas ofertas',
    imagens: IMAGENS,
    selecionada: FOTO_PENDENTE_COM_ALERTA,
    selecionada_indice: 0,
    sem_alerta: { quantidade: 2, rotulo: 'Aprovar 2 sem alerta' },
};

/** Os 6 estados do selo (`:1104`), com os rótulos de `FotosDoPainelService::SELOS`. */
const SEIS_ESTADOS = [
    ['approved', 'Aprovada', 'verde', 'verde'],
    ['pending', 'A conferir', 'ambar', 'ambar'],
    ['rejected', 'Reprovada', 'vermelho', 'vermelho'],
    ['regenerating', 'Regenerando', 'neutra', 'neutra'],
    ['replaced', 'Substituída', 'neutra', 'neutra_fraca'],
    ['removed', 'Removida', 'neutra', 'neutra_fraca'],
];

/** ⚠️ O adverso que já derrubou esta tela SEIS vezes. */
const ADVERSOS = [
    { toString: null },
    Object.create(null),
    null,
    undefined,
    Number.NaN,
    [],
    [{ toString: null }],
    '',
    0,
    false,
    Symbol.iterator,
];

// ─── Os bundles: TODOS montados ANTES do primeiro `test()` ───
const M_IMAGEM = await montar(REL_IMAGEM);
const M_GRADE = await montar(REL_GRADE);
const M_BLOCO = await montar(REL_BLOCO);
const M_COLUNA = await montar(REL_COLUNA);
const CHAMADAS = { get lista() { return globalThis.CHAMADAS_FOTOS_188_08; } };

// ═══════════════════════════════════════════════════════════════════════════
// 1. O ANTI-FANTASMA
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ ANTI-FANTASMA: todo caminho citado neste gate EXISTE no disco 188_08', () => {
    // ⚠️ UMA asserção coletando os ausentes, nunca uma por caminho.
    const ausentes = CAMINHOS_CITADOS.filter((rel) => !fs.existsSync(path.resolve(RAIZ, rel)));
    assert.deepEqual(ausentes, [], 'caminho citado não existe — sonda de ausência sobre fantasma passa calada');

    // ⚠️ ISCA: a sonda acusa um caminho que eu SEI que não existe.
    assert.equal(
        fs.existsSync(path.resolve(RAIZ, `${DIR_APROV}/NaoExiste8k3.jsx`)),
        false,
        'a isca do anti-fantasma virou arquivo de verdade',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. OS MARCADORES — por `style`, saneados, e com o ORDINAL LIDO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ MARCADOR: as coordenadas vão por `style`, e a forma por CLASSE não existe 188_08', () => {
    // ⚠️ A medida: `left:{pin.left};top:{pin.top}` em `:596`.
    assert.match(FONTE_IMAGEM, /style=\{\{\s*left:\s*`\$\{marcador\.x\}%`/, 'o `left` deixou de ir por `style`');
    assert.match(FONTE_IMAGEM, /top:\s*`\$\{marcador\.y\}%`/, 'o `top` deixou de ir por `style`');

    // ⚠️⚠️ A SONDA DA FORMA ERRADA: classe de posição montada por template
    // literal. O scanner do Tailwind NÃO a vê, a classe fica no HTML e o marcador
    // **não sai do canto**.
    const FORMA_POR_CLASSE = /(left|top)-\[\$\{/;

    const infratores = FONTES_DO_PLANO
        .filter(([, fonte]) => FORMA_POR_CLASSE.test(fonte))
        .map(([rel]) => rel);
    assert.deepEqual(infratores, [], 'coordenada montada como CLASSE por template literal — o Tailwind não gera o CSS');

    // ⚠️ ISCA das DUAS direções: a sonda ACUSA a forma errada e NÃO acusa a certa.
    assert.match('className={`left-[${x}%]`}', FORMA_POR_CLASSE, 'a sonda da forma por classe está CEGA');
    assert.doesNotMatch('style={{ left: `${x}%` }}', FORMA_POR_CLASSE, 'a sonda acusa a forma CERTA — ela é larga demais');

    // ⚠️ E a classe FIXA de posição (`left-[10px]` de `:597`) é legítima e NÃO
    // pode ser confundida com a montada: sem esta isca a sonda poderia ser
    // `/left-\[/` e reprovaria trabalho certo.
    assert.doesNotMatch('className="absolute left-[10px] top-[10px]"', FORMA_POR_CLASSE, 'a sonda reprova a classe FIXA, que é legítima');
});

test('⚠️⚠️ MARCADOR: x/y em PERCENTUAL no `style`, cor por severidade, e o ORDINAL do servidor 188_08', () => {
    const html = render(M_IMAGEM.MarcadoresDaImagem, { marcadores: MARCADORES });
    const elementos = tagsDeAbertura(html);

    assert.equal(elementos.length, 2, `esperava 2 marcadores, saíram ${elementos.length}`);

    // ⚠️ Índice ABSOLUTO na árvore, nunca `indexOf` de classes.
    assert.equal(elementos[0].estilo, 'left:37.5%;top:12.25%', `o 1º marcador saiu em ${elementos[0].estilo}`);
    assert.equal(elementos[1].estilo, 'left:80%;top:66.5%', `o 2º marcador saiu em ${elementos[1].estilo}`);

    // ⚠️⚠️ `high` e `med` dão cores DIFERENTES — sem isto os dois marcadores
    // sairiam iguais e a severidade não existiria na tela.
    assert.ok(classesDe(elementos[0]).has('bg-pub-danger'), 'o marcador de severidade alta não é vermelho');
    assert.ok(classesDe(elementos[1]).has('bg-pub-warn'), 'o marcador de severidade média não é âmbar');
    assert.notEqual(elementos[0].classe, elementos[1].classe, 'os dois marcadores saíram com a MESMA classe — a severidade não chega à tela');

    // ⚠️ A geometria de `:596`, por igualdade de CONJUNTO (nunca `includes`).
    const esperadas = new Set([
        'pointer-events-none', 'absolute', 'grid', 'h-[24px]', 'w-[24px]', '-translate-x-1/2',
        '-translate-y-1/2', 'place-items-center', 'rounded-full', 'border-2', 'border-pub-page',
        'text-[11px]', 'font-[800]', 'text-pub-page', 'shadow-[0_0_0_6px_rgba(239,68,68,0.18)]',
        'bg-pub-danger',
    ]);
    assert.deepEqual([...classesDe(elementos[0])].sort(), [...esperadas].sort(), 'as classes do marcador de `:596` divergiram');

    // ⚠️⚠️ O NÚMERO É O ORDINAL DO SERVIDOR, não a posição no `.map()`.
    assert.match(html, />1</, 'o marcador 1 não escreveu o ordinal');
    assert.match(html, />2</, 'o marcador 2 não escreveu o ordinal');
});

test('⚠️⚠️ MARCADOR: coordenada adversa NÃO desenha e NÃO derruba; lista vazia não desenha nada 188_08', () => {
    // ⚠️ `typeof NaN === 'number'` — é por isso que a guarda é `Number.isFinite`.
    const PROIBIDAS = [null, undefined, Number.NaN, '50', { toString: null }, [], -0.01, 100.01, 1e308, Infinity];

    for (const valor of PROIBIDAS) {
        const html = render(M_IMAGEM.MarcadoresDaImagem, {
            marcadores: [{ ordinal: 1, x: valor, y: 10, cor: 'vermelho' }],
        });
        assert.equal(html, '', `x = ${String(typeof valor)} desenhou marcador`);

        const htmlY = render(M_IMAGEM.MarcadoresDaImagem, {
            marcadores: [{ ordinal: 1, x: 10, y: valor, cor: 'vermelho' }],
        });
        assert.equal(htmlY, '', 'y adverso desenhou marcador');
    }

    // ⚠️ ISCA: o CONTROLE desenha. Sem ele o bloco acima passaria com a função
    // devolvendo `null` sempre — vazio por construção.
    assert.notEqual(render(M_IMAGEM.MarcadoresDaImagem, { marcadores: [{ ordinal: 9, x: 0, y: 100, cor: 'ambar' }] }), '', 'a sonda está cega: nem a coordenada VÁLIDA (0 e 100, as pontas) desenha');

    // ⚠️ Lista vazia → nenhum `span`; e a imagem continua desenhada.
    assert.equal(render(M_IMAGEM.MarcadoresDaImagem, { marcadores: [] }), '', 'lista vazia desenhou marcador');
    for (const adverso of ADVERSOS) {
        assert.doesNotThrow(() => render(M_IMAGEM.MarcadoresDaImagem, { marcadores: adverso }), `marcadores = ${String(typeof adverso)} derrubou a página`);
    }

    const semMarcador = render(M_IMAGEM.default, { imagem: { ...FOTO_PENDENTE_COM_ALERTA, marcadores: [] } });
    assert.match(semMarcador, /aspect-square/, 'sem marcador a imagem grande deixou de ser desenhada');
});

test('⚠️⚠️ ORDINAL: o número do MARCADOR é o MESMO do item da lista, LIDO e nunca recontado 188_08', () => {
    // ⚠️⚠️ Este é o erro mais caro desta coluna: duas numerações da mesma verdade
    // fariam o texto dizer "2" com o marcador 2 em OUTRO defeito, e a pessoa
    // reprovaria a foto errada.
    const htmlMarcadores = render(M_IMAGEM.MarcadoresDaImagem, { marcadores: MARCADORES });
    const htmlItens = render(M_BLOCO.VerificacoesAutomaticas, { verificacoes: VERIFICACOES });

    const numerosDoMarcador = tagsDeAbertura(htmlMarcadores).map((el, i) => {
        const re = new RegExp(`style="${el.estilo.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}"[^>]*>(\\d+)<`);
        const m = htmlMarcadores.match(re);
        return m ? Number(m[1]) : `sem-numero-${i}`;
    });

    // Os números do ITEM saem das classes do ordinal (`CLASSES_ORDINAL`), lidos do
    // HTML — não da fixture.
    const numerosDoItem = [...htmlItens.matchAll(/class="[^"]*\bflex-none text-\[12px\] font-bold[^"]*">(\d+)</g)].map((m) => Number(m[1]));

    assert.deepEqual(numerosDoMarcador, [1, 2], `os marcadores não escreveram os ordinais do servidor: ${JSON.stringify(numerosDoMarcador)}`);
    assert.deepEqual(numerosDoItem, [1, 2], `os itens não escreveram os ordinais do servidor: ${JSON.stringify(numerosDoItem)}`);
    assert.deepEqual(numerosDoItem, numerosDoMarcador, 'o número do item divergiu do número do marcador');

    // ⚠️⚠️ A PROVA DE QUE É LIDO, E NÃO CONTADO: ordinais 7 e 4 (fora de ordem e
    // fora de 1..N). Uma renumeração na tela sairia `1, 2`.
    const foraDeOrdem = [
        { ordinal: 7, x: 10, y: 10, cor: 'vermelho' },
        { ordinal: 4, x: 20, y: 20, cor: 'ambar' },
    ];
    const htmlFora = render(M_IMAGEM.MarcadoresDaImagem, { marcadores: foraDeOrdem });
    assert.deepEqual(
        [...htmlFora.matchAll(/>(\d+)</g)].map((m) => Number(m[1])),
        [7, 4],
        'o marcador RENUMEROU na tela — o ordinal tem de ser LIDO do servidor',
    );

    const itensForaDeOrdem = render(M_BLOCO.VerificacoesAutomaticas, {
        verificacoes: [
            { severidade: 'alto', texto: 'um', glifo: '!', cor: 'vermelho', ordinal: 7 },
            { severidade: 'medio', texto: 'dois', glifo: '!', cor: 'ambar', ordinal: 4 },
        ],
    });
    assert.deepEqual(
        [...itensForaDeOrdem.matchAll(/font-bold[^"]*">(\d+)</g)].map((m) => Number(m[1])),
        [7, 4],
        'o item de verificação RENUMEROU na tela',
    );

    // ⚠️ A assimetria DECLARADA (`188-FID01.md` §K): coordenada saneada tira o
    // MARCADOR e **mantém** o número do item. Renumerar seria o defeito.
    const assimetrico = render(M_IMAGEM.MarcadoresDaImagem, {
        marcadores: [{ ordinal: 1, x: null, y: 10, cor: 'vermelho' }, MARCADORES[1]],
    });
    assert.deepEqual([...assimetrico.matchAll(/>(\d+)</g)].map((m) => Number(m[1])), [2], 'o marcador sobrevivente foi RENUMERADO para 1');
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. OS QUATRO SELOS E O SELO DE ESTADO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ SELOS: `#n`, `★ PRINCIPAL` e IA × Original MUTUAMENTE EXCLUSIVOS 188_08', () => {
    const comIa = render(M_IMAGEM.SelosDaImagem, { imagem: FOTO_PENDENTE_COM_ALERTA });
    assert.match(comIa, /#1/, 'o selo `#n` de `:598` não saiu');
    assert.match(comIa, /★ PRINCIPAL/, 'o `★ PRINCIPAL` de `:599` não saiu');
    assert.match(comIa, /Gerada por IA/, 'o `✦ Gerada por IA` de `:600` não saiu');
    assert.match(comIa, /✦/, 'o glifo `✦` de `:600` não saiu');
    assert.doesNotMatch(comIa, />Original</, 'IA e Original saíram JUNTOS — `:600`/`:601` são exclusivos');

    const original = render(M_IMAGEM.SelosDaImagem, { imagem: FOTO_APROVADA });
    assert.match(original, />Original</, 'o `Original` de `:601` não saiu');
    assert.doesNotMatch(original, /Gerada por IA/, 'Original e IA saíram JUNTOS');
    assert.doesNotMatch(original, /★ PRINCIPAL/, 'o `★ PRINCIPAL` saiu numa foto que NÃO é a principal');

    // ⚠️ E nunca NENHUM dos dois: `tipo_rotulo` é sempre um dos dois no servidor.
    assert.ok(
        /Gerada por IA/.test(comIa) !== /Original/.test(comIa),
        'a foto de IA não tem exatamente UM dos dois selos de tipo',
    );

    // ⚠️ As classes literais de `:598`, por conjunto e por índice ABSOLUTO (o
    // elemento 0 é o wrapper `:597`).
    const elementos = tagsDeAbertura(comIa);
    assert.deepEqual([...classesDe(elementos[0])].sort(), ['absolute', 'flex', 'gap-[6px]', 'left-[10px]', 'pointer-events-none', 'top-[10px]'], 'o wrapper dos selos de `:597` divergiu');
    assert.deepEqual([...classesDe(elementos[1])].sort(), ['bg-pub-page/[0.8]', 'flex', 'font-semibold', 'h-[22px]', 'items-center', 'px-[8px]', 'rounded-pub-chip', 'text-[11.5px]', 'whitespace-nowrap'].sort(), 'o selo `#n` de `:598` divergiu');
    assert.deepEqual([...classesDe(elementos[2])].sort(), ['bg-pub-accent', 'flex', 'font-bold', 'h-[22px]', 'items-center', 'px-[8px]', 'rounded-pub-chip', 'text-[11px]', 'text-pub-page', 'whitespace-nowrap'].sort(), 'o `★ PRINCIPAL` de `:599` divergiu');
});

test('⚠️⚠️ SELO DE ESTADO: TEXTO NÃO-VAZIO nos 6 estados, e chave desconhecida cai no NEUTRO 188_08', () => {
    // ⚠️ Guarda que devolvesse `null` produziria elemento VAZIO, que **passa em
    // render e DESAPARECE na tela** (conserto medido na 187-08).
    const vazios = [];
    for (const [estado, rotulo, cor, dot] of SEIS_ESTADOS) {
        const html = render(M_IMAGEM.SeloDeEstado, {
            imagem: { ...FOTO_APROVADA, estado, estado_rotulo: rotulo, estado_cor: cor, estado_dot: dot },
        });
        if (texto(html) === '') vazios.push(estado);
        assert.match(html, new RegExp(rotulo.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `o selo de ${estado} não escreveu "${rotulo}"`);
    }
    assert.deepEqual(vazios, [], 'selo de estado com TEXTO VAZIO — passa em render e desaparece na tela');

    // ⚠️⚠️ Chave de cor DESCONHECIDA cai no NEUTRO, **nunca** no verde: afirmar
    // aprovação que não existe é o pior lado do erro.
    const desconhecida = render(M_IMAGEM.SeloDeEstado, {
        imagem: { ...FOTO_APROVADA, estado: 'coisa_nova', estado_rotulo: 'Estado não reconhecido', estado_cor: 'cor_que_nao_existe', estado_dot: { toString: null } },
    });
    assert.match(desconhecida, /Estado não reconhecido/, 'o rótulo neutro do servidor não chegou à tela');
    assert.ok(classesDe(tagsDeAbertura(desconhecida)[0]).has('text-pub-neutral-txt'), 'chave de cor desconhecida não caiu no NEUTRO');
    assert.doesNotMatch(desconhecida, /text-pub-ok-txt/, 'chave desconhecida caiu no VERDE — a tela afirmaria aprovação que não existe');

    // ⚠️ E rótulo ausente **não desenha o selo**, em vez de desenhar pílula muda.
    assert.equal(render(M_IMAGEM.SeloDeEstado, { imagem: { ...FOTO_APROVADA, estado_rotulo: '   ' } }), '', 'rótulo só-espaço desenhou uma pílula VAZIA');

    // ⚠️ ISCA: o controle desenha.
    assert.notEqual(render(M_IMAGEM.SeloDeEstado, { imagem: FOTO_APROVADA }), '', 'a sonda está cega: nem o controle desenha');
});

test('⚠️ `:605` "Gerando nova versão…" é ESTADO e nasce; `:606` é de OUTRA FASE e NÃO nasce; `:607` nasce SÓ COM HANDLER 188_08', () => {
    const regenerando = render(M_IMAGEM.default, { imagem: FOTO_REGENERANDO });
    assert.match(regenerando, /Gerando nova versão…/, 'o aviso de `:605` não saiu em `regenerating`');
    assert.match(regenerando, /animate-pub-spin/, 'o spinner de `:605` não saiu');
    // ⚠️ A duração do protótipo (`.8s`) vai por `style`, porque `duration-[Nms]`
    // NÃO COMPILA e o token `animate-pub-spin` é `.7s` — medido.
    assert.match(regenerando, /animation-duration:\.?8s/, 'a duração `.8s` de `:605` não entrou por `style`');

    const pendente = render(M_IMAGEM.default, { imagem: FOTO_PENDENTE_COM_ALERTA });
    assert.doesNotMatch(pendente, /Gerando nova versão/, 'o aviso de `:605` saiu num estado que NÃO é `regenerating`');

    // ⚠️⚠️ GATE DE AUSÊNCIA — rodado com as props PREENCHIDAS, para não ser gate
    // sobre zero casos.
    const completo = render(M_COLUNA.default, {
        fotos: FOTOS, live: true, selecionada: 0, onSelecionar: nada,
        empresaId: 7, ofertaId: 12, versao: 3,
    });
    assert.ok(completo.length > 1000, `a coluna saiu curta demais (${completo.length} bytes) — a sonda de ausência mediria zero casos`);

    // ═══ ⚠️⚠️ REAPONTE COM VETO — Fase 189, plano 06 (D-189-3b), 20/10/2026 ═══
    //
    // *"Teste que para de provar algo é pior que teste ausente: cirurgia é
    // REAPONTE com veto, e CONTE TESTES, não linhas"* (`188-REGUA.md` §10).
    // ➡️ As DUAS asserções de ausência de `:607` **não foram apagadas**: elas
    // passaram a medir o regime **SEM handler**, que é a parte que continua
    // verdadeira — botão sem destino é D4 violado, e `onClick` vazio é a mesma
    // dívida com outra roupa. A exigência de PRESENÇA com handler entrou LOGO
    // ABAIXO, no mesmo caso (acrescentar `test()` mudaria a contagem do manifesto
    // da 188-08, e reaponte não acrescenta nem remove teste).
    //
    // ⚠️⚠️ **A AUSÊNCIA DA FASE 192 NÃO FOI AFROUXADA** (T-189-40): ela é cobrada
    // nos DOIS regimes, e a isca sintética abaixo prova que a sonda dela continua
    // acusando. *"Nunca afrouxe a ausência de Gerar novamente"* — D-189-3.
    //
    // (O critério de aceitação do 189-06 escreveu "Fase 189, plano 05" por engano
    // de dígito; o dono do reaponte é o **06**.)
    assert.doesNotMatch(completo, /Ampliar/, 'o "⤢ Ampliar" nasceu SEM `onAmpliar` — botão sem destino (D4)');
    assert.doesNotMatch(completo, /⤢/, 'o glifo do "⤢ Ampliar" nasceu SEM `onAmpliar`');
    assert.doesNotMatch(completo, /Gerar novamente/, '"✦ Gerar novamente com IA" é da Fase 192 e foi ANTECIPADO');

    // ➡️ E COM `onAmpliar` função ele é **EXIGIDO** (Fase 189, plano 06).
    const comAmpliar = render(M_COLUNA.default, {
        fotos: FOTOS, live: true, selecionada: 0, onSelecionar: nada,
        empresaId: 7, ofertaId: 12, versao: 3, onAmpliar: nada,
    });
    assert.match(comAmpliar, /⤢ Ampliar/, 'o "⤢ Ampliar" NÃO nasceu com `onAmpliar` função — a Fase 189 o entrega (D-189-3b)');
    assert.doesNotMatch(comAmpliar, /Gerar novamente/, 'a inversão do "⤢ Ampliar" AFROUXOU a ausência da Fase 192 (T-189-40)');

    // ⚠️⚠️ ISCA SINTÉTICA NAS DUAS DIREÇÕES: as duas sondas enxergam o que devem
    // enxergar, e **não** uma a outra. Sem isto, "nenhum achado" não prova nada.
    assert.match('<button>✦ Gerar novamente com IA</button>', /Gerar novamente/, 'a sonda da Fase 192 está CEGA');
    assert.doesNotMatch('<button>⤢ Ampliar</button>', /Gerar novamente/, 'a sonda da Fase 192 acusa o "⤢ Ampliar" — ela é mais larga que o invariante');
    assert.match('<button>⤢ Ampliar</button>', /⤢ Ampliar/, 'a sonda do "⤢ Ampliar" está CEGA');
    assert.doesNotMatch('<button>✦ Gerar novamente com IA</button>', /⤢/, 'a sonda do glifo `⤢` acusa a Fase 192 — ela é mais larga que o invariante');

    // ⚠️ `onClick` vazio é a mesma dívida que botão sem destino, com outra roupa.
    const semDestino = FONTES_DO_PLANO.filter(([, fonte]) => /onClick=\{\(\)\s*=>\s*\{\s*\}\}/.test(fonte)).map(([rel]) => rel);
    assert.deepEqual(semDestino, [], '`onClick` vazio — botão sem destino com outra roupa (D4)');

    // ⚠️ ISCA das duas direções para a sonda de `onClick` vazio.
    assert.match('onClick={() => {}}', /onClick=\{\(\)\s*=>\s*\{\s*\}\}/, 'a sonda de `onClick` vazio está CEGA');
    assert.doesNotMatch('onClick={() => aprovar()}', /onClick=\{\(\)\s*=>\s*\{\s*\}\}/, 'a sonda de `onClick` vazio acusa handler de VERDADE');
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. A GRADE DE 6 COLUNAS E A PRECEDÊNCIA DAS CINCO BORDAS
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ GRADE: `repeat(6,minmax(0,1fr))` por IGUALDADE, com isca do quase-igual 188_08', () => {
    const html = render(M_GRADE.default, { imagens: IMAGENS, selecionada: 0, onSelecionar: nada });
    const grade = tagsDeAbertura(html)[0];

    // ⚠️ Igualdade de CONJUNTO, nunca `includes`: `includes` casa prefixo E sufixo
    // de outro nome, e já mordeu CINCO vezes neste projeto.
    assert.deepEqual([...classesDe(grade)].sort(), ['gap-[6px]', 'grid', 'grid-cols-[repeat(6,minmax(0,1fr))]'], 'a grade de `:610` divergiu');

    // ⚠️⚠️ A ISCA É NA FONTE, E NÃO NO CSS: `grid-cols-6` **EXISTE** no CSS
    // construído (outros donos) e compila para o MESMO valor — medido nesta wave
    // em 19/10/2026. Um gate de CSS aqui passaria com `grid-cols-6` escrito.
    assert.match(FONTE_GRADE, /grid-cols-\[repeat\(6,minmax\(0,1fr\)\)\]/, 'a classe literal de `:610` saiu da fonte');
    assert.doesNotMatch(FONTE_GRADE, /\bgrid-cols-6\b/, '`grid-cols-6` entrou — o protótipo cravou `repeat(6,minmax(0,1fr))`');
    assert.doesNotMatch(FONTE_GRADE, /grid-cols-\[repeat\(6,1fr\)\]/, '`repeat(6,1fr)` entrou — ele não clampa o `min-width:auto`');

    // ⚠️ ISCA das duas direções da sonda do quase-igual.
    assert.match('className="grid grid-cols-6 gap-2"', /\bgrid-cols-6\b/, 'a sonda do `grid-cols-6` está CEGA');
    assert.doesNotMatch('grid-cols-[repeat(6,minmax(0,1fr))]', /\bgrid-cols-6\b/, 'a sonda do `grid-cols-6` acusa a classe CERTA — ela é larga demais');

    assert.equal(tagsDeAbertura(html).filter((el) => classesDe(el).has('aspect-square')).length, 4, 'a grade não desenhou as 4 miniaturas');
});

test('⚠️⚠️ BORDA: a precedência de CINCO ramos, provada COM CONFLITO, e o anel SÓ na selecionada 188_08', () => {
    const { chaveDaBorda, BORDA_SELECIONADA, BORDA_ALERTA, BORDA_APROVADA, BORDA_PENDENTE, BORDA_NEUTRA } = M_GRADE;

    // ⚠️⚠️ OS CONFLITOS — sem eles a ordem invertida passaria.
    assert.equal(chaveDaBorda('pending', true, true), BORDA_SELECIONADA, 'selecionada E com alerta: a SELEÇÃO tem de ganhar');
    assert.equal(chaveDaBorda('approved', true, true), BORDA_SELECIONADA, 'selecionada E aprovada E com alerta: a SELEÇÃO tem de ganhar');
    assert.equal(chaveDaBorda('approved', false, true), BORDA_ALERTA, 'aprovada E com alerta: o ALERTA tem de ganhar');
    assert.equal(chaveDaBorda('pending', false, true), BORDA_ALERTA, 'pendente E com alerta: o ALERTA tem de ganhar');
    assert.equal(chaveDaBorda('approved', false, false), BORDA_APROVADA, 'aprovada sem alerta não saiu verde');
    assert.equal(chaveDaBorda('pending', false, false), BORDA_PENDENTE, 'pendente sem alerta não saiu âmbar');

    // ⚠️⚠️ O 5º RAMO, que o REVS-09 OMITE (`.planning/REQUIREMENTS-v27.md:596`
    // lista QUATRO bordas). Ele pinta os quatro estados restantes.
    for (const estado of ['rejected', 'regenerating', 'replaced', 'removed', 'coisa_nova', null, undefined]) {
        assert.equal(chaveDaBorda(estado, false, false), BORDA_NEUTRA, `${String(estado)} não caiu no 5º ramo`);
    }

    // ⚠️⚠️ UM VOCABULÁRIO SÓ: as cinco chaves são as de `FotosDoPainelService`,
    // lidas do PHP. Duas listas da mesma verdade divergiriam na 1ª edição.
    const php = fs.readFileSync(path.resolve(RAIZ, REL_SERVICO_FOTOS), 'utf8');
    const doPhp = [...php.matchAll(/public const BORDA_[A-Z]+ = '([a-z_]+)';/g)].map((m) => m[1]).sort();
    assert.ok(doPhp.length > 0, 'a leitura das chaves de borda no PHP está CEGA');
    assert.deepEqual(
        doPhp,
        [BORDA_ALERTA, BORDA_APROVADA, BORDA_NEUTRA, BORDA_PENDENTE, BORDA_SELECIONADA].sort(),
        'as chaves de borda da TELA divergiram das do SERVIÇO — duas listas da mesma verdade',
    );

    // ⚠️ E as cinco têm classe DIFERENTE: um mapa que devolvesse a mesma cor para
    // tudo passaria o bloco acima.
    const classes = doPhp.map((chave) => M_GRADE.classeDaBorda(chave));
    assert.equal(new Set(classes).size, 5, `as cinco bordas não têm cinco classes distintas: ${JSON.stringify(classes)}`);

    // ⚠️⚠️ O ANEL é box-shadow ADICIONAL à borda (`:1113`), a 2ª omissão do
    // REVS-09 — e existe **só** na selecionada.
    const html = render(M_GRADE.default, { imagens: IMAGENS, selecionada: 1, onSelecionar: nada });
    const miniaturas = tagsDeAbertura(html).filter((el) => classesDe(el).has('aspect-square'));
    assert.equal(miniaturas.length, 4, 'a grade não desenhou as 4 miniaturas');

    const comAnel = miniaturas.map((el, i) => (classesDe(el).has('ring-2') ? i : null)).filter((i) => i !== null);
    assert.deepEqual(comAnel, [1], `o anel não está SÓ na selecionada: ${JSON.stringify(comAnel)}`);

    // ⚠️⚠️ A BORDA SEGUE A IMAGEM SELECIONADA, NÃO A FOTO 0 — e o payload manda
    // `borda: 'selecionada'`/`anel: true` na foto 0, de propósito
    // (`PainelDeRevisaoService.php:310` chama `para($oferta)` SEM índice).
    assert.equal(IMAGENS[0].borda, 'selecionada', 'a fixture deixou de carregar o `borda` preso na foto 0 — ela não discrimina mais');
    assert.equal(IMAGENS[0].anel, true, 'a fixture deixou de carregar o `anel` preso na foto 0');
    assert.ok(!classesDe(miniaturas[0]).has('ring-2'), 'a miniatura 0 ficou com o anel — a tela está lendo o `anel` PRESO do payload');
    assert.ok(classesDe(miniaturas[0]).has('border-pub-danger'), 'a miniatura 0 (pendente COM alerta, não selecionada) devia sair vermelha');
    assert.ok(classesDe(miniaturas[1]).has('border-pub-accent'), 'a selecionada não saiu amarela');
    assert.ok(classesDe(miniaturas[3]).has('border-pub-line-off'), 'a `regenerating` não caiu no 5º ramo na TELA');
});

test('⚠️ MINIATURA: os dois badges nas condições certas, e `title` NUNCA vazio 188_08', () => {
    const html = render(M_GRADE.default, { imagens: IMAGENS, selecionada: 0, onSelecionar: nada });
    const elementos = tagsDeAbertura(html);

    // ⚠️ `:1105` — o badge `!` é de UM estado só: `pending` COM alerta.
    const alertas = elementos.filter((el) => classesDe(el).has('bg-pub-danger') && classesDe(el).has('rounded-full'));
    assert.equal(alertas.length, 1, `o badge \`!\` saiu ${alertas.length} vez(es); esperava 1 (só a pendente com alerta)`);

    const aprovadas = elementos.filter((el) => classesDe(el).has('bg-pub-ok') && classesDe(el).has('rounded-full'));
    assert.equal(aprovadas.length, 1, `o badge \`✓\` saiu ${aprovadas.length} vez(es); esperava 1 (só a aprovada)`);

    // ⚠️ E as regras NÃO são a mesma: uma foto aprovada COM alerta tem `✓` e NÃO
    // tem `!`. Unificá-las é o defeito que o serviço declara.
    const aprovadaComAlerta = render(M_GRADE.default, {
        imagens: [{ ...FOTO_APROVADA, com_alerta: true }], selecionada: null, onSelecionar: nada,
    });
    const els = tagsDeAbertura(aprovadaComAlerta);
    assert.equal(els.filter((el) => classesDe(el).has('bg-pub-danger') && classesDe(el).has('rounded-full')).length, 0, 'foto APROVADA com alerta ganhou badge `!` — `:1105` é de UM estado');
    assert.equal(els.filter((el) => classesDe(el).has('bg-pub-ok') && classesDe(el).has('rounded-full')).length, 1, 'foto aprovada perdeu o badge `✓`');
    // ⚠️ Mas a BORDA dela é a de alerta — são regras diferentes de propósito.
    // ⚠️⚠️ A miniatura é achada por `aspect-square`, e **não** por `els[0]`: o
    // elemento 0 é a GRADE. Furo na própria sonda, pego nesta wave — é a família
    // dos quatro gates deste projeto que mediam o IRMÃO ERRADO.
    const soUma = els.filter((el) => classesDe(el).has('aspect-square'));
    assert.equal(soUma.length, 1, 'a fixture de uma foto não desenhou uma miniatura');
    assert.ok(classesDe(soUma[0]).has('border-pub-danger'), 'a borda de alerta não venceu numa foto aprovada COM alerta');

    // ⚠️⚠️ `title=""` num botão de área invisível: elemento sem nome acessível
    // **passa em render e desaparece na tela**.
    const botoes = elementos.filter((el) => el.tag === 'button');
    assert.equal(botoes.length, 4, `esperava 4 botões invisíveis, saíram ${botoes.length}`);
    const semTitulo = botoes.map((el, i) => (typeof el.titulo === 'string' && el.titulo.trim() !== '' ? null : i)).filter((i) => i !== null);
    assert.deepEqual(semTitulo, [], 'botão de miniatura com `title` VAZIO — elemento sem nome acessível');
    assert.deepEqual(botoes.map((el) => el.titulo), IMAGENS.map((i) => i.titulo), 'o `title` deixou de ser o `titulo` do servidor');
    assert.deepEqual([...classesDe(botoes[0])].sort(), ['absolute', 'bg-transparent', 'border-none', 'cursor-pointer', 'inset-0', 'p-0'], 'o botão invisível de `:614` divergiu');

    // ⚠️ Os três degraus do `title`, para ele NUNCA sair vazio.
    const semNada = render(M_GRADE.default, { imagens: [{ ...FOTO_APROVADA, titulo: '  ', rotulo: '' }], selecionada: null });
    assert.equal(tagsDeAbertura(semNada).find((el) => el.tag === 'button').titulo, M_GRADE.TITULO_PADRAO, 'o último degrau do `title` não entrou');

    // ⚠️ `url_arquivo === null` → NENHUM `<img>`, e **nunca** `src=""` (string
    // vazia num `src` faz o navegador recarregar a própria página).
    const imgs = elementos.filter((el) => el.tag === 'img');
    assert.equal(imgs.length, 3, `esperava 3 <img> (a 3ª foto não tem arquivo), saíram ${imgs.length}`);
    assert.deepEqual(imgs.map((el) => el.src).filter((s) => s === ''), [], '`<img src="">` — o navegador recarregaria a própria página');
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. O BLOCO DA SELECIONADA — PERGUNTA-188-A (HÍBRIDO)
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ PERGUNTA-188-A: o cabeçalho `VERIFICAÇÃO AUTOMÁTICA · APOIO` UMA vez, e a forma COLADA não existe 188_08', () => {
    const html = render(M_BLOCO.VerificacoesAutomaticas, { verificacoes: VERIFICACOES });

    const CABECALHO = 'VERIFICAÇÃO AUTOMÁTICA · APOIO';
    const ocorrencias = html.split(CABECALHO).length - 1;
    assert.equal(ocorrencias, 1, `o cabeçalho saiu ${ocorrencias} vez(es); a decisão do usuário é UMA`);

    // ⚠️ As medidas de `:369`: `11px`, `600` e `0.08em`. FID-03: nunca `text-xs`.
    const cabecalho = tagsDeAbertura(html).find((el) => classesDe(el).has('tracking-[0.08em]'));
    assert.ok(cabecalho, 'o cabeçalho perdeu o `tracking-[0.08em]` de `:369`');
    assert.deepEqual([...classesDe(cabecalho)].sort(), ['font-semibold', 'text-[11px]', 'text-pub-txt-4', 'tracking-[0.08em]'], 'as classes do cabeçalho de `:369` divergiram');

    // ⚠️⚠️ A FORMA COLADA DO `:633` NÃO É REPRODUZIDA: o `a.n` do protótipo
    // (`:1108`) é concatenado DENTRO da frase, produzindo
    // `"Verificação automática2 · "`. Aqui o ordinal é ELEMENTO PRÓPRIO.
    const COLADA = /autom[áa]tica\s*(\{|\$\{|['"`]?\s*\+)/;
    const colados = FONTES_DO_PLANO.filter(([, fonte]) => COLADA.test(fonte)).map(([rel]) => rel);
    assert.deepEqual(colados, [], 'a forma COLADA `automática` + ordinal voltou — o ordinal é o número do MARCADOR, não sufixo');

    // ⚠️ ISCA das DUAS direções.
    assert.match("'Verificação automática' + a.ordinal", COLADA, 'a sonda da forma colada está CEGA');
    assert.match('`Verificação automática${ordinal}`', COLADA, 'a sonda da forma colada não pega o template literal');
    assert.doesNotMatch('<span>{item.ordinal}</span><span>{item.texto}</span>', COLADA, 'a sonda da forma colada acusa o número SEPARADO, que é o certo');

    // ⚠️ E o HTML também não tem a frase minúscula do painel.
    assert.doesNotMatch(html, /Verificação automática\d/, 'o número saiu COLADO na palavra no HTML');
    assert.doesNotMatch(html, /Verificação automática ·/, 'a frase minúscula de `:633` voltou — o cabeçalho substituiu a repetição');
});

test('⚠️ VERIFICAÇÕES: `ok` sem número, severidade com cor, e lista vazia SEM cabeçalho e SEM caixa 188_08', () => {
    const html = render(M_BLOCO.VerificacoesAutomaticas, { verificacoes: VERIFICACOES });
    const itens = tagsDeAbertura(html).filter((el) => classesDe(el).has('leading-[1.4]'));
    assert.equal(itens.length, 3, `esperava 3 itens, saíram ${itens.length}`);

    // ⚠️ O `ok` tem `ordinal: null` e **não** mostra número: `0` seria um número de
    // marcador PLAUSÍVEL para quem lê, e `''` é mentira de tipo.
    const numeros = [...html.matchAll(/font-bold[^"]*">(\d+)</g)].map((m) => Number(m[1]));
    assert.deepEqual(numeros, [1, 2], `o item \`ok\` ganhou número: ${JSON.stringify(numeros)}`);
    assert.match(html, /Fundo limpo e sem ruído/, 'o texto do item `ok` não saiu');

    // ⚠️ Cor por severidade, nas TRÊS: verde, vermelho e âmbar — e DIFERENTES.
    const glifos = tagsDeAbertura(html).filter((el) => classesDe(el).has('text-[9.5px]'));
    assert.equal(glifos.length, 3, 'os glifos dos itens não saíram');
    assert.ok(classesDe(glifos[0]).has('bg-pub-ok/[0.14]') && classesDe(glifos[0]).has('text-pub-ok-txt'), 'o glifo `ok` não saiu verde');
    assert.ok(classesDe(glifos[1]).has('bg-pub-danger/[0.16]') && classesDe(glifos[1]).has('text-pub-danger-txt'), 'o glifo de severidade alta não saiu vermelho');
    assert.ok(classesDe(glifos[2]).has('bg-pub-warn/[0.16]') && classesDe(glifos[2]).has('text-pub-warn-txt'), 'o glifo de severidade média não saiu âmbar');
    assert.equal(new Set(glifos.map((el) => el.classe)).size, 3, 'as três severidades saíram com a MESMA classe');
    assert.match(html, />✓</, 'o glifo `✓` não saiu');
    assert.match(html, />!</, 'o glifo `!` não saiu');

    // ⚠️⚠️ Lista vazia → **nem cabeçalho nem caixa**. Cabeçalho sozinho é casca
    // vazia, e um cabeçalho que diz "APOIO" sem nada embaixo não apoia nada.
    for (const vazia of [[], null, undefined, 'x', 7, { toString: null }, [{ toString: null }], [{ texto: '   ' }]]) {
        assert.equal(render(M_BLOCO.VerificacoesAutomaticas, { verificacoes: vazia }), '', `verificacoes = ${String(typeof vazia)} desenhou casca`);
    }

    // ⚠️ E no BLOCO inteiro: sem verificação, nenhum cabeçalho.
    const bloco = render(M_BLOCO.default, { imagem: FOTO_PENDENTE_SEM_ARQUIVO, live: true });
    assert.doesNotMatch(bloco, /VERIFICAÇÃO AUTOMÁTICA/, 'o cabeçalho nasceu sem nenhuma verificação — casca vazia');
    assert.match(bloco, /#3/, 'o bloco deixou de desenhar a identificação');
});

test('⚠️ BLOCO: `#n · rótulo` com truncate, só o TIPO, e as duas ações nos DOIS estados 188_08', () => {
    const html = render(M_BLOCO.default, { imagem: FOTO_PENDENTE_COM_ALERTA, live: true, onAprovar: nada, onReprovar: nada });
    const elementos = tagsDeAbertura(html);

    // ⚠️ `truncate` é exatamente `overflow:hidden` + `text-overflow:ellipsis` +
    // `white-space:nowrap` — os TRÊS de `:623`. O `nowrap` foi omitido SETE vezes
    // na Fase 187.
    const titulo = elementos.find((el) => classesDe(el).has('truncate'));
    assert.ok(titulo, 'o `#n · rótulo` perdeu o `truncate` (os três de `:623`)');
    assert.deepEqual([...classesDe(titulo)].sort(), ['font-semibold', 'text-[13px]', 'truncate'], 'as classes do título de `:623` divergiram');
    assert.match(html, /#1 · Capa/, 'o `#n · rótulo` não saiu');

    // ⚠️ O wrapper INTERNO com `min-w-0` é o que FAZ o ellipsis funcionar — a
    // `188-FID01.md` o omitia (achado da re-medição do 188-01).
    assert.ok(elementos.some((el) => classesDe(el).has('min-w-0') && classesDe(el).has('flex-col')), 'o wrapper `min-width:0` de `:623` saiu — sem ele o título NÃO trunca');

    // ⚠️⚠️ A VARIAÇÃO não é desenhada, e a CHAVE DO GRUPO **não vai para a tela**:
    // ela é `COLOR=id:52049` (`ChaveCanonica::combinacao()`), não um nome. Jargão
    // não vai para a tela.
    assert.match(html, />Gerada por IA</, 'o `tipo` de `:623` não saiu');
    assert.doesNotMatch(html, /COLOR=id:52049/, 'a CHAVE CANÔNICA do grupo foi para a tela — é jargão, não rótulo');
    assert.doesNotMatch(html, /GENERAL/, 'a chave `GENERAL` foi para a tela');

    // ⚠️ As duas ações, com as classes literais de `:626`/`:627`.
    const botoes = elementos.filter((el) => el.tag === 'button');
    assert.deepEqual(botoes.map((el) => texto(el.atributos) && null).filter(Boolean), [], 'leitura auxiliar');
    assert.equal(botoes.length, 2, `esperava 2 botões, saíram ${botoes.length}`);
    assert.deepEqual([...classesDe(botoes[0])].sort(), ['bg-transparent', 'border', 'border-pub-danger/[0.45]', 'cursor-pointer', 'font-medium', 'h-[30px]', 'px-[11px]', 'rounded-pub-control-sm', 'text-[12.5px]', 'text-pub-danger-txt', 'whitespace-nowrap'].sort(), 'as classes de "Reprovar" (`:626`) divergiram');
    assert.deepEqual([...classesDe(botoes[1])].sort(), ['bg-pub-accent', 'border-none', 'cursor-pointer', 'font-semibold', 'h-[30px]', 'px-[12px]', 'rounded-pub-control-sm', 'text-[12.5px]', 'text-pub-page', 'whitespace-nowrap'].sort(), 'as classes de "Aprovar foto" (`:627`) divergiram');
    assert.match(html, />Reprovar</, '"Reprovar" não saiu');
    assert.match(html, />Aprovar foto</, '"Aprovar foto" não saiu');

    // ⚠️⚠️ B2 — `onReprovar = null` → o botão **NÃO existe**, e **não existe casca
    // vazia no lugar dele**. O plano 188-12 é quem o religa, junto com o modal.
    const semReprovar = render(M_BLOCO.default, { imagem: FOTO_PENDENTE_COM_ALERTA, live: true, onAprovar: nada, onReprovar: null });
    assert.doesNotMatch(semReprovar, />Reprovar</, '"Reprovar" nasceu sem handler — botão sem destino (D4)');
    assert.match(semReprovar, />Aprovar foto</, '"Aprovar foto" caiu junto com o "Reprovar"');

    const semNenhum = render(M_BLOCO.default, { imagem: FOTO_PENDENTE_COM_ALERTA, live: true, onAprovar: null, onReprovar: null });
    assert.doesNotMatch(semNenhum, /<button/, 'botão nasceu sem handler nenhum');
    const wrappers = tagsDeAbertura(semNenhum).filter((el) => el.classe === 'flex gap-[6px]');
    assert.deepEqual(wrappers, [], 'o wrapper dos botões de `:625` ficou VAZIO na tela — casca decorativa');

    // ⚠️⚠️ `:1311` (e não `:1314`): `isPending && inf.live`. **São condições
    // diferentes; não unifique.**
    const foraDoLive = render(M_BLOCO.default, { imagem: FOTO_PENDENTE_COM_ALERTA, live: false, onAprovar: nada, onReprovar: nada });
    assert.doesNotMatch(foraDoLive, /<button/, 'fora do `live` as ações nasceram — a escrita é recusada pelo servidor e o botão mente');

    const aprovada = render(M_BLOCO.default, { imagem: FOTO_APROVADA, live: true, onAprovar: nada, onReprovar: nada });
    assert.doesNotMatch(aprovada, /<button/, 'foto APROVADA ganhou as ações de `pending`');
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. A COLUNA — progresso, "Aprovar N sem alerta", o aviso e o vazio honesto
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ PROGRESSO: `x de y aprovadas` nas TRÊS cores, e a nota de conjunto 188_08', () => {
    const base = { fotos: FOTOS, live: true, selecionada: 0, onSelecionar: nada, empresaId: 7, ofertaId: 12, versao: 3 };
    const html = render(M_COLUNA.default, base);

    assert.match(html, />Fotos</, '"Fotos" de `:589` não saiu');
    assert.match(html, /1 de 4 aprovadas/, 'o progresso do servidor não chegou à tela');

    const faixa = tagsDeAbertura(html).find((el) => classesDe(el).has('items-baseline'));
    assert.ok(faixa, 'o alinhamento por BASELINE de `:589` saiu');

    // ⚠️ As TRÊS cores de `:1303`: `pend ? (alerts ? vermelho : âmbar) : verde`.
    const corDe = (chave) => {
        const h = render(M_COLUNA.default, { ...base, fotos: { ...FOTOS, progresso_cor: chave } });
        const el = tagsDeAbertura(h).find((x) => classesDe(x).has('text-[12.5px]') && classesDe(x).has('font-medium'));
        return [...classesDe(el)].find((c) => c.startsWith('text-pub-')) ?? null;
    };
    assert.equal(corDe('vermelho'), 'text-pub-danger-txt', 'o progresso com alerta não saiu vermelho');
    assert.equal(corDe('ambar'), 'text-pub-warn-txt', 'o progresso com pendente sem alerta não saiu âmbar');
    assert.equal(corDe('verde'), 'text-pub-ok-txt', 'o progresso de tudo aprovado não saiu verde');
    assert.equal(new Set([corDe('vermelho'), corDe('ambar'), corDe('verde')]).size, 3, 'as três cores do progresso não são três');
    // ⚠️ Chave desconhecida cai no NEUTRO, nunca no verde.
    assert.equal(corDe('cor_que_nao_existe'), 'text-pub-neutral-txt', 'chave de cor desconhecida no progresso não caiu no NEUTRO');

    // ⚠️ `:592` — a nota de conjunto, com o `margin-top:-6px` da medida.
    assert.match(html, /Mesmas fotos de Clássico · a aprovação vale para as duas ofertas/, 'a nota de conjunto de `:592` não saiu');
    const nota = tagsDeAbertura(html).find((el) => el.classe === '-mt-[6px] text-[12px] text-pub-txt-2');
    assert.ok(nota, 'as classes da nota de `:592` divergiram');

    const semNota = render(M_COLUNA.default, { ...base, fotos: { ...FOTOS, nota_de_conjunto: null } });
    assert.doesNotMatch(semNota, /Mesmas fotos de/, 'a nota nasceu sem dado');
});

test('⚠️⚠️ "Aprovar N sem alerta": o N do SERVIDOR, nos DOIS eixos, e a escrita com `versao_esperada` 188_08', () => {
    const base = { fotos: FOTOS, live: true, selecionada: 0, onSelecionar: nada, empresaId: 7, ofertaId: 12, versao: 3 };
    const html = render(M_COLUNA.default, base);

    // ⚠️⚠️ O rótulo é o do SERVIDOR, e o N é o MESMO que a ação aprova: a wave
    // 188-05 mediu `quantidade = 3 → aprovadas = 3, grupos_percorridos = 2`.
    assert.match(html, />Aprovar 2 sem alerta</, 'o rótulo do servidor não chegou ao botão');
    // ⚠️ E a tela NÃO reconta: o número do botão não é `pendentes` (3) nem
    // `total` (4). Sem esta asserção, uma contagem na tela passaria.
    assert.doesNotMatch(html, />Aprovar 3 sem alerta</, 'o botão está contando na TELA — o N é `sem_alerta.quantidade`');
    assert.doesNotMatch(html, />Aprovar 4 sem alerta</, 'o botão está usando o TOTAL');

    const botao = tagsDeAbertura(html).find((el) => el.tag === 'button' && classesDe(el).has('h-[28px]'));
    assert.ok(botao, 'o botão de `:590` não saiu');
    assert.deepEqual([...classesDe(botao)].sort(), ['bg-pub-hover-2', 'border', 'border-pub-line-strong', 'cursor-pointer', 'h-[28px]', 'hover:border-pub-line-off', 'px-[10px]', 'rounded-pub-control-sm', 'text-[12px]', 'text-pub-txt', 'whitespace-nowrap'].sort(), 'as classes do botão de `:590` divergiram');

    // ⚠️ `sem_alerta = null` (N = 0 **ou** fora do `live`, o `hasSafe` de `:1305`)
    // → **NENHUM botão**. Botão sem destino é D4 violado.
    const semBotao = render(M_COLUNA.default, { ...base, fotos: { ...FOTOS, sem_alerta: null } });
    assert.doesNotMatch(semBotao, /sem alerta/, 'o botão nasceu com `sem_alerta` nulo');

    // ⚠️ E sem empresa/oferta/versão também não há handler — e sem handler, sem
    // botão: nunca uma escrita sobre versão que a tela não viu.
    for (const falta of [{ empresaId: null }, { ofertaId: null }, { versao: null }, { versao: 1.5 }]) {
        const h = render(M_COLUNA.default, { ...base, ...falta });
        assert.doesNotMatch(h, /Aprovar 2 sem alerta/, `o botão nasceu sem ${Object.keys(falta)[0]}`);
    }

    // ⚠️⚠️ A ESCRITA, NO CLIQUE — `renderToStaticMarkup` **não dispara `onClick`**,
    // e a TELA PRETA da 187-06 foi NO CLIQUE.
    CHAMADAS.lista.length = 0;
    const escritas = M_COLUNA.escritasDaColuna({ empresaId: 7, ofertaId: 12, versao: 3 });
    assert.equal(typeof escritas.aprovarSemAlerta, 'function', 'o handler de "sem alerta" não nasceu');
    escritas.aprovarSemAlerta();

    assert.equal(CHAMADAS.lista.length, 1, `esperava 1 escrita, houve ${CHAMADAS.lista.length}`);
    const [verbo, url, corpo] = CHAMADAS.lista[0];
    assert.equal(verbo, 'post', 'a escrita não foi um POST');
    assert.match(url, /mlb\.anuncios\.meus\.aprovacao\.painel\.fotos\.sem-alerta/, `a rota da escrita saiu errada: ${url}`);
    assert.match(url, /"company":7/, 'a empresa não entrou na rota');
    assert.match(url, /"oferta":12/, 'a oferta não entrou na rota');
    // ⚠️⚠️ COL-04 / critério 17: SEM `versao_esperada`, `exigirVersao()` recebe
    // `null` e **LIBERA** a escrita — "sobrescreve em silêncio".
    assert.equal(corpo.versao_esperada, 3, `\`versao_esperada\` não entrou no corpo: ${JSON.stringify(corpo)}`);

    // ⚠️ "Aprovar foto" — a outra escrita, e `{imagem}` é a linha de REVISÃO.
    CHAMADAS.lista.length = 0;
    assert.equal(escritas.aprovarImagem(901), true, 'o handler de "Aprovar foto" recusou um id válido');
    assert.equal(CHAMADAS.lista.length, 1, 'a escrita de "Aprovar foto" não saiu');
    assert.match(CHAMADAS.lista[0][1], /painel\.imagem\.aprovar/, 'a rota de "Aprovar foto" saiu errada');
    assert.match(CHAMADAS.lista[0][1], /"imagem":901/, 'o `{imagem}` não é o id da linha de REVISÃO');
    assert.equal(CHAMADAS.lista[0][2].versao_esperada, 3, '`versao_esperada` não entrou em "Aprovar foto"');

    // ⚠️ Id adverso **não escreve** e **não derruba**.
    CHAMADAS.lista.length = 0;
    for (const adverso of ADVERSOS) {
        assert.doesNotThrow(() => escritas.aprovarImagem(adverso), `aprovarImagem(${String(typeof adverso)}) derrubou`);
    }
    assert.equal(CHAMADAS.lista.length, 0, 'id adverso disparou escrita');

    // ⚠️ Sem versão, NADA é enviado.
    CHAMADAS.lista.length = 0;
    const sem = M_COLUNA.escritasDaColuna({ empresaId: 7, ofertaId: 12, versao: null });
    assert.equal(sem.aprovarSemAlerta, null, 'o handler nasceu sem versão');
    assert.equal(sem.aprovarImagem, null, 'o handler de foto nasceu sem versão');
    assert.equal(CHAMADAS.lista.length, 0, 'houve escrita sem versão');

    // ⚠️ E **nenhum** `router.post` direto nos quatro `.jsx` deste plano.
    const diretos = FONTES_DO_PLANO.filter(([, fonte]) => /router\.(post|put|delete)\(/.test(fonte)).map(([rel]) => rel);
    assert.deepEqual(diretos, [], '`router.post` direto — a escrita tem de ir pelo helper `escreverNoPainel`');
    assert.match(FONTE_COLUNA, /escreverNoPainel\(/, 'a coluna deixou de usar o helper único de escrita');
    // ⚠️ ISCA do recorte: o helper de verdade (no `PainelDeRevisao`) **usa**
    // `router.post`, e a sonda recortada aos meus arquivos NÃO o acusa.
    assert.match(lerSemComentarios(REL_PAINEL), /router\.post\(/, 'a isca do recorte sumiu: o helper deixou de usar `router.post`');
});

test('⚠️⚠️ PERGUNTA-188-D: o aviso FIXO das restantes, número do SERVIDOR, singular/plural, e NADA com 0 188_08', () => {
    const base = { fotos: FOTOS, live: true, selecionada: 0, onSelecionar: nada, empresaId: 7, ofertaId: 12, versao: 3 };

    // ⚠️⚠️ Decisão do usuário (19/10/2026): texto FIXO na coluna, **sem sétimo
    // toast**. A informação é ESTADO, não EVENTO — toast some em ~3,8 s.
    const um = render(M_COLUNA.default, base);
    assert.match(um, /1 foto ainda com alerta · confira uma a uma/, 'o aviso das restantes (singular) não saiu');
    assert.doesNotMatch(um, /1 fotos ainda/, 'o singular saiu no plural');

    const tres = render(M_COLUNA.default, { ...base, fotos: { ...FOTOS, com_alerta_restantes: 3 } });
    assert.match(tres, /3 fotos ainda com alerta · confira uma a uma/, 'o aviso das restantes (plural) não saiu');

    // ⚠️⚠️ O NÚMERO É O DO SERVIDOR, e **não** é recontado: com
    // `com_alerta_restantes = 3` a fixture tem **1** foto com alerta na lista.
    // Uma contagem na tela sairia "1".
    assert.equal(FOTOS.imagens.filter((i) => i.com_alerta).length, 1, 'a fixture deixou de discriminar contagem-na-tela × número-do-servidor');
    assert.doesNotMatch(tres, /1 foto ainda com alerta/, 'o aviso está CONTANDO na tela em vez de ler `com_alerta_restantes`');

    // ⚠️ Com 0 (ou dado adverso) o aviso **não nasce** — nada é decorativo.
    for (const valor of [0, -1, null, undefined, Number.NaN, '2', { toString: null }, []]) {
        const h = render(M_COLUNA.default, { ...base, fotos: { ...FOTOS, com_alerta_restantes: valor } });
        assert.doesNotMatch(h, /ainda com alerta/, `o aviso nasceu com com_alerta_restantes = ${String(typeof valor)}`);
    }
    // ⚠️ ISCA: o controle nasce. Sem ela o bloco acima seria vazio por construção.
    assert.match(render(M_COLUNA.default, { ...base, fotos: { ...FOTOS, com_alerta_restantes: 9 } }), /9 fotos ainda com alerta/, 'a sonda está cega: nem o controle desenha o aviso');

    // ⚠️ **Cor não é o único sinal**: o aviso leva GLIFO e TEXTO.
    const glifo = tagsDeAbertura(um).find((el) => classesDe(el).has('bg-pub-warn') && classesDe(el).has('rounded-full'));
    assert.ok(glifo, 'o aviso saiu só com cor — glifo e texto são obrigatórios');

    // ⚠️⚠️ E **NENHUM SÉTIMO TOAST**: o vocabulário travado segue com SEIS.
    const vocabulario = lerSemComentarios(REL_VOCABULARIO);
    const bloco = vocabulario.match(/export const TOASTS = \{([\s\S]*?)\n\};/);
    assert.ok(bloco, 'a leitura do literal de TOASTS está CEGA');
    const chaves = [...bloco[1].matchAll(/^\s{4}([a-z_]+):/gm)].map((m) => m[1]);
    assert.deepEqual(
        chaves.sort(),
        ['ajuste_solicitado', 'aprovado', 'foto_reprovada', 'gerando_com_ia', 'publicado', 'revisao_salva'],
        'VOC-08 deixou de ter SEIS toasts — a 7ª frase foi cunhada, e o usuário RECUSOU',
    );
    assert.doesNotMatch(vocabulario, /Fotos sem alerta aprovadas/, 'o par de toast do `:1151` entrou em VOC-08 — o usuário decidiu por texto FIXO');
    const comToast = FONTES_DO_PLANO.filter(([, fonte]) => /Fotos sem alerta aprovadas/.test(fonte)).map(([rel]) => rel);
    assert.deepEqual(comToast, [], 'a frase de toast do protótipo foi reproduzida — a decisão é texto FIXO, e NÃO as duas coisas');
});

test('⚠️ VAZIO HONESTO: galeria sem foto diz o vazio com literal TRAVADO, e sem botão para o editor 188_08', () => {
    const base = { live: true, selecionada: 0, onSelecionar: nada, empresaId: 7, ofertaId: 12, versao: 3 };
    const vazio = { ...FOTOS, total: 0, aprovadas: 0, pendentes: 0, com_alerta: 0, com_alerta_restantes: 0, imagens: [], selecionada: null, selecionada_indice: null, sem_alerta: null, nota_de_conjunto: null };
    const html = render(M_COLUNA.default, { ...base, fotos: vazio });

    // ⚠️ COMPOSIÇÃO de dois literais travados que já existem — nenhuma frase nova.
    assert.match(html, /Bloqueado: sem fotos/, 'o vazio honesto não saiu');
    assert.equal(M_COLUNA.VAZIO, 'Bloqueado: sem fotos', 'o literal do vazio deixou de ser a composição travada');

    // ⚠️ E **nenhum bloco com buraco**: nem imagem grande, nem grade, nem bloco da
    // selecionada.
    assert.doesNotMatch(html, /aspect-square/, 'a caixa 1:1 nasceu sem foto nenhuma');
    assert.doesNotMatch(html, /grid-cols-\[repeat\(6/, 'a grade de 6 nasceu vazia');
    assert.doesNotMatch(html, /VERIFICAÇÃO AUTOMÁTICA/, 'o bloco da selecionada nasceu sem foto');

    // ⚠️ Nenhum botão: "Adicionar fotos" leva ao EDITOR, território proibido.
    assert.doesNotMatch(html, /<button/, 'o vazio ganhou botão — "Adicionar fotos" leva ao editor (proibido)');
    assert.doesNotMatch(html, /Adicionar fotos/, 'o destino do editor foi desenhado');

    // ⚠️ `fotos` que NÃO é objeto → **nada**, nem casca de coluna (slot sem nó).
    // ⚠️⚠️ **O recorte é MEDIDO, não cosmético:** objeto comum **sem** `imagens` é
    // payload de galeria VAZIA, e aí o certo é o vazio honesto — não o silêncio. A
    // primeira redação deste bloco pedia `''` para os dois e **reprovava o
    // comportamento correto**: é a família "gate mais largo que o invariante".
    const NAO_OBJETOS = [null, undefined, Number.NaN, [], '', 'x', 0, 7, false, Symbol.iterator, [{ toString: null }]];
    for (const adverso of NAO_OBJETOS) {
        assert.equal(render(M_COLUNA.default, { ...base, fotos: adverso }), '', `fotos = ${String(typeof adverso)} desenhou casca`);
    }
    for (const bare of [{}, Object.create(null), { toString: null }]) {
        const h = render(M_COLUNA.default, { ...base, fotos: bare });
        assert.match(h, /Bloqueado: sem fotos/, 'objeto sem `imagens` devia cair no VAZIO HONESTO, não no silêncio');
        assert.doesNotMatch(h, /\[object Object\]|NaN|undefined/, 'objeto adverso vazou lixo para a tela');
    }
    // ⚠️ ISCA: o controle desenha.
    assert.notEqual(render(M_COLUNA.default, { ...base, fotos: FOTOS }), '', 'a sonda está cega: nem o controle desenha a coluna');
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. O RENDER ADVERSO, O CLIQUE E O ÍNDICE
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ ADVERSO: nenhum campo derruba a página — nem no render, nem no CLIQUE 188_08', () => {
    const CAMPOS = [
        'id', 'imagem_id', 'posicao', 'posicao_rotulo', 'rotulo', 'titulo', 'de_ia', 'tipo_rotulo',
        'principal', 'grupo', 'grupo_geral', 'estado', 'estado_rotulo', 'estado_cor', 'estado_dot',
        'com_alerta', 'aprovada', 'aceita_acao', 'borda', 'anel', 'url_arquivo', 'marcadores',
        'verificacoes',
    ];
    const RUIM = /\[object Object\]|NaN|undefined|Invalid Date/;

    for (const campo of CAMPOS) {
        for (const adverso of ADVERSOS) {
            const imagem = { ...FOTO_PENDENTE_COM_ALERTA, [campo]: adverso };
            const fotos = { ...FOTOS, imagens: [imagem], selecionada: imagem };
            let html = '';
            assert.doesNotThrow(() => {
                html = render(M_COLUNA.default, {
                    fotos, live: true, selecionada: 0, onSelecionar: nada, empresaId: 7, ofertaId: 12, versao: 3,
                });
            }, `${campo} = ${String(typeof adverso)} derrubou a página`);
            assert.doesNotMatch(html, RUIM, `${campo} = ${String(typeof adverso)} vazou lixo para a tela`);
        }
    }

    // ⚠️ Os campos de TOPO de `fotos`.
    for (const campo of ['progresso', 'progresso_cor', 'total', 'aprovadas', 'pendentes', 'com_alerta', 'com_alerta_restantes', 'nota_de_conjunto', 'imagens', 'selecionada', 'selecionada_indice', 'sem_alerta']) {
        for (const adverso of ADVERSOS) {
            let html = '';
            assert.doesNotThrow(() => {
                html = render(M_COLUNA.default, {
                    fotos: { ...FOTOS, [campo]: adverso }, live: true, selecionada: 0, onSelecionar: nada,
                    empresaId: 7, ofertaId: 12, versao: 3,
                });
            }, `fotos.${campo} = ${String(typeof adverso)} derrubou a página`);
            assert.doesNotMatch(html, RUIM, `fotos.${campo} = ${String(typeof adverso)} vazou lixo`);
        }
    }

    // ⚠️ E as PROPS da coluna, inclusive a prop inteira nula.
    for (const adverso of ADVERSOS) {
        assert.doesNotThrow(() => render(M_COLUNA.default, {
            fotos: FOTOS, live: adverso, selecionada: adverso, onSelecionar: adverso,
            empresaId: adverso, ofertaId: adverso, versao: adverso, onReprovar: adverso, onConflito: adverso,
        }), `props adversas (${String(typeof adverso)}) derrubaram a página`);
    }
    assert.doesNotThrow(() => render(M_COLUNA.default, {}), 'a coluna sem nenhuma prop derrubou a página');

    // ⚠️⚠️ E O CLIQUE: `renderToStaticMarkup` NÃO dispara `onClick`, então os
    // handlers são exercidos DIRETO. A tela preta da 187-06 foi no clique.
    CHAMADAS.lista.length = 0;
    const cliques = [];
    const htmlGrade = render(M_GRADE.default, { imagens: IMAGENS, selecionada: 0, onSelecionar: (i) => cliques.push(i) });
    assert.match(htmlGrade, /<button/, 'a grade perdeu os botões — o clique mediria zero casos');

    // O handler da grade, exercido pela MESMA forma que o `onClick` usa.
    for (const adverso of ADVERSOS) {
        assert.doesNotThrow(() => {
            const escritas = M_COLUNA.escritasDaColuna(adverso);
            if (escritas.aprovarSemAlerta !== null) escritas.aprovarSemAlerta();
            if (escritas.aprovarImagem !== null) escritas.aprovarImagem(adverso);
        }, `escritasDaColuna(${String(typeof adverso)}) derrubou no clique`);
    }
    assert.doesNotThrow(() => M_COLUNA.escritasDaColuna(), 'escritasDaColuna() sem argumento derrubou');
});

test('⚠️ ÍNDICE: clampado nas DUAS pontas, e `null` reservado para GALERIA VAZIA 188_08', () => {
    const { indiceSelecionado } = M_COLUNA;

    assert.equal(indiceSelecionado(2, 4), 2, 'o índice válido não passou');
    assert.equal(indiceSelecionado(0, 4), 0, 'o índice 0 não passou');
    assert.equal(indiceSelecionado(3, 4), 3, 'a última ponta não passou');

    // ⚠️⚠️ Fora da faixa cai no `0`, e **não** em `null`: `null` é reservado para
    // *"galeria vazia"*, e usá-lo aqui faria a coluna dizer o vazio COM fotos na
    // grade — a tela afirmando o que não é. (Divergência declarada do `:1293`,
    // que clampa só o teto.)
    for (const pedido of [4, 99, -1, -99, 1.5, Number.NaN, null, undefined, '2', { toString: null }, []]) {
        assert.equal(indiceSelecionado(pedido, 4), 0, `${String(pedido && typeof pedido)} não caiu no 0`);
    }

    // ⚠️ Galeria vazia → `null`, e é ele que acende o vazio honesto.
    for (const quantas of [0, -1, null, undefined, Number.NaN, '4', []]) {
        assert.equal(indiceSelecionado(1, quantas), null, `quantas = ${String(typeof quantas)} não devolveu null`);
    }

    // ⚠️ E a TELA respeita: índice fora da faixa desenha a foto 1, não o vazio.
    const html = render(M_COLUNA.default, {
        fotos: FOTOS, live: true, selecionada: 99, onSelecionar: nada, empresaId: 7, ofertaId: 12, versao: 3,
    });
    assert.match(html, /#1 · Capa/, 'índice fora da faixa não caiu na foto 1');
    assert.doesNotMatch(html, /Bloqueado: sem fotos/, 'índice fora da faixa acendeu o VAZIO com fotos na grade');
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. TERRITÓRIO, VOCABULÁRIO E AS LISTAS DA FASE 187
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ TERRITÓRIO: nenhum `localStorage`, nenhum `String(`, nenhuma PEÇA nova em `.js`, nenhum hex 188_08', () => {
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        assert.doesNotMatch(fonte, /localStorage/, `${rel}: \`localStorage\` é artefato do protótipo (\`:914\`)`);
        assert.doesNotMatch(fonte, /Restaurar dados de exemplo/, `${rel}: "Restaurar dados de exemplo" é do protótipo`);
        // ⚠️ `String(valor)` **LANÇA** com `{toString: null}` — há gate da 187-04
        // proibindo-o na página, e está CERTO.
        assert.doesNotMatch(fonte, /\bString\(/, `${rel}: \`String(\` LANÇA com {toString:null}`);
        assert.doesNotMatch(fonte, /JSON\.stringify/, `${rel}: \`JSON.stringify\` na mensagem LANÇA com ciclo`);
        // ⚠️ TOK-15 / FID-04.
        assert.doesNotMatch(fonte, /#[0-9a-fA-F]{3,8}\b/, `${rel}: hex literal — a cor vem por NOME de token`);
        assert.doesNotMatch(fonte, /\becf-(bg|card|card-2|yellow|yellow-2|line|dim|mute)\b/, `${rel}: token `);
        assert.doesNotMatch(fonte, /\bfont-display\b/, `${rel}: \`font-display\` é FID-04`);
        assert.doesNotMatch(fonte, /border-white\/\[0\.08\]/, `${rel}: \`border-white/[0.08]\` é FID-04`);
        // ⚠️ FID-03: a escala nomeada arredonda; `text-[11.5px]` não tem par nela.
        assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/, `${rel}: escala nomeada de texto (FID-03)`);
        assert.doesNotMatch(fonte, /\b(sm|md|lg|xl|2xl):/, `${rel}: breakpoint novo (FID-05)`);
    }

    // ⚠️ ISCA do recorte: a sonda de hex NÃO cobre o projeto inteiro, e o
    // `tailwind.config.js` — a ÚNICA casa dos hex — continua tendo o `#FACC15`.
    assert.match(fs.readFileSync(path.resolve(RAIZ, 'tailwind.config.js'), 'utf8'), /#FACC15/, 'a isca do recorte sumiu: a camada de tokens perdeu o hex');

    // ⚠️⚠️ `tailwind.config.js` varre `*.jsx` e **NÃO** `*.js`: classe num `.js`
    // não gera CSS e **não existe para a tela**, com render verde.
    // ⚠️ O invariante é "nenhuma PEÇA nova é `.js`" — a 187-11 mediu que *"nenhum
    // arquivo novo é `.js`"* era literalmente FALSO (9 `.js`, todos TESTES).
    const pecasEmJs = FONTES_DO_PLANO.filter(([rel]) => rel.startsWith('resources/') && rel.endsWith('.js')).map(([rel]) => rel);
    assert.deepEqual(pecasEmJs, [], 'peça nova sob `resources/` em `.js` — a classe não geraria CSS');
    assert.equal(FONTES_DO_PLANO.length, 4, 'o recorte deixou de ter os QUATRO arquivos do plano');

    // ⚠️ VOC-03 — o nome do número. "lucro líquido" nunca vai para a tela.
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        assert.doesNotMatch(fonte, /lucro l[íi]quido/i, `${rel}: "lucro líquido" — o vocabulário travado é "Resultado estimado por venda"`);
    }

    // ⚠️ NADA de provisório das waves 188-09/10/11/12.
    const completo = render(M_COLUNA.default, { fotos: FOTOS, live: true, selecionada: 0, onSelecionar: nada, empresaId: 7, ofertaId: 12, versao: 3 });
    for (const forasteiro of ['Resultado estimado por venda', 'Margem de contribuição', 'Solicitar ajustes', 'Solicitar exceção', 'Informar frete', 'Aprovar e publicar']) {
        assert.ok(!completo.includes(forasteiro), `"${forasteiro}" é de OUTRA wave e apareceu na coluna de fotos`);
    }
});

test('⚠️⚠️ INTOCÁVEIS e as LISTAS da Fase 187: os quatro `.jsx` DECLARADOS nos dois lugares 188_08', () => {
    // ⚠️ `git diff --numstat` por ESCOPO DO ASSUNTO: "prova por RANGE" não passa
    // nesta árvore (a 187-11 mediu 43 commits de sessão paralela em 111).
    // ⚠️ `test -e` ANTES, para a sonda não medir fantasma.
    const INTOCAVEIS = [
        REL_QUADRADOS, REL_VOCABULARIO, REL_CASCA_MODAL,
        `${DIR_APROV}/andamentoDoPortao.jsx`, `${DIR_APROV}/SeloDeAndamentoInterno.jsx`,
        `${DIR_APROV}/ToastDaFila.jsx`,
        'resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx',
        'resources/js/Layouts/AppLayout.jsx',
        'tests/js/publicador-header-zindex-ordem.test.js',
        'tests/js/estrutura-meus-anuncios.test.js',
    ];
    const ausentes = INTOCAVEIS.filter((rel) => !fs.existsSync(path.resolve(RAIZ, rel)));
    assert.deepEqual(ausentes, [], 'INTOCÁVEL inexistente — a sonda mediria fantasma');

    // ⚠️ E nenhum deles é importado pelos meus quatro arquivos, EXCETO o
    // `vocabularioDoPortao` (literal travado) — reusar não é editar.
    const importamIntocavel = FONTES_DO_PLANO
        .filter(([, fonte]) => /QuadradosDeFoto|CascaDeModal|ToastDaFila|AppLayout|PublicadorHeader/.test(fonte))
        .map(([rel]) => rel);
    assert.deepEqual(importamIntocavel, [], 'arquivo deste plano encostou num INTOCÁVEL');

    // ⚠️⚠️ AS DUAS LISTAS CONSTANTES DA 187 — declarar numa e esquecer a outra
    // REPROVA, e é esse o comportamento certo.
    const MEUS = ['ImagemGrandeDoPainel.jsx', 'GradeDeMiniaturas.jsx', 'BlocoDaImagemSelecionada.jsx', 'ColunaDeFotos.jsx'];

    const gateVocabulario = lerSemComentarios(REL_GATE_VOCABULARIO);
    const blocoVocabulario = gateVocabulario.match(/const\s+ARQUIVOS_NOVOS\s*=\s*\[([\s\S]*?)\n\];/);
    assert.ok(blocoVocabulario, 'a leitura de ARQUIVOS_NOVOS está CEGA');
    const declaradosVocabulario = [...blocoVocabulario[1].matchAll(/\$\{DIR_APROV\}\/([\w.-]+\.jsx)/g)].map((m) => m[1]);
    assert.ok(declaradosVocabulario.length > 0, 'ARQUIVOS_NOVOS não declara nenhum .jsx de aprovacao/ — leitura cega');
    assert.deepEqual(MEUS.filter((n) => !declaradosVocabulario.includes(n)), [], 'arquivo deste plano FORA de ARQUIVOS_NOVOS — ele fica sem conferência de vocabulário (GATE-03 passaria por omissão)');

    const gateFechamento = lerSemComentarios(REL_GATE_FECHAMENTO);
    const blocoFechamento = gateFechamento.match(/const\s+JSX_DEPOIS_DA_187\s*=\s*\[([\s\S]*?)\n\];/);
    assert.ok(blocoFechamento, 'a leitura de JSX_DEPOIS_DA_187 está CEGA');
    const declaradosFechamento = [...blocoFechamento[1].matchAll(/\$\{DIR_APROV\}\/([\w.-]+\.jsx)/g)].map((m) => m[1]);
    assert.ok(declaradosFechamento.length > 0, 'JSX_DEPOIS_DA_187 saiu vazia — leitura cega');
    assert.deepEqual(MEUS.filter((n) => !declaradosFechamento.includes(n)), [], 'arquivo deste plano FORA de JSX_DEPOIS_DA_187');

    // ⚠️ ISCA das duas leituras: um nome que eu SEI que não está nelas.
    assert.ok(!declaradosVocabulario.includes('NaoExiste8k3.jsx'), 'a isca da leitura de ARQUIVOS_NOVOS virou declaração de verdade');
    assert.ok(!declaradosFechamento.includes('NaoExiste8k3.jsx'), 'a isca da leitura de JSX_DEPOIS_DA_187 virou declaração de verdade');

    // ⚠️ `SLOTS_DE_NO_DEPOIS_DA_187` e `QUEM_USA_SHADOW_PUB_PAINEL` **não** mudam
    // neste plano, e isso é MEDIDO: a coluna entra pelo slot `colunaDeFotos`, que
    // já existe desde o 188-07, e nenhum `.jsx` meu escreve `shadow-pub-painel`.
    const comSombra = FONTES_DO_PLANO.filter(([, fonte]) => /shadow-pub-painel/.test(fonte)).map(([rel]) => rel);
    assert.deepEqual(comSombra, [], 'arquivo deste plano usou `shadow-pub-painel` sem entrar em QUEM_USA_SHADOW_PUB_PAINEL');
    const slotsNovos = [...FONTE_PAGINA.matchAll(/isValidElement\(\s*([A-Za-z_$][\w$]*)\s*\)/g)].map((m) => m[1]);
    assert.ok(slotsNovos.length > 0, 'a leitura dos slots de NÓ da página está CEGA');
    assert.ok(!slotsNovos.includes('colunaDeFotos'), 'a coluna abriu um SLOT DE NÓ novo na página — declare em SLOTS_DE_NO_DEPOIS_DA_187, nos TRÊS lugares');

    // ⚠️ A página liga o slot e a seleção VIVA.
    assert.match(FONTE_PAGINA, /colunaDeFotos=\{colunaDeFotos\}/, 'a página não ligou o slot `colunaDeFotos`');
    assert.match(FONTE_PAGINA, /onTrocarImagem=\{trocarFoto\}/, 'a página não ligou a troca de imagem (as setas ←/→ do 188-07)');
    assert.match(FONTE_PAGINA, /rodapeDoPainel\?\.botoes|objetoSeguro\(rodapeDoPainel\?\.botoes\)/, 'a página deixou de ler o `live` de `rodape.botoes.salvar`');
});

test('⚠️ MANIFESTO: este arquivo está declarado em `contagens-188-08.txt`, e os nomes são ÚNICOS 188_08', () => {
    const manifesto = fs.readFileSync(path.resolve(RAIZ, REL_MANIFESTO), 'utf8');
    const linhas = manifesto.split(/\r?\n/).filter((l) => l.trim() !== '' && !l.trim().startsWith('#'));

    assert.ok(linhas.length > 0, 'o manifesto não tem nenhuma linha de gate');
    assert.ok(linhas.some((l) => l.includes('tests/js/publicador-painel-fotos-188.test.js')), 'este arquivo de gate NÃO está no manifesto — ele nunca rodaria');
    // ⚠️ `vocabulario-tamanhos` em LINHA PRÓPRIA, forma `>=`: a contagem CRESCE por
    // construção (cada `.jsx` novo a aumenta), e embuti-lo num id composto fez a
    // wave 187-05 reprovar DOIS ids de outras waves.
    assert.ok(linhas.some((l) => /^vocabulario-tamanhos\s+js\s+tests\/js\/publicador-vocabulario-novo\.test\.js\s+::\s+tests=>=\d+$/.test(l.trim())), 'o `vocabulario-tamanhos` não está em LINHA PRÓPRIA com a forma `>=` e N medido');

    // ⚠️ Nenhum id repetido (o harness pega o PRIMEIRO).
    const ids = linhas.map((l) => l.trim().split(/\s+/)[0]);
    assert.deepEqual(ids.filter((id, i) => ids.indexOf(id) !== i), [], 'id repetido no manifesto');

    // ⚠️⚠️ HOMÔNIMO: o `--filter` do PHPUnit é regex NÃO ANCORADO, mas ele só
    // existe no modo `php` — e este manifesto **não tem id `php`**. ➡️ O que se
    // prova aqui é o que é VERDADE: nenhum nome de teste deste arquivo colide com
    // nome de teste de outro arquivo, inclusive por PREFIXO.
    // ⚠️ A 188-07 mediu que a própria sonda dela acusou 82 homônimos FALSOS por ser
    // mais LARGA que o invariante. E a 188-05 mediu o furo do
    // `/test_[A-Za-z0-9_]+/`, que **não casa a alternância `test_(a|b|c)`** que
    // todos os manifestos desta fase usam.
    assert.deepEqual(linhas.filter((l) => /\sphp\s/.test(l)), [], 'este manifesto passou a ter id `php` — a sonda de homônimo acima precisa virar a de `--filter`');

    const meus = [...fs.readFileSync(path.resolve(RAIZ, 'tests/js/publicador-painel-fotos-188.test.js'), 'utf8')
        .matchAll(/^test\('([^']+)'/gm)].map((m) => m[1]);
    assert.ok(meus.length > 0, 'a leitura dos nomes de teste deste arquivo está CEGA');
    assert.deepEqual(meus.filter((n) => !n.endsWith('188_08')), [], 'nome de teste sem o sufixo `188_08` — nome genérico COLIDE e o `--filter` SOMA');

    // ⚠️ E a alternância do manifesto é expandida ANTES de comparar — o furo
    // medido pela 188-05.
    const EXPANDE = (filtro) => {
        const m = filtro.match(/^test_\(([^)]*)\)(.*)$/);
        return m ? m[1].split('|').map((parte) => `test_${parte}${m[2]}`) : [filtro];
    };
    assert.deepEqual(EXPANDE('test_(a|b)_188_08'), ['test_a_188_08', 'test_b_188_08'], 'o expansor de alternância está furado — é o 4º "furo na própria sonda" desta fase');
    assert.deepEqual(EXPANDE('test_simples'), ['test_simples'], 'o expansor quebrou o caso sem alternância');
});

// ═══════════════════════════════════════════════════════════════════════════
// 9. O PROTÓTIPO — `skip` EXPLÍCITO quando o pacote não está no disco
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ FID: os literais e os ponteiros medidos contra o protótipo 188_08', async (contexto) => {
    const abs = path.resolve(RAIZ, PROTOTIPO);
    if (!fs.existsSync(abs)) {
        // ⚠️ `skip` com razão ESCRITA (e `skipped N` no sumário), nunca `return`
        // calado: `design_handoff_publicador/` NÃO é versionado.
        contexto.skip(`${PROTOTIPO} ausente — o pacote de handoff não é versionado`);

        return;
    }

    // ⚠️ A última entrada do `split` é a string vazia depois do `\n` final: sem
    // retirá-la a contagem sai **1433** e a asserção reprovaria por um motivo que
    // **não é o arquivo** (o `wc -l` mede 1432). Medido nesta wave.
    const linhas = fs.readFileSync(abs, 'utf8').split(/\r?\n/);
    if (linhas[linhas.length - 1] === '') linhas.pop();
    const em = (n) => linhas[n - 1] ?? '';

    await contexto.test('o protótipo é o MESMO (1432 linhas) e os ponteiros batem', () => {
        assert.equal(linhas.length, 1432, `o protótipo mudou de tamanho: ${linhas.length} linhas`);

        // ⚠️⚠️ OS PONTEIROS DO PLANO ESTAVAM DESLOCADOS, e a `188-FID01.md` §C já
        // os corrigiu: C12/C13/C14 são `:605`/`:606`/`:607`, e o `:608` do plano é
        // um `</span>` PURO.
        assert.match(em(605), /Gerando nova versão…/, '`:605` não é o "Gerando nova versão…"');
        assert.match(em(606), /Gerar novamente com IA/, '`:606` não é o "✦ Gerar novamente com IA" (Fase 192)');
        assert.match(em(607), /⤢ Ampliar/, '`:607` não é o "⤢ Ampliar" (Fase 189)');
        assert.match(em(608), /^\s*<\/span>\s*$/, '`:608` deixou de ser um `</span>` puro — era o ponteiro do PLANO');

        // ⚠️ `hasSafe`/`safeLabel` em `:1305` (o plano dizia `:1311`), e `:1311` é o
        // `selActions` com o `isPending && inf.live` (o plano dizia `:1314`).
        assert.match(em(1305), /hasSafe|safeLabel/, '`:1305` não é o `hasSafe`/`safeLabel`');
        assert.match(em(1311), /selActions/, '`:1311` não é o `selActions`');
        assert.match(em(1314), /const S = this\.state/, '`:1314` não é o `const S = this.state` — era o ponteiro do PLANO');
        // ⚠️ O `a.n` do item de verificação está em `:1108` (o plano dizia `:1111`).
        assert.match(em(1108), /autos:/, '`:1108` não é o `autos` com o `a.n`');
        assert.match(em(1111), /refSlot|hasRef/, '`:1111` não é o `ref`/`refSlot` — era o ponteiro do PLANO');
        // ⚠️ E `:1151` CONFIRMADO: o par de toast do `approveSafe`.
        assert.match(em(1151), /approveSafe/, '`:1151` não é o `approveSafe`');
        assert.match(em(1151), /Fotos sem alerta aprovadas/, '`:1151` perdeu o par de toast');
    });

    await contexto.test('os literais que a tela reproduz saem do protótipo, byte a byte', () => {
        assert.match(em(369), /VERIFICAÇÃO AUTOMÁTICA · APOIO/, '`:369` perdeu o literal do cabeçalho');
        assert.match(em(369), /letter-spacing:0\.08em/, '`:369` perdeu o `letter-spacing:0.08em`');
        assert.match(em(369), /font-size:11px/, '`:369` perdeu o `font-size:11px`');
        assert.match(em(589), />Fotos</, '`:589` perdeu o "Fotos"');
        assert.match(em(599), /★ PRINCIPAL/, '`:599` perdeu o "★ PRINCIPAL"');
        assert.match(em(600), /Gerada por IA/, '`:600` perdeu o "Gerada por IA"');
        assert.match(em(601), />Original</, '`:601` perdeu o "Original"');
        assert.match(em(610), /repeat\(6,minmax\(0,1fr\)\)/, '`:610` perdeu o `repeat(6,minmax(0,1fr))`');
        assert.match(em(626), />Reprovar</, '`:626` perdeu o "Reprovar"');
        assert.match(em(627), />Aprovar foto</, '`:627` perdeu o "Aprovar foto"');

        // ⚠️ O brilho do marcador é FIXO no protótipo (não muda com a severidade).
        assert.match(em(596), /box-shadow:0 0 0 6px rgba\(239,68,68,0\.18\)/, '`:596` perdeu o brilho FIXO do marcador');
        // ⚠️ E a duração do spinner é `.8s` — o token do projeto é `.7s`.
        assert.match(em(605), /animation:spin \.8s/, '`:605` perdeu o `animation:spin .8s`');
    });

    await contexto.test('⚠️ as três propriedades que a tabela esquece: `cursor`, `white-space` e `text-overflow`', () => {
        // ⚠️ A `188-FID01.md` erra por OMISSÃO: 11 das 33 divergências medidas.
        // Aqui elas são conferidas **coluna por coluna**, no protótipo.
        const comCursor = [590, 606, 607, 614, 626, 627];
        const semCursor = [589, 592, 594, 596, 597, 598, 599, 600, 601, 603, 605, 610, 612, 615, 616, 617, 621, 623, 630, 633];
        assert.deepEqual(comCursor.filter((n) => !/cursor:pointer/.test(em(n))), [], 'linha que DEVE ter `cursor:pointer` não tem');
        assert.deepEqual(semCursor.filter((n) => /cursor:pointer/.test(em(n))), [], 'linha que NÃO deve ter `cursor:pointer` tem');

        const comNowrap = [590, 598, 599, 600, 601, 603, 605, 606, 607, 623, 626, 627, 630];
        assert.deepEqual(comNowrap.filter((n) => !/white-space:nowrap/.test(em(n))), [], 'linha que DEVE ter `white-space:nowrap` não tem — foi omitido SETE vezes na Fase 187');
        // ⚠️ `:589` NÃO tem `nowrap` (a tabela dizia que tinha — divergência de VALOR
        // já corrigida), e `:597` também não (o `nowrap` está nos FILHOS).
        assert.deepEqual([589, 597].filter((n) => /white-space:nowrap/.test(em(n))), [], '`:589`/`:597` ganharam `nowrap` — a correção de VALOR da §C se perdeu');

        assert.match(em(623), /text-overflow:ellipsis/, '`:623` perdeu o `text-overflow:ellipsis`');
        assert.match(em(623), /min-width:0/, '`:623` perdeu o wrapper `min-width:0` — sem ele o ellipsis NÃO funciona');
        assert.deepEqual([589, 590, 598, 626].filter((n) => /text-overflow/.test(em(n))), [], 'linha que não tem `text-overflow` ganhou um');

        // ⚠️ O wrapper de `:604`, que o documento OMITIA (C11w).
        assert.match(em(604), /right:10px;bottom:10px/, '`:604` perdeu o `right:10px;bottom:10px` — sem ele os botões do overlay não têm posição');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 10. O CSS CONSTRUÍDO — com as OITO normalizações e as iscas MEDIDAS
// ═══════════════════════════════════════════════════════════════════════════

/** Escapa TODOS os metacaracteres de regex. */
function escapaRegex(texto_) {
    return texto_.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * O seletor que o Tailwind emite. ⚠️ A 8ª normalização: o escape é **GERAL**
 * (tudo que não é `[A-Za-z0-9_-]`), senão classe com `(`/`,` sai AUSENTE estando
 * presente — é o caso de `grid-cols-[repeat(6,minmax(0,1fr))]`.
 */
function seletorDe(classe) {
    return `.${[...classe].map((ch) => (/[A-Za-z0-9_-]/.test(ch) ? ch : `\\${ch}`)).join('')}`;
}

/**
 * ⚠️ É esta guarda de `mtime` que produz os DOIS REGIMES de contagem do
 * manifesto: mutar uma fonte a deixa mais nova que o CSS e a camada cai em
 * `skip`. ⚠️ E `cp` ATUALIZA o `mtime`, então a restauração da mutação também
 * derruba a camada — por isso `npm run build` vem DEPOIS da mutação.
 */
const FONTES_DO_CSS = [REL_IMAGEM, REL_GRADE, REL_BLOCO, REL_COLUNA];

function carregarCssConstruido() {
    const manifestRel = 'public/build/manifest.json';
    const manifestAbs = path.resolve(RAIZ, manifestRel);
    if (!fs.existsSync(manifestAbs)) return { motivo: `${manifestRel} ausente — rode \`npm run build\`` };

    // ⚠️ Manifest SEMPRE pelo JSON, nunca por grep: o hash sai com HÍFEN e o grep
    // ingênuo não casa.
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

test('⚠️ FID: as classes da coluna de fotos GERAM CSS com o valor certo 188_08', async (contexto) => {
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
        assert.ok(tem(REL_PAGINA), 'a página da fila saiu do manifest — a tela desaparece sem erro');
        assert.equal(tem('resources/js/Pages/Mlb/NaoExiste8k3.jsx'), false, 'a isca do manifest virou entrada de verdade');
    });

    await contexto.test('⚠️ ISCA: a sonda reporta AUSENTE o que não existe, e ACHA o que existe', () => {
        // ⚠️ Isca tem de ser classe inexistente no projeto INTEIRO — MEDIDAS nesta
        // sessão contra o CSS de verdade. ⚠️⚠️ `grid-cols-6` e `left-[37.5%]`
        // PARECIAM inventadas e saíram PRESENTES: a primeira tem outros donos, e a
        // segunda **foi gerada pelo meu próprio COMENTÁRIO** (o scanner do Tailwind
        // lê comentário também) — achado desta wave.
        for (const isca of ['bg-pub-isca-188-08', 'text-[97.5px]', 'w-[1993px]', 'top-[62.5%]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS.css.includes(isca), `a string da isca ${isca} aparece no CSS`);
        }
        assert.notEqual(regraDe('text-[12px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('⚠️⚠️ `duration-[Nms]` continua NÃO COMPILANDO, e `duration-200` compila', () => {
        assert.equal(regraDe('duration-[180ms]'), null, '`duration-[180ms]` passou a compilar — remeça a regra');
        assert.match(regraDe('duration-200'), /transition-duration:\s*\.?2s/, 'o CONTROLE da escala nomeada sumiu');
    });

    await contexto.test('⚠️⚠️ a GEOMETRIA da coluna, com as OITO normalizações', () => {
        // ⚠️ 8ª — o escape GERAL: sem ele esta sairia AUSENTE estando presente.
        assert.match(regraDe('grid-cols-[repeat(6,minmax(0,1fr))]'), /repeat\(6\s*,\s*minmax\(0\s*,\s*1fr\)\)/, 'a grade de 6 de `:610` não compilou');

        // ⚠️ 1ª/2ª — token SÓLIDO não compila para hex: sai `rgb(R G B / var(...))`.
        assert.match(regraDe('bg-pub-hover-2'), /rgb\(22 22 22/, '`bg-pub-hover-2` não é #161616 (o fundo de `:594`/`:612`)');
        assert.match(regraDe('bg-pub-sunken'), /rgb\(13 13 13/, '`bg-pub-sunken` não é #0D0D0D (o bloco de `:621`)');
        assert.match(regraDe('border-pub-line-subtle'), /rgb\(31 31 31/, '`border-pub-line-subtle` não é #1F1F1F');
        assert.match(regraDe('border-pub-accent'), /rgb\(250 204 21/, '`border-pub-accent` não é #FACC15 (a borda da selecionada)');
        assert.match(regraDe('border-pub-danger'), /rgb\(239 68 68/, '`border-pub-danger` não é #EF4444');
        assert.match(regraDe('border-pub-ok'), /rgb\(34 197 94/, '`border-pub-ok` não é #22C55E');
        assert.match(regraDe('border-pub-warn'), /rgb\(245 158 11/, '`border-pub-warn` não é #F59E0B');
        assert.match(regraDe('border-pub-line-off'), /rgb\(63 63 70/, '`border-pub-line-off` não é #3F3F46 — o 5º ramo da borda');

        // ⚠️ 3ª — o modificador de opacidade muda de FORMA conforme a PROPRIEDADE:
        // em `background-color` sai **hex de 8 dígitos**, e o byte do alfa PROVA a
        // opacidade. `0.8` → `0xcc`; `0.85` → `0xd9`.
        assert.match(regraDe('bg-pub-page/[0.8]'), /#0a0a0acc/, 'o fundo dos selos de `:598`-`:603` não é `rgba(10,10,10,0.8)`');
        assert.match(regraDe('bg-pub-page/[0.85]'), /#0a0a0ad9/, 'o fundo de `:605`/`:615` não é `rgba(10,10,10,0.85)`');
        assert.match(regraDe('bg-pub-ok/[0.14]'), /#22c55e24/, 'o glifo `ok` de `:633` não é `rgba(34,197,94,0.14)`');
        assert.match(regraDe('bg-pub-danger/[0.16]'), /#ef444429|#ef444428/, 'o glifo de severidade alta de `:633` não é `rgba(239,68,68,0.16)`');
        assert.match(regraDe('bg-pub-warn/[0.16]'), /#f59e0b29|#f59e0b28/, 'o glifo de severidade média de `:633` não é `rgba(245,158,11,0.16)`');
        assert.match(regraDe('border-pub-danger/[0.45]'), /#ef444473/, 'a borda de "Reprovar" (`:626`) não é `rgba(239,68,68,0.45)`');

        // ⚠️⚠️ O ANEL de `:1113` (`0 0 0 2px #FACC15`) sai por `ring-2` +
        // `ring-pub-accent`, e NÃO por `shadow-[…#FACC15]`: TOK-15 proíbe hex na
        // tela. O `ring` do Tailwind emite exatamente o mesmo valor com o offset
        // default de 0px.
        assert.match(regraDe('ring-2'), /0 0 0 calc\(2px \+ var\(--tw-ring-offset-width\)\)/, 'o `ring-2` deixou de emitir o spread de 2px');
        assert.match(regraDe('ring-pub-accent'), /rgb\(250 204 21/, 'o `ring-pub-accent` não é #FACC15');

        // ⚠️ O brilho FIXO do marcador de `:596` — o cssnano come o zero da frente
        // (4ª normalização): `0.18` sai `.18`.
        assert.match(regraDe('shadow-[0_0_0_6px_rgba(239,68,68,0.18)]'), /0 0 0 6px rgba\(239,68,68,\.18\)/, 'o brilho do marcador de `:596` não compilou');

        // ⚠️ `font-[800]` — o GATE-03 proíbe `font-extrabold`, e o arbitrário
        // entrega o MESMO peso computado (precedente de `AnelDeProgresso.jsx`).
        assert.match(regraDe('font-[800]'), /font-weight:\s*800/, '`font-[800]` não compilou — os glifos sairiam com o peso herdado');

        // ⚠️ 4ª — o zero da frente: `0.08em` sai `.08em`, e `1.4` fica `1.4`.
        assert.match(regraDe('tracking-[0.08em]'), /\.08em/, 'o `letter-spacing:0.08em` de `:369` não compilou');
        assert.match(regraDe('leading-[1.4]'), /line-height:\s*1\.4/, 'o `line-height:1.4` de `:633` não compilou');
        assert.match(regraDe('-mt-\[6px\]'.replace(/\\/g, '')), /margin-top:\s*-6px/, 'o `margin-top:-6px` de `:592` não compilou');

        // ⚠️ `truncate` é EXATAMENTE os três de `:623`.
        assert.match(regraDe('truncate'), /overflow:hidden/, '`truncate` perdeu o `overflow:hidden`');
        assert.match(regraDe('truncate'), /text-overflow:ellipsis/, '`truncate` perdeu o `text-overflow:ellipsis`');
        assert.match(regraDe('truncate'), /white-space:nowrap/, '`truncate` perdeu o `white-space:nowrap` — omitido SETE vezes na Fase 187');

        // ⚠️ `animate-pub-spin` é `.7s` e o protótipo é `.8s` — MEDIDO, e por isso a
        // duração entra por `style`.
        assert.match(regraDe('animate-pub-spin'), /animation:\s*spin \.7s linear infinite/, 'o token `animate-pub-spin` mudou de valor — remeça a divergência do `:605`');

        // ⚠️ 6ª — na VARIANTE o prefixo faz parte do nome.
        assert.match(regraDe('hover:border-pub-line-off'), /rgb\(63 63 70/, 'o hover de `:590` não compilou');

        // O resto das medidas, numa asserção COLETANDO.
        const semRegra = [
            'aspect-square', 'rounded-pub-sub', 'rounded-pub-chip', 'rounded-pub-badge',
            'rounded-pub-control-sm', 'rounded-[8px]', 'rounded-full', 'overflow-hidden',
            'object-contain', 'object-cover', 'pointer-events-none', 'place-items-center',
            'h-[24px]', 'w-[24px]', '-translate-x-1/2', '-translate-y-1/2', 'border-2',
            'border-[1.5px]', 'border-r-transparent', 'border-none', 'bg-transparent',
            'left-[10px]', 'top-[10px]', 'right-[10px]', 'bottom-[10px]',
            'left-[4px]', 'top-[4px]', 'right-[4px]', 'bottom-[4px]',
            'h-[22px]', 'h-[16px]', 'w-[16px]', 'h-[6px]', 'w-[6px]', 'h-[10px]', 'w-[10px]',
            'h-[28px]', 'h-[30px]', 'gap-[2px]', 'gap-[3px]', 'gap-[5px]', 'gap-[6px]',
            'gap-[7px]', 'gap-[8px]', 'gap-[10px]', 'px-[5px]', 'px-[8px]', 'px-[10px]',
            'px-[11px]', 'px-[12px]', 'px-[14px]', 'py-[10px]', 'py-[12px]',
            'text-[9.5px]', 'text-[10px]', 'text-[11px]', 'text-[11.5px]', 'text-[12.5px]',
            'text-[13px]', 'text-[15px]', 'font-medium', 'font-semibold', 'font-bold',
            'items-baseline', 'items-start', 'items-center', 'justify-between', 'min-w-0',
            'flex-none', 'flex-col', 'inset-0', 'p-0', 'cursor-pointer',
            'text-pub-txt', 'text-pub-txt-2', 'text-pub-txt-3', 'text-pub-txt-4', 'text-pub-txt-off',
            'text-pub-page', 'text-pub-accent', 'text-pub-fase1', 'text-pub-ok-txt',
            'text-pub-warn-txt', 'text-pub-danger-txt', 'text-pub-neutral-txt',
            'bg-pub-accent', 'bg-pub-ok', 'bg-pub-warn', 'bg-pub-danger', 'bg-pub-neutral-dot',
            'bg-pub-neutral-dot/[0.16]', 'border-pub-line-strong', 'border-pub-page',
        ].filter((c) => regraDe(c) === null);
        assert.deepEqual(semRegra, [], 'classe da coluna NÃO gerou CSS — num `.js` isso aconteceria com todo teste de render passando');
    });
});
