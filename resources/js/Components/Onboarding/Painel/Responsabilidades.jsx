import { CalendarClock, ShieldCheck, UserRound, Zap } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Responsabilidades — "de quem é a bola", sem abrir etapa nenhuma.
 *
 * ### Por que três donos e não quatro cards irmãos
 * `dono` (cliente/interno/sistema) é um eixo EXCLUSIVO: todo passo aberto cai
 * em exatamente um dos três, e os três somam o total de pendências. `reuniao`
 * é `natureza` — COMO o item se preenche —, um eixo independente: um passo
 * "na reunião" já está contado em ECF ou em Cliente.
 *
 * Colocar "Reunião" como quarto card irmão produziria quatro números que não
 * somam o total, e quem conferisse na mão concluiria que a tela está errada.
 * Por isso ele aparece como SUBCONJUNTO, com o rótulo dizendo isso.
 *
 * ### Automático não é pendência
 * O quarto card mede o contrário: o que o sistema fechou sozinho. Ele existe
 * porque é a resposta para "isso aí alguém precisa fazer?" — e a resposta
 * costuma ser não.
 */
const CARDS = [
    {
        chave: 'cliente',
        titulo: 'Cliente',
        sufixo: 'pendências',
        icone: UserRound,
        cor: 'text-sky-300',
        anel: 'border-sky-500/20 bg-sky-500/10',
        barra: 'bg-sky-400/70',
    },
    {
        chave: 'interno',
        titulo: 'ECF',
        sufixo: 'pendências',
        icone: ShieldCheck,
        cor: 'text-violet-300',
        anel: 'border-violet-500/20 bg-violet-500/10',
        barra: 'bg-violet-400/70',
    },
    {
        chave: 'sistema',
        titulo: 'Sistema',
        sufixo: 'pendências',
        icone: Zap,
        cor: 'text-ecf-yellow',
        anel: 'border-ecf-yellow/20 bg-ecf-yellow/10',
        barra: 'bg-ecf-yellow/70',
    },
    {
        chave: 'automaticos',
        titulo: 'Automático',
        sufixo: 'concluídas sozinhas',
        icone: Zap,
        cor: 'text-emerald-300',
        anel: 'border-emerald-500/20 bg-emerald-500/10',
        barra: 'bg-emerald-400/70',
    },
];

function Card({ card, valor, total }) {
    const Icone = card.icone;
    // Proporção só entre PENDÊNCIAS; o card de automáticos mede outra coisa e
    // uma barra ali sugeriria que ele compete com os outros três.
    const proporcao = card.chave === 'automaticos' || !total
        ? null
        : Math.round((valor / total) * 100);

    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-3.5">
            <div className="flex items-center gap-2">
                <span className={cn('grid place-items-center h-7 w-7 rounded-lg border shrink-0', card.anel)}>
                    <Icone size={14} className={card.cor} />
                </span>
                <span className="text-[11px] font-semibold uppercase tracking-wider text-white/45">
                    {card.titulo}
                </span>
            </div>

            <div className="mt-2.5 flex items-baseline gap-1.5">
                <span className="text-2xl font-bold text-white tabular-nums leading-none">{valor}</span>
                <span className="text-[11px] text-white/35 leading-tight">{card.sufixo}</span>
            </div>

            <div className="mt-2.5 h-1 w-full rounded-full bg-white/[0.06] overflow-hidden">
                <div
                    className={cn('h-full rounded-full', card.barra)}
                    style={{ width: proporcao === null ? '100%' : `${proporcao}%` }}
                />
            </div>
        </div>
    );
}

export default function Responsabilidades({ responsabilidades }) {
    if (!responsabilidades) return null;

    const total =
        (responsabilidades.cliente ?? 0) +
        (responsabilidades.interno ?? 0) +
        (responsabilidades.sistema ?? 0);

    return (
        <div className="space-y-2">
            <div className="grid grid-cols-2 xl:grid-cols-4 gap-3">
                {CARDS.map((c) => (
                    <Card key={c.chave} card={c} valor={responsabilidades[c.chave] ?? 0} total={total} />
                ))}
            </div>

            {responsabilidades.na_reuniao > 0 && (
                <p className="text-[11px] text-white/35 inline-flex items-center gap-1.5">
                    <CalendarClock size={12} className="text-fuchsia-300 shrink-0" />
                    <span>
                        <strong className="text-fuchsia-300 font-semibold">
                            {responsabilidades.na_reuniao}
                        </strong>{' '}
                        {responsabilidades.na_reuniao === 1 ? 'pendência é conduzida' : 'pendências são conduzidas'} na
                        reunião — já {responsabilidades.na_reuniao === 1 ? 'contada' : 'contadas'} nos cards acima.
                    </span>
                </p>
            )}
        </div>
    );
}
