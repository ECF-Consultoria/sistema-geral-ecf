import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';
import {
    cartoesDasFamilias, composicao, contagem, dadosParaGravar, diferencasDaReferencia, faixaDoCartao, mudouDoGravado, paraNumero, pendentesPrimeiro, porPrioridade,
    primeiraParaAbrir, proximaPendente, rotuloDoMembro, valorComum,
} from '../../resources/js/lib/precificacaoSimulador.js';
import { cartoesDaFamilia, montarFamilias } from '../../resources/js/lib/precificacaoPorFamilia.js';

// ═══════════════════════════════════════════════════════════════════════════
// Simulador da Precificação do Portal (20/10/2026).
//
// O usuário: a Precificação "igual à do onboarding de Polos", também dentro da
// ficha do produto, com "os que já foram precificados mais ocultos e os que
// faltam em prioridade".
//
// 21/10/2026 — na ficha, "muito grande e aparece precificação de outros
// produtos": virou uma lista enxuta, só das variações do produto, e com mais de
// uma variação o padrão é precificar TODAS de uma vez.
//
// Modos de falha que estes testes impedem: o pronto aparecer na frente do que
// falta; o simulador abrir numa oferta já pronta havendo pendente; a tela refazer
// a conta (o preço e a repartição vêm do servidor); a ficha gravar o custo da
// oferta ligada pela rota da Precificação (ele mora na variação); a ficha voltar
// ao simulador grande; o modo "Todas" deixar de ser o padrão.
// ═══════════════════════════════════════════════════════════════════════════

const oferta = (id, sku, fase, mais = {}) => ({ id, sku, nome: `Nome de ${sku}`, fase, componentes: [], ...mais });
const SEM_EXCECAO = { comissao_classico: null, comissao_premium: null, imposto: null, margem_contribuicao: null, lucro_liquido: null };
const PERC = { imposto: 7, margem_contribuicao: 6, lucro_liquido: 6, acrescimo: 20 };
const tipo = (minimo, mais = {}) => ({
    minimo, anunciado: minimo === null ? null : Math.round(minimo * 120) / 100, comissao: 11.5, frete: 20, frete_origem: 'digitado', frete_sugerido: null,
    sem_frete: false, impossivel: false,
    partes: minimo === null ? null : { custo: 100, frete: 20, comissao: 19.86, imposto: 12.09, margem_contribuicao: 10.36, lucro_liquido: 10.36 },
    ...mais,
});
const calculo = (custo, pendencia = null, mais = {}) => ({
    custo: { valor: custo, origem: custo === null ? null : 'digitado', calculado: null },
    frete_classico: 20, frete_premium: 20, do_produto: false, pendencia, excecoes: SEM_EXCECAO, percentuais: PERC,
    classico: tipo(pendencia ? null : 172.66), premium: tipo(pendencia ? null : 193.8),
    ...mais,
});

const CAD = oferta(1, 'CAD-01', 'simples', { variacao_id: 11 });
const CB2 = oferta(2, 'CAD-01-CB2', 'combo', { componentes: [{ id: 1, sku: 'CAD-01', quantidade: 2 }] });
const MESA = oferta(4, 'MSA-MR', 'simples', { variacao_id: 14 });
const KIT = oferta(5, 'KIT-1', 'kit', { componentes: [{ id: 4, sku: 'MSA-MR', quantidade: 1 }, { id: 1, sku: 'CAD-01', quantidade: 1 }] });

const ITENS = [
    { oferta: CAD, calculo: calculo(100) },
    { oferta: CB2, calculo: calculo(200, 'sem_frete', { classico: tipo(250, { sem_frete: true }) }) },
    { oferta: MESA, calculo: calculo(300) },
    { oferta: KIT, calculo: calculo(null, 'sem_custo') },
];

// Os cartões como a página os monta (`cartoesDaFamilia`): a unidade e os combos dela juntos; cada conjunto no seu.
const CARTOES = [
    { chave: 'o-1', membros: [ITENS[0], ITENS[1]] },
    { chave: 'o-4', membros: [ITENS[2]] },
    { chave: 'o-5', membros: [ITENS[3]] },
];
const umCartao = (item) => [{ chave: `o-${item.oferta.id}`, membros: [item] }];

test('rótulos, e a lista da ficha: sem preço primeiro, na ordem em que vieram', () => {
    assert.equal(rotuloDoMembro(CAD), 'Unidade');
    assert.equal(rotuloDoMembro(CB2), 'Combo 2');
    assert.equal(rotuloDoMembro(KIT), 'Kit');
    assert.deepEqual(pendentesPrimeiro(ITENS).map((i) => i.oferta.id), [2, 5, 1, 4]);
    assert.deepEqual(pendentesPrimeiro(null), []);
});

test('o que cada variação tem de diferente da primeira: custo do formulário, fretes e percentuais', () => {
    const variacao = (id, mais = {}) => ({ oferta: oferta(id, `V-${id}`, 'simples', { variacao_id: id }), calculo: calculo(100, null, mais) });
    const preta = variacao(1);
    const bege = variacao(2);
    const verde = variacao(3, { excecoes: { ...SEM_EXCECAO, imposto: 12 } });
    const azul = variacao(4, { frete_premium: 35 });
    const custos = { 1: '180,00', 2: '180', 3: '190,00', 4: '180,00' };
    const custoDe = (i) => custos[i.oferta.id];

    const d = diferencasDaReferencia([verde, preta, bege, azul], preta, custoDe);
    // 21/10: "coloco o custo para todas e não precifica igual" — a Verde tinha imposto e custo próprios.
    assert.deepEqual(d.map((x) => [x.item.oferta.id, x.campos]), [[3, ['custo', 'imposto']], [4, ['frete']]]);
    assert.deepEqual(diferencasDaReferencia([preta, bege], preta, custoDe), []);
    assert.deepEqual(diferencasDaReferencia([preta], null, custoDe), []);
});

test('valor em comum das variações: "180", "180,00" e 180 são o mesmo; vazio só é igual a vazio', () => {
    assert.equal(valorComum(['180,00', '180', 180]), '180');
    assert.equal(valorComum(['180,00', '190,00']), null);
    assert.equal(valorComum(['', null, undefined]), '');
    assert.equal(valorComum(['', '0']), null);
    assert.equal(valorComum([24.9, '24,90']), '24.9');
    assert.equal(valorComum([]), null);
});

test('prioridade: o que falta vem primeiro, o pronto fica para o fim, e abre na primeira que falta', () => {
    const cartoes = CARTOES;
    const { faltam, prontos } = porPrioridade(cartoes);
    assert.deepEqual(faltam.map((c) => c.chave), ['o-1', 'o-5']);
    assert.deepEqual(prontos.map((c) => c.chave), ['o-4']);
    // O cartão da cadeira tem pendência (o combo), e abre NO combo, não na unidade que já está pronta.
    assert.equal(primeiraParaAbrir(cartoes), 2);
    assert.equal(proximaPendente(cartoes, 2), 5);
    assert.equal(proximaPendente(cartoes, 5), 2);
    assert.deepEqual(contagem(cartoes), { total: 4, faltam: 2, prontas: 2 });
    // Tudo pronto: abre na primeira e não há próxima.
    const prontas = [...umCartao(ITENS[0]), ...umCartao(ITENS[2])];
    assert.equal(primeiraParaAbrir(prontas), 1);
    assert.equal(proximaPendente(prontas, 1), null);
});

test('a faixa do cartão usa só o preço do que está pronto; a composição vem das partes do servidor', () => {
    const [cadeira, , kit] = CARTOES;
    assert.deepEqual(faixaDoCartao(cadeira, 'classico'), { min: 172.66, max: 172.66 });
    assert.equal(faixaDoCartao(kit, 'classico'), null);
    const fatias = composicao(ITENS[0].calculo.classico.partes);
    assert.deepEqual(fatias.map((f) => f.chave), ['custo', 'frete', 'comissao', 'imposto', 'margem_contribuicao', 'lucro_liquido']);
    assert.equal(fatias[2].valor, 19.86);
    assert.deepEqual(composicao(null), []);
});

test('o que se grava: a linha inteira; a ligada não manda custo; "12,50" vira 12.50; sem mudança não grava', () => {
    assert.equal(paraNumero('1.234,50'), '1234.50');
    assert.equal(paraNumero(' R$ 12,5 '), '12.5');
    assert.equal(paraNumero(''), '');
    const ligada = dadosParaGravar({ doProduto: true, custo: '99', freteClassico: '20', fretePremium: '', excecoes: { imposto: '9' } });
    assert.equal('custo' in ligada, false);
    assert.deepEqual(ligada, { frete_classico: '20', frete_premium: '', comissao_classico: '', comissao_premium: '', imposto: '9', margem_contribuicao: '', lucro_liquido: '' });
    const avulsa = dadosParaGravar({ doProduto: false, custo: '50,5', freteClassico: '', fretePremium: '', excecoes: {} });
    assert.equal(avulsa.custo, '50.5');
    assert.equal(mudouDoGravado({ frete_classico: '20.0', custo: '' }, { frete_classico: 20, custo: null }), false);
    assert.equal(mudouDoGravado({ frete_classico: '21' }, { frete_classico: 20 }), true);
});

test('os cartões da página saem das famílias que ela já monta, com a conta de cada oferta', () => {
    const blocos = [{
        chave: 1, familia: { id: 3, nome: 'Sala' },
        principal: { id: 1, sku: 'CAD-01', nome: 'Cadeira', fase: 'simples' },
        tambem_em: [], ofertas: [CAD, CB2],
    }];
    const porOferta = { 1: ITENS[0].calculo, 2: ITENS[1].calculo };
    const cartoes = cartoesDasFamilias(montarFamilias(blocos, (id) => !! porOferta[id]), porOferta, cartoesDaFamilia);
    assert.equal(cartoes.length, 1);
    assert.deepEqual(cartoes[0].membros.map((m) => m.oferta.id), [1, 2]);
    assert.equal(cartoes[0].familia, 'Sala');
});

// ─── O simulador, desenhado de verdade ───

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
global.route = (nome, params) => '/' + nome + (params !== undefined && params !== null && typeof params !== 'object' ? `/${params}` : '');

const temporarios = [];
after(() => { for (const f of temporarios) fs.rmSync(f, { force: true }); });

async function montar(caminho) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, caminho)],
        bundle: true, format: 'esm', platform: 'node', jsx: 'automatic', write: false, logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js') },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@inertiajs/react'],
    });
    const arquivo = path.join(__dirname, `.precificacao-simulador-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(arquivo, resultado.outputFiles[0].text, 'utf8');
    temporarios.push(arquivo);

    return (await import(pathToFileURL(arquivo).href)).default;
}

const parametros = { comissao_classico: 11.5, comissao_premium: 16.5, imposto: 7, margem_contribuicao: 6, lucro_liquido: 6, acrescimo: 20 };

test('o simulador: o que falta na frente, o pronto recolhido, e o painel na primeira que falta', async (t) => {
    const Simulador = await montar('resources/js/Components/Portal/Estrutura/Precificacao/SimuladorPrecificacao.jsx');
    const html = renderToStaticMarkup(React.createElement(Simulador, { cartoes: CARTOES, parametros, onSalvar: async () => null }));

    await t.test('os cartões pendentes vêm no catálogo de cima; o pronto fica recolhido em "Já precificados"', () => {
        assert.match(html, /data-catalogo="faltam"/);
        assert.equal(html.includes('data-catalogo="prontos"'), false, 'o pronto nasce recolhido');
        assert.match(html, /Já precificados \(<!-- -->1<!-- -->\)|Já precificados \(1\)/);
        assert.match(html, /Faltam preço/);
        const ordem = [...html.matchAll(/data-cartao-simulador="([^"]+)"/g)].map((m) => m[1]);
        assert.deepEqual(ordem, ['o-1', 'o-5']);
    });

    await t.test('abre no combo que falta frete: o campo do frete pisca e o preço aparece apagado, sem o frete', () => {
        assert.match(html, /data-entrada="2"/);
        assert.match(html, /data-resultado="2"/);
        assert.match(html, /data-situacao="sem_frete"/);
        assert.match(html, /animate-blink-red/);
        assert.match(html, /Ainda sem o frete/);
        assert.match(html, /data-acao="proxima-pendente"/);
    });

    await t.test('pronto: preço em destaque, o de anunciar, para onde vai cada real, lucro e margem — tudo do servidor', () => {
        const pronto = renderToStaticMarkup(React.createElement(Simulador, { cartoes: umCartao(ITENS[0]), parametros, onSalvar: async () => null }));
        assert.match(pronto, /data-situacao="pronto"/);
        assert.match(pronto, /data-preco-sugerido[^>]*>R\$\s172,66</);
        assert.match(pronto, /data-preco-anunciar="true">R\$\s207,19</);
        assert.match(pronto, /data-composicao-preco/);
        assert.match(pronto, /Comissão do site/);
        assert.match(pronto, /R\$\s19,86/);
        assert.match(pronto, /Tudo com preço/);
        assert.equal(pronto.includes('data-acao="proxima-pendente"'), false);
    });

    await t.test('ligada na página: o custo é só leitura, com o caminho para o produto', () => {
        const ligada = { oferta: CAD, calculo: calculo(100, 'sem_frete', { do_produto: true, custo: { valor: 100, origem: 'produto', calculado: null } }) };
        const h = renderToStaticMarkup(React.createElement(Simulador, { cartoes: umCartao(ligada), parametros, onSalvar: async () => null }));
        assert.match(h, /data-custo-do-produto/);
        assert.match(h, /data-acao="alterar-no-produtos"/);
        assert.equal(h.includes('data-campo="custo"'), false);
    });
});

test('a fiação: a página abre no Simulador, a ficha tem a seção, e nenhum dos dois refaz a conta', () => {
    const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaPrecificacao.jsx');
    assert.match(pagina, /useState\(\(\) => filtros\.vista \?\? vistaGuardada\(\) \?\? 'simulador'\)/);
    assert.match(pagina, /<SimuladorPrecificacao cartoes=\{cartoesDoSimulador\}/);

    const ficha = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutoFicha.jsx');
    assert.match(ficha, /<PrecificacaoDoProduto ficha=\{ficha\}/);
    // 29/10/2026 (ficha reorganizada, protótipo aprovado): a Precificação fecha a ficha, depois da Ficha
    // técnica e da Descrição — o custo vem das variações e o frete das caixas, preenchidos antes.
    assert.ok(ficha.indexOf('<PrecificacaoDoProduto') > ficha.indexOf('<CoresDoProduto'));
    assert.ok(ficha.indexOf('<PrecificacaoDoProduto') > ficha.indexOf('<FichaTecnica'));
    assert.match(ficha, /id="secao-preco"/, 'o índice leva até ela');

    const secao = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/PrecificacaoDoProduto.jsx');
    assert.match(secao, /portal\.auth\.estrutura\.produtos\.precificacao/);
    // Grava por JSON, sem recarregar a página da ficha (o formulário está aberto).
    assert.match(secao, /axios\.put\(\s*route\('portal\.auth\.estrutura\.precificacao\.oferta'/);
    assert.equal(/router\.(put|post|visit|reload)/.test(secao), false);
    // 21/10: só as variações do produto, no simulador do onboarding (sem a grade de cartões), com
    // percentuais e "para onde vai cada real" à vista; e "Todas" é o padrão com mais de uma variação.
    assert.equal(/<SimuladorPrecificacao/.test(secao), false);
    assert.match(secao, /<ResultadoDaOferta /);
    for (const campo of [
        "percentual('Comissão %', `comissao_${tipo}`)",
        "percentual('Imposto %', 'imposto')",
        "percentual('Margem Contrib. %', 'margem_contribuicao')",
        "percentual('Lucro Líquido %', 'lucro_liquido')",
    ]) {
        assert.ok(secao.includes(campo), `falta o campo ${campo}`);
    }
    assert.match(secao, /useState\('todas'\)/);
    assert.match(secao, /const emMassa = varias && modo === 'todas'/);
    // "Todas" = TODAS IGUAIS (21/10): qualquer mudança grava o conjunto inteiro da referência em todas...
    assert.match(secao, /gravar\(itens, \{ \.\.\.gravadoComoTexto\(referencia\.calculo\), \.\.\.mudanca \}\)/);
    assert.match(secao, /onGravar: \(mudanca\) => unificar\(mudanca\)/);
    // ...e mexer no custo de todas também iguala o resto, senão o preço não sai igual.
    assert.match(secao, /onCusto: \(texto\) => \{ custoMexido\.current = true; ficha\.vars\.forEach\(\(v\) => ficha\.alterar\(v\._k, 'custo', texto\)\); \}/);
    assert.match(secao, /onCustoBlur: \(\) => \{ if \(custoMexido\.current\) \{ custoMexido\.current = false; unificar\(\); \} \}/);
    assert.match(secao, /data-acao="deixar-todas-iguais"/);

    // A conta é do servidor: nenhuma das três peças divide por (1 − percentuais).
    const simulador = lerSemComentarios('resources/js/Components/Portal/Estrutura/Precificacao/SimuladorPrecificacao.jsx');
    for (const [nome, fonte] of [['simulador', simulador], ['secao', secao], ['lib', lerSemComentarios('resources/js/lib/precificacaoSimulador.js')]]) {
        assert.equal(/1\s*-\s*\(?\s*comiss/i.test(fonte) || /\/\s*divisor/.test(fonte), false, `${nome} refaz a conta do preço`);
    }
});

// 21/10/2026 — "só atualiza o frete quando salva o produto" / "já deveria aparecer aqui e não salvar para depois
// voltar": a caixa digitada entra na prévia, e o produto novo tem preço antes do primeiro "Salvar produto".
test('a ficha calcula com a caixa digitada e o produto novo tem preço antes de salvar', () => {
    const secao = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/PrecificacaoDoProduto.jsx');
    // A prévia leva as caixas digitadas ("CxLxAxKG|…"), além do custo.
    assert.match(secao, /params: \{ custos, caixas \}/);
    assert.match(secao, /r\[chaveDe\(v\)\] = medidas\.map\(\(m\) => m\.join\('x'\)\)\.join\('\|'\)/);
    // O produto novo vai à prévia sem produto, pela chave da variação na tela, e não grava frete nem percentual.
    assert.match(secao, /novo \? route\('portal\.auth\.estrutura\.produtos\.precificacao\.previa'\)/);
    assert.match(secao, /const chaveDe = \(v\) => \(novo \? v\._k : v\.id\)/);
    assert.match(secao, /const gravar = \(alvos, mudanca\) => \(novo \? undefined : salvarVarias/);
    assert.match(secao, /bloqueado=\{novo\}/);
    assert.equal(secao.includes('Salve o produto para ver o preço'), false, 'o produto novo não pede mais para salvar antes');

    // A faixa da variação diz que os números dela são da caixa salva, e que a Precificação já usa a digitada.
    const faixa = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/FaixaCalculados.jsx');
    assert.match(faixa, /data-caixa-mexida/);
});
