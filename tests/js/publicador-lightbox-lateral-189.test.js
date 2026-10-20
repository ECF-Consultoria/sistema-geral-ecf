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
// Fase 189, plano 05 — A LATERAL DE 320px DO LIGHTBOX: a casca, o
// "Fechar · Esc", os metadados e a original de referência (tarefa 1); as
// verificações numeradas, o botão de zoom e Reprovar / Aprovar foto (tarefa 2).
//
// ⚠️⚠️ DE CADA BLOCO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR: "o que
// precisaria estar errado no mundo para ele falhar?" — **"Nada" é ACHADO.**
//
//  1. CONJUNTO    → `includes`/`substring` casa PREFIXO e já mordeu cinco vezes
//                   neste projeto (`w-[560px]` × `max-w-[560px]`; `h-[64px]` ×
//                   `min-h-[64px]`; `border` × `border-l`). Aqui toda classe é
//                   comparada por **igualdade de CONJUNTO**, com isca do
//                   quase-igual.
//  2. ÁRVORE      → `els[0]` mede o IRMÃO ERRADO — quatro gates deste projeto
//                   caíram nisso. A ordem dos pares de metadados é lida por
//                   **árvore com ÍNDICE ABSOLUTO**, nunca por `indexOf` de
//                   classes.
//  3. ORDEM       → trocar Origem/Posição/Estado de lugar, ou pendurar um
//                   separador vazio. A asserção cobra a SEQUÊNCIA de rótulos.
//  4. PRINCIPAL   → `principal` por truthiness genérica: `'1'`, `1` e `{}` são
//                   truthy e escreveriam " · principal" numa foto que **não é** a
//                   principal. A comparação é `=== true`, e a isca passa os três.
//  5. COR         → uma SEGUNDA cópia do mapa `estado_cor → classe`. Duas
//                   verdades sobre o mesmo estado divergem, e aqui a divergência
//                   é *"a pessoa vê verde numa foto que não está aprovada"* —
//                   e **aprovar já ENFILEIRA e não há desfazer**. Prova por
//                   **render cruzado** com o `SeloDeEstado` do 188-08.
//  6. ORIGINAL    → mostrar a `url_arquivo` da IMAGEM DE IA na caixa da original
//                   (T-189-32). A asserção prova `src` da original **diferente**
//                   da da imagem de IA, com isca cega: a URL da IA tem de EXISTIR
//                   na fixture, senão "diferente" não prova nada.
//  7. URL NULA    → `<img src="">` — *"string vazia num `src` faz o navegador
//                   recarregar a própria página"*. Caso REAL do servidor
//                   (`disponivel: true` com `url_arquivo: null`), e a resposta é
//                   o estado declarado do 188-08 (INV-11), IMPORTADO.
//  8. IMG-13      → a tela REESCREVER o texto do estado declarado. `rotulo`,
//                   `apoio` e `icone` são **LIDOS da prop** (o 189-02 os tira de
//                   `PubImagemReferencia`); gate de ausência do literal na fonte
//                   SEM COMENTÁRIO, e o render o MOSTRA.
//  9. MENTIRA     → desenhar "original não disponível" sobre uma foto que **É** a
//                   original. IMG-13 fala de CRIATIVO gerado sem vínculo.
// 10. OUTRA FASE  → "Comparar lado a lado na revisão detalhada" (`:756`, Fase
//                   190) e "✦ Gerar novamente com IA" (`:762`, Fase 192)
//                   desenhados — nem desabilitados, nem com `onClick` vazio
//                   (D-189-3). Gate de ausência **com ISCA nas duas direções**.
//                   ⚠️⚠️ **E a leitura é SEM COMENTÁRIO**: o docblock do `.jsx`
//                   CITA os dois literais para explicar por que não os desenha.
//                   Ler com comentário reprovaria o próprio conserto — a família
//                   medida em `feedback_gate_que_reprova_pelo_proprio_conserto`.
// 11. ORDINAL     → renumerar na tela. O número é o `ordinal` da prop, e se a
//                   lista contar 1,2,3 enquanto o marcador diz 4, **a pessoa
//                   reprova a foto errada**. As fixtures usam ordinais `4` e `2`
//                   — "contar" daria 1,2 e o gate pega (tarefa 2).
// 12. COLADO      → o número dentro do nó de texto (`…automática2`). Gate de
//                   ausência da forma COLADA, com isca que NÃO acusa o número
//                   desenhado separado (tarefa 2).
// 13. CABEÇALHO   → o literal `VERIFICAÇÃO AUTOMÁTICA · APOIO` DUAS vezes, ou
//                   ZERO com `verificacoes` não-vazia (PERGUNTA-189-A (a)). Gate
//                   de contagem EXATA no HTML, nos dois sentidos (tarefa 2).
// 14. TRAVA       → ação oferecida em imagem `regenerating` ou em oferta fora do
//                   `live` (T-189-30, IMG-09). `podeAgirNaFoto` é função pura
//                   exportada com comparação ESTRITA, e a matriz cobre
//                   6 estados × `aoVivo` × `aceita_acao` × handler (tarefa 2).
// 15. ESCRITA     → `router.post(`/`axios.`/`fetch(` neste arquivo (T-189-35). A
//                   escrita é do painel, pelo `escreverNoPainel` (188-07)
//                   (tarefa 2).
// 16. ADVERSO     → qualquer campo chegando objeto/`NaN`/array/nulo/ausente, a
//                   prop inteira nula, **e NO CLIQUE**. A TELA PRETA voltou SEIS
//                   vezes nesta milestone, a última **no clique**:
//                   `String(valor)`, `hasOwnProperty` e qualquer `obj[chave]`
//                   LANÇAM com `{toString: null}`.
// 17. MANIFESTO   → `vocabulario-tamanhos` fora de LINHA PRÓPRIA ou sem a forma
//                   `>=`, e o `.jsx` fora de UMA das DUAS listas (`ARQUIVOS_NOVOS`
//                   e `JSX_DEPOIS_DA_187`).
// 18. FANTASMA    → caminho citado que não existe no disco: sonda de AUSÊNCIA
//                   sobre arquivo inexistente passa CALADA. São DEZ fantasmas
//                   nesta milestone, TRÊS vindos de briefing.
//
// ⚠️⚠️ O QUE ESTE ARQUIVO **NÃO PROVA**: não há navegador nesta máquina, o
// `mysqld` local está parado, o banco não tem empresa com `ml_token` (a rota dá
// 404) e `pub_aprovacao_ofertas` está vazia. *"A tela mostra X"* aqui significa
// SEMPRE *"o HTML gerado contém X"*. O clique é exercido chamando o HANDLER
// EXPORTADO — `renderToStaticMarkup` **não dispara evento**.
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
        `.pub-lateral189-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';
const REL_LATERAL = `${DIR_APROV}/LateralDoLightbox.jsx`;
const REL_LIGHTBOX = `${DIR_APROV}/LightboxDaImagem.jsx`;
const REL_BLOCO = `${DIR_APROV}/BlocoDaImagemSelecionada.jsx`;
const REL_IMAGEM = `${DIR_APROV}/ImagemGrandeDoPainel.jsx`;
const REL_GATE_VOCABULARIO = 'tests/js/publicador-vocabulario-novo.test.js';
const REL_GATE_FECHAMENTO = 'tests/js/publicador-fila-fechamento-187.test.js';
const REL_MANIFESTO = 'tests/gates/contagens-189-05.txt';
const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

// ⚠️⚠️ ANTI-FANTASMA — `test -e` em CADA caminho citado. São DEZ caminhos
// fantasmas nesta milestone, TRÊS deles vindos de briefing. Sonda de AUSÊNCIA
// sobre arquivo inexistente passa CALADA.
// ⚠️ O protótipo fica FORA desta lista de propósito: ele **não é versionado** e o
// bloco que depende dele degrada para `skip` EXPLÍCITO e NOMEADO.
const CAMINHOS_CITADOS = [
    REL_LATERAL, REL_LIGHTBOX, REL_BLOCO, REL_IMAGEM,
    REL_GATE_VOCABULARIO, REL_GATE_FECHAMENTO, REL_MANIFESTO,
];

/** ⚠️ A ISCA do anti-fantasma: tem de continuar AUSENTE (`189-REGUA.md` §9 (c)). */
const ISCA_AUSENTE = 'resources/js/Pages/Mlb/NaoExiste9z7.jsx';

// ⚠️ A fonte SEM comentário: a prosa em pt-BR deste projeto cita os próprios
// identificadores e os próprios literais, e **palavra no docblock reprova pelo
// próprio conserto**.
const FONTE_LATERAL = lerSemComentarios(REL_LATERAL);

/** ⚠️ O CRU, para as iscas que provam que a leitura SEM comentário não é cega. */
const CRU_LATERAL = fs.readFileSync(path.resolve(RAIZ, REL_LATERAL), 'utf8');

const FONTE_GATE_VOCABULARIO = lerSemComentarios(REL_GATE_VOCABULARIO);
const FONTE_GATE_FECHAMENTO = lerSemComentarios(REL_GATE_FECHAMENTO);
const TEXTO_MANIFESTO = fs.readFileSync(path.resolve(RAIZ, REL_MANIFESTO), 'utf8');

const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

// ⚠️⚠️ TODOS os bundles ANTES do primeiro `test()` — o `after()` do `node --test`
// já matou um arquivo inteiro **sem nenhum teste falhar**.
// ⚠️ Os TRÊS módulos vizinhos são montados porque a régua §2 manda **IMPORTAR** o
// que a 188/189-03 já desenha, e o gate compara por RENDER CRUZADO:
//   · `BlocoDaImagemSelecionada` (188-08) — o cabeçalho APOIO e o ordinal (INV-07);
//   · `ImagemGrandeDoPainel`     (188-08) — a cor do estado (INV-08) e o estado de
//                                            `url_arquivo` nula (INV-11);
//   · `LightboxDaImagem`         (189-03) — os DOIS rótulos do zoom e o palco com
//                                            os marcadores (a coincidência de números).
const LATERAL = await montar(REL_LATERAL);
const LIGHTBOX = await montar(REL_LIGHTBOX);
const BLOCO = await montar(REL_BLOCO);
const IMAGEM_GRANDE = await montar(REL_IMAGEM);

// ═══ OS LEITORES DE HTML ═══════════════════════════════════════════════════

/**
 * A árvore de elementos do HTML, com PROFUNDIDADE e ÍNDICE ABSOLUTO.
 *
 * ⚠️⚠️ **NUNCA `indexOf(tagDeAbertura)`**: ele pega o IRMÃO ERRADO quando dois
 * elementos têm a MESMA string de classes — **quatro gates deste projeto estavam
 * medindo o irmão errado**.
 */
function arvore(html) {
    const itens = [];
    let profundidade = 0;
    const re = /<(\/?)(div|span|button|aside|section|p)\b([^>]*?)(\/?)>/g;
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
            });
            if (!autofechada) profundidade += 1;
        }
        m = re.exec(html);
    }
    return itens;
}

/** As imagens `<img>` do HTML, com `src`, `class` e se o atributo EXISTE. */
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

/** O texto IMEDIATO de cada `<span>` folha, na ordem do HTML. */
function spansDeTexto(html) {
    return [...html.matchAll(/<span\b[^>]*>([^<]*)<\/span>/g)].map((m) => m[1]);
}

const nada = () => {};

// ═══ AS FIXTURES — ⚠️⚠️ ELAS DISCRIMINAM DE PROPÓSITO ═════════════════════
//
// ⚠️ Na wave 187-06 as duas mutações sobreviventes eram falha da FIXTURE, não da
// asserção (uma oferta só não distinguia nada).
// ➡️ Aqui: ordinais `4` e `2` (NÃO 1 e 2 — "contar" daria 1,2 e o gate pega), uma
// imagem de IA `pending` COM alerta, uma Original `approved`, uma de IA
// `regenerating` com `url_arquivo` NULA, e as TRÊS formas de `originais` com
// URLs **diferentes** da URL da imagem de IA.

/** ⚠️ Os ordinais são 4 e 2 — NÃO 1 e 2: "contar" daria 1,2 e o gate pegaria. */
const VERIFICACOES = [
    { severidade: 'ok', texto: 'Fundo limpo e sem ruído', glifo: '✓', cor: 'verde', ordinal: null },
    { severidade: 'high', texto: 'Texto sobreposto ilegível no canto', glifo: '!', cor: 'vermelho', ordinal: 4 },
    { severidade: 'med', texto: 'Recorte corta a alça do produto', glifo: '!', cor: 'ambar', ordinal: 2 },
];

const MARCADORES = [
    { ordinal: 4, x: 37.5, y: 12.25, cor: 'vermelho' },
    { ordinal: 2, x: 80, y: 66.5, cor: 'ambar' },
];

/** A imagem de IA `pending`, PRINCIPAL, com alerta e com arquivo. */
const IA_PENDENTE = {
    id: 11, imagem_id: 901, posicao: 1, posicao_rotulo: '#1', rotulo: 'Frente do produto',
    titulo: 'Foto 1 — frente', de_ia: true, tipo_rotulo: 'Gerada por IA', principal: true,
    grupo: 'COLOR=id:52049', grupo_geral: null, estado: 'pending', estado_rotulo: 'A conferir',
    estado_cor: 'ambar', estado_dot: 'ambar', com_alerta: true, aprovada: false, aceita_acao: true,
    borda: 'selecionada', anel: true, url_arquivo: '/fotos/901/arquivo',
    marcadores: MARCADORES, verificacoes: VERIFICACOES,
};

/** A foto ORIGINAL (upload), `approved` — ela **não** tem o bloco da original. */
const ORIGINAL_APROVADA = {
    id: 12, imagem_id: 902, posicao: 2, posicao_rotulo: '#2', rotulo: 'Lateral',
    titulo: 'Foto 2 — lateral', de_ia: false, tipo_rotulo: 'Original', principal: false,
    grupo: 'COLOR=id:52049', grupo_geral: null, estado: 'approved', estado_rotulo: 'Aprovada',
    estado_cor: 'verde', estado_dot: 'verde', com_alerta: false, aprovada: true, aceita_acao: false,
    borda: 'aprovada', anel: false, url_arquivo: '/fotos/902/arquivo',
    marcadores: [], verificacoes: [],
};

/** A imagem de IA sendo REFEITA: `aceita_acao` falso e `url_arquivo` nula (IMG-09). */
const IA_REGENERANDO = {
    id: 13, imagem_id: 903, posicao: 3, posicao_rotulo: '#3', rotulo: 'Medidas',
    titulo: 'Foto 3 — medidas', de_ia: true, tipo_rotulo: 'Gerada por IA', principal: false,
    grupo: 'COLOR=id:77001', grupo_geral: null, estado: 'regenerating',
    estado_rotulo: 'Gerando nova versão', estado_cor: 'neutra', estado_dot: 'neutra',
    com_alerta: false, aprovada: false, aceita_acao: false, borda: 'neutra', anel: false,
    url_arquivo: null, marcadores: [], verificacoes: [],
};

// ⚠️ As TRÊS formas de `lightbox.originais[<id da revisão>]`, LIDAS da
// `189-02-SUMMARY.md` §4 (confirmadas byte a byte contra
// `LightboxDoPainelService.php:254`-`:273`).

/** IA COM vínculo utilizável. ⚠️ A URL é **diferente** da da imagem de IA. */
const ORIGINAL_DISPONIVEL = { disponivel: true, url_arquivo: '/fotos/877/arquivo', quantas: 2 };

/** IA com vínculo mas SEM arquivo — caso REAL (`caminho` nulo no banco). */
const ORIGINAL_SEM_ARQUIVO = { disponivel: true, url_arquivo: null, quantas: 1 };

/** IA SEM vínculo utilizável — o estado declarado de IMG-13, LIDO do servidor. */
const ORIGINAL_SEM_VINCULO = {
    disponivel: false,
    estado: 'sem_original_de_referencia',
    rotulo: 'Original de referência não disponível',
    apoio: 'A referência desta imagem não foi guardada — ela é anterior a este registro.',
    icone: 'image-off',
    url_arquivo: null,
};

const PROPS_BASE = {
    imagem: IA_PENDENTE,
    original: ORIGINAL_DISPONIVEL,
    aoVivo: true,
    zoom: 1,
    onFechar: nada,
    onAlternarZoom: nada,
    onAprovarFoto: nada,
    onReprovar: nada,
};

const comProps = (extra) => render(LATERAL.default, { ...PROPS_BASE, ...extra });

// ═══════════════════════════════════════════════════════════════════════════
// TAREFA 1 — A CASCA, O "FECHAR · ESC", OS METADADOS E A ORIGINAL
// ═══════════════════════════════════════════════════════════════════════════

test('LAT-189-1 — os caminhos citados existem, e a isca continua AUSENTE 189_05', () => {
    const ausentes = CAMINHOS_CITADOS.filter((rel) => !existsSync(path.resolve(RAIZ, rel)));

    assert.deepEqual(ausentes, [], `caminho citado que NÃO existe: ${ausentes.join(', ')}`);

    // ⚠️ SONDA NÃO-CEGA: a lista tem de ter conteúdo, senão o `deepEqual` acima
    // passaria sobre ZERO casos.
    assert.equal(CAMINHOS_CITADOS.length, 7, 'a lista de caminhos citados encolheu');

    // ⚠️ A ISCA: um caminho que SABIDAMENTE não existe tem de ser acusado.
    assert.ok(!existsSync(path.resolve(RAIZ, ISCA_AUSENTE)), 'a isca de fantasma PASSOU a existir');
    assert.equal(
        [ISCA_AUSENTE].filter((rel) => !existsSync(path.resolve(RAIZ, rel))).length,
        1,
        'a sonda de fantasma está CEGA: ela não acusou um caminho inexistente',
    );
});

test('LAT-189-2 — as peças da lateral são named exports, em lista CONSTANTE 189_05', () => {
    // ⚠️ Lista CONSTANTE, nunca `>= N`: **piso NÃO prova PRESENÇA**.
    const PECAS = [
        'CLASSES_LATERAL',
        'CLASSES_WRAPPER_DO_FECHAR',
        'CLASSES_FECHAR',
        'CLASSES_GRADE_DE_METADADOS',
        'CLASSES_ROTULO_DE_METADADO',
        'CLASSES_VALOR_FORTE',
        'CLASSES_BLOCO_DA_ORIGINAL',
        'CLASSES_TITULO_DA_ORIGINAL',
        'CLASSES_CAIXA_DA_ORIGINAL',
        'CLASSES_SEM_ORIGINAL',
        'CLASSES_ICONE_SEM_ORIGINAL',
        'CLASSES_ROTULO_SEM_ORIGINAL',
        'CLASSES_APOIO_SEM_ORIGINAL',
        'ROTULO_FECHAR',
        'ROTULO_ORIGEM',
        'ROTULO_POSICAO',
        'ROTULO_ESTADO',
        'SUFIXO_PRINCIPAL',
        'TITULO_DA_ORIGINAL',
        'MetadadosDaImagem',
        'OriginalDeReferencia',
        'iconeDaOriginal',
        'aoClicarEmFechar',
    ];

    const faltando = PECAS.filter((nome) => LATERAL[nome] === undefined);
    assert.deepEqual(faltando, [], `named export ausente: ${faltando.join(', ')}`);

    // ⚠️ SONDA NÃO-CEGA do `deepEqual` acima.
    assert.equal(PECAS.length, 23, 'a lista CONSTANTE de peças encolheu');
    assert.equal(
        ['NaoExisteEsteExport9z7'].filter((nome) => LATERAL[nome] === undefined).length,
        1,
        'a sonda de named export está CEGA: ela não acusou um nome inexistente',
    );

    assert.equal(typeof LATERAL.default, 'function', 'o default da lateral não é componente');
    assert.equal(LATERAL.ROTULO_FECHAR, 'Fechar · Esc', '`:745` — o literal do Fechar mudou');
    assert.equal(LATERAL.ROTULO_ORIGEM, 'Origem');
    assert.equal(LATERAL.ROTULO_POSICAO, 'Posição');
    assert.equal(LATERAL.ROTULO_ESTADO, 'Estado');
    assert.equal(LATERAL.SUFIXO_PRINCIPAL, ' · principal', '`:748` — o sufixo é MINÚSCULO e com ` · `');
    assert.equal(LATERAL.TITULO_DA_ORIGINAL, 'Original de referência', '`:754`');
});

test('LAT-189-3 — `CLASSES_LATERAL` por igualdade de CONJUNTO (§C1), com a isca do quase-igual 189_05', () => {
    const esperado = conjunto(
        'flex flex-col gap-[14px] overflow-y-auto border-l border-pub-line-subtle bg-pub-sunken p-[18px]',
    );

    // ⚠️ SONDA NÃO-CEGA antes do `deepEqual`.
    assert.equal(esperado.size, 8, 'o conjunto esperado da §C1 encolheu');

    assert.deepEqual(conjunto(LATERAL.CLASSES_LATERAL), esperado, '§C1 — `:744` divergiu');

    // ⚠️⚠️ A ISCA DO QUASE-IGUAL, nas DUAS direções: `includes` casaria PREFIXO
    // (`border` × `border-l`, `p-[18px]` × `px-[18px]`) e já mordeu CINCO vezes
    // neste projeto. A igualdade de CONJUNTO recusa os dois.
    assert.notDeepEqual(
        conjunto('flex flex-col gap-[14px] overflow-y-auto border border-pub-line-subtle bg-pub-sunken p-[18px]'),
        esperado,
        'a sonda de conjunto está CEGA: `border` passou por `border-l`',
    );
    assert.notDeepEqual(
        conjunto('flex flex-col gap-[14px] overflow-y-auto border-l border-pub-line-subtle bg-pub-sunken px-[18px]'),
        esperado,
        'a sonda de conjunto está CEGA: `px-[18px]` passou por `p-[18px]`',
    );

    // A raiz é um `<aside>`, e é ela que carrega as classes da §C1.
    const html = comProps({});
    const raiz = arvore(html)[0];
    assert.equal(raiz.tag, 'aside', 'a raiz da lateral não é `<aside>` (`:744`)');
    assert.deepEqual(classesDe(raiz), esperado, 'a raiz não leva `CLASSES_LATERAL`');

    // ⚠️ Nenhum `z-` na lateral: ela é FILHA da `RaizDoLightbox` (já `z-[41]`), e
    // um `z-` aqui criaria contexto de empilhamento dentro do lightbox.
    assert.doesNotMatch(LATERAL.CLASSES_LATERAL, /\bz-\[/, 'a lateral declarou `z-` própria');
});

test('LAT-189-4 — "Fechar · Esc": `nowrap` + `cursor-pointer`, e só nasce com handler FUNÇÃO 189_05', () => {
    const esperado = conjunto(
        'h-[30px] cursor-pointer whitespace-nowrap rounded-pub-control-sm border border-pub-line-strong '
        + 'bg-transparent px-[10px] text-[12px] text-pub-fase1',
    );
    assert.equal(esperado.size, 10, 'o conjunto esperado da §C3 encolheu');
    assert.deepEqual(conjunto(LATERAL.CLASSES_FECHAR), esperado, '§C3 — `:745#2` divergiu');
    assert.deepEqual(
        conjunto(LATERAL.CLASSES_WRAPPER_DO_FECHAR),
        conjunto('flex justify-end'),
        '§C2 — `:745#1` divergiu',
    );

    // ⚠️ `white-space:nowrap` é a PRIMEIRA propriedade de `:745#2`, e a omissão
    // que a Fase 187 cometeu SETE vezes.
    assert.ok(esperado.has('whitespace-nowrap'), 'o `nowrap` de `:745#2` sumiu da prescrição');

    const comHandler = comProps({ onFechar: nada });
    const botoes = arvore(comHandler).filter((e) => e.tag === 'button');
    const fechar = botoes.filter((b) => classesDe(b).has('text-pub-fase1') && classesDe(b).has('h-[30px]'));
    assert.equal(fechar.length, 1, 'o "Fechar · Esc" não é EXATAMENTE um botão');
    assert.deepEqual(classesDe(fechar[0]), esperado, 'o botão de fechar não leva `CLASSES_FECHAR`');
    assert.match(comHandler, /Fechar · Esc/, 'o literal `Fechar · Esc` não foi para o HTML');

    // ⚠️⚠️ D4 — BOTÃO SEM DESTINO NÃO NASCE. `onClick` vazio e `disabled`
    // decorativo são a mesma dívida, e "desabilitado com explicação" foi
    // OFERECIDO ao usuário e RECUSADO.
    for (const handler of [null, undefined, 'nao-sou-funcao', 42, {}, []]) {
        const html = comProps({ onFechar: handler });
        assert.doesNotMatch(html, /Fechar · Esc/, 'o "Fechar · Esc" nasceu SEM handler função');
        assert.doesNotMatch(html, /disabled/, 'apareceu `disabled` decorativo');
    }

    // ⚠️ A ISCA DA SONDA ACIMA: com handler função o literal ESTÁ lá — sem isto a
    // asserção de ausência passaria mesmo com a lateral inteira vazia.
    assert.match(comProps({ onFechar: nada }), /Fechar · Esc/, 'a sonda do Fechar está CEGA');
});

test('LAT-189-5 — a grade de metadados: §C4, e a ORDEM lida por ÁRVORE com índice ABSOLUTO 189_05', () => {
    const esperado = conjunto('grid grid-cols-[110px_minmax(0,1fr)] gap-y-[9px] text-[12.5px]');
    assert.equal(esperado.size, 4, 'o conjunto esperado da §C4 encolheu');
    assert.deepEqual(conjunto(LATERAL.CLASSES_GRADE_DE_METADADOS), esperado, '§C4 — `:746` divergiu');

    const html = render(LATERAL.MetadadosDaImagem, { imagem: IA_PENDENTE });
    const nos = arvore(html);

    // ⚠️ ÍNDICE ABSOLUTO: o nó 0 é a grade; os pares vêm na sequência.
    assert.equal(nos[0].tag, 'div', 'a grade de metadados não é o nó 0');
    assert.deepEqual(classesDe(nos[0]), esperado, 'o nó 0 não é a grade da §C4');

    const filhos = nos.filter((n) => n.profundidade === 1);
    const textos = spansDeTexto(html);

    // ⚠️⚠️ A ORDEM É O INVARIANTE. `:747`-`:750` desenha QUATRO pares
    // (Origem, Posição, Variação, Estado); a linha **Variação** NÃO é desenhada
    // aqui — INV-10/ACHADO A3 do `189-CONTRATO-188.md`: o 188-08 **não desenha
    // variação nenhuma** (a chave do servidor é CANÔNICA, `COLOR=id:52049`, e o
    // rótulo humano é `private static` em território VETADO), e a régua §2 manda
    // *"a 189 USA O QUE O 188-08 USAR"*. Decisão do usuário de 19/10: seguir sem
    // o nome. ➡️ TRÊS pares = SEIS `<span>`.
    assert.equal(filhos.length, 6, 'a grade não tem exatamente TRÊS pares rótulo/valor');
    assert.deepEqual(
        [textos[0], textos[2], textos[4]],
        ['Origem', 'Posição', 'Estado'],
        'a ORDEM dos rótulos de metadados divergiu (§C5/§C6/§C8)',
    );

    // ⚠️ Os rótulos levam `text-pub-txt-4` (`:747`/`:748`/`:750#1`).
    for (const i of [0, 2, 4]) {
        assert.deepEqual(
            classesDe(filhos[i]),
            conjunto('text-pub-txt-4'),
            `o rótulo de índice ${i} não leva a classe da §C5`,
        );
    }

    // ⚠️ O valor de Origem é `font-semibold` (`:747#2`); o de Posição é SEM
    // estilo próprio (§H2: herda `text-[12.5px]` da grade).
    assert.deepEqual(classesDe(filhos[1]), conjunto('font-semibold'), '§C5 — o valor de Origem perdeu o peso');
    assert.deepEqual(classesDe(filhos[3]), new Set(), '§H2 — o valor de Posição ganhou estilo que o protótipo não tem');
    assert.equal(textos[1], 'Gerada por IA', 'Origem não é o `tipo_rotulo`');

    // ⚠️⚠️ A CHAVE CANÔNICA NUNCA VAI PARA A TELA (VOC-06, jargão). Recortado à
    // lista CONSTANTE dos arquivos DESTE plano — `grupo` é campo legítimo do
    // payload, e gate largo reprovaria código certo.
    const tudo = texto(comProps({}));
    for (const jargao of ['COLOR=', 'id:52049', 'grupo_geral', 'cor=preto']) {
        assert.ok(!tudo.includes(jargao), `a chave canônica "${jargao}" foi para a tela`);
    }
    // ⚠️ A ISCA: a fixture TEM a chave canônica, senão a sonda acima seria cega.
    assert.equal(IA_PENDENTE.grupo, 'COLOR=id:52049', 'a fixture perdeu a chave canônica e a sonda ficou cega');
});

test('LAT-189-6 — Posição + " · principal" SÓ com `principal === true` (truthiness RECUSADA) 189_05', () => {
    const comPrincipal = render(LATERAL.MetadadosDaImagem, { imagem: IA_PENDENTE });
    assert.match(comPrincipal, /#1 · principal/, '`:748` — o sufixo da principal não apareceu');

    // ⚠️⚠️ TRUTHINESS GENÉRICA É RECUSADA: `'1'`, `1`, `{}` e `[]` são truthy e
    // escreveriam " · principal" numa foto que **não é** a principal.
    for (const valor of [false, null, undefined, 0, '', '1', 1, {}, [], 'true']) {
        const html = render(LATERAL.MetadadosDaImagem, { imagem: { ...IA_PENDENTE, principal: valor } });
        assert.doesNotMatch(html, / · principal/, 'o sufixo da principal saiu por truthiness');
        assert.match(html, /#1/, 'a posição sumiu junto com o sufixo');
    }

    // ⚠️ A ISCA da sonda acima: com `true` o sufixo ESTÁ lá.
    assert.match(
        render(LATERAL.MetadadosDaImagem, { imagem: { ...IA_PENDENTE, principal: true } }),
        / · principal/,
        'a sonda do sufixo está CEGA',
    );

    // Foto não-principal: a posição sai sozinha, sem separador pendurado.
    const html = render(LATERAL.MetadadosDaImagem, { imagem: ORIGINAL_APROVADA });
    assert.match(html, /#2/, 'a posição da foto 2 sumiu');
    assert.doesNotMatch(html, /#2 ·/, 'sobrou um separador pendurado na posição');
});

test('LAT-189-7 — Estado: o `estado_rotulo` com a MESMA classe de cor do selo do 188-08 (render CRUZADO) 189_05', () => {
    for (const imagem of [IA_PENDENTE, ORIGINAL_APROVADA, IA_REGENERANDO]) {
        const daLateral = render(LATERAL.MetadadosDaImagem, { imagem });
        const doSelo = render(IMAGEM_GRANDE.SeloDeEstado, { imagem });

        // ⚠️⚠️ RENDER CRUZADO — a MESMA imagem nos DOIS componentes. Uma segunda
        // cópia do mapa `estado_cor → classe` divergiria, e a divergência é
        // *"verde numa foto que não está aprovada"*.
        const classeDoSelo = [...classesDe(arvore(doSelo)[0])].filter((c) => c.startsWith('text-pub-') && c.endsWith('-txt'));
        assert.equal(classeDoSelo.length, 1, 'o selo do 188-08 não deu EXATAMENTE uma classe de cor de texto');

        const valorDoEstado = arvore(daLateral).filter((n) => n.profundidade === 1).at(-1);
        assert.deepEqual(
            classesDe(valorDoEstado),
            conjunto(`font-semibold ${classeDoSelo[0]}`),
            `§C8 — a cor do estado "${imagem.estado}" divergiu da do selo do 188-08`,
        );
        assert.ok(daLateral.includes(imagem.estado_rotulo), 'o `estado_rotulo` não foi para a tela');
    }

    // ⚠️⚠️ CHAVE DESCONHECIDA → NEUTRA, **NUNCA VERDE**: *"afirmar aprovação que
    // não existe é o pior lado do erro"*, porque aprovar JÁ ENFILEIRA.
    for (const chave of ['inventada', '', null, 42, {}, [], 'VERDE']) {
        const html = render(LATERAL.MetadadosDaImagem, {
            imagem: { ...IA_PENDENTE, estado_cor: chave, estado_rotulo: 'A conferir' },
        });
        assert.doesNotMatch(html, /text-pub-ok-txt/, 'chave de cor desconhecida caiu no VERDE');
        assert.match(html, /text-pub-neutral-txt/, 'chave de cor desconhecida não caiu na NEUTRA');
    }

    // ⚠️ A ISCA: a chave `verde` de verdade DÁ o verde — senão a sonda acima
    // estaria apenas proibindo uma classe que nunca aparece.
    assert.match(
        render(LATERAL.MetadadosDaImagem, { imagem: ORIGINAL_APROVADA }),
        /text-pub-ok-txt/,
        'a sonda da cor está CEGA: nem a chave `verde` produz o verde',
    );

    // ⚠️ `estado_rotulo` vazio → o par Estado **não** nasce (pílula muda é D4).
    const sem = render(LATERAL.MetadadosDaImagem, { imagem: { ...IA_PENDENTE, estado_rotulo: '  ' } });
    assert.doesNotMatch(sem, /Estado/, 'o rótulo "Estado" nasceu sem valor nenhum');
});

test('LAT-189-8 — a original de IA: `<img object-contain>` na caixa §C11, com `src` ≠ o da imagem de IA 189_05', () => {
    const esperadoCaixa = conjunto('relative aspect-square overflow-hidden rounded-[8px] bg-pub-hover-2 text-pub-txt-off');
    assert.equal(esperadoCaixa.size, 6, 'o conjunto esperado da §C11 encolheu');
    assert.deepEqual(conjunto(LATERAL.CLASSES_CAIXA_DA_ORIGINAL), esperadoCaixa, '§C11 — `:755` divergiu');
    assert.deepEqual(
        conjunto(LATERAL.CLASSES_BLOCO_DA_ORIGINAL),
        conjunto('flex flex-col gap-[8px]'),
        '§C9 — `:753` divergiu',
    );
    assert.deepEqual(
        conjunto(LATERAL.CLASSES_TITULO_DA_ORIGINAL),
        conjunto('text-[12px] text-pub-txt-4'),
        '§C10 — `:754` divergiu',
    );

    const html = render(LATERAL.OriginalDeReferencia, { imagem: IA_PENDENTE, original: ORIGINAL_DISPONIVEL });

    assert.match(html, /Original de referência/, '§C10 — o título da original não apareceu');

    const imagens = imagensDo(html);
    assert.equal(imagens.length, 1, 'a caixa da original não tem EXATAMENTE uma `<img>`');
    assert.ok(classesDe(imagens[0]).has('object-contain'), '§H1 — a original não é `object-contain`');

    // ⚠️⚠️ T-189-32 — A IMAGEM DE IA MOSTRADA COMO "ORIGINAL". O `src` tem de ser
    // o da ORIGINAL e **diferente** do da imagem de IA.
    assert.equal(imagens[0].src, ORIGINAL_DISPONIVEL.url_arquivo, 'o `src` não é a `url_arquivo` da ORIGINAL');
    assert.notEqual(imagens[0].src, IA_PENDENTE.url_arquivo, 'a caixa da original mostrou a imagem de IA');

    // ⚠️ ISCA CEGA: a URL da imagem de IA tem de EXISTIR na fixture, senão
    // "diferente" não provaria nada (foi o conserto da isca do 189-02).
    assert.equal(typeof IA_PENDENTE.url_arquivo, 'string', 'a fixture perdeu a URL da imagem de IA');
    assert.notEqual(IA_PENDENTE.url_arquivo, ORIGINAL_DISPONIVEL.url_arquivo, 'a fixture não discrimina as duas URLs');

    // ⚠️ A caixa é a §C11 e a `<img>` está DENTRO dela (profundidade, não aparência).
    const nos = arvore(html);
    const caixa = nos.filter((n) => classesDe(n).has('rounded-[8px]'));
    assert.equal(caixa.length, 1, 'a caixa da original não é EXATAMENTE um elemento');
    assert.deepEqual(classesDe(caixa[0]), esperadoCaixa, 'a caixa não leva `CLASSES_CAIXA_DA_ORIGINAL`');

    // ⚠️ O `quantas` do servidor NÃO vira texto de tela: ele existe para a Fase
    // 190 (comparação) e um "2" solto na lateral é jargão.
    assert.doesNotMatch(texto(html), /\b2\b/, 'o `quantas` do servidor foi desenhado');
});

test('LAT-189-9 — `disponivel: true` com `url_arquivo: null` → o estado de arquivo ausente do 188-08 (INV-11) 189_05', () => {
    const html = render(LATERAL.OriginalDeReferencia, { imagem: IA_PENDENTE, original: ORIGINAL_SEM_ARQUIVO });

    // ⚠️⚠️ NUNCA `<img src="">` — *"string vazia num `src` faz o navegador
    // recarregar a própria página"*.
    assert.equal(imagensDo(html).length, 0, 'com `url_arquivo` nula nasceu uma `<img>`');
    assert.doesNotMatch(html, /src="/, 'apareceu um atributo `src` com arquivo ausente');

    // ⚠️ O estado é o MESMO do painel: `CLASSES_PLACEHOLDER`, IMPORTADA do 188-08
    // (INV-11) — não uma segunda regra.
    const nos = arvore(html);
    const placeholder = nos.filter((n) => classesDe(n).has('place-items-center'));
    assert.equal(placeholder.length, 1, 'o estado de arquivo ausente não é EXATAMENTE um elemento');
    assert.deepEqual(
        classesDe(placeholder[0]),
        conjunto(IMAGEM_GRANDE.CLASSES_PLACEHOLDER),
        'INV-11 — o estado de arquivo ausente não é o IMPORTADO do 188-08',
    );

    // ⚠️ E ele NÃO é caixa vazia: tem texto.
    assert.ok(texto(html).length > 0, 'o estado de arquivo ausente saiu VAZIO');
    assert.match(html, /Original de referência/, 'o estado de arquivo ausente ficou sem texto nenhum');

    // ⚠️ E nunca o estado IMG-13: aqui HÁ vínculo, só falta o arquivo.
    assert.doesNotMatch(html, /não disponível/, 'arquivo ausente foi lido como SEM VÍNCULO (IMG-13)');
});

test('LAT-189-10 — `disponivel: false` → ícone `ImageOff` + `rotulo` + `apoio` LIDOS da prop (§H4) 189_05', () => {
    const html = render(LATERAL.OriginalDeReferencia, { imagem: IA_PENDENTE, original: ORIGINAL_SEM_VINCULO });

    // ⚠️⚠️ OS TEXTOS SÃO DO SERVIDOR. A tela NÃO os reescreve.
    assert.ok(html.includes(ORIGINAL_SEM_VINCULO.rotulo), '§H4 — o `rotulo` da prop não foi para a tela');
    assert.ok(html.includes(ORIGINAL_SEM_VINCULO.apoio), '§H4 — o `apoio` da prop não foi para a tela');

    // ⚠️ VOC-05 pede ícone **E** texto. O ícone é um `<svg>` do `lucide-react`.
    assert.match(html, /<svg/, '§H4 — o ícone do estado IMG-13 não foi desenhado');

    // ⚠️ A GEOMETRIA é a MESMA caixa da §C11 (a lateral não "pula" quando o
    // estado troca).
    const caixa = arvore(html).filter((n) => classesDe(n).has('rounded-[8px]'));
    assert.equal(caixa.length, 1, 'o estado IMG-13 saiu FORA da caixa da §C11');

    const estado = arvore(html).filter((n) => classesDe(n).has('justify-center') && classesDe(n).has('text-center'));
    assert.equal(estado.length, 1, 'o bloco do estado IMG-13 não é EXATAMENTE um elemento');
    assert.deepEqual(
        classesDe(estado[0]),
        conjunto('absolute inset-0 flex flex-col items-center justify-center gap-[6px] p-[12px] text-center'),
        '§H4 — as classes escolhidas (SEM medida no protótipo) divergiram',
    );

    // ⚠️⚠️ CHAVE DE ÍCONE DESCONHECIDA → SEM ÍCONE, **COM O TEXTO**. O texto é o
    // que carrega o sentido; e `obj[chave]` cru LANÇA com `{toString: null}`.
    for (const chave of ['nao-existe', '', null, undefined, 42, {}, [], { toString: null }, 'constructor', '__proto__']) {
        const adverso = render(LATERAL.OriginalDeReferencia, {
            imagem: IA_PENDENTE,
            original: { ...ORIGINAL_SEM_VINCULO, icone: chave },
        });
        assert.ok(adverso.includes(ORIGINAL_SEM_VINCULO.rotulo), 'chave de ícone adversa APAGOU o texto');
        assert.doesNotMatch(adverso, /\[object Object\]|NaN|undefined|Invalid Date/, 'chave de ícone adversa vazou para o HTML');
    }

    // ⚠️ A ISCA do mapa: `image-off` de verdade RESOLVE o ícone, e uma chave
    // inventada NÃO — senão o `<svg>` acima poderia estar vindo de qualquer coisa.
    assert.notEqual(LATERAL.iconeDaOriginal('image-off'), null, 'o mapa de ícones não resolve `image-off`');
    assert.equal(LATERAL.iconeDaOriginal('nao-existe'), null, 'o mapa de ícones resolveu uma chave inventada');
    assert.equal(LATERAL.iconeDaOriginal({ toString: null }), null, 'o mapa de ícones aceitou chave que LANÇA');

    // ⚠️ E o "0" que o `quantas` ausente poderia virar: nunca.
    assert.doesNotMatch(texto(html), /(^|\s)0(\s|$)/, 'apareceu um "0" solto no estado IMG-13');
});

test('LAT-189-11 — imagem Original (ou `originais[id] === null`) → o bloco §C9 NÃO existe 189_05', () => {
    // ⚠️⚠️ IMG-13 fala de CRIATIVO gerado sem vínculo. Mostrar "original não
    // disponível" sobre uma foto que **É** a original seria MENTIRA.
    const casos = [
        ['upload com `originais[id] === null`', { imagem: ORIGINAL_APROVADA, original: null }],
        ['upload com bloco por engano', { imagem: ORIGINAL_APROVADA, original: ORIGINAL_SEM_VINCULO }],
        ['IA sem entrada em `originais`', { imagem: IA_PENDENTE, original: null }],
        ['IA com `originais` string', { imagem: IA_PENDENTE, original: '/fotos/1/arquivo' }],
        ['`de_ia` por truthiness', { imagem: { ...ORIGINAL_APROVADA, de_ia: '1' }, original: ORIGINAL_DISPONIVEL }],
    ];

    for (const [nome, props] of casos) {
        const html = render(LATERAL.OriginalDeReferencia, props);
        assert.equal(html, '', `${nome}: o bloco da original nasceu quando não devia`);
    }

    // ⚠️ A ISCA: com IA **e** bloco de verdade ele NASCE — senão o `equal('')`
    // acima passaria com o componente inteiro morto.
    assert.notEqual(
        render(LATERAL.OriginalDeReferencia, { imagem: IA_PENDENTE, original: ORIGINAL_DISPONIVEL }),
        '',
        'a sonda do bloco da original está CEGA: ela não nasce nem no caso bom',
    );

    // E no componente inteiro: a foto Original não traz o título do bloco.
    const inteiro = comProps({ imagem: ORIGINAL_APROVADA, original: null });
    assert.doesNotMatch(inteiro, /Original de referência/, 'a foto Original ganhou o bloco da original');
    assert.match(inteiro, /Origem/, 'a lateral da foto Original perdeu os metadados');
});

test('LAT-189-12 — "Comparar lado a lado" e o texto do IMG-13 FORA do código, com ISCA nas duas direções 189_05', () => {
    // ⚠️⚠️ D-189-3 — `:756` é da **Fase 190**. Nem desabilitado, nem com `onClick`
    // vazio, nem "em breve".
    const PROIBIDOS = [
        ['Comparar lado a lado', 'o botão de `:756` é da Fase 190 (D-189-3)'],
        ['Original de referência não disponível', 'o texto do IMG-13 é do SERVIDOR (`PubImagemReferencia`), não da tela'],
    ];

    for (const [literal, razao] of PROIBIDOS) {
        assert.ok(!FONTE_LATERAL.includes(literal), `${literal}: ${razao}`);
        // ⚠️⚠️ A ISCA DO LEITOR: o docblock do `.jsx` **CITA** o literal para
        // explicar a ausência, e o gate continua VERDE porque lê SEM COMENTÁRIO.
        assert.ok(CRU_LATERAL.includes(literal), `o docblock parou de CITAR "${literal}" — a isca do leitor morreu`);
    }

    // ⚠️ A ISCA NA OUTRA DIREÇÃO: uma fonte SINTÉTICA com o literal É acusada.
    const sintetica = 'const x = 1;\nconst b = "Comparar lado a lado na revisão detalhada";\n';
    assert.ok(
        PROIBIDOS.some(([literal]) => sintetica.includes(literal)),
        'a sonda de ausência está CEGA: ela não acusou uma fonte com o literal',
    );
    const sintetica2 = '// Comparar lado a lado na revisão detalhada\nconst y = 2;\n';
    assert.ok(
        !sintetica2.replace(/(^|[^:])\/\/.*$/gm, '$1').includes('Comparar lado a lado'),
        'a leitura SEM comentário está furada: ela colheu o comentário',
    );

    // ⚠️ E no HTML renderizado, em TODOS os casos de `originais`.
    for (const original of [ORIGINAL_DISPONIVEL, ORIGINAL_SEM_ARQUIVO, ORIGINAL_SEM_VINCULO, null]) {
        const html = comProps({ original });
        assert.doesNotMatch(html, /Comparar lado a lado/, 'o botão da Fase 190 foi desenhado');
    }

    // ⚠️ E o texto do IMG-13 APARECE quando a prop o traz — a prova de que a
    // ausência acima é "não redigitar", e não "não mostrar".
    assert.ok(
        comProps({ original: ORIGINAL_SEM_VINCULO }).includes('Original de referência não disponível'),
        'o texto do IMG-13 não chegou à tela vindo da prop',
    );
});

test('LAT-189-13 — território: nada de `String(`, `localStorage`, hex cru, `font-extrabold` nem `.js` novo 189_05', () => {
    const VETADOS = [
        [/\bString\(/, '`String(` LANÇA com `{toString: null}` e há gate da 187-04 proibindo nesta região'],
        [/localStorage/, 'NADA de `localStorage` (decisão do usuário)'],
        [/#[0-9a-fA-F]{3,8}\b/, 'hex cru na tela (TOK-15): todo valor do lightbox tem token (§T)'],
        [/font-(thin|extralight|light|extrabold|black)\b/, 'o GATE-03 fecha a escala nomeada em 400/500/600/700'],
        [/\becf-(bg|card|yellow)\b/, 'FID-04 veta os tokens `ecf-*` nesta tela'],
        [/font-display/, 'FID-04 veta `font-display`'],
        [/\btext-(sm|base|lg|xs)\b/, 'FID-03: as medidas são arbitrárias (`text-[12.5px]`), nunca a escala nomeada'],
        [/duration-\[|delay-\[|ease-\[/, 'GATE-09: as três formas NÃO COMPILAM neste projeto'],
        [/\bz-\[/, 'a lateral é FILHA da `RaizDoLightbox` e não declara camada própria'],
    ];

    for (const [padrao, razao] of VETADOS) {
        assert.doesNotMatch(FONTE_LATERAL, padrao, razao);
    }

    // ⚠️ SONDA NÃO-CEGA: cada padrão tem de casar um caso que DEVE casar.
    const CONTROLES = [
        [/\bString\(/, 'const a = String(1);'],
        [/localStorage/, 'localStorage.getItem("x")'],
        [/#[0-9a-fA-F]{3,8}\b/, 'bg-[#0D0D0D]'],
        [/font-(thin|extralight|light|extrabold|black)\b/, 'font-extrabold'],
        [/\becf-(bg|card|yellow)\b/, 'bg-ecf-card'],
        [/font-display/, 'font-display'],
        [/\btext-(sm|base|lg|xs)\b/, 'text-sm'],
        [/duration-\[|delay-\[|ease-\[/, 'duration-[150ms]'],
        [/\bz-\[/, 'z-[41]'],
    ];
    for (const [padrao, controle] of CONTROLES) {
        assert.match(controle, padrao, 'uma sonda de território está CEGA');
    }
    assert.equal(VETADOS.length, CONTROLES.length, 'há padrão vetado SEM controle positivo');

    // ⚠️ O CONTROLE de `duration-`: a forma da escala NOMEADA COMPILA e é
    // permitida — sem ele a sonda poderia estar proibindo qualquer "duration".
    assert.doesNotMatch('duration-200', /duration-\[|delay-\[|ease-\[/, 'a sonda do GATE-09 proíbe a forma que COMPILA');

    // ⚠️ TODA PEÇA NOVA É `.jsx`: o `content` do `tailwind.config.js` varre
    // `*.jsx` e **NÃO** `*.js`.
    assert.ok(REL_LATERAL.endsWith('.jsx'), 'a peça nova não é `.jsx` — classe nela não geraria CSS');
    const noDisco = fs.readdirSync(path.resolve(RAIZ, DIR_APROV));
    assert.deepEqual(noDisco.filter((n) => n.endsWith('.js')), [], 'apareceu um `.js` em `aprovacao/`');
    assert.ok(noDisco.includes('LateralDoLightbox.jsx'), 'a sonda do diretório está CEGA');
});

test('LAT-189-14 — o `.jsx` está em `ARQUIVOS_NOVOS` E em `JSX_DEPOIS_DA_187`, UMA vez em cada 189_05', () => {
    // ⚠️⚠️ SÃO DUAS ESCRITURAÇÕES, e é DELIBERADO. Faltando `ARQUIVOS_NOVOS`, o
    // GATE-03 passaria POR OMISSÃO (VOC-07); faltando `JSX_DEPOIS_DA_187`, o
    // `deepEqual(noDisco, declarados)` do fechamento-187 REPROVA no meio do plano.
    // ⚠️ Um conserto anterior nesta milestone RECUSOU criar a TERCEIRA.
    const ALVO = 'LateralDoLightbox.jsx';
    const contar = (fonte) => fonte.split(ALVO).length - 1;

    assert.equal(contar(FONTE_GATE_VOCABULARIO), 1, `${ALVO} não está EXATAMENTE uma vez em ARQUIVOS_NOVOS`);
    assert.equal(contar(FONTE_GATE_FECHAMENTO), 1, `${ALVO} não está EXATAMENTE uma vez em JSX_DEPOIS_DA_187`);

    // ⚠️ SONDA NÃO-CEGA nos dois sentidos: o vizinho do 189-03 está nos dois, e um
    // nome inventado não está em nenhum.
    const contarAlvo = (fonte, alvo) => fonte.split(alvo).length - 1;
    assert.equal(contarAlvo(FONTE_GATE_VOCABULARIO, 'LightboxDaImagem.jsx'), 1, 'a sonda de ARQUIVOS_NOVOS está CEGA');
    assert.equal(contarAlvo(FONTE_GATE_FECHAMENTO, 'LightboxDaImagem.jsx'), 1, 'a sonda de JSX_DEPOIS_DA_187 está CEGA');
    assert.equal(contarAlvo(FONTE_GATE_VOCABULARIO, 'NaoExiste9z7.jsx'), 0, 'a sonda achou um nome inventado');
});

test('LAT-189-15 — o manifesto declara `vocabulario-tamanhos` em LINHA PRÓPRIA e na forma `>=` 189_05', () => {
    const linhas = TEXTO_MANIFESTO.split(/\r?\n/).filter((l) => !l.trimStart().startsWith('#') && l.trim() !== '');

    // ⚠️ LINHA PRÓPRIA: o id não pode estar embutido na linha de outro gate.
    const daVocabulario = linhas.filter((l) => l.trimStart().startsWith('vocabulario-tamanhos'));
    assert.equal(daVocabulario.length, 1, '`vocabulario-tamanhos` não está em LINHA PRÓPRIA (ou está duas vezes)');

    // ⚠️⚠️ FORMA `>=`: aquele arquivo faz 7 sub-testes por `.jsx` varrido, e a
    // contagem CRESCE POR CONSTRUÇÃO. Contagem EXATA ali é âncora frágil.
    assert.match(daVocabulario[0], /tests=>=\d+$/, '`vocabulario-tamanhos` não está na forma `>=N`');

    const piso = Number(daVocabulario[0].match(/tests=>=(\d+)$/)[1]);
    assert.ok(piso >= 688, `o piso de \`vocabulario-tamanhos\` (${piso}) é MENOR que o do 189-03 (688)`);

    // ⚠️ O outro id, com os DOIS regimes (pristino e mutado).
    const daLateral = linhas.filter((l) => l.trimStart().startsWith('lateral-do-lightbox'));
    assert.equal(daLateral.length, 1, '`lateral-do-lightbox` não está em LINHA PRÓPRIA');
    assert.match(daLateral[0], /tests=\d+,\d+$/, '`lateral-do-lightbox` não declara os DOIS regimes');
    assert.ok(daLateral[0].includes(path.posix.basename('tests/js/publicador-lightbox-lateral-189.test.js')));

    // ⚠️ ZERO id órfão: só os DOIS ids deste plano.
    assert.equal(linhas.length, 2, 'o manifesto tem id a mais ou a menos (id órfão = gate sobre zero casos)');
});

test('LAT-189-16 — FID-01 §C: os ponteiros do protótipo, re-conferidos por LEITURA DO ARQUIVO 189_05', (contexto) => {
    const abs = path.resolve(RAIZ, PROTOTIPO);

    // ⚠️⚠️ O protótipo **NÃO é versionado** (D-189-2): o bloco degrada para `skip`
    // EXPLÍCITO e NOMEADO, com o nome do arquivo ausente na mensagem.
    if (!existsSync(abs)) {
        contexto.skip(`SKIP EXPLÍCITO — ausente no disco: ${PROTOTIPO} (não versionado, D-189-2)`);
        return;
    }

    const linhas = fs.readFileSync(abs, 'utf8').split(/\r?\n/);
    const em = (n) => String(linhas[n - 1] ?? '');

    // ⚠️ Cada ponteiro julgado pela SEMÂNTICA, nunca pelo número.
    const PONTEIROS = [
        [744, /<aside style="background:#0D0D0D;border-left:1px solid #1F1F1F;padding:18px/, '§C1 — a lateral'],
        [745, /white-space:nowrap;height:30px;padding:0 10px/, '§C3 — o Fechar, com `nowrap` 1ª'],
        [745, /Fechar · Esc/, '§C3 — o literal'],
        [746, /grid-template-columns:110px minmax\(0,1fr\);row-gap:9px;font-size:12\.5px/, '§C4 — a grade'],
        [747, />Origem</, '§C5'],
        [748, />Posição</, '§C6'],
        [749, />Variação</, '§C7 — existe no protótipo, e NÃO é desenhada (INV-10)'],
        [750, />Estado</, '§C8'],
        [753, /display:flex;flex-direction:column;gap:8px/, '§C9 — o bloco da original'],
        [754, /font-size:12px;color:#71717A">Original de referência</, '§C10'],
        [755, /aspect-ratio:1\/1;border-radius:8px;overflow:hidden;background:#161616/, '§C11 — a caixa'],
        [755, /fit="contain"/, '§H1 — a original é `contain`'],
        [756, /Comparar lado a lado na revisão detalhada/, '§G — Fase 190, NÃO desenhado'],
        [760, /display:flex;gap:8px;font-size:12px;color:#D4D4D8;line-height:1\.4/, '§C12 — o item, SEM `items-start`'],
        [762, /Gerar novamente com IA/, '§G — Fase 192, NÃO desenhado'],
        [763, /\{\{ LB\.zoomLabel \}\}/, '§C14 — o botão de zoom (D-189-3a)'],
        [766, />Reprovar</, '§C16'],
        [767, />Aprovar foto</, '§C17'],
        [369, /VERIFICAÇÃO AUTOMÁTICA · APOIO/, '§H5 — o cabeçalho APOIO, do PAINEL'],
        [633, /align-items:flex-start/, '§C12 — o item do PAINEL TEM `items-start`; o do lightbox NÃO'],
    ];

    const infratores = [];
    for (const [n, padrao, nome] of PONTEIROS) {
        if (!padrao.test(em(n))) infratores.push(`:${n} (${nome})`);
    }
    assert.deepEqual(infratores, [], `ponteiro do protótipo DESLOCADO: ${infratores.join(' · ')}`);

    // ⚠️ SONDA NÃO-CEGA, nas duas direções.
    assert.equal(PONTEIROS.length, 20, 'a lista de ponteiros encolheu');
    assert.ok(!/align-items:flex-start/.test(em(760)), '`:760` do lightbox GANHOU `items-start`');
    assert.equal(em(9999), '', 'a isca de linha inexistente devolveu conteúdo');
    assert.ok(linhas.length >= 1432, `o protótipo encolheu: ${linhas.length} linhas`);
});

test('LAT-189-17 — render ADVERSO: nenhum campo derruba a página nem vaza para o HTML 189_05', () => {
    const ADVERSOS = [
        null, undefined, {}, [], NaN, Infinity, -0, 0, '', '   ', 0.5,
        { toString: null }, { valueOf: null }, Object.create(null),
        [1, 2], { a: 1 }, true, false, Symbol.iterator.toString(),
    ];

    const CAMPOS = [
        'posicao_rotulo', 'rotulo', 'titulo', 'tipo_rotulo', 'de_ia', 'principal',
        'estado', 'estado_rotulo', 'estado_cor', 'estado_dot', 'aceita_acao',
        'url_arquivo', 'verificacoes', 'marcadores', 'grupo', 'id',
    ];

    for (const campo of CAMPOS) {
        for (const valor of ADVERSOS) {
            const html = comProps({ imagem: { ...IA_PENDENTE, [campo]: valor } });
            assert.doesNotMatch(
                html,
                /\[object Object\]|NaN|undefined|Invalid Date/,
                `campo adverso vazou para o HTML: ${campo}`,
            );
        }
    }

    const CAMPOS_DA_ORIGINAL = ['disponivel', 'url_arquivo', 'quantas', 'estado', 'rotulo', 'apoio', 'icone'];
    for (const campo of CAMPOS_DA_ORIGINAL) {
        for (const valor of ADVERSOS) {
            for (const base of [ORIGINAL_DISPONIVEL, ORIGINAL_SEM_VINCULO, ORIGINAL_SEM_ARQUIVO]) {
                const html = comProps({ original: { ...base, [campo]: valor } });
                assert.doesNotMatch(
                    html,
                    /\[object Object\]|NaN|undefined|Invalid Date/,
                    `campo adverso da original vazou: ${campo}`,
                );
            }
        }
    }

    // ⚠️ As PROPS INTEIRAS adversas — e `imagem` nula.
    for (const valor of ADVERSOS) {
        for (const prop of ['imagem', 'original', 'aoVivo', 'zoom']) {
            const html = comProps({ [prop]: valor });
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `prop adversa: ${prop}`);
        }
    }
    assert.equal(render(LATERAL.default, {}), '', 'sem `imagem` a lateral devia sair vazia');
    assert.equal(render(LATERAL.default, { imagem: null }), '', '`imagem` nula devia sair vazia');

    // ⚠️ SONDA NÃO-CEGA: a regex de vazamento tem de CASAR um HTML que vaza.
    assert.match('<span>[object Object]</span>', /\[object Object\]|NaN|undefined|Invalid Date/, 'a sonda de vazamento está CEGA');
    assert.equal(ADVERSOS.length, 19, 'a lista de valores adversos encolheu');
    assert.equal(CAMPOS.length, 16, 'a lista de campos adversos encolheu');
});

test('LAT-189-18 — adverso NO CLIQUE do "Fechar" (o handler exportado) 189_05', () => {
    // ⚠️⚠️ `renderToStaticMarkup` **NÃO DISPARA EVENTO**, e a TELA PRETA da 187-06
    // foi **NO CLIQUE**. O clique é exercido chamando o handler EXPORTADO.
    // ⚠️ `{a = null} = {}` só cobre `undefined`, **não `null`** — e foi assim que
    // uma função passou a LANÇAR no clique.
    for (const handler of [null, undefined, 'x', 42, {}, [], false]) {
        assert.doesNotThrow(() => LATERAL.aoClicarEmFechar(handler), 'o clique em Fechar LANÇOU com handler adverso');
        assert.equal(LATERAL.aoClicarEmFechar(handler), false, 'handler adverso não devia ser "chamado"');
    }

    let chamadas = 0;
    const espiao = () => { chamadas += 1; };
    assert.equal(LATERAL.aoClicarEmFechar(espiao), true, 'o clique em Fechar não chamou o handler função');
    assert.equal(chamadas, 1, 'o handler de Fechar foi chamado um número errado de vezes');
});

// ═══════════════════════════════════════════════════════════════════════════
// TAREFA 2 — AS VERIFICAÇÕES NUMERADAS, O BOTÃO DE ZOOM E AS DUAS AÇÕES
//
// ⚠️⚠️ GATE DE ENTRADA DESTA TAREFA, conferido por COMANDO antes de ela começar:
// `grep -c "PERGUNTA-189-A: RESPONDIDA" …/189-REGUA.md` → **1**.
// ➡️ RESPONDIDA (a) SIM pelo usuário em 19/10/2026: o cabeçalho
// `VERIFICAÇÃO AUTOMÁTICA · APOIO` entra **UMA vez**, acima dos itens, com as
// MESMAS classes do cabeçalho do 188-08. Razão do usuário: *"sem o cabeçalho a
// verificação pode ser lida como VEREDICTO"*.
// ═══════════════════════════════════════════════════════════════════════════

/** Os NÚMEROS dos marcadores do PALCO (189-03), na ordem do HTML. */
function numerosDosMarcadores(html) {
    return [...html.matchAll(/<span[^>]*class="[^"]*h-\[26px\][^"]*"[^>]*>(\d+)<\/span>/g)].map((m) => Number(m[1]));
}

/** Os NÚMEROS dos ordinais da LATERAL, na ordem do HTML. */
function numerosDosOrdinais(html) {
    return [...html.matchAll(/<span[^>]*class="[^"]*font-bold[^"]*"[^>]*>(\d+)<\/span>/g)].map((m) => Number(m[1]));
}

test('LAT-189-19 — o cabeçalho APOIO entra UMA vez (PERGUNTA-189-A (a)), e ZERO com `verificacoes` vazia 189_05', () => {
    const CABECALHO = BLOCO.CABECALHO_DAS_VERIFICACOES;

    // ⚠️⚠️ O LITERAL É **IMPORTADO** do 188-08 (INV-07), nunca redigitado: duas
    // cópias do mesmo rótulo divergem, e a divergente é descoberta por alguém
    // vendo dois textos diferentes na mesma tela.
    assert.equal(CABECALHO, 'VERIFICAÇÃO AUTOMÁTICA · APOIO', 'o literal do cabeçalho do 188-08 mudou');
    assert.ok(
        !FONTE_LATERAL.includes(CABECALHO),
        'o literal do cabeçalho foi REDIGITADO na lateral — tem de vir IMPORTADO (INV-07)',
    );
    // ⚠️ A ISCA DO LEITOR: o docblock CITA o literal, e o gate acima lê SEM comentário.
    assert.ok(CRU_LATERAL.includes(CABECALHO), 'o docblock parou de CITAR o literal do cabeçalho');

    const contar = (html) => html.split(CABECALHO).length - 1;

    // ⚠️⚠️ CONTAGEM EXATA DE **UMA** OCORRÊNCIA — o gate lê o HTML RENDERIZADO,
    // não a fonte. DUAS reprovam (o cabeçalho repetido por item), ZERO reprova.
    const comItens = render(LATERAL.VerificacoesDoLightbox, { verificacoes: VERIFICACOES });
    assert.equal(contar(comItens), 1, 'o cabeçalho APOIO não aparece EXATAMENTE uma vez');

    // ⚠️ E no componente INTEIRO também — o cabeçalho não pode nascer duas vezes
    // por alguém desenhá-lo fora da peça.
    assert.equal(contar(comProps({})), 1, 'o cabeçalho APOIO aparece mais de uma vez na lateral inteira');

    // ⚠️⚠️ `verificacoes` VAZIA → nem itens nem cabeçalho. Um cabeçalho que diz
    // "APOIO" sem nada embaixo não apoia nada (D4, casca vazia).
    for (const vazia of [[], null, undefined, 'x', 42, {}, [null], [{}], [{ texto: '  ' }]]) {
        const html = render(LATERAL.VerificacoesDoLightbox, { verificacoes: vazia });
        assert.equal(contar(html), 0, 'o cabeçalho APOIO nasceu sem nenhum item');
        assert.equal(html, '', 'a lista de verificações nasceu como casca vazia');
    }

    // ⚠️ AS DUAS ISCAS DA SONDA DE CONTAGEM, nas duas direções.
    assert.equal(contar(`<p>${CABECALHO}</p><p>${CABECALHO}</p>`), 2, 'a sonda de contagem está CEGA para a duplicata');
    assert.equal(contar('<p>outra coisa</p>'), 0, 'a sonda de contagem acusou um HTML sem o literal');

    // ⚠️ As classes do cabeçalho são as do 188-08 (render CRUZADO com a peça dele).
    const doPainel = render(BLOCO.VerificacoesAutomaticas, { verificacoes: VERIFICACOES });
    const classeNoPainel = arvore(doPainel).filter((n) => classesDe(n).has('tracking-[0.08em]'));
    const classeNaLateral = arvore(comItens).filter((n) => classesDe(n).has('tracking-[0.08em]'));
    assert.equal(classeNoPainel.length, 1, 'o cabeçalho do 188-08 não é EXATAMENTE um elemento');
    assert.equal(classeNaLateral.length, 1, 'o cabeçalho da lateral não é EXATAMENTE um elemento');
    assert.deepEqual(
        classesDe(classeNaLateral[0]),
        classesDe(classeNoPainel[0]),
        '§H5 — as classes do cabeçalho divergiram das do 188-08',
    );
});

test('LAT-189-20 — o ordinal é ELEMENTO PRÓPRIO, `ok` NÃO tem número, e a forma COLADA é acusada 189_05', () => {
    const html = render(LATERAL.VerificacoesDoLightbox, { verificacoes: VERIFICACOES });

    // ⚠️⚠️ OS NÚMEROS SÃO OS `ordinal` DA PROP — **4 e 2**, não 1 e 2. "Contar"
    // daria 1,2 (o item `ok` é o primeiro da lista) e esta asserção o pegaria.
    assert.deepEqual(numerosDosOrdinais(html), [4, 2], 'os ordinais da lateral foram RECONTADOS');

    // ⚠️ `ok` (ordinal `null`) NÃO traz número — nunca `0`, nunca vazio.
    assert.equal(numerosDosOrdinais(html).length, 2, 'o item `ok` ganhou número');
    const spans = spansDeTexto(html);
    assert.ok(spans.includes('Fundo limpo e sem ruído'), 'o texto do item `ok` mudou');

    // ⚠️⚠️ A FORMA COLADA É PROIBIDA (`'…canto' + ordinal`): o texto do item tem
    // de sair EXATAMENTE como veio do servidor.
    for (const item of VERIFICACOES) {
        assert.ok(spans.includes(item.texto), `o texto "${item.texto}" mudou — número colado?`);
    }
    // ⚠️ A ISCA: a MESMA sonda acusa a forma colada, e NÃO acusa o número separado.
    assert.ok(
        !spansDeTexto('<span>Texto sobreposto ilegível no canto4</span>').includes('Texto sobreposto ilegível no canto'),
        'a sonda da forma COLADA está CEGA',
    );
    assert.ok(
        spansDeTexto('<span>4</span><span>Texto sobreposto ilegível no canto</span>').includes('Texto sobreposto ilegível no canto'),
        'a sonda acusa o número desenhado SEPARADO — ela está invertida',
    );

    // ⚠️ `ordinal: 0` NÃO desenha: `0` passa por `Number.isInteger` e viraria um
    // marcador "0" que não existe sobre a foto. A régua é inteiro POSITIVO.
    const comZero = render(LATERAL.VerificacoesDoLightbox, {
        verificacoes: [{ texto: 'Teste de ordinal zero', glifo: '!', cor: 'ambar', ordinal: 0 }],
    });
    assert.deepEqual(numerosDosOrdinais(comZero), [], '`ordinal: 0` foi desenhado');
    assert.ok(spansDeTexto(comZero).includes('Teste de ordinal zero'), '`ordinal: 0` apagou o texto do item');

    // ⚠️ As classes do ordinal são as do 188-08 (INV-07), IMPORTADAS.
    const ordinais = arvore(html).filter((n) => classesDe(n).has('font-bold'));
    assert.equal(ordinais.length, 2, 'o ordinal não é EXATAMENTE um elemento por item de alerta');
    for (const no of ordinais) {
        for (const classe of conjunto(BLOCO.CLASSES_ORDINAL)) {
            assert.ok(classesDe(no).has(classe), `o ordinal perdeu a classe do 188-08: ${classe}`);
        }
    }

    // ⚠️ E a COR do ordinal é a da SEVERIDADE — é ela que o liga ao marcador.
    assert.ok(classesDe(ordinais[0]).has('text-pub-danger-txt'), 'o ordinal `vermelho` perdeu a cor');
    assert.ok(classesDe(ordinais[1]).has('text-pub-warn-txt'), 'o ordinal `ambar` perdeu a cor');
});

test('LAT-189-21 — os NÚMEROS da lateral COINCIDEM com os dos marcadores do PALCO (critério 2) 189_05', () => {
    // ⚠️⚠️ É O CRITÉRIO 2 LITERALMENTE: *"os marcadores numerados coincidem com os
    // pontos listados na lateral"*. Se divergirem, **a pessoa reprova a foto
    // errada** — e aprovar JÁ ENFILEIRA, sem desfazer.
    const doPalco = render(LIGHTBOX.MarcadoresDoLightbox, { marcadores: IA_PENDENTE.marcadores });
    const daLateral = render(LATERAL.VerificacoesDoLightbox, { verificacoes: IA_PENDENTE.verificacoes });

    const numerosDoPalco = numerosDosMarcadores(doPalco);
    const numerosDaLateral = numerosDosOrdinais(daLateral);

    // ⚠️ SONDA NÃO-CEGA ANTES do `deepEqual`: os dois conjuntos têm de ter
    // conteúdo, senão `[] === []` passaria com os dois componentes mortos.
    assert.equal(numerosDoPalco.length, 2, 'o palco não desenhou os DOIS marcadores');
    assert.equal(numerosDaLateral.length, 2, 'a lateral não desenhou os DOIS ordinais');

    // ⚠️ IGUAIS **e NA MESMA ORDEM**.
    assert.deepEqual(numerosDaLateral, numerosDoPalco, 'os números da lateral divergiram dos do palco');
    assert.deepEqual(numerosDoPalco, [4, 2], 'os números não são os `ordinal` do servidor');

    // ⚠️ A ISCA: uma lateral que RECONTASSE daria [1, 2] — e o `deepEqual` acima
    // reprovaria. Aqui a prova de que a sonda distingue as duas coisas.
    assert.notDeepEqual([1, 2], numerosDoPalco, 'a sonda não distingue "ler o ordinal" de "contar"');
});

test('LAT-189-22 — o item do LIGHTBOX NÃO tem `items-start`; o do PAINEL tem (§C12 × `:633`) 189_05', () => {
    const esperado = conjunto('flex gap-[8px] text-[12px] leading-[1.4] text-pub-fase1');
    assert.equal(esperado.size, 5, 'o conjunto esperado da §C12 encolheu');
    assert.deepEqual(conjunto(LATERAL.CLASSES_ITEM_DA_VERIFICACAO), esperado, '§C12 — `:760#1` divergiu');

    // ⚠️⚠️ **NÃO REUSAR `CLASSES_ITEM` CRU** (INV-07): o item do PAINEL (`:633`)
    // TEM `align-items:flex-start` e o do LIGHTBOX (`:760#1`) **NÃO**. Medido nos
    // dois lados nesta wave, por `sed`.
    assert.ok(!esperado.has('items-start'), '§C12 — o item do lightbox GANHOU `items-start`');
    assert.ok(
        conjunto(BLOCO.CLASSES_ITEM).has('items-start'),
        'o item do PAINEL perdeu `items-start` — a diferença medida deixou de existir',
    );
    assert.notDeepEqual(
        conjunto(BLOCO.CLASSES_ITEM),
        esperado,
        'as duas classes de item ficaram IGUAIS: alguém reusou a do painel',
    );

    // ⚠️ E no HTML: cada item leva a classe do LIGHTBOX.
    const html = render(LATERAL.VerificacoesDoLightbox, { verificacoes: VERIFICACOES });
    const itens = arvore(html).filter((n) => n.profundidade === 1);
    assert.equal(itens.length, 4, 'a lista não tem o cabeçalho + os TRÊS itens');
    for (const item of itens.slice(1)) {
        assert.deepEqual(classesDe(item), esperado, 'um item não leva `CLASSES_ITEM_DA_VERIFICACAO`');
    }

    // ⚠️ O glifo é o IMPORTADO do 188-08 (`font-[800]`, nunca `font-extrabold`).
    const glifos = arvore(html).filter((n) => classesDe(n).has('h-[16px]') && classesDe(n).has('rounded-full'));
    assert.equal(glifos.length, 3, 'não há EXATAMENTE um glifo por item');
    for (const classe of conjunto(BLOCO.CLASSES_GLIFO)) {
        assert.ok(classesDe(glifos[0]).has(classe), `o glifo perdeu a classe do 188-08: ${classe}`);
    }
    assert.ok(conjunto(BLOCO.CLASSES_GLIFO).has('font-[800]'), 'o glifo do 188-08 deixou de ser `font-[800]`');
});

test('LAT-189-23 — o botão de zoom: §C14, os DOIS rótulos IMPORTADOS, e só com handler FUNÇÃO 189_05', () => {
    const esperado = conjunto(
        'h-[32px] cursor-pointer whitespace-nowrap rounded-[8px] border border-pub-line-strong '
        + 'bg-pub-hover-2 text-[12.5px] text-pub-txt',
    );
    assert.equal(esperado.size, 9, 'o conjunto esperado da §C14 encolheu');
    assert.deepEqual(conjunto(LATERAL.CLASSES_BOTAO_DE_ZOOM), esperado, '§C14 — `:763` divergiu');

    // ⚠️⚠️ OS DOIS LITERAIS VÊM DE `LightboxDaImagem.jsx` (189-03) — **UMA fonte
    // só**. A lateral NÃO os redigita: segunda cópia divergiria, e a divergente é
    // descoberta por alguém vendo dois textos na mesma tela.
    assert.equal(LIGHTBOX.ROTULO_AMPLIAR, 'Ampliar 2,5×', 'o rótulo do 189-03 mudou');
    assert.equal(LIGHTBOX.ROTULO_REDUZIR, 'Reduzir', 'o rótulo do 189-03 mudou');
    assert.equal(LIGHTBOX.rotuloDoZoom(1), LIGHTBOX.ROTULO_AMPLIAR);
    assert.equal(LIGHTBOX.rotuloDoZoom(2.5), LIGHTBOX.ROTULO_REDUZIR);

    for (const literal of [LIGHTBOX.ROTULO_AMPLIAR, LIGHTBOX.ROTULO_REDUZIR]) {
        assert.ok(
            !FONTE_LATERAL.includes(literal),
            `o literal "${literal}" foi REDIGITADO na lateral — tem de vir de rotuloDoZoom`,
        );
    }
    // ⚠️ E a prova de que a lateral IMPORTA as DUAS funções do 189-03 (não uma
    // terceira regra): `rotuloDoZoom` para o texto, `alternarZoom` para o clique.
    assert.match(FONTE_LATERAL, /from '\.\/LightboxDaImagem'/, 'a lateral não importa do LightboxDaImagem');
    for (const simbolo of ['rotuloDoZoom', 'alternarZoom']) {
        assert.ok(FONTE_LATERAL.includes(simbolo), `a lateral não usa ${simbolo} do 189-03`);
    }

    // ⚠️ O HTML traz o rótulo CERTO para cada escala.
    assert.ok(comProps({ zoom: 1 }).includes(LIGHTBOX.ROTULO_AMPLIAR), 'com 1x o botão não diz "Ampliar 2,5×"');
    assert.ok(comProps({ zoom: 2.5 }).includes(LIGHTBOX.ROTULO_REDUZIR), 'com 2,5x o botão não diz "Reduzir"');
    assert.ok(!comProps({ zoom: 2.5 }).includes(LIGHTBOX.ROTULO_AMPLIAR), 'com 2,5x sobrou o rótulo de ampliar');

    const botoes = arvore(comProps({ zoom: 1 })).filter((e) => e.tag === 'button' && classesDe(e).has('h-[32px]'));
    assert.equal(botoes.length, 1, 'o botão de zoom não é EXATAMENTE um');
    assert.deepEqual(classesDe(botoes[0]), esperado, 'o botão de zoom não leva CLASSES_BOTAO_DE_ZOOM');

    // ⚠️ D4 — sem handler função, o botão NÃO nasce.
    for (const handler of [null, undefined, 'x', 42, {}, []]) {
        const html = comProps({ onAlternarZoom: handler });
        assert.ok(!html.includes(LIGHTBOX.ROTULO_AMPLIAR), 'o botão de zoom nasceu SEM handler função');
        assert.doesNotMatch(html, /disabled/, 'apareceu `disabled` decorativo no botão de zoom');
    }
    assert.ok(comProps({ onAlternarZoom: nada }).includes(LIGHTBOX.ROTULO_AMPLIAR), 'a sonda do zoom está CEGA');

    // ⚠️ O CLIQUE entrega o ZOOM NOVO, calculado por `alternarZoom` do 189-03 —
    // a lateral não reimplementa o liga/desliga.
    const vistos = [];
    assert.equal(LATERAL.aoClicarNoZoom(1, (z) => vistos.push(z)), true);
    assert.equal(LATERAL.aoClicarNoZoom(2.5, (z) => vistos.push(z)), true);
    assert.deepEqual(vistos, [LIGHTBOX.ZOOM_AMPLIADO, LIGHTBOX.ZOOM_NORMAL], 'o clique no zoom não alterna como o 189-03');
    for (const handler of [null, undefined, 'x', 42, {}, []]) {
        assert.doesNotThrow(() => LATERAL.aoClicarNoZoom(1, handler), 'o clique no zoom LANÇOU com handler adverso');
        assert.equal(LATERAL.aoClicarNoZoom(1, handler), false);
    }
});

test('LAT-189-24 — `podeAgirNaFoto`: a matriz, a comparação ESTRITA e o CONFLITO (IMG-09) 189_05', () => {
    const ESTADOS = ['pending', 'approved', 'rejected', 'regenerating', 'replaced', 'removed'];

    // ⚠️⚠️ T-189-30 — A MATRIZ COMPLETA: 6 estados × `aoVivo` × `aceita_acao` ×
    // handler. Só UM ponto da matriz libera a ação.
    let liberados = 0;
    for (const estado of ESTADOS) {
        for (const aoVivo of [true, false]) {
            for (const aceita of [true, false]) {
                for (const handler of [nada, null]) {
                    const pode = LATERAL.podeAgirNaFoto({
                        aoVivo,
                        imagem: { ...IA_PENDENTE, estado, aceita_acao: aceita },
                        onAprovarFoto: handler,
                        onReprovar: handler,
                    });
                    const deveria = estado === 'pending' && aoVivo === true && aceita === true && handler === nada;
                    assert.equal(pode, deveria, `matriz: estado=${estado} aoVivo=${aoVivo} aceita=${aceita}`);
                    if (pode) liberados += 1;
                }
            }
        }
    }
    // ⚠️ SONDA NÃO-CEGA: a matriz tem de ter liberado EXATAMENTE um ponto.
    assert.equal(liberados, 1, 'a matriz liberou um número errado de pontos');
    assert.equal(ESTADOS.length, 6, 'a lista de estados encolheu');

    // ⚠️⚠️ O CONFLITO: `pending` **com** `aceita_acao: false` → SEM botões. É o
    // caso de IMG-09 (o servidor já diz que `regenerating` não aceita, e o 188-05
    // devolve **422**); esta é a tela **não oferecendo** o que o servidor recusaria.
    assert.equal(
        LATERAL.podeAgirNaFoto({ aoVivo: true, imagem: { ...IA_PENDENTE, aceita_acao: false }, onAprovarFoto: nada, onReprovar: nada }),
        false,
        'o conflito `pending` + `aceita_acao: false` liberou a ação',
    );

    // ⚠️⚠️ TRUTHINESS GENÉRICA É RECUSADA nos três campos.
    for (const valor of ['1', 1, {}, [], 'true', 'pending ', 'PENDING']) {
        assert.equal(
            LATERAL.podeAgirNaFoto({ aoVivo: valor, imagem: IA_PENDENTE, onAprovarFoto: nada, onReprovar: nada }),
            false,
            '`aoVivo` liberou por truthiness',
        );
        assert.equal(
            LATERAL.podeAgirNaFoto({ aoVivo: true, imagem: { ...IA_PENDENTE, aceita_acao: valor }, onAprovarFoto: nada, onReprovar: nada }),
            false,
            '`aceita_acao` liberou por truthiness',
        );
        assert.equal(
            LATERAL.podeAgirNaFoto({ aoVivo: true, imagem: { ...IA_PENDENTE, estado: valor }, onAprovarFoto: nada, onReprovar: nada }),
            false,
            '`estado` liberou por truthiness',
        );
    }

    // ⚠️ `{a = null} = {}` só cobre `undefined`, **NÃO `null`**: a função recebe UM
    // parâmetro e o saneia, em vez de desestruturar.
    for (const contexto of [null, undefined, 'x', 42, [], true]) {
        assert.doesNotThrow(() => LATERAL.podeAgirNaFoto(contexto), 'podeAgirNaFoto LANÇOU com contexto adverso');
        assert.equal(LATERAL.podeAgirNaFoto(contexto), false);
    }

    // ⚠️ A ISCA POSITIVA: o ponto bom da matriz libera — senão tudo acima seria
    // "a função devolve `false` sempre".
    assert.equal(
        LATERAL.podeAgirNaFoto({ aoVivo: true, imagem: IA_PENDENTE, onAprovarFoto: nada, onReprovar: nada }),
        true,
        'a sonda da trava está CEGA: nem o caso bom libera',
    );

    // ⚠️ E o `ESTADO_PENDENTE` é o IMPORTADO do 188-08 — não uma segunda cópia.
    assert.equal(BLOCO.ESTADO_PENDENTE, 'pending', 'o ESTADO_PENDENTE do 188-08 mudou');
    assert.ok(FONTE_LATERAL.includes('ESTADO_PENDENTE'), 'a lateral redigitou `pending` em vez de importar');
});

test('LAT-189-25 — Reprovar / Aprovar foto: §C16/§C17, rótulos IMPORTADOS e a imagem ATUAL no clique 189_05', () => {
    const espReprovar = conjunto(
        'h-[36px] flex-1 cursor-pointer whitespace-nowrap rounded-[8px] border border-pub-danger/[0.45] '
        + 'bg-transparent text-[13px] font-medium text-pub-danger-txt',
    );
    const espAprovar = conjunto(
        'h-[36px] flex-1 cursor-pointer whitespace-nowrap rounded-[8px] border-none bg-pub-accent '
        + 'text-[13px] font-semibold text-pub-page',
    );
    assert.equal(espReprovar.size, 11, 'o conjunto esperado da §C16 encolheu');
    assert.equal(espAprovar.size, 10, 'o conjunto esperado da §C17 encolheu');
    assert.deepEqual(conjunto(LATERAL.CLASSES_REPROVAR), espReprovar, '§C16 — `:766` divergiu');
    assert.deepEqual(conjunto(LATERAL.CLASSES_APROVAR), espAprovar, '§C17 — `:767` divergiu');
    assert.deepEqual(conjunto(LATERAL.CLASSES_LINHA_DE_ACOES), conjunto('mt-auto flex gap-[6px]'), '§C15 — `:765` divergiu');

    // ⚠️ Os dois rótulos vêm IMPORTADOS do 188-08 — uma fonte só.
    assert.equal(BLOCO.REPROVAR, 'Reprovar');
    assert.equal(BLOCO.APROVAR_FOTO, 'Aprovar foto');

    const html = comProps({});
    assert.ok(html.includes(BLOCO.REPROVAR), '"Reprovar" não foi para a tela na foto `pending` ao vivo');
    assert.ok(html.includes(BLOCO.APROVAR_FOTO), '"Aprovar foto" não foi para a tela');

    const reprovar = arvore(html).filter((e) => e.tag === 'button' && classesDe(e).has('text-pub-danger-txt'));
    const aprovar = arvore(html).filter((e) => e.tag === 'button' && classesDe(e).has('bg-pub-accent'));
    assert.equal(reprovar.length, 1, '"Reprovar" não é EXATAMENTE um botão');
    assert.equal(aprovar.length, 1, '"Aprovar foto" não é EXATAMENTE um botão');
    assert.deepEqual(classesDe(reprovar[0]), espReprovar);
    assert.deepEqual(classesDe(aprovar[0]), espAprovar);

    // ⚠️⚠️ `regenerating` (e tudo que a matriz recusa) → **SEM** os dois botões.
    for (const imagem of [IA_REGENERANDO, ORIGINAL_APROVADA]) {
        const bloqueado = comProps({ imagem, original: null });
        assert.ok(!bloqueado.includes(BLOCO.APROVAR_FOTO), `"Aprovar foto" nasceu em ${imagem.estado}`);
        assert.ok(!bloqueado.includes(BLOCO.REPROVAR), `"Reprovar" nasceu em ${imagem.estado}`);
        assert.doesNotMatch(bloqueado, /disabled/, 'apareceu `disabled` decorativo em vez da ausência');
    }
    // ⚠️ Oferta fora do `live` → idem, mesmo com a foto `pending`.
    const foraDoLive = comProps({ aoVivo: false });
    assert.ok(!foraDoLive.includes(BLOCO.APROVAR_FOTO), '"Aprovar foto" nasceu com a oferta fora do `live`');

    // ⚠️⚠️ OS HANDLERS RECEBEM A IMAGEM **ATUAL** DO LIGHTBOX — e a TELA PRETA da
    // 187-06 foi **NO CLIQUE**.
    const vistas = [];
    assert.equal(LATERAL.aoClicarEmReprovar(IA_PENDENTE, (i) => vistas.push(i)), true);
    assert.equal(LATERAL.aoClicarEmAprovar(IA_REGENERANDO, (i) => vistas.push(i)), true);
    assert.deepEqual(vistas.map((i) => i.id), [11, 13], 'os handlers não receberam a imagem ATUAL');
});

test('LAT-189-26 — ausências da Fase 192 e de ESCRITA, com ISCA nas duas direções 189_05', () => {
    // ⚠️ Recortado à lista CONSTANTE dos arquivos DESTE plano: gate largo
    // reprovaria código certo (`lucro_liquido` é campo legítimo do Portal).
    const PROIBIDOS = [
        ['Gerar novamente com IA', '`:762` é da Fase 192 (D-189-3)'],
        ['✦', 'o glifo do "Gerar novamente com IA" (Fase 192)'],
        ['router.post(', 'a escrita é do painel, pelo escreverNoPainel (188-07)'],
        ['axios.', 'idem'],
        ['fetch(', 'idem'],
        ['desfazer', 'aprovar JÁ ENFILEIRA e NÃO há desfazer'],
        ['confirmar publicação', 'vocabulário vetado das Fases 187 a 194'],
        ['cancelar aprovação', 'idem'],
        ['segundo passo', 'idem'],
        ['lucro líquido', 'o vocabulário travado é "Resultado estimado por venda"'],
        ['localStorage', 'decisão do usuário'],
    ];

    const infratores = PROIBIDOS.filter(([literal]) => FONTE_LATERAL.includes(literal));
    assert.deepEqual(infratores.map(([l, r]) => `${l} (${r})`), [], 'literal de outra fase ou de escrita no código');

    // ⚠️ SONDA NÃO-CEGA: a lista tem conteúdo e uma fonte SINTÉTICA é acusada.
    assert.equal(PROIBIDOS.length, 11, 'a lista CONSTANTE de literais proibidos encolheu');
    const sintetica = 'const b = "✦ Gerar novamente com IA"; router.post(rota); axios.get(x); fetch(y);';
    const acusados = PROIBIDOS.filter(([literal]) => sintetica.includes(literal));
    assert.equal(acusados.length, 5, 'a sonda de ausência está CEGA: ela não acusou a fonte sintética');

    // ⚠️ A ISCA DO LEITOR nos dois literais que o docblock CITA de propósito.
    for (const literal of ['Gerar novamente com IA', 'desfazer']) {
        assert.ok(CRU_LATERAL.includes(literal), `o docblock parou de CITAR "${literal}"`);
    }

    // ⚠️ E no HTML, em TODOS os estados de foto.
    for (const imagem of [IA_PENDENTE, ORIGINAL_APROVADA, IA_REGENERANDO]) {
        const html = comProps({ imagem });
        assert.doesNotMatch(html, /Gerar novamente/, 'o botão da Fase 192 foi desenhado');
        assert.doesNotMatch(html, /✦/, 'o glifo da Fase 192 apareceu');
    }
});

test('LAT-189-27 — adverso NO CLIQUE das TRÊS ações (os handlers exportados) 189_05', () => {
    // ⚠️⚠️ `renderToStaticMarkup` **NÃO DISPARA EVENTO**. A TELA PRETA voltou SEIS
    // vezes nesta milestone, a última **NO CLIQUE**: `String(valor)`,
    // `hasOwnProperty` e qualquer `obj[chave]` LANÇAM com `{toString: null}`.
    const IMAGENS_ADVERSAS = [
        null, undefined, 'x', 42, [], {}, { toString: null }, { valueOf: null },
        Object.create(null), { ...IA_PENDENTE, estado_rotulo: { toString: null } },
        { ...IA_PENDENTE, id: NaN }, { ...IA_PENDENTE, verificacoes: 'x' },
    ];
    const HANDLERS_ADVERSOS = [null, undefined, 'x', 42, {}, [], false];

    for (const imagem of IMAGENS_ADVERSAS) {
        for (const handler of [...HANDLERS_ADVERSOS, nada]) {
            for (const fn of [LATERAL.aoClicarEmReprovar, LATERAL.aoClicarEmAprovar]) {
                assert.doesNotThrow(() => fn(imagem, handler), 'o clique na ação LANÇOU com entrada adversa');
            }
        }
        // ⚠️ Imagem adversa NÃO chama o handler: a escrita iria para o lugar errado.
        let chamou = 0;
        LATERAL.aoClicarEmReprovar(imagem, () => { chamou += 1; });
        const valida = imagem !== null && typeof imagem === 'object' && !Array.isArray(imagem);
        assert.equal(chamou, valida ? 1 : 0, 'o clique chamou (ou não) o handler na hora errada');
    }

    // ⚠️ SONDA NÃO-CEGA: a imagem BOA chama exatamente uma vez.
    let boas = 0;
    assert.equal(LATERAL.aoClicarEmAprovar(IA_PENDENTE, () => { boas += 1; }), true);
    assert.equal(boas, 1, 'a sonda do clique está CEGA: nem a imagem boa chama o handler');

    // ⚠️ E o RENDER adverso da lateral inteira, com todos os handlers em jogo.
    for (const imagem of IMAGENS_ADVERSAS) {
        for (const zoom of [null, undefined, NaN, 'x', {}, 2.5, 1]) {
            const html = comProps({ imagem, zoom });
            assert.doesNotMatch(
                html,
                /\[object Object\]|NaN|undefined|Invalid Date/,
                'entrada adversa vazou para o HTML da lateral',
            );
        }
    }
    assert.equal(IMAGENS_ADVERSAS.length, 12, 'a lista de imagens adversas encolheu');
    assert.equal(HANDLERS_ADVERSOS.length, 7, 'a lista de handlers adversos encolheu');
});
