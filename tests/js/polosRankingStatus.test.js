import test from 'node:test';
import assert from 'node:assert/strict';
import { STATUS_ORDEM, STATUS_META, distribuirStatus } from '../../resources/js/Pages/Polos/components/statusMeta.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// "Ranking de % da meta" passou a mostrar a Distribuição de status POR REGIÃO
// (2026-08-26). O contrato que precisa valer: somar as contagens de todos os
// polos tem de devolver exatamente o donut "Distribuição de status".
//
// Isso se apoia num fato do backend (PolosController): `agregarPorPolo()` e
// `distribuicaoStatus()` varrem a MESMA lista de ativos com o MESMO
// `calcularStatus()`, e cada ativo cai em UM polo só. Se algum dia a agregação
// por polo passar a descartar ativo (ex.: exigir cust_id no CSV), o ranking
// deixa de fechar com o donut — e é este teste que trava.
// ═══════════════════════════════════════════════════════════════════════

const emp = (status) => ({ status });

const POLOS = [
    { polo: 'Arapongas',        empresas: [emp('Sim'), emp('Sim'), emp('Não'), emp('Em progresso')] },
    { polo: 'S. J. Rio Preto',  empresas: [emp('Sim'), emp('Problema')] },
    { polo: 'Serra Gaúcha',     empresas: [emp('Sim')] },
    { polo: 'São Bento do Sul', empresas: [emp('Não'), emp('Não')] },
];

// O donut que o backend mandaria para essas mesmas 9 empresas.
const DONUT = { 'Sim': 4, 'Em progresso': 1, 'Não': 3, 'Problema': 1, total: 9 };

// ─── 1. Distribuição por polo ───

test('conta cada status do polo e calcula o % no alvo', () => {
    const d = distribuirStatus(POLOS[0].empresas);
    assert.deepEqual(d.contagem, { 'Sim': 2, 'Em progresso': 1, 'Não': 1, 'Problema': 0 });
    assert.equal(d.total, 4);
    assert.equal(d.noAlvo, 2);
    assert.equal(d.pctNoAlvo, 50);
});

test('polo sem nenhuma empresa no alvo fica em 0% — sem divisao por zero', () => {
    const d = distribuirStatus(POLOS[3].empresas);
    assert.equal(d.noAlvo, 0);
    assert.equal(d.pctNoAlvo, 0);
    assert.equal(d.total, 2);
});

test('polo vazio ou prop ausente devolve zeros em vez de NaN', () => {
    for (const entrada of [[], undefined, null, 'nao-e-array']) {
        const d = distribuirStatus(entrada);
        assert.equal(d.total, 0);
        assert.equal(d.pctNoAlvo, 0);
        assert.deepEqual(d.contagem, { 'Sim': 0, 'Em progresso': 0, 'Não': 0, 'Problema': 0 });
    }
});

test('status desconhecido entra no total mas em nenhum balde — barra encolhe, tela nao quebra', () => {
    const d = distribuirStatus([emp('Sim'), emp('Fantasma'), { /* sem status */ }]);
    assert.equal(d.contagem['Sim'], 1);
    assert.equal(d.total, 3);
    assert.equal(
        STATUS_ORDEM.reduce((s, k) => s + d.contagem[k], 0),
        1,
        'so o Sim conhecido deveria estar contado',
    );
});

test('nao conta chave herdada do Object.prototype como status', () => {
    // `'toString' in contagem` é TRUE pela cadeia de protótipo: com `in` no lugar de
    // hasOwnProperty, este caso criaria a chave lixo `contagem.toString`.
    const d = distribuirStatus([emp('toString'), emp('constructor'), emp('valueOf'), emp('Sim')]);
    assert.deepEqual(Object.keys(d.contagem), STATUS_ORDEM, 'o objeto de contagem ganhou chave que nao e status');
    assert.equal(d.total, 4);
    assert.equal(STATUS_ORDEM.reduce((s, k) => s + d.contagem[k], 0), 1);
});

// ─── 2. O contrato com o donut ───

test('somar os polos reproduz o donut Distribuicao de status', () => {
    const soma = { 'Sim': 0, 'Em progresso': 0, 'Não': 0, 'Problema': 0 };
    let total = 0;
    POLOS.forEach((p) => {
        const d = distribuirStatus(p.empresas);
        STATUS_ORDEM.forEach((k) => { soma[k] += d.contagem[k]; });
        total += d.total;
    });

    STATUS_ORDEM.forEach((k) => assert.equal(soma[k], DONUT[k], `status ${k} divergiu do donut`));
    assert.equal(total, DONUT.total);
    assert.equal(Math.round((soma['Sim'] / total) * 100), 44); // 4 de 9
});

// ─── 3. Ordenação do ranking ───

test('ordena por % no alvo desc, com o polo maior na frente no empate', () => {
    const ord = POLOS
        .map((p) => ({ ...p, dist: distribuirStatus(p.empresas) }))
        .sort((a, b) => (b.dist.pctNoAlvo - a.dist.pctNoAlvo) || (b.dist.total - a.dist.total));

    assert.deepEqual(ord.map((p) => p.polo), [
        'Serra Gaúcha',      // 100% (1/1)
        'Arapongas',         //  50% (2/4) — empata com Rio Preto e ganha por ter mais ativos
        'S. J. Rio Preto',   //  50% (1/2)
        'São Bento do Sul',  //   0% (0/2)
    ]);
});

// ─── 4. Fiação no JSX (sem ESLint, identificador solto compila e a tela quebra) ───

const fonte = lerSemComentarios('resources/js/Pages/Polos/components/RankingProgresso.jsx');

test('o ranking usa a regra compartilhada em vez de recontar por conta propria', () => {
    assert.match(fonte, /import\s*\{[^}]*\bdistribuirStatus\b[^}]*\}\s*from\s*'\.\/statusMeta'/s);
    assert.match(fonte, /import\s*\{[^}]*\bSTATUS_ORDEM\b[^}]*\}\s*from\s*'\.\/statusMeta'/s);
    assert.match(fonte, /distribuirStatus\(p\.empresas\)/);
});

test('a barra deixou de ser faturamento÷meta e virou a pilha de status', () => {
    // A largura de cada fatia sai da contagem do status, não mais de `pct`.
    assert.match(fonte, /const w = mounted \? \(n \/ total\) \* 100 : 0/);
    // O número da direita é o % no alvo.
    assert.match(fonte, /\{pctNoAlvo\}%/);
    // Cores vêm do mesmo mapa do donut — nada de hex solto na barra.
    assert.match(fonte, /background: STATUS_META\[k\]\.cor/);
});

test('o faturamento continua visivel — a leitura financeira nao foi perdida', () => {
    assert.match(fonte, /Faturamento: \{formatCurrencyCompact\(p\.faturamento\)\}/);
    assert.match(fonte, /\{pctFat\.toFixed\(0\)\}% da meta/);
});

test('as cores de status conhecidas seguem as do donut', () => {
    assert.equal(STATUS_META['Sim'].cor, '#22c55e');
    assert.equal(STATUS_META['Sim'].label, 'No alvo');
    assert.deepEqual(STATUS_ORDEM, ['Sim', 'Em progresso', 'Não', 'Problema']);
});
