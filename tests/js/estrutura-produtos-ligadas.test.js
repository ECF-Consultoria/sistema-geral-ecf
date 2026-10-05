import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate das telas que mostram a oferta ligada ao Produtos (Fase 167-12,
// D-08/D-10/D-22).
//
// POR QUE EXISTE: o servidor já protege a oferta que veio do Produtos (167-03
// e 167-08). A tela não pode oferecer o que o servidor recusa: editar SKU, nome
// e fase, excluir pela lixeira, digitar custo. Lê a fonte SEM comentários.
// ═══════════════════════════════════════════════════════════════════════

const lista = lerSemComentarios('resources/js/Pages/Portal/EstruturaLista.jsx');
const form = lerSemComentarios('resources/js/Components/Portal/Estrutura/FormOferta.jsx');
const preco = lerSemComentarios('resources/js/Pages/Portal/EstruturaPrecificacao.jsx');

test('Lista SKUs: pílula "do Produtos" nos três lugares onde o SKU aparece', () => {
    assert.match(lista, /function DoProdutos\(/);
    assert.ok(lista.includes('do Produtos'));
    const usos = lista.match(/<DoProdutos\b/g) ?? [];
    assert.ok(usos.length >= 3, `esperava 3 usos, achei ${usos.length}`);
    assert.ok(lista.includes('oferta.variacao_id') || lista.includes('.variacao_id'));
});

test('Lista SKUs: oferta ligada troca a lixeira por "Excluir pelo Produtos"', () => {
    assert.ok(lista.includes('Excluir pelo Produtos'));
    assert.ok(lista.includes('Esta oferta vem do Produtos. Exclua a variação lá.'));
    assert.match(lista, /route\('portal\.auth\.estrutura\.produtos', \{ q: oferta\.sku \}\)/);
    assert.ok(lista.includes('data-acao="excluir-oferta"'), 'a lixeira segue para a oferta sem vínculo');
});

test('FormOferta: SKU, Nome e Fase viram texto na oferta ligada', () => {
    assert.ok(form.includes('Vem do Produtos.'));
    assert.ok(form.includes('Editar no Produtos'));
    assert.match(form, /route\('portal\.auth\.estrutura\.produtos', \{ q: base\.sku \}\)/);
    assert.match(form, /ligada\s*\?/, 'os inputs de SKU/Nome dependem de "ligada"');
    assert.ok(form.includes('base?.variacao_id'));
});

test('Precificação: custo vindo do produto é leitura, com link para o Produtos', () => {
    assert.ok(preco.includes('calculo.do_produto'));
    assert.ok(preco.includes('vem do produto'));
    assert.ok(preco.includes('Alterar no Produtos'));
    assert.match(preco, /route\('portal\.auth\.estrutura\.produtos', \{ q: oferta\.sku \}\)/);
    assert.match(preco, /doProduto \? \(/, 'o input de custo fica no ramo sem do_produto');
});

test('Precificação: o envio não leva custo quando a oferta é do Produtos', () => {
    assert.match(preco, /\.\.\.\(doProduto \? \{\} : \{ custo:/);
    assert.match(preco, /\.\.\.\(c\.do_produto \? \{\} : \{ custo:/, 'o ajuste de percentuais também omite o custo');
});
