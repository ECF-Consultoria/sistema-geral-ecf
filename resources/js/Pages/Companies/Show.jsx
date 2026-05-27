import AppLayout from '@/Layouts/AppLayout';
import { Link, router, useForm } from '@inertiajs/react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { ArrowLeft, Building2, Star, CalendarCheck, Target, FileText, TrendingUp, AlertTriangle, Briefcase, Plus, Pencil, PowerOff } from 'lucide-react';
import { formatCurrency, formatPercent, formatDate, formatDateTime } from '@/lib/utils';
import { cn } from '@/lib/utils';
import { useState, useMemo } from 'react';

const statusColor = { pending: 'secondary', completed: 'success', cancelled: 'destructive', scheduled: 'outline' };
const statusLabel = { pending: 'Pendente', completed: 'Realizada', cancelled: 'Cancelada', scheduled: 'Agendada' };

// ─── Tipos de cobrança (espelha App\Models\Servico) ─────────────────────────
const TIPO_COBRANCA = {
    mensal: { label: 'Mensal', className: 'bg-ecf-yellow/15 text-ecf-yellow border-ecf-yellow/25' },
    unica:  { label: 'Única',  className: 'bg-white/10 text-white/80 border-white/15' },
};

function TipoBadge({ tipo }) {
    const cfg = TIPO_COBRANCA[tipo] || { label: tipo, className: 'bg-white/10 text-white/60 border-white/10' };
    return (
        <span className={cn(
            'inline-flex items-center px-2 py-0.5 rounded-full border text-[10px] font-semibold uppercase tracking-wide',
            cfg.className,
        )}>
            {cfg.label}
        </span>
    );
}

function Section({ icon: Icon, title, children }) {
    return (
        <div className="card-ecf rounded-2xl overflow-hidden">
            <div className="flex items-center gap-2.5 px-5 py-4 border-b border-white/[0.06]">
                <Icon size={15} className="text-ecf-yellow/70" />
                <p className="text-white font-display font-bold text-[14px]">{title}</p>
            </div>
            <div className="p-5">{children}</div>
        </div>
    );
}

function InfoRow({ label, value }) {
    return (
        <div className="flex items-start justify-between py-2 border-b border-white/[0.04] last:border-0 gap-4">
            <span className="text-white/40 text-[12px] shrink-0">{label}</span>
            <span className="text-white/80 text-[13px] font-medium text-right">{value || '—'}</span>
        </div>
    );
}

export default function CompanyShow({ company, servicos_disponiveis = [] }) {
    const consultor = company.consultor?.[0];
    const estrategista = company.estrategista?.[0];
    const latestMetric = company.adman_metrics?.[0];

    const completedMeetings = (company.meetings || []).filter(m => m.status === 'completed');
    const absences = completedMeetings.filter(m => !m.consultant_present || !m.mentor_present).length;
    const absenteeism = completedMeetings.length > 0 ? (absences / completedMeetings.length * 100).toFixed(1) : 0;

    const completedNps = (company.nps_surveys || []).filter(s => s.status === 'completed' && s.response);
    const avgNps = completedNps.length > 0
        ? (completedNps.reduce((acc, s) => acc + (s.response?.score_overall ?? 0), 0) / completedNps.length).toFixed(1)
        : null;

    // ─── Contratos de serviço (Módulo Serviços — Frente A) ─────────────────
    const todosContratos = company.contratos_servico || [];
    const [showInativos, setShowInativos] = useState(false);
    const [contratoOpen, setContratoOpen] = useState(false);
    const [editingContrato, setEditingContrato] = useState(null);

    const contratosVisiveis = useMemo(
        () => showInativos ? todosContratos : todosContratos.filter(c => c.ativo),
        [todosContratos, showInativos],
    );

    const contratoForm = useForm({
        servico_id: '',
        valor_contratado: '',
        data_contratacao: new Date().toISOString().slice(0, 10),
        data_vencimento: '',
        observacoes: '',
        ativo: true,
    });

    const abrirNovoContrato = () => {
        setEditingContrato(null);
        contratoForm.setData({
            servico_id: '',
            valor_contratado: '',
            data_contratacao: new Date().toISOString().slice(0, 10),
            data_vencimento: '',
            observacoes: '',
            ativo: true,
        });
        contratoForm.clearErrors();
        setContratoOpen(true);
    };

    const abrirEditarContrato = (ct) => {
        setEditingContrato(ct);
        contratoForm.setData({
            servico_id: String(ct.servico?.id ?? ''),
            valor_contratado: ct.valor_contratado ?? '',
            data_contratacao: ct.data_contratacao || '',
            data_vencimento: ct.data_vencimento || '',
            observacoes: ct.observacoes || '',
            ativo: !!ct.ativo,
        });
        contratoForm.clearErrors();
        setContratoOpen(true);
    };

    // Ao escolher serviço no select, preenche valor_contratado com valor_padrao
    // (usuário pode editar livremente depois).
    const escolherServico = (id) => {
        const svc = servicos_disponiveis.find(s => String(s.id) === String(id));
        contratoForm.setData(prev => ({
            ...prev,
            servico_id: id,
            valor_contratado: svc ? svc.valor_padrao : prev.valor_contratado,
        }));
    };

    const submitContrato = (e) => {
        e.preventDefault();
        const onSuccess = () => { setContratoOpen(false); contratoForm.reset(); };
        if (editingContrato) {
            contratoForm.put(
                route('empresas.contratos.update', [company.id, editingContrato.id]),
                { onSuccess, preserveScroll: true },
            );
        } else {
            contratoForm.post(
                route('empresas.contratos.store', company.id),
                { onSuccess, preserveScroll: true },
            );
        }
    };

    const desativarContrato = (ct) => {
        if (confirm(`Desativar este contrato (${ct.servico?.nome ?? 'serviço'})?`)) {
            router.delete(route('empresas.contratos.destroy', [company.id, ct.id]), {
                preserveScroll: true,
            });
        }
    };

    return (
        <AppLayout title={company.name}>
            <div className="space-y-5 max-w-[1100px]">
                {/* Breadcrumb */}
                <button
                    onClick={() => window.history.back()}
                    className="inline-flex items-center gap-1.5 text-white/40 hover:text-white/70 text-sm transition-colors"
                >
                    <ArrowLeft size={14} /> Empresas
                </button>

                {/* Header */}
                <div className="card-ecf rounded-2xl p-6 flex items-start justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <div className="w-12 h-12 rounded-2xl bg-ecf-yellow/10 border border-ecf-yellow/20 flex items-center justify-center shrink-0">
                            <Building2 size={20} className="text-ecf-yellow/70" />
                        </div>
                        <div>
                            <h1 className="text-white font-display font-extrabold text-2xl tracking-tight">{company.name}</h1>
                            <div className="flex items-center gap-2 mt-1 flex-wrap">
                                {company.segment && <span className="text-white/40 text-sm">{company.segment}</span>}
                                {company.segment && company.cnpj && <span className="text-white/20">·</span>}
                                {company.cnpj && <span className="text-white/40 text-sm">{company.cnpj}</span>}
                                {company.adman_account_id && (
                                    <>
                                        <span className="text-white/20">·</span>
                                        {/* cust_id da Adman em destaque — analistas precisam dele
                                            pra cruzar dados manualmente com a dashboard Adman. */}
                                        <span className="inline-flex items-center gap-1 text-[11px] font-mono px-2 py-0.5 rounded bg-ecf-yellow/10 border border-ecf-yellow/20 text-ecf-yellow/80">
                                            cust {company.adman_account_id}
                                        </span>
                                    </>
                                )}
                            </div>
                        </div>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                        <Link
                            href={route('sugadores.config.show', company.id)}
                            className="inline-flex items-center gap-1.5 h-8 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/70 hover:text-white hover:bg-white/[0.05] text-[12px] font-medium"
                            title="Configurar detecção de Sugadores"
                        >
                            <AlertTriangle size={13} />
                            Sugadores
                        </Link>
                        <Badge variant={company.active ? 'success' : 'destructive'}>
                            {company.active ? 'Ativa' : 'Inativa'}
                        </Badge>
                    </div>
                </div>

                {/* KPIs financeiros 30d — Adman /accounts/metrics; fallback latestMetric (1 dia) se cache cold */}
                <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                    {[
                        { label: 'Faturamento (30d)', value: company.revenue_30d ? formatCurrency(company.revenue_30d) : '—', color: 'text-blue-400' },
                        { label: 'ACOS (30d)', value: (company.acos_30d ?? null) !== null ? formatPercent(company.acos_30d) : '—', color: 'text-orange-400' },
                        { label: 'TACOS (30d)', value: (company.tacos_30d ?? latestMetric?.tacos) ? formatPercent(company.tacos_30d ?? latestMetric?.tacos) : '—', color: 'text-ecf-yellow' },
                        { label: 'Margem % (30d)', value: (company.margin_pct_30d ?? null) !== null ? formatPercent(company.margin_pct_30d) : '—', color: 'text-emerald-400' },
                    ].map(k => (
                        <div key={k.label} className="card-ecf rounded-2xl p-4">
                            <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wide">{k.label}</p>
                            <p className={cn('font-display font-extrabold text-2xl mt-1', k.color)}>{k.value}</p>
                        </div>
                    ))}
                </div>

                {/* KPIs operacionais — linha secundária, menor */}
                <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
                    {[
                        { label: 'NPS Médio', value: avgNps ? avgNps : '—', color: avgNps >= 9 ? 'text-emerald-400' : avgNps >= 7 ? 'text-ecf-yellow' : 'text-red-400' },
                        { label: 'Absenteísmo', value: completedMeetings.length > 0 ? `${absenteeism}%` : '—', color: 'text-orange-400' },
                        { label: 'Invest. Ads (30d)', value: company.ad_investment_30d ? formatCurrency(company.ad_investment_30d) : '—', color: 'text-white/80' },
                    ].map(k => (
                        <div key={k.label} className="card-ecf rounded-xl p-3">
                            <p className="text-white/40 text-[10px] font-semibold uppercase tracking-wide">{k.label}</p>
                            <p className={cn('font-display font-bold text-lg mt-0.5 tabular-nums', k.color)}>{k.value}</p>
                        </div>
                    ))}
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                    {/* Dados cadastrais */}
                    <Section icon={Building2} title="Dados da Empresa">
                        <InfoRow label="Analista" value={consultor?.name} />
                        <InfoRow label="Estrategista" value={estrategista?.name} />
                        <InfoRow label="ID Conta Adman" value={company.adman_account_id} />
                        <InfoRow label="ID Loja ML" value={company.ml_store_id} />
                        <InfoRow label="ID Adman Store" value={company.adman_store_id} />
                        {company.notes && (
                            <div className="mt-3 pt-3 border-t border-white/[0.04]">
                                <p className="text-white/40 text-[11px] uppercase tracking-wide mb-1">Observações</p>
                                <p className="text-white/60 text-sm leading-relaxed">{company.notes}</p>
                            </div>
                        )}
                    </Section>

                    {/* Metas */}
                    <Section icon={Target} title="Metas Ativas">
                        {(company.goals || []).filter(g => g.active).length === 0 ? (
                            <p className="text-white/25 text-sm text-center py-4">Nenhuma meta cadastrada</p>
                        ) : (
                            <div className="space-y-2">
                                {company.goals.filter(g => g.active).map(g => (
                                    <div key={g.id} className="flex items-center justify-between py-2 border-b border-white/[0.04] last:border-0">
                                        <span className="text-white/60 text-[13px]">{g.metric_label ?? g.metric}</span>
                                        <span className="text-ecf-yellow font-bold text-[13px]">{g.target_value}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </Section>
                </div>

                {/* ─── Serviços contratados (Módulo Serviços — Frente A) ─── */}
                <div className="card-ecf rounded-2xl overflow-hidden">
                    <div className="flex items-center justify-between gap-2.5 px-5 py-4 border-b border-white/[0.06]">
                        <div className="flex items-center gap-2.5">
                            <Briefcase size={15} className="text-ecf-yellow/70" />
                            <p className="text-white font-display font-bold text-[14px]">Serviços contratados</p>
                            <span className="text-white/30 text-[11px]">
                                {contratosVisiveis.length} {contratosVisiveis.length === 1 ? 'contrato' : 'contratos'}
                            </span>
                        </div>
                        <div className="flex items-center gap-3">
                            <label className="inline-flex items-center gap-1.5 text-[11px] text-white/50 cursor-pointer select-none">
                                <input
                                    type="checkbox"
                                    checked={showInativos}
                                    onChange={e => setShowInativos(e.target.checked)}
                                    className="h-3.5 w-3.5 rounded border-white/20 bg-white/5 accent-ecf-yellow"
                                />
                                Mostrar inativos
                            </label>
                            <Button size="sm" onClick={abrirNovoContrato}>
                                <Plus className="h-3.5 w-3.5 mr-1" /> Adicionar contrato
                            </Button>
                        </div>
                    </div>
                    <div className="p-5">
                        {contratosVisiveis.length === 0 ? (
                            <p className="text-white/25 text-sm text-center py-6">
                                {todosContratos.length === 0
                                    ? 'Nenhum contrato de serviço registrado para esta empresa.'
                                    : 'Sem contratos para o filtro atual. Marque "Mostrar inativos" para ver o histórico.'}
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-[12px]">
                                    <thead>
                                        <tr className="text-white/30 border-b border-white/[0.06]">
                                            <th className="text-left pb-2 font-semibold">Serviço</th>
                                            <th className="text-right pb-2 font-semibold">Valor contratado</th>
                                            <th className="text-left pb-2 pl-3 font-semibold">Tipo</th>
                                            <th className="text-left pb-2 pl-3 font-semibold">Início</th>
                                            <th className="text-left pb-2 pl-3 font-semibold">Vencimento</th>
                                            <th className="text-left pb-2 pl-3 font-semibold">Status</th>
                                            <th className="text-right pb-2 font-semibold">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {contratosVisiveis.map(ct => (
                                            <tr
                                                key={ct.id}
                                                className={cn(
                                                    'border-b border-white/[0.03] last:border-0',
                                                    !ct.ativo && 'opacity-50',
                                                )}
                                            >
                                                <td className="py-2.5 text-white/85 font-medium">{ct.servico?.nome ?? '—'}</td>
                                                <td className="py-2.5 text-right text-white/80 tabular-nums">
                                                    {formatCurrency(ct.valor_contratado)}
                                                </td>
                                                <td className="py-2.5 pl-3">
                                                    <TipoBadge tipo={ct.servico?.tipo_cobranca} />
                                                </td>
                                                <td className="py-2.5 pl-3 text-white/60">
                                                    {ct.data_contratacao ? formatDate(ct.data_contratacao) : '—'}
                                                </td>
                                                <td className="py-2.5 pl-3 text-white/60">
                                                    {ct.data_vencimento ? formatDate(ct.data_vencimento) : <span className="text-white/30 italic">sem vencimento</span>}
                                                </td>
                                                <td className="py-2.5 pl-3">
                                                    {ct.ativo ? (
                                                        <span className="inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-[10px] font-semibold uppercase">Ativo</span>
                                                    ) : (
                                                        <span className="inline-flex items-center px-2 py-0.5 rounded-full bg-white/5 border border-white/10 text-white/40 text-[10px] font-semibold uppercase">Inativo</span>
                                                    )}
                                                </td>
                                                <td className="py-2.5 text-right">
                                                    <div className="flex justify-end gap-1">
                                                        <Button
                                                            size="icon"
                                                            variant="ghost"
                                                            onClick={() => abrirEditarContrato(ct)}
                                                            title="Editar contrato"
                                                            className="h-7 w-7"
                                                        >
                                                            <Pencil className="h-3.5 w-3.5" />
                                                        </Button>
                                                        {ct.ativo && (
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                onClick={() => desativarContrato(ct)}
                                                                title="Desativar contrato"
                                                                className="h-7 w-7 text-orange-400 hover:text-orange-300 hover:bg-orange-500/10"
                                                            >
                                                                <PowerOff className="h-3.5 w-3.5" />
                                                            </Button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                    {/* Reuniões recentes */}
                    <Section icon={CalendarCheck} title="Reuniões Recentes">
                        {(company.meetings || []).length === 0 ? (
                            <p className="text-white/25 text-sm text-center py-4">Nenhuma reunião registrada</p>
                        ) : (
                            <div className="space-y-2">
                                {company.meetings.slice(0, 8).map(m => (
                                    <div key={m.id} className="flex items-center justify-between py-2 border-b border-white/[0.04] last:border-0">
                                        <div>
                                            <p className="text-white/70 text-[13px]">{m.scheduled_at ? formatDate(m.scheduled_at) : '—'}</p>
                                            <p className="text-white/30 text-[11px]">{m.meeting_link || 'Sem link'}</p>
                                        </div>
                                        <Badge variant={statusColor[m.status] ?? 'secondary'} className="text-[10px]">
                                            {statusLabel[m.status] ?? m.status}
                                        </Badge>
                                    </div>
                                ))}
                            </div>
                        )}
                    </Section>

                    {/* NPS */}
                    <Section icon={Star} title="NPS Respondidos">
                        {completedNps.length === 0 ? (
                            <p className="text-white/25 text-sm text-center py-4">Nenhuma avaliação respondida</p>
                        ) : (
                            <div className="space-y-2">
                                {completedNps.slice(0, 8).map(s => {
                                    const score = s.response?.score_overall;
                                    const scoreColor = score >= 9 ? 'text-emerald-400' : score >= 7 ? 'text-ecf-yellow' : 'text-red-400';
                                    return (
                                        <div key={s.id} className="flex items-center justify-between py-2 border-b border-white/[0.04] last:border-0">
                                            <div>
                                                <p className="text-white/70 text-[13px]">{s.response?.respondent_name || '—'}</p>
                                                {s.response?.comment && (
                                                    <p className="text-white/30 text-[11px] truncate max-w-[200px]">{s.response.comment}</p>
                                                )}
                                            </div>
                                            <span className={cn('font-bold font-display text-lg', scoreColor)}>{score ?? '—'}</span>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </Section>
                </div>

                {/* PPAs */}
                {(company.ppas || []).length > 0 && (
                    <Section icon={FileText} title="PPAs">
                        <div className="space-y-2">
                            {company.ppas.map(p => (
                                <div key={p.id} className="flex items-center justify-between py-2 border-b border-white/[0.04] last:border-0">
                                    <div>
                                        <p className="text-white/70 text-[13px] font-medium">{p.title}</p>
                                        <p className="text-white/30 text-[11px]">Mentor: {p.mentor?.name || '—'}</p>
                                    </div>
                                    <div className="text-right">
                                        <p className="text-white/40 text-[11px]">{p.actions_count ?? 0} ações</p>
                                        <p className="text-ecf-yellow text-[12px] font-bold">{p.completion_pct ?? 0}%</p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Section>
                )}

                {/* Métricas Adman recentes */}
                {(company.adman_metrics || []).length > 0 && (
                    <Section icon={TrendingUp} title="Métricas Adman (últimos 30 dias)">
                        <div className="overflow-x-auto">
                            <table className="w-full text-[12px]">
                                <thead>
                                    <tr className="text-white/30 border-b border-white/[0.06]">
                                        <th className="text-left pb-2 font-semibold">Data</th>
                                        <th className="text-right pb-2 font-semibold">Faturamento</th>
                                        <th className="text-right pb-2 font-semibold">Investimento</th>
                                        <th className="text-right pb-2 font-semibold">TACOS</th>
                                        <th className="text-right pb-2 font-semibold">Margem</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {company.adman_metrics.slice(0, 10).map(m => (
                                        <tr key={m.id} className="border-b border-white/[0.03] last:border-0">
                                            <td className="py-2 text-white/50">{formatDate(m.reference_date)}</td>
                                            <td className="py-2 text-right text-blue-400 font-medium">{m.revenue ? formatCurrency(m.revenue) : '—'}</td>
                                            <td className="py-2 text-right text-white/40">{m.investment ? formatCurrency(m.investment) : '—'}</td>
                                            <td className="py-2 text-right text-ecf-yellow font-medium">{m.tacos ? formatPercent(m.tacos) : '—'}</td>
                                            <td className="py-2 text-right text-emerald-400">{m.contribution_margin_pct ? formatPercent(m.contribution_margin_pct) : '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Section>
                )}
            </div>

            {/* ─── Modal Adicionar/Editar contrato ──────────────────────── */}
            <Dialog open={contratoOpen} onOpenChange={setContratoOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {editingContrato ? 'Editar contrato' : 'Adicionar contrato'}
                        </DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitContrato} className="space-y-4">
                        {/* Select de serviço — bloqueado na edição (servico_id imutável após criação) */}
                        <div className="space-y-1.5">
                            <Label>Serviço *</Label>
                            {editingContrato ? (
                                <Input value={editingContrato.servico?.nome ?? '—'} disabled />
                            ) : (
                                <select
                                    value={contratoForm.data.servico_id}
                                    onChange={e => escolherServico(e.target.value)}
                                    required
                                    className="w-full rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[13px] text-white focus:border-ecf-yellow/40 focus:outline-none"
                                >
                                    <option value="">Selecionar...</option>
                                    {servicos_disponiveis.map(s => (
                                        <option key={s.id} value={s.id}>
                                            {s.nome} — {TIPO_COBRANCA[s.tipo_cobranca]?.label || s.tipo_cobranca} · {formatCurrency(s.valor_padrao)}
                                        </option>
                                    ))}
                                </select>
                            )}
                            {contratoForm.errors.servico_id && (
                                <p className="text-destructive text-xs">{contratoForm.errors.servico_id}</p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Valor contratado (R$) *</Label>
                            <Input
                                type="number"
                                step="0.01"
                                min="0"
                                value={contratoForm.data.valor_contratado}
                                onChange={e => contratoForm.setData('valor_contratado', e.target.value)}
                                required
                                placeholder="0,00"
                            />
                            <p className="text-white/30 text-[11px]">
                                Pré-preenchido com o valor padrão do serviço. Pode ser editado.
                            </p>
                            {contratoForm.errors.valor_contratado && (
                                <p className="text-destructive text-xs">{contratoForm.errors.valor_contratado}</p>
                            )}
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Data de contratação *</Label>
                                <Input
                                    type="date"
                                    value={contratoForm.data.data_contratacao}
                                    onChange={e => contratoForm.setData('data_contratacao', e.target.value)}
                                    required
                                />
                                {contratoForm.errors.data_contratacao && (
                                    <p className="text-destructive text-xs">{contratoForm.errors.data_contratacao}</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Data de vencimento</Label>
                                <Input
                                    type="date"
                                    value={contratoForm.data.data_vencimento || ''}
                                    onChange={e => contratoForm.setData('data_vencimento', e.target.value)}
                                />
                                <p className="text-white/30 text-[11px]">Em branco = vigente sem fim.</p>
                                {contratoForm.errors.data_vencimento && (
                                    <p className="text-destructive text-xs">{contratoForm.errors.data_vencimento}</p>
                                )}
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Observações</Label>
                            <Textarea
                                rows={2}
                                value={contratoForm.data.observacoes}
                                onChange={e => contratoForm.setData('observacoes', e.target.value)}
                                placeholder="Detalhes do contrato (opcional)"
                            />
                            {contratoForm.errors.observacoes && (
                                <p className="text-destructive text-xs">{contratoForm.errors.observacoes}</p>
                            )}
                        </div>

                        {editingContrato && (
                            <div className="flex items-center gap-2 pt-1">
                                <input
                                    type="checkbox"
                                    id="contrato-ativo"
                                    checked={!!contratoForm.data.ativo}
                                    onChange={e => contratoForm.setData('ativo', e.target.checked)}
                                    className="h-4 w-4 rounded border-white/20 bg-white/5 accent-ecf-yellow"
                                />
                                <Label htmlFor="contrato-ativo" className="cursor-pointer text-sm text-white/80">
                                    Contrato ativo
                                </Label>
                            </div>
                        )}

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setContratoOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={contratoForm.processing}>
                                {contratoForm.processing ? 'Salvando...' : editingContrato ? 'Atualizar' : 'Adicionar'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
