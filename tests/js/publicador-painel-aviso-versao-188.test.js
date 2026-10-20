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
// Fase 188, plano 12, TAREFA 3 — COL-04 / critério 17: O AVISO DE CONCORRÊNCIA,
// e o gate de que **TODA** escrita do painel passa pelo helper único.
//
// ⚠️⚠️ DE CADA BLOCO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR: "o que
// precisaria estar errado no mundo para ele falhar?" — **"Nada" é ACHADO.**
//
//  1. ⚠️⚠️ O HELPER → `versao_esperada` é **OPCIONAL no corpo** (`188-05`), então
//     omiti-la faz `exigirVersao()` receber `null` e **LIBERAR** a escrita:
//     *"sobrescrever em silêncio"*, que é o critério 17. ⚠️ O gate do helper
//     (188-07) roda **antes de os arquivos que escrevem existirem** — era **gate
//     sobre ZERO casos**. ➡️ **O gate de verdade mora AQUI**, com LISTA CONSTANTE
//     dos **11** componentes. ⚠️ **Nunca varredura de diretório** (família
//     *"contagem que depende de quantos arquivos existem"*, NOVE ocorrências).
//  2. ⚠️⚠️ O 422 → ele era **INALCANÇÁVEL** antes desta wave, e isso foi MEDIDO:
//     as recusas do painel são `response()->json([...], 422)` **CRUAS**, e o
//     `onError` do Inertia só é chamado dentro do ramo Inertia. ➡️ O helper passou
//     a ouvir `inertia:invalid`, a ler o **CAMPO `regra`** e a **cancelar o modal**.
//     ⚠️ Recusa SEM `regra` **continua mostrando o modal**: erro inesperado não
//     pode virar silêncio.
//  3. ⚠️⚠️ TOAST → *"um aviso de concorrência que desaparece é um aviso que não
//     houve"*. Toast tem vocabulário TRAVADO (VOC-08, SEIS frases) e some em
//     ~3,8 s, e o usuário RECUSOU o sétimo em 19/10.
//  4. ⚠️⚠️ VERSÃO ADVERSA → acender sem motivo **treina a pessoa a ignorá-lo**.
//     `typeof NaN === 'number'`, e `0` é versão plausível.
//  5. ⚠️ O MOLDE → COL-04 **não tem elemento no protótipo**: medido e declarado,
//     com as classes **IMPORTADAS** do aviso de alteração de preço.
//  6. ⚠️ A TELA NÃO REABRE NADA → `reopen` é da Fase 186.
//
// ⚠️ Todos os nomes de teste terminam em `188_12`.
// ⚠️⚠️ **TODOS os bundles são montados ANTES do primeiro `test()`.**
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

const STUB_INERTIA = path.join(__dirname, `.pub-aviso188-inertia-stub-${process.pid}.mjs`);

// ⚠️⚠️ O stub GRAVA as chamadas **e os ouvintes**: é por ele que o caminho do
// `invalid` é exercido. ⚠️ O gravador vive no `globalThis` porque o `montar()` usa
// `bundle: true` e o esbuild INLINA o stub dentro do bundle (conserto MEDIDO na
// wave 188-07: o array de dentro do bundle e o de um `import()` seriam DOIS
// objetos, e o gate leria um array sempre vazio).
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
globalThis.CHAMADAS_AVISO_188_12 = globalThis.CHAMADAS_AVISO_188_12 ?? [];
globalThis.OUVINTES_AVISO_188_12 = globalThis.OUVINTES_AVISO_188_12 ?? [];
const CHAMADAS = globalThis.CHAMADAS_AVISO_188_12;
const OUVINTES = globalThis.OUVINTES_AVISO_188_12;
export { CHAMADAS, OUVINTES };
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
    on: (tipo, callback) => {
        OUVINTES.push([tipo, callback]);

        return () => { OUVINTES.push(['off', tipo]); };
    },
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

global.route = (nome, params) => `/${nome}${JSON.stringify(params ?? {})}`;

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
        `.pub-aviso188-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';
const REL_AVISO = `${DIR_APROV}/AvisoDeVersaoMudou.jsx`;
const REL_PAINEL = `${DIR_APROV}/PainelDeRevisao.jsx`;
const REL_KPIS = `${DIR_APROV}/KpisDoPainel.jsx`;
const REL_TOAST = `${DIR_APROV}/ToastDaFila.jsx`;
const REL_VOCABULARIO = `${DIR_APROV}/vocabularioDoPortao.jsx`;
const REL_PAGINA = 'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx';
const REL_GATE_VOCABULARIO = 'tests/js/publicador-vocabulario-novo.test.js';
const REL_GATE_FECHAMENTO = 'tests/js/publicador-fila-fechamento-187.test.js';
const REL_GATE_FILTROS = 'tests/js/publicador-fila-filtros-187.test.js';
const REL_GATE_CABECALHO = 'tests/js/publicador-fila-cabecalho-187.test.js';
const REL_GATE_ZINDEX = 'tests/js/publicador-header-zindex-ordem.test.js';
const REL_CONTROLADOR = 'app/Http/Controllers/MlbPublicadorAprovacaoController.php';
const REL_SERVICO_APROVACAO = 'app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php';
const REL_INERTIA = 'node_modules/@inertiajs/core/dist/index.esm.js';
const REL_MANIFESTO = 'tests/gates/contagens-188-12.txt';
const REL_PLANO = '.planning/phases/188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece/188-12-PLAN.md';

/**
 * ⚠️⚠️ A LISTA CONSTANTE dos **11** componentes de `aprovacao/` que ESCREVEM.
 *
 * ⚠️ **Por que CONSTANTE e não varredura de diretório:** varredura é a família
 * *"contagem que depende de quantos arquivos existem"*, que caiu **NOVE** vezes
 * nesta milestone. Lista de nomes, cada um com o plano dono, e **diff VISÍVEL**
 * quando alguém entra.
 * ⚠️ **Arquivo novo que escreva e não entre aqui fica invisível a este gate** — é
 * a porta dos fundos que o learnings §4c descreve. A conferência contra os
 * `files_modified` dos planos 08-12 é do **plano 188-13**, que é o único lugar que
 * vê os treze.
 */
const QUEM_CHAMA_O_HELPER = [
    `${DIR_APROV}/ColunaDeFotos.jsx`,       // 188-08: aprovar foto, aprovar N sem alerta
    `${DIR_APROV}/RodapeDoPainel.jsx`,      // 188-10: salvar revisão, aprovar
    `${DIR_APROV}/PendenciasDoPainel.jsx`,  // 188-10: conferir alteração pelo botão
];

/**
 * ⚠️⚠️ OS OITO QUE **DELEGAM** — e esta divisão foi **MEDIDA**, não copiada do
 * plano.
 *
 * O `188-12-PLAN.md` lista os **onze** como *"os arquivos que escrevem"*. **MEDIDO
 * nesta wave: só TRÊS chamam o helper.** Os outros oito **recebem a ação como PROP
 * de função** (`onAprovar`, `onReprovar`, `onInformarFrete`, `onSimular`,
 * `onConferirAlteracao`, `onSolicitarAjustes`, `onSubmeter`) e **delegam ao
 * chamador**, que é quem tem a `versao`.
 * ➡️ **O invariante certo é mais FORTE que o do plano, e tem três partes:**
 *   1. **nenhum** dos onze faz chamada de rede;
 *   2. os **três** que escrevem passam pelo helper;
 *   3. os **oito** que delegam **recebem handler como prop** — senão eles não
 *      estariam delegando: estariam **não fazendo nada**, e o botão seria morto.
 * ⚠️ Exigir o helper nos onze reprovaria oito arquivos CORRETOS, e o "conserto"
 * natural seria mover a escrita para dentro deles — o oposto do critério 17.
 */
const QUEM_DELEGA = [
    `${DIR_APROV}/BlocoDaImagemSelecionada.jsx`,     // 188-08: onAprovar, onReprovar
    `${DIR_APROV}/ComposicaoFinanceiraDoPainel.jsx`, // 188-09: onInformarFrete
    `${DIR_APROV}/SimulacaoDePreco.jsx`,             // 188-09: onSimular/onDescartar/onSalvar
    `${DIR_APROV}/KpisDoPainel.jsx`,                 // 188-09: onConferir (o aviso de alteração)
    `${DIR_APROV}/ModalDeAjuste.jsx`,                // 188-11: onSubmeter
    `${DIR_APROV}/ModalDeExcecao.jsx`,               // 188-11: onSubmeter
    `${DIR_APROV}/ModalDeFrete.jsx`,                 // 188-12: onSubmeter
    `${DIR_APROV}/ModalDeReprovarImagem.jsx`,        // 188-12: onSubmeter
];

/** Os ONZE do plano — a UNIÃO dos dois papéis. */
const QUEM_ESCREVE = [...QUEM_CHAMA_O_HELPER, ...QUEM_DELEGA];

const CAMINHOS_CITADOS = [
    REL_AVISO, REL_PAINEL, REL_KPIS, REL_TOAST, REL_VOCABULARIO, REL_PAGINA,
    REL_GATE_VOCABULARIO, REL_GATE_FECHAMENTO, REL_GATE_FILTROS, REL_GATE_CABECALHO, REL_GATE_ZINDEX,
    REL_CONTROLADOR, REL_SERVICO_APROVACAO, REL_INERTIA, REL_MANIFESTO, REL_PLANO,
    ...QUEM_ESCREVE,
];

const FONTE_AVISO = lerSemComentarios(REL_AVISO);
const CRUA_AVISO = fs.readFileSync(path.resolve(RAIZ, REL_AVISO), 'utf8');
const FONTE_PAGINA = lerSemComentarios(REL_PAGINA);
const FONTE_PAINEL = lerSemComentarios(REL_PAINEL);

const FONTES_DO_PLANO = [[REL_AVISO, FONTE_AVISO]];

/**
 * ⚠️⚠️ Leitura CRUA sem linha de comentário — ACHADO da wave 188-11:
 * `lerSemComentarios` DESTRÓI 76% de `publicador-fila-filtros-187.test.js`, porque
 * o `'date-fns/*'` do `external` do esbuild (`:118`) é lido como ABERTURA de
 * comentário de bloco e o stripper fecha no próximo JSDoc — **62656 bytes crus →
 * 15249**, e `ESTADO_LOCAL_DA_PAGINA` DESAPARECE.
 * ⚠️ `_fonte.js` **não** foi consertado: dezenas de gates em produção dependem do
 * que ele mede.
 */
function lerSemLinhaDeComentario(rel) {
    return fs.readFileSync(path.resolve(RAIZ, rel), 'utf8')
        .split(/\r?\n/)
        .map((linha) => linha.replace(/(^|[^:])\/\/.*$/, '$1'))
        .join('\n');
}

const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

const nada = () => {};

const ARVORE_VAZIA = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';

const numstatDe = (rel, base) => execFileSync(
    'git',
    ['diff', '--numstat', base ?? 'HEAD', '--', rel],
    { cwd: RAIZ, encoding: 'utf8' },
);

function tagsDeAbertura(html) {
    return [...html.matchAll(/<(div|span|section|aside|button|input|label|select|option|textarea|img|a)\b([^>]*)>/g)].map((m) => ({
        tag: m[1],
        atributos: m[2],
        classe: (m[2].match(/class="([^"]*)"/) || [])[1] ?? '',
    }));
}

function classesDe(elemento) {
    return new Set(elemento.classe.split(/\s+/).filter((c) => c !== ''));
}

function texto(html) {
    return html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
}

const MOD_AVISO = await montar(REL_AVISO);
const MOD_PAINEL = await montar(REL_PAINEL);
const STUB = await import(pathToFileURL(STUB_INERTIA).href);

/** A MENSAGEM do servidor, byte a byte da `sprintf` de `recusaDeRevisao()`. */
const MENSAGEM_DO_SERVIDOR = 'Esta oferta mudou enquanto você revisava (você viu a versão 3, a atual é 4). Confira de novo antes de gravar.';

// ═══════════════════════════════════════════════════════════════════════════
// 0. ANTI-FANTASMA — e ele cobre os ONZE caminhos da lista CONSTANTE
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ ANTI-FANTASMA: os 11 caminhos de QUEM_ESCREVE e todo caminho citado EXISTEM 188_12', () => {
    // ⚠️⚠️ Esta é a ISCA (b) do gate do helper: **lista que não resolve é varredura
    // CEGA, não "tudo certo"** (learnings §4b.1). A conferência vem ANTES de
    // qualquer conclusão, numa asserção coletando os fantasmas.
    const fantasmas = CAMINHOS_CITADOS.filter((rel) => !fs.existsSync(path.resolve(RAIZ, rel)));
    assert.deepEqual(fantasmas, [], 'caminho fantasma citado por este gate — sonda de ausência sobre arquivo inexistente passa CALADA');
    assert.equal(QUEM_ESCREVE.length, 11, `a lista de quem escreve deixou de ter ONZE entradas (tem ${QUEM_ESCREVE.length}) — se nasceu arquivo que escreve, ele entra AQUI, senão fica invisível a este gate`);
    assert.equal(new Set(QUEM_ESCREVE).size, 11, 'a lista de quem escreve tem entrada REPETIDA');
    assert.equal(QUEM_CHAMA_O_HELPER.length, 3, `os que CHAMAM o helper deixaram de ser TRÊS (são ${QUEM_CHAMA_O_HELPER.length}) — a divisão foi MEDIDA, não copiada do plano`);
    assert.equal(QUEM_DELEGA.length, 8, `os que DELEGAM deixaram de ser OITO (são ${QUEM_DELEGA.length})`);
    assert.deepEqual(QUEM_CHAMA_O_HELPER.filter((r) => QUEM_DELEGA.includes(r)), [], 'arquivo nos DOIS papéis — ele chama o helper OU delega');
    assert.equal(fs.existsSync(path.resolve(RAIZ, 'app/Services/Publicador/Aprovacao/Fila/ResumoRapidoService.php')), false, 'o caminho fantasma MEDIDO pela wave 188-06 virou arquivo de verdade');
});

// ═══════════════════════════════════════════════════════════════════════════
// 1. ⚠️⚠️ TODA ESCRITA PASSA PELO HELPER ÚNICO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ R4 / critério 17: NENHUM dos 11 componentes escreve direto, e TODOS usam o helper 188_12', () => {
    const ESCRITA_DIRETA = [/router\s*\.\s*post\(/, /router\s*\.\s*put\(/, /router\s*\.\s*delete\(/, /router\s*\.\s*patch\(/, /axios\s*\.\s*(post|put|patch|delete)\(/, /fetch\(/];

    // (1) ⚠️ NENHUM dos 11 contém escrita direta — UMA asserção COLETANDO os
    // infratores, nunca uma por arquivo (que faria a contagem crescer com a lista).
    const diretos = [];
    for (const rel of QUEM_ESCREVE) {
        const fonte = lerSemComentarios(rel);
        for (const re of ESCRITA_DIRETA) {
            if (re.test(fonte)) diretos.push(`${rel} ~ ${re}`);
        }
    }
    assert.deepEqual(diretos, [], 'componente escrevendo DIRETO — `versao_esperada` é OPCIONAL no corpo, e omiti-la LIBERA a escrita: é "sobrescrever em silêncio"');

    // (2) ⚠️ OS TRÊS QUE ESCREVEM passam pelo helper — e "escrever" é lido pelo
    // COMPORTAMENTO (chamar `escreverNoPainel`), não por nome de arquivo.
    const semHelper = QUEM_CHAMA_O_HELPER.filter((rel) => !/escreverNoPainel\(/.test(lerSemComentarios(rel)));
    assert.deepEqual(semHelper, [], 'componente que ESCREVE e não passa pelo helper — a `versao_esperada` deixou de viajar, e omiti-la LIBERA a escrita');

    // (3) ⚠️⚠️ OS OITO QUE DELEGAM **não** chamam o helper **e** RECEBEM handler
    // como prop. ⚠️ Se um deles parasse de receber o handler, ele não estaria
    // delegando: estaria **não fazendo nada**, e o botão seria MORTO (D4).
    const PROPS_DE_ACAO = /\bon[A-Z][A-Za-z]*\s*=\s*null/;
    const semDelegacao = QUEM_DELEGA.filter((rel) => !PROPS_DE_ACAO.test(lerSemComentarios(rel)));
    assert.deepEqual(semDelegacao, [], 'componente da lista de DELEGAÇÃO que não recebe handler como prop — ele não delega: ele não faz nada, e o botão é morto');
    const delegaEEscreve = QUEM_DELEGA.filter((rel) => /escreverNoPainel\(/.test(lerSemComentarios(rel)));
    assert.deepEqual(delegaEEscreve, [], 'componente da lista de DELEGAÇÃO passou a ESCREVER — mova-o para `QUEM_CHAMA_O_HELPER` e declare');

    // ⚠️ ISCA do recorte da prop: a regex casa o que DEVE casar e não casa prop de
    // dado.
    assert.equal(PROPS_DE_ACAO.test('onReprovar = null,'), true, 'a regex de prop de ação não casa o próprio caso — gate cego');
    assert.equal(PROPS_DE_ACAO.test('imagem = null,'), false, 'a regex de prop de ação casa prop de DADO — ela é mais larga que o invariante');

    // (4) ⚠️⚠️ **A PORTA DOS FUNDOS:** nenhum OUTRO `.jsx` de `aprovacao/` escreve
    // direto — senão arquivo novo que escrevesse ficaria **invisível** à lista
    // CONSTANTE (é o furo que o learnings §4c descreve). ⚠️ A ÚNICA exceção é
    // `PainelDeRevisao.jsx`, que é a CASA do helper, e ela é PROVADA (ele tem o
    // helper), não afirmada.
    const todosOsJsx = fs.readdirSync(path.resolve(RAIZ, DIR_APROV))
        .filter((nome) => nome.endsWith('.jsx'))
        .map((nome) => `${DIR_APROV}/${nome}`);
    assert.ok(todosOsJsx.length > 20, `a varredura do diretório achou ${todosOsJsx.length} \`.jsx\` — está cega`);
    const foraDaLista = todosOsJsx.filter((rel) => {
        if (rel === REL_PAINEL || QUEM_ESCREVE.includes(rel)) return false;
        const fonte = lerSemComentarios(rel);

        return ESCRITA_DIRETA.some((re) => re.test(fonte)) || /escreverNoPainel\(/.test(fonte);
    });
    assert.deepEqual(foraDaLista, [], 'arquivo de `aprovacao/` escrevendo FORA da lista CONSTANTE — é a porta dos fundos: ele entra na lista, ou para de escrever');
    assert.ok(/escreverNoPainel\(/.test(FONTE_PAINEL) || /export function escreverNoPainel/.test(FONTE_PAINEL), 'a exceção de `PainelDeRevisao.jsx` virou LINHA MORTA: ele não tem mais o helper');

    // ⚠️ ISCA (a): a sonda ACHA um `router.post` onde ele EXISTE. Sem este controle
    // as seis regex poderiam estar todas cegas e o `deepEqual` passaria.
    assert.ok(ESCRITA_DIRETA.some((re) => re.test(FONTE_PAINEL)), 'a sonda não acha a escrita nem em `PainelDeRevisao.jsx`, que é onde o helper VIVE');
    assert.equal(ESCRITA_DIRETA[0].test('router . post(x)'), true, 'a sonda não tolera espaço: `router . post(` escaparia dela');
    assert.equal(ESCRITA_DIRETA[0].test('router.get(x)'), false, 'a sonda acusa LEITURA (`router.get`) — ela mede ESCRITA');

    // ⚠️ ISCA (c): o próprio `PainelDeRevisao.jsx` **PODE** ter `router.post` (é
    // onde o helper vive) e **NÃO está na lista** — a sonda não o acusa.
    assert.equal(QUEM_ESCREVE.includes(REL_PAINEL), false, '`PainelDeRevisao.jsx` entrou na lista — ele é a CASA do helper, e a sonda passaria a reprovar o helper');

    // ⚠️⚠️ ISCA (d): **a PÁGINA também pode**, e isso foi MEDIDO na wave 188-11. Ela
    // tem um `router.post` **legítimo** — a aprovação em LOTE da Fase 187, EM
    // PRODUÇÃO, que manda o próprio mapa `versoes`. Uma sonda de "zero
    // `router.post` na página" **REPROVA código certo** (a 14ª ocorrência da
    // família nesta milestone).
    assert.equal(QUEM_ESCREVE.includes(REL_PAGINA), false, 'a PÁGINA entrou na lista — ela tem um `router.post` legítimo (o LOTE da Fase 187)');
    const postsDaPagina = [...FONTE_PAGINA.matchAll(/router\s*\.\s*post\(\s*\n?\s*route\('([^']+)'/g)].map((m) => m[1]);
    assert.deepEqual(postsDaPagina, ['mlb.anuncios.meus.aprovacao.lote'], 'a página passou a chamar `router.post` para rota do PAINEL — toda escrita do painel vai pelo helper');

    // (3) ⚠️ E o helper acrescenta `versao_esperada` **SEMPRE**, com a chave LIDA
    // do servidor.
    assert.equal(MOD_PAINEL.CHAVE_DA_VERSAO, 'versao_esperada', 'a chave da versão mudou na tela');
    assert.match(fs.readFileSync(path.resolve(RAIZ, REL_CONTROLADOR), 'utf8'), /'versao_esperada' => \['sometimes', 'nullable', 'integer', 'min:1'\]/, 'a action deixou de aceitar `versao_esperada` — ou mudou a regra');
    assert.match(lerSemComentarios(REL_SERVICO_APROVACAO), /REGRA_VERSAO_DIVERGENTE/, 'a regra de versão divergente saiu do serviço');

    STUB.CHAMADAS.length = 0;
    const saiu = MOD_PAINEL.escreverNoPainel({ rota: 'r.frete', parametros: { company: 1, oferta: 2 }, corpo: { valor: '18,50' }, versao: 7 });
    assert.equal(saiu, true, 'o helper não disparou com rota e versão válidas');
    assert.equal(STUB.CHAMADAS.length, 1, `o helper disparou ${STUB.CHAMADAS.length} chamadas`);
    assert.deepEqual(STUB.CHAMADAS[0][2], { valor: '18,50', versao_esperada: 7 }, 'o corpo saiu SEM `versao_esperada` — `exigirVersao()` receberia `null` e LIBERARIA a escrita');

    // ⚠️ E sem versão INTEIRA nada é enviado: não se escreve sobre uma oferta cuja
    // versão a tela não viu. ⚠️ `0`/negativo/`'7'`/`1.5` recusados — **`0` é versão
    // plausível** e passaria por uma guarda de "número".
    for (const adversa of [null, undefined, 0, -1, '7', 1.5, NaN, {}, [], { toString: null }]) {
        STUB.CHAMADAS.length = 0;
        const r = MOD_PAINEL.escreverNoPainel({ rota: 'r.frete', versao: adversa });
        if (adversa === 0 || adversa === -1) {
            // ⚠️ `0` e `-1` SÃO inteiros: o helper dispara, e a recusa é do SERVIDOR
            // (`min:1`). Isto fica MEDIDO em vez de presumido.
            assert.equal(r, true, 'o helper recusou um inteiro — a trava de `min:1` é do servidor');
            assert.equal(STUB.CHAMADAS[0][2].versao_esperada, adversa, 'a versão não viajou');
        } else {
            assert.equal(r, false, 'o helper disparou com versão que NÃO é inteiro');
            assert.equal(STUB.CHAMADAS.length, 0, 'o helper escreveu sem versão inteira');
        }
    }
    STUB.CHAMADAS.length = 0;
    assert.equal(MOD_PAINEL.escreverNoPainel({ versao: 3 }), false, 'o helper disparou SEM rota');
    assert.equal(MOD_PAINEL.escreverNoPainel(null), false, 'o helper disparou com pedido nulo');
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. ⚠️⚠️ O 422 NÃO É ENGOLIDO — E O CAMINHO REAL É O `invalid`
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ T-188-B0: o 422 do painel acende o aviso pelo `invalid`, lendo o CAMPO `regra` 188_12', () => {
    // ⚠️⚠️ **O MEDIDO, e é ele que explica o caminho novo:** as recusas do painel
    // são `response()->json([...], 422)` CRUAS, e o `onError` do Inertia só é
    // chamado DENTRO do ramo Inertia. Resposta não-Inertia cai em
    // `handleNonInertiaResponse()`, que dispara `inertia:invalid` e MOSTRA O MODAL.
    const controlador = fs.readFileSync(path.resolve(RAIZ, REL_CONTROLADOR), 'utf8');
    assert.match(controlador, /response\(\)->json\(\[\s*'message' => \$e->getMessage\(\),\s*'regra' => \$e->regra,\s*\], 422\)/, 'o formato da recusa do painel mudou — ele é `{message, regra}` com 422 CRU, e é por isso que o `onError` não basta');
    const inertia = fs.readFileSync(path.resolve(RAIZ, REL_INERTIA), 'utf8');
    assert.match(inertia, /handleNonInertiaResponse\(\)/, 'o `@inertiajs/core` mudou de forma — remeça o caminho do 422');
    assert.match(inertia, /fireInvalidEvent\(response\)/, 'o `@inertiajs/core` deixou de disparar o evento `invalid` — remeça o caminho do 422');
    assert.match(inertia, /onGlobalEvent\(type, callback\)/, 'o `router.on` do Inertia mudou de forma');

    // (a) O helper REGISTRA o ouvinte, e o REMOVE no fim.
    STUB.CHAMADAS.length = 0;
    STUB.OUVINTES.length = 0;
    const vistos = [];
    MOD_PAINEL.escreverNoPainel({ rota: 'r.aprovar', versao: 3, onConflito: (m) => vistos.push(m) });
    assert.equal(STUB.OUVINTES.length, 1, `o helper registrou ${STUB.OUVINTES.length} ouvintes de evento (esperado 1)`);
    assert.equal(STUB.OUVINTES[0][0], 'invalid', 'o helper não ouve o evento `invalid` — o 422 CRU nunca chegaria ao `onConflito`');

    // (b) ⚠️⚠️ O CAMPO `regra` é o que decide, e a MENSAGEM é a do servidor.
    const aoInvalidar = STUB.OUVINTES[0][1];
    const resposta = aoInvalidar({ detail: { response: { data: { message: MENSAGEM_DO_SERVIDOR, regra: MOD_PAINEL.REGRA_VERSAO_DIVERGENTE } } } });
    assert.deepEqual(vistos, [MENSAGEM_DO_SERVIDOR], 'o conflito não chegou ao `onConflito` — a pessoa acharia que aprovou, e aprovar JÁ PUBLICA');
    assert.equal(resposta, false, 'o ouvinte não CANCELOU o modal de erro do Inertia — dois sinais para o mesmo fato treinam a pessoa a ignorar os dois');

    // ⚠️ E qualquer OUTRA regra também acende (o aviso é da ação que falhou), mas
    // recusa **SEM `regra`** NÃO é cancelada: erro inesperado não vira silêncio.
    vistos.length = 0;
    const outra = aoInvalidar({ detail: { response: { data: { message: 'Esta oferta não está aberta para revisão.', regra: 'COL-04-FORA-DO-AO-VIVO' } } } });
    assert.deepEqual(vistos, ['Esta oferta não está aberta para revisão.'], 'outra regra do painel não chegou ao `onConflito`');
    assert.equal(outra, false, 'o ouvinte não cancelou o modal para uma recusa COM regra');

    vistos.length = 0;
    const semRegra = aoInvalidar({ detail: { response: { data: { message: 'Server Error' } } } });
    assert.deepEqual(vistos, [], 'uma recusa SEM `regra` chamou o `onConflito` — ela não é do painel');
    assert.notEqual(semRegra, false, 'uma recusa SEM `regra` foi CANCELADA — erro inesperado não pode virar silêncio');

    // ⚠️ E o evento adverso não derruba nada.
    for (const forma of [null, undefined, {}, { detail: null }, { detail: {} }, { detail: { response: null } }, { detail: { response: { data: null } } }, { detail: { response: { data: 'texto' } } }, { detail: { response: { data: { regra: 42 } } } }, { detail: { response: { data: { regra: { toString: null } } } } }]) {
        assert.doesNotThrow(() => aoInvalidar(forma), 'o ouvinte de `invalid` LANÇOU com evento adverso');
    }

    // (c) ⚠️ E o `onError` CONTINUA registrado e continua não engolindo: é o caminho
    // da sacola `errors` do Inertia, para o dia em que a recusa virar validação.
    const opcoes = STUB.CHAMADAS[0][3];
    assert.equal(typeof opcoes.onError, 'function', 'o helper não registrou `onError` — o 422 de VALIDAÇÃO seria engolido');
    assert.equal(typeof opcoes.onFinish, 'function', 'o helper não registrou `onFinish` — o ouvinte de `invalid` ficaria GLOBAL e pegaria o `invalid` de qualquer outra tela');
    vistos.length = 0;
    opcoes.onError({ regra: MOD_PAINEL.REGRA_VERSAO_DIVERGENTE, message: MENSAGEM_DO_SERVIDOR });
    assert.deepEqual(vistos, [MENSAGEM_DO_SERVIDOR], 'o `onError` deixou de LER o campo `regra` — ele é o formato do servidor');
    vistos.length = 0;
    opcoes.onError({ versao_esperada: `${MOD_PAINEL.REGRA_VERSAO_DIVERGENTE}: mudou` });
    assert.equal(vistos.length, 1, 'o FALLBACK por substring saiu — ele é o caminho da sacola `errors`, que não tem `regra`');
    for (const sacola of [null, undefined, 'texto', 42, [], {}, { x: null }, { x: 42 }, { x: { toString: null } }]) {
        assert.doesNotThrow(() => opcoes.onError(sacola), 'o `onError` LANÇOU com sacola adversa');
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. O AVISO: OS DOIS GATILHOS, E A VERSÃO ADVERSA QUE NÃO ACENDE
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ COL-04: o aviso acende nos DOIS gatilhos, e NÃO acende por engano 188_12', () => {
    const { avisoDeVersao } = MOD_AVISO;

    // GATILHO 2 — o 422: a mensagem do SERVIDOR é a que a tela mostra, e ela **não
    // é remontada no cliente** (ela diz as DUAS versões).
    const doServidor = avisoDeVersao(3, 3, MENSAGEM_DO_SERVIDOR);
    assert.ok(doServidor, 'o gatilho do 422 não acendeu o aviso');
    assert.equal(doServidor.texto, MENSAGEM_DO_SERVIDOR, 'a tela REMONTOU a mensagem do 422 — ela é a do servidor, que diz as DUAS versões');

    // GATILHO 1 — o payload divergente.
    const doPayload = avisoDeVersao(3, 4, null);
    assert.ok(doPayload, 'o gatilho do payload divergente não acendeu o aviso');
    assert.ok(doPayload.texto.includes('4'), 'o aviso não DIZ qual versão está valendo');
    assert.equal(doPayload.mensagemDoServidor, null, 'o gatilho do payload inventou mensagem de servidor');

    // ⚠️⚠️ COM AS VERSÕES IGUAIS O AVISO **NÃO** É DESENHADO — e é esta asserção que
    // TRAVA a premissa inobservável do gatilho 1 na página (ver o docblock da
    // página): sem modal aberto o painel vem fresco a cada abertura, e **não há
    // estado velho sobre o que avisar**.
    assert.equal(avisoDeVersao(4, 4, null), null, 'o aviso acendeu com as versões IGUAIS');
    assert.equal(avisoDeVersao(1, 1, null), null, 'o aviso acendeu com as versões IGUAIS');
    assert.equal(avisoDeVersao(4, 4, ''), null, 'mensagem VAZIA acendeu o aviso');
    assert.equal(avisoDeVersao(4, 4, '   '), null, 'mensagem de espaços acendeu o aviso');

    // ⚠️⚠️ VERSÃO ADVERSA **NÃO ACENDE** — acender sem motivo treina a pessoa a
    // ignorá-lo, e aí ele deixa de servir exatamente quando precisa.
    // ⚠️ `typeof NaN === 'number'`, e **`0` é versão plausível** que passaria por
    // uma guarda de "inteiro".
    const ADVERSAS = [null, undefined, 0, -1, '3', '4', 1.5, NaN, Infinity, {}, [], { toString: null }, () => {}];
    for (const a of ADVERSAS) {
        assert.equal(avisoDeVersao(a, 4, null), null, 'versão VISTA adversa acendeu o aviso');
        assert.equal(avisoDeVersao(3, a, null), null, 'versão ATUAL adversa acendeu o aviso');
        assert.equal(avisoDeVersao(a, a, null), null, 'as duas versões adversas acenderam o aviso');
    }

    // ⚠️ ISCA: a versão VÁLIDA e divergente acende. Sem esta ponta as asserções
    // acima passariam com a função devolvendo `null` sempre.
    assert.ok(avisoDeVersao(1, 2, null), 'o aviso não acende nem com versões válidas e divergentes — a sonda está cega');

    // ⚠️ E **ZERO `String(`** na comparação e na mensagem: `String({toString:null})`
    // LANÇA, e foi assim que 5 blocos de teste morreram DENTRO da mensagem de
    // asserção no plano 180-05.
    assert.equal(/\bString\(/.test(FONTE_AVISO), false, 'apareceu `String(` no aviso — ele LANÇA com `{toString:null}`');
    assert.match(FONTE_AVISO, /Number\.isInteger|inteiroDoPainel/, 'o aviso parou de usar a guarda de INTEIRO — `typeof NaN` é `number`');

    // ⚠️ E o render NÃO desenha nada quando não acende (nem casca vazia).
    const base = { onRecarregar: nada };
    assert.equal(render(MOD_AVISO.default, { ...base, versaoVista: 4, versaoAtual: 4, mensagem: null }), '', 'o aviso desenhou casca com as versões iguais');
    assert.equal(render(MOD_AVISO.default, { ...base }), '', 'o aviso desenhou casca sem dado nenhum');
    assert.notEqual(render(MOD_AVISO.default, { ...base, versaoVista: 3, versaoAtual: 4 }), '', 'o aviso não desenhou nada com versões divergentes');
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. É CAIXA BLOQUEANTE, NÃO TOAST — E O MOLDE É IMPORTADO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ T-188-B6: o aviso é CAIXA, não toast, e o molde vem IMPORTADO do aviso de preço 188_12', () => {
    // ⚠️⚠️ Toast tem vocabulário TRAVADO com gate (VOC-08, SEIS frases FECHADAS, e
    // o usuário RECUSOU o sétimo em 19/10) e **some em ~3,8 s**: *"um aviso de
    // concorrência que desaparece é um aviso que não houve"*.
    assert.equal(/ToastDaFila|TOASTS\b/.test(FONTE_AVISO), false, 'o aviso virou TOAST — ele some em ~3,8 s, e um aviso de concorrência que desaparece é um aviso que NÃO HOUVE');
    const vocab = lerSemComentarios(REL_VOCABULARIO);
    const bloco = vocab.match(/const\s+TOASTS\s*=\s*\{([\s\S]*?)\n\};/);
    assert.ok(bloco, 'não achei o literal de `TOASTS` em `vocabularioDoPortao.jsx` — a leitura está cega');
    const frases = [...bloco[1].matchAll(/'([^']{6,})'/g)].map((m) => m[1]);
    assert.ok(frases.length >= 6, `o vocabulário de toast deixou de ter pelo menos SEIS frases (tem ${frases.length})`);
    const cunhadas = frases.filter((f) => FONTE_AVISO.includes(f));
    assert.deepEqual(cunhadas, [], 'o aviso usou uma das frases FECHADAS de VOC-08 — os seis toasts são fechados, e o usuário RECUSOU o sétimo');
    // ⚠️ ISCA: a leitura das frases NÃO está cega.
    assert.ok(frases.some((f) => vocab.includes(f)), 'a leitura das frases de toast está cega');

    // ⚠️ O MOLDE: COL-04 **não tem elemento no protótipo**, e o molde do aviso de
    // alteração de preço (`:649`-`:651`) foi reusado **por SEMELHANÇA DE FUNÇÃO**,
    // com as classes **IMPORTADAS** de `KpisDoPainel.jsx` — copiá-las criaria a
    // segunda cópia das mesmas medidas, e seriam duas caixas âmbares diferentes na
    // mesma tela.
    assert.match(FONTE_AVISO, /from '\.\/KpisDoPainel'/, 'o aviso parou de IMPORTAR o molde — ele viraria uma CÓPIA das medidas');
    for (const peca of ['CLASSES_AVISO', 'CLASSES_GLIFO', 'CLASSES_TEXTO_DO_AVISO', 'CLASSES_CONFERIR']) {
        assert.ok(FONTE_AVISO.includes(peca), `o aviso parou de importar \`${peca}\``);
    }
    // ⚠️ E ele NÃO redeclara nenhuma das medidas do molde.
    const MEDIDAS = ['bg-pub-row-new', 'border-pub-accent/[0.35]', 'rounded-pub-control', 'py-[9px]', 'px-[12px]', 'text-[12.5px]'];
    assert.deepEqual(MEDIDAS.filter((m) => FONTE_AVISO.includes(m)), [], 'o aviso REDECLAROU medida do molde — ele tem de IMPORTAR');
    // ⚠️ A RAZÃO é artefato e mora no docblock (leitura CRUA).
    assert.match(CRUA_AVISO, /não tem elemento no protótipo/i, 'o docblock parou de declarar que COL-04 não tem elemento no protótipo');
    assert.match(CRUA_AVISO, /SEMELHANÇA DE FUNÇÃO/i, 'o docblock parou de declarar POR QUE o molde foi reusado');

    // ⚠️ E as classes que saem no HTML são as MESMAS do aviso de preço.
    const html = render(MOD_AVISO.default, { versaoVista: 3, versaoAtual: 4, onRecarregar: nada });
    const els = tagsDeAbertura(html);
    assert.deepEqual(els.map((e) => e.tag), ['div', 'span', 'span', 'button'], 'a árvore do aviso mudou de forma');
    const kpis = lerSemComentarios(REL_KPIS);
    const doMolde = kpis.match(/CLASSES_AVISO = '([^']*)'\s*\n?\s*\+ '([^']*)'/);
    assert.ok(doMolde, 'não achei o literal de `CLASSES_AVISO` em `KpisDoPainel.jsx` — a leitura está cega');
    const esperadas = new Set((doMolde[1] + doMolde[2]).split(/\s+/).filter((c) => c !== ''));
    assert.deepEqual([...classesDe(els[0])].sort(), [...esperadas].sort(), 'a caixa do aviso divergiu do molde de `:649`');

    // ⚠️ O TEXTO é o sinal principal (D7: cor **e glifo** nunca são o único sinal),
    // e o botão existe com rótulo PRÓPRIO — é ele que distingue os dois avisos.
    const visivel = texto(html);
    assert.ok(visivel.includes('Esta oferta mudou enquanto você revisava'), 'o aviso não DIZ que a oferta mudou');
    assert.ok(visivel.includes('Recarregar a revisão'), 'o botão do aviso não tem o rótulo PRÓPRIO — é ele que distingue este aviso do de alteração de preço');
    assert.equal(MOD_AVISO.ROTULO_DA_ACAO, 'Recarregar a revisão', 'o rótulo do botão mudou');
    assert.notEqual(MOD_AVISO.ROTULO_DA_ACAO, 'Conferir alteração', 'o aviso ficou com o rótulo do OUTRO aviso — eles seriam indistinguíveis');
    assert.equal(MOD_AVISO.GLIFO, '↻', 'o glifo mudou');
    assert.match(html, /title="Recarregar a revisão para ver o estado atual da oferta"/, 'o `title` do botão sumiu — a tela nunca depende só do rótulo curto');

    // ⚠️ Sem handler o botão NÃO nasce, e o TEXTO continua aparecendo (D4).
    const semHandler = render(MOD_AVISO.default, { versaoVista: 3, versaoAtual: 4, onRecarregar: null });
    assert.equal(/<button/.test(semHandler), false, 'sem handler o botão foi desenhado — `onClick` vazio e `disabled` decorativo foram RECUSADOS');
    assert.ok(texto(semHandler).includes('Esta oferta mudou'), 'sem handler o TEXTO sumiu junto com o botão');
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. A TELA NÃO REABRE NADA, E O SLOT É O PRIMEIRO DO CORPO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ CRITÉRIO 17: a tela RECARREGA, não reabre; e o slot `aviso` é o PRIMEIRO do corpo 188_12', () => {
    // ⚠️ *"REABRE as verificações afetadas"* é efeito da **Fase 186**
    // (`registrarMudanca()`, BLOQ-05/BLOQ-06). ➡️ A tela não reabre nada: ela
    // **recarrega e mostra o estado REAL**.
    const DE_REABERTURA = [/reabr/i, /\breopen\b/i, /aguardando_aprovacao/, /andamento\s*=/, /bloqueios\s*=/];
    assert.deepEqual(DE_REABERTURA.filter((re) => re.test(FONTE_AVISO)), [], 'o aviso passou a falar de REABERTURA — seria a segunda verdade sobre o que reabre a aprovação');
    assert.equal(DE_REABERTURA[0].test('reabrirOfertas()'), true, 'a regex de reabertura não casa o próprio caso — gate cego');
    assert.match(lerSemComentarios(REL_SERVICO_APROVACAO), /function registrarMudanca\(/, 'o `registrarMudanca()` saiu do serviço — é ELE que reabre');
    assert.match(CRUA_AVISO, /ela recarrega e mostra o estado/i, 'o docblock parou de declarar que a tela NÃO reabre');

    // ⚠️ O slot `aviso` é o **PRIMEIRO filho do corpo rolável**: aviso que aparece
    // depois de duas colunas e de uma seção de pendências é aviso lido **depois do
    // clique**.
    const marca = 'data-isca-aviso-188-12';
    const html = renderToStaticMarkup(React.createElement(MOD_PAINEL.RaizDoPainel, {
        identificacao: null,
        falha: null,
        navegacao: null,
        aviso: React.createElement('div', { key: 'a', [marca]: 'sim' }, 'o aviso'),
        pendencias: React.createElement('div', { key: 'p' }, 'as pendencias'),
        rodape: React.createElement('div', { key: 'r' }, 'o rodape'),
        modais: React.createElement('div', { key: 'm' }, 'o modal'),
        onFechar: nada,
    }));
    const iAviso = html.indexOf(marca);
    const iPendencias = html.indexOf('as pendencias');
    const iRodape = html.indexOf('o rodape');
    const fimDoAside = html.indexOf('</aside>');
    const iModal = html.indexOf('o modal');
    assert.ok(iAviso >= 0, 'o slot `aviso` não chegou à árvore');
    assert.ok(iAviso < iPendencias, 'o aviso ficou DEPOIS das pendências — aviso lido depois do clique é aviso que não houve');
    assert.ok(iAviso < fimDoAside, 'o aviso saiu do `<aside>` — ele rola com o corpo de propósito: não é modal, e o que ele bloqueia é a AÇÃO que falhou');
    assert.ok(iRodape > iPendencias && iRodape < fimDoAside, 'o rodapé saiu do lugar — ele é irmão do CORPO');
    assert.ok(iModal > fimDoAside, 'o slot `modais` entrou no `<aside>` — ele é IRMÃO dele');

    // ⚠️ Sem nó o slot não desenha NADA.
    const sem = renderToStaticMarkup(React.createElement(MOD_PAINEL.RaizDoPainel, {
        identificacao: null, falha: null, navegacao: null, aviso: null, pendencias: null, rodape: null, modais: null, onFechar: nada,
    }));
    assert.equal(sem.includes(marca), false, 'o slot vazio desenhou algo');

    // ⚠️ E a página liga o slot, com a mensagem do conflito e o handler de recarga.
    assert.match(FONTE_PAGINA, /aviso=\{noAvisoDoPainel\}/, 'a página não ligou o slot `aviso`');
    assert.match(FONTE_PAGINA, /<AvisoDeVersaoMudou/, 'a página não monta o `AvisoDeVersaoMudou`');
    assert.match(FONTE_PAGINA, /onConflito: registrarConflito/, 'a página não liga o `onConflito` do helper — o 422 seria ENGOLIDO');
    assert.equal(/onConflito=\{null\}|onConflito: null/.test(FONTE_PAGINA), false, 'sobrou um `onConflito` NULO na página — aquela escrita engoliria o 422');
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. AS LISTAS, O ESTADO DA PÁGINA E O MANIFESTO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ VOC-07: as listas, o `conflitoDoPainel` declarado, os slots de NÓ e o manifesto 188_12', () => {
    const DO_PLANO = ['ModalDeFrete.jsx', 'ModalDeReprovarImagem.jsx', 'AvisoDeVersaoMudou.jsx'];

    const bloco = lerSemComentarios(REL_GATE_VOCABULARIO).match(/const\s+ARQUIVOS_NOVOS\s*=\s*\[([\s\S]*?)\n\];/);
    assert.ok(bloco, 'não achei o literal de `ARQUIVOS_NOVOS` — a leitura está cega');
    const declarados = [...bloco[1].matchAll(/\$\{DIR_APROV\}\/([\w.-]+\.jsx)/g)].map((m) => m[1]);
    assert.deepEqual(DO_PLANO.filter((n) => !declarados.includes(n)), [], '`.jsx` deste plano ficou FORA de `ARQUIVOS_NOVOS`');

    const bloco2 = lerSemComentarios(REL_GATE_FECHAMENTO).match(/const\s+JSX_DEPOIS_DA_187\s*=\s*\[([\s\S]*?)\n\];/);
    assert.ok(bloco2, 'não achei o literal de `JSX_DEPOIS_DA_187` — a leitura está cega');
    const depois = [...bloco2[1].matchAll(/\$\{DIR_APROV\}\/([\w.-]+\.jsx)/g)].map((m) => m[1]);
    assert.deepEqual(DO_PLANO.filter((n) => !depois.includes(n)), [], '`.jsx` deste plano ficou FORA de `JSX_DEPOIS_DA_187`');

    // ⚠️ `ESTADO_LOCAL_DA_PAGINA` — leitura CRUA sem linha de comentário (ver o
    // helper no topo: `lerSemComentarios` come aquele arquivo).
    const bloco3 = lerSemLinhaDeComentario(REL_GATE_FILTROS).match(/const\s+ESTADO_LOCAL_DA_PAGINA\s*=\s*\[([\s\S]*?)\n\s{4}\];/);
    assert.ok(bloco3, 'não achei o literal de `ESTADO_LOCAL_DA_PAGINA` — a leitura está cega');
    const estados = [...bloco3[1].matchAll(/'([^']+)'/g)].map((m) => m[1]);
    assert.ok(estados.includes('conflitoDoPainel'), '`conflitoDoPainel` ficou FORA de `ESTADO_LOCAL_DA_PAGINA` — aquele gate reprova por `deepEqual`');
    assert.ok(estados.includes('modalDoPainel'), '`modalDoPainel` saiu de `ESTADO_LOCAL_DA_PAGINA`');
    assert.match(FONTE_PAGINA, /const \[conflitoDoPainel, setConflitoDoPainel\] = useState\(null\)/, 'a página parou de guardar o conflito na forma declarada');
    // ⚠️ E ele NÃO vai para a URL: um link compartilhado abriria um aviso de
    // conflito que não é da pessoa.
    assert.doesNotMatch(FONTE_PAGINA, /querystringDe\([^)]*conflito/, '`conflito` entrou no montador de querystring');

    // ⚠️ SLOTS DE NÓ: comparados contra a UNIÃO das DUAS listas declaradas no gate
    // da 187 — **nunca** contra uma lista cravada aqui (o furo "sonda mais ESTREITA
    // que o invariante" medido na wave 188-11: `proximo`/`rodape`/`tabela` estão NO
    // AR desde a Fase 187).
    const slotsDaPagina = [...new Set([...FONTE_PAGINA.matchAll(/isValidElement\(\s*([A-Za-z_$][\w$]*)\s*\)/g)].map((m) => m[1]))].sort();
    const blocosDeSlot = [...lerSemComentarios(REL_GATE_CABECALHO).matchAll(/const\s+SLOTS_DE_NO_(?:DA_187|DEPOIS_DA_187)\s*=\s*\[([^\]]*)\]/g)];
    assert.equal(blocosDeSlot.length, 2, 'não achei as DUAS listas de slots de NÓ no gate da 187 — a leitura está cega');
    const slotsDeclarados = [...new Set(blocosDeSlot.flatMap((b) => [...b[1].matchAll(/'([^']+)'/g)].map((m) => m[1])))].sort();
    assert.ok(slotsDeclarados.length > 0, 'as listas de slots de NÓ saíram VAZIAS — a leitura está cega');
    assert.deepEqual(slotsDaPagina, slotsDeclarados, 'a página abriu `isValidElement(` novo — `SLOTS_DE_NO_DEPOIS_DA_187` (TRÊS pontas) tem de ser remedido. Esta wave NÃO abre slot novo na página: `aviso` e `modais` nascem em `PainelDeRevisao.jsx` e viajam por EXPRESSÃO');

    // O MANIFESTO.
    const manifesto = fs.readFileSync(path.resolve(RAIZ, REL_MANIFESTO), 'utf8');
    const linhas = manifesto.split(/\r?\n/).filter((l) => l.trim() !== '' && !l.trim().startsWith('#'));
    const esteArquivo = 'tests/js/publicador-painel-aviso-versao-188.test.js';
    const minhas = linhas.filter((l) => l.includes(esteArquivo));
    assert.equal(minhas.length, 1, `o manifesto declara ${minhas.length} ids para ${esteArquivo} — é UM id por arquivo de gate`);
    assert.match(minhas[0], /^aviso-de-versao\s+js\s+/, 'o id deste arquivo deixou de ser `aviso-de-versao`');
    const porArquivo = new Map();
    for (const linha of linhas) {
        for (const arquivo of linha.trim().split('::')[0].trim().split(/\s+/).slice(2)) {
            porArquivo.set(arquivo, (porArquivo.get(arquivo) ?? 0) + 1);
        }
    }
    assert.deepEqual([...porArquivo].filter(([, n]) => n > 1), [], 'arquivo de gate com MAIS DE UM id');
    assert.deepEqual([...porArquivo.keys()].filter((rel) => !fs.existsSync(path.resolve(RAIZ, rel))), [], 'o manifesto aponta para arquivo de gate que não existe');
    assert.deepEqual(linhas.filter((l) => /\s+php\s+/.test(l)), [], 'entrou id `php` no manifesto');

    const plano = fs.readFileSync(path.resolve(RAIZ, REL_PLANO), 'utf8');
    const ids = linhas.map((l) => l.trim().split(/\s+/)[0]);
    assert.equal(new Set(ids).size, ids.length, 'id REPETIDO no manifesto');
    const doVerify = [...plano.matchAll(/verificar-testes\.sh\s+188-12\s+([\w-]+)/g)].map((m) => m[1]);
    assert.ok(doVerify.length > 0, 'não achei `<verify>` com `188-12` no plano — a leitura está cega');
    assert.deepEqual(ids.filter((id) => !doVerify.includes(id)), [], 'id declarado no manifesto que NENHUM `<verify>` roda');
    assert.deepEqual(doVerify.filter((id) => !ids.includes(id)), [], 'id usado por um `<verify>` e não declarado no manifesto');
    const EXPANSOR = /test_(?:\([A-Za-z0-9_|]+\)|[A-Za-z0-9_]+)/;
    assert.equal(EXPANSOR.test('test_(a|b)_188_12'), true, 'o expansor de alternância não casa a forma `test_(a|b)`');
    assert.equal(EXPANSOR.test('test_simples_188_12'), true, 'o expansor deixou de casar a forma simples');

    const nomes = [...fs.readFileSync(path.resolve(RAIZ, esteArquivo), 'utf8').matchAll(/^test\('([^']*)'/gm)].map((m) => m[1]);
    assert.ok(nomes.length > 0, 'não achei os nomes de teste — a leitura está cega');
    assert.deepEqual(nomes.filter((n) => !n.endsWith('188_12')), [], 'nome de teste sem o sufixo `188_12`');

    const tamanhos = linhas.filter((l) => l.startsWith('vocabulario-tamanhos'));
    assert.equal(tamanhos.length, 1, '`vocabulario-tamanhos` não está em linha própria');
    assert.match(tamanhos[0], /tests=>=\d+/, 'a forma de `vocabulario-tamanhos` deixou de ser `>=`');
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. A TELA PRETA, O `font-pub` E O TERRITÓRIO
// ═══════════════════════════════════════════════════════════════════════════

const FORMAS_ADVERSAS = [
    null, undefined, 0, 1, -1, NaN, Infinity, '', '   ', 'texto', true, false,
    {}, [], [{}], { toString: null }, { valueOf: null }, () => {},
];

const SEM_OBJETO_ESTRANHO = /\[object Object\]|NaN|undefined|Invalid Date/;

test('⚠️⚠️ REND / critério 19: nenhum campo adverso derruba o aviso, nem no CLIQUE 188_12', () => {
    for (const campo of ['versaoVista', 'versaoAtual', 'mensagem']) {
        for (const forma of FORMAS_ADVERSAS) {
            let html = '';
            const props = { versaoVista: 3, versaoAtual: 4, mensagem: null, onRecarregar: nada, [campo]: forma };
            assert.doesNotThrow(() => { html = render(MOD_AVISO.default, props); }, `o campo \`${campo}\` derrubou o render`);
            assert.doesNotMatch(html, SEM_OBJETO_ESTRANHO, `o campo \`${campo}\` vazou para a tela`);
        }
    }

    for (const forma of FORMAS_ADVERSAS) {
        assert.doesNotThrow(() => render(MOD_AVISO.default, { versaoVista: forma, versaoAtual: forma, mensagem: forma, onRecarregar: forma }), 'a prop inteira adversa derrubou o render');
        assert.doesNotThrow(() => MOD_AVISO.avisoDeVersao(forma, forma, forma), 'a função pura LANÇOU com forma adversa');
    }

    // ⚠️⚠️ E TAMBÉM NO CLIQUE.
    for (const forma of FORMAS_ADVERSAS) {
        assert.doesNotThrow(() => {
            const el = React.createElement(MOD_AVISO.default, { versaoVista: 3, versaoAtual: 4, mensagem: forma, onRecarregar: nada });
            const arvore = el.type(el.props);
            if (arvore === null) return;
            const botao = arvore.props.children.find((f) => f && f.props && typeof f.props.onClick === 'function');
            if (botao) botao.props.onClick();
        }, 'o CLIQUE no botão de recarregar derrubou a página');
    }
});

test('⚠️ TERRITÓRIO e critério 15: o recorte CONSTANTE desta tarefa, com isca do recorte 188_12', () => {
    const VETADOS = [
        [/localStorage/, 'localStorage é artefato do protótipo'],
        [/Restaurar dados de exemplo/, 'texto de protótipo'],
        [/\bString\(/, '`String(` LANÇA com `{toString:null}`'],
        [/JSON\.stringify\(/, '`JSON.stringify` mente e lança'],
        [/#[0-9A-Fa-f]{6}\b/, 'TOK-15: hex literal no código'],
        [/\btext-(sm|xs|base|lg|xl)\b/, 'FID-03: escala nomeada'],
        [/\becf-(bg|card|yellow)\b/, 'FID-04: token `ecf-*` vetado'],
        [/font-display/, 'FID-04: `font-display` vetado'],
        [/border-white\/\[0\.08\]/, 'FID-04: a borda translúcida vetada'],
        [/\b(sm|md|lg|xl|2xl):/, 'FID-05: breakpoint'],
        [/Ampliar/, 'a Fase 189 NÃO é antecipada'],
        [/Gerar novamente com IA/, 'a Fase 192 NÃO é antecipada'],
        [/confirmar publica|segundo passo|cancelar aprova/i, 'decisão 1: aprovar JÁ PUBLICA'],
        [/lucro l[íi]quido/i, 'vocabulário travado'],
        [/duration-\[\d+ms\]/, '`duration-[Nms]` NÃO COMPILA'],
        [/new Date\(/, 'COL-06: a data é formatada no SERVIDOR'],
        [/setTimeout\(|setInterval\(/, 'o aviso é BLOQUEANTE: ele não some com o tempo'],
    ];
    const infratores = [];
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        for (const [re, razao] of VETADOS) {
            if (re.test(fonte)) infratores.push(`${rel} ~ ${razao}`);
        }
    }
    assert.deepEqual(infratores, [], 'apareceu forma VETADA no arquivo desta tarefa');

    const ISCAS = [
        'localStorage.getItem(1)', 'Restaurar dados de exemplo', 'String(x)', 'JSON.stringify(x)',
        '#FACC15', 'text-sm', 'ecf-yellow', 'font-display', 'border-white/[0.08]', 'md:flex',
        'Ampliar', 'Gerar novamente com IA', 'confirmar publicação', 'lucro líquido', 'duration-[180ms]',
        'new Date()', 'setTimeout(f, 3800)',
    ];
    assert.equal(ISCAS.length, VETADOS.length, 'a lista de iscas e a de vetos divergiram');
    assert.deepEqual(VETADOS.filter(([re], i) => !re.test(ISCAS[i])).map(([, r]) => r), [], 'regex que não casa o próprio caso — gate cego');
    assert.deepEqual(FONTES_DO_PLANO.map(([rel]) => rel), [REL_AVISO], 'o recorte deste gate deixou de ser a lista CONSTANTE desta tarefa');
    assert.ok(FONTES_DO_PLANO.every(([rel]) => !rel.startsWith('resources/') || rel.endsWith('.jsx')), 'peça nova sob `resources/` saiu como `.js`');

    // Os INTOCÁVEIS e o lockfile.
    for (const rel of [REL_TOAST, REL_GATE_ZINDEX, REL_VOCABULARIO, `${DIR_APROV}/CascaDeModal.jsx`, `${DIR_APROV}/BarraDeMargem.jsx`, 'resources/js/Layouts/AppLayout.jsx', 'resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx', 'package.json', 'package-lock.json']) {
        assert.equal(numstatDe(rel).trim(), '', `${rel} foi alterado — ele é INTOCÁVEL/em produção`);
    }
    assert.notEqual(numstatDe(REL_TOAST, ARVORE_VAZIA).trim(), '', 'a sonda de `numstat` está cega: nem contra a árvore vazia ela produz linha');

    // ⚠️ E os três arquivos do `.planning/` que não pertencem a plano nenhum (GATE-07).
    for (const rel of ['.planning/ROADMAP.md', '.planning/STATE.md', '.planning/REQUIREMENTS-v27.md']) {
        if (fs.existsSync(path.resolve(RAIZ, rel))) {
            assert.equal(numstatDe(rel).trim(), '', `${rel} foi alterado — GATE-07: nenhum dos três pertence a plano nenhum`);
        }
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. O CSS CONSTRUÍDO
// ═══════════════════════════════════════════════════════════════════════════

function escapaRegex(texto_) {
    return texto_.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function seletorDe(classe) {
    return `.${[...classe].map((ch) => (/[A-Za-z0-9_-]/.test(ch) ? ch : `\\${ch}`)).join('')}`;
}

const FONTES_DO_CSS = [REL_AVISO];

function carregarCssConstruido() {
    const manifestRel = 'public/build/manifest.json';
    const manifestAbs = path.resolve(RAIZ, manifestRel);
    if (!fs.existsSync(manifestAbs)) return { motivo: `${manifestRel} ausente — rode \`npm run build\`` };

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

test('⚠️ FID: as classes do aviso GERAM CSS com o valor certo 188_12', async (contexto) => {
    if (CSS.motivo) {
        contexto.skip(CSS.motivo);

        return;
    }

    const regraDe = (classe) => {
        const re = new RegExp(`${escapaRegex(seletorDe(classe))}(?![\\w\\\\-])[^{}]*\\{([^}]*)\\}`);
        const m = CSS.css.match(re);

        return m ? m[1] : null;
    };

    await contexto.test('⚠️ ISCA: a sonda reporta AUSENTE o que não existe, e ACHA o que existe', () => {
        assert.ok(CSS.css.length > 100000, `CSS suspeito de truncado: ${CSS.css.length} bytes`);
        for (const isca of ['bg-pub-isca-188-12c', 'text-[87.5px]', 'w-[1972px]', 'top-[68.5%]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
        }
        assert.notEqual(regraDe('text-[12.5px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('⚠️⚠️ o MOLDE de `:649`-`:651`, com as OITO normalizações', () => {
        // ⚠️ 1ª/2ª — token SÓLIDO sai em `rgb(R G B / var(…))`.
        assert.match(regraDe('bg-pub-row-new'), /rgb\(20 18 10/, '`bg-pub-row-new` não é #14120A (`:649`)');
        assert.match(regraDe('text-pub-accent'), /rgb\(250 204 21/, '`text-pub-accent` não é #FACC15 (o glifo de `:650`)');
        assert.match(regraDe('text-pub-fase1'), /rgb\(212 212 216/, '`text-pub-fase1` não é #D4D4D8 (o texto de `:650`)');
        // ⚠️ 3ª — a opacidade em `border` sai em **hex de 8 dígitos**: `0.35` → `0x59`.
        assert.match(regraDe('border-pub-accent/[0.35]'), /#facc1559/, 'a borda do aviso não é `rgba(250,204,21,0.35)` (`:649`)');
        assert.match(regraDe('border-pub-accent/[0.5]'), /#facc1580/, 'a borda do botão não é `rgba(250,204,21,0.5)` (`:651`)');

        const semRegra = [
            'rounded-pub-control', 'rounded-pub-chip', 'flex', 'items-center', 'flex-1', 'border',
            'cursor-pointer', 'whitespace-nowrap', 'font-bold', 'font-semibold',
            'h-[26px]', 'px-[10px]', 'px-[12px]', 'py-[9px]', 'gap-[10px]',
            'text-[12px]', 'text-[12.5px]',
        ].filter((c) => regraDe(c) === null);
        assert.deepEqual(semRegra, [], 'classe do aviso NÃO gerou CSS — num `.js` isso aconteceria com TODO teste de render passando');
    });
});
