import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 06 — A ORDEM DE EMPILHAMENTO DAS PEÇAS DA FILA, contra os
// QUATRO pontos TRAVADOS do projeto (divergência **D1** do `187-BASELINES.md`).
//
// ⚠️⚠️ POR QUE ESTE ARQUIVO É NOVO, E NÃO UM ACRÉSCIMO AO QUE JÁ EXISTE:
// `tests/js/publicador-header-zindex-ordem.test.js` é **INTOCÁVEL** nesta fase
// (`numstat` ZERO) — ele é a prova de um bug que o usuário relatou em
// 12/10/2026 e que está consertado em produção. E nome de guarda genérico
// **COLIDE** no `--filter` do harness, que SOMA as contagens. Por isso este
// arquivo tem sufixo próprio (`…-187`) e todos os nomes de teste terminam em
// `187-06`.
//
// ═══ ⚠️⚠️ A ESCALA DO PROTÓTIPO É INCOMPATÍVEL COM A DESTE PROJETO ═════════
//
// O protótipo monta o header em `z-index:50` (`Aprovacao.dc.html:26`) e por isso
// usa 44 (o overlay, `:546`), 45 (os dois popovers, `:82`/`:95`), 60 (a barra de
// lote, `:897`) e 120 (o toast, `:905`).
//
// **Neste projeto o header é `z-30`** e a ordem é TRAVADA:
// `filtros 10 < header 30 < menu lateral = painel 40`. ➡️ **A ORDEM RELATIVA é
// preservada e os NÚMEROS são NOVOS.** Copiar os números do protótipo faria o
// popover (45) cobrir o menu lateral (40) — exatamente o bug de 12/10.
//
//   peça                          protótipo   prescrito   por quê
//   overlay que fecha o popover   z-44        z-10        abaixo do popover, acima das linhas
//   popover de filtro             z-45        z-20        abaixo do header z-30, acima do overlay
//   barra flutuante de lote       z-60        z-[35]      acima do header, abaixo do menu/painel z-40
//   toast                         z-120       z-[45]      acima de tudo, inclusive do menu/painel
//
// ⚠️ **ESCOPO DECLARADO:** as duas últimas peças (barra de lote e toast) são do
// plano **187-09** e **não existem no disco hoje**. Gate sobre arquivo que não
// existe seria gate sobre ZERO casos — então aqui elas entram como
// **PRESCRIÇÃO PINADA**: os números prescritos são conferidos contra os quatro
// pontos LIDOS, de modo que, se alguém mover o header, a prescrição reprova e
// força uma decisão nova em vez de o 187-09 herdar um número velho. ⚠️ E há
// asserção provando que os dois arquivos **ainda não existem**, para que este
// bloco virar medição de verdade seja obrigatório quando eles nascerem.
//
// ⚠️ SUBSTRING CASA COM PREFIXO, e este projeto já foi mordido por isso: `z-1`
// casaria dentro de `z-10`, e `z-10` dentro de `z-100`. Toda extração termina em
// lookahead de espaço/aspa, e há caso provando contra strings sintéticas.
//
// ⚠️ Este arquivo SÓ LÊ FONTE, via `lerSemComentarios` — sem isso ele leria a
// prosa em pt-BR que CITA os próprios números (os docblocks dos dois componentes
// explicam `z-44`/`z-45`/`z-60`/`z-120` do protótipo) e passaria pelo motivo
// errado. É o caso 2 do learnings: palavra no docblock que explica "não uso
// isso" casa com o gate que conta aquela palavra.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

const REL_FILTROS = 'resources/js/Components/Mlb/Publicador/aprovacao/FiltrosDaFila.jsx';
const REL_POPOVER = 'resources/js/Components/Mlb/Publicador/aprovacao/PopoverDeFiltro.jsx';
const REL_TRAVADO = 'tests/js/publicador-header-zindex-ordem.test.js';

// ⚠️⚠️ PLANO 187-09 — AS TRÊS PEÇAS `fixed` NASCERAM, E A PRESCRIÇÃO PINADA VIROU
// LEITURA. Era exatamente isso que a asserção de ausência deste arquivo existia
// para forçar: enquanto os arquivos não existiam, gate sobre eles seria gate sobre
// ZERO casos; agora eles existem e os números são LIDOS da fonte, como as 6 peças
// antigas.
//
// ⚠️ **DIVERGÊNCIA DE NOME, DECLARADA:** a prescrição falava de `BarraDeLote.jsx`.
// O arquivo real do plano 187-09 é **`BarraDeSelecao.jsx`** (é o nome que o
// `files_modified` daquele plano prescreve, e é o que está no disco). Corrigir o
// nome aqui é o conserto certo; inventar um `BarraDeLote.jsx` só para casar uma
// string antiga seria criar arquivo para alimentar um gate.
const REL_BARRA = 'resources/js/Components/Mlb/Publicador/aprovacao/BarraDeSelecao.jsx';
const REL_CASCA = 'resources/js/Components/Mlb/Publicador/aprovacao/CascaDeModal.jsx';
const REL_TOAST = 'resources/js/Components/Mlb/Publicador/aprovacao/ToastDaFila.jsx';

/** ⚠️ `test -e` ANTES de acreditar em leitura vazia. */
for (const caminho of [REL_FILTROS, REL_POPOVER, REL_TRAVADO, REL_BARRA, REL_CASCA, REL_TOAST]) {
    if (!fs.existsSync(path.resolve(RAIZ, caminho))) {
        throw new Error(`caminho inexistente: ${caminho} — o gate mediria NADA`);
    }
}

/**
 * Os QUATRO pontos travados, com a âncora de cada um — **as mesmas âncoras** de
 * `publicador-header-zindex-ordem.test.js`, porque o invariante é o DELE.
 *
 * ⚠️ São LIDAS dos arquivos deles, nunca escritas como número aqui: um número à
 * mão viraria uma segunda verdade, e o dia em que o header mudasse os dois
 * gates discordariam.
 */
const TRAVADOS = {
    header: {
        arquivo: 'resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx',
        ancora: /<header className="sticky top-0 z-(\d+)(?=[\s"])/g,
    },
    menuLateral: {
        arquivo: 'resources/js/Layouts/AppLayout.jsx',
        ancora: /'absolute inset-y-0 left-0 z-(\d+)(?=[\s'])/g,
    },
    painelDoProduto: {
        arquivo: 'resources/js/Components/Mlb/Publicador/PainelDoProdutoLateral.jsx',
        ancora: /<div className="fixed inset-0 z-(\d+)"/g,
    },
    filtrosDaGrade: {
        arquivo: 'resources/js/Pages/Mlb/Publicador/Produtos.jsx',
        ancora: /<div className="sticky top-\[108px\] z-(\d+)(?=[\s"])/g,
    },
};

/** As duas peças DESTA wave, lidas das classes que os componentes escrevem. */
const DESTA_WAVE = {
    overlay: {
        arquivo: REL_POPOVER,
        ancora: /const CLASSES_OVERLAY = 'fixed inset-0 z-(\d+)(?=[\s'])/g,
        descricao: 'o overlay que fecha o popover de filtro',
    },
    popover: {
        arquivo: REL_POPOVER,
        ancora: /const CLASSES_POPOVER = 'absolute left-0 top-\[38px\] z-(\d+)(?=[\s'])/g,
        descricao: 'o popover de filtro (Responsável e Motivo de bloqueio)',
    },
    // ⚠️⚠️ As TRÊS do plano 187-09. Elas usam a forma de VALOR ARBITRÁRIO
    // (`z-[35]`), e não `z-35`, porque **35, 42 e 45 não existem na escala nomeada
    // do Tailwind** — a escala vai 0, 10, 20, 30, 40, 50. Por isso a âncora tem os
    // colchetes: uma âncora `z-(\d+)` NÃO casaria `z-[35]` e devolveria 0
    // ocorrências, e o `zDe()` abaixo reprovaria com "a âncora casou 0 vez(es)" —
    // que é o comportamento certo, mas pelo motivo errado.
    barraDeLote: {
        arquivo: REL_BARRA,
        ancora: /bottom-\[24px\] left-1\/2 z-\[(\d+)\](?=[\s'])/g,
        descricao: 'a barra flutuante da seleção em lote',
    },
    modal: {
        arquivo: REL_CASCA,
        ancora: /const CLASSES_BACKDROP = 'fixed inset-0 z-\[(\d+)\](?=[\s'])/g,
        descricao: 'o backdrop da casca de modal',
    },
    toast: {
        arquivo: REL_TOAST,
        ancora: /top-\[124px\] z-\[(\d+)\](?=[\s'])/g,
        descricao: 'o toast da fila',
    },
};

/**
 * Lê um z-index DO CÓDIGO.
 *
 * ⚠️ Reprova se a âncora não casar EXATAMENTE uma vez. Extração que devolve
 * `null` em silêncio quando a classe é renomeada é o jeito clássico de um gate
 * virar decoração: ele continua verde porque não achou nada para comparar.
 */
function zDe(mapa, nome) {
    const { arquivo, ancora } = mapa[nome];
    const fonte = lerSemComentarios(arquivo);
    const casos = [...fonte.matchAll(new RegExp(ancora.source, 'g'))];

    assert.equal(
        casos.length,
        1,
        `a âncora de "${nome}" casou ${casos.length} vez(es) em ${arquivo}, esperava 1. `
        + 'A classe foi renomeada ou o elemento se duplicou — conserte a âncora, NÃO apague a asserção.',
    );

    const valor = Number(casos[0][1]);
    assert.ok(Number.isInteger(valor) && valor >= 0, `z de "${nome}" não é inteiro: ${casos[0][1]}`);

    return valor;
}

test('a ordem das 6 peças que EXISTEM: overlay < popover < header < menu = painel 187-06', () => {
    const z = {
        filtrosDaGrade: zDe(TRAVADOS, 'filtrosDaGrade'),
        header: zDe(TRAVADOS, 'header'),
        menu: zDe(TRAVADOS, 'menuLateral'),
        painel: zDe(TRAVADOS, 'painelDoProduto'),
        overlay: zDe(DESTA_WAVE, 'overlay'),
        popover: zDe(DESTA_WAVE, 'popover'),
    };

    // ⚠️ A ORDEM RELATIVA do protótipo, remapeada: lá o overlay (44) fica abaixo
    // do popover (45), e o popover abaixo do header (50).
    assert.ok(
        z.overlay < z.popover,
        `overlay (${z.overlay}) tem de ficar ABAIXO do popover (${z.popover}): acima dele, o overlay `
        + 'cobriria os itens e o clique nunca chegaria neles.',
    );
    assert.ok(
        z.popover < z.header,
        `popover (${z.popover}) tem de ficar ABAIXO do header (${z.header}): acima, ele passaria por cima `
        + 'do header sticky ao rolar — e o header é o que ancora a navegação.',
    );
    assert.ok(
        z.header < z.menu && z.header < z.painel,
        `o invariante TRAVADO quebrou: header ${z.header}, menu ${z.menu}, painel ${z.painel} `
        + '(o bug relatado em 12/10/2026).',
    );
    assert.equal(z.menu, z.painel, `menu lateral (${z.menu}) e painel (${z.painel}) saíram do mesmo nível`);

    // ⚠️ E o overlay fica NO MESMO nível da camada de filtros do corpo
    // (`z-10`): são as duas peças que vivem no corpo da página e não disputam
    // entre si (uma é a barra de filtros de OUTRA tela, a outra o clique-fora).
    assert.equal(
        z.overlay,
        z.filtrosDaGrade,
        `o overlay (${z.overlay}) saiu do nível da camada de filtros do corpo (${z.filtrosDaGrade})`,
    );

    // ⚠️⚠️ E NENHUM número do protótipo foi copiado. Esta é a asserção que D1
    // exige: se alguém "trouxer o protótipo" para cá, ela reprova.
    for (const [nome, valor] of Object.entries(z)) {
        assert.ok(
            ![44, 45, 50, 60, 120].includes(valor),
            `"${nome}" ficou com ${valor}, que é um número da escala do PROTÓTIPO (44/45/50/60/120). `
            + 'A escala deste projeto é outra (D1): preserve a ORDEM, não os números.',
        );
    }
});

test('as TRÊS peças do 187-09 (barra, modal e toast), LIDAS da fonte 187-06', () => {
    const header = zDe(TRAVADOS, 'header');
    const menu = zDe(TRAVADOS, 'menuLateral');
    const painel = zDe(TRAVADOS, 'painelDoProduto');
    const popover = zDe(DESTA_WAVE, 'popover');

    // ⚠️⚠️ LIDOS da fonte, não prescritos: quando o 187-09 criou os arquivos, a
    // prescrição pinada deste bloco virou medição, que era o que a asserção de
    // ausência existia para forçar.
    const barra = zDe(DESTA_WAVE, 'barraDeLote');
    const modal = zDe(DESTA_WAVE, 'modal');
    const toast = zDe(DESTA_WAVE, 'toast');

    // ⚠️ No protótipo a barra de lote (60) fica ACIMA do header (50), o modal (100)
    // acima dela e o toast (120) acima de tudo. A ORDEM RELATIVA é preservada na
    // escala daqui; os NÚMEROS são novos (D1).
    assert.ok(barra > header, `a barra de lote (${barra}) tem de ficar ACIMA do header (${header})`);
    assert.ok(
        barra < menu,
        `a barra de lote (${barra}) tem de ficar ABAIXO do menu/painel (${menu}): acima, ela cobriria o `
        + 'menu expandido — o bug de 12/10/2026 com outra roupa.',
    );
    assert.ok(
        modal > menu && modal > painel,
        `o modal (${modal}) tem de ficar ACIMA do menu (${menu}) e do painel (${painel}): ele é MODAL, e `
        + 'nada pode ficar na frente dele a não ser o aviso do que acabou de acontecer.',
    );
    assert.ok(modal > barra, `o modal (${modal}) tem de ficar ACIMA da barra que o abriu (${barra})`);
    assert.ok(
        toast > modal,
        `o toast (${toast}) tem de ficar ACIMA do modal (${modal}): ele é o aviso do que acabou de `
        + 'acontecer, e nada pode cobri-lo.',
    );
    assert.ok(popover < barra, 'o popover tem de ficar abaixo da barra de lote');

    // ⚠️⚠️ E NENHUM número DO PROTÓTIPO FOI COPIADO — **conferido PEÇA POR PEÇA**.
    //
    // ⚠️⚠️ **POR QUE ESTA ASSERÇÃO NÃO É A LISTA LARGA DO CASO 1, E A DIFERENÇA FOI
    // MEDIDA NESTA SESSÃO.** A primeira redação deste bloco reusou o
    // `![44, 45, 50, 60, 100, 120].includes(valor)` do caso acima — e **REPROVOU
    // CÓDIGO CERTO**: o toast ficou com `z-[45]`, e `45` está na lista porque é o
    // número que o protótipo deu ao **POPOVER** (`:82`/`:95`), não ao toast (que lá
    // é **120**).
    // ➡️ O invariante de verdade é *"a peça não herdou o número que o protótipo deu
    // A ELA"*, e é isso que o mapa abaixo mede. A lista larga é mais larga que o
    // invariante — é a família que nesta milestone já reprovou trabalho correto três
    // vezes e **barrou um deploy**. ⚠️ E o conserto certo NÃO era baixar a régua:
    // era ancorá-la no COMPORTAMENTO (qual número o protótipo deu A ESTA peça), que
    // é mais forte, porque uma lista de seis números perdoaria um sétimo.
    //
    // ⚠️ O caso 1 **não foi tocado**: para as 6 peças antigas (10/20/30/40) a lista
    // larga não colide com nada, e ela é prova da wave 6.
    const DO_PROTOTIPO = { barra: 60, modal: 100, toast: 120 };
    for (const [nome, valor] of Object.entries({ barra, modal, toast })) {
        assert.notEqual(
            valor,
            DO_PROTOTIPO[nome],
            `"${nome}" ficou com ${valor}, que é EXATAMENTE o número que o protótipo deu a ela. A escala `
            + 'deste projeto é outra (D1): preserve a ORDEM, não os números.',
        );
    }

    // ⚠️⚠️ ISCA DO MAPA, nas DUAS direções — sem ela um mapa com a chave errada
    // (ou um `undefined`) faria as três asserções acima passar comparando contra
    // `undefined`, que nunca é igual a um inteiro.
    assert.deepEqual(
        Object.keys(DO_PROTOTIPO).sort(),
        ['barra', 'modal', 'toast'],
        'o mapa do protótipo perdeu (ou ganhou) uma peça — as asserções acima compararam contra `undefined`',
    );
    for (const nome of Object.keys(DO_PROTOTIPO)) {
        assert.equal(typeof DO_PROTOTIPO[nome], 'number', `o número do protótipo de "${nome}" não é número`);
    }
    // E a prova de que a asserção SABE recusar: o próprio número do protótipo reprova.
    assert.throws(
        () => assert.notEqual(DO_PROTOTIPO.toast, DO_PROTOTIPO.toast),
        'ISCA CEGA: `notEqual` não recusa nem o número idêntico',
    );

    // ⚠️ ISCA: as três âncoras NÃO casam a forma da escala NOMEADA (`z-45` sem
    // colchete). Sem isto, uma âncora que casasse as duas formas leria o número
    // errado no dia em que alguém trocasse `z-[45]` por `z-45` — que não compila
    // nesta escala e deixaria a peça SEM z-index nenhum.
    const semColchete = "const CLASSES_BACKDROP = 'fixed inset-0 z-42 flex';";
    assert.equal(
        [...semColchete.matchAll(new RegExp(DESTA_WAVE.modal.ancora.source, 'g'))].length,
        0,
        'a âncora do modal casa `z-42` sem colchete — ela leria um número que o Tailwind não compila',
    );
});

test('a extração não casa por PREFIXO (z-1 não casa dentro de z-10, z-10 dentro de z-100) 187-06', () => {
    const pegar = (texto, fonte) => {
        const m = [...texto.matchAll(new RegExp(fonte, 'g'))];
        return m.length === 1 ? Number(m[0][1]) : null;
    };
    const fonteOverlay = DESTA_WAVE.overlay.ancora.source;

    // O caso real: termina em espaço.
    assert.equal(pegar("const CLASSES_OVERLAY = 'fixed inset-0 z-10 cursor-default';", fonteOverlay), 10);
    // Termina em aspa (classe única no atributo).
    assert.equal(pegar("const CLASSES_OVERLAY = 'fixed inset-0 z-10';", fonteOverlay), 10);
    // ⚠️ O caso que mata gate por substring: 100 NÃO pode ser lido como 10.
    assert.equal(pegar("const CLASSES_OVERLAY = 'fixed inset-0 z-100 cursor-default';", fonteOverlay), 100);
    // E um valor de um dígito não "vira" o de dois.
    assert.equal(pegar("const CLASSES_OVERLAY = 'fixed inset-0 z-1 cursor-default';", fonteOverlay), 1);
});

test('ISCA: âncora que eu SEI que não existe dá 0 ocorrências 187-06', () => {
    const fonte = lerSemComentarios(REL_POPOVER);

    // Classe inventada, com a MESMA forma das âncoras de cima. Se isto casar, o
    // `matchAll` está achando qualquer coisa e os testes acima passam pelo
    // motivo errado.
    const isca = [...fonte.matchAll(/const CLASSES_OVERLAY = 'fixed inset-0 zz-(\d+)(?=[\s'])/g)];
    assert.equal(isca.length, 0, 'a isca casou — a extração deste arquivo acha âncora que não existe');

    // E a contraprova: a âncora de verdade casa no MESMO arquivo. Sem esta
    // linha, um `lerSemComentarios` que devolvesse string vazia faria a isca
    // passar e nada acusaria.
    assert.equal(
        [...fonte.matchAll(new RegExp(DESTA_WAVE.overlay.ancora.source, 'g'))].length,
        1,
        'a âncora de verdade não casou — a leitura da fonte está quebrada',
    );
});

test('ISCA: os comentários SÃO removidos antes de ler (a prosa cita os números do protótipo) 187-06', () => {
    // ⚠️ A isca mede `PopoverDeFiltro.jsx`, e **só ele**, porque é dele que as
    // duas âncoras desta wave são lidas — e é no docblock dele que `z-index:45`,
    // `z-44`, `z-index:50`, `z-60` e `z-120` estão escritos de propósito, para
    // explicar por que a escala do protótipo não vale aqui.
    //
    // ⚠️ MEDIDO nesta sessão: a primeira redação deste caso varria TAMBÉM
    // `FiltrosDaFila.jsx` e reprovava, porque o docblock daquele arquivo **não
    // cita número de protótipo nenhum** — ele fala da própria escala (`z-10`,
    // `z-20`, `z-30`). Inventar prosa só para alimentar a isca seria pior que
    // recortá-la: isca sobre arquivo errado é gate sobre ZERO casos.
    const bruta = fs.readFileSync(path.resolve(RAIZ, REL_POPOVER), 'utf8');
    const limpa = lerSemComentarios(REL_POPOVER);

    assert.match(bruta, /z-index:45|z-44|z-index:50/, 'a isca saiu do docblock do popover — troque a isca');
    assert.doesNotMatch(limpa, /z-index:45|z-44|z-index:50/, 'os comentários NÃO foram removidos do popover');

    // E a fonte lida continua tendo o CÓDIGO: um leitor que devolvesse vazio
    // faria todas as negativas acima passar por ausência.
    assert.match(limpa, /CLASSES_POPOVER/, 'o código desapareceu da fonte lida');

    // ⚠️ E `FiltrosDaFila.jsx` também é lido sem comentário por este arquivo (o
    // `REL_FILTROS` existe no `test -e` do topo e o GATE-03 o varre), então a
    // prova de que o leitor funciona NELE é a sua própria prosa: ele explica o
    // sticky que NÃO existe, e esse texto não pode sobreviver à limpeza.
    const brutaFiltros = fs.readFileSync(path.resolve(RAIZ, REL_FILTROS), 'utf8');
    assert.match(brutaFiltros, /NÃO HÁ STICKY NESTA LINHA/, 'a isca saiu do docblock dos filtros — troque a isca');
    assert.doesNotMatch(lerSemComentarios(REL_FILTROS), /NÃO HÁ STICKY NESTA LINHA/, 'os comentários NÃO foram removidos dos filtros');
    assert.match(lerSemComentarios(REL_FILTROS), /CLASSES_LINHA/, 'o código dos filtros desapareceu da fonte lida');

    // ⚠️⚠️ E a NEGATIVA que o docblock dos filtros afirma, medida no CÓDIGO: a
    // linha de filtros NÃO tem sticky. Medido por script no protótipo (`:66` não
    // declara `position:sticky`, `top:` nem `z-index:`), e sem esta asserção a
    // prosa seria só prosa.
    assert.doesNotMatch(
        lerSemComentarios(REL_FILTROS),
        /sticky|top-\[108px\]|top-0/,
        'a linha de filtros ganhou sticky: o protótipo (`:66`) NÃO o declara. Se for decisão nova, '
        + 'o valor é `sticky top-[108px] z-10` (nunca `top-0`: o header ocupa 108px do scrollport).',
    );
});

test('o arquivo TRAVADO não foi tocado e continua provando a ordem dele 187-06', () => {
    // ⚠️ Este gate NÃO substitui o travado: ele o COMPLEMENTA. A asserção abaixo
    // é a prova de que o invariante original continua escrito lá — se alguém
    // apagasse os testes de ordem daquele arquivo, este caso acusaria.
    const travado = fs.readFileSync(path.resolve(RAIZ, REL_TRAVADO), 'utf8');

    assert.match(travado, /filtros < header < menu lateral = painel do produto/, 'o teste de ordem completa saiu do arquivo travado');
    assert.match(travado, /o header fica ABAIXO do menu lateral do sistema/, 'o teste do menu lateral saiu do arquivo travado');
    assert.match(travado, /o header fica ABAIXO do painel lateral do produto/, 'o teste do painel saiu do arquivo travado');

    // ⚠️ E as QUATRO âncoras deste arquivo são as MESMAS de lá, byte a byte na
    // parte que importa: se alguém mudasse uma só de um lado, os dois gates
    // leriam elementos diferentes e discordariam em silêncio.
    //
    // ⚠️ MEDIDO: a primeira redação deste caso fazia `.replace(/\\d\+/, '')`
    // antes de comparar, e o resultado era `z-()(?=[\s"])` — um trecho que NÃO
    // existe em arquivo nenhum, então o caso reprovava o código certo. A
    // comparação certa é a `source` INTEIRA: ela é exatamente o texto do literal
    // de regex escrito lá.
    for (const nome of Object.keys(TRAVADOS)) {
        const trecho = TRAVADOS[nome].ancora.source;
        assert.ok(
            travado.includes(trecho),
            `a âncora de "${nome}" divergiu do arquivo travado — os dois gates passariam a ler `
            + `elementos DIFERENTES e discordariam em silêncio. Esperava achar: ${trecho}`,
        );
    }

    // ⚠️ ISCA DA COMPARAÇÃO: ela SABE recusar. Sem isto, um `includes` que
    // casasse tudo faria as quatro asserções acima passar medindo NADA.
    //
    // ⚠️⚠️ MEDIDO nesta sessão: a primeira isca era `zz-(\d+)` e CASOU — porque
    // aquele mesmo literal é a **isca do próprio arquivo travado** (ele tem um
    // caso "âncora que eu sei que NÃO existe"). Isca que colide com a isca alheia
    // é um falso positivo esperando a hora, então aqui ela é `zzz-`.
    assert.equal(
        travado.includes('<header className="sticky top-0 zzz-(\\d+)'),
        false,
        'a isca casou — a comparação está cega (ou alguém escreveu a isca de verdade naquele arquivo)',
    );
});
