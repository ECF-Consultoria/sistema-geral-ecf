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
// Fase 179, plano 07 — "O que fazer agora", "Saúde da conta" e "Publicações no
// Mercado Livre" (VG-09/10/11/13/14/18).
//
// Protótipo: `new_layout_publicador/Visao Geral.dc.html` — ⚠️ as linhas abaixo
// foram MEDIDAS no arquivo; as do `<action>` do 179-07-PLAN.md estão
// deslocadas (ele cita `:162` onde o arquivo tem `:165`, `:225` onde tem `:235`,
// `:258` onde tem `:256`). Quem mediu ganha (FID-01).
//   · card de ações   → :165  (cabeçalho :166, coluna :167, H2 :168, sub :169)
//   · segmented       → :170  (opção :172, dot + contagem :173)
//   · linha da ação   → :176  (grid :177, tile :178, ícones :179-:181)
//   · texto da ação   → :182  (linha de título :183, título :184)
//   · badges          → :185 / :186
//   · descrição       → :188  · pilha :189 (tile :191) · CTA :193
//   · chevron         → :194  (svg :195)
//   · sub-lista       → :198  (item :200, ini :202, nome :203, meta :204,
//                              motivo :206, "Abrir →" :207, adiar :209/:210)
//   · vazio           → :216  (tile :217, check :218, textos :219)
//   · Saúde           → :235  (cabeçalho :236, H2 :237, geral :238, linha :240,
//                              tile :241, coluna :242, nome :243, meta :244,
//                              botão :246, spinner :247, pílula :250)
//   · Publicações     → :256  (cabeçalho :257, H2 :258, "Ver todas" :259,
//                              total :260, barra :261, faixa :262,
//                              legenda :263/:265/:266, acervo :268,
//                              mosaico :269-:271, textos :272)
//   · a aritmética    → :425 (`SEV`) · :512-:520 · :523-:532 · :534-:536 ·
//                       :603-:607
//
// ⚠️ REND-03: TODOS os bundles são montados aqui no topo, ANTES do primeiro
// `test()`. O `after()` do `node --test` já apagou o stub de um arquivo deste
// projeto no meio da suíte e matou o arquivo inteiro SEM nenhum teste falhar
// (sintoma na Fase 178: 37 testes em vez de 94).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

// Stub de `route()` global: `BarraDaConta.jsx` (de onde vêm `textoSeguro`/
// `textoUtil`) chama `route()` no corpo do módulo, e o Ziggy não existe no node.
global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

const STUB_INERTIA = path.join(__dirname, `.acoes-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
export const usePage = () => ({ props: { auth: { user: { id: 7 } } } });
`, 'utf8');
after(() => fs.rmSync(STUB_INERTIA, { force: true }));

async function montarModulo(caminhoRelativo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, caminhoRelativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA },
        // `axios` e os dois pacotes do Radix entram porque o import de
        // `textoUtil` arrasta `BarraDaConta` → `SeletorEmpresaBusca` → Radix.
        external: [
            'react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios',
            '@radix-ui/react-popover', '@radix-ui/react-dialog',
        ],
    });

    const outfile = path.join(__dirname, `.acoes-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const REL_ACOES = 'resources/js/Components/Mlb/Publicador/visaogeral/acoes.js';
const REL_CARD = 'resources/js/Components/Mlb/Publicador/visaogeral/OQueFazerAgora.jsx';
const REL_SAUDE = 'resources/js/Components/Mlb/Publicador/visaogeral/SaudeDaConta.jsx';
const REL_PUBS = 'resources/js/Components/Mlb/Publicador/visaogeral/PublicacoesNoMercadoLivre.jsx';

// ─── REND-03: os QUATRO bundles, aqui, antes de qualquer `test()` ───
const A = await montarModulo(REL_ACOES);
const { default: OQueFazerAgora } = await montarModulo(REL_CARD);
const { default: SaudeDaConta } = await montarModulo(REL_SAUDE);
const { default: PublicacoesNoMercadoLivre } = await montarModulo(REL_PUBS);

const FONTE_ACOES = lerSemComentarios(REL_ACOES);
const FONTE_CARD = lerSemComentarios(REL_CARD);
const FONTE_SAUDE = lerSemComentarios(REL_SAUDE);
const FONTE_PUBS = lerSemComentarios(REL_PUBS);

const FONTES_JSX = [[REL_CARD, FONTE_CARD], [REL_SAUDE, FONTE_SAUDE], [REL_PUBS, FONTE_PUBS]];

// ═══════════════════════════════════════════════════════════════════════════
// Os extratores de elemento
// ═══════════════════════════════════════════════════════════════════════════

/** Todas as tags de abertura do HTML, na ordem do documento. */
function tagsDe(html) {
    return [...html.matchAll(/<([a-zA-Z][\w-]*)\b([^>]*?)\/?>/g)].map((m) => ({
        nome: m[1],
        bruto: m[0],
        indice: m.index,
    }));
}

/** A lista de classes de UMA tag de abertura, partida por espaço. */
function classesDe(bruto) {
    const achado = /class="([^"]*)"/.exec(bruto);
    return (achado?.[1] ?? '').split(/\s+/).filter(Boolean);
}

/**
 * Compara a lista de classes de UM elemento por igualdade de CONJUNTO.
 * ⚠️ Não é `includes`: classe a mais reprova, a menos reprova, trocada reprova.
 * ⚠️ `html.includes('classe')` não serve: substring casa com PREFIXO
 * (`text-[12px]` dentro de `text-[12.5px]`, `bg-pub-accent` dentro de
 * `bg-pub-accent-sunken`) — foi assim que quatro mutações reais passaram na 177.
 */
function conferirClasses(bruto, esperadas, onde) {
    assert.deepEqual(
        [...classesDe(bruto)].sort(),
        [...esperadas].sort(),
        `${onde}: a lista de classes divergiu (tag: ${bruto})`,
    );
}

const tagsPorNome = (html, nome) => tagsDe(html).filter((t) => t.nome === nome);

/** A tag de abertura do elemento cujo conteúdo textual termina exatamente em `texto`. */
function tagComTexto(html, nome, texto) {
    const escapado = texto.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const achado = new RegExp(`<${nome}([^>]*)>${escapado}</${nome}>`).exec(html);
    assert.ok(achado !== null, `não achei o <${nome}> com o texto exato "${texto}"`);
    return `<${nome}${achado[1]}>`;
}

/** As duas `<div>` abertas imediatamente antes de um texto: o wrapper e o grid. */
function blocoDaAcao(html, tituloExato) {
    const indice = html.indexOf(`>${tituloExato}<`);
    assert.ok(indice > -1, `não achei o título da ação "${tituloExato}"`);
    const divs = tagsPorNome(html.slice(0, indice), 'div');
    assert.ok(divs.length >= 2, 'faltam as duas <div> estruturais da ação');
    const wrapper = divs[divs.length - 2];
    const grid = divs[divs.length - 1];
    // ⚠️ `depoisDoGrid` é índice ABSOLUTO: duas ações não recomendadas têm
    // wrapper e grid com a MESMA string de classes, e `html.indexOf(grid)`
    // devolveria sempre a primeira — o gate do tile media a ação errada.
    return {
        wrapper: wrapper.bruto,
        grid: grid.bruto,
        depoisDoGrid: grid.indice + grid.bruto.length,
    };
}

/**
 * Os `<span>` abertos antes de um texto, SEM os que carregam `tabular-nums`:
 * o número da ação é irmão do título e entraria no meio da contagem.
 */
function spansEstruturaisAntes(html, indice) {
    return tagsPorNome(html.slice(0, indice), 'span').filter((t) => !/tabular-nums/.test(t.bruto));
}

/** Os chevrons: um por ação renderizada (o único `<button>` com `aria-expanded`). */
const chevronsDe = (html) => [...html.matchAll(/<button([^>]*aria-expanded="[^"]*"[^>]*)>/g)].map((m) => `<button${m[1]}>`);

/** As opções do segmented: todo `<button>` SEM `aria-expanded` e SEM `title`. */
const opcoesDoSegmented = (html) =>
    [...html.matchAll(/<button\b([^>]*)>([\s\S]*?)<\/button>/g)]
        .map((m) => ({ abertura: `<button${m[1]}>`, interior: m[2] }))
        .filter((b) => !/aria-expanded/.test(b.abertura) && !/title=/.test(b.abertura));

/** As faixas da barra: os spans que carregam `flex-grow` no `style`. */
const faixasDaBarra = (html) =>
    [...html.matchAll(/<span([^>]*style="flex-grow:[^"]*"[^>]*)\/?>/g)].map((m) => `<span${m[1]}>`);

/** A última tag `nome` aberta ANTES de um índice — o contêiner estrutural. */
function tagAntes(html, indice, nome) {
    const tags = tagsPorNome(html.slice(0, indice), nome);
    assert.ok(tags.length > 0, `nenhuma <${nome}> antes do índice ${indice}`);
    return tags[tags.length - 1].bruto;
}

/**
 * O bloco de UMA linha da Saúde, achado pelo NOME da linha (nunca por classe).
 *
 * ⚠️ Âncora: o `<span>` do NOME (13px/500), não `>${nome}<` solto — o tile da
 * linha do ERP tem o texto `ERP` e casaria primeiro.
 * ⚠️ E o índice vem de `tagsDe` (ABSOLUTO), nunca de `html.indexOf(abertura)`:
 * duas linhas sem tint têm a MESMA string de classes, e o `indexOf` devolvia
 * sempre a primeira delas — o gate do `⟳` e os quatro do ERP mediam a linha
 * errada e passavam/reprovavam pelo motivo errado.
 */
function linhaDaSaude(html, nome) {
    const escapado = nome.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const ancora = new RegExp(`<span class="text-\\[13px\\] font-medium">${escapado}</span>`).exec(html);
    assert.ok(ancora !== null, `não achei a linha "${nome}"`);
    const divs = tagsPorNome(html.slice(0, ancora.index), 'div');
    const alvo = divs[divs.length - 1];
    const inicio = alvo.indice;
    const seguintes = tagsPorNome(html.slice(inicio + alvo.bruto.length), 'div');
    const fim = seguintes.length > 0
        ? inicio + alvo.bruto.length + seguintes[0].indice
        : html.length;
    return { abertura: alvo.bruto, bloco: html.slice(inicio, fim) };
}

const renderAcoes = (props = {}) => renderToStaticMarkup(React.createElement(OQueFazerAgora, props));
const renderSaude = (props = {}) => renderToStaticMarkup(React.createElement(SaudeDaConta, props));
const renderPubs = (props = {}) => renderToStaticMarkup(React.createElement(PublicacoesNoMercadoLivre, props));

const semLixo = (html, onde) => {
    assert.doesNotMatch(html, /\[object Object\]|NaN|undefined|Invalid Date/, `${onde}: lixo na tela`);
};

// ═══════════════════════════════════════════════════════════════════════════
// Fixtures
// ═══════════════════════════════════════════════════════════════════════════

const item = (nome, meta, motivo) => ({ nome, meta, motivo });

const ACOES_CHEIAS = () => ({
    acoes: [
        {
            chave: 'com_problema',
            severidade: 'critico',
            titulo: 'Produtos com problema na publicação',
            descricao: 'Imagem principal recusada · Título excede 60 caracteres',
            numero: 2,
            recomendada: true,
            itens: [
                item('Cadeira Office Ergo', 'F1 · Premium', 'Imagem principal recusada'),
                item('Fone Bluetooth XYZ', 'F2 ×2 · Premium', 'Título excede 60 caracteres'),
            ],
            destino: { rota: 'mlb.anuncios.meus', params: { company: 9 } },
        },
        {
            chave: 'pausados',
            severidade: 'atencao',
            titulo: 'Anúncios pausados ou sem estoque',
            descricao: '4 por falta de estoque · 3 pausadas manualmente',
            numero: 7,
            recomendada: false,
            itens: [],
            destino: { rota: 'mlb.anuncios.meus', params: { company: 9, motivo: 'pausado' } },
        },
        {
            chave: 'prontos_fase2',
            severidade: 'oportunidade',
            titulo: 'Prontos para a Fase 2',
            descricao: 'Fase 1 publicada e nenhum combo criado',
            numero: 13,
            recomendada: false,
            itens: [item('Cadeira Oslo', 'CAD-OSLO', 'Fase 1 concluída ontem')],
            destino: { rota: 'mlb.anuncios.publicador.produtos', params: { conta: 'c-459' } },
        },
    ],
    contagens: { todas: 3, critico: 1, atencao: 1, oportunidade: 1 },
    adiadas: [],
    volta_em_iso: '2026-10-14T08:00:00-03:00',
});

const SAUDE_CHEIA = () => ({
    geral: 'tudo_conectado',
    linhas: [
        {
            chave: 'mercado_livre',
            estado: 'ativo',
            conta_nome: 'ECFDEV02',
            conta_ml_id: '2015132968',
            problema: false,
            link_reconexao: null,
        },
        { chave: 'erp', declarado: 'Bling', problema: false },
        {
            chave: 'sincronizacao',
            coletado_em: '2026-10-13T09:00:00-03:00',
            horas: 4,
            defasado: false,
            nunca_coletado: false,
            atualizar_url: '/mlb/anuncios/meus/9/atualizar',
        },
    ],
});

const PUBS_CHEIAS = () => ({
    disponivel: true,
    total: 58,
    faixas: [
        { chave: 'ativas', total: 49, url: '/p?status=ativos' },
        { chave: 'pausadas', total: 7, url: '/p?status=pausados' },
        { chave: 'em_revisao', total: 2, url: '/p?status=em_revisao' },
        { chave: 'encerradas', total: 0, url: '/p?status=encerrados' },
    ],
    outras: 0,
    ativas_pct: 84,
    pedem_acao: { total: 3, url: '/p?pedem=1' },
});

const ACERVO_CHEIO = () => ({ imagens: 128, packs: 9 });

// ═══════════════════════════════════════════════════════════════════════════
// A. `acoes.js` — módulo PURO: nem React, nem JSX, nem classe do Tailwind
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ acoes.js é módulo PURO: nem React, nem JSX, nem CLASSE DO TAILWIND', async (contexto) => {
    await contexto.test('nenhum import de React e nenhum JSX', () => {
        assert.doesNotMatch(FONTE_ACOES, /from 'react'/);
        assert.doesNotMatch(FONTE_ACOES, /<\/?[A-Za-z][\w-]*[\s/>]/);
    });

    // ⚠️⚠️ O ACHADO DO PLANO 179-06: o `content` do Tailwind varre `*.jsx` e NÃO
    // `*.js`. Classe escrita num `.js` de `resources/js` não é varrida, não gera
    // CSS e NÃO EXISTE para a tela — com todos os testes de render passando,
    // porque a classe chega ao HTML. O `<action>` deste plano manda `SEVERIDADES`
    // carregar `texto`/`tint`/`dot`; obedecer deixaria a tinta do ícone e o dot
    // do segmented INVISÍVEIS, sem nada acusar.
    await contexto.test('⚠️ o `content` do tailwind.config.js varre *.jsx e NÃO *.js', () => {
        // ⚠️ LIDO CRU, não por `lerSemComentarios`: o helper remove blocos
        // `/* … */`, e `'./resources/js/**/*.jsx'` contém um `/*` seguido de `*/`
        // — o glob chegaria aqui como `'./resources/js*.jsx'` e o gate mediria
        // NADA (armadilha de medidor medida no 179-06).
        const config = fs.readFileSync(path.resolve(RAIZ, 'tailwind.config.js'), 'utf8');
        assert.match(config, /'\.\/resources\/js\/\*\*\/\*\.jsx'/);
        assert.doesNotMatch(config, /'\.\/resources\/js\/\*\*\/\*\.js'/);

        // ISCA: o helper REALMENTE mastiga o glob — se um dia parar de mastigar,
        // este teste avisa, e aí ler cru deixa de ser obrigatório.
        assert.doesNotMatch(lerSemComentarios('tailwind.config.js'), /'\.\/resources\/js\/\*\*\/\*\.jsx'/);
    });

    await contexto.test('⚠️ NENHUM dos 11 padrões de classe do Tailwind aparece em acoes.js', () => {
        const proibidos = [
            /\bbg-pub-/, /\btext-pub-/, /\bborder-pub-/, /\bstroke-pub-/, /\bfill-pub-/,
            /\brounded-pub-/, /\btext-\[\d/, /\bh-\[\d/, /\bw-\[\d/, /\bgap-\[\d/,
            /\bgrid-cols-/, /\bhover:/, /\btabular-nums\b/, /\bflex\b.*\bitems-center\b/,
        ];
        for (const padrao of proibidos) {
            assert.doesNotMatch(FONTE_ACOES, padrao, `classe do Tailwind em acoes.js: ${padrao}`);
        }
    });

    await contexto.test('⚠️ ISCA: a varredura acima acha os padrões quando eles existem', () => {
        // Sem isca, 14 `doesNotMatch` que nunca casam nada deixariam o gate
        // verdadeiro por construção.
        for (const [caminho, fonte] of FONTES_JSX) {
            assert.match(fonte, /\bbg-pub-/, `${caminho} deveria ter classes (a varredura mede)`);
            assert.match(fonte, /\btext-\[\d/, caminho);
        }
    });

    await contexto.test('os 3 mapas de classe de severidade moram no .jsx, não no .js', () => {
        for (const mapa of ['TINTA_DA_SEVERIDADE', 'TEXTO_DA_SEVERIDADE', 'DOT_DA_SEVERIDADE']) {
            assert.match(FONTE_CARD, new RegExp(`const ${mapa} = \\{`), `${mapa} saiu do .jsx`);
            assert.doesNotMatch(FONTE_ACOES, new RegExp(`\\b${mapa}\\b`), `${mapa} voltou para o .js`);
        }
        for (const mapa of ['COR_DO_GERAL', 'DOT_DO_GERAL', 'COR_DO_ESTADO', 'DOT_DO_ESTADO', 'TILE_DA_LINHA']) {
            assert.match(FONTE_SAUDE, new RegExp(`const ${mapa} = \\{`), `${mapa} saiu do .jsx`);
        }
        assert.match(FONTE_PUBS, /const COR_DA_FAIXA = \{/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// B. Os literais, byte a byte (TODOS são do cliente — 179-02/179-03)
// ═══════════════════════════════════════════════════════════════════════════

test('os literais de severidade e de aba (:425, :515, :520)', async (contexto) => {
    await contexto.test('as 3 severidades, na ordem do servidor', () => {
        assert.deepEqual(Object.keys(A.SEVERIDADES), ['critico', 'atencao', 'oportunidade']);
        assert.deepEqual(Object.values(A.SEVERIDADES).map((s) => s.ordem), [0, 1, 2]);
    });

    await contexto.test('⚠️ o badge é MAIÚSCULO (`s[0].toUpperCase()`, :515)', () => {
        assert.equal(A.SEVERIDADES.critico.rotulo, 'CRÍTICO');
        assert.equal(A.SEVERIDADES.atencao.rotulo, 'ATENÇÃO');
        assert.equal(A.SEVERIDADES.oportunidade.rotulo, 'OPORTUNIDADE');
    });

    await contexto.test('⚠️ a ABA é caixa de título — DOIS rótulos para a mesma severidade (:520)', () => {
        assert.deepEqual(A.ABAS_SEVERIDADE.map((a) => a.chave), ['todas', 'critico', 'atencao', 'oportunidade']);
        assert.deepEqual(A.ABAS_SEVERIDADE.map((a) => a.rotulo), ['Todas', 'Crítico', 'Atenção', 'Oportunidade']);
        // e o da aba NÃO é o do badge
        assert.notEqual(A.ABAS_SEVERIDADE[1].rotulo, A.SEVERIDADES.critico.rotulo);
    });

    await contexto.test('⚠️ "Todas" NÃO tem dot (`hasDot: k !== "all"`, :520) — 3 dots, nunca 4', () => {
        assert.deepEqual(A.ABAS_SEVERIDADE.map((a) => a.tem_dot), [false, true, true, true]);
    });
});

test('os literais de tela do card de ações (:186, :207, :210, :217-:219, :603)', async (contexto) => {
    await contexto.test('igualdade de string INTEIRA', () => {
        assert.equal(A.TITULO, 'O que fazer agora');
        assert.equal(A.RECOMENDADO, 'RECOMENDADO AGORA');
        assert.equal(A.ABRIR, 'Abrir →');
        assert.equal(A.ADIAR, 'Adiar até amanhã');
        assert.equal(A.VAZIO_TITULO, 'Nada pendente neste filtro');
        assert.equal(A.VAZIO_TEXTO, 'Ações adiadas voltam amanhã às 8h.');
        assert.equal(A.SEM_ACAO, 'Nenhuma ação pendente');
    });

    await contexto.test('CONTRA-PROVA: a mesma frase sem acento NÃO está no HTML', () => {
        const html = renderAcoes({ acoes: { acoes: [], contagens: {}, volta_em_iso: null } });
        assert.match(html, />Nada pendente neste filtro</);
        assert.ok(!html.includes('Nada pendente neste filtro.'), 'ponto a mais');
        assert.ok(!html.includes('Acoes adiadas'), 'sem cedilha');
    });

    await contexto.test('os 12 CTA por chave — as 12 chaves fechadas do 179-02', () => {
        assert.deepEqual(Object.keys(A.CTAS), [
            'reconectar', 'com_problema', 'pausados', 'rascunhos_pendencias', 'prontos_fase2',
            'sem_publicacao', 'conferidos', 'portal_novas', 'alavancas', 'ficha_incompleta',
            'perdendo_catalogo', 'foto_insuficiente',
        ]);
        assert.equal(A.CTAS.reconectar, 'Reconectar');
        assert.equal(A.CTAS.com_problema, 'Corrigir');
        assert.equal(A.CTAS.pausados, 'Revisar');
        assert.equal(A.CTAS.rascunhos_pendencias, 'Continuar');
        assert.equal(A.CTAS.prontos_fase2, 'Criar combos');
        assert.equal(A.CTAS.sem_publicacao, 'Publicar');
        assert.equal(A.CTAS.conferidos, 'Publicar');
        assert.equal(A.CTAS.portal_novas, 'Sincronizar');
        assert.equal(A.CTAS.alavancas, 'Abrir alavancas');
        assert.equal(A.CTAS.ficha_incompleta, 'Corrigir');
        assert.equal(A.CTAS.perdendo_catalogo, 'Corrigir');
        assert.equal(A.CTAS.foto_insuficiente, 'Corrigir');
        assert.equal(A.CTA_PADRAO, 'Ver');
    });

    await contexto.test('⚠️ SUBTITULO: o protótipo NÃO trata o singular ("1 ações", :603)', () => {
        assert.equal(A.SUBTITULO(3), '3 ações priorizadas a partir dos dados da conta');
        assert.equal(A.SUBTITULO(1), '1 ações priorizadas a partir dos dados da conta');
        assert.equal(A.SUBTITULO(0), 'Nenhuma ação pendente');
        // e nenhuma forma no singular foi inventada
        assert.ok(!A.SUBTITULO(1).includes('1 ação'), 'alguém "consertou" o plural do protótipo');
    });

    await contexto.test('⚠️ VG-11: o rodapé do vazio sai do `volta_em_iso`, nunca cravado', () => {
        assert.equal(A.textoDaVolta('2026-10-14T08:00:00-03:00'), 'Ações adiadas voltam amanhã às 8h.');
        // a hora segue o SERVIDOR: mudar `HORA_DA_VOLTA` muda a tela sozinha
        assert.equal(A.textoDaVolta('2026-10-14T09:00:00-03:00'), 'Ações adiadas voltam amanhã às 9h.');
        assert.equal(A.textoDaVolta('2026-10-14T08:30:00-03:00'), 'Ações adiadas voltam amanhã às 8h30.');
        // sem instante medido, NENHUMA hora é inventada
        assert.equal(A.textoDaVolta(null), 'Ações adiadas voltam amanhã.');
        assert.equal(A.textoDaVolta('   '), 'Ações adiadas voltam amanhã.');
        assert.equal(A.textoDaVolta({ a: 1 }), 'Ações adiadas voltam amanhã.');
        assert.equal(A.textoDaVolta(7), 'Ações adiadas voltam amanhã.');
        assert.equal(A.textoDaVolta('não é data'), 'Ações adiadas voltam amanhã.');
        // ⚠️ e o JSX não tem "8h" escrito em lugar nenhum
        assert.doesNotMatch(FONTE_CARD, /8h/);
    });

    await contexto.test('⚠️ o ISO NÃO passa por `new Date()` (o fuso mudaria a hora escrita)', () => {
        assert.doesNotMatch(FONTE_ACOES, /new Date\(/);
        assert.doesNotMatch(FONTE_CARD, /new Date\(/);
        // 23:00 no ISO continua 23h, não vira 20h nem 2h do dia seguinte
        assert.equal(A.textoDaVolta('2026-10-14T23:00:00-03:00'), 'Ações adiadas voltam amanhã às 23h.');
    });
});

test('os literais da Saúde e de Publicações (:523-:532, :534-:536, :606-:607)', async (contexto) => {
    await contexto.test('Saúde — string INTEIRA', () => {
        assert.equal(A.TITULO_SAUDE, 'Saúde da conta');
        assert.deepEqual(A.GERAL, {
            tudo_conectado: 'Tudo conectado',
            atencao: 'Atenção',
            requer_acao: 'Requer ação',
        });
        assert.equal(A.LINHAS.mercado_livre.nome, 'Mercado Livre');
        assert.equal(A.LINHAS.mercado_livre.tile, 'ML');
        assert.equal(A.LINHAS.erp.tile, 'ERP');
        assert.equal(A.LINHAS.sincronizacao.nome, 'Sincronização');
        assert.deepEqual(A.ESTADO_ML, {
            ativo: 'Conectado',
            expirado: 'Token expirado',
            sem_token: 'Sem conexão',
        });
        assert.equal(A.ATUALIZAR, 'Atualizar');
        assert.equal(A.ATUALIZANDO, 'Atualizando');
        assert.equal(A.NUNCA_COLETADO, 'Nunca coletado');
        assert.equal(A.ERP_NAO_INFORMADO, 'Não informado');
    });

    await contexto.test('⚠️ o tile da Sincronização é o CARACTERE `⟳` (:531), não um ícone', () => {
        assert.equal(A.LINHAS.sincronizacao.tile, '⟳');
        const html = renderSaude({ saude: SAUDE_CHEIA() });
        const { bloco } = linhaDaSaude(html, 'Sincronização');
        assert.match(bloco, /⟳/);
        // e NÃO entrou um `RefreshCw` do Lucide no lugar
        assert.ok(!bloco.includes('lucide-refresh'), 'o caractere foi trocado por ícone');
        assert.doesNotMatch(FONTE_SAUDE, /RefreshCw/);
    });

    await contexto.test('Publicações — string INTEIRA', () => {
        assert.equal(A.TITULO_PUBS, 'Publicações no Mercado Livre');
        assert.equal(A.VER_TODAS, 'Ver todas →');
        assert.equal(A.OFERTAS, 'ofertas');
        // ⚠️ REAPONTADO na Fase 186, plano 01 — DECISÃO DO USUÁRIO de 14/10/2026.
        // VALOR ANTIGO: `em_revisao: 'Em revisão'`. As outras três faixas e as
        // quatro CHAVES não mudaram.
        // ⚠️ O `deepEqual` do mapa INTEIRO já é o veto: qualquer rótulo a mais, a
        // menos ou diferente reprova. É por isso que ele compara o objeto todo em
        // vez de campo a campo.
        assert.deepEqual(A.FAIXAS, {
            ativas: 'Ativas',
            pausadas: 'Pausadas',
            em_revisao: 'Em análise pelo Mercado Livre',
            encerradas: 'Encerradas',
        });
        assert.ok(
            !Object.values(A.FAIXAS).includes('Em revisão'),
            'o rótulo antigo voltou a alguma faixa da Visão Geral',
        );
        assert.equal(A.PEDEM_ACAO, 'Pedem ação');
        assert.equal(A.ACERVO, 'Acervo de imagens');
        assert.equal(A.SEM_MEDIDA, '—');
        assert.equal(
            A.TITLE_SEM_MEDIDA,
            'Nenhuma coleta do acervo ainda: este número não foi medido, e zero seria mentira.',
        );
        assert.equal(A.SPLIT_SEM_ANUNCIO, 'nenhuma ainda');
        assert.equal(A.SPLIT_NAO_MEDIDO, 'nunca coletado');
    });

    await contexto.test('⚠️ as 4 chaves de faixa são as REAIS — "Com erro" NÃO está entre elas', () => {
        assert.deepEqual(Object.keys(A.FAIXAS), ['ativas', 'pausadas', 'em_revisao', 'encerradas']);
        for (const [, fonte] of FONTES_JSX) {
            assert.ok(!fonte.includes('Com erro'), 'alguém inventou o status de erro');
        }
        assert.ok(!FONTE_ACOES.includes('Com erro'));
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// C. As funções puras
// ═══════════════════════════════════════════════════════════════════════════

test('destinoHref — os três shapes de `destino` do servidor', async (contexto) => {
    await contexto.test('{rota, params} vai pelo route() do Ziggy', () => {
        assert.equal(
            A.destinoHref({ rota: 'mlb.anuncios.meus', params: { company: 9 } }),
            '/mlb.anuncios.meus{"company":9}',
        );
    });

    await contexto.test('{acao, url} devolve a url (é o link_reconexao do ML)', () => {
        assert.equal(A.destinoHref({ acao: 'reconectar', url: 'https://auth.ml/x' }), 'https://auth.ml/x');
    });

    await contexto.test('⚠️ {acao: "sincronizar"} devolve NULL — não é link, é ação', () => {
        assert.equal(A.destinoHref({ acao: 'sincronizar' }), null);
        assert.equal(A.destinoHref({ acao: 'reconectar', url: null }), null);
    });

    await contexto.test('nada que não seja destino vira null, sem lançar', () => {
        for (const bruto of [null, undefined, 'x', 7, [], true, {}, { rota: 7 }, { rota: '  ' }, { url: 9 }]) {
            assert.equal(A.destinoHref(bruto), null, JSON.stringify(bruto));
        }
    });

    await contexto.test('⚠️ `url` que é número NÃO vira href (href="7" é destino morto)', () => {
        assert.equal(A.destinoHref({ acao: 'reconectar', url: 7 }), null);
        assert.equal(A.urlSegura(7), null);
        assert.equal(A.urlSegura({ a: 1 }), null);
        assert.equal(A.urlSegura(''), null);
        assert.equal(A.urlSegura('/ok'), '/ok');
    });
});

test('normalizarAcoes — a fronteira (T-179-30)', async (contexto) => {
    await contexto.test('o payload cheio passa inteiro, na ordem do servidor', () => {
        const normal = A.normalizarAcoes(ACOES_CHEIAS());
        assert.deepEqual(normal.acoes.map((a) => a.chave), ['com_problema', 'pausados', 'prontos_fase2']);
        assert.deepEqual(normal.acoes.map((a) => a.severidade), ['critico', 'atencao', 'oportunidade']);
        assert.deepEqual(normal.acoes.map((a) => a.numero), [2, 7, 13]);
        assert.deepEqual(normal.acoes.map((a) => a.recomendada), [true, false, false]);
        assert.deepEqual(normal.acoes.map((a) => a.itens.length), [2, 0, 1]);
        assert.deepEqual(normal.contagens, { todas: 3, critico: 1, atencao: 1, oportunidade: 1 });
    });

    await contexto.test('`acoes` que não é array vira lista vazia', () => {
        for (const bruta of [null, undefined, 'x', 7, true, {}, { acoes: null }, { acoes: 'x' }, { acoes: 7 }, { acoes: {} }]) {
            assert.deepEqual(A.normalizarAcoes(bruta).acoes, [], JSON.stringify(bruta));
        }
    });

    await contexto.test('⚠️ ação `null` dentro do array é IGNORADA (Fase 178: grupo nulo derrubou o painel)', () => {
        const normal = A.normalizarAcoes({ acoes: [null, 'x', 7, [], ACOES_CHEIAS().acoes[0]] });
        assert.equal(normal.acoes.length, 1);
        assert.equal(normal.acoes[0].chave, 'com_problema');
    });

    await contexto.test('severidade desconhecida cai em `atencao` e a ação APARECE', () => {
        const normal = A.normalizarAcoes({ acoes: [{ chave: 'nova', severidade: 'catastrofe' }] });
        assert.equal(normal.acoes.length, 1);
        assert.equal(normal.acoes[0].severidade, 'atencao');
        assert.equal(A.SEVERIDADE_PADRAO, 'atencao');
    });

    await contexto.test('`itens` não-array vira [] e item nulo é descartado', () => {
        for (const itens of [null, 'x', 7, {}, true]) {
            assert.deepEqual(A.normalizarAcoes({ acoes: [{ chave: 'a', itens }] }).acoes[0].itens, []);
        }
        const normal = A.normalizarAcoes({ acoes: [{ chave: 'a', itens: [null, 9, [], item('N', 'M', 'O')] }] });
        assert.equal(normal.acoes[0].itens.length, 1);
        assert.equal(normal.acoes[0].itens[0].nome, 'N');
    });

    await contexto.test('campo OBJETO nunca é interpolado — vira null', () => {
        const normal = A.normalizarAcoes({
            acoes: [{ chave: { a: 1 }, titulo: { a: 1 }, descricao: [], numero: { a: 1 }, destino: 'x', itens: [{ nome: {}, meta: [], motivo: { a: 1 } }] }],
        });
        const acao = normal.acoes[0];
        assert.equal(acao.chave, null);
        assert.equal(acao.titulo, null);
        assert.equal(acao.descricao, null);
        assert.equal(acao.numero, null);
        assert.equal(acao.destino, null);
        assert.deepEqual(acao.itens[0], { nome: null, meta: null, motivo: null });
    });

    await contexto.test('numeroSeguro nunca devolve NaN nem Infinity', () => {
        assert.equal(A.numeroSeguro(NaN), null);
        assert.equal(A.numeroSeguro(Infinity), null);
        assert.equal(A.numeroSeguro('abc'), null);
        assert.equal(A.numeroSeguro(0), 0);
        assert.equal(A.numeroSeguro('12'), 12);
        assert.equal(A.numeroSeguro(12.7), 12);
    });
});

test('abasComContagem — a contagem sai da PROP, não das linhas visíveis (:512)', async (contexto) => {
    await contexto.test('as 4 abas, com a contagem do payload', () => {
        const abas = A.abasComContagem({ todas: 11, critico: 2, atencao: 5, oportunidade: 4 });
        assert.deepEqual(abas.map((a) => a.chave), ['todas', 'critico', 'atencao', 'oportunidade']);
        assert.deepEqual(abas.map((a) => a.total), [11, 2, 5, 4]);
    });

    await contexto.test('⚠️ o adiamento OTIMISTA desconta da aba certa e de `todas`', () => {
        const abas = A.abasComContagem(
            { todas: 11, critico: 2, atencao: 5, oportunidade: 4 },
            [{ severidade: 'atencao' }, { severidade: 'atencao' }],
        );
        assert.deepEqual(abas.map((a) => a.total), [9, 2, 3, 4]);
    });

    await contexto.test('contagem nunca fica negativa', () => {
        const abas = A.abasComContagem({ todas: 0, critico: 0, atencao: 0, oportunidade: 0 }, [{ severidade: 'critico' }]);
        assert.deepEqual(abas.map((a) => a.total), [0, 0, 0, 0]);
    });

    await contexto.test('contagens nulas/tortas viram 0, nunca NaN', () => {
        for (const bruta of [null, undefined, 'x', 7, [], { todas: {} }, { todas: 'x' }]) {
            const abas = A.abasComContagem(bruta);
            assert.deepEqual(abas.map((a) => a.total), [0, 0, 0, 0], JSON.stringify(bruta));
        }
    });
});

test('acoesParaTela — filtro, recomendada, gaveta e adiar', async (contexto) => {
    const dados = () => A.normalizarAcoes(ACOES_CHEIAS());

    await contexto.test('sem filtro, as 3 ações, na ordem do servidor', () => {
        assert.deepEqual(
            A.acoesParaTela(dados(), 'todas', {}, {}, 'c-459').map((a) => a.chave),
            ['com_problema', 'pausados', 'prontos_fase2'],
        );
    });

    await contexto.test('o filtro RECORTA a lista', () => {
        assert.deepEqual(A.acoesParaTela(dados(), 'critico').map((a) => a.chave), ['com_problema']);
        assert.deepEqual(A.acoesParaTela(dados(), 'atencao').map((a) => a.chave), ['pausados']);
        assert.deepEqual(A.acoesParaTela(dados(), 'oportunidade').map((a) => a.chave), ['prontos_fase2']);
    });

    await contexto.test('⚠️ VG-10: filtrar NÃO promove ninguém — `recomendada` vem do servidor', () => {
        // a recomendada é a `com_problema`; filtrando por `atencao` a `pausados`
        // continua NÃO recomendada, e ninguém assume o posto
        const so = A.acoesParaTela(dados(), 'atencao');
        assert.equal(so.length, 1);
        assert.equal(so[0].recomendada, false);
        assert.equal(A.acoesParaTela(dados(), 'oportunidade')[0].recomendada, false);
        // e a fonte não recalcula nada
        assert.doesNotMatch(FONTE_ACOES, /recomendada: .*indice === 0/);
        assert.doesNotMatch(FONTE_ACOES, /recomendada: .*=== 0/);
    });

    await contexto.test('⚠️ a gaveta só abre com sub-item (`open && items.length > 0`, :520)', () => {
        const comItens = A.acoesParaTela(dados(), 'todas', { com_problema: true }, {}, 'c-459');
        assert.equal(comItens[0].aberta, true);
        const semItens = A.acoesParaTela(dados(), 'todas', { pausados: true }, {}, 'c-459');
        assert.equal(semItens[1].aberta, false, 'ação sem item NÃO abre gaveta');
        assert.equal(semItens[1].tem_itens, false);
    });

    await contexto.test('⚠️ sem `contaChave` o botão de adiar não existe (EXMP-03)', () => {
        assert.deepEqual(A.acoesParaTela(dados(), 'todas', {}, {}, 'c-459').map((a) => a.pode_adiar), [true, true, true]);
        for (const conta of [null, undefined, '', '   ', 7, {}]) {
            assert.deepEqual(
                A.acoesParaTela(dados(), 'todas', {}, {}, conta).map((a) => a.pode_adiar),
                [false, false, false],
                JSON.stringify(conta),
            );
        }
    });

    await contexto.test('a adiada no cliente desaparece da lista', () => {
        assert.deepEqual(
            A.acoesParaTela(dados(), 'todas', {}, { pausados: true }, 'c-459').map((a) => a.chave),
            ['com_problema', 'prontos_fase2'],
        );
        assert.deepEqual(A.escondidasDaLista(dados(), { pausados: true }).map((a) => a.severidade), ['atencao']);
    });

    await contexto.test('⚠️ chave desconhecida APARECE, com CTA_PADRAO', () => {
        const normal = A.normalizarAcoes({
            acoes: [{ chave: 'inventada_na_fase_200', severidade: 'critico', titulo: 'Nova pendência', itens: [], destino: { rota: 'r', params: {} } }],
        });
        const tela = A.acoesParaTela(normal, 'todas', {}, {}, 'c');
        assert.equal(tela.length, 1, 'a ação desapareceu por falta de rótulo');
        assert.equal(tela[0].cta, 'Ver');
    });

    await contexto.test('a pilha mostra NO MÁXIMO 3 (`items.slice(0, 3)`, :191)', () => {
        assert.equal(A.MAX_TILES, 3);
        const normal = A.normalizarAcoes({
            acoes: [{ chave: 'a', severidade: 'critico', itens: [1, 2, 3, 4, 5].map((n) => item(`P${n}`, 'm', 'o')) }],
        });
        const tela = A.acoesParaTela(normal, 'todas', {}, {}, 'c');
        assert.equal(tela[0].tiles.length, 3);
        assert.equal(tela[0].itens.length, 5, 'a sub-lista mostra TODOS, a pilha só 3');
    });
});

test('linhasDaSaude — as 3 linhas, sempre, e o ERP sem conexão', async (contexto) => {
    await contexto.test('as 3 linhas na ordem do servidor, com meta decidida', () => {
        const dados = A.linhasDaSaude(SAUDE_CHEIA());
        assert.deepEqual(dados.linhas.map((l) => l.chave), ['mercado_livre', 'erp', 'sincronizacao']);
        assert.deepEqual(dados.linhas.map((l) => l.nome), ['Mercado Livre', 'ERP', 'Sincronização']);
        assert.equal(dados.linhas[0].meta, 'ECFDEV02 · 2015132968');
        assert.equal(dados.linhas[1].meta, 'Declarado: Bling');
        assert.equal(dados.linhas[2].meta, 'Última coleta há 4 h');
        assert.equal(dados.rotulo_geral, 'Tudo conectado');
    });

    await contexto.test('⚠️ a meta do ML NÃO inventa prazo ("token renova em 4 h", :525)', () => {
        const dados = A.linhasDaSaude(SAUDE_CHEIA());
        assert.ok(!dados.linhas[0].meta.includes('renova'), 'prazo inventado');
        assert.doesNotMatch(FONTE_SAUDE, /renova/);
        assert.doesNotMatch(FONTE_ACOES, /renova/);
        // só `conta_nome`: a meta é só ele
        assert.equal(A.linhasDaSaude({ linhas: [{ chave: 'mercado_livre', estado: 'ativo', conta_nome: 'X', conta_ml_id: null }] }).linhas[0].meta, 'X');
        // nenhum dos dois: NENHUMA meta (a pílula já diz o estado)
        assert.equal(A.linhasDaSaude({ linhas: [{ chave: 'mercado_livre', estado: 'sem_token' }] }).linhas[0].meta, null);
    });

    await contexto.test('⚠️ a Sincronização distingue "há N h" de "Nunca coletado"', () => {
        const nunca = A.linhasDaSaude({ linhas: [{ chave: 'sincronizacao', horas: null, nunca_coletado: true, atualizar_url: '/x' }] });
        assert.equal(nunca.linhas[0].meta, 'Nunca coletado');
        assert.ok(!nunca.linhas[0].meta.includes('0'), '"há 0 h" seria mentira');
        const zero = A.linhasDaSaude({ linhas: [{ chave: 'sincronizacao', horas: 0, nunca_coletado: false, atualizar_url: '/x' }] });
        assert.equal(zero.linhas[0].meta, 'Última coleta há 0 h', 'zero MEDIDO é 0');
    });

    await contexto.test('o botão de Atualizar só existe com `atualizar_url` (T-179-34)', () => {
        assert.equal(A.linhasDaSaude(SAUDE_CHEIA()).linhas[2].atualizar_url, '/mlb/anuncios/meus/9/atualizar');
        for (const url of [null, undefined, '', 7, {}]) {
            assert.equal(A.linhasDaSaude({ linhas: [{ chave: 'sincronizacao', atualizar_url: url }] }).linhas[0].atualizar_url, null);
        }
    });

    await contexto.test('⚠️ `atualizar_url` só é lido na linha de SINCRONIZAÇÃO', () => {
        const dados = A.linhasDaSaude({
            linhas: [{ chave: 'erp', declarado: 'X', atualizar_url: '/maldoso' }, { chave: 'mercado_livre', estado: 'ativo', atualizar_url: '/maldoso' }],
        });
        assert.deepEqual(dados.linhas.map((l) => l.atualizar_url), [null, null]);
    });

    await contexto.test('linha nula e linha de chave desconhecida são IGNORADAS — nunca uma 4ª linha', () => {
        const dados = A.linhasDaSaude({
            geral: 'atencao',
            linhas: [null, 7, 'x', [], { chave: 'shopee' }, { chave: 'erp', declarado: null }],
        });
        assert.deepEqual(dados.linhas.map((l) => l.chave), ['erp']);
    });

    await contexto.test('`geral` desconhecido/torto vira null — "Tudo conectado" não é padrão', () => {
        for (const geral of ['inventado', null, undefined, 7, {}, []]) {
            const dados = A.linhasDaSaude({ geral, linhas: [] });
            assert.equal(dados.geral, null, JSON.stringify(geral));
            assert.equal(dados.rotulo_geral, null);
        }
    });

    await contexto.test('o âmbar é a DEFASAGEM da coleta (179-03, D-2), nunca o ERP', () => {
        const dados = A.linhasDaSaude({
            linhas: [
                { chave: 'erp', declarado: null, problema: true },
                { chave: 'sincronizacao', horas: 40, defasado: true, atualizar_url: '/x' },
            ],
        });
        assert.equal(dados.linhas[0].problema, false, 'o ERP ganhou problema');
        assert.equal(dados.linhas[0].atencao, false);
        assert.equal(dados.linhas[1].atencao, true);
    });
});

test('resumoParaTela e acervoParaTela — VG-18 nos dois sentidos', async (contexto) => {
    await contexto.test('o payload cheio: 4 faixas + a legenda de "Pedem ação"', () => {
        const resumo = A.resumoParaTela(PUBS_CHEIAS(), '/pubs');
        assert.deepEqual(resumo.faixas.map((f) => f.chave), ['ativas', 'pausadas', 'em_revisao', 'encerradas']);
        assert.deepEqual(resumo.faixas.map((f) => f.total), [49, 7, 2, 0]);
        assert.equal(resumo.total_texto, '58');
        assert.equal(resumo.split, '84% ativas');
        assert.equal(resumo.pedem_acao.total, 3);
        assert.equal(resumo.url_todas, '/pubs');
    });

    await contexto.test('⚠️ as 4 faixas SOMAM o total (`outras` fecha o resto, e não é faixa)', () => {
        const resumo = A.resumoParaTela(PUBS_CHEIAS(), null);
        assert.equal(resumo.faixas.reduce((a, f) => a + f.total, 0), resumo.total);
        // `outras` não virou faixa nem foi somada a "Encerradas"
        assert.equal(resumo.faixas.length, 4);
        assert.ok(!resumo.faixas.some((f) => f.chave === 'outras'));
        const comResiduo = PUBS_CHEIAS();
        comResiduo.total = 61;
        comResiduo.outras = 3;
        const r2 = A.resumoParaTela(comResiduo, null);
        assert.equal(r2.faixas[3].total, 0, '"Encerradas" engoliu o resíduo');
    });

    await contexto.test('⚠️ `pedem_acao` AUSENTE (não nula) ⇒ a legenda não nasce (179-03, armadilha 1)', () => {
        const sem = PUBS_CHEIAS();
        delete sem.pedem_acao;
        assert.ok(!('pedem_acao' in sem));
        assert.equal(A.resumoParaTela(sem, null).pedem_acao, null);
        // e com a chave presente e ZERO medido ela NASCE
        const zero = PUBS_CHEIAS();
        zero.pedem_acao = { total: 0, url: '/p' };
        assert.equal(A.resumoParaTela(zero, null).pedem_acao.total, 0);
    });

    await contexto.test('⚠️ os DOIS sentidos de `ativas_pct` null (179-03, armadilha 3)', () => {
        const naoMedido = A.resumoParaTela({ disponivel: false, total: null, faixas: [], outras: null, ativas_pct: null }, null);
        assert.equal(naoMedido.total_texto, '—');
        assert.equal(naoMedido.split, 'nunca coletado');

        const zeroMedido = A.resumoParaTela({ disponivel: true, total: 0, faixas: [], outras: 0, ativas_pct: null }, null);
        assert.equal(zeroMedido.total_texto, '0', 'zero MEDIDO é 0, não "—"');
        assert.equal(zeroMedido.split, 'nenhuma ainda');

        assert.notEqual(naoMedido.split, zeroMedido.split, 'os dois sentidos têm de ter textos diferentes');
    });

    await contexto.test('faixa nula / de chave desconhecida é descartada', () => {
        const resumo = A.resumoParaTela({ disponivel: true, total: 1, faixas: [null, 7, 'x', [], { chave: 'com_erro', total: 9, url: '/x' }, { chave: 'ativas', total: 1, url: '/a' }] }, null);
        assert.deepEqual(resumo.faixas.map((f) => f.chave), ['ativas']);
    });

    await contexto.test('⚠️ acervo: `—` para não medido, `0` para zero medido (VG-18)', () => {
        assert.equal(A.acervoParaTela({ imagens: 128, packs: 9 }).meta, '128 imagens · 9 packs gerados com IA');
        assert.equal(A.acervoParaTela({ imagens: 0, packs: 0 }).meta, '0 imagens · 0 packs gerados com IA');
        assert.equal(A.acervoParaTela({ imagens: null, packs: null }).meta, '— imagens · — packs gerados com IA');
        assert.equal(A.acervoParaTela({ imagens: 128, packs: null }).meta, '128 imagens · — packs gerados com IA');
        assert.equal(A.acervoParaTela({ imagens: 0, packs: 0 }).nao_medido, false);
        assert.equal(A.acervoParaTela({ imagens: null, packs: 0 }).nao_medido, true);
        assert.equal(A.acervoParaTela(null).meta, '— imagens · — packs gerados com IA');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// D. "O que fazer agora" — as medidas, por ELEMENTO (FID-01)
// ═══════════════════════════════════════════════════════════════════════════

test('FID-01 — 1/2/3/4: o card, o cabeçalho, a coluna, o H2 e o subtítulo', async (contexto) => {
    const html = renderAcoes({ acoes: ACOES_CHEIAS(), contaChave: 'c-459' });

    await contexto.test('1 — o card (:165): #111111, 1px #262626, raio 16, overflow hidden', () => {
        const secoes = tagsPorNome(html, 'section');
        assert.equal(secoes.length, 1, 'tem de haver UMA <section>');
        conferirClasses(secoes[0].bruto, [
            'overflow-hidden', 'rounded-pub-card', 'border', 'border-pub-line', 'bg-pub-surface',
        ], 'o card');
    });

    await contexto.test('2 — o cabeçalho (:166): padding 18/20/14 e gap 16', () => {
        const h2 = tagsPorNome(html, 'h2');
        assert.equal(h2.length, 1);
        // a coluna do título é a <div> imediatamente antes do H2; o cabeçalho é a anterior
        const divs = tagsPorNome(html.slice(0, h2[0].indice), 'div');
        conferirClasses(divs[divs.length - 2].bruto, [
            'flex', 'items-center', 'justify-between', 'gap-[16px]', 'px-[20px]', 'pb-[14px]', 'pt-[18px]',
        ], 'o cabeçalho');
    });

    await contexto.test('⚠️ 2b — a coluna do título (:167): MEDIDA AUSENTE da tabela do plano', () => {
        const h2 = tagsPorNome(html, 'h2');
        const divs = tagsPorNome(html.slice(0, h2[0].indice), 'div');
        conferirClasses(divs[divs.length - 1].bruto, ['flex', 'flex-col', 'gap-[4px]'], 'a coluna do título');
    });

    await contexto.test('3/4 — H2 17px/600 -0.01em e subtítulo 13px (:168/:169)', () => {
        conferirClasses(
            tagComTexto(html, 'h2', 'O que fazer agora'),
            ['m-0', 'text-[17px]', 'font-semibold', 'tracking-[-0.01em]'],
            'o H2',
        );
        conferirClasses(
            tagComTexto(html, 'span', '3 ações priorizadas a partir dos dados da conta'),
            ['text-[13px]', 'text-pub-txt-3'],
            'o subtítulo',
        );
    });
});

test('FID-01 — 5/6/7/8: o segmented (:170-:173)', async (contexto) => {
    const html = renderAcoes({ acoes: ACOES_CHEIAS(), contaChave: 'c-459' });
    const opcoes = opcoesDoSegmented(html);

    await contexto.test('⚠️ são EXATAMENTE 4 opções, nunca 3 nem 5', () => {
        assert.equal(opcoes.length, 4);
        assert.deepEqual(
            opcoes.map((o) => o.interior.replace(/<[^>]*>/g, '').trim()),
            ['Todas3', 'Crítico1', 'Atenção1', 'Oportunidade1'],
        );
    });

    await contexto.test('5 — o trilho (:170): gap 2, padding 3, #161616, 1px #1F1F1F, raio 10', () => {
        const indice = html.indexOf(opcoes[0].abertura);
        conferirClasses(tagAntes(html, indice, 'div'), [
            'flex', 'items-center', 'gap-[2px]', 'rounded-pub-sub', 'border', 'border-pub-line-subtle',
            'bg-pub-hover-2', 'p-[3px]',
        ], 'o trilho do segmented');
    });

    await contexto.test('6 — a opção ATIVA (:172): #262626 + inset 1px #333333 + #F4F4F5', () => {
        conferirClasses(opcoes[0].abertura, [
            'flex', 'h-[28px]', 'cursor-pointer', 'items-center', 'gap-[6px]', 'whitespace-nowrap',
            'rounded-pub-control-sm', 'border-none', 'px-[10px]', 'text-[12.5px]', 'font-medium',
            'bg-pub-seg', 'text-pub-txt', 'shadow-pub-seg-ativo',
        ], 'a opção ativa');
    });

    await contexto.test('6 — a opção INATIVA: transparente + #A1A1AA, e SEM o inset', () => {
        for (const opcao of opcoes.slice(1)) {
            conferirClasses(opcao.abertura, [
                'flex', 'h-[28px]', 'cursor-pointer', 'items-center', 'gap-[6px]', 'whitespace-nowrap',
                'rounded-pub-control-sm', 'border-none', 'px-[10px]', 'text-[12.5px]', 'font-medium',
                'bg-transparent', 'text-pub-txt-2',
            ], 'a opção inativa');
        }
    });

    await contexto.test('⚠️ 7 — TRÊS dots, nunca 4: "Todas" não tem (:525)', () => {
        const comDot = opcoes.filter((o) => /rounded-full/.test(o.interior));
        assert.equal(comDot.length, 3);
        assert.ok(!/rounded-full/.test(opcoes[0].interior), '"Todas" ganhou um dot');
    });

    await contexto.test('⚠️ 7 — o dot usa a cor do TEXTO (`SEV[k][1]`, :525), não a tinta sólida', () => {
        const esperados = ['bg-pub-danger-txt', 'bg-pub-warn-txt', 'bg-pub-accent'];
        opcoes.slice(1).forEach((opcao, i) => {
            const dot = /<span([^>]*rounded-full[^>]*)\/?>/.exec(opcao.interior);
            assert.ok(dot !== null, 'faltou o dot');
            conferirClasses(`<span${dot[1]}>`, ['h-[6px]', 'w-[6px]', 'rounded-full', esperados[i]], `o dot ${i}`);
        });
    });

    await contexto.test('8 — a contagem da opção: #71717A + tabular-nums (:173)', () => {
        for (const opcao of opcoes) {
            const contagem = /<span class="([^"]*)">\d+<\/span>/.exec(opcao.interior);
            assert.ok(contagem !== null, 'faltou a contagem');
            conferirClasses(`<span class="${contagem[1]}">`, ['tabular-nums', 'text-pub-txt-4'], 'a contagem');
        }
    });
});

test('FID-01 — 9/10/11: a linha da ação, o grid e o tile do ícone (:176-:178)', async (contexto) => {
    const html = renderAcoes({ acoes: ACOES_CHEIAS(), contaChave: 'c-459' });

    await contexto.test('⚠️ 9 — a RECOMENDADA tem fundo #131313; as outras, #111111', () => {
        const recomendada = blocoDaAcao(html, 'Produtos com problema na publicação');
        conferirClasses(recomendada.wrapper, ['border-t', 'border-pub-line-subtle', 'bg-pub-raised-0'], 'a recomendada');
        for (const titulo of ['Anúncios pausados ou sem estoque', 'Prontos para a Fase 2']) {
            conferirClasses(blocoDaAcao(html, titulo).wrapper, ['border-t', 'border-pub-line-subtle', 'bg-pub-surface'], titulo);
        }
    });

    await contexto.test('10 — o grid 38/1fr/auto/auto/28, gap 14, padding 14/20, hover #141414', () => {
        conferirClasses(blocoDaAcao(html, 'Prontos para a Fase 2').grid, [
            'grid', 'cursor-pointer', 'grid-cols-[38px_minmax(0,1fr)_auto_auto_28px]', 'items-center',
            'gap-[14px]', 'px-[20px]', 'py-[14px]', 'hover:bg-pub-hover',
        ], 'o grid da ação');
    });

    await contexto.test('11 — o tile do ícone 38×38 raio 10, com as TRÊS tintas', () => {
        const tintas = { critico: 'bg-pub-danger/[0.13]', atencao: 'bg-pub-warn/[0.13]', oportunidade: 'bg-pub-accent/[0.12]' };
        const titulos = {
            critico: 'Produtos com problema na publicação',
            atencao: 'Anúncios pausados ou sem estoque',
            oportunidade: 'Prontos para a Fase 2',
        };
        for (const [severidade, tinta] of Object.entries(tintas)) {
            const inicio = blocoDaAcao(html, titulos[severidade]).depoisDoGrid;
            const tile = /<span class="([^"]*)">/.exec(html.slice(inicio));
            conferirClasses(`<span class="${tile[1]}">`, [
                'grid', 'h-[38px]', 'w-[38px]', 'place-items-center', 'rounded-pub-sub', tinta,
            ], `o tile de ${severidade}`);
        }
    });

    await contexto.test('⚠️ as TRÊS tintas são 0.13 / 0.13 / 0.12 — não se uniformizam (:425)', () => {
        assert.match(FONTE_CARD, /bg-pub-danger\/\[0\.13\]/);
        assert.match(FONTE_CARD, /bg-pub-warn\/\[0\.13\]/);
        assert.match(FONTE_CARD, /bg-pub-accent\/\[0\.12\]/);
        assert.doesNotMatch(FONTE_CARD, /bg-pub-accent\/\[0\.13\]/, 'a tinta de oportunidade foi uniformizada');
        assert.doesNotMatch(FONTE_CARD, /bg-pub-danger\/\[0\.12\]/, 'a tinta de crítico foi uniformizada');
    });
});

test('FID-01 — 12/13/14: os três ícones de severidade, desenhados à mão (:179-:181)', async (contexto) => {
    const html = renderAcoes({ acoes: ACOES_CHEIAS(), contaChave: 'c-459' });
    const svgs = tagsPorNome(html, 'svg').filter((t) => !/lucide/.test(t.bruto));

    await contexto.test('⚠️ TRÊS svg que não são do Lucide — um por ação (e os chevrons são Lucide)', () => {
        assert.equal(svgs.length, 3);
        for (const svg of svgs) {
            assert.match(svg.bruto, /width="17" height="17"/);
            assert.match(svg.bruto, /viewBox="0 0 16 16"/);
        }
    });

    await contexto.test('12 — crítico: triângulo PREENCHIDO + "!" escuro de 1.8', () => {
        assert.match(html, /<path d="M8 1\.5L15 14H1z" class="fill-pub-danger"/);
        assert.match(html, /<path d="M8 6v3\.5M8 11\.6v\.1" stroke-width="1\.8" stroke-linecap="round" class="fill-none stroke-pub-page"/);
    });

    await contexto.test('13 — atenção: círculo r 6.5 PREENCHIDO + "!" escuro', () => {
        assert.match(html, /<circle cx="8" cy="8" r="6\.5" class="fill-pub-warn"/);
        assert.match(html, /<path d="M8 4\.8v3\.6M8 10\.9v\.1" stroke-width="1\.8" stroke-linecap="round" class="fill-none stroke-pub-page"/);
    });

    await contexto.test('14 — oportunidade: trending-up em CONTORNO amarelo, sem fill', () => {
        assert.match(html, /stroke-width="1\.8"[^>]*class="fill-none stroke-pub-accent"/);
        assert.match(html, /<path d="M2\.5 11\.5l4-4 2\.5 2\.5 4\.5-5\.5"/);
        assert.match(html, /<path d="M10 4\.5h3\.5V8"/);
    });

    await contexto.test('⚠️ nenhum AlertTriangle/AlertCircle/TrendingUp do Lucide no import', () => {
        assert.doesNotMatch(FONTE_CARD, /AlertTriangle|AlertCircle|TrendingUp/);
        assert.match(FONTE_CARD, /import \{ Check, ChevronDown \} from 'lucide-react'/);
    });
});

test('FID-01 — 15/16/17/18: título, badges e descrição (:182-:188)', async (contexto) => {
    const html = renderAcoes({ acoes: ACOES_CHEIAS(), contaChave: 'c-459' });

    await contexto.test('⚠️ 15b — a coluna de texto (:182): MEDIDA AUSENTE da tabela do plano', () => {
        const indice = html.indexOf('>Produtos com problema na publicação<');
        const spans = spansEstruturaisAntes(html, indice);
        // as duas <span> estruturais antes do título são a coluna e a linha de título
        conferirClasses(spans[spans.length - 2].bruto, ['flex', 'min-w-0', 'flex-col', 'gap-[4px]'], 'a coluna de texto');
    });

    await contexto.test('15 — a linha de título: flex-wrap + gap 8; o título 14px/600', () => {
        const indice = html.indexOf('>Produtos com problema na publicação<');
        const spans = spansEstruturaisAntes(html, indice);
        conferirClasses(spans[spans.length - 1].bruto, ['flex', 'flex-wrap', 'items-center', 'gap-[8px]'], 'a linha de título');
        conferirClasses(
            tagComTexto(html, 'span', 'Produtos com problema na publicação'),
            ['text-[14px]', 'font-semibold'],
            'o título',
        );
    });

    await contexto.test('⚠️ o NÚMERO é elemento irmão no mesmo corpo, com tabular-nums', () => {
        // o servidor manda `titulo` e `numero` em campos separados (179-02); o
        // protótipo os concatena numa string só
        for (const numero of ['2', '7', '13']) {
            conferirClasses(
                tagComTexto(html, 'span', numero),
                ['text-[14px]', 'font-semibold', 'tabular-nums'],
                `o número ${numero}`,
            );
        }
    });

    await contexto.test('16 — o badge de severidade: 10.5px/700, 0.06em, tinta + cor', () => {
        conferirClasses(tagComTexto(html, 'span', 'CRÍTICO'), [
            'rounded-pub-badge', 'px-[6px]', 'py-[2px]', 'text-[10.5px]', 'font-bold', 'tracking-[0.06em]',
            'bg-pub-danger/[0.13]', 'text-pub-danger-txt',
        ], 'o badge CRÍTICO');
        conferirClasses(tagComTexto(html, 'span', 'ATENÇÃO'), [
            'rounded-pub-badge', 'px-[6px]', 'py-[2px]', 'text-[10.5px]', 'font-bold', 'tracking-[0.06em]',
            'bg-pub-warn/[0.13]', 'text-pub-warn-txt',
        ], 'o badge ATENÇÃO');
        conferirClasses(tagComTexto(html, 'span', 'OPORTUNIDADE'), [
            'rounded-pub-badge', 'px-[6px]', 'py-[2px]', 'text-[10.5px]', 'font-bold', 'tracking-[0.06em]',
            'bg-pub-accent/[0.12]', 'text-pub-accent',
        ], 'o badge OPORTUNIDADE');
    });

    await contexto.test('⚠️ 17 — UM badge RECOMENDADO AGORA, nunca dois (:514, `acts[0].id`)', () => {
        const badges = [...html.matchAll(/RECOMENDADO AGORA/g)];
        assert.equal(badges.length, 1);
        conferirClasses(tagComTexto(html, 'span', 'RECOMENDADO AGORA'), [
            'rounded-pub-badge', 'border', 'border-pub-line-off', 'px-[6px]', 'py-[2px]',
            'text-[10.5px]', 'font-semibold', 'tracking-[0.06em]', 'text-pub-fase1',
        ], 'o badge RECOMENDADO AGORA');
    });

    await contexto.test('18 — a descrição: 12.5px #A1A1AA, UMA linha com ellipsis', () => {
        conferirClasses(
            tagComTexto(html, 'span', 'Imagem principal recusada · Título excede 60 caracteres'),
            ['truncate', 'text-[12.5px]', 'text-pub-txt-2'],
            'a descrição',
        );
    });
});

test('FID-01 — 19/20/21: a pilha, o CTA e o chevron (:189-:195)', async (contexto) => {
    const html = renderAcoes({ acoes: ACOES_CHEIAS(), contaChave: 'c-459' });

    await contexto.test('⚠️ 19b — o invólucro da pilha (:189): MEDIDA AUSENTE da tabela do plano', () => {
        const invólucros = [...html.matchAll(/<span class="flex items-center">/g)];
        assert.equal(invólucros.length, 3, 'um invólucro de pilha por ação');
    });

    await contexto.test('19 — o tile da pilha: 26×26 raio 7, -7px, border 2 #111111, 8.5px/700', () => {
        const tiles = [...html.matchAll(/<span aria-hidden="true" style="width:26px;height:26px;border-radius:7px;[^"]*" class="([^"]*)"/g)];
        // 2 itens na crítica (até 3) + 1 na oportunidade
        assert.equal(tiles.length, 3);
        for (const tile of tiles) {
            conferirClasses(`<span class="${tile[1]}">`, [
                'grid', 'flex-none', 'place-items-center', 'text-[8.5px]', '-ml-[7px]', 'border-2',
                'border-pub-surface', 'font-bold',
            ], 'o tile da pilha');
        }
    });

    await contexto.test('⚠️ a pilha mostra NO MÁXIMO 3 tiles por ação', () => {
        const muitos = ACOES_CHEIAS();
        muitos.acoes[0].itens = [1, 2, 3, 4, 5, 6].map((n) => item(`Produto ${n}`, 'm', 'o'));
        const h = renderAcoes({ acoes: muitos, contaChave: 'c-459' });
        // ⚠️ o recorte vai da ação recomendada até a PRÓXIMA linha de ação:
        // `indexOf('bg-pub-surface')` casaria com a classe da <section>, que vem
        // ANTES de tudo, e o recorte sairia vazio — gate vazio por construção.
        const inicio = h.indexOf('bg-pub-raised-0');
        const fim = h.indexOf('border-t border-pub-line-subtle bg-pub-surface', inicio);
        assert.ok(inicio > -1 && fim > inicio, 'o recorte da primeira ação saiu vazio');
        assert.equal(muitos.acoes[0].itens.length, 6, 'a ação precisa ter MAIS de 3 itens');
        assert.equal([...h.slice(inicio, fim).matchAll(/width:26px/g)].length, 3);
    });

    await contexto.test('20 — o CTA: 32px, raio 8, 12.5px/600, nos dois tons', () => {
        conferirClasses(tagComTexto(html, 'a', 'Corrigir'), [
            'flex', 'h-[32px]', 'items-center', 'whitespace-nowrap', 'rounded-[8px]', 'border',
            'px-[14px]', 'text-[12.5px]', 'font-semibold',
            'border-pub-accent', 'bg-pub-accent', 'text-pub-page',
        ], 'o CTA da recomendada');
        conferirClasses(tagComTexto(html, 'a', 'Revisar'), [
            'flex', 'h-[32px]', 'items-center', 'whitespace-nowrap', 'rounded-[8px]', 'border',
            'px-[14px]', 'text-[12.5px]', 'font-semibold',
            'border-pub-line-strong', 'bg-pub-hover-2', 'text-pub-txt',
        ], 'o CTA das outras');
    });

    await contexto.test('⚠️ o CTA chama stopPropagation (:518) — senão o clique abriria a gaveta', () => {
        assert.match(FONTE_CARD, /onClick=\{\(evento\) => evento\.stopPropagation\(\)\}/);
    });

    await contexto.test('⚠️ CTA SEM destino não nasce (EXMP-01): {acao: "sincronizar"}', () => {
        const comSincronizar = ACOES_CHEIAS();
        comSincronizar.acoes[1].destino = { acao: 'sincronizar' };
        comSincronizar.acoes[1].chave = 'portal_novas';
        const h = renderAcoes({ acoes: comSincronizar, contaChave: 'c-459' });
        assert.ok(!h.includes('>Sincronizar<'), 'nasceu um CTA sem destino');
        // e a ação continua na tela, com título, número e descrição
        assert.match(h, />Anúncios pausados ou sem estoque</);
        assert.match(h, />7</);
    });

    await contexto.test('21 — o chevron 28×28 #71717A, svg 14 stroke 2.7, transition .2s', () => {
        const chevrons = chevronsDe(html);
        assert.equal(chevrons.length, 3, 'um chevron por ação');
        for (const chevron of chevrons) {
            conferirClasses(chevron, [
                'grid', 'h-[28px]', 'w-[28px]', 'cursor-pointer', 'place-items-center', 'border-none',
                'bg-transparent', 'text-pub-txt-4',
            ], 'o chevron');
        }
        assert.match(html, /width="14" height="14"[^>]*stroke-width="2\.7"[^>]*lucide-chevron-down transition-transform duration-200/);
    });

    await contexto.test('⚠️ o chevron de ação SEM sub-item é `disabled=` (GATE-05: com o `=`)', () => {
        const chevrons = chevronsDe(html);
        // a 2ª ação (`pausados`) tem `itens: []`
        assert.match(chevrons[1], /disabled=/);
        assert.doesNotMatch(chevrons[0], /disabled=/);
        assert.doesNotMatch(chevrons[2], /disabled=/);
    });
});

test('FID-01 — 22: a sub-lista aberta (:198-:210)', async (contexto) => {
    const html = renderAcoes({
        acoes: ACOES_CHEIAS(),
        contaChave: 'c-459',
        expandidasIniciais: { com_problema: true },
    });

    await contexto.test('o chevron aberto gira 180° e o `aria-expanded` acompanha', () => {
        const chevrons = chevronsDe(html);
        assert.match(chevrons[0], /aria-expanded="true"/);
        assert.match(chevrons[0], /title="Recolher"/);
        assert.match(chevrons[1], /aria-expanded="false"/);
        assert.match(html, /lucide-chevron-down transition-transform duration-200 rotate-180/);
    });

    await contexto.test('22 — o contêiner: padding 0/20/14/72 e gap 2', () => {
        const indice = html.indexOf('>Cadeira Office Ergo<');
        const divs = tagsPorNome(html.slice(0, indice), 'div');
        conferirClasses(divs[divs.length - 2].bruto, [
            'flex', 'flex-col', 'gap-[2px]', 'pb-[14px]', 'pl-[72px]', 'pr-[20px]', 'pt-0',
        ], 'o contêiner da sub-lista');
    });

    await contexto.test('22 — o item: 40px, grid 1fr/auto/auto, raio 8, #0D0D0D, 1px #1C1C1C', () => {
        const indice = html.indexOf('>Cadeira Office Ergo<');
        const divs = tagsPorNome(html.slice(0, indice), 'div');
        conferirClasses(divs[divs.length - 1].bruto, [
            'grid', 'h-[40px]', 'grid-cols-[minmax(0,1fr)_auto_auto]', 'items-center', 'gap-[14px]',
            'rounded-[8px]', 'border', 'border-pub-line-subtle-2', 'bg-pub-sunken', 'px-[12px]',
        ], 'o item da sub-lista');
    });

    await contexto.test('22 — as iniciais 22×22 raio 6 8.5px/700', () => {
        const tiles = [...html.matchAll(/style="width:22px;height:22px;border-radius:6px;[^"]*" class="([^"]*)"/g)];
        assert.equal(tiles.length, 2, 'os 2 itens da ação crítica');
        for (const tile of tiles) {
            conferirClasses(`<span class="${tile[1]}">`, [
                'grid', 'flex-none', 'place-items-center', 'text-[8.5px]', 'font-bold',
            ], 'as iniciais do sub-item');
        }
    });

    await contexto.test('22 — nome 13px/500 truncado, meta 12px #8A8A90, motivo 12px na cor', () => {
        conferirClasses(tagComTexto(html, 'span', 'Cadeira Office Ergo'), ['truncate', 'text-[13px]', 'font-medium'], 'o nome');
        conferirClasses(tagComTexto(html, 'span', 'F1 · Premium'), ['whitespace-nowrap', 'text-[12px]', 'text-pub-txt-3'], 'a meta');
        conferirClasses(
            tagComTexto(html, 'span', 'Imagem principal recusada'),
            ['whitespace-nowrap', 'text-[12px]', 'text-pub-danger-txt'],
            'o motivo (na cor da severidade)',
        );
    });

    await contexto.test('22 — "Abrir →" 12px/500 #A1A1AA, um por item', () => {
        const abrir = [...html.matchAll(/>Abrir →</g)];
        assert.equal(abrir.length, 2);
        conferirClasses(tagComTexto(html, 'a', 'Abrir →'), ['whitespace-nowrap', 'text-[12px]', 'font-medium', 'text-pub-txt-2'], 'o Abrir');
    });

    await contexto.test('22 — "Adiar até amanhã": h26, raio 6, 12px #71717A, hover #1A1A1A + #D4D4D8', () => {
        conferirClasses(tagComTexto(html, 'button', 'Adiar até amanhã'), [
            'h-[26px]', 'cursor-pointer', 'whitespace-nowrap', 'rounded-pub-chip', 'border-none',
            'bg-transparent', 'px-[10px]', 'text-[12px]', 'text-pub-txt-4',
            'hover:bg-pub-hover-3', 'hover:text-pub-fase1',
        ], 'o botão de adiar');
        const indice = html.indexOf('>Adiar até amanhã<');
        conferirClasses(tagAntes(html, indice, 'div'), ['flex', 'justify-end', 'pt-[6px]'], 'o contêiner do adiar');
    });

    await contexto.test('⚠️ a gaveta de UMA ação não vaza para as outras', () => {
        // a ação de oportunidade tem 1 item e NÃO está aberta
        assert.ok(!html.includes('>Cadeira Oslo<'), 'o item de outra ação vazou');
        assert.equal([...html.matchAll(/Adiar até amanhã/g)].length, 1);
    });
});

test('⚠️ ação com `itens: []` NÃO abre gaveta, nem quando o estado diz aberto (:516)', async (contexto) => {
    // ⚠️ Esta é a asserção que seria VAZIA POR CONSTRUÇÃO sem a prop de estado
    // inicial: com tudo fechado, "Abrir →" está ausente de qualquer jeito.
    const html = renderAcoes({
        acoes: ACOES_CHEIAS(),
        contaChave: 'c-459',
        expandidasIniciais: { pausados: true, com_problema: true },
    });

    await contexto.test('a gaveta da ação SEM item não nasce', () => {
        // a crítica (com 2 itens) abriu: 2 "Abrir →". Se a `pausados` também
        // tivesse aberto, haveria uma gaveta vazia com um "Adiar" solto.
        assert.equal([...html.matchAll(/>Abrir →</g)].length, 2);
        assert.equal([...html.matchAll(/Adiar até amanhã/g)].length, 1);
        assert.equal([...html.matchAll(/pl-\[72px\]/g)].length, 1, 'nasceu uma 2ª sub-lista (vazia)');
    });

    await contexto.test('CONTRA-PROVA: com item, a MESMA ação abre', () => {
        const comItem = ACOES_CHEIAS();
        comItem.acoes[1].itens = [item('Banqueta Alta Ripa', 'BANQ-RIPA-75', 'Sem estoque')];
        const h = renderAcoes({ acoes: comItem, contaChave: 'c-459', expandidasIniciais: { pausados: true } });
        assert.match(h, />Banqueta Alta Ripa</);
        assert.equal([...h.matchAll(/pl-\[72px\]/g)].length, 1);
    });

    await contexto.test('e o chevron da ação sem item continua desenhado, só desabilitado', () => {
        assert.equal(chevronsDe(html).length, 3);
        assert.match(chevronsDe(html)[1], /disabled=/);
    });
});

test('o filtro por severidade (VG-09) e o que ele NÃO muda', async (contexto) => {
    await contexto.test('filtrar por `critico` deixa UMA linha na tela', () => {
        const html = renderAcoes({ acoes: ACOES_CHEIAS(), contaChave: 'c-459', filtroInicial: 'critico' });
        assert.equal(chevronsDe(html).length, 1);
        assert.match(html, />Produtos com problema na publicação</);
        assert.ok(!html.includes('>Anúncios pausados ou sem estoque<'));
        assert.ok(!html.includes('>Prontos para a Fase 2<'));
    });

    await contexto.test('⚠️ e a contagem de `Todas` CONTINUA 3 (a prop, não as linhas visíveis)', () => {
        const html = renderAcoes({ acoes: ACOES_CHEIAS(), contaChave: 'c-459', filtroInicial: 'critico' });
        const opcoes = opcoesDoSegmented(html);
        assert.equal(opcoes[0].interior.replace(/<[^>]*>/g, '').trim(), 'Todas3');
    });

    await contexto.test('⚠️ a contagem sai da PROP mesmo quando ela discorda da lista', () => {
        // payload com 3 ações e contagens dizendo 99: a tela mostra 99
        const torto = ACOES_CHEIAS();
        torto.contagens = { todas: 99, critico: 40, atencao: 30, oportunidade: 29 };
        const html = renderAcoes({ acoes: torto, contaChave: 'c-459' });
        assert.deepEqual(
            opcoesDoSegmented(html).map((o) => o.interior.replace(/<[^>]*>/g, '').trim()),
            ['Todas99', 'Crítico40', 'Atenção30', 'Oportunidade29'],
        );
    });

    await contexto.test('⚠️ a opção ATIVA acompanha o filtro', () => {
        const html = renderAcoes({ acoes: ACOES_CHEIAS(), contaChave: 'c-459', filtroInicial: 'atencao' });
        const opcoes = opcoesDoSegmented(html);
        assert.match(opcoes[2].abertura, /shadow-pub-seg-ativo/);
        for (const i of [0, 1, 3]) {
            assert.doesNotMatch(opcoes[i].abertura, /shadow-pub-seg-ativo/);
        }
    });

    await contexto.test('o filtro que não casa com nada mostra o VAZIO', () => {
        const so = ACOES_CHEIAS();
        so.acoes = [so.acoes[0]];
        const html = renderAcoes({ acoes: so, contaChave: 'c-459', filtroInicial: 'oportunidade' });
        assert.match(html, />Nada pendente neste filtro</);
        assert.equal(chevronsDe(html).length, 0);
    });

    await contexto.test('⚠️ o SUBTÍTULO conta a lista inteira, não a filtrada (:603)', () => {
        const html = renderAcoes({ acoes: ACOES_CHEIAS(), contaChave: 'c-459', filtroInicial: 'critico' });
        assert.match(html, />3 ações priorizadas a partir dos dados da conta</);
    });
});

test('"Adiar até amanhã" — posta na rota real e esconde na hora (VG-11)', async (contexto) => {
    await contexto.test('⚠️ o nome da rota é LITERAL na fonte do componente', () => {
        assert.match(FONTE_CARD, /publicador\.acoes\.adiar/);
        assert.match(FONTE_CARD, /route\('mlb\.anuncios\.publicador\.acoes\.adiar', \{ conta \}\)/);
    });

    await contexto.test('⚠️ o POST manda `{chave}` no corpo e usa as duas opções certas', () => {
        const chamada = /router\.post\(\s*urlDeAdiar\(acao\.conta\),\s*\{ chave: acao\.chave \},[\s\S]*?\{ preserveScroll: true, preserveState: false \},\s*\);/.exec(FONTE_CARD);
        assert.ok(chamada !== null, 'a chamada inteira do router.post mudou de forma');
    });

    await contexto.test('⚠️ a chave sai da PRÓPRIA ação renderizada (T-179-31), nunca de entrada', () => {
        // nenhuma leitura de input/estado de texto alimenta o corpo do POST
        assert.doesNotMatch(FONTE_CARD, /<input/);
        assert.doesNotMatch(FONTE_CARD, /event\.target\.value|evento\.target\.value/);
    });

    await contexto.test('o botão esconde a ação ANTES do POST (o otimista)', () => {
        const ordem = /setEscondidas\(\(atual\) => \(\{ \.\.\.atual, \[acao\.chave\]: true \}\)\);\s*router\.post\(/.exec(FONTE_CARD);
        assert.ok(ordem !== null, 'o esconder otimista saiu de antes do POST');
    });

    await contexto.test('a URL do adiar é função de MÓDULO, não derivada no componente (REND-04)', () => {
        assert.match(FONTE_CARD, /^function urlDeAdiar\(conta\) \{/m);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// E. "Saúde da conta" — as medidas e as asserções NEGATIVAS do ERP
// ═══════════════════════════════════════════════════════════════════════════

test('FID-01 Saúde — o card, o cabeçalho e o status geral (:235-:238)', async (contexto) => {
    const html = renderSaude({ saude: SAUDE_CHEIA() });

    await contexto.test('o card e o cabeçalho', () => {
        const secoes = tagsPorNome(html, 'section');
        assert.equal(secoes.length, 1);
        conferirClasses(secoes[0].bruto, [
            'overflow-hidden', 'rounded-pub-card', 'border', 'border-pub-line', 'bg-pub-surface',
        ], 'o card da Saúde');
        const h2 = tagsPorNome(html, 'h2')[0];
        conferirClasses(tagAntes(html, h2.indice, 'div'), [
            'flex', 'items-center', 'justify-between', 'px-[18px]', 'pb-[10px]', 'pt-[16px]',
        ], 'o cabeçalho da Saúde');
    });

    await contexto.test('o H2 é 15px/600 (não 17px: este card é da coluna de 392px)', () => {
        conferirClasses(tagComTexto(html, 'h2', 'Saúde da conta'), ['m-0', 'text-[15px]', 'font-semibold'], 'o H2 da Saúde');
    });

    await contexto.test('o status geral: 12px/500 + dot 7×7 na MESMA cor (:238)', () => {
        const pilula = /<span class="(flex items-center gap-\[6px\] text-\[12px\] font-medium[^"]*)"><span aria-hidden="true" class="([^"]*)"><\/span>Tudo conectado<\/span>/.exec(html);
        assert.ok(pilula !== null, 'a pílula do geral mudou de forma');
        conferirClasses(`<span class="${pilula[1]}">`, ['flex', 'items-center', 'gap-[6px]', 'text-[12px]', 'font-medium', 'text-pub-ok-txt'], 'a pílula do geral');
        conferirClasses(`<span class="${pilula[2]}">`, ['h-[7px]', 'w-[7px]', 'rounded-full', 'bg-pub-ok-txt'], 'o dot do geral');
    });

    await contexto.test('⚠️ as 3 cores do geral, e o dot é a CLARA nos três (:532)', () => {
        const esperado = {
            tudo_conectado: ['text-pub-ok-txt', 'bg-pub-ok-txt', 'Tudo conectado'],
            atencao: ['text-pub-warn-txt', 'bg-pub-warn-txt', 'Atenção'],
            requer_acao: ['text-pub-danger-txt', 'bg-pub-danger-txt', 'Requer ação'],
        };
        for (const [geral, [texto, dot, rotulo]] of Object.entries(esperado)) {
            const h = renderSaude({ saude: { geral, linhas: [] } });
            assert.match(h, new RegExp(`${texto}"><span aria-hidden="true" class="h-\\[7px\\] w-\\[7px\\] rounded-full ${dot}">`));
            assert.match(h, new RegExp(`>${rotulo}</span>`));
        }
    });

    await contexto.test('⚠️ e isto NÃO se uniformiza com a pílula da linha, que usa a SÓLIDA no dot', () => {
        assert.match(FONTE_SAUDE, /tudo_conectado: 'bg-pub-ok-txt'/);
        assert.match(FONTE_SAUDE, /ativo: 'bg-pub-ok'/);
        assert.doesNotMatch(FONTE_SAUDE, /ativo: 'bg-pub-ok-txt'/, 'a pílula da linha foi uniformizada');
        assert.doesNotMatch(FONTE_SAUDE, /tudo_conectado: 'bg-pub-ok'[,\s]/, 'o dot do geral foi uniformizado');
    });
});

test('FID-01 Saúde — as 3 linhas, o tile, a meta, o botão e o spinner (:240-:250)', async (contexto) => {
    const html = renderSaude({ saude: SAUDE_CHEIA() });

    await contexto.test('⚠️ EXATAMENTE 3 linhas, nunca 4', () => {
        const linhas = [...html.matchAll(/border-t border-pub-line-subtle-2/g)];
        assert.equal(linhas.length, 3);
    });

    await contexto.test('a linha: gap 12, padding 11/18, border-top #1C1C1C', () => {
        conferirClasses(linhaDaSaude(html, 'Mercado Livre').abertura, [
            'flex', 'items-center', 'gap-[12px]', 'border-t', 'border-pub-line-subtle-2', 'px-[18px]', 'py-[11px]',
        ], 'a linha do ML');
    });

    await contexto.test('o tile 32×32 raio 8 10.5px/700: ML amarelo, os outros na divisória', () => {
        const base = ['grid', 'h-[32px]', 'w-[32px]', 'flex-none', 'place-items-center', 'rounded-[8px]', 'text-[10.5px]', 'font-bold'];
        conferirClasses(tagComTexto(html, 'span', 'ML'), [...base, 'bg-pub-accent', 'text-pub-page'], 'o tile do ML');
        conferirClasses(tagComTexto(html, 'span', 'ERP'), [...base, 'bg-pub-line-subtle', 'text-pub-fase1'], 'o tile do ERP');
        conferirClasses(tagComTexto(html, 'span', '⟳'), [...base, 'bg-pub-line-subtle', 'text-pub-fase1'], 'o tile da Sincronização');
    });

    await contexto.test('a coluna, o nome 13px/500 e a meta 11.5px truncada', () => {
        const indice = html.indexOf('>Mercado Livre<');
        const spans = tagsPorNome(html.slice(0, indice), 'span');
        conferirClasses(spans[spans.length - 1].bruto, ['flex', 'min-w-0', 'flex-1', 'flex-col', 'gap-[2px]'], 'a coluna da linha');
        conferirClasses(tagComTexto(html, 'span', 'Mercado Livre'), ['text-[13px]', 'font-medium'], 'o nome');
        conferirClasses(tagComTexto(html, 'span', 'ECFDEV02 · 2015132968'), ['truncate', 'text-[11.5px]', 'text-pub-txt-3'], 'a meta');
    });

    await contexto.test('a pílula da linha do ML: texto claro + dot SÓLIDO (:250)', () => {
        const { bloco } = linhaDaSaude(html, 'Mercado Livre');
        const pilula = /<span class="(flex items-center gap-\[6px\][^"]*)"><span aria-hidden="true" class="([^"]*)"><\/span>Conectado<\/span>/.exec(bloco);
        assert.ok(pilula !== null, 'a pílula da linha mudou de forma');
        conferirClasses(`<span class="${pilula[1]}">`, ['flex', 'items-center', 'gap-[6px]', 'text-[12px]', 'font-medium', 'text-pub-ok-txt'], 'a pílula do ML');
        conferirClasses(`<span class="${pilula[2]}">`, ['h-[7px]', 'w-[7px]', 'rounded-full', 'bg-pub-ok'], 'o dot do ML');
    });

    await contexto.test('o botão "Atualizar" (:246) com as 10 classes medidas', () => {
        conferirClasses(tagComTexto(html, 'button', 'Atualizar'), [
            'flex', 'h-[28px]', 'cursor-pointer', 'items-center', 'gap-[6px]', 'whitespace-nowrap',
            'rounded-pub-control-sm', 'border', 'border-pub-line-strong', 'bg-pub-surface', 'px-[10px]',
            'text-[12px]', 'text-pub-fase1', 'hover:border-pub-line-off',
        ], 'o botão Atualizar');
    });

    await contexto.test('⚠️ UM spinner quando `atualizando`: 11×11, 1.5px, topo amarelo, spin .7s', () => {
        const h = renderSaude({ saude: SAUDE_CHEIA(), atualizandoInicialmente: true });
        const spinners = [...h.matchAll(/animate-pub-spin/g)];
        assert.equal(spinners.length, 1);
        const spinner = /<span aria-hidden="true" class="(h-\[11px\][^"]*)"><\/span>Atualizando/.exec(h);
        assert.ok(spinner !== null, 'o spinner mudou de forma');
        conferirClasses(`<span class="${spinner[1]}">`, [
            'h-[11px]', 'w-[11px]', 'animate-pub-spin', 'rounded-full', 'border-[1.5px]',
            'border-pub-line-off', 'border-t-pub-accent',
        ], 'o spinner');
        assert.match(h, />Atualizando</);
        const botao = /<button([^>]*)>(?:(?!<\/button>)[\s\S])*Atualizando<\/button>/.exec(h);
        assert.ok(botao !== null, 'não achei o botão com o spinner');
        assert.match(`<button${botao[1]}>`, /disabled=/);
    });

    await contexto.test('sem `atualizando`, ZERO spinner e o rótulo é "Atualizar"', () => {
        assert.equal([...html.matchAll(/animate-pub-spin/g)].length, 0);
        assert.match(html, />Atualizar</);
        assert.ok(!html.includes('>Atualizando<'));
    });

    await contexto.test('⚠️ "Atualizar" aponta para a rota que ENFILEIRA, com onStart/onFinish', () => {
        const chamada = /router\.post\(\s*linha\.atualizar_url,\s*\{\},\s*\{\s*preserveScroll: true,\s*onStart: \(\) => setAtualizando\(true\),\s*onFinish: \(\) => setAtualizando\(false\),\s*\},\s*\)/.exec(FONTE_SAUDE);
        assert.ok(chamada !== null, 'a chamada inteira do router.post mudou de forma');
        // ⚠️ o spinner EM SI é conferência visual do usuário: aqui o que se prova
        // é que o estado existe, liga o `disabled` e troca o rótulo.
    });

    await contexto.test('sem `atualizar_url` o botão NÃO nasce (T-179-34)', () => {
        const h = renderSaude({ saude: { geral: 'atencao', linhas: [{ chave: 'sincronizacao', nunca_coletado: true, atualizar_url: null }] } });
        assert.ok(!h.includes('>Atualizar<'), 'nasceu um botão sem destino');
        assert.match(h, />Nunca coletado</);
    });
});

test('⚠️ D — ERP: as asserções NEGATIVAS (177-03, 179-03 D-1/M04/M05)', async (contexto) => {
    // ⚠️ Positiva não serviria: uma fase futura poderia ACRESCENTAR "Conectado"
    // à linha do ERP e um teste de presença seguiria verde.
    await contexto.test('a linha do ERP NÃO contém "Conectado"', () => {
        const html = renderSaude({ saude: SAUDE_CHEIA() });
        const { bloco } = linhaDaSaude(html, 'ERP');
        assert.ok(!bloco.includes('Conectado'), 'o ERP diz Conectado');
        assert.ok(!bloco.includes('Desconectado'), 'o ERP diz Desconectado');
        assert.match(bloco, />Declarado: Bling</);
    });

    await contexto.test('a linha do ERP NÃO tem dot (verde nem nenhum) e NÃO tem pílula', () => {
        const html = renderSaude({ saude: SAUDE_CHEIA() });
        const { bloco } = linhaDaSaude(html, 'ERP');
        assert.ok(!bloco.includes('bg-pub-ok'), 'dot verde no ERP');
        assert.ok(!bloco.includes('rounded-full'), 'dot no ERP');
        assert.ok(!bloco.includes('bg-pub-warn'), 'tint âmbar no ERP');
    });

    await contexto.test('⚠️ `declarado: null` diz "Não informado" e o geral CONTINUA tudo_conectado', () => {
        const saude = SAUDE_CHEIA();
        saude.linhas[1] = { chave: 'erp', declarado: null, problema: false };
        const html = renderSaude({ saude });
        const { bloco } = linhaDaSaude(html, 'ERP');
        assert.match(bloco, />Não informado</);
        assert.match(html, />Tudo conectado</);
        assert.ok(!html.includes('Requer ação'));
    });

    await contexto.test('⚠️ `declarado` VAZIO também é "Não informado" (não "Declarado: ")', () => {
        for (const declarado of ['', '   ', 7, {}, [], null]) {
            const html = renderSaude({ saude: { geral: 'atencao', linhas: [{ chave: 'erp', declarado }] } });
            assert.match(html, />Não informado</, JSON.stringify(declarado));
            assert.ok(!html.includes('Declarado: '), JSON.stringify(declarado));
        }
    });

    await contexto.test('⚠️ nenhum CTA "Reconectar ERP" em lugar nenhum', () => {
        const html = renderSaude({ saude: SAUDE_CHEIA() });
        assert.ok(!html.includes('Reconectar'), 'nasceu um CTA de reconexão do ERP');
        assert.doesNotMatch(FONTE_SAUDE, /Reconectar/);
    });

    await contexto.test('⚠️ o ERP tem SÓ as 3 chaves do contrato — `conectado` não existe', () => {
        const dados = A.linhasDaSaude({ linhas: [{ chave: 'erp', declarado: 'X', conectado: true, estado: 'ativo' }] });
        // mesmo com o servidor mentindo, a tela não lê `conectado` nem `estado`
        assert.equal(dados.linhas[0].estado, null);
        assert.equal(dados.linhas[0].rotulo_estado, null);
        const html = renderSaude({ saude: { geral: 'tudo_conectado', linhas: [{ chave: 'erp', declarado: 'X', conectado: true, estado: 'ativo' }] } });
        const { bloco } = linhaDaSaude(html, 'ERP');
        assert.ok(!bloco.includes('Conectado'));
    });

    await contexto.test('o tint vermelho é do ML com problema; o âmbar, da coleta defasada', () => {
        const saude = SAUDE_CHEIA();
        saude.geral = 'requer_acao';
        saude.linhas[0] = { chave: 'mercado_livre', estado: 'expirado', conta_nome: null, conta_ml_id: null, problema: true, link_reconexao: '/x' };
        saude.linhas[2].defasado = true;
        const html = renderSaude({ saude });
        assert.match(linhaDaSaude(html, 'Mercado Livre').abertura, /bg-pub-danger\/\[0\.07\]/);
        assert.match(linhaDaSaude(html, 'Sincronização').abertura, /bg-pub-warn\/\[0\.07\]/);
        assert.doesNotMatch(linhaDaSaude(html, 'ERP').abertura, /bg-pub-(danger|warn)\/\[0\.07\]/);
        assert.match(html, />Token expirado</);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// F. "Publicações no Mercado Livre"
// ═══════════════════════════════════════════════════════════════════════════

test('FID-01 Publicações — card, total, barra e legenda (:256-:266)', async (contexto) => {
    const html = renderPubs({ publicacoesMl: PUBS_CHEIAS(), acervoImagens: ACERVO_CHEIO(), urlPublicacoes: '/pubs' });

    await contexto.test('o card: coluna com gap 14, padding 16/18/18', () => {
        const secoes = tagsPorNome(html, 'section');
        assert.equal(secoes.length, 1);
        conferirClasses(secoes[0].bruto, [
            'flex', 'flex-col', 'gap-[14px]', 'rounded-pub-card', 'border', 'border-pub-line',
            'bg-pub-surface', 'px-[18px]', 'pb-[18px]', 'pt-[16px]',
        ], 'o card de Publicações');
    });

    await contexto.test('o H2 15px/600 e o "Ver todas →" 12.5px/500', () => {
        conferirClasses(tagComTexto(html, 'h2', 'Publicações no Mercado Livre'), ['m-0', 'text-[15px]', 'font-semibold'], 'o H2');
        conferirClasses(tagComTexto(html, 'a', 'Ver todas →'), ['whitespace-nowrap', 'text-[12.5px]', 'font-medium', 'text-pub-txt-2'], 'o Ver todas');
    });

    await contexto.test('o total 30px -0.03em tabular-nums, com "ofertas · N% ativas"', () => {
        conferirClasses(tagComTexto(html, 'span', '58'), [
            'text-[30px]', 'font-semibold', 'leading-none', 'tracking-[-0.03em]', 'tabular-nums',
        ], 'o total');
        const indice = html.indexOf('>58<');
        conferirClasses(tagAntes(html, indice, 'div'), ['flex', 'items-baseline', 'gap-[8px]'], 'a linha do total');
        assert.match(html, /<span class="text-\[12\.5px\] text-pub-txt-3">ofertas · 84% ativas<\/span>/);
    });

    await contexto.test('⚠️ a barra tem EXATAMENTE 4 faixas, nunca 3 nem 5', () => {
        const faixas = faixasDaBarra(html);
        assert.equal(faixas.length, 4);
        const indice = html.indexOf(faixas[0]);
        conferirClasses(tagAntes(html, indice, 'div'), [
            'flex', 'h-[8px]', 'gap-[2px]', 'overflow-hidden', 'rounded-[4px]', 'bg-pub-line-subtle-2',
        ], 'o trilho da barra');
    });

    await contexto.test('⚠️ as 4 cores de faixa, por CLASSE (em_revisao é `bg-pub-neutral-dot`)', () => {
        const faixas = faixasDaBarra(html);
        const esperadas = ['bg-pub-ok', 'bg-pub-warn', 'bg-pub-neutral-dot', 'bg-pub-line-off'];
        faixas.forEach((faixa, i) => {
            conferirClasses(faixa, ['basis-0', esperadas[i]], `a faixa ${i}`);
        });
        // ⚠️ `bg-pub-neutral-dot` e `bg-pub-txt-4` são o MESMO hex (#71717A) com
        // nomes diferentes: o gate lê a CLASSE, não a cor computada.
        assert.match(FONTE_PUBS, /em_revisao: 'bg-pub-neutral-dot'/);
        assert.doesNotMatch(FONTE_PUBS, /bg-pub-txt-4/);
    });

    await contexto.test('o `flex-grow` de cada faixa é o total dela', () => {
        assert.deepEqual(
            faixasDaBarra(html).map((f) => /flex-grow:(\d+)/.exec(f)[1]),
            ['49', '7', '2', '0'],
        );
    });

    await contexto.test('a legenda: 5 linhas (4 faixas + "Pedem ação"), h30 raio 7 13px', () => {
        const linhas = [...html.matchAll(/<a href="([^"]*)" class="(flex h-\[30px\][^"]*)">/g)];
        assert.equal(linhas.length, 5);
        conferirClasses(`<a class="${linhas[0][2]}">`, [
            'flex', 'h-[30px]', 'items-center', 'gap-[10px]', '-mx-[8px]', 'whitespace-nowrap',
            'rounded-pub-control-sm', 'px-[8px]', 'text-[13px]', 'text-pub-fase1', 'hover:bg-pub-hover-3',
        ], 'a linha da legenda');
        const indice = html.indexOf(linhas[0][0]);
        conferirClasses(tagAntes(html, indice, 'div'), ['flex', 'flex-col', 'gap-[2px]'], 'o contêiner da legenda');
    });

    await contexto.test('o swatch 8×8 raio 2 e o número com tom por valor', () => {
        const swatches = [...html.matchAll(/<span aria-hidden="true" class="(h-\[8px\] w-\[8px\][^"]*)">/g)];
        assert.equal(swatches.length, 5);
        conferirClasses(`<span class="${swatches[0][1]}">`, ['h-[8px]', 'w-[8px]', 'rounded-[2px]', 'bg-pub-ok'], 'o swatch');
        conferirClasses(tagComTexto(html, 'span', '49'), ['ml-auto', 'font-medium', 'tabular-nums', 'text-pub-txt'], 'o número > 0');
        conferirClasses(tagComTexto(html, 'span', '0'), ['ml-auto', 'font-medium', 'tabular-nums', 'text-pub-txt-off'], 'o número 0');
    });

    await contexto.test('⚠️ cada linha da legenda leva à URL do SERVIDOR, filtrada', () => {
        assert.deepEqual(
            [...html.matchAll(/<a href="([^"]*)" class="flex h-\[30px\]/g)].map((m) => m[1]),
            ['/p?status=ativos', '/p?status=pausados', '/p?status=em_revisao', '/p?status=encerrados', '/p?pedem=1'],
        );
    });

    await contexto.test('⚠️ "Pedem ação" é a 5ª LEGENDA, não uma 5ª faixa da barra', () => {
        assert.equal(faixasDaBarra(html).length, 4);
        assert.match(html, />Pedem ação</);
        const swatchDoPedem = /class="h-\[8px\] w-\[8px\] rounded-\[2px\] bg-pub-danger">/.exec(html);
        assert.ok(swatchDoPedem !== null, 'o swatch de "Pedem ação" não é vermelho');
    });

    await contexto.test('⚠️ `pedem_acao` AUSENTE ⇒ 4 legendas e nenhum "Pedem ação"', () => {
        const sem = PUBS_CHEIAS();
        delete sem.pedem_acao;
        const h = renderPubs({ publicacoesMl: sem, acervoImagens: ACERVO_CHEIO(), urlPublicacoes: '/pubs' });
        assert.equal([...h.matchAll(/class="flex h-\[30px\]/g)].length, 4);
        assert.ok(!h.includes('Pedem ação'));
        // CONTRA-PROVA: com a chave (e zero medido) ela VOLTA
        const zero = PUBS_CHEIAS();
        zero.pedem_acao = { total: 0, url: '/p?pedem=1' };
        const h2 = renderPubs({ publicacoesMl: zero, acervoImagens: ACERVO_CHEIO(), urlPublicacoes: '/pubs' });
        assert.match(h2, />Pedem ação</);
        assert.equal([...h2.matchAll(/class="flex h-\[30px\]/g)].length, 5);
    });

    await contexto.test('⚠️ E — "Com erro" está AUSENTE do card inteiro', () => {
        assert.ok(!html.includes('Com erro'), 'alguém inventou o status de erro');
    });
});

test('FID-01 Publicações — o acervo e o mosaico (:268-:272)', async (contexto) => {
    const html = renderPubs({ publicacoesMl: PUBS_CHEIAS(), acervoImagens: ACERVO_CHEIO(), urlPublicacoes: '/pubs' });

    await contexto.test('o bloco do acervo: gap 12, padding 12, raio 10, #0D0D0D, 1px #1C1C1C', () => {
        const indice = html.indexOf('>Acervo de imagens<');
        const divs = tagsPorNome(html.slice(0, indice), 'div');
        conferirClasses(divs[divs.length - 1].bruto, [
            'flex', 'items-center', 'gap-[12px]', 'rounded-pub-sub', 'border', 'border-pub-line-subtle-2',
            'bg-pub-sunken', 'p-[12px]',
        ], 'o bloco do acervo');
    });

    await contexto.test('⚠️ o mosaico tem EXATAMENTE 6 quadrados de 14px, em 3 colunas', () => {
        const indice = html.indexOf('>Acervo de imagens<');
        const bloco = html.slice(html.lastIndexOf('<div', indice - 400), indice);
        const quadrados = [...bloco.matchAll(/class="h-\[14px\] w-\[14px\] rounded-\[3px\][^"]*"/g)];
        assert.equal(quadrados.length, 6);
        const mosaico = /<span aria-hidden="true" class="(grid grid-cols-\[repeat\(3,14px\)\][^"]*)">/.exec(bloco);
        assert.ok(mosaico !== null, 'o mosaico mudou de forma');
        conferirClasses(`<span class="${mosaico[1]}">`, ['grid', 'grid-cols-[repeat(3,14px)]', 'gap-[3px]'], 'o mosaico');
    });

    await contexto.test('⚠️ a QUINTA célula é `bg-pub-accent`; as outras 5 têm tom calculado (:271)', () => {
        const todos = [...html.matchAll(/<span([^>]*)class="h-\[14px\] w-\[14px\] rounded-\[3px\]([^"]*)"/g)];
        assert.equal(todos.length, 6, `achei ${todos.length} quadrados`);
        const comTom = todos.filter((q) => /oklch\(/.test(q[1]));
        const amarelos = todos.filter((q) => / bg-pub-accent/.test(q[2]));
        assert.equal(comTom.length, 5, 'os 5 tons calculados');
        assert.equal(amarelos.length, 1, 'UMA célula amarela');
        // e é a quinta
        assert.ok(/ bg-pub-accent/.test(todos[4][2]), 'a célula amarela não é a quinta');
        assert.equal(A.CELULA_AMARELA, 5);
    });

    await contexto.test('⚠️ os 5 hues são os do protótipo (250, 70, 330, 160, 30), não vizinhos', () => {
        const hues = [...html.matchAll(/oklch\(0\.27 0\.035 (\d+)\)/g)].map((m) => Number(m[1]));
        assert.deepEqual(hues, [250, 70, 330, 160, 30]);
        // ISCA: sementes consecutivas dariam hues consecutivos — e um mosaico de
        // uma só cor passaria por "6 quadrados" sem ninguém notar
        const vizinhos = hues.slice(1).filter((h, i) => Math.abs(h - hues[i]) <= 2);
        assert.equal(vizinhos.length, 0, 'os tons do mosaico são vizinhos: é um quadrado de uma cor');
    });

    await contexto.test('⚠️ H — `oklch(` NÃO aparece em nenhuma das 4 fontes (IDENT-08)', () => {
        for (const [caminho, fonte] of [[REL_ACOES, FONTE_ACOES], ...FONTES_JSX]) {
            assert.ok(!fonte.includes('oklch('), `${caminho} recalcula oklch`);
        }
        assert.match(FONTE_PUBS, /import \{ tomDoProduto \} from '\.\.\/identidade\/identidade'/);
    });

    await contexto.test('o nome 13px/500 e a meta 11.5px', () => {
        conferirClasses(tagComTexto(html, 'span', 'Acervo de imagens'), ['text-[13px]', 'font-medium'], 'o nome do acervo');
        conferirClasses(
            tagComTexto(html, 'span', '128 imagens · 9 packs gerados com IA'),
            ['text-[11.5px]', 'text-pub-txt-3'],
            'a meta do acervo',
        );
    });

    await contexto.test('⚠️ o acervo sai SEM "Abrir →" (não existe tela de acervo por conta)', () => {
        assert.ok(!html.includes('Abrir →'), 'nasceu um link sem destino');
        assert.doesNotMatch(FONTE_PUBS, /ABRIR/);
    });
});

test('⚠️ C — VG-18: os DOIS lados juntos (só o par prova algo)', async (contexto) => {
    await contexto.test('`disponivel: false` ⇒ "—" com o motivo, e NENHUM zero no card', () => {
        const html = renderPubs({
            publicacoesMl: { disponivel: false, total: null, faixas: [], outras: null, ativas_pct: null },
            acervoImagens: { imagens: null, packs: null },
            urlPublicacoes: null,
        });
        assert.match(html, />—</);
        assert.match(html, /title="Nenhuma coleta do acervo ainda: este número não foi medido, e zero seria mentira\."/);
        assert.ok(!/>0</.test(html), 'apareceu um zero inventado');
        assert.ok(!html.includes('% ativas'), 'apareceu um percentual inventado');
        assert.match(html, />ofertas · nunca coletado</);
        semLixo(html, 'publicações indisponíveis');
    });

    await contexto.test('`disponivel: true, total: 0` ⇒ "0" MEDIDO, e nenhum percentual', () => {
        const html = renderPubs({
            publicacoesMl: { disponivel: true, total: 0, faixas: [], outras: 0, ativas_pct: null, pedem_acao: { total: 0, url: '/p' } },
            acervoImagens: { imagens: 0, packs: 0 },
            urlPublicacoes: '/pubs',
        });
        assert.match(html, /tabular-nums">0<\/span>/);
        assert.ok(!html.includes('>—<'), 'zero medido virou "—"');
        assert.ok(!html.includes('% ativas'));
        assert.match(html, />ofertas · nenhuma ainda</);
        assert.match(html, />0 imagens · 0 packs gerados com IA</);
    });

    await contexto.test('⚠️ e os dois textos de split são DIFERENTES (os dois sentidos de pct null)', () => {
        const naoMedido = renderPubs({ publicacoesMl: { disponivel: false, total: null, faixas: [] }, acervoImagens: {} });
        const zeroMedido = renderPubs({ publicacoesMl: { disponivel: true, total: 0, faixas: [] }, acervoImagens: {} });
        assert.match(naoMedido, />ofertas · nunca coletado</);
        assert.match(zeroMedido, />ofertas · nenhuma ainda</);
    });

    await contexto.test('acervo: `imagens: null` ⇒ "—"; `imagens: 0` ⇒ "0"', () => {
        const nulo = renderPubs({ publicacoesMl: PUBS_CHEIAS(), acervoImagens: { imagens: null, packs: 9 } });
        assert.match(nulo, />— imagens · 9 packs gerados com IA</);
        const zero = renderPubs({ publicacoesMl: PUBS_CHEIAS(), acervoImagens: { imagens: 0, packs: 9 } });
        assert.match(zero, />0 imagens · 9 packs gerados com IA</);
        // e o `title` só existe no caso não medido
        assert.match(nulo, /title="Nenhuma coleta/);
        const semTitle = zero.slice(zero.indexOf('Acervo de imagens'));
        assert.ok(!semTitle.includes('title="Nenhuma coleta'));
    });

    await contexto.test('⚠️ sem faixa medida a barra e a legenda NÃO nascem (nada de trilho decorativo)', () => {
        const html = renderPubs({ publicacoesMl: { disponivel: false, total: null, faixas: [] }, acervoImagens: {} });
        assert.equal(faixasDaBarra(html).length, 0);
        assert.ok(!html.includes('h-[8px]'), 'nasceu um trilho vazio');
        assert.ok(!html.includes('h-[30px]'), 'nasceu uma legenda vazia');
    });

    await contexto.test('⚠️ `urlPublicacoes` nula (D23): "Ver todas →" não nasce', () => {
        const html = renderPubs({ publicacoesMl: PUBS_CHEIAS(), acervoImagens: ACERVO_CHEIO(), urlPublicacoes: null });
        assert.ok(!html.includes('Ver todas'), 'nasceu um link sem destino');
        // a legenda, que tem url PRÓPRIA do servidor, continua navegando
        assert.equal([...html.matchAll(/<a href="\/p\?/g)].length, 5);
    });

    await contexto.test('⚠️ faixa SEM url do servidor vira <span>, não <a href> morto', () => {
        const html = renderPubs({
            publicacoesMl: { disponivel: true, total: 1, faixas: [{ chave: 'ativas', total: 1, url: null }], ativas_pct: 100 },
            acervoImagens: {},
        });
        assert.match(html, /<span class="flex h-\[30px\]/);
        assert.ok(!html.includes('<a href="null"'));
        assert.ok(!html.includes('href=""'));
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// G. Casos adversos — a tela preta de 07/10 (REND-01/02), render REAL
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ F — casos adversos: nenhum campo do servidor derruba os três blocos', async (contexto) => {
    const TORTOS = [null, undefined, 'x', 7, 0, true, false, [], {}, { a: 1 }, NaN];

    await contexto.test('`acoes` em TODAS as formas tortas', () => {
        for (const bruta of TORTOS) {
            const html = renderAcoes({ acoes: bruta, contaChave: 'c-459' });
            semLixo(html, `acoes = ${JSON.stringify(bruta)}`);
            assert.match(html, />O que fazer agora</, 'o bloco desmontou');
            assert.match(html, />Nada pendente neste filtro</);
        }
    });

    await contexto.test('`acoes.acoes` em todas as formas, e com itens podres dentro', () => {
        const formas = [null, undefined, 'x', 7, {}, [], [null], [undefined], ['x'], [7], [{}], [[]], [true]];
        for (const acoes of formas) {
            const html = renderAcoes({ acoes: { acoes, contagens: null }, contaChave: 'c-459' });
            semLixo(html, `acoes.acoes = ${JSON.stringify(acoes)}`);
            assert.match(html, />O que fazer agora</);
        }
    });

    await contexto.test('cada campo da ação como OBJETO, um por vez', () => {
        const campos = ['chave', 'severidade', 'titulo', 'descricao', 'numero', 'recomendada', 'destino', 'itens'];
        for (const campo of campos) {
            const base = { chave: 'pausados', severidade: 'atencao', titulo: 'T', descricao: 'D', numero: 3, recomendada: false, itens: [item('N', 'M', 'O')], destino: { rota: 'r', params: {} } };
            base[campo] = { enganado: true };
            const html = renderAcoes({ acoes: { acoes: [base], contagens: { todas: 1 } }, contaChave: 'c-459' });
            semLixo(html, `ação com ${campo} objeto`);
            assert.match(html, />O que fazer agora</);
        }
    });

    await contexto.test('cada campo do sub-item como objeto, com a gaveta ABERTA', () => {
        for (const campo of ['nome', 'meta', 'motivo']) {
            const sub = { nome: 'N', meta: 'M', motivo: 'O' };
            sub[campo] = { enganado: true };
            const html = renderAcoes({
                acoes: { acoes: [{ chave: 'pausados', severidade: 'atencao', titulo: 'T', itens: [sub, null, 7], destino: { rota: 'r', params: {} } }], contagens: { todas: 1 } },
                contaChave: 'c-459',
                expandidasIniciais: { pausados: true },
            });
            semLixo(html, `sub-item com ${campo} objeto`);
            assert.match(html, />Abrir →</, 'a gaveta não abriu');
        }
    });

    await contexto.test('`contaChave` torta não produz href nem botão podre', () => {
        for (const contaChave of [null, undefined, {}, 7, [], '', '   ', true]) {
            const html = renderAcoes({ acoes: ACOES_CHEIAS(), contaChave, expandidasIniciais: { com_problema: true } });
            semLixo(html, `contaChave = ${JSON.stringify(contaChave)}`);
            assert.ok(!html.includes('Adiar até amanhã'), 'botão sem destino');
        }
    });

    await contexto.test('NENHUMA prop nos três componentes', () => {
        for (const [nome, html] of [['ações', renderAcoes()], ['saúde', renderSaude()], ['publicações', renderPubs()]]) {
            semLixo(html, `sem prop (${nome})`);
        }
        assert.match(renderAcoes(), />O que fazer agora</);
        assert.match(renderSaude(), />Saúde da conta</);
        assert.match(renderPubs(), />Publicações no Mercado Livre</);
    });

    await contexto.test('`saude` inteira torta, `linhas` torta e linha podre', () => {
        for (const saude of TORTOS) {
            const html = renderSaude({ saude });
            semLixo(html, `saude = ${JSON.stringify(saude)}`);
            assert.match(html, />Saúde da conta</, 'o bloco desmontou');
        }
        for (const linhas of [null, 'x', 7, {}, [], [null], ['x'], [7], [{}], [[]], [{ chave: 'inventada' }]]) {
            const html = renderSaude({ saude: { geral: 'atencao', linhas } });
            semLixo(html, `linhas = ${JSON.stringify(linhas)}`);
            assert.match(html, />Saúde da conta</);
        }
    });

    await contexto.test('cada campo da linha da Saúde como objeto', () => {
        const campos = ['estado', 'conta_nome', 'conta_ml_id', 'problema', 'declarado', 'horas', 'defasado', 'nunca_coletado', 'atualizar_url'];
        for (const campo of campos) {
            const saude = SAUDE_CHEIA();
            for (const linha of saude.linhas) linha[campo] = { enganado: true };
            const html = renderSaude({ saude });
            semLixo(html, `linha com ${campo} objeto`);
            assert.match(html, />Saúde da conta</);
        }
    });

    await contexto.test('`publicacoesMl` e `acervoImagens` inteiros tortos', () => {
        for (const publicacoesMl of TORTOS) {
            const html = renderPubs({ publicacoesMl, acervoImagens: ACERVO_CHEIO() });
            semLixo(html, `publicacoesMl = ${JSON.stringify(publicacoesMl)}`);
            assert.match(html, />Publicações no Mercado Livre</, 'o bloco desmontou');
        }
        for (const acervoImagens of TORTOS) {
            const html = renderPubs({ publicacoesMl: PUBS_CHEIAS(), acervoImagens });
            semLixo(html, `acervoImagens = ${JSON.stringify(acervoImagens)}`);
            assert.match(html, />Acervo de imagens</);
        }
    });

    await contexto.test('`faixas` torta, faixa nula e campos da faixa como objeto', () => {
        for (const faixas of [null, 'x', 7, {}, [], [null], ['x'], [7], [{}], [[]], [{ chave: {} }]]) {
            const html = renderPubs({ publicacoesMl: { disponivel: true, total: 1, faixas }, acervoImagens: {} });
            semLixo(html, `faixas = ${JSON.stringify(faixas)}`);
            assert.match(html, />Publicações no Mercado Livre</);
        }
        const html = renderPubs({
            publicacoesMl: { disponivel: true, total: { a: 1 }, ativas_pct: [], outras: {}, faixas: [{ chave: 'ativas', total: {}, url: {} }], pedem_acao: { total: {}, url: [] } },
            acervoImagens: { imagens: {}, packs: [] },
        });
        semLixo(html, 'tudo objeto');
        assert.match(html, />—</);
    });

    await contexto.test('⚠️ `pedem_acao` AUSENTE não derruba e nem inventa legenda', () => {
        const sem = { disponivel: true, total: 5, faixas: [{ chave: 'ativas', total: 5, url: '/a' }], outras: 0, ativas_pct: 100 };
        assert.ok(!('pedem_acao' in sem));
        const html = renderPubs({ publicacoesMl: sem, acervoImagens: ACERVO_CHEIO() });
        semLixo(html, 'pedem_acao ausente');
        assert.ok(!html.includes('Pedem ação'));
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// H. REND-04 e o reuso
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ G — REND-04: nenhuma flag de escopo do componente entra nos `.map()`', async (contexto) => {
    // Molde: `publicador-dashboard-v2.test.js:843` e `publicador-visao-geral-jornada.test.js`.
    // As proibidas são as variáveis DERIVADAS no corpo do componente — a forma
    // exata que o Rollup eliminou neste projeto (`feedback_rollup_map_scope_bug.md`).
    const VARREDURAS = [
        [REL_CARD, FONTE_CARD, ['dados', 'visiveis', 'fora', 'filtro', 'expandidas', 'escondidas', 'contaChave'], [
            'abasComContagem(dados.contagens, fora, filtro).map(',
            'visiveis.map(',
            'acao.tiles.map(',
            'acao.itens.map(',
        ]],
        [REL_SAUDE, FONTE_SAUDE, ['dados', 'saude'], ['dados.linhas.map(']],
        [REL_PUBS, FONTE_PUBS, ['resumo', 'acervo', 'publicacoesMl', 'acervoImagens', 'urlPublicacoes'], [
            'resumo.faixas.map(',
            ').map(',
            'SEMENTES_DO_MOSAICO.map(',
        ]],
    ];

    await contexto.test('cada `.map()` lê só o próprio parâmetro, os mapas de módulo e os setState', () => {
        for (const [caminho, fonte, proibidas, marcadores] of VARREDURAS) {
            for (const marcador of marcadores) {
                const inicio = fonte.indexOf(marcador);
                assert.ok(inicio > -1, `${caminho}: .map() não encontrado: ${marcador}`);
                const fim = fonte.indexOf('))}', inicio);
                const corpo = fonte.slice(inicio + marcador.length, fim === -1 ? fonte.length : fim);
                for (const flag of proibidas) {
                    assert.ok(
                        !new RegExp(`\\b${flag}\\b`).test(corpo),
                        `${caminho}: variável de escopo do componente dentro de ${marcador}: ${flag}`,
                    );
                }
            }
        }
    });

    await contexto.test('⚠️ ISCA: a varredura ACHA o padrão proibido quando ele existe', () => {
        const falso = "visiveis.map((acao) => { const x = dados; return x; }))}";
        const inicio = falso.indexOf('visiveis.map(');
        const corpo = falso.slice(inicio + 'visiveis.map('.length, falso.indexOf('))}', inicio));
        assert.ok(/\bdados\b/.test(corpo), 'a varredura tem de ACHAR o padrão quando ele existe');
    });

    await contexto.test('os `.map()` são exatamente estes — nenhum novo passa calado', () => {
        assert.equal([...FONTE_CARD.matchAll(/\.map\(/g)].length, 4, 'nasceu um .map() no card de ações');
        assert.equal([...FONTE_SAUDE.matchAll(/\.map\(/g)].length, 1, 'nasceu um .map() na Saúde');
        assert.equal([...FONTE_PUBS.matchAll(/\.map\(/g)].length, 3, 'nasceu um .map() em Publicações');
    });
});

test('⚠️ H — reuso: TileDoProduto, os helpers de texto e nada de hex', async (contexto) => {
    await contexto.test('o card de ações usa `TileDoProduto` nas duas pilhas', () => {
        assert.match(FONTE_CARD, /identidade\/TileDoProduto/);
        assert.equal([...FONTE_CARD.matchAll(/<TileDoProduto/g)].length, 2, 'a pilha e as iniciais do sub-item');
    });

    await contexto.test('`acoes.js` reusa `textoSeguro`/`textoUtil` de BarraDaConta (IDENT-08)', () => {
        assert.match(FONTE_ACOES, /import \{ textoSeguro, textoUtil \} from '\.\.\/BarraDaConta'/);
    });

    await contexto.test('TOK-15: nenhum hex literal nas 4 fontes', () => {
        for (const [caminho, fonte] of [[REL_ACOES, FONTE_ACOES], ...FONTES_JSX]) {
            assert.doesNotMatch(fonte, /#[0-9a-fA-F]{3,8}\b/, `${caminho}: hex literal`);
        }
    });

    await contexto.test('FID-03/FID-04: nenhuma escala nomeada, nenhum token `ecf-*`, nenhum breakpoint', () => {
        for (const [caminho, fonte] of [[REL_ACOES, FONTE_ACOES], ...FONTES_JSX]) {
            assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/, caminho);
            assert.doesNotMatch(fonte, /\becf-(bg|card|card-2|yellow|yellow-2|line|dim|mute)\b/, caminho);
            assert.doesNotMatch(fonte, /\bfont-display\b/, caminho);
            assert.doesNotMatch(fonte, /border-white\/\[0\.08\]/, caminho);
            assert.doesNotMatch(fonte, /\b(sm|md|lg|xl|2xl):/, caminho);
            assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/, caminho);
        }
    });

    await contexto.test('⚠️ os tamanhos de texto usados são só os do README (+ o 8.5 em dívida)', () => {
        const PERMITIDOS = new Set([8.5, 10, 10.5, 11, 11.5, 12, 12.5, 13, 13.5, 14, 14.5, 15, 16, 17, 20, 24, 28, 30, 32, 34]);
        for (const [caminho, fonte] of FONTES_JSX) {
            for (const achado of fonte.matchAll(/text-\[(\d+(?:\.\d+)?)px\]/g)) {
                assert.ok(PERMITIDOS.has(Number(achado[1])), `${caminho}: text-[${achado[1]}px] fora da lista`);
            }
        }
    });

    await contexto.test('T-179-SC: nenhum pacote novo — só `lucide-react`, que já era dependência', () => {
        const pacote = JSON.parse(fs.readFileSync(path.resolve(RAIZ, 'package.json'), 'utf8'));
        assert.ok('lucide-react' in pacote.dependencies);
        for (const [, fonte] of FONTES_JSX) {
            const imports = [...fonte.matchAll(/from '([^.@][^']*)'/g)].map((m) => m[1]);
            for (const pkg of imports) {
                assert.ok(
                    pkg in (pacote.dependencies ?? {}) || pkg in (pacote.devDependencies ?? {}),
                    `pacote não declarado: ${pkg}`,
                );
            }
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// I. O recado para o 179-09
// ═══════════════════════════════════════════════════════════════════════════

test('⚠️ o 179-09 precisa acrescentar os 4 arquivos novos a ARQUIVOS_NOVOS', async (contexto) => {
    const vocabulario = lerSemComentarios('tests/js/publicador-vocabulario-novo.test.js');
    const MEUS = [
        'visaogeral/acoes.js',
        'visaogeral/OQueFazerAgora.jsx',
        'visaogeral/SaudeDaConta.jsx',
        'visaogeral/PublicacoesNoMercadoLivre.jsx',
    ];

    await contexto.test('a dívida é VISÍVEL: hoje os 4 não estão na lista do gate de vocabulário', () => {
        // ⚠️ Este teste NÃO reprova (o dono daquele arquivo é o 179-09); existe
        // para que esquecer não passe por OMISSÃO, sem nada acusar.
        const faltando = MEUS.filter((caminho) => !vocabulario.includes(caminho));
        if (faltando.length > 0) {
            assert.equal(faltando.length, MEUS.length, 'entraram PELA METADE: ou os 4, ou nenhum');
        }
        assert.ok(true);
    });

    await contexto.test('⚠️ quando eles entrarem, `8.5` tem de estar em TAMANHOS_PERMITIDOS no MESMO commit', () => {
        const entraram = MEUS.every((caminho) => vocabulario.includes(caminho));
        if (entraram) {
            const naLista = /TAMANHOS_PERMITIDOS = new Set\(\[([^\]]*)\]/.exec(vocabulario);
            const tamanhos = naLista[1].split(',').map((t) => Number(t.trim()));
            assert.ok(tamanhos.includes(8.5), '8.5 precisa entrar junto com os arquivos');
        }
        assert.ok(true);
    });

    await contexto.test('⚠️ e `text-[8.5px]` É usado — por isso a dívida existe', () => {
        assert.match(FONTE_CARD, /text-\[8\.5px\]/);
        const naLista = /TAMANHOS_PERMITIDOS = new Set\(\[([^\]]*)\]/.exec(vocabulario);
        const tamanhos = naLista[1].split(',').map((t) => Number(t.trim()));
        if (!tamanhos.includes(8.5)) {
            assert.ok(
                !MEUS.some((caminho) => vocabulario.includes(caminho)),
                'os arquivos entraram na lista SEM o 8.5: o gate de vocabulário vai reprovar',
            );
        }
    });
});
