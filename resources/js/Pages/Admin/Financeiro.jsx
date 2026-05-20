import AppLayout from '@/Layouts/AppLayout';
import { router, useForm } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import { Banknote, ChevronDown, Building2, WifiOff, TrendingUp, TrendingDown, Minus, Check } from 'lucide-react';
import { PieChart, Pie, Cell, BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer } from 'recharts';
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

const FAIXAS_LIMITES = {
    faixa_1: { min: 0,           proximo: 500_000,   nome: 'Faixa 1' },
    faixa_2: { min: 500_000,     proximo: 1_000_000, nome: 'Faixa 2' },
    faixa_3: { min: 1_000_000,   proximo: 2_000_000, nome: 'Faixa 3' },
    faixa_4: { min: 2_000_000,   proximo: 3_000_000, nome: 'Faixa 4' },
    faixa_5: { min: 3_000_000,   proximo: 4_000_000, nome: 'Faixa 5' },
    faixa_6: { min: 4_000_000,   proximo: 5_000_000, nome: 'Faixa 6' },
};

const fmtBRL = (n) => n == null ? '—'
    : Number(n).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL',
        minimumFractionDigits: 0, maximumFractionDigits: 0 });

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

const TOOLTIP_STYLE = {
    background: '#0f1116',
    border: '1px solid rgba(255,255,255,0.08)',
    borderRadius: 8,
    fontSize: 12,
    color: '#fff',
};

function ChartCard({ titulo, children }) {
    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4">
            <p className="text-white/50 text-[11px] font-semibold tracking-widest uppercase mb-3">{titulo}</p>
            {children}
        </div>
    );
}

function MiniPie({ data }) {
    if (data.length === 0) {
        return <p className="text-white/25 text-[12px] text-center py-5">Sem dados</p>;
    }
    return (
        <div>
            <ResponsiveContainer width="100%" height={90}>
                <PieChart>
                    <Pie data={data} cx="50%" cy="50%" innerRadius={24} outerRadius={40}
                        dataKey="value" strokeWidth={0}>
                        {data.map((entry, i) => <Cell key={i} fill={entry.color} />)}
                    </Pie>
                    <Tooltip contentStyle={TOOLTIP_STYLE}
                        formatter={(val, name) => [`${val} empresa${val !== 1 ? 's' : ''}`, name]} />
                </PieChart>
            </ResponsiveContainer>
            <div className="flex flex-wrap gap-x-3 gap-y-1 mt-1">
                {data.map((d, i) => (
                    <div key={i} className="flex items-center gap-1.5">
                        <div className="w-2 h-2 rounded-full shrink-0" style={{ background: d.color }} />
                        <span className="text-white/45 text-[11px]">
                            {d.name} <span className="text-white/70 font-semibold">{d.value}</span>
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function GraficoServico({ empresas }) {
    const cnt = {};
    empresas.forEach(e => { const k = e.service_type || 'sem_tipo'; cnt[k] = (cnt[k] || 0) + 1; });
    const data = [
        { name: 'POLO',       key: 'polo',       color: '#3b82f6' },
        { name: 'Assessoria', key: 'assessoria',  color: '#a855f7' },
        { name: 'Incubadora', key: 'incubadora',  color: '#10b981' },
        { name: 'Sem tipo',   key: 'sem_tipo',    color: '#374151' },
    ].map(d => ({ ...d, value: cnt[d.key] || 0 })).filter(d => d.value > 0);
    return <ChartCard titulo="Tipo de serviço"><MiniPie data={data} /></ChartCard>;
}

function GraficoContrato({ empresas }) {
    const cnt = {};
    empresas.forEach(e => { const k = e.contract_type || 'sem_tipo'; cnt[k] = (cnt[k] || 0) + 1; });
    const data = [
        { name: 'Fixo',        key: 'fixo',       color: '#6366f1' },
        { name: 'Progressão',  key: 'progressao', color: '#ffe600' },
        { name: 'Indefinido',  key: 'sem_tipo',   color: '#374151' },
    ].map(d => ({ ...d, value: cnt[d.key] || 0 })).filter(d => d.value > 0);
    return <ChartCard titulo="Tipo de contrato"><MiniPie data={data} /></ChartCard>;
}

function GraficoFaixas({ empresas }) {
    const cnt = {};
    empresas.filter(e => e.estado === 'ok' && e.faixa)
            .forEach(e => { cnt[e.faixa] = (cnt[e.faixa] || 0) + 1; });

    const data = ['faixa_1','faixa_2','faixa_3','faixa_4','faixa_5','faixa_6','maxima']
        .map((k, i) => ({ name: k === 'maxima' ? 'Máx' : `F${i + 1}`, full: k === 'maxima' ? 'Máx' : `Faixa ${i + 1}`, value: cnt[k] || 0 }))
        .filter(d => d.value > 0);

    return (
        <ChartCard titulo="Distribuição de faixas">
            {data.length === 0
                ? <p className="text-white/25 text-[12px] text-center py-5">Sem dados</p>
                : (
                    <ResponsiveContainer width="100%" height={110}>
                        <BarChart data={data} barSize={18} margin={{ top: 4, right: 4, left: -28, bottom: 0 }}>
                            <XAxis dataKey="name" tick={{ fill: 'rgba(255,255,255,0.45)', fontSize: 11 }}
                                axisLine={false} tickLine={false} />
                            <YAxis allowDecimals={false} tick={{ fill: 'rgba(255,255,255,0.3)', fontSize: 10 }}
                                axisLine={false} tickLine={false} />
                            <Tooltip contentStyle={TOOLTIP_STYLE} cursor={{ fill: 'rgba(255,255,255,0.03)' }}
                                formatter={(val, _, props) => [`${val} empresa${val !== 1 ? 's' : ''}`, props.payload.full]} />
                            <Bar dataKey="value" fill="#ffe600" radius={[4, 4, 0, 0]} />
                        </BarChart>
                    </ResponsiveContainer>
                )
            }
        </ChartCard>
    );
}

function EvolucaoBadge({ evolucao }) {
    if (!evolucao) return null;
    const config = {
        subiu:   { Icon: TrendingUp,   cls: 'text-emerald-400', title: 'Subiu de faixa'   },
        desceu:  { Icon: TrendingDown,  cls: 'text-red-400',     title: 'Desceu de faixa'  },
        manteve: { Icon: Minus,         cls: 'text-white/25',    title: 'Manteve a faixa'  },
    }[evolucao];
    if (!config) return null;
    const { Icon, cls, title } = config;
    return <Icon size={14} className={cn('shrink-0', cls)} title={title} />;
}

function RecebidoToggle({ empresa, mesSelecionado }) {
    const [loading, setLoading] = useState(false);

    // Filhas não têm toggle individual — pagamento é feito pelo pai
    if (empresa.estado !== 'ok' || empresa.is_filha) return null;

    function toggle(e) {
        e.stopPropagation();
        setLoading(true);
        router.post(
            route('admin.financeiro.recebido', empresa.id),
            { mes: mesSelecionado },
            { preserveScroll: true, onFinish: () => setLoading(false) }
        );
    }

    return (
        <button
            onClick={toggle}
            disabled={loading}
            title={empresa.recebido ? 'Desmarcar recebido' : 'Marcar como recebido'}
            className={cn(
                'shrink-0 w-6 h-6 rounded-full border-2 flex items-center justify-center transition-all',
                empresa.recebido
                    ? 'border-emerald-400 bg-emerald-400/20 text-emerald-400'
                    : 'border-white/20 text-transparent hover:border-white/40 hover:text-white/20'
            )}
        >
            <Check size={11} />
        </button>
    );
}

function TotalConsolidado({ empresas }) {
    // Filhas não entram no total — valor já está no pai via valor_mensal_grupo
    const contam        = empresas.filter(e => e.conta_no_total !== false && e.estado === 'ok');
    const recebidas     = contam.filter(e => e.recebido);
    const inadimplentes = contam.filter(e => !e.recebido);
    const totalAReceber = contam.reduce((s, e) => s + Number(e.valor_mensal_grupo ?? e.valor_mensal ?? 0), 0);
    const totalRecebido = recebidas.reduce((s, e) => s + Number(e.valor_mensal_grupo ?? e.valor_mensal ?? 0), 0);
    const totalPendente = inadimplentes.reduce((s, e) => s + Number(e.valor_mensal_grupo ?? e.valor_mensal ?? 0), 0);
    const temDados      = contam.length > 0;

    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4">
            <div className="flex items-center gap-2 mb-3">
                <Banknote size={15} className="text-ecf-yellow/60 shrink-0" />
                <span className="text-white/50 text-[11px] font-semibold tracking-widest uppercase">
                    Total consolidado · {contam.length} pagador{contam.length !== 1 ? 'es' : ''} com dados
                </span>
            </div>
            <div className="grid grid-cols-3 gap-3">
                <div className="rounded-xl bg-white/[0.03] border border-white/[0.06] p-3">
                    <p className="text-white/40 text-[11px] mb-1">Recebido (mês)</p>
                    <p className="font-display font-bold text-xl text-emerald-400">
                        {temDados ? fmtBRL(totalRecebido) : '—'}
                    </p>
                    <p className="text-white/30 text-[11px] mt-0.5">
                        {recebidas.length} empresa{recebidas.length !== 1 ? 's' : ''}
                    </p>
                </div>
                <div className="rounded-xl bg-white/[0.03] border border-red-500/[0.15] p-3">
                    <p className="text-white/40 text-[11px] mb-1">Inadimplente</p>
                    <p className="font-display font-bold text-xl text-red-400">
                        {temDados ? fmtBRL(totalPendente) : '—'}
                    </p>
                    <p className="text-white/30 text-[11px] mt-0.5">
                        {inadimplentes.length} empresa{inadimplentes.length !== 1 ? 's' : ''}
                    </p>
                </div>
                <div className="rounded-xl bg-white/[0.03] border border-white/[0.06] p-3">
                    <p className="text-white/40 text-[11px] mb-1">A receber</p>
                    <p className="font-display font-bold text-xl text-ecf-yellow">
                        {temDados ? fmtBRL(totalAReceber) : '—'}
                    </p>
                    <p className="text-white/30 text-[11px] mt-0.5">
                        {contam.length} empresa{contam.length !== 1 ? 's' : ''}
                    </p>
                </div>
            </div>
        </div>
    );
}

function FaixaProgresso({ faturamento, faixa }) {
    if (faixa === 'maxima') {
        return (
            <div className="flex items-center gap-2 py-3">
                <TrendingUp size={14} className="text-ecf-yellow shrink-0" />
                <span className="text-ecf-yellow text-[13px] font-semibold">Faixa máxima</span>
                <span className="text-white/30 text-[12px]">acima de R$ 5.000.000</span>
            </div>
        );
    }

    if (!faixa || faturamento == null) return null;

    const faixaData = FAIXAS_LIMITES[faixa];
    if (!faixaData) return null;

    const pct   = Math.min(100, Math.max(0,
        ((Number(faturamento) - faixaData.min) / (faixaData.proximo - faixaData.min)) * 100
    ));
    const falta = Math.max(0, faixaData.proximo - Number(faturamento));

    return (
        <div className="py-3">
            <div className="flex items-center justify-between mb-1.5">
                <span className="text-white/60 text-[12px] font-semibold">{faixaData.nome}</span>
                <span className="text-white/50 text-[11px]">{Math.round(pct)}%</span>
            </div>
            <div className="h-1.5 bg-ecf-yellow/30 rounded-full overflow-hidden">
                <div
                    className="h-full rounded-full transition-all"
                    style={{ width: `${pct}%`, background: '#ffe600' }}
                />
            </div>
            <p className="text-white/40 text-[12px] mt-1.5">
                Falta {fmtBRL(falta)} para a próxima faixa
            </p>
        </div>
    );
}

function FechamentoRow({ empresa, expandida, onToggle, mesSelecionado }) {
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
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                    <span className="text-white font-semibold text-[13px] truncate">{empresa.name}</span>
                    {empresa.filhas?.length > 0 && (
                        <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-ecf-yellow/10 text-ecf-yellow border border-ecf-yellow/20 shrink-0">
                            Grupo · {empresa.filhas.length + 1}
                        </span>
                    )}
                    {empresa.is_filha && (
                        <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-white/[0.05] text-white/40 border border-white/[0.08] shrink-0">
                            Vinculada · {empresa.nome_pai}
                        </span>
                    )}
                </div>
                {empresa.estado === 'ok' && (
                    <span className="text-white/40 text-[12px] mt-0.5 block">
                        {fmtBRL(empresa.faturamento)} · {empresa.periodo_inicio} a {empresa.periodo_fim}
                    </span>
                )}
            </div>
            <EvolucaoBadge evolucao={empresa.evolucao} />
            <ServiceBadge tipo={empresa.service_type} />
            {!empresa.has_adman && <IntegrationBadge />}
            {empresa.estado === 'ok' && (empresa.valor_mensal_grupo ?? empresa.valor_mensal) != null && (
                <span className={cn('text-[13px] font-semibold font-mono shrink-0',
                    empresa.is_filha ? 'text-white/25' : 'text-emerald-400')}>
                    {fmtBRL(empresa.valor_mensal_grupo ?? empresa.valor_mensal)}
                    <span className="text-white/30 font-normal text-[11px]">/mês</span>
                </span>
            )}
            <RecebidoToggle empresa={empresa} mesSelecionado={mesSelecionado} />
            <span className="text-white/40 text-[13px] font-mono shrink-0">{datas}</span>
        </div>
    );
}

function ServiceForm({ empresa, onClose }) {
    const { data, setData, patch, processing, errors } = useForm({
        service_type:       empresa.service_type       ?? '',
        contract_type:      empresa.contract_type      ?? '',
        contract_start:     empresa.contract_start     ?? '',
        contract_end:       empresa.contract_end       ?? '',
        additional_service: empresa.additional_service ?? '',
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
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
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
                        Tipo de contrato
                    </label>
                    <select
                        value={data.contract_type}
                        onChange={e => setData('contract_type', e.target.value)}
                        className="w-full h-9 pl-3 pr-8 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40"
                    >
                        <option value="">Selecionar tipo...</option>
                        <option value="fixo">Fixo</option>
                        <option value="progressao">Escala de Progressão</option>
                    </select>
                    {errors.contract_type && (
                        <span className="text-[11px] text-red-400 mt-1 block">{errors.contract_type}</span>
                    )}
                </div>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
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
            <div className="mt-4">
                <label className="block text-[11px] uppercase tracking-wider text-white/40 mb-1">
                    Serviço adicional
                </label>
                <input
                    type="text"
                    value={data.additional_service}
                    onChange={e => setData('additional_service', e.target.value)}
                    placeholder="Descreva o serviço adicional..."
                    className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20"
                />
                {errors.additional_service && (
                    <span className="text-[11px] text-red-400 mt-1 block">{errors.additional_service}</span>
                )}
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
    const temGrupo = empresa.filhas?.length > 0;

    return (
        <div className="px-4 py-4 bg-black/30 border-t border-white/[0.04]">
            {empresa.estado === 'ok' && (
                <>
                    <FaixaProgresso faturamento={empresa.faturamento} faixa={empresa.faixa} />

                    {/* Breakdown de grupo (pai + filhas) */}
                    {temGrupo && (
                        <div className="mb-3 rounded-lg border border-white/[0.06] overflow-hidden">
                            <div className="px-3 py-1.5 bg-white/[0.02] border-b border-white/[0.04]">
                                <span className="text-[11px] uppercase tracking-wider text-white/40">Composição do grupo</span>
                            </div>
                            {[empresa, ...empresa.filhas].map((e, i) => (
                                <div key={e.id} className={cn('flex items-center justify-between px-3 py-2', i > 0 && 'border-t border-white/[0.03]')}>
                                    <span className="text-white/60 text-[12px]">
                                        {i === 0 ? `${e.name} (este)` : `↳ ${e.name}`}
                                    </span>
                                    <span className="text-white/50 text-[12px] font-mono">
                                        {e.valor_mensal != null ? fmtBRL(e.valor_mensal) : '—'}
                                    </span>
                                </div>
                            ))}
                            <div className="flex items-center justify-between px-3 py-2 border-t border-white/[0.06] bg-white/[0.02]">
                                <span className="text-[11px] uppercase tracking-wider text-white/50 font-semibold">Total do grupo</span>
                                <span className="text-emerald-400 text-[13px] font-bold font-mono">{fmtBRL(empresa.valor_mensal_grupo)}</span>
                            </div>
                        </div>
                    )}

                    {/* Mensalidade individual ou grupo */}
                    {!temGrupo && empresa.valor_mensal != null && (
                        <div className="flex items-center justify-between py-2 mb-2 border-b border-white/[0.04]">
                            <span className="text-[11px] uppercase tracking-wider text-white/40">Mensalidade a cobrar</span>
                            <span className="text-emerald-400 text-[15px] font-bold font-mono">
                                {fmtBRL(empresa.valor_mensal)}
                            </span>
                        </div>
                    )}
                </>
            )}
            <div className="border-t border-white/[0.04] pt-4">
                <ServiceForm empresa={empresa} onClose={onClose} />
            </div>
        </div>
    );
}

function FechamentoList({ empresas, mesSelecionado }) {
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
                        mesSelecionado={mesSelecionado}
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

function MesSeletor({ mesSelecionado }) {
    const meses = useMemo(() => {
        const lista = [];
        const agora = new Date();
        for (let i = 0; i < 12; i++) {
            const d     = new Date(agora.getFullYear(), agora.getMonth() - i, 1);
            const value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
            const label = d.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
            lista.push({ value, label });
        }
        return lista;
    }, []);

    function handleChange(e) {
        router.get(route('admin.financeiro'), { mes: e.target.value }, { preserveScroll: true });
    }

    return (
        <select
            value={mesSelecionado}
            onChange={handleChange}
            className="h-9 pl-3 pr-8 rounded-lg border border-white/[0.08] bg-ecf-card text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 shrink-0"
        >
            {meses.map(m => (
                <option key={m.value} value={m.value}>{m.label}</option>
            ))}
        </select>
    );
}

const FILTROS_INICIAL = { busca: '', service_type: '', contract_type: '', estado: '', recebido: '' };

function FiltroBarra({ filtros, onChange, total, filtrado }) {
    const sel = 'h-8 pl-2.5 pr-7 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[12px] text-white/70 focus:outline-none focus:border-ecf-yellow/40';
    const ativo = Object.values(filtros).some(v => v !== '');

    return (
        <div className="flex flex-wrap items-center gap-2">
            <input
                type="text"
                value={filtros.busca}
                onChange={e => onChange({ ...filtros, busca: e.target.value })}
                placeholder="Buscar empresa..."
                className="h-8 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[12px] text-white/70 focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20 w-44"
            />
            <select value={filtros.service_type} onChange={e => onChange({ ...filtros, service_type: e.target.value })} className={sel}>
                <option value="">Serviço</option>
                <option value="polo">POLO</option>
                <option value="assessoria">Assessoria</option>
                <option value="incubadora">Incubadora</option>
            </select>
            <select value={filtros.contract_type} onChange={e => onChange({ ...filtros, contract_type: e.target.value })} className={sel}>
                <option value="">Contrato</option>
                <option value="fixo">Fixo</option>
                <option value="progressao">Progressão</option>
            </select>
            <select value={filtros.estado} onChange={e => onChange({ ...filtros, estado: e.target.value })} className={sel}>
                <option value="">Estado</option>
                <option value="ok">Com dados</option>
                <option value="sem_dados">Sem dados</option>
                <option value="sem_integracao">Sem integração</option>
            </select>
            <select value={filtros.recebido} onChange={e => onChange({ ...filtros, recebido: e.target.value })} className={sel}>
                <option value="">Pagamento</option>
                <option value="sim">Recebido</option>
                <option value="nao">Pendente</option>
            </select>
            {ativo && (
                <button
                    onClick={() => onChange(FILTROS_INICIAL)}
                    className="h-8 px-2.5 rounded-lg text-[12px] text-white/40 hover:text-white/70 border border-white/[0.06] hover:border-white/20 transition-colors"
                >
                    Limpar
                </button>
            )}
            {ativo && (
                <span className="text-white/30 text-[12px] ml-auto">
                    {filtrado} de {total}
                </span>
            )}
        </div>
    );
}

export default function Financeiro({ companies, mes_selecionado }) {
    const [filtros, setFiltros] = useState(FILTROS_INICIAL);

    const filtradas = useMemo(() => companies.filter(e => {
        if (filtros.busca && !e.name.toLowerCase().includes(filtros.busca.toLowerCase())) return false;
        if (filtros.service_type && e.service_type !== filtros.service_type) return false;
        if (filtros.contract_type && e.contract_type !== filtros.contract_type) return false;
        if (filtros.estado && e.estado !== filtros.estado) return false;
        if (filtros.recebido === 'sim' && !e.recebido) return false;
        if (filtros.recebido === 'nao' && e.recebido) return false;
        return true;
    }), [companies, filtros]);

    return (
        <AppLayout title="Fechamento">
            <main className="p-6">
                <div className="space-y-6">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <div className="flex items-center gap-2 mb-2">
                                <Banknote size={20} className="text-ecf-yellow" />
                                <h1 className="text-xl font-semibold font-display text-white">Fechamento</h1>
                            </div>
                            <p className="text-[13px] text-white/40">Faturamento mensal, faixa de investimento e dados de contrato por empresa ativa.</p>
                        </div>
                        <MesSeletor mesSelecionado={mes_selecionado} />
                    </div>
                    <div className="grid grid-cols-3 gap-4">
                        <GraficoServico empresas={companies} />
                        <GraficoContrato empresas={companies} />
                        <GraficoFaixas empresas={companies} />
                    </div>
                    <TotalConsolidado empresas={companies} />
                    <div className="rounded-xl border border-white/[0.08] bg-white/[0.02]">
                        <div className="px-4 py-3 border-b border-white/[0.04]">
                            <FiltroBarra
                                filtros={filtros}
                                onChange={setFiltros}
                                total={companies.length}
                                filtrado={filtradas.length}
                            />
                        </div>
                        <FechamentoList empresas={filtradas} mesSelecionado={mes_selecionado} />
                    </div>
                </div>
            </main>
        </AppLayout>
    );
}
