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
// Fase 188, plano 11, TAREFA 2 — MOD-02: "Solicitar exceção autorizada".
//
// ⚠️⚠️ DE CADA BLOCO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR: "o que
// precisaria estar errado no mundo para ele falhar?" — **"Nada" é ACHADO.**
//
//  1. ⚠️⚠️ LIBERA SÓ DOIS → a exceção abrindo para um bloqueio que não é dela.
//     "Permitido a mais" aqui significa **publicar um anúncio que ninguém
//     conferiu**, sem desfazer (T-186-17). ➡️ **ISCA POR BLOQUEIO nos SETE
//     outros**: nenhum abre, nem com casca vazia; os DOIS abrem. E a lista é a
//     FECHADA de `PubAprovacaoExcecao::LIBERA`, lida do PHP.
//  2. ⚠️⚠️ `>= 10` E NÃO `> 10` → `:1398`/`:1203` recusam com `length < 10`, logo
//     **10 PASSA**. ➡️ Medido em **9, 10 e 11**, e com `trim()` (12 espaços + 2
//     letras **não** é 10). A fronteira é onde o erro mora.
//  3. ⚠️⚠️ "AGUARDANDO AUTORIZAÇÃO" → estado que **NÃO EXISTE** (decisão 6 do
//     usuário / DADO-11). ⚠️ A sonda ANCORA NO SUJEITO: *"Aguardando aprovação"*
//     é vocabulário **correto** do domínio e casa 10 ocorrências legítimas na
//     área — a sonda tem de discriminar **a exceção esperando**. ⚠️ E conta com a
//     cedilha e os acentos: `aprovad` **não** casa `aprovação`, e silêncio que
//     depende de UMA LETRA é sorte.
//  4. ⚠️⚠️ A PROSA DO PROTÓTIPO DE `:804` → *"No protótipo, a exceção é
//     registrada…"* é **fala SOBRE o protótipo**, é jargão, e **D7 proíbe jargão
//     na tela**. ➡️ Ausente, com a nossa nota PRESENTE (as duas pontas).
//  5. ⚠️⚠️ OS NOMES DE `:1399` → `Ricardo Gomes`/`Carla Mendes` são **DADO DE
//     EXEMPLO** com um CARGO inventado (não existe "cargo na conta" neste
//     sistema). Mesma classe das taxas fictícias (D8). ➡️ Proibidos na fonte; a
//     lista vem do servidor. ⚠️ Lista VAZIA → a tela **DIZ** e **não envia**.
//  6. ⚠️ O `12%` DE `:1399` É `MIN` → o detalhe é o TEXTO QUE O SERVIDOR
//     ESCREVEU, com o mínimo REAL. ➡️ Fixture com mínimo **≠ 12**, e zero
//     formatação de dinheiro/percentual na fonte.
//  7. ⚠️⚠️ O SLOT `modais` É IRMÃO DO `<aside>` → dentro do corpo
//     (`overflow-y-auto`) o modal ROLARIA; dentro do `<aside>` ficaria preso aos
//     1140px. **As duas com todo teste de render passando** — é a MESMA classe de
//     erro que fez o rodapé ganhar slot próprio na 188-10. ➡️ Medido por ÁRVORE.
//  8. ⚠️ `Escape` FECHA O MODAL ANTES DO PAINEL (`:1014`) → pela função pura
//     `resolverAtalhoDoPainel()` do 188-07, testada na COMPOSIÇÃO.
//  9. SEGUNDA CASCA, `font-pub`, LITERAIS CONGELADOS, `disabled=` por atributo,
//     ESCRITA, TELA PRETA, ROLLUP, TERRITÓRIO e LISTAS — os mesmos invariantes do
//     gate da tarefa 1, recortados a ESTE `.jsx`.
//
// ⚠️ Todos os nomes de teste terminam em `188_11`.
// ⚠️ `design_handoff_publicador/` **NÃO é versionado**: o bloco que depende dele
// degrada para `skip` EXPLÍCITO e NOMEADO.
// ⚠️⚠️ **TODOS os bundles são montados ANTES do primeiro `test()`.**
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

const STUB_INERTIA = path.join(__dirname, `.pub-excecao188-inertia-stub-${process.pid}.mjs`);

fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
globalThis.CHAMADAS_EXCECAO_188_11 = globalThis.CHAMADAS_EXCECAO_188_11 ?? [];
const CHAMADAS = globalThis.CHAMADAS_EXCECAO_188_11;
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
        `.pub-excecao188-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';
const REL_EXCECAO = `${DIR_APROV}/ModalDeExcecao.jsx`;
const REL_CASCA = `${DIR_APROV}/CascaDeModal.jsx`;
const REL_TOAST = `${DIR_APROV}/ToastDaFila.jsx`;
const REL_PAINEL = `${DIR_APROV}/PainelDeRevisao.jsx`;
const REL_VOCABULARIO = `${DIR_APROV}/vocabularioDoPortao.jsx`;
const REL_PAGINA = 'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx';
const REL_GATE_VOCABULARIO = 'tests/js/publicador-vocabulario-novo.test.js';
const REL_GATE_FECHAMENTO = 'tests/js/publicador-fila-fechamento-187.test.js';
const REL_GATE_FILTROS = 'tests/js/publicador-fila-filtros-187.test.js';
const REL_GATE_CABECALHO = 'tests/js/publicador-fila-cabecalho-187.test.js';
const REL_GATE_ZINDEX = 'tests/js/publicador-header-zindex-ordem.test.js';
const REL_MODELO_EXCECAO = 'app/Models/PubAprovacaoExcecao.php';
const REL_SERVICO_BLOQUEIOS = 'app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php';
const REL_SERVICO_AJUSTES = 'app/Services/Publicador/Aprovacao/AjustesDaOfertaService.php';
const REL_MANIFESTO = 'tests/gates/contagens-188-11.txt';
const REL_PLANO = '.planning/phases/188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece/188-11-PLAN.md';
const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

const CAMINHOS_CITADOS = [
    REL_EXCECAO, REL_CASCA, REL_TOAST, REL_PAINEL, REL_VOCABULARIO, REL_PAGINA,
    REL_GATE_VOCABULARIO, REL_GATE_FECHAMENTO, REL_GATE_FILTROS, REL_GATE_CABECALHO, REL_GATE_ZINDEX,
    REL_MODELO_EXCECAO, REL_SERVICO_BLOQUEIOS, REL_SERVICO_AJUSTES, REL_MANIFESTO, REL_PLANO,
];

/**
 * ⚠️⚠️ ACHADO DE FERRAMENTA DESTA WAVE: **`lerSemComentarios` DESTRÓI 76% de
 * `tests/js/publicador-fila-filtros-187.test.js`**, e o motivo é um `/*` que NÃO
 * é comentário.
 *
 * Aquele arquivo tem `'date-fns/*'` na lista `external` do esbuild (`:118`). O
 * O stripper de bloco do helper lê aquele `/`+`*` como ABERTURA de comentário e
 * fecha no FECHAMENTO do próximo JSDoc — comendo as linhas `:118`-`:816`.
 * ⚠️ Esta prosa NÃO escreve a sequência de fechamento de bloco por extenso, e isso
 * é deliberado: escrevê-la aqui FECHARIA ESTE PRÓPRIO DOCBLOCK e o arquivo
 * reprovaria com `SyntaxError` — aconteceu na primeira redação deste comentário.
 * É a MESMA família do defeito que ele descreve.
 * **MEDIDO: 62656 bytes crus → 15249 depois do helper**, e
 * `ESTADO_LOCAL_DA_PAGINA` (`:716`) desaparece.
 * ⚠️ A sonda deste gate reprovou com *"não achei o literal — a leitura está
 * cega"*, que é o comportamento CERTO: foi a asserção NÃO-CEGA que pegou.
 * ➡️ Para LER AQUELE ARQUIVO usa-se esta leitura: crua, tirando **só** o
 * comentário de LINHA. ⚠️ Não se conserta `_fonte.js`: ele é usado por dezenas de
 * gates em produção, e mexer nele agora mudaria o que todos eles medem.
 */
function lerSemLinhaDeComentario(rel) {
    return fs.readFileSync(path.resolve(RAIZ, rel), 'utf8')
        .split(/\r?\n/)
        .map((linha) => linha.replace(/(^|[^:])\/\/.*$/, '$1'))
        .join('\n');
}

const FONTE_EXCECAO = lerSemComentarios(REL_EXCECAO);
const CRUA_EXCECAO = fs.readFileSync(path.resolve(RAIZ, REL_EXCECAO), 'utf8');
const FONTE_PAGINA = lerSemComentarios(REL_PAGINA);

const FONTES_DO_PLANO = [[REL_EXCECAO, FONTE_EXCECAO]];

const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

const nada = () => {};

const ARVORE_VAZIA = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';

const numstatDe = (rel, base) => execFileSync(
    'git',
    ['diff', '--numstat', base ?? 'HEAD', '--', rel],
    { cwd: RAIZ, encoding: 'utf8' },
);

function tagsDeAbertura(html) {
    return [...html.matchAll(/<(div|span|section|aside|button|input|label|select|option|textarea|a)\b([^>]*)>/g)].map((m) => ({
        tag: m[1],
        atributos: m[2],
        classe: (m[2].match(/class="([^"]*)"/) || [])[1] ?? '',
        desabilitado: /\bdisabled=/.test(m[2]),
    }));
}

function classesDe(elemento) {
    return new Set(elemento.classe.split(/\s+/).filter((c) => c !== ''));
}

function texto(html) {
    return html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
}

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
// ⚠️ O detalhe do motivo traz o mínimo **17%**, e NÃO os 12% do protótipo: o `12%`
// de `:1399` é `this.MIN`, e com a fixture em 12 um gate que cravasse o número
// passaria. ⚠️ E os autorizadores vêm com `descricao` real (o número de ofertas),
// nunca com cargo — *"não existe dado de cargo na conta"* neste sistema.

const DETALHE_DA_MARGEM = 'Margem de 5,0% abaixo do mínimo de 17,0%';
const DETALHE_DO_PREJUIZO = 'Resultado negativo: R$ -3,20 por venda';

const AUTORIZADORES = [
    { id: 41, nome: 'Marina Lopes', descricao: '12 ofertas nesta conta' },
    { id: 58, nome: 'Rafael Dias', descricao: '1 oferta nesta conta' },
];

/**
 * ⚠️⚠️ OS SETE BLOQUEIOS QUE A EXCEÇÃO **NÃO** LIBERA — a ISCA POR BLOQUEIO.
 *
 * Lista escrita a partir do docblock de `PubAprovacaoExcecao::LIBERA`, que os
 * nomeia um por um. ⚠️ Há asserção abaixo provando que nenhum deles está em
 * `LIBERA` **e** que os dois que estão, abrem — senão o caso passaria com o modal
 * nunca abrindo.
 */
const NAO_LIBERA = [
    'fotos_a_conferir',
    'sem_fotos',
    'alerta_em_foto_de_ia',
    'frete_ausente',
    'alteracao_nao_conferida',
    'ajuste_em_aberto',
    'titulo_longo',
];

const MOD_EXCECAO = await montar(REL_EXCECAO);
const MOD_CASCA = await montar(REL_CASCA);
const MOD_PAINEL = await montar(REL_PAINEL);

// ═══════════════════════════════════════════════════════════════════════════
// 0. ANTI-FANTASMA
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ ANTI-FANTASMA: todo caminho citado pelo gate da exceção EXISTE no disco 188_11', () => {
    const fantasmas = CAMINHOS_CITADOS.filter((rel) => !fs.existsSync(path.resolve(RAIZ, rel)));
    assert.deepEqual(fantasmas, [], 'caminho fantasma citado por este gate — sonda de ausência sobre arquivo inexistente passa CALADA');
    assert.equal(fs.existsSync(path.resolve(RAIZ, 'app/Services/Publicador/Aprovacao/Fila/ResumoRapidoService.php')), false, 'o caminho fantasma MEDIDO pela wave 188-06 virou arquivo de verdade — o real é `app/Services/Publicador/Fila/ResumoRapidoService.php`');
});

// ═══════════════════════════════════════════════════════════════════════════
// 1. A EXCEÇÃO LIBERA SÓ DOIS — ISCA POR BLOQUEIO NOS SETE OUTROS
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ CRITÉRIO 11 / BLOQ-08: a exceção abre SÓ para os dois financeiros 188_11', () => {
    const { LIBERA, tipoLiberado } = MOD_EXCECAO;

    // (a) A lista é a FECHADA do modelo em produção, lida do PHP.
    const php = lerSemComentarios(REL_MODELO_EXCECAO);
    assert.match(php, /RESULTADO_NEGATIVO\s*=\s*'resultado_negativo'/, 'a chave de prejuízo mudou no modelo');
    assert.match(php, /MARGEM_ABAIXO_DO_MINIMO\s*=\s*'margem_abaixo_do_minimo'/, 'a chave de margem mudou no modelo');
    assert.match(php, /LIBERA\s*=\s*\[self::RESULTADO_NEGATIVO,\s*self::MARGEM_ABAIXO_DO_MINIMO\]/, '`PubAprovacaoExcecao::LIBERA` mudou de forma — remeça a lista da tela JUNTO');
    assert.deepEqual([...LIBERA].sort(), ['margem_abaixo_do_minimo', 'resultado_negativo'], 'a lista `LIBERA` da tela divergiu do modelo');

    // (b) ⚠️⚠️ ISCA POR BLOQUEIO: os SETE outros NÃO abrem, nem com casca vazia.
    const props = (tipo) => ({
        aberto: true, tipo, sub: 'Poltrona', detalhe: DETALHE_DA_MARGEM,
        autorizadores: AUTORIZADORES, responsavelId: 41, justificativa: 'preco de entrada por 15 dias',
        onFechar: nada, onSubmeter: nada, onResponsavel: nada, onJustificativa: nada,
    });
    const abriram = NAO_LIBERA.filter((tipo) => render(MOD_EXCECAO.default, props(tipo)) !== '');
    assert.deepEqual(abriram, [], 'a exceção abriu para bloqueio que ela NÃO libera — "permitido a mais" aqui é publicar um anúncio que ninguém conferiu, sem desfazer');

    // ⚠️ E os SETE estão de fato FORA de `LIBERA`: sem isto a lista podia estar
    // errada e o caso acima passaria afirmando nada.
    const intrusos = NAO_LIBERA.filter((tipo) => LIBERA.includes(tipo));
    assert.deepEqual(intrusos, [], 'a lista `NAO_LIBERA` do gate tem um bloqueio que a exceção LIBERA — a isca está errada');
    assert.equal(NAO_LIBERA.length, 7, 'a isca por bloqueio deixou de cobrir os SETE outros');

    // (c) ⚠️ ISCA NA OUTRA DIREÇÃO: os DOIS abrem. Sem isto o caso acima passaria
    // com o modal nunca abrindo para nada.
    for (const tipo of LIBERA) {
        assert.notEqual(render(MOD_EXCECAO.default, props(tipo)), '', `a exceção NÃO abriu para \`${tipo}\` — a sonda está cega`);
    }

    // (d) ⚠️ E o quase-igual também não abre: tipo com sufixo, maiúscula, ou a
    // chave inglesa do protótipo (`loss`/`margin`), que o servidor RECUSA.
    for (const quase of ['resultado_negativo2', 'RESULTADO_NEGATIVO', 'resultado', 'loss', 'margin', 'margem', '']) {
        assert.equal(render(MOD_EXCECAO.default, props(quase)), '', `o quase-igual \`${quase}\` abriu o modal`);
        assert.equal(tipoLiberado(quase), false, `\`tipoLiberado('${quase}')\` devolveu true`);
    }
    for (const adverso of [null, undefined, 42, {}, [], NaN, { toString: null }]) {
        assert.equal(tipoLiberado(adverso), false, 'tipo adverso foi liberado');
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. A JUSTIFICATIVA: `>= 10`, EM 9, 10 E 11, COM `trim()`
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ T-188-A2 / `:1398`: a justificativa é `>= 10` (não `> 10`), e com `trim()` 188_11', () => {
    const { justificativaValida, MINIMO_DA_JUSTIFICATIVA } = MOD_EXCECAO;

    // (a) O mínimo é o do modelo em produção.
    assert.equal(MINIMO_DA_JUSTIFICATIVA, 10, 'o mínimo da justificativa mudou na tela');
    assert.match(lerSemComentarios(REL_MODELO_EXCECAO), /MINIMO_JUSTIFICATIVA\s*=\s*10/, '`PubAprovacaoExcecao::MINIMO_JUSTIFICATIVA` mudou — remeça a constante da tela JUNTO');
    assert.match(lerSemComentarios(REL_SERVICO_AJUSTES), /REGRA_JUSTIFICATIVA_CURTA/, 'a trava REAL (`REGRA_JUSTIFICATIVA_CURTA`) saiu do serviço — a tela só apaga o botão');

    // (b) ⚠️⚠️ A FRONTEIRA, nos TRÊS pontos: 9 recusa, 10 PASSA, 11 passa.
    assert.equal(justificativaValida('123456789'), false, '9 caracteres passaram');
    assert.equal(justificativaValida('1234567890'), true, '⚠️ 10 caracteres foram RECUSADOS — a trava é `>= 10`, e `:1203` recusa com `length < 10`');
    assert.equal(justificativaValida('12345678901'), true, '11 caracteres foram recusados');

    // (c) ⚠️ `trim()` ANTES de contar: 12 espaços + 2 letras **não** é 10.
    assert.equal(justificativaValida('            ab'), false, 'espaços contaram para o mínimo — `trim()` é o que o protótipo usa');
    assert.equal(justificativaValida('   1234567890   '), true, '`trim()` comeu caracteres de verdade');
    assert.equal(justificativaValida('          '), false, '10 espaços passaram');

    for (const adverso of [null, undefined, 1234567890, {}, [], NaN, { toString: null }]) {
        assert.equal(justificativaValida(adverso), false, 'justificativa adversa passou');
    }

    // (d) ⚠️ AS DUAS PROVAS na tela: o botão APAGADO com `disabled=` **e** o clique
    // não disparando. ⚠️ Ancorado no botão DE SUBMETER, por rótulo único, com o
    // "Cancelar" provado HABILITADO (isca (c): `disabled=""` no outro não serve).
    const base = {
        aberto: true, tipo: 'margem_abaixo_do_minimo', sub: 'Poltrona', detalhe: DETALHE_DA_MARGEM,
        autorizadores: AUTORIZADORES, responsavelId: 58,
        onFechar: nada, onResponsavel: nada, onJustificativa: nada,
    };

    for (const curta of ['', '   ', '123456789', '            ab']) {
        const html = render(MOD_EXCECAO.default, { ...base, justificativa: curta, onSubmeter: nada });
        const botoes = botoesDe(html);
        const submeter = botoes.filter((b) => b.rotulo === 'Registrar exceção autorizada');
        assert.equal(submeter.length, 1, `não achei EXATAMENTE um botão de submeter (achei ${submeter.length})`);
        assert.equal(submeter[0].desabilitado, true, `o botão não saiu \`disabled=\` com justificativa de ${curta.trim().length} caracteres`);
        assert.ok(submeter[0].classe.split(/\s+/).includes('bg-pub-seg'), 'o botão não saiu APAGADO');
        const cancelar = botoes.filter((b) => b.rotulo === 'Cancelar');
        assert.equal(cancelar.length, 1, 'o "Cancelar" da casca sumiu');
        assert.equal(cancelar[0].desabilitado, false, 'o "Cancelar" saiu desabilitado — a medição está no irmão errado');

        const chamadas = [];
        const el = React.createElement(MOD_EXCECAO.default, { ...base, justificativa: curta, onSubmeter: (p) => chamadas.push(p) });
        el.type(el.props).props.onSubmeter();
        assert.deepEqual(chamadas, [], `o clique com justificativa de ${curta.trim().length} caracteres chamou \`onSubmeter\``);
    }

    // ⚠️ ISCA: com 10 o botão HABILITA e o clique dispara com o pedido certo.
    const htmlOk = render(MOD_EXCECAO.default, { ...base, justificativa: '1234567890', onSubmeter: nada });
    const ativo = botoesDe(htmlOk).find((b) => b.rotulo === 'Registrar exceção autorizada');
    assert.equal(ativo.desabilitado, false, 'com 10 caracteres o botão continuou desabilitado — a sonda está cega');
    assert.ok(ativo.classe.split(/\s+/).includes('bg-pub-accent'), 'com 10 caracteres o botão não saiu ATIVO');

    const chamadas = [];
    const el = React.createElement(MOD_EXCECAO.default, { ...base, justificativa: '1234567890', onSubmeter: (p) => chamadas.push(p) });
    el.type(el.props).props.onSubmeter();
    assert.deepEqual(
        chamadas,
        [{ tipo: 'margem_abaixo_do_minimo', justificativa: '1234567890', responsavel_id: 58 }],
        'o clique válido não mandou tipo/justificativa/responsavel_id',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. A LISTA DE AUTORIZADORES VEM DO SERVIDOR
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ T-188-A5: os autorizadores vêm do SERVIDOR; lista vazia DIZ e não envia 188_11', () => {
    const base = {
        aberto: true, tipo: 'resultado_negativo', sub: 'Poltrona', detalhe: DETALHE_DO_PREJUIZO,
        justificativa: 'margem negativa aceita por 15 dias',
        onFechar: nada, onResponsavel: nada, onJustificativa: nada,
    };

    // (a) ⚠️ Os nomes de `:1399` são DADO DE EXEMPLO e NÃO entram como literal.
    for (const inventado of ['Ricardo Gomes', 'Carla Mendes', 'gestor da conta', 'coordenação', "'RG'", "'CM'"]) {
        assert.equal(FONTE_EXCECAO.includes(inventado), false, `o dado de exemplo do protótipo ("${inventado}") foi CRAVADO na tela — é a mesma classe das taxas fictícias (D8)`);
    }

    // (b) A lista do servidor aparece, com `nome · descricao`.
    const html = render(MOD_EXCECAO.default, { ...base, autorizadores: AUTORIZADORES, responsavelId: 41, onSubmeter: nada });
    const opcoes = [...html.matchAll(/<option\b([^>]*)>([\s\S]*?)<\/option>/g)].map((m) => ({
        valor: (m[1].match(/value="([^"]*)"/) || [])[1] ?? '',
        rotulo: texto(m[2]),
    }));
    assert.deepEqual(
        opcoes,
        [{ valor: '41', rotulo: 'Marina Lopes · 12 ofertas nesta conta' }, { valor: '58', rotulo: 'Rafael Dias · 1 oferta nesta conta' }],
        'a lista de autorizadores não é a do servidor, na ordem, com `nome · descricao`',
    );
    assert.ok(texto(html).includes('Responsável pela autorização'), 'o rótulo de `:798` sumiu');

    // (c) ⚠️ LISTA VAZIA → a tela DIZ e o botão NÃO envia (D4: `<select>` vazio é
    // um botão sem destino disfarçado).
    for (const vazia of [[], null, undefined, 'lista', 42, {}, [{}], [{ id: 0, nome: 'Zero' }], [{ id: 7 }], [{ nome: 'Sem id' }]]) {
        const semLista = render(MOD_EXCECAO.default, { ...base, autorizadores: vazia, responsavelId: null, onSubmeter: nada });
        assert.notEqual(semLista, '', 'sem autorizador o modal INTEIRO desapareceu — o motivo e a justificativa continuam tendo de aparecer');
        assert.equal(/<select/.test(semLista), false, 'sem autorizador válido a tela desenhou um `<select>` vazio');
        assert.ok(texto(semLista).includes('Nenhuma pessoa desta conta pode autorizar'), 'sem autorizador a tela não DIZ isso');
        const submeter = botoesDe(semLista).find((b) => b.rotulo === 'Registrar exceção autorizada');
        assert.equal(submeter.desabilitado, true, 'sem autorizador o botão de submeter ficou habilitado');

        const chamadas = [];
        const el = React.createElement(MOD_EXCECAO.default, { ...base, autorizadores: vazia, responsavelId: null, onSubmeter: (p) => chamadas.push(p) });
        el.type(el.props).props.onSubmeter();
        assert.deepEqual(chamadas, [], 'sem autorizador o clique enviou');
    }

    // ⚠️ `id = 0` passava pela guarda de "inteiro": a guarda é POSITIVO. Id zero é
    // plausível e sairia numa rota que não existe.
    assert.deepEqual(MOD_EXCECAO.autorizadoresDoPainel([{ id: 0, nome: 'Zero' }, { id: -3, nome: 'Negativo' }, { id: 9, nome: 'Vale' }]), [{ id: 9, nome: 'Vale', descricao: null }], 'a guarda de id aceitou 0 ou negativo');

    // (d) Id pedido fora da lista cai no PRIMEIRO, nunca `undefined`.
    for (const pedido of [999, null, undefined, 'quarenta', NaN, {}, [], 0, -1]) {
        assert.equal(MOD_EXCECAO.responsavelEscolhido(AUTORIZADORES, pedido).id, 41, 'id adverso não caiu no primeiro autorizador');
    }
    assert.equal(MOD_EXCECAO.responsavelEscolhido(AUTORIZADORES, 58).id, 58, 'id VÁLIDO deixou de ser respeitado — a sonda acima passaria com a função sempre devolvendo o primeiro');
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. O MOTIVO: RÓTULO DO VOCABULÁRIO, DETALHE DO SERVIDOR, E NENHUM `12%`
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ `:1399`: o rótulo vem do vocabulário, o detalhe do SERVIDOR, e o mínimo é o REAL 188_11', () => {
    const base = {
        aberto: true, sub: 'Poltrona', autorizadores: AUTORIZADORES, responsavelId: 41,
        justificativa: 'preco de entrada por 15 dias', onFechar: nada, onSubmeter: nada,
        onResponsavel: nada, onJustificativa: nada,
    };

    const margem = render(MOD_EXCECAO.default, { ...base, tipo: 'margem_abaixo_do_minimo', detalhe: DETALHE_DA_MARGEM });
    const prejuizo = render(MOD_EXCECAO.default, { ...base, tipo: 'resultado_negativo', detalhe: DETALHE_DO_PREJUIZO });

    // (a) Os DOIS rótulos, LIDOS de `vocabularioDoPortao.jsx` (espelho de
    // `PubAprovacaoExcecao::ROTULOS`) — uma casa só para a mesma frase.
    assert.ok(texto(margem).includes('Margem abaixo do mínimo'), 'o rótulo de margem sumiu');
    assert.ok(texto(prejuizo).includes('Resultado negativo'), 'o rótulo de prejuízo sumiu');
    const vocab = lerSemComentarios(REL_VOCABULARIO);
    assert.match(vocab, /resultado_negativo: 'Resultado negativo'/, 'o rótulo mudou no vocabulário — remeça o espelho JUNTO');
    assert.match(vocab, /margem_abaixo_do_minimo: 'Margem abaixo do mínimo'/, 'o rótulo mudou no vocabulário');
    // ⚠️ E a tela NÃO crava os dois rótulos: ela os LÊ.
    assert.equal(FONTE_EXCECAO.includes("'Resultado negativo'"), false, 'o rótulo de prejuízo foi CRAVADO — seria a segunda redação da mesma frase');
    assert.equal(FONTE_EXCECAO.includes("'Margem abaixo do mínimo'"), false, 'o rótulo de margem foi CRAVADO');
    assert.match(FONTE_EXCECAO, /import \{ ROTULO_DO_BLOQUEIO \} from '\.\/vocabularioDoPortao'/, 'a tela parou de LER o rótulo do vocabulário');

    // (b) ⚠️ O DETALHE é o texto do SERVIDOR, com o mínimo REAL (a fixture usa
    // **17%**, não os 12% do protótipo: `:1399` monta `12%` a partir de `MIN`).
    assert.ok(texto(margem).includes(DETALHE_DA_MARGEM), 'o detalhe do servidor não apareceu');
    assert.ok(texto(margem).includes('17,0%'), 'o mínimo REAL (17,0%) não apareceu — se a tela cravasse `12%`, este caso reprovaria');
    assert.equal(texto(margem).includes('12%'), false, 'apareceu `12%` na tela — o `12%` de `:1399` é `MIN`, não literal');
    assert.ok(texto(prejuizo).includes(DETALHE_DO_PREJUIZO), 'o detalhe do prejuízo não apareceu');

    // (c) ⚠️ E a tela NÃO formata dinheiro nem percentual: zero aritmética, zero
    // `R$` montado, zero `%` montado, zero `toFixed`/`toLocaleString`.
    const FORMATACAO = [/toFixed\(/, /toLocaleString\(/, /Intl\.NumberFormat/, /'R\$ '/, /`R\$ /, /\+\s*'%'/, /\* 100/, /\/ 100/];
    const infratores = FORMATACAO.filter((re) => re.test(FONTE_EXCECAO));
    assert.deepEqual(infratores, [], 'a tela passou a formatar dinheiro/percentual — o texto é o que o SERVIDOR escreveu');
    // ⚠️ ISCA: as oito regex casam o que DEVEM casar.
    const ISCAS = ['x.toFixed(2)', 'x.toLocaleString()', 'new Intl.NumberFormat()', "'R$ ' + v", '`R$ ${v}`', "p + '%'", 'v * 100', 'v / 100'];
    const cegas = FORMATACAO.filter((re, i) => !re.test(ISCAS[i]));
    assert.deepEqual(cegas, [], 'regex de formatação que não casa o próprio caso — gate cego');
    // ⚠️ E a prova de que o SERVIDOR é quem escreve a frase.
    assert.match(lerSemComentarios(REL_SERVICO_BLOQUEIOS), /'Margem de '\.self::pct\(/, 'o servidor parou de montar a frase da margem — é dele que a tela lê');

    // (d) A caixa é VERMELHA, por igualdade de CONJUNTO, no elemento de índice
    // ABSOLUTO 1 da árvore do corpo (o 0 é a raiz com `font-pub`).
    const els = tagsDeAbertura(render(MOD_EXCECAO.CorpoDaExcecao, {
        tipo: 'margem_abaixo_do_minimo', detalhe: DETALHE_DA_MARGEM, autorizadores: AUTORIZADORES,
        responsavelId: 41, justificativa: 'x', onResponsavel: nada, onJustificativa: nada,
    }));
    assert.deepEqual(
        els.map((e) => e.tag),
        ['div', 'div', 'span', 'span', 'label', 'select', 'option', 'option', 'label', 'textarea', 'span'],
        'a árvore do corpo da exceção mudou de forma — remeça os índices ABSOLUTOS antes de confiar em qualquer medida',
    );
    assert.deepEqual(
        [...classesDe(els[1])].sort(),
        ['bg-pub-danger/[0.08]', 'border', 'border-pub-danger/[0.35]', 'flex', 'flex-col', 'gap-[3px]', 'p-[12px]', 'rounded-pub-control'].sort(),
        'a caixa VERMELHA de `:797` divergiu',
    );
    assert.deepEqual([...classesDe(els[2])].sort(), ['font-semibold', 'text-[13px]', 'text-pub-danger-txt'].sort(), 'o rótulo do motivo de `:797` divergiu');
    assert.deepEqual([...classesDe(els[3])].sort(), ['text-[12.5px]', 'text-pub-fase1'].sort(), 'o detalhe do motivo de `:797` divergiu');
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. "AGUARDANDO AUTORIZAÇÃO" NÃO EXISTE, E A PROSA DE `:804` NÃO VAI À TELA
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ DADO-11 / decisão 6: a exceção só REGISTRA — não há estado de espera 188_11', () => {
    // ⚠️⚠️ A SONDA ANCORA NO SUJEITO. *"Aguardando aprovação"* é vocabulário
    // CORRETO do domínio (`aguardando_aprovacao` é um dos 8 andamentos!) e casa
    // dezenas de ocorrências legítimas na área: uma sonda por "aguardando"
    // reprovaria trabalho certo. ➡️ O que não pode existir é **a EXCEÇÃO
    // esperando**.
    const PROIBIDAS = [
        /exce(ç|c)[ãa]o\s+aguardando/i,
        /aguardando\s+autoriza(ç|c)[ãa]o/i,
        /pendente\s+de\s+aprova(ç|c)[ãa]o\s+do\s+gestor/i,
        /exce(ç|c)[ãa]o\s+solicitada/i,
        /aguardando\s+libera(ç|c)[ãa]o/i,
        /enviada\s+para\s+aprova(ç|c)[ãa]o/i,
    ];

    const infratores = [];
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        for (const re of PROIBIDAS) {
            if (re.test(fonte)) infratores.push(`${rel} ~ ${re}`);
        }
    }
    assert.deepEqual(infratores, [], 'apareceu texto de "exceção esperando" — esse estado NÃO EXISTE (decisão 6 do usuário)');

    // ⚠️ ISCA nas duas direções, e com os ACENTOS: `aprovad` **não** casa
    // `aprovação`, e silêncio que depende de UMA LETRA é sorte.
    const DEVEM_CASAR = [
        'a exceção aguardando o gestor',
        'Aguardando autorização do responsável',
        'pendente de aprovação do gestor',
        'exceção solicitada, aguarde',
        'aguardando liberação',
        'enviada para aprovação',
    ];
    const cegas = PROIBIDAS.filter((re, i) => !re.test(DEVEM_CASAR[i]));
    assert.deepEqual(cegas, [], 'regex que não casa o próprio caso — gate cego');
    // ⚠️ E ela NÃO pode pegar o vocabulário LEGÍTIMO do domínio.
    for (const legitimo of ['Aguardando aprovação', 'aguardando_aprovacao', 'A oferta está aguardando aprovação', 'Aprovado — aguardando publicação']) {
        const pegou = PROIBIDAS.filter((re) => re.test(legitimo));
        assert.deepEqual(pegou, [], `a sonda acusou o vocabulário LEGÍTIMO "${legitimo}" — gate mais largo que o invariante`);
    }

    // ⚠️ E nada disso na TELA renderizada (gate sobre dado PREENCHIDO, não vazio).
    const html = render(MOD_EXCECAO.default, {
        aberto: true, tipo: 'resultado_negativo', sub: 'Poltrona', detalhe: DETALHE_DO_PREJUIZO,
        autorizadores: AUTORIZADORES, responsavelId: 41, justificativa: 'preco de entrada por 15 dias',
        onFechar: nada, onSubmeter: nada, onResponsavel: nada, onJustificativa: nada,
    });
    const visivel = texto(html);
    assert.ok(visivel.length > 80, 'o HTML saiu curto demais — o gate de ausência rodaria sobre ZERO casos');
    for (const re of PROIBIDAS) {
        assert.equal(re.test(visivel), false, 'texto de espera apareceu na tela');
    }

    // ⚠️⚠️ A PROSA DO PROTÓTIPO DE `:804` **NÃO** VAI PARA A TELA (D7: jargão não
    // vai para a tela; e é fala SOBRE o protótipo).
    assert.equal(/No protótipo/i.test(visivel), false, 'a prosa do protótipo de `:804` foi para a tela');
    assert.equal(/No protótipo/i.test(FONTE_EXCECAO), false, 'a prosa do protótipo de `:804` está na fonte (lida SEM comentário)');
    // ⚠️ E a NOSSA nota está — as duas pontas, senão o caso passaria com a tela
    // sem nota nenhuma.
    assert.equal(MOD_EXCECAO.NOTA, 'A exceção fica registrada no histórico desta oferta, com quem autorizou, a data e a justificativa.', 'a nota do produto mudou — remeça a expectativa CONGELADA aqui e declare');
    assert.ok(visivel.includes(MOD_EXCECAO.NOTA), 'a nota do produto não apareceu na tela');
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. OS LITERAIS, CONGELADOS NO GATE
// ═══════════════════════════════════════════════════════════════════════════

const LITERAIS_CONGELADOS = {
    TITULO: 'Solicitar exceção autorizada',
    ROTULO_DO_BOTAO: 'Registrar exceção autorizada',
    ROTULO_DO_RESPONSAVEL: 'Responsável pela autorização',
    ROTULO_DA_JUSTIFICATIVA: 'Justificativa (mín. 10 caracteres)',
    PLACEHOLDER_DA_JUSTIFICATIVA: 'Ex.: oferta de entrada para ganhar relevância por 15 dias',
    SEPARADOR_DO_AUTORIZADOR: ' · ',
    SEM_AUTORIZADOR: 'Nenhuma pessoa desta conta pode autorizar a exceção. Peça acesso ao time do Publicador.',
};

test('⚠️⚠️ OS LITERAIS DO MOD-02, CONGELADOS byte a byte 188_11', () => {
    // ⚠️⚠️ A EXPECTATIVA MORA AQUI, e **não** é lida do módulo testado: foi a
    // mutação SOBREVIVENTE da wave 188-10 (ler o literal do próprio módulo muda os
    // DOIS lados juntos — tautologia). ⚠️ E ela **não depende do protótipo**, que
    // **não é versionado**.
    for (const [nome, esperado] of Object.entries(LITERAIS_CONGELADOS)) {
        assert.equal(MOD_EXCECAO[nome], esperado, `o literal \`${nome}\` mudou — se foi de propósito, remeça a expectativa CONGELADA aqui e declare`);
    }

    // ⚠️ ISCA DO QUASE-IGUAL, que já mordeu cinco vezes neste projeto.
    assert.notEqual(MOD_EXCECAO.ROTULO_DA_JUSTIFICATIVA, 'Justificativa (min. 10 caracteres)', 'a sonda aceita o quase-igual SEM o acento de `mín.`');
    assert.notEqual(MOD_EXCECAO.ROTULO_DA_JUSTIFICATIVA, 'Justificativa (mín. 10 caractere)', 'a sonda aceita o quase-igual no singular');
    assert.notEqual(MOD_EXCECAO.SEPARADOR_DO_AUTORIZADOR, ' - ', 'o separador deixou de ser o `·` (U+00B7)');
    assert.notEqual(MOD_EXCECAO.ROTULO_DO_BOTAO, 'Registrar exceção', 'a sonda aceita o prefixo — `includes` casa prefixo E sufixo');

    // ⚠️ O rótulo da justificativa é MONTADO com o mínimo, não cravado: trocar o
    // mínimo tem de trocar o rótulo junto.
    assert.ok(MOD_EXCECAO.ROTULO_DA_JUSTIFICATIVA.includes(`${MOD_EXCECAO.MINIMO_DA_JUSTIFICATIVA}`), 'o rótulo da justificativa não cita o mínimo — dois literais divergiriam na primeira edição');
    assert.match(FONTE_EXCECAO, /Justificativa \(mín\. \$\{MINIMO_DA_JUSTIFICATIVA\} caracteres\)/, 'o rótulo passou a cravar o número em vez de LER a constante');

    const html = render(MOD_EXCECAO.default, {
        aberto: true, tipo: 'margem_abaixo_do_minimo', sub: 'Poltrona Reclinável', detalhe: DETALHE_DA_MARGEM,
        autorizadores: AUTORIZADORES, responsavelId: 41, justificativa: 'preco de entrada por 15 dias',
        onFechar: nada, onSubmeter: nada, onResponsavel: nada, onJustificativa: nada,
    });
    const visivel = texto(html);
    for (const literal of [LITERAIS_CONGELADOS.TITULO, LITERAIS_CONGELADOS.ROTULO_DO_BOTAO, LITERAIS_CONGELADOS.ROTULO_DO_RESPONSAVEL, LITERAIS_CONGELADOS.ROTULO_DA_JUSTIFICATIVA]) {
        assert.ok(visivel.includes(literal), `o literal "${literal}" não está na tela`);
    }
    assert.ok(visivel.includes('Poltrona Reclinável'), 'o `sub` (o título da oferta) não chegou ao topo do modal');
    assert.match(html, /placeholder="Ex\.: oferta de entrada para ganhar relevância por 15 dias"/, 'o placeholder de `:802` sumiu');
    assert.match(html, /rows="3"/, 'o `rows="3"` de `:802` sumiu');
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. A CASCA É IMPORTADA, `font-pub` NO CORPO, E O MODAL NÃO ESCREVE
// ═══════════════════════════════════════════════════════════════════════════

const RE_LARGURA_DA_CASCA = /(^|[\s'"`])w-\[560px\]/;

test('⚠️⚠️ T-188-A0 / critério 15: casca IMPORTADA, `font-pub` no corpo, zero escrita 188_11', () => {
    // (a) IMPORTA a casca e não reimplementa peça dela.
    assert.match(FONTE_EXCECAO, /import\s+CascaDeModal\s+from\s+'\.\/CascaDeModal'/, 'o modal de exceção parou de IMPORTAR a casca da Fase 187');
    assert.match(FONTE_EXCECAO, /<CascaDeModal\b/, 'o modal de exceção não MONTA a casca');
    const MARCAS = ['pt-[96px]', 'max-h-[calc(100vh-140px)]', 'shadow-pub-modal', 'fixed inset-0', 'CLASSES_BACKDROP', 'CLASSES_RODAPE', 'CLASSES_TOPO', 'z-[42]'];
    assert.deepEqual(MARCAS.filter((m) => FONTE_EXCECAO.includes(m)), [], 'o modal de exceção reimplementou peça da casca');
    assert.equal(RE_LARGURA_DA_CASCA.test(FONTE_EXCECAO), false, 'o modal de exceção declarou a largura de 560px — seria a SEGUNDA casca');

    // ⚠️ A âncora continua distinguindo o `max-w-[560px]` legítimo do toast.
    assert.ok(lerSemComentarios(REL_TOAST).includes('max-w-[560px]'), 'o `max-w-[560px]` do toast sumiu — a armadilha mudou de lugar');
    assert.equal(RE_LARGURA_DA_CASCA.test('max-w-[560px]'), false, 'a âncora aceita sufixo');
    assert.equal(RE_LARGURA_DA_CASCA.test('flex w-[560px] flex-col'), true, 'a âncora rejeita a forma que ela DEVE pegar');

    // (b) `numstat` ZERO na casca e nos vizinhos em produção.
    for (const rel of [REL_CASCA, REL_TOAST, REL_GATE_ZINDEX, REL_VOCABULARIO, `${DIR_APROV}/andamentoDoPortao.jsx`, `${DIR_APROV}/SeloDeAndamentoInterno.jsx`, `${DIR_APROV}/BarraDeMargem.jsx`]) {
        assert.equal(numstatDe(rel).trim(), '', `${rel} foi alterado — ele é INTOCÁVEL/em produção`);
    }
    assert.notEqual(numstatDe(REL_CASCA, ARVORE_VAZIA).trim(), '', 'a sonda de `numstat` está cega: nem contra a árvore vazia ela produz linha');

    // (c) `font-pub` na RAIZ do corpo, com a RAZÃO no docblock.
    assert.match(FONTE_EXCECAO, /CLASSES_CORPO_DO_MODAL\s*=\s*'[^']*font-pub/, 'o corpo do modal não declara `font-pub`');
    const raiz = tagsDeAbertura(render(MOD_EXCECAO.CorpoDaExcecao, {
        tipo: 'resultado_negativo', detalhe: DETALHE_DO_PREJUIZO, autorizadores: AUTORIZADORES,
        responsavelId: 41, justificativa: 'x', onResponsavel: nada, onJustificativa: nada,
    }))[0];
    assert.ok(classesDe(raiz).has('font-pub'), 'a RAIZ do corpo não tem `font-pub` — é no limite do portal que a herança quebra');
    assert.match(CRUA_EXCECAO, /conteúdo portado não herda fonte/i, 'o docblock parou de dizer POR QUE `font-pub` é declarado aqui');
    assert.equal(lerSemComentarios(REL_CASCA).includes('font-pub'), false, '`font-pub` entrou na casca da Fase 187');

    // (d) O modal NÃO escreve.
    const ESCRITAS = [/router\s*\.\s*post\(/, /router\s*\.\s*put\(/, /router\s*\.\s*delete\(/, /axios\s*\.\s*(post|put|delete)\(/, /fetch\(/, /escreverNoPainel/];
    assert.deepEqual(ESCRITAS.filter((re) => re.test(FONTE_EXCECAO)), [], 'o modal escreve por conta própria — a escrita é do CHAMADOR, que é quem tem a versão');
    assert.equal(ESCRITAS[0].test('router . post(x)'), true, 'a sonda não tolera espaço: `router . post(` escaparia dela');
    assert.ok(ESCRITAS.some((re) => re.test(lerSemComentarios(REL_PAINEL))), 'a sonda não acha a escrita nem no arquivo que TEM o helper');
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. O SLOT `modais` É IRMÃO DO `<aside>`, NÃO FILHO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ ESTRUTURA: o slot `modais` é IRMÃO do `<aside>`, medido por ÁRVORE 188_11', () => {
    const marcaDoModal = 'data-isca-modal-188-11';
    const html = renderToStaticMarkup(React.createElement(MOD_PAINEL.RaizDoPainel, {
        identificacao: null,
        falha: null,
        navegacao: null,
        pendencias: React.createElement('div', { key: 'p' }, 'as pendencias'),
        rodape: React.createElement('div', { key: 'r' }, 'o rodape'),
        modais: React.createElement('div', { key: 'm', [marcaDoModal]: 'sim' }, 'o modal'),
        onFechar: nada,
    }));

    // ⚠️ A prova é por ÁRVORE, com contagem de profundidade — não por "aparece no
    // HTML". No `children` ou no slot `pendencias` o modal apareceria do mesmo
    // jeito, e **ROLARIA PARA FORA DA TELA**: é a mesma classe de erro que fez o
    // rodapé ganhar slot próprio na 188-10, com todo teste de render passando.
    const indiceDoAside = html.indexOf('<aside');
    const fimDoAside = html.indexOf('</aside>');
    const indiceDoModal = html.indexOf(marcaDoModal);
    assert.ok(indiceDoAside >= 0, 'o `<aside>` do painel sumiu');
    assert.ok(indiceDoModal >= 0, 'o slot `modais` não chegou à árvore');
    assert.ok(indiceDoModal > fimDoAside, 'o slot `modais` ficou DENTRO do `<aside>` — lá ele ficaria limitado aos 1140px do painel em vez de cobrir a tela');

    // ⚠️ ISCA: o RODAPÉ continua DENTRO do `<aside>` (ele é irmão do CORPO, não do
    // `<aside>`), e as PENDÊNCIAS continuam dentro do corpo rolável. Sem esta
    // ponta a asserção acima passaria com tudo jogado para fora.
    const indiceDoRodape = html.indexOf('o rodape');
    const indiceDasPendencias = html.indexOf('as pendencias');
    assert.ok(indiceDoRodape > indiceDoAside && indiceDoRodape < fimDoAside, 'o rodapé saiu do `<aside>` — ele é irmão do CORPO, não do `<aside>`');
    assert.ok(indiceDasPendencias > indiceDoAside && indiceDasPendencias < indiceDoRodape, 'as pendências saíram do corpo rolável');

    // ⚠️ E sem nó o slot não desenha NADA (nem casca vazia).
    const semModal = renderToStaticMarkup(React.createElement(MOD_PAINEL.RaizDoPainel, {
        identificacao: null, falha: null, navegacao: null, pendencias: null, rodape: null, modais: null, onFechar: nada,
    }));
    assert.equal(semModal.includes(marcaDoModal), false, 'o slot vazio desenhou algo');

    // ⚠️ E o slot viaja por EXPRESSÃO na página, sem `isValidElement(` novo — é por
    // isso que `SLOTS_DE_NO_DEPOIS_DA_187` **não** muda nesta wave.
    assert.match(FONTE_PAGINA, /modais=\{noModaisDoPainel\}/, 'a página não ligou o slot `modais`');

    // ⚠️⚠️ E o conjunto de slots de NÓ da PÁGINA continua sendo o DECLARADO — a
    // comparação é contra a UNIÃO das DUAS listas do gate da 187, lida do literal,
    // e **nunca** contra uma lista cravada aqui (cravar seria a terceira
    // escrituração, e a primeira redação desta sonda era mais ESTREITA que o
    // invariante: ela esquecia `proximo`/`rodape`/`tabela` e reprovava trabalho
    // que está NO AR).
    const slotsDaPagina = [...new Set(
        [...FONTE_PAGINA.matchAll(/isValidElement\(\s*([A-Za-z_$][\w$]*)\s*\)/g)].map((m) => m[1]),
    )].sort();
    const fonteDoCabecalho = lerSemComentarios(REL_GATE_CABECALHO);
    const blocosDeSlot = [...fonteDoCabecalho.matchAll(/const\s+SLOTS_DE_NO_(?:DA_187|DEPOIS_DA_187)\s*=\s*\[([^\]]*)\]/g)];
    assert.equal(blocosDeSlot.length, 2, 'não achei as DUAS listas de slots de NÓ no gate da 187 — a leitura está cega');
    const slotsDeclarados = [...new Set(
        blocosDeSlot.flatMap((bloco) => [...bloco[1].matchAll(/'([^']+)'/g)].map((m) => m[1])),
    )].sort();
    assert.ok(slotsDeclarados.length > 0, 'as listas de slots de NÓ saíram VAZIAS — a leitura está cega');
    assert.deepEqual(
        slotsDaPagina,
        slotsDeclarados,
        'a página abriu `isValidElement(` novo — então `SLOTS_DE_NO_DEPOIS_DA_187` (TRÊS pontas:'
        + ' `publicador-fila-cabecalho-187`, `publicador-fila-proximo-187` e `FilaDeAprovacaoRotaTest.php`)'
        + ' tem de ser remedido. Esta wave NÃO abre slot novo na página: o `modais` nasce em'
        + ' `PainelDeRevisao.jsx` e viaja por EXPRESSÃO.',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 9. `Escape` FECHA O MODAL ANTES DO PAINEL
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ `:1014`: com modal aberto o `Escape` fecha o MODAL, não o painel 188_11', () => {
    const { resolverAtalhoDoPainel, ACOES } = MOD_PAINEL;

    const comModal = resolverAtalhoDoPainel({ tecla: 'Escape', temModal: true, alvoEhCampo: false, anteriorId: 2, proximaId: 3, imagemAtual: 0, totalDeImagens: 2 });
    assert.equal(comModal.acao, ACOES.FECHAR_MODAL, 'com modal aberto o `Escape` não fecha o MODAL');

    // ⚠️ ISCA: SEM modal ele fecha o painel. Sem esta ponta o caso acima passaria
    // com a função sempre devolvendo `FECHAR_MODAL`.
    const semModal = resolverAtalhoDoPainel({ tecla: 'Escape', temModal: false, alvoEhCampo: false, anteriorId: 2, proximaId: 3, imagemAtual: 0, totalDeImagens: 2 });
    assert.equal(semModal.acao, ACOES.FECHAR_PAINEL, 'sem modal o `Escape` deixou de fechar o painel');

    // ⚠️ E a COMPOSIÇÃO: a página passa `temModal` e `onFecharModal` de verdade.
    assert.match(FONTE_PAGINA, /temModal=\{modalAberto !== null\}/, 'a página não informa ao painel que há modal aberto — o `Escape` fecharia o painel por baixo do modal');
    assert.match(FONTE_PAGINA, /onFecharModal=\{fecharModalDoPainel\}/, 'a página não ligou o `onFecharModal`');
});

// ═══════════════════════════════════════════════════════════════════════════
// 10. A TELA PRETA — RENDER ADVERSO E NO CLIQUE
// ═══════════════════════════════════════════════════════════════════════════

const FORMAS_ADVERSAS = [
    null, undefined, 0, 1, -1, NaN, Infinity, '', '   ', 'texto', true, false,
    {}, [], [{}], { toString: null }, { valueOf: null }, () => {},
];

const SEM_OBJETO_ESTRANHO = /\[object Object\]|NaN|undefined|Invalid Date/;

test('⚠️⚠️ REND / critério 19: nenhum campo adverso derruba o MOD-02, nem no CLIQUE 188_11', () => {
    const base = {
        aberto: true, tipo: 'resultado_negativo', sub: 'Poltrona', detalhe: DETALHE_DO_PREJUIZO,
        autorizadores: AUTORIZADORES, responsavelId: 41, justificativa: 'preco de entrada por 15 dias',
        onFechar: nada, onSubmeter: nada, onResponsavel: nada, onJustificativa: nada,
    };

    for (const campo of ['tipo', 'sub', 'detalhe', 'autorizadores', 'responsavelId', 'justificativa']) {
        for (const forma of FORMAS_ADVERSAS) {
            let html = '';
            assert.doesNotThrow(
                () => { html = render(MOD_EXCECAO.default, { ...base, [campo]: forma }); },
                `o campo \`${campo}\` derrubou o render`,
            );
            assert.doesNotMatch(html, SEM_OBJETO_ESTRANHO, `o campo \`${campo}\` vazou para a tela`);
        }
    }

    // Cada autorizador adverso, um a um.
    for (const campo of ['id', 'nome', 'descricao']) {
        for (const forma of FORMAS_ADVERSAS) {
            const lista = [{ ...AUTORIZADORES[0], [campo]: forma }, AUTORIZADORES[1]];
            let html = '';
            assert.doesNotThrow(() => { html = render(MOD_EXCECAO.default, { ...base, autorizadores: lista }); }, `o campo \`autorizador.${campo}\` derrubou o render`);
            assert.doesNotMatch(html, SEM_OBJETO_ESTRANHO, `o campo \`autorizador.${campo}\` vazou para a tela`);
        }
    }

    // A prop inteira.
    for (const forma of FORMAS_ADVERSAS) {
        assert.doesNotThrow(() => render(MOD_EXCECAO.default, {
            aberto: forma, tipo: forma, sub: forma, detalhe: forma, autorizadores: forma,
            responsavelId: forma, justificativa: forma, onFechar: forma, onSubmeter: forma,
            onResponsavel: forma, onJustificativa: forma,
        }), 'a prop inteira adversa derrubou o render');
        assert.doesNotThrow(() => render(MOD_EXCECAO.CorpoDaExcecao, {
            tipo: forma, detalhe: forma, autorizadores: forma, responsavelId: forma,
            justificativa: forma, onResponsavel: forma, onJustificativa: forma,
        }), 'a prop inteira adversa derrubou o corpo');
    }

    // ⚠️⚠️ E TAMBÉM NO CLIQUE: `renderToStaticMarkup` **não dispara `onClick`**.
    for (const forma of FORMAS_ADVERSAS) {
        assert.doesNotThrow(() => {
            const el = React.createElement(MOD_EXCECAO.default, { ...base, justificativa: forma, responsavelId: forma, onSubmeter: nada });
            const casca = el.type(el.props);
            if (casca !== null && typeof casca.props.onSubmeter === 'function') casca.props.onSubmeter();
            if (casca !== null && typeof casca.props.onFechar === 'function') casca.props.onFechar();
        }, 'o CLIQUE com campo adverso derrubou a página');

        // Os dois `onChange`, com evento adverso.
        const el2 = React.createElement(MOD_EXCECAO.CorpoDaExcecao, {
            tipo: 'resultado_negativo', detalhe: DETALHE_DO_PREJUIZO, autorizadores: AUTORIZADORES,
            responsavelId: 41, justificativa: 'x', onResponsavel: nada, onJustificativa: nada,
        });
        const arvore = el2.type(el2.props);
        assert.doesNotThrow(() => {
            for (const filho of arvore.props.children) {
                if (filho === null || !filho.props || !Array.isArray(filho.props.children)) continue;
                for (const neto of filho.props.children) {
                    if (neto !== null && typeof neto === 'object' && neto.props && typeof neto.props.onChange === 'function') {
                        neto.props.onChange({ target: { value: forma } });
                    }
                }
            }
        }, 'o `onChange` com valor adverso derrubou a página');
    }

    // O filho OBJETO COMUM passando pela casca.
    assert.doesNotThrow(() => renderToStaticMarkup(React.createElement(MOD_CASCA.default, {
        aberto: true, titulo: 'x', rotuloDoBotao: 'y', onFechar: nada, onSubmeter: nada,
        children: [{}, React.createElement(MOD_EXCECAO.CorpoDaExcecao, {
            tipo: 'resultado_negativo', detalhe: DETALHE_DO_PREJUIZO, autorizadores: AUTORIZADORES,
            responsavelId: 41, justificativa: 'x', onResponsavel: nada, onJustificativa: nada,
        })],
    })), 'objeto comum no meio dos filhos derrubou a casca');
});

// ═══════════════════════════════════════════════════════════════════════════
// 11. ROLLUP E TERRITÓRIO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ ROLLUP e TERRITÓRIO: o recorte CONSTANTE deste plano, com isca do recorte 188_11', () => {
    // O bundle DE VERDADE renderiza os `<option>`: é a prova de que o Rollup não
    // elidiu variável de escopo lida dentro do `.map()`.
    const html = render(MOD_EXCECAO.CorpoDaExcecao, {
        tipo: 'resultado_negativo', detalhe: DETALHE_DO_PREJUIZO, autorizadores: AUTORIZADORES,
        responsavelId: 41, justificativa: 'x', onResponsavel: nada, onJustificativa: nada,
    });
    assert.equal((html.match(/<option/g) || []).length, 2, 'o bundle não renderizou os dois `<option>`');

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
        [/confirmar publica|segundo passo|cancelar aprova|desfazer/i, 'decisão 1: aprovar JÁ PUBLICA'],
        [/lucro l[íi]quido/i, 'vocabulário travado'],
        [/duration-\[\d+ms\]/, '`duration-[Nms]` NÃO COMPILA'],
        [/new Date\(/, 'COL-06: a data é formatada no SERVIDOR'],
    ];
    const infratores = [];
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        for (const [re, razao] of VETADOS) {
            if (re.test(fonte)) infratores.push(`${rel} ~ ${razao}`);
        }
    }
    assert.deepEqual(infratores, [], 'apareceu forma VETADA no arquivo deste plano');

    const ISCAS = [
        'localStorage.getItem(1)', 'Restaurar dados de exemplo', 'String(x)', 'JSON.stringify(x)',
        '#FACC15', 'text-sm', 'ecf-yellow', 'font-display', 'border-white/[0.08]', 'md:flex',
        'Ampliar', 'Gerar novamente com IA', 'confirmar publicação', 'lucro líquido', 'duration-[180ms]',
        'new Date()',
    ];
    assert.equal(ISCAS.length, VETADOS.length, 'a lista de iscas e a de vetos divergiram');
    assert.deepEqual(VETADOS.filter(([re], i) => !re.test(ISCAS[i])).map(([, r]) => r), [], 'regex que não casa o próprio caso — gate cego');
    assert.deepEqual(FONTES_DO_PLANO.map(([rel]) => rel), [REL_EXCECAO], 'o recorte deste gate deixou de ser a lista CONSTANTE deste plano');
    assert.ok(FONTES_DO_PLANO.every(([rel]) => !rel.startsWith('resources/') || rel.endsWith('.jsx')), 'peça nova sob `resources/` saiu como `.js`');

    for (const rel of ['package.json', 'package-lock.json']) {
        assert.equal(numstatDe(rel).trim(), '', `${rel} foi alterado — este plano NÃO instala nada`);
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 12. AS LISTAS CONSTANTES E O MANIFESTO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ VOC-07: os `.jsx` deste plano nas DUAS listas, `modalDoPainel` declarado, e o manifesto 188_11', () => {
    const DO_PLANO = ['ModalDeAjuste.jsx', 'ModalDeExcecao.jsx'];

    // (a) `ARQUIVOS_NOVOS`.
    const bloco = lerSemComentarios(REL_GATE_VOCABULARIO).match(/const\s+ARQUIVOS_NOVOS\s*=\s*\[([\s\S]*?)\n\];/);
    assert.ok(bloco, 'não achei o literal de `ARQUIVOS_NOVOS` — a leitura está cega');
    const declarados = [...bloco[1].matchAll(/\$\{DIR_APROV\}\/([\w.-]+\.jsx)/g)].map((m) => m[1]);
    assert.ok(declarados.length > 0, '`ARQUIVOS_NOVOS` saiu vazia — a leitura está cega');
    assert.deepEqual(DO_PLANO.filter((n) => !declarados.includes(n)), [], '`.jsx` deste plano ficou FORA de `ARQUIVOS_NOVOS` — o GATE-03 passaria por OMISSÃO');

    // (b) `JSX_DEPOIS_DA_187` — ⚠️ a SEGUNDA escrituração, deliberada.
    const bloco2 = lerSemComentarios(REL_GATE_FECHAMENTO).match(/const\s+JSX_DEPOIS_DA_187\s*=\s*\[([\s\S]*?)\n\];/);
    assert.ok(bloco2, 'não achei o literal de `JSX_DEPOIS_DA_187` — a leitura está cega');
    const depois = [...bloco2[1].matchAll(/\$\{DIR_APROV\}\/([\w.-]+\.jsx)/g)].map((m) => m[1]);
    assert.ok(depois.length > 0, '`JSX_DEPOIS_DA_187` saiu vazia — a leitura está cega');
    assert.deepEqual(DO_PLANO.filter((n) => !depois.includes(n)), [], '`.jsx` deste plano ficou FORA de `JSX_DEPOIS_DA_187`');

    // (c) ⚠️ `ESTADO_LOCAL_DA_PAGINA` — esta wave ACRESCENTA `modalDoPainel`, e é
    // desvio DECLARADO: os modais são puros e controlados, então a PÁGINA é a dona
    // do formulário. Sem a entrada o `deepEqual` daquele gate reprova.
    // ⚠️ Leitura CRUA sem linha de comentário: `lerSemComentarios` come este
    // arquivo a partir do `'date-fns/*'` do `external` (ver o helper no topo).
    const bloco3 = lerSemLinhaDeComentario(REL_GATE_FILTROS).match(/const\s+ESTADO_LOCAL_DA_PAGINA\s*=\s*\[([\s\S]*?)\n\s{4}\];/);
    assert.ok(bloco3, 'não achei o literal de `ESTADO_LOCAL_DA_PAGINA` — a leitura está cega');
    const estados = [...bloco3[1].matchAll(/'([^']+)'/g)].map((m) => m[1]);
    assert.ok(estados.includes('modalDoPainel'), '`modalDoPainel` ficou FORA de `ESTADO_LOCAL_DA_PAGINA` — aquele gate reprova por `deepEqual`');
    assert.match(FONTE_PAGINA, /const \[modalDoPainel, setModalDoPainel\] = useState\(null\)/, 'a página parou de guardar o estado dos modais na forma declarada');
    // ⚠️ E "o modal está aberto" NÃO vai para a URL (D7/COL-03).
    assert.doesNotMatch(FONTE_PAGINA, /querystringDe\([^)]*modal/, '`modal` entrou no montador de querystring — um link compartilhado abriria um modal na cara de outra pessoa');

    // (d) O manifesto: UM id por arquivo de gate, e o id deste arquivo.
    const manifesto = fs.readFileSync(path.resolve(RAIZ, REL_MANIFESTO), 'utf8');
    const linhas = manifesto.split(/\r?\n/).filter((l) => l.trim() !== '' && !l.trim().startsWith('#'));
    assert.ok(linhas.length > 0, 'o manifesto não tem linha de gate — a leitura está cega');
    const esteArquivo = 'tests/js/publicador-painel-modal-excecao-188.test.js';
    const minhas = linhas.filter((l) => l.includes(esteArquivo));
    assert.equal(minhas.length, 1, `o manifesto declara ${minhas.length} ids para ${esteArquivo} — é UM id por arquivo de gate`);
    assert.match(minhas[0], /^modal-de-excecao\s+js\s+/, 'o id deste arquivo deixou de ser `modal-de-excecao`');

    // ⚠️ E cada arquivo de gate tem UM id só, nos dois sentidos.
    const porArquivo = new Map();
    for (const linha of linhas) {
        const partes = linha.trim().split('::')[0].trim().split(/\s+/);
        for (const arquivo of partes.slice(2)) {
            porArquivo.set(arquivo, (porArquivo.get(arquivo) ?? 0) + 1);
        }
    }
    assert.deepEqual([...porArquivo].filter(([, n]) => n > 1), [], 'arquivo de gate com MAIS DE UM id — a mesma contagem sairia duas vezes e nenhum id mediria a sua metade');
    assert.deepEqual([...porArquivo.keys()].filter((rel) => !fs.existsSync(path.resolve(RAIZ, rel))), [], 'o manifesto aponta para arquivo de gate que não existe');

    // (e) ⚠️ BIJEÇÃO id ↔ `<verify>`, nas DUAS direções, com o EXPANSOR DE
    // ALTERNÂNCIA testado (`/test_[A-Za-z0-9_]+/` NÃO casa `test_(a|b)`, furo que
    // deu `SEM_ID=14` acusando trabalho CORRETO).
    const plano = fs.readFileSync(path.resolve(RAIZ, REL_PLANO), 'utf8');
    const ids = linhas.map((l) => l.trim().split(/\s+/)[0]);
    assert.equal(new Set(ids).size, ids.length, 'id REPETIDO no manifesto — o harness pega o PRIMEIRO');
    const doVerify = [...plano.matchAll(/verificar-testes\.sh\s+188-11\s+([\w-]+)/g)].map((m) => m[1]);
    assert.ok(doVerify.length > 0, 'não achei `<verify>` com `188-11` no plano — a leitura está cega');
    assert.deepEqual(ids.filter((id) => !doVerify.includes(id)), [], 'id declarado no manifesto que NENHUM `<verify>` roda — ele não falha: simplesmente nunca roda');
    assert.deepEqual(doVerify.filter((id) => !ids.includes(id)), [], 'id usado por um `<verify>` e não declarado no manifesto — o harness ABORTA');
    const EXPANSOR = /test_(?:\([A-Za-z0-9_|]+\)|[A-Za-z0-9_]+)/;
    assert.equal(EXPANSOR.test('test_(a|b)_188_11'), true, 'o expansor de alternância não casa a forma `test_(a|b)` — é o furo MEDIDO pela wave 188-05');
    assert.equal(EXPANSOR.test('test_simples_188_11'), true, 'o expansor deixou de casar a forma simples');

    // (f) Sufixo `188_11` em todo nome de teste deste arquivo.
    const nomes = [...fs.readFileSync(path.resolve(RAIZ, esteArquivo), 'utf8').matchAll(/^test\('([^']*)'/gm)].map((m) => m[1]);
    assert.ok(nomes.length > 0, 'não achei os nomes de teste — a leitura está cega');
    assert.deepEqual(nomes.filter((n) => !n.endsWith('188_11')), [], 'nome de teste sem o sufixo `188_11`');

    // (g) ⚠️ Nenhum id `php` — declarado, não presumido.
    assert.deepEqual(linhas.filter((l) => /\s+php\s+/.test(l)), [], 'entrou id `php` no manifesto — agora a conferência de homônimo por script é obrigatória');

    // (h) ⚠️ O `vocabulario-tamanhos` em LINHA PRÓPRIA, com UM arquivo, forma `>=`.
    const tamanhos = linhas.filter((l) => l.startsWith('vocabulario-tamanhos'));
    assert.equal(tamanhos.length, 1, '`vocabulario-tamanhos` não está em linha própria — sem a linha o harness ABORTA');
    assert.match(tamanhos[0], /tests=>=\d+/, 'a forma de `vocabulario-tamanhos` deixou de ser `>=`');
    assert.deepEqual(tamanhos[0].split('::')[0].trim().split(/\s+/).slice(2), [REL_GATE_VOCABULARIO], 'o id `vocabulario-tamanhos` passou a rodar OUTROS arquivos');
});

// ═══════════════════════════════════════════════════════════════════════════
// 13. O PROTÓTIPO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ FID-01: os ponteiros de `:795`-`:804` e de `:1398`/`:1399`, por LEITURA DA LINHA 188_11', async (contexto) => {
    const abs = path.resolve(RAIZ, PROTOTIPO);
    if (!fs.existsSync(abs)) {
        contexto.skip(`${PROTOTIPO} ausente — o pacote de handoff não é versionado`);

        return;
    }

    const linhas = fs.readFileSync(abs, 'utf8').split(/\r?\n/);
    const em = (n) => linhas[n - 1] ?? '';

    await contexto.test('o arquivo é o MEDIDO (1432 linhas) e o diretório é o certo', () => {
        assert.equal(linhas.length, 1432 + 1, `o protótipo mudou de tamanho (${linhas.length - 1} linhas) — remeça TODOS os ponteiros`);
    });

    await contexto.test('⚠️ o HTML do MOD-02: `:797` (não `:796`), `:799`, `:801`/`:802`, `:804` (não `:803`)', () => {
        assert.match(em(796), /isExc/, '`:796` não é o `<sc-if>` do MOD-02 — era ele que a §F.2 confundia com a caixa');
        assert.match(em(797), /rgba\(239,68,68,0\.08\)/, '`:797` não é a caixa VERMELHA');
        assert.match(em(797), /rgba\(239,68,68,0\.35\)/, '`:797` perdeu a borda vermelha');
        assert.match(em(798), />Responsável pela autorização$/, '`:798` não é o `<label>` de responsável');
        assert.match(em(799), /<select/, '`:799` não é o `<select>` de responsável');
        assert.match(em(801), /Justificativa \(mín\. 10 caracteres\)/, '`:801` não é o `<label>` da justificativa, onde vive o literal');
        assert.match(em(802), /rows="3"/, '`:802` não é o `<textarea>` da justificativa');
        assert.match(em(802), /placeholder="Ex\.: oferta de entrada para ganhar relevância por 15 dias"/, 'o placeholder de `:802` mudou');
        assert.match(em(803), /<\/label>/, '`:803` deixou de ser o `</label>` — era ele que a §F.2 confundia com a nota');
        assert.match(em(804), /No protótipo, a exceção é registrada/, '`:804` não é a PROSA do protótipo');
        assert.match(em(804), /font-size:11\.5px;color:#71717A/, '`:804` perdeu a medida da nota');
    });

    await contexto.test('⚠️⚠️ `>= 10` está em `:1398`, e `kindLabel`/`kindValue`/`approvers` em `:1399`', () => {
        assert.match(em(1398), /if \(k === 'exception'\)/, '`:1398` deixou de ser a abertura do ramo `exception`');
        assert.match(em(1398), /\(F\.reason \|\| ''\)\.trim\(\)\.length >= 10/, '`:1398` não tem o `>= 10` — se virou `> 10`, remeça a trava da tela JUNTO');
        assert.match(em(1399), /kindLabel: F\.kind === 'loss' \? 'Resultado negativo' : 'Margem abaixo do mínimo'/, '`:1399` não tem o `kindLabel`');
        assert.match(em(1399), /mínimo 12%/, '`:1399` não tem o `12%` — ele é `MIN`, não literal');
        assert.match(em(1399), /Ricardo Gomes/, '`:1399` não tem os nomes de EXEMPLO — eles são a isca de D8');
        assert.match(em(1395), /exception: 'Registrar exceção autorizada'/, '`:1395` não tem o rótulo do botão');
        assert.match(em(1203), /submitException\(\)/, '`:1203` não é o `submitException`');
        assert.match(em(1203), /length < 10/, '`:1203` perdeu a recusa com `length < 10` — é ela que prova que 10 PASSA');
    });

    await contexto.test('⚠️ `cursor` / `white-space` / `text-overflow`, em COLUNAS PRÓPRIAS', () => {
        const faixa = [796, 797, 798, 799, 801, 802, 803, 804];
        const comCursor = faixa.filter((n) => /cursor:pointer/.test(em(n)));
        const comNowrap = faixa.filter((n) => /white-space:nowrap/.test(em(n)));
        const comEllipsis = faixa.filter((n) => /text-overflow:ellipsis/.test(em(n)));
        assert.deepEqual(comCursor, [], `\`cursor:pointer\` apareceu em [${comCursor.join(', ')}] — nenhum dos oito elementos do MOD-02 o tem`);
        assert.deepEqual(comNowrap, [], `\`white-space:nowrap\` apareceu em [${comNowrap.join(', ')}] — nenhum dos oito o tem`);
        assert.deepEqual(comEllipsis, [], '`text-overflow:ellipsis` apareceu no MOD-02');
        // ⚠️ ISCA: a varredura ACHA as três onde elas EXISTEM (o × da casca e o
        // chip do MOD-01), senão as três listas vazias acima seriam vazias por
        // construção — sonda cega passando como "conferido".
        assert.match(em(779), /cursor:pointer/, 'a varredura está cega: nem no × de `:779` ela acha `cursor:pointer`');
        assert.match(em(794), /white-space:nowrap/, 'a varredura está cega: nem no chip de `:794` ela acha `white-space:nowrap`');
    });

    await contexto.test('⚠️ CRUZAMENTO: os literais do MÓDULO aparecem no protótipo', () => {
        assert.ok(em(798).includes(MOD_EXCECAO.ROTULO_DO_RESPONSAVEL), 'o rótulo do responsável não casa `:798`');
        assert.ok(em(801).includes(MOD_EXCECAO.ROTULO_DA_JUSTIFICATIVA), 'o rótulo da justificativa não casa `:801`');
        assert.ok(em(802).includes(MOD_EXCECAO.PLACEHOLDER_DA_JUSTIFICATIVA), 'o placeholder não casa `:802`');
        assert.ok(em(1399).includes(MOD_EXCECAO.TITULO), 'o título não casa `:1399`');
        assert.ok(em(1395).includes(MOD_EXCECAO.ROTULO_DO_BOTAO), 'o rótulo do botão não casa `:1395`');
        // ⚠️ E a NOTA do produto NÃO está no protótipo — é divergência DECLARADA.
        assert.equal(em(804).includes(MOD_EXCECAO.NOTA), false, 'a nota do produto virou igual à prosa do protótipo — a divergência era deliberada');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 14. O CSS CONSTRUÍDO
// ═══════════════════════════════════════════════════════════════════════════

function escapaRegex(texto_) {
    return texto_.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function seletorDe(classe) {
    return `.${[...classe].map((ch) => (/[A-Za-z0-9_-]/.test(ch) ? ch : `\\${ch}`)).join('')}`;
}

const FONTES_DO_CSS = [REL_EXCECAO];

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

test('⚠️ FID: as classes do MOD-02 GERAM CSS com o valor certo 188_11', async (contexto) => {
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
        assert.equal(tem('resources/js/Pages/Mlb/NaoExiste7h4.jsx'), false, 'a isca do manifest virou entrada de verdade');
    });

    await contexto.test('⚠️ ISCA: a sonda reporta AUSENTE o que não existe, e ACHA o que existe', () => {
        for (const isca of ['bg-pub-isca-188-11b', 'text-[92.5px]', 'w-[1977px]', 'top-[63.5%]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS.css.includes(isca), `a string da isca ${isca} aparece no CSS`);
        }
        assert.notEqual(regraDe('text-[13px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('⚠️⚠️ a GEOMETRIA do MOD-02, com as OITO normalizações', () => {
        // ⚠️ 3ª normalização: a opacidade em `background`/`border` sai em **hex de
        // 8 dígitos**, e o byte do alfa PROVA a opacidade (`0.08` → `0x14`,
        // `0.35` → `0x59`).
        assert.match(regraDe('bg-pub-danger/[0.08]'), /#ef444414/, 'o fundo da caixa vermelha não é `rgba(239,68,68,0.08)` (`:797`)');
        assert.match(regraDe('border-pub-danger/[0.35]'), /#ef444459/, 'a borda da caixa vermelha não é `rgba(239,68,68,0.35)` (`:797`)');

        // ⚠️ 1ª/2ª: token SÓLIDO sai em `rgb(R G B / var(…))`.
        assert.match(regraDe('text-pub-danger-txt'), /rgb\(248 113 113/, '`text-pub-danger-txt` não é #F87171 (`:797`)');
        assert.match(regraDe('text-pub-fase1'), /rgb\(212 212 216/, '`text-pub-fase1` não é #D4D4D8 (`:797`)');
        assert.match(regraDe('bg-pub-sunken'), /rgb\(13 13 13/, '`bg-pub-sunken` não é #0D0D0D (`:799`/`:802`)');
        assert.match(regraDe('text-pub-txt-4'), /rgb\(113 113 122/, '`text-pub-txt-4` não é #71717A (`:804`)');
        assert.match(regraDe('font-pub'), /Geist/, '`font-pub` deixou de resolver a Geist — o modal cairia em Inter no portal');

        const semRegra = [
            'rounded-[8px]', 'rounded-pub-control', 'flex', 'flex-col', 'border', 'resize-y',
            'p-[12px]', 'px-[10px]', 'px-[12px]', 'py-[10px]', 'gap-[3px]', 'gap-[6px]', 'gap-[14px]',
            'h-[36px]', 'outline-none', 'font-semibold', 'leading-[1.45]',
            'text-[11.5px]', 'text-[12.5px]', 'text-[13px]',
        ].filter((c) => regraDe(c) === null);
        assert.deepEqual(semRegra, [], 'classe do MOD-02 NÃO gerou CSS — num `.js` isso aconteceria com TODO teste de render passando');
    });
});
