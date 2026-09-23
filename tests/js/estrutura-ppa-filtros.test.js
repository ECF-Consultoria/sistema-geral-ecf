import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { lerSemComentarios } from './_fonte.js';
import { GRUPOS } from '../../resources/js/lib/ppaAgrupamento.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate da unificação das duas telas de PPA (23/09/2026).
//
// Até aqui, equipe e cliente olhavam desenhos DIFERENTES do mesmo plano, e
// falar ao telefone sobre "o card em andamento" exigia traduzir entre as duas.
// Agora os dois lados montam os MESMOS componentes de `Components/Ppa/`.
//
// Lê a fonte SEM COMENTÁRIOS: a prosa deste projeto cita os próprios
// identificadores, e um gate cru passaria pelo comentário.
//
// O que estas travas protegem, na ordem em que quebraria:
//  1. a volta da duplicação — alguém copia o quadro para um dos lados
//     "só para ajustar uma coisinha" e as telas divergem de novo;
//  2. o payload: componente compartilhado exige as MESMAS chaves, e quem as
//     produz é `PpaListaService` em cima de `PortalPpaService::visao()`;
//  3. `value=""` num Select do Radix apaga a tela inteira (já aconteceu aqui);
//  4. a ordem escolhida pelo usuário, que a tela não pode refazer por conta;
//  5. filtrar por "Concluídos" com a gaveta fechada = tela vazia com o
//     contador dizendo que há 12.
// ═══════════════════════════════════════════════════════════════════════

const INTERNA = 'resources/js/Pages/Ppa/Index.jsx';
const PORTAL  = 'resources/js/Pages/Portal/Ppa.jsx';

const interna = lerSemComentarios(INTERNA);
const portal  = lerSemComentarios(PORTAL);

const existe = (caminho) => {
    try {
        readFileSync(resolve(import.meta.dirname, '../..', caminho));
        return true;
    } catch {
        return false;
    }
};

// ─── 1. Um desenho só para os dois lados ───

test('ppa — as duas telas montam os MESMOS componentes de quadro', () => {
    for (const fonte of [interna, portal]) {
        assert.match(fonte, /from '@\/Components\/Ppa\/PlanoPpa'/);
        assert.match(fonte, /from '@\/Components\/Ppa\/IndicadoresPpa'/);
        assert.match(fonte, /from '@\/Components\/Ppa\/TituloSecaoPpa'/);
    }
});

test('ppa — os componentes saíram da pasta do Portal e nada ficou para trás', () => {
    // O caminho antigo dizia que o quadro era do cliente. Ele é dos dois.
    assert.equal(existe('resources/js/Components/Portal/Ppa/PlanoPortal.jsx'), false);
    assert.equal(existe('resources/js/Components/Ppa/PlanoPpa.jsx'), true);

    for (const fonte of [interna, portal]) {
        assert.doesNotMatch(fonte, /Components\/Portal\/Ppa/);
        assert.doesNotMatch(fonte, /PlanoPortal|ColunaPortal|CardTarefaPortal/);
    }
});

test('ppa — nenhuma das telas redesenha indicador ou coluna por conta própria', () => {
    // Um `function Indicador` ou um `const COLUNAS` reaparecendo numa página é
    // o primeiro passo da divergência que esta unificação desfez.
    for (const fonte of [interna, portal]) {
        assert.doesNotMatch(fonte, /function Indicador\b/);
        assert.doesNotMatch(fonte, /const COLUNAS\b/);
        assert.doesNotMatch(fonte, /function TituloSecao\b/);
    }
});

test('ppa — os quatro números saem da mesma conta, na lib', () => {
    for (const fonte of [interna, portal]) {
        assert.match(fonte, /totaisDosPlanos\(planos, tarefasPorPlano\)/);
    }
});

// ─── 2. O que só a equipe vê entra por propriedade, não por cópia ───

test('ppa/interna — empresa, responsável, datas e ações entram como props do PlanoPpa', () => {
    assert.match(interna, /meta=\{metaDoPlano\(plano\)\}/);
    assert.match(interna, /acoes=\{acoesDoPlano\(plano\)\}/);
    assert.match(interna, /Criado \{plano\.criado_em\}/);
    assert.match(interna, /Atualizado \{plano\.atualizado_em\}/);
});

test('ppa/interna — a equipe não herda a trava de leitura do cliente', () => {
    // No portal, plano encerrado vira consulta. Internamente, quem encerrou foi
    // a própria equipe — impedi-la de reabrir seria uma trava sem dono.
    assert.match(interna, /somenteLeitura=\{false\}/);
    assert.doesNotMatch(portal, /somenteLeitura=\{/);
});

test('ppa/interna — o arraste usa a rota que responde JSON', () => {
    // `ppa.tasks.update` devolve Inertia e faria o quadro piscar a cada card.
    assert.match(interna, /route\('ppa\.tasks\.mover', tarefa\.id\)/);
    assert.doesNotMatch(interna, /route\('ppa\.tasks\.update'/);
});

// ─── 3. Os dois seletores ───

test('ppa/interna — situação e ordem são Selects, com sentinela em vez de vazio', () => {
    assert.match(interna, /const TODAS = 'todos'/);
    assert.match(interna, /const PADRAO = 'prioridade'/);
    assert.match(interna, /value=\{situacao \|\| TODAS\}/);
    assert.match(interna, /value=\{ordem \|\| PADRAO\}/);
    assert.match(interna, /v === TODAS \? '' : v/);
    assert.match(interna, /v === PADRAO \? '' : v/);
});

test('ppa/interna — a ordem é por ATUALIZAÇÃO, não por data exata', () => {
    // O intervalo "criado de/até" foi recusado em revisão: o pedido era "do
    // mais recente atualizado, ou dos mais antigos".
    assert.match(interna, /\{ valor: 'recente', titulo: 'Atualizados recentemente' \}/);
    assert.match(interna, /\{ valor: 'antigo',  titulo: 'Atualizados há mais tempo' \}/);
    assert.doesNotMatch(interna, /type="date"[\s\S]{0,200}aria-label="Criado/);
});

test('ppa/interna — as situações são as seções da lista mais "Vencidos"', () => {
    assert.match(interna, /\.\.\.GRUPOS\.map\(\(g\) => \(\{ valor: g\.chave, titulo: g\.titulo \}\)\)/);
    assert.match(interna, /\{ valor: 'vencido', titulo: 'Vencidos' \}/);

    // Viajam crus na URL e são lidos por `Ppa::SITUACOES` no PHP.
    assert.deepEqual(['vencido', ...GRUPOS.map((g) => g.chave)].sort(),
        ['andamento', 'concluido', 'fazer', 'vencido']);
});

// ─── 4. Quem ordena é o servidor ───

test('ppa/interna — a tela agrupa mas NÃO reordena o que veio do servidor', () => {
    // Reordenar aqui por prazo desfaria "atualizados recentemente", calado.
    assert.match(interna, /seccionar\(filtrados, \{ ordenar: false \}\)/);
});

test('ppa/interna — a paginação leva os filtros junto', () => {
    assert.match(interna, /const irParaPagina = \(page\) => router\.get\(route\(R\.index\), paramsDe\(\{ situacao, ordem \}, \{ page \}\)\)/);
    assert.doesNotMatch(interna, /router\.get\(route\(R\.index\), \{ page:/);
});

// ─── 5. A gaveta dos concluídos ───

test('ppa/interna — filtrar por "Concluídos" abre a gaveta que vem fechada', () => {
    assert.match(interna, /const soConcluidos = situacao === GRUPO_CONCLUIDO/);
    assert.match(interna, /const dobravel = ehConcluidos && !soConcluidos/);
});

test('ppa/interna — lista vazia POR FILTRO oferece a saída', () => {
    assert.match(interna, /temFiltro \? 'Nenhum PPA nestes filtros' : 'Nenhum PPA encontrado'/);
    assert.match(interna, /onClick=\{limparFiltros\}/);
});
