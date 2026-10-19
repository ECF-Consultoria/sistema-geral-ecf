// ═══════════════════════════════════════════════════════════════════════════════
// Fase 187, plano 01 — OS 5 TOKENS DA FILA e OS 2 TAMANHOS LIBERADOS.
//
// POR QUE EXISTE
//
// Este plano não desenha tela nenhuma: ele deixa de pé as ferramentas que os
// DEZ planos seguintes vão usar. Duas delas moram em arquivos compartilhados
// (`tailwind.config.js` e `publicador-vocabulario-novo.test.js`) e as duas são
// AFROUXAMENTOS no sentido literal — um token novo é um nome novo que ninguém
// mediu ainda, e um tamanho liberado é um valor que o gate de vocabulário
// deixa de reprovar. Este arquivo é o preço de admissão dos dois.
//
// ⚠️ A PERGUNTA DE CADA GATE DAQUI ("o que precisaria estar errado no mundo
// para ele falhar?") está escrita em cima de cada `test()`. Onde a resposta era
// "nada", o gate virou skip NOMEADO com isca invertida ao lado — nunca silêncio.
//
// ─────────────────────────────────────────────────────────────────────────────
// ⚠️⚠️ O ACHADO QUE MUDOU O DESENHO DESTE ARQUIVO (medido em 15/10/2026)
//
// O plano pedia "cada um dos 5 tokens novos tem regra no CSS construído". Isso
// é IMPOSSÍVEL neste plano, e não por descuido: o `content` do
// `tailwind.config.js` é `['./resources/js/**/*.jsx', …]`, então o Tailwind só
// emite a classe que algum `.jsx` ESCREVE. Este plano não escreve nenhum.
//
// A prova não é raciocínio, é medida — `shadow-pub-painel` existe na camada
// desde a Fase 177 e NÃO está no CSS construído, porque nenhum `.jsx` o usa:
//
//     $ grep -c 'shadow-pub-painel' public/build/assets/app-BZcBiEAW.css   -> 0
//     $ grep -rl 'shadow-pub-painel' resources/js --include=*.jsx | wc -l  -> 0
//     $ grep -c 'bg-pub-raised-0'   public/build/assets/app-BZcBiEAW.css   -> 1
//     $ grep -rl 'bg-pub-raised-0'  resources/js --include=*.jsx | wc -l   -> 1
//
// Escrever a asserção como o plano pedia a tornaria VAZIA hoje (ou vermelha por
// motivo errado) e FALSO POSITIVO depois — é exatamente o §4b.1 do learnings.
// ➡️ O desenho é em DUAS CAMADAS:
//   · a camada do CONFIG roda SEMPRE (é o que este plano de fato entrega), por
//     `import()` do config de verdade — nunca por `grep` do texto, que contaria
//     a prosa em pt-BR que cita os próprios hex;
//   · a camada do CSS roda POR TOKEN, e pula com RAZÃO NOMEADA no token que
//     nenhum `.jsx` usa ainda. Ela ACORDA SOZINHA no plano que escrever a
//     classe — e é por isso que ela fica aqui em vez de esperar.
//   · e para a camada do CSS não ser decorativa enquanto dorme, ela carrega
//     CONTROLE POSITIVO (`bg-pub-raised-0`, que a sonda TEM de achar), ISCA
//     (classe `pub-*` inexistente, que ela NÃO pode achar) e ISCA INVERTIDA (o
//     precedente `shadow-pub-painel`, que prova que "ausente do CSS" é um
//     estado REAL e não a sonda cega).
// ─────────────────────────────────────────────────────────────────────────────
//
// ⚠️ AS NORMALIZAÇÕES DO CSS CONSTRUÍDO USADAS AQUI, todas MEDIDAS nesta rodada
// em `public/build/assets/app-BZcBiEAW.css` (227.384 bytes) e não presumidas:
//   (i)   token SÓLIDO sai DECIMAL com a variável de opacidade:
//         `.bg-pub-sunken{--tw-bg-opacity: 1;background-color:rgb(13 13 13 / var(--tw-bg-opacity, 1))}`
//         ➡️ esperar HEX aqui dá falsa falta (foram 25 na Fase 177).
//   (ii)  o cssnano COME O ZERO DA FRENTE, inclusive dentro da sombra:
//         `.shadow-pub-popover{--tw-shadow: 0 24px 60px rgba(0,0,0,.7);…}` — `.7`, não `0.7`.
//   (iii) o gradiente sai com o hex em MINÚSCULA:
//         `.bg-pub-hachura{background-image:repeating-linear-gradient(135deg,#3f3f46 0 3px,#262626 3px 6px)}`
//   (iv)  o seletor vem ESCAPADO (`.text-\[22px\]`), e escapar só a barra
//         invertida transformaria `[`, `]` e `.` em classe de caracteres.
//   (v)   `escapaRegex` escapa TODOS os metacaracteres, parênteses inclusive.
//   (vi)  ⚠️ UM MESMO SELETOR PODE TER DUAS REGRAS. Medido: `duration-200`
//         emite `{transition-duration:.2s}` **e** `{animation-duration:.2s}`.
//         Por isso a sonda devolve LISTA de corpos, não o primeiro — ler só o
//         primeiro é a 8ª cara do "substring casa com prefixo".
// ═══════════════════════════════════════════════════════════════════════════════

import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { relative, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { createRequire, registerHooks } from 'node:module';
import { lerSemComentarios } from './_fonte.js';

const RAIZ = resolve(import.meta.dirname, '../..');

// O `tailwind.config.js` é ESM e importa `tailwindcss/defaultTheme`, que o ESM
// do Node não resolve (o pacote não tem mapa `exports`). Em vez de mexer no
// config — lido pelo build de TODO o sistema —, ensina-se o resolvedor. É a
// mesma receita de `publicador-tokens-do-publicador.test.js`, e assim o que se
// importa é o config DE VERDADE, sem cópia nem transformação.
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

const REL_CONFIG = 'tailwind.config.js';
const REL_VOCABULARIO = 'tests/js/publicador-vocabulario-novo.test.js';
const DIR_JSX = resolve(RAIZ, 'resources/js');

// ═══════════════════════════════════════════════════════════════════════════════
// OS 5 TOKENS — o valor, a CLASSE que o Tailwind emite, e a medida no protótipo.
//
// ⚠️ Toda linha de `medidoEm` foi conferida com `grep -n` sobre
// `design_handoff_publicador/Aprovacao.dc.html` nesta sessão. Os números do
// plano estavam DESLOCADOS em dois casos (`:88`/`:98` para os popovers, que são
// `:82`/`:95`; e `:201` para a legenda, que é `:194`) — a tabela de fidelidade
// deste projeto não erra VALOR, erra por OMISSÃO e por REFERÊNCIA.
// ═══════════════════════════════════════════════════════════════════════════════
const TOKENS_NOVOS = [
    {
        caminho: ['colors', 'pub', 'sunken-2'],
        valor: '#0F0F0F',
        classe: 'bg-pub-sunken-2',
        propriedade: /background-color:\s*rgb\(15 15 15 \//,
        medidoEm: 'Aprovacao.dc.html:124 — fundo da sub-linha de conjunto de fotos',
        // `sunken` é #0D0D0D (cabeçalho/rodapé da tabela) e `raised-0` é
        // #131313: a sub-linha fica ENTRE os dois, e o protótipo pinta os três
        // distintos. "Usar o token parecido" é justificativa vetada (FID-02).
        vizinhos: { sunken: '#0D0D0D', 'raised-0': '#131313' },
    },
    {
        caminho: ['colors', 'pub', 'float'],
        valor: '#1C1C1C',
        classe: 'bg-pub-float',
        propriedade: /background-color:\s*rgb\(28 28 28 \//,
        medidoEm: 'Aprovacao.dc.html:897 (barra flutuante de lote) e :905 (toast)',
        // O hex já tem DOIS nomes na camada, e nenhum serve: `line-subtle-2` é
        // BORDA e `row-focus` é LINHA DE TABELA. Usar qualquer um para o fundo
        // de um elemento flutuante seria nome mentindo sobre o uso — é o
        // precedente declarado de `neutral-dot`/`txt-4`.
        vizinhos: { 'line-subtle-2': '#1C1C1C', 'row-focus': '#1C1C1C' },
    },
    {
        caminho: ['colors', 'pub', 'float-line'],
        valor: '#333333',
        classe: 'border-pub-float-line',
        propriedade: /border-color:\s*rgb\(51 51 51 \//,
        medidoEm: 'Aprovacao.dc.html:897, :898 (divisor do rótulo) e :905',
        // `seg-ring` é o `inset 0 0 0 1px` da opção ativa do segmented — outro uso.
        vizinhos: { 'seg-ring': '#333333' },
    },
    {
        caminho: ['backgroundImage', 'pub-anel-parcial'],
        valor: 'linear-gradient(90deg,#A1A1AA 50%,transparent 50%)',
        classe: 'bg-pub-anel-parcial',
        // ⚠️⚠️ **A PRESCRIÇÃO ANTERIOR ESTAVA ERRADA, E FOI CORRIGIDA POR MEDIÇÃO
        // NO PLANO 187-08 (16/10/2026) — o plano que ACORDOU este token.**
        //
        // Ela dizia *"(iii) o gradiente sai com o hex em MINÚSCULA no CSS
        // construído"* e cravava `#a1a1aa`. **Sai em MAIÚSCULA.** Medido no CSS de
        // verdade, com o token em uso por `AnelDeProgresso.jsx`:
        //
        //     bg-pub-anel-parcial  =>  background-image:linear-gradient(90deg,#A1A1AA 50%,transparent 50%)
        //
        // ⚠️ Por que o erro ficou invisível até agora: enquanto **nenhum `.jsx`**
        // usava a classe, este sub-teste **PULAVA** (é o `sub.skip()` nomeado
        // abaixo), então a regex **nunca foi exercida**. Era prescrição escrita às
        // CEGAS — exatamente o item 3 do learnings §3 (*"leia a forma REAL no CSS
        // construído antes de escrever a regex; prescrever às cegas foi a 8ª
        // mentira do medidor desta milestone"*). O próprio gate diz que **"ACORDA
        // SOZINHO no plano que escrever a classe"**: é por desenho que a wave que
        // acorda o token é a que confere (e conserta) a prescrição.
        //
        // ⚠️ `/i` de propósito: o que este gate prova é a ESTRUTURA do gradiente
        // (ângulo, as duas paradas em 50% e a cor), não a CAIXA do hex — que é
        // detalhe do minificador e já mudou de prescrição uma vez. Baixar a régua
        // para "qualquer `background-image`" é que seria afrouxar; isto não é.
        propriedade: /background-image:\s*linear-gradient\(90deg,#A1A1AA 50%,transparent 50%\)/i,
        medidoEm: 'Aprovacao.dc.html:1241 (PG.partial) e :194 (legenda do rodapé) — ⚠️ o plano cita :201',
        // Gradiente não tem token nenhum, e escrito na tela viraria hex cru, que
        // o gate de vocabulário proíbe. É o precedente de `pub-hachura`.
        vizinhos: {},
    },
    {
        caminho: ['boxShadow', 'pub-flutuante'],
        valor: '0 16px 40px rgba(0,0,0,0.6)',
        classe: 'shadow-pub-flutuante',
        // (ii) o zero da frente do alfa é comido pelo cssnano: `,.6)`.
        propriedade: /--tw-shadow:\s*0 16px 40px rgba\(0,0,0,\.6\)/,
        medidoEm: 'Aprovacao.dc.html:82 e :95 (os dois popovers), :897 e :905 — ⚠️ o plano cita :88/:98',
        // `pub-popover` é `0 24px 60px rgba(0,0,0,0.7)` e `pub-painel` é
        // `-24px 0 64px rgba(0,0,0,0.6)`. Valores DIFERENTES, e FID-03 proíbe
        // arredondar em direção ao token que já existe.
        vizinhos: {},
    },
];

/** Vizinhos de sombra, conferidos fora da tabela acima (chaves de `boxShadow`). */
const SOMBRAS_VIZINHAS = {
    'pub-popover': '0 24px 60px rgba(0,0,0,0.7)',
    'pub-painel': '-24px 0 64px rgba(0,0,0,0.6)',
};

const emCaminho = (raiz, caminho) => caminho.reduce((no, chave) => (no == null ? undefined : no[chave]), raiz);

// ───────────────────────────────────────────────────────────────────────────────
// 1. A CAMADA DO CONFIG — roda sempre.
//
// O que precisaria estar errado para falhar: um dos 5 tokens ausente, com hex
// aproximado, com o gradiente truncado, ou um dos VIZINHOS de que ele foi
// distinguido tendo sido sobrescrito pelo valor novo (o modo de falhar mais
// provável, porque os três hex novos têm vizinho de 2 e 3 pontos de distância).
// ───────────────────────────────────────────────────────────────────────────────

test('TOK-187 — os 5 tokens novos existem na camada com o valor MEDIDO no protótipo', () => {
    assert.equal(TOKENS_NOVOS.length, 5, 'a tabela deste gate deveria ter os 5 tokens do plano');

    // ⚠️ Contagem FIXA colhendo INFRATORES, não uma asserção por item: a
    // mensagem nomeia TODOS em vez de parar no primeiro (learnings §4b.2).
    const errados = TOKENS_NOVOS
        .map((t) => ({ t, veio: emCaminho(EXT, t.caminho) }))
        .filter(({ t, veio }) => veio !== t.valor)
        .map(({ t, veio }) => `theme.extend.${t.caminho.join('.')} = ${JSON.stringify(veio)} (esperado ${JSON.stringify(t.valor)}; medido em ${t.medidoEm})`);

    assert.deepEqual(errados, [], `tokens da Fase 187 ausentes ou com valor errado:\n  - ${errados.join('\n  - ')}`);
});

test('TOK-187 — os VIZINHOS de que cada token foi distinguido seguem intactos (FID-02)', () => {
    const pub = EXT.colors?.pub ?? {};
    const divergentes = [];

    for (const token of TOKENS_NOVOS) {
        for (const [nome, hex] of Object.entries(token.vizinhos)) {
            if (pub[nome] !== hex) divergentes.push(`pub.${nome} = ${JSON.stringify(pub[nome])} (esperado ${hex})`);
        }
    }
    for (const [nome, valor] of Object.entries(SOMBRAS_VIZINHAS)) {
        if (EXT.boxShadow?.[nome] !== valor) divergentes.push(`boxShadow['${nome}'] = ${JSON.stringify(EXT.boxShadow?.[nome])}`);
    }
    if (EXT.backgroundImage?.['pub-hachura'] !== 'repeating-linear-gradient(135deg,#3F3F46 0 3px,#262626 3px 6px)') {
        divergentes.push('backgroundImage[pub-hachura] mudou');
    }

    assert.deepEqual(divergentes, [], `um token novo atropelou o vizinho de que ele foi distinguido:\n  - ${divergentes.join('\n  - ')}`);

    // ⚠️ ISCA INVERTIDA: a lista de vizinhos conferidos é NÃO-VAZIA. Se um dia
    // `vizinhos` esvaziar, o caso acima passa sem medir nada — e "0 divergentes"
    // deixaria de ser notícia.
    const quantos = TOKENS_NOVOS.reduce((n, t) => n + Object.keys(t.vizinhos).length, 0)
        + Object.keys(SOMBRAS_VIZINHAS).length;
    assert.equal(quantos, 7, 'a lista de vizinhos conferidos mudou de tamanho — remeça antes de aceitar');
});

test('⚠️ ISCA do config: um token `pub-*` inexistente sai UNDEFINED', () => {
    // Sem esta isca, `emCaminho` devolvendo sempre algo faria os dois casos
    // acima passarem com a sonda cega.
    assert.equal(emCaminho(EXT, ['colors', 'pub', 'isca-187-nao-existe']), undefined);
    assert.equal(emCaminho(EXT, ['boxShadow', 'pub-isca-187']), undefined);
    assert.equal(emCaminho(EXT, ['backgroundImage', 'pub-isca-187']), undefined);
    // E o CONTROLE POSITIVO: a sonda ACHA o que existe desde a Fase 177.
    assert.equal(emCaminho(EXT, ['colors', 'pub', 'page']), '#0A0A0A');
});

// ═══════════════════════════════════════════════════════════════════════════════
// ⚠️⚠️ CIRURGIA DA FASE 188 (19/10/2026) — A ISCA INVERTIDA DE `pub-painel` FOI
// REAPONTADA COM VETO, E CONTINUA INVERTIDA. **Nenhum invariante foi afrouxado;
// um foi acrescentado.**
//
// O QUE ESTAVA AQUI (dentro do caso `CONTROLE POSITIVO + ISCA`, abaixo):
// `assert.equal(jsxQueUsam('shadow-pub-painel').length, 0)` — um TOTAL afirmado
// sobre um token que a Fase 188 **existe para usar**. A `188-REGUA.md` manda
// desenhar o painel de revisão com `-24px 0 64px rgba(0,0,0,0.6)` (critério 1),
// e esse valor **É** `boxShadow['pub-painel']`; o plano 188-07 o escreveu em
// `PainelDeRevisao.jsx`. O gate passou a reprovar trabalho CORRETO — e a própria
// mensagem dele já dizia o conserto: *"troque o precedente desta isca invertida"*.
//
// POR QUE NÃO SE SOBE O NÚMERO (de 0 para 1): as waves 188-08 a 188-13 põem mais
// peças no painel, e cada uma teria de subir de novo. É a família "contagem que
// depende de quantos arquivos existem", com DEZ ocorrências nesta milestone,
// **todas consertadas na RAIZ** com lista constante (learnings §4b.2 e
// `feedback_gate_que_reprova_pelo_proprio_conserto`): número que muda quando o
// trabalho certo é feito treina o executor seguinte a ajustar réguas quando elas
// incomodam, e um dia ele ajusta uma que importava.
//
// O QUE ENTROU, EM DUAS PARTES:
//   (a) O INVARIANTE DO TOKEN USADO deixou de ser *"ninguém usa"* e passou a ser
//       **"quem usa está DECLARADO"**, por igualdade de CONJUNTO contra
//       `QUEM_USA_SHADOW_PUB_PAINEL` — lista de NOMES, cada um com o plano dono.
//       ⚠️ É MAIS FORTE que o zero e que qualquer contagem: `.jsx` que passar a
//       escrever a sombra **sem entrar na lista continua REPROVANDO**, cada uso
//       fica NOMEADO, e acrescentar à lista é **diff VISÍVEL** — não um número
//       trocado em silêncio. (Mesmo molde do `LIBERADOS_DEPOIS_DA_187` deste
//       arquivo, plano 188-01, e dos reapontes que os planos 187-03 e 187-04
//       fizeram em `DA_FASE_180` e `DO_PORTAO_DA_FASE_186`.)
//   (b) A ISCA INVERTIDA **PERMANECE INVERTIDA**, com o PRECEDENTE TROCADO para
//       `ease-pub-painel` — o MESMO nome de token na camada
//       `transitionTimingFunction` (TOK-12: a curva de entrada do painel), que
//       continua declarado no config, continua sem NENHUM `.jsx` e continua
//       AUSENTE do CSS construído (medido em 19/10/2026 em
//       `public/build/assets/app-CtjSDO4t.css`: 0 ocorrências de
//       `ease-pub-painel`, contra 1 de `.shadow-pub-painel{…}`). É ele que agora
//       prova que "token no config e ausente do CSS" é um estado REAL deste
//       projeto, e não a sonda cega — e é isso que mantém honesto todo `skip`
//       por token da camada de CSS.
//
// ⚠️ QUEM PASSAR A USAR A SOMBRA ACRESCENTA UMA LINHA ABAIXO, com o plano dono e
// a medida. **Não** basta escrever a classe no `.jsx`: sem a linha aqui, o gate
// REPROVA — e é esse o comportamento certo.
// ═══════════════════════════════════════════════════════════════════════════════
const QUEM_USA_SHADOW_PUB_PAINEL = [
    // Fase 188, plano 07 — o painel de revisão de 1140px, montado POR PORTAL.
    // A sombra `-24px 0 64px rgba(0,0,0,0.6)` é o critério 1 da `188-REGUA.md`, e
    // o deslocamento NEGATIVO no eixo X é o que a derrama para a ESQUERDA, sobre
    // a fila. Medido em 19/10/2026.
    'resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx',
];

/**
 * O PRECEDENTE SEM USO que mantém honesto todo `skip` da camada de CSS: token
 * DECLARADO no config, nenhum `.jsx` escrevendo a classe, e por isso ausente do
 * CSS. Trocar o precedente é o conserto certo quando alguém passa a usá-lo.
 */
const PRECEDENTE_SEM_USO = {
    classe: 'ease-pub-painel',
    camada: 'transitionTimingFunction',
    chave: 'pub-painel',
    valor: 'cubic-bezier(.2,.8,.2,1)',
};

test('TOK-188 (CONFIG) — quem usa `shadow-pub-painel` está DECLARADO, e o precedente sem uso continua sem uso', () => {
    // ⚠️ Este caso vive FORA da camada de CSS de propósito: aquela camada PULA
    // quando o CSS construído é mais antigo que as fontes, e o VETO da lista não
    // pode dormir junto. Aqui só se lê o config e os `.jsx` — roda SEMPRE.
    assert.equal(
        EXT.boxShadow?.['pub-painel'],
        '-24px 0 64px rgba(0,0,0,0.6)',
        'a sombra do painel saiu da camada de tokens — ela é o critério 1 da `188-REGUA.md`',
    );

    // ⚠️ IGUALDADE DE CONJUNTO, não contagem: arquivo que usa e não está na lista
    // cai aqui, e entrada na lista que ninguém usa também.
    assert.deepEqual(
        jsxQueUsamRel('shadow-pub-painel'),
        [...QUEM_USA_SHADOW_PUB_PAINEL].sort(),
        'quem escreve `shadow-pub-painel` divergiu de QUEM_USA_SHADOW_PUB_PAINEL:'
        + ' acrescente o arquivo à lista com o plano dono e a medida, ou pare de usar a sombra',
    );

    // ⚠️ Lista que aponta para caminho fantasma "prova" cobertura medindo NADA —
    // e entrada morta é linha morta (learnings §4b.1).
    assert.deepEqual(
        QUEM_USA_SHADOW_PUB_PAINEL.filter((rel) => !existsSync(resolve(RAIZ, rel))),
        [],
        'caminho fantasma em QUEM_USA_SHADOW_PUB_PAINEL',
    );

    // ⚠️ CONTROLE DA SONDA, nas DUAS direções: ela ACHA uma classe que existe
    // (`bg-pub-raised-0`, 1 `.jsx` medido) e NÃO acha uma `pub-*` inventada. Sem
    // isto, a igualdade de conjunto acima passaria com `jsxQueUsamRel` cega
    // devolvendo sempre `[]` — e a lista teria de estar vazia para casar.
    assert.ok(jsxQueUsamRel('bg-pub-raised-0').length > 0, 'a sonda de uso está CEGA: nem o controle `bg-pub-raised-0` ela acha');
    assert.deepEqual(jsxQueUsamRel('shadow-pub-isca-188a'), [], 'a isca `shadow-pub-isca-188a` virou classe de verdade — troque a isca');

    // ⚠️ ISCA INVERTIDA — o precedente TROCADO pela Fase 188 (ver o bloco acima).
    // Quando alguém animar o painel com esta curva, este caso reprova e manda
    // trocar o precedente. De propósito.
    assert.equal(
        EXT[PRECEDENTE_SEM_USO.camada]?.[PRECEDENTE_SEM_USO.chave],
        PRECEDENTE_SEM_USO.valor,
        'o precedente saiu da camada de tokens — escolha outro token DECLARADO e sem uso',
    );
    assert.deepEqual(
        jsxQueUsamRel(PRECEDENTE_SEM_USO.classe),
        [],
        `alguém passou a usar \`${PRECEDENTE_SEM_USO.classe}\` — troque o precedente desta isca invertida`,
    );
});

// ───────────────────────────────────────────────────────────────────────────────
// 2. A CAMADA DO CSS — por token, com skip NOMEADO no que nenhum `.jsx` usa.
// ───────────────────────────────────────────────────────────────────────────────

/** Escapa TODOS os metacaracteres de regex, parênteses inclusive (v). */
function escapaRegex(texto) {
    return texto.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** O seletor que o Tailwind emite: `.` + o nome com os metacaracteres de CSS escapados (iv). */
function seletorDe(classe) {
    return `.${classe.replace(/[[\].:/%,]/g, (ch) => `\\${ch}`)}`;
}

function carregarCssConstruido() {
    const manifestAbs = resolve(RAIZ, 'public/build/manifest.json');
    if (!existsSync(manifestAbs)) return { motivo: 'public/build/manifest.json ausente — rode `npm run build`' };

    // ⚠️ Manifest SEMPRE pelo JSON, nunca por grep: medido nesta rodada, o hash
    // do `app.jsx` sai COM HÍFEN (`app-Dv6-mbDf.js`) e um grep de nome
    // quebraria nele. E `resources/css/app.css` NÃO é chave do manifest — o CSS
    // entra por `manifest['resources/js/app.jsx'].css`.
    const manifest = JSON.parse(readFileSync(manifestAbs, 'utf8'));
    const entrada = manifest['resources/js/app.jsx'];
    if (!entrada || !Array.isArray(entrada.css) || entrada.css.length === 0) {
        return { motivo: 'o manifest não aponta CSS para resources/js/app.jsx' };
    }

    const cssAbs = resolve(RAIZ, 'public/build', entrada.css[0]);
    if (!existsSync(cssAbs)) return { motivo: `${entrada.css[0]} ausente — rode \`npm run build\`` };

    // ⚠️ O CSS tem de ser mais NOVO que o config, senão o que se mede é o build
    // anterior aos tokens. `skip` NOMEADO, nunca `return` calado — é daí que
    // viriam os DOIS REGIMES de contagem no manifesto, se a campanha de mutação
    // tocar o config.
    if (statSync(cssAbs).mtimeMs < statSync(resolve(RAIZ, REL_CONFIG)).mtimeMs) {
        return { motivo: `o CSS construído (${entrada.css[0]}) é mais antigo que ${REL_CONFIG} — rode \`npm run build\`` };
    }

    return { arquivo: entrada.css[0], css: readFileSync(cssAbs, 'utf8') };
}

const CSS = carregarCssConstruido();

/** Todo `.jsx` sob `resources/js`, recursivo. */
function todosOsJsx(dir) {
    const achados = [];
    for (const entrada of readdirSync(dir, { withFileTypes: true })) {
        const abs = resolve(dir, entrada.name);
        if (entrada.isDirectory()) achados.push(...todosOsJsx(abs));
        else if (entrada.name.endsWith('.jsx')) achados.push(abs);
    }
    return achados;
}

/**
 * Quais `.jsx` escrevem a classe. ⚠️ Com BORDA NOS DOIS LADOS: `bg-pub-float`
 * casaria dentro de `bg-pub-float-line` numa busca ingênua (substring casa com
 * prefixo — e também com o FIM de outro nome, learnings §4 caso 4b).
 */
function jsxQueUsam(classe) {
    const re = new RegExp(`(?<![\\w-])${escapaRegex(classe)}(?![\\w-])`);
    return todosOsJsx(DIR_JSX).filter((abs) => re.test(readFileSync(abs, 'utf8')));
}

/**
 * O mesmo, em caminho RELATIVO à raiz e com `/` — é a forma que as listas
 * constantes deste arquivo declaram, e a única comparável entre máquinas.
 * ⚠️ `relative()` devolve `\` no Windows; sem a troca a igualdade de conjunto
 * falharia por separador e pareceria divergência de lista.
 */
function jsxQueUsamRel(classe) {
    return jsxQueUsam(classe)
        .map((abs) => relative(RAIZ, abs).split('\\').join('/'))
        .sort();
}

test('TOK-187 (CSS) — cada token novo GERA regra com o valor certo, ou pula NOMEADO', async (contexto) => {
    if (CSS.motivo) {
        contexto.skip(CSS.motivo);
        return;
    }

    /** TODOS os corpos de regra da classe (vi: um seletor pode ter duas regras). */
    const regrasDe = (classe) => {
        const re = new RegExp(`${escapaRegex(seletorDe(classe))}(?![\\w\\\\-])[^{}]*\\{([^}]*)\\}`, 'g');
        return [...CSS.css.matchAll(re)].map((m) => m[1]);
    };

    await contexto.test('o CSS lido é o do manifest e tem tamanho de CSS de verdade', () => {
        assert.ok(CSS.css.length > 200000, `CSS suspeito de truncado: ${CSS.css.length} bytes`);
    });

    await contexto.test('⚠️ CONTROLE POSITIVO + ISCA: a sonda acha o que existe e não acha o que não existe', () => {
        // Controle positivo: `bg-pub-raised-0` é usado por 1 `.jsx` e ESTÁ no
        // CSS (medido). Sem ele, as ausências abaixo passariam com a sonda cega.
        const controle = regrasDe('bg-pub-raised-0');
        assert.equal(controle.length, 1, 'a sonda está cega: nem o controle `bg-pub-raised-0` ela acha');
        assert.match(controle[0], /background-color:\s*rgb\(19 19 19 \//);

        // Isca: classe `pub-*` inexistente no projeto inteiro.
        for (const isca of ['bg-pub-isca-187a', 'shadow-pub-isca-187a']) {
            assert.deepEqual(regrasDe(isca), [], `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS.css.includes(isca), `a string da isca ${isca} apareceu no CSS`);
        }

        // ⚠️ ISCA INVERTIDA — o PRECEDENTE que torna honesto todo `skip` deste
        // teste. ⚠️⚠️ **REAPONTADO PELA FASE 188 (ver o bloco de cirurgia acima
        // de `QUEM_USA_SHADOW_PUB_PAINEL`):** era `shadow-pub-painel`, que o
        // plano 188-07 passou a usar legitimamente; agora é `ease-pub-painel` —
        // o MESMO token na camada `transitionTimingFunction`, declarado no
        // config, sem NENHUM `.jsx` e por isso AUSENTE do CSS. Prova que "token
        // no config e ausente do CSS" é um estado REAL do projeto, e não a sonda
        // quebrada. Quando alguém animar o painel com esta curva, este caso
        // reprova e manda trocar o precedente — de propósito.
        assert.equal(EXT[PRECEDENTE_SEM_USO.camada]?.[PRECEDENTE_SEM_USO.chave], PRECEDENTE_SEM_USO.valor);
        assert.equal(jsxQueUsam(PRECEDENTE_SEM_USO.classe).length, 0, `alguém passou a usar \`${PRECEDENTE_SEM_USO.classe}\` — troque o precedente desta isca invertida`);
        assert.deepEqual(regrasDe(PRECEDENTE_SEM_USO.classe), [], 'o precedente apareceu no CSS sem nenhum .jsx usá-lo');

        // ⚠️⚠️ E O LADO DE CÁ, que a Fase 188 ACRESCENTOU: agora que a lista
        // `QUEM_USA_SHADOW_PUB_PAINEL` é NÃO-VAZIA, a sombra TEM de estar no CSS
        // com o valor certo. Antes este token era prova de ausência; hoje é prova
        // de PRESENÇA, e as duas direções da sonda ficam cobertas no mesmo caso.
        // ⚠️ O cssnano come o zero da frente (normalização (ii)): `.6`, não `0.6`.
        assert.ok(QUEM_USA_SHADOW_PUB_PAINEL.length > 0, 'QUEM_USA_SHADOW_PUB_PAINEL esvaziou — então o token voltou a ser candidato a precedente sem uso');
        const doPainel = regrasDe('shadow-pub-painel');
        assert.equal(
            doPainel.length,
            1,
            `\`shadow-pub-painel\` é escrito por ${QUEM_USA_SHADOW_PUB_PAINEL.length} .jsx e NÃO está no CSS — rode \`npm run build\``,
        );
        assert.match(
            doPainel[0],
            /--tw-shadow:\s*-24px 0 64px rgba\(0,0,0,\.6\)/,
            `a sombra do painel compilou com o valor ERRADO: ${doPainel[0]}`,
        );
    });

    for (const token of TOKENS_NOVOS) {
        await contexto.test(`${token.classe} — regra com o valor certo (ou skip nomeado)`, (sub) => {
            const donos = jsxQueUsam(token.classe);
            if (donos.length === 0) {
                // ⚠️ RAZÃO NOMEADA, nunca `return` calado. Este gate ACORDA
                // SOZINHO no plano que escrever a classe.
                sub.skip(
                    `nenhum .jsx usa \`${token.classe}\` ainda — o plano 187-01 só CRIA o token `
                    + `(medido em ${token.medidoEm}); o Tailwind só emite o que o \`content\` varre`,
                );
                return;
            }
            const regras = regrasDe(token.classe);
            assert.ok(regras.length > 0, `\`${token.classe}\` é usado por ${donos.length} .jsx e NÃO está no CSS — rode \`npm run build\``);
            assert.ok(
                regras.some((corpo) => token.propriedade.test(corpo)),
                `\`${token.classe}\` compilou com o valor ERRADO. Corpos: ${JSON.stringify(regras)}`,
            );
        });
    }
});

// ───────────────────────────────────────────────────────────────────────────────
// 3. OS 2 TAMANHOS LIBERADOS — e a prova de que nada SAIU da lista.
//
// O que precisaria estar errado para falhar: `9.5` ou `22` não ter entrado
// (e então o plano da faixa teria de arredondar para `text-[24px]`/`text-xl`,
// que FID-03 proíbe), OU alguém ter TROCADO dois valores antigos pelos dois
// novos — que é por que `contagem += 2` sozinha não basta.
// ───────────────────────────────────────────────────────────────────────────────

/**
 * `TAMANHOS_PERMITIDOS` é `const` de módulo no gate de vocabulário, não export —
 * e importar aquele módulo RODARIA os testes dele. Então lê-se o LITERAL.
 * ⚠️ Sobre a fonte SEM COMENTÁRIO: a prosa daquele arquivo cita os próprios
 * números (`"9.5, 10, 10.5, …"` da varredura) e um gate cru leria o comentário.
 */
function tamanhosPermitidos() {
    const fonte = lerSemComentarios(REL_VOCABULARIO);
    const m = fonte.match(/const\s+TAMANHOS_PERMITIDOS\s*=\s*new Set\(\s*\[([^\]]*)\]/);
    assert.ok(m, 'não achei o literal de TAMANHOS_PERMITIDOS na fonte sem comentários');
    return m[1].split(',').map((t) => t.trim()).filter(Boolean).map(Number);
}

/** A lista EXATA de antes da Fase 187, medida em `75b9f82f` — 21 valores. */
const ANTES_DA_187 = [8.5, 10, 10.5, 11, 11.5, 12, 12.5, 13, 13.5, 14, 14.5, 15, 15.5, 16, 17, 20, 24, 28, 30, 32, 34];

/**
 * ⚠️⚠️ CIRURGIA DO PLANO 188-01 (19/10/2026) — A CONTAGEM FIXA SAIU, E A LISTA
 * CONSTANTE ENTROU. **Nenhum invariante foi afrouxado; um foi acrescentado.**
 *
 * O QUE ESTAVA AQUI: `assert.equal(agora.length, 23)` e
 * `assert.equal(new Set(agora).size, 23)`, com o comentário *"Contagem FIXA: 21
 * antes, 23 depois. Não é `+= 2`"*.
 *
 * POR QUE SAIU — e **não** porque incomodou:
 *   · `TAMANHOS_PERMITIDOS` é uma régua que **toda fase nova legitimamente
 *     AMPLIA MEDINDO** (a 177 pôs 11 e 28, a 179/180 puseram 8.5, 13.5 e 15.5, a
 *     187 pôs 9.5 e 22, a 188 põe 21). Uma contagem fixa transforma **todo
 *     trabalho correto das Fases 188-194 em vermelho**, e o conserto barato —
 *     subir 23 para 24 — é **exatamente** o que este projeto já identificou
 *     como o dano: *"elevar régua repetidamente TREINA o executor seguinte a
 *     ajustar réguas quando elas incomodam, e um dia ele ajusta uma que
 *     importava"* (está escrito no `verificar-testes.sh`, na justificativa da
 *     forma `>=`, e em `feedback_gate_que_reprova_pelo_proprio_conserto`).
 *   · É a família **"contagem que depende de quantos valores/arquivos/commits
 *     existem"**, com NOVE ocorrências anteriores nesta milestone, **todas
 *     consertadas na RAIZ** com lista CONSTANTE (learnings §4b.2). Este é o
 *     décimo caso, e o conserto é o mesmo.
 *   · ⚠️ E o próprio autor do caso já sabia que a contagem **não era a
 *     asserção que carregava o peso**: o comentário do `removidos` abaixo diz,
 *     desde 15/10/2026, *"É esta asserção, e não a contagem, que impede trocar
 *     dois valores por outros dois"*.
 *
 * O QUE ENTROU NO LUGAR, e por que é **mais forte** que a contagem:
 *   a contagem fixa provava *"nada entrou além dos dois"* sem dizer **o que**
 *   poderia entrar. A lista constante prova a mesma coisa **e NOMEIA cada
 *   acréscimo com o plano dono e a medição** — então valor liberado sem medição
 *   continua reprovando, que é o único motivo de este gate existir.
 *
 * ⚠️ QUEM AMPLIAR A RÉGUA ACRESCENTA UMA LINHA AQUI, com o plano e a medida.
 * **Não** basta pôr o número em `TAMANHOS_PERMITIDOS`: sem a linha aqui, este
 * gate REPROVA — e é esse o comportamento certo.
 * ⚠️ A contagem de `test()` deste arquivo **não mudou** (são as mesmas três
 * asserções reorganizadas dentro dos mesmos casos), então `tokens-da-fila`
 * (`contagens-187-01.txt`, `tests=16,9`) continua valendo sem retoque.
 */
const LIBERADOS_DEPOIS_DA_187 = [
    // Fase 188, plano 01 — os 3 KPIs da coluna de preço do painel E2 de
    // `design_handoff_publicador/Aprovacao.dc.html` (`:642`-`:644`), medidos por
    // script em 19/10/2026. Era o ÚNICO ausente da lista.
    21,
];

test('TAM-187 — 9.5 e 22 entraram, e é AMPLIAÇÃO: nada saiu da lista', () => {
    const agora = tamanhosPermitidos();

    // O "antes" é CONGELADO: se ele mudar de tamanho, alguém reescreveu a
    // história em vez de ampliar a régua.
    assert.equal(ANTES_DA_187.length, 21, 'a lista de antes mudou de tamanho — remeça o "antes"');
    assert.equal(new Set(agora).size, agora.length, `há valor duplicado em TAMANHOS_PERMITIDOS: ${agora.join(', ')}`);

    // Os dois novos entraram...
    const faltando = [9.5, 22].filter((v) => !agora.includes(v));
    assert.deepEqual(faltando, [], `faltam em TAMANHOS_PERMITIDOS: ${faltando.join(', ')} — sem eles o plano da faixa teria de arredondar (FID-03)`);

    // ...e o conjunto ANTIGO continua inteiro DENTRO do novo. ⚠️ É esta
    // asserção, e não a contagem, que impede trocar dois valores por outros dois.
    const removidos = ANTES_DA_187.filter((v) => !agora.includes(v));
    assert.deepEqual(removidos, [], `valores REMOVIDOS de TAMANHOS_PERMITIDOS: ${removidos.join(', ')} — esta fase AMPLIA, nunca endurece`);

    // E o conjunto é EXATAMENTE o antigo + os dois da 187 + a lista CONSTANTE do
    // que foi liberado depois, cada um com o plano dono escrito ao lado.
    // ⚠️ Valor que entrar em `TAMANHOS_PERMITIDOS` sem entrar em
    // `LIBERADOS_DEPOIS_DA_187` cai aqui — é a porta dos fundos que a contagem
    // fixa fechava, e ela continua fechada.
    const esperado = [...new Set([...ANTES_DA_187, 9.5, 22, ...LIBERADOS_DEPOIS_DA_187])].sort((a, b) => a - b);
    assert.deepEqual([...agora].sort((a, b) => a - b), esperado,
        `TAMANHOS_PERMITIDOS divergiu do esperado. Liberou tamanho novo? Acrescente-o a LIBERADOS_DEPOIS_DA_187 com o plano e a medida. nao-medidos=[${agora.filter((v) => !esperado.includes(v)).join(', ')}]`);
});

test('⚠️ ISCA INVERTIDA de TAM-187 — a lista é NÃO-VAZIA e tem os marcos das fases anteriores', () => {
    // Se a leitura do literal vier vazia (regex quebrada, arquivo renomeado), o
    // teste acima diria "nada foi removido" sobre uma lista de zero valores.
    // Vazio aqui não é "tudo certo", é a leitura cega (learnings §4b.1).
    const agora = tamanhosPermitidos();
    assert.ok(agora.length >= 23, `leitura suspeita de cega: ${agora.length} valores`);
    assert.ok(agora.every((v) => Number.isFinite(v) && v > 0), `valor não-numérico na lista: ${agora.join(', ')}`);
    // Os três marcos que as fases 179 e 180 liberaram MEDINDO, cada um com a
    // medida escrita no próprio gate de vocabulário.
    for (const marco of [8.5, 13.5, 15.5]) {
        assert.ok(agora.includes(marco), `${marco} saiu da lista — era liberação MEDIDA das Fases 179/180`);
    }
});

test('TAM-187 — a medida dos dois valores está ESCRITA ao lado, no arquivo do gate', () => {
    // ⚠️ Liberar tamanho é afrouxar um gate. O que impede isso de virar porta
    // dos fundos é a medida escrita ao lado — e este caso prova que ela está
    // lá, em vez de confiar em quem revisa. Aqui o alvo é o COMENTÁRIO, então
    // a leitura é do arquivo CRU, de propósito.
    const cru = readFileSync(resolve(RAIZ, REL_VOCABULARIO), 'utf8');
    assert.match(cru, /Aprovacao\.dc\.html/, 'o registro não cita o protótipo de autoridade');
    assert.match(cru, /design_handoff_publicador/, 'o registro não cita o DIRETÓRIO do protótipo (há duas cópias com conteúdo diferente)');
    // A varredura COMPLETA de `font-size:` do E1, para o próximo leitor saber
    // que a lista é MEDIDA e não acumulada.
    assert.match(cru, /9\.5,\s*10,\s*10\.5,\s*11,\s*11\.5,\s*12,\s*12\.5,\s*13,\s*13\.5,\s*14,\s*15,\s*22,\s*28/, 'a varredura completa de font-size do E1 não está escrita ao lado');
});

// ───────────────────────────────────────────────────────────────────────────────
// 4. `duration-[Nms]` CONTINUA NÃO COMPILANDO — e nenhum arquivo deste plano o usa.
//
// O que precisaria estar errado para falhar: alguém "consertar" a animação da
// fila com `duration-[160ms]` e ela não compilar — render verde, tela sem
// transição. É a armadilha §2b do learnings, e a saída é `style={{…}}`.
// ───────────────────────────────────────────────────────────────────────────────

test('ANIM-187 — `duration-[Nms]` não compila; `duration-200` é o controle', (contexto) => {
    if (CSS.motivo) {
        contexto.skip(CSS.motivo);
        return;
    }
    const regrasDe = (classe) => {
        const re = new RegExp(`${escapaRegex(seletorDe(classe))}(?![\\w\\\\-])[^{}]*\\{([^}]*)\\}`, 'g');
        return [...CSS.css.matchAll(re)].map((m) => m[1]);
    };

    // O CONTROLE primeiro: se `duration-200` também sair ausente, a asserção de
    // ausência abaixo não prova nada.
    const controle = regrasDe('duration-200');
    assert.ok(controle.length > 0, 'nem `duration-200` está no CSS — a sonda está cega');
    // (vi) MEDIDO: `duration-200` emite DUAS regras — transition e animation.
    assert.ok(
        controle.some((c) => /transition-duration:\s*\.2s/.test(c)),
        `o controle compilou diferente do medido: ${JSON.stringify(controle)}`,
    );

    for (const arbitraria of ['duration-[160ms]', 'duration-[200ms]', 'delay-[80ms]']) {
        assert.deepEqual(regrasDe(arbitraria), [], `\`${arbitraria}\` passou a compilar — reveja §2b do learnings antes de usar`);
    }
});

test('ANIM-187 — nenhum arquivo deste plano escreve `duration-[` ou `delay-[`', () => {
    // ⚠️ Recortado: varrer `resources/js` inteiro acusaria trabalho de outras
    // fases. E o escopo é o arquivo deste plano que PODE emitir classe —
    // `tailwind.config.js`. ⚠️ Este arquivo de teste fica FORA de propósito:
    // ele CONTÉM `duration-[160ms]` como string de isca, e incluí-lo aqui
    // faria o gate reprovar pela própria prova (foi medido: reprovou na 1ª
    // execução). O que garante que a sonda não está cega é a isca abaixo, não
    // a presença deste arquivo na lista.
    const meus = [REL_CONFIG];
    const infratores = [];
    for (const rel of meus) {
        const abs = resolve(RAIZ, rel);
        assert.ok(existsSync(abs), `caminho fantasma: ${rel}`);
        const fonte = lerSemComentarios(rel);
        if (/\b(duration|delay|ease)-\[/.test(fonte)) infratores.push(rel);
    }
    assert.deepEqual(infratores, [], `arquivo deste plano usa classe arbitrária de tempo: ${infratores.join(', ')}`);

    // ⚠️ ISCA: a sonda PEGA a forma que ela diz pegar. Sem isto a negativa
    // acima é satisfeita por uma regex quebrada.
    assert.ok(/\b(duration|delay|ease)-\[/.test('class="duration-[160ms]"'), 'a sonda não pega `duration-[`');
    assert.ok(/\b(duration|delay|ease)-\[/.test('delay-[80ms]'), 'a sonda não pega `delay-[`');
    assert.ok(/\b(duration|delay|ease)-\[/.test('ease-[cubic-bezier(.2,.8,.2,1)]'), 'a sonda não pega `ease-[`');
    // E o que ela NÃO pode pegar: a forma que COMPILA.
    assert.ok(!/\b(duration|delay|ease)-\[/.test('duration-200 delay-150 ease-out'), 'a sonda pega a forma que compila');
});
