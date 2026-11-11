import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Bar, BarChart, CartesianGrid, LabelList, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { cn } from '@/lib/utils';
import { AbasAnalistaConta, SeloPlanejada, SeloStatus } from '../components/PendenciasConta';

// Série única nos gráficos: slot 1 da paleta de referência para tema escuro, validado contra a
// superfície do cartão (#0f1116) pelo validate_palette (contraste, faixa de luminosidade, croma).
const COR_SERIE = '#3987e5';
const COR_GRADE = 'rgba(255,255,255,0.06)';
const COR_EIXO  = 'rgba(255,255,255,0.45)';

const PERIODOS = [7, 30, 90];

/** Horas até concluir em texto curto: "45 min", "5 h", "2,4 dias". */
const fmtTempo = (h) => {
    if (h === null || h === undefined) return '—';
    if (h < 1) return `${Math.max(1, Math.round(h * 60))} min`;
    if (h < 24) return `${Math.round(h)} h`;
    return `${(h / 24).toFixed(1).replace('.', ',')} dias`;
};

/**
 * Polos/AnalistaConta/Acompanhamento — a visão da coordenação e da liderança (TKT-0035, fatia 3):
 * pendências por status e por quem pediu, as atrasadas, o tempo entre abrir e concluir e o volume
 * concluído por dia e por semana. "Em aberto" é o estado de agora; o resto conta no período.
 */
export default function AcompanhamentoAnalistaConta({ dados }) {
    const r = dados?.resumo ?? {};
    const [carregando, setCarregando] = useState(false);

    const trocarPeriodo = (dias) => router.get(route('mlb.polos-painel.analista-conta.acompanhamento'), { dias }, {
        preserveScroll: true, preserveState: true, onStart: () => setCarregando(true), onFinish: () => setCarregando(false),
    });

    const maxStatus = Math.max(1, ...dados.por_status.map((s) => s.total));

    return (
        <AppLayout title="Acompanhamento do Analista de Conta">
            <Head title="Acompanhamento do Analista de Conta" />

            <div className="space-y-5 max-w-[1240px] mx-auto">
                <div className="flex flex-col gap-1">
                    <h1 className="text-white font-display font-extrabold text-2xl tracking-tight">Acompanhamento do Analista de Conta</h1>
                    <p className="text-white/50 text-sm">
                        Pendências encaminhadas pela fila de revisão dos Polos. Em aberto é o estado de agora; criadas, concluídas e
                        canceladas contam de {dados.periodo.desde} a {dados.periodo.ate}.
                    </p>
                </div>

                <AbasAnalistaConta atual="acompanhamento" podeAcompanhar />

                {/* Período — um só filtro, acima de tudo que ele recorta */}
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className="mr-1 text-[10px] font-semibold uppercase tracking-wider text-white/35">Período</span>
                    {PERIODOS.map((d) => (
                        <button key={d} type="button" onClick={() => trocarPeriodo(d)} aria-pressed={dados.periodo.dias === d}
                                className={cn('rounded-full px-3 py-1 text-xs font-semibold transition',
                                    dados.periodo.dias === d ? 'bg-ecf-yellow text-black' : 'bg-white/[0.05] text-white/60 hover:bg-white/[0.1]')}>
                            Últimos {d} dias
                        </button>
                    ))}
                </div>

                <div className={cn('space-y-5 transition-opacity', carregando && 'opacity-50')}>
                    {/* Números */}
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        <Numero rotulo="Em aberto" valor={r.em_aberto} sub={`${r.sem_data ?? 0} sem data planejada`} />
                        <Numero rotulo="Atrasadas" valor={r.atrasadas} sub="prazo pedido já passou" alerta={(r.atrasadas ?? 0) > 0} />
                        <Numero rotulo="Ficaram para trás" valor={r.para_tras} sub="dia planejado já passou" />
                        <Numero rotulo="Criadas" valor={r.criadas} sub="no período" />
                        <Numero rotulo="Concluídas" valor={r.concluidas} sub={`no período · ${r.canceladas ?? 0} canceladas`} />
                        <Numero rotulo="Tempo médio" valor={fmtTempo(r.tempo_medio_horas)} sub="da abertura à conclusão" />
                    </div>

                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                        {/* Por status */}
                        <Painel titulo="Por status" sub="em aberto agora; concluídas e canceladas no período">
                            <ul className="space-y-2.5">
                                {dados.por_status.map((s) => (
                                    <li key={s.status}>
                                        <div className="flex items-center justify-between gap-2 text-[12px]">
                                            <span className="text-white/70">{s.rotulo}</span>
                                            <span className="font-semibold text-white tabular-nums">{s.total}</span>
                                        </div>
                                        <div className="mt-1 h-2 overflow-hidden rounded-full bg-white/[0.05]">
                                            <div className="h-full rounded-full" style={{ width: `${(s.total / maxStatus) * 100}%`, background: COR_SERIE }} />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </Painel>

                        {/* Volume por dia */}
                        <Painel titulo="Concluídas por dia" sub={`últimos ${dados.periodo.dias} dias`}>
                            <GraficoBarras dados={dados.por_dia} chave="rotulo" alturaPx={220} rotular={false} />
                        </Painel>
                    </div>

                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                        <Painel titulo="Concluídas por semana" sub="últimas 8 semanas, de segunda a domingo">
                            <GraficoBarras dados={dados.por_semana} chave="rotulo" alturaPx={220} rotular prefixoDica="Semana de " />
                        </Painel>

                        {/* Por quem pediu */}
                        <Painel titulo="Por quem pediu" sub="em aberto agora; concluídas e tempo no período">
                            {dados.por_solicitante.length === 0 ? (
                                <p className="py-6 text-center text-[12px] text-white/35">Nenhuma pendência.</p>
                            ) : (
                                <table className="w-full text-[12px]">
                                    <thead>
                                        <tr className="text-left text-[10px] uppercase tracking-wider text-white/35">
                                            <th className="py-1.5 font-semibold">Quem pediu</th>
                                            <th className="py-1.5 text-right font-semibold">Em aberto</th>
                                            <th className="py-1.5 text-right font-semibold">Atrasadas</th>
                                            <th className="py-1.5 text-right font-semibold">Concluídas</th>
                                            <th className="py-1.5 text-right font-semibold">Tempo médio</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-white/[0.05]">
                                        {dados.por_solicitante.map((s) => (
                                            <tr key={s.id ?? 'removido'}>
                                                <td className="py-2 text-white/85">{s.nome}</td>
                                                <td className="py-2 text-right tabular-nums text-white/80">{s.em_aberto}</td>
                                                <td className={cn('py-2 text-right tabular-nums', s.atrasadas > 0 ? 'font-semibold text-red-300' : 'text-white/45')}>{s.atrasadas}</td>
                                                <td className="py-2 text-right tabular-nums text-white/80">{s.concluidas}</td>
                                                <td className="py-2 text-right tabular-nums text-white/60">{fmtTempo(s.tempo_medio_horas)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </Painel>
                    </div>

                    {/* Atrasadas */}
                    <Painel titulo="Atrasadas" sub="prazo pedido já passou e a pendência segue aberta — a mais antiga primeiro">
                        {dados.atrasadas.length === 0 ? (
                            <p className="py-6 text-center text-[12px] text-white/35">Nenhuma pendência atrasada.</p>
                        ) : (
                            <ul className="divide-y divide-white/[0.05]">
                                {dados.atrasadas.map((p) => (
                                    <li key={p.id}>
                                        <Link href={route('mlb.polos-painel.analista-conta', { pendencia: p.id })}
                                              className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-[12px] transition hover:bg-white/[0.02]">
                                            <span className="min-w-[10rem] font-semibold text-white">{p.empresa?.nome}</span>
                                            <span className="text-white/60">{p.tipo_label}</span>
                                            <span className="rounded bg-red-500/15 px-1.5 py-0.5 text-[10.5px] font-semibold text-red-300 tabular-nums">
                                                Atrasada · {p.dias_atraso}d · prazo {p.prazo}
                                            </span>
                                            <SeloStatus p={p} />
                                            <SeloPlanejada p={p} />
                                            <span className="ml-auto text-white/40">por {p.solicitante?.nome}</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Painel>
                </div>
            </div>
        </AppLayout>
    );
}

function Numero({ rotulo, valor, sub, alerta = false }) {
    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] px-4 py-3">
            <p className="text-white/40 text-[10px] font-semibold uppercase tracking-wider">{rotulo}</p>
            <p className={cn('mt-1 font-display text-2xl font-extrabold tabular-nums', alerta ? 'text-red-400' : 'text-white/90')}>{valor ?? 0}</p>
            {sub && <p className="mt-1 text-[11px] leading-snug text-white/40">{sub}</p>}
        </div>
    );
}

function Painel({ titulo, sub, children }) {
    return (
        <section className="rounded-2xl border border-white/[0.08] bg-ecf-card p-4">
            <h2 className="text-sm font-semibold text-white">{titulo}</h2>
            {sub && <p className="mb-3 mt-0.5 text-[11px] text-white/40">{sub}</p>}
            {children}
        </section>
    );
}

/**
 * Colunas de uma série só (sem legenda: o título do painel diz o que é). Barras finas com a
 * ponta arredondada, grade horizontal discreta, dica ao passar o mouse com o valor em destaque.
 * `rotular` põe o número no topo de cada barra — só onde são poucas (as 8 semanas).
 */
function GraficoBarras({ dados, chave, alturaPx, rotular = false, prefixoDica = '' }) {
    const total = dados.reduce((n, d) => n + d.concluidas, 0);
    if (total === 0) {
        return <p className="flex items-center justify-center text-[12px] text-white/35" style={{ height: alturaPx }}>Nenhuma concluída no período.</p>;
    }
    return (
        <div style={{ height: alturaPx }} role="img" aria-label={`Concluídas: ${dados.map((d) => `${prefixoDica}${d[chave]} ${d.concluidas}`).join(', ')}`}>
            <ResponsiveContainer width="100%" height="100%">
                <BarChart data={dados} margin={{ top: rotular ? 18 : 6, right: 4, left: -18, bottom: 0 }} barCategoryGap="22%">
                    <CartesianGrid stroke={COR_GRADE} vertical={false} />
                    <XAxis dataKey={chave} tick={{ fill: COR_EIXO, fontSize: 10 }} tickLine={false} axisLine={{ stroke: COR_GRADE }}
                           interval="preserveStartEnd" minTickGap={14} />
                    <YAxis allowDecimals={false} tick={{ fill: COR_EIXO, fontSize: 10 }} tickLine={false} axisLine={false} width={40} />
                    <Tooltip cursor={{ fill: 'rgba(255,255,255,0.04)' }} content={<Dica prefixo={prefixoDica} />} />
                    <Bar dataKey="concluidas" fill={COR_SERIE} radius={[4, 4, 0, 0]} maxBarSize={24} isAnimationActive={false}>
                        {rotular && <LabelList dataKey="concluidas" position="top" fill="rgba(255,255,255,0.7)" fontSize={10} />}
                    </Bar>
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
}

function Dica({ active, payload, label, prefixo }) {
    if (!active || !payload?.length) return null;
    const n = payload[0].value;
    return (
        <div className="rounded-lg border border-white/[0.1] bg-[#0b0e14] px-2.5 py-1.5 shadow-lg">
            <p className="text-sm font-bold text-white tabular-nums">{n} {n === 1 ? 'concluída' : 'concluídas'}</p>
            <p className="text-[11px] text-white/50">{prefixo}{label}</p>
        </div>
    );
}
