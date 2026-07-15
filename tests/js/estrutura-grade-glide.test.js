import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gates estruturais da grade em canvas. Leem a fonte SEM COMENTÁRIOS: a prosa
// em pt-BR deste arquivo cita os próprios identificadores, e um grep cru
// contaria o comentário e passaria pelo motivo errado.
// ═══════════════════════════════════════════════════════════════════════

const fonte = lerSemComentarios('resources/js/Pages/Mlb/GradeAnuncioGlide.jsx');

// ─── FIX-83-2: estados terminais na grade ───

test('a grade lê o status do servidor', () => {
    assert.match(fonte, /linha\.statusServidor === 'publicado'/);
    assert.match(fonte, /linha\.statusServidor === 'erro'/);
});

test('publicado tem glifo verde próprio (green-500), distinto do "válido no ML"', () => {
    assert.match(fonte, /#22c55e/);
    assert.match(fonte, /#34d399/, 'o verde do "valido no ML" nao pode ter sumido (SHEET2-07)');
});

test('erro real de publicação usa ✕, distinto do ! do erro local', () => {
    assert.match(fonte, /glifo = '✕'/, 'falha real do POST /items');
    assert.match(fonte, /glifo = '!'/, 'erro local continua com !');
});

test('realce de linha cobre publicado e erro do servidor', () => {
    assert.match(fonte, /rgba\(34,197,94/, 'verde de publicado');
    assert.match(fonte, /l\.statusServidor === 'erro'/);
});

test('zero regressão: os ramos antigos do realce continuam lá', () => {
    assert.match(fonte, /rgba\(239,68,68,0\.06\)/, 'vermelho do erro local');
    assert.match(fonte, /rgba\(251,191,36/, 'ambar do aviso do ML');
});

// ─── FIX-83-6a: Delete ───

test('origemCellRenderer implementa onDelete (as 8 colunas que não respondiam)', () => {
    assert.match(fonte, /onDelete:\s*\(cell\)\s*=>/,
        'custom cell nao herda o onDelete nativo — sem isto Delete nao limpa Titulo/SKU/Preco/etc');
});

test('anti-código-morto: sem wrapper de DropdownCell (a lib já cobre via deletedValue)', () => {
    assert.doesNotMatch(fonte, /dropdownComDelete/);
    assert.match(fonte, /RENDERERS\s*=\s*\[DropdownCell,\s*origemCellRenderer\]/,
        'RENDERERS nao muda nesta fase');
});

test('Delete no Tipo volta ao padrão em vez de ser engolido', () => {
    assert.match(fonte, /escolhido\.trim\(\) === ''/,
        "sem este ramo, normalizarTipoAnuncio('') devolve null e o Delete no Tipo nao faz nada");
    assert.match(fonte, /'tier',\s*'gold_special'/);
});

// ─── FIX-83-5: preço ───

test('preço normaliza no ponto único de escrita (não no montarPayloadLinha)', () => {
    assert.match(fonte, /normalizarPreco/);
    assert.match(fonte, /coluna\.id === 'price'\s*\?\s*normalizarPreco\(valor\)/,
        'a coercao mora no onCellsEdited: o autosave chama montarPayloadLinha a cada 600ms');
});

// ─── Zero regressão das props da Fase 82 ───

test('as capacidades de planilha da Fase 82 continuam ligadas', () => {
    for (const prop of ['rangeSelect', 'fillHandle', 'allowedFillDirections', 'rowMarkers',
        'getCellsForSelection', 'onPaste', 'coercePasteValue', 'customRenderers',
        'getRowThemeOverride', 'trailingRowOptions']) {
        assert.match(fonte, new RegExp('\\b' + prop + '\\b'), `prop ${prop} sumiu`);
    }
});

test('SHEET2-04 segue nativo: nenhum handler de teclado custom', () => {
    assert.doesNotMatch(fonte, /onKeyDown/);
});
