import test from 'node:test';
import assert from 'node:assert/strict';
import {
    FASES_TAREFAS,
    ORDEM_INDICADORES,
    universoTarefas,
    feitoDaTarefa,
    progressoDaTarefa,
    resumoPorResponsavel,
    distribuicaoEstagios,
    rotuloPrazo,
    nivelEstagio,
    coresDosResponsaveis,
    spansDosCartoes,
    fileirasDosSpans,
} from '../../resources/js/lib/polosTarefas.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// TKT-0017 — tarefas do time dos Polos: "feito hoje × meta".
//
// O que estes testes travam (e não é óbvio lendo a tela):
//   · o universo é M1–M4 (resposta da solicitante) — M0, Churn e Encerrado não contam;
//   · a régua de cada indicador é a aprovada no ticket, sem acento nem caixa (texto livre);
//   · Publicação conta quem chegou ao estágio-alvo OU ADIANTE (evolução de estágios);
//   · a contagem manual usa o número digitado, não as empresas;
//   · os cartões da TV ficam no arranjo do desenho (2 por fileira, a última com 3);
//   · a lista de indicadores do JS é a mesma do PHP (sem enum compartilhado).
// ═══════════════════════════════════════════════════════════════════════

const emp = (campos) => ({ fase: 'M2', ...campos });

test('universo = empresas de M1 a M4', () => {
    assert.deepEqual(FASES_TAREFAS, ['M1', 'M2', 'M3', 'M4']);
    const lista = ['M0', 'M1', 'M2', 'M3', 'M4', 'Churn', 'Encerrado', null].map((fase) => ({ fase }));
    assert.equal(universoTarefas(lista).length, 4);
});

test('régua de cada indicador, sem acento nem caixa', () => {
    const casos = [
        ['places', { places: 'ATIVO' }, { places: 'Solicitado' }],
        ['me1', { me1: ' ativo ' }, { me1: 'Em contratação' }],
        ['decola', { decola: 'sim' }, { decola: 'Mensagem Enviada' }],
        ['central_promocao', { central_promocao: 'Sim' }, { central_promocao: 'Não' }],
        ['colaborador', { acesso_colaborador: 'com acesso' }, { acesso_colaborador: 'Falta Aceitar' }],
        ['campanha', { ads_campanha: 'ativa' }, { ads_campanha: 'sem_campanha' }],
        ['adman', { adman_vinculo: 'Vinculado' }, { adman_vinculo: 'Não vinculado' }],
        ['ppa', { tem_ppa: true }, { tem_ppa: false }],
    ];
    for (const [indicador, sim, nao] of casos) {
        const universo = [emp(sim), emp(nao), emp({})];
        assert.equal(feitoDaTarefa({ indicador }, universo), 1, `régua de ${indicador}`);
    }
});

test('Decola confirmado pelo Mercado Livre conta mesmo sem o "Sim" marcado', () => {
    const universo = [emp({ decola: 'Não', decola_ml_ativo: true }), emp({ decola: 'Não', decola_ml_ativo: false })];
    assert.equal(feitoDaTarefa({ indicador: 'decola' }, universo), 1);
});

test('campanha desligada ou não verificada não conta como ativa', () => {
    const universo = [emp({ ads_campanha: 'desligada' }), emp({ ads_campanha: null })];
    assert.equal(feitoDaTarefa({ indicador: 'campanha' }, universo), 0);
});

test('Publicação: estágio-alvo ou adiante; sem alvo = saiu de Não Listado', () => {
    const universo = ['Não Listado', 'Estágio 1', 'Estágio 2', 'Estágio 3', 'Concluido', 'Concluído', null, '1º Anúncio']
        .map((estagio) => emp({ estagio }));
    assert.equal(feitoDaTarefa({ indicador: 'publicacao' }, universo), 5);
    assert.equal(feitoDaTarefa({ indicador: 'publicacao', estagio_alvo: 'Estágio 1' }, universo), 5);
    assert.equal(feitoDaTarefa({ indicador: 'publicacao', estagio_alvo: 'Estágio 2' }, universo), 4);
    assert.equal(feitoDaTarefa({ indicador: 'publicacao', estagio_alvo: 'Concluido' }, universo), 2);
    assert.equal(nivelEstagio('concluído'), 4);
    assert.equal(nivelEstagio('1º Anúncio'), -1);
});

test('indicador desconhecido não quebra a tela: conta zero', () => {
    assert.equal(feitoDaTarefa({ indicador: 'inexistente' }, [emp({ places: 'Ativo' })]), 0);
});

test('progresso: feito/meta, barra com teto, meta batida e atraso', () => {
    const universo = [emp({ places: 'Ativo' }), emp({ places: 'Ativo' }), emp({ places: 'Não' })];
    const hoje = new Date(2026, 9, 14);

    const p = progressoDaTarefa({ indicador: 'places', meta: 4, prazo: '2026-10-10' }, universo, hoje);
    assert.deepEqual([p.feito, p.meta, p.pct, p.pctBarra, p.batida, p.atrasada], [2, 4, 50, 50, false, true]);
    assert.equal(p.prazoLabel, '10/10 (sáb)');

    const estourada = progressoDaTarefa({ indicador: 'places', meta: 1, prazo: '2026-10-10' }, universo, hoje);
    assert.deepEqual([estourada.pct, estourada.pctBarra, estourada.batida, estourada.atrasada], [200, 100, true, false]);

    const continua = progressoDaTarefa({ indicador: 'places', meta: 10, prazo: null }, universo, hoje);
    assert.equal(continua.atrasada, false);
    assert.equal(continua.prazoLabel, 'contínuo');

    const semMeta = progressoDaTarefa({ indicador: 'places', meta: 0 }, universo, hoje);
    assert.equal(semMeta.pct, null);
    assert.equal(semMeta.pctBarra, 0);

    // prazo de hoje ainda não está atrasado
    assert.equal(progressoDaTarefa({ indicador: 'places', meta: 9, prazo: '2026-10-14' }, universo, hoje).atrasada, false);
});

test('rótulo do prazo lê os dígitos, sem cair no dia anterior por UTC', () => {
    assert.equal(rotuloPrazo('2026-10-16'), '16/10 (sex)');
    assert.equal(rotuloPrazo(''), 'contínuo');
});

test('resumo por responsável: média do % com teto de 100, mais tarefas primeiro', () => {
    const universo = [emp({ places: 'Ativo', me1: 'Ativo' }), emp({ places: 'Ativo' })];
    const tarefas = [
        { indicador: 'places', meta: 1, responsavel_id: 7, responsavel_nome: 'Carol' },   // 200% → 100
        { indicador: 'me1', meta: 4, responsavel_id: 7, responsavel_nome: 'Carol' },      // 25%
        { indicador: 'ppa', meta: 0, responsavel_id: 7, responsavel_nome: 'Carol' },      // sem meta: fora da média
        { indicador: 'places', meta: 4, responsavel_id: 3, responsavel_nome: 'Bruno' },   // 50%
        { indicador: 'places', meta: 4, responsavel_id: null },                            // sem responsável
    ];
    const r = resumoPorResponsavel(tarefas, universo);
    assert.deepEqual(r.map((x) => [x.nome, x.tarefas, x.pct]), [
        ['Carol', 3, 63],
        ['Bruno', 1, 50],
        ['Sem responsável', 1, 50],
    ]);
});

test('distribuição por estágio fecha com o total do universo', () => {
    const universo = ['Não Listado', 'Estágio 1', 'Estágio 1', 'Concluido', null].map((estagio) => emp({ estagio }));
    const d = distribuicaoEstagios(universo);
    assert.equal(d.total, 5);
    assert.equal(d.linhas.reduce((s, l) => s + l.n, 0), 5);
    assert.deepEqual(d.linhas.map((l) => l.rotulo), ['Não Listado', 'Estágio 1', 'Estágio 2', 'Estágio 3', 'Concluído', 'Sem estágio']);
});

test('cor do responsável é estável pela ordem do id', () => {
    const a = coresDosResponsaveis([{ responsavel_id: 9 }, { responsavel_id: 2 }, { responsavel_id: null }]);
    const b = coresDosResponsaveis([{ responsavel_id: 2 }, { responsavel_id: 9 }]);
    assert.deepEqual(a, b);
    assert.equal(Object.keys(a).length, 2);
});

test('os indicadores do JS são os mesmos do PHP (PoloTarefaMeta::INDICADORES), na mesma ordem', () => {
    const modelo = lerSemComentarios('app/Models/PoloTarefaMeta.php');
    const bloco = modelo.match(/INDICADORES = \[([\s\S]*?)\];/);
    assert.ok(bloco, 'INDICADORES não encontrado no model');
    const chavesPhp = [...bloco[1].matchAll(/'([a-z_0-9]+)'\s*=>/g)].map((m) => m[1]);
    assert.deepEqual(ORDEM_INDICADORES, chavesPhp);
});

test('contagem manual: o feito é o número digitado, não as empresas', () => {
    const universo = [emp({ places: 'Ativo' }), emp({ places: 'Ativo' })];
    assert.equal(feitoDaTarefa({ indicador: 'manual', feito_manual: 1 }, universo), 1);
    assert.equal(feitoDaTarefa({ indicador: 'manual', feito_manual: null }, universo), 0);
    assert.equal(feitoDaTarefa({ indicador: 'manual', feito_manual: '-3' }, universo), 0);
    const p = progressoDaTarefa({ indicador: 'manual', feito_manual: 1, meta: 4 }, universo);
    assert.equal(p.pct, 25);
});

test('cartões da TV no arranjo do desenho: 2 por fileira, a última com 3 quando ímpar', () => {
    assert.deepEqual(spansDosCartoes(7), [6, 6, 6, 6, 5, 4, 3]);
    assert.equal(fileirasDosSpans(spansDosCartoes(7)), 3);
    assert.deepEqual(spansDosCartoes(6), [6, 6, 6, 6, 6, 6]);
    assert.deepEqual(spansDosCartoes(3), [5, 4, 3]);
    assert.deepEqual(spansDosCartoes(1), [12]);
    assert.deepEqual(spansDosCartoes(0), []);
    // De 10 em diante, três por fileira; toda fileira fecha em 12.
    [10, 11, 12, 13].forEach((n) => {
        const spans = spansDosCartoes(n);
        assert.equal(spans.length, n);
        assert.equal(spans.reduce((a, b) => a + b, 0) % 12, 0, `n=${n}`);
    });
    assert.equal(fileirasDosSpans(spansDosCartoes(10)), 4);
});
