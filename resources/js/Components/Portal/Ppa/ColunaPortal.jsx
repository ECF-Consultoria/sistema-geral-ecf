import { useDroppable } from '@dnd-kit/core';
import { CheckCircle2, Circle, Clock } from 'lucide-react';
import CardTarefaPortal from './CardTarefaPortal';
import { cn } from '@/lib/utils';

// ─── Uma das três colunas do quadro do cliente ──────────────────────────────
//
// São TRÊS, sempre: `todo`, `doing`, `done` — o ENUM `ppa_tasks.status`. As
// colunas extras que a equipe cria no quadro interno (`ppa_colunas`) não
// aparecem aqui de propósito: elas são refinamento interno sobre o mesmo
// status, e o cliente precisa das três etapas que ele entende. Mover um card
// por aqui preserva a coluna extra quando ela pertence ao status de destino —
// quem cuida disso é `PpaTask::moverPara()`, do lado do servidor.
//
// ### "Em andamento" pesa mais
// A coluna do meio é onde o trabalho vivo está, e é a única que ganha a cor da
// marca. Sem isso as três colunas têm exatamente o mesmo peso visual e a tela
// não responde de longe à pergunta que o cliente faz primeiro: "o que está
// acontecendo agora?".

export const COLUNAS = [
    {
        chave: 'todo',
        rotulo: 'A fazer',
        icone: Circle,
        ponto: 'bg-white/35',
        texto: 'text-white/65',
        badge: 'bg-white/[0.07] text-white/55',
        caixa: 'bg-white/[0.018] ring-white/[0.045]',
        ativa: 'ring-white/30 bg-white/[0.05]',
        vazio: 'Nada pendente por aqui.',
    },
    {
        chave: 'doing',
        rotulo: 'Em andamento',
        icone: Clock,
        ponto: 'bg-ecf-yellow',
        texto: 'text-ecf-yellow',
        badge: 'bg-ecf-yellow/15 text-ecf-yellow',
        caixa: 'bg-ecf-yellow/[0.035] ring-ecf-yellow/[0.16]',
        ativa: 'ring-ecf-yellow/45 bg-ecf-yellow/[0.08]',
        vazio: 'Arraste para cá o que começar.',
    },
    {
        chave: 'done',
        rotulo: 'Concluído',
        icone: CheckCircle2,
        ponto: 'bg-emerald-400',
        texto: 'text-emerald-300',
        badge: 'bg-emerald-400/15 text-emerald-300',
        caixa: 'bg-emerald-400/[0.028] ring-emerald-400/[0.14]',
        ativa: 'ring-emerald-400/45 bg-emerald-400/[0.07]',
        vazio: 'Ainda nada concluído.',
    },
];

export default function ColunaPortal({ coluna, tarefas, somenteLeitura, arrastandoAlgo }) {
    const { setNodeRef, isOver } = useDroppable({
        id: coluna.chave,
        disabled: somenteLeitura,
        data: { coluna: coluna.chave },
    });

    const Icone = coluna.icone;

    return (
        <div
            ref={setNodeRef}
            data-coluna={coluna.chave}
            className={cn(
                'flex flex-col rounded-2xl ring-1 ring-inset p-3 transition-colors duration-150',
                coluna.caixa,
                isOver && coluna.ativa,
            )}
        >
            <div className="flex items-center gap-2 px-1 pb-2.5">
                <span className={cn('w-2 h-2 rounded-full shrink-0', coluna.ponto)} />
                <span className={cn('text-[12.5px] font-semibold truncate', coluna.texto)}>
                    {coluna.rotulo}
                </span>
                <span className={cn(
                    'grid place-items-center min-w-[20px] h-5 px-1.5 rounded-md text-[11px] font-bold tabular-nums ml-auto shrink-0',
                    coluna.badge,
                )}>
                    {tarefas.length}
                </span>
            </div>

            <div className="flex-1 space-y-2 min-h-[72px]">
                {tarefas.map((tarefa) => (
                    <CardTarefaPortal
                        key={tarefa.id}
                        tarefa={tarefa}
                        desabilitado={somenteLeitura}
                    />
                ))}

                {/* Alvo de soltura no fim da coluna. Ele só aparece quando há
                    um arraste em curso: fora disso seria uma caixa tracejada
                    permanente em cada coluna — três molduras vazias competindo
                    com as tarefas de verdade. */}
                {arrastandoAlgo && !somenteLeitura && (
                    <div className={cn(
                        'rounded-xl border border-dashed grid place-items-center text-[11.5px] transition-all duration-150',
                        isOver
                            ? 'border-white/35 bg-white/[0.05] text-white/70 h-[58px]'
                            : 'border-white/10 text-white/25 h-[38px]',
                    )}>
                        {isOver ? 'Solte aqui' : ''}
                    </div>
                )}

                {tarefas.length === 0 && !arrastandoAlgo && (
                    <p className="text-white/20 text-[11.5px] text-center py-5 px-2 leading-relaxed">
                        {somenteLeitura ? 'Nenhuma tarefa' : coluna.vazio}
                    </p>
                )}
            </div>
        </div>
    );
}
