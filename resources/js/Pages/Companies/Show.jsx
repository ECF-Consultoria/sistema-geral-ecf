import AppLayout from '@/Layouts/AppLayout';
import { Link, router, useForm } from '@inertiajs/react';
import { Badge } from '@/Components/ui/badge';
import { SourceBadge } from '@/Components/ui/source-badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import {
    ArrowLeft, Building2, Star, Target, Briefcase, Plus, Pencil, PowerOff,
    ShoppingCart, Copy, Check, Unplug, RefreshCw, Info, CheckCircle2,
    Users, UserPlus, UserMinus, History, Medal, DollarSign, Percent,
    AlertTriangle, FileText, CalendarDays,
} from 'lucide-react';
import { formatCurrency, formatPercent, formatDate, formatDateTime, cn } from '@/lib/utils';
import { useState, useMemo } from 'react';
import HistoricoMedalhas from '@/Pages/EmpresaAnaliseEcf/components/HistoricoMedalhas';
import GoalProgressPanel from '@/Components/goals/GoalProgressPanel';

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

// ─── Section com brilho no topo (identidade dashboard ML) ───────────────────
function Section({ icon: Icon, title, children, action, glow }) {
    return (
        <div className="group relative card-ecf rounded-2xl overflow-hidden">
            <div
                className="pointer-events-none absolute -top-16 -right-10 h-40 w-40 rounded-full blur-3xl opacity-[0.10] group-hover:opacity-20 transition-opacity duration-500"
                style={{ background: glow ?? 'radial-gradient(circle, rgba(255,230,0,0.4), transparent 70%)' }}
            />
            <div className="relative z-10 flex items-center gap-2.5 px-5 py-4 border-b border-white/[0.06]">
                <Icon size={15} className="text-ecf-yellow/70" />
                <p className="text-white font-display font-bold text-[14px]">{title}</p>
                {action && <div className="ml-auto">{action}</div>}
            </div>
            <div className="relative z-10 p-5">{children}</div>
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

// ─── KPI com brilho ─────────────────────────────────────────────────────────
function KpiGlow({ icon: Icon, label, value, sub, valueClass = 'text-white', glow }) {
    return (
        <div className="group relative overflow-hidden rounded-2xl border border-white/[0.08] bg-gradient-to-b from-white/[0.04] to-white/[0.01] p-5">
            <div
                className="pointer-events-none absolute -top-14 left-1/2 -translate-x-1/2 h-28 w-[78%] rounded-full blur-2xl opacity-[0.18] group-hover:opacity-40 transition-opacity duration-300"
                style={{ background: glow }}
            />
            <div className="relative z-10 min-w-0">
                <div className="flex items-center gap-2 text-white/40 text-[10.5px] font-bold uppercase tracking-wide">
                    {Icon && <Icon size={13} />}{label}
                </div>
                <p className={cn('font-display font-extrabold text-2xl tracking-tight mt-2 tabular-nums truncate', valueClass)}>{value}</p>
                {sub && <p className="text-white/30 text-[11px] mt-1">{sub}</p>}
            </div>
        </div>
    );
}

// ─── Card de responsável (analista/estrategista) ────────────────────────────
function ResponsavelCard({ papel, pessoa, cor }) {
    return (
        <div className="rounded-xl border border-white/[0.07] bg-white/[0.02] p-4">
            <p className="text-white/35 text-[10px] font-semibold uppercase tracking-wide">{papel}</p>
            {pessoa ? (
                <>
                    <p className={cn('font-display font-bold text-[15px] mt-1', cor)}>{pessoa.name}</p>
                    <p className="text-white/30 text-[11px] mt-0.5">
                        {pessoa.desde ? `Desde ${formatDate(pessoa.desde)}` : 'Sem data de início'}
                    </p>
                </>
            ) : (
                <p className="text-white/25 text-[13px] mt-1 italic">Sem responsável</p>
            )}
        </div>
    );
}

// ─── Card de integração ML OAuth (preservado) ───────────────────────────────
function MlConnectionCard({ company, permissions = {} }) {
    const token   = company.ml_token;
    const canInitiate = permissions.can_initiate_ml_oauth ?? true;
    const canDisconnect = !!permissions.can_disconnect_ml_oauth;
    const canSync = !!permissions.can_sync_ml;

    const [linkDialogOpen, setLinkDialogOpen] = useState(false);
    const [authUrl, setAuthUrl]               = useState('');
    const [loadingLink, setLoadingLink]       = useState(false);
    const [copied, setCopied]                 = useState(false);
    const [syncing, setSyncing]               = useState(false);
    const [syncResult, setSyncResult]         = useState(null);

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
            <Section
                icon={ShoppingCart}
                title="Integração Mercado Livre"
                glow="radial-gradient(circle, rgba(255,230,0,0.4), transparent 70%)"
                action={token && (
                    <span className={cn(
                        'inline-flex items-center px-2 py-0.5 rounded-full border text-[10px] font-semibold uppercase tracking-wide',
                        statusCfg[token.status]?.className,
                    )}>
                        {statusCfg[token.status]?.label ?? token.status}
                    </span>
                )}
            >
                <div className="space-y-4">
                    {token ? (
                        <>
                            {token.status === 'active' && (
                                <div className="rounded-lg bg-emerald-500/[0.07] border border-emerald-500/15 px-3 py-2.5 flex items-start gap-2">
                                    <CheckCircle2 size={14} className="text-emerald-400 mt-0.5 shrink-0" />
                                    <div>
                                        <p className="text-emerald-300 text-[12px] font-semibold">Conexão ativa</p>
                                        <p className="text-white/40 text-[11px] leading-snug">Renovação automática — permanece ativa sem reconexão manual.</p>
                                    </div>
                                </div>
                            )}
                            <div className="space-y-1">
                                <InfoRow label="User ID ML" value={<span className="font-mono">{token.ml_user_id}</span>} />
                                <InfoRow label="Conectado em" value={token.connected_at ? formatDateTime(token.connected_at) : '—'} />
                                <InfoRow label="Última renovação" value={token.last_refreshed_at ? formatDateTime(token.last_refreshed_at) : '—'} />
                                <InfoRow label="Próxima renovação do token" value={token.expires_at ? formatDateTime(token.expires_at) : '—'} />
                            </div>

                            {canSync && (
                                <div className="pt-1 border-t border-white/[0.04]">
                                    <Button
                                        type="button" variant="ghost" size="sm"
                                        className="w-full text-[11px] gap-1.5 text-white/30 hover:text-white/60"
                                        onClick={forceSyncD1}
                                        disabled={syncing || token?.status !== 'active'}
                                    >
                                        <RefreshCw size={11} className={syncing ? 'animate-spin' : ''} />
                                        {syncing ? 'Sincronizando D-1…' : 'Forçar sync D-1'}
                                    </Button>
                                    {syncResult?.ok && <p className="text-center text-[11px] text-emerald-400 mt-1">✓ {syncResult.date} sincronizado</p>}
                                    {syncResult?.error && <p className="text-center text-[11px] text-red-400 mt-1">{syncResult.error}</p>}
                                </div>
                            )}

                            <div className="flex gap-2 pt-1">
                                <Button type="button" variant="outline" size="sm" className="text-[12px] gap-1.5" onClick={gerarLink} disabled={loadingLink || !canInitiate}>
                                    <ShoppingCart size={13} />
                                    {loadingLink ? 'Gerando...' : 'Reconectar'}
                                </Button>
                                {canDisconnect && (
                                    <Button type="button" variant="ghost" size="sm" className="text-[12px] gap-1.5 text-red-400 hover:text-red-300 hover:bg-red-500/10" onClick={desconectar}>
                                        <Unplug size={13} /> Desconectar
                                    </Button>
                                )}
                            </div>
                        </>
                    ) : (
                        <div className="text-center py-4 space-y-3">
                            <p className="text-white/30 text-[13px]">Nenhuma conta ML vinculada</p>
                            <Button type="button" size="sm" className="gap-1.5 text-[12px]" onClick={gerarLink} disabled={loadingLink || !canInitiate}>
                                <ShoppingCart size={13} />
                                {loadingLink ? 'Gerando link...' : 'Gerar link de conexão'}
                            </Button>
                        </div>
                    )}
                </div>
            </Section>

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
                            <input readOnly value={authUrl} className="flex-1 rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[12px] text-white/70 font-mono truncate focus:outline-none" />
                            <Button type="button" size="sm" variant="outline" className="shrink-0 gap-1.5 text-[12px]" onClick={copiarLink}>
                                {copied ? <Check size={13} className="text-emerald-400" /> : <Copy size={13} />}
                                {copied ? 'Copiado!' : 'Copiar'}
                            </Button>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="ghost" size="sm" onClick={() => setLinkDialogOpen(false)}>Fechar</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

const ML_STATUS_HERO = {
    active:  { label: 'ML conectado', className: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/25' },
    expired: { label: 'ML expirado',  className: 'bg-orange-500/15 text-orange-300 border-orange-500/25' },
    revoked: { label: 'ML revogado',  className: 'bg-red-500/15 text-red-300 border-red-500/25' },
};

export default function CompanyShow({
    company,
    servicos_disponiveis = [],
    ecf_drive = null,
    goal_metrics = {},
    goal_percentage_only_metrics = [],
    permissions = {},
}) {
    const consultor = company.consultor?.[0];
    const estrategista = company.estrategista?.[0];
    const canManageContracts = !!permissions.can_manage_contracts;
    const canCreateGoals = !!permissions.can_create_goals;
    const isMlDriven = company.ml_token?.status === 'active';
    const custId = company.ml_token?.ml_user_id ?? company.ml_store_id ?? company.adman_account_id;

    const historico = company.historico_gestao || [];

    const completedNps = (company.nps_surveys || []).filter(s => s.status === 'completed' && s.response);
    // Fase 116 Plan 07 (tarefa adicional) — a média já vem PRONTA do backend
    // em `company.nps_avg` (conta NPS enviado e não respondido como nota 1,
    // Plan 116-05). Antes esta página recalculava no navegador só com as
    // respostas reais de `nps_surveys` — número diferente de todas as outras
    // telas da fase. `nps_avg` é null quando a empresa nunca teve NENHUM NPS
    // disparado (nem resposta, nem não respondido).
    const avgNps = company.nps_avg !== null && company.nps_avg !== undefined
        ? Number(company.nps_avg).toFixed(1)
        : null;
    const npsRespondidos    = company.nps_respondidos ?? completedNps.length;
    const npsNaoRespondidos = company.nps_nao_respondidos ?? 0;

    // ─── Contratos de serviço ──────────────────────────────────────────────
    const todosContratos = company.contratos_servico || [];
    const [showInativos, setShowInativos] = useState(false);
    const [contratoOpen, setContratoOpen] = useState(false);
    const [editingContrato, setEditingContrato] = useState(null);

    const contratosVisiveis = useMemo(
        () => showInativos ? todosContratos : todosContratos.filter(c => c.ativo),
        [todosContratos, showInativos],
    );

    const contratoForm = useForm({
        servico_id: '', valor_contratado: '',
        data_contratacao: new Date().toISOString().slice(0, 10),
        data_vencimento: '', observacoes: '', ativo: true,
    });
    const [goalOpen, setGoalOpen] = useState(false);
    const goalMetricOptions = Object.entries(goal_metrics || {});
    const goalForm = useForm({
        company_id: company.id,
        metric: goalMetricOptions[0]?.[0] ?? '',
        target_value: '', value_type: 'currency', period_type: 'monthly', description: '',
    });

    const abrirNovaMeta = () => {
        if (!canCreateGoals) return;
        goalForm.setData({
            company_id: company.id, metric: goalMetricOptions[0]?.[0] ?? '',
            target_value: '', value_type: 'currency', period_type: 'monthly', description: '',
        });
        goalForm.clearErrors();
        setGoalOpen(true);
    };

    const submitGoal = (e) => {
        e.preventDefault();
        if (!canCreateGoals) return;
        goalForm.post(route('goals.store'), {
            preserveScroll: true,
            onSuccess: () => { setGoalOpen(false); goalForm.reset(); },
        });
    };

    const abrirNovoContrato = () => {
        if (!canManageContracts) return;
        setEditingContrato(null);
        contratoForm.setData({
            servico_id: '', valor_contratado: '',
            data_contratacao: new Date().toISOString().slice(0, 10),
            data_vencimento: '', observacoes: '', ativo: true,
        });
        contratoForm.clearErrors();
        setContratoOpen(true);
    };

    const abrirEditarContrato = (ct) => {
        if (!canManageContracts) return;
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

    const escolherServico = (id) => {
        const svc = servicos_disponiveis.find(s => String(s.id) === String(id));
        contratoForm.setData(prev => ({ ...prev, servico_id: id, valor_contratado: svc ? svc.valor_padrao : prev.valor_contratado }));
    };

    const submitContrato = (e) => {
        e.preventDefault();
        if (!canManageContracts) return;
        const onSuccess = () => { setContratoOpen(false); contratoForm.reset(); };
        if (editingContrato) {
            contratoForm.put(route('empresas.contratos.update', [company.id, editingContrato.id]), { onSuccess, preserveScroll: true });
        } else {
            contratoForm.post(route('empresas.contratos.store', company.id), { onSuccess, preserveScroll: true });
        }
    };

    const desativarContrato = (ct) => {
        if (!canManageContracts) return;
        if (confirm(`Desativar este contrato (${ct.servico?.nome ?? 'serviço'})?`)) {
            router.delete(route('empresas.contratos.destroy', [company.id, ct.id]), { preserveScroll: true });
        }
    };

    return (
        <AppLayout title={company.name}>
            <div className="space-y-5 max-w-[1100px] mx-auto">
                {/* Breadcrumb */}
                <button onClick={() => window.history.back()} className="inline-flex items-center gap-1.5 text-white/40 hover:text-white/70 text-sm transition-colors">
                    <ArrowLeft size={14} /> Empresas
                </button>

                {/* ─── 1. HERO / Identidade ─────────────────────────────────── */}
                <div className="group relative overflow-hidden rounded-2xl border border-white/[0.08] bg-gradient-to-b from-white/[0.05] to-white/[0.01] p-6">
                    <div className="pointer-events-none absolute -top-20 -right-16 h-56 w-56 rounded-full blur-3xl opacity-[0.14] group-hover:opacity-24 transition-opacity duration-500"
                        style={{ background: 'radial-gradient(circle, rgba(255,230,0,0.5), transparent 70%)' }} />
                    <div className="relative z-10 flex items-start justify-between gap-4 flex-wrap">
                        <div className="flex items-start gap-4 min-w-0">
                            <div className="h-14 w-14 rounded-2xl bg-ecf-yellow/10 border border-ecf-yellow/25 flex items-center justify-center shrink-0">
                                <Building2 className="h-7 w-7 text-ecf-yellow" />
                            </div>
                            <div className="min-w-0">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <h1 className="text-white text-2xl font-display font-extrabold tracking-tight truncate">{company.name}</h1>
                                    <Badge variant={company.active ? 'success' : 'destructive'}>{company.active ? 'Ativa' : 'Inativa'}</Badge>
                                    {company.source && company.source !== 'none' && <SourceBadge variant={company.source} />}
                                    {company.ml_token && ML_STATUS_HERO[company.ml_token.status] && (
                                        <span className={cn('inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-[10px] font-semibold uppercase tracking-wide', ML_STATUS_HERO[company.ml_token.status].className)}>
                                            <ShoppingCart size={10} /> {ML_STATUS_HERO[company.ml_token.status].label}
                                        </span>
                                    )}
                                </div>
                                <div className="flex items-center gap-x-2.5 gap-y-1 flex-wrap text-white/45 text-[12.5px] mt-2">
                                    {company.cnpj && <span>{company.cnpj}</span>}
                                    {company.segment && <><span className="text-white/20">·</span><span>{company.segment}</span></>}
                                    {custId && <><span className="text-white/20">·</span>
                                        <span className="inline-flex items-center gap-1 font-mono text-ecf-yellow/80">Cust {custId}</span></>}
                                    {company.data_entrada && <><span className="text-white/20">·</span>
                                        <span className="inline-flex items-center gap-1"><CalendarDays size={12} className="text-white/30" /> Entrou em {formatDate(company.data_entrada)}</span></>}
                                </div>
                            </div>
                        </div>
                        <Link href={route('sugadores.config.show', company.id)}
                            className="inline-flex items-center gap-1.5 h-8 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/70 hover:text-white hover:bg-white/[0.05] text-[12px] font-medium shrink-0"
                            title="Configurar detecção de Sugadores">
                            <AlertTriangle size={13} /> Sugadores
                        </Link>
                    </div>
                </div>

                {/* ─── 2. Responsáveis + histórico de gerenciamento ─────────── */}
                <Section icon={Users} title="Responsáveis" glow="radial-gradient(circle, rgba(56,189,248,0.4), transparent 70%)">
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <ResponsavelCard papel="Analista" pessoa={consultor} cor="text-sky-300" />
                        <ResponsavelCard papel="Estrategista" pessoa={estrategista} cor="text-purple-300" />
                    </div>

                    <div className="mt-5 pt-4 border-t border-white/[0.06]">
                        <div className="flex items-center gap-2 mb-3 text-white/40 text-[11px] font-semibold uppercase tracking-wide">
                            <History size={13} /> Histórico de gerenciamento
                        </div>
                        {historico.length === 0 ? (
                            <p className="text-white/25 text-[12.5px] italic">
                                Nenhuma troca registrada ainda — o histórico passa a ser registrado a cada mudança de responsável.
                            </p>
                        ) : (
                            <div className="space-y-1.5">
                                {historico.map(h => {
                                    const entrada = h.evento === 'entrada';
                                    const Icon = entrada ? UserPlus : UserMinus;
                                    return (
                                        <div key={h.id} className="flex items-center gap-2.5 py-1.5 text-[12.5px]">
                                            <span className={cn('h-6 w-6 rounded-md flex items-center justify-center shrink-0 border',
                                                entrada ? 'bg-emerald-500/10 border-emerald-500/25 text-emerald-300' : 'bg-rose-500/10 border-rose-500/25 text-rose-300')}>
                                                <Icon size={13} />
                                            </span>
                                            <span className="text-white/80">
                                                <strong className="font-semibold">{h.user}</strong>
                                                <span className="text-white/40"> {entrada ? 'entrou como' : 'saiu de'} {h.papel}</span>
                                            </span>
                                            <span className="ml-auto text-white/30 text-[11px] shrink-0">
                                                {h.data ? formatDate(h.data) : ''}{h.changed_by ? ` · por ${h.changed_by}` : ''}
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                </Section>

                {/* ─── 3. Financeiro (30 dias) ──────────────────────────────── */}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <KpiGlow
                        icon={DollarSign}
                        label="Faturamento (30 dias)"
                        value={company.revenue_30d ? formatCurrency(company.revenue_30d) : '—'}
                        sub={isMlDriven ? 'Fonte: API Mercado Livre' : 'Fonte: Adman'}
                        valueClass="text-blue-300"
                        glow="radial-gradient(circle, rgba(59,130,246,0.5), transparent 70%)"
                    />
                    <KpiGlow
                        icon={Percent}
                        label="Margem (30 dias)"
                        value={(company.margin_pct_30d ?? null) !== null ? formatPercent(company.margin_pct_30d) : '—'}
                        sub={(company.margin_pct_30d ?? null) !== null
                            ? (company.liquid_margin_30d ? `${formatCurrency(company.liquid_margin_30d)} de margem de contribuição` : 'Margem de contribuição %')
                            : (isMlDriven ? 'Requer CMV — indisponível na API do Mercado Livre' : 'Sem dado de margem no período')}
                        valueClass="text-emerald-300"
                        glow="radial-gradient(circle, rgba(52,211,153,0.5), transparent 70%)"
                    />
                </div>

                {/* ─── 4. NPS ───────────────────────────────────────────────── */}
                <Section icon={Star} title="NPS" glow="radial-gradient(circle, rgba(255,230,0,0.4), transparent 70%)"
                    action={avgNps !== null && (
                        <span className="inline-flex items-baseline gap-1 text-[12px] text-white/40">
                            média <strong className="text-ecf-yellow font-display text-base">{avgNps}</strong>/5
                        </span>
                    )}>
                    {/* Fase 116 Plan 07 (tarefa adicional) — mesma explicação da
                        área NPS: sem jargão, deixa claro que não responder tem
                        custo na média. Só aparece quando existe pelo menos 1
                        NPS que caiu na regra. */}
                    {npsNaoRespondidos > 0 && (
                        <div className="flex items-center gap-2 mb-3 px-3 py-2 rounded-lg bg-white/[0.03] border border-white/[0.06]">
                            <Info className="h-3.5 w-3.5 text-white/35 shrink-0" />
                            <p className="text-white/45 text-[11.5px]">
                                {npsRespondidos} respondida{npsRespondidos === 1 ? '' : 's'} · {npsNaoRespondidos} sem resposta (conta{npsNaoRespondidos === 1 ? '' : 'm'} 1) —
                                NPS enviado e não respondido conta nota 1 na média.
                            </p>
                        </div>
                    )}
                    {completedNps.length === 0 ? (
                        <p className="text-white/25 text-sm text-center py-6">Nenhuma avaliação respondida</p>
                    ) : (
                        <div className="space-y-1.5">
                            {completedNps.map(s => {
                                const score = s.response?.score_empresa;
                                const scoreColor = score >= 5 ? 'text-emerald-400' : score >= 4 ? 'text-ecf-yellow' : 'text-red-400';
                                return (
                                    <div key={s.id} className="flex items-center justify-between py-2 border-b border-white/[0.04] last:border-0 gap-4">
                                        <div className="min-w-0">
                                            <p className="text-white/70 text-[13px] truncate">{s.response?.respondent_name || '—'}</p>
                                            {s.response?.comment && <p className="text-white/30 text-[11px] truncate">{s.response.comment}</p>}
                                        </div>
                                        <span className={cn('font-bold font-display text-lg shrink-0', scoreColor)}>{score ?? '—'}</span>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </Section>

                {/* ─── 5. Metas Ativas ──────────────────────────────────────── */}
                <Section icon={Target} title="Metas ativas"
                    action={canCreateGoals && (
                        <Button size="sm" onClick={abrirNovaMeta}><Plus className="h-3.5 w-3.5 mr-1" /> Nova meta</Button>
                    )}>
                    {(company.goals || []).filter(g => g.active).length === 0 ? (
                        <p data-testid="company-goals-empty" className="text-white/25 text-sm text-center py-8">Nenhuma meta cadastrada</p>
                    ) : (
                        <div data-testid="company-goals-grid" className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                            {company.goals.filter(g => g.active).map(g => (
                                <GoalProgressPanel key={g.id} goal={g} results={g.results || []} compact />
                            ))}
                        </div>
                    )}
                </Section>

                {/* ─── 6. Serviços contratados ──────────────────────────────── */}
                <Section icon={Briefcase} title="Serviços contratados"
                    action={
                        <div className="flex items-center gap-3">
                            <label className="inline-flex items-center gap-1.5 text-[11px] text-white/50 cursor-pointer select-none">
                                <input type="checkbox" checked={showInativos} onChange={e => setShowInativos(e.target.checked)} className="h-3.5 w-3.5 rounded border-white/20 bg-white/5 accent-ecf-yellow" />
                                Mostrar inativos
                            </label>
                            {canManageContracts && (
                                <Button size="sm" onClick={abrirNovoContrato}><Plus className="h-3.5 w-3.5 mr-1" /> Adicionar</Button>
                            )}
                        </div>
                    }>
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
                                        <th className="text-right pb-2 font-semibold">Valor</th>
                                        <th className="text-left pb-2 pl-3 font-semibold">Tipo</th>
                                        <th className="text-left pb-2 pl-3 font-semibold">Início</th>
                                        <th className="text-left pb-2 pl-3 font-semibold">Vencimento</th>
                                        <th className="text-left pb-2 pl-3 font-semibold">Status</th>
                                        {canManageContracts && <th className="text-right pb-2 font-semibold">Ações</th>}
                                    </tr>
                                </thead>
                                <tbody>
                                    {contratosVisiveis.map(ct => (
                                        <tr key={ct.id} className={cn('border-b border-white/[0.03] last:border-0', !ct.ativo && 'opacity-50')}>
                                            <td className="py-2.5 text-white/85 font-medium">{ct.servico?.nome ?? '—'}</td>
                                            <td className="py-2.5 text-right text-white/80 tabular-nums">{formatCurrency(ct.valor_contratado)}</td>
                                            <td className="py-2.5 pl-3"><TipoBadge tipo={ct.servico?.tipo_cobranca} /></td>
                                            <td className="py-2.5 pl-3 text-white/60">{ct.data_contratacao ? formatDate(ct.data_contratacao) : '—'}</td>
                                            <td className="py-2.5 pl-3 text-white/60">{ct.data_vencimento ? formatDate(ct.data_vencimento) : <span className="text-white/30 italic">sem vencimento</span>}</td>
                                            <td className="py-2.5 pl-3">
                                                {ct.ativo
                                                    ? <span className="inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-[10px] font-semibold uppercase">Ativo</span>
                                                    : <span className="inline-flex items-center px-2 py-0.5 rounded-full bg-white/5 border border-white/10 text-white/40 text-[10px] font-semibold uppercase">Inativo</span>}
                                            </td>
                                            {canManageContracts && (
                                                <td className="py-2.5 text-right">
                                                    <div className="flex justify-end gap-1">
                                                        <Button size="icon" variant="ghost" onClick={() => abrirEditarContrato(ct)} title="Editar contrato" className="h-7 w-7"><Pencil className="h-3.5 w-3.5" /></Button>
                                                        {ct.ativo && (
                                                            <Button size="icon" variant="ghost" onClick={() => desativarContrato(ct)} title="Desativar contrato" className="h-7 w-7 text-orange-400 hover:text-orange-300 hover:bg-orange-500/10"><PowerOff className="h-3.5 w-3.5" /></Button>
                                                        )}
                                                    </div>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Section>

                {/* ─── 7. Mercado Livre: integração + medalhas ──────────────── */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">
                    <MlConnectionCard company={company} permissions={permissions} />
                    {ecf_drive?.medalhas?.length > 0 && (
                        <Section icon={Medal} title="Medalhas Mercado Livre" glow="radial-gradient(circle, rgba(255,230,0,0.45), transparent 70%)"
                            action={typeof ecf_drive.medalha_atual === 'string' && ecf_drive.medalha_atual && (
                                <span className="inline-flex items-center gap-1 text-[11px] text-ecf-yellow/80"><Medal size={12} /> {ecf_drive.medalha_atual}</span>
                            )}>
                            <HistoricoMedalhas medalhas={ecf_drive.medalhas} />
                        </Section>
                    )}
                </div>

                {/* ─── 8. Informações comerciais (fechamento / Close) ───────── */}
                <Section icon={FileText} title="Informações comerciais" glow="radial-gradient(circle, rgba(168,85,247,0.35), transparent 70%)">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8">
                        <div>
                            <InfoRow label="Nicho" value={company.nicho} />
                            <InfoRow label="Principal dor" value={company.dor} />
                            <InfoRow label="Vende no Mercado Livre" value={company.vende_ml === null || company.vende_ml === undefined ? null : (company.vende_ml ? 'Sim' : 'Não')} />
                            <InfoRow label="Faturamento declarado" value={company.faturamento_mensal != null ? formatCurrency(company.faturamento_mensal) : null} />
                        </div>
                        <div>
                            <InfoRow label="Marketplaces extras" value={(company.marketplaces_extras || []).length ? company.marketplaces_extras.join(', ') : null} />
                            <InfoRow label="E-mail do cliente" value={company.email_cliente} />
                            <InfoRow label="Telefone" value={company.telefone} />
                            <InfoRow label="E-mail do colaborador" value={company.email_colaborador} />
                        </div>
                    </div>
                    {company.notes && (
                        <div className="mt-4 pt-3 border-t border-white/[0.06]">
                            <p className="text-white/40 text-[11px] uppercase tracking-wide mb-1">Observações</p>
                            <p className="text-white/70 text-[13px] whitespace-pre-line leading-relaxed">{company.notes}</p>
                        </div>
                    )}
                    {/* SPIN (HubSpot) — sempre os 4 campos, '—' nos vazios */}
                    <div className="mt-4 pt-3 border-t border-white/[0.06] space-y-3">
                        <p className="text-white/40 text-[11px] uppercase tracking-wide">SPIN (HubSpot)</p>
                        {[
                            ['Situação atual do cliente', company.spin?.situacao],
                            ['Problema Principal Identificado', company.spin?.problema],
                            ['Implicação do Problema', company.spin?.implicacao],
                            ['Necessidade de Solução', company.spin?.necessidade],
                        ].map(([label, valor]) => (
                            <div key={label}>
                                <p className="text-white/35 text-[10px] uppercase tracking-wide mb-0.5">{label}</p>
                                <p className="text-white/70 text-[13px] whitespace-pre-line leading-relaxed">{valor || '—'}</p>
                            </div>
                        ))}
                    </div>
                </Section>

                {/* ─── Modal Criar meta ──────────────────────── */}
                <Dialog open={goalOpen} onOpenChange={setGoalOpen}>
                    <DialogContent className="max-w-md">
                        <DialogHeader><DialogTitle>Nova meta</DialogTitle></DialogHeader>
                        <form onSubmit={submitGoal} className="space-y-4">
                            <div className="space-y-1.5">
                                <Label>Métrica *</Label>
                                <select
                                    value={goalForm.data.metric}
                                    onChange={e => {
                                        const metric = e.target.value;
                                        goalForm.setData(prev => ({ ...prev, metric, value_type: goal_percentage_only_metrics.includes(metric) ? 'percentage' : prev.value_type }));
                                    }}
                                    required
                                    className="w-full rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[13px] text-white focus:border-ecf-yellow/40 focus:outline-none"
                                >
                                    {goalMetricOptions.map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                                </select>
                                {goalForm.errors.metric && <p className="text-destructive text-xs">{goalForm.errors.metric}</p>}
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1.5">
                                    <Label>Valor alvo *</Label>
                                    <Input type="number" step="0.01" min="0" value={goalForm.data.target_value} onChange={e => goalForm.setData('target_value', e.target.value)} required />
                                    {goalForm.errors.target_value && <p className="text-destructive text-xs">{goalForm.errors.target_value}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Tipo</Label>
                                    <select value={goalForm.data.value_type} onChange={e => goalForm.setData('value_type', e.target.value)}
                                        disabled={goal_percentage_only_metrics.includes(goalForm.data.metric)}
                                        className="w-full rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[13px] text-white focus:border-ecf-yellow/40 focus:outline-none disabled:opacity-50">
                                        <option value="currency">R$</option>
                                        <option value="percentage">%</option>
                                    </select>
                                </div>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Período</Label>
                                <select value={goalForm.data.period_type} onChange={e => goalForm.setData('period_type', e.target.value)}
                                    className="w-full rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[13px] text-white focus:border-ecf-yellow/40 focus:outline-none">
                                    <option value="monthly">Mensal</option>
                                    <option value="quarterly">Trimestral</option>
                                    <option value="yearly">Anual</option>
                                </select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Descrição</Label>
                                <Textarea rows={2} value={goalForm.data.description} onChange={e => goalForm.setData('description', e.target.value)} placeholder="Opcional" />
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setGoalOpen(false)}>Cancelar</Button>
                                <Button type="submit" disabled={goalForm.processing || !canCreateGoals}>{goalForm.processing ? 'Salvando...' : 'Criar meta'}</Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                {/* ─── Modal Adicionar/Editar contrato ──────────────────────── */}
                {canManageContracts && (
                    <Dialog open={contratoOpen} onOpenChange={setContratoOpen}>
                        <DialogContent className="max-w-md">
                            <DialogHeader><DialogTitle>{editingContrato ? 'Editar contrato' : 'Adicionar contrato'}</DialogTitle></DialogHeader>
                            <form onSubmit={submitContrato} className="space-y-4">
                                <div className="space-y-1.5">
                                    <Label>Serviço *</Label>
                                    {editingContrato ? (
                                        <Input value={editingContrato.servico?.nome ?? '—'} disabled />
                                    ) : (
                                        <select value={contratoForm.data.servico_id} onChange={e => escolherServico(e.target.value)} required
                                            className="w-full rounded-md border border-white/10 bg-white/[0.03] px-3 py-2 text-[13px] text-white focus:border-ecf-yellow/40 focus:outline-none">
                                            <option value="">Selecionar...</option>
                                            {servicos_disponiveis.map(s => (
                                                <option key={s.id} value={s.id}>{s.nome} — {TIPO_COBRANCA[s.tipo_cobranca]?.label || s.tipo_cobranca} · {formatCurrency(s.valor_padrao)}</option>
                                            ))}
                                        </select>
                                    )}
                                    {contratoForm.errors.servico_id && <p className="text-destructive text-xs">{contratoForm.errors.servico_id}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Valor contratado (R$) *</Label>
                                    <Input type="number" step="0.01" min="0" value={contratoForm.data.valor_contratado} onChange={e => contratoForm.setData('valor_contratado', e.target.value)} required placeholder="0,00" />
                                    <p className="text-white/30 text-[11px]">Pré-preenchido com o valor padrão do serviço. Pode ser editado.</p>
                                    {contratoForm.errors.valor_contratado && <p className="text-destructive text-xs">{contratoForm.errors.valor_contratado}</p>}
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="space-y-1.5">
                                        <Label>Data de contratação *</Label>
                                        <Input type="date" value={contratoForm.data.data_contratacao} onChange={e => contratoForm.setData('data_contratacao', e.target.value)} required />
                                        {contratoForm.errors.data_contratacao && <p className="text-destructive text-xs">{contratoForm.errors.data_contratacao}</p>}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Data de vencimento</Label>
                                        <Input type="date" value={contratoForm.data.data_vencimento || ''} onChange={e => contratoForm.setData('data_vencimento', e.target.value)} />
                                        <p className="text-white/30 text-[11px]">Em branco = vigente sem fim.</p>
                                    </div>
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Observações</Label>
                                    <Textarea rows={2} value={contratoForm.data.observacoes} onChange={e => contratoForm.setData('observacoes', e.target.value)} placeholder="Detalhes do contrato (opcional)" />
                                </div>
                                {editingContrato && (
                                    <div className="flex items-center gap-2 pt-1">
                                        <input type="checkbox" id="contrato-ativo" checked={!!contratoForm.data.ativo} onChange={e => contratoForm.setData('ativo', e.target.checked)} className="h-4 w-4 rounded border-white/20 bg-white/5 accent-ecf-yellow" />
                                        <Label htmlFor="contrato-ativo" className="cursor-pointer text-sm text-white/80">Contrato ativo</Label>
                                    </div>
                                )}
                                <DialogFooter>
                                    <Button type="button" variant="outline" onClick={() => setContratoOpen(false)}>Cancelar</Button>
                                    <Button type="submit" disabled={contratoForm.processing}>{contratoForm.processing ? 'Salvando...' : editingContrato ? 'Atualizar' : 'Adicionar'}</Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                )}
            </div>
        </AppLayout>
    );
}
