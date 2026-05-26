import AppLayout from '@/Layouts/AppLayout';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Briefcase, Building2, ChevronDown, Pencil, Plus, PowerOff } from 'lucide-react';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';

const fmtBRL = (n) => n == null ? null
    : Number(n).toLocaleString('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    });

function Chip({ label, color }) {
    return (
        <span className={cn('text-[11px] font-semibold px-2 py-0.5 rounded-full border shrink-0', color)}>
            {label}
        </span>
    );
}

function EmpresaRow({ empresa, expandida, onToggle }) {
    const contratos = empresa.servicos_contratados || [];

    return (
        <div
            onClick={onToggle}
            className={cn(
                'flex items-center gap-3 px-4 py-3 cursor-pointer transition-colors',
                expandida ? 'bg-white/[0.05]' : 'hover:bg-white/[0.03]',
                !empresa.active && 'opacity-50',
            )}
        >
            <ChevronDown
                size={14}
                className={cn(
                    'transition-transform duration-200 shrink-0',
                    expandida ? 'rotate-180 text-ecf-yellow' : 'text-white/40',
                )}
            />
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                    <span className="text-white font-semibold text-[13px] truncate">{empresa.name}</span>
                    {!empresa.active && (
                        <span className="text-[10px] text-white/30 border border-white/[0.08] px-1.5 py-0.5 rounded-full shrink-0">
                            inativa
                        </span>
                    )}
                </div>
                {empresa.nome_pai && (
                    <span className="text-white/30 text-[11px] mt-0.5 block">vinculada a {empresa.nome_pai}</span>
                )}
                {empresa.filhas?.length > 0 && (
                    <span className="text-ecf-yellow/50 text-[11px] mt-0.5 block">
                        Grupo: {empresa.filhas.length} vinculada{empresa.filhas.length !== 1 ? 's' : ''}
                    </span>
                )}
                <span className="text-white/35 text-[12px] mt-0.5 block truncate">
                    {contratos.length === 0
                        ? 'Sem contratos ativos'
                        : `${contratos.length} contrato${contratos.length === 1 ? '' : 's'} ativo${contratos.length === 1 ? '' : 's'}`}
                </span>
            </div>
            {contratos.length > 0
                ? contratos.slice(0, 3).map(c => (
                    <Chip key={c.id} label={c.servico_nome ?? 'Servico'} color="bg-ecf-yellow/10 text-ecf-yellow border-ecf-yellow/20" />
                ))
                : <Chip label="Sem servicos" color="bg-white/[0.05] text-white/30 border-white/[0.08]" />
            }
        </div>
    );
}

function ContratosSection({ empresa, onAdicionar, onEditar, onDesativar }) {
    const contratos = empresa.servicos_contratados || [];

    return (
        <div className="space-y-3 rounded-lg border border-white/[0.08] bg-white/[0.02] p-3">
            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <Briefcase size={14} className="text-ecf-yellow/70" />
                    <span className="text-white/80 text-[13px] font-semibold">Servicos contratados</span>
                </div>
                <button
                    type="button"
                    onClick={() => onAdicionar(empresa)}
                    className="inline-flex items-center gap-1 px-2.5 py-1 text-[12px] rounded-md bg-ecf-yellow/10 hover:bg-ecf-yellow/20 text-ecf-yellow border border-ecf-yellow/20"
                >
                    <Plus size={12} /> Adicionar contrato
                </button>
            </div>
            {contratos.length === 0 ? (
                <p className="text-white/35 text-[12px] py-2">Nenhum contrato ativo.</p>
            ) : (
                <div className="overflow-x-auto rounded-lg border border-white/[0.06]">
                    <table className="w-full text-[12px]">
                        <thead>
                            <tr className="text-white/35 border-b border-white/[0.06]">
                                <th className="text-left py-2 px-3">Servico</th>
                                <th className="text-right py-2 px-3">Valor</th>
                                <th className="text-center py-2 px-3">Tipo</th>
                                <th className="text-right py-2 px-3">Acoes</th>
                            </tr>
                        </thead>
                        <tbody>
                            {contratos.map(c => (
                                <tr key={c.id} className="border-b border-white/[0.03] last:border-0 text-white/70">
                                    <td className="py-2 px-3">{c.servico_nome ?? '-'}</td>
                                    <td className="py-2 px-3 text-right font-mono">{fmtBRL(c.valor_contratado) ?? '-'}</td>
                                    <td className="py-2 px-3 text-center">{c.tipo_cobranca === 'mensal' ? 'Mensal' : 'Unica'}</td>
                                    <td className="py-2 px-3">
                                        <div className="flex justify-end gap-1">
                                            <button type="button" onClick={() => onEditar(empresa, c)} className="p-1 text-white/40 hover:text-ecf-yellow">
                                                <Pencil size={12} />
                                            </button>
                                            <button type="button" onClick={() => onDesativar(empresa, c)} className="p-1 text-white/40 hover:text-red-400">
                                                <PowerOff size={12} />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

function EmpresaForm({ empresa, allCompanies, onClose, onAdicionarContrato, onEditarContrato, onDesativarContrato }) {
    const filhasAtuais = new Set((empresa.filhas ?? []).map(f => f.id));
    const { data, setData, patch, processing, errors } = useForm({
        parent_company_id: empresa.parent_company_id ?? '',
        filha_ids: [...filhasAtuais],
    });

    const candidatasFilha = allCompanies.filter(c =>
        c.id !== empresa.id &&
        (!c.parent_company_id || c.parent_company_id === empresa.id),
    );
    const opcoesPai = allCompanies.filter(c =>
        c.id !== empresa.id && !filhasAtuais.has(c.id),
    );

    function toggleFilha(id) {
        setData('filha_ids', data.filha_ids.includes(id)
            ? data.filha_ids.filter(i => i !== id)
            : [...data.filha_ids, id],
        );
    }

    function submit(e) {
        e.preventDefault();
        patch(route('admin.empresas.update', empresa.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    }

    const select = 'w-full h-9 pl-3 pr-8 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40';
    const label = 'block text-[11px] uppercase tracking-wider text-white/40 mb-1';
    const err = 'text-[11px] text-red-400 mt-1 block';

    return (
        <form onSubmit={submit} className="space-y-4">
            {!data.parent_company_id && (
                <div>
                    <label className={label}>Empresas vinculadas</label>
                    {candidatasFilha.length === 0 ? (
                        <p className="text-white/25 text-[12px] py-2">Nenhuma empresa disponivel para vincular.</p>
                    ) : (
                        <div className="rounded-lg border border-white/[0.08] divide-y divide-white/[0.04] max-h-48 overflow-y-auto">
                            {candidatasFilha.map(c => (
                                <label
                                    key={c.id}
                                    className="flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-white/[0.03] transition-colors"
                                >
                                    <input
                                        type="checkbox"
                                        checked={data.filha_ids.includes(c.id)}
                                        onChange={() => toggleFilha(c.id)}
                                        className="accent-ecf-yellow w-4 h-4 shrink-0"
                                    />
                                    <span className="text-white/70 text-[13px]">{c.name}</span>
                                    {c.parent_company_id === empresa.id && (
                                        <span className="text-[10px] text-ecf-yellow/60 ml-auto">vinculada</span>
                                    )}
                                </label>
                            ))}
                        </div>
                    )}
                    {data.filha_ids.length > 0 && (
                        <p className="text-[11px] text-white/30 mt-1">
                            {data.filha_ids.length} empresa{data.filha_ids.length !== 1 ? 's' : ''} vinculada{data.filha_ids.length !== 1 ? 's' : ''} a este grupo no Fechamento.
                        </p>
                    )}
                    {errors.filha_ids && <span className={err}>{errors.filha_ids}</span>}
                </div>
            )}

            <div>
                <label className={label}>Esta empresa faz parte do grupo de</label>
                <select value={data.parent_company_id} onChange={e => setData('parent_company_id', e.target.value)} className={select}>
                    <option value="">Nenhuma (empresa independente)</option>
                    {opcoesPai.map(c => (
                        <option key={c.id} value={c.id}>{c.name}</option>
                    ))}
                </select>
                {data.parent_company_id && (
                    <p className="text-[11px] text-white/30 mt-1">Esta empresa sera agrupada sob a empresa selecionada.</p>
                )}
                {errors.parent_company_id && <span className={err}>{errors.parent_company_id}</span>}
            </div>

            <ContratosSection
                empresa={empresa}
                onAdicionar={onAdicionarContrato}
                onEditar={onEditarContrato}
                onDesativar={onDesativarContrato}
            />

            <div className="flex items-center gap-2 pt-1">
                <button
                    type="submit"
                    disabled={processing}
                    className="bg-ecf-yellow/10 hover:bg-ecf-yellow/20 text-ecf-yellow text-[13px] h-9 px-4 rounded-lg transition-colors font-semibold"
                >
                    {processing ? 'Salvando...' : 'Salvar vinculos'}
                </button>
                <button
                    type="button"
                    onClick={onClose}
                    className="text-white/40 hover:text-white/70 text-[13px] h-9 px-3 rounded-lg transition-colors"
                >
                    Descartar
                </button>
            </div>
        </form>
    );
}

function EmpresaList({ empresas, allCompanies, onAdicionarContrato, onEditarContrato, onDesativarContrato }) {
    const [aberta, setAberta] = useState(null);

    return (
        <div className="divide-y divide-white/[0.04]">
            {empresas.map(empresa => (
                <div key={empresa.id}>
                    <EmpresaRow
                        empresa={empresa}
                        expandida={aberta === empresa.id}
                        onToggle={() => setAberta(prev => prev === empresa.id ? null : empresa.id)}
                    />
                    {aberta === empresa.id && (
                        <div className="px-4 py-4 bg-black/30 border-t border-white/[0.04]">
                            <EmpresaForm
                                empresa={empresa}
                                allCompanies={allCompanies}
                                onClose={() => setAberta(null)}
                                onAdicionarContrato={onAdicionarContrato}
                                onEditarContrato={onEditarContrato}
                                onDesativarContrato={onDesativarContrato}
                            />
                        </div>
                    )}
                </div>
            ))}
        </div>
    );
}

export default function Empresas({ companies, servicos_disponiveis = [] }) {
    const [busca, setBusca] = useState('');
    const [servicoFiltro, setServicoFiltro] = useState('');
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
        companies.flatMap(e => (e.servicos_contratados || []).map(s => s.servico_nome).filter(Boolean)),
    )].sort((a, b) => a.localeCompare(b, 'pt-BR'));

    const filtradas = companies.filter(e => {
        const buscaOk = !busca.trim() || e.name.toLowerCase().includes(busca.toLowerCase());
        const servicoOk = !servicoFiltro
            || (e.servicos_contratados || []).some(s => s.servico_nome === servicoFiltro);
        return buscaOk && servicoOk;
    });

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

        const baseUrl = `/empresas/${contratoModal.empresa.id}/contratos-servico`;
        const url = contratoModal.contrato ? `${baseUrl}/${contratoModal.contrato.id}` : baseUrl;
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

        router.delete(`/empresas/${empresa.id}/contratos-servico/${contrato.id}`, {
            preserveScroll: true,
        });
    }

    return (
        <AppLayout title="Empresas - Administrativo">
            <main className="p-6">
                <div className="space-y-5">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <div className="flex items-center gap-2 mb-2">
                                <Building2 size={20} className="text-ecf-yellow" />
                                <h1 className="text-xl font-semibold font-display text-white">Empresas</h1>
                            </div>
                            <p className="text-[13px] text-white/40">
                                Contratos de servico e vinculos usados no Fechamento.
                            </p>
                        </div>
                        <span className="text-white/30 text-[12px] shrink-0 pt-1">
                            {companies.length} empresa{companies.length !== 1 ? 's' : ''}
                        </span>
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        <input
                            type="text"
                            value={busca}
                            onChange={e => setBusca(e.target.value)}
                            placeholder="Buscar empresa..."
                            className="h-9 flex-1 min-w-[220px] px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20"
                        />
                        <select
                            value={servicoFiltro}
                            onChange={e => setServicoFiltro(e.target.value)}
                            className={cn(
                                'h-9 min-w-[180px] px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] focus:outline-none focus:border-ecf-yellow/40',
                                servicoFiltro ? 'text-white/80' : 'text-white/30',
                            )}
                        >
                            <option value="">Todos os servicos</option>
                            {servicosNomes.map(nome => (
                                <option key={nome} value={nome}>{nome}</option>
                            ))}
                        </select>
                    </div>

                    <div className="rounded-xl border border-white/[0.08] bg-white/[0.02]">
                        {filtradas.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-16 gap-2">
                                <Building2 size={24} className="text-white/20" />
                                <p className="text-[13px] text-white/40">Nenhuma empresa encontrada.</p>
                            </div>
                        ) : (
                            <EmpresaList
                                empresas={filtradas}
                                allCompanies={companies}
                                onAdicionarContrato={abrirAdicionarContrato}
                                onEditarContrato={abrirEditarContrato}
                                onDesativarContrato={desativarContrato}
                            />
                        )}
                    </div>
                </div>
            </main>

            <Dialog open={contratoModal.open} onOpenChange={open => !open && fecharModalContrato()}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {contratoModal.contrato ? 'Editar' : 'Adicionar'} contrato
                            {contratoModal.empresa && (
                                <span className="text-white/40 font-normal"> - {contratoModal.empresa.name}</span>
                            )}
                        </DialogTitle>
                    </DialogHeader>
                    <form onSubmit={salvarContrato} className="space-y-4">
                        <div className="space-y-1.5">
                            <label className="block text-xs text-white/60 font-medium">Servico *</label>
                            {contratoModal.contrato ? (
                                <input
                                    value={contratoModal.contrato.servico_nome ?? '-'}
                                    disabled
                                    className="w-full rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[13px] text-white/60"
                                />
                            ) : (
                                <select
                                    value={contratoForm.servico_id}
                                    onChange={e => escolherServico(e.target.value)}
                                    required
                                    className="w-full rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[13px] text-white focus:border-ecf-yellow/40 focus:outline-none"
                                >
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
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                value={contratoForm.valor_contratado}
                                onChange={e => setContratoForm(prev => ({ ...prev, valor_contratado: e.target.value }))}
                                required
                                className="w-full rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[13px] text-white focus:border-ecf-yellow/40 focus:outline-none"
                            />
                            {contratoErrors.valor_contratado && <p className="text-red-400 text-xs">{contratoErrors.valor_contratado}</p>}
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <label className="block text-xs text-white/60 font-medium">Contratacao *</label>
                                <input
                                    type="date"
                                    value={contratoForm.data_contratacao}
                                    onChange={e => setContratoForm(prev => ({ ...prev, data_contratacao: e.target.value }))}
                                    required
                                    className="w-full rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[13px] text-white focus:border-ecf-yellow/40 focus:outline-none"
                                />
                                {contratoErrors.data_contratacao && <p className="text-red-400 text-xs">{contratoErrors.data_contratacao}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <label className="block text-xs text-white/60 font-medium">Vencimento</label>
                                <input
                                    type="date"
                                    value={contratoForm.data_vencimento || ''}
                                    onChange={e => setContratoForm(prev => ({ ...prev, data_vencimento: e.target.value }))}
                                    className="w-full rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[13px] text-white focus:border-ecf-yellow/40 focus:outline-none"
                                />
                                {contratoErrors.data_vencimento && <p className="text-red-400 text-xs">{contratoErrors.data_vencimento}</p>}
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <label className="block text-xs text-white/60 font-medium">Observacoes</label>
                            <textarea
                                rows={2}
                                value={contratoForm.observacoes}
                                onChange={e => setContratoForm(prev => ({ ...prev, observacoes: e.target.value }))}
                                className="w-full rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[13px] text-white resize-none focus:border-ecf-yellow/40 focus:outline-none"
                            />
                            {contratoErrors.observacoes && <p className="text-red-400 text-xs">{contratoErrors.observacoes}</p>}
                        </div>

                        {contratoModal.contrato && (
                            <label className="flex items-center gap-2 text-sm text-white/80">
                                <input
                                    type="checkbox"
                                    checked={!!contratoForm.ativo}
                                    onChange={e => setContratoForm(prev => ({ ...prev, ativo: e.target.checked }))}
                                    className="h-4 w-4 accent-ecf-yellow"
                                />
                                Contrato ativo
                            </label>
                        )}

                        <DialogFooter>
                            <button
                                type="button"
                                onClick={fecharModalContrato}
                                className="h-9 px-4 rounded-lg border border-white/[0.08] text-white/60 hover:text-white/90 text-[13px]"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                disabled={contratoSalvando}
                                className="h-9 px-4 rounded-lg bg-ecf-yellow text-black font-semibold text-[13px] disabled:opacity-50"
                            >
                                {contratoSalvando ? 'Salvando...' : contratoModal.contrato ? 'Atualizar' : 'Adicionar'}
                            </button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
