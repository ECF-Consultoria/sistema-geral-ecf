import AppLayout from '@/Layouts/AppLayout';
import { Link } from '@inertiajs/react';
import { Badge } from '@/Components/ui/badge';
import { ArrowLeft, Building2, Star, CalendarCheck, Target, FileText, TrendingUp, AlertTriangle } from 'lucide-react';
import { formatCurrency, formatPercent, formatDate, formatDateTime } from '@/lib/utils';
import { cn } from '@/lib/utils';

const statusColor = { pending: 'secondary', completed: 'success', cancelled: 'destructive', scheduled: 'outline' };
const statusLabel = { pending: 'Pendente', completed: 'Realizada', cancelled: 'Cancelada', scheduled: 'Agendada' };

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

export default function CompanyShow({ company }) {
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

    return (
        <AppLayout title={company.name}>
            <div className="space-y-5 max-w-[1100px]">
                {/* Breadcrumb */}
                <Link
                    href={route('companies.index')}
                    className="inline-flex items-center gap-1.5 text-white/40 hover:text-white/70 text-sm transition-colors"
                >
                    <ArrowLeft size={14} /> Empresas
                </Link>

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

                {/* KPIs rápidos */}
                <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                    {[
                        { label: 'TACOS', value: latestMetric?.tacos ? formatPercent(latestMetric.tacos) : '—', color: 'text-ecf-yellow' },
                        { label: 'Faturamento (30d)', value: company.revenue_30d ? formatCurrency(company.revenue_30d) : '—', color: 'text-blue-400' },
                        { label: 'NPS Médio', value: avgNps ? avgNps : '—', color: avgNps >= 9 ? 'text-emerald-400' : avgNps >= 7 ? 'text-ecf-yellow' : 'text-red-400' },
                        { label: 'Absenteísmo', value: completedMeetings.length > 0 ? `${absenteeism}%` : '—', color: 'text-orange-400' },
                    ].map(k => (
                        <div key={k.label} className="card-ecf rounded-2xl p-4">
                            <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wide">{k.label}</p>
                            <p className={cn('font-display font-extrabold text-2xl mt-1', k.color)}>{k.value}</p>
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
        </AppLayout>
    );
}
