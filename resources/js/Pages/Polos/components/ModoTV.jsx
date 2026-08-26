import { useCallback, useEffect, useMemo, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, X } from 'lucide-react';
import { cn, formatCurrencyCompact } from '@/lib/utils';
import { ehReservaProximoMes, somaMetaDoMes, competenciaDe } from '@/lib/polosEntrantes';
import { STATUS_META, STATUS_ORDEM } from './statusMeta';
import { montarCorDoPolo } from './poloCores';

/**
 * ModoTV — o Painel Polos na TV da empresa. Painel de PAREDE, lido a 4–6 metros.
 *
 * Duas telas, uma por aba do painel (a lente ativa decide qual abre):
 *  · METAS      → Entrantes (M0) × meta do mês, aceites, reserva e entrada por polo.
 *  · FATURAMENTO → faturamento × meta, ADS, ranking de polos e distribuição de status.
 * Dentro da TV as setas ← → alternam entre as duas (sem rotação automática: quem passa
 * na frente tem que ver TUDO da tela de uma vez, não um quinto dela).
 *
 * Régua de legibilidade (TV 50–55" a 1920×1080 ≈ 0,63 mm/px), o que manda no layout:
 *   número herói ≥ 200px · números de KPI ≥ 90px · rótulo ≥ 32px · nada essencial < 28px.
 * Por isso a tela só tem RÓTULO + NÚMERO: frase explicativa, nota de metodologia e
 * parêntese de regra ficam no painel normal, não aqui — cada palavra a mais rouba px
 * do número, que é a única coisa que se lê de longe.
 *
 * A tela de faturamento é admin-only na origem (`/mlb/polos-painel/financeiro` exige
 * admin): sem cockpit carregado ela nem entra na lista — não fica bloco vazio na parede.
 *
 * Fontes (nada é recalculado por regra própria aqui):
 *  · entrantes/aceites/reserva = `empresa.fase` + coluna "Status entrada" da planilha
 *  · meta de entrantes         = soma de `polos_meta_entrada` do mês corrente
 *  · faturamento/ADS/status    = cockpit (ECF Drive + Adman), já agregado no backend
 */

// ── Fases do funil (strings EXATAS do banco / planilha) ──
const ehAceite = (e) => e.fase === 'Aceite no Projeto';
const ehM0     = (e) => e.fase === 'M0';

const MESES_BR = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
const HEX = { yellow: '#ffe600', green: '#22c55e', violet: '#a855f7', sky: '#38bdf8', fuchsia: '#e879f9' };

const pct = (n, t) => (t > 0 ? Math.round((n / t) * 100) : 0);
const fmtInt = (n) => new Intl.NumberFormat('pt-BR').format(Math.round(Number(n) || 0));

// ── Escala tipográfica da parede (mínimos vindos da régua de legibilidade acima) ──
const FT = {
    heroi:    'clamp(4rem, 12vw, 15rem)',      // 230px em 1080p
    subHeroi: 'clamp(1.4rem, 3vw, 4rem)',      //  57px
    kpi:      'clamp(2rem, 4.4vw, 5.5rem)',    //  84px
    titulo:   'clamp(1.1rem, 2vw, 2.6rem)',    //  38px
    rotulo:   'clamp(0.95rem, 1.6vw, 2rem)',   //  30px
    barra:    'clamp(1rem, 1.7vw, 2.2rem)',    //  32px
};

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

// ── Peças da parede ────────────────────────────────────────────────────────────

// Número herói: rótulo curto em cima, valor gigante, linha de apoio e barra grossa.
function Heroi({ rotulo, valor, apoio = null, pct: p = null, cor = HEX.yellow }) {
    return (
        <div className="flex min-w-0 flex-col justify-center">
            <div className="uppercase tracking-[0.18em] text-white/50" style={{ fontSize: FT.titulo }}>{rotulo}</div>
            <div className="mt-[0.12em] font-display font-extrabold tabular-nums leading-[0.9] text-white"
                 style={{ fontSize: FT.heroi }}>{valor}</div>
            {apoio && <div className="mt-[0.25em] font-semibold tabular-nums" style={{ fontSize: FT.subHeroi, color: cor }}>{apoio}</div>}
            {p !== null && (
                <div className="mt-[0.5em] h-[clamp(14px,1.6vw,30px)] w-full overflow-hidden rounded-full bg-white/[0.08]">
                    <div className="h-full rounded-full" style={{ width: `${Math.max(0, Math.min(100, p))}%`, background: cor }} />
                </div>
            )}
        </div>
    );
}

// KPI: rótulo de no máximo 3 palavras + número. Nada mais.
function Kpi({ rotulo, valor, cor = '#ffffff' }) {
    return (
        <div className="flex min-w-0 flex-col justify-center rounded-2xl border border-white/[0.1] bg-white/[0.04] px-[1.4vw] py-[1.6vh]">
            <span className="truncate uppercase tracking-[0.14em] text-white/50" style={{ fontSize: FT.rotulo }}>{rotulo}</span>
            <span className="mt-[0.15em] font-display font-extrabold tabular-nums leading-none"
                  style={{ color: cor, fontSize: FT.kpi }}>{valor}</span>
        </div>
    );
}

// Barra de ranking: nome + valor grande, barra espessa embaixo.
function Barra({ label, valor, pct: p, cor = HEX.yellow }) {
    return (
        <div className="min-w-0">
            <div className="flex items-baseline justify-between gap-4">
                <span className="truncate font-semibold text-white" style={{ fontSize: FT.barra }}>{label}</span>
                <span className="shrink-0 font-display font-extrabold tabular-nums" style={{ fontSize: FT.barra, color: cor }}>{valor}</span>
            </div>
            <div className="mt-[0.3em] h-[clamp(10px,1.1vw,20px)] w-full overflow-hidden rounded-full bg-white/[0.08]">
                <div className="h-full rounded-full" style={{ width: `${Math.max(0, Math.min(100, p))}%`, background: cor }} />
            </div>
        </div>
    );
}

export default function ModoTV({
    empresas = [],
    metasEntrada = [],
    cockpit = null,
    isAdmin = false,
    lenteInicial = 'geral',
    onSair,
    onAtualizar,
    minutosParaAtualizar = 5,
}) {
    const { asset_url } = usePage().props;
    const [agora, setAgora]           = useState(() => new Date());
    const [atualizadoEm, setAtualizado] = useState(() => new Date());

    // Relógio do cabeçalho (30s bastam — o painel não é cronômetro).
    useEffect(() => {
        const id = setInterval(() => setAgora(new Date()), 30_000);
        return () => clearInterval(id);
    }, []);

    // Recarrega os dados sozinho: a TV fica ligada o dia inteiro e ninguém aperta F5.
    useEffect(() => {
        if (!onAtualizar) return undefined;
        const id = setInterval(() => { onAtualizar(); setAtualizado(new Date()); }, minutosParaAtualizar * 60_000);
        return () => clearInterval(id);
    }, [onAtualizar, minutosParaAtualizar]);

    const mesNome  = MESES_BR[agora.getMonth()];
    const mesAtual = competenciaDe(agora);

    // ── Metas: entrantes (M0) × meta do mês ──
    const entrantes = useMemo(() => empresas.filter(ehM0), [empresas]);
    const aceites   = useMemo(() => empresas.filter(ehAceite), [empresas]);
    const reservas  = useMemo(() => empresas.filter(ehReservaProximoMes), [empresas]);
    const metaMes   = useMemo(() => somaMetaDoMes(metasEntrada, mesAtual), [metasEntrada, mesAtual]);
    const pctMeta   = pct(entrantes.length, metaMes);
    const faltam    = Math.max(0, metaMes - entrantes.length);
    const diasUteis = useMemo(() => diasUteisRestantes(agora), [agora]);
    const ritmo     = faltam > 0 && diasUteis > 0 ? (faltam / diasUteis) : 0;

    const porPolo = useMemo(() => {
        const m = {};
        entrantes.forEach((e) => { const k = e.polo || '—'; m[k] = (m[k] ?? 0) + 1; });
        return Object.entries(m).map(([polo, n]) => ({ polo, n })).sort((a, b) => b.n - a.n);
    }, [entrantes]);
    const corPoloEntrantes = useMemo(() => montarCorDoPolo(porPolo.map((p) => ({ polo: p.polo }))), [porPolo]);

    // ── Faturamento: cockpit (admin) ──
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
    const mesRef     = cockpit?.mesRefLabel ?? `${mesNome}/${agora.getFullYear()}`;

    // ── Telas disponíveis (a lente ativa escolhe a inicial) ──
    const telas = useMemo(
        () => (temCk ? ['metas', 'faturamento'] : ['metas']),
        [temCk],
    );
    const [tela, setTela] = useState(() => (lenteInicial === 'metas' ? 'metas' : 'faturamento'));
    // Sem cockpit (não-admin, ou financeiro ainda carregando) só existe a tela de metas.
    const telaAtiva = telas.includes(tela) ? tela : 'metas';
    const idx = telas.indexOf(telaAtiva);

    const proxima  = useCallback(() => setTela(telas[(idx + 1) % telas.length]), [telas, idx]);
    const anterior = useCallback(() => setTela(telas[(idx - 1 + telas.length) % telas.length]), [telas, idx]);

    // Teclado: ← → alternam a tela · Esc sai. Sem rotação automática (é tela única).
    useEffect(() => {
        const onKey = (ev) => {
            if (ev.key === 'ArrowRight') { ev.preventDefault(); proxima(); }
            else if (ev.key === 'ArrowLeft') { ev.preventDefault(); anterior(); }
            else if (ev.key === 'Escape') { onSair?.(); }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [proxima, anterior, onSair]);

    const hora = agora.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    const horaAtualizacao = atualizadoEm.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });

    return (
        <div className="fixed inset-0 z-50 flex flex-col overflow-hidden bg-ecf-bg text-white">
            {/* Cabeçalho: LOGO da empresa (não texto) + tela + mês + relógio */}
            <div className="flex items-center justify-between gap-[2vw] px-[3vw] pt-[3vh]">
                <div className="flex min-w-0 items-center gap-[1.6vw]">
                    <img
                        src={`${asset_url ?? ''}/images/logo.png`}
                        alt="ECF Consultoria"
                        className="ecf-logo w-auto object-contain"
                        style={{ height: 'clamp(28px, 3.4vw, 72px)' }}
                        onError={(e) => { e.currentTarget.style.display = 'none'; }}
                    />
                    <span className="truncate uppercase tracking-[0.3em] text-white/45" style={{ fontSize: FT.rotulo }}>
                        {telaAtiva === 'metas' ? 'Metas · Polos' : 'Faturamento Polos'}
                    </span>
                </div>
                <div className="flex items-center gap-[1.6vw]">
                    <span className="uppercase tracking-[0.18em] text-white/45" style={{ fontSize: FT.rotulo }}>{mesRef}</span>
                    <span className="font-display font-extrabold tabular-nums text-white" style={{ fontSize: FT.titulo }}>{hora}</span>
                    {/* Controles quase invisíveis: a TV não tem mouse — reaparecem no hover. */}
                    <div className="flex items-center gap-1.5 opacity-10 transition-opacity hover:opacity-100">
                        {telas.length > 1 && (
                            <>
                                <button type="button" onClick={anterior} title="Tela anterior (←)"
                                        className="rounded-lg border border-white/[0.1] bg-white/[0.04] p-2 text-white/70 hover:bg-white/[0.1]"><ChevronLeft size={16} /></button>
                                <button type="button" onClick={proxima} title="Próxima tela (→)"
                                        className="rounded-lg border border-white/[0.1] bg-white/[0.04] p-2 text-white/70 hover:bg-white/[0.1]"><ChevronRight size={16} /></button>
                            </>
                        )}
                        <button type="button" onClick={() => onSair?.()} title="Sair do Modo TV (Esc)"
                                className="rounded-lg border border-white/[0.1] bg-white/[0.04] p-2 text-white/70 hover:bg-white/[0.1]"><X size={16} /></button>
                    </div>
                </div>
            </div>

            {/* Corpo: TELA ÚNICA — tudo visível de uma vez, sem rolagem e sem rodízio. */}
            <div className="min-h-0 flex-1 px-[3vw] py-[3vh]">
                {telaAtiva === 'metas' ? (
                    <div className="grid h-full grid-cols-1 gap-[3vw] lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
                        {/* Herói: o número da meta do mês */}
                        <Heroi
                            rotulo={`Entrantes (M0) — ${mesNome}/${agora.getFullYear()}`}
                            valor={metaMes > 0 ? `${entrantes.length}/${metaMes}` : fmtInt(entrantes.length)}
                            apoio={metaMes > 0
                                ? (faltam > 0 ? `faltam ${faltam} · ${pctMeta}%` : `meta batida · ${pctMeta}%`)
                                : 'meta do mês não cadastrada'}
                            pct={metaMes > 0 ? pctMeta : null}
                            cor={faltam > 0 ? HEX.yellow : HEX.green}
                        />

                        {/* Coluna de apoio: KPIs do funil + entrada por polo */}
                        <div className="grid min-h-0 grid-rows-[auto_minmax(0,1fr)] gap-[2.5vh]">
                            <div className="grid grid-cols-3 gap-[1.2vw]">
                                <Kpi rotulo="Aceites" valor={fmtInt(aceites.length)} cor={HEX.fuchsia} />
                                <Kpi rotulo="Reserva" valor={fmtInt(reservas.length)} cor={HEX.violet} />
                                <Kpi rotulo={faltam > 0 ? 'Ritmo/dia' : 'Dias úteis'} valor={faltam > 0 ? ritmo.toFixed(1) : fmtInt(diasUteis)} />
                            </div>
                            <div className="flex min-h-0 flex-col justify-center gap-[2vh]">
                                {porPolo.slice(0, 5).map(({ polo, n }) => (
                                    <Barra key={polo} label={polo} valor={fmtInt(n)}
                                           pct={pct(n, porPolo[0]?.n ?? 1)} cor={corPoloEntrantes[polo] ?? HEX.yellow} />
                                ))}
                            </div>
                        </div>
                    </div>
                ) : (
                    <div className="grid h-full grid-rows-[auto_minmax(0,1fr)] gap-[3vh]">
                        {/* Linha de cima: herói do faturamento + KPIs de ADS */}
                        <div className="grid grid-cols-1 gap-[3vw] lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
                            <Heroi
                                rotulo="Faturamento do mês"
                                valor={formatCurrencyCompact(totalFat)}
                                apoio={metaFat > 0 ? `de ${formatCurrencyCompact(metaFat)} · ${pctFat}%` : null}
                                pct={metaFat > 0 ? pctFat : null}
                                cor={pctFat >= 100 ? HEX.green : HEX.yellow}
                            />
                            <div className="grid grid-cols-2 gap-[1.2vw]">
                                <Kpi rotulo="Ativas" valor={fmtInt(totalAtiv)} />
                                <Kpi rotulo="Média/empresa" valor={formatCurrencyCompact(totalAtiv > 0 ? totalFat / totalAtiv : 0)} />
                                <Kpi rotulo="ADS investido" valor={formatCurrencyCompact(totalAds)} cor={HEX.sky} />
                                <Kpi rotulo="ADS disponível" valor={formatCurrencyCompact(Math.max(0, tetoAds - totalAds))} />
                            </div>
                        </div>

                        {/* Linha de baixo: ranking dos polos + distribuição de status.
                            4 polos, não 5: com 5 a coluna passa da altura útil em 1080p e a
                            última barra sai cortada — nesta tela o herói já come 400px. */}
                        <div className="grid min-h-0 grid-cols-1 gap-[3vw] lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
                            <div className="flex min-h-0 flex-col justify-center gap-[1.6vh]">
                                {ranking.slice(0, 4).map((p) => (
                                    <Barra key={p.polo} label={p.polo} valor={`${Math.round(Number(p.pct) || 0)}%`}
                                           pct={Number(p.pct) || 0}
                                           cor={(Number(p.pct) || 0) >= 100 ? HEX.green : (corPoloCk[p.polo] ?? HEX.yellow)} />
                                ))}
                            </div>
                            {statusDist && (
                                <div className="grid grid-cols-2 gap-[1.2vw]">
                                    {STATUS_ORDEM.map((k) => (
                                        <Kpi key={k} rotulo={STATUS_META[k].label} valor={fmtInt(statusDist[k] ?? 0)} cor={STATUS_META[k].cor} />
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                )}
            </div>

            {/* Rodapé: qual tela está no ar (a outra fica a uma seta de distância) + hora da última carga */}
            <div className="flex items-center justify-between gap-4 px-[3vw] pb-[2.5vh]">
                <div className="flex items-center gap-[1vw]">
                    {telas.map((t) => (
                        <button key={t} type="button" onClick={() => setTela(t)}
                                className={cn('rounded-full uppercase tracking-[0.2em] transition-colors',
                                    t === telaAtiva ? 'bg-ecf-yellow/15 text-ecf-yellow' : 'text-white/25 hover:text-white/50')}
                                style={{ fontSize: FT.rotulo, padding: '0.3em 0.9em' }}>
                            {t === 'metas' ? 'Metas' : 'Faturamento'}
                        </button>
                    ))}
                </div>
                <span className="uppercase tracking-[0.2em] text-white/25" style={{ fontSize: FT.rotulo }}>
                    atualizado {horaAtualizacao}
                </span>
            </div>
        </div>
    );
}
