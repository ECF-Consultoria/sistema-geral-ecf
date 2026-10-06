import test, { beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import {
    apagarRascunho, entradaAtual, gravarRascunho, guardarRetorno, lerRascunho, passosAte, pegarVolta, rolarParaVolta, urlDeVolta,
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

// ─── Retorno e volta com validade (FE-IN-04) ────────────────────────────────

const naLista = (search = '') => {
    globalThis.route = () => 'https://admin.test/portal/estrutura/produtos';
    globalThis.window = {
        sessionStorage: memoria(),
        location: { origin: 'https://admin.test', pathname: '/portal/estrutura/produtos', search },
        scrollY: 640,
    };
};

test('retorno: guarda busca, página, rolagem e carimbo; a volta leva para a mesma lista', () => {
    naLista('?q=mesa&pagina=3');
    guardarRetorno();

    const r = JSON.parse(window.sessionStorage.getItem('ecf.produtos.retorno'));
    assert.equal(r.url, '/portal/estrutura/produtos?q=mesa&pagina=3');
    assert.equal(r.scrollY, 640);
    assert.equal(typeof r.em, 'number');
    assert.equal(urlDeVolta(), '/portal/estrutura/produtos?q=mesa&pagina=3');
});

test('retorno: de horas atrás não vale — a ficha aberta por URL volta para a lista pura', () => {
    naLista();
    window.sessionStorage.setItem('ecf.produtos.retorno', JSON.stringify({ url: '/portal/estrutura/produtos?q=velha', scrollY: 0, em: Date.now() - 3 * 60 * 60 * 1000 }));
    assert.equal(urlDeVolta(), '/portal/estrutura/produtos');

    window.sessionStorage.setItem('ecf.produtos.retorno', JSON.stringify({ url: '/portal/estrutura/produtos?q=sem-carimbo' }));
    assert.equal(urlDeVolta(), '/portal/estrutura/produtos', 'sem carimbo também não vale');
});

test('retorno: nunca vira redirecionamento aberto', () => {
    naLista();
    for (const url of ['https://mal.test/', '//mal.test', '/portal/estrutura/produtosX', '/outra']) {
        window.sessionStorage.setItem('ecf.produtos.retorno', JSON.stringify({ url, em: Date.now() }));
        assert.equal(urlDeVolta(), '/portal/estrutura/produtos', url);
    }
});

test('volta: velha não mostra "Produto salvo." de novo, e é consumida ao ler', () => {
    naLista();
    window.sessionStorage.setItem('ecf.produtos.volta', JSON.stringify({ aviso: 'Produto salvo.', produtoId: null, scrollY: 0, em: Date.now() - 5 * 60 * 1000 }));
    assert.equal(pegarVolta(), null);

    window.sessionStorage.setItem('ecf.produtos.volta', JSON.stringify({ aviso: 'Produto salvo.', produtoId: 12, scrollY: 300, em: Date.now() }));
    const volta = pegarVolta();
    assert.equal(volta.aviso, 'Produto salvo.');
    assert.equal(volta.produtoId, 12);
    assert.equal(pegarVolta(), null, 'recarregar a lista não repete o aviso');
});

test('volta: produtoId estranho não chega ao seletor', () => {
    naLista();
    window.sessionStorage.setItem('ecf.produtos.volta', JSON.stringify({ aviso: null, produtoId: '1"] , body [x="', scrollY: 50, em: Date.now() }));
    const volta = pegarVolta();
    assert.equal(volta.produtoId, null);

    const seletores = [];
    globalThis.requestAnimationFrame = (f) => f();
    globalThis.document = { querySelector: (s) => { seletores.push(s); return null; } };
    let rolou = null;
    window.scrollTo = (x, y) => { rolou = y; };
    rolarParaVolta({ produtoId: '1"]', scrollY: 50 });
    assert.deepEqual(seletores, [], 'nada cru vira seletor');
    assert.equal(rolou, 50);

    rolarParaVolta({ produtoId: 7, scrollY: 0 });
    assert.deepEqual(seletores, ['[data-produto-id="7"]']);
});
