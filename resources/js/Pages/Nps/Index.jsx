import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/Components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogDescription } from '@/Components/ui/dialog';
import { useForm, usePage, router } from '@inertiajs/react';
import { useState, useEffect, useMemo } from 'react';
import {
    Plus, Copy, CheckCircle, AlertTriangle,
    Briefcase, Users as UsersIcon, Building2, Eye,
    Link2, Search, ChevronDown, ArrowUp, ArrowDown,
    Calendar, Star,
} from 'lucide-react';
import {
    ResponsiveContainer, LineChart, Line, XAxis, YAxis,
    Tooltip, CartesianGrid, AreaChart, Area,
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
const GRAD_ECF = 'linear-gradient(120deg,#1e5ef3,#e84393 42%,#f97316 72%,#facc15)';
const COL_ESTRATEGISTA = '#facc15';   // amarelo (marca)
const COL_ESTRATEGISTA_LINE = '#ffb020';
const COL_ANALISTA     = '#19e06a';
const COL_EMPRESA      = '#60a5fa';
const COL_ATENCAO      = '#ff9a5a';
const COL_LARANJA_GLOW = '#f97316';

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

// Delta entre penúltimo e último mês (>0 sobe / <0 desce). Retorna null se
// menos de 2 pontos válidos.
function computeDelta(serie, key) {
    if (!Array.isArray(serie) || serie.length < 2) return null;
    const last = Number(serie[serie.length - 1]?.[key]);
    const prev = Number(serie[serie.length - 2]?.[key]);
    if (Number.isNaN(last) || Number.isNaN(prev)) return null;
    return last - prev;
}

// Sparkline: últimos N meses da série, no formato Recharts.
function sparkData(serie, key, months = 6) {
    if (!Array.isArray(serie)) return [];
    return serie.slice(-months).map((s, i) => ({ i, v: Number(s?.[key] ?? 0) }));
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
function NotaCard({ label, valor }) {
    if (valor === null || valor === undefined) {
        return (
            <div className="rounded-lg border border-white/[0.08] bg-white/[0.02] px-3 py-3 text-center">
                <p className="text-[10px] text-white/40 uppercase tracking-wide">{label}</p>
                <p className="text-2xl font-bold text-white/30 mt-1">—</p>
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
        </div>
    );
}

// ═══ Sparkline mini (AreaChart Recharts, 100×36 sem eixos) ═══════════════
function Sparkline({ data, color, gradientId }) {
    if (!data || data.length === 0) return <div style={{ width: 100, height: 36 }} />;
    return (
        <div style={{ width: 100, height: 36 }}>
            <ResponsiveContainer width="100%" height="100%">
                <AreaChart data={data} margin={{ top: 2, right: 0, bottom: 0, left: 0 }}>
                    <defs>
                        <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%"   stopColor={color} stopOpacity={0.45} />
                            <stop offset="100%" stopColor={color} stopOpacity={0}    />
                        </linearGradient>
                    </defs>
                    <YAxis hide domain={[1, 5]} />
                    <Area type="monotone" dataKey="v" stroke={color} strokeWidth={1.8}
                          fill={`url(#${gradientId})`} isAnimationActive={false} />
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
    return (
        <div style={{ ...glassPillStyle(!!active), padding: 0, paddingLeft: Icon ? 10 : 0, width }}>
            <Select value={value} onValueChange={onValueChange}>
                <SelectTrigger
                    className="border-0 bg-transparent hover:bg-transparent focus:ring-0 focus-visible:ring-0 shadow-none h-full px-3 gap-2 [&>svg]:hidden"
                    style={{ fontSize: 13, color: active ? '#eef' : 'rgba(255,255,255,0.6)', fontWeight: active ? 600 : 500 }}
                >
                    {Icon && <Icon size={15} style={{ color: 'rgba(255,255,255,0.5)' }} />}
                    <SelectValue placeholder={placeholder} />
                    <ChevronDown size={13} style={{ color: 'rgba(255,255,255,0.4)', marginLeft: 4 }} />
                </SelectTrigger>
                <SelectContent>
                    {options}
                </SelectContent>
            </Select>
        </div>
    );
}

// ═══ ActionStrip — faixa de pendentes/expirando ══════════════════════════
function ActionStrip({ pendentesSurveys, onCobrarLink }) {
    const total = pendentesSurveys.length;
    if (total === 0) return null;

    // Empresa que expira mais próximo (menor daysUntil positivo).
    const expiring = pendentesSurveys
        .map(s => ({ ...s, dias: daysUntil(s.expires_at) }))
        .filter(s => s.dias !== null && s.dias >= 0)
        .sort((a, b) => a.dias - b.dias);
    const nextExpire = expiring[0];

    const chipNames = pendentesSurveys.slice(0, 3).map(s => s.company_name);

    return (
        <div style={{
            display: 'flex', alignItems: 'center', gap: 18,
            padding: '17px 20px', borderRadius: 18,
            background: 'rgba(255,255,255,0.04)',
            backdropFilter: 'blur(22px)', WebkitBackdropFilter: 'blur(22px)',
            border: '1px solid rgba(255,255,255,0.09)',
            boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.08), 0 0 60px -20px rgba(232,67,147,0.4)',
            position: 'relative', overflow: 'hidden',
            flexWrap: 'wrap',
        }}>
            <div style={{
                position: 'absolute', top: -40, left: -20, width: 200, height: 200,
                borderRadius: '50%', background: COL_LARANJA_GLOW, filter: 'blur(90px)',
                opacity: 0.22, pointerEvents: 'none',
            }} />
            <div style={{
                width: 46, height: 46, borderRadius: 13,
                background: 'linear-gradient(150deg, rgba(249,115,22,0.3), rgba(232,67,147,0.25))',
                border: '1px solid rgba(255,255,255,0.15)',
                display: 'flex', alignItems: 'center', justifyContent: 'center',
                color: '#ffb87a', flex: '0 0 auto', position: 'relative',
                boxShadow: '0 0 20px -4px rgba(249,115,22,0.6)',
            }}>
                <AlertTriangle size={22} strokeWidth={1.9} />
            </div>
            <div style={{ flex: 1, position: 'relative', minWidth: 0 }}>
                <div style={{ color: '#f4f4f5', fontWeight: 700, fontSize: 14.5 }}>
                    {total} pesquisa{total === 1 ? '' : 's'} ainda não responder{total === 1 ? 'a' : 'am'}
                    {nextExpire && (
                        <>
                            {' · '}
                            <span style={{ color: COL_ATENCAO }}>
                                {nextExpire.company_name} expira em {nextExpire.dias} dia{nextExpire.dias === 1 ? '' : 's'}
                            </span>
                        </>
                    )}
                </div>
                <div style={{ color: 'rgba(255,255,255,0.5)', fontSize: 12.5, marginTop: 3 }}>
                    Cobre os pendentes antes do prazo para não perder o mês.
                </div>
            </div>
            {chipNames.length > 0 && (
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, position: 'relative' }}>
                    {chipNames.map((name, i) => (
                        <span key={i} style={{
                            display: 'inline-flex', alignItems: 'center', height: 26, padding: '0 10px',
                            borderRadius: 999, background: 'rgba(255,255,255,0.07)',
                            border: '1px solid rgba(255,255,255,0.08)',
                            color: 'rgba(255,255,255,0.75)', fontSize: 11.5, fontWeight: 600,
                            whiteSpace: 'nowrap',
                        }}>{name}</span>
                    ))}
                    {total > 3 && (
                        <span style={{ color: 'rgba(255,255,255,0.5)', fontSize: 11.5 }}>
                            +{total - 3}
                        </span>
                    )}
                </div>
            )}
            <button
                type="button"
                onClick={onCobrarLink}
                style={{
                    height: 40, padding: '0 17px', borderRadius: 12,
                    border: '1px solid rgba(255,255,255,0.14)',
                    background: 'rgba(255,255,255,0.06)',
                    color: '#fff', fontWeight: 700, fontSize: 13,
                    cursor: 'pointer', whiteSpace: 'nowrap', position: 'relative',
                }}
                title="Copia lista das empresas pendentes"
            >
                Copiar lista
            </button>
        </div>
    );
}

// ═══ StatCard — card de média glass ══════════════════════════════════════
function StatCard({ dim, kicker, icon: Icon, color, colorLine, valor, total, pendentes, delta, sparkline, sparkId }) {
    const val = formatNota(valor);
    return (
        <div style={{
            padding: 19, borderRadius: 20,
            background: 'rgba(255,255,255,0.035)',
            backdropFilter: 'blur(22px)', WebkitBackdropFilter: 'blur(22px)',
            border: '1px solid rgba(255,255,255,0.09)',
            boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.08), 0 24px 50px -34px rgba(0,0,0,0.9)',
            position: 'relative', overflow: 'hidden',
        }}>
            <div style={{
                position: 'absolute', top: -30, right: -20, width: 130, height: 130,
                borderRadius: '50%', background: color, filter: 'blur(70px)',
                opacity: dim === 'estrategista' ? 0.14 : dim === 'analista' ? 0.12 : 0.16,
                pointerEvents: 'none',
            }} />
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', position: 'relative' }}>
                <span style={{ color: 'rgba(255,255,255,0.45)', fontSize: 11, fontWeight: 700, letterSpacing: '0.12em' }}>
                    {kicker}
                </span>
                <span style={{
                    width: 30, height: 30, borderRadius: 9,
                    background: `${color}1F`,
                    border: `1px solid ${color}38`,
                    display: 'flex', alignItems: 'center', justifyContent: 'center', color,
                }}>
                    <Icon size={16} strokeWidth={1.8} />
                </span>
            </div>
            <div style={{ display: 'flex', alignItems: 'flex-end', gap: 8, marginTop: 14, position: 'relative' }}>
                <span style={{
                    fontFamily: "'Space Grotesk', sans-serif", fontWeight: 700, fontSize: 40,
                    lineHeight: 0.9, color: total === 0 ? 'rgba(255,255,255,0.25)' : scoreColor(valor),
                }}>
                    {val ?? '—'}
                </span>
                <span style={{ color: 'rgba(255,255,255,0.35)', fontSize: 15, fontWeight: 600, marginBottom: 5 }}>/5</span>
                <div style={{ flex: 1 }} />
                <Sparkline data={sparkline} color={colorLine} gradientId={sparkId} />
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 13, position: 'relative' }}>
                {delta !== null ? (
                    <span style={{
                        display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 12, fontWeight: 700,
                        color: delta > 0 ? '#19e06a' : delta < 0 ? '#ff8a3c' : 'rgba(255,255,255,0.4)',
                    }}>
                        {delta > 0 ? <ArrowUp size={11} /> : delta < 0 ? <ArrowDown size={11} /> : '—'}
                        {Math.abs(delta).toFixed(2)}
                    </span>
                ) : (
                    <span style={{ color: 'rgba(255,255,255,0.35)', fontSize: 12, fontWeight: 600 }}>—</span>
                )}
                <span style={{ color: 'rgba(255,255,255,0.3)', fontSize: 11.5 }}>vs. mês anterior</span>
                <div style={{ flex: 1 }} />
                <span style={{ color: 'rgba(255,255,255,0.45)', fontSize: 11.5 }}>
                    {total} resp{pendentes > 0 && (
                        <> · <span style={{ color: COL_ATENCAO }}>{pendentes} pend</span></>
                    )}
                </span>
            </div>
        </div>
    );
}

// ═══ ChartCard — LineChart glass com legenda-toggle ══════════════════════
function ChartCard({ serie, cards, lines, setLines }) {
    const temDados = Array.isArray(serie) && serie.some(s => (s.estrategista ?? 0) > 0 || (s.analista ?? 0) > 0 || (s.empresa ?? 0) > 0);

    const legPill = (on, dot, label, value) => ({
        display: 'inline-flex', alignItems: 'center', gap: 7,
        height: 30, padding: '0 12px', borderRadius: 999,
        border: '1px solid ' + (on ? 'rgba(255,255,255,0.14)' : 'rgba(255,255,255,0.06)'),
        background: on ? 'rgba(255,255,255,0.06)' : 'rgba(255,255,255,0.02)',
        color: on ? '#eef' : 'rgba(255,255,255,0.35)',
        fontSize: 12, fontWeight: 600, cursor: 'pointer',
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
                        <span style={{ width: 8, height: 8, borderRadius: '50%', background: COL_ESTRATEGISTA_LINE, boxShadow: `0 0 6px ${COL_ESTRATEGISTA_LINE}` }} />
                        Estrategista <span style={{ opacity: 0.7 }}>{formatNota(cards.estrategista?.media) ?? '—'}</span>
                    </button>
                    <button type="button" onClick={() => setLines(v => ({ ...v, ana: !v.ana }))} style={legPill(lines.ana)}>
                        <span style={{ width: 8, height: 8, borderRadius: '50%', background: COL_ANALISTA, boxShadow: `0 0 6px ${COL_ANALISTA}` }} />
                        Analista <span style={{ opacity: 0.7 }}>{formatNota(cards.analista?.media) ?? '—'}</span>
                    </button>
                    <button type="button" onClick={() => setLines(v => ({ ...v, emp: !v.emp }))} style={legPill(lines.emp)}>
                        <span style={{ width: 8, height: 8, borderRadius: '50%', background: COL_EMPRESA, boxShadow: `0 0 6px ${COL_EMPRESA}` }} />
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
                        <LineChart data={serie} margin={{ top: 8, right: 12, left: 0, bottom: 4 }}>
                            <defs>
                                <linearGradient id="areaEmpresa" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%"   stopColor={COL_EMPRESA} stopOpacity={0.3} />
                                    <stop offset="100%" stopColor={COL_EMPRESA} stopOpacity={0}   />
                                </linearGradient>
                                <filter id="glowLine" x="-50%" y="-50%" width="200%" height="200%">
                                    <feGaussianBlur stdDeviation="3" />
                                </filter>
                            </defs>
                            <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.05)" vertical={false} />
                            <XAxis dataKey="mes" stroke="rgba(255,255,255,0.4)" fontSize={11} tickLine={false} axisLine={false} interval={0} />
                            <YAxis domain={[1, 5]} stroke="rgba(255,255,255,0.4)" fontSize={11} ticks={[1, 2, 3, 4, 5]} tickLine={false} axisLine={false} />
                            <Tooltip
                                contentStyle={{ background: 'rgba(15,17,22,0.95)', border: '1px solid rgba(255,255,255,0.09)', borderRadius: 10, fontSize: 12, backdropFilter: 'blur(12px)' }}
                                labelStyle={{ color: 'rgba(255,255,255,0.6)' }}
                                itemStyle={{ color: '#eef' }}
                                formatter={(v) => formatNota(v)}
                            />
                            {lines.emp && (
                                <Area type="monotone" dataKey="empresa" stroke="transparent" fill="url(#areaEmpresa)" isAnimationActive={false} />
                            )}
                            {/* Halo (linha borrada por baixo) — visual glow */}
                            {lines.est && (
                                <Line type="monotone" dataKey="estrategista" stroke={COL_ESTRATEGISTA_LINE} strokeWidth={5} strokeOpacity={0.25} dot={false} filter="url(#glowLine)" isAnimationActive={false} />
                            )}
                            {lines.ana && (
                                <Line type="monotone" dataKey="analista" stroke={COL_ANALISTA} strokeWidth={5} strokeOpacity={0.25} dot={false} filter="url(#glowLine)" isAnimationActive={false} />
                            )}
                            {lines.emp && (
                                <Line type="monotone" dataKey="empresa" stroke={COL_EMPRESA} strokeWidth={5} strokeOpacity={0.25} dot={false} filter="url(#glowLine)" isAnimationActive={false} />
                            )}
                            {/* Linhas principais */}
                            {lines.est && (
                                <Line type="monotone" dataKey="estrategista" name="Estrategista" stroke={COL_ESTRATEGISTA_LINE} strokeWidth={2.6} dot={false} activeDot={{ r: 5, fill: COL_ESTRATEGISTA_LINE, stroke: 'rgba(255,255,255,0.15)', strokeWidth: 2 }} isAnimationActive={false} />
                            )}
                            {lines.ana && (
                                <Line type="monotone" dataKey="analista" name="Analista" stroke={COL_ANALISTA} strokeWidth={2.6} dot={false} activeDot={{ r: 5, fill: COL_ANALISTA, stroke: 'rgba(255,255,255,0.15)', strokeWidth: 2 }} isAnimationActive={false} />
                            )}
                            {lines.emp && (
                                <Line type="monotone" dataKey="empresa" name="Empresa" stroke={COL_EMPRESA} strokeWidth={2.6} dot={false} activeDot={{ r: 5, fill: COL_EMPRESA, stroke: 'rgba(255,255,255,0.15)', strokeWidth: 2 }} isAnimationActive={false} />
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

function TableCard({ surveys, activeStatus, setActiveStatus, sort, setSort, onOpenSurvey, onCopyLink }) {
    // Contagens client-side sobre a página atual.
    const contagens = useMemo(() => {
        const list = surveys.data ?? [];
        return {
            todos: list.length,
            completed: list.filter(s => s.status === 'completed').length,
            pending:   list.filter(s => s.status === 'pending').length,
            expired:   list.filter(s => s.status === 'expired').length,
        };
    }, [surveys.data]);

    const filtrados = useMemo(() => {
        const list = surveys.data ?? [];
        const f = activeStatus === 'todos' ? list : list.filter(s => s.status === activeStatus);
        // Sort client-side por company/date
        const dir = sort.dir === 'asc' ? 1 : -1;
        return [...f].sort((a, b) => {
            if (sort.key === 'company') {
                return dir * (a.company_name || '').localeCompare(b.company_name || '');
            }
            // por data (created_at "dd/mm/yyyy hh:mm" — comparação de string bem-formada funciona invertido)
            return dir * (a.created_at || '').localeCompare(b.created_at || '');
        });
    }, [surveys.data, activeStatus, sort]);

    const chipStyle = (key) => {
        const active = activeStatus === key;
        return {
            display: 'inline-flex', alignItems: 'center', gap: 7,
            height: 32, padding: '0 13px', borderRadius: 999,
            border: '1px solid ' + (active ? 'transparent' : 'rgba(255,255,255,0.08)'),
            background: active ? GRAD_ECF : 'rgba(255,255,255,0.03)',
            color: active ? '#fff' : 'rgba(255,255,255,0.6)',
            fontSize: 12.5, fontWeight: 600, cursor: 'pointer',
            boxShadow: active ? '0 6px 18px -6px rgba(232,67,147,0.5)' : 'none',
            transition: 'all .18s ease',
        };
    };

    const toggleSort = (key) => {
        setSort(s => s.key === key ? { key, dir: s.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: 'asc' });
    };

    const gridCols = '1.5fr 1.4fr 1.2fr 1.7fr 1.1fr 1fr 60px';

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
                </div>
                <div style={{
                    display: 'inline-flex', alignItems: 'center', gap: 8,
                    height: 32, padding: '0 12px', borderRadius: 10,
                    background: 'rgba(255,255,255,0.03)',
                    border: '1px solid rgba(255,255,255,0.08)',
                    color: 'rgba(255,255,255,0.4)', fontSize: 12.5,
                }}>
                    <Search size={14} />Buscar empresa
                </div>
            </div>

            {/* Header da grid */}
            <div style={{
                display: 'grid', gridTemplateColumns: gridCols, gap: 12,
                padding: '11px 18px', borderBottom: '1px solid rgba(255,255,255,0.05)',
                background: 'rgba(255,255,255,0.015)',
            }}>
                <button type="button" onClick={() => toggleSort('company')} style={{
                    textAlign: 'left', background: 'none', border: 'none', padding: 0,
                    cursor: 'pointer', color: 'rgba(255,255,255,0.4)',
                    fontSize: 10.5, fontWeight: 700, letterSpacing: '0.08em',
                    display: 'inline-flex', alignItems: 'center', gap: 4,
                }}>
                    EMPRESA {sort.key === 'company' ? (sort.dir === 'asc' ? '↑' : '↓') : ''}
                </button>
                <span style={{ color: 'rgba(255,255,255,0.4)', fontSize: 10.5, fontWeight: 700, letterSpacing: '0.08em' }}>NOTAS (E · A · EMP)</span>
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

                return (
                    <div key={s.id} style={{
                        display: 'grid', gridTemplateColumns: gridCols, gap: 12,
                        padding: '13px 18px', borderBottom: '1px solid rgba(255,255,255,0.04)',
                        alignItems: 'center',
                    }}>
                        <div style={{ minWidth: 0 }}>
                            <div style={{
                                color: '#eef', fontSize: 13.5, fontWeight: 600,
                                whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis',
                            }}>{s.company_name}</div>
                            <span style={{
                                display: 'inline-flex', alignItems: 'center', height: 18, padding: '0 7px',
                                marginTop: 4, borderRadius: 5,
                                background: s.auto_generated ? 'rgba(255,255,255,0.06)' : 'rgba(255,184,32,0.12)',
                                color: s.auto_generated ? 'rgba(255,255,255,0.6)' : '#ffb020',
                                fontSize: 9.5, fontWeight: 700, letterSpacing: '0.04em',
                            }}>{s.auto_generated ? 'MENSAL' : 'MANUAL'}</span>
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
                        </div>

                        <div style={{ textAlign: 'right' }}>
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
                        </div>
                    </div>
                );
            })}

            {/* Rodapé + paginação (server-side) */}
            <div style={{
                display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                padding: '13px 18px', color: 'rgba(255,255,255,0.4)', fontSize: 12,
            }}>
                <span>Mostrando {filtrados.length} de {surveys.total ?? filtrados.length}</span>
                {surveys.last_page > 1 && (
                    <div style={{ display: 'flex', gap: 6 }}>
                        {Array.from({ length: Math.min(surveys.last_page, 6) }).map((_, i) => {
                            const page = i + 1;
                            const active = page === surveys.current_page;
                            return (
                                <span key={page} style={{
                                    width: 28, height: 28, borderRadius: 8,
                                    background: active ? GRAD_ECF : 'rgba(255,255,255,0.03)',
                                    border: active ? 'none' : '1px solid rgba(255,255,255,0.08)',
                                    color: active ? '#fff' : 'rgba(255,255,255,0.6)',
                                    display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
                                    fontWeight: active ? 700 : 500, fontSize: 12.5,
                                    boxShadow: active ? '0 4px 14px -4px rgba(232,67,147,0.5)' : 'none',
                                    cursor: active ? 'default' : 'pointer',
                                }}
                                onClick={() => !active && router.get(route('nps.index'), { page }, { preserveState: true, preserveScroll: true })}>
                                    {page}
                                </span>
                            );
                        })}
                    </div>
                )}
            </div>
        </div>
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
    pode_filtrar_por_pessoa = false,
    cards = {},
    serie_12m = [],
    mes_filtro = '',
    filtros = {},
}) {
    const { flash, auth } = usePage().props;
    const isAdmin = auth?.user?.role === 'admin';

    // ─── Modais ──────────────────────────────────────────────────────────
    const [open, setOpen] = useState(false);
    const [linkDialog, setLinkDialog] = useState(false);
    const [generatedLink, setGeneratedLink] = useState('');
    const [copied, setCopied] = useState(false);
    const [modalSurvey, setModalSurvey] = useState(null);

    // ─── Client-side state ───────────────────────────────────────────────
    const [activeStatus, setActiveStatus] = useState('todos');
    const [lines, setLines] = useState({ est: true, ana: true, emp: true });
    const [sort, setSort] = useState({ key: 'date', dir: 'desc' });

    const { data, setData, post, processing, reset, errors } = useForm({ company_id: '' });

    useEffect(() => {
        if (flash?.nps_link) {
            setGeneratedLink(flash.nps_link);
            setLinkDialog(true);
        }
    }, [flash?.nps_link]);

    // ─── Filtros server-side ─────────────────────────────────────────────
    const mesOpcoes = useMemo(
        () => serie_12m.map(s => ({ value: s.mes_iso, label: s.mes })),
        [serie_12m]
    );

    const aplicarFiltros = (overrides = {}) => {
        const payload = {
            mes: mes_filtro,
            empresa_id: filtros.empresa_id || undefined,
            estrategista_id: filtros.estrategista_id || undefined,
            analista_id: filtros.analista_id || undefined,
            ...overrides,
        };
        Object.keys(payload).forEach(k => { if (!payload[k]) delete payload[k]; });
        router.get(route('nps.index'), payload, { preserveState: true, preserveScroll: true });
    };

    const handleMesChange = (v) => aplicarFiltros({ mes: v });
    const handleEmpresaChange = (v) => aplicarFiltros({ empresa_id: v === '__all__' ? undefined : v });
    const handleEstrategistaChange = (v) => aplicarFiltros({ estrategista_id: v === '__all__' ? undefined : v });
    const handleAnalistaChange = (v) => aplicarFiltros({ analista_id: v === '__all__' ? undefined : v });

    const submit = (e) => {
        e.preventDefault();
        post(route('nps.generate'), {
            onSuccess: () => { reset(); setOpen(false); },
        });
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

    // ─── Derivados dinâmicos (sem prop nova) ─────────────────────────────
    const pendentesList = useMemo(
        () => (surveys.data ?? []).filter(s => s.status === 'pending'),
        [surveys.data],
    );

    const cobrarLinkAll = () => {
        const lista = pendentesList.map(s => `${s.company_name}: ${s.link}`).join('\n');
        if (lista) {
            navigator.clipboard.writeText(lista);
            alert(`${pendentesList.length} link(s) copiados para a área de transferência. Cole no WhatsApp/e-mail para cobrar.`);
        }
    };

    const sparkEst = useMemo(() => sparkData(serie_12m, 'estrategista'), [serie_12m]);
    const sparkAna = useMemo(() => sparkData(serie_12m, 'analista'),     [serie_12m]);
    const sparkEmp = useMemo(() => sparkData(serie_12m, 'empresa'),      [serie_12m]);

    const deltaEst = useMemo(() => computeDelta(serie_12m, 'estrategista'), [serie_12m]);
    const deltaAna = useMemo(() => computeDelta(serie_12m, 'analista'),     [serie_12m]);
    const deltaEmp = useMemo(() => computeDelta(serie_12m, 'empresa'),      [serie_12m]);

    // ─── Render ──────────────────────────────────────────────────────────
    return (
        <AppLayout title="NPS">
            <div className="relative">
                <div style={{ padding: '22px 0 34px' }}>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>

                        {/* ─── Filter bar ────────────────────────────────────── */}
                        <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                            <GlassSelect
                                icon={Calendar}
                                active
                                value={mes_filtro}
                                onValueChange={handleMesChange}
                                placeholder="Mês..."
                                width={168}
                                options={mesOpcoes.map(m => (
                                    <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>
                                ))}
                            />
                            <GlassSelect
                                value={filtros.empresa_id ? String(filtros.empresa_id) : '__all__'}
                                onValueChange={handleEmpresaChange}
                                placeholder="Todas as empresas"
                                width={210}
                                options={[
                                    <SelectItem key="__all__" value="__all__">Todas as empresas</SelectItem>,
                                    ...companies.map(c => (
                                        <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                    )),
                                ]}
                            />
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
                                    border: 'none', background: GRAD_ECF,
                                    color: '#fff', fontWeight: 800, fontSize: 13,
                                    cursor: 'pointer',
                                    boxShadow: '0 10px 30px -8px rgba(232,67,147,0.6), inset 0 1px 0 rgba(255,255,255,0.25)',
                                }}
                            >
                                <Plus size={16} strokeWidth={2.4} />
                                Gerar Link NPS
                            </button>
                        </div>

                        {/* ─── Faixa de ação (pendentes/expirando) ─────────────── */}
                        <ActionStrip pendentesSurveys={pendentesList} onCobrarLink={cobrarLinkAll} />

                        {/* ─── 3 stat cards ────────────────────────────────────── */}
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16 }}>
                            <StatCard
                                dim="estrategista"
                                kicker="ESTRATEGISTA"
                                icon={Briefcase}
                                color={COL_ESTRATEGISTA}
                                colorLine={COL_ESTRATEGISTA_LINE}
                                valor={cards.estrategista?.media}
                                total={cards.estrategista?.total ?? 0}
                                pendentes={pendentesList.length}
                                delta={deltaEst}
                                sparkline={sparkEst}
                                sparkId="sparkEst"
                            />
                            <StatCard
                                dim="analista"
                                kicker="ANALISTA"
                                icon={UsersIcon}
                                color={COL_ANALISTA}
                                colorLine={COL_ANALISTA}
                                valor={cards.analista?.media}
                                total={cards.analista?.total ?? 0}
                                pendentes={pendentesList.length}
                                delta={deltaAna}
                                sparkline={sparkAna}
                                sparkId="sparkAna"
                            />
                            <StatCard
                                dim="empresa"
                                kicker="EMPRESA"
                                icon={Building2}
                                color={COL_EMPRESA}
                                colorLine={COL_EMPRESA}
                                valor={cards.empresa?.media}
                                total={cards.empresa?.total ?? 0}
                                pendentes={pendentesList.length}
                                delta={deltaEmp}
                                sparkline={sparkEmp}
                                sparkId="sparkEmp"
                            />
                        </div>

                        {/* ─── Chart card ──────────────────────────────────────── */}
                        <ChartCard serie={serie_12m} cards={cards} lines={lines} setLines={setLines} />

                        {/* ─── Table card ──────────────────────────────────────── */}
                        <TableCard
                            surveys={surveys}
                            activeStatus={activeStatus}
                            setActiveStatus={setActiveStatus}
                            sort={sort}
                            setSort={setSort}
                            onOpenSurvey={setModalSurvey}
                            onCopyLink={copyLink}
                        />

                    </div>
                </div>
            </div>

            {/* ═══ Modais (preservados) ═══════════════════════════════════════ */}

            {/* Dialog: gerar link manual */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Gerar Link NPS</DialogTitle>
                        <DialogDescription>
                            Selecione o cliente para gerar o link de avaliação manual.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <Select value={data.company_id} onValueChange={v => setData('company_id', v)} required>
                            <SelectTrigger><SelectValue placeholder="Selecionar empresa..." /></SelectTrigger>
                            <SelectContent>
                                {companies.map(c => (
                                    <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.company_id && <p className="text-destructive text-xs">{errors.company_id}</p>}
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancelar</Button>
                            <Button type="submit" disabled={processing || !data.company_id}>Gerar Link</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog: link gerado */}
            <Dialog open={linkDialog} onOpenChange={setLinkDialog}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <CheckCircle className="h-5 w-5 text-emerald-400" /> Link NPS
                        </DialogTitle>
                        <DialogDescription>
                            Copie e envie este link para o cliente via WhatsApp ou chat da reunião.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex items-center gap-2 p-3 bg-muted rounded-lg">
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

            {/* Dialog: ver respostas (preservado do fix 2026-07-08) */}
            <Dialog open={!!modalSurvey} onOpenChange={(o) => !o && setModalSurvey(null)}>
                <DialogContent className="max-w-2xl bg-ecf-card border border-white/[0.08] max-h-[85vh] p-0 gap-0 flex flex-col overflow-hidden">
                    <DialogHeader className="px-6 pt-6 pb-4 border-b border-white/[0.06] shrink-0">
                        <DialogTitle className="text-white">
                            {modalSurvey?.company_name ?? '—'} — Resposta NPS
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
                                    <NotaCard label="Estrategista" valor={modalSurvey.score_estrategista} />
                                    <NotaCard label="Analista"     valor={modalSurvey.score_analista} />
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
                        </div>
                    )}

                    <DialogFooter className="gap-2 sm:gap-0 sm:justify-between px-6 py-4 border-t border-white/[0.06] shrink-0">
                        {isAdmin && modalSurvey && (
                            <button
                                type="button"
                                onClick={() => {
                                    if (!confirm(`Excluir a resposta de "${modalSurvey.company_name}"? A pesquisa voltará para pendente e poderá ser respondida novamente.`)) return;
                                    router.delete(route('nps.responses.destroy', modalSurvey.id), {
                                        preserveScroll: true,
                                        onSuccess: () => setModalSurvey(null),
                                    });
                                }}
                                className="px-4 py-2 rounded-md bg-rose-500/20 hover:bg-rose-500/30 text-rose-300 border border-rose-500/30 text-sm font-medium"
                            >
                                Excluir resposta
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={() => setModalSurvey(null)}
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
