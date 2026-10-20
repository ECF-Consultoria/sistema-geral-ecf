import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { existsSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
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

const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

// ⚠️⚠️ TODOS os bundles ANTES do primeiro `test()` — o `after()` do `node --test`
// já matou um arquivo inteiro **sem nenhum teste falhar**.
// ⚠️ Os DOIS módulos do 188-08 são montados porque a régua §2 manda **IMPORTAR** o
// que a 188 já desenha (INV-09 a borda com seleção e o anel; INV-11 o estado de
// `url_arquivo` nula), e o gate compara os literais IMPORTADOS com os usados.
const LIGHTBOX = await montar(REL_LIGHTBOX);
const IMAGEM_GRANDE = await montar(REL_IMAGEM);
const GRADE = await montar(REL_GRADE);

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

// ═══════════════════════════════════════════════════════════════════════════
// TAREFA 2 — A CASCA POR PORTAL, O PALCO, OS MARCADORES, AS MINIATURAS E AS SETAS
// ═══════════════════════════════════════════════════════════════════════════

/**
 * A ÁRVORE do HTML, com a PROFUNDIDADE de cada elemento de abertura.
 *
 * ⚠️⚠️ **NUNCA `indexOf(tagDeAbertura)`**: ele pega o IRMÃO ERRADO quando dois
 * elementos têm a MESMA string de classes — **quatro gates deste projeto estavam
 * medindo o irmão errado**. Aqui cada elemento tem índice ABSOLUTO na árvore.
 * ⚠️ E a PROFUNDIDADE é o que prova ESTRUTURA: *"o rodapé do painel é IRMÃO do
 * corpo rolável"* só se pega medindo, nunca pela aparência — dentro do `children`
 * ele ROLARIA PARA FORA DA TELA com todo teste de render passando.
 */
function arvore(html) {
    const itens = [];
    let profundidade = 0;
    const re = /<(\/?)(div|span|button|aside|section)\b([^>]*?)(\/?)>/g;
    let m = re.exec(html);
    while (m !== null) {
        const fechando = m[1] === '/';
        const autofechada = m[4] === '/';
        if (fechando) {
            profundidade -= 1;
        } else {
            itens.push({
                indice: itens.length,
                tag: m[2],
                profundidade,
                atributos: m[3],
                classe: (m[3].match(/class="([^"]*)"/) || [])[1] ?? '',
                titulo: (m[3].match(/title="([^"]*)"/) || [])[1] ?? null,
                rotulo: (m[3].match(/aria-label="([^"]*)"/) || [])[1] ?? null,
                estilo: (m[3].match(/style="([^"]*)"/) || [])[1] ?? null,
                texto: null,
            });
            if (!autofechada) profundidade += 1;
        }
        m = re.exec(html);
    }
    return itens;
}

/** As imagens `<img>` do HTML, com `src`, `class` e `alt`. */
function imagensDo(html) {
    return [...html.matchAll(/<img\b([^>]*)>/g)].map((m) => ({
        atributos: m[1],
        classe: (m[1].match(/class="([^"]*)"/) || [])[1] ?? '',
        src: (m[1].match(/src="([^"]*)"/) || [])[1] ?? null,
        temSrc: /\ssrc=/.test(m[1]),
    }));
}

/** As classes de um elemento como CONJUNTO — igualdade, nunca `includes`. */
function classesDe(elemento) {
    return new Set(elemento.classe.split(/\s+/).filter((c) => c !== ''));
}

const conjunto = (texto) => new Set(texto.split(/\s+/).filter((c) => c !== ''));

/** O conteúdo de texto, sem tags — para ler o que a tela DIZ. */
function texto(html) {
    return html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
}

/** Os NÚMEROS dentro dos marcadores, na ordem do HTML. */
function numerosDosMarcadores(html) {
    return [...html.matchAll(/<span[^>]*class="[^"]*h-\[26px\][^"]*"[^>]*>(\d+)<\/span>/g)]
        .map((m) => Number(m[1]));
}

const nada = () => {};

// ═══ AS FIXTURES — ⚠️⚠️ ELAS DISCRIMINAM DE PROPÓSITO ═════════════════════
//
// ⚠️ Na wave 187-06 as duas mutações sobreviventes eram falha da FIXTURE, não da
// asserção (uma oferta só não distinguia nada).
// ➡️ Aqui: QUATRO imagens, cada uma num ESTADO diferente, com alerta e sem, IA e
// original, COM e SEM `url_arquivo`, e os ordinais **fora de ordem alfabética**
// para que "ler o ordinal" seja distinguível de "contar".

/** ⚠️ Os ordinais são 4 e 2 — NÃO 1 e 2: "contar" daria 1,2 e o gate pegaria. */
const VERIFICACOES_DA_FOTO_1 = [
    { severidade: 'ok', texto: 'Fundo limpo e sem ruído', glifo: '✓', cor: 'verde', ordinal: null },
    { severidade: 'alto', texto: 'Texto sobreposto ilegível no canto', glifo: '!', cor: 'vermelho', ordinal: 4 },
    { severidade: 'medio', texto: 'Recorte corta a alça do produto', glifo: '!', cor: 'ambar', ordinal: 2 },
];

const MARCADORES_DA_FOTO_1 = [
    { ordinal: 4, x: 37.5, y: 12.25, cor: 'vermelho' },
    { ordinal: 2, x: 80, y: 66.5, cor: 'ambar' },
];

const IMAGENS = [
    {
        id: 11, imagem_id: 901, posicao: 1, posicao_rotulo: '#1', rotulo: 'Frente do produto',
        titulo: 'Foto 1 — frente', de_ia: true, tipo_rotulo: 'Gerada por IA', principal: true,
        grupo: 'cor=preto', grupo_geral: null, estado: 'pending', estado_rotulo: 'A conferir',
        estado_cor: 'ambar', estado_dot: 'ambar', com_alerta: true, aprovada: false, aceita_acao: true,
        borda: 'selecionada', anel: true, url_arquivo: '/fotos/901/arquivo',
        marcadores: MARCADORES_DA_FOTO_1, verificacoes: VERIFICACOES_DA_FOTO_1,
    },
    {
        id: 12, imagem_id: 902, posicao: 2, posicao_rotulo: '#2', rotulo: 'Lateral',
        titulo: 'Foto 2 — lateral', de_ia: false, tipo_rotulo: 'Original', principal: false,
        grupo: 'cor=preto', grupo_geral: null, estado: 'approved', estado_rotulo: 'Aprovada',
        estado_cor: 'verde', estado_dot: 'verde', com_alerta: false, aprovada: true, aceita_acao: false,
        borda: 'aprovada', anel: false, url_arquivo: '/fotos/902/arquivo',
        marcadores: [], verificacoes: [],
    },
    {
        // ⚠️ `url_arquivo` NULA — o estado declarado do 188-08 (INV-11), nunca `src=""`.
        id: 13, imagem_id: 903, posicao: 3, posicao_rotulo: '#3', rotulo: 'Medidas',
        titulo: 'Foto 3 — medidas', de_ia: true, tipo_rotulo: 'Gerada por IA', principal: false,
        grupo: 'cor=branco', grupo_geral: null, estado: 'regenerating', estado_rotulo: 'Gerando nova versão',
        estado_cor: 'neutra', estado_dot: 'neutra', com_alerta: false, aprovada: false, aceita_acao: false,
        borda: 'neutra', anel: false, url_arquivo: null,
        marcadores: [], verificacoes: [],
    },
    {
        // ⚠️ O 5º RAMO da borda: `rejected` → `border-pub-line-off`.
        id: 14, imagem_id: 904, posicao: 4, posicao_rotulo: '#4', rotulo: 'Embalagem',
        titulo: 'Foto 4 — embalagem', de_ia: false, tipo_rotulo: 'Original', principal: false,
        grupo: 'cor=branco', grupo_geral: null, estado: 'rejected', estado_rotulo: 'Reprovada',
        estado_cor: 'vermelho', estado_dot: 'vermelho', com_alerta: false, aprovada: false, aceita_acao: false,
        borda: 'neutra', anel: false, url_arquivo: '/fotos/904/arquivo',
        marcadores: [{ ordinal: 1, x: 10, y: 10, cor: 'vermelho' }], verificacoes: [],
    },
];

const PROPS_ABERTO = {
    aberto: true,
    imagens: IMAGENS,
    indice: 0,
    zoom: 1,
    origem: { x: 50, y: 50 },
    onMover: nada,
    onSelecionar: nada,
    onAlternarZoom: nada,
    onMoverOrigem: nada,
    lateral: null,
};

test('LB-189-16 — as CINCO peças internas são named exports, e a CONTRAPROVA do portal 189_03', () => {
    // ⚠️ Lista CONSTANTE, nunca `>= N`: **piso NÃO prova PRESENÇA**.
    const PECAS = [
        'RaizDoLightbox', 'PalcoDoLightbox', 'MarcadoresDoLightbox',
        'MiniaturasDoLightbox', 'SetasDoLightbox',
    ];
    assert.equal(PECAS.length, 5, 'a lista de peças mudou de tamanho — remeça o plano');
    const ausentes = PECAS.filter((nome) => typeof LIGHTBOX[nome] !== 'function');
    assert.deepEqual(
        ausentes,
        [],
        'peça interna que NÃO é named export: o renderizador de servidor não desenha PORTAL, então é SÓ por elas'
        + ' que o gate consegue medir o lightbox',
    );

    // Os handlers puros também, porque `renderToStaticMarkup` NÃO dispara `onClick`.
    const HANDLERS = ['aoMoverOCursor', 'aoClicarNaSeta', 'aoClicarNaMiniatura'];
    const handlersAusentes = HANDLERS.filter((nome) => typeof LIGHTBOX[nome] !== 'function');
    assert.deepEqual(handlersAusentes, [], 'handler que não é named export — o clique ficaria sem gate');

    // ⚠️⚠️ A CONTRAPROVA, EM DUAS PARTES, E É ELA QUE IMPEDE O FALSO POSITIVO:
    //
    // (a) o COMPOSTO sai VAZIO — *"Portals are not currently supported by the
    //     server renderer"*. É o comportamento esperado, e a guarda de ambiente é
    //     o que faz sair `''` em vez de LANÇAR (`document is not defined`);
    const composto = render(LIGHTBOX.default, PROPS_ABERTO);
    assert.equal(
        composto,
        '',
        'o composto NÃO saiu vazio: ou o portal foi removido, ou a guarda de ambiente mudou',
    );

    // (b) ⚠️⚠️ **E A RAIZ, EXERCIDA DIRETAMENTE, SAI CHEIA.** Sem esta segunda
    //     parte o `equal('')` acima seria **vazio por construção**: um arquivo que
    //     não exporta nada, ou um componente que devolve `null` sempre, passaria
    //     no (a) e o gate ficaria VERDE provando NADA — o furo mais perigoso desta
    //     fase, porque não deixa sinal.
    const raiz = render(LIGHTBOX.RaizDoLightbox, {
        children: React.createElement('span', null, 'palco'),
        lateral: null,
    });
    assert.ok(
        raiz.length > 200,
        `a raiz do lightbox saiu com ${raiz.length} bytes (esperado > 200): a contraprova do portal está vazia`,
    );
});

test('LB-189-17 — `CLASSES_RAIZ` por igualdade de CONJUNTO: `z-[41]`, o grid e o `font-pub` 189_03', () => {
    // ⚠️⚠️ Igualdade de CONJUNTO, nunca `includes`: ele casa prefixo E sufixo de
    // outro nome, e já mordeu CINCO vezes neste projeto (`w-[560px]` ×
    // `max-w-[560px]`; `h-[64px]` × `min-h-[64px]`).
    assert.deepEqual(
        conjunto(LIGHTBOX.CLASSES_RAIZ),
        conjunto('fixed inset-0 z-[41] grid grid-cols-[minmax(0,1fr)_320px] bg-[rgba(5,5,5,0.94)] font-pub text-pub-txt'),
        'as classes da raiz divergiram da `189-FID01.md` §B1',
    );

    // ⚠️⚠️ O `z-[41]` é o REMAPEAMENTO, e o gate RECUSA o número que o protótipo
    // deu A ESTA PEÇA (`:727`, `z-index:90`). A ordem relativa é o que importa:
    // header `z-30` < backdrop `z-[39]` < painel `z-[40]` < **lightbox `z-[41]`**
    // < casca de modal `z-[42]` < toast `z-[45]`.
    // ⚠️ `z-[41]` JÁ EXISTE no projeto (`PlanejadorFull/Gaveta.jsx`) — outro
    // módulo, outra tela. **Não é colisão**, e isca de "z-[41] inexistente" seria
    // FALSA.
    const classes = conjunto(LIGHTBOX.CLASSES_RAIZ);
    assert.ok(classes.has('z-[41]'), 'a raiz perdeu o `z-[41]`');
    for (const proibido of ['z-[90]', 'z-90', 'z-[70]', 'z-[100]', 'z-[120]', 'z-[50]']) {
        assert.equal(
            classes.has(proibido),
            false,
            `a raiz usa \`${proibido}\`: é o número do PROTÓTIPO, e esta fase REMAPEIA preservando a ORDEM`,
        );
    }

    // ⚠️⚠️ `font-pub` NA RAIZ do que é portado (REND-05): conteúdo portado **não
    // herda a fonte** (ele sai da subárvore e volta a herdar do `document.body`,
    // que é `font-sans`). Sem a classe o lightbox perde a Geist **em silêncio**,
    // com render verde.
    assert.ok(classes.has('font-pub'), 'a raiz do PORTAL perdeu `font-pub`: a Geist sai em silêncio (REND-05)');
    assert.ok(classes.has('text-pub-txt'), 'a raiz perdeu `text-pub-txt`');

    // E a raiz RENDERIZADA tem o mesmo conjunto — o literal sozinho não prova que
    // o componente o usa.
    const itens = arvore(render(LIGHTBOX.RaizDoLightbox, { children: null, lateral: null }));
    assert.ok(itens.length > 0, 'a raiz não desenhou elemento nenhum');
    assert.deepEqual(classesDe(itens[0]), conjunto(LIGHTBOX.CLASSES_RAIZ), 'a raiz RENDERIZADA não usa `CLASSES_RAIZ`');
});

test('LB-189-18 — a PROFUNDIDADE: marcador DENTRO do zoom, setas FILHAS DO PALCO, miniaturas IRMÃS da caixa 189_03', () => {
    // ⚠️⚠️ ESTRUTURA SÓ SE PEGA MEDINDO PROFUNDIDADE. Medido no protótipo
    // (`node` contando aberturas/fechamentos de `:726` a `:746`):
    //   `:727` raiz → `:728` palco → `:729` título | `:730` caixa → `:731` camada
    //   do zoom → `:732` imagem + `:733` MARCADORES ; `:736` faixa de miniaturas e
    //   `:741`/`:742` SETAS são IRMÃS da caixa, dentro do PALCO; `:744` a lateral é
    //   IRMÃ do palco.
    const html = render(LIGHTBOX.PalcoDoLightbox, {
        imagens: IMAGENS, indice: 0, zoom: 2.5, origem: { x: 30, y: 70 },
        onMover: nada, onSelecionar: nada, onMoverOrigem: nada,
    });
    const itens = arvore(html);

    const palco = itens.find((e) => classesDe(e).has('px-[70px]'));
    const caixa = itens.find((e) => classesDe(e).has('w-[min(78vh,100%)]'));
    const camada = itens.find((e) => e.estilo !== null && /transform:\s*scale/.test(e.estilo));
    const marcadores = itens.filter((e) => classesDe(e).has('h-[26px]'));
    const faixa = itens.find((e) => e.classe === LIGHTBOX.CLASSES_FAIXA_DE_MINIATURAS);
    const setas = itens.filter((e) => classesDe(e).has('h-[44px]'));

    for (const [nome, achado] of [['palco', palco], ['caixa', caixa], ['camada do zoom', camada], ['faixa de miniaturas', faixa]]) {
        assert.ok(achado !== undefined, `não achei o elemento "${nome}" no HTML do palco`);
    }
    assert.equal(marcadores.length, 2, 'os dois marcadores da foto 1 não foram desenhados');
    assert.equal(setas.length, 2, 'as duas setas não foram desenhadas');

    // ⚠️⚠️ (1) A CAMADA DO ZOOM É FILHA DA CAIXA, e a IMAGEM e os MARCADORES são
    // filhos da CAMADA — é o que faz os marcadores ESCALAREM com o zoom. Se eles
    // fossem irmãos da camada, ficariam parados enquanto a imagem amplia, e
    // apontariam para o lugar ERRADO com todo render passando.
    assert.equal(camada.profundidade, caixa.profundidade + 1, 'a camada do zoom não é FILHA da caixa da imagem');
    for (const marcador of marcadores) {
        assert.ok(
            marcador.profundidade > camada.profundidade,
            `marcador na profundidade ${marcador.profundidade}, fora da camada do zoom (${camada.profundidade}):`
            + ' ele não escalaria com o zoom e apontaria para o lugar ERRADO',
        );
    }

    // ⚠️⚠️ (2) AS SETAS SÃO FILHAS DO PALCO, não da raiz. Elas são
    // `position:absolute` e o palco é `position:relative`: na RAIZ (que é
    // `position:fixed`, logo ancestral posicionado) o `right-[16px]` ficaria
    // medido sobre a viewport INTEIRA — a seta → sairia **debaixo da lateral de
    // 320px**.
    for (const seta of setas) {
        assert.equal(
            seta.profundidade,
            palco.profundidade + 1,
            'a seta não é FILHA do palco: `right-[16px]` passaria a medir sobre a viewport e ela sairia sob a lateral',
        );
    }

    // ⚠️ (3) A FAIXA DE MINIATURAS É IRMÃ DA CAIXA, as duas filhas do palco.
    assert.equal(faixa.profundidade, caixa.profundidade, 'a faixa de miniaturas não é IRMÃ da caixa da imagem');
    assert.equal(caixa.profundidade, palco.profundidade + 1, 'a caixa da imagem não é FILHA do palco');

    // ⚠️ E o palco é a RAIZ deste componente (a raiz do portal o recebe por slot).
    assert.equal(palco.profundidade, 0, 'o palco não é a raiz do próprio componente');
});

test('LB-189-19 — o palco: `#n · rótulo`, `n de N`, e UMA classe de cursor por estado do zoom 189_03', () => {
    const comZoom1 = render(LIGHTBOX.PalcoDoLightbox, {
        imagens: IMAGENS, indice: 0, zoom: 1, origem: null, onMover: nada, onSelecionar: nada, onMoverOrigem: nada,
    });
    const comZoom25 = render(LIGHTBOX.PalcoDoLightbox, {
        imagens: IMAGENS, indice: 0, zoom: 2.5, origem: { x: 30, y: 70 },
        onMover: nada, onSelecionar: nada, onMoverOrigem: nada,
    });

    // `:729#2` — `{{ LB.posLabel }} · {{ LB.label }}` e, ao lado, `{{ LB.count }}`.
    const lido = texto(comZoom1);
    assert.ok(lido.includes('#1 · Frente do produto'), `a linha do título não traz "#n · rótulo": ${lido.slice(0, 160)}`);
    assert.ok(lido.includes('1 de 4'), `a contagem "n de N" não está na tela: ${lido.slice(0, 160)}`);

    // ⚠️ A linha do título e o `<span>` do título, por CONJUNTO.
    const itens = arvore(comZoom1);
    const linha = itens.find((e) => e.classe === LIGHTBOX.CLASSES_LINHA_DO_TITULO);
    assert.ok(linha !== undefined, 'não achei a linha do título (`:729#1`)');
    assert.deepEqual(
        conjunto(LIGHTBOX.CLASSES_LINHA_DO_TITULO),
        conjunto('flex items-center gap-[10px] text-[13px] text-pub-txt-2'),
        'as classes da linha do título divergiram da §B3',
    );
    assert.deepEqual(
        conjunto(LIGHTBOX.CLASSES_TITULO),
        conjunto('font-semibold text-pub-txt'),
        'as classes do título divergiram da §B4',
    );

    // ⚠️⚠️ A CAIXA DA IMAGEM — §B5 — com **UMA** classe de cursor por estado, e as
    // duas medidas nos DOIS estados (um estado só passaria com o mapa invertido).
    const caixaDe = (html) => arvore(html).find((e) => classesDe(e).has('w-[min(78vh,100%)]'));
    const esperadoBase = 'relative aspect-square w-[min(78vh,100%)] overflow-hidden rounded-pub-panel bg-pub-hover text-pub-txt-off';

    assert.deepEqual(
        classesDe(caixaDe(comZoom1)),
        conjunto(`${esperadoBase} cursor-crosshair`),
        'a caixa com zoom 1× não está com `cursor-crosshair` (`:1326`)',
    );
    assert.deepEqual(
        classesDe(caixaDe(comZoom25)),
        conjunto(`${esperadoBase} cursor-zoom-out`),
        'a caixa com zoom 2,5× não está com `cursor-zoom-out` (`:1326`)',
    );

    // ⚠️ UMA classe de cursor, nunca as duas juntas.
    for (const html of [comZoom1, comZoom25]) {
        const classes = classesDe(caixaDe(html));
        const cursores = [...classes].filter((c) => c.startsWith('cursor-'));
        assert.equal(cursores.length, 1, `a caixa saiu com ${cursores.length} classes de cursor: ${cursores.join(', ')}`);
    }

    // ⚠️⚠️ A CAMADA DO ZOOM leva o `style` de `estiloDoZoom`, e NADA por classe.
    const camada = arvore(comZoom25).find((e) => e.estilo !== null && /transform:\s*scale/.test(e.estilo));
    assert.ok(camada !== undefined, 'a camada do zoom não tem `style` com `transform`');
    assert.deepEqual(classesDe(camada), conjunto('absolute inset-0'), 'as classes da camada do zoom divergiram da §B6');
    for (const trecho of ['transform:scale(2.5)', 'transform-origin:30% 70%', 'transition-duration:150ms', 'transition-timing-function:ease']) {
        assert.ok(
            camada.estilo.replace(/;\s*/g, ';').includes(trecho),
            `o \`style\` da camada não traz "${trecho}": ${camada.estilo}`,
        );
    }
});

test('LB-189-20 — a imagem é `object-contain`, e `url_arquivo` nula vira o estado DECLARADO do 188-08 189_03', () => {
    const comFoto = render(LIGHTBOX.PalcoDoLightbox, {
        imagens: IMAGENS, indice: 0, zoom: 1, origem: null, onMover: nada, onSelecionar: nada, onMoverOrigem: nada,
    });
    const semFoto = render(LIGHTBOX.PalcoDoLightbox, {
        imagens: IMAGENS, indice: 2, zoom: 1, origem: null, onMover: nada, onSelecionar: nada, onMoverOrigem: nada,
    });

    // `:732` — `<image-slot … fit="contain">` → `<img>` `object-contain`.
    const grande = imagensDo(comFoto).find((i) => i.src === '/fotos/901/arquivo');
    assert.ok(grande !== undefined, 'a imagem grande não foi desenhada com a `url_arquivo` do servidor');
    assert.ok(classesDe(grande).has('object-contain'), 'a imagem grande não é `object-contain` (`fit="contain"`, `:732`)');

    // ⚠️⚠️ `url_arquivo === null` → o MESMO estado declarado do painel (INV-11),
    // **NUNCA `<img src="">`**: string vazia num `src` faz o navegador
    // **recarregar a própria página**.
    const vazias = imagensDo(semFoto).filter((i) => i.src === '' || (i.temSrc && i.src === null));
    assert.deepEqual(vazias.map((i) => i.atributos), [], '`<img src="">` no lightbox: recarrega a própria página');
    assert.doesNotMatch(semFoto, /src=""/, '`src=""` apareceu no HTML');

    // O estado declarado É o do 188-08 — IMPORTADO, não redesenhado (régua §2).
    const itens = arvore(semFoto);
    const placeholder = itens.find((e) => e.classe === IMAGEM_GRANDE.CLASSES_PLACEHOLDER);
    assert.ok(
        placeholder !== undefined,
        'o estado de `url_arquivo` nula não usa o `CLASSES_PLACEHOLDER` do 188-08: duas regras da mesma verdade'
        + ' divergem, e a divergente é descoberta por alguém vendo dois estados diferentes na mesma tela',
    );
    assert.ok(texto(semFoto).includes('Medidas'), 'o estado nulo não mostra o rótulo da própria imagem');

    // ⚠️ ISCA DO MESMO LADO: com foto, o placeholder NÃO aparece. Sem ela, "o
    // placeholder está lá" seria satisfeito por um componente que o desenha sempre.
    const placeholderComFoto = arvore(comFoto).find((e) => e.classe === IMAGEM_GRANDE.CLASSES_PLACEHOLDER);
    assert.equal(placeholderComFoto, undefined, 'o placeholder aparece COM foto — ele está sendo desenhado sempre');
});

test('LB-189-21 — os marcadores: as classes da §B7, `left`/`top` por `style`, e coordenada adversa NÃO desenha 189_03', () => {
    const html = render(LIGHTBOX.MarcadoresDoLightbox, { marcadores: MARCADORES_DA_FOTO_1 });
    const itens = arvore(html);
    assert.equal(itens.length, 2, `foram desenhados ${itens.length} marcadores (esperado 2)`);

    // ⚠️⚠️ O MARCADOR DO LIGHTBOX **NÃO É O DO PAINEL**: `:733` é 26px/11,5px e
    // **SEM halo**; `:596` é 24px/11px e **COM** `box-shadow`. Não reusar a classe
    // do painel.
    const BASE = 'pointer-events-none absolute grid h-[26px] w-[26px] -translate-x-1/2 -translate-y-1/2'
        + ' place-items-center rounded-full border-2 border-pub-page text-[11.5px] font-[800] text-pub-page';
    assert.deepEqual(classesDe(itens[0]), conjunto(`${BASE} bg-pub-danger`), 'o marcador `vermelho` divergiu da §B7');
    assert.deepEqual(classesDe(itens[1]), conjunto(`${BASE} bg-pub-warn`), 'o marcador `ambar` divergiu da §B7');

    // ⚠️ E é DIFERENTE do marcador do painel, por conjunto — a prova de que a
    // classe do 188-08 não foi reusada.
    assert.notDeepEqual(
        conjunto(LIGHTBOX.CLASSES_MARCADOR),
        conjunto(IMAGEM_GRANDE.CLASSES_MARCADOR),
        'o marcador do lightbox ficou IGUAL ao do painel: `:733` é 26px/11,5px sem halo, `:596` é 24px/11px com halo',
    );
    assert.equal(
        [...conjunto(LIGHTBOX.CLASSES_MARCADOR)].some((c) => c.startsWith('shadow-')),
        false,
        'o marcador do lightbox ganhou halo: `:733` não declara `box-shadow`',
    );

    // ⚠️⚠️ AS COORDENADAS VÃO POR `style`, NUNCA POR CLASSE. `left-[37.5%]`
    // montado por template literal o scanner do Tailwind **não vê**: a classe fica
    // no HTML, todo render passa, e **o marcador não sai do canto**.
    assert.ok(itens[0].estilo !== null, 'o marcador não tem `style`');
    assert.match(itens[0].estilo, /left:\s*37\.5%/, `o \`left\` do 1º marcador não é 37,5%: ${itens[0].estilo}`);
    assert.match(itens[0].estilo, /top:\s*12\.25%/, `o \`top\` do 1º marcador não é 12,25%: ${itens[0].estilo}`);
    for (const item of itens) {
        const classes = [...classesDe(item)];
        const porClasse = classes.filter((c) => /^-?(left|top)-\[/.test(c));
        assert.deepEqual(porClasse, [], `coordenada por CLASSE: ${porClasse.join(', ')} — o scanner do Tailwind não a vê`);
    }

    // ⚠️ Coordenada não finita ou FORA de 0–100 → o marcador **NÃO é desenhado**:
    // marcador fantasma no canto aponta para um defeito que não está ali.
    // ⚠️ `typeof NaN === 'number'` — é por isso que a guarda é `Number.isFinite`.
    const RUINS = [
        { ordinal: 9, x: NaN, y: 10, cor: 'vermelho' },
        { ordinal: 9, x: 10, y: NaN, cor: 'vermelho' },
        { ordinal: 9, x: -1, y: 10, cor: 'vermelho' },
        { ordinal: 9, x: 101, y: 10, cor: 'vermelho' },
        { ordinal: 9, x: '10', y: 10, cor: 'vermelho' },
        { ordinal: 9, x: {}, y: 10, cor: 'vermelho' },
        { ordinal: 9, x: null, y: 10, cor: 'vermelho' },
        { ordinal: null, x: 10, y: 10, cor: 'vermelho' },
        { ordinal: NaN, x: 10, y: 10, cor: 'vermelho' },
        'não é objeto',
        null,
        7,
        [],
    ];
    for (const [posicao, ruim] of RUINS.entries()) {
        const so = render(LIGHTBOX.MarcadoresDoLightbox, { marcadores: [ruim] });
        assert.equal(arvore(so).length, 0, `o marcador adverso de indice ${posicao} na lista RUINS foi DESENHADO`);
        assert.doesNotMatch(so, /NaN|undefined|\[object Object\]/);
    }

    // ⚠️ ISCA DO MESMO LADO: a MESMA forma com os valores CERTOS desenha. Sem ela,
    // "adverso não desenha" seria satisfeito por um componente que nunca desenha.
    const bom = render(LIGHTBOX.MarcadoresDoLightbox, { marcadores: [{ ordinal: 9, x: 10, y: 10, cor: 'vermelho' }] });
    assert.equal(arvore(bom).length, 1, 'a isca do mesmo lado não desenhou — o componente pode nunca desenhar nada');

    // Lista vazia / adversa → nada, e **nenhuma casca vazia** (D4).
    for (const lista of [[], null, undefined, 'x', 7, {}]) {
        assert.equal(render(LIGHTBOX.MarcadoresDoLightbox, { marcadores: lista }), '');
    }
});

test('LB-189-22 — os NÚMEROS dos marcadores são OS MESMOS `ordinal` da lista de verificações 189_03', () => {
    // ⚠️⚠️ ESTE É O GATE DE "A PESSOA REPROVA A FOTO ERRADA". Duas numerações da
    // mesma verdade divergiriam, e aqui a divergência é exatamente isso.
    //
    // ⚠️ A fixture usa os ordinais **4 e 2** de propósito: "contar na tela" daria
    // **1 e 2**, e esta asserção pegaria. Com ordinais 1 e 2 ela não distinguiria
    // "ler" de "recontar".
    const html = render(LIGHTBOX.PalcoDoLightbox, {
        imagens: IMAGENS, indice: 0, zoom: 1, origem: null, onMover: nada, onSelecionar: nada, onMoverOrigem: nada,
    });

    const noHtml = numerosDosMarcadores(html);
    const daLista = VERIFICACOES_DA_FOTO_1
        .map((v) => v.ordinal)
        .filter((o) => typeof o === 'number' && Number.isInteger(o));

    assert.deepEqual(daLista, [4, 2], 'a fixture mudou: ela precisa de ordinais FORA da ordem 1,2 para discriminar');
    assert.deepEqual(
        noHtml,
        daLista,
        `o número no marcador (${noHtml.join(',')}) não é o da lista de verificações (${daLista.join(',')}):`
        + ' a pessoa reprovaria a foto ERRADA',
    );

    // ⚠️ E os números vêm do `marcadores[*].ordinal`, que é a MESMA fonte.
    assert.deepEqual(noHtml, MARCADORES_DA_FOTO_1.map((m) => m.ordinal), 'o ordinal foi RECONTADO em vez de lido');

    // ⚠️ ISCA: a sonda que lê o HTML VÊ um número diferente quando ele é diferente.
    // Sem isto, `deepEqual` passando seria indistinguível de leitura cega.
    const trocado = render(LIGHTBOX.MarcadoresDoLightbox, {
        marcadores: [{ ordinal: 7, x: 10, y: 10, cor: 'vermelho' }],
    });
    assert.deepEqual(numerosDosMarcadores(trocado), [7], 'a sonda de leitura dos marcadores está CEGA');
});

test('LB-189-23 — as miniaturas: UMA por imagem, a borda da seleção DO LIGHTBOX e o anel 189_03', () => {
    const html = render(LIGHTBOX.MiniaturasDoLightbox, { imagens: IMAGENS, indice: 0, onSelecionar: nada });
    const itens = arvore(html);

    const faixa = itens.find((e) => e.classe === LIGHTBOX.CLASSES_FAIXA_DE_MINIATURAS);
    assert.ok(faixa !== undefined, 'não achei a faixa de miniaturas (`:736`)');
    assert.deepEqual(conjunto(LIGHTBOX.CLASSES_FAIXA_DE_MINIATURAS), conjunto('flex gap-[6px]'), 'a faixa divergiu da §B8');

    const caixas = itens.filter((e) => classesDe(e).has('h-[56px]'));
    assert.equal(caixas.length, IMAGENS.length, `foram desenhadas ${caixas.length} miniaturas (esperado ${IMAGENS.length})`);

    // §B9 — 56×56, raio 7px, borda de 1,5px. ⚠️ A GEOMETRIA é OUTRA no painel
    // (`aspect-square rounded-[8px]` numa grade de 6 colunas): a REGRA se importa,
    // a classe de caixa não (INV-09).
    const BASE = 'relative h-[56px] w-[56px] overflow-hidden rounded-pub-control-sm border-[1.5px] bg-pub-hover-2 text-pub-txt-off';

    // ⚠️⚠️ A BORDA VEM DA SELEÇÃO **DO LIGHTBOX**, NUNCA DO `borda` CRU DO
    // SERVIDOR: ele marca a imagem **0** sempre (`FotosDoPainelService::para()` é
    // chamado SEM índice, AVISO-189-1), e o anel amarelo ficaria na primeira
    // miniatura **para sempre**, qualquer que fosse a foto aberta.
    //
    // ⚠️ A precedência só se prova COM CONFLITO: a foto 1 é `pending` **E**
    // `com_alerta` **E** selecionada → tem de sair **AMARELA** (`selecionada`
    // vence). Um estado por vez passaria com a ordem invertida.
    assert.deepEqual(
        classesDe(caixas[0]),
        conjunto(`${BASE} border-pub-accent ${GRADE.CLASSES_ANEL}`),
        'a miniatura 0 (selecionada E com alerta) não saiu amarela: a precedência de 5 ramos está invertida',
    );
    assert.deepEqual(classesDe(caixas[1]), conjunto(`${BASE} border-pub-ok`), 'a miniatura `approved` não saiu verde');
    assert.deepEqual(classesDe(caixas[2]), conjunto(`${BASE} border-pub-line-off`), 'a miniatura `regenerating` não caiu no 5º ramo');
    assert.deepEqual(classesDe(caixas[3]), conjunto(`${BASE} border-pub-line-off`), 'a miniatura `rejected` não caiu no 5º ramo');

    // ⚠️ O ANEL é ADICIONAL à borda e existe **SÓ** na selecionada.
    for (const [indice, caixa] of caixas.entries()) {
        const temAnel = classesDe(caixa).has('ring-2');
        assert.equal(temAnel, indice === 0, `o anel ${temAnel ? 'apareceu' : 'faltou'} na miniatura ${indice}`);
    }

    // ⚠️ E com OUTRA selecionada o anel ANDA — prova de que não é o `anel` cru do
    // servidor (que é `true` só na imagem 0 desta fixture).
    const outra = arvore(render(LIGHTBOX.MiniaturasDoLightbox, { imagens: IMAGENS, indice: 2, onSelecionar: nada }))
        .filter((e) => classesDe(e).has('h-[56px]'));
    assert.equal(classesDe(outra[0]).has('ring-2'), false, 'o anel ficou preso na miniatura 0 — está lendo o `anel` CRU');
    assert.equal(classesDe(outra[2]).has('ring-2'), true, 'o anel não acompanhou a seleção do LIGHTBOX');
    assert.ok(classesDe(outra[2]).has('border-pub-accent'), 'a borda não acompanhou a seleção do LIGHTBOX');

    // §B10 — o botão invisível, com `title` NÃO-VAZIO: elemento sem nome
    // acessível **passa em render e desaparece na tela**.
    const botoes = itens.filter((e) => e.tag === 'button' && classesDe(e).has('bg-transparent'));
    assert.equal(botoes.length, IMAGENS.length, 'faltou botão invisível em alguma miniatura');
    for (const botao of botoes) {
        assert.ok(typeof botao.titulo === 'string' && botao.titulo.trim() !== '', `botão de miniatura com \`title\` vazio: ${botao.atributos}`);
    }
    assert.equal(botoes[0].titulo, 'Foto 1 — frente', 'o `title` não é o `titulo` do servidor');

    // Coleção vazia / adversa → **nenhuma casca** (D4: moldura sem nada dentro).
    for (const lista of [[], null, undefined, 'x', 7, {}]) {
        assert.equal(render(LIGHTBOX.MiniaturasDoLightbox, { imagens: lista, indice: 0, onSelecionar: nada }), '');
    }
});

test('LB-189-24 — as setas: 18px, `aria-label` não-vazio, e o clique DÁ A VOLTA zerando só o zoom 189_03', () => {
    const html = render(LIGHTBOX.SetasDoLightbox, { indice: 0, total: 4, onMover: nada });
    const setas = arvore(html).filter((e) => e.tag === 'button');
    assert.equal(setas.length, 2, `foram desenhadas ${setas.length} setas (esperado 2)`);

    // §B11/§B12 — 44px, raio 50%, `text-[18px]` (o ÚNICO tamanho novo da fase,
    // liberado na wave 189-01), e o lado de cada uma.
    const BASE = 'absolute top-1/2 -mt-[22px] h-[44px] w-[44px] cursor-pointer rounded-full'
        + ' border border-pub-line-strong bg-pub-surface text-[18px] text-pub-txt';
    assert.deepEqual(classesDe(setas[0]), conjunto(`${BASE} left-[16px]`), 'a seta ← divergiu da §B11');
    assert.deepEqual(classesDe(setas[1]), conjunto(`${BASE} right-[16px]`), 'a seta → divergiu da §B12');
    for (const seta of setas) {
        assert.ok(classesDe(seta).has('text-[18px]'), 'a seta perdeu o `text-[18px]` de `:741`/`:742`');
    }

    // ⚠️ Os glifos, e o `aria-label` NÃO-VAZIO (acréscimo invisível, declarado na
    // §B11): botão com só um glifo não tem nome acessível.
    const lido = texto(html);
    assert.ok(lido.includes('←'), 'o glifo ← não está na tela');
    assert.ok(lido.includes('→'), 'o glifo → não está na tela');
    for (const seta of setas) {
        assert.ok(
            typeof seta.rotulo === 'string' && seta.rotulo.trim() !== '',
            `seta sem \`aria-label\`: ${seta.atributos}`,
        );
    }

    // ⚠️⚠️ O CLIQUE — `renderToStaticMarkup` **NÃO dispara `onClick`**, e a tela
    // preta da 187-06 foi **NO CLIQUE**. Por isso o handler é exportado.
    const { aoClicarNaSeta, aoClicarNaMiniatura, ZOOM_NORMAL } = LIGHTBOX;

    // Dá a volta para trás, e o `0` do clamp é o que a asserção RECUSA.
    const paraTras = aoClicarNaSeta(0, -1, 4);
    assert.equal(paraTras.indice, 3, 'o clique no ← não deu a volta para a ÚLTIMA foto');
    assert.notEqual(paraTras.indice, 0, 'o clique no ← clampou: é a regra do PAINEL, não a do lightbox');
    assert.equal(aoClicarNaSeta(3, 1, 4).indice, 0, 'o clique no → não deu a volta para a PRIMEIRA foto');

    // ⚠️⚠️ TROCAR DE FOTO **ZERA O ZOOM**, MAS **NÃO ZERA A ORIGEM** (`:1321`,
    // `:1327`: os dois mandam `lbZoom: 1` e **não** mexem em `lbX`/`lbY`).
    // ➡️ A prova é ESTRUTURAL: o pedido TEM a chave `zoom` e **NÃO TEM** `origem`.
    for (const pedido of [aoClicarNaSeta(0, 1, 4), aoClicarNaMiniatura(2, 4)]) {
        assert.equal(pedido.zoom, ZOOM_NORMAL, 'trocar de foto não zerou o zoom');
        assert.equal(
            Object.prototype.hasOwnProperty.call(pedido, 'origem'),
            false,
            'o pedido traz `origem`: trocar de foto ZERARIA a origem, e o protótipo a PRESERVA',
        );
        assert.deepEqual(Object.keys(pedido).sort(), ['indice', 'zoom'], 'o pedido ganhou/perdeu chave');
    }
    assert.equal(aoClicarNaMiniatura(2, 4).indice, 2, 'o clique na miniatura não levou ao índice dela');

    // Entrada adversa nos dois handlers → `null`, e **nunca lança**.
    for (const adverso of [null, undefined, NaN, '2', {}, [], true, { toString: null }]) {
        assert.equal(aoClicarNaSeta(0, 1, adverso), null, 'total adverso produziu pedido');
        assert.equal(aoClicarNaMiniatura(adverso, 4).indice, 0, 'indice adverso na miniatura nao caiu em 0');
        assert.equal(aoClicarNaMiniatura(0, adverso), null, 'total adverso produziu pedido na miniatura');
    }

    // ⚠️ Com UMA foto só a seta não tem destino: DIVERGÊNCIA DECLARADA do
    // protótipo (ele é estático e desenha sempre 6 miniaturas, nunca expressa o
    // caso de 1). D4 — *"nada nasce decorativo"*, e `onClick` vazio é a mesma
    // dívida. Precedente em produção: `GradeDeMiniaturas` (188-08) não desenha a
    // casca da grade vazia.
    assert.equal(render(LIGHTBOX.SetasDoLightbox, { indice: 0, total: 1, onMover: nada }), '');
    assert.equal(render(LIGHTBOX.SetasDoLightbox, { indice: 0, total: 0, onMover: nada }), '');

    // ⚠️ E sem handler as setas também não nascem: botão sem destino não nasce.
    assert.equal(render(LIGHTBOX.SetasDoLightbox, { indice: 0, total: 4, onMover: null }), '');

    // ⚠️ ISCA DO MESMO LADO: com 4 fotos E handler elas nascem (já medido acima,
    // repetido aqui para a ausência não ser satisfeita por "nunca desenha").
    assert.ok(render(LIGHTBOX.SetasDoLightbox, { indice: 0, total: 4, onMover: nada }).length > 100);
});

test('LB-189-25 — `aberto !== true` → `null`; sem `document` → `null` SEM lançar; o slot só aceita elemento 189_03', () => {
    // ⚠️ `aberto` tem de ser **exatamente** `true`: `'1'`, `1` e `{}` são truthy e
    // abririam o lightbox por acidente.
    for (const aberto of [false, null, undefined, 0, 1, '1', 'true', {}, [], NaN]) {
        assert.equal(
            render(LIGHTBOX.default, { ...PROPS_ABERTO, aberto }),
            '',
            `\`aberto: ${String(typeof aberto)}\` desenhou alguma coisa`,
        );
    }

    // ⚠️⚠️ SEM `document` (que é o caso DESTE processo) o composto devolve `null`
    // em vez de LANÇAR. `typeof document === 'undefined'` e **não** `!document`: a
    // segunda forma LANÇA no mesmo lugar que se quer evitar.
    assert.equal(typeof document, 'undefined', 'este processo TEM `document` — a contraprova do portal mudou de sentido');
    assert.doesNotThrow(() => render(LIGHTBOX.default, PROPS_ABERTO), 'o composto LANÇOU no render de servidor');
    assert.ok(
        FONTE_LIGHTBOX.includes("typeof document === 'undefined'"),
        'a guarda de ambiente saiu da fonte: o render de servidor volta a LANÇAR `document is not defined`',
    );
    assert.ok(FONTE_LIGHTBOX.includes('createPortal'), 'o `createPortal` saiu: o lightbox volta a ficar preso ao'
        + ' containing block que o `backdrop-filter` do `PublicadorHeader` cria para `position:fixed`');

    // ⚠️⚠️ PROP DE NÓ CHEGANDO OBJETO COMUM **MATA A PÁGINA**. O slot `lateral`
    // aceita `isValidElement(…)` e nada mais.
    for (const lateral of [{}, { a: 1 }, 'texto', 7, true, [], { toString: null }, () => {}]) {
        const html = render(LIGHTBOX.RaizDoLightbox, { children: null, lateral });
        assert.doesNotMatch(
            html,
            /\[object Object\]|NaN|undefined|Invalid Date/,
            'prop de NÓ adversa vazou para o HTML',
        );
    }
    // ⚠️ ISCA DO MESMO LADO: um elemento DE VERDADE passa pelo slot.
    const comLateral = render(LIGHTBOX.RaizDoLightbox, {
        children: null,
        lateral: React.createElement('aside', { className: 'lateral-de-teste' }, 'conteúdo da lateral'),
    });
    assert.ok(comLateral.includes('lateral-de-teste'), 'o slot `lateral` recusou um elemento legítimo');
});

test('LB-189-26 — render ADVERSO: nenhum campo derruba a página nem vaza para o HTML 189_03', () => {
    const PODRES = [null, undefined, NaN, Infinity, 0, -1, '', 'x', 7, true, false, {}, [], { toString: null }, { valueOf: null }];
    const RUIM = /\[object Object\]|NaN|undefined|Invalid Date/;

    // (1) Campo por campo, na imagem selecionada.
    const CAMPOS = [
        'id', 'imagem_id', 'posicao', 'posicao_rotulo', 'rotulo', 'titulo', 'de_ia', 'tipo_rotulo',
        'principal', 'grupo', 'grupo_geral', 'estado', 'estado_rotulo', 'estado_cor', 'estado_dot',
        'com_alerta', 'aprovada', 'aceita_acao', 'borda', 'anel', 'url_arquivo', 'marcadores', 'verificacoes',
    ];
    for (const campo of CAMPOS) {
        for (const podre of PODRES) {
            const imagens = [{ ...IMAGENS[0], [campo]: podre }, IMAGENS[1]];
            let html = null;
            assert.doesNotThrow(() => {
                html = render(LIGHTBOX.PalcoDoLightbox, {
                    imagens, indice: 0, zoom: 1, origem: null, onMover: nada, onSelecionar: nada, onMoverOrigem: nada,
                });
            }, `o palco LANÇOU com \`${campo}\` adverso`);
            assert.doesNotMatch(html, RUIM, `\`${campo}\` adverso vazou para o HTML`);
        }
    }

    // (2) A COLEÇÃO inteira, e o índice.
    for (const imagens of PODRES) {
        for (const indice of PODRES) {
            assert.doesNotThrow(() => {
                render(LIGHTBOX.PalcoDoLightbox, {
                    imagens, indice, zoom: 1, origem: null, onMover: nada, onSelecionar: nada, onMoverOrigem: nada,
                });
            }, 'o palco LANÇOU com a coleção/índice adversos');
        }
    }

    // (3) Objeto comum NO MEIO da coleção (o item que não é imagem).
    for (const meio of PODRES) {
        const html = render(LIGHTBOX.PalcoDoLightbox, {
            imagens: [IMAGENS[0], meio, IMAGENS[1]], indice: 0, zoom: 1, origem: null,
            onMover: nada, onSelecionar: nada, onMoverOrigem: nada,
        });
        assert.doesNotMatch(html, RUIM, 'item adverso no meio da coleção vazou para o HTML');
    }

    // (4) O ZOOM e a ORIGEM adversos.
    for (const zoom of PODRES) {
        for (const origem of [null, {}, { x: NaN, y: 10 }, { x: {}, y: [] }, 'x', 7, []]) {
            const html = render(LIGHTBOX.PalcoDoLightbox, {
                imagens: IMAGENS, indice: 0, zoom, origem, onMover: nada, onSelecionar: nada, onMoverOrigem: nada,
            });
            assert.doesNotMatch(html, RUIM, 'zoom/origem adversos vazaram para o HTML');
        }
    }

    // (5) A PROP INTEIRA nula — as cinco peças, sem prop nenhuma.
    for (const nome of ['RaizDoLightbox', 'PalcoDoLightbox', 'MarcadoresDoLightbox', 'MiniaturasDoLightbox', 'SetasDoLightbox']) {
        let html = null;
        assert.doesNotThrow(() => { html = render(LIGHTBOX[nome], {}); }, `${nome} LANÇOU sem prop nenhuma`);
        assert.doesNotMatch(html, RUIM, `${nome} vazou campo adverso sem prop nenhuma`);
        assert.doesNotThrow(() => render(LIGHTBOX[nome], undefined), `${nome} LANÇOU com props \`undefined\``);
    }

    // ⚠️ ISCA DO MESMO LADO: a fixture BOA produz HTML de verdade. Sem ela, todo
    // `doesNotMatch` acima seria satisfeito por um componente que devolve `''`.
    const bom = render(LIGHTBOX.PalcoDoLightbox, {
        imagens: IMAGENS, indice: 0, zoom: 1, origem: null, onMover: nada, onSelecionar: nada, onMoverOrigem: nada,
    });
    assert.ok(bom.length > 500, `a fixture boa saiu com ${bom.length} bytes: os `
        + 'render adversos acima poderiam estar passando sobre HTML vazio');

    // ⚠️ E a mensagem de asserção NUNCA usa `String()`/`JSON.stringify()` sobre o
    // valor adverso: `String({toString:null})` **LANÇA** dentro do próprio gate.
    assert.doesNotMatch(FONTE_LIGHTBOX, /\bString\s*\(/, '`String(` voltou ao `.jsx` do lightbox');
});

test('LB-189-27 — adverso NO CLIQUE e NO MOVIMENTO DO CURSOR (os handlers exportados) 189_03', () => {
    const { aoMoverOCursor, aoClicarNaSeta, aoClicarNaMiniatura } = LIGHTBOX;

    // ⚠️⚠️ `renderToStaticMarkup` **NÃO DISPARA EVENTO**, e a TELA PRETA da 187-06
    // foi **NO CLIQUE**. É por isso que os três handlers são named exports e
    // exercidos aqui, um a um, com entrada adversa.
    const EVENTOS_PODRES = [
        null, undefined, 'evento', 7, true, [], {}, { toString: null },
        { currentTarget: null },
        { currentTarget: {} },
        { currentTarget: 'x' },
        { currentTarget: { getBoundingClientRect: null } },
        { currentTarget: { getBoundingClientRect: 'não é função' } },
        { currentTarget: { getBoundingClientRect: () => null } },
        { currentTarget: { getBoundingClientRect: () => ({}) } },
        { currentTarget: { getBoundingClientRect: () => ({ left: 0, top: 0, width: 0, height: 0 }) } },
        { clientX: NaN, clientY: NaN, currentTarget: { getBoundingClientRect: () => ({ left: 0, top: 0, width: 10, height: 10 }) } },
    ];
    for (const evento of EVENTOS_PODRES) {
        assert.doesNotThrow(() => aoMoverOCursor(evento, 2.5), 'o movimento do cursor LANÇOU com evento adverso');
        assert.equal(aoMoverOCursor(evento, 2.5), null, 'evento adverso produziu origem');
    }

    // ⚠️ `:1329` — **o cursor só move a origem COM ZOOM > 1**. Com 1× o movimento
    // é ignorado, e é o protótipo que manda (`if (S.lbZoom <= 1) return;`).
    const bom = {
        clientX: 150,
        clientY: 50,
        currentTarget: { getBoundingClientRect: () => ({ left: 100, top: 0, width: 200, height: 200 }) },
    };
    assert.deepEqual(aoMoverOCursor(bom, 2.5), { x: 25, y: 25 }, 'o movimento com zoom 2,5× não produziu a origem');
    assert.equal(aoMoverOCursor(bom, 1), null, 'o movimento com zoom 1× moveu a origem — `:1329` o IGNORA');
    for (const zoom of [null, undefined, NaN, '2.5', {}, [], 0, -1]) {
        assert.equal(aoMoverOCursor(bom, zoom), null, 'zoom adverso deixou o movimento passar');
    }

    // ⚠️ E o CLIQUE nos dois handlers, com entrada adversa — nunca lança.
    for (const a of [null, undefined, NaN, 'x', {}, [], true, { toString: null }]) {
        assert.doesNotThrow(() => aoClicarNaSeta(a, a, a), 'o clique na seta LANÇOU');
        assert.doesNotThrow(() => aoClicarNaMiniatura(a, a), 'o clique na miniatura LANÇOU');
    }

    // ⚠️⚠️ **O MOVIMENTO NA TELA NÃO É PROVADO AQUI, E ISSO É DECLARADO.** O que
    // esta asserção prova é a FUNÇÃO que decide a origem; que o `onMouseMove`
    // dispare, que o navegador anime os 150 ms e que a imagem acompanhe o cursor
    // é a conferência visual da conta 459 (plano 189-08).
    // ⚠️ Premissa inobservável se TRAVA, não se descarta: a guarda `zoom <= 1` que
    // só roda no navegador fica travada aqui por IGUALDADE DE FONTE — e isto é
    // **proxy de FONTE**, NÃO prova de comportamento.
    assert.ok(
        FONTE_LIGHTBOX.includes('onMouseMove'),
        'o `onMouseMove` saiu da caixa da imagem (`:730`): o zoom deixaria de seguir o cursor, e NENHUM teste'
        + ' deste arquivo pegaria isso em runtime — é só por esta igualdade de FONTE que o gate o trava',
    );
});
