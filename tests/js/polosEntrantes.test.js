import test from 'node:test';
import assert from 'node:assert/strict';
import {
    STATUS_ENTRADA_RESERVA,
    DIA_CORTE_ENTRANTES,
    competenciaDeISO,
    janelaDaCompetencia,
    rotuloJanelaCompetencia,
    diasCorridosNaCompetencia,
    ehReservaProximoMes,
    somaMetaDoMes,
    competenciaDe,
    FASES_TERMINAIS,
    ehFaseTerminal,
    semTerminais,
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
});

// ─── 2b. Corte do dia 27 — a competência de entrantes NÃO é o mês do calendário ───
//
// Regra do time (2026-08-28): "a meta de entrantes fecha dia 27; o que for feito hoje
// já conta para o mês que vem". Agosto = 28/07..27/08. É a única conta do painel com
// essa régua — M1 e faturamento continuam no mês cheio.

test('o dia 27 ainda fecha o proprio mes; o 28 ja e do mes seguinte', () => {
    assert.equal(DIA_CORTE_ENTRANTES, 27);
    assert.equal(competenciaDe(new Date(2026, 7, 27)), '2026-08'); // último dia de agosto
    assert.equal(competenciaDe(new Date(2026, 7, 28)), '2026-09'); // já é setembro
    assert.equal(competenciaDe(new Date(2026, 7, 31)), '2026-09');
    assert.equal(competenciaDe(new Date(2026, 8, 1)), '2026-09');
});

test('o corte vira o ano em dezembro', () => {
    assert.equal(competenciaDe(new Date(2026, 11, 27)), '2026-12');
    assert.equal(competenciaDe(new Date(2026, 11, 28)), '2027-01');
    assert.equal(competenciaDeISO('2026-12-31'), '2027-01');
});

test('competenciaDeISO le a string do backend sem passar por Date (fuso nao move o dia)', () => {
    // `new Date('2026-08-28')` é meia-noite UTC => 27/08 21h em Brasília. Se a competência
    // saísse daí, toda empresa do dia 28 cairia no mês errado exatamente na virada.
    assert.equal(competenciaDeISO('2026-08-27'), '2026-08');
    assert.equal(competenciaDeISO('2026-08-28'), '2026-09');
    assert.equal(competenciaDeISO('2026-08-28T00:00:00-03:00'), '2026-09');
});

test('empresa sem data_solicitacao nao entra em competencia nenhuma', () => {
    assert.equal(competenciaDeISO(null), '');
    assert.equal(competenciaDeISO(undefined), '');
    assert.equal(competenciaDeISO(''), '');
    assert.equal(competenciaDeISO('sem data'), '');
});

test('a janela da competencia vai de 28 do mes anterior a 27 do proprio', () => {
    const j = janelaDaCompetencia('2026-09');
    assert.equal(j.inicio.getDate(), 28);
    assert.equal(j.inicio.getMonth(), 7);  // agosto
    assert.equal(j.fim.getDate(), 27);
    assert.equal(j.fim.getMonth(), 8);     // setembro
    assert.equal(j.dias, 31);
    assert.equal(rotuloJanelaCompetencia('2026-09'), '28/08 a 27/09');
    // Janeiro puxa dezembro do ano anterior — o Date rola o índice -1 sozinho.
    assert.equal(rotuloJanelaCompetencia('2026-01'), '28/12 a 27/01');
    assert.equal(janelaDaCompetencia('2026-01').inicio.getFullYear(), 2025);
    assert.equal(rotuloJanelaCompetencia('lixo'), '');
});

test('dias corridos medem o ritmo DENTRO da janela, nao do mes do calendario', () => {
    // 28/08 é o dia 1 da competência de setembro — projetar por "dia 28 de 31" inflaria a meta.
    assert.equal(diasCorridosNaCompetencia('2026-09', new Date(2026, 7, 28)), 1);
    assert.equal(diasCorridosNaCompetencia('2026-09', new Date(2026, 8, 27)), 31);
    assert.equal(diasCorridosNaCompetencia('2026-08', new Date(2026, 7, 28)), null); // já fechou
    assert.equal(diasCorridosNaCompetencia('2026-10', new Date(2026, 7, 28)), null); // ainda não abriu
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

// ═══════════════════════════════════════════════════════════════════════
// Fases terminais — meta NÃO conta churn (corrigido em 27/08/2026).
// A "Visão geral" contava empresa em Churn como entrante do mês: 7 dos 101
// entrantes de agosto estavam churnados. A régua vive na lib para não voltar
// a divergir entre as abas.
// ═══════════════════════════════════════════════════════════════════════

test('FASES_TERMINAIS cobre as três fases de saída, com as strings exatas da planilha', () => {
    assert.deepEqual(FASES_TERMINAIS, ['Encerrado', 'Protocolo Churn', 'Churn']);
});

test('ehFaseTerminal reconhece churn, protocolo churn e encerrado', () => {
    assert.equal(ehFaseTerminal({ fase: 'Churn' }), true);
    assert.equal(ehFaseTerminal({ fase: 'Protocolo Churn' }), true);
    assert.equal(ehFaseTerminal({ fase: 'Encerrado' }), true);
});

test('ehFaseTerminal não derruba fase operacional nem entrada vazia', () => {
    ['M0', 'M1', 'M2', 'M3', 'M4', 'Aceite no Projeto', 'Encaminhar Comercial'].forEach((fase) => {
        assert.equal(ehFaseTerminal({ fase }), false, `fase ${fase} não é terminal`);
    });
    assert.equal(ehFaseTerminal({}), false);
    assert.equal(ehFaseTerminal(null), false);
    assert.equal(ehFaseTerminal({ fase: null }), false);
});

test('ehFaseTerminal é sensível a caixa — a planilha grava a string exata', () => {
    assert.equal(ehFaseTerminal({ fase: 'churn' }), false);
    assert.equal(ehFaseTerminal({ fase: 'CHURN' }), false);
});

test('semTerminais remove churn/encerrado e preserva a ordem do resto', () => {
    const lista = [
        { id: 1, fase: 'M0' },
        { id: 2, fase: 'Churn' },
        { id: 3, fase: 'M1' },
        { id: 4, fase: 'Encerrado' },
        { id: 5, fase: 'Protocolo Churn' },
        { id: 6, fase: 'Aceite no Projeto' },
    ];
    assert.deepEqual(semTerminais(lista).map((e) => e.id), [1, 3, 6]);
});

test('semTerminais tolera entrada inválida (nunca quebra a aba de metas)', () => {
    assert.deepEqual(semTerminais(null), []);
    assert.deepEqual(semTerminais(undefined), []);
    assert.deepEqual(semTerminais([]), []);
});

test('MetasPanel filtra as fases terminais na ENTRADA, antes de qualquer indicador', () => {
    const fonte = lerSemComentarios('resources/js/Pages/Polos/components/MetasPanel.jsx');
    assert.match(fonte, /semTerminais/, 'MetasPanel precisa usar semTerminais');
    assert.match(
        fonte,
        /const empresas = useMemo\(\(\) => semTerminais\(empresasProp\)/,
        'o filtro tem que ser na entrada: derivar de `empresas` já limpo é o que mantém todos os indicadores consistentes',
    );
});

// ─── 5. Corte do dia 27 — fiação nas três telas que dividem o mesmo denominador ───
//
// `competenciaDe` é compartilhada por MetasPanel, EntrantesM0Panel e ModoTV. Mudar a
// semântica dela sem acertar o RÓTULO de cada tela produz o pior tipo de erro: a parede
// anuncia "Agosto" enquanto cobra a meta de setembro, e ninguém percebe olhando.
const metas  = lerSemComentarios('resources/js/Pages/Polos/components/MetasPanel.jsx');
const modoTv = lerSemComentarios('resources/js/Pages/Polos/components/ModoTV.jsx');

test('MetasPanel deriva a competencia pela lib, nao por slice da data', () => {
    assert.match(metas, /import\s*\{[^}]*\bcompetenciaDeISO\b[^}]*\}\s*from\s*'@\/lib\/polosEntrantes'/s);
    assert.match(metas, /const\s+mesDe\s*=\s*\(e\)\s*=>\s*competenciaDeISO\(e\.data_solicitacao\)/);
    assert.doesNotMatch(metas, /data_solicitacao\s*\|\|\s*''\)\.slice\(0,\s*7\)/);
});

test('MetasPanel abre no mes de competencia e projeta dentro da janela', () => {
    assert.match(metas, /const\s+mesAtual\s*=\s*useMemo\(\(\)\s*=>\s*competenciaDe\(new Date\(\)\)/);
    assert.match(metas, /diasCorridosNaCompetencia\(mesAtual\)/);
});

test('nenhuma tela tira o nome do mes de getMonth() enquanto cobra a meta da competencia', () => {
    const entrantes = lerSemComentarios('resources/js/Pages/Polos/components/EntrantesM0Panel.jsx');
    // O rótulo tem de sair de `mesAtual` (competência), não do mês do calendário.
    assert.doesNotMatch(entrantes, /mesNome\s*=\s*MESES_BR\[hoje\.getMonth\(\)\]/);
    assert.doesNotMatch(modoTv, /const\s+mesNome\s*=\s*MESES_BR\[agora\.getMonth\(\)\]/);
    assert.match(modoTv, /mesNome\s*=\s*MESES_BR\[\(Number\(numComp\)/);
});

test('ModoTV mede o ritmo ate o fim da competencia, nao do mes corrido', () => {
    assert.match(modoTv, /function diasUteisRestantes\(hoje, ym\)/);
    assert.match(modoTv, /janelaDaCompetencia\(ym\)/);
    assert.match(modoTv, /diasUteisRestantes\(agora, mesAtual\)/);
});

test('o fallback do faturamento no ModoTV NAO herda a competencia de entrantes', () => {
    // Faturamento segue o mês do calendário; se o fallback virar `mesCorrente`, uma tela
    // sem cockpit rotularia faturamento de agosto como "Setembro/2026".
    assert.match(modoTv, /mesRefFat\s*=\s*cockpit\?\.mesRefLabel\s*\?\?\s*`\$\{mesNomeCalendario\}/);
});
