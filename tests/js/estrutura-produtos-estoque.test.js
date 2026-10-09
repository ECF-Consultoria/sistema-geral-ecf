import test from 'node:test';
import assert from 'node:assert/strict';
import { linhaDoServidor, linhaParaServidor } from '../../resources/js/lib/produtosEstrutura.js';
import { lerSemComentarios } from './_fonte.js';

// Fase 172-02: estoque por variação na ficha. 0 e vazio são coisas diferentes;
// variação nova não herda o estoque da primeira; o JS só manda o texto cru
// (a validação é do servidor).

const base = { id: 5, produto_id: 9, codigo: 'A-1', nome: 'Mesa', volumes: [] };

test('linhaDoServidor: 0 vira "0", nulo vira vazio, número vira texto', () => {
    assert.equal(linhaDoServidor({ ...base, estoque: 0 }).estoque, '0');
    assert.equal(linhaDoServidor({ ...base, estoque: null }).estoque, '');
    assert.equal(linhaDoServidor({ ...base, estoque: 7 }).estoque, '7');
    assert.equal(linhaDoServidor({ ...base }).estoque, '');
});

test('linha existente: não mexeu = chave ausente; alterou = valor; limpou = null; 0 vai como "0"', () => {
    const row = linhaDoServidor({ ...base, estoque: 7 });
    assert.equal('estoque' in linhaParaServidor(row), false);

    assert.equal(linhaParaServidor({ ...row, estoque: '12' }).estoque, '12');
    assert.equal(linhaParaServidor({ ...row, estoque: '0' }).estoque, '0');
    assert.equal(linhaParaServidor({ ...row, estoque: '' }).estoque, null);
});

test('variação nova de produto gravado manda estoque explícito (null quando vazio)', () => {
    const nova = { _k: 'm1', produto_id: 9, codigo: 'A-2', nome: 'Mesa', estoque: '', volumes: [] };
    assert.equal(linhaParaServidor(nova).estoque, null);
    assert.equal(linhaParaServidor({ ...nova, estoque: '3' }).estoque, '3');
});

test('gate de fonte: o hook zera o estoque na variação nova e o cartão tem o campo neutro', () => {
    const hook = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/useFichaProduto.js');
    const nova = hook.slice(hook.indexOf('const novaVariacao'));
    assert.match(nova.slice(0, nova.indexOf('return nova._k')), /estoque:\s*''/);

    const cartao = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/CartaoVariacao.jsx');
    assert.match(cartao, /estoque-\$\{k\}/);
    // O cartão já fala de "anúncios" na linha da oferta (Lista SKUs); o gate vale para o BLOCO do estoque.
    const bloco = cartao.slice(cartao.indexOf('htmlFor={`estoque-'), cartao.indexOf('podeExcluir &&'));
    assert.ok(bloco.length > 50);
    assert.doesNotMatch(bloco, /mercado|an[uú]ncio|publicar|MLB/i);
});
