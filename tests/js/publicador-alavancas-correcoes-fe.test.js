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
