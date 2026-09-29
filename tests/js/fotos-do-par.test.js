import test from 'node:test';
import assert from 'node:assert/strict';
import { moverFoto, moverUmPasso, tornarCapa } from '../../resources/js/lib/fotosDoPar.js';

// ═══════════════════════════════════════════════════════════════════════
// A ordem das fotos do par (Anunciar, 29/09): a lista é a sequência do
// anúncio, a 1ª é a capa. O arraste, os botões ◀ ▶ e o "tornar capa" passam
// todos por estas funções puras — o que se testa aqui é o que vai para o
// rascunho e, dele, para `pictures` no POST /items.
// ═══════════════════════════════════════════════════════════════════════

const f = (...ids) => ids.map((id) => ({ id }));
const ids = (lista) => lista.map((x) => x.id);

test('arrastar para trás e para frente: a foto sai de onde estava e as outras abrem espaço', () => {
    assert.deepEqual(ids(moverFoto(f('A', 'B', 'C', 'D'), 2, 0)), ['C', 'A', 'B', 'D']);   // 3ª vira capa
    assert.deepEqual(ids(moverFoto(f('A', 'B', 'C', 'D'), 0, 3)), ['B', 'C', 'D', 'A']);   // capa vai para o fim
    assert.deepEqual(ids(moverFoto(f('A', 'B', 'C', 'D'), 1, 2)), ['A', 'C', 'B', 'D']);   // vizinhas trocam
    assert.deepEqual(ids(moverFoto(f('A', 'B', 'C', 'D'), 3, 1)), ['A', 'D', 'B', 'C']);
});

test('nenhuma foto se perde nem se repete, seja qual for o movimento', () => {
    const lista = f('A', 'B', 'C', 'D', 'E', 'F');
    for (let de = 0; de < lista.length; de++) {
        for (let para = 0; para < lista.length; para++) {
            const r = ids(moverFoto(lista, de, para));
            assert.equal(r.length, 6, `${de}→${para}`);
            assert.deepEqual([...r].sort(), ['A', 'B', 'C', 'D', 'E', 'F'], `${de}→${para}`);
            assert.equal(r[para], lista[de].id, `${de}→${para}: a foto tem de estar onde caiu`);
        }
    }
});

test('◀ ▶ movem um passo e param nas pontas', () => {
    assert.deepEqual(ids(moverUmPasso(f('A', 'B', 'C'), 1, -1)), ['B', 'A', 'C']);
    assert.deepEqual(ids(moverUmPasso(f('A', 'B', 'C'), 1, 1)), ['A', 'C', 'B']);
    const lista = f('A', 'B', 'C');
    assert.equal(moverUmPasso(lista, 0, -1), lista, 'a capa não vai para trás');
    assert.equal(moverUmPasso(lista, 2, 1), lista, 'a última não vai para frente');
});

test('tornar capa leva a foto para a 1ª posição', () => {
    assert.deepEqual(ids(tornarCapa(f('A', 'B', 'C', 'D'), 3)), ['D', 'A', 'B', 'C']);
    const lista = f('A', 'B');
    assert.equal(tornarCapa(lista, 0), lista, 'a capa já é a capa');
});

test('não muta a lista recebida e devolve a mesma referência quando nada muda (o autosave não dispara à toa)', () => {
    const lista = f('A', 'B', 'C');
    const copia = ids(lista);
    moverFoto(lista, 0, 2);
    assert.deepEqual(ids(lista), copia);
    assert.equal(moverFoto(lista, 1, 1), lista);
    assert.equal(moverFoto(lista, 5, 0), lista);
    assert.equal(moverFoto(lista, 0, -1), lista);
});
