// ─── Trazer do Mercado Livre: regras da tela, sem React (28/10/2026) ────────
//
// Só apresentação: a prioridade, os problemas e os números vêm do servidor
// (DiagnosticoDoAnuncio). Aqui só se decide texto, cor e o que pode ser marcado.

export const PRIORIDADES = {
    alta: { rotulo: 'Vale trazer', classe: 'border-ecf-yellow/40 bg-ecf-yellow/[0.10] text-ecf-yellow' },
    media: { rotulo: 'Pode melhorar', classe: 'border-white/[0.14] bg-white/[0.05] text-white/80' },
    baixa: { rotulo: 'Está bom', classe: 'border-emerald-400/25 bg-emerald-400/[0.08] text-emerald-300' },
};

export const ORDENS = [
    { valor: 'piores', rotulo: 'Piores primeiro' },
    { valor: 'vendidos', rotulo: 'Mais vendidos' },
    { valor: 'visitas', rotulo: 'Mais visitas' },
];

export const SITUACOES = {
    fila: 'Na fila',
    importando: 'Trazendo',
    pronto: 'Pronto',
    ja_existe: 'Já estava no cadastro',
    erro: 'Não deu certo',
};

export const fmtInt = (n) => (n === null || n === undefined ? '—' : Number(n).toLocaleString('pt-BR'));

export const fmtPct = (n) => (n === null || n === undefined ? '—' : `${Number(n).toLocaleString('pt-BR', { maximumFractionDigits: n < 10 ? 1 : 0 })}%`);

/** O anúncio pode ser marcado: ainda não foi trazido e não está sendo trazido agora. */
export function podeMarcar(anuncio, estadoPorMlb = {}) {
    if (anuncio.importado) return false;
    const s = estadoPorMlb[anuncio.mlb]?.situacao;

    return s !== 'fila' && s !== 'importando' && s !== 'pronto';
}

/** Marca ou desmarca, respeitando o teto por envio. Devolve um Set novo. */
export function alternar(selecionados, mlb, teto) {
    const novo = new Set(selecionados);
    if (novo.has(mlb)) {
        novo.delete(mlb);
    } else if (novo.size < teto) {
        novo.add(mlb);
    }

    return novo;
}

/** Os da página que ainda dá para marcar, até completar o teto. */
export function marcarPagina(selecionados, anuncios, estadoPorMlb, teto) {
    const novo = new Set(selecionados);
    for (const a of anuncios) {
        if (novo.size >= teto) break;
        if (podeMarcar(a, estadoPorMlb)) novo.add(a.mlb);
    }

    return novo;
}

/** O resumo curto do cadastro do anúncio: título, Modelo e campos. */
export function resumoDoCadastro(d) {
    if (! d || d.erro) return [];
    const partes = [`Título ${d.titulo.chars}/${d.titulo.max}`];
    partes.push(d.modelo.vazio ? 'Modelo vazio' : (d.modelo.fora_do_padrao ? 'Modelo fora do padrão' : 'Modelo ok'));
    if (d.campos.total > 0) partes.push(`Campos ${d.campos.preenchidos} de ${d.campos.total}`);
    partes.push(`${d.fotos} ${d.fotos === 1 ? 'foto' : 'fotos'}`);

    return partes;
}

/**
 * Linha do painel de importação: o texto depois do título. Texto neutro de propósito: o cliente
 * vê que o produto foi criado e que a ficha foi completada — não o como.
 */
export function textoDoItem(item) {
    if (item.situacao === 'pronto') {
        if (item.campos_ia === null || item.campos_ia === undefined) return item.ia_erro ? 'Produto criado. A ficha fica para você completar.' : 'Produto criado. Completando a ficha técnica…';
        if (item.campos_ia === 0) return 'Produto criado com a ficha técnica do anúncio.';

        return `Produto criado. Completamos ${item.campos_ia} ${item.campos_ia === 1 ? 'campo' : 'campos'} da ficha técnica — confira.`;
    }

    return item.mensagem || SITUACOES[item.situacao] || '';
}
