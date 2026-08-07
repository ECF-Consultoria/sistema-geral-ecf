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

// ═══════════════════════════════════════════════════════════════════════
// 2026-08-06 — os cards por indicador passaram a exibir PONTOS como valor
// principal, com a % / p.p. rebaixada a linha de apoio.
//
// O gate anterior aqui exigia `formatPercent(c.var_margem_pct)` no card. Ele
// codificava a D-04 tal como ela foi escrita na Fase 123 ("o card do topo
// continua exibindo o número legado enquanto a flag estiver desligada"), e o
// raciocínio de então era explícito: *quem produz a `nota_final` exibida ao
// lado é o número relativo*.
//
// Isso deixou de ser verdade em 2026-08-05, quando a nota passou a ser a média
// dos PONTOS por indicador, com a régua aplicada loja a loja
// (`computeNotaFinalPorIndicador()` — ver learnings §0). Desde então o número
// relativo NÃO produz mais a nota, e mantê-lo como destaque é que colocava dois
// números que não se explicam lado a lado — exatamente o que a D-04 proíbe.
//
// O invariante real da D-04 sobrevive e é o que estes testes travam: o número
// em destaque tem de ser o que produz a nota, e NUNCA pode ser derivado das
// linhas por empresa (a flag `performance_company_first_score` segue `false`).
// ═══════════════════════════════════════════════════════════════════════

test('D-04: o valor em destaque dos cards é o PONTO, que é o que produz a nota', () => {
    assert.match(fonteShow, /formatNota\(p\.margem\)/);
    assert.match(fonteShow, /formatNota\(p\.faturamento\)/);
    assert.match(fonteShow, /pontos_componentes/);
});

test('D-04: nenhum card do topo é derivado das linhas por empresa', () => {
    // `empresas_score` só pode alimentar a TABELA, nunca os cards agregados —
    // a nota do topo continua vindo do cálculo por carteira.
    const trechoCards = fonteShow.slice(
        fonteShow.indexOf('function ParametroCard'),
        fonteShow.indexOf('function FaixaBonusCard'),
    );
    assert.doesNotMatch(trechoCards, /empresas_score/);
});

test('o card de NPS usa pontos_componentes.nps, NUNCA componentes.nps_medio como destaque', () => {
    // Armadilha real: `nps_medio` é a média das NOTAS de NPS; quem entra na
    // conta da nota final é a média dos PONTOS por loja. Exibir o primeiro
    // como valor principal fazia o card não fechar com a conta
    // "(nps+fat+margem)/n = nota" mostrada no card de bônus logo ao lado.
    assert.match(fonteShow, /valor=\{formatNota\(p\.nps\)\}/);
    assert.doesNotMatch(fonteShow, /valor=\{formatNota\(c\.nps_medio\)\}/);
});

test('o card Absenteísmo saiu da tela (não tinha fonte de dados)', () => {
    assert.doesNotMatch(fonteShow, /Absenteísmo/);
});

test('EmpresasScoreTabela.jsx expõe o denominador explícito (D-06) e a ressalva do topo (D-04)', () => {
    assert.match(fonteTabela, /tituloEntraram/);
    assert.match(fonteTabela, /tituloNaoEntraram/);
    assert.match(fonteTabela, /NOTA_TOPO_RESSALVA/);
});
