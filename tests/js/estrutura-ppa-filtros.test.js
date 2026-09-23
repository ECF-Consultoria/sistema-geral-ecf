import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { lerSemComentarios } from './_fonte.js';
import { GRUPOS, ORDENS_PPA, SITUACOES_PPA } from '../../resources/js/lib/ppaAgrupamento.js';

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

test('ppa/interna — não há link para o "quadro completo" em lugar nenhum', () => {
    // Desligado a pedido ("não vou usar isso, ninguém vai"). A página e a rota
    // continuam existindo; o que não pode voltar é o caminho até elas.
    assert.doesNotMatch(interna, /ppa\.kanban|R\.kanban|LayoutDashboard/);
});

test('ppa/interna — a criação de tarefa veio junto, senão o módulo fica sem ela', () => {
    // O "quadro completo" era o ÚNICO lugar que criava tarefa. Tirar o link sem
    // trazer a criação deixaria o PPA sem como adicionar uma ação.
    assert.match(interna, /route\('ppa\.tasks\.store', plano\.id\)/);
    assert.match(interna, /Adicionar tarefa/);
    assert.match(interna, /rodape=\{rodapeDoPlano\(plano\)\}/);

    // O cliente move cards, mas não cria: o rodapé é só do lado interno.
    assert.doesNotMatch(portal, /rodape=/);
});

test('ppa/interna — a tarefa nova aparece sem fechar o plano', () => {
    // `preserveState` mantém o plano aberto; quem traz a tarefa para a tela é o
    // efeito que re-semeia as tarefas quando os props chegam. Sem ele, o estado
    // local continuaria o de antes e a tarefa só apareceria no F5.
    assert.match(interna, /useEffect\(\(\) => \{/);
    assert.match(interna, /setTarefasPorPlano\(Object\.fromEntries/);
    assert.match(interna, /\}, \[linhas\]\)/);

    // `?? []` criaria um array novo por render e o efeito giraria em falso.
    assert.match(interna, /const SEM_PLANOS = \[\]/);
    assert.match(interna, /const linhas = ppas\.data \?\? SEM_PLANOS/);
});

// ─── 3. Os dois seletores ───

test('ppa — situação e ordem são Selects, com sentinela em vez de vazio', () => {
    // `value=""` num Select do Radix apaga a tela inteira. As sentinelas vivem
    // na lib; o vazio só existe na URL, do lado do PHP.
    for (const fonte of [interna, portal]) {
        assert.match(fonte, /value=\{situacao \|\| TODAS_SITUACOES\}/);
        assert.match(fonte, /value=\{ordem \|\| ORDEM_PADRAO\}/);
        assert.match(fonte, /TODAS_SITUACOES \? '' : v/);
        assert.match(fonte, /ORDEM_PADRAO \? '' : v/);
    }
});

test('ppa/interna — a ordem é por ATUALIZAÇÃO, não por data exata', () => {
    // O intervalo "criado de/até" foi recusado em revisão: o pedido era "do
    // mais recente atualizado, ou dos mais antigos".
    assert.deepEqual(ORDENS_PPA.map((o) => o.valor), ['prioridade', 'recente', 'antigo']);
    assert.doesNotMatch(interna, /type="date"[\s\S]{0,200}aria-label="Criado/);
});

test('ppa/interna — as situações são as seções da lista mais "Vencidos"', () => {
    // Viajam crus na URL e são lidos por `Ppa::SITUACOES` no PHP.
    assert.deepEqual(SITUACOES_PPA.map((x) => x.valor),
        ['todos', 'vencido', ...GRUPOS.map((g) => g.chave)]);
    assert.deepEqual(GRUPOS.map((g) => g.chave), ['andamento', 'fazer', 'concluido']);
});

// ─── 3b. O cliente também filtra (23/09/2026) ───

test('ppa/portal — a tela do cliente tem os dois seletores', () => {
    assert.match(portal, /aria-label="Filtrar por situação"/);
    assert.match(portal, /aria-label="Ordenar os planos"/);
    assert.match(portal, /value=\{situacao \|\| TODAS_SITUACOES\}/);
    assert.match(portal, /value=\{ordem \|\| ORDEM_PADRAO\}/);
});

test('ppa/portal — os rótulos vêm da lib, não de uma segunda lista', () => {
    // Duas listas de rótulos divergem no primeiro ajuste, e aí o mesmo filtro
    // passa a se chamar diferente de cada lado.
    for (const fonte of [interna, portal]) {
        assert.match(fonte, /SITUACOES_PPA/);
        assert.match(fonte, /ORDENS_PPA/);
        // A lista local de cada página foi embora.
        assert.doesNotMatch(fonte, /^const SITUACOES = \[/m);
        assert.doesNotMatch(fonte, /^const ORDENS = \[/m);
    }
});

test('ppa/portal — lá o filtro é do NAVEGADOR; aqui, do servidor', () => {
    // O portal recebe todos os planos do cliente de uma vez e não pagina, então
    // filtrar no navegador é instantâneo e correto. A lista interna pagina de
    // 20 em 20: recortar só a página mostraria "3 vencidos" para quem tem 19
    // nas seguintes.
    assert.match(portal, /filtrarPorSituacao\(planos, situacao\)/);
    assert.doesNotMatch(portal, /router\.(get|visit)/);

    assert.match(interna, /router\.get\(route\(R\.index\), paramsDe\(proximo\)/);
    assert.doesNotMatch(interna, /filtrarPorSituacao/);
});

test('ppa/portal — a ordem escolhida não é desfeita por `seccionar`', () => {
    assert.match(portal, /seccionar\(ordenarPorAtualizacao\(filtrados, ordem\), \{ ordenar: !ordem \}\)/);
});

test('ppa/portal — a busca deixou de sumir com poucos planos', () => {
    // Com os seletores ao lado, esconder a busca deixaria a linha pela metade.
    assert.doesNotMatch(portal, /ppas\.length > 3/);
});

test('ppa/portal — ordenar por atualização exige a data no payload e na tela', () => {
    // Ordenar por um critério invisível deixa o cliente sem como conferir o que
    // a lista acabou de fazer.
    assert.match(portal, /Atualizado \{plano\.atualizado_em\}/);
    assert.match(portal, /meta=\{meta\(plano\)\}/);
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
