import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 188, plano 11, TAREFA 1 — MOD-01: o modal "Solicitar ajustes".
//
// ⚠️⚠️ DE CADA BLOCO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR: "o que
// precisaria estar errado no mundo para ele falhar?" — **"Nada" é ACHADO.**
//
//  1. SEGUNDA CASCA → o modal declarando backdrop/caixa/topo/rodapé próprios em
//     vez de IMPORTAR `CascaDeModal.jsx` (Fase 187, EM PRODUÇÃO). Duas cascas
//     divergiriam. ⚠️⚠️ E a sonda da largura tem de ser **ANCORADA**:
//     `w-[560px]` é **substring de `max-w-[560px]`**, que existe legitimamente em
//     `ToastDaFila.jsx:69` — `includes('w-[560px]')` acusaria o toast da 187, que
//     está NO AR. ⚠️ Com **ISCA INVERTIDA**: a lista de quem IMPORTA a casca não
//     pode estar vazia (vazia = varredura cega, não "tudo certo").
//  2. `font-pub` → o corpo sem a fonte do módulo. ⚠️ O painel desta fase é
//     PORTAL, e conteúdo portado **não herda fonte** (medido na Fase 178). Sem a
//     classe o modal inteiro perde a Geist **em silêncio**, com render verde.
//  3. SETE FIXOS → a tela montando a tabela de escopos. Seriam DUAS verdades
//     sobre "o que cada item afeta", e a divergente moveria ofertas erradas para
//     `changes`. ⚠️ **São SETE fixos, não oito** (`:1183`-`:1189` medido por
//     `sed`; `:1181` é o `.map()` das imagens e `:1191` é um `}`).
//  4. ÂMBAR → cor fixa no destino. `:1397`: âmbar **SÓ** no escopo de produto,
//     que é o único que afeta todas as ofertas. ⚠️ Os TRÊS escopos são testados —
//     um gate que olhasse um só passaria com a cor fixa.
//  5. CONCATENAÇÃO → a sugestão SUBSTITUINDO o comentário. `:1397` concatena
//     **e acrescenta um PONTO**. ⚠️ "Clicar apagou o que eu escrevi" é o defeito
//     que ninguém reporta: a pessoa só para de usar o chip.
//  6. VAZIO → texto vazio virando ajuste sem conteúdo. ⚠️ **DUAS provas**: o
//     botão APAGADO (+ `disabled=`) **e** o clique NÃO chamando `onSubmeter`. Só
//     a primeira é cosmética. ⚠️ E com `'   '` (só espaços): um `if (!texto)`
//     passaria o caso de `''` e aceitaria os espaços.
//  7. LITERAIS → paráfrase de rótulo. ⚠️⚠️ **EXPECTATIVA CONGELADA no gate, por
//     igualdade de STRING**, e NÃO lida do módulo que está sendo testado — foi a
//     mutação SOBREVIVENTE da wave 188-10 (ler o literal do próprio módulo muda
//     os dois lados juntos: tautologia). O protótipo entra como CRUZAMENTO, não
//     como fonte: ele **não é versionado**.
//  8. `disabled=` → **e NUNCA `/disabled/`**, que casa com `disabled:opacity-40`.
//     ⚠️ Lido no `<button>` DE SUBMETER, por **ÁRVORE COM ÍNDICE ABSOLUTO**:
//     `disabled=""` em "Cancelar" não pode satisfazer o caso.
//  9. ESCRITA → `router.post` dentro do modal. ⚠️ `versao_esperada` é OPCIONAL no
//     corpo, então omiti-la faz `exigirVersao()` receber `null` e **LIBERAR** a
//     escrita — *"sobrescrever em silêncio"*, que é o critério 17. O modal **não
//     escreve**: ele chama `onSubmeter`.
// 10. ADVERSO → qualquer campo objeto/`NaN`/array/nulo, a prop inteira nula,
//     **e no CLIQUE** (`renderToStaticMarkup` **não dispara `onClick`**, e a
//     última tela preta deste projeto foi NO CLIQUE). ⚠️ E `[{}]` como filho já
//     derrubou a casca: **prop de NÓ chegando objeto comum MATA a página**.
// 11. ROLLUP → flag de escopo do componente lida dentro do `.map()`. Este arquivo
//     mapeia os itens **e** as sugestões.
// 12. TERRITÓRIO → `localStorage`, `String(`, hex cru, FID-03/FID-04, as Fases
//     189/192 antecipadas, os INTOCÁVEIS tocados, o lockfile.
// 13. LISTAS → `.jsx` fora de `ARQUIVOS_NOVOS` (o GATE-03 passaria POR OMISSÃO)
//     ou fora de `JSX_DEPOIS_DA_187`. São **DUAS** escriturações, e isso é
//     DELIBERADO.
//
// ⚠️ Todos os nomes de teste terminam em `188_11`: nome genérico COLIDE no
// `--filter`, que **SOMA** as contagens.
// ⚠️ `design_handoff_publicador/` **NÃO é versionado**: o bloco que depende dele
// degrada para `skip` EXPLÍCITO e NOMEADO, nunca passa calado.
// ⚠️⚠️ **TODOS os bundles são montados ANTES do primeiro `test()`** — o `after()`
// do `node --test` já matou um arquivo inteiro **sem nenhum teste falhar**.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

// ⚠️ O nome do stub carrega o PID: a árvore é compartilhada com outras sessões, e
// um stub de nome fixo seria apagado pelo `after()` da sessão vizinha no meio da
// execução desta.
const STUB_INERTIA = path.join(__dirname, `.pub-modaisA188-inertia-stub-${process.pid}.mjs`);

// ⚠️⚠️ O gravador vive no `globalThis`: o `montar()` usa `bundle: true`, então o
// esbuild **INLINA o stub dentro do bundle** — o array de dentro do bundle e o de
// um `import()` do stub seriam DOIS objetos diferentes, e o gate leria um array
// sempre vazio (conserto MEDIDO na wave 188-07).
// ⚠️ `router.on` existe aqui porque o helper de escrita do painel passou a usá-lo
// (ver o bloco do 422 no `PainelDeRevisao.jsx`), e um stub sem ele mediria um
// caminho que não existe.
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
globalThis.CHAMADAS_MODAIS_A_188_11 = globalThis.CHAMADAS_MODAIS_A_188_11 ?? [];
const CHAMADAS = globalThis.CHAMADAS_MODAIS_A_188_11;
export { CHAMADAS };
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = {
    get: (...a) => { CHAMADAS.push(['get', ...a]); },
    post: (...a) => { CHAMADAS.push(['post', ...a]); },
    put: (...a) => { CHAMADAS.push(['put', ...a]); },
    delete: (...a) => { CHAMADAS.push(['delete', ...a]); },
    visit: (...a) => { CHAMADAS.push(['visit', ...a]); },
    reload: (...a) => { CHAMADAS.push(['reload', ...a]); },
    on: (...a) => { CHAMADAS.push(['on', ...a]); return () => {}; },
};
export function usePage() { return { props: { auth: { user: { id: 7 } } } }; }
export function Head({ children, title }) { return React.createElement('title', null, title ?? children); }
export function useForm(inicial) { return { data: inicial ?? {}, setData: () => {}, post: () => {}, get: () => {}, processing: false, errors: {}, reset: () => {} }; }
export function useRemember(v) { return [v, () => {}]; }
export const Deferred = ({ children }) => children;
export const WhenVisible = ({ children }) => children;
`, 'utf8');

after(() => {
    fs.rmSync(STUB_INERTIA, { force: true });
});

/** ⚠️ `route` é o global que o Ziggy injeta no navegador. */
global.route = (nome, params) => `/${nome}${JSON.stringify(params ?? {})}`;

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
        `.pub-modaisA188-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';
const REL_AJUSTE = `${DIR_APROV}/ModalDeAjuste.jsx`;
const REL_CASCA = `${DIR_APROV}/CascaDeModal.jsx`;
const REL_TOAST = `${DIR_APROV}/ToastDaFila.jsx`;
const REL_LOTE = `${DIR_APROV}/ModalDeLote.jsx`;
const REL_PAINEL = `${DIR_APROV}/PainelDeRevisao.jsx`;
const REL_GATE_VOCABULARIO = 'tests/js/publicador-vocabulario-novo.test.js';
const REL_GATE_FECHAMENTO = 'tests/js/publicador-fila-fechamento-187.test.js';
const REL_GATE_ZINDEX = 'tests/js/publicador-header-zindex-ordem.test.js';
const REL_GATE_MEUS_ANUNCIOS = 'tests/js/estrutura-meus-anuncios.test.js';
const REL_SERVICO_PAINEL = 'app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php';
const REL_MODELO_AJUSTE = 'app/Models/PubAprovacaoAjuste.php';
const REL_MANIFESTO = 'tests/gates/contagens-188-11.txt';
const REL_PLANO = '.planning/phases/188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece/188-11-PLAN.md';
const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

// ⚠️⚠️ ANTI-FANTASMA — `test -e` em CADA caminho citado. São DEZ caminhos
// fantasmas nesta milestone, TRÊS deles vindos de briefing do orquestrador, e o
// DÉCIMO foi escrito por quem tinha acabado de ler o aviso. Sonda de AUSÊNCIA
// sobre arquivo inexistente passa CALADA.
const CAMINHOS_CITADOS = [
    REL_AJUSTE, REL_CASCA, REL_TOAST, REL_LOTE, REL_PAINEL,
    REL_GATE_VOCABULARIO, REL_GATE_FECHAMENTO, REL_GATE_ZINDEX, REL_GATE_MEUS_ANUNCIOS,
    REL_SERVICO_PAINEL, REL_MODELO_AJUSTE, REL_MANIFESTO, REL_PLANO,
];

// ⚠️ A fonte SEM comentário: a prosa em pt-BR deste projeto cita os próprios
// identificadores e os próprios literais, e **palavra no docblock reprova pelo
// próprio conserto** (TRÊS mutações abortadas por isso na wave 187-04).
const FONTE_AJUSTE = lerSemComentarios(REL_AJUSTE);

/** A fonte CRUA — o docblock é artefato, e é nele que a RAZÃO está escrita. */
const CRUA_AJUSTE = fs.readFileSync(path.resolve(RAIZ, REL_AJUSTE), 'utf8');

/**
 * ⚠️⚠️ A LISTA CONSTANTE dos `.jsx` DESTE plano — o recorte de TODA sonda de
 * ausência.
 *
 * Por que CONSTANTE e não glob: glob pegaria arquivo de outra wave e a sonda
 * cobraria vocabulário de trabalho que não é deste plano. ⚠️ E por que RECORTADA:
 * `lucro_liquido` é campo **legítimo** do Portal, e varrer `app/` **barrou um
 * deploy** na Fase 186.
 */
const FONTES_DO_PLANO = [[REL_AJUSTE, FONTE_AJUSTE]];

const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

const nada = () => {};

/**
 * ⚠️⚠️ A ÁRVORE VAZIA do git — o objeto que todo repositório tem, sempre.
 *
 * É a ISCA DETERMINÍSTICA da sonda de `numstat`: contra ela **todo arquivo
 * rastreado** produz uma linha, então a sonda prova que a invocação funciona e
 * que o pathspec resolve. ⚠️ Usar "um arquivo que esta wave alterou" como isca
 * seria **vazio por construção depois do commit** (`git diff HEAD` fica limpo), e
 * o gate viraria falso positivo no dia seguinte.
 */
const ARVORE_VAZIA = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';

const numstatDe = (rel, base) => execFileSync(
    'git',
    ['diff', '--numstat', base ?? 'HEAD', '--', rel],
    { cwd: RAIZ, encoding: 'utf8' },
);

/**
 * O elemento de índice ABSOLUTO na árvore de tags de abertura do HTML.
 *
 * ⚠️⚠️ **NUNCA `indexOf(tagDeAbertura)`**: ele pega o IRMÃO ERRADO quando dois
 * elementos têm a MESMA string de classes — **quatro gates deste projeto estavam
 * medindo o irmão errado**.
 * ⚠️⚠️ **E `desabilitado` é `disabled=` por ATRIBUTO, nunca `/disabled/`**: a
 * segunda forma casa com a CLASSE `disabled:opacity-40` e o gate passaria com o
 * botão HABILITADO.
 */
function tagsDeAbertura(html) {
    return [...html.matchAll(/<(div|span|section|aside|button|input|label|select|option|textarea|a)\b([^>]*)>/g)].map((m) => ({
        tag: m[1],
        atributos: m[2],
        classe: (m[2].match(/class="([^"]*)"/) || [])[1] ?? '',
        desabilitado: /\bdisabled=/.test(m[2]),
    }));
}

/** As classes de um elemento como CONJUNTO — igualdade, nunca `includes`. */
function classesDe(elemento) {
    return new Set(elemento.classe.split(/\s+/).filter((c) => c !== ''));
}

/** O conteúdo de texto, sem tags — para ler o que a tela DIZ. */
function texto(html) {
    return html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
}

/** Os `<button>` na ORDEM da árvore, com o rótulo de texto de cada um. */
function botoesDe(html) {
    return [...html.matchAll(/<button\b([^>]*)>([\s\S]*?)<\/button>/g)].map((m) => ({
        atributos: m[1],
        rotulo: texto(m[2]),
        classe: (m[1].match(/class="([^"]*)"/) || [])[1] ?? '',
        desabilitado: /\bdisabled=/.test(m[1]),
    }));
}

// ═══ AS FIXTURES — ⚠️⚠️ ELAS DISCRIMINAM DE PROPÓSITO ═════════════════════
//
// ⚠️ Na wave 187-06 as duas mutações sobreviventes eram falha da FIXTURE, não da
// asserção. ➡️ Aqui a lista de itens tem **os TRÊS escopos** (sem isso o âmbar
// seria inobservável), **DUAS imagens antes dos sete fixos** (sem isso "a ordem é
// a do servidor" passaria com a tela reordenando) e um item **de produto com
// sugestão única** (sem isso a concatenação seria medida só no caso fácil).

/** ⚠️ As duas entradas de IMAGEM (`:1181`), que vêm ANTES dos sete fixos. */
const ITEM_IMAGEM_1 = {
    rotulo: 'Imagem #1 · Frontal (IA)',
    escopo: 'set',
    destino: 'Conjunto de fotos (Clássico e Premium)',
    nota_do_escopo: 'Ajuste no conjunto de fotos compartilhado',
    sugestoes: ['IA alterou os pés da poltrona', 'Cor diferente da original'],
};

const ITEM_IMAGEM_2 = {
    rotulo: 'Imagem #2 · Lateral',
    escopo: 'set',
    destino: 'Conjunto de fotos (Clássico e Premium)',
    nota_do_escopo: 'Ajuste no conjunto de fotos compartilhado',
    sugestoes: ['Recorte ruim'],
};

/** ⚠️ Os SETE fixos de `:1183`-`:1189`, na ORDEM, com os escopos reais da 186. */
const SETE_FIXOS = [
    { rotulo: 'Preço Clássico', escopo: 'offer', destino: 'Este anúncio (Clássico)', nota_do_escopo: 'Ajuste específico desta oferta', sugestoes: ['Rever preço para atingir a margem mínima', 'Preço abaixo do praticado pelo cliente'] },
    { rotulo: 'Frete do vendedor', escopo: 'offer', destino: 'Este anúncio (Clássico)', nota_do_escopo: 'Ajuste específico desta oferta', sugestoes: ['Frete do vendedor ainda não confirmado'] },
    { rotulo: 'Título', escopo: 'offer', destino: 'Este anúncio (Clássico)', nota_do_escopo: 'Ajuste específico desta oferta', sugestoes: ['Incluir medida e material no título'] },
    { rotulo: 'Descrição', escopo: 'offer', destino: 'Este anúncio (Clássico)', nota_do_escopo: 'Ajuste específico desta oferta', sugestoes: ['Descrição sem informações de montagem'] },
    { rotulo: 'Custo do produto', escopo: 'product', destino: 'Cadastro do produto (todas as ofertas)', nota_do_escopo: 'Ajuste no cadastro base: afeta todas as ofertas deste produto', sugestoes: ['Custo desatualizado no ERP'] },
    { rotulo: 'Atributos e ficha técnica', escopo: 'product', destino: 'Cadastro do produto (todas as ofertas)', nota_do_escopo: 'Ajuste no cadastro base: afeta todas as ofertas deste produto', sugestoes: ['Atributo obrigatório divergente'] },
    { rotulo: 'Composição do kit', escopo: 'product', destino: 'Cadastro do produto (todas as ofertas)', nota_do_escopo: 'Ajuste no cadastro base: afeta todas as ofertas deste produto', sugestoes: ['Composição do kit divergente das fotos'] },
];

const ITENS = [ITEM_IMAGEM_1, ITEM_IMAGEM_2, ...SETE_FIXOS];

/** O índice de cada escopo dentro de `ITENS` — ⚠️ lido, nunca presumido. */
const I_CONJUNTO = 0;
const I_OFERTA = 2;
const I_PRODUTO = 6;
/** ⚠️ O SÉTIMO fixo — o que ALTERNA por `units > 1` (`:1189`). Índice 2 + 6 = 8. */
const I_ULTIMO = 8;

// ═══ OS BUNDLES, TODOS ANTES DO PRIMEIRO `test()` ═════════════════════════

const MOD_AJUSTE = await montar(REL_AJUSTE);
const MOD_CASCA = await montar(REL_CASCA);

// ═══════════════════════════════════════════════════════════════════════════
// 0. ANTI-FANTASMA
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ ANTI-FANTASMA: todo caminho citado por este gate EXISTE no disco 188_11', () => {
    const fantasmas = CAMINHOS_CITADOS.filter((rel) => !fs.existsSync(path.resolve(RAIZ, rel)));
    assert.deepEqual(fantasmas, [], 'caminho fantasma citado por este gate — sonda de ausência sobre arquivo inexistente passa CALADA');

    // ⚠️ ISCA: a sonda tem de ACUSAR um caminho que de fato não existe, senão ela
    // está cega e o `deepEqual` acima passaria com qualquer lista.
    assert.equal(fs.existsSync(path.resolve(RAIZ, `${DIR_APROV}/ModalQueNinguemEscreveu.jsx`)), false, 'a isca do anti-fantasma virou arquivo de verdade');
});

// ═══════════════════════════════════════════════════════════════════════════
// 1. A CASCA É IMPORTADA, E NÃO EXISTE UMA SEGUNDA
// ═══════════════════════════════════════════════════════════════════════════

/** Os `.jsx` de `aprovacao/`, lidos SEM comentário (o scanner do Tailwind lê comentário; o gate NÃO). */
const JSX_DE_APROVACAO = fs.readdirSync(path.resolve(RAIZ, DIR_APROV))
    .filter((nome) => nome.endsWith('.jsx'))
    .map((nome) => [`${DIR_APROV}/${nome}`, lerSemComentarios(`${DIR_APROV}/${nome}`)]);

/**
 * ⚠️⚠️ A SONDA ANCORADA da largura de 560px — e ela foi MEDIDA contra o caso que
 * morde.
 *
 * `w-[560px]` é **substring de `max-w-[560px]`**, e `max-w-[560px]` EXISTE em
 * `ToastDaFila.jsx:69` (Fase 187, **NO AR**). `includes('w-[560px]')` acusaria o
 * toast. ➡️ A âncora é borda de CLASSE nos dois lados: ou começo de string/espaço/
 * abre-aspas antes, ou nada vale.
 */
const RE_LARGURA_DA_CASCA = /(^|[\s'"`])w-\[560px\]/;

test('⚠️⚠️ T-188-A0: a casca da Fase 187 é IMPORTADA, e NÃO existe uma segunda casca 188_11', () => {
    // (a) O modal IMPORTA a casca — por FORMA, não por "menciona".
    assert.match(FONTE_AJUSTE, /import\s+CascaDeModal\s+from\s+'\.\/CascaDeModal'/, 'o modal de ajuste parou de IMPORTAR a casca da Fase 187');
    assert.match(FONTE_AJUSTE, /<CascaDeModal\b/, 'o modal de ajuste não MONTA a casca');

    // (b) ⚠️ ISCA INVERTIDA — a lista de quem importa a casca **não pode estar
    // vazia**: vazia significa varredura cega, não "tudo certo" (learnings §4b.1).
    const importam = JSX_DE_APROVACAO
        .filter(([rel, fonte]) => rel !== REL_CASCA && /from\s+'\.\/CascaDeModal'/.test(fonte))
        .map(([rel]) => rel);
    assert.ok(importam.length > 0, 'NINGUÉM importa `CascaDeModal` — a varredura está cega, não "tudo certo"');
    assert.ok(importam.includes(REL_AJUSTE), `o modal de ajuste saiu da lista de quem importa a casca: [${importam.join(', ')}]`);
    assert.ok(importam.includes(REL_LOTE), 'o modal de LOTE (Fase 187, em produção) saiu da lista — a sonda mudou de forma');

    // (c) ⚠️⚠️ NENHUM arquivo além da casca declara a geometria dela.
    const MARCAS_DA_CASCA = [
        ['pt-[96px]', (fonte) => fonte.includes('pt-[96px]')],
        ['max-h-[calc(100vh-140px)]', (fonte) => fonte.includes('max-h-[calc(100vh-140px)]')],
        ['shadow-pub-modal', (fonte) => fonte.includes('shadow-pub-modal')],
        ['w-[560px] (ANCORADO)', (fonte) => RE_LARGURA_DA_CASCA.test(fonte)],
    ];
    const segundaCasca = [];
    for (const [rel, fonte] of JSX_DE_APROVACAO) {
        if (rel === REL_CASCA) continue;
        for (const [marca, achou] of MARCAS_DA_CASCA) {
            if (achou(fonte)) segundaCasca.push(`${rel} ~ ${marca}`);
        }
    }
    assert.deepEqual(segundaCasca, [], 'nasceu uma SEGUNDA casca de modal — duas cascas divergiriam, e a divergente seria vista como dois raios diferentes na mesma tela');

    // (d) ⚠️ E a sonda ACHA as quatro marcas NA casca: sem este controle o
    // `deepEqual` acima passaria com as quatro funções cegas.
    const fonteDaCasca = lerSemComentarios(REL_CASCA);
    const naCasca = MARCAS_DA_CASCA.filter(([, achou]) => achou(fonteDaCasca)).map(([marca]) => marca);
    assert.equal(naCasca.length, MARCAS_DA_CASCA.length, `a sonda da segunda casca está CEGA: na própria casca ela só achou [${naCasca.join(', ')}]`);

    // (e) ⚠️⚠️ A ARMADILHA MEDIDA, nas DUAS direções: a sonda ancorada **não**
    // pode casar o `max-w-[560px]` legítimo do toast da Fase 187.
    const fonteDoToast = lerSemComentarios(REL_TOAST);
    assert.ok(fonteDoToast.includes('max-w-[560px]'), 'o `max-w-[560px]` do toast da Fase 187 sumiu — a armadilha mudou de lugar, remeça a âncora');
    assert.equal(RE_LARGURA_DA_CASCA.test(fonteDoToast), false, 'a sonda ANCORADA acusou o `max-w-[560px]` do toast — é gate largo reprovando trabalho que está NO AR');
    assert.equal(RE_LARGURA_DA_CASCA.test('max-w-[560px]'), false, 'a âncora aceita sufixo: `includes` morde por prefixo E sufixo, e já mordeu cinco vezes neste projeto');
    assert.equal(RE_LARGURA_DA_CASCA.test('flex w-[560px] flex-col'), true, 'a âncora rejeita a forma que ela DEVE pegar — toda regex precisa de um caso que casa');

    // (f) ⚠️ E o modal **não** declara backdrop, topo nem rodapé próprios.
    const PROPRIOS = ['fixed inset-0', 'CLASSES_BACKDROP', 'CLASSES_RODAPE', 'CLASSES_TOPO', 'z-[42]'];
    const reimplementou = PROPRIOS.filter((marca) => FONTE_AJUSTE.includes(marca));
    assert.deepEqual(reimplementou, [], 'o modal de ajuste reimplementou peça da casca em vez de importá-la');
});

test('⚠️⚠️ `CascaDeModal.jsx` sai deste plano com `numstat` ZERO 188_11', () => {
    assert.equal(numstatDe(REL_CASCA).trim(), '', '`CascaDeModal.jsx` foi EDITADA — ela está EM PRODUÇÃO, e o plano tinha de PARAR e devolver a pergunta');
    assert.equal(numstatDe(REL_TOAST).trim(), '', '`ToastDaFila.jsx` foi editada — é da Fase 187 e está NO AR');
    assert.equal(numstatDe(REL_LOTE).trim(), '', '`ModalDeLote.jsx` foi editado — é da Fase 187 e está NO AR');

    // ⚠️ ISCA DETERMINÍSTICA: contra a ÁRVORE VAZIA do git todo arquivo rastreado
    // produz linha. "Um arquivo que esta wave alterou" seria vazio por construção
    // DEPOIS DO COMMIT, e o gate viraria falso positivo no dia seguinte.
    assert.notEqual(numstatDe(REL_CASCA, ARVORE_VAZIA).trim(), '', 'a sonda de `numstat` está cega: nem contra a árvore vazia ela produz linha');
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. `font-pub` NO CORPO, COM A RAZÃO ESCRITA
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ CRITÉRIO 15 / REND-05: o corpo do modal DECLARA a fonte do módulo 188_11', () => {
    assert.match(FONTE_AJUSTE, /CLASSES_CORPO_DO_MODAL\s*=\s*'[^']*font-pub/, 'o corpo do modal não declara `font-pub` — conteúdo portado NÃO herda fonte, e o modal perderia a Geist em SILÊNCIO');

    const html = render(MOD_AJUSTE.CorpoDoAjuste, { itens: ITENS, indice: I_OFERTA, texto: '', onItem: nada, onTexto: nada });
    const raiz = tagsDeAbertura(html)[0];
    assert.ok(classesDe(raiz).has('font-pub'), 'a RAIZ do corpo não tem `font-pub` — é exatamente no limite do portal que a herança quebra, então a classe tem de estar na raiz, não num filho');

    // ⚠️ A RAZÃO é artefato, e mora no DOCBLOCK: a próxima pessoa tem de achar por
    // GREP, não por bisseção. ⚠️ Leitura CRUA aqui de propósito.
    assert.match(CRUA_AJUSTE, /conteúdo portado não herda fonte/i, 'o docblock parou de dizer POR QUE `font-pub` é declarado aqui');
    assert.match(CRUA_AJUSTE, /a casca é compartilhada e está em produção/i, 'o docblock parou de dizer por que a casca NÃO recebe `font-pub`');

    // ⚠️ E a casca continua SEM `font-pub` — acrescentá-la lá seria mudar arquivo
    // em produção para resolver um problema que é do CHAMADOR.
    assert.equal(lerSemComentarios(REL_CASCA).includes('font-pub'), false, '`font-pub` entrou na casca da Fase 187 — o problema é do chamador, e a casca está em produção');
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. OS SETE FIXOS + N IMAGENS, NA ORDEM DO SERVIDOR
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ CRITÉRIO 12: os itens vêm do SERVIDOR, na ORDEM, e a tela não tem tabela de escopos 188_11', () => {
    const html = render(MOD_AJUSTE.CorpoDoAjuste, { itens: ITENS, indice: I_CONJUNTO, texto: '', onItem: nada, onTexto: nada });

    // (a) As 9 entradas, NA ORDEM, por igualdade de lista — não por `includes`.
    const opcoes = [...html.matchAll(/<option\b[^>]*>([\s\S]*?)<\/option>/g)].map((m) => texto(m[1]));
    assert.deepEqual(
        opcoes,
        ITENS.map((item) => item.rotulo),
        'o `<select>` de item não lista as entradas do servidor NA ORDEM — reordenar aqui faria o "Imagem #3" do modal apontar para outra foto',
    );

    // (b) ⚠️⚠️ SÃO SETE FIXOS, e não oito: `:1183`-`:1189`. A prosa do plano diz
    // OITO; o protótipo e a `188-FID01.md` §F.1 dizem SETE, e o
    // `AJUSTE_ITENS_FIXOS` do serviço também. O FID01 tem razão.
    assert.equal(SETE_FIXOS.length, 7, 'a fixture dos fixos deixou de ter SETE entradas — ela é a prova de que a prosa do plano erra');
    const fixosNoServico = lerSemComentarios(REL_SERVICO_PAINEL).match(/AJUSTE_ITENS_FIXOS\s*=\s*\[([\s\S]*?)\n\s{4}\];/);
    assert.ok(fixosNoServico, 'não achei `AJUSTE_ITENS_FIXOS` no serviço — a leitura está cega');
    assert.equal(
        (fixosNoServico[1].match(/'escopo'\s*=>/g) || []).length,
        7,
        'o servidor deixou de ter SETE itens fixos — se virou oito, remeça o FID01 e a fixture JUNTOS',
    );

    // (c) ⚠️⚠️ A TELA NÃO TEM A TABELA DE ESCOPOS. Escrevê-la aqui criaria a
    // SEGUNDA VERDADE sobre "o que cada item afeta", e a divergente moveria
    // ofertas erradas para `changes`.
    const DO_SERVIDOR = [
        'Conjunto de fotos', 'Cadastro do produto', 'Este anúncio',
        'Ajuste no conjunto de fotos compartilhado',
        'Ajuste no cadastro base', 'Ajuste específico desta oferta',
        'Frete do vendedor', 'Custo do produto', 'Atributos e ficha técnica',
        'Composição do kit', 'Categoria', 'Descrição',
    ];
    const cravados = DO_SERVIDOR.filter((literal) => FONTE_AJUSTE.includes(literal));
    assert.deepEqual(cravados, [], 'a tela CRAVOU rótulo/destino/nota que é do servidor — segunda verdade sobre o que cada item afeta');

    // ⚠️ ISCA do recorte, nas duas direções: os doze literais ESTÃO no servidor.
    const fonteDoServico = lerSemComentarios(REL_SERVICO_PAINEL);
    const ausentesNoServidor = DO_SERVIDOR.filter((literal) => !fonteDoServico.includes(literal) && !lerSemComentarios(REL_MODELO_AJUSTE).includes(literal));
    assert.deepEqual(ausentesNoServidor, [], 'literal que o gate cobra da tela não existe no servidor — a sonda está cobrando o lugar errado');

    // (d) Sem item nenhum o modal **não abre**, nem com casca vazia: `<select>` sem
    // opção é um botão sem destino disfarçado.
    assert.equal(render(MOD_AJUSTE.default, { aberto: true, itens: [], texto: 'tem texto aqui', onSubmeter: nada }), '', 'com ZERO item o modal desenhou casca vazia');
    assert.equal(render(MOD_AJUSTE.default, { aberto: true, itens: null, texto: 'tem texto aqui', onSubmeter: nada }), '', 'com `itens` nulo o modal desenhou casca vazia');

    // (e) ⚠️ Índice fora da lista cai no PRIMEIRO (`:1396`), nunca `undefined`.
    for (const adverso of [99, -1, 'dois', null, undefined, NaN, {}, [], 1.5]) {
        const escolha = MOD_AJUSTE.itemEscolhido(ITENS, adverso);
        assert.equal(escolha.posicao, 0, `índice adverso não caiu no primeiro item`);
    }
    assert.equal(MOD_AJUSTE.itemEscolhido(ITENS, I_PRODUTO).posicao, I_PRODUTO, 'índice VÁLIDO deixou de ser respeitado — a sonda acima passaria com a função sempre devolvendo 0');
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. OS TRÊS ESCOPOS, E O ÂMBAR SÓ NO PRODUTO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ `:1397`: a caixa "Encaminhar para" mostra destino e nota, e o âmbar é SÓ do produto 188_11', () => {
    const corDoDestino = (indice) => {
        const html = render(MOD_AJUSTE.CorpoDoAjuste, { itens: ITENS, indice, texto: '', onItem: nada, onTexto: nada });
        const spans = [...html.matchAll(/<span\b([^>]*)>([\s\S]*?)<\/span>/g)].map((m) => ({
            classe: (m[1].match(/class="([^"]*)"/) || [])[1] ?? '',
            rotulo: texto(m[2]),
        }));
        const destino = spans.find((s) => s.rotulo === ITENS[indice].destino);
        assert.ok(destino, `o destino "${ITENS[indice].destino}" não apareceu na tela`);

        return { html, classes: new Set(destino.classe.split(/\s+/).filter((c) => c !== '')) };
    };

    const conjunto = corDoDestino(I_CONJUNTO);
    const oferta = corDoDestino(I_OFERTA);
    const produto = corDoDestino(I_PRODUTO);

    // (a) O rótulo da caixa, o destino e a nota aparecem nos TRÊS.
    for (const [nome, caso, indice] of [['conjunto', conjunto, I_CONJUNTO], ['oferta', oferta, I_OFERTA], ['produto', produto, I_PRODUTO]]) {
        const visivel = texto(caso.html);
        assert.ok(visivel.includes('Encaminhar para'), `o rótulo "Encaminhar para" sumiu no escopo ${nome}`);
        assert.ok(visivel.includes(ITENS[indice].destino), `o destino sumiu no escopo ${nome}`);
        assert.ok(visivel.includes(ITENS[indice].nota_do_escopo), `a nota do escopo sumiu no escopo ${nome}`);
    }

    // (b) ⚠️ O âmbar é SÓ do produto — os TRÊS escopos medidos, porque um gate que
    // olhasse um só passaria com a cor FIXA.
    assert.ok(produto.classes.has('text-pub-warn-txt'), 'o destino de PRODUTO perdeu o âmbar (`:1397`: `prod → #FBBF24`) — é o único escopo que afeta todas as ofertas');
    assert.ok(conjunto.classes.has('text-pub-fase1'), 'o destino de CONJUNTO não está no cinza-claro de `:1397` (`#D4D4D8`)');
    assert.ok(oferta.classes.has('text-pub-fase1'), 'o destino de OFERTA não está no cinza-claro de `:1397`');
    assert.equal(conjunto.classes.has('text-pub-warn-txt'), false, 'o destino de CONJUNTO saiu em âmbar — a cor ficou FIXA');
    assert.equal(oferta.classes.has('text-pub-warn-txt'), false, 'o destino de OFERTA saiu em âmbar — a cor ficou FIXA');

    // (c) ⚠️ A chave do escopo é `product`, LIDA do modelo em produção — e o `prod`
    // do protótipo é RECUSADO de propósito.
    assert.match(FONTE_AJUSTE, /ESCOPO_DO_PRODUTO\s*=\s*'product'/, 'a chave do escopo de produto deixou de ser `product` — ela é a de `PubAprovacaoAjuste::ESCOPO_PRODUTO`, que está em produção');
    assert.match(lerSemComentarios(REL_MODELO_AJUSTE), /ESCOPO_PRODUTO\s*=\s*'product'/, 'o modelo mudou a chave do escopo de produto — remeça a constante da tela JUNTO');
    assert.equal(/'prod'/.test(FONTE_AJUSTE), false, 'a tela passou a usar o `prod` do protótipo, que o servidor RECUSA');

    // (d) Sem destino a caixa NÃO é desenhada (D4): caixa que diz "Encaminhar para"
    // e não diz para onde é casca decorativa.
    const semDestino = render(MOD_AJUSTE.CorpoDoAjuste, {
        itens: [{ ...ITEM_IMAGEM_1, destino: null }], indice: 0, texto: '', onItem: nada, onTexto: nada,
    });
    assert.equal(texto(semDestino).includes('Encaminhar para'), false, 'sem destino a caixa "Encaminhar para" foi desenhada vazia (D4)');
    // ⚠️ ISCA: a MESMA fixture COM destino desenha — senão o caso passaria com a
    // tela nunca desenhando a caixa.
    const comDestino = render(MOD_AJUSTE.CorpoDoAjuste, { itens: [ITEM_IMAGEM_1], indice: 0, texto: '', onItem: nada, onTexto: nada });
    assert.ok(texto(comDestino).includes('Encaminhar para'), 'nem COM destino a caixa aparece — a sonda está cega');
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. A SUGESTÃO CONCATENA, E ACRESCENTA UM PONTO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ `:1397`: a sugestão CONCATENA no comentário e acrescenta um PONTO 188_11', () => {
    const { comentarioComSugestao } = MOD_AJUSTE;

    // (a) Campo vazio: só a sugestão, COM o ponto.
    assert.equal(comentarioComSugestao('', 'Cor diferente da original'), 'Cor diferente da original.', 'com o campo vazio a sugestão não entrou com o ponto final');

    // (b) ⚠️ COM texto: CONCATENA (espaço no meio), NÃO substitui.
    assert.equal(
        comentarioComSugestao('A poltrona está torta.', 'Cor diferente da original'),
        'A poltrona está torta. Cor diferente da original.',
        'a sugestão SUBSTITUIU o que já estava escrito — "clicar apagou o que eu escrevi" é o defeito que ninguém reporta',
    );

    // (c) Duas em sequência acumulam.
    const uma = comentarioComSugestao('', 'Recorte ruim');
    assert.equal(comentarioComSugestao(uma, 'Peça ausente'), 'Recorte ruim. Peça ausente.', 'a segunda sugestão não acumulou');

    // (d) Sugestão adversa não produz texto (e não lança).
    for (const adversa of [null, undefined, 42, {}, [], NaN, '', '   ', { toString: null }]) {
        assert.equal(comentarioComSugestao('base', adversa), null, 'sugestão adversa produziu texto');
    }

    // (e) ⚠️ E o CLIQUE de verdade no chip chama `onTexto` com o texto CONCATENADO.
    let recebido = null;
    const htmlNode = React.createElement(MOD_AJUSTE.CorpoDoAjuste, {
        itens: ITENS, indice: I_CONJUNTO, texto: 'ja escrito', onItem: nada, onTexto: (t) => { recebido = t; },
    });
    // ⚠️ `renderToStaticMarkup` **não dispara `onClick`** — é por isso que o handler
    // é exercido direto, pela árvore de elementos.
    const filhos = htmlNode.type(htmlNode.props);
    const faixa = filhos.props.children.filter((f) => f && f.props && f.props.className === MOD_AJUSTE.CLASSES_FAIXA_DE_SUGESTOES);
    assert.equal(faixa.length, 1, 'não achei a faixa de sugestões na árvore');
    const chips = faixa[0].props.children.filter((c) => c !== null);
    assert.equal(chips.length, ITEM_IMAGEM_1.sugestoes.length, 'a quantidade de chips divergiu das sugestões do servidor');
    chips[1].props.onClick();
    assert.equal(recebido, 'ja escrito Cor diferente da original.', 'o CLIQUE no chip não concatenou — o render estático não dispara `onClick`, e é por isso que o handler é exercido direto');

    // (f) O rótulo do chip é `'+ ' + texto`, nos dois.
    const html = render(MOD_AJUSTE.CorpoDoAjuste, { itens: ITENS, indice: I_CONJUNTO, texto: '', onItem: nada, onTexto: nada });
    const rotulos = botoesDe(html).map((b) => b.rotulo);
    assert.deepEqual(rotulos, ITEM_IMAGEM_1.sugestoes.map((s) => `+ ${s}`), 'o rótulo do chip deixou de ser `+ {texto}` (`:794`)');
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. TEXTO VAZIO NÃO ENVIA — AS DUAS PROVAS
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ T-188-A7 / `:1193`: texto vazio e só-espaços não enviam, nas DUAS provas 188_11', () => {
    const { comentarioValido } = MOD_AJUSTE;

    // (a) A decisão, numa casa só.
    assert.equal(comentarioValido(''), false, '`` passou');
    assert.equal(comentarioValido('   '), false, '`   ` (só espaços) passou — `trim()` é o que o protótipo usa, e um `if (!texto)` aceitaria isto');
    assert.equal(comentarioValido('\n\t '), false, 'só quebra de linha e tabulação passou');
    assert.equal(comentarioValido('a'), true, 'UM caractere não-vazio foi recusado — a trava de tamanho é da EXCEÇÃO, não daqui');
    for (const adverso of [null, undefined, 0, 42, {}, [], NaN, { toString: null }]) {
        assert.equal(comentarioValido(adverso), false, 'campo adverso passou pela validação');
    }

    // (b) ⚠️ PROVA 1 (cosmética): o botão de submeter sai APAGADO e com `disabled=`.
    // ⚠️ Localizado por ÁRVORE COM ÍNDICE ABSOLUTO: há DOIS botões no rodapé da
    // casca, e `disabled=""` em "Cancelar" não pode satisfazer o caso.
    for (const vazio of ['', '   ']) {
        const html = render(MOD_AJUSTE.default, { aberto: true, itens: ITENS, indice: I_OFERTA, texto: vazio, onFechar: nada, onSubmeter: nada, onTexto: nada, onItem: nada });
        const botoes = botoesDe(html);
        const submeter = botoes.filter((b) => b.rotulo === 'Solicitar ajustes');
        assert.equal(submeter.length, 1, `não achei EXATAMENTE um botão "Solicitar ajustes" (achei ${submeter.length})`);
        assert.equal(submeter[0].desabilitado, true, `o botão de submeter não saiu \`disabled=\` com texto ${JSON.stringify(vazio)} — cor não é o único sinal`);
        assert.ok(submeter[0].classe.split(/\s+/).includes('bg-pub-seg'), 'o botão de submeter não saiu APAGADO (`CLASSES_SUBMETER_APAGADO`)');

        // ⚠️ E "Cancelar" continua HABILITADO: um gate que lesse o rodapé inteiro
        // por regex seria satisfeito pelo `disabled=` do botão errado.
        const cancelar = botoes.filter((b) => b.rotulo === 'Cancelar');
        assert.equal(cancelar.length, 1, 'o "Cancelar" da casca sumiu');
        assert.equal(cancelar[0].desabilitado, false, 'o "Cancelar" saiu desabilitado — a medição está no irmão errado');
    }

    // ⚠️ ISCA: com texto o botão HABILITA. Sem isto o caso acima passaria com o
    // botão desabilitado para sempre.
    const comTexto = botoesDe(render(MOD_AJUSTE.default, { aberto: true, itens: ITENS, indice: I_OFERTA, texto: 'a poltrona veio torta', onFechar: nada, onSubmeter: nada, onTexto: nada, onItem: nada }));
    const ativo = comTexto.find((b) => b.rotulo === 'Solicitar ajustes');
    assert.equal(ativo.desabilitado, false, 'com texto o botão continuou desabilitado — a sonda está cega');
    assert.ok(ativo.classe.split(/\s+/).includes('bg-pub-accent'), 'com texto o botão não saiu ATIVO (`CLASSES_SUBMETER_ATIVO`)');

    // (c) ⚠️⚠️ PROVA 2 (a que importa): o CLIQUE não chama `onSubmeter`.
    // `renderToStaticMarkup` não dispara `onClick`, então o handler é exercido
    // direto — pelo named export da casca, que é quem desenha o rodapé.
    for (const vazio of ['', '   ', null, {}, 42]) {
        const chamadas = [];
        const el = React.createElement(MOD_AJUSTE.default, {
            aberto: true, itens: ITENS, indice: I_OFERTA, texto: vazio,
            onFechar: nada, onSubmeter: (p) => chamadas.push(p), onTexto: nada, onItem: nada,
        });
        const casca = el.type(el.props);
        assert.ok(casca !== null, 'o modal devolveu `null` com item e aberto — a prova do clique ficaria vazia por construção');
        // O `onSubmeter` que a casca recebeu é o do modal, com a guarda repetida.
        casca.props.onSubmeter();
        assert.deepEqual(chamadas, [], `o clique com texto ${JSON.stringify(vazio)} chamou \`onSubmeter\` — o botão cinza é cosmético, a trava é esta`);
    }

    // ⚠️ ISCA do clique: com texto válido ele CHAMA, com o escopo e o item do
    // servidor. Sem isto a prova acima passaria com `onSubmeter` nunca chamado.
    const chamadas = [];
    const el = React.createElement(MOD_AJUSTE.default, {
        aberto: true, itens: ITENS, indice: I_ULTIMO, texto: 'composicao divergente',
        onFechar: nada, onSubmeter: (p) => chamadas.push(p), onTexto: nada, onItem: nada,
    });
    el.type(el.props).props.onSubmeter();
    assert.deepEqual(
        chamadas,
        [{ escopo: 'product', item: 'Composição do kit', texto: 'composicao divergente' }],
        'o clique válido não mandou o escopo/item/texto do item escolhido',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. OS LITERAIS, CONGELADOS NO GATE
// ═══════════════════════════════════════════════════════════════════════════

/**
 * ⚠️⚠️ A EXPECTATIVA CONGELADA, e ela NÃO é lida do módulo testado.
 *
 * Foi a mutação SOBREVIVENTE da wave 188-10: a asserção comparava o render com o
 * literal **do mesmo módulo** que o renderizou, então trocar o literal mudava os
 * DOIS lados juntos — tautologia, e a resposta de *"o que precisaria estar errado
 * no mundo para este gate falhar?"* era **"nada"**.
 * ⚠️ E ela **não depende do protótipo**, que **não é versionado**: um gate que só
 * vivesse lá voltaria a ser tautológico no dia em que o pacote não estivesse no
 * disco. O protótipo entra como CRUZAMENTO, abaixo.
 */
const LITERAIS_CONGELADOS = {
    TITULO: 'Solicitar ajustes',
    ROTULO_DO_BOTAO: 'Solicitar ajustes',
    ROTULO_DO_ITEM: 'Item',
    ROTULO_DO_DESTINO: 'Encaminhar para',
    ROTULO_DO_COMENTARIO: 'Comentário',
    PLACEHOLDER_DO_COMENTARIO: 'Descreva o que precisa ser corrigido',
    PREFIXO_DA_SUGESTAO: '+ ',
    SEPARADOR_DA_SUGESTAO: ' ',
    SUFIXO_DA_SUGESTAO: '.',
};

test('⚠️⚠️ OS LITERAIS DESTA TELA, CONGELADOS byte a byte 188_11', () => {
    for (const [nome, esperado] of Object.entries(LITERAIS_CONGELADOS)) {
        // ⚠️ IGUALDADE DE STRING, nunca `includes`: ele casa prefixo E sufixo de
        // outro nome, e já mordeu cinco vezes neste projeto.
        assert.equal(MOD_AJUSTE[nome], esperado, `o literal \`${nome}\` mudou — se foi de propósito, remeça a expectativa CONGELADA aqui e declare`);
    }

    // ⚠️ ISCA DO QUASE-IGUAL: a sonda tem de reprovar o sinônimo e o quase-igual.
    assert.notEqual(MOD_AJUSTE.ROTULO_DO_DESTINO, 'Encaminhar para:', 'a sonda aceita o quase-igual com dois-pontos');
    assert.notEqual(MOD_AJUSTE.PLACEHOLDER_DO_COMENTARIO, 'Descreva o que precisa ser corrigido.', 'a sonda aceita o quase-igual com ponto');
    assert.notEqual(MOD_AJUSTE.PREFIXO_DA_SUGESTAO, '+', 'a sonda aceita o `+` sem o espaço');

    // E eles aparecem NA TELA (sonda NÃO-CEGA antes de qualquer conclusão).
    const html = render(MOD_AJUSTE.default, { aberto: true, itens: ITENS, indice: I_OFERTA, texto: 'x', onFechar: nada, onSubmeter: nada, onTexto: nada, onItem: nada });
    const visivel = texto(html);
    assert.ok(visivel.length > 50, 'o HTML saiu curto demais — a leitura está cega');
    for (const literal of ['Solicitar ajustes', 'Item', 'Encaminhar para', 'Comentário']) {
        assert.ok(visivel.includes(literal), `o literal "${literal}" não está na tela`);
    }
    assert.match(html, /placeholder="Descreva o que precisa ser corrigido"/, 'o placeholder de `:792` sumiu');
    assert.match(html, /rows="3"/, 'o `rows="3"` de `:792` sumiu');

    // ⚠️⚠️ E NENHUM texto de "exceção aguardando autorização": a decisão 6 do
    // usuário diz que esse estado NÃO EXISTE (o gate completo é da tarefa 2).
    assert.equal(/aguardando autoriza/i.test(visivel), false, 'apareceu "aguardando autorização" — esse estado NÃO EXISTE (decisão 6)');
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. A GEOMETRIA — HTML por ÁRVORE COM ÍNDICE ABSOLUTO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ FID-01: a geometria do corpo, por IGUALDADE DE CONJUNTO e índice ABSOLUTO 188_11', () => {
    const html = render(MOD_AJUSTE.CorpoDoAjuste, { itens: ITENS, indice: I_CONJUNTO, texto: 'x', onItem: nada, onTexto: nada });
    const els = tagsDeAbertura(html);

    // ⚠️ A ÁRVORE, conferida pela TAG antes de medir qualquer classe: `els[0]`
    // mede o irmão errado quando a ordem muda em silêncio.
    assert.deepEqual(
        els.map((e) => e.tag),
        ['div', 'label', 'select', 'option', 'option', 'option', 'option', 'option', 'option', 'option', 'option', 'option',
            'div', 'span', 'span', 'span', 'label', 'textarea', 'div', 'button', 'button'],
        'a árvore do corpo mudou de forma — remeça os índices ABSOLUTOS abaixo antes de confiar em qualquer medida',
    );

    const classes = (indice) => classesDe(els[indice]);

    // `:783` — o `<label>` de Item.
    assert.deepEqual([...classes(1)].sort(), ['flex', 'flex-col', 'gap-[6px]', 'text-[12.5px]', 'text-pub-txt-2'], 'o `<label>` de `:783` divergiu');

    // `:784` — o `<select>`.
    assert.deepEqual(
        [...classes(2)].sort(),
        ['bg-pub-sunken', 'border', 'border-pub-line-strong', 'h-[36px]', 'outline-none', 'px-[10px]', 'rounded-[8px]', 'text-[13px]', 'text-pub-txt'].sort(),
        'o `<select>` de `:784` divergiu',
    );

    // `:788` — a caixa de destino.
    assert.deepEqual(
        [...classes(12)].sort(),
        ['bg-pub-sunken', 'border', 'border-pub-line-subtle', 'flex', 'gap-[10px]', 'items-center', 'px-[12px]', 'py-[10px]', 'rounded-pub-control', 'text-[12.5px]'].sort(),
        'a caixa "Encaminhar para" de `:788` divergiu',
    );
    assert.deepEqual([...classes(15)].sort(), ['ml-auto', 'text-[11.5px]', 'text-pub-txt-3'].sort(), 'a nota de `:788` perdeu o `margin-left:auto`');

    // `:792` — o `<textarea>`.
    assert.deepEqual(
        [...classes(17)].sort(),
        ['bg-pub-sunken', 'border', 'border-pub-line-strong', 'leading-[1.45]', 'outline-none', 'px-[12px]', 'py-[10px]', 'resize-y', 'rounded-[8px]', 'text-[13px]', 'text-pub-txt'].sort(),
        'o `<textarea>` de `:792` divergiu',
    );

    // `:794` — o wrapper com `flex-wrap:wrap` (que a tabela do plano OMITIA) e o chip.
    assert.deepEqual([...classes(18)].sort(), ['flex', 'flex-wrap', 'gap-[6px]'].sort(), 'o wrapper dos chips de `:794` perdeu o `flex-wrap`');
    assert.deepEqual(
        [...classes(19)].sort(),
        ['bg-pub-sunken', 'border', 'border-pub-line-strong', 'cursor-pointer', 'h-[26px]', 'px-[9px]', 'rounded-full', 'text-[11.5px]', 'text-pub-fase1', 'whitespace-nowrap'].sort(),
        'o chip de sugestão de `:794` divergiu — `cursor:pointer` e `white-space:nowrap` são as DUAS omissões da prescrição original',
    );

    // ⚠️ `h-[26px]` × `min-h-[26px]`: o segundo CONTÉM o primeiro, e a prova é nas
    // duas direções.
    assert.ok(classes(19).has('h-[26px]'), 'o chip perdeu a altura de 26px');
    assert.equal(classes(19).has('min-h-[26px]'), false, 'o chip ganhou `min-h-[26px]`: `includes` não distingue os dois, a igualdade de CONJUNTO sim');
});

// ═══════════════════════════════════════════════════════════════════════════
// 9. O MODAL NÃO ESCREVE
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ CRITÉRIO 17: o modal de ajuste NÃO escreve — nem `router.post`, nem `axios` 188_11', () => {
    const ESCRITAS = [/router\s*\.\s*post\(/, /router\s*\.\s*put\(/, /router\s*\.\s*delete\(/, /axios\s*\.\s*(post|put|delete)\(/, /fetch\(/];
    const infratores = [];
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        for (const re of ESCRITAS) {
            if (re.test(fonte)) infratores.push(`${rel} ~ ${re}`);
        }
    }
    assert.deepEqual(infratores, [], 'o modal escreve por conta própria — `versao_esperada` é OPCIONAL no corpo, e omiti-la LIBERA a escrita: é "sobrescrever em silêncio"');

    // ⚠️ ISCA da sonda, nas duas direções: ela ACHA a forma com espaços (que é a
    // fuga óbvia) e ACHA no arquivo que legitimamente escreve.
    assert.equal(ESCRITAS[0].test('router . post(x)'), true, 'a sonda não tolera espaço: `router . post(` escaparia dela');
    assert.ok(ESCRITAS.some((re) => re.test(lerSemComentarios(REL_PAINEL))), 'a sonda não acha a escrita nem no arquivo que TEM o helper — ela está cega');

    // E o modal não importa o helper: ele não é o dono da escrita.
    assert.equal(/escreverNoPainel/.test(FONTE_AJUSTE), false, 'o modal passou a chamar o helper de escrita — a escrita é do CHAMADOR, que é quem tem a versão');
});

// ═══════════════════════════════════════════════════════════════════════════
// 10. A TELA PRETA — RENDER ADVERSO E NO CLIQUE
// ═══════════════════════════════════════════════════════════════════════════

/**
 * ⚠️⚠️ As formas que já derrubaram esta tela SEIS vezes, a última NO CLIQUE.
 *
 * `{toString: null}` faz `String(valor)` **LANÇAR**; `hasOwnProperty` sobre chave
 * não-string também; `typeof NaN === 'number'`; e **prop de NÓ chegando objeto
 * comum faz o React LANÇAR** e mata a página.
 */
const FORMAS_ADVERSAS = [
    null, undefined, 0, 1, -1, NaN, Infinity, '', '   ', 'texto', true, false,
    {}, [], [{}], { toString: null }, { valueOf: null }, () => {},
];

const SEM_OBJETO_ESTRANHO = /\[object Object\]|NaN|undefined|Invalid Date/;

test('⚠️⚠️ REND / critério 19: nenhum campo adverso derruba o modal de ajuste, nem no CLIQUE 188_11', () => {
    const CAMPOS = ['rotulo', 'escopo', 'destino', 'nota_do_escopo', 'sugestoes'];

    for (const campo of CAMPOS) {
        for (const forma of FORMAS_ADVERSAS) {
            const itens = [{ ...ITEM_IMAGEM_1, [campo]: forma }, ...SETE_FIXOS];
            let html = '';
            assert.doesNotThrow(
                () => { html = render(MOD_AJUSTE.default, { aberto: true, itens, indice: 0, texto: 'x', onFechar: nada, onSubmeter: nada, onTexto: nada, onItem: nada }); },
                // ⚠️ NUNCA `String()`/`JSON.stringify()` na mensagem: eles LANÇAM
                // com `{toString: null}` e já mataram 5 blocos DENTRO da mensagem.
                `o campo \`${campo}\` derrubou o render`,
            );
            assert.doesNotMatch(html, SEM_OBJETO_ESTRANHO, `o campo \`${campo}\` vazou para a tela`);
        }
    }

    // A lista inteira, e a prop inteira.
    for (const forma of FORMAS_ADVERSAS) {
        assert.doesNotThrow(() => render(MOD_AJUSTE.default, { aberto: true, itens: forma, indice: forma, texto: forma, onFechar: forma, onSubmeter: forma, onTexto: forma, onItem: forma }), 'a prop inteira adversa derrubou o render');
        assert.doesNotThrow(() => render(MOD_AJUSTE.CorpoDoAjuste, { itens: forma, indice: forma, texto: forma, onItem: forma, onTexto: forma }), 'a prop inteira adversa derrubou o corpo');
    }

    // ⚠️⚠️ E TAMBÉM NO CLIQUE: `renderToStaticMarkup` **não dispara `onClick`**.
    for (const forma of FORMAS_ADVERSAS) {
        const itens = [{ ...ITEM_IMAGEM_1, sugestoes: [forma, 'Cor diferente da original'] }, ...SETE_FIXOS];
        const el = React.createElement(MOD_AJUSTE.CorpoDoAjuste, { itens, indice: 0, texto: forma, onItem: nada, onTexto: nada });
        assert.doesNotThrow(() => {
            const arvore = el.type(el.props);
            const faixa = arvore.props.children.find((f) => f && f.props && f.props.className === MOD_AJUSTE.CLASSES_FAIXA_DE_SUGESTOES);
            if (!faixa) return;
            for (const chip of faixa.props.children.filter((c) => c !== null)) chip.props.onClick();
        }, 'o CLIQUE num chip com sugestão adversa derrubou a página');

        // ⚠️ E o `onChange` do select e do textarea, com evento adverso.
        const el2 = React.createElement(MOD_AJUSTE.CorpoDoAjuste, { itens: ITENS, indice: 0, texto: 'x', onItem: nada, onTexto: nada });
        const arvore2 = el2.type(el2.props);
        const rotuloDoItem = arvore2.props.children[0];
        assert.doesNotThrow(() => rotuloDoItem.props.children[1].props.onChange({ target: { value: forma } }), 'o `onChange` do select com valor adverso derrubou a página');
    }

    // ⚠️ E o filho OBJETO COMUM, que já derrubou a casca ("Objects are not valid as
    // a React child"): a casca filtra filho a filho, e esta é a prova de que ela
    // continua filtrando com o nosso corpo no meio.
    assert.doesNotThrow(() => renderToStaticMarkup(React.createElement(MOD_CASCA.default, {
        aberto: true, titulo: 'x', rotuloDoBotao: 'y', onFechar: nada, onSubmeter: nada,
        children: [{}, React.createElement(MOD_AJUSTE.CorpoDoAjuste, { itens: ITENS, indice: 0, texto: '', onItem: nada, onTexto: nada })],
    })), 'objeto comum no meio dos filhos derrubou a casca');
});

// ═══════════════════════════════════════════════════════════════════════════
// 11. O ROLLUP, E O BUNDLE DE VERDADE
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ ROLLUP: nenhuma flag de escopo do componente é lida dentro dos dois `.map()` 188_11', () => {
    // ⚠️ O Rollup ELIMINA variável de escopo do componente usada dentro de `.map()`
    // e o bundle sai com `ReferenceError` — ⚠️ com TODO teste de render passando no
    // módulo não empacotado. A correção medida na 187-09: a expressão do `.map()`
    // só pode ler **parâmetro** ou nome de **MÓDULO**.
    const mapas = [...FONTE_AJUSTE.matchAll(/\.map\(\(([^)]*)\)\s*=>\s*\{([\s\S]*?)\n\s{20,}\}\)/g)];
    assert.ok(mapas.length >= 2, `não achei os DOIS \`.map()\` deste arquivo (achei ${mapas.length}) — a leitura está cega`);

    // O bundle DE VERDADE renderiza: é a prova que o Rollup/esbuild não elidiu nada.
    const html = render(MOD_AJUSTE.CorpoDoAjuste, { itens: ITENS, indice: I_CONJUNTO, texto: '', onItem: nada, onTexto: nada });
    assert.ok(html.includes('<option'), 'o bundle não renderizou as opções');
    assert.ok(html.includes('<button'), 'o bundle não renderizou os chips');
});

// ═══════════════════════════════════════════════════════════════════════════
// 12. TERRITÓRIO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ TERRITÓRIO: o recorte CONSTANTE deste plano, com isca do recorte 188_11', () => {
    const VETADOS = [
        [/localStorage/, 'localStorage é artefato do protótipo (`KEY = ecf-aprovacao-v1`)'],
        [/Restaurar dados de exemplo/, 'texto de protótipo'],
        [/\bString\(/, '`String(` LANÇA com `{toString:null}` — há gate da 187-04 proibindo'],
        [/JSON\.stringify\(/, '`JSON.stringify` mente e lança'],
        [/#[0-9A-Fa-f]{6}\b/, 'TOK-15: hex literal no código'],
        [/\btext-(sm|xs|base|lg|xl)\b/, 'FID-03: escala nomeada em vez de `text-[Npx]`'],
        [/\becf-(bg|card|yellow)\b/, 'FID-04: token `ecf-*` vetado'],
        [/font-display/, 'FID-04: `font-display` vetado'],
        [/border-white\/\[0\.08\]/, 'FID-04: a borda translúcida vetada'],
        [/\b(sm|md|lg|xl|2xl):/, 'FID-05: breakpoint'],
        [/Ampliar/, 'a Fase 189 (lightbox "⤢ Ampliar") NÃO é antecipada'],
        [/Gerar novamente com IA/, 'a Fase 192 NÃO é antecipada'],
        [/confirmar publica|segundo passo|cancelar aprova|desfazer/i, 'decisão 1: aprovar JÁ PUBLICA, sem segundo clique e sem desfazer'],
        [/lucro l[íi]quido/i, 'vocabulário travado: "Resultado estimado por venda", nunca "lucro líquido"'],
        [/duration-\[\d+ms\]/, '`duration-[Nms]` NÃO COMPILA neste projeto'],
    ];

    const infratores = [];
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        for (const [re, razao] of VETADOS) {
            if (re.test(fonte)) infratores.push(`${rel} ~ ${razao}`);
        }
    }
    assert.deepEqual(infratores, [], 'apareceu forma VETADA nos arquivos deste plano');

    // ⚠️ ISCA DO RECORTE, nas duas direções: cada regex tem de casar um caso que
    // ela DEVE pegar. Sem isto um erro de escrita na regex deixaria o gate cego.
    const ISCAS = [
        'localStorage.getItem(1)', 'Restaurar dados de exemplo', 'String(x)', 'JSON.stringify(x)',
        '#FACC15', 'text-sm', 'ecf-yellow', 'font-display', 'border-white/[0.08]', 'md:flex',
        'Ampliar', 'Gerar novamente com IA', 'confirmar publicação', 'lucro líquido', 'duration-[180ms]',
    ];
    assert.equal(ISCAS.length, VETADOS.length, 'a lista de iscas e a de vetos divergiram — cada regex precisa do seu caso');
    const cegas = VETADOS.filter(([re], i) => !re.test(ISCAS[i])).map(([, razao]) => razao);
    assert.deepEqual(cegas, [], 'regex que não casa o próprio caso de teste — gate cego');

    // ⚠️ E o recorte é CONSTANTE: varrer `app/` ou `resources/` inteiro barrou um
    // deploy na Fase 186 (`lucro_liquido` é campo LEGÍTIMO do Portal).
    assert.deepEqual(FONTES_DO_PLANO.map(([rel]) => rel), [REL_AJUSTE], 'o recorte deste gate deixou de ser a lista CONSTANTE deste plano');

    // ⚠️ Peça nova sob `resources/` nunca é `.js`: o `content` do `tailwind.config.js`
    // varre `*.jsx` e **NÃO** `*.js`, e a classe num `.js` não gera CSS — com render
    // verde. ⚠️ O invariante CERTO é "nenhuma PEÇA nova sob `resources/`", e não
    // "nenhum arquivo novo" (há 9 `.js`, TODOS testes).
    assert.ok(FONTES_DO_PLANO.every(([rel]) => !rel.startsWith('resources/') || rel.endsWith('.jsx')), 'peça nova sob `resources/` saiu como `.js`');

    // T-188-SC — este plano não instala nada.
    for (const rel of ['package.json', 'package-lock.json']) {
        assert.equal(numstatDe(rel).trim(), '', `${rel} foi alterado — este plano NÃO instala nada`);
    }

    // Os INTOCÁVEIS.
    for (const rel of [REL_GATE_ZINDEX, REL_GATE_MEUS_ANUNCIOS, `${DIR_APROV}/vocabularioDoPortao.jsx`, `${DIR_APROV}/andamentoDoPortao.jsx`, `${DIR_APROV}/SeloDeAndamentoInterno.jsx`, `${DIR_APROV}/BarraDeMargem.jsx`]) {
        assert.equal(numstatDe(rel).trim(), '', `${rel} é INTOCÁVEL e foi alterado`);
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 13. AS LISTAS CONSTANTES — AS DUAS ESCRITURAÇÕES
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ VOC-07: o `.jsx` desta tarefa está nas DUAS listas, e o manifesto tem o id 188_11', () => {
    // (a) `ARQUIVOS_NOVOS` — fora dela o GATE-03 passa POR OMISSÃO, e o `deepEqual`
    // de `DIRETORIOS_FECHADOS` reprova na hora (comportamento certo).
    const vocabulario = lerSemComentarios(REL_GATE_VOCABULARIO);
    const bloco = vocabulario.match(/const\s+ARQUIVOS_NOVOS\s*=\s*\[([\s\S]*?)\n\];/);
    assert.ok(bloco, 'não achei o literal de `ARQUIVOS_NOVOS` — a leitura está cega');
    const declarados = [...bloco[1].matchAll(/\$\{DIR_APROV\}\/([\w.-]+\.jsx)/g)].map((m) => m[1]);
    assert.ok(declarados.length > 0, '`ARQUIVOS_NOVOS` não declara nenhum `.jsx` de `aprovacao/` — a leitura está cega');
    assert.ok(declarados.includes('ModalDeAjuste.jsx'), '`ModalDeAjuste.jsx` ficou FORA de `ARQUIVOS_NOVOS` — o GATE-03 passaria por OMISSÃO');

    // (b) `JSX_DEPOIS_DA_187` — ⚠️ são DUAS escriturações e isso é DELIBERADO: o
    // conserto da família RECUSOU criar uma terceira.
    const fechamento = lerSemComentarios(REL_GATE_FECHAMENTO);
    const bloco2 = fechamento.match(/const\s+JSX_DEPOIS_DA_187\s*=\s*\[([\s\S]*?)\n\];/);
    assert.ok(bloco2, 'não achei o literal de `JSX_DEPOIS_DA_187` — a leitura está cega');
    const depois = [...bloco2[1].matchAll(/\$\{DIR_APROV\}\/([\w.-]+\.jsx)/g)].map((m) => m[1]);
    assert.ok(depois.length > 0, '`JSX_DEPOIS_DA_187` saiu vazia — a leitura está cega');
    assert.ok(depois.includes('ModalDeAjuste.jsx'), '`ModalDeAjuste.jsx` ficou FORA de `JSX_DEPOIS_DA_187` — o conjunto do diretório divergiria');

    // (c) O manifesto declara o id desta tarefa, e **um id por ARQUIVO de gate**:
    // dois ids sobre o mesmo arquivo dariam a MESMA contagem duas vezes e nenhum
    // mediria a sua metade (gate decorativo por construção, medido na 187-08).
    const manifesto = fs.readFileSync(path.resolve(RAIZ, REL_MANIFESTO), 'utf8');
    const linhas = manifesto.split(/\r?\n/).filter((l) => l.trim() !== '' && !l.trim().startsWith('#'));
    assert.ok(linhas.length > 0, 'o manifesto não tem nenhuma linha de gate — a leitura está cega');
    const esteArquivo = 'tests/js/publicador-painel-modais-a-188.test.js';
    const minhas = linhas.filter((l) => l.includes(esteArquivo));
    assert.equal(minhas.length, 1, `o manifesto declara ${minhas.length} ids para ${esteArquivo} — é UM id por arquivo de gate`);
    assert.match(minhas[0], /^modal-de-ajuste\s+js\s+/, 'o id deste arquivo de gate deixou de ser `modal-de-ajuste`');
    assert.match(minhas[0], /::\s*tests=\d+/, 'a linha do manifesto perdeu a contagem MEDIDA');

    // (d) ⚠️ `vocabulario-tamanhos` em LINHA PRÓPRIA, com UM arquivo só.
    const tamanhos = linhas.filter((l) => l.startsWith('vocabulario-tamanhos'));
    assert.equal(tamanhos.length, 1, '`vocabulario-tamanhos` não está em linha própria no manifesto — sem a linha o harness ABORTA');
    assert.match(tamanhos[0], /tests=>=\d+/, 'a forma de `vocabulario-tamanhos` deixou de ser `>=` — a contagem CRESCE por construção');
    const arquivosDoId = tamanhos[0].split('::')[0].trim().split(/\s+/).slice(2);
    assert.deepEqual(arquivosDoId, [REL_GATE_VOCABULARIO], 'o id `vocabulario-tamanhos` passou a rodar OUTROS arquivos — foi o id COMPOSTO que fez a wave 187-05 reprovar dois ids alheios');

    // (e) ⚠️ BIJEÇÃO id ↔ `<verify>`, nas DUAS direções, com o EXPANSOR DE
    // ALTERNÂNCIA (`/test_[A-Za-z0-9_]+/` NÃO casa `test_(a|b)` — furo que deu
    // `SEM_ID=14` acusando trabalho CORRETO).
    const plano = fs.readFileSync(path.resolve(RAIZ, REL_PLANO), 'utf8');
    const idsDoManifesto = linhas.map((l) => l.trim().split(/\s+/)[0]);
    assert.equal(new Set(idsDoManifesto).size, idsDoManifesto.length, 'id REPETIDO no manifesto — o harness pega o PRIMEIRO e o segundo nunca roda');
    const idsDoVerify = [...plano.matchAll(/verificar-testes\.sh\s+188-11\s+([\w-]+)/g)].map((m) => m[1]);
    assert.ok(idsDoVerify.length > 0, 'não achei nenhum `<verify>` com `188-11` no plano — a leitura está cega');
    const orfaosNoManifesto = idsDoManifesto.filter((id) => !idsDoVerify.includes(id));
    assert.deepEqual(orfaosNoManifesto, [], 'id declarado no manifesto e que NENHUM `<verify>` roda — ele não falha: ele simplesmente nunca roda');
    const semDeclaracao = idsDoVerify.filter((id) => !idsDoManifesto.includes(id));
    assert.deepEqual(semDeclaracao, [], 'id usado por um `<verify>` e não declarado no manifesto — o harness ABORTA');

    // (f) ⚠️ E TODO nome de teste deste arquivo termina em `188_11`: nome genérico
    // COLIDE no `--filter`, que SOMA as contagens.
    const esteGate = fs.readFileSync(path.resolve(RAIZ, esteArquivo), 'utf8');
    const nomes = [...esteGate.matchAll(/^test\('([^']*)'/gm)].map((m) => m[1]);
    assert.ok(nomes.length > 0, 'não achei os nomes de teste deste arquivo — a leitura está cega');
    const semSufixo = nomes.filter((n) => !n.endsWith('188_11'));
    assert.deepEqual(semSufixo, [], 'nome de teste sem o sufixo `188_11`');

    // (g) ⚠️ Nenhum id `php` neste manifesto — então a colisão de HOMÔNIMO do
    // `--filter` (regex NÃO ANCORADO) não tem onde acontecer. Declarado, não
    // presumido: se um dia entrar, esta asserção é o aviso.
    assert.deepEqual(linhas.filter((l) => /\s+php\s+/.test(l)), [], 'entrou id `php` no manifesto — agora a conferência de homônimo por script é obrigatória');
});

// ═══════════════════════════════════════════════════════════════════════════
// 14. O PROTÓTIPO — ⚠️ `skip` EXPLÍCITO e NOMEADO se o pacote não está no disco
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ FID-01: os ponteiros de `:782`-`:794` e de `:1397`, reconferidos por LEITURA DA LINHA 188_11', async (contexto) => {
    const abs = path.resolve(RAIZ, PROTOTIPO);
    if (!fs.existsSync(abs)) {
        // ⚠️ `skip` com razão ESCRITA (e `skipped N` no sumário), nunca `return`
        // calado: `design_handoff_publicador/` **não é versionado**.
        contexto.skip(`${PROTOTIPO} ausente — o pacote de handoff não é versionado`);

        return;
    }

    const linhas = fs.readFileSync(abs, 'utf8').split(/\r?\n/);
    const em = (n) => linhas[n - 1] ?? '';

    await contexto.test('o arquivo é o MEDIDO (1432 linhas), e o diretório é o certo', () => {
        // ⚠️ `find . -maxdepth 3 -name Aprovacao.dc.html` devolve SÓ
        // `./design_handoff_publicador/Aprovacao.dc.html` — há duas cópias do
        // pacote de handoff na árvore, com conteúdo DIFERENTE.
        assert.equal(linhas.length, 1432 + 1, `o protótipo mudou de tamanho (${linhas.length - 1} linhas) — remeça TODOS os ponteiros`);
    });

    await contexto.test('⚠️ os ponteiros do HTML do MOD-01 (`:783`, `:784`, `:788`, `:791`/`:792`, `:794`)', () => {
        assert.match(em(782), /isAdjust/, '`:782` não é o `<sc-if>` do MOD-01');
        assert.match(em(783), />Item$/, '`:783` não é o `<label>` de Item');
        assert.match(em(784), /<select/, '`:784` não é o `<select>` de item');
        assert.match(em(788), /border-radius:9px;background:#0D0D0D/, '`:788` não é a caixa "Encaminhar para"');
        assert.match(em(791), />Comentário$/, '`:791` não é o `<label>` de Comentário');
        assert.match(em(792), /rows="3"/, '`:792` não é o `<textarea>` — a §F.1 já corrigia `:791` → `:792`');
        assert.match(em(792), /placeholder="Descreva o que precisa ser corrigido"/, 'o placeholder de `:792` mudou');
        assert.match(em(794), /flex-wrap:wrap/, '`:794` não é o wrapper dos chips com `flex-wrap`');
    });

    await contexto.test('⚠️⚠️ `destColor`/`destNote` e a concatenação estão em `:1397`, NÃO em `:1396`', () => {
        // ⚠️ A §F.1 do FID01 aponta `:1396`; a wave 188-06 mediu `:1397`, e esta
        // wave CONFIRMA: `:1396` é a ABERTURA `if (k === 'adjust')`.
        assert.match(em(1396), /if \(k === 'adjust'\)/, '`:1396` deixou de ser a abertura do ramo `adjust`');
        assert.match(em(1397), /destColor: it\.scope === 'prod' \? '#FBBF24' : '#D4D4D8'/, '`:1397` não tem o `destColor` — o ponteiro se moveu');
        assert.match(em(1397), /\(F\.text \? F\.text \+ ' ' : ''\) \+ t \+ '\.'/, '`:1397` não tem a concatenação COM o ponto');
        assert.match(em(1397), /destNote: it\.scope === 'prod'/, '`:1397` não tem o `destNote`');
        assert.match(em(1395), /adjust: 'Solicitar ajustes'/, '`:1395` não tem o rótulo do botão');
        assert.match(em(1193), /if \(!\(F\.text \|\| ''\)\.trim\(\)\) return;/, '`:1193` não é a guarda de texto vazio');
    });

    await contexto.test('⚠️⚠️ SÃO SETE FIXOS: `:1183`-`:1189`, com `:1181` sendo o `.map()` das imagens', () => {
        assert.match(em(1181), /ord\.map\(\(id, i\)/, '`:1181` não é o `.map()` das imagens');
        assert.match(em(1182), /return it\.concat\(\[/, '`:1182` não é o `concat` dos fixos');
        assert.match(em(1183), /label: 'Preço ' \+ this\.modName\(o\)/, '`:1183` não é o primeiro fixo');
        assert.match(em(1189), /set\.units > 1 \? 'Composição do kit' : 'Categoria'/, '`:1189` não é o SÉTIMO fixo, o que ALTERNA');
        assert.match(em(1190), /^\s*\]\);\s*$/, '`:1190` deixou de FECHAR o `concat` — então não são sete');
        // ⚠️ A contagem literal: 7 entradas entre `:1183` e `:1189`.
        const fixos = linhas.slice(1182, 1189).filter((l) => /\{ label:/.test(l));
        assert.equal(fixos.length, 7, `o protótipo tem ${fixos.length} itens fixos, não 7 — remeça o FID01 e o serviço JUNTOS`);
    });

    await contexto.test('⚠️ `cursor` / `white-space` / `text-overflow`, em COLUNAS PRÓPRIAS', () => {
        // ⚠️ Varredura INDEPENDENTE da faixa, por propriedade — a tabela erra por
        // OMISSÃO (`cursor:pointer` onze vezes numa fase; `white-space:nowrap`
        // sete na 187, TRÊS como PRIMEIRA propriedade da linha).
        const faixa = [782, 783, 784, 788, 791, 792, 794];
        const comCursor = faixa.filter((n) => /cursor:pointer/.test(em(n)));
        const comNowrap = faixa.filter((n) => /white-space:nowrap/.test(em(n)));
        const comEllipsis = faixa.filter((n) => /text-overflow:ellipsis/.test(em(n)));
        assert.deepEqual(comCursor, [794], `\`cursor:pointer\` apareceu em [${comCursor.join(', ')}] — o esperado é SÓ o chip de \`:794\``);
        assert.deepEqual(comNowrap, [794], `\`white-space:nowrap\` apareceu em [${comNowrap.join(', ')}] — o esperado é SÓ o chip de \`:794\``);
        assert.deepEqual(comEllipsis, [], '`text-overflow:ellipsis` apareceu no MOD-01 — ele não existe em nenhum dos sete elementos');
        // ⚠️ E `white-space:nowrap` é a PRIMEIRA propriedade do `<button>` do chip,
        // que é exatamente a posição em que a Fase 187 o perdeu três vezes.
        assert.match(em(794), /<button[^>]*style="white-space:nowrap;/, 'o `white-space:nowrap` saiu da PRIMEIRA posição do chip');
    });

    await contexto.test('⚠️ CRUZAMENTO: os literais do MÓDULO aparecem no protótipo', () => {
        // ⚠️ Este é o CRUZAMENTO, não a fonte: a expectativa congelada mora no
        // bloco de literais, que roda mesmo sem o pacote de handoff no disco.
        assert.ok(em(783).includes(MOD_AJUSTE.ROTULO_DO_ITEM), 'o rótulo `Item` do módulo não casa `:783`');
        assert.ok(em(791).includes(MOD_AJUSTE.ROTULO_DO_COMENTARIO), 'o rótulo `Comentário` do módulo não casa `:791`');
        assert.ok(em(792).includes(MOD_AJUSTE.PLACEHOLDER_DO_COMENTARIO), 'o placeholder do módulo não casa `:792`');
        assert.ok(em(1395).includes(MOD_AJUSTE.ROTULO_DO_BOTAO), 'o rótulo do botão do módulo não casa `:1395`');
        assert.ok(em(788).includes('Encaminhar para') || em(789).includes('Encaminhar para'), 'o rótulo "Encaminhar para" não está em `:788`/`:789`');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 15. O CSS CONSTRUÍDO — ⚠️ com as OITO normalizações e as iscas MEDIDAS
// ═══════════════════════════════════════════════════════════════════════════

/** Escapa TODOS os metacaracteres de regex. */
function escapaRegex(texto_) {
    return texto_.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * O seletor que o Tailwind emite. ⚠️ A 8ª normalização: o escape é **GERAL**
 * (tudo que não é `[A-Za-z0-9_-]`), senão classe com `(`/`,`/`[`/`/` sai AUSENTE
 * estando presente.
 */
function seletorDe(classe) {
    return `.${[...classe].map((ch) => (/[A-Za-z0-9_-]/.test(ch) ? ch : `\\${ch}`)).join('')}`;
}

/**
 * ⚠️ É esta guarda de `mtime` que produz os DOIS REGIMES de contagem do
 * manifesto: mutar uma fonte a deixa mais nova que o CSS e a camada cai em
 * `skip`. ⚠️ `cp` ATUALIZA o `mtime`, então `npm run build` vem DEPOIS da mutação
 * (`cp -p` preserva, e aí o regime pristino volta sem build).
 */
const FONTES_DO_CSS = [REL_AJUSTE];

function carregarCssConstruido() {
    const manifestRel = 'public/build/manifest.json';
    const manifestAbs = path.resolve(RAIZ, manifestRel);
    if (!fs.existsSync(manifestAbs)) return { motivo: `${manifestRel} ausente — rode \`npm run build\`` };

    // ⚠️ Manifest SEMPRE pelo JSON, nunca por grep: o hash sai com HÍFEN
    // (`assets/app--5yuaubs.js`) e o grep ingênuo não casa.
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

test('⚠️ FID: as classes do MOD-01 GERAM CSS com o valor certo 188_11', async (contexto) => {
    if (CSS.motivo) {
        contexto.skip(CSS.motivo);

        return;
    }

    const regraDe = (classe) => {
        const re = new RegExp(`${escapaRegex(seletorDe(classe))}(?![\\w\\\\-])[^{}]*\\{([^}]*)\\}`);
        const m = CSS.css.match(re);

        return m ? m[1] : null;
    };

    await contexto.test('o CSS lido é o do manifest, e a PÁGINA está no manifest', () => {
        assert.ok(CSS.css.length > 100000, `CSS suspeito de truncado: ${CSS.css.length} bytes`);
        const tem = (k) => Object.prototype.hasOwnProperty.call(CSS.manifest, k);
        assert.ok(tem('resources/js/Pages/Mlb/Publicador/Aprovacao.jsx'), 'a página saiu do manifest — a tela desaparece sem erro nenhum');
        assert.equal(tem('resources/js/Pages/Mlb/NaoExiste9j2.jsx'), false, 'a isca do manifest virou entrada de verdade');
    });

    await contexto.test('⚠️ ISCA: a sonda reporta AUSENTE o que não existe, e ACHA o que existe', () => {
        // ⚠️⚠️ Isca tem de ser classe inexistente no projeto INTEIRO. `grid-cols-6`
        // e `left-[37.5%]` PARECIAM inventadas e saíram PRESENTES na 188-08 — a
        // segunda **gerada por um COMENTÁRIO** (o scanner do Tailwind lê comentário).
        for (const isca of ['bg-pub-isca-188-11', 'text-[93.5px]', 'w-[1978px]', 'top-[62.5%]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS.css.includes(isca), `a string da isca ${isca} aparece no CSS`);
        }
        assert.notEqual(regraDe('text-[12.5px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('⚠️⚠️ `duration-[Nms]` continua NÃO COMPILANDO, e `duration-200` compila', () => {
        assert.equal(regraDe('duration-[180ms]'), null, '`duration-[180ms]` passou a compilar — remeça a regra');
        assert.match(regraDe('duration-200'), /transition-duration:\s*\.?2s/, 'o CONTROLE da escala nomeada sumiu');
    });

    await contexto.test('⚠️⚠️ a GEOMETRIA do MOD-01, com as OITO normalizações', () => {
        // ⚠️ 1ª/2ª — token SÓLIDO não compila para hex: sai `rgb(R G B / var(…))`.
        assert.match(regraDe('bg-pub-sunken'), /rgb\(13 13 13/, '`bg-pub-sunken` não é #0D0D0D (`:784`/`:788`/`:792`)');
        assert.match(regraDe('border-pub-line-strong'), /rgb\(46 46 46/, '`border-pub-line-strong` não é #2E2E2E');
        assert.match(regraDe('border-pub-line-subtle'), /rgb\(31 31 31/, '`border-pub-line-subtle` não é #1F1F1F (`:788`)');
        assert.match(regraDe('text-pub-txt'), /rgb\(244 244 245/, '`text-pub-txt` não é #F4F4F5');
        assert.match(regraDe('text-pub-txt-2'), /rgb\(161 161 170/, '`text-pub-txt-2` não é #A1A1AA (`:783`)');
        assert.match(regraDe('text-pub-txt-3'), /rgb\(138 138 144/, '`text-pub-txt-3` não é #8A8A90 (a nota de `:788`)');
        assert.match(regraDe('text-pub-txt-4'), /rgb\(113 113 122/, '`text-pub-txt-4` não é #71717A (o rótulo de `:788`)');
        assert.match(regraDe('text-pub-fase1'), /rgb\(212 212 216/, '`text-pub-fase1` não é #D4D4D8 (`:1397`, o destino sem âmbar)');
        assert.match(regraDe('text-pub-warn-txt'), /rgb\(251 191 36/, '`text-pub-warn-txt` não é #FBBF24 (`:1397`, o âmbar do produto)');

        // ⚠️ `font-pub` é a FAMÍLIA, e é ela que o portal não herda.
        assert.match(regraDe('font-pub'), /Geist/, '`font-pub` deixou de resolver a Geist — o modal cairia em Inter no portal');

        // O resto das medidas, numa asserção COLETANDO os infratores.
        const semRegra = [
            'rounded-[8px]', 'rounded-pub-control', 'rounded-full',
            'flex', 'flex-col', 'flex-wrap', 'items-center', 'border', 'resize-y',
            'cursor-pointer', 'whitespace-nowrap', 'font-semibold', 'ml-auto', 'outline-none',
            'h-[36px]', 'h-[26px]', 'px-[10px]', 'px-[12px]', 'px-[9px]', 'py-[10px]',
            'gap-[6px]', 'gap-[10px]', 'gap-[14px]',
            'text-[11.5px]', 'text-[12.5px]', 'text-[13px]', 'leading-[1.45]',
        ].filter((c) => regraDe(c) === null);
        assert.deepEqual(semRegra, [], 'classe do MOD-01 NÃO gerou CSS — num `.js` isso aconteceria com TODO teste de render passando');
    });
});
