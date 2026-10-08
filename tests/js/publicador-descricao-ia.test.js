import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import { decidirLeitura, deveDispararAuto, podeAplicarDescricao } from '../../resources/js/Components/Publicador/descricaoIa.js';

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
    assert.match(src, /decidirLeitura\(\{/);
    assert.match(src, /atual: ref\.current/, 'o estado é relido depois do await');
    assert.match(src, /if \(emVoo\.current\) return;/, 'uma leitura por vez');
    assert.match(src, /vivo: vivo\.current/, 'saiu da página, nada é aplicado');
    assert.match(src, /descricao-ia/);
});

test('editor — chama o hook na página e a seção mostra painel, botão e usar', () => {
    const ed = lerSemComentarios('resources/js/Pages/Mlb/Publicador/Editor.jsx');
    assert.equal((ed.match(/useDescricaoIa\(/g) ?? []).length, 1);
    assert.match(ed, /<EtapaDetalhes m=\{m\} descricaoIa=\{descricaoIa\}/);
    const et = lerSemComentarios(`${BASE}/Mesa/EtapaDetalhes.jsx`);
    for (const k of ['data-descricao-cliente', 'gerar-descricao-ia', 'usar-descricao-ia']) assert.ok(et.includes(k), k);
    assert.equal(et.includes('dangerouslySetInnerHTML'), false);
});

test('decidirLeitura — só o próprio pedido, ainda rodando, com a página viva (review 172 WR-01)', () => {
    const atual = { status: 'rodando', pedido: 'p1', automatico: true, textoNoPedido: '' };
    const pronto = { pedido: 'p1', status: 'pronto', valor: 'Texto' };
    const base = { vivo: true, atual, data: pronto, disabled: false, textoAgora: '' };

    assert.equal(decidirLeitura(base), 'aplicar');
    assert.equal(decidirLeitura({ ...base, vivo: false }), 'ignorar', 'saiu da página');
    assert.equal(decidirLeitura({ ...base, atual: { ...atual, status: 'erro' } }), 'ignorar', 'o tempo esgotou durante a leitura');
    assert.equal(decidirLeitura({ ...base, atual: { ...atual, status: 'parado' } }), 'ignorar', 'a 1ª leitura já aplicou: a 2ª não ressuscita o pronto');
    assert.equal(decidirLeitura({ ...base, atual: { ...atual, pedido: 'p2' } }), 'ignorar', 'pedido novo');
    assert.equal(decidirLeitura({ ...base, disabled: true }), 'guardar', 'mesa travada não recebe texto');
    assert.equal(decidirLeitura({ ...base, textoAgora: 'digitei' }), 'guardar', 'a pessoa digitou no meio');
    assert.equal(decidirLeitura({ ...base, data: { pedido: 'p1', status: 'rodando' } }), 'continuar');
    assert.equal(decidirLeitura({ ...base, data: { pedido: 'p1', status: 'erro' } }), 'erro');
});
