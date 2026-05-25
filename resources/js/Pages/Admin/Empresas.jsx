import AppLayout from '@/Layouts/AppLayout';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Building2, ChevronDown } from 'lucide-react';
import { cn, formatDate } from '@/lib/utils';

const SERVICE_LABELS = {
    polo: 'POLO', polos: 'POLO', assessoria: 'Assessoria',
    incubadora: 'Incubadora', publicidade: 'Publicidade', gestao: 'Gestão',
};
const SERVICE_COLORS = {
    polo:        'bg-blue-500/10 text-blue-300 border-blue-500/20',
    polos:       'bg-blue-500/10 text-blue-300 border-blue-500/20',
    assessoria:  'bg-purple-500/10 text-purple-300 border-purple-500/20',
    incubadora:  'bg-emerald-500/10 text-emerald-300 border-emerald-500/20',
    publicidade: 'bg-orange-500/10 text-orange-300 border-orange-500/20',
    gestao:      'bg-teal-500/10 text-teal-300 border-teal-500/20',
};
const CONTRACT_LABELS = { fixo: 'Fixo', progressao: 'Progressão' };
const CONTRACT_COLORS = {
    fixo:       'bg-indigo-500/10 text-indigo-300 border-indigo-500/20',
    progressao: 'bg-ecf-yellow/10 text-ecf-yellow border-ecf-yellow/20',
};

const fmtBRL = (n) => n == null ? null
    : Number(n).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL',
        minimumFractionDigits: 0, maximumFractionDigits: 0 });

function Chip({ label, color }) {
    return (
        <span className={cn('text-[11px] font-semibold px-2 py-0.5 rounded-full border shrink-0', color)}>
            {label}
        </span>
    );
}

function EmpresaRow({ empresa, expandida, onToggle }) {
    const datas = empresa.contract_start
        ? empresa.contract_end
            ? `${formatDate(empresa.contract_start)} – ${formatDate(empresa.contract_end)}`
            : `${formatDate(empresa.contract_start)} –`
        : null;

    return (
        <div
            onClick={onToggle}
            className={cn(
                'flex items-center gap-3 px-4 py-3 cursor-pointer transition-colors',
                expandida ? 'bg-white/[0.05]' : 'hover:bg-white/[0.03]',
                !empresa.active && 'opacity-50'
            )}
        >
            <ChevronDown
                size={14}
                className={cn('transition-transform duration-200 shrink-0',
                    expandida ? 'rotate-180 text-ecf-yellow' : 'text-white/40')}
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
                    <span className="text-white/30 text-[11px] mt-0.5 block">↳ {empresa.nome_pai}</span>
                )}
                {empresa.filhas?.length > 0 && (
                    <span className="text-ecf-yellow/50 text-[11px] mt-0.5 block">
                        Grupo · {empresa.filhas.length} vinculada{empresa.filhas.length !== 1 ? 's' : ''}
                    </span>
                )}
                {(empresa.additional_service || empresa.additional_service_price) && (
                    <span className="text-white/35 text-[12px] mt-0.5 block truncate">
                        {empresa.additional_service}
                        {empresa.additional_service_price != null && (
                            <span className="text-emerald-400/70 ml-1">
                                · {fmtBRL(empresa.additional_service_price)}/mês
                            </span>
                        )}
                    </span>
                )}
            </div>
            {(empresa.service_type?.length > 0)
                ? (empresa.service_type).map(t => (
                    <Chip key={t} label={SERVICE_LABELS[t] ?? t} color={SERVICE_COLORS[t] ?? 'bg-white/[0.05] text-white/30 border-white/[0.08]'} />
                ))
                : <Chip label="Sem tipo" color="bg-white/[0.05] text-white/30 border-white/[0.08]" />
            }
            {empresa.contract_type
                ? <Chip label={CONTRACT_LABELS[empresa.contract_type]} color={CONTRACT_COLORS[empresa.contract_type]} />
                : <Chip label="Sem contrato" color="bg-white/[0.05] text-white/30 border-white/[0.08]" />
            }
            {datas && <span className="text-white/35 text-[12px] font-mono shrink-0">{datas}</span>}
        </div>
    );
}

function EmpresaForm({ empresa, allCompanies, onClose }) {
    const filhasAtuais = new Set((empresa.filhas ?? []).map(f => f.id));

    const { data, setData, patch, processing, errors } = useForm({
        service_type:              Array.isArray(empresa.service_type) ? empresa.service_type : (empresa.service_type ? [empresa.service_type] : []),
        contract_type:             empresa.contract_type             ?? '',
        contract_start:            empresa.contract_start            ?? '',
        contract_end:              empresa.contract_end              ?? '',
        additional_service:        empresa.additional_service        ?? '',
        additional_service_price:  empresa.additional_service_price  ?? '',
        parent_company_id:         empresa.parent_company_id         ?? '',
        filha_ids:                 [...filhasAtuais],
    });

    // Empresas que podem ser filhas: não é a própria, não tem parent já (ou é filha desta)
    const candidatasFilha = allCompanies.filter(c =>
        c.id !== empresa.id &&
        (!c.parent_company_id || c.parent_company_id === empresa.id)
    );

    // Empresas que podem ser o pai: não é a própria, não é já filha desta
    const opcoesPai = allCompanies.filter(c =>
        c.id !== empresa.id && !filhasAtuais.has(c.id)
    );

    function toggleFilha(id) {
        setData('filha_ids', data.filha_ids.includes(id)
            ? data.filha_ids.filter(i => i !== id)
            : [...data.filha_ids, id]
        );
    }

    function submit(e) {
        e.preventDefault();
        patch(route('admin.empresas.update', empresa.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    }

    const input = 'w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40';
    const select = 'w-full h-9 pl-3 pr-8 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40';
    const label = 'block text-[11px] uppercase tracking-wider text-white/40 mb-1';
    const err = 'text-[11px] text-red-400 mt-1 block';

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label className={label}>Tipo de serviço</label>
                    <div className="flex flex-col gap-1.5 pt-1">
                        {[
                            { value: 'polos',       label: 'Publicação — POLOS' },
                            { value: 'assessoria',  label: 'Publicação — Assessoria' },
                            { value: 'incubadora',  label: 'Incubadora' },
                            { value: 'publicidade', label: 'Publicidade' },
                            { value: 'gestao',      label: 'Gestão' },
                        ].map(({ value, label: lbl }) => (
                            <label key={value} className="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" checked={data.service_type.includes(value)}
                                    onChange={() => setData('service_type', data.service_type.includes(value)
                                        ? data.service_type.filter(t => t !== value)
                                        : [...data.service_type, value])}
                                    className="accent-ecf-yellow" />
                                <span className="text-[12px] text-white/70">{lbl}</span>
                            </label>
                        ))}
                    </div>
                    {errors.service_type && <span className={err}>{errors.service_type}</span>}
                </div>
                <div>
                    <label className={label}>Tipo de contrato</label>
                    <select value={data.contract_type} onChange={e => setData('contract_type', e.target.value)} className={select}>
                        <option value="">Selecionar...</option>
                        <option value="fixo">Fixo</option>
                        <option value="progressao">Escala de Progressão</option>
                    </select>
                    {errors.contract_type && <span className={err}>{errors.contract_type}</span>}
                </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label className={label}>Início do contrato</label>
                    <input type="date" value={data.contract_start}
                        onChange={e => setData('contract_start', e.target.value)}
                        className={cn(input, 'appearance-none')} />
                    {errors.contract_start && <span className={err}>{errors.contract_start}</span>}
                </div>
                <div>
                    <label className={label}>Término do contrato</label>
                    <input type="date" value={data.contract_end}
                        onChange={e => setData('contract_end', e.target.value)}
                        className={cn(input, 'appearance-none')} />
                    {errors.contract_end && <span className={err}>{errors.contract_end}</span>}
                </div>
            </div>

            {/* Vinculação: esta empresa é pai → selecionar filhas */}
            {!data.parent_company_id && (
                <div>
                    <label className={label}>Empresas vinculadas</label>
                    {candidatasFilha.length === 0 ? (
                        <p className="text-white/25 text-[12px] py-2">Nenhuma empresa disponível para vincular.</p>
                    ) : (
                        <div className="rounded-lg border border-white/[0.08] divide-y divide-white/[0.04] max-h-48 overflow-y-auto">
                            {candidatasFilha.map(c => (
                                <label key={c.id}
                                    className="flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-white/[0.03] transition-colors">
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

            {/* Vinculação: esta empresa é filha → selecionar o pai */}
            <div>
                <label className={label}>Esta empresa faz parte do grupo de</label>
                <select value={data.parent_company_id} onChange={e => setData('parent_company_id', e.target.value)} className={select}>
                    <option value="">Nenhuma (empresa independente)</option>
                    {opcoesPai.map(c => (
                        <option key={c.id} value={c.id}>{c.name}</option>
                    ))}
                </select>
                {data.parent_company_id && (
                    <p className="text-[11px] text-white/30 mt-1">Esta empresa será agrupada sob a empresa selecionada.</p>
                )}
                {errors.parent_company_id && <span className={err}>{errors.parent_company_id}</span>}
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div className="sm:col-span-2">
                    <label className={label}>Serviço adicional</label>
                    <input type="text" value={data.additional_service}
                        onChange={e => setData('additional_service', e.target.value)}
                        placeholder="Ex: Consultoria mensal, Treinamento..."
                        className={cn(input, 'placeholder:text-white/20')} />
                    {errors.additional_service && <span className={err}>{errors.additional_service}</span>}
                </div>
                <div>
                    <label className={label}>Valor (R$/mês)</label>
                    <input type="number" min="0" step="0.01" value={data.additional_service_price}
                        onChange={e => setData('additional_service_price', e.target.value)}
                        placeholder="0"
                        className={cn(input, 'placeholder:text-white/20')} />
                    {errors.additional_service_price && <span className={err}>{errors.additional_service_price}</span>}
                </div>
            </div>

            <div className="flex items-center gap-2 pt-1">
                <button type="submit" disabled={processing}
                    className="bg-ecf-yellow/10 hover:bg-ecf-yellow/20 text-ecf-yellow text-[13px] h-9 px-4 rounded-lg transition-colors font-semibold">
                    {processing ? 'Salvando...' : 'Salvar'}
                </button>
                <button type="button" onClick={onClose}
                    className="text-white/40 hover:text-white/70 text-[13px] h-9 px-3 rounded-lg transition-colors">
                    Descartar
                </button>
            </div>
        </form>
    );
}

function EmpresaList({ empresas, allCompanies }) {
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
                            />
                        </div>
                    )}
                </div>
            ))}
        </div>
    );
}

export default function Empresas({ companies }) {
    const [busca, setBusca] = useState('');

    const filtradas = busca.trim()
        ? companies.filter(e => e.name.toLowerCase().includes(busca.toLowerCase()))
        : companies;

    return (
        <AppLayout title="Empresas — Administrativo">
            <main className="p-6">
                <div className="space-y-5">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <div className="flex items-center gap-2 mb-2">
                                <Building2 size={20} className="text-ecf-yellow" />
                                <h1 className="text-xl font-semibold font-display text-white">Empresas</h1>
                            </div>
                            <p className="text-[13px] text-white/40">
                                Tipo de serviço, contrato e serviços adicionais. Dados usados no Fechamento.
                            </p>
                        </div>
                        <span className="text-white/30 text-[12px] shrink-0 pt-1">
                            {companies.length} empresa{companies.length !== 1 ? 's' : ''}
                        </span>
                    </div>

                    <input
                        type="text"
                        value={busca}
                        onChange={e => setBusca(e.target.value)}
                        placeholder="Buscar empresa..."
                        className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20"
                    />

                    <div className="rounded-xl border border-white/[0.08] bg-white/[0.02]">
                        {filtradas.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-16 gap-2">
                                <Building2 size={24} className="text-white/20" />
                                <p className="text-[13px] text-white/40">Nenhuma empresa encontrada.</p>
                            </div>
                        ) : (
                            <EmpresaList empresas={filtradas} allCompanies={companies} />
                        )}
                    </div>
                </div>
            </main>
        </AppLayout>
    );
}
