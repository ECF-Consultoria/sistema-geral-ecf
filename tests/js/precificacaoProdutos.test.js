import test from 'node:test';
import assert from 'node:assert/strict';
import {
    PRECIF_LINHA_VAZIA,
    produtosPreenchidos,
    mesclarPrecificacaoComPlanilha,
    criarTesteDaPlanilha,
} from '../../resources/js/lib/precificacaoProdutos.js';

// ═══════════════════════════════════════════════════════════════════════
// Pareamento Planilha de Produtos × Precificação (/implementacao/{token}).
//
// Bug de origem: a chave do merge era só o SKU. O cliente digitou "Não tenho"
// no SKU dos 11 produtos, então os 11 caíram no MESMO registro salvo — todos
// apareciam como "Poltrona Rimini" pelo mesmo preço, e renomear não adiantava
// porque a reabertura do modal remesclava por cima.
// ═══════════════════════════════════════════════════════════════════════

const NOMES = [
    'Poltrona Savage', 'Poltrona Rimini', 'Poltrona Perla Fixa', 'Puff Rimini',
    'Puff Tour', 'Puff Gaya', 'Puff Noa', 'Puff Perla Fixa',
    'Banco Nook', 'Banco Nest', 'Banco Rialto',
];
const planilhaSemSku = NOMES.map(n => ({ sku: 'Não tenho', produto: n }));
const linha = (extra) => ({ ...PRECIF_LINHA_VAZIA, ...extra });
const nomes = (rows) => rows.map(r => r.descricao);

// ─── O caso que originou o fix: SKU repetido em todos os produtos ───

test('SKU repetido em todos os produtos nao colapsa a lista num produto so', () => {
    const rows = mesclarPrecificacaoComPlanilha(planilhaSemSku, []);
    assert.equal(rows.length, 11);
    assert.deepEqual(nomes(rows), NOMES);
});

test('com SKU repetido cada produto guarda o SEU custo ao reabrir', () => {
    const salvos = NOMES.map((n, i) => linha({ sku: 'Não tenho', descricao: n, custo: String(100 + i) }));
    const rows   = mesclarPrecificacaoComPlanilha(planilhaSemSku, salvos);

    assert.deepEqual(nomes(rows), NOMES);
    assert.deepEqual(rows.map(r => r.custo), salvos.map(r => r.custo));
    assert.equal(rows.length, 11, 'nenhuma linha duplicada no fim');
});

test('estado ja colapsado em producao volta a ter os nomes da planilha', () => {
    // 11 linhas idênticas: o que o bug deixou salvo antes do fix.
    const salvos = Array.from({ length: 11 }, () =>
        linha({ sku: 'Não tenho', descricao: 'Poltrona Rimini', custo: '1200' }));
    const rows = mesclarPrecificacaoComPlanilha(planilhaSemSku, salvos);

    assert.deepEqual(nomes(rows), NOMES);
    assert.equal(rows.length, 11, 'sem chips orfaos sobrando');
});

test('a planilha manda no nome — renomear na precificacao nao vira lista divergente', () => {
    const salvos = [linha({ sku: 'Não tenho', descricao: 'nome digitado na precificacao', custo: '50' })];
    const rows   = mesclarPrecificacaoComPlanilha([{ sku: 'Não tenho', produto: 'Poltrona Savage' }], salvos);

    assert.equal(rows.length, 1);
    assert.equal(rows[0].descricao, 'Poltrona Savage');
    assert.equal(rows[0].custo, '50', 'o numero digitado sobrevive');
});

// ─── Produto avulso (criado só no Simulador, fora da planilha) ───

test('produto avulso sobrevive ao merge e fica no fim da lista', () => {
    const salvos = [
        ...NOMES.map((n, i) => linha({ sku: 'Não tenho', descricao: n, custo: String(100 + i) })),
        linha({ sku: '', descricao: 'Item avulso', custo: '77' }),
    ];
    const rows = mesclarPrecificacaoComPlanilha(planilhaSemSku, salvos);

    assert.equal(rows.length, 12);
    assert.equal(rows[11].descricao, 'Item avulso');
    assert.equal(rows[11].custo, '77');
});

test('avulso sem SKU nunca e grudado num produto da planilha', () => {
    // A 3ª passada (por ordem) só aceita linhas com SKU da planilha — o avulso fica de fora.
    const salvos = [linha({ sku: '', descricao: '', custo: '9' })];
    const rows   = mesclarPrecificacaoComPlanilha([{ sku: 'A', produto: 'Mesa' }], salvos);

    assert.equal(rows.length, 2);
    assert.equal(rows[0].descricao, 'Mesa');
    assert.equal(rows[0].custo, '', 'a Mesa nao herdou o custo do avulso');
    assert.equal(rows[1].custo, '9');
});

test('sem planilha a precificacao vive so de avulsos', () => {
    const rows = mesclarPrecificacaoComPlanilha([], [linha({ descricao: 'So no simulador', custo: '5' })]);
    assert.equal(rows.length, 1);
    assert.equal(rows[0].custo, '5');
});

// ─── Variações: mesmo nome, SKUs diferentes (um anúncio, N variações) ───

test('variacoes de mesmo nome mantem cada uma o seu custo', () => {
    const planilha = [
        { sku: 'CAD-AZ', produto: 'Cadeira Gamer' },
        { sku: 'CAD-PR', produto: 'Cadeira Gamer' },
        { sku: 'MESA-1', produto: 'Mesa' },
    ];
    const salvos = [
        linha({ sku: 'CAD-AZ', descricao: 'Cadeira Gamer', custo: '10' }),
        linha({ sku: 'CAD-PR', descricao: 'Cadeira Gamer', custo: '20' }),
        linha({ sku: 'MESA-1', descricao: 'Mesa',          custo: '30' }),
    ];
    assert.deepEqual(mesclarPrecificacaoComPlanilha(planilha, salvos).map(r => r.custo), ['10', '20', '30']);
});

test('variacoes com SKU repetido consomem linhas salvas distintas, em ordem', () => {
    const planilha = [
        { sku: 'Não tenho', produto: 'Cadeira Gamer' },
        { sku: 'Não tenho', produto: 'Cadeira Gamer' },
    ];
    const salvos = [
        linha({ sku: 'Não tenho', descricao: 'Cadeira Gamer', custo: '10' }),
        linha({ sku: 'Não tenho', descricao: 'Cadeira Gamer', custo: '20' }),
    ];
    const rows = mesclarPrecificacaoComPlanilha(planilha, salvos);
    assert.equal(rows.length, 2);
    assert.deepEqual(rows.map(r => r.custo), ['10', '20']);
});

// ─── Reancoragem quando o cliente mexe na planilha depois de precificar ───

test('corrigir o SKU na planilha nao perde o custo (casa pelo nome)', () => {
    const rows = mesclarPrecificacaoComPlanilha(
        [{ sku: 'SKU-NOVO', produto: 'Poltrona Savage' }],
        [linha({ sku: 'Não tenho', descricao: 'Poltrona Savage', custo: '999' })],
    );
    assert.equal(rows.length, 1);
    assert.equal(rows[0].sku, 'SKU-NOVO');
    assert.equal(rows[0].custo, '999');
});

test('renomear na planilha nao perde o custo (casa pela ordem)', () => {
    const rows = mesclarPrecificacaoComPlanilha(
        [{ sku: 'Não tenho', produto: 'Poltrona Savage PLUS' }],
        [linha({ sku: 'Não tenho', descricao: 'Poltrona Savage', custo: '555' })],
    );
    assert.equal(rows.length, 1);
    assert.equal(rows[0].descricao, 'Poltrona Savage PLUS');
    assert.equal(rows[0].custo, '555');
});

test('produto novo na planilha entra sem custo e nao rouba o do vizinho', () => {
    const rows = mesclarPrecificacaoComPlanilha(
        [{ sku: 'A', produto: 'Mesa' }, { sku: 'B', produto: 'Cadeira' }],
        [linha({ sku: 'A', descricao: 'Mesa', custo: '40' })],
    );
    assert.deepEqual(rows.map(r => [r.descricao, r.custo]), [['Mesa', '40'], ['Cadeira', '']]);
});

// ─── Linhas em branco do grid não viram produto ───

test('linha em branco da planilha nao vira produto na precificacao', () => {
    const planilha = [{ sku: 'A', produto: 'Mesa' }, { sku: '', produto: '' }, { sku: '  ', produto: '  ' }];
    assert.equal(produtosPreenchidos(planilha).length, 1);
    assert.equal(mesclarPrecificacaoComPlanilha(planilha, []).length, 1);
});

test('produto da planilha sem SKU ainda aparece na precificacao', () => {
    const rows = mesclarPrecificacaoComPlanilha([{ sku: '', produto: 'Sem código' }], []);
    assert.equal(rows.length, 1);
    assert.equal(rows[0].descricao, 'Sem código');
});

// ─── criarTesteDaPlanilha: quem tem identificação travada no Simulador ───

test('produto da planilha e reconhecido como travado', () => {
    const daPlanilha = criarTesteDaPlanilha(planilhaSemSku);
    assert.equal(daPlanilha({ sku: 'Não tenho', descricao: 'Puff Noa' }), true);
    assert.equal(daPlanilha({ sku: 'NÃO TENHO', descricao: ' puff noa ' }), true, 'compara sem caixa/espaco');
});

test('produto novo em branco nunca nasce travado', () => {
    const daPlanilha = criarTesteDaPlanilha(planilhaSemSku);
    assert.equal(daPlanilha({ ...PRECIF_LINHA_VAZIA }), false);
    assert.equal(daPlanilha({ sku: '', descricao: 'Item avulso' }), false);
    assert.equal(daPlanilha(undefined), false);
});

test('sem planilha nenhum produto fica travado', () => {
    const daPlanilha = criarTesteDaPlanilha([]);
    assert.equal(daPlanilha({ sku: 'A', descricao: 'Mesa' }), false);
});

// ─── Imutabilidade: o merge não pode alterar o que recebeu ───

test('o merge nao muta a planilha nem as linhas salvas', () => {
    const planilha = [{ sku: 'A', produto: 'Mesa' }];
    const salvos   = [linha({ sku: 'A', descricao: 'Mesa', custo: '40' })];
    const copiaP   = JSON.parse(JSON.stringify(planilha));
    const copiaS   = JSON.parse(JSON.stringify(salvos));

    mesclarPrecificacaoComPlanilha(planilha, salvos);

    assert.deepEqual(planilha, copiaP);
    assert.deepEqual(salvos, copiaS);
});

test('merge aceita entradas nulas sem quebrar', () => {
    assert.deepEqual(mesclarPrecificacaoComPlanilha(null, null), []);
    assert.deepEqual(mesclarPrecificacaoComPlanilha(undefined, undefined), []);
});
