import AppLayout from '@/Layouts/AppLayout';
import { Link, router, useForm } from '@inertiajs/react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { ArrowLeft, Building2, Star, CalendarCheck, Target, FileText, TrendingUp, AlertTriangle, Briefcase, Plus, Pencil, PowerOff, ShoppingCart, Copy, Check, Unplug, RefreshCw, Info, CheckCircle2, Award } from 'lucide-react';
import { formatCurrency, formatPercent, formatDate, formatDateTime } from '@/lib/utils';
import { cn } from '@/lib/utils';
import { useState, useMemo } from 'react';
import EvolucaoEmpresaChart from '@/Pages/EmpresaAnaliseEcf/components/EvolucaoEmpresaChart';
import HistoricoMedalhas from '@/Pages/EmpresaAnaliseEcf/components/HistoricoMedalhas';
import AlertasDoSeller from '@/Pages/EmpresaAnaliseEcf/components/AlertasDoSeller';
import KpiCard from '@/Pages/PainelExecutivo/components/KpiCard';
import { PROGRAMA_LABELS, CLUSTER_LABELS } from '@/lib/ecfDriveLabels';

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

const ML_PERIODOS = [
    { label: 'Ontem',   days: 1  },
    { label: '7 dias',  days: 7  },
    { label: '30 dias', days: 30 },
    { label: '90 dias', days: 90 },
];

function localDateStr(d) {
    const y = d.getFullYear(), m = String(d.getMonth() + 1).padStart(2, '0'), day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

function mlKpis(metrics, days) {
    const cutoff = new Date();
    cutoff.setDate(cutoff.getDate() - days);
    const cutoffStr = localDateStr(cutoff);
    const slice = metrics.filter(m => m.date >= cutoffStr);
    const revenue = slice.reduce((s, m) => s + m.revenue, 0);
    const adSpend = slice.reduce((s, m) => s + m.ad_spend, 0);
    const tacos   = revenue > 0 ? adSpend / revenue * 100 : null;
    return { revenue, adSpend, tacos, count: slice.length };
}

// ─── Card de integração ML OAuth ────────────────────────────────────────────
function MlConnectionCard({ company }) {
    const token   = company.ml_token;
    const metrics = company.ml_metrics ?? [];

    const [linkDialogOpen, setLinkDialogOpen] = useState(false);
    const [authUrl, setAuthUrl]               = useState('');
    const [loadingLink, setLoadingLink]       = useState(false);
    const [copied, setCopied]                 = useState(false);
    const [period, setPeriod]                 = useState(30);
    const [syncing, setSyncing]               = useState(false);
    const [syncResult, setSyncResult]         = useState(null);

    const kpis = useMemo(() => mlKpis(metrics, period), [metrics, period]);

    const forceSyncD1 = async () => {
        setSyncing(true); setSyncResult(null);
        const yesterday = new Date(); yesterday.setDate(yesterday.getDate() - 1);
        const date = yesterday.toISOString().slice(0, 10);
        try {
            const res = await fetch(route('ml.sync.now', company.id) + '?date=' + date, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '', 'Accept': 'application/json' },
            });
            const data = await res.json();
            setSyncResult(data.error ? { error: data.error } : { ok: true, date });
        } catch { setSyncResult({ error: 'Erro de conexão.' }); }
        finally { setSyncing(false); }
    };

    const statusCfg = {
        active:  { label: 'Conectado',    className: 'bg-emerald-500/15 text-emerald-400 border-emerald-500/25' },
        expired: { label: 'Expirado',     className: 'bg-orange-500/15 text-orange-400 border-orange-500/25' },
        revoked: { label: 'Revogado',     className: 'bg-red-500/15 text-red-400 border-red-500/25' },
    };

    const gerarLink = async () => {
        setLoadingLink(true);
        try {
            const res = await fetch(route('ml.oauth.initiate', company.id), {
                method:  'POST',
                headers: {
                    'X-CSRF-TOKEN':  document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'Accept':        'application/json',
                    'Content-Type':  'application/json',
                },
            });
            const data = await res.json();
            setAuthUrl(data.url);
            setLinkDialogOpen(true);
        } catch {
            alert('Erro ao gerar link. Tente novamente.');
        } finally {
            setLoadingLink(false);
        }
    };

    const copiarLink = () => {
        navigator.clipboard.writeText(authUrl).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    };

    const desconectar = () => {
        if (! confirm(`Remover a conexão ML da empresa "${company.name}"?`)) return;
        router.delete(route('ml.oauth.disconnect', company.id));
    };

    return (
        <>
            <div className="card-ecf rounded-2xl overflow-hidden">
                <div className="flex items-center gap-2.5 px-5 py-4 border-b border-white/[0.06]">
                    <ShoppingCart size={15} className="text-ecf-yellow/70" />
                    <p className="text-white font-display font-bold text-[14px]">Integração Mercado Livre</p>
                    {token && (
                        <span className={cn(
                            'ml-auto inline-flex items-center px-2 py-0.5 rounded-full border text-[10px] font-semibold uppercase tracking-wide',
                            statusCfg[token.status]?.className,
                        )}>
                            {statusCfg[token.status]?.label ?? token.status}
                        </span>
                    )}
                </div>
                <div className="p-5 space-y-4">
                    {token ? (
                        <>
                            {/* Status amigável — a conexão (refresh token) não expira enquanto
                                renova automaticamente; o "expires_at" abaixo é só do access token. */}
                            {token.status === 'active' && (
                                <div className="rounded-lg bg-emerald-500/[0.07] border border-emerald-500/15 px-3 py-2.5 flex items-start gap-2">
                                    <CheckCircle2 size={14} className="text-emerald-400 mt-0.5 shrink-0" />
                                    <div>
                                        <p className="text-emerald-300 text-[12px] font-semibold">Conexão ativa</p>
                                        <p className="text-white/40 text-[11px] leading-snug">Renovação automática — permanece ativa sem reconexão manual.</p>
                                    </div>
                                </div>
                            )}
                            <div className="space-y-2">
                                <div className="flex items-start justify-between py-1.5 border-b border-white/[0.04]">
                                    <span className="text-white/40 text-[12px]">User ID ML</span>
                                    <span className="text-white/80 text-[13px] font-mono">{token.ml_user_id}</span>
                                </div>
                                <div className="flex items-start justify-between py-1.5 border-b border-white/[0.04]">
                                    <span className="text-white/40 text-[12px]">Conectado em</span>
                                    <span className="text-white/70 text-[13px]">{token.connected_at ? formatDateTime(token.connected_at) : '—'}</span>
                                </div>
                                <div className="flex items-start justify-between py-1.5 border-b border-white/[0.04]">
                                    <span className="text-white/40 text-[12px]">Última renovação</span>
                                    <span className="text-white/70 text-[13px]">{token.last_refreshed_at ? formatDateTime(token.last_refreshed_at) : '—'}</span>
                                </div>
                                {/* Access token expira em ~6h e renova sozinho — rotulado como
                                    "próxima renovação" para não passar ideia de que a conta cai. */}
                                <div className="flex items-start justify-between py-1.5">
                                    <span className="text-white/40 text-[12px]">Próxima renovação do token</span>
                                    <span className="text-white/70 text-[13px]">{token.expires_at ? formatDateTime(token.expires_at) : '—'}</span>
                                </div>
                            </div>
                            {/* KPIs ML com filtro de período */}
                            {metrics.length > 0 && (
                                <div className="pt-2 space-y-3 border-t border-white/[0.06]">
                                    <div className="flex items-center justify-between">
                                        <p className="text-white/40 text-[11px] uppercase tracking-wide">Métricas</p>
                                        <div className="flex gap-1">
                                            {ML_PERIODOS.map(p => (
                                                <button
                                                    key={p.days}
                                                    onClick={() => setPeriod(p.days)}
                                                    className={cn(
                                                        'px-2.5 py-0.5 rounded text-[10px] font-medium border transition-colors',
                                                        period === p.days
                                                            ? 'bg-ecf-yellow/15 border-ecf-yellow/30 text-ecf-yellow'
                                                            : 'border-white/[0.06] text-white/30 hover:text-white/60'
                                                    )}
                                                >
                                                    {p.label}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-3 gap-2">
                                        <div className="rounded-lg bg-white/[0.03] border border-white/[0.06] px-3 py-2.5">
                                            <p className="text-white/30 text-[10px] mb-1">Faturamento</p>
                                            <p className="text-blue-400 font-semibold text-[13px]">{formatCurrency(kpis.revenue)}</p>
                                        </div>
                                        <div className="rounded-lg bg-white/[0.03] border border-white/[0.06] px-3 py-2.5">
                                            <p className="text-white/30 text-[10px] mb-1">Ad Spend</p>
                                            <p className="text-white/60 font-semibold text-[13px]">{formatCurrency(kpis.adSpend)}</p>
                                        </div>
                                        <div className="rounded-lg bg-white/[0.03] border border-white/[0.06] px-3 py-2.5">
                                            <p className="text-white/30 text-[10px] mb-1">TACOS</p>
                                            <p className="text-ecf-yellow font-semibold text-[13px]">{kpis.tacos != null ? formatPercent(kpis.tacos) : '—'}</p>
                                        </div>
                                    </div>
                                    <p className="text-white/20 text-[10px]">{kpis.count} dia{kpis.count !== 1 ? 's' : ''} com dados</p>
                                </div>
                            )}

                            {/* Forçar resync D-1 — para quando o job automático falhou */}
                            <div className="pt-1 border-t border-white/[0.04]">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="w-full text-[11px] gap-1.5 text-white/30 hover:text-white/60"
                                    onClick={forceSyncD1}
                                    disabled={syncing || token?.status !== 'active'}
                                >
                                    <RefreshCw size={11} className={syncing ? 'animate-spin' : ''} />
                                    {syncing ? 'Sincronizando D-1…' : 'Forçar sync D-1'}
                                </Button>
                                {syncResult?.ok && (
                                    <p className="text-center text-[11px] text-emerald-400 mt-1">✓ {syncResult.date} sincronizado</p>
                                )}
                                {syncResult?.error && (
                                    <p className="text-center text-[11px] text-red-400 mt-1">{syncResult.error}</p>
                                )}
                            </div>

                            <div className="flex gap-2 pt-1">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="text-[12px] gap-1.5"
                                    onClick={gerarLink}
                                    disabled={loadingLink}
                                >
                                    <ShoppingCart size={13} />
                                    {loadingLink ? 'Gerando...' : 'Reconectar'}
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="text-[12px] gap-1.5 text-red-400 hover:text-red-300 hover:bg-red-500/10"
                                    onClick={desconectar}
                                >
                                    <Unplug size={13} />
                                    Desconectar
                                </Button>
                            </div>
                        </>
                    ) : (
                        <div className="text-center py-4 space-y-3">
                            <p className="text-white/30 text-[13px]">Nenhuma conta ML vinculada</p>
                            <Button
                                type="button"
                                size="sm"
                                className="gap-1.5 text-[12px]"
                                onClick={gerarLink}
                                disabled={loadingLink}
                            >
                                <ShoppingCart size={13} />
                                {loadingLink ? 'Gerando link...' : 'Gerar link de conexão'}
                            </Button>
                        </div>
                    )}
                </div>
            </div>

            {/* Modal do link OAuth */}
            <Dialog open={linkDialogOpen} onOpenChange={setLinkDialogOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Link de autorização — Mercado Livre</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4">
                        <p className="text-white/55 text-[13px]">
                            Envie este link ao cliente. Após a autorização, a conta será vinculada automaticamente à empresa <strong className="text-white/80">{company.name}</strong>.
                            O link expira em <strong className="text-ecf-yellow">7 dias</strong>.
                        </p>
                        <p className="text-white/35 text-[12px] leading-relaxed flex items-start gap-1.5">
                            <Info size={13} className="text-white/25 mt-0.5 shrink-0" />
                            Ao abrir o link, o cliente é levado à tela oficial de permissões do Mercado Livre. Se ele já autorizou o app antes, o ML conecta direto sem exibir a tela novamente — isso é normal.
                        </p>
                        <div className="flex gap-2">
                            <input
                                readOnly
                                value={authUrl}
                                className="flex-1 rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[12px] text-white/70 font-mono truncate focus:outline-none"
                            />
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="shrink-0 gap-1.5 text-[12px]"
                                onClick={copiarLink}
                            >
                                {copied ? <Check size={13} className="text-emerald-400" /> : <Copy size={13} />}
                                {copied ? 'Copiado!' : 'Copiar'}
                            </Button>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setLinkDialogOpen(false)}>
                            Fechar
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

// ─── Phase 25 Plano 04: helpers locais para o bloco ECF Drive ────────────────
// Valores da API ECF Drive vêm como STRING (ex: "2733708.08") — parseFloat
// defensivo. Retorna null quando inválido para KpiCard mostrar "—".
const ecfNumOuNull = (v) => {
    if (v === null || v === undefined || v === '') return null;
    const n = parseFloat(v);
    return isNaN(n) ? null : n;
};

export default function CompanyShow({ company, servicos_disponiveis = [], ecf_drive = null }) {
    const consultor = company.consultor?.[0];
    const estrategista = company.estrategista?.[0];
    const latestMetric = company.adman_metrics?.[0];
    // ML-driven: empresa com token Mercado Livre ATIVO — o backend serve os
    // KPIs financeiros 30d (revenue/acos/tacos/margin) pelo caminho ML mesmo
    // que ainda exista adman_account_id (cutover Adman → ML, Opção A).
    const isMlDriven = company.ml_token?.status === 'active';

    // Cust ID canônico = Seller ID (ml_user_id) do token OAuth; cai para
    // ml_store_id quando ainda não há token. Identificador único da loja ML.
    const custId = company.ml_token?.ml_user_id ?? company.ml_store_id;

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

                {/* KPIs financeiros — mesma grade para Adman e ML (ML-only é
                    agregado no backend a partir da API do Mercado Livre). */}
                <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                    {[
                        { label: 'Faturamento (30d)', value: company.revenue_30d ? formatCurrency(company.revenue_30d) : '—', color: 'text-blue-400' },
                        { label: 'ACOS (30d)', value: (company.acos_30d ?? null) !== null ? formatPercent(company.acos_30d) : '—', color: 'text-orange-400' },
                        { label: 'TACOS (30d)', value: (company.tacos_30d ?? null) !== null ? formatPercent(company.tacos_30d) : '—', color: 'text-ecf-yellow' },
                        // Margem % depende de CMV/impostos, que a API ML não fornece —
                        // p/ empresa ML-only mostra "—" com aviso explicativo.
                        {
                            label: 'Margem % (30d)',
                            value: (company.margin_pct_30d ?? null) !== null ? formatPercent(company.margin_pct_30d) : '—',
                            color: 'text-emerald-400',
                            hint: (company.margin_pct_30d ?? null) === null && isMlDriven
                                ? 'Requer CMV — indisponível na API do Mercado Livre'
                                : null,
                        },
                    ].map(k => (
                        <div key={k.label} className="card-ecf rounded-2xl p-4" title={k.hint || undefined}>
                            <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wide flex items-center gap-1">
                                {k.label}
                                {k.hint && <Info size={11} className="text-white/30 shrink-0" />}
                            </p>
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

                {/* ─── Phase 25 Plano 04 — Análise ECF Drive (2026-06-05) ───────
                    Bloco com dados oficiais do parceiro ECF Drive (vem direto do
                    Mercado Livre). Os KPIs de Faturamento e Invest. Ads acima já
                    foram SUBSTITUÍDOS pelo backend quando ECF Drive tem cobertura
                    — esta seção adiciona o que a Adman não tem: vendas, visitas,
                    scores, medalha, gráfico 12 meses, histórico de medalhas e
                    alertas estratégicos específicos do seller. */}
                {ecf_drive?.seller && (() => {
                    const seller = ecf_drive.seller;
                    const metricaAtual = seller.metricaMensalAtual ?? null;
                    const medalha = seller.medalhaAtual ?? null;
                    const nivelMedalha = medalha?.nivelSolucion ?? metricaAtual?.nivelSolucion ?? null;
                    const programaKey = medalha?.programa ?? metricaAtual?.programa ?? seller.programa ?? null;
                    const programaLabel = programaKey ? (PROGRAMA_LABELS[programaKey] || programaKey) : null;
                    const clusterRaw = seller.cluster ?? metricaAtual?.cluster ?? null;
                    const clusterLabel = clusterRaw ? (CLUSTER_LABELS[clusterRaw] || clusterRaw) : null;
                    const segmento = seller.segmento ?? null;

                    return (
                        <Section icon={TrendingUp} title="Análise ECF Drive (dados oficiais do Mercado Livre)">
                            {/* Sub-header: badge de medalha + programa + cluster + segmento */}
                            <div className="flex flex-wrap items-center gap-2 mb-4">
                                {nivelMedalha && (
                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-semibold border bg-cyan-500/15 text-cyan-300 border-cyan-500/30">
                                        <Award size={12} />
                                        Medalha: {nivelMedalha}
                                    </span>
                                )}
                                {programaLabel && (
                                    <span className="text-xs text-white/60">
                                        Programa: <span className="text-white/90">{programaLabel}</span>
                                    </span>
                                )}
                                {clusterLabel && (
                                    <span className="text-xs text-white/60">
                                        Perfil: <span className="text-white/90">{clusterLabel}</span>
                                    </span>
                                )}
                                {segmento && (
                                    <span className="text-xs text-white/60">
                                        Segmento: <span className="text-white/90">{segmento}</span>
                                    </span>
                                )}
                            </div>

                            {/* KPI cards exclusivos do ECF Drive — Adman não tem esses */}
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                                <KpiCard
                                    label="Vendas (unidades)"
                                    valor={ecfNumOuNull(metricaAtual?.tsi)}
                                    deltaPct={null}
                                    isCount
                                    helpText="Total de itens vendidos no mês corrente. Fonte: parceiro ECF Drive (dados oficiais ML)."
                                />
                                <KpiCard
                                    label="Visitas"
                                    valor={ecfNumOuNull(metricaAtual?.visitas)}
                                    deltaPct={null}
                                    isCount
                                    helpText="Total de visitas nos anúncios do lojista no mês. Fonte: parceiro ECF Drive."
                                />
                                <KpiCard
                                    label="Score de qualidade"
                                    valor={ecfNumOuNull(metricaAtual?.scoreFinalFull)}
                                    deltaPct={null}
                                    isCount
                                    helpText="Score geral de qualidade do lojista, de 0 a 100. Quanto maior melhor a saúde da loja. Fonte: parceiro ECF Drive."
                                />
                                <KpiCard
                                    label="Score Ads"
                                    valor={ecfNumOuNull(metricaAtual?.scoreFinalPads)}
                                    deltaPct={null}
                                    isCount
                                    helpText="Score de qualidade das campanhas de anúncios, de 0 a 100. Fonte: parceiro ECF Drive."
                                />
                            </div>

                            {/* Gráfico evolução 12 meses */}
                            {(ecf_drive.metricas?.length ?? 0) > 0 && (
                                <div className="mb-4">
                                    <h4 className="text-xs font-semibold text-white/60 uppercase tracking-wide mb-2">
                                        Evolução dos últimos 12 meses
                                    </h4>
                                    <EvolucaoEmpresaChart data={ecf_drive.metricas} />
                                </div>
                            )}

                            {/* Histórico de medalhas */}
                            {(ecf_drive.medalhas?.length ?? 0) > 0 && (
                                <div className="mb-4">
                                    <h4 className="text-xs font-semibold text-white/60 uppercase tracking-wide mb-2">
                                        Histórico de medalhas
                                    </h4>
                                    <HistoricoMedalhas medalhas={ecf_drive.medalhas} />
                                </div>
                            )}

                            {/* Alertas estratégicos do seller */}
                            {(ecf_drive.signals?.length ?? 0) > 0 && (
                                <div>
                                    <h4 className="text-xs font-semibold text-white/60 uppercase tracking-wide mb-2">
                                        Alertas estratégicos
                                    </h4>
                                    <AlertasDoSeller signals={ecf_drive.signals} />
                                </div>
                            )}
                        </Section>
                    );
                })()}

                <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                    {/* Dados cadastrais */}
                    <Section icon={Building2} title="Dados da Empresa">
                        <InfoRow label="Analista" value={consultor?.name} />
                        <InfoRow label="Estrategista" value={estrategista?.name} />
                        {/* Identificador único da conta (Cust ID = Seller ID ML).
                            Campos Adman legados só aparecem quando preenchidos. */}
                        <InfoRow label="Cust ID (Seller ID ML)" value={custId} />
                        {company.adman_account_id && <InfoRow label="ID Conta Adman" value={company.adman_account_id} />}
                        {company.adman_store_id && <InfoRow label="ID Adman Store" value={company.adman_store_id} />}
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

                {/* Integração Mercado Livre */}
                <MlConnectionCard company={company} />

                {/* Métricas Adman recentes */}
                {company.adman_account_id && (company.adman_metrics || []).length > 0 && (
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
