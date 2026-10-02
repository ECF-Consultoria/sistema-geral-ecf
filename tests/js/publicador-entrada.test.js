import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import { haQuanto } from '../../resources/js/Components/Mlb/Publicador/tempo.js';

// ═══════════════════════════════════════════════════════════════════════
// Gates de fonte da entrada do Publicador (Fase 160, plano 10) e dos
// componentes compartilhados com as telas B e C (160-11 e 160-13 acrescentam
// os seus arquivos à lista abaixo). Lê a fonte SEM comentários.
// ═══════════════════════════════════════════════════════════════════════

const DIR = 'resources/js/Components/Mlb/Publicador/';

const ARQUIVOS = [
    DIR + 'tempo.js',
    DIR + 'SeloConta.jsx',
    DIR + 'SeloPortal.jsx',
    DIR + 'AvisoContaTravada.jsx',
    DIR + 'LinkReconexao.jsx',
    DIR + 'BotaoSincronizarPortal.jsx',
    DIR + 'SeletorPrograma.jsx',
    DIR + 'IndicadoresDoPrograma.jsx',
    DIR + 'PainelComoFunciona.jsx',
];

const TAMANHOS_OK = new Set(['24', '15', '13', '11']);

for (const caminho of ARQUIVOS) {
    const fonte = lerSemComentarios(caminho);

    test(`${caminho} — tipografia: só 24/15/13/11px`, () => {
        const usados = [...fonte.matchAll(/text-\[(\d+(?:\.\d+)?)px\]/g)].map((m) => m[1]);
        for (const t of usados) assert.ok(TAMANHOS_OK.has(t), `tamanho fora do vocabulário: ${t}px`);
        assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl|[2-9]xl)\b/);
    });

    test(`${caminho} — peso: só 400 e 700`, () => {
        assert.doesNotMatch(fonte, /font-(thin|extralight|light|medium|semibold|extrabold|black)\b/);
    });

    test(`${caminho} — sem amarelo sólido, sem select Radix, sem HTML injetado`, () => {
        assert.doesNotMatch(fonte, /\bbg-ecf-yellow(?!\/)/);
        assert.doesNotMatch(fonte, /@\/Components\/ui\/select/);
        assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/);
    });
}

test('AvisoContaTravada — estado calmo: sem vermelho, âmbar nem AlertTriangle (D21)', () => {
    const fonte = lerSemComentarios(DIR + 'AvisoContaTravada.jsx');
    assert.doesNotMatch(fonte, /red-|amber-|AlertTriangle/);
});

test('AvisoContaTravada — D26: validação e publicação esperam a liberação; variante linha existe', () => {
    const fonte = lerSemComentarios(DIR + 'AvisoContaTravada.jsx');
    assert.match(fonte, /A validação e a publicação no Mercado Livre são liberadas conta a conta/);
    assert.match(fonte, /'linha'/);
    assert.match(fonte, /nota-conta-travada/);
});

test('SeletorPrograma — radiogroup com Polos, Incubadora e Gestão nessa ordem', () => {
    const fonte = lerSemComentarios(DIR + 'SeletorPrograma.jsx');
    assert.match(fonte, /role="radiogroup"/);
    assert.match(fonte, /role="radio"/);
    assert.match(fonte, /aria-checked/);
    const ordem = [fonte.indexOf("'Polos'"), fonte.indexOf("'Incubadora'"), fonte.indexOf("'Gestão'")];
    assert.ok(ordem.every((i) => i >= 0) && ordem[0] < ordem[1] && ordem[1] < ordem[2]);
});

test('BotaoSincronizarPortal — posta na rota do contrato e usa a mensagem do servidor', () => {
    const fonte = lerSemComentarios(DIR + 'BotaoSincronizarPortal.jsx');
    assert.match(fonte, /mlb\.anuncios\.publicador\.sincronizar/);
    assert.match(fonte, /Sincronizando…/);
    assert.match(fonte, /Não foi possível buscar do Portal\. Nada foi alterado\. Tente de novo em instantes\./);
});

test('SeloConta — os três estados e seus rótulos', () => {
    const fonte = lerSemComentarios(DIR + 'SeloConta.jsx');
    for (const r of ['Conectada', 'Reconectar', 'Falta reconectar']) assert.ok(fonte.includes(r), r);
});

test('LinkReconexao — mantém o aviso de que o link é do navegador do CLIENTE', () => {
    const fonte = lerSemComentarios(DIR + 'LinkReconexao.jsx');
    assert.match(fonte, /navegador DELE/);
});

test('haQuanto — minutos, horas, dias, agora e inválido', () => {
    const agora = Date.parse('2026-10-02T12:00:00Z');
    assert.equal(haQuanto('2026-10-02T11:48:00Z', agora), 'há 12 min');
    assert.equal(haQuanto('2026-10-02T10:00:00Z', agora), 'há 2 h');
    assert.equal(haQuanto('2026-09-29T12:00:00Z', agora), 'há 3 d');
    assert.equal(haQuanto('2026-10-02T11:59:50Z', agora), 'agora');
    assert.equal(haQuanto(null, agora), null);
    assert.equal(haQuanto('lixo', agora), null);
});
