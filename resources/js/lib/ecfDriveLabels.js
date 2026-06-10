/**
 * Dicionários pt-BR para termos do classificador interno do Mercado Livre.
 *
 * Extraído de Pages/PainelExecutivo/components/BreakdownTabs.jsx (Phase 24)
 * para reuso em outras phases (Phase 25 EmpresaHeader.jsx, Phase 27 futura, etc).
 *
 * Mantém o termo original entre parênteses quando a tradução direta
 * pode causar ambiguidade para quem já usa a nomenclatura oficial.
 */

// ─── Clusters (perfis internos do ML) ────────────────────────────────────────
export const CLUSTER_LABELS = {
    'Core':            'Núcleo maduro (Core)',
    'MeliPro':         'Premium (MeliPro)',
    'Emerging':        'Em desenvolvimento (Emerging)',
    'Newbie':          'Iniciante (Newbie)',
    'CrownJewels':     'Joia da coroa (CrownJewels)',
    'NKOB':            'Conta nova em profissionalização (NKOB)',
    'Seasonal':        'Sazonal (Seasonal)',
    'Stall':           'Estagnado (Stall)',
    'Churn':           'Saindo da plataforma (Churn)',
    'Starter/Newbie':  'Começando (Starter/Newbie)',
    'HobbySeller':     'Hobbysta (HobbySeller)',
    'SEM_CLUSTER':     'Não classificado',
};

// ─── Tipos de frete / modalidade de envio ────────────────────────────────────
// NOTA: estas modalidades NÃO são mutuamente exclusivas (um mesmo pedido pode
// contar em ME2 e em COLETAS/FULL). Por isso o breakdown de frete usa o `pct`
// da API (fatia sobre o faturamento total) e não recalcula como partição.
export const FRETE_LABELS = {
    'ME2':     'Mercado Envios padrão (ME2)',
    'FULL':    'Mercado Envios Full',
    'FLEX':    'Mercado Envios Flex',
    'COLETAS': 'Coletas',
    'PLACES':  'Mercado Envios Places',
    'OUTROS':  'Outros',
};

// ─── Programas ECF (CPP e POLOS) ─────────────────────────────────────────────
// 2026-06-06: removida a expansão "Comercial Pessoa Privada" — o significado
// real do acrônimo CPP é desconhecido (não é "Comercial Pessoa Privada").
// Manter apenas a sigla original até descobrirmos o nome real.
export const PROGRAMA_LABELS = {
    'CPP':   'CPP',
    'POLOS': 'POLOS',
};

/**
 * Aplica o dicionário pt-BR correto conforme a tab/contexto ativo.
 *
 * @param {string} tabKey  — 'cluster' | 'frete' | 'programa' (ou qualquer outro)
 * @param {string} valor   — valor bruto da API
 * @returns {string}       — label traduzido ou valor original se não encontrado
 */
export const traduzirItem = (tabKey, valor) => {
    if (!valor) return '—';
    if (tabKey === 'cluster')  return CLUSTER_LABELS[valor]  || valor;
    if (tabKey === 'frete')    return FRETE_LABELS[valor]    || valor;
    if (tabKey === 'programa') return PROGRAMA_LABELS[valor] || valor;
    return valor;
};
