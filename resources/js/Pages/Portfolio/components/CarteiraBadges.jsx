import { TrendingUp, TrendingDown, Minus, Store, Coins, ShoppingCart, Ban } from 'lucide-react';
import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════
// Badges compartilhados da carteira (§8.3) — usados na carteira individual
// (Portfolio/AdminCarteira) E na página de transparência (Portfolio/Transparencia)
// para que as duas fiquem IDÊNTICAS (fonte, status, variação crescimento/queda).
// ═══════════════════════════════════════════════════════════════════════

export const FONTE = {
    ml:        { label: 'Mercado Livre', icon: Store,        cls: 'text-yellow-300 border-yellow-500/25 bg-yellow-500/[0.08]' },
    adman:     { label: 'Adman',         icon: Coins,        cls: 'text-sky-300 border-sky-500/25 bg-sky-500/[0.08]' },
    ml_adman:  { label: 'ML + Adman',    icon: Store,        cls: 'text-emerald-300 border-emerald-500/25 bg-emerald-500/[0.08]' },
    shopee:    { label: 'Shopee',        icon: ShoppingCart, cls: 'text-orange-300 border-orange-500/25 bg-orange-500/[0.08]' },
    sem_fonte: { label: 'Sem fonte',     icon: Ban,          cls: 'text-white/40 border-white/[0.1] bg-white/[0.03]' },
};

export const STATUS = {
    completo:     { label: 'Completo',             cls: 'bg-emerald-500/10 text-emerald-300 border-emerald-500/25' },
    parcial:      { label: 'Parcial',              cls: 'bg-amber-500/10 text-amber-200 border-amber-500/25' },
    sem_baseline: { label: 'Sem baseline',         cls: 'bg-amber-500/10 text-amber-200/80 border-amber-500/20' },
    sem_dados:    { label: 'Sem dados Adman',      cls: 'bg-white/[0.04] text-white/50 border-white/[0.1]' },
    sem_fonte:    { label: 'Sem fonte financeira', cls: 'bg-sky-500/10 text-sky-300 border-sky-500/25' },
    invalidada:   { label: 'Invalidada p/ bônus',  cls: 'bg-red-500/10 text-red-300 border-red-500/30' },
};

// Badge de fonte de dados combinada da empresa.
export function FonteBadge({ fonte }) {
    const f = FONTE[fonte] ?? FONTE.sem_fonte;
    const Icon = f.icon;
    return (
        <span className={cn('inline-flex items-center gap-1 text-[11px] px-2 py-0.5 rounded-full border whitespace-nowrap', f.cls)}>
            <Icon size={11} /> {f.label}
        </span>
    );
}

// Badge de status de qualidade/elegibilidade da linha.
export function StatusBadge({ status, invalidada = false }) {
    const s = STATUS[status] ?? STATUS.sem_dados;
    return (
        <span className={cn('inline-flex items-center gap-1 text-[11px] px-2 py-0.5 rounded-full border', s.cls)}>
            {invalidada && <Ban size={10} />}
            {s.label}
        </span>
    );
}

// Badge de variação (crescimento/queda) — seta + %.
export function VarBadge({ v }) {
    if (v === null || v === undefined) return <span className="text-white/25 text-xs">—</span>;
    const n = Number(v);
    const zero = n === 0;
    const up = n >= 0;
    return (
        <span className={cn('inline-flex items-center gap-0.5 text-xs font-medium tabular-nums',
            zero ? 'text-white/40' : up ? 'text-emerald-400' : 'text-rose-400')}>
            {zero ? <Minus size={11} /> : up ? <TrendingUp size={11} /> : <TrendingDown size={11} />}
            {up ? '+' : ''}{n.toFixed(1)}%
        </span>
    );
}
