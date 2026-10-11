import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import { etapaDoProblema } from '../../resources/js/Components/Publicador/apoio.js';
import { problemasParaCorrigir, seletoresDoProblema } from '../../resources/js/Components/Publicador/destaque.js';

// ═══════════════════════════════════════════════════════════════════════════
// Conferência de frete no editor do Publicador (11/10/2026).
//
// O "Conferir" compara o frete que o Mercado Livre cota agora com o que a
// Precificação do Portal usou (V-FRT-01, aviso). Na tela: o aviso cai em
// Condições de venda, o "Corrigir" acende o preço daquele tipo, e um botão
// leva o frete do Mercado Livre para a Precificação.
// ═══════════════════════════════════════════════════════════════════════════

const aviso = (mais = {}) => ({
    regra: 'V-FRT-01', severidade: 'WARNING', camada: 'L3',
    mensagem: 'Frete do Clássico: o Mercado Livre cobra hoje R$ 26,85 e a Precificação usou R$ 20,00 (digitado no Portal). Diferença de R$ 6,85.',
    alvo: { etapa: 'E10', campo: 'preco', alvo: 'gold_special', variante: '__single__', frete: { portal: 20, ml: 26.85, nivel: 'reprecificar' } },
    ...mais,
});

test('o aviso de frete cai em Condições de venda e entra no que o "Corrigir" acende', () => {
    assert.equal(etapaDoProblema(aviso()), 'condicoes');
    assert.deepEqual(problemasParaCorrigir([aviso()], 'condicoes'), [aviso()]);
});

test('"Corrigir" acende o preço daquele tipo e daquela variação (os números que vão no alvo não atrapalham)', () => {
    assert.deepEqual(seletoresDoProblema(aviso()).slice(0, 2), ['[id="preco-gold_special-__single__"]', '[id^="preco-gold_special-"]']);
    const premium = aviso({ alvo: { etapa: 'E10', campo: 'preco', alvo: 'gold_pro', variante: 'COLOR=id:1', frete: { portal: 20, ml: 30, nivel: 'reprecificar' } } });
    assert.equal(seletoresDoProblema(premium)[0], '[id="preco-gold_pro-COLOR=id:1"]');
});

test('o botão "levar para a Precificação" aparece só com o aviso de frete e manda pela rota própria, sem número', () => {
    const f = lerSemComentarios('resources/js/Components/Publicador/Mesa/Publicar.jsx');
    assert.match(f, /e\.itens\.some\(\(p\) => p\.regra === 'V-FRT-01'\) && \(/);
    assert.match(f, /data-acao="levar-frete"/);
    assert.match(f, /Levar o frete do Mercado Livre para a Precificação/);
    // O valor NUNCA sai da tela: o pedido vai sem corpo e o servidor usa o que a conferência gravou.
    assert.match(f, /axios\.post\(route\('mlb\.anuncios\.publicador\.frete-precificacao', \{ produto: produtoId \}\)\)/);
    // Depois de levar: avisa que o preço mudou e relê o rascunho (a conferência deixou de valer).
    assert.match(f, /pub\.setAviso\(`Frete do Mercado Livre levado para a Precificação/);
    assert.match(f, /confira de novo\.`\);\s*pub\.recarregar\(\);/);
    assert.match(f, /disabled=\{levandoFrete\}/);
    assert.match(f, /import \{ useState \} from 'react'/);
});
