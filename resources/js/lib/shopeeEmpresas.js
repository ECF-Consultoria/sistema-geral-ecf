// ─── Lente por grupo (carteira) da aba Empresas da Shopee ───────────────────
// Mora fora da página para poder ser testada (tests/js/shopeeEmpresas.test.js):
// é ela que decide o que as duas abas, os cards de pendência e a contagem do
// título passam a enxergar. Também mora aqui o estado do link de conexão OAuth
// da coluna "Conexão" (estadoDoLink).

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
 * Estado do link de conexão OAuth na coluna "Conexão" da aba Empresas.
 *
 * `gerado` é a resposta do `shopee.oauth.initiate` desta sessão ({url,
 * expires_at}) e vence o que veio do banco. Ler a validade só das props fazia o
 * "Regerar" não mudar nada na tela: o link novo era gravado, mas a célula seguia
 * "Link expirado". Link do banco já vencido não é oferecido para copiar — a
 * assinatura não vale mais e o cliente cairia na página de link vencido.
 *
 * @param {{shopee_link_url: ?string, shopee_link_generated_at: ?string, shopee_link_expires_at: ?string}} company
 * @param {?{url: string, expires_at: string}} gerado
 * @returns {{url: ?string, dias: ?number, vencido: boolean, pendente: boolean}}
 */
export function estadoDoLink(company, gerado = null, agora = new Date()) {
    // Link desta sessão nunca herda a validade velha das props.
    const expiraEm = gerado?.url ? (gerado.expires_at ?? null) : (company.shopee_link_expires_at ?? null);
    const dias     = expiraEm ? Math.ceil((new Date(expiraEm) - agora) / (1000 * 60 * 60 * 24)) : null;
    const temLink  = !! (gerado?.url || company.shopee_link_generated_at);
    const vencido  = temLink && dias !== null && dias <= 0;
    const url      = vencido ? null : (gerado?.url ?? company.shopee_link_url ?? null);

    return { url, dias, vencido, pendente: temLink && !! url };
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
