// Correções do teste no navegador antes da demo pública de 23/10/2026 (conta #459).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { tituloNoCampo } from '../../resources/js/Components/Publicador/derivados.js';

const ler = (c) => readFileSync(c, 'utf8');
const semComentarios = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
const BASE = 'resources/js/Components/Publicador';

// ─── N15: faixa "ainda não subiu" só para foto usada no anúncio ─────────────

test('N15 — AvisosDasFotos não avisa falha de foto fora de grupo (referência do cliente, §40)', () => {
    const f = semComentarios(ler(`${BASE}/FotosPorGrupo.jsx`));
    const avisos = f.slice(f.indexOf('export function AvisosDasFotos'), f.indexOf('export function BlocoDeFotos'));
    assert.doesNotMatch(avisos, /<Falha|soltas|data-fotos-falhas/);
    assert.match(avisos, /\.filter\(usada\)/);
});

test('N15 — a falha diz a foto pela miniatura e pela posição, não pelo id interno', () => {
    const f = semComentarios(ler(`${BASE}/FotosPorGrupo.jsx`));
    assert.doesNotMatch(f, /Foto \{f\.id\}/);
    assert.match(f, /data-foto-falha-miniatura/);
    assert.match(f, /`\$\{posicao\}ª foto`/);
    assert.match(f, /posicao=\{indice >= 0 \? indice \+ 1 : null\}/);
});

// ─── N21: título do Portal como texto, com selo ─────────────────────────────

test('N21 — o título do Portal é o valor do campo (não placeholder), com o selo "do Portal"', () => {
    const f = semComentarios(ler(`${BASE}/Mesa/EtapaProduto.jsx`));
    // P-31d (23/10/2026): a decisão do que o campo mostra foi para `tituloNoCampo` (derivados.js), com o "esvaziado".
    assert.match(f, /const \{ valor: valorNoCampo, doPortal \} = tituloNoCampo\(\{ titulo, efetivo: a\.titulo_efetivo, comPortal: !! m\.estado\.produto\?\.oferta_id, esvaziado \}\)/);
    assert.match(f, /valor=\{valorNoCampo\}/);
    assert.match(f, /placeholder=\{doPortal \? undefined :/);
    assert.match(f, /data-selo-titulo-portal=\{lt\}>do Portal</);
    assert.deepEqual(tituloNoCampo({ titulo: '', efetivo: 'Cadeira Preta', comPortal: true }), { valor: 'Cadeira Preta', doPortal: true });
    assert.deepEqual(tituloNoCampo({ titulo: '', efetivo: 'Cadeira Preta', comPortal: false }), { valor: '', doPortal: false }, 'sem oferta do Portal, é placeholder');
    assert.deepEqual(tituloNoCampo({ titulo: 'Mesa', efetivo: 'Cadeira Preta', comPortal: true }), { valor: 'Mesa', doPortal: false });
});

// ─── P-31d: apagar tudo e digitar começa um título do zero ─────────────────

test('P-31d — o campo que a pessoa esvaziou fica vazio enquanto ela está nele; o do Portal volta ao sair', () => {
    const portal = { efetivo: 'Cadeira Gamer Reclinável Preta', comPortal: true };
    // Ctrl+A e Backspace: o rascunho fica sem título e o campo, VAZIO (antes voltava o título do Portal inteiro).
    assert.deepEqual(tituloNoCampo({ ...portal, titulo: '', esvaziado: true }), { valor: '', doPortal: false });
    // A próxima letra é o começo do título novo, não "… PretaM".
    assert.deepEqual(tituloNoCampo({ ...portal, titulo: 'M', esvaziado: true }), { valor: 'M', doPortal: false });
    // Saiu do campo vazio: volta a seguir o Portal (decisão §47).
    assert.deepEqual(tituloNoCampo({ ...portal, titulo: '', esvaziado: false }), { valor: portal.efetivo, doPortal: true });

    const f = semComentarios(ler(`${BASE}/Mesa/EtapaProduto.jsx`));
    assert.match(f, /if \(novo === ''\) setEsvaziado\(true\);\s*mudar\(\{ titulo: novo \}\);/, 'esvaziar marca o campo');
    assert.match(f, /onBlur=\{\(\) => setEsvaziado\(false\)\}/, 'sair do campo devolve o do Portal');
});
