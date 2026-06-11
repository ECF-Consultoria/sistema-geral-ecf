import { useForm, router, Link } from '@inertiajs/react';
import { useState } from 'react';
import { Tag, Plus, Pencil, Trash2, X, Briefcase } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';

/**
 * Gestão de grupos nomeados de empresas (tipo carteira) — compartilhada entre
 * /companies (aba Grupos) e /administrativo/empresas (aba Grupos), pra ficarem
 * idênticas. Usa as rotas company-groups.* e companies.set-group.
 *
 * Props:
 *   grupos    — [{ id, name, color, companies_count }]
 *   companies — [{ id, name, active, company_group_id, adman_account_id, ml_store_id }]
 */

// Badge colorido do grupo (reaproveitado nas linhas das tabelas).
export function GrupoBadge({ grupo }) {
    if (!grupo) return null;
    return (
        <span
            title={`Grupo: ${grupo.name}`}
            className="inline-flex items-center gap-1 text-[10px] font-medium px-1.5 py-0.5 rounded-full border"
            style={{ borderColor: `${grupo.color}55`, backgroundColor: `${grupo.color}18`, color: grupo.color }}
        >
            <Tag size={9} />{grupo.name}
        </span>
    );
}

// Campo buscável (nome ou cust id) para adicionar empresa ao grupo. Resultados
// renderizados INLINE (empurram o conteúdo) — evita clipping por overflow.
function AddCompanyPicker({ candidatos, onAdd }) {
    const [q, setQ] = useState('');
    const [aberto, setAberto] = useState(false);
    const ql = q.trim().toLowerCase();
    const matches = (ql
        ? candidatos.filter(c =>
            c.name.toLowerCase().includes(ql) ||
            String(c.adman_account_id || '').includes(ql) ||
            String(c.ml_store_id || '').includes(ql))
        : candidatos
    ).slice(0, 8);

    return (
        <div className="mt-1">
            <input
                value={q}
                onChange={e => { setQ(e.target.value); setAberto(true); }}
                onFocus={() => setAberto(true)}
                onBlur={() => setTimeout(() => setAberto(false), 150)}
                placeholder="+ Adicionar empresa (nome ou cust id)…"
                className="w-full h-9 px-3 rounded-lg border border-dashed border-white/[0.12] bg-transparent text-[13px] text-white/70 placeholder:text-white/35 focus:outline-none focus:border-ecf-yellow/40"
            />
            {aberto && matches.length > 0 && (
                <div className="mt-1 max-h-56 overflow-auto rounded-lg border border-white/10 bg-[#0b0c10] py-1">
                    {matches.map(c => (
                        <button
                            key={c.id}
                            type="button"
                            onMouseDown={e => e.preventDefault()}
                            onClick={() => { onAdd(c.id); setQ(''); setAberto(false); }}
                            className="w-full text-left px-3 py-1.5 hover:bg-white/[0.06] flex items-center justify-between gap-2"
                        >
                            <span className="text-white/80 text-[13px] truncate">{c.name}</span>
                            <span className="text-white/35 text-[11px] font-mono shrink-0">{c.adman_account_id || c.ml_store_id || '—'}</span>
                        </button>
                    ))}
                </div>
            )}
            {aberto && ql && matches.length === 0 && (
                <div className="mt-1 rounded-lg border border-white/10 bg-[#0b0c10] px-3 py-2 text-[12px] text-white/40">
                    Nenhuma empresa encontrada.
                </div>
            )}
        </div>
    );
}

export default function GruposManager({ grupos = [], companies = [], servicos = [] }) {
    const grupoForm = useForm({ name: '', color: '#ffe600' });
    const [grupoModalOpen, setGrupoModalOpen] = useState(false);
    const [editingGrupo, setEditingGrupo] = useState(null);

    // Atribuir serviço ao grupo inteiro
    const servicoForm = useForm({ servico_id: '', valor_contratado: '', data_contratacao: '', data_vencimento: '', observacoes: '' });
    const [servicoModalOpen, setServicoModalOpen] = useState(false);
    const [servicoGrupo, setServicoGrupo] = useState(null);

    const openServicoModal = (g) => {
        setServicoGrupo(g);
        servicoForm.setData({ servico_id: '', valor_contratado: '', data_contratacao: new Date().toISOString().slice(0, 10), data_vencimento: '', observacoes: '' });
        setServicoModalOpen(true);
    };
    const escolherServico = (id) => {
        const s = servicos.find(x => String(x.id) === String(id));
        servicoForm.setData('servico_id', id);
        if (s) servicoForm.setData('valor_contratado', s.valor_padrao);
    };
    const submitServico = (e) => {
        e.preventDefault();
        servicoForm.post(route('company-groups.atribuir-servico', servicoGrupo.id), {
            preserveScroll: true,
            onSuccess: () => setServicoModalOpen(false),
        });
    };

    const openCreateGrupo = () => {
        setEditingGrupo(null);
        grupoForm.setData({ name: '', color: '#ffe600' });
        setGrupoModalOpen(true);
    };
    const openEditGrupo = (g) => {
        setEditingGrupo(g);
        grupoForm.setData({ name: g.name, color: g.color || '#ffe600' });
        setGrupoModalOpen(true);
    };
    const submitGrupo = (e) => {
        e.preventDefault();
        const onSuccess = () => setGrupoModalOpen(false);
        if (editingGrupo) {
            grupoForm.put(route('company-groups.update', editingGrupo.id), { onSuccess });
        } else {
            grupoForm.post(route('company-groups.store'), { onSuccess });
        }
    };
    const deleteGrupo = (g) => {
        if (confirm(`Excluir o grupo "${g.name}"? As empresas serão desvinculadas (não excluídas).`)) {
            router.delete(route('company-groups.destroy', g.id), { preserveScroll: true });
        }
    };
    const setGroup = (companyId, groupId) => {
        router.put(route('companies.set-group', companyId), { company_group_id: groupId }, { preserveScroll: true });
    };

    return (
        <>
            <div className="flex items-center justify-between">
                <p className="text-white/50 text-[13px]">Agrupe empresas em carteiras nomeadas para visão e gestão conjunta.</p>
                <Button onClick={openCreateGrupo}><Plus className="h-4 w-4 mr-1" /> Novo Grupo</Button>
            </div>

            {grupos.length === 0 && (
                <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-10 text-center text-white/40">
                    <Tag className="h-8 w-8 mx-auto mb-2 opacity-40" />
                    Nenhum grupo criado ainda. Clique em "Novo Grupo" para começar.
                </div>
            )}

            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                {grupos.map(g => {
                    const membros = companies.filter(c => c.company_group_id === g.id);
                    const semGrupo = companies.filter(c => c.active && !c.company_group_id);
                    return (
                        <div key={g.id} className="rounded-2xl border bg-white/[0.02]" style={{ borderColor: `${g.color}40` }}>
                            <div className="px-4 py-3 flex items-center justify-between rounded-t-2xl" style={{ backgroundColor: `${g.color}14` }}>
                                <div className="flex items-center gap-2 min-w-0">
                                    <span className="w-3 h-3 rounded-full shrink-0" style={{ backgroundColor: g.color }} />
                                    <span className="text-white font-semibold text-[14px] truncate">{g.name}</span>
                                    <span className="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-white/10 text-white/70 text-[10px] font-bold">{g.companies_count}</span>
                                </div>
                                <div className="flex items-center gap-1 shrink-0">
                                    <button onClick={() => openServicoModal(g)} className="p-1 text-white/40 hover:text-ecf-yellow transition-colors" title="Atribuir serviço a todas as empresas do grupo"><Briefcase size={13} /></button>
                                    <button onClick={() => openEditGrupo(g)} className="p-1 text-white/40 hover:text-white transition-colors" title="Editar grupo"><Pencil size={13} /></button>
                                    <button onClick={() => deleteGrupo(g)} className="p-1 text-white/40 hover:text-red-400 transition-colors" title="Excluir grupo"><Trash2 size={13} /></button>
                                </div>
                            </div>
                            <div className="p-3 space-y-1.5">
                                {membros.length === 0 && <p className="text-white/30 text-[12px] px-1 py-2">Nenhuma empresa neste grupo.</p>}
                                {membros.map(c => (
                                    <div key={c.id} className="flex items-center justify-between gap-2 rounded-lg bg-white/[0.03] px-3 py-1.5">
                                        <Link href={route('companies.show', c.id)} className="text-white/80 text-[13px] truncate hover:text-ecf-yellow">{c.name}</Link>
                                        <button onClick={() => setGroup(c.id, null)} className="p-0.5 text-white/30 hover:text-red-400 transition-colors shrink-0" title="Remover do grupo"><X size={13} /></button>
                                    </div>
                                ))}
                                <AddCompanyPicker candidatos={semGrupo} onAdd={(cid) => setGroup(cid, g.id)} />
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* Modal criar/editar grupo */}
            <Dialog open={grupoModalOpen} onOpenChange={setGrupoModalOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{editingGrupo ? 'Editar Grupo' : 'Novo Grupo'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitGrupo} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Nome do grupo *</Label>
                            <Input value={grupoForm.data.name} onChange={e => grupoForm.setData('name', e.target.value)} placeholder="Ex: Grupo Camillo, Carteira Premium" required />
                            {grupoForm.errors.name && <p className="text-destructive text-xs">{grupoForm.errors.name}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Cor</Label>
                            <div className="flex items-center gap-3">
                                <input type="color" value={grupoForm.data.color} onChange={e => grupoForm.setData('color', e.target.value)} className="h-9 w-14 rounded border border-white/10 bg-transparent cursor-pointer" />
                                <span className="inline-flex items-center gap-1.5 text-[12px] px-2 py-1 rounded-full border" style={{ borderColor: `${grupoForm.data.color}55`, backgroundColor: `${grupoForm.data.color}18`, color: grupoForm.data.color }}>
                                    <Tag size={11} /> {grupoForm.data.name || 'Prévia'}
                                </span>
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setGrupoModalOpen(false)}>Cancelar</Button>
                            <Button type="submit" disabled={grupoForm.processing}>{grupoForm.processing ? 'Salvando...' : editingGrupo ? 'Atualizar' : 'Criar'}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Modal atribuir serviço ao grupo inteiro */}
            <Dialog open={servicoModalOpen} onOpenChange={setServicoModalOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Atribuir serviço ao grupo{servicoGrupo ? ` — ${servicoGrupo.name}` : ''}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitServico} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Serviço *</Label>
                            <select
                                required
                                value={servicoForm.data.servico_id}
                                onChange={e => escolherServico(e.target.value)}
                                className="w-full h-9 px-3 rounded-lg border border-white/10 bg-white/[0.03] text-[13px] text-white focus:outline-none focus:border-ecf-yellow/40"
                            >
                                <option value="">Selecionar...</option>
                                {servicos.map(s => (
                                    <option key={s.id} value={s.id} className="bg-[#0f1116]">
                                        {s.nome} — {s.tipo_cobranca === 'mensal' ? 'Mensal' : 'Única'}
                                    </option>
                                ))}
                            </select>
                            {servicoForm.errors.servico_id && <p className="text-destructive text-xs">{servicoForm.errors.servico_id}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Valor contratado (R$) *</Label>
                            <Input type="number" step="0.01" min="0" value={servicoForm.data.valor_contratado} onChange={e => servicoForm.setData('valor_contratado', e.target.value)} required />
                            {servicoForm.errors.valor_contratado && <p className="text-destructive text-xs">{servicoForm.errors.valor_contratado}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Contratação *</Label>
                                <Input type="date" value={servicoForm.data.data_contratacao} onChange={e => servicoForm.setData('data_contratacao', e.target.value)} required />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Vencimento</Label>
                                <Input type="date" value={servicoForm.data.data_vencimento} onChange={e => servicoForm.setData('data_vencimento', e.target.value)} />
                            </div>
                        </div>
                        <p className="text-white/40 text-[11px] leading-relaxed">
                            Cria o contrato em <span className="text-white/70">todas as empresas do grupo</span>. Quem já tiver esse serviço ativo é pulado.
                        </p>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setServicoModalOpen(false)}>Cancelar</Button>
                            <Button type="submit" disabled={servicoForm.processing}>{servicoForm.processing ? 'Aplicando...' : 'Atribuir ao grupo'}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
