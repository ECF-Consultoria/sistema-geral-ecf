import test from 'node:test';
import assert from 'node:assert/strict';
import {
    PRECIF_LINHA_VAZIA,
    CAMPOS_PRECIFICACAO,
    produtosPreenchidos,
    mesclarPrecificacaoComPlanilha,
    criarTesteDaPlanilha,
    agruparFamilias,
    rotuloVariacao,
    contarVariacoes,
    familiaUniforme,
    aplicarNaFamilia,
    replicarPrecificacao,
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

// ═══════════════════════════════════════════════════════════════════════
// Famílias — o caso real: 6 produtos em 8 cores viram 48 linhas planas.
// ═══════════════════════════════════════════════════════════════════════

// Reproduz a planilha que motivou o agrupamento: nome repetido, cor só no sufixo do SKU.
const CORES = ['BGLH', 'CZLH', 'BG', 'BE', 'CZ', 'AZ', 'PT', 'MR'];
const banquetas = ['Banqueta Alta', 'Banqueta Média', 'Banqueta Baixa']
    .flatMap((nome, n) => CORES.map(c => ({ sku: `BLYCE${c}${71 - n * 6}`, produto: nome })));

test('o vinculo de variacao da planilha chega na precificacao', () => {
    const planilha = [{ sku: 'A', produto: 'Banqueta', variacao_grupo: 'g1', variacao_tipo: 'Cor', variacao_valor: 'Azul' }];
    const [row] = mesclarPrecificacaoComPlanilha(planilha, []);
    assert.equal(row.variacao_grupo, 'g1');
    assert.equal(row.variacao_tipo, 'Cor');
    assert.equal(row.variacao_valor, 'Azul');
});

test('a planilha manda no vinculo de variacao, nao a precificacao salva', () => {
    const planilha = [{ sku: 'A', produto: 'Banqueta', variacao_grupo: 'novo' }];
    const salvos   = [linha({ sku: 'A', descricao: 'Banqueta', custo: '40', variacao_grupo: 'antigo' })];
    const [row] = mesclarPrecificacaoComPlanilha(planilha, salvos);
    assert.equal(row.variacao_grupo, 'novo', 'grupo e reflexo da planilha, como SKU e nome');
    assert.equal(row.custo, '40', 'e os numeros salvos continuam vindo da precificacao');
});

test('24 linhas de nome repetido colapsam em 3 familias', () => {
    const rows = mesclarPrecificacaoComPlanilha(banquetas, []);
    const familias = agruparFamilias(rows);

    assert.equal(rows.length, 24);
    assert.equal(familias.length, 3, 'sem grupo preenchido, o nome agrupa');
    assert.deepEqual(familias.map(f => f.nome), ['Banqueta Alta', 'Banqueta Média', 'Banqueta Baixa']);
    assert.deepEqual(familias.map(f => f.idxs.length), [8, 8, 8]);
});

test('familia respeita a ordem da planilha', () => {
    const familias = agruparFamilias([
        { descricao: 'Mesa' }, { descricao: 'Puff' }, { descricao: 'Mesa' },
    ]);
    assert.deepEqual(familias.map(f => f.nome), ['Mesa', 'Puff']);
    assert.deepEqual(familias[0].idxs, [0, 2], 'a linha distante entra na familia ja aberta');
});

test('grupo preenchido tem prioridade sobre o nome', () => {
    // Mesmo nome, grupos diferentes: são dois anúncios, não um.
    const familias = agruparFamilias([
        { descricao: 'Banqueta', variacao_grupo: 'g1' },
        { descricao: 'Banqueta', variacao_grupo: 'g2' },
        { descricao: 'Banqueta', variacao_grupo: 'g1' },
    ]);
    assert.equal(familias.length, 2);
    assert.deepEqual(familias[0].idxs, [0, 2]);
});

test('grupo "azul" nao se mistura com nome "azul"', () => {
    const familias = agruparFamilias([
        { descricao: 'Cadeira', variacao_grupo: 'azul' },
        { descricao: 'azul' },
    ]);
    assert.equal(familias.length, 2, 'os espacos de chave sao separados por prefixo');
});

test('avulsos em branco nunca se fundem num so', () => {
    const familias = agruparFamilias([
        { ...PRECIF_LINHA_VAZIA }, { ...PRECIF_LINHA_VAZIA }, { ...PRECIF_LINHA_VAZIA },
    ]);
    assert.equal(familias.length, 3, 'linha anonima e familia de si mesma');
});

test('agrupar aceita entrada nula e nao muta o que recebeu', () => {
    assert.deepEqual(agruparFamilias(null), []);
    assert.deepEqual(agruparFamilias(undefined), []);
    const rows = [{ descricao: 'Mesa' }];
    const copia = JSON.parse(JSON.stringify(rows));
    agruparFamilias(rows);
    assert.deepEqual(rows, copia);
});

test('rotulo da variacao prefere o valor, cai no SKU, e por fim na posicao', () => {
    assert.equal(rotuloVariacao({ variacao_valor: 'Azul', sku: 'A-1' }, 0), 'Azul');
    assert.equal(rotuloVariacao({ variacao_valor: '  ', sku: 'A-1' }, 0), 'A-1');
    assert.equal(rotuloVariacao({ ...PRECIF_LINHA_VAZIA }, 4), '#5');
    assert.equal(rotuloVariacao(undefined, 0), '#1');
});

test('a contagem de variacoes nao produz "variaçãos" nem "cors"', () => {
    // Foi o que a tela mostrou na primeira rodada: concatenar 's' no eixo.
    assert.equal(contarVariacoes(8), '8 variações');
    assert.equal(contarVariacoes(1), '1 variação');
    assert.equal(contarVariacoes(8, 'Cor'), '8 variações de cor');
    assert.equal(contarVariacoes(1, 'Tamanho'), '1 variação de tamanho');
    assert.equal(contarVariacoes(8, '   '), '8 variações', 'eixo em branco nao vira qualificador');
    assert.equal(contarVariacoes(8, undefined), '8 variações');
    // Nenhum eixo digitado pelo cliente pode gerar plural errado, porque não há flexão.
    ['Cor', 'Voltagem', 'Pé', 'kit'].forEach(e => {
        assert.doesNotMatch(contarVariacoes(8, e), /(ãos|ors|ens\b.*s\b)/);
    });
});

// ─── Uniformidade: é ela que escolhe o modo de edição que abre ───

test('familia toda em branco conta como uniforme', () => {
    const rows = mesclarPrecificacaoComPlanilha(banquetas, []);
    assert.equal(familiaUniforme(rows, [0, 1, 2, 3, 4, 5, 6, 7]), true);
});

test('uniforme compara por numero, nao por texto', () => {
    const rows = [{ custo: '10' }, { custo: '10.00' }, { custo: 10 }];
    assert.equal(familiaUniforme(rows, [0, 1, 2], ['custo']), true);
});

test('vazio so e igual a vazio — nao a zero', () => {
    assert.equal(familiaUniforme([{ custo: '' }, { custo: '0' }], [0, 1], ['custo']), false);
    assert.equal(familiaUniforme([{ custo: '' }, { custo: '  ' }], [0, 1], ['custo']), true);
});

test('um custo diferente ja quebra a uniformidade', () => {
    const rows = [{ custo: '10', frete_classico: '5' }, { custo: '11', frete_classico: '5' }];
    assert.equal(familiaUniforme(rows, [0, 1]), false);
});

test('divergencia em qualquer campo da precificacao quebra a uniformidade', () => {
    CAMPOS_PRECIFICACAO.forEach(campo => {
        const rows = [{ ...PRECIF_LINHA_VAZIA }, { ...PRECIF_LINHA_VAZIA, [campo]: '7' }];
        assert.equal(familiaUniforme(rows, [0, 1]), false, `${campo} deveria contar`);
    });
});

test('SKU e descricao diferentes NAO quebram a uniformidade', () => {
    // São a identidade da variação — se contassem, nenhuma família seria uniforme.
    const rows = [
        { sku: 'A', descricao: 'Banqueta', custo: '10' },
        { sku: 'B', descricao: 'Banqueta', custo: '10' },
    ];
    assert.equal(familiaUniforme(rows, [0, 1]), true);
});

test('familia de uma variacao e sempre uniforme', () => {
    assert.equal(familiaUniforme([{ custo: '10' }], [0]), true);
    assert.equal(familiaUniforme([], []), true);
});

// ─── Aplicar na família / replicar ───

test('aplicar na familia escreve nas 8 e nao encosta nas outras', () => {
    const rows = mesclarPrecificacaoComPlanilha(banquetas, []);
    const familias = agruparFamilias(rows);
    const novo = aplicarNaFamilia(rows, familias[0].idxs, 'custo', '210');

    familias[0].idxs.forEach(i => assert.equal(novo[i].custo, '210'));
    familias[1].idxs.forEach(i => assert.equal(novo[i].custo, '', 'outra familia fica intacta'));
    assert.equal(rows[0].custo, '', 'nao muta a entrada');
});

test('aplicar na familia preserva a identidade de cada variacao', () => {
    const rows = mesclarPrecificacaoComPlanilha(banquetas, []);
    const novo = aplicarNaFamilia(rows, [0, 1], 'custo', '210');
    assert.equal(novo[0].sku, 'BLYCEBGLH71');
    assert.equal(novo[1].sku, 'BLYCECZLH71', 'o SKU nao e arrastado pela edicao em massa');
});

test('replicar copia os numeros da origem e deixa SKU e nome de fora', () => {
    const rows = [
        { sku: 'A', descricao: 'Banqueta', custo: '210', frete_classico: '32', mc_individual: '8' },
        { sku: 'B', descricao: 'Banqueta', custo: '',    frete_classico: '',   mc_individual: '' },
    ];
    const novo = replicarPrecificacao(rows, [0, 1], 0);

    assert.equal(novo[1].custo, '210');
    assert.equal(novo[1].frete_classico, '32');
    assert.equal(novo[1].mc_individual, '8');
    assert.equal(novo[1].sku, 'B', 'identidade preservada');
    assert.equal(novo[0], rows[0], 'a origem nem e recriada');
});

test('replicar apaga o que estava preenchido quando a origem esta vazia', () => {
    // É o comportamento pedido: replicar iguala a família à origem, inclusive esvaziando.
    const rows = [{ custo: '' }, { custo: '999' }];
    const novo = replicarPrecificacao(rows, [0, 1], 0);
    assert.equal(novo[1].custo, '');
});

test('replicar com origem inexistente devolve tudo como estava', () => {
    const rows = [{ custo: '10' }];
    assert.equal(replicarPrecificacao(rows, [0], 5), rows);
    assert.deepEqual(replicarPrecificacao(null, [0], 0), []);
});
