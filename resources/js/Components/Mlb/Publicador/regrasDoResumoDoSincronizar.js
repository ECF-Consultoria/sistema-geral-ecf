// Textos do resumo do "Sincronizar do Portal" (Fase 172-12; enxuto desde 09/10/2026). Funções puras, sem React.

const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;

const inteiro = (v) => {
    const n = Number(v ?? 0);

    return Number.isFinite(n) && n > 0 ? Math.trunc(n) : 0;
};

/**
 * A linha única do painel: "Sincronizado: 13 produtos, 20 variações, 0 fotos." — com
 * ", N campos atualizados" só quando o Portal mudou algo que ele mesmo tinha escrito, e as linhas
 * antigas de cor juntadas ao produto, na mesma linha, só quando houve. Sem "campos mantidos" e sem
 * avisos: o painel não é lugar de lista (os avisos vão para o log do servidor).
 *
 * `so_avisos` = o clique não tinha nada a preencher; sobra só o que foi juntado (ou "Sincronizado.").
 */
export function linhaDoResumo(resumo, absorvidos = 0) {
    const r = resumo ?? {};
    const juntadas = textoDosAbsorvidos(absorvidos);

    if (r.so_avisos) return juntadas ?? 'Sincronizado.';

    const partes = [
        plural(inteiro(r.produtos), 'produto', 'produtos'),
        plural(inteiro(r.variantes), 'variação', 'variações'),
        plural(inteiro(r.fotos_trazidas), 'foto', 'fotos'),
    ];
    const atualizados = inteiro(r.campos_atualizados);
    if (atualizados > 0) partes.push(plural(atualizados, 'campo atualizado', 'campos atualizados'));

    const frase = `Sincronizado: ${partes.join(', ')}.`;

    return juntadas ? `${frase} ${juntadas}` : frase;
}

/**
 * Linhas antigas de cor (uma por oferta, sem rascunho) que o Sincronizar removeu porque a cor já é
 * variante do produto agrupado. Zero ou ausente = nada a dizer (null).
 */
export function textoDosAbsorvidos(n) {
    const total = Number(n ?? 0);
    if (! Number.isFinite(total) || total <= 0) return null;

    return total === 1
        ? '1 linha antiga de cor foi juntada ao produto.'
        : `${total} linhas antigas de cor foram juntadas ao produto.`;
}
