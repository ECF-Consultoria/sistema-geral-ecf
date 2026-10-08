import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import { deveDispararAuto, podeAplicarDescricao } from '../../resources/js/Components/Publicador/descricaoIa.js';

const BASE = 'resources/js/Components/Publicador';

test('deveDispararAuto — só com descrição vazia, descrição do cliente e mesa editável', () => {
    assert.equal(deveDispararAuto({ descricao: '', descricaoCliente: 'x', disabled: false }), true);
    assert.equal(deveDispararAuto({ descricao: '  ', descricaoCliente: 'x', disabled: false }), true);
    assert.equal(deveDispararAuto({ descricao: null, descricaoCliente: 'x', disabled: false }), true);
    assert.equal(deveDispararAuto({ descricao: 'a', descricaoCliente: 'x', disabled: false }), false);
    assert.equal(deveDispararAuto({ descricao: '', descricaoCliente: '', disabled: false }), false);
    assert.equal(deveDispararAuto({ descricao: '', descricaoCliente: null, disabled: false }), false);
    assert.equal(deveDispararAuto({ descricao: '', descricaoCliente: 'x', disabled: true }), false);
});

test('podeAplicarDescricao — automático só com campo vazio; manual só sem mudança', () => {
    assert.equal(podeAplicarDescricao({ automatico: true, textoNoPedido: '', textoAgora: '' }), true);
    assert.equal(podeAplicarDescricao({ automatico: true, textoNoPedido: '', textoAgora: 'oi' }), false);
    assert.equal(podeAplicarDescricao({ automatico: false, textoNoPedido: 'a', textoAgora: 'a' }), true);
    assert.equal(podeAplicarDescricao({ automatico: false, textoNoPedido: 'a', textoAgora: 'ab' }), false);
});

test('hook — aplica por mudarRasc, sem PUT, e só aceita o próprio pedido', () => {
    const src = lerSemComentarios(`${BASE}/useDescricaoIa.js`);
    assert.equal(src.includes('axios.put'), false);
    assert.ok((src.match(/mudarRasc/g) ?? []).length >= 2);
    assert.match(src, /data\.pedido !== atual\.pedido/);
    assert.match(src, /descricao-ia/);
});
