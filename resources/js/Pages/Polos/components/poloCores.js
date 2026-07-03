// Paleta de identidade por polo (chips, ranking, charts). Amarelo ECF primeiro.
// Extraído de Polos/Index.jsx para ser compartilhado com Polos/Painel.jsx — evita
// duplicar a lógica de cor (Restrição #8). NÃO alterar a ordem do array: a cor é
// posicional (índice do polo), então mudar a ordem muda as cores em telas existentes.
export const POLO_PALETTE = ['#ffe600', '#38bdf8', '#22c55e', '#a855f7', '#fb923c', '#f43f5e', '#2dd4bf', '#e879f9'];

/**
 * Mapa estável { polo: corHex } a partir da lista de polos.
 * A cor depende da POSIÇÃO no array recebido — o backend entrega `polos` em ordem
 * alfabética, garantindo cor estável entre renders. Uma segunda tela que passe os
 * polos em outra ordem terá outras cores (contrato posicional, idêntico ao Index).
 */
export function montarCorDoPolo(polos = []) {
    const mapa = {};
    (polos ?? []).forEach((p, i) => { mapa[p.polo] = POLO_PALETTE[i % POLO_PALETTE.length]; });
    return mapa;
}
