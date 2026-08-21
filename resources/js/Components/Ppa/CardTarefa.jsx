import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { CalendarDays, CheckCircle2, Circle, Flag, GripVertical, MoreHorizontal } from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Card de tarefa do quadro do PPA ────────────────────────────────────────
//
// ### Campo ausente não vira espaço vazio
// `area`, `prioridade`, `prazo` e `responsavel_lado` são todos opcionais em
// `ppa_tasks` — tarefa criada antes de 21/08/2026 não tem nenhum deles. Cada
// pedaço do card só existe se o dado existir, e o rodapé inteiro desaparece
// quando não há nada nele. Um card antigo fica enxuto, não quebrado.
//
// ### As setas ‹ › saíram
// Elas eram a única forma de mover a tarefa. Com o arraste funcionando, manter
// as duas seria oferecer dois caminhos para a mesma coisa e ocupar a base do
// card com botões que competem com a informação. A rota que elas usavam
// (`ppa.tasks.update`) continua de pé e continua sendo usada pela edição.

const PRIORIDADES = {
    alta:  { rotulo: 'Alta',  classe: 'text-rose-300' },
    media: { rotulo: 'Média', classe: 'text-amber-300' },
    baixa: { rotulo: 'Baixa', classe: 'text-white/40' },
};

const LADOS = {
    ecf:     { rotulo: 'ECF',     iniciais: 'MB', classe: 'bg-violet-500/20 text-violet-200 border-violet-400/25' },
    cliente: { rotulo: 'Cliente', iniciais: 'CL', classe: 'bg-emerald-500/20 text-emerald-200 border-emerald-400/25' },
};

/** Tag de área: cor derivada do próprio texto, para a mesma área ficar sempre da mesma cor. */
const CORES_AREA = [
    'bg-violet-500/10 text-violet-300 ring-violet-400/20',
    'bg-sky-500/10 text-sky-300 ring-sky-400/20',
    'bg-amber-500/10 text-amber-300 ring-amber-400/20',
    'bg-emerald-500/10 text-emerald-300 ring-emerald-400/20',
    'bg-rose-500/10 text-rose-300 ring-rose-400/20',
];

function corDaArea(area) {
    let soma = 0;
    for (let i = 0; i < area.length; i++) soma += area.charCodeAt(i);
    return CORES_AREA[soma % CORES_AREA.length];
}

/**
 * O conteúdo do card, sem nada de drag. Fica separado porque o `DragOverlay`
 * do dnd-kit precisa renderizar o MESMO visual solto no ar, sem os hooks de
 * ordenação — usar o componente completo ali dispara `useSortable` duas vezes
 * para o mesmo id e o card fantasma some no meio do arraste.
 */
export function ConteudoCard({ task, arrastando = false, onAbrir, onMenu }) {
    const prioridade = task.prioridade ? PRIORIDADES[task.prioridade] : null;
    const lado = task.responsavel_lado ? LADOS[task.responsavel_lado] : null;
    const feita = task.status === 'done';

    // Prazo vencido só pinta enquanto a tarefa está aberta — o backend já manda
    // `prazo_dias` como null quando ela está concluída, para não haver duas
    // regras de "atrasado" (uma aqui e outra lá).
    const atrasada = task.prazo_dias !== null && task.prazo_dias < 0;
    const hoje = task.prazo_dias === 0;

    const temRodape = lado || task.prazo || prioridade || task.concluida_em;

    return (
        <div
            className={cn(
                'group/card rounded-xl p-3 space-y-2 transition-all ring-1 ring-inset',
                feita
                    ? 'bg-emerald-500/[0.05] ring-emerald-400/12'
                    : 'bg-white/[0.045] ring-white/[0.05] hover:bg-white/[0.07] hover:ring-white/[0.10]',
                arrastando && 'shadow-2xl shadow-black/60 ring-ecf-yellow/40 rotate-[2deg] scale-[1.02]',
            )}
        >
            {/* Linha 1: tag de área + ações discretas. A linha existe mesmo
                sem área — é onde as ações de hover moram, e sem ela o menu
                cairia sobre o título. */}
            <div className="flex items-start gap-2 min-h-[20px]">
                {task.area ? (
                    <span className={cn(
                        'inline-flex items-center px-2 py-0.5 rounded-md ring-1 ring-inset text-[10px] font-semibold uppercase tracking-wide',
                        corDaArea(task.area),
                    )}>
                        {task.area}
                    </span>
                ) : <span />}

                <div className="ml-auto flex items-center gap-0.5 opacity-0 group-hover/card:opacity-100 transition-opacity">
                    {onMenu && (
                        <button
                            type="button"
                            onClick={(e) => { e.stopPropagation(); onMenu(task, e); }}
                            className="p-1 rounded text-white/30 hover:text-white/80 hover:bg-white/[0.07] transition-colors"
                            title="Ações da tarefa"
                        >
                            <MoreHorizontal size={14} />
                        </button>
                    )}
                    {/* A alça existe para o toque e para deixar óbvio que o card
                        se arrasta — no desktop o card inteiro já é arrastável. */}
                    <span
                        className="p-1 rounded text-white/20 cursor-grab active:cursor-grabbing"
                        data-alca-arraste
                        title="Arraste para mover"
                    >
                        <GripVertical size={14} />
                    </span>
                </div>
            </div>

            {/* Linha 2: título — a informação principal */}
            <button
                type="button"
                onClick={() => onAbrir?.(task)}
                className="block w-full text-left"
            >
                <p className={cn(
                    'text-[14px] font-semibold leading-snug tracking-tight',
                    feita ? 'text-white/40 line-through' : 'text-white',
                )}>
                    {task.title}
                </p>

                {task.description && (
                    <p className="text-white/35 text-[12px] leading-relaxed mt-1.5 line-clamp-2">
                        {task.description}
                    </p>
                )}
            </button>

            {/* Rodapé: só existe se houver o que mostrar */}
            {temRodape && (
                <div className={cn(
                    'flex items-center gap-x-2.5 gap-y-1.5 flex-wrap',
                    // Fio só quando há texto acima: em card de título curto
                    // ele seria mais uma linha sem função.
                    task.description && 'pt-2 border-t border-white/[0.04]',
                )}>
                    {lado && (
                        <span className="flex items-center gap-1.5 min-w-0">
                            <span className={cn(
                                'grid place-items-center h-5 w-5 rounded-full border text-[9px] font-bold shrink-0',
                                lado.classe,
                            )}>
                                {lado.iniciais}
                            </span>
                            <span className="text-white/50 text-[11.5px] truncate">{lado.rotulo}</span>
                        </span>
                    )}

                    {feita && task.concluida_em ? (
                        <span className="flex items-center gap-1 text-emerald-400/80 text-[11.5px]">
                            <CheckCircle2 size={11} /> Concluída em {task.concluida_em}
                        </span>
                    ) : task.prazo && (
                        <span className={cn(
                            'flex items-center gap-1 text-[11.5px]',
                            atrasada ? 'text-rose-300' : hoje ? 'text-amber-300' : 'text-white/45',
                        )}>
                            <CalendarDays size={11} /> {task.prazo}
                            {atrasada && ' · atrasada'}
                            {hoje && ' · hoje'}
                        </span>
                    )}

                    {prioridade && !feita && (
                        <span className={cn('flex items-center gap-1 text-[11.5px]', prioridade.classe)}>
                            <Flag size={11} /> {prioridade.rotulo}
                        </span>
                    )}
                </div>
            )}
        </div>
    );
}

/** O card ordenável de verdade — o que vive dentro da coluna. */
export default function CardTarefa({ task, onAbrir, onMenu }) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
        id: task.id,
        data: { tipo: 'tarefa', task },
    });

    return (
        <div
            ref={setNodeRef}
            style={{ transform: CSS.Translate.toString(transform), transition }}
            {...attributes}
            {...listeners}
            className={cn(
                'touch-none',
                // O card original vira um vazio esmaecido enquanto o `DragOverlay`
                // o desenha sob o cursor. Removê-lo da lista faria as demais
                // tarefas pularem de posição no instante em que o arraste começa.
                isDragging && 'opacity-30',
            )}
        >
            <ConteudoCard task={task} onAbrir={onAbrir} onMenu={onMenu} />
        </div>
    );
}
