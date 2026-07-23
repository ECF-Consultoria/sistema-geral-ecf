import test from 'node:test';
import assert from 'node:assert/strict';
import {
    LIMITES_MERCADO_ENVIOS,
    PRECO_FRETE_GRATIS_OBRIGATORIO,
    pesoVolumetricoKg,
    checarDimensoesPacote,
    extrairSettingsCategoria,
    completudeFicha,
    analisarAnuncio,
} from '../../resources/js/lib/mlAnuncioRegras.js';

// ═══════════════════════════════════════════════════════════════════════
// Regras de publicação do Mercado Livre (guard-rail + saúde do anúncio).
// Fixam o comportamento das funções puras que o wizard consome — se alguém
// mudar um limiar sem querer, um teste acende.
// ═══════════════════════════════════════════════════════════════════════

// helper: predicado de "atributo preenchido" espelhando o wizard
const preenchido = (valores) => (a) => !!(valores[a.id]?.value_id || valores[a.id]?.value_name);

// base mínima de um anúncio "saudável" para partir e degradar por caso
function anuncioBase(over = {}) {
    return {
        titulo: 'Tênis de corrida masculino leve confortável',
        categoria: { settings: { minimum_price: 0, maximum_price: null, max_pictures_per_item: 12, max_description_length: 50000, listing_allowed: true, status: 'enabled', item_conditions: ['new', 'used'] } },
        categoryId: 'MLB1234',
        preco: '199.90',
        estoque: '5',
        condicao: 'new',
        tipoAnuncio: 'gold_special',
        imagemUrl: 'https://x/img.jpg',
        descricao: 'Descrição completa do produto com benefícios, medidas, materiais e conteúdo da embalagem para passar de 120 caracteres tranquilamente aqui.',
        temVariacoes: false,
        variacoes: [],
        obrigatorios: [{ id: 'BRAND' }, { id: 'MODEL' }],
        opcionais: [{ id: 'COLOR_DETAIL' }],
        preenchido: preenchido({ BRAND: { value_id: '1' }, MODEL: { value_name: 'X' }, COLOR_DETAIL: { value_name: 'azul' } }),
        pesoG: '500', comprimentoCm: '30', larguraCm: '20', alturaCm: '10',
        shippingMode: 'me2', freteGratis: false,
        ...over,
    };
}

// ─── pesoVolumetricoKg ───

test('peso volumétrico = C×L×A / 6000', () => {
    assert.equal(pesoVolumetricoKg(30, 20, 10), 1); // 6000/6000
    assert.equal(pesoVolumetricoKg(0, 20, 10), null);
    assert.equal(pesoVolumetricoKg('', '', ''), null);
});

// ─── checarDimensoesPacote ───

test('pacote dentro do Mercado Envios não excede', () => {
    const r = checarDimensoesPacote({ pesoG: 500, comprimentoCm: 30, larguraCm: 20, alturaCm: 10 });
    assert.equal(r.excedeME, false);
    assert.equal(r.ladoMinInvalido, false);
});

test('pacote acima do peso máximo excede', () => {
    const r = checarDimensoesPacote({ pesoG: LIMITES_MERCADO_ENVIOS.pesoMaxG + 1, comprimentoCm: 30, larguraCm: 20, alturaCm: 10 });
    assert.equal(r.excedeME, true);
    assert.ok(r.motivos.some(m => m.includes('peso')));
});

test('maior lado acima do limite excede', () => {
    const r = checarDimensoesPacote({ pesoG: 500, comprimentoCm: LIMITES_MERCADO_ENVIOS.maiorLadoMaxCm + 10, larguraCm: 20, alturaCm: 10 });
    assert.equal(r.excedeME, true);
    assert.ok(r.motivos.some(m => m.includes('maior lado')));
});

test('lado menor que o mínimo é sinalizado', () => {
    const r = checarDimensoesPacote({ pesoG: 500, comprimentoCm: 2, larguraCm: 20, alturaCm: 10 });
    assert.equal(r.ladoMinInvalido, true);
});

test('lado zero (não preenchido) NÃO conta como mínimo inválido', () => {
    const r = checarDimensoesPacote({ pesoG: '', comprimentoCm: '', larguraCm: '', alturaCm: '' });
    assert.equal(r.ladoMinInvalido, false);
    assert.equal(r.excedeME, false);
});

// ─── extrairSettingsCategoria ───

test('extrai settings com defaults quando ausente', () => {
    const s = extrairSettingsCategoria(null);
    assert.equal(s.maxFotos, 12);
    assert.equal(s.maxDescricao, 50000);
    assert.equal(s.precoMin, null);
    assert.equal(s.listingAllowed, true); // permissivo por padrão
});

test('minimum_price 0 vira null-útil (não bloqueia)', () => {
    const s = extrairSettingsCategoria({ settings: { minimum_price: 0 } });
    assert.equal(s.precoMin, 0); // preservado, mas o guard só bloqueia quando > 0
});

test('listing_allowed false é capturado', () => {
    const s = extrairSettingsCategoria({ settings: { listing_allowed: false, status: 'disabled' } });
    assert.equal(s.listingAllowed, false);
    assert.equal(s.statusOk, false);
});

// ─── completudeFicha ───

test('completude conta obrigatórios e opcionais', () => {
    const obr = [{ id: 'A' }, { id: 'B' }];
    const opc = [{ id: 'C' }, { id: 'D' }];
    const f = completudeFicha(obr, opc, preenchido({ A: { value_name: '1' }, C: { value_name: '2' } }));
    assert.equal(f.obrOk, 1);
    assert.equal(f.obrTotal, 2);
    assert.equal(f.obrCompleta, false);
    assert.equal(f.opcOk, 1);
    assert.equal(f.opcPct, 50);
});

// ─── analisarAnuncio: cenário saudável ───

test('anúncio completo não gera erros bloqueantes', () => {
    const r = analisarAnuncio(anuncioBase());
    assert.equal(r.erros.length, 0);
    assert.ok(r.score >= 80, `score esperado alto, veio ${r.score}`);
});

// ─── analisarAnuncio: erros bloqueantes ───

test('anúncio sem foto (sem variação) bloqueia', () => {
    const r = analisarAnuncio(anuncioBase({ imagemUrl: '' }));
    assert.ok(r.erros.some(e => e.campo === 'imagem'));
});

test('obrigatório da ficha faltando bloqueia', () => {
    const r = analisarAnuncio(anuncioBase({
        preenchido: preenchido({ BRAND: { value_id: '1' } }), // MODEL faltando
    }));
    assert.ok(r.erros.some(e => e.campo === 'ficha técnica'));
});

test('preço abaixo do mínimo da categoria bloqueia', () => {
    const r = analisarAnuncio(anuncioBase({
        categoria: { settings: { minimum_price: 100, listing_allowed: true, status: 'enabled', item_conditions: ['new'] } },
        preco: '50',
    }));
    assert.ok(r.erros.some(e => e.campo === 'preço'));
});

test('condição não aceita pela categoria bloqueia', () => {
    const r = analisarAnuncio(anuncioBase({
        categoria: { settings: { item_conditions: ['used'], listing_allowed: true, status: 'enabled' } },
        condicao: 'new',
    }));
    assert.ok(r.erros.some(e => e.campo === 'condição'));
});

test('categoria que não aceita novos anúncios bloqueia', () => {
    const r = analisarAnuncio(anuncioBase({
        categoria: { settings: { listing_allowed: false, status: 'enabled' } },
    }));
    assert.ok(r.erros.some(e => e.campo === 'categoria'));
});

// ─── analisarAnuncio: avisos (não bloqueiam) ───

test('dimensões grandes geram AVISO, não erro', () => {
    const r = analisarAnuncio(anuncioBase({ pesoG: String(LIMITES_MERCADO_ENVIOS.pesoMaxG + 5000) }));
    assert.ok(r.avisos.some(a => a.campo === 'dimensões'));
    assert.equal(r.erros.some(e => e.campo === 'dimensões'), false);
});

test('preço alto sem frete grátis (me2) gera aviso', () => {
    const r = analisarAnuncio(anuncioBase({ preco: String(PRECO_FRETE_GRATIS_OBRIGATORIO + 50), freteGratis: false, shippingMode: 'me2' }));
    assert.ok(r.avisos.some(a => a.campo === 'frete'));
});

test('preço alto COM frete grátis não avisa sobre frete', () => {
    const r = analisarAnuncio(anuncioBase({ preco: String(PRECO_FRETE_GRATIS_OBRIGATORIO + 50), freteGratis: true, shippingMode: 'me2' }));
    assert.equal(r.avisos.some(a => a.campo === 'frete'), false);
});

test('descrição curta gera aviso', () => {
    const r = analisarAnuncio(anuncioBase({ descricao: 'curta' }));
    assert.ok(r.avisos.some(a => a.campo === 'descrição'));
});
