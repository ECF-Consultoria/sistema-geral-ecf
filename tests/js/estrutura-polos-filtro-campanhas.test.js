import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// TKT-0015 — filtro "Campanhas" em /polos/empresas (Polos/Empresas.jsx).
//
// O chip "Ads desligado" junta campanha parada com conta sem campanha nenhuma.
// A fileira "Campanhas" separa as duas a partir de `ads_campanha`, que o
// servidor manda pronta (PoloAdsStatus::situacaoDaEmpresa). O que estas travas
// protegem:
//  1. as quatro situações e o "Não verificado" (null do servidor) como chips;
//  2. o filtro lendo a chave do servidor, não recalculando a regra na tela;
//  3. a fileira só no mês corrente (mês fechado não traz a chave);
//  4. o chip "Ads desligado" continua existindo (links do donut usam status=ads).
// ═══════════════════════════════════════════════════════════════════════

const fonte = lerSemComentarios('resources/js/Pages/Polos/Empresas.jsx');

test('polos/empresas — chips de campanha com as quatro situações', () => {
    for (const chave of ['ativa', 'desligada', 'sem_campanha', 'nao_verificado']) {
        assert.match(fonte, new RegExp(`key: '${chave}'`));
    }
    assert.match(fonte, /label: 'Sem campanha'/);
    assert.match(fonte, /label: 'Não verificado'/);
});

test('polos/empresas — o filtro usa a situação do servidor, null vira não verificado', () => {
    assert.match(fonte, /e\.ads_campanha \?\? 'nao_verificado'/);
    assert.match(fonte, /campanhaDa\(e\) !== campanha/);
    assert.doesNotMatch(fonte, /ads_auto\.total === 0 \?\s*'sem_campanha'/);
});

test('polos/empresas — fileira de campanhas só no mês corrente', () => {
    assert.match(fonte, /\{parcial && \(\s*<div[^>]*>\s*<span[^>]*>\s*Campanhas/);
    assert.match(fonte, /parcial && campanha !== 'todas'/);
});

test('polos/empresas — o chip "Ads desligado" continua no status', () => {
    assert.match(fonte, /key: 'ads',\s*label: 'Ads desligado'/);
});
