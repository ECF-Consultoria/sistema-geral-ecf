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
// Fase 188, plano 12, TAREFA 2 — MOD-04: "Reprovar imagem".
//
// ⚠️⚠️ DE CADA BLOCO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR: "o que
// precisaria estar errado no mundo para ele falhar?" — **"Nada" é ACHADO.**
//
//  1. ⚠️⚠️ OS CINCO EFEITOS → a tela reimplementando qualquer um deles. Seria a
//     segunda verdade, e a divergente só apareceria **quando alguém reprovasse a
//     PRINCIPAL** — o caso mais raro e o mais caro.
//  2. ⚠️⚠️ A NOTA DE `:821` → é ela que diz que **NÃO HÁ DESFAZER**. Omiti-la faz
//     a pessoa clicar sem saber o que acontece.
//  3. ⚠️⚠️ `> 2` × `>= 10` → *"deixei igual ao outro modal"* é **FID-02 com outra
//     roupa**. Medido em **2, 3 e 4**, e CRUZADO com o piso da exceção.
//  4. ⚠️⚠️ O BOTÃO VERMELHO (`:1403`) → é o **ÚNICO** dos quatro modais com cor de
//     submissão diferente, e é **fácil de omitir** porque a casca traz
//     `CLASSES_SUBMETER_ATIVO = 'bg-pub-accent …'`.
//  5. ⚠️⚠️ A CASCA COMPOSTA → **nenhuma medida redeclarada**, tudo importado dos
//     named exports dela, e `numstat` **ZERO** nela.
//  6. ⚠️⚠️ OS CHIPS DO SERVIDOR, CONCATENANDO COM `'; '` → três modais, três
//     formas; e lista vazia **não inventa chip** (D4).
//  7. ⚠️⚠️ A MINIATURA → `url_arquivo` da **mesma fonte** da coluna de fotos (a
//     rota serve BYTES). ⚠️ O acervo é PROIBIDO (R2 privado em produção desde
//     16/10). ⚠️ `url_arquivo === null` → o RÓTULO, **nunca `<img src="">`**.
//  8. TELA PRETA, ROLLUP, TERRITÓRIO, LISTAS e CSS construído.
//
// ⚠️ Todos os nomes de teste terminam em `188_12`.
// ⚠️⚠️ **TODOS os bundles são montados ANTES do primeiro `test()`.**
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

const STUB_INERTIA = path.join(__dirname, `.pub-reprovar188-inertia-stub-${process.pid}.mjs`);

fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
globalThis.CHAMADAS_REPROVAR_188_12 = globalThis.CHAMADAS_REPROVAR_188_12 ?? [];
const CHAMADAS = globalThis.CHAMADAS_REPROVAR_188_12;
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
        `.pub-reprovar188-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';
const REL_REPROVAR = `${DIR_APROV}/ModalDeReprovarImagem.jsx`;
const REL_EXCECAO = `${DIR_APROV}/ModalDeExcecao.jsx`;
const REL_AJUSTE = `${DIR_APROV}/ModalDeAjuste.jsx`;
const REL_CASCA = `${DIR_APROV}/CascaDeModal.jsx`;
const REL_TOAST = `${DIR_APROV}/ToastDaFila.jsx`;
const REL_PAINEL = `${DIR_APROV}/PainelDeRevisao.jsx`;
const REL_BLOCO = `${DIR_APROV}/BlocoDaImagemSelecionada.jsx`;
const REL_FOTOS = `${DIR_APROV}/ColunaDeFotos.jsx`;
const REL_VOCABULARIO = `${DIR_APROV}/vocabularioDoPortao.jsx`;
const REL_PAGINA = 'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx';
const REL_GATE_VOCABULARIO = 'tests/js/publicador-vocabulario-novo.test.js';
const REL_GATE_FECHAMENTO = 'tests/js/publicador-fila-fechamento-187.test.js';
const REL_GATE_ZINDEX = 'tests/js/publicador-header-zindex-ordem.test.js';
const REL_SERVICO_IMAGEM = 'app/Services/Publicador/Aprovacao/RevisaoDeImagemService.php';
const REL_SERVICO_FOTOS = 'app/Services/Publicador/Aprovacao/Painel/FotosDoPainelService.php';
const REL_MODELO_DEFEITO = 'app/Models/PubAprovacaoDefeito.php';
const REL_ROTAS = 'routes/mlb_anuncios.php';
const REL_MANIFESTO = 'tests/gates/contagens-188-12.txt';
const REL_PLANO = '.planning/phases/188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece/188-12-PLAN.md';
const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

const CAMINHOS_CITADOS = [
    REL_REPROVAR, REL_EXCECAO, REL_AJUSTE, REL_CASCA, REL_TOAST, REL_PAINEL, REL_BLOCO, REL_FOTOS,
    REL_VOCABULARIO, REL_PAGINA, REL_GATE_VOCABULARIO, REL_GATE_FECHAMENTO, REL_GATE_ZINDEX,
    REL_SERVICO_IMAGEM, REL_SERVICO_FOTOS, REL_MODELO_DEFEITO, REL_ROTAS, REL_MANIFESTO, REL_PLANO,
];

const FONTE_REPROVAR = lerSemComentarios(REL_REPROVAR);
const CRUA_REPROVAR = fs.readFileSync(path.resolve(RAIZ, REL_REPROVAR), 'utf8');
const FONTE_PAGINA = lerSemComentarios(REL_PAGINA);

const FONTES_DO_PLANO = [[REL_REPROVAR, FONTE_REPROVAR]];

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
        rotulo: texto(m[2]),
        classe: (m[1].match(/class="([^"]*)"/) || [])[1] ?? '',
        desabilitado: /\bdisabled=/.test(m[1]),
    }));
}

// ═══ AS FIXTURES — ⚠️⚠️ ELAS DISCRIMINAM DE PROPÓSITO ═════════════════════
//
// ⚠️ A imagem de IA e a ORIGINAL vêm separadas, porque o `sub` depende do
// `tipo_rotulo`; e há uma **SEM `url_arquivo`**, que é o caso REAL do
// `PubImagem.caminho` nulo (IMG-13).

const IMAGEM_IA = {
    id: 910,
    imagem_id: 4471,
    rotulo: 'Frontal',
    tipo_rotulo: 'Gerada por IA',
    // ⚠️ A URL é a FORMA que `route('mlb.anuncios.publicador.fotos.arquivo', …)`
    // produz em produção. ⚠️ A 1ª redação desta fixture usava a forma do STUB de
    // `route()` deste gate (`/nome{"produto":77}`), e o `"` dela sai ESCAPADO como
    // `&quot;` no HTML — a asserção do `src` reprovava por causa da FIXTURE, não do
    // código. **Fixture que não parece com produção mede outra coisa.**
    url_arquivo: '/mlb/anuncios/publicador/produtos/77/fotos/4471/arquivo',
};

const IMAGEM_ORIGINAL = { ...IMAGEM_IA, id: 911, imagem_id: 4472, rotulo: 'Lateral', tipo_rotulo: 'Original' };

const IMAGEM_SEM_ARQUIVO = { ...IMAGEM_IA, id: 912, imagem_id: 4473, rotulo: 'Detalhe do tecido', url_arquivo: null };

const DEFEITOS = [
    { chave: 'deform', rotulo: 'Deformação' },
    { chave: 'dup', rotulo: 'Peça duplicada' },
    { chave: 'falta', rotulo: 'Peça ausente' },
];

const MOD_REPROVAR = await montar(REL_REPROVAR);
const MOD_EXCECAO = await montar(REL_EXCECAO);
const MOD_AJUSTE = await montar(REL_AJUSTE);
const MOD_CASCA = await montar(REL_CASCA);

// ═══════════════════════════════════════════════════════════════════════════
// 0. ANTI-FANTASMA
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ ANTI-FANTASMA: todo caminho citado pelo gate de reprovar EXISTE no disco 188_12', () => {
    const fantasmas = CAMINHOS_CITADOS.filter((rel) => !fs.existsSync(path.resolve(RAIZ, rel)));
    assert.deepEqual(fantasmas, [], 'caminho fantasma citado por este gate — sonda de ausência sobre arquivo inexistente passa CALADA');
    assert.equal(fs.existsSync(path.resolve(RAIZ, 'app/Services/Publicador/Aprovacao/Fila/ResumoRapidoService.php')), false, 'o caminho fantasma MEDIDO pela wave 188-06 virou arquivo de verdade');
});

// ═══════════════════════════════════════════════════════════════════════════
// 1. OS CINCO EFEITOS SÃO DO SERVIDOR, E A TELA DIZ O QUE VAI ACONTECER
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ CRITÉRIO 13: os CINCO efeitos são do servidor, e a tela só colhe o motivo 188_12', () => {
    // (a) ⚠️ A tela NÃO reimplementa nenhum dos cinco. A divergente só apareceria
    // **quando alguém reprovasse a PRINCIPAL** — o caso mais raro e o mais caro.
    const DO_SERVIDOR = [
        [/principal/i, 'promover a próxima a principal'],
        [/\bpromov/i, 'promover'],
        [/reabr/i, 'reabrir a aprovação do conjunto'],
        [/fora da publica/i, 'mandar para "Fora da publicação"'],
        [/conjunto\s*\./i, 'mexer no conjunto'],
    ];
    const infratores = DO_SERVIDOR.filter(([re]) => re.test(FONTE_REPROVAR)).map(([, razao]) => razao);
    assert.deepEqual(infratores, [], 'a tela passou a falar dos efeitos que são do servidor — seria a SEGUNDA VERDADE sobre o que reprovar faz');

    // ⚠️ ISCA nas duas direções: as cinco regex casam o que DEVEM casar, e os cinco
    // efeitos ESTÃO no serviço da Fase 186.
    const ISCAS = ['definirPrincipal(x)', 'promoverProxima()', 'reabrirOfertas()', 'Fora da publicação', 'conjunto.imgs'];
    assert.deepEqual(DO_SERVIDOR.filter(([re], i) => !re.test(ISCAS[i])).map(([, r]) => r), [], 'regex que não casa o próprio caso — gate cego');
    const servico = lerSemComentarios(REL_SERVICO_IMAGEM);
    assert.match(servico, /function reprovar\(/, 'o `reprovar()` saiu de `RevisaoDeImagemService` — a tela chamaria o nada');
    assert.match(servico, /DB::transaction|transaction\(/, 'o `reprovar()` deixou de ser uma TRANSAÇÃO ÚNICA — os cinco efeitos poderiam gravar pela metade');

    // (b) ⚠️⚠️ MAS A TELA DIZ O QUE VAI ACONTECER — é a nota de `:821`, e é ela que
    // faz a pessoa entender que **não há desfazer**.
    assert.equal(MOD_REPROVAR.NOTA, 'A imagem sai do conjunto final e fica registrada como reprovada.', 'a nota de `:821` mudou — se foi de propósito, remeça a expectativa CONGELADA aqui e declare');
    assert.notEqual(MOD_REPROVAR.NOTA, 'A imagem sai do conjunto final e fica registrada como reprovada', 'a sonda aceita o quase-igual SEM o ponto final');
    const html = render(MOD_REPROVAR.default, {
        aberto: true, imagem: IMAGEM_IA, defeitos: DEFEITOS, motivo: 'pe torto',
        onMotivo: nada, onFechar: nada, onSubmeter: nada,
    });
    assert.ok(texto(html).includes(MOD_REPROVAR.NOTA), 'a nota de `:821` não está na tela — é ela que diz que NÃO HÁ DESFAZER');

    // (c) O pedido que sai do clique é SÓ o motivo.
    const chamadas = [];
    const el = React.createElement(MOD_REPROVAR.default, {
        aberto: true, imagem: IMAGEM_IA, defeitos: DEFEITOS, motivo: 'pe torto',
        onMotivo: nada, onFechar: nada, onSubmeter: (p) => chamadas.push(p),
    });
    const arvore = el.type(el.props);
    const submeter = arvore.props.children.props.children[2].props.children[1];
    submeter.props.onSubmeter();
    assert.deepEqual(chamadas, [{ motivo: 'pe torto' }], 'o clique não mandou SÓ o motivo');
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. A TRAVA `> 2`, E ELA É DIFERENTE DO `>= 10` DE PROPÓSITO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ T-188-B2 / `:1402`: o motivo é `> 2`, e isso é DIFERENTE do `>= 10` da exceção 188_12', () => {
    const { motivoValido, MINIMO_DO_MOTIVO } = MOD_REPROVAR;

    assert.equal(MINIMO_DO_MOTIVO, 2, 'o piso do motivo mudou');

    // ⚠️⚠️ A FRONTEIRA, nos TRÊS pontos: 2 recusa, 3 PASSA, 4 passa.
    assert.equal(motivoValido('ab'), false, '2 caracteres passaram — a trava é `> 2`');
    assert.equal(motivoValido('abc'), true, '⚠️ 3 caracteres foram RECUSADOS — a trava é `> 2`, não `> 3`');
    assert.equal(motivoValido('abcd'), true, '4 caracteres foram recusados');
    assert.equal(motivoValido('  ab  '), false, 'espaços contaram para o mínimo — `trim()` é o que o protótipo usa');
    assert.equal(motivoValido('  abc  '), true, 'o `trim()` comeu caractere de verdade');
    assert.equal(motivoValido('   '), false, 'só espaços passou');
    for (const adverso of [null, undefined, 123, {}, [], NaN, { toString: null }]) {
        assert.equal(motivoValido(adverso), false, 'motivo adverso passou');
    }

    // ⚠️⚠️ **O CRUZAMENTO COM A EXCEÇÃO: os dois pisos são DIFERENTES de propósito**
    // (`:1402` × `:1398`), e *"deixei igual ao outro modal"* é FID-02 com outra
    // roupa. ➡️ A prova é por COMPORTAMENTO, não por leitura de constante.
    assert.equal(MOD_EXCECAO.MINIMO_DA_JUSTIFICATIVA, 10, 'o piso da EXCEÇÃO mudou — os dois foram harmonizados?');
    assert.notEqual(MOD_REPROVAR.MINIMO_DO_MOTIVO, MOD_EXCECAO.MINIMO_DA_JUSTIFICATIVA, 'os dois pisos ficaram IGUAIS — eles são diferentes DE PROPÓSITO');
    assert.equal(motivoValido('abc'), true, 'o motivo de 3 caracteres passa');
    assert.equal(MOD_EXCECAO.justificativaValida('abc'), false, 'a justificativa de 3 caracteres passou — ela exige 10');
    // ⚠️ E o operador também é diferente: `> 2` × `>= 10`.
    assert.equal(motivoValido('ab'), false, 'o `>` do motivo virou `>=`');
    assert.equal(MOD_EXCECAO.justificativaValida('1234567890'), true, 'o `>=` da justificativa virou `>`');

    // ⚠️ AS DUAS PROVAS na tela: botão `disabled=` + APAGADO, e o clique não
    // disparando. ⚠️ `disabled=` por ATRIBUTO, nunca `/disabled/`.
    const base = { aberto: true, imagem: IMAGEM_IA, defeitos: DEFEITOS, onMotivo: nada, onFechar: nada };
    for (const curto of ['', 'a', 'ab', '   ', '  ab ']) {
        const html = render(MOD_REPROVAR.default, { ...base, motivo: curto, onSubmeter: nada });
        const botoes = botoesDe(html);
        const submeter = botoes.filter((b) => b.rotulo === 'Reprovar imagem');
        assert.equal(submeter.length, 1, `não achei EXATAMENTE um botão "Reprovar imagem" (achei ${submeter.length})`);
        assert.equal(submeter[0].desabilitado, true, `o botão não saiu \`disabled=\` com motivo ${JSON.stringify(curto)}`);
        assert.ok(submeter[0].classe.split(/\s+/).includes('bg-pub-seg'), 'o botão não saiu APAGADO');
        const cancelar = botoes.filter((b) => b.rotulo === 'Cancelar');
        assert.equal(cancelar.length, 1, 'o "Cancelar" da casca sumiu');
        assert.equal(cancelar[0].desabilitado, false, 'o "Cancelar" saiu desabilitado — a medição está no irmão errado');

        const chamadas = [];
        const el = React.createElement(MOD_REPROVAR.default, { ...base, motivo: curto, onSubmeter: (p) => chamadas.push(p) });
        const arvore = el.type(el.props);
        arvore.props.children.props.children[2].props.children[1].props.onSubmeter();
        assert.deepEqual(chamadas, [], `o clique com motivo ${JSON.stringify(curto)} chamou \`onSubmeter\``);
    }

    // ⚠️ E a trava REAL é do servidor, cujo piso é NÃO-VAZIO (a 188-05 declarou que
    // `min:3` criaria DUAS regras sobre o mesmo campo).
    const controlador = fs.readFileSync(path.resolve(RAIZ, 'app/Http/Controllers/MlbPublicadorAprovacaoController.php'), 'utf8');
    assert.match(controlador, /'motivo' => \['required', 'string'\]/, 'a action de reprovar mudou a regra do motivo — o piso do servidor é NÃO-VAZIO, e `min:3` seria uma SEGUNDA regra sobre o mesmo campo');
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. O BOTÃO ATIVO É VERMELHO — E A CASCA NÃO FOI EDITADA
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ `:1403`: o submeter ATIVO é VERMELHO, e a casca é COMPOSTA sem ser editada 188_12', () => {
    const base = { aberto: true, imagem: IMAGEM_IA, defeitos: DEFEITOS, onMotivo: nada, onFechar: nada, onSubmeter: nada };

    // (a) ATIVO: vermelho, e **não** o amarelo da casca.
    const ativo = botoesDe(render(MOD_REPROVAR.default, { ...base, motivo: 'pe torto' })).find((b) => b.rotulo === 'Reprovar imagem');
    const classesAtivo = new Set(ativo.classe.split(/\s+/));
    assert.ok(classesAtivo.has('bg-pub-danger'), 'o submeter ATIVO não é VERMELHO (`:1403`: `#EF4444`) — é o ÚNICO dos quatro modais com cor diferente, e a casca traz `bg-pub-accent`');
    assert.ok(classesAtivo.has('text-pub-page'), 'o texto sobre o vermelho não é `pub-page` (#0A0A0A)');
    assert.equal(classesAtivo.has('bg-pub-accent'), false, 'o submeter saiu AMARELO — a cor da casca venceu');

    // (b) APAGADO: o mesmo par da casca.
    const apagado = botoesDe(render(MOD_REPROVAR.default, { ...base, motivo: 'a' })).find((b) => b.rotulo === 'Reprovar imagem');
    const classesApagado = new Set(apagado.classe.split(/\s+/));
    assert.ok(classesApagado.has('bg-pub-seg'), 'o submeter APAGADO não é `#262626`');
    assert.equal(classesApagado.has('bg-pub-danger'), false, 'o submeter APAGADO saiu vermelho — a cor ficou FIXA');

    // (c) ⚠️⚠️ A GEOMETRIA é IMPORTADA da casca: nenhuma medida é redeclarada.
    const MEDIDAS_DA_CASCA = ['h-[36px]', 'px-[16px]', 'rounded-pub-control', 'pt-[96px]', 'max-h-[calc(100vh-140px)]', 'shadow-pub-modal', 'w-[560px]', 'px-[20px]', 'py-[14px]'];
    const redeclaradas = MEDIDAS_DA_CASCA.filter((m) => FONTE_REPROVAR.includes(m));
    assert.deepEqual(redeclaradas, [], 'o modal REDECLAROU medida da casca — a geometria tem de ficar numa casa só');

    // ⚠️ E ele IMPORTA as nove peças dela, por FORMA.
    for (const peca of ['CLASSES_BACKDROP', 'CLASSES_CAIXA', 'CLASSES_CORPO', 'CLASSES_RODAPE', 'CLASSES_BOTAO', 'CLASSES_CANCELAR', 'CLASSES_SUBMETER', 'ROTULO_CANCELAR', 'TopoDoModal']) {
        assert.ok(FONTE_REPROVAR.includes(peca), `o modal parou de IMPORTAR \`${peca}\` da casca — a geometria viraria cópia`);
    }
    assert.match(FONTE_REPROVAR, /from '\.\/CascaDeModal'/, 'o modal parou de importar da casca da Fase 187');

    // ⚠️ E a casca sai INTOCADA, com a isca determinística da ÁRVORE VAZIA.
    for (const rel of [REL_CASCA, REL_TOAST, REL_GATE_ZINDEX, REL_VOCABULARIO, `${DIR_APROV}/BarraDeMargem.jsx`]) {
        assert.equal(numstatDe(rel).trim(), '', `${rel} foi alterado — ele é INTOCÁVEL/em produção`);
    }
    assert.notEqual(numstatDe(REL_CASCA, ARVORE_VAZIA).trim(), '', 'a sonda de `numstat` está cega: nem contra a árvore vazia ela produz linha');

    // (d) A ÁRVORE do composto: backdrop → caixa → topo + corpo + rodapé.
    const els = tagsDeAbertura(render(MOD_REPROVAR.default, { ...base, motivo: 'pe torto' }));
    assert.equal(els[0].tag, 'div', 'a raiz do modal mudou de tag');
    assert.ok(classesDe(els[0]).has('z-[42]'), 'o backdrop perdeu o `z-[42]` da casca — a ORDEM TRAVADA depende dele');
    assert.ok(classesDe(els[0]).has('pt-[96px]'), 'o backdrop perdeu o `pt-[96px]` da casca');
    assert.ok(classesDe(els[1]).has('w-[560px]'), 'a caixa perdeu os 560px da casca');
    assert.ok(classesDe(els[1]).has('shadow-pub-modal'), 'a caixa perdeu a sombra da casca');
    // ⚠️ E o rodapé existe, com o `border-top` e o fundo `pub-sunken` de `:888` — é
    // exatamente o que se perderia se o botão fosse para dentro do `children`.
    const rodape = els.find((e) => classesDe(e).has('border-t') && classesDe(e).has('bg-pub-sunken'));
    assert.ok(rodape, 'o rodapé de `:888` sumiu — foi isso que a saída (b) perderia, pondo o botão dentro do corpo');
    assert.equal(MOD_REPROVAR.MODAL_REPROVAR, 'reprovar', 'a chave do modal mudou');
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. OS CHIPS DO SERVIDOR, E AS TRÊS FORMAS DE CONCATENAR
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ `:1403`: os chips vêm do SERVIDOR e CONCATENAM com `"; "` — três modais, três formas 188_12', () => {
    const { motivoComChip } = MOD_REPROVAR;

    // (a) A concatenação com `'; '`, sem ponto.
    assert.equal(motivoComChip('', 'Deformação'), 'Deformação', 'com o campo vazio o chip não entrou limpo');
    assert.equal(motivoComChip('Deformação', 'Peça ausente'), 'Deformação; Peça ausente', 'o chip SUBSTITUIU ou usou outro separador');
    assert.equal(motivoComChip('Deformação; Peça ausente', 'Recorte ruim'), 'Deformação; Peça ausente; Recorte ruim', 'o terceiro chip não acumulou');
    for (const adverso of [null, undefined, 42, {}, [], NaN, '', '   ', { toString: null }]) {
        assert.equal(motivoComChip('base', adverso), null, 'chip adverso produziu texto');
    }

    // (b) ⚠️⚠️ AS TRÊS FORMAS, CRUZADAS: o MOD-01 concatena com ESPAÇO **e PONTO**
    // (`:1397`), o MOD-04 com **PONTO-E-VÍRGULA** sem ponto (`:1403`), e o MOD-02
    // **não tem chip**. *"Deixei igual ao outro"* seria a quarta forma.
    assert.equal(MOD_AJUSTE.comentarioComSugestao('a', 'b'), 'a b.', 'a forma do MOD-01 mudou');
    assert.equal(motivoComChip('a', 'b'), 'a; b', 'a forma do MOD-04 mudou');
    assert.notEqual(motivoComChip('a', 'b'), MOD_AJUSTE.comentarioComSugestao('a', 'b'), 'as duas formas ficaram IGUAIS — elas são diferentes DE PROPÓSITO');
    assert.equal(MOD_REPROVAR.SEPARADOR_DO_CHIP, '; ', 'o separador do MOD-04 mudou');
    assert.equal(MOD_AJUSTE.SEPARADOR_DA_SUGESTAO, ' ', 'o separador do MOD-01 mudou');
    assert.equal(typeof MOD_EXCECAO.motivoComChip, 'undefined', 'o MOD-02 ganhou chip — ele NÃO tem (`:795`-`:804`)');

    // (c) ⚠️ Os chips vêm do SERVIDOR: os SEIS rótulos são PROIBIDOS na fonte.
    const vocab = lerSemComentarios(REL_VOCABULARIO);
    const bloco = vocab.match(/const\s+DEFEITOS\s*=\s*\{([\s\S]*?)\};/);
    assert.ok(bloco, 'não achei o literal de `DEFEITOS` em `vocabularioDoPortao.jsx` — a leitura está cega');
    const rotulos = [...bloco[1].matchAll(/'([^']+)'/g)].map((m) => m[1]);
    assert.equal(rotulos.length, 6, `o vocabulário deixou de ter SEIS defeitos (tem ${rotulos.length}) — remeça a lista`);
    const cravados = rotulos.filter((r) => FONTE_REPROVAR.includes(r));
    assert.deepEqual(cravados, [], 'a tela CRAVOU rótulo de defeito que é do servidor — segunda redação dos rótulos, e a divergente é a que a pessoa clica');
    assert.match(lerSemComentarios(REL_MODELO_DEFEITO), /DEFEITOS = \[/, 'a lista canônica saiu de `PubAprovacaoDefeito` — a tela leria o nada');

    // (d) Os chips do servidor aparecem, com `'+ ' + rotulo`, na ORDEM.
    const html = render(MOD_REPROVAR.CorpoDaReprovacao, { imagem: IMAGEM_IA, defeitos: DEFEITOS, motivo: '', onMotivo: nada });
    assert.deepEqual(
        botoesDe(html).map((b) => b.rotulo),
        DEFEITOS.map((d) => `+ ${d.rotulo}`),
        'os chips não são os do servidor, na ordem, com o `+ ` na frente',
    );

    // (e) ⚠️ LISTA VAZIA → NENHUM chip, e o campo de motivo CONTINUA funcionando.
    for (const vazia of [[], null, undefined, 'lista', 42, {}, [{}], [{ chave: 'x' }], [{ rotulo: '' }]]) {
        const semChip = render(MOD_REPROVAR.CorpoDaReprovacao, { imagem: IMAGEM_IA, defeitos: vazia, motivo: '', onMotivo: nada });
        assert.equal(botoesDe(semChip).length, 0, 'a tela INVENTOU chip com lista vazia (D4)');
        assert.ok(semChip.includes('<textarea'), 'o campo de motivo sumiu junto com os chips — ele continua funcionando');
        assert.ok(texto(semChip).includes('Motivo'), 'o rótulo `Motivo` sumiu');
    }

    // (f) ⚠️ E o CLIQUE de verdade concatena.
    let recebido = null;
    const el = React.createElement(MOD_REPROVAR.CorpoDaReprovacao, { imagem: IMAGEM_IA, defeitos: DEFEITOS, motivo: 'base', onMotivo: (t) => { recebido = t; } });
    const arvore = el.type(el.props);
    const faixa = arvore.props.children.find((f) => f && f.props && f.props.className === MOD_REPROVAR.CLASSES_FAIXA_DE_CHIPS);
    assert.ok(faixa, 'não achei a faixa de chips na árvore');
    faixa.props.children.filter((c) => c !== null)[2].props.onClick();
    assert.equal(recebido, 'base; Peça ausente', 'o CLIQUE no chip não concatenou com `"; "` — o render estático não dispara `onClick`');
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. A MINIATURA, O `sub` E O CASO `url_arquivo === null`
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ T-188-B5 / IMG-13: a miniatura usa a MESMA fonte de URL, e `null` mostra o RÓTULO 188_12', () => {
    const base = { defeitos: DEFEITOS, motivo: '', onMotivo: nada };

    // (a) Com URL: um `<img>` com o `src` do servidor e o `alt` do rótulo.
    const comUrl = render(MOD_REPROVAR.CorpoDaReprovacao, { ...base, imagem: IMAGEM_IA });
    const imgs = [...comUrl.matchAll(/<img\b([^>]*)>/g)].map((m) => m[1]);
    assert.equal(imgs.length, 1, `não achei EXATAMENTE um <img> (achei ${imgs.length})`);
    assert.ok(imgs[0].includes(`src="${IMAGEM_IA.url_arquivo}"`), 'o `src` não é o `url_arquivo` do servidor');
    assert.ok(imgs[0].includes('alt="Frontal"'), 'o `alt` não é o rótulo da imagem');

    // (b) ⚠️⚠️ `url_arquivo === null` é caso REAL (`PubImagem.caminho` nulo; a rota
    // faz `abort(404)`): a tela mostra o **RÓTULO**, e **nunca `<img src="">`** —
    // string vazia num `src` faz o navegador **RECARREGAR A PÁGINA**.
    const semUrl = render(MOD_REPROVAR.CorpoDaReprovacao, { ...base, imagem: IMAGEM_SEM_ARQUIVO });
    assert.equal(/<img\b/.test(semUrl), false, 'com `url_arquivo` nulo a tela desenhou um `<img>`');
    assert.equal(/src=""/.test(semUrl), false, '`<img src="">` — string vazia num `src` faz o navegador RECARREGAR a página');
    assert.ok(texto(semUrl).includes('Detalhe do tecido'), 'com `url_arquivo` nulo a tela não mostrou o RÓTULO como placeholder (IMG-13)');
    // ⚠️ E o docblock do servidor continua declarando o caso.
    assert.match(fs.readFileSync(path.resolve(RAIZ, REL_SERVICO_FOTOS), 'utf8'), /abort\(404\)/, 'o servidor parou de declarar o caso `caminho === null`');

    // (c) ⚠️ A URL vem da MESMA fonte da coluna de fotos, e o ACERVO é PROIBIDO.
    assert.match(FONTE_REPROVAR, /url_arquivo/, 'o modal parou de ler `url_arquivo` — a MESMA chave da coluna de fotos (plano 188-08)');
    assert.ok(lerSemComentarios(REL_FOTOS).includes('url_arquivo') || lerSemComentarios(`${DIR_APROV}/GradeDeMiniaturas.jsx`).includes('url_arquivo'), 'a coluna de fotos parou de usar `url_arquivo` — as duas fontes divergiram');
    const DO_ACERVO = [/Acervo/, /ImagemAssetService/, /acervo_imagens/, /storage\//, /\/r2\//, /cloudflare/i];
    assert.deepEqual(DO_ACERVO.filter((re) => re.test(FONTE_REPROVAR)), [], 'o modal encostou no ACERVO — ele é território PROIBIDO (R2 privado em produção desde 16/10), e o bucket NÃO serve URL pública');
    assert.equal(DO_ACERVO[0].test('ImagemAssetService'), false, 'a isca do acervo está trocada');
    assert.equal(DO_ACERVO[1].test('ImagemAssetService::url()'), true, 'a regex do acervo não casa o próprio caso — gate cego');
    for (const rel of ['app/Support/Publicador/Imagem/ArquivosDeImagem.php']) {
        if (fs.existsSync(path.resolve(RAIZ, rel))) {
            assert.equal(numstatDe(rel).trim(), '', `${rel} foi alterado — o acervo é território PROIBIDO`);
        }
    }

    // (d) ⚠️ O `sub` é `{rótulo} · {Gerada por IA|Original}`, nos DOIS tipos, e o
    // tipo vem do SERVIDOR (`tipo_rotulo`).
    assert.equal(MOD_REPROVAR.subDaImagem(IMAGEM_IA), 'Frontal · Gerada por IA', 'o `sub` da imagem de IA divergiu de `:1403`');
    assert.equal(MOD_REPROVAR.subDaImagem(IMAGEM_ORIGINAL), 'Lateral · Original', 'o `sub` da imagem ORIGINAL divergiu');
    assert.equal(MOD_REPROVAR.subDaImagem(null), null, 'o `sub` de uma imagem ausente não é `null`');
    // ⚠️ E as duas frases NÃO são cravadas na tela: elas vêm de `tipo_rotulo`.
    assert.equal(FONTE_REPROVAR.includes("'Gerada por IA'"), false, 'a frase `Gerada por IA` foi CRAVADA — ela vem de `tipo_rotulo`');
    assert.equal(FONTE_REPROVAR.includes("'Original'"), false, 'a frase `Original` foi CRAVADA');
    assert.match(lerSemComentarios(REL_SERVICO_FOTOS), /TIPO_IA_ROTULO = 'Gerada por IA'/, 'o servidor mudou a frase — remeça o espelho');
    assert.ok(texto(render(MOD_REPROVAR.default, { aberto: true, imagem: IMAGEM_ORIGINAL, ...base, onFechar: nada, onSubmeter: nada })).includes('Lateral · Original'), 'o `sub` não chegou ao topo do modal');

    // (e) ⚠️ SEM IMAGEM o modal NÃO ABRE — reprovar "a imagem nenhuma" é clique morto.
    // ⚠️ "SEM imagem" é o que `objetoDoPainel()` RECUSA: `null`, `undefined`,
    // string, número, ARRAY e `NaN`. ⚠️ **`{toString: null}` NÃO entra nesta lista**
    // — ele é um OBJETO, logo um payload de imagem (adverso, mas payload), e o
    // modal **deve** abrir mostrando o placeholder. A 1ª redação o punha aqui e
    // reprovava comportamento CERTO; o caso adverso dele está no bloco da TELA
    // PRETA, que é onde ele mede algo.
    for (const vazia of [null, undefined, 'imagem', 42, [], [{}], NaN]) {
        assert.equal(render(MOD_REPROVAR.default, { aberto: true, imagem: vazia, ...base, onFechar: nada, onSubmeter: nada }), '', 'o modal abriu SEM imagem');
    }
    // ⚠️ ISCA da outra direção: COM imagem ele abre. Sem isto a sonda acima
    // passaria com o modal nunca abrindo.
    assert.notEqual(render(MOD_REPROVAR.default, { aberto: true, imagem: IMAGEM_IA, ...base, onFechar: nada, onSubmeter: nada }), '', 'o modal não abre nem COM imagem — a sonda está cega');
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. `font-pub`, A GEOMETRIA DO CORPO E A AUSÊNCIA DE ESCRITA
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ critério 15 + FID-01: `font-pub` no corpo, a geometria por CONJUNTO, zero escrita 188_12', () => {
    assert.match(FONTE_REPROVAR, /CLASSES_CORPO_DO_MODAL\s*=\s*'[^']*font-pub/, 'o corpo do modal não declara `font-pub`');
    const els = tagsDeAbertura(render(MOD_REPROVAR.CorpoDaReprovacao, { imagem: IMAGEM_IA, defeitos: DEFEITOS, motivo: 'x', onMotivo: nada }));
    assert.ok(classesDe(els[0]).has('font-pub'), 'a RAIZ do corpo não tem `font-pub` — é no limite do portal que a herança quebra');
    assert.match(CRUA_REPROVAR, /não herda fonte/i, 'o docblock parou de dizer POR QUE `font-pub` é declarado aqui');
    assert.equal(lerSemComentarios(REL_CASCA).includes('font-pub'), false, '`font-pub` entrou na casca da Fase 187');

    // A ÁRVORE do corpo, conferida pela TAG antes de medir classe.
    assert.deepEqual(
        els.map((e) => e.tag),
        ['div', 'div', 'img', 'div', 'button', 'button', 'button', 'label', 'textarea', 'span'],
        'a árvore do corpo mudou de forma — remeça os índices ABSOLUTOS antes de confiar em qualquer medida',
    );

    // `:816` — a miniatura de 120px.
    assert.deepEqual(
        [...classesDe(els[1])].sort(),
        ['aspect-square', 'bg-pub-hover-2', 'overflow-hidden', 'relative', 'rounded-[8px]', 'text-pub-txt-off', 'w-[120px]'].sort(),
        'a miniatura de `:816` divergiu',
    );
    // ⚠️ `w-[120px]` × `max-w-[120px]`: o segundo CONTÉM o primeiro.
    assert.equal(classesDe(els[1]).has('max-w-[120px]'), false, 'a miniatura ganhou `max-w-[120px]`: a igualdade de CONJUNTO distingue, `includes` não');

    // `:817` — o wrapper com `flex-wrap` e o chip.
    assert.deepEqual([...classesDe(els[3])].sort(), ['flex', 'flex-wrap', 'gap-[6px]'].sort(), 'o wrapper dos chips de `:817` perdeu o `flex-wrap`');
    assert.deepEqual(
        [...classesDe(els[4])].sort(),
        ['bg-pub-sunken', 'border', 'border-pub-line-strong', 'cursor-pointer', 'h-[26px]', 'px-[9px]', 'rounded-full', 'text-[11.5px]', 'text-pub-fase1', 'whitespace-nowrap'].sort(),
        'o chip de `:817` divergiu — `cursor:pointer` e `white-space:nowrap` são as DUAS omissões da tabela',
    );

    // `:819` — o textarea, e `:821` — a nota.
    assert.deepEqual(
        [...classesDe(els[8])].sort(),
        ['bg-pub-sunken', 'border', 'border-pub-line-strong', 'leading-[1.45]', 'outline-none', 'px-[12px]', 'py-[10px]', 'resize-y', 'rounded-[8px]', 'text-[13px]', 'text-pub-txt'].sort(),
        'o `<textarea>` de `:819` divergiu',
    );
    assert.deepEqual([...classesDe(els[9])].sort(), ['text-[12px]', 'text-pub-txt-2'].sort(), 'a nota de `:821` divergiu (ela é `12px`/`#A1A1AA`, não `11.5px`/`#71717A`)');
    assert.match(render(MOD_REPROVAR.CorpoDaReprovacao, { imagem: IMAGEM_IA, defeitos: DEFEITOS, motivo: 'x', onMotivo: nada }), /rows="3"/, 'o `rows="3"` de `:819` sumiu');
    assert.match(render(MOD_REPROVAR.CorpoDaReprovacao, { imagem: IMAGEM_IA, defeitos: DEFEITOS, motivo: 'x', onMotivo: nada }), /placeholder="O que está errado nesta imagem"/, 'o placeholder de `:819` sumiu');

    // Os literais, CONGELADOS no gate.
    assert.equal(MOD_REPROVAR.TITULO, 'Reprovar imagem', 'o título mudou');
    assert.equal(MOD_REPROVAR.ROTULO_DO_BOTAO, 'Reprovar imagem', 'o rótulo do botão mudou');
    assert.equal(MOD_REPROVAR.ROTULO_DO_MOTIVO, 'Motivo', 'o rótulo do campo mudou');
    assert.equal(MOD_REPROVAR.PLACEHOLDER_DO_MOTIVO, 'O que está errado nesta imagem', 'o placeholder mudou');
    assert.equal(MOD_REPROVAR.PREFIXO_DO_CHIP, '+ ', 'o prefixo do chip mudou');
    assert.notEqual(MOD_REPROVAR.PLACEHOLDER_DO_MOTIVO, 'O que está errado nesta imagem?', 'a sonda aceita o quase-igual com interrogação');
    assert.notEqual(MOD_REPROVAR.PREFIXO_DO_CHIP, '+', 'a sonda aceita o `+` sem o espaço');

    // O modal NÃO escreve.
    const ESCRITAS = [/router\s*\.\s*post\(/, /router\s*\.\s*put\(/, /router\s*\.\s*delete\(/, /axios\s*\.\s*(post|put|delete)\(/, /fetch\(/, /escreverNoPainel/];
    assert.deepEqual(ESCRITAS.filter((re) => re.test(FONTE_REPROVAR)), [], 'o modal escreve por conta própria — a escrita é do CHAMADOR, que é quem tem a versão');
    assert.ok(ESCRITAS.some((re) => re.test(lerSemComentarios(REL_PAINEL))), 'a sonda não acha a escrita nem no arquivo que TEM o helper');
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. A FIAÇÃO (B2): o "Reprovar" do bloco da imagem foi RELIGADO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ B2: o "Reprovar" do bloco da imagem foi RELIGADO, com a guarda e pelo helper 188_12', () => {
    // ⚠️ O botão é desenhado por `BlocoDaImagemSelecionada` (plano 188-08) e o
    // handler nasce AQUI: sem ele o critério 13 ficava INTEIRO inalcançável, porque
    // não havia caminho da tela até a rota.
    assert.match(FONTE_PAGINA, /onReprovar=\{abrirModalDeReprovar\}/, 'a página não religou o `onReprovar` da coluna de fotos');
    assert.match(FONTE_PAGINA, /const abrirModalDeReprovar = !podeEscreverNoPainel/, 'o handler perdeu a guarda — sem empresa/oferta/versão ele TEM de sair `null` (D4)');
    assert.match(FONTE_PAGINA, /<ModalDeReprovarImagem/, 'a página não monta o `ModalDeReprovarImagem`');
    assert.match(FONTE_PAGINA, /MODAL_REPROVAR/, 'a página não usa a chave do modal');

    // ⚠️ A escrita vai pelo HELPER ÚNICO, com `versao_esperada`, e a rota leva o
    // `{imagem}` no CAMINHO.
    assert.match(FONTE_PAGINA, /rota: 'mlb\.anuncios\.meus\.aprovacao\.painel\.imagem\.reprovar'/, 'a página parou de chamar a rota de reprovar');
    assert.match(FONTE_PAGINA, /parametros: \{ company: empresaId, oferta: ofertaDoPainel, imagem: imagemId \}/, 'a rota de reprovar perdeu o `{imagem}` do CAMINHO');
    assert.match(FONTE_PAGINA, /escreverNoPainel\(/, 'a página parou de escrever pelo helper único');

    // ⚠️ E `imagemId` é inteiro POSITIVO: `id = 0` é plausível e sairia numa rota
    // que não existe.
    assert.match(FONTE_PAGINA, /imagemId === null \|\| imagemId <= 0/, 'a guarda do `imagemId` deixou de exigir POSITIVO — `0` passaria pela guarda de "inteiro"');

    // ⚠️ A rota EXISTE pelo NOME.
    assert.ok(fs.readFileSync(path.resolve(RAIZ, REL_ROTAS), 'utf8').includes("name('meus.aprovacao.painel.imagem.reprovar')"), 'a rota de reprovar imagem não existe — o handler apontaria para o nada');

    // ⚠️ E o bloco continua recebendo o handler como PROP (contrato de nó da 188-08).
    assert.match(lerSemComentarios(REL_BLOCO), /onReprovar = null/, 'o `BlocoDaImagemSelecionada` deixou de receber `onReprovar` como prop');
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. A TELA PRETA, O ROLLUP, O TERRITÓRIO E AS LISTAS
// ═══════════════════════════════════════════════════════════════════════════

const FORMAS_ADVERSAS = [
    null, undefined, 0, 1, -1, NaN, Infinity, '', '   ', 'texto', true, false,
    {}, [], [{}], { toString: null }, { valueOf: null }, () => {},
];

const SEM_OBJETO_ESTRANHO = /\[object Object\]|NaN|undefined|Invalid Date/;

test('⚠️⚠️ REND / critério 19: nenhum campo adverso derruba o MOD-04, nem no CLIQUE 188_12', () => {
    const base = {
        aberto: true, imagem: IMAGEM_IA, defeitos: DEFEITOS, motivo: 'pe torto',
        onMotivo: nada, onFechar: nada, onSubmeter: nada,
    };

    for (const campo of ['id', 'rotulo', 'titulo', 'tipo_rotulo', 'url_arquivo']) {
        for (const forma of FORMAS_ADVERSAS) {
            let html = '';
            assert.doesNotThrow(() => { html = render(MOD_REPROVAR.default, { ...base, imagem: { ...IMAGEM_IA, [campo]: forma } }); }, `o campo \`imagem.${campo}\` derrubou o render`);
            assert.doesNotMatch(html, SEM_OBJETO_ESTRANHO, `o campo \`imagem.${campo}\` vazou para a tela`);
            assert.equal(/src=""/.test(html), false, `o campo \`imagem.${campo}\` produziu \`<img src="">\``);
        }
    }

    for (const campo of ['chave', 'rotulo']) {
        for (const forma of FORMAS_ADVERSAS) {
            let html = '';
            assert.doesNotThrow(() => { html = render(MOD_REPROVAR.default, { ...base, defeitos: [{ ...DEFEITOS[0], [campo]: forma }, DEFEITOS[1]] }); }, `o campo \`defeito.${campo}\` derrubou o render`);
            assert.doesNotMatch(html, SEM_OBJETO_ESTRANHO, `o campo \`defeito.${campo}\` vazou para a tela`);
        }
    }

    for (const forma of FORMAS_ADVERSAS) {
        assert.doesNotThrow(() => render(MOD_REPROVAR.default, {
            aberto: forma, imagem: forma, defeitos: forma, motivo: forma,
            onMotivo: forma, onFechar: forma, onSubmeter: forma,
        }), 'a prop inteira adversa derrubou o render');
        assert.doesNotThrow(() => render(MOD_REPROVAR.CorpoDaReprovacao, { imagem: forma, defeitos: forma, motivo: forma, onMotivo: forma }), 'a prop inteira adversa derrubou o corpo');
        assert.doesNotThrow(() => render(MOD_REPROVAR.BotaoDeReprovar, { podeSubmeter: forma, onSubmeter: forma }), 'o botão vermelho com prop adversa derrubou o render');
    }

    // ⚠️⚠️ E TAMBÉM NO CLIQUE.
    for (const forma of FORMAS_ADVERSAS) {
        assert.doesNotThrow(() => {
            const el = React.createElement(MOD_REPROVAR.default, { ...base, motivo: forma, onSubmeter: nada });
            const arvore = el.type(el.props);
            if (arvore === null) return;
            const rodape = arvore.props.children.props.children[2];
            rodape.props.children[0].props.onClick();
            rodape.props.children[1].props.onSubmeter();
            arvore.props.onClick();
            arvore.props.children.props.onClick({ stopPropagation: () => {} });
        }, 'o CLIQUE com campo adverso derrubou a página');

        const el2 = React.createElement(MOD_REPROVAR.CorpoDaReprovacao, { imagem: IMAGEM_IA, defeitos: [{ chave: 'x', rotulo: forma }, DEFEITOS[0]], motivo: forma, onMotivo: nada });
        const arvore2 = el2.type(el2.props);
        assert.doesNotThrow(() => {
            const faixa = arvore2.props.children.find((f) => f && f.props && f.props.className === MOD_REPROVAR.CLASSES_FAIXA_DE_CHIPS);
            if (faixa) for (const chip of faixa.props.children.filter((c) => c !== null)) chip.props.onClick();
            const rotulo = arvore2.props.children.find((f) => f && f.props && f.props.className === MOD_REPROVAR.CLASSES_ROTULO);
            if (rotulo) rotulo.props.children[1].props.onChange({ target: { value: forma } });
        }, 'o CLIQUE num chip adverso ou o `onChange` derrubou a página');
    }

    // O filho OBJETO COMUM passando pela casca (a casca filtra filho a filho).
    assert.doesNotThrow(() => renderToStaticMarkup(React.createElement(MOD_CASCA.default, {
        aberto: true, titulo: 'x', rotuloDoBotao: 'y', onFechar: nada, onSubmeter: nada,
        children: [{}, React.createElement(MOD_REPROVAR.CorpoDaReprovacao, { imagem: IMAGEM_IA, defeitos: DEFEITOS, motivo: 'x', onMotivo: nada })],
    })), 'objeto comum no meio dos filhos derrubou a casca');
});

test('⚠️ ROLLUP, TERRITÓRIO, LISTAS e MANIFESTO 188_12', () => {
    // O bundle DE VERDADE renderiza os três chips.
    const html = render(MOD_REPROVAR.CorpoDaReprovacao, { imagem: IMAGEM_IA, defeitos: DEFEITOS, motivo: '', onMotivo: nada });
    assert.equal(botoesDe(html).length, 3, 'o bundle não renderizou os três chips');

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
        [/confirmar publica|segundo passo|cancelar aprova|desfazer/i, 'decisão 1: aprovar JÁ PUBLICA, sem desfazer'],
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
    assert.deepEqual(infratores, [], 'apareceu forma VETADA no arquivo desta tarefa');

    const ISCAS = [
        'localStorage.getItem(1)', 'Restaurar dados de exemplo', 'String(x)', 'JSON.stringify(x)',
        '#EF4444', 'text-sm', 'ecf-yellow', 'font-display', 'border-white/[0.08]', 'md:flex',
        'Ampliar', 'Gerar novamente com IA', 'confirmar publicação', 'lucro líquido', 'duration-[180ms]',
        'new Date()',
    ];
    assert.equal(ISCAS.length, VETADOS.length, 'a lista de iscas e a de vetos divergiram');
    assert.deepEqual(VETADOS.filter(([re], i) => !re.test(ISCAS[i])).map(([, r]) => r), [], 'regex que não casa o próprio caso — gate cego');
    // ⚠️ `desfazer` é vetado na FONTE, mas a prosa CRUA fala dele de propósito —
    // esta é a prova de que a leitura SEM COMENTÁRIO é o que salva.
    assert.match(CRUA_REPROVAR, /não há desfazer/i, 'a prosa parou de dizer que NÃO HÁ DESFAZER — é ela que a próxima pessoa lê');
    assert.equal(/desfazer/i.test(FONTE_REPROVAR), false, '`lerSemComentarios` deixou de tirar a prosa — o gate passaria a reprovar pelo próprio conserto');

    assert.deepEqual(FONTES_DO_PLANO.map(([rel]) => rel), [REL_REPROVAR], 'o recorte deste gate deixou de ser a lista CONSTANTE desta tarefa');
    for (const rel of ['package.json', 'package-lock.json']) {
        assert.equal(numstatDe(rel).trim(), '', `${rel} foi alterado — este plano NÃO instala nada`);
    }

    // AS LISTAS e o MANIFESTO.
    const DO_PLANO = ['ModalDeFrete.jsx', 'ModalDeReprovarImagem.jsx', 'AvisoDeVersaoMudou.jsx'];
    const bloco = lerSemComentarios(REL_GATE_VOCABULARIO).match(/const\s+ARQUIVOS_NOVOS\s*=\s*\[([\s\S]*?)\n\];/);
    assert.ok(bloco, 'não achei o literal de `ARQUIVOS_NOVOS` — a leitura está cega');
    const declarados = [...bloco[1].matchAll(/\$\{DIR_APROV\}\/([\w.-]+\.jsx)/g)].map((m) => m[1]);
    assert.deepEqual(DO_PLANO.filter((n) => !declarados.includes(n)), [], '`.jsx` deste plano ficou FORA de `ARQUIVOS_NOVOS`');
    const bloco2 = lerSemComentarios(REL_GATE_FECHAMENTO).match(/const\s+JSX_DEPOIS_DA_187\s*=\s*\[([\s\S]*?)\n\];/);
    assert.ok(bloco2, 'não achei o literal de `JSX_DEPOIS_DA_187` — a leitura está cega');
    const depois = [...bloco2[1].matchAll(/\$\{DIR_APROV\}\/([\w.-]+\.jsx)/g)].map((m) => m[1]);
    assert.deepEqual(DO_PLANO.filter((n) => !depois.includes(n)), [], '`.jsx` deste plano ficou FORA de `JSX_DEPOIS_DA_187`');

    const manifesto = fs.readFileSync(path.resolve(RAIZ, REL_MANIFESTO), 'utf8');
    const linhas = manifesto.split(/\r?\n/).filter((l) => l.trim() !== '' && !l.trim().startsWith('#'));
    const esteArquivo = 'tests/js/publicador-painel-modal-reprovar-188.test.js';
    const minhas = linhas.filter((l) => l.includes(esteArquivo));
    assert.equal(minhas.length, 1, `o manifesto declara ${minhas.length} ids para ${esteArquivo} — é UM id por arquivo de gate`);
    assert.match(minhas[0], /^modal-de-reprovar\s+js\s+/, 'o id deste arquivo deixou de ser `modal-de-reprovar`');
    assert.deepEqual(linhas.filter((l) => /\s+php\s+/.test(l)), [], 'entrou id `php` no manifesto');

    const plano = fs.readFileSync(path.resolve(RAIZ, REL_PLANO), 'utf8');
    const ids = linhas.map((l) => l.trim().split(/\s+/)[0]);
    assert.equal(new Set(ids).size, ids.length, 'id REPETIDO no manifesto');
    const doVerify = [...plano.matchAll(/verificar-testes\.sh\s+188-12\s+([\w-]+)/g)].map((m) => m[1]);
    assert.ok(doVerify.length > 0, 'não achei `<verify>` com `188-12` no plano — a leitura está cega');
    assert.deepEqual(ids.filter((id) => !doVerify.includes(id)), [], 'id declarado no manifesto que NENHUM `<verify>` roda');
    assert.deepEqual(doVerify.filter((id) => !ids.includes(id)), [], 'id usado por um `<verify>` e não declarado no manifesto');

    const nomes = [...fs.readFileSync(path.resolve(RAIZ, esteArquivo), 'utf8').matchAll(/^test\('([^']*)'/gm)].map((m) => m[1]);
    assert.ok(nomes.length > 0, 'não achei os nomes de teste — a leitura está cega');
    assert.deepEqual(nomes.filter((n) => !n.endsWith('188_12')), [], 'nome de teste sem o sufixo `188_12`');
});

// ═══════════════════════════════════════════════════════════════════════════
// 9. O PROTÓTIPO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ FID-01: os ponteiros de `:815`-`:821` e de `:1402`/`:1403`, por LEITURA DA LINHA 188_12', async (contexto) => {
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

    await contexto.test('⚠️ o HTML do MOD-04: `:816` (não `:814`), `:817`, `:818`/`:819`, `:821` (não `:820`)', () => {
        // ⚠️ A §F.4 já corrigia: `:814` é um `</sc-if>` e `:815` é o `<sc-if>` do MOD-04.
        assert.match(em(814), /<\/sc-if>/, '`:814` deixou de ser o fechamento do MOD-03');
        assert.match(em(815), /isReject/, '`:815` não é o `<sc-if>` do MOD-04');
        assert.match(em(816), /width:120px;aspect-ratio:1\/1/, '`:816` não é a miniatura de 120px');
        assert.match(em(817), /flex-wrap:wrap/, '`:817` não é o wrapper dos chips com `flex-wrap`');
        assert.match(em(817), /border-radius:999px/, '`:817` perdeu o chip em PÍLULA');
        assert.match(em(818), />Motivo$/, '`:818` não é o `<label>` do motivo');
        assert.match(em(819), /rows="3"/, '`:819` não é o `<textarea>`');
        assert.match(em(819), /placeholder="O que está errado nesta imagem"/, 'o placeholder de `:819` mudou');
        assert.match(em(820), /<\/label>/, '`:820` deixou de ser o `</label>` — era ele que a §F.4 confundia com a nota');
        assert.match(em(821), /A imagem sai do conjunto final e fica registrada como reprovada\./, '`:821` não é a NOTA');
        assert.match(em(821), /font-size:12px;color:#A1A1AA/, '`:821` perdeu a medida da nota (12px/#A1A1AA)');
    });

    await contexto.test('⚠️⚠️ o `> 2` está em `:1402` e o `#EF4444`/chips/sub em `:1403` — NÃO em `:1404`', () => {
        // ⚠️⚠️ A §F.4 e o `<interfaces>` do `188-12-PLAN.md` apontam `:1404`.
        // MEDIDO: `:1404` é `if (k === 'regen')`, assunto da **Fase 192**.
        assert.match(em(1402), /if \(k === 'reject'\)/, '`:1402` deixou de ser a abertura do ramo `reject`');
        assert.match(em(1402), /\(F\.reason \|\| ''\)\.trim\(\)\.length > 2/, '`:1402` não tem o `> 2` — se virou `>= 2`, remeça a trava da tela JUNTO');
        assert.match(em(1403), /title: 'Reprovar imagem'/, '`:1403` não é o `return` do ramo `reject`');
        assert.match(em(1403), /\(m\.k === 'ai' \? 'Gerada por IA' : 'Original'\)/, '`:1403` não tem o `sub` com os dois tipos');
        assert.match(em(1403), /\(F\.reason \? F\.reason \+ '; ' : ''\) \+ l/, "`:1403` não tem a concatenação com `'; '`");
        assert.match(em(1403), /btnBg: ok \? '#EF4444'/, "`:1403` não tem o `#EF4444` — e é ELE, não `:1404`");
        assert.match(em(1404), /if \(k === 'regen'\)/, '⚠️ `:1404` deixou de ser o ramo `regen` — era ele que a §F.4 citava por engano');
        assert.match(em(1395), /reject: 'Reprovar imagem'/, '`:1395` não tem o rótulo do botão');
        assert.match(em(1152), /rejectImg/, '`:1152` não é o `rejectImg` com os cinco efeitos');
    });

    await contexto.test('⚠️ `cursor` / `white-space` / `text-overflow`, em COLUNAS PRÓPRIAS', () => {
        const faixa = [815, 816, 817, 818, 819, 820, 821];
        const comCursor = faixa.filter((n) => /cursor:pointer/.test(em(n)));
        const comNowrap = faixa.filter((n) => /white-space:nowrap/.test(em(n)));
        const comEllipsis = faixa.filter((n) => /text-overflow:ellipsis/.test(em(n)));
        assert.deepEqual(comCursor, [817], `\`cursor:pointer\` apareceu em [${comCursor.join(', ')}] — o esperado é SÓ o chip de \`:817\``);
        assert.deepEqual(comNowrap, [817], `\`white-space:nowrap\` apareceu em [${comNowrap.join(', ')}] — o esperado é SÓ o chip de \`:817\``);
        assert.deepEqual(comEllipsis, [], '`text-overflow:ellipsis` apareceu no MOD-04');
        // ⚠️ `white-space:nowrap` é a PRIMEIRA propriedade do `<button>` do chip.
        assert.match(em(817), /<button[^>]*style="white-space:nowrap;/, 'o `white-space:nowrap` saiu da PRIMEIRA posição do chip');
    });

    await contexto.test('⚠️ CRUZAMENTO: os literais do MÓDULO aparecem no protótipo', () => {
        assert.ok(em(818).includes(MOD_REPROVAR.ROTULO_DO_MOTIVO), 'o rótulo `Motivo` não casa `:818`');
        assert.ok(em(819).includes(MOD_REPROVAR.PLACEHOLDER_DO_MOTIVO), 'o placeholder não casa `:819`');
        assert.ok(em(821).includes(MOD_REPROVAR.NOTA), 'a nota não casa `:821`');
        assert.ok(em(1395).includes(MOD_REPROVAR.ROTULO_DO_BOTAO), 'o rótulo do botão não casa `:1395`');
        assert.ok(em(1403).includes(MOD_REPROVAR.SEPARADOR_DO_CHIP), 'o separador `; ` não casa `:1403`');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 10. O CSS CONSTRUÍDO
// ═══════════════════════════════════════════════════════════════════════════

function escapaRegex(texto_) {
    return texto_.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function seletorDe(classe) {
    return `.${[...classe].map((ch) => (/[A-Za-z0-9_-]/.test(ch) ? ch : `\\${ch}`)).join('')}`;
}

const FONTES_DO_CSS = [REL_REPROVAR];

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

test('⚠️ FID: as classes do MOD-04 GERAM CSS com o valor certo 188_12', async (contexto) => {
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
        for (const isca of ['bg-pub-isca-188-12b', 'text-[89.5px]', 'w-[1974px]', 'top-[66.5%]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
        }
        assert.notEqual(regraDe('text-[12px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('⚠️⚠️ o VERMELHO e a geometria do MOD-04, com as OITO normalizações', () => {
        // ⚠️ `bg-pub-danger` é `#EF4444` = o `btnBg` ativo de `:1403`.
        assert.match(regraDe('bg-pub-danger'), /rgb\(239 68 68/, '`bg-pub-danger` não é #EF4444 (o submeter ativo de `:1403`)');
        assert.match(regraDe('text-pub-page'), /rgb\(10 10 10/, '`text-pub-page` não é #0A0A0A (o texto sobre o vermelho)');
        assert.match(regraDe('bg-pub-hover-2'), /rgb\(22 22 22/, '`bg-pub-hover-2` não é #161616 (o fundo da miniatura de `:816`)');
        assert.match(regraDe('text-pub-txt-off'), /rgb\(82 82 91/, '`text-pub-txt-off` não é #52525B (`:816`)');
        assert.match(regraDe('text-pub-txt-2'), /rgb\(161 161 170/, '`text-pub-txt-2` não é #A1A1AA (a nota de `:821`)');
        assert.match(regraDe('font-pub'), /Geist/, '`font-pub` deixou de resolver a Geist');

        const semRegra = [
            'rounded-[8px]', 'rounded-full', 'aspect-square', 'overflow-hidden', 'object-cover',
            'w-[120px]', 'h-full', 'w-full', 'h-[26px]', 'px-[9px]', 'px-[12px]', 'py-[10px]',
            'relative', 'grid', 'place-items-center', 'flex', 'flex-wrap', 'flex-col',
            'cursor-pointer', 'whitespace-nowrap', 'resize-y', 'outline-none',
            'text-[11.5px]', 'text-[12px]', 'text-[12.5px]', 'text-[13px]', 'leading-[1.45]',
        ].filter((c) => regraDe(c) === null);
        assert.deepEqual(semRegra, [], 'classe do MOD-04 NÃO gerou CSS — num `.js` isso aconteceria com TODO teste de render passando');
    });
});
