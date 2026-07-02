import AppLayout from '@/Layouts/AppLayout';
import { router } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import {
    AlertTriangle, Building2,
    X, Tag,
    Settings, Search, ArrowRight,
    Copy, Check, Loader2,
} from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Constantes de UI ──────────────────────────────────────────────────────
const STATUS_LABELS = {
    pendente:       'Pendente',
    em_acao:        'Em ação',
    resolvido:      'Resolvido',
    ignorado:       'Ignorado',
    movido:         'Movido p/ SGI',
    auto_resolvido: 'Auto-resolvido',
};

const STATUS_BADGE = {
    pendente:       'bg-red-500/15 text-red-300 border-red-500/30',
    em_acao:        'bg-amber-500/15 text-amber-300 border-amber-500/30',
    resolvido:      'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
    ignorado:       'bg-zinc-500/15 text-zinc-300 border-zinc-500/30',
    movido:         'bg-ecf-yellow/15 text-ecf-yellow border-ecf-yellow/30',
    // Verde claro distinto do `resolvido` cheio — sinaliza que o sistema resolveu sozinho.
    auto_resolvido: 'bg-emerald-500/10 text-emerald-200/70 border-emerald-500/20',
};

const TIPO_LABELS = { adgroup: 'Adgroup', campanha: 'Campanha' };
const TIPO_ICONS  = { adgroup: Tag, campanha: Megaphone };

const MOTIVO_LABELS = {
    gasto_sem_venda:         'Gasto sem venda',
    cpc_alto:                'CPC alto',
    acos_alto:               'ACOS alto',
    cliques_sem_conversao:   'Cliques sem conversão',
    pct_anuncios_sugadores:  '% adgroups sugadores',
};

const ACAO_LABELS = {
    pausado:        'Pausado no ML',
    removido:       'Removido',
    reduzido_lance: 'Reduzido lance',
    reativado:      'Reativado',
    outro:          'Outro',
};

// ─── Helpers ───────────────────────────────────────────────────────────────
const fmtBRL = (n) => 'R$ ' + Number(n ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtInt = (n) => Number(n ?? 0).toLocaleString('pt-BR');
const fmtPct = (n) => n == null ? '—' : Number(n).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + '%';
// Aceita "YYYY-MM-DD" e também ISO datetime ("YYYY-MM-DDTHH:mm:ss.SSSZ") do cast 'date' do Laravel.
// Fixa em meia-noite local para não cair no dia anterior por causa de timezone.
const fmtDate = (d) => d ? new Date(String(d).slice(0, 10) + 'T00:00:00').toLocaleDateString('pt-BR') : '—';

// Sugador identificado HOJE — usado pra destacar row e exibir badge "HOJE".
// Compara só os primeiros 10 chars (YYYY-MM-DD) pra evitar ruído de timezone.
const isHoje = (d) => {
    if (!d) return false;
    const refDay = String(d).slice(0, 10);
    const today  = new Date().toLocaleDateString('en-CA'); // YYYY-MM-DD local
    return refDay === today;
};

// ─── Helper de clipboard ──────────────────────────────────────────────────
// Duplicado de Show.jsx para não criar acoplamento entre páginas.
// Futuro: extrair para resources/js/lib/utils.js quando houver 3+ consumers.
const copyToClipboard = async (text) => {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return true;
        }
        // Fallback necessário para intranet sem HTTPS — navigator.clipboard exige secure context.
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        return ok;
    } catch {
        return false;
    }
};

// ─── Componentes locais ────────────────────────────────────────────────────

function StatusBadge({ status }) {
    // Tooltip explicativo só faz sentido pro auto_resolvido — outros status são autoexplicativos.
    const title = status === 'auto_resolvido' ? 'Resolvido automaticamente pelo sistema' : undefined;
    return (
        <span
            className={cn('inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border', STATUS_BADGE[status])}
            title={title}
        >
            {STATUS_LABELS[status] || status}
        </span>
    );
}

// ─── Helpers de tempo relativo (sem deps externas — date-fns só usado em outros módulos) ──
const fmtRelative = (iso) => {
    if (!iso) return '—';
    const ts = new Date(iso).getTime();
    if (Number.isNaN(ts)) return '—';
    const diff = Date.now() - ts;
    const min = Math.round(diff / 60000);
    if (min < 1) return 'agora';
    if (min < 60) return `há ${min} min`;
    const h = Math.round(min / 60);
    if (h < 24) return `há ${h}h`;
    const d = Math.round(h / 24);
    if (d < 30) return `há ${d}d`;
    // Fallback pra datas antigas — exibe a data formatada.
    return new Date(iso).toLocaleDateString('pt-BR');
};

// Phase 52-02 (A7): helper fmtHHMM removido junto com o feedback "Enfileirado às HH:mm".

// ─── Card por empresa ──────────────────────────────────────────────────────
// Componente local — segue convenção de StatusBadge/MotivoBadge no mesmo arquivo.
// Phase 18 W5-T4 — Badge "Cust ID Inválido" inline. Renderiza apenas
// quando status === 'invalido'; sem emoji; tooltip pt-BR via title nativo.
function CustIdInvalidoBadge({ status }) {
    if (status !== 'invalido') return null;
    return (
        <span
            title="Cust ID corrompido — Adman não reconhece. Conectar OAuth ML ou ajustar cadastro."
            className="inline-flex items-center text-[10px] font-semibold px-1.5 py-0.5 rounded bg-red-500/10 text-red-400 border border-red-500/20 tracking-wide"
        >
            Cust ID Inválido
        </span>
    );
}

// Phase 52-02 (A2): AnaliseBadge removido — a UI não deve mais alegar
// "Análise diária" enquanto não existe cron ML equivalente ao antigo Adman D-1.
// Reintroduzir apenas quando o cron ML de análise (seed futuro) estiver ativo.

function CompanyCard({ card, onVer, copyingEmpresaId, copiedEmpresaFeedback, onCopyMlbs }) {
    const hasHoje = (card.count_hoje ?? 0) > 0;
    return (
        <div className="card-ecf rounded-xl p-4 flex flex-col gap-3 hover:border-white/[0.12] transition-colors">
            <div className="flex items-start gap-2 min-w-0">
                <Building2 size={14} className="text-white/40 shrink-0 mt-0.5" />
                <h3 className="text-white font-display font-semibold text-[14px] truncate flex-1" title={card.name}>
                    {card.name}
                </h3>
                {/* Phase 18 W5-T4 — Badge "Cust ID Inválido" no header do card */}
                <CustIdInvalidoBadge status={card.cust_id_status} />
                {/* Phase 52-02 (A2): AnaliseBadge removido — sem cron ML ativo. */}
            </div>

            <div>
                <p className={cn(
                    'font-display font-bold text-3xl tabular-nums leading-none',
                    hasHoje ? 'text-ecf-yellow' : 'text-white/40',
                )}>
                    {fmtInt(card.count_hoje)}
                </p>
                <p className="text-white/40 text-[10px] uppercase tracking-wider font-semibold mt-1">
                    Pendentes HOJE
                </p>
            </div>

            <div className="flex items-center justify-between text-[11px] text-white/50 pt-2 border-t border-white/[0.04]">
                <span>{fmtInt(card.total_pendentes)} acumulado{Number(card.total_pendentes) !== 1 ? 's' : ''}</span>
                <span title={card.ultima_analise || ''}>{fmtRelative(card.ultima_analise)}</span>
            </div>

            <div className="flex items-center gap-2 pt-1 flex-wrap">
                <button
                    type="button"
                    onClick={() => onVer(card.company_id)}
                    className="flex-1 inline-flex items-center justify-center gap-1.5 h-8 px-3 rounded-lg bg-ecf-yellow/15 text-ecf-yellow border border-ecf-yellow/30 hover:bg-ecf-yellow/25 text-[12px] font-semibold"
                >
                    Ver sugadores
                    <ArrowRight size={11} />
                </button>

                {/* Phase 19 W2-T4 — Botão Copiar MLBs da empresa.
                    Reutiliza endpoint mlbs-by-company que serializa MCP via Cache::lock por custId.
                    1º adgroup paga o custo (~15s TLS); demais leem do cache compartilhado. */}
                {hasHoje && onCopyMlbs && (
                    <button
                        type="button"
                        onClick={() => onCopyMlbs(card.company_id)}
                        disabled={copyingEmpresaId === card.company_id}
                        className="inline-flex items-center justify-center gap-1.5 h-8 px-2.5 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/70 hover:text-white hover:bg-white/[0.05] text-[12px]"
                        title={`Copia MLBs de todos os ${card.count_hoje} sugadores adgroup desta empresa (HOJE)`}
                    >
                        {copyingEmpresaId === card.company_id
                            ? <><Loader2 size={11} className="animate-spin" /> Processando...</>
                            : copiedEmpresaFeedback?.[card.company_id]?.ok
                                ? <><Check size={11} className="text-emerald-300" /> Copiado {copiedEmpresaFeedback[card.company_id].total}</>
                                : copiedEmpresaFeedback?.[card.company_id]?.error
                                    ? copiedEmpresaFeedback[card.company_id].error
                                    : <><Copy size={11} /> Copiar MLBs</>}
                    </button>
                )}

                {/* Phase 52-02 (A7): botão "Reanalisar" removido — reanálise per-empresa
                    passa a viver no drilldown Show.jsx (adicionada em wave posterior). */}
            </div>

            {/* Aviso truncado: mais de 20 adgroups, só os 20 primeiros foram copiados */}
            {copiedEmpresaFeedback?.[card.company_id]?.truncated && (
                <p className="text-white/40 text-[11px] -mt-1">
                    (20 de {card.count_hoje} — copie em partes se necessário)
                </p>
            )}

            {/* Phase 52-02 (A2/A7): bloco "Análise diária já rodou hoje" removido,
                assim como o feedback "Enfileirado às HH:mm" (dependia de enqueuedAt do botão Reanalisar,
                que também sai nesta wave). */}
        </div>
    );
}

function MotivoBadge({ motivo }) {
    return (
        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-white/[0.05] text-white/70 border border-white/[0.08]">
            {MOTIVO_LABELS[motivo] || motivo}
        </span>
    );
}

// Phase 52-02 (A9): componentes NativeSelect e SearchableCompanyFilter removidos —
// eram usados exclusivamente pela barra de filtros da tabela lista (descontinuada).

/**
 * Picker simples de empresa que abre Config de Sugadores ao escolher.
 * Pulado o seletor quando há uma só empresa (vai direto pra config).
 */
function ConfigPickerModal({ companies, onClose }) {
    const [q, setQ] = useState('');
    const filtered = q
        ? companies.filter(c => c.name.toLowerCase().includes(q.toLowerCase()))
        : companies;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" onClick={onClose} />
            <div className="relative card-ecf rounded-2xl w-full max-w-md p-5">
                <div className="flex items-start justify-between mb-3">
                    <div>
                        <div className="flex items-center gap-2">
                            <Settings size={15} className="text-ecf-yellow" />
                            <h3 className="text-white font-display font-bold text-base">Configurar Sugadores</h3>
                        </div>
                        <p className="text-white/50 text-xs mt-0.5">Selecione a empresa para editar os critérios de detecção.</p>
                    </div>
                    <button onClick={onClose} className="text-white/40 hover:text-white">
                        <X size={18} />
                    </button>
                </div>

                <div className="relative mb-3">
                    <Search size={12} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-white/40" />
                    <input
                        type="text"
                        value={q}
                        onChange={e => setQ(e.target.value)}
                        autoFocus
                        placeholder="Buscar empresa..."
                        className="w-full h-9 pl-8 pr-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40"
                    />
                </div>

                <div className="max-h-[320px] overflow-y-auto -mx-1 px-1">
                    {filtered.length === 0 ? (
                        <p className="text-white/40 text-sm py-6 text-center">Nenhuma empresa encontrada.</p>
                    ) : (
                        <ul className="space-y-1">
                            {filtered.map(c => (
                                <li key={c.id}>
                                    <Link
                                        href={route('sugadores.config.show', c.id)}
                                        className="flex items-center gap-2 px-3 py-2 rounded-lg border border-transparent hover:border-white/[0.08] hover:bg-white/[0.04] text-[13px] text-white/80 hover:text-white"
                                    >
                                        <Building2 size={12} className="text-white/30" />
                                        <span className="truncate">{c.name}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </div>
    );
}

// Phase 52-02 (A9): StatusUpdateModal removido — botão "Agir" vivia dentro da linha
// da tabela lista. Fluxo de mudança de status por sugador segue vivo dentro da
// drilldown Show.jsx (que tem seu próprio widget).

// ─── Página principal ──────────────────────────────────────────────────────

export default function SugadoresIndex({
    companies,
    total_pendentes,
    can_manage,
    // Phase 52-02: props deixadas de ser consumidas — backend continua enviando
    // (sem quebrar), esta wave remove só os call-sites do frontend:
    //  - `sugadores`, `users`, `user_companies`, `filters`, `view_mode`, `default_view`:
    //    alimentavam a tabela lista global + filtros descontinuados (A9).
    //  - `can_analyze`: alimentava botão global "Rodar análise" + botão Reanalisar (A7).
    //  - `analise_diaria`: alimentava banner "Análise diária roda às ..." (A2).
    companies_summary = [],
}) {
    // Phase 52-02 (A7/A9): estados `f`/`setF`, `showFilters`, `actionTarget`, `analyzing`,
    // `enqueuedAt`, `copyingId`, `copiedFeedback` removidos junto com a tabela lista
    // e botões descontinuados.

    // ─── Estado de cópia por empresa no CompanyCard (W2-T4) ────────────────
    const [copyingEmpresaId, setCopyingEmpresaId] = useState(null);
    const [copiedEmpresaFeedback, setCopiedEmpresaFeedback] = useState({});

    async function copyMlbsEmpresa(companyId) {
        setCopyingEmpresaId(companyId);
        try {
            const r = await fetch(route('sugadores.mlbs-by-company', companyId), { headers: { Accept: 'application/json' } });
            // Backend agora retorna 502 com { reason } quando TODOS os sugadores
            // falham (típico em rate limit 429 da Adman). Antes, ele silenciava
            // erros e devolvia mlbs:[] que o frontend exibia como "Sem MLBs" —
            // confundindo falha temporária com lista realmente vazia.
            const j = await r.json().catch(() => ({}));
            if (!r.ok) {
                const reason = j.reason ?? `Erro ${r.status} — tente novamente`;
                setCopiedEmpresaFeedback(p => ({ ...p, [companyId]: { error: reason } }));
                return;
            }
            const mlbs = j.mlbs || [];
            if (!mlbs.length) {
                setCopiedEmpresaFeedback(p => ({ ...p, [companyId]: { total: 0, error: 'Sem MLBs' } }));
                return;
            }
            const ok = await copyToClipboard(mlbs.join(','));
            setCopiedEmpresaFeedback(p => ({
                ...p,
                [companyId]: { total: j.total_mlbs, processados: j.sugadores_processados, truncated: j.truncated, ok },
            }));
        } catch {
            setCopiedEmpresaFeedback(p => ({ ...p, [companyId]: { error: 'Falha de rede — tente novamente' } }));
        } finally {
            setCopyingEmpresaId(null);
            // Mensagens de erro/aviso ficam 6s pra usuário ler frase longa (rate limit etc).
            setTimeout(() => setCopiedEmpresaFeedback(p => { const n = { ...p }; delete n[companyId]; return n; }), 6000);
        }
    }

    // ─── Chip "Continuar com [Empresa]" via localStorage ──────────────────────
    // SSR-safe: leitura/escrita SEMPRE em useEffect ou handler, nunca no body.
    const [lastCompanyId, setLastCompanyId] = useState(null);

    useEffect(() => {
        try {
            const v = localStorage.getItem('sugadores:last_company_id');
            if (v) setLastCompanyId(Number(v));
        } catch {
            // localStorage pode estar bloqueado (modo privado/Storage Access API) — ignora.
        }
    }, []);

    const [configPickerOpen, setConfigPickerOpen] = useState(false);

    // Phase 52-02 (A9): funções applyFilters, clearFilters, switchView, canSelect,
    // toggleOne, toggleAllVisible, clearSelection e derivados (list, meta, hasAnyFilter,
    // filteredCompanyName, selectedSugadores, lockedCompany, eligibleVisibleCount,
    // allVisibleSelected) removidos junto com a tabela lista completa.

    // ─── Abre drilldown filtrado por empresa ────────────────────────────────
    // Phase 52-02 (A9): antes navegava para modo lista via {company_id, view: 'list'}.
    // Modo lista foi descontinuado; agora apenas persiste a última empresa
    // acessada em localStorage (feed do chip "Continuar com X"). O click acaba
    // sendo no-op de navegação — os cards já mostram os totais por empresa.
    // Reanálise per-empresa migra para o drilldown Show.jsx em wave posterior,
    // ponto em que este handler passará a chamar router.visit(route('sugadores.show', ...)).
    function abrirDrilldown(companyId) {
        try {
            localStorage.setItem('sugadores:last_company_id', String(companyId));
            setLastCompanyId(Number(companyId));
        } catch {
            // localStorage indisponível — segue sem persistir.
        }
    }

    // ─── Limpar chip "Continuar com X" ───────────────────────────────────────
    function dismissContinueChip() {
        try {
            localStorage.removeItem('sugadores:last_company_id');
        } catch {
            // ignora — apenas estado local
        }
        setLastCompanyId(null);
    }

    // ─── Resolve empresa do chip "Continuar com X" ──────────────────────────
    // Só renderiza se: tem id salvo e a empresa aparece no summary (evita chip
    // "fantasma" referenciando empresa que sumiu da carteira do user).
    const continueCompany = lastCompanyId
        ? companies_summary.find(c => Number(c.company_id) === Number(lastCompanyId))
        : null;

    return (
        <AppLayout title="Sugadores">
            <div className="mb-6 flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <div className="flex items-center gap-3">
                        <h1 className="text-white font-display font-bold text-2xl">Sugadores</h1>
                        {total_pendentes > 0 && (
                            <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-red-500/15 border border-red-500/30 text-red-300 text-xs font-bold">
                                <AlertTriangle size={12} />
                                {fmtInt(total_pendentes)} pendente{total_pendentes !== 1 ? 's' : ''}
                            </span>
                        )}
                    </div>
                    <p className="text-white/40 text-sm mt-1">Adgroups (e opcionalmente campanhas) drenando investimento sem retorno</p>
                    {/* Phase 52-02 (A2): banner "Análise diária roda às ... · Última execução ..." removido.
                        Referenciava cron Adman D-1 que não roda mais; sem cron ML equivalente ainda. */}
                </div>

                <div className="flex items-center gap-2">
                    {/* Phase 52-02 (A9): toggle Cards/Lista removido — só visão em cards.
                        A tabela lista global e o filtro por empresa foram descontinuados;
                        cards por empresa é a única visão do Index a partir desta wave. */}
                    {can_manage && (
                        <button
                            onClick={() => setConfigPickerOpen(true)}
                            className="inline-flex items-center gap-2 h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/70 hover:text-white hover:bg-white/[0.05] text-[13px] font-medium"
                            title="Configurar critérios de detecção por empresa"
                        >
                            <Settings size={14} />
                            Configurar
                        </button>
                    )}
                    {/* Phase 52-02 (A7 parte 2): botão global "Rodar análise" removido.
                        Disparava analyze-all em TODAS as empresas via confirm nativo — comportamento
                        perigoso. Rota backend sugadores.analyze-all fica preservada para Artisan/scripts.
                        Reanálise passa a ser per-empresa no drilldown Show.jsx (wave posterior). */}
                </div>
            </div>

            {/* Chip "Continuar com [Empresa]" — só no modo cards e quando há empresa salva. */}
            {continueCompany && (
                <div className="mb-3 -mt-2 inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-ecf-yellow/[0.08] border border-ecf-yellow/30 text-ecf-yellow text-[12px]">
                    <span className="text-ecf-yellow/70">Continuar com</span>
                    <button
                        type="button"
                        onClick={() => abrirDrilldown(continueCompany.company_id)}
                        className="font-semibold hover:underline inline-flex items-center gap-1"
                    >
                        {continueCompany.name}
                        <ArrowRight size={11} />
                    </button>
                    <button
                        type="button"
                        onClick={dismissContinueChip}
                        className="text-ecf-yellow/60 hover:text-ecf-yellow ml-1"
                        title="Dispensar"
                    >
                        <X size={12} />
                    </button>
                </div>
            )}

            {/* Phase 52-02 (A9): chip "Filtrando empresa" removido (dependia do modo lista).
                Cards por empresa é a única visão do Index a partir desta wave. */}

            {/* ─── Visão em Cards (única visão do Index a partir de Phase 52-02) ─── */}
            {companies_summary.length === 0 ? (
                <div className="card-ecf rounded-xl p-12 text-center">
                    <Building2 size={32} className="mx-auto text-white/20 mb-3" />
                    <p className="text-white/60 text-sm font-medium">Nenhuma empresa visível.</p>
                    <p className="text-white/30 text-xs mt-1">
                        {can_manage
                            ? 'Configure thresholds em uma empresa e rode a análise.'
                            : 'Nenhum sugador pendente na sua carteira.'}
                    </p>
                </div>
            ) : (
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    {companies_summary.map(card => (
                        <CompanyCard
                            key={card.company_id}
                            card={card}
                            onVer={abrirDrilldown}
                            onCopyMlbs={copyMlbsEmpresa}
                            copyingEmpresaId={copyingEmpresaId}
                            copiedEmpresaFeedback={copiedEmpresaFeedback}
                        />
                    ))}
                </div>
            )}


            {/* Phase 52-02 (A9): modais StatusUpdateModal (acao inline por sugador) e
                MoveToSgiModal (bulk move) removidos junto com a tabela lista.
                A ação por sugador continua disponível dentro do drilldown Show.jsx.
                Bulk move só existia no contexto da tabela lista com checkboxes. */}

            {configPickerOpen && (
                <ConfigPickerModal companies={companies} onClose={() => setConfigPickerOpen(false)} />
            )}
        </AppLayout>
    );
}
