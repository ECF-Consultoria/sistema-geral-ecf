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
// Fase 180, plano 09 — a LINHA DE GRUPO da visão "Por produto", o RODAPÉ de
// 44px e os ESTADOS VAZIOS, com render REAL (esbuild + `react-dom/server`).
//
// Protótipo: `new_layout_publicador/Publicacoes v2.dc.html`. Linhas MEDIDAS com
// `awk`/`grep -n` no arquivo e confirmadas pela SEMÂNTICA — nunca copiadas da
// tabela do plano (⚠️ esta rodada achou SEIS referências deslocadas, as minhas
// próprias da primeira escrita, e as corrigiu por medição):
//   · linha de grupo → :173   · chevron       → :174   · tile      → :175
//   · coluna nome    → :176   · nome + tag    → :177   · SKU+est.  → :178
//   · bloco cobert.  → :180   · par de fase   → :182   · rótulo F1 → :183
//   · célula do mapa → :185   · "Criar combo" → :190   · seta      → :191
//   · contagem       → :194   · "Ver produto" → :195   · seta      → :196
//   · vazio          → :286   · título        → :287   · corpo     → :288
//   · botão do vazio → :289   · rodapé        → :293   · frase     → :294
//   · legenda        → :295   · as 4 teclas   → :296-:299
//   · `footerLabel`  → :644   · `resultSummary` (com singular) → :626
//
// ⚠️ REND-03: TODOS os bundles são montados AQUI NO TOPO, antes do primeiro
// `test()`. O `after()` do `node --test` dispara quando os testes registrados
// terminam e já matou um arquivo inteiro deste projeto SEM nenhum teste falhar
// (sintoma: 37 testes em vez de 94).
//
// ⚠️⚠️ TRÊS GRUPOS IRMÃOS IDÊNTICOS: `html.indexOf(<lista de classes>)` pegaria
// o irmão errado (foi assim que quatro gates do ERP mediram a linha do Mercado
// Livre). Aqui toda localização é por ÁRVORE com índices absolutos, e a
// identidade de cada grupo é confirmada pelo NOME DO PRODUTO, que é único por
// grupo nos fixtures. Nunca pela lista de classes.
//
// ⚠️ E a segunda armadilha da árvore: `tagsInternas(html, bloco, 'div')[0]`
// devolveria o PRÓPRIO bloco quando ele começa com `<div` — o plano 180-08 tinha
// 4 asserções conferindo o pai acreditando conferir o filho. Aqui `filhosDe()`
// pula a tag de abertura e conta PROFUNDIDADE.
//
// ⚠️ A pergunta obrigatória — *"o que precisaria estar errado no código para
// este gate falhar?"* — está respondida POR BLOCO, no comentário de cada um.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

const STUB_INERTIA = path.join(__dirname, `.pub-grupos-inertia-stub-${process.pid}.mjs`);
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
        `.pub-grupos-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_PUB = 'resources/js/Components/Mlb/Publicador/publicacoes';
const DIR_IDENT = 'resources/js/Components/Mlb/Publicador/identidade';
const REL_GRUPO = `${DIR_PUB}/LinhaDeGrupoDeProduto.jsx`;
const REL_RODAPE = `${DIR_PUB}/RodapeDaTabela.jsx`;
const REL_VAZIOS = `${DIR_PUB}/VaziosDaLista.jsx`;
const REL_MAPA = `${DIR_IDENT}/MapaDeCobertura.jsx`;
const REL_TILE = `${DIR_IDENT}/TileDoProduto.jsx`;

// ⚠️ `test -e` ANTES de acreditar em qualquer leitura vazia. CINCO caminhos
// inexistentes já "provaram" território intocado nesta milestone.
for (const caminho of [DIR_PUB, DIR_IDENT, REL_GRUPO, REL_RODAPE, REL_VAZIOS, REL_MAPA, REL_TILE]) {
    if (!fs.existsSync(path.resolve(RAIZ, caminho))) {
        throw new Error(`caminho inexistente: ${caminho} — gate mediria NADA`);
    }
}

const MOD_GRUPO = await montar(REL_GRUPO);
const MOD_RODAPE = await montar(REL_RODAPE);
const MOD_VAZIOS = await montar(REL_VAZIOS);

const Grupo = MOD_GRUPO.default;
const Rodape = MOD_RODAPE.default;
const Vazios = MOD_VAZIOS.default;

const FONTE_GRUPO = lerSemComentarios(REL_GRUPO);
const FONTE_RODAPE = lerSemComentarios(REL_RODAPE);
const FONTE_VAZIOS = lerSemComentarios(REL_VAZIOS);

const FONTES = [
    [REL_GRUPO, FONTE_GRUPO],
    [REL_RODAPE, FONTE_RODAPE],
    [REL_VAZIOS, FONTE_VAZIOS],
];

const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

// ─────────────────────────────────────────────────────────────────────────
// Localização por ÁRVORE com índices absolutos (o molde dos planos 180-06/07/08)
// ─────────────────────────────────────────────────────────────────────────

/** A tag de abertura inteira que começa em `pos`. */
function tagEm(html, pos) {
    assert.ok(pos !== -1 && pos < html.length, 'posição fora do HTML');
    const fim = html.indexOf('>', pos);
    assert.ok(fim !== -1, 'tag de abertura sem fechamento');
    return html.slice(pos, fim + 1);
}

/**
 * O BLOCO inteiro (abertura + conteúdo + fechamento) do elemento `nome` que
 * começa no índice absoluto `pos`, contando PROFUNDIDADE.
 *
 * ⚠️ Fatiar "do começo da tag até o próximo `</div>`" fecharia no FILHO: a linha
 * de grupo tem `<div>` dentro de `<div>` em dois lugares.
 */
function blocoEm(html, pos, nome) {
    assert.equal(html.slice(pos, pos + nome.length + 1), `<${nome}`, `o índice ${pos} não abre <${nome}>`);
    const re = new RegExp(`</?${nome}(?=[\\s/>])`, 'g');
    re.lastIndex = pos;
    let profundidade = 0;
    let m = re.exec(html);
    while (m !== null) {
        if (m[0][1] === '/') {
            profundidade -= 1;
            if (profundidade === 0) {
                const fim = html.indexOf('>', m.index);
                assert.ok(fim !== -1, 'fechamento sem `>`');
                return html.slice(pos, fim + 1);
            }
        } else {
            const fim = html.indexOf('>', m.index);
            assert.ok(fim !== -1, 'abertura sem `>`');
            if (html[fim - 1] !== '/') profundidade += 1;
        }
        m = re.exec(html);
    }
    throw new Error(`<${nome}> aberta em ${pos} nunca fecha`);
}

/**
 * Os FILHOS DIRETOS do elemento que começa em `pos`, em ordem, com índice
 * absoluto, nome e bloco.
 *
 * ⚠️ Pula a tag de abertura do próprio pai (é o furo que o 180-08 mediu) e anda
 * bloco a bloco, então wrapper interno não entra na lista como se fosse irmão.
 */
function filhosDe(html, pos, nomeDoPai) {
    const bloco = blocoEm(html, pos, nomeDoPai);
    const base = html.indexOf(bloco);
    assert.ok(base !== -1, 'o bloco não pertence a este HTML');
    const filhos = [];
    let i = 1; // pula o `<` da tag de abertura do pai
    while (i < bloco.length) {
        if (bloco[i] === '<' && bloco[i + 1] !== '/') {
            const nome = /^<([a-z]+)/.exec(bloco.slice(i))?.[1];
            if (nome === undefined) break;
            const filho = blocoEm(html, base + i, nome);
            filhos.push({ pos: base + i, nome, bloco: filho, tag: tagEm(html, base + i) });
            i += filho.length;
        } else {
            i += 1;
        }
    }
    return filhos;
}

/** Os índices absolutos de todas as tags de abertura `<nome` do HTML inteiro. */
function todasAsTags(html, nome) {
    return [...html.matchAll(new RegExp(`<${nome}(?=[\\s/>])`, 'g'))].map((m) => m.index);
}

/** As classes de uma tag de abertura, como LISTA (nunca substring). */
function classesDa(tag) {
    const m = tag.match(/class="([^"]*)"/);
    assert.ok(m, `tag sem class=: ${tag}`);
    return m[1].split(/\s+/).filter(Boolean);
}

/**
 * `true` quando a tag TEM a classe, como item da lista.
 *
 * ⚠️ Existe porque `classesDa()` ASSERTA a presença do atributo, e nem toda
 * `<div>` do HTML tem `class=` (o wrapper do `acao` no vazio, por exemplo). Usar
 * `classesDa` dentro de um `filter` fazia o gate reprovar o código CERTO — e
 * `tag.includes('classe')` não serve, porque substring casa com prefixo
 * (`h-[44px]` dentro de `h-[44px]x`) e com o FIM de outro nome.
 */
function temClasse(tag, classe) {
    const m = tag.match(/class="([^"]*)"/);
    if (m === null) return false;
    return m[1].split(/\s+/).filter(Boolean).includes(classe);
}

/**
 * Igualdade de CONJUNTO de classes.
 *
 * ⚠️ É este gate — e não o de CSS — que protege a fidelidade DESTES arquivos:
 * classe a mais reprova, classe a menos reprova, e o CSS do último build não
 * tem voto (a mesma classe pode ter outro dono no repositório).
 */
function assertClasses(tag, esperadas, onde) {
    assert.deepEqual(
        [...classesDa(tag)].sort(),
        [...esperadas].sort(),
        `${onde}: classes divergentes em ${tag}`,
    );
}

/** Um atributo de uma tag de abertura, ou `null`. */
function atributo(tag, nome) {
    const m = tag.match(new RegExp(`\\s${nome}="([^"]*)"`));
    return m ? m[1] : null;
}

/** O texto visível de um bloco (sem tags), colapsado. */
function textoDe(bloco) {
    return bloco.replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
}

/**
 * Descritor SEGURO para mensagem de asserção.
 *
 * ⚠️ `String({toString: null})` LANÇA e mataria o gate DENTRO da mensagem (foi o
 * que aconteceu com 5 blocos no plano 180-05); `JSON.stringify` MENTE
 * (`NaN`/`Infinity` → `'null'`). ⚠️ E `Object.keys` de um Proxy hostil também
 * pode lançar, por isso o `try`.
 */
function descreve(valor) {
    if (valor === null) return 'null';
    if (valor === undefined) return 'undefined';
    const tipo = typeof valor;
    if (tipo === 'number' || tipo === 'boolean' || tipo === 'bigint') return `${tipo}`;
    if (tipo === 'string') return `string(${valor.length} chars)`;
    if (tipo === 'function') return 'function';
    if (Array.isArray(valor)) return `array(${valor.length})`;
    try {
        return `object{${Object.keys(valor).join(',')}}`;
    } catch {
        return 'object(ilegível)';
    }
}

/** O detector de sujeira de render — a tela preta de 07/10/2026. */
const RE_SUJEIRA = /\[object Object\]|NaN|undefined|Invalid Date/;

// ⚠️ CODE POINTS, não glifos colados: um "·" que virasse "•" (U+2022) ou aspas
// retas no lugar das curvas passariam num gate escrito por cópia.
const PONTO_MEIO = String.fromCharCode(0x00b7); //  ·  MIDDLE DOT (protótipo :178)
const TRAVESSAO = String.fromCharCode(0x2014); //  —  EM DASH
const ASPA_ESQ = String.fromCharCode(0x201c); //  “  LEFT DOUBLE QUOTATION MARK
const ASPA_DIR = String.fromCharCode(0x201d); //  ”  RIGHT DOUBLE QUOTATION MARK
const SETA_CIMA = String.fromCharCode(0x2191); //  ↑
const SETA_BAIXO = String.fromCharCode(0x2193); //  ↓

// ─────────────────────────────────────────────────────────────────────────
// OS FIXTURES — as chaves são as do SERVIDOR, conferidas no controller
// (`MlbAnuncioController.php:1439`-`:1465` para o grupo de produto,
// `:1487`-`:1508` para o grupo legado e `:1022`-`:1026` para o rodapé).
// ─────────────────────────────────────────────────────────────────────────

/** Cobertura COERENTE com `sem_fase2: true`: F1 nos dois tipos, F2 em nenhum. */
const COBERTURA_SO_F1 = { f1_classico: true, f1_premium: true, f2_classico: false, f2_premium: false };

/** Um grupo CHEIO — nenhum campo nulo, para as negativas valerem algo. */
const GRUPO_CHEIO = {
    produto_id: 12,
    produto_nome: 'Fone Bluetooth XYZ',
    produto_sku: 'FON-001',
    estoque: 4,
    cobertura: COBERTURA_SO_F1,
    total_no_produto: 7,
    total_visivel: 2,
    sem_fase2: true,
    ver_produto_url: '/mlb/anuncios/publicador/editor/12',
    criar_combo_url: '/mlb/anuncios/publicador/produtos/9?filtro=publicados&fase=so_base',
    ml_item_ids: ['MLB1000000001', 'MLB1000000002'],
};

/** O grupo LEGADO, como o servidor o manda (`MlbAnuncioController::GRUPO_SEM_PRODUTO`). */
const GRUPO_LEGADO = {
    produto_id: null,
    produto_nome: 'Sem produto no Publicador',
    produto_sku: null,
    estoque: null,
    cobertura: { f1_classico: false, f1_premium: false, f2_classico: false, f2_premium: false },
    total_no_produto: 3,
    total_visivel: 3,
    sem_fase2: false,
    ver_produto_url: null,
    criar_combo_url: null,
    ml_item_ids: ['MLB9000000001'],
};

/** TRÊS grupos irmãos, com NOME ÚNICO cada um — é o que torna a busca honesta. */
const OS_TRES = [
    { ...GRUPO_CHEIO },
    {
        ...GRUPO_CHEIO,
        produto_id: 31,
        produto_nome: 'Caixa de Som Portatil ABC',
        produto_sku: 'CXA-031',
        estoque: 0,
        total_no_produto: 3,
        total_visivel: 3,
        sem_fase2: false,
        criar_combo_url: null,
    },
    {
        ...GRUPO_CHEIO,
        produto_id: 77,
        produto_nome: 'Smartwatch Serie 9 DEF',
        produto_sku: 'SMW-077',
        estoque: null,
        total_no_produto: 1,
        total_visivel: 1,
    },
];

function Tabela({ grupos }) {
    return React.createElement(
        'div',
        null,
        ...grupos.map((grupo, indice) => React.createElement(Grupo, {
            key: indice,
            grupo,
            aberto: false,
            primeiro: indice === 0,
            onAlternar: () => {},
        })),
    );
}

const HTML_TRES = renderToStaticMarkup(React.createElement(Tabela, { grupos: OS_TRES }));

/** Um grupo sozinho, com as props que o caso quiser. */
const umGrupo = (extra = {}) => render(Grupo, {
    grupo: GRUPO_CHEIO,
    aberto: false,
    primeiro: true,
    onAlternar: () => {},
    ...extra,
});

const HTML_UM = umGrupo();
const HTML_ABERTO = umGrupo({ aberto: true });
const HTML_LEGADO = umGrupo({ grupo: GRUPO_LEGADO });

const HTML_RODAPE = render(Rodape, {
    rodape: { visiveis: 50, total: 248, produtos: 9 },
    paginacao: React.createElement('nav', { 'aria-label': 'Paginação' }, '«'),
});
const HTML_RODAPE_NULO = render(Rodape, { rodape: null, paginacao: null });

const ACAO = React.createElement('button', { type: 'button' }, 'Atualizar agora');

const vazio = (extra = {}) => render(Vazios, { busca: null, skuNaoColetado: false, ...extra });

const HTML_NUNCA = vazio({ caso: 'nunca_coletado', acao: ACAO });
const HTML_SEM_ANUNCIOS = vazio({ caso: 'sem_anuncios' });
const HTML_SEM_RESULTADO = vazio({
    caso: 'sem_resultado',
    busca: 'fone bluetooth',
    skuNaoColetado: true,
    acao: ACAO,
    onLimparTudo: () => {},
});
const HTML_SEM_RESULTADO_SEM_SKU = vazio({ caso: 'sem_resultado', busca: 'fone', onLimparTudo: () => {} });
const HTML_VAZIO_NULO = vazio({ caso: null });

/** Acha o grupo cujo NOME é `nome` — por texto único, nunca por classe. */
function grupoDoNome(html, nome) {
    const posicoes = todasAsTags(html, 'div').filter((pos) => tagEm(html, pos).includes('role="button"'));
    const achados = posicoes.filter((pos) => blocoEm(html, pos, 'div').includes(`>${nome}<`));
    assert.equal(achados.length, 1, `esperava UM grupo com "${nome}", achei ${achados.length}`);
    return achados[0];
}

/** O índice absoluto da única linha de grupo de um HTML com um grupo só. */
function unicoGrupo(html) {
    const posicoes = todasAsTags(html, 'div').filter((pos) => tagEm(html, pos).includes('role="button"'));
    assert.equal(posicoes.length, 1, `esperava UMA linha de grupo, achei ${posicoes.length}`);
    return posicoes[0];
}

/**
 * Os filhos diretos da linha de grupo, por tipo — é o que permite assertar
 * AUSÊNCIA de um elemento sem depender de substring.
 */
function pecasDoGrupo(html, pos) {
    const filhos = filhosDe(html, pos, 'div');
    const bloco = blocoEm(html, pos, 'div');
    return {
        bloco,
        tag: tagEm(html, pos),
        filhos,
        chevron: filhos.filter((f) => f.nome === 'svg'),
        // O tile é o `<span aria-hidden>` com `style` de 38px (o `TileDoProduto`).
        tile: filhos.filter((f) => f.nome === 'span' && (atributo(f.tag, 'style') ?? '').includes('width:38px')),
        colunas: filhos.filter((f) => f.nome === 'div'),
        contagem: filhos.filter((f) => f.nome === 'span' && temClasse(f.tag, 'ml-auto')),
        links: filhos.filter((f) => f.nome === 'a'),
    };
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. OS LITERAIS, BYTE A BYTE — e por CODE POINT onde o glifo engana
//
// O que precisaria estar errado para falhar: a tag virar "Produto", a contagem
// perder o singular, o "·" virar "-" ou "•", as aspas curvas virarem retas, o
// "↑↓" virar a palavra "setas", qualquer um dos textos de vazio ser reescrito,
// ou o aviso de SKU ser ENCURTADO (é o que o critério 13 nomeia).
// ⚠️ Toda asserção é no NÓ localizado por árvore, não no HTML inteiro:
// "publicação" é substring de "publicações" e " de " é substring de quase tudo.
// ═══════════════════════════════════════════════════════════════════════════

test('FID — os literais da linha de grupo', async (contexto) => {
    const pecas = pecasDoGrupo(HTML_UM, unicoGrupo(HTML_UM));

    await contexto.test('a tag diz exatamente PRODUTO', () => {
        const coluna = pecas.colunas[0];
        const tags = filhosDe(HTML_UM, coluna.pos, 'div')
            .flatMap((filho) => filhosDe(HTML_UM, filho.pos, filho.nome))
            .filter((neto) => textoDe(neto.bloco) === 'PRODUTO');
        assert.equal(tags.length, 1, 'esperava UMA tag "PRODUTO" na coluna do nome');
        assert.equal(textoDe(tags[0].bloco), 'PRODUTO');
    });

    await contexto.test(`a linha do SKU é "SKU ${PONTO_MEIO} Estoque N", com MIDDLE DOT`, () => {
        const coluna = pecas.colunas[0];
        const linhas = filhosDe(HTML_UM, coluna.pos, 'div');
        assert.equal(linhas.length, 2, 'a coluna do nome tem 2 linhas: nome+tag e SKU+estoque');
        const texto = textoDe(linhas[1].bloco);
        assert.equal(texto, `FON-001 ${PONTO_MEIO} Estoque 4`);
        // ⚠️ O separador é U+00B7, medido no protótipo `:178`.
        assert.ok(texto.includes(PONTO_MEIO), 'o separador não é o MIDDLE DOT do protótipo');
        assert.ok(!texto.includes(String.fromCharCode(0x2022)), 'virou BULLET (U+2022)');
    });

    await contexto.test('a contagem diz "2 de 7 publicações"', () => {
        assert.equal(pecas.contagem.length, 1);
        assert.equal(textoDe(pecas.contagem[0].bloco), '2 de 7 publicações');
    });

    await contexto.test('o "Criar combo" e o "Ver produto" são os literais do protótipo', () => {
        assert.equal(pecas.links.length, 1, 'o link da direita é um só nos filhos diretos');
        assert.equal(textoDe(pecas.links[0].bloco), 'Ver produto');

        // O "Criar combo" é filho do BLOCO DA COBERTURA, não da linha.
        const cobertura = pecas.colunas[1];
        const combos = filhosDe(HTML_UM, cobertura.pos, 'div').filter((f) => f.nome === 'a');
        assert.equal(combos.length, 1);
        assert.equal(textoDe(combos[0].bloco), `Fase 2 não criada ${PONTO_MEIO} Criar combo`);
    });
});

test('FID — os literais do rodapé e dos vazios', async (contexto) => {
    await contexto.test('a frase do rodapé e as 4 teclas, com as SETAS por code point', () => {
        const filhos = filhosDe(HTML_RODAPE, unicoDoRodape(HTML_RODAPE), 'div');
        assert.equal(textoDe(filhos[0].bloco), `50 de 248 publicações ${PONTO_MEIO} 9 produtos`);

        const legenda = filhos[filhos.length - 1];
        const itens = filhosDe(HTML_RODAPE, legenda.pos, 'span');
        assert.equal(itens.length, 4, 'a legenda tem exatamente 4 atalhos');
        assert.deepEqual(itens.map((item) => textoDe(item.bloco)), [
            '/buscar',
            `${SETA_CIMA}${SETA_BAIXO}navegar`,
            'Enterdetalhes',
            'Escfechar',
        ]);
        // ⚠️ Ausência com dado PREENCHIDO: o HTML tem as 4 teclas e mesmo assim
        // a palavra "setas" não aparece em lugar nenhum.
        assert.doesNotMatch(HTML_RODAPE, /setas/i);
    });

    await contexto.test('o `nunca_coletado` é byte a byte o de hoje', () => {
        const textos = textosDoVazio(HTML_NUNCA);
        assert.equal(textos[0], 'Ainda não coletamos os anúncios desta empresa.');
        assert.equal(
            textos[1],
            'A primeira coleta roda automaticamente até amanhã, ou clique em Atualizar agora para adiantar.',
        );
        assert.ok(HTML_NUNCA.includes('Atualizar agora</button>'), 'o botão do `acao` não foi renderizado');
    });

    await contexto.test('o `sem_anuncios` mantém o título e corrige o CAMINHO do corpo', () => {
        const textos = textosDoVazio(HTML_SEM_ANUNCIOS);
        assert.equal(textos[0], 'Esta empresa não tem anúncios ativos no Mercado Livre.');
        assert.equal(
            textos[1],
            'Publique um anúncio na aba Produtos, ou troque a aba de status acima para ver as pausadas e as com erro.',
        );
        // ⚠️ As abas "Individual" e "Em massa" NÃO existem desde a Fase 164
        // (`header/AbasDoHeader.jsx:102`-`:106`): instrução para lugar
        // inexistente é EXMP-01. Esta negativa é o que impede o texto velho de
        // voltar por "melhoria" de copy.
        assert.doesNotMatch(HTML_SEM_ANUNCIOS, /Em massa/);
        assert.doesNotMatch(HTML_SEM_ANUNCIOS, /abas Individual/);
    });

    await contexto.test('o `sem_resultado` diz o TERMO com aspas CURVAS e os QUATRO identificadores', () => {
        const textos = textosDoVazio(HTML_SEM_RESULTADO);
        assert.equal(textos[0], `Nada encontrado para ${ASPA_ESQ}fone bluetooth${ASPA_DIR}.`);
        // ⚠️ Code point, não glifo: aspa reta passaria num gate escrito por cópia.
        assert.ok(textos[0].includes(ASPA_ESQ) && textos[0].includes(ASPA_DIR));
        assert.ok(!textos[0].includes('"'), 'as aspas viraram retas');
        assert.equal(
            textos[1],
            'A busca considera nome do produto, título do anúncio, SKU e código MLB.'
            + ' Tente outro termo ou remova filtros.',
        );
        // O texto VELHO dizia três identificadores; desde o plano 180-03 são quatro.
        assert.doesNotMatch(HTML_SEM_RESULTADO, /procura no título do anúncio, no código MLB e no SKU/);
    });

    await contexto.test('o aviso de SKU não coletado está INTEIRO (critério 13)', () => {
        const textos = textosDoVazio(HTML_SEM_RESULTADO);
        assert.equal(
            textos[2],
            'O SKU dos anúncios desta empresa ainda não foi coletado'
            + ` ${TRAVESSAO} por isso uma busca por SKU não acharia nada mesmo que o anúncio exista.`
            + ' Clique em Atualizar agora e busque de novo.',
        );
        // ⚠️ O aviso MANDA clicar em "Atualizar agora" — então o botão tem de
        // estar na tela (EXMP-01: nenhuma instrução sem destino).
        assert.ok(HTML_SEM_RESULTADO.includes('Atualizar agora</button>'));
    });

    await contexto.test('o título e o botão do protótipo', () => {
        const textos = textosDoVazio(HTML_SEM_RESULTADO_SEM_SKU);
        assert.ok(HTML_SEM_RESULTADO_SEM_SKU.includes('Limpar busca e filtros</button>'));
        assert.equal(textos.length, 2, 'sem `skuNaoColetado` o aviso não nasce');

        // Sem termo legível o título é o do protótipo (`:287`).
        const semTermo = textosDoVazio(vazio({ caso: 'sem_resultado', busca: '   ', onLimparTudo: () => {} }));
        assert.equal(semTermo[0], 'Nenhuma publicação encontrada');
    });
});

/** O índice absoluto da `<div>` do rodapé (é a raiz do componente). */
function unicoDoRodape(html) {
    const posicoes = todasAsTags(html, 'div').filter((pos) => temClasse(tagEm(html, pos), 'h-[44px]'));
    assert.equal(posicoes.length, 1, `esperava UM rodapé, achei ${posicoes.length}`);
    return posicoes[0];
}

/** Os textos dos filhos diretos `<span>` da caixa do vazio, em ordem. */
function textosDoVazio(html) {
    const posicoes = todasAsTags(html, 'div').filter((pos) => temClasse(tagEm(html, pos), 'py-[64px]'));
    assert.equal(posicoes.length, 1, `esperava UMA caixa de vazio, achei ${posicoes.length}`);
    return filhosDe(html, posicoes[0], 'div')
        .filter((filho) => filho.nome === 'span')
        .map((filho) => textoDe(filho.bloco));
}

// ═══════════════════════════════════════════════════════════════════════════
// 2. OS TRÊS GRUPOS IRMÃOS — e o `border-top` só a partir do segundo
//
// O que precisaria estar errado para falhar: o `primeiro` deixar de tirar o
// `border-t` (e aí a tabela ganharia uma linha dupla embaixo do cabeçalho), o
// `border-t` sumir dos outros dois, qualquer classe da linha mudar de valor
// (58px → 56px, pl-14 → pl-16) ou o `cursor-pointer` cair — ele já foi omitido
// NOVE vezes nesta fase.
// ═══════════════════════════════════════════════════════════════════════════

const CLASSES_DA_LINHA = [
    'flex', 'h-[58px]', 'cursor-pointer', 'items-center', 'gap-[14px]',
    'bg-pub-raised', 'pl-[14px]', 'pr-[16px]', 'hover:bg-pub-hover-3',
];

test('FID — a geometria da linha de grupo e os três irmãos', async (contexto) => {
    await contexto.test('os três grupos existem e cada um é achado pelo NOME', () => {
        const nomes = ['Fone Bluetooth XYZ', 'Caixa de Som Portatil ABC', 'Smartwatch Serie 9 DEF'];
        const posicoes = nomes.map((nome) => grupoDoNome(HTML_TRES, nome));
        assert.equal(new Set(posicoes).size, 3, 'dois nomes caíram no mesmo grupo');
        // A ORDEM do servidor é preservada: os índices crescem.
        assert.deepEqual([...posicoes].sort((a, b) => a - b), posicoes);
    });

    await contexto.test('o PRIMEIRO grupo não tem `border-t`; os outros dois têm', () => {
        const primeiro = grupoDoNome(HTML_TRES, 'Fone Bluetooth XYZ');
        assertClasses(tagEm(HTML_TRES, primeiro), CLASSES_DA_LINHA, 'o primeiro grupo');

        for (const nome of ['Caixa de Som Portatil ABC', 'Smartwatch Serie 9 DEF']) {
            assertClasses(
                tagEm(HTML_TRES, grupoDoNome(HTML_TRES, nome)),
                [...CLASSES_DA_LINHA, 'border-t', 'border-pub-line'],
                nome,
            );
        }
    });

    await contexto.test('as peças internas têm as classes medidas, uma a uma', () => {
        const pos = unicoGrupo(HTML_UM);
        const pecas = pecasDoGrupo(HTML_UM, pos);

        // chevron (`:174`)
        assertClasses(
            pecas.chevron[0].tag,
            ['flex-none', 'stroke-pub-txt-3', 'transition-transform', 'rotate-0'],
            'chevron',
        );
        // ⚠️ Os `.18s` do protótipo vêm por `style` porque `duration-[180ms]`
        // NÃO COMPILA neste projeto (o `tailwindcss-animate` torna `duration-*`
        // ambíguo e o Tailwind recusa o valor arbitrário). Ver o bloco
        // "a classe MORTA não volta", no gate de CSS.
        assert.match(atributo(pecas.chevron[0].tag, 'style'), /transition-duration:\s*180ms/);
        assert.equal(atributo(pecas.chevron[0].tag, 'viewBox'), '0 0 16 16');
        assert.equal(atributo(pecas.chevron[0].tag, 'stroke-width'), '1.8');
        assert.equal(atributo(pecas.chevron[0].tag, 'stroke-linecap'), 'round');
        assert.equal(atributo(pecas.chevron[0].tag, 'stroke-linejoin'), 'round');
        assert.equal(atributo(pecas.chevron[0].tag, 'fill'), 'none');
        assert.equal(atributo(pecas.chevron[0].tag, 'width'), '14');

        // tile (`:175`) — 38px e raio 9 vêm do `TileDoProduto`, por `style`
        assert.equal(pecas.tile.length, 1);
        const estilo = atributo(pecas.tile[0].tag, 'style');
        assert.match(estilo, /width:38px/);
        assert.match(estilo, /height:38px/);
        assert.match(estilo, /border-radius:9px/);
        assert.ok(classesDa(pecas.tile[0].tag).includes('-ml-[4px]'), 'o `margin-left:-4px` caiu');
        assert.ok(classesDa(pecas.tile[0].tag).includes('text-[12px]'), 'a letra de 12px do protótipo caiu');

        // coluna nome+sku (`:176`), nome (`:177`), tag e SKU (`:178`)
        const coluna = pecas.colunas[0];
        assertClasses(coluna.tag, ['flex', 'min-w-0', 'flex-col', 'gap-[2px]'], 'coluna nome+sku');
        const linhas = filhosDe(HTML_UM, coluna.pos, 'div');
        assertClasses(
            linhas[0].tag,
            ['flex', 'items-center', 'gap-[8px]', 'text-[14px]', 'font-semibold', 'tracking-[-0.01em]'],
            'nome do produto',
        );
        const tagProduto = filhosDe(HTML_UM, linhas[0].pos, 'span')[0];
        assertClasses(
            tagProduto.tag,
            [
                'rounded-pub-badge', 'border', 'border-pub-line', 'px-[6px]', 'py-[2px]',
                'text-[10.5px]', 'font-semibold', 'tracking-[0.06em]', 'text-pub-txt-3',
            ],
            'tag PRODUTO',
        );
        assertClasses(linhas[1].tag, ['text-[12px]', 'text-pub-txt-3'], 'linha do SKU');
        assertClasses(
            filhosDe(HTML_UM, linhas[1].pos, 'span')[0].tag,
            ['font-pub-mono', 'text-[11.5px]'],
            'SKU em mono',
        );

        // bloco da cobertura (`:180`)
        assertClasses(
            pecas.colunas[1].tag,
            ['ml-[22px]', 'flex', 'h-[30px]', 'items-center', 'gap-[14px]', 'border-l', 'border-pub-line', 'pl-[22px]'],
            'bloco da cobertura',
        );

        // contagem (`:194`)
        assertClasses(pecas.contagem[0].tag, ['ml-auto', 'text-[12.5px]', 'text-pub-neutral-txt'], 'contagem');

        // "Ver produto" (`:195`)
        assertClasses(
            pecas.links[0].tag,
            [
                'flex', 'items-center', 'gap-[5px]', 'rounded-pub-control-sm', 'border', 'border-pub-line',
                'bg-pub-surface', 'px-[10px]', 'py-[6px]', 'text-[12.5px]', 'font-medium',
            ],
            'Ver produto',
        );

        // "Criar combo" (`:190`)
        const combo = filhosDe(HTML_UM, pecas.colunas[1].pos, 'div').find((f) => f.nome === 'a');
        assertClasses(
            combo.tag,
            [
                'flex', 'items-center', 'gap-[5px]', 'rounded-pub-chip', 'bg-pub-accent/[0.12]',
                'px-[9px]', 'py-[4px]', 'text-[12px]', 'font-medium', 'text-pub-accent',
            ],
            'Criar combo',
        );

        // a seta de 11px dos dois links (`:191`/`:196`)
        for (const link of [combo, pecas.links[0]]) {
            const seta = filhosDe(HTML_UM, link.pos, 'a').find((f) => f.nome === 'svg');
            assert.equal(atributo(seta.tag, 'width'), '11');
            assert.equal(atributo(seta.tag, 'viewBox'), '0 0 16 16');
            assert.equal(atributo(seta.tag, 'stroke'), 'currentColor');
            assert.equal(atributo(seta.tag, 'stroke-width'), '1.8');
            assert.equal(atributo(seta.tag, 'fill'), 'none');
        }
    });

    await contexto.test('o rodapé, a caixa do vazio e o botão têm as classes medidas', () => {
        assertClasses(
            tagEm(HTML_RODAPE, unicoDoRodape(HTML_RODAPE)),
            [
                'flex', 'h-[44px]', 'items-center', 'justify-between', 'border-t', 'border-pub-line',
                'bg-pub-sunken', 'px-[16px]', 'text-[12px]', 'text-pub-txt-3',
            ],
            'rodapé',
        );

        const filhos = filhosDe(HTML_RODAPE, unicoDoRodape(HTML_RODAPE), 'div');
        const legenda = filhos[filhos.length - 1];
        assertClasses(legenda.tag, ['flex', 'items-center', 'gap-[14px]'], 'legenda de atalhos');
        const itens = filhosDe(HTML_RODAPE, legenda.pos, 'span');
        assertClasses(itens[0].tag, ['flex', 'items-center', 'gap-[5px]'], 'item da legenda');
        assertClasses(
            filhosDe(HTML_RODAPE, itens[0].pos, 'span')[0].tag,
            [
                'font-pub-mono', 'rounded-pub-badge', 'border', 'border-pub-line-strong',
                'bg-pub-surface', 'px-[6px]', 'py-[1px]', 'text-pub-neutral-txt',
            ],
            'tecla',
        );

        const caixa = todasAsTags(HTML_SEM_RESULTADO, 'div')
            .filter((pos) => temClasse(tagEm(HTML_SEM_RESULTADO, pos), 'py-[64px]'))[0];
        assertClasses(
            tagEm(HTML_SEM_RESULTADO, caixa),
            [
                'flex', 'flex-col', 'items-center', 'gap-[8px]', 'border-t', 'border-pub-line-subtle',
                'px-[24px]', 'py-[64px]', 'text-center',
            ],
            'caixa do vazio',
        );
        const dentro = filhosDe(HTML_SEM_RESULTADO, caixa, 'div');
        assertClasses(dentro[0].tag, ['text-[15px]', 'font-semibold'], 'título do vazio');
        assertClasses(
            dentro[1].tag,
            ['max-w-[380px]', 'text-[13px]', 'text-pub-neutral-txt', '[text-wrap:pretty]'],
            'explicação do vazio',
        );
        assertClasses(
            dentro[2].tag,
            ['max-w-[380px]', 'text-[13px]', 'text-pub-warn-txt', '[text-wrap:pretty]'],
            'aviso de SKU',
        );
        const botao = dentro.find((f) => f.nome === 'button');
        assertClasses(
            botao.tag,
            [
                'mt-[6px]', 'h-[32px]', 'cursor-pointer', 'rounded-[8px]', 'border', 'border-pub-line-strong',
                'bg-pub-surface', 'px-[14px]', 'text-[12.5px]',
            ],
            'botão do vazio',
        );
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. O CHEVRON E O `aria-expanded`
//
// O que precisaria estar errado para falhar: o chevron deixar de girar (e aí
// aberto e fechado ficariam iguais), o `rotate` ir para o lado errado, ou o
// `aria-expanded` não acompanhar — quem usa leitor de tela não vê a seta.
// ⚠️ `rotate-90` casaria DENTRO de `rotate-900` se existisse: a asserção é por
// conjunto de classes, não por substring.
// ═══════════════════════════════════════════════════════════════════════════

test('FID — o chevron gira e o `aria-expanded` acompanha', async (contexto) => {
    await contexto.test('fechado: `rotate-0` e `aria-expanded="false"`', () => {
        const pos = unicoGrupo(HTML_UM);
        assert.equal(atributo(tagEm(HTML_UM, pos), 'aria-expanded'), 'false');
        const classes = classesDa(pecasDoGrupo(HTML_UM, pos).chevron[0].tag);
        assert.ok(classes.includes('rotate-0'));
        assert.ok(!classes.includes('rotate-90'));
    });

    await contexto.test('aberto: `rotate-90` e `aria-expanded="true"`', () => {
        const pos = unicoGrupo(HTML_ABERTO);
        assert.equal(atributo(tagEm(HTML_ABERTO, pos), 'aria-expanded'), 'true');
        const classes = classesDa(pecasDoGrupo(HTML_ABERTO, pos).chevron[0].tag);
        assert.ok(classes.includes('rotate-90'));
        assert.ok(!classes.includes('rotate-0'));
    });

    await contexto.test('a linha é alcançável por teclado e tem nome acessível', () => {
        const tag = tagEm(HTML_UM, unicoGrupo(HTML_UM));
        assert.equal(atributo(tag, 'role'), 'button');
        assert.equal(atributo(tag, 'tabindex'), '0');
        assert.equal(atributo(tag, 'aria-label'), 'Fone Bluetooth XYZ — 2 de 7 publicações');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. "NUNCA MEDIMOS" ≠ "MEDIMOS E É ZERO" — o estoque
//
// O que precisaria estar errado para falhar: `estoque: null` passar a escrever
// "Estoque 0" (que é um zero INVENTADO), ou `estoque: 0` deixar de aparecer
// (que seria esconder uma medição real).
// ⚠️ Os dois casos rodam com o RESTO DO GRUPO CHEIO: asserção de ausência com
// fixture vazio é vazia por construção.
// ═══════════════════════════════════════════════════════════════════════════

test('`estoque` nulo é travessão; zero é zero', async (contexto) => {
    await contexto.test('`estoque: null` → "Estoque —", nunca "Estoque 0"', () => {
        const html = umGrupo({ grupo: { ...GRUPO_CHEIO, estoque: null } });
        const coluna = pecasDoGrupo(html, unicoGrupo(html)).colunas[0];
        const texto = textoDe(filhosDe(html, coluna.pos, 'div')[1].bloco);
        assert.equal(texto, `FON-001 ${PONTO_MEIO} Estoque ${TRAVESSAO}`);
        assert.doesNotMatch(texto, /Estoque 0/);
    });

    await contexto.test('`estoque: 0` → "Estoque 0"', () => {
        const html = umGrupo({ grupo: { ...GRUPO_CHEIO, estoque: 0 } });
        const coluna = pecasDoGrupo(html, unicoGrupo(html)).colunas[0];
        const texto = textoDe(filhosDe(html, coluna.pos, 'div')[1].bloco);
        assert.equal(texto, `FON-001 ${PONTO_MEIO} Estoque 0`);
        assert.ok(!texto.includes(TRAVESSAO), 'zero medido virou travessão');
    });

    await contexto.test('`estoque` que não é número não inventa valor', () => {
        for (const valor of ['12', { n: 1 }, NaN, Infinity]) {
            const html = umGrupo({ grupo: { ...GRUPO_CHEIO, estoque: valor } });
            const coluna = pecasDoGrupo(html, unicoGrupo(html)).colunas[0];
            const texto = textoDe(filhosDe(html, coluna.pos, 'div')[1].bloco);
            assert.equal(texto, `FON-001 ${PONTO_MEIO} Estoque ${TRAVESSAO}`, `estoque ${descreve(valor)}`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. A CONTAGEM — "x de N" só quando filtrado, e nada quando não foi medida
//
// O que precisaria estar errado para falhar: o "x de N" virar sempre "N" (e aí
// a tela mentiria sobre quantas publicações o produto tem fora do filtro), o
// singular cair, ou `NaN`/string virarem número — `typeof NaN === 'number'` é a
// armadilha que escreveria "NaN publicações".
// ═══════════════════════════════════════════════════════════════════════════

test('a contagem do grupo', async (contexto) => {
    const contagemDe = (grupo) => {
        const html = umGrupo({ grupo });
        const pecas = pecasDoGrupo(html, unicoGrupo(html));
        return pecas.contagem.length === 0 ? null : textoDe(pecas.contagem[0].bloco);
    };

    await contexto.test('3 de 3 → "3 publicações"', () => {
        assert.equal(contagemDe({ ...GRUPO_CHEIO, total_visivel: 3, total_no_produto: 3 }), '3 publicações');
    });

    await contexto.test('1 de 1 → "1 publicação" (singular)', () => {
        assert.equal(contagemDe({ ...GRUPO_CHEIO, total_visivel: 1, total_no_produto: 1 }), '1 publicação');
    });

    await contexto.test('2 de 7 → "2 de 7 publicações"', () => {
        assert.equal(contagemDe({ ...GRUPO_CHEIO, total_visivel: 2, total_no_produto: 7 }), '2 de 7 publicações');
    });

    await contexto.test('1 de 7 → "1 de 7 publicações" (o plural segue o ÚLTIMO número)', () => {
        assert.equal(contagemDe({ ...GRUPO_CHEIO, total_visivel: 1, total_no_produto: 7 }), '1 de 7 publicações');
    });

    await contexto.test('`NaN`, string ou ausente → a contagem NÃO aparece', () => {
        assert.equal(contagemDe({ ...GRUPO_CHEIO, total_visivel: NaN }), null);
        assert.equal(contagemDe({ ...GRUPO_CHEIO, total_no_produto: NaN }), null);
        assert.equal(contagemDe({ ...GRUPO_CHEIO, total_visivel: '2' }), null);
        const { total_no_produto: _, ...semTotal } = GRUPO_CHEIO;
        assert.equal(contagemDe(semTotal), null);
    });

    await contexto.test('sem contagem, quem empurra o bloco da direita é o "Ver produto"', () => {
        const html = umGrupo({ grupo: { ...GRUPO_CHEIO, total_visivel: NaN } });
        const pecas = pecasDoGrupo(html, unicoGrupo(html));
        assert.equal(pecas.contagem.length, 0);
        assert.ok(classesDa(pecas.links[0].tag).includes('ml-auto'), 'o botão não recebeu o `ml-auto`');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. EXMP-01 — NENHUM LINK SEM DESTINO, e o `E` do "Criar combo"
//
// O que precisaria estar errado para falhar: um `href="#"` nascer, o
// `ver_produto_url` nulo virar botão morto, ou o "Criar combo" ser oferecido a
// quem já tem Fase 2 (ou a quem não tem destino, que é o caso REAL do servidor
// quando a conta não resolve).
// ⚠️ Todos os casos rodam com o grupo CHEIO em volta.
// ═══════════════════════════════════════════════════════════════════════════

test('EXMP-01 — os dois links só existem com destino', async (contexto) => {
    const linksDe = (grupo) => {
        const html = umGrupo({ grupo });
        const pos = unicoGrupo(html);
        const pecas = pecasDoGrupo(html, pos);
        const combo = filhosDe(html, pecas.colunas[1].pos, 'div').filter((f) => f.nome === 'a');
        return {
            html,
            verProduto: pecas.links.map((l) => atributo(l.tag, 'href')),
            combo: combo.map((l) => atributo(l.tag, 'href')),
        };
    };

    await contexto.test('`ver_produto_url` nulo → o link NÃO é desenhado', () => {
        const r = linksDe({ ...GRUPO_CHEIO, ver_produto_url: null });
        assert.deepEqual(r.verProduto, []);
        assert.doesNotMatch(r.html, /Ver produto/);
        // E o combo continua lá: a ausência é só do que perdeu destino.
        assert.equal(r.combo.length, 1);
    });

    await contexto.test('`ver_produto_url` que não é string → o link NÃO é desenhado', () => {
        for (const valor of [7, { url: '/x' }, ['/x'], '   ']) {
            const r = linksDe({ ...GRUPO_CHEIO, ver_produto_url: valor });
            assert.deepEqual(r.verProduto, [], `ver_produto_url ${descreve(valor)}`);
        }
    });

    await contexto.test('`sem_fase2: true` + url nula → SEM link (é o caso do servidor)', () => {
        const r = linksDe({ ...GRUPO_CHEIO, sem_fase2: true, criar_combo_url: null });
        assert.deepEqual(r.combo, []);
        assert.doesNotMatch(r.html, /Criar combo/);
    });

    await contexto.test('`sem_fase2: false` + url válida → SEM link', () => {
        const r = linksDe({ ...GRUPO_CHEIO, sem_fase2: false, criar_combo_url: '/produtos?fase=so_base' });
        assert.deepEqual(r.combo, []);
        assert.doesNotMatch(r.html, /Criar combo/);
        // ⚠️ Ausência com dado preenchido: o resto do grupo está inteiro.
        assert.match(r.html, /Ver produto/);
    });

    await contexto.test('`sem_fase2: true` + url válida → COM link, e para o destino certo', () => {
        const r = linksDe({ ...GRUPO_CHEIO, sem_fase2: true, criar_combo_url: '/produtos?fase=so_base' });
        assert.deepEqual(r.combo, ['/produtos?fase=so_base']);
    });

    await contexto.test('nenhum `href="#"` em nenhum dos HTMLs', () => {
        for (const html of [HTML_TRES, HTML_UM, HTML_LEGADO, HTML_RODAPE, HTML_SEM_RESULTADO]) {
            assert.doesNotMatch(html, /href="#"/);
        }
    });

    await contexto.test('a limitação do "Criar combo" é DITA no nome acessível', () => {
        // ⚠️ O destino é real mas NÃO pré-seleciona o produto (medido pelo plano
        // 180-04). O texto visível é o do protótipo; a ressalva vai no
        // `aria-label`/`title`, que é onde cabe sem quebrar a fidelidade.
        const pecas = pecasDoGrupo(HTML_UM, unicoGrupo(HTML_UM));
        const combo = filhosDe(HTML_UM, pecas.colunas[1].pos, 'div').find((f) => f.nome === 'a');
        const rotulo = atributo(combo.tag, 'aria-label');
        assert.match(rotulo, /não vem pré-selecionado/);
        assert.equal(atributo(combo.tag, 'title'), rotulo, 'IDENT-07: `title` e `aria-label` com o MESMO texto');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. O GRUPO LEGADO — nada que afirme produto nasce nele
//
// O que precisaria estar errado para falhar: o tile desenhar as iniciais de um
// nome que não é produto, a tag "PRODUTO" aparecer, o SKU/Estoque nascer vazio,
// ou um dos dois links ser oferecido para um grupo que não tem produto.
// ═══════════════════════════════════════════════════════════════════════════

test('o grupo legado (`produto_id: null`)', async (contexto) => {
    const pos = unicoGrupo(HTML_LEGADO);
    const pecas = pecasDoGrupo(HTML_LEGADO, pos);

    await contexto.test('sem tile, sem tag PRODUTO, sem SKU e sem os dois links', () => {
        assert.equal(pecas.tile.length, 0, 'o tile de iniciais nasceu num grupo que não é produto');
        assert.equal(pecas.links.length, 0);
        assert.doesNotMatch(HTML_LEGADO, /PRODUTO/);
        assert.doesNotMatch(HTML_LEGADO, /Ver produto/);
        assert.doesNotMatch(HTML_LEGADO, /Criar combo/);
        assert.doesNotMatch(HTML_LEGADO, /Estoque/);
        assert.doesNotMatch(HTML_LEGADO, /font-pub-mono/);
    });

    await contexto.test('o nome é o do servidor e a contagem continua existindo', () => {
        const coluna = pecas.colunas[0];
        const linhas = filhosDe(HTML_LEGADO, coluna.pos, 'div');
        assert.equal(linhas.length, 1, 'sem SKU e sem estoque, a segunda linha não nasce');
        assert.equal(textoDe(linhas[0].bloco), 'Sem produto no Publicador');
        assert.equal(textoDe(pecas.contagem[0].bloco), '3 publicações');
    });

    await contexto.test('a cobertura continua desenhada, com as 4 células apagadas', () => {
        // É a VERDADE MEDIDA do servidor ("sem produto não há fase nem tipo que
        // cobrir"), não "não sabemos" — `MlbAnuncioController.php:1493`-`:1496`.
        const mapa = filhosDe(HTML_LEGADO, pecas.colunas[1].pos, 'div')
            .find((f) => atributo(f.tag, 'role') === 'group');
        assert.ok(mapa, 'o mapa de cobertura sumiu do grupo legado');
        const celulas = filhosDe(HTML_LEGADO, mapa.pos, 'div');
        assert.equal(celulas.length, 4);
        for (const celula of celulas) {
            assert.match(atributo(celula.tag, 'aria-label'), /não criada/);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. O RODAPÉ — cada pedaço só com número medido, e a paginação INTACTA
//
// O que precisaria estar errado para falhar: a frase escrever "— de —", um
// pedaço sobrar com separador solto, o plural cair, o `rodape: null` desenhar
// uma frase com buracos, ou a paginação (que é a de HOJE e FICA, critério 13)
// deixar de ser renderizada.
// ═══════════════════════════════════════════════════════════════════════════

test('o rodapé e a paginação de 50', async (contexto) => {
    const frase = (rodape) => {
        const html = render(Rodape, { rodape, paginacao: null });
        return textoDe(filhosDe(html, unicoDoRodape(html), 'div')[0].bloco);
    };

    await contexto.test('a frase completa, com plural nos dois', () => {
        assert.equal(frase({ visiveis: 50, total: 248, produtos: 9 }), `50 de 248 publicações ${PONTO_MEIO} 9 produtos`);
        assert.equal(frase({ visiveis: 1, total: 1, produtos: 1 }), `1 de 1 publicação ${PONTO_MEIO} 1 produto`);
    });

    await contexto.test('pedaço não medido NÃO vira travessão nem separador solto', () => {
        assert.equal(frase({ visiveis: 50, total: 248, produtos: null }), '50 de 248 publicações');
        assert.equal(frase({ visiveis: null, total: 248, produtos: 9 }), `248 publicações ${PONTO_MEIO} 9 produtos`);
        assert.equal(frase({ visiveis: 50, total: NaN, produtos: 9 }), '9 produtos');
        assert.equal(frase({ visiveis: null, total: null, produtos: null }), '');
        assert.equal(frase(null), '');
        for (const valor of [{}, [], 'x', 7]) {
            assert.equal(frase(valor), '', `rodape ${descreve(valor)}`);
        }
    });

    await contexto.test('`rodape: null` mostra SÓ a legenda, e ela está inteira', () => {
        const filhos = filhosDe(HTML_RODAPE_NULO, unicoDoRodape(HTML_RODAPE_NULO), 'div');
        assert.equal(textoDe(filhos[0].bloco), '');
        assert.equal(filhosDe(HTML_RODAPE_NULO, filhos[filhos.length - 1].pos, 'span').length, 4);
        assert.doesNotMatch(HTML_RODAPE_NULO, new RegExp(TRAVESSAO));
        assert.doesNotMatch(HTML_RODAPE_NULO, /publicações/);
    });

    await contexto.test('a paginação chega como NÓ e é renderizada como veio', () => {
        assert.match(HTML_RODAPE, /<nav aria-label="Paginação">«<\/nav>/);
        // ⚠️ Nó que não é elemento NÃO é renderizado — `{objeto}` derrubaria a
        // página inteira com "Objects are not valid as a React child".
        for (const valor of [{ a: 1 }, 7, true, '', []]) {
            const html = render(Rodape, { rodape: null, paginacao: valor });
            assert.doesNotMatch(html, RE_SUJEIRA, `paginacao ${descreve(valor)}`);
        }
        const comLista = render(Rodape, {
            rodape: null,
            paginacao: [React.createElement('a', { key: 1, href: '/p/2' }, '2')],
        });
        assert.match(comLista, /href="\/p\/2"/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 9. OS QUATRO ESTADOS VAZIOS — e o aviso de SKU só onde ele é verdade
//
// O que precisaria estar errado para falhar: `caso: null` desenhar uma caixa
// vazia de 64px, um caso desconhecido virar texto, o aviso de SKU aparecer no
// `sem_anuncios` (onde não houve busca por SKU nenhuma) ou o botão "Limpar
// busca e filtros" nascer sem `onLimparTudo` (botão que não faz nada).
// ═══════════════════════════════════════════════════════════════════════════

test('os quatro estados vazios', async (contexto) => {
    await contexto.test('`caso: null` e caso desconhecido → HTML VAZIO', () => {
        assert.equal(HTML_VAZIO_NULO, '');
        for (const caso of [undefined, 'xpto', 7, {}, [], { toString: null }]) {
            assert.equal(vazio({ caso }), '', `caso ${descreve(caso)}`);
        }
    });

    await contexto.test('o aviso de SKU NÃO aparece no `sem_anuncios`', () => {
        const html = vazio({ caso: 'sem_anuncios', skuNaoColetado: true, busca: 'fone', onLimparTudo: () => {} });
        assert.doesNotMatch(html, /O SKU dos anúncios/);
        // E o `sem_resultado` com o MESMO `skuNaoColetado` mostra: a negativa
        // acima não é vazia por construção.
        assert.match(HTML_SEM_RESULTADO, /O SKU dos anúncios/);
    });

    await contexto.test('o aviso de SKU NÃO aparece sem busca legível', () => {
        const html = vazio({ caso: 'sem_resultado', busca: { toString: null }, skuNaoColetado: true });
        assert.doesNotMatch(html, /O SKU dos anúncios/);
        assert.doesNotMatch(html, RE_SUJEIRA);
        assert.doesNotMatch(html, new RegExp(ASPA_ESQ));
    });

    await contexto.test('o botão "Limpar" só nasce com `onLimparTudo`, e só no `sem_resultado`', () => {
        assert.doesNotMatch(vazio({ caso: 'sem_resultado', busca: 'fone' }), /Limpar busca e filtros/);
        assert.doesNotMatch(
            vazio({ caso: 'sem_anuncios', onLimparTudo: () => {} }),
            /Limpar busca e filtros/,
        );
        assert.doesNotMatch(
            vazio({ caso: 'nunca_coletado', onLimparTudo: () => {} }),
            /Limpar busca e filtros/,
        );
        assert.match(HTML_SEM_RESULTADO, /Limpar busca e filtros/);
    });

    await contexto.test('`acao` ausente → sem botão; `acao` inválido → sem sujeira', () => {
        assert.doesNotMatch(vazio({ caso: 'nunca_coletado' }), /<button/);
        for (const valor of [{ a: 1 }, 7, true, '']) {
            const html = vazio({ caso: 'nunca_coletado', acao: valor });
            assert.doesNotMatch(html, RE_SUJEIRA, `acao ${descreve(valor)}`);
            assert.doesNotMatch(html, /<button/, `acao ${descreve(valor)} virou botão`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 10. O REUSO — a identidade é compartilhada, não copiada (IDENT-08)
//
// O que precisaria estar errado para falhar: alguém redesenhar as 4 células ou
// o tile dentro deste plano (duas casas para a mesma verdade), copiar o
// `oklch()` do `identidade.js`, ou usar `dangerouslySetInnerHTML` para os
// rótulos do paginator (T-134-20).
// ═══════════════════════════════════════════════════════════════════════════

test('IDENT-08 / T-134-20 — reuso e nada de HTML cru', async (contexto) => {
    await contexto.test('`MapaDeCobertura` e `TileDoProduto` são IMPORTADOS', () => {
        assert.match(FONTE_GRUPO, /from '\.\.\/identidade\/MapaDeCobertura'/);
        assert.match(FONTE_GRUPO, /from '\.\.\/identidade\/TileDoProduto'/);
        assert.match(FONTE_GRUPO, /<MapaDeCobertura/);
        assert.match(FONTE_GRUPO, /<TileDoProduto/);
    });

    await contexto.test('nenhum dos 3 arquivos copia `oklch(` nem a letra do tile', () => {
        for (const [caminho, fonte] of FONTES) {
            assert.doesNotMatch(fonte, /oklch\(/, caminho);
            assert.doesNotMatch(fonte, /h-\[18px\] w-\[15px\]/, caminho);
            assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/, caminho);
            assert.doesNotMatch(fonte, /#[0-9a-fA-F]{3,8}\b/, `${caminho}: hex literal (TOK-15)`);
            assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/, `${caminho}: escala nomeada (FID-03)`);
            assert.doesNotMatch(fonte, /\becf-(bg|card|card-2|yellow|yellow-2|line|dim|mute)\b/, `${caminho}: FID-04`);
        }
    });

    await contexto.test('D-11: zero `router.*` e zero `new Date()` nos 3 arquivos', () => {
        for (const [caminho, fonte] of FONTES) {
            assert.doesNotMatch(fonte, /router\.(get|post|put|patch|delete|reload|visit)/, caminho);
            assert.doesNotMatch(fonte, /@inertiajs\/react/, caminho);
            assert.doesNotMatch(fonte, /new Date\(|Date\.now\(/, caminho);
            // ⚠️ `String(valor)`/`JSON.stringify` em valor do servidor LANÇAM
            // com `{toString: null}` — a tela preta de 07/10/2026.
            assert.doesNotMatch(fonte, /\bString\(/, caminho);
            assert.doesNotMatch(fonte, /JSON\.stringify/, caminho);
        }
    });

    await contexto.test('a string do GRID não é redeclarada aqui (ela mora no CabecalhoDaTabela)', () => {
        // ⚠️ A linha de grupo do protótipo (`:173`) é FLEX, não o grid de 9
        // colunas — então ela não importa `GRID_DA_TABELA` E TAMBÉM não pode
        // redeclarar o literal: duas casas para a mesma string é o bug clássico
        // de tabela em grid.
        for (const [caminho, fonte] of FONTES) {
            assert.doesNotMatch(fonte, /grid-cols-\[/, `${caminho}: literal do grid redeclarado`);
            assert.doesNotMatch(fonte, /minmax\(300px/, `${caminho}: literal do grid redeclarado`);
        }
        assert.match(FONTE_GRUPO, /flex h-\[58px\]/, 'a linha de grupo deixou de ser `flex` de 58px');
    });

    await contexto.test('REND-04: toda flag do `.map()` nasce DENTRO do callback', () => {
        // ⚠️ A eliminação de variável pelo Rollup NÃO é reproduzível em
        // `renderToStaticMarkup` — este gate é ESTRUTURAL, e a lacuna está
        // declarada na SUMMARY. O único `.map()` destes 3 arquivos é o das
        // teclas do rodapé.
        const mapas = [...FONTE_RODAPE.matchAll(/\.map\(\(([^)]*)\) => \{/g)];
        assert.equal(mapas.length, 1, 'mudou o número de `.map()` com corpo de bloco no rodapé');
        assert.doesNotMatch(FONTE_GRUPO, /\.map\(/, 'a linha de grupo não mapeia nada (o mapa é do MapaDeCobertura)');
        assert.doesNotMatch(FONTE_VAZIOS, /\.map\(/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 11. RENDER ADVERSO — a tela preta de 07/10/2026
//
// O que precisaria estar errado para falhar: qualquer campo do servidor chegando
// objeto/`NaN`/nulo/ausente e sendo interpolado como texto; `cobertura` com
// chave errada; `grupo` nulo (que DERRUBOU O PAINEL INTEIRO na Fase 178 e só o
// render adverso pegou); ou indexação com chave `{toString: null}`, que COAGE e
// LANÇA.
// ⚠️ A mensagem de asserção nunca usa `String(valor)` nem `JSON.stringify`.
// ═══════════════════════════════════════════════════════════════════════════

const VALORES_HOSTIS = [
    null,
    undefined,
    { toString: null },
    { nested: { a: 1 } },
    [1, 2, 3],
    NaN,
    Infinity,
    -0,
    '',
    '   ',
    0,
    false,
    () => {},
];

test('REND-01 — render adverso completo', async (contexto) => {
    await contexto.test('`grupo` inteiro hostil não derruba a tela', () => {
        for (const valor of [...VALORES_HOSTIS, 'texto', 7]) {
            let html = '';
            assert.doesNotThrow(() => {
                html = umGrupo({ grupo: valor });
            }, `grupo ${descreve(valor)} lançou`);
            assert.doesNotMatch(html, RE_SUJEIRA, `grupo ${descreve(valor)} sujou a tela`);
        }
    });

    await contexto.test('cada CHAVE do grupo, hostil, uma a uma', () => {
        const chaves = [
            'produto_id', 'produto_nome', 'produto_sku', 'estoque', 'cobertura',
            'total_no_produto', 'total_visivel', 'sem_fase2', 'ver_produto_url',
            'criar_combo_url', 'ml_item_ids',
        ];
        for (const chave of chaves) {
            for (const valor of VALORES_HOSTIS) {
                const grupo = { ...GRUPO_CHEIO, [chave]: valor };
                let html = '';
                assert.doesNotThrow(() => {
                    html = umGrupo({ grupo });
                }, `${chave} = ${descreve(valor)} lançou`);
                assert.doesNotMatch(html, RE_SUJEIRA, `${chave} = ${descreve(valor)} sujou a tela`);
            }
            // E a chave AUSENTE (não é a mesma coisa que `undefined` explícito
            // em todo caminho de leitura).
            const { [chave]: _removida, ...semAChave } = GRUPO_CHEIO;
            let html = '';
            assert.doesNotThrow(() => {
                html = umGrupo({ grupo: semAChave });
            }, `sem a chave ${chave} lançou`);
            assert.doesNotMatch(html, RE_SUJEIRA, `sem a chave ${chave} sujou a tela`);
        }
    });

    await contexto.test('`cobertura` com chave desconhecida ou tipo errado', () => {
        const coberturas = [
            { f1_classico: 'sim', f1_premium: 1, f2_classico: {}, f2_premium: [] },
            { chave_que_nao_existe: true },
            { [String.fromCharCode(0x201c)]: true },
            [true, true, true, true],
            'f1',
            42,
        ];
        for (const cobertura of coberturas) {
            let html = '';
            assert.doesNotThrow(() => {
                html = umGrupo({ grupo: { ...GRUPO_CHEIO, cobertura } });
            }, `cobertura ${descreve(cobertura)} lançou`);
            assert.doesNotMatch(html, RE_SUJEIRA, `cobertura ${descreve(cobertura)} sujou a tela`);
        }
    });

    await contexto.test('as props de controle hostis', () => {
        for (const valor of VALORES_HOSTIS) {
            for (const prop of ['aberto', 'primeiro', 'onAlternar', 'className']) {
                let html = '';
                assert.doesNotThrow(() => {
                    html = umGrupo({ [prop]: valor });
                }, `${prop} = ${descreve(valor)} lançou`);
                assert.doesNotMatch(html, RE_SUJEIRA, `${prop} = ${descreve(valor)} sujou a tela`);
            }
        }
    });

    await contexto.test('o rodapé com props hostis', () => {
        for (const valor of VALORES_HOSTIS) {
            let html = '';
            assert.doesNotThrow(() => {
                html = render(Rodape, { rodape: valor, paginacao: valor, className: valor });
            }, `rodapé com ${descreve(valor)} lançou`);
            assert.doesNotMatch(html, RE_SUJEIRA, `rodapé com ${descreve(valor)} sujou a tela`);
        }
        for (const chave of ['visiveis', 'total', 'produtos']) {
            for (const valor of VALORES_HOSTIS) {
                const rodape = { visiveis: 50, total: 248, produtos: 9, [chave]: valor };
                let html = '';
                assert.doesNotThrow(() => {
                    html = render(Rodape, { rodape, paginacao: null });
                }, `rodape.${chave} = ${descreve(valor)} lançou`);
                assert.doesNotMatch(html, RE_SUJEIRA, `rodape.${chave} = ${descreve(valor)} sujou a tela`);
            }
        }
    });

    await contexto.test('os vazios com props hostis', () => {
        for (const caso of ['nunca_coletado', 'sem_anuncios', 'sem_resultado', null, 'xpto']) {
            for (const valor of VALORES_HOSTIS) {
                let html = '';
                assert.doesNotThrow(() => {
                    html = render(Vazios, {
                        caso,
                        busca: valor,
                        skuNaoColetado: valor,
                        acao: valor,
                        onLimparTudo: valor,
                        className: valor,
                    });
                }, `vazio ${caso === null ? 'null' : caso} com ${descreve(valor)} lançou`);
                assert.doesNotMatch(html, RE_SUJEIRA, `vazio com ${descreve(valor)} sujou a tela`);
            }
        }
    });

    await contexto.test('a prop INTEIRA nula/ausente nos três componentes', () => {
        for (const Componente of [Grupo, Rodape, Vazios]) {
            assert.doesNotThrow(() => renderToStaticMarkup(React.createElement(Componente)));
            assert.doesNotThrow(() => renderToStaticMarkup(React.createElement(Componente, {})));
        }
    });

    await contexto.test('⚠️ ISCA: o detector de sujeira PEGA o que existe', () => {
        // Sem isto, `doesNotMatch(RE_SUJEIRA)` poderia estar cego e aprovar tudo.
        assert.match('valor: [object Object]', RE_SUJEIRA);
        assert.match('NaN publicações', RE_SUJEIRA);
        assert.match('undefined', RE_SUJEIRA);
        assert.match('Invalid Date', RE_SUJEIRA);
        assert.doesNotMatch('50 de 248 publicações', RE_SUJEIRA);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 12. AS CLASSES GERAM CSS COM O VALOR CERTO (não só existem)
//
// O que precisaria estar errado para falhar: classe presente com VALOR errado —
// `h-[58px]` virando outra altura, o `padding` assimétrico virando simétrico, o
// `-ml-[4px]` perdendo o sinal, o `bg-pub-raised` apontando para o cinza de
// `raised-0`/`raised-2`, o alfa de `bg-pub-accent/[0.12]` mudando, ou
// `[text-wrap:pretty]` não compilando nesta versão do Tailwind.
//
// ⚠️ AS ARMADILHAS DO MEDIDOR, TODAS APLICADAS:
//   1. token sólido NÃO compila para hex — sai `rgb(... / var(--tw-...))`;
//   2. `stroke`/`fill` sólidos, ao contrário, SAEM EM HEX LITERAL;
//   3. o modificador de opacidade muda de FORMA conforme a propriedade;
//   4. o cssnano come o zero da frente, inclusive depois do menos;
//   5. o seletor sai ESCAPADO com barra invertida;
//   6. na variante o prefixo faz parte do nome (`.hover\:bg-...:hover`);
//   7. escapar só a barra quebra em classe com colchete/ponto/PARÊNTESE.
// ⚠️ E a LIMITAÇÃO ESTRUTURAL: esta sonda prova que a classe EXISTE com o valor
// certo; que o ELEMENTO a tem é o gate de HTML acima (igualdade de conjunto).
// ═══════════════════════════════════════════════════════════════════════════

/** Escapa TODOS os metacaracteres de regex (armadilha 7). */
function escapaRegex(texto) {
    return texto.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * O seletor que o Tailwind emite: `.` + o nome com os metacaracteres de CSS
 * escapados. ⚠️ `(` e `)` ENTRAM NA LISTA — foi a falta deles que fez a sonda do
 * plano 180-07 reportar `shadow-[…rgba(0,0,0,0.6)]` como AUSENTE.
 */
function seletorDe(classe) {
    return `.${classe.replace(/[[\]().:/%,]/g, (ch) => `\\${ch}`)}`;
}

function carregarCssConstruido() {
    const manifestRel = 'public/build/manifest.json';
    const manifestAbs = path.resolve(RAIZ, manifestRel);
    if (!fs.existsSync(manifestAbs)) return { motivo: `${manifestRel} ausente — rode \`npm run build\`` };

    // ⚠️ Manifest SEMPRE pelo JSON, nunca por grep: o hash sai com hífen.
    const manifest = JSON.parse(fs.readFileSync(manifestAbs, 'utf8'));
    const entrada = manifest['resources/js/app.jsx'];
    if (!entrada || !Array.isArray(entrada.css) || entrada.css.length === 0) {
        return { motivo: 'o manifest não aponta CSS para resources/js/app.jsx' };
    }

    const cssAbs = path.resolve(RAIZ, 'public/build', entrada.css[0]);
    if (!fs.existsSync(cssAbs)) return { motivo: `${entrada.css[0]} ausente — rode \`npm run build\`` };

    const mtimeCss = fs.statSync(cssAbs).mtimeMs;
    const mtimeFontes = Math.max(
        ...[REL_GRUPO, REL_RODAPE, REL_VAZIOS].map((rel) => fs.statSync(path.resolve(RAIZ, rel)).mtimeMs),
    );
    if (mtimeCss < mtimeFontes) {
        return { motivo: `o CSS construído (${entrada.css[0]}) é mais antigo que os componentes — rode \`npm run build\`` };
    }

    return { arquivo: entrada.css[0], css: fs.readFileSync(cssAbs, 'utf8') };
}

const CSS = carregarCssConstruido();

test('FID — as classes GERAM CSS com o valor certo', async (contexto) => {
    if (CSS.motivo) {
        contexto.skip(CSS.motivo);
        return;
    }

    const regraDe = (classe) => {
        const re = new RegExp(`${escapaRegex(seletorDe(classe))}(?![\\w\\\\-])[^{}]*\\{([^}]*)\\}`);
        const m = CSS.css.match(re);
        return m ? m[1] : null;
    };

    await contexto.test('o CSS lido é o do manifest e tem tamanho de CSS de verdade', () => {
        assert.ok(CSS.css.length > 100000, `CSS suspeito de truncado: ${CSS.css.length} bytes`);
    });

    await contexto.test('⚠️ ISCA: a sonda reporta AUSENTE o que não existe', () => {
        // As três iscas foram medidas como inexistentes no repo inteiro
        // (`grep -rF` em `resources/`, `app/`, `tests/` e `tailwind.config.js`
        // → 0 ocorrências) ANTES de serem usadas. ⚠️ `h-[59px]` é de propósito a
        // MESMA FORMA do `h-[58px]` real: se a sonda lesse a FORMA em vez do
        // valor, ela acharia as duas.
        for (const isca of ['bg-pub-isca-9k4', 'h-[59px]', 'pl-[13px]', 'py-[63px]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS.css.includes(isca), `a string ${isca} apareceu no CSS`);
        }
        assert.notEqual(regraDe('h-[58px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('a geometria da linha de grupo', () => {
        assert.match(regraDe('h-[58px]'), /height:\s*58px/);
        assert.match(regraDe('pl-[14px]'), /padding-left:\s*14px/);
        assert.match(regraDe('pr-[16px]'), /padding-right:\s*16px/);
        assert.match(regraDe('gap-[14px]'), /gap:\s*14px/);
        assert.match(regraDe('ml-[22px]'), /margin-left:\s*22px/);
        assert.match(regraDe('pl-[22px]'), /padding-left:\s*22px/);
        assert.match(regraDe('h-[30px]'), /height:\s*30px/);
        // ⚠️ O cssnano tira o zero da frente INCLUSIVE depois do menos — mas
        // `-4px` não tem zero; o que não pode é o sinal sumir.
        // ⚠️ O SINAL é a informação: `margin-left:4px` empurraria o tile para o
        // lado errado e um gate de presença de classe não veria diferença.
        assert.match(regraDe('-ml-[4px]'), /margin-left:\s*-4px/);
        assert.doesNotMatch(regraDe('-ml-[4px]'), /margin-left:\s*4px/);
    });

    await contexto.test('a geometria do rodapé e do vazio', () => {
        assert.match(regraDe('h-[44px]'), /height:\s*44px/);
        assert.match(regraDe('py-[64px]'), /padding-top:\s*64px;padding-bottom:\s*64px/);
        assert.match(regraDe('px-[24px]'), /padding-left:\s*24px;padding-right:\s*24px/);
        assert.match(regraDe('max-w-[380px]'), /max-width:\s*380px/);
        assert.match(regraDe('mt-[6px]'), /margin-top:\s*6px/);
        assert.match(regraDe('h-[32px]'), /height:\s*32px/);
        assert.match(regraDe('rounded-[8px]'), /border-radius:\s*8px/);
        assert.match(regraDe('px-[9px]'), /padding-left:\s*9px;padding-right:\s*9px/);
        assert.match(regraDe('py-[4px]'), /padding-top:\s*4px;padding-bottom:\s*4px/);
        assert.match(regraDe('py-[1px]'), /padding-top:\s*1px;padding-bottom:\s*1px/);
    });

    await contexto.test('⚠️ `[text-wrap:pretty]` COMPILA nesta versão do Tailwind', () => {
        const regra = regraDe('[text-wrap:pretty]');
        assert.notEqual(regra, null, 'a propriedade arbitrária não compilou — o valor teria de ir por `style`');
        assert.match(regra, /text-wrap:\s*pretty/);
    });

    await contexto.test('⚠️ os fundos e as bordas, em DECIMAL (token sólido não sai em hex)', () => {
        // `bg-pub-raised` NÃO pode casar com `bg-pub-raised-0` (#131313) nem com
        // `bg-pub-raised-2` (#181818) — é o `(?![\w\\-])` do `regraDe`.
        assert.match(regraDe('bg-pub-raised'), /rgb\(21 21 21/);
        assert.match(regraDe('bg-pub-raised-0'), /rgb\(19 19 19/);
        assert.match(regraDe('bg-pub-raised-2'), /rgb\(24 24 24/);
        assert.match(regraDe('bg-pub-sunken'), /rgb\(13 13 13/);
        assert.match(regraDe('bg-pub-surface'), /rgb\(17 17 17/);
        assert.match(regraDe('border-pub-line'), /rgb\(38 38 38/);
        assert.match(regraDe('border-pub-line-strong'), /rgb\(46 46 46/);
        assert.match(regraDe('border-pub-line-subtle'), /rgb\(31 31 31/);
        assert.match(regraDe('text-pub-txt-3'), /rgb\(138 138 144/);
        assert.match(regraDe('text-pub-neutral-txt'), /rgb\(161 161 170/);
        assert.match(regraDe('text-pub-accent'), /rgb\(250 204 21/);
        assert.match(regraDe('text-pub-warn-txt'), /rgb\(251 191 36/);
        assert.match(regraDe('stroke-pub-txt-3'), /stroke:\s*#8a8a90/i); // armadilha 2
    });

    await contexto.test('⚠️ o hover é uma classe com `:hover` no NOME, e o alfa do chip', () => {
        const hover = regraDe('hover:bg-pub-hover-3');
        assert.notEqual(hover, null, 'o `hover:` sumiu — a linha de grupo não reage ao mouse');
        assert.match(hover, /rgb\(26 26 26/);

        // ⚠️ `background-color` com modificador de opacidade sai em HEX DE 8
        // DÍGITOS, e o byte do alfa é o que PROVA 0,12 (0.12*255 ≈ 0x1f).
        const chip = regraDe('bg-pub-accent/[0.12]');
        assert.notEqual(chip, null, 'o fundo do "Criar combo" não compilou');
        assert.match(chip, /#facc151f/i, `o alfa do chip mudou: ${chip}`);
    });

    await contexto.test('a rotação do chevron e a transição', () => {
        assert.match(regraDe('rotate-90'), /--tw-rotate:\s*90deg/);
        assert.match(regraDe('rotate-0'), /--tw-rotate:\s*0deg/);
        assert.match(regraDe('transition-transform'), /transition-property:\s*transform/);
        // ⚠️⚠️ A CLASSE MORTA: `duration-[180ms]` NÃO EXISTE no CSS deste
        // projeto — o `tailwindcss-animate` registra `duration` como utilitário
        // de ANIMAÇÃO e o Tailwind recusa o valor arbitrário por ambiguidade
        // ("The class `duration-[180ms]` is ambiguous and matches multiple
        // utilities", reproduzido com postcss + tailwind + animate em
        // 13/10/2026). ⚠️ A escala NOMEADA existe (controle), então esta
        // asserção não é vazia por construção: ela distingue "o arbitrário não
        // compila" de "a sonda não acha nada com `duration`".
        assert.equal(regraDe('duration-[180ms]'), null, 'passou a compilar — reveja a decisão do `style`');
        assert.notEqual(regraDe('duration-200'), null, 'controle: a escala nomeada de duration existe');
        assert.ok(!CSS.css.includes(String.raw`duration-\[`), 'apareceu uma duration ARBITRÁRIA no CSS');
        // E a classe morta não pode voltar para o arquivo.
        for (const [caminho, fonte] of FONTES) {
            assert.doesNotMatch(fonte, /duration-\[/, `${caminho}: classe de duração que não compila`);
        }
    });

    await contexto.test('os corpos de texto', () => {
        for (const [classe, valor] of [
            ['text-[10.5px]', '10.5px'],
            ['text-[11.5px]', '11.5px'],
            ['text-[12px]', '12px'],
            ['text-[12.5px]', '12.5px'],
            ['text-[13px]', '13px'],
            ['text-[14px]', '14px'],
            ['text-[15px]', '15px'],
        ]) {
            const regra = regraDe(classe);
            assert.notEqual(regra, null, `${classe} não gerou CSS`);
            assert.match(regra, new RegExp(`font-size:\\s*${escapaRegex(valor)}`), `${classe}: ${regra}`);
        }
        // ⚠️ `text-[11px]` e `text-[11.5px]` são regras DIFERENTES: substring
        // casaria uma na outra.
        assert.notEqual(regraDe('text-[11px]'), regraDe('text-[11.5px]'));
        assert.match(regraDe('tracking-[-0.01em]'), /letter-spacing:\s*-\.?0?\.?01em/);
        assert.match(regraDe('tracking-[0.06em]'), /letter-spacing:\s*\.?0?\.?06em/);
    });

    await contexto.test('os raios nomeados e a fonte mono', () => {
        assert.match(regraDe('rounded-pub-badge'), /border-radius:\s*4px/);
        assert.match(regraDe('rounded-pub-chip'), /border-radius:\s*6px/);
        assert.match(regraDe('rounded-pub-control-sm'), /border-radius:\s*7px/);
        assert.match(regraDe('font-pub-mono'), /Geist Mono/);
    });
});
