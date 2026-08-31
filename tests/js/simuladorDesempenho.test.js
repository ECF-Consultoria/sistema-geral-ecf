import { test, describe } from 'node:test';
import assert from 'node:assert/strict';

import {
    FAIXAS_FALLBACK,
    aplicarPromocao,
    classificarFaixa,
    linhaEditada,
    notaSimulada,
    parsePonto,
    pontosEfetivos,
    resumoFaixas,
    simularLinha,
    simularRanking,
} from '../../resources/js/lib/simuladorDesempenho.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate do simulador de nota do /performance.
//
// O simulador refaz no navegador a conta que o `DesempenhoScoreService` faz
// no PHP. Se as duas divergirem, a tela mostra um "e se" que nunca
// aconteceria — e quem olha decide bônus em cima disso. Este arquivo trava a
// paridade nos pontos onde ela é fácil de perder: divisor fixo 3, ausente
// somando zero, fronteira da régua e a promoção DESEMP-08.
// ═══════════════════════════════════════════════════════════════════════

// Régua REAL semeada em produção — os testes de fronteira dependem destes
// valores, então ficam explícitos aqui em vez de virem do fallback.
const REGUA = FAIXAS_FALLBACK;

describe('notaSimulada — espelha computeNotaFinalPorIndicador()', () => {
    test('média simples dos três indicadores', () => {
        assert.equal(notaSimulada({ nps: 4, faturamento: 4, margem: 4 }), 4);
    });

    test('indicador ausente soma ZERO e o divisor continua 3', () => {
        // O caso concreto da carteira só-Shopee: sem CMV, a margem não existe.
        // Teto documentado: (5 + 0 + 5) / 3 = 3,33 — nunca alcança os 4,00 da
        // primeira faixa de bônus. É a regra decidida em 2026-08-10, não um
        // efeito colateral.
        const nota = notaSimulada({ nps: 5, faturamento: 5, margem: null });
        assert.equal(Math.round(nota * 100) / 100, 3.33);
    });

    test('carteira sem NENHUM indicador é null, jamais 0', () => {
        // Ausência total de dado não é desempenho zero — a trava D-91-01
        // (`blocked`) depende dessa distinção.
        assert.equal(notaSimulada({ nps: null, faturamento: null, margem: null }), null);
        assert.equal(notaSimulada(null), null);
    });

    test('zero digitado é diferente de campo vazio', () => {
        assert.equal(notaSimulada({ nps: 0, faturamento: null, margem: null }), 0);
        assert.equal(notaSimulada({ nps: null, faturamento: null, margem: null }), null);
    });

    test('não arredonda — a nota crua é o que vai para a régua', () => {
        // 3,8261 + 3,6100 + 0 = 7,4361 / 3 = 2,4787
        const nota = notaSimulada({ nps: 3.8261, faturamento: 3.61, margem: null });
        assert.ok(Math.abs(nota - 2.4787) < 1e-9, `esperado ~2.4787, veio ${nota}`);
    });
});

describe('parsePonto — entrada do usuário', () => {
    test('aceita vírgula (teclado pt-BR) e ponto', () => {
        assert.equal(parsePonto('4,5'), 4.5);
        assert.equal(parsePonto('4.5'), 4.5);
    });

    test('campo vazio é null (sem indicador), não zero', () => {
        assert.equal(parsePonto(''), null);
        assert.equal(parsePonto('   '), null);
        assert.equal(parsePonto(null), null);
    });

    test('grampeia em [0, 5] em vez de recusar', () => {
        assert.equal(parsePonto('50'), 5);
        assert.equal(parsePonto('-3'), 0);
    });

    test('texto que não é número devolve null', () => {
        assert.equal(parsePonto('abc'), null);
    });
});

describe('classificarFaixa — espelha classificarFaixa() do Service', () => {
    test('fronteiras da régua são inclusivas nos dois lados', () => {
        assert.equal(classificarFaixa(0, REGUA), 'sem_bonus');
        assert.equal(classificarFaixa(3.99, REGUA), 'sem_bonus');
        assert.equal(classificarFaixa(4.0, REGUA), 'basico');
        assert.equal(classificarFaixa(4.49, REGUA), 'basico');
        assert.equal(classificarFaixa(4.5, REGUA), 'intermediario');
        assert.equal(classificarFaixa(4.99, REGUA), 'intermediario');
        assert.equal(classificarFaixa(5.0, REGUA), 'maximo');
    });

    test('3,995 não cai em faixa nenhuma — o buraco existe no PHP também', () => {
        // A régua vai até 3,99 e recomeça em 4,00; a nota crua NÃO é
        // arredondada antes de classificar, nem aqui nem no
        // `DesempenhoScoreService`. Reproduzir o buraco é proposital: se o
        // simulador "consertasse" a lacuna, mostraria Básico para quem o
        // sistema deixa sem faixa. Quem trata o null é a exibição.
        assert.equal(classificarFaixa(3.995, REGUA), null);
    });

    test('nota null não classifica', () => {
        assert.equal(classificarFaixa(null, REGUA), null);
    });

    test('usa a régua recebida, não a hardcoded', () => {
        const reguaCustomizada = [
            { slug: 'piso', nome: 'Piso', nota_min: 0, nota_max: 2.5, ordem: 1 },
            { slug: 'teto', nome: 'Teto', nota_min: 2.51, nota_max: 5, ordem: 2 },
        ];
        assert.equal(classificarFaixa(4.6, reguaCustomizada), 'teto');
    });
});

describe('aplicarPromocao — regra DESEMP-08', () => {
    test('intermediário com histórico do mês anterior promove para máximo', () => {
        assert.deepEqual(
            aplicarPromocao('intermediario', 4.7, true),
            { faixa: 'maximo', promovida: true },
        );
    });

    test('intermediário sem histórico continua intermediário', () => {
        assert.deepEqual(
            aplicarPromocao('intermediario', 4.7, false),
            { faixa: 'intermediario', promovida: false },
        );
    });

    test('faixa diferente de intermediário nunca é promovida', () => {
        assert.deepEqual(aplicarPromocao('basico', 4.2, true), { faixa: 'basico', promovida: false });
        assert.deepEqual(aplicarPromocao(null, null, true), { faixa: null, promovida: false });
    });
});

describe('pontosEfetivos — edição parcial não apaga o resto', () => {
    const linha = { id: 1, pontos_componentes: { nps: 3.8261, faturamento: 4.1, margem: null } };

    test('sem edição usa o valor CRU do backend, não o arredondado da tela', () => {
        assert.deepEqual(pontosEfetivos(linha, {}), { nps: 3.8261, faturamento: 4.1, margem: null });
    });

    test('edita um indicador e preserva os outros', () => {
        assert.deepEqual(
            pontosEfetivos(linha, { faturamento: 5 }),
            { nps: 3.8261, faturamento: 5, margem: null },
        );
    });

    test('chave com null é "o admin apagou", diferente de chave ausente', () => {
        assert.deepEqual(
            pontosEfetivos(linha, { nps: null }),
            { nps: null, faturamento: 4.1, margem: null },
        );
    });

    test('linhaEditada só marca quando o valor realmente mudou', () => {
        assert.equal(linhaEditada(linha, {}), false);
        assert.equal(linhaEditada(linha, { nps: 3.8261 }), false);
        assert.equal(linhaEditada(linha, { nps: 4 }), true);
    });
});

describe('simularLinha', () => {
    test('linha intocada reproduz a nota oficial do backend', () => {
        // Paridade que sustenta a tela inteira: ao abrir o simulador, sem
        // digitar nada, a nota simulada tem que ser IGUAL à nota_final que o
        // ranking já mostra. Se divergir, o simulador começa mentindo.
        const linha = {
            id: 7,
            nota_final: 4.4787,
            faixa_bonus: 'basico',
            pontos_componentes: { nps: 4.8, faturamento: 4.5361, margem: 4.1 },
        };
        const sim = simularLinha(linha, {}, REGUA);
        assert.ok(
            Math.abs(sim.nota - (4.8 + 4.5361 + 4.1) / 3) < 1e-9,
            `nota simulada ${sim.nota} deveria bater com a conta do backend`,
        );
        assert.equal(sim.faixa, 'basico');
    });

    test('linha em cálculo (cache frio) não é simulável', () => {
        const sim = simularLinha({ id: 9, calculando: true, pontos_componentes: null }, {}, REGUA);
        assert.equal(sim.calculando, true);
        assert.equal(sim.nota, null);
    });

    test('promovivel_historico da linha alimenta a promoção', () => {
        const linha = {
            id: 3,
            promovivel_historico: true,
            pontos_componentes: { nps: 4.7, faturamento: 4.7, margem: 4.7 },
        };
        const sim = simularLinha(linha, {}, REGUA);
        assert.equal(sim.faixa, 'maximo');
        assert.equal(sim.promovida, true);
    });

    test('carteira blocked continua simulável — o badge é que avisa', () => {
        // O backend força `nota_final = null` quando `score_status = blocked`.
        // No simulador a conta aparece assim mesmo: a pergunta "quanto daria?"
        // é o motivo da tela existir. Nota oficial ela não vira.
        const linha = {
            id: 11,
            score_status: 'blocked',
            nota_final: null,
            pontos_componentes: { nps: null, faturamento: null, margem: null },
        };
        const sim = simularLinha(linha, { nps: 5, faturamento: 5, margem: 5 }, REGUA);
        assert.equal(sim.nota, 5);
        assert.equal(sim.faixa, 'maximo');
    });
});

describe('simularRanking — reordenação', () => {
    const ranking = [
        { id: 1, name: 'Ana',   posicao: 1, nota_final: 4.6, faixa_bonus: 'intermediario', pontos_componentes: { nps: 4.6, faturamento: 4.6, margem: 4.6 } },
        { id: 2, name: 'Bruno', posicao: 2, nota_final: 4.2, faixa_bonus: 'basico',        pontos_componentes: { nps: 4.2, faturamento: 4.2, margem: 4.2 } },
        { id: 3, name: 'Caio',  posicao: 3, nota_final: null, faixa_bonus: null,           pontos_componentes: { nps: null, faturamento: null, margem: null } },
    ];

    test('sem edição preserva a ordem que já estava na tela', () => {
        const out = simularRanking(ranking, {}, REGUA);
        assert.deepEqual(out.map((r) => r.id), [1, 2, 3]);
        assert.deepEqual(out.map((r) => r.delta_posicao), [0, 0, 0]);
        assert.deepEqual(out.map((r) => r.editada), [false, false, false]);
    });

    test('editar ponto reordena e reporta o salto de posição', () => {
        const out = simularRanking(ranking, { 2: { nps: 5, faturamento: 5, margem: 5 } }, REGUA);
        assert.deepEqual(out.map((r) => r.id), [2, 1, 3]);

        const bruno = out.find((r) => r.id === 2);
        assert.equal(bruno.posicao_simulada, 1);
        assert.equal(bruno.delta_posicao, 1);      // subiu uma posição
        assert.equal(bruno.faixa_simulada, 'maximo');
        assert.equal(bruno.faixa_mudou, true);
        assert.equal(bruno.editada, true);
        assert.ok(Math.abs(bruno.delta_nota - 0.8) < 1e-9);
    });

    test('nota null vai sempre para o fim, como no sortByDesc do backend', () => {
        const out = simularRanking(ranking, { 1: { nps: 0, faturamento: 0, margem: 0 } }, REGUA);
        assert.equal(out[out.length - 1].id, 3);
    });

    test('empate mantém a ordem original (ordenação estável)', () => {
        const empatados = [
            { id: 10, posicao: 1, nota_final: 4, pontos_componentes: { nps: 4, faturamento: 4, margem: 4 } },
            { id: 20, posicao: 2, nota_final: 4, pontos_componentes: { nps: 4, faturamento: 4, margem: 4 } },
        ];
        assert.deepEqual(simularRanking(empatados, {}, REGUA).map((r) => r.id), [10, 20]);
    });
});

describe('resumoFaixas — distribuição antes x depois', () => {
    test('conta a régua inteira, inclusive faixa que ninguém ocupa', () => {
        const linhas = simularRanking(
            [
                { id: 1, posicao: 1, nota_final: 4.6, faixa_bonus: 'intermediario', pontos_componentes: { nps: 4.6, faturamento: 4.6, margem: 4.6 } },
                { id: 2, posicao: 2, nota_final: 4.2, faixa_bonus: 'basico',        pontos_componentes: { nps: 4.2, faturamento: 4.2, margem: 4.2 } },
            ],
            { 2: { nps: 5, faturamento: 5, margem: 5 } },
            REGUA,
        );

        const resumo = resumoFaixas(linhas, REGUA);
        assert.deepEqual(resumo.map((f) => f.slug), ['sem_bonus', 'basico', 'intermediario', 'maximo']);

        const basico = resumo.find((f) => f.slug === 'basico');
        assert.deepEqual([basico.antes, basico.depois, basico.delta], [1, 0, -1]);

        const maximo = resumo.find((f) => f.slug === 'maximo');
        assert.deepEqual([maximo.antes, maximo.depois, maximo.delta], [0, 1, 1]);
    });
});
