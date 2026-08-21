import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/Components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogDescription } from '@/Components/ui/dialog';
import { useForm, usePage, router } from '@inertiajs/react';
import { useState, useEffect, useMemo, useRef } from 'react';
import {
    Plus, Copy, CheckCircle,
    Briefcase, Users as UsersIcon, Building2, Eye,
    Link2, Search, ChevronDown, ArrowUp, ArrowDown,
    ArrowUpRight, ArrowDownRight, Clock, User,
    Calendar, Star, Trash2, ShieldCheck, X, AlertCircle, Info, Lock,
} from 'lucide-react';
import {
    ResponsiveContainer, LineChart, Line, XAxis, YAxis,
    Tooltip, CartesianGrid, AreaChart, Area, ReferenceArea, ReferenceLine,
} from 'recharts';
import { cn } from '@/lib/utils';

/* ═══════════════════════════════════════════════════════════════════════
   Dashboard NPS — versão "Command Center · Glass" (2A).
   Redesign 2026-07-08 baseado no bundle Claude Design + README dash nps.md.

   Preserva:
   - Contrato Inertia congelado (Phase 69-70-72): mes_filtro, filtros,
     companies, estrategistas, analistas, cards, serie_12m, surveys.
   - Escopo por carteira server-side (analista/estrategista já filtrados).
   - Modais existentes: Gerar Link Manual, Link Gerado, Ver Respostas.
   - Fluxo submit v15 (POST nps.generate).

   Novo:
   - Layout dark com orbs de luz difusas atrás do conteúdo (4 orbs
     posicionadas: azul topo direito, rosa direita, laranja esquerda,
     amarelo bottom).
   - Ação estampada: faixa de pendentes/expirando com CTA "Cobrar todos"
     (endpoint por definir — hoje só visual + copy para o clipboard).
   - Cards de média com sparkline (últimos 6 meses de serie_12m) + delta
     vs. mês anterior + total/pendentes.
   - Line chart com legenda-toggle (3 pills que ligam/desligam linhas).
   - Tabela com chips de filtro por status (client-side) + 7 colunas
     compactas + ordenação por Empresa/Data.

   Padrão de nota: sempre 2 casas decimais (4.00, 4.25, 3.67).
   ═══════════════════════════════════════════════════════════════════════ */

// ═══ Design tokens ═══════════════════════════════════════════════════════
// 2026-07-16 · aposentado o gradiente arco-íris GRAD_ECF (azul→rosa→laranja→
// amarelo). O design system usa acento ÚNICO da marca (ecf-yellow) para ação
// primária e um destaque sóbrio por-status nos filtros — nada de arco-íris.
const ACCENT = '#ffe600';             // ecf-yellow — ação primária / marca
const COL_ESTRATEGISTA = '#facc15';   // amarelo (marca)
const COL_ESTRATEGISTA_LINE = '#ffb020';
const COL_ANALISTA     = '#19e06a';
const COL_EMPRESA      = '#60a5fa';
const COL_ATENCAO      = '#ff9a5a';

// rgba a partir de hex (#rgb/#rrggbb) + alpha — para tints sóbrios de estado.
function hexAlpha(hex, a) {
    if (typeof hex !== 'string') return `rgba(255,255,255,${a})`;
    let h = hex.replace('#', '');
    if (h.length === 3) h = h.split('').map(c => c + c).join('');
    const n = parseInt(h, 16);
    const r = (n >> 16) & 255, g = (n >> 8) & 255, b = n & 255;
    return `rgba(${r},${g},${b},${a})`;
}

// Faixas semânticas (escala 1..5).
function scoreColor(n) {
    if (n == null || Number.isNaN(Number(n))) return 'rgba(255,255,255,0.3)';
    const v = Number(n);
    if (v >= 4.5) return '#19e06a';
    if (v >= 3.5) return '#9ee34f';
    if (v >= 2.5) return '#ffd23d';
    if (v >= 1.5) return '#ff8a3c';
    return '#f4436b';
}
function scoreBg(n) {
    const c = scoreColor(n);
    // Adiciona alpha ~14% para o chip.
    if (c.startsWith('rgba')) return 'rgba(255,255,255,0.06)';
    return c + '24';
}

// Padrão de nota: 2 casas decimais fixas ("4.00", "3.67").
function formatNota(v) {
    if (v === null || v === undefined) return null;
    const n = Number(v);
    if (Number.isNaN(n)) return String(v);
    return n.toFixed(2);
}

// Nota "ordenável" de uma linha da tabela: média das dimensões que têm nota
// (E · A · EMP). Usada pelo cabeçalho NOTAS pra trazer as mais altas/mais
// baixas ao topo. Linhas sem nota nenhuma (pendente/expirada) retornam null e
// são jogadas para o fim da ordenação, em qualquer direção.
function notaMediaRow(s) {
    const vals = [s.score_estrategista, s.score_analista, s.score_empresa]
        .map(Number)
        .filter(n => Number.isFinite(n) && n > 0);
    if (vals.length === 0) return null;
    return vals.reduce((a, b) => a + b, 0) / vals.length;
}

// Delta entre penúltimo e último mês (>0 sobe / <0 desce). Retorna null quando
// QUALQUER um dos dois meses não tem dado (média 0/NaN) — sem isso o delta virava
// "nota − 0" e mostrava o valor cheio (ex.: ↑4.96) como se fosse variação real.
function computeDelta(serie, key) {
    if (!Array.isArray(serie) || serie.length < 2) return null;
    const last = Number(serie[serie.length - 1]?.[key]);
    const prev = Number(serie[serie.length - 2]?.[key]);
    if (!(last > 0) || !(prev > 0)) return null;
    return last - prev;
}

// Sparkline: últimos N meses da série, no formato Recharts.
function sparkData(serie, key, months = 6) {
    if (!Array.isArray(serie)) return [];
    return serie.slice(-months).map((s, i) => ({ i, v: Number(s?.[key] ?? 0) }));
}

// Fase 95 (AB-95-2) · formata segundos brutos de `tempo_ate_resposta` em
// texto legível pt-BR pra seção Auditoria — nunca exibe segundos crus.
function fmtDuracao(segundos) {
    if (segundos === null || segundos === undefined) return '—';
    const s = Number(segundos);
    if (Number.isNaN(s)) return '—';
    if (s < 60) return `${Math.round(s)}s`;
    const min = Math.floor(s / 60);
    const rest = Math.round(s % 60);
    return `${min}min ${rest}s`;
}

// Dias entre hoje e uma data "dd/mm/yyyy" (formato do backend). Retorna número
// (positivo = falta, negativo = passou).
function daysUntil(dateStr) {
    if (!dateStr || typeof dateStr !== 'string') return null;
    const m = dateStr.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if (!m) return null;
    const target = new Date(Number(m[3]), Number(m[2]) - 1, Number(m[1]));
    target.setHours(0, 0, 0, 0);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return Math.round((target - today) / 86400000);
}

// ═══ Sentiment shared (usado nos modais) ═════════════════════════════════
const SENTIMENT_TEXT = ['text-rose-400', 'text-orange-400', 'text-amber-400', 'text-lime-400', 'text-emerald-400'];
const SENTIMENT_RING = ['border-rose-500/30 bg-rose-500/10', 'border-orange-500/30 bg-orange-500/10', 'border-amber-500/30 bg-amber-500/10', 'border-lime-500/30 bg-lime-500/10', 'border-emerald-500/30 bg-emerald-500/10'];
const sentClsFor = (peso, arr) => arr[Math.max(0, Math.min(4, (peso ?? 3) - 1))];

// Badges do peso (usado no modal "Ver respostas").
function PesoBadge({ peso }) {
    if (peso === null || peso === undefined) return null;
    return (
        <span className={cn(
            'inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[10.5px] font-semibold border font-mono',
            sentClsFor(peso, SENTIMENT_RING),
            sentClsFor(peso, SENTIMENT_TEXT),
        )}>
            = {peso}
        </span>
    );
}
function DimensaoBadge({ dimensao }) {
    const LABEL = { estrategista: 'Estrategista', analista: 'Analista', empresa: 'Empresa', geral: 'Geral' };
    const COR = {
        estrategista: 'bg-amber-500/15 text-amber-300 border-amber-500/25',
        analista:     'bg-emerald-500/15 text-emerald-300 border-emerald-500/25',
        empresa:      'bg-blue-500/15 text-blue-300 border-blue-500/25',
        geral:        'bg-white/[0.06] text-white/60 border-white/[0.10]',
    };
    if (!dimensao) return null;
    return (
        <span className={cn(
            'inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wider border',
            COR[dimensao] ?? COR.geral,
        )}>
            {LABEL[dimensao] ?? dimensao}
        </span>
    );
}
function RespostaExtraValor({ tipo, valor, peso }) {
    if (tipo === 'escala_1_5') {
        const n = parseInt(valor, 10);
        const cor = n <= 2 ? 'text-rose-400' : n === 3 ? 'text-yellow-400' : 'text-emerald-400';
        return <span className={cn('text-lg font-bold', cor)}>{n}/5</span>;
    }
    if (tipo === 'sim_nao') {
        return (
            <div className="inline-flex items-center gap-2">
                <span className={cn(
                    'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border',
                    valor === 'sim'
                        ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/25'
                        : 'bg-rose-500/15 text-rose-400 border-rose-500/25'
                )}>
                    {valor === 'sim' ? '✓ Sim' : '✗ Não'}
                </span>
                <PesoBadge peso={peso} />
            </div>
        );
    }
    if (tipo === 'multipla' || tipo === 'opcoes') {
        return (
            <div className="inline-flex items-center gap-2 flex-wrap">
                <span className={cn(
                    'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border',
                    peso != null
                        ? cn(sentClsFor(peso, SENTIMENT_RING), sentClsFor(peso, SENTIMENT_TEXT))
                        : 'bg-white/[0.06] border-white/[0.10] text-white/85',
                )}>
                    {valor}
                </span>
                <PesoBadge peso={peso} />
            </div>
        );
    }
    return (
        <p className="text-sm text-white/80 whitespace-pre-wrap break-words">
            {valor || <span className="text-white/30 italic">Não informado</span>}
        </p>
    );
}
// Quick task 260715-pu0 — prop `pessoas` opcional: mostra o NOME de quem
// recebeu aquela nota, lendo a atribuição congelada (Fase 79). `undefined`
// = dimensão sem pessoa (card Empresa, não renderiza nada); array vazio =
// resposta legada sem atribuição registrada (mostra "—", nunca o pivot
// vivo); 1+ nomes = join(', ') porque um template pode cobrir 2 serviços
// com responsáveis diferentes.
function NotaCard({ label, valor, pessoas }) {
    const temPessoas = pessoas !== undefined;
    const nomes = temPessoas ? (pessoas.length > 0 ? pessoas.join(', ') : '—') : null;

    if (valor === null || valor === undefined) {
        return (
            <div className="rounded-lg border border-white/[0.08] bg-white/[0.02] px-3 py-3 text-center">
                <p className="text-[10px] text-white/40 uppercase tracking-wide">{label}</p>
                <p className="text-2xl font-bold text-white/30 mt-1">—</p>
                {temPessoas && (
                    <p className="text-[11px] text-white/50 truncate mt-0.5" title={nomes}>{nomes}</p>
                )}
            </div>
        );
    }
    const n = Number(valor);
    return (
        <div className="rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-3 text-center min-w-0">
            <p className="text-[10px] text-white/40 uppercase tracking-wide truncate">{label}</p>
            <p className="text-2xl font-bold mt-1 leading-none" style={{ color: scoreColor(n) }}>
                {formatNota(valor)}<span className="text-sm text-white/40">/5</span>
            </p>
            {temPessoas && (
                <p className="text-[11px] text-white/50 truncate mt-1" title={nomes}>{nomes}</p>
            )}
        </div>
    );
}

// Fase 95 (AB-95-2) · par rótulo/valor da seção Auditoria — rótulos pt-BR
// claros, `title` só quando fornecido explicitamente (ex.: navegador truncado).
function AuditoriaField({ label, valor, title }) {
    return (
        <div className="min-w-0">
            <p className="text-[10px] text-white/40 uppercase tracking-wide">{label}</p>
            <p className="text-xs text-white/80 truncate" title={title}>{valor}</p>
        </div>
    );
}

// ═══ Sparkline — área de tendência (largura total, sem eixos) ════════════
// Herói do card: mostra o movimento real dos últimos meses. Domain [0,5] p/
// que meses zerados apareçam no piso (tendência "vinda de baixo").
function Sparkline({ data, color, gradientId, height = 46 }) {
    if (!data || data.length === 0) return <div style={{ width: '100%', height }} />;
    return (
        <div style={{ width: '100%', height }}>
            <ResponsiveContainer width="100%" height="100%">
                <AreaChart data={data} margin={{ top: 4, right: 3, bottom: 0, left: 0 }}>
                    <defs>
                        <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%"   stopColor={color} stopOpacity={0.38} />
                            <stop offset="100%" stopColor={color} stopOpacity={0}    />
                        </linearGradient>
                    </defs>
                    <YAxis hide domain={[0, 5]} />
                    <Area type="monotone" dataKey="v" stroke={color} strokeWidth={2}
                          fill={`url(#${gradientId})`} isAnimationActive={false}
                          dot={false} activeDot={false} />
                </AreaChart>
            </ResponsiveContainer>
        </div>
    );
}

// ═══ Componentes de filtro (pills de vidro) ══════════════════════════════
const glassPillStyle = (active) => ({
    display: 'inline-flex', alignItems: 'center', gap: 8,
    height: 38, padding: '0 13px', borderRadius: 11,
    background: active ? 'rgba(255,255,255,0.05)' : 'rgba(255,255,255,0.035)',
    backdropFilter: 'blur(16px)',
    WebkitBackdropFilter: 'blur(16px)',
    border: '1px solid ' + (active ? 'rgba(255,255,255,0.09)' : 'rgba(255,255,255,0.08)'),
    color: active ? '#eef' : 'rgba(255,255,255,0.6)',
    fontSize: 13, fontWeight: active ? 600 : 500,
    boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.06)',
    cursor: 'pointer',
});

function GlassSelect({ icon: Icon, active, value, onValueChange, placeholder, options, width }) {
    // 2026-07-20 · trunca o valor selecionado com ellipsis para nomes de modelo
    // longos ("NPS | Performance ECF Consultoria & Assessoria (principal)").
    // ATENÇÃO: o Radix Select.Value (v2.2) DESCARTA className/style do <span> que
    // renderiza — então o truncate precisa ser aplicado pelo trigger, mirando o
    // span filho: [&>span]:flex-1 [&>span]:min-w-0 [&>span]:truncate.
    // O chevron nativo do Radix é o último svg (após os filhos) → escondemos só
    // ele com [&>svg:last-child]:hidden, preservando o ícone e a seta customizada.
    return (
        <div style={{ ...glassPillStyle(!!active), padding: 0, paddingLeft: Icon ? 10 : 0, width, maxWidth: '100%', overflow: 'hidden' }}>
            <Select value={value} onValueChange={onValueChange}>
                <SelectTrigger
                    className="border-0 bg-transparent hover:bg-transparent focus:ring-0 focus-visible:ring-0 shadow-none h-full w-full min-w-0 px-3 gap-2 [&>svg:last-child]:hidden [&>span]:block [&>span]:flex-1 [&>span]:min-w-0 [&>span]:truncate [&>span]:text-left"
                    style={{ fontSize: 13, color: active ? '#eef' : 'rgba(255,255,255,0.6)', fontWeight: active ? 600 : 500 }}
                >
                    {Icon && <Icon size={15} style={{ color: 'rgba(255,255,255,0.5)', flexShrink: 0 }} />}
                    <SelectValue placeholder={placeholder} />
                    <ChevronDown size={13} style={{ color: 'rgba(255,255,255,0.4)', marginLeft: 4, flexShrink: 0 }} />
                </SelectTrigger>
                <SelectContent>
                    {options}
                </SelectContent>
            </Select>
        </div>
    );
}

// ═══ CicloAcao — encerrar / reabrir o ciclo do mês (spec 2026-08-14, item 2) ══
//
// O ciclo deixou de ser uma conta de datas e virou estado: um admin encerra
// quando quiser, e o encerramento vale mesmo antes da data automática. Fechado,
// o mês para de gerar link e para de aceitar resposta — inclusive de link já
// emitido e ainda dentro da validade.
//
// A confirmação mostra o mês E a referência, porque é o que a pessoa precisa
// conferir antes de encerrar: "Agosto/2026 — Ref. Julho/2026". Reabrir só
// aparece quando o fechamento foi MANUAL: mês vencido pela data não tem como
// ser reaberto pela tela, e oferecer o botão prometeria algo que não acontece.
function CicloAcao({ ciclo, competencia, coleta }) {
    const [enviando, setEnviando] = useState(false);
    if (!ciclo) return null;

    const rotulo = `${coleta || ciclo.mes} — Ref. ${competencia || '—'}`;

    const confirmarEEnviar = (rota, pergunta) => {
        if (enviando) return;
        if (!window.confirm(pergunta)) return;
        setEnviando(true);
        router.post(rota, { mes: ciclo.mes }, {
            preserveScroll: true,
            onFinish: () => setEnviando(false),
        });
    };

    if (ciclo.fechado) {
        return (
            <span className="inline-flex items-center gap-2">
                <span
                    style={{
                        display: 'inline-flex', alignItems: 'center', gap: 6, height: 36,
                        padding: '0 12px', borderRadius: 10, fontSize: 12.5, fontWeight: 600,
                        color: '#f4436b', background: 'rgba(244,67,107,0.10)',
                        border: '1px solid rgba(244,67,107,0.28)',
                    }}
                    title={ciclo.fechado_manual
                        ? 'Ciclo encerrado manualmente: não aceita novos links nem novas respostas.'
                        : 'A coleta deste mês já terminou pela data de encerramento.'}
                >
                    <Lock size={13} /> Ciclo fechado
                </span>
                {ciclo.fechado_manual && (
                    <button
                        type="button"
                        disabled={enviando}
                        onClick={() => confirmarEEnviar(
                            route('nps.ciclo.reabrir'),
                            `Reabrir o ciclo ${rotulo}?\n\nO mês volta a aceitar novos links e novas respostas.`,
                        )}
                        className="h-9 px-3 rounded-[10px] border border-white/[0.10] bg-white/[0.03] text-[12.5px] text-white/70 hover:bg-white/[0.06] disabled:opacity-50"
                    >
                        Reabrir
                    </button>
                )}
            </span>
        );
    }

    return (
        <button
            type="button"
            disabled={enviando}
            onClick={() => confirmarEEnviar(
                route('nps.ciclo.fechar'),
                `Encerrar o NPS ${rotulo}?\n\n`
                + '• Nenhum link novo poderá ser gerado deste ciclo\n'
                + '• Links já enviados param de aceitar resposta\n'
                + '• Quem não respondeu passa a contar nota 1',
            )}
            title={`Encerrar o ciclo ${rotulo}`}
            className="inline-flex items-center gap-1.5 h-9 px-3 rounded-[10px] border border-white/[0.10] bg-white/[0.03] text-[12.5px] text-white/75 hover:bg-white/[0.06] hover:border-white/[0.18] disabled:opacity-50"
        >
            <Lock size={13} /> Fechar NPS
        </button>
    );
}

// ═══ SearchableGlassSelect — select com CAMPO DE BUSCA (2026-07-20) ═══════
// O Radix Select puro (GlassSelect acima) não tem busca — com 150+ empresas
// vira uma lista rolável impossível de filtrar ("não dá pra pesquisar"). Este
// componente troca por um dropdown próprio: pill glass + painel com input de
// busca (substring, case-insensitive) + lista filtrada. Fecha ao clicar fora
// ou Esc; Enter seleciona o 1º resultado. `items` = [{ value, label }] (o 1º
// costuma ser a opção "Todas ...", que nunca é filtrada).
function SearchableGlassSelect({ icon: Icon, active, value, onValueChange, placeholder, items, width, searchPlaceholder = 'Buscar...', disabled = false }) {
    const [open, setOpen]   = useState(false);
    const [query, setQuery] = useState('');
    const wrapRef  = useRef(null);
    const inputRef = useRef(null);

    const selected      = items.find(i => i.value === value);
    const selectedLabel = selected?.label ?? placeholder;

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return items;
        return items.filter(i => i.label.toLowerCase().includes(q));
    }, [items, query]);

    // fecha ao clicar fora
    useEffect(() => {
        if (!open) return;
        const onDoc = (e) => { if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false); };
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, [open]);

    // ao abrir: limpa a busca e foca o input
    useEffect(() => {
        if (open) { setQuery(''); const t = setTimeout(() => inputRef.current?.focus(), 10); return () => clearTimeout(t); }
    }, [open]);

    const pick = (v) => { onValueChange(v); setOpen(false); };

    return (
        <div ref={wrapRef} style={{ position: 'relative', width, maxWidth: '100%' }}>
            <button
                type="button"
                disabled={disabled}
                onClick={() => { if (!disabled) setOpen(o => !o); }}
                style={{ ...glassPillStyle(!!active), width: '100%', padding: 0, paddingLeft: Icon ? 10 : 12, overflow: 'hidden', justifyContent: 'flex-start', opacity: disabled ? 0.5 : 1, cursor: disabled ? 'not-allowed' : 'pointer' }}
            >
                {Icon && <Icon size={15} style={{ color: 'rgba(255,255,255,0.5)', flexShrink: 0 }} />}
                <span style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', textAlign: 'left' }}>
                    {selectedLabel}
                </span>
                <ChevronDown size={13} style={{ color: 'rgba(255,255,255,0.4)', margin: '0 10px', flexShrink: 0 }} />
            </button>

            {open && (
                <div style={{
                    position: 'absolute', top: 'calc(100% + 6px)', left: 0, zIndex: 60,
                    width: '100%', minWidth: 260, maxWidth: '92vw',
                    background: '#12141a', border: '1px solid rgba(255,255,255,0.1)',
                    borderRadius: 11, boxShadow: '0 14px 44px rgba(0,0,0,0.55)', overflow: 'hidden',
                }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 10px', borderBottom: '1px solid rgba(255,255,255,0.07)' }}>
                        <Search size={14} style={{ color: 'rgba(255,255,255,0.4)', flexShrink: 0 }} />
                        <input
                            ref={inputRef}
                            value={query}
                            onChange={e => setQuery(e.target.value)}
                            onKeyDown={e => {
                                if (e.key === 'Escape') setOpen(false);
                                if (e.key === 'Enter' && filtered.length) { e.preventDefault(); pick(filtered[0].value); }
                            }}
                            placeholder={searchPlaceholder}
                            style={{ flex: 1, background: 'transparent', border: 0, outline: 'none', color: '#eef', fontSize: 13 }}
                        />
                    </div>
                    <div style={{ maxHeight: 300, overflowY: 'auto', padding: 4 }}>
                        {filtered.length === 0 ? (
                            <div style={{ padding: '10px 12px', color: 'rgba(255,255,255,0.4)', fontSize: 12 }}>Nada encontrado</div>
                        ) : filtered.map(i => {
                            const sel = i.value === value;
                            return (
                                <button
                                    key={i.value}
                                    type="button"
                                    onClick={() => pick(i.value)}
                                    style={{
                                        display: 'block', width: '100%', textAlign: 'left',
                                        padding: '7px 10px', borderRadius: 7, border: 0, cursor: 'pointer',
                                        background: sel ? 'rgba(255,255,255,0.08)' : 'transparent',
                                        color: sel ? '#fff' : 'rgba(255,255,255,0.75)',
                                        fontSize: 13, fontWeight: sel ? 600 : 500,
                                        whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis',
                                    }}
                                    onMouseEnter={e => { if (!sel) e.currentTarget.style.background = 'rgba(255,255,255,0.045)'; }}
                                    onMouseLeave={e => { if (!sel) e.currentTarget.style.background = 'transparent'; }}
                                >
                                    {i.label}
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}
        </div>
    );
}

// ═══ StatCard — card de média (layout aprovado 2026-07-16 v4) ════════════
// Ícone + label + "NPS MÉDIO"; pill de delta (↗/↘ +0.00) com "vs. mês anterior";
// nota grande branca /5; barra de progresso colorida pela dimensão (0..5, SEM
// meta); rodapé "X respondidas · Y pendentes" com ícones. Sem ranking.
function StatCard({ kicker, icon: Icon, color, valor, total, pendentes, delta, naoRespondidos = 0, janelaFechada = true }) {
    const val = formatNota(valor);
    const temNota = total > 0 && valor != null && Number(valor) > 0;
    const pct = temNota ? Math.max(3, Math.min(100, (Number(valor) / 5) * 100)) : 0;
    const semBase = delta == null;   // sem mês anterior com dado → estado neutro "—"
    const subiu = delta > 0, desceu = delta < 0;
    const deltaColor = subiu ? '#19e06a' : desceu ? '#ff8a3c' : 'rgba(255,255,255,0.45)';
    const deltaSinal = delta > 0 ? '+' : '';   // negativo já vem com '-'

    // Fase 116 · desde o Plan 116-03 `total` soma respostas reais + notas de
    // NPS não respondido (que contam 1 na média). O rodapé "respondidas"
    // mentia ao mostrar o `total` cheio — o número de respondidas de verdade
    // é total menos as que vieram do não respondido.
    const respondidas = Math.max(0, (total ?? 0) - (naoRespondidos ?? 0));

    return (
        <div style={{
            padding: 18, borderRadius: 14,
            background: '#101216',
            border: '1px solid rgba(255,255,255,0.07)',
            boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.04)',
            position: 'relative', overflow: 'hidden',
        }}>
            {/* Cabeçalho: ícone + (label / NPS MÉDIO) · delta + vs. mês anterior */}
            <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 10, position: 'relative' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10, minWidth: 0 }}>
                    <span style={{
                        width: 36, height: 36, borderRadius: 9,
                        background: `${color}1F`, border: `1px solid ${color}38`,
                        display: 'flex', alignItems: 'center', justifyContent: 'center', color,
                        flexShrink: 0,
                    }}>
                        <Icon size={18} strokeWidth={1.9} />
                    </span>
                    <div style={{ minWidth: 0 }}>
                        <div style={{ color, fontSize: 12, fontWeight: 800, letterSpacing: '0.09em' }}>{kicker}</div>
                        <div style={{ color: 'rgba(255,255,255,0.4)', fontSize: 9.5, fontWeight: 700, letterSpacing: '0.11em', marginTop: 2 }}>NPS MÉDIO</div>
                    </div>
                </div>
                {/* Delta vs. mês anterior — pill neutro "—" quando ainda não há
                    mês anterior com dado (não engana e o rótulo permanece). O
                    valor só aparece quando os DOIS meses têm base (computeDelta). */}
                <div style={{ textAlign: 'right', flexShrink: 0 }}>
                    <span style={{
                        display: 'inline-flex', alignItems: 'center', gap: 2,
                        height: 22, padding: '0 9px', borderRadius: 999,
                        fontSize: 12, fontWeight: 700,
                        color: deltaColor,
                        background: hexAlpha(deltaColor, 0.13),
                        border: '1px solid ' + hexAlpha(deltaColor, 0.28),
                    }}>
                        {semBase ? '—' : (
                            <>
                                {subiu ? <ArrowUpRight size={13} /> : desceu ? <ArrowDownRight size={13} /> : null}
                                {deltaSinal}{Math.abs(delta).toFixed(2)}
                            </>
                        )}
                    </span>
                    <div style={{ color: 'rgba(255,255,255,0.35)', fontSize: 10.5, marginTop: 5 }}>vs. mês anterior</div>
                </div>
            </div>

            {/* Nota grande branca + / 5 */}
            <div style={{ display: 'flex', alignItems: 'flex-end', gap: 7, marginTop: 14, position: 'relative' }}>
                <span style={{
                    fontFamily: "'Space Grotesk', sans-serif", fontWeight: 700, fontSize: 40,
                    lineHeight: 0.85, color: temNota ? 'rgba(255,255,255,0.96)' : 'rgba(255,255,255,0.25)',
                }}>
                    {val ?? '—'}
                </span>
                <span style={{ color: 'rgba(255,255,255,0.35)', fontSize: 16, fontWeight: 600, marginBottom: 5 }}>/ 5</span>
            </div>

            {/* Barra de progresso colorida pela dimensão (0..5, sem meta) */}
            <div style={{ height: 8, borderRadius: 999, background: 'rgba(255,255,255,0.08)', marginTop: 13, overflow: 'hidden', position: 'relative' }}>
                <div style={{
                    height: '100%', width: `${pct}%`, borderRadius: 999,
                    background: `linear-gradient(90deg, ${hexAlpha(color, 0.75)}, ${color})`,
                    transition: 'width .4s ease',
                }} />
            </div>

            {/* Rodapé: respondidas · sem resposta (contam 1) · pendentes (com ícones) */}
            <div style={{ display: 'flex', alignItems: 'center', gap: 18, rowGap: 6, marginTop: 13, position: 'relative', flexWrap: 'wrap' }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, color: 'rgba(255,255,255,0.55)', fontSize: 12 }}>
                    <User size={13} style={{ color: 'rgba(255,255,255,0.35)' }} />
                    {respondidas} respondida{respondidas === 1 ? '' : 's'}
                </span>
                {/* Fase 116 · só aparece quando existe pelo menos 1 nota vinda de
                    não respondido — nunca mostra "0 sem resposta". */}
                {/* 2026-08-14 · com a coleta AINDA ABERTA o não respondido não
                    entra na média (mesma régua do bônus) — o contador vira
                    aviso de prazo, não penalidade já aplicada. */}
                {naoRespondidos > 0 && (
                    <span
                        style={{ display: 'inline-flex', alignItems: 'center', gap: 6, color: COL_ATENCAO, fontSize: 12 }}
                        title={janelaFechada
                            ? 'A coleta do mês encerrou: quem não respondeu entra na média com nota 1.'
                            : 'A coleta ainda está aberta: quem não respondeu fica FORA da média até o fim do mês.'}
                    >
                        <AlertCircle size={13} style={{ color: COL_ATENCAO }} />
                        {naoRespondidos} sem resposta {janelaFechada
                            ? `(conta${naoRespondidos === 1 ? '' : 'm'} 1)`
                            : '(ainda no prazo)'}
                    </span>
                )}
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, color: 'rgba(255,255,255,0.55)', fontSize: 12 }}>
                    <Clock size={13} style={{ color: COL_ATENCAO }} />
                    <span style={{ color: pendentes > 0 ? COL_ATENCAO : 'rgba(255,255,255,0.55)' }}>{pendentes} pendente{pendentes === 1 ? '' : 's'}</span>
                </span>
            </div>
        </div>
    );
}

// ═══ Tooltip do gráfico — dark, com swatch por série e nota em 2 casas ════
// (Redesign 2026-07-16 · substitui o tooltip default; ordena por valor desc,
// cor só no swatch, valor em tinta clara — legível no tema dark.)
function NpsTooltip({ active, payload, label }) {
    if (!active || !Array.isArray(payload) || payload.length === 0) return null;
    const rows = payload
        .filter(p => p.value != null)
        .sort((a, b) => (b.value ?? 0) - (a.value ?? 0));
    if (rows.length === 0) return null;
    return (
        <div style={{
            background: '#171922', border: '1px solid rgba(255,255,255,0.08)',
            borderRadius: 9, padding: '9px 11px',
            boxShadow: '0 14px 34px -14px rgba(0,0,0,0.85)',
        }}>
            {/* 2026-08-14 — o eixo mostra o mês da COLETA; aqui vai junto o mês
                a que aquela coleta se refere (o anterior), para o ponto do
                gráfico não ser lido como se fosse do próprio mês. */}
            <div style={{ color: 'rgba(255,255,255,0.55)', fontSize: 11, fontWeight: 600, marginBottom: 7 }}>
                {label}
                {payload[0]?.payload?.competencia_label && (
                    <span style={{ color: 'rgba(255,255,255,0.35)', fontWeight: 500 }}>
                        {' '}· ref. {payload[0].payload.competencia_label}
                    </span>
                )}
            </div>
            {rows.map(p => (
                <div key={p.dataKey} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12, lineHeight: '19px' }}>
                    <span style={{ width: 8, height: 8, borderRadius: 2, background: p.color || p.stroke, flexShrink: 0 }} />
                    <span style={{ color: 'rgba(255,255,255,0.6)', flex: 1, paddingRight: 14 }}>{p.name}</span>
                    <span style={{ color: 'rgba(255,255,255,0.92)', fontWeight: 700, fontFamily: "'Space Grotesk', sans-serif" }}>{formatNota(p.value)}</span>
                </div>
            ))}
        </div>
    );
}

// ═══ ChartCard — LineChart glass com legenda-toggle ══════════════════════
function ChartCard({ serie, cards, lines, setLines }) {
    const temDados = Array.isArray(serie) && serie.some(s => (s.estrategista ?? 0) > 0 || (s.analista ?? 0) > 0 || (s.empresa ?? 0) > 0);

    // Meses sem resposta chegam como 0 do backend e são plotados no PISO (eixo
    // começa em 0) — decisão de produto: em produção o histórico ainda não
    // existe, então a linha "vem de baixo" e sobe conforme os dados chegam.
    const data = Array.isArray(serie) ? serie : [];

    const legPill = (on) => ({
        display: 'inline-flex', alignItems: 'center', gap: 7,
        height: 30, padding: '0 12px', borderRadius: 999,
        border: '1px solid ' + (on ? 'rgba(255,255,255,0.14)' : 'rgba(255,255,255,0.06)'),
        background: on ? 'rgba(255,255,255,0.06)' : 'rgba(255,255,255,0.02)',
        color: on ? '#eef' : 'rgba(255,255,255,0.35)',
        fontSize: 12, fontWeight: 600, cursor: 'pointer',
    });
    // Swatch da legenda: traço nítido, sem halo/glow (opacidade cai quando off).
    const legDot = (color, on) => ({
        width: 9, height: 3, borderRadius: 2, background: color, opacity: on ? 1 : 0.4,
    });

    return (
        <div style={{
            padding: '20px 22px', borderRadius: 20,
            background: 'rgba(255,255,255,0.03)',
            backdropFilter: 'blur(22px)', WebkitBackdropFilter: 'blur(22px)',
            border: '1px solid rgba(255,255,255,0.09)',
            boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.07), 0 24px 60px -40px rgba(0,0,0,0.9)',
        }}>
            <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', marginBottom: 6, gap: 14, flexWrap: 'wrap' }}>
                <div>
                    <div style={{ color: 'rgba(255,255,255,0.42)', fontSize: 11, fontWeight: 700, letterSpacing: '0.14em' }}>
                        VARIAÇÃO 12 MESES
                    </div>
                    <div style={{ color: '#f4f4f5', fontFamily: "'Space Grotesk', sans-serif", fontWeight: 700, fontSize: 20, marginTop: 4 }}>
                        Média NPS por mês
                    </div>
                </div>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    <button type="button" onClick={() => setLines(v => ({ ...v, est: !v.est }))} style={legPill(lines.est)}>
                        <span style={legDot(COL_ESTRATEGISTA_LINE, lines.est)} />
                        Estrategista <span style={{ opacity: 0.7 }}>{formatNota(cards.estrategista?.media) ?? '—'}</span>
                    </button>
                    <button type="button" onClick={() => setLines(v => ({ ...v, ana: !v.ana }))} style={legPill(lines.ana)}>
                        <span style={legDot(COL_ANALISTA, lines.ana)} />
                        Analista <span style={{ opacity: 0.7 }}>{formatNota(cards.analista?.media) ?? '—'}</span>
                    </button>
                    <button type="button" onClick={() => setLines(v => ({ ...v, emp: !v.emp }))} style={legPill(lines.emp)}>
                        <span style={legDot(COL_EMPRESA, lines.emp)} />
                        Empresa <span style={{ opacity: 0.7 }}>{formatNota(cards.empresa?.media) ?? '—'}</span>
                    </button>
                </div>
            </div>
            <div style={{ height: 250, marginTop: 8 }}>
                {!temDados ? (
                    <div className="h-full flex items-center justify-center">
                        <p style={{ color: 'rgba(255,255,255,0.25)' }}>Sem respostas nos últimos 12 meses</p>
                    </div>
                ) : (
                    <ResponsiveContainer width="100%" height="100%">
                        <LineChart data={data} margin={{ top: 10, right: 16, left: 0, bottom: 4 }}>
                            {/* Banda de meta (zona "bom" 4..5) — referência sutil. */}
                            <ReferenceArea y1={4} y2={5} fill="rgba(25,224,106,0.05)" strokeOpacity={0} />
                            <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.06)" vertical={false} />
                            <XAxis dataKey="mes" stroke="rgba(255,255,255,0.4)" fontSize={11} tickLine={false} axisLine={false} interval={0} tickMargin={8} />
                            <YAxis domain={[0, 5]} stroke="rgba(255,255,255,0.4)" fontSize={11} ticks={[0, 1, 2, 3, 4, 5]} tickLine={false} axisLine={false} width={26} />
                            {/* Linha de meta 4.0 — pontilhada, discreta. */}
                            <ReferenceLine y={4} stroke="rgba(255,255,255,0.16)" strokeDasharray="4 4"
                                label={{ value: 'meta 4.0', position: 'insideTopRight', fill: 'rgba(255,255,255,0.35)', fontSize: 10 }} />
                            <Tooltip content={<NpsTooltip />} cursor={{ stroke: 'rgba(255,255,255,0.14)', strokeWidth: 1 }} />
                            {/* Linhas nítidas, 2px, sem glow. activeDot com anel na cor da superfície. */}
                            {lines.est && (
                                <Line type="monotone" dataKey="estrategista" name="Estrategista" stroke={COL_ESTRATEGISTA_LINE} strokeWidth={2} dot={false} activeDot={{ r: 4, fill: COL_ESTRATEGISTA_LINE, stroke: '#0f1116', strokeWidth: 2 }} connectNulls isAnimationActive={false} />
                            )}
                            {lines.ana && (
                                <Line type="monotone" dataKey="analista" name="Analista" stroke={COL_ANALISTA} strokeWidth={2} dot={false} activeDot={{ r: 4, fill: COL_ANALISTA, stroke: '#0f1116', strokeWidth: 2 }} connectNulls isAnimationActive={false} />
                            )}
                            {lines.emp && (
                                <Line type="monotone" dataKey="empresa" name="Empresa" stroke={COL_EMPRESA} strokeWidth={2} dot={false} activeDot={{ r: 4, fill: COL_EMPRESA, stroke: '#0f1116', strokeWidth: 2 }} connectNulls isAnimationActive={false} />
                            )}
                        </LineChart>
                    </ResponsiveContainer>
                )}
            </div>
        </div>
    );
}

// ═══ TableCard — chips de status + tabela 7 colunas + paginação ══════════
const STATUS_LABEL = { pending: 'Pendente', completed: 'Respondido', expired: 'Expirado' };
const STATUS_COLOR = { pending: '#ff8a3c', completed: '#19e06a', expired: '#f4436b' };

// ═══ Fase 95 (AB-95-1) — badge tri-estado de confiança, admin-only ═══════
// Rótulos pt-BR simples exigidos pelo CONTEXT — nunca "flag"/"fraud score".
const CONFIANCA_LABELS = { confiavel: 'Confiável', atencao: 'Atenção', suspeita: 'Suspeita' };
const CONFIANCA_BADGE = {
    confiavel: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
    atencao:   'bg-amber-500/15 text-amber-300 border-amber-500/30',
    suspeita:  'bg-rose-500/15 text-rose-300 border-rose-500/30',
};
// Guard é a EXISTÊNCIA da prop `confianca` (não-admin nunca recebe a chave
// no payload — Fase 95-01), nunca `isAdmin &&` sobre dado já entregue.
function ConfiancaBadge({ confianca }) {
    if (!confianca) return null;
    return (
        <span
            className={cn('inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border', CONFIANCA_BADGE[confianca.status])}
            title={confianca.motivos?.join(' ') || undefined}
        >
            {CONFIANCA_LABELS[confianca.status]}
        </span>
    );
}

function TableCard({ surveys, contadores = {}, faltantes = [], activeStatus, setActiveStatus, sort, setSort, onOpenSurvey, onCopyLink, onGerarLink, onVerDetalheLink, isAdmin }) {
    // Bugfix 2026-07-16 · contagens vêm AGREGADAS do servidor (conjunto filtrado
    // inteiro), não mais recalculadas sobre surveys.data (só os 20 da página).
    // Os chips deixam de mudar de valor conforme a paginação.
    const contagens = {
        todos:     contadores.todos ?? 0,
        completed: contadores.respondidos ?? 0,
        pending:   contadores.pendentes ?? 0,
        expired:   contadores.expirados ?? 0,
        faltantes: contadores.faltantes ?? 0,
    };

    // 2026-07-20 · busca por nome da empresa DENTRO da lista carregada (mês
    // filtrado, todas as linhas numa página só). Antes a caixa "Buscar empresa"
    // da toolbar era um <div> decorativo — não dava pra digitar. Agora é um
    // input real que filtra client-side por substring, case-insensitive.
    const [buscaEmpresa, setBuscaEmpresa] = useState('');

    const filtrados = useMemo(() => {
        const list = surveys.data ?? [];
        const q = buscaEmpresa.trim().toLowerCase();
        let f = activeStatus === 'todos' ? list : list.filter(s => s.status === activeStatus);
        // 2026-08-20 · a linha de GRUPO tem o nome do grupo em company_name;
        // quem digita o nome de UMA das empresas cobertas (o caso real: a
        // pessoa procura "Chikweb", que só existe dentro do grupo MaxiGold)
        // precisa achar a linha do mesmo jeito.
        if (q) f = f.filter(s => (
            (s.company_name || '').toLowerCase().includes(q)
            || (s.empresas_nomes ?? []).some(n => (n || '').toLowerCase().includes(q))
        ));
        // Sort client-side por company/date
        const dir = sort.dir === 'asc' ? 1 : -1;
        return [...f].sort((a, b) => {
            if (sort.key === 'company') {
                return dir * (a.company_name || '').localeCompare(b.company_name || '');
            }
            if (sort.key === 'nota') {
                const na = notaMediaRow(a);
                const nb = notaMediaRow(b);
                // Linhas sem nota (pendente/expirada) sempre no fim, em qualquer direção.
                if (na === null && nb === null) return 0;
                if (na === null) return 1;
                if (nb === null) return -1;
                return dir * (na - nb);
            }
            // por data (created_at "dd/mm/yyyy hh:mm" — comparação de string bem-formada funciona invertido)
            return dir * (a.created_at || '').localeCompare(b.created_at || '');
        });
    }, [surveys.data, activeStatus, sort, buscaEmpresa]);

    // ─── Seleção para exclusão em massa (admin) ──────────────────────────────
    // 2026-07-13 · Set de ids selecionados. Reseta ao trocar de filtro ou de
    // página (surveys.data muda) pra não excluir algo fora da vista.
    const [selected, setSelected] = useState(new Set());
    useEffect(() => { setSelected(new Set()); }, [activeStatus, surveys.data]);

    // 2026-08-20 · a exclusão em massa fala com `nps_surveys` (ids numéricos).
    // Linha de grupo (`tipo === 'grupo'`, id textual "grupo-N") nunca entra na
    // seleção — a tela também não renderiza checkbox nem lixeira para ela.
    const visibleIds = useMemo(
        () => filtrados.filter(s => s.tipo !== 'grupo').map(s => s.id),
        [filtrados]
    );
    const allSelected = visibleIds.length > 0 && visibleIds.every(id => selected.has(id));

    const toggleOne = (id) => setSelected(prev => {
        const n = new Set(prev);
        n.has(id) ? n.delete(id) : n.add(id);
        return n;
    });
    const toggleAll = () => setSelected(allSelected ? new Set() : new Set(visibleIds));

    const deleteOne = (s) => {
        if (!confirm(`Excluir a pesquisa NPS de "${s.company_name}"? Esta ação é permanente e não pode ser desfeita.`)) return;
        router.delete(route('nps.surveys.destroy', s.id), { preserveScroll: true });
    };
    const deleteBulk = () => {
        const ids = [...selected];
        if (ids.length === 0) return;
        if (!confirm(`Excluir ${ids.length} pesquisa${ids.length === 1 ? '' : 's'} NPS selecionada${ids.length === 1 ? '' : 's'}? Esta ação é permanente e não pode ser desfeita.`)) return;
        router.delete(route('nps.surveys.bulk-destroy'), {
            data: { ids },
            preserveScroll: true,
            onSuccess: () => setSelected(new Set()),
        });
    };

    const checkboxStyle = { accentColor: '#e84393', width: 15, height: 15, cursor: 'pointer', flexShrink: 0 };

    // Destaque sóbrio por-status: o chip ativo adota a cor semântica do seu
    // status (Todos = acento da marca) como tint suave — acento único, não o
    // antigo gradiente arco-íris.
    const CHIP_ACCENT = { todos: ACCENT, completed: '#19e06a', pending: '#ff8a3c', expired: '#f4436b', faltantes: '#5b8def' };
    const chipStyle = (key) => {
        const active = activeStatus === key;
        const accent = CHIP_ACCENT[key] ?? ACCENT;
        return {
            display: 'inline-flex', alignItems: 'center', gap: 7,
            height: 32, padding: '0 13px', borderRadius: 999,
            border: '1px solid ' + (active ? hexAlpha(accent, 0.45) : 'rgba(255,255,255,0.08)'),
            background: active ? hexAlpha(accent, 0.14) : 'rgba(255,255,255,0.03)',
            color: active ? accent : 'rgba(255,255,255,0.6)',
            fontSize: 12.5, fontWeight: 600, cursor: 'pointer',
            transition: 'all .18s ease',
        };
    };

    const toggleSort = (key) => {
        setSort(s => s.key === key ? { key, dir: s.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: 'asc' });
    };

    // Admin ganha uma coluna de checkbox no início e a coluna de ação mais
    // larga (2 botões: copiar/ver + excluir).
    // 2026-07-20 · coluna MODELO adicionada após EMPRESA (distingue ML/Performance
    // vs Shopee em empresas multi-serviço).
    const gridCols = isAdmin
        ? '32px 1.4fr 1fr 1.3fr 1.1fr 1.5fr 1.1fr 1fr 78px'
        : '1.4fr 1fr 1.3fr 1.1fr 1.6fr 1.1fr 1fr 60px';
    // Largura mínima da grade: abaixo disso as colunas esmagavam texto/chips.
    // O wrapper rola horizontalmente em telas estreitas em vez de quebrar.
    const gridMinWidth = isAdmin ? 1050 : 930;

    return (
        <div style={{
            borderRadius: 20,
            background: 'rgba(255,255,255,0.03)',
            backdropFilter: 'blur(22px)', WebkitBackdropFilter: 'blur(22px)',
            border: '1px solid rgba(255,255,255,0.09)',
            boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.07), 0 24px 60px -40px rgba(0,0,0,0.9)',
            overflow: 'hidden',
        }}>
            {/* Toolbar */}
            <div style={{
                display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 14,
                padding: '16px 18px', borderBottom: '1px solid rgba(255,255,255,0.06)',
                flexWrap: 'wrap',
            }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                    <button type="button" onClick={() => setActiveStatus('todos')} style={chipStyle('todos')}>
                        Todos <span style={{ opacity: 0.6, fontWeight: 700 }}>{contagens.todos}</span>
                    </button>
                    <button type="button" onClick={() => setActiveStatus('completed')} style={chipStyle('completed')}>
                        Respondidos <span style={{ opacity: 0.6, fontWeight: 700 }}>{contagens.completed}</span>
                    </button>
                    <button type="button" onClick={() => setActiveStatus('pending')} style={chipStyle('pending')}>
                        Pendentes <span style={{ opacity: 0.6, fontWeight: 700 }}>{contagens.pending}</span>
                    </button>
                    <button type="button" onClick={() => setActiveStatus('expired')} style={chipStyle('expired')}>
                        Expirados <span style={{ opacity: 0.6, fontWeight: 700 }}>{contagens.expired}</span>
                    </button>
                    {/* 2026-07-20 · Faltantes = empresas elegíveis SEM nenhum link
                        no mês (não é filtro sobre surveys — troca a vista pela
                        lista de empresas). Título explica pra evitar jargão. */}
                    <button
                        type="button"
                        onClick={() => setActiveStatus('faltantes')}
                        style={chipStyle('faltantes')}
                        title="Empresas que ainda não tiveram link de NPS gerado neste mês"
                    >
                        Faltantes <span style={{ opacity: 0.6, fontWeight: 700 }}>{contagens.faltantes}</span>
                    </button>
                </div>
                <div style={{
                    display: 'inline-flex', alignItems: 'center', gap: 8,
                    height: 32, padding: '0 12px', borderRadius: 10,
                    background: 'rgba(255,255,255,0.03)',
                    border: '1px solid rgba(255,255,255,0.08)',
                    color: 'rgba(255,255,255,0.4)', fontSize: 12.5,
                }}>
                    <Search size={14} style={{ flexShrink: 0 }} />
                    <input
                        value={buscaEmpresa}
                        onChange={e => setBuscaEmpresa(e.target.value)}
                        placeholder="Buscar empresa"
                        style={{
                            background: 'transparent', border: 0, outline: 'none',
                            color: '#eef', fontSize: 12.5, width: 150,
                        }}
                    />
                    {buscaEmpresa && (
                        <button
                            type="button"
                            onClick={() => setBuscaEmpresa('')}
                            title="Limpar busca"
                            style={{ background: 'transparent', border: 0, color: 'rgba(255,255,255,0.4)', cursor: 'pointer', padding: 0, lineHeight: 0 }}
                        >
                            <X size={13} />
                        </button>
                    )}
                </div>
            </div>

            {/* Barra de exclusão em massa (admin, aparece com ≥1 selecionada) */}
            {isAdmin && selected.size > 0 && (
                <div style={{
                    display: 'flex', alignItems: 'center', gap: 12,
                    padding: '10px 18px', borderBottom: '1px solid rgba(255,255,255,0.06)',
                    background: 'rgba(244,67,107,0.07)',
                }}>
                    <span style={{ color: '#fff', fontSize: 12.5, fontWeight: 600 }}>
                        {selected.size} selecionada{selected.size === 1 ? '' : 's'}
                    </span>
                    <button
                        type="button"
                        onClick={deleteBulk}
                        style={{
                            display: 'inline-flex', alignItems: 'center', gap: 6,
                            height: 30, padding: '0 12px', borderRadius: 8,
                            border: '1px solid rgba(244,67,107,0.35)',
                            background: 'rgba(244,67,107,0.18)', color: '#ffb3c4',
                            fontSize: 12.5, fontWeight: 600, cursor: 'pointer',
                        }}
                    >
                        <Trash2 size={13} /> Excluir selecionadas
                    </button>
                    <button
                        type="button"
                        onClick={() => setSelected(new Set())}
                        style={{
                            height: 30, padding: '0 12px', borderRadius: 8,
                            border: '1px solid rgba(255,255,255,0.08)',
                            background: 'rgba(255,255,255,0.03)', color: 'rgba(255,255,255,0.6)',
                            fontSize: 12.5, fontWeight: 500, cursor: 'pointer',
                        }}
                    >
                        Limpar
                    </button>
                </div>
            )}

            {/* 2026-07-20 · o chip "Faltantes" troca a vista inteira: em vez das
                pesquisas do mês, mostra a lista de EMPRESAS elegíveis que ainda
                não receberam link neste mês (dado vem pronto do servidor, já
                respeitando os filtros de pessoa/empresa). */}
            {activeStatus === 'faltantes' ? (
                <FaltantesView faltantes={faltantes} onGerarLink={onGerarLink} contadores={contadores} />
            ) : (
              <>
            {/* Área rolável (header + linhas) — rola na horizontal em telas
                estreitas E na vertical: todas as pesquisas do mês numa página só,
                a lista desce DENTRO do card (maxHeight) sem crescer a página.
                Header e linhas compartilham a mesma minWidth p/ ficarem alinhados. */}
            <div style={{ overflowX: 'auto', overflowY: 'auto', maxHeight: '60vh' }}>
            {/* Header da grid — sticky no topo da área rolável; fundo sólido p/ as
                linhas não aparecerem atrás dele ao descer. */}
            <div style={{
                display: 'grid', gridTemplateColumns: gridCols, gap: 12,
                minWidth: gridMinWidth,
                padding: '11px 18px', borderBottom: '1px solid rgba(255,255,255,0.06)',
                background: '#101218',
                position: 'sticky', top: 0, zIndex: 2,
                alignItems: 'center',
            }}>
                {isAdmin && (
                    <input
                        type="checkbox"
                        checked={allSelected}
                        onChange={toggleAll}
                        style={checkboxStyle}
                        title="Selecionar todas as pesquisas visíveis"
                    />
                )}
                <button type="button" onClick={() => toggleSort('company')} style={{
                    textAlign: 'left', background: 'none', border: 'none', padding: 0,
                    cursor: 'pointer', color: 'rgba(255,255,255,0.4)',
                    fontSize: 10.5, fontWeight: 700, letterSpacing: '0.08em',
                    display: 'inline-flex', alignItems: 'center', gap: 4,
                }}>
                    EMPRESA {sort.key === 'company' ? (sort.dir === 'asc' ? '↑' : '↓') : ''}
                </button>
                <span style={{ color: 'rgba(255,255,255,0.4)', fontSize: 10.5, fontWeight: 700, letterSpacing: '0.08em' }}>MODELO</span>
                <button type="button" onClick={() => toggleSort('nota')}
                    title="Ordenar pelas notas — 1 clique: mais baixas no topo · 2º clique: mais altas no topo"
                    style={{
                        textAlign: 'left', background: 'none', border: 'none', padding: 0,
                        cursor: 'pointer', color: 'rgba(255,255,255,0.4)',
                        fontSize: 10.5, fontWeight: 700, letterSpacing: '0.08em',
                        display: 'inline-flex', alignItems: 'center', gap: 4,
                    }}>
                    NOTAS (E · A · EMP) {sort.key === 'nota' ? (sort.dir === 'asc' ? '↑' : '↓') : ''}
                </button>
                <span style={{ color: 'rgba(255,255,255,0.4)', fontSize: 10.5, fontWeight: 700, letterSpacing: '0.08em' }}>RESPONDENTE</span>
                <span style={{ color: 'rgba(255,255,255,0.4)', fontSize: 10.5, fontWeight: 700, letterSpacing: '0.08em' }}>COMENTÁRIO</span>
                <button type="button" onClick={() => toggleSort('date')} style={{
                    textAlign: 'left', background: 'none', border: 'none', padding: 0,
                    cursor: 'pointer', color: 'rgba(255,255,255,0.4)',
                    fontSize: 10.5, fontWeight: 700, letterSpacing: '0.08em',
                    display: 'inline-flex', alignItems: 'center', gap: 4,
                }}>
                    DATA / PRAZO {sort.key === 'date' ? (sort.dir === 'asc' ? '↑' : '↓') : ''}
                </button>
                <span style={{ color: 'rgba(255,255,255,0.4)', fontSize: 10.5, fontWeight: 700, letterSpacing: '0.08em' }}>STATUS</span>
                <span style={{ color: 'rgba(255,255,255,0.4)', fontSize: 10.5, fontWeight: 700, letterSpacing: '0.08em', textAlign: 'right' }}>AÇÃO</span>
            </div>

            {/* Rows */}
            {filtrados.length === 0 ? (
                <div className="text-center py-10">
                    <Star className="h-8 w-8 mx-auto mb-2 opacity-30" />
                    <p style={{ color: 'rgba(255,255,255,0.4)', fontSize: 13 }}>Nenhuma pesquisa para o filtro atual</p>
                </div>
            ) : filtrados.map((s) => {
                const est = formatNota(s.score_estrategista);
                const ana = formatNota(s.score_analista);
                const emp = formatNota(s.score_empresa);
                const dias = daysUntil(s.expires_at);
                const prazoCol = s.status === 'pending'
                    ? (dias !== null && dias <= 3 ? '#ff6a2b' : 'rgba(255,255,255,0.4)')
                    : 'rgba(255,255,255,0.4)';
                const prazoText = s.status === 'pending'
                    ? (dias === null ? 'sem prazo' : dias < 0 ? `expirou há ${-dias}d` : `expira em ${dias}d`)
                    : s.status === 'completed' ? 'no prazo' : 'expirado';
                const statusCol = STATUS_COLOR[s.status] ?? '#888';

                // 2026-08-20 · linha de LINK DE GRUPO: 1 link, N empresas. Vem
                // de `nps_group_surveys` (o espelho por empresa só nasce na
                // resposta), então não tem empresa única, nota, respondente
                // nem id de survey para excluir.
                const ehGrupo = s.tipo === 'grupo';
                const nomesGrupo = ehGrupo ? (s.empresas_nomes ?? []).join(', ') : '';

                const rowSelected = selected.has(s.id);

                return (
                    <div key={s.id} style={{
                        display: 'grid', gridTemplateColumns: gridCols, gap: 12,
                        minWidth: gridMinWidth,
                        padding: '13px 18px', borderBottom: '1px solid rgba(255,255,255,0.04)',
                        alignItems: 'center',
                        background: rowSelected ? 'rgba(244,67,107,0.06)' : 'transparent',
                    }}>
                        {isAdmin && (ehGrupo
                            ? <span />
                            : (
                                <input
                                    type="checkbox"
                                    checked={rowSelected}
                                    onChange={() => toggleOne(s.id)}
                                    style={checkboxStyle}
                                />
                            )
                        )}
                        <div style={{ minWidth: 0 }}>
                            <div title={ehGrupo ? nomesGrupo : undefined} style={{
                                color: '#eef', fontSize: 13.5, fontWeight: 600,
                                whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis',
                            }}>{s.company_name}</div>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 5, marginTop: 4, flexWrap: 'wrap' }}>
                                <span style={{
                                    display: 'inline-flex', alignItems: 'center', height: 18, padding: '0 7px',
                                    borderRadius: 5,
                                    background: ehGrupo ? 'rgba(91,141,239,0.16)' : (s.auto_generated ? 'rgba(255,255,255,0.06)' : 'rgba(255,184,32,0.12)'),
                                    color: ehGrupo ? '#9cc0ff' : (s.auto_generated ? 'rgba(255,255,255,0.6)' : '#ffb020'),
                                    fontSize: 9.5, fontWeight: 700, letterSpacing: '0.04em',
                                }}>{ehGrupo ? 'GRUPO' : (s.auto_generated ? 'MENSAL' : 'MANUAL')}</span>
                                {ehGrupo && (
                                    <span title={nomesGrupo} style={{
                                        display: 'inline-flex', alignItems: 'center', height: 18, padding: '0 7px',
                                        borderRadius: 5, background: 'rgba(255,255,255,0.06)',
                                        color: 'rgba(255,255,255,0.6)', fontSize: 9.5, fontWeight: 700,
                                    }}>{s.empresas_count} EMPRESAS</span>
                                )}
                            </div>
                        </div>

                        {/* 2026-07-20 · Modelo (template) do NPS — distingue
                            ML/Performance vs Shopee em empresas multi-serviço. */}
                        <div style={{ minWidth: 0 }}>
                            {s.modelo ? (
                                <span title={s.modelo} style={{
                                    display: 'inline-block', maxWidth: '100%',
                                    padding: '3px 9px', borderRadius: 6,
                                    background: 'rgba(91,141,239,0.12)',
                                    border: '1px solid rgba(91,141,239,0.25)',
                                    color: '#9cc0ff', fontSize: 11.5, fontWeight: 600,
                                    whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis',
                                    verticalAlign: 'middle',
                                }}>{s.modelo}</span>
                            ) : (
                                <span style={{ color: 'rgba(255,255,255,0.3)', fontSize: 12 }}>—</span>
                            )}
                        </div>

                        <div style={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
                                <ChipNota v={s.score_estrategista} />
                                <ChipNota v={s.score_analista} />
                                <ChipNota v={s.score_empresa} />
                            </div>
                        </div>

                        <div style={{
                            color: 'rgba(255,255,255,0.62)', fontSize: 12.5,
                            whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis',
                        }}>{s.respondent || '—'}</div>

                        <div title={s.comment || ''} style={{
                            color: 'rgba(255,255,255,0.45)', fontSize: 12,
                            whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis',
                        }}>{s.comment || '—'}</div>

                        <div>
                            <div style={{ color: 'rgba(255,255,255,0.6)', fontSize: 12 }}>{s.created_at}</div>
                            <div style={{ color: prazoCol, fontSize: 10.5, fontWeight: 600, marginTop: 2 }}>{prazoText}</div>
                        </div>

                        <div>
                            <span style={{
                                display: 'inline-flex', alignItems: 'center', gap: 5,
                                height: 24, padding: '0 10px', borderRadius: 999,
                                background: `${statusCol}22`, color: statusCol,
                                fontSize: 11, fontWeight: 700,
                            }}>
                                <span style={{ width: 6, height: 6, borderRadius: '50%', background: 'currentColor' }} />
                                {STATUS_LABEL[s.status]}
                            </span>
                            {/* Fase 95 (AB-95-1) · s.confianca só existe no
                                payload pra admin (Fase 95-01) — a ausência
                                da chave já é o guard (nunca `isAdmin &&`). */}
                            {s.confianca && (
                                <div style={{ marginTop: 4 }}>
                                    <ConfiancaBadge confianca={s.confianca} />
                                </div>
                            )}
                            {/* Fase 96 (AB-96-3) · s.invalidada só existe no
                                payload pra admin — mesma blindagem acima. */}
                            {s.invalidada && (
                                <div style={{ marginTop: 4 }}>
                                    <span style={{
                                        display: 'inline-flex', alignItems: 'center',
                                        padding: '1px 8px', borderRadius: 999,
                                        background: 'rgba(148,163,184,0.15)', color: 'rgba(203,213,225,0.9)',
                                        fontSize: 10, fontWeight: 700, border: '1px solid rgba(148,163,184,0.3)',
                                    }}>
                                        Invalidada
                                    </span>
                                </div>
                            )}
                        </div>

                        <div style={{ display: 'flex', justifyContent: 'flex-end', alignItems: 'center', gap: 6 }}>
                            {s.status === 'pending' && (
                                <button
                                    type="button"
                                    onClick={() => onCopyLink(s.link)}
                                    style={{
                                        width: 30, height: 30, borderRadius: 8,
                                        border: '1px solid rgba(255,255,255,0.08)',
                                        background: 'rgba(255,255,255,0.03)',
                                        color: 'rgba(255,255,255,0.6)',
                                        cursor: 'pointer',
                                        display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
                                    }}
                                    title="Copiar link"
                                >
                                    <Link2 size={14} />
                                </button>
                            )}
                            {/* Quick task 260730-jzx (ajuste 2) — detalhe do
                                link pendente (endereço, quem gerou, quando,
                                validade, se é de grupo). O botão de copiar
                                acima continua como atalho rápido. */}
                            {s.status === 'pending' && (
                                <button
                                    type="button"
                                    onClick={() => onVerDetalheLink(s)}
                                    style={{
                                        width: 30, height: 30, borderRadius: 8,
                                        border: '1px solid rgba(255,255,255,0.08)',
                                        background: 'rgba(255,255,255,0.03)',
                                        color: 'rgba(255,255,255,0.6)',
                                        cursor: 'pointer',
                                        display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
                                    }}
                                    title="Ver detalhes do link"
                                >
                                    <Eye size={14} />
                                </button>
                            )}
                            {s.status === 'completed' && (
                                <button
                                    type="button"
                                    onClick={() => onOpenSurvey(s)}
                                    style={{
                                        width: 30, height: 30, borderRadius: 8,
                                        border: '1px solid rgba(255,255,255,0.08)',
                                        background: 'rgba(255,255,255,0.03)',
                                        color: 'rgba(255,255,255,0.6)',
                                        cursor: 'pointer',
                                        display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
                                    }}
                                    title="Ver respostas"
                                >
                                    <Eye size={14} />
                                </button>
                            )}
                            {/* Excluir a pesquisa inteira — admin, qualquer status
                                (inclusive pendente). Cascade no banco limpa respostas.
                                2026-08-20 · nunca em linha de GRUPO: a rota
                                apaga `nps_surveys` por id, e apagar o link de
                                grupo é outra operação (não existe hoje). */}
                            {isAdmin && !ehGrupo && (
                                <button
                                    type="button"
                                    onClick={() => deleteOne(s)}
                                    style={{
                                        width: 30, height: 30, borderRadius: 8,
                                        border: '1px solid rgba(244,67,107,0.22)',
                                        background: 'rgba(244,67,107,0.10)',
                                        color: '#f4436b',
                                        cursor: 'pointer',
                                        display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
                                    }}
                                    title="Excluir pesquisa"
                                >
                                    <Trash2 size={14} />
                                </button>
                            )}
                        </div>
                    </div>
                );
            })}
            </div>

            {/* Rodapé — total do conjunto (sem paginação: a lista inteira rola
                dentro do card). Com filtro de status ativo, mostra "X de Y". */}
            <div style={{
                display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                padding: '13px 18px', color: 'rgba(255,255,255,0.4)', fontSize: 12,
                borderTop: '1px solid rgba(255,255,255,0.05)',
            }}>
                <span>
                    {filtrados.length === (surveys.total ?? filtrados.length)
                        ? `${filtrados.length} pesquisa${filtrados.length === 1 ? '' : 's'}`
                        : `Mostrando ${filtrados.length} de ${surveys.total ?? filtrados.length}`}
                </span>
            </div>
              </>
            )}
        </div>
    );
}

// ═══ FaltantesView — empresas elegíveis SEM link de NPS no mês (2026-07-20) ══
// Renderizada no lugar da tabela quando o chip "Faltantes" está ativo. A lista
// já chega filtrada do servidor (escopo de carteira + pessoa/empresa). Cada
// linha oferece um atalho pra abrir o modal "Gerar link".
function FaltantesView({ faltantes = [], onGerarLink, contadores = {} }) {
    if (!faltantes.length) {
        return (
            <div style={{ padding: '48px 18px', textAlign: 'center' }}>
                <CheckCircle size={26} style={{ color: '#19e06a', marginBottom: 10 }} />
                <p style={{ color: 'rgba(255,255,255,0.6)', fontSize: 13 }}>
                    Nenhuma empresa faltante — todas as elegíveis já têm link de NPS neste mês.
                </p>
            </div>
        );
    }
    // Fase 119.1 Plan 07 (D1/D5) — resumo de quantas empresas já estão
    // contando nota 1 por falta de pesquisa/contato neste mês. Só aparece
    // quando existe pelo menos 1 (nunca "0 empresas...").
    const contamNota1 = contadores.contam_nota_1 ?? 0;
    return (
        <div>
            <div style={{
                display: 'flex', alignItems: 'center', gap: 8,
                padding: '12px 18px', borderBottom: '1px solid rgba(255,255,255,0.06)',
                color: 'rgba(255,255,255,0.6)', fontSize: 12.5,
            }}>
                <AlertCircle size={14} style={{ color: '#5b8def', flexShrink: 0 }} />
                <span>
                    <strong style={{ color: '#eef' }}>{faltantes.length}</strong> NPS ainda sem link neste mês (por empresa e modelo)
                </span>
            </div>
            {contamNota1 > 0 && (
                <div style={{
                    display: 'flex', alignItems: 'center', gap: 8,
                    padding: '10px 18px', borderBottom: '1px solid rgba(255,255,255,0.06)',
                    background: 'rgba(255,154,90,0.08)', color: '#ffb37a', fontSize: 12.5,
                }}>
                    <AlertCircle size={14} style={{ flexShrink: 0 }} />
                    <span>
                        <strong>{contamNota1}</strong> empresa{contamNota1 === 1 ? '' : 's'} estão contando nota 1 por não terem recebido pesquisa neste mês.
                    </span>
                </div>
            )}
            <div style={{ maxHeight: '60vh', overflowY: 'auto' }}>
                {faltantes.map((c) => {
                    // Pitfall Rollup do projeto — flags derivadas do item
                    // calculadas DENTRO do callback do .map(), nunca no
                    // escopo do componente (bundle de produção estoura
                    // ReferenceError se ficarem fora).
                    // Quick task 260730-jzx (ajuste 1) — o selo/texto sobre
                    // contato não cadastrado saiu da tela: o envio é
                    // manual, não depende de contato cadastrado. Como
                    // `motivo` não existe mais no payload, o único texto de
                    // estado agora é o de `conta_nota_1`.
                    const jaContaUm = c.conta_nota_1 === true;
                    // Quick task 260730-jzx (ajuste 3) — linha de GRUPO:
                    // várias empresas cuidadas pelas mesmas pessoas, 1 link
                    // só para todas.
                    const ehGrupo = c.tipo === 'grupo';
                    const nomesGrupo = ehGrupo ? (c.empresas_nomes ?? []).join(', ') : '';
                    return (
                        <div
                            key={ehGrupo ? `g-${c.group_id}-${c.template_id}` : `${c.company_id}-${c.template_id ?? 'x'}`}
                            style={{
                                display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12,
                                padding: '12px 18px', borderBottom: '1px solid rgba(255,255,255,0.04)',
                            }}
                        >
                            <div style={{ display: 'flex', flexDirection: 'column', gap: 4, minWidth: 0, flex: 1 }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 9, minWidth: 0, flexWrap: 'wrap' }}>
                                    <Building2 size={15} style={{ color: 'rgba(255,255,255,0.35)', flexShrink: 0 }} />
                                    <span style={{
                                        color: '#eef', fontSize: 13.5, fontWeight: 600,
                                        whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis',
                                    }}>{c.name}</span>
                                    {ehGrupo && (
                                        <span style={{
                                            flexShrink: 0, padding: '2px 8px', borderRadius: 6,
                                            background: 'rgba(255,255,255,0.08)',
                                            border: '1px solid rgba(255,255,255,0.16)',
                                            color: 'rgba(255,255,255,0.75)', fontSize: 11, fontWeight: 600,
                                            whiteSpace: 'nowrap',
                                        }}>{c.empresas_count} empresas</span>
                                    )}
                                    {c.modelo && (
                                        <span title={c.modelo} style={{
                                            flexShrink: 0, padding: '2px 8px', borderRadius: 6,
                                            background: 'rgba(91,141,239,0.12)',
                                            border: '1px solid rgba(91,141,239,0.25)',
                                            color: '#9cc0ff', fontSize: 11, fontWeight: 600,
                                            whiteSpace: 'nowrap',
                                        }}>{c.modelo}</span>
                                    )}
                                </div>
                                {ehGrupo && (
                                    <p title={nomesGrupo} style={{
                                        color: 'rgba(255,255,255,0.5)', fontSize: 11.5, marginLeft: 24,
                                        whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', maxWidth: 380,
                                    }}>{nomesGrupo}</p>
                                )}
                                <p style={{ color: 'rgba(255,255,255,0.5)', fontSize: 11.5, marginLeft: 24 }}>
                                    {ehGrupo
                                        ? `Um link só vale para as ${c.empresas_count} empresas — todas são cuidadas pelas mesmas pessoas.`
                                            + (c.conta_nota_1_count > 0 ? ` ${c.conta_nota_1_count} já estão contando nota 1 neste mês.` : '')
                                        : (jaContaUm
                                            ? 'Sem link neste mês — já está contando nota 1.'
                                            : 'Sem link neste mês — ainda dá tempo de enviar.')}
                                </p>
                            </div>
                            {onGerarLink && (
                                <button
                                    type="button"
                                    onClick={() => onGerarLink(ehGrupo ? { grupoId: c.group_id, templateId: c.template_id } : null)}
                                    style={{
                                        display: 'inline-flex', alignItems: 'center', gap: 6,
                                        height: 30, padding: '0 12px', borderRadius: 8,
                                        border: '1px solid rgba(91,141,239,0.35)',
                                        background: 'rgba(91,141,239,0.14)', color: '#9cc0ff',
                                        fontSize: 12, fontWeight: 600, cursor: 'pointer', flexShrink: 0,
                                    }}
                                    title={ehGrupo ? 'Gerar link do grupo' : 'Gerar link de NPS'}
                                >
                                    <Plus size={13} /> {ehGrupo ? 'Gerar link do grupo' : 'Gerar link'}
                                </button>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

// ═══ CoberturaPreview — cabeçalho "quem entra/quem fica de fora" (Fase
// 119.1 Plan 07) ══════════════════════════════════════════════════════════
// Renderiza os 5 estados do item 2 do contrato de UI. NENHUM fetch acontece
// aqui — quem busca é o efeito em NpsIndex; este componente só EXIBE.
function CoberturaPreview({ modo, carregando, cobertura }) {
    let corpo;

    if (modo === 'empresa') {
        // Estado "Empresa única, sem grupo" — nenhuma prévia é buscada.
        corpo = (
            <p className="text-sm text-white/60">
                Esta empresa não faz parte de nenhum grupo. O link vale só para ela.
            </p>
        );
    } else if (carregando) {
        corpo = <p className="text-sm text-white/50">Verificando as empresas do grupo...</p>;
    } else if (!cobertura) {
        corpo = null;
    } else {
        const incluidas = cobertura.incluidas ?? [];
        const excluidas = cobertura.excluidas ?? [];

        if (incluidas.length === 0) {
            // Grupo não qualificado — botão de gerar já fica desabilitado
            // no formulário (nenhuma incluída).
            corpo = (
                <div className="space-y-2">
                    <p className="text-sm text-amber-200">
                        Nenhuma empresa deste grupo pode receber este link agora. Veja o motivo de cada uma abaixo.
                    </p>
                    <MotivosExclusao excluidas={excluidas} />
                </div>
            );
        } else if (excluidas.length === 0) {
            // Grupo qualificado — nenhuma excluída.
            corpo = (
                <div className="space-y-1.5">
                    <p className="text-sm text-emerald-300">
                        Todas as {incluidas.length} empresas do grupo vão receber a nota que o cliente der.
                    </p>
                    <p className="text-xs text-white/50">{incluidas.map(e => e.name).join(', ')}</p>
                </div>
            );
        } else {
            // Grupo parcialmente qualificado — dois blocos.
            corpo = (
                <div className="space-y-2">
                    <div className="rounded-md border border-emerald-500/25 bg-emerald-500/10 p-2.5">
                        <p className="text-xs text-emerald-300 font-semibold mb-1">Vão receber a nota ({incluidas.length})</p>
                        <p className="text-xs text-white/60">{incluidas.map(e => e.name).join(', ')}</p>
                    </div>
                    <div className="rounded-md border border-amber-500/25 bg-amber-500/10 p-2.5">
                        <p className="text-xs text-amber-300 font-semibold mb-1.5">Não vão receber ({excluidas.length})</p>
                        <MotivosExclusao excluidas={excluidas} />
                    </div>
                </div>
            );
        }
    }

    return (
        <div className="rounded-lg border border-white/[0.08] bg-white/[0.02] p-3 space-y-2">
            <p className="text-xs text-white/40 uppercase tracking-wide font-semibold">Quem vai receber esta nota</p>
            {corpo}
        </div>
    );
}

// Uma linha por empresa excluída do grupo, com o motivo em português simples
// — os textos são EXATOS (contrato de UI item 2; `sem_responsavel` entrou em
// 2026-08-18, junto com a régua que deixa a empresa órfã de fora do link em
// vez de travar o grupo inteiro). Cada flag é derivada
// DENTRO do .map() (pitfall Rollup do projeto: variável de escopo do
// componente usada só dentro do callback some no bundle de produção).
function MotivosExclusao({ excluidas = [] }) {
    return (
        <ul className="space-y-1.5">
            {excluidas.map((e) => {
                const ehResponsavelDiferente = e.motivo === 'responsavel_diferente';
                const ehSemServicoContratado = e.motivo === 'sem_servico_contratado';
                const ehSemServicoEmComum    = e.motivo === 'sem_servico_em_comum';
                const ehJaTemLink            = e.motivo === 'ja_tem_link';
                const ehEmpresaInativa       = e.motivo === 'empresa_inativa';
                const ehSemResponsavel       = e.motivo === 'sem_responsavel';

                return (
                    <li key={e.company_id} className="text-xs text-amber-200/90 leading-relaxed">
                        <strong className="text-amber-100">{e.name}</strong>
                        {' — '}
                        {ehResponsavelDiferente && (
                            <>o {e.servico} desta empresa é cuidado por outra pessoa ({e.quem_cuida}). Ela continua com nota 1 no mês até você gerar um link só para ela.</>
                        )}
                        {ehSemServicoContratado && (
                            <>não tem {e.servico} contratado, então este modelo de pesquisa não se aplica a ela.</>
                        )}
                        {ehSemServicoEmComum && (
                            <>não tem nenhum serviço em comum com as outras empresas do grupo neste modelo de pesquisa.</>
                        )}
                        {ehJaTemLink && (
                            <>já tem um link deste modelo neste mês. Ela responde pelo link dela.</>
                        )}
                        {ehEmpresaInativa && (
                            <>está inativa no sistema.</>
                        )}
                        {ehSemResponsavel && (
                            <>ainda não tem estrategista nem analista atribuídos, então a nota dela não teria dono. Ela fica de fora até alguém ser vinculado no cadastro da empresa.</>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

// Mini chip de nota individual da tabela (E/A/EMP).
function ChipNota({ v }) {
    const val = formatNota(v);
    return (
        <span style={{
            minWidth: 40, textAlign: 'center', height: 22, lineHeight: '22px',
            padding: '0 6px',
            borderRadius: 6,
            fontSize: 11, fontWeight: 800, fontFamily: "'Space Grotesk', sans-serif",
            color: scoreColor(v),
            background: scoreBg(v),
        }}>
            {val ?? '—'}
        </span>
    );
}

// ═══════════════════════════════════════════════════════════════════════
// ═══ Page component
// ═══════════════════════════════════════════════════════════════════════
export default function NpsIndex({
    surveys,
    companies,
    estrategistas = [],
    analistas = [],
    templates = [],
    grupos = [],
    pode_filtrar_por_pessoa = false,
    pode_ver_confianca = false, // Fase 95 (AB-95-3) · ausente (não `false`) pra não-admin
    cards = {},
    contadores = {},
    faltantes = [],
    serie_12m = [],
    mes_filtro = '',
    // 2026-08-14 — `mes_filtro` continua sendo o mês de COLETA (é a chave do
    // `?mes=`). Estes dois vêm prontos do backend só para a UI dizer qual mês
    // está sendo AVALIADO: o NPS coletado em agosto avalia julho.
    competencia_filtro = '',
    coleta_filtro = '',
    // Spec 2026-08-14 (item 2) — { fechado, fechado_manual, mes } do ciclo
    // exibido. `null` blinda payload antigo em cache logo após o deploy.
    ciclo = null,
    // 2026-08-14 — true quando a coleta do mês exibido já encerrou. Só então
    // quem não respondeu entra na média com nota 1 (mesma régua do bônus).
    janela_fechada = true,
    filtros = {},
    principal_template_id = null,
    regra_nao_respondido = false,
}) {
    const { flash, auth } = usePage().props;
    const isAdmin = auth?.user?.role === 'admin';

    // ─── Modais ──────────────────────────────────────────────────────────
    const [open, setOpen] = useState(false);
    const [linkDialog, setLinkDialog] = useState(false);
    const [generatedLink, setGeneratedLink] = useState('');
    const [copied, setCopied] = useState(false);
    // Fase 119.1 Plan 07 — o flash pode trazer nps_link (gerado agora) OU
    // nps_link_existente (bloqueio de duplicidade, Plan 02/06). Reusa o MESMO
    // modal/estado — linkJaExistia só troca título/texto/cor (item 1 do
    // contrato de UI), nunca um terceiro modal.
    const [linkJaExistia, setLinkJaExistia] = useState(false);
    // 'empresa' | 'grupo' — alterna o alvo do link no modal "Gerar link"
    // (seletor de modo entra na Task 2 desta fase); usado aqui só para
    // escolher a frase certa no aviso de link já existente.
    const [modoGeracao, setModoGeracao] = useState('empresa');
    const [modalSurvey, setModalSurvey] = useState(null);
    // Quick task 260730-jzx (ajuste 2) — detalhe do link de um PENDENTE
    // (endereço, quem gerou, quando, validade, se é de grupo). `null` =
    // modal fechado; objeto = o item de `surveys.data` clicado.
    const [linkPendente, setLinkPendente] = useState(null);
    const [linkPendenteCopiado, setLinkPendenteCopiado] = useState(false);
    // Fase 96 (AB-96-3) — form de motivo da invalidação, aberto sob demanda
    // dentro do modal de detalhe. Reseta sempre que um survey diferente é
    // aberto (abrirModalSurvey abaixo).
    const [invalidarAberto, setInvalidarAberto] = useState(false);
    const [motivoInvalidar, setMotivoInvalidar] = useState('');
    const abrirModalSurvey = (s) => {
        setInvalidarAberto(false);
        setMotivoInvalidar('');
        setModalSurvey(s);
    };

    // ─── Client-side state ───────────────────────────────────────────────
    const [activeStatus, setActiveStatus] = useState('todos');
    const [lines, setLines] = useState({ est: true, ana: true, emp: true });
    const [sort, setSort] = useState({ key: 'date', dir: 'desc' });

    // Ajuste 2026-07-13 · admin pode escolher qual modelo NPS o link vai
    // usar. Vazio = auto-resolve pelo NpsTemplateService (comportamento
    // legacy). Só admin vê o campo (usuários normais não têm permissão de
    // escolher template arbitrário — segurança em profundidade no controller).
    const { data, setData, post, processing, reset, errors } = useForm({ company_id: '', template_id: '' });

    // 2026-07-14 (Fase 81) · modal gerar-link agora é MODELO-FIRST (DEC-81-3):
    // passo 1 escolhe o modelo (obrigatório); ao escolher, o passo 2 lista só
    // as empresas ELEGÍVEIS (serviços cobertos do modelo ∩ contratos ativos),
    // vindas do endpoint dedicado 81-02. Escopo por carteira é garantido no
    // backend, então o front só renderiza o que o servidor retornou.
    const [empresasElegiveis, setEmpresasElegiveis] = useState([]);
    const [carregandoEmpresas, setCarregandoEmpresas] = useState(false);

    // Fase 119.1 Plan 07 — NPS de GRUPO. `grupoId` substitui `data.company_id`
    // quando `modoGeracao === 'grupo'`. `cobertura` é o resultado de
    // `nps.grupo.cobertura` (incluidas/excluidas/motivo) — nunca decide quem
    // recebe a nota (isso é recalculado no servidor no submit), só informa.
    const [grupoId, setGrupoId] = useState('');
    const [cobertura, setCobertura] = useState(null);
    const [carregandoCobertura, setCarregandoCobertura] = useState(false);

    // Ao escolher o modelo: fixa o template_id, zera empresa/grupo (a lista
    // muda) e busca as empresas elegíveis daquele modelo. finally garante o
    // fim do loading mesmo em erro de rede.
    const onSelectModelo = async (id) => {
        setData(d => ({ ...d, template_id: id, company_id: '' }));
        setGrupoId('');
        setCobertura(null);
        setCarregandoEmpresas(true);
        setEmpresasElegiveis([]);
        try {
            const { data: res } = await window.axios.get(
                route('nps.configuracao.templates.empresas-elegiveis', id),
            );
            setEmpresasElegiveis(res.empresas ?? []);
        } catch (e) {
            setEmpresasElegiveis([]);
        } finally {
            setCarregandoEmpresas(false);
        }
    };

    // Alterna entre "Uma empresa" e "Um grupo de empresas" — zera a escolha
    // do outro modo (não faz sentido guardar empresa E grupo ao mesmo tempo).
    const trocarModoGeracao = (modo) => {
        setModoGeracao(modo);
        setData(d => ({ ...d, company_id: '' }));
        setGrupoId('');
        setCobertura(null);
    };

    // Busca a prévia de cobertura (item 2 do contrato de UI) sempre que
    // grupo E modelo estiverem escolhidos, no mesmo padrão assíncrono de
    // onSelectModelo. Nenhum fetch acontece fora do modo grupo.
    useEffect(() => {
        if (modoGeracao !== 'grupo' || !grupoId || !data.template_id) {
            setCobertura(null);
            return;
        }
        let cancelado = false;
        setCarregandoCobertura(true);
        setCobertura(null);
        window.axios.get(route('nps.grupo.cobertura', { grupo: grupoId, template: data.template_id }))
            .then(({ data: res }) => { if (!cancelado) setCobertura(res); })
            .catch(() => { if (!cancelado) setCobertura({ incluidas: [], excluidas: [] }); })
            .finally(() => { if (!cancelado) setCarregandoCobertura(false); });
        return () => { cancelado = true; };
    }, [modoGeracao, grupoId, data.template_id]);

    useEffect(() => {
        if (flash?.nps_link) {
            setLinkJaExistia(false);
            setGeneratedLink(flash.nps_link);
            setLinkDialog(true);
        }
    }, [flash?.nps_link]);

    // Fase 119.1 Plan 07 — bloqueio de duplicidade (Plan 02 individual /
    // Plan 06 grupo) devolve o link que JÁ EXISTE em vez de recusar sem
    // alternativa. Abre o mesmo modal, com aviso em âmbar.
    useEffect(() => {
        if (flash?.nps_link_existente) {
            setLinkJaExistia(true);
            setGeneratedLink(flash.nps_link_existente);
            setLinkDialog(true);
        }
    }, [flash?.nps_link_existente]);

    // ─── Filtros server-side ─────────────────────────────────────────────
    // 2026-08-14 — cada opção diz o mês da COLETA e a que mês ele se refere
    // ("ago/26 · ref. jul/26"). O `value` continua sendo o mês de coleta, que
    // é o que o `?mes=` sempre significou: `?mes=2026-07` traz o NPS coletado
    // em julho, referente a junho. Sem o "ref." na própria opção, essa
    // pergunta se repete toda vez que alguém troca o mês.
    const mesOpcoes = useMemo(
        () => serie_12m.map(s => ({
            value: s.mes_iso,
            label: s.competencia_label ? `${s.mes} · ref. ${s.competencia_label}` : s.mes,
        })),
        [serie_12m]
    );

    // 2026-07-13 · valor efetivo do <select> de modelo. Default = principal
    // (quando nenhum filtro explícito); '__todos__' = ver todos os modelos;
    // id numérico = modelo específico. Sempre truthy, então persiste ao mudar
    // outros filtros (não some no delete-falsy do aplicarFiltros).
    const templateSelectValue = filtros.template_todos
        ? '__todos__'
        : (filtros.template_id != null
            ? String(filtros.template_id)
            : (principal_template_id != null ? String(principal_template_id) : '__todos__'));

    const aplicarFiltros = (overrides = {}) => {
        const payload = {
            mes: mes_filtro,
            empresa_id: filtros.empresa_id || undefined,
            estrategista_id: filtros.estrategista_id || undefined,
            analista_id: filtros.analista_id || undefined,
            template_id: templateSelectValue,
            // Fase 95 (AB-95-3) · persiste o filtro de confiança entre trocas
            // de outros filtros (mês/empresa/modelo); delete-falsy abaixo
            // limpa sozinho quando volta pra 'todos'.
            confianca: filtros.confianca && filtros.confianca !== 'todos' ? filtros.confianca : undefined,
            ...overrides,
        };
        Object.keys(payload).forEach(k => { if (!payload[k]) delete payload[k]; });
        router.get(route('nps.index'), payload, { preserveState: true, preserveScroll: true });
    };

    const handleMesChange = (v) => aplicarFiltros({ mes: v });
    const handleEmpresaChange = (v) => aplicarFiltros({ empresa_id: v === '__all__' ? undefined : v });
    const handleEstrategistaChange = (v) => aplicarFiltros({ estrategista_id: v === '__all__' ? undefined : v });
    const handleAnalistaChange = (v) => aplicarFiltros({ analista_id: v === '__all__' ? undefined : v });
    // v é '__todos__' ou o id do modelo — ambos truthy, enviados literalmente.
    const handleTemplateChange = (v) => aplicarFiltros({ template_id: v });
    // Fase 95 (AB-95-3) · 'todos' vira undefined pra não poluir a URL.
    const handleConfiancaChange = (v) => aplicarFiltros({ confianca: v === 'todos' ? undefined : v });

    const submit = (e) => {
        e.preventDefault();
        // Fase 119.1 Plan 07 — modo grupo posta em nps.grupo.generate (guard
        // de duplicidade próprio + fan-out em N surveys-espelho no submit
        // público, Plan 06); modo empresa mantém o fluxo individual de sempre.
        if (modoGeracao === 'grupo') {
            router.post(route('nps.grupo.generate'), {
                company_group_id: grupoId,
                template_id: data.template_id,
            }, {
                onSuccess: () => fecharGerarLink(),
            });
            return;
        }
        post(route('nps.generate'), {
            onSuccess: () => { reset(); setEmpresasElegiveis([]); setOpen(false); },
        });
    };

    // Fecha/reseta o modal gerar-link limpando também a lista de elegíveis e
    // o estado do modo grupo (grupoId/cobertura), sempre voltando pro modo
    // "empresa" default na próxima abertura.
    const fecharGerarLink = () => {
        reset();
        setEmpresasElegiveis([]);
        setOpen(false);
        setModoGeracao('empresa');
        setGrupoId('');
        setCobertura(null);
    };

    const copyLink = (link) => {
        navigator.clipboard.writeText(link);
        setGeneratedLink(link);
        setLinkDialog(true);
    };
    const copyModal = () => {
        navigator.clipboard.writeText(generatedLink);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    // Quick task 260730-jzx (ajuste 3, Task 3) — abre "Gerar link" já no
    // modo grupo quando vem de uma linha de grupo dos Faltantes (preselecao
    // = {grupoId, templateId}); sem preseleção mantém o comportamento atual
    // (modo empresa). O efeito que já busca quem entraria no link do grupo
    // (dependências [modoGeracao, grupoId, data.template_id]) faz o resto.
    const abrirGerarLink = (preselecao) => {
        if (preselecao) {
            setModoGeracao('grupo');
            setGrupoId(String(preselecao.grupoId));
            setData('template_id', String(preselecao.templateId));
        } else {
            setModoGeracao('empresa');
        }
        setOpen(true);
    };

    // Quick task 260730-jzx (ajuste 2) — botão de copiar DENTRO do modal de
    // detalhe do link pendente (independente do `copyModal` do modal "link
    // gerado" — os dois podem existir ao mesmo tempo, com estados de "copiado"
    // separados).
    const copiarLinkPendente = () => {
        if (!linkPendente) return;
        navigator.clipboard.writeText(linkPendente.link);
        setLinkPendenteCopiado(true);
        setTimeout(() => setLinkPendenteCopiado(false), 2000);
    };

    // ─── Derivados dinâmicos ─────────────────────────────────────────────
    // Pendentes agregados vêm do servidor (contadores), não da página atual.
    const pendentesTotal = contadores.pendentes ?? 0;

    const deltaEst = useMemo(() => computeDelta(serie_12m, 'estrategista'), [serie_12m]);
    const deltaAna = useMemo(() => computeDelta(serie_12m, 'analista'),     [serie_12m]);
    const deltaEmp = useMemo(() => computeDelta(serie_12m, 'empresa'),      [serie_12m]);

    // ─── Cards reativos ao filtro de pessoa (2026-07-20) ─────────────────
    // Regra de produto: ao filtrar por UMA pessoa, os cards do topo colapsam
    // para a nota dela — estrategista → dimensão estrategista; analista →
    // dimensão analista. Com os DOIS filtros ativos, mostra um único card com
    // a MÉDIA das duas dimensões. Sem filtro de pessoa, mantém os 3 cards de
    // dimensão (comportamento original). O gráfico abaixo não muda.
    const estrategistaId = filtros.estrategista_id ?? null;
    const analistaId     = filtros.analista_id ?? null;
    const soEstrategista = !!estrategistaId && !analistaId;
    const soAnalista     = !!analistaId && !estrategistaId;
    const ambosPessoa    = !!estrategistaId && !!analistaId;
    const filtroPessoa   = soEstrategista || soAnalista || ambosPessoa;

    // Média das duas dimensões — considera só a dimensão que teve resposta
    // (total>0), para não puxar a média para baixo com um 0 "sem base".
    const cardMedia = useMemo(() => {
        const partes = [];
        if ((cards.estrategista?.total ?? 0) > 0) partes.push(Number(cards.estrategista.media));
        if ((cards.analista?.total ?? 0) > 0)     partes.push(Number(cards.analista.media));
        const media = partes.length ? partes.reduce((a, b) => a + b, 0) / partes.length : 0;
        return { media, total: contadores.respondidos ?? 0 };
    }, [cards, contadores]);

    // Delta da média = média dos deltas disponíveis (ambos já scoped pelo
    // filtro via serie_12m). Null quando nenhuma dimensão tem base anterior.
    const deltaMedia = useMemo(() => {
        const ds = [deltaEst, deltaAna].filter((d) => d != null);
        return ds.length ? ds.reduce((a, b) => a + b, 0) / ds.length : null;
    }, [deltaEst, deltaAna]);

    // ─── Render ──────────────────────────────────────────────────────────
    return (
        <AppLayout title="NPS">
            <div className="relative">
                <div style={{ padding: '22px 0 34px' }}>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>

                        {/* ─── Título + Filter bar ───────────────────────────── */}
                        <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                            <div style={{ marginRight: 6 }}>
                                <h1 style={{ color: 'rgba(255,255,255,0.96)', fontFamily: "'Space Grotesk', sans-serif", fontWeight: 700, fontSize: 21, lineHeight: 1.1 }}>
                                    NPS Dashboard
                                </h1>
                                <p style={{ color: 'rgba(255,255,255,0.4)', fontSize: 12.5, marginTop: 2 }}>
                                    {competencia_filtro
                                        ? <>Coletado em <strong style={{ color: 'rgba(255,255,255,0.7)' }}>{coleta_filtro}</strong> · referente a <strong style={{ color: 'rgba(255,255,255,0.7)' }}>{competencia_filtro}</strong></>
                                        : 'Acompanhe a satisfação dos seus clientes'}
                                </p>
                            </div>
                            <GlassSelect
                                icon={Calendar}
                                active
                                value={mes_filtro}
                                onValueChange={handleMesChange}
                                placeholder="Mês..."
                                width={214}
                                options={mesOpcoes.map(m => (
                                    <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>
                                ))}
                            />
                            <SearchableGlassSelect
                                icon={Building2}
                                active={!!filtros.empresa_id}
                                value={filtros.empresa_id ? String(filtros.empresa_id) : '__all__'}
                                onValueChange={handleEmpresaChange}
                                placeholder="Todas as empresas"
                                searchPlaceholder="Buscar empresa..."
                                width={210}
                                items={[
                                    { value: '__all__', label: 'Todas as empresas' },
                                    ...companies.map(c => ({ value: String(c.id), label: c.name })),
                                ]}
                            />
                            {/* Ajuste 2026-07-13 · filtro por modelo NPS. Só
                                aparece quando existe mais de 1 modelo cadastrado
                                (com 1 só a lista é trivial e polui a UI). */}
                            {templates.length > 1 && (
                                <GlassSelect
                                    value={templateSelectValue}
                                    onValueChange={handleTemplateChange}
                                    placeholder="Modelo principal"
                                    width={210}
                                    options={[
                                        <SelectItem key="__todos__" value="__todos__">Todos os modelos</SelectItem>,
                                        ...templates.map(t => (
                                            <SelectItem key={t.id} value={String(t.id)}>
                                                {t.nome}{Number(t.id) === Number(principal_template_id) ? ' (principal)' : ''}
                                            </SelectItem>
                                        )),
                                    ]}
                                />
                            )}
                            {/* Fase 95 (AB-95-3) · filtro de confiança, só
                                aparece quando o backend confirma que o
                                usuário é admin (chave ausente pra todos os
                                outros papéis — Fase 95-01). */}
                            {pode_ver_confianca && (
                                <GlassSelect
                                    icon={ShieldCheck}
                                    value={filtros.confianca || 'todos'}
                                    onValueChange={handleConfiancaChange}
                                    placeholder="Confiança"
                                    width={170}
                                    options={[
                                        <SelectItem key="todos" value="todos">Todos</SelectItem>,
                                        <SelectItem key="confiavel" value="confiavel">Confiáveis</SelectItem>,
                                        <SelectItem key="atencao" value="atencao">Com alerta</SelectItem>,
                                        <SelectItem key="suspeita" value="suspeita">Suspeitos</SelectItem>,
                                    ]}
                                />
                            )}
                            {/* Spec 2026-08-14 (item 2) — fechamento manual do
                                ciclo. Admin-only na tela E na rota; o guard que
                                vale é o do servidor. */}
                            {isAdmin && <CicloAcao ciclo={ciclo} competencia={competencia_filtro} coleta={coleta_filtro} />}
                            {pode_filtrar_por_pessoa && (
                                <>
                                    <GlassSelect
                                        value={filtros.estrategista_id ? String(filtros.estrategista_id) : '__all__'}
                                        onValueChange={handleEstrategistaChange}
                                        placeholder="Estrategista"
                                        width={180}
                                        options={[
                                            <SelectItem key="__all__" value="__all__">Todos os estrategistas</SelectItem>,
                                            ...estrategistas.map(u => (
                                                <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                                            )),
                                        ]}
                                    />
                                    <GlassSelect
                                        value={filtros.analista_id ? String(filtros.analista_id) : '__all__'}
                                        onValueChange={handleAnalistaChange}
                                        placeholder="Analista"
                                        width={170}
                                        options={[
                                            <SelectItem key="__all__" value="__all__">Todos os analistas</SelectItem>,
                                            ...analistas.map(u => (
                                                <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                                            )),
                                        ]}
                                    />
                                </>
                            )}
                            <span style={{ color: 'rgba(255,255,255,0.35)', fontSize: 12.5, marginLeft: 2 }}>
                                · {surveys.total} pesquisa{surveys.total === 1 ? '' : 's'}
                            </span>
                            <div style={{ flex: 1 }} />
                            <button
                                type="button"
                                onClick={() => setOpen(true)}
                                style={{
                                    display: 'inline-flex', alignItems: 'center', gap: 8,
                                    height: 40, padding: '0 18px', borderRadius: 12,
                                    border: 'none', background: ACCENT,
                                    color: '#050507', fontWeight: 800, fontSize: 13,
                                    cursor: 'pointer',
                                    boxShadow: '0 10px 26px -10px ' + hexAlpha(ACCENT, 0.55),
                                }}
                            >
                                <Plus size={16} strokeWidth={2.4} />
                                Gerar Link NPS
                            </button>
                        </div>

                        {/* ─── Stat cards — reativos ao filtro de pessoa ────────── */}
                        {filtroPessoa ? (
                            <div style={{ display: 'flex', gap: 14 }}>
                                <div style={{ width: 'min(100%, 380px)' }}>
                                    {soEstrategista && (
                                        <StatCard
                                            kicker="ESTRATEGISTA"
                                            icon={Briefcase}
                                            color={COL_ESTRATEGISTA_LINE}
                                            valor={cards.estrategista?.media}
                                            total={cards.estrategista?.total ?? 0}
                                            pendentes={pendentesTotal}
                                            delta={deltaEst}
                                            naoRespondidos={cards.estrategista?.nao_respondidos ?? 0}
                                            janelaFechada={janela_fechada}
                                        />
                                    )}
                                    {soAnalista && (
                                        <StatCard
                                            kicker="ANALISTA"
                                            icon={UsersIcon}
                                            color={COL_ANALISTA}
                                            valor={cards.analista?.media}
                                            total={cards.analista?.total ?? 0}
                                            pendentes={pendentesTotal}
                                            delta={deltaAna}
                                            naoRespondidos={cards.analista?.nao_respondidos ?? 0}
                                            janelaFechada={janela_fechada}
                                        />
                                    )}
                                    {ambosPessoa && (
                                        <StatCard
                                            kicker="MÉDIA · EST + ANA"
                                            icon={UsersIcon}
                                            color={ACCENT}
                                            valor={cardMedia.media}
                                            total={cardMedia.total}
                                            pendentes={pendentesTotal}
                                            delta={deltaMedia}
                                            naoRespondidos={(cards.estrategista?.nao_respondidos ?? 0) + (cards.analista?.nao_respondidos ?? 0)}
                                            janelaFechada={janela_fechada}
                                        />
                                    )}
                                </div>
                            </div>
                        ) : (
                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 14 }}>
                                <StatCard
                                    kicker="ESTRATEGISTA"
                                    icon={Briefcase}
                                    color={COL_ESTRATEGISTA_LINE}
                                    valor={cards.estrategista?.media}
                                    total={cards.estrategista?.total ?? 0}
                                    pendentes={pendentesTotal}
                                    delta={deltaEst}
                                    naoRespondidos={cards.estrategista?.nao_respondidos ?? 0}
                                    janelaFechada={janela_fechada}
                                />
                                <StatCard
                                    kicker="ANALISTA"
                                    icon={UsersIcon}
                                    color={COL_ANALISTA}
                                    valor={cards.analista?.media}
                                    total={cards.analista?.total ?? 0}
                                    pendentes={pendentesTotal}
                                    delta={deltaAna}
                                    naoRespondidos={cards.analista?.nao_respondidos ?? 0}
                                    janelaFechada={janela_fechada}
                                />
                                <StatCard
                                    kicker="EMPRESA"
                                    icon={Building2}
                                    color={COL_EMPRESA}
                                    valor={cards.empresa?.media}
                                    total={cards.empresa?.total ?? 0}
                                    pendentes={pendentesTotal}
                                    delta={deltaEmp}
                                    naoRespondidos={cards.empresa?.nao_respondidos ?? 0}
                                    janelaFechada={janela_fechada}
                                />
                            </div>
                        )}

                        {/* ─── Fase 116 · explica a regra do não respondido ─────── */}
                        {regra_nao_respondido && (
                            <div style={{ display: 'flex', alignItems: 'center', gap: 7, padding: '0 2px' }}>
                                <Info size={13} style={{ color: 'rgba(255,255,255,0.35)', flexShrink: 0 }} />
                                <p style={{ color: 'rgba(255,255,255,0.45)', fontSize: 12 }}>
                                    NPS enviado e não respondido conta nota 1 na média.
                                </p>
                            </div>
                        )}

                        {/* ─── Chart card ──────────────────────────────────────── */}
                        <ChartCard serie={serie_12m} cards={cards} lines={lines} setLines={setLines} />

                        {/* ─── Table card ──────────────────────────────────────── */}
                        <TableCard
                            surveys={surveys}
                            contadores={contadores}
                            faltantes={faltantes}
                            activeStatus={activeStatus}
                            setActiveStatus={setActiveStatus}
                            sort={sort}
                            setSort={setSort}
                            onOpenSurvey={abrirModalSurvey}
                            onCopyLink={copyLink}
                            onGerarLink={abrirGerarLink}
                            onVerDetalheLink={setLinkPendente}
                            isAdmin={isAdmin}
                        />

                    </div>
                </div>
            </div>

            {/* ═══ Modais (preservados) ═══════════════════════════════════════ */}

            {/* Dialog: gerar link manual — MODELO-FIRST (Fase 81 / DEC-81-3) */}
            <Dialog open={open} onOpenChange={o => { if (!o) fecharGerarLink(); else setOpen(true); }}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Gerar Link NPS</DialogTitle>
                        <DialogDescription>
                            Escolha primeiro o modelo de NPS e depois a empresa
                            elegível para gerar o link de avaliação manual.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        {/* Passo 1 · MODELO (obrigatório, sem "__auto__"). Vale
                            para todos os usuários que geram link — o passo 2
                            depende dele. */}
                        <div className="space-y-1.5">
                            <label className="text-xs text-white/70 font-medium">1 · Modelo NPS</label>
                            <Select
                                value={data.template_id || undefined}
                                onValueChange={onSelectModelo}
                                required
                            >
                                <SelectTrigger><SelectValue placeholder="Selecionar modelo..." /></SelectTrigger>
                                <SelectContent>
                                    {templates.map(t => {
                                        // Pitfall 4 (Rollup): derivar value dentro do map.
                                        const value = String(t.id);
                                        return (
                                            <SelectItem key={t.id} value={value}>{t.nome}</SelectItem>
                                        );
                                    })}
                                </SelectContent>
                            </Select>
                            {errors.template_id && <p className="text-destructive text-xs">{errors.template_id}</p>}
                        </div>

                        {/* Alvo do link — Fase 119.1 Plan 07 (NPS de grupo).
                            Ao trocar de modo, o seletor de empresa dá lugar
                            ao seletor de grupo (nunca os dois ao mesmo tempo). */}
                        <div className="space-y-1.5">
                            <label className="text-xs text-white/70 font-medium">2 · Quem vai responder</label>
                            <div className="grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    onClick={() => trocarModoGeracao('empresa')}
                                    className={cn(
                                        'h-9 rounded-lg border text-xs font-semibold transition-colors',
                                        modoGeracao === 'empresa'
                                            ? 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow'
                                            : 'border-white/[0.08] bg-white/[0.02] text-white/50 hover:text-white/70',
                                    )}
                                >
                                    Uma empresa
                                </button>
                                <button
                                    type="button"
                                    onClick={() => trocarModoGeracao('grupo')}
                                    className={cn(
                                        'h-9 rounded-lg border text-xs font-semibold transition-colors',
                                        modoGeracao === 'grupo'
                                            ? 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow'
                                            : 'border-white/[0.08] bg-white/[0.02] text-white/50 hover:text-white/70',
                                    )}
                                >
                                    Um grupo de empresas
                                </button>
                            </div>
                        </div>

                        {modoGeracao === 'empresa' ? (
                            /* Passo 3 · EMPRESA (só as elegíveis do modelo). Fica
                               desabilitado até escolher o modelo / durante a carga. */
                            <div className="space-y-1.5">
                                <label className="text-xs text-white/70 font-medium">3 · Empresa</label>
                                {/* 2026-07-20 · seletor pesquisável (o modelo "padrão"
                                    lista TODAS as empresas — sem busca virava lista
                                    impossível de rolar). */}
                                <SearchableGlassSelect
                                    active={!!data.company_id}
                                    disabled={!data.template_id || carregandoEmpresas}
                                    value={data.company_id || ''}
                                    onValueChange={v => setData('company_id', v)}
                                    placeholder={
                                        !data.template_id
                                            ? 'Escolha um modelo primeiro'
                                            : (carregandoEmpresas ? 'Carregando empresas...' : 'Selecionar empresa...')
                                    }
                                    searchPlaceholder="Buscar empresa..."
                                    width="100%"
                                    items={empresasElegiveis.map(c => ({ value: String(c.id), label: c.name }))}
                                />
                                {errors.company_id && <p className="text-destructive text-xs">{errors.company_id}</p>}
                            </div>
                        ) : (
                            /* Passo 3 · GRUPO — mesmo padrão de busca do seletor
                               de empresa. */
                            <div className="space-y-1.5">
                                <label className="text-xs text-white/70 font-medium">3 · Grupo de empresas</label>
                                <SearchableGlassSelect
                                    active={!!grupoId}
                                    disabled={!data.template_id}
                                    value={grupoId}
                                    onValueChange={v => setGrupoId(v)}
                                    placeholder={!data.template_id ? 'Escolha um modelo primeiro' : 'Selecionar grupo...'}
                                    searchPlaceholder="Buscar grupo..."
                                    width="100%"
                                    items={grupos.map(g => ({ value: String(g.id), label: g.name }))}
                                />
                            </div>
                        )}

                        {/* Prévia de cobertura (item 2 do contrato de UI) — só
                            renderiza quando há algo a mostrar (empresa
                            selecionada, ou grupo+modelo escolhidos). */}
                        {((modoGeracao === 'empresa' && data.company_id) || (modoGeracao === 'grupo' && grupoId && data.template_id)) && (
                            <CoberturaPreview
                                modo={modoGeracao}
                                carregando={carregandoCobertura}
                                cobertura={cobertura}
                            />
                        )}

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={fecharGerarLink}>Cancelar</Button>
                            <Button
                                type="submit"
                                disabled={
                                    processing || !data.template_id
                                    || (modoGeracao === 'empresa' && !data.company_id)
                                    || (modoGeracao === 'grupo' && (
                                        !grupoId || carregandoCobertura || !cobertura || (cobertura.incluidas ?? []).length === 0
                                    ))
                                }
                            >
                                Gerar Link
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog: link gerado — ou aviso de link já existente (Fase 119.1
                Plan 07, item 1 do contrato de UI). Título/cor/texto trocam
                conforme `linkJaExistia`; o bloco copiável e o botão de copiar
                são os MESMOS nos dois casos. */}
            <Dialog open={linkDialog} onOpenChange={setLinkDialog}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            {linkJaExistia
                                ? <><AlertCircle className="h-5 w-5 text-amber-400" /> Já existe um link deste mês</>
                                : <><CheckCircle className="h-5 w-5 text-emerald-400" /> Link NPS</>}
                        </DialogTitle>
                        <DialogDescription>
                            {linkJaExistia
                                ? (modoGeracao === 'grupo'
                                    ? 'Este grupo já tem um link de NPS deste modelo neste mês. Enviar um segundo link pode fazer o cliente responder duas vezes. Use o link abaixo.'
                                    : 'Esta empresa já tem um link de NPS deste modelo neste mês. Enviar um segundo link pode fazer o cliente responder duas vezes. Use o link abaixo.')
                                : 'Copie e envie este link para o cliente via WhatsApp ou chat da reunião.'}
                        </DialogDescription>
                    </DialogHeader>
                    <div className={cn(
                        'flex items-center gap-2 p-3 rounded-lg',
                        linkJaExistia ? 'bg-amber-500/10 border border-amber-500/25' : 'bg-muted',
                    )}>
                        <p className="text-sm text-foreground flex-1 break-all">{generatedLink}</p>
                        <Button size="icon" variant="ghost" onClick={copyModal}>
                            {copied
                                ? <CheckCircle className="h-4 w-4 text-emerald-400" />
                                : <Copy className="h-4 w-4" />}
                        </Button>
                    </div>
                    <DialogFooter>
                        <Button onClick={() => setLinkDialog(false)}>Fechar</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Dialog: detalhe do link de um PENDENTE (ajuste 2, quick task
                260730-jzx) — mesmo padrão de caixa copiável do modal acima
                (bg-muted + text-sm break-all + botão Copy/CheckCircle). */}
            <Dialog open={!!linkPendente} onOpenChange={(o) => { if (!o) setLinkPendente(null); }}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{linkPendente?.company_name} — Link da pesquisa</DialogTitle>
                    </DialogHeader>
                    {linkPendente && (
                        <>
                            <div className="flex items-center gap-2 p-3 rounded-lg bg-muted">
                                <p className="text-sm text-foreground flex-1 break-all">{linkPendente.link}</p>
                                <Button size="icon" variant="ghost" onClick={copiarLinkPendente}>
                                    {linkPendenteCopiado
                                        ? <CheckCircle className="h-4 w-4 text-emerald-400" />
                                        : <Copy className="h-4 w-4" />}
                                </Button>
                            </div>
                            <div className="space-y-1.5 text-sm text-white/70">
                                <p><span className="text-white/40">Gerado por:</span> {linkPendente.generated_by ?? (linkPendente.auto_generated ? 'Envio automático' : 'Não informado')}</p>
                                <p><span className="text-white/40">Gerado em:</span> {linkPendente.created_at}</p>
                                <p><span className="text-white/40">Vale até:</span> {linkPendente.expires_at ?? 'Sem prazo'}</p>
                                <p><span className="text-white/40">Tipo de link:</span> {linkPendente.de_grupo ? 'Link de grupo (vale para todas as empresas do grupo)' : 'Link só desta empresa'}</p>
                            </div>
                            {/* 2026-08-20 · quais empresas ESTE link cobre. Só
                                a linha vinda de `nps_group_surveys` traz a
                                lista (a cobertura é recalculada no servidor);
                                o espelho já respondido continua sendo de uma
                                empresa só. */}
                            {linkPendente.tipo === 'grupo' && (
                                <div className="rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-2.5">
                                    <p className="text-xs text-white/40 mb-1.5">
                                        Empresas que recebem a nota deste link ({linkPendente.empresas_count})
                                    </p>
                                    <ul className="text-sm text-white/75 space-y-0.5">
                                        {(linkPendente.empresas_nomes ?? []).map((nome) => (
                                            <li key={nome}>{nome}</li>
                                        ))}
                                    </ul>
                                    <p className="text-xs text-white/40 mt-2">
                                        Uma resposta só vale para todas elas. Empresa do grupo que não está
                                        nesta lista continua em Faltantes e precisa de link próprio.
                                    </p>
                                </div>
                            )}
                        </>
                    )}
                    <DialogFooter>
                        <Button onClick={() => setLinkPendente(null)}>Fechar</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Dialog: ver respostas (preservado do fix 2026-07-08) */}
            <Dialog open={!!modalSurvey} onOpenChange={(o) => !o && abrirModalSurvey(null)}>
                <DialogContent className="max-w-2xl bg-ecf-card border border-white/[0.08] max-h-[85vh] p-0 gap-0 flex flex-col overflow-hidden">
                    <DialogHeader className="px-6 pt-6 pb-4 border-b border-white/[0.06] shrink-0">
                        <DialogTitle className="text-white flex items-center gap-2">
                            {modalSurvey?.company_name ?? '—'} — Resposta NPS
                            {/* Fase 95 (AB-95-1/95-2) · guard por existência da
                                chave (não-admin nunca recebe `confianca`). */}
                            <ConfiancaBadge confianca={modalSurvey?.confianca} />
                            {/* Fase 96 (AB-96-3) · modalSurvey.invalidada só
                                existe pra admin — mesma blindagem acima. */}
                            {modalSurvey?.invalidada && (
                                <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border border-slate-500/40 bg-slate-500/10 text-slate-300">
                                    Invalidada
                                </span>
                            )}
                        </DialogTitle>
                        <p className="text-xs text-white/50">
                            {modalSurvey?.respondent || 'Respondente não informado'}
                            {modalSurvey?.completed_at ? ` · ${modalSurvey.completed_at}` : ''}
                        </p>
                    </DialogHeader>

                    {modalSurvey && (
                        <div className="space-y-5 overflow-y-auto px-6 py-5 flex-1 min-h-0">
                            <div>
                                <h3 className="text-xs text-white/60 uppercase tracking-wide mb-2">Notas por dimensão</h3>
                                <div className="grid grid-cols-3 gap-3">
                                    <NotaCard label="Estrategista" valor={modalSurvey.score_estrategista} pessoas={modalSurvey.responsaveis?.estrategista ?? []} />
                                    <NotaCard label="Analista"     valor={modalSurvey.score_analista}     pessoas={modalSurvey.responsaveis?.analista ?? []} />
                                    <NotaCard label="Empresa"      valor={modalSurvey.score_empresa} />
                                </div>
                            </div>

                            <div>
                                <h3 className="text-xs text-white/60 uppercase tracking-wide mb-2">Comentário</h3>
                                <div className="rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-2.5 text-sm text-white/80 whitespace-pre-wrap break-words">
                                    {modalSurvey.comment || <span className="text-white/30 italic">Não informado</span>}
                                </div>
                            </div>

                            {modalSurvey.respostas_customizadas?.length > 0 && (
                                <div>
                                    <h3 className="text-xs text-white/60 uppercase tracking-wide mb-2">
                                        Todas as respostas ({modalSurvey.respostas_customizadas.length})
                                    </h3>
                                    <div className="space-y-2">
                                        {modalSurvey.respostas_customizadas.map((r, idx) => (
                                            <div key={r.id ?? idx} className="rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-2.5">
                                                <div className="flex items-start justify-between gap-2 mb-1.5">
                                                    <p className="text-xs text-white/60 leading-snug">{r.pergunta_texto}</p>
                                                    <DimensaoBadge dimensao={r.dimensao} />
                                                </div>
                                                <RespostaExtraValor tipo={r.tipo} valor={r.valor} peso={r.peso} />
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Fase 95 (AB-95-2) · seção Auditoria, admin-only
                                — renderizada só quando `auditoria` existe no
                                payload (não-admin nunca recebe a chave). */}
                            {modalSurvey.auditoria && (
                                <div>
                                    <h3 className="text-xs text-white/60 uppercase tracking-wide mb-2">Auditoria</h3>
                                    <div className={cn('rounded-xl border border-white/[0.08] bg-white/[0.03] p-3')}>
                                        <div className="grid grid-cols-2 gap-x-4 gap-y-2.5">
                                            <AuditoriaField label="Gerado em" valor={modalSurvey.auditoria.gerado_em ?? '—'} />
                                            <AuditoriaField label="Gerado por" valor={modalSurvey.auditoria.gerado_por ?? 'Disparo mensal automático'} />
                                            <AuditoriaField
                                                label="Aberto em"
                                                valor={
                                                    modalSurvey.auditoria.aberto_primeira
                                                        ? `${modalSurvey.auditoria.aberto_primeira}${modalSurvey.auditoria.aberto_contagem > 1 ? ` · última ${modalSurvey.auditoria.aberto_ultima} · ${modalSurvey.auditoria.aberto_contagem} aberturas` : ''}`
                                                        : '—'
                                                }
                                            />
                                            <AuditoriaField label="Respondido em" valor={modalSurvey.auditoria.respondido_em ?? '—'} />
                                            <AuditoriaField label="Tempo até resposta" valor={fmtDuracao(modalSurvey.auditoria.tempo_ate_resposta)} />
                                            <AuditoriaField label="IP da abertura" valor={modalSurvey.auditoria.ip_abertura ?? '—'} />
                                            <AuditoriaField label="IP da resposta" valor={modalSurvey.auditoria.ip_resposta ?? '—'} />
                                            <AuditoriaField
                                                label="Navegador"
                                                valor={modalSurvey.auditoria.user_agent ?? '—'}
                                                title={modalSurvey.auditoria.user_agent || undefined}
                                            />
                                            <AuditoriaField label="Canal de envio" valor={modalSurvey.auditoria.canal ?? '—'} />
                                        </div>

                                        {modalSurvey.auditoria.motivos?.length > 0 && (
                                            <div className="mt-3 pt-3 border-t border-white/[0.06]">
                                                <p className="text-[10px] text-white/40 uppercase tracking-wide mb-1.5">Motivos de suspeita</p>
                                                <ul className="space-y-1">
                                                    {modalSurvey.auditoria.motivos.map((motivo, idx) => {
                                                        // Pitfall 4 (Rollup): cor derivada DENTRO do
                                                        // callback do map — nunca herdada de variável
                                                        // do corpo do componente (memória do projeto).
                                                        const cor = modalSurvey.confianca?.status === 'suspeita' ? 'text-rose-300' : 'text-amber-300';
                                                        return (
                                                            <li key={idx} className={cn('text-xs', cor)}>• {motivo}</li>
                                                        );
                                                    })}
                                                </ul>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}

                            {/* Fase 96 (AB-96-3) · form de motivo, aberto ao
                                clicar em "Invalidar resposta" no rodapé.
                                modalSurvey.auditoria existe = admin (mesmo
                                guard usado em toda a seção acima). */}
                            {modalSurvey.auditoria && invalidarAberto && (
                                <div className="rounded-lg border border-amber-500/30 bg-amber-500/[0.06] p-3 space-y-2">
                                    <label className="text-xs text-amber-200/80 block">
                                        Motivo da invalidação (opcional)
                                    </label>
                                    <textarea
                                        value={motivoInvalidar}
                                        onChange={(e) => setMotivoInvalidar(e.target.value)}
                                        rows={2}
                                        maxLength={500}
                                        placeholder="Ex.: resposta enviada da rede interna da ECF"
                                        className="w-full rounded-md bg-black/20 border border-white/[0.08] px-3 py-2 text-sm text-white placeholder-white/30 focus:outline-none focus:border-amber-500/50"
                                    />
                                </div>
                            )}
                        </div>
                    )}

                    <DialogFooter className="gap-2 sm:gap-0 sm:justify-between px-6 py-4 border-t border-white/[0.06] shrink-0">
                        <div className="flex items-center gap-2">
                            {isAdmin && modalSurvey && !invalidarAberto && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        if (!confirm(`Excluir a resposta de "${modalSurvey.company_name}"? A pesquisa voltará para pendente e poderá ser respondida novamente.`)) return;
                                        router.delete(route('nps.responses.destroy', modalSurvey.id), {
                                            preserveScroll: true,
                                            onSuccess: () => abrirModalSurvey(null),
                                        });
                                    }}
                                    className="px-4 py-2 rounded-md bg-rose-500/20 hover:bg-rose-500/30 text-rose-300 border border-rose-500/30 text-sm font-medium"
                                >
                                    Excluir resposta
                                </button>
                            )}

                            {/* Fase 96 (AB-96-3) · botão alterna Invalidar/
                                Revalidar conforme modalSurvey.invalidada.
                                modalSurvey.auditoria = guard admin-only
                                (mesmo padrão de confianca/auditoria acima —
                                não-admin nunca recebe a chave). */}
                            {isAdmin && modalSurvey?.auditoria && !modalSurvey.invalidada && !invalidarAberto && (
                                <button
                                    type="button"
                                    onClick={() => setInvalidarAberto(true)}
                                    className="px-4 py-2 rounded-md bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 border border-amber-500/30 text-sm font-medium"
                                >
                                    Invalidar resposta
                                </button>
                            )}

                            {isAdmin && modalSurvey?.auditoria && modalSurvey.invalidada && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        if (!confirm('Revalidar esta resposta? Ela volta a contar nos dashboards e no bônus.')) return;
                                        router.patch(route('nps.responses.revalidar', modalSurvey.id), {}, {
                                            preserveScroll: true,
                                            onSuccess: () => abrirModalSurvey(null),
                                        });
                                    }}
                                    className="px-4 py-2 rounded-md bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-300 border border-emerald-500/30 text-sm font-medium"
                                >
                                    Revalidar resposta
                                </button>
                            )}

                            {isAdmin && invalidarAberto && (
                                <>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            router.patch(route('nps.responses.invalidar', modalSurvey.id), { motivo: motivoInvalidar || null }, {
                                                preserveScroll: true,
                                                onSuccess: () => abrirModalSurvey(null),
                                            });
                                        }}
                                        className="px-4 py-2 rounded-md bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 border border-amber-500/30 text-sm font-medium"
                                    >
                                        Confirmar invalidação
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setInvalidarAberto(false);
                                            setMotivoInvalidar('');
                                        }}
                                        className="px-4 py-2 rounded-md bg-white/[0.08] hover:bg-white/[0.12] text-white text-sm"
                                    >
                                        Cancelar
                                    </button>
                                </>
                            )}
                        </div>
                        <button
                            type="button"
                            onClick={() => abrirModalSurvey(null)}
                            className="px-4 py-2 rounded-md bg-white/[0.08] hover:bg-white/[0.12] text-white text-sm"
                        >
                            Fechar
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
