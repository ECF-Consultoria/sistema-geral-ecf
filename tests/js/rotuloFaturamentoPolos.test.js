import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { TITULO_FATURAMENTO_TOTAL, apoioDaMeta } from '../../resources/js/Pages/Polos/components/rotuloFaturamento.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// TKT-0038 — o card "Faturamento total" do Modo TV já somava M1–M4, mas a única
// etiqueta dele era "M2–M4: X% da meta", e foi lido como se o total fosse M2–M4.
// O título diz M1–M4 e a linha de apoio diz que a META é que é M2–M4.
// ═══════════════════════════════════════════════════════════════════════

describe('rótulos do card de faturamento do Modo TV', () => {
    test('o título diz que o total é M1–M4', () => {
        assert.match(TITULO_FATURAMENTO_TOTAL, /^Faturamento total/);
        assert.match(TITULO_FATURAMENTO_TOTAL, /M1–M4/);
    });

    test('o apoio diz que a meta é sobre M2–M4', () => {
        assert.equal(apoioDaMeta(131, 'R$ 3,20M'), 'Meta (M2–M4): 131% de R$ 3,20M');
    });

    test('as telas Faturamento e Tarefas usam os rótulos e não têm mais o texto antigo', () => {
        const modoTv = lerSemComentarios('resources/js/Pages/Polos/components/ModoTV.jsx');
        const tarefas = lerSemComentarios('resources/js/Pages/Polos/components/TarefasTV.jsx');

        assert.doesNotMatch(modoTv, /M2–M4: \$\{pctFat\}% da meta/);
        assert.match(modoTv, /titulo=\{TITULO_FATURAMENTO_TOTAL\}/);
        assert.equal((modoTv.match(/apoioDaMeta\(pctFat/g) ?? []).length, 2);
        assert.match(tarefas, /\{TITULO_FATURAMENTO_TOTAL\}/);
        assert.doesNotMatch(tarefas, />Faturamento total</);
    });
});
