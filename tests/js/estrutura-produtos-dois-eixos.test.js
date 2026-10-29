import test from 'node:test';
import assert from 'node:assert/strict';
import {
    contagemDoEixo, gruposPorCor, linhaDoServidor, linhaParaServidor, rotuloDoNovo, segundoEixoDe, valorDaVariacao,
} from '../../resources/js/lib/produtosEstrutura.js';
import { eixosEmUso } from '../../resources/js/lib/fichaTecnica.js';
import { lerSemComentarios } from './_fonte.js';

// Cor E tamanho (28/10/2026, o usuário: "deveria ter mais de um eixo, assim como é no mercado livre"):
// a variação tem o 2º eixo, a ficha agrupa pela cor e manda os dois ao servidor.

const linha = (id, valor, valor2, extra = {}) => ({
    id, produto_id: 9, codigo: `M-${id}`, nome: 'Meia', volumes: [],
    eixo_rotulo: 'Cor', valor, eixo_2_rotulo: 'Tamanho', valor_2: valor2, ...extra,
});

test('valorDaVariacao: os dois valores juntos, ou só o que houver', () => {
    assert.equal(valorDaVariacao({ valor: 'Verde', valor_2: '34-39' }), 'Verde · 34-39');
    assert.equal(valorDaVariacao({ valor: 'Verde', valor_2: '' }), 'Verde');
    assert.equal(valorDaVariacao({ valor: ' ', valor_2: null }), '');
});

test('segundoEixoDe: o rótulo do 2º eixo de qualquer variação, ou vazio', () => {
    assert.equal(segundoEixoDe([{ eixo_2_rotulo: '' }, { eixo_2_rotulo: 'Tamanho' }]), 'Tamanho');
    assert.equal(segundoEixoDe([{ eixo_rotulo: 'Cor' }]), '');
});

test('gruposPorCor: agrupa pela cor sem acento de caixa, na ordem, e a dona das fotos é a 1ª que tem foto', () => {
    const vars = [
        { _k: 'a', valor: 'Verde', valor_2: '34-39', imagens: [] },
        { _k: 'b', valor: 'Azul', valor_2: '34-39', imagens: [{ id: 1 }] },
        { _k: 'c', valor: 'verde', valor_2: '40-44', imagens: [{ id: 2 }] },
    ];

    const grupos = gruposPorCor(vars);

    assert.deepEqual(grupos.map((g) => [g.chave, g.valor, g.vars.map((v) => v._k), g.dona._k]), [
        ['a', 'Verde', ['a', 'c'], 'c'],
        ['b', 'Azul', ['b'], 'b'],
    ]);
});

test('gruposPorCor: cor sem foto nenhuma tem a 1ª variação como dona (é para ela que a foto sobe)', () => {
    const [g] = gruposPorCor([{ _k: 'x', valor: 'Rosa', imagens: [] }, { _k: 'y', valor: 'Rosa' }]);
    assert.equal(g.dona._k, 'x');
});

test('linhaDoServidor/linhaParaServidor: o 2º eixo vai só quando mudou, e esvaziado vai null', () => {
    const row = linhaDoServidor(linha(5, 'Verde', '34-39'));
    assert.equal(row.eixo_2_rotulo, 'Tamanho');
    assert.equal(row.valor_2, '34-39');
    assert.ok(! ('valor_2' in linhaParaServidor(row)), 'não mexeu');
    assert.equal(linhaParaServidor({ ...row, valor_2: '40-44' }).valor_2, '40-44');
    const tirou = linhaParaServidor({ ...row, eixo_2_rotulo: '', valor_2: '' });
    assert.equal(tirou.eixo_2, null);
    assert.equal(tirou.valor_2, null);
});

test('variação nova de produto gravado manda o 2º eixo explícito e a cor que veio da tela', () => {
    const nova = { _k: 'm1', produto_id: 9, codigo: 'M-9', nome: 'Meia', eixo_rotulo: 'Cor', valor: 'Verde', eixo_2_rotulo: 'Tamanho', valor_2: '40-44',
        _base: { codigo: '', valor: '', valor_2: '', eixo_rotulo: 'Cor', eixo_2_rotulo: 'Tamanho' } };
    const out = linhaParaServidor(nova);
    assert.equal(out.eixo, 'Cor');
    assert.equal(out.eixo_2, 'Tamanho');
    assert.equal(out.valor, 'Verde');
    assert.equal(out.valor_2, '40-44');
});

test('eixosEmUso: a ficha técnica esconde os DOIS eixos', () => {
    const usados = eixosEmUso([{ eixo_rotulo: 'Cor', eixo_2_rotulo: 'Tamanho' }], { cor: 'Cor', tamanho: 'Tamanho', material: 'Material' });
    assert.deepEqual(usados, ['cor', 'tamanho']);
});

test('rótulos: "Novo tamanho", "Nova cor", plural certo', () => {
    assert.equal(rotuloDoNovo('Tamanho'), 'Novo tamanho');
    assert.equal(rotuloDoNovo('Cor'), 'Nova cor');
    assert.equal(rotuloDoNovo('Outro'), 'Nova variação');
    assert.equal(contagemDoEixo(1, 'Tamanho'), '1 tamanho');
    assert.equal(contagemDoEixo(3, 'Voltagem'), '3 voltagens');
    assert.equal(contagemDoEixo(2, 'Material'), '2 materiais');
});

test('a ficha usa o bloco por cor só com o 2º eixo, e a galeria fica no bloco (não no cartão do tamanho)', () => {
    // 29/10/2026: a seção das variações saiu da página para CoresDoProduto.
    const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutoFicha.jsx');
    assert.match(pagina, /<CoresDoProduto /);
    const secao = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/CoresDoProduto.jsx');
    assert.match(secao, /ficha\.segundoEixo \?/);
    assert.match(secao, /<VariacoesPorCor /);
    const cartao = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/CartaoVariacao.jsx');
    assert.match(cartao, /! dentroDaCor && <GaleriaVariacao/);
    const bloco = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/VariacoesPorCor.jsx');
    assert.match(bloco, /<GaleriaVariacao variacao=\{g\.dona\}/);
});
