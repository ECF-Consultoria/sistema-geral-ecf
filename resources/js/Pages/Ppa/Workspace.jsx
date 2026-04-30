import { useState } from 'react';
import { cn } from '@/lib/utils';
import { CheckCircle2, Circle, Clock, ArrowRight, ArrowLeft } from 'lucide-react';
import axios from 'axios';

const COLUMNS = [
    { key: 'todo',  label: 'A Fazer',      icon: Circle,        color: 'border-white/20',       dot: 'bg-white/40',      bg: 'bg-white/[0.02]' },
    { key: 'doing', label: 'Em Andamento',  icon: Clock,         color: 'border-yellow-400/40',  dot: 'bg-yellow-400',    bg: 'bg-yellow-400/[0.02]' },
    { key: 'done',  label: 'Concluído',     icon: CheckCircle2,  color: 'border-emerald-400/40', dot: 'bg-emerald-400',   bg: 'bg-emerald-400/[0.02]' },
];

function TaskCard({ task, token, onStatusChange, clientMode }) {
    const [loading, setLoading] = useState(false);

    const move = async (direction) => {
        const NEXT = { todo: 'doing', doing: 'done' };
        const PREV = { doing: 'todo', done: 'doing' };
        const newStatus = direction === 'next' ? NEXT[task.status] : PREV[task.status];
        if (!newStatus) return;

        setLoading(true);
        try {
            await axios.patch(route('ppa.workspace.task.update', { token, task: task.id }), {
                status: newStatus,
            });
            onStatusChange(task.id, newStatus);
        } catch {
            alert('Não foi possível atualizar. Tente novamente.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className={cn(
            'rounded-xl border p-4 space-y-3 transition-all',
            task.status === 'done'
                ? 'border-emerald-500/20 bg-emerald-500/[0.04]'
                : 'border-white/[0.07] bg-white/[0.03]'
        )}>
            <div className="flex items-start gap-2">
                {task.status === 'done'
                    ? <CheckCircle2 size={16} className="text-emerald-400 mt-0.5 shrink-0" />
                    : <Circle size={16} className="text-white/25 mt-0.5 shrink-0" />
                }
                <p className={cn(
                    'text-sm leading-snug flex-1',
                    task.status === 'done' ? 'text-white/50 line-through' : 'text-white/90'
                )}>
                    {task.title}
                </p>
            </div>

            {task.description && (
                <p className="text-white/40 text-xs leading-relaxed pl-6">{task.description}</p>
            )}

            {/* Botões de movimento */}
            <div className="flex gap-2 pl-6">
                {task.status !== 'todo' && (
                    <button
                        onClick={() => move('prev')}
                        disabled={loading}
                        className="flex items-center gap-1 text-xs text-white/30 hover:text-white/60 transition-colors disabled:opacity-40"
                    >
                        <ArrowLeft size={11} /> Voltar
                    </button>
                )}
                {task.status !== 'done' && (
                    <button
                        onClick={() => move('next')}
                        disabled={loading}
                        className={cn(
                            'flex items-center gap-1 text-xs transition-colors disabled:opacity-40',
                            task.status === 'doing'
                                ? 'text-emerald-400 hover:text-emerald-300'
                                : 'text-ecf-yellow hover:text-yellow-300'
                        )}
                    >
                        {task.status === 'doing' ? (
                            <><CheckCircle2 size={11} /> Marcar Concluído</>
                        ) : (
                            <><ArrowRight size={11} /> Iniciar</>
                        )}
                    </button>
                )}
            </div>
        </div>
    );
}

export default function PpaWorkspace({ ppa, tasks: initialTasks }) {
    const [tasks, setTasks] = useState(initialTasks);

    const handleStatusChange = (id, newStatus) => {
        setTasks(prev => prev.map(t => t.id === id ? { ...t, status: newStatus } : t));
    };

    const done  = tasks.filter(t => t.status === 'done').length;
    const total = tasks.length;
    const pct   = total > 0 ? Math.round((done / total) * 100) : 0;

    return (
        <div className="min-h-screen bg-[#050507] text-white">
            {/* Top bar */}
            <div className="h-[3px] bg-gradient-to-r from-yellow-400 via-yellow-300 to-yellow-500" />

            <div className="max-w-4xl mx-auto px-4 py-8 space-y-8">
                {/* Header */}
                <div className="text-center space-y-2">
                    <div className="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-ecf-yellow/10 border border-ecf-yellow/20 mb-3">
                        <span className="text-ecf-yellow font-bold text-xl">E</span>
                    </div>
                    <h1 className="text-xl font-bold text-white">{ppa.title}</h1>
                    <p className="text-white/50 text-sm">{ppa.company_name} · ECF Consultoria</p>
                </div>

                {/* Progress */}
                <div className="rounded-2xl border border-white/[0.07] bg-white/[0.02] p-5 space-y-3">
                    <div className="flex items-center justify-between">
                        <span className="text-white/60 text-sm font-medium">Progresso do Plano</span>
                        <span className="text-white font-bold">{done}/{total} tarefas</span>
                    </div>
                    <div className="h-2 rounded-full bg-white/[0.06] overflow-hidden">
                        <div
                            className="h-full rounded-full bg-gradient-to-r from-emerald-500 to-emerald-400 transition-all duration-500"
                            style={{ width: `${pct}%` }}
                        />
                    </div>
                    <p className="text-white/30 text-xs">{pct}% concluído</p>
                </div>

                {/* Columns */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {COLUMNS.map(col => {
                        const colTasks = tasks.filter(t => t.status === col.key);
                        const Icon = col.icon;
                        return (
                            <div key={col.key} className={cn('rounded-2xl border p-4 space-y-3', col.color, col.bg)}>
                                <div className="flex items-center gap-2 pb-1">
                                    <span className={cn('w-2 h-2 rounded-full', col.dot)} />
                                    <span className="text-white/70 text-sm font-semibold">{col.label}</span>
                                    <span className="text-white/25 text-xs ml-auto">{colTasks.length}</span>
                                </div>

                                {colTasks.length === 0 && (
                                    <p className="text-white/20 text-xs text-center py-4">Nenhuma tarefa</p>
                                )}

                                {colTasks.map(task => (
                                    <TaskCard
                                        key={task.id}
                                        task={task}
                                        token={ppa.token}
                                        onStatusChange={handleStatusChange}
                                        clientMode
                                    />
                                ))}
                            </div>
                        );
                    })}
                </div>

                <p className="text-center text-white/20 text-xs pb-4">ECF Consultoria · Plano Prático de Ação</p>
            </div>
        </div>
    );
}
