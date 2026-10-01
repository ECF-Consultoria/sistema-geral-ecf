/**
 * Fase 159 (D-08) — regra de visibilidade de item de menu quando a pessoa
 * tem mais de um cargo.
 *
 * Regra (restrita pelo WR-10 da revisão da fase): o item SOME se o papel do
 * sistema OU QUALQUER cargo da pessoa estiver em `excludeRoles` — a mesma
 * regra de antes da fase —, com UMA exceção: um cargo de Desempenho
 * (`analista`/`estrategista`) excluído NÃO esconde o item quando a pessoa
 * também tem o OUTRO cargo de Desempenho e esse outro não está excluído.
 * Ex.: "Alertas Estratégicos" exclui `analista`; quem é analista E
 * estrategista continua vendo, porque como estrategista tem acesso.
 *
 * Por que a exceção é só entre os dois cargos de Desempenho: `pubCargos`
 * (AppLayout) traz os cargos de TODOS os setores. A primeira versão de D-08
 * ("aparece se pelo menos um cargo tem acesso") devolvia "Alertas
 * Estratégicos" a qualquer publicador com um cargo a mais em outro setor — e
 * o `excludeRoles` é o ÚNICO gate dessa rota para o publicador (role
 * `consultor`). A decisão do usuário foi sobre a pessoa com os dois cargos de
 * Desempenho, não sobre qualquer combinação.
 *
 * Para quem tem um cargo só, é exatamente a regra antiga.
 */

/** Os dois cargos de Desempenho — a única dupla em que um cargo "compensa" o outro. */
const CARGOS_DESEMPENHO = ['analista', 'estrategista'];

/**
 * @param {object}   params
 * @param {string[]} [params.excludeRoles] Papéis que este item exclui (item.excludeRoles).
 * @param {?string}  params.mainRole       Role do sistema do usuário (admin/consultor/mentor).
 * @param {string[]} [params.cargos]       Cargos do usuário, de todos os setores (pubCargos).
 * @returns {boolean} true quando o item deve ficar OCULTO.
 */
export function itemOcultoPorPapel({ excludeRoles, mainRole, cargos = [] }) {
    if (!excludeRoles || excludeRoles.length === 0) {
        return false;
    }

    // O papel do SISTEMA (admin/consultor/mentor) exclui sozinho — não é um
    // "cargo" que a pessoa possa somar a outro para ganhar acesso.
    if (mainRole && excludeRoles.includes(mainRole)) {
        return true;
    }

    const cargosDaPessoa = new Set((cargos ?? []).filter(Boolean));

    for (const cargo of cargosDaPessoa) {
        if (!excludeRoles.includes(cargo)) {
            continue;
        }

        // Exceção D-08: analista excluído é compensado pelo estrategista com
        // acesso (e vice-versa). Nenhum outro cargo compensa.
        if (CARGOS_DESEMPENHO.includes(cargo)) {
            const outro = CARGOS_DESEMPENHO.find((c) => c !== cargo);
            if (cargosDaPessoa.has(outro) && !excludeRoles.includes(outro)) {
                continue;
            }
        }

        return true;
    }

    return false;
}
