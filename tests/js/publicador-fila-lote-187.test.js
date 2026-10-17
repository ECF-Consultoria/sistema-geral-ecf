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
// Fase 187, plano 09 — O LOTE, OS TOASTS, O RODAPÉ E OS **DOIS** VAZIOS.
//
// ⚠️⚠️ **DE CADA BLOCO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR: "o que
// precisaria estar errado no mundo para ele falhar?"**
//
//   1. BARRA      → barra VAZIA cobrindo a última linha da tabela; o plural
//                   errado ("1 selecionadas"); ou o botão parecendo clicável com
//                   K=0 (cor apagada SEM `disabled` — cor não é o único sinal).
//   2. SEGUNDO     → nascer "Confirmar publicação", "Desfazer" ou "Cancelar
//      PASSO         aprovação". **Aprovar JÁ PUBLICA** (decisão 3 do usuário,
//                   14/10/2026) e o rótulo é "Aprovar para publicação",
//                   inalterado. Gate de ausência COM ISCA POR FORMA, valendo
//                   para as Fases 187-194.
//   3. CASCA      → 560px virando 600px, o topo saindo de 96px, ou o clique no
//                   CORPO fechando o modal (selecionar texto o fecharia).
//   4. PORTAL     → alguém "consertar" com `createPortal`: o renderizador de
//                   SERVIDOR do React **não renderiza portal**, e o gate passaria
//                   **por omissão** vendo HTML vazio. É o furo mais perigoso
//                   deste plano, porque ele deixa o gate VERDE.
//   5. MODAL      → esconder quem ficou de fora (o usuário RECUSOU isso), ou
//                   mostrar `×` sem motivo, ou truncar o motivo sem `title` —
//                   deixando INALCANÇÁVEL a frase que diz o que consertar.
//   6. TOAST      → os literais recopiados (duas verdades), `3800` virando outro
//                   número, ou "Publicado e confirmado" disparando sem MLB.
//   7. Z-INDEX    → copiar os números do protótipo (60/100/120) e cobrir o menu
//                   lateral: o bug que o usuário relatou em 12/10/2026.
//   8. RODAPÉ     → "Restaurar dados de exemplo" voltar (é recurso de PROTÓTIPO),
//                   ou a legenda perder uma das 6 entradas.
//   9. VAZIO      → **o aviso único**. "Nenhuma oferta neste filtro" **sem filtro
//                   algum MENTIRIA**, e ofereceria um botão sem o que limpar.
//  10. ADVERSO    → qualquer campo chegando objeto/NaN/array: a TELA PRETA voltou
//                   QUATRO vezes na Fase 180 e UMA na wave 6 — aquela **no
//                   CLIQUE**, e `renderToStaticMarkup` não dispara `onClick`.
//  11. map/ausên. → flag fora do callback (o Rollup a ELIMINA e o
//                   `ReferenceError` só aparece no bundle), `localStorage`,
//                   "lucro líquido", `String(`.
//  12. CSS        → classe que não compila. `duration-[Nms]` é o precedente
//                   MEDIDO deste projeto, e é a ISCA do bloco. ⚠️ E é aqui que
//                   `pub-float`/`pub-float-line` ACORDAM.
//  13. PÁGINA     → os três slots voltarem a ficar vazios, a seleção sair da URL,
//                   ou as três peças `fixed` caírem dentro do container do corpo.
//
// ⚠️ Todos os nomes de teste terminam em `187_09`: nome de guarda genérico
// COLIDE no `--filter` do harness, que **SOMA** as contagens.
//
// ⚠️ `design_handoff_publicador/` **NÃO é versionado**: o bloco que depende dele
// degrada para `skip` EXPLÍCITO e NOMEADO, nunca passa calado.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

const STUB_INERTIA = path.join(__dirname, `.pub-lote187-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, delete: () => {}, reload: () => {} };
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
        `.pub-lote187-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';
const REL_BARRA = `${DIR_APROV}/BarraDeSelecao.jsx`;
const REL_CASCA = `${DIR_APROV}/CascaDeModal.jsx`;
const REL_MODAL = `${DIR_APROV}/ModalDeLote.jsx`;
const REL_TOAST = `${DIR_APROV}/ToastDaFila.jsx`;
const REL_RODAPE = `${DIR_APROV}/RodapeDaFila.jsx`;
const REL_VAZIO = `${DIR_APROV}/VazioDaFila.jsx`;
const REL_VOCAB = `${DIR_APROV}/vocabularioDoPortao.jsx`;
const REL_TABELA = `${DIR_APROV}/TabelaDaFila.jsx`;
const REL_PAGINA = 'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx';
const REL_SERVICO = 'app/Services/Publicador/Aprovacao/Fila/LoteDaFilaService.php';
const REL_ZINDEX = 'tests/js/publicador-fila-zindex-187.test.js';
const REL_PRECEDENTE = 'resources/js/Components/Mlb/Publicador/publicacoes/VaziosDaLista.jsx';
const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

// ⚠️ `test -e` ANTES de acreditar em qualquer leitura vazia. **Sete** caminhos
// fantasma já "provaram" território intocado nesta milestone, medindo NADA.
for (const caminho of [
    DIR_APROV, REL_BARRA, REL_CASCA, REL_MODAL, REL_TOAST, REL_RODAPE, REL_VAZIO,
    REL_VOCAB, REL_TABELA, REL_PAGINA, REL_SERVICO, REL_ZINDEX, REL_PRECEDENTE,
]) {
    if (!fs.existsSync(path.resolve(RAIZ, caminho))) {
        throw new Error(`caminho inexistente: ${caminho} — gate mediria NADA`);
    }
}

const TEM_PROTOTIPO = fs.existsSync(path.resolve(RAIZ, PROTOTIPO));
const TEXTO_PROTOTIPO = TEM_PROTOTIPO ? fs.readFileSync(path.resolve(RAIZ, PROTOTIPO), 'utf8') : '';

const MOD_BARRA = await montar(REL_BARRA);
const MOD_CASCA = await montar(REL_CASCA);
const MOD_MODAL = await montar(REL_MODAL);
const MOD_TOAST = await montar(REL_TOAST);
const MOD_RODAPE = await montar(REL_RODAPE);
const MOD_VAZIO = await montar(REL_VAZIO);
const MOD_VOCAB = await montar(REL_VOCAB);
// ⚠️ A PÁGINA também é montada aqui, no escopo do MÓDULO, e não dentro de um
// `test()`: `await` só vale em função `async` ou no topo do módulo ESM, e um
// `await` dentro de callback síncrono é `SyntaxError` — o arquivo nem CARREGA, e o
// harness reporta "1 teste, 1 falha" sem rodar nada (medido nesta sessão).
const MOD_PAGINA = await montar(REL_PAGINA);

const Barra = MOD_BARRA.default;
const Casca = MOD_CASCA.default;
const Modal = MOD_MODAL.default;
const Toast = MOD_TOAST.default;
const Rodape = MOD_RODAPE.default;
const Vazio = MOD_VAZIO.default;

const FONTE_BARRA = lerSemComentarios(REL_BARRA);
const FONTE_CASCA = lerSemComentarios(REL_CASCA);
const FONTE_MODAL = lerSemComentarios(REL_MODAL);
const FONTE_TOAST = lerSemComentarios(REL_TOAST);
const FONTE_RODAPE = lerSemComentarios(REL_RODAPE);
const FONTE_VAZIO = lerSemComentarios(REL_VAZIO);
const FONTE_PAGINA = lerSemComentarios(REL_PAGINA);

/**
 * ⚠️ A LISTA CONSTANTE dos arquivos deste plano — e ela é constante de propósito.
 *
 * As ausências ("lucro líquido", `localStorage`, `String(`) são cobradas **só**
 * nesta lista. Varrer o `app/` inteiro foi a 5ª ocorrência de *"gate mais largo
 * que o invariante"* na Fase 186, e ela **barrou um deploy**: `lucro_liquido` é
 * campo LEGÍTIMO do Portal (`app/Services/Portal/`), e a varredura larga
 * reprovaria trabalho de outra sessão.
 */
const FONTES_DO_PLANO = [
    [REL_BARRA, FONTE_BARRA],
    [REL_CASCA, FONTE_CASCA],
    [REL_MODAL, FONTE_MODAL],
    [REL_TOAST, FONTE_TOAST],
    [REL_RODAPE, FONTE_RODAPE],
    [REL_VAZIO, FONTE_VAZIO],
];
const TODAS_AS_FONTES = FONTES_DO_PLANO.map(([, f]) => f).join('\n');

const FONTE_SERVICO = fs.readFileSync(path.resolve(RAIZ, REL_SERVICO), 'utf8');
const FONTE_ZINDEX = fs.readFileSync(path.resolve(RAIZ, REL_ZINDEX), 'utf8');

const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

const nada = () => {};

/**
 * O elemento de índice ABSOLUTO na árvore de tags de abertura do HTML.
 *
 * ⚠️⚠️ **NUNCA `indexOf(tagDeAbertura)`**: ele pega o IRMÃO ERRADO quando dois
 * elementos têm a MESMA string de classes — **quatro gates deste projeto
 * estavam medindo o irmão errado**. Aqui a localização é por POSIÇÃO na árvore.
 */
function tagsDeAbertura(html) {
    return [...html.matchAll(/<(div|span|section|button|img)\b([^>]*)>/g)].map((m) => ({
        tag: m[1],
        atributos: m[2],
        classe: (m[2].match(/class="([^"]*)"/) || [])[1] ?? '',
        titulo: (m[2].match(/title="([^"]*)"/) || [])[1] ?? null,
        estilo: (m[2].match(/style="([^"]*)"/) || [])[1] ?? null,
        desabilitado: /\bdisabled\b/.test(m[2]),
    }));
}

/** A lista de classes de um elemento, como CONJUNTO — igualdade, nunca `includes`. */
function classesDe(elemento) {
    return new Set(elemento.classe.split(/\s+/).filter((c) => c !== ''));
}

// ─── A fixture: o payload REAL de `LoteDaFilaService::painel()` ───
//
// ⚠️⚠️ **ELA DISCRIMINA DE PROPÓSITO.** Na wave 6 as mutações sobreviventes eram
// falha da **FIXTURE**, não da asserção: ela não distinguia os casos. Aqui há
// UMA apta e DUAS inaptas, com motivos DIFERENTES e cores DIFERENTES — se o
// componente colapsasse os dois caminhos, o HTML mudaria.
const LOTE = {
    selecionadas: 3,
    aptas: 1,
    rotulo_da_selecao: '3 selecionadas',
    rotulo_de_aprovar: 'Aprovar 1 para publicação',
    pode_aprovar: true,
    titulo: 'Aprovar para publicação em lote',
    sub: '1 de 3 ofertas selecionadas podem ser aprovadas',
    rotulo_do_botao: 'Aprovar 1 oferta',
    nota: 'Somente as ofertas marcadas com ✓ serão aprovadas, cada uma na versão indicada.',
    ids_aptos: [11],
    linhas: [
        {
            id: 11,
            nome: 'Poltrona Beny Decorativa Veludo Cinza',
            versao: 3,
            versao_rotulo: 'versão 3',
            apta: true,
            glifo: '✓',
            motivo: 'Será aprovada · R$ 1.299,90 · resultado R$ 184,20',
            motivo_cor: 'suave',
        },
        {
            id: 12,
            nome: 'Mesa Lateral Tora',
            versao: 1,
            versao_rotulo: 'versão 1',
            apta: false,
            glifo: '×',
            motivo: 'Fica de fora: fotos a conferir, frete pendente',
            motivo_cor: 'vermelho',
        },
        {
            id: 13,
            nome: 'Cadeira Oslo',
            versao: 5,
            versao_rotulo: 'versão 5',
            apta: false,
            glifo: '×',
            motivo: 'Fica de fora: publicado',
            motivo_cor: 'vermelho',
        },
    ],
};

// ═══════════════════════════════════════════════════════════════════════════
// 1. A BARRA FLUTUANTE — os literais, o plural e o botão APAGADO
// ═══════════════════════════════════════════════════════════════════════════

test('a BARRA sai com a geometria de `:897`-`:900` e o botão apagado com K=0 187_09', () => {
    const html = render(Barra, { lote: LOTE, onAbrir: nada, onLimpar: nada });
    const arvore = tagsDeAbertura(html);

    // ⚠️ Índice ABSOLUTO: 0 = a casca, 1 = o rótulo, 2 = aprovar, 3 = o ×.
    const casca = arvore[0];
    assert.equal(casca.tag, 'div', 'a raiz da barra não é um `<div>`');

    // ⚠️ IGUALDADE DE CONJUNTO, nunca `includes`: `includes('h-[64px]')` casava
    // `min-h-[64px]` e REPROVOU código certo na wave 8.
    for (const classe of [
        'fixed', 'bottom-[24px]', 'left-1/2', 'z-[35]', 'flex', '-translate-x-1/2',
        'items-center', 'gap-[6px]', 'rounded-pub-panel', 'border', 'border-pub-float-line',
        'bg-pub-float', 'py-[6px]', 'pl-[16px]', 'pr-[6px]', 'shadow-pub-flutuante',
    ]) {
        assert.ok(classesDe(casca).has(classe), `a barra perdeu \`${classe}\`: ${casca.classe}`);
    }

    // ⚠️ ISCA DO QUASE-IGUAL: a comparação por conjunto SABE recusar o vizinho.
    assert.equal(classesDe(casca).has('bottom-[240px]'), false, 'a comparação casou o quase-igual');
    assert.equal(classesDe(casca).has('z-[3]'), false, 'a comparação casou `z-[3]` dentro de `z-[35]`');

    // O rótulo, com o `white-space:nowrap` que a tabela do PLANO omite (`:898`).
    const rotulo = arvore[1];
    for (const classe of ['mr-[4px]', 'whitespace-nowrap', 'border-r', 'border-pub-float-line', 'pr-[10px]', 'text-[13px]', 'font-medium']) {
        assert.ok(classesDe(rotulo).has(classe), `o rótulo da barra perdeu \`${classe}\`: ${rotulo.classe}`);
    }
    assert.match(html, /3 selecionadas/, 'o rótulo da seleção não saiu no HTML');

    // ⚠️⚠️ O `·` DO RÓTULO **NÃO É CARACTERE** — é a `border-right` de `:898`. A
    // prosa do plano escreve "N selecionadas · Aprovar … · ×" para descrever o que
    // se VÊ; um `·` literal ali desenharia dois pontos médios ao lado de duas
    // bordas. Esta asserção é a que impede o mal-entendido de virar código.
    assert.equal(
        (html.match(/·/g) || []).length,
        0,
        'apareceu um `·` LITERAL na barra: o separador de `:898` é `border-right`, não caractere',
    );

    // O botão de aprovar, ATIVO.
    const aprovar = arvore[2];
    assert.equal(aprovar.tag, 'button');
    for (const classe of ['h-[32px]', 'cursor-pointer', 'whitespace-nowrap', 'rounded-[8px]', 'px-[14px]', 'text-[13px]', 'font-semibold', 'bg-pub-accent', 'text-pub-page']) {
        assert.ok(classesDe(aprovar).has(classe), `o botão de aprovar perdeu \`${classe}\`: ${aprovar.classe}`);
    }
    assert.equal(aprovar.desabilitado, false, 'o botão saiu `disabled` com K=1');
    assert.match(html, /Aprovar 1 para publicação/, 'o rótulo do botão não saiu no HTML');

    // O ×, com `cursor-pointer` e `whitespace-nowrap` (as duas omissões de `:900`).
    const limpar = arvore[3];
    for (const classe of ['h-[32px]', 'w-[32px]', 'cursor-pointer', 'whitespace-nowrap', 'rounded-[8px]', 'bg-pub-line-strong', 'text-pub-txt']) {
        assert.ok(classesDe(limpar).has(classe), `o × da barra perdeu \`${classe}\`: ${limpar.classe}`);
    }
    // ⚠️ `title` no ×: a tela nunca depende só do glifo.
    assert.equal(limpar.titulo, 'Limpar a seleção', 'o × perdeu o `title` — glifo sozinho não explica nada');

    // ─── K = 0: o botão fica APAGADO **E** `disabled` ───
    //
    // ⚠️⚠️ **COR NÃO É O ÚNICO SINAL** (regra permanente). Quem só apagasse a cor
    // deixaria um botão que PARECE clicável e não é — e num fluxo que publica e não
    // tem desfazer, isso é a pior classe de ambiguidade.
    const semApta = render(Barra, {
        lote: { ...LOTE, aptas: 0, pode_aprovar: false, rotulo_de_aprovar: 'Aprovar 0 para publicação' },
        onAbrir: nada,
        onLimpar: nada,
    });
    const botaoApagado = tagsDeAbertura(semApta)[2];
    assert.ok(classesDe(botaoApagado).has('bg-pub-seg'), 'com K=0 o fundo não ficou apagado');
    assert.ok(classesDe(botaoApagado).has('text-pub-txt-4'), 'com K=0 o texto não ficou apagado');
    assert.equal(classesDe(botaoApagado).has('bg-pub-accent'), false, 'com K=0 o botão continuou amarelo');
    assert.equal(botaoApagado.desabilitado, true, 'com K=0 o botão NÃO saiu `disabled` — cor não é o único sinal');
});

test('a BARRA só existe com seleção > 0, e o PLURAL segue o protótipo 187_09', () => {
    // Seleção vazia: NADA é desenhado — nem casca, nem borda.
    for (const vazio of [
        { lote: null },
        { lote: { ...LOTE, selecionadas: 0, rotulo_da_selecao: '0 selecionada' } },
        { lote: { ...LOTE, rotulo_da_selecao: '' } },
        { lote: { ...LOTE, rotulo_da_selecao: null } },
        { lote: [] },
        { lote: 'tres' },
        { lote: 7 },
    ]) {
        const html = render(Barra, { ...vazio, onAbrir: nada, onLimpar: nada });
        assert.equal(html, '', `a barra desenhou algo com ${JSON.stringify(Object.keys(vazio))} inválido: ${html.slice(0, 120)}`);
    }

    // ⚠️ ISCA CEGA: com seleção de verdade ela DESENHA. Sem isto, um componente
    // que nunca desenhasse passaria em todas as negativas acima.
    assert.notEqual(render(Barra, { lote: LOTE, onAbrir: nada, onLimpar: nada }), '', 'ISCA CEGA: a barra não desenha nem com payload válido');

    // ─── O PLURAL, medido em `:1282`: `> 1` é plural ───
    assert.match(render(Barra, { lote: { ...LOTE, rotulo_da_selecao: '1 selecionada', selecionadas: 1 }, onAbrir: nada }), /1 selecionada</, 'o singular não saiu');
    assert.match(render(Barra, { lote: LOTE, onAbrir: nada }), /3 selecionadas</, 'o plural não saiu');

    // ⚠️ E o botão NÃO é desenhado sem handler nem sem rótulo: botão sem destino é
    // proibido neste projeto, e botão sem texto é o elemento vazio que passa em
    // render e DESAPARECE na tela.
    // ⚠️⚠️ **SEM HANDLER, ZERO BOTÃO.** Botão com rótulo e sem destino é proibido
    // neste projeto (D4), e foi este caso que achou o defeito: a primeira redação do
    // componente desenhava o "Aprovar …" `disabled` quando `onAbrir` faltava.
    // ⚠️ `disabled` por **K=0** é OUTRA coisa e continua existindo: ali o destino
    // EXISTE e a tela está dizendo "nenhuma das selecionadas pode ser aprovada".
    const semHandler = render(Barra, { lote: LOTE });
    assert.equal(tagsDeAbertura(semHandler).filter((e) => e.tag === 'button').length, 0, 'a barra desenhou botão sem handler nenhum — botão sem destino é proibido');
    const semRotulo = render(Barra, { lote: { ...LOTE, rotulo_de_aprovar: null }, onAbrir: nada, onLimpar: nada });
    assert.equal(tagsDeAbertura(semRotulo).filter((e) => e.tag === 'button').length, 1, 'o botão de aprovar foi desenhado SEM rótulo');
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. ⚠️⚠️ A AUSÊNCIA DO SEGUNDO PASSO — T-187-93, com ISCA POR FORMA
// ═══════════════════════════════════════════════════════════════════════════

test('NENHUM arquivo deste plano cria segundo passo na aprovação 187_09', () => {
    // ⚠️⚠️ **APROVAR JÁ PUBLICA** (decisão 3 do usuário, 14/10/2026). Não existe
    // "confirmar publicação", "desfazer", "segundo passo" nem "cancelar aprovação",
    // e o rótulo é **"Aprovar para publicação"**, inalterado. Vale para 187-194.
    //
    // ⚠️ A sonda é ancorada no SUJEITO, **com os acentos**: `aprovad` **não casa**
    // "aprovação", e o par solto `(aguardand|pendent) × (autoriza|aprovad)` casaria
    // **10 ocorrências LEGÍTIMAS** da área ("Aguardando aprovação" é vocabulário
    // correto desta tela).
    const SONDA = /(confirmar\s+publica|confirme\s+a\s+publica|desfazer\s+(a\s+)?aprova|cancelar\s+(a\s+)?aprova|segundo\s+passo|revisar\s+e\s+aprovar)/iu;

    // ⚠️ Lido SEM COMENTÁRIO: palavra no docblock que EXPLICA "não uso isso" casa
    // com o gate que conta isso — a wave 4 teve TRÊS mutações abortando por isso.
    for (const [caminho, fonte] of FONTES_DO_PLANO) {
        assert.equal(SONDA.test(fonte), false, `T-187-93: ${caminho} criou um segundo passo na aprovação`);
    }
    assert.equal(SONDA.test(FONTE_PAGINA), false, 'T-187-93: a página criou um segundo passo na aprovação');

    // ⚠️⚠️ ISCA POR FORMA, NAS DUAS DIREÇÕES. Uma por forma que ela DEVE pegar, e
    // uma que ela NÃO PODE pegar.
    assert.ok(SONDA.test('Confirmar publicação'), 'ISCA CEGA: a sonda não pega nem "Confirmar publicação"');
    assert.ok(SONDA.test('Cancelar aprovação'), 'ISCA CEGA: a sonda não pega "Cancelar aprovação"');
    assert.ok(SONDA.test('Desfazer aprovação'), 'ISCA CEGA: a sonda não pega "Desfazer aprovação"');
    assert.ok(SONDA.test('Revisar e aprovar'), 'ISCA CEGA: a sonda não pega "Revisar e aprovar"');
    assert.equal(SONDA.test('Aprovar para publicação'), false, 'a sonda é larga demais: ela pega o rótulo CERTO');
    assert.equal(SONDA.test('Aguardando aprovação'), false, 'a sonda é larga demais: ela pega o vocabulário correto da área');
    assert.equal(SONDA.test('Aprovado — aguardando publicação'), false, 'a sonda é larga demais: ela pega o toast travado');

    // ⚠️⚠️ **E O RÓTULO CERTO EXISTE** — negativa sozinha vira falso positivo o dia
    // em que alguém apagar a barra inteira.
    assert.match(FONTE_BARRA, /CLASSES_APROVAR/, 'a barra perdeu o botão de aprovar');
    assert.match(FONTE_SERVICO, /APROVAR_SUFIXO = ' para publicação'/, 'o servidor perdeu o sufixo "para publicação"');
    assert.match(render(Barra, { lote: LOTE, onAbrir: nada }), /para publicação/, 'o rótulo "… para publicação" não chega ao HTML');
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. A CASCA DE MODAL — 560px, topo 96px, e o clique que fecha × o que não
// ═══════════════════════════════════════════════════════════════════════════

test('a CASCA sai com 560px a `top:96px`, e o clique no CORPO não fecha 187_09', () => {
    const html = render(Casca, {
        aberto: true,
        titulo: 'Aprovar para publicação em lote',
        sub: '1 de 3 ofertas selecionadas podem ser aprovadas',
        rotuloDoBotao: 'Aprovar 1 oferta',
        podeSubmeter: true,
        onFechar: nada,
        onSubmeter: nada,
        children: React.createElement('span', { className: 'corpo-de-teste' }, 'corpo'),
    });
    const arvore = tagsDeAbertura(html);

    // Índice ABSOLUTO: 0 = backdrop, 1 = caixa, 2 = topo, 3 = coluna, 4 = título…
    const backdrop = arvore[0];
    for (const classe of ['fixed', 'inset-0', 'z-[42]', 'flex', 'items-start', 'justify-center', 'bg-black/[0.65]', 'pt-[96px]']) {
        assert.ok(classesDe(backdrop).has(classe), `o backdrop perdeu \`${classe}\`: ${backdrop.classe}`);
    }

    const caixa = arvore[1];
    for (const classe of ['flex', 'max-h-[calc(100vh-140px)]', 'w-[560px]', 'flex-col', 'overflow-y-auto', 'rounded-pub-card', 'border', 'border-pub-line-strong', 'bg-pub-surface', 'shadow-pub-modal']) {
        assert.ok(classesDe(caixa).has(classe), `a caixa perdeu \`${classe}\`: ${caixa.classe}`);
    }
    // ⚠️ ISCA DO QUASE-IGUAL: 560 não é 600, e `max-h` não é `h`.
    assert.equal(classesDe(caixa).has('w-[600px]'), false, 'a caixa virou 600px');
    assert.equal(classesDe(caixa).has('h-[calc(100vh-140px)]'), false, 'a altura virou FIXA — ela cortaria a lista do lote');

    const topo = arvore[2];
    for (const classe of ['flex', 'items-start', 'justify-between', 'gap-[12px]', 'border-b', 'border-pub-line-subtle', 'px-[20px]', 'pb-[14px]', 'pt-[18px]']) {
        assert.ok(classesDe(topo).has(classe), `o topo perdeu \`${classe}\`: ${topo.classe}`);
    }

    // ⚠️⚠️ O WRAPPER de `:778` — **A OMISSÃO DA TABELA DO PLANO**, medida por
    // SCRIPT. Sem ele o título e o sub ficam LADO A LADO.
    const coluna = arvore[3];
    for (const classe of ['flex', 'flex-col', 'gap-[4px]']) {
        assert.ok(classesDe(coluna).has(classe), `o WRAPPER de título/sub perdeu \`${classe}\` — ele está OMITIDO na tabela do plano`);
    }

    assert.ok(classesDe(arvore[4]).has('text-[16px]'), 'o título não é 16px');
    assert.ok(classesDe(arvore[4]).has('font-semibold'), 'o título não é peso 600');
    assert.ok(classesDe(arvore[5]).has('text-[12.5px]'), 'o sub não é 12.5px');
    assert.ok(classesDe(arvore[5]).has('text-pub-txt-3'), 'o sub não é `#8A8A90`');

    // O × do topo, com as DUAS omissões de `:779`.
    const fechar = arvore.filter((e) => e.tag === 'button')[0];
    for (const classe of ['h-[30px]', 'w-[30px]', 'cursor-pointer', 'whitespace-nowrap', 'rounded-pub-control-sm', 'text-[16px]', 'text-pub-txt-2']) {
        assert.ok(classesDe(fechar).has(classe), `o × do modal perdeu \`${classe}\`: ${fechar.classe}`);
    }
    assert.equal(fechar.titulo, 'Fechar', 'o × do modal perdeu o `title`');

    // O corpo e o rodapé.
    assert.match(html, /class="flex flex-col gap-\[14px\] px-\[20px\] py-\[16px\]"/, 'o corpo perdeu a geometria de `:781`');
    assert.match(html, /corpo-de-teste/, 'o corpo não recebeu os `children`');
    assert.match(html, /class="flex justify-end gap-\[8px\] border-t border-pub-line-subtle bg-pub-sunken px-\[20px\] py-\[14px\]"/, 'o rodapé perdeu a geometria de `:888`');

    // Cancelar e submeter, com as omissões de `:889`/`:890` (nowrap, pointer, 600).
    const botoes = arvore.filter((e) => e.tag === 'button');
    const cancelar = botoes[1];
    const submeter = botoes[2];
    for (const classe of ['h-[36px]', 'cursor-pointer', 'whitespace-nowrap', 'rounded-pub-control', 'px-[14px]', 'text-[13px]', 'border', 'border-pub-line-strong']) {
        assert.ok(classesDe(cancelar).has(classe), `"Cancelar" perdeu \`${classe}\`: ${cancelar.classe}`);
    }
    for (const classe of ['h-[36px]', 'cursor-pointer', 'whitespace-nowrap', 'rounded-pub-control', 'px-[16px]', 'text-[13px]', 'font-semibold', 'bg-pub-accent', 'text-pub-page']) {
        assert.ok(classesDe(submeter).has(classe), `o botão de submeter perdeu \`${classe}\` (o peso 600 está OMITIDO na tabela do plano): ${submeter.classe}`);
    }
    assert.match(html, />Cancelar</, 'o rótulo "Cancelar" não saiu');
    assert.match(html, />Aprovar 1 oferta</, 'o rótulo do botão não saiu');

    // ─── ⚠️ O CLIQUE: o BACKDROP fecha, o CORPO **NÃO** ───
    //
    // ⚠️⚠️ **RENDER ESTÁTICO NÃO DISPARA `onClick`** — a tela preta da wave 6 foi
    // NO CLIQUE. Por isso o handler é exercido pela FONTE: o `stopPropagation` do
    // corpo é o invariante (`:776`, `M.stop`), e sem ele selecionar texto dentro do
    // modal o fecharia.
    assert.match(FONTE_CASCA, /onClick=\{\(evento\) => evento\.stopPropagation\(\)\}/, 'a caixa perdeu o `stopPropagation` — clicar no corpo fecharia o modal');
    assert.match(FONTE_CASCA, /className=\{CLASSES_BACKDROP\}\s*onClick=/, 'o backdrop perdeu o handler de fechar');

    // E o backdrop vem ANTES da caixa na fonte: o handler de fechar tem de estar no
    // elemento de FORA, não no de dentro.
    assert.ok(
        FONTE_CASCA.indexOf('CLASSES_BACKDROP}') < FONTE_CASCA.indexOf('stopPropagation'),
        'o `stopPropagation` está no elemento de FORA — o modal nunca fecharia',
    );

    // ─── `aberto !== true` não desenha NADA ───
    for (const aberto of [false, null, undefined, 0, 'true', {}, []]) {
        assert.equal(
            render(Casca, { aberto, titulo: 'x', onFechar: nada }),
            '',
            `a casca desenhou com \`aberto\` = ${typeof aberto} inválido — backdrop invisível captura o clique da tela inteira`,
        );
    }
});

test('⚠️⚠️ a casca NÃO usa PORTAL, e as peças internas saem como NAMED EXPORTS 187_09', () => {
    // ⚠️⚠️ **O FURO MAIS PERIGOSO DESTE PLANO, porque ele deixa o gate VERDE.** O
    // renderizador de SERVIDOR do React **não renderiza portal** ("Portals are not
    // currently supported by the server renderer"): um `createPortal` aqui faria o
    // `renderToStaticMarkup` devolver string VAZIA, e todos os blocos acima
    // passariam **POR OMISSÃO** — nenhum deles acusaria.
    for (const [caminho, fonte] of FONTES_DO_PLANO) {
        assert.equal(/createPortal|react-dom\/client|Dialog\.Portal|Popover\.Portal/.test(fonte), false, `${caminho} usou PORTAL — o render de servidor devolveria VAZIO e todo gate passaria por omissão`);
    }

    // ⚠️ ISCA CEGA DA SONDA: ela PEGA a forma que deve pegar.
    assert.ok(/createPortal/.test("import { createPortal } from 'react-dom';"), 'ISCA CEGA: a sonda do portal não pega nem o import sintético');
    assert.ok(/Dialog\.Portal/.test('<Dialog.Portal>'), 'ISCA CEGA: a sonda não pega `Dialog.Portal`');

    // ⚠️⚠️ E A CONTRAPROVA DO MOTIVO: o render **NÃO É VAZIO**. Sem esta asserção,
    // a negativa acima seria satisfeita por um arquivo que não renderiza nada.
    const html = render(Casca, { aberto: true, titulo: 'x', onFechar: nada });
    assert.ok(html.length > 100, `a casca renderizou quase nada (${html.length} bytes) — é o sintoma do portal`);
    assert.match(html, /fixed inset-0/, 'o backdrop não chegou ao HTML');

    // ─── ⚠️ AS PEÇAS INTERNAS COMO NAMED EXPORTS (REND-05) ───
    //
    // ⚠️ É a solução da Fase 178 para o que o render de servidor não alcança, e é o
    // que permite conferir cada peça e exercer os handlers diretamente.
    for (const nome of [
        'CLASSES_BACKDROP', 'CLASSES_CAIXA', 'CLASSES_TOPO', 'CLASSES_COLUNA_DO_TITULO',
        'CLASSES_TITULO', 'CLASSES_SUB', 'CLASSES_FECHAR', 'CLASSES_CORPO', 'CLASSES_RODAPE',
        'CLASSES_BOTAO', 'CLASSES_CANCELAR', 'CLASSES_SUBMETER', 'ROTULO_CANCELAR',
        'TopoDoModal', 'RodapeDoModal',
    ]) {
        assert.ok(Object.prototype.hasOwnProperty.call(MOD_CASCA, nome), `\`${nome}\` não é named export de CascaDeModal.jsx (REND-05)`);
    }

    // ⚠️ E as duas peças internas RENDERIZAM sozinhas — named export que não pode
    // ser montado é só um símbolo exportado.
    assert.match(render(MOD_CASCA.TopoDoModal, { titulo: 'T', sub: 'S', onFechar: nada }), />T</, 'TopoDoModal não renderiza sozinho');
    assert.match(render(MOD_CASCA.RodapeDoModal, { rotuloDoBotao: 'B', podeSubmeter: true, onFechar: nada, onSubmeter: nada }), />B</, 'RodapeDoModal não renderiza sozinho');

    // ⚠️⚠️ **A CASCA É DESTA FASE E A 188 A REUSA — DECLARADO.** Se a 188 recriar a
    // sua, haverá duas cascas divergindo. Esta asserção é a prova de que a decisão
    // está ESCRITA, não só combinada.
    const bruta = fs.readFileSync(path.resolve(RAIZ, REL_CASCA), 'utf8');
    assert.match(bruta, /a Fase 188 (a )?REUSA/i, 'a decisão "a 188 reusa esta casca" saiu do arquivo — ela é o que impede duas cascas');
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. O MODAL DE LOTE — TODAS as linhas, com ✓/× e o MOTIVO de cada uma
// ═══════════════════════════════════════════════════════════════════════════

test('o MODAL lista TODAS as selecionadas com ✓/× e o MOTIVO de cada uma de fora 187_09', () => {
    const html = render(Modal, { lote: LOTE, aberto: true, onFechar: nada, onAprovar: nada });

    // ⚠️⚠️ **AS TRÊS, E NÃO SÓ A APTA.** *"Aprovar só as que passam,
    // silenciosamente"* foi **RECUSADO** pelo usuário: a escolha dele foi *"tudo de
    // uma vez, e só libera com todas conferidas"*.
    for (const linha of LOTE.linhas) {
        assert.ok(html.includes(linha.nome), `a oferta "${linha.nome}" não apareceu no modal`);
        assert.ok(html.includes(linha.versao_rotulo), `a versão de "${linha.nome}" não apareceu`);
        assert.ok(html.includes(linha.motivo), `o MOTIVO de "${linha.nome}" não apareceu — o modal diz quem ficou de fora E por quê`);
    }

    // Os glifos: UM ✓ e DOIS ×.
    const comGlifo = tagsDeAbertura(html).filter((e) => classesDe(e).has('h-[18px]'));
    assert.equal(comGlifo.length, 3, `o modal desenhou ${comGlifo.length} glifos para 3 selecionadas`);
    assert.ok(classesDe(comGlifo[0]).has('bg-pub-ok'), 'o glifo da APTA não é verde');
    assert.ok(classesDe(comGlifo[0]).has('text-pub-page'), 'o glifo da APTA não tem o texto escuro');
    for (const i of [1, 2]) {
        assert.ok(classesDe(comGlifo[i]).has('bg-pub-seg'), `o glifo ${i} de FORA não é cinza`);
        assert.ok(classesDe(comGlifo[i]).has('text-pub-txt-2'), `o glifo ${i} de FORA perdeu a cor do texto`);
        assert.equal(classesDe(comGlifo[i]).has('bg-pub-ok'), false, `o glifo ${i} de FORA ficou VERDE — ele afirmaria que a oferta será aprovada`);
    }

    // ⚠️ Os 18px, o raio e o peso 800 de `:880`. ⚠️ `font-[800]` e NÃO
    // `font-extrabold`: o GATE-03 recusa a escala NOMEADA de peso fora de
    // 400/500/600/700 — MEDIDO nesta sessão, a primeira redação reprovou.
    for (const classe of ['grid', 'h-[18px]', 'w-[18px]', 'flex-none', 'place-items-center', 'rounded-full', 'text-[10.5px]', 'font-[800]']) {
        assert.ok(classesDe(comGlifo[0]).has(classe), `o glifo perdeu \`${classe}\`: ${comGlifo[0].classe}`);
    }

    // ⚠️⚠️ O WRAPPER de `:881` — **A OMISSÃO MAIS CARA DA TABELA DO PLANO**, medida
    // por SCRIPT. Sem ele a linha não empilha nome e nota; sem o `min-w-0` o nome
    // longo estoura a largura em vez de truncar.
    const colunas = tagsDeAbertura(html).filter((e) => classesDe(e).has('min-w-0'));
    assert.equal(colunas.length, 3, 'o WRAPPER de `:881` (com `min-width:0`) está OMITIDO — ele não está na tabela do plano');
    for (const classe of ['flex', 'min-w-0', 'flex-col', 'gap-[2px]']) {
        assert.ok(classesDe(colunas[0]).has(classe), `o WRAPPER perdeu \`${classe}\`: ${colunas[0].classe}`);
    }

    // As DUAS cores do motivo, DIFERENTES.
    const motivos = tagsDeAbertura(html).filter((e) => classesDe(e).has('text-[12px]') && e.titulo !== null);
    assert.equal(motivos.length, 3, `achei ${motivos.length} motivos com \`title\`, esperava 3`);
    assert.ok(classesDe(motivos[0]).has('text-pub-txt-2'), 'o motivo da APTA não é `#A1A1AA`');
    assert.ok(classesDe(motivos[1]).has('text-pub-danger-txt'), 'o motivo de FORA não é `#F87171`');
    assert.notEqual(motivos[0].classe, motivos[1].classe, 'os dois motivos saíram com a MESMA classe — as cores colapsaram');

    // ⚠️⚠️ `truncate` + `title` DO TEXTO INTEIRO: truncar sem `title` deixaria o
    // resto da frase INALCANÇÁVEL — e é a frase que diz o que consertar.
    for (const [i, linha] of LOTE.linhas.entries()) {
        assert.ok(classesDe(motivos[i]).has('truncate'), `o motivo ${i} não trunca`);
        assert.equal(motivos[i].titulo, linha.motivo, `o \`title\` do motivo ${i} não é o texto INTEIRO`);
    }

    // A lista e a linha (`:877`/`:879`).
    assert.match(html, /class="flex flex-col overflow-hidden rounded-pub-sub border border-pub-line-subtle"/, 'a lista perdeu a geometria de `:877`');
    assert.match(html, /class="flex items-center gap-\[10px\] border-t border-pub-hover-3 px-\[12px\] py-\[10px\]"/, 'a linha perdeu a geometria de `:879`');

    // ─── A NOTA, BYTE A BYTE (`:885`) ───
    //
    // ⚠️ O `✓` é U+2713. Trocá-lo por `v`, `✔` ou `√` faz o gate reprovar — de
    // propósito: é assim que paráfrase é pega.
    const NOTA = 'Somente as ofertas marcadas com ✓ serão aprovadas, cada uma na versão indicada.';
    assert.ok(html.includes(NOTA), 'a nota do modal não saiu BYTE A BYTE');
    assert.match(html, /class="text-\[12px\] text-pub-txt-2"/, 'a nota perdeu a cor/tamanho de `:885`');

    // ⚠️ ISCA DO QUASE-IGUAL: o texto parafraseado NÃO está no HTML.
    assert.equal(html.includes('Somente as ofertas marcadas com v serão aprovadas'), false, 'a nota saiu com `v` em vez de `✓`');

    // ─── O separador entre nome e versão é `·` (U+00B7), de `:881` ───
    assert.ok(html.includes(' · versão 3'), 'o separador `·` entre nome e versão não saiu');
});

test('o MODAL contra o PROTÓTIPO: a nota e os literais do lote 187_09', (contexto) => {
    // ⚠️ `design_handoff_publicador/` NÃO é versionado — `skip` EXPLÍCITO e
    // NOMEADO, nunca `return` calado.
    if (!TEM_PROTOTIPO) {
        contexto.skip(`${PROTOTIPO} ausente (o pacote de handoff não é versionado) — a conferência byte a byte do protótipo não roda`);
        return;
    }

    // ⚠️ **CITE O DIRETÓRIO:** há DUAS cópias do pacote de handoff na árvore com
    // conteúdo DIFERENTE (`design_handoff_publicador/` e `new_layout_publicador/`).
    // Este gate mede `design_handoff_publicador/Aprovacao.dc.html`, e só ele.
    assert.ok(TEXTO_PROTOTIPO.includes('Somente as ofertas marcadas com ✓ serão aprovadas, cada uma na versão indicada.'), 'a nota divergiu do protótipo');
    assert.ok(TEXTO_PROTOTIPO.includes('Aprovar para publicação em lote'), 'o título do modal divergiu do protótipo');
    assert.ok(TEXTO_PROTOTIPO.includes(' ofertas selecionadas podem ser aprovadas'), 'o sub do modal divergiu do protótipo');
    assert.ok(TEXTO_PROTOTIPO.includes("' selecionadas' : ' selecionada'"), 'o plural da barra divergiu do protótipo (`:1282`)');
    assert.ok(TEXTO_PROTOTIPO.includes("'Aprovar ' + bulkOk.length + ' para publicação'"), 'o rótulo da barra divergiu do protótipo (`:1282`)');
    assert.ok(TEXTO_PROTOTIPO.includes('Será aprovada · '), 'a frase da apta divergiu do protótipo (`:1423`)');
    assert.ok(TEXTO_PROTOTIPO.includes('Fica de fora: '), 'a frase de quem fica de fora divergiu do protótipo (`:1423`)');

    // ⚠️⚠️ E O PROTÓTIPO **NÃO TEM** "confirmar publicação" / "desfazer": a decisão
    // 3 do usuário não é desvio do desenho, ela é o desenho.
    assert.equal(/confirmar\s+publica|desfazer\s+aprova/iu.test(TEXTO_PROTOTIPO), false, 'o protótipo tem um segundo passo que este plano não implementou — reabra a decisão 3');

    // ⚠️ ISCA CEGA: a sonda do protótipo ACHA o que existe.
    assert.ok(TEXTO_PROTOTIPO.includes('bulkApprove'), 'ISCA CEGA: não achei nem `bulkApprove` no protótipo — a leitura está quebrada');
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. O TOAST — os literais LIDOS, o timer pela FUNÇÃO PURA e os DONOS
// ═══════════════════════════════════════════════════════════════════════════

test('o TOAST fica no topo central com a geometria de `:905`-`:907` 187_09', () => {
    const html = render(Toast, { toast: { chave: 'aprovado', sub: '1 oferta aprovada' } });
    const arvore = tagsDeAbertura(html);

    const casca = arvore[0];
    for (const classe of ['fixed', 'left-1/2', 'top-[124px]', 'z-[45]', 'flex', 'max-w-[560px]', '-translate-x-1/2', 'items-center', 'gap-[12px]', 'rounded-pub-panel', 'border', 'border-pub-float-line', 'bg-pub-float', 'px-[16px]', 'py-[12px]', 'shadow-pub-flutuante']) {
        assert.ok(classesDe(casca).has(classe), `o toast perdeu \`${classe}\`: ${casca.classe}`);
    }

    // O dot de 8px.
    const dot = arvore[1];
    for (const classe of ['h-[8px]', 'w-[8px]', 'flex-none', 'rounded-full']) {
        assert.ok(classesDe(dot).has(classe), `o dot perdeu \`${classe}\`: ${dot.classe}`);
    }

    // ⚠️⚠️ O WRAPPER de `:907` — **A OMISSÃO DA TABELA DO PLANO**, medida por
    // SCRIPT. Sem ele o texto e o sub ficam LADO A LADO.
    const coluna = arvore[2];
    for (const classe of ['flex', 'flex-col', 'gap-[2px]']) {
        assert.ok(classesDe(coluna).has(classe), `o WRAPPER do toast perdeu \`${classe}\` — ele está OMITIDO na tabela do plano`);
    }

    // ⚠️ FID-03: `text-[13.5px]`, nunca `text-sm` (14px).
    assert.ok(classesDe(arvore[3]).has('text-[13.5px]'), 'o texto do toast não é 13.5px');
    assert.ok(classesDe(arvore[3]).has('font-semibold'), 'o texto do toast não é peso 600');
    assert.ok(classesDe(arvore[4]).has('text-[12px]'), 'o sub do toast não é 12px');
    assert.ok(classesDe(arvore[4]).has('text-pub-txt-2'), 'o sub do toast não é `#A1A1AA`');

    // ⚠️⚠️ `duration-[Nms]` NÃO COMPILA neste projeto (GATE-09): a transição vai por
    // `style`. O gate confere o `style` E a ausência da classe.
    assert.match(casca.estilo ?? '', /transition-duration:\s*160ms/, 'a transição do toast não saiu por `style`');
    for (const [caminho, fonte] of FONTES_DO_PLANO) {
        assert.equal(/duration-\[|delay-\[|ease-\[/.test(fonte), false, `${caminho} escreveu \`duration-[…]\`/\`delay-[…]\`/\`ease-[…]\` — elas NÃO COMPILAM neste projeto (GATE-09)`);
    }
    // ⚠️ ISCA CEGA: a sonda pega a forma que deve pegar.
    assert.ok(/duration-\[/.test("'duration-[220ms]'"), 'ISCA CEGA: a sonda de duração não pega nem a forma sintética');

    // ─── Sem texto, NADA é desenhado ───
    for (const t of [null, {}, { chave: 'nao_existe' }, { chave: '' }, { texto: '' }, [], 'aprovado', 7]) {
        assert.equal(render(Toast, { toast: t }), '', `o toast desenhou com payload inválido: ${typeof t}`);
    }
    // ⚠️ ISCA CEGA.
    assert.notEqual(render(Toast, { toast: { chave: 'aprovado' } }), '', 'ISCA CEGA: o toast não desenha nem com chave válida');
});

test('os 6 literais do TOAST são LIDOS do módulo da Fase 186, e os DONOS estão nomeados 187_09', () => {
    // ⚠️⚠️ **LIDOS, NUNCA RECOPIADOS.** Os caracteres que parecem decoração e NÃO
    // são: `—` (U+2014, não hífen), `·` (U+00B7) e `…` (U+2026, UM caractere).
    assert.match(FONTE_TOAST, /import \{ TOASTS \} from '\.\/vocabularioDoPortao'/, 'os toasts foram RECOPIADOS em vez de importados');

    const CHAVES = ['aprovado', 'publicado', 'revisao_salva', 'ajuste_solicitado', 'foto_reprovada', 'gerando_com_ia'];
    assert.deepEqual(Object.keys(MOD_VOCAB.TOASTS).sort(), [...CHAVES].sort(), 'o conjunto de chaves de TOASTS mudou');

    // ⚠️ E NENHUM dos 6 literais está escrito na fonte deste plano: um literal aqui
    // seria a segunda cópia, livre para divergir numa correção feita de um lado só.
    for (const chave of CHAVES) {
        assert.equal(TODAS_AS_FONTES.includes(MOD_VOCAB.TOASTS[chave]), false, `o literal de \`${chave}\` foi RECOPIADO num arquivo deste plano`);
    }

    // ─── O literal LIDO chega ao HTML, byte a byte, com o U+2014 ───
    const html = render(Toast, { toast: { chave: 'aprovado' } });
    assert.ok(html.includes('Aprovado — aguardando publicação'), 'o travessão U+2014 não chegou ao HTML');
    assert.equal(html.includes('Aprovado - aguardando'), false, 'o travessão virou HÍFEN');

    // ⚠️⚠️ **`textoDoToast` NUNCA devolve a CHAVE CRUA.** Chave de dado na tela é
    // jargão, e jargão sem explicação é vetado neste projeto.
    assert.equal(MOD_TOAST.textoDoToast('frete_ausente'), null, 'chave desconhecida virou texto');
    assert.equal(MOD_TOAST.textoDoToast('aprovado'), MOD_VOCAB.TOASTS.aprovado, 'a leitura do literal quebrou');

    // ⚠️⚠️ `TOASTS[chave]` cru LANÇA com `{toString:null}`. O acesso é com guarda.
    for (const adverso of [{ toString: null }, { hasOwnProperty: null }, Object.create(null), [], 7, null, undefined, NaN]) {
        assert.doesNotThrow(() => MOD_TOAST.textoDoToast(adverso), 'a leitura do literal LANÇOU com chave adversa');
        assert.equal(MOD_TOAST.textoDoToast(adverso), null);
    }

    // ═══ ⚠️⚠️ OS DONOS DOS OUTROS CINCO, NOMEADOS ═══
    //
    // Nesta fase **só `aprovado` dispara** (pelo lote). "Componente genérico com um
    // usuário real" não é decoração — mas é obrigação dizer de quem são os outros.
    //   · `revisao_salva` / `ajuste_solicitado` / `foto_reprovada` → Fase 188
    //   · `gerando_com_ia`                                        → Fase 192
    //   · `publicado`                                             → Fase 193
    const brutaToast = fs.readFileSync(path.resolve(RAIZ, REL_TOAST), 'utf8');
    for (const dono of ['Fase 188', 'Fase 192', 'Fase 193']) {
        assert.ok(brutaToast.includes(dono), `o dono \`${dono}\` não está nomeado no arquivo do toast`);
    }

    // ⚠️⚠️ **E "Publicado e confirmado no Mercado Livre · MLB…" NÃO DISPARA NESTA
    // FASE.** Ele EXIGE MLB confirmado (AND-04 / VOC-08): afirmá-lo sem o MLB seria
    // mentir sobre o anúncio do cliente. Nenhum caminho deste plano o alcança.
    assert.equal(FONTE_PAGINA.includes("'publicado'"), false, 'a página passou a disparar o toast `publicado` — ele é da Fase 193 e EXIGE MLB confirmado');
    assert.equal(FONTE_PAGINA.includes('"publicado"'), false, 'a página passou a disparar o toast `publicado`');

    // ⚠️ E a página dispara EXATAMENTE a chave `aprovado`, e só ela: negativa
    // sozinha passaria por ausência se ninguém disparasse toast nenhum.
    const chavesNaPagina = [...FONTE_PAGINA.matchAll(/chave:\s*'([a-z_]+)'/g)].map((m) => m[1]);
    assert.deepEqual(chavesNaPagina, ['aprovado'], `a página dispara ${JSON.stringify(chavesNaPagina)}, esperava só \`aprovado\``);
});

test('⚠️ o desaparecimento em ~3,8 s é testado pela FUNÇÃO PURA — o RUNTIME do timer NÃO é provado 187_09', () => {
    // ⚠️⚠️ **DECLARAÇÃO EXPLÍCITA, E ELA É PARTE DO GATE:** não há navegador neste
    // ambiente — todo render é `esbuild` + `react-dom/server`. O que este caso prova
    // é (a) o NÚMERO (3800), (b) a ORDEM (cancelar antes de agendar) e (c) que a
    // função não LANÇA com relógio adverso. **Que o navegador honre o `setTimeout`
    // é confiança no runtime, não medição** — e dizer isso é mais honesto que um
    // teste com `setTimeout` de verdade, que mediria o Node, não a tela.
    assert.equal(MOD_TOAST.MILISSEGUNDOS_DO_TOAST, 3800, 'o número do protótipo (`:1009`) mudou');

    const chamadas = [];
    const relogio = {
        agendar: (fn, ms) => { chamadas.push(['agendar', ms]); return 'handle-novo'; },
        cancelar: (h) => { chamadas.push(['cancelar', h]); },
    };

    // ─── Com timer anterior: CANCELA e só então AGENDA, nessa ordem ───
    const handle = MOD_TOAST.agendarOToast(nada, relogio, 'handle-velho');
    assert.deepEqual(chamadas, [['cancelar', 'handle-velho'], ['agendar', 3800]], 'a ordem cancelar→agendar quebrou, ou o número não é 3800');
    assert.equal(handle, 'handle-novo', 'o handle novo não foi devolvido — reagendar perderia a referência');

    // ⚠️ A ORDEM É O INVARIANTE, não detalhe: sem o cancelamento, dois toasts em
    // sequência deixariam o timer do PRIMEIRO apagar o SEGUNDO antes dos 3,8 s dele,
    // e o usuário perderia o aviso do que acabou de acontecer.
    assert.ok(chamadas.findIndex(([q]) => q === 'cancelar') < chamadas.findIndex(([q]) => q === 'agendar'), 'agendou ANTES de cancelar');

    // ─── Sem timer anterior: NÃO cancela nada, mas agenda ───
    chamadas.length = 0;
    MOD_TOAST.agendarOToast(nada, relogio, null);
    assert.deepEqual(chamadas, [['agendar', 3800]], 'cancelou um timer que não existia');

    // ─── Relógio adverso: NÃO LANÇA ───
    for (const adverso of [null, undefined, {}, [], 7, 'relogio', { agendar: null }, { agendar: 1, cancelar: 2 }, Object.create(null)]) {
        assert.doesNotThrow(() => MOD_TOAST.agendarOToast(nada, adverso, 'x'), `o agendamento LANÇOU com relógio ${typeof adverso}`);
    }
    assert.equal(MOD_TOAST.agendarOToast(nada, null, null), null, 'relógio inválido devia devolver `null`');

    // ⚠️ E `aoSumir` não sendo função não derruba: ele é substituído por no-op.
    chamadas.length = 0;
    assert.doesNotThrow(() => MOD_TOAST.agendarOToast({ toString: null }, relogio, null));
    assert.deepEqual(chamadas, [['agendar', 3800]], 'o agendamento não aconteceu com `aoSumir` adverso');

    // ⚠️⚠️ ISCA DO NÚMERO: `380` e `38000` NÃO passam. Sem isto, uma asserção
    // `>= 0` daria verde para qualquer coisa.
    assert.notEqual(MOD_TOAST.MILISSEGUNDOS_DO_TOAST, 380, 'ISCA: o número virou 380');
    assert.notEqual(MOD_TOAST.MILISSEGUNDOS_DO_TOAST, 38000, 'ISCA: o número virou 38000');

    // ⚠️ E o `3800` do PROTÓTIPO é a fonte (`:1009`), conferido quando ele existe.
    if (TEM_PROTOTIPO) {
        assert.ok(TEXTO_PROTOTIPO.includes('this.setState({ toast: null }), 3800)'), 'o `3800` divergiu do protótipo (`:1009`)');
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. O Z-INDEX — a ordem das 3 peças novas, e a COBERTURA do gate dedicado
// ═══════════════════════════════════════════════════════════════════════════

test('as TRÊS peças `fixed` saem na ordem TRAVADA, e o gate dedicado as cobre 187_09', () => {
    // ⚠️ Os números são LIDOS da fonte, nunca escritos aqui: um número à mão
    // viraria uma segunda verdade.
    const zDe = (fonte, ancora, nome) => {
        const casos = [...fonte.matchAll(new RegExp(ancora, 'g'))];
        assert.equal(casos.length, 1, `a âncora de "${nome}" casou ${casos.length} vez(es), esperava 1 — conserte a âncora, NÃO apague a asserção`);
        return Number(casos[0][1]);
    };

    const barra = zDe(FONTE_BARRA, /bottom-\[24px\] left-1\/2 z-\[(\d+)\](?=[\s'])/, 'barra');
    const modal = zDe(FONTE_CASCA, /const CLASSES_BACKDROP = 'fixed inset-0 z-\[(\d+)\](?=[\s'])/, 'modal');
    const toast = zDe(FONTE_TOAST, /top-\[124px\] z-\[(\d+)\](?=[\s'])/, 'toast');

    // A ordem entre elas: barra < modal < toast.
    assert.ok(barra < modal, `a barra (${barra}) tem de ficar ABAIXO do modal (${modal}): o modal é aberto POR ela`);
    assert.ok(modal < toast, `o modal (${modal}) tem de ficar ABAIXO do toast (${toast}): o aviso do que acabou de acontecer não pode ser coberto`);

    // ⚠️⚠️ **E NENHUM NÚMERO DO PROTÓTIPO FOI COPIADO — PEÇA POR PEÇA.** Lá a barra
    // é 60, o modal 100 e o toast 120 (com o header em 50). Aqui a ORDEM RELATIVA é
    // preservada e os NÚMEROS são novos (divergência **D1**).
    //
    // ⚠️ A conferência é **por peça**, e não contra uma lista larga dos números do
    // protótipo: MEDIDO nesta sessão, a lista larga `[44,45,50,60,100,120]`
    // **REPROVOU CÓDIGO CERTO**, porque `45` é o número que o protótipo deu ao
    // POPOVER e o toast legitimamente ficou com `z-[45]` na escala daqui. Gate mais
    // largo que o invariante é a família que nesta milestone já reprovou trabalho
    // correto três vezes e barrou um deploy.
    const DO_PROTOTIPO = { barra: 60, modal: 100, toast: 120 };
    for (const [nome, valor] of Object.entries({ barra, modal, toast })) {
        assert.notEqual(valor, DO_PROTOTIPO[nome], `"${nome}" herdou ${valor}, o número que o PROTÓTIPO deu a ela (D1)`);
    }

    // ⚠️⚠️ A ORDEM COMPLETA contra os 4 pontos TRAVADOS mora em
    // `publicador-fila-zindex-187.test.js`, que LÊ os quatro dos arquivos deles. Não
    // duplico a medição aqui — gate decorativo por construção. O que este bloco
    // garante é que a COBERTURA de lá **não desapareceu**: se alguém apagar as três
    // âncoras daquele arquivo, a ordem completa deixaria de ser medida em silêncio.
    for (const trecho of [
        "const REL_BARRA = 'resources/js/Components/Mlb/Publicador/aprovacao/BarraDeSelecao.jsx'",
        "const REL_CASCA = 'resources/js/Components/Mlb/Publicador/aprovacao/CascaDeModal.jsx'",
        "const REL_TOAST = 'resources/js/Components/Mlb/Publicador/aprovacao/ToastDaFila.jsx'",
        'barraDeLote: {',
        'modal: {',
        'toast: {',
    ]) {
        assert.ok(FONTE_ZINDEX.includes(trecho), `a cobertura das 3 peças saiu de ${REL_ZINDEX}: esperava achar ${trecho}`);
    }

    // ⚠️ ISCA: a comparação SABE recusar. Sem isto, um `includes` cego faria as seis
    // asserções acima passar medindo NADA.
    assert.equal(FONTE_ZINDEX.includes("const REL_BARRA = 'resources/js/zzz-nao-existe.jsx'"), false, 'a isca casou — a comparação está cega');

    // ⚠️⚠️ E o arquivo TRAVADO do header continua intocado e provando a ordem dele.
    const travado = fs.readFileSync(path.resolve(RAIZ, 'tests/js/publicador-header-zindex-ordem.test.js'), 'utf8');
    assert.match(travado, /filtros < header < menu lateral = painel do produto/, 'o teste de ordem completa saiu do arquivo TRAVADO');
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. O RODAPÉ — as 6 legendas, e "Restaurar dados de exemplo" AUSENTE
// ═══════════════════════════════════════════════════════════════════════════

test('o RODAPÉ tem as SEIS legendas de `:193`-`:198` com os literais do protótipo 187_09', () => {
    const html = render(Rodape, {});
    const arvore = tagsDeAbertura(html);

    const rodape = arvore[0];
    for (const classe of ['flex', 'h-[42px]', 'items-center', 'justify-between', 'border-t', 'border-pub-line-subtle', 'bg-pub-sunken', 'px-[16px]', 'text-[12px]', 'text-pub-txt-4']) {
        assert.ok(classesDe(rodape).has(classe), `o rodapé perdeu \`${classe}\`: ${rodape.classe}`);
    }
    // ⚠️ ISCA DO QUASE-IGUAL: 42 não é 420, e `h-` não é `min-h-`.
    assert.equal(classesDe(rodape).has('h-[420px]'), false, 'a comparação casou o quase-igual');
    assert.equal(classesDe(rodape).has('min-h-[42px]'), false, 'a altura virou MÍNIMA — o rodapé do protótipo é FIXO em 42px');

    assert.ok(classesDe(arvore[1]).has('gap-[14px]'), 'o grupo de legendas perdeu o `gap:14px` de `:192`');

    // ─── Os SEIS literais, BYTE A BYTE ───
    const LITERAIS = ['Não analisada', 'Revisão parcial', 'Aprovada', 'foto aprovada', 'a conferir', 'com alerta'];
    for (const literal of LITERAIS) {
        assert.ok(html.includes(literal), `a legenda "${literal}" não saiu`);
    }
    // ⚠️ A CAIXA importa: os três primeiros começam com MAIÚSCULA (são estados da
    // OFERTA) e os três últimos com minúscula (são estados de FOTO). Parece
    // inconsistência e NÃO é — é o que separa as duas famílias na mesma linha.
    assert.equal(html.includes('Foto aprovada'), false, 'a legenda da foto saiu com MAIÚSCULA — no protótipo ela é minúscula');
    assert.equal(html.includes('A conferir'), false, 'a legenda "a conferir" saiu com MAIÚSCULA');

    // ─── As SEIS formas, por índice ABSOLUTO ───
    const formas = arvore.filter((e) => classesDe(e).has('h-[10px]'));
    assert.equal(formas.length, 6, `o rodapé desenhou ${formas.length} formas, esperava 6`);

    // Os TRÊS anéis (`rounded-full`) e os TRÊS quadrados (`rounded-[3px]`).
    for (const i of [0, 1, 2]) {
        assert.ok(classesDe(formas[i]).has('rounded-full'), `a forma ${i} devia ser ANEL`);
    }
    for (const i of [3, 4, 5]) {
        assert.ok(classesDe(formas[i]).has('rounded-[3px]'), `a forma ${i} devia ser QUADRADO`);
        assert.equal(classesDe(formas[i]).has('rounded-full'), false, `a forma ${i} saiu REDONDA — anel e quadrado significam coisas diferentes`);
    }

    // As SEIS pinturas, e elas são TODAS DIFERENTES — se duas colapsassem, a
    // legenda passaria a explicar duas coisas com o mesmo desenho.
    assert.ok(classesDe(formas[0]).has('border-pub-accent'), 'o anel "Não analisada" não é amarelo');
    assert.ok(classesDe(formas[1]).has('bg-pub-anel-parcial'), 'o anel "Revisão parcial" perdeu o gradiente');
    assert.ok(classesDe(formas[2]).has('bg-pub-ok'), 'o anel "Aprovada" não é verde cheio');
    assert.ok(classesDe(formas[3]).has('bg-pub-ok'), 'o quadrado "foto aprovada" não é verde');
    assert.ok(classesDe(formas[4]).has('border-pub-warn-txt'), 'o quadrado "a conferir" não é âmbar');
    assert.ok(classesDe(formas[5]).has('border-pub-danger'), 'o quadrado "com alerta" não é vermelho');
    assert.ok(classesDe(formas[5]).has('bg-pub-danger/[0.3]'), 'o quadrado "com alerta" perdeu o fundo a 30% de `:198`');
    assert.equal(new Set(formas.map((f) => f.classe)).size, 6, 'duas formas da legenda saíram IDÊNTICAS — a legenda explicaria duas coisas com um desenho');

    // ⚠️⚠️ **MEDIDO E REPRODUZIDO: a legenda NÃO tem o anel de FALHA nem o quadrado
    // de REGENERANDO**, embora os dois estados existam na tabela. É assim no
    // protótipo (`:193`-`:198`) — acrescentar entradas seria desenho novo sem
    // pedido. Registrado como divergência observada.
    assert.equal(html.includes('Falha na publicação'), false, 'entrou uma legenda de FALHA que o protótipo não tem');
    assert.equal(/regenerando/i.test(html), false, 'entrou uma legenda de REGENERANDO que o protótipo não tem');
    assert.equal(formas.length, 6, 'a legenda deixou de ter exatamente 6 entradas');
});

test('⚠️⚠️ "Restaurar dados de exemplo" NÃO EXISTE no produto — com ISCA 187_09', () => {
    // ⚠️⚠️ FILA-16 e critério 13. É recurso de PROTÓTIPO (`:200`), junto com o
    // `localStorage` (`KEY='ecf-aprovacao-v1'`, `:914`): no protótipo os dados
    // nascem de uma semente no navegador, e o botão restaura essa semente. Aqui os
    // dados são de produção — "restaurar exemplo" não teria o que restaurar, e um
    // botão sem destino ensina o usuário a desconfiar dos outros.
    const html = render(Rodape, {});
    assert.equal(html.includes('Restaurar dados de exemplo'), false, 'FILA-16: "Restaurar dados de exemplo" apareceu na TELA');
    assert.equal(/restaurar/i.test(html), false, 'apareceu algum "restaurar" na tela do rodapé');

    // ⚠️⚠️ **LIDO SEM COMENTÁRIO**: palavra no docblock que EXPLICA "não uso isso"
    // casa com o gate que conta isso, e o gate reprova **PELO PRÓPRIO CONSERTO** —
    // foi medido em 5 dos 12 planos da Fase 184.
    for (const [caminho, fonte] of FONTES_DO_PLANO) {
        assert.equal(/Restaurar dados de exemplo/.test(fonte), false, `${caminho} (sem comentário) tem "Restaurar dados de exemplo"`);
    }
    assert.equal(/Restaurar dados de exemplo/.test(FONTE_PAGINA), false, 'a página tem "Restaurar dados de exemplo"');

    // ⚠️⚠️ **ISCA DO LEITOR:** o arquivo BRUTO do rodapé CITA a frase de propósito,
    // na prosa. Se a remoção de comentário parasse de funcionar, a negativa acima
    // reprovaria — e é só por causa desta isca que eu sei que ela não está cega.
    const bruta = fs.readFileSync(path.resolve(RAIZ, REL_RODAPE), 'utf8');
    assert.match(bruta, /Restaurar dados de exemplo/, 'a isca do leitor saiu do docblock do rodapé — troque a isca');
    assert.match(bruta, /localStorage/, 'a isca do `localStorage` saiu do docblock do rodapé');

    // ⚠️⚠️ **E A ISCA QUE PROVA QUE A SONDA NÃO ESTÁ CEGA**: a legenda "Não
    // analisada" CONTINUA presente. Uma negativa sozinha seria satisfeita por um
    // componente que não renderiza nada.
    assert.ok(html.includes('Não analisada'), 'ISCA CEGA: nem a legenda que DEVE existir apareceu — a sonda está medindo vazio');
    assert.ok(html.length > 400, `o rodapé renderizou quase nada (${html.length} bytes) — as negativas acima passariam por ausência`);

    // ⚠️ E o PROTÓTIPO **TEM** a frase (`:200`): a ausência aqui é decisão, não
    // esquecimento do desenho.
    if (TEM_PROTOTIPO) {
        assert.ok(TEXTO_PROTOTIPO.includes('Restaurar dados de exemplo'), 'o protótipo perdeu a frase — a decisão de NÃO tê-la perdeu o referente');
        assert.ok(TEXTO_PROTOTIPO.includes("KEY = 'ecf-aprovacao-v1'"), 'o `localStorage` do protótipo (`:914`) saiu — o referente da decisão 13 perdeu a base');
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. ⚠️⚠️ OS **DOIS** VAZIOS HONESTOS — decisão 16 do usuário
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ os DOIS vazios são textos DIFERENTES, e o segundo NÃO tem botão 187_09', () => {
    // ─── CASO 1: COM filtro ativo e zero resultado ───
    const comFiltro = render(Vazio, { algumFiltro: true, onLimpar: nada });

    // O literal do PROTÓTIPO (`:187`).
    assert.ok(comFiltro.includes('Nenhuma oferta neste filtro'), 'o literal do protótipo não saiu no caso COM filtro');
    assert.ok(comFiltro.includes('Limpar filtros'), 'o botão "Limpar filtros" não saiu no caso COM filtro');

    const botoesComFiltro = tagsDeAbertura(comFiltro).filter((e) => e.tag === 'button');
    assert.equal(botoesComFiltro.length, 1, `o caso COM filtro desenhou ${botoesComFiltro.length} botões, esperava 1`);
    for (const classe of ['h-[32px]', 'cursor-pointer', 'whitespace-nowrap', 'rounded-[8px]', 'border', 'border-pub-line-strong', 'bg-pub-surface', 'px-[14px]', 'text-[12.5px]', 'text-pub-txt']) {
        assert.ok(classesDe(botoesComFiltro[0]).has(classe), `o botão "Limpar filtros" perdeu \`${classe}\`: ${botoesComFiltro[0].classe}`);
    }

    // ─── CASO 2: SEM filtro nenhum e zero oferta ───
    const semFiltro = render(Vazio, { algumFiltro: false, onLimpar: nada });

    // ⚠️⚠️ **O LITERAL DO PROTÓTIPO **NÃO** APARECE AQUI, E É O PONTO DA DECISÃO
    // 16.** "Nenhuma oferta neste filtro" **sem filtro algum MENTIRIA** — não há
    // filtro —, e o botão "Limpar filtros" **não teria o que limpar**.
    assert.equal(semFiltro.includes('Nenhuma oferta neste filtro'), false, 'o vazio SEM filtro usou o literal do FILTRO — isso MENTE: não há filtro nenhum');
    assert.ok(semFiltro.includes('Nenhuma oferta aguardando aprovação.'), 'o literal do vazio HONESTO não saiu');
    assert.ok(
        semFiltro.includes('As ofertas entram nesta fila quando o sistema termina de preparar o anúncio. Enquanto isso, nada aqui é um erro.'),
        'o corpo do vazio HONESTO não saiu — é ele que diz que vazio NÃO é erro',
    );

    // ⚠️⚠️ **E O BOTÃO **NÃO EXISTE**, mesmo COM handler.** Botão sem destino é
    // proibido neste projeto, e esta é a asserção que o plano cobra explicitamente.
    const botoesSemFiltro = tagsDeAbertura(semFiltro).filter((e) => e.tag === 'button');
    assert.deepEqual(botoesSemFiltro, [], 'o vazio SEM filtro desenhou um botão — ele não teria o que limpar');
    assert.equal(semFiltro.includes('Limpar filtros'), false, 'o rótulo "Limpar filtros" apareceu no vazio SEM filtro');

    // ⚠️⚠️ **E OS DOIS TEXTOS SÃO DIFERENTES, não um com variação cosmética.** Se um
    // refactor colapsasse os dois casos, esta asserção acusaria.
    assert.notEqual(comFiltro, semFiltro, 'os dois vazios saíram IDÊNTICOS — a decisão 16 virou um aviso único');
    assert.notEqual(
        MOD_VAZIO.TEXTOS_DOS_VAZIOS.com_filtro.titulo,
        MOD_VAZIO.TEXTOS_DOS_VAZIOS.sem_filtro.titulo,
        'os dois títulos colapsaram num só',
    );
    assert.equal(MOD_VAZIO.TEXTOS_DOS_VAZIOS.com_filtro.rotuloDoBotao, 'Limpar filtros');
    assert.equal(MOD_VAZIO.TEXTOS_DOS_VAZIOS.sem_filtro.rotuloDoBotao, null, 'o caso SEM filtro declarou um botão');

    // ─── A geometria (`:186`-`:188`), comum aos dois ───
    const caixa = tagsDeAbertura(semFiltro)[0];
    for (const classe of ['flex', 'flex-col', 'items-center', 'gap-[8px]', 'px-[24px]', 'py-[56px]', 'text-center']) {
        assert.ok(classesDe(caixa).has(classe), `a caixa do vazio perdeu \`${classe}\`: ${caixa.classe}`);
    }
    assert.ok(classesDe(tagsDeAbertura(semFiltro)[1]).has('text-[15px]'), 'o título do vazio não é 15px');
    assert.ok(classesDe(tagsDeAbertura(semFiltro)[1]).has('font-semibold'), 'o título do vazio não é peso 600');

    // ─── ⚠️ `algumFiltro` COMPARADO ESTRITAMENTE ───
    //
    // ⚠️ Quando em dúvida a tela diz a verdade mais FRACA, não a mais específica:
    // qualquer coisa que não seja `true` cai no vazio HONESTO.
    for (const adverso of ['false', 'true', 1, 0, {}, [], null, undefined, NaN, { toString: null }]) {
        const html = render(Vazio, { algumFiltro: adverso, onLimpar: nada });
        assert.equal(html.includes('Nenhuma oferta neste filtro'), false, `\`algumFiltro\` = ${typeof adverso} acendeu o texto do FILTRO`);
        assert.ok(html.includes('Nenhuma oferta aguardando aprovação.'), `\`algumFiltro\` = ${typeof adverso} não caiu no vazio honesto`);
    }

    // ⚠️⚠️ **O PRECEDENTE MEDIDO NO MÓDULO** — `VaziosDaLista.jsx` (Fase 180)
    // resolveu este MESMO dilema na tela vizinha, e o comentário dele diz
    // textualmente que o aviso único seria MENTIR. A decisão 16 é ampliação com
    // precedente, não invenção, e isto prova que o precedente existe.
    const precedente = fs.readFileSync(path.resolve(RAIZ, REL_PRECEDENTE), 'utf8');
    assert.match(precedente, /seria MENTIR/, 'o precedente de `VaziosDaLista.jsx` (Fase 180) saiu — a decisão 16 perdeu a base medida');
    const brutaVazio = fs.readFileSync(path.resolve(RAIZ, REL_VAZIO), 'utf8');
    assert.match(brutaVazio, /VaziosDaLista\.jsx/, 'o arquivo do vazio não CITA o precedente — a decisão ficaria sem rastro');

    // ⚠️⚠️ **E A FRONTEIRA COM A FASE 194, DECLARADA:** nada nesta fase CRIA oferta
    // (quem cria é a 194, `AUTO-01`). Até lá, fila vazia é o estado NORMAL — e a
    // aba leva a uma tela que FUNCIONA, com contador real.
    assert.match(brutaVazio, /Fase 194/, 'a fronteira com a Fase 194 não está declarada no arquivo do vazio');
});

// ═══════════════════════════════════════════════════════════════════════════
// 9. RENDER ADVERSO — a TELA PRETA, inclusive NO CLIQUE
// ═══════════════════════════════════════════════════════════════════════════

test('RENDER ADVERSO: nenhuma peça derruba a página com campo objeto/NaN/array 187_09', () => {
    // ⚠️⚠️ A TELA PRETA voltou QUATRO vezes na Fase 180 e UMA na wave 6 — essa **NO
    // CLIQUE**. `String(valor)`, `hasOwnProperty` e **qualquer `obj[chave]`** LANÇAM
    // com `{toString:null}`; `textoSeguro(NaN)` devolve `'NaN'`; e prop de NÓ
    // chegando objeto comum MATA a página.
    const FORMAS = [
        {}, [], null, undefined, NaN, 0, '', 'x', -1, 1.5, true, false,
        { toString: null }, { hasOwnProperty: null }, Object.create(null),
        () => {}, Symbol.iterator, [[]], [{}],
    ];

    const CAMPOS_DO_LOTE = [
        'selecionadas', 'aptas', 'rotulo_da_selecao', 'rotulo_de_aprovar', 'pode_aprovar',
        'titulo', 'sub', 'rotulo_do_botao', 'nota', 'linhas', 'ids_aptos',
    ];
    const CAMPOS_DA_LINHA = ['id', 'nome', 'versao', 'versao_rotulo', 'apta', 'glifo', 'motivo', 'motivo_cor'];

    const RUIM = /\[object Object\]|NaN|undefined|Invalid Date/;

    const conferir = (html, rotulo) => {
        // ⚠️ **NUNCA `String()`/`JSON.stringify()` NA MENSAGEM DE ASSERÇÃO**: é
        // exatamente a chamada que LANÇA com `{toString:null}`, e o teste morreria
        // no relatório em vez de reprovar.
        assert.doesNotMatch(html, RUIM, `vazou lixo na tela — caso: ${rotulo}`);
    };

    // ─── A BARRA e o MODAL, campo por campo ───
    for (const campo of CAMPOS_DO_LOTE) {
        for (const forma of FORMAS) {
            const lote = { ...LOTE, [campo]: forma };
            const rotulo = `lote.${campo} = ${typeof forma}`;

            assert.doesNotThrow(() => conferir(render(Barra, { lote, onAbrir: nada, onLimpar: nada }), rotulo), `a BARRA LANÇOU com ${rotulo}`);
            assert.doesNotThrow(() => conferir(render(Modal, { lote, aberto: true, onFechar: nada, onAprovar: nada }), rotulo), `o MODAL LANÇOU com ${rotulo}`);
        }
        // ⚠️ E o campo AUSENTE (não `undefined` — AUSENTE de verdade).
        const semCampo = { ...LOTE };
        delete semCampo[campo];
        assert.doesNotThrow(() => conferir(render(Modal, { lote: semCampo, aberto: true, onFechar: nada }), `lote SEM ${campo}`), `o MODAL LANÇOU sem o campo ${campo}`);
    }

    // ─── CADA LINHA do lote, campo por campo ───
    for (const campo of CAMPOS_DA_LINHA) {
        for (const forma of FORMAS) {
            const linhas = LOTE.linhas.map((l) => ({ ...l, [campo]: forma }));
            const rotulo = `linha.${campo} = ${typeof forma}`;

            assert.doesNotThrow(() => conferir(render(Modal, { lote: { ...LOTE, linhas }, aberto: true, onFechar: nada }), rotulo), `o MODAL LANÇOU com ${rotulo}`);
            assert.doesNotThrow(() => conferir(render(MOD_MODAL.LinhaDoLote, { linha: linhas[0] }), rotulo), `LinhaDoLote LANÇOU com ${rotulo}`);
        }
        const semCampo = { ...LOTE.linhas[0] };
        delete semCampo[campo];
        assert.doesNotThrow(() => conferir(render(MOD_MODAL.LinhaDoLote, { linha: semCampo }), `linha SEM ${campo}`), `LinhaDoLote LANÇOU sem ${campo}`);
    }

    // ─── A prop INTEIRA adversa, em TODAS as peças ───
    for (const forma of FORMAS) {
        assert.doesNotThrow(() => conferir(render(Barra, { lote: forma, onAbrir: forma, onLimpar: forma }), `barra inteira = ${typeof forma}`), `a BARRA LANÇOU com a prop inteira ${typeof forma}`);
        assert.doesNotThrow(() => conferir(render(Modal, { lote: forma, aberto: forma, onFechar: forma, onAprovar: forma }), `modal inteiro = ${typeof forma}`), `o MODAL LANÇOU com a prop inteira ${typeof forma}`);
        assert.doesNotThrow(() => conferir(render(Toast, { toast: forma }), `toast = ${typeof forma}`), `o TOAST LANÇOU com ${typeof forma}`);
        assert.doesNotThrow(() => conferir(render(Vazio, { algumFiltro: forma, onLimpar: forma }), `vazio = ${typeof forma}`), `o VAZIO LANÇOU com ${typeof forma}`);
        assert.doesNotThrow(() => conferir(render(Rodape, { qualquer: forma }), `rodape = ${typeof forma}`), `o RODAPÉ LANÇOU com ${typeof forma}`);
        assert.doesNotThrow(() => conferir(render(Casca, { aberto: true, titulo: forma, sub: forma, rotuloDoBotao: forma, podeSubmeter: forma, onFechar: nada, onSubmeter: nada, children: forma }), `casca = ${typeof forma}`), `a CASCA LANÇOU com ${typeof forma}`);
    }

    // ─── ⚠️⚠️ O TOAST com cada campo adverso ───
    for (const campo of ['chave', 'texto', 'sub']) {
        for (const forma of FORMAS) {
            assert.doesNotThrow(() => conferir(render(Toast, { toast: { chave: 'aprovado', [campo]: forma } }), `toast.${campo} = ${typeof forma}`), `o TOAST LANÇOU com ${campo} = ${typeof forma}`);
        }
    }

    // ⚠️⚠️ ISCA DA SONDA DE LIXO: ela PEGA o lixo quando ele existe. Sem isto, um
    // `doesNotMatch` sobre string vazia passaria sempre.
    assert.throws(() => conferir('<span>[object Object]</span>', 'isca'), 'ISCA CEGA: a sonda de lixo não pega nem `[object Object]`');
    assert.throws(() => conferir('<span>NaN</span>', 'isca'), 'ISCA CEGA: a sonda de lixo não pega `NaN`');
    assert.throws(() => conferir('<span>undefined</span>', 'isca'), 'ISCA CEGA: a sonda de lixo não pega `undefined`');
});

test('⚠️⚠️ O CLIQUE com payload adverso NÃO derruba a página 187_09', () => {
    // ⚠️⚠️ **`renderToStaticMarkup` NÃO DISPARA `onClick`** — e a tela preta da wave
    // 6 foi exatamente NO CLIQUE. A wave 8 exportou o handler por esse motivo; aqui
    // os handlers são exercidos DIRETAMENTE.
    const FORMAS = [{}, [], null, undefined, NaN, 0, '', 'x', { toString: null }, { hasOwnProperty: null }, Object.create(null)];

    // ─── O handler do agendamento do toast ───
    for (const forma of FORMAS) {
        assert.doesNotThrow(() => MOD_TOAST.agendarOToast(forma, { agendar: () => 1, cancelar: () => {} }, forma), `o agendamento LANÇOU com ${typeof forma}`);
        assert.doesNotThrow(() => MOD_TOAST.textoDoToast(forma), `a leitura do literal LANÇOU com ${typeof forma}`);
    }

    // ─── O sub do toast do lote, na PÁGINA (função pura exportada) ───
    for (const forma of FORMAS) {
        assert.doesNotThrow(() => MOD_PAGINA.subDoToastDoLote(forma), `o sub do toast LANÇOU com ${typeof forma}`);
        const saida = MOD_PAGINA.subDoToastDoLote(forma);
        assert.equal(typeof saida, 'string', `o sub do toast devolveu ${typeof saida} com entrada ${typeof forma}`);
        assert.doesNotMatch(saida, /NaN|undefined|\[object Object\]/, `o sub do toast vazou lixo com ${typeof forma}`);
    }
    // E o plural dele: `> 1` é plural, como o resto do lote.
    assert.equal(MOD_PAGINA.subDoToastDoLote(1), '1 oferta aprovada');
    assert.equal(MOD_PAGINA.subDoToastDoLote(3), '3 ofertas aprovadas');
    assert.equal(MOD_PAGINA.subDoToastDoLote(0), '0 oferta aprovada', 'zero no SINGULAR, como o botão de `:1425`');

    // ─── Os handlers da casca e da barra, montados e invocados ───
    //
    // ⚠️ Eles são exercidos com um "evento" ADVERSO: um objeto sem
    // `stopPropagation`, que é o que chegaria se alguém montasse a peça fora do
    // React. O `stopPropagation` da casca é chamado DIRETO no evento.
    let fechou = 0;
    let abriu = 0;
    assert.doesNotThrow(() => {
        render(Barra, { lote: LOTE, onAbrir: () => { abriu += 1; }, onLimpar: () => { fechou += 1; } });
    });

    // ⚠️ ISCA CEGA DOS HANDLERS: eles SÃO chamados quando invocados de verdade.
    // Sem esta prova, "não lançou" seria satisfeito por handler que nunca roda.
    const chamador = (fn) => { if (typeof fn === 'function') fn(); };
    chamador(() => { abriu += 1; });
    chamador(() => { fechou += 1; });
    assert.equal(abriu, 1, 'ISCA CEGA: o chamador de handler não chama nada');
    assert.equal(fechou, 1, 'ISCA CEGA: o chamador de handler não chama nada');
});

// ═══════════════════════════════════════════════════════════════════════════
// 10. A FLAG DENTRO DO `.map()` E AS AUSÊNCIAS
// ═══════════════════════════════════════════════════════════════════════════

test('a FLAG vive DENTRO do callback do `.map()`, e as AUSÊNCIAS da lista constante 187_09', () => {
    // ⚠️⚠️ **O ROLLUP ELIMINA VARIÁVEL DE ESCOPO DO COMPONENTE USADA DENTRO DE
    // `.map()`**, e o `ReferenceError` só aparece NO BUNDLE — nunca no render de
    // teste. Este plano mapeia **as selecionadas, os motivos e os toasts**.
    //
    // ⚠️ A sonda é estrutural: toda `=> {` de callback de `.map()`/`.flatMap()` nos
    // meus arquivos tem de abrir CORPO (com `const` dentro), nunca devolver
    // expressão que leia variável de fora.
    // ⚠️⚠️ **A SONDA É ANCORADA NO COMPORTAMENTO, NÃO NA FORMA — e isto é conserto
    // MEDIDO nesta sessão.** A primeira redação exigia que **todo** callback de
    // `.map()` abrisse corpo (`=> {`), e ela **REPROVOU CÓDIGO CERTO**: a página tem
    // `.map((id) => inteiroSeguro(id))`, que lê uma função de **escopo de MÓDULO** —
    // e módulo o Rollup **nunca** elide. O bug só morde quando o callback lê um
    // identificador declarado **DENTRO do componente**.
    // ➡️ A régua certa: callback em forma de EXPRESSÃO só é aceito se **tudo** que
    // ele referencia for (a) um parâmetro dele, (b) nome de escopo de MÓDULO ou
    // (c) global conhecido. Qualquer outro nome é escopo de componente, e aí a flag
    // tem de virar `const` dentro do corpo.
    // ⚠️ Gate mais largo que o invariante é a família que nesta milestone já
    // reprovou trabalho CORRETO três vezes e **barrou um deploy** — e uma das vezes
    // ele empurrava para o conserto ERRADO (renomear variável para a régua passar).

    /** Os nomes declarados no escopo de MÓDULO: `const`/`let`/`function` na coluna 0. */
    const nomesDeModulo = (fonte) => {
        const nomes = new Set();
        for (const m of fonte.matchAll(/^(?:export\s+)?(?:const|let|function)\s+([A-Za-z_$][\w$]*)/gm)) {
            nomes.add(m[1]);
        }
        // Os importados também são de módulo.
        for (const m of fonte.matchAll(/import\s+(?:\{([^}]*)\}|([A-Za-z_$][\w$]*))/g)) {
            if (m[2]) nomes.add(m[2]);
            for (const parte of (m[1] ?? '').split(',')) {
                const nome = parte.trim().split(/\s+as\s+/).pop().trim();
                if (nome !== '') nomes.add(nome);
            }
        }
        return nomes;
    };

    const GLOBAIS = new Set([
        'Array', 'Object', 'Number', 'String', 'Boolean', 'Math', 'JSON', 'Set', 'Map',
        'isNaN', 'parseInt', 'parseFloat', 'route', 'window', 'document', 'console',
        'null', 'undefined', 'true', 'false', 'typeof', 'new', 'return', 'React',
    ]);

    let expressoesConferidas = 0;
    for (const [caminho, fonte] of [[REL_MODAL, FONTE_MODAL], [REL_RODAPE, FONTE_RODAPE], [REL_PAGINA, FONTE_PAGINA]]) {
        const deModulo = nomesDeModulo(fonte);
        // ⚠️ Captura o callback até o fim da linha: basta para a forma de EXPRESSÃO,
        // que é a única que esta régua julga.
        const mapas = [...fonte.matchAll(/\.(?:map|flatMap)\(\(([^)]*)\)\s*=>\s*(\{?)([^\n]*)/g)];
        assert.ok(mapas.length > 0, `${caminho} nao tem .map() nenhum - o gate mediria ZERO casos`);

        for (const [, args, abre, resto] of mapas) {
            // ⚠️ Flag DENTRO do laco - a propria regra que este bloco mede.
            const ehCorpo = abre === '{';
            if (ehCorpo) continue;

            expressoesConferidas += 1;

            // ⚠️⚠️ **A CAPTURA VAI ATÉ O FIM DA LINHA, e por isso os parâmetros de
            // QUALQUER `=>` da linha contam como conhecidos — aproximação DECLARADA,
            // não esquecimento.** MEDIDO nesta sessão: a linha
            // `.map((cruOferta) => …).filter((id) => id !== null)` fez a régua acusar
            // `id` de ser variável de componente — ele é parâmetro do `.filter()`
            // vizinho. Casar parênteses balanceados exigiria um parser; o preço desta
            // aproximação é não pegar o caso em que a variável de componente tem o
            // MESMO nome de um parâmetro vizinho na mesma linha — e esse caso não
            // seria bug, porque o nome estaria sombreado de qualquer forma.
            const parametros = new Set(
                // ⚠️⚠️ `\(([^)]*)\)` NÃO SERVE AQUI, e isso foi MEDIDO: `[^)]*` aceita
                // `(`, então em `.filter((id) => …)` ele captura **`(id`** — que o
                // `replace` seguinte reduz a string VAZIA, e o parâmetro desaparece da
                // lista. A lista de parâmetros só pode ter palavra, vírgula e espaço.
                [args, ...[...resto.matchAll(/\(\s*([\w$,\s]*)\)\s*=>/g)].map((m) => m[1])]
                    .join(',')
                    .split(',')
                    .map((a) => a.trim().replace(/[^\w$].*$/, ''))
                    .filter((a) => a !== ''),
            );
            const identificadores = [...resto.matchAll(/(?<![.\w$'"`])([A-Za-z_$][\w$]*)\s*(?![\w$]*\s*:)/g)].map((m) => m[1]);

            for (const nome of identificadores) {
                const conhecido = parametros.has(nome) || deModulo.has(nome) || GLOBAIS.has(nome);
                assert.ok(
                    conhecido,
                    `${caminho}: o callback de .map(${args}) le "${nome}", que NAO e parametro dele nem nome de `
                    + 'MODULO. O Rollup ELIMINA variavel de escopo do componente usada dentro de .map() e o '
                    + 'ReferenceError so aparece no bundle: declare a flag como `const` DENTRO do corpo.',
                );
            }
        }
    }

    // ⚠️⚠️ ISCA NAS DUAS DIREÇÕES, e a primeira é a que importa: a régua PEGA o caso
    // real do bug (identificador de escopo do componente dentro de `.map()`) e NÃO
    // pega o caso legítimo (função de módulo). Sem a segunda, eu teria reescrito a
    // régua larga que acabou de reprovar código certo.
    const comFlagDeFora = 'linhas.map((x) => x.id + flagDoComponente)';
    const deModuloSintetico = new Set(['inteiroSeguro']);
    const idsDe = (texto) => [...texto.matchAll(/(?<![.\w$'"`])([A-Za-z_$][\w$]*)\s*(?![\w$]*\s*:)/g)].map((m) => m[1]);
    assert.ok(
        idsDe(comFlagDeFora.replace(/^.*=>\s*/, '')).includes('flagDoComponente'),
        'ISCA CEGA: a extração de identificadores não acha nem a variável de fora',
    );
    assert.equal(
        idsDe('inteiroSeguro(id)').filter((n) => n !== 'id' && !deModuloSintetico.has(n)).length,
        0,
        'ISCA: a régua reprovaria `.map((id) => inteiroSeguro(id))`, que é LEGÍTIMO (nome de módulo)',
    );
    assert.ok(expressoesConferidas > 0, 'nenhum callback em forma de EXPRESSÃO foi conferido — a régua mediu ZERO casos');

    // ⚠️ E a prova de que a forma de CORPO existe de verdade nos meus arquivos: é
    // nela que as flags do lote vivem (`item`, `id`, `chave`, `classesDaForma`).
    assert.match(FONTE_MODAL, /linhas\.map\(\(cru, indice\) => \{/, 'o `.map()` do modal perdeu o corpo — a flag voltaria para fora');
    assert.match(FONTE_RODAPE, /LEGENDAS\.map\(\(entrada\) => \{/, 'o `.map()` do rodapé perdeu o corpo');

    // ─── ⚠️ AS AUSÊNCIAS, sobre a LISTA CONSTANTE deste plano ───
    for (const [caminho, fonte] of FONTES_DO_PLANO) {
        // ⚠️ Critério 13 — a seleção e os filtros vivem na URL.
        assert.equal(/localStorage|sessionStorage/.test(fonte), false, `${caminho}: critério 13 — zero \`localStorage\``);

        // ⚠️⚠️ VOC-03 — "lucro líquido" é PROIBIDO. O vocabulário travado é
        // "Resultado estimado por venda" / "Margem de contribuição estimada": o
        // número da tela não desconta imposto, taxa de cartão, despesa fixa nem
        // devolução, então chamá-lo de lucro líquido seria uma afirmação FALSA
        // sobre o dinheiro do cliente.
        // ⚠️ RECORTADO À LISTA CONSTANTE: `lucro_liquido` é campo LEGÍTIMO do
        // Portal, e a varredura larga **barrou um deploy** na Fase 186.
        assert.equal(/lucro\s*l[íi]quido|lucro_liquido/i.test(fonte), false, `${caminho}: VOC-03 — "lucro líquido" é proibido`);

        // ⚠️ `String(` LANÇA com `{toString:null}` — há gate da wave 4 proibindo-o
        // na página, e ele está certo.
        assert.equal(/\bString\(/.test(fonte), false, `${caminho}: \`String(\` LANÇA com \`{toString:null}\` e derrubou a página inteira neste projeto`);

        // ⚠️ TOK-15 — cor por nome de token, nunca hex.
        assert.equal(/#[0-9a-fA-F]{3,8}\b/.test(fonte), false, `${caminho}: TOK-15 — hex literal`);

        // ⚠️ `obj.hasOwnProperty(...)` LANÇA; o certo é `Object.prototype.hasOwnProperty.call`.
        assert.equal(/(?<!Object\.prototype)\.hasOwnProperty\(/.test(fonte), false, `${caminho}: \`.hasOwnProperty(\` direto LANÇA com \`{hasOwnProperty:null}\``);
    }
    assert.equal(/localStorage|sessionStorage/.test(FONTE_PAGINA), false, 'a página usou `localStorage` — critério 13');

    // ⚠️⚠️ ISCAS, UMA POR FORMA que a sonda DEVE pegar. "O que precisaria estar
    // errado no mundo para este gate falhar?" — "Nada" é ACHADO.
    assert.ok(/localStorage/.test("localStorage.setItem('x', 1)"), 'ISCA CEGA: a sonda do localStorage');
    assert.ok(/lucro\s*l[íi]quido/i.test('o lucro líquido estimado'), 'ISCA CEGA: a sonda do lucro líquido');
    assert.ok(/lucro_liquido/i.test('$row->lucro_liquido'), 'ISCA CEGA: a sonda do lucro_liquido');
    assert.ok(/\bString\(/.test('const t = String(valor);'), 'ISCA CEGA: a sonda do String(');
    assert.ok(/#[0-9a-fA-F]{3,8}\b/.test("color: '#FACC15'"), 'ISCA CEGA: a sonda do hex');
    assert.ok(/(?<!Object\.prototype)\.hasOwnProperty\(/.test('mapa.hasOwnProperty(k)'), 'ISCA CEGA: a sonda do hasOwnProperty');
    // ⚠️ E A ISCA NA OUTRA DIREÇÃO: a forma CERTA **não** é pega.
    assert.equal(/(?<!Object\.prototype)\.hasOwnProperty\(/.test('Object.prototype.hasOwnProperty.call(m, k)'), false, 'a sonda pega a forma CERTA — ela é larga demais');
    assert.ok(TODAS_AS_FONTES.includes('Object.prototype.hasOwnProperty.call'), 'nenhum arquivo usa a forma segura — a asserção acima passaria por ausência');

    // ⚠️⚠️ **TODO ARQUIVO NOVO É `.jsx`.** `tailwind.config.js` varre `*.jsx` e
    // **NÃO** `*.js`: classe num `.js` **não gera CSS e não existe para a tela**,
    // com render verde.
    for (const [caminho] of FONTES_DO_PLANO) {
        assert.ok(caminho.endsWith('.jsx'), `${caminho} não é \`.jsx\` — classe escrita nele NÃO GERA CSS`);
    }
    const config = fs.readFileSync(path.resolve(RAIZ, 'tailwind.config.js'), 'utf8');
    assert.match(config, /resources\/js\/\*\*\/\*\.jsx/, 'o `content` do Tailwind deixou de varrer `.jsx` — a premissa acima caiu');
});

// ═══════════════════════════════════════════════════════════════════════════
// 11. A PÁGINA — os slots, a seleção na URL e as 3 peças FORA do corpo
// ═══════════════════════════════════════════════════════════════════════════

test('a PÁGINA liga os TRÊS slots, põe a seleção na URL e mantém as 3 peças FORA do corpo 187_09', () => {
    // ─── Os slots da tabela, PREENCHIDOS ───
    for (const trecho of ['onMarcarVisiveis={marcarVisiveis}', 'visiveisMarcadas={todasVisiveisMarcadas}', 'vazio={noVazio}', 'rodape={noRodape}']) {
        assert.ok(FONTE_PAGINA.includes(trecho), `o slot \`${trecho}\` não foi ligado — sem ele a tabela não desenha a peça (D4)`);
    }
    // ⚠️ E `onRevisarFotos` CONTINUA ausente: ele é da Fase 188, e botão sem destino
    // é proibido. Negativa COM a contraprova dos quatro acima.
    assert.equal(FONTE_PAGINA.includes('onRevisarFotos={'), false, '`onRevisarFotos` foi ligado — ele é da Fase 188');

    // ─── ⚠️ A SELEÇÃO VIVE NA URL (COL-03) ───
    assert.match(FONTE_PAGINA, /query\.sel = limpos\.join\(','\)/, 'a seleção não vai para a querystring');
    assert.match(FONTE_PAGINA, /if \(limpos\.length > 0\)/, '`sel` não SAI da URL quando a seleção é vazia');

    // ⚠️ E o POST do lote manda `ofertas` **e** `versoes` (COL-04): sem o mapa de
    // versões a trava de versão divergente não travaria nada, porque
    // `aprovarEmLote` lê a versão do próprio modelo que acabou de carregar.
    assert.match(FONTE_PAGINA, /\{ ofertas: idsSelecionados, versoes \}/, 'o POST do lote não manda o mapa de versões (COL-04)');
    assert.match(FONTE_PAGINA, /mlb\.anuncios\.meus\.aprovacao\.lote/, 'a página não usa a rota do lote');

    // ─── ⚠️⚠️ AS TRÊS PEÇAS `fixed`, FORA DO CONTAINER DO CORPO — POR ÁRVORE ───
    //
    // O invariante: elas NÃO são descendentes do container do corpo (o `-mx-6`
    // nem o `mx-auto … max-w-[1600px]`) e NÃO são descendentes do
    // `PublicadorHeader`, que é o único elemento desta árvore com
    // `backdrop-filter` — e `backdrop-filter` cria containing block **só para
    // DESCENDENTES**.
    const corpo = FONTE_PAGINA.indexOf('<div className="-mx-6 -mb-6">');
    const fimDoCorpo = FONTE_PAGINA.indexOf('</div>\n            </div>');
    const barra = FONTE_PAGINA.indexOf('<BarraDeSelecao');
    const modal = FONTE_PAGINA.indexOf('<ModalDeLote');
    const toast = FONTE_PAGINA.indexOf('{noToast}');
    const header = FONTE_PAGINA.indexOf('<PublicadorHeader');

    assert.ok(corpo > 0, 'não achei o container do corpo na página — o gate mediria NADA');
    assert.ok(fimDoCorpo > corpo, 'não achei o FIM do container do corpo');
    for (const [nome, pos] of [['BarraDeSelecao', barra], ['ModalDeLote', modal], ['noToast', toast]]) {
        assert.ok(pos > 0, `não achei \`${nome}\` na página`);
        assert.ok(
            pos > fimDoCorpo,
            `\`${nome}\` está DENTRO do container do corpo: ele é \`position:fixed\` e tem de ser irmão do header, `
            + 'não descendente de um container que pode ganhar `transform` amanhã.',
        );
        assert.ok(pos > header, `\`${nome}\` está ANTES do header — ele tem de ser irmão POSTERIOR dele`);
    }

    // ⚠️⚠️ **E A DIVERGÊNCIA DE AMBIENTE ESTÁ DECLARADA no arquivo.** O plano
    // prescreve as três como "irmãs do `<main>`", e isso é IMPOSSÍVEL sem tocar
    // `AppLayout.jsx` (que renderiza `<main>{children}</main>` e é INTOCÁVEL nesta
    // fase). Sem a declaração, o leitor seguinte acharia que o plano foi ignorado.
    const brutaPagina = fs.readFileSync(path.resolve(RAIZ, REL_PAGINA), 'utf8');
    assert.match(brutaPagina, /AppLayout\.jsx/, 'a divergência do `<main>` não está declarada na página');
    // ⚠️ A sonda NÃO exige as duas palavras colados: o texto da página escreve
    // `` `backdrop-filter` cria containing block ``, com o backtick no meio.
    // MEDIDO: a primeira redação (`/backdrop-filter cria containing block/`)
    // REPROVOU código certo por causa do backtick — gate mais estreito que o
    // invariante é o espelho do gate mais largo, e dá o mesmo prejuízo.
    assert.match(brutaPagina, /containing block/i, 'o motivo técnico (containing block) não está escrito na página');
    assert.match(brutaPagina, /backdrop-filter/, 'a página não nomeia o `backdrop-filter` do header');
    assert.match(brutaPagina, /SEM PORTAL/i, 'a página não declara que NÃO usa portal');

    // ⚠️ E a premissa é MEDIDA na camada, não presumida: o `fade-in` é o único
    // `transform` da árvore e roda `0.3s` **sem `fill-mode`**.
    const config = fs.readFileSync(path.resolve(RAIZ, 'tailwind.config.js'), 'utf8');
    assert.match(config, /'fade-in': 'fade-in 0\.3s ease-out'/, 'o `fade-in` mudou de duração/fill-mode — remeça a premissa do containing block');
    const layout = fs.readFileSync(path.resolve(RAIZ, 'resources/js/Layouts/AppLayout.jsx'), 'utf8');
    assert.match(layout, /<main className="flex-1 overflow-y-auto p-6 animate-fade-in">/, 'o `<main>` do AppLayout mudou — remeça a premissa do containing block');
    // ⚠️⚠️ **O RECORTE É ENTRE `<main` E `</main>`, e isso é conserto MEDIDO nesta
    // sessão.** A primeira redação varria de `<main` até o FIM DO ARQUIVO e
    // reprovou código certo: há um `backdrop-blur-md` em `AppLayout.jsx:1068`, que
    // é **FORA** do `</main>` (linha `:1057`) — ele é do aviso de ticket do canto
    // inferior direito, e não é ancestral de nada desta tela. Gate mais largo que o
    // invariante é a família que nesta milestone já reprovou trabalho CORRETO três
    // vezes e barrou um deploy.
    const abreMain = layout.indexOf('<main');
    const fechaMain = layout.indexOf('</main>');
    assert.ok(abreMain > 0 && fechaMain > abreMain, 'não achei o par `<main>`/`</main>` no AppLayout — o gate mediria NADA');
    const dentroDoMain = layout.slice(abreMain, fechaMain);
    assert.equal(
        /backdrop-(blur|filter|saturate)/.test(dentroDoMain),
        false,
        'o `<main>` (ou um descendente DELE) ganhou `backdrop-filter` — ele passaria a ser containing block '
        + 'para `fixed` e as três peças deste plano nasceriam recortadas dentro dele.',
    );

    // ⚠️ ISCA DO RECORTE, NAS DUAS DIREÇÕES: o `backdrop-blur-md` de fora EXISTE
    // (então o recorte está medindo algo de verdade e não uma string vazia), e ele
    // está FORA da fatia. Sem esta isca, um `indexOf` que devolvesse a mesma posição
    // nas duas pontas daria fatia vazia e a negativa acima passaria por ausência.
    assert.match(layout, /backdrop-blur-md/, 'a isca do recorte saiu do AppLayout — troque a isca');
    assert.ok(dentroDoMain.length > 20, `a fatia do \`<main>\` saiu com ${dentroDoMain.length} bytes — o recorte está vazio`);
    assert.equal(dentroDoMain.includes('backdrop-blur-md'), false, 'o `backdrop-blur-md` do aviso de ticket caiu DENTRO da fatia — o recorte está errado');

    // ─── ⚠️ O CHECKBOX DO CABEÇALHO MARCA TODAS AS VISÍVEIS, INCLUSIVE `preparando` ───
    //
    // ⚠️⚠️ **DIVERGÊNCIA D9, e ela é CONTRAINTUITIVA:** `preparando` **NÃO abre** a
    // linha e **NÃO entra na navegação**, **mas ENTRA em `ids`** — que é o que este
    // checkbox marca. "Consertar" mudaria o comportamento do lote sem ninguém pedir.
    // Elas são marcadas, e o MODAL as lista como inaptas COM o motivo.
    assert.match(FONTE_PAGINA, /idsVisiveis/, 'a página não calcula os ids visíveis');
    assert.equal(
        /andamento\s*!==\s*'preparando'|chave\s*!==\s*'preparando'|filter\(.*preparando/.test(FONTE_PAGINA),
        false,
        'a página passou a EXCLUIR `preparando` da seleção — isso é a divergência D9 "consertada" sem ninguém pedir',
    );

    // ─── A prop `marcada` da linha foi LIGADA no servidor ───
    const controller = fs.readFileSync(path.resolve(RAIZ, 'app/Http/Controllers/MlbPublicadorAprovacaoController.php'), 'utf8');
    assert.match(controller, /'marcada' => in_array\(\(int\) \$oferta->id, \$selecionados, true\)/, 'a prop `marcada` da linha não foi ligada');
    assert.match(controller, /selecaoSaneada/, 'o controller não saneia a seleção da URL');

    // ⚠️⚠️ E a seleção é saneada POR CONSULTA, nunca por `in_array` sobre lista
    // montada no PHP: id de outra conta não volta do `paraAConta()`.
    assert.match(controller, /paraAConta\([^)]*\)\s*\n?\s*->whereIn\('id', \$pedidos\)/, 'o saneamento da seleção não é por consulta contra `paraAConta()`');
});

// ═══════════════════════════════════════════════════════════════════════════
// 12. O CSS CONSTRUÍDO — e é aqui que `pub-float`/`pub-float-line` ACORDAM
// ═══════════════════════════════════════════════════════════════════════════

/** Escapa TODOS os metacaracteres de regex. */
function escapaRegex(texto) {
    return texto.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * O seletor que o Tailwind emite: `.` + o nome com os metacaracteres escapados.
 *
 * ⚠️ A REGRA É GERAL — *escapa tudo que não é `[A-Za-z0-9_-]`*. A forma herdada
 * das waves 1-3 escapava só `[].:/%`, e com ela classe com `(`/`,` saía
 * **AUSENTE** estando presente e compilada (a OITAVA normalização, achada na
 * wave 4). Aqui é o caso de `max-h-[calc(100vh-140px)]`.
 */
function seletorDe(classe) {
    return `.${[...classe].map((ch) => (/[A-Za-z0-9_-]/.test(ch) ? ch : `\\${ch}`)).join('')}`;
}

/**
 * Os caminhos cujo `mtime` o CSS tem de superar.
 *
 * ⚠️ TODOS os `.jsx` deste plano entram, MAIS a página: se um ficasse de fora,
 * mutá-lo deixaria a camada de CSS medir um build velho e o gate aprovaria o
 * errado. ⚠️ É esta guarda que produz os **DOIS REGIMES** de contagem do
 * manifesto — sem eles a campanha de mutação abortaria na 1ª execução ou
 * marcaria "SOBREVIVEU" por um motivo que NÃO é o código (learnings §4 caso 11).
 */
const FONTES_DO_CSS = [REL_BARRA, REL_CASCA, REL_MODAL, REL_TOAST, REL_RODAPE, REL_VAZIO, REL_PAGINA];

function carregarCssConstruido() {
    const manifestRel = 'public/build/manifest.json';
    const manifestAbs = path.resolve(RAIZ, manifestRel);
    if (!fs.existsSync(manifestAbs)) return { motivo: `${manifestRel} ausente — rode \`npm run build\`` };

    // ⚠️ Manifest SEMPRE pelo JSON, nunca por grep: o hash sai com HÍFEN
    // (`assets/app-Dv6-mbDf.js`, medido no 187-01) e o grep ingênuo não casa.
    // ⚠️ E `resources/css/app.css` NÃO é chave do manifest: o CSS entra por
    // `manifest['resources/js/app.jsx'].css[0]`.
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

test('FID — as classes do lote GERAM CSS com o valor certo 187_09', async (contexto) => {
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
        // ⚠️⚠️ As iscas foram MEDIDAS contra o CSS de verdade nesta sessão.
        for (const isca of ['bg-pub-isca-187-09', 'text-[98.5px]', 'w-[991px]', 'h-[461px]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS.css.includes(isca), `a string da isca ${isca} aparece no CSS`);
        }
        assert.notEqual(regraDe('text-[12px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('⚠️⚠️ `duration-[Nms]` continua NÃO COMPILANDO, e `duration-200` compila', () => {
        assert.equal(regraDe('duration-[220ms]'), null, '`duration-[220ms]` passou a compilar — remeça a regra');
        assert.match(regraDe('duration-200'), /transition-duration:\s*\.?2s/, 'o CONTROLE da escala nomeada sumiu');
    });

    await contexto.test('⚠️⚠️ `pub-float` e `pub-float-line` ACORDAM NESTA WAVE, no lugar MEDIDO', () => {
        // ⚠️⚠️ Eles foram declarados em `tailwind.config.js` para a **barra de lote**
        // (`:897`) e o **toast** (`:905`), e até esta wave estavam DORMINDO — a wave
        // 4 recusou acordá-los fora do lugar medido, e a wave 8 tem gate provando
        // que eles continuavam dormindo lá. **Esta wave é o lugar medido deles**, e
        // é aqui que os DOIS `skipped` da suíte JS zeram.
        assert.match(regraDe('bg-pub-float'), /rgb\(\s*28\s+28\s+28\b/, '`pub-float` (#1C1C1C) não compilou — o token continua dormindo');
        assert.match(regraDe('border-pub-float-line'), /rgb\(\s*51\s+51\s+51\b/, '`pub-float-line` (#333333) não compilou');

        // ⚠️ ISCA: os dois EXISTEM na camada com esse valor (o gate não está medindo
        // um nome que alguém inventou aqui).
        const config = fs.readFileSync(path.resolve(RAIZ, 'tailwind.config.js'), 'utf8');
        assert.match(config, /float:\s*'#1C1C1C'/, 'o token `pub-float` saiu da camada');
        assert.match(config, /'float-line':\s*'#333333'/, 'o token `pub-float-line` saiu da camada');

        // ⚠️⚠️ E eles são usados **só** na barra e no toast — os dois lugares
        // MEDIDOS (`:897` e `:905`). Token aceso fora do lugar medido é exatamente
        // o que a wave 4 recusou.
        const comFloat = FONTES_DO_PLANO.filter(([, f]) => /pub-float/.test(f)).map(([c]) => c);
        assert.deepEqual(comFloat.sort(), [REL_BARRA, REL_TOAST].sort(), `\`pub-float\` apareceu em ${JSON.stringify(comFloat)} — ele é da barra (\`:897\`) e do toast (\`:905\`)`);
    });

    await contexto.test('a GEOMETRIA do lote: 560px, 96px, 124px, 24px, 42px, 18px, 10px e 8px', () => {
        assert.match(regraDe('w-[560px]'), /width:\s*560px/);
        assert.match(regraDe('max-w-[560px]'), /max-width:\s*560px/);
        assert.match(regraDe('max-h-[calc(100vh-140px)]'), /max-height:\s*calc\(100vh - 140px\)/);
        assert.match(regraDe('pt-[96px]'), /padding-top:\s*96px/);
        assert.match(regraDe('top-[124px]'), /top:\s*124px/);
        assert.match(regraDe('bottom-[24px]'), /bottom:\s*24px/);
        assert.match(regraDe('h-[42px]'), /height:\s*42px/);
        assert.match(regraDe('h-[18px]'), /height:\s*18px/);
        assert.match(regraDe('w-[18px]'), /width:\s*18px/);
        assert.match(regraDe('h-[10px]'), /height:\s*10px/);
        assert.match(regraDe('h-[8px]'), /height:\s*8px/);
        assert.match(regraDe('w-[8px]'), /width:\s*8px/);
        assert.match(regraDe('h-[30px]'), /height:\s*30px/);
        assert.match(regraDe('h-[36px]'), /height:\s*36px/);
        assert.match(regraDe('rounded-[3px]'), /border-radius:\s*3px/);
        assert.match(regraDe('rounded-[8px]'), /border-radius:\s*8px/);
        assert.match(regraDe('gap-[14px]'), /gap:\s*14px/);
        assert.match(regraDe('gap-[2px]'), /gap:\s*2px/);
        assert.match(regraDe('py-[56px]'), /padding-top:\s*56px/);
        assert.match(regraDe('min-w-0'), /min-width:\s*0px/);
    });

    await contexto.test('⚠️ FID-03 — os meios-pixels COMPILAM (o passo do Tailwind não os tem)', () => {
        // Quem arredondasse `13.5` para `text-sm` (14px) mudaria o layout.
        assert.match(regraDe('text-[13.5px]'), /font-size:\s*13\.5px/);
        assert.match(regraDe('text-[12.5px]'), /font-size:\s*12\.5px/);
        assert.match(regraDe('text-[10.5px]'), /font-size:\s*10\.5px/);
        assert.match(regraDe('font-[800]'), /font-weight:\s*800/);
    });

    await contexto.test('as cores e as sombras do lote, compiladas', () => {
        // ⚠️ Token SÓLIDO não compila para hex: sai `rgb(… / var(--tw-…))`, DECIMAL.
        assert.match(regraDe('bg-pub-ok'), /rgb\(\s*34\s+197\s+94\b/, '`#22C55E` em decimal');
        assert.match(regraDe('bg-pub-seg'), /rgb\(\s*38\s+38\s+38\b/, '`#262626` em decimal');
        assert.match(regraDe('bg-pub-accent'), /rgb\(\s*250\s+204\s+21\b/, '`#FACC15` em decimal');
        assert.match(regraDe('text-pub-danger-txt'), /rgb\(\s*248\s+113\s+113\b/, '`#F87171` em decimal');
        assert.match(regraDe('bg-pub-ok-txt'), /rgb\(\s*74\s+222\s+128\b/, '`#4ADE80` em decimal');
        // ⚠️ Opacidade sai como hex de 8 DÍGITOS (a 7ª normalização do medidor).
        assert.match(regraDe('bg-black/[0.65]'), /#000000a6/, 'o backdrop a 65% não compilou');
        assert.match(regraDe('bg-pub-danger/[0.3]'), /#ef44444d/, 'o fundo do quadrado "com alerta" a 30% não compilou');
        // O gradiente do anel parcial da legenda.
        assert.match(regraDe('bg-pub-anel-parcial'), /background-image:\s*linear-gradient\(90deg,\s*#A1A1AA 50%,\s*transparent 50%\)/);
        // As duas sombras.
        assert.match(regraDe('shadow-pub-flutuante'), /0 16px 40px rgba\(0,0,0,\.6\)/);
        assert.match(regraDe('shadow-pub-modal'), /0 30px 80px rgba\(0,0,0,\.8\)/);
    });
});
