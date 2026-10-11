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
    assert.match(f, /\? `Frete do Mercado Livre levado para a Precificação \(\$\{n\}/);
    assert.match(f, /confira de novo\.`/);
    assert.match(f, /pub\.recarregar\(\);/);
    assert.match(f, /disabled=\{levandoFrete\}/);
    assert.match(f, /import \{ useState \} from 'react'/);
    // O clique não pode virar o `itemId` (o evento do React entraria como argumento).
    assert.match(f, /onClick=\{\(\) => levarFrete\(\)\} disabled=\{levandoFrete\} className=\{LINK\} data-acao="levar-frete"/);
});

// ─── A terceira conferência: o frete do anúncio JÁ publicado ───

test('na lista de anúncios publicados, cada um diz o frete: conferido, "a combinar" ou a diferença com o botão', () => {
    const f = lerSemComentarios('resources/js/Components/Publicador/Mesa/Publicar.jsx');
    assert.match(f, /i\.frete\?\.aplicavel === false && <span[^>]*data-frete-do-anuncio="nao-se-aplica">· sem frete do Mercado Livre \(a combinar\)<\/span>/);
    assert.match(f, /i\.frete\?\.aplicavel && i\.frete\.nivel === 'igual' && <span[^>]*>· frete conferido com a Precificação<\/span>/);
    assert.match(f, /\{i\.frete\?\.mensagem && \(/);
    assert.match(f, /i\.frete\.pode_levar && \(\s*<button type="button" onClick=\{\(\) => levarFrete\(i\.id\)\}/);
    // Do anúncio publicado vai pela rota do ITEM, e o aviso manda atualizar o preço no Mercado Livre.
    assert.match(f, /axios\.post\(route\('mlb\.anuncios\.publicador\.itens\.frete-precificacao', \{ produto: produtoId, item: itemId \}\)\)/);
    assert.match(f, /atualize o preço deste anúncio no Mercado Livre\./);
});

test('no cartão da tarefa pós-publicação, o anúncio com frete divergente mostra o aviso e o mesmo botão', () => {
    const f = lerSemComentarios('resources/js/Pages/Mlb/Publicador/Tarefas.jsx');
    assert.match(f, /function FreteDoAnuncio\(\{ frete, produtoId, podeEscrever, onErro \}\)/);
    assert.match(f, /if \(f\.aplicavel !== true \|\| typeof f\.mensagem !== 'string' \|\| f\.mensagem === ''\) return null;/, 'igual ou "a combinar": não ocupa o cartão');
    assert.match(f, /<FreteDoAnuncio frete=\{comoObjeto\(t\.fretes\)\[textoSeguro\(i\?\.ml_item_id, ''\)\]\} produtoId=\{produto\.id\}/);
    assert.match(f, /axios\.post\(route\('mlb\.anuncios\.publicador\.itens\.frete-precificacao', \{ produto: produtoId, item: f\.item_id \}\)\)/);
    assert.match(f, /router\.reload\(\{ only: \['tarefas'\] \}\)/);
    assert.match(f, /f\.pode_levar === true && podeEscrever && produtoId \?/);
    assert.match(f, /import axios from 'axios'/);
});
