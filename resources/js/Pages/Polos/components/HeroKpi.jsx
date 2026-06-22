import { cn } from '@/lib/utils';
import RadialGauge from './RadialGauge';

// Tratamento de glow por hierarquia: amarelo no número herói, rose só em alertas
// quando há ocorrências, neutro caso contrário.
const GLOW = {
    yellow: 'text-ecf-yellow drop-shadow-[0_0_18px_rgba(255,230,0,0.45)]',
    rose:   'text-rose-400 drop-shadow-[0_0_16px_rgba(244,63,94,0.45)]',
    green:  'text-emerald-400 drop-shadow-[0_0_16px_rgba(34,197,94,0.45)]',
    none:   'text-white/85',
};

/**
 * HeroKpi — card de KPI de comando: ícone (chip) + título + número herói grande
 * (font-display) + sublabel narrativo. Glow dosado por `glow`. Quando recebe
 * `gauge` (percentual), embute um arco radial atrás do número (card "% Geral").
 *
 * Props:
 *   titulo   : rótulo curto (uppercase)
 *   valor    : valor já formatado (string/number)
 *   icone    : componente lucide (ex.: Wallet)
 *   sublabel : linha de apoio (string)
 *   glow     : 'yellow' | 'rose' | 'green' | 'none'
 *   gauge    : percentual 0–100 → renderiza RadialGauge atrás do número
 *   alerta   : realça o card (borda/sombra rose) — usado quando há alertas
 */
export default function HeroKpi({ titulo, valor, icone: Icone, sublabel, glow = 'none', gauge = null, alerta = false }) {
    const numCls = cn('font-display font-extrabold tabular-nums leading-none', GLOW[glow] ?? GLOW.none);

    return (
        <div
            className={cn(
                'relative overflow-hidden rounded-2xl border bg-ecf-card-2/60 p-5',
                'before:absolute before:inset-x-0 before:top-0 before:h-px before:bg-gradient-to-r before:from-transparent before:via-white/[0.12] before:to-transparent',
                alerta ? 'border-rose-500/25 shadow-[0_0_24px_rgba(244,63,94,0.18)]' : 'border-white/[0.08]',
            )}
        >
            {/* Cabeçalho: título + chip do ícone */}
            <div className="flex items-start justify-between gap-2">
                <p className="text-white/40 text-xs uppercase tracking-wider">{titulo}</p>
                {Icone && (
                    <span className="rounded-xl bg-white/[0.04] p-2 text-white/50 shrink-0">
                        <Icone size={16} />
                    </span>
                )}
            </div>

            {/* Corpo: número herói (com gauge radial opcional ao fundo) */}
            {gauge !== null ? (
                <div className="mt-3 flex items-center gap-4">
                    <div className="relative grid place-items-center shrink-0" style={{ width: 92, height: 92 }}>
                        <RadialGauge pct={gauge} size={92} />
                        <span className={cn(numCls, 'absolute text-2xl')}>{valor}</span>
                    </div>
                    {sublabel && (
                        <p className="text-[11px] text-white/50 leading-snug">{sublabel}</p>
                    )}
                </div>
            ) : (
                <>
                    <p className={cn(numCls, 'mt-3 text-3xl lg:text-4xl break-words')}>{valor}</p>
                    {sublabel && (
                        <p className="mt-2 text-[11px] text-white/50 leading-snug break-words">{sublabel}</p>
                    )}
                </>
            )}
        </div>
    );
}
