import test from 'node:test';
import assert from 'node:assert/strict';
import {
    textoAtualizado, percentualDoTotal, textoSugestoes, textoSelecionadas, rotuloAceitarSelecionadas,
    rotuloDaFase, textoDoComponente, ROTULO_STATUS, filtroAtivo, filtrosDaAba, controlesDaAba, dicaDaAba,
} from '../../resources/js/lib/sugestoesEstrutura.js';

// ═══════════════════════════════════════════════════════════════════════
// Redesenho da tela "Sugestões de ofertas" (Fase 168, plano 18).
// Funções puras REAIS, entrada -> saída. Os gates das peças ficam no fim.
// ═══════════════════════════════════════════════════════════════════════

test('textoAtualizado: agora, minutos e hora local', () => {
    const base = Date.parse('2026-10-07T12:00:00-03:00');
    const iso = (deltaSeg) => new Date(base - deltaSeg * 1000).toISOString();

    assert.equal(textoAtualizado(iso(10), base), 'Atualizado agora');
    assert.equal(textoAtualizado(iso(-30), base), 'Atualizado agora');
    assert.equal(textoAtualizado(iso(60), base), 'Atualizado há 1 min');
    assert.equal(textoAtualizado(iso(119), base), 'Atualizado há 1 min');
    assert.equal(textoAtualizado(iso(59 * 60 + 59), base), 'Atualizado há 59 min');

    const antigo = iso(3 * 3600);
    const d = new Date(antigo);
    const hh = String(d.getHours()).padStart(2, '0');
    const mm = String(d.getMinutes()).padStart(2, '0');
    assert.match(textoAtualizado(antigo, base), /^Atualizado às \d{2}:\d{2}$/);
    assert.equal(textoAtualizado(antigo, base), `Atualizado às ${hh}:${mm}`);

    assert.equal(textoAtualizado('nao-e-data', base), null);
    assert.equal(textoAtualizado('', base), null);
    assert.equal(textoAtualizado(null, base), null);
});

test('percentualDoTotal: números da referência e total zero', () => {
    assert.equal(percentualDoTotal(46, 181), '25% do total');
    assert.equal(percentualDoTotal(92, 181), '51% do total');
    assert.equal(percentualDoTotal(43, 181), '24% do total');
    assert.equal(percentualDoTotal(5, 0), '0% do total');
    assert.equal(percentualDoTotal(1, 3), '33% do total');
});

test('plurais: sugestões, selecionadas, rótulo da fase', () => {
    assert.equal(textoSugestoes(1), '1 sugestão');
    assert.equal(textoSugestoes(0), '0 sugestões');
    assert.equal(textoSugestoes(12), '12 sugestões');

    assert.equal(textoSelecionadas(1), '1 selecionada');
    assert.equal(textoSelecionadas(3), '3 selecionadas');
    assert.equal(textoSelecionadas(0), '0 selecionadas');
    assert.equal(rotuloAceitarSelecionadas(1), 'Aceitar 1 selecionada');
    assert.equal(rotuloAceitarSelecionadas(3), 'Aceitar 3 selecionadas');

    assert.equal(rotuloDaFase('combo', 1), 'Combo');
    assert.equal(rotuloDaFase('combo', 46), 'Combos');
    assert.equal(rotuloDaFase('kit', 2), 'Kits');
    assert.equal(rotuloDaFase('combit', 0), 'Combits');
});

test('textoDoComponente: quantidade, valor e SKU entre parênteses', () => {
    assert.equal(textoDoComponente({ quantidade: 4, produto_nome: 'Cadeira Polo', valor: 'Natural', sku: 'V201' }), '4x Cadeira Polo — Natural (V201)');
    assert.equal(textoDoComponente({ quantidade: 1, produto_nome: 'Mesa Polo', sku: 'V101' }), '1x Mesa Polo (V101)');
    assert.equal(textoDoComponente({ quantidade: 2, produto_nome: 'Banco Polo' }), '2x Banco Polo');
});

test('ROTULO_STATUS e filtroAtivo', () => {
    assert.deepEqual(ROTULO_STATUS, { prontas: 'Prontas para aceitar', com_aviso: 'Com aviso' });
    assert.equal(filtroAtivo({ fase: null, familia: null, tipo: null, q: '', status: null }), false);
    assert.equal(filtroAtivo({}), false);
    for (const campo of ['fase', 'familia', 'tipo', 'q', 'status']) {
        assert.equal(filtroAtivo({ [campo]: 'x' }), true, campo);
    }
});

test('filtrosDaAba: filtros que seguem para a outra aba', () => {
    const f = { aba: 'sugestoes', fase: 'kit', familia: '12', tipo: null, q: '', status: 'com_aviso', pagina: 3 };

    assert.deepEqual(filtrosDaAba(f, 'sem_tipo'), { aba: 'sem_tipo', fase: 'kit', familia: '12', status: 'com_aviso' });
    const paraSugestoes = filtrosDaAba(f, 'sugestoes');
    assert.equal('aba' in paraSugestoes, false);
    assert.equal('pagina' in paraSugestoes, false);
    assert.equal(paraSugestoes.fase, 'kit');
});

test('controlesDaAba e dicaDaAba', () => {
    assert.deepEqual(controlesDaAba('sugestoes'), { busca: true, familia: true, fase: true, tipo: true, status: true });
    assert.deepEqual(controlesDaAba('descartadas'), { busca: true, familia: true, fase: true, tipo: true, status: false });
    assert.deepEqual(controlesDaAba('sem_tipo'), { busca: true, familia: true, fase: false, tipo: false, status: false });

    assert.equal(dicaDaAba('sem_tipo'), 'Na aba Sem tipo valem só a busca e a família.');
    assert.equal(dicaDaAba('descartadas'), 'Na aba Descartadas o status não se aplica.');
    assert.equal(dicaDaAba('sugestoes'), null);
});
