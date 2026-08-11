import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate do D-16 (Fase 134 Plano 09) — o wizard perdeu o bloco "Rascunhos
// recentes" (migrado para RascunhosPainel.jsx, D-14), mas a "Saúde do
// anúncio" e o deep-link do "Anunciar semelhante" (Fase 86) continuam
// intactos. Lê a fonte SEM COMENTÁRIOS pelo mesmo motivo do gate de
// estrutura-meus-anuncios.test.js: a prosa em pt-BR deste projeto cita os
// próprios identificadores, e um grep cru contaria o comentário.
// ═══════════════════════════════════════════════════════════════════════

const fonte = lerSemComentarios('resources/js/Pages/Mlb/AnunciarML.jsx');

// ─── 1/2. D-16 — "Saúde do anúncio" e o guard-rail que a alimenta continuam lá ───

test('D-16: o painel "Saúde do anúncio" continua no aside do wizard', () => {
    assert.match(fonte, /Saúde do anúncio/);
});

test('D-16: analisarAnuncio (a análise que alimenta o guard-rail de publicar()) continua ligada', () => {
    assert.match(fonte, /analisarAnuncio/);
});

// ─── 3/4. D-16 — o bloco de Rascunhos recentes saiu, sem código morto ───

test('D-16: o bloco "Rascunhos recentes" não existe mais no wizard', () => {
    assert.doesNotMatch(fonte, /Rascunhos recentes/);
});

test('D-16: nenhum resquício de código morto da seleção em lote (toggleTodos/publicarLote/rascunhosSelecionaveis/errosLote)', () => {
    assert.doesNotMatch(fonte, /\btoggleTodos\b/);
    assert.doesNotMatch(fonte, /\bpublicarLote\b/);
    assert.doesNotMatch(fonte, /\brascunhosSelecionaveis\b/);
    assert.doesNotMatch(fonte, /\berrosLote\b/);
});

// ─── 5. Deep-link do "Anunciar semelhante" (Fase 86) não regrediu ───

test('deep-link: abrirRascunhoId e abrirRascunho continuam presentes — "Anunciar semelhante" não regride', () => {
    assert.match(fonte, /\babrirRascunhoId\b/);
    assert.match(fonte, /\babrirRascunho\b/);
});

// ─── 6. STATUS_BADGE/STATUS_LABEL migraram para RascunhosPainel.jsx ───

test('STATUS_BADGE/STATUS_LABEL saíram do wizard e vivem em RascunhosPainel.jsx', () => {
    assert.doesNotMatch(fonte, /\bSTATUS_BADGE\b/);
    assert.doesNotMatch(fonte, /\bSTATUS_LABEL\b/);

    const fontePainel = lerSemComentarios('resources/js/Pages/Mlb/components/RascunhosPainel.jsx');
    assert.match(fontePainel, /\bSTATUS_BADGE\b/);
    assert.match(fontePainel, /\bSTATUS_LABEL\b/);
});
