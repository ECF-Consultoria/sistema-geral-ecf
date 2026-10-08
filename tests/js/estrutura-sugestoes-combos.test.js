import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import {
    alternarCombos, montarGrupos, qualEstadoVazio, textoCombosCortados, textoVerCombos,
} from '../../resources/js/lib/sugestoesEstrutura.js';
import { aceitarMarcadas, estadoInicial, marcarVarias } from '../../resources/js/lib/sugestoesSelecao.js';

// ═══════════════════════════════════════════════════════════════════════
// Combos recolhidos por família no Planejamento (08/10).
//
// POR QUE EXISTE: os Combos (cadeira x2/x4/x6/x8 por cor) enchiam a 1ª página antes do
// primeiro Kit. O servidor manda os Combos de cada família como um bloco "Ver N combos"; a
// tela só monta os grupos e pede a família aberta. Marcar, aceitar e descartar continuam
// pela chave, iguais para um Combo aberto.
// ═══════════════════════════════════════════════════════════════════════

const item = (chave, fase, familia) => ({ chave, fase, familia: familia === null ? { id: null, nome: null } : { id: familia, nome: `F${familia}` } });

test('montarGrupos com grupos do servidor: família uma vez, Combos abertos no bloco', () => {
    const itens = [item('k1', 'kit', 1), item('c1', 'combit', 1), item('x1', 'combo', 1), item('k2', 'kit', 2)];
    const grupos = [
        { chave: '1', nome: 'F1', combos: { total: 3, expandido: true, mostrando: 1 } },
        { chave: '2', nome: 'F2', combos: null },
        { chave: 'sem', nome: null, combos: { total: 2, expandido: false, mostrando: 0 } },
    ];

    const r = montarGrupos(itens, grupos);

    assert.deepEqual(r.map((g) => g.chave), ['1', '2', 'sem']);
    assert.deepEqual(r[0].itens.map((i) => i.chave), ['k1', 'c1']);
    assert.deepEqual(r[0].combos.map((i) => i.chave), ['x1']);
    assert.deepEqual(r[0].bloco, { total: 3, expandido: true, mostrando: 1 });
    assert.equal(r[1].bloco, null);
    assert.deepEqual(r[2].itens, []);
    assert.deepEqual(r[2].combos, []);
});

test('montarGrupos com filtro Combo (bloco nulo): o Combo é linha comum do grupo', () => {
    const r = montarGrupos([item('x1', 'combo', 1)], [{ chave: '1', nome: 'F1', combos: null }]);
    assert.deepEqual(r[0].itens.map((i) => i.chave), ['x1']);
    assert.equal(r[0].combos, null);
});

test('montarGrupos sem grupos (aba Descartadas antiga): agrupa os consecutivos', () => {
    const r = montarGrupos([item('a', 'kit', 1), item('b', 'combo', 1), item('c', 'kit', null)]);
    assert.deepEqual(r.map((g) => [g.chave, g.itens.length]), [['1', 2], ['sem', 1]]);
});

test('alternarCombos abre e fecha sem repetir', () => {
    assert.deepEqual(alternarCombos([], 12), ['12']);
    assert.deepEqual(alternarCombos(['12', 'sem'], 'sem'), ['12']);
    assert.deepEqual(alternarCombos(['12'], '12'), []);
});

test('textos do bloco', () => {
    assert.equal(textoVerCombos(1), 'Ver 1 combo');
    assert.equal(textoVerCombos(9), 'Ver 9 combos');
    assert.equal(textoCombosCortados(200, 340), 'Mostrando 200 de 340. Use o filtro Combo para ver todos.');
});

test('página só com blocos recolhidos não é estado vazio', () => {
    assert.equal(qualEstadoVazio({ temProdutos: true, contagens: { sugestoes: 6 }, filtroAtivo: true, qtdItens: 1 }), null);
});

test('Combo aberto marca e aceita pela chave como qualquer sugestão', async () => {
    const { estado } = marcarVarias(estadoInicial(), ['k1', 'x1'], 100);
    let enviado = null;
    const r = await aceitarMarcadas(estado, estado.marcadas, {
        limite: 100,
        enviar: async (pedidos) => { enviado = pedidos; return { criadas: [{ chave: 'k1' }, { chave: 'x1' }], ja_existiam: [], erros: [] }; },
    });
    assert.deepEqual(enviado.map((p) => p.chave), ['k1', 'x1']);
    assert.deepEqual(r.estado.marcadas, []);
});

// ─── Gate: a tela ───────────────────────────────────────────────────────

const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaSugestoes.jsx');

test('a tela monta os grupos do servidor e desenha o bloco de Combos', () => {
    assert.ok(pagina.includes('montarGrupos(itens, sugestoes.grupos)'));
    assert.ok(pagina.includes('data-bloco-combos={chave}') && pagina.includes('data-acao="alternar-combos"'));
    assert.ok(pagina.includes("'Ocultar combos'") && pagina.includes('textoVerCombos(bloco.total)'));
    assert.ok(pagina.includes('aria-expanded={bloco.expandido}'));
});

test('abrir a família mantém a página e os filtros; o bloco conta como conteúdo', () => {
    assert.ok(pagina.includes("visitar({ combos: abertos.join(',') || undefined, pagina: sugestoes.paginacao.pagina })"));
    assert.ok(pagina.includes('combos: proximo.combos'));
    assert.ok(pagina.includes('qtdItens: itens.length + grupos.filter((g) => g.bloco).length'));
    // "Selecionar todas" da família leva o que está na tela, inclusive os Combos abertos.
    assert.ok(pagina.includes('const visiveis = [...g.itens, ...(g.combos ?? [])];'));
    assert.ok(pagina.includes('onMarcarTodas={() => alternarVarias(visiveis.map((i) => i.chave))}'));
});

test('sigilo do portal no texto novo', () => {
    for (const proibida of ['mercado', 'anúncio', 'publicar', 'MLB']) {
        assert.ok(! textoVerCombos(3).toLowerCase().includes(proibida.toLowerCase()));
        assert.ok(! textoCombosCortados(1, 2).toLowerCase().includes(proibida.toLowerCase()));
    }
});
