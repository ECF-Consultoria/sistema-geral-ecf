import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// TKT-0013 — selo "automático" na coluna Decola do Painel Polos.
//
// O Decola vira "Sim" sozinho quando o Mercado Livre confirma o programa
// ativo (polos:decola-status). O select continua editável; o selo é o único
// aviso de que um valor diferente volta a "Sim" na leitura seguinte. Se ele
// sumir da célula, a tela passa a "desfazer" a edição de alguém sem explicar.
// ═══════════════════════════════════════════════════════════════════════

const painel = lerSemComentarios('resources/js/Pages/Polos/Painel.jsx');

test('a célula do Decola mostra o selo só quando o servidor confirma o programa ativo', () => {
    const celula = painel.match(/\n\s*decola: <td[\s\S]*?<\/td>,/);
    assert.ok(celula, 'célula decola não encontrada em LinhaPainel');
    assert.match(celula[0], /\{e\.decola_ml_ativo && <SeloDecolaMl \/>\}/);
});

test('o Decola continua editável: o selo não troca o select', () => {
    const celula = painel.match(/\n\s*decola: <td[\s\S]*?<\/td>,/);
    assert.match(celula[0], /<EditSelect e=\{e\} campo="decola"/);
});

test('o selo explica de onde vem o valor', () => {
    const selo = painel.match(/function SeloDecolaMl\(\) \{([\s\S]*?)\n\}/);
    assert.ok(selo, 'SeloDecolaMl não encontrado');
    assert.match(selo[1], /Mercado Livre/);
    assert.match(selo[1], /title=/);
});
