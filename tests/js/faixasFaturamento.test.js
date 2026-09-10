import test from 'node:test';
import assert from 'node:assert/strict';
import {
    tetoRedondo,
    tetoGravado,
    indiceDeGravacao,
    faturamentoDaLinha,
    formatarDinheiroRedondo,
    rotuloFaturamento,
    valorExibidoNoCampo,
    aplicarValorDigitado,
} from '../../resources/js/lib/faixasFaturamento.js';

// ═══════════════════════════════════════════════════════════════════════
// Quick 260910-faixa-mostra-valor-redondo — conversão teto gravado (,99)
// <-> valor redondo mostrado (contrato). Um centavo errado aqui move
// empresa de faixa — por isso a trava cobre a primeira linha, uma
// intermediária e a última (sem teto), no caminho digitação -> valor
// gravado.
// ═══════════════════════════════════════════════════════════════════════

const FAIXAS_TRES_LINHAS = [
    { ordem: 1, limite_superior: 499_999.99, valor: 3_000, valor_e_piso: false },
    { ordem: 2, limite_superior: 999_999.99, valor: 4_500, valor_e_piso: false },
    { ordem: 3, limite_superior: null, valor: 6_000, valor_e_piso: true },
];

// ─── tetoRedondo / tetoGravado — a ida e a volta ──────────────────────

test('tetoRedondo soma 0,01 ao teto gravado', () => {
    assert.equal(tetoRedondo(499_999.99), 500_000);
    assert.equal(tetoRedondo(999_999.99), 1_000_000);
    assert.equal(tetoRedondo(49_999.99), 50_000);
});

test('tetoRedondo(null) devolve null (sem teto nao vira zero)', () => {
    assert.equal(tetoRedondo(null), null);
});

test('tetoGravado subtrai 0,01 do valor redondo digitado', () => {
    assert.equal(tetoGravado(500_000), 499_999.99);
    assert.equal(tetoGravado(1_000_000), 999_999.99);
});

test('tetoGravado(null) devolve null', () => {
    assert.equal(tetoGravado(null), null);
});

test('ida e volta nao perde nem ganha centavo', () => {
    for (const teto of [499_999.99, 999_999.99, 1_999_999.99, 49_999.99]) {
        assert.equal(tetoGravado(tetoRedondo(teto)), teto);
    }
});

// ─── indiceDeGravacao — a primeira linha aponta pra ela mesma ─────────

test('indiceDeGravacao: primeira linha aponta pra ela mesma (a unica ao contrario)', () => {
    assert.equal(indiceDeGravacao(0), 0);
});

test('indiceDeGravacao: as demais linhas apontam pra linha anterior', () => {
    assert.equal(indiceDeGravacao(1), 0);
    assert.equal(indiceDeGravacao(2), 1);
    assert.equal(indiceDeGravacao(5), 4);
});

// ─── faturamentoDaLinha / rotuloFaturamento — a leitura ───────────────

// toLocaleString('pt-BR', {style:'currency'}) separa "R$" do número com um
// ESPAÇO SEM QUEBRA (U+00A0), não um espaço comum — já é assim em todo fmtBRL
// existente do projeto (TabelaProgressivaFaixas.jsx etc.), então os literais
// de comparação abaixo usam   de propósito, não um typo.
const NBSP = ' ';

test('primeira linha mostra "ate" o proprio teto redondo', () => {
    const { tipo, valor } = faturamentoDaLinha(FAIXAS_TRES_LINHAS, 0);
    assert.equal(tipo, 'ate');
    assert.equal(valor, 500_000);
    assert.equal(rotuloFaturamento(FAIXAS_TRES_LINHAS, 0), `até R$${NBSP}500.000`);
});

test('linha intermediaria mostra "a partir de" o teto da linha anterior', () => {
    const { tipo, valor } = faturamentoDaLinha(FAIXAS_TRES_LINHAS, 1);
    assert.equal(tipo, 'a_partir_de');
    assert.equal(valor, 500_000);
    assert.equal(rotuloFaturamento(FAIXAS_TRES_LINHAS, 1), `a partir de R$${NBSP}500.000`);
});

test('ultima linha (sem teto) tambem mostra "a partir de" o teto da linha anterior', () => {
    const { tipo, valor } = faturamentoDaLinha(FAIXAS_TRES_LINHAS, 2);
    assert.equal(tipo, 'a_partir_de');
    assert.equal(valor, 1_000_000);
    assert.equal(rotuloFaturamento(FAIXAS_TRES_LINHAS, 2), `a partir de R$${NBSP}1.000.000`);
});

test('tabela de uma faixa so, sem teto: primeira linha cai no fallback "acima"', () => {
    const faixaUnica = [{ ordem: 1, limite_superior: null, valor: 1_000, valor_e_piso: true }];
    assert.equal(rotuloFaturamento(faixaUnica, 0), 'acima');
});

// ─── formatarDinheiroRedondo ────────────────────────────────────────────

test('formatarDinheiroRedondo nunca mostra centavos', () => {
    assert.equal(formatarDinheiroRedondo(500_000), `R$${NBSP}500.000`);
    assert.equal(formatarDinheiroRedondo(null), null);
});

// ─── valorExibidoNoCampo / aplicarValorDigitado — o caminho digitacao -> gravado ─

test('digitacao na primeira linha grava valor - 0,01 nela mesma', () => {
    const linhas = [
        { ordem: 1, limite_superior: null, valor: 3_000, valor_e_piso: false },
        { ordem: 2, limite_superior: null, valor: 4_500, valor_e_piso: true },
    ];
    assert.equal(valorExibidoNoCampo(linhas, 0), null);

    const depois = aplicarValorDigitado(linhas, 0, 500_000);
    assert.equal(depois[0].limite_superior, 499_999.99);
    assert.equal(depois[1].limite_superior, null, 'a segunda linha nao pode ser tocada pela edicao da primeira.');
    // Imutavel: o array original nao muda.
    assert.equal(linhas[0].limite_superior, null);
});

test('digitacao numa linha intermediaria grava piso - 0,01 como teto da linha anterior', () => {
    const linhas = [
        { ordem: 1, limite_superior: null, valor: 3_000, valor_e_piso: false },
        { ordem: 2, limite_superior: 999_999.99, valor: 4_500, valor_e_piso: false },
        { ordem: 3, limite_superior: null, valor: 6_000, valor_e_piso: true },
    ];
    assert.equal(valorExibidoNoCampo(linhas, 1), null);

    const depois = aplicarValorDigitado(linhas, 1, 500_000);
    assert.equal(depois[0].limite_superior, 499_999.99, 'grava na linha ANTERIOR (indice 0), nao na propria linha 1.');
    assert.equal(depois[1].limite_superior, 999_999.99, 'a propria linha 1 fica intocada — o teto dela e gravado pela linha 2.');
    assert.equal(depois[2].limite_superior, null);
});

test('digitacao na ultima linha (sem teto) grava piso - 0,01 como teto da linha anterior', () => {
    const linhas = [
        { ordem: 1, limite_superior: 499_999.99, valor: 3_000, valor_e_piso: false },
        { ordem: 2, limite_superior: null, valor: 4_500, valor_e_piso: false },
        { ordem: 3, limite_superior: null, valor: 6_000, valor_e_piso: true },
    ];
    assert.equal(valorExibidoNoCampo(linhas, 2), null, 'a linha 1 (indice de gravacao da ultima) ainda nao tem teto — transitorio apos "Adicionar faixa".');

    const depois = aplicarValorDigitado(linhas, 2, 1_000_000);
    assert.equal(depois[0].limite_superior, 499_999.99, 'a primeira linha fica intocada.');
    assert.equal(depois[1].limite_superior, 999_999.99, 'grava na linha anterior (indice 1) — a ultima linha (indice 2) continua sem teto, aberta.');
    assert.equal(depois[2].limite_superior, null, 'a ultima linha nunca recebe teto proprio — continua "a partir de", aberta.');
});

test('linha 1 (a segunda) espelha a linha 0 — as duas mostram/gravam o mesmo teto', () => {
    // Consequencia direta de indiceDeGravacao: linha 0 aponta pra ela mesma
    // (o "ate" da primeira faixa) e a linha 1 aponta pra ela TAMBEM (o "a
    // partir de" da segunda faixa e, por definicao, o mesmo numero). As duas
    // ficam sincronizadas sempre — nao e bug, e a mesma fronteira vista de
    // dois lados (o "ate" de quem termina ali e o "a partir de" de quem
    // comeca ali), documentado aqui pra ninguem "consertar" achando duplicata.
    const linhas = [
        { ordem: 1, limite_superior: 499_999.99, valor: 3_000, valor_e_piso: false },
        { ordem: 2, limite_superior: null, valor: 4_500, valor_e_piso: true },
    ];
    assert.equal(valorExibidoNoCampo(linhas, 0), 500_000);
    assert.equal(valorExibidoNoCampo(linhas, 1), 500_000);

    const depois = aplicarValorDigitado(linhas, 1, 600_000);
    assert.equal(depois[0].limite_superior, 599_999.99, 'editar pela linha 1 tambem atualiza o que a linha 0 mostra.');
    assert.equal(valorExibidoNoCampo(depois, 0), 600_000);
});

test('caminho completo digitacao -> valor gravado bate com FAIXAS_TRES_LINHAS', () => {
    // Simula a pessoa digitando 500.000 (linha 0, "ate") e 1.000.000 (linha 2,
    // "a partir de" — quem grava o teto da SEGUNDA faixa e a TERCEIRA linha,
    // nao a segunda: o teto da linha 1 e a fronteira que a linha 2 descreve)
    // e conferindo que o array final bate byte a byte com a tabela gravada
    // em producao.
    let linhas = [
        { ordem: 1, limite_superior: null, valor: 3_000, valor_e_piso: false },
        { ordem: 2, limite_superior: null, valor: 4_500, valor_e_piso: false },
        { ordem: 3, limite_superior: null, valor: 6_000, valor_e_piso: true },
    ];
    linhas = aplicarValorDigitado(linhas, 0, 500_000);
    linhas = aplicarValorDigitado(linhas, 2, 1_000_000);

    assert.equal(linhas[0].limite_superior, FAIXAS_TRES_LINHAS[0].limite_superior);
    assert.equal(linhas[1].limite_superior, FAIXAS_TRES_LINHAS[1].limite_superior);
    assert.equal(linhas[2].limite_superior, FAIXAS_TRES_LINHAS[2].limite_superior);
});
