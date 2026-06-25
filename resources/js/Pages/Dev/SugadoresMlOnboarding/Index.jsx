import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { router, usePage } from '@inertiajs/react';
import { format } from 'date-fns';
import {
    Activity,
    AlertTriangle,
    Check,
    RefreshCw,
    Search,
    ToggleLeft,
    ToggleRight,
    Zap,
} from 'lucide-react';
import { useMemo, useState } from 'react';

// ─── Constantes / labels ────────────────────────────────────────────────────

const STATUS_LABELS = {
    adman_only:           'Adman apenas',
    ml_shadow:            'ML em shadow',
    ml_primary_candidate: 'Candidato a primary',
    ml_primary:           'ML primary',
};

const STATUS_BADGE = {
    adman_only:           'text-white/70 bg-white/[0.04] border-white/[0.08]',
    ml_shadow:            'text-blue-300 bg-blue-400/10 border-blue-400/20',
    ml_primary_candidate: 'text-amber-300 bg-amber-400/10 border-amber-400/20',
    ml_primary:           'text-emerald-300 bg-emerald-400/10 border-emerald-400/20',
};

const TOKEN_LABELS = {
    active:        'Token ativo',
    expired:       'Expirado',
    error_refresh: 'Erro refresh',
    missing:       'Ausente',
};

const TOKEN_BADGE = {
    active:        'text-emerald-300 bg-emerald-400/10 border-emerald-400/20',
    expired:       'text-amber-300 bg-amber-400/10 border-amber-400/20',
    error_refresh: 'text-red-300 bg-red-400/10 border-red-400/20',
    missing:       'text-white/40 bg-white/[0.04] border-white/[0.08]',
};

// Formata ISO → 'dd/MM HH:mm' (null-safe)
const fmtTs = (iso) => (iso ? format(new Date(iso), 'dd/MM HH:mm') : '—');

// Formata percentual (1 casa) ou —
const fmtPct = (n) => (typeof n === 'number' ? `${n.toFixed(1)}%` : '—');

// ─── DevCard local (mesmo pattern de Dev/Desenvolvimento.jsx) ───────────────

function DevCard({ icon: Icon, title, subtitle, children }) {
    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-5">
            <div className="flex items-start gap-3 mb-4">
                <div className="w-10 h-10 rounded-lg bg-ecf-yellow/[0.12] border border-ecf-yellow/20 flex items-center justify-center shrink-0">
                    <Icon size={18} className="text-ecf-yellow" />
                </div>
                <div className="flex-1 min-w-0">
                    <h3 className="text-white font-semibold text-[15px] leading-tight">{title}</h3>
                    {subtitle && <p className="text-white/40 text-[12px] mt-0.5">{subtitle}</p>}
                </div>
            </div>
            {children}
        </div>
    );
}

// ─── Sub-componentes visuais ────────────────────────────────────────────────

function TokenBadge({ state }) {
    const label = TOKEN_LABELS[state] ?? state;
    return (
        <span
            className={cn(
                'inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium border',
                TOKEN_BADGE[state] ?? 'text-white/40 bg-white/[0.04] border-white/[0.08]',
            )}
        >
            {label}
        </span>
    );
}

function StatusBadge({ status }) {
    const label = STATUS_LABELS[status] ?? status;
    return (
        <span
            className={cn(
                'inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium border',
                STATUS_BADGE[status] ?? 'text-white/40 bg-white/[0.04] border-white/[0.08]',
            )}
        >
            {label}
        </span>
    );
}

function ParityCell({ pct, status }) {
    if (pct === null || pct === undefined) {
        return <span className="text-white/40 text-[12px]">—</span>;
    }
    const cor =
        pct >= 95 ? 'text-emerald-300' :
        pct >= 80 ? 'text-amber-300'   :
        'text-red-300';
    return (
        <span className={cn('text-[12px] font-mono', cor)}>
            {fmtPct(pct)}
        </span>
    );
}

function KpiCard({ label, value, hint }) {
    return (
        <div className="rounded-lg border border-white/[0.08] bg-black/30 px-4 py-3">
            <p className="text-white/40 text-[11px] uppercase tracking-wider">{label}</p>
            <p className="text-white text-2xl font-display font-bold mt-0.5">{value}</p>
            {hint && <p className="text-white/40 text-[11px] mt-0.5">{hint}</p>}
        </div>
    );
}

// ─── Pagina principal ───────────────────────────────────────────────────────

export default function SugadoresMlOnboardingIndex({ companies = [] }) {
    const { auth } = usePage().props;
    void auth; // usado pelo AppLayout/sidebar; nao consumido diretamente aqui.

    // Filtros UI (client-side; a lista vem ja filtrada por active=true).
    const [busca,       setBusca]       = useState('');
    const [statusFilt,  setStatusFilt]  = useState('todos');
    const [tokenFilt,   setTokenFilt]   = useState('todos');

    // Quais botoes estao em flight (evita doble-click)
    const [emAcao, setEmAcao] = useState({}); // { [companyId]: 'smoke' | 'shadow' | 'toggle' }

    // ── Contadores (header) ─────────────────────────────────────────────
    const kpis = useMemo(() => {
        const total              = companies.length;
        const semToken           = companies.filter((c) => c.token_state === 'missing').length;
        const shadowAtivo        = companies.filter((c) => c.status === 'ml_shadow' || c.status === 'ml_primary_candidate').length;
        const candidatosPrimary  = companies.filter((c) => c.status === 'ml_primary_candidate').length;
        const mlPrimary          = companies.filter((c) => c.status === 'ml_primary').length;
        return { total, semToken, shadowAtivo, candidatosPrimary, mlPrimary };
    }, [companies]);

    // ── Linhas filtradas ────────────────────────────────────────────────
    const linhas = useMemo(() => {
        const q = busca.trim().toLowerCase();
        return companies.filter((c) => {
            if (statusFilt !== 'todos' && c.status !== statusFilt) return false;
            if (tokenFilt  !== 'todos' && c.token_state !== tokenFilt) return false;
            if (q && !String(c.name ?? '').toLowerCase().includes(q)) return false;
            return true;
        });
    }, [companies, busca, statusFilt, tokenFilt]);

    // ── Acoes inline (POST async) ───────────────────────────────────────
    function dispararAcao(routeKey, companyId, label) {
        setEmAcao((prev) => ({ ...prev, [companyId]: label }));
        router.post(
            route(routeKey, { company: companyId }),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setEmAcao((prev) => {
                        const next = { ...prev };
                        delete next[companyId];
                        return next;
                    });
                },
            },
        );
    }

    return (
        <AppLayout title="Sugadores ML — Onboarding">
            <div className="max-w-7xl mx-auto space-y-6">
                {/* Header / contexto */}
                <DevCard
                    icon={Activity}
                    title="Sugadores ML — Onboarding por empresa"
                    subtitle="Estado da migracao Adman → ML por empresa: token, advertiser, smoke, paridade shadow e acoes inline."
                >
                    <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
                        <KpiCard label="Total"            value={kpis.total} hint="empresas ativas" />
                        <KpiCard label="Sem token"        value={kpis.semToken} hint="precisam OAuth" />
                        <KpiCard label="Shadow ativo"     value={kpis.shadowAtivo} hint="rodando paralelo" />
                        <KpiCard label="Candidatos"       value={kpis.candidatosPrimary} hint="paridade ≥ 95%" />
                        <KpiCard label="ML primary"       value={kpis.mlPrimary} hint="cut-over feito" />
                    </div>
                </DevCard>

                {/* Filtros + busca */}
                <DevCard icon={Search} title="Filtros" subtitle="Refina a lista por status, estado do token ou nome.">
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label className="block text-white/40 text-[11px] uppercase tracking-wider mb-1">Status</label>
                            <select
                                value={statusFilt}
                                onChange={(e) => setStatusFilt(e.target.value)}
                                className="w-full rounded-md bg-black/40 border border-white/[0.08] px-3 py-2 text-[13px] text-white focus:outline-none focus:border-ecf-yellow/40"
                            >
                                <option value="todos">Todos</option>
                                <option value="adman_only">Adman apenas</option>
                                <option value="ml_shadow">ML em shadow</option>
                                <option value="ml_primary_candidate">Candidato a primary</option>
                                <option value="ml_primary">ML primary</option>
                            </select>
                        </div>
                        <div>
                            <label className="block text-white/40 text-[11px] uppercase tracking-wider mb-1">Token ML</label>
                            <select
                                value={tokenFilt}
                                onChange={(e) => setTokenFilt(e.target.value)}
                                className="w-full rounded-md bg-black/40 border border-white/[0.08] px-3 py-2 text-[13px] text-white focus:outline-none focus:border-ecf-yellow/40"
                            >
                                <option value="todos">Todos</option>
                                <option value="active">Ativo</option>
                                <option value="expired">Expirado</option>
                                <option value="error_refresh">Erro refresh</option>
                                <option value="missing">Ausente</option>
                            </select>
                        </div>
                        <div>
                            <label className="block text-white/40 text-[11px] uppercase tracking-wider mb-1">Buscar por nome</label>
                            <input
                                type="text"
                                value={busca}
                                onChange={(e) => setBusca(e.target.value)}
                                placeholder="ex: Bymobille"
                                className="w-full rounded-md bg-black/40 border border-white/[0.08] px-3 py-2 text-[13px] text-white placeholder-white/30 focus:outline-none focus:border-ecf-yellow/40"
                            />
                        </div>
                    </div>
                </DevCard>

                {/* Tabela principal */}
                <DevCard
                    icon={Zap}
                    title="Empresas"
                    subtitle={`${linhas.length} de ${kpis.total} mostrada${linhas.length === 1 ? '' : 's'}.`}
                >
                    {linhas.length === 0 ? (
                        <div className="text-center py-10 text-white/40 text-[13px]">
                            Nenhuma empresa corresponde aos filtros atuais.
                        </div>
                    ) : (
                        <div className="overflow-x-auto -mx-2">
                            <table className="min-w-full text-[13px]">
                                <thead className="text-white/40 text-[11px] uppercase tracking-wider border-b border-white/[0.06]">
                                    <tr>
                                        <th className="text-left px-3 py-2">Empresa</th>
                                        <th className="text-left px-3 py-2">Token ML</th>
                                        <th className="text-left px-3 py-2">Advertiser</th>
                                        <th className="text-left px-3 py-2">Smoke</th>
                                        <th className="text-left px-3 py-2">Shadow 7d</th>
                                        <th className="text-left px-3 py-2">Status</th>
                                        <th className="text-right px-3 py-2">Acoes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {linhas.map((row) => {
                                        const acao = emAcao[row.id];
                                        return (
                                            <tr
                                                key={row.id}
                                                className="border-b border-white/[0.04] hover:bg-white/[0.02] transition-colors"
                                            >
                                                <td className="px-3 py-3">
                                                    <div className="font-medium text-white">{row.name}</div>
                                                    <div className="text-white/40 text-[11px] mt-0.5">#{row.id}</div>
                                                </td>
                                                <td className="px-3 py-3">
                                                    <TokenBadge state={row.token_state} />
                                                </td>
                                                <td className="px-3 py-3">
                                                    {row.advertiser === 'ok' ? (
                                                        <span className="inline-flex items-center gap-1 text-emerald-300">
                                                            <Check size={14} /> ok
                                                        </span>
                                                    ) : (
                                                        <span className="text-white/40">—</span>
                                                    )}
                                                </td>
                                                <td className="px-3 py-3 text-white/70 font-mono text-[12px]">
                                                    {fmtTs(row.smoke_last_at)}
                                                </td>
                                                <td className="px-3 py-3">
                                                    <ParityCell pct={row.shadow_paridade_7d} status={row.status} />
                                                </td>
                                                <td className="px-3 py-3">
                                                    <StatusBadge status={row.status} />
                                                </td>
                                                <td className="px-3 py-3">
                                                    <div className="flex items-center justify-end gap-1.5">
                                                        <button
                                                            type="button"
                                                            disabled={!row.actions?.can_run_smoke || !!acao}
                                                            onClick={() =>
                                                                dispararAcao(
                                                                    'dev.sugadores_ml_onboarding.smoke',
                                                                    row.id,
                                                                    'smoke',
                                                                )
                                                            }
                                                            className={cn(
                                                                'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[12px] transition-colors border',
                                                                row.actions?.can_run_smoke
                                                                    ? 'bg-white/[0.05] hover:bg-white/[0.1] text-white/70 border-white/[0.08]'
                                                                    : 'bg-white/[0.02] text-white/20 border-white/[0.04] cursor-not-allowed',
                                                            )}
                                                            title="Dispara sugadores:ml-smoke async via queue"
                                                        >
                                                            {acao === 'smoke' ? <RefreshCw size={12} className="animate-spin" /> : <Zap size={12} />}
                                                            Smoke
                                                        </button>
                                                        <button
                                                            type="button"
                                                            disabled={!row.actions?.can_run_shadow || !!acao}
                                                            onClick={() =>
                                                                dispararAcao(
                                                                    'dev.sugadores_ml_onboarding.shadow',
                                                                    row.id,
                                                                    'shadow',
                                                                )
                                                            }
                                                            className={cn(
                                                                'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[12px] transition-colors border',
                                                                row.actions?.can_run_shadow
                                                                    ? 'bg-white/[0.05] hover:bg-white/[0.1] text-white/70 border-white/[0.08]'
                                                                    : 'bg-white/[0.02] text-white/20 border-white/[0.04] cursor-not-allowed',
                                                            )}
                                                            title="Dispara sugadores:shadow-ml --days=1 async"
                                                        >
                                                            {acao === 'shadow' ? <RefreshCw size={12} className="animate-spin" /> : <Activity size={12} />}
                                                            Shadow
                                                        </button>
                                                        <button
                                                            type="button"
                                                            disabled={!row.actions?.can_toggle_shadow || !!acao}
                                                            onClick={() =>
                                                                dispararAcao(
                                                                    'dev.sugadores_ml_onboarding.toggle_shadow',
                                                                    row.id,
                                                                    'toggle',
                                                                )
                                                            }
                                                            className={cn(
                                                                'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[12px] transition-colors border',
                                                                row.actions?.can_toggle_shadow
                                                                    ? (row.status === 'ml_shadow' || row.status === 'ml_primary_candidate')
                                                                        ? 'bg-blue-400/10 hover:bg-blue-400/20 text-blue-200 border-blue-400/20'
                                                                        : 'bg-white/[0.05] hover:bg-white/[0.1] text-white/70 border-white/[0.08]'
                                                                    : 'bg-white/[0.02] text-white/20 border-white/[0.04] cursor-not-allowed',
                                                            )}
                                                            title="Alterna shadow_enabled em sugador_ml_company_config"
                                                        >
                                                            {acao === 'toggle'
                                                                ? <RefreshCw size={12} className="animate-spin" />
                                                                : (row.status === 'ml_shadow' || row.status === 'ml_primary_candidate')
                                                                    ? <ToggleRight size={12} />
                                                                    : <ToggleLeft size={12} />}
                                                            Toggle
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {/* Aviso de erro: empresas sem token */}
                    {kpis.semToken > 0 && (
                        <div className="mt-4 flex items-start gap-2 rounded-lg bg-amber-400/[0.06] border border-amber-400/20 px-3 py-2.5 text-[12px] text-amber-200">
                            <AlertTriangle size={14} className="mt-0.5 shrink-0" />
                            <div>
                                {kpis.semToken} empresa{kpis.semToken === 1 ? '' : 's'} sem MlToken: complete o OAuth ML pelo painel
                                "ML OAuth" antes de habilitar smoke/shadow.
                            </div>
                        </div>
                    )}
                </DevCard>
            </div>
        </AppLayout>
    );
}
