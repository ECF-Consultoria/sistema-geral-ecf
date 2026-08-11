import test from 'node:test';
import assert from 'node:assert/strict';
import {
    MOTIVO_LABEL,
    motivoLabel,
    statusEmpresaLabel,
    fmtPctAbs,
    fmtPp,
    fmtMargemAntesDepois,
    fraseVarMargemPp,
    ehPlaceholderShopee,
    dividirPorEntrada,
    carteiraTodaShopeeNaEntrada,
    deveColapsarNaoEntraram,
    LIMIAR_COLAPSO_NAO_ENTRARAM,
    marketplaceLabel,
    composicaoPorMarketplace,
} from '../../resources/js/lib/desempenhoLabels.js';

// ═══════════════════════════════════════════════════════════════════════
// Fase 123 Plano 01 (Task 2) — vocabulário pt-BR, formatadores e regras
// puras de particionamento das três telas de desempenho/bonificação.
// ═══════════════════════════════════════════════════════════════════════

// ─── fmtMargemAntesDepois — exemplo canônico do CONTEXT ───

test('fmtMargemAntesDepois trava o formato antes -> depois + variacao', () => {
    assert.equal(fmtMargemAntesDepois(14.1, 12.0, -2.1), '14,1% → 12,0%  −2,1');
});

test('fmtMargemAntesDepois so com pp quando os absolutos sao nulos', () => {
    assert.equal(fmtMargemAntesDepois(null, null, -1.5), '−1,5');
});

test('fmtMargemAntesDepois vira travessao quando tudo e nulo', () => {
    assert.equal(fmtMargemAntesDepois(null, null, null), '—');
});

// ─── MOTIVO_LABEL — 6 chaves com o texto exato ───

test('MOTIVO_LABEL tem exatamente os 6 slugs fechados com o texto exato', () => {
    assert.deepEqual(MOTIVO_LABEL, {
        sem_fonte_financeira:     'Sem fonte financeira vinculada',
        faturamento_sem_baseline: 'Sem mês anterior para comparar o faturamento',
        margem_pp_indisponivel:   'Sem dado de margem da Adman neste mês',
        nps_nao_elegivel:         'Empresa não elegível para NPS neste mês',
        nps_janela_aberta:        'Janela de coleta de NPS ainda aberta',
        nps_indisponivel:         'Sem dado de NPS disponível',
    });
    assert.equal(Object.keys(MOTIVO_LABEL).length, 6);
});

// ─── Fallback para slug desconhecido ───

test('motivoLabel cai no fallback legivel para um 7o motivo desconhecido', () => {
    assert.equal(motivoLabel('motivo_futuro_qualquer'), 'motivo futuro qualquer');
});

test('statusEmpresaLabel cai no proprio slug quando o status e novo', () => {
    assert.equal(statusEmpresaLabel('status_novo'), 'status_novo');
});

// ─── Formatadores aceitam vazio ───

test('fmtPp(null) e fmtPctAbs(undefined) devolvem travessao', () => {
    assert.equal(fmtPp(null), '—');
    assert.equal(fmtPctAbs(undefined), '—');
});

test('fmtPp aplica o sinal correto (+ positivo, MINUS SIGN negativo)', () => {
    assert.equal(fmtPp(-2.1), '−2,1');
    assert.equal(fmtPp(3.2), '+3,2');
    assert.equal(fmtPp(0), '0,0');
});

// ─── fraseVarMargemPp — as tres saidas ───

test('fraseVarMargemPp positivo plural', () => {
    assert.equal(fraseVarMargemPp(3.2), 'A margem subiu 3,2 pontos percentuais na média da carteira.');
});

test('fraseVarMargemPp negativo singular (1,0 ponto)', () => {
    assert.equal(fraseVarMargemPp(-1), 'A margem caiu 1,0 ponto percentual na média da carteira.');
});

test('fraseVarMargemPp null devolve null', () => {
    assert.equal(fraseVarMargemPp(null), null);
});

test('fraseVarMargemPp estavel quando arredonda para 0,0', () => {
    assert.equal(fraseVarMargemPp(0), 'A margem ficou estável na média da carteira.');
});

// ─── ehPlaceholderShopee — tres casos ───

test('ehPlaceholderShopee nos tres casos: com o campo, sem o campo, undefined', () => {
    assert.equal(ehPlaceholderShopee({ quality: { margin_source: 'placeholder_shopee' } }), true);
    assert.equal(ehPlaceholderShopee({ quality: { margin_source: null } }), false);
    assert.equal(ehPlaceholderShopee(undefined), false);
});

// ─── dividirPorEntrada ───

test('dividirPorEntrada separa complete de partial preservando a ordem', () => {
    const linhas = [
        { company_id: 1, status: 'complete' },
        { company_id: 2, status: 'partial' },
        { company_id: 3, status: 'partial' },
    ];
    const { entraram, naoEntraram } = dividirPorEntrada(linhas);
    assert.equal(entraram.length, 1);
    assert.equal(naoEntraram.length, 2);
    assert.equal(entraram[0].company_id, 1);
    assert.deepEqual(naoEntraram.map((l) => l.company_id), [2, 3]);
});

test('dividirPorEntrada aceita [], null e undefined', () => {
    assert.deepEqual(dividirPorEntrada([]), { entraram: [], naoEntraram: [] });
    assert.deepEqual(dividirPorEntrada(null), { entraram: [], naoEntraram: [] });
    assert.deepEqual(dividirPorEntrada(undefined), { entraram: [], naoEntraram: [] });
});

test('dividirPorEntrada preserva a ordem de entrada dentro do grupo entraram', () => {
    const linhas = [
        { company_id: 30, status: 'complete' },
        { company_id: 10, status: 'complete' },
        { company_id: 20, status: 'complete' },
    ];
    const { entraram } = dividirPorEntrada(linhas);
    assert.deepEqual(entraram.map((l) => l.company_id), [30, 10, 20]);
});

// ─── Caso Felipe — margem sobre 3 de 30 empresas ───

test('caso Felipe: 30 linhas com 3 complete produz denominador pequeno e colapso ligado', () => {
    const linhas = [
        ...Array.from({ length: 3 }, (_, i) => ({ company_id: i, status: 'complete' })),
        ...Array.from({ length: 27 }, (_, i) => ({ company_id: 100 + i, status: 'partial' })),
    ];
    const { entraram, naoEntraram } = dividirPorEntrada(linhas);
    assert.equal(entraram.length, 3);
    assert.equal(naoEntraram.length, 27);
    assert.equal(deveColapsarNaoEntraram(naoEntraram.length), true);
});

// ─── Caso Matheus Estrela — carteira toda-Shopee ───

test('caso Matheus Estrela: carteira toda-Shopee ativa o aviso agregado', () => {
    const entraram = Array.from({ length: 4 }, (_, i) => ({
        company_id: i,
        status: 'complete',
        quality: { margin_source: 'placeholder_shopee' },
    }));
    assert.equal(carteiraTodaShopeeNaEntrada(entraram), true);

    const comUmaFora = [...entraram];
    comUmaFora[0] = { ...comUmaFora[0], quality: { margin_source: null } };
    assert.equal(carteiraTodaShopeeNaEntrada(comUmaFora), false);

    assert.equal(carteiraTodaShopeeNaEntrada([]), false);
});

// ─── deveColapsarNaoEntraram — fronteira ───

test('deveColapsarNaoEntraram na fronteira do limiar', () => {
    assert.equal(LIMIAR_COLAPSO_NAO_ENTRARAM, 8);
    assert.equal(deveColapsarNaoEntraram(8), false);
    assert.equal(deveColapsarNaoEntraram(9), true);
    assert.equal(deveColapsarNaoEntraram(null), false);
});

// ═══════════════════════════════════════════════════════════════════════
// quick 260810-n5b — marketplace da conta no Desempenho
// ═══════════════════════════════════════════════════════════════════════

// ─── marketplaceLabel — a tradução que tira o jargão da tela ───

test('marketplaceLabel traduz a fonte financeira para nome de marketplace', () => {
    assert.equal(marketplaceLabel('adman'), 'Mercado Livre');
    assert.equal(marketplaceLabel('shopee'), 'Shopee');
});

test('marketplaceLabel devolve null quando nao ha fonte financeira', () => {
    // Setor polos/publicacao: a empresa nao tem marketplace nesta tela, e a
    // chamadora decide se omite. Nunca 'null' escrito na interface.
    assert.equal(marketplaceLabel(null), null);
    assert.equal(marketplaceLabel(undefined), null);
    assert.equal(marketplaceLabel(''), null);
});

test('marketplaceLabel nunca devolve undefined nem slug cru para fonte nova', () => {
    // Uma 4a plataforma que apareca no futuro cai aqui — legivel, nunca
    // 'undefined' na tela e nunca o slug em minusculo.
    assert.equal(marketplaceLabel('amazon'), 'Amazon');
});

// ─── composicaoPorMarketplace — contagem da faixa da tela do profissional ───

test('composicaoPorMarketplace conta por marketplace, do maior para o menor', () => {
    const linhas = [
        { fonte_financeira: 'adman' },
        { fonte_financeira: 'shopee' },
        { fonte_financeira: 'adman' },
        { fonte_financeira: 'adman' },
    ];

    assert.deepEqual(composicaoPorMarketplace(linhas), [
        { label: 'Mercado Livre', total: 3 },
        { label: 'Shopee', total: 1 },
    ]);
});

test('composicaoPorMarketplace ignora empresa sem fonte financeira', () => {
    // Loja de Polos/Publicacao nao tem marketplace — inventar um balde
    // "sem marketplace" competindo com os reais confunde mais que informa.
    const linhas = [
        { fonte_financeira: 'adman' },
        { fonte_financeira: null },
        { fonte_financeira: null },
    ];

    assert.deepEqual(composicaoPorMarketplace(linhas), [
        { label: 'Mercado Livre', total: 1 },
    ]);
});

test('composicaoPorMarketplace desempata por nome para a ordem nao oscilar', () => {
    const linhas = [
        { fonte_financeira: 'shopee' },
        { fonte_financeira: 'adman' },
    ];

    assert.deepEqual(composicaoPorMarketplace(linhas), [
        { label: 'Mercado Livre', total: 1 },
        { label: 'Shopee', total: 1 },
    ]);
});

test('composicaoPorMarketplace devolve lista vazia sem entrada valida', () => {
    assert.deepEqual(composicaoPorMarketplace([]), []);
    assert.deepEqual(composicaoPorMarketplace(null), []);
    assert.deepEqual(composicaoPorMarketplace([{ fonte_financeira: null }]), []);
});
