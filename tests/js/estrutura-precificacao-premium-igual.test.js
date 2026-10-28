import test from 'node:test';
import assert from 'node:assert/strict';
import { premiumIgualAoClassico } from '../../resources/js/lib/precificacaoSimulador.js';
import { lerSemComentarios } from './_fonte.js';

// 28/10/2026, o usuário (produto 77 da #459): "o preço está indo igual para os dois. Preço clássico é um e premium
// é outro, pois muda a comissão" — e o botão do Premium pisca vermelho e a ficha não salva até o Premium ter preço próprio.

const calculo = (classico, premium) => ({ classico: { minimo: classico }, premium: { minimo: premium } });

test('premiumIgualAoClassico: igual (até meio centavo) é Premium não preenchido', () => {
    assert.equal(premiumIgualAoClassico(calculo(299.21, 299.21)), true);
    assert.equal(premiumIgualAoClassico(calculo(299.21, 299.214)), true);
    assert.equal(premiumIgualAoClassico(calculo(299.21, 322.4)), false);
});

test('premiumIgualAoClassico: sem preço (sem custo) não conta — é outra pendência', () => {
    assert.equal(premiumIgualAoClassico(calculo(null, null)), false);
    assert.equal(premiumIgualAoClassico(calculo(100, null)), false);
    assert.equal(premiumIgualAoClassico({}), false);
    assert.equal(premiumIgualAoClassico(undefined), false);
});

const precificacao = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/PrecificacaoDoProduto.jsx');
const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutoFicha.jsx');
const campo = lerSemComentarios('resources/js/Components/Portal/Estrutura/Precificacao/SimuladorPrecificacao.jsx');

test('o botão do Premium pisca vermelho quando o preço dele é igual ao do Clássico', () => {
    assert.match(precificacao, /premiumIgualAoClassico\(i\.calculo\)/);
    assert.match(precificacao, /data-premium-pendente=/);
    assert.match(precificacao, /animate-blink-red ring-2 ring-inset ring-red-500/);
    assert.match(precificacao, /data-aviso-premium-igual/);
    // Produto novo fica como sempre (os dois vêm do padrão).
    assert.match(precificacao, /const premiumIguais = novo \? \[\] :/);
});

test('a comissão do Premium fica vermelha na aba Premium', () => {
    assert.match(precificacao, /invalido=\{premiumPendente && k === 'comissao_premium'\}/);
    assert.match(campo, /invalido && 'animate-blink-red border-red-500'/);
});

test('o Salvar produto não passa com o Premium igual: avisa, abre o Premium e não chama o salvar', () => {
    const salvar = pagina.slice(pagina.indexOf('const salvarProduto = async'), pagina.indexOf('const aoClicarProdutos') > pagina.indexOf('const salvarProduto = async') ? pagina.indexOf('const aoClicarProdutos') : undefined);
    const trava = salvar.indexOf('premiumPendente.length > 0');
    const chamada = salvar.indexOf('ficha.salvar()');
    assert.ok(trava > 0 && chamada > trava, 'a trava vem antes de salvar');
    assert.match(salvar, /setAbrirPremium\(/);
    assert.match(pagina, /onPremiumPendente=\{/);
    assert.match(pagina, /data-aviso-premium/);
});
