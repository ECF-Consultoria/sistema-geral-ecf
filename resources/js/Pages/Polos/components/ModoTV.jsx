import { useCallback, useEffect, useMemo, useState } from 'react';
import {
    UserPlus, TrendingUp, Trophy, Activity, Rocket, Megaphone, Target,
    Pause, Play, ChevronLeft, ChevronRight, X, CalendarClock,
} from 'lucide-react';
import { cn, formatCurrencyCompact } from '@/lib/utils';
import { ehReservaProximoMes, somaMetaDoMes, competenciaDe } from '@/lib/polosEntrantes';
import { STATUS_META, STATUS_ORDEM } from './statusMeta';
import { montarCorDoPolo } from './poloCores';

/**
 * ModoTV — o Painel Polos como PAINEL DE PAREDE: sem interação, letra grande e
 * cenas que se revezam sozinhas na TV da empresa.
 *
 * O que ele NÃO é: uma versão "maximizada" da planilha (isso já existe, é a Tela
 * cheia). Aqui só entra número que se lê de longe e que muda a conversa do time:
 * meta de entrantes do mês, faturamento × meta, ranking de polos, distribuição de
 * status e o funil de operação.
 *
 * As 3 cenas do cockpit (faturamento, ranking, status) só existem para admin —
 * `/mlb/polos-painel/financeiro` é admin-only, então quem não é admin vê apenas as
 * cenas montadas a partir de `empresas` (entrantes e operação).
 *
 * Fontes: as mesmas do painel — nada é recalculado por regra própria aqui.
 *  · entrantes/aceites/reserva = `empresa.fase` + coluna "Status entrada" da planilha
 *  · meta de entrantes         = soma de `polos_meta_entrada` do mês corrente
 *  · faturamento/ADS/status    = cockpit (ECF Drive + Adman), já agregado no backend
 *
 * Teclado: ← → troca de cena · espaço pausa · Esc sai.
 */

// ── Fases do funil (strings EXATAS do banco / planilha) ──
const ehAceite = (e) => e.fase === 'Aceite no Projeto';
const ehM0     = (e) => e.fase === 'M0';
const FASES_OPERACAO = ['M1', 'M2', 'M3', 'M4'];

// M1 completo — mesma régua da aba Metas (Decola ativo · anúncio publicado · campanha criada).
const PUBLICADO_ESTAGIOS = ['Estágio 1', 'Estágio 2', 'Estágio 3', 'Concluido'];
const temDecola    = (e) => e.decola === 'Sim';
const temPublicado = (e) => PUBLICADO_ESTAGIOS.includes(e.estagio);
const temCampanha  = (e) => e.campanha_criada === true;

const MESES_BR = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
const HEX = { yellow: '#ffe600', green: '#22c55e', red: '#ef4444', violet: '#a855f7', sky: '#38bdf8', fuchsia: '#e879f9', amber: '#fcd34d' };

const pct = (n, t) => (t > 0 ? Math.round((n / t) * 100) : 0);
const fmtInt = (n) => new Intl.NumberFormat('pt-BR').format(Math.round(Number(n) || 0));

// Dias úteis (seg–sex) que ainda restam no mês, contando hoje. Sem calendário de
// feriados de propósito: o número serve de ritmo ("quantos por dia"), não de prazo.
function diasUteisRestantes(hoje) {
    const fim = new Date(hoje.getFullYear(), hoje.getMonth() + 1, 0);
    let n = 0;
    for (let d = new Date(hoje.getFullYear(), hoje.getMonth(), hoje.getDate()); d <= fim; d.setDate(d.getDate() + 1)) {
        const dia = d.getDay();
        if (dia !== 0 && dia !== 6) n += 1;
    }
    return n;
}

// ── Peças visuais da TV ────────────────────────────────────────────────────────

// Anel de progresso grande. Sem filtro de glow de propósito: o halo do SVG é
// recortado pelo viewport e vira um quadrado luminoso na tela grande.
function AnelTv({ pct: p = 0, cor = HEX.yellow, children, size = 'clamp(220px, 22vw, 380px)' }) {
    const atingido = Math.max(Math.min(Number(p) || 0, 100), 0);
    const stroke = 9;
    const r = 50 - stroke / 2;
    const circ = 2 * Math.PI * r;
    const dash = (atingido / 100) * circ;

    return (
        <div className="relative grid place-items-center" style={{ width: size, height: size }}>
            <svg viewBox="0 0 100 100" className="h-full w-full -rotate-90" aria-hidden="true">
                <circle cx="50" cy="50" r={r} fill="none" stroke="rgba(255,255,255,0.08)" strokeWidth={stroke} />
                {dash > 0 && (
                    <circle cx="50" cy="50" r={r} fill="none" stroke={cor} strokeWidth={stroke} strokeLinecap="round"
                            strokeDasharray={`${dash} ${circ - dash}`} />
                )}
            </svg>
            <div className="absolute inset-0 grid place-items-center text-center leading-none">{children}</div>
        </div>
    );
}

// Barra horizontal com rótulo — usada no ranking e nas listas por polo.
function BarraTv({ label, valor, pct: p, cor = HEX.yellow, sub = null }) {
    return (
        <div className="min-w-0">
            <div className="mb-[0.35em] flex items-baseline justify-between gap-3">
                <span className="truncate font-semibold text-white/85" style={{ fontSize: 'clamp(0.95rem, 1.35vw, 1.6rem)' }}>{label}</span>
                <span className="shrink-0 tabular-nums text-white/60" style={{ fontSize: 'clamp(0.85rem, 1.2vw, 1.45rem)' }}>
                    <b style={{ color: cor }}>{valor}</b>{sub ? <span className="text-white/35"> · {sub}</span> : null}
                </span>
            </div>
            <div className="h-[clamp(10px,1.1vw,18px)] w-full overflow-hidden rounded-full bg-white/[0.07]">
                <div className="h-full rounded-full transition-[width] duration-700"
                     style={{ width: `${Math.max(0, Math.min(100, p))}%`, background: cor }} />
            </div>
        </div>
    );
}

// Tile de número — bloco de leitura rápida (no máximo 4 por linha).
function TileTv({ label, valor, cor = '#ffffff', sub = null, icone: Ico = null }) {
    return (
        <div className="flex flex-col justify-center rounded-2xl border border-white/[0.08] bg-white/[0.03] px-[1.2vw] py-[1.4vh]">
            <span className="flex items-center gap-2 uppercase tracking-widest text-white/40" style={{ fontSize: 'clamp(0.65rem, 0.85vw, 1.05rem)' }}>
                {Ico && <Ico className="shrink-0" size={16} />}{label}
            </span>
            <span className="mt-[0.35em] font-display font-extrabold tabular-nums leading-none"
                  style={{ color: cor, fontSize: 'clamp(1.8rem, 3.4vw, 4.2rem)' }}>{valor}</span>
            {sub && <span className="mt-[0.4em] text-white/45" style={{ fontSize: 'clamp(0.7rem, 1vw, 1.2rem)' }}>{sub}</span>}
        </div>
    );
}

// Casca da cena: título + conteúdo (o conteúdo ocupa o que sobra da tela).
function Cena({ titulo, icone: Ico, badge = null, children }) {
    return (
        <div className="flex min-h-0 flex-1 flex-col gap-[2vh]">
            <div className="flex items-center gap-3">
                {Ico && <Ico className="text-ecf-yellow" style={{ width: 'clamp(22px, 2vw, 40px)', height: 'clamp(22px, 2vw, 40px)' }} />}
                <h2 className="font-display font-extrabold tracking-tight text-white" style={{ fontSize: 'clamp(1.3rem, 2.4vw, 3rem)' }}>{titulo}</h2>
                {badge && (
                    <span className="rounded-full border border-white/[0.1] bg-white/[0.05] px-[0.9em] py-[0.35em] uppercase tracking-widest text-white/50"
                          style={{ fontSize: 'clamp(0.6rem, 0.85vw, 1rem)' }}>{badge}</span>
                )}
            </div>
            <div className="min-h-0 flex-1">{children}</div>
        </div>
    );
}

// Barra de progresso da cena — estado próprio (key={idx}) p/ não re-renderizar a cena inteira.
function ProgressoCena({ segundos, pausado }) {
    const [p, setP] = useState(0);
    useEffect(() => {
        if (pausado) return undefined;
        const passo = 250;
        const id = setInterval(() => setP((v) => Math.min(100, v + (passo / (segundos * 1000)) * 100)), passo);
        return () => clearInterval(id);
    }, [segundos, pausado]);
    return (
        <div className="h-[3px] w-full overflow-hidden bg-white/[0.06]">
            <div className="h-full bg-ecf-yellow/70 transition-[width] duration-200 ease-linear" style={{ width: `${p}%` }} />
        </div>
    );
}

export default function ModoTV({
    empresas = [],
    metasEntrada = [],
    cockpit = null,
    isAdmin = false,
    onSair,
    onAtualizar,
    segundosPorCena = 20,
    minutosParaAtualizar = 5,
}) {
    const [idx, setIdx]         = useState(0);
    const [pausado, setPausado] = useState(false);
    const [agora, setAgora]     = useState(() => new Date());

    // Relógio do cabeçalho (30s bastam — o painel não é cronômetro).
    useEffect(() => {
        const id = setInterval(() => setAgora(new Date()), 30_000);
        return () => clearInterval(id);
    }, []);

    // Recarrega os dados sozinho: a TV fica ligada o dia inteiro e ninguém aperta F5.
    useEffect(() => {
        if (!onAtualizar) return undefined;
        const id = setInterval(() => onAtualizar(), minutosParaAtualizar * 60_000);
        return () => clearInterval(id);
    }, [onAtualizar, minutosParaAtualizar]);

    const mesNome  = MESES_BR[agora.getMonth()];
    const mesAtual = competenciaDe(agora);

    // ── Entrantes (M0) ──
    const entrantes = useMemo(() => empresas.filter(ehM0), [empresas]);
    const aceites   = useMemo(() => empresas.filter(ehAceite), [empresas]);
    const reservas  = useMemo(() => empresas.filter(ehReservaProximoMes), [empresas]);
    const metaMes   = useMemo(() => somaMetaDoMes(metasEntrada, mesAtual), [metasEntrada, mesAtual]);
    const pctMeta   = pct(entrantes.length, metaMes);
    const faltam    = Math.max(0, metaMes - entrantes.length);
    const diasUteis = useMemo(() => diasUteisRestantes(agora), [agora]);
    const ritmo     = faltam > 0 && diasUteis > 0 ? (faltam / diasUteis) : 0;

    const entrantesPorPolo = useMemo(() => {
        const m = {};
        entrantes.forEach((e) => { const k = e.polo || '—'; m[k] = (m[k] ?? 0) + 1; });
        return Object.entries(m).map(([polo, n]) => ({ polo, n })).sort((a, b) => b.n - a.n);
    }, [entrantes]);
    const corPoloEntrantes = useMemo(
        () => montarCorDoPolo(entrantesPorPolo.map((p) => ({ polo: p.polo }))),
        [entrantesPorPolo],
    );

    // ── Operação (M1–M4) ──
    const porFase = useMemo(
        () => FASES_OPERACAO.map((f) => ({ fase: f, n: empresas.filter((e) => e.fase === f).length })),
        [empresas],
    );
    const totalOperacao = useMemo(() => porFase.reduce((s, f) => s + f.n, 0), [porFase]);
    const m1 = useMemo(() => empresas.filter((e) => e.fase === 'M1'), [empresas]);
    const m1Itens = useMemo(() => ([
        { label: 'Decola ativo',      n: m1.filter(temDecola).length,    cor: HEX.sky },
        { label: 'Anúncio publicado', n: m1.filter(temPublicado).length, cor: HEX.yellow },
        { label: 'Campanha criada',   n: m1.filter(temCampanha).length,  cor: HEX.green },
    ]), [m1]);

    // ── Cockpit (admin): faturamento, ranking e status ──
    const polosCk   = cockpit?.polos ?? [];
    const temCk     = isAdmin && polosCk.length > 0 && !cockpit?.erro;
    const totalFat  = useMemo(() => polosCk.reduce((s, p) => s + (Number(p.faturamento) || 0), 0), [polosCk]);
    const totalAtiv = useMemo(() => polosCk.reduce((s, p) => s + (Number(p.ativos) || 0), 0), [polosCk]);
    const totalAds  = useMemo(
        () => polosCk.reduce((s, p) => s + (p.empresas ?? []).reduce((x, e) => x + (Number(e.ads) || 0), 0), 0),
        [polosCk],
    );
    const metaFat    = Number(cockpit?.metaFaturamento) || 0;
    const pctFat     = metaFat > 0 ? Math.round((totalFat / metaFat) * 100) : 0;
    const tetoAds    = (Number(cockpit?.adsLimites?.teto) || 0) * totalAtiv;
    const ranking    = useMemo(() => [...polosCk].sort((a, b) => (Number(b.pct) || 0) - (Number(a.pct) || 0)), [polosCk]);
    const corPoloCk  = useMemo(() => montarCorDoPolo(polosCk), [polosCk]);
    const statusDist = cockpit?.statusDist ?? null;
    const mesRefLabel = cockpit?.mesRefLabel ?? null;
    const parcial     = !!cockpit?.parcial;

    // ── Cenas disponíveis (as do cockpit só entram com dado carregado) ──
    const cenas = useMemo(() => {
        const lista = [
            {
                key: 'entrantes',
                render: () => (
                    <Cena titulo={`Entrantes (M0) — ${mesNome}`} icone={UserPlus} badge={metaMes > 0 ? `meta ${metaMes}` : 'sem meta cadastrada'}>
                        <div className="grid h-full grid-cols-1 items-center gap-[3vw] lg:grid-cols-[auto_minmax(0,1fr)]">
                            <div className="flex flex-col items-center gap-[2vh]">
                                <AnelTv pct={metaMes > 0 ? pctMeta : 100} cor={faltam > 0 ? HEX.yellow : HEX.green}>
                                    <div>
                                        <div className="font-display font-extrabold tabular-nums text-white"
                                             style={{ fontSize: metaMes > 0 ? 'clamp(2.6rem, 6vw, 7rem)' : 'clamp(3.5rem, 9vw, 10rem)' }}>
                                            {metaMes > 0 ? `${entrantes.length}/${metaMes}` : entrantes.length}
                                        </div>
                                        {metaMes > 0 && (
                                            <div className="mt-[0.3em] uppercase tracking-widest text-white/45" style={{ fontSize: 'clamp(0.7rem, 1vw, 1.2rem)' }}>
                                                {pctMeta}% da meta
                                            </div>
                                        )}
                                    </div>
                                </AnelTv>
                                <div className="text-center" style={{ fontSize: 'clamp(0.9rem, 1.4vw, 1.7rem)' }}>
                                    {metaMes > 0
                                        ? (faltam > 0
                                            ? <span className="text-white/60">faltam <b className="text-ecf-yellow">{faltam}</b> · ritmo <b className="text-white">{ritmo.toFixed(1)}</b>/dia útil ({diasUteis} dias)</span>
                                            : <span className="font-bold text-emerald-400">meta do mês batida</span>)
                                        : <span className="text-white/45">defina a meta na aba Metas</span>}
                                </div>
                            </div>

                            <div className="flex h-full min-h-0 flex-col justify-center gap-[2.2vh]">
                                <div className="grid grid-cols-3 gap-[1vw]">
                                    <TileTv label="Aceite no projeto" valor={fmtInt(aceites.length)} cor={HEX.fuchsia} icone={CalendarClock} sub="entrando (pré-M0)" />
                                    <TileTv label="Reserva próx. mês" valor={fmtInt(reservas.length)} cor={HEX.violet} sub="status de entrada" />
                                    <TileTv label="Funil de entrada" valor={fmtInt(aceites.length + entrantes.length)} sub="aceites + entrantes" />
                                </div>
                                <div className="flex flex-col gap-[1.4vh]">
                                    {entrantesPorPolo.slice(0, 6).map(({ polo, n }) => (
                                        <BarraTv key={polo} label={polo} valor={fmtInt(n)}
                                                 pct={pct(n, entrantesPorPolo[0]?.n ?? 1)} cor={corPoloEntrantes[polo] ?? HEX.yellow} />
                                    ))}
                                </div>
                            </div>
                        </div>
                    </Cena>
                ),
            },
            {
                key: 'operacao',
                render: () => (
                    <Cena titulo="Operação — empresas por fase" icone={Activity} badge={`${totalOperacao} em M1–M4`}>
                        <div className="grid h-full grid-rows-[auto_minmax(0,1fr)] gap-[3vh]">
                            <div className="grid grid-cols-4 gap-[1.2vw]">
                                {porFase.map(({ fase, n }, i) => (
                                    <TileTv key={fase} label={fase} valor={fmtInt(n)}
                                            cor={[HEX.sky, HEX.yellow, HEX.amber, HEX.green][i]}
                                            sub={`${pct(n, totalOperacao)}% da operação`} />
                                ))}
                            </div>
                            <div className="flex flex-col justify-center gap-[2.4vh] rounded-2xl border border-white/[0.08] bg-white/[0.02] px-[2vw] py-[2vh]">
                                <div className="flex items-center gap-3 text-white/70" style={{ fontSize: 'clamp(0.95rem, 1.5vw, 1.8rem)' }}>
                                    <Rocket className="text-ecf-yellow" size={22} /> Conclusão do M1 — <b className="text-white">{m1.length}</b> empresas
                                </div>
                                {m1Itens.map(({ label, n, cor }) => (
                                    <BarraTv key={label} label={label} valor={`${n}/${m1.length}`} sub={`${pct(n, m1.length)}%`} pct={pct(n, m1.length)} cor={cor} />
                                ))}
                            </div>
                        </div>
                    </Cena>
                ),
            },
        ];

        if (temCk) {
            lista.splice(1, 0, {
                key: 'faturamento',
                render: () => (
                    <Cena titulo="Faturamento do mês" icone={TrendingUp} badge={`${mesRefLabel ?? ''}${parcial ? ' · parcial' : ' · fechado'}`}>
                        <div className="grid h-full grid-rows-[minmax(0,1fr)_auto] gap-[3vh]">
                            <div className="flex flex-col items-center justify-center gap-[2.5vh]">
                                <div className="text-center leading-none">
                                    <div className="font-display font-extrabold tabular-nums text-ecf-yellow" style={{ fontSize: 'clamp(3rem, 8.5vw, 10rem)' }}>
                                        {formatCurrencyCompact(totalFat)}
                                    </div>
                                    <div className="mt-[0.45em] text-white/45" style={{ fontSize: 'clamp(0.9rem, 1.5vw, 1.9rem)' }}>
                                        de <b className="text-white/80">{formatCurrencyCompact(metaFat)}</b> de meta · <b style={{ color: pctFat >= 100 ? HEX.green : HEX.yellow }}>{pctFat}%</b>
                                    </div>
                                </div>
                                <div className="h-[clamp(16px,1.8vw,28px)] w-full overflow-hidden rounded-full bg-white/[0.07]">
                                    <div className="h-full rounded-full transition-[width] duration-700"
                                         style={{ width: `${Math.min(100, pctFat)}%`, background: pctFat >= 100 ? HEX.green : HEX.yellow }} />
                                </div>
                            </div>
                            <div className="grid grid-cols-4 gap-[1.2vw]">
                                <TileTv label="Empresas ativas" valor={fmtInt(totalAtiv)} sub="M2–M4 na meta" />
                                <TileTv label="Média por empresa" valor={formatCurrencyCompact(totalAtiv > 0 ? totalFat / totalAtiv : 0)} />
                                <TileTv label="ADS investido" valor={formatCurrencyCompact(totalAds)} icone={Megaphone} cor={HEX.sky}
                                        sub={tetoAds > 0 ? `${pct(totalAds, tetoAds)}% do disponível` : null} />
                                <TileTv label="ADS disponível" valor={formatCurrencyCompact(Math.max(0, tetoAds - totalAds))} sub="teto × ativos" />
                            </div>
                        </div>
                    </Cena>
                ),
            });
            lista.splice(2, 0, {
                key: 'ranking',
                render: () => (
                    <Cena titulo="Ranking de polos" icone={Trophy} badge={`${ranking.length} polos`}>
                        <div className="flex h-full flex-col justify-center gap-[1.8vh]">
                            {ranking.slice(0, 8).map((p) => (
                                <BarraTv key={p.polo} label={p.polo} valor={`${Math.round(Number(p.pct) || 0)}%`}
                                         sub={`${formatCurrencyCompact(p.faturamento)} · ${p.ativos} ativas`}
                                         pct={Number(p.pct) || 0}
                                         cor={(Number(p.pct) || 0) >= 100 ? HEX.green : (corPoloCk[p.polo] ?? HEX.yellow)} />
                            ))}
                        </div>
                    </Cena>
                ),
            });
            if (statusDist) {
                lista.splice(3, 0, {
                    key: 'status',
                    render: () => {
                        const total = Number(statusDist.total) || 0;
                        return (
                            <Cena titulo="Distribuição de status" icone={Target} badge={`${total} empresas na meta`}>
                                <div className="grid h-full grid-rows-[minmax(0,1fr)_auto] gap-[3vh]">
                                    <div className="grid grid-cols-4 gap-[1.2vw]">
                                        {STATUS_ORDEM.map((k) => (
                                            <TileTv key={k} label={STATUS_META[k].label} valor={fmtInt(statusDist[k] ?? 0)}
                                                    cor={STATUS_META[k].cor} sub={`${pct(statusDist[k] ?? 0, total)}% do total`} />
                                        ))}
                                    </div>
                                    <div className="flex h-[clamp(24px,3vw,48px)] w-full overflow-hidden rounded-full bg-white/[0.06]">
                                        {STATUS_ORDEM.map((k) => {
                                            const p = pct(statusDist[k] ?? 0, total);
                                            return p > 0 ? <div key={k} style={{ width: `${p}%`, background: STATUS_META[k].cor }} /> : null;
                                        })}
                                    </div>
                                </div>
                            </Cena>
                        );
                    },
                });
            }
        }
        return lista;
    }, [mesNome, metaMes, pctMeta, entrantes.length, faltam, ritmo, diasUteis, aceites.length, reservas.length,
        entrantesPorPolo, corPoloEntrantes, porFase, totalOperacao, m1, m1Itens, temCk, mesRefLabel, parcial,
        totalFat, metaFat, pctFat, totalAtiv, totalAds, tetoAds, ranking, corPoloCk, statusDist]);

    const nCenas = cenas.length;
    const cena   = cenas[Math.min(idx, nCenas - 1)];

    const proxima  = useCallback(() => setIdx((v) => (v + 1) % nCenas), [nCenas]);
    const anterior = useCallback(() => setIdx((v) => (v - 1 + nCenas) % nCenas), [nCenas]);

    // Rotação automática — o timer reinicia a cada troca (manual ou automática).
    useEffect(() => {
        if (pausado || nCenas < 2) return undefined;
        const id = setTimeout(proxima, segundosPorCena * 1000);
        return () => clearTimeout(id);
    }, [idx, pausado, nCenas, proxima, segundosPorCena]);

    // Teclado: ← → troca · espaço pausa · Esc sai.
    useEffect(() => {
        const onKey = (ev) => {
            if (ev.key === 'ArrowRight') { ev.preventDefault(); proxima(); }
            else if (ev.key === 'ArrowLeft') { ev.preventDefault(); anterior(); }
            else if (ev.key === ' ') { ev.preventDefault(); setPausado((v) => !v); }
            else if (ev.key === 'Escape') { onSair?.(); }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [proxima, anterior, onSair]);

    const hora = agora.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    const data = agora.toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long' });

    return (
        <div className="fixed inset-0 z-50 flex flex-col bg-ecf-bg text-white">
            <ProgressoCena key={idx} segundos={segundosPorCena} pausado={pausado} />

            {/* Cabeçalho fixo: identidade + relógio (o resto da tela é da cena) */}
            <div className="flex items-center justify-between gap-4 px-[2.5vw] pt-[2.2vh]">
                <div className="flex items-baseline gap-3">
                    <span className="font-display font-extrabold tracking-tight text-ecf-yellow" style={{ fontSize: 'clamp(1rem, 1.6vw, 2rem)' }}>ECF</span>
                    <span className="uppercase tracking-[0.3em] text-white/35" style={{ fontSize: 'clamp(0.6rem, 0.9vw, 1.05rem)' }}>Projeto Polos</span>
                </div>
                <div className="flex items-center gap-[1.6vw]">
                    <div className="text-right leading-tight">
                        <div className="font-display font-extrabold tabular-nums" style={{ fontSize: 'clamp(1.1rem, 1.8vw, 2.2rem)' }}>{hora}</div>
                        <div className="capitalize text-white/35" style={{ fontSize: 'clamp(0.6rem, 0.85vw, 1rem)' }}>{data}</div>
                    </div>
                    {/* Controles quase invisíveis: a TV não tem mouse — reaparecem no hover. */}
                    <div className="flex items-center gap-1.5 opacity-20 transition-opacity hover:opacity-100">
                        <button type="button" onClick={anterior} title="Cena anterior (←)"
                                className="rounded-lg border border-white/[0.1] bg-white/[0.04] p-2 text-white/70 hover:bg-white/[0.1]"><ChevronLeft size={16} /></button>
                        <button type="button" onClick={() => setPausado((v) => !v)} title={pausado ? 'Retomar (espaço)' : 'Pausar (espaço)'}
                                className="rounded-lg border border-white/[0.1] bg-white/[0.04] p-2 text-white/70 hover:bg-white/[0.1]">
                            {pausado ? <Play size={16} /> : <Pause size={16} />}
                        </button>
                        <button type="button" onClick={proxima} title="Próxima cena (→)"
                                className="rounded-lg border border-white/[0.1] bg-white/[0.04] p-2 text-white/70 hover:bg-white/[0.1]"><ChevronRight size={16} /></button>
                        <button type="button" onClick={() => onSair?.()} title="Sair do Modo TV (Esc)"
                                className="rounded-lg border border-white/[0.1] bg-white/[0.04] p-2 text-white/70 hover:bg-white/[0.1]"><X size={16} /></button>
                    </div>
                </div>
            </div>

            {/* Cena */}
            <div className="flex min-h-0 flex-1 flex-col px-[2.5vw] py-[2.5vh]">
                {cena?.render()}
            </div>

            {/* Rodapé: bolinhas das cenas + estado da rotação */}
            <div className="flex items-center justify-between gap-4 px-[2.5vw] pb-[2vh]">
                <div className="flex items-center gap-2">
                    {cenas.map((c, i) => (
                        <button key={c.key} type="button" onClick={() => setIdx(i)} title={c.key}
                                className={cn('h-2 rounded-full transition-all', i === idx ? 'w-8 bg-ecf-yellow' : 'w-2 bg-white/20 hover:bg-white/40')} />
                    ))}
                </div>
                <span className="uppercase tracking-widest text-white/20" style={{ fontSize: 'clamp(0.55rem, 0.75vw, 0.9rem)' }}>
                    {pausado ? 'pausado · espaço retoma' : `atualiza sozinho a cada ${minutosParaAtualizar} min · esc sai`}
                </span>
            </div>
        </div>
    );
}
