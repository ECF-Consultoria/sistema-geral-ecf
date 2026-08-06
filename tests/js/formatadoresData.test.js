import test from 'node:test';
import assert from 'node:assert/strict';
import { formatDate, formatDateTime, formatTime } from '../../resources/js/lib/utils.js';

// ═══════════════════════════════════════════════════════════════════════
// Quick task fast-260806 — formatadores de data/hora de `lib/utils.js`.
//
// Bug que motivou o arquivo: `formatDateTime` passava só `{hour, minute}` para
// o `toLocaleString`. Quando o Intl recebe apenas componentes de hora, ele
// emite SÓ a hora e descarta a data em silêncio — a função virou um clone do
// `formatTime`, e a coluna "Cadastrado em" da listagem do Comercial (mais os
// 3 campos de token da página da empresa e 2 telas de NPS) exibia só "17:28".
//
// Os testes abaixo travam o contrato dos três formatadores. Todos usam
// timezone America/Sao_Paulo, que é fixo no módulo.
// ═══════════════════════════════════════════════════════════════════════

// 05/08/2026 17:28 em São Paulo (UTC-3) = 20:28Z.
const INSTANTE = '2026-08-05T20:28:00.000Z';

// ─── formatDateTime — precisa trazer data E hora ───

test('formatDateTime traz data E hora (a regressao que motivou o teste)', () => {
    const saida = formatDateTime(INSTANTE);
    assert.match(saida, /05\/08\/2026/, `esperava a data em ${saida}`);
    assert.match(saida, /17:28/, `esperava a hora em ${saida}`);
});

test('formatDateTime NAO pode ser igual a formatTime', () => {
    // Se alguem "simplificar" as options removendo day/month/year, o Intl
    // descarta a data e as duas funcoes passam a devolver a mesma string.
    assert.notEqual(formatDateTime(INSTANTE), formatTime(INSTANTE));
});

test('formatDateTime aplica o fuso de Sao Paulo, nao UTC', () => {
    // 20:28Z vira 17:28 em Sao Paulo. Sem timeZone sairia 20:28.
    assert.match(formatDateTime(INSTANTE), /17:28/);
});

test('formatDateTime nao vira 04/08 na virada do dia em Sao Paulo', () => {
    // 06/08 00:30Z ainda e 05/08 21:30 em Sao Paulo — prova que a data segue
    // o fuso e nao o UTC.
    assert.match(formatDateTime('2026-08-06T00:30:00.000Z'), /05\/08\/2026/);
});

// ─── formatDate e formatTime — continuam recortando de proposito ───

test('formatDate traz so a data', () => {
    const saida = formatDate(INSTANTE);
    assert.match(saida, /05\/08\/2026/);
    assert.doesNotMatch(saida, /17:28/, `formatDate nao deveria trazer hora: ${saida}`);
});

test('formatTime traz so a hora', () => {
    const saida = formatTime(INSTANTE);
    assert.match(saida, /17:28/);
    assert.doesNotMatch(saida, /2026/, `formatTime nao deveria trazer data: ${saida}`);
});

// ─── ausencia de valor ───

test('os tres formatadores devolvem travessao curto quando nao ha data', () => {
    for (const fn of [formatDate, formatDateTime, formatTime]) {
        assert.equal(fn(null), '-');
        assert.equal(fn(undefined), '-');
        assert.equal(fn(''), '-');
    }
});
