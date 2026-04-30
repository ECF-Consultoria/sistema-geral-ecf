import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs) {
    return twMerge(clsx(inputs));
}

export function formatCurrency(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
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
