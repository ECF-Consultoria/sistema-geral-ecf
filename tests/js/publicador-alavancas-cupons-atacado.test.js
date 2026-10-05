import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Abas Cupons e Atacado (Fase 166-15). Gates de fonte; tipografia, peso e único
// amarelo por arquivo já são conferidos por publicador-alavancas.test.js.
// ═══════════════════════════════════════════════════════════════════════

const PASTA = 'resources/js/Components/Mlb/Alavancas';

const cupons = lerSemComentarios(`${PASTA}/AbaCupons.jsx`);
const form = lerSemComentarios(`${PASTA}/Cupons/FormCupom.jsx`);
const atacado = lerSemComentarios(`${PASTA}/AbaAtacado.jsx`);
const faixas = lerSemComentarios(`${PASTA}/Atacado/FaixasDoAnuncio.jsx`);
const pagina = lerSemComentarios('resources/js/Pages/Mlb/Publicador/Alavancas.jsx');

test('AbaCupons — lista com saldo, exclui pela janela e cuida dos produtos sem preço', () => {
    assert.match(cupons, /useLeitura\('cupons'/);
    assert.match(cupons, /cupom\.excluir/);
    assert.match(cupons, /saldo/);
    assert.match(cupons, /AdicionarProdutos/);
    assert.match(cupons, /comPreco=\{false\}/);
    assert.match(cupons, /SELLER_COUPON_CAMPAIGN/);
    assert.match(cupons, /Cupom sem produtos não vale para nenhuma venda\./);
    // Cupom programado precisa mostrar os produtos: nada de fixar o status em "started".
    assert.doesNotMatch(cupons, /status:\s*'started'|status="started"/);
});

test('FormCupom — criar/alterar, teto no percentual, código opcional e um só primário', () => {
    assert.match(form, /cupom\.criar/);
    assert.match(form, /cupom\.alterar/);
    assert.match(form, /max_purchase_amount/);
    assert.match(form, /partial_coupon_code/);
    assert.match(form, /O orçamento só aumenta\./);
    assert.equal((form.match(/primario/g) ?? []).length, 1);
});

test('AbaAtacado — sem business mostra só a explicação; seletor de um anúncio só com business', () => {
    assert.match(atacado, /useLeitura\('atacado', conta\)/);
    assert.match(atacado, /dados\?\.business/);
    assert.match(atacado, /dados\?\.explicacao/);
    assert.match(atacado, /maximo=\{1\}/);
    assert.ok(atacado.indexOf('explicacao') < atacado.indexOf('SeletorDeProdutos conta'), 'a explicação vem antes do seletor');
});

test('FaixasDoAnuncio — lista inteira, recomendação, absoluto, limite de 5 e um só primário', () => {
    assert.match(faixas, /atacado\.gravar/);
    assert.match(faixas, /atacado\.recomendacoes/);
    assert.match(faixas, /atacado\.item/);
    assert.match(faixas, /remover_absoluto/);
    assert.match(faixas, /incoerente/);
    assert.match(faixas, /const MAXIMO = 5/);
    assert.match(faixas, /linhas\.length >= MAXIMO/);
    assert.match(faixas, /faixas = linhas\.map/);
    assert.match(faixas, /Recarregar faixas/);
    assert.match(faixas, /O Mercado Livre não aceita essa quantidade\./);
    assert.match(faixas, /O Mercado Livre não tem recomendação para este anúncio/);
    assert.equal((faixas.match(/primario/g) ?? []).length, 1);
    assert.doesNotMatch(faixas, /standard\/quantity/);
});

test('Alavancas.jsx — abas na ordem Promoções, Cupons, Publicidade, Atacado', () => {
    const abas = pagina.slice(pagina.indexOf('const ABAS'), pagina.indexOf('];', pagina.indexOf('const ABAS')));
    const posicoes = ['promocoes', 'cupons', 'publicidade', 'atacado'].map((c) => abas.indexOf(`'${c}'`));
    assert.ok(posicoes.every((p) => p >= 0), 'faltou uma aba');
    assert.deepEqual([...posicoes].sort((a, b) => a - b), posicoes);
});
