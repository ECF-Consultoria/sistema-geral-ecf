import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { execFileSync } from 'node:child_process';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 07 — A TABELA DA FILA, NÍVEIS 1 E 2: o grid literal de 11
// colunas, a linha de produto de 52px, a sub-linha de conjunto de 38px e os
// mini-quadrados nos DOIS tamanhos.
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas, sha256 `68624883ce35547e`). A seção E1 é `:26`-`:205`.
// ⚠️ **CITE O DIRETÓRIO:** há DUAS cópias do pacote de handoff com conteúdo
// DIFERENTE (`design_handoff_publicador/` × `new_layout_publicador/`), e a
// segunda é a versão ANTIGA — a ERRADA para esta fase.
//
// ⚠️⚠️ ONZE DOS DEZESSETE PONTEIROS DO PLANO ESTAVAM DESLOCADOS, e os corretos
// abaixo foram MEDIDOS com `awk`/`sed` nesta sessão (16/10/2026):
//
//   elemento                    plano    MEDIDO
//   `<section>`                 :104     **:108**   ⚠️
//   cabeçalho (o grid)          :105     **:109**   ⚠️
//   checkbox do cabeçalho       :106     **:110**   ⚠️
//   Preço / Resultado (right)   :107     **:111**   ⚠️
//   borda entre grupos          :109     **:114**   ⚠️
//   linha de produto (52px)     :110     **:115**   ⚠️
//   tile 34px                   :111     **:116**   ⚠️
//   nome + tag PRODUTO          :112     **:117**   ⚠️
//   SKU                         :113     **:118**   ⚠️
//   "N ofertas"                 :114     **:119**   ⚠️
//   frase do cadastro           :120       :120 ✓
//   sub-linha … "Revisar fotos" :124-:130  iguais ✓
//   grid da LINHA da oferta     :133       :133  ✓
//   `sq()`                      :1116-:1122 **:1115-:1122** (o ternário é `:1117`)
//   o agrupamento               :1266-:1273 **:1256-:1262** ⚠️⚠️
//
// ⚠️⚠️ **TRÊS OMISSÕES MEDIDAS NA TABELA DO PLANO**, e é a classe de erro que mais
// aparece neste projeto (a tabela não erra VALOR: erra por OMISSÃO e por
// REFERÊNCIA):
//   · `white-space:nowrap` no botão "Revisar fotos do conjunto" (`:130`) — e ela
//     é a **PRIMEIRA** propriedade da linha;
//   · o **WRAPPER** do nome (`:117`): um `<span>` `display:flex;align-items:center;
//     gap:8px` que embrulha o nome **e** a tag PRODUTO. Sem ele a tag cairia no
//     `gap:12px` da linha, não no `gap:8px` dela;
//   · `font-extrabold` prescrito para o checkbox do cabeçalho, que o **GATE-03
//     PROÍBE** — a casa escreve `font-[800]` (medido: reprovou na 1ª redação).
//
// ⚠️ E **são 8 rótulos no cabeçalho, não 9** (o plano escreveu 9): medido em
// `:111`, são `<button>` + `<span></span>` + 8 rótulos + `<span></span>` = 11
// células.
//
// ⚠️ REND-03: TODOS os bundles são montados AQUI NO TOPO, antes do primeiro
// `test()`. O `after()` do `node --test` dispara quando os testes registrados
// terminam e já matou um arquivo inteiro deste projeto SEM nenhum teste falhar.
//
// ⚠️ A pergunta obrigatória — *"o que precisaria estar errado no mundo para este
// gate falhar?"* — está respondida POR BLOCO. "Nada" é achado, não conforto.
//
// ⚠️ **ESCOPO DECLARADO:** a PÁGINA não é renderizada aqui, pelo mesmo recorte
// das waves 4, 5 e 6: montar `Pages/Mlb/Publicador/Aprovacao.jsx` exigiria o
// `AppLayout` e o `PublicadorHeader` inteiros (que leem `usePage()`, Radix e o
// `localStorage` das contas recentes), e o que isso mediria é a casca da Fase
// 177. Da página, o que este arquivo mede é a FONTE (o slot preenchido, o par
// nó × dado, zero `localStorage`).
// ⚠️ **Nenhum executor desta fase viu esta tela renderizada:** o banco local não
// tem empresa com `ml_token` (a rota dá 404 aqui). A conferência é do usuário,
// em produção, na conta 459.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

// ⚠️⚠️ O commit de PARTIDA deste plano, REMEDIDO: a outra máquina enviou 8
// commits no meio desta wave e o orquestrador integrou dois merges. Com a
// partida velha (`963e55d0`) o range abraçaria o trabalho das outras máquinas.
// ⚠️ O range SOZINHO não basta: as TRÊS sessões commitam nesta árvore com o
// MESMO `user.name`, então o AUTOR não discrimina nada. O discriminador é o
// ESCOPO DO ASSUNTO, e há isca nas duas direções.
const PARTIDA = '07d87a56';
const ESCOPO = '(187-07)';

const STUB_INERTIA = path.join(__dirname, `.pub-tab187-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, delete: () => {}, reload: () => {} };
export function usePage() { return { props: { auth: { user: { id: 7 } } } }; }
`, 'utf8');

after(() => {
    fs.rmSync(STUB_INERTIA, { force: true });
});

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
        `.pub-tab187-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';
const REL_TABELA = `${DIR_APROV}/TabelaDaFila.jsx`;
const REL_LINHA_PRODUTO = `${DIR_APROV}/LinhaDeProdutoDaFila.jsx`;
const REL_SUBLINHA = `${DIR_APROV}/SubLinhaDeConjunto.jsx`;
const REL_QUADRADOS = `${DIR_APROV}/QuadradosDeFoto.jsx`;
const REL_PAGINA = 'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx';
const REL_GRUPO_SERVICO = 'app/Services/Publicador/Aprovacao/Fila/GrupoDaFilaService.php';
const REL_GALERIA_SERVICO = 'app/Services/Publicador/Aprovacao/Fila/GaleriaDaOfertaService.php';
const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

// ⚠️ `test -e` ANTES de acreditar em qualquer leitura vazia. **Seis** caminhos
// fantasma já "provaram" território intocado nesta milestone, medindo NADA (e a
// Fase 186 achou o sétimo).
for (const caminho of [
    DIR_APROV, REL_TABELA, REL_LINHA_PRODUTO, REL_SUBLINHA, REL_QUADRADOS,
    REL_PAGINA, REL_GRUPO_SERVICO, REL_GALERIA_SERVICO, PROTOTIPO,
]) {
    if (!fs.existsSync(path.resolve(RAIZ, caminho))) {
        throw new Error(`caminho inexistente: ${caminho} — gate mediria NADA`);
    }
}

const MOD_TABELA = await montar(REL_TABELA);
const MOD_LINHA_PRODUTO = await montar(REL_LINHA_PRODUTO);
const MOD_SUBLINHA = await montar(REL_SUBLINHA);
const MOD_QUADRADOS = await montar(REL_QUADRADOS);
const Tabela = MOD_TABELA.default;
const LinhaDeProduto = MOD_LINHA_PRODUTO.default;
const SubLinha = MOD_SUBLINHA.default;
const Quadrados = MOD_QUADRADOS.default;

const FONTE_TABELA = lerSemComentarios(REL_TABELA);
const FONTE_LINHA_PRODUTO = lerSemComentarios(REL_LINHA_PRODUTO);
const FONTE_SUBLINHA = lerSemComentarios(REL_SUBLINHA);
const FONTE_QUADRADOS = lerSemComentarios(REL_QUADRADOS);
const FONTE_PAGINA = lerSemComentarios(REL_PAGINA);
const TODAS_AS_FONTES = [FONTE_TABELA, FONTE_LINHA_PRODUTO, FONTE_SUBLINHA, FONTE_QUADRADOS].join('\n');
const TEXTO_PROTOTIPO = fs.readFileSync(path.resolve(RAIZ, PROTOTIPO), 'utf8');

const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));
const nada = () => {};

// ─── O payload REAL que `GrupoDaFilaService::montar()` devolve ───
//
// ⚠️ A fixture DISCRIMINA de propósito: dois grupos (para a borda entre eles),
// conjuntos com UMA e com DUAS ofertas (para o pill), quadrados nos quatro
// estados, e um grupo SEM a frase do cadastro. Na Fase 186 duas mutações
// sobreviveram **não** por falha da asserção, mas porque a fixture tinha uma
// oferta só: "a conta" e "as ao vivo" davam o mesmo número.

const QUADRADOS = [
    { id: 11, estado: 'approved', de_ia: false, com_alerta: false, rotulo: 'Capa', titulo: 'Capa · aprovada' },
    { id: 12, estado: 'pending', de_ia: true, com_alerta: true, rotulo: 'Foto 2', titulo: 'Foto 2 · com alerta' },
    { id: 13, estado: 'pending', de_ia: true, com_alerta: false, rotulo: 'Foto 3', titulo: 'Foto 3 · a conferir' },
];

const CONJUNTO_COMPARTILHADO = {
    rascunho_id: 501,
    produto_id: 301,
    rotulo: 'Unidade',
    unidades: 1,
    fotos: '3 fotos (2 geradas por IA)',
    quadrados: QUADRADOS,
    progresso: '1/3 aprovadas',
    completo: false,
    compartilhado: true,
    ofertas: [{ id: 9001 }, { id: 9002 }],
};

const CONJUNTO_SOZINHO = {
    rascunho_id: 502,
    produto_id: 302,
    rotulo: 'Combo ×2',
    unidades: 2,
    fotos: '2 fotos (0 geradas por IA)',
    quadrados: [QUADRADOS[0], { ...QUADRADOS[0], id: 14, rotulo: 'Foto 2', titulo: 'Foto 2 · aprovada' }],
    progresso: '2/2 aprovadas',
    completo: true,
    compartilhado: false,
    ofertas: [{ id: 9003 }],
};

const GRUPO_1 = {
    produto_id: 301,
    nome: 'Poltrona Beny',
    sku: 'POL-BENY',
    contagem: '3 ofertas',
    borda_superior: false,
    cadastro: 'Cadastro completo · categoria e ficha técnica organizadas',
    conjuntos: [CONJUNTO_COMPARTILHADO, CONJUNTO_SOZINHO],
};

const GRUPO_2 = {
    produto_id: 401,
    nome: 'Mesa Tora',
    sku: 'MES-TORA',
    contagem: '1 oferta',
    borda_superior: true,
    // ⚠️ `null` de propósito: é o grupo cujo cadastro NÃO está completo, e a frase
    // **não pode aparecer** (D4 / EXMP-01).
    cadastro: null,
    conjuntos: [{ ...CONJUNTO_SOZINHO, rascunho_id: 503, produto_id: 401, ofertas: [{ id: 9004 }] }],
};

const GRUPOS = [GRUPO_1, GRUPO_2];

const HTML_SEM_SLOT = render(Tabela, { grupos: GRUPOS });
const HTML_COM_SLOT = render(Tabela, {
    grupos: GRUPOS,
    linhaDaOferta: (oferta) => React.createElement('div', { 'data-linha': oferta.id, className: 'casca-da-linha' }),
});
const HTML_COM_HANDLERS = render(Tabela, {
    grupos: GRUPOS, onRevisarFotos: nada, onMarcarVisiveis: nada, visiveisMarcadas: true,
});
const HTML_VAZIO = render(Tabela, { grupos: [] });

/**
 * O elemento de índice ABSOLUTO na árvore de tags de abertura do HTML.
 *
 * ⚠️⚠️ **NUNCA `indexOf(tagDeAbertura)`**: ele pega o IRMÃO ERRADO quando dois
 * elementos têm a MESMA string de classes — **quatro gates deste projeto estavam
 * medindo a linha do Mercado Livre em vez do ERP**. Aqui a localização é por
 * POSIÇÃO na árvore, contada do começo.
 */
function tagsDeAbertura(html) {
    return [...html.matchAll(/<(div|span|section|button|img)\b([^>]*)>/g)].map((m) => ({
        tag: m[1],
        atributos: m[2],
        classe: (m[2].match(/class="([^"]*)"/) || [])[1] ?? '',
        titulo: (m[2].match(/title="([^"]*)"/) || [])[1] ?? null,
    }));
}

const ARVORE_SEM_SLOT = tagsDeAbertura(HTML_SEM_SLOT);
const ARVORE_COM_HANDLERS = tagsDeAbertura(HTML_COM_HANDLERS);

/** A lista de classes de um elemento, como CONJUNTO — igualdade, nunca `includes`. */
function classesDe(elemento) {
    return new Set(elemento.classe.split(/\s+/).filter((c) => c !== ''));
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. ⚠️⚠️ O GRID DE 11 COLUNAS — o item mais fácil de errar da fase
//
// O que precisaria estar errado para falhar: uma trilha trocada (o plano manda
// mutar `104px` por `108px`), o grid do cabeçalho divergir do da linha, ou
// alguém "aproximar" uma medida. A comparação é de **STRING INTEIRA**, nunca
// `includes`: `substring` casa com PREFIXO (`z-3` dentro de `z-30`,
// `text-[11px]` dentro de `text-[11.5px]`) e já mordeu este projeto quatro
// vezes, inclusive pelo FIM do nome de outra classe.
// ═══════════════════════════════════════════════════════════════════════════

test('o grid de 11 colunas é o literal do protótipo, byte a byte 187-07', () => {
    // As 11 trilhas, lidas do PROTÓTIPO e não escritas de cabeça.
    const doPrototipo = [...TEXTO_PROTOTIPO.matchAll(/grid-template-columns:([^;"]*)/g)]
        .map((m) => m[1].trim());

    const dasOnze = '32px 24px minmax(170px,1fr) 104px 118px 92px 108px 76px 112px 164px 96px';

    // ⚠️ ISCA INVERTIDA: o protótipo DECLARA esse grid. Se a leitura viesse vazia,
    // tudo abaixo passaria comparando a minha string consigo mesma.
    assert.ok(
        doPrototipo.includes(dasOnze),
        `o protótipo não declara o grid das 11 colunas — leitura trouxe ${JSON.stringify(doPrototipo.slice(0, 4))}`,
    );

    // ⚠️ E ele aparece DUAS vezes: `:109` (cabeçalho) e `:133` (linha da oferta).
    assert.equal(
        doPrototipo.filter((g) => g === dasOnze).length,
        2,
        'o grid das 11 colunas deveria aparecer DUAS vezes no protótipo (cabeçalho e linha)',
    );

    // A classe exportada — IGUALDADE de string, nunca `includes`.
    const esperada = 'grid-cols-[32px_24px_minmax(170px,1fr)_104px_118px_92px_108px_76px_112px_164px_96px]';
    assert.equal(MOD_TABELA.GRID_DAS_11_COLUNAS, esperada, 'a constante do grid divergiu do protótipo');

    // ⚠️ E ela é a trilha do protótipo com `_` no lugar do espaço — conferido por
    // TRANSFORMAÇÃO, não por eu ter digitado as duas iguais.
    assert.equal(
        `grid-cols-[${dasOnze.replace(/ /g, '_')}]`,
        MOD_TABELA.GRID_DAS_11_COLUNAS,
        'a constante não é a tradução literal da trilha do protótipo',
    );

    // ⚠️⚠️ O cabeçalho usa a MESMA string, por IGUALDADE DE CONJUNTO de classes.
    const cabecalho = ARVORE_SEM_SLOT.find((e) => classesDe(e).has('grid') && classesDe(e).has('h-[38px]'));
    assert.ok(cabecalho, 'não achei o cabeçalho de 38px na árvore');
    assert.ok(
        classesDe(cabecalho).has(esperada),
        `o cabeçalho não carrega o grid literal: ${cabecalho.classe}`,
    );

    // ⚠️ ISCA DA FORMA: a comparação por conjunto SABE recusar o quase-igual.
    const quaseIgual = 'grid-cols-[32px_24px_minmax(170px,1fr)_108px_118px_92px_108px_76px_112px_164px_96px]';
    assert.notEqual(quaseIgual, esperada, 'a isca do quase-igual virou a classe de verdade');
    assert.equal(classesDe(cabecalho).has(quaseIgual), false, 'o cabeçalho casou o grid MUTADO');
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. O CABEÇALHO: 38px, os 8 rótulos e as DUAS células à direita
//
// O que precisaria estar errado para falhar: um rótulo trocado, a ordem das
// colunas invertida, o alinhamento à direita na célula errada (ou em todas), ou
// o número de células deixar de ser 11.
// ═══════════════════════════════════════════════════════════════════════════

test('o cabeçalho tem 11 células, 8 rótulos e só Preço/Resultado à direita 187-07', () => {
    // ⚠️ Os rótulos são LIDOS do módulo e conferidos contra o PROTÓTIPO, nunca
    // escritos de cabeça nos dois lados.
    assert.deepEqual(
        MOD_TABELA.ROTULOS_DO_CABECALHO,
        ['Oferta', 'Modalidade', 'Fotos', 'Preço', 'Resultado/venda', 'Margem', 'Responsável', 'Andamento'],
        'os 8 rótulos do cabeçalho divergiram',
    );

    // ⚠️ **SÃO 8, NÃO 9** — o plano escreveu 9. Medido em `:111`.
    assert.equal(MOD_TABELA.ROTULOS_DO_CABECALHO.length, 8, 'o cabeçalho tem OITO rótulos (o plano dizia 9)');

    // A linha `:111` do protótipo, literal, com os 8 rótulos na ordem.
    const linha111 = TEXTO_PROTOTIPO.split(/\r?\n/)[110];
    assert.ok(linha111.includes('<span>Oferta</span>'), `a linha :111 mudou de lugar: ${linha111.slice(0, 80)}`);
    for (const rotulo of MOD_TABELA.ROTULOS_DO_CABECALHO) {
        assert.ok(linha111.includes(`>${rotulo}</span>`), `o protótipo não declara o rótulo "${rotulo}" em :111`);
    }

    // ⚠️ `text-align:right` aparece DUAS vezes em `:111`, e só nessas duas.
    assert.equal(
        (linha111.match(/text-align:right/g) || []).length,
        2,
        'o protótipo alinha à direita exatamente DUAS células em :111',
    );

    const cabecalho = ARVORE_SEM_SLOT.find((e) => classesDe(e).has('h-[38px]'));
    for (const classe of ['bg-pub-sunken', 'border-b', 'border-pub-line-subtle', 'px-[16px]', 'text-[12px]', 'font-medium', 'text-pub-txt-4']) {
        assert.ok(classesDe(cabecalho).has(classe), `o cabeçalho perdeu \`${classe}\``);
    }

    // As células do cabeçalho, por ÁRVORE com índice ABSOLUTO: o cabeçalho é o
    // 2º elemento (depois do `<section>`), e as 11 células vêm na sequência.
    const inicio = ARVORE_SEM_SLOT.indexOf(cabecalho);
    const celulas = ARVORE_SEM_SLOT.slice(inicio + 1, inicio + 12);
    assert.equal(celulas.length, 11, 'o cabeçalho não tem 11 células');

    // Sem `onMarcarVisiveis` a célula 1 é um `<span>` vazio (D4: botão sem
    // destino não é desenhado).
    assert.equal(celulas[0].tag, 'span', 'sem handler, a célula do checkbox não é um botão');

    // As duas à direita são a 6ª e a 7ª célula (Preço e Resultado/venda).
    const aDireita = celulas
        .map((c, i) => (classesDe(c).has('text-right') ? i : -1))
        .filter((i) => i >= 0);
    assert.deepEqual(aDireita, [5, 6], 'o alinhamento à direita não está nas células de Preço e Resultado/venda');
    for (const i of aDireita) {
        assert.ok(classesDe(celulas[i]).has('pr-[14px]'), 'a célula à direita perdeu o `pr-[14px]`');
    }
});

test('o checkbox do cabeçalho só existe COM handler, e nunca com `font-extrabold` 187-07', () => {
    // ⚠️ Sem handler: ausência rodada com as props PREENCHIDAS (`grupos` tem dois
    // grupos), nunca com o componente vazio — asserção de ausência sobre render
    // vazio não prova nada.
    assert.ok(!HTML_SEM_SLOT.includes(MOD_TABELA.TITULO_SELECIONAR), 'o checkbox apareceu SEM handler');
    assert.ok(HTML_SEM_SLOT.includes('Poltrona Beny'), 'ISCA CEGA: o render estava vazio — a ausência não prova nada');

    // Com handler: o botão, com o `title` literal e a geometria de `:110`.
    const botao = ARVORE_COM_HANDLERS.find((e) => e.tag === 'button' && e.titulo === MOD_TABELA.TITULO_SELECIONAR);
    assert.ok(botao, 'o checkbox do cabeçalho não apareceu COM handler');
    for (const classe of ['h-[16px]', 'w-[16px]', 'cursor-pointer', 'rounded-pub-badge', 'text-[10px]', 'font-[800]', 'text-pub-page', 'p-0']) {
        assert.ok(classesDe(botao).has(classe), `o checkbox perdeu \`${classe}\``);
    }

    // ⚠️⚠️ `font-extrabold` é PROIBIDO pelo GATE-03 em `ARQUIVOS_NOVOS`, e a tabela
    // FID-01 do plano o prescrevia. A isca garante que ele não volte.
    assert.doesNotMatch(TODAS_AS_FONTES, /font-extrabold/, 'o GATE-03 proíbe `font-extrabold` — use `font-[800]`');
    assert.match(FONTE_TABELA, /font-\[800\]/, 'o peso 800 do protótipo (`:110`) desapareceu');

    // `✓` marcado é U+2713, e ele só aparece marcado.
    assert.equal(MOD_TABELA.GLIFO_MARCADO, '✓');
    assert.ok(HTML_COM_HANDLERS.includes(MOD_TABELA.GLIFO_MARCADO), 'o glifo da marcação não apareceu com `visiveisMarcadas`');
    const semMarcar = render(Tabela, { grupos: GRUPOS, onMarcarVisiveis: nada, visiveisMarcadas: false });
    assert.ok(!semMarcar.includes(MOD_TABELA.GLIFO_MARCADO), 'o glifo apareceu DESMARCADO');
    assert.ok(semMarcar.includes(MOD_TABELA.TITULO_SELECIONAR), 'ISCA CEGA: o render desmarcado estava vazio');
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. A LINHA DE PRODUTO (nível 1): 52px, tile, tag PRODUTO, SKU, "N ofertas"
//
// O que precisaria estar errado para falhar: a altura, o fundo, o wrapper de
// `gap:8px` que o plano OMITE, o `letra="text-[11px]"` do tile (a escada de
// `TileDoProduto` devolveria 10.5px), ou a frase do cadastro aparecendo onde ela
// é FALSA.
// ═══════════════════════════════════════════════════════════════════════════

test('a linha de produto: 52px, `bg-pub-raised`, tile de 34px e tag PRODUTO 187-07', () => {
    const html = render(LinhaDeProduto, { grupo: GRUPO_1 });
    const arvore = tagsDeAbertura(html);

    const faixa = arvore[0];
    for (const classe of ['flex', 'h-[52px]', 'items-center', 'gap-[12px]', 'bg-pub-raised', 'px-[16px]']) {
        assert.ok(classesDe(faixa).has(classe), `a linha de produto perdeu \`${classe}\``);
    }

    // ⚠️⚠️ O WRAPPER que o plano OMITE (`:117`): `gap:8px` entre nome e tag.
    const wrapper = arvore.find((e) => classesDe(e).has('gap-[8px]') && classesDe(e).has('text-[14px]'));
    assert.ok(wrapper, 'o wrapper de `gap:8px` do nome + tag PRODUTO não existe (a OMISSÃO do plano)');
    assert.ok(classesDe(wrapper).has('font-semibold'), 'o nome deveria ser 14px/600');

    // ⚠️ E o `gap:8px` é DIFERENTE do `gap:12px` da faixa — os dois existem.
    assert.ok(classesDe(faixa).has('gap-[12px]'));
    assert.equal(classesDe(faixa).has('gap-[8px]'), false, 'a faixa colapsou os dois gaps num só');

    // A tag PRODUTO, com o `tracking` e a borda de `:117`.
    assert.equal(MOD_LINHA_PRODUTO.TAG_PRODUTO, 'PRODUTO');
    const tag = arvore.find((e) => classesDe(e).has('tracking-[0.06em]'));
    assert.ok(tag, 'a tag PRODUTO perdeu o `tracking-[0.06em]`');
    for (const classe of ['rounded-pub-badge', 'border', 'border-pub-line-strong', 'px-[6px]', 'py-[2px]', 'text-[10px]', 'font-semibold', 'text-pub-txt-3']) {
        assert.ok(classesDe(tag).has(classe), `a tag PRODUTO perdeu \`${classe}\``);
    }
    assert.ok(html.includes('>PRODUTO<'), 'o literal PRODUTO não foi desenhado');

    // O SKU em mono 11.5px e "N ofertas" em 12px.
    const sku = arvore.find((e) => classesDe(e).has('font-pub-mono'));
    assert.ok(sku, 'o SKU perdeu a fonte mono');
    assert.ok(classesDe(sku).has('text-[11.5px]') && classesDe(sku).has('text-pub-txt-3'));
    assert.ok(html.includes('POL-BENY'));
    const contagem = arvore.find((e) => classesDe(e).has('text-[12px]') && classesDe(e).has('text-pub-txt-4'));
    assert.ok(contagem, '"N ofertas" deveria ser 12px / `txt-4`');
    assert.ok(html.includes('3 ofertas'));

    // ⚠️ O tile: `TileDoProduto` com `letra="text-[11px]"` — a escada devolveria
    // 10.5px, e o protótipo mede 11px (`:116`).
    assert.match(FONTE_LINHA_PRODUTO, /letra="text-\[11px\]"/, 'o tile saiu da medida de `:116`');
    assert.match(FONTE_LINHA_PRODUTO, /tamanho=\{34\}/);
    assert.match(FONTE_LINHA_PRODUTO, /raio=\{8\}/);
    // ⚠️ IDENT-08: as iniciais e o tom NÃO nascem aqui.
    assert.doesNotMatch(FONTE_LINHA_PRODUTO, /oklch\(/, 'o tom do tile foi recriado — IDENT-08');
    assert.doesNotMatch(FONTE_LINHA_PRODUTO, /\.toUpperCase\(\)/, 'as iniciais foram recriadas — IDENT-08');
    assert.match(FONTE_LINHA_PRODUTO, /TileDoProduto/, 'o tile compartilhado não é importado');
});

test('⚠️ a frase do cadastro só aparece quando o servidor diz que é VERDADE 187-07', () => {
    const FRASE = 'Cadastro completo · categoria e ficha técnica organizadas';

    // Com a frase: ela sai byte a byte, e com o `ml-auto` de `:120`.
    const comFrase = render(LinhaDeProduto, { grupo: GRUPO_1 });
    assert.ok(comFrase.includes(FRASE), 'a frase do cadastro não foi desenhada quando é VERDADE');
    const daFrase = tagsDeAbertura(comFrase).find((e) => classesDe(e).has('ml-auto'));
    assert.ok(daFrase, 'a frase do cadastro perdeu o `ml-auto` de `:120`');
    for (const classe of ['truncate', 'text-[12px]', 'text-pub-txt-3']) {
        assert.ok(classesDe(daFrase).has(classe), `a frase do cadastro perdeu \`${classe}\``);
    }

    // ⚠️⚠️ Sem a frase: asserção de AUSÊNCIA rodada com as props PREENCHIDAS.
    const semFrase = render(LinhaDeProduto, { grupo: GRUPO_2 });
    assert.ok(!semFrase.includes(FRASE), 'a frase apareceu com `cadastro: null` — afirmação FALSA sobre o cadastro');
    assert.ok(!semFrase.includes('Cadastro'), 'sobrou um pedaço da frase');
    assert.ok(semFrase.includes('Mesa Tora'), 'ISCA CEGA: o render estava vazio — a ausência não prova nada');

    // ⚠️ E nada de texto fixo no lugar dela: nem "—", nem "pendente".
    assert.ok(!semFrase.includes('pendente'), 'um rótulo genérico ocupou o lugar da frase');
    assert.ok(!semFrase.includes('—'), 'um travessão ocupou o lugar da frase');

    // ⚠️ O literal nunca é escrito no JSX: ele vem do SERVIDOR (ele mora em
    // `GrupoDaFilaService::CADASTRO_COMPLETO`). Duas cópias divergiriam.
    assert.doesNotMatch(FONTE_LINHA_PRODUTO, /Cadastro completo/, 'o literal da frase foi recopiado no JSX');
    const servico = fs.readFileSync(path.resolve(RAIZ, REL_GRUPO_SERVICO), 'utf8');
    assert.ok(servico.includes(FRASE), 'o literal da frase saiu do serviço — a única casa dele');
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. A SUB-LINHA DE CONJUNTO (nível 2): 38px, recuo de 62px, pill e atalho
//
// O que precisaria estar errado para falhar: a altura, o recuo (o plano manda
// mutar `pl-[62px]` por `pl-[60px]`), o token `pub-sunken-2` virar o "parecido"
// (`pub-sunken`), o pill aparecer num conjunto de uma oferta só, ou o botão
// perder o `whitespace-nowrap` que a tabela do plano OMITE.
// ═══════════════════════════════════════════════════════════════════════════

test('a sub-linha: 38px, `pl-[62px]`, `bg-pub-sunken-2` e a borda `line-subtle-2` 187-07', () => {
    const html = render(SubLinha, { conjunto: CONJUNTO_COMPARTILHADO });
    const faixa = tagsDeAbertura(html)[0];

    for (const classe of ['flex', 'h-[38px]', 'items-center', 'gap-[10px]', 'border-t', 'border-pub-line-subtle-2', 'bg-pub-sunken-2', 'pl-[62px]', 'pr-[16px]', 'text-[12px]', 'text-pub-txt-2']) {
        assert.ok(classesDe(faixa).has(classe), `a sub-linha perdeu \`${classe}\``);
    }

    // ⚠️⚠️ O token `pub-sunken-2` NÃO é o `pub-sunken`: o protótipo pinta os TRÊS
    // tons distintos (`#0D0D0D` / `#0F0F0F` / `#131313`) e "usar o parecido que já
    // existe" é FID-02, justificativa VETADA.
    assert.equal(classesDe(faixa).has('bg-pub-sunken'), false, 'a sub-linha encostou no token PARECIDO (FID-02)');
    assert.equal(classesDe(faixa).has('bg-pub-raised-0'), false, 'a sub-linha encostou no token PARECIDO (FID-02)');

    // ⚠️ O recuo é assimétrico: 62px à esquerda, 16px à direita (`:124`).
    assert.equal(classesDe(faixa).has('px-[16px]'), false, 'o padding virou simétrico — o recuo de 62px é só à esquerda');

    // O rótulo do conjunto (600 / `fase1`) e o separador (`line-off`).
    const rotulo = tagsDeAbertura(html).find((e) => classesDe(e).has('font-semibold') && classesDe(e).has('text-pub-fase1'));
    assert.ok(rotulo, 'o rótulo do conjunto perdeu 600/`fase1`');
    assert.ok(html.includes('Unidade'));
    const separador = tagsDeAbertura(html).find((e) => classesDe(e).has('text-pub-line-off'));
    assert.ok(separador, 'o separador "·" perdeu o `text-pub-line-off` de `:126`');
    assert.equal(MOD_SUBLINHA.SEPARADOR, '·', 'o separador tem de ser U+00B7, nunca a letra `x` nem um hífen');
    assert.ok(html.includes('3 fotos (2 geradas por IA)'), 'o literal das fotos de `:1262` não saiu');
});

test('"x/y aprovadas" é verde com x===y e âmbar com x<y, e a cor nunca é o único sinal 187-07', () => {
    const parcial = render(SubLinha, { conjunto: CONJUNTO_COMPARTILHADO });
    const completo = render(SubLinha, { conjunto: CONJUNTO_SOZINHO });

    const doParcial = tagsDeAbertura(parcial).find((e) => classesDe(e).has('font-medium'));
    const doCompleto = tagsDeAbertura(completo).find((e) => classesDe(e).has('font-medium'));

    assert.ok(classesDe(doParcial).has('text-pub-warn-txt'), 'x < y deveria ser âmbar');
    assert.ok(classesDe(doCompleto).has('text-pub-ok-txt'), 'x === y deveria ser verde');
    // ⚠️ E as duas cores são DIFERENTES — se colapsassem, o gate acima passaria.
    assert.notEqual(doParcial.classe, doCompleto.classe, 'as duas cores do progresso colapsaram');

    // ⚠️⚠️ A COR NUNCA É O ÚNICO SINAL: o TEXTO traz os dois números.
    assert.ok(parcial.includes('1/3 aprovadas'), 'o progresso saiu SEM texto — a cor viraria o único sinal');
    assert.ok(completo.includes('2/2 aprovadas'));

    // ⚠️ Sem texto, o bloco colorido NÃO é desenhado.
    const semTexto = render(SubLinha, { conjunto: { ...CONJUNTO_COMPARTILHADO, progresso: null } });
    assert.ok(!semTexto.includes('font-medium'), 'um bloco colorido SEM número foi desenhado');
    assert.ok(semTexto.includes('Unidade'), 'ISCA CEGA: o render estava vazio');
});

test('⚠️ o pill da aprovação compartilhada só aparece com `compartilhado: true` 187-07', () => {
    const LITERAL = 'Mesmas fotos em Clássico e Premium · aprovação compartilhada';

    // O literal é LIDO do módulo e conferido contra o PROTÓTIPO (`:129`).
    assert.equal(MOD_SUBLINHA.PILL_COMPARTILHADO, LITERAL);
    assert.ok(TEXTO_PROTOTIPO.includes(LITERAL), 'o protótipo não declara o literal do pill — remeça `:129`');

    const com = render(SubLinha, { conjunto: CONJUNTO_COMPARTILHADO });
    assert.ok(com.includes(LITERAL), 'o pill não apareceu com `compartilhado: true`');
    const doPill = tagsDeAbertura(com).find((e) => classesDe(e).has('rounded-full'));
    assert.ok(doPill, 'o pill perdeu o `rounded-full` de `:129` (`border-radius:999px`)');
    for (const classe of ['flex', 'h-[22px]', 'items-center', 'gap-[6px]', 'whitespace-nowrap', 'border', 'border-pub-line-strong', 'px-[8px]', 'text-pub-fase1']) {
        assert.ok(classesDe(doPill).has(classe), `o pill perdeu \`${classe}\``);
    }

    // ⚠️⚠️ Ausência rodada com as props PREENCHIDAS: o conjunto SOZINHO tem rótulo,
    // fotos, quadrados e progresso — só `compartilhado` é `false`.
    const sem = render(SubLinha, { conjunto: CONJUNTO_SOZINHO });
    assert.ok(!sem.includes(LITERAL), 'o pill apareceu num conjunto de UMA oferta — a frase seria FALSA');
    assert.ok(!sem.includes('Mesmas fotos'), 'sobrou um pedaço do pill');
    assert.ok(!sem.includes('rounded-full'), 'sobrou a casca do pill');
    assert.ok(sem.includes('Combo ×2') && sem.includes('2/2 aprovadas'), 'ISCA CEGA: o render estava vazio');

    // ⚠️ Truthiness não vale: `'sim'`, `1` e `{}` NÃO são compartilhado.
    for (const valor of ['sim', 1, {}, [], 'true']) {
        const html = render(SubLinha, { conjunto: { ...CONJUNTO_SOZINHO, compartilhado: valor } });
        assert.ok(!html.includes('Mesmas fotos'), `\`compartilhado: ${typeof valor}\` desenhou o pill por truthiness`);
    }
});

test('"Revisar fotos do conjunto": 26px/raio 7/12px, `cursor-pointer` e `whitespace-nowrap` 187-07', () => {
    assert.equal(MOD_SUBLINHA.ROTULO_REVISAR_FOTOS, 'Revisar fotos do conjunto');
    assert.ok(TEXTO_PROTOTIPO.includes('Revisar fotos do conjunto'), 'o protótipo não declara o rótulo — remeça `:130`');

    const com = render(SubLinha, { conjunto: CONJUNTO_COMPARTILHADO, onRevisarFotos: nada });
    const botao = tagsDeAbertura(com).find((e) => e.tag === 'button');
    assert.ok(botao, 'o atalho não apareceu COM handler');
    for (const classe of ['ml-auto', 'h-[26px]', 'cursor-pointer', 'whitespace-nowrap', 'rounded-pub-control-sm', 'border', 'border-pub-line-strong', 'bg-pub-surface', 'px-[10px]', 'text-[12px]', 'text-pub-fase1', 'hover:border-pub-line-off']) {
        assert.ok(classesDe(botao).has(classe), `o atalho perdeu \`${classe}\``);
    }

    // ⚠️⚠️ `white-space:nowrap` é a OMISSÃO MEDIDA na tabela do plano, e ela é a
    // PRIMEIRA propriedade de `:130`. A isca lê o protótipo.
    const linha130 = TEXTO_PROTOTIPO.split(/\r?\n/)[129];
    assert.ok(linha130.includes('white-space:nowrap'), 'a linha :130 mudou de lugar — remeça o ponteiro');
    assert.ok(linha130.includes('cursor:pointer'), ':130 declara `cursor:pointer` (é um dos 5 do recorte)');

    // ⚠️ Sem handler o botão NÃO é desenhado: botão sem destino é D4 violado, e
    // `onClick` vazio é a mesma dívida com outra roupa.
    const sem = render(SubLinha, { conjunto: CONJUNTO_COMPARTILHADO });
    assert.ok(!sem.includes('<button'), 'o atalho foi desenhado SEM handler');
    assert.ok(!sem.includes('Revisar fotos'), 'sobrou o rótulo do atalho');
    assert.ok(sem.includes('Unidade'), 'ISCA CEGA: o render estava vazio');

    // ⚠️ E `onRevisarFotos` que não é função também não desenha.
    for (const valor of ['nada', 42, {}, []]) {
        const html = render(SubLinha, { conjunto: CONJUNTO_COMPARTILHADO, onRevisarFotos: valor });
        assert.ok(!html.includes('<button'), `\`onRevisarFotos: ${typeof valor}\` desenhou o botão`);
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. OS MINI-QUADRADOS: os 4 estados, os 2 tamanhos e o `title` não-vazio
//
// O que precisaria estar errado para falhar: o quadrado mudar de tamanho (o
// plano manda mutar 10px por 12px), a borda do `regenerando` deixar de ser
// tracejada, a ordem do ternário trocar (alerta antes de regenerando), ou um
// quadrado sair SEM `title` — e aí a cor seria o único sinal.
// ═══════════════════════════════════════════════════════════════════════════

test('os 4 estados do quadrado saem com as 4 combinações literais de `sq()` 187-07', () => {
    const casos = [
        { estado: 'approved', com_alerta: false, esperado: ['bg-pub-ok', 'border-pub-ok'], proibido: ['border-dashed'] },
        { estado: 'regenerating', com_alerta: false, esperado: ['bg-transparent', 'border-dashed', 'border-pub-txt-4'], proibido: ['bg-pub-ok'] },
        { estado: 'pending', com_alerta: true, esperado: ['bg-pub-danger/[0.3]', 'border-pub-danger'], proibido: ['border-dashed'] },
        { estado: 'pending', com_alerta: false, esperado: ['bg-transparent', 'border-pub-warn-txt'], proibido: ['border-dashed', 'bg-pub-ok'] },
    ];

    assert.equal(casos.length, 4, 'os 4 estados de `sq()` mudaram de número');

    const infratores = [];
    for (const caso of casos) {
        // ⚠️ Flag DENTRO do laço (é o mesmo motivo do `.map()`: o Rollup elimina
        // variável de escopo usada dentro do callback).
        const aparencia = MOD_QUADRADOS.aparenciaDoQuadrado(caso.estado, caso.com_alerta);
        const classes = new Set(aparencia.split(/\s+/));
        for (const classe of caso.esperado) {
            if (!classes.has(classe)) infratores.push(`${caso.estado}/${caso.com_alerta}: falta ${classe}`);
        }
        for (const classe of caso.proibido) {
            if (classes.has(classe)) infratores.push(`${caso.estado}/${caso.com_alerta}: sobra ${classe}`);
        }
    }

    // UMA asserção, coletando os infratores.
    assert.deepEqual(infratores, [], 'as 4 aparências do quadrado divergiram de `sq()` (`:1118`-`:1121`)');

    // ⚠️⚠️ A ORDEM DO TERNÁRIO: `regenerating` vem ANTES do alerta (`:1119` antes
    // de `:1120`). Uma foto sendo regerada COM alerta é TRACEJADA, não vermelha.
    const regenerandoComAlerta = MOD_QUADRADOS.aparenciaDoQuadrado('regenerating', true);
    assert.match(regenerandoComAlerta, /border-dashed/, 'a ordem do ternário inverteu: o alerta ganhou do regenerando');
    assert.doesNotMatch(regenerandoComAlerta, /border-pub-danger/, 'a ordem do ternário inverteu');

    // ⚠️ `approved` ganha de TUDO (`:1118` é o primeiro).
    assert.match(MOD_QUADRADOS.aparenciaDoQuadrado('approved', true), /bg-pub-ok/, '`approved` deveria ganhar do alerta');

    // ⚠️ Truthiness não vale para o alerta.
    for (const valor of ['sim', 1, {}, [], 'true']) {
        assert.match(
            MOD_QUADRADOS.aparenciaDoQuadrado('pending', valor),
            /border-pub-warn-txt/,
            `\`com_alerta: ${typeof valor}\` pintou de vermelho por truthiness`,
        );
    }

    // ⚠️ Estado desconhecido/nulo cai no default, sem lançar.
    for (const valor of [null, undefined, 42, {}, [], NaN, { toString: null }]) {
        assert.match(MOD_QUADRADOS.aparenciaDoQuadrado(valor, false), /border-pub-warn-txt/);
    }
});

test('os DOIS tamanhos de quadrado (10px e 12px) são literais, e o `title` nunca é vazio 187-07', () => {
    const de10 = render(Quadrados, { quadrados: QUADRADOS, tamanho: 10 });
    const de12 = render(Quadrados, { quadrados: QUADRADOS, tamanho: 12 });

    assert.match(de10, /h-\[10px\] w-\[10px\]/, 'o quadrado de 10px (`:127`) não saiu');
    assert.match(de12, /h-\[12px\] w-\[12px\]/, 'o quadrado de 12px (`:153`) não saiu');
    // ⚠️⚠️ São DIFERENTES de propósito: "deixei igual" é FID-02.
    assert.ok(!de10.includes('h-[12px]'), 'o de 10px virou 12px');
    assert.ok(!de12.includes('h-[10px]'), 'o de 12px virou 10px');

    // ⚠️ Tamanho fora do mapa FECHADO cai no de 10px, nunca numa classe inventada.
    for (const valor of [11, 0, -1, null, '12', {}, NaN]) {
        const html = render(Quadrados, { quadrados: QUADRADOS, tamanho: valor });
        assert.match(html, /h-\[10px\]/, `tamanho ${JSON.stringify(valor)} não caiu no default de 10px`);
    }

    // ⚠️ **Nenhuma classe montada por CONCATENAÇÃO:** o scanner do Tailwind não vê
    // `h-[${n}px]`, e o quadrado sairia SEM TAMANHO na tela com o render verde.
    assert.doesNotMatch(FONTE_QUADRADOS, /h-\[\$\{/, 'classe montada por interpolação — o Tailwind não a vê');
    assert.doesNotMatch(FONTE_QUADRADOS, /w-\[\$\{/);
    assert.doesNotMatch(TODAS_AS_FONTES, /`[a-z-]+-\[\$\{/, 'classe montada por template literal');

    // ⚠️⚠️ TODO quadrado desenhado tem `title` NÃO-VAZIO: sem ele a cor seria o
    // único sinal (VOC-05), e `title=""` é `title` ausente.
    const comTitulo = [...de10.matchAll(/<span title="([^"]*)"/g)].map((m) => m[1]);
    assert.equal(comTitulo.length, QUADRADOS.length, 'algum quadrado saiu SEM `title`');
    for (const titulo of comTitulo) {
        assert.notEqual(titulo.trim(), '', '`title` vazio é `title` AUSENTE');
    }
    assert.ok(de10.includes('Capa · aprovada'), 'o `title` do servidor não chegou ao quadrado');

    // ⚠️ `titulo` inválido NÃO vira `title="undefined"` nem `title="[object Object]"`.
    const adversos = render(Quadrados, {
        quadrados: [{ id: 1, estado: 'pending', titulo: {} }, { id: 2, estado: 'pending', titulo: '' }, { id: 3, estado: 'pending' }],
        tamanho: 10,
    });
    assert.doesNotMatch(adversos, /title="/, '`titulo` inválido virou atributo — nunca `String(valor)`');
    assert.doesNotMatch(adversos, /\[object Object\]|undefined|NaN/);

    // ⚠️ Lista vazia/inválida não desenha casca nenhuma (D4).
    for (const valor of [[], null, undefined, 'tres', 42, {}]) {
        assert.equal(render(Quadrados, { quadrados: valor }), '', `\`quadrados: ${JSON.stringify(valor)}\` desenhou casca`);
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. ⚠️⚠️ O SLOT DAS LINHAS: SEM NÓ, NENHUMA CASCA DE LINHA (D4)
//
// O que precisaria estar errado para falhar: alguém desenhar a casca da linha
// "para a tabela não ficar estranha" — e aí a Fase 187, que está NO AR parcial,
// mostraria linha vazia no lugar da oferta.
// ═══════════════════════════════════════════════════════════════════════════

test('sem o slot `linhaDaOferta`, o HTML tem cabeçalho + produto + sub-linha e ZERO linha de oferta 187-07', () => {
    // O que ESTÁ lá.
    assert.ok(HTML_SEM_SLOT.includes('Oferta'), 'o cabeçalho desapareceu');
    assert.ok(HTML_SEM_SLOT.includes('Poltrona Beny'), 'a linha de produto desapareceu');
    assert.ok(HTML_SEM_SLOT.includes('1/3 aprovadas'), 'a sub-linha desapareceu');

    // ⚠️ O que NÃO está: nenhuma casca de linha de oferta. A marca é a classe que
    // só a linha da oferta tem (o `min-h-[64px]` de `:133`, plano 187-08).
    assert.ok(!HTML_SEM_SLOT.includes('min-h-[64px]'), 'uma casca de linha de oferta foi desenhada sem o slot');
    assert.ok(!HTML_SEM_SLOT.includes('casca-da-linha'), 'o slot foi chamado sem função');

    // ⚠️ ISCA DO SLOT: COM a função, as 4 ofertas da fixture viram 4 nós. Sem esta
    // isca, "zero cascas" poderia ser um `.map()` que nunca roda.
    assert.equal((HTML_COM_SLOT.match(/casca-da-linha/g) || []).length, 4, 'o slot não desenhou as 4 ofertas da fixture');
    assert.ok(HTML_COM_SLOT.includes('data-linha="9001"') && HTML_COM_SLOT.includes('data-linha="9004"'), 'o slot não recebeu a oferta');

    // ⚠️ Nó INVÁLIDO devolvido pelo slot não derruba a página nem vaza texto.
    for (const retorno of [null, undefined, 'texto', 42, {}, [], { toString: null }]) {
        const html = render(Tabela, { grupos: GRUPOS, linhaDaOferta: () => retorno });
        assert.ok(html.includes('Poltrona Beny'), `slot devolvendo ${typeof retorno} derrubou a tabela`);
        assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/);
    }

    // ⚠️ `linhaDaOferta` que não é função também não desenha casca.
    for (const valor of ['nada', 42, {}, []]) {
        const html = render(Tabela, { grupos: GRUPOS, linhaDaOferta: valor });
        assert.ok(!html.includes('min-h-[64px]'), `\`linhaDaOferta: ${typeof valor}\` desenhou casca`);
    }
});

test('o primeiro grupo NÃO tem borda superior; do segundo em diante, `border-t border-pub-line` 187-07', () => {
    // ⚠️⚠️ **CONTAGEM POR CONJUNTO DE CLASSES, e isto é CORREÇÃO MEDIDA nesta
    // sessão.** A primeira redação deste caso usava `html.includes('border-pub-line ')`
    // e reprovou o código CERTO: o `<section>` da tabela carrega
    // `border border-pub-line bg-pub-surface` (`:108`), então aquela substring
    // existe SEMPRE — o gate estava medindo a casca, não o embrulho do grupo.
    // ⚠️ É a família *"substring casa com prefixo"* / *"gate mais largo que o
    // invariante"*, e a diferença entre os dois é `border-t`: a borda ENTRE GRUPOS
    // é só superior; a da casca é a borda inteira.
    const bordasEntreGrupos = (html) => tagsDeAbertura(html)
        .filter((e) => classesDe(e).has('border-t') && classesDe(e).has('border-pub-line'));

    // ⚠️ ISCA DA FORMA, nas duas direções: a sonda SABE separar a casca do
    // embrulho. Sem isto, estreitá-la poderia tê-la tornado um gate vazio.
    const daCasca = tagsDeAbertura(HTML_SEM_SLOT).find((e) => e.tag === 'section');
    assert.ok(daCasca, 'não achei o `<section>` da tabela');
    assert.ok(classesDe(daCasca).has('border-pub-line'), 'a casca perdeu a borda de `:108`');
    assert.equal(classesDe(daCasca).has('border-t'), false, 'a casca ganhou `border-t` — a sonda deixou de discriminar');

    // Com DOIS grupos: UMA borda (o 2º).
    assert.equal(bordasEntreGrupos(HTML_SEM_SLOT).length, 1, 'a borda entre grupos deveria aparecer UMA vez com DOIS grupos');

    // ⚠️ ISCA: com UM grupo só, NENHUMA borda. É o que prova que a regra do
    // `:1260` (`gi ? … : 'none'`) está sendo aplicada e não é decoração fixa.
    const umSo = render(Tabela, { grupos: [GRUPO_1] });
    assert.equal(bordasEntreGrupos(umSo).length, 0, 'o grupo ÚNICO ganhou borda superior');
    assert.ok(umSo.includes('Poltrona Beny'), 'ISCA CEGA: o render estava vazio');

    // ⚠️ E com TRÊS grupos, DUAS bordas — a contagem acompanha.
    const tres = render(Tabela, { grupos: [GRUPO_1, GRUPO_2, { ...GRUPO_2, produto_id: 501, nome: 'Rack Vega' }] });
    assert.equal(bordasEntreGrupos(tres).length, 2, 'com três grupos deveriam ser DUAS bordas');

    // ⚠️⚠️ E o ÍNDICE é a trava: mesmo com o servidor mandando `borda_superior: true`
    // no PRIMEIRO, ele NÃO ganha borda.
    const forjado = render(Tabela, { grupos: [{ ...GRUPO_1, borda_superior: true }] });
    assert.equal(bordasEntreGrupos(forjado).length, 0, 'o PRIMEIRO grupo ganhou borda com o dado forjado');

    // ⚠️ E o inverso: o servidor mandando `false` no SEGUNDO tira a borda dele —
    // o dado manda, o índice só trava o primeiro.
    const semNoSegundo = render(Tabela, { grupos: [GRUPO_1, { ...GRUPO_2, borda_superior: false }] });
    assert.equal(bordasEntreGrupos(semNoSegundo).length, 0, 'o `borda_superior: false` do servidor foi ignorado');
    assert.ok(semNoSegundo.includes('Mesa Tora'), 'ISCA CEGA: o render estava vazio');
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. ⚠️⚠️ RENDER ADVERSO — A TELA PRETA DE 07/10/2026
//
// O que precisaria estar errado para falhar: `String(valor)`, `hasOwnProperty`
// ou `obj[chave]` com `{toString: null}`; `grupos` chegando string e o `.map()`
// explodindo; prop de NÓ chegando objeto comum (o React LANÇA). Voltou QUATRO
// vezes na Fase 180 e UMA na wave 6 — aquela **no CLIQUE**.
// ═══════════════════════════════════════════════════════════════════════════

const ADVERSOS = [
    null, undefined, 42, -1, 0, NaN, Infinity, '', 'texto', true, false,
    {}, [], [1, 2], { toString: null }, { valueOf: null },
    { hasOwnProperty: null }, Object.create(null), () => {}, Symbol.iterator.toString(),
];

test('RENDER ADVERSO: `grupos` e cada campo da árvore em 20 formas, sem derrubar nem vazar 187-07', () => {
    // A prop inteira, em todas as formas.
    for (const valor of ADVERSOS) {
        const html = render(Tabela, { grupos: valor });
        assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `\`grupos: ${typeof valor}\` vazou marcador`);
    }

    // Cada CAMPO do grupo, um por vez, com os outros PREENCHIDOS.
    const camposDoGrupo = ['produto_id', 'nome', 'sku', 'contagem', 'borda_superior', 'cadastro', 'conjuntos'];
    for (const campo of camposDoGrupo) {
        for (const valor of ADVERSOS) {
            const html = render(Tabela, { grupos: [{ ...GRUPO_1, [campo]: valor }] });
            assert.doesNotMatch(
                html,
                /\[object Object\]|NaN|undefined|Invalid Date/,
                `grupo.${campo} como ${typeof valor} vazou marcador`,
            );
        }
    }

    // Cada CAMPO do conjunto.
    const camposDoConjunto = ['rascunho_id', 'rotulo', 'fotos', 'quadrados', 'progresso', 'completo', 'compartilhado', 'ofertas'];
    for (const campo of camposDoConjunto) {
        for (const valor of ADVERSOS) {
            const html = render(Tabela, {
                grupos: [{ ...GRUPO_1, conjuntos: [{ ...CONJUNTO_COMPARTILHADO, [campo]: valor }] }],
                linhaDaOferta: () => React.createElement('i', null, 'x'),
                onRevisarFotos: nada,
            });
            assert.doesNotMatch(
                html,
                /\[object Object\]|NaN|undefined|Invalid Date/,
                `conjunto.${campo} como ${typeof valor} vazou marcador`,
            );
        }
    }

    // Cada CAMPO do quadrado.
    for (const campo of ['id', 'estado', 'com_alerta', 'titulo', 'de_ia', 'rotulo']) {
        for (const valor of ADVERSOS) {
            const html = render(Quadrados, { quadrados: [{ ...QUADRADOS[0], [campo]: valor }], tamanho: 12 });
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `quadrado.${campo} como ${typeof valor} vazou`);
        }
    }

    // Os grupos/conjuntos/quadrados NÃO-objeto dentro da lista.
    for (const valor of ADVERSOS) {
        const html = render(Tabela, { grupos: [valor, GRUPO_1] });
        assert.ok(html.includes('Poltrona Beny'), `grupo ${typeof valor} na lista derrubou os VÁLIDOS`);
        assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/);
    }

    // Os SLOTS de nó, em todas as formas.
    for (const valor of ADVERSOS) {
        const html = render(Tabela, { grupos: GRUPOS, rodape: valor, vazio: valor });
        assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `slot ${typeof valor} vazou marcador`);
    }

    // ⚠️ ISCA DO MEDIDOR: ele SABE pegar os quatro marcadores. Sem isto, um
    // `doesNotMatch` que nunca casa nada seria gate vazio por construção.
    for (const sujo of ['[object Object]', 'NaN', 'undefined', 'Invalid Date']) {
        assert.match(`a ${sujo} b`, /\[object Object\]|NaN|undefined|Invalid Date/, 'o medidor do render adverso está cego');
    }
    assert.equal(ADVERSOS.length, 20, 'as formas adversas mudaram de número — remeça o cabeçalho');
});

test('TODA flag nasce DENTRO do callback de cada `.map()` 187-07', () => {
    // ⚠️⚠️ O Rollup elimina variável de escopo do componente usada dentro do
    // `.map()`, e o `ReferenceError` só aparece no bundle de PRODUÇÃO. São TRÊS
    // `.map()` aninhados na tabela (grupos → conjuntos → ofertas) e um nos
    // quadrados.
    //
    // A sonda: todo corpo de `.map((...) => {` dos arquivos desta wave declara as
    // suas próprias `const` antes de usar qualquer nome derivado.
    const mapsDaTabela = [...FONTE_TABELA.matchAll(/\.map\(\(([^)]*)\)\s*=>\s*\{/g)];
    assert.ok(mapsDaTabela.length >= 3, `achei ${mapsDaTabela.length} \`.map()\` com corpo na tabela — esperava >= 3`);

    const mapsDoQuadrado = [...FONTE_QUADRADOS.matchAll(/\.map\(\(([^)]*)\)\s*=>\s*\{/g)];
    assert.equal(mapsDoQuadrado.length, 1, 'o `.map()` dos quadrados desapareceu');

    // ⚠️⚠️ **O ÍNDICE É COBRADO SÓ DOS `.map()` SOBRE DADO DO SERVIDOR, e isto é
    // CORREÇÃO MEDIDA nesta sessão.** A primeira redação exigia índice de TODO
    // `.map()` com corpo, e reprovou o código CERTO: o `.map()` dos 8 rótulos do
    // cabeçalho varre uma CONSTANTE DO MÓDULO (`ROTULOS_DO_CABECALHO`), cuja chave
    // do React é o próprio rótulo — único por construção — e que **não tem índice
    // para que serviria**. O invariante não é "todo map recebe índice": é **"toda
    // flag nasce dentro do callback"**, e o índice só é necessário onde a chave
    // depende de um `id` que pode chegar inválido.
    // ⚠️ Era *"gate mais largo que o invariante"*, a família que já reprovou
    // trabalho correto de outra sessão nove vezes nesta milestone.
    const SOBRE_DADO_DO_SERVIDOR = ['cruGrupo', 'cruConjunto', 'cruOferta'];
    const comIndice = mapsDaTabela.filter(([, p]) => SOBRE_DADO_DO_SERVIDOR.some((n) => p.includes(n)));
    assert.equal(comIndice.length, 3, `achei ${comIndice.length} \`.map()\` sobre dado do servidor — esperava os 3 aninhados`);
    for (const [, params] of comIndice) {
        assert.match(params, /,\s*indice/, `um \`.map()\` sobre dado do servidor não recebe o índice: (${params})`);
    }

    // ⚠️ E o dos quadrados também, pelo mesmo motivo (o `id` pode chegar inválido).
    assert.match(mapsDoQuadrado[0][1], /,\s*indice/, 'o `.map()` dos quadrados não recebe o índice');

    // ⚠️⚠️ O INVARIANTE DE VERDADE: cada corpo de `.map()` destes arquivos declara
    // as suas PRÓPRIAS `const` antes de desenhar. É isso que o Rollup não pode
    // eliminar — variável de escopo do componente usada dentro do callback vira
    // `ReferenceError` só no bundle de produção.
    for (const fonte of [FONTE_TABELA, FONTE_QUADRADOS]) {
        for (const casou of fonte.matchAll(/\.map\(\([^)]*\)\s*=>\s*\{([\s\S]{0,900}?)\n\s{8,}\}/g)) {
            // Flag DENTRO do laço.
            const corpo = casou[1];
            assert.match(corpo, /\bconst\s+\w+\s*=/, `um corpo de \`.map()\` não declara flag própria: ${corpo.slice(0, 90)}`);
        }
    }

    // ⚠️ ISCA DA SONDA, nas duas direções: ela SABE casar a forma que procura e
    // NÃO casa a sem corpo. Sem estas duas linhas, uma regex quebrada faria os
    // laços acima rodarem sobre ZERO casos — gate vazio por construção.
    assert.equal([...'x.map((a, indice) => { return a; })'.matchAll(/\.map\(\(([^)]*)\)\s*=>\s*\{/g)].length, 1);
    assert.equal([...'x.map((a) => a)'.matchAll(/\.map\(\(([^)]*)\)\s*=>\s*\{/g)].length, 0);
    assert.doesNotMatch('const x = 1;', /,\s*indice/, 'a sonda do índice casa qualquer coisa');
    assert.match('(cruGrupo, indice)', /,\s*indice/, 'a sonda do índice está cega');
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. AS AUSÊNCIAS: `localStorage`, "Restaurar dados de exemplo", "lucro líquido"
//
// O que precisaria estar errado para falhar: alguém guardar a seleção no
// navegador (COL-02), trazer o botão de dado de mentira do protótipo (`:200`,
// FILA-16), ou escrever "lucro líquido" (VOC-03) — que é afirmação FALSA, porque
// o número não desconta imposto, cartão, despesa fixa nem devolução.
// ═══════════════════════════════════════════════════════════════════════════

test('zero `localStorage`, zero "Restaurar dados de exemplo", zero "lucro líquido" 187-07', () => {
    // ⚠️ Lista CONSTANTE de arquivos, nunca varredura do `resources/` nem do
    // `app/`: `lucro_liquido` é campo LEGÍTIMO do Portal, e varrer largo
    // reprovaria trabalho de outra sessão — foi a 5ª ocorrência de "gate mais
    // largo que o invariante" na Fase 186, e ela **barrou um deploy**.
    const ARQUIVOS_DESTE_PLANO = [
        [REL_TABELA, FONTE_TABELA],
        [REL_LINHA_PRODUTO, FONTE_LINHA_PRODUTO],
        [REL_SUBLINHA, FONTE_SUBLINHA],
        [REL_QUADRADOS, FONTE_QUADRADOS],
    ];
    assert.equal(ARQUIVOS_DESTE_PLANO.length, 4, 'a lista constante de arquivos deste plano mudou de tamanho');

    const infratores = [];
    for (const [caminho, fonte] of ARQUIVOS_DESTE_PLANO) {
        // Flag DENTRO do laço.
        if (/localStorage|sessionStorage/.test(fonte)) infratores.push(`${caminho}: localStorage`);
        if (/Restaurar dados de exemplo/.test(fonte)) infratores.push(`${caminho}: dado de exemplo`);
        if (/lucro\s*l[íi]quido|lucro_liquido/i.test(fonte)) infratores.push(`${caminho}: lucro líquido`);
        if (/String\s*\(/.test(fonte)) infratores.push(`${caminho}: String(`);
        if (/JSON\.stringify/.test(fonte)) infratores.push(`${caminho}: JSON.stringify`);
    }
    assert.deepEqual(infratores, [], 'ausências violadas nos arquivos deste plano');

    // ⚠️ ISCAS DAS CINCO SONDAS, nas duas direções: cada uma PEGA a forma que
    // procura. Sem isto, uma regex quebrada seria gate que nunca reprova.
    assert.match('window.localStorage.getItem("x")', /localStorage|sessionStorage/);
    assert.match('Restaurar dados de exemplo', /Restaurar dados de exemplo/);
    assert.match('o lucro líquido do mês', /lucro\s*l[íi]quido|lucro_liquido/i);
    assert.match('lucro_liquido', /lucro\s*l[íi]quido|lucro_liquido/i);
    assert.match('String(v)', /String\s*\(/);
    assert.match('JSON.stringify(v)', /JSON\.stringify/);
    // E NÃO pegam o que é legítimo.
    assert.doesNotMatch('const lucro = 1;', /lucro\s*l[íi]quido|lucro_liquido/i);
    assert.doesNotMatch('toUpperCase()', /String\s*\(/);

    // ⚠️⚠️ "Restaurar dados de exemplo" EXISTE no protótipo (`:200`) — a ausência
    // aqui é DECISÃO (FILA-16/critério 13), não acaso. Sem esta linha, a asserção
    // acima poderia estar medindo um literal que nunca existiu.
    assert.ok(TEXTO_PROTOTIPO.includes('Restaurar dados de exemplo'), 'o protótipo não tem o literal — a ausência não prova nada');
});

test('a PÁGINA preencheu o slot `tabela` e o nó de composição ainda SOBREPÕE o dado 187-07', () => {
    // ⚠️ O par nó × dado: `tabela` (NÓ) × `grupos` (DADO) — duas props com UM
    // sentido cada, nunca uma com dois (defeito grave achado na Fase 186).
    assert.match(FONTE_PAGINA, /isValidElement\(tabela\)\s*\?\s*tabela\s*:\s*<TabelaDaFila/, 'o slot `tabela` não sobrepõe o dado');
    assert.match(FONTE_PAGINA, /grupos=\{grupos\}/, 'a página não repassa `grupos` ao componente');
    assert.match(FONTE_PAGINA, /^\s*grupos = null,/m, 'a prop `grupos` não tem default nulo');

    // ⚠️⚠️ **ESTA ASSERÇÃO FOI INVERTIDA PELO PLANO 187-08, E O MOTIVO É A PRÓPRIA
    // RÉGUA DO PROJETO — não conveniência.**
    //
    // A redação da wave 7 era `assert.doesNotMatch(FONTE_PAGINA, /linhaDaOferta=/,
    // '`linhaDaOferta` é do plano 187-08')`: uma asserção de AUSÊNCIA cuja própria
    // mensagem nomeava a wave que a tornaria FALSA. É literalmente o item 6 da
    // régua — *"asserção vazia quando escrita que vira FALSO POSITIVO depois"* —, e
    // a pergunta que ela deveria ter respondido é *"o desenho PROÍBE isso, ou eu só
    // não vi ainda?"*. O desenho **não** proibia: o slot nasceu NOMEADO E VAZIO no
    // 187-07 exatamente para o 187-08 o preencher.
    //
    // ➡️ Invertida PARA UMA POSITIVA, que é estritamente mais forte e permanente:
    // a página **tem** de preencher o slot, senão a tabela volta a desenhar
    // cabeçalho + produto + sub-linha e **nenhuma linha de oferta** — a fila ficaria
    // sem o conteúdo que ela existe para mostrar, e nenhum outro gate pegaria isso
    // (os casos acima, que provam "sem slot não há casca", rodam o COMPONENTE, não
    // a página). ⚠️ A contagem de asserções deste caso **não muda** (uma por uma),
    // então `contagens-187-07.txt` segue intocado — conferido, não suposto.
    // ⚠️ Foi o ÚNICO ponto em que uma régua da wave 7 reprovaria o trabalho certo
    // da wave 8; `:747` e `:765` continuam valendo porque renderizam o componente.
    assert.match(FONTE_PAGINA, /linhaDaOferta=\{/, 'a página deixou de preencher `linhaDaOferta` (plano 187-08)');

    // ⚠️⚠️ **O `onMarcarVisiveis` CHEGOU NO PLANO 187-09 — a SEGUNDA negativa desta
    // família invertida, e pela MESMA razão que a wave 8 já havia escrito logo
    // acima:** asserção cuja mensagem nomeia o plano que a tornará falsa é o item 6
    // da régua. O slot nasceu aqui (187-07) NOMEADO E VAZIO para esta wave o
    // preencher, e a positiva é mais forte: sem o handler, o checkbox do cabeçalho
    // não é desenhado e a fila perde a seleção em lote.
    // ⚠️ A contagem de asserções deste caso **não muda** (uma virou uma), então
    // `contagens-187-07.txt` segue intocado — conferido, não suposto.
    assert.match(FONTE_PAGINA, /onMarcarVisiveis=\{marcarVisiveis\}/, 'a página deixou de ligar a seleção em lote (plano 187-09)');

    // ⚠️ Zero `localStorage` e zero `String(` na página (gate da wave 4).
    assert.doesNotMatch(FONTE_PAGINA, /localStorage|sessionStorage/);
    assert.doesNotMatch(FONTE_PAGINA, /String\s*\(/, 'a wave 4 proíbe `String(` nesta página');
});

// ═══════════════════════════════════════════════════════════════════════════
// 9. O CSS CONSTRUÍDO — a classe EXISTE com o valor certo, não só "está escrita"
//
// O que precisaria estar errado para falhar: a classe sair do `content` do
// Tailwind (num `.js`, num mapa, numa string montada), ou o valor arbitrário não
// compilar — `duration-[Nms]` é o precedente MEDIDO deste projeto, e ele é a
// ISCA deste bloco.
//
// ⚠️ As OITO normalizações do medidor estão aplicadas: seletor ESCAPADO com
// escape GERAL (a 8ª, achada na wave 4: sem ela, classe com parêntese/vírgula
// sai AUSENTE estando presente — e o grid de 11 colunas TEM parêntese e vírgula),
// token sólido em `rgb()` DECIMAL, e a opacidade saindo como hex de 8 DÍGITOS.
// ⚠️ **A sonda de CSS NÃO protege a fidelidade de um ARQUIVO:** o CSS é do último
// build e a classe pode ter outro dono. Ela prova que a classe COMPILA.
// ═══════════════════════════════════════════════════════════════════════════

/** Escapa TODOS os metacaracteres de regex. */
function escapaRegex(texto) {
    return texto.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * O seletor que o Tailwind emite: `.` + o nome com os metacaracteres de CSS
 * escapados.
 *
 * ⚠️ A REGRA É GERAL — *escapa tudo que não é `[A-Za-z0-9_-]`*. A forma herdada
 * das waves 1-3 escapava só `[].:/%`, e com ela
 * `grid-cols-[repeat(9,minmax(0,1fr))]` saía **AUSENTE** no CSS construído,
 * embora a classe esteja lá e compilada (medido na wave 4). Aqui é o caso do
 * grid das 11 colunas, que tem `(`, `,` e `)`.
 */
function seletorDe(classe) {
    return `.${[...classe].map((ch) => (/[A-Za-z0-9_-]/.test(ch) ? ch : `\\${ch}`)).join('')}`;
}

/**
 * Os caminhos cujo `mtime` o CSS tem de superar.
 *
 * ⚠️ TODOS os `.jsx` deste plano entram: se um ficasse de fora, mutá-lo deixaria
 * a camada de CSS medir um build velho e o gate aprovaria o errado. ⚠️ É esta
 * guarda que produz os DOIS REGIMES de contagem do manifesto.
 */
const FONTES_DO_CSS = [REL_TABELA, REL_LINHA_PRODUTO, REL_SUBLINHA, REL_QUADRADOS, REL_PAGINA];

function carregarCssConstruido() {
    const manifestRel = 'public/build/manifest.json';
    const manifestAbs = path.resolve(RAIZ, manifestRel);
    if (!fs.existsSync(manifestAbs)) return { motivo: `${manifestRel} ausente — rode \`npm run build\`` };

    // ⚠️ Manifest SEMPRE pelo JSON, nunca por grep: o hash sai com HÍFEN
    // (`assets/app-Dv6-mbDf.js`, medido no 187-01) e o grep ingênuo não casa.
    // ⚠️ E `resources/css/app.css` NÃO é chave do manifest (divergência N5 do
    // `187-BASELINES.md`): o CSS entra por `manifest['resources/js/app.jsx'].css[0]`.
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

test('FID — as classes da tabela GERAM CSS com o valor certo 187-07', async (contexto) => {
    if (CSS.motivo) {
        // ⚠️ `skip` com razão ESCRITA (e `skipped N` aparece no sumário), nunca
        // `return` calado.
        contexto.skip(CSS.motivo);
        return;
    }

    /** O corpo da regra da classe, ou `null`. */
    const regraDe = (classe) => {
        const re = new RegExp(`${escapaRegex(seletorDe(classe))}(?![\\w\\\\-])[^{}]*\\{([^}]*)\\}`);
        const m = CSS.css.match(re);
        return m ? m[1] : null;
    };

    await contexto.test('o CSS lido é o do manifest, e a PÁGINA está no manifest', () => {
        assert.ok(CSS.css.length > 100000, `CSS suspeito de truncado: ${CSS.css.length} bytes`);

        // ⚠️ Página React FORA do manifest desaparece da aplicação SEM erro
        // nenhum. ⚠️ `hasOwnProperty.call` e nunca `manifest[k]` cru.
        const tem = (k) => Object.prototype.hasOwnProperty.call(CSS.manifest, k);
        assert.ok(tem(REL_PAGINA), `${REL_PAGINA} saiu do manifest — a tela desaparece sem erro`);
        assert.equal(tem('resources/js/Pages/Mlb/NaoExiste9z7.jsx'), false, 'a isca do manifest virou entrada de verdade');
    });

    await contexto.test('⚠️ ISCA: a sonda reporta AUSENTE o que não existe, e ACHA o que existe', () => {
        for (const isca of ['bg-pub-isca-187-07', 'text-[99.5px]', 'w-[997px]', 'h-[463px]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS.css.includes(isca), `a string da isca ${isca} aparece no CSS`);
        }
        assert.notEqual(regraDe('text-[12px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('⚠️⚠️ `duration-[Nms]` continua NÃO COMPILANDO, e `duration-200` compila', () => {
        assert.equal(regraDe('duration-[220ms]'), null, '`duration-[220ms]` passou a compilar — remeça a regra');
        assert.match(regraDe('duration-200'), /transition-duration:\s*\.?2s/, 'o CONTROLE da escala nomeada sumiu');
    });

    await contexto.test('⚠️⚠️ O GRID DE 11 COLUNAS compila, com as 11 trilhas na ordem', () => {
        const regra = regraDe(MOD_TABELA.GRID_DAS_11_COLUNAS);
        assert.notEqual(regra, null, 'o grid de 11 colunas NÃO COMPILOU — a 8ª normalização ou o `content`');
        // ⚠️ Comparação do VALOR da trilha, por string, não por "contém".
        assert.match(
            regra,
            /grid-template-columns:\s*32px 24px minmax\(170px,1fr\) 104px 118px 92px 108px 76px 112px 164px 96px/,
            `as trilhas compiladas divergiram: ${regra}`,
        );
    });

    await contexto.test('⚠️⚠️ `pub-sunken-2` ACORDA nesta wave, com o hex medido em `:124`', () => {
        // ⚠️ Token SÓLIDO não compila para hex: sai `rgb(15 15 15 / …)`, DECIMAL.
        const regra = regraDe('bg-pub-sunken-2');
        assert.notEqual(regra, null, '`bg-pub-sunken-2` não compilou — o token continua dormindo');
        assert.match(regra, /rgb\(\s*15\s+15\s+15\b/, '`#0F0F0F` em decimal');

        // ⚠️ E ele é DIFERENTE dos dois vizinhos no CSS compilado — é a prova, no
        // artefato final, de que os três tons não colapsaram (FID-02).
        assert.match(regraDe('bg-pub-sunken'), /rgb\(\s*13\s+13\s+13\b/, '`#0D0D0D` em decimal');
        assert.match(regraDe('bg-pub-raised'), /rgb\(\s*21\s+21\s+21\b/, '`#151515` em decimal');
    });

    await contexto.test('⚠️ os tokens `pub-float`/`pub-float-line` ACORDARAM no 187-09, e NÃO nesta wave', () => {
        // ⚠️⚠️ Medido no `tailwind.config.js`: os dois foram declarados para a
        // **barra de lote** (`:897`) e o **toast** (`:905`), que são do plano
        // **187-09** — NÃO para esta sub-linha. Usar token fora do lugar onde ele
        // foi medido só para zerar um `skip` é o que a wave 4 recusou, e estava
        // certa. ➡️ Eles acordaram na wave que os usa.
        //
        // ⚠️⚠️ **O PLANO 187-09 CHEGOU, E O `regraDe(...) === null` FOI INVERTIDO EM
        // VEZ DE AFROUXADO.** Aquela asserção era um PROXY: o invariante desta wave
        // nunca foi *"os tokens não compilam"* (isso não depende dela), e sim **"os
        // arquivos DESTA wave não os usam"**. O proxy virava falso no instante em
        // que a wave dona dos tokens chegasse — item 6 da régua, e o próprio
        // comentário acima já dizia *"eles acordam na wave que os usa"*.
        // ➡️ A forma nova é mais forte: ela prova que os tokens COMPILAM (se alguém
        // apagar a barra ou o toast, isto acusa) **e** que não é esta wave que os
        // usa (a negativa de fonte, que é o invariante de verdade, continua).
        assert.notEqual(regraDe('bg-pub-float'), null, '`pub-float` não compila — a barra de lote do 187-09 perdeu o fundo');
        assert.notEqual(regraDe('border-pub-float-line'), null, '`pub-float-line` não compila — a barra e o toast perderam a borda');
        assert.doesNotMatch(TODAS_AS_FONTES, /pub-float/, 'um arquivo desta wave usou `pub-float` — ele é do 187-09');

        // ⚠️ E quem os usa são EXATAMENTE os dois arquivos medidos (`:897` e `:905`).
        // Sem isto, a negativa de fonte acima seria satisfeita por "ninguém usa".
        for (const dono of [
            'resources/js/Components/Mlb/Publicador/aprovacao/BarraDeSelecao.jsx',
            'resources/js/Components/Mlb/Publicador/aprovacao/ToastDaFila.jsx',
        ]) {
            assert.match(fs.readFileSync(path.resolve(RAIZ, dono), 'utf8'), /pub-float/, `${dono} deixou de usar \`pub-float\``);
        }

        // ⚠️ ISCA: os dois EXISTEM na camada (o `skip` não é "token inexistente").
        const config = fs.readFileSync(path.resolve(RAIZ, 'tailwind.config.js'), 'utf8');
        assert.match(config, /float:\s*'#1C1C1C'/, 'o token `pub-float` saiu da camada — o `skip` perdeu o sentido');
        assert.match(config, /'float-line':\s*'#333333'/, 'o token `pub-float-line` saiu da camada');
    });

    await contexto.test('a GEOMETRIA das duas linhas: 52px, 38px, 62px e os quadrados', () => {
        assert.match(regraDe('h-[52px]'), /height:\s*52px/);
        assert.match(regraDe('h-[38px]'), /height:\s*38px/);
        assert.match(regraDe('pl-[62px]'), /padding-left:\s*62px/);
        assert.match(regraDe('pr-[16px]'), /padding-right:\s*16px/);
        assert.match(regraDe('h-[10px]'), /height:\s*10px/);
        assert.match(regraDe('w-[10px]'), /width:\s*10px/);
        assert.match(regraDe('h-[12px]'), /height:\s*12px/);
        assert.match(regraDe('w-[12px]'), /width:\s*12px/);
        assert.match(regraDe('rounded-[3px]'), /border-radius:\s*3px/);
        assert.match(regraDe('border-[1.5px]'), /border-width:\s*1\.5px/);
        assert.match(regraDe('border-dashed'), /border-style:\s*dashed/);
        assert.match(regraDe('gap-[3px]'), /gap:\s*3px/);
        assert.match(regraDe('gap-[8px]'), /gap:\s*8px/);
        assert.match(regraDe('gap-[10px]'), /gap:\s*10px/);
        assert.match(regraDe('gap-[12px]'), /gap:\s*12px/);
        assert.match(regraDe('ml-[4px]'), /margin-left:\s*4px/);
        assert.match(regraDe('h-[26px]'), /height:\s*26px/);
        assert.match(regraDe('h-[22px]'), /height:\s*22px/);
        assert.match(regraDe('h-[16px]'), /height:\s*16px/);
        assert.match(regraDe('rounded-pub-table'), /border-radius:\s*14px/);
        assert.match(regraDe('rounded-pub-control-sm'), /border-radius:\s*7px/);
        assert.match(regraDe('rounded-pub-badge'), /border-radius:\s*4px/);
        assert.match(regraDe('rounded-full'), /border-radius:\s*9999px/);
        assert.match(regraDe('tracking-[0.06em]'), /letter-spacing:\s*0?\.06em/);
        assert.match(regraDe('font-pub-mono'), /Geist Mono/);
        assert.match(regraDe('cursor-pointer'), /cursor:\s*pointer/);
        assert.match(regraDe('whitespace-nowrap'), /white-space:\s*nowrap/);
    });

    await contexto.test('as CORES por nome de token, em decimal, e o alfa do quadrado com alerta', () => {
        assert.match(regraDe('bg-pub-surface'), /rgb\(\s*17\s+17\s+17\b/, '`#111111` em decimal');
        assert.match(regraDe('border-pub-line'), /rgb\(\s*38\s+38\s+38\b/, '`#262626` em decimal');
        assert.match(regraDe('border-pub-line-subtle'), /rgb\(\s*31\s+31\s+31\b/, '`#1F1F1F` em decimal');
        assert.match(regraDe('border-pub-line-subtle-2'), /rgb\(\s*28\s+28\s+28\b/, '`#1C1C1C` em decimal');
        assert.match(regraDe('border-pub-line-strong'), /rgb\(\s*46\s+46\s+46\b/, '`#2E2E2E` em decimal');
        assert.match(regraDe('text-pub-line-off'), /rgb\(\s*63\s+63\s+70\b/, '`#3F3F46` em decimal');
        assert.match(regraDe('text-pub-txt-2'), /rgb\(\s*161\s+161\s+170\b/, '`#A1A1AA` em decimal');
        assert.match(regraDe('text-pub-txt-3'), /rgb\(\s*138\s+138\s+144\b/, '`#8A8A90` em decimal');
        assert.match(regraDe('text-pub-txt-4'), /rgb\(\s*113\s+113\s+122\b/, '`#71717A` em decimal');
        assert.match(regraDe('text-pub-fase1'), /rgb\(\s*212\s+212\s+216\b/, '`#D4D4D8` em decimal');
        assert.match(regraDe('text-pub-ok-txt'), /rgb\(\s*74\s+222\s+128\b/, '`#4ADE80` em decimal');
        assert.match(regraDe('text-pub-warn-txt'), /rgb\(\s*251\s+191\s+36\b/, '`#FBBF24` em decimal');
        assert.match(regraDe('bg-pub-ok'), /rgb\(\s*34\s+197\s+94\b/, '`#22C55E` em decimal');
        assert.match(regraDe('border-pub-danger'), /rgb\(\s*239\s+68\s+68\b/, '`#EF4444` em decimal');
        assert.match(regraDe('border-pub-txt-4'), /rgb\(\s*113\s+113\s+122\b/, '`#71717A` em decimal');

        // ⚠️⚠️ A tinta do quadrado com alerta sai como hex de 8 DÍGITOS, e o BYTE
        // DO ALFA é o que prova a porcentagem: 0.3 × 255 = 76,5 → `4d`.
        const comAlerta = regraDe('bg-pub-danger/[0.3]');
        assert.notEqual(comAlerta, null, '`bg-pub-danger/[0.3]` não compilou');
        assert.match(comAlerta, /background-color:\s*#ef44444d/i, `o alfa de 30% não bate: ${comAlerta}`);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 10. TERRITÓRIO — por ESCOPO DO ASSUNTO, não por range aberto
//
// O que precisaria estar errado para falhar: esta wave tocar um arquivo que não
// é dela. ⚠️ O range SOZINHO não basta — as TRÊS sessões commitam nesta árvore
// com o MESMO `user.name`, e a partida foi REMEDIDA depois dos 8 commits da
// outra máquina. O discriminador é o ESCOPO, com isca nas duas direções.
// ═══════════════════════════════════════════════════════════════════════════

test('este plano não saiu do território declarado 187-07', () => {
    const git = (args) => execFileSync('git', args, { cwd: RAIZ, encoding: 'utf8' }).trim();

    const log = git(['log', '--format=%h:%s', `${PARTIDA}..HEAD`]);

    // ⚠️ ISCA DO RECORTE: o escopo SABE discriminar. Se casasse tudo, o gate
    // mediria a árvore inteira e acusaria as outras máquinas — já aconteceu em
    // 15/10/2026 e deixou a suíte com 1 falha herdada.
    assert.ok('feat(187-07): x'.includes(ESCOPO), 'o escopo não casa o próprio assunto');
    assert.equal('feat(187-06): x'.includes(ESCOPO), false, 'o escopo casa a wave 6 — ele está largo');
    assert.equal('feat(187-08): x'.includes(ESCOPO), false, 'o escopo casa a wave 8 — ele está largo');
    assert.equal('fix(261016-mwe): x'.includes(ESCOPO), false, 'o escopo casa a outra máquina — ele está largo');

    const meus = log.split('\n')
        .filter((l) => l !== '')
        .filter((l) => l.slice(l.indexOf(':') + 1).includes(ESCOPO))
        .map((l) => l.slice(0, l.indexOf(':')));

    if (meus.length === 0) {
        // Primeira rodada: sem commit no escopo não há o que medir — e dizer
        // "intocado" aqui seria gate VAZIO POR CONSTRUÇÃO.
        assert.ok(true, 'sem commit no escopo ainda');
        return;
    }

    const arquivos = git(['show', '--name-only', '--format=', ...meus])
        .split('\n').map((a) => a.trim()).filter((a) => a !== '');

    const PROIBIDOS = [
        // Os 10 da Fase 186 em `Aprovacao/` (fora de `Fila/`) e os das waves 2/5/6.
        'app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php',
        'app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php',
        'app/Services/Publicador/Aprovacao/VocabularioDoPortao.php',
        'app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php',
        'app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php',
        'app/Services/Publicador/Aprovacao/RevisaoDeImagemService.php',
        'app/Services/Publicador/Aprovacao/Fila/FilaDeAprovacaoService.php',
        'app/Services/Publicador/Aprovacao/Fila/ProximoARevisarService.php',
        'app/Services/Publicador/Aprovacao/Fila/FiltrosDaFilaService.php',
        // Os componentes já NO AR.
        `${DIR_APROV}/vocabularioDoPortao.jsx`,
        `${DIR_APROV}/andamentoDoPortao.jsx`,
        `${DIR_APROV}/SeloDeAndamentoInterno.jsx`,
        `${DIR_APROV}/CabecalhoDaFila.jsx`,
        `${DIR_APROV}/FaixaDeAndamento.jsx`,
        `${DIR_APROV}/ProximoARevisar.jsx`,
        `${DIR_APROV}/FiltrosDaFila.jsx`,
        `${DIR_APROV}/PopoverDeFiltro.jsx`,
        'resources/js/Components/Mlb/Publicador/publicacoes',
        'resources/js/Components/Mlb/Publicador/identidade',
        'resources/js/Components/Publicador/Mesa',
        'resources/js/Layouts/AppLayout.jsx',
        'resources/js/Pages/Mlb/Publicador/Editor.jsx',
        // Creative Engine, acervo, MCP, Support e os de outras fases.
        'app/Services/Creative',
        'app/Services/Publicador/Criativos',
        'app/Services/Publicador/ImagemAssetService.php',
        'app/Support/Publicador',
        'app/Mcp',
        'app/Services/Portal',
        // Os gates INTOCÁVEIS e os de outras waves.
        'tests/js/publicador-header-zindex-ordem.test.js',
        'tests/js/estrutura-meus-anuncios.test.js',
        'tests/js/publicador-fila-filtros-187.test.js',
        'tests/js/publicador-fila-proximo-187.test.js',
        'tests/js/publicador-fila-tokens-187.test.js',
        'tests/js/publicador-fila-zindex-187.test.js',
        'tests/js/publicador-fila-cabecalho-187.test.js',
        // Dependências (T-187-SC), migrations (esta fase NÃO tem) e a camada de tokens.
        'package.json', 'package-lock.json', 'composer.json', 'composer.lock',
        'database/migrations', 'tailwind.config.js',
        // Os artefatos de planejamento que este plano não move.
        '.planning/ROADMAP.md', '.planning/STATE.md',
    ];

    // ⚠️ `test -e` em CADA caminho: caminho fantasma "provaria" território
    // intocado medindo NADA — já aconteceu seis vezes nesta milestone.
    for (const caminho of PROIBIDOS) {
        assert.ok(fs.existsSync(path.resolve(RAIZ, caminho)), `caminho inexistente: ${caminho} — o gate mediria NADA`);
    }

    // Prefixo de SEGMENTO, nunca `includes`: `app/Mcp` não pode casar
    // `app/McpOutraCoisa.php`.
    const tocados = arquivos.filter((a) => PROIBIDOS.some((p) => a === p || a.startsWith(`${p}/`)));
    assert.deepEqual(tocados, [], 'este plano saiu do território declarado');

    // ⚠️ ISCA DO PREFIXO: a comparação SABE casar, e NÃO casa o irmão.
    assert.ok('app/Mcp/X.php'.startsWith('app/Mcp/'));
    assert.ok(!'app/McpOutraCoisa.php'.startsWith('app/Mcp/'));

    // ⚠️⚠️ E a prova de que o range NÃO está vazio por construção: os commits do
    // escopo TOCARAM os arquivos deste plano.
    assert.ok(arquivos.length > 0, 'o range do escopo não devolveu arquivo nenhum — o gate está vazio');
    assert.ok(
        arquivos.some((a) => a.startsWith(`${DIR_APROV}/TabelaDaFila`)
            || a.startsWith(`${DIR_APROV}/LinhaDeProduto`)
            || a.startsWith(`${DIR_APROV}/SubLinhaDeConjunto`)
            || a.startsWith(`${DIR_APROV}/QuadradosDeFoto`)
            || a.includes('Fila/Galeria')
            || a.includes('Fila/Grupo')),
        `os commits do escopo não tocaram nenhum arquivo deste plano: ${JSON.stringify(arquivos.slice(0, 8))}`,
    );
});
