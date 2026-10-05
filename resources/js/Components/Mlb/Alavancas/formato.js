// ─── Alavancas: formatadores (ESM puro) ─────────────────────────────────────

const FUSO = 'America/Sao_Paulo';

const vazio = (n) => n === null || n === undefined || n === '' || Number.isNaN(Number(n));

export const fmtInt = (n) => (vazio(n) ? '—' : Number(n).toLocaleString('pt-BR'));

export const fmtBRL = (n) => (vazio(n) ? '—' : Number(n).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }));

/** Porcentagem já em pontos (12,5 = 12,5%). */
export const fmtPct = (n) => (vazio(n) ? '—' : `${Number(n).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}%`);

/** dd/mm/aaaa no fuso de São Paulo; com `hora`, acrescenta hh:mm. */
export function fmtData(valor, { hora = false } = {}) {
    if (! valor) return '—';
    const d = new Date(valor);
    if (Number.isNaN(d.getTime())) return '—';
    const opcoes = hora
        ? { timeZone: FUSO, day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }
        : { timeZone: FUSO, day: '2-digit', month: '2-digit', year: 'numeric' };

    return d.toLocaleString('pt-BR', opcoes).replace(',', '');
}
