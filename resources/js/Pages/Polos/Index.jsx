import { useState, useMemo } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle, Clock, RefreshCw, X, Megaphone, MegaphoneOff, LayoutList,
    Wallet, Target, Building2,
} from 'lucide-react';
import { formatCurrency, cn } from '@/lib/utils';
import { STATUS_META, STATUS_ORDEM } from './components/statusMeta';
import StatusBadge from './components/StatusBadge';
import HeroKpi from './components/HeroKpi';
import FatVsMetaChart from './components/FatVsMetaChart';
import RankingProgresso from './components/RankingProgresso';
import StatusDonut from './components/StatusDonut';
import SparkSemanal from './components/SparkSemanal';
import AdsCard from './components/AdsCard';
import M1Card from './components/M1Card';
import { montarCorDoPolo } from './components/poloCores';

// Chrome do card de seção do Cockpit (com inner-glow sutil no topo).
const CARD = cn(
    'relative overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5 lg:p-6',
    'before:absolute before:inset-x-0 before:top-0 before:h-px before:bg-gradient-to-r before:from-transparent before:via-white/[0.10] before:to-transparent',
);

/**
 * PolosIndex — Página /polos · "Cockpit ECF — Faturamento por Polo".
 *
 * Redesign command-center (substitui o layout pizza+grade): faixa de KPI heroes,
 * gráfico focal Faturamento vs Meta (bullet/cobertura), ranking de % da meta
 * clicável e donut de distribuição de status. Mantém header, seletor de mês,
 * sincronização, chips de filtro, drawer de empresas e estados de erro/vazio.
 *
 * O CSV POLOS MENSAL empilha ~12 meses; exibe UM mês por vez (default: o mais
 * recente / corrente, parcial, atualizado diariamente pelo ECF Drive).
 *
 * Props de PolosController::index(): polos, statusDist, meses, mesSelecionado,
 * mesRefLabel, parcial, fonteFaturamento, erro.
 */
export default function PolosIndex({
    polos            = [],
    statusDist       = { Sim: 0, 'Em progresso': 0, 'Não': 0, Problema: 0, total: 0 },
    meses            = [],
    mesSelecionado   = null,
    mesRefLabel      = null,
    parcial          = false,
    fonteFaturamento = null,
    adsLimites       = { teto: 3000, alerta1: 1000, alerta2: 2000 },
    m1               = { total: 0, faturando: 0, nao: 0, faturamento: 0, empresas: [], polos: [] },
    erro             = null,
}) {
    // Cor estável por polo (ordem alfabética que o backend já entrega)
    const corDoPolo = useMemo(() => montarCorDoPolo(polos), [polos]);

    // Filtro client-side de polos (default: todos visíveis)
    const [ativos, setAtivos] = useState(() => polos.map((p) => p.polo));
    const visiveis = polos.filter((p) => ativos.includes(p.polo));

    const togglePolo = (nome) => {
        setAtivos((cur) => cur.includes(nome) ? cur.filter((n) => n !== nome) : [...cur, nome]);
    };

    // Troca de mês: recarrega via Inertia com ?mes=YYYYMM
    const trocarMes = (e) => {
        router.get(route('polos.index'), { mes: e.target.value }, { preserveScroll: true, preserveState: false });
    };

    // Botão "Sincronizar": aquece o cache da Adman do mês exibido (roda em background).
    const [sincronizando, setSincronizando] = useState(false);
    const [syncMsg, setSyncMsg] = useState(null);
    const sincronizar = () => {
        router.post(route('polos.sync'), { mes: mesSelecionado }, {
            preserveScroll: true,
            preserveState: true,
            onStart:   () => setSincronizando(true),
            onSuccess: () => setSyncMsg('Sincronização iniciada — recarregue a página em alguns minutos.'),
            onFinish:  () => setSincronizando(false),
        });
    };

    // ── Detalhe por empresa: drawer do polo + semanal sob demanda ──────────────
    const [poloAberto, setPoloAberto] = useState(null);     // objeto do polo (com .empresas)
    const [empresaSel, setEmpresaSel] = useState(null);     // cust_id expandido
    const [semanal, setSemanal]       = useState({});       // cust_id -> { semanas, total, totalAds, loading, erro }

    const abrirEmpresa = (cust) => {
        setEmpresaSel((cur) => (cur === cust ? null : cust));
        if (!semanal[cust]) {
            setSemanal((s) => ({ ...s, [cust]: { loading: true } }));
            fetch(route('polos.empresa.semanal', { cust, mes: mesSelecionado }))
                .then((r) => r.json())
                .then((d) => setSemanal((s) => ({ ...s, [cust]: { ...d, loading: false } })))
                .catch(() => setSemanal((s) => ({ ...s, [cust]: { erro: true, loading: false } })));
        }
    };

    const fecharDrawer = () => { setPoloAberto(null); setEmpresaSel(null); };

    // ── Agregados do Cockpit (recalculados sobre os polos VISÍVEIS — respeitam os chips) ──
    const totalFat    = visiveis.reduce((acc, p) => acc + (p.faturamento ?? 0), 0);
    const totalMeta   = visiveis.reduce((acc, p) => acc + (p.meta ?? 0), 0);
    const totalAtivos = visiveis.reduce((acc, p) => acc + (p.ativos ?? 0), 0);
    const pctGeral    = totalMeta > 0 ? totalFat / totalMeta * 100 : 0;

    // Faturamento agora é sempre da Adman; "fechado" passa a significar mês histórico
    // (não-corrente) — usado p/ sinalizar que ADS só é apurado no mês corrente.
    const fechado = !parcial;

    // KPI "Alertas": empresas com ADS desligado OU problema (booleanos reais — NUNCA o
    // valor e.ads, que vem zerado no /index). Em mês fechado o roster CSV não traz flags.
    const alertas = useMemo(() => {
        let ads = 0, prob = 0, n = 0;
        visiveis.forEach((p) => (p.empresas ?? []).forEach((e) => {
            const a = e.ads_desligado === true, pr = e.problema === true;
            if (a) ads++;
            if (pr) prob++;
            if (a || pr) n++;
        }));
        return { ads, prob, n };
    }, [visiveis]);

    const temDados = !erro && polos.length > 0;

    return (
        <AppLayout title="Faturamento Polos">
            <Head title="Faturamento Polos" />

            <div className="space-y-6 max-w-[1400px] mx-auto">

                {/* ── Cabeçalho + seletor de mês ────────────────────────────── */}
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-white font-display font-extrabold text-2xl tracking-tight">
                            Faturamento por Polo
                        </h1>
                        <div className="flex items-center gap-2">
                            <p className="text-white/40 text-sm">
                                {mesRefLabel ? `Mês de referência: ${mesRefLabel}` : 'Dados ao vivo — ECF Drive'}
                            </p>
                            {parcial && (
                                <span className="inline-flex items-center gap-1 rounded-full border border-ecf-yellow/30 bg-ecf-yellow/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-ecf-yellow">
                                    <Clock size={11} /> Mês parcial
                                </span>
                            )}
                        </div>
                    </div>

                    <div className="flex flex-col items-end gap-1.5">
                        <div className="flex items-center gap-2">
                            {/* Visão completa: abre a página de todas as empresas em nova aba */}
                            {temDados && (
                                <a
                                    href={route('polos.empresas', { mes: mesSelecionado })}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    title="Abrir a visão completa de todas as empresas em nova aba"
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-white/[0.12] bg-white/[0.04] px-3 py-1.5 text-sm font-semibold text-white/80 transition hover:bg-white/[0.08]"
                                >
                                    <LayoutList size={14} /> Todas as empresas
                                </a>
                            )}

                            {/* Sincronizar: aquece o faturamento do dia direto da Adman */}
                            <button
                                type="button"
                                onClick={sincronizar}
                                disabled={sincronizando}
                                title="Puxa o faturamento do dia direto da Adman (roda em background, leva alguns minutos)"
                                className="inline-flex items-center gap-1.5 rounded-lg border border-ecf-yellow/30 bg-ecf-yellow/10 px-3 py-1.5 text-sm font-semibold text-ecf-yellow transition hover:bg-ecf-yellow/20 disabled:opacity-50"
                            >
                                <RefreshCw size={14} className={cn(sincronizando && 'animate-spin')} />
                                {sincronizando ? 'Iniciando…' : 'Sincronizar'}
                            </button>

                            {meses.length > 0 && (
                                <>
                                    <label className="text-white/40 text-xs uppercase tracking-wider ml-1">Mês</label>
                                    <select
                                        value={mesSelecionado ?? ''}
                                        onChange={trocarMes}
                                        className="rounded-lg border border-white/[0.1] bg-ecf-card px-3 py-1.5 text-sm text-white/90 outline-none focus:border-ecf-yellow/40"
                                    >
                                        {meses.map((m) => (
                                            <option key={m.value} value={m.value} className="bg-ecf-card text-white">
                                                {m.label}{m.parcial ? ' (parcial)' : ''}
                                            </option>
                                        ))}
                                    </select>
                                </>
                            )}
                        </div>
                        {syncMsg && (
                            <span className="text-[11px] text-ecf-yellow/80">{syncMsg}</span>
                        )}
                    </div>
                </div>

                {/* ── Estado de erro: ECF Drive offline ─────────────────────── */}
                {erro && (
                    <div className={cn('flex items-start gap-3 rounded-xl p-4', 'border border-red-500/20 bg-red-500/[0.06]')}>
                        <AlertTriangle size={18} className="text-red-400 mt-0.5 shrink-0" />
                        <div>
                            <p className="text-red-300 font-semibold text-sm">Erro ao carregar dados dos polos</p>
                            <p className="text-red-400/70 text-xs mt-0.5">{erro}</p>
                        </div>
                    </div>
                )}

                {/* ── Estado vazio (sem erro) ───────────────────────────────── */}
                {!erro && polos.length === 0 && (
                    <div className={cn('rounded-xl p-6 text-center', 'border border-white/[0.06] bg-white/[0.02]')}>
                        <p className="text-white/40 text-sm">Nenhum polo encontrado para o mês selecionado.</p>
                    </div>
                )}

                {/* ── Faixa de KPI heroes (comando) ─────────────────────────── */}
                {temDados && visiveis.length > 0 && (
                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 animate-fade-in">
                        <HeroKpi
                            titulo="Faturamento total"
                            valor={formatCurrency(totalFat)}
                            icone={Wallet}
                            glow="yellow"
                            sublabel={mesRefLabel ? `${mesRefLabel} · ${parcial ? 'parcial' : 'fechado'}` : null}
                        />
                        <HeroKpi
                            titulo="% Geral da meta"
                            valor={`${pctGeral.toFixed(0)}%`}
                            icone={Target}
                            glow="yellow"
                            sublabel={`${formatCurrency(totalFat)} / ${formatCurrency(totalMeta)}`}
                        />
                        <HeroKpi
                            titulo="Empresas ativas"
                            valor={totalAtivos}
                            icone={Building2}
                            sublabel={`${visiveis.length} ${visiveis.length === 1 ? 'polo' : 'polos'}`}
                        />
                        <HeroKpi
                            titulo="Alertas"
                            valor={fechado ? '—' : alertas.n}
                            icone={AlertTriangle}
                            glow={!fechado && alertas.n > 0 ? 'rose' : 'none'}
                            alerta={!fechado && alertas.n > 0}
                            sublabel={fechado ? 'sem dado de ADS no mês fechado' : `${alertas.ads} ads desligado · ${alertas.prob} problema`}
                        />
                    </div>
                )}

                {/* ── Filtro de polos (chips) ───────────────────────────────── */}
                {temDados && (
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-white/30 text-[11px] uppercase tracking-wider mr-1">Polos</span>
                        {polos.map((p) => {
                            const on = ativos.includes(p.polo);
                            return (
                                <button
                                    key={p.polo}
                                    onClick={() => togglePolo(p.polo)}
                                    className={cn(
                                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs transition-colors',
                                        on
                                            ? 'border-white/15 bg-white/[0.06] text-white/90'
                                            : 'border-white/[0.06] bg-transparent text-white/30',
                                    )}
                                >
                                    <span
                                        className="h-2.5 w-2.5 rounded-full transition-opacity"
                                        style={{ background: corDoPolo[p.polo], opacity: on ? 1 : 0.3 }}
                                    />
                                    {p.polo}
                                </button>
                            );
                        })}
                    </div>
                )}

                {/* ── Gráfico focal + ranking (lado a lado no desktop) ──────── */}
                {temDados && visiveis.length > 0 && (
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 animate-fade-in">
                        {/* Faturamento vs Meta por polo */}
                        <div className={CARD}>
                            <p className="text-white/40 text-xs uppercase tracking-wider mb-1">
                                Faturamento vs Meta por polo
                            </p>
                            <FatVsMetaChart
                                polos={visiveis}
                                corDoPolo={corDoPolo}
                                fonteFaturamento={fonteFaturamento}
                                parcial={parcial}
                            />
                        </div>

                        {/* Ranking de % da meta (clicável → drawer) */}
                        <div className={CARD}>
                            <div className="flex items-center justify-between mb-3">
                                <p className="text-white/40 text-xs uppercase tracking-wider">
                                    % da meta por polo
                                </p>
                                <span className="text-white/25 text-[10px]">clique p/ ver empresas</span>
                            </div>
                            <RankingProgresso
                                polos={visiveis}
                                corDoPolo={corDoPolo}
                                onPolo={setPoloAberto}
                                fechado={fechado}
                            />
                        </div>
                    </div>
                )}

                {/* ── Distribuição de status (donut de comando) ─────────────── */}
                {temDados && statusDist.total > 0 && (
                    <div className={cn(CARD, 'grid grid-cols-1 lg:grid-cols-2 gap-6 items-center animate-fade-in')}>
                        <div className="flex flex-col items-center gap-3">
                            <p className="text-white/40 text-xs uppercase tracking-wider self-start">
                                Distribuição de status — {mesRefLabel}
                            </p>
                            <StatusDonut statusDist={statusDist} height={300} />
                            <p className="text-white/40 text-[11px] self-start">
                                <span className="text-ecf-yellow font-semibold">{statusDist.total}</span> ativos no mês · todos os polos
                            </p>
                        </div>

                        {/* Legenda com label + contagem + % sobre o total de ativos */}
                        <div className="space-y-1.5">
                            {STATUS_ORDEM.map((k) => {
                                const count = statusDist[k] ?? 0;
                                const pct   = statusDist.total > 0 ? (count / statusDist.total * 100) : 0;
                                const { cor, label } = STATUS_META[k];
                                return (
                                    <div key={k} className="flex items-center gap-3 rounded-lg px-3 py-2 hover:bg-white/[0.03]">
                                        <span className="h-3 w-3 rounded-full shrink-0" style={{ background: cor }} />
                                        <span className="text-white/80 text-sm flex-1">{label}</span>
                                        <span className="text-white font-semibold text-sm tabular-nums">{count}</span>
                                        <span className="text-white/40 text-xs tabular-nums w-12 text-right">{pct.toFixed(1)}%</span>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}

                {/* ── Saldo de ADS do mês (disponível teto×ativos vs gasto) ─── */}
                {temDados && visiveis.length > 0 && (
                    <div className={cn(CARD, 'animate-fade-in')}>
                        <AdsCard
                            polos={visiveis}
                            teto={adsLimites?.teto ?? 3000}
                            fechado={fechado}
                            onPolo={setPoloAberto}
                        />
                    </div>
                )}

                {/* ── Empresas M1 (onboarding) — gráfico dedicado ───────────── */}
                {temDados && m1.total > 0 && (
                    <div className={cn(CARD, 'animate-fade-in')}>
                        <M1Card m1={m1} fechado={fechado} />
                    </div>
                )}

                {/* Todos os polos desmarcados */}
                {temDados && visiveis.length === 0 && (
                    <div className={cn('rounded-xl p-6 text-center', 'border border-white/[0.06] bg-white/[0.02]')}>
                        <p className="text-white/40 text-sm">Nenhum polo selecionado. Reative um polo no filtro acima.</p>
                    </div>
                )}
            </div>

            {/* Painel lateral: empresas do polo + faturamento semanal sob demanda */}
            {poloAberto && (
                <PoloDrawer
                    polo={poloAberto}
                    cor={corDoPolo[poloAberto.polo]}
                    mesRefLabel={mesRefLabel}
                    fechado={fechado}
                    empresaSel={empresaSel}
                    semanal={semanal}
                    onEmpresa={abrirEmpresa}
                    onClose={fecharDrawer}
                />
            )}
        </AppLayout>
    );
}

/**
 * PoloDrawer — painel lateral com as empresas do polo. Cada empresa mostra
 * status, faturamento vs meta (%), ads ligado/desligado e problema. Ao clicar,
 * expande o faturamento semanal (Adman, carregado sob demanda) como área neon.
 */
function PoloDrawer({ polo, cor, mesRefLabel, fechado, empresaSel, semanal, onEmpresa, onClose }) {
    const empresas = polo.empresas ?? [];
    const [filtro, setFiltro] = useState('todas');

    const FILTROS = [
        { key: 'todas',        label: 'Todas' },
        { key: 'Sim',          label: 'No alvo' },
        { key: 'Em progresso', label: 'Em progresso' },
        { key: 'Não',          label: 'Não faturou' },
        { key: 'Problema',     label: 'Problema' },
        { key: 'ads',          label: 'Ads desligado' },
    ];

    const empresasFiltradas = empresas.filter((e) => {
        if (filtro === 'todas') return true;
        if (filtro === 'ads')   return e.ads_desligado === true;
        return e.status === filtro;
    });

    return (
        <div className="fixed inset-0 z-50 flex justify-end">
            {/* backdrop */}
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />

            <aside className="relative h-full w-full max-w-xl overflow-y-auto border-l border-white/[0.1] bg-ecf-bg shadow-2xl">
                {/* Cabeçalho do polo */}
                <div className="sticky top-0 z-10 flex items-start justify-between gap-3 border-b border-white/[0.08] bg-ecf-bg/95 px-5 py-4 backdrop-blur">
                    <div className="flex items-center gap-3">
                        <span className="h-3 w-3 rounded-full" style={{ background: cor }} />
                        <div>
                            <h2 className="text-white font-display font-extrabold text-lg leading-tight">{polo.polo}</h2>
                            <p className="text-white/40 text-xs">{empresas.length} empresas · {mesRefLabel}</p>
                        </div>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-lg p-1.5 text-white/50 hover:bg-white/[0.06] hover:text-white">
                        <X size={18} />
                    </button>
                </div>

                {/* Resumo do polo */}
                <div className="grid grid-cols-3 gap-2 px-5 py-4 border-b border-white/[0.06]">
                    <div>
                        <p className="text-white/30 text-[10px] uppercase tracking-wider">Faturamento</p>
                        <p className="text-ecf-yellow font-display font-extrabold text-base mt-0.5">{formatCurrency(polo.faturamento)}</p>
                    </div>
                    <div>
                        <p className="text-white/30 text-[10px] uppercase tracking-wider">Meta</p>
                        <p className="text-white/80 font-display font-extrabold text-base mt-0.5">{formatCurrency(polo.meta)}</p>
                    </div>
                    <div>
                        <p className="text-white/30 text-[10px] uppercase tracking-wider">% da meta</p>
                        <p className="text-white/80 font-display font-extrabold text-base mt-0.5">{polo.pct}%</p>
                    </div>
                </div>

                {/* Filtros rápidos */}
                <div className="flex flex-wrap gap-1.5 px-5 py-3 border-b border-white/[0.06]">
                    {FILTROS.map((f) => {
                        const n = f.key === 'todas'
                            ? empresas.length
                            : f.key === 'ads'
                                ? empresas.filter((e) => e.ads_desligado === true).length
                                : empresas.filter((e) => e.status === f.key).length;
                        const ativo = filtro === f.key;
                        return (
                            <button
                                key={f.key}
                                type="button"
                                onClick={() => setFiltro(f.key)}
                                className={cn(
                                    'rounded-full px-2.5 py-1 text-[11px] font-semibold transition',
                                    ativo ? 'bg-ecf-yellow text-black' : 'bg-white/[0.05] text-white/60 hover:bg-white/[0.1]',
                                )}
                            >
                                {f.label} <span className="tabular-nums opacity-70">{n}</span>
                            </button>
                        );
                    })}
                </div>

                {/* Lista de empresas */}
                <div className="divide-y divide-white/[0.05]">
                    {empresasFiltradas.length === 0 && (
                        <p className="px-5 py-6 text-center text-white/40 text-sm">Nenhuma empresa neste filtro.</p>
                    )}
                    {empresasFiltradas.map((e) => {
                        const expandida = empresaSel === e.cust_id;
                        const sem        = semanal[e.cust_id];
                        const pctBar     = Math.min(e.pct ?? 0, 100);
                        const statusCor  = (STATUS_META[e.status] ?? {}).cor ?? '#94a3b8';
                        return (
                            <div key={e.cust_id} className={cn('px-5 py-3 transition', expandida && 'bg-white/[0.03]')}>
                                <button type="button" onClick={() => onEmpresa(e.cust_id)} className="w-full text-left focus:outline-none">
                                    <div className="flex items-center justify-between gap-3">
                                        <div className="min-w-0 flex items-center gap-2">
                                            <span className="text-white/90 font-semibold text-sm truncate">{e.nome}</span>
                                            <span className="text-white/30 text-[10px] uppercase shrink-0">{e.fase}</span>
                                            {polo.todas && e.polo && (
                                                <span className="text-white/30 text-[10px] shrink-0">· {e.polo}</span>
                                            )}
                                        </div>
                                        <StatusBadge status={e.status} />
                                    </div>

                                    {/* Faturamento + barra % da meta */}
                                    <div className="mt-2 flex items-center gap-3">
                                        <span className="text-white font-semibold text-sm tabular-nums w-28 shrink-0">{formatCurrency(e.faturamento)}</span>
                                        <div className="flex-1 h-1.5 rounded-full bg-white/[0.08] overflow-hidden">
                                            <div className="h-full rounded-full" style={{ width: `${pctBar}%`, background: statusCor }} />
                                        </div>
                                        <span className="text-white/40 text-xs tabular-nums w-14 text-right shrink-0">{e.pct}%</span>
                                    </div>

                                    {/* Badges: ads + problema */}
                                    <div className="mt-2 flex flex-wrap items-center gap-2">
                                        {e.ads_desligado === true && (
                                            <span className="inline-flex items-center gap-1 rounded-full bg-red-500/[0.12] px-2 py-0.5 text-[10px] font-semibold text-red-300">
                                                <MegaphoneOff size={11} /> Ads desligado
                                            </span>
                                        )}
                                        {e.ads_desligado === false && (
                                            <span className="inline-flex items-center gap-1 rounded-full bg-green-500/[0.12] px-2 py-0.5 text-[10px] font-semibold text-green-300">
                                                <Megaphone size={11} /> Ads ligado
                                            </span>
                                        )}
                                        {e.problema && (
                                            <span className="inline-flex items-center gap-1 rounded-full bg-purple-500/[0.15] px-2 py-0.5 text-[10px] font-semibold text-purple-300" title={e.problema_nota || ''}>
                                                <AlertTriangle size={11} /> Problema{e.problema_nota ? ' · ' + e.problema_nota : ''}
                                            </span>
                                        )}
                                    </div>
                                </button>

                                {/* Detalhe semanal (sob demanda) — área neon */}
                                {expandida && (
                                    <div className="mt-3 rounded-lg border border-white/[0.06] bg-white/[0.02] p-3">
                                        <p className="text-white/30 text-[10px] uppercase tracking-wider mb-2">Faturamento por semana</p>
                                        {sem?.loading && <p className="text-white/40 text-xs">Carregando da Adman…</p>}
                                        {sem?.erro && <p className="text-red-300 text-xs">Falha ao buscar o semanal.</p>}
                                        {sem && !sem.loading && !sem.erro && (
                                            <SparkSemanal
                                                semanas={sem.semanas ?? []}
                                                total={sem.total ?? 0}
                                                totalAds={sem.totalAds ?? 0}
                                                fechado={fechado}
                                            />
                                        )}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            </aside>
        </div>
    );
}
