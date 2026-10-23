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
// Fase 187, plano 08 — A LINHA DA OFERTA: as ONZE células do grid, o anel de
// progresso nos quatro estados e a barra de margem de 0 a 40%.
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas). ⚠️ **CITE O DIRETÓRIO:** há DUAS cópias do pacote de handoff
// com conteúdo DIFERENTE (`design_handoff_publicador/` × `new_layout_publicador/`)
// e a segunda é a versão ANTIGA — a ERRADA para esta fase.
//
// ⚠️⚠️ **DEZENOVE DOS VINTE E CINCO PONTEIROS DO PLANO ESTAVAM DESLOCADOS** — a
// lista medida está no topo de `LinhaDaOferta.jsx`, com `plano → MEDIDO` coluna
// a coluna. Os corretos foram obtidos por SCRIPT (`sed -n '130,182p'` +
// `grep -n` de literais distintivos), não por leitura de olho.
//
// ⚠️⚠️ **E CINCO OMISSÕES**: `white-space:nowrap` em `:152`, `:154` e `:158`; a
// COLUNA título+meta de `:138`; o wrapper da modalidade de `:147`; o wrapper
// interno do responsável de `:167`; e o separador `·` + o wrapper de origem de
// `:141`/`:142`. A tabela do plano não erra VALOR — erra por OMISSÃO.
//
// ═══ ⚠️ A PERGUNTA OBRIGATÓRIA, RESPONDIDA POR BLOCO ══════════════════════
//
// *"O que precisaria estar errado no mundo para este gate falhar?"* — "nada" é
// ACHADO, não conforto. Por bloco:
//
//   1. GRID       → uma trilha trocada, ou o grid recopiado em vez de importado
//                   (e aí cabeçalho e linha divergem sem ninguém saber qual está
//                   certo). E `h-[64px]` no lugar de `min-h-[64px]`, que CORTA
//                   título de duas linhas sem avisar.
//   2. ORDEM      → duas células trocadas de lugar: o preço cairia sob "Fotos".
//   3. FUNDOS     → um token trocado, ou chave desconhecida acendendo a linha
//                   como "aberta" (afirmaria um painel que não está aberto).
//   4. META       → a ordem invertida, ou o selo EXCEÇÃO por truthiness
//                   (anunciaria liberação que ninguém concedeu).
//   5. MODALIDADE → o losango no Clássico (afirmaria Premium, que custa mais
//                   comissão e tem outra regra de frete).
//   6. AÇÃO       → os rótulos recopiados aqui (duas verdades), ou `Revisar`
//                   amarelo em todas, apagando o "comece por esta".
//   7. ABRIR      → `preparando` abrindo o painel de uma oferta que o sistema
//                   ainda está montando (D9), e o CLIQUE derrubando a página.
//   8. SUB-LINHA  → texto longo quebrando o grid, ou o resto da frase ficando
//                   INALCANÇÁVEL (ellipsis sem `title`).
//   9. ANEL       → guarda devolvendo `null` e produzindo elemento VAZIO, que
//                   passa em todo render e DESAPARECE na tela.
//  10. BARRA      → 95% estourando a trilha, negativa desenhando para a
//                   esquerda, `width:"NaN%"` sumindo com a barra, ou `left-[30%]`
//                   cravado (D10: a prescrição é a FÓRMULA `minimo/40`).
//  11. ADVERSO    → qualquer um dos ~53 campos chegando objeto/NaN/array: a TELA
//                   PRETA de 07/10/2026 voltou QUATRO vezes na Fase 180 e UMA na
//                   wave 6 — aquela **no CLIQUE**.
//  12. map/ausên. → flag fora do callback (o Rollup a elimina e o
//                   `ReferenceError` só aparece no bundle), `localStorage`,
//                   "lucro líquido", `String(`.
//  13. CSS        → classe que não compila: `duration-[Nms]` é o precedente
//                   MEDIDO deste projeto, e é a ISCA do bloco.
//  14. PÁGINA     → o slot voltar a ficar vazio e a fila perder as linhas.
//
// ⚠️ REND-03: TODOS os bundles são montados AQUI NO TOPO, antes do primeiro
// `test()`. O `after()` do `node --test` dispara quando os testes registrados
// terminam e já matou um arquivo inteiro deste projeto SEM nenhum teste falhar.
//
// ⚠️ **ESCOPO DECLARADO:** a PÁGINA não é renderizada aqui, pelo mesmo recorte
// das waves 4-7: montar `Pages/Mlb/Publicador/Aprovacao.jsx` exigiria o
// `AppLayout` e o `PublicadorHeader` inteiros (que leem `usePage()`, Radix e o
// `localStorage` das contas recentes), e o que isso mediria é a casca da Fase
// 177. Da página, o que este arquivo mede é a FONTE (o slot preenchido, o
// destino do clique, zero `localStorage`, zero `String(`).
// ⚠️ **Nenhum executor desta fase viu esta tela renderizada:** o banco local não
// tem empresa com `ml_token` (a rota dá 404 aqui). A conferência é do usuário,
// em produção, na conta 459.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

const STUB_INERTIA = path.join(__dirname, `.pub-linha187-inertia-stub-${process.pid}.mjs`);
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
        `.pub-linha187-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_APROV = 'resources/js/Components/Mlb/Publicador/aprovacao';
const REL_LINHA = `${DIR_APROV}/LinhaDaOferta.jsx`;
const REL_ANEL = `${DIR_APROV}/AnelDeProgresso.jsx`;
const REL_BARRA = `${DIR_APROV}/BarraDeMargem.jsx`;
const REL_TABELA = `${DIR_APROV}/TabelaDaFila.jsx`;
const REL_PAGINA = 'resources/js/Pages/Mlb/Publicador/Aprovacao.jsx';
const REL_SERVICO = 'app/Services/Publicador/Aprovacao/Fila/LinhaDaOfertaService.php';
const REL_MODELO = 'app/Models/PubAprovacaoOferta.php';
const REL_COMPOSICAO = 'app/Models/PubAprovacaoComposicao.php';
const PROTOTIPO = 'design_handoff_publicador/Aprovacao.dc.html';

// ⚠️ `test -e` ANTES de acreditar em qualquer leitura vazia. **Sete** caminhos
// fantasma já "provaram" território intocado nesta milestone, medindo NADA.
// ⚠️ `design_handoff_publicador/` NÃO é versionado: se ele faltar, o bloco que
// depende dele degrada para `skip` EXPLÍCITO e NOMEADO (mais abaixo) — nunca
// passa calado. Os outros oito são do repositório e a ausência deles é ERRO.
for (const caminho of [
    DIR_APROV, REL_LINHA, REL_ANEL, REL_BARRA, REL_TABELA, REL_PAGINA,
    REL_SERVICO, REL_MODELO, REL_COMPOSICAO,
]) {
    if (!fs.existsSync(path.resolve(RAIZ, caminho))) {
        throw new Error(`caminho inexistente: ${caminho} — gate mediria NADA`);
    }
}

const TEM_PROTOTIPO = fs.existsSync(path.resolve(RAIZ, PROTOTIPO));
const TEXTO_PROTOTIPO = TEM_PROTOTIPO ? fs.readFileSync(path.resolve(RAIZ, PROTOTIPO), 'utf8') : '';

const MOD_LINHA = await montar(REL_LINHA);
const MOD_ANEL = await montar(REL_ANEL);
const MOD_BARRA = await montar(REL_BARRA);
const MOD_TABELA = await montar(REL_TABELA);
const Linha = MOD_LINHA.default;
const Anel = MOD_ANEL.default;
const Barra = MOD_BARRA.default;

const FONTE_LINHA = lerSemComentarios(REL_LINHA);
const FONTE_ANEL = lerSemComentarios(REL_ANEL);
const FONTE_BARRA = lerSemComentarios(REL_BARRA);
const FONTE_PAGINA = lerSemComentarios(REL_PAGINA);

/**
 * ⚠️ A LISTA CONSTANTE dos arquivos deste plano — e ela é constante de propósito.
 *
 * As ausências ("lucro líquido", `localStorage`, `String(`) são cobradas **só**
 * nesta lista. Varrer o `app/` inteiro foi a 5ª ocorrência de "gate mais largo
 * que o invariante" na Fase 186, e ela **barrou um deploy**: `lucro_liquido` é
 * campo LEGÍTIMO do Portal (`app/Services/Portal/`), e a varredura larga
 * reprovaria trabalho de outra sessão.
 */
const FONTES_DO_PLANO = [
    [REL_LINHA, FONTE_LINHA],
    [REL_ANEL, FONTE_ANEL],
    [REL_BARRA, FONTE_BARRA],
];
const TODAS_AS_FONTES = FONTES_DO_PLANO.map(([, f]) => f).join('\n');

const FONTE_SERVICO = fs.readFileSync(path.resolve(RAIZ, REL_SERVICO), 'utf8');
const FONTE_MODELO = fs.readFileSync(path.resolve(RAIZ, REL_MODELO), 'utf8');
const FONTE_COMPOSICAO = fs.readFileSync(path.resolve(RAIZ, REL_COMPOSICAO), 'utf8');

const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

// ─── A fixture: o payload REAL de `LinhaDaOfertaService::para()` ───
//
// ⚠️⚠️ **ELA DISCRIMINA DE PROPÓSITO.** Na Fase 186 duas mutações sobreviveram
// **não** por falha da asserção, mas porque a fixture não distinguia os casos
// (uma oferta só fazia "a conta" e "as ao vivo" darem o mesmo número). Aqui:
// os quatro pedaços da meta preenchidos (para a ORDEM ser mensurável), Premium
// (para o losango), margem ACIMA do mínimo com `largura` ≠ `minimo` (para o
// traço não coincidir com a barra), responsável COM id (para o tom do avatar) e
// `acao.paleta` de DESTAQUE (a da oferta próxima).

const QUADRADOS = [
    { id: 11, estado: 'approved', de_ia: false, com_alerta: false, rotulo: 'Capa', titulo: 'Capa · aprovada' },
    { id: 12, estado: 'pending', de_ia: true, com_alerta: true, rotulo: 'Foto 2', titulo: 'Foto 2 · com alerta' },
    { id: 13, estado: 'pending', de_ia: true, com_alerta: false, rotulo: 'Foto 3', titulo: 'Foto 3 · a conferir' },
];

const LINHA = {
    id: 9001,
    progresso: 'nao_analisada',
    titulo: 'Poltrona Beny — Veludo Azul 2 Lugares Pés de Madeira',
    titulo_forte: true,
    meta: {
        sku: 'POL-BENY-K2',
        origem: { rotulo: 'Automática', glifo: '⚡', cor: 'destaque' },
        versao: 'v3',
        excecao: true,
        excecao_rotulo: 'EXCEÇÃO',
    },
    modalidade: { rotulo: 'Premium', premium: true, formato: 'Combo ×2 · 2 un.' },
    fotos: { resumo: '3 fotos · 2 IA', quadrados: QUADRADOS, sub: '1 com alerta', sub_cor: 'vermelho' },
    preco: { valor: 'R$ 1.299,90', titulo: 'pior caso entre 3 variações · cor:azul|tamanho:m', ativos: 3 },
    resultado: {
        valor: 'R$ 184,20',
        cor: 'texto',
        sub: 'estimado',
        sub_cor: 'neutro',
        rotulo_longo: 'Resultado estimado por venda',
    },
    margem: {
        valor: '14,2%',
        cor: 'texto',
        largura: '35.5%',
        barra: 'suave',
        minimo: '30%',
        minimo_valor: 12,
        rotulo_longo: 'Margem de contribuição estimada',
        fonte: 'com a tarifa real do Mercado Livre',
    },
    responsavel: { id: 7, nome: 'Ana Ribeiro', espera: 'há 3 dias', espera_minutos: 4320, espera_cor: 'ambar' },
    andamento: { chave: 'aguardando_aprovacao', sub: 'Bloqueado: fotos a conferir +1', sub_cor: 'vermelho' },
    acao: { rotulo: 'Revisar', paleta: 'destaque' },
    abre: true,
    fundo: 'padrao',
};

const nada = () => {};

const HTML = render(Linha, { linha: LINHA, onAbrir: nada });

/**
 * O elemento de índice ABSOLUTO na árvore de tags de abertura do HTML.
 *
 * ⚠️⚠️ **NUNCA `indexOf(tagDeAbertura)`**: ele pega o IRMÃO ERRADO quando dois
 * elementos têm a MESMA string de classes — **quatro gates deste projeto
 * estavam medindo a linha do Mercado Livre em vez do ERP**. Aqui a localização
 * é por POSIÇÃO na árvore, contada do começo.
 */
function tagsDeAbertura(html) {
    return [...html.matchAll(/<(div|span|section|button|img)\b([^>]*)>/g)].map((m) => ({
        tag: m[1],
        atributos: m[2],
        classe: (m[2].match(/class="([^"]*)"/) || [])[1] ?? '',
        titulo: (m[2].match(/title="([^"]*)"/) || [])[1] ?? null,
        estilo: (m[2].match(/style="([^"]*)"/) || [])[1] ?? null,
    }));
}

const ARVORE = tagsDeAbertura(HTML);

/** A lista de classes de um elemento, como CONJUNTO — igualdade, nunca `includes`. */
function classesDe(elemento) {
    return new Set(elemento.classe.split(/\s+/).filter((c) => c !== ''));
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. ⚠️⚠️ O GRID DE 11 COLUNAS E A ALTURA MÍNIMA
// ═══════════════════════════════════════════════════════════════════════════

test('o grid da LINHA é o MESMO do cabeçalho e a altura é MÍNIMA, nunca fixa 187_08', (contexto) => {
    const esperada = 'grid-cols-[32px_24px_minmax(170px,1fr)_104px_118px_92px_108px_76px_112px_164px_96px]';

    // ⚠️⚠️ IMPORTADO, nunca recopiado: a constante é a MESMA de `TabelaDaFila.jsx`.
    // Duas cópias divergiriam numa correção feita de um lado só, e o sintoma — o
    // cabeçalho desalinhado das linhas — não diria qual dos dois está certo.
    assert.equal(MOD_TABELA.GRID_DAS_11_COLUNAS, esperada, 'a constante do grid divergiu');
    assert.match(FONTE_LINHA, /import \{ GRID_DAS_11_COLUNAS \} from '\.\/TabelaDaFila'/, 'o grid foi RECOPIADO em vez de importado');
    // E a string literal NÃO aparece nesta fonte (seria a segunda cópia).
    assert.ok(!FONTE_LINHA.includes('grid-cols-[32px'), 'o grid literal foi recopiado em `LinhaDaOferta.jsx`');

    const raiz = ARVORE[0];
    assert.equal(raiz.tag, 'div', 'a raiz da linha não é um `<div>`');
    assert.ok(classesDe(raiz).has(esperada), `a linha não carrega o grid literal: ${raiz.classe}`);

    // ⚠️ ISCA DA FORMA: a comparação por CONJUNTO sabe recusar o quase-igual.
    // `substring` casaria o prefixo — e isso já mordeu este projeto quatro vezes.
    const quaseIgual = 'grid-cols-[32px_24px_minmax(170px,1fr)_108px_118px_92px_108px_76px_112px_164px_96px]';
    assert.notEqual(quaseIgual, esperada, 'a isca do quase-igual virou a classe de verdade');
    assert.equal(classesDe(raiz).has(quaseIgual), false, 'a linha casou o grid MUTADO');

    // ⚠️⚠️ `min-h-[64px]`, NUNCA `h-[64px]` (`:133` diz `min-height`).
    assert.ok(classesDe(raiz).has('min-h-[64px]'), 'a linha perdeu a altura mínima de 64px');
    assert.equal(classesDe(raiz).has('h-[64px]'), false, 'a linha ficou com altura FIXA — ela corta conteúdo');

    // ⚠️⚠️ **A SONDA DA FONTE É ANCORADA, e isto é CONSERTO MEDIDO nesta sessão.**
    // A primeira redação era `!FONTE_LINHA.includes('h-[64px]')` e ela **REPROVOU
    // O CÓDIGO CERTO**: `min-h-[64px]` **CONTÉM** `h-[64px]` como substring. É a
    // mesma família que já mordeu este projeto quatro vezes (`z-3` dentro de
    // `z-30`, `text-[11px]` dentro de `text-[11.5px]`, `/disabled/` casando
    // `disabled:opacity-40`), e o "conserto barato" seria apagar a sonda —
    // perdendo o único gate da altura FIXA.
    // ➡️ Ancorada no começo de CLASSE (espaço, aspa ou abre-parêntese antes).
    const ALTURA_FIXA = /(?:^|[\s'"(])h-\[64px\]/;
    assert.doesNotMatch(FONTE_LINHA, ALTURA_FIXA, 'altura FIXA na fonte: ela corta título de duas linhas');
    // ⚠️ ISCA NAS DUAS DIREÇÕES: a sonda PEGA a forma que procura e NÃO pega a
    // legítima. Sem isto ela seria gate que nunca reprova.
    assert.match("className='grid h-[64px] items-center'", ALTURA_FIXA, 'a sonda da altura fixa está CEGA');
    assert.match("cn(h-[64px])", ALTURA_FIXA);
    assert.doesNotMatch("className='grid min-h-[64px] items-center'", ALTURA_FIXA, 'a sonda reprova o `min-h` legítimo');

    for (const classe of ['grid', 'cursor-pointer', 'items-center', 'border-t', 'border-pub-hover-3',
        'px-[16px]', 'transition-colors', 'duration-150', 'hover:bg-pub-hover-2']) {
        assert.ok(classesDe(raiz).has(classe), `a linha perdeu \`${classe}\``);
    }

    // ⚠️ `duration-[Nms]` NÃO compila neste projeto — a escala NOMEADA é a certa.
    assert.ok(!FONTE_LINHA.includes('duration-['), '`duration-[Nms]` não compila: use a escala nomeada');

    contexto.diagnostic('grid e altura conferidos contra `:133`');
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. AS 11 CÉLULAS, POR ÁRVORE COM ÍNDICE ABSOLUTO
// ═══════════════════════════════════════════════════════════════════════════

test('as 11 células saem na ordem, localizadas por ÁRVORE com índice ABSOLUTO 187_08', () => {
    // Sem `onMarcar` a célula 1 é um `<span>` vazio (D4: botão sem destino não é
    // desenhado; a seleção em lote é o plano 187-09).
    assert.equal(ARVORE[1].tag, 'span', 'a célula 1 (checkbox) não é o `<span>` vazio sem handler');
    assert.equal(ARVORE[1].classe, '', 'a célula 1 ganhou classe sem handler');

    // Célula 2 — o anel, com `title`.
    assert.ok(classesDe(ARVORE[2]).has('rounded-full'), 'a célula 2 não é o anel');
    assert.equal(ARVORE[2].titulo, 'Ainda não analisada', 'o anel não trouxe o `title` do estado');

    // Célula 3 — a oferta.
    assert.ok(classesDe(ARVORE[3]).has('gap-[12px]'), 'a célula 3 não é a da oferta (`gap:12px` de `:136`)');

    // ⚠️ AS ONZE CÉLULAS são os filhos DIRETOS da raiz do grid. A contagem é
    // feita pela ORDEM em que o React as emite, que é a ordem do JSX.
    const comHandlers = tagsDeAbertura(render(Linha, { linha: LINHA, onAbrir: nada, onMarcar: nada, marcada: true }));
    assert.equal(comHandlers[1].tag, 'button', 'com handler, a célula 1 não virou botão');
    assert.ok(classesDe(comHandlers[1]).has('rounded-pub-badge'), 'o checkbox da linha perdeu o raio de 4px');
    assert.ok(classesDe(comHandlers[1]).has('font-[800]'), 'o checkbox perdeu o peso 800 de `:134`');

    // ⚠️⚠️ `font-extrabold` é PROIBIDO pelo GATE-03 em `ARQUIVOS_NOVOS`, e a
    // tabela FID-01 do plano o prescreve em DOIS lugares (o checkbox e o anel).
    assert.doesNotMatch(TODAS_AS_FONTES, /font-extrabold/, 'o GATE-03 proíbe `font-extrabold` — use `font-[800]`');
    assert.match(FONTE_LINHA, /font-\[800\]/, 'o peso 800 do protótipo desapareceu da linha');
    assert.match(FONTE_ANEL, /font-\[800\]/, 'o peso 800 do protótipo desapareceu do anel');

    // ⚠️ ISCA: o glifo `✓` só aparece MARCADA.
    assert.ok(render(Linha, { linha: LINHA, onAbrir: nada, onMarcar: nada, marcada: true }).includes(MOD_LINHA.GLIFO_MARCADO));
    const desmarcada = render(Linha, { linha: LINHA, onAbrir: nada, onMarcar: nada, marcada: false });
    assert.ok(!desmarcada.includes(MOD_LINHA.GLIFO_MARCADO), 'o glifo apareceu DESMARCADO');
    assert.ok(desmarcada.includes('Poltrona Beny'), 'ISCA CEGA: o render desmarcado estava vazio');

    // ⚠️ E `marcada` por TRUTHINESS não marca: `'sim'`/`1`/`{}` não são `true`.
    for (const quase of ['sim', 1, {}, []]) {
        const html = render(Linha, { linha: LINHA, onAbrir: nada, onMarcar: nada, marcada: quase });
        assert.ok(!html.includes(MOD_LINHA.GLIFO_MARCADO), 'a linha marcou por truthiness');
    }

    // As 11 trilhas exigem 11 células; com o checkbox ausente a primeira é o
    // `<span/>`, e as dez seguintes existem — conferidas pelos marcadores de cada.
    for (const marcador of ['gap-[12px]', 'gap-[3px]', 'gap-[5px]', 'text-right',
        'items-end', 'gap-[6px]', 'gap-[8px]', 'items-start', 'justify-end']) {
        assert.ok(HTML.includes(marcador), `a célula marcada por \`${marcador}\` não foi desenhada`);
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. OS QUATRO FUNDOS E O HOVER (`:1250`)
// ═══════════════════════════════════════════════════════════════════════════

test('os QUATRO fundos são os literais, com fallback no padrão e o hover de `:133` 187_08', () => {
    const esperados = {
        aberta: 'bg-pub-row-sel',
        foco: 'bg-pub-hover-3',
        marcada: 'bg-pub-row-checked',
        padrao: 'bg-pub-surface',
    };
    assert.deepEqual(MOD_LINHA.FUNDOS, esperados, 'os quatro fundos divergiram de `:1250`');

    for (const [chave, token] of Object.entries(esperados)) {
        const raiz = tagsDeAbertura(render(Linha, { linha: { ...LINHA, fundo: chave }, onAbrir: nada }))[0];
        assert.ok(classesDe(raiz).has(token), `o fundo \`${chave}\` não saiu como \`${token}\``);
    }

    // ⚠️⚠️ Chave desconhecida cai no PADRÃO, nunca em `aberta`: acender a linha
    // como "painel aberto" afirmaria algo que não está acontecendo.
    for (const cru of ['nao_existe', '', null, undefined, 42, {}, [], { toString: null }]) {
        const raiz = tagsDeAbertura(render(Linha, { linha: { ...LINHA, fundo: cru }, onAbrir: nada }))[0];
        assert.ok(classesDe(raiz).has('bg-pub-surface'), 'chave de fundo desconhecida não caiu no padrão');
        assert.equal(classesDe(raiz).has('bg-pub-row-sel'), false, 'chave desconhecida acendeu a linha como ABERTA');
    }

    // ⚠️ A PRECEDÊNCIA (aberta > foco > marcada > padrão) é do SERVIDOR, e há gate
    // PHP provando-a com dois estados ativos ao mesmo tempo
    // (`abertura-e-fundos`, `contagens-187-08.txt`). Aqui se prova que a tradução
    // não a reimplementa: a fonte não tem ternário de estado nenhum.
    assert.ok(!FONTE_LINHA.includes('aberta ?'), 'a precedência dos fundos foi reimplementada no JSX');
    assert.ok(!FONTE_LINHA.includes('.marcada ?'), 'a precedência dos fundos foi reimplementada no JSX');
    assert.match(FONTE_SERVICO, /aberta.*\?\?\s*false\)\s*===\s*true/s, 'o serviço deixou de resolver o fundo');
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. A META: UMA LINHA, COM ELLIPSIS E NA ORDEM LITERAL
// ═══════════════════════════════════════════════════════════════════════════

test('a META é UMA linha com ellipsis, na ordem SKU · origem · vN EXCEÇÃO 187_08', () => {
    // ⚠️ A ORDEM é lida do MÓDULO, não escrita de cabeça nos dois lados.
    assert.deepEqual(
        MOD_LINHA.pedacosDaMeta(LINHA.meta).map((p) => p.chave),
        ['sku', 'origem', 'versao', 'excecao'],
        'a ordem dos pedaços da meta divergiu de `:141`-`:143`',
    );

    // ⚠️⚠️ O separador vai ENTRE SKU/origem e origem/versão, e **NÃO** antes do
    // selo EXCEÇÃO — medido em `:142`/`:143`. "v3 · EXCEÇÃO" não existe.
    assert.deepEqual(
        MOD_LINHA.pedacosDaMeta(LINHA.meta).map((p) => p.comSeparador),
        [false, true, true, false],
        'o separador da meta está no lugar errado',
    );

    const meta = ARVORE.find((e) => classesDe(e).has('truncate') && classesDe(e).has('gap-[6px]'));
    assert.ok(meta, 'não achei a linha da meta');
    for (const classe of ['flex', 'min-w-0', 'items-center', 'truncate', 'text-[11.5px]', 'text-pub-txt-3']) {
        assert.ok(classesDe(meta).has(classe), `a meta perdeu \`${classe}\``);
    }

    // Os quatro pedaços estão no HTML, e a ORDEM no texto é a do protótipo.
    const posSku = HTML.indexOf('POL-BENY-K2');
    const posOrigem = HTML.indexOf('Automática');
    const posVersao = HTML.indexOf('v3');
    const posExcecao = HTML.indexOf('EXCEÇÃO');
    assert.ok(posSku > 0 && posOrigem > posSku && posVersao > posOrigem && posExcecao > posVersao,
        'a ordem SKU · origem · vN · EXCEÇÃO não está na saída');

    // O SKU em monoespaçada de 11px (`:141`).
    const sku = ARVORE.find((e) => classesDe(e).has('font-pub-mono'));
    assert.ok(sku, 'o SKU não saiu em monoespaçada');
    assert.ok(classesDe(sku).has('text-[11px]'), 'o SKU perdeu o `text-[11px]` de `:141`');

    // ⚠️ O wrapper de origem (`gap:4px`, `:142`) — OMITIDO na tabela do plano.
    assert.ok(ARVORE.some((e) => classesDe(e).has('gap-[4px]') && classesDe(e).has('items-center')),
        'o wrapper de origem de `:142` (gap:4px) não existe');

    // ⚠️ A COLUNA título+meta (`gap:4px`, `:138`) — OMITIDA na tabela do plano.
    assert.ok(ARVORE.some((e) => classesDe(e).has('flex-col') && classesDe(e).has('gap-[4px]') && classesDe(e).has('min-w-0')),
        'a coluna título+meta de `:138` não existe');

    // O separador em `#3F3F46` = `text-pub-line-off` (`:141`/`:142`).
    assert.equal(MOD_LINHA.SEPARADOR, '·', 'o separador não é U+00B7');
    assert.ok(ARVORE.some((e) => classesDe(e).has('text-pub-line-off')), 'o separador perdeu a cor de `:141`');

    // ⚠️ EXCEÇÃO só com a flag — e NUNCA por truthiness: o selo afirma liberação
    // vigente, e anunciá-la por `'sim'`/`1` seria afirmação falsa.
    for (const quase of [false, 'sim', 1, {}, [], null, undefined] ) {
        const html = render(Linha, { linha: { ...LINHA, meta: { ...LINHA.meta, excecao: quase } }, onAbrir: nada });
        assert.ok(!html.includes('EXCEÇÃO'), 'o selo EXCEÇÃO apareceu sem exceção vigente');
        assert.ok(html.includes('POL-BENY-K2'), 'ISCA CEGA: o render estava vazio — a ausência não prova nada');
    }

    // O selo com a geometria de `:143`.
    const selo = ARVORE.find((e) => classesDe(e).has('tracking-[0.05em]'));
    assert.ok(selo, 'o selo EXCEÇÃO não saiu');
    for (const classe of ['rounded-pub-badge', 'border', 'border-pub-line-off', 'px-[5px]', 'py-[1px]', 'text-[10px]', 'font-bold', 'text-pub-fase1']) {
        assert.ok(classesDe(selo).has(classe), `o selo EXCEÇÃO perdeu \`${classe}\``);
    }

    // O título com o peso de `:1242`: 600 na não analisada, 500 nas outras três.
    const titulo = ARVORE.find((e) => classesDe(e).has('text-[13.5px]') && classesDe(e).has('truncate'));
    assert.ok(titulo, 'não achei o título');
    assert.ok(classesDe(titulo).has('font-semibold'), 'o título NÃO ANALISADO perdeu o peso 600');
    const tituloFraco = tagsDeAbertura(render(Linha, { linha: { ...LINHA, titulo_forte: false }, onAbrir: nada }))
        .find((e) => classesDe(e).has('text-[13.5px]') && classesDe(e).has('truncate'));
    assert.ok(classesDe(tituloFraco).has('font-medium'), 'o título analisado deveria ficar em peso 500');
    assert.equal(classesDe(tituloFraco).has('font-semibold'), false, 'o título analisado ficou em 600');
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. A MODALIDADE: o losango é SÓ do Premium (`:147`-`:149`)
// ═══════════════════════════════════════════════════════════════════════════

test('o losango é SÓ do Premium, e o peso da modalidade acompanha 187_08', () => {
    // ⚠️ O wrapper de `:147` (`flex-direction:column;gap:3px`) — OMITIDO no plano.
    const wrapper = ARVORE.find((e) => classesDe(e).has('flex-col') && classesDe(e).has('gap-[3px]'));
    assert.ok(wrapper, 'o wrapper da modalidade de `:147` não existe');

    const losango = ARVORE.find((e) => classesDe(e).has('rotate-45'));
    assert.ok(losango, 'o losango do Premium não saiu');
    for (const classe of ['h-[7px]', 'w-[7px]', 'rounded-[1px]', 'bg-pub-txt']) {
        assert.ok(classesDe(losango).has(classe), `o losango perdeu \`${classe}\``);
    }

    const rotulo = ARVORE.find((e) => classesDe(e).has('gap-[5px]') && classesDe(e).has('text-[13px]'));
    assert.ok(classesDe(rotulo).has('font-semibold'), 'o Premium perdeu o peso 600 de `:148`');

    // Clássico: sem losango e em peso 400.
    const classico = { ...LINHA, modalidade: { rotulo: 'Clássico', premium: false, formato: 'Unidade · 1 un.' } };
    const htmlClassico = render(Linha, { linha: classico, onAbrir: nada });
    assert.ok(!htmlClassico.includes('rotate-45'), 'o losango apareceu no Clássico — afirmaria Premium');
    assert.ok(htmlClassico.includes('Clássico'), 'ISCA CEGA: o render do Clássico estava vazio');
    const rotuloClassico = tagsDeAbertura(htmlClassico).find((e) => classesDe(e).has('gap-[5px]') && classesDe(e).has('text-[13px]'));
    assert.ok(classesDe(rotuloClassico).has('font-normal'), 'o Clássico deveria ficar em peso 400');

    // ⚠️ E `premium` por TRUTHINESS não acende o losango.
    for (const quase of ['sim', 1, {}, [], 'gold_pro']) {
        const html = render(Linha, { linha: { ...LINHA, modalidade: { ...LINHA.modalidade, premium: quase } }, onAbrir: nada });
        assert.ok(!html.includes('rotate-45'), 'o losango apareceu por truthiness');
    }

    // O formato com `whitespace-nowrap` (`:149`).
    const formato = ARVORE.find((e) => classesDe(e).has('text-[11.5px]') && classesDe(e).has('whitespace-nowrap') && classesDe(e).has('text-pub-txt-3'));
    assert.ok(formato, 'o formato perdeu o `whitespace-nowrap` de `:149`');
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. A AÇÃO: os rótulos vêm do MODELO, as paletas são as de `:1240`
// ═══════════════════════════════════════════════════════════════════════════

test('os rótulos da ação vêm do mapa CTA do MODELO, e as QUATRO paletas são as de `:1240` 187_08', () => {
    // ⚠️⚠️ Os rótulos são LIDOS de `PubAprovacaoOferta::CTA` — nunca escritos aqui
    // nem no `.jsx`. Recopiá-los criaria uma segunda verdade livre para divergir.
    // ⚠️⚠️ **O RECORTE É O BLOCO `CTA`, e isto é CONSERTO MEDIDO nesta sessão.** A
    // primeira redação varria `/self::\w+\s*=>\s*'([^']+)'/` no arquivo INTEIRO e
    // **REPROVOU O CÓDIGO CERTO**: `PubAprovacaoOferta.php` tem OUTROS mapas com a
    // mesma forma (os rótulos dos 8 andamentos — "Preparando", "Publicando",
    // "Publicado", "Falha na publicação"…), e a sonda trouxe 8 em vez de 4. Era
    // gate MAIS LARGO QUE O INVARIANTE (a nona ocorrência dessa família nesta
    // milestone), e o conserto barato seria "atualizar a lista esperada" — que
    // faria o gate parar de medir o CTA e passar a medir o inventário do arquivo.
    // ➡️ Recortado ao bloco `public const CTA = [ … ];`, com isca de existência.
    const blocoCta = FONTE_MODELO.match(/public const CTA = \[([\s\S]*?)\];/);
    assert.ok(blocoCta, 'ISCA: não achei o bloco `public const CTA` no modelo — a sonda mediria NADA');
    const doModelo = [...blocoCta[1].matchAll(/=>\s*'([^']+)'/g)].map((m) => m[1]);
    assert.equal(doModelo.length, 8, `o mapa CTA tem ${doModelo.length} entradas — esperava os 8 andamentos`);
    const rotulos = [...new Set(doModelo)].sort();
    assert.deepEqual(rotulos, ['Aguarde', 'Revisar', 'Tentar de novo', 'Ver'], `os rótulos do modelo divergiram: ${rotulos.join(', ')}`);
    // ⚠️ ISCA DO RECORTE: ele NÃO abraça o mapa vizinho. Se abraçasse, os rótulos
    // de andamento entrariam e a asserção acima teria de ser afrouxada.
    assert.ok(!blocoCta[1].includes('Preparando'), 'o recorte do CTA abraçou o mapa de ANDAMENTOS');

    // ⚠️ E NENHUM deles está escrito no `.jsx`: quem resolve é o servidor.
    for (const rotulo of rotulos) {
        assert.ok(!FONTE_LINHA.includes(`'${rotulo}'`), `o rótulo "${rotulo}" foi RECOPIADO no JSX`);
    }

    const esperadas = {
        destaque: 'border-pub-accent bg-pub-accent text-pub-page',
        ver: 'border-pub-line-strong bg-pub-surface text-pub-fase1',
        aguarde: 'border-pub-line-subtle bg-pub-surface text-pub-txt-off',
        neutro: 'border-pub-line-strong bg-pub-hover-2 text-pub-txt',
    };
    assert.deepEqual(MOD_LINHA.PALETAS, esperadas, 'as quatro paletas divergiram de `:1240`');

    // As quatro paletas, uma por uma, no botão de verdade.
    for (const [chave, classes] of Object.entries(esperadas)) {
        const botao = tagsDeAbertura(render(Linha, {
            linha: { ...LINHA, acao: { rotulo: 'Revisar', paleta: chave } },
            onAbrir: nada,
        })).find((e) => e.tag === 'button');
        assert.ok(botao, `o botão não saiu com a paleta \`${chave}\``);
        for (const classe of classes.split(' ')) {
            assert.ok(classesDe(botao).has(classe), `a paleta \`${chave}\` perdeu \`${classe}\``);
        }
        for (const classe of ['h-[30px]', 'cursor-pointer', 'whitespace-nowrap', 'rounded-[8px]', 'border', 'px-[12px]', 'text-[12.5px]', 'font-semibold']) {
            assert.ok(classesDe(botao).has(classe), `o botão perdeu \`${classe}\` de \`:177\``);
        }
    }

    // ⚠️⚠️ `Revisar` AMARELO é SÓ da oferta PRÓXIMA (T-187-85) — quem decide é o
    // servidor (`acao()` com `$ehProxima`), e há gate PHP com mutação. Aqui se
    // prova que paleta DESCONHECIDA cai no NEUTRO e nunca no amarelo.
    for (const cru of ['nao_existe', '', null, undefined, 7, {}, [], { toString: null }]) {
        const botao = tagsDeAbertura(render(Linha, {
            linha: { ...LINHA, acao: { rotulo: 'Revisar', paleta: cru } },
            onAbrir: nada,
        })).find((e) => e.tag === 'button');
        assert.ok(classesDe(botao).has('bg-pub-hover-2'), 'paleta desconhecida não caiu no neutro');
        assert.equal(classesDe(botao).has('bg-pub-accent'), false, 'paleta desconhecida pintou o botão de AMARELO');
    }

    // ⚠️ Sem handler o botão NÃO é desenhado (D4), e sem rótulo também não.
    assert.ok(!render(Linha, { linha: LINHA }).includes('<button'), 'o botão de ação apareceu SEM handler');
    assert.ok(render(Linha, { linha: LINHA }).includes('Poltrona Beny'), 'ISCA CEGA: o render sem handler estava vazio');
    for (const semRotulo of [{ paleta: 'ver' }, { rotulo: '', paleta: 'ver' }, { rotulo: {}, paleta: 'ver' }]) {
        const html = render(Linha, { linha: { ...LINHA, acao: semRotulo }, onAbrir: nada });
        assert.ok(!html.includes('<button'), 'um botão EM BRANCO foi desenhado');
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. ⚠️⚠️ `preparando` NÃO ABRE — E O CLIQUE É EXERCIDO DE VERDADE
//
// A tela preta da wave 6 foi **NO CLIQUE**, e `renderToStaticMarkup` não
// dispara `onClick`. É por isso que `aoAbrir` é EXPORTADA: sem ela, este
// caminho ficaria sem gate nenhum.
// ═══════════════════════════════════════════════════════════════════════════

test('`preparando` NÃO abre (D9), e o CLIQUE adverso não derruba nada 187_08', () => {
    const chamadas = [];
    const registra = (id) => chamadas.push(id);

    // Abre: o id INTEIRO chega ao destino (nunca a linha inteira, que viraria
    // `[object Object]` na querystring).
    MOD_LINHA.aoAbrir(LINHA, registra);
    assert.deepEqual(chamadas, [9001], 'a linha aberta não chamou o destino com o id inteiro');

    // ⚠️⚠️ `preparando`: `abre: false` → NÃO chama. É a D9 — "consertar" levaria a
    // pessoa ao painel de uma oferta que o sistema ainda está montando.
    chamadas.length = 0;
    MOD_LINHA.aoAbrir({ ...LINHA, abre: false }, registra);
    assert.deepEqual(chamadas, [], '`preparando` ABRIU a linha');

    // ⚠️ E `abre` por TRUTHINESS não abre.
    for (const quase of ['sim', 1, {}, [], 'true', null, undefined]) {
        chamadas.length = 0;
        MOD_LINHA.aoAbrir({ ...LINHA, abre: quase }, registra);
        assert.deepEqual(chamadas, [], 'a linha abriu por truthiness');
    }

    // ⚠️ `id` que não é inteiro não vai para a URL.
    for (const cru of ['9001', 90.5, {}, [], null, undefined, NaN, { toString: null }]) {
        chamadas.length = 0;
        MOD_LINHA.aoAbrir({ ...LINHA, id: cru }, registra);
        assert.deepEqual(chamadas, [], 'um id que não é inteiro foi interpolado na URL');
    }

    // ⚠️⚠️ O CLIQUE com a LINHA INTEIRA adversa não LANÇA. `String(valor)`,
    // `hasOwnProperty` e `obj[chave]` lançam com `{toString: null}`.
    const adversas = [null, undefined, 'texto', 42, [], NaN, { toString: null }, { hasOwnProperty: null }, { valueOf: null }];
    for (let i = 0; i < adversas.length; i += 1) {
        // ⚠️ Flag DENTRO do laço (é o mesmo motivo do `.map()`).
        const forma = `forma #${i}`;
        assert.doesNotThrow(() => MOD_LINHA.aoAbrir(adversas[i], registra), `o clique LANÇOU com a ${forma}`);
        assert.doesNotThrow(() => MOD_LINHA.aoAbrir(LINHA, adversas[i]), `o clique LANÇOU com destino adverso: ${forma}`);
    }

    // ⚠️ `onAbrir` que não é função é ignorado, sem lançar.
    assert.doesNotThrow(() => MOD_LINHA.aoAbrir(LINHA, null));

    // ⚠️ ISCA DA SONDA: o espião CONTA de verdade. Sem isto, "não chamou" poderia
    // ser um espião quebrado, e todos os casos acima passariam por engano.
    chamadas.length = 0;
    MOD_LINHA.aoAbrir({ ...LINHA, id: 777 }, registra);
    assert.deepEqual(chamadas, [777], 'ISCA: o espião do clique não conta — os casos acima não provam nada');
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. A SUB-LINHA DO ANDAMENTO: ellipsis + `title` com o texto INTEIRO
// ═══════════════════════════════════════════════════════════════════════════

test('a sub-linha do andamento tem ellipsis e `title` com o texto INTEIRO 187_08', () => {
    const celula = ARVORE.find((e) => classesDe(e).has('items-start') && classesDe(e).has('pr-[10px]'));
    assert.ok(celula, 'não achei a célula do andamento (`:169`)');
    for (const classe of ['flex', 'min-w-0', 'flex-col', 'gap-[4px]']) {
        assert.ok(classesDe(celula).has(classe), `a célula do andamento perdeu \`${classe}\``);
    }

    const sub = ARVORE.find((e) => classesDe(e).has('max-w-full') && classesDe(e).has('truncate'));
    assert.ok(sub, 'a sub-linha do andamento não saiu com `max-w-full truncate`');
    assert.equal(sub.titulo, 'Bloqueado: fotos a conferir +1', 'a sub-linha perdeu o `title` com o texto inteiro');
    assert.ok(classesDe(sub).has('text-[11.5px]'), 'a sub-linha perdeu o `text-[11.5px]` de `:174`');
    assert.ok(classesDe(sub).has('text-pub-danger-txt'), 'a cor da sub-linha não veio da chave do servidor');

    // ⚠️ Texto LONGO não quebra o grid: a classe de ellipsis continua, e o `title`
    // traz tudo. Sem o `title`, o resto da frase ficaria INALCANÇÁVEL.
    const longo = 'Bloqueado: resultado negativo com um motivo muito mais comprido do que a coluna de 164px +4';
    const comLongo = tagsDeAbertura(render(Linha, {
        linha: { ...LINHA, andamento: { ...LINHA.andamento, sub: longo } },
        onAbrir: nada,
    })).find((e) => classesDe(e).has('max-w-full') && classesDe(e).has('truncate'));
    assert.equal(comLongo.titulo, longo, 'o `title` da sub-linha longa não traz o texto inteiro');

    // ⚠️ `title=""` é um `title` AUSENTE: sub vazia não desenha a sub-linha.
    for (const vazia of ['', '   ', null, undefined, {}, [], 42, NaN]) {
        const arvore = tagsDeAbertura(render(Linha, {
            linha: { ...LINHA, andamento: { ...LINHA.andamento, sub: vazia } },
            onAbrir: nada,
        }));
        assert.ok(!arvore.some((e) => classesDe(e).has('max-w-full')), 'uma sub-linha VAZIA foi desenhada');
        assert.ok(arvore.some((e) => classesDe(e).has('rounded-pub-chip')), 'ISCA CEGA: a pill também desapareceu');
    }

    // ⚠️ A pill é `SeloDeAndamentoInterno` (Fase 186, SOMENTE LEITURA) — NÃO
    // redesenhada aqui. O rótulo dela vem do mapa daquele módulo.
    assert.match(FONTE_LINHA, /import SeloDeAndamentoInterno from '\.\/SeloDeAndamentoInterno'/, 'a pill foi redesenhada em vez de reusada');
    assert.ok(HTML.includes('Aguardando aprovação'), 'o rótulo da pill não veio do módulo da Fase 186');
    assert.ok(!FONTE_LINHA.includes('Aguardando aprovação'), 'o rótulo da pill foi RECOPIADO no JSX');
});

// ═══════════════════════════════════════════════════════════════════════════
// 9. ⚠️⚠️ O ANEL: OS 4 ESTADOS, E ELE NUNCA SAI VAZIO
// ═══════════════════════════════════════════════════════════════════════════

test('o ANEL tem os 4 estados com `title` NÃO-VAZIO, e chave desconhecida cai no NEUTRO 187_08', () => {
    const esperados = {
        nao_analisada: ['', 'border-pub-accent bg-transparent', 'Ainda não analisada'],
        parcial: ['', 'border-pub-txt-2 bg-pub-anel-parcial', 'Revisão parcial salva'],
        aprovada: ['✓', 'border-pub-ok bg-pub-ok', 'Aprovada'],
        falha: ['!', 'border-pub-danger bg-pub-danger', 'Falha na publicação'],
    };

    for (const [chave, [glifo, aparencia, titulo]] of Object.entries(esperados)) {
        // ⚠️ Flag DENTRO do laço.
        const estado = MOD_ANEL.estadoDoAnel(chave);
        assert.equal(estado.glifo, glifo, `o glifo de \`${chave}\` divergiu de \`:1241\``);
        assert.equal(estado.aparencia, aparencia, `a aparência de \`${chave}\` divergiu de \`:1241\``);
        assert.equal(estado.titulo, titulo, `o \`title\` de \`${chave}\` divergiu de \`:1241\``);
        assert.equal(estado.conhecido, true);

        const el = tagsDeAbertura(render(Anel, { progresso: chave }))[0];
        assert.equal(el.titulo, titulo, `o anel \`${chave}\` não levou o \`title\` ao HTML`);
        // ⚠️⚠️ **O `title` É SEMPRE TEXTO NÃO-VAZIO.** `title=""` é um `title`
        // ausente, e aí a COR viraria o único sinal.
        assert.ok(typeof el.titulo === 'string' && el.titulo.trim() !== '', `o anel \`${chave}\` saiu com \`title\` VAZIO`);
        for (const classe of aparencia.split(' ')) {
            assert.ok(classesDe(el).has(classe), `o anel \`${chave}\` perdeu \`${classe}\``);
        }
        for (const classe of ['grid', 'h-[16px]', 'w-[16px]', 'place-items-center', 'rounded-full', 'border-[1.5px]', 'text-[9.5px]', 'font-[800]', 'text-pub-page']) {
            assert.ok(classesDe(el).has(classe), `o anel \`${chave}\` perdeu a geometria \`${classe}\` de \`:135\``);
        }
    }

    // Os quatro `title` são DIFERENTES entre si.
    const titulos = Object.values(esperados).map(([, , t]) => t);
    assert.equal(new Set(titulos).size, 4, 'dois estados do anel têm o MESMO `title`');

    // ⚠️⚠️ **CHAVE DESCONHECIDA NUNCA DEVOLVE `null`.** Guarda que devolvesse
    // `null` produziria elemento sem borda, sem fundo e sem texto — que passa em
    // TODO teste de render e DESAPARECE na tela.
    for (const cru of ['nao_existe', '', '   ', null, undefined, 42, NaN, {}, [],
        { toString: null }, { hasOwnProperty: null }, true]) {
        const html = render(Anel, { progresso: cru });
        assert.notEqual(html, '', 'o anel saiu VAZIO com chave desconhecida');
        const el = tagsDeAbertura(html)[0];
        assert.ok(el, 'o anel não desenhou elemento nenhum');
        assert.ok(typeof el.titulo === 'string' && el.titulo.trim() !== '', 'o anel desconhecido saiu sem `title`');
        assert.equal(el.titulo, MOD_ANEL.NEUTRO.titulo, 'o anel desconhecido não caiu no NEUTRO');
        // ⚠️ E NUNCA no `parcial`: afirmaria "revisão parcial salva" sobre uma
        // oferta de que não se sabe nada.
        assert.equal(classesDe(el).has('bg-pub-anel-parcial'), false, 'chave desconhecida virou `parcial`');
        assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, 'o anel desconhecido vazou valor cru');
    }

    // ⚠️ `className` só vale como string (número viraria a classe `42`).
    for (const cru of [42, {}, [], null, undefined, '']) {
        assert.doesNotThrow(() => render(Anel, { progresso: 'parcial', className: cru }));
    }
    assert.ok(classesDe(tagsDeAbertura(render(Anel, { progresso: 'parcial', className: 'ml-[2px]' }))[0]).has('ml-[2px]'));
});

// ═══════════════════════════════════════════════════════════════════════════
// 10. A BARRA DE MARGEM: clamp, o traço em `minimo/40` e a cor nunca sozinha
// ═══════════════════════════════════════════════════════════════════════════

test('a BARRA: 4px, largura por `style`, clamp e o traço em `minimo/40` (D10) 187_08', () => {
    const arvore = tagsDeAbertura(render(Barra, { margem: LINHA.margem }));

    const coluna = arvore[0];
    for (const classe of ['flex', 'flex-col', 'gap-[6px]', 'pr-[12px]']) {
        assert.ok(classesDe(coluna).has(classe), `a coluna da margem perdeu \`${classe}\` de \`:161\``);
    }

    const numero = arvore.find((e) => classesDe(e).has('text-[13px]'));
    assert.ok(numero, 'o percentual não saiu');
    for (const classe of ['font-semibold', 'tabular-nums', 'text-pub-txt']) {
        assert.ok(classesDe(numero).has(classe), `o percentual perdeu \`${classe}\` de \`:162\``);
    }

    const trilha = arvore.find((e) => classesDe(e).has('h-[4px]'));
    assert.ok(trilha, 'a trilha de 4px não saiu');
    for (const classe of ['relative', 'rounded-[2px]', 'bg-pub-line-subtle']) {
        assert.ok(classesDe(trilha).has(classe), `a trilha perdeu \`${classe}\` de \`:163\``);
    }

    // ⚠️⚠️ A LARGURA VAI POR `style`, nunca por classe: classe montada por
    // concatenação o scanner do Tailwind NÃO VÊ — ela fica no HTML, o render passa
    // e a barra não aparece na tela.
    const preenchimento = arvore.find((e) => classesDe(e).has('inset-y-0'));
    assert.ok(preenchimento, 'o preenchimento não saiu');
    assert.match(preenchimento.estilo ?? '', /width:\s*35\.5%/, 'a largura não veio por `style`');
    for (const classe of ['absolute', 'left-0', 'rounded-[2px]', 'bg-pub-txt-2']) {
        assert.ok(classesDe(preenchimento).has(classe), `o preenchimento perdeu \`${classe}\``);
    }
    // ⚠️⚠️ **O INVARIANTE É O ARTEFATO, NÃO O GREP — e isto é CONSERTO MEDIDO
    // nesta sessão.** A primeira redação era `!FONTE_BARRA.includes('w-[')` e ela
    // **REPROVOU O CÓDIGO CERTO**: `w-[1.5px]` é a LARGURA LEGÍTIMA do traço do
    // mínimo (`:163`). Era gate mais largo que o invariante, e o conserto barato
    // seria tirar a sonda (perdendo a prova da largura por `style`).
    // ➡️ Duas provas no lugar certo:
    //   (a) o PREENCHIMENTO não tem NENHUMA classe de largura — ela é `style`;
    //   (b) nenhum arquivo deste plano monta classe por CONCATENAÇÃO, que é a
    //       forma que o scanner do Tailwind NÃO VÊ (a classe fica no HTML, o
    //       render passa e a barra não aparece na tela).
    assert.deepEqual(
        [...classesDe(preenchimento)].filter((c) => c.startsWith('w-')),
        [],
        'o preenchimento ganhou classe de largura — ela tem de vir por `style`',
    );
    const CLASSE_CONCATENADA = /`[^`]*-\[\$\{/;
    assert.doesNotMatch(TODAS_AS_FONTES, CLASSE_CONCATENADA, 'classe montada por concatenação: o Tailwind não a varre');
    // ⚠️ ISCA NAS DUAS DIREÇÕES.
    assert.match('className={`w-[${pct}]`}', CLASSE_CONCATENADA, 'a sonda da concatenação está CEGA');
    assert.match('cn(`h-[${n}px]`)', CLASSE_CONCATENADA);
    assert.doesNotMatch('tomDoProduto(`${idDoResponsavel}`)', CLASSE_CONCATENADA, 'a sonda reprova template literal legítimo');
    assert.doesNotMatch("cn(CLASSES_TRACO)", CLASSE_CONCATENADA);

    // ⚠️⚠️ **D10 — a FÓRMULA, não o número.** O protótipo escreve `left:30%` porque
    // `MIN = 12` e `12/40 = 30%`. Cravar `left-[30%]` amarraria a tela a um mínimo
    // que vem do parâmetro (FIN-09) e pode ser outro por empresa.
    const traco = arvore.find((e) => classesDe(e).has('w-[1.5px]'));
    assert.ok(traco, 'o traço do mínimo não saiu');
    assert.match(traco.estilo ?? '', /left:\s*30%/, 'o traço não ficou em `minimo/40` (30% com mínimo 12)');
    for (const classe of ['absolute', 'top-[-3px]', 'h-[10px]', 'bg-pub-txt-4']) {
        assert.ok(classesDe(traco).has(classe), `o traço perdeu \`${classe}\` de \`:163\``);
    }
    assert.ok(!FONTE_BARRA.includes('left-[30%]'), '`left-[30%]` foi CRAVADO — D10 pede a fórmula');

    // ⚠️ E com OUTRO mínimo o traço acompanha — é a prova da fórmula, e sem ela o
    // `30%` poderia ser literal e o gate não notaria.
    const outro = tagsDeAbertura(render(Barra, { margem: { ...LINHA.margem, minimo: '50%', minimo_valor: 20 } }))
        .find((e) => classesDe(e).has('w-[1.5px]'));
    assert.match(outro.estilo ?? '', /left:\s*50%/, 'o traço não acompanhou outro mínimo');

    // ⚠️ O CLAMP e as formas adversas: `width:"NaN%"` é CSS inválido (a barra
    // desaparece sem erro) e `95%` numa barra de teto 40 ESTOURARIA a trilha.
    const recusadas = ['-5%', '150%', '95', 'NaN%', '', 'abc%', '1e3%', '35,5%'];
    for (const cru of recusadas) {
        assert.equal(MOD_BARRA.percentualSeguro(cru), '0%', `a forma \`${cru}\` passou pela guarda`);
    }
    for (const cru of [null, undefined, NaN, 42, {}, [], { toString: null }, { valueOf: null }, true]) {
        assert.doesNotThrow(() => MOD_BARRA.percentualSeguro(cru));
        assert.equal(MOD_BARRA.percentualSeguro(cru), '0%', 'uma forma não-texto passou pela guarda');
    }
    // ⚠️ ISCA DA GUARDA, nas duas direções: ela ACEITA o que é legítimo.
    for (const boa of ['0%', '30%', '100%', '35.5%', '88.75%']) {
        assert.equal(MOD_BARRA.percentualSeguro(boa), boa, `a guarda recusou o percentual legítimo \`${boa}\``);
    }

    // ⚠️⚠️ **A COR NUNCA É O ÚNICO SINAL:** margem desconhecida sai `—` ESCRITO,
    // com largura zero e trilha neutra — nunca `0%`, que afirmaria margem zero.
    const semNumero = tagsDeAbertura(render(Barra, {
        margem: { valor: '—', cor: 'neutro', largura: '0%', barra: 'trilha', minimo: '30%' },
    }));
    assert.ok(render(Barra, { margem: { valor: '—', cor: 'neutro', largura: '0%', barra: 'trilha', minimo: '30%' } }).includes('—'),
        'a margem desconhecida não escreveu o travessão');
    assert.equal(MOD_BARRA.SEM_NUMERO, '—', 'o travessão não é U+2014');
    const barraNeutra = semNumero.find((e) => classesDe(e).has('inset-y-0'));
    assert.ok(classesDe(barraNeutra).has('bg-pub-line-off'), 'a barra neutra não usou a trilha de `:1246`');
    assert.match(barraNeutra.estilo ?? '', /width:\s*0%/, 'a barra neutra não ficou em 0%');

    // ⚠️ A célula INTEIRA aparece mesmo sem dado: a coluna existe no grid de 11, e
    // sumir com ela desalinharia o cabeçalho.
    for (const cru of [null, undefined, 'texto', 42, [], NaN, { toString: null }, { hasOwnProperty: null }]) {
        const html = render(Barra, { margem: cru });
        assert.notEqual(html, '', 'a célula da margem desapareceu com dado adverso');
        assert.ok(html.includes('—'), 'a célula adversa não escreveu o travessão');
        assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, 'a célula da margem vazou valor cru');
    }

    // As quatro cores do número e as quatro da barra, por chave do servidor.
    assert.deepEqual(MOD_BARRA.CORES_DO_NUMERO, {
        neutro: 'text-pub-txt-4', vermelho: 'text-pub-danger-txt', ambar: 'text-pub-warn-txt', texto: 'text-pub-txt',
    }, 'as cores do número divergiram de `:1246`');
    assert.deepEqual(MOD_BARRA.CORES_DA_BARRA, {
        trilha: 'bg-pub-line-off', barra_baixa: 'bg-pub-danger', barra_alerta: 'bg-pub-warn', suave: 'bg-pub-txt-2',
    }, 'as cores da barra divergiram de `:1246`');
    // ⚠️ São hex DIFERENTES dos do número, e isso é do protótipo: unificar os dois
    // mapas mudaria as duas coisas.
    assert.equal(
        Object.values(MOD_BARRA.CORES_DO_NUMERO).filter((t) => Object.values(MOD_BARRA.CORES_DA_BARRA).includes(t)).length,
        0,
        'um token aparece nos DOIS mapas — eles são diferentes de propósito',
    );

    // ⚠️ D4 / decisão 9: a célula diz DE ONDE o número vem, e o `title` nomeia a
    // fonte. `Fila/ResumoRapidoService` usa OUTRO número, de propósito.
    assert.equal(tagsDeAbertura(render(Barra, { margem: LINHA.margem }))
        .find((e) => classesDe(e).has('text-[13px]')).titulo,
    'Margem de contribuição estimada · com a tarifa real do Mercado Livre',
    'o balão da margem perdeu a fonte do número');
});

// ═══════════════════════════════════════════════════════════════════════════
// 11. ⚠️⚠️ RENDER ADVERSO EM CADA CAMPO — a TELA PRETA
//
// `String(valor)`, `hasOwnProperty` e qualquer `obj[chave]` LANÇAM com
// `{toString: null}`; prop de NÓ chegando objeto comum faz o React LANÇAR e
// mata a página. Voltou QUATRO vezes na Fase 180 e UMA na wave 6.
// ⚠️ A mensagem de asserção usa o NOME DA FORMA e o CAMINHO — nunca `String()`
// nem `JSON.stringify()` do valor adverso, que lançariam no próprio relatório.
// ═══════════════════════════════════════════════════════════════════════════

test('RENDER ADVERSO em cada campo da linha, e com a prop INTEIRA adversa 187_08', () => {
    const FORMAS = [
        ['objeto com toString nulo', () => ({ toString: null })],
        ['objeto com hasOwnProperty nulo', () => ({ hasOwnProperty: null })],
        ['objeto comum', () => ({ a: 1 })],
        ['nulo', () => null],
        ['NaN', () => NaN],
        ['array', () => [1, 2]],
        ['texto vazio', () => ''],
        ['booleano', () => true],
    ];

    const CAMINHOS = [];
    for (const chave of Object.keys(LINHA)) {
        CAMINHOS.push([chave]);
        const valor = LINHA[chave];
        if (valor !== null && typeof valor === 'object' && !Array.isArray(valor)) {
            for (const sub of Object.keys(valor)) CAMINHOS.push([chave, sub]);
        }
    }
    // ⚠️ ISCA DO INVENTÁRIO: a varredura cobre os ~53 campos de verdade. Com a
    // lista vazia (ou com 3 campos) todo o laço abaixo passaria medindo NADA.
    assert.ok(CAMINHOS.length >= 50, `a varredura achou só ${CAMINHOS.length} campos — esperava >= 50`);

    const infratores = [];

    for (const caminho of CAMINHOS) {
        for (const [nomeDaForma, cria] of FORMAS) {
            // ⚠️⚠️ TODA flag nasce DENTRO do laço (é o mesmo motivo do `.map()`).
            const rotulo = `${caminho.join('.')} = ${nomeDaForma}`;
            const copia = { ...LINHA };
            if (caminho.length === 1) {
                copia[caminho[0]] = cria();
            } else {
                copia[caminho[0]] = { ...LINHA[caminho[0]], [caminho[1]]: cria() };
            }

            let html = null;
            try {
                html = render(Linha, { linha: copia, onAbrir: nada, onMarcar: nada });
            } catch {
                infratores.push(`${rotulo}: LANÇOU no render`);
                continue;
            }

            if (/\[object Object\]|NaN|undefined|Invalid Date/.test(html)) {
                infratores.push(`${rotulo}: vazou valor cru na tela`);
            }

            // ⚠️⚠️ E NO CLIQUE: a tela preta da wave 6 foi no clique, não no render.
            try {
                MOD_LINHA.aoAbrir(copia, nada);
            } catch {
                infratores.push(`${rotulo}: LANÇOU no clique`);
            }
        }
    }

    // ⚠️ Campo AUSENTE (`delete`), que é diferente de `undefined` explícito em
    // `hasOwnProperty`.
    for (const caminho of CAMINHOS) {
        const rotulo = `${caminho.join('.')} AUSENTE`;
        const copia = { ...LINHA };
        if (caminho.length === 1) {
            delete copia[caminho[0]];
        } else {
            copia[caminho[0]] = { ...LINHA[caminho[0]] };
            delete copia[caminho[0]][caminho[1]];
        }

        try {
            const html = render(Linha, { linha: copia, onAbrir: nada, onMarcar: nada });
            if (/\[object Object\]|NaN|undefined|Invalid Date/.test(html)) {
                infratores.push(`${rotulo}: vazou valor cru na tela`);
            }
            MOD_LINHA.aoAbrir(copia, nada);
        } catch {
            infratores.push(`${rotulo}: LANÇOU`);
        }
    }

    // A prop INTEIRA adversa, e as props de handler adversas.
    for (const [nomeDaForma, cria] of FORMAS) {
        const rotulo = `linha INTEIRA = ${nomeDaForma}`;
        try {
            const html = render(Linha, { linha: cria(), onAbrir: nada });
            if (/\[object Object\]|NaN|undefined|Invalid Date/.test(html)) {
                infratores.push(`${rotulo}: vazou valor cru`);
            }
        } catch {
            infratores.push(`${rotulo}: LANÇOU`);
        }

        try {
            render(Linha, { linha: LINHA, onAbrir: cria(), onMarcar: cria(), marcada: cria() });
        } catch {
            infratores.push(`handlers = ${nomeDaForma}: LANÇOU`);
        }
    }

    // `quadrados` chegando string (tem `.length` e não tem `.map`) e `acao` sem rótulo.
    for (const [rotulo, linha] of [
        ['quadrados string', { ...LINHA, fotos: { ...LINHA.fotos, quadrados: 'tres' } }],
        ['quadrados com itens adversos', { ...LINHA, fotos: { ...LINHA.fotos, quadrados: [null, 'x', 42, {}, { toString: null }] } }],
        ['acao sem rotulo', { ...LINHA, acao: { paleta: 'ver' } }],
        ['meta vazia', { ...LINHA, meta: {} }],
        ['tudo vazio menos o id', { id: 1 }],
    ]) {
        try {
            const html = render(Linha, { linha, onAbrir: nada, onMarcar: nada });
            if (/\[object Object\]|NaN|undefined|Invalid Date/.test(html)) {
                infratores.push(`${rotulo}: vazou valor cru`);
            }
        } catch {
            infratores.push(`${rotulo}: LANÇOU`);
        }
    }

    assert.deepEqual(infratores, [], 'render/clique adverso derrubou ou vazou');

    // ⚠️ ISCA DA SONDA, nas duas direções: ela PEGA o vazamento que procura.
    assert.match('<span>[object Object]</span>', /\[object Object\]|NaN|undefined|Invalid Date/);
    assert.match('<span>NaN</span>', /\[object Object\]|NaN|undefined|Invalid Date/);
    assert.doesNotMatch('<span>R$ 184,20</span>', /\[object Object\]|NaN|undefined|Invalid Date/);
    // E o render PRISTINO não casa a sonda (senão todo o laço acima seria ruído).
    assert.doesNotMatch(HTML, /\[object Object\]|NaN|undefined|Invalid Date/, 'o render PRISTINO já vaza — a sonda não discrimina');
});

// ═══════════════════════════════════════════════════════════════════════════
// 12. FLAG DENTRO DO CALLBACK + AS AUSÊNCIAS (lista CONSTANTE de arquivos)
// ═══════════════════════════════════════════════════════════════════════════

test('TODA flag nasce DENTRO do callback do `.map()`, e as ausências valem 187_08', () => {
    // ⚠️⚠️ O Rollup ELIMINA variável de escopo do componente usada dentro do
    // `.map()`, e o `ReferenceError` só aparece no bundle de PRODUÇÃO.
    const maps = [...FONTE_LINHA.matchAll(/\.map\(\(([^)]*)\)\s*=>\s*\{/g)];
    assert.equal(maps.length, 1, `achei ${maps.length} \`.map()\` com corpo em LinhaDaOferta — esperava 1 (os pedaços da meta)`);

    // O INVARIANTE: o corpo declara flag PRÓPRIA.
    const corpo = FONTE_LINHA.slice(maps[0].index, maps[0].index + 900);
    assert.match(corpo, /\bconst\s+\w+\s*=/, `o corpo do \`.map()\` não declara flag própria: ${corpo.slice(0, 90)}`);
    assert.match(corpo, /const ehSku\b/, 'a flag do pedaço desapareceu do callback');

    // ⚠️⚠️ **O ÍNDICE NÃO É COBRADO AQUI, E ISSO É DECISÃO MEDIDA.** O gate da wave
    // 7 cobra o índice **só** dos `.map()` sobre dado do SERVIDOR, porque é lá que
    // a chave do React pode faltar. Este `.map()` percorre a lista que
    // `pedacosDaMeta()` acabou de construir, e a chave é um literal de uma lista
    // FECHADA (`sku`/`origem`/`versao`/`excecao`) — nunca valor do servidor. Pedir
    // o índice aqui seria gate mais largo que o invariante (NONA ocorrência dessa
    // família nesta milestone), e a wave 7 já reprovou código CERTO assim.
    assert.deepEqual(
        MOD_LINHA.pedacosDaMeta(LINHA.meta).map((p) => p.chave).sort(),
        ['excecao', 'origem', 'sku', 'versao'],
        'as chaves dos pedaços deixaram de ser a lista fechada — aí o índice passa a ser necessário',
    );

    // ⚠️ ISCA DA SONDA DO `.map()`: ela pega a forma COM corpo e não a sem.
    assert.equal([...'x.map((a, i) => { return a; })'.matchAll(/\.map\(\(([^)]*)\)\s*=>\s*\{/g)].length, 1);
    assert.equal([...'x.map((a) => a)'.matchAll(/\.map\(\(([^)]*)\)\s*=>\s*\{/g)].length, 0);

    // ─── AS AUSÊNCIAS, na lista CONSTANTE dos arquivos deste plano ───
    const infratores = [];
    for (const [caminho, fonte] of FONTES_DO_PLANO) {
        if (/localStorage|sessionStorage/.test(fonte)) infratores.push(`${caminho}: localStorage`);
        if (/Restaurar dados de exemplo/.test(fonte)) infratores.push(`${caminho}: dado de exemplo`);
        if (/lucro\s*l[íi]quido|lucro_liquido/i.test(fonte)) infratores.push(`${caminho}: lucro líquido`);
        if (/String\s*\(/.test(fonte)) infratores.push(`${caminho}: String(`);
        if (/JSON\.stringify/.test(fonte)) infratores.push(`${caminho}: JSON.stringify`);
        if (/new Date\s*\(/.test(fonte)) infratores.push(`${caminho}: new Date(`);
        if (/#[0-9A-Fa-f]{6}\b/.test(fonte)) infratores.push(`${caminho}: hex cru`);
        if (/\becf-(bg|card|yellow)\b|font-display|border-white\//.test(fonte)) infratores.push(`${caminho}: token vetado (FID-04)`);
        if (/\btext-(xs|sm|base|lg)\b/.test(fonte)) infratores.push(`${caminho}: escala nomeada de texto (FID-03)`);
    }
    assert.deepEqual(infratores, [], 'ausências violadas nos arquivos deste plano');

    // ⚠️ ISCAS DAS NOVE SONDAS, nas duas direções — cada uma PEGA a forma que
    // procura. Sem isto, uma regex quebrada seria gate que nunca reprova.
    assert.match('window.localStorage.getItem("x")', /localStorage|sessionStorage/);
    assert.match('Restaurar dados de exemplo', /Restaurar dados de exemplo/);
    assert.match('o lucro líquido do mês', /lucro\s*l[íi]quido|lucro_liquido/i);
    assert.match('lucro_liquido', /lucro\s*l[íi]quido|lucro_liquido/i);
    assert.match('String(v)', /String\s*\(/);
    assert.match('JSON.stringify(v)', /JSON\.stringify/);
    assert.match('new Date(x)', /new Date\s*\(/);
    assert.match('color: #FACC15;', /#[0-9A-Fa-f]{6}\b/);
    assert.match('bg-ecf-card', /\becf-(bg|card|yellow)\b|font-display|border-white\//);
    assert.match('text-sm', /\btext-(xs|sm|base|lg)\b/);
    // E NÃO pegam o que é legítimo.
    assert.doesNotMatch('const lucro = 1;', /lucro\s*l[íi]quido|lucro_liquido/i);
    assert.doesNotMatch('toUpperCase()', /String\s*\(/);
    assert.doesNotMatch('text-[13.5px]', /\btext-(xs|sm|base|lg)\b/);
    assert.doesNotMatch('text-pub-txt-3', /\btext-(xs|sm|base|lg)\b/);

    // ⚠️⚠️ "Restaurar dados de exemplo" EXISTE no protótipo (`:200`) e
    // `localStorage` também (`:914`) — a ausência aqui é DECISÃO (FILA-16 /
    // COL-02), não acaso. Sem esta prova, as asserções acima poderiam estar
    // medindo literais que nunca existiram.
    if (TEM_PROTOTIPO) {
        assert.ok(TEXTO_PROTOTIPO.includes('Restaurar dados de exemplo'), 'o protótipo não tem o literal — a ausência não prova nada');
        assert.ok(TEXTO_PROTOTIPO.includes("KEY = 'ecf-aprovacao-v1'"), 'o protótipo não tem o `localStorage` — a ausência não prova nada');
    }

    // ⚠️ VOC-03/VOC-04: os rótulos longos vêm do MODELO, LIDOS, e aparecem no
    // `title` das duas células de dinheiro. **Nunca "lucro líquido".**
    assert.match(FONTE_COMPOSICAO, /ROTULO_RESULTADO\s*=\s*'Resultado estimado por venda'/, 'o rótulo do resultado saiu do modelo');
    assert.match(FONTE_COMPOSICAO, /ROTULO_MARGEM\s*=\s*'Margem de contribuição estimada'/, 'o rótulo da margem saiu do modelo');
    assert.ok(HTML.includes('Resultado estimado por venda'), 'o `title` do resultado não chegou à tela');
    assert.ok(!FONTE_LINHA.includes('Resultado estimado por venda'), 'o rótulo longo foi RECOPIADO no JSX');
});

// ═══════════════════════════════════════════════════════════════════════════
// 13. O CSS CONSTRUÍDO — a classe EXISTE com o valor certo, não só "está escrita"
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
 * O seletor que o Tailwind emite: `.` + o nome com os metacaracteres escapados.
 *
 * ⚠️ A REGRA É GERAL — *escapa tudo que não é `[A-Za-z0-9_-]`*. A forma herdada
 * das waves 1-3 escapava só `[].:/%`, e com ela classe com `(`/`,` saía
 * **AUSENTE** estando presente e compilada (medido na wave 4). Aqui é o caso do
 * grid das 11 colunas.
 */
function seletorDe(classe) {
    return `.${[...classe].map((ch) => (/[A-Za-z0-9_-]/.test(ch) ? ch : `\\${ch}`)).join('')}`;
}

/**
 * Os caminhos cujo `mtime` o CSS tem de superar.
 *
 * ⚠️ TODOS os `.jsx` deste plano entram, MAIS a página: se um ficasse de fora,
 * mutá-lo deixaria a camada de CSS medir um build velho e o gate aprovaria o
 * errado. ⚠️ É esta guarda que produz os DOIS REGIMES de contagem do manifesto —
 * sem eles a campanha de mutação abortaria na 1ª execução ou marcaria
 * "SOBREVIVEU" por um motivo que NÃO é o código (learnings §4 caso 11).
 */
const FONTES_DO_CSS = [REL_LINHA, REL_ANEL, REL_BARRA, REL_TABELA, REL_PAGINA];

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

test('FID — as classes da linha GERAM CSS com o valor certo 187_08', async (contexto) => {
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
        // ⚠️⚠️ As iscas foram MEDIDAS contra o CSS de verdade nesta sessão: `h-[64px]`
        // e `left-[30%]` **EXISTEM** no CSS (outros arquivos do projeto os usam) e
        // por isso **NÃO servem** como isca aqui — usá-los teria dado "PRESENTE" e
        // o gate reprovaria por um motivo que não é o meu. A ausência DELES é
        // cobrada na FONTE (blocos 1 e 10), onde o invariante de verdade mora.
        for (const isca of ['bg-pub-isca-187-08', 'text-[99.5px]', 'w-[997px]', 'h-[463px]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS.css.includes(isca), `a string da isca ${isca} aparece no CSS`);
        }
        assert.notEqual(regraDe('text-[12px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('⚠️⚠️ `duration-[Nms]` continua NÃO COMPILANDO, e `duration-150` compila', () => {
        assert.equal(regraDe('duration-[220ms]'), null, '`duration-[220ms]` passou a compilar — remeça a regra');
        assert.match(regraDe('duration-150'), /transition-duration:\s*\.?15s/, 'o CONTROLE da escala nomeada sumiu');
    });

    await contexto.test('⚠️⚠️ `pub-anel-parcial` ACORDA nesta wave, com o GRADIENTE literal de `:1241`', () => {
        const regra = regraDe('bg-pub-anel-parcial');
        assert.notEqual(regra, null, '`bg-pub-anel-parcial` não compilou — o token continua dormindo');
        // ⚠️ Token de `backgroundImage` sai como `background-image`, com o gradiente
        // literal (não é cor sólida, então NÃO vira `rgb()`).
        assert.match(
            regra,
            /background-image:\s*linear-gradient\(90deg,\s*#A1A1AA 50%,\s*transparent 50%\)/,
            `o gradiente compilado divergiu: ${regra}`,
        );

        // ⚠️ ISCA: o token EXISTE na camada com esse valor (o gate não está medindo
        // um nome que alguém inventou aqui).
        const config = fs.readFileSync(path.resolve(RAIZ, 'tailwind.config.js'), 'utf8');
        assert.match(config, /'pub-anel-parcial':\s*'linear-gradient\(90deg,#A1A1AA 50%,transparent 50%\)'/, 'o token saiu da camada');
    });

    await contexto.test('⚠️ os tokens `pub-float`/`pub-float-line` ACORDARAM no 187-09, e NÃO nesta wave', () => {
        // ⚠️⚠️ Medido no `tailwind.config.js`: os dois foram declarados para a
        // **barra de lote** (`:897`) e o **toast** (`:905`), que são do plano
        // **187-09** — NÃO para esta linha. Usar token fora do lugar onde ele foi
        // medido só para zerar um `skip` é o que a wave 4 recusou, e estava certa.
        //
        // ⚠️⚠️ **O PLANO 187-09 CHEGOU, E O `regraDe(...) === null` FOI INVERTIDO EM
        // VEZ DE AFROUXADO.** Aquela asserção era um PROXY: o invariante desta wave
        // nunca foi *"os tokens não compilam"* (isso não depende dela), e sim **"os
        // arquivos DESTA wave não os usam"**. O proxy virava falso no instante em
        // que a wave dona dos tokens chegasse — item 6 da régua.
        // ➡️ A forma nova é mais forte: prova que os tokens COMPILAM (se alguém
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
        assert.match(config, /float:\s*'#1C1C1C'/, 'o token `pub-float` saiu da camada — a prova perdeu o sentido');
        assert.match(config, /'float-line':\s*'#333333'/, 'o token `pub-float-line` saiu da camada');
    });

    await contexto.test('a GEOMETRIA da linha: 64px, 16px, 44px, 24px, 12px, 7px, 4px e 1.5px', () => {
        assert.match(regraDe('min-h-[64px]'), /min-height:\s*64px/);
        assert.match(regraDe('h-[16px]'), /height:\s*16px/);
        assert.match(regraDe('w-[16px]'), /width:\s*16px/);
        assert.match(regraDe('h-[44px]'), /height:\s*44px/);
        assert.match(regraDe('w-[44px]'), /width:\s*44px/);
        assert.match(regraDe('h-[24px]'), /height:\s*24px/);
        assert.match(regraDe('h-[30px]'), /height:\s*30px/);
        assert.match(regraDe('h-[12px]'), /height:\s*12px/);
        assert.match(regraDe('h-[7px]'), /height:\s*7px/);
        assert.match(regraDe('w-[7px]'), /width:\s*7px/);
        assert.match(regraDe('h-[4px]'), /height:\s*4px/);
        assert.match(regraDe('h-[10px]'), /height:\s*10px/);
        assert.match(regraDe('w-[1.5px]'), /width:\s*1\.5px/);
        assert.match(regraDe('top-[-3px]'), /top:\s*-3px/);
        assert.match(regraDe('border-[1.5px]'), /border-width:\s*1\.5px/);
        assert.match(regraDe('rounded-[2px]'), /border-radius:\s*2px/);
        assert.match(regraDe('rounded-[8px]'), /border-radius:\s*8px/);
        assert.match(regraDe('rounded-[1px]'), /border-radius:\s*1px/);
        assert.match(regraDe('tracking-[0.05em]'), /letter-spacing:\s*\.05em/);
    });

    await contexto.test('⚠️ FID-03 — os meios-pixels COMPILAM (o passo do Tailwind não os tem)', () => {
        // Quem arredondasse `13.5` para `text-sm` (14px) mudaria o layout.
        assert.match(regraDe('text-[13.5px]'), /font-size:\s*13\.5px/);
        assert.match(regraDe('text-[12.5px]'), /font-size:\s*12\.5px/);
        assert.match(regraDe('text-[11.5px]'), /font-size:\s*11\.5px/);
        assert.match(regraDe('text-[9.5px]'), /font-size:\s*9\.5px/);
        assert.match(regraDe('font-[800]'), /font-weight:\s*800/);
    });

    await contexto.test('os QUATRO fundos de linha são hex DIFERENTES no CSS compilado', () => {
        // ⚠️ Token SÓLIDO não compila para hex: sai `rgb(34 29 7 / …)`, DECIMAL.
        assert.match(regraDe('bg-pub-row-sel'), /rgb\(\s*34\s+29\s+7\b/, '`#221D07` em decimal');
        assert.match(regraDe('bg-pub-hover-3'), /rgb\(\s*26\s+26\s+26\b/, '`#1A1A1A` em decimal');
        assert.match(regraDe('bg-pub-row-checked'), /rgb\(\s*24\s+22\s+12\b/, '`#18160C` em decimal');
        assert.match(regraDe('bg-pub-surface'), /rgb\(\s*17\s+17\s+17\b/, '`#111111` em decimal');
        assert.match(regraDe('bg-pub-hover-2'), /rgb\(\s*22\s+22\s+22\b/, '`#161616` em decimal (o hover)');

        // ⚠️ E os CINCO são distintos no artefato final — é a prova, no CSS, de que
        // os tons não colapsaram (FID-02: "usar o token parecido" é vetado).
        const corpos = ['bg-pub-row-sel', 'bg-pub-hover-3', 'bg-pub-row-checked', 'bg-pub-surface', 'bg-pub-hover-2']
            .map((c) => (regraDe(c).match(/rgb\([^)]*\)/) || [])[0]);
        assert.equal(new Set(corpos).size, 5, `dois fundos colapsaram no CSS: ${corpos.join(' | ')}`);
    });

    await contexto.test('a trilha, o traço e as cores semânticas da barra', () => {
        assert.match(regraDe('bg-pub-line-subtle'), /rgb\(\s*31\s+31\s+31\b/, '`#1F1F1F` da trilha');
        assert.match(regraDe('bg-pub-txt-4'), /rgb\(\s*113\s+113\s+122\b/, '`#71717A` do traço');
        assert.match(regraDe('bg-pub-line-off'), /rgb\(\s*63\s+63\s+70\b/, '`#3F3F46` da barra neutra');
        assert.match(regraDe('bg-pub-danger'), /rgb\(\s*239\s+68\s+68\b/, '`#EF4444` da barra negativa');
        assert.match(regraDe('bg-pub-warn'), /rgb\(\s*245\s+158\s+11\b/, '`#F59E0B` da barra abaixo do mínimo');
        assert.match(regraDe('bg-pub-txt-2'), /rgb\(\s*161\s+161\s+170\b/, '`#A1A1AA` da barra em ordem');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 14. A PÁGINA: o slot preenchido e o destino do clique
// ═══════════════════════════════════════════════════════════════════════════

test('a PÁGINA preencheu o slot `linhaDaOferta`, e o destino é o FOCO da oferta 187_08', () => {
    // ⚠️⚠️ **É O INVARIANTE QUE ESTA WAVE EXISTE PARA FECHAR.** Sem o slot, a
    // tabela desenha cabeçalho + linha de produto + sub-linha e **NENHUMA linha de
    // oferta** — a fila perderia o conteúdo que ela existe para mostrar.
    assert.match(FONTE_PAGINA, /linhaDaOferta=\{/, 'a página não preenche `linhaDaOferta`');
    assert.match(FONTE_PAGINA, /<LinhaDaOferta linha=\{oferta\}/, 'o slot não monta a linha com a oferta da árvore');
    assert.match(FONTE_PAGINA, /import LinhaDaOferta from '@\/Components\/Mlb\/Publicador\/aprovacao\/LinhaDaOferta'/, 'a página não importa o componente');

    // ⚠️ O par nó × dado do 187-04 CONTINUA valendo: `tabela` (NÓ) × `grupos`
    // (DADO), duas props com UM sentido cada.
    assert.match(FONTE_PAGINA, /isValidElement\(tabela\)\s*\?\s*tabela\s*:\s*<TabelaDaFila/, 'o slot `tabela` deixou de sobrepor o dado');
    assert.match(FONTE_PAGINA, /grupos=\{grupos\}/, 'a página não repassa `grupos` ao componente');

    // ⚠️⚠️ **O DESTINO É `irParaOferta`, CHAMADO DE NOVO E NÃO HOISTEADO.**
    // `publicador-fila-proximo-187.test.js:857` (wave 5) lê o TEXTO
    // `onRevisar={irParaOferta(empresaId, etapaAtiva)}` nesta fonte: guardar a
    // chamada numa variável e reusá-la reprovaria aquele gate. A função não faz
    // I/O — devolve um `router.get` fechado sobre os mesmos dois valores.
    assert.match(FONTE_PAGINA, /onRevisar=\{irParaOferta\(empresaId, etapaAtiva\)\}/, 'a faixa perdeu o destino (gate da wave 5)');
    assert.match(FONTE_PAGINA, /onAbrir=\{irParaOferta\(empresaId, etapaAtiva\)\}/, 'a linha não recebeu o destino do foco');

    // ⚠️⚠️ **O `onMarcarVisiveis` CHEGOU NO PLANO 187-09, e esta negativa foi
    // INVERTIDA em vez de afrouxada.** A mensagem original — *"a seleção em lote é
    // do plano 187-09"* — nomeava o plano que a tornaria FALSA: é o item 6 da régua
    // (*asserção escrita quando a peça ainda não tinha dono*), e a pergunta que ela
    // deveria ter respondido é *"o desenho PROÍBE isso, ou eu só não vi ainda?"*.
    // O desenho não proibia: `TabelaDaFila.jsx` nasceu (187-07) com o slot NOMEADO
    // E VAZIO exatamente para esta wave o preencher.
    // ➡️ A positiva é estritamente mais forte e permanente: sem o handler, o
    // checkbox do cabeçalho **não é desenhado** e a fila perde a seleção em lote —
    // e nenhum outro gate desta wave pegaria isso, porque os casos que rodam o
    // COMPONENTE provam "sem handler não há checkbox", não "a página o liga".
    assert.match(FONTE_PAGINA, /onMarcarVisiveis=\{marcarVisiveis\}/, 'a página deixou de ligar a seleção em lote (plano 187-09)');
    // ⚠️⚠️ 23/10/2026 (P-18c) — e esta negativa foi INVERTIDA pelo mesmo motivo da
    // de cima: ela nomeava o plano 187-09, que fechou SEM ligar o checkbox da
    // linha (o `LinhaDaOferta` sabia desenhá-lo; a página nunca passava
    // `onMarcar`). A positiva prova que a página o liga.
    assert.match(FONTE_PAGINA, /onMarcar=\{marcarOferta\}/, 'a página deixou de ligar o checkbox da LINHA (seleção uma a uma)');
    assert.doesNotMatch(FONTE_PAGINA, /onRevisarFotos=/, 'o painel de revisão é a Fase 188');

    // ⚠️ Zero `localStorage` e zero `String(` na página (gate da wave 4, e está certo).
    assert.doesNotMatch(FONTE_PAGINA, /localStorage|sessionStorage/);
    assert.doesNotMatch(FONTE_PAGINA, /String\s*\(/, 'a wave 4 proíbe `String(` nesta página');

    // ⚠️ E o CONTROLADOR liga o serviço, com UMA chamada por oferta (T-187-83).
    const fonteControlador = fs.readFileSync(path.resolve(RAIZ, 'app/Http/Controllers/MlbPublicadorAprovacaoController.php'), 'utf8');
    assert.match(fonteControlador, /\$linhaDaOferta->para\(/, 'o controlador não chama `LinhaDaOfertaService::para()`');
    assert.equal(
        (fonteControlador.match(/\$linhaDaOferta->para\(/g) || []).length,
        1,
        '`para()` é chamado mais de uma vez no controlador — cada chamada é uma requisição a mais ao Mercado Livre',
    );
});
