// Página /comercial/empresas — listagem e gestão de empresas pelo setor Comercial.
//
// Props do ComercialController::empresas():
//   companies: [{ id, name, cnpj, service_type, status, created_at, adman_account_id, ml_store_id, notes }]
//   service_type é um array JSON ex: ['polos', 'gestao']
import { useState } from 'react';
import { Head, useForm, usePage, router } from '@inertiajs/react';
import { Building2, PlusCircle, Search, X, Pencil, Trash2, AlertTriangle } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';

const TIPOS = [
    { value: 'polos',       label: 'Publicação — POLOS' },
    { value: 'assessoria',  label: 'Publicação — Assessoria' },
    { value: 'publicidade', label: 'Publicidade' },
    { value: 'gestao',      label: 'Gestão' },
];

const SERVICE_TYPE_LABELS = {
    polos: 'POLO', assessoria: 'Assessoria', publicidade: 'Publicidade', gestao: 'Gestão',
};

const SERVICE_TYPE_COLORS = {
    polos:       'text-blue-300 bg-blue-950/60 border-blue-500/20',
    assessoria:  'text-purple-300 bg-purple-950/60 border-purple-500/20',
    publicidade: 'text-orange-300 bg-orange-950/60 border-orange-500/20',
    gestao:      'text-teal-300 bg-teal-950/60 border-teal-500/20',
};

const fmtDate = (iso) => {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: '2-digit' });
};

function TypeBadges({ types }) {
    const arr = Array.isArray(types) ? types : (types ? [types] : []);
    if (arr.length === 0) return <span className="text-white/20 text-xs">—</span>;
    return (
        <span className="inline-flex flex-wrap gap-1">
            {arr.map(t => (
                <span key={t} className={cn('text-xs font-medium px-2 py-0.5 rounded-full border', SERVICE_TYPE_COLORS[t])}>
                    {SERVICE_TYPE_LABELS[t] ?? t}
                </span>
            ))}
        </span>
    );
}

// ─── Checkboxes de tipo de serviço (reutilizado nos dois forms) ───────────────

function TiposCheckbox({ value, onChange, error }) {
    const temPolos      = value.includes('polos');
    const temAssessoria = value.includes('assessoria');
    const conflito      = temPolos && temAssessoria;

    function toggle(tipo) {
        onChange(value.includes(tipo) ? value.filter(t => t !== tipo) : [...value, tipo]);
    }

    return (
        <div className="space-y-1.5">
            <label className="block text-xs text-white/60 font-medium">
                Tipo de Serviço <span className="text-ecf-yellow">*</span>
            </label>
            <div className="grid grid-cols-2 gap-2">
                {TIPOS.map(({ value: v, label }) => (
                    <label key={v} className={cn(
                        'flex items-center gap-2 px-3 py-2 rounded-lg border cursor-pointer transition-colors',
                        value.includes(v)
                            ? 'border-ecf-yellow/40 bg-ecf-yellow/5 text-white'
                            : 'border-white/[0.08] bg-white/[0.02] text-white/50 hover:text-white/70'
                    )}>
                        <input type="checkbox" checked={value.includes(v)}
                            onChange={() => toggle(v)} className="accent-ecf-yellow shrink-0" />
                        <span className="text-[12px] leading-tight">{label}</span>
                    </label>
                ))}
            </div>
            {conflito && (
                <p className="text-red-400 text-xs">POLOS e Assessoria são mutuamente exclusivos.</p>
            )}
            {error && <p className="text-red-400 text-xs">{Array.isArray(error) ? error[0] : error}</p>}
            {value.includes('polos') && !conflito && (
                <p className="text-white/30 text-[11px] leading-snug">Cria empresa + registro MLB (POLOS) + implementação automaticamente.</p>
            )}
            {value.includes('assessoria') && !conflito && (
                <p className="text-white/30 text-[11px] leading-snug">Cria empresa + registro MLB (Assessoria). Implementação não é criada.</p>
            )}
        </div>
    );
}

// ─── Modal base ───────────────────────────────────────────────────────────────

function Modal({ open, onClose, children }) {
    if (!open) return null;
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/70" onClick={onClose} />
            <div className="relative z-10 w-full max-w-lg rounded-2xl border border-white/[0.08] bg-ecf-card shadow-2xl max-h-[90vh] overflow-y-auto">
                {children}
            </div>
        </div>
    );
}

// ─── Formulário de Nova Empresa ───────────────────────────────────────────────

function FormularioCriar({ onClose }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        nome:         '',
        cnpj:         '',
        service_type: [],
    });

    function handleSubmit(e) {
        e.preventDefault();
        post(route('comercial.empresas.store'), {
            preserveScroll: true,
            onSuccess: () => { reset(); onClose(); },
        });
    }

    return (
        <form onSubmit={handleSubmit} className="p-6 space-y-5">
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-2.5">
                    <div className="flex items-center justify-center w-8 h-8 rounded-lg bg-ecf-yellow/10 border border-ecf-yellow/20 shrink-0">
                        <PlusCircle size={15} className="text-ecf-yellow" />
                    </div>
                    <h2 className="text-white font-bold text-base">Nova Empresa</h2>
                </div>
                <button type="button" onClick={onClose} className="text-white/30 hover:text-white/70 transition-colors">
                    <X size={18} />
                </button>
            </div>

            <div className="space-y-1.5">
                <label className="block text-xs text-white/60 font-medium">
                    Nome da Empresa <span className="text-ecf-yellow">*</span>
                </label>
                <input type="text" value={data.nome} onChange={e => setData('nome', e.target.value)}
                    placeholder="Ex: Empresa XYZ Ltda" autoFocus required
                    className={cn('w-full bg-white/[0.04] border rounded-lg px-3 py-2.5 text-white text-sm',
                        'placeholder:text-white/20 focus:outline-none focus:border-ecf-yellow/40 transition-colors',
                        errors.nome ? 'border-red-500/50' : 'border-white/[0.08]')} />
                {errors.nome && <p className="text-red-400 text-xs">{errors.nome}</p>}
            </div>

            <div className="space-y-1.5">
                <label className="block text-xs text-white/60 font-medium">
                    CNPJ <span className="text-white/30 text-[11px] font-normal">(opcional)</span>
                </label>
                <input type="text" value={data.cnpj} onChange={e => setData('cnpj', e.target.value)}
                    placeholder="00.000.000/0001-00"
                    className={cn('w-full bg-white/[0.04] border rounded-lg px-3 py-2.5 text-white text-sm',
                        'placeholder:text-white/20 focus:outline-none focus:border-ecf-yellow/40 transition-colors',
                        errors.cnpj ? 'border-red-500/50' : 'border-white/[0.08]')} />
                {errors.cnpj && <p className="text-red-400 text-xs">{errors.cnpj}</p>}
            </div>

            <TiposCheckbox value={data.service_type} onChange={v => setData('service_type', v)} error={errors.service_type} />

            <div className="flex items-center justify-end gap-3 pt-1">
                <button type="button" onClick={onClose}
                    className="px-4 py-2 rounded-lg text-sm text-white/50 hover:text-white/80 transition-colors">
                    Cancelar
                </button>
                <button type="submit" disabled={processing}
                    className="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-bold transition-all bg-ecf-yellow text-black hover:bg-yellow-300 disabled:opacity-50 disabled:cursor-not-allowed">
                    <PlusCircle size={14} />
                    {processing ? 'Cadastrando...' : 'Cadastrar'}
                </button>
            </div>
        </form>
    );
}

// ─── Formulário de Edição ─────────────────────────────────────────────────────

function FormularioEditar({ company, onClose }) {
    const [confirmandoExclusao, setConfirmandoExclusao] = useState(false);
    const [excluindo, setExcluindo] = useState(false);

    const { data, setData, put, processing, errors } = useForm({
        name:         company.name,
        cnpj:         company.cnpj ?? '',
        notes:        company.notes ?? '',
        service_type: Array.isArray(company.service_type) ? company.service_type
                        : (company.service_type ? [company.service_type] : []),
    });

    function handleSubmit(e) {
        e.preventDefault();
        put(route('comercial.empresas.update', company.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    }

    function handleExcluir() {
        setExcluindo(true);
        router.delete(route('comercial.empresas.destroy', company.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onFinish: () => setExcluindo(false),
        });
    }

    const novoTipoTemMlb = data.service_type.some(t => ['polos', 'assessoria'].includes(t));
    const originalTemMlb = (Array.isArray(company.service_type) ? company.service_type : [company.service_type])
        .some(t => ['polos', 'assessoria'].includes(t));

    return (
        <form onSubmit={handleSubmit} className="p-6 space-y-5">
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-2.5">
                    <div className="flex items-center justify-center w-8 h-8 rounded-lg bg-white/[0.06] border border-white/[0.08] shrink-0">
                        <Pencil size={14} className="text-white/60" />
                    </div>
                    <h2 className="text-white font-bold text-base leading-tight">Editar Empresa</h2>
                </div>
                <button type="button" onClick={onClose} className="text-white/30 hover:text-white/70 transition-colors">
                    <X size={18} />
                </button>
            </div>

            <div className="space-y-1.5">
                <label className="block text-xs text-white/60 font-medium">
                    Nome da Empresa <span className="text-ecf-yellow">*</span>
                </label>
                <input type="text" value={data.name} onChange={e => setData('name', e.target.value)}
                    autoFocus required
                    className={cn('w-full bg-white/[0.04] border rounded-lg px-3 py-2.5 text-white text-sm',
                        'placeholder:text-white/20 focus:outline-none focus:border-ecf-yellow/40 transition-colors',
                        errors.name ? 'border-red-500/50' : 'border-white/[0.08]')} />
                {errors.name && <p className="text-red-400 text-xs">{errors.name}</p>}
            </div>

            <div className="space-y-1.5">
                <label className="block text-xs text-white/60 font-medium">
                    CNPJ <span className="text-white/30 text-[11px] font-normal">(opcional)</span>
                </label>
                <input type="text" value={data.cnpj} onChange={e => setData('cnpj', e.target.value)}
                    placeholder="00.000.000/0001-00"
                    className={cn('w-full bg-white/[0.04] border rounded-lg px-3 py-2.5 text-white text-sm',
                        'placeholder:text-white/20 focus:outline-none focus:border-ecf-yellow/40 transition-colors',
                        errors.cnpj ? 'border-red-500/50' : 'border-white/[0.08]')} />
                {errors.cnpj && <p className="text-red-400 text-xs">{errors.cnpj}</p>}
            </div>

            <TiposCheckbox value={data.service_type} onChange={v => setData('service_type', v)} error={errors.service_type} />

            {novoTipoTemMlb && !originalTemMlb && (
                <p className="text-yellow-400/70 text-[11px] leading-snug -mt-2">
                    Registro MLB será criado automaticamente se não existir.
                </p>
            )}

            <div className="space-y-1.5">
                <label className="block text-xs text-white/60 font-medium">
                    Observações <span className="text-white/30 text-[11px] font-normal">(opcional)</span>
                </label>
                <textarea value={data.notes} onChange={e => setData('notes', e.target.value)} rows={3}
                    placeholder="Notas internas sobre a empresa..."
                    className={cn('w-full bg-white/[0.04] border rounded-lg px-3 py-2.5 text-white text-sm resize-none',
                        'placeholder:text-white/20 focus:outline-none focus:border-ecf-yellow/40 transition-colors',
                        errors.notes ? 'border-red-500/50' : 'border-white/[0.08]')} />
                {errors.notes && <p className="text-red-400 text-xs">{errors.notes}</p>}
            </div>

            {confirmandoExclusao ? (
                <div className="rounded-xl border border-red-500/20 bg-red-950/40 p-4 space-y-3">
                    <div className="flex items-center gap-2 text-red-300">
                        <AlertTriangle size={14} />
                        <span className="text-sm font-medium">Confirmar remoção</span>
                    </div>
                    <p className="text-white/50 text-xs leading-relaxed">
                        A empresa <strong className="text-white/80">{company.name}</strong> será removida da listagem.
                        Os registros relacionados (MLB, sugadores) são preservados.
                    </p>
                    <div className="flex items-center gap-2">
                        <button type="button" onClick={handleExcluir} disabled={excluindo}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold bg-red-600 hover:bg-red-500 text-white transition-colors disabled:opacity-50">
                            <Trash2 size={12} />
                            {excluindo ? 'Removendo...' : 'Confirmar remoção'}
                        </button>
                        <button type="button" onClick={() => setConfirmandoExclusao(false)}
                            className="px-3 py-1.5 rounded-lg text-xs text-white/40 hover:text-white/70 transition-colors">
                            Cancelar
                        </button>
                    </div>
                </div>
            ) : (
                <button type="button" onClick={() => setConfirmandoExclusao(true)}
                    className="inline-flex items-center gap-1.5 text-xs text-red-400/60 hover:text-red-400 transition-colors">
                    <Trash2 size={12} />
                    Remover empresa
                </button>
            )}

            <div className="flex items-center justify-end gap-3 pt-1 border-t border-white/[0.06]">
                <button type="button" onClick={onClose}
                    className="px-4 py-2 rounded-lg text-sm text-white/50 hover:text-white/80 transition-colors">
                    Cancelar
                </button>
                <button type="submit" disabled={processing}
                    className="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-bold transition-all bg-ecf-yellow text-black hover:bg-yellow-300 disabled:opacity-50 disabled:cursor-not-allowed">
                    {processing ? 'Salvando...' : 'Salvar Alterações'}
                </button>
            </div>
        </form>
    );
}

// ─── Página principal ─────────────────────────────────────────────────────────

export default function Empresas({ companies }) {
    const { flash } = usePage().props;
    const [modalCriar, setModalCriar] = useState(false);
    const [empresaEditar, setEmpresaEditar] = useState(null);
    const [filtroTexto, setFiltroTexto] = useState('');
    const [filtroTipo, setFiltroTipo] = useState('');

    const empresasFiltradas = companies.filter(c => {
        const types = Array.isArray(c.service_type) ? c.service_type : (c.service_type ? [c.service_type] : []);
        const textoOk = !filtroTexto
            || c.name.toLowerCase().includes(filtroTexto.toLowerCase())
            || (c.cnpj && c.cnpj.includes(filtroTexto));
        const tipoOk = !filtroTipo || types.includes(filtroTipo);
        return textoOk && tipoOk;
    });

    const pendentes = empresasFiltradas.filter(c => c.status === 'pendente');
    const ativos    = empresasFiltradas.filter(c => c.status !== 'pendente');

    return (
        <AppLayout title="Empresas">
            <Head title="Empresas — Comercial" />

            <Modal open={modalCriar} onClose={() => setModalCriar(false)}>
                <FormularioCriar onClose={() => setModalCriar(false)} />
            </Modal>

            <Modal open={!!empresaEditar} onClose={() => setEmpresaEditar(null)}>
                {empresaEditar && (
                    <FormularioEditar company={empresaEditar} onClose={() => setEmpresaEditar(null)} />
                )}
            </Modal>

            <div className="space-y-6">
                <div className="flex items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <div className="flex items-center justify-center w-10 h-10 rounded-xl bg-ecf-yellow/10 border border-ecf-yellow/20 shrink-0">
                            <Building2 size={18} className="text-ecf-yellow" />
                        </div>
                        <div>
                            <h1 className="text-white font-bold text-lg leading-tight">Empresas</h1>
                            <p className="text-white/40 text-[13px]">{companies.length} empresa{companies.length !== 1 ? 's' : ''} cadastrada{companies.length !== 1 ? 's' : ''}</p>
                        </div>
                    </div>
                    <button onClick={() => setModalCriar(true)}
                        className="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-bold bg-ecf-yellow text-black hover:bg-yellow-300 transition-all shrink-0">
                        <PlusCircle size={14} />
                        Nova Empresa
                    </button>
                </div>

                {flash?.success && (
                    <div className="rounded-xl border border-green-500/20 bg-green-950/40 px-4 py-3 text-green-300 text-sm">
                        {flash.success}
                    </div>
                )}

                <div className="flex items-center gap-3 flex-wrap">
                    <div className="relative flex-1 min-w-[200px]">
                        <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30 pointer-events-none" />
                        <input type="text" value={filtroTexto} onChange={e => setFiltroTexto(e.target.value)}
                            placeholder="Buscar por nome ou CNPJ..."
                            className="w-full bg-white/[0.04] border border-white/[0.08] rounded-lg pl-9 pr-3 py-2 text-white text-sm placeholder:text-white/20 focus:outline-none focus:border-ecf-yellow/40 transition-colors" />
                    </div>
                    <select value={filtroTipo} onChange={e => setFiltroTipo(e.target.value)}
                        className={cn('bg-white/[0.04] border border-white/[0.08] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-ecf-yellow/40 transition-colors',
                            !filtroTipo ? 'text-white/30' : 'text-white')}>
                        <option value="">Todos os tipos</option>
                        {TIPOS.map(({ value, label }) => (
                            <option key={value} value={value}>{label}</option>
                        ))}
                    </select>
                </div>

                <div className="rounded-2xl border border-white/[0.08] bg-ecf-card overflow-hidden">
                    {empresasFiltradas.length === 0 ? (
                        <div className="py-16 text-center text-white/30 text-sm">
                            {companies.length === 0 ? 'Nenhuma empresa cadastrada ainda.' : 'Nenhuma empresa encontrada com os filtros aplicados.'}
                        </div>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-white/[0.06]">
                                    <th className="text-left text-xs text-white/30 font-medium px-5 py-3">Empresa</th>
                                    <th className="text-left text-xs text-white/30 font-medium px-4 py-3 hidden sm:table-cell">Tipo</th>
                                    <th className="text-left text-xs text-white/30 font-medium px-4 py-3 hidden md:table-cell">CNPJ</th>
                                    <th className="text-left text-xs text-white/30 font-medium px-4 py-3 hidden lg:table-cell">Cadastro</th>
                                    <th className="text-left text-xs text-white/30 font-medium px-4 py-3">Status</th>
                                    <th className="px-4 py-3 w-10" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-white/[0.04]">
                                {pendentes.map(c => (
                                    <EmpresaRow key={c.id} company={c} onEdit={() => setEmpresaEditar(c)} />
                                ))}
                                {pendentes.length > 0 && ativos.length > 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-5 py-1.5 bg-white/[0.015]">
                                            <span className="text-[10px] text-white/20 uppercase tracking-widest">Ativas</span>
                                        </td>
                                    </tr>
                                )}
                                {ativos.map(c => (
                                    <EmpresaRow key={c.id} company={c} onEdit={() => setEmpresaEditar(c)} />
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}

function EmpresaRow({ company: c, onEdit }) {
    return (
        <tr className="hover:bg-white/[0.02] transition-colors group">
            <td className="px-5 py-3">
                <span className="text-white font-medium">{c.name}</span>
            </td>
            <td className="px-4 py-3 hidden sm:table-cell">
                <TypeBadges types={c.service_type} />
            </td>
            <td className="px-4 py-3 hidden md:table-cell text-white/40 text-xs">
                {c.cnpj || '—'}
            </td>
            <td className="px-4 py-3 hidden lg:table-cell text-white/30 text-xs">
                {fmtDate(c.created_at)}
            </td>
            <td className="px-4 py-3">
                {c.status === 'pendente' ? (
                    <span className="text-xs font-medium px-2 py-0.5 rounded-full border text-yellow-300 bg-yellow-950/50 border-yellow-500/20">Pendente</span>
                ) : (
                    <span className="text-xs font-medium px-2 py-0.5 rounded-full border text-green-300 bg-green-950/50 border-green-500/20">Ativa</span>
                )}
            </td>
            <td className="px-4 py-3">
                <button onClick={onEdit}
                    className="opacity-0 group-hover:opacity-100 p-1.5 rounded-lg hover:bg-white/[0.06] text-white/40 hover:text-white/80 transition-all"
                    title="Editar empresa">
                    <Pencil size={13} />
                </button>
            </td>
        </tr>
    );
}
