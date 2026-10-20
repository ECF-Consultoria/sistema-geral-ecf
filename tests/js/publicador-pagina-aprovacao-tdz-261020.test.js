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
// Quick 261020-tdz — O GATE QUE FALTAVA: RENDERIZAR O `default export` DA
// PÁGINA DE APROVAÇÃO.
//
// ⚠️⚠️ **O DEFEITO QUE ESTE ARQUIVO EXISTE PARA MATAR, e ele está EM PRODUÇÃO
// desde o deploy `e250f9fb` (20/10/2026):** o corpo de
// `Pages/Mlb/Publicador/Aprovacao.jsx` lê `const` do próprio escopo **antes** de
// elas serem inicializadas. Em JavaScript isso é TDZ (*temporal dead zone*): a
// leitura lança `ReferenceError: Cannot access 'X' before initialization`. E
// como a leitura está em posição **EAGER** (atributo de JSX avaliado na hora,
// dentro de um ternário que corre durante a execução do corpo do componente), o
// resultado é **TELA PRETA no instante em que o painel de revisão abre com
// conteúdo**.
//
// ⚠️⚠️ **POR QUE NENHUM DOS ~30 GATES DESTE MÓDULO PEGOU — e é esta a razão de
// este arquivo existir:** MEDIDO em 20/10/2026, **nenhuma sonda deste projeto
// nunca renderizou o `default export` da página**. Todos os gates montam a
// página por `esbuild` e exercitam **só os named exports** (`SLOTS_DE_NO_*`,
// `ESTADO_LOCAL_DA_PAGINA`, varreduras de FONTE); o componente da página
// **nunca é instanciado**. ➡️ O defeito morava no único lugar que nenhuma sonda
// olhava.
//
// ⚠️ **ALCANÇABILIDADE, medida:** `Aprovacao.jsx` monta `fotosDoPainel` como
// `painelDeRevisao === null ? null : objetoSeguro(painelDeRevisao.fotos)`, e o
// construtor do slot é um TERNÁRIO AVALIADO NA HORA. No ramo `null` a JSX **não
// é avaliada** e nada lança; no ramo com fotos a JSX **É avaliada**, lê o
// binding em TDZ e **LANÇA**. ➡️ O ramo perigoso é alcançado exatamente quando
// `painelDeRevisao !== null` — isto é, **quando o painel abre com payload**.
// Hoje é inalcançável só porque `pub_aprovacao_ofertas` está VAZIA; a Fase 194
// cria a primeira oferta.
//
// ⚠️⚠️ **SÃO CINCO SÍMBOLOS, NÃO QUATRO.** O veto da 189-07
// (`publicador-lightbox-acoes-189.test.js`) nomeia quatro, e o quinto
// (`podeEscreverNoPainel`) é **invisível para aquela sonda** porque a forma da
// leitura é `prop={!nome ? … : …}`, e ela só casa `prop={nome}` e `{...nome}`.
// Ele foi achado por **RENDER**, não por leitura — e foi exatamente a leitura
// que falhou cinco vezes.
//
// ═══ ⚠️⚠️ O QUE ESTE ARQUIVO **NÃO** PROVA ═══════════════════════════════
//
// Não há navegador nesta máquina. *"A página renderiza"* aqui significa SEMPRE
// *"o render de SERVIDOR do React executa o corpo do componente e devolve HTML
// sem lançar"*. **Não é afirmável** que "a tela aparece certa".
//
// ⚠️⚠️ E a armadilha que quase fez deste arquivo um **GATE VAZIO POR
// CONSTRUÇÃO**, medida antes de ele existir: `PainelDeRevisao` devolve
// `createPortal(…)` (`PainelDeRevisao.jsx:1335`), e `renderToStaticMarkup`
// **não desenha portal**. Medido: o HTML da página tem **o MESMO tamanho**
// (`28119` bytes) com `painel: null` e com payload cheio. ➡️ Qualquer asserção
// de CONTEÚDO sobre o HTML da página seria vazia. Por isso existem DUAS
// montagens: a REAL (que prova "não lança", e isso é independente do portal) e
// a ESPIÃO, que aliasa `PainelDeRevisao` para um componente que desenha os nós
// dos slots INLINE — e é **sobre o HTML do espião** que roda o render adverso.
// ⚠️ Há isca para a montagem espião: ela tem de produzir HTML ESTRITAMENTE
// MAIOR com payload do que com `painel: null`. Se empatar, o espião não
// recebeu nada e a camada adversa não mede.
//
// ═══ ⚠️ DE CADA CAMADA, "o que precisaria estar errado para ela falhar?" ═══
//
//  1. RENDER     → a TDZ volta (declaração descer, ou atributo eager novo lido
//                  antes da hora). É a camada AUTORITATIVA: ela não pode ser
//                  enganada por regex, porque é o V8 quem decide.
//  2. ORDEM      → os CINCO nomes, por lista CONSTANTE (nunca contagem), com a
//                  declaração obrigada a vir ANTES dos TRÊS construtores de
//                  slot que as leem eagerly. Pega o caso que o render não
//                  alcançaria se um payload futuro mudasse de forma.
//  3. EAGER      → sonda genérica, MAIS LARGA que a da 189-07 (ela cobre
//                  `prop={!nome`, `prop={nome ===`, `prop={nome?.`), para
//                  acusar um SEXTO que ninguém listou.
//  4. ESPIÃO     → os nós dos slots não desenharem (slot que virou `null` em
//                  silêncio é casca vazia).
//  5. ADVERSO    → `[object Object]`, `NaN`, `undefined` ou `Invalid Date`
//                  vazando para a tela com payload torto. A TELA PRETA voltou
//                  SEIS vezes nesta milestone, a última NO CLIQUE.
//  6. FANTASMA   → caminho citado que não existe. Sonda de AUSÊNCIA sobre
//                  arquivo inexistente passa CALADA.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

const REL_PAGINA = 'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx';
const REL_PAINEL = 'resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx';
const REL_COLUNA_FOTOS = 'resources/js/Components/Mlb/Publicador/aprovacao/ColunaDeFotos.jsx';
const REL_COLUNA_PRECO = 'resources/js/Components/Mlb/Publicador/aprovacao/ColunaDePreco.jsx';
const REL_PENDENCIAS = 'resources/js/Components/Mlb/Publicador/aprovacao/PendenciasDoPainel.jsx';
const REL_LAYOUT = 'resources/js/Layouts/AppLayout.jsx';
const REL_MANIFESTO = 'tests/gates/contagens-261020-01.txt';
const REL_PLANO = '.planning/quick/261020-tdz-quatro-telas-pretas-da-pagina-de-aprovacao/PLAN.md';

// ⚠️⚠️ ANTI-FANTASMA — `existsSync` em CADA caminho citado, ANTES de acreditar
// em qualquer leitura vazia. Este projeto acumulou DEZ caminhos fantasma, TRÊS
// vindos de briefing, e sonda de ausência sobre arquivo inexistente passa
// CALADA.
const CAMINHOS_CITADOS = [
    REL_PAGINA, REL_PAINEL, REL_COLUNA_FOTOS, REL_COLUNA_PRECO,
    REL_PENDENCIAS, REL_LAYOUT, REL_MANIFESTO, REL_PLANO,
];
for (const caminho of CAMINHOS_CITADOS) {
    if (!fs.existsSync(path.resolve(RAIZ, caminho))) {
        throw new Error(`caminho inexistente: ${caminho} — o gate mediria NADA`);
    }
}

/** ⚠️ A ISCA do anti-fantasma: tem de continuar AUSENTE. */
const ISCA_AUSENTE = 'resources/js/Pages/Mlb/Publicador/NaoExiste261020.jsx';

// ─────────────────────────────────────────────────────────────────────────────
// Os stubs. ⚠️ `@inertiajs/react` não roda fora do navegador, e `route()` é
// GLOBAL (Ziggy) — sem ele a página lança `route is not defined`, que NÃO é o
// defeito que este arquivo mede e produziria vermelho pelo motivo errado.
// ⚠️ `AppLayout` é o REAL de propósito: medido que ele renderiza em servidor, e
// a composição real é o que esta sonda existe para exercer.
// ─────────────────────────────────────────────────────────────────────────────
const STUB_INERTIA = path.join(__dirname, `.pub-tdz261020-inertia-${process.pid}.mjs`);
fs.writeFileSync(
    STUB_INERTIA,
    [
        "import React from 'react';",
        'export function Link({ href, children, className, ...props }) {',
        "    return React.createElement('a', { href, className, ...props }, children);",
        '}',
        'export const router = {',
        '    get: () => {}, post: () => {}, put: () => {}, patch: () => {},',
        '    delete: () => {}, reload: () => {}, visit: () => {},',
        '};',
        'export function usePage() {',
        '    return { props: { auth: { user: { id: 7, name: "Dev", role: "admin" } }, flash: {}, csrf_token: "t", asset_url: "", sugadores_pendentes: 0 } };',
        '}',
        'export function Head() { return null; }',
        'export function useForm() { return { data: {}, setData: () => {}, post: () => {}, processing: false, errors: {} }; }',
    ].join('\n'),
    'utf8',
);

// ⚠️⚠️ O ESPIÃO. Ele **re-exporta** todos os named exports do painel REAL (a
// página importa `escreverNoPainel`, `propsDaLateralDoLightbox` e mais seis
// dali, e um stub que os omitisse quebraria o bundle por motivo ERRADO) e troca
// SÓ o `default`, por um componente que desenha os nós dos slots INLINE, sem
// portal. Ele publica as props em `globalThis` porque o espião é compilado
// DENTRO do bundle da página — não há fronteira de módulo por onde ler.
const STUB_ESPIAO = path.join(__dirname, `.pub-tdz261020-espiao-${process.pid}.mjs`);
fs.writeFileSync(
    STUB_ESPIAO,
    [
        "import React from 'react';",
        // ⚠️ Caminho ABSOLUTO em barra normal, nunca `file://`: medido que o
        // esbuild NÃO resolve URL `file:` num `export * from` (`ERROR: Could not
        // resolve "file:///C:/…"`). ⚠️ E o alias do painel NÃO intercepta este
        // caminho, porque a chave do alias é a string `@/Components/…` — então
        // não há recursão.
        `export * from '${path.resolve(RAIZ, REL_PAINEL).split(path.sep).join('/')}';`,
        'export default function EspiaoDoPainel(props) {',
        '    globalThis.__ESPIAO_261020__ = props;',
        "    const slots = ['colunaDeFotos', 'colunaDePreco', 'aviso', 'pendencias', 'rodape', 'modais'];",
        '    return React.createElement(',
        "        'div',",
        "        { 'data-espiao': 'painel' },",
        '        slots.map((nome) => React.createElement(',
        "            'div',",
        // ⚠️ Nome do atributo em MINÚSCULA: o React avisa (e o atributo fica
        // ambíguo) com `data-slot-colunaDeFotos`. A asserção usa a MESMA
        // transformação, nunca uma string escrita à mão dos dois lados.
        '            { key: nome, [`data-slot-${nome.toLowerCase()}`]: props[nome] === null || props[nome] === undefined ? "vazio" : "cheio" },',
        '            props[nome] ?? null,',
        '        )),',
        '    );',
        '}',
    ].join('\n'),
    'utf8',
);

after(() => {
    fs.rmSync(STUB_INERTIA, { force: true });
    fs.rmSync(STUB_ESPIAO, { force: true });
});

const EXTERNOS = [
    'react', 'react-dom', 'react-dom/server', 'react-dom/client', 'react/jsx-runtime',
    'lucide-react', 'axios', 'recharts', 'date-fns', 'date-fns/*',
    'clsx', 'tailwind-merge', 'class-variance-authority',
    '@radix-ui/*', '@headlessui/*',
];

/** Compila a página de verdade (JSX + imports reais) e devolve os exports. */
async function montar({ espiao }) {
    const alias = {
        '@': path.resolve(RAIZ, 'resources/js'),
        '@inertiajs/react': STUB_INERTIA,
    };
    if (espiao) {
        alias['@/Components/Mlb/Publicador/aprovacao/PainelDeRevisao'] = STUB_ESPIAO;
    }

    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, REL_PAGINA)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias,
        external: EXTERNOS,
    });

    const outfile = path.join(
        __dirname,
        `.pub-tdz261020-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

// ⚠️ `route()` é GLOBAL (Ziggy). Ele devolve string de propósito: a página a
// passa para `href`, e `undefined` ali geraria `undefined` no HTML — o mesmo
// padrão que a camada adversa caça, pelo motivo errado.
globalThis.route = (nome, parametros) => {
    const cauda = parametros !== null && typeof parametros === 'object'
        ? Object.values(parametros).join('/')
        : '';
    return `/rota-de-teste/${nome}/${cauda}`;
};

// ⚠️⚠️ TODOS os bundles ANTES do primeiro `test()` — o `after()` do `node --test`
// já matou um arquivo inteiro neste projeto **sem nenhum teste falhar**. E
// `await` dentro de callback síncrono é `SyntaxError`: o arquivo nem CARREGA.
const MOD_REAL = await montar({ espiao: false });
const MOD_ESPIAO = await montar({ espiao: true });

const PaginaReal = MOD_REAL.default;
const PaginaEspiao = MOD_ESPIAO.default;

const FONTE_PAGINA = lerSemComentarios(REL_PAGINA);
const CRU_PAGINA = fs.readFileSync(path.resolve(RAIZ, REL_PAGINA), 'utf8');
const TEXTO_MANIFESTO = fs.readFileSync(path.resolve(RAIZ, REL_MANIFESTO), 'utf8');

const EMPRESA = { id: 42, name: 'Loja de Teste' };

/** O payload MÍNIMO que faz `painelDeRevisao !== null`. */
const base = (extra) => ({ oferta_id: 9, versao: 3, ...extra });

const FOTOS = {
    imagens: [
        { id: 11, imagem_id: 101, url_arquivo: '/bytes/11', rotulo: 'Principal', tipo_rotulo: 'capa' },
        { id: 12, imagem_id: 102, url_arquivo: '/bytes/12', rotulo: 'Medidas', tipo_rotulo: 'apoio' },
    ],
};
const PRECO = { atual: {}, simulacao: null };
const PENDENCIAS = [{ chave: 'alteracao_nao_conferida', texto: 'Confira a alteração' }];
const RODAPE = { botoes: { salvar: true } };

/**
 * Os payloads que **ALCANÇAM** cada ramo perigoso, um por vez.
 *
 * ⚠️⚠️ **O MÉTODO, DECLARADO:** isolamento por payload. `fotos` sozinho alcança
 * só `colunaDeFotos`; `preco` sozinho só `colunaDePreco`; `pendencias` sozinho
 * só `noPendenciasDoPainel`. ⚠️ DOIS dos cinco símbolos (`registrarConflito` e
 * `podeEscreverNoPainel`) **nunca são os primeiros** a lançar em payload nenhum
 * — o primeiro que lança no mesmo bloco os esconde. Eles são provados por
 * CASCATA (variante com um bloco já movido, re-render, o próximo lança), e a
 * cascata está registrada na SUMMARY desta quick. ➡️ É por isso que a camada 2
 * deste arquivo cobra os CINCO por ORDEM DE DECLARAÇÃO: ela não depende de qual
 * deles lança primeiro.
 */
const PAYLOADS_QUE_ALCANCAM = [
    ['só fotos', base({ fotos: FOTOS })],
    ['só preço', base({ preco: PRECO })],
    ['só pendências', base({ pendencias: PENDENCIAS })],
    ['só rodapé', base({ rodape: RODAPE })],
    ['tudo junto', base({
        fotos: FOTOS,
        preco: PRECO,
        pendencias: PENDENCIAS,
        rodape: RODAPE,
        identificacao: { titulo: 'Produto X', chips: { andamento: { chave: 'em_revisao' } } },
        modais: {},
        navegacao: { proxima_id: 10 },
    })],
];

const renderPagina = (Componente, painel, extra) => renderToStaticMarkup(
    React.createElement(Componente, { empresa: EMPRESA, painel, ...extra }),
);

/** Renderiza e devolve `{ html, erro }` — nunca deixa a exceção escapar. */
function tentarRender(Componente, painel, extra) {
    try {
        return { html: renderPagina(Componente, painel, extra), erro: null };
    } catch (e) {
        return { html: null, erro: e };
    }
}

/** Conta OCORRÊNCIAS (não linhas: `grep -c` já deu 12 para uma só neste projeto). */
function ocorrencias(texto, agulha) {
    let n = 0;
    let i = texto.indexOf(agulha);
    while (i !== -1) {
        n += 1;
        i = texto.indexOf(agulha, i + agulha.length);
    }
    return n;
}

/** A linha (1-based) da declaração de topo do corpo do componente. */
function linhaDaDeclaracao(linhas, nome) {
    const i = linhas.findIndex((l) => l.startsWith(`    const ${nome} =`));
    return i < 0 ? null : i + 1;
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. ⚠️⚠️ A CAMADA AUTORITATIVA: O `default export` DA PÁGINA RENDERIZA
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ TDZ: o `default export` da página renderiza com painel COM CONTEÚDO 261020_tdz', () => {
    assert.equal(typeof PaginaReal, 'function', 'o `default export` da página não é um componente — o bundle não carregou');

    // ⚠️ A PROVA DE ALCANÇABILIDADE, antes de qualquer veredicto: `painel: null`
    // renderiza hoje, e é por isso que o defeito está invisível em produção. Se
    // ESTA linha falhar, o vermelho das de baixo não é da TDZ.
    const comNull = tentarRender(PaginaReal, null);
    assert.equal(
        comNull.erro,
        null,
        'a página não renderiza nem com `painel: null` — o vermelho abaixo NÃO seria da TDZ',
    );

    const mortes = [];
    for (const [nome, painel] of PAYLOADS_QUE_ALCANCAM) {
        const { erro } = tentarRender(PaginaReal, painel);
        if (erro !== null) {
            // ⚠️ NUNCA `String(erro)` nem `JSON.stringify`: `{toString: null}`
            // LANÇA, e aí a asserção morre pelo motivo errado. `erro.message` de
            // um `ReferenceError` do V8 é sempre string.
            const msg = erro instanceof Error && typeof erro.message === 'string' ? erro.message : '(sem mensagem)';
            const tipo = erro instanceof ReferenceError ? 'ReferenceError' : 'outro';
            mortes.push(`${nome}: ${tipo}: ${msg}`);
        }
    }

    assert.deepEqual(
        mortes,
        [],
        '⚠️⚠️ a página LANÇOU ao renderizar com payload — isso é TELA PRETA no instante em que o painel de revisão abre com conteúdo',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. ⚠️⚠️ A ORDEM DE DECLARAÇÃO DOS CINCO — LISTA CONSTANTE, NUNCA CONTAGEM
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ TDZ: os CINCO símbolos são declarados ANTES dos TRÊS construtores de slot que os leem eagerly 261020_tdz', () => {
    // ⚠️⚠️ LISTA CONSTANTE e NOMEADA, nunca `length === 5`: asserção de contagem
    // treina o executor seguinte a subir o número em vez de consertar o código —
    // a família com QUATORZE ocorrências nesta milestone.
    const LIDOS_EAGER = [
        'podeEscreverNoPainel',
        'abrirModalDoPainel',
        'abrirModalDeReprovar',
        'registrarConflito',
        'precoComSimulacao',
    ];

    // Os TRÊS construtores de slot que fazem a leitura EAGER, medidos.
    const LEITORES = ['colunaDeFotos', 'colunaDePreco', 'noPendenciasDoPainel'];

    const linhas = CRU_PAGINA.split(/\r?\n/);

    const faltando = [...LIDOS_EAGER, ...LEITORES].filter((n) => linhaDaDeclaracao(linhas, n) === null);
    assert.deepEqual(faltando, [], 'símbolo que não existe mais no corpo do componente — a sonda mediria NADA');

    const primeiroLeitor = Math.min(...LEITORES.map((n) => linhaDaDeclaracao(linhas, n)));

    const atrasados = LIDOS_EAGER
        .filter((n) => linhaDaDeclaracao(linhas, n) > primeiroLeitor)
        .map((n) => `${n} (declarada em :${linhaDaDeclaracao(linhas, n)}, lida a partir de :${primeiroLeitor})`);

    assert.deepEqual(
        atrasados,
        [],
        '⚠️⚠️ declaração DEPOIS do primeiro construtor de slot que a lê eagerly — isso é TDZ, ou seja TELA PRETA',
    );

    // ⚠️⚠️ ISCA: a sonda tem de ACUSAR a ordem ERRADA. Sem isto, "zero atrasados"
    // poderia significar só que `linhaDaDeclaracao` devolve `null` para tudo.
    const falsas = ['    const colunaDeFotos = x', '    const abrirModalDeReprovar = y'];
    assert.equal(linhaDaDeclaracao(falsas, 'colunaDeFotos'), 1, 'a sonda de linha está CEGA');
    assert.ok(
        linhaDaDeclaracao(falsas, 'abrirModalDeReprovar') > linhaDaDeclaracao(falsas, 'colunaDeFotos'),
        'a isca da ordem invertida não é acusada — a sonda é mais fraca que o invariante',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. ⚠️ A SONDA GENÉRICA, MAIS LARGA QUE A DA 189-07 — CONTRA UM SEXTO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ TDZ: nenhuma leitura EAGER de atributo de JSX antes da declaração (sonda LARGA) 261020_tdz', () => {
    const linhas = FONTE_PAGINA.split('\n');

    const declaradoEm = new Map();
    linhas.forEach((l, i) => {
        const m = l.match(/^ {4}const ([A-Za-z_$][\w$]*) =/);
        if (m !== null && !declaradoEm.has(m[1])) declaradoEm.set(m[1], i);
    });
    assert.ok(declaradoEm.size > 50, `achei só ${declaradoEm.size} declarações no corpo do componente — a sonda está quebrada`);

    // ⚠️⚠️ **POR QUE ESTA SONDA É MAIS LARGA QUE A DA 189-07, e por que isso
    // importa:** aquela casa só `prop={nome}` e `{...nome}`. Medido: ela NÃO VÊ
    // `onConferirAlteracao={!podeEscreverNoPainel ? …}` nem
    // `onInformarFrete={abrirModalDoPainel === null ? …}` — e as DUAS são
    // leituras EAGER de verdade, uma delas o QUINTO símbolo que ninguém tinha
    // listado. ➡️ Esta cobre `!nome`, `...nome` e `nome` seguido de operador.
    const eager = (nome) => new RegExp(
        `[A-Za-z_$][\\w$]*=\\{\\s*!?\\s*(?:\\.\\.\\.)?${nome}\\b|\\{\\.\\.\\.${nome}\\}`,
    );

    const antesDaHora = [];
    for (const [nome, linhaDecl] of declaradoEm) {
        const re = eager(nome);
        if (linhas.slice(0, linhaDecl).some((l) => re.test(l))) antesDaHora.push(nome);
    }

    assert.deepEqual(
        antesDaHora,
        [],
        '⚠️⚠️ leitura EAGER de atributo de JSX antes da declaração — isso é TDZ (`ReferenceError`), ou seja TELA PRETA quando o painel tem payload',
    );

    // ⚠️⚠️ ISCA NAS DUAS DIREÇÕES. A da 189-07 provava CEGUEIRA pela existência
    // dos quatro no código; com eles consertados essa prova desaparece, então a
    // cegueira é provada aqui por CEBO SINTÉTICO — que não some com o conserto.
    const re = eager('alvo');
    const DEVE_CASAR = [
        '                onReprovar={alvo}',
        '                {...alvo}',
        '                preco={alvo}',
        '                onConferir={!alvo ? null : 1}',
        '                onFrete={alvo === null ? null : 2}',
        '                chave={alvo?.x}',
        '                lista={...alvo}',
    ];
    const cegas = DEVE_CASAR.filter((l) => !re.test(l));
    assert.deepEqual(cegas, [], 'a sonda LARGA está CEGA para uma forma EAGER — "nenhum achado" não significaria nada');

    const NAO_DEVE_CASAR = [
        '                onReprovar={() => alvo(1)}',
        '                onReprovar={(x) => alvo(x)}',
        '                onReprovar={async () => alvo()}',
        '                onOutro={alvoDoLado}',
    ];
    const largas = NAO_DEVE_CASAR.filter((l) => re.test(l));
    assert.deepEqual(largas, [], 'a sonda LARGA acusa leitura DIFERIDA (ou nome vizinho) — ela é mais larga que o invariante');
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. ⚠️⚠️ O ESPIÃO: OS NÓS DOS SLOTS DESENHAM DE VERDADE
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ os nós dos slots que a página monta DESENHAM (o painel real é portal e não sairia no HTML) 261020_tdz', () => {
    assert.equal(typeof PaginaEspiao, 'function', 'o `default export` da montagem-espião não carregou');

    // ⚠️ A PROVA DO PORTAL, medida e não presumida: na montagem REAL o HTML tem
    // o MESMO tamanho com e sem payload. É isto que tornaria vazia qualquer
    // asserção de conteúdo sobre o HTML da página.
    const realVazio = tentarRender(PaginaReal, null);
    const realCheio = tentarRender(PaginaReal, PAYLOADS_QUE_ALCANCAM[4][1]);
    assert.equal(realVazio.erro, null, 'a montagem REAL não renderiza com `painel: null`');
    assert.equal(realCheio.erro, null, 'a montagem REAL não renderiza com payload cheio');
    assert.equal(
        realCheio.html.length,
        realVazio.html.length,
        'o painel REAL deixou de ser portal — então a montagem-espião virou desnecessária e esta camada mede outra coisa',
    );

    // ⚠️⚠️ E a ISCA que prova que o ESPIÃO não é vazio: com payload ele tem de
    // produzir HTML ESTRITAMENTE MAIOR. Empate = o espião não recebeu nada.
    delete globalThis.__ESPIAO_261020__;
    const espiaoVazio = tentarRender(PaginaEspiao, null);
    assert.equal(espiaoVazio.erro, null, 'a montagem-espião não renderiza com `painel: null`');

    const espiaoCheio = tentarRender(PaginaEspiao, PAYLOADS_QUE_ALCANCAM[4][1]);
    assert.equal(espiaoCheio.erro, null, 'a montagem-espião LANÇOU com payload cheio');
    assert.ok(
        espiaoCheio.html.length > espiaoVazio.html.length,
        `o espião produziu HTML do MESMO tamanho (${espiaoVazio.html.length}) com e sem payload — ele não recebeu os nós dos slots, e a camada adversa mediria NADA`,
    );

    // Os slots que a página MONTA com este payload, por nome, não por contagem.
    const capturado = globalThis.__ESPIAO_261020__;
    assert.ok(capturado !== null && typeof capturado === 'object', 'o espião não registrou props nenhuma');

    const cheios = ['colunaDeFotos', 'colunaDePreco', 'pendencias', 'rodape']
        .filter((nome) => ocorrencias(espiaoCheio.html, `data-slot-${nome.toLowerCase()}="cheio"`) === 1);
    assert.deepEqual(
        cheios.sort(),
        ['colunaDeFotos', 'colunaDePreco', 'pendencias', 'rodape'].sort(),
        'slot que a página deveria montar saiu VAZIO — casca sem conteúdo é o defeito D4',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. ⚠️⚠️ O RENDER ADVERSO — A TELA PRETA VOLTOU SEIS VEZES NESTA MILESTONE
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ render ADVERSO: cada campo do painel como objeto, nulo, ausente, NaN e array 261020_tdz', () => {
    // ⚠️ `{toString: null}` LANÇA em `String(valor)`; `obj[chave]` com ele LANÇA
    // na coação; `textoSeguro(NaN)` devolve `'NaN'`; e prop de NÓ chegando
    // objeto comum MATA A PÁGINA. ⚠️ `0` passa pela guarda de inteiro.
    const ADVERSOS = [
        ['objeto comum', {}],
        ['objeto hostil', { toString: null }],
        ['nulo', null],
        ['ausente', undefined],
        ['NaN', NaN],
        ['array', []],
        ['zero', 0],
        ['string vazia', ''],
    ];
    const CAMPOS = [
        'oferta_id', 'versao', 'fotos', 'preco', 'rodape', 'pendencias',
        'identificacao', 'modais', 'navegacao', 'pendencias_resumo',
    ];

    const mortes = [];
    const vazamentos = [];

    const conferir = (rotulo, painel) => {
        const { html, erro } = tentarRender(PaginaEspiao, painel);
        if (erro !== null) {
            const msg = erro instanceof Error && typeof erro.message === 'string' ? erro.message : '(sem mensagem)';
            mortes.push(`${rotulo}: ${msg}`);
            return;
        }
        // ⚠️ NUNCA `String(html)` na mensagem: o gate da 187-04 proíbe `String(`
        // na página, e ele está CERTO. Aqui o `html` já é string do React.
        const m = html.match(/\[object Object\]|NaN|undefined|Invalid Date/);
        if (m !== null) vazamentos.push(`${rotulo}: vazou ${m[0]}`);
    };

    // O painel INTEIRO torto.
    for (const [nome, valor] of ADVERSOS) {
        conferir(`painel inteiro = ${nome}`, valor);
    }

    // Um campo por vez, sobre o payload que ALCANÇA todos os ramos.
    const cheio = PAYLOADS_QUE_ALCANCAM[4][1];
    for (const campo of CAMPOS) {
        for (const [nome, valor] of ADVERSOS) {
            const painel = { ...cheio };
            if (nome === 'ausente') delete painel[campo];
            else painel[campo] = valor;
            conferir(`${campo} = ${nome}`, painel);
        }
    }

    assert.deepEqual(mortes, [], '⚠️⚠️ a página LANÇOU com payload adverso — TELA PRETA');
    assert.deepEqual(vazamentos, [], '⚠️⚠️ vazou `[object Object]`/`NaN`/`undefined`/`Invalid Date` para o HTML');

    // ⚠️⚠️ ISCA: a sonda de vazamento tem de CASAR o próprio cebo. Sem isto,
    // "zero vazamentos" poderia ser a sonda cega (e já foi, neste projeto).
    const CEBO = '<span>[object Object]</span><i>NaN</i><b>undefined</b><em>Invalid Date</em>';
    for (const agulha of ['[object Object]', 'NaN', 'undefined', 'Invalid Date']) {
        assert.ok(
            /\[object Object\]|NaN|undefined|Invalid Date/.test(agulha),
            `a sonda de vazamento está CEGA para ${agulha}`,
        );
    }
    assert.equal(ocorrencias(CEBO, '[object Object]'), 1, 'o contador de ocorrências está quebrado');
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. ⚠️ ANTI-FANTASMA E MANIFESTO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ anti-fantasma: a isca de caminho inexistente continua AUSENTE 261020_tdz', () => {
    assert.equal(
        fs.existsSync(path.resolve(RAIZ, ISCA_AUSENTE)),
        false,
        'a isca do anti-fantasma passou a existir — a prova de que `existsSync` enxerga morreu',
    );
    // E a direção contrária: um caminho que EXISTE tem de ser visto.
    assert.ok(fs.existsSync(path.resolve(RAIZ, REL_PAGINA)), '`existsSync` está cego para a própria página');
});

test('⚠️ manifesto: os ids desta quick estão escriturados e a bijeção id ↔ gate é fechada 261020_tdz', () => {
    // ⚠️ O manifesto é lido por `sh tests/gates/verificar-testes.sh 261020-01 <id>`.
    // ⚠️⚠️ MEDIDO: o 1º argumento é resolvido por
    // `sed -nE 's/^([0-9]+)-([0-9]+)$/\1-\2/p'` (`verificar-testes.sh:122`), que
    // aceita **só DÍGITOS**. `261020-tdz` cairia no ramo `else` e procuraria
    // `contagens-186-261020-tdz.txt` — prefixo de fase FALSO. Por isso o par é
    // NUMÉRICO: `261020-01`.
    const IDS = ['pagina-aprovacao-tdz'];
    const orfaos = IDS.filter((id) => !new RegExp(`^${id}\\s+js\\s`, 'm').test(TEXTO_MANIFESTO));
    assert.deepEqual(orfaos, [], 'id do `<verify>` ausente do manifesto — e manifesto incompleto ABORTA o harness');

    // ⚠️ ISCA: id que NÃO existe não pode "ser encontrado".
    assert.equal(
        new RegExp('^id-que-nao-existe-261020\\s+js\\s', 'm').test(TEXTO_MANIFESTO),
        false,
        'a sonda do manifesto casa qualquer coisa — ela não mede nada',
    );

    // O próprio arquivo deste gate tem de estar na linha do id.
    assert.ok(
        TEXTO_MANIFESTO.includes('tests/js/publicador-pagina-aprovacao-tdz-261020.test.js'),
        'o manifesto não aponta para ESTE arquivo de gate',
    );

    // ⚠️ E a razão de existir um manifesto de QUICK (o primeiro do projeto) tem
    // de estar ESCRITA nele — senão o próximo executor não sabe por que o par é
    // numérico e "conserta" o nome.
    assert.ok(
        TEXTO_MANIFESTO.includes('261020-tdz') && TEXTO_MANIFESTO.includes('verificar-testes.sh'),
        'o manifesto não explica POR QUE o par é numérico — e aí o próximo executor troca o nome',
    );
});
