import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';
import {
    SEM_FAMILIA, filtrosDeTipo, linhasDaFamilia, montarFamilias, ofertasDaFamilia, ondeEstaCadaOferta, pendentesDaFamilia, resumoDaFamilia,
} from '../../resources/js/lib/precificacaoPorFamilia.js';

// ═══════════════════════════════════════════════════════════════════════════
// Precificação do Portal agrupada por família (11/10/2026).
//
// O usuário: a lista era "uma lista inteira sem saber o que é". Queria o produto
// unitário com as variações dele embaixo, e levantou o nó do kit, que junta "um
// produto de um e de outro" e teria dois produtos-pai na tela.
//
// Aqui: a montagem dos grupos (lógica pura) e a página desenhada de verdade
// (esbuild + react-dom/server), com as props no formato do servidor.
// ═══════════════════════════════════════════════════════════════════════════

const oferta = (id, sku, fase, mais = {}) => ({ id, sku, nome: `Nome de ${sku}`, fase, componentes: [], ...mais });
const SALA = { id: 3, nome: 'Sala de Jantar' };

const CAD = oferta(1, 'CAD-01', 'simples');
const CB2 = oferta(2, 'CAD-01-CB2', 'combo', { componentes: [{ id: 1, sku: 'CAD-01', nome: 'Cadeira 01', fase: 'simples', quantidade: 2 }] });
const CB4 = oferta(3, 'CAD-01-CB4', 'combo', { componentes: [{ id: 1, sku: 'CAD-01', nome: 'Cadeira 01', fase: 'simples', quantidade: 4 }] });
const MESA = oferta(4, 'MSA-MR', 'simples');
const KIT = oferta(5, 'MSA+CAD-KIT', 'kit', {
    nome: 'Kit Mesa + 1 Cadeira',
    componentes: [{ id: 4, sku: 'MSA-MR', nome: 'Mesa Marfim', fase: 'simples', quantidade: 1 }, { id: 1, sku: 'CAD-01', nome: 'Cadeira 01', fase: 'simples', quantidade: 1 }],
});
const POLT = oferta(6, 'POLT', 'simples');
const resumoKit = { id: 5, sku: 'MSA+CAD-KIT', nome: 'Kit Mesa + 1 Cadeira', fase: 'kit' };

/** Os blocos como o servidor manda: já na ordem (família, produtos antes de conjuntos, sem família no fim). */
const blocos = () => [
    { chave: 1, familia: SALA, ofertas: [CAD, CB2, CB4], tambem_em: [resumoKit] },
    { chave: 4, familia: SALA, ofertas: [MESA], tambem_em: [resumoKit] },
    { chave: 5, familia: SALA, ofertas: [KIT], tambem_em: [] },
    { chave: 6, familia: null, ofertas: [POLT], tambem_em: [] },
];

// ─── A montagem ───

test('cada produto aparece uma vez, com os combos dele; o kit fica nos conjuntos, uma vez só', () => {
    const [sala, sem] = montarFamilias(blocos());

    assert.equal(sala.chave, 'f-3');
    assert.equal(sala.nome, 'Sala de Jantar');
    assert.deepEqual(sala.produtos.map((p) => [p.principal.sku, p.combos.map((c) => c.sku)]), [['CAD-01', ['CAD-01-CB2', 'CAD-01-CB4']], ['MSA-MR', []]]);
    assert.deepEqual(sala.conjuntos.map((k) => k.sku), ['MSA+CAD-KIT']);
    // O nó do kit: ele não vira filho de nenhum dos dois produtos; os dois apontam para ele.
    assert.deepEqual(sala.produtos.map((p) => p.tambemEm.map((k) => k.sku)), [['MSA+CAD-KIT'], ['MSA+CAD-KIT']]);
    assert.equal(ofertasDaFamilia(sala).filter((o) => o.sku === 'MSA+CAD-KIT').length, 1);

    assert.equal(sem.chave, SEM_FAMILIA);
    assert.equal(sem.semFamilia, true);
    assert.deepEqual(sem.produtos.map((p) => p.principal.sku), ['POLT']);
});

test('"Sem família" vai sempre para o fim, mesmo que a página comece por ela', () => {
    const invertido = [blocos()[3], ...blocos().slice(0, 3)];
    assert.deepEqual(montarFamilias(invertido).map((f) => f.chave), ['f-3', SEM_FAMILIA]);
});

test('oferta sem preço calculado fica de fora, e bloco que esvazia some com a família dele', () => {
    const soACadeira = montarFamilias(blocos(), (id) => id === 1);
    assert.deepEqual(soACadeira.map((f) => f.chave), ['f-3']);
    assert.deepEqual(ofertasDaFamilia(soACadeira[0]).map((o) => o.sku), ['CAD-01']);
    assert.deepEqual(montarFamilias(blocos(), () => false), []);
    assert.deepEqual(montarFamilias(null), []);
});

test('resumo da família: só do que há, no singular e no plural', () => {
    const [sala, sem] = montarFamilias(blocos());
    assert.equal(resumoDaFamilia(sala), '2 produtos · 2 combos · 1 conjunto');
    assert.equal(resumoDaFamilia(sem), '1 produto');
});

test('pendentes da família conta o que ainda não fechou preço', () => {
    const [sala] = montarFamilias(blocos());
    assert.equal(pendentesDaFamilia(sala, { 1: { pendencia: null }, 2: { pendencia: 'sem_frete' }, 3: { pendencia: 'sem_custo' }, 4: {}, 5: { pendencia: 'impossivel' } }), 3);
    assert.equal(pendentesDaFamilia(sala, {}), 0);
});

// ─── As linhas ───

test('linhas: produto com a seta, combos pendurados, depois o título e os conjuntos com a composição', () => {
    const [sala] = montarFamilias(blocos());
    const linhas = linhasDaFamilia(sala);

    assert.deepEqual(linhas.map((l) => (l.tipo === 'titulo' ? '— conjuntos —' : `${l.filho ? '  └ ' : ''}${l.oferta.sku}`)),
        ['CAD-01', '  └ CAD-01-CB2', '  └ CAD-01-CB4', 'MSA-MR', '— conjuntos —', 'MSA+CAD-KIT']);
    assert.equal(linhas[0].alternar, 1, 'a cadeira abre e fecha os combos dela');
    assert.equal(linhas[0].aberto, true);
    assert.equal(linhas[3].alternar, null, 'a mesa não tem combo: sem seta');
    assert.deepEqual(linhas[0].tambemEm.map((k) => k.sku), ['MSA+CAD-KIT']);
    assert.deepEqual(linhas[5].itens.map((c) => `${c.quantidade}× ${c.sku}`), ['1× MSA-MR', '1× CAD-01']);
});

test('recolher os combos de um produto esconde só os dele', () => {
    const [sala] = montarFamilias(blocos());
    const linhas = linhasDaFamilia(sala, { recolhidos: { 1: true } });
    assert.deepEqual(linhas.filter((l) => l.tipo === 'oferta').map((l) => l.oferta.sku), ['CAD-01', 'MSA-MR', 'MSA+CAD-KIT']);
    assert.equal(linhas[0].aberto, false);
    assert.equal(linhas[0].alternar, 1, 'a seta continua lá para abrir de novo');
});

test('filtrando, a lista fica rasa: sem recuo, sem seta e sem "também entra em"', () => {
    const [sala] = montarFamilias(blocos());
    const linhas = linhasDaFamilia(sala, { filtrando: true, recolhidos: { 1: true } }).filter((l) => l.tipo === 'oferta');
    assert.deepEqual(linhas.map((l) => l.oferta.sku), ['CAD-01', 'CAD-01-CB2', 'CAD-01-CB4', 'MSA-MR', 'MSA+CAD-KIT']);
    assert.ok(linhas.every((l) => ! l.filho && l.alternar === null));
    assert.ok(linhas.slice(0, 4).every((l) => l.tambemEm.length === 0));
});

test('filtro por tipo tirou o produto e deixou os combos: eles aparecem direto, na família dele', () => {
    const soCombos = montarFamilias([{ chave: 1, familia: SALA, ofertas: [CB2, CB4], tambem_em: [resumoKit] }]);
    assert.equal(soCombos[0].produtos[0].principal, null);
    assert.equal(resumoDaFamilia(soCombos[0]), '2 combos');
    const linhas = linhasDaFamilia(soCombos[0], { filtrando: true });
    assert.deepEqual(linhas.map((l) => [l.oferta.sku, l.filho]), [['CAD-01-CB2', false], ['CAD-01-CB4', false]]);
});

test('onde está cada oferta: o atalho sabe que família abrir e de que produto mostrar os combos', () => {
    const onde = ondeEstaCadaOferta(montarFamilias(blocos()));
    assert.deepEqual(onde[1], { familia: 'f-3', produto: null });
    assert.deepEqual(onde[3], { familia: 'f-3', produto: 1 });
    assert.deepEqual(onde[5], { familia: 'f-3', produto: null });
    assert.deepEqual(onde[6], { familia: SEM_FAMILIA, produto: null });
    assert.equal(onde[99], undefined);
});

test('filtros de tipo: a contagem é a da empresa inteira (o painel), e um só fica ativo', () => {
    const painel = { ofertas: 9, por_fase: { simples: 2, combo: 5, kit: 1, combit: 1 } };
    assert.deepEqual(filtrosDeTipo(painel, null).map((t) => `${t.rotulo} ${t.quantos}${t.ativo ? '*' : ''}`), ['Todos 9*', 'Simples 2', 'Combo 5', 'Kit 1', 'Combit 1']);
    assert.deepEqual(filtrosDeTipo(painel, 'combo').filter((t) => t.ativo).map((t) => t.chave), ['combo']);
    assert.deepEqual(filtrosDeTipo(null, null).map((t) => t.quantos), [0, 0, 0, 0, 0]);
});

// ─── A página, desenhada de verdade ───

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const PAGINA = 'resources/js/Pages/Portal/EstruturaPrecificacao.jsx';

global.route = (nome, params) => '/' + nome + (params !== undefined && params !== null && typeof params !== 'object' ? `/${params}` : '');
global.__PROPS__ = {};

const stub = (nome, fonte) => {
    const arquivo = path.join(__dirname, `.precificacao-familia-${nome}-${process.pid}.mjs`);
    fs.writeFileSync(arquivo, fonte, 'utf8');

    return arquivo;
};
const STUB_INERTIA = stub('inertia', `
import React from 'react';
export function Link({ href, children, className }) { return React.createElement('a', { href, className }, children); }
export const router = { get: () => {}, put: () => {}, post: () => {}, reload: () => {} };
export function usePage() { return { props: globalThis.__PROPS__ }; }
`);
const STUB_LAYOUT = stub('layout', `
import React from 'react';
export default function PortalClienteLayout({ children, titulo }) { return React.createElement('main', { 'data-titulo': titulo }, children); }
`);
const STUB_VAZIO = stub('vazio', `
export default function Nada() { return null; }
`);
after(() => { for (const f of [STUB_INERTIA, STUB_LAYOUT, STUB_VAZIO]) fs.rmSync(f, { force: true }); });

async function montarPagina() {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, PAGINA)],
        bundle: true, format: 'esm', platform: 'node', jsx: 'automatic', write: false, logLevel: 'silent',
        alias: {
            '@/Layouts/PortalClienteLayout': STUB_LAYOUT,
            '@/Components/Portal/Estrutura/Janela': STUB_VAZIO,
            '@/Components/Portal/Estrutura/ComoFunciona': STUB_VAZIO,
            '@inertiajs/react': STUB_INERTIA,
            '@': path.resolve(RAIZ, 'resources/js'),
        },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/react-dialog', '@radix-ui/react-popover'],
    });
    const arquivo = path.join(__dirname, `.precificacao-familia-pagina-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(arquivo, resultado.outputFiles[0].text, 'utf8');
    try {
        return (await import(pathToFileURL(arquivo).href)).default;
    } finally {
        fs.rmSync(arquivo, { force: true });
    }
}

const tipoDePreco = (minimo) => ({ minimo, anunciado: minimo === null ? null : Math.round(minimo * 120) / 100, comissao: 11.5, frete: 20, frete_origem: 'digitado', frete_sugerido: null });
const calculo = (custo, pendencia = null) => ({
    custo: { valor: custo, origem: custo === null ? null : 'digitado', calculado: null },
    frete_classico: 20, frete_premium: 25, do_produto: false, pendencia,
    excecoes: { comissao_classico: null, comissao_premium: null, imposto: null, margem_contribuicao: null, lucro_liquido: null },
    classico: tipoDePreco(pendencia ? null : 172.66), premium: tipoDePreco(pendencia ? null : 193.8),
});
const parametros = { comissao_classico: 11.5, comissao_premium: 16.5, imposto: 7, margem_contribuicao: 6, lucro_liquido: 6, acrescimo: 20 };

const props = (mais = {}) => ({
    empresa: { id: 1, nome: 'Seller' },
    modulos: [],
    estrutura: { painel: { ofertas: 6, por_fase: { simples: 3, combo: 2, kit: 1, combit: 0 } }, blocos: blocos(), paginacao: { pagina: 1, paginas: 1 } },
    precificacao: {
        parametros, padroes: parametros,
        resumo: { total: 6, precificadas: 4, sem_custo: 1, sem_frete: 1, impossivel: 0 },
        por_oferta: { 1: calculo(100), 2: calculo(200), 3: calculo(400, 'sem_frete'), 4: calculo(300), 5: calculo(400), 6: calculo(null, 'sem_custo') },
    },
    filtros: { q: '', tipo: null },
    ml_conectado: false,
    frete_tabela: null,
    ...mais,
});

test('a página por família: cabeçalhos, combos pendurados, conjuntos no fim e "Sem família" por último', async (contexto) => {
    const Pagina = await montarPagina();
    const html = renderToStaticMarkup(React.createElement(Pagina, props()));
    const posicao = (trecho) => { const i = html.indexOf(trecho); assert.notEqual(i, -1, `faltou ${trecho}`); return i; };

    await contexto.test('duas famílias, na ordem, cada uma com o resumo dela', () => {
        assert.equal((html.match(/data-familia-cabecalho=/g) ?? []).length, 2);
        assert.ok(posicao('data-familia="f-3"') < posicao('data-familia="sem-familia"'));
        assert.match(html, /Sala de Jantar<\/span><span[^>]*>2 produtos · 2 combos · 1 conjunto<\/span>/);
        assert.match(html, /Sem família<\/span><span[^>]*>1 produto<\/span><span[^>]*>Defina a família na ficha do produto para agrupar\.<\/span>/);
        // Pendentes: o CB4 (sem frete) na Sala; a poltrona (sem custo) no Sem família.
        assert.equal((html.match(/data-pendentes="1"/g) ?? []).length, 2);
    });

    await contexto.test('a ordem das linhas é a do desenho, e cada oferta aparece uma vez', () => {
        const ordem = [...html.matchAll(/data-linha-preco="(\d+)"/g)].map((m) => Number(m[1]));
        assert.deepEqual(ordem, [1, 2, 3, 4, 5, 6]);
        assert.ok(posicao('data-linha-preco="4"') < posicao('data-titulo-conjuntos') && posicao('data-titulo-conjuntos') < posicao('data-linha-preco="5"'));
        assert.match(html, /Conjuntos desta família/);
    });

    await contexto.test('combo é linha de combo (recuada, com a ligação); produto com combo tem a seta', () => {
        assert.equal((html.match(/data-nivel="combo"/g) ?? []).length, 2);
        assert.equal((html.match(/data-ligacao/g) ?? []).length, 2);
        assert.equal((html.match(/data-acao="alternar-combos"/g) ?? []).length, 1, 'só a cadeira tem combos');
        assert.match(html, /aria-label="Esconder os combos de CAD-01"/);
    });

    await contexto.test('o nó do kit: os dois produtos dizem "Também entra em", e o kit mostra do que é feito', () => {
        assert.equal((html.match(/data-tambem-em/g) ?? []).length, 2);
        // O atalho mostra tipo + código (o nome do conjunto é comprido e vai na dica), e é clicável: o kit está nesta página.
        assert.equal((html.match(/title="Ir para Kit Mesa \+ 1 Cadeira \(MSA\+CAD-KIT\)"[^>]*>Kit MSA\+CAD-KIT<\/button>/g) ?? []).length, 2);
        assert.equal((html.match(/data-composicao/g) ?? []).length, 1);
        assert.match(html, />1× Mesa Marfim<\/button>/);
        assert.match(html, />1× Cadeira 01<\/button>/);
    });

    await contexto.test('filtros de tipo com a contagem da empresa, e o resumo da página', () => {
        assert.match(html, /data-tipo="todos"[^>]*>Todos <span[^>]*>6<\/span>/);
        assert.match(html, /data-tipo="combo"[^>]*>Combo <span[^>]*>2<\/span>/);
        assert.match(html, /aria-pressed="true"[^>]*data-tipo="todos"|data-tipo="todos"[^>]*aria-pressed="true"/);
        assert.match(html, /disabled=""[^>]*data-tipo="combit"|data-tipo="combit"[^>]*disabled=""/, 'tipo sem nenhuma oferta fica desligado');
        assert.match(html, /6 ofertas · 2 grupos/);
        assert.match(html, /Recolher tudo/);
        assert.doesNotMatch(html, /\[object Object\]/);
    });

    await contexto.test('as contas continuam vindo do servidor: preço e situação de cada linha', () => {
        assert.equal((html.match(/data-anunciar/g) ?? []).length, 8, '4 ofertas com preço × 2 tipos');
        assert.equal((html.match(/>Precificado</g) ?? []).length, 4);
        assert.match(html, />Sem frete</);
        assert.match(html, />Sem custo</);
    });
});

test('com busca, a lista fica rasa e aberta; atalho para oferta fora da página vira só texto', async () => {
    const Pagina = await montarPagina();
    const soCombos = props({
        filtros: { q: '', tipo: 'combo' },
        estrutura: { painel: { ofertas: 6, por_fase: { simples: 3, combo: 2, kit: 1, combit: 0 } }, paginacao: { pagina: 1, paginas: 1 },
            blocos: [{ chave: 1, familia: SALA, ofertas: [CB2, CB4], tambem_em: [resumoKit] }] },
    });
    const html = renderToStaticMarkup(React.createElement(Pagina, soCombos));

    assert.deepEqual([...html.matchAll(/data-linha-preco="(\d+)"/g)].map((m) => Number(m[1])), [2, 3]);
    assert.doesNotMatch(html, /data-nivel="combo"/, 'raso: sem recuo');
    assert.doesNotMatch(html, /data-acao="alternar-combos"|data-tambem-em|Recolher tudo/);
    assert.match(html, /Sala de Jantar<\/span><span[^>]*>2 combos<\/span>/);
    assert.match(html, /data-tipo="combo"[^>]*aria-pressed="true"|aria-pressed="true"[^>]*data-tipo="combo"/);

    // Sem nada: a frase do filtro, e nenhuma família.
    const vazio = renderToStaticMarkup(React.createElement(Pagina, props({ estrutura: { ...soCombos.estrutura, blocos: [] } })));
    assert.match(vazio, /Nenhuma oferta com esse filtro\./);
    assert.doesNotMatch(vazio, /data-familia-cabecalho/);
});

test('a página liga o que o build não confere: imports, filtro que acompanha a busca e a página, e o atalho que abre o que estava fechado', () => {
    const f = lerSemComentarios(PAGINA);
    assert.match(f, /import\s*\{[^}]*\bmontarFamilias\b[^}]*\}\s*from\s*'@\/lib\/precificacaoPorFamilia'/s);
    for (const nome of ['filtrosDeTipo', 'linhasDaFamilia', 'ondeEstaCadaOferta', 'pendentesDaFamilia', 'resumoDaFamilia']) {
        assert.match(f, new RegExp(`import\\s*\\{[^}]*\\b${nome}\\b[^}]*\\}\\s*from\\s*'@/lib/precificacaoPorFamilia'`, 's'), nome);
    }
    assert.match(f, /import\s*\{[^}]*\bChevronDown\b[^}]*\bChevronRight\b[^}]*\}\s*from\s*'lucide-react'/);
    // O tipo escolhido não se perde ao buscar, ao trocar de página nem ao cotar.
    assert.match(f, /visitar\(\{ q: busca \|\| undefined, tipo: tipo \|\| undefined \}\)/);
    assert.match(f, /visitar\(\{ q: busca \|\| undefined, tipo: tipo \|\| undefined, pagina \}\)/);
    assert.match(f, /tipo: tipo \|\| undefined, pagina: paginacao\.pagina > 1 \? paginacao\.pagina : undefined, cotar: 1/);
    // O atalho abre a família e os combos antes de rolar até a linha.
    assert.match(f, /setFechadas\(\(f\) => \(\{ \.\.\.f, \[lugar\.familia\]: false \}\)\)/);
    assert.match(f, /if \(lugar\.produto !== null\) setRecolhidos\(\(r\) => \(\{ \.\.\.r, \[lugar\.produto\]: false \}\)\)/);
    assert.match(f, /document\.getElementById\(`oferta-\$\{id\}`\)/);
    assert.match(f, /<tr id=\{`oferta-\$\{oferta\.id\}`\}/);
});
