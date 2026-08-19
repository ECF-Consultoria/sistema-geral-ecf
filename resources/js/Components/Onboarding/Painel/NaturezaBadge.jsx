import { CalendarClock, HelpCircle } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Selo de `natureza` — COMO o item se preenche. Eixo independente do
 * `DonoBadge` (que responde de quem é a bola): "conduzir na reunião" e
 * "responder uma pergunta" são ambos `dono=interno`, e é justamente isso que
 * este selo distingue.
 *
 * `acao` NÃO renderiza nada de propósito: é a natureza da maioria dos passos e
 * o comportamento que a tela já tinha antes do eixo existir. Selo em todo
 * lugar vira ruído e some no meio — o que precisa saltar é a exceção ("isto
 * aqui só se responde na call").
 */
const NATUREZAS = {
    reuniao: {
        label: 'Na reunião',
        icone: CalendarClock,
        tom: 'text-fuchsia-300 bg-fuchsia-500/10 border-fuchsia-500/20',
        titulo: 'Conduzido na reunião com o cliente',
    },
    pergunta: {
        label: 'Pergunta',
        icone: HelpCircle,
        tom: 'text-cyan-300 bg-cyan-500/10 border-cyan-500/20',
        titulo: 'Pergunta com resposta preenchida pela equipe',
    },
};

export default function NaturezaBadge({ natureza }) {
    const cfg = NATUREZAS[natureza];

    if (!cfg) return null;

    const Icone = cfg.icone;

    return (
        <span
            title={cfg.titulo}
            className={cn(
                'inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[11px] font-semibold whitespace-nowrap',
                cfg.tom
            )}
        >
            <Icone size={11} className="shrink-0" />
            {cfg.label}
        </span>
    );
}
