import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/Components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogDescription } from '@/Components/ui/dialog';
import { useForm, usePage, router } from '@inertiajs/react';
import { useState, useEffect, useMemo } from 'react';
import {
    Plus, Copy, CheckCircle,
    Briefcase, Users as UsersIcon, Building2, Eye,
    Link2, Search, ChevronDown, ArrowUp, ArrowDown,
    ArrowUpRight, ArrowDownRight, Clock, User,
    Calendar, Star, Trash2, ShieldCheck,
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
    // 2026-07-16 · trava a largura (max-width) e trunca o valor selecionado com
    // ellipsis — nomes de modelo longos ("NPS | Performance ECF Consultoria &
    // Assessoria (principal)") não estouram mais o layout. min-w-0 permite o
    // flex-item encolher abaixo do conteúdo; overflow:hidden clipa; a seta e o
    // ícone não encolhem (shrink-0).
    return (
        <div style={{ ...glassPillStyle(!!active), padding: 0, paddingLeft: Icon ? 10 : 0, width, maxWidth: '100%', overflow: 'hidden' }}>
            <Select value={value} onValueChange={onValueChange}>
                <SelectTrigger
                    className="border-0 bg-transparent hover:bg-transparent focus:ring-0 focus-visible:ring-0 shadow-none h-full w-full min-w-0 px-3 gap-2 [&>svg]:hidden"
                    style={{ fontSize: 13, color: active ? '#eef' : 'rgba(255,255,255,0.6)', fontWeight: active ? 600 : 500 }}
                >
                    {Icon && <Icon size={15} style={{ color: 'rgba(255,255,255,0.5)', flexShrink: 0 }} />}
                    <SelectValue className="min-w-0 flex-1 truncate text-left" placeholder={placeholder} />
                    <ChevronDown size={13} style={{ color: 'rgba(255,255,255,0.4)', marginLeft: 4, flexShrink: 0 }} />
                </SelectTrigger>
                <SelectContent>
                    {options}
                </SelectContent>
            </Select>
        </div>
    );
}

// ═══ StatCard — card de média (layout aprovado 2026-07-16 v4) ════════════
// Ícone + label + "NPS MÉDIO"; pill de delta (↗/↘ +0.00) com "vs. mês anterior";
// nota grande branca /5; barra de progresso colorida pela dimensão (0..5, SEM
// meta); rodapé "X respondidas · Y pendentes" com ícones. Sem ranking.
function StatCard({ kicker, icon: Icon, color, valor, total, pendentes, delta }) {
    const val = formatNota(valor);
    const temNota = total > 0 && valor != null && Number(valor) > 0;
    const pct = temNota ? Math.max(3, Math.min(100, (Number(valor) / 5) * 100)) : 0;
    const semBase = delta == null;   // sem mês anterior com dado → estado neutro "—"
    const subiu = delta > 0, desceu = delta < 0;
    const deltaColor = subiu ? '#19e06a' : desceu ? '#ff8a3c' : 'rgba(255,255,255,0.45)';
    const deltaSinal = delta > 0 ? '+' : '';   // negativo já vem com '-'

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

            {/* Rodapé: respondidas · pendentes (com ícones) */}
            <div style={{ display: 'flex', alignItems: 'center', gap: 18, marginTop: 13, position: 'relative' }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, color: 'rgba(255,255,255,0.55)', fontSize: 12 }}>
                    <User size={13} style={{ color: 'rgba(255,255,255,0.35)' }} />
                    {total} respondida{total === 1 ? '' : 's'}
                </span>
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
            <div style={{ color: 'rgba(255,255,255,0.55)', fontSize: 11, fontWeight: 600, marginBottom: 7 }}>{label}</div>
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

function TableCard({ surveys, contadores = {}, activeStatus, setActiveStatus, sort, setSort, onOpenSurvey, onCopyLink, isAdmin }) {
    // Bugfix 2026-07-16 · contagens vêm AGREGADAS do servidor (conjunto filtrado
    // inteiro), não mais recalculadas sobre surveys.data (só os 20 da página).
    // Os chips deixam de mudar de valor conforme a paginação.
    const contagens = {
        todos:     contadores.todos ?? 0,
        completed: contadores.respondidos ?? 0,
        pending:   contadores.pendentes ?? 0,
        expired:   contadores.expirados ?? 0,
    };

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

    // ─── Seleção para exclusão em massa (admin) ──────────────────────────────
    // 2026-07-13 · Set de ids selecionados. Reseta ao trocar de filtro ou de
    // página (surveys.data muda) pra não excluir algo fora da vista.
    const [selected, setSelected] = useState(new Set());
    useEffect(() => { setSelected(new Set()); }, [activeStatus, surveys.data]);

    const visibleIds = useMemo(() => filtrados.map(s => s.id), [filtrados]);
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
    const CHIP_ACCENT = { todos: ACCENT, completed: '#19e06a', pending: '#ff8a3c', expired: '#f4436b' };
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
    const gridCols = isAdmin
        ? '32px 1.5fr 1.4fr 1.2fr 1.6fr 1.1fr 1fr 78px'
        : '1.5fr 1.4fr 1.2fr 1.7fr 1.1fr 1fr 60px';
    // Largura mínima da grade: abaixo disso as colunas esmagavam texto/chips.
    // O wrapper rola horizontalmente em telas estreitas em vez de quebrar.
    const gridMinWidth = isAdmin ? 940 : 820;

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

            {/* Área rolável (header + linhas) — evita esmagar colunas em telas
                estreitas; header e linhas compartilham a mesma minWidth p/ ficarem
                alinhados durante o scroll. */}
            <div style={{ overflowX: 'auto' }}>
            {/* Header da grid */}
            <div style={{
                display: 'grid', gridTemplateColumns: gridCols, gap: 12,
                minWidth: gridMinWidth,
                padding: '11px 18px', borderBottom: '1px solid rgba(255,255,255,0.05)',
                background: 'rgba(255,255,255,0.015)',
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

                const rowSelected = selected.has(s.id);

                return (
                    <div key={s.id} style={{
                        display: 'grid', gridTemplateColumns: gridCols, gap: 12,
                        minWidth: gridMinWidth,
                        padding: '13px 18px', borderBottom: '1px solid rgba(255,255,255,0.04)',
                        alignItems: 'center',
                        background: rowSelected ? 'rgba(244,67,107,0.06)' : 'transparent',
                    }}>
                        {isAdmin && (
                            <input
                                type="checkbox"
                                checked={rowSelected}
                                onChange={() => toggleOne(s.id)}
                                style={checkboxStyle}
                            />
                        )}
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
                            {/* Fase 95 (AB-95-1) · s.confianca só existe no
                                payload pra admin (Fase 95-01) — a ausência
                                da chave já é o guard (nunca `isAdmin &&`). */}
                            {s.confianca && (
                                <div style={{ marginTop: 4 }}>
                                    <ConfiancaBadge confianca={s.confianca} />
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
                                (inclusive pendente). Cascade no banco limpa respostas. */}
                            {isAdmin && (
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
                                    background: active ? hexAlpha(ACCENT, 0.14) : 'rgba(255,255,255,0.03)',
                                    border: '1px solid ' + (active ? hexAlpha(ACCENT, 0.45) : 'rgba(255,255,255,0.08)'),
                                    color: active ? ACCENT : 'rgba(255,255,255,0.6)',
                                    display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
                                    fontWeight: active ? 700 : 500, fontSize: 12.5,
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
    templates = [],
    pode_filtrar_por_pessoa = false,
    pode_ver_confianca = false, // Fase 95 (AB-95-3) · ausente (não `false`) pra não-admin
    cards = {},
    contadores = {},
    serie_12m = [],
    mes_filtro = '',
    filtros = {},
    principal_template_id = null,
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

    // Ao escolher o modelo: fixa o template_id, zera a empresa (a lista muda) e
    // busca as empresas elegíveis daquele modelo. finally garante o fim do
    // loading mesmo em erro de rede.
    const onSelectModelo = async (id) => {
        setData(d => ({ ...d, template_id: id, company_id: '' }));
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
        post(route('nps.generate'), {
            onSuccess: () => { reset(); setEmpresasElegiveis([]); setOpen(false); },
        });
    };

    // Fecha/reseta o modal gerar-link limpando também a lista de elegíveis.
    const fecharGerarLink = () => {
        reset();
        setEmpresasElegiveis([]);
        setOpen(false);
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

    // ─── Derivados dinâmicos ─────────────────────────────────────────────
    // Pendentes agregados vêm do servidor (contadores), não da página atual.
    const pendentesTotal = contadores.pendentes ?? 0;

    const deltaEst = useMemo(() => computeDelta(serie_12m, 'estrategista'), [serie_12m]);
    const deltaAna = useMemo(() => computeDelta(serie_12m, 'analista'),     [serie_12m]);
    const deltaEmp = useMemo(() => computeDelta(serie_12m, 'empresa'),      [serie_12m]);

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
                                    Acompanhe a satisfação dos seus clientes
                                </p>
                            </div>
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

                        {/* ─── 3 stat cards ────────────────────────────────────── */}
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 14 }}>
                            <StatCard
                                kicker="ESTRATEGISTA"
                                icon={Briefcase}
                                color={COL_ESTRATEGISTA_LINE}
                                valor={cards.estrategista?.media}
                                total={cards.estrategista?.total ?? 0}
                                pendentes={pendentesTotal}
                                delta={deltaEst}
                            />
                            <StatCard
                                kicker="ANALISTA"
                                icon={UsersIcon}
                                color={COL_ANALISTA}
                                valor={cards.analista?.media}
                                total={cards.analista?.total ?? 0}
                                pendentes={pendentesTotal}
                                delta={deltaAna}
                            />
                            <StatCard
                                kicker="EMPRESA"
                                icon={Building2}
                                color={COL_EMPRESA}
                                valor={cards.empresa?.media}
                                total={cards.empresa?.total ?? 0}
                                pendentes={pendentesTotal}
                                delta={deltaEmp}
                            />
                        </div>

                        {/* ─── Chart card ──────────────────────────────────────── */}
                        <ChartCard serie={serie_12m} cards={cards} lines={lines} setLines={setLines} />

                        {/* ─── Table card ──────────────────────────────────────── */}
                        <TableCard
                            surveys={surveys}
                            contadores={contadores}
                            activeStatus={activeStatus}
                            setActiveStatus={setActiveStatus}
                            sort={sort}
                            setSort={setSort}
                            onOpenSurvey={setModalSurvey}
                            onCopyLink={copyLink}
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

                        {/* Passo 2 · EMPRESA (só as elegíveis do modelo). Fica
                            desabilitado até escolher o modelo / durante a carga. */}
                        <div className="space-y-1.5">
                            <label className="text-xs text-white/70 font-medium">2 · Empresa</label>
                            <Select
                                value={data.company_id || undefined}
                                onValueChange={v => setData('company_id', v)}
                                disabled={!data.template_id || carregandoEmpresas}
                                required
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder={
                                        !data.template_id
                                            ? 'Escolha um modelo primeiro'
                                            : (carregandoEmpresas ? 'Carregando empresas...' : 'Selecionar empresa...')
                                    } />
                                </SelectTrigger>
                                <SelectContent>
                                    {empresasElegiveis.length === 0 && !carregandoEmpresas ? (
                                        <div className="px-2 py-1.5 text-xs text-white/50">
                                            Nenhuma empresa elegível para este modelo
                                        </div>
                                    ) : (
                                        empresasElegiveis.map(c => {
                                            // Pitfall 4 (Rollup): derivar value dentro do map.
                                            const value = String(c.id);
                                            return (
                                                <SelectItem key={c.id} value={value}>{c.name}</SelectItem>
                                            );
                                        })
                                    )}
                                </SelectContent>
                            </Select>
                            {errors.company_id && <p className="text-destructive text-xs">{errors.company_id}</p>}
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={fecharGerarLink}>Cancelar</Button>
                            <Button type="submit" disabled={processing || !data.template_id || !data.company_id}>Gerar Link</Button>
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
                        <DialogTitle className="text-white flex items-center gap-2">
                            {modalSurvey?.company_name ?? '—'} — Resposta NPS
                            {/* Fase 95 (AB-95-1/95-2) · guard por existência da
                                chave (não-admin nunca recebe `confianca`). */}
                            <ConfiancaBadge confianca={modalSurvey?.confianca} />
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
