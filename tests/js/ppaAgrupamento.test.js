import test from 'node:test';
import assert from 'node:assert/strict';
import {
    GRUPO_ANDAMENTO, GRUPO_FAZER, GRUPO_CONCLUIDO,
    abertosPorPadrao, contarTarefas, grupoDoPlano, ordenarPlanos,
    percentual, resumoTarefas, seccionar, seloPrazo,
} from '../../resources/js/lib/ppaAgrupamento.js';

// A régua que decide a ordem das duas listas de PPA (portal e interna). O que
// se protege aqui é o critério, não o desenho: se "em andamento" parar de vir
// antes de "a fazer", ou se um plano 100% feito voltar a ocupar o topo, o
// problema que a reforma resolveu volta sem ninguém perceber.

const plano = (extra = {}) => ({ concluido: false, total: 0, feitas: 0, fazendo: 0, ...extra });

test('o grupo sai das tarefas, e tarefa em andamento manda no plano', () => {
    assert.equal(grupoDoPlano(plano({ total: 3, feitas: 1, fazendo: 1 })), GRUPO_ANDAMENTO);
    assert.equal(grupoDoPlano(plano({ total: 3, feitas: 0, fazendo: 0 })), GRUPO_FAZER);

    // Sem tarefa nenhuma o plano fica com o que ainda não começou — nunca com
    // o que acabou, que é onde uma divisão por zero o colocaria.
    assert.equal(grupoDoPlano(plano()), GRUPO_FAZER);
    assert.equal(percentual({ total: 0, feitas: 0 }), 0);
});

test('plano 100% feito desce para concluídos mesmo sem a equipe encerrar', () => {
    assert.equal(grupoDoPlano(plano({ total: 4, feitas: 4 })), GRUPO_CONCLUIDO);

    // E o encerramento da equipe vence as tarefas abertas: se ela deu o plano
    // por fechado, ele não volta a cobrar o cliente.
    assert.equal(grupoDoPlano(plano({ concluido: true, total: 4, feitas: 1, fazendo: 2 })), GRUPO_CONCLUIDO);
});

test('dentro do grupo, o atrasado vem antes; sem prazo vai para o fim', () => {
    const lista = [
        { id: 'sem-prazo', grupo: GRUPO_ANDAMENTO, prazoDias: null },
        { id: 'folgado',   grupo: GRUPO_ANDAMENTO, prazoDias: 30 },
        { id: 'atrasado',  grupo: GRUPO_ANDAMENTO, prazoDias: -5 },
        { id: 'hoje',      grupo: GRUPO_ANDAMENTO, prazoDias: 0 },
    ];

    assert.deepEqual(
        ordenarPlanos(lista).map((p) => p.id),
        ['atrasado', 'hoje', 'folgado', 'sem-prazo'],
    );
});

test('a seção ordena por grupo antes de qualquer urgência', () => {
    const lista = [
        { id: 1, grupo: GRUPO_CONCLUIDO, prazoDias: -90 },
        { id: 2, grupo: GRUPO_FAZER,     prazoDias: 10 },
        { id: 3, grupo: GRUPO_ANDAMENTO, prazoDias: 40 },
    ];

    // O concluído atrasado há 90 dias continua por último: encerrado é
    // encerrado, e urgência de plano fechado não é urgência.
    assert.deepEqual(ordenarPlanos(lista).map((p) => p.id), [3, 2, 1]);

    const secoes = seccionar(lista);
    assert.deepEqual(secoes.map((s) => s.chave), [GRUPO_ANDAMENTO, GRUPO_FAZER, GRUPO_CONCLUIDO]);
    assert.deepEqual(secoes.map((s) => s.planos.length), [1, 1, 1]);
});

test('abre só o primeiro de cada grupo ativo — nunca um concluído', () => {
    const lista = [
        { id: 'and-1', grupo: GRUPO_ANDAMENTO, prazoDias: 5 },
        { id: 'and-2', grupo: GRUPO_ANDAMENTO, prazoDias: -2 },
        { id: 'faz-1', grupo: GRUPO_FAZER,     prazoDias: null },
        { id: 'ok-1',  grupo: GRUPO_CONCLUIDO, prazoDias: null },
    ];

    const abertos = abertosPorPadrao(lista);
    // `and-2` está atrasado, então é ele que abre — não o primeiro da lista.
    assert.deepEqual([...abertos].sort(), ['and-2', 'faz-1']);
    assert.equal(abertos.has('ok-1'), false);
});

test('o selo de prazo fala só quando tem o que dizer', () => {
    assert.deepEqual(seloPrazo(-1), { tom: 'atrasado', texto: 'Atrasado há 1 dia' });
    assert.deepEqual(seloPrazo(-9), { tom: 'atrasado', texto: 'Atrasado há 9 dias' });
    assert.deepEqual(seloPrazo(0),  { tom: 'hoje',     texto: 'Vence hoje' });
    assert.deepEqual(seloPrazo(1),  { tom: 'proximo',  texto: 'Vence amanhã' });
    assert.deepEqual(seloPrazo(7),  { tom: 'proximo',  texto: 'Vence em 7 dias' });

    // Longe, sem prazo e encerrado não ganham selo: a data crua basta, e plano
    // fechado não cobra ninguém.
    assert.equal(seloPrazo(8), null);
    assert.equal(seloPrazo(null), null);
    assert.equal(seloPrazo(-30, { encerrado: true }), null);
});

test('o resumo da linha recolhida só cita o que existe', () => {
    assert.equal(resumoTarefas(contarTarefas([])), 'Sem tarefas ainda');

    const tarefas = [
        { status: 'doing' }, { status: 'doing' },
        { status: 'todo' },
        { status: 'done' },
    ];
    assert.deepEqual(contarTarefas(tarefas), { total: 4, feitas: 1, fazendo: 2, aFazer: 1 });
    assert.equal(resumoTarefas(contarTarefas(tarefas)), '2 em andamento · 1 a fazer · 1 de 4 concluídas');

    // Nada pendente: some o "0 a fazer" em vez de mostrar um zero decorativo.
    assert.equal(
        resumoTarefas(contarTarefas([{ status: 'done' }, { status: 'done' }])),
        '2 de 2 concluídas',
    );
});
