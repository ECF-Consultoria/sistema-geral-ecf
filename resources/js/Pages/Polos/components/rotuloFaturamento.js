/**
 * Rótulos do card de faturamento do Modo TV (telas Faturamento e Tarefas).
 *
 * O número do card é o TOTAL do projeto, M1–M4 (Σ `cockpit.faturamentoPorFase`, pedido de
 * 30/09). A meta continua sobre M2–M4 (learnings painel-polos §14). No Painel isso fica em dois
 * cards ("M1–M4" e "% Geral da meta · base M2–M4"); na TV os dois viraram um card só, e a única
 * etiqueta que sobrava era "M2–M4: X% da meta" — lida como se o total fosse M2–M4 (TKT-0038).
 * Por isso o título diz M1–M4 e a linha de apoio diz de qual base é a meta.
 */

export const TITULO_FATURAMENTO_TOTAL = 'Faturamento total · M1–M4';

/**
 * @param {number} pct           % da meta (base M2–M4), já arredondado
 * @param {string} metaFormatada meta em R$, já formatada (ex.: "R$ 3,20M")
 */
export function apoioDaMeta(pct, metaFormatada) {
    return `Meta (M2–M4): ${pct}% de ${metaFormatada}`;
}
