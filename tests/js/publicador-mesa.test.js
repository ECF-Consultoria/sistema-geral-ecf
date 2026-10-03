import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gates de fonte da "mesa de anúncio" do Publicador interno (Fase 164,
// UI-SPEC §4/§5/§8.4). Lê a fonte SEM comentários (ver _fonte.js).
//
// A lista abaixo é o ponto de extensão: o plano 164-05 acrescenta os cards
// dele aqui, e os gates de vocabulário passam a valer para eles também.
// ═══════════════════════════════════════════════════════════════════════

const BASE = 'resources/js/Components/Publicador';
const CARDS = [
    `${BASE}/Mesa/comum.jsx`,
    `${BASE}/Mesa/CardProduto.jsx`,
    `${BASE}/Mesa/CardFichaTecnica.jsx`,
    `${BASE}/Mesa/CardFotos.jsx`,
    `${BASE}/Mesa/CardVariacoes.jsx`,
    `${BASE}/Mesa/CartaoVariante.jsx`,
    `${BASE}/Mesa/CardTiposEPrecos.jsx`,
    `${BASE}/Mesa/CardLogistica.jsx`,
    `${BASE}/Mesa/CardDescricao.jsx`,
];
// Componentes de campo reaproveitados do piloto, normalizados nesta fase.
const NORMALIZADOS = [
    `${BASE}/CampoAtributo.jsx`,
    `${BASE}/FotosPorGrupo.jsx`,
    `${BASE}/Problemas.jsx`,
    `${BASE}/EditorDeEixos.jsx`,
    `${BASE}/GradeVariantes.jsx`,
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

test('CardFotos — regra de foto lê schema.limites (nada fixo) e usa os grupos do servidor', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CardFotos.jsx`);
    assert.doesNotMatch(fonte, /1200/);
    assert.match(fonte, /limites/);
    assert.match(fonte, /grupos_imagem/);
    assert.match(fonte, /publicacao_liberada/);
});

test('FotosPorGrupo — envioAoMl: foto pendente em conta não liberada vira nota neutra (D26)', () => {
    const fonte = lerSemComentarios(`${BASE}/FotosPorGrupo.jsx`);
    assert.match(fonte, /envioAoMl = true/);
    assert.match(fonte, /sobem para o Mercado Livre quando a publicação for liberada para esta conta/);
});

test('CartaoVariante — campos de estoque/SKU/GTIN vêm de GradeVariantes (sem duplicar a lógica de depósito)', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CartaoVariante.jsx`);
    assert.match(fonte, /from '\.\.\/GradeVariantes'/);
    assert.match(fonte, /CampoEstoque/);
    assert.match(fonte, /CampoSku/);
    assert.match(fonte, /CampoGtin/);
    assert.doesNotMatch(fonte, /estoque_depositos/);
});

test('GradeVariantes — exporta CampoEstoque, CampoSku e CampoGtin por nome', () => {
    const fonte = lerSemComentarios(`${BASE}/GradeVariantes.jsx`);
    assert.match(fonte, /export function CampoEstoque\b/);
    assert.match(fonte, /export function CampoSku\b/);
    assert.match(fonte, /export function CampoGtin\b/);
});

test('CartaoVariante — preço usa precos_efetivos como placeholder; a dica da Precificação só com oferta_id', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CartaoVariante.jsx`);
    assert.match(fonte, /precos_efetivos/);
    assert.match(fonte, /produto\?\.oferta_id/);
    assert.match(fonte, /em branco = o da Precificação/);
});

test('CartaoVariante — não existe campo de título por variante (o título é por tipo, Q-UI-10)', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CartaoVariante.jsx`);
    assert.doesNotMatch(fonte, /data-titulo/);
    assert.doesNotMatch(fonte, /titulo:/);
});

test('CardDescricao — texto simples (RN-72): sem Markdown, prévia ou regenerar', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CardDescricao.jsx`);
    assert.doesNotMatch(fonte, /markdown|prévia|regenerar/i);
    assert.match(fonte, /<textarea/);
});

test('CardTiposEPrecos — máximo do título vem de schema.limites (fallback 60); vermelho só acima dele', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CardTiposEPrecos.jsx`);
    assert.match(fonte, /max_title_length/);
    assert.match(fonte, /tamanho > maxTitulo/);
    assert.match(fonte, /m\.copiarTituloDo/);
    assert.match(fonte, /m\.simular\(\)/);
});

test('CardTiposEPrecos — a dica "vem da aba Anúncios" só com oferta_id', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CardTiposEPrecos.jsx`);
    assert.match(fonte, /produto\?\.oferta_id/);
    assert.match(fonte, /vem da aba Anúncios/);
});

test('CardLogistica — Seletor nativo, modos de envio do servidor e medidas da seção EMBALAGEM', () => {
    const fonte = lerSemComentarios(`${BASE}/Mesa/CardLogistica.jsx`);
    assert.match(fonte, /Seletor/);
    assert.match(fonte, /modos_envio/);
    assert.match(fonte, /EMBALAGEM/);
    assert.match(fonte, /SELLER_PACKAGE_WEIGHT/);
    assert.doesNotMatch(fonte, /Coleta elegível/);
});

test('CardVariacoes — eixos editáveis por m.salvarEixos e fotos do servidor (grupos_imagem)', () => {
    assert.match(lerSemComentarios(`${BASE}/Mesa/CardVariacoes.jsx`), /m\.salvarEixos/);
    assert.match(lerSemComentarios(`${BASE}/Mesa/CartaoVariante.jsx`), /grupos_imagem/);
});
