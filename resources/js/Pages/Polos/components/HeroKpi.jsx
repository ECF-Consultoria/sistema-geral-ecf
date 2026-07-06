import { cn } from '@/lib/utils';
import RadialGauge from './RadialGauge';

// Cor do número herói por hierarquia (sem glow): amarelo no número principal,
// rose só em alertas quando há ocorrências, neutro caso contrário.
const GLOW = {
    yellow: 'text-ecf-yellow',
    rose:   'text-rose-400',
    green:  'text-emerald-400',
    none:   'text-white/85',
};

/**
 * HeroKpi — card de KPI de comando: ícone (chip) + título + número herói grande
 * (font-display) + sublabel narrativo. Cor de destaque por `glow` (sem brilho).
 * Quando recebe `gauge` (percentual), embute um arco radial atrás do número (card "% Geral").
 *
 * Props:
 *   titulo   : rótulo curto (uppercase)
 *   valor    : valor já formatado (string/number)
 *   icone    : componente lucide (ex.: Wallet)
 *   sublabel : linha de apoio (string)
 *   glow     : 'yellow' | 'rose' | 'green' | 'none'
 *   gauge    : percentual 0–100 → renderiza RadialGauge atrás do número
 *   alerta   : realça o card (borda rose) — usado quando há alertas
 */
export default function HeroKpi({ titulo, valor, icone: Icone, sublabel, glow = 'none', gauge = null, alerta = false }) {
    const numCls = cn('font-display font-extrabold tabular-nums leading-none', GLOW[glow] ?? GLOW.none);

    return (
        <div
            className={cn(
                'relative overflow-hidden rounded-2xl border bg-ecf-card-2/60 p-5',
                'before:absolute before:inset-x-0 before:top-0 before:h-px before:bg-gradient-to-r before:from-transparent before:via-white/[0.12] before:to-transparent',
                alerta ? 'border-rose-500/40' : 'border-white/[0.08]',
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
                        {/* Número responsivo ao comprimento: frações "128/140" não transbordam o arco de 92px. */}
                        <span className={cn(numCls, 'absolute max-w-[78px] px-1 text-center leading-none', String(valor).length > 4 ? 'text-lg' : 'text-2xl')}>{valor}</span>
                    </div>
                    {sublabel && (
                        <p className="text-[11px] text-white/50 leading-snug">{sublabel}</p>
                    )}
                </div>
            ) : (
                <>
                    <p className={cn(numCls, 'mt-3 text-2xl lg:text-3xl break-words')}>{valor}</p>
                    {sublabel && (
                        <p className="mt-2 text-[11px] text-white/50 leading-snug break-words">{sublabel}</p>
                    )}
                </>
            )}
        </div>
    );
}
