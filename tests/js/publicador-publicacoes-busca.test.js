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
// Fase 180, plano 06 — a busca central de 54px (LIST-03/LIST-04), o trilho de
// status com "Rascunhos" integrada (LIST-05/LIST-25) e a linha de controles com
// o toggle de visão (LIST-06), com render REAL (esbuild + `react-dom/server`).
//
// Protótipo: `new_layout_publicador/Publicacoes v2.dc.html`. Linhas MEDIDAS com
// `grep -n` neste arquivo (não copiadas da tabela do plano):
//   · wrapper da busca   → :68   · lupa            → :69
//   · `<input>` de 54px  → :70   · bloco direita   → :71
//   · resumo             → :73   · "Limpar"        → :74
//   · dica + tecla "/"   → :77   · coluna externa  → :82 (do plano 180-10)
//   · linha de controles → :82   · trilho das abas → :83
//   · botão de aba       → :85   · dot             → :86
//   · contagem na aba    → :88   · bloco direita   → :90
//   · rótulo da ordenação→ :93   · trilho do toggle→ :92
//   · opção de visão     → :96
//   · os 5 rótulos       → :531  · ativa/inativa   → :532 e :536
//   · a frase do resumo  → :626
//
// ⚠️ REND-03: TODOS os bundles são montados AQUI NO TOPO, antes do primeiro
// `test()`. O `after()` do `node --test` dispara quando os testes registrados
// terminam e já matou um arquivo inteiro deste projeto SEM nenhum teste falhar.
//
// ⚠️ ESTA TELA É FEITA DE IRMÃOS IDÊNTICOS: 6 abas com a MESMA string de
// classes e 2 opções de visão idem. `html.indexOf(listaDeClasses)` pegaria o
// irmão errado — quatro gates do ERP já mediram a linha do Mercado Livre por
// isso. Toda localização aqui é por ÁRVORE com índices absolutos.
//
// ⚠️ A pergunta obrigatória — *"o que precisaria estar errado no código para
// este gate falhar?"* — está respondida POR TESTE, no comentário de cada bloco.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

const STUB_INERTIA = path.join(__dirname, `.pub-busca-inertia-stub-${process.pid}.mjs`);
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
        `.pub-busca-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`,
    );
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const DIR_PUB = 'resources/js/Components/Mlb/Publicador/publicacoes';
const REL_BUSCA = `${DIR_PUB}/BuscaDePublicacoes.jsx`;
const REL_ABAS = `${DIR_PUB}/AbasDeStatus.jsx`;
const REL_LINHA = `${DIR_PUB}/LinhaDeControles.jsx`;

// ⚠️ `test -e` ANTES de acreditar em qualquer leitura vazia. CINCO caminhos
// inexistentes já "provaram" território intocado nesta milestone.
for (const caminho of [DIR_PUB, REL_BUSCA, REL_ABAS, REL_LINHA]) {
    if (!fs.existsSync(path.resolve(RAIZ, caminho))) {
        throw new Error(`caminho inexistente: ${caminho} — gate mediria NADA`);
    }
}

const MOD_BUSCA = await montar(REL_BUSCA);
const MOD_ABAS = await montar(REL_ABAS);
const MOD_LINHA = await montar(REL_LINHA);

const Busca = MOD_BUSCA.default;
const Abas = MOD_ABAS.default;
const Linha = MOD_LINHA.default;

const FONTE_BUSCA = lerSemComentarios(REL_BUSCA);
const FONTE_ABAS = lerSemComentarios(REL_ABAS);
const FONTE_LINHA = lerSemComentarios(REL_LINHA);

const FONTES = [
    [REL_BUSCA, FONTE_BUSCA],
    [REL_ABAS, FONTE_ABAS],
    [REL_LINHA, FONTE_LINHA],
];

/** Render de servidor, sem comentários `<!-- -->` entre nós de texto. */
const render = (Componente, props) => renderToStaticMarkup(React.createElement(Componente, props));

// ─────────────────────────────────────────────────────────────────────────
// Localização por ÁRVORE com índices absolutos.
// ─────────────────────────────────────────────────────────────────────────

/** A tag de abertura inteira que começa em `pos`. */
function tagEm(html, pos) {
    assert.ok(pos !== -1 && pos < html.length, 'posição fora do HTML');
    const fim = html.indexOf('>', pos);
    assert.ok(fim !== -1, 'tag de abertura sem fechamento');
    return html.slice(pos, fim + 1);
}

/** Índice absoluto da `ocorrencia`-ésima tag de abertura `<nome`. */
function indiceDaTag(html, nome, ocorrencia = 1) {
    let pos = -1;
    for (let i = 0; i < ocorrencia; i += 1) {
        pos = html.indexOf(`<${nome}`, pos + 1);
        assert.ok(pos !== -1, `não achei a ${i + 1}ª <${nome}> no HTML`);
    }
    return pos;
}

/**
 * O BLOCO inteiro (abertura + conteúdo + fechamento) do elemento `nome` que
 * começa no índice absoluto `pos`, contando profundidade.
 *
 * ⚠️ É isto que torna a localização por árvore honesta: fatiar "do começo da
 * tag até o próximo `</div>`" fecharia no filho, não no pai.
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
            // Tag auto-fechada (`<input … />`) não abre nível.
            if (html[fim - 1] !== '/') profundidade += 1;
        }
        m = re.exec(html);
    }
    throw new Error(`<${nome}> aberta em ${pos} nunca fecha`);
}

/** Os índices absolutos de TODAS as tags de abertura `<nome` dentro de `bloco`. */
function indicesDasTags(html, bloco, nome) {
    const base = html.indexOf(bloco);
    assert.ok(base !== -1, 'o bloco não pertence a este HTML');
    return [...bloco.matchAll(new RegExp(`<${nome}(?=[\\s/>])`, 'g'))].map((m) => base + m.index);
}

/** As classes de uma tag de abertura, como LISTA (nunca substring). */
function classesDa(tag) {
    const m = tag.match(/class="([^"]*)"/);
    assert.ok(m, `tag sem class=: ${tag}`);
    return m[1].split(/\s+/).filter(Boolean);
}

/** Igualdade de CONJUNTO de classes — endurecimento: classe a mais reprova. */
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

/**
 * Descritor SEGURO para mensagem de asserção.
 *
 * ⚠️ `String({toString: null})` LANÇA e mataria o gate DENTRO da mensagem (foi
 * o que aconteceu com 5 blocos no plano 180-05); `JSON.stringify` MENTE
 * (`NaN`/`Infinity` → `'null'`).
 */
function descreve(valor) {
    if (valor === null) return 'null';
    if (valor === undefined) return 'undefined';
    const tipo = typeof valor;
    if (tipo === 'number' || tipo === 'boolean' || tipo === 'bigint') return `${tipo}:${valor}`;
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

// ─────────────────────────────────────────────────────────────────────────
// OS FIXTURES
//
// ⚠️ As 6 abas são as que o controller monta (`MlbAnuncioController::meus()`,
// `:887`-`:897`), com `comDot` em camelCase e já embutindo o "> 0".
// ─────────────────────────────────────────────────────────────────────────

const ABAS_6 = [
    { chave: 'todos', rotulo: 'Todas', total: 24, comDot: false },
    { chave: 'ativos', rotulo: 'Ativas', total: 18, comDot: false },
    { chave: 'pausados', rotulo: 'Pausadas', total: 4, comDot: false },
    { chave: 'com_erro', rotulo: 'Com erro', total: 2, comDot: true },
    { chave: 'em_revisao', rotulo: 'Em revisão', total: 0, comDot: false },
    { chave: 'rascunhos', rotulo: 'Rascunhos', total: 7, comDot: false },
];

/** As mesmas 6, sem nenhum dot — para a asserção de ausência não ser vazia. */
const ABAS_6_SEM_DOT = ABAS_6.map((aba) => ({ ...aba, comDot: false }));

const CONTAGENS = {
    todos: 24, acionaveis: 22, ativos: 18, pausados: 4,
    em_revisao: 0, encerrados: 2, com_erro: 2, rascunhos: 7,
};

const CHAVES_6 = ABAS_6.map((aba) => aba.chave);
const ROTULOS_6 = ABAS_6.map((aba) => aba.rotulo);

const HTML_BUSCA_VAZIA = render(Busca, { valor: '', onMudar: () => {}, onLimpar: () => {} });
const HTML_BUSCA_CHEIA = render(Busca, {
    valor: 'poltrona',
    onMudar: () => {},
    onLimpar: () => {},
    resumo: { publicacoes: 2, produtos: 1 },
});
const HTML_BUSCA_SEM_RESUMO = render(Busca, { valor: 'poltrona', onMudar: () => {}, onLimpar: () => {} });

const HTML_ABAS = render(Abas, { abas: ABAS_6, ativa: 'todos', onTrocar: () => {} });
const HTML_ABAS_ERRO = render(Abas, { abas: ABAS_6, ativa: 'com_erro', onTrocar: () => {} });
const HTML_ABAS_SEM_DOT = render(Abas, { abas: ABAS_6_SEM_DOT, ativa: 'todos', onTrocar: () => {} });

const HTML_LINHA = render(Linha, {
    abas: React.createElement(Abas, { abas: ABAS_6, ativa: 'todos', onTrocar: () => {} }),
    visao: 'grupo',
    onTrocarVisao: () => {},
});

/** Os 6 botões do trilho, por ÁRVORE: o bloco do `role="tablist"` e os `<button>` dele. */
function botoesDoTrilho(html) {
    const posTrilho = html.indexOf('<div role="tablist"');
    assert.ok(posTrilho !== -1, 'não achei o trilho `role="tablist"`');
    const bloco = blocoEm(html, posTrilho, 'div');
    const indices = indicesDasTags(html, bloco, 'button');
    return indices.map((pos) => ({
        pos,
        tag: tagEm(html, pos),
        bloco: blocoEm(html, pos, 'button'),
    }));
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. OS LITERAIS, BYTE A BYTE
//
// O que precisaria estar errado para falhar: alguém parafrasear o placeholder,
// trocar o `…` de UM caractere por três pontos, mudar "Pressione"/"para
// buscar"/"Limpar", parafrasear um dos 6 rótulos de aba ou os 2 do toggle, ou
// cravar "Mais recentes primeiro" (que MENTE sobre a ordenação do servidor).
// É a forma mais comum de o redesenho "adaptar ao sistema atual" sem ninguém
// notar.
// ═══════════════════════════════════════════════════════════════════════════

test('LIST-03/04 — os literais da busca estão byte a byte', async (contexto) => {
    await contexto.test('o placeholder é o do protótipo (:70), com o `…` de UM caractere', () => {
        const esperado = 'Buscar por produto, título, SKU ou MLB…';
        assert.equal(MOD_BUSCA.PLACEHOLDER, esperado);
        assert.ok(HTML_BUSCA_VAZIA.includes(`placeholder="${esperado}"`), HTML_BUSCA_VAZIA);

        // ⚠️ O code point, não a aparência: `...` (3 chars) passaria por
        // qualquer leitura humana e mudaria o literal.
        assert.equal(esperado.at(-1), '…', 'o fim do placeholder não é U+2026 HORIZONTAL ELLIPSIS');
        assert.equal(esperado.at(-1).codePointAt(0), 0x2026);
        assert.ok(!esperado.includes('...'), 'o placeholder usa três pontos em vez do `…`');
        assert.ok(!FONTE_BUSCA.includes('MLB...'), 'a fonte escreveu três pontos no placeholder');
    });

    await contexto.test('sem busca: "Pressione", a tecla "/" e "para buscar" (:77)', () => {
        assert.ok(HTML_BUSCA_VAZIA.includes('Pressione '), HTML_BUSCA_VAZIA);
        assert.ok(HTML_BUSCA_VAZIA.includes(' para buscar'));
        assert.equal(MOD_BUSCA.TECLA_DA_BUSCA, '/');
        // A tecla é o CONTEÚDO de uma span própria, não um `/` perdido no texto.
        assert.match(HTML_BUSCA_VAZIA, /<span class="[^"]*font-pub-mono[^"]*">\/<\/span>/);
    });

    await contexto.test('com busca: "Limpar" (:74) e o resumo (:626)', () => {
        assert.equal(MOD_BUSCA.LIMPAR, 'Limpar');
        assert.ok(HTML_BUSCA_CHEIA.includes('>Limpar</button>'), HTML_BUSCA_CHEIA);
        assert.ok(HTML_BUSCA_CHEIA.includes('2 publicações · 1 produto'), 'a frase do resumo (:626)');
    });

    await contexto.test('os dois estados são EXCLUSIVOS (um nega o outro, com dado cheio)', () => {
        // ⚠️ Asserção de ausência rodada com as props PREENCHIDAS: com busca o
        // bloco da dica não pode existir, e sem busca o "Limpar" não pode.
        assert.ok(!HTML_BUSCA_CHEIA.includes('para buscar'), 'a dica apareceu COM busca');
        assert.ok(!HTML_BUSCA_VAZIA.includes('>Limpar</button>'), 'o "Limpar" apareceu SEM busca');
    });

    await contexto.test('plural correto nos dois números', () => {
        const um = render(Busca, { valor: 'x', onLimpar: () => {}, resumo: { publicacoes: 1, produtos: 1 } });
        assert.ok(um.includes('1 publicação · 1 produto'), um);
        assert.ok(!um.includes('publicações'), 'plural usado para 1 publicação');
        assert.ok(!um.includes('produtos'), 'plural usado para 1 produto');

        const varios = render(Busca, { valor: 'x', onLimpar: () => {}, resumo: { publicacoes: 3, produtos: 2 } });
        assert.ok(varios.includes('3 publicações · 2 produtos'), varios);
        // ⚠️ `'1 publicação'` é substring de nada aqui; a negativa exclusiva é
        // o singular do SUBSTANTIVO, que não pode aparecer no render de 3.
        assert.ok(!varios.includes('publicação ·'), 'singular usado para 3');
    });
});

test('LIST-05/06/25 — os literais do trilho e do toggle estão byte a byte', async (contexto) => {
    await contexto.test('os 5 rótulos de status do protótipo (:531), na ordem do servidor', () => {
        const botoes = botoesDoTrilho(HTML_ABAS);
        assert.equal(botoes.length, 6, 'o trilho tem de ter exatamente 6 opções');
        for (let i = 0; i < 6; i += 1) {
            assert.ok(
                botoes[i].bloco.includes(`>${ROTULOS_6[i]}<`),
                `a ${i + 1}ª opção não é "${ROTULOS_6[i]}": ${botoes[i].bloco}`,
            );
        }
    });

    await contexto.test('"Rascunhos" é a 6ª e última, com o contador de hoje (LIST-25)', () => {
        const botoes = botoesDoTrilho(HTML_ABAS);
        const ultimo = botoes[5];
        assert.ok(ultimo.bloco.includes('>Rascunhos<'), ultimo.bloco);
        assert.ok(ultimo.bloco.includes('>7</span>'), 'o contador de Rascunhos não é o do servidor');
        // E o nome acessível diz que ela troca a FONTE, não o filtro.
        assert.equal(atributo(ultimo.tag, 'aria-label'), MOD_ABAS.ARIA_DA_FONTE);
        assert.match(MOD_ABAS.ARIA_DA_FONTE, /^Rascunhos/);
    });

    await contexto.test('⚠️ jargão do ML NUNCA aparece na tela', () => {
        for (const cru of ['under_review', 'gold_pro', 'gold_special', 'active', 'paused', 'closed']) {
            assert.ok(!HTML_ABAS.includes(cru), `"${cru}" vazou para a tela`);
        }
        // E as CHAVES do servidor também não: elas vão para a URL, não para o texto.
        assert.ok(!HTML_ABAS.includes('>com_erro<'), 'a chave crua `com_erro` virou rótulo');
        assert.ok(!HTML_ABAS.includes('>em_revisao<'), 'a chave crua `em_revisao` virou rótulo');
    });

    await contexto.test('o toggle é "Por produto" e "Lista", nesta ordem (:536)', () => {
        assert.deepEqual(
            MOD_LINHA.OPCOES_DE_VISAO.map((o) => o.rotulo),
            ['Por produto', 'Lista'],
        );
        const posPrimeiro = HTML_LINHA.indexOf('>Por produto<');
        const posSegundo = HTML_LINHA.indexOf('>Lista<');
        assert.ok(posPrimeiro !== -1 && posSegundo !== -1, HTML_LINHA);
        assert.ok(posPrimeiro < posSegundo, '"Lista" veio antes de "Por produto"');
    });

    await contexto.test('⚠️ o rótulo da ordenação diz a VERDADE: gravidade, não data', () => {
        // O servidor ordena por `orderByDesc('severidade')` (D-12). O protótipo
        // ordena por data (`:529`) e por isso escreve "Mais recentes primeiro"
        // (`:93`). Rótulo que mente é pior que rótulo diferente do protótipo.
        assert.equal(MOD_LINHA.ORDENACAO_PADRAO, 'Mais graves primeiro');
        assert.ok(HTML_LINHA.includes('Mais graves primeiro'), HTML_LINHA);
        assert.ok(
            !FONTE_LINHA.includes('Mais recentes primeiro'),
            'o literal do protótipo foi cravado no código e ele MENTE sobre a ordenação do servidor',
        );
    });

    await contexto.test('e o rótulo do servidor GANHA do default (é prop, não literal)', () => {
        const html = render(Linha, {
            visao: 'lista',
            onTrocarVisao: () => {},
            ordenacaoRotulo: 'Mais recentes primeiro',
        });
        assert.ok(html.includes('Mais recentes primeiro'), html);
        assert.ok(!html.includes('Mais graves primeiro'), 'o default venceu o rótulo do servidor');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. FID-01 — A GEOMETRIA, POR IGUALDADE DE CONJUNTO DE CLASSES
//
// O que precisaria estar errado para falhar: qualquer medida trocada por outra
// (54→52px, raio 14→12, 15.5px→`text-base`, `bg-pub-hover-2`→`bg-pub-hover`),
// qualquer classe A MAIS (é igualdade de conjunto, não `includes`), o elemento
// mudar de tag, ou o `viewBox` do protótipo virar o do Lucide.
//
// ⚠️ É este bloco — e não a sonda de CSS — que protege a fidelidade DESTES
// arquivos: a sonda lê o CSS do último build, e a mesma classe pode ter outro
// dono no repositório (medido no plano 180-05).
// ═══════════════════════════════════════════════════════════════════════════

const CLASSES_INPUT = [
    'h-[54px]', 'w-full', 'rounded-pub-table', 'border', 'border-pub-line-strong',
    'bg-pub-surface', 'pl-[50px]', 'pr-[220px]', 'text-[15.5px]', 'text-pub-txt',
    'outline-none', 'transition-[border-color,box-shadow]', 'duration-150',
    'placeholder:text-pub-txt-4', 'focus:border-pub-accent', 'focus:ring-[3px]',
    'focus:ring-pub-accent/[0.18]',
];

const CLASSES_ABA_BASE = [
    'flex', 'h-[30px]', 'cursor-pointer', 'items-center', 'gap-[7px]',
    'rounded-pub-control-sm', 'px-[12px]', 'text-[13px]', 'font-medium',
];

const CLASSES_ATIVA = ['bg-pub-seg', 'shadow-pub-seg-ativo', 'text-pub-txt'];
const CLASSES_INATIVA = ['bg-transparent', 'text-pub-neutral-txt'];
const CLASSES_TRILHO = ['flex', 'items-center', 'gap-[2px]', 'rounded-pub-sub', 'bg-pub-hover-2', 'p-[3px]'];

test('FID-01 — a geometria da busca central (:68-:77)', async (contexto) => {
    await contexto.test('wrapper :68 — relative flex items-center', () => {
        assertClasses(tagEm(HTML_BUSCA_VAZIA, 0), ['relative', 'flex', 'items-center'], 'wrapper :68');
    });

    await contexto.test('lupa :69 — 18×18, viewBox do protótipo, stroke 1.6, left:18px', () => {
        const pos = indiceDaTag(HTML_BUSCA_VAZIA, 'svg');
        const tag = tagEm(HTML_BUSCA_VAZIA, pos);
        assertClasses(
            tag,
            ['pointer-events-none', 'absolute', 'left-[18px]', 'fill-none', 'stroke-pub-neutral-txt'],
            'lupa :69',
        );
        assert.equal(atributo(tag, 'width'), '18');
        assert.equal(atributo(tag, 'height'), '18');
        // ⚠️ `0 0 16 16` e NÃO o `0 0 24 24` do Lucide (que exigiria 1.6×1,5).
        assert.equal(atributo(tag, 'viewBox'), '0 0 16 16');
        assert.equal(atributo(tag, 'stroke-width'), '1.6');
        assert.equal(atributo(tag, 'stroke-linecap'), 'round');
        assert.equal(atributo(tag, 'aria-hidden'), 'true', 'a lupa é decorativa');
        // O desenho do protótipo: círculo r=4.5 em (7,7) + o cabo.
        const bloco = blocoEm(HTML_BUSCA_VAZIA, pos, 'svg');
        assert.match(bloco, /<circle cx="7" cy="7" r="4\.5">/);
        assert.match(bloco, /d="M10\.5 10\.5L14 14"/);
    });

    await contexto.test('input :70 — 54px, raio 14, 15.5px, padding 0 220px 0 50px, foco amarelo', () => {
        const tag = tagEm(HTML_BUSCA_VAZIA, indiceDaTag(HTML_BUSCA_VAZIA, 'input'));
        assertClasses(tag, CLASSES_INPUT, 'input :70');
        assert.equal(atributo(tag, 'type'), 'text');
        // ⚠️ Substring não prova medida: `h-[54px]` × `h-[5px]`, `text-[15.5px]`
        // × `text-[15px]`. A asserção acima é de CONJUNTO, por isso prova.
        // Aqui provo o inverso — a medida errada NÃO está na lista:
        const classes = classesDa(tag);
        for (const errada of ['h-[52px]', 'h-[56px]', 'rounded-pub-sub', 'text-[15px]', 'text-base', 'text-sm']) {
            assert.ok(!classes.includes(errada), `${errada} entrou no input`);
        }
    });

    await contexto.test('bloco da direita :71 — absolute right:12px gap:10px', () => {
        // Por ÁRVORE: é o `<div>` que vem DEPOIS do input, não "o 2º div do HTML".
        const posInput = indiceDaTag(HTML_BUSCA_VAZIA, 'input');
        const posBloco = HTML_BUSCA_VAZIA.indexOf('<div', posInput);
        assert.ok(posBloco !== -1, 'não achei o bloco da direita depois do input');
        assertClasses(
            tagEm(HTML_BUSCA_VAZIA, posBloco),
            ['absolute', 'right-[12px]', 'flex', 'items-center', 'gap-[10px]'],
            'bloco da direita :71',
        );
    });

    await contexto.test('resumo :73 — 12.5px / #A1A1AA', () => {
        const pos = HTML_BUSCA_CHEIA.indexOf('2 publicações');
        assert.ok(pos !== -1, HTML_BUSCA_CHEIA);
        const inicio = HTML_BUSCA_CHEIA.lastIndexOf('<span', pos);
        assertClasses(
            tagEm(HTML_BUSCA_CHEIA, inicio),
            ['text-[12.5px]', 'text-pub-neutral-txt'],
            'resumo :73',
        );
    });

    await contexto.test('"Limpar" :74 — 28px, raio 7, #0D0D0D, 12px, cursor:pointer', () => {
        const tag = tagEm(HTML_BUSCA_CHEIA, indiceDaTag(HTML_BUSCA_CHEIA, 'button'));
        assertClasses(
            tag,
            [
                'h-[28px]', 'cursor-pointer', 'rounded-pub-control-sm', 'border', 'border-pub-line',
                'bg-pub-sunken', 'px-[10px]', 'text-[12px]', 'text-pub-neutral-txt',
            ],
            '"Limpar" :74',
        );
        assert.equal(atributo(tag, 'type'), 'button');
    });

    await contexto.test('dica :77 — gap:6px / 12px / #8A8A90, e a tecla com border-bottom 2px', () => {
        const posDica = HTML_BUSCA_VAZIA.indexOf('Pressione');
        assert.ok(posDica !== -1);
        const inicioDica = HTML_BUSCA_VAZIA.lastIndexOf('<span', posDica);
        assertClasses(
            tagEm(HTML_BUSCA_VAZIA, inicioDica),
            ['flex', 'items-center', 'gap-[6px]', 'text-[12px]', 'text-pub-txt-3'],
            'dica :77',
        );

        // ⚠️ A tecla é a span DENTRO da dica: `lastIndexOf('<span', posDoTexto)`
        // pegaria a própria dica se eu procurasse por "Pressione".
        const blocoDica = blocoEm(HTML_BUSCA_VAZIA, inicioDica, 'span');
        const posTecla = indicesDasTags(HTML_BUSCA_VAZIA, blocoDica, 'span')[1];
        assertClasses(
            tagEm(HTML_BUSCA_VAZIA, posTecla),
            [
                'rounded-[5px]', 'border', 'border-b-2', 'border-pub-line-strong', 'bg-pub-sunken',
                'px-[7px]', 'py-[2px]', 'font-pub-mono', 'text-[11.5px]', 'text-pub-neutral-txt',
            ],
            'tecla "/" :77',
        );
    });
});

test('FID-01 — a geometria do trilho, do dot e da contagem (:83-:88)', async (contexto) => {
    await contexto.test('trilho :83 — gap:2px padding:3px #161616 raio 10', () => {
        assertClasses(tagEm(HTML_ABAS, 0), CLASSES_TRILHO, 'trilho :83');
        assert.equal(atributo(tagEm(HTML_ABAS, 0), 'role'), 'tablist');
    });

    await contexto.test('os 6 botões :85 — mesma geometria, uma asserção por irmão', () => {
        const botoes = botoesDoTrilho(HTML_ABAS);
        assert.equal(botoes.length, 6);
        for (let i = 0; i < 6; i += 1) {
            const esperadas = [
                ...CLASSES_ABA_BASE,
                ...(CHAVES_6[i] === 'todos' ? CLASSES_ATIVA : CLASSES_INATIVA),
            ];
            assertClasses(botoes[i].tag, esperadas, `aba ${i + 1} (${CHAVES_6[i]}) :85`);
            assert.equal(atributo(botoes[i].tag, 'type'), 'button');
            assert.equal(atributo(botoes[i].tag, 'role'), 'tab');
        }
    });

    await contexto.test('dot :86 — 6×6, redondo, vermelho', () => {
        const comErro = botoesDoTrilho(HTML_ABAS)[3];
        const pos = indicesDasTags(HTML_ABAS, comErro.bloco, 'span')[0];
        assertClasses(
            tagEm(HTML_ABAS, pos),
            ['h-[6px]', 'w-[6px]', 'rounded-full', 'bg-pub-danger'],
            'dot :86',
        );
        assert.equal(atributo(tagEm(HTML_ABAS, pos), 'aria-hidden'), 'true');
    });

    await contexto.test('contagem :88 — 12px, #8A8A90, tabular-nums', () => {
        const todas = botoesDoTrilho(HTML_ABAS)[0];
        const pos = indicesDasTags(HTML_ABAS, todas.bloco, 'span')[0];
        assertClasses(
            tagEm(HTML_ABAS, pos),
            ['text-[12px]', 'tabular-nums', 'text-pub-txt-3'],
            'contagem :88',
        );
    });

    await contexto.test('a divisória de "Rascunhos" — DESVIO declarado, e ela é só uma', () => {
        const divisorias = [...HTML_ABAS.matchAll(/<span [^>]*class="mx-\[2px\][^"]*"/g)];
        assert.equal(divisorias.length, 1, 'esperava UMA divisória no trilho de 6 opções');
        const pos = HTML_ABAS.indexOf('<span aria-hidden="true" class="mx-[2px]');
        assertClasses(
            tagEm(HTML_ABAS, pos),
            ['mx-[2px]', 'h-[18px]', 'w-[1px]', 'bg-pub-line'],
            'divisória de Rascunhos',
        );
        // E ela vem ANTES de "Rascunhos", nunca depois.
        assert.ok(pos < HTML_ABAS.indexOf('>Rascunhos<'), 'a divisória nasceu depois da opção');
    });
});

test('FID-01 — a geometria da linha de controles e do toggle (:82-:96)', async (contexto) => {
    await contexto.test('a linha :82 — space-between gap:16px', () => {
        assertClasses(
            tagEm(HTML_LINHA, 0),
            ['flex', 'items-center', 'justify-between', 'gap-[16px]'],
            'linha de controles :82',
        );
    });

    await contexto.test('bloco da direita :90 — gap:10px (a tabela FID-01 OMITIU este bloco)', () => {
        const posToggle = HTML_LINHA.indexOf('<div role="group"');
        assert.ok(posToggle !== -1, 'não achei o trilho do toggle');
        const posBloco = HTML_LINHA.lastIndexOf('<div', posToggle - 1);
        assertClasses(
            tagEm(HTML_LINHA, posBloco),
            ['flex', 'items-center', 'gap-[10px]'],
            'bloco da direita :90',
        );
    });

    await contexto.test('rótulo da ordenação :93 — 12.5px / #8A8A90', () => {
        const pos = HTML_LINHA.lastIndexOf('<span', HTML_LINHA.indexOf('Mais graves primeiro'));
        assertClasses(tagEm(HTML_LINHA, pos), ['text-[12.5px]', 'text-pub-txt-3'], 'ordenação :93');
    });

    await contexto.test('trilho do toggle :92 — IDÊNTICO ao das abas', () => {
        const pos = HTML_LINHA.indexOf('<div role="group"');
        assertClasses(tagEm(HTML_LINHA, pos), CLASSES_TRILHO, 'trilho do toggle :92');
        // "idêntico" provado como igualdade, não como semelhança:
        assert.equal(MOD_ABAS.CLASSE_DO_TRILHO, CLASSES_TRILHO.join(' '));
    });

    await contexto.test('opções de visão :96 — 30px, raio 7, 13px/500, SEM display:flex', () => {
        const posTrilho = HTML_LINHA.indexOf('<div role="group"');
        const bloco = blocoEm(HTML_LINHA, posTrilho, 'div');
        const indices = indicesDasTags(HTML_LINHA, bloco, 'button');
        assert.equal(indices.length, 2, 'o toggle tem de ter exatamente 2 opções');

        const base = ['h-[30px]', 'cursor-pointer', 'rounded-pub-control-sm', 'px-[12px]', 'text-[13px]', 'font-medium'];
        assertClasses(tagEm(HTML_LINHA, indices[0]), [...base, ...CLASSES_ATIVA], 'visão "Por produto" :96');
        assertClasses(tagEm(HTML_LINHA, indices[1]), [...base, ...CLASSES_INATIVA], 'visão "Lista" :96');

        // ⚠️ O protótipo declara `display:flex` no botão de ABA (`:85`) e NÃO no
        // de visão (`:96`). A tabela FID-01 disse "igual à aba"; o protótipo
        // ganha. Esta negativa é o que trava a divergência nos dois sentidos.
        for (const pos of indices) {
            const classes = classesDa(tagEm(HTML_LINHA, pos));
            assert.ok(!classes.includes('flex'), 'a opção de visão ganhou display:flex, que o protótipo não declara');
            assert.ok(!classes.includes('gap-[7px]'), 'a opção de visão ganhou o gap da aba');
        }
    });

    await contexto.test('⚠️ font-weight é FIXO em 500 nas duas opções (:96), ativa ou não', () => {
        // O briefing dizia "font-weight:600 quando ativa" — isso é dos CHIPS de
        // triagem (`seg()`, `:535`), que são do plano 180-07. Medido no
        // protótipo: a opção de visão é `font-weight:500` nas duas.
        assert.ok(!HTML_LINHA.includes('font-semibold'), 'peso 600 entrou no toggle');
        assert.equal((HTML_LINHA.match(/font-medium/g) ?? []).length, 8, '6 abas + 2 opções de visão');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. A ABA ATIVA É A CERTA — SEIS IRMÃOS IDÊNTICOS, SEIS ASSERÇÕES
//
// O que precisaria estar errado para falhar: `bg-pub-seg` na aba ERRADA. É
// a mutação que prova que a localização por árvore não está medindo o irmão
// errado — "existe um `bg-pub-seg` no HTML" passaria com a marca em qualquer
// uma das 6.
// ═══════════════════════════════════════════════════════════════════════════

test('LIST-05 — a aba ativa é a certa, e as outras 5 NÃO estão marcadas', async (contexto) => {
    for (const chaveAtiva of CHAVES_6) {
        await contexto.test(`ativa="${chaveAtiva}" — exatamente ela leva bg-pub-seg`, () => {
            const html = render(Abas, { abas: ABAS_6, ativa: chaveAtiva, onTrocar: () => {} });
            const botoes = botoesDoTrilho(html);
            assert.equal(botoes.length, 6);

            for (let i = 0; i < 6; i += 1) {
                const ehAtiva = CHAVES_6[i] === chaveAtiva;
                const classes = classesDa(botoes[i].tag);
                assert.equal(
                    classes.includes('bg-pub-seg'),
                    ehAtiva,
                    `a aba ${i + 1} (${CHAVES_6[i]}) ${ehAtiva ? 'perdeu' : 'ganhou'} bg-pub-seg com ativa="${chaveAtiva}"`,
                );
                assert.equal(classes.includes('shadow-pub-seg-ativo'), ehAtiva);
                assert.equal(classes.includes('bg-transparent'), !ehAtiva);
                assert.equal(atributo(botoes[i].tag, 'aria-selected'), String(ehAtiva));
            }

            // E uma só, nunca duas.
            assert.equal((html.match(/bg-pub-seg/g) ?? []).length, 1);
            assert.equal((html.match(/aria-selected="true"/g) ?? []).length, 1);
        });
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. O DOT — `comDot` VEM DO SERVIDOR E NÃO É RECALCULADO
//
// O que precisaria estar errado para falhar: alguém recalcular o dot de
// `total > 0` aqui. O caso `total: 5, comDot: false` é a prova: com recálculo
// o dot apareceria, e as duas regras passariam a poder divergir.
// ═══════════════════════════════════════════════════════════════════════════

test('LIST-05 — o dot sai de `comDot` (camelCase), nunca de `total`', async (contexto) => {
    await contexto.test('com comDot:true em "Com erro", o dot está DENTRO daquela aba', () => {
        const botoes = botoesDoTrilho(HTML_ABAS);
        for (let i = 0; i < 6; i += 1) {
            const esperado = CHAVES_6[i] === 'com_erro';
            assert.equal(
                botoes[i].bloco.includes('bg-pub-danger'),
                esperado,
                `o dot ${esperado ? 'faltou em' : 'vazou para'} a aba ${CHAVES_6[i]}`,
            );
        }
        assert.equal((HTML_ABAS.match(/bg-pub-danger/g) ?? []).length, 1);
    });

    await contexto.test('com comDot:false em TODAS, nenhum dot — e as props estão CHEIAS', () => {
        // ⚠️ Asserção de ausência rodada com dado preenchido: as 6 abas têm
        // rótulo, total e chave; só `comDot` é falso.
        assert.equal(botoesDoTrilho(HTML_ABAS_SEM_DOT).length, 6);
        assert.ok(HTML_ABAS_SEM_DOT.includes('>Com erro<'), 'o fixture perdeu a aba "Com erro"');
        assert.ok(HTML_ABAS_SEM_DOT.includes('>2</span>'), 'o fixture perdeu a contagem');
        assert.ok(!HTML_ABAS_SEM_DOT.includes('bg-pub-danger'), 'dot desenhado com comDot:false');
    });

    await contexto.test('⚠️ total:5 com comDot:false NÃO desenha dot (o recálculo reprova aqui)', () => {
        const html = render(Abas, {
            abas: [{ chave: 'com_erro', rotulo: 'Com erro', total: 5, comDot: false }],
            ativa: 'com_erro',
            onTrocar: () => {},
        });
        assert.ok(html.includes('>5</span>'), 'a contagem 5 não apareceu');
        assert.ok(!html.includes('bg-pub-danger'), 'o dot foi RECALCULADO de total > 0');
    });

    await contexto.test('⚠️ snake_case (`com_dot`) não liga o dot — é o furo que o controller evitou', () => {
        const html = render(Abas, {
            abas: [{ chave: 'com_erro', rotulo: 'Com erro', total: 2, com_dot: true }],
            ativa: 'todos',
            onTrocar: () => {},
        });
        assert.ok(!html.includes('bg-pub-danger'), html);
        // E a fonte lê `comDot`, nunca `com_dot`.
        assert.ok(FONTE_ABAS.includes('comDot'), 'a fonte parou de ler `comDot`');
        assert.ok(!FONTE_ABAS.includes('com_dot'), 'a fonte passou a ler `com_dot` em snake_case');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. "NUNCA MEDIMOS" ≠ "MEDIMOS E É ZERO"
//
// O que precisaria estar errado para falhar: `total` nulo virando "0" ou "—",
// ou `total: 0` deixando de aparecer. ⚠️ `doesNotMatch(/0%/)` e parentes são
// proibidos (`/0%/` casa com os dois últimos caracteres de `100%`): aqui a
// asserção é sobre o NÓ da contagem, localizado por árvore.
// ═══════════════════════════════════════════════════════════════════════════

test('LIST-05 — `total` nulo não vira número; `total: 0` aparece', async (contexto) => {
    const comNulo = [
        { chave: 'todos', rotulo: 'Todas', total: null, comDot: false },
        { chave: 'ativos', rotulo: 'Ativas', total: 0, comDot: false },
    ];
    const html = render(Abas, { abas: comNulo, ativa: 'todos', onTrocar: () => {} });
    const botoes = botoesDoTrilho(html);

    await contexto.test('total null → NENHUM nó de contagem na aba (nem "0", nem "—")', () => {
        assert.equal(botoes.length, 2);
        assert.equal(
            indicesDasTags(html, botoes[0].bloco, 'span').length,
            0,
            `a aba de total nulo desenhou algo no lugar da contagem: ${botoes[0].bloco}`,
        );
        assert.equal(botoes[0].bloco, '<button type="button" role="tab" aria-selected="true" class="'
            + CLASSES_ABA_BASE.join(' ') + ' ' + CLASSES_ATIVA.join(' ') + '">Todas</button>');
    });

    await contexto.test('total 0 → o nó existe e o número é 0 (medido e é zero)', () => {
        const pos = indicesDasTags(html, botoes[1].bloco, 'span')[0];
        assert.equal(tagEm(html, pos).includes('tabular-nums'), true);
        assert.match(botoes[1].bloco, /tabular-nums[^>]*>0<\/span>/);
    });

    await contexto.test('string/NaN/Infinity/fracionário/negativo NÃO são contagem', () => {
        for (const sujo of ['5', NaN, Infinity, -Infinity, 2.5, -1, true, {}, []]) {
            const sujoHtml = render(Abas, {
                abas: [{ chave: 'todos', rotulo: 'Todas', total: sujo, comDot: false }],
                ativa: 'todos',
                onTrocar: () => {},
            });
            assert.ok(
                !sujoHtml.includes('tabular-nums'),
                `total ${descreve(sujo)} virou contagem na tela`,
            );
            assert.doesNotMatch(sujoHtml, RE_SUJEIRA, `total ${descreve(sujo)} sujou o HTML`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. A DECISÃO DO `status` FORA DO TRILHO (`acionaveis` / `encerrados`)
//
// O que precisaria estar errado para falhar: o trilho ficar SEM nenhuma opção
// marcada quando o usuário chega da Visão geral com `?status=acionaveis` (o
// DEFAULT da tela) ou `?status=encerrados`; ou a tela inventar uma aba que o
// servidor não conhece; ou o número sair de outro lugar que não `contagens`.
// ═══════════════════════════════════════════════════════════════════════════

test('LIST-05 — `acionaveis`/`encerrados` ganham opção À FRENTE, com rótulo de hoje', async (contexto) => {
    await contexto.test('os dois rótulos são os que a tela de HOJE já usa', () => {
        assert.deepEqual(MOD_ABAS.ROTULOS_FORA_DO_TRILHO, {
            acionaveis: 'Acionáveis',
            encerrados: 'Encerrados',
        });
    });

    for (const [chave, rotulo, total] of [['acionaveis', 'Acionáveis', 22], ['encerrados', 'Encerrados', 2]]) {
        await contexto.test(`ativa="${chave}" — 7 opções, a 1ª é "${rotulo}" e está marcada`, () => {
            const html = render(Abas, {
                abas: ABAS_6, ativa: chave, onTrocar: () => {}, contagens: CONTAGENS,
            });
            const botoes = botoesDoTrilho(html);
            assert.equal(botoes.length, 7, 'esperava as 6 do servidor + a ativa fora do trilho');
            assert.ok(botoes[0].bloco.includes(`>${rotulo}<`), botoes[0].bloco);
            assert.equal(atributo(botoes[0].tag, 'aria-selected'), 'true');
            assert.ok(botoes[0].bloco.includes(`>${total}</span>`), 'o número não veio de `contagens`');

            // ⚠️ E nenhuma das 6 do servidor ficou marcada.
            for (let i = 1; i < 7; i += 1) {
                assert.equal(atributo(botoes[i].tag, 'aria-selected'), 'false', `a ${i + 1}ª ficou marcada também`);
            }
            assert.equal((html.match(/aria-selected="true"/g) ?? []).length, 1);
            // A chave crua nunca aparece como texto.
            assert.ok(!html.includes(`>${chave}<`), `a chave crua "${chave}" virou rótulo`);
        });
    }

    await contexto.test('sem `contagens`, a opção nasce SEM número (nunca com 0 inventado)', () => {
        const html = render(Abas, { abas: ABAS_6, ativa: 'acionaveis', onTrocar: () => {} });
        const primeiro = botoesDoTrilho(html)[0];
        assert.ok(primeiro.bloco.includes('>Acionáveis<'), primeiro.bloco);
        assert.equal(
            indicesDasTags(html, primeiro.bloco, 'span').length,
            0,
            'a opção fora do trilho inventou um número sem `contagens`',
        );
    });

    await contexto.test('⚠️ status DESCONHECIDO não inventa opção nenhuma (lista fechada de 2)', () => {
        const html = render(Abas, {
            abas: ABAS_6, ativa: 'arquivados', onTrocar: () => {}, contagens: CONTAGENS,
        });
        assert.equal(botoesDoTrilho(html).length, 6, 'uma opção foi inventada para um status desconhecido');
        assert.ok(!html.includes('arquivados'), 'a chave desconhecida vazou para a tela');
        assert.equal((html.match(/aria-selected="true"/g) ?? []).length, 0, 'marcou uma aba que não é o filtro');
    });

    await contexto.test('e quando a aba JÁ EXISTE no trilho, nada é duplicado', () => {
        const html = render(Abas, {
            abas: ABAS_6, ativa: 'todos', onTrocar: () => {}, contagens: CONTAGENS,
        });
        assert.equal(botoesDoTrilho(html).length, 6);
        assert.ok(!html.includes('Acionáveis'), 'opção fora do trilho desenhada sem necessidade');
    });

    await contexto.test('`montarOpcoes` devolve a ordem do servidor, sem reordenar', () => {
        const opcoes = MOD_ABAS.montarOpcoes(ABAS_6, 'pausados', CONTAGENS);
        assert.deepEqual(opcoes.map((o) => o.chave), CHAVES_6);
        assert.deepEqual(opcoes.map((o) => o.ativa), [false, false, true, false, false, false]);
        // A divisória só antes de `rascunhos`.
        assert.deepEqual(opcoes.map((o) => o.separador), [false, false, false, false, false, true]);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. O CONFLITO DO `role="radiogroup"` — DECIDIDO E TRAVADO
//
// O que precisaria estar errado para falhar: o toggle de visão nascer como um
// segundo `role="radiogroup"`. `publicador-paginas-anuncios-render.test.js:354`
// assere `(html.match(/role="radiogroup"/g) ?? []).length === 1` na página de
// Meus Anúncios (o radiogroup das SUB-ABAS), e um segundo reprovaria aquele
// gate — que não é desta fase. A decisão (`role="group"` + `aria-pressed`) está
// escrita na 180-06-SUMMARY para o plano 180-10.
// ═══════════════════════════════════════════════════════════════════════════

test('LIST-06 — ZERO radiogroup novo nos 3 componentes, com as props CHEIAS', async (contexto) => {
    await contexto.test('nenhum dos 3 renders tem role="radiogroup"', () => {
        for (const [nome, html] of [
            ['Busca', HTML_BUSCA_CHEIA],
            ['AbasDeStatus', HTML_ABAS],
            ['LinhaDeControles', HTML_LINHA],
        ]) {
            // ⚠️ Com o `=`: `/radiogroup/` casaria dentro de um comentário ou de
            // um `aria-label`, e a asserção seria vazia.
            assert.equal(
                (html.match(/role="radiogroup"/g) ?? []).length,
                0,
                `${nome} desenhou um radiogroup — reprovaria o gate da página`,
            );
        }
        // ISCA: o detector ACHA o que existe — o toggle tem `role="group"`.
        assert.equal((HTML_LINHA.match(/role="group"/g) ?? []).length, 1);
    });

    await contexto.test('e nenhuma das 3 FONTES escreve radiogroup', () => {
        for (const [nome, fonte] of FONTES) {
            assert.ok(!fonte.includes('radiogroup'), `${nome} escreveu radiogroup`);
        }
    });

    await contexto.test('o toggle marca o estado por aria-pressed, uma só opção', () => {
        assert.equal((HTML_LINHA.match(/aria-pressed="true"/g) ?? []).length, 1);
        assert.equal((HTML_LINHA.match(/aria-pressed="false"/g) ?? []).length, 1);
    });

    await contexto.test('visão "lista" marca a segunda, e visão suja cai no default', () => {
        const lista = render(Linha, { visao: 'lista', onTrocarVisao: () => {} });
        assert.match(lista, /aria-pressed="false"[^>]*>Por produto</);
        assert.match(lista, /aria-pressed="true"[^>]*>Lista</);

        for (const sujo of [null, undefined, '', '   ', 7, {}, [], 'tabela', true]) {
            const html = render(Linha, { visao: sujo, onTrocarVisao: () => {} });
            assert.equal(
                (html.match(/aria-pressed="true"/g) ?? []).length,
                1,
                `visao ${descreve(sujo)} deixou o toggle sem opção marcada`,
            );
            assert.match(html, /aria-pressed="true"[^>]*>Por produto</, `visao ${descreve(sujo)} não caiu no default`);
            assert.doesNotMatch(html, RE_SUJEIRA, `visao ${descreve(sujo)} sujou o HTML`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. EXMP-01 — NADA NASCE DECORATIVO
//
// O que precisaria estar errado para falhar: um "Limpar" que não limpa, um
// botão de aba que não troca nada, ou um trilho vazio desenhado como moldura.
// ═══════════════════════════════════════════════════════════════════════════

test('EXMP-01 — botão sem destino não nasce', async (contexto) => {
    await contexto.test('sem `onLimpar` o "Limpar" não é desenhado (nem desabilitado)', () => {
        const html = render(Busca, { valor: 'poltrona', resumo: { publicacoes: 2, produtos: 1 } });
        assert.ok(html.includes('2 publicações · 1 produto'), 'o resumo some junto, e não devia');
        assert.ok(!html.includes('>Limpar<'), html);
        // ⚠️ Com o `=`: `/disabled/` casaria com `disabled:opacity-40`.
        assert.doesNotMatch(html, /disabled=/);
        // ISCA: com handler ele existe — a negativa não é vazia por construção.
        assert.ok(HTML_BUSCA_CHEIA.includes('>Limpar<'));
    });

    await contexto.test('com busca e SEM resumo: o "Limpar" aparece sozinho (é honesto)', () => {
        assert.ok(HTML_BUSCA_SEM_RESUMO.includes('>Limpar<'), HTML_BUSCA_SEM_RESUMO);
        assert.ok(!HTML_BUSCA_SEM_RESUMO.includes('publicações'), 'resumo inventado sem dado do servidor');
        assert.ok(!HTML_BUSCA_SEM_RESUMO.includes('para buscar'), 'a dica voltou com busca ativa');
    });

    await contexto.test('a CONTAGEM nunca é feita no cliente (D-05)', () => {
        // ⚠️ A primeira forma deste gate era `!fonte.includes('.length')` e ela
        // reprovava o código CERTO: `pedacos.length === 0` e
        // `opcoes.length === 0` são guardas de lista interna, não contagem de
        // dado do servidor. A pergunta "o que precisaria estar errado?" tem uma
        // resposta precisa: um número da TELA sair de um `.length`. Então o
        // gate prova (a) de onde cada número vem e (b) trava a CONTAGEM de
        // `.length` por arquivo — acrescentar um reprova.
        assert.match(FONTE_BUSCA, /pedacoDoResumo\(resumo\.publicacoes/, 'o resumo deixou de vir de `resumo`');
        assert.match(FONTE_BUSCA, /pedacoDoResumo\(resumo\.produtos/);
        assert.match(FONTE_ABAS, />\{item\.total\}</, 'a contagem da aba deixou de vir de `total`');

        for (const [nome, fonte, esperado] of [
            [REL_BUSCA, FONTE_BUSCA, 1],   // `pedacos.length === 0`
            [REL_ABAS, FONTE_ABAS, 1],     // `opcoes.length === 0`
            [REL_LINHA, FONTE_LINHA, 0],
        ]) {
            assert.equal(
                (fonte.match(/\.length/g) ?? []).length,
                esperado,
                `${nome}: mudou a quantidade de \`.length\` — se é contagem de dado do servidor, é D-05 violado`,
            );
        }

        // E nenhum `.length` DENTRO de uma expressão de JSX, que é onde um
        // número contado no cliente apareceria na tela.
        for (const [nome, fonte] of FONTES) {
            assert.doesNotMatch(
                fonte,
                /\{[A-Za-z0-9_.?[\] ]*\.length[A-Za-z0-9_.?[\] ]*\}/,
                `${nome} renderizou um \`.length\` na tela`,
            );
        }
    });

    await contexto.test('trilho sem nenhuma opção NÃO nasce (moldura vazia é ornamento)', () => {
        for (const vazio of [null, undefined, [], 'texto', 7, {}, [null], [{}], [{ chave: 'x' }]]) {
            const html = render(Abas, { abas: vazio, ativa: 'todos', onTrocar: () => {} });
            assert.equal(html, '', `abas ${descreve(vazio)} desenhou um trilho vazio: ${html}`);
        }
        // ISCA: com as 6 ele nasce.
        assert.ok(HTML_ABAS.startsWith('<div role="tablist"'));
    });

    await contexto.test('aba sem rótulo legível não é desenhada (nem como [object Object])', () => {
        const html = render(Abas, {
            abas: [
                { chave: 'todos', rotulo: 'Todas', total: 1, comDot: false },
                { chave: 'quebrada', rotulo: { pt: 'Quebrada' }, total: 2, comDot: false },
                { chave: 'sem_rotulo', total: 3, comDot: false },
            ],
            ativa: 'todos',
            onTrocar: () => {},
        });
        assert.equal(botoesDoTrilho(html).length, 1, html);
        assert.doesNotMatch(html, RE_SUJEIRA);
    });

    await contexto.test('chave repetida entra UMA vez (e não duplica o destino)', () => {
        const html = render(Abas, {
            abas: [
                { chave: 'todos', rotulo: 'Todas', total: 1, comDot: false },
                { chave: 'todos', rotulo: 'Todas de novo', total: 9, comDot: false },
            ],
            ativa: 'todos',
            onTrocar: () => {},
        });
        assert.equal(botoesDoTrilho(html).length, 1, html);
        assert.ok(!html.includes('Todas de novo'), 'a segunda ocorrência da chave foi desenhada');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 9. RENDER ADVERSO — A TELA PRETA DE 07/10/2026
//
// O que precisaria estar errado para falhar: qualquer prop (ou campo de prop)
// chegando como objeto, `NaN`, nulo ou ausente e sendo renderizada como texto.
// Um campo-objeto do presenter derrubou a PÁGINA INTEIRA em 07/10/2026, e na
// Fase 178 grupo nulo derrubava o painel — só o render adverso pegou.
//
// ⚠️ A mensagem de asserção usa `descreve()`, nunca `String(valor)` (que LANÇA
// com `{toString: null}`) nem `JSON.stringify` (que transforma `NaN` em
// `'null'`).
// ═══════════════════════════════════════════════════════════════════════════

/** As 17 formas adversas que já morderam este projeto. */
const FORMAS = [
    null, undefined, '', '   ', 0, 1, -1, NaN, Infinity, true, false,
    {}, { toString: null }, [], [null], 'texto', 7.5,
];

test('REND — render adverso na busca: nada quebra, nada suja a tela', async (contexto) => {
    await contexto.test('`valor` em todas as formas adversas', () => {
        for (const forma of FORMAS) {
            let html;
            assert.doesNotThrow(() => {
                html = render(Busca, { valor: forma, onMudar: () => {}, onLimpar: () => {} });
            }, `valor ${descreve(forma)} derrubou o render`);
            assert.doesNotMatch(html, RE_SUJEIRA, `valor ${descreve(forma)} sujou o HTML`);
            assert.ok(html.includes('<input'), `valor ${descreve(forma)} apagou o input`);
        }
    });

    await contexto.test('`resumo` em todas as formas adversas', () => {
        for (const forma of FORMAS) {
            let html;
            assert.doesNotThrow(() => {
                html = render(Busca, { valor: 'x', onLimpar: () => {}, resumo: forma });
            }, `resumo ${descreve(forma)} derrubou o render`);
            assert.doesNotMatch(html, RE_SUJEIRA, `resumo ${descreve(forma)} sujou o HTML`);
        }
    });

    await contexto.test('cada CAMPO de `resumo` em todas as formas adversas', () => {
        for (const campo of ['publicacoes', 'produtos']) {
            for (const forma of FORMAS) {
                let html;
                assert.doesNotThrow(() => {
                    html = render(Busca, { valor: 'x', onLimpar: () => {}, resumo: { [campo]: forma } });
                }, `resumo.${campo} = ${descreve(forma)} derrubou o render`);
                assert.doesNotMatch(html, RE_SUJEIRA, `resumo.${campo} = ${descreve(forma)} sujou o HTML`);
            }
        }
    });

    await contexto.test('⚠️ resumo.publicacoes = NaN NÃO imprime "NaN"', () => {
        const html = render(Busca, {
            valor: 'x', onLimpar: () => {}, resumo: { publicacoes: NaN, produtos: 3 },
        });
        assert.ok(!html.includes('NaN'), html);
        // E o pedaço que FOI medido continua aparecendo, sem separador sobrando.
        assert.ok(html.includes('3 produtos'), html);
        assert.ok(!html.includes('· 3 produtos'), 'separador sobrando com um pedaço ausente');
    });

    await contexto.test('chave desconhecida no resumo não apaga o que existe', () => {
        const html = render(Busca, {
            valor: 'x', onLimpar: () => {}, resumo: { publicacoes: 2, produtos: 1, ruido: { a: 1 } },
        });
        assert.ok(html.includes('2 publicações · 1 produto'), html);
        assert.doesNotMatch(html, RE_SUJEIRA);
    });

    await contexto.test('`onMudar`/`onLimpar`/`onTecla`/`refDoInput` adversos', () => {
        for (const prop of ['onMudar', 'onLimpar', 'onTecla', 'refDoInput']) {
            for (const forma of FORMAS) {
                let html;
                assert.doesNotThrow(() => {
                    html = render(Busca, { valor: 'x', [prop]: forma });
                }, `${prop} = ${descreve(forma)} derrubou o render`);
                assert.doesNotMatch(html, RE_SUJEIRA, `${prop} = ${descreve(forma)} sujou o HTML`);
            }
        }
        // ⚠️ `ref` de STRING foi removido no React 18 e LANÇA. A guarda existe
        // por isso, e a linha acima a exercita com `'texto'` e `''`.
    });

    await contexto.test('a prop INTEIRA nula (recarga parcial do Inertia)', () => {
        let html;
        assert.doesNotThrow(() => { html = render(Busca, null); });
        assert.ok(html.includes('<input'), html);
        assert.doesNotMatch(html, RE_SUJEIRA);
    });
});

test('REND — render adverso no trilho e na linha de controles', async (contexto) => {
    await contexto.test('`abas` em todas as formas adversas', () => {
        for (const forma of FORMAS) {
            let html;
            assert.doesNotThrow(() => {
                html = render(Abas, { abas: forma, ativa: 'todos', onTrocar: () => {} });
            }, `abas ${descreve(forma)} derrubou o render`);
            assert.doesNotMatch(html, RE_SUJEIRA, `abas ${descreve(forma)} sujou o HTML`);
        }
    });

    await contexto.test('`abas` como array de formas adversas', () => {
        for (const forma of FORMAS) {
            let html;
            assert.doesNotThrow(() => {
                html = render(Abas, { abas: [forma, forma], ativa: 'todos', onTrocar: () => {} });
            }, `abas [${descreve(forma)}] derrubou o render`);
            assert.doesNotMatch(html, RE_SUJEIRA, `abas [${descreve(forma)}] sujou o HTML`);
        }
    });

    await contexto.test('cada CAMPO de uma aba em todas as formas adversas', () => {
        for (const campo of ['chave', 'rotulo', 'total', 'comDot']) {
            for (const forma of FORMAS) {
                const abas = ABAS_6.map((aba, i) => (i === 3 ? { ...aba, [campo]: forma } : aba));
                let html;
                assert.doesNotThrow(() => {
                    html = render(Abas, { abas, ativa: 'todos', onTrocar: () => {} });
                }, `aba.${campo} = ${descreve(forma)} derrubou o render`);
                assert.doesNotMatch(html, RE_SUJEIRA, `aba.${campo} = ${descreve(forma)} sujou o HTML`);
            }
        }
    });

    await contexto.test('`ativa`, `contagens` e `onTrocar` adversos', () => {
        for (const prop of ['ativa', 'contagens', 'onTrocar']) {
            for (const forma of FORMAS) {
                let html;
                assert.doesNotThrow(() => {
                    html = render(Abas, { abas: ABAS_6, ativa: 'todos', onTrocar: () => {}, [prop]: forma });
                }, `${prop} = ${descreve(forma)} derrubou o render`);
                assert.doesNotMatch(html, RE_SUJEIRA, `${prop} = ${descreve(forma)} sujou o HTML`);
            }
        }
    });

    await contexto.test('`contagens` com valor sujo na chave fora do trilho', () => {
        for (const forma of FORMAS) {
            let html;
            assert.doesNotThrow(() => {
                html = render(Abas, {
                    abas: ABAS_6, ativa: 'acionaveis', onTrocar: () => {}, contagens: { acionaveis: forma },
                });
            }, `contagens.acionaveis = ${descreve(forma)} derrubou o render`);
            assert.doesNotMatch(html, RE_SUJEIRA, `contagens.acionaveis = ${descreve(forma)} sujou o HTML`);
            assert.ok(html.includes('>Acionáveis<'), 'a opção fora do trilho sumiu');
        }
    });

    await contexto.test('chave desconhecida numa aba não apaga a aba', () => {
        const abas = ABAS_6.map((aba, i) => (i === 0 ? { ...aba, ruido: { a: 1 }, severidade: NaN } : aba));
        const html = render(Abas, { abas, ativa: 'todos', onTrocar: () => {} });
        assert.equal(botoesDoTrilho(html).length, 6);
        assert.doesNotMatch(html, RE_SUJEIRA);
    });

    await contexto.test('a prop INTEIRA nula nos dois componentes', () => {
        for (const [nome, Componente] of [['AbasDeStatus', Abas], ['LinhaDeControles', Linha]]) {
            let html;
            assert.doesNotThrow(() => { html = render(Componente, null); }, `${nome} com props nula`);
            assert.doesNotMatch(html, RE_SUJEIRA, `${nome} sujou o HTML com props nula`);
        }
    });

    await contexto.test('`abas` da linha (ReactNode) em todas as formas adversas', () => {
        for (const forma of FORMAS) {
            let html;
            assert.doesNotThrow(() => {
                html = render(Linha, { abas: forma, visao: 'grupo', onTrocarVisao: () => {} });
            }, `abas(ReactNode) ${descreve(forma)} derrubou o render`);
            assert.doesNotMatch(html, RE_SUJEIRA, `abas(ReactNode) ${descreve(forma)} sujou o HTML`);
            // O toggle continua, sempre: ele não depende das abas.
            assert.ok(html.includes('>Por produto<'), 'o toggle sumiu junto com as abas');
        }
    });

    await contexto.test('`ordenacaoRotulo` e `onTrocarVisao` adversos', () => {
        for (const prop of ['ordenacaoRotulo', 'onTrocarVisao']) {
            for (const forma of FORMAS) {
                let html;
                assert.doesNotThrow(() => {
                    html = render(Linha, { visao: 'grupo', [prop]: forma });
                }, `${prop} = ${descreve(forma)} derrubou o render`);
                assert.doesNotMatch(html, RE_SUJEIRA, `${prop} = ${descreve(forma)} sujou o HTML`);
            }
        }
    });

    await contexto.test('⚠️ ISCA do detector de sujeira: ele PEGA [object Object] e NaN', () => {
        // Sem esta isca, todo `doesNotMatch(RE_SUJEIRA, …)` acima poderia estar
        // passando por o regex nunca casar com nada.
        assert.match('<span>[object Object]</span>', RE_SUJEIRA);
        assert.match('<span>NaN publicações</span>', RE_SUJEIRA);
        assert.match('<span>undefined</span>', RE_SUJEIRA);
        assert.match('<span>Invalid Date</span>', RE_SUJEIRA);
        assert.doesNotMatch('<span>2 publicações · 1 produto</span>', RE_SUJEIRA);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 10. D-11 E A REGRA DO ROLLUP — O QUE A FONTE NÃO PODE TER
//
// O que precisaria estar errado para falhar: um `router.*`/`fetch` nascer num
// destes 3 arquivos (D-11: a tela é só leitura e o único `router.post` é o do
// "Atualizar", na página); um `.map()` com JSX INLINE (o Rollup elimina
// variável de escopo do componente lida dentro do callback, e isso vira
// `ReferenceError` SÓ no bundle de produção); ou hex literal em vez de token.
// ═══════════════════════════════════════════════════════════════════════════

test('D-11 / REND-04 — o que as 3 fontes NÃO podem ter', async (contexto) => {
    for (const [nome, fonte] of FONTES) {
        await contexto.test(`${nome} — zero router.*, axios, fetch e import do Inertia`, () => {
            assert.ok(!fonte.includes('router.'), `${nome} chamou router.*`);
            assert.ok(!fonte.includes('axios'), `${nome} chamou axios`);
            assert.doesNotMatch(fonte, /\bfetch\(/, `${nome} chamou fetch(`);
            assert.doesNotMatch(fonte, /from '@inertiajs\/react'/, `${nome} importou o Inertia`);
        });

        await contexto.test(`${nome} — zero data no cliente e zero listener global`, () => {
            assert.doesNotMatch(fonte, /new Date\(/, `${nome} montou data no cliente`);
            assert.doesNotMatch(fonte, /Date\.now\(/);
            assert.doesNotMatch(fonte, /toLocale/);
            // O atalho "/" é do plano 180-11, não daqui.
            assert.doesNotMatch(fonte, /addEventListener/, `${nome} registrou listener global`);
        });

        await contexto.test(`${nome} — nenhum String(valor) e nenhum JSON.stringify`, () => {
            // `String({toString: null})` LANÇA; `JSON.stringify(NaN)` vira 'null'.
            assert.doesNotMatch(fonte, /\bString\(/, `${nome} usou String(valor)`);
            assert.doesNotMatch(fonte, /JSON\.stringify/, `${nome} usou JSON.stringify`);
        });

        await contexto.test(`${nome} — nenhum hex literal (TOK-15)`, () => {
            assert.doesNotMatch(fonte, /#[0-9a-fA-F]{3,8}\b/, `${nome} escreveu hex em vez de token`);
        });
    }

    await contexto.test('REND-04 — o `.map()` chama helper, NUNCA JSX inline', () => {
        // ⚠️ É o gate estrutural contra a armadilha do Rollup. JSX inline dentro
        // do callback é exatamente a forma que já produziu ReferenceError em
        // tela neste projeto.
        assert.match(FONTE_ABAS, /\{opcoes\.map\(\(item\) => opcao\(item\)\)\}/);
        assert.match(FONTE_LINHA, /\{OPCOES_DE_VISAO\.map\(\(item\) => opcaoDeVisao\(item\)\)\}/);
    });

    await contexto.test('os 6 botões saem de UM helper (nunca 6 blocos copiados)', () => {
        assert.equal(
            (FONTE_ABAS.match(/<button/g) ?? []).length,
            1,
            'AbasDeStatus tem mais de um `<button` — as 6 abas começariam a divergir de aparência',
        );
        assert.equal((FONTE_LINHA.match(/<button/g) ?? []).length, 1);
    });

    await contexto.test('o trilho tem UMA casa: a classe é importada, não copiada', () => {
        assert.match(FONTE_LINHA, /from '\.\/AbasDeStatus'/);
        assert.ok(
            !FONTE_LINHA.includes('bg-pub-hover-2'),
            'LinhaDeControles copiou a classe do trilho em vez de importar CLASSE_DO_TRILHO',
        );
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 11. FIDELIDADE PELO CSS CONSTRUÍDO — presença de classe NÃO é fidelidade
//
// O que precisaria estar errado para falhar: a classe estar presente com o
// VALOR errado (o token `pub-hover-2` apontando para outro cinza, o raio 14 do
// `pub-table` mudando, a opacidade 0.18 arredondando diferente) — nada disso um
// gate de presença pega.
//
// ⚠️ As SETE armadilhas do medidor, todas aplicadas abaixo. E a OITAVA, medida
// NESTA rodada: o plano mandava "ler o hex de 8 dígitos e conferir o byte do
// alfa" no ring, e o Tailwind NÃO emite hex aqui — emite
// `--tw-ring-color: rgb(250 204 21 / .18)`, decimal e COM o zero da frente
// comido pelo cssnano. É a mesma família da armadilha (iv), num lugar novo.
// ═══════════════════════════════════════════════════════════════════════════

/** Escapa TODOS os metacaracteres de regex (armadilha vii). */
function escapaRegex(texto) {
    return texto.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** O seletor que o Tailwind emite: `.` + o nome com os metacaracteres de CSS escapados (v/vi). */
function seletorDe(classe) {
    return `.${classe.replace(/[[\].:/%,]/g, (ch) => `\\${ch}`)}`;
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

    // ⚠️ O CSS tem de ser mais NOVO que os componentes, senão este bloco mediria
    // um build velho e reprovaria o código certo (ou aprovaria o errado).
    const mtimeCss = fs.statSync(cssAbs).mtimeMs;
    const mtimeFontes = Math.max(
        ...[REL_BUSCA, REL_ABAS, REL_LINHA].map((rel) => fs.statSync(path.resolve(RAIZ, rel)).mtimeMs),
    );
    if (mtimeCss < mtimeFontes) {
        return { motivo: `o CSS construído (${entrada.css[0]}) é mais antigo que os componentes — rode \`npm run build\`` };
    }

    return { arquivo: entrada.css[0], css: fs.readFileSync(cssAbs, 'utf8') };
}

const CSS = carregarCssConstruido();

test('FID — as classes GERAM CSS com o valor certo (não só existem)', async (contexto) => {
    if (CSS.motivo) {
        // ⚠️ `skip` com razão ESCRITA (e `skipped N` aparece no sumário do
        // `node --test`), em vez de `return` calado.
        contexto.skip(CSS.motivo);
        return;
    }

    /** O corpo da regra da classe, ou `null`. */
    const regraDe = (classe) => {
        const re = new RegExp(`${escapaRegex(seletorDe(classe))}(?![\\w\\\\-])[^{}]*\\{([^}]*)\\}`);
        const m = CSS.css.match(re);
        return m ? m[1] : null;
    };

    await contexto.test('o CSS lido é o do manifest e tem tamanho de CSS de verdade', () => {
        assert.ok(CSS.css.length > 100000, `CSS suspeito de truncado: ${CSS.css.length} bytes`);
    });

    await contexto.test('⚠️ ISCA: a sonda reporta AUSENTE o que não existe', () => {
        // As duas iscas foram medidas como inexistentes no repo inteiro
        // (`grep -rn` em `resources/`, `app/`, `tests/`, `tailwind.config.js`)
        // antes de serem usadas — é a lição do `w-[13px]`, que parecia
        // inventado e saiu presente.
        for (const isca of ['bg-pub-isca-6k2', 'text-[97.5px]']) {
            assert.equal(regraDe(isca), null, `a isca ${isca} virou classe de verdade — troque a isca`);
            assert.ok(!CSS.css.includes(isca), `a string ${isca} apareceu no CSS`);
        }
        // E o controle: a sonda ACHA o que existe.
        assert.notEqual(regraDe('h-[54px]'), null, 'a sonda está cega: nem o controle ela acha');
    });

    await contexto.test('a busca: 54px, raio 14, 15.5px e os dois paddings', () => {
        assert.match(regraDe('h-[54px]'), /height:\s*54px/);
        assert.match(regraDe('rounded-pub-table'), /border-radius:\s*14px/);
        assert.match(regraDe('text-[15.5px]'), /font-size:\s*15\.5px/);
        assert.match(regraDe('pl-[50px]'), /padding-left:\s*50px/);
        assert.match(regraDe('pr-[220px]'), /padding-right:\s*220px/);
    });

    await contexto.test('⚠️ o foco: ring de 3px E o alfa 0.18 (o byte que distingue de 0.2)', () => {
        // (iii)/(iv) na forma MEDIDA nesta rodada: aqui NÃO sai hex de 8
        // dígitos — sai decimal, e o cssnano come o zero da frente.
        const ring = regraDe('focus:ring-pub-accent/[0.18]');
        assert.notEqual(ring, null, 'a regra do ring do foco não existe no CSS');
        assert.match(ring, /--tw-ring-color:\s*rgb\(250 204 21 \/ \.18\)/, ring);
        assert.ok(!/\/ \.2\)/.test(ring), `o alfa virou 0.2: ${ring}`);
        assert.ok(!/\/ \.15\)/.test(ring), `o alfa virou 0.15: ${ring}`);

        // E os 3px do anel, com o prefixo da variante no nome da classe (vi).
        assert.match(regraDe('focus:ring-[3px]'), /0 0 0 calc\(3px \+ var\(--tw-ring-offset-width\)\)/);
        // (i) token sólido sai DECIMAL, nunca hex.
        assert.match(regraDe('focus:border-pub-accent'), /border-color:\s*rgb\(250 204 21/);
    });

    await contexto.test('(ii) stroke sólido SAI EM HEX LITERAL — a lupa é #A1A1AA', () => {
        assert.match(regraDe('stroke-pub-neutral-txt'), /stroke:\s*#a1a1aa/i);
    });

    await contexto.test('o segmentado: #161616, raio 10, 3px, e o inset da opção ativa', () => {
        // (i) token sólido em DECIMAL.
        assert.match(regraDe('bg-pub-hover-2'), /background-color:\s*rgb\(22 22 22/);
        assert.match(regraDe('rounded-pub-sub'), /border-radius:\s*10px/);
        assert.match(regraDe('p-[3px]'), /padding:\s*3px/);
        assert.match(regraDe('rounded-pub-control-sm'), /border-radius:\s*7px/);
        assert.match(regraDe('bg-pub-seg'), /background-color:\s*rgb\(38 38 38/);
        assert.match(regraDe('shadow-pub-seg-ativo'), /--tw-shadow:\s*inset 0 0 0 1px #333333/);
        assert.match(regraDe('bg-pub-danger'), /background-color:\s*rgb\(239 68 68/);
    });

    await contexto.test('a tecla "/": 11.5px, raio 5 e o border-bottom de 2px', () => {
        // (v) o seletor vem ESCAPADO (`.text-\[11\.5px\]`) e (vii) escapar só a
        // barra transformaria os colchetes em classe de caracteres.
        assert.match(regraDe('text-[11.5px]'), /font-size:\s*11\.5px/);
        assert.match(regraDe('rounded-[5px]'), /border-radius:\s*5px/);
        assert.match(regraDe('border-b-2'), /border-bottom-width:\s*2px/);
        assert.match(regraDe('font-pub-mono'), /font-family:\s*Geist Mono/);
    });

    await contexto.test('⚠️ substring não prova medida: 11.5 ≠ 11, 12.5 ≠ 12', () => {
        // `text-[11px]` casa DENTRO de `text-[11.5px]` numa busca de substring.
        // Aqui a regra de cada um é lida e comparada por igualdade do valor.
        assert.match(regraDe('text-[12px]'), /font-size:\s*12px/);
        assert.match(regraDe('text-[12.5px]'), /font-size:\s*12\.5px/);
        assert.ok(!/font-size:\s*12\.5px/.test(regraDe('text-[12px]')));
        assert.ok(!/font-size:\s*12px;/.test(regraDe('text-[12.5px]')));
    });
});
