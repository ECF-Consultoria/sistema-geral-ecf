import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import {
    UNIDADES_MEDIDA, UNIDADES_PESO, daUnidadeMl, digitoEan13, eanValido, eixosComValores, eixosSemValor, gerarEan13, gtinsEmUso, juntarTermo,
    numeroDoAtributo, paraUnidadeMl, pedidoCompleto, termoNoTitulo, unidadeInicial, varianteDoPedido, variantesSemGtin,
} from '../../resources/js/Components/Publicador/ferramentas.js';

// ═══════════════════════════════════════════════════════════════════════
// Melhoria do Publicador de 03/10/2026 (melhoria_publicador.docx):
// ferramentas puras + gates de fonte de cada item do documento.
// ═══════════════════════════════════════════════════════════════════════

const BASE = 'resources/js/Components/Publicador';

// ── §4 EAN-13 ──

test('digitoEan13 — mesmo cálculo do gerador do time (posição ímpar ×1, par ×3)', () => {
    // 7891000315507 (Nescau): EAN real, conferido.
    assert.equal(digitoEan13('789100031550'), 7);
    assert.equal(eanValido('7891000315507'), true);
    assert.equal(eanValido('7891000315508'), false);
    assert.equal(eanValido('123'), false);
});

test('gerarEan13 — 789 + 13 dígitos, verificador válido e sem repetir os existentes', () => {
    for (let i = 0; i < 200; i++) {
        const ean = gerarEan13();
        assert.match(ean, /^789\d{10}$/);
        assert.ok(eanValido(ean), ean);
    }
    // Sorteio fixo: o primeiro candidato já existe, então tem de sair outro.
    let n = 0;
    const sorteios = [0.1, 0.1, 0.1, 0.1, 0.1, 0.1, 0.1, 0.1, 0.1, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2, 0.2];
    const sorteio = () => sorteios[n++ % sorteios.length];
    const primeiro = gerarEan13(new Set(), () => 0.1);
    const outro = gerarEan13(new Set([primeiro]), sorteio);
    assert.notEqual(outro, primeiro);
    assert.ok(eanValido(outro));
});

test('variantesSemGtin — só ativa, não publicada, sem código e sem o motivo de "não tem código"; e só se a categoria pede GTIN', () => {
    const schema = { atributos: { GTIN: {} } };
    const vs = [
        { chave: 'a', ativa: true, atributos: {} },
        { chave: 'b', ativa: true, atributos: { GTIN: { value_name: '7891000315507' } } },
        { chave: 'c', ativa: false, atributos: {} },
        { chave: 'd', ativa: true, publicada: true, atributos: {} },
        { chave: 'e', ativa: true, atributos: { EMPTY_GTIN_REASON: { value_id: '17055158' } } },
        { chave: 'f', ativa: true, orfa: true, atributos: {} },
        { chave: 'g', ativa: true, atributos: { GTIN: { value_name: '  ' } } },
    ];
    assert.deepEqual(variantesSemGtin(vs, schema).map((v) => v.chave), ['a', 'g']);
    assert.deepEqual(variantesSemGtin(vs, { atributos: {} }), []);
    assert.deepEqual([...gtinsEmUso(vs)], ['7891000315507']);
});

// ── §5 unidades do pacote ──

test('paraUnidadeMl — kg vira g sem casas; m e mm viram cm com uma casa; vazio ou ≤ 0 = nulo', () => {
    assert.equal(paraUnidadeMl('1,5', UNIDADES_PESO.kg, 0), 1500);
    assert.equal(paraUnidadeMl('0.25', UNIDADES_PESO.kg, 0), 250);
    assert.equal(paraUnidadeMl('800', UNIDADES_PESO.g, 0), 800);
    assert.equal(paraUnidadeMl('1,2', UNIDADES_MEDIDA.m, 1), 120);
    assert.equal(paraUnidadeMl('125', UNIDADES_MEDIDA.mm, 1), 12.5);
    assert.equal(paraUnidadeMl('', UNIDADES_PESO.kg, 0), null);
    assert.equal(paraUnidadeMl('0', UNIDADES_PESO.kg, 0), null);
    assert.equal(paraUnidadeMl('abc', UNIDADES_PESO.kg, 0), null);
});

test('daUnidadeMl / numeroDoAtributo / unidadeInicial — o gravado volta na unidade da tela', () => {
    assert.equal(numeroDoAtributo({ value_name: '1500 g' }), 1500);
    assert.equal(numeroDoAtributo({ value_name: '12.5 cm' }), 12.5);
    assert.equal(numeroDoAtributo(null), null);
    assert.equal(daUnidadeMl(1500, UNIDADES_PESO.kg), '1,5');
    assert.equal(daUnidadeMl(120, UNIDADES_MEDIDA.m), '1,2');
    assert.equal(daUnidadeMl(null, 1), '');
    assert.equal(unidadeInicial(1500, 'peso'), 'kg');
    assert.equal(unidadeInicial(800, 'peso'), 'g');
    assert.equal(unidadeInicial(null, 'peso'), 'g');
    assert.equal(unidadeInicial(30, 'medida'), 'cm');
});

// ── §3 termos no título ──

test('juntarTermo — acrescenta só as palavras que faltam (sem acento/caixa) e termoNoTitulo reconhece', () => {
    assert.equal(juntarTermo('Cadeira Escritório', 'cadeira escritorio giratoria'), 'Cadeira Escritório giratoria');
    assert.equal(juntarTermo('', 'cadeira gamer'), 'cadeira gamer');
    assert.equal(juntarTermo('Cadeira Gamer', 'cadeira gamer'), 'Cadeira Gamer');
    assert.equal(termoNoTitulo('Cadeira Escritório Giratória', 'cadeira giratoria'), true);
    assert.equal(termoNoTitulo('Cadeira Escritório', 'cadeira gamer'), false);
    assert.equal(termoNoTitulo('', ''), false);
});

// ── Gates de fonte ──

test('§1 — a mesa ocupa a largura (sem o teto de 800px)', () => {
    const f = lerSemComentarios('resources/js/Pages/Mlb/Publicador/Editor.jsx');
    assert.match(f, /grid-cols-\[minmax\(0,1fr\)_340px\]/);
});

test('§2 — Modelo: botão da IA, contador de 120 e pedido automático ao escolher categoria com o Modelo vazio', () => {
    const card = lerSemComentarios(`${BASE}/Mesa/CardFichaTecnica.jsx`);
    assert.match(card, /m\.pedirPalavrasIa\('modelo'\)/);
    assert.match(card, /LIMITE_MODELO = 120/);
    const hook = lerSemComentarios(`${BASE}/usePublicador.js`);
    assert.match(hook, /pedirPalavrasIa\('modelo', \{ automatico: true \}\)/);
    assert.match(hook, /valorVazio\(rascRef\.current\?\.atributos\?\.MODEL\)/);
    // O pedido automático não pisa no que a pessoa escreveu enquanto a IA trabalhava.
    assert.match(hook, /automatico && ! valorVazio\(rascRef\.current\?\.atributos\?\.MODEL\)/);
    // Só aceita a resposta do próprio pedido.
    assert.match(hook, /data\.pedido !== s\.pedido/);
});

test('§3 — título: painel de termos com filtro de coerência e a IA por tipo de anúncio', () => {
    const card = lerSemComentarios(`${BASE}/Mesa/CardTiposEPrecos.jsx`);
    assert.match(card, /<TermosMaisBuscados /);
    assert.match(card, /m\.pedirPalavrasIa\(`titulo_\$\{lt\}`, \{ escolhidos \}\)/);
    assert.match(card, /juntarTermo\(/);
    const painel = lerSemComentarios(`${BASE}/Mesa/TermosMaisBuscados.jsx`);
    assert.match(painel, /t\.relacionado/);
    assert.match(painel, /m\.carregarTermos\(\)/);
    assert.match(painel, /Ver também os que não citam o produto/);
});

test('§4 — Variações: "Nova variação" à vista e EAN-13 automático uma vez por variação', () => {
    const card = lerSemComentarios(`${BASE}/Mesa/CardVariacoes.jsx`);
    assert.match(card, /Nova variação/);
    assert.match(card, /data-acao="adicionar-variacao"/);
    assert.match(card, /variantesSemGtin\(m\.variantes, schema\)/);
    assert.match(card, /gerados\.current\.add\(v\.chave\)/);
    assert.match(lerSemComentarios(`${BASE}/GradeVariantes.jsx`), /gerarEan13\(existentes\)/);
});

test('§5 — Logística: unidades kg/g e cm/mm/m, chip com a forma ESCOLHIDA e frete grátis obrigatório vindo do ML', () => {
    const card = lerSemComentarios(`${BASE}/Mesa/CardLogistica.jsx`);
    assert.match(card, /UNIDADES_PESO/);
    assert.match(card, /UNIDADES_MEDIDA/);
    assert.match(card, /data-modalidade=\{modo\}/);
    assert.doesNotMatch(card, /modos \?\? \[\]\)\.map\(\(modo\) => ENVIOS\[modo\]\)/);
    assert.match(card, /m\.consultarFrete\(\)/);
    assert.match(card, /disabled=\{m\.disabled \|\| freteObrigatorio\}/);
    assert.doesNotMatch(card, /\b79\b/);
});

test('§6 — Ficha técnica: nada recolhido nem rotulado "opcional"; borda âmbar só no obrigatório', () => {
    const card = lerSemComentarios(`${BASE}/Mesa/CardFichaTecnica.jsx`);
    assert.doesNotMatch(card, /opcion/i);
    assert.doesNotMatch(card, /aria-expanded/);
    assert.match(card, /obrigatorio=\{a\.obrigatoriedade === 'REQUIRED'\}/);
    assert.match(card, /titulo="Ficha técnica"/);
    const comum = lerSemComentarios(`${BASE}/Mesa/comum.jsx`);
    assert.match(comum, /preenchido \|\| ! obrigatorio \? 'border-white\/\[0\.08\]' : 'border-amber-400\/50'/);
});

// ── Variações e fotos juntas, como no Mercado Livre (03/10/2026, pedido depois do docx) ──

const EIXOS = [{ chave: 'COLOR', nome: 'Cor', defines_picture: true, customizado: false, valores: [{ chave: 'COLOR=id:1', id: '1', nome: 'Preto' }] }];

test('eixosComValores — acrescenta o valor novo (sem repetir, sem acento/caixa) no formato do PUT', () => {
    assert.deepEqual(eixosComValores(EIXOS, { COLOR: { id: '2', nome: ' Azul ' } }), [
        { chave: 'COLOR', nome: 'Cor', defines_picture: true, valores: [{ id: '1', nome: 'Preto' }, { id: '2', nome: 'Azul' }] },
    ]);
    assert.deepEqual(eixosComValores(EIXOS, { COLOR: { id: null, nome: 'preto' } })[0].valores.length, 1);
    assert.deepEqual(eixosComValores(EIXOS, { COLOR: { nome: '  ' } })[0].valores.length, 1);
});

test('eixosSemValor — tira o valor; o eixo que fica vazio sai (volta ao produto único)', () => {
    const dois = eixosComValores(EIXOS, { COLOR: { id: '2', nome: 'Azul' } });
    assert.deepEqual(eixosSemValor(dois, 'COLOR', 'azul')[0].valores, [{ id: '1', nome: 'Preto' }]);
    assert.deepEqual(eixosSemValor(EIXOS, 'COLOR', 'Preto'), []);
});

test('varianteDoPedido / pedidoCompleto — acha pela combinação de valores, sem caixa', () => {
    const vs = [
        { chave: 'a', valores: { COLOR: { nome: 'Preto' }, SIZE: { nome: 'P' } } },
        { chave: 'b', valores: { COLOR: { nome: 'Azul' }, SIZE: { nome: 'P' } } },
    ];
    assert.equal(varianteDoPedido(vs, { COLOR: { nome: 'azul' }, SIZE: { nome: 'p' } })?.chave, 'b');
    assert.equal(varianteDoPedido(vs, { COLOR: { nome: 'Verde' }, SIZE: { nome: 'P' } }), null);
    assert.equal(varianteDoPedido(vs, {}), null);
    assert.equal(pedidoCompleto(['COLOR', 'SIZE'], { COLOR: { nome: 'Azul' }, SIZE: { nome: '' } }), false);
    assert.equal(pedidoCompleto(['COLOR'], { COLOR: { nome: 'Azul' } }), true);
    assert.equal(pedidoCompleto([], {}), false);
});

test('Fotos dentro de cada variação: o card Fotos sumiu e a lateral leva às variações', () => {
    const cartao = lerSemComentarios(`${BASE}/Mesa/CartaoVariante.jsx`);
    assert.match(cartao, /<BlocoDeFotos grupo=\{grupo\} titulo="Fotos" obrigatorio=\{v\.ativa\}/);
    assert.match(cartao, /onArquivos=\{m\.enviarFotos\}/);
    const card = lerSemComentarios(`${BASE}/Mesa/CardVariacoes.jsx`);
    assert.match(card, /titulo="Variações, fotos e estoque"/);
    assert.match(card, /<NovaVariacao /);
    // Sem eixo que defina a foto, cada variação ganha as próprias fotos.
    assert.match(card, /m\.mudarRasc\(\{ fotos_por_variante: true \}\)/);
    // Tirar com um eixo = remover o valor (órfã com os dados); com mais = desativar.
    assert.match(card, /eixosSemValor\(eixos, eixo\.chave/);
    assert.match(card, /m\.mudarVar\(v\.chave, \{ ativa: false \}\)/);
    assert.match(card, /trazer de volta/);
    const apoio = lerSemComentarios(`${BASE}/apoio.js`);
    assert.match(apoio, /fotos: 'variacoes'/);
});

test('NovaVariacao — a 1ª variação dá nome à que já existe (fica com os dados) e só depois cria a nova', () => {
    const f = lerSemComentarios(`${BASE}/Mesa/NovaVariacao.jsx`);
    assert.match(f, /const passo1 = await m\.salvarEixos\(\[\{ \.\.\.eixo, valores: \[\{ id: atual\.id/);
    assert.match(f, /const passo2 = passo1 && await m\.salvarEixos/);
    // Mais de um eixo: as combinações que nasceram junto e não foram pedidas ficam desativadas.
    assert.match(f, /if \(v\.chave !== pedida\?\.chave && v\.ativa\) m\.mudarVar\(v\.chave, \{ ativa: false \}\)/);
    assert.match(f, /Essa variação já existe\./);
});
