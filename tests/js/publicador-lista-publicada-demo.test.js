import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════════
// Demo 23/10/2026 — a lista final depois de publicar (teste no navegador, #459).
//
// N17: cada anúncio diz o frete com que saiu, inclusive "a combinar" (aviso 4057).
// N23: a variação mostra a cor de verdade, não "Único".
// H21: anúncio que o Mercado Livre pausou ou pôs em revisão não fica "Publicado".
// O servidor está em `SituacaoDoPublicadoTest`.
// ═══════════════════════════════════════════════════════════════════════════

const PUBLICAR = 'resources/js/Components/Publicador/Mesa/Publicar.jsx';

test('N17 — o frete com que o anúncio saiu aparece na hora; "a combinar" em âmbar', () => {
    const f = lerSemComentarios(PUBLICAR);
    assert.match(f, /\{i\.envio && <span className=\{i\.envio\.a_combinar \? 'text-amber-300' : 'text-white\/45'\} data-envio-do-anuncio=\{i\.envio\.modo\}>· \{i\.envio\.texto\}<\/span>\}/);
    // A linha antiga do frete do publicado só aparece quando o envio não foi lido (itens de antes).
    assert.match(f, /\{! i\.envio && i\.frete\?\.aplicavel === false && <span/);
});

test('N23 — o nome da variação vem do servidor e "Único" não aparece', () => {
    const f = lerSemComentarios(PUBLICAR);
    assert.match(f, /const r = i\.rotulo \?\? variantes\.find\(\(v\) => v\.chave === i\.variante_chave\)\?\.rotulo \?\? null;/);
    assert.match(f, /return r && r !== 'Único' \? r : null;/);
    assert.doesNotMatch(f, /\?\.rotulo \?\? '—'/, 'nem o travessão de antes');
});
