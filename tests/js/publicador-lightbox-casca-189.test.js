import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { existsSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 189, plano 03 — O LIGHTBOX DA IMAGEM: as FUNÇÕES PURAS (tarefa 1) e a
// casca POR PORTAL, o palco, os marcadores, as miniaturas e as setas (tarefa 2).
//
// ⚠️⚠️ DE CADA BLOCO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR: "o que
// precisaria estar errado no mundo para ele falhar?" — **"Nada" é ACHADO.**
//
//  1. VOLTA       → alguém "unificar" `moverNoLightbox` com a navegação do PAINEL,
//                   que CLAMPA (`:1018`, 188-07). O protótipo do lightbox DÁ A
//                   VOLTA (`:1321`). A asserção recusa o `0` que um clamp
//                   devolveria em `moverNoLightbox(0, -1, 5)` — é a isca que
//                   distingue as duas regras, e não um valor decorativo.
//  2. GALERIA     → `indiceNoLightbox` devolvendo `0` em vez de `null` com total
//                   `0`. O `null` é o que FECHA o lightbox (`:1324`); um `0`
//                   deixaria a casca de tela cheia aberta sobre nada.
//  3. ORIGEM      → dividir por `width` `0` (dá `Infinity`), ou deixar passar
//                   `NaN`. ⚠️ `typeof NaN === 'number'`: guarda por `typeof`
//                   desenharia `transformOrigin: 'NaN% NaN%'`, que **não lança** e
//                   é o caminho da TELA PRETA (voltou SEIS vezes, a última NO
//                   CLIQUE).
//  4. GATE-09     → `duration-[150ms]`, `delay-[…]` ou `ease-[…]` na fonte: eles
//                   **NÃO COMPILAM** (o `tailwindcss-animate` torna a classe
//                   ambígua) e a transição sairia SEM duração nenhuma, com todo
//                   teste de render passando. ⚠️ O controle é `duration-200`, a
//                   forma da escala NOMEADA, que compila — sem ele a sonda poderia
//                   estar proibindo qualquer coisa com "duration".
//                   ⚠️⚠️ **E a leitura é SEM COMENTÁRIO**: o docblock do `.jsx`
//                   CITA as três formas proibidas para explicar por que não as usa.
//                   Ler com comentário reprovaria o próprio conserto — a família
//                   medida em `feedback_gate_que_reprova_pelo_proprio_conserto`.
//  5. CURVA       → a classe `transition-transform` do Tailwind em vez do `style`:
//                   ela traz `cubic-bezier(0.4,0,0.2,1)` e o protótipo (`:731`) é
//                   `transition:transform .15s`, curva padrão `ease`. Trocar a
//                   curva é FID-02 (*"o Tailwind não tem esse passo"*).
//  6. CURSOR      → as duas classes CONCATENADAS (`cursor-${…}`): o scanner do
//                   Tailwind não vê classe montada por template literal e o
//                   cursor sai o do navegador, com o HTML "certo".
//  7. RESSALVA    → o docblock perder a declaração de que o MOVIMENTO não é
//                   provado. Não há navegador nesta máquina; afirmar "o zoom segue
//                   o cursor" sem a ressalva é o que esta fase não pode fazer.
//  8. TERRITÓRIO  → `String(` (há gate da 187-04 proibindo nesta região),
//                   `localStorage`, hex cru, peça nova em `.js`, e o `.jsx` fora de
//                   `ARQUIVOS_NOVOS` (o GATE-03 ficaria VERMELHO no meio do plano).
//  9. MANIFESTO   → `vocabulario-tamanhos` fora de LINHA PRÓPRIA ou sem a forma
//                   `>=`: aquele arquivo faz **7 sub-testes por `.jsx` varrido** e a
//                   contagem CRESCE POR CONSTRUÇÃO.
// 10. ADVERSO     → qualquer campo chegando objeto/`NaN`/array/nulo/ausente, a prop
//                   inteira nula, **e no CLIQUE e no MOVIMENTO** (tarefa 2).
//
// ⚠️ Todos os nomes de teste terminam em `189_03`: nome genérico COLIDE no
// `--filter`, que **SOMA** as contagens. (Este plano não tem id `php`, então o
// `--filter` não roda sobre ele — a régua do sufixo vale para o dia em que rodar.)
// ⚠️⚠️ **TODOS os bundles são montados ANTES do primeiro `test()`** — o `after()`
// do `node --test` já matou um arquivo inteiro **sem nenhum teste falhar**.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

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
        },
        external: [
            'react', 'react-dom', 'react-dom/server', 'react/jsx-runtime',
            'lucide-react', 'axios', 'recharts', 'date-fns', 'date-fns/*',
            'clsx', 'tailwind-merge', 'class-variance-authority',
            '@inertiajs/react',
            '@radix-ui/*', '@headlessui/*',
        ],
    });

    const outfile = path.join(
        __dirname,
        `.pub-lightbox189-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';
const REL_LIGHTBOX = `${DIR_APROV}/LightboxDaImagem.jsx`;
const REL_IMAGEM = `${DIR_APROV}/ImagemGrandeDoPainel.jsx`;
const REL_GRADE = `${DIR_APROV}/GradeDeMiniaturas.jsx`;
const REL_PAINEL = `${DIR_APROV}/PainelDeRevisao.jsx`;
const REL_GATE_VOCABULARIO = 'tests/js/publicador-vocabulario-novo.test.js';
const REL_MANIFESTO = 'tests/gates/contagens-189-03.txt';
const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

// ⚠️⚠️ ANTI-FANTASMA — `test -e` em CADA caminho citado. São DEZ caminhos
// fantasmas nesta milestone, TRÊS deles vindos de briefing. Sonda de AUSÊNCIA
// sobre arquivo inexistente passa CALADA.
// ⚠️ O protótipo fica FORA desta lista de propósito: ele **não é versionado** e o
// bloco que depende dele degrada para `skip` EXPLÍCITO e NOMEADO.
const CAMINHOS_CITADOS = [
    REL_LIGHTBOX, REL_IMAGEM, REL_GRADE, REL_PAINEL, REL_GATE_VOCABULARIO, REL_MANIFESTO,
];

/** ⚠️ A ISCA do anti-fantasma: tem de continuar AUSENTE (`189-REGUA.md` §9 (c)). */
const ISCA_AUSENTE = 'resources/js/Pages/Mlb/NaoExiste9z7.jsx';

// ⚠️ As fontes SEM comentário: a prosa em pt-BR deste projeto cita os próprios
// identificadores e os próprios literais, e **palavra no docblock reprova pelo
// próprio conserto**.
const FONTE_LIGHTBOX = lerSemComentarios(REL_LIGHTBOX);

/** ⚠️ O CRU, para as iscas que provam que a leitura SEM comentário não é cega. */
const CRU_LIGHTBOX = fs.readFileSync(path.resolve(RAIZ, REL_LIGHTBOX), 'utf8');

const FONTE_GATE_VOCABULARIO = lerSemComentarios(REL_GATE_VOCABULARIO);
const TEXTO_MANIFESTO = fs.readFileSync(path.resolve(RAIZ, REL_MANIFESTO), 'utf8');

// ⚠️⚠️ TODOS os bundles ANTES do primeiro `test()`.
const LIGHTBOX = await montar(REL_LIGHTBOX);

// ═══════════════════════════════════════════════════════════════════════════
// TAREFA 1 — AS FUNÇÕES PURAS
// ═══════════════════════════════════════════════════════════════════════════

test('LB-189-1 — os caminhos citados existem, e a isca continua AUSENTE 189_03', () => {
    const fantasmas = CAMINHOS_CITADOS.filter((c) => !existsSync(path.resolve(RAIZ, c)));
    assert.deepEqual(
        fantasmas,
        [],
        'caminho fantasma citado por este gate: sonda de AUSÊNCIA sobre arquivo inexistente passa CALADA',
    );

    // ⚠️ A isca do outro lado: sem ela, "os caminhos existem" não distinguiria
    // leitura certa de `existsSync` que sempre devolve `true`.
    assert.equal(
        existsSync(path.resolve(RAIZ, ISCA_AUSENTE)),
        false,
        `${ISCA_AUSENTE} EXISTE — ele é a isca de propósito da fase e tem de continuar ausente`,
    );
});

test('LB-189-2 — as DEZ funções/constantes puras são named exports, com os valores do protótipo 189_03', () => {
    // ⚠️ Lista CONSTANTE, nunca `Object.keys(…).length >= 10`: piso NÃO prova
    // presença, e um `>=` passaria com dez exports ERRADOS.
    const EXIGIDOS = [
        'ZOOM_NORMAL', 'ZOOM_AMPLIADO', 'origemDoZoom', 'moverNoLightbox',
        'indiceNoLightbox', 'rotuloDoZoom', 'classeDoCursorDoZoom', 'estiloDoZoom',
        'alternarZoom', 'contagemDoLightbox',
    ];
    assert.equal(EXIGIDOS.length, 10, 'a lista exigida mudou de tamanho — remeça o plano');

    const ausentes = EXIGIDOS.filter((nome) => LIGHTBOX[nome] === undefined);
    assert.deepEqual(ausentes, [], 'export que falta no módulo do lightbox');

    // As duas escalas, do `:1328`.
    assert.equal(LIGHTBOX.ZOOM_NORMAL, 1);
    assert.equal(LIGHTBOX.ZOOM_AMPLIADO, 2.5);

    // ⚠️ ISCA: nome que ninguém escreveu NÃO existe — prova que a sonda acima
    // não está lendo um objeto que devolve tudo.
    assert.equal(LIGHTBOX.zoomQueNinguemEscreveu189 ?? null, null);
});

test('LB-189-3 — `origemDoZoom`: o caso do protótipo, o CLAMP e a caixa inválida 189_03', () => {
    const { origemDoZoom } = LIGHTBOX;

    // ⚠️ O caso MEDIDO (`:1329`): `Math.round((clientX - left) / width * 100)`.
    assert.deepEqual(
        origemDoZoom({ clientX: 150, clientY: 50, caixa: { left: 100, top: 0, width: 200, height: 200 } }),
        { x: 25, y: 25 },
        'a origem do zoom não é a do protótipo `:1329` — é esta função que o "zoom que segue o cursor" É',
    );

    // Os dois extremos, exatos.
    assert.deepEqual(
        origemDoZoom({ clientX: 100, clientY: 0, caixa: { left: 100, top: 0, width: 200, height: 200 } }),
        { x: 0, y: 0 },
    );
    assert.deepEqual(
        origemDoZoom({ clientX: 300, clientY: 200, caixa: { left: 100, top: 0, width: 200, height: 200 } }),
        { x: 100, y: 100 },
    );

    // ⚠️⚠️ O CLAMP — acréscimo defensivo DECLARADO. Cursor fora da caixa nos
    // quatro lados: `-50` e `150` sairiam no `transformOrigin` sem o clamp.
    assert.deepEqual(
        origemDoZoom({ clientX: 0, clientY: -400, caixa: { left: 100, top: 0, width: 200, height: 200 } }),
        { x: 0, y: 0 },
        'o cursor à ESQUERDA/ACIMA da caixa não clampou em 0',
    );
    assert.deepEqual(
        origemDoZoom({ clientX: 999, clientY: 999, caixa: { left: 100, top: 0, width: 200, height: 200 } }),
        { x: 100, y: 100 },
        'o cursor à DIREITA/ABAIXO da caixa não clampou em 100',
    );

    // ⚠️⚠️ A CAIXA INVÁLIDA → `null` (a origem anterior fica), NUNCA `NaN`.
    // ⚠️ `width: 0` é o pior: `/0` dá `Infinity`, e `Infinity%` não lança.
    const CAIXAS_RUINS = [
        { left: 0, top: 0, width: 0, height: 200 },
        { left: 0, top: 0, width: 200, height: 0 },
        { left: 0, top: 0, width: -200, height: 200 },
        { left: 0, top: 0, width: NaN, height: 200 },
        { left: 0, top: 0, width: '200', height: 200 },
        { left: 0, top: 0, width: {}, height: 200 },
        { left: NaN, top: 0, width: 200, height: 200 },
        { left: null, top: 0, width: 200, height: 200 },
    ];
    for (const caixa of CAIXAS_RUINS) {
        const obtido = origemDoZoom({ clientX: 150, clientY: 50, caixa });
        assert.equal(obtido, null, 'caixa inválida devolveu origem em vez de `null`');
    }

    // A caixa que NÃO é objeto, e a ausência dela.
    for (const caixa of [null, undefined, 'caixa', 7, [], true]) {
        assert.equal(origemDoZoom({ clientX: 150, clientY: 50, caixa }), null);
    }

    // As coordenadas adversas.
    for (const clientX of [NaN, Infinity, '150', {}, null, undefined, []]) {
        assert.equal(
            origemDoZoom({ clientX, clientY: 50, caixa: { left: 100, top: 0, width: 200, height: 200 } }),
            null,
            'coordenada adversa produziu origem',
        );
    }

    // O movimento inteiro adverso — e **nunca lança**.
    for (const movimento of [null, undefined, 'x', 7, [], true, { toString: null }, { caixa: { toString: null } }]) {
        assert.equal(origemDoZoom(movimento), null);
    }

    // ⚠️ ISCA DO MESMO LADO: a MESMA forma com os tipos CERTOS produz origem.
    // Sem ela, "caixa inválida → null" seria satisfeito por uma função que
    // devolve `null` SEMPRE.
    assert.deepEqual(
        origemDoZoom({ clientX: 110, clientY: 20, caixa: { left: 100, top: 0, width: 200, height: 200 } }),
        { x: 5, y: 10 },
        'a isca do mesmo lado não produziu origem — a função pode estar devolvendo `null` sempre',
    );
});

test('LB-189-4 — `moverNoLightbox` DÁ A VOLTA, e o `0` do clamp é o que a asserção RECUSA 189_03', () => {
    const { moverNoLightbox } = LIGHTBOX;

    // ⚠️⚠️ OS DOIS CASOS QUE DISTINGUEM VOLTA DE CLAMP (`:1321` × `:1018`).
    const voltaParaTras = moverNoLightbox(0, -1, 5);
    assert.equal(voltaParaTras, 4, 'o ← da primeira foto não deu a volta para a ÚLTIMA (`:1321`)');
    assert.notEqual(
        voltaParaTras,
        0,
        'o ← devolveu 0: é a regra do PAINEL (clamp, `:1018`) aplicada ao LIGHTBOX — são duas regras de propósito',
    );

    const voltaParaFrente = moverNoLightbox(4, 1, 5);
    assert.equal(voltaParaFrente, 0, 'o → da última foto não deu a volta para a PRIMEIRA (`:1321`)');
    assert.notEqual(voltaParaFrente, 4, 'o → clampou na última em vez de dar a volta');

    // O meio da lista, onde volta e clamp CONCORDAM — por isso não serve de prova
    // sozinho, e está aqui só para a função não estar quebrada no caso fácil.
    assert.equal(moverNoLightbox(2, 1, 5), 3);
    assert.equal(moverNoLightbox(2, -1, 5), 1);

    // Galeria de UMA foto: qualquer direção fica nela mesma.
    assert.equal(moverNoLightbox(0, 1, 1), 0);
    assert.equal(moverNoLightbox(0, -1, 1), 0);

    // ⚠️ Total `0` → `null`: não há foto para onde ir.
    assert.equal(moverNoLightbox(0, 1, 0), null);

    // Entrada adversa: nunca lança, nunca devolve `NaN`.
    for (const total of [null, undefined, NaN, -3, 2.5, '5', {}, []]) {
        assert.equal(moverNoLightbox(0, 1, total), null, 'total adverso produziu índice');
    }
    for (const delta of [null, undefined, NaN, 0.5, '1', {}, []]) {
        assert.equal(moverNoLightbox(0, delta, 5), null, 'delta adverso produziu índice');
    }
    // Índice adverso cai na primeira foto e o delta vale: `0 + 1 = 1`.
    for (const indice of [null, undefined, NaN, '2', {}, [], -7]) {
        assert.equal(moverNoLightbox(indice, 1, 5), 1, 'índice adverso não caiu na primeira foto');
    }

    // ⚠️ O acréscimo defensivo (b): `d` maior que `n` não sai da faixa.
    // A forma do protótipo (`(i + d + n) % n`) daria NEGATIVO aqui.
    const longe = moverNoLightbox(0, -12, 5);
    assert.ok(longe >= 0 && longe <= 4, `delta grande saiu da faixa: ${longe}`);
});

test('LB-189-5 — `indiceNoLightbox`: o clamp do `:1324`, e `null` é o que FECHA o lightbox 189_03', () => {
    const { indiceNoLightbox } = LIGHTBOX;

    // `:1324` — `Math.min(lb.i, ord.length - 1)`.
    assert.equal(indiceNoLightbox(7, 3), 2, 'o índice não foi clampado no total (`:1324`)');
    assert.equal(indiceNoLightbox(0, 3), 0);
    assert.equal(indiceNoLightbox(2, 3), 2);

    // ⚠️⚠️ GALERIA VAZIA → `null`. É o `null` que FECHA (`:1324`: `if (i < 0)
    // return { open: false }`). Um `0` aqui deixaria a casca de tela cheia aberta
    // sobre NADA — e reprovar uma foto tira ela do conjunto final.
    assert.equal(indiceNoLightbox(0, 0), null, 'galeria vazia não devolveu `null`: o lightbox não fecharia');
    assert.equal(indiceNoLightbox(5, 0), null);

    // Índice adverso com total > 0 → **0**, nunca `null`: fechar o lightbox por
    // causa de um campo torto seria pior que mostrar a primeira foto.
    for (const indice of [null, undefined, NaN, 2.5, '1', {}, [], -4, true]) {
        assert.equal(indiceNoLightbox(indice, 3), 0, 'índice adverso não caiu em 0');
    }

    // Total adverso → `null`.
    for (const total of [null, undefined, NaN, -1, 1.5, '3', {}, []]) {
        assert.equal(indiceNoLightbox(0, total), null);
    }
});

test('LB-189-6 — `rotuloDoZoom`, `classeDoCursorDoZoom` e `alternarZoom` seguem o `> 1` do `:1326` 189_03', () => {
    const { rotuloDoZoom, classeDoCursorDoZoom, alternarZoom, ZOOM_NORMAL, ZOOM_AMPLIADO } = LIGHTBOX;

    assert.equal(rotuloDoZoom(1), 'Ampliar 2,5×');
    assert.equal(rotuloDoZoom(2.5), 'Reduzir');
    assert.equal(classeDoCursorDoZoom(1), 'cursor-crosshair');
    assert.equal(classeDoCursorDoZoom(2.5), 'cursor-zoom-out');
    assert.equal(alternarZoom(1), 2.5);
    assert.equal(alternarZoom(2.5), 1);

    // ⚠️ As constantes atravessam as três, e é o que permite ao 189-05 importar
    // os rótulos em vez de redigitá-los.
    assert.equal(rotuloDoZoom(ZOOM_NORMAL), LIGHTBOX.ROTULO_AMPLIAR);
    assert.equal(rotuloDoZoom(ZOOM_AMPLIADO), LIGHTBOX.ROTULO_REDUZIR);

    // ⚠️ O critério é `> 1` (o do protótipo), não `=== 2.5`: uma escala
    // intermediária futura continua lendo como AMPLIADA.
    assert.equal(rotuloDoZoom(1.8), 'Reduzir');
    assert.equal(classeDoCursorDoZoom(1.8), 'cursor-zoom-out');
    assert.equal(alternarZoom(1.8), 1);

    // ⚠️ Zoom adverso cai no NORMAL — e `typeof NaN === 'number'` é por que a
    // guarda é `Number.isFinite`.
    for (const zoom of [null, undefined, NaN, Infinity, '2.5', {}, [], true, { toString: null }]) {
        assert.equal(rotuloDoZoom(zoom), 'Ampliar 2,5×', 'zoom adverso leu como AMPLIADO');
        assert.equal(classeDoCursorDoZoom(zoom), 'cursor-crosshair');
        assert.equal(alternarZoom(zoom), 2.5);
    }

    // ⚠️⚠️ AS DUAS CLASSES SÃO LITERAIS DE UM MAPA, e o mapa tem EXATAMENTE duas
    // chaves. Igualdade de conjunto, nunca `includes` (que casa prefixo).
    assert.deepEqual(
        Object.keys(LIGHTBOX.CURSOR_DO_ZOOM).sort(),
        ['ampliado', 'normal'],
        'o mapa de cursor ganhou/perdeu chave',
    );
    assert.deepEqual(
        Object.values(LIGHTBOX.CURSOR_DO_ZOOM).sort(),
        ['cursor-crosshair', 'cursor-zoom-out'],
    );
});

test('LB-189-7 — `estiloDoZoom` leva a transição INTEIRA por `style`, com a curva `ease` do `:731` 189_03', () => {
    const { estiloDoZoom } = LIGHTBOX;

    assert.deepEqual(
        estiloDoZoom({ zoom: 2.5, x: 30, y: 70 }),
        {
            transform: 'scale(2.5)',
            transformOrigin: '30% 70%',
            transitionProperty: 'transform',
            transitionDuration: '150ms',
            transitionTimingFunction: 'ease',
        },
        'o `style` da camada do zoom não é o do `:731`',
    );

    assert.deepEqual(
        estiloDoZoom({ zoom: 1, x: 0, y: 100 }),
        {
            transform: 'scale(1)',
            transformOrigin: '0% 100%',
            transitionProperty: 'transform',
            transitionDuration: '150ms',
            transitionTimingFunction: 'ease',
        },
    );

    // ⚠️⚠️ `x`/`y` ADVERSOS → `50% 50%`, NUNCA `NaN%`. Um `NaN%` num
    // `transformOrigin` **não lança** — é por isso que o gate olha o VALOR.
    for (const ruim of [NaN, Infinity, -1, 101, '30', {}, null, undefined, [], true]) {
        const estilo = estiloDoZoom({ zoom: 2.5, x: ruim, y: 70 });
        assert.equal(estilo.transformOrigin, '50% 50%', 'x adverso virou origem');
        assert.doesNotMatch(estilo.transformOrigin, /NaN|undefined|Infinity|object/);
        const estiloY = estiloDoZoom({ zoom: 2.5, x: 30, y: ruim });
        assert.equal(estiloY.transformOrigin, '50% 50%');
    }

    // O estado inteiro adverso — nunca lança, e a transição continua inteira.
    for (const estado of [null, undefined, 'x', 7, [], true, { toString: null }]) {
        const estilo = estiloDoZoom(estado);
        assert.equal(estilo.transform, 'scale(1)');
        assert.equal(estilo.transformOrigin, '50% 50%');
        assert.equal(estilo.transitionDuration, '150ms');
        assert.equal(estilo.transitionTimingFunction, 'ease');
    }

    // ⚠️ O acréscimo defensivo (c): o `scale` é um dos DOIS literais, então zoom
    // adverso NUNCA vira `scale(NaN)`.
    for (const zoom of [NaN, Infinity, '2.5', {}, null, []]) {
        assert.equal(estiloDoZoom({ zoom, x: 10, y: 10 }).transform, 'scale(1)');
    }
    assert.equal(estiloDoZoom({ zoom: 7, x: 10, y: 10 }).transform, 'scale(2.5)');

    // ⚠️ A curva é `ease` e NÃO o `cubic-bezier` da classe `transition-transform`.
    assert.notEqual(
        LIGHTBOX.TRANSICAO_DO_ZOOM.transitionTimingFunction,
        'cubic-bezier(0.4,0,0.2,1)',
        'a curva virou a do Tailwind — FID-02: *"o Tailwind não tem esse passo"*',
    );
});

test('LB-189-8 — `contagemDoLightbox` é o `"2 de 6"` do `:1326`, e nunca escreve `NaN` 189_03', () => {
    const { contagemDoLightbox } = LIGHTBOX;

    assert.equal(contagemDoLightbox(1, 6), '2 de 6', 'a contagem não é a de `:1326`');
    assert.equal(contagemDoLightbox(0, 1), '1 de 1');

    // ⚠️ Índice acima do total é CLAMPADO (a galeria encolheu depois de reprovar).
    assert.equal(contagemDoLightbox(9, 3), '3 de 3');

    // Galeria vazia → `null` (nada a contar; o lightbox já fechou).
    assert.equal(contagemDoLightbox(0, 0), null);

    for (const total of [null, undefined, NaN, -1, '6', {}, []]) {
        assert.equal(contagemDoLightbox(1, total), null);
    }
    for (const indice of [null, undefined, NaN, '1', {}, [], true]) {
        const texto = contagemDoLightbox(indice, 6);
        assert.equal(texto, '1 de 6', 'índice adverso não caiu na primeira foto');
        assert.doesNotMatch(texto, /NaN|undefined|\[object Object\]/);
    }
});

test('LB-189-9 — GATE-09 na FONTE: nenhum `duration-[`/`delay-[`/`ease-[`, com `duration-200` como CONTROLE 189_03', () => {
    // ⚠️⚠️ A LEITURA É **SEM COMENTÁRIO**. O docblock do `.jsx` CITA as três
    // formas proibidas para explicar por que não as usa; ler o cru reprovaria o
    // próprio conserto.
    const PROIBIDAS = [
        ['duration arbitrária', /duration-\[/],
        ['delay arbitrário', /delay-\[/],
        ['ease arbitrário', /ease-\[/],
        ['a classe `transition-transform` do Tailwind (outra curva)', /transition-transform\b/],
    ];
    const infratoras = PROIBIDAS.filter(([, re]) => re.test(FONTE_LIGHTBOX)).map(([nome]) => nome);
    assert.deepEqual(
        infratoras,
        [],
        'forma que NÃO COMPILA (o `tailwindcss-animate` torna a classe ambígua): a transição sairia sem duração,'
        + ' com todo teste de render passando',
    );

    // ⚠️⚠️ O CONTROLE `duration-200`: a escala NOMEADA compila, e a sonda NÃO a
    // proíbe. Sem este controle, as regexes acima poderiam estar banindo
    // qualquer coisa com "duration" — e aí elas não mediriam o invariante.
    assert.doesNotMatch('duration-200', /duration-\[/, 'o controle `duration-200` casou a forma proibida');
    assert.doesNotMatch('ease-in-out', /ease-\[/, 'o controle `ease-in-out` casou a forma proibida');

    // ⚠️⚠️ ISCA NAS DUAS DIREÇÕES — as regexes VEEM a forma errada. Sem isto,
    // `infratoras === []` seria indistinguível de quatro regexes cegas.
    const SINTETICO = 'className="duration-[150ms] delay-[80ms] ease-[cubic-bezier(.4,0,.2,1)] transition-transform"';
    const vistas = PROIBIDAS.filter(([, re]) => re.test(SINTETICO)).map(([nome]) => nome);
    assert.equal(vistas.length, 4, `a sonda do GATE-09 está CEGA: viu ${vistas.length} de 4 formas no texto sintético`);

    // ⚠️ E a transição está de fato no código, por `style`: a ausência das formas
    // proibidas sozinha seria satisfeita por um arquivo SEM transição nenhuma.
    for (const campo of ['transitionProperty', 'transitionDuration', 'transitionTimingFunction']) {
        assert.ok(
            FONTE_LIGHTBOX.includes(campo),
            `${campo} não está na fonte: a transição foi retirada em vez de ir por \`style\``,
        );
    }
    assert.ok(FONTE_LIGHTBOX.includes("'150ms'"), 'a duração de 150 ms (`:731`) saiu do `style`');
});

test('LB-189-10 — as duas classes de cursor são LITERAIS, nunca concatenadas 189_03', () => {
    // ⚠️⚠️ O scanner do Tailwind **não vê** classe montada por template literal:
    // ela fica no HTML, todo render passa, e o cursor sai o do navegador.
    assert.ok(FONTE_LIGHTBOX.includes("'cursor-crosshair'"), 'o `cursor-crosshair` não é literal na fonte');
    assert.ok(FONTE_LIGHTBOX.includes("'cursor-zoom-out'"), 'o `cursor-zoom-out` não é literal na fonte');

    const CONCATENADAS = [
        ['cursor por template literal', /cursor-\$\{/],
        ['cursor por concatenação', /'cursor-'\s*\+/],
        ['classe de coordenada por template literal', /(left|top)-\[\$\{/],
    ];
    const achadas = CONCATENADAS.filter(([, re]) => re.test(FONTE_LIGHTBOX)).map(([nome]) => nome);
    assert.deepEqual(achadas, [], 'classe montada por concatenação: o scanner do Tailwind NÃO a vê');

    // ⚠️ ISCA: as três regexes acham a forma errada num texto sintético.
    const SINTETICO = "cn(`cursor-${chave}`, 'cursor-' + chave, `left-[${x}%]`, `top-[${y}%]`)";
    const vistas = CONCATENADAS.filter(([, re]) => re.test(SINTETICO)).map(([nome]) => nome);
    assert.equal(vistas.length, 3, `a sonda de concatenação está CEGA: viu ${vistas.length} de 3`);
});

test('LB-189-11 — o docblock DECLARA que o movimento do cursor NÃO é provado 189_03', () => {
    // ⚠️⚠️ Esta é a ÚNICA sonda deste arquivo que lê o CRU de propósito: o
    // docblock **é** o artefato sob teste. A inversão está declarada aqui;
    // "consertá-la" para `lerSemComentarios` faria o gate medir NADA.
    const EXIGIDOS = [
        ['a ressalva literal', /não é provado/],
        ['o nome da função que PROVA o zoom', /origemDoZoom/],
        ['o motivo (não há navegador)', /não há navegador/],
        ['o dono da conferência visual (a conta 459)', /459/],
    ];
    const ausentes = EXIGIDOS.filter(([, re]) => !re.test(CRU_LIGHTBOX)).map(([nome]) => nome);
    assert.deepEqual(
        ausentes,
        [],
        'o docblock perdeu a ressalva: afirmar "o zoom segue o cursor" sem ela é o que esta fase NÃO pode fazer',
    );

    // ⚠️ ISCA DO LEITOR: a ressalva vive em COMENTÁRIO, então a fonte SEM
    // comentário **não** a tem. Se tivesse, alguém a escreveu em código (ou o
    // `lerSemComentarios` parou de funcionar) — e as outras sondas deste arquivo,
    // que dependem dele, estariam lendo prosa.
    assert.doesNotMatch(
        FONTE_LIGHTBOX,
        /não é provado/,
        'a ressalva apareceu na fonte SEM comentário: o `lerSemComentarios` não está removendo o docblock',
    );
});

test('LB-189-12 — território: nada de `String(`, `localStorage`, hex cru nem `.js` novo 189_03', () => {
    const VETADAS = [
        ['String(', /\bString\s*\(/],
        ['localStorage', /localStorage/],
        ['sessionStorage', /sessionStorage/],
        ['hex cru (TOK-15)', /#[0-9a-fA-F]{3,8}\b/],
        ['token `ecf-*` (FID-04)', /\becf-(bg|card|yellow)\b/],
        ['`font-display` (FID-04)', /font-display\b/],
        ['`text-sm` (FID-03)', /\btext-sm\b/],
        ['`border-white/\\[0.08\\]` (FID-04)', /border-white\/\[0\.08\]/],
        ['escala de peso NOMEADA proibida pelo GATE-03', /font-(thin|extralight|light|extrabold|black)\b/],
    ];
    const infratoras = VETADAS.filter(([, re]) => re.test(FONTE_LIGHTBOX)).map(([nome]) => nome);
    assert.deepEqual(infratoras, [], 'forma VETADA no `.jsx` do lightbox');

    // ⚠️ ISCA NAS DUAS DIREÇÕES: as nove regexes veem as formas erradas.
    const SINTETICO = "String(x); localStorage.getItem('a'); sessionStorage.clear();"
        + " 'bg-[#FACC15] text-sm bg-ecf-card font-display font-extrabold border-white/[0.08]'";
    const vistas = VETADAS.filter(([, re]) => re.test(SINTETICO)).map(([nome]) => nome);
    assert.equal(vistas.length, VETADAS.length, `a sonda de território está CEGA: viu ${vistas.length} de ${VETADAS.length}`);

    // ⚠️ E a peça é `.jsx`: num `.js` nenhuma classe geraria CSS.
    assert.ok(REL_LIGHTBOX.endsWith('.jsx'), 'a peça do lightbox não é `.jsx`');
});

test('LB-189-13 — o `.jsx` está em `ARQUIVOS_NOVOS` exatamente UMA vez (GATE-03) 189_03', () => {
    // ⚠️⚠️ O GATE-03 compara por `deepEqual` os `.jsx` do DISCO de `aprovacao/`
    // com `ARQUIVOS_NOVOS`: `.jsx` no disco fora da lista deixa aquele gate
    // VERMELHO **no meio deste plano**, e árvore vermelha no HEAD pode BARRAR
    // DEPLOY.
    const ocorrencias = FONTE_GATE_VOCABULARIO.split('LightboxDaImagem.jsx').length - 1;
    assert.equal(
        ocorrencias,
        1,
        `o lightbox aparece ${ocorrencias} vez(es) em ARQUIVOS_NOVOS (lido SEM comentário) — tem de ser exatamente 1`,
    );

    // ⚠️ ISCA DO LEITOR: o CRU tem MAIS ocorrências (o bloco de comentário cita o
    // arquivo). Se os dois números fossem iguais, o `lerSemComentarios` não estaria
    // removendo nada e esta contagem poderia estar contando prosa.
    const cru = fs.readFileSync(path.resolve(RAIZ, REL_GATE_VOCABULARIO), 'utf8');
    const ocorrenciasCruas = cru.split('LightboxDaImagem.jsx').length - 1;
    assert.ok(
        ocorrenciasCruas >= 1,
        'o arquivo de gate não cita o lightbox nem no cru — a leitura está apontando para outro lugar',
    );
});

test('LB-189-14 — o manifesto declara `vocabulario-tamanhos` em LINHA PRÓPRIA e na forma `>=` 189_03', () => {
    // ⚠️⚠️ FORMA `>=` porque a contagem daquele arquivo CRESCE POR CONSTRUÇÃO: ele
    // faz **7 sub-testes por `.jsx` varrido** nos diretórios fechados. Subir o
    // piso a cada wave é o conserto errado — treina o executor seguinte a ajustar
    // réguas quando elas incomodam.
    const linhas = TEXTO_MANIFESTO.split(/\r?\n/)
        .filter((l) => /^vocabulario-tamanhos\s/.test(l));
    assert.equal(linhas.length, 1, 'o `vocabulario-tamanhos` não está em LINHA PRÓPRIA (ou está duplicado)');
    assert.match(
        linhas[0],
        /^vocabulario-tamanhos\s+js\s+\S+\s+::\s+tests=>=\d+$/,
        `a linha do manifesto não está na forma \`>=\`: ${linhas[0]}`,
    );

    // ⚠️ E o id deste plano está lá, com o arquivo de teste inteiro: no modo `js`
    // o harness roda o ARQUIVO, então é um id por arquivo de gate.
    assert.match(
        TEXTO_MANIFESTO,
        /^lightbox-casca\s+js\s+tests\/js\/publicador-lightbox-casca-189\.test\.js\s+::\s+tests=\d+$/m,
        'o id `lightbox-casca` não está no manifesto na forma esperada',
    );

    // ⚠️ ISCA: a regex da linha própria NÃO casa a forma de igualdade exata nem a
    // forma sem `js`. Sem isto, "a linha está na forma `>=`" poderia estar casando
    // qualquer coisa.
    assert.doesNotMatch(
        'vocabulario-tamanhos  js  a.test.js  ::  tests=681',
        /^vocabulario-tamanhos\s+js\s+\S+\s+::\s+tests=>=\d+$/,
        'a sonda do `>=` casou a forma de igualdade exata — está cega',
    );
    assert.match(
        'vocabulario-tamanhos  js  a.test.js  ::  tests=>=681',
        /^vocabulario-tamanhos\s+js\s+\S+\s+::\s+tests=>=\d+$/,
        'a sonda do `>=` não casou a forma CERTA — está cega do outro lado',
    );
});

test('LB-189-15 — FID-01 §E: os ponteiros do protótipo deste plano, reconfirmados 189_03', (contexto) => {
    // ⚠️⚠️ `design_handoff_publicador/` **NÃO é versionado**: este bloco degrada
    // para `skip` EXPLÍCITO e NOMEADO, nunca passa calado como "conferido".
    const caminho = path.resolve(RAIZ, PROTOTIPO);
    if (!existsSync(caminho)) {
        contexto.skip(`SKIP NOMEADO: ${PROTOTIPO} não existe nesta árvore (não é versionado) — os ponteiros do`
            + ' lightbox NÃO foram conferidos nesta execução');
        return;
    }

    const linhas = fs.readFileSync(caminho, 'utf8').split(/\r?\n/);
    const linha = (n) => linhas[n - 1] ?? '';

    // ⚠️ A notação `:N` é EXCLUSIVA do protótipo, e `N <= 1432`.
    const PONTEIROS = [
        [727, 'rgba(5,5,5,0.94)'],
        [727, 'grid-template-columns:minmax(0,1fr) 320px'],
        [730, 'width:min(78vh, 100%)'],
        [731, 'transition:transform .15s'],
        [733, 'width:26px'],
        [741, 'font-size:18px'],
        [1321, '(lb.i + d + n) % n'],
        [1326, "'Ampliar 2,5×'"],
        [1328, 'lbZoom > 1 ? 1 : 2.5'],
    ];
    const divergentes = PONTEIROS
        .filter(([n, trecho]) => !linha(n).includes(trecho))
        .map(([n, trecho]) => `:${n} não contém ${trecho}`);
    assert.deepEqual(divergentes, [], 'ponteiro do protótipo deslocado — meça por `sed` e corrija a tabela');

    // ⚠️ ISCA: a sonda VÊ deslocamento. A linha 9999 não existe e a 1 não contém
    // o fundo do lightbox.
    assert.equal(linha(9999).includes('rgba(5,5,5,0.94)'), false, 'a isca L9999 casou — a leitura está cega');
    assert.equal(linha(1).includes('rgba(5,5,5,0.94)'), false, 'a isca da linha 1 casou — a leitura está cega');

    // ⚠️ E o documento é o CERTO: 1432 linhas.
    assert.equal(linhas.length >= 1432, true, `o protótipo encolheu: ${linhas.length} linhas (esperado >= 1432)`);
});
