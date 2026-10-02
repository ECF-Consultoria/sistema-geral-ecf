import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gates de fonte da "mesa de anúncio" do Publicador interno (Fase 160,
// UI-SPEC §4/§5/§8.4). Lê a fonte SEM comentários (ver _fonte.js).
//
// A lista abaixo é o ponto de extensão: o plano 160-05 acrescenta os cards
// dele aqui, e os gates de vocabulário passam a valer para eles também.
// ═══════════════════════════════════════════════════════════════════════

const BASE = 'resources/js/Components/Publicador';
const CARDS = [
    `${BASE}/Mesa/comum.jsx`,
    `${BASE}/Mesa/CardProduto.jsx`,
    `${BASE}/Mesa/CardFichaTecnica.jsx`,
];
// Componentes de campo reaproveitados do piloto, normalizados nesta fase.
const NORMALIZADOS = [
    `${BASE}/CampoAtributo.jsx`,
];

for (const caminho of [...CARDS, ...NORMALIZADOS]) {
    const fonte = lerSemComentarios(caminho);

    test(`${caminho} — tipografia: só 24/15/13/11px (sem text-xs/sm/base/lg nem tamanhos intermediários)`, () => {
        assert.doesNotMatch(fonte, /\btext-(xs|sm|base|lg|xl)\b/);
        assert.doesNotMatch(fonte, /text-\[(?!24px\]|15px\]|13px\]|11px\])[0-9.]+px\]/);
    });

    test(`${caminho} — peso: só 400 e 700`, () => {
        assert.doesNotMatch(fonte, /font-(medium|semibold|extrabold|light|thin|black)\b/);
    });
}

for (const caminho of CARDS) {
    const fonte = lerSemComentarios(caminho);

    test(`${caminho} — acento reservado: nenhum bg-ecf-yellow sólido no card`, () => {
        assert.doesNotMatch(fonte, /bg-ecf-yellow(?![/\w-])/);
    });

    test(`${caminho} — Select nativo (sem Radix), sem rota direta e sem HTML cru`, () => {
        assert.doesNotMatch(fonte, /@\/Components\/ui\/select/);
        assert.doesNotMatch(fonte, /\broute\(/);
        assert.doesNotMatch(fonte, /dangerouslySetInnerHTML/);
    });
}

test('apoio.js — fonte única de SECOES (8 chaves, em ordem), secaoDoProblema e CARD_DA_SECAO', async () => {
    const fonte = lerSemComentarios(`${BASE}/apoio.js`);
    assert.match(fonte, /export const SECOES\b/);
    assert.match(fonte, /export const secaoDoProblema\b/);
    assert.match(fonte, /export const CARD_DA_SECAO\b/);
    assert.match(fonte, /export const estadoDasSecoes\b/);

    const chaves = [...fonte.matchAll(/\{ chave: '(\w+)'/g)].map((x) => x[1]);
    assert.deepEqual(chaves, ['categoria', 'caracteristicas', 'variacoes', 'fotos', 'variantes', 'tipos', 'envio', 'descricao']);
});

test('EditorPublicador.jsx — não declara mais SECOES nem secaoDoProblema: importa de ./apoio', () => {
    const fonte = lerSemComentarios(`${BASE}/EditorPublicador.jsx`);
    assert.doesNotMatch(fonte, /^const SECOES\b/m);
    assert.doesNotMatch(fonte, /^const secaoDoProblema\b/m);
    assert.match(fonte, /import \{[^}]*\bSECOES\b[^}]*\} from '\.\/apoio'/);
});

test('CardMesa — section com h3 e chevron com aria-expanded e aria-controls', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/comum.jsx`);
    assert.match(fonte, /<section\b/);
    assert.match(fonte, /<h3\b/);
    assert.match(fonte, /aria-expanded=\{aberto\}/);
    assert.match(fonte, /aria-controls=/);
    assert.match(fonte, /scroll-mt-20/);
});

test('CardProduto — o selo de origem deriva de produto.oferta_id (D27), não de origem', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CardProduto.jsx`);
    assert.match(fonte, /produto\??\.oferta_id/);
    assert.match(fonte, /Item sincronizado do Portal/);
    assert.match(fonte, /Cadastrado no Publicador/);
    assert.match(fonte, /m\.buscarCategorias/);
    assert.match(fonte, /m\.escolherCategoria/);
});

