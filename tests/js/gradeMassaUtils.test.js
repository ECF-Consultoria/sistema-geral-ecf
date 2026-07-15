import test from 'node:test';
import assert from 'node:assert/strict';
import {
    normalizarPreco,
    linhaVazia,
    linhaPublicavel,
} from '../../resources/js/Pages/Mlb/gradeMassaUtils.js';

// ═══════════════════════════════════════════════════════════════════════
// Funções puras da grade em massa. São a barreira que impede dado inválido
// de chegar ao Mercado Livre — por isso têm teste de comportamento, não gate
// estrutural.
// ═══════════════════════════════════════════════════════════════════════

// ─── FIX-83-5: preço no padrão BR ───

test('normalizarPreco: vírgula BR vira ponto (o caso do requisito)', () => {
    assert.equal(normalizarPreco('129,99'), '129.99');
    assert.equal(normalizarPreco('0,5'), '0.5');
});

test('normalizarPreco: quem já digita com ponto não regride', () => {
    assert.equal(normalizarPreco('129.99'), '129.99');
    assert.equal(normalizarPreco('1234.56'), '1234.56'); // sem vírgula, ponto é decimal
});

test('normalizarPreco: paste do Excel BR — ponto é milhar quando há vírgula', () => {
    assert.equal(normalizarPreco('1.234,56'), '1234.56');
    assert.equal(normalizarPreco('1.234.567,89'), '1234567.89');
});

test('normalizarPreco: trim', () => {
    assert.equal(normalizarPreco('  89,90  '), '89.90');
});

test('normalizarPreco: vazio/null/undefined viram string vazia', () => {
    assert.equal(normalizarPreco(''), '');
    assert.equal(normalizarPreco(null), '');
    assert.equal(normalizarPreco(undefined), '');
});

test('normalizarPreco: não numérico passa cru (quem reprova é errosLocaisLinha)', () => {
    assert.equal(normalizarPreco('abc'), 'abc');
});

test('normalizarPreco: INVARIANTE — todo caso numérico vira Number válido', () => {
    for (const entrada of ['129,99', '129.99', '1.234,56', '1234.56', '  89,90  ', '0,5']) {
        const n = Number(normalizarPreco(entrada));
        assert.ok(!Number.isNaN(n), `Number(normalizarPreco('${entrada}')) virou NaN`);
        assert.ok(n > 0, `Number(normalizarPreco('${entrada}')) não é > 0`);
    }
});

// ─── FIX-83-2: campos de estado do servidor ───

test('linhaVazia: ganha os 4 campos de estado do servidor', () => {
    const l = linhaVazia();
    assert.equal(l.publicando, false);   // sempre existe: o merge compara valor com valor
    assert.equal(l.statusServidor, null);
    assert.equal(l.erroResumo, null);
    assert.equal(l.erroCompleto, null);
});

test('linhaVazia: mantém TODOS os defaults de hoje (zero regressão)', () => {
    const l = linhaVazia();
    assert.equal(l.tier, 'gold_special');
    assert.deepEqual(l.attrs, {});
    assert.deepEqual(l.origem, {});
    assert.equal(l.salvo, false);
    assert.equal(l.salvando, false);
    assert.equal(l.id, null);
    assert.equal(l.title, '');
    assert.equal(l.price, '');
    assert.equal(l.estoque, '');
    assert.equal(typeof l.uid, 'string');
    assert.ok(l.uid.length > 0);
});

// ─── FIX-83-2 + D-01: quem entra no lote ───

const abaCom = { category_id: 'MLB1234', obrigatorios: [] };
const abaSem = { category_id: null, obrigatorios: [] };
const linhaOk = () => ({ ...linhaVazia(), id: 10, title: 'Camisa P', price: '49.90', estoque: '3' });

test('linhaPublicavel: linha salva e sem erro é publicável (comportamento de hoje)', () => {
    assert.equal(linhaPublicavel(linhaOk(), abaCom), true);
});

test('linhaPublicavel: linha em voo (publicando) sai do lote — não redisparar job', () => {
    assert.equal(linhaPublicavel({ ...linhaOk(), publicando: true }, abaCom), false);
});

test('linhaPublicavel: linha já publicada sai do lote', () => {
    assert.equal(linhaPublicavel({ ...linhaOk(), statusServidor: 'publicado' }, abaCom), false);
});

test('linhaPublicavel: linha com ERRO CONTINUA publicável — republicar é a recuperação', () => {
    assert.equal(linhaPublicavel({ ...linhaOk(), statusServidor: 'erro' }, abaCom), true);
});

test('linhaPublicavel: linha sem categoria NÃO é publicável (category_id null = 400 certo no ML)', () => {
    assert.equal(linhaPublicavel(linhaOk(), abaSem), false);
});

test('linhaPublicavel: sem id ou sem título continua fora (comportamento de hoje)', () => {
    assert.equal(linhaPublicavel({ ...linhaOk(), id: null }, abaCom), false);
    assert.equal(linhaPublicavel({ ...linhaOk(), title: '' }, abaCom), false);
});
