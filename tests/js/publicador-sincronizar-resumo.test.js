import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import { motivosNaoTrazidas, textoDoResumo } from '../../resources/js/Components/Mlb/Publicador/resumoDoSincronizar.js';

// Fase 172-12 — resumo do "Sincronizar do Portal": textos puros e gates de fonte do acompanhamento.

const DIR = 'resources/js/Components/Mlb/Publicador/';

test('textoDoResumo — frase completa com mantidos', () => {
    assert.equal(
        textoDoResumo({ produtos: 2, variantes: 5, fotos_trazidas: 8, campos_mantidos: 3 }),
        '2 produtos, 5 variações, 8 fotos trazidas; 3 campos mantidos porque já estavam preenchidos.',
    );
});

test('textoDoResumo — singular com 1 e sem a parte dos mantidos quando zero', () => {
    assert.equal(
        textoDoResumo({ produtos: 1, variantes: 1, fotos_trazidas: 1, campos_mantidos: 1 }),
        '1 produto, 1 variação, 1 foto trazida; 1 campo mantido porque já estava preenchido.',
    );
    assert.equal(textoDoResumo({ produtos: 0, variantes: 0, fotos_trazidas: 0, campos_mantidos: 0 }), '0 produtos, 0 variações, 0 fotos trazidas.');
    assert.equal(textoDoResumo(null), '0 produtos, 0 variações, 0 fotos trazidas.');
});

test('motivosNaoTrazidas — frases por motivo, código quando desconhecido', () => {
    const l = motivosNaoTrazidas({ pequena: 2, formato: 1 });
    assert.deepEqual(l, ['2 fotos pequenas demais (mínimo 500 px)', '1 foto em formato não aceito']);
    assert.deepEqual(motivosNaoTrazidas({ estranho: 3 }), ['3 fotos (estranho)']);
    assert.deepEqual(motivosNaoTrazidas({ pequena: 0 }), []);
    assert.deepEqual(motivosNaoTrazidas(undefined), []);
});

test('BotaoSincronizarPortal — acompanha a rota do resumo só com onResumo, a cada 2500 ms, por 5 min', () => {
    const fonte = lerSemComentarios(DIR + 'BotaoSincronizarPortal.jsx');
    assert.match(fonte, /mlb\.anuncios\.publicador\.sincronizar\.resumo/);
    assert.match(fonte, /INTERVALO_MS = 2500/);
    assert.match(fonte, /5 \* 60 \* 1000/);
    assert.match(fonte, /data\?\.pedido && onResumo/);
    assert.match(fonte, /'pronto'/);
});

test('Produtos.jsx — passa onResumo aos dois botões, mostra o painel e recarrega ao ficar pronto', () => {
    const fonte = lerSemComentarios('resources/js/Pages/Mlb/Publicador/Produtos.jsx');
    assert.equal((fonte.match(/onResumo=\{aoLerResumo\}/g) ?? []).length, 2);
    assert.match(fonte, /<ResumoDoSincronizar /);
    assert.match(fonte, /router\.reload\(\{ only: \['produtos', 'contagens'\] \}\)/);
});

test('AnunciosEmpresas.jsx — não passa onResumo (a tela A segue sem resumo)', () => {
    const fonte = lerSemComentarios('resources/js/Pages/Mlb/AnunciosEmpresas.jsx');
    assert.doesNotMatch(fonte, /onResumo/);
});

test('ResumoDoSincronizar.jsx — vocabulário visual da página: 24/15/13/11px, peso 400/700, sem amarelo sólido', () => {
    const fonte = lerSemComentarios(DIR + 'ResumoDoSincronizar.jsx');
    for (const m of fonte.matchAll(/text-\[(\d+(?:\.\d+)?)px\]/g)) assert.ok(['24', '15', '13', '11'].includes(m[1]), m[1]);
    assert.doesNotMatch(fonte, /font-(thin|extralight|light|medium|semibold|extrabold|black)\b/);
    assert.doesNotMatch(fonte, /\bbg-ecf-yellow(?!\/)/);
    assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/);
});
