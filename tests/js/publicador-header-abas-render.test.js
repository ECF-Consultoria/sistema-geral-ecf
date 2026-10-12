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
// Fase 177, plano 05 — render REAL (esbuild + react-dom/server) das abas-pílula
// da linha 2 do `PublicadorHeader`.
//
// POR QUE ESTE GATE EXISTE:
//   1. REND-01/02 — estas abas são a navegação do módulo inteiro: vivem nas 9
//      páginas do Publicador. Um erro aqui aparece nas nove de uma vez. A tela
//      preta de 07/10 começou exatamente assim (campo-objeto vindo do servidor
//      renderizado como texto), por isso `contadores` é exercitado completo,
//      NULO, ausente e com campo objeto.
//   2. HEAD-09 — são QUATRO abas de propósito. O README e o protótipo têm
//      CINCO (com "Desempenho"). Quem reimplementar lendo só o README vai
//      "consertar" para cinco; este arquivo reprova isso, no HTML e na FONTE.
//   3. HEAD-10 — nenhum número fixo no JSX. O protótipo traz `32` e `58`
//      cravados (são mock); aqui todo contador vem do servidor.
//   4. T-177-18 — `null` não é `0`. Contador nulo significa "o acervo nunca
//      foi coletado". Escrever "0" afirmaria "esta conta não tem publicação".
//
// ⚠️ REND-03: TODOS os bundles são montados ANTES do primeiro `test()` — o
// `after()` do `node --test` já apagou o stub no meio de um arquivo e matou a
// suíte inteira sem nenhum teste falhar.
// ⚠️ GATE-05: `/disabled=/` e `/aria-disabled="true"/` — com o `=`. `/disabled/`
// casaria a palavra dentro de qualquer classe e seria asserção vazia.
// ⚠️ A asserção de fidelidade é POR ELEMENTO (tag de abertura extraída +
// lista de classes + contagem exata). No plano 177-04, 4 de 22 mutações
// passaram justamente porque `html.includes('classe')` não prova nada quando
// vários elementos compartilham a classe — e aqui as quatro pílulas
// compartilham TODAS as classes da base.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

// Stub de route() global — o helper Ziggy não existe no node, e o destino só
// precisa ser verificável.
global.route = (nome, params) => '/' + nome + JSON.stringify(params ?? {});

// `@inertiajs/react` real traz `qs`/`object-inspect`, com `require('util')`
// condicional incompatível com o bundle ESM do esbuild. Aqui só `Link` importa
// (renderiza `<a>`).
const STUB_INERTIA = path.join(__dirname, `.header-abas-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, reload: () => {} };
`, 'utf8');
after(() => fs.rmSync(STUB_INERTIA, { force: true }));

/** Compila o módulo de verdade (JSX + imports reais) e devolve os exports. */
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
        // `textoSeguro` vem de `BarraDaConta.jsx`, que arrasta Radix e axios
        // pela árvore do `SeletorEmpresaBusca`.
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/react-popover'],
    });

    // Precisa viver DENTRO da árvore do projeto — import ESM fora dela não acha node_modules.
    const outfile = path.join(__dirname, `.header-abas-render-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

// ─── REND-03: tudo montado aqui, no topo do módulo ───
const { default: AbasDoHeader, ABAS } = await montarModulo('resources/js/Components/Mlb/Publicador/header/AbasDoHeader.jsx');
const FONTE = lerSemComentarios('resources/js/Components/Mlb/Publicador/header/AbasDoHeader.jsx');

const CONTA = 'company-459';
const COMPANY = 459;
const CONTADORES_CHEIOS = { produtos: 32, publicacoes: 58, publicacoes_pedem_acao: 3, alavancas: 2 };

const renderizar = (props = {}) => renderToStaticMarkup(React.createElement(AbasDoHeader, {
    aba: 'produtos', conta: CONTA, companyId: COMPANY, contadores: CONTADORES_CHEIOS, ...props,
}));

const HTML_CHEIO = renderizar();

/**
 * Extrai a tag de ABERTURA de um elemento. A asserção de fidelidade tem de ser
 * por elemento: as quatro pílulas compartilham a base inteira, então
 * `html.includes('h-[34px]')` continuaria verdadeiro com uma delas quebrada.
 */
function tagDe(html, padrao, rotulo) {
    const achado = html.match(padrao);
    assert.ok(achado, `elemento "${rotulo}" não encontrado no HTML`);
    return achado[0];
}

function exigirClasses(tag, classes, rotulo) {
    for (const classe of classes) {
        assert.ok(tag.includes(classe), `${rotulo}: faltou "${classe}". Renderizado: ${tag}`);
    }
}

function proibirClasses(tag, classes, rotulo) {
    for (const classe of classes) {
        assert.ok(!tag.includes(classe), `${rotulo}: não deveria ter "${classe}". Renderizado: ${tag}`);
    }
}

const contar = (html, agulha) => html.split(agulha).length - 1;

/**
 * Parte o HTML nas quatro pílulas, em ordem de documento. Cada pedaço contém
 * UMA pílula inteira (a habilitada é `<a>`, a de D23 é `<span role="link">`),
 * para as asserções do contador, do dot e da barra serem feitas DENTRO da
 * pílula certa e não "em algum lugar do HTML".
 */
function pilulas(html) {
    const interno = html.replace(/^<div[^>]*>/, '').replace(/<\/div>$/, '');
    return interno.split(/(?=<a href="\/mlb\.|<span role="link")/).filter((p) => p.trim() !== '');
}

const CLASSES_BASE = [
    'relative', 'flex', 'h-[34px]', 'items-center', 'gap-2', 'whitespace-nowrap',
    'rounded-pub-control', 'px-[12px]', 'text-[13.5px]',
    'transition-[background-color,color]', 'duration-150',
];

const BARRA_ATIVA = '<span aria-hidden="true" class="absolute bottom-[-7px] left-[12px] right-[12px] h-[2px] rounded-[2px] bg-pub-accent">';

// ═══════════════════════════════════════════════════════════════════════════
// 1. As quatro abas, a ordem e as rotas
// ═══════════════════════════════════════════════════════════════════════════

test('AbasDoHeader — HEAD-09: são QUATRO abas, nesta ordem', async (contexto) => {
    await contexto.test('ABAS tem exatamente 4 itens, nas chaves e na ordem do plano', () => {
        assert.equal(ABAS.length, 4);
        assert.deepEqual(ABAS.map((a) => a.chave), ['visao-geral', 'produtos', 'publicacoes', 'alavancas']);
        assert.deepEqual(ABAS.map((a) => a.rotulo), ['Visão geral', 'Produtos', 'Publicações', 'Alavancas']);
    });

    await contexto.test('as rotas são as MESMAS de AbasDaConta.jsx (REG-05: nenhuma nova, nenhuma removida)', () => {
        assert.deepEqual(ABAS.map((a) => a.rota), [
            'mlb.anuncios.publicador.visao-geral',
            'mlb.anuncios.publicador.produtos',
            'mlb.anuncios.meus',
            'mlb.anuncios.publicador.alavancas.index',
        ]);
    });

    await contexto.test('cada aba tem um ícone de componente (nenhum SVG cru — TOK-14)', () => {
        for (const item of ABAS) {
            assert.ok(item.icone, `a aba ${item.chave} está sem ícone`);
        }
        assert.doesNotMatch(FONTE, /<svg/, 'ícone copiado como SVG cru: o Lucide tem equivalente para os 4');
    });

    await contexto.test('o HTML traz os 4 rótulos, nesta ordem de documento', () => {
        for (const rotulo of ['Visão geral', 'Produtos', 'Publicações', 'Alavancas']) {
            assert.ok(HTML_CHEIO.includes(rotulo), `o rótulo "${rotulo}" não foi renderizado`);
        }
        const posicao = (r) => HTML_CHEIO.indexOf(r);
        assert.ok(posicao('Visão geral') < posicao('Produtos'), 'Visão geral deveria vir antes de Produtos');
        assert.ok(posicao('Produtos') < posicao('Publicações'), 'Produtos deveria vir antes de Publicações');
        assert.ok(posicao('Publicações') < posicao('Alavancas'), 'Publicações deveria vir antes de Alavancas');
    });

    await contexto.test('exatamente 4 pílulas, cada uma com um destino real', () => {
        assert.equal(pilulas(HTML_CHEIO).length, 4);
        assert.equal(contar(HTML_CHEIO, 'h-[34px]'), 4, 'as 4 pílulas têm 34px de altura');
        assert.equal(contar(HTML_CHEIO, '<a href="/mlb.'), 4, 'com conta e Company, as 4 abas são links de verdade');
    });

    await contexto.test('as 4 rotas aparecem no href com os parâmetros certos', () => {
        assert.match(HTML_CHEIO, /href="\/mlb\.anuncios\.publicador\.visao-geral\{&quot;conta&quot;:&quot;company-459&quot;\}"/);
        assert.match(HTML_CHEIO, /href="\/mlb\.anuncios\.publicador\.produtos\{&quot;conta&quot;:&quot;company-459&quot;\}"/);
        // ⚠️ Publicações é a única parametrizada por `company`, não por `conta`.
        assert.match(HTML_CHEIO, /href="\/mlb\.anuncios\.meus\{&quot;company&quot;:459\}"/);
        assert.match(HTML_CHEIO, /href="\/mlb\.anuncios\.publicador\.alavancas\.index\{&quot;conta&quot;:&quot;company-459&quot;\}"/);
    });
});

test('AbasDoHeader — HEAD-09: a aba "Desempenho" NÃO existe (desvio deliberado)', async (contexto) => {
    // ⚠️⚠️ ESTE É O TESTE QUE IMPEDE ALGUÉM "CORRIGIR" O HEADER PARA CINCO ABAS
    // lendo só o README do protótipo. O README §0 e o array `T` do
    // `PublicadorHeader.dc.html` têm CINCO abas; a quinta ficou FORA por
    // decisão do usuário em 11/10/2026 ("não mostre o link da tela desempenho
    // por enquanto"), porque a tela não existe e aba sem destino é link morto.
    // A aba volta com a Fase 182 — e então este teste muda COM ela.
    await contexto.test('nenhuma aba Desempenho no HTML', () => {
        assert.doesNotMatch(HTML_CHEIO, /Desempenho/);
    });

    await contexto.test('nenhuma chave "desempenho" na FONTE do componente', () => {
        assert.doesNotMatch(FONTE, /'desempenho'/);
        assert.doesNotMatch(FONTE, /"desempenho"/);
        assert.equal(ABAS.filter((a) => /desempenho/i.test(a.chave) || /Desempenho/.test(a.rotulo)).length, 0);
    });

    await contexto.test('HEAD-08: nenhuma aba Configurações na linha 2 (virou ícone da linha 1)', () => {
        assert.doesNotMatch(HTML_CHEIO, /Configura/);
        assert.equal(ABAS.filter((a) => a.chave === 'configuracoes').length, 0);
        assert.doesNotMatch(FONTE, /'configuracoes'/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. Contadores — todos do servidor, e `null` nunca vira `0`
// ═══════════════════════════════════════════════════════════════════════════

test('AbasDoHeader — HEAD-10: contadores 100% do servidor', async (contexto) => {
    await contexto.test('nenhum número fixo no JSX (o protótipo crava 32 e 58; aqui não)', () => {
        assert.doesNotMatch(FONTE, />\s*\d{1,4}\s*</, 'número cravado entre tags no JSX');
        assert.match(FONTE, /contadores/, 'o componente deixou de ler `contadores`');
    });

    await contexto.test('32 em Produtos, 58 em Publicações e 2 em Alavancas — cada um na SUA pílula', () => {
        const [visaoGeral, produtos, publicacoes, alavancas] = pilulas(HTML_CHEIO);
        assert.ok(produtos.includes('>32</span>'), `Produtos deveria mostrar 32: ${produtos}`);
        assert.ok(publicacoes.includes('>58</span>'), `Publicações deveria mostrar 58: ${publicacoes}`);
        assert.ok(alavancas.includes('>2</span>'), `Alavancas deveria mostrar 2: ${alavancas}`);
        // Visão geral NUNCA tem contador (nem no protótipo).
        assert.ok(!visaoGeral.includes('min-w-[22px]'), `Visão geral não deveria ter pílula de contador: ${visaoGeral}`);
        assert.equal(contar(HTML_CHEIO, 'min-w-[22px]'), 3, 'exatamente 3 contadores: produtos, publicações e alavancas');
    });

    await contexto.test('o badge de Alavancas mantém o title literal de hoje (REG-01)', () => {
        const [, , , alavancas] = pilulas(HTML_CHEIO);
        assert.match(alavancas, /title="2 publicados aguardando alavancas"/);

        const umSo = renderizar({ contadores: { ...CONTADORES_CHEIOS, alavancas: 1 } });
        assert.match(umSo, /title="1 publicado aguardando alavancas"/, 'o singular do title de hoje se perdeu');
    });

    await contexto.test('alavancas = 0 — nenhuma pílula (badge opcional, como hoje)', () => {
        const html = renderizar({ contadores: { ...CONTADORES_CHEIOS, alavancas: 0 } });
        const [, , , alavancas] = pilulas(html);
        assert.ok(!alavancas.includes('min-w-[22px]'), `alavancas=0 não deveria desenhar badge: ${alavancas}`);
        assert.equal(contar(html, 'min-w-[22px]'), 2, 'sobram só produtos e publicações');
        assert.ok(!alavancas.includes('>0<'), 'nunca escrever "0" em Alavancas');
    });

    await contexto.test('T-177-18: publicacoes = null (acervo nunca coletado) — SEM pílula e SEM "0"', () => {
        const html = renderizar({ contadores: { produtos: 32, publicacoes: null, publicacoes_pedem_acao: null, alavancas: 2 } });
        const [, produtos, publicacoes] = pilulas(html);
        assert.ok(!publicacoes.includes('min-w-[22px]'), `publicacoes=null não deveria desenhar pílula: ${publicacoes}`);
        assert.ok(!publicacoes.includes('>0<'), 'null não é zero: "0" afirmaria que a conta não tem publicação');
        assert.doesNotMatch(html, /undefined/);
        assert.doesNotMatch(html, /NaN/);
        // E Produtos continua mostrando o dele: um nulo não apaga o outro.
        assert.ok(produtos.includes('>32</span>'));
    });

    await contexto.test('publicacoes = 0 — aí SIM a pílula com 0 (medido e vazio é diferente de não medido)', () => {
        const html = renderizar({ contadores: { produtos: 0, publicacoes: 0, publicacoes_pedem_acao: 0, alavancas: 0 } });
        const [, produtos, publicacoes] = pilulas(html);
        assert.ok(produtos.includes('>0</span>'), `produtos=0 é medição válida: ${produtos}`);
        assert.ok(publicacoes.includes('>0</span>'), `publicacoes=0 é medição válida: ${publicacoes}`);
    });

    await contexto.test('contadores = null — nenhuma pílula, nenhum crash', () => {
        let html;
        assert.doesNotThrow(() => { html = renderizar({ contadores: null }); });
        assert.equal(contar(html, 'min-w-[22px]'), 0);
        assert.doesNotMatch(html, /undefined/);
        assert.doesNotMatch(html, /NaN/);
        assert.doesNotMatch(html, /\[object Object\]/);
        // As 4 abas continuam no lugar: sem contador não é sem aba.
        assert.equal(pilulas(html).length, 4);
    });

    await contexto.test('contadores ausente (nem a prop chega) — nenhuma pílula, nenhum crash', () => {
        let html;
        assert.doesNotThrow(() => {
            html = renderToStaticMarkup(React.createElement(AbasDoHeader, { aba: 'produtos', conta: CONTA, companyId: COMPANY }));
        });
        assert.equal(contar(html, 'min-w-[22px]'), 0);
        assert.doesNotMatch(html, /undefined/);
        assert.equal(pilulas(html).length, 4);
    });
});

test('AbasDoHeader — REND-01: campo do servidor em formato inesperado', async (contexto) => {
    // ⚠️ A tela preta de 07/10 foi isto: campo-objeto renderizado como texto.
    for (const [rotulo, valor] of [
        ['objeto', { total: 32 }],
        ['texto', '32'],
        ['nulo', null],
        ['ausente', undefined],
        ['booleano', true],
        ['NaN', Number.NaN],
        ['lista', [1, 2]],
    ]) {
        await contexto.test(`contadores.produtos ${rotulo} — nunca [object Object], nunca NaN`, () => {
            let html;
            assert.doesNotThrow(() => { html = renderizar({ contadores: { ...CONTADORES_CHEIOS, produtos: valor } }); });
            assert.doesNotMatch(html, /\[object Object\]/);
            assert.doesNotMatch(html, /NaN/);
            assert.doesNotMatch(html, /undefined/);
            assert.equal(pilulas(html).length, 4, 'as 4 abas têm de sobreviver a um contador estragado');
        });
    }

    await contexto.test('contadores inteiro em formato errado (texto, lista) — nenhuma pílula, nenhum crash', () => {
        for (const valor of ['32', [1, 2], 7, true]) {
            let html;
            assert.doesNotThrow(() => { html = renderizar({ contadores: valor }); });
            assert.doesNotMatch(html, /\[object Object\]/);
            assert.doesNotMatch(html, /NaN/);
            assert.equal(pilulas(html).length, 4);
        }
    });

    await contexto.test('publicacoes_pedem_acao objeto/texto/NaN — nenhum dot inventado', () => {
        for (const valor of [{ n: 3 }, '3', Number.NaN, null, undefined]) {
            const html = renderizar({ contadores: { ...CONTADORES_CHEIOS, publicacoes_pedem_acao: valor } });
            assert.equal(contar(html, 'bg-pub-danger'), 0, `pedem_acao=${JSON.stringify(valor)} não deveria desenhar dot`);
            assert.doesNotMatch(html, /\[object Object\]/);
            assert.doesNotMatch(html, /NaN/);
        }
    });

    await contexto.test('companyId objeto/NaN — cai no D23, nunca num href inventado', () => {
        for (const valor of [{ id: 459 }, Number.NaN, '', true, []]) {
            const html = renderizar({ companyId: valor });
            assert.doesNotMatch(html, /\[object Object\]/);
            assert.doesNotMatch(html, /NaN/);
            assert.doesNotMatch(html, /href="[^"]*mlb\.anuncios\.meus/, `companyId=${JSON.stringify(valor)} montou destino inventado`);
            assert.match(html, /aria-disabled="true"/);
        }
    });

    await contexto.test('conta objeto — cai no desabilitado, nunca num href inventado', () => {
        const html = renderizar({ conta: { chave: 'company-459' } });
        assert.doesNotMatch(html, /\[object Object\]/);
        assert.doesNotMatch(html, /href="[^"]*visao-geral/);
        assert.match(html, /aria-disabled="true"/);
        assert.equal(pilulas(html).length, 4);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. Dot vermelho de alerta
// ═══════════════════════════════════════════════════════════════════════════

test('AbasDoHeader — o dot de alerta de Publicações', async (contexto) => {
    await contexto.test('pedem_acao = 3 — dot de 6px SÓ na pílula de Publicações, com texto acessível', () => {
        const [visaoGeral, produtos, publicacoes, alavancas] = pilulas(HTML_CHEIO);
        const dot = tagDe(publicacoes, /<span title="3 publica[^>]*>/, 'dot de alerta');
        exigirClasses(dot, ['h-[6px]', 'w-[6px]', 'rounded-full', 'bg-pub-danger'], 'dot de alerta');
        // ⚠️ "pedem ação", NUNCA "com erro": o acervo não tem status de erro
        // (`MlAcervoItem.status` só assume active|paused|under_review|closed).
        assert.match(dot, /title="3 publicações pedem ação"/);
        assert.match(dot, /aria-label="3 publicações pedem ação"/);
        assert.doesNotMatch(HTML_CHEIO, /com erro/);
        assert.equal(contar(HTML_CHEIO, 'bg-pub-danger'), 1, 'o dot é um só, e é o de Publicações');
        for (const [rotulo, pedaco] of [['Visão geral', visaoGeral], ['Produtos', produtos], ['Alavancas', alavancas]]) {
            assert.ok(!pedaco.includes('bg-pub-danger'), `${rotulo} não deveria ter dot de alerta`);
        }
    });

    await contexto.test('pedem_acao = 1 — o texto vai para o singular', () => {
        const html = renderizar({ contadores: { ...CONTADORES_CHEIOS, publicacoes_pedem_acao: 1 } });
        assert.match(html, /title="1 publicação pede ação"/);
        assert.match(html, /aria-label="1 publicação pede ação"/);
    });

    await contexto.test('pedem_acao = 0 — NENHUM dot', () => {
        const html = renderizar({ contadores: { ...CONTADORES_CHEIOS, publicacoes_pedem_acao: 0 } });
        assert.equal(contar(html, 'bg-pub-danger'), 0);
        assert.doesNotMatch(html, /pedem ação/);
    });

    await contexto.test('pedem_acao = null — NENHUM dot (não medido não é alerta)', () => {
        const html = renderizar({ contadores: { ...CONTADORES_CHEIOS, publicacoes_pedem_acao: null } });
        assert.equal(contar(html, 'bg-pub-danger'), 0);
    });

    await contexto.test('o dot aparece mesmo quando o contador de publicações é nulo', () => {
        // São dois números independentes: pode haver triagem pendente num
        // acervo cuja CONTAGEM total ainda não foi apurada.
        const html = renderizar({ contadores: { produtos: 32, publicacoes: null, publicacoes_pedem_acao: 4, alavancas: 0 } });
        const [, , publicacoes] = pilulas(html);
        assert.ok(publicacoes.includes('bg-pub-danger'), `o dot deveria sobreviver ao contador nulo: ${publicacoes}`);
        assert.ok(!publicacoes.includes('min-w-[22px]'), 'e sem inventar a pílula de contagem');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. D23 e a ausência de conta
// ═══════════════════════════════════════════════════════════════════════════

test('AbasDoHeader — D23: sem Company, Publicações desabilitada e NUNCA escondida', async (contexto) => {
    const html = renderizar({ aba: 'produtos', companyId: null, contadores: CONTADORES_CHEIOS });

    await contexto.test('a aba continua NA TELA, como <span role="link"> sem href', () => {
        assert.equal(pilulas(html).length, 4, 'a aba desabilitada não pode desaparecer');
        assert.ok(html.includes('Publicações'), 'o rótulo tem de continuar visível');
        assert.doesNotMatch(html, /href="[^"]*mlb\.anuncios\.meus/, 'sem Company não existe destino');
        assert.equal(contar(html, '<span role="link"'), 1);
    });

    await contexto.test('aria-disabled="true" e o literal EXATO de ModoAnuncioTabs.jsx', () => {
        const [, , publicacoes] = pilulas(html);
        const tag = tagDe(publicacoes, /<span role="link"[^>]*>/, 'pílula de D23');
        assert.match(tag, /aria-disabled="true"/);
        assert.match(tag, /title="Disponível só para empresas cadastradas no sistema"/);
        assert.match(tag, /aria-label="Publicações — Disponível só para empresas cadastradas no sistema"/);
        exigirClasses(tag, ['cursor-not-allowed', 'opacity-40'], 'pílula de D23');
    });

    await contexto.test('o literal está byte a byte na FONTE (há gate procurando essa string)', () => {
        assert.ok(FONTE.includes('Disponível só para empresas cadastradas no sistema'), 'o literal de D23 foi parafraseado');
    });

    await contexto.test('Visão geral, Produtos e Alavancas NÃO passam por D23', () => {
        const [visaoGeral, produtos, , alavancas] = pilulas(html);
        for (const [rotulo, pedaco] of [['Visão geral', visaoGeral], ['Produtos', produtos], ['Alavancas', alavancas]]) {
            assert.ok(!pedaco.includes('aria-disabled='), `${rotulo} não deveria ser desabilitada por D23: ${pedaco}`);
            assert.match(pedaco, /^<a href="\/mlb\./, `${rotulo} deveria continuar sendo link`);
        }
        assert.equal(contar(html, 'aria-disabled="true"'), 1, 'só Publicações é desabilitada por D23');
    });

    await contexto.test('o contador de Publicações continua aparecendo mesmo desabilitada', () => {
        // A informação não é privilégio: a aba diz o que há e por que não dá
        // para entrar. Esconder o número seria esconder o motivo.
        const [, , publicacoes] = pilulas(html);
        assert.ok(publicacoes.includes('>58</span>'), `o contador sumiu junto com o link: ${publicacoes}`);
    });
});

test('AbasDoHeader — sem chave de conta, nenhum href com undefined', async (contexto) => {
    const html = renderizar({ conta: null, companyId: COMPANY });

    await contexto.test('nenhuma href com undefined no HTML', () => {
        assert.doesNotMatch(html, /href="[^"]*undefined/);
        assert.doesNotMatch(html, /undefined/);
        assert.doesNotMatch(html, /null/);
    });

    await contexto.test('as 3 abas que dependem da conta ficam desabilitadas COM explicação', () => {
        const [visaoGeral, produtos, publicacoes, alavancas] = pilulas(html);
        for (const [rotulo, pedaco] of [['Visão geral', visaoGeral], ['Produtos', produtos], ['Alavancas', alavancas]]) {
            const tag = tagDe(pedaco, /<span role="link"[^>]*>/, `pílula ${rotulo}`);
            assert.match(tag, /aria-disabled="true"/);
            assert.match(tag, /title="Abra uma empresa do Publicador/, `${rotulo} desabilitou sem explicar`);
        }
        // E Publicações, que depende da Company e não da conta, segue viva.
        assert.match(publicacoes, /^<a href="\/mlb\.anuncios\.meus/);
    });

    await contexto.test('conta = null E companyId = null — as 4 desabilitadas, nenhuma escondida', () => {
        const tudoNulo = renderizar({ conta: null, companyId: null, contadores: null });
        assert.equal(pilulas(tudoNulo).length, 4);
        assert.equal(contar(tudoNulo, 'aria-disabled="true"'), 4);
        assert.equal(contar(tudoNulo, '<a href'), 0);
        assert.doesNotMatch(tudoNulo, /undefined/);
        for (const rotulo of ['Visão geral', 'Produtos', 'Publicações', 'Alavancas']) {
            assert.ok(tudoNulo.includes(rotulo), `${rotulo} desapareceu`);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. Aba ativa
// ═══════════════════════════════════════════════════════════════════════════

test('AbasDoHeader — T-177-20: a aba ativa, chave por chave', async (contexto) => {
    for (const [indice, item] of ABAS.entries()) {
        await contexto.test(`aba="${item.chave}" — aria-current="page" UMA única vez, na pílula certa`, () => {
            const html = renderizar({ aba: item.chave });
            assert.equal(contar(html, 'aria-current="page"'), 1, 'a aba ativa é uma só');
            const pedacos = pilulas(html);
            assert.ok(pedacos[indice].includes('aria-current="page"'), `a marca de ativa caiu na pílula errada: ${pedacos[indice]}`);
            // A barra amarela de 2px por baixo, e só uma.
            assert.equal(contar(html, BARRA_ATIVA), 1, 'a barra de 2px da ativa é uma só');
            assert.ok(pedacos[indice].includes(BARRA_ATIVA), 'a barra caiu na pílula errada');
            assert.equal(contar(html, 'shadow-pub-aba-ativa'), 1, 'o anel interno é só da ativa');
            assert.ok(pedacos[indice].includes('shadow-pub-aba-ativa'));
        });
    }

    await contexto.test('aba="configuracoes" (o ícone da linha 1) — NENHUMA das quatro fica ativa', () => {
        const html = renderizar({ aba: 'configuracoes' });
        assert.equal(contar(html, 'aria-current="page"'), 0);
        assert.equal(contar(html, BARRA_ATIVA), 0);
        assert.equal(contar(html, 'shadow-pub-aba-ativa'), 0);
        assert.equal(pilulas(html).length, 4, 'e as 4 continuam na tela');
    });

    await contexto.test('aba ausente ou desconhecida — nenhuma ativa, nenhum crash', () => {
        for (const valor of [undefined, null, '', 'inventada', { chave: 'produtos' }, 7]) {
            let html;
            assert.doesNotThrow(() => { html = renderizar({ aba: valor }); });
            assert.equal(contar(html, 'aria-current="page"'), 0, `aba=${JSON.stringify(valor)} marcou alguma como ativa`);
            assert.equal(pilulas(html).length, 4);
        }
    });

    await contexto.test('D23 + ativa — a aba desabilitada ATIVA ainda se declara como página atual', () => {
        const html = renderizar({ aba: 'publicacoes', companyId: null });
        const [, , publicacoes] = pilulas(html);
        const tag = tagDe(publicacoes, /<span role="link"[^>]*>/, 'pílula de D23 ativa');
        assert.match(tag, /aria-current="page"/);
        assert.match(tag, /aria-disabled="true"/);
        assert.ok(publicacoes.includes(BARRA_ATIVA), 'a barra da ativa some quando a aba é desabilitada');
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. FID-01 — a geometria do protótipo, elemento por elemento
// ═══════════════════════════════════════════════════════════════════════════

test('AbasDoHeader — FID-01/FID-03 travados no HTML, elemento por elemento', async (contexto) => {
    const [visaoGeral, produtos, publicacoes, alavancas] = pilulas(HTML_CHEIO);

    await contexto.test('o grupo das abas: flex, min-w-0 e o gap de 2px do protótipo', () => {
        exigirClasses(
            tagDe(HTML_CHEIO, /^<div[^>]*>/, 'grupo das abas'),
            ['flex', 'min-w-0', 'items-center', 'gap-[2px]'],
            'grupo das abas',
        );
        assert.equal(contar(HTML_CHEIO, 'gap-[2px]'), 1, 'o gap de 2px é do grupo, não das pílulas');
    });

    await contexto.test('pílula INATIVA: a base inteira + peso 500, texto #8A8A90 e os dois hovers', () => {
        const tag = tagDe(visaoGeral, /<a href="\/mlb\.anuncios\.publicador\.visao-geral[^>]*>/, 'pílula inativa');
        exigirClasses(tag, [...CLASSES_BASE, 'font-medium', 'text-pub-txt-3', 'hover:bg-pub-raised', 'hover:text-pub-txt'], 'pílula inativa');
        proibirClasses(tag, ['bg-pub-hover-2', 'shadow-pub-aba-ativa', 'font-semibold', 'cursor-not-allowed', 'opacity-40'], 'pílula inativa');
    });

    await contexto.test('pílula ATIVA: fundo #161616, anel interno #262626, peso 600, texto #F4F4F5', () => {
        const tag = tagDe(produtos, /<a href="\/mlb\.anuncios\.publicador\.produtos[^>]*>/, 'pílula ativa');
        exigirClasses(tag, [...CLASSES_BASE, 'bg-pub-hover-2', 'shadow-pub-aba-ativa', 'font-semibold', 'text-pub-txt'], 'pílula ativa');
        // ⚠️ O anel da ativa é `inset 0 0 0 1px` — SOMBRA, não borda: borda
        // empurraria o conteúdo 1px e a pílula mediria 36px.
        proibirClasses(tag, ['border', 'font-medium', 'hover:bg-pub-raised'], 'pílula ativa');
        assert.equal(contar(HTML_CHEIO, 'font-medium'), 3, 'as 3 inativas têm peso 500');
    });

    await contexto.test('as 4 pílulas compartilham a base, e a contagem prova isso', () => {
        for (const classe of ['h-[34px]', 'rounded-pub-control', 'px-[12px]', 'text-[13.5px]', 'whitespace-nowrap', 'transition-[background-color,color]', 'duration-150', 'relative']) {
            assert.equal(contar(HTML_CHEIO, classe), 4, `"${classe}" deveria estar nas 4 pílulas`);
        }
        // `gap-2` (8px, dentro da pílula) é diferente de `gap-[2px]` (entre elas).
        assert.equal(contar(HTML_CHEIO, 'gap-2"'), 0, 'o gap interno não deveria fechar a classe');
        assert.equal(contar(HTML_CHEIO, ' gap-2 '), 4, 'as 4 pílulas têm 8px entre ícone, label e contador');
    });

    await contexto.test('ícone: 15px com traço 1,6, dentro de um grid centrado, amarelo só na ativa', () => {
        assert.equal(contar(HTML_CHEIO, 'width="15"'), 4, 'os 4 ícones têm 15px');
        assert.equal(contar(HTML_CHEIO, 'stroke-width="1.6"'), 4, 'os 4 ícones têm traço 1,6');
        assert.equal(contar(HTML_CHEIO, 'aria-hidden="true"'), 5, '4 ícones decorativos + a barra da ativa');

        const caixaAtiva = tagDe(produtos, /<span class="grid[^>]*>/, 'caixa do ícone ativo');
        exigirClasses(caixaAtiva, ['grid', 'flex-none', 'place-items-center', 'text-pub-accent'], 'caixa do ícone ativo');
        const caixaInativa = tagDe(visaoGeral, /<span class="grid[^>]*>/, 'caixa do ícone inativo');
        exigirClasses(caixaInativa, ['grid', 'flex-none', 'place-items-center', 'text-pub-txt-4'], 'caixa do ícone inativo');
        assert.equal(contar(HTML_CHEIO, 'text-pub-txt-4'), 3, 'os 3 ícones inativos são #71717A');
    });

    await contexto.test('ícones: os 4 equivalentes Lucide dos SVG do protótipo (TOK-14)', () => {
        // painel · pacote · lista · raio — na ordem das abas.
        for (const [pedaco, classe, rotulo] of [
            [visaoGeral, 'lucide-layout-dashboard', 'Visão geral'],
            [produtos, 'lucide-package', 'Produtos'],
            [publicacoes, 'lucide-layout-list', 'Publicações'],
            [alavancas, 'lucide-zap', 'Alavancas'],
        ]) {
            assert.ok(pedaco.includes(classe), `o ícone de ${rotulo} deveria ser ${classe}`);
            assert.equal(contar(HTML_CHEIO, classe), 1, `${classe} deveria aparecer uma única vez`);
        }
    });

    await contexto.test('contador ATIVO: 18px, min 22px, raio pleno, 11px/600, amarelo sobre tinta 0.14', () => {
        const tag = tagDe(produtos, /<span class="grid h-\[18px\][^>]*>/, 'contador ativo');
        exigirClasses(
            tag,
            ['grid', 'h-[18px]', 'min-w-[22px]', 'flex-none', 'place-items-center', 'rounded-full',
                'px-[6px]', 'text-[11px]', 'font-semibold', 'tabular-nums', 'bg-pub-accent/[0.14]', 'text-pub-accent'],
            'contador ativo',
        );
    });

    await contexto.test('contador INATIVO: a mesma caixa, fundo #1A1A1A e texto #8A8A90', () => {
        const tag = tagDe(publicacoes, /<span class="grid h-\[18px\][^>]*>/, 'contador inativo');
        exigirClasses(
            tag,
            ['h-[18px]', 'min-w-[22px]', 'rounded-full', 'px-[6px]', 'text-[11px]', 'font-semibold',
                'tabular-nums', 'bg-pub-hover-3', 'text-pub-txt-3'],
            'contador inativo',
        );
        proibirClasses(tag, ['bg-pub-accent', 'text-pub-accent'], 'contador inativo');
        // TOK-02: `tabular-nums` em TODA contagem — os três contadores.
        assert.equal(contar(HTML_CHEIO, 'tabular-nums'), 3);
        assert.equal(contar(HTML_CHEIO, 'text-[11px]'), 3);
        assert.equal(contar(HTML_CHEIO, 'rounded-full'), 4, '3 contadores + o dot de alerta');
    });

    await contexto.test('barra da ativa: 2px, raio 2px, amarela, a -7px da pílula, entre 12px e 12px', () => {
        assert.ok(produtos.includes(BARRA_ATIVA), `a barra da ativa mudou de medida: ${produtos}`);
        assert.equal(contar(HTML_CHEIO, 'bottom-[-7px]'), 1);
        assert.equal(contar(HTML_CHEIO, 'h-[2px]'), 1);
    });

    await contexto.test('FID-03/FID-04: nenhuma escala nomeada, nada da camada antiga, nenhum hex', () => {
        assert.doesNotMatch(HTML_CHEIO, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/);
        assert.doesNotMatch(HTML_CHEIO, /\bh-(8|9|10|11|12)\b/, 'a pílula é 34px, não um passo do Tailwind');
        assert.doesNotMatch(HTML_CHEIO, /\brounded-(sm|md|lg|xl|t-lg)\b/);
        assert.doesNotMatch(HTML_CHEIO, /\becf-(bg|card|yellow|line)\b/);
        assert.doesNotMatch(HTML_CHEIO, /\bfont-display\b/);
        assert.doesNotMatch(HTML_CHEIO, /border-white\/\[0\.08\]/);
        assert.doesNotMatch(HTML_CHEIO, /#[0-9a-fA-F]{6}\b/);
        // A família Geist vem do `<header>` (plano 177-04): as abas NÃO
        // redeclaram família, senão a troca de fonte passa a ter duas casas.
        assert.doesNotMatch(HTML_CHEIO, /font-pub/);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. REND-04 e a montagem no header
// ═══════════════════════════════════════════════════════════════════════════

test('AbasDoHeader — REND-04 e a montagem na <nav> (asserções de fonte)', async (contexto) => {
    await contexto.test('as flags nascem DENTRO da função chamada pelo .map()', () => {
        // Variável de escopo do componente lida só dentro de um `.map()` já foi
        // eliminada pelo Rollup no bundle de produção deste projeto.
        assert.match(FONTE, /function pilula\(item\)/, 'a pílula deveria ser uma função que recebe o item');
        assert.match(FONTE, /ABAS\.map\(\(item\) => pilula\(item\)\)/, 'o .map() deveria só delegar');
        for (const flag of ['const ativa =', 'const desabilitada =', 'const alerta =', 'let contador =']) {
            const trecho = FONTE.slice(FONTE.indexOf('function pilula(item)'));
            assert.ok(trecho.includes(flag), `"${flag}" deveria ser calculada dentro de pilula()`);
        }
    });

    await contexto.test('a chave da conta passa por textoSeguro, não por String()', () => {
        assert.match(FONTE, /from '\.\.\/BarraDaConta'/, 'o textoSeguro foi recriado em vez de importado');
        assert.match(FONTE, /textoSeguro\(conta, null\)/);
        assert.doesNotMatch(FONTE, /String\(conta/);
    });

    await contexto.test('o contador é lido com Number.isFinite (NaN é number e escreveria "NaN")', () => {
        assert.match(FONTE, /Number\.isFinite\(contadores\?\.produtos\)/);
        assert.match(FONTE, /Number\.isFinite\(contadores\?\.publicacoes\)/);
        assert.match(FONTE, /Number\.isFinite\(contadores\?\.alavancas\)/);
        assert.match(FONTE, /Number\.isFinite\(contadores\?\.publicacoes_pedem_acao\)/);
    });

    await contexto.test('o PublicadorHeader monta as abas na <nav> com ?? null em todo acesso', () => {
        const header = lerSemComentarios('resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx');
        assert.match(header, /<AbasDoHeader/, 'o header deixou de montar as abas');
        assert.match(header, /from '\.\/AbasDoHeader'/);
        assert.match(header, /companyId=\{cabecalho\?\.company_id \?\? null\}/);
        assert.match(header, /contadores=\{cabecalho\?\.contadores \?\? null\}/);
        // E as abas vêm ANTES do slot `acoes` dentro da <nav>.
        assert.ok(header.indexOf('<AbasDoHeader') < header.indexOf('acoes !== null'), 'as abas deveriam vir antes do slot de ações');
    });
});
