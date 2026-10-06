import test, { beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import {
    apagarRascunho, entradaAtual, gravarRascunho, lerRascunho, passosAte,
} from '../../resources/js/lib/produtosNavegacao.js';

// ═══════════════════════════════════════════════════════════════════════
// Rascunho da ficha e volta do histórico, de verdade (revisão da Fase 167, FE-CR-02).
//
// POR QUE EXISTE: o voltar do navegador descartava a ficha sem perguntar. A
// ficha passou a guardar um rascunho no sessionStorage e a devolver o histórico
// para a entrada dela quando a pessoa decide ficar. Aqui roda o código real
// contra um `window` falso: storage em memória e uma Navigation API mínima.
// ═══════════════════════════════════════════════════════════════════════

const memoria = () => {
    const m = new Map();

    return {
        getItem: (k) => (m.has(k) ? m.get(k) : null),
        setItem: (k, v) => { m.set(k, String(v)); },
        removeItem: (k) => { m.delete(k); },
        _m: m,
    };
};

beforeEach(() => {
    globalThis.window = { sessionStorage: memoria() };
});

const vars = [{ _k: 'v1', id: 1, codigo: 'A1', nome: 'Mesa', custo: '10,00' }];

test('rascunho: grava e lê por produto; produto novo tem a chave "novo", separada', () => {
    gravarRascunho(5, vars);
    gravarRascunho(null, [{ _k: 'm1', codigo: 'N1', nome: 'Novo' }]);

    assert.deepEqual(lerRascunho(5).vars, vars);
    assert.equal(typeof lerRascunho(5).em, 'number', 'tem carimbo de tempo');
    assert.equal(lerRascunho(null).vars[0].codigo, 'N1');
    assert.ok(window.sessionStorage._m.has('ecf.produtos.rascunho.5'));
    assert.ok(window.sessionStorage._m.has('ecf.produtos.rascunho.novo'));
});

test('rascunho: apagar some só com o do produto pedido', () => {
    gravarRascunho(5, vars);
    gravarRascunho(6, vars);
    apagarRascunho(5);

    assert.equal(lerRascunho(5), null);
    assert.ok(lerRascunho(6));
});

test('rascunho: vencido, vazio ou estranho não é oferecido e é apagado', () => {
    const s = window.sessionStorage;
    s.setItem('ecf.produtos.rascunho.7', JSON.stringify({ em: Date.now() - 7 * 60 * 60 * 1000, vars }));
    s.setItem('ecf.produtos.rascunho.8', JSON.stringify({ em: Date.now(), vars: [] }));
    s.setItem('ecf.produtos.rascunho.9', '{quebrado');

    assert.equal(lerRascunho(7), null);
    assert.equal(s._m.has('ecf.produtos.rascunho.7'), false);
    assert.equal(lerRascunho(8), null);
    assert.equal(lerRascunho(9), null);
});

test('rascunho: id que não é inteiro positivo cai na chave "novo" (nada cru vira chave)', () => {
    gravarRascunho('abc', vars);

    assert.ok(window.sessionStorage._m.has('ecf.produtos.rascunho.novo'));
});

test('rascunho: navegador que bloqueia o storage não quebra a ficha', () => {
    globalThis.window = { sessionStorage: { getItem() { throw new Error('bloqueado'); }, setItem() { throw new Error('cheio'); }, removeItem() { throw new Error('x'); } } };

    assert.doesNotThrow(() => gravarRascunho(1, vars));
    assert.equal(lerRascunho(1), null);
    assert.doesNotThrow(() => apagarRascunho(1));
});

test('volta do histórico: sem Navigation API, o caminho de volta é 1 passo à frente', () => {
    assert.equal(entradaAtual(), null);
    assert.equal(passosAte(null), 1);
    assert.equal(passosAte('qualquer'), 1);
});

test('volta do histórico: com Navigation API, anda até a entrada da ficha, para trás ou para a frente', () => {
    const entradas = ['lista', 'ficha', 'outra'].map((key, index) => ({ key, index }));
    const nav = { entries: () => entradas, currentEntry: entradas[1] };
    globalThis.window = { sessionStorage: memoria(), navigation: nav };

    assert.equal(entradaAtual(), 'ficha');

    nav.currentEntry = entradas[0];   // a pessoa voltou para a lista
    assert.equal(passosAte('ficha'), 1);

    nav.currentEntry = entradas[2];   // a pessoa avançou
    assert.equal(passosAte('ficha'), -1);

    assert.equal(passosAte('sumiu'), 1, 'entrada que não existe mais: caso comum');
});
