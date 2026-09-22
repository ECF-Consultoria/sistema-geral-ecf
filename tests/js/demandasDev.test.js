import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { compararCodigo, diasEntre, fmtData, ordenarFila, textoPrazo } from '../../resources/js/lib/demandasDev.js';

// ═══════════════════════════════════════════════════════════════════════
// Demandas Dev — helpers da tela.
//
// A ordem da fila tem de ser a MESMA do servidor (DemandasDevService::fila):
// faixa → prazo (sem prazo por último) → código. Se divergir, a fila da tela e
// a do backend mostram "a próxima demanda" diferente.
// ═══════════════════════════════════════════════════════════════════════

describe('ordenarFila', () => {
    test('faixa vence prazo; sem prazo vai para o fim da faixa; código desempata', () => {
        const fila = ordenarFila([
            { codigo: 'DEV-10', faixa_fila: 7, prazo: null },
            { codigo: 'DEV-2',  faixa_fila: 7, prazo: null },
            { codigo: 'DEV-3',  faixa_fila: 7, prazo: '2026-10-01' },
            { codigo: 'DEV-4',  faixa_fila: 2, prazo: '2026-12-01' },
            { codigo: 'DEV-5',  faixa_fila: 1, prazo: null },
        ]);
        assert.deepEqual(fila.map((d) => d.codigo), ['DEV-5', 'DEV-4', 'DEV-3', 'DEV-2', 'DEV-10']);
    });
});

describe('prazo', () => {
    const hoje = '2026-09-22';

    test('diasEntre atravessa virada de mês sem fuso', () => {
        assert.equal(diasEntre('2026-09-29', '2026-10-02'), 3);
        assert.equal(diasEntre(hoje, '2026-09-19'), -3);
    });

    test('textoPrazo', () => {
        assert.equal(textoPrazo({ prazo: null }, hoje), 'sem prazo');
        assert.equal(textoPrazo({ prazo: hoje }, hoje), 'vence hoje');
        assert.equal(textoPrazo({ prazo: '2026-09-23' }, hoje), 'vence amanhã');
        assert.equal(textoPrazo({ prazo: '2026-09-30' }, hoje), 'em 8 dias');
        assert.equal(textoPrazo({ prazo: '2026-09-21' }, hoje), '1 dia atrasada');
        assert.equal(textoPrazo({ prazo: '2026-09-01', encerrada: true }, hoje), '01/09');
    });

    test('fmtData omite o ano corrente', () => {
        assert.equal(fmtData('2026-09-25', hoje), '25/09');
        assert.equal(fmtData('2025-12-31', hoje), '31/12/2025');
        assert.equal(fmtData(null, hoje), '—');
    });
});

test('compararCodigo é natural', () => {
    assert.deepEqual(['DEV-10', 'DEV-9', 'ADM-1'].sort(compararCodigo), ['ADM-1', 'DEV-9', 'DEV-10']);
});
