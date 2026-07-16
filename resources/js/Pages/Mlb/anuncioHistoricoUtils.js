// ═══════════════════════════════════════════════════════════════════════
// Helpers puros do histórico de anúncios (módulo puro: sem React, sem I/O).
// ═══════════════════════════════════════════════════════════════════════

// ─── Link do anúncio no Mercado Livre ───
// O ML aceita a URL curta `produto.mercadolivre.com.br/MLB-<numero>`, e o id vem
// do banco ora como "MLB123" ora como "MLB-123" — daí o strip do prefixo antes de
// remontar. Formato herdado do wizard (commit 9e5a640, que corrigiu o link errado):
// esta função é a fonte única, para os dois não divergirem de novo.
export function linkAnuncioMl(mlItemId) {
    const id = String(mlItemId ?? '').trim();
    if (!id) return null;
    return `https://produto.mercadolivre.com.br/MLB-${id.replace(/^MLB-?/i, '')}`;
}

// ─── Rótulo do tipo de anúncio ───
// Mesmo par do resto do módulo (gradeMassaUtils/LABEL_TIER). Sem enum compartilhado
// entre PHP e JS — convenção do projeto, sincronizado à mão.
export const LABEL_TIER_HISTORICO = { gold_special: 'Clássico', gold_pro: 'Premium' };
export const rotuloTier = (t) => LABEL_TIER_HISTORICO[t] ?? (t || '—');

// ─── Preço em BRL (o payload guarda number ou string) ───
export function precoBRL(v) {
    const n = Number(v);
    if (!Number.isFinite(n) || n <= 0) return '—';
    return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

// ─── Data de publicação (ISO → dd/mm/aaaa) ───
// Sem date-fns aqui: o módulo é importado pelos testes em Node puro.
export function dataPublicacao(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('pt-BR');
}
