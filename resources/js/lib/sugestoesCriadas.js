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

/**
 * Quantas da lista já têm preço e quantas têm alguma pendência (16/10/2026): a linha do topo da aba,
 * contada sobre o que está NA TELA (acima do teto, as mais recentes). `{ comPreco, pendentes }`.
 */
export function resumoDasCriadas(criadas) {
    const itens = (Array.isArray(criadas?.grupos) ? criadas.grupos : []).flatMap((g) => g.itens ?? []);
    const comPreco = itens.filter((i) => rotuloDaPendencia(i).chave === 'ok').length;

    return { comPreco, pendentes: itens.length - comPreco };
}

/**
 * O tamanho, em px, de cada foto da vitrine do cartão, pelo número de peças mostradas: uma foto
 * grande no Combo, duas lado a lado no Kit, e menores conforme o kit cresce (até 6 componentes).
 */
export function tamanhoDaPeca(quantas) {
    if (quantas <= 1) return 132;
    if (quantas === 2) return 108;
    if (quantas === 3) return 76;

    return 56;
}

/** As peças da vitrine: até 3 componentes com foto e, passando disso, quantos ficaram de fora ("+2"). */
export function pecasDaVitrine(composicao, maximo = 3) {
    const lista = Array.isArray(composicao) ? composicao : [];
    if (lista.length <= maximo + 1) return { pecas: lista, resto: 0 };

    return { pecas: lista.slice(0, maximo), resto: lista.length - maximo };
}

/** "3 criadas" / "1 criada" / "Mostrando as 300 mais recentes de 412." */
export function textoDeQuantasCriadas(criadas) {
    const total = Number(criadas?.total) || 0;
    const mostrando = Number(criadas?.mostrando) || 0;
    if (total > mostrando) return `Mostrando as ${mostrando} mais recentes de ${total}. A lista inteira está na Precificação.`;

    return total === 1 ? '1 criada' : `${total} criadas`;
}
