import test from 'node:test';
import assert from 'node:assert/strict';
import {
    STATUS_ENTRADA_RESERVA,
    ehReservaProximoMes,
    somaMetaDoMes,
    competenciaDe,
} from '../../resources/js/lib/polosEntrantes.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Funil de entrada dos Polos — "Entrantes (M0)".
//
// Duas contas que o time confere na mão contra a planilha:
//   · progresso do mês = entrantes M0 / meta cadastrada do mês;
//   · reserva p/ o próximo mês = coluna "Status entrada".
//
// A reserva é o ponto frágil: `status_entrada` chega da planilha como TEXTO
// LIVRE (SyncPolosPlanilha não valida contra o enum), então uma comparação
// por igualdade estrita zeraria o card em silêncio na primeira célula
// digitada com caixa diferente.
// ═══════════════════════════════════════════════════════════════════════

// ─── 1. Reserva — entrada no próximo mês ───

test('reserva reconhece o valor canonico da planilha', () => {
    assert.equal(ehReservaProximoMes({ status_entrada: STATUS_ENTRADA_RESERVA }), true);
    assert.equal(STATUS_ENTRADA_RESERVA, 'Reserva - entrada prox mês');
});

test('reserva absorve caixa e espacamento que o texto livre deixa passar', () => {
    for (const v of [
        'reserva - entrada prox mês',
        'RESERVA - ENTRADA PROX MÊS',
        '  Reserva - entrada prox mes  ',
        'Reserva – entrada próx. mês',   // travessão + acento digitados na mão
        'Reserva',
    ]) {
        assert.equal(ehReservaProximoMes({ status_entrada: v }), true, `deveria contar: ${v}`);
    }
});

test('nenhum outro status do funil vira reserva', () => {
    for (const v of [
        'Feito', 'em contato', 'Não tem CNPJ', 'Não tem conta ML',
        'Não responde', 'Abandonou o projeto',
    ]) {
        assert.equal(ehReservaProximoMes({ status_entrada: v }), false, `nao deveria contar: ${v}`);
    }
});

test('empresa sem ficha (status_entrada null/ausente) nao quebra nem conta', () => {
    assert.equal(ehReservaProximoMes({ status_entrada: null }), false);
    assert.equal(ehReservaProximoMes({}), false);
    assert.equal(ehReservaProximoMes(undefined), false);
});

// ─── 2. Meta do mês (denominador do "32/90") ───

const METAS = [
    { polo: 'Arapongas',        mes: '2026-07', meta: 13 },
    { polo: 'S. J. Rio Preto',  mes: '2026-07', meta: 11 },
    { polo: 'Serra Gaúcha',     mes: '2026-08', meta: 13 },
    { polo: 'São Bento do Sul', mes: '2026-08', meta: 8 },
    { polo: 'Arapongas',        mes: '2026-08', meta: 13 },
];

test('meta do mes soma todos os polos daquele mes', () => {
    assert.equal(somaMetaDoMes(METAS, '2026-08'), 34);
    assert.equal(somaMetaDoMes(METAS, '2026-07'), 24);
});

test('mes sem meta cadastrada devolve 0 — o card volta ao numero absoluto', () => {
    assert.equal(somaMetaDoMes(METAS, '2026-09'), 0);
    assert.equal(somaMetaDoMes([], '2026-08'), 0);
    assert.equal(somaMetaDoMes(undefined, '2026-08'), 0);
});

test('meta nula ou nao numerica nao contamina a soma com NaN', () => {
    const sujas = [
        { polo: 'A', mes: '2026-08', meta: null },
        { polo: 'B', mes: '2026-08', meta: undefined },
        { polo: 'C', mes: '2026-08', meta: 10 },
        null,
    ];
    assert.equal(somaMetaDoMes(sujas, '2026-08'), 10);
});

test('competenciaDe usa o mesmo formato YYYY-MM da tabela de metas', () => {
    assert.equal(competenciaDe(new Date(2026, 7, 26)), '2026-08'); // agosto = índice 7
    assert.equal(competenciaDe(new Date(2026, 0, 1)), '2026-01');
    assert.equal(competenciaDe(new Date(2026, 11, 31)), '2026-12');
});

// ─── 3. Fiação no JSX (sem ESLint, identificador solto compila e a tela quebra) ───

const fonte = lerSemComentarios('resources/js/Pages/Polos/components/EntrantesM0Panel.jsx');

test('o painel importa as regras da lib em vez de reimplementar', () => {
    assert.match(fonte, /import\s*\{[^}]*\behReservaProximoMes\b[^}]*\}\s*from\s*'@\/lib\/polosEntrantes'/s);
    assert.match(fonte, /import\s*\{[^}]*\bsomaMetaDoMes\b[^}]*\}\s*from\s*'@\/lib\/polosEntrantes'/s);
    assert.match(fonte, /import\s*\{[^}]*\bcompetenciaDe\b[^}]*\}\s*from\s*'@\/lib\/polosEntrantes'/s);
});

test('o painel recebe metasEntrada — sem a prop o denominador seria sempre 0', () => {
    assert.match(fonte, /function EntrantesM0Panel\(\{[^}]*\bmetasEntrada\b/s);
    const painel = lerSemComentarios('resources/js/Pages/Polos/Painel.jsx');
    assert.match(painel, /<EntrantesM0Panel[^>]*metasEntrada=\{metas\}/s);
});

test('os icones novos (Target, CalendarClock) estao importados do lucide', () => {
    assert.match(fonte, /import\s*\{[^}]*\bTarget\b[^}]*\}\s*from\s*'lucide-react'/s);
    assert.match(fonte, /import\s*\{[^}]*\bCalendarClock\b[^}]*\}\s*from\s*'lucide-react'/s);
});
