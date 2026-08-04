import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gates estruturais da seção "Empresas da carteira" em Performance/Show.jsx
// (123-04-PLAN.md Task 3 — UIEM-02/D-01/D-03/D-04). Leem a fonte SEM
// COMENTÁRIOS: a prosa em pt-BR deste projeto cita os próprios
// identificadores, e um grep cru passaria pelo motivo errado.
// ═══════════════════════════════════════════════════════════════════════

const fonteShow = lerSemComentarios('resources/js/Pages/Performance/Show.jsx');
const fonteTabela = lerSemComentarios('resources/js/Components/Desempenho/EmpresasScoreTabela.jsx');

test('UIEM-01: a fonte de Show.jsx não contém percentageMargin (sobrevive a refactor)', () => {
    assert.doesNotMatch(fonteShow, /percentageMargin/);
});

test('a fonte usa o componente novo, o guard de detalhe e o resumo pré-calculado', () => {
    assert.match(fonteShow, /EmpresasScoreTabela/);
    assert.match(fonteShow, /tem_detalhe_empresas/);
    assert.match(fonteShow, /empresas_score_resumo/);
});

test('o aviso de ausência usa os textos do módulo compartilhado, não texto novo', () => {
    assert.match(fonteShow, /AVISO_SEM_DETALHE_EM_CURSO/);
    assert.match(fonteShow, /avisoSemDetalheFechado/);
});

test('anti-hardcode: o texto do aviso de ausência não é escrito à mão no JSX', () => {
    assert.doesNotMatch(fonteShow, /Ainda não há detalhe/,
        'esse texto só pode existir em resources/js/lib/desempenhoLabels.js');
});

test('D-04 preservada: o valor do card de margem continua vindo de var_margem_pct', () => {
    assert.match(fonteShow, /formatPercent\(c\.var_margem_pct\)/);
});

test('EmpresasScoreTabela.jsx expõe o denominador explícito (D-06) e a ressalva do topo (D-04)', () => {
    assert.match(fonteTabela, /tituloEntraram/);
    assert.match(fonteTabela, /tituloNaoEntraram/);
    assert.match(fonteTabela, /NOTA_TOPO_RESSALVA/);
});
