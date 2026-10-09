import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gates estruturais da Fase 173-05 — AnunciosHistorico.jsx desabilita (nunca
// esconde) "Anunciar semelhante" e "Anunciar semelhante em massa" para itens
// da fonte nova (pub_publicacoes), que ainda não tem rotina de clonar um
// PubRascunho. Leem a fonte SEM COMENTÁRIOS de propósito (ver _fonte.js).
// ═══════════════════════════════════════════════════════════════════════

const fonte = lerSemComentarios('resources/js/Pages/Mlb/AnunciosHistorico.jsx');

// ─── CardAnuncio: fail-safe individual ───

test('CardAnuncio só habilita "Anunciar semelhante" quando pode_duplicar === true (nunca por omissão)', () => {
    assert.match(fonte, /const podeDuplicar = a\.pode_duplicar === true;/,
        'comparação tem que ser === true — !== false deixaria undefined passar como habilitado');
});

test('o botão individual fica disabled quando não pode duplicar (e não só quando está clonando)', () => {
    assert.match(fonte, /disabled=\{estaClonando \|\| !podeDuplicar\}/,
        'sem !podeDuplicar no disabled, item da fonte nova ficaria clicável');
});

test('o botão individual explica o motivo (title) quando desabilitado pela fonte', () => {
    assert.match(fonte, /desabilitadoPorFonte[\s\S]{0,40}Ainda não é possível duplicar anúncios publicados pelo editor novo\./,
        'desabilitar sem explicar quebra a regra "nunca esconder sem dizer por quê"');
});

test('o estado "clonando" (Duplicando…) continua distinto do estado desabilitado-por-fonte', () => {
    assert.match(fonte, /estaClonando\s*\n?\s*\?\s*'cursor-wait border-white\/\[0\.06\] text-white\/30'/,
        'o terceiro estado visual não pode se confundir com "clonando"');
    assert.match(fonte, /desabilitadoPorFonte\s*\n?\s*\?\s*'cursor-not-allowed border-white\/\[0\.06\] text-white\/25'/);
});

// ─── BlocoLote: fail-safe do lote ───

test('BlocoLote desabilita o "em massa" só quando NENHUM item do lote pode duplicar', () => {
    assert.match(fonte, /const loteSemDuplicar = grupo\.itens\.every\(\(i\) => i\.pode_duplicar !== true\);/,
        'every(...!== true) é o fail-safe certo: um único item sem pode_duplicar=true explícito não habilita sozinho, mas basta UM true para destravar o lote');
});

test('o botão de massa fica disabled quando o lote inteiro não pode duplicar', () => {
    assert.match(fonte, /disabled=\{esteClonando \|\| loteSemDuplicar\}/);
});

test('o botão de massa também explica o motivo quando desabilitado pela fonte', () => {
    assert.match(fonte, /loteSemDuplicar[\s\S]{0,60}Ainda não é possível duplicar anúncios publicados pelo editor novo\./);
});

// ─── Nada mais no resto da tela muda (busca/paginação/agrupamento intactos) ───

test('busca, paginação e agrupamento continuam na página (nenhuma regressão de leitura)', () => {
    assert.match(fonte, /route\('mlb\.anuncios\.historico'/, 'rota de busca/paginação sumiu');
    assert.match(fonte, /grupos\.links/, 'paginação por lote sumiu');
    assert.match(fonte, /g\.total > 1/, 'distinção lote×avulso sumiu');
});
