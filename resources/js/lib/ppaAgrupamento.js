// ─── A régua de "qual plano merece atenção primeiro" ────────────────────────
//
// Uma régua só, usada pelas DUAS telas de lista de PPA: o Portal do Cliente
// (`Pages/Portal/Ppa.jsx`) e a lista interna (`Pages/Ppa/Index.jsx`, que o PPA
// Polos re-exporta). Elas mostram públicos diferentes, mas respondem à mesma
// pergunta — "o que está andando, o que está parado e o que já acabou" — e
// duas implementações da mesma pergunta viram, no primeiro ajuste, dois
// critérios que discordam na tela.
//
// ### Isto NÃO é um status novo
// Nada aqui vai para o banco. `ppas.status` (draft/sent/completed) e
// `ppa_tasks.status` (todo/doing/done) continuam sendo a verdade, e o grupo é
// LIDO deles a cada render. Nenhuma coluna, nenhum campo, nenhuma migration.
//
// ### Por que "100% feito" cai em concluído mesmo sem a equipe encerrar
// `ppas.status = completed` é um ato da equipe, e ela nem sempre volta para
// marcar. Um plano com todas as tarefas em `done` não tem mais nada que o
// cliente possa fazer — deixá-lo ocupando o topo da tela empurraria para baixo
// justamente o trabalho vivo. Ele desce para a seção recolhida, mas continua
// EDITÁVEL quando aberto (só o encerrado pela equipe vira leitura, que é a
// regra que já existia). Ver `somenteLeitura` em `PlanoPpa`.

export const GRUPO_ANDAMENTO = 'andamento';
export const GRUPO_FAZER     = 'fazer';
export const GRUPO_CONCLUIDO = 'concluido';

/** Ordem em que as seções aparecem na tela, e o peso usado para ordenar. */
export const GRUPOS = [
    {
        chave: GRUPO_ANDAMENTO,
        titulo: 'Em andamento',
        descricao: 'Planos com tarefa já iniciada — é aqui que o trabalho está.',
    },
    {
        chave: GRUPO_FAZER,
        titulo: 'A fazer',
        descricao: 'Planos com tarefas ainda não iniciadas.',
    },
    {
        chave: GRUPO_CONCLUIDO,
        titulo: 'Concluídos',
        descricao: 'Planos sem nada pendente.',
    },
];

const PESO = { [GRUPO_ANDAMENTO]: 0, [GRUPO_FAZER]: 1, [GRUPO_CONCLUIDO]: 2 };

/**
 * As quatro contagens de um plano a partir das tarefas.
 *
 * Aceita a lista viva da tela (o estado local que o arraste altera), e não um
 * número pronto do servidor — é isso que faz o rótulo "2 em andamento" mudar
 * no mesmo instante em que o card muda de coluna.
 */
export function contarTarefas(tarefas = []) {
    let feitas = 0;
    let fazendo = 0;

    for (const t of tarefas) {
        if (t.status === 'done') feitas++;
        else if (t.status === 'doing') fazendo++;
    }

    const total = tarefas.length;

    return { total, feitas, fazendo, aFazer: total - feitas - fazendo };
}

/** Percentual concluído. Plano sem tarefa é 0% — e não 100% por divisão vazia. */
export function percentual({ total, feitas }) {
    return total > 0 ? Math.round((feitas / total) * 100) : 0;
}

/**
 * O grupo de um plano.
 *
 * @param {{concluido?: boolean, total: number, feitas: number, fazendo: number}} contagem
 *   `concluido` é o encerramento pela equipe (`ppas.status === 'completed'`).
 */
export function grupoDoPlano({ concluido = false, total = 0, feitas = 0, fazendo = 0 }) {
    if (concluido) return GRUPO_CONCLUIDO;
    if (total > 0 && feitas === total) return GRUPO_CONCLUIDO;
    if (fazendo > 0) return GRUPO_ANDAMENTO;

    // Plano sem tarefa nenhuma cai aqui de propósito. No portal ele é um plano
    // que a equipe ainda está montando; na lista interna, um que pede tarefas.
    // Nos dois casos o lugar dele é junto do que ainda não começou, nunca
    // junto do que acabou.
    return GRUPO_FAZER;
}

/**
 * Ordena os planos: grupo primeiro, urgência do prazo depois.
 *
 * `prazoDias` é a distância em dias até `ppas.due_date` (negativo = atrasado),
 * calculada no servidor para o fuso do navegador não virar um dia de
 * diferença. Plano sem prazo vai para o fim do grupo — ausência de prazo não é
 * urgência, mas também não pode jogá-lo na frente de quem tem data marcada.
 *
 * Entre concluídos a urgência não significa mais nada: eles mantêm a ordem em
 * que chegaram do servidor (mais recente primeiro).
 *
 * `Array.prototype.sort` é estável desde o ES2019, então empate preserva a
 * ordem de origem — é o que mantém o critério do backend valendo dentro do
 * grupo.
 */
export function ordenarPlanos(planos) {
    return [...planos].sort((a, b) => {
        const pesoA = PESO[a.grupo] ?? 9;
        const pesoB = PESO[b.grupo] ?? 9;
        if (pesoA !== pesoB) return pesoA - pesoB;

        if (a.grupo === GRUPO_CONCLUIDO) return 0;

        const diasA = Number.isFinite(a.prazoDias) ? a.prazoDias : Infinity;
        const diasB = Number.isFinite(b.prazoDias) ? b.prazoDias : Infinity;
        return diasA - diasB;
    });
}

/**
 * Quebra a lista nas seções da tela, na ordem de `GRUPOS`.
 *
 * Devolve SEMPRE as três seções, inclusive vazias: quem chama decide se some
 * com a vazia (o portal some) ou se a mostra (a lista interna mostra "nenhum",
 * porque ali a ausência é informação de trabalho).
 *
 * @param {Array<{grupo: string}>} planos já anotados por `anotarPlano`
 */
export function seccionar(planos, { ordenar = true } = {}) {
    // `ordenar: false` quando o SERVIDOR já escolheu a ordem — é o caso da
    // lista interna, onde quem ordena é `Ppa::scopeOrdenadoPorAtencao()` e o
    // usuário pode pedir "atualizados recentemente". Reordenar aqui por prazo
    // desfaria, calado, a escolha dele.
    const ordenados = ordenar ? ordenarPlanos(planos) : planos;

    return GRUPOS.map((g) => ({
        ...g,
        planos: ordenados.filter((p) => p.grupo === g.chave),
    }));
}

/**
 * O selo de prazo. `null` quando não há prazo ou quando ele ainda está longe —
 * data distante não precisa de selo, a data crua já diz o que precisa dizer.
 *
 * `encerrado` silencia o selo: plano fechado não fica gritando atraso sobre
 * trabalho que ninguém mais espera. É a mesma regra que
 * `PpaQuadroService::prazo()` aplica no quadro interno.
 */
export function seloPrazo(dias, { encerrado = false } = {}) {
    if (encerrado || !Number.isFinite(dias)) return null;

    if (dias < 0) {
        const n = Math.abs(dias);
        return { tom: 'atrasado', texto: n === 1 ? 'Atrasado há 1 dia' : `Atrasado há ${n} dias` };
    }
    if (dias === 0) return { tom: 'hoje', texto: 'Vence hoje' };
    if (dias <= 7) return { tom: 'proximo', texto: dias === 1 ? 'Vence amanhã' : `Vence em ${dias} dias` };

    return null;
}

/**
 * O resumo de uma linha que o plano recolhido mostra — "2 em andamento · 1 a
 * fazer". Só entra o que existe: um plano sem nada a fazer não ganha
 * "0 a fazer", que seria ruído com cara de dado.
 */
export function resumoTarefas({ total, feitas, fazendo, aFazer }) {
    if (total === 0) return 'Sem tarefas ainda';

    const partes = [];
    if (fazendo > 0) partes.push(`${fazendo} em andamento`);
    if (aFazer > 0) partes.push(`${aFazer} a fazer`);
    if (partes.length === 0) return `${feitas} de ${total} concluídas`;

    partes.push(`${feitas} de ${total} concluídas`);
    return partes.join(' · ');
}

// ─── Os filtros da lista, iguais nas duas telas ─────────────────────────────
//
// Os rótulos e os valores vivem aqui porque as DUAS telas de PPA filtram: a
// interna (`Pages/Ppa/Index.jsx`) e a do cliente (`Pages/Portal/Ppa.jsx`).
// Duas listas de rótulos divergem no primeiro ajuste, e aí o mesmo filtro passa
// a se chamar diferente de cada lado.
//
// ### Os valores atravessam para o PHP
// Eles viajam crus na URL da lista interna e são lidos por `Ppa::SITUACOES` e
// `Ppa::ORDENS`. Renomear aqui sem renomear lá faz o filtro devolver a lista
// inteira, calado — o scope trata valor desconhecido como "sem filtro".
//
// ### Sentinelas, e não string vazia
// `value=""` num Select do Radix apaga a tela inteira (já aconteceu neste
// projeto). O vazio só existe na URL.

export const TODAS_SITUACOES = 'todos';
export const ORDEM_PADRAO    = 'prioridade';
export const SITUACAO_VENCIDO = 'vencido';

export const SITUACOES_PPA = [
    { valor: TODAS_SITUACOES,  titulo: 'Todas as situações' },
    // "Vencidos" não é um grupo: um plano vencido continua estando em andamento
    // ou a fazer. Ele ATRAVESSA as seções em vez de substituí-las.
    { valor: SITUACAO_VENCIDO, titulo: 'Vencidos' },
    ...GRUPOS.map((g) => ({ valor: g.chave, titulo: g.titulo })),
];

export const ORDENS_PPA = [
    { valor: ORDEM_PADRAO, titulo: 'Prioridade (prazo)' },
    { valor: 'recente',    titulo: 'Atualizados recentemente' },
    { valor: 'antigo',     titulo: 'Atualizados há mais tempo' },
];

/**
 * Recorta os planos por situação — o espelho, em JS, de `Ppa::scopeDaSituacao`.
 *
 * NÃO é uma quarta implementação da régua de agrupamento: o grupo de cada plano
 * já foi decidido por {@see grupoDoPlano}, e aqui só se compara. "Vencido" olha
 * `prazoDias`, que o SERVIDOR calcula (`Ppa::diasAteOPrazo`) e que já vem nulo
 * em plano encerrado — é o que mantém o recorte igual ao do SQL sem repetir a
 * regra.
 *
 * Existe para a tela do CLIENTE, que não pagina e por isso filtra no navegador.
 * A lista interna pagina de 20 em 20 e filtra no banco: recortar só a página
 * mostraria "3 vencidos" para quem tem 19 nas páginas seguintes.
 *
 * @param {Array<{grupo: string, prazoDias: ?number}>} planos já anotados
 */
export function filtrarPorSituacao(planos, situacao) {
    if (!situacao || situacao === TODAS_SITUACOES) return planos;

    if (situacao === SITUACAO_VENCIDO) {
        return planos.filter((p) => Number.isFinite(p.prazoDias) && p.prazoDias < 0);
    }

    return planos.filter((p) => p.grupo === situacao);
}

/**
 * Ordena por quando mexeram no plano pela última vez.
 *
 * `atualizado_iso` vem pronto do servidor (`Ppa::atualizadoEm()`, que conta
 * mexida em TAREFA) porque o mesmo cálculo aqui usaria o fuso do navegador.
 * Ordem desconhecida — ou a padrão — devolve a lista como estava, para
 * `seccionar` aplicar a régua de atenção.
 *
 * O GRUPO continua mandando: quem separa em seções é `seccionar`, e ele é
 * chamado depois. Um concluído mexido agora não pula na frente de um plano
 * andando.
 */
export function ordenarPorAtualizacao(planos, ordem) {
    if (ordem !== 'recente' && ordem !== 'antigo') return planos;

    const sentido = ordem === 'recente' ? -1 : 1;

    return [...planos].sort((a, b) => sentido * String(a.atualizado_iso ?? '')
        .localeCompare(String(b.atualizado_iso ?? '')));
}

/**
 * Os quatro números do topo, a partir do estado VIVO das tarefas.
 *
 * Vive aqui, e não em cada tela, porque o Portal e a lista interna mostram os
 * MESMOS quatro números — e "tarefas em andamento" tem de querer dizer a mesma
 * coisa nos dois lados para uma conversa entre equipe e cliente fazer sentido.
 *
 * Tarefa de plano ENCERRADO não entra nas pendências: a equipe fechou o plano,
 * e um número teimando ali mandaria perseguir algo que ninguém mais espera. É a
 * mesma regra do badge do menu (`PortalPpaService::pendentes()`).
 *
 * @param {Array<{id: number|string, grupo: string, prazoDias: ?number}>} planos
 * @param {Record<string|number, Array<{status: string}>>} tarefasPorPlano
 */
export function totaisDosPlanos(planos, tarefasPorPlano) {
    let fazendo = 0, aFazer = 0, feitas = 0, total = 0, atrasados = 0, concluidos = 0;

    for (const plano of planos) {
        const c = contarTarefas(tarefasPorPlano[plano.id] ?? []);
        total  += c.total;
        feitas += c.feitas;

        if (plano.grupo === GRUPO_CONCLUIDO) {
            concluidos++;
            continue;
        }

        fazendo += c.fazendo;
        aFazer  += c.aFazer;
        if (Number.isFinite(plano.prazoDias) && plano.prazoDias < 0) atrasados++;
    }

    return { fazendo, aFazer, feitas, total, atrasados, concluidos, pct: percentual({ total, feitas }) };
}

/**
 * Quais planos começam abertos.
 *
 * Só o PRIMEIRO de cada grupo ativo — o mais urgente de "Em andamento" e o
 * mais urgente de "A fazer". Abrir todos os ativos traria de volta o problema
 * que esta tela existe para resolver: com oito planos em andamento, oito
 * quadros de três colunas empilhados são o mesmo scroll infinito de antes, só
 * que mais bem organizado. Concluído nunca abre sozinho.
 *
 * @param {Array<{id: number|string, grupo: string}>} planos
 * @returns {Set<number|string>}
 */
export function abertosPorPadrao(planos) {
    const abertos = new Set();

    for (const grupo of [GRUPO_ANDAMENTO, GRUPO_FAZER]) {
        const primeiro = ordenarPlanos(planos).find((p) => p.grupo === grupo);
        if (primeiro) abertos.add(primeiro.id);
    }

    return abertos;
}
