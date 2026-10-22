// Correções do teste no navegador antes da demo pública de 23/10/2026 (conta #459).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

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
    assert.match(f, /const doPortal = ! titulo && !! \(a\.titulo_efetivo && m\.estado\.produto\?\.oferta_id\)/);
    assert.match(f, /valor=\{doPortal \? a\.titulo_efetivo : titulo\}/);
    assert.match(f, /placeholder=\{doPortal \? undefined :/);
    assert.match(f, /data-selo-titulo-portal=\{lt\}>do Portal</);
});
