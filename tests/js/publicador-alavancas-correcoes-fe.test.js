import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Correções da revisão do frontend da Fase 166 (CR-FE-*, WR-FE-*).
// Testes de fonte (o `node --test` não importa .jsx) e de função pura (`formato.js`).
// ═══════════════════════════════════════════════════════════════════════

const RAIZ = resolve(import.meta.dirname, '../..');
const PASTA = 'resources/js/Components/Mlb/Alavancas';

const JSX_DA_PASTA = readdirSync(resolve(RAIZ, PASTA), { recursive: true })
    .map((c) => String(c).replaceAll('\\', '/'))
    .filter((c) => /\.jsx$/.test(c))
    .map((c) => `${PASTA}/${c}`);

/** Corpo (entre as chaves) da `function nome(...) { ... }`, por contagem de chaves. */
function corpoDaFuncao(fonte, nome) {
    const inicio = fonte.search(new RegExp(`function ${nome}\\s*\\(`));
    if (inicio === -1) return null;
    const abre = fonte.indexOf('{', fonte.indexOf(')', inicio));
    let nivel = 0;
    for (let i = abre; i < fonte.length; i++) {
        if (fonte[i] === '{') nivel++;
        if (fonte[i] === '}' && --nivel === 0) return fonte.slice(abre + 1, i);
    }

    return null;
}

// ─── CR-FE-01: o `onConcluido` só relê; quem fecha é o `onFechar` ───
for (const caminho of JSX_DA_PASTA) {
    const fonte = lerSemComentarios(caminho);

    test(`${caminho} — CR-FE-01: função passada ao onConcluido não desmonta a janela nem o formulário`, () => {
        for (const m of fonte.matchAll(/onConcluido=\{(\w+)\}/g)) {
            const corpo = corpoDaFuncao(fonte, m[1]);
            if (corpo === null) continue;
            assert.doesNotMatch(corpo, /set(Alvo|Form)\(\s*null\s*\)/, `${m[1]} fecha janela/formulário dentro do onConcluido`);
        }
        for (const m of fonte.matchAll(/onConcluido=\{\(([^)]*)\)\s*=>\s*([^}]*)\}/g)) {
            assert.doesNotMatch(m[2], /set(Alvo|Form)\(\s*null\s*\)/);
        }
    });
}

test('CR-FE-01: ModalConfirmacao entrega o último resultado ao onFechar', () => {
    const fonte = lerSemComentarios(`${PASTA}/ModalConfirmacao.jsx`);
    assert.match(fonte, /onFechar\?\.\(ultimo\.current\)/);
    assert.doesNotMatch(fonte, /onClick=\{onFechar\}/);
});

test('CR-FE-01: FormCupom e AbaCupons fecham o formulário só no onEncerrado (resultado OK)', () => {
    const form = lerSemComentarios(`${PASTA}/Cupons/FormCupom.jsx`);
    const aba = lerSemComentarios(`${PASTA}/AbaCupons.jsx`);
    assert.match(form, /resultado\?\.resultado === 'OK'\) onEncerrado/);
    assert.match(aba, /onEncerrado=\{\(\) => setForm\(null\)\}/);
});

// ─── CR-FE-02: fmtData não desloca data pura ───
test('CR-FE-02: data pura aaaa-mm-dd sai igual, sem conversão de fuso', async () => {
    const { fmtData } = await import('../../resources/js/Components/Mlb/Alavancas/formato.js');
    assert.equal(fmtData('2026-10-05'), '05/10/2026');
    assert.equal(fmtData('2026-10-18'), '18/10/2026');
    assert.equal(fmtData('2026-01-01', { hora: true }), '01/01/2026');
});

test('CR-FE-02: instante com Z ou offset continua convertido para São Paulo', async () => {
    const { fmtData } = await import('../../resources/js/Components/Mlb/Alavancas/formato.js');
    assert.equal(fmtData('2026-10-05T02:00:00Z'), '04/10/2026');
    assert.equal(fmtData('2026-10-05T15:30:00Z', { hora: true }), '05/10/2026 12:30');
    assert.equal(fmtData('2026-10-05T00:00:00-03:00'), '05/10/2026');
});

test('CR-FE-02: ISO sem fuso vale horário de São Paulo, e vazio/inválido vira traço', async () => {
    const { fmtData } = await import('../../resources/js/Components/Mlb/Alavancas/formato.js');
    assert.equal(fmtData('2026-10-05T23:59:59'), '05/10/2026');
    assert.equal(fmtData('2026-10-05T23:59:59', { hora: true }), '05/10/2026 23:59');
    assert.equal(fmtData(null), '—');
    assert.equal(fmtData('lixo'), '—');
});

// ─── WR-FE-09: leitura única de número pt-BR ───
test('WR-FE-09: lerNumero — com vírgula, ponto é milhar e vírgula é decimal', async () => {
    const { lerNumero } = await import('../../resources/js/Components/Mlb/Alavancas/formato.js');
    assert.equal(lerNumero('1.500,50'), 1500.5);
    assert.equal(lerNumero('12,50'), 12.5);
    assert.equal(lerNumero('1.234.567,8'), 1234567.8);
});

test('WR-FE-09: lerNumero — sem vírgula, ponto + 3 dígitos é milhar; o resto é decimal', async () => {
    const { lerNumero } = await import('../../resources/js/Components/Mlb/Alavancas/formato.js');
    assert.equal(lerNumero('1.500'), 1500);
    assert.equal(lerNumero('1.299'), 1299);
    assert.equal(lerNumero('1.500.000'), 1500000);
    assert.equal(lerNumero('1.5'), 1.5);
    assert.equal(lerNumero('85.90'), 85.9);
    assert.equal(lerNumero('0.500'), 0.5);
    assert.equal(lerNumero('1500'), 1500);
});

test('WR-FE-09: lerNumero — vazio e inválido viram null; `positivo` recusa zero e negativo', async () => {
    const { lerNumero } = await import('../../resources/js/Components/Mlb/Alavancas/formato.js');
    assert.equal(lerNumero(''), null);
    assert.equal(lerNumero('  '), null);
    assert.equal(lerNumero(null), null);
    assert.equal(lerNumero('abc'), null);
    assert.equal(lerNumero('1,2,3'), null);
    assert.equal(lerNumero('0'), 0);
    assert.equal(lerNumero('0', { positivo: true }), null);
    assert.equal(lerNumero('-5', { positivo: true }), null);
});

test('WR-FE-09: nenhuma tela mantém cópia própria do parser (todas usam lerNumero)', () => {
    for (const caminho of JSX_DA_PASTA) {
        const fonte = lerSemComentarios(caminho);
        assert.doesNotMatch(fonte, /includes\(','\)/, `${caminho} tem parser próprio`);
        assert.doesNotMatch(fonte, /\.replace\(',', '\.'\)/, `${caminho} tem parser próprio`);
    }
    for (const arq of ['Promocoes/ItensDoConvite', 'Promocoes/AdicionarProdutos', 'Promocoes/DescontoIndividual', 'Cupons/FormCupom', 'Atacado/FaixasDoAnuncio', 'Promocoes/CampanhasDoVendedor']) {
        assert.match(lerSemComentarios(`${PASTA}/${arq}.jsx`), /lerNumero/, arq);
    }
});

// ─── WR-FE-07: dia em São Paulo, não em UTC ───
test('WR-FE-07: diaSP converte instante com Z/offset para o dia de São Paulo', async () => {
    const { diaSP } = await import('../../resources/js/Components/Mlb/Alavancas/formato.js');
    assert.equal(diaSP('2026-10-05T02:00:00Z'), '2026-10-04');
    assert.equal(diaSP('2026-10-18T02:59:59Z'), '2026-10-17');
    assert.equal(diaSP('2026-10-05T00:00:00-03:00'), '2026-10-05');
    assert.equal(diaSP('2026-10-05T23:30:00-03:00'), '2026-10-05');
});

test('WR-FE-07: diaSP sem fuso devolve os 10 primeiros caracteres; vazio/inválido vira texto vazio', async () => {
    const { diaSP } = await import('../../resources/js/Components/Mlb/Alavancas/formato.js');
    assert.equal(diaSP('2026-10-05'), '2026-10-05');
    assert.equal(diaSP('2026-10-05T23:59:59'), '2026-10-05');
    assert.equal(diaSP(null), '');
    assert.equal(diaSP('lixo'), '');
});

test('WR-FE-07: FormCupom e CampanhasDoVendedor usam diaSP, sem cortar o ISO em UTC', () => {
    for (const arq of ['Cupons/FormCupom', 'Promocoes/CampanhasDoVendedor']) {
        const fonte = lerSemComentarios(`${PASTA}/${arq}.jsx`);
        assert.match(fonte, /diaSP\(/, arq);
        assert.doesNotMatch(fonte, /\.slice\(0, 10\)/, arq);
    }
});
