import AppLayout from '@/Layouts/AppLayout';
import { useForm, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    Plus, Trash2, Pencil, Check, X, Copy, CheckCircle,
    ArrowRight, ArrowLeft, ExternalLink, Link as LinkIcon
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

const COLUMNS = [
    { key: 'todo',  label: 'A Fazer',       color: 'border-white/20',      dot: 'bg-white/30' },
    { key: 'doing', label: 'Em Andamento',   color: 'border-ecf-yellow/40', dot: 'bg-ecf-yellow' },
    { key: 'done',  label: 'Concluído',      color: 'border-emerald-500/40', dot: 'bg-emerald-400' },
];

function TaskCard({ task, onMove, onEdit, onDelete, ppaId }) {
    return (
        <div className="group rounded-xl border border-white/[0.07] bg-white/[0.03] p-3.5 space-y-2 hover:border-white/[0.14] transition-all">
            <p className="text-white/90 text-sm leading-snug">{task.title}</p>
            {task.description && (
                <p className="text-white/40 text-xs leading-relaxed">{task.description}</p>
            )}
            <div className="flex items-center justify-between pt-1">
                <div className="flex gap-1">
                    {task.status !== 'todo' && (
                        <button
                            onClick={() => onMove(task, 'prev')}
                            className="p-1 rounded text-white/30 hover:text-white/70 hover:bg-white/[0.06] transition-all"
                            title="Voltar coluna"
                        >
                            <ArrowLeft size={13} />
                        </button>
                    )}
                    {task.status !== 'done' && (
                        <button
                            onClick={() => onMove(task, 'next')}
                            className="p-1 rounded text-white/30 hover:text-ecf-yellow hover:bg-ecf-yellow/10 transition-all"
                            title="Avançar coluna"
                        >
                            <ArrowRight size={13} />
                        </button>
                    )}
                </div>
                <div className="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button
                        onClick={() => onEdit(task)}
                        className="p-1 rounded text-white/30 hover:text-white/70 hover:bg-white/[0.06] transition-all"
                    >
                        <Pencil size={12} />
                    </button>
                    <button
                        onClick={() => onDelete(task.id)}
                        className="p-1 rounded text-white/30 hover:text-red-400 hover:bg-red-400/10 transition-all"
                    >
                        <Trash2 size={12} />
                    </button>
                </div>
            </div>
        </div>
    );
}

export default function PpaKanban({ ppa, tasks: initialTasks }) {
    const { flash } = usePage().props;
    const [tasks, setTasks] = useState(initialTasks);
    const [editTask, setEditTask] = useState(null);
    const [addingCol, setAddingCol] = useState(null);
    const [newTitle, setNewTitle] = useState('');
    const [newDesc, setNewDesc] = useState('');
    const [copied, setCopied] = useState(false);
    const [linkDialog, setLinkDialog] = useState(false);
    const [workspaceUrl, setWorkspaceUrl] = useState(ppa.workspace_url || '');

    const editForm = useForm({ title: '', description: '', status: '' });

    const NEXT = { todo: 'doing', doing: 'done' };
    const PREV = { doing: 'todo', done: 'doing' };

    const moveTask = (task, direction) => {
        const newStatus = direction === 'next' ? NEXT[task.status] : PREV[task.status];
        if (!newStatus) return;

        // Optimistic update
        setTasks(prev => prev.map(t => t.id === task.id ? { ...t, status: newStatus } : t));

        router.put(route('ppa.tasks.update', task.id), { status: newStatus }, {
            preserveScroll: true,
            onError: () => setTasks(initialTasks),
        });
    };

    const deleteTask = (id) => {
        if (!confirm('Remover esta tarefa?')) return;
        setTasks(prev => prev.filter(t => t.id !== id));
        router.delete(route('ppa.tasks.destroy', id), { preserveScroll: true });
    };

    const addTask = (col) => {
        if (!newTitle.trim()) return;
        router.post(route('ppa.tasks.store', ppa.id), {
            title: newTitle.trim(),
            description: newDesc.trim() || null,
            status: col,
        }, {
            preserveScroll: true,
            onSuccess: (page) => {
                setNewTitle('');
                setNewDesc('');
                setAddingCol(null);
                // Recarrega tarefas do servidor
                router.reload({ only: ['tasks'] });
            },
        });
    };

    const openEdit = (task) => {
        setEditTask(task);
        editForm.setData({ title: task.title, description: task.description || '', status: task.status });
    };

    const submitEdit = (e) => {
        e.preventDefault();
        editForm.put(route('ppa.tasks.update', editTask.id), {
            preserveScroll: true,
            onSuccess: () => {
                setTasks(prev => prev.map(t => t.id === editTask.id ? { ...t, ...editForm.data } : t));
                setEditTask(null);
            },
        });
    };

    const generateLink = () => {
        router.post(route('ppa.workspace.generate', ppa.id), {}, {
            preserveScroll: true,
            onSuccess: (page) => {
                const url = page.props.flash?.workspace_url || '';
                setWorkspaceUrl(url);
                setLinkDialog(true);
            },
        });
    };

    const copyLink = () => {
        navigator.clipboard.writeText(workspaceUrl);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <AppLayout title={`Kanban — ${ppa.title}`}>
            <div className="space-y-5">
                {/* Header */}
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <p className="text-white/60 text-sm">{ppa.company_name}</p>
                        <h2 className="text-white font-semibold text-base mt-0.5">{ppa.title}</h2>
                    </div>
                    <div className="flex gap-2 shrink-0">
                        {workspaceUrl ? (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setLinkDialog(true)}
                            >
                                <LinkIcon size={14} className="mr-1.5" /> Ver Link do Cliente
                            </Button>
                        ) : (
                            <Button size="sm" variant="outline" onClick={generateLink}>
                                <LinkIcon size={14} className="mr-1.5" /> Gerar Link do Cliente
                            </Button>
                        )}
                        <Button size="sm" variant="ghost" onClick={() => router.get(route('ppa.index'))}>
                            ← Voltar
                        </Button>
                    </div>
                </div>

                {/* Kanban board */}
                <div className="grid grid-cols-3 gap-4">
                    {COLUMNS.map(col => {
                        const colTasks = tasks.filter(t => t.status === col.key);
                        return (
                            <div key={col.key} className={cn('rounded-2xl border bg-white/[0.02] p-4 space-y-3', col.color)}>
                                {/* Column header */}
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <span className={cn('w-2 h-2 rounded-full', col.dot)} />
                                        <span className="text-white/70 text-sm font-semibold">{col.label}</span>
                                        <span className="text-white/25 text-xs">{colTasks.length}</span>
                                    </div>
                                    <button
                                        onClick={() => { setAddingCol(col.key); setNewTitle(''); setNewDesc(''); }}
                                        className="p-1 rounded text-white/30 hover:text-white/70 hover:bg-white/[0.06] transition-all"
                                        title="Adicionar tarefa"
                                    >
                                        <Plus size={14} />
                                    </button>
                                </div>

                                {/* Tasks */}
                                <div className="space-y-2">
                                    {colTasks.map(task => (
                                        <TaskCard
                                            key={task.id}
                                            task={task}
                                            ppaId={ppa.id}
                                            onMove={moveTask}
                                            onEdit={openEdit}
                                            onDelete={deleteTask}
                                        />
                                    ))}
                                </div>

                                {/* Inline add form */}
                                {addingCol === col.key && (
                                    <div className="rounded-xl border border-white/10 bg-white/[0.04] p-3 space-y-2">
                                        <Input
                                            autoFocus
                                            value={newTitle}
                                            onChange={e => setNewTitle(e.target.value)}
                                            placeholder="Título da tarefa..."
                                            className="text-sm h-8"
                                            onKeyDown={e => e.key === 'Enter' && addTask(col.key)}
                                        />
                                        <Textarea
                                            value={newDesc}
                                            onChange={e => setNewDesc(e.target.value)}
                                            placeholder="Descrição (opcional)"
                                            rows={2}
                                            className="text-xs resize-none"
                                        />
                                        <div className="flex gap-1.5">
                                            <Button size="sm" className="h-7 text-xs" onClick={() => addTask(col.key)}>
                                                <Check size={11} className="mr-1" /> Adicionar
                                            </Button>
                                            <Button size="sm" variant="ghost" className="h-7 text-xs" onClick={() => setAddingCol(null)}>
                                                <X size={11} />
                                            </Button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* Dialog: editar tarefa */}
            <Dialog open={!!editTask} onOpenChange={() => setEditTask(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Editar Tarefa</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitEdit} className="space-y-3">
                        <Input
                            value={editForm.data.title}
                            onChange={e => editForm.setData('title', e.target.value)}
                            placeholder="Título"
                        />
                        <Textarea
                            value={editForm.data.description}
                            onChange={e => editForm.setData('description', e.target.value)}
                            placeholder="Descrição (opcional)"
                            rows={3}
                        />
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setEditTask(null)}>Cancelar</Button>
                            <Button type="submit" disabled={editForm.processing}>Salvar</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog: link do workspace */}
            <Dialog open={linkDialog} onOpenChange={setLinkDialog}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <CheckCircle className="h-5 w-5 text-emerald-400" /> Link do Quadro para o Cliente
                        </DialogTitle>
                    </DialogHeader>
                    <p className="text-muted-foreground text-sm">Envie este link para o cliente. Ele poderá ver as tarefas e marcar como concluídas.</p>
                    <div className="flex items-center gap-2 p-3 bg-muted rounded-lg">
                        <p className="text-sm text-foreground flex-1 break-all">{workspaceUrl}</p>
                        <Button size="icon" variant="ghost" onClick={copyLink}>
                            {copied ? <CheckCircle className="h-4 w-4 text-emerald-400" /> : <Copy className="h-4 w-4" />}
                        </Button>
                    </div>
                    <div className="flex justify-between">
                        <Button variant="outline" size="sm" onClick={() => window.open(workspaceUrl, '_blank')}>
                            <ExternalLink size={13} className="mr-1.5" /> Abrir
                        </Button>
                        <Button onClick={() => setLinkDialog(false)}>Fechar</Button>
                    </div>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
