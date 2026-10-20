// ═══════════════════════════════════════════════════════════════════════════════
// Fase 188, plano 01 — O ÚNICO TAMANHO NOVO (`21`) E OS TOKENS QUE **NÃO** FORAM
// CRIADOS.
//
// ⚠️ A pergunta do GATE-01, respondida para o arquivo inteiro — *o que precisaria
// estar errado no mundo para estes gates falharem?*
//   · `21` não ter entrado em `TAMANHOS_PERMITIDOS`, e então o painel teria de
//     arredondar o KPI dos 3 cartões para `20px`/`22px`, que já estão na lista —
//     a "aproximação porque o Tailwind não tem esse passo" que FID-02/03 VETAM;
//   · a régua ter sido aberta DE MAIS: alguém liberar `23`, ou qualquer outro,
//     "por precaução". Régua aberta sem medição treina o executor seguinte a
//     abri-la de novo (T-188-01, Tampering);
//   · `shadow-pub-painel` deixar de ser EXATAMENTE
//     `-24px 0 64px rgba(0,0,0,0.6)` — e então o painel ganharia uma sombra
//     parecida, que é FID-02 literal;
//   · um dos 5 tokens que o painel REUSA mudar de valor, ou desaparecer;
//   · **este plano** inventar token novo — inventar token que JÁ EXISTE é a outra
//     metade do erro de "usar o token parecido".
//
// ⚠️⚠️ AS DUAS LEITURAS, E A RAZÃO DE CADA UMA:
//   · `TAMANHOS_PERMITIDOS` é lido por REGEX sobre a FONTE do arquivo de
//     vocabulário, **não por import**: importar aquele módulo RODARIA os 548
//     testes dele dentro deste gate.
//   · `tailwind.config.js` é lido por IMPORT DE VERDADE (com o resolvedor
//     ensinado, receita da 187), porque o que importa é o valor que o build
//     resolve, não o literal do arquivo.
//   · ⚠️ Para as AUSÊNCIAS a leitura é SEM COMENTÁRIO (`lerSemComentarios`):
//     palavra no docblock que explica *"não uso isso"* casa com o gate que conta
//     isso, e a wave 187-04 teve TRÊS mutações abortando por esse motivo.
//     ➡️ **ISCA DO LEITOR, de propósito:** a prosa deste arquivo cita
//     `ecf-yellow`, `font-display`, `ecf-bg`, `ecf-card` e
//     `border-white/[0.08]` — os cinco tokens VETADOS pelo FID-04 —, e o gate
//     tem de continuar **VERDE**. Se ele reprovar por causa deste parágrafo, a
//     leitura voltou a ser crua.
//   · ⚠️ E para GLOBS a leitura seria CRUA: a 187-11 mediu que
//     `lerSemComentarios` **COME** o `/**/` de `'./resources/js/**/*.jsx'` (chega
//     como `'./resources/js*.jsx'`). Este gate não lê glob nenhum, e é por isso
//     que não precisa da leitura crua — mas quem acrescentar aqui precisa saber.
//
// ⚠️ Todo sub-teste deste arquivo leva `188-01` no nome, e nenhum é prefixo de
// outro. Conferido por script contra todo `test_*` de `tests/`, nas duas
// direções: ARQUIVOS=1208 METODOS=7755 HOMONIMOS=0.
// ═══════════════════════════════════════════════════════════════════════════════

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { createRequire, registerHooks } from 'node:module';
import { execFileSync } from 'node:child_process';
import { lerSemComentarios } from './_fonte.js';

const RAIZ = resolve(import.meta.dirname, '../..');

// O `tailwind.config.js` é ESM e importa `tailwindcss/defaultTheme`, que o ESM do
// Node não resolve (o pacote não tem mapa `exports`). Em vez de mexer no config —
// lido pelo build de TODO o sistema —, ensina-se o resolvedor. É a receita já
// usada em `publicador-fila-tokens-187.test.js`, e assim o que se importa é o
// config DE VERDADE, sem cópia nem transformação.
assert.equal(
    typeof registerHooks,
    'function',
    'node:module.registerHooks indisponível — exige Node >= 22.15; atualize o Node para rodar este gate',
);
const exigir = createRequire(import.meta.url);
registerHooks({
    resolve(especificador, contexto, proximo) {
        if (especificador === 'tailwindcss/defaultTheme') {
            return { url: pathToFileURL(exigir.resolve('tailwindcss/defaultTheme')).href, shortCircuit: true };
        }
        return proximo(especificador, contexto);
    },
});

const CONFIG = (await import('../../tailwind.config.js')).default;
const EXT = CONFIG.theme.extend;

const REL_VOCABULARIO = 'tests/js/publicador-vocabulario-novo.test.js';
const REL_FILA_187 = 'tests/js/publicador-fila-tokens-187.test.js';

/**
 * Os arquivos que ESTE PLANO escreve ou altera. ⚠️ Lista **CONSTANTE**, nunca
 * varredura de diretório: varrer `app/`/`resources/` reprovaria trabalho de outra
 * sessão, e foi a 5ª ocorrência de "gate mais largo que o invariante" na Fase
 * 186 — aquela **barrou um deploy**.
 */
const ARQUIVOS_DESTE_PLANO = [
    'tests/js/publicador-painel-tokens-188.test.js',
    'tests/js/publicador-vocabulario-novo.test.js',
    'tests/js/publicador-fila-tokens-187.test.js',
];

function tamanhosPermitidos() {
    const fonte = lerSemComentarios(REL_VOCABULARIO);
    const m = fonte.match(/const\s+TAMANHOS_PERMITIDOS\s*=\s*new Set\(\s*\[([^\]]*)\]/);
    assert.ok(m, 'não achei o literal de TAMANHOS_PERMITIDOS na fonte sem comentários');
    return m[1].split(',').map((t) => t.trim()).filter(Boolean).map(Number);
}

/**
 * Os tamanhos que as fases DEPOIS da 187 liberaram **com a medida escrita ao
 * lado**, lidos do literal `LIBERADOS_DEPOIS_DA_187` de
 * `publicador-fila-tokens-187.test.js` — a ÚNICA escrituração de régua ampliada
 * desta milestone (criada pela wave 188-01, commit `61e84710`). ⚠️ **FONTE
 * ÚNICA, de propósito:** copiar a lista para cá recriaria o problema que ela
 * resolve — cada lugar esquecido é uma reprovação de trabalho legítimo.
 *
 * ⚠️⚠️ **LEITURA SEM COMENTÁRIO, e isto NÃO é zelo: é medição.** Aquele literal
 * carrega a medição de cada valor em `//` **DENTRO** do array, e a prosa do `18`
 * cita `grep -oE 'font-size:[0-9.]+px'`. ➡️ Lido CRU, o `[^\]]*` **TRUNCA no `]`
 * de `[0-9.]`**: medido nesta máquina em 20/10/2026, o corpo sai com **552
 * bytes**, **7 pseudo-valores**, **`NaN` entre eles** e **sem o `18`**. Ou seja,
 * a leitura crua erraria em SILÊNCIO — e para o lado de barrar trabalho correto.
 *
 * @returns {number[]} os tamanhos DECLARADOS como medidos depois da 187
 */
function liberadosDepoisDa187() {
    const fonte = lerSemComentarios(REL_FILA_187);
    const m = fonte.match(/const\s+LIBERADOS_DEPOIS_DA_187\s*=\s*\[([^\]]*)\]/);
    assert.ok(m, `não achei o literal de LIBERADOS_DEPOIS_DA_187 em ${REL_FILA_187} (fonte SEM comentários)`);
    return m[1].split(',').map((t) => t.trim()).filter(Boolean).map(Number);
}

/**
 * Os vizinhos de régua que este gate VIGIA: os passos de `font-size` que
 * aparecem "por precaução" quando alguém arredonda em vez de medir. Lista
 * CONSTANTE — e ela **não é** a lista dos proibidos, é a dos vigiados.
 */
const VIZINHOS_VIGIADOS = [18, 19, 21.5, 23, 25, 26];

/**
 * ⚠️⚠️ **O CONSERTO DA RAIZ, e é uma função PURA de propósito:** devolve os
 * vigiados que entraram em `TAMANHOS_PERMITIDOS` **sem estar DECLARADOS** como
 * medidos. Pura é o que permite exercê-la nas DUAS direções com dado sintético,
 * sem depender de a árvore estar num estado particular.
 *
 * @param {number[]} permitidos a régua de hoje (`TAMANHOS_PERMITIDOS`)
 * @param {number[]} declarados a escrituração (`LIBERADOS_DEPOIS_DA_187`)
 * @param {number[]} vigiados os vizinhos sob vigília
 * @returns {number[]} os infratores
 */
function entraramSemDeclaracao(permitidos, declarados, vigiados = VIZINHOS_VIGIADOS) {
    return vigiados.filter((v) => permitidos.includes(v) && !declarados.includes(v));
}

// ───────────────────────────────────────────────────────────────────────────────
// BLOCO 1 — O `21` ENTROU, E **NADA ALÉM DELE**
// ───────────────────────────────────────────────────────────────────────────────

test('TOK-188-01 — `21` está em TAMANHOS_PERMITIDOS, e nada entra sem DECLARAÇÃO (isca invertida)', () => {
    const lista = tamanhosPermitidos();

    // ⚠️ Leitura cega primeiro: sem isto, "23 não está" seria verdade sobre uma
    // lista de zero valores (learnings §4b.1).
    assert.ok(lista.length >= 24, `leitura suspeita de cega: ${lista.length} valores em TAMANHOS_PERMITIDOS`);
    assert.ok(lista.every((v) => Number.isFinite(v) && v > 0), `valor não-numérico na lista: ${lista.join(', ')}`);

    // O que ESTE plano liberou.
    assert.ok(
        lista.includes(21),
        `21 não está em TAMANHOS_PERMITIDOS — sem ele o painel teria de arredondar os 3 KPIs de ${'`:642`'}-${'`:644`'} para 20px ou 22px, que FID-03 proíbe`,
    );

    // ⚠️⚠️ A ISCA INVERTIDA, e ela é o único motivo de este bloco medir algo: se o
    // gate só cobrasse "21 está lá", liberar a lista inteira passaria.
    //
    // ⚠️⚠️ **O QUE ESTAVA AQUI, E POR QUE SAIU — e não porque incomodou:**
    //   `assert.ok(!lista.includes(23))` mais
    //   `[18, 19, 21.5, 23, 25, 26].filter((v) => lista.includes(v))` — uma
    //   BLOCKLIST de seis números LITERAIS.
    //   · Ela REPROVOU em 20/10/2026, pelo `18`: o plano **189-01** liberou o
    //     `18` MEDINDO (as setas ← → do lightbox, `Aprovacao.dc.html:741/:742`,
    //     glifo de 18px num círculo de 44px) e DECLAROU a medida em
    //     `LIBERADOS_DEPOIS_DA_187`. Arredondar para 17px/20px é vetado por
    //     FID-02/FID-03. ➡️ O `18` deixou de ser *"sem medição"*, mas o LITERAL
    //     não tinha como saber: ele reprovou **trabalho correto**.
    //   · E o conserto barato — tirar o `18` da linha — resolveria o `18` e
    //     deixaria `19`, `21.5`, `23`, `25` e `26` como minas para as Fases
    //     190-194, cada uma reprovando no dia em que medisse o seu valor.
    //   · ⚠️ Número que muda quando o trabalho CERTO é feito **ensina o executor
    //     seguinte a ajustar a régua quando ela incomoda**, e um dia ele ajusta
    //     uma que importava (`feedback_gate_que_reprova_pelo_proprio_conserto`).
    //     É a família "gate ancorado em número que muda legitimamente", com DOZE
    //     consertos anteriores nesta milestone.
    //
    // ➡️ **O INVARIANTE VERDADEIRO, e é ele que está escrito agora:** *nenhum
    // tamanho entra em `TAMANHOS_PERMITIDOS` sem estar DECLARADO como medido* —
    // e **não** *"estes seis números nunca aparecem"*. A blocklist **DERIVA** da
    // escrituração: vigiado presente na régua e ausente da declaração REPROVA;
    // vigiado presente nas DUAS passa. Mesma forma do próprio
    // `LIBERADOS_DEPOIS_DA_187` (`61e84710`) e do `JSX_DEPOIS_DA_187`
    // (`b10c19cc`, que reapontou para a invariante em vez de criar lista nova).
    //
    // ⚠️ E ela é **MAIS FORTE** que a blocklist, não só mais estável:
    //   · a blocklist cobria SEIS valores; a derivação cobre os seis **e** diz o
    //     que fazer com qualquer outro — quem liberar um valor fora da vigília
    //     cai no `TAM-187` da MESMA escrituração, que compara o conjunto INTEIRO;
    //   · a blocklist aceitava liberação sem medida desde que o número não
    //     estivesse nos seis; a derivação exige a **linha declarada com o plano e
    //     a medida ao lado**, que é diff VISÍVEL em vez de número trocado em
    //     silêncio;
    //   · e ela deixa de reprovar as Fases 190-194 por fazerem o certo.
    const declarados = liberadosDepoisDa187();

    // ⚠️ SONDA NÃO-CEGA **antes** da subtração: declaração vazia faria
    // `entraramSemDeclaracao` acusar TODOS os vigiados (falha pelo motivo
    // errado), e uma que casasse tudo não acusaria ninguém. "Lista vazia casa com
    // lista vazia" — learnings §4b.1.
    assert.ok(
        declarados.length >= 2,
        `leitura suspeita de cega: ${declarados.length} valores em LIBERADOS_DEPOIS_DA_187 — esperados ao menos o 21 (188-01) e o 18 (189-01)`,
    );
    assert.ok(
        declarados.every((v) => Number.isFinite(v) && v > 0),
        `valor não-numérico na declaração (a leitura voltou a ser crua?): ${declarados.join(', ')}`,
    );
    assert.ok(
        declarados.includes(21),
        'o `21` da 188-01 saiu de LIBERADOS_DEPOIS_DA_187 — a escrituração de que este gate deriva foi reescrita',
    );

    const semDeclaracao = entraramSemDeclaracao(lista, declarados);
    assert.deepEqual(
        semDeclaracao,
        [],
        `valores em TAMANHOS_PERMITIDOS sem medição DECLARADA: ${semDeclaracao.join(', ')} — régua aberta sem medição treina o executor seguinte a abri-la de novo (T-188-01). Mediu? Acrescente o valor a LIBERADOS_DEPOIS_DA_187 (${REL_FILA_187}), com o plano dono e a medida ao lado.`,
    );

    // ⚠️⚠️ ISCA INVERTIDA, CINCO PONTAS, CONTRA DADO **REAL** — é esta que prova
    // que derivar NÃO afrouxou nada: cada vigiado que HOJE não está declarado,
    // **se** entrasse na régua, SERIA acusado. ⚠️ E de propósito não é um
    // snapshot do tipo *"os barrados são exatamente estes cinco"*, que voltaria a
    // reprovar a fase que medir o `19` — o mesmo erro, de novo.
    const aindaNaoDeclarados = VIZINHOS_VIGIADOS.filter((v) => !declarados.includes(v));
    assert.ok(
        aindaNaoDeclarados.length >= 1,
        'TODOS os vigiados viraram declarados — este recorte deixou de medir; remeça os vizinhos contra o `188-REGUA.md` §3',
    );
    const seriamAcusados = aindaNaoDeclarados.filter((v) => entraramSemDeclaracao([...lista, v], declarados).includes(v));
    assert.deepEqual(
        seriamAcusados,
        aindaNaoDeclarados,
        `estes vigiados NÃO seriam acusados se entrassem na régua sem declaração: ${aindaNaoDeclarados.filter((v) => !seriamAcusados.includes(v)).join(', ')} — a derivação AFROUXOU o gate`,
    );

    // ⚠️ ISCA DO QUE **NÃO** DEVE ACUSAR: vigiado presente nas DUAS listas passa.
    // Sem este lado, um `entraramSemDeclaracao` que acusasse tudo pareceria
    // perfeito — e reprovaria a Fase 190 no dia em que ela medisse o `19`.
    assert.deepEqual(
        entraramSemDeclaracao([...lista, 19], [...declarados, 19]),
        [],
        'a derivação acusou um valor DECLARADO — ela reprovaria a fase que medir o 19',
    );

    // ⚠️ ISCA DO RECORTE: a lista de vigiados não pode esvaziar sem ninguém ver —
    // com ela vazia, as subtrações acima passariam sem provar nada.
    assert.equal(
        VIZINHOS_VIGIADOS.length,
        6,
        'a lista constante de vizinhos vigiados mudou de tamanho — remeça e escreva a razão',
    );
});

test('TOK-188-01 — a lista está em ORDEM NUMÉRICA e sem duplicata', () => {
    const lista = tamanhosPermitidos();
    const ordenada = [...lista].sort((a, b) => a - b);
    assert.deepEqual(lista, ordenada, 'TAMANHOS_PERMITIDOS saiu da ordem numérica — acréscimo descuidado no fim da lista');
    assert.equal(new Set(lista).size, lista.length, `há valor duplicado: ${lista.join(', ')}`);
});

test('TOK-188-01 — a medida do `21` está ESCRITA ao lado, no arquivo do gate', () => {
    // ⚠️ Liberar tamanho é afrouxar um gate. O que impede isso de virar porta dos
    // fundos é a medida escrita ao lado — e este caso prova que ela está lá, em
    // vez de confiar em quem revisa. Aqui o alvo é o COMENTÁRIO, então a leitura
    // é do arquivo CRU, de propósito.
    const cru = readFileSync(resolve(RAIZ, REL_VOCABULARIO), 'utf8');
    assert.match(cru, /Aprovacao\.dc\.html/, 'o registro não cita o protótipo de autoridade');
    assert.match(
        cru,
        /design_handoff_publicador/,
        'o registro não cita o DIRETÓRIO do protótipo (há duas cópias do pacote de handoff com conteúdo diferente)',
    );
    // A varredura COMPLETA do painel, para o próximo leitor saber que a lista é
    // MEDIDA e não acumulada.
    assert.match(
        cru,
        /9\.5 10 10\.5 11 11\.5 12 12\.5 13 13\.5 14 15 16 20 21/,
        'a varredura completa de font-size do painel E2 não está escrita ao lado do 21',
    );
    // E os 3 KPIs nomeados, que são o uso medido.
    for (const ponteiro of ['`:642`', '`:643`', '`:644`']) {
        assert.ok(cru.includes(ponteiro), `o uso do 21 não nomeia ${ponteiro} (um dos 3 KPIs da coluna de preço)`);
    }
});

// ───────────────────────────────────────────────────────────────────────────────
// BLOCO 2 — `shadow-pub-painel` JÁ EXISTE, E O VALOR É **EXATO**
// ───────────────────────────────────────────────────────────────────────────────

/** O valor medido no `<aside>` do painel (`design_handoff_publicador/Aprovacao.dc.html:550`). */
const SOMBRA_DO_PAINEL = '-24px 0 64px rgba(0,0,0,0.6)';

test('TOK-188-01 — `shadow-pub-painel` existe e é EXATAMENTE a sombra do protótipo', () => {
    assert.ok(EXT.boxShadow, 'theme.extend.boxShadow não existe no tailwind.config.js');
    assert.ok(
        Object.prototype.hasOwnProperty.call(EXT.boxShadow, 'pub-painel'),
        'o token `pub-painel` não existe em theme.extend.boxShadow — este plano NÃO deve criá-lo: ele já existia',
    );

    // ⚠️ IGUALDADE, não `includes`. `includes`/`substring` casa prefixo **e**
    // sufixo de outro nome e já mordeu CINCO vezes neste projeto (o
    // `includes('h-[64px]')` que casava `min-h-[64px]`, o `/0%/` que casava
    // "100%", o `/disabled/` que casa `disabled:opacity-40`...).
    assert.equal(
        EXT.boxShadow['pub-painel'],
        SOMBRA_DO_PAINEL,
        'shadow-pub-painel divergiu da sombra medida no protótipo — o painel ganharia uma sombra PARECIDA, que é FID-02 literal',
    );

    // ⚠️ ISCA DO QUASE-IGUAL: prova que a igualdade DISCRIMINA. Com `includes`
    // ou com uma comparação frouxa, `rgba(0,0,0,0.60)` passaria por
    // `rgba(0,0,0,0.6)`.
    assert.notEqual(
        EXT.boxShadow['pub-painel'],
        '-24px 0 64px rgba(0,0,0,0.60)',
        'a comparação aceitou o quase-igual `0.60` — ela não discrimina',
    );
    assert.notEqual(EXT.boxShadow['pub-painel'], '-24px 0 64px rgba(0,0,0,.6)', 'a comparação aceitou `.6` sem o zero');
    assert.notEqual(EXT.boxShadow['pub-painel'], '-24px 0 64px rgba(0,0,0,0.6) ', 'a comparação aceitou espaço no fim');
});

// ───────────────────────────────────────────────────────────────────────────────
// BLOCO 3 — OS 5 TOKENS QUE O PAINEL **REUSA**, com o valor do protótipo
// ───────────────────────────────────────────────────────────────────────────────

/**
 * Lista **CONSTANTE** do que o painel reusa, com o valor medido no protótipo.
 * ⚠️ UMA asserção coletando os infratores, **nunca uma por token** — asserção por
 * item faria a contagem do gate crescer junto com a lista, que é a fragilidade de
 * learnings §4b.2 (nove ocorrências nesta milestone).
 * ⚠️ E é lista CONSTANTE, **não piso**: a 187-11 mediu uma sobrevivente de mutação
 * exatamente num `>= N` (com 13 itens, perder um deixava 12 e o piso passava).
 */
const TOKENS_REUSADOS = [
    ['colors.pub.sunken', () => CONFIG.theme.extend.colors.pub.sunken, '#0D0D0D', 'fundo do painel (`:550`)'],
    ['colors.pub.line-strong', () => CONFIG.theme.extend.colors.pub['line-strong'], '#2E2E2E', 'borda esquerda do painel (`:550`)'],
    ['colors.pub.surface', () => CONFIG.theme.extend.colors.pub.surface, '#111111', 'as duas `<section>` de coluna (`:587`/`:639`)'],
    ['colors.pub.line', () => CONFIG.theme.extend.colors.pub.line, '#262626', 'borda das `<section>` (`:587`/`:639`)'],
    ['borderRadius.pub-table', () => CONFIG.theme.extend.borderRadius['pub-table'], '14px', 'raio das `<section>` (`:587`/`:639`)'],
];

test('TOK-188-01 — os 5 tokens que o painel REUSA existem com o valor medido', () => {
    const divergentes = [];
    for (const [chave, ler, esperado, onde] of TOKENS_REUSADOS) {
        let obtido;
        try {
            obtido = ler();
        } catch {
            obtido = '(explodiu ao ler — a camada mudou de forma)';
        }
        if (obtido !== esperado) divergentes.push(`${chave}: esperado ${esperado}, obtido ${String(obtido)} (${onde})`);
    }
    assert.deepEqual(
        divergentes,
        [],
        `tokens reusados pelo painel divergiram do protótipo:\n  ${divergentes.join('\n  ')}`,
    );

    // ⚠️ ISCA DO RECORTE: a lista não pode esvaziar sem ninguém ver. Com ela
    // vazia, o `deepEqual([], [])` acima passaria sem provar nada — "asserção de
    // ausência rodada com dado VAZIO é vazia por construção".
    assert.equal(TOKENS_REUSADOS.length, 5, 'a lista constante de tokens reusados mudou de tamanho — remeça e escreva a razão');

    // ⚠️ ISCA POSITIVA: prova que a comparação ACUSA quando o valor está errado.
    // Sem ela, um `obtido !== esperado` quebrado deixaria o laço sempre vazio.
    const falso = [['colors.pub.sunken', () => CONFIG.theme.extend.colors.pub.sunken, '#FFFFFF', 'isca']];
    const acusou = falso.filter(([, ler, esperado]) => ler() !== esperado);
    assert.equal(acusou.length, 1, 'a comparação NÃO acusou um valor deliberadamente errado — ela não mede nada');
});

test('TOK-188-01 — os valores dos 5 tokens batem com o `.dc.html` (degrada para skip NOMEADO)', (t) => {
    // ⚠️ `design_handoff_publicador/` **NÃO é versionado** (`??`) e não existe na
    // máquina de outro dev. ➡️ O gate degrada para `skip` EXPLÍCITO e NOMEADO,
    // **nunca** para "conferido".
    const proto = resolve(RAIZ, 'design_handoff_publicador/Aprovacao.dc.html');
    let html;
    try {
        html = readFileSync(proto, 'utf8');
    } catch {
        t.skip('SKIP NOMEADO: design_handoff_publicador/Aprovacao.dc.html não está no disco (pacote de handoff NÃO versionado). Os valores seguem cobrados contra a lista constante no caso anterior.');
        return;
    }

    const linhas = html.split(/\r?\n/);
    const aside = linhas[549] ?? ''; // `:550`, o <aside> do painel
    const secao = linhas[586] ?? ''; // `:587`, a 1ª <section> de coluna

    const faltando = [];
    if (!aside.includes('background:#0D0D0D')) faltando.push('`:550` não traz background:#0D0D0D (pub-sunken)');
    if (!aside.includes('border-left:1px solid #2E2E2E')) faltando.push('`:550` não traz border-left #2E2E2E (pub-line-strong)');
    if (!aside.includes(`box-shadow:${SOMBRA_DO_PAINEL}`)) faltando.push('`:550` não traz a box-shadow medida (shadow-pub-painel)');
    if (!secao.includes('background:#111111')) faltando.push('`:587` não traz background:#111111 (pub-surface)');
    if (!secao.includes('border:1px solid #262626')) faltando.push('`:587` não traz border #262626 (pub-line)');
    if (!secao.includes('border-radius:14px')) faltando.push('`:587` não traz border-radius:14px (rounded-pub-table)');
    assert.deepEqual(faltando, [], `o protótipo não confirma os tokens reusados:\n  ${faltando.join('\n  ')}`);

    // ISCA: a leitura não é cega — o documento medido tem 1432 linhas.
    assert.equal(linhas.length >= 1432, true, `leitura suspeita de cega: ${linhas.length} linhas no protótipo`);
});

// ───────────────────────────────────────────────────────────────────────────────
// BLOCO 4 — ⚠️⚠️ **ESTE PLANO** NÃO ADICIONOU TOKEN
//
// E **não** "a camada ficou idêntica à PARTIDA para sempre". A primeira redação
// do plano exigia que as chaves de `theme.extend.colors.pub`/`borderRadius`/
// `boxShadow` ficassem IDÊNTICAS ao commit de partida — e isso é **GATE MAIS
// LARGO QUE O INVARIANTE**, a 11ª ocorrência dessa família nesta milestone:
// reprovaria o trabalho CORRETO das Fases 189-194 e da sessão paralela, que
// legitimamente acrescentam token. ⚠️ Gate que reprova trabalho certo é tão caro
// quanto o que passa calado, **porque é ele que ensina o executor seguinte a
// mexer na régua** — e um dia ele mexe numa que importava.
//
// ➡️ O invariante certo é por **ESCOPO DO ASSUNTO**: nenhum commit cujo assunto
// seja `(188-01)` acrescenta chave àquelas três camadas.
// ⚠️ **RANGE CRU NÃO SERVE** (`188-REGUA.md` §6, regra 9): a 187-11 mediu 111
// commits no range, **43 da sessão paralela**, e o diff cru **acusava 12 dos 60
// alvos vetados**. ⚠️ **E o AUTOR não discrimina nada**: as três máquinas desta
// árvore commitam com o MESMO `user.name` (`MB.ECF-100376`), então `%an` é vazio
// por construção aqui.
// ───────────────────────────────────────────────────────────────────────────────

const ESCOPO_DO_PLANO = '(188-01)';

function git(...args) {
    return execFileSync('git', args, { cwd: RAIZ, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
}

/** Os commits cujo ASSUNTO carrega o escopo deste plano. */
function commitsDoEscopo(escopo = ESCOPO_DO_PLANO) {
    const saida = git('log', '-n', '500', '--format=%H\u001f%s');
    return saida
        .split(/\r?\n/)
        .filter(Boolean)
        .map((l) => {
            const [sha, assunto] = l.split('\u001f');
            return { sha, assunto: assunto ?? '' };
        })
        .filter((c) => c.assunto.includes(escopo));
}

/**
 * O analisador: dado o texto de um diff de `tailwind.config.js`, devolve as
 * chaves ACRESCENTADAS. ⚠️ Função PURA, de propósito — é o que permite exercê-la
 * com diff sintético nas duas direções, sem depender de a árvore estar num
 * estado particular.
 */
function chavesAcrescentadas(diff) {
    return diff
        .split(/\r?\n/)
        .filter((l) => l.startsWith('+') && !l.startsWith('+++'))
        .map((l) => l.slice(1))
        // ⚠️⚠️ VARREDURA GLOBAL DA LINHA, e não só do começo dela — achado da
        // INVESTIGAÇÃO da única sobrevivente da campanha de mutação (a nº 3).
        // A primeira redação ancorava em `^\s*`, então numa linha com DUAS chaves
        // (`'line-strong': '#2E2E2E', 'linha-inventada-188': '#ABCDEF',`) o
        // VEREDICTO ficava CERTO — a lista saía não-vazia e o gate morreria —, mas
        // a mensagem **nomeava `line-strong`**, a chave LEGÍTIMA, em vez da
        // inventada. ⚠️ Mensagem que nomeia a coisa errada é como o executor
        // seguinte conserta a coisa errada: é a família do *"gate que empurrava
        // para o conserto errado"* (`188-REGUA.md` §6, regra 8).
        // ⚠️ O guard `['"[{]` depois dos dois-pontos é o que impede casar DENTRO
        // de um valor: `max-height:calc(100vh - 140px)` **não** casa, porque
        // `calc(` não começa por aspa nem colchete.
        .flatMap((l) => [...l.matchAll(/'?([A-Za-z][A-Za-z0-9-]*)'?\s*:\s*['"[{]/g)].map((m) => m[1]));
}

test('TOK-188-01 — nenhum commit de escopo `(188-01)` acrescenta token (passada A)', () => {
    const meus = commitsDoEscopo();

    // ⚠️ A prova de que o escopo NÃO está vazio é asserção COLETADA, nunca
    // `return` antecipado: gate sobre zero casos não prova nada, e um escopo
    // vazio passaria calado.
    assert.ok(
        meus.length >= 1,
        `o escopo ${ESCOPO_DO_PLANO} não casou commit nenhum — ou o assunto mudou de forma, ou este gate está medindo o vazio`,
    );

    const infratores = [];
    for (const c of meus) {
        const nomes = git('show', '--name-only', '--format=', c.sha).split(/\r?\n/).map((s) => s.trim());
        if (!nomes.includes('tailwind.config.js')) continue;
        const diff = git('show', '--unified=0', '--format=', c.sha, '--', 'tailwind.config.js');
        for (const chave of chavesAcrescentadas(diff)) {
            infratores.push(`${c.sha.slice(0, 8)} (${c.assunto}) acrescentou a chave '${chave}'`);
        }
    }
    // ⚠️ UMA asserção coletando infratores: a contagem deste gate NÃO depende de
    // quantos commits o escopo tem nem de quantas chaves a camada tem.
    assert.deepEqual(
        infratores,
        [],
        `commits de ${ESCOPO_DO_PLANO} acrescentaram token, e este plano não deveria criar nenhum:\n  ${infratores.join('\n  ')}`,
    );

    // ⚠️⚠️ ISCA (a) — O ANALISADOR **ACUSA** quando a chave é acrescentada. Sem
    // isto, "zero infratores" seria indistinguível de um analisador quebrado.
    const diffFalso = [
        'diff --git a/tailwind.config.js b/tailwind.config.js',
        '--- a/tailwind.config.js',
        '+++ b/tailwind.config.js',
        '@@ -150,0 +151 @@',
        "+                    'line-inventada': '#ABCDEF',",
    ].join('\n');
    assert.deepEqual(
        chavesAcrescentadas(diffFalso),
        ['line-inventada'],
        'o analisador NÃO acusou uma chave claramente acrescentada — ele não mede nada',
    );

    // ⚠️⚠️ ISCA (b) — O ESCOPO **NÃO** pega commit de outro assunto. É isto que
    // impede o gate de reprovar as Fases 189-194 e a sessão paralela.
    assert.deepEqual(
        commitsDoEscopo('(188-99-nao-existe)'),
        [],
        'o filtro de escopo casou um assunto que não existe',
    );
    const deOutroAssunto = commitsDoEscopo('(187-');
    assert.ok(
        deOutroAssunto.every((c) => !c.assunto.includes(ESCOPO_DO_PLANO)),
        'o escopo deste plano e o da 187 se sobrepõem — o recorte por assunto não discrimina',
    );

    // ⚠️ ISCA (c) — linha de CONTEXTO e linha REMOVIDA não contam como acréscimo.
    assert.deepEqual(chavesAcrescentadas("                     'line': '#262626',"), [], 'contexto contou como acréscimo');
    assert.deepEqual(chavesAcrescentadas("-                    'line': '#262626',"), [], 'remoção contou como acréscimo');
    assert.deepEqual(chavesAcrescentadas('+++ b/tailwind.config.js'), [], 'o cabeçalho `+++` contou como acréscimo');
});

/**
 * A PARTIDA deste plano, registrada em `188-BASELINES.md`.
 *
 * ⚠️⚠️ **Ela NÃO é extremo de range, e nenhum gate deste plano a usa como tal** —
 * "prova de território por RANGE" não passa nesta árvore (`188-REGUA.md` §6,
 * regra 9). Ela é **referência de LEITURA**: o commit do qual se lê o estado
 * anterior por `git show PARTIDA:<arquivo>` (⚠️ **nunca `git checkout`**, que
 * restaura o estado COMMITADO e já apagou ~90 linhas de gate não commitado).
 */
const PARTIDA = 'db9b1ff9';

/**
 * Os 6 tokens que o painel REUSA, com o literal exato como ele aparecia no
 * `tailwind.config.js` **na PARTIDA**. Lista CONSTANTE.
 *
 * ⚠️ Provar "a chave existe HOJE" seria fraco: este plano poderia tê-la criado.
 * Provar que ela **já existia na PARTIDA com o MESMO valor** é o que sustenta a
 * afirmação *"quase nenhum token é novo — USE os que existem"*.
 */
const REUSADOS_NA_PARTIDA = [
    ['colors.pub.sunken', /\bsunken:\s*'#0D0D0D'/],
    ['colors.pub.line-strong', /'line-strong':\s*'#2E2E2E'/],
    ['colors.pub.surface', /\bsurface:\s*'#111111'/],
    ['colors.pub.line', /\bline:\s*'#262626'/],
    ['borderRadius.pub-table', /'pub-table':\s*'14px'/],
    ['boxShadow.pub-painel', /'pub-painel':\s*'-24px 0 64px rgba\(0,0,0,0\.6\)'/],
];

test('TOK-188-01 — os 6 tokens que o painel reusa JÁ EXISTIAM na PARTIDA (passada B)', () => {
    // A segunda passada é INDEPENDENTE da primeira: em vez de olhar o ASSUNTO dos
    // commits, olha o CONTEÚDO do arquivo no commit de partida. Duas passadas
    // independentes é o que o plano prescreve, e é o que impede que um erro na
    // leitura do histórico derrube a conclusão inteira.
    const naPartida = git('show', `${PARTIDA}:tailwind.config.js`);

    // ⚠️ Leitura cega primeiro: um blob vazio faria todas as buscas falharem por
    // um motivo que NÃO é o código, e um blob truncado faria o contrário.
    assert.ok(
        naPartida.length > 10000,
        `o tailwind.config.js da PARTIDA veio com ${naPartida.length} bytes — leitura suspeita de cega`,
    );

    const ausentes = REUSADOS_NA_PARTIDA
        .filter(([, rx]) => !rx.test(naPartida))
        .map(([nome]) => nome);
    // ⚠️ UMA asserção coletando infratores: a contagem deste gate NÃO depende de
    // quantos tokens a camada tem.
    assert.deepEqual(
        ausentes,
        [],
        `estes tokens NÃO existiam com o mesmo valor na PARTIDA ${PARTIDA}: ${ausentes.join(', ')} — ou este plano os criou/alterou, ou a medição do plano estava errada`,
    );

    // ⚠️ ISCA DO RECORTE: a lista não pode esvaziar sem ninguém ver.
    assert.equal(REUSADOS_NA_PARTIDA.length, 6, 'a lista constante dos reusados mudou de tamanho — remeça e escreva a razão');

    // ⚠️⚠️ ISCA POSITIVA: a sonda ACUSA um token que não está lá. Sem isto,
    // "nenhum ausente" seria indistinguível de um `rx.test` sempre verdadeiro.
    assert.deepEqual(
        [['inventado', /'pub-inventado-188-01':/]].filter(([, rx]) => !rx.test(naPartida)).map(([n]) => n),
        ['inventado'],
        'a sonda NÃO acusou um token inexistente — ela não mede nada',
    );

    // ⚠️ ISCA NEGATIVA: e ela ACHA o que está lá, com o valor certo.
    assert.ok(/'pub-painel':/.test(naPartida), 'a sonda não achou `pub-painel`, que comprovadamente existe na PARTIDA');

    // ⚠️ E o valor de HOJE é o MESMO da PARTIDA — o que fecha a passada: nem
    // criado, nem alterado por este plano.
    assert.equal(
        EXT.boxShadow['pub-painel'],
        SOMBRA_DO_PAINEL,
        'shadow-pub-painel mudou entre a PARTIDA e agora',
    );
});

// ───────────────────────────────────────────────────────────────────────────────
// A ISCA DO LEITOR — os 5 tokens VETADOS pelo FID-04, lidos SEM COMENTÁRIO
// ───────────────────────────────────────────────────────────────────────────────

/** Os tokens que o FID-04 VETA no painel. Lista CONSTANTE. */
const VETADOS_PELO_FID04 = ['ecf-bg', 'ecf-card', 'ecf-yellow', 'font-display', 'border-white/[0.08]'];

/**
 * A sonda de token vetado. ⚠️ Função PURA, e isso **não** é estilo: é o que
 * permite exercê-la com fonte sintética nas duas direções, sem depender de a
 * árvore estar num estado particular — e sem que a própria LISTA constante deste
 * arquivo (que, por definição, contém os cinco nomes) se denuncie.
 *
 * @returns {string[]} os vetados encontrados na fonte
 */
function vetadosUsadosEm(fonte) {
    return VETADOS_PELO_FID04.filter((v) => fonte.includes(v));
}

test('TOK-188-01 — a sonda do FID-04 DISCRIMINA, e este plano não cria arquivo sob `resources/`', () => {
    // ⚠️⚠️ O INVARIANTE ESCRITO COM HONESTIDADE, e é por isso que ele não é uma
    // varredura: **este plano não cria nem altera NENHUM arquivo sob
    // `resources/`** — ele entrega baselines, dois gates e dois documentos de
    // `.planning/`. Então "nenhuma classe vetada nos arquivos deste plano" seria
    // **gate sobre ZERO casos**, e zero casos não prova nada (learnings §4,
    // regra 4). ➡️ O que se afirma, e se confere, é o que é VERDADE:
    //   (1) este plano não tem arquivo sob `resources/`;
    //   (2) a sonda que as waves 2-13 vão usar **DISCRIMINA** — provado agora,
    //       para ela não nascer decorativa lá.
    const sobResources = ARQUIVOS_DESTE_PLANO.filter((f) => f.startsWith('resources/'));
    assert.deepEqual(
        sobResources,
        [],
        `este plano passou a ter arquivo sob resources/: ${sobResources.join(', ')} — então a varredura de token vetado tem de deixar de ser sintética e passar a varrer ESTES arquivos`,
    );
    assert.ok(ARQUIVOS_DESTE_PLANO.length >= 3, 'a lista de arquivos deste plano esvaziou — ela não pode ficar vazia por descuido');

    // ⚠️ ISCA POSITIVA, uma por FORMA que a sonda DEVE pegar — e são as cinco,
    // numa asserção só (contagem que não cresce com a lista).
    const fonteSuja = [
        'const a = "bg-ecf-bg";',
        'const b = cn("bg-ecf-card", x);',
        'const c = "text-ecf-yellow";',
        'const d = "font-display";',
        'const e = "border border-white/[0.08]";',
    ].join('\n');
    assert.deepEqual(
        vetadosUsadosEm(fonteSuja).sort(),
        [...VETADOS_PELO_FID04].sort(),
        'a sonda do FID-04 não pegou os cinco tokens vetados numa fonte que os usa — ela nasceria decorativa nas waves 2-13',
    );

    // ⚠️ ISCA NEGATIVA: e ela NÃO pega o que é legítimo. Sem este lado, uma sonda
    // que casasse tudo pareceria perfeita.
    const fonteLimpa = [
        'const a = "bg-pub-sunken";',
        'const b = cn("border-pub-line-strong", x);',
        'const c = "text-[13.5px] shadow-pub-painel rounded-pub-table";',
        'const d = "bg-pub-surface border-pub-line";',
    ].join('\n');
    assert.deepEqual(
        vetadosUsadosEm(fonteLimpa),
        [],
        'a sonda acusou token vetado numa fonte que só usa os tokens `pub-*` legítimos — ela reprovaria trabalho certo',
    );

    // ⚠️⚠️ ISCA DO LEITOR, nas duas direções — e ela é o que impede a wave
    // seguinte de "consertar" este gate apagando a prosa:
    // (a) o CRU deste arquivo cita os cinco vetados **de propósito**;
    // (b) e o gate continua **VERDE** — porque a conferência é sobre fonte
    //     SINTÉTICA e sobre a lista de arquivos, nunca sobre o texto deste
    //     arquivo. Palavra no docblock que explica *"não uso isso"* casa com o
    //     gate que conta isso, e a wave 187-04 teve TRÊS mutações abortando
    //     exatamente por esse motivo (learnings §4, regra 2).
    const esteArquivo = 'tests/js/publicador-painel-tokens-188.test.js';
    const cru = readFileSync(resolve(RAIZ, esteArquivo), 'utf8');
    assert.deepEqual(
        vetadosUsadosEm(cru).sort(),
        [...VETADOS_PELO_FID04].sort(),
        'a prosa deste arquivo deixou de citar os cinco vetados — a isca do leitor morreu e ninguém saberia que a leitura voltou a ser crua',
    );
    assert.equal(VETADOS_PELO_FID04.length, 5, 'a lista dos vetados pelo FID-04 mudou de tamanho — remeça contra o `188-REGUA.md` §3');
});
