import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 11 — O FECHAMENTO, lado JS: a CONTAGEM FIXA dos arquivos da
// fase, a declaração deles no gate de vocabulário, e os 11 manifestos.
//
// ⚠️⚠️ **UMA asserção de contagem, AQUI, medida uma vez.** Não uma por plano. A
// razão está no learnings §4b.2 e foi medida NOVE vezes nesta milestone:
// contagem que sobe a cada plano é frágil **por construção**, e elevar régua
// repetidamente **treina o executor seguinte a ajustar réguas quando elas
// incomodam** — um dia ele ajusta uma que importava.
//
// ⚠️ **Nome de guarda próprio (`187-11`) em todo teste.** O `--filter` do
// harness casa por nome, e nome genérico SOMA contagens de dois arquivos.
//
// ⚠️ **O QUE PRECISARIA ESTAR ERRADO NO MUNDO PARA ESTES GATES FALHAREM:**
//   · alguém **apagar** um dos 20 `.jsx` de produção da fase, ou tirá-lo da
//     lista `ARQUIVOS_NOVOS` do gate de vocabulário (e então aquele arquivo
//     sairia da conferência de vocabulário em SILÊNCIO — VOC-07);
//   · alguém criar peça de tela da fase como **`.js`** em vez de `.jsx` — o
//     `content` do `tailwind.config.js` varre `*.jsx` e **NÃO** `*.js`, então
//     toda classe daquele arquivo deixaria de compilar, com todo teste verde;
//   · alguém **apagar um manifesto**, ou pôr `tests=0` num id (e então o gate
//     daquele id roda **sem régua** e parece verde);
//   · alguém **recriar** a `CascaDeModal.jsx` na Fase 188 em vez de importá-la.
// ═══════════════════════════════════════════════════════════════════════════

const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';

// ⚠️ LISTA CONSTANTE, não `glob`. Os 20 arquivos de PRODUÇÃO (frontend) da
// Fase 187, medidos em 18/10/2026 por DOIS caminhos independentes que bateram:
//   (a) `git show --diff-filter=A` nos 68 commits de escopo `187-*` → 20 `.jsx`
//       sob `resources/` (18 em `aprovacao/`, 1 em `publicacoes/`, 1 `Pages/`);
//   (b) as entradas de `ARQUIVOS_NOVOS` da Fase 187 em
//       `publicador-vocabulario-novo.test.js` → as MESMAS 20.
const JSX_DA_FASE_187 = [
    'resources/js/Components/Mlb/Publicador/publicacoes/AbasDaPagina.jsx',
    'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx',
    `${DIR_APROV}/CabecalhoDaFila.jsx`,
    `${DIR_APROV}/FaixaDeAndamento.jsx`,
    `${DIR_APROV}/ProximoARevisar.jsx`,
    `${DIR_APROV}/FiltrosDaFila.jsx`,
    `${DIR_APROV}/PopoverDeFiltro.jsx`,
    `${DIR_APROV}/TabelaDaFila.jsx`,
    `${DIR_APROV}/LinhaDeProdutoDaFila.jsx`,
    `${DIR_APROV}/SubLinhaDeConjunto.jsx`,
    `${DIR_APROV}/QuadradosDeFoto.jsx`,
    `${DIR_APROV}/LinhaDaOferta.jsx`,
    `${DIR_APROV}/AnelDeProgresso.jsx`,
    `${DIR_APROV}/BarraDeMargem.jsx`,
    `${DIR_APROV}/BarraDeSelecao.jsx`,
    `${DIR_APROV}/CascaDeModal.jsx`,
    `${DIR_APROV}/ModalDeLote.jsx`,
    `${DIR_APROV}/ToastDaFila.jsx`,
    `${DIR_APROV}/RodapeDaFila.jsx`,
    `${DIR_APROV}/VazioDaFila.jsx`,
];

// Os 3 `.jsx` que a Fase **186** pôs em `aprovacao/` — território SOMENTE
// LEITURA desta fase, e por isso fora da lista de cima.
const JSX_DA_FASE_186 = [
    `${DIR_APROV}/SeloDeAndamentoInterno.jsx`,
    `${DIR_APROV}/andamentoDoPortao.jsx`,
    `${DIR_APROV}/vocabularioDoPortao.jsx`,
];

// ═══════════════════════════════════════════════════════════════════════════
// ⚠️⚠️ CIRURGIA DA FASE 188 (19/10/2026) — O TOTAL `21` DO DIRETÓRIO SAIU, E O
// REAPONTE PARA A INVARIANTE ENTROU. **Nenhum invariante foi afrouxado; dois
// foram acrescentados.**
//
// O QUE ESTAVA AQUI: `assert.equal(noDisco.length, 21, 'o diretorio aprovacao/
// nao tem 21 .jsx')`, com o raciocínio *"18 da 187 + 3 da 186 = 21, e nada
// mais"*.
//
// POR QUE REPROVAVA TRABALHO CERTO: a Fase 188 **existe para acrescentar peça
// neste diretório**. O plano 188-07 pôs três — `PainelDeRevisao.jsx`,
// `TopoDoPainel.jsx` e `IdentificacaoDaOferta.jsx` — e o diretório virou 24. As
// waves 188-08 a 188-13 põem mais, e cada uma teria de subir o número de novo.
//
// ⚠️ E O PRÓPRIO DOCBLOCK DESTE ARQUIVO JÁ DIZIA O PORQUÊ, no topo: *"contagem
// que sobe a cada plano é frágil POR CONSTRUÇÃO, e elevar régua repetidamente
// treina o executor seguinte a ajustar réguas quando elas incomodam"*. A
// contagem de 21 era desta família (a décima da milestone, learnings §4b.2) e
// escapou porque media o DIRETÓRIO, não a FASE — exatamente o diagnóstico que os
// planos 187-03 e 187-04 escreveram ao reapontar `DA_FASE_180` e
// `DO_PORTAO_DA_FASE_186`.
//
// O QUE ENTROU NO LUGAR, e por que é MAIS FORTE que o total:
//   (a) `JSX_DEPOIS_DA_187` — lista CONSTANTE de nomes, cada um com a fase e o
//       plano dono. O `deepEqual(noDisco, declarados)` que já existia passa a
//       cobrir o diretório INTEIRO sem nenhum número: arquivo no disco fora das
//       TRÊS listas continua REPROVANDO (era o que o 21 garantia), cada
//       acréscimo fica NOMEADO, e acrescentar é **diff VISÍVEL** — não um número
//       trocado em silêncio. ⚠️ E ele é mais forte numa direção que o total não
//       cobria: o 21 aceitava a REMOÇÃO de um arquivo da 187 compensada pela
//       criação de um da 188.
//   (b) O REAPONTE PARA A INVARIANTE: o que este gate de fato precisa provar é
//       *"o conjunto no disco é o conjunto DECLARADO no gate de vocabulário"* —
//       porque arquivo fora de `ARQUIVOS_NOVOS` fica sem conferência contra
//       `text-sm`, `ecf-*`, `font-display` e hex cru, e o gate de vocabulário
//       passaria POR OMISSÃO (VOC-07). Isso é o `deepEqual(noDisco, declarados)`
//       do GATE-03, que **já está verde com os três `.jsx` da 188-07** — e agora
//       este arquivo confere o MESMO conjunto, lendo o literal daquele gate.
//       Nenhum número no meio.
//
// ⚠️ QUEM CRIAR `.jsx` EM `aprovacao/` ACRESCENTA UMA LINHA ABAIXO, com a fase e
// o plano dono, **e** o caminho em `ARQUIVOS_NOVOS`. Faltando um dos dois, o
// gate REPROVA — e é esse o comportamento certo.
// ═══════════════════════════════════════════════════════════════════════════

/** `.jsx` nascidos em `aprovacao/` DEPOIS do fechamento da Fase 187. */
const JSX_DEPOIS_DA_187 = [
    // Fase 188, plano 07 — o painel de revisão de 1140px montado POR PORTAL, o
    // topo de 54px dele e a coluna de identificação da oferta com os 5 chips.
    `${DIR_APROV}/PainelDeRevisao.jsx`,
    `${DIR_APROV}/TopoDoPainel.jsx`,
    `${DIR_APROV}/IdentificacaoDaOferta.jsx`,
    // Fase 188, plano 08 — a coluna de FOTOS do painel: a imagem grande 1:1 com
    // os marcadores x/y, a grade de 6 colunas e o bloco da imagem selecionada.
    `${DIR_APROV}/ImagemGrandeDoPainel.jsx`,
    `${DIR_APROV}/GradeDeMiniaturas.jsx`,
    `${DIR_APROV}/BlocoDaImagemSelecionada.jsx`,
    `${DIR_APROV}/ColunaDeFotos.jsx`,
    // Fase 188, plano 09 — a coluna de PREÇO do painel: os 3 KPIs com a barra da
    // margem de 0 a 40%, o aviso de alteração não conferida, a composição com
    // selo de origem por linha, a simulação que nunca é salva e a comparação com
    // a oferta irmã.
    `${DIR_APROV}/KpisDoPainel.jsx`,
    `${DIR_APROV}/ColunaDePreco.jsx`,
    `${DIR_APROV}/ComposicaoFinanceiraDoPainel.jsx`,
    `${DIR_APROV}/SimulacaoDePreco.jsx`,
    `${DIR_APROV}/ComparacaoComAIrma.jsx`,
    // Fase 188, plano 10 — as PENDÊNCIAS com o botão de correção de cada uma (os
    // nove destinos LIDOS do servidor) e o RODAPÉ FIXO de 66px com os quatro
    // botões e o motivo, onde vive o `disabled=` de "Aprovar para publicação".
    `${DIR_APROV}/PendenciasDoPainel.jsx`,
    `${DIR_APROV}/RodapeDoPainel.jsx`,
    // Fase 188, plano 11 — os dois primeiros MODAIS do painel, dentro da casca da
    // Fase 187 (`CascaDeModal.jsx`, IMPORTADA e nunca recriada). Tarefa 1: MOD-01
    // "Solicitar ajustes" (item, destino por escopo, comentário e sugestões).
    `${DIR_APROV}/ModalDeAjuste.jsx`,
    // Tarefa 2: MOD-02 "Solicitar exceção autorizada" (caixa vermelha, responsável
    // do servidor, justificativa de >= 10 caracteres).
    `${DIR_APROV}/ModalDeExcecao.jsx`,
];

/** O gate que fecha o conjunto do diretório contra a conferência de vocabulário. */
const REL_VOCABULARIO = 'tests/js/publicador-vocabulario-novo.test.js';

/**
 * Os `.jsx` de `aprovacao/` DECLARADOS em `ARQUIVOS_NOVOS`, lidos do literal.
 *
 * ⚠️ `ARQUIVOS_NOVOS` é `const` de módulo naquele arquivo, não export — e
 * importá-lo RODARIA os testes dele. Então lê-se o LITERAL, e da fonte SEM
 * COMENTÁRIO: a prosa daquele gate CITA nomes de arquivo em pt-BR, e uma leitura
 * crua colheria o comentário junto (foi medido em dois gates desta milestone).
 * ⚠️ E recortado ao bloco de `ARQUIVOS_NOVOS`: `DIR_APROV` aparece 37 vezes
 * naquele arquivo, a maioria em OUTRAS listas, e varrê-lo inteiro mediria um
 * conjunto que não é o do gate de vocabulário.
 */
function declaradosNoGateDeVocabulario() {
    const fonte = lerSemComentarios(REL_VOCABULARIO);
    const bloco = fonte.match(/const\s+ARQUIVOS_NOVOS\s*=\s*\[([\s\S]*?)\n\];/);
    assert.ok(bloco, `nao achei o literal de ARQUIVOS_NOVOS em ${REL_VOCABULARIO} — a leitura esta cega`);
    const nomes = [...bloco[1].matchAll(/\$\{DIR_APROV\}\/([\w.-]+\.jsx)/g)].map((m) => m[1]);
    assert.ok(nomes.length > 0, `ARQUIVOS_NOVOS nao declara nenhum .jsx de aprovacao/ — a leitura esta cega`);
    return [...new Set(nomes)].sort();
}

const abs = (rel) => path.resolve(RAIZ, rel);

// ═══════════════════════════════════════════════════════════════════════════
// id de gate `fechamento-arquivos-da-fase`
// ═══════════════════════════════════════════════════════════════════════════

test('187-11: os 20 .jsx de producao da fase existem no disco, e o diretorio da fila e o conjunto DECLARADO', () => {
    const faltando = JSX_DA_FASE_187.filter((rel) => !fs.existsSync(abs(rel)));
    assert.deepEqual(faltando, [], 'arquivo de producao da Fase 187 ausente do disco');

    const faltando186 = JSX_DA_FASE_186.filter((rel) => !fs.existsSync(abs(rel)));
    assert.deepEqual(faltando186, [], 'arquivo da Fase 186 em aprovacao/ desapareceu — territorio somente leitura');

    // ⚠️ E as peças nascidas depois da 187 tambem estao no disco: lista que
    // aponta para caminho fantasma "prova" cobertura medindo NADA.
    const faltandoDepois = JSX_DEPOIS_DA_187.filter((rel) => !fs.existsSync(abs(rel)));
    assert.deepEqual(faltandoDepois, [], 'caminho fantasma em JSX_DEPOIS_DA_187 — entrada morta e linha morta');

    // ⚠️ O conjunto fecha o diretório SEM NENHUM TOTAL (ver o bloco de cirurgia
    // acima): 18 da 187 + 3 da 186 + os declarados depois, e **nada mais**.
    // Arquivo no disco fora das TRÊS listas é peça que ninguém declarou — e o
    // gate de vocabulário não a varreria.
    const noDisco = fs.readdirSync(abs(DIR_APROV)).filter((f) => f.endsWith('.jsx')).sort();
    const declarados = [...JSX_DA_FASE_187, ...JSX_DA_FASE_186, ...JSX_DEPOIS_DA_187]
        .filter((rel) => rel.startsWith(DIR_APROV))
        .map((rel) => path.basename(rel))
        .sort();
    // ⚠️ SONDA NÃO-CEGA primeiro: diretorio renomeado devolveria `[]` e o
    // `deepEqual` passaria se as listas tambem estivessem vazias.
    assert.ok(noDisco.length > 0, 'nenhum .jsx em aprovacao/ — o caminho DIR_APROV esta errado?');
    assert.deepEqual(
        noDisco,
        declarados,
        'o diretorio aprovacao/ divergiu do DECLARADO. Criou .jsx novo? Acrescente-o a JSX_DEPOIS_DA_187'
        + ' com a fase e o plano dono — e tambem a ARQUIVOS_NOVOS, senao ele fica SEM conferencia de vocabulario.'
        + ` nao-declarados=[${noDisco.filter((f) => !declarados.includes(f)).join(', ')}]`
        + ` fantasmas=[${declarados.filter((f) => !noDisco.includes(f)).join(', ')}]`,
    );

    // ⚠️⚠️ O REAPONTE PARA A INVARIANTE: o conjunto no disco é o conjunto que o
    // GATE-03 declara. É isto, e não um total, que prova que nenhuma peça deste
    // diretorio ficou FORA da conferencia de vocabulario (VOC-07).
    assert.deepEqual(
        declaradosNoGateDeVocabulario(),
        noDisco,
        `ARQUIVOS_NOVOS (${REL_VOCABULARIO}) divergiu do disco: arquivo fora daquela lista passa SEM`
        + ' conferencia contra `text-sm`, `ecf-*`, `font-display` e hex cru, e o gate passa POR OMISSAO',
    );

    // ⚠️ ISCA: a conferência de existência TEM de reprovar um nome inventado.
    assert.equal(
        fs.existsSync(abs(`${DIR_APROV}/NaoExiste9z7.jsx`)),
        false,
        'a isca do arquivo inexistente EXISTE no disco — troque o nome da isca',
    );
    // ⚠️ ISCA DA LEITURA DO LITERAL: o nome inventado NAO esta declarado no gate
    // de vocabulario. Sem ela, `declaradosNoGateDeVocabulario()` devolvendo
    // sempre tudo faria o reaponte acima passar com a sonda cega.
    assert.ok(
        !declaradosNoGateDeVocabulario().includes('NaoExiste9z7.jsx'),
        'a leitura de ARQUIVOS_NOVOS acha arquivo que nao existe — a sonda esta cega',
    );
});

test('187-11: nenhuma peca de tela da fase e .js — o content do Tailwind varre *.jsx e NAO *.js', () => {
    // O invariante REAL, e ele não é "nenhum arquivo novo da fase é `.js`": os
    // **9 arquivos de teste** da fase são `.js` de propósito (`tests/js/*.test.js`),
    // e teste não é varrido pelo Tailwind. ⚠️ O invariante é sobre `resources/`.
    // ⚠️ `JSX_DEPOIS_DA_187` entra aqui pela Fase 188: a regra do `content` do
    // Tailwind vale para TODA peça do diretorio, nao so para as da 187.
    const comExtensaoErrada = [...JSX_DA_FASE_187, ...JSX_DA_FASE_186, ...JSX_DEPOIS_DA_187].filter(
        (rel) => rel.startsWith('resources/') && !rel.endsWith('.jsx'),
    );
    assert.deepEqual(comExtensaoErrada, [], 'peca de tela da fase com extensao .js — as classes dela NAO compilam');

    // E o inverso, no disco: nenhum `.js` nasceu em `aprovacao/`.
    const jsNoDiretorio = fs.readdirSync(abs(DIR_APROV)).filter((f) => f.endsWith('.js'));
    assert.deepEqual(jsNoDiretorio, [], 'ha arquivo .js em aprovacao/ — se ele tiver classe Tailwind, ela nao compila');

    // ⚠️⚠️ **LIDO CRU, nunca por `lerSemComentarios`** — e eu CAÍ nesta armadilha
    // ao escrever este gate, o que é a melhor prova de que ela está viva: o
    // helper remove blocos `/* … */`, e `'./resources/js/**/*.jsx'` contém um
    // `/*` seguido de `*/`, então o glob chega como `'./resources/js*.jsx'` e o
    // gate mede NADA. Está medida desde a Fase 179-06, e há **duas** iscas
    // invertidas no repositório (`publicador-visao-geral-acoes.test.js:344` e
    // `publicador-aprovacao-vocabulario.test.js:746`) exigindo que o helper
    // CONTINUE mastigando o glob — para avisar se um dia ele parar.
    const config = fs.readFileSync(abs('tailwind.config.js'), 'utf8');
    assert.match(config, /'\.\/resources\/js\/\*\*\/\*\.jsx'/, 'o content do Tailwind deixou de varrer *.jsx');
    assert.doesNotMatch(
        config,
        /'\.\/resources\/js\/\*\*\/\*\.js'/,
        'o content do Tailwind passou a varrer *.js — a premissa deste gate mudou, releia o learnings §2',
    );
    // ⚠️ ISCA INVERTIDA, a terceira do repositório: o helper AINDA mastiga o
    // glob. Se um dia parar, esta linha reprova e a leitura crua deixa de ser
    // obrigatória.
    assert.doesNotMatch(
        lerSemComentarios('tailwind.config.js'),
        /'\.\/resources\/js\/\*\*\/\*\.jsx'/,
        'o lerSemComentarios parou de comer o /**/ do glob — o comentario acima ficou desatualizado',
    );

    // ⚠️ ISCA NAS DUAS DIREÇÕES da sonda de extensão.
    assert.deepEqual(
        ['resources/js/x/Y.js', 'resources/js/z/W.jsx'].filter(
            (rel) => rel.startsWith('resources/') && !rel.endsWith('.jsx'),
        ),
        ['resources/js/x/Y.js'],
        'a sonda de extensao nao distingue .js de .jsx',
    );
});

test('187-11: os 20 .jsx da fase estao DECLARADOS em ARQUIVOS_NOVOS do gate de vocabulario', () => {
    // ⚠️ Esta é a conferência que impede o furo VOC-07: arquivo fora de
    // `ARQUIVOS_NOVOS` sai da varredura de vocabulário **em silêncio**.
    //
    // ⚠️ Leitura SEM COMENTÁRIO: a prosa daquele arquivo cita os próprios
    // caminhos ("os quatro arquivos — e `DIR_APROV` está em..."), e um gate cru
    // passaria pela EXPLICAÇÃO em vez da declaração. É a armadilha do
    // `feedback_gate_que_reprova_pelo_proprio_conserto`.
    const fonte = lerSemComentarios('tests/js/publicador-vocabulario-novo.test.js');

    // A lista daquele arquivo é montada com template string e as constantes de
    // diretório, então a declaração de cada peça é o BASENAME dentro de
    // `${DIR_...}/`. É isso que se procura — nunca o caminho inteiro.
    const naoDeclarados = JSX_DA_FASE_187.filter((rel) => {
        const base = path.basename(rel);
        if (rel.startsWith('resources/js/Pages/')) {
            return !fonte.includes(`'${rel}'`);
        }
        return !fonte.includes(`/${base}\``);
    });
    assert.deepEqual(naoDeclarados, [], 'arquivo da Fase 187 NAO declarado em ARQUIVOS_NOVOS — ele sai da varredura de vocabulario calado');

    // ⚠️ ISCA: um nome que NÃO está declarado tem de dar negativo. Sem ela eu
    // não saberia se o `includes` acha qualquer coisa.
    assert.equal(fonte.includes('/NaoExiste9z7.jsx`'), false, 'a isca da declaracao casou um nome inventado — a sonda esta cega');
    // ⚠️ ISCA POSITIVA: um nome que ESTÁ declarado tem de dar positivo.
    assert.equal(fonte.includes('/CascaDeModal.jsx`'), true, 'a sonda da declaracao nao acha o que esta declarado');

    // ⚠️ E a contagem FIXA, medida UMA vez: 20 peças de produção da Fase 187.
    assert.equal(JSX_DA_FASE_187.length, 20, 'a lista constante dos .jsx da Fase 187 deixou de ter 20 entradas');
});

test('187-11: a CascaDeModal declara que a Fase 188 a REUSA, e nao ha segunda casca', () => {
    // ⚠️ Duas cascas divergiriam. O Coverage Map atribui a "casca comum" à Fase
    // 188 (MOD-01..05), mas o **modal de lote** é da 187 — então ela nasceu aqui
    // e a 188 **importa**. O aviso vive no arquivo, com gate, para a Fase 188
    // não o descobrir por acidente.
    const bruto = fs.readFileSync(abs(`${DIR_APROV}/CascaDeModal.jsx`), 'utf8');
    assert.match(bruto, /Fase 188/, 'a CascaDeModal nao avisa que a Fase 188 a REUSA');

    // Nenhuma segunda casca em `aprovacao/`: só um arquivo do diretório exporta
    // um componente com "CascaDe" no nome.
    const cascas = fs
        .readdirSync(abs(DIR_APROV))
        .filter((f) => f.endsWith('.jsx') && /Casca/i.test(f));
    assert.deepEqual(cascas, ['CascaDeModal.jsx'], 'ha mais de uma casca de modal em aprovacao/ — elas vao divergir');

    // ⚠️ O ModalDeLote USA a casca (e não reimplementa backdrop/foco).
    const modal = lerSemComentarios(`${DIR_APROV}/ModalDeLote.jsx`);
    assert.match(modal, /from '\.\/CascaDeModal'/, 'o ModalDeLote nao importa a CascaDeModal');
});

// ═══════════════════════════════════════════════════════════════════════════
// id de gate `fechamento-manifestos`
// ═══════════════════════════════════════════════════════════════════════════

test('187-11: os 11 manifestos da fase existem, nenhum id com tests=0, e a forma de cada linha fecha', () => {
    const faltando = [];
    const ids = [];
    const malformadas = [];
    const zerados = [];

    for (let i = 1; i <= 11; i++) {
        const nn = String(i).padStart(2, '0');
        const rel = `tests/gates/contagens-187-${nn}.txt`;
        if (!fs.existsSync(abs(rel))) {
            faltando.push(rel);
            continue;
        }
        const linhas = fs
            .readFileSync(abs(rel), 'utf8')
            .split(/\r?\n/)
            .filter((l) => l.trim() !== '' && !/^\s*#/.test(l));
        for (const l of linhas) {
            // A forma do manifesto: `<id> <js|php> <alvos> :: tests=<N>[ assertions=<A>]`
            const m = l.match(/^(\S+)\s+(js|php)\s+(.+?)\s+::\s+(.+)$/);
            if (!m) {
                malformadas.push(`${rel}: ${l}`);
                continue;
            }
            ids.push(`${nn}:${m[1]}`);
            const tests = m[4].match(/(^|\s)tests=([0-9,>=]+)/);
            if (!tests) {
                malformadas.push(`${rel}: ${l} (sem tests=)`);
                continue;
            }
            for (const n of tests[2].split(',')) {
                if (n === '0') zerados.push(`${rel}: ${m[1]}`);
            }
            if (m[2] === 'php' && !/(^|\s)assertions=[1-9]/.test(m[4])) {
                malformadas.push(`${rel}: ${m[1]} (modo php sem assertions=<A> positivo)`);
            }
        }
    }

    assert.deepEqual(faltando, [], 'manifesto da Fase 187 ausente — teste sem id no manifesto NUNCA roda');
    assert.deepEqual(malformadas, [], 'linha de manifesto fora da forma — o harness ABORTA nela, e abortar nao e passar');
    assert.deepEqual(zerados, [], "id com tests=0 — 'tests=0' nao e contagem, e numero de fora");

    // ⚠️ PISO de ids, não igualdade. A lista de ids **cresce** quando uma fase
    // futura acrescenta gate, e nunca deve **encolher**. Medido em 18/10/2026:
    // 66 ids nos manifestos 01..10, mais os deste plano.
    assert.ok(
        ids.length >= 70,
        `os manifestos da fase tem ${ids.length} ids, abaixo do piso de 70 (66 das waves 1-10 + os deste plano) — a lista ENCOLHEU`,
    );

    // Nenhum id repetido DENTRO do mesmo manifesto (o harness pega o primeiro e
    // o segundo nunca roda).
    const repetidos = ids.filter((v, i) => ids.indexOf(v) !== i);
    assert.deepEqual(repetidos, [], 'id repetido no mesmo manifesto — o harness roda o primeiro e o segundo fica morto');

    // ⚠️ ISCA NAS DUAS DIREÇÕES da sonda de forma.
    assert.ok(
        /^(\S+)\s+(js|php)\s+(.+?)\s+::\s+(.+)$/.test('meu-id  js  tests/js/x.test.js  ::  tests=12'),
        'a sonda de forma do manifesto nao casa a propria forma que exige',
    );
    assert.equal(
        /^(\S+)\s+(js|php)\s+(.+?)\s+::\s+(.+)$/.test('meu-id  rust  x  ::  tests=1'),
        false,
        'a sonda de forma do manifesto aceita modo que o harness nao conhece',
    );
});

test('187-11: o id do gate de vocabulario usa >=N e NAO esta embutido em id composto', () => {
    // ⚠️ `publicador-vocabulario-novo.test.js` tem contagem que **cresce por
    // construção**: um sub-teste por arquivo varrido nos diretórios fechados.
    // Por isso ele tem **id PRÓPRIO** (`vocabulario-tamanhos`), com a forma
    // `>=N`, e **não** pode ser embutido num id composto com outro arquivo —
    // num id composto a contagem agregada volta a ser frágil, e a correção
    // barata seria elevar o número a cada wave (a NONA ocorrência da família).
    const m01 = fs.readFileSync(abs('tests/gates/contagens-187-01.txt'), 'utf8');
    const linha = m01
        .split(/\r?\n/)
        .find((l) => /^vocabulario-tamanhos\s/.test(l));
    assert.ok(linha, 'o id `vocabulario-tamanhos` saiu de contagens-187-01.txt');
    assert.match(linha, /tests=>=\d+/, 'o id `vocabulario-tamanhos` deixou de usar a forma >=N');
    assert.match(linha, /tests\/js\/publicador-vocabulario-novo\.test\.js/, 'o id `vocabulario-tamanhos` aponta para outro arquivo');

    // ⚠️ E ele aponta para UM arquivo só (id composto somaria contagens).
    const alvos = linha.replace(/^\S+\s+js\s+/, '').replace(/\s+::.*$/, '').trim().split(/\s+/);
    assert.equal(alvos.length, 1, 'o id `vocabulario-tamanhos` virou id COMPOSTO — a contagem agregada e fragil');

    // ⚠️ E `publicador-vocabulario-novo.test.js` não aparece em NENHUM outro id
    // de NENHUM manifesto da fase — nem como segundo alvo de um composto.
    const infratores = [];
    for (let i = 1; i <= 11; i++) {
        const nn = String(i).padStart(2, '0');
        const rel = `tests/gates/contagens-187-${nn}.txt`;
        if (!fs.existsSync(abs(rel))) continue;
        for (const l of fs.readFileSync(abs(rel), 'utf8').split(/\r?\n/)) {
            if (l.trim() === '' || /^\s*#/.test(l)) continue;
            if (!l.includes('publicador-vocabulario-novo.test.js')) continue;
            if (/^vocabulario-tamanhos\s/.test(l)) continue;
            infratores.push(`${rel}: ${l.trim()}`);
        }
    }
    assert.deepEqual(infratores, [], 'o gate de vocabulario foi embutido em outro id — a contagem dele cresce por construcao');

    // ⚠️ ISCA: a sonda de id composto conta mesmo os alvos.
    assert.equal(
        'a-b  js  um.test.js  dois.test.js  ::  tests=9'.replace(/^\S+\s+js\s+/, '').replace(/\s+::.*$/, '').trim().split(/\s+/).length,
        2,
        'a sonda de id composto nao conta os alvos',
    );
});
