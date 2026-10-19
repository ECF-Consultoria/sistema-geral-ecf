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
const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

// ⚠️⚠️ ANTI-FANTASMA — `test -e` em CADA caminho citado. São NOVE caminhos
// fantasmas nesta milestone, TRÊS deles vindos de briefing do orquestrador.
// Sonda de AUSÊNCIA sobre arquivo inexistente passa CALADA.
const CAMINHOS_CITADOS = [
    REL_KPIS, REL_COLUNA, REL_PAINEL, REL_BARRA_DA_FILA, REL_VOCABULARIO, REL_IMAGEM,
    REL_PAGINA, REL_SERVICO_PRECO, REL_SERVICO_PAINEL, REL_DTO, REL_LINHA_FINANCEIRA,
    REL_RESUMO_RAPIDO, REL_ROTAS, REL_GATE_VOCABULARIO, REL_GATE_FECHAMENTO,
    REL_GATE_ZINDEX, REL_MANIFESTO,
];

// ⚠️ As fontes SEM comentário: a prosa em pt-BR deste projeto cita os próprios
// identificadores e os próprios literais, e **palavra no docblock reprova pelo
// próprio conserto**.
const FONTE_KPIS = lerSemComentarios(REL_KPIS);
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

    // ⚠️⚠️ **E A ROTA NÃO EXISTE NO HEAD** — a wave 188-06 é a dona do
    // transporte. MEDIDO em `routes/mlb_anuncios.php`.
    const rotas = fs.readFileSync(path.resolve(RAIZ, REL_ROTAS), 'utf8');
    for (const ausente of ['preco/simular', 'preco.simular', 'alteracao.conferir', 'conferir-alteracao']) {
        assert.ok(!rotas.includes(ausente), `a rota \`${ausente}\` apareceu — se a wave 188-06 já rodou, RELIGUE o handler em vez de deixar o botão sem destino`);
    }
    // ⚠️ ISCA DA LEITURA: as rotas que JÁ existem casam. Sem ela a sonda acima
    // passaria com o arquivo vazio ou com o caminho errado.
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
    const INTOCAVEIS = [
        REL_GATE_ZINDEX,
        'tests/js/estrutura-meus-anuncios.test.js',
        REL_VOCABULARIO,
        `${DIR_APROV}/andamentoDoPortao.jsx`,
        `${DIR_APROV}/SeloDeAndamentoInterno.jsx`,
        `${DIR_APROV}/CascaDeModal.jsx`,
        `${DIR_APROV}/ToastDaFila.jsx`,
        `${DIR_APROV}/BarraDeMargem.jsx`,
        'resources/js/Layouts/AppLayout.jsx',
        'resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx',
        REL_SERVICO_PRECO,
        REL_SERVICO_PAINEL,
        REL_DTO,
        REL_LINHA_FINANCEIRA,
        '.planning/ROADMAP.md',
        '.planning/STATE.md',
        '.planning/REQUIREMENTS-v27.md',
        'package.json',
        'package-lock.json',
    ];

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
const FONTES_DO_CSS = [REL_KPIS, REL_COLUNA];

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
        ].filter((c) => regraDe(c) === null);
        assert.deepEqual(semRegra, [], 'classe da coluna de preço NÃO gerou CSS — num `.js` isso aconteceria com todo teste de render passando');
    });
});
