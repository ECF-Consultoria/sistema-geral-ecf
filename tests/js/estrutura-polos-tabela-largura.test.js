import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Largura da tabela de /polos/empresas (Polos/Empresas.jsx).
//
// A página tem no máximo 1500px. Medido em 27/10/2026 com as linhas mais
// largas reais (bônus de ADS, "Desligado · sem campanha", "sem dado" +
// lançar manual): a tabela pedia 1552px, e a coluna "Sinais" saía da tela.
// O que tirou a sobra — e o que estas travas impedem de voltar:
//  1. o teto do ADS (igual para todas as linhas) aparece uma vez, no
//     cabeçalho, e não repetido em cada célula;
//  2. o selo de bônus fica abaixo do gasto, não ao lado (teste do bônus);
//  3. células com px-3, não px-4 — em tela larga a tabela redistribui a
//     sobra e nada muda; em notebook (~1280px) é o que a faz caber.
// ═══════════════════════════════════════════════════════════════════════

const fonte = lerSemComentarios('resources/js/Pages/Polos/Empresas.jsx');

const inicioTabela = fonte.indexOf('<table');
const fimTabela    = fonte.indexOf('</table>', inicioTabela);
const tabela       = fonte.slice(inicioTabela, fimTabela);

test('polos/empresas — teto do ADS no cabeçalho, não em cada linha', () => {
    assert.match(tabela, /<Th campo="ads">\s*ADS<span[^>]*> · teto \{formatCurrency\(adsLimites\.teto\)\}<\/span>\s*<\/Th>/);
    assert.doesNotMatch(tabela, /\/ \{formatCurrency\(adsLimites\.teto\)\}/);
});

test('polos/empresas — linha da tabela sem px-4 nas células', () => {
    assert.ok(inicioTabela > 0 && fimTabela > inicioTabela, 'tabela não encontrada');
    // O <td> de "Nenhuma empresa neste filtro" é linha única e não pesa na largura.
    const celulas = tabela.match(/<t[dh] className="px-4 py-[0-9.]+[^"]*"/g) ?? [];
    assert.deepEqual(celulas.filter((c) => !c.includes('py-8')), []);
    assert.match(fonte, /<th className=\{cn\('px-3 py-2\.5 /);
});
