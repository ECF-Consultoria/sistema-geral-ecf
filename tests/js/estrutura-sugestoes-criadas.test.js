import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import {
    acaoDaPendencia, filtroLigado, gravarModoCriadas, layoutDaVitrine, lerModoCriadas, nomeDoItem, paramsDaCriadas, rotuloDaPendencia, textoDoTotal,
} from '../../resources/js/lib/sugestoesCriadas.js';
import { controlesDaAba, filtrosDaAba } from '../../resources/js/lib/sugestoesEstrutura.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Planejamento, aba "Criadas" (13/10/2026).
//
// POR QUE EXISTE: "em Planejamento deveriam ficar os produtos que planejamos…
// aceitou, ele fica ali". A sugestão aceita sumia da tela e o único rastro era um
// aviso de segundos. A aba mostra os combos, kits e combits que já existem, com
// do que são feitos, o envio e o preço, e os atalhos para mexer neles.
//
// 16/10/2026: pela FOTO ("não dá para saber quais são os produtos… visualmente
// pela foto"), com filtros, ordem, páginas e modo Lista ("deveria ter filtros, modo
// lista também"), e cada pendência levando aonde se resolve.
// ═══════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const DIR = 'resources/js/Components/Portal/Estrutura/Sugestoes';

global.route = (nome, params) => `/${nome}?${new URLSearchParams(params ?? {})}`;

const STUB_INERTIA = path.join(__dirname, `.criadas-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, preserveState, preserveScroll, replace, ...props }) { return React.createElement('a', { href, ...props }, children); }
export const router = { get() {}, reload() {} };
export function usePage() { return { props: {} }; }
`, 'utf8');
test.after(() => fs.rmSync(STUB_INERTIA, { force: true }));

async function montar(relativo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, relativo)],
        bundle: true, format: 'esm', platform: 'node', jsx: 'automatic', write: false, logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/*'],
    });
    const outfile = path.join(__dirname, `.criadas-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

/** Renderiza com um localStorage de mentira (o modo vem dele, como em Produtos). */
function comModo(modo, fn) {
    const antes = globalThis.window;
    globalThis.window = { localStorage: { getItem: () => modo, setItem() {} } };
    try {
        return fn();
    } finally {
        globalThis.window = antes;
    }
}

const FOTO_NINA = '/portal/estrutura/produtos/variacao/9/imagem/41?w=320';
const ME1 = { chave: 'me1', pacote: { c: 53, l: 60, a: 160, peso: 16 }, somado: true, volumes: 2, sem_medida: [], declarada: null, modalidade: 'drop_off',
    estouros: [{ medida: 'maior', valor: 160, limite: 100 }] };
const COMBO = {
    id: 571, sku: 'poltrona-nina-CB2', nome: 'Combo 2 Poltrona Nina — Cinza', fase: 'combo', pendencia: 'sem_frete', sem_calculo: false,
    composicao: [{ id: 566, sku: 'poltrona-nina', nome: 'Poltrona Nina', quantidade: 2, foto: FOTO_NINA }],
    envio: ME1, preco: { classico: null, premium: null }, faltam_custo: [],
};
const KIT = {
    id: 580, sku: 'NINA+MESA', nome: 'Kit Nina e Mesa', fase: 'kit', pendencia: null, sem_calculo: false,
    composicao: [{ id: 566, sku: 'poltrona-nina', nome: 'Poltrona Nina', quantidade: 1, foto: FOTO_NINA }, { id: 525, sku: 'MESA-RD', nome: 'Mesa Redonda', quantidade: 1, foto: null }],
    envio: { chave: 'me2', pacote: { c: 60, l: 60, a: 90, peso: 20 }, somado: true, volumes: 2, estouros: [], sem_medida: [], declarada: null, modalidade: 'drop_off' },
    preco: { classico: 989.92, premium: 1133.39 }, faltam_custo: [],
};
const CONTAGENS = {
    fase: { todas: 2, combo: 1, kit: 1, combit: 0 },
    situacao: { todas: 2, com_preco: 1, pendente: 1, sem_custo: 0, sem_frete: 1, impossivel: 0 },
    envio: { todas: 2, me2: 1, me2_full: 0, me1: 1, pendente: 0, declarada: 0 },
};
const CRIADAS = {
    total: 2, filtrado: 2, contagens: CONTAGENS, paginacao: { pagina: 1, paginas: 1, por_pagina: 60, blocos: 2 },
    grupos: [{ fase: 'combo', rotulo: 'Combos', itens: [COMBO] }, { fase: 'kit', rotulo: 'Kits', itens: [KIT] }],
};

// ─── As palavras ─────────────────────────────────────────────────────────────

test('o selo do preço diz o que falta, com a mesma pendência da tela de preços', () => {
    assert.deepEqual(rotuloDaPendencia({ pendencia: null }), { chave: 'ok', texto: 'Com preço', tom: 'ok' });
    assert.deepEqual(rotuloDaPendencia({ pendencia: 'sem_frete' }), { chave: 'sem_frete', texto: 'Falta o frete', tom: 'falta' });
    assert.equal(rotuloDaPendencia({ pendencia: 'sem_custo' }).texto, 'Falta o custo');
    assert.equal(rotuloDaPendencia({ pendencia: 'impossivel' }).texto, 'Preço impossível');
    assert.equal(rotuloDaPendencia({ pendencia: 'outra' }).texto, 'Falta o preço');
    assert.deepEqual(rotuloDaPendencia({ sem_calculo: true, pendencia: null }), { chave: 'sem_calculo', texto: 'Sem preço calculado', tom: 'neutro' });
    assert.equal(rotuloDaPendencia(undefined).chave, 'sem_calculo');
});

test('sem nome gravado, o título se monta pela composição (antes repetia o código)', () => {
    assert.equal(nomeDoItem(KIT), 'Kit Nina e Mesa');
    assert.equal(nomeDoItem({ ...KIT, nome: null }), '1× Poltrona Nina + 1× Mesa Redonda');
    assert.equal(nomeDoItem({ sku: 'X', nome: '', composicao: [{ sku: 'Z', nome: null, quantidade: 2 }] }), '2× Z');
    assert.equal(nomeDoItem({ sku: 'SO-SKU', nome: null, composicao: [] }), 'SO-SKU');
});

test('cada pendência diz de quem é e leva aonde se resolve', () => {
    assert.deepEqual(acaoDaPendencia(KIT), { texto: 'Com preço', detalhe: null, acao: 'Preço', destino: 'precificacao', q: 'NINA+MESA', destaque: false });

    const custo = acaoDaPendencia({ ...KIT, pendencia: 'sem_custo', faltam_custo: ['MESA-RD'] });
    assert.equal(custo.destino, 'produtos', 'o custo vem do cadastro do produto, não da Precificação');
    assert.equal(custo.q, 'MESA-RD');
    assert.equal(custo.acao, 'Cadastrar custo');
    assert.match(custo.detalhe, /de MESA-RD/);

    const medidas = acaoDaPendencia({ ...KIT, pendencia: 'sem_frete', envio: { chave: 'pendente', sem_medida: ['CAD', 'MESA-RD'] } });
    assert.deepEqual([medidas.texto, medidas.acao, medidas.destino, medidas.q], ['Faltam medidas', 'Cadastrar medidas', 'produtos', 'CAD']);
    assert.match(medidas.detalhe, /de CAD, MESA-RD/);

    const frete = acaoDaPendencia(COMBO);
    assert.deepEqual([frete.acao, frete.destino, frete.q, frete.destaque], ['Informar frete', 'precificacao', 'poltrona-nina-CB2', true]);

    assert.match(acaoDaPendencia({ ...KIT, pendencia: 'impossivel' }).detalhe, /100% ou mais/);
    assert.equal(acaoDaPendencia({ ...KIT, sem_calculo: true }).destaque, false);
});

test('a vitrine: em linha até 3 produtos, mosaico 2×2 a partir de 4 (em linha passavam da borda)', () => {
    const p = (n) => Array.from({ length: n }, (_, i) => ({ id: i + 1, quantidade: 1 }));
    assert.deepEqual([1, 2, 3].map((n) => [layoutDaVitrine(p(n)).modo, layoutDaVitrine(p(n)).tamanho]), [['linha', 132], ['linha', 108], ['linha', 72]]);
    assert.deepEqual(layoutDaVitrine(p(4)), { modo: 'mosaico', pecas: p(4), resto: 0, tamanho: 72 }, 'quatro cabem inteiras no mosaico');
    assert.deepEqual(layoutDaVitrine(p(6)), { modo: 'mosaico', pecas: p(6).slice(0, 3), resto: 3, tamanho: 72 });
    assert.equal(layoutDaVitrine(undefined).pecas.length, 0);
});

test('os parâmetros da URL: só o que difere do padrão, e mudar filtro volta à página 1', () => {
    const atuais = { fase: 'kit', situacao: null, envio: null, ordem: 'recentes', q: '' };
    assert.deepEqual(paramsDaCriadas(atuais), { aba: 'criadas', fase: 'kit' });
    assert.deepEqual(paramsDaCriadas(atuais, { pagina: 3 }), { aba: 'criadas', fase: 'kit', pagina: 3 });
    assert.deepEqual(paramsDaCriadas({ ...atuais, pagina: 3 }, { situacao: 'sem_custo' }), { aba: 'criadas', fase: 'kit', situacao: 'sem_custo' }, 'filtro novo: página 1');
    assert.deepEqual(paramsDaCriadas(atuais, { q: '  nina ', ordem: 'nome' }), { aba: 'criadas', fase: 'kit', q: 'nina', ordem: 'nome' });
    assert.deepEqual(paramsDaCriadas(atuais, { fase: null }), { aba: 'criadas' });
});

test('o total diz "N de M" quando há filtro', () => {
    assert.equal(textoDoTotal({ total: 18, filtrado: 18 }, {}), '18 criadas');
    assert.equal(textoDoTotal({ total: 1, filtrado: 1 }, {}), '1 criada');
    assert.equal(textoDoTotal({ total: 18, filtrado: 5 }, { situacao: 'sem_custo' }), '5 de 18 criadas');
    assert.equal(filtroLigado({ ordem: 'nome' }), false, 'a ordem não é filtro');
    assert.equal(filtroLigado({ q: '  ' }), false);
});

test('o modo Visual grande / Lista fica no navegador; sem storage vale o Visual grande', () => {
    const guardado = {};
    const antes = globalThis.window;
    globalThis.window = { localStorage: { getItem: (k) => guardado[k] ?? null, setItem: (k, v) => { guardado[k] = v; } } };
    try {
        assert.equal(lerModoCriadas(), 'grande');
        gravarModoCriadas('lista');
        assert.equal(lerModoCriadas(), 'lista');
        gravarModoCriadas('outro');
        assert.equal(lerModoCriadas(), 'lista', 'valor estranho não grava');
        assert.equal(guardado['ecf.criadas.modo'], 'lista');
    } finally {
        globalThis.window = antes;
    }
    assert.equal(lerModoCriadas(), 'grande', 'sem window/localStorage');
});

test('a aba Criadas não usa nenhum filtro da barra das sugestões e abre com a URL limpa', () => {
    assert.deepEqual(controlesDaAba('criadas'), { busca: false, familia: false, fase: false, tipo: false, status: false });
    assert.deepEqual(filtrosDaAba({ fase: 'kit', familia: '12', q: 'nina', status: 'prontas' }, 'criadas'), { aba: 'criadas' });
    assert.deepEqual(filtrosDaAba({ fase: 'kit', q: 'nina' }, 'descartadas'), { aba: 'descartadas', fase: 'kit', q: 'nina' });
    assert.deepEqual(controlesDaAba('sugestoes'), { busca: true, familia: true, fase: true, tipo: true, status: true });
});

// ─── A tela ──────────────────────────────────────────────────────────────────

test('PainelCriadas, Visual grande: filtros com contagem, cartão pela foto e ação no lugar certo', async () => {
    const { default: PainelCriadas } = await montar(`${DIR}/PainelCriadas.jsx`);
    const html = comModo('grande', () => renderToStaticMarkup(React.createElement(PainelCriadas, { criadas: CRIADAS, filtros: { ordem: 'recentes' } })));

    assert.match(html, /data-modo="grande"/);
    assert.match(html, /data-criadas-total="true">2 criadas</);
    assert.doesNotMatch(html, /data-acao="limpar-filtros-criadas"/, 'sem filtro, sem "Limpar"');
    // Os filtros: tipo e situação com a contagem; opção com zero some (Combit 0 fica, é tipo; "Falta custo" 0 some).
    assert.match(html, /data-fase-opcao="kit"[^>]*>Kit<span[^>]*>1<\/span>/);
    assert.match(html, /data-situacao-opcao="sem_frete"[^>]*>Falta frete<span[^>]*>1<\/span>/);
    assert.doesNotMatch(html, /data-situacao-opcao="sem_custo"/);
    assert.match(html, /<option value="me1">ME1 \(transportadora\) \(1\)<\/option>/);
    assert.doesNotMatch(html, /<option value="me2_full">/);
    assert.match(html, /data-seletor-modo/);

    assert.ok(html.indexOf('data-grupo-criadas="combo"') < html.indexOf('data-grupo-criadas="kit"'), 'Combos antes de Kits');
    assert.match(html, />Combos <span[^>]*>1<\/span>/);

    const combo = html.match(/<li[^>]*data-criada="571"[\s\S]*?<\/li>/)[0];
    assert.match(combo, /data-vitrine="linha"/);
    assert.equal([...combo.matchAll(/data-peca="/g)].length, 1);
    assert.match(combo, /data-peca="566" data-quantidade="2"/);
    assert.match(combo, new RegExp(`<img src="${FOTO_NINA.replace(/[?]/g, '\\?')}" alt="Poltrona Nina"`));
    assert.match(combo, /data-qtd="true">2<!-- -->×<|data-qtd="true">2×</);
    assert.match(combo, /data-preco="sem_frete"[\s\S]*?Falta o frete[\s\S]*?fora do Mercado Envios/);
    assert.match(combo, /title="Fora do Mercado Envios \(ME1\) · Pacote somado \(2 caixas\): 53 × 60 × 160 cm · 16 kg · passa do limite: maior lado 160 cm \(máx\. 100\)" data-envio="me1"/);
    assert.match(combo, /<a href="\/portal\.auth\.estrutura\.mapeamento\?abrir=571"[^>]*data-acao="editar-criada"/);
    assert.match(combo, /<a href="\/portal\.auth\.estrutura\.precificacao\?q=poltrona-nina-CB2"[^>]*data-acao="precificar-criada" data-destino="precificacao">Informar frete/);

    const kit = html.match(/<li[^>]*data-criada="580"[\s\S]*?<\/li>/)[0];
    assert.equal([...kit.matchAll(/data-peca="/g)].length, 2);
    assert.doesNotMatch(kit, /data-qtd/);
    assert.match(kit, /data-sem-foto="true"[\s\S]*?MESA-RD</);
    assert.match(kit, /data-preco="ok"[\s\S]*?Clássico[\s\S]*?R\$\s989,92[\s\S]*?Premium[\s\S]*?R\$\s1\.133,39/);
    assert.match(kit, /data-acao="precificar-criada" data-destino="precificacao">Preço/);
});

test('PainelCriadas, Lista: uma linha por item, preço nas colunas e a pendência ocupando as duas', async () => {
    const { default: PainelCriadas } = await montar(`${DIR}/PainelCriadas.jsx`);
    const semCusto = { ...KIT, id: 590, sku: 'KT-SEM-CUSTO', nome: null, pendencia: 'sem_custo', faltam_custo: ['MESA-RD'] };
    const criadas = { ...CRIADAS, grupos: [{ fase: 'kit', rotulo: 'Kits', itens: [KIT, semCusto] }] };
    const html = comModo('lista', () => renderToStaticMarkup(React.createElement(PainelCriadas, { criadas, filtros: {} })));

    assert.match(html, /data-modo="lista"/);
    assert.equal([...html.matchAll(/data-linha="true"/g)].length, 2);
    assert.match(html, />Clássico<\/span><span[^>]*>Premium</, 'cabeçalho das colunas');
    const linhaKit = html.match(/<li[^>]*data-criada="580"[\s\S]*?<\/li>/)[0];
    assert.match(linhaKit, /data-vitrine-compacta/);
    assert.match(linhaKit, /data-preco="ok">R\$\s989,92</);
    const linhaSemCusto = html.match(/<li[^>]*data-criada="590"[\s\S]*?<\/li>/)[0];
    assert.match(linhaSemCusto, /data-nome="true">1× Poltrona Nina \+ 1× Mesa Redonda</, 'sem nome: monta pela composição');
    assert.match(linhaSemCusto, /col-span-2[^"]*" data-preco="sem_custo"/);
    assert.match(linhaSemCusto, /<a href="\/portal\.auth\.estrutura\.produtos\?q=MESA-RD"[^>]*data-destino="produtos">Cadastrar custo/);
});

test('PainelCriadas: kit de 6 produtos vira mosaico com 3 fotos e "+3"', async () => {
    const { default: PainelCriadas } = await montar(`${DIR}/PainelCriadas.jsx`);
    const composicao = [1, 2, 3, 4, 5, 6].map((id) => ({ id, sku: `P${id}`, nome: `Produto ${id}`, quantidade: 1, foto: null }));
    const html = renderToStaticMarkup(React.createElement(PainelCriadas, { criadas: { ...CRIADAS, grupos: [{ fase: 'kit', rotulo: 'Kits', itens: [{ ...KIT, composicao }] }] } }));

    assert.match(html, /data-vitrine="mosaico"/);
    assert.match(html, /data-mosaico="true"/);
    assert.equal([...html.matchAll(/data-peca="/g)].length, 3);
    assert.match(html, /data-resto="true">\+<!-- -->3<|data-resto="true">\+3</);
});

test('PainelCriadas: com filtro e nada achado, diz isso e oferece limpar', async () => {
    const { default: PainelCriadas } = await montar(`${DIR}/PainelCriadas.jsx`);
    const html = renderToStaticMarkup(React.createElement(PainelCriadas, {
        criadas: { ...CRIADAS, filtrado: 0, grupos: [] }, filtros: { situacao: 'impossivel', ordem: 'recentes' },
    }));

    assert.match(html, /data-criadas-filtro-vazio/);
    assert.match(html, /Nada com esses filtros/);
    assert.match(html, /data-criadas-total="true">0 de 2 criadas</);
    assert.match(html, /data-acao="limpar-filtros-criadas"/);
    assert.match(html, /data-situacao-opcao="impossivel"/, 'a opção ligada aparece mesmo com zero, para dar para desligar');
});

test('PainelCriadas vazio: diz que o que for aceito ou montado fica ali', async () => {
    const { default: PainelCriadas } = await montar(`${DIR}/PainelCriadas.jsx`);
    for (const criadas of [null, undefined, { total: 0, filtrado: 0, grupos: [], contagens: {} }]) {
        const html = renderToStaticMarkup(React.createElement(PainelCriadas, { criadas }));
        assert.match(html, /data-criadas-vazio/);
        assert.match(html, /Os combos, kits e combits que você aceitar em Pendentes ou montar à mão ficam aqui\./);
    }
});

test('PainelCriadas: a paginação aparece só com mais de uma página', async () => {
    const { default: PainelCriadas } = await montar(`${DIR}/PainelCriadas.jsx`);
    const render = (paginas) => renderToStaticMarkup(React.createElement(PainelCriadas, {
        criadas: { ...CRIADAS, paginacao: { pagina: 1, paginas, por_pagina: 60, blocos: 130 } },
    }));
    assert.doesNotMatch(render(1), /data-paginacao/);
    assert.match(render(3), /data-paginacao[\s\S]*130 criadas · 60 por página/);
});

test('AbasDasSugestoes: quarta aba "Criadas" com a contagem, e sem seleção em lote nela', async () => {
    const { default: AbasDasSugestoes } = await montar(`${DIR}/AbasDasSugestoes.jsx`);
    const render = (aba) => renderToStaticMarkup(React.createElement(AbasDasSugestoes, {
        aba, contagens: { sugestoes: 12, sem_tipo: 1, descartadas: 0, criadas: 8 }, hrefAba: (a) => `/planejamento?aba=${a}`, naPagina: 5,
    }));

    const criadas = render('criadas');
    assert.deepEqual([...criadas.matchAll(/data-aba="([a-z_]+)"/g)].map((m) => m[1]), ['sugestoes', 'sem_tipo', 'descartadas', 'criadas']);
    assert.match(criadas, /<a href="\/planejamento\?aba=criadas"[^>]*aria-current="page"[^>]*>Criadas<span[^>]*>8<\/span>/);
    assert.match(criadas, /grid-cols-4/);
    assert.doesNotMatch(criadas, /data-selecao-topo|data-selecao-celular|data-acao="marcar-pagina"/, 'nada para marcar em Criadas');

    assert.match(render('sugestoes'), /data-selecao-topo/, 'em Pendentes a seleção continua');
});

test('a página liga a aba: recebe `criadas` e os filtros dela, e saindo dela os filtros ficam para trás', () => {
    const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaSugestoes.jsx');
    assert.match(pagina, /sugestoes, criadas = null, filtros_criadas = null, filtros/);
    assert.match(pagina, /const ehAbaCriadas = sugestoes\.aba === 'criadas';/);
    assert.match(pagina, /\{sugestoes\.tem_produtos && ! ehAbaCriadas && \(/);
    assert.match(pagina, /\{ehAbaCriadas && <PainelCriadas criadas=\{criadas\} filtros=\{filtros_criadas\} \/>\}/);
    assert.match(pagina, /filtrosDaAba\(ehAbaCriadas \? \{\} : filtros, aba\)/);
    // 16/10: o subtítulo fala do que está na aba, e o "Atualizado" (das sugestões) sai dela.
    assert.match(pagina, /ehAbaCriadas\s*\?\s*'Os combos, kits e combits que você já criou, com o preço e o que falta em cada um\.'/);
    assert.match(pagina, /\{textoDeAtualizacao && ! ehAbaCriadas && \(/);
});
