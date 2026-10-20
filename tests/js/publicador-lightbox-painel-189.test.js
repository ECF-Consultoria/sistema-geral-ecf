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
// Fase 189, plano 06 — O FIO PAINEL ↔ LIGHTBOX: o "⤢ Ampliar" do painel e a
// INVERSÃO do gate de ausência do 188-08 (tarefa 1); o estado, o CONTEXTO
// PRÓPRIO do lightbox, o degrau do `Escape`, ←/→, ↑/↓ e a devolução do foco
// (tarefa 2).
//
// ⚠️⚠️ DE CADA BLOCO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR: "o que
// precisaria estar errado no mundo para ele falhar?" — **"Nada" é ACHADO.**
//
//  1. CONJUNTO    → `includes`/`substring` casa PREFIXO e já mordeu CINCO vezes
//                   neste projeto (`w-[560px]` × `max-w-[560px]`; `h-[64px]` ×
//                   `min-h-[64px]`; `border` × `border-l`). As classes do
//                   "⤢ Ampliar" são comparadas por **igualdade de CONJUNTO**,
//                   com isca do quase-igual.
//  2. ÁRVORE      → `els[0]` mede o IRMÃO ERRADO — QUATRO gates deste projeto
//                   caíram nisso. O botão e o aviso de regeneração são lidos por
//                   **árvore com ÍNDICE ABSOLUTO**, e é assim que a ORDEM dentro
//                   do wrapper de `:604` é provada.
//  3. GUARDA      → desenhar o botão sem handler (D4: botão sem destino não
//                   nasce; `onClick` vazio é a mesma dívida com outra roupa, e
//                   *"desabilitado com explicação"* foi OFERECIDO e RECUSADO).
//                   A asserção cobra a AUSÊNCIA sem handler **e** a PRESENÇA com
//                   handler função — as duas direções.
//  4. REGEN       → condicionar o botão a `regenerating`. No protótipo só o
//                   "Gerar novamente" é condicionado (`:1319`: `P.onZoom` exige
//                   apenas `rv.sel`), então o "⤢ Ampliar" nasce **também** em
//                   `regenerating`, e aí os DOIS dividem o wrapper de `:604`.
//  5. ORDEM       → o botão antes do "Gerando nova versão…". A sequência do
//                   protótipo é `:605` → `:607`, provada por índice absoluto.
//  6. ESCAPE      → tirar o degrau do lightbox e o `Escape` fechar o PAINEL com
//                   o lightbox aberto (a pessoa perde a revisão inteira num
//                   toque). A cadeia `modal → lightbox → painel` é provada nas
//                   TRÊS camadas, uma asserção por camada.
//  7. VOLTA       → unificar ←/→ do lightbox com as do painel. **São regras
//                   DIFERENTES de propósito:** o lightbox DÁ A VOLTA (`:1321`,
//                   `(lb.i + d + n) % n`) e o painel CLAMPA (`:1018` + `:1293`).
//                   O gate cobra a volta nas duas pontas E que o índice do
//                   PAINEL não se mexa com o lightbox aberto.
//  8. FILA        → ↑/↓ ficarem inertes com o lightbox aberto. **Medido em
//                   `:1017`: o protótipo NÃO testa `!S.lb` ali** — a fila
//                   continua navegando por baixo (PERGUNTA-189-B (b), decisão do
//                   usuário de 19/10/2026).
//  9. CONTEXTO    → o lightbox ler a oferta/versão do PAINEL por baixo depois de
//                   um ↑/↓ (T-189-42: escrita na VERSÃO ERRADA). `contextoDoLightbox`
//                   refresca **só** na MESMA `oferta_id`, com comparação ESTRITA,
//                   e a fixture DISCRIMINA (A v3 com 4 imagens × B v7 com 2).
// 10. FOCO        → fechar e deixar o foco no `<body>` (a pessoa perde o lugar e
//                   o `Escape` seguinte fecha o painel). `ref` nula ou sem
//                   `focus` **não lança**.
// 11. ADVERSO     → tecla/índice/`painel` chegando objeto, nulo, ausente, `NaN`
//                   ou array, a prop inteira nula, **e NO CLIQUE**. A TELA PRETA
//                   voltou SEIS vezes nesta milestone, a última **no clique**:
//                   `String(valor)`, `hasOwnProperty` e qualquer `obj[chave]`
//                   **LANÇAM** com `{toString: null}`.
// 12. TERRITÓRIO  → a ausência de "✦ Gerar novamente com IA" afrouxada junto com
//                   a inversão do "⤢ Ampliar" (T-189-40). O gate prova que a
//                   sonda da Fase 192 **continua acusando**, com isca sintética.
// 13. ARMADILHA   → o literal `⤢ Ampliar` **no código** de `PainelDeRevisao.jsx`:
//                   `publicador-painel-casca-188.test.js:1035` varre a FONTE dele
//                   SEM COMENTÁRIO por `/Ampliar/`. ⚠️ Uma prop chamada
//                   `onAmpliar` ali REPROVARIA. Este gate cobra que o nome usado
//                   lá é OUTRO, e que o literal não vazou.
// 14. PÁGINA      → `String(` ou `localStorage` na página (gates da 187-04 e da
//                   188-07, os dois CERTOS), e a prop `lightbox` chegando como
//                   NÓ em vez de DADO (o contrato de UM significado por prop).
// 15. MANIFESTO   → `vocabulario-tamanhos` fora de LINHA PRÓPRIA ou sem a forma
//                   `>=`; e a contagem alheia de `contagens-188-08.txt` COPIADA
//                   em vez de conferida igual.
// 16. FANTASMA    → caminho citado que não existe no disco: sonda de AUSÊNCIA
//                   sobre arquivo inexistente passa CALADA. São DEZ fantasmas
//                   nesta milestone, TRÊS vindos de briefing.
//
// ⚠️⚠️ O QUE ESTE ARQUIVO **NÃO PROVA**: não há navegador nesta máquina, o
// `mysqld` local está PARADO, o banco não tem empresa com `ml_token` (a rota dá
// **404**), `pub_aprovacao_ofertas` está **VAZIA** e a fila só ganha oferta na
// Fase 194. *"A tela mostra X"* aqui significa SEMPRE *"o HTML gerado contém X"*.
// ⚠️ O `addEventListener('keydown')` **não é provado** (não há navegador): o que
// está provado é a FUNÇÃO PURA que decide o que cada tecla faz. O clique é
// exercido chamando o HANDLER EXPORTADO — `renderToStaticMarkup` **não dispara
// evento nenhum**. ⚠️ E `ref.current.focus()` é provado com um STUB que conta
// chamadas, nunca com foco de verdade.
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
        `.pub-lbpainel189-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';
const REL_IMAGEM = `${DIR_APROV}/ImagemGrandeDoPainel.jsx`;
const REL_COLUNA = `${DIR_APROV}/ColunaDeFotos.jsx`;
const REL_PAINEL = `${DIR_APROV}/PainelDeRevisao.jsx`;
const REL_LIGHTBOX = `${DIR_APROV}/LightboxDaImagem.jsx`;
const REL_LATERAL = `${DIR_APROV}/LateralDoLightbox.jsx`;
const REL_PAGINA = 'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx';
const REL_GATE_FOTOS = 'tests/js/publicador-painel-fotos-188.test.js';
const REL_GATE_CASCA = 'tests/js/publicador-painel-casca-188.test.js';
const REL_MANIFESTO = 'tests/gates/contagens-189-06.txt';
const REL_MANIFESTO_188_08 = 'tests/gates/contagens-188-08.txt';
const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

// ⚠️⚠️ ANTI-FANTASMA — `test -e` em CADA caminho citado. São DEZ caminhos
// fantasmas nesta milestone, TRÊS deles vindos de briefing. Sonda de AUSÊNCIA
// sobre arquivo inexistente passa CALADA.
// ⚠️ O protótipo fica FORA desta lista de propósito: ele **não é versionado**
// (D-189-2) e o bloco que depende dele degrada para `skip` EXPLÍCITO e NOMEADO.
const CAMINHOS_CITADOS = [
    REL_IMAGEM, REL_COLUNA, REL_PAINEL, REL_LIGHTBOX, REL_LATERAL, REL_PAGINA,
    REL_GATE_FOTOS, REL_GATE_CASCA, REL_MANIFESTO, REL_MANIFESTO_188_08,
];

/** ⚠️ A ISCA do anti-fantasma: tem de continuar AUSENTE (`189-REGUA.md` §9 (c)). */
const ISCA_AUSENTE = 'resources/js/Pages/Mlb/NaoExiste9z7.jsx';

// ⚠️ As fontes SEM comentário: a prosa em pt-BR deste projeto cita os próprios
// identificadores e os próprios literais, e **palavra no docblock reprova pelo
// próprio conserto** (TRÊS mutações da wave 187-04 abortaram por isso).
const FONTE_IMAGEM = lerSemComentarios(REL_IMAGEM);
const FONTE_COLUNA = lerSemComentarios(REL_COLUNA);
const FONTE_PAINEL = lerSemComentarios(REL_PAINEL);
const FONTE_PAGINA = lerSemComentarios(REL_PAGINA);

/** ⚠️ O CRU, para as iscas que provam que a leitura SEM comentário não é cega. */
const CRU_PAINEL = fs.readFileSync(path.resolve(RAIZ, REL_PAINEL), 'utf8');

const FONTE_GATE_FOTOS = lerSemComentarios(REL_GATE_FOTOS);
const FONTE_GATE_CASCA = lerSemComentarios(REL_GATE_CASCA);
const TEXTO_MANIFESTO = fs.readFileSync(path.resolve(RAIZ, REL_MANIFESTO), 'utf8');

const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

// ⚠️⚠️ TODOS os bundles ANTES do primeiro `test()` — o `after()` do `node --test`
// já matou um arquivo inteiro **sem nenhum teste falhar**.
const IMAGEM = await montar(REL_IMAGEM);
const COLUNA = await montar(REL_COLUNA);
const PAINEL = await montar(REL_PAINEL);
const LIGHTBOX = await montar(REL_LIGHTBOX);

// ═══ OS LEITORES DE HTML ═══════════════════════════════════════════════════

/**
 * A árvore de elementos do HTML, com PROFUNDIDADE e ÍNDICE ABSOLUTO.
 *
 * ⚠️⚠️ **NUNCA `indexOf(tagDeAbertura)`**: ele pega o IRMÃO ERRADO quando dois
 * elementos têm a MESMA string de classes — **quatro gates deste projeto
 * estavam medindo o irmão errado**.
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
                estilo: (m[3].match(/style="([^"]*)"/) || [])[1] ?? null,
            });
            if (!autofechada) profundidade += 1;
        }
        m = re.exec(html);
    }
    return itens;
}

/** As classes de um elemento como CONJUNTO — igualdade, nunca `includes`. */
function classesDe(elemento) {
    return new Set(elemento.classe.split(/\s+/).filter((c) => c !== ''));
}

const conjunto = (texto_) => new Set(texto_.split(/\s+/).filter((c) => c !== ''));

/** O conteúdo de texto, sem tags — para ler o que a tela DIZ. */
function texto(html) {
    return html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
}

const nada = () => {};

// ═══ AS FIXTURAS ═══════════════════════════════════════════════════════════

const FOTO_PENDENTE = {
    id: 41,
    imagem_id: 910,
    posicao: 1,
    posicao_rotulo: '#1',
    rotulo: 'Capa',
    titulo: 'Capa do anúncio',
    de_ia: true,
    tipo_rotulo: 'Gerada por IA',
    principal: true,
    estado: 'pending',
    estado_rotulo: 'A conferir',
    estado_cor: 'ambar',
    estado_dot: 'ambar',
    com_alerta: false,
    aprovada: false,
    aceita_acao: true,
    url_arquivo: '/mlb/publicador/fotos/910/arquivo',
    marcadores: [],
    verificacoes: [],
};

const FOTO_REGENERANDO = {
    ...FOTO_PENDENTE,
    id: 42,
    imagem_id: 911,
    posicao_rotulo: '#2',
    estado: 'regenerating',
    estado_rotulo: 'Gerando nova versão',
    estado_cor: 'neutra',
    estado_dot: 'neutra',
    aceita_acao: false,
};

const FOTO_APROVADA = {
    ...FOTO_PENDENTE,
    id: 43,
    imagem_id: 912,
    posicao_rotulo: '#3',
    estado: 'approved',
    estado_rotulo: 'Aprovada',
    estado_cor: 'verde',
    estado_dot: 'verde',
    aprovada: true,
    aceita_acao: false,
};

const FOTOS = {
    imagens: [FOTO_PENDENTE, FOTO_REGENERANDO, FOTO_APROVADA],
    progresso: '1 de 3 aprovadas',
    progresso_cor: 'ambar',
    nota_de_conjunto: 'Três fotos no conjunto.',
    com_alerta_restantes: 0,
    sem_alerta: null,
};

// ⚠️⚠️ A FIXTURA QUE **DISCRIMINA** a PERGUNTA-189-B: duas ofertas com VERSÃO e
// QUANTIDADE DE IMAGENS diferentes. Sem a diferença nos DOIS campos, um contexto
// que lesse o painel por baixo passaria por coincidência.
const PAINEL_A = {
    oferta_id: 501,
    versao: 3,
    fotos: { imagens: [FOTO_PENDENTE, FOTO_REGENERANDO, FOTO_APROVADA, { ...FOTO_PENDENTE, id: 44 }] },
    rodape: { botoes: { salvar: true } },
    navegacao: { anterior_id: null, proxima_id: 502 },
};

const PAINEL_B = {
    oferta_id: 502,
    versao: 7,
    fotos: { imagens: [{ ...FOTO_PENDENTE, id: 71 }, { ...FOTO_APROVADA, id: 72 }] },
    rodape: { botoes: { salvar: false } },
    navegacao: { anterior_id: 501, proxima_id: null },
};

const LIGHTBOX_A = { originais: { 41: { disponivel: true, url_arquivo: '/x/1', quantas: 1 } } };
const LIGHTBOX_B = { originais: { 71: { disponivel: false, url_arquivo: null } } };

/** Os valores que já derrubaram a página seis vezes nesta milestone. */
const ADVERSOS = [
    null, undefined, 0, -1, NaN, Infinity, '', '  ', 'texto', true, false,
    [], [1, 2], {}, { toString: null }, { current: null }, () => {},
];

// ═══════════════════════════════════════════════════════════════════════════
// 1. O "⤢ Ampliar" — A GEOMETRIA, A GUARDA E A ORDEM (tarefa 1)
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ D2 — o "⤢ Ampliar" por IGUALDADE DE CONJUNTO, com isca do quase-igual 189_06', () => {
    // ⚠️ O literal byte a byte: o `⤢` é **U+2922** (NORTH EAST AND SOUTH WEST
    // ARROW), medido por `codePointAt` nesta wave — não é um `↗` nem um `⇱`.
    assert.equal(IMAGEM.AMPLIAR, '⤢ Ampliar', 'o literal de `:607` divergiu');
    assert.equal(IMAGEM.AMPLIAR.codePointAt(0), 0x2922, 'o glifo não é U+2922');

    const html = render(IMAGEM.default, { imagem: FOTO_PENDENTE, indice: 0, onAmpliar: nada });
    const els = arvore(html);
    const botoes = els.filter((el) => el.tag === 'button');

    assert.equal(botoes.length, 1, `a imagem grande desenhou ${botoes.length} botões, esperava 1`);

    // ⚠️⚠️ IGUALDADE DE CONJUNTO, nunca `includes`: `includes` casa PREFIXO e
    // SUFIXO de outro nome, e já mordeu CINCO vezes neste projeto.
    assert.deepEqual(
        [...classesDe(botoes[0])].sort(),
        [...conjunto(
            'h-[30px] cursor-pointer whitespace-nowrap rounded-[8px] border-none '
            + 'bg-pub-page/[0.85] px-[11px] text-[12px] font-medium text-pub-txt',
        )].sort(),
        'as classes do "⤢ Ampliar" divergiram da `189-FID01.md` §D2',
    );

    // ⚠️ `white-space:nowrap` é a PRIMEIRA propriedade de `:607` e `cursor:pointer`
    // a ÚLTIMA — as DUAS mais omitidas desta milestone, e por isso a FID-01 as põe
    // em COLUNA PRÓPRIA.
    assert.ok(classesDe(botoes[0]).has('whitespace-nowrap'), '`white-space:nowrap` de `:607` foi OMITIDO');
    assert.ok(classesDe(botoes[0]).has('cursor-pointer'), '`cursor:pointer` de `:607` foi OMITIDO');

    // ⚠️ ISCA DO QUASE-IGUAL, nas DUAS direções: o conjunto NÃO aceita o vizinho
    // de prefixo, e a sonda enxerga quando ele entra.
    assert.notDeepEqual([...conjunto('h-[30px] min-h-[30px]')].sort(), [...conjunto('h-[30px]')].sort(), 'a sonda de conjunto está CEGA ao quase-igual');
    assert.ok(!classesDe(botoes[0]).has('min-h-[30px]'), '`min-h-[30px]` entrou no lugar de `h-[30px]`');
    assert.ok(!classesDe(botoes[0]).has('text-sm'), 'FID-03: `text-sm` em vez de `text-[12px]`');

    // ⚠️ `type="button"` — sem ele, dentro de um `<form>` o botão SUBMETE.
    assert.match(botoes[0].atributos, /type="button"/, 'o "⤢ Ampliar" saiu sem `type="button"`');
    assert.match(html, /⤢ Ampliar/, 'o texto do botão não saiu no HTML');
});

test('⚠️⚠️ D1/D4 — o wrapper de `:604` e a GUARDA DE FUNÇÃO nas duas direções 189_06', () => {
    // ⚠️ O wrapper é o named export que o 188-08 já tinha (INV-05/A5): o 189-06
    // AFROUXA A CONDIÇÃO dele, **não o recria**.
    assert.equal(
        IMAGEM.CLASSES_OVERLAY,
        'absolute bottom-[10px] right-[10px] flex gap-[6px]',
        'o wrapper de `:604` (named export do 188-08) divergiu',
    );

    // ➡️ COM handler função: o wrapper nasce E o botão nasce.
    const com = render(IMAGEM.default, { imagem: FOTO_PENDENTE, indice: 0, onAmpliar: nada });
    const overlayCom = arvore(com).filter((el) => el.classe === IMAGEM.CLASSES_OVERLAY);
    assert.equal(overlayCom.length, 1, 'o wrapper de `:604` não nasceu com o botão');
    assert.match(com, /⤢ Ampliar/, 'o botão não nasceu com handler FUNÇÃO');

    // ➡️ ⚠️⚠️ SEM handler (e com handler que NÃO É FUNÇÃO): nem botão, nem CASCA
    // VAZIA no lugar. Wrapper `flex gap-[6px]` vazio é D4 violado — e passa em
    // render enquanto DESAPARECE na tela.
    for (const handler of [null, undefined, 0, '', 'nada', [], {}, { toString: null }, NaN, true]) {
        const sem = render(IMAGEM.default, { imagem: FOTO_PENDENTE, indice: 0, onAmpliar: handler });
        assert.doesNotMatch(sem, /⤢/, `o botão nasceu com handler que não é função (typeof ${typeof handler})`);
        assert.equal(
            arvore(sem).filter((el) => el.classe === IMAGEM.CLASSES_OVERLAY).length,
            0,
            `casca VAZIA de ':604' com handler que não é função (typeof ${typeof handler})`,
        );
    }

    // ⚠️ E o `onClick` vazio é a mesma dívida com outra roupa — sonda na FONTE,
    // lida SEM comentário, com isca nas duas direções.
    assert.doesNotMatch(FONTE_IMAGEM, /onClick=\{\(\)\s*=>\s*\{\s*\}\}/, '`onClick` vazio — botão sem destino com outra roupa (D4)');
    assert.match('onClick={() => {}}', /onClick=\{\(\)\s*=>\s*\{\s*\}\}/, 'a sonda de `onClick` vazio está CEGA');
    assert.doesNotMatch('onClick={ampliar}', /onClick=\{\(\)\s*=>\s*\{\s*\}\}/, 'a sonda de `onClick` vazio acusa handler de VERDADE');
});

test('⚠️⚠️ O botão nasce nos TRÊS estados, e a ORDEM dentro de `:604` é `:605` → `:607` 189_06', () => {
    // ⚠️⚠️ **`regenerating` TAMBÉM tem o botão.** Medido em `:1319`: `P.onZoom` só
    // exige `rv.sel` — só o "Gerar novamente" é condicionado (`:1319`/`:606`).
    // Condicioná-lo tiraria da pessoa justamente a ampliação da foto que está
    // sendo refeita.
    for (const [nome, foto] of [['pending', FOTO_PENDENTE], ['regenerating', FOTO_REGENERANDO], ['approved', FOTO_APROVADA]]) {
        const html = render(IMAGEM.default, { imagem: foto, indice: 0, onAmpliar: nada });
        assert.match(html, /⤢ Ampliar/, `o "⤢ Ampliar" não nasceu em \`${nome}\``);
    }

    // ⚠️⚠️ A ORDEM, por ÁRVORE COM ÍNDICE ABSOLUTO — nunca por `indexOf` de
    // classes, que pega o IRMÃO ERRADO quando duas classes coincidem.
    const html = render(IMAGEM.default, { imagem: FOTO_REGENERANDO, indice: 1, onAmpliar: nada });
    const els = arvore(html);
    const overlay = els.filter((el) => el.classe === IMAGEM.CLASSES_OVERLAY);
    assert.equal(overlay.length, 1, 'o wrapper de `:604` não é único em `regenerating` com o botão');

    const filhos = els.filter((el) => el.profundidade === overlay[0].profundidade + 1 && el.indice > overlay[0].indice);
    const primeiros = filhos.slice(0, 2);
    assert.equal(primeiros.length, 2, 'o wrapper de `:604` não tem os DOIS filhos em `regenerating`');
    assert.equal(primeiros[0].classe, IMAGEM.CLASSES_REGENERANDO, '`:605` "Gerando nova versão…" não é o PRIMEIRO filho de `:604`');
    assert.equal(primeiros[1].tag, 'button', 'o "⤢ Ampliar" (`:607`) não é o SEGUNDO filho de `:604`');
    assert.ok(primeiros[1].indice > primeiros[0].indice, 'a ordem `:605` → `:607` do protótipo foi invertida');

    // ⚠️ ISCA: fora de `regenerating` o aviso de `:605` NÃO nasce, e aí o botão é
    // filho único. Sem esta asserção o bloco acima passaria com o aviso sempre
    // ligado.
    const pendente = render(IMAGEM.default, { imagem: FOTO_PENDENTE, indice: 0, onAmpliar: nada });
    assert.doesNotMatch(pendente, /Gerando nova versão/, 'o aviso de `:605` saiu num estado que NÃO é `regenerating`');
    assert.match(pendente, /⤢ Ampliar/, 'o botão desapareceu fora de `regenerating`');
});

test('⚠️⚠️ `aoClicarEmAmpliar` — índice ADVERSO não abre a foto errada e NUNCA lança 189_06', () => {
    // ⚠️⚠️ **O LADO SEGURO É NÃO ABRIR.** Clampar para `0` abriria a FOTO 1
    // quando a pessoa clicou na foto 4, e no lightbox ela APROVA ou REPROVA —
    // aprovar ENFILEIRA e **não há desfazer**. Divergência DECLARADA do
    // *"índice clampado"* da prosa do plano, que já oferecia *"ou nada"*.
    const chamadas = [];
    const espia = (i) => chamadas.push(i);

    assert.equal(IMAGEM.aoClicarEmAmpliar(3, espia), true, 'índice válido não chamou o handler');
    assert.deepEqual(chamadas, [3], 'o handler não recebeu o índice que foi clicado');

    for (const adverso of [NaN, -1, -7, 1.5, '2', null, undefined, {}, [], { toString: null }, Infinity, true]) {
        const antes = chamadas.length;
        let devolveu;
        assert.doesNotThrow(() => { devolveu = IMAGEM.aoClicarEmAmpliar(adverso, espia); }, `LANÇOU com índice adverso (typeof ${typeof adverso})`);
        assert.equal(devolveu, false, `índice adverso abriu o lightbox (typeof ${typeof adverso})`);
        assert.equal(chamadas.length, antes, `índice adverso CHAMOU o handler (typeof ${typeof adverso})`);
    }

    // ⚠️ Sem handler função: nada acontece, e nada lança.
    for (const handler of ADVERSOS.filter((v) => typeof v !== 'function')) {
        assert.doesNotThrow(() => IMAGEM.aoClicarEmAmpliar(0, handler), `LANÇOU com handler adverso (typeof ${typeof handler})`);
        assert.equal(IMAGEM.aoClicarEmAmpliar(0, handler), false, `handler adverso devolveu true (typeof ${typeof handler})`);
    }

    // ⚠️ ISCA: o `0` é índice VÁLIDO (é a primeira foto), e não pode ser barrado
    // junto com o lixo — a guarda de id POSITIVO é de ID, não de ÍNDICE.
    chamadas.length = 0;
    assert.equal(IMAGEM.aoClicarEmAmpliar(0, espia), true, 'o índice 0 (a PRIMEIRA foto) foi barrado como se fosse lixo');
    assert.deepEqual(chamadas, [0], 'o índice 0 não chegou ao handler');
});

test('⚠️ A `ref` do "⤢ Ampliar" — só forma que o React aceita, e nada lança 189_06', () => {
    // ⚠️⚠️ `ref` com valor que o React NÃO aceita (número, string, array)
    // **LANÇA** no cliente; aqui a guarda devolve `undefined` e o botão nasce
    // igual. ⚠️ `renderToStaticMarkup` **não anexa ref**, então o que está
    // provado é a FUNÇÃO e o render não quebrar — declarado.
    const objeto = { current: null };
    const funcao = () => {};

    assert.equal(IMAGEM.refDoBotao(funcao), funcao, 'a `ref` função foi recusada');
    assert.equal(IMAGEM.refDoBotao(objeto), objeto, 'a `ref` objeto foi recusada');

    for (const adverso of [null, undefined, 0, 7, '', 'botao', NaN, [], [1], true, false]) {
        assert.doesNotThrow(() => IMAGEM.refDoBotao(adverso), `a guarda de ref LANÇOU (typeof ${typeof adverso})`);
        assert.equal(IMAGEM.refDoBotao(adverso), undefined, `ref adversa passou adiante (typeof ${typeof adverso})`);
    }

    for (const adverso of [...ADVERSOS, objeto]) {
        assert.doesNotThrow(
            () => render(IMAGEM.default, { imagem: FOTO_PENDENTE, indice: 0, onAmpliar: nada, refDoAmpliar: adverso }),
            `o render LANÇOU com \`refDoAmpliar\` adversa (typeof ${typeof adverso})`,
        );
    }

    // ⚠️ ISCA: com a ref boa o botão continua lá (a guarda não engoliu o botão).
    assert.match(
        render(IMAGEM.default, { imagem: FOTO_PENDENTE, indice: 0, onAmpliar: nada, refDoAmpliar: objeto }),
        /⤢ Ampliar/,
        'a guarda de `ref` engoliu o botão',
    );
});

test('⚠️⚠️ A COLUNA repassa `onAmpliar`/`refDoAmpliar` à imagem grande — e só com imagem 189_06', () => {
    const comum = { fotos: FOTOS, live: true, selecionada: 1, onSelecionar: nada, empresaId: 7, ofertaId: 12, versao: 3 };

    const com = render(COLUNA.default, { ...comum, onAmpliar: nada, refDoAmpliar: { current: null } });
    assert.match(com, /⤢ Ampliar/, 'a coluna não repassou `onAmpliar` à imagem grande');

    const sem = render(COLUNA.default, comum);
    assert.doesNotMatch(sem, /⤢/, 'a coluna desenhou o botão SEM `onAmpliar`');

    // ⚠️⚠️ GALERIA VAZIA: nem imagem grande, nem botão — o vazio HONESTO do
    // 188-08 continua, e um botão de ampliar sobre nada é D4 violado.
    const vazia = render(COLUNA.default, { ...comum, fotos: { ...FOTOS, imagens: [] }, onAmpliar: nada });
    assert.doesNotMatch(vazia, /⤢/, 'o botão nasceu com a galeria VAZIA');
    assert.ok(texto(vazia).length > 0, 'a coluna saiu muda com a galeria vazia — o vazio honesto desapareceu');

    // ⚠️⚠️ O ÍNDICE QUE CHEGA AO HANDLER É O DA FOTO SELECIONADA, não `0`. Sem
    // esta prova o lightbox abriria sempre na primeira foto, e a pessoa aprovaria
    // a foto errada (T-189-42 pela porta da frente).
    const pedidos = [];
    render(COLUNA.default, { ...comum, selecionada: 2, onAmpliar: (i) => pedidos.push(i) });
    // ⚠️ `renderToStaticMarkup` NÃO dispara `onClick` — o clique é exercido pelo
    // HANDLER EXPORTADO, com o índice que a coluna calculou.
    assert.deepEqual(pedidos, [], 'o render disparou o clique — a leitura do gate está errada');
    assert.equal(COLUNA.indiceSelecionado(2, 3), 2, 'a coluna deixou de clampar o índice para a faixa');
    IMAGEM.aoClicarEmAmpliar(COLUNA.indiceSelecionado(2, 3), (i) => pedidos.push(i));
    assert.deepEqual(pedidos, [2], 'o índice entregue ao handler não é o da foto SELECIONADA');
});

test('⚠️⚠️ T-189-40 — a inversão do 188-08 NÃO afrouxou a ausência de "Gerar novamente" 189_06', () => {
    const comum = { fotos: FOTOS, live: true, selecionada: 0, onSelecionar: nada, empresaId: 7, ofertaId: 12, versao: 3 };

    // ⚠️ A Fase 192 continua AUSENTE nos DOIS regimes — com e sem `onAmpliar`.
    for (const [nome, props] of [['sem handler', comum], ['com handler', { ...comum, onAmpliar: nada }]]) {
        const html = render(COLUNA.default, props);
        assert.ok(html.length > 1000, `a coluna saiu curta demais (${html.length} bytes) ${nome} — a sonda mediria zero casos`);
        assert.doesNotMatch(html, /Gerar novamente/, `"✦ Gerar novamente com IA" (Fase 192) apareceu ${nome}`);
    }

    // ⚠️⚠️ A ISCA SINTÉTICA: a sonda da Fase 192 **continua acusando**. Sem ela,
    // "nenhum achado" não significa nada.
    assert.match('<button>✦ Gerar novamente com IA</button>', /Gerar novamente/, 'a sonda de "Gerar novamente" está CEGA');
    assert.doesNotMatch('<button>⤢ Ampliar</button>', /Gerar novamente/, 'a sonda de "Gerar novamente" acusa o "⤢ Ampliar" — ela é mais larga que o invariante');

    // ⚠️ E na FONTE das duas peças desta tarefa, lida SEM comentário.
    for (const [rel, fonte] of [[REL_IMAGEM, FONTE_IMAGEM], [REL_COLUNA, FONTE_COLUNA]]) {
        assert.doesNotMatch(fonte, /Gerar novamente/, `${rel} escreveu "Gerar novamente" (Fase 192)`);
        assert.doesNotMatch(fonte, /Comparar lado a lado/, `${rel} escreveu "Comparar lado a lado" (190/191, FORA DE ESCOPO em 20/10/2026)`);
        assert.doesNotMatch(fonte, /Confirmar publica|Desfazer|Cancelar aprova/i, `${rel} escreveu vocabulário vetado das Fases 187-194`);
        assert.doesNotMatch(fonte, /localStorage|sessionStorage/, `${rel} usou armazenamento do navegador`);
        assert.doesNotMatch(fonte, /\bString\(/, `${rel} usou \`String(\` — ele LANÇA com {toString:null}`);
    }

    // ⚠️ ISCA das sondas acima, uma por uma (regex que não casa o próprio cebo
    // prova NADA).
    const CEBO = 'Gerar novamente com IA / Comparar lado a lado / Confirmar publicação / Desfazer / '
        + 'Cancelar aprovação / localStorage.getItem(1) / sessionStorage.clear() / String(x)';
    for (const re of [/Gerar novamente/, /Comparar lado a lado/, /Confirmar publica|Desfazer|Cancelar aprova/i, /localStorage|sessionStorage/, /\bString\(/]) {
        assert.match(CEBO, re, `a sonda ${re.source} está CEGA`);
    }
    assert.doesNotMatch('textoSeguro(x)', /\bString\(/, 'a sonda de `String(` casa nome de outra função — ela é mais larga que o invariante');
});

test('⚠️⚠️ O GATE DO 188-08 FOI REAPONTADO COM VETO, e a contagem alheia é CONFERIDA 189_06', () => {
    // ⚠️⚠️ *"Teste que para de provar algo é pior que teste ausente: cirurgia é
    // REAPONTE com veto, e CONTE TESTES, não linhas"* (`188-REGUA.md` §10).
    // ➡️ A ausência SEM handler continua lá, a PRESENÇA com handler entrou, e a
    // ausência de "Gerar novamente" **não foi tocada**.
    assert.match(FONTE_GATE_FOTOS, /doesNotMatch\(completo, \/Ampliar\//, 'a ausência SEM handler saiu do gate do 188-08');
    assert.match(FONTE_GATE_FOTOS, /doesNotMatch\(completo, \/⤢\//, 'a ausência do GLIFO sem handler saiu do gate do 188-08');
    assert.match(FONTE_GATE_FOTOS, /doesNotMatch\(completo, \/Gerar novamente\//, 'a ausência da Fase 192 saiu do gate do 188-08');
    assert.match(FONTE_GATE_FOTOS, /match\(comAmpliar, \/⤢ Ampliar\//, 'a EXIGÊNCIA de presença com handler não entrou no gate do 188-08');

    // ⚠️ Quem reapontou, quando e por quê — a próxima pessoa tem de achar por
    // `grep`, não por bisseção. ⚠️ Leitura CRUA: é o COMENTÁRIO o artefato.
    const cruFotos = fs.readFileSync(path.resolve(RAIZ, REL_GATE_FOTOS), 'utf8');
    assert.ok(cruFotos.includes('D-189-3b'), 'o reaponte do 188-08 não cita a decisão D-189-3b');
    assert.ok(cruFotos.includes('Fase 189, plano 06'), 'o reaponte do 188-08 não nomeia o plano DONO (o 06)');

    // ⚠️ A asserção POSITIVA do protótipo (`:607` é o "⤢ Ampliar") continua
    // INTOCADA: ela confere o PROTÓTIPO, não o código.
    assert.match(FONTE_GATE_FOTOS, /match\(em\(607\), \/⤢ Ampliar\//, 'a asserção positiva do protótipo (`:1361`) foi mexida');

    // ⚠️⚠️ A CONTAGEM ALHEIA é CONFERIDA IGUAL, nunca copiada de cabeça: o
    // `tests=` de `coluna-de-fotos-188` neste manifesto tem de ser **o mesmo
    // texto** que `contagens-188-08.txt` declara para `coluna-de-fotos`.
    const texto188 = fs.readFileSync(path.resolve(RAIZ, REL_MANIFESTO_188_08), 'utf8');
    const do188 = texto188.match(/^coluna-de-fotos\s+js\s+\S+\s+::\s+(tests=\S+)$/m);
    assert.notEqual(do188, null, 'não achei a linha de `coluna-de-fotos` no manifesto do 188-08 — a leitura está CEGA');
    const daqui = TEXTO_MANIFESTO.match(/^coluna-de-fotos-188\s+js\s+\S+\s+::\s+(tests=\S+)$/m);
    assert.notEqual(daqui, null, 'não achei a linha de `coluna-de-fotos-188` no manifesto deste plano');
    assert.equal(daqui[1], do188[1], 'a contagem do manifesto da 188 foi SUBIDA para caber — reaponte não acrescenta nem remove teste');
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. O TECLADO — O DEGRAU DO `Escape`, ←/→ COM VOLTA E ↑/↓ POR BAIXO (tarefa 2)
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ E12 — `Escape` na ordem modal → LIGHTBOX → painel, uma asserção por camada 189_06', () => {
    const { resolverAtalhoDoPainel: res, ACOES } = PAINEL;

    // ⚠️⚠️ As TRÊS camadas, e o degrau do meio é o que o 188-07 deixou "previsto
    // e declarado" (ACHADO A7 do inventário). Sem ele o `Escape` com o lightbox
    // aberto fecharia o PAINEL INTEIRO, e a pessoa perderia a revisão num toque.
    assert.equal(res({ tecla: 'Escape', temModal: true, temLightbox: true })?.acao, ACOES.FECHAR_MODAL, 'com modal E lightbox, o `Escape` não fechou o MODAL primeiro');
    assert.equal(res({ tecla: 'Escape', temModal: false, temLightbox: true })?.acao, ACOES.FECHAR_LIGHTBOX, 'com o lightbox aberto, o `Escape` não fechou o LIGHTBOX');
    assert.equal(res({ tecla: 'Escape', temModal: false, temLightbox: false })?.acao, ACOES.FECHAR_PAINEL, 'sem modal e sem lightbox, o `Escape` não fechou o PAINEL');
    assert.equal(res({ tecla: 'Escape', temModal: true, temLightbox: false })?.acao, ACOES.FECHAR_MODAL, 'com modal, o `Escape` não fechou o MODAL');

    // ⚠️ `temLightbox` é `true` ESTRITO: `'1'`, `1` e `{}` são **truthy** e
    // fechariam o lightbox sobre um estado que não é o dele.
    for (const quase of [1, '1', 'true', {}, [], 'sim']) {
        assert.equal(
            res({ tecla: 'Escape', temModal: false, temLightbox: quase })?.acao,
            ACOES.FECHAR_PAINEL,
            `\`temLightbox\` truthy não-booleano foi lido como aberto (typeof ${typeof quase})`,
        );
    }

    // ⚠️⚠️ O `Escape` dispara TAMBÉM dentro de campo de texto — medido em
    // `:1014`/`:1015`: o `if (typing) return` está DEPOIS do bloco do `Escape`.
    // O contrário TRANCARIA a pessoa dentro de um campo de modal.
    assert.equal(res({ tecla: 'Escape', alvoEhCampo: true, temLightbox: true })?.acao, ACOES.FECHAR_LIGHTBOX, 'o `Escape` deixou de agir dentro de campo de texto');

    // ⚠️ E o padrão de `temLightbox` é `false`: todos os casos do 188-07 chamam o
    // resolvedor SEM ele e continuam valendo.
    assert.equal(res({ tecla: 'Escape' })?.acao, ACOES.FECHAR_PAINEL, 'sem `temLightbox` o padrão deixou de ser `false`');
});

test('⚠️⚠️ E13 — ←/→ vão para o LIGHTBOX e DÃO A VOLTA; o índice do PAINEL não se mexe 189_06', () => {
    const { resolverAtalhoDoPainel: res, ACOES } = PAINEL;
    const base = { temModal: false, temLightbox: true, imagemDoLightbox: 0, totalDoLightbox: 4, imagemAtual: 2, totalDeImagens: 4 };

    // ⚠️⚠️ **DÁ A VOLTA** (`:1321`, `(lb.i + d + n) % n`) — e isso é DIFERENTE do
    // painel, que CLAMPA (`:1018` + `:1293`). **Não unifique as duas regras.**
    const esquerdaNaPrimeira = res({ ...base, tecla: 'ArrowLeft' });
    assert.equal(esquerdaNaPrimeira?.acao, ACOES.MOVER_LIGHTBOX, '← com o lightbox aberto não foi para o lightbox');
    assert.equal(esquerdaNaPrimeira?.imagem, 3, '← na PRIMEIRA foto não deu a volta para a ÚLTIMA');

    const direitaNaUltima = res({ ...base, tecla: 'ArrowRight', imagemDoLightbox: 3 });
    assert.equal(direitaNaUltima?.acao, ACOES.MOVER_LIGHTBOX, '→ com o lightbox aberto não foi para o lightbox');
    assert.equal(direitaNaUltima?.imagem, 0, '→ na ÚLTIMA foto não deu a volta para a PRIMEIRA');

    assert.equal(res({ ...base, tecla: 'ArrowRight', imagemDoLightbox: 1 })?.imagem, 2, '→ no meio não andou uma foto');

    // ⚠️⚠️ **O ÍNDICE DO PAINEL NÃO SE MEXE.** Com o lightbox aberto a ação é
    // `mover-lightbox`, nunca `trocar-imagem`: trocar a foto por baixo deixaria a
    // pessoa aprovando no lightbox uma foto e vendo outra no painel.
    for (const tecla of ['ArrowLeft', 'ArrowRight']) {
        assert.notEqual(res({ ...base, tecla })?.acao, ACOES.TROCAR_IMAGEM, `${tecla} mexeu no índice do PAINEL com o lightbox aberto`);
    }

    // ⚠️⚠️ E SEM o lightbox a regra ANTIGA continua inteira: CLAMPA nas DUAS
    // pontas e **não consome** a tecla na ponta.
    const semLb = { temModal: false, temLightbox: false, imagemAtual: 0, totalDeImagens: 4 };
    assert.equal(res({ ...semLb, tecla: 'ArrowLeft' }), null, '← na primeira foto do PAINEL deixou de CLAMPAR (ele não dá a volta)');
    assert.equal(res({ ...semLb, tecla: 'ArrowRight' })?.acao, ACOES.TROCAR_IMAGEM, '→ no painel deixou de trocar a imagem');
    assert.equal(res({ ...semLb, tecla: 'ArrowRight', imagemAtual: 3 }), null, '→ na última foto do PAINEL deixou de CLAMPAR');

    // ⚠️ A galeria do LIGHTBOX vazia → a tecla não é consumida (`preventDefault`
    // numa tecla que não faz nada tira a rolagem da pessoa sem motivo).
    for (const total of [0, -1, null, undefined, NaN, '4', {}, []]) {
        assert.equal(
            res({ ...base, tecla: 'ArrowRight', totalDoLightbox: total }),
            null,
            `← / → agiram com \`totalDoLightbox\` adverso (typeof ${typeof total})`,
        );
    }

    // ⚠️⚠️ A VOLTA É A FUNÇÃO DO 189-03, IMPORTADA — uma fonte só. Duas regras de
    // alternância divergiriam, e a divergente leva a pessoa à foto errada.
    assert.equal(LIGHTBOX.moverNoLightbox(0, -1, 4), 3, 'a função do 189-03 deixou de dar a volta');
    assert.equal(res({ ...base, tecla: 'ArrowLeft' })?.imagem, LIGHTBOX.moverNoLightbox(0, -1, 4), 'o resolvedor e `moverNoLightbox` divergiram — são DUAS regras de volta');
    assert.match(FONTE_PAINEL, /from '\.\/LightboxDaImagem'/, 'o painel deixou de IMPORTAR a regra da volta do 189-03');
});

test('⚠️⚠️ E14 — ↑/↓ CONTINUAM navegando a fila com o lightbox aberto (PERGUNTA-189-B (b)) 189_06', () => {
    const { resolverAtalhoDoPainel: res, ACOES } = PAINEL;
    const base = { temModal: false, anteriorId: 501, proximaId: 502, imagemDoLightbox: 0, totalDoLightbox: 4 };

    // ⚠️⚠️ **MEDIDO em `:1017`: o protótipo NÃO testa `!S.lb` ali.** A fila
    // continua navegando por baixo, e o lightbox guarda o PRÓPRIO contexto. É a
    // decisão (b) do usuário de 19/10/2026.
    for (const temLightbox of [true, false]) {
        assert.equal(res({ ...base, temLightbox, tecla: 'ArrowDown' })?.acao, ACOES.IR_PARA_OFERTA, `↓ ficou inerte com temLightbox=${temLightbox}`);
        assert.equal(res({ ...base, temLightbox, tecla: 'ArrowDown' })?.oferta, 502, `↓ não foi para \`proxima_id\` com temLightbox=${temLightbox}`);
        assert.equal(res({ ...base, temLightbox, tecla: 'ArrowUp' })?.oferta, 501, `↑ não foi para \`anterior_id\` com temLightbox=${temLightbox}`);
    }

    // ⚠️ Na ponta da fila (`null`) a tecla **não navega e não é consumida** — o
    // destino é LIDO do servidor, nunca recalculado no cliente.
    assert.equal(res({ ...base, temLightbox: true, tecla: 'ArrowUp', anteriorId: null }), null, '↑ na PRIMEIRA oferta inventou destino');
    assert.equal(res({ ...base, temLightbox: true, tecla: 'ArrowDown', proximaId: null }), null, '↓ na ÚLTIMA oferta inventou destino');

    // ⚠️ Em campo de texto NENHUMA seta age (`:1015`) — nem com o lightbox aberto.
    for (const tecla of ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight']) {
        assert.equal(res({ ...base, temLightbox: true, tecla, alvoEhCampo: true }), null, `${tecla} agiu dentro de campo de texto`);
    }

    // ⚠️ Com MODAL aberto as setas também não agem (`:1017`/`:1018` exigem
    // `!S.modal`): navegar a fila atrás de um modal trocaria a oferta que o
    // modal está confirmando.
    for (const tecla of ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight']) {
        assert.equal(res({ ...base, temLightbox: true, temModal: true, tecla }), null, `${tecla} agiu com MODAL aberto`);
    }
});

test('⚠️⚠️ T-189-43 — tecla, índice e `evento` ADVERSOS não derrubam a página 189_06', () => {
    const { resolverAtalhoDoPainel: res } = PAINEL;

    for (const adverso of ADVERSOS) {
        assert.doesNotThrow(() => res(adverso), `o resolvedor LANÇOU com evento adverso (typeof ${typeof adverso})`);
        assert.doesNotThrow(() => res({ tecla: adverso, temLightbox: true }), `o resolvedor LANÇOU com tecla adversa (typeof ${typeof adverso})`);
        assert.doesNotThrow(
            () => res({ tecla: 'ArrowRight', temLightbox: true, imagemDoLightbox: adverso, totalDoLightbox: adverso }),
            `o resolvedor LANÇOU com índice/total adversos (typeof ${typeof adverso})`,
        );
    }

    // ⚠️ Tecla que não é desta peça devolve `null` (sem `preventDefault`).
    for (const tecla of ['a', 'Enter', 'Tab', ' ', 'F5', 'ArrowUpLeft']) {
        assert.equal(res({ tecla, temLightbox: true, temModal: false }), null, `a tecla "${tecla}" foi consumida sem ser desta peça`);
    }

    // ⚠️ O resultado de um resolvedor adverso NUNCA leva `NaN`/`undefined` para a
    // tela: ou é `null`, ou tem `acao` string e `imagem`/`oferta` inteiros ou nulos.
    for (const adverso of ADVERSOS) {
        const r = res({ tecla: 'ArrowRight', temLightbox: true, imagemDoLightbox: adverso, totalDoLightbox: 4 });
        if (r === null) continue;
        assert.equal(typeof r.acao, 'string', 'o resolvedor devolveu `acao` que não é string');
        assert.ok(r.imagem === null || Number.isInteger(r.imagem), `o resolvedor devolveu índice ${String(r.imagem)} — ele iria para o estado`);
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. O CONTEXTO PRÓPRIO DO LIGHTBOX (PERGUNTA-189-B (b), T-189-42)
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ T-189-42 — abrir na A (v3) e ↓ para a B (v7): o contexto continua na A 189_06', () => {
    const { contextoDoLightbox } = PAINEL;

    // ⚠️ A ABERTURA captura o contexto do instante: `oferta_id`, `versao`,
    // `ao_vivo`, as `imagens` e as `originais`.
    const guardado = contextoDoLightbox(null, PAINEL_A, LIGHTBOX_A);
    assert.equal(guardado?.oferta_id, 501, 'a abertura não capturou a `oferta_id`');
    assert.equal(guardado?.versao, 3, 'a abertura não capturou a `versao`');
    assert.equal(guardado?.ao_vivo, true, 'a abertura não capturou o `ao_vivo` (lido de `rodape.botoes.salvar`)');
    assert.equal(guardado?.imagens.length, 4, 'a abertura não capturou as 4 imagens da oferta A');
    assert.equal(guardado?.originais?.[41]?.disponivel, true, 'a abertura não capturou as `originais`');

    // ⚠️⚠️ **O ↓ TROCA A OFERTA POR BAIXO E O CONTEXTO NÃO MUDA.** A fixtura
    // DISCRIMINA nos TRÊS campos: versão 3 × 7, 4 imagens × 2, `ao_vivo` true ×
    // false. Um contexto que lesse o painel por baixo escreveria na VERSÃO
    // ERRADA — e, pior, na FOTO de outra oferta.
    const depoisDoDown = contextoDoLightbox(guardado, PAINEL_B, LIGHTBOX_B);
    assert.equal(depoisDoDown.oferta_id, 501, 'o contexto do lightbox seguiu a oferta do PAINEL (T-189-42)');
    assert.equal(depoisDoDown.versao, 3, 'a VERSÃO do contexto virou a da oferta B — a escrita iria para a versão errada');
    assert.equal(depoisDoDown.imagens.length, 4, 'as IMAGENS do contexto viraram as da oferta B');
    assert.equal(depoisDoDown.ao_vivo, true, 'o `ao_vivo` do contexto virou o da oferta B');
    assert.deepEqual(depoisDoDown, guardado, 'o contexto GUARDADO não saiu intacto com OUTRA oferta no painel');

    // ⚠️⚠️ E na MESMA oferta ele REFRESCA — é assim que a foto aprovada aparece
    // aprovada depois da ação.
    const mesmaOfertaVersaoNova = {
        ...PAINEL_A,
        versao: 4,
        fotos: { imagens: [FOTO_APROVADA, FOTO_PENDENTE] },
        rodape: { botoes: { salvar: false } },
    };
    const refrescado = contextoDoLightbox(guardado, mesmaOfertaVersaoNova, LIGHTBOX_A);
    assert.equal(refrescado.oferta_id, 501, 'o refresco perdeu a `oferta_id`');
    assert.equal(refrescado.versao, 4, 'o refresco não trouxe a VERSÃO nova do payload');
    assert.equal(refrescado.imagens.length, 2, 'o refresco não trouxe as IMAGENS novas do payload');
    assert.equal(refrescado.ao_vivo, false, 'o refresco não trouxe o `ao_vivo` novo');

    // ⚠️⚠️ COMPARAÇÃO ESTRITA de `oferta_id`: `'501'` é OUTRA oferta, nunca "a
    // mesma". `==` leria a string como o inteiro e refrescaria com dado alheio.
    for (const quase of ['501', 501.0000001, true, null, undefined, NaN, {}, []]) {
        const r = contextoDoLightbox(guardado, { ...PAINEL_B, oferta_id: quase }, LIGHTBOX_B);
        if (quase === 501.0000001) {
            assert.equal(r.oferta_id, 501, 'id quase-igual foi lido como A MESMA oferta');
            continue;
        }
        assert.deepEqual(r, guardado, `\`oferta_id\` adversa foi lida como A MESMA oferta (typeof ${typeof quase})`);
    }
});

test('⚠️⚠️ O contexto CLAMPA o índice e FECHA com a galeria vazia (E3) 189_06', () => {
    const { contextoDoLightbox, indiceNoContexto } = PAINEL;
    const guardado = contextoDoLightbox(null, PAINEL_A, LIGHTBOX_A);

    // ⚠️ Payload novo da MESMA oferta com MENOS imagens → o índice é CLAMPADO.
    // Sem isso o lightbox apontaria para uma foto que não existe mais (reprovar
    // tira a foto do conjunto final).
    const menos = { ...PAINEL_A, fotos: { imagens: [FOTO_PENDENTE, FOTO_APROVADA] } };
    const depois = contextoDoLightbox(guardado, menos, LIGHTBOX_A);
    assert.equal(indiceNoContexto(3, depois), 1, 'o índice 3 não foi clampado para a última das 2 imagens');
    assert.equal(indiceNoContexto(0, depois), 0, 'o índice 0 foi mexido sem motivo');

    // ⚠️⚠️ ZERO imagens → `null`, e `null` é o sinal de FECHAR (`:1324`,
    // `if (i < 0) return { open: false }`).
    const zero = contextoDoLightbox(guardado, { ...PAINEL_A, fotos: { imagens: [] } }, LIGHTBOX_A);
    assert.equal(indiceNoContexto(0, zero), null, 'galeria VAZIA não devolveu `null` (o lightbox ficaria aberto sobre nada)');
    assert.equal(indiceNoContexto(0, null), null, 'contexto nulo não devolveu `null`');

    // ⚠️ Índice e contexto adversos: nunca lança, nunca `NaN`.
    for (const adverso of ADVERSOS) {
        assert.doesNotThrow(() => indiceNoContexto(adverso, depois), `o clamp LANÇOU com índice adverso (typeof ${typeof adverso})`);
        assert.doesNotThrow(() => indiceNoContexto(0, adverso), `o clamp LANÇOU com contexto adverso (typeof ${typeof adverso})`);
        const r = indiceNoContexto(adverso, depois);
        assert.ok(r === null || Number.isInteger(r), `o clamp devolveu ${String(r)} — ele iria para o estado`);
    }

    // ⚠️ ISCA: o clamp do CONTEXTO é o do 189-03, IMPORTADO — uma fonte só.
    assert.equal(indiceNoContexto(9, depois), LIGHTBOX.indiceNoLightbox(9, 2), 'o clamp do contexto e `indiceNoLightbox` divergiram');
});

test('⚠️⚠️ T-189-43/`contextoDoLightbox` — payload ADVERSO não derruba a página 189_06', () => {
    const { contextoDoLightbox } = PAINEL;

    for (const adverso of ADVERSOS) {
        assert.doesNotThrow(() => contextoDoLightbox(adverso, adverso, adverso), `o contexto LANÇOU com tudo adverso (typeof ${typeof adverso})`);
        assert.doesNotThrow(() => contextoDoLightbox(null, adverso, null), `o contexto LANÇOU com painel adverso (typeof ${typeof adverso})`);
        assert.doesNotThrow(
            () => contextoDoLightbox(null, { oferta_id: adverso, versao: adverso, fotos: adverso, rodape: adverso }, { originais: adverso }),
            `o contexto LANÇOU com campos adversos (typeof ${typeof adverso})`,
        );
    }

    // ⚠️ Painel sem oferta → **não abre** (`null`): um lightbox sem oferta não
    // tem o que aprovar, e um contexto com `oferta_id` nula escreveria em
    // `{oferta: null}`, uma rota que não existe.
    assert.equal(contextoDoLightbox(null, null, LIGHTBOX_A), null, 'painel nulo produziu contexto');
    assert.equal(contextoDoLightbox(null, { ...PAINEL_A, oferta_id: null }, LIGHTBOX_A), null, 'oferta nula produziu contexto');
    assert.equal(contextoDoLightbox(null, { ...PAINEL_A, oferta_id: 0 }, LIGHTBOX_A), null, '`oferta_id` 0 passou pela guarda de id POSITIVO');
    assert.equal(contextoDoLightbox(null, { ...PAINEL_A, versao: 0 }, LIGHTBOX_A), null, '`versao` 0 passou pela guarda de id POSITIVO');

    // ⚠️ `imagens` que não é lista → `[]`, nunca `.map` em string/número (LANÇA).
    const semLista = contextoDoLightbox(null, { ...PAINEL_A, fotos: { imagens: { 0: FOTO_PENDENTE } } }, LIGHTBOX_A);
    assert.deepEqual(semLista.imagens, [], '`imagens` objeto não caiu em lista vazia');

    // ⚠️ `originais` que não é objeto comum → `{}`.
    const semOriginais = contextoDoLightbox(null, PAINEL_A, { originais: [1, 2] });
    assert.deepEqual(semOriginais.originais, {}, '`originais` array não caiu em objeto vazio');

    // ⚠️ E o texto que sai do contexto nunca é `[object Object]`/`NaN`.
    for (const adverso of ADVERSOS) {
        const r = contextoDoLightbox(null, { ...PAINEL_A, oferta_id: 501, versao: 3, rodape: adverso }, LIGHTBOX_A);
        if (r === null) continue;
        assert.equal(typeof r.ao_vivo, 'boolean', '`ao_vivo` saiu do contexto sem ser booleano');
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. O FOCO AO FECHAR (critério 7, PERGUNTA-189-C e o DEFAULT da 189-D1)
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ Critério 7 — fechar devolve o foco ao botão que abriu, e `ref` nula NÃO lança 189_06', () => {
    const { fecharLightboxDevolvendoFoco } = PAINEL;

    const chamadas = [];
    const ref = { current: { focus: () => chamadas.push('focus') } };
    const fechou = [];

    assert.equal(fecharLightboxDevolvendoFoco({ ref, fechar: () => fechou.push(1) }), true, 'o fechamento com `ref` boa não devolveu `true`');
    assert.deepEqual(fechou, [1], 'o lightbox não foi fechado');
    assert.deepEqual(chamadas, ['focus'], 'o foco não voltou ao botão que abriu');

    // ⚠️⚠️ **FECHAR SEMPRE ACONTECE, mesmo sem foco para devolver.** Um
    // fechamento que dependesse da `ref` deixaria o lightbox preso na tela.
    for (const adversa of [null, undefined, {}, { current: null }, { current: {} }, { current: { focus: 'nao-e-funcao' } }, [], 0, 'ref', NaN]) {
        const fechados = [];
        assert.doesNotThrow(
            () => fecharLightboxDevolvendoFoco({ ref: adversa, fechar: () => fechados.push(1) }),
            `LANÇOU com \`ref\` adversa (typeof ${typeof adversa})`,
        );
        assert.deepEqual(fechados, [1], `o lightbox NÃO fechou com \`ref\` adversa (typeof ${typeof adversa})`);
    }

    // ⚠️ Pedido inteiro adverso: nada lança, e sem `fechar` função devolve `false`.
    for (const adverso of ADVERSOS) {
        assert.doesNotThrow(() => fecharLightboxDevolvendoFoco(adverso), `LANÇOU com pedido adverso (typeof ${typeof adverso})`);
    }
    assert.equal(fecharLightboxDevolvendoFoco({ ref, fechar: null }), false, 'sem `fechar` função devolveu `true`');
    assert.equal(fecharLightboxDevolvendoFoco(null), false, 'pedido nulo devolveu `true`');

    // ⚠️ ISCA: o stub de foco CONTA de verdade (sonda que não conta nada é vazia
    // por construção).
    assert.equal(chamadas.length, 1, 'o stub de `focus` não contou a chamada — a sonda está CEGA');
});

test('⚠️⚠️ 189-C e o DEFAULT da 189-D1 — a foto do painel e o destino do foco 189_06', () => {
    const { aoFecharOLightbox, contextoDoLightbox, FOCO_NO_BOTAO_DE_ABRIR } = PAINEL;
    const contexto = contextoDoLightbox(null, PAINEL_A, LIGHTBOX_A);

    // ➡️ **189-C (a oferta por baixo NÃO mudou):** o painel continua na foto de
    // onde se abriu, e o foco volta ao botão que abriu.
    const mesma = aoFecharOLightbox({ contexto, painelAtual: PAINEL_A });
    assert.equal(mesma.mudarFotoDoPainel, false, '189-C: fechar mexeu na foto selecionada do painel');
    assert.equal(mesma.focar, FOCO_NO_BOTAO_DE_ABRIR, '189-C: o foco não voltou ao botão que abriu');

    // ➡️ ⚠️⚠️ **189-D1 (a oferta MUDOU) — DEFAULT APLICADO, pergunta ABERTA:** o
    // painel FICA na oferta nova (a navegação foi ação explícita da pessoa), na
    // foto que B já tinha, e o foco vai ao botão do painel de B. A alternativa
    // (`router.get` de volta para a A) é LOCAL e **não** é o default.
    const outra = aoFecharOLightbox({ contexto, painelAtual: PAINEL_B });
    assert.equal(outra.mudarFotoDoPainel, false, '189-D1: fechar mexeu na foto que a oferta B já tinha');
    assert.equal(outra.focar, FOCO_NO_BOTAO_DE_ABRIR, '189-D1: o foco não foi ao botão do painel de B');
    assert.equal(outra.voltarParaAOferta, null, '189-D1: o default virou `router.get` de volta para a A — essa é a ALTERNATIVA, não o default');

    // ⚠️ Oferta B SEM imagem: o foco vai a NADA, sem lançar (a `ref` fica nula).
    const semImagem = aoFecharOLightbox({ contexto, painelAtual: { ...PAINEL_B, fotos: { imagens: [] } } });
    assert.equal(semImagem.focar, null, 'o foco foi para um botão que não existe (a oferta B não tem imagem)');

    // ⚠️ Pedido adverso: nunca lança, e o resultado é seguro.
    for (const adverso of ADVERSOS) {
        assert.doesNotThrow(() => aoFecharOLightbox(adverso), `LANÇOU com pedido adverso (typeof ${typeof adverso})`);
        const r = aoFecharOLightbox({ contexto: adverso, painelAtual: adverso });
        assert.equal(r.mudarFotoDoPainel, false, 'pedido adverso mandou mexer na foto do painel');
        assert.ok(r.focar === null || r.focar === FOCO_NO_BOTAO_DE_ABRIR, `o destino do foco saiu ${String(r.focar)}`);
    }

    // ⚠️⚠️ O TOKEN DO FOCO **não** contém `Ampliar`: a FONTE de
    // `PainelDeRevisao.jsx` é varrida por `/Ampliar/` em
    // `publicador-painel-casca-188.test.js:1035`, e *"silêncio que depende de UMA
    // LETRA é sorte"* — `'ampliar'` minúsculo passaria **por sorte**.
    assert.equal(typeof FOCO_NO_BOTAO_DE_ABRIR, 'string', 'o token do foco não é string');
    assert.doesNotMatch(FOCO_NO_BOTAO_DE_ABRIR, /ampliar/i, 'o token do foco cita "ampliar" — ele depende da CAIXA de uma letra para não reprovar o `casca-188:1035`');
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. A ARMADILHA A2 E O TERRITÓRIO DO PAINEL E DA PÁGINA
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ ARMADILHA A2 — nem `⤢` nem `Ampliar` no CÓDIGO de `PainelDeRevisao.jsx` 189_06', () => {
    // ⚠️⚠️ `publicador-painel-casca-188.test.js:1034`/`:1035` varrem a FONTE de
    // `PainelDeRevisao.jsx` **SEM COMENTÁRIO** por `/⤢/` e `/Ampliar/`. ➡️ Uma
    // prop chamada `onAmpliar` ali **REPROVARIA**, e o nome usado é OUTRO.
    assert.doesNotMatch(FONTE_PAINEL, /Ampliar/, 'o literal `Ampliar` entrou no CÓDIGO do painel — `casca-188:1035` reprova');
    assert.doesNotMatch(FONTE_PAINEL, /⤢/, 'o glifo `⤢` entrou no CÓDIGO do painel — `casca-188:1034` reprova');

    // ⚠️ E a sonda do VIZINHO continua existindo (se ela sair, esta asserção
    // ficaria protegendo o nada).
    assert.match(FONTE_GATE_CASCA, /\[\/Ampliar\/, /, 'a sonda `/Ampliar/` saiu do `casca-188` — esta asserção passaria a proteger o nada');
    assert.match(FONTE_GATE_CASCA, /\[\/⤢\/, /, 'a sonda `/⤢/` saiu do `casca-188`');

    // ⚠️ ISCA: a leitura SEM comentário **não é cega** — o CRU tem prosa que o
    // stripper tira, e por isso a sonda acima não é vazia por construção.
    assert.ok(CRU_PAINEL.length > FONTE_PAINEL.length, 'a leitura SEM comentário não tirou nada — o stripper está inerte');
    assert.doesNotMatch(FONTE_PAINEL, /⚠️/, 'a leitura SEM comentário deixou os avisos — ela não está removendo comentário');

    // ⚠️ E as formas que LANÇAM continuam fora do painel (o `casca-188` as
    // proíbe, e está CERTO).
    for (const re of [/\bString\(/, /hasOwnProperty/, /JSON\.stringify/, /localStorage/, /sessionStorage/]) {
        assert.doesNotMatch(FONTE_PAINEL, re, `${re.source} entrou no painel — \`casca-188\` reprova, e com razão`);
    }
    const CEBO = 'String(x); Object.prototype.hasOwnProperty.call(o,k); JSON.stringify(o); localStorage.x; sessionStorage.y;';
    for (const re of [/\bString\(/, /hasOwnProperty/, /JSON\.stringify/, /localStorage/, /sessionStorage/]) {
        assert.match(CEBO, re, `a sonda ${re.source} está CEGA`);
    }
});

test('⚠️⚠️ O painel compõe o LIGHTBOX e a LATERAL, e os três exports puros existem 189_06', () => {
    // ⚠️ As três funções puras que a página consome — por NOME, nunca por linha
    // (âncora em número que muda é frágil).
    for (const nome of ['contextoDoLightbox', 'fecharLightboxDevolvendoFoco', 'aoFecharOLightbox', 'indiceNoContexto']) {
        assert.equal(typeof PAINEL[nome], 'function', `\`${nome}\` não é named export de PainelDeRevisao.jsx`);
        assert.match(FONTE_PAINEL, new RegExp(`export function ${nome}\\b`), `\`${nome}\` deixou de ser \`export function\``);
    }
    assert.equal(typeof PAINEL.FOCO_NO_BOTAO_DE_ABRIR, 'string', '`FOCO_NO_BOTAO_DE_ABRIR` não é named export');

    // ⚠️ O degrau e as duas ações novas.
    assert.equal(PAINEL.ACOES.FECHAR_LIGHTBOX, 'fechar-lightbox', 'a ação `fechar-lightbox` divergiu');
    assert.equal(PAINEL.ACOES.MOVER_LIGHTBOX, 'mover-lightbox', 'a ação `mover-lightbox` divergiu');
    assert.ok((FONTE_PAINEL.match(/temLightbox/g) ?? []).length >= 2, 'o parâmetro `temLightbox` aparece menos de 2 vezes no painel');

    // ⚠️⚠️ As ações ANTIGAS do 188-07 continuam de pé — reaponte não apaga.
    for (const [chave, valor] of [['FECHAR_MODAL', 'fechar-modal'], ['FECHAR_PAINEL', 'fechar-painel'], ['IR_PARA_OFERTA', 'ir-para-oferta'], ['TROCAR_IMAGEM', 'trocar-imagem']]) {
        assert.equal(PAINEL.ACOES[chave], valor, `a ação \`${chave}\` do 188-07 mudou de valor`);
    }

    // ⚠️ O painel continua sendo PORTAL e os `z` continuam onde estavam: as duas
    // âncoras de `publicador-lightbox-camadas-189.test.js` casam EXATAMENTE 1x.
    assert.equal((FONTE_PAINEL.match(/const CLASSES_BACKDROP = 'fixed inset-0 z-\[39\] bg-black\//g) ?? []).length, 1, 'a âncora do backdrop `z-[39]` deixou de casar 1x');
    assert.equal((FONTE_PAINEL.match(/'fixed inset-y-0 right-0 z-\[40\]/g) ?? []).length, 1, 'a âncora do painel `z-[40]` deixou de casar 1x');
    assert.match(FONTE_PAINEL, /createPortal\(/, 'o painel deixou de ser portado');
});

test('⚠️⚠️ A PÁGINA repassa `lightbox` como DADO, sem `String(` e sem `localStorage` 189_06', () => {
    // ⚠️ A prop-raiz do servidor (189-02) entra na assinatura da página.
    assert.match(FONTE_PAGINA, /\blightbox = null\b/, 'a prop-raiz `lightbox` não entrou na assinatura da página');

    // ⚠️⚠️ **UM SIGNIFICADO POR PROP:** `lightbox` é DADO, e não um slot de NÓ.
    // Slot de NÓ novo obrigaria a acrescentar linha em DUAS listas, em DOIS
    // arquivos de gate (`SLOTS_DE_NO_*`), e isto NÃO é um slot.
    assert.doesNotMatch(FONTE_PAGINA, /isValidElement\(\s*lightbox\s*\)/, '`lightbox` virou slot de NÓ — ele é DADO (e as listas `SLOTS_DE_NO_*` não foram tocadas)');
    assert.match(FONTE_PAGINA, /<LightboxDaImagem/, 'a página deixou de montar o lightbox');
    assert.match(FONTE_PAGINA, /<LateralDoLightbox/, 'a página deixou de montar a lateral de 320px (189-05)');
    assert.match(FONTE_PAGINA, /onAmpliar=/, 'a página deixou de ligar o "⤢ Ampliar" da coluna de fotos');

    // ⚠️ Os gates da 187-04 e do 188-07 sobre a página continuam valendo, e os
    // dois estão CERTOS.
    assert.doesNotMatch(FONTE_PAGINA, /\bString\(/, '`String(` voltou à página — ele LANÇA com `{toString:null}`');
    assert.doesNotMatch(FONTE_PAGINA, /localStorage|sessionStorage/, 'o estado do lightbox foi para o navegador — ele é memória do componente (T-189-44)');
    assert.doesNotMatch(FONTE_PAGINA, /Gerar novamente/, 'a página antecipou a Fase 192');
    assert.doesNotMatch(FONTE_PAGINA, /Comparar lado a lado/, 'a página antecipou as Fases 190/191, que estão FORA DE ESCOPO desde 20/10/2026');

    // ⚠️ ISCA: a fonte da página foi lida de verdade (sonda de ausência sobre
    // leitura quebrada passa CALADA).
    assert.match(FONTE_PAGINA, /export default function Aprovacao\(/, 'a página desapareceu da fonte lida — os `doesNotMatch` acima seriam vazios');
    assert.ok(FONTE_PAGINA.length > 10000, `a fonte da página veio com ${FONTE_PAGINA.length} bytes — a leitura está quebrada`);
});

test('⚠️ ANTI-FANTASMA e o MANIFESTO deste plano 189_06', () => {
    // ⚠️⚠️ `test -e` em CADA caminho citado: sonda de AUSÊNCIA sobre arquivo
    // inexistente passa CALADA. São DEZ fantasmas nesta milestone.
    const fantasmas = CAMINHOS_CITADOS.filter((rel) => !existsSync(path.resolve(RAIZ, rel)));
    assert.deepEqual(fantasmas, [], 'caminho citado que não existe no disco');

    // ⚠️ E a ISCA do anti-fantasma: o caminho que tem de continuar AUSENTE.
    assert.ok(!existsSync(path.resolve(RAIZ, ISCA_AUSENTE)), 'a isca do anti-fantasma passou a EXISTIR — a sonda deixou de distinguir');

    // ⚠️⚠️ `vocabulario-tamanhos` em LINHA PRÓPRIA e na forma `>=` — a contagem
    // CRESCE por construção (um lote de sub-testes por arquivo varrido), e
    // forma exata viraria número que o trabalho certo derruba.
    const linha = TEXTO_MANIFESTO.match(/^vocabulario-tamanhos\s+js\s+(\S+)\s+::\s+tests=(>=\d+)$/m);
    assert.notEqual(linha, null, '`vocabulario-tamanhos` não está em LINHA PRÓPRIA na forma `>=N`');
    assert.equal(linha[1], 'tests/js/publicador-vocabulario-novo.test.js', '`vocabulario-tamanhos` aponta para outro arquivo');

    // ⚠️ Os três ids deste plano, por igualdade de CONJUNTO — nunca uma CONTAGEM
    // (número que sobe a cada tarefa treina o executor a ajustar réguas).
    const ids = [...TEXTO_MANIFESTO.matchAll(/^([a-z0-9-]+)\s+(js|php)\s+/gm)].map((m) => m[1]);
    assert.deepEqual(
        [...ids].sort(),
        ['coluna-de-fotos-188', 'lightbox-no-painel', 'vocabulario-tamanhos'],
        'os ids do manifesto deste plano deixaram de ser EXATAMENTE os três do `<verify>`',
    );

    // ⚠️ UM id por arquivo de gate (no modo `js` o harness roda o arquivo
    // inteiro): nenhum arquivo aparece em dois ids.
    const arquivos = [...TEXTO_MANIFESTO.matchAll(/^[a-z0-9-]+\s+js\s+(\S+)\s+::/gm)].map((m) => m[1]);
    assert.equal(new Set(arquivos).size, arquivos.length, 'um arquivo de gate aparece em DOIS ids do manifesto');
});

test('⚠️ FID-01 §D e §E — as linhas do protótipo, por SCRIPT (ou `skip` NOMEADO) 189_06', (t) => {
    // ⚠️⚠️ O protótipo **não é versionado** (D-189-2): sem ele o bloco degrada
    // para `skip` EXPLÍCITO e NOMEADO — nunca para verde silencioso.
    const abs = path.resolve(RAIZ, PROTOTIPO);
    if (!existsSync(abs)) {
        t.skip(`SKIP NOMEADO: \`${PROTOTIPO}\` não existe nesta máquina (não é versionado — D-189-2)`);
        return;
    }

    const linhas = fs.readFileSync(abs, 'utf8').split(/\r?\n/);
    const em = (n) => linhas[n - 1] ?? '';

    // §D1/§D2 — o wrapper e o botão, COM `cursor` e `white-space` conferidos um a
    // um (a FID-01 erra por OMISSÃO, quase nunca por valor).
    assert.ok(em(604).includes('position:absolute;right:10px;bottom:10px;display:flex;gap:6px'), '`:604` (D1) divergiu do protótipo');
    assert.match(em(607), /⤢ Ampliar/, '`:607` não é o "⤢ Ampliar" — o ponteiro apodreceu');
    assert.ok(em(607).startsWith('                <button onClick="{{ P.onZoom }}" style="white-space:nowrap;'), '`white-space:nowrap` deixou de ser a PRIMEIRA propriedade de `:607`');
    for (const prop of ['height:30px', 'padding:0 11px', 'border:none', 'border-radius:8px', 'background:rgba(10,10,10,0.85)', 'color:#F4F4F5', 'font-size:12px', 'font-weight:500', 'cursor:pointer']) {
        assert.ok(em(607).includes(prop), `\`${prop}\` (D2) não está em \`:607\``);
    }
    assert.ok(!em(608).includes('Ampliar'), '`:608` voltou a ser o botão — o ponteiro corrigido pela 188-01 apodreceu');

    // §E12/§E13/§E14 — a ordem do teclado, lida do protótipo.
    assert.match(em(1014), /if \(e\.key === 'Escape'\)/, '`:1014` não é o bloco do `Escape`');
    assert.ok(em(1014).indexOf('S.modal') < em(1014).indexOf('S.lb'), 'a ordem `modal → lightbox` não está em `:1014`');
    assert.ok(em(1014).indexOf('S.lb') < em(1014).indexOf('S.panelId'), 'a ordem `lightbox → painel` não está em `:1014`');
    assert.match(em(1015), /if \(typing\) return;/, '`:1015` não é a guarda de campo de texto — o `Escape` deixaria de agir ali');
    assert.match(em(1016), /S\.lb && \(e\.key === 'ArrowRight' \|\| e\.key === 'ArrowLeft'\)/, '`:1016` não é o ←/→ do lightbox');
    // ⚠️⚠️ A PROVA de que ↑/↓ NÃO são barradas pelo lightbox (PERGUNTA-189-B (b)).
    assert.match(em(1017), /ArrowDown' \|\| e\.key === 'ArrowUp'/, '`:1017` não é o ↑/↓ da fila');
    assert.ok(!em(1017).includes('!S.lb'), '`:1017` passou a barrar ↑/↓ com o lightbox aberto — a PERGUNTA-189-B (b) muda de leitura');
    assert.match(em(1321), /\(lb\.i \+ d \+ n\) % n/, '`:1321` deixou de DAR A VOLTA');
    assert.match(em(1018), /Math\.max\(0, S\.pImg/, '`:1018` deixou de CLAMPAR o piso do painel');
    assert.match(em(975), /lbX: 50, lbY: 50/, '`:975` não é a origem inicial `{x:50,y:50}`');
    assert.match(em(1319), /lbZoom: 1/, '`:1319` deixou de abrir com o zoom em 1×');

    // ⚠️ ISCA: a leitura do protótipo não está cega.
    assert.equal(linhas.length, 1433, `o protótipo tem ${linhas.length} entradas por \`split\` (1432 por \`wc -l\`) — ele mudou de tamanho`);
    assert.equal(em(99999), '', 'a leitura de linha fora do arquivo não devolveu vazio — o leitor está errado');
});
