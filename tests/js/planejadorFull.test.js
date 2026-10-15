import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import {
    calcular, paraDia, deDia, arredondarMultiplo, porPrioridade, projecaoEstoque, fmtCobertura, fmtDia, CONFIG_PADRAO,
} from '../../resources/js/lib/planejadorFull.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Planejador FULL — motor de cálculo (porta do Planejador_FULL.html).
//
// A conta tem de ser a MESMA do HTML que o time usava com planilha: venda/dia
// pelos 30 dias, posição = apto + pendente + transferência, lead time do envio
// = preparo + agenda + trânsito + recebimento, segurança pela curva ABC.
// A equivalência com o HTML foi conferida sobre os 300+ produtos reais da
// WENUS embutidos nele (15/10/2026); aqui ficam os casos que a sustentam.
// ═══════════════════════════════════════════════════════════════════════

const BASE = '2026-10-15';
const HOJE = paraDia(BASE);

const produto = (over = {}) => ({
    cod: 'AAAA00001', sku: 'SKU-1', titulo: 'Relógio', marca: 'Orient', anuncios: ['MLB1'], status: 'active',
    aptas: 0, transferencia: 0, pendente: 0, vendas: 0, faturamento: 0, ...over,
});

const roda = (produtos, extra = {}) => calcular({ dataBase: BASE, diasVendas: 30, produtos, hoje: HOJE, ...extra });

describe('linha do tempo do envio', () => {
    test('sem data de despacho: base + preparo + agenda; apto após trânsito + recebimento', () => {
        const R = roda([produto({ aptas: 10, vendas: 30, faturamento: 300 })]);
        assert.equal(R.LTfull, 10);
        assert.equal(deDia(R.ship), '2026-10-20');
        assert.equal(deDia(R.arrival), '2026-10-25');
        assert.equal(R.daysA, 10);
    });

    test('data de despacho informada manda no despacho', () => {
        const R = roda([produto({ aptas: 10, vendas: 30 })], { config: { proximoDespacho: '2026-10-17' } });
        assert.equal(deDia(R.ship), '2026-10-17');
        assert.equal(R.daysA, 7);
    });
});

describe('status por produto', () => {
    // 1 un/dia; envio fica apto em 10 dias; segurança curva A = 10 dias.
    const status = (aptas, extra = {}) => roda([produto({ aptas, vendas: 30, faturamento: 3000, ...extra })]).rows[0].status;

    test('sem estoque apto = ruptura, mesmo com mercadoria a caminho', () => {
        assert.equal(status(0, { pendente: 50 }), 'ruptura');
    });
    test('acaba antes do envio chegar = crítico', () => {
        assert.equal(status(5), 'critico');
    });
    test('chega abaixo da segurança = atenção', () => {
        assert.equal(status(15), 'atencao');
    });
    test('cobertura normal = ok', () => {
        assert.equal(status(40), 'ok');
    });
    test('cobertura acima do limite = excesso', () => {
        assert.equal(status(200), 'excesso');
    });
    test('sem venda em 30 dias = sem giro', () => {
        assert.equal(roda([produto({ aptas: 50, vendas: 0 })]).rows[0].status, 'semgiro');
    });
    test('pendente e transferência contam como posição (evita enviar de novo o que já está indo)', () => {
        assert.equal(status(5, { pendente: 40 }), 'ok');
        assert.equal(status(5, { transferencia: 40 }), 'ok');
    });
});

describe('sugestão de envio', () => {
    test('cobre o intervalo entre envios + segurança, descontando o que sobra na chegada', () => {
        // d=1; na chegada sobram 20-10=10; precisa 1*(14+10)=24 → 24-10 = 14.
        const r = roda([produto({ aptas: 20, vendas: 30, faturamento: 3000 })]).rows[0];
        assert.equal(r.sugerido, 14);
        assert.equal(r.qtdFinal, 14);
    });

    test('múltiplo de caixa arredonda para cima', () => {
        const r = roda([produto({ aptas: 20, vendas: 30, faturamento: 3000 })], { skus: { 'SKU-1': { multiplo: 12 } } }).rows[0];
        assert.equal(r.sugerido, 24);
    });

    test('fora do envio zera; quantidade manual vence o sugerido', () => {
        const fora = roda([produto({ aptas: 20, vendas: 30 })], { itens: { AAAA00001: { fora: true } } }).rows[0];
        assert.equal(fora.qtdFinal, 0);
        const manual = roda([produto({ aptas: 20, vendas: 30 })], { itens: { AAAA00001: { qtdManual: 7 } } }).rows[0];
        assert.equal(manual.qtdFinal, 7);
    });

    test('ajuste de demanda e fator sazonal multiplicam a venda/dia', () => {
        const r = roda([produto({ aptas: 20, vendas: 30 })], { itens: { AAAA00001: { ajuste: 0.5 } }, config: { fatorSazonal: 2 } }).rows[0];
        assert.equal(r.d, 3);
    });

    test('galpão informado limita o envio e divide entre Códigos ML do mesmo SKU na proporção', () => {
        const R = roda([
            produto({ cod: 'AAAA00001', aptas: 0, vendas: 60, faturamento: 600 }),
            produto({ cod: 'AAAA00002', aptas: 0, vendas: 30, faturamento: 300 }),
        ], { skus: { 'SKU-1': { galpao: 30 } } });
        const [a, b] = R.rows;
        const soma = a.sugerido + b.sugerido;
        assert.equal(a.podeEnviar, Math.floor((30 * a.sugerido) / soma));
        assert.equal(b.podeEnviar, Math.floor((30 * b.sugerido) / soma));
        assert.ok(a.podeEnviar + b.podeEnviar <= 30);
        assert.equal(a.compartilhado, true);
        assert.equal(a.falta, a.sugerido - a.podeEnviar);
    });

    test('produto sem SKU vira grupo próprio (não se junta a outro sem SKU)', () => {
        const R = roda([
            produto({ cod: 'AAAA00001', sku: null, vendas: 30 }),
            produto({ cod: 'AAAA00002', sku: null, vendas: 30 }),
        ]);
        assert.equal(R.compras.length, 2);
        assert.deepEqual(R.compras.map((c) => c.chaveSku).sort(), ['#AAAA00001', '#AAAA00002']);
    });
});

describe('curva ABC', () => {
    test('A até 80% do faturamento acumulado, B até 95%, C o resto e quem não fatura', () => {
        const R = roda([
            produto({ cod: 'A0000001', sku: 'a', vendas: 30, faturamento: 800 }),
            produto({ cod: 'B0000001', sku: 'b', vendas: 30, faturamento: 150 }),
            produto({ cod: 'C0000001', sku: 'c', vendas: 30, faturamento: 50 }),
            produto({ cod: 'D0000001', sku: 'd', vendas: 0, aptas: 5, faturamento: 0 }),
        ]);
        const abc = Object.fromEntries(R.rows.map((r) => [r.cod, r.abc]));
        assert.deepEqual(abc, { A0000001: 'A', B0000001: 'B', C0000001: 'C', D0000001: 'C' });
    });

    test('empate de faturamento recebe a mesma curva', () => {
        const R = roda([
            produto({ cod: 'A0000001', sku: 'a', vendas: 30, faturamento: 500 }),
            produto({ cod: 'A0000002', sku: 'b', vendas: 30, faturamento: 500 }),
        ]);
        assert.deepEqual(R.rows.map((r) => r.abc), ['A', 'A']);
    });
});

describe('compras com o fornecedor', () => {
    test('pedir até desconta lead time do fornecedor + envio + segurança; comprar cobre também o intervalo', () => {
        // d=1, pos=100, lt fornecedor 15 + envio 10 + seg A 10 → pedir até base + 100 - 35 = base + 65.
        const R = roda([produto({ aptas: 100, vendas: 30, faturamento: 3000 })]);
        const c = R.compras[0];
        assert.equal(c.pedirAte, HOJE + 65);
        assert.equal(c.status, 'ok');
        // 1 * (25 + 30 + 10) - 100 < 0 → nada a comprar.
        assert.equal(c.comprar, 0);
    });

    test('lead time do SKU vence o do fornecedor, que vence o padrão', () => {
        const prods = [produto({ aptas: 10, vendas: 30, faturamento: 3000 })];
        assert.equal(roda(prods).compras[0].lt, CONFIG_PADRAO.leadTimeFornecedor);
        assert.equal(roda(prods, { fornecedores: [{ nome: 'orient', leadTime: 40 }] }).compras[0].lt, 40);
        assert.equal(roda(prods, { fornecedores: [{ nome: 'Orient', leadTime: 40 }], skus: { 'SKU-1': { leadTime: 3 } } }).compras[0].lt, 3);
    });

    test('fornecedor padrão é a marca do anúncio', () => {
        assert.equal(roda([produto({ vendas: 30 })]).compras[0].fornecedor, 'Orient');
        assert.equal(roda([produto({ vendas: 30, marca: null })]).compras[0].fornecedor, 'Outros');
    });

    test('prazo vencido = pedir já; galpão e pedido em aberto entram na posição', () => {
        const ja = roda([produto({ aptas: 5, vendas: 30, faturamento: 3000 })]).compras[0];
        assert.equal(ja.status, 'ja');
        const comGalpao = roda([produto({ aptas: 5, vendas: 30, faturamento: 3000 })], { skus: { 'SKU-1': { galpao: 50, aberto: 50 } } }).compras[0];
        assert.equal(comGalpao.posTotal, 105);
        assert.equal(comGalpao.informado, true);
    });
});

describe('totais e prioridade', () => {
    test('conta itens por status e unidades do envio', () => {
        const R = roda([
            produto({ cod: 'A0000001', sku: 'a', aptas: 0, vendas: 30, faturamento: 300 }),
            produto({ cod: 'B0000001', sku: 'b', aptas: 200, vendas: 30, faturamento: 300 }),
        ]);
        assert.equal(R.totais.ruptura, 1);
        assert.equal(R.totais.excesso, 1);
        assert.equal(R.totais.finalUnidades, R.rows.reduce((a, r) => a + r.qtdFinal, 0));
    });

    test('ruptura/crítico primeiro, depois maior receita/dia', () => {
        const R = roda([
            produto({ cod: 'OK000001', sku: 'a', aptas: 40, vendas: 30, faturamento: 9000 }),
            produto({ cod: 'RU000001', sku: 'b', aptas: 0, vendas: 30, faturamento: 100 }),
            produto({ cod: 'RU000002', sku: 'c', aptas: 0, vendas: 30, faturamento: 900 }),
        ]);
        assert.deepEqual([...R.rows].sort(porPrioridade).map((r) => r.cod), ['RU000002', 'RU000001', 'OK000001']);
    });

    test('produto sem estoque, sem entrada e sem venda fica fora', () => {
        assert.equal(roda([produto({ aptas: 0, vendas: 0 })]).rows.length, 0);
    });
});

describe('projeção e formatos', () => {
    test('com o envio, o estoque volta a subir no dia em que o envio fica apto', () => {
        const R = roda([produto({ aptas: 20, vendas: 30, faturamento: 3000 })]);
        const serie = projecaoEstoque(R.rows[0], R);
        assert.equal(serie[0].sem, 20);
        assert.equal(serie[R.daysA].com, 10 + R.rows[0].qtdFinal);
        assert.equal(serie[R.daysA - 1].com, serie[R.daysA - 1].sem);
    });

    test('múltiplo, cobertura e datas', () => {
        assert.equal(arredondarMultiplo(13, 6), 18);
        assert.equal(arredondarMultiplo(13, 0), 13);
        assert.equal(fmtCobertura(null), 'sem venda');
        assert.equal(fmtCobertura(5000), '999+ d');
        assert.equal(fmtDia(paraDia('2026-10-15'), 'semana'), 'qui 15/10');
        assert.equal(fmtDia(paraDia('2026-10-15'), 'ano'), '15/10/2026');
    });
});

describe('tela: entrada pendente e transferência', () => {
    // Pedido do usuário em 15/10/2026: "Entrada pendente e Em transferência não estão puxando".
    // A transferência vinha da API mas sumia somada na coluna "A caminho"; o pendente fica zerado
    // até haver envio registrado (a API não o entrega). As duas colunas ficam separadas e a tela
    // avisa quando não há envio registrado.
    test('Produtos e Próximo envio mostram pendente e transferência em colunas próprias', () => {
        const produtos = lerSemComentarios('resources/js/Components/PlanejadorFull/Produtos.jsx');
        const envio = lerSemComentarios('resources/js/Components/PlanejadorFull/ProximoEnvio.jsx');
        for (const fonte of [produtos, envio]) {
            assert.ok(fonte.includes('fmtInt(r.pendente)'));
            assert.ok(fonte.includes('fmtInt(r.transferencia)'));
            assert.ok(!fonte.includes('fmtInt(r.aCaminho)'));
        }
        assert.match(produtos, />Entrada pendente</);
        assert.match(produtos, />Em transferência</);
    });

    test('sem envio registrado a tela avisa que a entrada pendente está zerada', () => {
        const show = lerSemComentarios('resources/js/Pages/PlanejadorFull/Show.jsx');
        assert.match(show, /enviosAbertos === 0/);
        assert.match(show, /Entrada pendente zerada/);
    });
});
