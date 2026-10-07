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

// ─── As 8 verificações e as 3 etapas do anúncio ─────────────────────────────
//
// Fonte única: o editor e o hook `usePublicador` importam daqui.
// As VERIFICAÇÕES são do servidor (uma por grupo de regras). As ETAPAS são o
// que a pessoa vê (04/10/2026): três, como no Mercado Livre — Produto,
// Detalhes e Condições de venda. Cada pendência do servidor cai na etapa onde
// se resolve; o "Continuar" só avança quando a etapa não tem bloqueio.
export const SECOES = [
    { chave: 'categoria', titulo: 'Categoria', etapas: ['E2'] },
    { chave: 'caracteristicas', titulo: 'Características', etapas: ['E3', 'E8'] },
    { chave: 'variacoes', titulo: 'Variações', etapas: ['E4'] },
    { chave: 'fotos', titulo: 'Fotos', etapas: ['E6'] },
    { chave: 'variantes', titulo: 'Estoque, SKU e código', etapas: ['E5'] },
    { chave: 'tipos', titulo: 'Título e preço', etapas: ['E7'] },
    { chave: 'envio', titulo: 'Envio, garantia e embalagem', etapas: ['E10'] },
    { chave: 'descricao', titulo: 'Descrição', etapas: ['E9'] },
];

/** Em que seção o problema se resolve; nulo = é da conta/conferência. */
export const secaoDoProblema = (p) => {
    const e = p.alvo?.etapa;
    if (e === 'E10' && ['preco', 'tipo'].includes(p.alvo?.campo)) return 'tipos';

    return SECOES.find((s) => s.etapas.includes(e))?.chave ?? null;
};

/**
 * Estado de cada seção: `{ [chave]: { faltam, completo } }` (o hook conta as prontas por aqui).
 * Sem schema, toda seção além da categoria conta como incompleta (falta 1).
 */
export const estadoDasSecoes = (problemas, schema) => Object.fromEntries(SECOES.map((s) => {
    if (! schema && s.chave !== 'categoria') return [s.chave, { faltam: 1, completo: false }];
    const faltam = (problemas ?? []).filter((p) => p.severidade === 'BLOCKER' && secaoDoProblema(p) === s.chave).length;

    return [s.chave, { faltam, completo: faltam === 0 }];
}));

/** Quantos bloqueios há numa lista de problemas. */
export const contarBloqueios = (problemas) => (problemas ?? []).filter((p) => p.severidade === 'BLOCKER').length;

export const ETAPAS = [
    { chave: 'produto', titulo: 'Produto' },
    { chave: 'detalhes', titulo: 'Detalhes' },
    { chave: 'imagens', titulo: 'Imagens' },
    { chave: 'condicoes', titulo: 'Condições de venda' },
];
export const ETAPA_INICIAL = ETAPAS[0].chave;

export const etapaValida = (chave) => (ETAPAS.some((e) => e.chave === chave) ? chave : null);
export const proximaEtapa = (chave) => ETAPAS[ETAPAS.findIndex((e) => e.chave === chave) + 1]?.chave ?? null;
export const etapaAnterior = (chave) => ETAPAS[ETAPAS.findIndex((e) => e.chave === chave) - 1]?.chave ?? null;
export const tituloDaEtapa = (chave) => ETAPAS.find((e) => e.chave === chave)?.titulo ?? chave;

/**
 * Em que etapa o problema se resolve:
 * - Produto: categoria (E2), condição (E3 campo `condicao`) e títulos (E7);
 * - Detalhes: variações (E4, E5), ficha técnica (E3, E8) e descrição (E9);
 * - Imagens: fotos (`alvo.grupo`/`alvo.imagem`, E6) — todo problema de foto, com ou sem grupo/imagem;
 * - Condições de venda: preço, envio, garantia e embalagem (E10) e o que é da conta ou da conferência (E0, E11, E13, sem etapa).
 */
export const etapaDoProblema = (p) => {
    const alvo = p.alvo ?? {};
    const e = alvo.etapa;
    if (alvo.grupo || alvo.imagem || e === 'E6') return 'imagens';
    if (e === 'E2' || e === 'E7' || (e === 'E3' && alvo.campo === 'condicao')) return 'produto';
    if (['E3', 'E4', 'E5', 'E8', 'E9'].includes(e)) return 'detalhes';

    return 'condicoes';
};

/**
 * O que impede a etapa de avançar: os bloqueios do servidor que caem nela e, sem categoria,
 * o aviso local (sem categoria o servidor não tem schema para validar nada).
 */
export const bloqueiosDaEtapa = (etapa, problemas, { temCategoria = true } = {}) => {
    const doServidor = (problemas ?? []).filter((p) => p.severidade === 'BLOCKER' && etapaDoProblema(p) === etapa);
    if (temCategoria) return doServidor;
    const mensagem = etapa === 'produto' ? 'Escolha a categoria do produto.' : 'Escolha a categoria na etapa Produto.';

    return [{ regra: 'LOCAL-CATEGORIA', severidade: 'BLOCKER', mensagem, alvo: { etapa: 'E2' } }, ...doServidor];
};
