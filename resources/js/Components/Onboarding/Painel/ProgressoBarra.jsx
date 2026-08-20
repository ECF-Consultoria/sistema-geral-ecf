import { cn } from '@/lib/utils';

/**
 * ProgressoBarra — o volume de atividades de UM onboarding.
 *
 * ### Por que é um componente, e não duas barras parecidas
 * A lista e o detalhe mostram o mesmo número. Se cada tela desenhar a própria
 * barra, uma delas eventualmente vai arredondar diferente, ou contar
 * `nao_aplicavel` de outro jeito, e aí "45% na listagem / 47% no detalhe" vira
 * um chamado que ninguém consegue reproduzir. A fração já vem pronta do
 * backend (`OnboardingSituacaoService::progresso()`); aqui só existe
 * apresentação.
 *
 * ### Por que a cor é quase sempre a mesma
 * O andamento NÃO é o alarme da tela — quem alarma é "Próxima ação" (SC-11).
 * Uma barra que fica vermelha em 20% transformaria todo onboarding recém
 * criado em problema. Só o 100% muda de tom, porque "acabou" é a única leitura
 * que vale destacar sozinha.
 */
export default function ProgressoBarra({ progresso, className, compacto = false }) {
    // `total = 0` acontece de verdade: onboarding cujos passos foram todos
    // marcados como não-aplicáveis. Mostrar "0%" ali seria mentira — não há
    // atividade nenhuma para fazer.
    if (!progresso || progresso.total === 0) {
        return <span className="text-white/30 text-[13px]">—</span>;
    }

    const { percentual, feitos, total } = progresso;
    const completo = percentual >= 100;

    return (
        <div className={cn('min-w-[92px]', className)}>
            <div className="flex items-baseline gap-1.5">
                <span
                    className={cn(
                        'text-[13px] font-semibold tabular-nums',
                        completo ? 'text-emerald-300' : 'text-white/80'
                    )}
                >
                    {percentual}%
                </span>
                {!compacto && (
                    <span className="text-[11px] text-white/35 tabular-nums whitespace-nowrap">
                        {feitos} de {total}
                    </span>
                )}
            </div>

            <div
                className="mt-1 h-1.5 w-full rounded-full bg-white/[0.07] overflow-hidden"
                role="progressbar"
                aria-valuenow={percentual}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label={`${feitos} de ${total} atividades concluídas`}
            >
                <div
                    className={cn(
                        'h-full rounded-full transition-[width] duration-500',
                        completo ? 'bg-emerald-400/80' : 'bg-ecf-yellow/80'
                    )}
                    style={{ width: `${Math.min(100, Math.max(0, percentual))}%` }}
                />
            </div>
        </div>
    );
}
