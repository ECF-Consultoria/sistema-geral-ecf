import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { BookOpen, Plus, Pencil, Trash2, ExternalLink, LogIn, Save } from 'lucide-react';

export default function Treinamentos({ treinamentos, canManage, linkAcesso }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [deleteId, setDeleteId] = useState(null);
    const [editingConfig, setEditingConfig] = useState(false);

    const { data, setData, processing, reset, errors } = useForm({
        titulo: '', descricao: '', url_video: '', ordem: 0, ativo: true,
    });

    const configForm = useForm({ link_acesso: linkAcesso ?? '' });

    const openCreate = () => {
        reset();
        setData({ titulo: '', descricao: '', url_video: '', ordem: 0, ativo: true });
        setEditing(null);
        setOpen(true);
    };

    const openEdit = (t) => {
        setEditing(t);
        setData({ titulo: t.titulo, descricao: t.descricao ?? '', url_video: t.url_video, ordem: t.ordem ?? 0, ativo: t.ativo });
        setOpen(true);
    };

    const submit = (e) => {
        e.preventDefault();
        if (editing) {
            router.put(route('mlb.treinamentos.update', editing.id), data, { onSuccess: () => setOpen(false) });
        } else {
            router.post(route('mlb.treinamentos.store'), data, { onSuccess: () => setOpen(false) });
        }
    };

    const submitConfig = (e) => {
        e.preventDefault();
        configForm.post(route('mlb.config'), { onSuccess: () => setEditingConfig(false) });
    };

    const doDelete = () => {
        router.delete(route('mlb.treinamentos.destroy', deleteId), { onSuccess: () => setDeleteId(null) });
    };

    const ativos   = treinamentos.filter(t => t.ativo);
    const inativos = treinamentos.filter(t => !t.ativo);

    return (
        <AppLayout title="Treinamentos">
            <div className="space-y-6 max-w-3xl">

                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-white font-semibold text-lg">Treinamentos</h2>
                        <p className="text-white/40 text-sm mt-0.5">Vídeos de treinamento para cada etapa do processo de publicação</p>
                    </div>
                    {canManage && (
                        <Button onClick={openCreate}>
                            <Plus size={15} className="mr-1.5" /> Novo Treinamento
                        </Button>
                    )}
                </div>

                {/* ── Acesso à plataforma ── */}
                <Card className="border border-ecf-yellow/20 bg-ecf-yellow/[0.04]">
                    <CardContent className="p-5">
                        {!editingConfig ? (
                            <div className="flex items-center justify-between gap-4">
                                <div>
                                    <p className="text-white/50 text-[11px] font-semibold uppercase tracking-wide mb-1.5">
                                        Passo 1 — Acesse a plataforma
                                    </p>
                                    <p className="text-white/40 text-sm">
                                        Clique no botão para entrar na plataforma. Depois volte aqui e acesse os vídeos.
                                    </p>
                                </div>
                                <div className="flex items-center gap-2 shrink-0">
                                    {linkAcesso ? (
                                        <a
                                            href={linkAcesso}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="flex items-center gap-2 px-4 py-2 rounded-lg bg-ecf-yellow text-[#1a1a1a] text-sm font-semibold hover:bg-ecf-yellow/80 transition-colors"
                                        >
                                            <LogIn size={14} /> Acessar plataforma
                                        </a>
                                    ) : (
                                        <span className="text-white/30 text-sm italic">Link não configurado</span>
                                    )}
                                    {canManage && (
                                        <Button size="sm" variant="ghost" onClick={() => setEditingConfig(true)}>
                                            <Pencil size={13} />
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ) : (
                            <form onSubmit={submitConfig} className="space-y-3">
                                <p className="text-white/50 text-[11px] font-semibold uppercase tracking-wide">
                                    Link de acesso à plataforma
                                </p>
                                <p className="text-white/30 text-xs">
                                    Cole o link de acesso automático da Cademi. Pode ser atualizado sempre que expirar.
                                </p>
                                <Input
                                    value={configForm.data.link_acesso}
                                    onChange={e => configForm.setData('link_acesso', e.target.value)}
                                    placeholder="https://url7150.cademi.com.br/ls/click?..."
                                />
                                <div className="flex gap-2 justify-end">
                                    <Button type="button" size="sm" variant="outline" onClick={() => setEditingConfig(false)}>
                                        Cancelar
                                    </Button>
                                    <Button type="submit" size="sm" disabled={configForm.processing}>
                                        <Save size={13} className="mr-1.5" />
                                        {configForm.processing ? 'Salvando...' : 'Salvar'}
                                    </Button>
                                </div>
                            </form>
                        )}
                    </CardContent>
                </Card>

                {/* ── Vídeos ── */}
                {ativos.length === 0 ? (
                    <Card>
                        <CardContent className="py-12 text-center">
                            <BookOpen className="mx-auto mb-3 text-white/20" size={36} />
                            <p className="text-white/40">Nenhum treinamento disponível ainda.</p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-3">
                        {ativos.map((t, i) => (
                            <Card key={t.id} className="border border-white/[0.08] bg-white/[0.02]">
                                <CardContent className="p-5">
                                    <div className="flex items-start gap-4">
                                        <div className="w-8 h-8 rounded-lg bg-ecf-yellow/10 border border-ecf-yellow/20 flex items-center justify-center shrink-0 mt-0.5">
                                            <span className="text-ecf-yellow text-sm font-bold">{i + 1}</span>
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <h3 className="text-white font-semibold text-[15px] leading-snug">{t.titulo}</h3>
                                                    {t.descricao && (
                                                        <p className="text-white/50 text-sm mt-1 leading-relaxed">{t.descricao}</p>
                                                    )}
                                                </div>
                                                <div className="flex items-center gap-2 shrink-0">
                                                    <a
                                                        href={t.url_video}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-ecf-yellow/10 border border-ecf-yellow/20 text-ecf-yellow text-sm font-medium hover:bg-ecf-yellow/20 transition-colors"
                                                    >
                                                        <ExternalLink size={13} /> Assistir
                                                    </a>
                                                    {canManage && (
                                                        <>
                                                            <Button size="icon" variant="ghost" onClick={() => openEdit(t)}>
                                                                <Pencil size={14} />
                                                            </Button>
                                                            <Button size="icon" variant="ghost" onClick={() => setDeleteId(t.id)}>
                                                                <Trash2 size={14} className="text-destructive/60" />
                                                            </Button>
                                                        </>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}

                {/* Inativos — só quem gerencia */}
                {canManage && inativos.length > 0 && (
                    <div className="space-y-2">
                        <p className="text-white/30 text-xs font-semibold uppercase tracking-wide">Inativos</p>
                        {inativos.map(t => (
                            <Card key={t.id} className="border border-white/[0.05] opacity-50">
                                <CardContent className="p-4 flex items-center justify-between gap-3">
                                    <div className="flex items-center gap-3">
                                        <Badge variant="outline" className="text-white/30 border-white/10">Inativo</Badge>
                                        <span className="text-white/50 text-sm">{t.titulo}</span>
                                    </div>
                                    <div className="flex gap-1">
                                        <Button size="icon" variant="ghost" onClick={() => openEdit(t)}>
                                            <Pencil size={14} />
                                        </Button>
                                        <Button size="icon" variant="ghost" onClick={() => setDeleteId(t.id)}>
                                            <Trash2 size={14} className="text-destructive/60" />
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>

            {/* Modal criar/editar */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Editar Treinamento' : 'Novo Treinamento'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Título *</Label>
                            <Input value={data.titulo} onChange={e => setData('titulo', e.target.value)} required />
                            {errors.titulo && <p className="text-destructive text-xs">{errors.titulo}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Descrição</Label>
                            <textarea
                                value={data.descricao}
                                onChange={e => setData('descricao', e.target.value)}
                                rows={3}
                                className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 resize-none"
                                placeholder="Breve descrição do conteúdo..."
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label>URL do Vídeo *</Label>
                            <Input
                                value={data.url_video}
                                onChange={e => setData('url_video', e.target.value)}
                                placeholder="https://membros.exemplo.com/aula/12345"
                                required
                            />
                            {errors.url_video && <p className="text-destructive text-xs">{errors.url_video}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5">
                                <Label>Ordem</Label>
                                <Input
                                    type="number" min={0} max={255}
                                    value={data.ordem}
                                    onChange={e => setData('ordem', parseInt(e.target.value) || 0)}
                                />
                                <p className="text-white/30 text-[11px]">Menor número aparece primeiro</p>
                            </div>
                            <div className="flex items-center gap-2 pt-6">
                                <input
                                    type="checkbox" id="ativo"
                                    checked={data.ativo}
                                    onChange={e => setData('ativo', e.target.checked)}
                                    className="w-4 h-4 rounded accent-ecf-yellow"
                                />
                                <label htmlFor="ativo" className="text-white/70 text-sm cursor-pointer">Ativo</label>
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancelar</Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Salvando...' : editing ? 'Atualizar' : 'Criar'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Confirmar exclusão */}
            <Dialog open={deleteId !== null} onOpenChange={() => setDeleteId(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader><DialogTitle>Remover treinamento?</DialogTitle></DialogHeader>
                    <p className="text-white/60 text-sm">Esta ação não pode ser desfeita.</p>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeleteId(null)}>Cancelar</Button>
                        <Button variant="destructive" onClick={doDelete}>Remover</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
