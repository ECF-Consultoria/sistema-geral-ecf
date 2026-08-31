import { test, describe } from 'node:test';
import assert from 'node:assert/strict';

import {
    FAIXAS_FALLBACK,
    aplicarPromocao,
    classificarFaixa,
    componentesEsperados,
    linhaEditada,
    mediasPorIndicador,
    notaSimulada,
    parsePonto,
    pontosEfetivos,
    resumoFaixas,
    simularCarteira,
    simularEmpresa,
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

// ═══════════════════════════════════════════════════════════════════════
// Simulador DETALHADO — nível empresa
//
// A régua de 1 a 5 é aplicada loja a loja pelo CompanyScoreService, e o
// DesempenhoScoreService só promedia. Estes testes travam a camada de baixo:
// se a média por indicador aqui divergir da do PHP, o simulador detalhado
// mostra uma nota que o fechamento nunca produziria.
// ═══════════════════════════════════════════════════════════════════════

/** Linha por empresa como o snapshot entrega (só os campos que importam). */
function loja(id, nome, { nps = null, fat = null, margem = null, fonte = 'adman' } = {}) {
    return {
        company_id: id,
        company_name: nome,
        fonte_financeira: fonte,
        nps_pontos: nps,
        faturamento_pontos: fat,
        margem_pontos: margem,
    };
}

describe('componentesEsperados — regra da Shopee', () => {
    test('Shopee sem margem espera 2 (a plataforma não fornece CMV)', () => {
        const l = loja(1, 'Shop', { fonte: 'shopee' });
        assert.equal(componentesEsperados(l, { nps: 5, faturamento: 4, margem: null }), 2);
    });

    test('Shopee COM margem passa a esperar 3, como a margem manual faz no motor', () => {
        // O simulador pode preencher a margem de uma loja Shopee. Se
        // continuássemos esperando 2, ela ficaria com 3 presentes contra 2
        // esperados e cairia em `partial` por ter sido completada.
        const l = loja(1, 'Shop', { fonte: 'shopee' });
        assert.equal(componentesEsperados(l, { nps: 5, faturamento: 4, margem: 3 }), 3);
    });

    test('qualquer outra fonte espera 3', () => {
        assert.equal(componentesEsperados(loja(2, 'ML'), { nps: 5, faturamento: 4, margem: null }), 3);
    });
});

describe('simularEmpresa — nota estrita por loja (D-01)', () => {
    test('loja com os 3 componentes fecha nota e status complete', () => {
        const s = simularEmpresa(loja(1, 'Alfa', { nps: 5, fat: 4, margem: 3 }));
        assert.equal(s.status_simulado, 'complete');
        assert.equal(s.nota_empresa_simulada, 4);
    });

    test('loja sem margem não fecha nota estrita, mas tem parcial', () => {
        const s = simularEmpresa(loja(2, 'Beta', { nps: 5, fat: 4 }));
        assert.equal(s.status_simulado, 'partial');
        assert.equal(s.nota_empresa_simulada, null);
        assert.equal(s.nota_empresa_parcial_simulada, 4.5);
    });

    test('loja sem nenhum componente é sem_dados', () => {
        const s = simularEmpresa(loja(3, 'Gama'));
        assert.equal(s.status_simulado, 'sem_dados');
        assert.equal(s.nota_empresa_parcial_simulada, null);
    });

    test('preencher a margem que faltava completa a loja', () => {
        const s = simularEmpresa(loja(2, 'Beta', { nps: 5, fat: 4 }), { margem: 3 });
        assert.equal(s.status_simulado, 'complete');
        assert.equal(s.nota_empresa_simulada, 4);
        assert.equal(s.editada, true);
    });

    test('loja Shopee sem margem já é complete com 2 componentes', () => {
        const s = simularEmpresa(loja(4, 'Shop', { nps: 5, fat: 4, fonte: 'shopee' }));
        assert.equal(s.status_simulado, 'complete');
        assert.equal(s.nota_empresa_simulada, 4.5);
    });
});

describe('mediasPorIndicador — denominador INDEPENDENTE', () => {
    test('loja sem margem continua contando no faturamento e no NPS', () => {
        // É a diferença entre `computeNotaFinalPorIndicador` (oficial) e
        // `computeNotaFinalPorEmpresa` (descarta a linha toda). Aqui a Beta
        // entra em 2 das 3 médias.
        const linhas = [
            simularEmpresa(loja(1, 'Alfa', { nps: 5, fat: 4, margem: 3 })),
            simularEmpresa(loja(2, 'Beta', { nps: 3, fat: 2 })),
        ];
        const m = mediasPorIndicador(linhas);
        assert.equal(m.nps, 4);          // (5+3)/2
        assert.equal(m.faturamento, 3);  // (4+2)/2
        assert.equal(m.margem, 3);       // só a Alfa tem margem
    });

    test('indicador que nenhuma loja tem vira null, não zero', () => {
        const linhas = [simularEmpresa(loja(1, 'Alfa', { nps: 5, fat: 4 }))];
        assert.equal(mediasPorIndicador(linhas).margem, null);
    });

    test('carteira vazia devolve os três null', () => {
        assert.deepEqual(mediasPorIndicador([]), { nps: null, faturamento: null, margem: null });
    });
});

describe('simularCarteira', () => {
    const carteira = [
        loja(1, 'Alfa', { nps: 5, fat: 4, margem: 3 }),
        loja(2, 'Beta', { nps: 3, fat: 2, margem: 1 }),
    ];

    test('abrir sem mexer em nada não conta como tocada', () => {
        // Garante que só carregar o detalhe não sequestra o número de cima.
        const c = simularCarteira(carteira, {}, []);
        assert.equal(c.tocada, false);
        assert.deepEqual(c.medias, { nps: 4, faturamento: 3, margem: 2 });
        assert.equal(c.nota, 3);
    });

    test('editar o ponto de uma loja move a média do indicador', () => {
        const c = simularCarteira(carteira, { 2: { nps: 5 } }, []);
        assert.equal(c.tocada, true);
        assert.equal(c.medias.nps, 5);
        assert.equal(c.medias.faturamento, 3); // intocado
    });

    test('excluir loja tira ela das médias e conta como tocada', () => {
        const c = simularCarteira(carteira, {}, [2]);
        assert.equal(c.tocada, true);
        assert.equal(c.qtd_incluidas, 1);
        assert.equal(c.qtd_excluidas, 1);
        assert.deepEqual(c.medias, { nps: 5, faturamento: 4, margem: 3 });
    });

    test('a loja excluída continua na lista, marcada — não some da conferência', () => {
        const c = simularCarteira(carteira, {}, [2]);
        assert.equal(c.linhas.length, 2);
        assert.equal(c.linhas.find((l) => l.company_id === 2).incluida, false);
    });

    test('excluir TODAS as lojas zera a carteira sem inventar nota', () => {
        const c = simularCarteira(carteira, {}, [1, 2]);
        assert.deepEqual(c.medias, { nps: null, faturamento: null, margem: null });
        assert.equal(c.nota, null);
    });
});

describe('simularRanking com detalhe por empresa', () => {
    const ranking = [
        { id: 1, name: 'Ana',   posicao: 1, nota_final: 4.6, faixa_bonus: 'intermediario', pontos_componentes: { nps: 4.6, faturamento: 4.6, margem: 4.6 } },
        { id: 2, name: 'Bruno', posicao: 2, nota_final: 3.0, faixa_bonus: 'sem_bonus',     pontos_componentes: { nps: 4.0, faturamento: 3.0, margem: 2.0 } },
    ];
    const carteiraBruno = [
        loja(10, 'Alfa', { nps: 5, fat: 4, margem: 3 }),
        loja(20, 'Beta', { nps: 3, fat: 2, margem: 1 }),
    ];

    test('detalhe carregado e intocado não muda nada no ranking', () => {
        const out = simularRanking(ranking, {}, REGUA, {
            2: { empresas: carteiraBruno, edicoes: {}, excluidas: [] },
        });
        const bruno = out.find((r) => r.id === 2);
        assert.equal(bruno.derivado_das_lojas, false);
        assert.equal(bruno.editada, false);
        assert.equal(bruno.nota_simulada, 3);
        assert.deepEqual(out.map((r) => r.id), [1, 2]);
    });

    test('editar loja sobe a nota do colaborador', () => {
        const out = simularRanking(ranking, {}, REGUA, {
            2: {
                empresas: carteiraBruno,
                edicoes: { 20: { nps: 5, faturamento: 5, margem: 5 } },
                excluidas: [],
            },
        });
        const bruno = out.find((r) => r.id === 2);
        assert.equal(bruno.derivado_das_lojas, true);
        // médias: nps (5+5)/2=5 · fat (4+5)/2=4,5 · margem (3+5)/2=4 → 4,5
        assert.equal(bruno.nota_simulada, 4.5);
        assert.equal(bruno.faixa_simulada, 'intermediario');
        assert.deepEqual(out.map((r) => r.id), [1, 2]); // 4,6 ainda na frente
    });

    test('excluir a loja fraca muda a nota da carteira', () => {
        const out = simularRanking(ranking, {}, REGUA, {
            2: { empresas: carteiraBruno, edicoes: {}, excluidas: [20] },
        });
        const bruno = out.find((r) => r.id === 2);
        assert.equal(bruno.nota_simulada, 4);   // só a Alfa: (5+4+3)/3
        assert.equal(bruno.posicao_simulada, 2);
        assert.equal(bruno.delta_posicao, 0);
    });

    test('detalhe tocado tem precedência sobre a edição de cima', () => {
        // Dois donos para o mesmo número deixaria a tela mostrando um valor
        // que não corresponde a nenhuma das duas edições. Quem manda é o
        // detalhe; a UI desabilita os campos de cima nesse estado.
        const out = simularRanking(ranking, { 2: { nps: 1, faturamento: 1, margem: 1 } }, REGUA, {
            2: { empresas: carteiraBruno, edicoes: { 20: { nps: 5 } }, excluidas: [] },
        });
        const bruno = out.find((r) => r.id === 2);
        assert.equal(bruno.derivado_das_lojas, true);
        assert.equal(bruno.pontos_simulados.nps, 5);            // média das lojas
        assert.notEqual(bruno.pontos_simulados.faturamento, 1); // não o "1" de cima
    });

    test('sem detalhe carregado, a edição de cima continua valendo', () => {
        const out = simularRanking(ranking, { 2: { nps: 5, faturamento: 5, margem: 5 } }, REGUA, {});
        const bruno = out.find((r) => r.id === 2);
        assert.equal(bruno.derivado_das_lojas, false);
        assert.equal(bruno.nota_simulada, 5);
    });
});

describe('paridade entre os dois níveis', () => {
    test('médias das lojas reproduzem a conta do colaborador', () => {
        // Se esta relação quebrar, abrir o detalhe mostraria números que não
        // somam a nota exibida no ranking — e o simulador perderia a
        // credibilidade antes da primeira edição.
        const carteira = [
            loja(1, 'Alfa', { nps: 5, fat: 4, margem: 3 }),
            loja(2, 'Beta', { nps: 4, fat: 3, margem: null }),
            loja(3, 'Shop', { nps: 3, fat: 2, fonte: 'shopee' }),
        ];
        const c = simularCarteira(carteira, {}, []);

        assert.equal(c.medias.nps, 4);                        // (5+4+3)/3
        assert.equal(c.medias.faturamento, 3);                // (4+3+2)/3
        assert.equal(c.medias.margem, 3);                     // só a Alfa
        assert.ok(Math.abs(c.nota - (4 + 3 + 3) / 3) < 1e-9); // divisor fixo 3
    });
});
