import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs) {
    return twMerge(clsx(inputs));
}

export function formatCurrency(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
}

/**
 * Formato compacto pra dashboards (Quick 260619 redesign Carteira):
 *   12 -> "R$ 12,00"        (formato BR completo — sem unidade compacta)
 *   891 -> "R$ 891,00"      (id.; deixa claro que NAO sao 891 mil)
 *   1.234 -> "R$ 1,23K"
 *   891.040 -> "R$ 891K"
 *   3.469.401 -> "R$ 3,47M"
 *   20.200.857 -> "R$ 20,20M"
 *
 * Regra: >=1M usa M com 2 decimais; >=10K usa K sem decimais; 1K-9.99K usa
 * K com 2 decimais; <1K usa formato BR completo (R$ X,XX) — esse <1K
 * inclui virgula+centavos pra eliminar a ambiguidade reportada em 2026-06-19
 * (usuario via 'R$ 891' e nao sabia se eram 891 reais ou 891 mil).
 *
 * Decimais usam virgula BR ("R$ 1,23M") em todos os tiers; o briefing original
 * usava ponto americano mas a UI eh pt-BR, entao mantemos consistencia.
 */
export function formatCurrencyCompact(value) {
    const n = Number(value ?? 0);
    if (!Number.isFinite(n)) return 'R$ 0,00';
    const abs = Math.abs(n);
    const sign = n < 0 ? '-' : '';
    const withComma = (decStr) => decStr.replace('.', ',');
    if (abs >= 1_000_000) {
        return `${sign}R$ ${withComma((abs / 1_000_000).toFixed(2))}M`;
    }
    if (abs >= 10_000) {
        return `${sign}R$ ${Math.round(abs / 1_000)}K`;
    }
    if (abs >= 1_000) {
        return `${sign}R$ ${withComma((abs / 1_000).toFixed(2))}K`;
    }
    // <1K: BR completo (R$ X,XX) — elimina ambiguidade 'R$ 891' vs 'R$ 891K'.
    return `${sign}${new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(abs)}`;
}

export function formatPercent(value, decimals = 1) {
    return `${Number(value ?? 0).toFixed(decimals)}%`;
}

const TZ = 'America/Sao_Paulo';

export function formatDate(date) {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('pt-BR', { timeZone: TZ });
}

// Os componentes de DATA (day/month/year) sao explicitos de proposito: quando o
// Intl recebe so componentes de hora, ele emite SO a hora e descarta a data em
// silencio — a funcao virava um clone do formatTime abaixo. Nao "simplificar"
// removendo day/month/year.
export function formatDateTime(date) {
    if (!date) return '-';
    return new Date(date).toLocaleString('pt-BR', {
        timeZone: TZ,
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function formatTime(date) {
    if (!date) return '-';
    return new Date(date).toLocaleTimeString('pt-BR', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });
}
