// ─── Lente por grupo (carteira) da aba Empresas da Shopee ───────────────────
// Mora fora da página para poder ser testada (tests/js/shopeeEmpresas.test.js):
// é ela que decide o que as duas abas, os cards de pendência e a contagem do
// título passam a enxergar.

/** Valor sentinela do select para "empresas que não estão em grupo nenhum". */
export const SEM_GRUPO = 'sem';

/**
 * Aplica a lente de grupo sobre a lista de empresas.
 *
 * `grupoFilter` vem do <select>, então é sempre STRING; `company_group_id` vem
 * do backend como número. A comparação é feita em texto de propósito — comparar
 * os dois com === direto nunca casaria.
 *
 * @param {Array<{company_group_id: ?number}>} empresas
 * @param {string} grupoFilter '' = todos, SEM_GRUPO = sem grupo, senão o id do grupo
 */
export function filtrarPorGrupo(empresas = [], grupoFilter = '') {
    if (! grupoFilter) {
        return empresas;
    }

    if (grupoFilter === SEM_GRUPO) {
        return empresas.filter(c => ! c.company_group_id);
    }

    return empresas.filter(c => String(c.company_group_id) === String(grupoFilter));
}

/**
 * Options do select: grupo + quantas empresas DESTA lista ele tem.
 *
 * A contagem sai da lista recebida (as empresas Shopee da tela), nunca do
 * `companies_count` que o backend manda junto de cada grupo — aquele conta a
 * carteira inteira, ML incluído, e mostraria um número que não bate com o que
 * aparece na tabela. Grupo sem nenhuma empresa aqui fica de fora da lista.
 *
 * @returns {{opcoes: Array<{id: number, name: string, qtd: number}>, semGrupo: number}}
 */
export function opcoesDeGrupo(empresas = [], grupos = []) {
    const opcoes = grupos
        .map(g => ({
            ...g,
            qtd: empresas.filter(c => String(c.company_group_id) === String(g.id)).length,
        }))
        .filter(g => g.qtd > 0);

    return {
        opcoes,
        semGrupo: empresas.filter(c => ! c.company_group_id).length,
    };
}
