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

// ─── As 8 verificações e as 7 etapas da mesa ────────────────────────────────
//
// Fonte única: a mesa de anúncio e o hook `usePublicador` importam daqui.
// As VERIFICAÇÕES são do servidor (uma por grupo de regras); as ETAPAS são a
// navegação da tela (03/10/2026: um passo por vez, como o cliente pediu). Cada
// verificação se resolve em UMA etapa; a 7ª (revisar) não tem verificação — ela
// lê a conferência.
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

/** As etapas, na ordem do trilho. `curto` é o nome em tela estreita; `secoes` são as verificações que ela fecha. */
export const ETAPAS = [
    { chave: 'produto', titulo: 'Produto e categoria', curto: 'Produto', secoes: ['categoria'] },
    { chave: 'ficha', titulo: 'Ficha técnica', curto: 'Ficha', secoes: ['caracteristicas'] },
    { chave: 'variacoes', titulo: 'Variações e fotos', curto: 'Variações', secoes: ['variacoes', 'fotos', 'variantes'] },
    { chave: 'tipos', titulo: 'Título e preço', curto: 'Título e preço', secoes: ['tipos'] },
    { chave: 'logistica', titulo: 'Envio e garantia', curto: 'Envio', secoes: ['envio'] },
    { chave: 'descricao', titulo: 'Descrição', curto: 'Descrição', secoes: ['descricao'] },
    { chave: 'revisar', titulo: 'Revisar e publicar', curto: 'Revisar', secoes: [] },
];

export const ETAPA_INICIAL = ETAPAS[0].chave;

/** Em que etapa a verificação se resolve (ids dos painéis: `etapa-{valor}`). */
export const ETAPA_DA_SECAO = Object.fromEntries(ETAPAS.flatMap((e) => e.secoes.map((s) => [s, e.chave])));

/** Em que seção o problema se resolve; nulo = é da conta/conferência (vai para a revisão). */
export const secaoDoProblema = (p) => {
    const e = p.alvo?.etapa;
    if (e === 'E10' && ['preco', 'tipo'].includes(p.alvo?.campo)) return 'tipos';

    return SECOES.find((s) => s.etapas.includes(e))?.chave ?? null;
};

/** Em que etapa do trilho o problema se resolve; nulo = é da conta/conferência. */
export const etapaDoProblema = (p) => {
    const secao = secaoDoProblema(p);

    return secao ? (ETAPA_DA_SECAO[secao] ?? null) : null;
};

/** A etapa que fecha a etapa `E…` da spec (para o "ir para" das pendências da conferência). */
export const etapaDaEtapaMl = (etapaMl) => {
    const secao = SECOES.find((s) => s.etapas.includes(etapaMl))?.chave ?? null;

    return secao ? (ETAPA_DA_SECAO[secao] ?? null) : null;
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

/**
 * Estado de cada etapa do trilho a partir do das seções: `{ [chave]: { faltam, completo } }`.
 * A etapa de revisão não soma nada (ela lê a conferência, não as verificações).
 */
export const estadoDasEtapas = (secoes) => Object.fromEntries(ETAPAS.map((e) => {
    const faltam = e.secoes.reduce((n, s) => n + (secoes?.[s]?.faltam ?? 0), 0);

    return [e.chave, { faltam, completo: faltam === 0 }];
}));

/** Quantas etapas de conteúdo (todas menos a revisão) estão completas. */
export const contarEtapasCompletas = (secoes) => {
    const estados = estadoDasEtapas(secoes);

    return ETAPAS.filter((e) => e.secoes.length > 0 && estados[e.chave].completo).length;
};

export const TOTAL_ETAPAS_DE_CONTEUDO = ETAPAS.filter((e) => e.secoes.length > 0).length;

/** A etapa de uma chave qualquer (URL, link antigo): só as que existem; senão a inicial. */
export const etapaValida = (chave) => (ETAPAS.some((e) => e.chave === chave) ? chave : ETAPA_INICIAL);
