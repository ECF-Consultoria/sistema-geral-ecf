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
// Fase 188, plano 09 — A COLUNA DE PREÇO E RESULTADO DO PAINEL: os 3 KPIs com a
// barra da margem, o aviso de alteração não conferida, a composição com SELO DE
// ORIGEM por linha, a simulação que NUNCA é salva e a comparação com a irmã.
//
// ⚠️⚠️ DE CADA BLOCO, A PERGUNTA RESPONDIDA ANTES DE ELE EXISTIR: "o que
// precisaria estar errado no mundo para ele falhar?" — **"Nada" é ACHADO.**
//
//  1. VOCABULÁRIO → "lucro líquido" na tela. ⚠️ O recorte é a lista CONSTANTE
//                   dos `.jsx` DESTE plano: `lucro_liquido` é campo **legítimo**
//                   do Portal (8 arquivos o usam), e varrer largo BARROU UM
//                   DEPLOY na Fase 186. ⚠️ E a leitura é SEM COMENTÁRIO, porque
//                   o docblock que PROÍBE a palavra a contém — é a regra 2 da
//                   §6, com TRÊS mutações abortadas por ela na wave 187-04.
//  2. DECISÃO 9   → a margem sem dizer de onde vem. A frase
//                   `· com a tarifa real do Mercado Livre` chega FECHADA de
//                   `ComposicaoDaOferta:271` (medido por `sed`), e deixar a
//                   margem sem texto foi **RECUSADO** pelo usuário. ⚠️ A tela
//                   LÊ; remontar a frase aqui APAGARIA a decisão.
//  3. BARRA       → cravar a posição do traço. O `left:30%` de `:645` existe
//                   **porque `MIN = 12`** (`:914`) e `12/40 = 30%`. ⚠️ Só se
//                   prova com DOIS mínimos: com 12 o traço dá 30% e com 20 dá
//                   50%. Um mínimo só passaria com o literal cravado.
//  4. `style`     → largura/posição por CLASSE montada por template literal. O
//                   scanner do Tailwind **não vê** classe concatenada: ela fica
//                   no HTML, todo render passa, e **a barra não desenha**.
//                   ⚠️⚠️ A sonda é na FONTE SEM COMENTÁRIO — no CSS construído o
//                   próprio comentário que proíbe a classe **a faz existir**
//                   (medido na wave 188-08: o CSS encolheu 7 bytes quando o
//                   exemplo do docblock foi neutralizado).
//  5. TRÊS CORES  → duas das três cores do resultado iguais. Um teste de
//                   EXISTÊNCIA passaria; o que distingue é a DIFERENÇA aos pares.
//                   ⚠️ E D7: cada cor tem TEXTO ao lado — cor nunca é o único
//                   sinal.
//  6. ZERO        → custo/frete ausente virando `R$ 0,00`. Zero AFIRMARIA que é
//                   de graça; o certo é `Incompleto` e `—` (FIN-05/FIN-06, D4).
//  7. ORDEM       → a tela reordenar os KPIs. `KPIS_EM_ORDEM` é o contrato do
//                   servidor; uma segunda ordenação aqui seria a terceira lista
//                   do mesmo fato.
//  8. DIVISÓRIA   → `border-left` na célula 1 (ou faltando na 2/3). A divisória é
//                   POSIÇÃO, não propriedade do KPI.
//  9. BOTÃO       → "Conferir alteração" desenhado SEM destino. ⚠️ A rota é da
//                   wave **188-06** e **NÃO EXISTE** no HEAD de partida — medido
//                   em `routes/mlb_anuncios.php`. Botão sem destino é D4
//                   violado, e `onClick` vazio é a mesma dívida com outra roupa.
// 10. ADVERSO     → qualquer campo chegando objeto/`NaN`/array/nulo, a prop
//                   inteira nula, **e no CLIQUE**: a TELA PRETA voltou SEIS vezes
//                   neste projeto e a última foi NO CLIQUE.
//                   `renderToStaticMarkup` **não dispara `onClick`**.
// 11. FASES       → "⤢ Ampliar" (189) e "✦ Gerar novamente com IA" (192)
//                   antecipadas; pendências/rodapé (188-10) ou modais (188-11/12)
//                   aparecendo como provisório.
// 12. TERRITÓRIO  → `localStorage`, `String(`, peça nova em `.js`, hex cru, e os
//                   arquivos INTOCÁVEIS tocados.
//
// ⚠️ Todos os nomes de teste terminam em `188_09`: nome genérico COLIDE no
// `--filter`, que **SOMA** as contagens. (Este plano não tem id `php`, então o
// `--filter` não roda sobre ele — a régua do sufixo vale para o dia em que
// rodar.)
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
const STUB_INERTIA = path.join(__dirname, `.pub-preco188-inertia-stub-${process.pid}.mjs`);

// ⚠️⚠️ O `router` do stub **GRAVA** as chamadas. ⚠️ E o gravador vive no
// `globalThis`: o `montar()` usa `bundle: true`, então o esbuild **INLINA o stub
// dentro do bundle** — o array de dentro do bundle e o de um `import()` do stub
// seriam DOIS objetos diferentes, e o gate leria um array sempre vazio (conserto
// MEDIDO na wave 188-07).
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
globalThis.CHAMADAS_PRECO_188_09 = globalThis.CHAMADAS_PRECO_188_09 ?? [];
const CHAMADAS = globalThis.CHAMADAS_PRECO_188_09;
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

// ⚠️ `route` é o global que o Ziggy injeta no navegador — mesma forma das waves
// 187-05/06 e 188-07/08.
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
        `.pub-preco188-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';
const REL_KPIS = `${DIR_APROV}/KpisDoPainel.jsx`;
const REL_COMPOSICAO = `${DIR_APROV}/ComposicaoFinanceiraDoPainel.jsx`;
const REL_SIMULACAO = `${DIR_APROV}/SimulacaoDePreco.jsx`;
const REL_IRMA = `${DIR_APROV}/ComparacaoComAIrma.jsx`;
const REL_COLUNA = `${DIR_APROV}/ColunaDePreco.jsx`;
const REL_PAINEL = `${DIR_APROV}/PainelDeRevisao.jsx`;
const REL_BARRA_DA_FILA = `${DIR_APROV}/BarraDeMargem.jsx`;
const REL_VOCABULARIO = `${DIR_APROV}/vocabularioDoPortao.jsx`;
const REL_IMAGEM = `${DIR_APROV}/ImagemGrandeDoPainel.jsx`;
const REL_PAGINA = 'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx';
const REL_SERVICO_PRECO = 'app/Services/Publicador/Aprovacao/Painel/PrecoDoPainelService.php';
const REL_SERVICO_PAINEL = 'app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php';
const REL_DTO = 'app/Services/Publicador/Aprovacao/ComposicaoDaOferta.php';
const REL_LINHA_FINANCEIRA = 'app/Services/Publicador/Aprovacao/LinhaFinanceira.php';
const REL_RESUMO_RAPIDO = 'app/Services/Publicador/Fila/ResumoRapidoService.php';
const REL_ROTAS = 'routes/mlb_anuncios.php';
const REL_GATE_VOCABULARIO = 'tests/js/publicador-vocabulario-novo.test.js';
const REL_GATE_FECHAMENTO = 'tests/js/publicador-fila-fechamento-187.test.js';
const REL_GATE_ZINDEX = 'tests/js/publicador-header-zindex-ordem.test.js';
const REL_MANIFESTO = 'tests/gates/contagens-188-09.txt';
const REL_ANDAMENTO = `${DIR_APROV}/andamentoDoPortao.jsx`;
const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

// ⚠️⚠️ ANTI-FANTASMA — `test -e` em CADA caminho citado. São NOVE caminhos
// fantasmas nesta milestone, TRÊS deles vindos de briefing do orquestrador.
// Sonda de AUSÊNCIA sobre arquivo inexistente passa CALADA.
const CAMINHOS_CITADOS = [
    REL_KPIS, REL_COMPOSICAO, REL_SIMULACAO, REL_IRMA, REL_COLUNA, REL_PAINEL, REL_BARRA_DA_FILA,
    REL_VOCABULARIO, REL_IMAGEM, REL_ANDAMENTO,
    REL_PAGINA, REL_SERVICO_PRECO, REL_SERVICO_PAINEL, REL_DTO, REL_LINHA_FINANCEIRA,
    REL_RESUMO_RAPIDO, REL_ROTAS, REL_GATE_VOCABULARIO, REL_GATE_FECHAMENTO,
    REL_GATE_ZINDEX, REL_MANIFESTO,
];

// ⚠️ As fontes SEM comentário: a prosa em pt-BR deste projeto cita os próprios
// identificadores e os próprios literais, e **palavra no docblock reprova pelo
// próprio conserto**.
const FONTE_KPIS = lerSemComentarios(REL_KPIS);
const FONTE_COMPOSICAO = lerSemComentarios(REL_COMPOSICAO);
const FONTE_SIMULACAO = lerSemComentarios(REL_SIMULACAO);
const FONTE_IRMA = lerSemComentarios(REL_IRMA);
const FONTE_COLUNA = lerSemComentarios(REL_COLUNA);
const FONTE_PAGINA = lerSemComentarios(REL_PAGINA);

/**
 * ⚠️⚠️ A LISTA CONSTANTE dos `.jsx` DESTE plano — o recorte de TODA sonda de
 * ausência.
 *
 * Por que CONSTANTE e não glob: glob pegaria arquivo de outra wave e a sonda
 * cobraria vocabulário de trabalho que não é deste plano. ⚠️ E por que RECORTADA:
 * `lucro_liquido` é campo **legítimo** do Portal (8 arquivos), e varrer `app/`
 * **barrou um deploy** na Fase 186.
 */
const FONTES_DO_PLANO = [
    [REL_KPIS, FONTE_KPIS],
    [REL_COMPOSICAO, FONTE_COMPOSICAO],
    [REL_SIMULACAO, FONTE_SIMULACAO],
    [REL_IRMA, FONTE_IRMA],
    [REL_COLUNA, FONTE_COLUNA],
];

const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

const nada = () => {};

/**
 * ⚠️⚠️ A ÁRVORE VAZIA do git — o objeto que todo repositório tem, sempre.
 *
 * É a ISCA DETERMINÍSTICA da sonda de `numstat`: contra ela **todo arquivo
 * rastreado** produz uma linha, então a sonda prova que a invocação funciona e
 * que o pathspec resolve. ⚠️ Usar "um arquivo que esta wave alterou" como isca
 * seria **vazio por construção depois do commit** (`git diff HEAD` fica limpo),
 * e o gate viraria falso positivo no dia seguinte — é a mesma família do
 * `git diff --stat` sem range que a §Gate-vazio-por-construção descreve.
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
 * medindo o irmão errado**, e a wave 188-08 caiu nisso medindo a grade em vez da
 * foto.
 */
function tagsDeAbertura(html) {
    return [...html.matchAll(/<(div|span|section|aside|button|input|a)\b([^>]*)>/g)].map((m) => ({
        tag: m[1],
        atributos: m[2],
        classe: (m[2].match(/class="([^"]*)"/) || [])[1] ?? '',
        titulo: (m[2].match(/title="([^"]*)"/) || [])[1] ?? null,
        estilo: (m[2].match(/style="([^"]*)"/) || [])[1] ?? null,
        href: (m[2].match(/href="([^"]*)"/) || [])[1] ?? null,
        desabilitado: /\bdisabled\b/.test(m[2]),
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

// ═══ AS FIXTURES — ⚠️⚠️ ELAS DISCRIMINAM DE PROPÓSITO ═════════════════════
//
// ⚠️ Na wave 187-06 as duas mutações sobreviventes eram falha da FIXTURE, não da
// asserção. A 188-04 criou o Premium PRIMEIRO (id MENOR) porque `orderBy('id')`
// passaria por acidente, e a 188-08 pôs `com_alerta_restantes = 3` com UMA foto
// na lista para provar que o número vem do servidor.
// ➡️ Aqui: as TRÊS cores do resultado em três fixtures distintas, o mínimo em
// **20%** (e não 12%) para que o traço em `50%` seja distinguível do `30%`
// literal do protótipo, e a nota da margem com a frase da tarifa real.

/** O KPI 1 — o preço. ⚠️ O `−` do `brl()` é **U+2212**, não hífen-menos. */
const KPI_PRECO = {
    chave: 'preco_de_venda',
    rotulo: 'Preço de venda',
    valor: 'R$ 149,90',
    cor: 'texto',
};

/** O KPI 2 — o resultado POSITIVO. ⚠️ O rótulo vem do servidor, não daqui. */
const KPI_RESULTADO_OK = {
    chave: 'resultado',
    rotulo: 'Resultado estimado por venda',
    valor: 'R$ 23,41',
    cor: 'texto',
};

/** ⚠️ O resultado NEGATIVO: vermelho **e** a palavra `prejuízo` na nota do KPI 3. */
const KPI_RESULTADO_PREJUIZO = {
    ...KPI_RESULTADO_OK,
    valor: '− R$ 8,10',
    cor: 'vermelho',
};

/** ⚠️ O resultado INCOMPLETO: âmbar e a PALAVRA `Incompleto` — nunca `R$ 0,00`. */
const KPI_RESULTADO_INCOMPLETO = {
    ...KPI_RESULTADO_OK,
    valor: 'Incompleto',
    cor: 'ambar',
};

/**
 * O KPI 3 com o mínimo em **20%** — e é por isso que o traço sai em `50%`.
 *
 * ⚠️ `20/40 = 50%`, e o protótipo escreve `left:30%` porque `MIN = 12`. Usar 12
 * aqui faria o caso passar com o literal cravado.
 */
const KPI_MARGEM = {
    chave: 'margem',
    rotulo: 'Margem sobre o preço',
    rotulo_do_conceito: 'Margem de contribuição estimada',
    valor: '15,6%',
    cor: 'ambar',
    barra: { largura: '39%', cor: 'barra_alerta', minimo: '50%' },
    nota_da_margem: 'abaixo do mínimo de 20% · com a tarifa real do Mercado Livre',
    nota_cor: 'ambar',
    minimo_origem: 'parâmetro da empresa',
};

/**
 * ⚠️ O KPI 3 no ramo do PREJUÍZO: margem NEGATIVA, mínimo ainda em 20%.
 *
 * ⚠️ A nota é a do ramo `margem < 0` de `ComposicaoDaOferta::notaDaMargem()`, e
 * é ela que põe a PALAVRA ao lado da cor (D7) — a cor vermelha sozinha não diz
 * *"dá prejuízo"*.
 */
const KPI_MARGEM_PREJUIZO = {
    ...KPI_MARGEM,
    valor: '−5,4%',
    cor: 'vermelho',
    barra: { largura: '0%', cor: 'barra_baixa', minimo: '50%' },
    nota_da_margem: 'prejuízo por venda · com a tarifa real do Mercado Livre',
    nota_cor: 'vermelho',
};

/** ⚠️ O MESMO KPI com o mínimo em 12% — o traço tem de ir para `30%`. */
const KPI_MARGEM_MIN_12 = {
    ...KPI_MARGEM,
    valor: '15,6%',
    cor: 'texto',
    barra: { largura: '39%', cor: 'clara', minimo: '30%' },
    nota_da_margem: 'acima do mínimo de 12% · com a tarifa real do Mercado Livre',
    nota_cor: 'apagada',
};

/** ⚠️ A margem SEM dado: `—`, nunca `0%` — zero afirmaria margem zero. */
const KPI_MARGEM_SEM_DADO = {
    ...KPI_MARGEM,
    valor: '—',
    cor: 'neutro',
    barra: { largura: '0%', cor: 'trilha', minimo: '30%' },
    nota_da_margem: 'dados incompletos · com a tarifa real do Mercado Livre',
    nota_cor: 'ambar',
};

const KPIS_OK = [KPI_PRECO, KPI_RESULTADO_OK, KPI_MARGEM_MIN_12];
const KPIS_PREJUIZO = [KPI_PRECO, KPI_RESULTADO_PREJUIZO, KPI_MARGEM_PREJUIZO];
const KPIS_INCOMPLETO = [{ ...KPI_PRECO, valor: '—' }, KPI_RESULTADO_INCOMPLETO, KPI_MARGEM_SEM_DADO];

/** `:1310` — o aviso de alteração, com QUEM e QUANDO. */
const ALTERACAO = {
    glifo: '↻',
    texto: 'Preço alterado por Marina Vieira · 18 out 14:32: R$ 139,90 → R$ 149,90',
    correcao: 'Conferir alteração',
};

/** O payload mínimo da coluna, com o que a tarefa 1 desenha. */
const PRECO_OK = {
    titulo: 'Preço e resultado',
    kpis: KPIS_OK,
    incompleto: false,
    estimado_por_baixo: false,
    frete_ausente: false,
    preco: { valor: 'R$ 149,90', variante: 'Azul · M', nota: null },
    alteracao: null,
};

// ⚠️⚠️ OS BUNDLES, TODOS MONTADOS ANTES DO PRIMEIRO `test()`.
const MOD_KPIS = await montar(REL_KPIS);
const MOD_COMPOSICAO = await montar(REL_COMPOSICAO);
const MOD_SIMULACAO = await montar(REL_SIMULACAO);
const MOD_IRMA = await montar(REL_IRMA);
const MOD_COLUNA = await montar(REL_COLUNA);

// ═══════════════════════════════════════════════════════════════════════════
// 1. ANTI-FANTASMA — o gate perene desta fase
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ ANTI-FANTASMA: todo caminho citado por este gate existe no disco 188_09', () => {
    const fantasmas = CAMINHOS_CITADOS.filter((rel) => !fs.existsSync(path.resolve(RAIZ, rel)));
    assert.deepEqual(fantasmas, [], 'caminho fantasma citado pelo gate — sonda de AUSÊNCIA sobre arquivo inexistente passa CALADA');

    // ⚠️ ISCA: a sonda PEGA um caminho que não existe. Sem ela, um `existsSync`
    // sempre-verdadeiro (caminho relativo resolvido errado) passaria calado.
    assert.equal(fs.existsSync(path.resolve(RAIZ, `${DIR_APROV}/NaoExisteZz9.jsx`)), false, 'a isca do anti-fantasma virou arquivo de verdade');
    assert.ok(CAMINHOS_CITADOS.length >= 17, `a lista de caminhos encolheu: ${CAMINHOS_CITADOS.length}`);
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. VOCABULÁRIO — VOC-03 / D6, com o recorte e as DUAS iscas
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ VOC-03: nenhum arquivo DESTE plano diz "lucro liquido", e o recorte tem isca nas DUAS direções 188_09', () => {
    // A forma com cedilha E a forma sem acento, mais a chave de dado.
    const PROIBIDAS = [/lucro\s+l[íi]quido/i, /lucro_liquido/i];

    const infratores = [];
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        for (const re of PROIBIDAS) {
            if (re.test(fonte)) infratores.push(`${rel} ~ ${re}`);
        }
    }
    assert.deepEqual(infratores, [], 'arquivo deste plano usa "lucro líquido" — VOC-03/D6: o valor não desconta imposto, cartão, despesa fixa nem devolução');

    // ⚠️ ISCA POSITIVA: a sonda PEGA a forma proibida (as duas grafias).
    for (const cebo of ['o lucro líquido do mês', 'lucro liquido estimado', 'campo lucro_liquido']) {
        assert.ok(PROIBIDAS.some((re) => re.test(cebo)), `a sonda NÃO pega o cebo "${cebo}" — ela está furada`);
    }

    // ⚠️⚠️ ISCA DO RECORTE (a outra direção): um arquivo LEGÍTIMO de fora da
    // lista **casa** a sonda, e é exatamente por isso que o recorte existe.
    // Varredura larga sobre `app/` BARROU UM DEPLOY na Fase 186.
    const deFora = fs.readFileSync(path.resolve(RAIZ, 'app/Models/EstruturaPrecificacaoParametros.php'), 'utf8');
    assert.ok(PROIBIDAS.some((re) => re.test(deFora)), 'o arquivo de fora do recorte deixou de casar — troque a isca do recorte');
    assert.ok(!FONTES_DO_PLANO.some(([rel]) => rel.includes('EstruturaPrecificacaoParametros')), 'o arquivo da isca entrou na lista do plano — o recorte perdeu o sentido');

    // ⚠️⚠️ E A LEITURA É SEM COMENTÁRIO, DE PROPÓSITO: o docblock de
    // `KpisDoPainel.jsx` **contém** a palavra proibida (é ele que a proíbe), e uma
    // leitura CRUA reprovaria pelo próprio conserto — regra 2 da §6, com TRÊS
    // mutações abortadas por ela na wave 187-04.
    const cru = fs.readFileSync(path.resolve(RAIZ, REL_KPIS), 'utf8');
    assert.ok(PROIBIDAS.some((re) => re.test(cru)), 'o docblock deixou de explicar por que "lucro líquido" é proibido — sem ele a próxima pessoa não acha o motivo por grep');
    assert.ok(!PROIBIDAS.some((re) => re.test(FONTE_KPIS)), 'a leitura SEM comentário não está limpando o docblock — `lerSemComentarios` quebrou');
});

test('⚠️⚠️ DECISÃO 9: a nota da margem NOMEIA a tarifa real, e a tela LÊ em vez de remontar 188_09', () => {
    const html = render(MOD_KPIS.default, { kpis: KPIS_OK });

    // ⚠️ A substring MEDIDA, byte a byte, com o `·` U+00B7.
    assert.ok(
        texto(html).includes('com a tarifa real do Mercado Livre'),
        'a nota da margem não diz de onde vem o número — deixar a margem SEM texto foi RECUSADO pelo usuário (decisão 9)',
    );

    // ⚠️⚠️ E ELA NÃO É REMONTADA AQUI: a frase chega fechada de
    // `ComposicaoDaOferta:271`. Literal na tela seria a SEGUNDA redação, e a
    // divergente seria a que a pessoa lê.
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        assert.ok(
            !/tarifa real do Mercado Livre/.test(fonte),
            `${rel} remontou a frase da tarifa real — ela vem de ComposicaoDaOferta:271 (decisão 9)`,
        );
    }

    // ⚠️ ISCA: a frase ESTÁ no servidor — se sair de lá, a tela fica sem texto e
    // ninguém saberia por quê.
    const dto = fs.readFileSync(path.resolve(RAIZ, REL_DTO), 'utf8');
    assert.match(dto, /· com a tarifa real do Mercado Livre/, 'a frase saiu de ComposicaoDaOferta — a decisão 9 foi apagada no servidor');

    // ⚠️⚠️ AS DUAS MARGENS NÃO SE UNIFICAM, e isso é ordem escrita: a da visão
    // rápida do lote vive em `Publicador/Fila/ResumoRapidoService` (⚠️ o caminho
    // **NÃO** é `.../Aprovacao/Fila/`).
    const resumo = fs.readFileSync(path.resolve(RAIZ, REL_RESUMO_RAPIDO), 'utf8');
    assert.ok(resumo.length > 0, 'o ResumoRapidoService do lote sumiu — a decisão 9 tem DOIS lados');
    assert.equal(
        fs.existsSync(path.resolve(RAIZ, 'app/Services/Publicador/Aprovacao/Fila/ResumoRapidoService.php')),
        false,
        'apareceu um ResumoRapidoService dentro de Aprovacao/Fila — confundir os dois APAGARIA a decisão 9',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. OS 3 KPIs — ordem, divisória, as TRÊS cores e o texto ao lado
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ FID: os 3 KPIs saem na ORDEM do servidor, e a tela NÃO reordena 188_09', () => {
    const html = render(MOD_KPIS.default, { kpis: KPIS_OK });
    const conteudo = texto(html);

    const iPreco = conteudo.indexOf('Preço de venda');
    const iResultado = conteudo.indexOf('Resultado estimado por venda');
    const iMargem = conteudo.indexOf('Margem sobre o preço');

    assert.ok(iPreco >= 0 && iResultado >= 0 && iMargem >= 0, `faltou rótulo de KPI na tela: ${conteudo}`);
    assert.ok(iPreco < iResultado, 'o KPI do preço saiu DEPOIS do resultado — a ordem é KPIS_EM_ORDEM do servidor');
    assert.ok(iResultado < iMargem, 'o KPI da margem saiu ANTES do resultado — a ordem é KPIS_EM_ORDEM do servidor');

    // ⚠️⚠️ A PROVA DE QUE A ORDEM É A DO SERVIDOR, e não uma lista da tela: com a
    // lista INVERTIDA, a tela inverte. Uma reordenação interna passaria no caso
    // acima e reprovaria aqui.
    const invertido = texto(render(MOD_KPIS.default, { kpis: [...KPIS_OK].reverse() }));
    assert.ok(
        invertido.indexOf('Margem sobre o preço') < invertido.indexOf('Preço de venda'),
        'a tela REORDENOU os KPIs — a ordem é contrato do servidor (KPIS_EM_ORDEM), e uma segunda lista aqui divergiria',
    );

    // ⚠️ E as chaves do contrato continuam as três, na ordem, no servidor.
    const servico = fs.readFileSync(path.resolve(RAIZ, REL_SERVICO_PRECO), 'utf8');
    assert.match(servico, /KPIS_EM_ORDEM = \[self::KPI_PRECO, self::KPI_RESULTADO, self::KPI_MARGEM\]/, 'a ordem dos KPIs mudou no servidor — remeça o contrato');
    assert.deepEqual(MOD_KPIS.CHAVES_DOS_KPIS, ['preco_de_venda', 'resultado', 'margem'], 'as chaves dos KPIs divergiram do contrato do servidor');
});

test('⚠️ FID: a célula 1 NÃO tem divisória e as células 2 e 3 TÊM — a divisória é POSIÇÃO 188_09', () => {
    const html = render(MOD_KPIS.default, { kpis: KPIS_OK });
    const tags = tagsDeAbertura(html);

    // ⚠️ Árvore com índice ABSOLUTO: a grade é o elemento 0, e as três células
    // são as `div` seguintes. `indexOf` de classe pegaria o irmão errado.
    const celulas = tags.filter((t) => t.tag === 'div' && /p-\[12px\]/.test(t.classe));
    assert.equal(celulas.length, 3, `não há exatamente 3 células de KPI: ${celulas.length}`);

    assert.equal(classesDe(celulas[0]).has('border-l'), false, 'a célula 1 ganhou `border-left` — `:642` não tem');
    assert.ok(classesDe(celulas[1]).has('border-l'), 'a célula 2 perdeu o `border-left` de `:643`');
    assert.ok(classesDe(celulas[2]).has('border-l'), 'a célula 3 perdeu o `border-left` de `:644`');

    // ⚠️ A célula 3 tem `gap:6px` (barra + nota); as 1 e 2 têm `gap:4px`. Medido.
    assert.ok(classesDe(celulas[0]).has('gap-[4px]'), 'a célula 1 mudou de `gap:4px` (`:642`)');
    assert.ok(classesDe(celulas[2]).has('gap-[6px]'), 'a célula 3 mudou de `gap:6px` (`:644`)');

    // ⚠️ A GRADE por IGUALDADE de string, com isca do quase-igual: `includes`
    // casa prefixo E sufixo de outro nome, e já mordeu CINCO vezes.
    assert.equal(
        MOD_KPIS.CLASSES_GRADE,
        'grid grid-cols-[repeat(3,minmax(0,1fr))] rounded-pub-sub border border-pub-line-subtle',
        'a grade dos 3 KPIs divergiu de `:641`',
    );
    for (const quaseIgual of ['grid-cols-3', 'grid-cols-[repeat(3,1fr)]', 'grid-cols-[repeat(2,minmax(0,1fr))]']) {
        assert.ok(!FONTE_KPIS.includes(quaseIgual), `o quase-igual \`${quaseIgual}\` entrou na fonte — ele compila para OUTRO valor (ou para o mesmo com outro dono)`);
    }
    // ⚠️ ISCA DO QUASE-IGUAL: a forma CERTA está lá, e a sonda a distingue.
    assert.ok(FONTE_KPIS.includes('grid-cols-[repeat(3,minmax(0,1fr))]'), 'a sonda do quase-igual está cega: nem a forma certa ela acha');
});

test('⚠️⚠️ D7: os TRÊS estados do resultado têm TRÊS cores DISTINTAS e TEXTO ao lado de cada uma 188_09', () => {
    const cores = new Map();
    const textos = new Map();

    for (const [nome, kpis] of [['ok', KPIS_OK], ['prejuizo', KPIS_PREJUIZO], ['incompleto', KPIS_INCOMPLETO]]) {
        const html = render(MOD_KPIS.default, { kpis });
        const valores = tagsDeAbertura(html).filter((t) => /text-\[21px\]/.test(t.classe));
        assert.equal(valores.length, 3, `${nome}: não há 3 valores de 21px`);

        // ⚠️ O KPI 2 é o do RESULTADO — índice ABSOLUTO 1, nunca por busca de classe.
        const daCor = [...classesDe(valores[1])].filter((c) => c.startsWith('text-pub-'));
        assert.equal(daCor.length, 1, `${nome}: o valor do resultado tem ${daCor.length} classes de cor`);
        cores.set(nome, daCor[0]);
        textos.set(nome, texto(html));
    }

    // ⚠️⚠️ TRÊS cores DISTINTAS aos pares. Um teste de existência passaria com
    // duas iguais, e duas iguais apagariam a diferença entre "dá prejuízo" e
    // "não se sabe".
    const distintas = new Set(cores.values());
    assert.equal(distintas.size, 3, `as cores do resultado NÃO são três distintas: ${[...cores].map(([k, v]) => `${k}=${v}`).join(' ')}`);
    assert.equal(cores.get('incompleto'), 'text-pub-warn-txt', 'o incompleto deixou de ser âmbar (`:1132`)');
    assert.equal(cores.get('prejuizo'), 'text-pub-danger-txt', 'o prejuízo deixou de ser vermelho (`:1132`)');
    assert.equal(cores.get('ok'), 'text-pub-txt', 'o resultado normal deixou de ser a cor de texto (`:1132`)');

    // ⚠️⚠️ D7 — A COR NUNCA É O ÚNICO SINAL: cada estado leva PALAVRA.
    assert.ok(textos.get('incompleto').includes('Incompleto'), 'o estado incompleto não tem a PALAVRA na tela — cor seria o único sinal (D7)');
    assert.ok(textos.get('incompleto').includes('dados incompletos'), 'a nota do incompleto não diz "dados incompletos"');
    assert.ok(textos.get('prejuizo').includes('prejuízo por venda'), 'o prejuízo não tem a palavra `prejuízo por venda` ao lado da cor (D7)');
    assert.ok(textos.get('ok').includes('acima do mínimo'), 'o estado normal não diz de que lado do mínimo está (D7)');

    // ⚠️ E o INCOMPLETO nunca sai `R$ 0,00`: zero AFIRMARIA que é de graça
    // (FIN-05/FIN-06, D4). A margem sai `—`, nunca `0%`.
    assert.ok(!textos.get('incompleto').includes('R$ 0,00'), 'o incompleto virou `R$ 0,00` — zero afirma que é de graça');
    assert.ok(textos.get('incompleto').includes('—'), 'a margem sem dado não saiu `—` — `0%` afirmaria margem zero');
    assert.ok(!/\b0,0%/.test(textos.get('incompleto')), 'a margem sem dado saiu como percentual — ela não se sabe');
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. A BARRA — FÓRMULA com DOIS mínimos, e tudo por `style`
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ D10: o traço do mínimo SE MOVE com o mínimo — provado com DOIS mínimos (12 e 20) 188_09', () => {
    const posicaoDoTraco = (kpis) => {
        const html = render(MOD_KPIS.default, { kpis });
        const tracos = tagsDeAbertura(html).filter((t) => /w-\[1\.5px\]/.test(t.classe));
        assert.equal(tracos.length, 1, `não há exatamente 1 traço do mínimo: ${tracos.length}`);

        return tracos[0].estilo;
    };

    // ⚠️⚠️ `minimo = 12` ⇒ `12/40 = 30%` (o literal do protótipo) e `minimo = 20`
    // ⇒ `20/40 = 50%`. **Com um mínimo só, o literal cravado passaria.**
    assert.match(posicaoDoTraco(KPIS_OK), /left:\s*30%/, 'com mínimo 12 o traço não ficou em 30% — a fórmula é `minimo/40`');
    assert.match(posicaoDoTraco(KPIS_PREJUIZO), /left:\s*50%/, 'com mínimo 20 o traço NÃO se moveu — alguém cravou a posição (D10)');

    // ⚠️ E a LARGURA do preenchimento também vem por `style`, do servidor.
    const html = render(MOD_KPIS.default, { kpis: KPIS_OK });
    const preenchimentos = tagsDeAbertura(html).filter((t) => /inset-y-0/.test(t.classe));
    assert.equal(preenchimentos.length, 1, 'não há exatamente 1 preenchimento de barra');
    assert.match(preenchimentos[0].estilo, /width:\s*39%/, 'a largura da barra não veio do servidor');

    // ⚠️ A SEGUNDA PORTA: percentual adverso cai em `0%`, nunca em `NaN%` (CSS
    // inválido faria a barra desaparecer SEM erro nenhum).
    for (const ruim of [{ largura: 'NaN%', minimo: {} }, { largura: null, minimo: [] }, { largura: '950%', minimo: '-7%' }]) {
        const sujo = render(MOD_KPIS.default, { kpis: [KPI_PRECO, KPI_RESULTADO_OK, { ...KPI_MARGEM, barra: { ...ruim, cor: 'trilha' } }] });
        assert.ok(!/NaN/.test(sujo), `percentual adverso virou NaN no style: ${ruim.largura}`);
        assert.ok(!/width:\s*950%/.test(sujo), 'percentual acima de 100 passou — ele estouraria a trilha');
    }
});

test('⚠️⚠️ TAILWIND: largura e posição NUNCA por classe montada por template literal 188_09', () => {
    // ⚠️⚠️ A SONDA É NA FONTE SEM COMENTÁRIO, e isso é ACHADO MEDIDO da wave
    // 188-08: **o scanner do Tailwind lê COMENTÁRIO**, e no CSS construído o
    // próprio docblock que proíbe a classe A FAZ EXISTIR. Um gate de CSS aqui
    // reprovaria o conserto.
    const MONTADAS = [/\bw-\[\$\{/, /\bleft-\[\$\{/, /`w-\[/, /`left-\[/, /['"]w-\[['"]\s*\+/, /['"]left-\[['"]\s*\+/];

    const infratores = [];
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        for (const re of MONTADAS) {
            if (re.test(fonte)) infratores.push(`${rel} ~ ${re}`);
        }
    }
    assert.deepEqual(infratores, [], 'classe de largura/posição montada por template literal — o Tailwind NÃO gera o CSS e a barra não desenha');

    // ⚠️ ISCA POSITIVA: a sonda PEGA as duas formas erradas.
    for (const cebo of ['className={`w-[${pct}]`}', 'cn(`left-[${min}]`)', "'w-[' + pct + ']'"]) {
        assert.ok(MONTADAS.some((re) => re.test(cebo)), `a sonda NÃO pega o cebo \`${cebo}\` — ela está furada`);
    }
    // ⚠️ ISCA NEGATIVA: classe LITERAL de largura é legítima e não pode reprovar.
    for (const legitimo of ["'w-[1.5px]'", "'left-[10px] top-[10px]'"]) {
        assert.ok(!MONTADAS.some((re) => re.test(legitimo)), `a sonda reprova a forma LEGÍTIMA \`${legitimo}\` — ela é mais larga que o invariante`);
    }

    // ⚠️ E a largura/posição estão de fato em `style`, nos dois pontos.
    assert.match(FONTE_KPIS, /style=\{\{\s*width:\s*largura\s*\}\}/, 'a largura da barra saiu de `style`');
    assert.match(FONTE_KPIS, /style=\{\{\s*left:\s*minimo\s*\}\}/, 'a posição do traço saiu de `style`');
});

test('⚠️ A decisão sobre `BarraDeMargem.jsx`: a função PURA é reusada e o DESENHO é próprio 188_09', () => {
    // ⚠️ O reuso é da forma fechada que recusa `NaN%` — uma casa só.
    assert.match(FONTE_KPIS, /import \{ percentualSeguro \} from '\.\/BarraDeMargem'/, 'o `percentualSeguro` deixou de ser reusado — a segunda cópia divergiria');

    // ⚠️⚠️ E o arquivo da FILA está INTOCADO: território somente leitura.
    assert.equal(numstatDe(REL_BARRA_DA_FILA).trim(), '', 'BarraDeMargem.jsx foi ALTERADO — ele é da Fase 187 e está NO AR');
    // ⚠️ ISCA DETERMINÍSTICA: contra a ÁRVORE VAZIA o mesmo arquivo produz linha.
    assert.ok(numstatDe(REL_BARRA_DA_FILA, ARVORE_VAZIA).trim() !== '', 'a sonda de `numstat` está CEGA — o pathspec não resolve');

    // ⚠️⚠️ AS TRÊS DIVERGÊNCIAS MEDIDAS que justificam o desenho próprio:
    //   (a) o traço do painel é `pub-txt-2` (#A1A1AA, `:645`) e o da fila é
    //       `pub-txt-4` (#71717A, `:163`);
    //   (b) o preenchimento normal do painel é `clara`/#D4D4D8 (`:1132`) e o da
    //       fila é `suave`/#A1A1AA (`:1246`) — a `188-FID01.md` §L já media isso;
    //   (c) o wrapper é outro (a fila tem `pr-[12px]` e o número de 13px ACIMA).
    const fonteDaFila = lerSemComentarios(REL_BARRA_DA_FILA);
    assert.ok(fonteDaFila.includes('bg-pub-txt-4'), 'o traço da FILA deixou de ser `pub-txt-4` — remeça a divergência');
    assert.ok(MOD_KPIS.CLASSES_TRACO.includes('bg-pub-txt-2'), 'o traço do PAINEL deixou de ser `pub-txt-2` (`:645`)');
    assert.ok(!MOD_KPIS.CLASSES_TRACO.includes('bg-pub-txt-4'), 'o traço do painel copiou a cor da fila — são DIFERENTES, medido nos dois pontos');
    assert.ok(!fonteDaFila.includes('suave: \'bg-pub-fase1\''), 'a chave `suave` da fila virou `pub-fase1` — a cor da fila é #A1A1AA');
    assert.equal(MOD_KPIS.FUNDO_DA_BARRA.clara, 'bg-pub-fase1', 'a chave `clara` do painel deixou de ser `pub-fase1` (#D4D4D8, `:1132`)');
    assert.equal(Object.prototype.hasOwnProperty.call(MOD_KPIS.FUNDO_DA_BARRA, 'suave'), false, 'o mapa do painel ganhou a chave `suave` da fila — ela é da OUTRA cor');
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. O AVISO DE ALTERAÇÃO — e o botão SEM DESTINO (a rota é da 188-06)
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ BLOQ-07: o aviso de alteração só existe COM alteração, e o texto vem do servidor 188_09', () => {
    const semAviso = render(MOD_KPIS.AvisoDeAlteracao, { alteracao: null, onConferir: nada });
    assert.equal(semAviso, '', 'o aviso de alteração nasceu SEM alteração — casca amarela sem motivo é D4 violado');

    const comAviso = render(MOD_KPIS.AvisoDeAlteracao, { alteracao: ALTERACAO, onConferir: nada });
    const conteudo = texto(comAviso);
    assert.ok(conteudo.includes('Preço alterado por Marina Vieira'), 'o aviso não diz QUEM alterou');
    assert.ok(conteudo.includes('R$ 139,90 → R$ 149,90'), 'o aviso não diz o DE e o PARA');
    assert.ok(conteudo.includes('↻'), 'o glifo do aviso sumiu — cor não é o único sinal');

    // ⚠️ O rótulo do botão é `alteracao.correcao`, LIDO de
    // `VocabularioDoPortao::CORRECOES` pelo servidor — a tela não o escreve.
    assert.ok(conteudo.includes('Conferir alteração'), 'o rótulo do botão não veio do servidor');
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        assert.ok(!/Conferir altera[çc][ãa]o/.test(fonte), `${rel} escreveu o rótulo à mão — ele é LIDO de VocabularioDoPortao::CORRECOES`);
    }

    // ⚠️ E ele é o rótulo certo no vocabulário do servidor (isca da leitura).
    const vocabulario = fs.readFileSync(path.resolve(RAIZ, 'app/Services/Publicador/Aprovacao/VocabularioDoPortao.php'), 'utf8');
    assert.match(vocabulario, /ALTERACAO_NAO_CONFERIDA => 'Conferir alteração'/, 'o rótulo mudou no vocabulário — remeça a leitura');
});

test('⚠️⚠️ D4: "Conferir alteração" NÃO é desenhado sem handler, e a rota é da wave 188-06 188_09', () => {
    // ⚠️ SEM handler: o TEXTO do aviso permanece, o BOTÃO não nasce. É a mesma
    // régua da PERGUNTA-188-B (o destino fica `null` e o texto continua).
    const sem = render(MOD_KPIS.AvisoDeAlteracao, { alteracao: ALTERACAO, onConferir: null });
    assert.ok(texto(sem).includes('Preço alterado por'), 'sem handler o aviso INTEIRO sumiu — o texto do bloqueio tem de permanecer');
    assert.equal(tagsDeAbertura(sem).filter((t) => t.tag === 'button').length, 0, 'o botão nasceu SEM destino — D4 violado');

    // ⚠️ COM handler: exatamente UM botão, e ele chama o handler NO CLIQUE.
    const com = render(MOD_KPIS.AvisoDeAlteracao, { alteracao: ALTERACAO, onConferir: nada });
    const botoes = tagsDeAbertura(com).filter((t) => t.tag === 'button');
    assert.equal(botoes.length, 1, `com handler não há exatamente 1 botão: ${botoes.length}`);
    assert.equal(botoes[0].desabilitado, false, 'o botão nasceu `disabled` — `disabled` decorativo foi OFERECIDO e RECUSADO');

    // ═══ ⚠️⚠️ O TRANSPORTE NÃO É DESTA WAVE — E ESTA SONDA JÁ FOI CONSERTADA
    //     UMA VEZ, POR TER REPROVADO TRABALHO CORRETO DE OUTRA SESSÃO ════════
    //
    // ⚠️⚠️ **MEDIDO EM 19/10/2026, DURANTE ESTA EXECUÇÃO:** a primeira redação
    // deste caso exigia que `preco/simular` **NÃO existisse** em
    // `routes/mlb_anuncios.php`. Ela REPROVOU — porque a wave **188-06** tem WIP
    // **não commitado** nesta MESMA árvore (+61 em `routes/`, +329 no
    // `MlbPublicadorAprovacaoController`, +288 e +220 nos dois serviços do
    // painel). ➡️ A sonda era **mais LARGA que o invariante** e reprovava
    // trabalho CORRETO: é a família de erro que já BARROU UM DEPLOY neste
    // projeto (`188-REGUA.md` §6, regra 8).
    //
    // ⚠️ O invariante CERTO desta wave **não** é *"a rota não existe"* — é
    // **esta wave não escreve o transporte**: a peça recebe o handler por PROP,
    // e sem handler o botão não nasce. Quem liga a rota é a **188-06** (o
    // transporte) e a **188-12** (a religação). ➡️ **Ancorado no COMPORTAMENTO,
    // não na ausência de uma linha num arquivo de outra wave.**
    const infratoresDoTransporte = [];
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        // ⚠️ Nenhum `.jsx` deste plano chama `router.` direto NEM nomeia rota: o
        // helper único é `escreverNoPainel` (W11/COL-04), e o gate de LISTA
        // CONSTANTE dele mora no **188-12** — aqui ele seria gate sobre zero
        // casos, porque a escrita desta coluna chega por prop.
        if (/\brouter\s*\./.test(fonte)) infratoresDoTransporte.push(`${rel} router.`);
        if (/mlb\.anuncios\.meus\.aprovacao/.test(fonte)) infratoresDoTransporte.push(`${rel} nome de rota`);
        if (/\baxios\b|\bfetch\s*\(/.test(fonte)) infratoresDoTransporte.push(`${rel} HTTP direto`);
    }
    assert.deepEqual(infratoresDoTransporte, [], 'a coluna de preço passou a FALAR com o servidor — o transporte é da wave 188-06, e o helper único é `escreverNoPainel`');
    // ⚠️ ISCA NAS DUAS DIREÇÕES: a sonda pega as três formas erradas...
    for (const cebo of ['router.post(rota, corpo)', "route('mlb.anuncios.meus.aprovacao.painel.preco')", 'await fetch (url)']) {
        assert.ok(
            /\brouter\s*\./.test(cebo) || /mlb\.anuncios\.meus\.aprovacao/.test(cebo) || /\baxios\b|\bfetch\s*\(/.test(cebo),
            `a sonda do transporte NÃO pega o cebo \`${cebo}\``,
        );
    }
    // ...e NÃO pega a forma LEGÍTIMA (receber o handler por prop).
    for (const legitimo of ['onConferirAlteracao = null', 'typeof onConferir === \'function\'']) {
        assert.ok(
            !/\brouter\s*\./.test(legitimo) && !/mlb\.anuncios\.meus\.aprovacao/.test(legitimo),
            `a sonda do transporte reprova a forma LEGÍTIMA \`${legitimo}\``,
        );
    }
    // ⚠️ E o arquivo de rotas continua legível (ISCA da leitura): sem isso a
    // ausência de `router.` acima poderia ser medida sobre um caminho errado.
    const rotas = fs.readFileSync(path.resolve(RAIZ, REL_ROTAS), 'utf8');
    for (const presente of ['meus.aprovacao.painel.tentar-de-novo', 'meus.aprovacao.painel.fotos.sem-alerta']) {
        assert.ok(rotas.includes(presente), `a isca \`${presente}\` não está nas rotas — a leitura está CEGA`);
    }

    // ⚠️ `onClick` vazio e `disabled=` decorativo NÃO existem nos arquivos deste
    // plano. ⚠️ `/disabled=/` e **nunca** `/disabled/`, que casa
    // `disabled:opacity-40` (a armadilha escrita no critério 8).
    const infratores = [];
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        if (/onClick=\{\(\)\s*=>\s*\{\s*\}\}/.test(fonte)) infratores.push(`${rel} onClick vazio`);
        if (/disabled=/.test(fonte)) infratores.push(`${rel} disabled=`);
    }
    assert.deepEqual(infratores, [], '`onClick` vazio ou `disabled` decorativo — as duas foram OFERECIDAS e RECUSADAS (D4)');
    // ⚠️ ISCA: a sonda do `disabled=` distingue o atributo da VARIANTE.
    assert.ok(/disabled=/.test('<button disabled={true}>'), 'a sonda de `disabled=` está furada');
    assert.ok(!/disabled=/.test('className="disabled:opacity-40"'), 'a sonda de `disabled=` casa a VARIANTE do Tailwind — ela é mais larga que o invariante');
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. A COLUNA — a casca da 188-07 e o slot sem dado
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ D4: a coluna de preço NÃO desenha casca sem dado, e reusa a casca da 188-07 188_09', () => {
    for (const adverso of [null, undefined, [], 'texto', 7, true, { toString: null }]) {
        const html = render(MOD_COLUNA.default, { preco: adverso });
        assert.equal(html, '', `a coluna desenhou casca com \`preco\` adverso (${typeof adverso}) — borda de 14px vazia é D4 violado`);
    }

    const html = render(MOD_COLUNA.default, { preco: PRECO_OK });
    const tags = tagsDeAbertura(html);
    assert.equal(tags[0].tag, 'section', 'a raiz da coluna não é a `<section>` de `ColunaDoPainel` (`:639`)');

    // ⚠️ A casca é a da 188-07, byte a byte — não se reescreve aqui.
    assert.deepEqual(
        [...classesDe(tags[0])].sort(),
        'flex flex-col gap-[12px] rounded-pub-table border border-pub-line bg-pub-surface p-[14px]'.split(' ').sort(),
        'a casca da coluna divergiu de `ColunaDoPainel` (`:639`) — ela é da 188-07 e NÃO se reescreve',
    );
    assert.match(FONTE_COLUNA, /import \{ ColunaDoPainel, filhosSeguros \} from '\.\/PainelDeRevisao'/, 'a coluna deixou de reusar a casca e o filtro de filhos da 188-07');

    // ⚠️ O título vem do servidor, não de um literal da tela.
    assert.ok(texto(html).includes('Preço e resultado'), 'o título da coluna não saiu');
    assert.ok(!FONTE_COLUNA.includes('Preço e resultado'), 'o título foi escrito à mão — ele é `PrecoDoPainelService::TITULO`');
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. ⚠️⚠️ A TELA PRETA — render adverso REAL, e TAMBÉM no CLIQUE
// ═══════════════════════════════════════════════════════════════════════════

/**
 * As formas adversas. ⚠️ `{ toString: null }` é a que **LANÇA** em
 * `String(valor)`, em `hasOwnProperty` e em qualquer `obj[chave]`; `NaN` passa
 * por `typeof === 'number'`; objeto comum como prop de NÓ **mata a página**.
 */
const ADVERSAS = [
    null, undefined, {}, [], NaN, Infinity, -0, '', '   ', 0, false, true,
    { toString: null }, { valueOf: null }, [[1]], () => 'x', Symbol.iterator,
];

test('⚠️⚠️ REND: nenhum campo adverso dos KPIs derruba a página 188_09', () => {
    const CAMPOS = ['chave', 'rotulo', 'valor', 'cor', 'barra', 'nota_da_margem', 'nota_cor', 'rotulo_do_conceito', 'minimo_origem'];

    for (const campo of CAMPOS) {
        for (const forma of ADVERSAS) {
            const kpis = [KPI_PRECO, KPI_RESULTADO_OK, { ...KPI_MARGEM, [campo]: forma }];
            let html = null;
            assert.doesNotThrow(
                () => { html = render(MOD_KPIS.default, { kpis }); },
                // ⚠️ NUNCA `String(forma)` nem `JSON.stringify(forma)` na mensagem:
                // os dois LANÇAM / mentem com estas formas. Só o nome do campo.
                `o campo \`${campo}\` derrubou o render dos KPIs`,
            );
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `o campo \`${campo}\` vazou valor cru para a tela`);
        }
    }

    // ⚠️ E os campos da BARRA, que vão para `style` (CSS inválido some sem erro).
    for (const campo of ['largura', 'minimo', 'cor']) {
        for (const forma of ADVERSAS) {
            const kpis = [KPI_PRECO, KPI_RESULTADO_OK, { ...KPI_MARGEM, barra: { ...KPI_MARGEM.barra, [campo]: forma } }];
            let html = null;
            assert.doesNotThrow(() => { html = render(MOD_KPIS.default, { kpis }); }, `o campo \`barra.${campo}\` derrubou o render`);
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined/, `o campo \`barra.${campo}\` vazou valor cru`);
        }
    }

    // ⚠️ A prop INTEIRA adversa, e a COLEÇÃO adversa (`.map` em string LANÇA).
    for (const forma of [...ADVERSAS, [null], [{}], ['x'], [[]], [KPI_PRECO, null, {}]]) {
        let html = null;
        assert.doesNotThrow(() => { html = render(MOD_KPIS.default, { kpis: forma }); }, 'a prop `kpis` inteira derrubou o render');
        assert.doesNotMatch(html, /\[object Object\]|NaN|undefined/, 'a prop `kpis` inteira vazou valor cru');
    }

    // ⚠️ O aviso de alteração, campo por campo.
    for (const campo of ['glifo', 'texto', 'correcao']) {
        for (const forma of ADVERSAS) {
            let html = null;
            assert.doesNotThrow(
                () => { html = render(MOD_KPIS.AvisoDeAlteracao, { alteracao: { ...ALTERACAO, [campo]: forma }, onConferir: nada }); },
                `o campo \`alteracao.${campo}\` derrubou o render`,
            );
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined/, `o campo \`alteracao.${campo}\` vazou valor cru`);
        }
    }

    // ⚠️ E a coluna inteira com os três blocos adversos ao mesmo tempo.
    for (const forma of ADVERSAS) {
        let html = null;
        assert.doesNotThrow(
            () => { html = render(MOD_COLUNA.default, { preco: { titulo: forma, kpis: forma, alteracao: forma }, onConferirAlteracao: forma }); },
            'a coluna inteira derrubou o render com campos adversos',
        );
        assert.doesNotMatch(html, /\[object Object\]|NaN|undefined/, 'a coluna vazou valor cru');
    }
});

test('⚠️⚠️ REND: o CLIQUE do "Conferir alteração" não derruba a página 188_09', () => {
    // ⚠️⚠️ `renderToStaticMarkup` **NÃO dispara `onClick`** — a TELA PRETA da
    // wave 187-06 foi NO CLIQUE, e por isso o handler é exercido DIRETO.
    for (const forma of ADVERSAS) {
        assert.doesNotThrow(
            () => render(MOD_KPIS.AvisoDeAlteracao, { alteracao: ALTERACAO, onConferir: forma }),
            'o `onConferir` adverso derrubou o render',
        );
    }

    // ⚠️ O handler de VERDADE é chamado UMA vez, sem argumento inventado.
    let chamadas = 0;
    const vivo = () => { chamadas += 1; };
    const html = render(MOD_KPIS.AvisoDeAlteracao, { alteracao: ALTERACAO, onConferir: vivo });
    assert.ok(html.includes('<button'), 'o botão não nasceu com handler vivo');
    assert.equal(chamadas, 0, 'o handler foi chamado NO RENDER — ele é do clique');

    // ⚠️ E o leitor de mapa de cor NÃO LANÇA com chave adversa (`obj[chave]` cru
    // LANÇA com `{toString: null}`, e `hasOwnProperty` sobre ela também).
    for (const forma of ADVERSAS) {
        assert.doesNotThrow(
            () => MOD_KPIS.classeDaCorDoPreco(MOD_KPIS.TEXTO_DA_COR, forma, MOD_KPIS.TEXTO_DA_COR.neutro),
            'o leitor de cor LANÇOU com chave adversa',
        );
        assert.equal(
            typeof MOD_KPIS.classeDaCorDoPreco(MOD_KPIS.TEXTO_DA_COR, forma, MOD_KPIS.TEXTO_DA_COR.neutro),
            'string',
            'o leitor de cor devolveu algo que não é classe',
        );
    }

    // ⚠️⚠️ E o padrão NUNCA é verde: afirmar que um número foi confirmado quando a
    // chave é desconhecida é o pior lado do erro nesta coluna.
    assert.notEqual(
        MOD_KPIS.classeDaCorDoPreco(MOD_KPIS.TEXTO_DA_COR, 'chave_que_nao_existe', MOD_KPIS.TEXTO_DA_COR.neutro),
        MOD_KPIS.TEXTO_DA_COR.verde,
        'chave desconhecida caiu no VERDE',
    );

    // ⚠️⚠️ A CHAVE NEUTRA DESTA COLUNA É `neutro`, e a da coluna de FOTOS é
    // `neutra` — medido. É por isso que o `classeDaCor()` daquele arquivo não
    // serve aqui, e um gate que exigisse o reuso reprovaria código certo.
    assert.equal(MOD_KPIS.CHAVE_NEUTRA, 'neutro', 'a chave neutra desta coluna mudou — remeça contra PrecoDoPainelService::COR_NEUTRA');
    const servico = fs.readFileSync(path.resolve(RAIZ, REL_SERVICO_PRECO), 'utf8');
    assert.match(servico, /COR_NEUTRA = 'neutro'/, 'a chave neutra do servidor mudou');
    const fotos = fs.readFileSync(path.resolve(RAIZ, 'app/Services/Publicador/Aprovacao/Painel/FotosDoPainelService.php'), 'utf8');
    assert.ok(/'neutra'/.test(fotos), 'a coluna de FOTOS deixou de usar `neutra` — a divergência das duas chaves foi resolvida? remeça');
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. TERRITÓRIO — o que esta wave NÃO pode ter tocado nem antecipado
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ TERR: nada de localStorage, `String(`, hex cru, nem peça nova em `.js` 188_09', () => {
    const infratores = [];
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        if (/localStorage/.test(fonte)) infratores.push(`${rel} localStorage`);
        if (/Restaurar dados de exemplo/.test(fonte)) infratores.push(`${rel} dados de exemplo`);
        if (/ecf-aprovacao-v1/.test(fonte)) infratores.push(`${rel} KEY do protótipo`);
        // ⚠️ `String(` LANÇA com `{toString: null}`, e há gate da 187-04 que o
        // proíbe na página — está CERTO, não se afrouxa.
        if (/\bString\(/.test(fonte)) infratores.push(`${rel} String(`);
        if (/JSON\.stringify\(/.test(fonte)) infratores.push(`${rel} JSON.stringify(`);
        // ⚠️ TOK-15 — nenhum hex literal na tela.
        if (/#[0-9A-Fa-f]{6}\b/.test(fonte)) infratores.push(`${rel} hex literal`);
        // ⚠️ FID-04 — os tokens VETADOS.
        for (const vetado of ['ecf-bg', 'ecf-card', 'ecf-yellow', 'font-display', 'border-white/[0.08]']) {
            if (fonte.includes(vetado)) infratores.push(`${rel} ${vetado}`);
        }
        // ⚠️ FID-03 — `text-sm`/`text-xs`/`text-xl` são arredondamento VETADO.
        for (const vetado of ['text-sm', 'text-xs', 'text-xl', 'text-base']) {
            if (fonte.includes(vetado)) infratores.push(`${rel} ${vetado}`);
        }
    }
    assert.deepEqual(infratores, [], 'arquivo deste plano violou território/vocabulário');

    // ⚠️ ISCA: cada sonda PEGA o que deve pegar.
    assert.ok(/#[0-9A-Fa-f]{6}\b/.test("color: '#FACC15'"), 'a sonda de hex está furada');
    assert.ok(/\bString\(/.test('String(valor)'), 'a sonda de `String(` está furada');
    assert.ok(!/\bString\(/.test('textoSeguro(valor)'), 'a sonda de `String(` casa nome de outra função — ela é mais larga que o invariante');

    // ⚠️⚠️ O invariante CERTO é *"nenhuma PEÇA nova sob `resources/` é `.js`"* — a
    // wave 187-11 mediu que *"nenhum arquivo novo é `.js`"* era literalmente
    // FALSO (há 9 `.js`, **todos testes**).
    const pecasEmJs = FONTES_DO_PLANO.filter(([rel]) => rel.startsWith('resources/') && rel.endsWith('.js'));
    assert.deepEqual(pecasEmJs, [], 'peça nova sob `resources/` em `.js` — a classe não geraria CSS e a tela sairia sem geometria');
    assert.ok(FONTES_DO_PLANO.every(([rel]) => rel.endsWith('.jsx')), 'arquivo deste plano não é `.jsx`');
});

test('⚠️ TERR: as Fases 189/192 e as waves 10/11/12 NÃO são antecipadas 188_09', () => {
    const infratores = [];
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        // ⚠️ Fase 189 — o lightbox. Fase 192 — o gerador.
        if (/Ampliar/.test(fonte)) infratores.push(`${rel} ⤢ Ampliar (Fase 189)`);
        if (/Gerar novamente/.test(fonte)) infratores.push(`${rel} ✦ Gerar novamente (Fase 192)`);
        // ⚠️ Decisão 1 do usuário — aprovar JÁ PUBLICA, sem segundo clique.
        for (const vetado of ['confirmar publicação', 'segundo passo', 'cancelar aprovação', 'Desfazer']) {
            if (fonte.includes(vetado)) infratores.push(`${rel} ${vetado}`);
        }
        // ⚠️ Pendências e rodapé são da 188-10; os modais da 188-11/12.
        if (/Salvar revisão/.test(fonte)) infratores.push(`${rel} rodapé (188-10)`);
        if (/Aprovar para publicação/.test(fonte)) infratores.push(`${rel} rodapé (188-10)`);
    }
    assert.deepEqual(infratores, [], 'a coluna de preço antecipou trabalho de outra fase/wave');

    // ⚠️ ISCA: as sondas PEGAM os literais que deveriam pegar.
    assert.ok(/Ampliar/.test('⤢ Ampliar'), 'a sonda de Ampliar está furada');
    assert.ok(/Gerar novamente/.test('✦ Gerar novamente com IA'), 'a sonda de Gerar novamente está furada');

    // ⚠️⚠️ O painel APROVA (e aprovar ENFILEIRA); ele NÃO publica — a escrita no
    // ML é da Fase 193 (critério 18). A fronteira fica DECLARADA no código,
    // porque a próxima pessoa tem de achá-la por `grep`, não por bisseção.
    // ⚠️ Aqui a leitura é do arquivo CRU, DE PROPÓSITO: o docblock é o artefato.
    const cru = fs.readFileSync(path.resolve(RAIZ, REL_COLUNA), 'utf8');
    assert.match(cru, /APROVA \(e aprovar ENFILEIRA\); ele NÃO publica/, 'a fronteira do critério 18 saiu do docblock da coluna');
    assert.match(cru, /\*\*Fase 193\*\*/, 'o docblock não nomeia a Fase 193 como dona da escrita no ML');
});

test('⚠️ TERR: os INTOCÁVEIS não foram tocados, com isca da sonda 188_09', () => {
    // ⚠️⚠️ **SÓ ENTRA AQUI O QUE NENHUMA WAVE IRMÃ DESTA FASE TOCA.** E isso é
    // CONSERTO MEDIDO nesta execução, não cautela: a primeira redação listava
    // `PrecoDoPainelService.php` e `PainelDeRevisaoService.php`, e REPROVOU —
    // porque a wave **188-06** tem WIP **não commitado** nesta MESMA árvore
    // (+220 e +288 naqueles dois, +329 no controller, +61 em `routes/`).
    //
    // ⚠️⚠️ `git diff HEAD` **NÃO ATRIBUI AUTORIA**: ele mostra a árvore inteira,
    // e nesta árvore há outra sessão escrevendo. ➡️ Para arquivo que uma wave
    // irmã legitimamente edita, a prova de território **não pode ser o diff** —
    // ela é o CONTRATO (as constantes e as chaves que esta coluna LÊ continuam
    // lá), e isso está assertido caso por caso nos blocos acima. ⚠️ Conferir o
    // AUTOR também não serviria: as três máquinas desta árvore commitam com o
    // MESMO `user.name`.
    const INTOCAVEIS = [
        REL_GATE_ZINDEX,
        'tests/js/estrutura-meus-anuncios.test.js',
        REL_VOCABULARIO,
        `${DIR_APROV}/andamentoDoPortao.jsx`,
        `${DIR_APROV}/SeloDeAndamentoInterno.jsx`,
        `${DIR_APROV}/CascaDeModal.jsx`,
        `${DIR_APROV}/ToastDaFila.jsx`,
        `${DIR_APROV}/BarraDeMargem.jsx`,
        `${DIR_APROV}/ColunaDeFotos.jsx`,
        `${DIR_APROV}/PainelDeRevisao.jsx`,
        `${DIR_APROV}/TopoDoPainel.jsx`,
        'resources/js/Layouts/AppLayout.jsx',
        'resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx',
        // ⚠️ Fase 186, EM PRODUÇÃO: nenhuma wave da 188 pode editá-los.
        REL_DTO,
        REL_LINHA_FINANCEIRA,
        // ⚠️ GATE-07 — nenhum dos três pertence a plano nenhum.
        '.planning/ROADMAP.md',
        '.planning/STATE.md',
        '.planning/REQUIREMENTS-v27.md',
        // ⚠️ T-188-SC — esta wave não instala nada.
        'package.json',
        'package-lock.json',
    ];

    // ⚠️⚠️ OS QUE A SONDA DE DIFF **NÃO PODE** MEDIR, e por quê — declarado em
    // vez de silenciosamente omitido. A prova deles é o CONTRATO.
    const SOMENTE_POR_CONTRATO = [REL_SERVICO_PRECO, REL_SERVICO_PAINEL, REL_ROTAS];
    const colididos = SOMENTE_POR_CONTRATO.filter((rel) => INTOCAVEIS.includes(rel));
    assert.deepEqual(colididos, [], 'arquivo de wave irmã entrou na lista de diff — a sonda voltaria a reprovar trabalho correto de outra sessão');
    // ⚠️ E o contrato que esta coluna LÊ continua de pé, nos três:
    const doPreco = fs.readFileSync(path.resolve(RAIZ, REL_SERVICO_PRECO), 'utf8');
    for (const contrato of ['KPIS_EM_ORDEM', 'ORDEM_DAS_LINHAS', 'NOTA_DO_FRETE_DO_COMPRADOR', 'FRETE_ROTULO_ACAO', "COR_NEUTRA = 'neutro'"]) {
        assert.ok(doPreco.includes(contrato), `o contrato \`${contrato}\` saiu de PrecoDoPainelService — a coluna leria o nada`);
    }

    // ⚠️ ANTI-FANTASMA antes do diff: `numstat` de caminho inexistente sai VAZIO
    // e a sonda passaria CALADA.
    const fantasmas = INTOCAVEIS.filter((rel) => !fs.existsSync(path.resolve(RAIZ, rel)));
    assert.deepEqual(fantasmas, [], 'caminho intocável que não existe — o `numstat` dele sairia vazio por construção');

    const tocados = INTOCAVEIS.filter((rel) => numstatDe(rel).trim() !== '');
    assert.deepEqual(tocados, [], 'arquivo INTOCÁVEL alterado por esta wave');

    // ⚠️⚠️ ISCA DETERMINÍSTICA, e NÃO "um arquivo que esta wave alterou": contra a
    // ÁRVORE VAZIA todo arquivo rastreado produz linha, então a sonda prova que a
    // invocação funciona HOJE e continua provando DEPOIS DO COMMIT. Uma isca
    // baseada em WIP seria **vazia por construção** no dia seguinte.
    const cegos = INTOCAVEIS.filter((rel) => numstatDe(rel, ARVORE_VAZIA).trim() === '');
    assert.deepEqual(cegos, [], 'a sonda de `numstat` está CEGA para estes caminhos — o pathspec não resolve');

    // ⚠️ T-188-SC — esta wave NÃO instala nada. A prova é por FORMA (diff vazio),
    // e ela já está no laço acima; aqui fica a razão escrita: install que falha
    // pode ser pacote slopsquatted, e o conserto NÃO é trocar o nome.
    assert.ok(INTOCAVEIS.includes('package-lock.json'), 'o lockfile saiu da lista de intocáveis (T-188-SC)');
    assert.ok(SOMENTE_POR_CONTRATO.length === 3, 'a lista dos medidos por CONTRATO mudou de tamanho — declare por quê');
});

// ═══════════════════════════════════════════════════════════════════════════
// 9. AS LISTAS CONSTANTES das Fases 186/187, e o MANIFESTO
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️⚠️ LISTAS: os `.jsx` desta wave estão DECLARADOS nas DUAS escriturações 188_09', () => {
    const meus = FONTES_DO_PLANO.map(([rel]) => path.basename(rel));
    assert.ok(meus.length > 0, 'a lista dos arquivos deste plano está VAZIA — a sonda está cega');

    // ⚠️ `ARQUIVOS_NOVOS` do GATE-03: arquivo fora dela fica SEM conferência de
    // vocabulário, e o `deepEqual` de `DIRETORIOS_FECHADOS` reprova na hora.
    const gateVocabulario = lerSemComentarios(REL_GATE_VOCABULARIO);
    const blocoVocabulario = gateVocabulario.match(/const\s+ARQUIVOS_NOVOS\s*=\s*\[([\s\S]*?)\n\];/);
    assert.ok(blocoVocabulario, 'não achei o literal de ARQUIVOS_NOVOS — a leitura está CEGA');
    const faltandoNoVocabulario = meus.filter((nome) => !blocoVocabulario[1].includes(nome));
    assert.deepEqual(faltandoNoVocabulario, [], 'arquivo deste plano fora de `ARQUIVOS_NOVOS` — o GATE-03 passaria POR OMISSÃO (VOC-07)');

    // ⚠️ `JSX_DEPOIS_DA_187`: são DUAS escriturações para `aprovacao/`, e isso é
    // DELIBERADO — o último conserto RECUSOU criar uma terceira e apontou o gate
    // do fechamento para a invariante em vez de copiar 28 nomes.
    const gateFechamento = lerSemComentarios(REL_GATE_FECHAMENTO);
    const blocoFechamento = gateFechamento.match(/const\s+JSX_DEPOIS_DA_187\s*=\s*\[([\s\S]*?)\n\];/);
    assert.ok(blocoFechamento, 'não achei o literal de JSX_DEPOIS_DA_187 — a leitura está CEGA');
    const faltandoNoFechamento = meus.filter((nome) => !blocoFechamento[1].includes(nome));
    assert.deepEqual(faltandoNoFechamento, [], 'arquivo deste plano fora de `JSX_DEPOIS_DA_187` — o conjunto do diretório divergiria');

    // ⚠️ ISCA DAS DUAS LEITURAS: elas ACHAM um nome que está lá e NÃO acham um
    // que não está. Sem isso, um `includes` sobre bloco vazio passaria.
    assert.ok(blocoVocabulario[1].includes('ColunaDeFotos.jsx'), 'a leitura de ARQUIVOS_NOVOS está cega');
    assert.ok(!blocoVocabulario[1].includes('NaoExisteZz9.jsx'), 'a leitura de ARQUIVOS_NOVOS casa qualquer coisa');
    assert.ok(blocoFechamento[1].includes('PainelDeRevisao.jsx'), 'a leitura de JSX_DEPOIS_DA_187 está cega');
    assert.ok(!blocoFechamento[1].includes('NaoExisteZz9.jsx'), 'a leitura de JSX_DEPOIS_DA_187 casa qualquer coisa');

    // ⚠️ `21` continua na lista de tamanhos LIBERADOS (plano 188-01), e é o único
    // tamanho novo desta fase. Sem ele o GATE-03 reprova o `text-[21px]`.
    const gateTokens = lerSemComentarios('tests/js/publicador-fila-tokens-187.test.js');
    const blocoLiberados = gateTokens.match(/const\s+LIBERADOS_DEPOIS_DA_187\s*=\s*\[([\s\S]*?)\n\];/);
    assert.ok(blocoLiberados, 'não achei o literal de LIBERADOS_DEPOIS_DA_187 — a leitura está CEGA');
    assert.match(blocoLiberados[1], /\b21\b/, 'o `21` saiu de LIBERADOS_DEPOIS_DA_187 — o `text-[21px]` dos KPIs reprovaria');

    // ⚠️⚠️ E ESTA WAVE NÃO AMPLIA A RÉGUA: todos os tamanhos que ela usa já
    // estavam liberados. A sonda é sobre os tamanhos REALMENTE escritos.
    const usados = new Set();
    for (const [, fonte] of FONTES_DO_PLANO) {
        for (const m of fonte.matchAll(/text-\[(\d+(?:\.\d+)?)px\]/g)) usados.add(Number(m[1]));
    }
    assert.ok(usados.size > 0, 'a leitura dos tamanhos usados está CEGA');
    const PERMITIDOS = new Set([8.5, 9.5, 10, 10.5, 11, 11.5, 12, 12.5, 13, 13.5, 14, 14.5, 15, 15.5, 16, 17, 20, 21, 22, 24, 28, 30, 32, 34]);
    assert.deepEqual([...usados].filter((n) => !PERMITIDOS.has(n)), [], 'esta wave usou tamanho FORA de TAMANHOS_PERMITIDOS — liberar exige MEDIR e entrar em LIBERADOS_DEPOIS_DA_187');
});

test('⚠️ MANIFESTO: este arquivo está declarado em `contagens-188-09.txt`, e os nomes são ÚNICOS 188_09', () => {
    const manifesto = fs.readFileSync(path.resolve(RAIZ, REL_MANIFESTO), 'utf8');
    const linhas = manifesto.split(/\r?\n/).filter((l) => l.trim() !== '' && !l.trim().startsWith('#'));

    assert.ok(linhas.length > 0, 'o manifesto não tem nenhuma linha de gate');
    assert.ok(
        linhas.some((l) => l.includes('tests/js/publicador-painel-preco-188.test.js')),
        'este arquivo de gate NÃO está no manifesto — ele nunca rodaria',
    );

    // ⚠️ Nenhum id repetido (o harness pega o PRIMEIRO).
    const ids = linhas.map((l) => l.trim().split(/\s+/)[0]);
    assert.deepEqual(ids.filter((id, i) => ids.indexOf(id) !== i), [], 'id repetido no manifesto');

    // ⚠️⚠️ HOMÔNIMO: o `--filter` do PHPUnit é regex NÃO ANCORADO, mas ele só
    // existe no modo `php` — e este manifesto **não tem id `php`**. ➡️ O que se
    // prova aqui é o que é VERDADE: todo nome de teste deste arquivo termina em
    // `188_09`, então nome genérico não colide.
    assert.deepEqual(linhas.filter((l) => /\sphp\s/.test(l)), [], 'este manifesto passou a ter id `php` — a sonda de homônimo precisa virar a de `--filter`');

    const meus = [...fs.readFileSync(path.resolve(RAIZ, 'tests/js/publicador-painel-preco-188.test.js'), 'utf8')
        .matchAll(/^test\('([^']+)'/gm)].map((m) => m[1]);
    assert.ok(meus.length > 0, 'a leitura dos nomes de teste deste arquivo está CEGA');
    assert.deepEqual(meus.filter((n) => !n.endsWith('188_09')), [], 'nome de teste sem o sufixo `188_09` — nome genérico COLIDE e o `--filter` SOMA');

    // ⚠️ E a alternância do manifesto é expandida ANTES de comparar — o furo
    // MEDIDO pela wave 188-05 (`/test_[A-Za-z0-9_]+/` **não casa** `test_(a|b)`,
    // que é a forma de todos os manifestos desta fase: deu `SEM_ID=14` acusando
    // trabalho CORRETO).
    const EXPANDE = (filtro) => {
        const m = filtro.match(/^test_\(([^)]*)\)(.*)$/);

        return m ? m[1].split('|').map((parte) => `test_${parte}${m[2]}`) : [filtro];
    };
    assert.deepEqual(EXPANDE('test_(a|b)_188_09'), ['test_a_188_09', 'test_b_188_09'], 'o expansor de alternância está furado');
    assert.deepEqual(EXPANDE('test_simples'), ['test_simples'], 'o expansor quebrou o caso sem alternância');
});

// ═══════════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════════════
// 12. A COMPOSIÇÃO — as 7 linhas, o SELO DE ORIGEM e as TRÊS formas do valor
// ═══════════════════════════════════════════════════════════════════════════

/** ⚠️ As SETE linhas da conta, na ordem do servidor, cada uma com nota PRÓPRIA. */
const LINHAS_DA_CONTA = [
    { chave: 'custo', rotulo: 'Custo do produto', detalhe: 'ERP Bling · atualizado 11 out', valor: '− R$ 62,00', valor_cor: 'clara', origem: 'confirmado', origem_rotulo: 'Confirmado', origem_cor: 'verde', origem_borda: 'verde_fraca', nota: 'ERP Bling · atualizado 11 out', pendente: false, informativa: false },
    { chave: 'comissao', rotulo: 'Comissão', detalhe: '16,5% · Clássico', valor: '− R$ 24,73', valor_cor: 'clara', origem: 'confirmado', origem_rotulo: 'Confirmado', origem_cor: 'verde', origem_borda: 'verde_fraca', nota: 'tarifa real do Mercado Livre', pendente: false, informativa: false },
    { chave: 'tarifa_fixa', rotulo: 'Tarifa fixa por venda', detalhe: 'faixa acima de R$ 79,00', valor: '− R$ 6,75', valor_cor: 'clara', origem: 'confirmado', origem_rotulo: 'Confirmado', origem_cor: 'verde', origem_borda: 'verde_fraca', nota: 'tabela do Mercado Livre', pendente: false, informativa: false },
    { chave: 'frete', rotulo: 'Frete pago pelo vendedor', detalhe: 'Mercado Envios Full', valor: '− R$ 21,90', valor_cor: 'clara', origem: 'estimado', origem_rotulo: 'Estimado', origem_cor: 'clara', origem_borda: 'trilha', nota: 'cotação média dos últimos 30 dias', pendente: false, informativa: false },
    { chave: 'imposto', rotulo: 'Impostos', detalhe: 'Simples Nacional · 6%', valor: '− R$ 8,99', valor_cor: 'clara', origem: 'estimado', origem_rotulo: 'Estimado', origem_cor: 'clara', origem_borda: 'trilha', nota: 'parâmetro da empresa', pendente: false, informativa: false },
    // ⚠️⚠️ A EXCEÇÃO DELIBERADA: Embalagem nasce `Pendente` e **NÃO anula**.
    { chave: 'embalagem', rotulo: 'Embalagem', detalhe: 'não informado', valor: 'Pendente', valor_cor: 'vermelho', origem: 'pendente', origem_rotulo: 'Pendente', origem_cor: 'vermelho', origem_borda: 'vermelha_fraca', nota: 'ninguém informou o custo de embalagem', pendente: true, informativa: false },
    // ⚠️ "Outros custos" nasce R$ 0,00 `Confirmado` — e ZERO sai SEM sinal, em neutro.
    { chave: 'outros', rotulo: 'Outros custos', detalhe: 'nenhum lançamento', valor: 'R$ 0,00', valor_cor: 'neutro', origem: 'confirmado', origem_rotulo: 'Confirmado', origem_cor: 'verde', origem_borda: 'verde_fraca', nota: 'nenhum custo extra lançado', pendente: false, informativa: false },
];

/** FIN-08 — a linha INFORMATIVA: aparece e **não entra na soma**. */
const LINHAS_INFORMATIVAS = [
    { chave: 'frete_do_comprador', rotulo: 'Frete pago pelo comprador', detalhe: 'cobrado no checkout', valor: 'R$ 0,00', valor_cor: 'neutro', origem: 'estimado', origem_rotulo: 'Estimado', origem_cor: 'clara', origem_borda: 'trilha', nota: 'regra de frete grátis do Mercado Livre', pendente: false, informativa: true },
];

const COMPOSICAO_OK = {
    cabecalho: { rotulo: 'Preço de venda', valor: 'R$ 149,90' },
    linhas: LINHAS_DA_CONTA,
    informativas: LINHAS_INFORMATIVAS,
    rodape: { rotulo: 'Resultado estimado por venda', valor: 'R$ 23,41', cor: 'texto' },
    nota: 'Frete pago pelo comprador não entra no resultado.',
};

/** ⚠️ O módulo do VOCABULÁRIO, montado ANTES do primeiro `test()` que o usa. */
const MOD_VOCABULARIO = await montar(REL_VOCABULARIO);

test('⚠️⚠️ DADO-13: as 7 linhas saem na ORDEM do servidor, e TODAS têm `title` NÃO-VAZIO 188_09', () => {
    const html = render(MOD_COMPOSICAO.default, { composicao: COMPOSICAO_OK, preco: { valor: 'R$ 149,90', variante: null, nota: null } });
    const conteudo = texto(html);

    // ⚠️ A ORDEM, por POSIÇÃO do rótulo no texto — e ela é a do servidor.
    const posicoes = LINHAS_DA_CONTA.map((l) => conteudo.indexOf(l.rotulo));
    assert.deepEqual(posicoes.filter((p) => p < 0), [], 'faltou rótulo de linha na tela');
    const ordenadas = [...posicoes].sort((a, b) => a - b);
    assert.deepEqual(posicoes, ordenadas, 'a tela REORDENOU as 7 linhas — a ordem é `ORDEM_DAS_LINHAS` do servidor');

    // ⚠️⚠️ A PROVA DE QUE A ORDEM É A DO SERVIDOR: com a lista invertida, a tela
    // inverte. Uma ordenação interna passaria acima e reprovaria aqui.
    const invertido = texto(render(MOD_COMPOSICAO.default, { composicao: { ...COMPOSICAO_OK, linhas: [...LINHAS_DA_CONTA].reverse() } }));
    assert.ok(invertido.indexOf('Outros custos') < invertido.indexOf('Custo do produto'), 'a tela tem a sua própria ordem — ela seria a terceira lista do mesmo fato');

    // ⚠️ E a ordem do contrato é `array_keys(PubAprovacaoComposicao::LINHAS)`.
    assert.deepEqual(MOD_COMPOSICAO.ORDEM_DAS_LINHAS, ['custo', 'comissao', 'tarifa_fixa', 'frete', 'imposto', 'embalagem', 'outros'], 'a ordem declarada divergiu do servidor');
    const servico = fs.readFileSync(path.resolve(RAIZ, REL_SERVICO_PRECO), 'utf8');
    assert.match(servico, /ORDEM_DAS_LINHAS = \['custo', 'comissao', 'tarifa_fixa', 'frete', 'imposto', 'embalagem', 'outros'\]/, 'a ordem mudou no servidor — remeça o contrato');

    // ⚠️⚠️ DADO-13 — `title` NÃO-VAZIO nas SETE linhas + na informativa. `title=""`
    // é um `title` AUSENTE, e nota vazia é ERRO (não decoração) por regra do
    // servidor. ⚠️ A asserção COLETA os infratores, em contagem FIXA.
    const selos = tagsDeAbertura(html).filter((t) => /text-\[10\.5px\]/.test(t.classe));
    assert.equal(selos.length, 8, `não há 8 selos de origem (7 linhas + 1 informativa): ${selos.length}`);
    const semTitulo = selos.filter((t) => t.titulo === null || t.titulo.trim() === '');
    assert.deepEqual(semTitulo.map((t) => t.classe), [], 'selo de origem com `title` VAZIO — nota vazia é ERRO (DADO-13 / LinhaFinanceira::REGRA_NOTA_VAZIA)');

    // ⚠️ E a nota NOMEIA a fonte: ela não é texto decorativo.
    assert.ok(selos.some((t) => /ERP Bling/.test(t.titulo)), 'a nota do custo não NOMEIA o ERP');
    assert.ok(selos.some((t) => /tarifa real do Mercado Livre/.test(t.titulo)), 'a nota da comissão não NOMEIA a tarifa real');

    // ⚠️ ISCA: nota em branco PRODUZ `title` ausente, e a sonda acima o pegaria.
    // ⚠️ DEFEITO DO PRÓPRIO TESTE, achado por ele: sem `informativas: []` a isca
    // produzia DOIS selos (a linha + a informativa) e a contagem fixa reprovava.
    const comVazia = render(MOD_COMPOSICAO.default, { composicao: { ...COMPOSICAO_OK, linhas: [{ ...LINHAS_DA_CONTA[0], nota: '   ' }], informativas: [] } });
    const seloVazio = tagsDeAbertura(comVazia).filter((t) => /text-\[10\.5px\]/.test(t.classe));
    assert.equal(seloVazio.length, 1, 'a isca não produziu selo');
    assert.equal(seloVazio[0].titulo, null, 'nota em branco virou `title=""` — ele seria um `title` AUSENTE que passa em render e desaparece na tela');

    // ⚠️ A regra existe no servidor (isca da leitura).
    const linhaFinanceira = fs.readFileSync(path.resolve(RAIZ, REL_LINHA_FINANCEIRA), 'utf8');
    assert.match(linhaFinanceira, /REGRA_NOTA_VAZIA/, 'a regra de nota vazia saiu do servidor — sem ela o `title` deste gate não prova nada');
});

test('⚠️⚠️ `:1125`: as TRÊS origens têm SEIS cores distintas aos pares, e batem com o VOCABULÁRIO 188_09', () => {
    const html = render(MOD_COMPOSICAO.default, { composicao: COMPOSICAO_OK });
    const selos = tagsDeAbertura(html).filter((t) => /text-\[10\.5px\]/.test(t.classe));

    // ⚠️ Por ORIGEM, e por ÁRVORE com índice ABSOLUTO: o selo 0 é `Confirmado`, o
    // 3 é `Estimado`, o 5 é `Pendente` — nunca `indexOf` de classes.
    const paleta = new Map();
    for (const [origem, indice] of [['confirmado', 0], ['estimado', 3], ['pendente', 5]]) {
        const classes = classesDe(selos[indice]);
        const daCor = [...classes].filter((c) => c.startsWith('text-pub-'));
        const daBorda = [...classes].filter((c) => c.startsWith('border-pub-'));
        assert.equal(daCor.length, 1, `${origem}: ${daCor.length} classes de texto no selo`);
        assert.equal(daBorda.length, 1, `${origem}: ${daBorda.length} classes de borda no selo`);
        paleta.set(origem, { texto: daCor[0], borda: daBorda[0] });
    }

    // ⚠️⚠️ SEIS cores DISTINTAS aos pares. Duas iguais passariam num teste de
    // EXISTÊNCIA e apagariam a diferença entre "conferido" e "não existe".
    const textos = [...paleta.values()].map((p) => p.texto);
    const bordas = [...paleta.values()].map((p) => p.borda);
    assert.equal(new Set(textos).size, 3, `as cores de TEXTO das origens não são três distintas: ${textos.join(' ')}`);
    assert.equal(new Set(bordas).size, 3, `as cores de BORDA das origens não são três distintas: ${bordas.join(' ')}`);
    assert.equal(new Set([...textos, ...bordas]).size, 6, 'as seis cores das origens não são distintas aos pares');

    // ⚠️⚠️ E ELAS BATEM COM `SELOS_DE_ORIGEM` DO VOCABULÁRIO, origem por origem:
    // é ele a casa do vocabulário, e duas paletas para a mesma coisa divergiriam
    // no primeiro ajuste.
    for (const [origem, resolvida] of paleta) {
        const doVocabulario = MOD_VOCABULARIO.SELOS_DE_ORIGEM[origem];
        assert.ok(doVocabulario, `a origem \`${origem}\` saiu de SELOS_DE_ORIGEM — a leitura está CEGA`);
        assert.equal(resolvida.texto, doVocabulario.texto, `a cor de TEXTO de \`${origem}\` divergiu do vocabulário`);
        assert.equal(resolvida.borda, doVocabulario.borda, `a cor de BORDA de \`${origem}\` divergiu do vocabulário`);
    }

    // ⚠️ ISCA: chave de origem desconhecida NÃO cai no verde (afirmar confirmação
    // que não existe é o pior lado do erro nesta coluna).
    const sujo = render(MOD_COMPOSICAO.default, { composicao: { ...COMPOSICAO_OK, linhas: [{ ...LINHAS_DA_CONTA[0], origem_cor: 'inventada', origem_borda: 'inventada' }] } });
    const seloSujo = tagsDeAbertura(sujo).filter((t) => /text-\[10\.5px\]/.test(t.classe))[0];
    assert.ok(!classesDe(seloSujo).has('text-pub-ok-txt'), 'chave de cor desconhecida caiu no VERDE');
});

test('⚠️⚠️ `:1126`: o valor da linha tem TRÊS formas — invertido, zero neutro e `Pendente` 188_09', () => {
    const html = render(MOD_COMPOSICAO.default, { composicao: COMPOSICAO_OK });
    const valores = tagsDeAbertura(html).filter((t) => /text-right/.test(t.classe));
    assert.equal(valores.length, 8, `não há 8 valores de linha: ${valores.length}`);

    const conteudo = texto(html);

    // (1) ⚠️⚠️ O SINAL INVERTIDO, com **U+2212 MINUS SIGN seguido de espaço**
    // (`E2 88 92 20`) — e NÃO o hífen-menos. Trocar passa desapercebido na tela,
    // e por isso a conferência é por BYTES.
    const bytesDoMenos = Buffer.from('− ', 'utf8');
    assert.deepEqual([...bytesDoMenos], [0xE2, 0x88, 0x92, 0x20], 'a referência do U+2212 está errada no próprio teste');
    assert.ok(Buffer.from(conteudo, 'utf8').includes(bytesDoMenos), 'o valor negativo não usa U+2212 seguido de espaço');
    assert.ok(!/-\s?R\$/.test(conteudo), 'apareceu hífen-menos antes de R$ — o `brl()` do servidor imprime U+2212');
    assert.ok(conteudo.includes('− R$ 62,00'), 'o custo positivo não foi EXIBIDO negativo');

    // (2) ⚠️ ZERO sai SEM sinal e em NEUTRO ("Outros custos" nasce R$ 0,00
    // `Confirmado`). `− R$ 0,00` seria a tela inventando um sinal.
    assert.ok(classesDe(valores[6]).has('text-pub-txt-4'), 'o valor zero não saiu em neutro');
    assert.ok(conteudo.includes('R$ 0,00'), 'o zero não apareceu');
    assert.ok(!conteudo.includes('− R$ 0,00'), 'o zero saiu COM sinal — ele é zero, não um desconto');

    // (3) ⚠️ `null` sai como o TEXTO `Pendente`, em vermelho — e é o MESMO texto
    // do selo, porque os dois saem de `rotuloDaOrigem()`.
    assert.ok(classesDe(valores[5]).has('text-pub-danger-txt'), 'o valor pendente não saiu em vermelho');
    assert.ok(conteudo.includes('Pendente'), 'o valor pendente não saiu como TEXTO');

    // ⚠️⚠️ E NENHUM FORMATADOR NOVO NASCEU AQUI: a formatação é toda do servidor.
    const infratores = [];
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        if (/toLocaleString/.test(fonte)) infratores.push(`${rel} toLocaleString`);
        if (/Intl\.NumberFormat/.test(fonte)) infratores.push(`${rel} Intl.NumberFormat`);
        if (/toFixed\(/.test(fonte)) infratores.push(`${rel} toFixed(`);
        if (/'R\$ '|"R\$ "/.test(fonte)) infratores.push(`${rel} prefixo R$ literal`);
        if (/−/.test(fonte)) infratores.push(`${rel} U+2212 literal`);
    }
    assert.deepEqual(infratores, [], 'nasceu um SEGUNDO formatador de moeda na tela — a formatação é do servidor, byte a byte');
    // ⚠️ ISCA: as sondas pegam o que devem pegar.
    assert.ok(/toFixed\(/.test('n.toFixed(2)'), 'a sonda de `toFixed(` está furada');
    assert.ok(/'R\$ '|"R\$ "/.test("return 'R$ ' + n"), 'a sonda do prefixo R$ está furada');
    assert.ok(/−/.test('− R$ 1,00'), 'a sonda do U+2212 está furada');
});

test('⚠️⚠️ DECISÃO 3: Embalagem `Pendente` NÃO anula o resultado, e o FRETE ausente ANULA 188_09', () => {
    // ⚠️⚠️ A EXCEÇÃO DELIBERADA: com Embalagem `Pendente` o resultado EXISTE (sem
    // ela **100%** das ofertas diriam "Incompleto" e nada seria aprovável), e sai
    // marcado "estimado por baixo" pela nota da margem.
    const comEmbalagemPendente = render(MOD_KPIS.default, {
        kpis: [KPI_PRECO, KPI_RESULTADO_OK, { ...KPI_MARGEM_MIN_12, nota_da_margem: 'acima do mínimo de 12% · com a tarifa real do Mercado Livre · sem o custo de embalagem' }],
    });
    const textoEmbalagem = texto(comEmbalagemPendente);
    assert.ok(textoEmbalagem.includes('R$ 23,41'), 'com Embalagem `Pendente` o resultado DESAPARECEU — a exceção da decisão 3 foi apagada');
    assert.ok(!textoEmbalagem.includes('Incompleto'), 'com Embalagem `Pendente` o resultado virou `Incompleto` — sem a exceção NADA seria aprovável');
    assert.ok(textoEmbalagem.includes('sem o custo de embalagem'), 'o resultado não saiu marcado "estimado por baixo" — a tela esconderia que ele é OTIMISTA');

    // ⚠️ E a composição mostra a Embalagem como `Pendente` SEM derrubar o rodapé.
    const daComposicao = texto(render(MOD_COMPOSICAO.default, { composicao: COMPOSICAO_OK }));
    assert.ok(daComposicao.includes('Embalagem'), 'a linha da Embalagem sumiu');
    assert.ok(daComposicao.includes('R$ 23,41'), 'o rodapé do resultado foi ANULADO pela Embalagem pendente — a decisão 3 foi desfeita');

    // ⚠️⚠️ E O SINAL VIAJA POR UMA FONTE SÓ: `nota_da_margem`. Desenhá-lo DE NOVO
    // poria o MESMO fato em DUAS fontes — e fato repetido em dois lugares já
    // divergiu neste projeto (razão da decisão 9 e da PERGUNTA-188-D, em que
    // "toast + texto fixo" foi OFERECIDO e RECUSADO).
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        assert.ok(!/estimado por baixo/i.test(fonte), `${rel} escreveu "estimado por baixo" — o sinal vem de nota_da_margem, uma fonte só`);
        assert.ok(!/sem o custo de embalagem/.test(fonte), `${rel} remontou a frase da embalagem — ela é de ComposicaoDaOferta`);
    }
    // ⚠️ ISCA: a frase ESTÁ no servidor.
    const dto = fs.readFileSync(path.resolve(RAIZ, REL_DTO), 'utf8');
    assert.match(dto, /· sem o custo de embalagem/, 'a frase do estimado por baixo saiu do servidor');

    // ⚠️⚠️ A ISCA QUE SEPARA AS DUAS: o FRETE ausente **ANULA** — resultado
    // `Incompleto`, margem `—`, e NUNCA `R$ 0,00`.
    const comFreteAusente = texto(render(MOD_KPIS.default, { kpis: KPIS_INCOMPLETO }));
    assert.ok(comFreteAusente.includes('Incompleto'), 'com frete ausente o resultado NÃO foi anulado — o frete anula e a embalagem não');
    assert.ok(!comFreteAusente.includes('R$ 0,00'), 'frete ausente virou zero — zero afirmaria que o frete é de graça (FIN-05/FIN-06)');

    // ⚠️ E a lista das que ANULAM continua no servidor, sem a embalagem.
    const modelo = fs.readFileSync(path.resolve(RAIZ, 'app/Models/PubAprovacaoComposicao.php'), 'utf8');
    const bloco = modelo.match(/ANULAM_O_RESULTADO = \[([\s\S]*?)\];/);
    assert.ok(bloco, 'não achei ANULAM_O_RESULTADO — a leitura está CEGA');
    assert.ok(bloco[1].includes("'frete'"), 'o frete saiu das que ANULAM');
    assert.ok(!bloco[1].includes("'embalagem'"), 'a embalagem ENTROU nas que anulam — a exceção deliberada da decisão 3 foi desfeita');
    assert.ok(!bloco[1].includes("'outros'"), '"outros custos" entrou nas que anulam — ele nasce R$ 0,00 Confirmado');
});

test('⚠️ FIN-08: as linhas INFORMATIVAS vêm SEPARADAS e DEPOIS do rodapé do resultado 188_09', () => {
    const html = render(MOD_COMPOSICAO.default, { composicao: COMPOSICAO_OK });
    const conteudo = texto(html);

    const iRodape = conteudo.indexOf('Resultado estimado por venda');
    const iInformativa = conteudo.indexOf('Frete pago pelo comprador');
    assert.ok(iRodape >= 0 && iInformativa >= 0, 'faltou rodapé ou informativa');
    assert.ok(
        iRodape < iInformativa,
        'a linha INFORMATIVA saiu ANTES do rodapé — a pessoa somaria o frete do comprador junto, e o rodapé discordaria da lista (FIN-08)',
    );

    // ⚠️ Gate sobre dado PREENCHIDO **e** sobre dado AUSENTE: asserção de ausência
    // rodada com lista vazia é vazia por construção.
    // ⚠️⚠️ DEFEITO DO PRÓPRIO TESTE, achado por ele: a primeira redação procurava
    // `'Frete pago pelo comprador'`, que é **PREFIXO** da nota de `:665` (*"Frete
    // pago pelo comprador não entra no resultado."*) — a sonda de ausência NUNCA
    // ficava vazia, e reprovava com a lista correta. É a família do `includes`
    // que casa prefixo, que já mordeu CINCO vezes neste projeto.
    // ➡️ O recorte é o DETALHE da linha informativa, que só ela tem.
    const semInformativa = render(MOD_COMPOSICAO.default, { composicao: { ...COMPOSICAO_OK, informativas: [] } });
    // ⚠️⚠️ **E O RECORTE ESCOLHIDO FOI O SEGUNDO, porque o PRIMEIRO TAMBÉM
    // COLIDIU:** `'acima de R$ 79,00'` é SUBSTRING do detalhe da linha
    // `tarifa_fixa` (*"faixa acima de R$ 79,00"*). ➡️ Foi a TERCEIRA colisão de
    // substring que este gate achou nas PRÓPRIAS sondas, e a isca final é um
    // detalhe que **só** a linha informativa tem.
    assert.ok(!texto(semInformativa).includes('cobrado no checkout'), 'a informativa apareceu com a lista VAZIA');
    assert.ok(texto(semInformativa).includes('Resultado estimado por venda'), 'o rodapé sumiu junto com a informativa');
    // ⚠️ SONDA NÃO-CEGA: com a lista PREENCHIDA o detalhe aparece.
    assert.ok(texto(html).includes('cobrado no checkout'), 'o detalhe da informativa não aparece nem com a lista cheia — a sonda está CEGA');

    // ⚠️⚠️ DIVERGÊNCIA (1) DECLARADA: SÓ A PRIMEIRA FRASE DE `:665`. A segunda
    // ("Taxas de comissão são exemplos configuráveis.") é disclaimer da FICÇÃO do
    // protótipo, e aqui a comissão é a tarifa REAL do ML (decisão 9) — dizê-la
    // seria a tela AFIRMANDO O QUE NÃO É (D8 / FIN-02 / FIN-03).
    assert.ok(conteudo.includes('Frete pago pelo comprador não entra no resultado.'), 'a primeira frase de `:665` não saiu');
    assert.ok(!/exemplos configur[áa]veis/i.test(conteudo), 'a SEGUNDA frase de `:665` foi reproduzida — ela é FALSA neste produto');
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        assert.ok(!/exemplos configur[áa]veis/i.test(fonte), `${rel} escreveu "exemplos configuráveis" — divergência (1), declarada`);
        assert.ok(!/taxa de exemplo/i.test(fonte), `${rel} trouxe a ficção do protótipo`);
    }
    // ⚠️ ISCA: a sonda PEGA a frase proibida, COM e SEM acento — silêncio que
    // depende de UMA LETRA é sorte.
    assert.ok(/exemplos configur[áa]veis/i.test('Taxas de comissão são exemplos configuráveis.'), 'a sonda da 2ª frase está furada');
    assert.ok(/exemplos configur[áa]veis/i.test('exemplos configuraveis'), 'a sonda da 2ª frase não pega a forma sem acento');
    // ⚠️ E a frase do servidor é EXATAMENTE a primeira (isca da leitura).
    const servico = fs.readFileSync(path.resolve(RAIZ, REL_SERVICO_PRECO), 'utf8');
    assert.match(servico, /NOTA_DO_FRETE_DO_COMPRADOR = 'Frete pago pelo comprador não entra no resultado\.';/, 'a nota do servidor mudou — remeça a divergência (1)');
});

test('⚠️⚠️ DECISÃO 2 + P1: a frase do PIOR CASO é VISÍVEL com N > 1 e AUSENTE com um preço só 188_09', () => {
    // ⚠️ Com mais de um preço: a frase é TEXTO, não `title`. Na fila (187-08) ela
    // é só `title` porque a coluna tem 92px e UMA linha — "acrescentar segunda
    // linha na coluna de preço" foi OFERECIDO e RECUSADO. Aqui a decisão P1 do
    // usuário (15/10) a tornou VISÍVEL.
    const comNota = render(MOD_COMPOSICAO.default, {
        composicao: COMPOSICAO_OK,
        preco: { valor: 'R$ 149,90', variante: 'Azul · M', nota: 'pior caso entre 3 variantes · Azul · M' },
    });
    const conteudo = texto(comNota);
    assert.ok(conteudo.includes('pior caso entre 3 variantes'), 'a frase do PIOR CASO não é VISÍVEL — na fila ela é `title`, aqui é TEXTO (decisão P1)');
    assert.ok(conteudo.includes('Azul · M'), 'a frase não NOMEIA a variação');

    // ⚠️⚠️ E ela é TEXTO de verdade, não um `title` escondido: `title` seria
    // indistinguível de "não desenhei nada" para quem olha a tela.
    const comoTitulo = tagsDeAbertura(comNota).filter((t) => t.titulo !== null && /pior caso/.test(t.titulo));
    assert.deepEqual(comoTitulo.map((t) => t.classe), [], 'a frase do pior caso saiu como `title` — a decisão P1 pede TEXTO VISÍVEL');

    // ⚠️ Com UM preço só a nota é `null` e NÃO há frase — nunca "pior caso entre
    // 1 variante".
    for (const semNota of [null, undefined, '', '   ', {}, [], NaN]) {
        const html = render(MOD_COMPOSICAO.default, { composicao: COMPOSICAO_OK, preco: { valor: 'R$ 149,90', variante: null, nota: semNota } });
        assert.ok(!/pior caso/.test(texto(html)), 'a frase do pior caso apareceu SEM nota do servidor');
        assert.ok(!/1 variante/.test(texto(html)), 'apareceu "1 variante" — com um preço só NÃO há nota');
    }

    // ⚠️⚠️ E a frase NÃO é remontada aqui: ela é `ComposicaoDaOferta::$notaDoPreco`.
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        assert.ok(!/pior caso/i.test(fonte), `${rel} remontou a frase do pior caso — ela vem do DTO`);
    }
    const dto = fs.readFileSync(path.resolve(RAIZ, REL_DTO), 'utf8');
    assert.match(dto, /'pior caso entre '\.\$precosAtivos\.' variantes'/, 'a frase do pior caso saiu do DTO — remeça a decisão 2');
    assert.match(dto, /\$precosAtivos <= 1/, 'o DTO deixou de tratar o caso de UM preço só');
});

test('⚠️⚠️ B2 / D4: "Informar frete confirmado" nas QUATRO combinações — só UMA desenha 188_09', () => {
    const casos = [
        { freteAusente: true, handler: nada, desenha: true },
        { freteAusente: true, handler: null, desenha: false },
        { freteAusente: false, handler: nada, desenha: false },
        { freteAusente: false, handler: null, desenha: false },
    ];

    for (const caso of casos) {
        const html = render(MOD_COMPOSICAO.default, {
            composicao: COMPOSICAO_OK,
            frete: { rotulo_acao: 'Informar frete confirmado' },
            freteAusente: caso.freteAusente,
            onInformarFrete: caso.handler,
        });
        const botoes = tagsDeAbertura(html).filter((t) => t.tag === 'button');
        assert.equal(
            botoes.length,
            caso.desenha ? 1 : 0,
            `frete_ausente=${caso.freteAusente} handler=${caso.handler === null ? 'null' : 'funcao'}: esperava ${caso.desenha ? 1 : 0} botão`,
        );
        if (caso.desenha) {
            assert.equal(botoes[0].desabilitado, false, 'o botão de frete nasceu `disabled` — `disabled` decorativo foi RECUSADO');
            assert.ok(texto(html).includes('Informar frete confirmado'), 'o rótulo do botão não veio do servidor');
        }
        // ⚠️ E o TEXTO da composição continua aparecendo nos quatro casos: o botão
        // é que não nasce, a informação não desaparece (régua da PERGUNTA-188-B).
        assert.ok(texto(html).includes('Resultado estimado por venda'), 'a composição sumiu junto com o botão');
    }

    // ⚠️ O rótulo não é escrito na tela.
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        assert.ok(!/Informar frete/.test(fonte), `${rel} escreveu o rótulo do frete à mão — ele é PrecoDoPainelService::FRETE_ROTULO_ACAO`);
    }

    // ⚠️⚠️ DIVERGÊNCIA (2): o dado é PRECISO. O `freightMissing` do protótipo
    // (`:1143`) é o `missing` INTEIRO, e acenderia o botão quando o que falta é o
    // CUSTO — mandando a pessoa ao lugar errado. O servidor manda os DOIS dados
    // separados, e o botão lê o PRECISO.
    const servico = fs.readFileSync(path.resolve(RAIZ, REL_SERVICO_PRECO), 'utf8');
    assert.match(servico, /'frete_ausente' => \$this->fretePendente\(\$composicao\)/, 'o servidor deixou de mandar o dado PRECISO do frete');
    assert.match(servico, /linha\('frete'\)\?->ehPendente\(\) === true/, 'o `fretePendente` deixou de olhar SÓ o frete');

    // ⚠️ E o DESTINO não é cravado aqui: `frete_ausente` tem DOIS destinos, já
    // resolvidos por `PainelDeRevisaoService::destino()`.
    const doPainel = fs.readFileSync(path.resolve(RAIZ, REL_SERVICO_PAINEL), 'utf8');
    assert.match(doPainel, /function destino/, 'o `destino()` saiu do serviço do painel — a tela passaria a cravar o modal');
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        assert.ok(!/openModal|abrirModal/.test(fonte), `${rel} cravou a abertura de um modal — o destino do frete tem DOIS e é resolvido no servidor`);
    }
});

test('⚠️ FID: a GEOMETRIA da linha da composição, por IGUALDADE e com isca do quase-igual 188_09', () => {
    // ⚠️⚠️ IGUALDADE DE STRING, nunca `includes`: ele casa prefixo E sufixo de
    // outro nome, e já mordeu CINCO vezes (`h-[64px]`×`min-h-[64px]`,
    // `w-[560px]`×`max-w-[560px]`).
    assert.equal(
        MOD_COMPOSICAO.CLASSES_LINHA,
        'grid grid-cols-[minmax(0,1fr)_auto_92px] items-center gap-[10px] '
        + 'border-t border-pub-hover-3 px-[12px] py-[7px] text-[12.5px]',
        'a linha da composição divergiu de `:657`',
    );

    for (const quaseIgual of [
        'grid-cols-[minmax(0,1fr)_auto_92px_auto]',
        'grid-cols-[minmax(0,2fr)_auto_92px]',
        'grid-cols-[minmax(0,1fr)_auto_90px]',
        'grid-cols-3',
    ]) {
        assert.ok(!FONTE_COMPOSICAO.includes(quaseIgual), `o quase-igual \`${quaseIgual}\` entrou na fonte`);
    }
    // ⚠️ ISCA DO QUASE-IGUAL: a forma CERTA está lá, e a sonda a distingue.
    assert.ok(FONTE_COMPOSICAO.includes('grid-cols-[minmax(0,1fr)_auto_92px]'), 'a sonda do quase-igual está cega: nem a forma certa ela acha');

    // ⚠️⚠️ `min-w-0` É LOAD-BEARING, e a tabela FID-01 o OMITIA: sem ele o
    // `truncate` do detalhe NÃO TRUNCA — item de grade tem `min-width:auto` por
    // default e estoura a coluna de `minmax(0,1fr)` em vez de cortar o texto.
    assert.equal(MOD_COMPOSICAO.CLASSES_LINHA_TEXTO, 'flex min-w-0 flex-col gap-[1px]', 'o wrapper de `:658` divergiu — sem `min-w-0` o detalhe não trunca');
    assert.ok(MOD_COMPOSICAO.CLASSES_LINHA_DETALHE.includes('truncate'), 'o detalhe de `:658` perdeu o `truncate` (os TRÊS de `overflow`/`ellipsis`/`nowrap`)');

    // ⚠️ O rodapé: o `justify-between` e o `items-center` que a tabela OMITIA.
    assert.ok(MOD_COMPOSICAO.CLASSES_RODAPE.includes('justify-between'), 'o rodapé de `:663` perdeu o `justify-content:space-between`');
    assert.ok(MOD_COMPOSICAO.CLASSES_RODAPE.includes('items-center'), 'o rodapé de `:663` perdeu o `align-items:center`');
    assert.ok(MOD_COMPOSICAO.CLASSES_RODAPE_VALOR.includes('font-bold'), 'o valor do rodapé perdeu o `font-weight:700`');
    assert.ok(MOD_COMPOSICAO.CLASSES_LINHA_VALOR.includes('font-medium'), 'o valor da linha perdeu o `font-weight:500`');

    // ⚠️ E a nota solta tem o `margin-top:-4px` de `:665`.
    assert.ok(MOD_COMPOSICAO.CLASSES_NOTA.includes('-mt-[4px]'), 'a nota de `:665` perdeu o `margin-top:-4px`');

    // ⚠️ O selo tem o `whitespace-nowrap` de `:659` (omitido SETE vezes na 187).
    assert.ok(MOD_COMPOSICAO.CLASSES_SELO.includes('whitespace-nowrap'), 'o selo de `:659` perdeu o `white-space:nowrap`');
    assert.ok(MOD_COMPOSICAO.CLASSES_FRETE.includes('whitespace-nowrap'), 'o botão de `:667` perdeu o `white-space:nowrap` (a 1ª propriedade da linha)');
    assert.ok(MOD_COMPOSICAO.CLASSES_FRETE.includes('cursor-pointer'), 'o botão de `:667` perdeu o `cursor:pointer`');
    assert.ok(MOD_COMPOSICAO.CLASSES_FRETE.includes('self-start'), 'o botão de `:667` perdeu o `align-self:flex-start`');
});

test('⚠️⚠️ REND: nenhum campo adverso da composição derruba a página, nem no CLIQUE 188_09', () => {
    const CAMPOS = ['chave', 'rotulo', 'detalhe', 'valor', 'valor_cor', 'origem', 'origem_rotulo', 'origem_cor', 'origem_borda', 'nota', 'pendente', 'informativa'];

    for (const campo of CAMPOS) {
        for (const forma of ADVERSAS) {
            let html = null;
            assert.doesNotThrow(
                () => { html = render(MOD_COMPOSICAO.default, { composicao: { ...COMPOSICAO_OK, linhas: [{ ...LINHAS_DA_CONTA[0], [campo]: forma }] } }); },
                `o campo \`linha.${campo}\` derrubou o render da composição`,
            );
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `o campo \`linha.${campo}\` vazou valor cru`);
        }
    }

    // ⚠️ O cabeçalho, o rodapé e as notas, bloco por bloco.
    for (const bloco of ['cabecalho', 'rodape', 'nota']) {
        for (const forma of ADVERSAS) {
            let html = null;
            assert.doesNotThrow(() => { html = render(MOD_COMPOSICAO.default, { composicao: { ...COMPOSICAO_OK, [bloco]: forma } }); }, `o bloco \`${bloco}\` derrubou o render`);
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined/, `o bloco \`${bloco}\` vazou valor cru`);
        }
    }

    // ⚠️ As COLEÇÕES adversas (`.map` em string LANÇA) e a prop inteira.
    for (const forma of [...ADVERSAS, [null], [{}], ['x'], [[]], [LINHAS_DA_CONTA[0], null, 'x']]) {
        let html = null;
        assert.doesNotThrow(() => { html = render(MOD_COMPOSICAO.default, { composicao: { ...COMPOSICAO_OK, linhas: forma, informativas: forma } }); }, 'a coleção de linhas derrubou o render');
        assert.doesNotMatch(html, /\[object Object\]|NaN|undefined/, 'a coleção de linhas vazou valor cru');

        let inteiro = null;
        assert.doesNotThrow(() => { inteiro = render(MOD_COMPOSICAO.default, { composicao: forma, preco: forma, frete: forma, freteAusente: forma, onInformarFrete: forma }); }, 'a prop inteira derrubou o render');
        assert.doesNotMatch(inteiro, /\[object Object\]|NaN|undefined/, 'a prop inteira vazou valor cru');
    }

    // ⚠️⚠️ E NO CLIQUE: `renderToStaticMarkup` **não dispara `onClick`**, então a
    // decisão do botão é exercida DIRETO — e ela é função PURA de propósito.
    for (const forma of ADVERSAS) {
        assert.doesNotThrow(
            () => MOD_COMPOSICAO.rotuloDoBotaoDeFrete(forma, forma, forma),
            'a decisão do botão de frete LANÇOU com entrada adversa',
        );
        assert.equal(MOD_COMPOSICAO.rotuloDoBotaoDeFrete(forma, forma, forma), null, 'a decisão do botão devolveu rótulo com entrada adversa');
    }
    // ⚠️ SONDA NÃO-CEGA: ela devolve o rótulo quando TUDO está certo.
    assert.equal(
        MOD_COMPOSICAO.rotuloDoBotaoDeFrete({ rotulo_acao: 'Informar frete confirmado' }, true, nada),
        'Informar frete confirmado',
        'a decisão do botão está CEGA: nem o caso certo ela aceita',
    );

    // ⚠️ O handler de verdade é chamado só no clique, nunca no render.
    let chamadas = 0;
    render(MOD_COMPOSICAO.default, { composicao: COMPOSICAO_OK, frete: { rotulo_acao: 'Informar frete confirmado' }, freteAusente: true, onInformarFrete: () => { chamadas += 1; } });
    assert.equal(chamadas, 0, 'o handler do frete foi chamado NO RENDER');
});

// ═══════════════════════════════════════════════════════════════════════════
// 13. A SIMULAÇÃO — o selo nos dois eixos, os atalhos e ZERO conta na tela
// ═══════════════════════════════════════════════════════════════════════════

/** O estado NEUTRO: nada digitado, sem selo, sem delta, sem salvar. */
const SIMULACAO_NEUTRA = {
    titulo: 'Simular preço',
    placeholder: '0,00',
    rotulo_descartar: 'Descartar',
    rotulo_salvar: 'Salvar no anúncio',
    simulando: false,
    selo: null,
    preco_salvo: 'R$ 149,90',
    preco_simulado: null,
    preenche: '',
    resultado: '—',
    resultado_cor: 'texto',
    margem: '—',
    margem_cor: 'neutro',
    delta: '',
    delta_cor: 'neutro',
    pode_salvar: false,
    versao: 7,
};

/** ⚠️ Simulando **e** `live`: é o único estado em que os dois botões nascem. */
const SIMULACAO_ATIVA = {
    ...SIMULACAO_NEUTRA,
    simulando: true,
    selo: 'SIMULAÇÃO · NÃO SALVA',
    preco_simulado: 'R$ 159,90',
    preenche: '159,90',
    resultado: 'R$ 31,52',
    resultado_cor: 'texto',
    margem: '19,7%',
    margem_cor: 'ambar',
    delta: '+ R$ 8,11 vs. salvo',
    delta_cor: 'verde',
    pode_salvar: true,
};

/** ⚠️ Simulando mas FORA do `live`: o selo aparece e os botões NÃO. */
const SIMULACAO_SEM_LIVE = { ...SIMULACAO_ATIVA, pode_salvar: false };

/**
 * ⚠️⚠️ Os atalhos com o mínimo em **20%**, não em 12%.
 *
 * O protótipo escreve *"Margem mínima 12%"* porque `MIN = 12` (`:914`). Com 12
 * aqui, um literal cravado na tela PASSARIA — e a tela mentiria para a empresa
 * que configurou outro mínimo (FIN-09).
 * ⚠️ E `preenche` do segundo é `ceil(alvo) − 0,1` (`:1140`): alvo R$ 118,43
 * preenche **118,90**, não 118,43. O cálculo é do SERVIDOR.
 */
const ATALHOS = [
    { chave: 'equilibrio', rotulo: 'Equilíbrio · R$ 104,37', preenche: '104,37' },
    { chave: 'margem_minima', rotulo: 'Margem mínima 20% · R$ 118,43', preenche: '118,90' },
];

test('⚠️⚠️ CRITÉRIO 7: o selo "SIMULAÇÃO · NÃO SALVA" só com `simulando`, e os botões nos DOIS eixos 188_09', () => {
    // ⚠️ `:1129` — o selo só existe quando o digitado DIFERE do salvo (tolerância
    // `0,001`). Com `>= 0` ele apareceria com o preço IGUAL ao salvo.
    const neutro = render(MOD_SIMULACAO.default, { simulacao: SIMULACAO_NEUTRA, onSimular: nada });
    assert.ok(!texto(neutro).includes('SIMULAÇÃO'), 'o selo apareceu SEM simulação ativa');
    assert.ok(texto(neutro).includes('Simular preço'), 'o título da caixa sumiu no estado neutro');

    const ativo = render(MOD_SIMULACAO.default, { simulacao: SIMULACAO_ATIVA, onSimular: nada, onDescartar: nada, onSalvar: nada });
    assert.ok(texto(ativo).includes('SIMULAÇÃO · NÃO SALVA'), 'o selo não apareceu com simulação ativa');

    // ⚠️⚠️ **A FIXTURE QUE DISCRIMINA, e ela nasceu da CAMPANHA DE MUTAÇÃO:** no
    // estado neutro o servidor manda `selo: null`, então a guarda de `simulando`
    // da TELA ficaria **inobservável** — a mutação que a remove SOBREVIVERIA, e o
    // gate estaria medindo o servidor, não a tela.
    // ➡️ Aqui o selo chega PREENCHIDO com `simulando: false`: é o payload
    // contraditório (ou velho) que a tela **não pode** acreditar. ⚠️ Com `>= 0` na
    // tolerância do servidor o selo apareceria com o preço IGUAL ao salvo, e
    // `pode_salvar` ficaria verdadeiro para salvar o que já está salvo.
    const contraditorio = render(MOD_SIMULACAO.default, {
        simulacao: { ...SIMULACAO_NEUTRA, selo: 'SIMULAÇÃO · NÃO SALVA', simulando: false },
        onSimular: nada,
    });
    assert.ok(
        !texto(contraditorio).includes('SIMULAÇÃO'),
        'o selo apareceu com `simulando: false` — a tela acreditou num selo que o servidor não acendeu',
    );
    // ⚠️ E com `simulando: true` ele aparece (sonda NÃO-CEGA do caso acima).
    const coerente = render(MOD_SIMULACAO.default, {
        simulacao: { ...SIMULACAO_NEUTRA, selo: 'SIMULAÇÃO · NÃO SALVA', simulando: true },
        onSimular: nada,
    });
    assert.ok(texto(coerente).includes('SIMULAÇÃO'), 'a sonda do selo está CEGA: nem com `simulando: true` ela o vê');

    // ⚠️ O selo é LITERAL do servidor, com o `·` U+00B7 — a tela não o escreve.
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        assert.ok(!/SIMULA[ÇC][ÃA]O/.test(fonte), `${rel} escreveu o selo à mão — ele é PrecoDoPainelService::SIMULACAO_SELO`);
    }
    const servico = fs.readFileSync(path.resolve(RAIZ, REL_SERVICO_PRECO), 'utf8');
    assert.match(servico, /SIMULACAO_SELO = 'SIMULAÇÃO · NÃO SALVA';/, 'o selo mudou no servidor — remeça o literal');
    assert.match(servico, /TOLERANCIA_DO_SIMULADO = 0\.001;/, 'a tolerância de `:1129` mudou no servidor');

    // ⚠️⚠️ OS DOIS EIXOS DOS BOTÕES — e são QUATRO combinações, com só UMA
    // desenhando os dois. `pode_salvar` já é `simOn && live` no servidor (`:1142`).
    const casos = [
        { simulacao: SIMULACAO_ATIVA, handlers: true, botoes: 2 },
        { simulacao: SIMULACAO_ATIVA, handlers: false, botoes: 0 },
        { simulacao: SIMULACAO_SEM_LIVE, handlers: true, botoes: 0 },
        { simulacao: SIMULACAO_NEUTRA, handlers: true, botoes: 0 },
    ];
    for (const caso of casos) {
        const html = render(MOD_SIMULACAO.default, {
            simulacao: caso.simulacao,
            onSimular: nada,
            onDescartar: caso.handlers ? nada : null,
            onSalvar: caso.handlers ? nada : null,
        });
        const botoes = tagsDeAbertura(html).filter((t) => t.tag === 'button');
        assert.equal(botoes.length, caso.botoes, `pode_salvar=${caso.simulacao.pode_salvar} handlers=${caso.handlers}: esperava ${caso.botoes} botão(ões)`);
        const semDisabled = botoes.filter((t) => t.desabilitado);
        assert.deepEqual(semDisabled.map((t) => t.classe), [], 'botão da simulação nasceu `disabled` — `disabled` decorativo foi RECUSADO');
    }

    // ⚠️ E o delta sai com o sufixo ` vs. salvo`, do servidor.
    assert.ok(texto(ativo).includes('vs. salvo'), 'o delta perdeu o sufixo ` vs. salvo` de `:1136`');
    assert.match(servico, /DELTA_SUFIXO = ' vs\. salvo';/, 'o sufixo do delta mudou no servidor');
});

test('⚠️⚠️ D4: sem o transporte da wave 188-06 a caixa de simulação NÃO é desenhada 188_09', () => {
    // ⚠️⚠️ Campo de digitação sem destino é CONTROLE MORTO — a pessoa digita e
    // nada acontece, que é pior que a ausência. E caixa tracejada com só o título
    // dentro é casca decorativa (D4). ⚠️ "Desenhar desabilitado com explicação" foi
    // OFERECIDO e RECUSADO na PERGUNTA-188-B3.
    for (const semTransporte of [null, undefined, {}, 'x', 7, true, []]) {
        const html = render(MOD_SIMULACAO.default, { simulacao: SIMULACAO_ATIVA, atalhos: ATALHOS, onSimular: semTransporte });
        assert.equal(html, '', 'a caixa de simulação nasceu SEM transporte — campo sem destino é controle morto (D4)');
    }

    // ⚠️ COM transporte: um input, e ele é o de 120×32 de `:675`.
    const html = render(MOD_SIMULACAO.default, { simulacao: SIMULACAO_ATIVA, atalhos: ATALHOS, onSimular: nada });
    const inputs = tagsDeAbertura(html).filter((t) => t.tag === 'input');
    assert.equal(inputs.length, 1, `não há exatamente 1 input de simulação: ${inputs.length}`);
    const doInput = classesDe(inputs[0]);
    assert.ok(doInput.has('w-[120px]'), 'o input não tem os 120px de `:675`');
    assert.ok(doInput.has('h-[32px]'), 'o input não tem os 32px de `:675`');
    assert.ok(doInput.has('pl-[32px]'), 'o input perdeu o `padding-left:32px` que abre espaço para o `R$`');
    assert.ok(doInput.has('focus:border-pub-accent'), 'o input perdeu o `style-focus` de `:675`');
    assert.ok(texto(html).includes('R$'), 'o prefixo `R$` absoluto de `:675` sumiu');

    // ⚠️ E a página DECLARA que o transporte é de outra wave, em vez de inventar
    // um `onClick` vazio. ⚠️ A leitura é do arquivo CRU: o docblock é o artefato.
    const paginaCrua = fs.readFileSync(path.resolve(RAIZ, REL_PAGINA), 'utf8');
    assert.match(paginaCrua, /O TRANSPORTE DA SIMULAÇÃO E DO "CONFERIR ALTERAÇÃO" É DA WAVE/, 'a página não DECLARA de quem é o transporte — a próxima pessoa teria de achar por bisseção');
    assert.match(FONTE_PAGINA, /onSimular=\{null\}/, 'a página passou a mandar um `onSimular` — se a wave 188-06 fechou, religue pelo helper `escreverNoPainel`');
    assert.match(FONTE_PAGINA, /colunaDePreco=\{colunaDePreco\}/, 'a página não ligou o slot `colunaDePreco`');
});

test('⚠️⚠️ T-188-82: ZERO aritmética de taxa na tela, lida SEM comentário 188_09', () => {
    // ⚠️⚠️ A tela EXIBE; ela NÃO calcula. Conta no cliente divergiria da conta do
    // servidor na primeira edição — num campo em que **salvar troca o preço de
    // verdade e não há desfazer**.
    const ARITMETICA = [
        /\*\s*0\.1[0-9]/, /\*\s*0\.0[0-9]/, /\/\s*\(\s*1\s*-/, /\b6\.75\b/, /\b0\.165\b/,
        /\*\s*\(\s*1\s*-/, /\bMath\.round\(/, /\btoFixed\(/,
    ];

    const infratores = [];
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        for (const re of ARITMETICA) {
            if (re.test(fonte)) infratores.push(`${rel} ~ ${re}`);
        }
    }
    assert.deepEqual(infratores, [], 'apareceu aritmética de taxa na tela — ela EXIBE o que o servidor devolve');

    // ⚠️ ISCA POSITIVA: a sonda PEGA as formas erradas.
    for (const cebo of ['const taxa = preco * 0.165', 'return valor / (1 - margem)', 'const fixa = 6.75', 'n.toFixed(2)']) {
        assert.ok(ARITMETICA.some((re) => re.test(cebo)), `a sonda de aritmética NÃO pega o cebo \`${cebo}\``);
    }
    // ⚠️ ISCA NEGATIVA: ela NÃO pega as formas legítimas desta coluna.
    for (const legitimo of ['lista.length < 2', 'ofertaId > 0', 'indice > 0', "dados.pode_salvar === true"]) {
        assert.ok(!ARITMETICA.some((re) => re.test(legitimo)), `a sonda de aritmética reprova a forma LEGÍTIMA \`${legitimo}\``);
    }

    // ⚠️⚠️ E A LEITURA É SEM COMENTÁRIO, DE PROPÓSITO: o docblock de
    // `SimulacaoDePreco.jsx` **cita** `* 0.12` e `/ (1 - margem)` para explicar
    // que eles NÃO existem ali — leitura CRUA reprovaria pelo próprio conserto, e
    // TRÊS mutações da wave 187-04 abortaram exatamente por isso.
    const cru = fs.readFileSync(path.resolve(RAIZ, REL_SIMULACAO), 'utf8');
    assert.ok(ARITMETICA.some((re) => re.test(cru)), 'o docblock deixou de explicar que não há conta aqui — sem ele a próxima pessoa não acha o motivo por grep');
    assert.ok(!ARITMETICA.some((re) => re.test(FONTE_SIMULACAO)), 'a leitura SEM comentário não está limpando o docblock');
});

test('⚠️⚠️ FIN-09: o `N%` do atalho vem do mínimo REAL, e o `ceil(alvo) − 0,1` é do SERVIDOR 188_09', () => {
    const html = render(MOD_SIMULACAO.default, { simulacao: SIMULACAO_NEUTRA, atalhos: ATALHOS, onSimular: nada });
    const conteudo = texto(html);

    // ⚠️ A fixture usa mínimo **20%**, não 12%: com 12 um literal cravado passaria.
    assert.ok(conteudo.includes('Margem mínima 20%'), 'o rótulo do atalho não traz o mínimo REAL — ele é do servidor (FIN-09)');
    assert.ok(!conteudo.includes('12%'), 'apareceu `12%` com mínimo 20 — alguém cravou o `MIN` do protótipo');
    assert.ok(conteudo.includes('Equilíbrio · R$ 104,37'), 'o atalho de equilíbrio não saiu');

    // ⚠️ Nenhum dos dois rótulos é escrito na tela.
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        assert.ok(!/Margem m[íi]nima/.test(fonte), `${rel} escreveu o rótulo da margem mínima à mão`);
        assert.ok(!/Equil[íi]brio/.test(fonte), `${rel} escreveu o rótulo do equilíbrio à mão`);
        assert.ok(!/\b12\b\s*%/.test(fonte), `${rel} cravou o 12% do protótipo`);
    }

    // ⚠️⚠️ E O `ceil(alvo) − 0,1` É DO SERVIDOR (`:1140`, ponteiro corrigido pela
    // §L do FID01 — o plano dizia `:1139`, que é o `onSimBE`, SEM o ajuste). A
    // tela só repassa `atalho.preenche`: R$ 118,43 preenche **118,90**.
    const servico = fs.readFileSync(path.resolve(RAIZ, REL_SERVICO_PRECO), 'utf8');
    assert.match(servico, /\$preenche = ceil\(\$alvo\) - 0\.1;/, 'o `ceil(alvo) − 0,1` saiu do servidor — a tela passaria a arredondar');
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        assert.ok(!/Math\.ceil\(/.test(fonte), `${rel} arredondou na tela — o cálculo é do servidor`);
    }
    // ⚠️ O atalho PREENCHE pelo mesmo caminho da digitação (uma porta só).
    assert.match(FONTE_SIMULACAO, /onClick=\{\(\) => simular\(preenche\)\}/, 'o atalho deixou de preencher pelo caminho da digitação');

    // ⚠️ Sem atalhos, a tela DIZ POR QUÊ — e o texto é do servidor.
    const semAtalhos = render(MOD_SIMULACAO.default, {
        simulacao: SIMULACAO_NEUTRA,
        atalhos: [],
        atalhosIndisponivel: 'Preço de equilíbrio indisponível até o frete ser confirmado.',
        onSimular: nada,
    });
    const semConteudo = texto(semAtalhos);
    assert.ok(semConteudo.includes('Preço de equilíbrio indisponível até o frete ser confirmado.'), 'a frase de `:685` não saiu');
    assert.ok(!semConteudo.includes('Equilíbrio ·'), 'o atalho apareceu com a lista VAZIA');
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        assert.ok(!/indispon[íi]vel at[ée]/.test(fonte), `${rel} escreveu a frase de \`:685\` à mão`);
    }
    assert.match(servico, /EQUILIBRIO_INDISPONIVEL = 'Preço de equilíbrio indisponível até o frete ser confirmado\.';/, 'a frase de `:685` mudou no servidor');
});

test('⚠️ FID: a caixa de simulação é TRACEJADA, e os atalhos são PÍLULA com `flex-wrap` 188_09', () => {
    // ⚠️ IGUALDADE de string, com isca do quase-igual.
    assert.equal(
        MOD_SIMULACAO.CLASSES_CAIXA,
        'flex flex-col gap-[8px] rounded-pub-sub border border-dashed border-pub-line-strong p-[12px]',
        'a caixa de simulação divergiu de `:669`',
    );
    assert.ok(MOD_SIMULACAO.CLASSES_CAIXA.includes('border-dashed'), 'a caixa perdeu o `border:1px dashed` — é ele que a distingue das outras');

    // ⚠️ `border-radius:999px` é `rounded-full`, não `rounded-pub-chip` (6px).
    assert.ok(MOD_SIMULACAO.CLASSES_ATALHO.includes('rounded-full'), 'o atalho perdeu o `border-radius:999px` de `:681`');
    assert.ok(!MOD_SIMULACAO.CLASSES_ATALHO.includes('rounded-pub-chip'), 'o atalho virou `rounded-pub-chip` (6px) — ele é pílula');
    assert.ok(MOD_SIMULACAO.CLASSES_ATALHO.includes('h-[26px]'), 'o atalho perdeu a altura de 26px');

    // ⚠️⚠️ `flex-wrap` é LOAD-BEARING (D26w, que a tabela §D OMITIA): com os dois
    // rótulos longos eles quebram para a 2ª linha em vez de VAZAR a coluna.
    assert.ok(MOD_SIMULACAO.CLASSES_ATALHOS.includes('flex-wrap'), 'o wrapper dos atalhos perdeu o `flex-wrap:wrap` de `:680` — os rótulos longos vazariam a coluna');

    // ⚠️ O `margin-left:auto` de `:677` que empurra os botões para a direita.
    assert.ok(MOD_SIMULACAO.CLASSES_ACOES.includes('ml-auto'), 'o wrapper dos botões perdeu o `margin-left:auto` de `:677`');
    // ⚠️ "Descartar" é `border:none`, e "Salvar no anúncio" tem borda — são DOIS.
    assert.ok(MOD_SIMULACAO.CLASSES_DESCARTAR.includes('border-none'), 'o "Descartar" ganhou borda — `:677` diz `border:none`');
    assert.ok(MOD_SIMULACAO.CLASSES_SALVAR.includes('border-pub-line-off'), 'o "Salvar no anúncio" perdeu a borda `#3F3F46` de `:677`');
    assert.notEqual(MOD_SIMULACAO.CLASSES_DESCARTAR, MOD_SIMULACAO.CLASSES_SALVAR, 'os dois botões ficaram iguais — eles têm aparências DIFERENTES');

    // ⚠️ O wrapper INTERNO de `:676` que empilha o resultado e o delta (gap 1px).
    assert.ok(MOD_SIMULACAO.CLASSES_RESULTADO.includes('gap-[1px]'), 'o wrapper interno de `:676` perdeu o `gap:1px`');
    // ⚠️ O separador `· ` é U+00B7.
    assert.equal(MOD_SIMULACAO.SEPARADOR, '· ', 'o separador de `:676` não é U+00B7 seguido de espaço');
});

// ═══════════════════════════════════════════════════════════════════════════
// 14. A IRMÃ — clicável, na ordem certa, com DUAS aparências
// ═══════════════════════════════════════════════════════════════════════════

/** ⚠️ A célula ATUAL: `href` **null** — ela não navega para si mesma (`:1294`). */
const CELULA_ATUAL = {
    oferta_id: 4101,
    rotulo: 'Clássico',
    premium: false,
    ordem: 0,
    preco: 'R$ 149,90',
    resultado: 'R$ 23,41',
    resultado_cor: 'texto',
    margem: '15,6%',
    margem_cor: 'clara',
    andamento_da_irma: 'em_revisao_ecf',
    rotulo_do_andamento: 'Em revisão pela ECF',
    atual: true,
    href: null,
};

/** ⚠️ A IRMÃ: Premium (losango), com `href` e números DIFERENTES da atual. */
const CELULA_IRMA = {
    oferta_id: 4102,
    rotulo: 'Premium',
    premium: true,
    ordem: 1,
    preco: 'R$ 179,90',
    resultado: '− R$ 4,20',
    resultado_cor: 'vermelho',
    margem: '−2,3%',
    margem_cor: 'vermelho',
    andamento_da_irma: 'aguardando_aprovacao',
    rotulo_do_andamento: 'Aguardando aprovação',
    atual: false,
    href: '?oferta=4102',
};

test('⚠️⚠️ REVS-11 / FIN-13: a irmã é CLICÁVEL, na ordem Clássico → Premium, e a ATUAL não navega 188_09', () => {
    let abertas = [];
    const html = render(MOD_IRMA.default, {
        comparacao: [CELULA_ATUAL, CELULA_IRMA],
        irma: CELULA_IRMA,
        onAbrir: (id) => { abertas = [...abertas, id]; },
    });
    const conteudo = texto(html);

    // ⚠️ A ORDEM é do servidor: Clássico (posição 0) antes de Premium (posição 1).
    assert.ok(conteudo.indexOf('Clássico') < conteudo.indexOf('Premium'), 'a ordem Clássico → Premium se inverteu (`:1294`)');

    // ⚠️⚠️ A PROVA DE QUE A ORDEM É A DO SERVIDOR: com a lista invertida, a tela
    // inverte. Uma ordenação interna passaria acima e reprovaria aqui.
    const invertido = texto(render(MOD_IRMA.default, { comparacao: [CELULA_IRMA, CELULA_ATUAL], irma: CELULA_IRMA }));
    assert.ok(invertido.indexOf('Premium') < invertido.indexOf('Clássico'), 'a tela tem a sua própria ordem — ela seria a terceira lista do mesmo fato');

    // ⚠️ E a ordem vem da CONSTANTE no servidor, nunca de `orderBy('id')`.
    const servico = fs.readFileSync(path.resolve(RAIZ, REL_SERVICO_PRECO), 'utf8');
    assert.match(servico, /array_keys\(EstruturaPublicacao::LISTING_TYPES\)/, 'a ordem das irmãs deixou de vir da CONSTANTE');
    // ⚠️⚠️ **DEFEITO DA PRÓPRIA SONDA, ACHADO POR ELA — e é a regra 2 da §6, agora
    // num arquivo PHP:** a primeira redação procurava `orderBy('id')` no arquivo
    // CRU e REPROVOU, porque o docblock de `PrecoDoPainelService:1138` **cita**
    // `nunca orderBy('id')` para explicar que ele NÃO é usado. ➡️ *"Palavra no
    // DOCBLOCK que explica 'não uso isso' reprova pelo próprio conserto"* — a
    // leitura tem de ser SEM COMENTÁRIO. (TRÊS mutações da wave 187-04 abortaram
    // pelo mesmo motivo, e esta é a quarta ocorrência da família nesta fase.)
    const servicoSemComentario = lerSemComentarios(REL_SERVICO_PRECO);
    assert.ok(!/orderBy\('id'\)/.test(servicoSemComentario), 'o servidor passou a ordenar por `id` — dependeria de qual oferta foi criada primeiro');
    // ⚠️ ISCA DA LEITURA: o docblock CONTINUA explicando a proibição (é por grep
    // que a próxima pessoa acha o motivo), e a leitura sem comentário o limpa.
    assert.ok(/orderBy\('id'\)/.test(servico), 'o docblock deixou de explicar por que a ordenacao por id e proibida ali');
    // ⚠️ E o invariante POSITIVO: a ordenação é por `ordem`, com a chave
    // desconhecida indo para o FIM (`PHP_INT_MAX`), nunca para a frente.
    assert.match(servicoSemComentario, /\$a\['ordem'\] <=> \$b\['ordem'\]/, 'a ordenação das irmãs deixou de usar a posição da CONSTANTE');
    assert.match(servicoSemComentario, /\$ordem === false \? PHP_INT_MAX : \$ordem/, 'chave de modalidade desconhecida deixou de ir para o FIM');

    // ⚠️⚠️ SÓ A IRMÃ É LINK: a atual tem `href` null no servidor e sai como
    // elemento NÃO clicável (`:1294`, `x.id !== o.id`).
    const links = tagsDeAbertura(html).filter((t) => t.tag === 'a');
    assert.equal(links.length, 1, `não há exatamente 1 célula clicável: ${links.length}`);
    assert.equal(links[0].href, '?oferta=4102', 'o `href` da irmã não é o `?oferta=` do servidor');
    assert.ok(classesDe(links[0]).has('cursor-pointer'), 'a irmã perdeu o `cursor:pointer` de `:690`');

    // ⚠️⚠️ AS DUAS APARÊNCIAS, DIFERENTES — e por ÁRVORE com índice ABSOLUTO.
    const celulas = tagsDeAbertura(html).filter((t) => /py-\[10px\]/.test(t.classe));
    assert.equal(celulas.length, 2, `não há exatamente 2 células: ${celulas.length}`);
    const daAtual = classesDe(celulas[0]);
    const daOutra = classesDe(celulas[1]);
    assert.ok(daAtual.has('bg-pub-hover-3'), 'a célula ATUAL perdeu o fundo `#1A1A1A` de `:1294`');
    assert.ok(daAtual.has('border-pub-line-off'), 'a célula ATUAL perdeu a borda `#3F3F46` de `:1294`');
    assert.ok(daOutra.has('bg-pub-surface'), 'a OUTRA perdeu o fundo `#111111` de `:1294`');
    assert.ok(daOutra.has('border-pub-line-subtle'), 'a OUTRA perdeu a borda `#1F1F1F` de `:1294`');
    assert.notDeepEqual([...daAtual].sort(), [...daOutra].sort(), 'as DUAS células ficaram com a MESMA aparência — a pessoa perderia de vista em qual ela está');

    // ⚠️ O losango SÓ no Premium, 6px e SEM `border-radius` (medido em `:691`).
    const losangos = tagsDeAbertura(html).filter((t) => /rotate-45/.test(t.classe));
    assert.equal(losangos.length, 1, `o losango não é exclusivo do Premium: ${losangos.length}`);
    assert.ok(classesDe(losangos[0]).has('h-[6px]'), 'o losango da irmã não tem 6px — os outros dois do módulo têm 7px, e são elementos DIFERENTES');
    assert.ok(!classesDe(losangos[0]).has('rounded-[1px]'), 'o losango de `:691` ganhou `border-radius` — ele não tem');

    // ⚠️⚠️ E O CLIQUE CHAMA O HANDLER COM O ID DA IRMÃ, uma vez.
    assert.deepEqual(abertas, [], 'o handler foi chamado NO RENDER');
    const nomes = Object.keys(MOD_IRMA);
    assert.ok(nomes.includes('CelulaDaComparacao'), 'a célula não é NAMED EXPORT — o gate não poderia exercer o clique');

    // ⚠️ A grade por IGUALDADE de string, com isca do quase-igual.
    assert.equal(MOD_IRMA.CLASSES_GRADE, 'grid grid-cols-[repeat(2,minmax(0,1fr))] gap-[6px]', 'a grade das irmãs divergiu de `:688`');
    for (const quaseIgual of ['grid-cols-2', 'grid-cols-[repeat(2,1fr)]', 'grid-cols-[repeat(3,minmax(0,1fr))]']) {
        assert.ok(!FONTE_IRMA.includes(quaseIgual), `o quase-igual \`${quaseIgual}\` entrou na fonte`);
    }
    assert.ok(FONTE_IRMA.includes('grid-cols-[repeat(2,minmax(0,1fr))]'), 'a sonda do quase-igual está cega');
});

test('⚠️⚠️ SEM IRMÃ, SEM GRADE: 2 colunas com um item só é casca vazia 188_09', () => {
    // ⚠️ O servidor SEMPRE manda a célula atual, e `irma` é `null` quando não há a
    // outra modalidade. Desenhar a grade com um item deixaria meia tela de borda.
    for (const semIrma of [null, undefined, '', 7, [], 'x', true]) {
        const html = render(MOD_IRMA.default, { comparacao: [CELULA_ATUAL], irma: semIrma, onAbrir: nada });
        assert.equal(html, '', 'a grade das irmãs nasceu SEM irmã — 2 colunas com um item é casca vazia (D4)');
    }

    // ⚠️ E com `irma` presente mas a lista TORTA (uma célula só), também não: as
    // duas chaves vêm do MESMO lugar no servidor, e exigir as duas é o que impede
    // a grade de nascer torta.
    assert.equal(render(MOD_IRMA.default, { comparacao: [CELULA_ATUAL], irma: CELULA_IRMA }), '', 'a grade nasceu com UMA célula e `irma` preenchida — as duas chaves discordaram');

    // ⚠️ SONDA NÃO-CEGA: com as DUAS ela nasce.
    assert.ok(render(MOD_IRMA.default, { comparacao: [CELULA_ATUAL, CELULA_IRMA], irma: CELULA_IRMA }) !== '', 'a grade não nasce nem com as duas — a sonda está CEGA');

    // ⚠️ E `irma` é DERIVADA de `comparacao` no servidor, para que nunca discordem.
    const servico = fs.readFileSync(path.resolve(RAIZ, REL_SERVICO_PRECO), 'utf8');
    assert.match(servico, /'irma' => \$irmas\[0\] \?\? null/, 'a `irma` deixou de ser DERIVADA de `comparacao` — as duas chaves poderiam discordar');
});

test('⚠️ VOC: o rótulo do andamento da irmã é do SERVIDOR, e a COR vem do módulo 188_09', () => {
    const html = render(MOD_IRMA.default, { comparacao: [CELULA_ATUAL, CELULA_IRMA], irma: CELULA_IRMA });
    const conteudo = texto(html);

    assert.ok(conteudo.includes('Em revisão pela ECF'), 'o rótulo do andamento da atual não saiu');
    assert.ok(conteudo.includes('Aguardando aprovação'), 'o rótulo do andamento da irmã não saiu');

    // ⚠️ UM VOCABULÁRIO SÓ: nenhum rótulo de andamento é escrito aqui.
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        assert.ok(!/Em revis[ãa]o/.test(fonte), `${rel} escreveu um rótulo de andamento à mão`);
        assert.ok(!/Aguardando aprova/.test(fonte), `${rel} escreveu um rótulo de andamento à mão`);
    }

    // ⚠️⚠️ `andamentoDe()` NUNCA devolve `null` e o rótulo dele NUNCA é vazio: uma
    // guarda que devolvesse `null` produziria elemento vazio, que **passa em
    // render e desaparece na tela**.
    const MOD_ANDAMENTO = MOD_IRMA;
    assert.ok(MOD_ANDAMENTO !== null);
    for (const chave of [null, undefined, {}, [], NaN, '', '   ', 7, true, 'chave_que_nao_existe']) {
        const semRotulo = render(MOD_IRMA.default, {
            comparacao: [CELULA_ATUAL, { ...CELULA_IRMA, andamento_da_irma: chave, rotulo_do_andamento: null }],
            irma: CELULA_IRMA,
        });
        const status = tagsDeAbertura(semRotulo).filter((t) => /ml-auto/.test(t.classe));
        assert.equal(status.length, 2, 'o selo de status desapareceu com andamento adverso — elemento vazio passa em render e some na tela');
    }

    // ⚠️ A COR do status vem do módulo (TOK-15: nenhum hex sai do PHP), e é a do
    // andamento — não um cinza genérico.
    const status = tagsDeAbertura(html).filter((t) => /ml-auto/.test(t.classe));
    assert.equal(status.length, 2, 'não há 2 selos de status');
    const cores = status.map((t) => [...classesDe(t)].filter((c) => c.startsWith('text-pub-')));
    assert.deepEqual(cores.map((c) => c.length), [1, 1], 'o selo de status não tem exatamente 1 classe de cor');
    assert.notEqual(cores[0][0], cores[1][0], 'os dois andamentos saíram com a MESMA cor — eles são estados diferentes');
    const doAndamento = lerSemComentarios(REL_ANDAMENTO);
    assert.ok(doAndamento.includes('em_revisao_ecf'), 'o andamento `em_revisao_ecf` saiu do módulo — a cor viria do nada');
});

test('⚠️⚠️ DECISÃO 6: "exceção aguardando autorização" NÃO EXISTE, e a frase é do SERVIDOR 188_09', () => {
    const html = render(MOD_COLUNA.default, {
        preco: { ...PRECO_OK, excecao: '§ Exceção autorizada por Rafael Lima · 17 out 09:12 · “cliente aceitou a margem menor no lote de Natal”' },
    });
    const conteudo = texto(html);
    assert.ok(conteudo.includes('Exceção autorizada por Rafael Lima'), 'a frase da exceção não saiu');
    assert.ok(conteudo.includes('§'), 'o glifo `§` da exceção sumiu');

    // ⚠️ Sem exceção, sem linha — nada nasce decorativo.
    for (const sem of [null, undefined, '', '   ', {}, [], 7]) {
        const semExcecao = render(MOD_COLUNA.default, { preco: { ...PRECO_OK, excecao: sem } });
        assert.ok(!texto(semExcecao).includes('§'), 'a linha da exceção nasceu SEM exceção');
    }

    // ⚠️⚠️ **A EXCEÇÃO SÓ REGISTRA QUEM LIBEROU** (decisão 6): não há estado de
    // espera. ⚠️ A sonda é ancorada no SUJEITO, e não em palavras soltas:
    // *"Aguardando aprovação"* é vocabulário CORRETO do domínio (é um dos 8
    // andamentos) e casa dez ocorrências legítimas. ⚠️ E `aprovad` NÃO casa
    // `aprovação` — conte com a cedilha e os acentos.
    const PROIBIDAS = [
        /exce[çc][ãa]o\s+aguardando/i,
        /aguardando\s+autoriza[çc][ãa]o/i,
        /exce[çc][ãa]o\s+pendente/i,
        /autoriza[çc][ãa]o\s+pendente/i,
    ];
    const infratores = [];
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        for (const re of PROIBIDAS) {
            if (re.test(fonte)) infratores.push(`${rel} ~ ${re}`);
        }
    }
    assert.deepEqual(infratores, [], '"exceção aguardando autorização" NÃO EXISTE (decisão 6) — a exceção só REGISTRA quem liberou');

    // ⚠️ ISCA POSITIVA: a sonda pega as quatro formas, COM e SEM acento.
    for (const cebo of ['Exceção aguardando autorização', 'excecao aguardando aprovacao', 'aguardando autorizacao do gestor', 'Exceção pendente de análise']) {
        assert.ok(PROIBIDAS.some((re) => re.test(cebo)), `a sonda da exceção NÃO pega o cebo "${cebo}"`);
    }
    // ⚠️⚠️ ISCA NEGATIVA — ela NÃO pega o vocabulário CORRETO do domínio: o
    // andamento "Aguardando aprovação" é um dos OITO e aparece em produção. Uma
    // sonda por palavra solta reprovaria código certo (é a 10ª ocorrência dessa
    // família neste projeto, e uma delas BARROU UM DEPLOY).
    for (const legitimo of ['Aguardando aprovação', 'aguardando_aprovacao', 'Aprovado — aguardando publicação', 'aguardando publicação']) {
        assert.ok(!PROIBIDAS.some((re) => re.test(legitimo)), `a sonda da exceção reprova o vocabulário LEGÍTIMO "${legitimo}"`);
    }

    // ⚠️ E a frase é LIDA de `PubAprovacaoExcecao::fraseDeExibicao()`, que trata
    // `liberada_por` nulo SEM mentir. A tela não remonta nada.
    const modelo = fs.readFileSync(path.resolve(RAIZ, 'app/Models/PubAprovacaoExcecao.php'), 'utf8');
    assert.match(modelo, /Exceção autorizada por/, 'a frase da exceção saiu do modelo');
    assert.match(modelo, /responsável removido/, 'o tratamento de `liberada_por` nulo saiu do modelo — a frase passaria a mentir');
    for (const [rel, fonte] of FONTES_DO_PLANO) {
        assert.ok(!/Exce[çc][ãa]o autorizada/.test(fonte), `${rel} remontou a frase da exceção`);
    }
    // ⚠️ E `LIBERA` continua a lista FECHADA de DOIS (decisão 6): só prejuízo e
    // margem — nunca fotos, frete, alteração, ajuste ou título.
    const bloco = modelo.match(/LIBERA = \[([\s\S]*?)\];/);
    assert.ok(bloco, 'não achei `LIBERA` — a leitura está CEGA');
    for (const nunca of ['fotos', 'frete', 'alteracao', 'ajuste', 'titulo']) {
        assert.ok(!bloco[1].includes(nunca), `a exceção passou a liberar \`${nunca}\` — a lista é FECHADA em prejuízo e margem (decisão 6)`);
    }
});

test('⚠️⚠️ REND: nenhum campo adverso da simulação nem da irmã derruba a página, nem no CLIQUE 188_09', () => {
    const DA_SIMULACAO = ['titulo', 'placeholder', 'rotulo_descartar', 'rotulo_salvar', 'simulando', 'selo', 'preco_salvo', 'preco_simulado', 'preenche', 'resultado', 'resultado_cor', 'margem', 'margem_cor', 'delta', 'delta_cor', 'pode_salvar', 'versao'];

    for (const campo of DA_SIMULACAO) {
        for (const forma of ADVERSAS) {
            let html = null;
            assert.doesNotThrow(
                () => { html = render(MOD_SIMULACAO.default, { simulacao: { ...SIMULACAO_ATIVA, [campo]: forma }, atalhos: ATALHOS, onSimular: nada, onDescartar: nada, onSalvar: nada }); },
                `o campo \`simulacao.${campo}\` derrubou o render`,
            );
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `o campo \`simulacao.${campo}\` vazou valor cru`);
        }
    }

    // ⚠️ Os ATALHOS, campo por campo, e a coleção inteira.
    for (const campo of ['chave', 'rotulo', 'preenche']) {
        for (const forma of ADVERSAS) {
            let html = null;
            assert.doesNotThrow(() => { html = render(MOD_SIMULACAO.default, { simulacao: SIMULACAO_NEUTRA, atalhos: [{ ...ATALHOS[0], [campo]: forma }], onSimular: nada }); }, `o campo \`atalho.${campo}\` derrubou o render`);
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined/, `o campo \`atalho.${campo}\` vazou valor cru`);
        }
    }
    for (const forma of [...ADVERSAS, [null], [{}], ['x'], [[]], [ATALHOS[0], null, 'x']]) {
        let html = null;
        assert.doesNotThrow(() => { html = render(MOD_SIMULACAO.default, { simulacao: SIMULACAO_NEUTRA, atalhos: forma, atalhosIndisponivel: forma, onSimular: nada }); }, 'a coleção de atalhos derrubou o render');
        assert.doesNotMatch(html, /\[object Object\]|NaN|undefined/, 'a coleção de atalhos vazou valor cru');
    }

    // ⚠️ A IRMÃ, campo por campo — e com objeto NO MEIO da coleção.
    const DA_IRMA = ['oferta_id', 'rotulo', 'premium', 'ordem', 'preco', 'resultado', 'resultado_cor', 'margem', 'margem_cor', 'andamento_da_irma', 'rotulo_do_andamento', 'atual', 'href'];
    for (const campo of DA_IRMA) {
        for (const forma of ADVERSAS) {
            let html = null;
            assert.doesNotThrow(
                () => { html = render(MOD_IRMA.default, { comparacao: [CELULA_ATUAL, { ...CELULA_IRMA, [campo]: forma }], irma: CELULA_IRMA, onAbrir: nada }); },
                `o campo \`irma.${campo}\` derrubou o render`,
            );
            assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `o campo \`irma.${campo}\` vazou valor cru`);
        }
    }
    for (const forma of [...ADVERSAS, [null], [{}], ['x'], [[]], [CELULA_ATUAL, null], [CELULA_ATUAL, 'x', CELULA_IRMA]]) {
        let html = null;
        assert.doesNotThrow(() => { html = render(MOD_IRMA.default, { comparacao: forma, irma: forma, onAbrir: forma }); }, 'a coleção de irmãs derrubou o render');
        assert.doesNotMatch(html, /\[object Object\]|NaN|undefined/, 'a coleção de irmãs vazou valor cru');
    }

    // ⚠️⚠️ E NO CLIQUE: `renderToStaticMarkup` **não dispara `onClick`**, e a TELA
    // PRETA deste projeto voltou SEIS vezes — a última NO CLIQUE. A célula é
    // NAMED EXPORT exatamente para o clique ser exercido direto.
    for (const forma of ADVERSAS) {
        assert.doesNotThrow(
            () => render(MOD_IRMA.CelulaDaComparacao, { celula: { ...CELULA_IRMA, oferta_id: forma }, onAbrir: forma }),
            'o clique da irmã derrubou o render com entrada adversa',
        );
        assert.doesNotThrow(
            () => render(MOD_SIMULACAO.default, { simulacao: SIMULACAO_ATIVA, onSimular: nada, onDescartar: forma, onSalvar: forma }),
            'os handlers adversos da simulação derrubaram o render',
        );
    }

    // ⚠️⚠️ **`id = 0` PASSA pela guarda de inteiro, e ZERO é um id PLAUSÍVEL** —
    // conserto MEDIDO pela wave 188-08. A navegação exige inteiro POSITIVO.
    const comZero = render(MOD_IRMA.default, { comparacao: [CELULA_ATUAL, { ...CELULA_IRMA, oferta_id: 0 }], irma: CELULA_IRMA, onAbrir: nada });
    const linksComZero = tagsDeAbertura(comZero).filter((t) => t.tag === 'a');
    assert.equal(linksComZero.length, 1, 'a célula com id 0 deixou de ser link — o `href` do servidor continua sendo destino');
    assert.ok(FONTE_IRMA.includes('ofertaId > 0'), 'a guarda de id POSITIVO saiu — `0` passaria pela guarda de inteiro e navegaria para uma oferta que não existe');

    // ⚠️ E a coluna INTEIRA com todos os blocos adversos de uma vez.
    for (const forma of ADVERSAS) {
        let html = null;
        assert.doesNotThrow(
            () => {
                html = render(MOD_COLUNA.default, {
                    preco: { titulo: forma, kpis: forma, alteracao: forma, composicao: forma, preco: forma, frete: forma, frete_ausente: forma, simulacao: forma, atalhos: forma, atalhos_indisponivel: forma, excecao: forma, comparacao: forma, irma: forma },
                    onConferirAlteracao: forma, onInformarFrete: forma, onSimular: forma, onDescartar: forma, onSalvar: forma, onAbrirIrma: forma,
                });
            },
            'a coluna inteira derrubou o render com TODOS os campos adversos',
        );
        assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, 'a coluna inteira vazou valor cru');
    }
});
// 10. O PROTÓTIPO — `skip` EXPLÍCITO quando o pacote não está no disco
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ FID: os literais e os ponteiros medidos contra o protótipo 188_09', async (contexto) => {
    const abs = path.resolve(RAIZ, PROTOTIPO);
    if (!fs.existsSync(abs)) {
        // ⚠️ `skip` com razão ESCRITA (e `skipped N` no sumário), nunca `return`
        // calado: `design_handoff_publicador/` NÃO é versionado.
        contexto.skip(`${PROTOTIPO} ausente — o pacote de handoff não é versionado`);

        return;
    }

    // ⚠️ `split(/\r?\n/)` dá 1433 e não 1432 (a última linha vazia) — contar com
    // cuidado foi medido nesta fase.
    const linhas = fs.readFileSync(abs, 'utf8').split(/\r?\n/);
    const em = (n) => linhas[n - 1] ?? '';

    await contexto.test('o protótipo não se moveu (1432 linhas úteis)', () => {
        assert.ok(linhas.length === 1432 || linhas.length === 1433, `o protótipo mudou de tamanho: ${linhas.length}`);
        assert.match(em(640), /Preço e resultado/, '`:640` deixou de ser o título — os ponteiros se moveram');
    });

    await contexto.test('⚠️⚠️ os PONTEIROS do §D, reconferidos por leitura da linha', () => {
        assert.match(em(641), /grid-template-columns:repeat\(3,minmax\(0,1fr\)\)/, '`:641` não é a grade dos 3 KPIs');
        assert.match(em(642), /font-size:21px/, '`:642` não tem o valor de 21px');
        assert.match(em(643), /border-left:1px solid #1F1F1F/, '`:643` não tem a divisória da célula 2');
        assert.match(em(644), /gap:6px;padding:12px;border-left/, '`:644` não é a célula da margem');
        assert.match(em(645), /left:30%;top:-3px;width:1\.5px;height:10px;background:#A1A1AA/, '`:645` não é o traço do mínimo — ou a COR mudou');
        assert.match(em(646), /font-size:11px/, '`:646` não é a nota da margem');
        assert.match(em(649), /background:#14120A/, '`:649` não é a caixa do aviso');
        assert.match(em(650), /↻/, '`:650` não tem o glifo do aviso');
        assert.match(em(651), /Conferir alteração/, '`:651` não é o botão de conferir');
        // ⚠️ O ponteiro do MÍNIMO e o do `brl()`, da região do view-model que
        // NENHUMA passada de HTML/CSS re-mediu (`188-FID01.md` §J).
        assert.match(em(914), /MIN = 12/, '`:914` não é o `MIN = 12` — o `left:30%` de `:645` é CONSEQUÊNCIA dele');
        assert.match(em(1127), /Math\.max\(0, Math\.min\(40, m\)\) \/ 40 \* 100/, '`:1127` não é a fórmula da barra, clampada nas DUAS pontas');
        assert.match(em(1128), /m < this\.MIN \? '#FBBF24'/, '`:1128` não é a cor do NÚMERO da margem');
        assert.match(em(1132), /marginBar/, '`:1132` não é o `return` do `finVm`');
        assert.match(em(1133), /marginNote/, '`:1133` não é a nota da margem');
        assert.match(em(1310), /hasChange/, '`:1310` não é o aviso de alteração');
    });

    await contexto.test('⚠️ `cursor` / `white-space` / `text-overflow`, coluna por coluna', () => {
        // ⚠️ `white-space:nowrap` foi omitido SETE vezes na Fase 187, TRÊS delas
        // como PRIMEIRA propriedade da linha — e aqui ele é a primeira de `:651`.
        const COM_CURSOR = [651];
        const SEM_CURSOR = [640, 641, 642, 643, 644, 645, 646, 649, 650];
        const COM_NOWRAP = [642, 643, 644, 651];

        for (const n of COM_CURSOR) assert.ok(em(n).includes('cursor:pointer'), `\`:${n}\` perdeu o \`cursor:pointer\``);
        for (const n of SEM_CURSOR) assert.ok(!em(n).includes('cursor:pointer'), `\`:${n}\` ganhou \`cursor:pointer\` — remeça`);
        for (const n of COM_NOWRAP) assert.ok(em(n).includes('white-space:nowrap'), `\`:${n}\` perdeu o \`white-space:nowrap\``);
        // ⚠️ Nenhum destes tem `text-overflow` — ele mora em `:658`, que é da
        // tarefa 2. Afirmar aqui seria gate sobre zero casos.
        for (const n of [640, 641, 642, 643, 644, 645, 646, 649, 650, 651]) {
            assert.ok(!em(n).includes('text-overflow'), `\`:${n}\` ganhou \`text-overflow\` — remeça`);
        }
    });

    await contexto.test('⚠️ os LITERAIS da tela saem do SERVIDOR, não da tela', () => {
        // Os rótulos do protótipo existem no serviço; a tela só os exibe.
        const servico = fs.readFileSync(path.resolve(RAIZ, REL_SERVICO_PRECO), 'utf8');
        for (const literal of ['Preço e resultado', 'Preço de venda', 'Margem sobre o preço']) {
            assert.ok(servico.includes(literal), `o literal "${literal}" saiu do servidor`);
            for (const [rel, fonte] of FONTES_DO_PLANO) {
                assert.ok(!fonte.includes(literal), `${rel} escreveu "${literal}" à mão — ele é constante do servidor`);
            }
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 11. O CSS CONSTRUÍDO — com as OITO normalizações e as iscas MEDIDAS
// ═══════════════════════════════════════════════════════════════════════════

/** Escapa TODOS os metacaracteres de regex. */
function escapaRegex(texto_) {
    return texto_.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * O seletor que o Tailwind emite. ⚠️ A 8ª normalização: o escape é **GERAL**
 * (tudo que não é `[A-Za-z0-9_-]`), senão classe com `(`/`,` sai AUSENTE estando
 * presente — é o caso de `grid-cols-[repeat(3,minmax(0,1fr))]`.
 */
function seletorDe(classe) {
    return `.${[...classe].map((ch) => (/[A-Za-z0-9_-]/.test(ch) ? ch : `\\${ch}`)).join('')}`;
}

/**
 * ⚠️ É esta guarda de `mtime` que produz os DOIS REGIMES de contagem do
 * manifesto: mutar uma fonte a deixa mais nova que o CSS e a camada cai em
 * `skip`. ⚠️ E `cp` ATUALIZA o `mtime`, então `npm run build` vem DEPOIS da
 * mutação (`cp -p` preserva, e aí o regime pristino volta sem build).
 */
const FONTES_DO_CSS = [REL_KPIS, REL_COMPOSICAO, REL_SIMULACAO, REL_IRMA, REL_COLUNA];

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

test('⚠️ FID: as classes da coluna de preço GERAM CSS com o valor certo 188_09', async (contexto) => {
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
        assert.ok(tem(REL_PAGINA), 'a página da fila saiu do manifest — a tela desaparece sem erro nenhum');
        assert.equal(tem('resources/js/Pages/Mlb/NaoExiste8k3.jsx'), false, 'a isca do manifest virou entrada de verdade');
    });

    await contexto.test('⚠️ ISCA: a sonda reporta AUSENTE o que não existe, e ACHA o que existe', () => {
        // ⚠️⚠️ Isca tem de ser classe inexistente no projeto INTEIRO. A wave 188-08
        // mediu que `grid-cols-6` EXISTE (outros donos) e que `left-[37.5%]` foi
        // gerada pelo PRÓPRIO COMENTÁRIO de um docblock — o scanner do Tailwind lê
        // comentário. Estas quatro foram medidas AUSENTES contra o CSS de verdade.
        for (const isca of ['bg-pub-isca-188-09', 'text-[96.5px]', 'w-[1987px]', 'top-[63.5%]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS.css.includes(isca), `a string da isca ${isca} aparece no CSS`);
        }
        assert.notEqual(regraDe('text-[12px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('⚠️⚠️ `duration-[Nms]` continua NÃO COMPILANDO, e `duration-200` compila', () => {
        assert.equal(regraDe('duration-[180ms]'), null, '`duration-[180ms]` passou a compilar — remeça a regra');
        assert.match(regraDe('duration-200'), /transition-duration:\s*\.?2s/, 'o CONTROLE da escala nomeada sumiu');
    });

    await contexto.test('⚠️⚠️ a GEOMETRIA dos KPIs e do aviso, com as OITO normalizações', () => {
        // ⚠️ 8ª — o escape GERAL: sem ele esta sairia AUSENTE estando presente.
        assert.match(regraDe('grid-cols-[repeat(3,minmax(0,1fr))]'), /repeat\(3\s*,\s*minmax\(0\s*,\s*1fr\)\)/, 'a grade dos 3 KPIs de `:641` não compilou');

        // ⚠️ 1ª/2ª — token SÓLIDO não compila para hex: sai `rgb(R G B / var(...))`.
        assert.match(regraDe('border-pub-line-subtle'), /rgb\(31 31 31/, '`border-pub-line-subtle` não é #1F1F1F (`:641`)');
        assert.match(regraDe('bg-pub-line-subtle'), /rgb\(31 31 31/, '`bg-pub-line-subtle` não é #1F1F1F (a trilha de `:645`)');
        assert.match(regraDe('bg-pub-line-off'), /rgb\(63 63 70/, '`bg-pub-line-off` não é #3F3F46 (a barra sem dado)');
        assert.match(regraDe('bg-pub-fase1'), /rgb\(212 212 216/, '`bg-pub-fase1` não é #D4D4D8 — a cor da barra do PAINEL (`:1132`), que NÃO é a da fila');
        assert.match(regraDe('bg-pub-txt-2'), /rgb\(161 161 170/, '`bg-pub-txt-2` não é #A1A1AA — o traço do PAINEL (`:645`)');
        assert.match(regraDe('bg-pub-row-new'), /rgb\(20 18 10/, '`bg-pub-row-new` não é #14120A (o fundo de `:649`)');
        assert.match(regraDe('text-pub-txt-3'), /rgb\(138 138 144/, '`text-pub-txt-3` não é #8A8A90 (o rótulo dos KPIs)');

        // ⚠️ 3ª — o modificador de opacidade muda de FORMA conforme a PROPRIEDADE:
        // em `border-color` sai **hex de 8 dígitos**, e o byte do alfa PROVA a
        // opacidade. `0.35` → `0x59`; `0.5` → `0x80`.
        assert.match(regraDe('border-pub-accent/[0.35]'), /#facc1559/, 'a borda de `:649` não é `rgba(250,204,21,0.35)`');
        assert.match(regraDe('border-pub-accent/[0.5]'), /#facc1580/, 'a borda de `:651` não é `rgba(250,204,21,0.5)`');

        // ⚠️ 4ª — o zero da frente comido pelo cssnano: `-0.02em` sai `-.02em`.
        assert.match(regraDe('tracking-[-0.02em]'), /-\.02em|-0\.02em/, 'o `letter-spacing:-0.02em` de `:642` não compilou');

        // ⚠️ FID-03 — o `21` que o plano 188-01 liberou MEDINDO. `text-xl` é 20px e
        // arredondar para ele mudaria o layout: esta é a razão de o 21 existir.
        assert.match(regraDe('text-[21px]'), /font-size:\s*21px/, 'o `text-[21px]` dos KPIs não compilou');

        // ⚠️ A COMPOSIÇÃO (`:654`-`:667`) — a 3ª normalização de novo, agora em
        // `border-color` e em `background-color` do botão de frete.
        assert.match(regraDe('grid-cols-[minmax(0,1fr)_auto_92px]'), /minmax\(0\s*,\s*1fr\)\s+auto\s+92px/, 'a grade da linha de `:657` não compilou');
        assert.match(regraDe('border-pub-hover-3'), /rgb\(26 26 26/, '`border-pub-hover-3` não é #1A1A1A — a borda MEDIDA de `:657` (o plano dizia #1C1C1C)');
        assert.match(regraDe('border-pub-ok/[0.35]'), /#22c55e59/, 'a borda do selo `Confirmado` não é `rgba(34,197,94,0.35)`');
        assert.match(regraDe('border-pub-danger/[0.45]'), /#ef444473/, 'a borda do selo `Pendente` não é `rgba(239,68,68,0.45)`');
        assert.match(regraDe('border-pub-danger/[0.5]'), /#ef444480/, 'a borda do botão de `:667` não é `rgba(239,68,68,0.5)`');
        assert.match(regraDe('bg-pub-danger/[0.08]'), /#ef444414/, 'o fundo do botão de `:667` não é `rgba(239,68,68,0.08)`');
        assert.match(regraDe('bg-pub-sunken'), /rgb\(13 13 13/, '`bg-pub-sunken` não é #0D0D0D (o cabeçalho de `:655` e o rodapé de `:663`)');
        // ⚠️ `truncate` é EXATAMENTE os TRÊS de `:658`, e o `nowrap` foi omitido
        // SETE vezes na Fase 187.
        assert.match(regraDe('truncate'), /overflow:hidden/, '`truncate` perdeu o `overflow:hidden`');
        assert.match(regraDe('truncate'), /text-overflow:ellipsis/, '`truncate` perdeu o `text-overflow:ellipsis`');
        assert.match(regraDe('truncate'), /white-space:nowrap/, '`truncate` perdeu o `white-space:nowrap`');
        // ⚠️ `min-w-0` é LOAD-BEARING: sem ele o detalhe não trunca.
        assert.match(regraDe('min-w-0'), /min-width:\s*0/, '`min-w-0` não compilou — o detalhe de `:658` estouraria a coluna');
        // ⚠️ 4ª normalização: o zero da frente de `-4px` não existe, mas o sinal sim.
        assert.match(regraDe('-mt-[4px]'), /margin-top:\s*-4px/, 'o `margin-top:-4px` de `:665` não compilou');

        // ⚠️ A SIMULAÇÃO e as IRMÃS — a 8ª normalização de novo na grade de 2, e a
        // 4ª no `letter-spacing` (o cssnano come o zero da frente: `.05em`).
        assert.match(regraDe('grid-cols-[repeat(2,minmax(0,1fr))]'), /repeat\(2\s*,\s*minmax\(0\s*,\s*1fr\)\)/, 'a grade das irmãs de `:688` não compilou');
        assert.match(regraDe('tracking-[0.05em]'), /\.05em/, 'o `letter-spacing:0.05em` do selo de `:672` não compilou');
        assert.match(regraDe('border-dashed'), /border-style:\s*dashed/, 'o `border:1px dashed` de `:669` não compilou');
        assert.match(regraDe('rounded-full'), /border-radius:\s*9999px/, 'o `border-radius:999px` dos atalhos não compilou');
        assert.match(regraDe('bg-pub-seg'), /rgb\(38 38 38/, '`bg-pub-seg` não é #262626 (o fundo do selo de `:672`)');
        assert.match(regraDe('bg-pub-hover-2'), /rgb\(22 22 22/, '`bg-pub-hover-2` não é #161616 (o fundo da exceção de `:687`)');
        assert.match(regraDe('bg-pub-hover-3'), /rgb\(26 26 26/, '`bg-pub-hover-3` não é #1A1A1A (o fundo da célula ATUAL de `:1294`)');
        assert.match(regraDe('bg-pub-surface'), /rgb\(17 17 17/, '`bg-pub-surface` não é #111111 (o fundo da OUTRA célula de `:1294`)');
        // ⚠️ 6ª normalização: na VARIANTE o prefixo faz parte do nome.
        assert.match(regraDe('focus:border-pub-accent'), /rgb\(250 204 21/, 'o `style-focus` do input de `:675` não compilou');

        // O resto das medidas, numa asserção COLETANDO.
        const semRegra = [
            'rounded-pub-sub', 'rounded-pub-table', 'rounded-pub-control', 'rounded-pub-chip',
            'rounded-[2px]', 'relative', 'absolute', 'inset-y-0', 'left-0', 'flex-1', 'flex-col',
            'items-center', 'border-l', 'border', 'bg-transparent', 'whitespace-nowrap',
            'tabular-nums', 'cursor-pointer', 'h-[4px]', 'h-[10px]', 'h-[26px]', 'w-[1.5px]',
            'top-[-3px]', 'p-[12px]', 'p-[14px]', 'px-[10px]', 'px-[12px]', 'py-[9px]',
            'gap-[4px]', 'gap-[6px]', 'gap-[10px]', 'gap-[12px]',
            'text-[11px]', 'text-[11.5px]', 'text-[12px]', 'text-[12.5px]', 'text-[15px]',
            'font-semibold', 'font-bold', 'text-pub-txt', 'text-pub-txt-2', 'text-pub-txt-4',
            'text-pub-fase1', 'text-pub-accent', 'text-pub-warn-txt', 'text-pub-danger-txt',
            'text-pub-ok-txt', 'bg-pub-danger', 'bg-pub-warn', 'bg-pub-surface',
            'border-pub-line', 'border-pub-line-subtle',
            // ─── tarefa 2: a composição (`:654`-`:667`) ───
            'rounded-pub-badge', 'rounded-pub-control-sm', 'overflow-hidden', 'border-t',
            'justify-between', 'text-right', 'self-start', 'min-w-0', 'truncate',
            'h-[30px]', 'py-[2px]', 'py-[7px]', 'py-[8px]', 'py-[10px]', 'px-[6px]',
            'gap-[1px]', 'text-[10.5px]', 'text-[13px]', 'font-medium',
            'text-pub-txt-3', 'border-pub-line-off',
            // --- tarefa 3: a simulacao (`:669`-`:685`) e as irmas (`:688`-`:692`) ---
            'border-dashed', 'rounded-full', 'rounded-[8px]', 'flex-wrap', 'ml-auto',
            'outline-none', 'rotate-45', 'flex-none', 'h-[26px]', 'h-[28px]', 'h-[32px]',
            'w-[120px]', 'w-[6px]', 'h-[6px]', 'left-[10px]', 'pl-[32px]', 'pr-[10px]',
            'px-[9px]', 'gap-[8px]', 'text-[11.5px]', 'bg-pub-seg', 'bg-pub-hover-2',
            'bg-pub-hover-3', 'border-pub-line-strong', 'tracking-[0.05em]',
        ].filter((c) => regraDe(c) === null);
        assert.deepEqual(semRegra, [], 'classe da coluna de preço NÃO gerou CSS — num `.js` isso aconteceria com todo teste de render passando');
    });
});
