import test, { beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { apagarRascunho, gravarRascunho, lerRascunho } from '../../resources/js/lib/produtosNavegacao.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Rascunho do produto NOVO sobrevive a fechar a aba (teste da demo 23/10, A5).
//
// POR QUE EXISTE: o rascunho do produto novo ficava só no sessionStorage, que
// morre com a aba. Agora vai também para o localStorage, numa chave POR EMPRESA,
// por 24 h, e sai dos dois ao salvar ou ao "Começar do zero". Roda o código real
// contra um `window` falso com os dois storages em memória.
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
    globalThis.window = { sessionStorage: memoria(), localStorage: memoria() };
});

const vars = [{ _k: 'm1', codigo: 'N1', nome: 'Poltrona nova' }];
const fecharAba = () => { window.sessionStorage = memoria(); };

test('produto novo: grava nos dois, com chave da empresa no localStorage', () => {
    gravarRascunho(null, vars, { descricao: 'Texto', escopo: 459 });
    assert.ok(window.sessionStorage._m.has('ecf.produtos.rascunho.novo'));
    assert.ok(window.localStorage._m.has('ecf.produtos.rascunho.novo.empresa.459'));
    assert.equal(window.localStorage._m.size, 1);
});

test('fechar a aba: o rascunho volta do localStorage da MESMA empresa, e só dela', () => {
    gravarRascunho(null, vars, { descricao: 'Texto', escopo: 459 });
    fecharAba();
    const r = lerRascunho(null, { escopo: 459 });
    assert.deepEqual(r.vars, vars);
    assert.equal(r.descricao, 'Texto');
    assert.equal(lerRascunho(null, { escopo: 460 }), null, 'outra empresa no mesmo navegador não vê');
    assert.equal(lerRascunho(null), null, 'sem empresa conhecida, nada do localStorage');
});

test('troca de empresa na mesma aba: o rascunho da aba de outra empresa não vale', () => {
    gravarRascunho(null, vars, { escopo: 459 });
    assert.equal(lerRascunho(null, { escopo: 460 }), null);
    assert.ok(lerRascunho(null, { escopo: 459 }));
});

test('validade de 24 h no localStorage: vencido é apagado', () => {
    const chave = 'ecf.produtos.rascunho.novo.empresa.459';
    window.localStorage.setItem(chave, JSON.stringify({ em: Date.now() - 25 * 60 * 60 * 1000, vars }));
    assert.equal(lerRascunho(null, { escopo: 459 }), null);
    assert.equal(window.localStorage._m.has(chave), false);

    window.localStorage.setItem(chave, JSON.stringify({ em: Date.now() - 20 * 60 * 60 * 1000, vars }));
    assert.ok(lerRascunho(null, { escopo: 459 }), '20 h ainda vale (o da aba vale 6 h)');
    window.localStorage.setItem(chave, '{quebrado');
    assert.equal(lerRascunho(null, { escopo: 459 }), null);
});

test('apagar (salvou ou "Começar do zero") limpa os dois', () => {
    gravarRascunho(null, vars, { escopo: 459 });
    gravarRascunho(null, vars, { escopo: 460 });
    apagarRascunho(null, { escopo: 459 });
    assert.equal(window.sessionStorage._m.has('ecf.produtos.rascunho.novo'), false);
    assert.equal(window.localStorage._m.has('ecf.produtos.rascunho.novo.empresa.459'), false);
    assert.ok(window.localStorage._m.has('ecf.produtos.rascunho.novo.empresa.460'), 'a outra empresa fica');
});

test('produto já salvo: só sessionStorage, como antes', () => {
    gravarRascunho(5, vars, { escopo: 459 });
    assert.ok(window.sessionStorage._m.has('ecf.produtos.rascunho.5'));
    assert.equal(window.localStorage._m.size, 0);
    fecharAba();
    assert.equal(lerRascunho(5, { escopo: 459 }), null);
});

test('escopo estranho não vira chave; storage bloqueado não quebra', () => {
    gravarRascunho(null, vars, { escopo: 'abc' });
    assert.equal(window.localStorage._m.size, 0);
    const bloqueado = { getItem() { throw new Error('x'); }, setItem() { throw new Error('cheio'); }, removeItem() { throw new Error('x'); } };
    globalThis.window = { sessionStorage: bloqueado, localStorage: bloqueado };
    assert.doesNotThrow(() => gravarRascunho(null, vars, { escopo: 459 }));
    assert.equal(lerRascunho(null, { escopo: 459 }), null);
    assert.doesNotThrow(() => apagarRascunho(null, { escopo: 459 }));
});

test('a ficha passa a empresa em toda leitura, gravação e limpeza do rascunho', () => {
    const hook = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/useFichaProduto.js');
    const semEscopo = [...hook.matchAll(/(?:ler|apagar)Rascunho\(([^;\n]*)\)/g)].filter((m) => ! m[1].includes('noEscopo'));
    assert.deepEqual(semEscopo.map((m) => m[0]), []);
    assert.equal((hook.match(/gravarRascunho\([^;\n]*escopo: escopoRascunho/g) ?? []).length, (hook.match(/gravarRascunho\(/g) ?? []).length);
    const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutoFicha.jsx');
    assert.ok(pagina.includes('escopo_rascunho: escopoRascunho'));
    assert.ok(pagina.includes('escopoRascunho })'));
});
