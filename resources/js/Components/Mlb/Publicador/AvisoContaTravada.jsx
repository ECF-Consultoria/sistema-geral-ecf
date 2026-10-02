import { cn } from '@/lib/utils';
import { Lock } from 'lucide-react';

/**
 * Estado calmo de "conta não liberada" (D21) — nunca alarme.
 *
 * D26 (decidido depois da UI-SPEC): em conta não liberada a conferência no
 * Mercado Livre também espera a liberação; preparar e conferir os dados
 * localmente continua. Por isso os textos dizem "validação E publicação".
 *
 * Variantes: 'selo' (linha da tela A), 'faixa' (tela B), 'nota' (resumo do
 * editor, alvo do aria-describedby) e 'linha' (texto calmo livre via children,
 * ex.: conferência local do editor).
 */
export default function AvisoContaTravada({ variante = 'selo', className, children }) {
    if (variante === 'faixa') {
        return (
            <div className={cn('rounded-xl border border-white/[0.08] bg-white/[0.03] p-4', className)}>
                <p className="flex items-center gap-2 text-[13px] font-bold text-white/70">
                    <Lock className="h-4 w-4 shrink-0 text-white/55" aria-hidden="true" />
                    Publicação ainda não liberada para esta conta
                </p>
                <p className="mt-1 text-[13px] font-normal text-white/55">
                    Você pode preparar os rascunhos e conferir os dados aqui. A validação e a publicação no Mercado Livre são liberadas conta a conta pelo time de desenvolvimento.
                </p>
            </div>
        );
    }

    if (variante === 'nota') {
        return (
            <p
                id="nota-conta-travada"
                className={cn(
                    'flex items-start gap-2 rounded-lg border border-white/[0.08] bg-white/[0.03] p-3 text-[13px] font-normal text-white/55',
                    className,
                )}
            >
                <Lock className="mt-1 h-[14px] w-[14px] shrink-0" aria-hidden="true" />
                <span>
                    Publicação ainda não liberada para esta conta. Você pode preparar o rascunho e conferir os dados aqui; a validação no Mercado Livre espera a liberação da conta.
                </span>
            </p>
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
                'inline-flex items-center gap-1 rounded-full border border-white/[0.08] bg-white/[0.04] px-2 py-1 text-[11px] font-bold text-white/55',
                className,
            )}
        >
            <Lock className="h-[14px] w-[14px]" aria-hidden="true" />
            Publicação ainda não liberada
        </span>
    );
}
