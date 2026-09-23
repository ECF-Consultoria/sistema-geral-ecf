import { useDraggable } from '@dnd-kit/core';
import { CalendarDays, CheckCircle2, Circle, Clock, GripVertical, Pencil } from 'lucide-react';
import { seloPrazo } from '@/lib/ppaAgrupamento';
import { cn } from '@/lib/utils';

// ─── O card de tarefa como o CLIENTE o vê ───────────────────────────────────
//
// Primo do card do quadro interno (`Components/Ppa/CardTarefa.jsx`), não uma
// cópia dele: o interno carrega área, prioridade, menu de ações e alça de
// reordenar, e nada disso existe no portal. O que os dois têm em comum de
// verdade é o arraste, e essa parte é do `@dnd-kit`, não do componente.
//
// ### Arrasta, não reordena
// `useDraggable` e não `useSortable`. A rota do portal
// (`PortalPpaController::moverTarefa`) persiste STATUS, e só. Um card que se
// reordenasse dentro da coluna mostraria uma organização que o próximo F5
// desfaz — e o cliente concluiria, com razão, que o portal perde o que ele
// faz. Mover entre colunas é o que a rota sabe fazer, e é o que o card
// oferece.
//
// ### As setas saíram
// "Voltar" / "Iniciar" / "Marcar concluída" eram a única forma de mover a
// tarefa. Com o arraste, manter os botões seria oferecer dois caminhos para a
// mesma coisa e encher a base do card de controles que competem com o texto.
// Quem não usa ponteiro continua atendido: o `KeyboardSensor` em `PlanoPpa`
// move o card com Espaço + setas.

const LADOS = {
    ecf:     { rotulo: 'Nossa equipe', iniciais: 'ECF', classe: 'bg-violet-500/15 text-violet-200 ring-violet-400/25' },
    cliente: { rotulo: 'Com você',     iniciais: 'VC',  classe: 'bg-emerald-500/15 text-emerald-200 ring-emerald-400/25' },
};

const TOM_PRAZO = {
    atrasado: 'text-rose-300',
    hoje:     'text-amber-300',
    proximo:  'text-amber-200/80',
};

/**
 * O conteúdo visual, sem nenhum hook de arraste.
 *
 * Separado porque o `DragOverlay` precisa desenhar o MESMO card solto sob o
 * cursor: usar o componente completo ali registraria o mesmo id duas vezes no
 * dnd-kit e o card fantasma sumiria no meio do arraste.
 */
export function ConteudoCardPpa({ tarefa, arrastando = false, estatico = false, editavel = false }) {
    const feita = tarefa.status === 'done';
    const lado  = tarefa.responsavel_lado ? LADOS[tarefa.responsavel_lado] : null;
    const prazo = seloPrazo(tarefa.prazo_dias, { encerrado: feita });

    const Icone = feita ? CheckCircle2 : tarefa.status === 'doing' ? Clock : Circle;

    return (
        <div
            className={cn(
                'group/card rounded-xl p-3.5 ring-1 ring-inset transition-all',
                feita
                    ? 'bg-emerald-500/[0.055] ring-emerald-400/15'
                    : 'bg-white/[0.045] ring-white/[0.06]',
                !estatico && !arrastando && 'hover:bg-white/[0.075] hover:ring-white/[0.12] cursor-grab active:cursor-grabbing',
                // O estado de arraste: elevado, inclinado e com sombra funda.
                // É o que dá a sensação de o card ter saído da página e estar
                // na mão de quem move.
                arrastando && 'shadow-2xl shadow-black/70 ring-ecf-yellow/40 rotate-[2.5deg] scale-[1.03] cursor-grabbing',
            )}
        >
            <div className="flex items-start gap-2">
                <Icone
                    size={15}
                    className={cn(
                        'mt-0.5 shrink-0',
                        feita ? 'text-emerald-400' : tarefa.status === 'doing' ? 'text-ecf-yellow' : 'text-white/25',
                    )}
                />

                <p className={cn(
                    'text-[13px] leading-snug flex-1 min-w-0 font-medium',
                    feita ? 'text-white/45 line-through' : 'text-white/90',
                )}>
                    {tarefa.titulo}
                </p>

                {/* A alça não move nada sozinha — o card inteiro já é a área de
                    arraste. Ela existe para DIZER que o card se arrasta, que é
                    a única pista que um kanban sem botões precisa dar. */}
                {/* O lápis só aparece no hover: é a pista de que o card abre,
                    sem somar um controle fixo a cada card da coluna. */}
                {editavel && !arrastando && (
                    <Pencil
                        size={12}
                        className="shrink-0 mt-0.5 text-white/0 group-hover/card:text-white/45 transition-colors"
                        aria-hidden="true"
                    />
                )}

                {!estatico && (
                    <GripVertical
                        size={14}
                        className="shrink-0 text-white/15 group-hover/card:text-white/40 transition-colors"
                        aria-hidden="true"
                    />
                )}
            </div>

            {tarefa.descricao && (
                <p className="text-white/40 text-[12px] leading-relaxed mt-2 pl-[23px] line-clamp-3">
                    {tarefa.descricao}
                </p>
            )}

            {/* Rodapé só existe se houver o que dizer — card sem prazo e sem
                responsável fica enxuto em vez de exibir travessões. */}
            {(lado || prazo || tarefa.prazo) && (
                <div className="flex items-center gap-x-3 gap-y-1.5 flex-wrap mt-2.5 pl-[23px]">
                    {lado && (
                        <span className="flex items-center gap-1.5 min-w-0">
                            <span className={cn(
                                'grid place-items-center h-[18px] px-1.5 rounded-md ring-1 ring-inset text-[9px] font-bold shrink-0',
                                lado.classe,
                            )}>
                                {lado.iniciais}
                            </span>
                            <span className="text-white/45 text-[11.5px] truncate">{lado.rotulo}</span>
                        </span>
                    )}

                    {tarefa.prazo && (
                        <span className={cn(
                            'flex items-center gap-1 text-[11.5px]',
                            prazo ? TOM_PRAZO[prazo.tom] : 'text-white/40',
                        )}>
                            <CalendarDays size={11} />
                            {prazo ? prazo.texto : tarefa.prazo}
                        </span>
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * O card arrastável de verdade — o que vive dentro da coluna.
 *
 * `onAbrir` só chega da lista INTERNA (23/09/2026): clicar abre o diálogo de
 * edição da tarefa. O arraste não o dispara por engano porque o `MouseSensor`
 * só começa depois de 6px — um clique parado nunca vira arraste. No portal a
 * prop não vem, e o card continua sendo só de mover.
 */
export default function CardTarefaPpa({ tarefa, desabilitado = false, onAbrir = null }) {
    const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
        id: tarefa.id,
        disabled: desabilitado,
        data: { tarefa },
    });

    return (
        <div
            ref={setNodeRef}
            {...attributes}
            {...listeners}
            // `manipulation` e não `none`: com `touch-action: none` o dedo
            // deixaria de rolar a página ao encostar num card, e no celular
            // quase tudo que se faz numa lista de tarefas é rolar. Quem move
            // no toque usa o `TouchSensor` (segurar e arrastar), configurado
            // em `PlanoPpa`.
            style={{ touchAction: 'manipulation' }}
            onClick={onAbrir ? () => onAbrir(tarefa) : undefined}
            title={onAbrir ? 'Clique para editar · arraste para mover' : undefined}
            className={cn(
                'outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/50 rounded-xl',
                // O original vira um fantasma esmaecido enquanto o
                // `DragOverlay` desenha o card sob o cursor. Tirá-lo da lista
                // faria os cards de baixo pularem no instante do clique.
                isDragging && 'opacity-25',
            )}
        >
            <ConteudoCardPpa tarefa={tarefa} estatico={desabilitado} editavel={Boolean(onAbrir)} />
        </div>
    );
}
