import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';
import { somarDias } from '../../resources/js/Components/Mlb/Alavancas/formato.js';

// ═══════════════════════════════════════════════════════════════════════
// Aba Promoções, parte do vendedor (Fase 166-14): produtos da conta, desconto
// individual, campanhas do vendedor, campanhas automáticas e "tirar de todas".
// Gates de fonte; tipografia, peso e único amarelo por arquivo já são conferidos
// por publicador-alavancas.test.js (a pasta entra sozinha).
// ═══════════════════════════════════════════════════════════════════════

const PASTA = 'resources/js/Components/Mlb/Alavancas';

const seletor = lerSemComentarios(`${PASTA}/SeletorDeProdutos.jsx`);
const desconto = lerSemComentarios(`${PASTA}/Promocoes/DescontoIndividual.jsx`);
const campanhas = lerSemComentarios(`${PASTA}/Promocoes/CampanhasDoVendedor.jsx`);
const adicionar = lerSemComentarios(`${PASTA}/Promocoes/AdicionarProdutos.jsx`);
const itens = lerSemComentarios(`${PASTA}/Promocoes/ItensDoConvite.jsx`);
const automaticas = lerSemComentarios(`${PASTA}/Promocoes/CampanhasAutomaticas.jsx`);
const tirar = lerSemComentarios(`${PASTA}/Promocoes/TirarDeTodas.jsx`);
const aba = lerSemComentarios(`${PASTA}/AbaPromocoes.jsx`);

test('SeletorDeProdutos — lê produtos ao vivo com busca e página, mostra aviso, busca local e teto', () => {
    assert.match(seletor, /useLeitura\('produtos', conta, \{ busca, pagina \}\)/);
    assert.match(seletor, /dados\?\.aviso/);
    assert.match(seletor, /busca_local/);
    assert.match(seletor, /Busca feita só nesta página/);
    assert.match(seletor, /Até \{maximo\} produtos por vez\./);
    assert.match(seletor, /setTimeout\([\s\S]*400\)/);
    assert.match(seletor, /motivos/);
    assert.match(seletor, /soElegiveis/);
});

test('SeletorDeProdutos — caixa de seleção opcional e ação por linha', () => {
    assert.match(seletor, /selecionavel = true/);
    assert.match(seletor, /acaoDaLinha/);
});

test('DescontoIndividual — criar e remover pelo contrato real, com datas e Mercado Pontos', () => {
    assert.match(desconto, /desconto\.criar/);
    assert.match(desconto, /desconto\.remover/);
    assert.match(desconto, /produtos\.promocoes/);
    assert.match(desconto, /top_deal_price/);
    assert.match(desconto, /start_date: inicio/);
    assert.match(desconto, /finish_date: fim/);
    assert.match(desconto, /Aumentar o preço do anúncio depois remove o desconto/);
    assert.match(desconto, /promotion_type: 'PRICE_DISCOUNT'/);
});

test('somarDias — conta o fim do desconto sem depender do relógio nem do fuso', () => {
    assert.equal(somarDias('2026-10-05', 13), '2026-10-18');
    assert.equal(somarDias('2026-12-25', 13), '2027-01-07');
    assert.equal(somarDias('2028-02-20', 10), '2028-03-01');
});

test('CampanhasDoVendedor — criar, alterar e excluir; VOLUME com allow_combination; sem subtipo antigo', () => {
    assert.match(campanhas, /campanha\.criar/);
    assert.match(campanhas, /campanha\.alterar/);
    assert.match(campanhas, /campanha\.excluir/);
    assert.match(campanhas, /VOLUME/);
    assert.match(campanhas, /allow_combination/);
    assert.doesNotMatch(campanhas, /FIXED_PERCENTAGE/);
    assert.match(campanhas, /AdicionarProdutos/);
    assert.match(campanhas, /ItensDoConvite/);
});

test('CampanhasDoVendedor — os subtipos do leve mais, pague menos', () => {
    for (const sub of ['BNGM', 'BNSP', 'SPONTH']) assert.match(campanhas, new RegExp(sub));
    assert.match(campanhas, /buy_quantity/);
    assert.match(campanhas, /pay_quantity/);
    assert.match(campanhas, /discount_percentage/);
});

test('CampanhasDoVendedor — o sub_type só vai no leve mais, pague menos', () => {
    // O envio do subtipo fica dentro do `if (volume)` da criação.
    const criacao = campanhas.slice(campanhas.indexOf('if (! alterando)'), campanhas.indexOf('// Só vai o que mudou.'));
    assert.match(criacao, /if \(volume\) \{[\s\S]*sub_type/);
    assert.doesNotMatch(criacao.replace(/if \(volume\) \{[\s\S]*?\n {12}\}/, ''), /sub_type/);
});

test('CampanhasDoVendedor — não fixa a situação em "ativos": os programados aparecem; ItensDoConvite filtra', () => {
    assert.doesNotMatch(campanhas, /status:\s*'started'|status="started"/);
    assert.match(itens, /'pending'/);
    assert.match(itens, /Programados/);
});

test('AdicionarProdutos — inscreve pelos produtos escolhidos; o preço só com comPreco', () => {
    assert.match(adicionar, /convite\.inscrever/);
    assert.match(adicionar, /comPreco/);
    assert.match(adicionar, /deal_price/);
    assert.match(adicionar, /SeletorDeProdutos/);
});

test('ItensDoConvite — tirar o preço do Mercado Pontos manda remove_loyalty', () => {
    assert.match(itens, /remove_loyalty: true/);
    assert.match(itens, /Tirar o preço do Mercado Pontos/);
    assert.match(itens, /Campanha iniciada: o preço só pode baixar\./);
});

test('CampanhasAutomaticas — conta e produto, sempre pela janela, sem amarelo próprio', () => {
    assert.match(automaticas, /exclusao\.conta/);
    assert.match(automaticas, /exclusao\.item/);
    assert.match(automaticas, /ModalConfirmacao/);
    assert.doesNotMatch(automaticas, /primario/);
});

test('TirarDeTodas — pela janela de confirmação, sem amarelo próprio', () => {
    assert.match(tirar, /convite\.remover_todas/);
    assert.match(tirar, /ModalConfirmacao/);
    assert.doesNotMatch(tirar, /primario/);
});

test('CampanhasDoVendedor — um só amarelo no arquivo', () => {
    assert.equal((campanhas.match(/primario/g) ?? []).length, 1);
});

test('AbaPromocoes — as cinco seções na ordem pedida, cada uma só montada quando aberta', () => {
    const titulos = ['Central de Promoções', 'Desconto individual', 'Campanhas do vendedor', 'Campanhas automáticas', 'Produtos da conta'];
    const posicoes = titulos.map((t) => aba.indexOf(`titulo="${t}"`));
    assert.ok(posicoes.every((p) => p >= 0), `seção ausente: ${posicoes}`);
    assert.deepEqual([...posicoes].sort((a, b) => a - b), posicoes);
    assert.match(aba, /TirarDeTodas/);
    assert.match(aba, /selecionavel=\{false\}/);
    assert.match(aba, /acaoDaLinha=/);
    assert.match(aba, /aberta === 'desconto-individual' &&/);
});
