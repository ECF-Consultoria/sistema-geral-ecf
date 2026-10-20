import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import { conferenciaChegou } from '../../resources/js/Components/Publicador/derivados.js';

// "Conferir no Mercado Livre" (19/10/2026): o editor espera o job da conferência lendo o estado a cada
// 2,5 s. Na 1ª conferência do produto a espera acabava na 1ª leitura, antes do resultado, e a tela
// ficava em "Ainda não conferido" até recarregar. Medido em produção no produto 73 da #459.

const BASE = 'resources/js/Components/Publicador';

test('1ª conferência do produto: segue esperando enquanto o servidor não tem nenhuma', () => {
    const aguardando = { tipo: 'conferencia', conferencia: null };
    assert.equal(conferenciaChegou(aguardando, { conferencia: null }), false, 'chave nula');
    assert.equal(conferenciaChegou(aguardando, {}), false, 'chave ausente (undefined) não é conferência nova');
    assert.equal(conferenciaChegou(aguardando, { conferencia: undefined }), false);
    assert.equal(conferenciaChegou(aguardando, null), false, 'leitura sem corpo');
});

test('1ª conferência do produto: para quando a conferência chega', () => {
    assert.equal(conferenciaChegou({ tipo: 'conferencia', conferencia: null }, { conferencia: { id: 23, resultado: 'AVISOS' } }), true);
});

test('com conferência anterior: espera a de id diferente', () => {
    const aguardando = { tipo: 'conferencia', conferencia: 23 };
    assert.equal(conferenciaChegou(aguardando, { conferencia: { id: 23, vale: false } }), false, 'ainda é a antiga');
    assert.equal(conferenciaChegou(aguardando, { conferencia: { id: 24, vale: true } }), true);
});

test('o acompanhamento do hook usa a regra, não a comparação direta', () => {
    const hook = lerSemComentarios(`${BASE}/usePublicador.js`);
    assert.match(hook, /aguardando\.tipo === 'conferencia' && conferenciaChegou\(aguardando, data\)/);
    assert.doesNotMatch(hook, /data\.conferencia\?\.id !== aguardando\.conferencia/);
});
