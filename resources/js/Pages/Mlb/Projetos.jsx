import AppLayout from '@/Layouts/AppLayout';
import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { AlertTriangle, ChevronDown, ChevronUp, Building2, Link2, BookUser } from 'lucide-react';
import {
    PieChart, Pie, Cell, ResponsiveContainer, Tooltip,
} from 'recharts';

const PRIORIDADE_COR = {
    '1 Urgente': 'text-red-400',
    '2 Alto':    'text-orange-400',
    '3 Média':   'text-yellow-400',
    '4 Baixa':   'text-white/30',
};

const PROJETO_COR = {
    'POLOS':      { bg: 'bg-ecf-yellow/10',   border: 'border-ecf-yellow/30',   text: 'text-ecf-yellow',   hex: '#ffe600' },
    'Assessoria': { bg: 'bg-blue-500/10',      border: 'border-blue-500/30',     text: 'text-blue-400',     hex: '#3b82f6' },
    'Incubadora': { bg: 'bg-purple-500/10',    border: 'border-purple-500/30',   text: 'text-purple-400',   hex: '#a855f7' },
    'Implantação':{ bg: 'bg-emerald-500/10',   border: 'border-emerald-500/30',  text: 'text-emerald-400',  hex: '#22c55e' },
    'Outros':     { bg: 'bg-white/5',          border: 'border-white/10',        text: 'text-white/50',     hex: '#6b7280' },
};

function getCor(nome) {
    return PROJETO_COR[nome] ?? { bg: 'bg-white/5', border: 'border-white/10', text: 'text-white/50', hex: '#6b7280' };
}

// ─── Gráfico de distribuição ─────────────────────────────────────────────────

const tooltipStyle = {
    contentStyle: {
        background: '#0f1116',
        border: '1px solid rgba(255,255,255,0.07)',
        borderRadius: 10,
        fontSize: 12,
    },
    labelStyle: { color: '#9ba0aa' },
};

function CustomTooltip({ active, payload }) {
    if (!active || !payload?.length) return null;
    const d = payload[0].payload;
    return (
        <div style={tooltipStyle.contentStyle} className="px-3 py-2">
            <p className="text-white/60 text-[11px] mb-0.5">{d.nome}</p>
            <p className="text-white font-bold text-sm">{d.total} empresa{d.total !== 1 ? 's' : ''}</p>
            <p className="text-white/50 text-[11px]">{d.pct}% do total</p>
        </div>
    );
}

function DistribuicaoChart({ distribuicao, totalEmpresas }) {
    if (!distribuicao?.length) return null;

    return (
        <div className="card-ecf rounded-2xl p-5 mb-6">
            <div className="flex items-center gap-2 mb-5">
                <div className="w-7 h-7 rounded-lg bg-ecf-yellow/10 border border-ecf-yellow/20 flex items-center justify-center">
                    <Building2 size={13} className="text-ecf-yellow" />
                </div>
                <div>
                    <p className="text-white font-semibold text-sm leading-none">Distribuição por Tipo</p>
                    <p className="text-white/30 text-[11px] mt-0.5">{totalEmpresas} empresa{totalEmpresas !== 1 ? 's' : ''} no total</p>
                </div>
            </div>

            <div className="flex flex-col md:flex-row items-center gap-6">
                {/* Donut */}
                <div className="shrink-0 w-44 h-44">
                    <ResponsiveContainer width="100%" height="100%">
                        <PieChart>
                            <Pie
                                data={distribuicao}
                                cx="50%"
                                cy="50%"
                                innerRadius={52}
                                outerRadius={76}
                                paddingAngle={3}
                                dataKey="total"
                                strokeWidth={0}
                            >
                                {distribuicao.map((entry) => (
                                    <Cell key={entry.nome} fill={getCor(entry.nome).hex} />
                                ))}
                            </Pie>
                            <Tooltip content={<CustomTooltip />} />
                        </PieChart>
                    </ResponsiveContainer>
                </div>

                {/* Legenda */}
                <div className="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-3 w-full">
                    {distribuicao.map((item) => {
                        const cor = getCor(item.nome);
                        return (
                            <div key={item.nome} className={cn('rounded-xl border px-4 py-3', cor.border, cor.bg)}>
                                <div className="flex items-center justify-between mb-2">
                                    <div className="flex items-center gap-2">
                                        <span
                                            className="w-2.5 h-2.5 rounded-full shrink-0"
                                            style={{ background: cor.hex }}
                                        />
                                        <span className={cn('text-[12px] font-semibold', cor.text)}>{item.nome}</span>
                                    </div>
                                    <span className={cn('text-[13px] font-bold', cor.text)}>{item.pct}%</span>
                                </div>
                                {/* barra de progresso */}
                                <div className="h-1 bg-white/10 rounded-full overflow-hidden">
                                    <div
                                        className="h-full rounded-full transition-all"
                                        style={{ width: `${item.pct}%`, background: cor.hex }}
                                    />
                                </div>
                                <p className="text-white/30 text-[11px] mt-1.5">
                                    {item.total} empresa{item.total !== 1 ? 's' : ''}
                                </p>
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

// ─── Componentes de listagem ──────────────────────────────────────────────────

function ProgressBar({ ok, total }) {
    const pct   = total > 0 ? Math.round(ok / total * 100) : 0;
    const color = pct === 100 ? '#22c55e' : pct >= 50 ? '#eab308' : '#8b5cf6';
    return (
        <div className="flex items-center gap-2 w-24">
            <div className="flex-1 h-1 bg-white/10 rounded-full overflow-hidden">
                <div style={{ width: `${pct}%`, background: color }} className="h-full rounded-full" />
            </div>
            <span className="text-white/30 text-[10px] shrink-0">{ok}/{total}</span>
        </div>
    );
}

function EmpresaRow({ e, appUrl }) {
    return (
        <div className={cn(
            'flex items-center gap-3 px-4 py-3 rounded-xl border border-white/[0.06] bg-white/[0.02] hover:bg-white/[0.04] transition-colors',
            e.problema && 'border-red-500/20 bg-red-500/[0.03]'
        )}>
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-white text-[13px] font-medium truncate">{e.nome}</span>
                    {e.problema && (
                        <AlertTriangle size={11} className="text-red-400 shrink-0" />
                    )}
                    {e.estagio && (
                        <span className={cn('px-1.5 py-0.5 rounded text-[10px] font-semibold',
                            e.estagio === 'Concluido'
                                ? 'bg-emerald-500/15 text-emerald-400'
                                : 'bg-purple-500/15 text-purple-400'
                        )}>{e.estagio}</span>
                    )}
                    {e.prioridade && (
                        <span className={cn('text-[11px]', PRIORIDADE_COR[e.prioridade] ?? 'text-white/30')}>
                            {e.prioridade}
                        </span>
                    )}
                </div>
                {e.responsavel_nome && (
                    <p className="text-white/30 text-[11px] mt-0.5">→ {e.responsavel_nome}</p>
                )}
            </div>
            <div className="flex items-center gap-1 shrink-0">
                {e.implementacao_token && (
                    <>
                        <a
                            href={`${appUrl}/implementacao/${e.implementacao_token}`}
                            target="_blank"
                            rel="noreferrer"
                            title="Link do Cliente (Implementação)"
                            className="p-1.5 rounded-lg text-white/20 hover:text-ecf-yellow hover:bg-ecf-yellow/10 transition-colors"
                        >
                            <Link2 size={13} />
                        </a>
                        <a
                            href={`${appUrl}/implementacao/${e.implementacao_token}/publicador`}
                            target="_blank"
                            rel="noreferrer"
                            title="Visão do Publicador"
                            className="p-1.5 rounded-lg text-white/20 hover:text-violet-400 hover:bg-violet-500/10 transition-colors"
                        >
                            <BookUser size={13} />
                        </a>
                    </>
                )}
                <ProgressBar ok={e.progresso.ok} total={e.progresso.total} />
            </div>
        </div>
    );
}

function MSection({ mes, empresas, appUrl }) {
    const [open, setOpen] = useState(false);
    return (
        <div className="rounded-xl border border-white/[0.07] overflow-hidden">
            <button
                type="button"
                onClick={() => setOpen(o => !o)}
                className="w-full flex items-center justify-between px-4 py-3 bg-white/[0.03] hover:bg-white/[0.05] transition-colors"
            >
                <div className="flex items-center gap-3">
                    <span className="text-ecf-yellow font-bold text-sm">{mes}</span>
                    <span className="text-white/40 text-[11px]">= Mês {mes.replace('M', '')}</span>
                    <span className="px-2 py-0.5 rounded-full bg-ecf-yellow/10 text-ecf-yellow text-[11px] font-semibold border border-ecf-yellow/20">
                        {empresas.length} empresa{empresas.length !== 1 ? 's' : ''}
                    </span>
                </div>
                {open ? <ChevronUp size={14} className="text-white/30" /> : <ChevronDown size={14} className="text-white/30" />}
            </button>
            {open && (
                <div className="p-3 space-y-2">
                    {empresas.map(e => <EmpresaRow key={e.id} e={e} appUrl={appUrl} />)}
                </div>
            )}
        </div>
    );
}

function ProjetoSection({ nome, children, total }) {
    const [open, setOpen] = useState(false);
    const cor = PROJETO_COR[nome] ?? { bg: 'bg-white/5', border: 'border-white/10', text: 'text-white/60' };

    return (
        <div className={cn('rounded-2xl border overflow-hidden', cor.border, cor.bg)}>
            <button
                type="button"
                onClick={() => setOpen(o => !o)}
                className="w-full flex items-center justify-between px-5 py-4 hover:brightness-110 transition-all"
            >
                <div className="flex items-center gap-3">
                    <span className={cn('font-bold text-base', cor.text)}>{nome}</span>
                    <span className={cn('px-2 py-0.5 rounded-full text-[12px] font-semibold border', cor.border, cor.text)}>
                        {total} empresa{total !== 1 ? 's' : ''}
                    </span>
                </div>
                {open ? <ChevronUp size={16} className="text-white/30" /> : <ChevronDown size={16} className="text-white/30" />}
            </button>
            {open && (
                <div className="px-4 pb-4 space-y-3">
                    {children}
                </div>
            )}
        </div>
    );
}

// ─── Página principal ─────────────────────────────────────────────────────────

export default function Projetos({ polos, outros, totais, distribuicao, totalEmpresas }) {
    const { asset_url } = usePage().props;
    const appUrl  = asset_url ?? '';
    const temPolos   = Object.keys(polos ?? {}).length > 0;
    const temOutros  = Object.keys(outros ?? {}).length > 0;

    return (
        <AppLayout title="Projetos">
            <div className="space-y-5 max-w-4xl">

                <div>
                    <h2 className="text-white font-semibold text-lg">Projetos</h2>
                    <p className="text-white/40 text-sm mt-0.5">
                        Empresas agrupadas por projeto — atribuição automática pela fase
                    </p>
                </div>

                {/* Gráfico de distribuição */}
                <DistribuicaoChart distribuicao={distribuicao} totalEmpresas={totalEmpresas ?? 0} />

                {!temPolos && !temOutros && (
                    <div className="card-ecf rounded-2xl p-12 text-center text-white/20">
                        Nenhuma empresa com fase definida encontrada.
                    </div>
                )}

                {/* POLOS */}
                {temPolos && (
                    <ProjetoSection nome="POLOS" total={totais.POLOS}>
                        <p className="text-white/30 text-[12px] pb-1">
                            Empresas com fase M0 a M4. Cada M representa um mês de implantação.
                        </p>
                        {Object.entries(polos).map(([mes, empresas]) => (
                            <MSection key={mes} mes={mes} empresas={empresas} appUrl={appUrl} />
                        ))}
                    </ProjetoSection>
                )}

                {/* Outros projetos */}
                {Object.entries(outros ?? {}).map(([proj, empresas]) => (
                    <ProjetoSection key={proj} nome={proj} total={empresas.length}>
                        <div className="space-y-2">
                            {empresas.map(e => <EmpresaRow key={e.id} e={e} appUrl={appUrl} />)}
                        </div>
                    </ProjetoSection>
                ))}
            </div>
        </AppLayout>
    );
}
