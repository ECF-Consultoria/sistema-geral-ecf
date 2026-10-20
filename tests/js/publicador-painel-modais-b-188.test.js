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
// Fase 188, plano 12, TAREFA 1 — MOD-03: "Informar frete do vendedor".
//
// ⚠️⚠️ DE CADA BLOCO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR: "o que
// precisaria estar errado no mundo para ele falhar?" — **"Nada" é ACHADO.**
//
//  1. ⚠️⚠️ ARITMÉTICA NO CLIENTE → a tela calculando taxa. Conta no cliente
//     divergiria da do servidor na primeira edição, num campo em que **confirmar
//     INCREMENTA A VERSÃO** e reabre a aprovação. ⚠️ **D8/FIN-02: as taxas do
//     README são FICTÍCIAS** e não entram como literal nem como config. ➡️ Gate de
//     ausência lendo **SEM comentário** (a prosa cita os números de propósito),
//     com **isca do recorte**.
//  2. ⚠️⚠️ AS TRÊS ORIGENS → **lista FECHADA do modelo**, por igualdade de STRING
//     e **NA ORDEM**, com o espelho conferido contra o PHP chave por chave. ⚠️ **A
//     CHAVE viaja no corpo, o RÓTULO fica na tela**: mandar a FRASE do protótipo dá
//     **422** (`REGRA_ORIGEM_DESCONHECIDA`), e há caso provando que o corpo leva a
//     CHAVE.
//  3. ⚠️⚠️ AS QUATRO CORES DA PRÉVIA → **quatro estados DISTINTOS** (`:1401`).
//     ⚠️ Duas iguais apagariam o sinal, e **D7 diz que cor nunca é o único
//     sinal**: o TEXTO é o sinal principal, e ele é medido junto.
//  4. ⚠️⚠️ `R$ 0,00` COM VALOR INVÁLIDO → **zero afirmaria que o frete é de
//     graça** (D4). ➡️ A prévia volta ao **texto NEUTRO**, e `0`/negativo/vazio/
//     texto/array/objeto caem nas **DUAS provas**: botão apagado **e** clique não
//     disparando.
//  5. SEGUNDA CASCA, `font-pub`, `disabled=` por atributo, ESCRITA, TELA PRETA,
//     ROLLUP, TERRITÓRIO e LISTAS — os mesmos invariantes dos gates do plano
//     188-11, recortados a ESTE `.jsx`.
//  6. ⚠️ COL-06 → `new Date()` no cliente. A nota do frete nomeia a FONTE e a DATA,
//     e a data é formatada no **SERVIDOR**: `Invalid Date` e o deslocamento de fuso
//     de data-only em BRT já morderam este projeto.
//
// ⚠️ Todos os nomes de teste terminam em `188_12`.
// ⚠️ `design_handoff_publicador/` **NÃO é versionado**: o bloco que depende dele
// degrada para `skip` EXPLÍCITO e NOMEADO.
// ⚠️⚠️ **TODOS os bundles são montados ANTES do primeiro `test()`.**
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

const STUB_INERTIA = path.join(__dirname, `.pub-freteB188-inertia-stub-${process.pid}.mjs`);

fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
globalThis.CHAMADAS_FRETE_188_12 = globalThis.CHAMADAS_FRETE_188_12 ?? [];
const CHAMADAS = globalThis.CHAMADAS_FRETE_188_12;
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
    on: (...a) => { CHAMADAS.push(['on', ...a]); return () => { CHAMADAS.push(['off']); }; },
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
        `.pub-freteB188-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';
const REL_FRETE = `${DIR_APROV}/ModalDeFrete.jsx`;
const REL_CASCA = `${DIR_APROV}/CascaDeModal.jsx`;
const REL_TOAST = `${DIR_APROV}/ToastDaFila.jsx`;
const REL_PAINEL = `${DIR_APROV}/PainelDeRevisao.jsx`;
const REL_KPIS = `${DIR_APROV}/KpisDoPainel.jsx`;
const REL_COMPOSICAO = `${DIR_APROV}/ComposicaoFinanceiraDoPainel.jsx`;
const REL_PAGINA = 'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx';
const REL_GATE_VOCABULARIO = 'tests/js/publicador-vocabulario-novo.test.js';
const REL_GATE_FECHAMENTO = 'tests/js/publicador-fila-fechamento-187.test.js';
const REL_GATE_ZINDEX = 'tests/js/publicador-header-zindex-ordem.test.js';
const REL_MODELO_FRETE = 'app/Models/PubAprovacaoFrete.php';
const REL_SERVICO_PRECO = 'app/Services/Publicador/Aprovacao/Painel/PrecoDoPainelService.php';
const REL_ROTAS = 'routes/mlb_anuncios.php';
const REL_MANIFESTO = 'tests/gates/contagens-188-12.txt';
const REL_PLANO = '.planning/phases/188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece/188-12-PLAN.md';
const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

const CAMINHOS_CITADOS = [
    REL_FRETE, REL_CASCA, REL_TOAST, REL_PAINEL, REL_KPIS, REL_COMPOSICAO, REL_PAGINA,
    REL_GATE_VOCABULARIO, REL_GATE_FECHAMENTO, REL_GATE_ZINDEX,
    REL_MODELO_FRETE, REL_SERVICO_PRECO, REL_ROTAS, REL_MANIFESTO, REL_PLANO,
];

const FONTE_FRETE = lerSemComentarios(REL_FRETE);
const CRUA_FRETE = fs.readFileSync(path.resolve(RAIZ, REL_FRETE), 'utf8');
const FONTE_PAGINA = lerSemComentarios(REL_PAGINA);

const FONTES_DO_PLANO = [[REL_FRETE, FONTE_FRETE]];

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
// ⚠️ As QUATRO prévias usam as QUATRO cores do servidor, e os quatro TEXTOS são
// diferentes: sem isso "quatro cores distintas" passaria com a tela pintando tudo
// igual e trocando só a frase (ou o contrário).

const PREVIA_NEUTRA = {
    preenchido: false, valor: null, texto: 'Informe o valor para ver o resultado',
    cor: 'neutro', resultado: null, margem: null, incompleto: false,
};

const PREVIA_PREJUIZO = {
    preenchido: true, valor: 90.0, texto: 'Resultado R$ -12,40 · margem -8,1%',
    cor: 'vermelho', resultado: 'R$ -12,40', margem: '-8,1%', incompleto: false,
};

const PREVIA_ABAIXO = {
    preenchido: true, valor: 40.0, texto: 'Resultado R$ 8,10 · margem 5,0%',
    cor: 'ambar', resultado: 'R$ 8,10', margem: '5,0%', incompleto: false,
};

const PREVIA_VERDE = {
    preenchido: true, valor: 12.0, texto: 'Resultado R$ 41,20 · margem 25,4%',
    cor: 'verde', resultado: 'R$ 41,20', margem: '25,4%', incompleto: false,
};

const MOD_FRETE = await montar(REL_FRETE);
const MOD_CASCA = await montar(REL_CASCA);

// ═══════════════════════════════════════════════════════════════════════════
// 0. ANTI-FANTASMA
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ ANTI-FANTASMA: todo caminho citado pelo gate do frete EXISTE no disco 188_12', () => {
    const fantasmas = CAMINHOS_CITADOS.filter((rel) => !fs.existsSync(path.resolve(RAIZ, rel)));
    assert.deepEqual(fantasmas, [], 'caminho fantasma citado por este gate — sonda de ausência sobre arquivo inexistente passa CALADA');
    assert.equal(fs.existsSync(path.resolve(RAIZ, 'app/Services/Publicador/Aprovacao/Fila/ResumoRapidoService.php')), false, 'o caminho fantasma MEDIDO pela wave 188-06 virou arquivo de verdade — o real é `app/Services/Publicador/Fila/ResumoRapidoService.php`');
});

// ═══════════════════════════════════════════════════════════════════════════
// 1. AS TRÊS ORIGENS — CHAVE no corpo, RÓTULO na tela, na ORDEM
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ MOD-03: as TRÊS origens são a lista FECHADA do modelo, na ORDEM 188_12', () => {
    const { ORIGENS_DO_FRETE, origemEscolhida } = MOD_FRETE;

    // (a) ⚠️ IGUALDADE DE LISTA, não `includes`: substring casa prefixo E sufixo de
    // outro nome, e já mordeu cinco vezes neste projeto.
    assert.deepEqual(
        ORIGENS_DO_FRETE,
        [
            { chave: 'cotacao_transportadora', rotulo: 'Cotação da transportadora' },
            { chave: 'tabela_cliente', rotulo: 'Tabela do cliente' },
            { chave: 'simulador', rotulo: 'Simulador de frete' },
        ],
        'as três origens, as chaves ou a ORDEM mudaram — se foi de propósito, remeça o espelho do PHP JUNTO',
    );

    // (b) ⚠️⚠️ O ESPELHO conferido contra o PHP, chave por chave e rótulo por
    // rótulo: dois lugares com a mesma lista divergem no primeiro ajuste.
    const php = lerSemComentarios(REL_MODELO_FRETE);
    for (const { chave, rotulo } of ORIGENS_DO_FRETE) {
        assert.ok(php.includes(`'${chave}'`), `a chave \`${chave}\` não existe em PubAprovacaoFrete — a tela mandaria algo que o servidor RECUSA`);
        assert.ok(php.includes(`'${rotulo}'`), `o rótulo "${rotulo}" não existe em PubAprovacaoFrete::ORIGENS — remeça o espelho`);
    }
    // ⚠️ E o PHP não tem uma QUARTA: lista FECHADA nas duas pontas.
    const bloco = php.match(/ORIGENS\s*=\s*\[([\s\S]*?)\];/);
    assert.ok(bloco, 'não achei o literal de `PubAprovacaoFrete::ORIGENS` — a leitura está cega');
    assert.equal((bloco[1].match(/=>/g) || []).length, 3, 'o modelo deixou de ter exatamente TRÊS origens — remeça a tela JUNTO');

    // (c) Os três RÓTULOS na tela, na ordem, e os três `value` com a CHAVE.
    const html = render(MOD_FRETE.CorpoDoFrete, { valor: '', origem: null, previa: PREVIA_NEUTRA, onValor: nada, onOrigem: nada });
    const opcoes = [...html.matchAll(/<option\b([^>]*)>([\s\S]*?)<\/option>/g)].map((m) => ({
        valor: (m[1].match(/value="([^"]*)"/) || [])[1] ?? '',
        rotulo: texto(m[2]),
    }));
    assert.deepEqual(
        opcoes,
        ORIGENS_DO_FRETE.map((o) => ({ valor: o.chave, rotulo: o.rotulo })),
        'o `<select>` de origem não traz as três chaves com os três rótulos, na ordem',
    );
    assert.ok(texto(html).includes('Origem do valor'), 'o rótulo de `:810` sumiu');

    // (d) ⚠️⚠️ O CORPO leva a CHAVE, nunca a FRASE: a frase do protótipo dá 422.
    const chamadas = [];
    const el = React.createElement(MOD_FRETE.default, {
        aberto: true, sub: 'Poltrona', valor: '18,50', origem: 'tabela_cliente', previa: PREVIA_VERDE,
        onValor: nada, onOrigem: nada, onFechar: nada, onSubmeter: (p) => chamadas.push(p),
    });
    el.type(el.props).props.onSubmeter();
    assert.deepEqual(chamadas, [{ valor: '18,50', origem: 'tabela_cliente' }], 'o corpo não levou a CHAVE da origem (ou mandou a FRASE, que dá 422)');

    // (e) Origem adversa cai na PRIMEIRA, nunca `undefined`.
    for (const adversa of ['Cotação da transportadora', 'cotacao', 'COTACAO_TRANSPORTADORA', 'prod', '', null, undefined, 42, {}, [], NaN, { toString: null }]) {
        assert.equal(origemEscolhida(adversa).chave, 'cotacao_transportadora', 'origem adversa não caiu na primeira');
    }
    assert.equal(origemEscolhida('simulador').chave, 'simulador', 'origem VÁLIDA deixou de ser respeitada — a sonda acima passaria com a função sempre devolvendo a primeira');
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. AS QUATRO CORES DA PRÉVIA, DISTINTAS — e o TEXTO como sinal principal
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ CRITÉRIO 14 / `:1401`: a prévia tem QUATRO estados DISTINTOS, com o TEXTO ao lado 188_12', () => {
    const corDa = (previa, valor) => {
        const html = render(MOD_FRETE.CorpoDoFrete, { valor, origem: 'simulador', previa, onValor: nada, onOrigem: nada });
        const spans = [...html.matchAll(/<span\b([^>]*)>([\s\S]*?)<\/span>/g)].map((m) => ({
            classe: (m[1].match(/class="([^"]*)"/) || [])[1] ?? '',
            rotulo: texto(m[2]),
        }));
        const alvo = spans.find((s) => s.rotulo === previa.texto);

        return { span: alvo, html };
    };

    const neutra = corDa(PREVIA_NEUTRA, '');
    const prejuizo = corDa(PREVIA_PREJUIZO, '90');
    const abaixo = corDa(PREVIA_ABAIXO, '40');
    const verde = corDa(PREVIA_VERDE, '12');

    // ⚠️ Com valor INVÁLIDO a prévia é o texto NEUTRO do servidor, e ele aparece.
    assert.ok(texto(neutra.html).includes(PREVIA_NEUTRA.texto), 'o texto neutro de `:1401` não apareceu com o campo vazio');

    // ⚠️ Os TRÊS preenchidos aparecem com o TEXTO do servidor — o sinal principal.
    for (const [nome, caso] of [['prejuízo', prejuizo], ['abaixo do mínimo', abaixo], ['acima', verde]]) {
        assert.ok(caso.span, `o texto da prévia de ${nome} não apareceu na tela`);
    }

    // ⚠️⚠️ As QUATRO cores são QUATRO valores DIFERENTES — duas iguais apagariam o
    // sinal. ⚠️ E a comparação é por CLASSE, não por "existe cor".
    const classeDa = (caso) => [...new Set(caso.span.classe.split(/\s+/).filter((c) => c.startsWith('text-pub')))].join(' ');
    const cores = [classeDa(neutra), classeDa(prejuizo), classeDa(abaixo), classeDa(verde)];
    assert.equal(new Set(cores).size, 4, `as quatro cores da prévia não são distintas: [${cores.join(' | ')}]`);
    assert.deepEqual(cores, ['text-pub-txt-4', 'text-pub-danger-txt', 'text-pub-warn-txt', 'text-pub-ok-txt'], 'as cores da prévia divergiram de `:1401` (neutro #71717A · prejuízo #F87171 · abaixo #FBBF24 · acima #4ADE80)');

    // ⚠️ E a COR vem do SERVIDOR: a tela não a recalcula. Zero decisão de cor por
    // número aqui.
    assert.equal(/resultado\s*<\s*0|margem\s*<\s*/.test(FONTE_FRETE), false, 'a tela passou a DECIDIR a cor da prévia — a ordem dos ramos é do servidor (`:1401`: prejuízo vence margem baixa)');
    assert.match(lerSemComentarios(REL_SERVICO_PRECO), /\$resultado < 0 => self::COR_VERMELHA/, 'o servidor parou de decidir a cor da prévia — é dele que a tela LÊ');
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. VALOR INVÁLIDO — AS DUAS PROVAS, E NUNCA `R$ 0,00`
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ T-188-B3 / `:1048`: valor inválido apaga o botão, não envia, e a prévia volta ao NEUTRO 188_12', () => {
    const { valorDoFrete } = MOD_FRETE;

    // (a) A decisão, numa casa só. ⚠️ `num()` só aceita **> 0**.
    for (const invalido of ['', '0', '0,00', '-5', '-0,01', '\u22125', '1.2.3', 'abc', 'R$', '   ', null, undefined, 0, -3, NaN, Infinity, {}, [], { toString: null }]) {
        assert.equal(valorDoFrete(invalido), null, 'valor inválido foi aceito');
    }
    // ⚠️⚠️ OS DOIS CASOS QUE A 1ª REDAÇÃO DA TELA ACEITAVA, e que o SERVIDOR
    // recusa — este gate os achou:
    //   · `'-5'`: o `replace` de não-dígitos **come o menos** e o negativo entrava
    //     como POSITIVO. `sanear():741` recusa o sinal ANTES de limpar, e o
    //     docblock dele chama isso de *"divergência (3)"* — o `num()` do protótipo
    //     (`:1048`) TEM esse defeito, e o produto o conserta;
    //   · `'1.2.3'`: `parseFloat` devolveria `1.2`, e o servidor recusa por
    //     `is_numeric` (`sanear():750`). A tela habilitaria o botão para um valor
    //     que volta **422**.
    // ⚠️ São duas leituras do MESMO campo, e a divergente é a que decide se o botão
    // acende. Há asserção lendo o PHP para travar as duas regras:
    const sanear = lerSemComentarios(REL_SERVICO_PRECO);
    assert.match(sanear, /str_contains\(\$bruto, '-'\)/, 'o servidor parou de recusar o SINAL antes de limpar — remeça a tela JUNTO');
    assert.match(sanear, /! is_numeric\(\$t\)/, 'o servidor parou de exigir string inteiramente numérica — remeça a tela JUNTO');
    // ⚠️ E os VÁLIDOS passam, com vírgula decimal e separador de milhar — é o que a
    // pessoa digita. Sem esta ponta a sonda acima passaria recusando tudo.
    assert.equal(valorDoFrete('18,50'), 18.5, 'a vírgula decimal foi recusada');
    assert.equal(valorDoFrete('1.234,56'), 1234.56, 'o separador de milhar foi recusado');
    assert.equal(valorDoFrete('7'), 7, 'o inteiro simples foi recusado');
    assert.equal(valorDoFrete(12.5), 12.5, 'o número foi recusado');

    const base = {
        aberto: true, sub: 'Poltrona', origem: 'simulador',
        onValor: nada, onOrigem: nada, onFechar: nada,
    };

    for (const invalido of ['', '0', '-5', 'abc', '   ']) {
        // ⚠️ PROVA 1: o botão sai `disabled=` e APAGADO. ⚠️ `disabled=` por
        // ATRIBUTO, e nunca `/disabled/`, que casa com `disabled:opacity-40`.
        const html = render(MOD_FRETE.default, { ...base, valor: invalido, previa: PREVIA_NEUTRA, onSubmeter: nada });
        const submeter = botoesDe(html).filter((b) => b.rotulo === 'Confirmar frete');
        assert.equal(submeter.length, 1, `não achei EXATAMENTE um botão "Confirmar frete" (achei ${submeter.length})`);
        assert.equal(submeter[0].desabilitado, true, `o botão não saiu \`disabled=\` com valor ${JSON.stringify(invalido)}`);
        assert.ok(submeter[0].classe.split(/\s+/).includes('bg-pub-seg'), 'o botão não saiu APAGADO');
        const cancelar = botoesDe(html).filter((b) => b.rotulo === 'Cancelar');
        assert.equal(cancelar.length, 1, 'o "Cancelar" da casca sumiu');
        assert.equal(cancelar[0].desabilitado, false, 'o "Cancelar" saiu desabilitado — a medição está no irmão errado');

        // ⚠️ PROVA 2: o clique NÃO chama a rota.
        const chamadas = [];
        const el = React.createElement(MOD_FRETE.default, { ...base, valor: invalido, previa: PREVIA_NEUTRA, onSubmeter: (p) => chamadas.push(p) });
        el.type(el.props).props.onSubmeter();
        assert.deepEqual(chamadas, [], `o clique com valor ${JSON.stringify(invalido)} chamou \`onSubmeter\``);

        // ⚠️⚠️ E a PRÉVIA VOLTA AO NEUTRO mesmo que o payload traga um resultado:
        // **zero afirmaria que o frete é de graça** (D4).
        const comResultadoVelho = render(MOD_FRETE.CorpoDoFrete, { valor: invalido, origem: 'simulador', previa: PREVIA_VERDE, onValor: nada, onOrigem: nada });
        assert.equal(texto(comResultadoVelho).includes(PREVIA_VERDE.texto), false, `com valor ${JSON.stringify(invalido)} a tela mostrou o resultado de um valor que não é o da tela`);
        assert.equal(/R\$ 0,00/.test(texto(comResultadoVelho)), false, 'apareceu "R$ 0,00" na prévia — zero afirmaria que o frete é de graça');
    }

    // ⚠️ ISCA: com valor VÁLIDO o botão habilita e o clique dispara.
    const htmlOk = render(MOD_FRETE.default, { ...base, valor: '18,50', previa: PREVIA_VERDE, onSubmeter: nada });
    const ativo = botoesDe(htmlOk).find((b) => b.rotulo === 'Confirmar frete');
    assert.equal(ativo.desabilitado, false, 'com valor válido o botão continuou desabilitado — a sonda está cega');
    assert.ok(ativo.classe.split(/\s+/).includes('bg-pub-accent'), 'com valor válido o botão não saiu ATIVO');
    assert.ok(texto(htmlOk).includes(PREVIA_VERDE.texto), 'com valor válido a prévia do servidor não apareceu');
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. ZERO ARITMÉTICA DE TAXA, E ZERO DATA NO CLIENTE
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ T-188-B1 / D8: ZERO aritmética de taxa e ZERO data no cliente, lido SEM comentário 188_12', () => {
    // ⚠️⚠️ A tela EXIBE; ela NÃO calcula. Conta no cliente divergiria da do
    // servidor na primeira edição — num campo em que **confirmar INCREMENTA A
    // VERSÃO** e reabre a aprovação.
    const VETADAS = [
        [/\*\s*0\.1[0-9]/, 'multiplicação por taxa'],
        [/\*\s*0\.0[0-9]/, 'multiplicação por taxa'],
        [/\/\s*\(\s*1\s*-/, 'divisão por (1 - taxa)'],
        [/\b6\.75\b/, 'a tarifa fixa fictícia do README'],
        [/\b0\.165\b/, 'a comissão fictícia do README'],
        [/\bMath\.round\(/, 'arredondamento no cliente'],
        [/\btoFixed\(/, 'formatação de número no cliente'],
        [/\btoLocaleString\(/, 'formatação de número no cliente'],
        [/\bIntl\.NumberFormat\b/, 'formatação de número no cliente'],
        [/new Date\(/, 'COL-06: a data é formatada no SERVIDOR'],
        [/'R\$ '/, 'dinheiro montado no cliente'],
    ];

    const infratores = [];
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        for (const [re, razao] of VETADAS) {
            if (re.test(fonte)) infratores.push(`${rel} ~ ${razao}`);
        }
    }
    assert.deepEqual(infratores, [], 'apareceu conta ou formatação na tela — ela EXIBE o que o servidor devolve');

    // ⚠️ ISCA DO RECORTE: cada regex casa o seu caso. Regex que não casa o próprio
    // caso é gate cego — e a PROSA deste arquivo cita os números de propósito, que
    // é exatamente por que a leitura é SEM COMENTÁRIO.
    const ISCAS = ['v * 0.16', 'v * 0.05', 'x / (1 - t)', '6.75', '0.165', 'Math.round(v)', 'v.toFixed(2)', 'v.toLocaleString()', 'new Intl.NumberFormat()', 'new Date()', "'R$ ' + v"];
    assert.equal(ISCAS.length, VETADAS.length, 'a lista de iscas e a de vetos divergiram');
    assert.deepEqual(VETADAS.filter(([re], i) => !re.test(ISCAS[i])).map(([, r]) => r), [], 'regex que não casa o próprio caso — gate cego');

    // ⚠️ E a prova de que a leitura SEM COMENTÁRIO é o que salva: a prosa CRUA
    // CITA os números, e a fonte sem comentário NÃO.
    assert.match(CRUA_FRETE, /6\.75|0\.12/, 'a prosa deste arquivo parou de citar os números — a isca da leitura sem comentário ficou vazia');
    assert.equal(/\b6\.75\b/.test(FONTE_FRETE), false, '`lerSemComentarios` deixou de tirar a prosa — o gate passaria a reprovar pelo próprio conserto');

    // ⚠️ `equilibrio` e `precoAlvo` NÃO são calculados na prévia (declarado no
    // servidor): número plausível e errado é pior que número ausente.
    for (const chave of ['equilibrio', 'precoAlvo', 'preco_alvo']) {
        assert.equal(FONTE_FRETE.includes(chave), false, `a tela passou a falar de \`${chave}\`, que a prévia NÃO calcula`);
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. A CASCA, O `font-pub`, A GEOMETRIA E A AUSÊNCIA DE ESCRITA
// ═══════════════════════════════════════════════════════════════════════════

const RE_LARGURA_DA_CASCA = /(^|[\s'"`])w-\[560px\]/;

test('⚠️⚠️ T-188-B7 / critério 15: casca IMPORTADA, `font-pub` no corpo, zero escrita 188_12', () => {
    assert.match(FONTE_FRETE, /import\s+CascaDeModal\s+from\s+'\.\/CascaDeModal'/, 'o modal de frete parou de IMPORTAR a casca da Fase 187');
    assert.match(FONTE_FRETE, /<CascaDeModal\b/, 'o modal de frete não MONTA a casca');

    const MARCAS = ['pt-[96px]', 'max-h-[calc(100vh-140px)]', 'shadow-pub-modal', 'fixed inset-0', 'CLASSES_BACKDROP', 'CLASSES_RODAPE', 'CLASSES_TOPO', 'z-[42]'];
    assert.deepEqual(MARCAS.filter((m) => FONTE_FRETE.includes(m)), [], 'o modal de frete reimplementou peça da casca');
    assert.equal(RE_LARGURA_DA_CASCA.test(FONTE_FRETE), false, 'o modal de frete declarou a largura de 560px — seria a SEGUNDA casca');
    // ⚠️ A âncora continua distinguindo o `max-w-[560px]` legítimo do toast da 187.
    assert.ok(lerSemComentarios(REL_TOAST).includes('max-w-[560px]'), 'o `max-w-[560px]` do toast sumiu — a armadilha mudou de lugar');
    assert.equal(RE_LARGURA_DA_CASCA.test('max-w-[560px]'), false, 'a âncora aceita sufixo');
    assert.equal(RE_LARGURA_DA_CASCA.test('flex w-[560px] flex-col'), true, 'a âncora rejeita a forma que ela DEVE pegar');

    for (const rel of [REL_CASCA, REL_TOAST, REL_GATE_ZINDEX, `${DIR_APROV}/vocabularioDoPortao.jsx`, `${DIR_APROV}/BarraDeMargem.jsx`]) {
        assert.equal(numstatDe(rel).trim(), '', `${rel} foi alterado — ele é INTOCÁVEL/em produção`);
    }
    assert.notEqual(numstatDe(REL_CASCA, ARVORE_VAZIA).trim(), '', 'a sonda de `numstat` está cega: nem contra a árvore vazia ela produz linha');

    // `font-pub` na RAIZ do corpo, com a RAZÃO no docblock.
    assert.match(FONTE_FRETE, /CLASSES_CORPO_DO_MODAL\s*=\s*'[^']*font-pub/, 'o corpo do modal não declara `font-pub`');
    const raiz = tagsDeAbertura(render(MOD_FRETE.CorpoDoFrete, { valor: '18,50', origem: 'simulador', previa: PREVIA_VERDE, onValor: nada, onOrigem: nada }))[0];
    assert.ok(classesDe(raiz).has('font-pub'), 'a RAIZ do corpo não tem `font-pub` — é no limite do portal que a herança quebra');
    assert.match(CRUA_FRETE, /conteúdo portado não herda fonte/i, 'o docblock parou de dizer POR QUE `font-pub` é declarado aqui');
    assert.equal(lerSemComentarios(REL_CASCA).includes('font-pub'), false, '`font-pub` entrou na casca da Fase 187');

    // O modal NÃO escreve.
    const ESCRITAS = [/router\s*\.\s*post\(/, /router\s*\.\s*put\(/, /router\s*\.\s*delete\(/, /axios\s*\.\s*(post|put|delete)\(/, /fetch\(/, /escreverNoPainel/];
    assert.deepEqual(ESCRITAS.filter((re) => re.test(FONTE_FRETE)), [], 'o modal escreve por conta própria — a escrita é do CHAMADOR, que é quem tem a versão');
    assert.equal(ESCRITAS[0].test('router . post(x)'), true, 'a sonda não tolera espaço');
    assert.ok(ESCRITAS.some((re) => re.test(lerSemComentarios(REL_PAINEL))), 'a sonda não acha a escrita nem no arquivo que TEM o helper');
});

test('⚠️ FID-01: a geometria do MOD-03, por CONJUNTO e índice ABSOLUTO 188_12', () => {
    const html = render(MOD_FRETE.CorpoDoFrete, { valor: '18,50', origem: 'simulador', previa: PREVIA_VERDE, onValor: nada, onOrigem: nada });
    const els = tagsDeAbertura(html);

    assert.deepEqual(
        els.map((e) => e.tag),
        ['div', 'label', 'span', 'span', 'input', 'label', 'select', 'option', 'option', 'option', 'span'],
        'a árvore do corpo do frete mudou de forma — remeça os índices ABSOLUTOS antes de confiar em qualquer medida',
    );

    // `:808` — o wrapper RELATIVO, o `R$` absoluto e o input.
    assert.deepEqual([...classesDe(els[2])].sort(), ['relative', 'flex', 'items-center'].sort(), 'o wrapper de `:808` perdeu o `position:relative` (e aí o `R$` ancoraria no lugar errado)');
    assert.deepEqual([...classesDe(els[3])].sort(), ['absolute', 'left-[12px]', 'text-[13px]', 'text-pub-txt-4'].sort(), 'o `R$` de `:808` divergiu');
    assert.deepEqual(
        [...classesDe(els[4])].sort(),
        ['bg-pub-sunken', 'border', 'border-pub-line-strong', 'focus:border-pub-accent', 'h-[38px]', 'outline-none', 'pl-[36px]', 'pr-[12px]', 'rounded-[8px]', 'text-[14px]', 'text-pub-txt', 'w-full'].sort(),
        'o input de `:808` divergiu',
    );
    // ⚠️ `pl-[36px]` é o que abre espaço para o `R$`. Sem ele o prefixo fica EM
    // CIMA do número, e **nenhum teste de texto pegaria isso**.
    assert.ok(classesDe(els[4]).has('pl-[36px]'), 'o input perdeu o `padding-left:36px` — o `R$` ficaria em cima do número');
    assert.ok(texto(html).includes('R$'), 'o prefixo `R$` de `:808` sumiu');
    assert.match(html, /placeholder="0,00"/, 'o placeholder `0,00` de `:808` sumiu');

    // `:811` — o select.
    assert.deepEqual(
        [...classesDe(els[6])].sort(),
        ['bg-pub-sunken', 'border', 'border-pub-line-strong', 'h-[36px]', 'outline-none', 'px-[10px]', 'rounded-[8px]', 'text-[13px]', 'text-pub-txt'].sort(),
        'o `<select>` de `:811` divergiu',
    );

    // `:813` — a prévia.
    assert.ok(classesDe(els[10]).has('text-[13px]'), 'a prévia de `:813` perdeu os 13px');
    assert.ok(classesDe(els[10]).has('font-semibold'), 'a prévia de `:813` perdeu o peso 600');

    // ⚠️ `h-[38px]` × `min-h-[38px]`: o segundo CONTÉM o primeiro.
    assert.equal(classesDe(els[4]).has('min-h-[38px]'), false, 'o input ganhou `min-h-[38px]`: a igualdade de CONJUNTO distingue, `includes` não');

    // ⚠️ E os literais, CONGELADOS no gate (nunca lidos do módulo testado).
    assert.equal(MOD_FRETE.TITULO, 'Informar frete do vendedor', 'o título mudou');
    assert.equal(MOD_FRETE.ROTULO_DO_BOTAO, 'Confirmar frete', 'o rótulo do botão mudou');
    assert.equal(MOD_FRETE.ROTULO_DO_VALOR, 'Frete pago pelo vendedor (por venda)', 'o rótulo do campo mudou');
    assert.equal(MOD_FRETE.ROTULO_DA_ORIGEM, 'Origem do valor', 'o rótulo da origem mudou');
    assert.equal(MOD_FRETE.PLACEHOLDER_DO_VALOR, '0,00', 'o placeholder mudou');
    assert.notEqual(MOD_FRETE.ROTULO_DO_VALOR, 'Frete pago pelo vendedor', 'a sonda aceita o prefixo — `includes` casa prefixo E sufixo');
    assert.notEqual(MOD_FRETE.ROTULO_DO_BOTAO, 'Confirmar', 'a sonda aceita o prefixo');
    assert.ok(texto(html).includes(MOD_FRETE.ROTULO_DO_VALOR), 'o rótulo do campo não está na tela');
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. A TELA PRETA, O ROLLUP E O TERRITÓRIO
// ═══════════════════════════════════════════════════════════════════════════

const FORMAS_ADVERSAS = [
    null, undefined, 0, 1, -1, NaN, Infinity, '', '   ', 'texto', true, false,
    {}, [], [{}], { toString: null }, { valueOf: null }, () => {},
];

const SEM_OBJETO_ESTRANHO = /\[object Object\]|NaN|undefined|Invalid Date/;

test('⚠️⚠️ REND / critério 19: nenhum campo adverso derruba o MOD-03, nem no CLIQUE 188_12', () => {
    const base = {
        aberto: true, sub: 'Poltrona', valor: '18,50', origem: 'simulador', previa: PREVIA_VERDE,
        onValor: nada, onOrigem: nada, onFechar: nada, onSubmeter: nada,
    };

    for (const campo of ['sub', 'valor', 'origem', 'previa']) {
        for (const forma of FORMAS_ADVERSAS) {
            let html = '';
            assert.doesNotThrow(() => { html = render(MOD_FRETE.default, { ...base, [campo]: forma }); }, `o campo \`${campo}\` derrubou o render`);
            assert.doesNotMatch(html, SEM_OBJETO_ESTRANHO, `o campo \`${campo}\` vazou para a tela`);
        }
    }

    // Cada campo da PRÉVIA, um a um.
    for (const campo of ['preenchido', 'valor', 'texto', 'cor', 'resultado', 'margem', 'incompleto']) {
        for (const forma of FORMAS_ADVERSAS) {
            let html = '';
            assert.doesNotThrow(() => { html = render(MOD_FRETE.default, { ...base, previa: { ...PREVIA_VERDE, [campo]: forma } }); }, `o campo \`previa.${campo}\` derrubou o render`);
            assert.doesNotMatch(html, SEM_OBJETO_ESTRANHO, `o campo \`previa.${campo}\` vazou para a tela`);
        }
    }

    // A prop inteira e os handlers.
    for (const forma of FORMAS_ADVERSAS) {
        assert.doesNotThrow(() => render(MOD_FRETE.default, {
            aberto: forma, sub: forma, valor: forma, origem: forma, previa: forma,
            onValor: forma, onOrigem: forma, onFechar: forma, onSubmeter: forma,
        }), 'a prop inteira adversa derrubou o render');
        assert.doesNotThrow(() => render(MOD_FRETE.CorpoDoFrete, { valor: forma, origem: forma, previa: forma, onValor: forma, onOrigem: forma }), 'a prop inteira adversa derrubou o corpo');
    }

    // ⚠️⚠️ E TAMBÉM NO CLIQUE: `renderToStaticMarkup` **não dispara `onClick`**.
    for (const forma of FORMAS_ADVERSAS) {
        assert.doesNotThrow(() => {
            const el = React.createElement(MOD_FRETE.default, { ...base, valor: forma, origem: forma, onSubmeter: nada });
            const casca = el.type(el.props);
            if (casca !== null && typeof casca.props.onSubmeter === 'function') casca.props.onSubmeter();
        }, 'o CLIQUE com campo adverso derrubou a página');

        // Os dois `onChange`, com evento adverso.
        const el2 = React.createElement(MOD_FRETE.CorpoDoFrete, { valor: '1', origem: 'simulador', previa: PREVIA_VERDE, onValor: nada, onOrigem: nada });
        const arvore = el2.type(el2.props);
        assert.doesNotThrow(() => {
            const rotuloValor = arvore.props.children[0];
            rotuloValor.props.children[1].props.children[1].props.onChange({ target: { value: forma } });
            arvore.props.children[1].props.children[1].props.onChange({ target: { value: forma } });
        }, 'o `onChange` com valor adverso derrubou a página');
    }

    // O filho OBJETO COMUM passando pela casca.
    assert.doesNotThrow(() => renderToStaticMarkup(React.createElement(MOD_CASCA.default, {
        aberto: true, titulo: 'x', rotuloDoBotao: 'y', onFechar: nada, onSubmeter: nada,
        children: [{}, React.createElement(MOD_FRETE.CorpoDoFrete, { valor: '1', origem: 'simulador', previa: PREVIA_VERDE, onValor: nada, onOrigem: nada })],
    })), 'objeto comum no meio dos filhos derrubou a casca');
});

test('⚠️ ROLLUP e TERRITÓRIO: o recorte CONSTANTE deste plano, com isca do recorte 188_12', () => {
    // O bundle DE VERDADE renderiza os três `<option>`.
    const html = render(MOD_FRETE.CorpoDoFrete, { valor: '1', origem: 'simulador', previa: PREVIA_VERDE, onValor: nada, onOrigem: nada });
    assert.equal((html.match(/<option/g) || []).length, 3, 'o bundle não renderizou os três `<option>`');

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
    ];
    assert.equal(ISCAS.length, VETADOS.length, 'a lista de iscas e a de vetos divergiram');
    assert.deepEqual(VETADOS.filter(([re], i) => !re.test(ISCAS[i])).map(([, r]) => r), [], 'regex que não casa o próprio caso — gate cego');
    assert.deepEqual(FONTES_DO_PLANO.map(([rel]) => rel), [REL_FRETE], 'o recorte deste gate deixou de ser a lista CONSTANTE desta tarefa');
    assert.ok(FONTES_DO_PLANO.every(([rel]) => !rel.startsWith('resources/') || rel.endsWith('.jsx')), 'peça nova sob `resources/` saiu como `.js`');

    for (const rel of ['package.json', 'package-lock.json']) {
        assert.equal(numstatDe(rel).trim(), '', `${rel} foi alterado — este plano NÃO instala nada`);
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. A FIAÇÃO: a prévia vem do SERVIDOR pelo FLASH, e o botão do frete foi RELIGADO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ B2: o "Informar frete confirmado" foi RELIGADO, e a prévia vem do FLASH 188_12', () => {
    // ⚠️ O botão é desenhado por `ComposicaoFinanceiraDoPainel` (plano 188-09) e o
    // handler nasce AQUI: sem ele o critério 14 ficava inalcançável.
    assert.match(FONTE_PAGINA, /onInformarFrete=\{abrirModalDoPainel === null \? null :/, 'a página não religou o `onInformarFrete` com a guarda — sem empresa/oferta/versão ele TEM de sair `null` (D4)');
    assert.match(FONTE_PAGINA, /MODAL_FRETE/, 'a página não usa a chave `MODAL_FRETE` do servidor');
    assert.match(FONTE_PAGINA, /<ModalDeFrete/, 'a página não monta o `ModalDeFrete`');

    // ⚠️⚠️ A PRÉVIA chega pelo FLASH, não por `axios`: as actions são DUAL-PATH e
    // pelo Inertia o ramo é o `back()->with(...)`.
    assert.match(FONTE_PAGINA, /painel_frete_previa/, 'a página parou de ler a prévia do FLASH');
    assert.match(lerSemComentarios(REL_SERVICO_PRECO), /PREVIA_SEM_VALOR = 'Informe o valor para ver o resultado'/, 'o texto neutro saiu do servidor — é dele que a tela lê');
    assert.match(fs.readFileSync(path.resolve(RAIZ, 'app/Http/Controllers/MlbPublicadorAprovacaoController.php'), 'utf8'), /back\(\)->with\('painel_frete_previa', \$previa\)/, 'a action da prévia parou de flashar o resultado — a tela leria o nada');

    // ⚠️ E ZERO `axios`/`fetch` na página para isto.
    assert.equal(/axios\s*\.\s*(post|get)\(/.test(FONTE_PAGINA), false, 'a página passou a usar `axios` — a prévia vem pelo FLASH, e o transporte é UM só');

    // ⚠️ A rota existe (`test -e` já cobriu o arquivo; aqui é o NOME).
    const rotas = fs.readFileSync(path.resolve(RAIZ, REL_ROTAS), 'utf8');
    for (const nome of ['painel.frete.previa', 'painel.frete']) {
        assert.ok(rotas.includes(`name('meus.aprovacao.${nome}')`), `a rota \`${nome}\` não existe — o handler apontaria para o nada`);
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. AS LISTAS E O MANIFESTO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ VOC-07: os TRÊS `.jsx` deste plano nas DUAS listas, e o manifesto 188_12', () => {
    const DO_PLANO = ['ModalDeFrete.jsx', 'ModalDeReprovarImagem.jsx', 'AvisoDeVersaoMudou.jsx'];

    const bloco = lerSemComentarios(REL_GATE_VOCABULARIO).match(/const\s+ARQUIVOS_NOVOS\s*=\s*\[([\s\S]*?)\n\];/);
    assert.ok(bloco, 'não achei o literal de `ARQUIVOS_NOVOS` — a leitura está cega');
    const declarados = [...bloco[1].matchAll(/\$\{DIR_APROV\}\/([\w.-]+\.jsx)/g)].map((m) => m[1]);
    assert.ok(declarados.length > 0, '`ARQUIVOS_NOVOS` saiu vazia — a leitura está cega');
    assert.deepEqual(DO_PLANO.filter((n) => !declarados.includes(n)), [], '`.jsx` deste plano ficou FORA de `ARQUIVOS_NOVOS` — o GATE-03 passaria por OMISSÃO');

    const bloco2 = lerSemComentarios(REL_GATE_FECHAMENTO).match(/const\s+JSX_DEPOIS_DA_187\s*=\s*\[([\s\S]*?)\n\];/);
    assert.ok(bloco2, 'não achei o literal de `JSX_DEPOIS_DA_187` — a leitura está cega');
    const depois = [...bloco2[1].matchAll(/\$\{DIR_APROV\}\/([\w.-]+\.jsx)/g)].map((m) => m[1]);
    assert.ok(depois.length > 0, '`JSX_DEPOIS_DA_187` saiu vazia — a leitura está cega');
    assert.deepEqual(DO_PLANO.filter((n) => !depois.includes(n)), [], '`.jsx` deste plano ficou FORA de `JSX_DEPOIS_DA_187`');

    const manifesto = fs.readFileSync(path.resolve(RAIZ, REL_MANIFESTO), 'utf8');
    const linhas = manifesto.split(/\r?\n/).filter((l) => l.trim() !== '' && !l.trim().startsWith('#'));
    assert.ok(linhas.length > 0, 'o manifesto não tem linha de gate — a leitura está cega');
    const esteArquivo = 'tests/js/publicador-painel-modais-b-188.test.js';
    const minhas = linhas.filter((l) => l.includes(esteArquivo));
    assert.equal(minhas.length, 1, `o manifesto declara ${minhas.length} ids para ${esteArquivo} — é UM id por arquivo de gate`);
    assert.match(minhas[0], /^modal-de-frete\s+js\s+/, 'o id deste arquivo deixou de ser `modal-de-frete`');

    // ⚠️ Um id por arquivo, e nenhum caminho de gate fantasma.
    const porArquivo = new Map();
    for (const linha of linhas) {
        for (const arquivo of linha.trim().split('::')[0].trim().split(/\s+/).slice(2)) {
            porArquivo.set(arquivo, (porArquivo.get(arquivo) ?? 0) + 1);
        }
    }
    assert.deepEqual([...porArquivo].filter(([, n]) => n > 1), [], 'arquivo de gate com MAIS DE UM id — a mesma contagem sairia duas vezes');
    assert.deepEqual([...porArquivo.keys()].filter((rel) => !fs.existsSync(path.resolve(RAIZ, rel))), [], 'o manifesto aponta para arquivo de gate que não existe');

    // ⚠️ BIJEÇÃO id ↔ `<verify>`, nas DUAS direções, com o EXPANSOR DE ALTERNÂNCIA.
    const plano = fs.readFileSync(path.resolve(RAIZ, REL_PLANO), 'utf8');
    const ids = linhas.map((l) => l.trim().split(/\s+/)[0]);
    assert.equal(new Set(ids).size, ids.length, 'id REPETIDO no manifesto — o harness pega o PRIMEIRO');
    const doVerify = [...plano.matchAll(/verificar-testes\.sh\s+188-12\s+([\w-]+)/g)].map((m) => m[1]);
    assert.ok(doVerify.length > 0, 'não achei `<verify>` com `188-12` no plano — a leitura está cega');
    assert.deepEqual(ids.filter((id) => !doVerify.includes(id)), [], 'id declarado no manifesto que NENHUM `<verify>` roda — ele não falha: simplesmente nunca roda');
    assert.deepEqual(doVerify.filter((id) => !ids.includes(id)), [], 'id usado por um `<verify>` e não declarado no manifesto — o harness ABORTA');
    const EXPANSOR = /test_(?:\([A-Za-z0-9_|]+\)|[A-Za-z0-9_]+)/;
    assert.equal(EXPANSOR.test('test_(a|b)_188_12'), true, 'o expansor de alternância não casa a forma `test_(a|b)` — é o furo MEDIDO pela wave 188-05');
    assert.equal(EXPANSOR.test('test_simples_188_12'), true, 'o expansor deixou de casar a forma simples');

    const nomes = [...fs.readFileSync(path.resolve(RAIZ, esteArquivo), 'utf8').matchAll(/^test\('([^']*)'/gm)].map((m) => m[1]);
    assert.ok(nomes.length > 0, 'não achei os nomes de teste — a leitura está cega');
    assert.deepEqual(nomes.filter((n) => !n.endsWith('188_12')), [], 'nome de teste sem o sufixo `188_12`');

    assert.deepEqual(linhas.filter((l) => /\s+php\s+/.test(l)), [], 'entrou id `php` no manifesto — agora a conferência de homônimo por script é obrigatória');

    const tamanhos = linhas.filter((l) => l.startsWith('vocabulario-tamanhos'));
    assert.equal(tamanhos.length, 1, '`vocabulario-tamanhos` não está em linha própria — sem a linha o harness ABORTA');
    assert.match(tamanhos[0], /tests=>=\d+/, 'a forma de `vocabulario-tamanhos` deixou de ser `>=`');
    assert.deepEqual(tamanhos[0].split('::')[0].trim().split(/\s+/).slice(2), [REL_GATE_VOCABULARIO], 'o id `vocabulario-tamanhos` passou a rodar OUTROS arquivos');
});

// ═══════════════════════════════════════════════════════════════════════════
// 9. O PROTÓTIPO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ FID-01: os ponteiros de `:806`-`:813` e de `:1400`/`:1401`, por LEITURA DA LINHA 188_12', async (contexto) => {
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

    await contexto.test('⚠️ o HTML do MOD-03: `:807`/`:808` (não `:805`/`:806`), `:810`/`:811`, `:813`', () => {
        // ⚠️ A §F.3 já corrigia: `:805` é um `</sc-if>` e `:806` é o `<sc-if>` do MOD-03.
        assert.match(em(805), /<\/sc-if>/, '`:805` deixou de ser o fechamento do MOD-02');
        assert.match(em(806), /isFreight/, '`:806` não é o `<sc-if>` do MOD-03');
        assert.match(em(807), />Frete pago pelo vendedor \(por venda\)$/, '`:807` não é o `<label>` do valor');
        assert.match(em(808), /position:relative;display:flex;align-items:center/, '`:808` não é o wrapper relativo');
        assert.match(em(808), /position:absolute;left:12px/, '`:808` perdeu o `R$` absoluto');
        assert.match(em(808), /padding:0 12px 0 36px/, '`:808` perdeu o `padding-left:36px` do input');
        assert.match(em(808), /border-color:#FACC15/, '`:808` perdeu o `style-focus`');
        assert.match(em(810), />Origem do valor$/, '`:810` não é o `<label>` da origem');
        assert.match(em(811), /<select/, '`:811` não é o `<select>` da origem');
        assert.match(em(813), /M\.previewColor/, '`:813` não é a PRÉVIA');
    });

    await contexto.test('⚠️⚠️ as 3 origens e o `previewColor` estão em `:1401`, NÃO em `:1402`/`:1403`', () => {
        // ⚠️ A §F.3 aponta `:1403` para as origens e `:1402` para o `previewColor`.
        // A wave 188-06 mediu `:1401` para os dois, e esta wave CONFIRMA.
        assert.match(em(1400), /if \(k === 'freight'\)/, '`:1400` deixou de ser a abertura do ramo `freight`');
        assert.match(em(1400), /this\.fin\(Object\.assign\(\{\}, o, \{ freight: v \}\), o\.price\)/, '`:1400` não tem a prévia com o frete SUBSTITUÍDO');
        assert.match(em(1401), /srcs: \['Cotação da transportadora', 'Tabela do cliente', 'Simulador de frete'\]/, '`:1401` não tem as TRÊS origens');
        assert.match(em(1401), /previewColor: prev \?/, '`:1401` não tem o `previewColor`');
        assert.match(em(1401), /'Informe o valor para ver o resultado'/, '`:1401` não tem o texto NEUTRO');
        assert.match(em(1402), /if \(k === 'reject'\)/, '`:1402` não é a abertura do ramo `reject` — é ELE que a §F.3 confundia com o `previewColor`');
        assert.match(em(1403), /title: 'Reprovar imagem'/, '`:1403` não é o `return` do ramo `reject`');
        assert.match(em(1395), /freight: 'Confirmar frete'/, '`:1395` não tem o rótulo do botão');
        assert.match(em(1204), /submitFreight\(\)/, '`:1204` não é o `submitFreight`');
        assert.match(em(1204), /x\.version\+\+/, '`:1204` perdeu o INCREMENTO DA VERSÃO (critério 14)');
        assert.match(em(1048), /v > 0 \? v : null/, '`:1048` perdeu o `num()` que só aceita > 0');
    });

    await contexto.test('⚠️ `cursor` / `white-space` / `text-overflow`, em COLUNAS PRÓPRIAS', () => {
        const faixa = [806, 807, 808, 810, 811, 813];
        const comCursor = faixa.filter((n) => /cursor:pointer/.test(em(n)));
        const comNowrap = faixa.filter((n) => /white-space:nowrap/.test(em(n)));
        const comEllipsis = faixa.filter((n) => /text-overflow:ellipsis/.test(em(n)));
        assert.deepEqual(comCursor, [], `\`cursor:pointer\` apareceu em [${comCursor.join(', ')}] — nenhum dos seis elementos do MOD-03 o tem`);
        assert.deepEqual(comNowrap, [], `\`white-space:nowrap\` apareceu em [${comNowrap.join(', ')}] — nenhum dos seis o tem`);
        assert.deepEqual(comEllipsis, [], '`text-overflow:ellipsis` apareceu no MOD-03');
        // ⚠️ ISCA: a varredura ACHA as duas onde elas EXISTEM, senão as três listas
        // vazias seriam vazias por CONSTRUÇÃO.
        assert.match(em(779), /cursor:pointer/, 'a varredura está cega: nem no × de `:779` ela acha `cursor:pointer`');
        assert.match(em(890), /white-space:nowrap/, 'a varredura está cega: nem no submeter de `:890` ela acha `white-space:nowrap`');
    });

    await contexto.test('⚠️ CRUZAMENTO: os literais do MÓDULO aparecem no protótipo', () => {
        assert.ok(em(807).includes(MOD_FRETE.ROTULO_DO_VALOR), 'o rótulo do valor não casa `:807`');
        assert.ok(em(808).includes(MOD_FRETE.PLACEHOLDER_DO_VALOR), 'o placeholder não casa `:808`');
        assert.ok(em(810).includes(MOD_FRETE.ROTULO_DA_ORIGEM), 'o rótulo da origem não casa `:810`');
        assert.ok(em(1395).includes(MOD_FRETE.ROTULO_DO_BOTAO), 'o rótulo do botão não casa `:1395`');
        assert.ok(em(1401).includes(MOD_FRETE.TITULO), 'o título não casa `:1401`');
        for (const { rotulo } of MOD_FRETE.ORIGENS_DO_FRETE) {
            assert.ok(em(1401).includes(rotulo), `a origem "${rotulo}" não casa \`:1401\``);
        }
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

const FONTES_DO_CSS = [REL_FRETE];

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

test('⚠️ FID: as classes do MOD-03 GERAM CSS com o valor certo 188_12', async (contexto) => {
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
        assert.equal(tem('resources/js/Pages/Mlb/NaoExiste6c1.jsx'), false, 'a isca do manifest virou entrada de verdade');
    });

    await contexto.test('⚠️ ISCA: a sonda reporta AUSENTE o que não existe, e ACHA o que existe', () => {
        for (const isca of ['bg-pub-isca-188-12', 'text-[91.5px]', 'w-[1976px]', 'top-[64.5%]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS.css.includes(isca), `a string da isca ${isca} aparece no CSS`);
        }
        assert.notEqual(regraDe('text-[14px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('⚠️⚠️ `duration-[Nms]` continua NÃO COMPILANDO, e `duration-200` compila', () => {
        assert.equal(regraDe('duration-[180ms]'), null, '`duration-[180ms]` passou a compilar — remeça a regra');
        assert.match(regraDe('duration-200'), /transition-duration:\s*\.?2s/, 'o CONTROLE da escala nomeada sumiu');
    });

    await contexto.test('⚠️⚠️ a GEOMETRIA do MOD-03, com as OITO normalizações', () => {
        assert.match(regraDe('bg-pub-sunken'), /rgb\(13 13 13/, '`bg-pub-sunken` não é #0D0D0D');
        assert.match(regraDe('border-pub-line-strong'), /rgb\(46 46 46/, '`border-pub-line-strong` não é #2E2E2E');
        assert.match(regraDe('text-pub-txt-4'), /rgb\(113 113 122/, '`text-pub-txt-4` não é #71717A (o `R$` e a prévia NEUTRA)');
        assert.match(regraDe('text-pub-danger-txt'), /rgb\(248 113 113/, '`text-pub-danger-txt` não é #F87171 (a prévia de prejuízo)');
        assert.match(regraDe('text-pub-warn-txt'), /rgb\(251 191 36/, '`text-pub-warn-txt` não é #FBBF24 (a prévia abaixo do mínimo)');
        assert.match(regraDe('text-pub-ok-txt'), /rgb\(74 222 128/, '`text-pub-ok-txt` não é #4ADE80 (a prévia acima)');
        assert.match(regraDe('font-pub'), /Geist/, '`font-pub` deixou de resolver a Geist — o modal cairia em Inter no portal');

        // ⚠️ `focus:` é VARIANTE: na variante o prefixo faz parte do nome do seletor.
        assert.match(regraDe('focus:border-pub-accent'), /rgb\(250 204 21/, 'o `style-focus` de `:808` não compilou (ou não é #FACC15)');

        const semRegra = [
            'rounded-[8px]', 'relative', 'absolute', 'left-[12px]', 'w-full',
            'h-[38px]', 'h-[36px]', 'pl-[36px]', 'pr-[12px]', 'px-[10px]',
            'flex', 'flex-col', 'items-center', 'border', 'outline-none', 'font-semibold',
            'gap-[6px]', 'gap-[14px]', 'text-[12.5px]', 'text-[13px]', 'text-[14px]',
        ].filter((c) => regraDe(c) === null);
        assert.deepEqual(semRegra, [], 'classe do MOD-03 NÃO gerou CSS — num `.js` isso aconteceria com TODO teste de render passando');
    });
});
