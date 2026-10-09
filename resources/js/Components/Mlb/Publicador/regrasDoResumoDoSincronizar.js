// Textos do resumo do "Sincronizar do Portal" (Fase 172-12). Funções puras, sem React.

const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;

/**
 * Frase do que veio do Portal: "2 produtos, 5 variações, 8 fotos trazidas; 3 campos mantidos
 * porque já estavam preenchidos." Singular correto com 1; a parte dos mantidos só aparece com mantidos.
 */
export function textoDoResumo(resumo) {
    const r = resumo ?? {};
    const produtos = Number(r.produtos ?? 0);
    const variantes = Number(r.variantes ?? 0);
    const fotos = Number(r.fotos_trazidas ?? 0);
    const mantidos = Number(r.campos_mantidos ?? 0);

    const trazido = [
        plural(produtos, 'produto', 'produtos'),
        plural(variantes, 'variação', 'variações'),
        `${plural(fotos, 'foto', 'fotos')} ${fotos === 1 ? 'trazida' : 'trazidas'}`,
    ].join(', ');

    if (mantidos <= 0) return `${trazido}.`;
    const verbo = mantidos === 1 ? 'mantido porque já estava preenchido' : 'mantidos porque já estavam preenchidos';
    return `${trazido}; ${mantidos} ${mantidos === 1 ? 'campo' : 'campos'} ${verbo}.`;
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

const MOTIVOS = {
    pequena: (n) => `${n} ${n === 1 ? 'foto pequena' : 'fotos pequenas'} demais (mínimo 500 px)`,
    formato: (n) => `${n} ${n === 1 ? 'foto em formato não aceito' : 'fotos em formato não aceito'}`,
    arquivo_sumido: (n) => `${n} ${n === 1 ? 'foto' : 'fotos'} cujo arquivo não foi encontrado`,
    acima_do_limite: (n) => `${n} ${n === 1 ? 'foto' : 'fotos'} além do limite de fotos do anúncio`,
    arquivo_grande: (n) => `${n} ${n === 1 ? 'foto' : 'fotos'} com arquivo grande demais`,
    dimensao_grande: (n) => `${n} ${n === 1 ? 'foto' : 'fotos'} com resolução grande demais (acima de 40 megapixels)`,
};

/** Lista de frases, uma por motivo; motivo desconhecido aparece com o código. */
export function motivosNaoTrazidas(mapa) {
    return Object.entries(mapa ?? {})
        .filter(([, n]) => Number(n) > 0)
        .map(([motivo, n]) => (MOTIVOS[motivo] ? MOTIVOS[motivo](Number(n)) : `${Number(n)} ${Number(n) === 1 ? 'foto' : 'fotos'} (${motivo})`));
}
