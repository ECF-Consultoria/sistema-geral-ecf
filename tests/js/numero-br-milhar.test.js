// P-02 (23/10/2026): "1.299" digitado no preço do Publicador virava R$ 1,30 — as cópias do leitor de número só
// tratavam o ponto como milhar quando havia vírgula. A regra pt-BR mora em `lib/numeroBr.js` e vale para o preço
// do Publicador (`apoio.paraNumero`), o custo e o frete do Portal (`precificacaoSimulador.paraNumero` e a página
// da Precificação) e a comparação do campo único de frete (`freteUnico`).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { normalizarNumeroBr } from '../../resources/js/lib/numeroBr.js';
import { paraNumero as precoDoPublicador } from '../../resources/js/Components/Publicador/apoio.js';
import { dadosParaGravar, paraNumero as numeroDoPortal } from '../../resources/js/lib/precificacaoSimulador.js';
import { freteUnico } from '../../resources/js/lib/precificacaoFreteSugerido.js';
import { lerSemComentarios } from './_fonte.js';

test('a regra pt-BR: com vírgula ela é o decimal; sem vírgula, grupos de 3 depois do ponto são milhar', () => {
    assert.equal(normalizarNumeroBr('1.299,90'), '1299.90');
    assert.equal(normalizarNumeroBr('1.299'), '1299');
    assert.equal(normalizarNumeroBr('2.500'), '2500');
    assert.equal(normalizarNumeroBr('1.299.000'), '1299000');
    assert.equal(normalizarNumeroBr('12.5'), '12.5', 'um dígito depois do ponto é decimal');
    assert.equal(normalizarNumeroBr('1.29'), '1.29', 'dois dígitos depois do ponto é decimal');
    assert.equal(normalizarNumeroBr('1299'), '1299');
    assert.equal(normalizarNumeroBr('12,5'), '12.5');
    assert.equal(normalizarNumeroBr('0.500'), '0.500', 'número que começa com 0 não tem milhar (como o NumeroBr do servidor)');
    assert.equal(normalizarNumeroBr('1.2999'), '1.2999', 'quatro dígitos não é grupo de milhar');
});

test('medida não tem milhar: "1.500" kg é 1,5 kg, como o servidor lê a caixa', () => {
    assert.equal(normalizarNumeroBr('1.500', { milhar: false }), '1.500');
    assert.equal(normalizarNumeroBr('1,5', { milhar: false }), '1.5');
    assert.equal(numeroDoPortal('1.500', { milhar: false }), '1.500');
});

test('Publicador: o preço "1.299" é R$ 1.299, não R$ 1,30', () => {
    assert.equal(precoDoPublicador('1.299'), 1299);
    assert.equal(precoDoPublicador('2.500'), 2500);
    assert.equal(precoDoPublicador('R$ 1.299,90'), 1299.9);
    assert.equal(precoDoPublicador('1.299.000'), 1299000);
    assert.equal(precoDoPublicador('12.5'), 12.5);
    assert.equal(precoDoPublicador('1.29'), 1.29);
    assert.equal(precoDoPublicador(''), null);
    assert.equal(precoDoPublicador('abc'), null);
    assert.equal(precoDoPublicador(89.9), 89.9, 'número do servidor passa como está');
});

test('Portal: custo e frete "1.299" vão ao servidor como 1299', () => {
    assert.equal(numeroDoPortal('1.299'), '1299');
    assert.equal(numeroDoPortal('2.500'), '2500');
    assert.equal(numeroDoPortal('1.299,90'), '1299.90');
    assert.equal(numeroDoPortal('12.5'), '12.5');
    assert.deepEqual(
        dadosParaGravar({ doProduto: false, custo: '1.299', freteClassico: '2.500', fretePremium: '35,90', excecoes: {} }),
        { custo: '1299', frete_classico: '2500', frete_premium: '35.90', comissao_classico: '', comissao_premium: '', imposto: '', margem_contribuicao: '', lucro_liquido: '' },
    );
});

test('Portal: "1.200" e "1200,00" são o mesmo frete no campo único', () => {
    const digitado = (valor) => ({ frete_origem: 'digitado', frete: valor });
    assert.equal(freteUnico('1.200', '1200,00', { classico: digitado(1200), premium: digitado(1200) }).unico, true);
});

test('as cópias do leitor usam a regra única', () => {
    for (const arquivo of [
        'resources/js/Components/Publicador/apoio.js',
        'resources/js/lib/precificacaoSimulador.js',
        'resources/js/lib/precificacaoFreteSugerido.js',
        'resources/js/Pages/Portal/EstruturaPrecificacao.jsx',
    ]) {
        const fonte = lerSemComentarios(arquivo);
        assert.match(fonte, /normalizarNumeroBr\(/, `${arquivo} usa normalizarNumeroBr`);
        assert.doesNotMatch(fonte, /includes\(','\) \? \w+\.replace\(\/\\\.\/g, ''\)/, `${arquivo} não tem mais a cópia que só via milhar com vírgula`);
    }
});
