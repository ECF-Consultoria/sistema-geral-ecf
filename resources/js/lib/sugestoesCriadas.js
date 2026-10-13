// ═══════════════════════════════════════════════════════════════════════
// Planejamento, aba "Criadas": as palavras da lista (funções puras).
//
// Cada item vem do servidor com `pendencia` (a do preço, a mesma da tela de
// preços: sem custo, sem frete, impossível; nula = pronto) e `sem_calculo`
// quando o servidor não conseguiu calcular aquela oferta.
// ═══════════════════════════════════════════════════════════════════════

const PENDENCIAS = {
    sem_custo: 'Falta o custo',
    sem_frete: 'Falta o frete',
    impossivel: 'Preço impossível',
};

/** O selo do preço de uma oferta criada: `{ chave, texto, tom }`. */
export function rotuloDaPendencia(item) {
    if (! item || item.sem_calculo) return { chave: 'sem_calculo', texto: 'Sem preço calculado', tom: 'neutro' };
    if (! item.pendencia) return { chave: 'ok', texto: 'Com preço', tom: 'ok' };

    return { chave: item.pendencia, texto: PENDENCIAS[item.pendencia] ?? 'Falta o preço', tom: 'falta' };
}

/** "3 criadas" / "1 criada" / "Mostrando as 300 mais recentes de 412." */
export function textoDeQuantasCriadas(criadas) {
    const total = Number(criadas?.total) || 0;
    const mostrando = Number(criadas?.mostrando) || 0;
    if (total > mostrando) return `Mostrando as ${mostrando} mais recentes de ${total}. A lista inteira está na Precificação.`;

    return total === 1 ? '1 criada' : `${total} criadas`;
}
