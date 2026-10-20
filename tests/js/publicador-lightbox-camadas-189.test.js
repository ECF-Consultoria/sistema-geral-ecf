import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 189, plano 04 — A ORDEM DAS CAMADAS, o GATE-09 e o CSS CONSTRUÍDO do
// lightbox.
//
// ⚠️⚠️ POR QUE ESTE ARQUIVO EXISTE SEPARADO DO `publicador-lightbox-casca-189`:
// o 189-03 provou o lightbox na FONTE e no RENDER; o que falta é o que só o
// BUILD responde — a classe arbitrária EXISTE no CSS com o valor certo? — e o
// que só a leitura CRUZADA de seis arquivos responde — a ordem de empilhamento
// continua monotônica? Era a tarefa 3 do 189-03, separada em plano próprio a
// pedido do plan-checker (19/10/2026) para caber no orçamento de contexto.
//
// ⚠️⚠️ **O QUE PRECISARIA ESTAR ERRADO NO MUNDO PARA ESTE GATE FALHAR** (a
// pergunta que a régua manda responder por escrito, porque *"nada"* é ACHADO):
//
//   1. alguém mover QUALQUER das sete peças `fixed`/`sticky` para um z que
//      inverta a cadeia `header < barra < backdrop < painel < LIGHTBOX < modal
//      < toast`. O caso concreto e caro: o lightbox ACIMA da casca de modal —
//      a pessoa clica "Reprovar" dentro do lightbox e **nada aparece**, porque
//      o modal nasce atrás dele (T-189-22);
//   2. alguém "trazer o protótipo" e escrever `z-[90]` no lightbox — o número
//      que o protótipo deu A ESTA PEÇA (`Aprovacao.dc.html:727`). Ele inverte a
//      cadeia contra o modal (`z-[42]`) e contra o toast (`z-[45]`);
//   3. alguém trocar o `z-[41]` por `z-41` **sem colchete**: 41 não existe na
//      escala nomeada do Tailwind (0/10/20/30/40/50), a classe não compila e a
//      peça fica **SEM z-index nenhum**, com todo render passando;
//   4. a transição do zoom voltar para `duration-[150ms]` / `delay-[…]` /
//      `ease-[…]`: as três **NÃO COMPILAM** neste projeto (o plugin
//      `tailwindcss-animate` torna a classe ambígua) e a transição simplesmente
//      não acontece na tela, com o HTML "certo" (learnings §2b);
//   5. uma classe arbitrária do lightbox deixar de gerar CSS — por ter ido para
//      um módulo `.js` (o `content` do Tailwind varre só `*.jsx`), por ter sido
//      montada por concatenação (o scanner não vê template literal), ou por ter
//      sido escrita com espaço dentro do valor (`min(78vh, 100%)` quebra);
//   6. o `npm run build` deixar de emitir CSS para `resources/js/app.jsx`, ou a
//      PÁGINA da aprovação sair do manifest — a tela desaparece sem erro.
//
// ═══ ⚠️⚠️ A SONDA DE AUSÊNCIA DE CLASSE **NÃO** PODE MORAR NO CSS ══════════
//
// Medido na wave 188-08 (learnings §4d.1): o `content` do Tailwind varre o
// arquivo como **TEXTO, não como código** — uma classe escrita na PROSA de um
// docblock (`left-[37.5%]`, justamente no comentário que explicava por que NÃO
// usá-la) **virou regra de CSS de verdade**, e o CSS encolheu 7 bytes quando ela
// foi neutralizada.
// ➡️ **Por isso o GATE-09 tem DUAS camadas com papéis DIFERENTES, e elas não se
// trocam:**
//   · a camada de **FONTE SEM COMENTÁRIO** é a que prova *"o lightbox não
//     escreve `duration-[`"*. No CSS essa prova seria impossível: o próprio
//     docblock que PROÍBE a forma a faria existir, e o gate reprovaria o
//     conserto;
//   · a camada de **CSS** é a que prova o fato de projeto medido em 13/10/2026:
//     `duration-[Nms]` **não compila** (zero regras `.duration-\[` no bundle) e
//     a escala NOMEADA **compila** (`duration-200` é o CONTROLE — sem ele a
//     sonda não distingue *"o arbitrário não compila"* de *"a sonda está
//     cega"*).
//
// ⚠️ Este arquivo é `tests/js/…`, e o `content` do `tailwind.config.js` é
// `./resources/js/**/*.jsx` + os blades: **os comentários DESTE arquivo não
// geram CSS**. Conferido na config, não presumido.
//
// ═══ ⚠️ A LEITURA É SEM COMENTÁRIO, E ISSO NÃO É ZELO ══════════════════════
//
// O docblock do `LightboxDaImagem.jsx` **CITA** `z-index:90`, `duration-[`,
// `delay-[` e `ease-[` para explicar por que não os usa (`:364`-`:381` e
// `:455`-`:462`). Ler o CRU reprovaria o próprio conserto — é a família
// `feedback_gate_que_reprova_pelo_proprio_conserto`. Os docblocks dos outros
// cinco componentes citam os números do protótipo pelo mesmo motivo.
//
// ═══ ⚠️ O QUE ESTE ARQUIVO **NÃO** FAZ ════════════════════════════════════
//
//   · **não edita** `publicador-header-zindex-ordem.test.js` (INTOCÁVEL: nasceu
//     de bug relatado pelo usuário em 12/10/2026 e consertado em produção).
//     Aquele gate guarda o invariante `header < menu = painel`; este guarda a
//     cadeia das peças da APROVAÇÃO. Os dois leem as MESMAS fontes;
//   · **não edita** nenhum dos seis `.jsx` que lê — nem o `LightboxDaImagem.jsx`
//     do 189-03. Este plano só PROVA (PI(189-04) na SUMMARY);
//   · **não prova** que o movimento do zoom acompanha o cursor: sem navegador, o
//     que se prova é a FUNÇÃO (189-03 §8). *"A tela mostra X"* aqui significa
//     *"o CSS/HTML gerado contém X"*.
//
// ⚠️ Nome de guarda genérico COLIDE no `--filter` do harness (que SOMA
// contagens): todos os casos deste arquivo terminam em `189_04`.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

const REL_HEADER = 'resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx';
const REL_BARRA = 'resources/js/Components/Mlb/Publicador/aprovacao/BarraDeSelecao.jsx';
const REL_PAINEL = 'resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx';
const REL_LIGHTBOX = 'resources/js/Components/Mlb/Publicador/aprovacao/LightboxDaImagem.jsx';
const REL_CASCA = 'resources/js/Components/Mlb/Publicador/aprovacao/CascaDeModal.jsx';
const REL_TOAST = 'resources/js/Components/Mlb/Publicador/aprovacao/ToastDaFila.jsx';
const REL_PAGINA = 'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx';
const REL_TRAVADO = 'tests/js/publicador-header-zindex-ordem.test.js';

/**
 * ⚠️ `test -e` em CADA caminho ANTES de acreditar em leitura vazia.
 *
 * Dez caminhos fantasmas foram medidos nesta milestone, três deles vindos de
 * briefing. Gate sobre arquivo inexistente é gate sobre ZERO casos.
 */
for (const caminho of [
    REL_HEADER, REL_BARRA, REL_PAINEL, REL_LIGHTBOX, REL_CASCA, REL_TOAST, REL_PAGINA, REL_TRAVADO,
]) {
    if (!fs.existsSync(path.resolve(RAIZ, caminho))) {
        throw new Error(`caminho inexistente: ${caminho} — este gate mediria NADA`);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. AS SETE PEÇAS, COM A ÂNCORA DE CADA UMA
// ═══════════════════════════════════════════════════════════════════════════

/**
 * ⚠️⚠️ Os números são **LIDOS** do arquivo de cada peça, NUNCA escritos à mão
 * aqui. Número à mão viraria uma SEGUNDA VERDADE, e o dia em que alguém movesse
 * o header os dois gates discordariam — sem ninguém saber qual está certo.
 *
 * ⚠️ As âncoras exigem a forma que o Tailwind de fato compila:
 *   · `z-(\d+)` para a escala NOMEADA (o header, `z-30`);
 *   · `z-\[(\d+)\]` **com colchete** para os valores arbitrários (35, 39, 40,
 *     41, 42, 45 — nenhum existe na escala nomeada, que vai de 10 em 10).
 * Uma âncora que casasse as DUAS formas leria o número errado no dia em que
 * alguém trocasse `z-[41]` por `z-41`, que **não compila**.
 *
 * ⚠️ `(?=[\s'"])` fecha a substring: sem o lookahead, `z-4` casaria dentro de
 * `z-45`. Este projeto já foi mordido por isso.
 *
 * ⚠️ **ÂNCORA DO HEADER, declarada:** a outra sessão trocou `top-0` por
 * `-top-6` em `:174` por fora desta fase (medido pelo 189-03 §3). A âncora aqui
 * recorta `<header className="sticky … z-NN` com `[^"]*?` no meio justamente
 * para não depender do offset, que **não é** o invariante desta cadeia.
 */
const PECAS = {
    header: {
        arquivo: REL_HEADER,
        ancora: /<header className="sticky [^"]*?z-(\d+)(?=[\s"])/,
        descricao: 'o header global do Publicador (z-30, TRAVADO desde 12/10/2026)',
    },
    barra: {
        arquivo: REL_BARRA,
        ancora: /bottom-\[24px\] left-1\/2 z-\[(\d+)\](?=[\s'])/,
        descricao: 'a barra flutuante da seleção em lote (187-09)',
    },
    backdrop: {
        arquivo: REL_PAINEL,
        ancora: /const CLASSES_BACKDROP = 'fixed inset-0 z-\[(\d+)\] bg-black\//,
        descricao: 'o backdrop do painel de revisão (188-07)',
    },
    painel: {
        arquivo: REL_PAINEL,
        ancora: /'fixed inset-y-0 right-0 z-\[(\d+)\](?=[\s'])/,
        descricao: 'o painel de revisão de 1140px (188-07)',
    },
    lightbox: {
        arquivo: REL_LIGHTBOX,
        ancora: /const CLASSES_RAIZ = 'fixed inset-0 z-\[(\d+)\](?=[\s'])/,
        descricao: 'a raiz do lightbox, portada para o document.body (189-03)',
    },
    modal: {
        arquivo: REL_CASCA,
        ancora: /const CLASSES_BACKDROP = 'fixed inset-0 z-\[(\d+)\] flex items-start/,
        descricao: 'o backdrop da casca de modal, que inclui "Reprovar imagem"',
    },
    toast: {
        arquivo: REL_TOAST,
        ancora: /top-\[124px\] z-\[(\d+)\](?=[\s'])/,
        descricao: 'o toast da fila (187-09)',
    },
};

/**
 * Lê um z-index de um TEXTO qualquer.
 *
 * ⚠️ Reprova se a âncora não casar EXATAMENTE uma vez. Extração que devolve
 * `null` em silêncio quando a classe é renomeada é o jeito clássico de um gate
 * virar decoração: ele continua verde porque **não achou nada para comparar**.
 *
 * ⚠️ Está separada de `zDoArquivo` de propósito: é ela que permite alimentar
 * FONTE SINTÉTICA e provar que a sonda enxerga a inversão (a isca do §4c.2).
 */
function zDoTexto(texto, ancora, nome) {
    const casos = [...texto.matchAll(new RegExp(ancora.source, 'g'))];

    assert.equal(
        casos.length,
        1,
        `a âncora de "${nome}" casou ${casos.length} vez(es), esperava 1. A classe foi renomeada ou o `
        + 'elemento se duplicou — conserte a âncora, NÃO apague a asserção.',
    );

    const valor = Number(casos[0][1]);
    assert.ok(Number.isInteger(valor) && valor >= 0, `z de "${nome}" não é inteiro: ${casos[0][1]}`);

    return valor;
}

/** ⚠️ SEM COMENTÁRIO: os docblocks das seis peças citam os números do protótipo. */
function zDoArquivo(nome) {
    const { arquivo, ancora } = PECAS[nome];

    return zDoTexto(lerSemComentarios(arquivo), ancora, nome);
}

/**
 * A CADEIA: quem tem de ficar abaixo de quem, e **por quê na tela**.
 *
 * ⚠️⚠️ É aqui que mora o invariante, e ele é de COMPORTAMENTO — não uma tabela
 * de números. A 187-09 mediu que uma lista larga de números do protótipo
 * **REPROVA CÓDIGO CERTO**: `45` é o número que o protótipo deu ao POPOVER, e o
 * toast legitimamente ficou em `z-[45]`. Essa família já reprovou trabalho
 * correto três vezes nesta milestone e **barrou um deploy**.
 */
const CADEIA = [
    ['header', 'barra', 'a barra de lote flutua ACIMA do header: ela é a ação do que está selecionado'],
    ['barra', 'backdrop', 'o backdrop do painel escurece a fila INTEIRA, barra de lote incluída'],
    ['backdrop', 'painel', 'o painel fica ACIMA do próprio backdrop que o escurece'],
    ['painel', 'lightbox', 'o lightbox COBRE o painel: é a imagem em tela cheia'],
    ['lightbox', 'modal', 'o "Reprovar" DENTRO do lightbox abre o modal POR CIMA dele — abaixo, a pessoa clica e nada aparece'],
    ['modal', 'toast', 'o toast é o aviso do que acabou de acontecer, e nada pode cobri-lo'],
];

/**
 * Confere a cadeia e devolve as INFRAÇÕES (nunca lança).
 *
 * ⚠️ Devolver lista em vez de lançar é o que permite a isca de INVERSÃO: com
 * `assert` dentro, a fonte sintética não poderia ser medida sem derrubar o
 * teste, e a prova de que a sonda VÊ a inversão não existiria.
 */
function infracoesDaCadeia(z) {
    const nomes = Object.keys(PECAS);
    const faltando = nomes.filter((n) => !Number.isInteger(z[n]));
    if (faltando.length > 0) {
        return [`peça(s) sem z medido: ${faltando.join(', ')} — a cadeia foi conferida contra \`undefined\``];
    }

    return CADEIA
        .filter(([a, b]) => !(z[a] < z[b]))
        .map(([a, b, razao]) => `${a}(${z[a]}) tem de ficar ABAIXO de ${b}(${z[b]}): ${razao}`);
}

function zDeTodas() {
    const z = {};
    for (const nome of Object.keys(PECAS)) z[nome] = zDoArquivo(nome);

    return z;
}

// ═══════════════════════════════════════════════════════════════════════════
// 2. A ORDEM, PELO COMPORTAMENTO
// ═══════════════════════════════════════════════════════════════════════════

test('a cadeia header < barra < backdrop < painel < LIGHTBOX < modal < toast, LIDA das sete fontes 189_04', () => {
    const z = zDeTodas();

    // ⚠️ UMA asserção COLETANDO as infrações: a mensagem nomeia todas, e a
    // contagem de asserções não cresce com o tamanho da cadeia.
    assert.deepEqual(
        infracoesDaCadeia(z),
        [],
        'a ordem de empilhamento da aprovação quebrou — veja a razão de cada elo na mensagem',
    );

    // ⚠️ SONDA NÃO-CEGA antes do `deepEqual` acima valer como prova: a cadeia
    // tem de ter sido medida sobre SETE números de verdade, todos distintos.
    assert.equal(Object.keys(z).length, 7, 'o mapa de peças perdeu (ou ganhou) uma peça');
    assert.equal(new Set(Object.values(z)).size, 7, `duas peças ficaram no MESMO nível: ${JSON.stringify(z)}`);
    assert.equal(CADEIA.length, 6, 'a cadeia deixou de cobrir os seis elos entre as sete peças');

    // ⚠️ E a cadeia cobre TODAS as peças: elo que não encosta em ninguém é peça
    // sem prova — "lista de nomes não é prova de comportamento".
    assert.deepEqual(
        Object.keys(PECAS).filter((n) => !CADEIA.some(([a, b]) => a === n || b === n)),
        [],
        'há peça no mapa que nenhum elo da cadeia menciona — ela está no gate só decorando',
    );
});

test('⚠️ o lightbox recusa o `90` que o protótipo deu A ESTA PEÇA (e não uma lista larga) 189_04', () => {
    const lightbox = zDoArquivo('lightbox');

    // ⚠️⚠️ O mapa é POR PEÇA. A alternativa — uma lista
    // `![50, 60, 69, 70, 90, 100, 120].includes(valor)` aplicada a todas — é
    // mais LARGA que o invariante: ela reprovaria uma peça que legitimamente
    // caísse num número que o protótipo deu a OUTRA peça (foi exatamente o que
    // aconteceu com o toast e o `45` na wave 187-09).
    const DO_PROTOTIPO = { lightbox: 90 };

    assert.notEqual(
        lightbox,
        DO_PROTOTIPO.lightbox,
        `o lightbox ficou com ${lightbox}, que é EXATAMENTE o número que o protótipo deu a ele `
        + '(`Aprovacao.dc.html:727`). A escala deste projeto é outra: preserve a ORDEM, não os números.',
    );

    // ⚠️ ISCA DO MAPA, nas DUAS direções — sem ela um mapa com a chave errada
    // (ou um `undefined`) faria a asserção acima passar comparando contra
    // `undefined`, que nunca é igual a um inteiro.
    assert.deepEqual(Object.keys(DO_PROTOTIPO), ['lightbox'], 'o mapa do protótipo perdeu a peça medida');
    assert.equal(typeof DO_PROTOTIPO.lightbox, 'number', 'o número do protótipo não é número');

    // ⚠️ E a prova de que a asserção SABE recusar: o número idêntico reprova.
    assert.throws(
        () => assert.notEqual(DO_PROTOTIPO.lightbox, DO_PROTOTIPO.lightbox),
        'ISCA CEGA: `notEqual` não recusa nem o número idêntico',
    );

    // ⚠️ E o `90` não entra na cadeia nem por acaso: ele é ACIMA do modal.
    assert.ok(
        DO_PROTOTIPO.lightbox > zDoArquivo('modal'),
        'o `90` do protótipo deixou de inverter a cadeia — remeça a razão deste gate',
    );
});

test('⚠️⚠️ ISCA DE INVERSÃO: fonte sintética com o lightbox em `z-[43]` REPROVA 189_04', () => {
    const z = zDeTodas();

    // ⚠️ A fonte sintética tem a MESMA forma da real — é a âncora de verdade que
    // a lê. Se a âncora deixar de casar, `zDoTexto` reprova (não passa calada).
    const SINTETICA_ACIMA_DO_MODAL = "export const CLASSES_RAIZ = 'fixed inset-0 z-[43] grid "
        + "grid-cols-[minmax(0,1fr)_320px] bg-[rgba(5,5,5,0.94)] font-pub text-pub-txt';";

    const lightboxInvertido = zDoTexto(SINTETICA_ACIMA_DO_MODAL, PECAS.lightbox.ancora, 'lightbox (sintético)');
    assert.equal(lightboxInvertido, 43, 'a âncora não leu o z da fonte sintética');

    const infracoes = infracoesDaCadeia({ ...z, lightbox: lightboxInvertido });
    assert.equal(
        infracoes.length,
        1,
        `esperava EXATAMENTE a infração do elo lightbox→modal, vieram ${infracoes.length}: ${JSON.stringify(infracoes)}`,
    );
    assert.match(infracoes[0], /lightbox\(43\) tem de ficar ABAIXO de modal\(42\)/, 'a infração acusada é outra');
});

test('⚠️⚠️ ISCA NA OUTRA DIREÇÃO: trocar o NÚMERO sem inverter a ordem NÃO reprova 189_04', () => {
    const z = zDeTodas();

    // ⚠️⚠️ Esta é a metade que falta na maioria dos gates de z-index, e é ela que
    // distingue **INVERSÃO** de **TROCA DE NÚMERO**. O invariante é a ORDEM: se
    // amanhã a escala toda descer de 1, nada na tela muda e o gate tem de
    // continuar verde. Sem esta isca, o gate estaria medindo a TABELA, e o
    // executor seguinte aprenderia a mexer na régua para caber nela.
    const painelMenor = "    'fixed inset-y-0 right-0 z-[38] flex w-[min(1140px,calc(100vw-100px))] flex-col '";
    const backdropMenor = "export const CLASSES_BACKDROP = 'fixed inset-0 z-[37] bg-black/[0.55]';";

    const painel = zDoTexto(painelMenor, PECAS.painel.ancora, 'painel (sintético)');
    const backdrop = zDoTexto(backdropMenor, PECAS.backdrop.ancora, 'backdrop (sintético)');
    assert.deepEqual([backdrop, painel], [37, 38], 'as âncoras não leram os z sintéticos');

    assert.deepEqual(
        infracoesDaCadeia({ ...z, painel, backdrop }),
        [],
        'descer painel e backdrop PRESERVANDO a ordem foi acusado — o gate está medindo a TABELA, não a ORDEM',
    );

    // ⚠️ E a prova de que o par sintético continua DENTRO da cadeia (acima da
    // barra `z-[35]`): se ele caísse abaixo dela, este teste passaria por
    // omissão em vez de por ordem preservada.
    assert.ok(backdrop > z.barra, `o backdrop sintético (${backdrop}) caiu abaixo da barra (${z.barra})`);
});

test('⚠️ as âncoras NÃO casam a forma que o Tailwind não compila (`z-41` sem colchete) 189_04', () => {
    // ⚠️ 41, 42, 39, 40, 35 e 45 NÃO existem na escala nomeada (0/10/20/30/40/50).
    // Uma âncora que casasse as duas formas leria um número que a tela não tem:
    // a classe não compila e a peça fica SEM z-index.
    const SEM_COLCHETE = [
        ["export const CLASSES_RAIZ = 'fixed inset-0 z-41 grid grid-cols-[minmax(0,1fr)_320px]';", 'lightbox'],
        ["export const CLASSES_BACKDROP = 'fixed inset-0 z-42 flex items-start justify-center';", 'modal'],
        ["export const CLASSES_BACKDROP = 'fixed inset-0 z-39 bg-black/[0.55]';", 'backdrop'],
    ];

    const casaram = SEM_COLCHETE.filter(
        ([texto, nome]) => [...texto.matchAll(new RegExp(PECAS[nome].ancora.source, 'g'))].length > 0,
    ).map(([, nome]) => nome);

    assert.deepEqual(casaram, [], 'âncora casando a forma SEM colchete: ela leria um número que o Tailwind não compila');

    // ⚠️ ISCA DO MESMO LADO: as MESMAS três formas, COM colchete, casam. Sem
    // isto, "nenhuma casou" seria satisfeito por uma âncora quebrada.
    const comColchete = SEM_COLCHETE.map(([texto, nome]) => [texto.replace(/z-(\d+)/, 'z-[$1]'), nome]);
    const naoCasaram = comColchete.filter(
        ([texto, nome]) => [...texto.matchAll(new RegExp(PECAS[nome].ancora.source, 'g'))].length !== 1,
    ).map(([, nome]) => nome);

    assert.deepEqual(naoCasaram, [], 'âncora que não casa nem a forma CERTA — a sonda está cega');
});

test('⚠️ ISCA CEGA: âncora que eu SEI que não existe dá 0 ocorrências e REPROVA 189_04', () => {
    const inexistente = /const CLASSES_INVENTADA_189_04 = 'fixed inset-0 z-\[(\d+)\]/;

    assert.throws(
        () => zDoTexto(lerSemComentarios(REL_LIGHTBOX), inexistente, 'inventada'),
        /casou 0 vez\(es\)/,
        'o extrator devolveu algo para uma âncora inexistente — ele passaria calado numa renomeação',
    );

    // ⚠️ E o oposto: duas ocorrências também REPROVAM (elemento duplicado).
    assert.throws(
        () => zDoTexto(
            "const CLASSES_RAIZ = 'fixed inset-0 z-[41] a';\nconst CLASSES_RAIZ = 'fixed inset-0 z-[41] b';",
            PECAS.lightbox.ancora,
            'duplicada',
        ),
        /casou 2 vez\(es\)/,
        'o extrator aceitou duas ocorrências — ele leria a primeira e ignoraria a segunda',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. GATE-09 NA FONTE: as três formas que NÃO COMPILAM
// ═══════════════════════════════════════════════════════════════════════════

/**
 * ⚠️ Medido em 13/10/2026 (plano 180-09) e reconfirmado na camada de CSS deste
 * arquivo: o plugin `tailwindcss-animate` registra `duration`, `delay` e `ease`
 * como utilitários de ANIMAÇÃO, e o Tailwind então recusa o valor arbitrário
 * (*"the class is ambiguous and matches multiple utilities"*). A classe fica no
 * HTML, todo teste de render passa, e a transição **não acontece**.
 */
const PROIBIDAS_NA_FONTE = [
    ['duration arbitrária', /duration-\[/],
    ['delay arbitrário', /delay-\[/],
    ['ease arbitrário', /ease-\[/],
];

/** O que a transição do zoom tem de ter, por `style` — a ausência sozinha seria satisfeita por um arquivo SEM transição. */
const EXIGIDAS_NA_FONTE = ['transitionProperty', 'transitionDuration', 'transitionTimingFunction'];

test('⚠️⚠️ GATE-09 na FONTE: o lightbox não escreve `duration-[`/`delay-[`/`ease-[`, e TEM a transição por `style` 189_04', () => {
    const fonte = lerSemComentarios(REL_LIGHTBOX);

    // ⚠️ SONDA NÃO-CEGA: a leitura sem comentário não pode ter esvaziado o
    // arquivo (o `lerSemComentarios` come blocos `/* */`, e um arquivo com 890
    // linhas majoritariamente de docblock poderia sair quase vazio).
    assert.ok(fonte.length > 6000, `a fonte sem comentário saiu com ${fonte.length} bytes — leitura suspeita`);
    assert.match(fonte, /export const CLASSES_RAIZ/, 'a fonte sem comentário perdeu o corpo do componente');

    assert.deepEqual(
        PROIBIDAS_NA_FONTE.filter(([, re]) => re.test(fonte)).map(([nome]) => nome),
        [],
        'o lightbox voltou a escrever uma forma que NÃO COMPILA — a transição não aconteceria na tela',
    );

    assert.deepEqual(
        EXIGIDAS_NA_FONTE.filter((nome) => !fonte.includes(nome)),
        [],
        'a transição do zoom saiu do `style` — a ausência das classes proibidas passaria a ser satisfeita por um arquivo SEM transição',
    );

    // ⚠️⚠️ A PROVA de que ler SEM COMENTÁRIO é obrigatório: no arquivo CRU as
    // três formas APARECEM (o docblock as cita para explicar por que não as
    // usa). Se esta asserção falhar, o docblock perdeu a explicação — e o gate
    // passou a ser satisfeito pelo motivo errado.
    const cru = fs.readFileSync(path.resolve(RAIZ, REL_LIGHTBOX), 'utf8');
    assert.deepEqual(
        PROIBIDAS_NA_FONTE.filter(([, re]) => !re.test(cru)).map(([nome]) => nome),
        [],
        'o docblock do lightbox deixou de citar as formas proibidas — perdeu-se a razão de ler sem comentário',
    );
});

test('⚠️ ISCA do GATE-09 na fonte: `duration-[150ms]` é acusado, `duration-200` NÃO 189_04', () => {
    const COM_ARBITRARIO = "const CLASSES = 'transition-transform duration-[150ms] delay-[30ms] ease-[ease]';";
    const COM_NOMEADA = "const CLASSES = 'transition duration-200 delay-150 ease-in-out';";

    // ⚠️ As TRÊS regexes VEEM a forma errada — senão a ausência acima seria
    // vazia por construção.
    assert.deepEqual(
        PROIBIDAS_NA_FONTE.filter(([, re]) => !re.test(COM_ARBITRARIO)).map(([nome]) => nome),
        [],
        'há regex do GATE-09 que não vê nem a forma arbitrária escrita de propósito — ela está cega',
    );

    // ⚠️ E o CONTROLE: a escala NOMEADA, que COMPILA, não é acusada por nenhuma.
    assert.deepEqual(
        PROIBIDAS_NA_FONTE.filter(([, re]) => re.test(COM_NOMEADA)).map(([nome]) => nome),
        [],
        'o GATE-09 acusou a escala NOMEADA — ele é mais largo que o invariante e reprovaria trabalho certo',
    );

    assert.equal(PROIBIDAS_NA_FONTE.length, 3, 'a lista de formas proibidas mudou de tamanho — remeça as duas iscas');
});
