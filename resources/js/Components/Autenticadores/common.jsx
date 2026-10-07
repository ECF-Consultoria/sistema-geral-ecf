// Peças de UI compartilhadas entre a lista (Index) e a página da conta (Show)
// do módulo Autenticadores 2FA. Mantém o mapa de ícones de serviço num lugar só.
import { cn } from '@/lib/utils';

export const STATUS_LABELS = { ativo: 'Ativo', expirando: 'Expirando', inativo: 'Inativo' };

const STATUS_BADGE = {
    ativo:     'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
    expirando: 'bg-amber-500/15 text-amber-300 border-amber-500/30',
    inativo:   'bg-zinc-500/15 text-zinc-300 border-zinc-500/30',
};

// Ícone do serviço: SVGs servidos em /images; senão, a inicial num quadradinho.
export const SERVICO_ICON = {
    google:          '/images/google-icon.svg',
    'mercado livre': '/images/mercado-livre-87.svg',
    shopee:          '/images/shopee-icon.svg',
    amazon:          '/images/icons8-amazon.svg',
};

export const iniciais = (texto) => (texto || '?').trim().slice(0, 2).toUpperCase();
export const antesDoArroba = (conta) => (conta || '').split('@')[0];
export const fmtCodigo = (c) => (c ? `${c.slice(0, Math.floor(c.length / 2))} ${c.slice(Math.floor(c.length / 2))}` : '•••  •••');

// Helper de clipboard (mesmo padrão de Sugadores/Index) — fallback p/ intranet sem HTTPS.
export const copyToClipboard = async (text) => {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return true;
        }
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        return ok;
    } catch {
        return false;
    }
};

export function StatusBadge({ status }) {
    return (
        <span className={cn('inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold border', STATUS_BADGE[status] || STATUS_BADGE.inativo)}>
            <span className="w-1.5 h-1.5 rounded-full bg-current" />
            {STATUS_LABELS[status] || status}
        </span>
    );
}

export function ServicoIcone({ servico, className = 'w-5 h-5' }) {
    const src = SERVICO_ICON[(servico || '').toLowerCase()];
    if (src) {
        return <img src={src} alt="" className={cn(className, 'object-contain')} />;
    }
    return (
        <span className={cn(className, 'rounded bg-white/10 text-[10px] font-bold text-white/70 flex items-center justify-center')}>
            {iniciais(servico)}
        </span>
    );
}

export function Avatar({ texto, className = 'w-9 h-9 text-[13px]' }) {
    return (
        <span className={cn(className, 'rounded-lg bg-ecf-yellow/[0.12] border border-ecf-yellow/20 text-ecf-yellow font-bold flex items-center justify-center shrink-0')}>
            {iniciais(texto)}
        </span>
    );
}

// Anel de contagem regressiva (SVG). `fraction` 1→0.
export function RingCountdown({ seconds, fraction }) {
    const r = 26;
    const c = 2 * Math.PI * r;
    const low = seconds <= 5;
    return (
        <div className="relative w-16 h-16 shrink-0">
            <svg viewBox="0 0 64 64" className="w-16 h-16 -rotate-90">
                <circle cx="32" cy="32" r={r} fill="none" stroke="currentColor" strokeWidth="5" className="text-white/10" />
                <circle
                    cx="32" cy="32" r={r} fill="none" strokeWidth="5" strokeLinecap="round"
                    stroke="currentColor"
                    className={cn('transition-[stroke-dashoffset] duration-500 ease-linear', low ? 'text-amber-400' : 'text-ecf-yellow')}
                    strokeDasharray={c}
                    strokeDashoffset={c * (1 - Math.max(fraction, 0))}
                />
            </svg>
            <span className={cn('absolute inset-0 flex items-center justify-center text-[13px] font-semibold tabular-nums', low ? 'text-amber-400' : 'text-white')}>
                {seconds}s
            </span>
        </div>
    );
}

export function Info({ rotulo, children }) {
    return (
        <div>
            <p className="text-white/35 text-[11px] uppercase tracking-wide">{rotulo}</p>
            <p className="text-white/85 mt-0.5">{children}</p>
        </div>
    );
}
