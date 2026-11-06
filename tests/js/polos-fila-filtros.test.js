import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { aplicarFiltros, campanhaDa, FILTROS_PADRAO, ORDENS } from '../../resources/js/lib/polosFila.js';

// ═══════════════════════════════════════════════════════════════════════
// Fila de revisão dos Polos (TKT-0033) — ordenar por consumo de publicidade e
// filtrar pela situação da campanha, no cliente, sobre a carteira carregada.
//
// "Consumo de publicidade" = `investimento` do cartão = gasto de ADS do mês, o
// mesmo número da coluna ADS do Faturamento Polos. Quem não tem o número do mês
// (ECF Drive sem o arquivo) vai para o fim nos dois sentidos.
// ═══════════════════════════════════════════════════════════════════════

const carteira = [
    { nome: 'Baixo',   investimento: 50,   campanha: 'ativa' },
    { nome: 'Sem dado', investimento: null, campanha: null },
    { nome: 'Alto',    investimento: 900,  campanha: 'desligada' },
    { nome: 'Médio',   investimento: 300,  campanha: 'sem_campanha' },
    { nome: 'Zero',    investimento: 0,    campanha: 'ativa' },
];

const nomes = (lista) => lista.map((e) => e.nome);
const com = (extra) => ({ ...FILTROS_PADRAO, ...extra });

describe('ordem por consumo de publicidade', () => {
    test('maior consumo primeiro; sem dado no fim', () => {
        assert.deepEqual(nomes(aplicarFiltros(carteira, com({ ordem: 'ads_mais' }))), ['Alto', 'Médio', 'Baixo', 'Zero', 'Sem dado']);
    });

    test('menor consumo primeiro; zero é dado, sem dado continua no fim', () => {
        assert.deepEqual(nomes(aplicarFiltros(carteira, com({ ordem: 'ads_menos' }))), ['Zero', 'Baixo', 'Médio', 'Alto', 'Sem dado']);
    });

    test('rótulos da opção', () => {
        assert.equal(ORDENS.ads_mais[0], 'Publicidade: maior consumo primeiro');
        assert.equal(ORDENS.ads_menos[0], 'Publicidade: menor consumo primeiro');
    });

    test('prioridade mantém a ordem que o servidor montou', () => {
        assert.deepEqual(nomes(aplicarFiltros(carteira, FILTROS_PADRAO)), nomes(carteira));
    });
});

describe('filtro de campanha', () => {
    test('null do servidor é "não verificado"', () => {
        assert.equal(campanhaDa({ campanha: null }), 'nao_verificado');
        assert.equal(campanhaDa({}), 'nao_verificado');
    });

    test('cada opção separa a sua situação', () => {
        const filtra = (campanha) => nomes(aplicarFiltros(carteira, com({ campanha })));
        assert.deepEqual(filtra('ativa'), ['Baixo', 'Zero']);
        assert.deepEqual(filtra('desligada'), ['Alto']);
        assert.deepEqual(filtra('sem_campanha'), ['Médio']);
        assert.deepEqual(filtra('nao_verificado'), ['Sem dado']);
        assert.equal(filtra('todas').length, carteira.length);
    });

    test('combina com a ordem por publicidade', () => {
        assert.deepEqual(nomes(aplicarFiltros(carteira, com({ campanha: 'ativa', ordem: 'ads_mais' }))), ['Baixo', 'Zero']);
    });

    test('busca continua ignorando acento', () => {
        assert.deepEqual(nomes(aplicarFiltros(carteira, com({ busca: 'medio' }))), ['Médio']);
    });
});
