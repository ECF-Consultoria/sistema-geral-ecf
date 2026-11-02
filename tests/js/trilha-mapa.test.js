import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import { PROPS_EQUIPE, paraCliente } from './_trilha-exemplo.js';
import { lerSemComentarios } from './_fonte.js';
import { COR_DA_ETAPA, chaveDaOferta, chaveDoProduto } from '../../resources/js/lib/trilha.js';
import {
    COR_ANTES_DAS_ONDAS,
    COR_DO_ANUNCIO,
    COR_MAPEADO,
    COR_SEM_ONDA,
    cabecalhosDoMapa,
    caixaDoNo,
    familiasDoMapa,
    legendaDoMapa,
    ligacaoAcesa,
    montarGrafo,
    posicionar,
    relacionados,
    resumoDoFluxo,
    situacaoDoAnuncio,
} from '../../resources/js/lib/trilhaMapa.js';

// ═══════════════════════════════════════════════════════════════════════════
// Trilha de produtos — o Mapa da loja (30/10/2026, 1ª entrega).
//
// POR QUE EXISTE: o mapa é o port do `mapaGrafo/mapaLayout/mapaRelacionados` do
// protótipo do Erlon para dados REAIS — família no lugar do ambiente, 3 colunas
// enquanto não há ondas, oferta sem produto na lista, e o MLB que NÃO pode
// aparecer na visão do cliente nem quando a equipe usa o "Ver como: Cliente"
// (o dado ainda é o da equipe). As funções puras são testadas direto; o
// componente, com render de verdade (esbuild + react-dom/server, como em
// estrutura-montar-kit-render.test.js), porque variável sem declarar no caminho
// do render passa no build e deixa a tela branca.
// ═══════════════════════════════════════════════════════════════════════════

const ids = (grafo, tipo) => grafo.nos.filter((n) => n.tipo === tipo).map((n) => n.id);
const ligacoesDe = (grafo) => grafo.ligacoes
    .map((l) => `${l.de}>${l.para}${l.quantidade > 1 ? ` ×${l.quantidade}` : ''}${l.via ? ` via ${l.via}` : ''}`)
    .sort();
const no = (grafo, id) => grafo.nos.find((n) => n.id === id);

/** O exemplo com uma onda de verdade: a simples da cadeira entrou na "Onda 1". */
const COM_ONDAS = {
    ...PROPS_EQUIPE,
    ondas: [{ id: 7, nome: 'Onda 1 · simples', data: '2026-11-10' }],
    ofertas: PROPS_EQUIPE.ofertas.map((o) => (o.id === 's-2' ? { ...o, onda_id: 7 } : o)),
};

/** Uma loja grande e embaralhada (60 produtos, combos, kits entre famílias), para o teste de sobreposição. */
function lojaGrande() {
    const familias = ['Mesas', 'Cadeiras', 'Racks', null, 'Sofás'];
    const produtos = Array.from({ length: 60 }, (_, i) => ({
        ...PROPS_EQUIPE.produtos[0],
        id: 100 + i,
        nome: `Produto ${i}`,
        codigo: `PX-${i}`,
        skus: [`PX-${i}`],
        familia: familias[i % 5],
        etapa: [null, 'lista', 'mapa'][i % 3],
        faltas: i % 3 === 1 ? ['peso'] : [],
    }));
    const base = { status: 'x', alerta: false, preparo: null, onda_id: null, sem_produto: false, anuncios: [] };
    const ofertas = [];
    produtos.forEach((p, i) => {
        const comp = (produto, quantidade) => ({ produto_id: produto.id, quantidade, sku: produto.codigo, nome: produto.nome });
        ofertas.push({
            ...base, id: `s-${p.id}`, tipo: 'Simples', nome: p.nome, sku: p.codigo, skus: [p.codigo], componentes: [comp(p, 1)],
            etapa: ['plano', 'robo', 'revisao', 'lanc'][i % 4],
            anuncios: i % 4 === 3 ? [{ tipo: 'Clássico', mlb: `MLB${9000 + i}`, situacao: null }, { tipo: 'Premium', mlb: `MLB${8000 + i}`, situacao: i % 8 === 3 ? 'Pausado' : null }] : [],
        });
        if (i % 3 === 0) {
            const n = 2 + (i % 5);
            ofertas.push({ ...base, id: `c-${p.id}-${n}`, tipo: 'Combo', nome: `Combo ${n} · ${p.nome}`, sku: `${p.codigo}-CB${n}`, skus: [], componentes: [comp(p, n)], etapa: 'plano' });
        }
        if (i % 7 === 0 && i + 13 < produtos.length) {
            const outro = produtos[i + 13];
            ofertas.push({ ...base, id: `o-${500 + i}`, tipo: 'Kit', nome: `${p.nome} + ${outro.nome}`, sku: `KT-${i}`, skus: [], componentes: [comp(p, 1), comp(outro, 1)], etapa: 'robo' });
        }
    });
    ofertas.push({ ...base, id: 'o-999', tipo: 'Simples', nome: 'Avulsa', sku: 'AV-1', skus: ['AV-1'], componentes: [{ produto_id: null, quantidade: 1, sku: 'AV-1', nome: 'Avulsa' }], etapa: 'lanc', sem_produto: true, anuncios: [{ tipo: 'Clássico', mlb: 'MLB1', situacao: null }] });

    return { produtos, ofertas, ondas: [] };
}

// ─── O grafo ────────────────────────────────────────────────────────────────

test('o grafo liga cada produto às ofertas que o levam (com ×N) e cada oferta aos seus anúncios', () => {
    const g = montarGrafo(PROPS_EQUIPE);

    assert.equal(g.temOndas, false);
    assert.equal(g.colunas, 3);
    assert.equal(ids(g, 'produto').length, 11);
    assert.deepEqual(ids(g, 'oferta').sort(), PROPS_EQUIPE.ofertas.map(chaveDaOferta).sort());
    assert.deepEqual(ids(g, 'onda'), []);
    assert.deepEqual(ids(g, 'anuncio'), ['m:s-1:0', 'm:s-1:1', 'm:s-10:0', 'm:o-40:0', 'm:o-40:1']);
    // O id do produto e o da oferta SÃO as chaves da tela: o "Abrir na trilha" os passa direto.
    for (const p of PROPS_EQUIPE.produtos) assert.ok(no(g, chaveDoProduto(p)), `faltou o produto ${p.id}`);

    assert.deepEqual(ligacoesDe(g), [
        'p:1>o:s-1', 'p:1>o:o-32', 'p:2>o:s-2', 'p:2>o:c-2-4 ×4', 'p:2>o:o-32 ×6', 'p:3>o:s-3', 'p:4>o:s-4', 'p:4>o:o-31', 'p:5>o:o-31',
        'p:10>o:s-10', 'p:11>o:s-11',
        // Sem ondas, a oferta liga direto aos anúncios.
        'o:s-1>m:s-1:0', 'o:s-1>m:s-1:1', 'o:s-10>m:s-10:0', 'o:o-40>m:o-40:0', 'o:o-40>m:o-40:1',
    ].sort());

    // A oferta sem produto na lista não tem de onde vir.
    assert.equal(no(g, 'o:o-40').semProduto, true);
    assert.ok(ids(g, 'oferta').filter((id) => id !== 'o:o-40').every((id) => no(g, id).semProduto === false));

    // Selo do tipo (o mesmo do protótipo) e a etapa como situação.
    assert.deepEqual(['o:s-1', 'o:c-2-4', 'o:o-31', 'o:o-32'].map((id) => no(g, id).sigla), ['S', 'CB', 'KIT', 'CBT']);
    assert.equal(no(g, 'o:s-11').situacao, 'Revisão e publicação');
    assert.equal(no(g, 'o:s-11').status, 'A publicação falhou');
    assert.equal(no(g, 'o:s-11').alerta, true);

    // Produto: falta (lista), falta mapear (mapa) ou mapeado (segue pelas ofertas).
    assert.deepEqual(['p:6', 'p:7', 'p:4', 'p:1'].map((id) => no(g, id).situacao), ['faltam 2', 'faltam 5', 'falta mapear', 'mapeado']);
    assert.equal(no(g, 'p:9').sub, 'CRIADO-01', 'sem código de grupo, o SKU da 1ª cor');

    // Anúncio da equipe: "{tipo} · {MLB}", com o nome da oferta em cima.
    assert.equal(no(g, 'm:s-1:0').sub, 'Clássico · MLB3412345678');
    assert.equal(no(g, 'm:s-1:0').titulo, 'Mesa de jantar jatobá 6 lugares');
    assert.equal(no(g, 'm:s-1:0').origem, 'o:s-1');
    assert.equal(no(g, 'm:s-10:0').situacao, 'Pausado');
});

test('duas cores do mesmo produto num kit viram uma linha só, com a soma', () => {
    const cadeira = PROPS_EQUIPE.produtos.find((p) => p.id === 2);
    const kit = {
        id: 'o-50', tipo: 'Kit', nome: 'Par de cadeiras natural + mel', sku: 'KT-NAT-MEL', skus: ['KT-NAT-MEL'], etapa: 'plano', status: 'Sem onda',
        alerta: false, anuncios: [], preparo: null, onda_id: null, sem_produto: false,
        componentes: [
            { produto_id: 2, quantidade: 1, sku: 'CJ-211-NAT', nome: 'Cadeira natural' },
            { produto_id: 2, quantidade: 1, sku: 'CJ-211-MEL', nome: 'Cadeira mel' },
        ],
    };

    assert.deepEqual(ligacoesDe(montarGrafo({ produtos: [cadeira], ofertas: [kit], ondas: [] })), ['p:2>o:o-50 ×2']);
});

test('as cores: produto pela etapa (mapeado fica neutro), oferta pela etapa, anúncio pela situação', () => {
    const g = montarGrafo(PROPS_EQUIPE);

    assert.equal(no(g, 'p:6').cor, COR_DA_ETAPA.lista);
    assert.equal(no(g, 'p:4').cor, COR_DA_ETAPA.mapa);
    assert.equal(no(g, 'p:1').cor, COR_MAPEADO);
    for (const o of PROPS_EQUIPE.ofertas) assert.equal(no(g, chaveDaOferta(o)).cor, COR_DA_ETAPA[o.etapa], o.id);
    assert.equal(no(g, 'm:s-1:0').cor, COR_DA_ETAPA.lanc, 'no ar = cor da etapa "No ar"');
    assert.equal(no(g, 'm:s-10:0').cor, COR_DO_ANUNCIO.atencao, 'pausado = âmbar');
    // A ligação tem a cor do que chega nela.
    assert.equal(g.ligacoes.find((l) => l.para === 'o:s-3').cor, COR_DA_ETAPA.robo);

    assert.equal(situacaoDoAnuncio({ situacao: null }), 'ativo');
    assert.equal(situacaoDoAnuncio({ situacao: 'Pausado' }), 'atencao');
    assert.equal(situacaoDoAnuncio({ situacao: 'Em revisão' }), 'atencao');
    assert.equal(situacaoDoAnuncio({ situacao: 'Encerrado' }), 'fora');
    // A régua do servidor (`SituacaoDasOfertas`, revisão de 31/10/2026): cinza só o que não volta a vender
    // (encerrado, inativo); o resto parado — inclusive o "Fora do ar" genérico, que o servidor dá a qualquer
    // situação nova do anúncio — liga o alerta lá e é âmbar aqui.
    for (const parado of ['Fora do ar', 'Aguardando pagamento', 'Ainda não ativado', 'Pausado pelo Mercado Livre']) {
        assert.equal(situacaoDoAnuncio({ situacao: parado }), 'atencao', parado);
    }
    assert.equal(situacaoDoAnuncio({ situacao: 'Inativo' }), 'fora');
    const foraDoAr = montarGrafo({
        ...PROPS_EQUIPE,
        ofertas: PROPS_EQUIPE.ofertas.map((o) => (o.id === 's-10'
            ? { ...o, status: 'Fora do ar · Clássico', alerta: true, anuncios: [{ ...o.anuncios[0], situacao: 'Fora do ar' }] }
            : o)),
    });
    assert.equal(no(foraDoAr, 'm:s-10:0').cor, COR_DO_ANUNCIO.atencao, '"Fora do ar" = âmbar, como "Em revisão"');

    // Em `.js` nada de classe do Tailwind (não seria gerada): só rgb(var(--t-…)).
    for (const n of g.nos) assert.match(n.cor, /^rgb\(var\(--t-[a-z0-9-]+\)\)$/, n.id);
    const legenda = legendaDoMapa(false);
    assert.deepEqual(legenda.etapas.map((e) => e.rotulo), ['Lista de produtos', 'Mapeamento', 'Planejamento', 'Robô', 'Revisão', 'No ar', 'Produto mapeado']);
    assert.ok(legendaDoMapa(true).etapas.some((e) => e.rotulo === 'Sem onda'));
    assert.ok(legendaDoMapa(true).etapas.some((e) => e.rotulo === 'Antes ou fora das ondas' && e.cor === COR_ANTES_DAS_ONDAS));
    assert.deepEqual(legenda.anuncios.map((e) => e.rotulo), ['No ar', 'Pausado, em revisão ou fora do ar', 'Encerrado ou inativo']);
});

// ─── Colunas e ondas ────────────────────────────────────────────────────────

test('sem ondas (hoje): 3 colunas — Produtos → Ofertas → Anúncios', () => {
    const g = montarGrafo(PROPS_EQUIPE);
    const L = posicionar(g);

    assert.equal(L.colunas.length, 3);
    assert.deepEqual([...new Set(L.nos.map((n) => n.x))].sort((a, b) => a - b), L.colunas.map((c) => c.x));
    assert.ok(L.nos.filter((n) => n.tipo === 'anuncio').every((n) => n.coluna === 2 && n.x === L.colunas[2].x));
    assert.deepEqual(cabecalhosDoMapa(g), [
        { titulo: 'Lista de produtos', detalhe: '11 produtos' },
        { titulo: 'Ofertas', detalhe: '10 ofertas' },
        { titulo: 'Anúncios', detalhe: '5 anúncios' },
    ]);
    assert.ok(L.colunas.every((c) => c.x + c.w <= L.largura), 'as caixas cabem no desenho');
});

test('com ondas: 4 colunas como no protótipo, "Sem onda" tracejada e o anúncio vindo da onda pela oferta', () => {
    const g = montarGrafo(COM_ONDAS);

    assert.equal(g.colunas, 4);
    assert.deepEqual(ids(g, 'onda'), ['w:7', 'w:sem', 'w:antes', 'w:fora']);
    // A data da onda é o dia em que o robô COMEÇA (2ª entrega): nada de "até".
    assert.deepEqual([no(g, 'w:7').sub, no(g, 'w:7').situacao], ['começa 10/11', '1 oferta']);
    assert.equal(no(montarGrafo({ ...COM_ONDAS, ondas: [{ ...COM_ONDAS.ondas[0], comecou: true }] }), 'w:7').sub, 'começou 10/11');
    // "Sem onda" (tracejada, âmbar) só com o que está no Planejamento: o combo e o kit.
    assert.deepEqual([no(g, 'w:sem').tracejado, no(g, 'w:sem').situacao, no(g, 'w:sem').cor], [true, '2 ofertas', COR_SEM_ONDA]);
    // O que já tinha passado do Planejamento sem onda vai para a caixa neutra "Antes das ondas".
    assert.deepEqual([no(g, 'w:antes').titulo, no(g, 'w:antes').situacao, no(g, 'w:antes').cor, !!no(g, 'w:antes').tracejado],
        ['Antes das ondas', '6 ofertas', COR_ANTES_DAS_ONDAS, false]);
    // A oferta sem produto na Lista não entra em onda: nem "Sem onda", nem "Antes das ondas" — "Fora das ondas".
    assert.deepEqual([no(g, 'w:fora').titulo, no(g, 'w:fora').sub, no(g, 'w:fora').situacao, no(g, 'w:fora').tracejado],
        ['Fora das ondas', 'sem produto na lista', '1 oferta', true]);
    assert.ok(g.ligacoes.some((l) => l.de === 'o:o-40' && l.para === 'w:fora'));
    // A sem produto que está no Planejamento também vai para "Fora das ondas" (não para "Sem onda · falta planejar").
    const avulsa = { ...PROPS_EQUIPE.ofertas.find((o) => o.id === 'o-40'), id: 'o-41', etapa: 'plano', status: 'Fora das ondas · sem produto na Lista', anuncios: [] };
    const comAvulsa = montarGrafo({ ...COM_ONDAS, ofertas: [...COM_ONDAS.ofertas, avulsa] });
    assert.equal(no(comAvulsa, 'w:sem').situacao, '2 ofertas');
    assert.equal(no(comAvulsa, 'w:fora').situacao, '2 ofertas');
    assert.ok(g.ligacoes.some((l) => l.de === 'o:s-2' && l.para === 'w:7'));
    assert.ok(g.ligacoes.some((l) => l.de === 'o:o-31' && l.para === 'w:sem'));
    assert.ok(ligacoesDe(g).includes('w:antes>m:s-1:0 via o:s-1'));

    const L = posicionar(g);
    assert.equal(L.colunas.length, 4);
    assert.ok(L.nos.filter((n) => n.tipo === 'anuncio').every((n) => n.coluna === 3));
    assert.ok(L.nos.filter((n) => n.tipo === 'onda').every((n) => n.h === 52));
    assert.deepEqual(cabecalhosDoMapa(g).map((c) => `${c.titulo}: ${c.detalhe}`), ['Lista de produtos: 11 produtos', 'Ofertas: 10 ofertas', 'Ondas: 1 onda', 'Anúncios: 5 anúncios']);

    assert.deepEqual([...relacionados(g, 'w:7')].sort(), ['o:s-2', 'p:2', 'w:7']);
    assert.ok(relacionados(g, 'm:s-1:0').has('w:antes'), 'o anúncio acende a onda da oferta dele');
    assert.ok(relacionados(g, 'p:1').has('w:antes'));
    // Escolhida a mesa, a linha onda → anúncio do espelho (outra oferta) não acende.
    const mesa = relacionados(g, 'p:1');
    const doEspelho = g.ligacoes.find((l) => l.para === 'm:s-10:0');
    assert.equal(ligacaoAcesa(doEspelho, mesa), false);
    assert.equal(ligacaoAcesa(g.ligacoes.find((l) => l.para === 'm:s-1:0'), mesa), true);
});

// ─── Família ────────────────────────────────────────────────────────────────

test('agrupa e filtra pela família ("Sem família" no fim)', () => {
    assert.deepEqual(familiasDoMapa(PROPS_EQUIPE).map((f) => `${f.nome} ${f.produtos}`), [
        'Aparadores 1', 'Cadeiras 2', 'Decoração 1', 'Mesas 1', 'Racks 3', 'Sofás 1', 'Sem família 2',
    ]);

    const racks = montarGrafo(PROPS_EQUIPE, { familia: 'Racks' });
    assert.equal(racks.familia, 'Racks');
    assert.deepEqual(ids(racks, 'produto'), ['p:4', 'p:5', 'p:11'], 'pelo SKU: CJ-213, CJ-214, CJ-302');
    assert.deepEqual(ids(racks, 'oferta').sort(), ['o:o-31', 'o:s-11', 'o:s-4']);
    assert.deepEqual(ids(racks, 'anuncio'), []);
    assert.ok(!no(racks, 'o:o-40'), 'a oferta sem produto não tem família');

    // O combit mesa + 6 cadeiras entra pela cadeira; a linha da mesa (outra família) fica de fora.
    assert.deepEqual(ligacoesDe(montarGrafo(PROPS_EQUIPE, { familia: 'Cadeiras' })), ['p:2>o:c-2-4 ×4', 'p:2>o:o-32 ×6', 'p:2>o:s-2']);
    assert.deepEqual(ids(montarGrafo(PROPS_EQUIPE, { familia: 'Mesas' }), 'anuncio'), ['m:s-1:0', 'm:s-1:1']);
    assert.deepEqual(ids(montarGrafo(PROPS_EQUIPE, { familia: 'Sem família' }), 'produto'), ['p:8', 'p:9']);

    // A família escolhida sumiu do dado (recarregou): volta a mostrar tudo, sem quebrar.
    const sumiu = montarGrafo(PROPS_EQUIPE, { familia: 'Família que não existe' });
    assert.equal(sumiu.familia, null);
    assert.equal(ids(sumiu, 'produto').length, 11);

    // No desenho, o nome de cada família em cima do seu grupo, na mesma ordem.
    const L = posicionar(montarGrafo(PROPS_EQUIPE));
    assert.deepEqual(L.rotulos.filter((r) => r.x === L.colunas[0].x).map((r) => r.texto), [
        'APARADORES', 'CADEIRAS', 'DECORAÇÃO', 'MESAS', 'RACKS', 'SOFÁS', 'SEM FAMÍLIA',
    ]);
});

// ─── Relacionados ───────────────────────────────────────────────────────────

test('escolher uma caixa acende de onde ela veio e o que ela gerou', () => {
    const g = montarGrafo(PROPS_EQUIPE);

    assert.equal(relacionados(g, null), null);
    assert.equal(relacionados(g, 'p:999'), null, 'id que não está no desenho');

    const mesa = relacionados(g, 'p:1');
    assert.deepEqual([...mesa].sort(), ['m:s-1:0', 'm:s-1:1', 'o:o-32', 'o:s-1', 'p:1'], 'as ofertas da mesa e os anúncios delas');
    assert.ok(!mesa.has('p:2'), 'o outro produto do combit não acende');

    assert.deepEqual([...relacionados(g, 'p:2')].sort(), ['o:c-2-4', 'o:o-32', 'o:s-2', 'p:2']);
    assert.deepEqual([...relacionados(g, 'o:o-32')].sort(), ['o:o-32', 'p:1', 'p:2'], 'a oferta acende os produtos dela');
    assert.deepEqual([...relacionados(g, 'o:s-1')].sort(), ['m:s-1:0', 'm:s-1:1', 'o:s-1', 'p:1']);
    assert.deepEqual([...relacionados(g, 'm:s-1:1')].sort(), ['m:s-1:1', 'o:s-1', 'p:1'], 'o Clássico da mesma oferta não acende');
    assert.deepEqual([...relacionados(g, 'o:o-40')].sort(), ['m:o-40:0', 'm:o-40:1', 'o:o-40']);

    assert.deepEqual(g.ligacoes.filter((l) => ligacaoAcesa(l, mesa)).map((l) => `${l.de}>${l.para}`).sort(), [
        'o:s-1>m:s-1:0', 'o:s-1>m:s-1:1', 'p:1>o:o-32', 'p:1>o:s-1',
    ]);
    assert.equal(ligacaoAcesa(g.ligacoes[0], null), false, 'sem escolha, nenhuma acende (todas ficam no meio-termo)');
});

// ─── Posições ───────────────────────────────────────────────────────────────

test('posições: caixas da mesma coluna nunca se sobrepõem, em qualquer recorte', () => {
    const cenarios = {
        exemplo: montarGrafo(PROPS_EQUIPE),
        cadeiras: montarGrafo(PROPS_EQUIPE, { familia: 'Cadeiras' }),
        'com ondas': montarGrafo(COM_ONDAS),
        'loja grande': montarGrafo(lojaGrande()),
        'loja grande, cliente': montarGrafo(lojaGrande(), { visao: 'cliente', familia: 'Racks' }),
        vazia: montarGrafo({ produtos: [], ofertas: [], ondas: [] }),
    };

    for (const [nome, g] of Object.entries(cenarios)) {
        const L = posicionar(g);
        assert.equal(L.nos.length, g.nos.length, `${nome}: toda caixa tem posição`);
        for (let c = 0; c < L.colunas.length; c++) {
            const coluna = L.nos.filter((n) => n.coluna === c).sort((a, b) => a.y - b.y);
            for (let i = 1; i < coluna.length; i++) {
                assert.ok(coluna[i - 1].y + coluna[i - 1].h <= coluna[i].y, `${nome}, coluna ${c}: ${coluna[i - 1].id} encosta em ${coluna[i].id}`);
            }
            assert.ok(coluna.every((n) => n.x === L.colunas[c].x && n.w === L.colunas[c].w), `${nome}: coluna ${c} alinhada`);
        }
        assert.ok(L.nos.every((n) => n.y >= 24 && n.y + n.h <= L.altura), `${nome}: tudo dentro do desenho`);
        assert.equal(L.ligacoes.length, g.ligacoes.length, `${nome}: toda ligação desenhada`);
        assert.ok(L.ligacoes.every((l) => /^M[\d.]+,[\d.]+ C[\d.]+,[\d.]+ [\d.]+,[\d.]+ [\d.]+,[\d.]+$/.test(l.d)), `${nome}: curva bem formada`);
        assert.ok(L.ligacoes.every((l) => l.x1 < l.x2), `${nome}: a linha anda da esquerda para a direita`);
    }

    const L = posicionar(montarGrafo(PROPS_EQUIPE));
    // A oferta sem produto vai para o fim da coluna, com o rótulo dela em cima.
    const ofertas = L.nos.filter((n) => n.tipo === 'oferta').sort((a, b) => a.y - b.y);
    assert.equal(ofertas.at(-1).id, 'o:o-40');
    const rotulo = L.rotulos.find((r) => r.texto === 'SEM PRODUTO NA LISTA');
    assert.ok(rotulo && rotulo.x === L.colunas[1].x && rotulo.y < L.pos['o:o-40'].y && rotulo.y > ofertas.at(-2).y + ofertas.at(-2).h);
    // O anúncio fica na altura da sua oferta (o segundo, logo abaixo do primeiro).
    assert.equal(L.pos['m:s-1:0'].y, L.pos['o:s-1'].y);
    assert.equal(L.pos['m:s-1:1'].y, L.pos['m:s-1:0'].y + 34 + 8);
    // As duas linhas que chegam no combit entram em alturas diferentes (o "×6" não cai em cima de nada).
    const noCombit = L.ligacoes.filter((l) => l.para === 'o:o-32');
    assert.equal(noCombit.length, 2);
    assert.notEqual(noCombit[0].y2, noCombit[1].y2);

    // O texto da caixa cabe nela: o título do kit (46 letras) é cortado, o da mesa não.
    const kit = caixaDoNo(L.pos['o:o-31']);
    assert.equal(kit.recuo, 'KIT'.length * 7 + 22, 'o texto começa depois do selo, como no protótipo');
    assert.ok(kit.titulo.endsWith('…') && kit.titulo.length * 7 <= L.pos['o:o-31'].w - kit.recuo);
    assert.equal(caixaDoNo(L.pos['p:1']).titulo, 'Mesa de jantar jatobá 6 lugares');
});

// ─── Visão do cliente ───────────────────────────────────────────────────────

test('visão do cliente: nenhum MLB no mapa — nem com o dado da equipe ("Ver como: Cliente")', () => {
    const equipe = montarGrafo(PROPS_EQUIPE);
    assert.match(JSON.stringify(equipe), /MLB3412345678/);

    for (const [nome, dados] of [['dado da equipe', PROPS_EQUIPE], ['porta do cliente', paraCliente()]]) {
        const g = montarGrafo(dados, { visao: 'cliente' });
        assert.equal(JSON.stringify([g, posicionar(g)]).match(/MLB\d/), null, nome);
        assert.deepEqual(g.nos.filter((n) => n.tipo === 'anuncio').map((n) => n.sub), ['Clássico', 'Premium', 'Clássico', 'Clássico', 'Premium'], nome);
        // A chave não depende do MLB: a caixa escolhida sobrevive à troca de visão.
        assert.deepEqual(ids(g, 'anuncio'), ids(equipe, 'anuncio'), nome);
    }

    // A situação que o Publicador grava fala da plataforma; o cliente não lê esse nome.
    const pausado = {
        ...PROPS_EQUIPE,
        ofertas: PROPS_EQUIPE.ofertas.map((o) => (o.id === 's-10' ? { ...o, anuncios: [{ tipo: 'Clássico', mlb: 'MLB3488800012', situacao: 'Pausado pelo Mercado Livre' }] } : o)),
    };
    const doCliente = montarGrafo(pausado, { visao: 'cliente' });
    assert.equal(no(doCliente, 'm:s-10:0').situacao, 'Pausado');
    assert.equal(no(doCliente, 'm:s-10:0').cor, COR_DO_ANUNCIO.atencao);
    assert.equal(JSON.stringify(doCliente).match(/Mercado Livre/), null);
    assert.equal(no(montarGrafo(pausado), 'm:s-10:0').situacao, 'Pausado pelo Mercado Livre', 'a equipe lê como veio');
});

// ─── Faixa do fluxo ─────────────────────────────────────────────────────────

test('faixa do fluxo: Lista → Mapeamento → Planejamento → Robô → Revisão → No ar, da loja inteira', () => {
    const fluxo = resumoDoFluxo(PROPS_EQUIPE);

    assert.deepEqual(fluxo.map((p) => p.titulo), ['Lista', 'Mapeamento', 'Planejamento', 'Robô', 'Revisão', 'No ar']);
    assert.deepEqual(fluxo.map((p) => `${p.numero} ${p.unidade}${p.detalhe ? ` · ${p.detalhe}` : ''}`), [
        '11 produtos · 4 incompletos',
        '10 ofertas · 2 produtos por mapear',
        '2 ofertas · esperando onda',
        '2 preparando',
        '3 para publicar · 2 com pendência',
        '3 ofertas · 5 anúncios',
    ]);
    assert.deepEqual(fluxo.map((p) => p.cor), ['lista', 'mapa', 'plano', 'robo', 'revisao', 'lanc'].map((k) => COR_DA_ETAPA[k]));

    assert.equal(resumoDoFluxo(COM_ONDAS)[2].numero, 1);
    assert.equal(`${resumoDoFluxo(COM_ONDAS)[2].unidade} · ${resumoDoFluxo(COM_ONDAS)[2].detalhe}`, 'em ondas · 2 sem onda');
    assert.deepEqual(resumoDoFluxo({}).map((p) => p.numero), [0, 0, 0, 0, 0, 0], 'loja vazia não quebra');
    assert.equal(resumoDoFluxo({ produtos: [PROPS_EQUIPE.produtos[5]], ofertas: [] })[0].unidade, 'produto');
});

// ─── O componente, desenhado de verdade ────────────────────────────────────

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const temporarios = [];

after(() => {
    for (const f of temporarios) fs.rmSync(f, { force: true });
});

async function montar(relativo, rotulo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, relativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js') },
        // Pacotes de node_modules ficam de fora (o Node os carrega): os CJS dentro do bundle ESM
        // quebram com "Dynamic require of react".
        external: ['react', 'react-dom', 'react-dom/server', 'react/jsx-runtime', 'lucide-react', 'clsx', 'tailwind-merge'],
    });
    const saida = path.join(__dirname, `.${rotulo}-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    temporarios.push(saida);
    fs.writeFileSync(saida, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(saida).href);
    } finally {
        fs.rmSync(saida, { force: true });
    }
}

const { createElement: h } = await import('react');
const { renderToStaticMarkup } = await import('react-dom/server');
const html = (el) => renderToStaticMarkup(el);
const texto = (s) => s.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/\s+/g, ' ');

/** Acha um elemento na árvore devolvida por um componente sem hooks (chamado como função). */
function acharElemento(el, pred) {
    if (!el || typeof el !== 'object') return null;
    if (Array.isArray(el)) {
        for (const filho of el) {
            const achado = acharElemento(filho, pred);
            if (achado) return achado;
        }

        return null;
    }
    if (pred(el)) return el;

    return acharElemento(el.props?.children, pred);
}

const DADOS = { produtos: PROPS_EQUIPE.produtos, ofertas: PROPS_EQUIPE.ofertas, ondas: PROPS_EQUIPE.ondas };

test('MapaDaLoja desenha a faixa do fluxo, o filtro de família, as 3 colunas e o painel — sem MLB para o cliente', async () => {
    const { default: MapaDaLoja } = await montar('resources/js/Components/Trilha/MapaDaLoja.jsx', 'trilha-mapa');

    const equipe = html(h(MapaDaLoja, { dados: DADOS, visao: 'equipe', onAbrir: () => {} }));
    const t = texto(equipe);
    for (const s of [
        'Mapa da loja', 'Lista', '4 incompletos', 'Mapeamento', '2 produtos por mapear', 'esperando onda', 'Robô', '2 com pendência', 'No ar', '5 anúncios',
        'Lista de produtos', '11 produtos', 'Ofertas', '10 ofertas', 'Anúncios',
        'Ainda não há ondas', 'Todas', 'Aparadores', 'Sem família', 'APARADORES', 'SEM FAMÍLIA', 'SEM PRODUTO NA LISTA', '×6', '×4',
        'Clique em qualquer caixa', 'Etapa (cor da caixa)', 'Produto mapeado', 'Pausado, em revisão ou fora do ar',
    ]) {
        assert.ok(t.includes(s), `faltou: ${s}`);
    }
    assert.match(equipe, /data-colunas="3"/);
    assert.match(equipe, /data-aviso-ondas/);
    assert.ok(equipe.includes('Clássico · MLB3412345678'), 'a equipe vê o MLB');
    // 26 caixas, todas alcançáveis pelo teclado; só o "Todas" ligado e nenhuma caixa escolhida.
    assert.equal((equipe.match(/role="button" tabindex="0"/g) ?? []).length, 26);
    assert.equal((equipe.match(/aria-pressed="true"/g) ?? []).length, 1);
    assert.match(equipe, /aria-pressed="true"[^>]*>Todas/);
    // Rolagem horizontal DENTRO da figura (largura mínima no miolo), não na página.
    assert.match(equipe, /class="overflow-x-auto"><div style="min-width:820px"/);
    // As cores entram por style (as do Tailwind não existem em .js).
    assert.match(equipe, /style="fill:rgb\(var\(--t-s-lista\)\)"/);

    for (const [nome, el] of [
        ['Ver como: Cliente', h(MapaDaLoja, { dados: DADOS, visao: 'cliente', onAbrir: () => {} })],
        ['porta do cliente', h(MapaDaLoja, { dados: paraCliente(), visao: 'cliente' })],
    ]) {
        const cliente = html(el);
        assert.equal(cliente.match(/MLB\d/), null, nome);
        assert.equal(texto(cliente).match(/Mercado Livre/), null, nome);
        assert.ok(cliente.includes('Anúncio Clássico de Mesa de jantar jatobá 6 lugares · No ar'), nome);
    }

    // Com ondas: 4 colunas e sem o aviso.
    const quatro = html(h(MapaDaLoja, { dados: COM_ONDAS, visao: 'equipe' }));
    assert.match(quatro, /data-colunas="4"/);
    assert.ok(!quatro.includes('data-aviso-ondas'));
    for (const s of ['Onda 1 · simples', 'começa 10/11', 'Sem onda', 'Antes das ondas', 'já tinham começado', 'Fora das ondas', 'sem produto na lista']) {
        assert.ok(texto(quatro).includes(s), `faltou: ${s}`);
    }
    assert.equal(texto(quatro).match(/(?:^|\s)até \d/), null, 'a onda não tem prazo');

    // Com a trilha de trabalho e nenhuma onda ainda: 3 colunas, e o aviso "Ainda não há ondas" (da 1ª entrega) sai.
    const semOndaAinda = html(h(MapaDaLoja, { dados: DADOS, visao: 'equipe', trabalho: true }));
    assert.match(semOndaAinda, /data-colunas="3"/);
    assert.ok(!semOndaAinda.includes('data-aviso-ondas'));

    // Loja vazia e dado faltando: não quebra.
    assert.ok(texto(html(h(MapaDaLoja, { dados: { produtos: [], ofertas: [], ondas: [] } }))).includes('nada para desenhar'));
    assert.ok(texto(html(h(MapaDaLoja, { dados: undefined }))).includes('nada para desenhar'));
    // Uma família só: o filtro não aparece.
    const umaFamilia = html(h(MapaDaLoja, { dados: { produtos: [PROPS_EQUIPE.produtos[0]], ofertas: [PROPS_EQUIPE.ofertas[0]], ondas: [] } }));
    assert.ok(!umaFamilia.includes('aria-label="Família"'));
});

test('painel do mapa: a caixa escolhida, o que está ligado a ela, "Abrir na trilha" com a chave da tela e "Limpar"', async () => {
    const { DetalheDoMapa } = await montar('resources/js/Components/Trilha/MapaDaLoja.jsx', 'trilha-mapa-detalhe');
    const painel = (visao, sel, extra = {}) => {
        const g = montarGrafo(DADOS, { visao });
        const desenho = posicionar(g);

        return { desenho, sel, acesos: relacionados(g, sel), onEscolher: () => {}, onAbrir: () => {}, onLimpar: () => {}, tituloRef: null, ...extra };
    };

    assert.ok(texto(html(h(DetalheDoMapa, painel('equipe', null)))).includes('Clique em qualquer caixa'));

    const doProduto = texto(html(h(DetalheDoMapa, painel('equipe', 'p:1'))));
    for (const s of ['Mesa de jantar jatobá 6 lugares', 'CJ-210', 'mapeado', 'Ofertas', 'Mesa de jantar jatobá 6 lugares + 6 cadeiras', 'Anúncios', 'Clássico · MLB3412345678', 'Abrir na trilha', 'Limpar']) {
        assert.ok(doProduto.includes(s), `faltou: ${s}`);
    }
    assert.ok(!doProduto.includes('Produtos da lista'), 'o produto escolhido não lista outros produtos');

    const daOferta = texto(html(h(DetalheDoMapa, painel('equipe', 'o:s-11'))));
    for (const s of ['Prateleira flutuante jatobá 1 m', 'Simples · CJ-302 · Revisão e publicação', 'A publicação falhou', 'Produtos da lista']) {
        assert.ok(daOferta.includes(s), `faltou: ${s}`);
    }
    assert.ok(texto(html(h(DetalheDoMapa, painel('equipe', 'o:o-40')))).includes('sem produto na lista'));

    // "Abrir na trilha" chama onAbrir com a chave da tela (p:12 / o:s-12); o anúncio leva à oferta dele.
    const abertos = [];
    for (const sel of ['p:1', 'o:c-2-4', 'm:s-10:0']) {
        const arvore = DetalheDoMapa(painel('equipe', sel, { onAbrir: (chave) => abertos.push(chave) }));
        acharElemento(arvore, (el) => el.props?.['data-acao'] === 'abrir-na-trilha').props.onClick();
    }
    assert.deepEqual(abertos, ['p:1', 'o:c-2-4', 'o:s-10']);
    assert.ok(texto(html(h(DetalheDoMapa, painel('equipe', 'm:s-10:0')))).includes('Abrir a oferta na trilha'));
    let limpou = false;
    acharElemento(DetalheDoMapa(painel('equipe', 'p:1', { onLimpar: () => { limpou = true; } })), (el) => el.props?.['data-acao'] === 'limpar-mapa').props.onClick();
    assert.equal(limpou, true);
    // Sem onAbrir, sem o botão (e o Limpar continua).
    const semAbrir = html(h(DetalheDoMapa, painel('equipe', 'p:1', { onAbrir: undefined })));
    assert.ok(!semAbrir.includes('abrir-na-trilha') && semAbrir.includes('limpar-mapa'));

    // Cliente: o anúncio escolhido mostra só o tipo e a situação.
    const anuncioDoCliente = html(h(DetalheDoMapa, painel('cliente', 'm:s-1:0')));
    assert.equal(anuncioDoCliente.match(/MLB\d/), null);
    assert.ok(texto(anuncioDoCliente).includes('Clássico · No ar'));
    assert.equal(html(h(DetalheDoMapa, painel('cliente', 'p:1'))).match(/MLB\d/), null);
});

test('caixa do mapa: Enter e Espaço escolhem (botão de verdade para o teclado), outra tecla não', async () => {
    const { NoDoMapa } = await montar('resources/js/Components/Trilha/MapaDaLoja.jsx', 'trilha-mapa-no');
    const desenho = posicionar(montarGrafo(DADOS));
    const escolhidos = [];
    const caixa = NoDoMapa({ no: desenho.pos['o:o-31'], aceso: true, escolhido: false, onAlternar: (id) => escolhidos.push(id), registrar: () => {} });

    assert.equal(caixa.props.role, 'button');
    assert.equal(caixa.props.tabIndex, 0);
    assert.equal(caixa.props['aria-pressed'], false);
    let evitou = 0;
    for (const key of ['Enter', ' ', 'a', 'Tab']) caixa.props.onKeyDown({ key, preventDefault: () => { evitou += 1; } });
    caixa.props.onClick();
    assert.deepEqual(escolhidos, ['o:o-31', 'o:o-31', 'o:o-31']);
    assert.equal(evitou, 2, 'Espaço não rola a página');

    const marcado = html(h(NoDoMapa, { no: desenho.pos['o:o-31'], aceso: false, escolhido: true, onAlternar: () => {}, registrar: () => {} }));
    assert.match(marcado, /aria-pressed="true"/);
    assert.match(marcado, /opacity="0.18"/);
    assert.match(marcado, />KIT</);
    assert.match(marcado, /<title>Kit: Kit rack 1,80 m \+ painel ripado 2,20 m jatobá · KT-CJ-213-CJ-214 · Planejamento · Sem onda<\/title>/);
});

test('o mapa só usa as cores da trilha (nada de branco fixo, ecf-*, pub-* nem hex)', () => {
    const tela = lerSemComentarios('resources/js/Components/Trilha/MapaDaLoja.jsx');
    assert.equal(tela.match(/\b(?:text|bg|border|fill|stroke|ring|outline)-white\b/), null);
    assert.equal(tela.match(/\b[a-z]+-ecf-/), null);
    assert.equal(tela.replace(/font-pub-mono/g, '').match(/\bpub-/), null);
    assert.equal(tela.match(/#[0-9a-fA-F]{3,8}\b/), null);

    // Em .js, classe do Tailwind não é gerada: a lib do mapa não pode ter nenhuma.
    const lib = lerSemComentarios('resources/js/lib/trilhaMapa.js');
    assert.equal(lib.match(/className|\b(?:bg|text|border|fill|stroke)-trilha-/), null);
});
