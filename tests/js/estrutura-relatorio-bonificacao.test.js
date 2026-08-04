import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gates estruturais da linha expansível do Relatório de Bonificação
// (123-05-PLAN.md Task 2 — UIEM-04/D-08/D-09). Leem a fonte SEM
// COMENTÁRIOS: a prosa em pt-BR deste projeto cita os próprios
// identificadores, e um grep cru passaria pelo motivo errado.
// ═══════════════════════════════════════════════════════════════════════

const fonte = lerSemComentarios('resources/js/Pages/Desempenho/RelatorioBonificacao.jsx');

test('a fonte reusa EmpresasScoreTabela e o estado de expansão via useState', () => {
    assert.match(fonte, /EmpresasScoreTabela/);
    assert.match(fonte, /useState/);
});

test('a linha expansível lê tem_detalhe_empresas e empresas_score_resumo', () => {
    assert.match(fonte, /tem_detalhe_empresas/);
    assert.match(fonte, /empresas_score_resumo/);
});

test('o aviso de ausência (D-03) usa avisoSemDetalheFechado do módulo compartilhado', () => {
    assert.match(fonte, /avisoSemDetalheFechado/);
});

test('anti-hardcode: o texto do aviso de ausência não é escrito à mão no JSX', () => {
    assert.doesNotMatch(fonte, /Ainda não há detalhe/,
        'esse texto só pode existir em resources/js/lib/desempenhoLabels.js');
});

test('o botão de exportar PDF continua apontando pra rota de PDF (D-09, sem alteração)', () => {
    assert.match(fonte, /route\('desempenho\.relatorio-bonificacao\.pdf'/);
});
