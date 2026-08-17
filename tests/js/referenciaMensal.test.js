/**
 * Regra ÚNICA de referência mensal (spec 2026-08-14, item 1).
 *
 * Trava o contrato: exibe o mês de ACOMPANHAMENTO (competência + 1) e anota a
 * competência como "Ref.". A entrada é SEMPRE a competência — o mês do dado.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    mesAcompanhamento,
    mesExibicao,
    competenciaEmCurso,
    rotuloAcompanhamento,
    rotuloReferencia,
    rotuloMesReferencia,
    rotuloMesReferenciaCurto,
} from '../../resources/js/lib/referenciaMensal.js';

// "Hoje" fixo: 14 de agosto de 2026 — a competência corrente é agosto.
const HOJE = new Date(2026, 7, 14);

test('competência de julho é acompanhada em agosto', () => {
    const d = mesAcompanhamento('2026-07');
    assert.equal(d.getFullYear(), 2026);
    assert.equal(d.getMonth(), 7); // 0-based: 7 = agosto
});

test('o exemplo da spec sai literal', () => {
    assert.equal(rotuloAcompanhamento('2026-07', HOJE), 'agosto de 2026');
    assert.equal(rotuloReferencia('2026-07'), 'julho de 2026');
    assert.equal(rotuloMesReferencia('2026-07', { hoje: HOJE }), 'agosto de 2026 · Ref. julho de 2026');
});

// Regressão relatada em 2026-08-14: estando em agosto, o seletor oferecia
// "setembro de 2026 · Ref. agosto de 2026" — um mês que ainda não começou. A
// competência EM CURSO é acompanhada por ela mesma, e não ganha "Ref.".
test('competência em curso não desloca e não ganha Ref.', () => {
    assert.equal(competenciaEmCurso('2026-08', HOJE), true);
    assert.equal(rotuloMesReferencia('2026-08', { hoje: HOJE }), 'agosto de 2026');
    assert.equal(rotuloAcompanhamento('2026-08', HOJE), 'agosto de 2026');
    assert.equal(mesExibicao('2026-08', HOJE).getMonth(), 7); // agosto, não setembro
});

test('competência fechada segue deslocando', () => {
    assert.equal(competenciaEmCurso('2026-07', HOJE), false);
    assert.equal(mesExibicao('2026-07', HOJE).getMonth(), 7); // acompanhada em agosto
});

test('aceita a competência com dia (YYYY-MM-DD), como vem do backend', () => {
    assert.equal(rotuloMesReferencia('2026-07-01', { hoje: HOJE }), 'agosto de 2026 · Ref. julho de 2026');
});

test('dezembro vira janeiro do ano seguinte', () => {
    assert.equal(rotuloMesReferencia('2026-12', { hoje: HOJE }), 'janeiro de 2027 · Ref. dezembro de 2026');
});

test('mês de 31 dias não transborda (soma sempre a partir do dia 1º)', () => {
    // 31/01 + 1 mês, se somado sobre o dia 31, cairia em março. Aqui é fevereiro.
    const d = mesAcompanhamento('2026-01-31');
    assert.equal(d.getMonth(), 1); // fevereiro
});

test('versão curta serve para chip e opção de select', () => {
    assert.equal(rotuloMesReferenciaCurto('2026-07'), 'ago. de 26 · Ref. jul. de 26');
});

test('entrada vazia ou inválida não quebra a tela', () => {
    assert.equal(rotuloMesReferencia(null), '—');
    assert.equal(rotuloMesReferencia(''), '—');
    assert.equal(rotuloMesReferencia('lixo'), '—');
    assert.equal(mesAcompanhamento(undefined), null);
});
