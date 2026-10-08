import test from 'node:test';
import assert from 'node:assert/strict';
import { deveGravarDescricao, LIMITE_DESCRICAO } from '../../resources/js/Components/Portal/Estrutura/Produtos/useDescricaoProduto.js';
import { lerSemComentarios } from './_fonte.js';

// Fase 172-05: descrição do produto na ficha do portal. Função pura + gates de fonte
// (ordem de gravação, rótulo exato e sigilo: nada que cite a origem do campo).

const D = 'resources/js/Components/Portal/Estrutura/Produtos/';
const PROIBIDO = /mercado|an[uú]ncio|publicar|\bMLB\b/i;

test('deveGravarDescricao: igual após trim = false; diferente = true', () => {
    assert.equal(deveGravarDescricao('Mesa', 'Mesa'), false);
    assert.equal(deveGravarDescricao('Mesa', '  Mesa \n'), false);
    assert.equal(deveGravarDescricao('', '   '), false);
    assert.equal(deveGravarDescricao(null, ''), false);
    assert.equal(deveGravarDescricao('Mesa', 'Mesa grande'), true);
    assert.equal(deveGravarDescricao('Mesa', ''), true);
    assert.equal(deveGravarDescricao('', 'Nova'), true);
});

test('limite de 5000 caracteres no cliente espelha o servidor', () => {
    assert.equal(LIMITE_DESCRICAO, 5000);
});

test('gate de fonte: gravar sem produtoId pula sem requisição, e o hook da ficha grava depois da ficha técnica', () => {
    const desc = lerSemComentarios(`${D}useDescricaoProduto.js`);
    const gravar = desc.slice(desc.indexOf('const gravar'));
    assert.ok(gravar.indexOf('! produtoId') < gravar.indexOf('axios.put'), 'o pulo vem antes da requisição');
    assert.match(gravar, /pulou: true/);

    const hook = lerSemComentarios(`${D}useFichaProduto.js`);
    assert.equal((hook.match(/descricao\.gravar/g) ?? []).length, 1);
    assert.ok(hook.indexOf('tecnica.gravar') < hook.indexOf('descricao.gravar'), 'descrição depois da ficha técnica');
    assert.match(hook, /descricao,\n?\s*};|tecnica, descricao,/);
});

test('gate de fonte: bloco com rótulo exato, ligado na página, sem termo que revele a origem', () => {
    const bloco = lerSemComentarios(`${D}FichaDescricao.jsx`);
    assert.match(bloco, /Descrição do produto/);
    assert.match(bloco, /<textarea/);

    const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutoFicha.jsx');
    assert.ok(pagina.indexOf('<FichaTecnica') < pagina.indexOf('<FichaDescricao'));
    assert.match(pagina, /descricao: descricao|\bdescricao\b/);

    for (const f of ['FichaDescricao.jsx', 'useDescricaoProduto.js']) {
        assert.doesNotMatch(lerSemComentarios(`${D}${f}`), PROIBIDO, `${f} cita a origem do campo`);
    }
});
