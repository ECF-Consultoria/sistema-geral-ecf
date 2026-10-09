import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import { mostraSeloDaIa } from '../../resources/js/Components/Publicador/derivados.js';

// Preparo pela IA ao salvar no Portal (09/10/2026): o selo discreto no editor e o sinal de "editor aberto".

const BASE = 'resources/js/Components/Publicador';

test('mostraSeloDaIa — só com a marca do servidor e a tela mostrando o mesmo valor', () => {
    assert.equal(mostraSeloDaIa(true, 'Cadeira Giratória', 'Cadeira Giratória'), true);
    assert.equal(mostraSeloDaIa(true, 'Cadeira Giratória editada', 'Cadeira Giratória'), false, 'a pessoa começou a editar');
    assert.equal(mostraSeloDaIa(false, 'Cadeira', 'Cadeira'), false, 'o servidor diz que não é mais da IA');
    assert.equal(mostraSeloDaIa(undefined, 'Cadeira', 'Cadeira'), false, 'estado antigo, sem preparo_ia');
    assert.equal(mostraSeloDaIa(true, '', ''), false, 'campo vazio nunca leva selo');
    assert.equal(mostraSeloDaIa(true, null, undefined), false);
});

test('o selo aparece no título, no Modelo e na descrição — 13px e sem amarelo', () => {
    const comum = lerSemComentarios(`${BASE}/Mesa/comum.jsx`);
    const trecho = comum.slice(comum.indexOf('export function GeradoPelaIa'), comum.indexOf('export function Subtitulo'));
    assert.match(trecho, /Gerado pela IA a partir da ficha do Portal\./);
    assert.match(trecho, /text-\[13px\]/);
    assert.doesNotMatch(trecho, /yellow|amber/);

    const produto = lerSemComentarios(`${BASE}/Mesa/EtapaProduto.jsx`);
    assert.match(produto, /mostraSeloDaIa\(m\.estado\.preparo_ia\?\.titulos\?\.\[lt\]/);
    assert.match(produto, /<GeradoPelaIa campo=\{`titulo-\$\{lt\}`\} \/>/);

    const detalhes = lerSemComentarios(`${BASE}/Mesa/EtapaDetalhes.jsx`);
    assert.match(detalhes, /preparo_ia\?\.modelo/);
    assert.match(detalhes, /<GeradoPelaIa campo="modelo" \/>/);
    assert.match(detalhes, /preparo_ia\?\.descricao/);
    assert.match(detalhes, /<GeradoPelaIa campo="descricao" \/>/);
});

test('o editor manda o sinal de aberto só com a aba visível', () => {
    const hook = lerSemComentarios(`${BASE}/usePublicador.js`);
    assert.match(hook, /rota\('presenca', produtoId\)/);
    assert.match(hook, /document\.visibilityState !== 'visible'/);
    assert.match(hook, /clearInterval\(t\)/);
});
