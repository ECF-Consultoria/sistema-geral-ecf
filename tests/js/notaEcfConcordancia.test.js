import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { analisarAnuncio } from '../../resources/js/lib/mlAnuncioRegras.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Metade JS da guarda de concordância PHP×JS (D-10/D-22).
//
// Lê o MESMO fixture que tests/Unit/Phase134/NotaEcfFecharComContaTest.php
// consome do lado PHP (tests/fixtures/phase134/nota-ecf-casos.json). O
// invariante que os dois lados travam:
//
//   score_wizard_100 − 14×(sinal de descrição) === nota_ecf_86
//
// Se um dia calcularScore() (mlAnuncioRegras.js:263) mudar um peso sem o
// AnuncioSaudeService mudar junto, é aqui que quebra — sem depender de
// rodar PHPUnit para descobrir. Precedente da pegadinha que motivou este
// teste: .planning/learnings/desempenho-bonificacao.md (nps_medio ≠
// pontos_componentes.nps, card que não fechava com a própria conta).
//
// NÃO editar resources/js/lib/mlAnuncioRegras.js — este arquivo só lê.
// ═══════════════════════════════════════════════════════════════════════

const raiz = resolve(import.meta.dirname, '..', '..');
const fixturePath = resolve(raiz, 'tests/fixtures/phase134/nota-ecf-casos.json');
const { casos } = JSON.parse(readFileSync(fixturePath, 'utf8'));

// Espelha o filtro de "ficha técnica" de AnunciarML.jsx:1420 — exclui
// atributos de variação (Cor/Tamanho vão na etapa de Variações) e grade de
// moda (SIZE_GRID_ID / *GRID*).
function obrigatoriosDe(atributosCategoria) {
    return atributosCategoria.filter(a =>
        a.tags?.required
        && !a.tags?.allow_variations
        && a.id !== 'SIZE_GRID_ID'
        && !String(a.id).includes('GRID'),
    );
}

// Espelha o filtro de "características secundárias" de AnunciarML.jsx:1450.
function opcionaisDe(atributosCategoria) {
    return atributosCategoria.filter(a =>
        !a.tags?.required
        && !a.tags?.allow_variations
        && !a.tags?.hidden
        && !a.tags?.read_only
        && a.id !== 'SIZE_GRID_ID'
        && !String(a.id).includes('GRID')
        && a.id !== 'CATALOG_PRODUCT_ID'
        && a.id !== 'GTIN'
        && a.id !== 'SELLER_SKU',
    );
}

for (const caso of casos) {
    test(`concordância PHP×JS — ${caso.nome}`, () => {
        const obrigatorios = obrigatoriosDe(caso.atributos_categoria);
        const opcionais = opcionaisDe(caso.atributos_categoria);
        // Predicado de "atributo preenchido" = está na lista derivada de item_ml.attributes (AnunciarML.jsx:1994).
        const preenchido = (a) => caso.wizard.atributosPreenchidos.includes(a.id);

        const analise = analisarAnuncio({
            ...caso.wizard,
            obrigatorios,
            opcionais,
            preenchido,
        });

        // 1. Score do wizard trava contra deriva.
        assert.equal(
            analise.score,
            caso.esperado.score_wizard_100,
            `score do wizard divergiu no caso "${caso.nome}" (veio ${analise.score}, esperado ${caso.esperado.score_wizard_100})`,
        );

        // 2. Invariante de concordância, do lado JS.
        const descricaoOk = caso.esperado.descricao_ok ? 1 : 0;
        assert.equal(
            analise.score - 14 * descricaoOk,
            caso.esperado.nota_ecf_86,
            `invariante score_wizard - 14*descricao !== nota_ecf_86 no caso "${caso.nome}"`,
        );

        // 3. Os dois blocos do fixture descrevem o MESMO item — senão o
        //    teste de concordância passaria por acaso.
        assert.equal(caso.wizard.titulo, caso.item_ml.title, `wizard.titulo diverge de item_ml.title no caso "${caso.nome}"`);
        assert.equal(caso.wizard.categoryId, caso.item_ml.category_id, `wizard.categoryId diverge de item_ml.category_id no caso "${caso.nome}"`);

        // 4. Faixa válida — nunca acima da base 86.
        assert.ok(
            caso.esperado.nota_ecf_86 >= 0 && caso.esperado.nota_ecf_86 <= 86,
            `nota fora da faixa [0,86] no caso "${caso.nome}"`,
        );
    });
}

// Gate que detecta alteração unilateral da régua do wizard — a fonte de
// verdade que esta fase se comprometeu a não recalibrar (D-22). Se alguém
// mudar um dos 8 pesos de calcularScore() sem que isso seja uma decisão
// explícita registrada em CONTEXT.md, este teste acende.
test('pesos_do_wizard_nao_mudaram', () => {
    const fonte = lerSemComentarios('resources/js/lib/mlAnuncioRegras.js');

    const linhasAdd = fonte.split('\n').filter((linha) => linha.trim().startsWith('add('));
    const pesosEncontrados = linhasAdd
        .map((linha) => linha.match(/,\s*(\d+)\)\s*;/))
        .filter(Boolean)
        .map((m) => Number(m[1]))
        .sort((a, b) => a - b);

    assert.deepEqual(
        pesosEncontrados,
        [4, 8, 12, 12, 14, 14, 16, 20],
        'os 8 pesos de calcularScore() mudaram — a régua do wizard não pode ser recalibrada nesta fase (D-22)',
    );
});
