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
    // `\b` no fim: sem ele, `grupos.linksQualquerCoisa` passaria e a asserção
    // seria vazia — furo medido na prova por mutação do plano 177-08.
    assert.match(fonte, /grupos\.links\b/, 'paginação por lote sumiu');
    assert.match(fonte, /g\.total > 1/, 'distinção lote×avulso sumiu');
});

// ─── Fase 177, plano 08: o header novo no lugar de BarraDaConta + AbasDaConta ───

test('177-08: a página monta o PublicadorHeader na aba "publicacoes" e não monta mais os dois antigos', () => {
    assert.match(fonte, /<PublicadorHeader aba="publicacoes" cabecalho=\{cabecalho \?\? null\} \/>/,
        '`cabecalho` chega NULO nesta página (Company fora de programa) — a passagem tem de ser defensiva');
    assert.doesNotMatch(fonte, /<BarraDaConta\b/);
    assert.doesNotMatch(fonte, /<AbasDaConta\b/);
});

test('177-08: esta página continua SEM slot de ações (não tem ação de cabeçalho)', () => {
    // O header só desenha o <div> do slot quando `acoes` chega; passar um slot
    // vazio deixaria uma caixa órfã na linha 2.
    assert.doesNotMatch(fonte, /acoes=/,
        'esta tela não tem equivalente ao "Atualizar agora"; `acoes` fica no default (null)');
});

test('177-08: as sub-abas No ar | Histórico continuam montadas, com "historico" marcada (LIST-25)', () => {
    // Este é o único caminho de volta para "No ar" nesta tela. A remoção está
    // autorizada só na Fase 180, com a guarda de provar que o filtro de status
    // alcança o mesmo dado.
    assert.match(fonte, /<SubAbasDePublicacoes atual="historico" companyId=\{empresa\.id\} \/>/);
});
