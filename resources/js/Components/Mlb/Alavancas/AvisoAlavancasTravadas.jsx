import { cn } from '@/lib/utils';
import { Lock } from 'lucide-react';

/**
 * Estado calmo de "alavancas não liberadas para esta conta" — nunca alarme.
 * Molde do AvisoContaTravada, com texto próprio (o da Fase 164 é travado por gate).
 *
 * Variantes: 'selo' (cabeçalho), 'faixa' (acima das abas) e 'linha' (texto livre via children,
 * ex.: o motivo vindo do servidor).
 */
export default function AvisoAlavancasTravadas({ variante = 'selo', className, children }) {
    if (variante === 'faixa') {
        return (
            <div className={cn('rounded-xl border border-white/[0.08] bg-white/[0.03] p-4', className)}>
                <p className="flex items-center gap-2 text-[13px] font-bold text-white/70">
                    <Lock className="h-4 w-4 shrink-0 text-white/55" aria-hidden="true" />
                    Alavancas ainda não liberadas para esta conta
                </p>
                <p className="mt-1 text-[13px] font-normal text-white/55">
                    Você pode ver e analisar tudo aqui; criar e alterar espera a liberação, que é feita conta a conta pelo time de desenvolvimento.
                </p>
                {children && <p className="mt-1 text-[13px] font-normal text-white/55">{children}</p>}
            </div>
        );
    }

    if (variante === 'linha') {
        return (
            <p className={cn('flex items-start gap-2 text-[13px] font-normal text-white/55', className)}>
                <Lock className="mt-1 h-[14px] w-[14px] shrink-0" aria-hidden="true" />
                <span>{children}</span>
            </p>
        );
    }

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 whitespace-nowrap rounded-full border border-white/[0.08] bg-white/[0.04] px-2 py-1 text-[11px] font-bold text-white/55',
                className,
            )}
        >
            <Lock className="h-[14px] w-[14px]" aria-hidden="true" />
            Alavancas ainda não liberadas
        </span>
    );
}
