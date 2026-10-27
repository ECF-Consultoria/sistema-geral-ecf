import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// TKT-0021 — bônus de ADS ao lado do consumo em /polos/empresas
// (Polos/Empresas.jsx). O servidor manda `ads_bonus` pronto (saldo somado,
// dias do bônus que vence primeiro, lista) e só quando há bônus vigente
// (PoloAdsBonificacao::paraTela). O que estas travas protegem:
//  1. o chip fica DENTRO da célula ADS, logo abaixo do gasto (lado a lado
//     a coluna passava de 400px e a tabela saía da tela — 27/10/2026);
//  2. a tela usa os dias do servidor — não recalcula data no navegador,
//     onde o fuso deslocaria o dia do fim;
//  3. cada bônus aparece no hover.
// ═══════════════════════════════════════════════════════════════════════

const fonte = lerSemComentarios('resources/js/Pages/Polos/Empresas.jsx');

test('polos/empresas — bônus na célula ADS, logo abaixo do gasto', () => {
    assert.match(fonte, /\{formatCurrency\(gastoAds\)\}\s*<\/span>\s*<\/div>\s*\{e\.ads_bonus && <BonusAds bonus=\{e\.ads_bonus\} \/>\}/);
});

test('polos/empresas — saldo e dias vêm do servidor', () => {
    assert.match(fonte, /formatCurrency\(bonus\.saldo\)/);
    assert.match(fonte, /diasTexto\(bonus\.dias\)/);
    assert.doesNotMatch(fonte, /new Date\(b(onus)?\.vence_em/);
});

test('polos/empresas — cada bônus no hover', () => {
    assert.match(fonte, /bonus\.itens\.map/);
    assert.match(fonte, /title=\{titulo\}/);
});
