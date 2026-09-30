/**
 * Fase 159 (D-08) — regra de visibilidade de item de menu quando a pessoa
 * tem mais de um cargo.
 *
 * Antes: o item sumia se QUALQUER papel efetivo (role do sistema + cargos de
 * publicação) estivesse em `excludeRoles` — "Alertas Estratégicos" (que
 * exclui `analista`) sumia até para quem também é estrategista. Decisão do
 * usuário: o item aparece se PELO MENOS UM cargo da pessoa tem acesso.
 *
 * Equivalência para quem tem um cargo só: é EXATAMENTE a regra antiga
 * ("esconde se o papel do sistema OU o cargo estiver excluído") — com um só
 * cargo na lista, "todos excluídos" e "o único excluído" são a mesma coisa.
 */

/**
 * @param {object}   params
 * @param {string[]} [params.excludeRoles] Papéis que este item exclui (item.excludeRoles).
 * @param {?string}  params.mainRole       Role do sistema do usuário (admin/consultor/mentor).
 * @param {string[]} [params.cargos]       Cargos de publicação do usuário (pubCargos).
 * @returns {boolean} true quando o item deve ficar OCULTO.
 */
export function itemOcultoPorPapel({ excludeRoles, mainRole, cargos = [] }) {
    if (!excludeRoles || excludeRoles.length === 0) {
        return false;
    }

    // O papel do SISTEMA (admin/consultor/mentor) continua excluindo sozinho —
    // não é um "cargo" que a pessoa possa somar a outro para ganhar acesso.
    if (mainRole && excludeRoles.includes(mainRole)) {
        return true;
    }

    // Cargo Dev é ortogonal a este gate (governa `modulos_ocultos`, não
    // `excludeRoles`) — não conta nem a favor nem contra a visibilidade aqui.
    const cargosRelevantes = (cargos ?? []).filter((c) => c && c !== 'dev');

    if (cargosRelevantes.length === 0) {
        return false;
    }

    // Oculta só quando TODOS os cargos da pessoa estão excluídos — um cargo
    // com acesso já basta para o item aparecer (D-08).
    return cargosRelevantes.every((c) => excludeRoles.includes(c));
}
