import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Linha expandida de /polos/empresas (Polos/Empresas.jsx): semanal à esquerda,
// comentários à direita.
//
// A <td colSpan> da linha expandida tem a largura da TABELA, que rola na
// horizontal e passa da área visível. Com a coluna do semanal em `flex-1`
// sem teto, ela engolia toda a sobra e empurrava os comentários para a ponta
// direita da tabela — fora da vista, com um buraco vazio no meio (27/10/2026).
// As travas: as duas colunas têm largura máxima e nenhuma delas cresce solta.
// ═══════════════════════════════════════════════════════════════════════

const fonte = lerSemComentarios('resources/js/Pages/Polos/Empresas.jsx');

// Trecho entre o título do semanal e o painel de comentários da linha expandida.
const inicio = fonte.indexOf('Faturamento e ADS por semana');
const fim    = fonte.indexOf('<Comentarios', inicio);
const antesDoSemanal = fonte.slice(Math.max(0, inicio - 400), inicio);
const ateComentarios = fonte.slice(inicio, fim);

test('polos/empresas — linha expandida existe com semanal e comentários', () => {
    assert.ok(inicio > 0, 'título do semanal não encontrado');
    assert.ok(fim > inicio, 'painel de comentários não encontrado depois do semanal');
});

test('polos/empresas — coluna do semanal tem teto e não cresce solta', () => {
    assert.match(antesDoSemanal, /<div className="min-w-0 w-full max-w-3xl">/);
    assert.doesNotMatch(antesDoSemanal, /className="min-w-0 flex-1"/);
});

test('polos/empresas — comentários com 340px lado a lado e teto quando empilha', () => {
    assert.match(ateComentarios, /<div className="w-full max-w-3xl shrink-0 xl:w-\[340px\]">\s*$/);
});
