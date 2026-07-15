import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gates estruturais da fiação de AnunciarMassa.jsx.
//
// Leem a fonte SEM COMENTÁRIOS de propósito: a prosa em pt-BR deste arquivo cita
// os próprios identificadores ("o merge chama mesclarStatusRascunhos..."), então
// um grep cru contaria o comentário e passaria pelo motivo errado.
// ═══════════════════════════════════════════════════════════════════════

const fonte = lerSemComentarios('resources/js/Pages/Mlb/AnunciarMassa.jsx');

// ─── FIX-83-2: o merge ───

test('a página chama o merge do estado do servidor', () => {
    assert.match(fonte, /mesclarStatusRascunhos\(prev,\s*rascunhos\)/,
        'sem setAbas(prev => mesclarStatusRascunhos(prev, rascunhos)) o polling nao muda nada na tela');
});

test('existe um efeito que depende da prop rascunhos', () => {
    assert.match(fonte, /\},\s*\[rascunhos\]\)/,
        'o merge precisa reagir a prop recarregada; sem deps [rascunhos] ele nunca roda de novo');
});

test('linhaDeRascunho lê o status do servidor', () => {
    assert.match(fonte, /l\.statusServidor\s*=\s*r\.status/,
        'sem isto, reabrir a pagina no meio de um lote mostra as linhas paradas e sem erro');
    assert.match(fonte, /l\.publicando\s*=\s*r\.status === 'publicando'/,
        'o merge precisa do publicando para inferir sucesso quando o rascunho some da prop');
});

// ─── FIX-83-1: o bug original ───

test('publicarLote destrava o botão SEMPRE (finally), não só no catch', () => {
    // publicarLote é a última das três (removerLinha < validarTudo < publicarLote),
    // então o bloco vai do início dele até o fim do useCallback.
    const ini = fonte.indexOf('const publicarLote');
    assert.ok(ini > -1, 'publicarLote sumiu do arquivo');
    const bloco = fonte.slice(ini, ini + 2500);
    assert.match(bloco, /finally\s*\{[\s\S]*?setPublicandoLote\(false\)/,
        'era o bug: setPublicandoLote(false) so no catch deixava o SUCESSO travado para sempre');
});

// ─── FIX-83-2: polling (D-02) e teto (D-03) ───

test('polling usa setInterval com recarga parcial da prop', () => {
    assert.match(fonte, /setInterval\(/);
    assert.match(fonte, /only:\s*\['rascunhos'\]/,
        'recarga parcial: nao recarregar a pagina inteira durante a edicao');
});

test('polling limpa o interval (sem vazamento)', () => {
    assert.match(fonte, /clearInterval\(/);
});

test('polling tem teto de segurança — nunca gira para sempre', () => {
    assert.match(fonte, /deadlineRef/,
        'sem teto, um job morto deixa o polling infinito — recriando o bug que a fase conserta');
    assert.match(fonte, /setPollingEstourado\(true\)/);
});

test('D-02: o padrão é polling — nada de websocket/SSE', () => {
    assert.doesNotMatch(fonte, /WebSocket|EventSource|Echo\./,
        'o projeto e queue=database sem Redis/websocket; o padrao aprovado e polling como no wizard');
});

// ─── Zero regressão: o init NÃO pode virar reativo ───

test('o useEffect de inicialização continua com deps vazias', () => {
    assert.match(fonte, /setAbaAtiva\(0\);[\s\S]{0,80}\},\s*\[\]\)/,
        're-agrupar as abas a cada poll faria o publicador perder a aba ativa e a ordem das linhas');
});
