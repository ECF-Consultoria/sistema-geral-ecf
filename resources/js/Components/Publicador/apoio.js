// ─── Publicador: o que todas as partes da tela usam ─────────────────────────
//
// As rotas são todas por produto (`mlb.anuncios.publicador.*`) e TODA resposta
// traz o estado inteiro do rascunho — a tela nunca adivinha o que foi gravado.

/** Monta o gerador de rotas de um editor: `criarRota('mlb.anuncios.publicador', 'produto')('salvar', 7)`. */
export const criarRota = (prefixo, chave) => (nome, id, extra = {}) => route(`${prefixo}.${nome}`, { [chave]: id, ...extra });

export const mensagemDe = (e) => {
    if (e?.code === 'ECONNABORTED') return 'O servidor demorou demais. Recarregue a página e confira antes de tentar de novo.';
    const d = e?.response?.data;
    if (d?.errors) return Object.values(d.errors).flat()[0];

    return d?.message ?? 'Não foi possível concluir. Tente de novo.';
};

export const NOME_TIPO = { gold_special: 'Clássico', gold_pro: 'Premium' };
export const NOTA_TIPO = { gold_special: 'Menor comissão', gold_pro: 'Parcelado sem juros' };

// As etapas da spec (`02` §1).
export const NOME_ETAPA = {
    E0: 'Conta do Mercado Livre', E2: 'Categoria', E3: 'Características principais', E4: 'Variações',
    E5: 'Dados das variações', E6: 'Fotos', E7: 'Título', E8: 'Ficha técnica', E9: 'Descrição',
    E10: 'Condições de venda', E11: 'Conferência', E13: 'Publicação', OUTROS: 'Outros avisos do Mercado Livre',
};

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

// ─── As 8 verificações da lateral e os cards da mesa ────────────────────────
//
// Fonte única: a mesa de anúncio e o hook `usePublicador` importam daqui.
export const SECOES = [
    { chave: 'categoria', titulo: 'Categoria', etapas: ['E2'] },
    { chave: 'caracteristicas', titulo: 'Características', etapas: ['E3', 'E8'] },
    { chave: 'variacoes', titulo: 'Variações', etapas: ['E4'] },
    { chave: 'fotos', titulo: 'Fotos', etapas: ['E6'] },
    { chave: 'variantes', titulo: 'Estoque, SKU e código', etapas: ['E5'] },
    { chave: 'tipos', titulo: 'Clássico e Premium', etapas: ['E7'] },
    { chave: 'envio', titulo: 'Envio, garantia e embalagem', etapas: ['E10'] },
    { chave: 'descricao', titulo: 'Descrição', etapas: ['E9'] },
];

/** Em que card a seção se resolve (ids dos cards: `card-{valor}`). As fotos ficam nas variações (03/10/2026). */
export const CARD_DA_SECAO = {
    categoria: 'produto', caracteristicas: 'ficha', variacoes: 'variacoes', variantes: 'variacoes',
    fotos: 'variacoes', tipos: 'tipos', envio: 'logistica', descricao: 'descricao',
};

/** Em que seção o problema se resolve; nulo = é da conta/conferência (vai para a lateral). */
export const secaoDoProblema = (p) => {
    const e = p.alvo?.etapa;
    if (e === 'E10' && ['preco', 'tipo'].includes(p.alvo?.campo)) return 'tipos';

    return SECOES.find((s) => s.etapas.includes(e))?.chave ?? null;
};

/**
 * Estado de cada seção para o chip do card: `{ [chave]: { faltam, completo } }`.
 * Sem schema, toda seção além da categoria conta como incompleta (falta 1).
 */
export const estadoDasSecoes = (problemas, schema) => Object.fromEntries(SECOES.map((s) => {
    if (! schema && s.chave !== 'categoria') return [s.chave, { faltam: 1, completo: false }];
    const faltam = (problemas ?? []).filter((p) => p.severidade === 'BLOCKER' && secaoDoProblema(p) === s.chave).length;

    return [s.chave, { faltam, completo: faltam === 0 }];
}));
