// ─── Publicador: o que todas as partes da tela usam ─────────────────────────
//
// As rotas são todas por oferta (`portal.auth.publicador.*`) e TODA resposta
// traz o estado inteiro do rascunho — a tela nunca adivinha o que foi gravado.

export const rota = (nome, ofertaId, extra = {}) => route(`portal.auth.publicador.${nome}`, { oferta: ofertaId, ...extra });

export const mensagemDe = (e) => {
    if (e?.code === 'ECONNABORTED') return 'O servidor demorou demais. Recarregue a página e confira antes de tentar de novo.';
    const d = e?.response?.data;
    if (d?.errors) return Object.values(d.errors).flat()[0];

    return d?.message ?? 'Não foi possível concluir. Tente de novo.';
};

export const NOME_TIPO = { gold_special: 'Clássico', gold_pro: 'Premium' };
export const NOTA_TIPO = { gold_special: 'Menor comissão', gold_pro: 'Parcelado sem juros' };

// As etapas da spec (`02` §1) e a aba onde cada uma mora.
export const NOME_ETAPA = {
    E0: 'Conta do Mercado Livre', E2: 'Categoria', E3: 'Características principais', E4: 'Variações',
    E5: 'Dados das variações', E6: 'Fotos', E7: 'Título', E8: 'Ficha técnica', E9: 'Descrição',
    E10: 'Condições de venda', E11: 'Conferência', E13: 'Publicação', OUTROS: 'Outros avisos do Mercado Livre',
};
export const ABA_DA_ETAPA = {
    E0: 'revisao', E2: 'produto', E3: 'produto', E8: 'produto', E9: 'produto',
    E4: 'variacoes', E5: 'variacoes', E6: 'variacoes',
    E7: 'venda', E10: 'venda',
    E11: 'revisao', E13: 'revisao', OUTROS: 'revisao',
};

export const ABAS = [
    ['produto', '1 · Produto'],
    ['variacoes', '2 · Variações e fotos'],
    ['venda', '3 · Condições de venda'],
    ['revisao', '4 · Revisão e publicação'],
];

export const GERAL = 'GENERAL';

export const paraTexto = (n) => (n === null || n === undefined || n === '' ? '' : Number(n).toFixed(2).replace('.', ','));
export const paraNumero = (t) => {
    const s = String(t ?? '').trim().replace(/\s|R\$/g, '');
    if (s === '') return null;
    const n = Number(s.includes(',') ? s.replace(/\./g, '').replace(',', '.') : s);

    return Number.isFinite(n) ? n : null;
};

export const valorVazio = (v) => ! v || ((v.value_id ?? '') === '' && String(v.value_name ?? '').trim() === '' && (v.value_number ?? '') === '');

/** Problemas que apontam para um atributo (do produto ou de uma variante). */
export const problemasDoAtributo = (problemas, id, variante = null) => (problemas ?? []).filter((p) => p.alvo?.atributo === id && (variante === null || ! p.alvo?.variante || p.alvo.variante === variante));
