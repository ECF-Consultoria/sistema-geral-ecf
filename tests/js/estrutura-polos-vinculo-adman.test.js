import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// TKT-0014 — coluna "Vínculo Adman" no Painel Polos.
//
// Coluna nova no painel são três lugares que andam juntos (learnings
// painel-polos-status-e-meta §3b): a def do AutoFiltro (COLUNAS), a ordem
// por lente (COLS_POR_LENTE) e a célula em LinhaPainel. Faltando a célula, o
// cabeçalho mostra a coluna e o corpo sai com <td> vazio, sem erro nenhum.
// ═══════════════════════════════════════════════════════════════════════

const painel = lerSemComentarios('resources/js/Pages/Polos/Painel.jsx');

function listaDaLente(lente) {
    const bloco = painel.match(/const COLS_POR_LENTE = \{([\s\S]*?)\n\};/);
    assert.ok(bloco, 'COLS_POR_LENTE não encontrado');
    const m = bloco[1].match(new RegExp(`\\b${lente}:\\s*\\[([\\s\\S]*?)\\]`));
    assert.ok(m, `lente ${lente} não encontrada`);
    return [...m[1].matchAll(/'([^']+)'/g)].map((x) => x[1]);
}

test('a coluna é filtrável no AutoFiltro, com rótulo próprio para o vazio', () => {
    assert.match(painel, /adman_vinculo:\s*\{\s*key: 'adman_vinculo', label: 'Vínculo Adman', accessor: \(e\) => e\.adman_vinculo/);
    assert.match(painel, /'\(Não verificado\)'/);
});

test('a coluna entra na Geral e em Acessos & Setup, logo depois do Gmail colaborador', () => {
    for (const lente of ['geral', 'acessos']) {
        const cols = listaDaLente(lente);
        const i = cols.indexOf('adman_vinculo');
        assert.ok(i > 0, `adman_vinculo fora da lente ${lente}`);
        assert.equal(cols[i - 1], 'gmail_colaborador', `ordem errada na lente ${lente}`);
    }
});

test('a linha tem a célula da coluna, e ela é só leitura', () => {
    assert.match(painel, /adman_vinculo: <td className=\{td\}><CelVinculoAdman e=\{e\} \/><\/td>/);
    const cel = painel.match(/function CelVinculoAdman\(\{ e \}\) \{([\s\S]*?)\n\}/);
    assert.ok(cel, 'CelVinculoAdman não encontrado');
    assert.doesNotMatch(cel[1], /onSave|salvarCampo|<select|<input/);
});
