import AppLayout from '@/Layouts/AppLayout';
import { Link, router, useForm } from '@inertiajs/react';
import { useState, useRef, useEffect } from 'react';
import {
    AlertTriangle, Building2, ChevronLeft, ChevronRight,
    PlayCircle, Filter, X, Megaphone, Tag, ListTree, ArrowRightLeft,
    Settings, Search, LayoutGrid, List, RotateCw, ArrowRight,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import MoveToSgiModal from '@/Components/MoveToSgiModal';

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

// Formata HH:mm pra feedback de enfileiramento ("Enfileirado às HH:mm").
const fmtHHMM = (date) => {
    const h = String(date.getHours()).padStart(2, '0');
    const m = String(date.getMinutes()).padStart(2, '0');
    return `${h}:${m}`;
};

// ─── Card por empresa ──────────────────────────────────────────────────────
// Componente local — segue convenção de StatusBadge/MotivoBadge no mesmo arquivo.
function CompanyCard({ card, canAnalyze, enqueuedAt, onReanalisar, onVer }) {
    const hasHoje = (card.count_hoje ?? 0) > 0;
    return (
        <div className="card-ecf rounded-xl p-4 flex flex-col gap-3 hover:border-white/[0.12] transition-colors">
            <div className="flex items-start gap-2 min-w-0">
                <Building2 size={14} className="text-white/40 shrink-0 mt-0.5" />
                <h3 className="text-white font-display font-semibold text-[14px] truncate flex-1" title={card.name}>
                    {card.name}
                </h3>
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

            <div className="flex items-center gap-2 pt-1">
                <button
                    type="button"
                    onClick={() => onVer(card.company_id)}
                    className="flex-1 inline-flex items-center justify-center gap-1.5 h-8 px-3 rounded-lg bg-ecf-yellow/15 text-ecf-yellow border border-ecf-yellow/30 hover:bg-ecf-yellow/25 text-[12px] font-semibold"
                >
                    Ver sugadores
                    <ArrowRight size={11} />
                </button>
                {canAnalyze && card.can_analyze && (
                    <button
                        type="button"
                        onClick={() => onReanalisar(card.company_id)}
                        className="inline-flex items-center justify-center gap-1.5 h-8 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/70 hover:text-white hover:bg-white/[0.05] text-[12px]"
                        title="Reanalisar esta empresa agora (job na fila)"
                    >
                        <RotateCw size={11} />
                        Reanalisar
                    </button>
                )}
            </div>

            {enqueuedAt && (
                <p className="text-emerald-300/80 text-[11px] -mt-1">
                    Enfileirado às {enqueuedAt}
                </p>
            )}
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

function NativeSelect({ value, onChange, placeholder, options, className }) {
    return (
        <select
            value={value || ''}
            onChange={e => onChange(e.target.value)}
            className={cn(
                'h-9 pl-3 pr-8 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 cursor-pointer',
                className
            )}
        >
            {placeholder && <option value="">{placeholder}</option>}
            {options.map(o => (
                <option key={o.value ?? o} value={o.value ?? o}>{o.label ?? o}</option>
            ))}
        </select>
    );
}

function SearchableCompanyFilter({ value, onChange, companies, placeholder }) {
    const [open, setOpen]   = useState(false);
    const [q, setQ]         = useState('');
    const containerRef      = useRef(null);
    const inputRef          = useRef(null);

    const selected = companies.find(c => String(c.id) === String(value));
    const filtered = q
        ? companies.filter(c => c.name.toLowerCase().includes(q.toLowerCase()))
        : companies;

    useEffect(() => {
        if (!open) return;
        function handler(e) {
            if (containerRef.current && !containerRef.current.contains(e.target)) {
                setOpen(false);
                setQ('');
            }
        }
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, [open]);

    function select(company) {
        onChange(company ? String(company.id) : '');
        setOpen(false);
        setQ('');
    }

    function handleOpen() {
        setOpen(true);
        setTimeout(() => inputRef.current?.focus(), 0);
    }

    return (
        <div ref={containerRef} className="relative">
            <button
                type="button"
                onClick={handleOpen}
                className="w-full h-9 pl-3 pr-8 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-left flex items-center justify-between gap-2 focus:outline-none focus:border-ecf-yellow/40"
            >
                <span className={cn('truncate', selected ? 'text-white/80' : 'text-white/40')}>
                    {selected ? selected.name : placeholder}
                </span>
                {selected && (
                    <span
                        role="button"
                        onClick={e => { e.stopPropagation(); select(null); }}
                        className="shrink-0 text-white/30 hover:text-white/70 cursor-pointer"
                    >
                        <X size={12} />
                    </span>
                )}
            </button>

            {open && (
                <div className="absolute z-30 top-10 left-0 w-64 card-ecf rounded-lg border border-white/[0.08] shadow-xl p-2">
                    <div className="relative mb-2">
                        <Search size={11} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-white/30" />
                        <input
                            ref={inputRef}
                            type="text"
                            value={q}
                            onChange={e => setQ(e.target.value)}
                            onKeyDown={e => e.key === 'Escape' && (setOpen(false), setQ(''))}
                            placeholder="Buscar empresa..."
                            className="w-full h-8 pl-7 pr-3 rounded-md border border-white/[0.08] bg-white/[0.03] text-[12px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/30"
                        />
                    </div>
                    <ul className="max-h-52 overflow-y-auto space-y-0.5">
                        <li>
                            <button
                                onClick={() => select(null)}
                                className="w-full text-left px-2.5 py-1.5 rounded text-[12px] text-white/40 hover:text-white/70 hover:bg-white/[0.04]"
                            >
                                {placeholder}
                            </button>
                        </li>
                        {filtered.length === 0 ? (
                            <li className="text-white/30 text-[12px] py-3 text-center">Nenhuma empresa encontrada</li>
                        ) : (
                            filtered.map(c => (
                                <li key={c.id}>
                                    <button
                                        onClick={() => select(c)}
                                        className={cn(
                                            'w-full text-left px-2.5 py-1.5 rounded text-[12px] hover:bg-white/[0.04]',
                                            String(value) === String(c.id) ? 'text-ecf-yellow' : 'text-white/80 hover:text-white',
                                        )}
                                    >
                                        {c.name}
                                    </button>
                                </li>
                            ))
                        )}
                    </ul>
                </div>
            )}
        </div>
    );
}

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

function StatusUpdateModal({ sugador, onClose }) {
    const { data, setData, patch, processing, errors, reset } = useForm({
        status:      'em_acao',
        acao_tomada: '',
        observacao:  '',
    });

    function submit(e) {
        e.preventDefault();
        patch(route('sugadores.update-status', sugador.id), {
            preserveScroll: true,
            onSuccess: () => { reset(); onClose(); },
        });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/70 backdrop-blur-sm" onClick={onClose} />
            <div className="relative card-ecf rounded-2xl w-full max-w-md p-6">
                <div className="flex items-start justify-between mb-4">
                    <div>
                        <h3 className="text-white font-display font-bold text-lg">Atualizar status</h3>
                        <p className="text-white/40 text-xs mt-0.5">{sugador.company?.name}</p>
                    </div>
                    <button onClick={onClose} className="text-white/40 hover:text-white">
                        <X size={18} />
                    </button>
                </div>

                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <label className="block text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-1.5">Novo status</label>
                        <NativeSelect
                            value={data.status}
                            onChange={v => setData('status', v)}
                            options={[
                                { value: 'em_acao',   label: 'Em ação' },
                                { value: 'resolvido', label: 'Resolvido' },
                                { value: 'ignorado',  label: 'Ignorado' },
                                { value: 'pendente',  label: 'Voltar para pendente' },
                            ]}
                            className="w-full"
                        />
                        {errors.status && <p className="text-red-400 text-xs mt-1">{errors.status}</p>}
                    </div>

                    {data.status === 'resolvido' && (
                        <div>
                            <label className="block text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-1.5">Ação tomada no ML</label>
                            <NativeSelect
                                value={data.acao_tomada}
                                onChange={v => setData('acao_tomada', v)}
                                placeholder="— selecione —"
                                options={Object.entries(ACAO_LABELS).map(([v, l]) => ({ value: v, label: l }))}
                                className="w-full"
                            />
                        </div>
                    )}

                    <div>
                        <label className="block text-white/60 text-[11px] font-semibold uppercase tracking-wider mb-1.5">Observação</label>
                        <textarea
                            value={data.observacao}
                            onChange={e => setData('observacao', e.target.value)}
                            rows={3}
                            placeholder="Notas sobre a ação tomada (opcional)"
                            className="w-full p-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 resize-none"
                        />
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            onClick={onClose}
                            className="px-4 h-9 rounded-lg border border-white/[0.08] text-white/60 hover:text-white hover:bg-white/[0.05] text-[13px] font-medium"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            disabled={processing}
                            className="px-4 h-9 rounded-lg bg-ecf-yellow text-[#252525] hover:bg-ecf-yellow/90 disabled:opacity-50 text-[13px] font-bold"
                        >
                            {processing ? 'Salvando...' : 'Confirmar'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

// ─── Página principal ──────────────────────────────────────────────────────

export default function SugadoresIndex({
    sugadores,
    companies,
    users = [],
    user_companies = {},
    filters,
    total_pendentes,
    can_manage,
    can_analyze,
    companies_summary = [],
    view_mode = 'cards',
}) {
    const [f, setF] = useState({
        company_id:       filters?.company_id || '',
        user_id:          filters?.user_id || '',
        status:           filters?.status || '',
        tipo:             filters?.tipo || '',
        date_from:        filters?.date_from || '',
        date_to:          filters?.date_to || '',
        include_resolved: filters?.include_resolved ? '1' : '',
    });
    const [showFilters, setShowFilters] = useState(false);
    const [actionTarget, setActionTarget] = useState(null);
    const [analyzing, setAnalyzing] = useState(false);

    // ─── Estado de feedback do botão "Reanalisar" (por empresa) ───────────────
    // Map company_id → "HH:mm" do momento do enfileiramento; expira após 10s.
    const [enqueuedAt, setEnqueuedAt] = useState({});

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

    // ─── Bulk selection: só adgroups (tipo=adgroup) podem ser movidos pra SGI.
    // Estado é Set de IDs. Constraint dura: todos selecionados devem ser da MESMA
    // empresa — quando o user clica num sugador de outra empresa enquanto há
    // seleção, a primeira empresa "trava" o resto até limpar. UI desabilita
    // os checkboxes incompatíveis pra evitar ambiguidade.
    const [selectedIds, setSelectedIds] = useState(new Set());
    const [bulkMoveOpen, setBulkMoveOpen] = useState(false);
    const [configPickerOpen, setConfigPickerOpen] = useState(false);

    // Empresas disponíveis no filtro: se um responsável está selecionado,
    // mostra apenas as empresas vinculadas a ele via company_users.
    const companiesParaFiltro = f.user_id && user_companies[f.user_id]
        ? companies.filter(c => user_companies[f.user_id].map(String).includes(String(c.id)))
        : companies;

    function applyFilters(updates = {}) {
        let merged = { ...f, ...updates };

        // Filtro cascata: ao trocar responsável, limpa empresa se ela não pertence ao novo responsável.
        if ('user_id' in updates && updates.user_id !== f.user_id) {
            const permitidas = updates.user_id
                ? (user_companies[updates.user_id] || []).map(String)
                : null;
            if (permitidas && merged.company_id && !permitidas.includes(String(merged.company_id))) {
                merged = { ...merged, company_id: '' };
            }
        }

        setF(merged);
        const clean = Object.fromEntries(Object.entries(merged).filter(([, v]) => v !== '' && v != null));
        // Preserva view_mode atual na navegação (filtros só fazem sentido em modo lista).
        if (view_mode) clean.view = view_mode;
        router.get(route('sugadores.index'), clean, { preserveState: true, preserveScroll: true });
    }

    function clearFilters() {
        setF({ company_id: '', user_id: '', status: '', tipo: '', date_from: '', date_to: '', include_resolved: '' });
        router.get(route('sugadores.index'), { view: view_mode }, { preserveState: true });
    }

    function runAnalysis() {
        if (!confirm('Rodar análise para TODAS as empresas com config ativa? Isso pode levar alguns minutos.')) return;
        setAnalyzing(true);
        router.post(route('sugadores.analyze-all'), {}, {
            preserveScroll: true,
            onFinish: () => setAnalyzing(false),
        });
    }

    // ─── Toggle entre cards e lista (preserva filtros relevantes) ────────────
    function switchView(nextView) {
        if (nextView === view_mode) return;
        const params = nextView === 'list'
            ? { ...Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v != null)), view: 'list' }
            : { view: 'cards' };
        router.get(route('sugadores.index'), params, { preserveScroll: true, preserveState: true });
    }

    // ─── Abre drilldown filtrado por empresa (modo lista) ───────────────────
    function abrirDrilldown(companyId) {
        try {
            localStorage.setItem('sugadores:last_company_id', String(companyId));
            setLastCompanyId(Number(companyId));
        } catch {
            // localStorage indisponível — segue navegação normalmente.
        }
        router.get(route('sugadores.index'), { company_id: companyId, view: 'list' }, {
            preserveScroll: false,
        });
    }

    // ─── Dispara reanálise de UMA empresa (reusa rota existente) ────────────
    function reanalisarEmpresa(companyId) {
        router.post(route('sugadores.analyze-company', companyId), {}, {
            preserveScroll: true,
            onSuccess: () => {
                const hhmm = fmtHHMM(new Date());
                setEnqueuedAt(prev => ({ ...prev, [companyId]: hhmm }));
                // Limpa o feedback após ~10s — só uma indicação visual de "deu certo".
                setTimeout(() => {
                    setEnqueuedAt(prev => {
                        const next = { ...prev };
                        delete next[companyId];
                        return next;
                    });
                }, 10000);
            },
        });
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

    const list = sugadores?.data ?? [];
    const meta = sugadores ?? { current_page: 1, last_page: 1, links: [] };
    const hasAnyFilter = Object.values(f).some(v => v && v !== '');

    // ─── Resolve empresa do chip "Continuar com X" ──────────────────────────
    // Só renderiza se: tem id salvo, está no modo cards, e a empresa aparece no summary
    // (evita chip "fantasma" referenciando empresa que sumiu da carteira do user).
    const continueCompany = view_mode === 'cards' && lastCompanyId
        ? companies_summary.find(c => Number(c.company_id) === Number(lastCompanyId))
        : null;

    // ─── Nome da empresa filtrada (modo lista) ──────────────────────────────
    const filteredCompanyName = view_mode === 'list' && f.company_id
        ? (companies.find(c => Number(c.id) === Number(f.company_id))?.name || null)
        : null;

    // Empresa-fonte da seleção: 1ª empresa do 1º selecionado. Usada pra (a) saber
    // pra qual empresa pedir lista de SGI no modal e (b) bloquear checkboxes de
    // outras empresas.
    const selectedSugadores = list.filter(s => selectedIds.has(s.id));
    const lockedCompany     = selectedSugadores[0]?.company || null;

    const canSelect = (s) => {
        // Só adgroups podem ser movidos (campanhas não fazem sentido).
        if (s.tipo !== 'adgroup') return false;
        // Já-movidos/resolvidos saem do fluxo.
        if (s.status === 'movido' || s.status === 'resolvido') return false;
        // Carteira/visão: o backend já filtra; aqui só trava cross-empresa.
        if (lockedCompany && s.company?.id !== lockedCompany.id) return false;
        return true;
    };

    const toggleOne = (id) => {
        setSelectedIds(prev => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    };

    const toggleAllVisible = () => {
        const eligible = list.filter(canSelect).map(s => s.id);
        const allSelected = eligible.length > 0 && eligible.every(id => selectedIds.has(id));
        setSelectedIds(prev => {
            const next = new Set(prev);
            if (allSelected) {
                eligible.forEach(id => next.delete(id));
            } else {
                eligible.forEach(id => next.add(id));
            }
            return next;
        });
    };

    const clearSelection = () => setSelectedIds(new Set());

    const eligibleVisibleCount = list.filter(canSelect).length;
    const allVisibleSelected   = eligibleVisibleCount > 0
        && list.filter(canSelect).every(s => selectedIds.has(s.id));

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
                    {/* Disclaimer D-1 da Adman (Phase 16 SC-7): análise lê métricas
                        da API Adman, que publica D-1; análise diária roda 12h BRT. */}
                    <span
                        className="inline-flex items-center text-white/40 text-xs mt-1"
                        title="Dados defasados em 1 dia — a API Adman publica D-1 ao redor das 10h BRT. Análise diária roda às 12h BRT."
                    >
                        Dados D-1 da Adman · próxima análise: amanhã 12h
                    </span>
                </div>

                <div className="flex items-center gap-2">
                    {/* Toggle Cards / Lista — Cards é a visão default; Lista é o paradigma legado. */}
                    <div className="inline-flex items-center rounded-lg border border-white/[0.08] bg-white/[0.03] p-0.5">
                        <button
                            type="button"
                            onClick={() => switchView('cards')}
                            className={cn(
                                'inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md text-[12px] font-medium transition-colors',
                                view_mode === 'cards'
                                    ? 'bg-ecf-yellow/15 text-ecf-yellow'
                                    : 'text-white/60 hover:text-white',
                            )}
                            title="Visão por empresa"
                        >
                            <LayoutGrid size={12} />
                            Cards
                        </button>
                        <button
                            type="button"
                            onClick={() => switchView('list')}
                            className={cn(
                                'inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md text-[12px] font-medium transition-colors',
                                view_mode === 'list'
                                    ? 'bg-ecf-yellow/15 text-ecf-yellow'
                                    : 'text-white/60 hover:text-white',
                            )}
                            title="Lista global (paradigma antigo, compat com bookmarks)"
                        >
                            <List size={12} />
                            Lista
                        </button>
                    </div>
                    {view_mode === 'list' && (
                        <button
                            onClick={() => setShowFilters(s => !s)}
                            className="inline-flex items-center gap-2 h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white/70 hover:text-white hover:bg-white/[0.05] text-[13px] font-medium"
                        >
                            <Filter size={14} />
                            Filtros
                            {hasAnyFilter && <span className="w-1.5 h-1.5 rounded-full bg-ecf-yellow" />}
                        </button>
                    )}
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
                    {can_analyze && (
                        <button
                            onClick={runAnalysis}
                            disabled={analyzing}
                            className="inline-flex items-center gap-2 h-9 px-3 rounded-lg bg-ecf-yellow text-[#252525] hover:bg-ecf-yellow/90 disabled:opacity-60 text-[13px] font-bold"
                        >
                            <PlayCircle size={14} />
                            {analyzing ? 'Analisando...' : 'Rodar análise'}
                        </button>
                    )}
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

            {/* Chip "Filtrando empresa: [Nome] ✕" — só no modo lista quando há filtro de empresa ativo. */}
            {view_mode === 'list' && filteredCompanyName && (
                <div className="mb-3 -mt-2 inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-white/[0.04] border border-white/[0.10] text-white/80 text-[12px]">
                    <Building2 size={12} className="text-white/50" />
                    <span className="text-white/50">Filtrando empresa:</span>
                    <b className="text-white/90">{filteredCompanyName}</b>
                    <button
                        type="button"
                        onClick={() => applyFilters({ company_id: '' })}
                        className="text-white/40 hover:text-white ml-1"
                        title="Limpar filtro de empresa"
                    >
                        <X size={12} />
                    </button>
                </div>
            )}

            {/* ─── Modo Cards ─── */}
            {view_mode === 'cards' && (
                <>
                    {companies_summary.length === 0 ? (
                        <div className="card-ecf rounded-xl p-12 text-center">
                            <Building2 size={32} className="mx-auto text-white/20 mb-3" />
                            <p className="text-white/60 text-sm font-medium">Nenhuma empresa visível.</p>
                            <p className="text-white/30 text-xs mt-1">
                                {can_manage
                                    ? 'Configure thresholds em uma empresa e rode a análise.'
                                    : 'Aguarde a próxima análise diária ou peça acesso a uma carteira.'}
                            </p>
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            {companies_summary.map(card => (
                                <CompanyCard
                                    key={card.company_id}
                                    card={card}
                                    canAnalyze={!!can_analyze}
                                    enqueuedAt={enqueuedAt[card.company_id]}
                                    onReanalisar={reanalisarEmpresa}
                                    onVer={abrirDrilldown}
                                />
                            ))}
                        </div>
                    )}
                </>
            )}

            {/* ─── Modo Lista (paradigma antigo) ─── */}
            {view_mode === 'list' && (
                <>

            {/* Filtros */}
            {showFilters && (
                <div className="card-ecf rounded-xl p-4 mb-4">
                    <div className="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-3">
                        <SearchableCompanyFilter
                            value={f.company_id}
                            onChange={v => applyFilters({ company_id: v })}
                            companies={companiesParaFiltro}
                            placeholder="Todas empresas"
                        />
                        {can_manage && users.length > 0 && (
                            <NativeSelect
                                value={f.user_id}
                                onChange={v => applyFilters({ user_id: v })}
                                placeholder="Responsável (qualquer)"
                                options={users.map(u => ({ value: u.id, label: u.name }))}
                                className="w-full"
                            />
                        )}
                        <NativeSelect
                            value={f.status}
                            onChange={v => applyFilters({ status: v })}
                            placeholder="Todos status"
                            options={Object.entries(STATUS_LABELS).map(([v, l]) => ({ value: v, label: l }))}
                            className="w-full"
                        />
                        <NativeSelect
                            value={f.tipo}
                            onChange={v => applyFilters({ tipo: v })}
                            placeholder="Todos tipos"
                            options={Object.entries(TIPO_LABELS).map(([v, l]) => ({ value: v, label: l }))}
                            className="w-full"
                        />
                        <input
                            type="date"
                            value={f.date_from}
                            onChange={e => applyFilters({ date_from: e.target.value })}
                            className="h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40"
                            placeholder="De"
                        />
                        <input
                            type="date"
                            value={f.date_to}
                            onChange={e => applyFilters({ date_to: e.target.value })}
                            className="h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40"
                            placeholder="Até"
                        />
                    </div>
                    <div className="mt-3 flex items-center justify-between gap-3 flex-wrap">
                        <label className="inline-flex items-center gap-2 text-white/70 text-xs cursor-pointer select-none">
                            <input
                                type="checkbox"
                                checked={!!f.include_resolved}
                                onChange={e => applyFilters({ include_resolved: e.target.checked ? '1' : '' })}
                                className="w-3.5 h-3.5 accent-ecf-yellow cursor-pointer"
                            />
                            Incluir resolvidos na listagem
                        </label>
                        {hasAnyFilter && (
                            <button onClick={clearFilters} className="text-white/50 hover:text-white text-xs underline">
                                Limpar filtros
                            </button>
                        )}
                    </div>
                </div>
            )}

            {/* Aviso de resolvidos ocultos (só quando nenhum status específico está filtrado) */}
            {!f.status && !f.include_resolved && (
                <p className="text-white/40 text-[11px] mb-3 -mt-1 px-1">
                    Sugadores <b className="text-emerald-300/80">resolvidos</b> estão ocultos. Use o filtro <b className="text-white/60">Incluir resolvidos</b> pra ver.
                </p>
            )}

            {/* Barra de bulk actions — aparece quando há seleção. Sticky pra
                não ficar invisível ao rolar tabela longa. */}
            {selectedIds.size > 0 && (
                <div className="sticky top-2 z-30 mb-3 flex items-center justify-between gap-3 px-4 py-2.5 rounded-xl border border-ecf-yellow/30 bg-ecf-yellow/[0.08] backdrop-blur shadow-lg">
                    <div className="flex items-center gap-3 min-w-0">
                        <span className="inline-flex items-center justify-center min-w-[28px] h-7 px-2 rounded-full bg-ecf-yellow text-[#252525] text-[12px] font-bold">
                            {selectedIds.size}
                        </span>
                        <p className="text-white/90 text-[13px] truncate">
                            selecionado{selectedIds.size !== 1 ? 's' : ''}
                            {lockedCompany && (
                                <span className="text-white/50"> · empresa: <b className="text-white/80">{lockedCompany.name}</b></span>
                            )}
                        </p>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                        <button
                            type="button"
                            onClick={() => setBulkMoveOpen(true)}
                            className="inline-flex items-center gap-1.5 h-8 px-3 rounded-lg bg-ecf-yellow text-[#252525] hover:bg-ecf-yellow/90 text-[12px] font-bold"
                        >
                            <ArrowRightLeft size={12} />
                            Mover para SGI
                        </button>
                        <button
                            type="button"
                            onClick={clearSelection}
                            className="inline-flex items-center h-8 px-3 rounded-lg border border-white/[0.10] text-white/70 hover:text-white hover:bg-white/[0.05] text-[12px]"
                        >
                            Limpar
                        </button>
                    </div>
                </div>
            )}

            {/* Tabela */}
            <div className="card-ecf rounded-xl overflow-hidden">
                {list.length === 0 ? (
                    <div className="p-12 text-center">
                        <ListTree size={32} className="mx-auto text-white/20 mb-3" />
                        <p className="text-white/60 text-sm font-medium">Nenhum sugador encontrado.</p>
                        <p className="text-white/30 text-xs mt-1">
                            {hasAnyFilter
                                ? 'Tente limpar os filtros.'
                                : can_manage
                                    ? 'Configure thresholds em uma empresa e rode a análise.'
                                    : 'Aguarde a próxima análise diária.'}
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead className="bg-white/[0.02] border-b border-white/[0.06]">
                                <tr>
                                    <th className="w-10 px-3 py-3">
                                        <input
                                            type="checkbox"
                                            checked={allVisibleSelected}
                                            onChange={toggleAllVisible}
                                            disabled={eligibleVisibleCount === 0}
                                            className="w-3.5 h-3.5 accent-ecf-yellow cursor-pointer disabled:opacity-30"
                                            title={
                                                eligibleVisibleCount === 0
                                                    ? 'Nenhum adgroup elegível nesta página'
                                                    : allVisibleSelected ? 'Desmarcar visíveis' : 'Marcar elegíveis desta página'
                                            }
                                        />
                                    </th>
                                    <th className="text-left px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Empresa</th>
                                    <th className="text-left px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Tipo</th>
                                    <th className="text-left px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Nome / ID</th>
                                    <th className="text-right px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Investimento</th>
                                    <th className="text-right px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Vendas</th>
                                    <th className="text-right px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">CPC</th>
                                    <th className="text-right px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">ACOS</th>
                                    <th className="text-left px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Motivos</th>
                                    <th className="text-left px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Status</th>
                                    <th className="text-left px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Detectado</th>
                                    <th className="text-right px-4 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/50">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                {list.map(s => {
                                    const TipoIcon = TIPO_ICONS[s.tipo] || Tag;
                                    const isPendente = s.status === 'pendente';
                                    const hoje = isHoje(s.reference_date);
                                    const selectable = canSelect(s);
                                    const isSelected = selectedIds.has(s.id);
                                    const isLockedByOther = !selectable && lockedCompany && s.company?.id !== lockedCompany.id
                                        && s.tipo === 'adgroup' && s.status !== 'movido' && s.status !== 'resolvido';
                                    return (
                                        <tr
                                            key={s.id}
                                            onClick={() => router.visit(route('sugadores.show', s.id))}
                                            className={cn(
                                                'border-b border-white/[0.04] hover:bg-white/[0.04] transition-colors cursor-pointer',
                                                isPendente && 'bg-red-500/[0.02]',
                                                // Destaque adicional pra sugadores identificados HOJE — borda amarela à esquerda + bg sutil.
                                                hoje && 'bg-ecf-yellow/[0.04] border-l-2 border-l-ecf-yellow',
                                                isSelected && 'bg-ecf-yellow/[0.06] border-l-2 border-l-ecf-yellow'
                                            )}
                                        >
                                            <td className="w-10 px-3 py-3" onClick={e => e.stopPropagation()}>
                                                <input
                                                    type="checkbox"
                                                    checked={isSelected}
                                                    disabled={!selectable && !isSelected}
                                                    onChange={() => toggleOne(s.id)}
                                                    className="w-3.5 h-3.5 accent-ecf-yellow cursor-pointer disabled:opacity-25 disabled:cursor-not-allowed"
                                                    title={
                                                        s.tipo !== 'adgroup' ? 'Só adgroups podem ser movidos'
                                                            : s.status === 'movido' ? 'Já foi movido'
                                                            : s.status === 'resolvido' ? 'Já foi resolvido'
                                                            : isLockedByOther ? `Limpe a seleção atual (${lockedCompany.name}) para selecionar empresas diferentes`
                                                            : ''
                                                    }
                                                />
                                            </td>
                                            <td className="px-4 py-3 text-[13px] text-white/80">
                                                <div className="flex items-center gap-2">
                                                    <Building2 size={12} className="text-white/30 shrink-0" />
                                                    <span className="truncate max-w-[160px]">{s.company?.name || '—'}</span>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-1.5 text-[12px] text-white/70">
                                                    <TipoIcon size={12} className="text-white/40" />
                                                    {TIPO_LABELS[s.tipo] || s.tipo}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-[12px] text-white/70 max-w-[260px]">
                                                {s.tipo === 'campanha' ? (
                                                    <div className="truncate">{s.campaign_name || s.campaign_id}</div>
                                                ) : (
                                                    <div className="flex items-center gap-2 min-w-0">
                                                        {s.thumbnail ? (
                                                            <img
                                                                src={s.thumbnail}
                                                                alt=""
                                                                loading="lazy"
                                                                className="w-9 h-9 rounded-md object-cover bg-white/[0.05] border border-white/[0.06] shrink-0"
                                                                onError={(e) => { e.currentTarget.style.display = 'none'; }}
                                                            />
                                                        ) : (
                                                            <div className="w-9 h-9 rounded-md bg-white/[0.04] border border-white/[0.06] shrink-0 flex items-center justify-center">
                                                                <Tag size={12} className="text-white/30" />
                                                            </div>
                                                        )}
                                                        <div className="min-w-0">
                                                            <div className="truncate">{s.adgroup_name || s.adgroup_id || s.campaign_id}</div>
                                                            {(s.adgroup_type || s.catalog_listing) && (
                                                                <div className="text-[10px] text-white/40 mt-0.5">
                                                                    {s.catalog_listing ? 'Catálogo' : (s.adgroup_type || '').toLowerCase()}
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right text-[13px] text-white/80 font-medium tabular-nums">
                                                {fmtBRL(s.investimento_periodo)}
                                            </td>
                                            <td className="px-4 py-3 text-right text-[13px] text-white/80 tabular-nums">
                                                {fmtInt(s.vendas_periodo)}
                                            </td>
                                            <td className="px-4 py-3 text-right text-[12px] text-white/60 tabular-nums">
                                                {s.cpc_medio == null ? '—' : 'R$ ' + Number(s.cpc_medio).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
                                            </td>
                                            <td className="px-4 py-3 text-right text-[12px] text-white/60 tabular-nums">
                                                {fmtPct(s.acos)}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex flex-wrap gap-1 max-w-[200px]">
                                                    {(s.motivos || []).slice(0, 2).map(m => (
                                                        <MotivoBadge key={m} motivo={m} />
                                                    ))}
                                                    {(s.motivos || []).length > 2 && (
                                                        <span className="text-white/40 text-[10px] font-semibold">+{s.motivos.length - 2}</span>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <StatusBadge status={s.status} />
                                            </td>
                                            <td className="px-4 py-3 text-[12px] text-white/50">
                                                <div className="flex items-center gap-1.5">
                                                    {fmtDate(s.reference_date)}
                                                    {hoje && (
                                                        <span className="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider bg-ecf-yellow text-ecf-bg">
                                                            HOJE
                                                        </span>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-right" onClick={e => e.stopPropagation()}>
                                                <div className="inline-flex items-center gap-1">
                                                    {isPendente && (
                                                        <button
                                                            onClick={() => setActionTarget(s)}
                                                            className="px-2 py-1 rounded text-[11px] font-semibold bg-ecf-yellow/15 text-ecf-yellow border border-ecf-yellow/30 hover:bg-ecf-yellow/25"
                                                        >
                                                            Agir
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {/* Paginação */}
                {meta.last_page > 1 && (
                    <div className="flex items-center justify-between px-4 py-3 border-t border-white/[0.04]">
                        <p className="text-white/50 text-xs">
                            Página {meta.current_page} de {meta.last_page} · {fmtInt(meta.total)} registro{meta.total !== 1 ? 's' : ''}
                        </p>
                        <div className="flex items-center gap-1">
                            {meta.links?.map((link, i) => {
                                if (!link.url) return null;
                                const labelPlain = link.label.replace(/<[^>]+>/g, '').trim();
                                const isPrev = link.label.includes('Previous') || link.label.includes('&laquo;');
                                const isNext = link.label.includes('Next')     || link.label.includes('&raquo;');
                                return (
                                    <Link
                                        key={i}
                                        href={link.url}
                                        preserveState
                                        preserveScroll
                                        className={cn(
                                            'inline-flex items-center justify-center min-w-[32px] h-8 px-2 rounded-lg text-[12px] font-semibold border',
                                            link.active
                                                ? 'bg-ecf-yellow/15 text-ecf-yellow border-ecf-yellow/30'
                                                : 'text-white/60 hover:text-white border-white/[0.08] hover:bg-white/[0.05]'
                                        )}
                                    >
                                        {isPrev ? <ChevronLeft size={14} /> : isNext ? <ChevronRight size={14} /> : labelPlain}
                                    </Link>
                                );
                            })}
                        </div>
                    </div>
                )}
            </div>
                </>
            )}

            {actionTarget && (
                <StatusUpdateModal sugador={actionTarget} onClose={() => setActionTarget(null)} />
            )}

            {bulkMoveOpen && lockedCompany && (
                <MoveToSgiModal
                    company={lockedCompany}
                    sugadorIds={Array.from(selectedIds)}
                    countLabel={`${selectedIds.size} selecionado${selectedIds.size !== 1 ? 's' : ''}`}
                    onClose={() => setBulkMoveOpen(false)}
                    onSuccess={clearSelection}
                />
            )}

            {configPickerOpen && (
                <ConfigPickerModal companies={companies} onClose={() => setConfigPickerOpen(false)} />
            )}
        </AppLayout>
    );
}
