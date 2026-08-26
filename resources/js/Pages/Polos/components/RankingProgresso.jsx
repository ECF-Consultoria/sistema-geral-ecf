import { useEffect, useMemo, useState } from 'react';
import * as Popover from '@radix-ui/react-popover';
import { AlertTriangle, ShieldAlert, MegaphoneOff } from 'lucide-react';
import { cn, formatCurrency, formatCurrencyCompact } from '@/lib/utils';
import { STATUS_META, STATUS_ORDEM, distribuirStatus } from './statusMeta';
import StatusBadge from './StatusBadge';

/**
 * RankingProgresso — ranking denso por polo. Cada linha abre o PoloDrawer (onPolo).
 *
 * A RÉGUA MUDOU EM 2026-08-26 (pedido do usuário): a barra deixou de ser
 * faturamento ÷ meta do polo e passou a ser a DISTRIBUIÇÃO DE STATUS daquela região —
 * o mesmo recorte do donut "Distribuição de status", quebrado por polo. O número da
 * direita é o "% no alvo" (fatia verde), e somar as contagens de todos os polos devolve
 * o donut inteiro.
 *
 * Por que trocou: faturamento ÷ meta produzia percentuais de 5.668% (um polo com um único
 * ativo M2 tem meta de R$ 1.000), o que tornava o ranking ilegível e incomparável entre
 * polos de tamanhos diferentes. O faturamento não sumiu — desceu para a linha de apoio.
 *
 * O badge "Problema" e o contador de alertas (⚠) abrem um popover com AS EMPRESAS
 * do polo que estão com problema (nome + descrição) e as com ADS desligado.
 *
 * Props:
 *   polos     : polos visíveis ({ polo, faturamento, meta, pct, status, ativos, empresas })
 *   corDoPolo : mapa polo → cor (POLO_PALETTE) — usado no ponto de identidade
 *   onPolo    : (polo) => void — abre o drawer
 *   fechado   : mês fechado (CSV)? alertas indisponíveis → exibe "—"
 */

// Popover com o detalhe dos alertas do polo (empresas com problema + ADS desligado).
// `children` é o gatilho (badge/contador) — stopPropagation evita disparar o onPolo da linha.
function DetalheAlertas({ polo, problemas = [], adsOff = [], children }) {
    return (
        <Popover.Root>
            <Popover.Trigger asChild>{children}</Popover.Trigger>
            <Popover.Portal>
                <Popover.Content
                    side="top" align="end" sideOffset={8}
                    className="z-[60] w-72 rounded-xl border border-white/10 bg-ecf-card p-2 text-white shadow-2xl shadow-black/50 data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95"
                >
                    <div className="mb-1 flex items-center gap-1.5 border-b border-white/[0.08] px-1 pb-1.5">
                        <ShieldAlert size={13} className="text-rose-300" />
                        <span className="text-[12px] font-semibold text-white/85">Alertas — {polo}</span>
                    </div>
                    <div className="max-h-64 space-y-1 overflow-y-auto">
                        {problemas.map((e, i) => (
                            <div key={`p-${e.cust_id || 'x'}-${i}`} className="rounded-lg bg-rose-500/[0.06] px-2 py-1.5">
                                <div className="flex items-center gap-1.5">
                                    <ShieldAlert size={11} className="shrink-0 text-rose-300" />
                                    <span className="flex-1 truncate text-[12px] font-medium text-white/90">{e.nome}</span>
                                </div>
                                <p className="mt-0.5 pl-4 text-[11px] leading-snug text-white/55">
                                    {e.problema_nota?.trim() ? e.problema_nota : 'Sem descrição do problema.'}
                                </p>
                            </div>
                        ))}
                        {adsOff.map((e, i) => (
                            <div key={`a-${e.cust_id || 'x'}-${i}`} className="flex items-center gap-1.5 rounded-lg px-2 py-1.5">
                                <MegaphoneOff size={11} className="shrink-0 text-white/40" />
                                <span className="flex-1 truncate text-[12px] text-white/70">{e.nome}</span>
                                <span className="shrink-0 text-[10px] text-white/35">ADS desligado</span>
                            </div>
                        ))}
                        {problemas.length === 0 && adsOff.length === 0 && (
                            <p className="px-2 py-3 text-center text-[11px] text-white/30">Sem alertas neste polo.</p>
                        )}
                    </div>
                </Popover.Content>
            </Popover.Portal>
        </Popover.Root>
    );
}

export default function RankingProgresso({ polos = [], corDoPolo = {}, onPolo, fechado = false }) {
    // Anima a largura das barras 0 → pct na montagem
    const [mounted, setMounted] = useState(false);
    useEffect(() => {
        const id = requestAnimationFrame(() => setMounted(true));
        return () => cancelAnimationFrame(id);
    }, []);

    // Distribuição de status por polo + ordenação por "% no alvo" (empate: polo maior primeiro,
    // senão um polo de 1 ativo no alvo empataria em 100% com um de 40).
    const ord = useMemo(() => polos
        .map((p) => ({ ...p, dist: distribuirStatus(p.empresas) }))
        .sort((a, b) => (b.dist.pctNoAlvo - a.dist.pctNoAlvo) || (b.dist.total - a.dist.total)),
        [polos]);

    // Totais do rodapé — fecham com o donut quando nenhum polo está filtrado fora.
    const geral = useMemo(() => {
        const soma = { 'Sim': 0, 'Em progresso': 0, 'Não': 0, 'Problema': 0 };
        let total = 0;
        ord.forEach((p) => {
            STATUS_ORDEM.forEach((k) => { soma[k] += p.dist.contagem[k]; });
            total += p.dist.total;
        });
        return { soma, total, pctNoAlvo: total > 0 ? Math.round((soma['Sim'] / total) * 100) : 0 };
    }, [ord]);

    return (
        <div className="space-y-2">
        <div className={cn('space-y-0.5', ord.length > 12 && 'max-h-[560px] overflow-y-auto pr-1')}>
            {ord.map((p) => {
                const { contagem, total, noAlvo, pctNoAlvo } = p.dist;
                const cor   = STATUS_META['Sim'].cor;             // o número é a fatia "No alvo"
                const pctFat = Number(p.pct) || 0;                // faturamento ÷ meta (linha de apoio)
                const problemas = fechado ? [] : (p.empresas ?? []).filter((e) => e.problema === true);
                const adsOff    = fechado ? [] : (p.empresas ?? []).filter((e) => e.ads_desligado === true);
                const alertas   = fechado ? 0 : problemas.length + adsOff.length;
                const temDetalhe = alertas > 0;

                return (
                    <div
                        key={p.polo}
                        role="button"
                        tabIndex={0}
                        onClick={() => onPolo?.(p)}
                        onKeyDown={(ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); onPolo?.(p); } }}
                        title={
                            `${p.polo} — ${noAlvo} de ${total} no alvo (${pctNoAlvo}%)\n`
                            + STATUS_ORDEM.map((k) => `${STATUS_META[k].label}: ${contagem[k]}`).join(' · ')
                            + `\nFaturamento: ${formatCurrency(p.faturamento)} / ${formatCurrency(p.meta)} (${pctFat.toFixed(0)}%)`
                        }
                        className="group flex w-full cursor-pointer flex-col gap-1.5 rounded-xl px-3 py-2 text-left transition hover:bg-white/[0.03] focus:outline-none focus:ring-2 focus:ring-ecf-yellow/40"
                    >
                        {/* Linha 1: identidade + alertas + status + % (nome encolhe p/ nunca cortar o badge) */}
                        <div className="flex w-full items-center gap-2">
                            <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: corDoPolo[p.polo] ?? '#fff' }} />
                            <span className="min-w-0 flex-1 truncate text-sm text-white/85">{p.polo}</span>

                            {temDetalhe && (
                                <DetalheAlertas polo={p.polo} problemas={problemas} adsOff={adsOff}>
                                    <button
                                        type="button"
                                        onClick={(ev) => ev.stopPropagation()}
                                        title="Ver empresas com alerta"
                                        className="inline-flex shrink-0 items-center gap-0.5 rounded text-[10px] text-rose-300 outline-none transition hover:text-rose-200 focus:ring-2 focus:ring-ecf-yellow/40"
                                    >
                                        <AlertTriangle size={11} /> {alertas}
                                    </button>
                                </DetalheAlertas>
                            )}

                            {/* Status: se há problema no polo, o badge vira gatilho do popover */}
                            {problemas.length > 0 ? (
                                <DetalheAlertas polo={p.polo} problemas={problemas} adsOff={adsOff}>
                                    <button
                                        type="button"
                                        onClick={(ev) => ev.stopPropagation()}
                                        title="Ver empresas com problema"
                                        className="shrink-0 rounded-full outline-none transition hover:brightness-125 focus:ring-2 focus:ring-ecf-yellow/40"
                                    >
                                        <StatusBadge status={p.status} />
                                    </button>
                                </DetalheAlertas>
                            ) : (
                                <span className="shrink-0"><StatusBadge status={p.status} /></span>
                            )}

                            <span className="w-10 shrink-0 text-right text-sm font-semibold tabular-nums" style={{ color: cor }}>
                                {pctNoAlvo}%
                            </span>
                        </div>

                        {/* Linha 2: barra 100% empilhada com a distribuição de status do polo
                            (mesmas cores do donut) + "n/N no alvo". */}
                        <div className="flex w-full items-center gap-2 pl-[18px]">
                            <div className="relative flex h-2 flex-1 overflow-hidden rounded-full bg-white/[0.06]">
                                {total > 0 && STATUS_ORDEM.map((k) => {
                                    const n = contagem[k];
                                    if (n === 0) return null;
                                    const w = mounted ? (n / total) * 100 : 0;
                                    return (
                                        <span
                                            key={k}
                                            title={`${STATUS_META[k].label}: ${n} de ${total}`}
                                            className="h-full transition-[width] duration-700 ease-out"
                                            style={{ width: `${w}%`, background: STATUS_META[k].cor }}
                                        />
                                    );
                                })}
                            </div>
                            <span className="shrink-0 text-[11px] tabular-nums text-white/55">
                                <b className="text-white/85">{noAlvo}</b><span className="text-white/25">/{total}</span> no alvo
                            </span>
                        </div>

                        {/* Linha 3: faturamento vs meta — deixou de ser a régua da barra, mas
                            continua visível para não perder a leitura financeira do polo. */}
                        <div className="flex w-full items-center gap-1.5 pl-[18px] text-[10px] tabular-nums text-white/30">
                            <span>Faturamento: {formatCurrencyCompact(p.faturamento)} / {formatCurrencyCompact(p.meta)}</span>
                            <span className="text-white/20">·</span>
                            <span>{pctFat.toFixed(0)}% da meta</span>
                        </div>
                    </div>
                );
            })}
        </div>

        {/* Rodapé: legenda + total consolidado. Fecha com o donut "Distribuição de status"
            quando todos os polos estão visíveis (em Polos/Index os chips podem filtrar). */}
        {geral.total > 0 && (
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-white/[0.06] px-3 pt-2 text-[10px] text-white/40">
                {STATUS_ORDEM.map((k) => (
                    <span key={k} className="inline-flex items-center gap-1">
                        <span className="h-2 w-2 rounded-full" style={{ background: STATUS_META[k].cor }} />
                        {STATUS_META[k].label}: <b className="tabular-nums text-white/65">{geral.soma[k]}</b>
                    </span>
                ))}
                <span className="ml-auto tabular-nums">
                    <b className="text-white/70">{geral.soma['Sim']}</b> de <b className="text-white/70">{geral.total}</b> ativos no alvo
                    {' · '}<b style={{ color: STATUS_META['Sim'].cor }}>{geral.pctNoAlvo}%</b>
                </span>
            </div>
        )}
        </div>
    );
}
