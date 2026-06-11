import { useState } from 'react';
import { Head, useForm, usePage, router } from '@inertiajs/react';
import { AlertTriangle, Briefcase, Building2, Pencil, Plus, PlusCircle, PowerOff, Search, Trash2, X } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';

const fmtDate = (iso) => {
    if (!iso) return '-';
    return new Date(iso).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: '2-digit' });
};

const fmtBRL = (n) => n == null ? '-'
    : Number(n).toLocaleString('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    });

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

function ServicoBadges({ servicos }) {
    const lista = Array.isArray(servicos) ? servicos : [];
    if (lista.length === 0) return <span className="text-white/20 text-xs">-</span>;

    return (
        <span className="inline-flex flex-wrap gap-1">
            {lista.map((servico, index) => {
                const nome = typeof servico === 'string' ? servico : servico.servico_nome;
                return (
                    <span key={`${nome}-${index}`} className="text-xs font-medium px-2 py-0.5 rounded-full border text-ecf-yellow bg-ecf-yellow/10 border-ecf-yellow/20">
                        {nome || '-'}
                    </span>
                );
            })}
        </span>
    );
}

function ContratosSection({ company, onAdicionar, onEditar, onDesativar }) {
    const contratos = Array.isArray(company.servicos_contratados) ? company.servicos_contratados : [];

    return (
        <div className="space-y-3 rounded-xl border border-white/[0.08] bg-white/[0.02] p-3">
            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <Briefcase size={14} className="text-ecf-yellow/70" />
                    <span className="text-white/80 text-sm font-semibold">Servicos contratados</span>
                </div>
                <button
                    type="button"
                    onClick={() => onAdicionar(company)}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-ecf-yellow/20 bg-ecf-yellow/10 px-2.5 py-1 text-xs font-semibold text-ecf-yellow hover:bg-ecf-yellow/20"
                >
                    <Plus size={12} />
                    Adicionar contrato
                </button>
            </div>
            {contratos.length === 0 ? (
                <p className="text-white/30 text-xs">Nenhum contrato ativo.</p>
            ) : (
                <div className="rounded-lg border border-white/[0.06] overflow-hidden">
                    {contratos.map(c => (
                        <div key={c.id} className="flex items-center gap-3 px-3 py-2 border-b border-white/[0.04] last:border-0">
                            <div className="min-w-0 flex-1">
                                <p className="text-white/75 text-sm truncate">{c.servico_nome}</p>
                                <p className="text-white/30 text-[11px]">{c.tipo_cobranca === 'mensal' ? 'Mensal' : 'Unica'} - {fmtBRL(c.valor_contratado)}</p>
                            </div>
                            <button type="button" onClick={() => onEditar(company, c)} className="p-1.5 rounded-lg text-white/40 hover:text-ecf-yellow hover:bg-white/[0.04]">
                                <Pencil size={13} />
                            </button>
                            <button type="button" onClick={() => onDesativar(company, c)} className="p-1.5 rounded-lg text-white/40 hover:text-red-400 hover:bg-white/[0.04]">
                                <PowerOff size={13} />
                            </button>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

function FormularioEditar({ company, onClose, onAdicionarContrato, onEditarContrato, onDesativarContrato, vinculaveis = [], filhaIdsAtuais = [] }) {
    const [confirmandoExclusao, setConfirmandoExclusao] = useState(false);
    const [excluindo, setExcluindo] = useState(false);
    const { data, setData, put, processing, errors } = useForm({
        name: company.name,
        cnpj: company.cnpj ?? '',
        notes: company.notes ?? '',
        // Phase 31 D-04 — destinatario do email NPS mensal
        email_cliente: company.email_cliente ?? '',
        // Quick 260611-eml — contato comercial.
        telefone: company.telefone ?? '',
        // Vínculo de grupo gerenciado pela principal: ids das empresas vinculadas.
        filha_ids: filhaIdsAtuais,
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
                <label className="block text-xs text-white/60 font-medium">Nome da Empresa <span className="text-ecf-yellow">*</span></label>
                <input
                    type="text"
                    value={data.name}
                    onChange={e => setData('name', e.target.value)}
                    autoFocus
                    required
                    className={cn('w-full bg-white/[0.04] border rounded-lg px-3 py-2.5 text-white text-sm placeholder:text-white/20 focus:outline-none focus:border-ecf-yellow/40 transition-colors', errors.name ? 'border-red-500/50' : 'border-white/[0.08]')}
                />
                {errors.name && <p className="text-red-400 text-xs">{errors.name}</p>}
            </div>

            <div className="space-y-1.5">
                <label className="block text-xs text-white/60 font-medium">CNPJ <span className="text-white/30 text-[11px] font-normal">(opcional)</span></label>
                <input
                    type="text"
                    value={data.cnpj}
                    onChange={e => setData('cnpj', e.target.value)}
                    placeholder="00.000.000/0001-00"
                    className={cn('w-full bg-white/[0.04] border rounded-lg px-3 py-2.5 text-white text-sm placeholder:text-white/20 focus:outline-none focus:border-ecf-yellow/40 transition-colors', errors.cnpj ? 'border-red-500/50' : 'border-white/[0.08]')}
                />
                {errors.cnpj && <p className="text-red-400 text-xs">{errors.cnpj}</p>}
            </div>

            <div className="space-y-1.5">
                <label className="block text-xs text-white/60 font-medium">Descricao / Observacoes <span className="text-white/30 text-[11px] font-normal">(opcional)</span></label>
                <textarea
                    value={data.notes}
                    onChange={e => setData('notes', e.target.value)}
                    rows={3}
                    placeholder="Notas internas sobre a empresa..."
                    className={cn('w-full bg-white/[0.04] border rounded-lg px-3 py-2.5 text-white text-sm resize-none placeholder:text-white/20 focus:outline-none focus:border-ecf-yellow/40 transition-colors', errors.notes ? 'border-red-500/50' : 'border-white/[0.08]')}
                />
                {errors.notes && <p className="text-red-400 text-xs">{errors.notes}</p>}
            </div>

            {/* Phase 31 D-04 — Email do cliente para NPS mensal */}
            <div className="space-y-1.5">
                <label className="block text-xs text-white/60 font-medium">
                    Email do Cliente para NPS{' '}
                    <span className="text-white/30 text-[11px] font-normal">(opcional)</span>
                </label>
                <input
                    type="email"
                    value={data.email_cliente}
                    onChange={e => setData('email_cliente', e.target.value)}
                    placeholder="cliente@empresa.com.br"
                    className={cn('w-full bg-white/[0.04] border rounded-lg px-3 py-2.5 text-white text-sm placeholder:text-white/20 focus:outline-none focus:border-ecf-yellow/40 transition-colors', errors.email_cliente ? 'border-red-500/50' : 'border-white/[0.08]')}
                />
                {errors.email_cliente && <p className="text-red-400 text-xs">{errors.email_cliente}</p>}
                <p className="text-white/30 text-[11px]">Destinatário do email mensal de NPS. Deixe em branco para pausar o envio.</p>
            </div>

            {/* Quick 260611-eml — Telefone do contato comercial */}
            <div className="space-y-1.5">
                <label className="block text-xs text-white/60 font-medium">
                    Telefone <span className="text-white/30 text-[11px] font-normal">(opcional)</span>
                </label>
                <input
                    type="tel"
                    value={data.telefone}
                    onChange={e => setData('telefone', e.target.value)}
                    placeholder="(11) 99999-9999"
                    className={cn('w-full bg-white/[0.04] border rounded-lg px-3 py-2.5 text-white text-sm placeholder:text-white/20 focus:outline-none focus:border-ecf-yellow/40 transition-colors', errors.telefone ? 'border-red-500/50' : 'border-white/[0.08]')}
                />
                {errors.telefone && <p className="text-red-400 text-xs">{errors.telefone}</p>}
            </div>

            {/* Vínculo de grupo gerenciado PELA empresa principal: marque as
                empresas que pertencem a este grupo. Se esta empresa já está
                vinculada a outra, ela não pode ter vinculadas (limite de 1 nível). */}
            <div className="space-y-1.5">
                <label className="block text-xs text-white/60 font-medium">
                    Empresas vinculadas <span className="text-white/30 text-[11px] font-normal">(opcional)</span>
                </label>
                {company.parent_company_id ? (
                    <p className="text-white/40 text-xs leading-relaxed rounded-lg border border-white/[0.08] bg-white/[0.02] px-3 py-2.5">
                        Esta empresa está vinculada a <strong className="text-white/70">{company.nome_pai}</strong>. Para gerenciar vinculadas, abra a empresa principal.
                    </p>
                ) : vinculaveis.length === 0 ? (
                    <p className="text-white/40 text-xs rounded-lg border border-white/[0.08] bg-white/[0.02] px-3 py-2.5">
                        Nenhuma outra empresa disponível para vincular.
                    </p>
                ) : (
                    <div className="max-h-44 overflow-y-auto rounded-lg border border-white/[0.08] bg-white/[0.02] divide-y divide-white/[0.04]">
                        {vinculaveis.map(c => (
                            <label key={c.id} className="flex items-center gap-2.5 px-3 py-2 cursor-pointer hover:bg-white/[0.03]">
                                <input
                                    type="checkbox"
                                    checked={data.filha_ids.includes(c.id)}
                                    onChange={e => setData('filha_ids', e.target.checked
                                        ? [...data.filha_ids, c.id]
                                        : data.filha_ids.filter(id => id !== c.id))}
                                    className="h-4 w-4 accent-ecf-yellow shrink-0"
                                />
                                <span className="text-white/80 text-sm truncate">{c.name}</span>
                            </label>
                        ))}
                    </div>
                )}
                {errors.filha_ids && <p className="text-red-400 text-xs">{errors.filha_ids}</p>}
                {!company.parent_company_id && vinculaveis.length > 0 && (
                    <p className="text-white/30 text-[11px]">Marque as empresas que pertencem a este grupo.</p>
                )}
            </div>

            <ContratosSection
                company={company}
                onAdicionar={onAdicionarContrato}
                onEditar={onEditarContrato}
                onDesativar={onDesativarContrato}
            />

            {confirmandoExclusao ? (
                <div className="rounded-xl border border-red-500/20 bg-red-950/40 p-4 space-y-3">
                    <div className="flex items-center gap-2 text-red-300">
                        <AlertTriangle size={14} />
                        <span className="text-sm font-medium">Confirmar remocao</span>
                    </div>
                    <p className="text-white/50 text-xs leading-relaxed">
                        A empresa <strong className="text-white/80">{company.name}</strong> sera removida da listagem.
                    </p>
                    <div className="flex items-center gap-2">
                        <button type="button" onClick={handleExcluir} disabled={excluindo}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold bg-red-600 hover:bg-red-500 text-white transition-colors disabled:opacity-50">
                            <Trash2 size={12} />
                            {excluindo ? 'Removendo...' : 'Confirmar remocao'}
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
                    {processing ? 'Salvando...' : 'Salvar alteracoes'}
                </button>
            </div>
        </form>
    );
}

export default function Empresas({ companies, servicos_disponiveis = [] }) {
    const { flash } = usePage().props;
    const [empresaEditar, setEmpresaEditar] = useState(null);
    const [filtroTexto, setFiltroTexto] = useState('');
    const [filtroServico, setFiltroServico] = useState('');
    const [contratoModal, setContratoModal] = useState({ open: false, empresa: null, contrato: null });
    const [contratoForm, setContratoForm] = useState({
        servico_id: '',
        valor_contratado: '',
        data_contratacao: '',
        data_vencimento: '',
        observacoes: '',
        ativo: true,
    });
    const [contratoErrors, setContratoErrors] = useState({});
    const [contratoSalvando, setContratoSalvando] = useState(false);

    const servicosNomes = [...new Set(
        companies.flatMap(c => (c.servicos_contratados || []).map(s => typeof s === 'string' ? s : s.servico_nome).filter(Boolean)),
    )].sort((a, b) => a.localeCompare(b, 'pt-BR'));

    const empresasFiltradas = companies.filter(c => {
        const textoOk = !filtroTexto
            || c.name.toLowerCase().includes(filtroTexto.toLowerCase())
            || (c.cnpj && c.cnpj.includes(filtroTexto));
        const servicoOk = !filtroServico
            || (c.servicos_contratados || []).some(s => (typeof s === 'string' ? s : s.servico_nome) === filtroServico);
        return textoOk && servicoOk;
    });

    const pendentes = empresasFiltradas.filter(c => c.status === 'pendente');
    const ativos = empresasFiltradas.filter(c => c.status !== 'pendente');

    function abrirAdicionarContrato(empresa) {
        setContratoModal({ open: true, empresa, contrato: null });
        setContratoForm({
            servico_id: '',
            valor_contratado: '',
            data_contratacao: new Date().toISOString().slice(0, 10),
            data_vencimento: '',
            observacoes: '',
            ativo: true,
        });
        setContratoErrors({});
    }

    function abrirEditarContrato(empresa, contrato) {
        setContratoModal({ open: true, empresa, contrato });
        setContratoForm({
            servico_id: String(contrato.servico_id ?? ''),
            valor_contratado: contrato.valor_contratado ?? '',
            data_contratacao: contrato.data_contratacao || '',
            data_vencimento: contrato.data_vencimento || '',
            observacoes: contrato.observacoes || '',
            ativo: contrato.ativo !== false,
        });
        setContratoErrors({});
    }

    function fecharModalContrato() {
        setContratoModal({ open: false, empresa: null, contrato: null });
        setContratoErrors({});
    }

    function escolherServico(id) {
        const servico = servicos_disponiveis.find(s => String(s.id) === String(id));
        setContratoForm(prev => ({
            ...prev,
            servico_id: id,
            valor_contratado: servico ? servico.valor_padrao : prev.valor_contratado,
        }));
    }

    function salvarContrato(e) {
        e.preventDefault();
        if (!contratoModal.empresa) return;

        // Ziggy route() respeita o base path do app (ex.: /ecf_admin/public);
        // caminhos absolutos crus quebravam (404 do Apache) quando o app roda em subdiretório.
        const url = contratoModal.contrato
            ? route('empresas.contratos.update', [contratoModal.empresa.id, contratoModal.contrato.id])
            : route('empresas.contratos.store', contratoModal.empresa.id);
        const method = contratoModal.contrato ? 'put' : 'post';

        setContratoSalvando(true);
        router[method](url, contratoForm, {
            preserveScroll: true,
            onSuccess: () => fecharModalContrato(),
            onError: errors => setContratoErrors(errors || {}),
            onFinish: () => setContratoSalvando(false),
        });
    }

    function desativarContrato(empresa, contrato) {
        const nome = contrato.servico_nome ?? 'servico';
        if (!confirm(`Desativar contrato "${nome}"?`)) return;

        router.delete(route('empresas.contratos.destroy', [empresa.id, contrato.id]), {
            preserveScroll: true,
        });
    }

    return (
        <AppLayout title="Empresas">
            <Head title="Empresas - Comercial" />

            <Modal open={!!empresaEditar} onClose={() => setEmpresaEditar(null)}>
                {empresaEditar && (
                    <FormularioEditar
                        company={empresaEditar}
                        onClose={() => setEmpresaEditar(null)}
                        onAdicionarContrato={abrirAdicionarContrato}
                        onEditarContrato={abrirEditarContrato}
                        onDesativarContrato={desativarContrato}
                        vinculaveis={companies.filter(c =>
                            c.id !== empresaEditar.id
                            && !c.is_principal
                            && (c.parent_company_id == null || c.parent_company_id === empresaEditar.id)
                        )}
                        filhaIdsAtuais={companies.filter(c => c.parent_company_id === empresaEditar.id).map(c => c.id)}
                    />
                )}
            </Modal>

            <Modal open={contratoModal.open} onClose={fecharModalContrato}>
                <form onSubmit={salvarContrato} className="p-6 space-y-4">
                    <div className="flex items-center justify-between">
                        <h2 className="text-white font-bold text-base">
                            {contratoModal.contrato ? 'Editar' : 'Adicionar'} contrato
                        </h2>
                        <button type="button" onClick={fecharModalContrato} className="text-white/30 hover:text-white/70">
                            <X size={18} />
                        </button>
                    </div>
                    <div className="space-y-1.5">
                        <label className="block text-xs text-white/60 font-medium">Servico *</label>
                        {contratoModal.contrato ? (
                            <input value={contratoModal.contrato.servico_nome ?? '-'} disabled className="w-full bg-white/[0.04] border border-white/[0.08] rounded-lg px-3 py-2.5 text-white/60 text-sm" />
                        ) : (
                            <select value={contratoForm.servico_id} onChange={e => escolherServico(e.target.value)} required className="w-full bg-white/[0.04] border border-white/[0.08] rounded-lg px-3 py-2.5 text-white text-sm focus:outline-none focus:border-ecf-yellow/40">
                                <option value="">Selecionar...</option>
                                {servicos_disponiveis.map(s => (
                                    <option key={s.id} value={s.id}>
                                        {s.nome} - {s.tipo_cobranca === 'mensal' ? 'Mensal' : 'Unica'} - {fmtBRL(s.valor_padrao)}
                                    </option>
                                ))}
                            </select>
                        )}
                        {contratoErrors.servico_id && <p className="text-red-400 text-xs">{contratoErrors.servico_id}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <label className="block text-xs text-white/60 font-medium">Valor contratado (R$) *</label>
                        <input type="number" min="0" step="0.01" value={contratoForm.valor_contratado} onChange={e => setContratoForm(prev => ({ ...prev, valor_contratado: e.target.value }))} required className="w-full bg-white/[0.04] border border-white/[0.08] rounded-lg px-3 py-2.5 text-white text-sm focus:outline-none focus:border-ecf-yellow/40" />
                        {contratoErrors.valor_contratado && <p className="text-red-400 text-xs">{contratoErrors.valor_contratado}</p>}
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <label className="block text-xs text-white/60 font-medium">Contratacao *</label>
                            <input type="date" value={contratoForm.data_contratacao} onChange={e => setContratoForm(prev => ({ ...prev, data_contratacao: e.target.value }))} required className="w-full bg-white/[0.04] border border-white/[0.08] rounded-lg px-3 py-2.5 text-white text-sm focus:outline-none focus:border-ecf-yellow/40" />
                            {contratoErrors.data_contratacao && <p className="text-red-400 text-xs">{contratoErrors.data_contratacao}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <label className="block text-xs text-white/60 font-medium">Vencimento</label>
                            <input type="date" value={contratoForm.data_vencimento || ''} onChange={e => setContratoForm(prev => ({ ...prev, data_vencimento: e.target.value }))} className="w-full bg-white/[0.04] border border-white/[0.08] rounded-lg px-3 py-2.5 text-white text-sm focus:outline-none focus:border-ecf-yellow/40" />
                            {contratoErrors.data_vencimento && <p className="text-red-400 text-xs">{contratoErrors.data_vencimento}</p>}
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <label className="block text-xs text-white/60 font-medium">Observacoes</label>
                        <textarea rows={2} value={contratoForm.observacoes} onChange={e => setContratoForm(prev => ({ ...prev, observacoes: e.target.value }))} className="w-full bg-white/[0.04] border border-white/[0.08] rounded-lg px-3 py-2.5 text-white text-sm resize-none focus:outline-none focus:border-ecf-yellow/40" />
                        {contratoErrors.observacoes && <p className="text-red-400 text-xs">{contratoErrors.observacoes}</p>}
                    </div>
                    {contratoModal.contrato && (
                        <label className="flex items-center gap-2 text-sm text-white/80">
                            <input type="checkbox" checked={!!contratoForm.ativo} onChange={e => setContratoForm(prev => ({ ...prev, ativo: e.target.checked }))} className="h-4 w-4 accent-ecf-yellow" />
                            Contrato ativo
                        </label>
                    )}
                    <div className="flex items-center justify-end gap-3 pt-1">
                        <button type="button" onClick={fecharModalContrato} className="px-4 py-2 rounded-lg text-sm text-white/50 hover:text-white/80">Cancelar</button>
                        <button type="submit" disabled={contratoSalvando} className="px-5 py-2.5 rounded-lg text-sm font-bold bg-ecf-yellow text-black hover:bg-yellow-300 disabled:opacity-50">
                            {contratoSalvando ? 'Salvando...' : contratoModal.contrato ? 'Atualizar' : 'Adicionar'}
                        </button>
                    </div>
                </form>
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
                    <button onClick={() => router.visit(route('comercial.empresas.novo'))}
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
                    <select value={filtroServico} onChange={e => setFiltroServico(e.target.value)}
                        className={cn('bg-white/[0.04] border border-white/[0.08] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-ecf-yellow/40 transition-colors', !filtroServico ? 'text-white/30' : 'text-white')}>
                        <option value="">Todos os servicos</option>
                        {servicosNomes.map(nome => (
                            <option key={nome} value={nome}>{nome}</option>
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
                                    <th className="text-left text-xs text-white/30 font-medium px-4 py-3 hidden sm:table-cell">Servicos</th>
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
                <div className="flex items-center gap-2">
                    <span className="text-white font-medium">{c.name}</span>
                    {c.is_principal && (
                        <span className="text-xs font-medium px-2 py-0.5 rounded-full border text-ecf-yellow bg-ecf-yellow/10 border-ecf-yellow/20 whitespace-nowrap">
                            Principal · {c.filhas_count} vinculada{c.filhas_count !== 1 ? 's' : ''}
                        </span>
                    )}
                </div>
                {/* ↳ espelha o indicador de vinculada do Relatório de Fechamento */}
                {c.nome_pai && (
                    <span className="block text-white/30 text-xs mt-0.5">↳ vinculada a {c.nome_pai}</span>
                )}
            </td>
            <td className="px-4 py-3 hidden sm:table-cell">
                <ServicoBadges servicos={c.servicos_contratados} />
            </td>
            <td className="px-4 py-3 hidden md:table-cell text-white/40 text-xs">
                {c.cnpj || '-'}
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
