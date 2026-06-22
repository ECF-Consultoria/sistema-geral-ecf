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
 *   1.230 -> "R$ 1.23K"
 *   891.040 -> "R$ 891K"
 *   3.469.401 -> "R$ 3.47M"
 *   20.200.857 -> "R$ 20.20M"
 *
 * Regra: >=1M usa M com 2 decimais; >=1K usa K (sem decimais quando >=10K).
 * Use em cards principais/chips; mantenha formatCurrency em modais e tabelas
 * onde valor exato importa.
 */
export function formatCurrencyCompact(value) {
    const n = Number(value ?? 0);
    if (!Number.isFinite(n)) return 'R$ 0';
    const abs = Math.abs(n);
    const sign = n < 0 ? '-' : '';
    if (abs >= 1_000_000) {
        return `${sign}R$ ${(abs / 1_000_000).toFixed(2)}M`;
    }
    if (abs >= 10_000) {
        return `${sign}R$ ${Math.round(abs / 1_000)}K`;
    }
    if (abs >= 1_000) {
        return `${sign}R$ ${(abs / 1_000).toFixed(2)}K`;
    }
    return `${sign}R$ ${abs.toFixed(0)}`;
}

export function formatPercent(value, decimals = 1) {
    return `${Number(value ?? 0).toFixed(decimals)}%`;
}

const TZ = 'America/Sao_Paulo';

export function formatDate(date) {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('pt-BR', { timeZone: TZ });
}

export function formatDateTime(date) {
    if (!date) return '-';
    return new Date(date).toLocaleString('pt-BR', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });
}

export function formatTime(date) {
    if (!date) return '-';
    return new Date(date).toLocaleTimeString('pt-BR', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });
}
