import AppLayout from '@/Layouts/AppLayout';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Banknote, ChevronDown, Building2, WifiOff } from 'lucide-react';
import { cn, formatDate } from '@/lib/utils';

const SERVICE_LABELS = {
    polo:       'POLO',
    assessoria: 'Assessoria',
    incubadora: 'Incubadora',
};

const SERVICE_COLORS = {
    polo:       'bg-blue-500/10 text-blue-300 border-blue-500/20',
    assessoria: 'bg-purple-500/10 text-purple-300 border-purple-500/20',
    incubadora: 'bg-emerald-500/10 text-emerald-300 border-emerald-500/20',
};

function ServiceBadge({ tipo }) {
    if (!tipo) {
        return (
            <span className="bg-white/[0.05] text-white/40 border-white/[0.08] text-[11px] font-semibold px-2 py-0.5 rounded-full border">
                Sem tipo
            </span>
        );
    }
    return (
        <span className={cn('text-[11px] font-semibold px-2 py-0.5 rounded-full border', SERVICE_COLORS[tipo])}>
            {SERVICE_LABELS[tipo]}
        </span>
    );
}

function IntegrationBadge() {
    return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-[11px] font-semibold bg-amber-500/10 text-amber-300 border-amber-500/20">
            <WifiOff size={10} className="shrink-0" />
            Sem integração
        </span>
    );
}

function FechamentoRow({ empresa, expandida, onToggle }) {
    const datas = (() => {
        if (empresa.contract_start && empresa.contract_end) {
            return `${formatDate(empresa.contract_start)} – ${formatDate(empresa.contract_end)}`;
        }
        if (empresa.contract_start) {
            return `${formatDate(empresa.contract_start)} –`;
        }
        return '—';
    })();

    return (
        <div
            onClick={onToggle}
            className={cn(
                'flex items-center gap-4 px-4 py-3 cursor-pointer transition-colors',
                expandida ? 'bg-white/[0.05]' : 'hover:bg-white/[0.03]'
            )}
        >
            <ChevronDown
                size={14}
                className={cn('transition-transform duration-200 shrink-0', expandida ? 'rotate-180 text-ecf-yellow' : 'text-white/40')}
            />
            <span className="flex-1 text-white font-semibold text-[13px] truncate">{empresa.name}</span>
            <ServiceBadge tipo={empresa.service_type} />
            {!empresa.has_adman && <IntegrationBadge />}
            <span className="text-white/40 text-[13px] font-mono shrink-0">{datas}</span>
        </div>
    );
}

function ServiceForm({ empresa, onClose }) {
    const { data, setData, patch, processing, errors } = useForm({
        service_type:   empresa.service_type   ?? '',
        contract_start: empresa.contract_start ?? '',
        contract_end:   empresa.contract_end   ?? '',
    });

    function submit(e) {
        e.preventDefault();
        patch(route('admin.financeiro.update', empresa.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    }

    return (
        <form onSubmit={submit}>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label className="block text-[11px] uppercase tracking-wider text-white/40 mb-1">
                        Tipo de serviço
                    </label>
                    <select
                        value={data.service_type}
                        onChange={e => setData('service_type', e.target.value)}
                        className="w-full h-9 pl-3 pr-8 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40"
                    >
                        <option value="">Selecionar tipo...</option>
                        <option value="polo">POLO</option>
                        <option value="assessoria">Assessoria</option>
                        <option value="incubadora">Incubadora</option>
                    </select>
                    {errors.service_type && (
                        <span className="text-[11px] text-red-400 mt-1 block">{errors.service_type}</span>
                    )}
                </div>
                <div>
                    <label className="block text-[11px] uppercase tracking-wider text-white/40 mb-1">
                        Início do contrato
                    </label>
                    <input
                        type="date"
                        value={data.contract_start}
                        onChange={e => setData('contract_start', e.target.value)}
                        className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 appearance-none"
                    />
                    {errors.contract_start && (
                        <span className="text-[11px] text-red-400 mt-1 block">{errors.contract_start}</span>
                    )}
                </div>
                <div>
                    <label className="block text-[11px] uppercase tracking-wider text-white/40 mb-1">
                        Término do contrato
                    </label>
                    <input
                        type="date"
                        value={data.contract_end}
                        onChange={e => setData('contract_end', e.target.value)}
                        className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 appearance-none"
                    />
                    {errors.contract_end && (
                        <span className="text-[11px] text-red-400 mt-1 block">{errors.contract_end}</span>
                    )}
                </div>
            </div>
            <div className="flex items-center gap-2 mt-4">
                <button
                    type="submit"
                    disabled={processing}
                    className="bg-ecf-yellow/10 hover:bg-ecf-yellow/20 text-ecf-yellow text-[13px] h-9 px-4 rounded-lg transition-colors font-semibold"
                >
                    {processing ? 'Salvando dados...' : 'Salvar dados'}
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

function FechamentoAccordion({ empresa, onClose }) {
    return (
        <div className="px-4 py-4 bg-black/30 border-t border-white/[0.04]">
            <ServiceForm empresa={empresa} onClose={onClose} />
        </div>
    );
}

function FechamentoList({ empresas }) {
    const [aberta, setAberta] = useState(null);

    function toggleEmpresa(id) {
        setAberta(prev => prev === id ? null : id);
    }

    if (empresas.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center py-16 gap-3">
                <Building2 size={24} className="text-white/20" />
                <p className="text-[13px] font-semibold text-white/40">Nenhuma empresa ativa encontrada.</p>
                <p className="text-[12px] text-white/25">Cadastre uma empresa com status ativo para que ela apareça aqui.</p>
            </div>
        );
    }

    return (
        <div className="divide-y divide-white/[0.04]">
            {empresas.map(empresa => (
                <div key={empresa.id}>
                    <FechamentoRow
                        empresa={empresa}
                        expandida={aberta === empresa.id}
                        onToggle={() => toggleEmpresa(empresa.id)}
                    />
                    {aberta === empresa.id && (
                        <FechamentoAccordion
                            empresa={empresa}
                            onClose={() => setAberta(null)}
                        />
                    )}
                </div>
            ))}
        </div>
    );
}

export default function Financeiro({ companies }) {
    return (
        <AppLayout title="Fechamento">
            <main className="p-6">
                <div className="max-w-4xl mx-auto space-y-6">
                    <div>
                        <div className="flex items-center gap-2 mb-2">
                            <Banknote size={20} className="text-ecf-yellow" />
                            <h1 className="text-xl font-semibold font-display text-white">Fechamento</h1>
                        </div>
                        <p className="text-[13px] text-white/40">Tipo de serviço e datas de contrato por empresa ativa.</p>
                    </div>
                    <div className="rounded-xl border border-white/[0.08] bg-white/[0.02]">
                        <FechamentoList empresas={companies} />
                    </div>
                </div>
            </main>
        </AppLayout>
    );
}
