import test from 'node:test';
import assert from 'node:assert/strict';
import { lerTsvDoExcel, proximaEditavel } from '../../resources/js/lib/gradeTeclado.js';

// Comportamento das funções puras do teclado da grade (Fase 167-04).
// Caso real que motivou: colar 70 linhas do Excel gravava 10, sem aviso.

test('colar 70 linhas devolve 70 linhas', () => {
    const texto = Array.from({ length: 70 }, (_, i) => `REF${i}\tProduto ${i}\t${i * 2}`).join('\r\n') + '\r\n';
    const m = lerTsvDoExcel(texto);
    assert.equal(m.length, 70);
    assert.deepEqual(m[69], ['REF69', 'Produto 69', '138']);
});

test('celula entre aspas com quebra de linha e aspa dupla vira uma celula so', () => {
    const m = lerTsvDoExcel('a\t"linha1\nlinha2 ""citada"""\tc\nd\te\tf');
    assert.equal(m.length, 2);
    assert.deepEqual(m[0], ['a', 'linha1\nlinha2 "citada"', 'c']);
    assert.deepEqual(m[1], ['d', 'e', 'f']);
});

test('CRLF e LF dao o mesmo resultado e a ultima linha vazia some', () => {
    assert.deepEqual(lerTsvDoExcel('a\tb\r\nc\td\r\n'), lerTsvDoExcel('a\tb\nc\td\n'));
    assert.equal(lerTsvDoExcel('a\tb\n').length, 1);
    assert.deepEqual(lerTsvDoExcel(''), []);
});

const COLS = [
    { id: 'ref', type: 'text' },
    { id: 'custo', type: 'currency' },
    { id: 'total', type: 'readonly' },
    { id: 'margem', type: 'number', compute: () => 1 },
    { id: 'obs', type: 'text' },
];

test('Tab pula colunas calculadas e readonly', () => {
    assert.deepEqual(proximaEditavel(COLS, 0, 1, 1, 3), { r: 0, c: 4 });
});

test('Tab no fim da linha vai para a primeira editavel da seguinte', () => {
    assert.deepEqual(proximaEditavel(COLS, 0, 4, 1, 3), { r: 1, c: 0 });
});

test('Tab na ultima celula da ultima linha pede linha nova', () => {
    assert.deepEqual(proximaEditavel(COLS, 2, 4, 1, 3), { criarLinha: true });
});

test('Shift+Tab volta e fica parado no comeco da primeira linha', () => {
    assert.deepEqual(proximaEditavel(COLS, 1, 0, -1, 3), { r: 0, c: 4 });
    assert.deepEqual(proximaEditavel(COLS, 0, 4, -1, 3), { r: 0, c: 1 });
    assert.deepEqual(proximaEditavel(COLS, 0, 0, -1, 3), { r: 0, c: 0 });
});
