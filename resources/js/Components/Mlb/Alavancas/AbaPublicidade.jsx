import { useMemo, useState } from 'react';
import { cn } from '@/lib/utils';
import { SELECT } from '@/Components/Publicador/Mesa/comum';
import { useLeitura } from './useAlavancas';
import { fmtBRL, fmtData, fmtInt, fmtPct, janelaDeDias } from './formato';

const PERIODOS = [
    { dias: 7, rotulo: 'Últimos 7 dias' },
    { dias: 30, rotulo: 'Últimos 30 dias' },
    { dias: 90, rotulo: 'Últimos 90 dias' },
];

const COLUNAS = ['Campanha', 'Situação', 'Orçamento diário', 'Estratégia', 'ACOS alvo', 'Investimento', 'Vendas', 'ACOS', 'ROAS', 'Cliques'];

// campaign_id 0 no Mercado Livre = o grupo de anúncios não está em nenhuma campanha.
const FORA = '0';

const num = (v) => (v === null || v === undefined || Number.isNaN(Number(v)) ? '—' : Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 2 }));

/** Ad Groups de uma campanha (ou os que ficam fora de campanha). */
function AdGroups({ conta, de, ate, campanha }) {
    const { dados, erro, carregando } = useLeitura('publicidade.ad-groups', conta, { de, ate });
    const todos = dados?.ad_groups ?? [];
    const meus = todos.filter((g) => String(g.campanha_id) === String(campanha));

    return (
        <div className="px-3 py-4">
            <p className="mb-2 text-[13px] font-bold text-white/90">{campanha === FORA ? 'Fora de campanha' : 'Grupos de anúncios da campanha'}</p>
            {carregando && <p className="text-[13px] font-normal text-white/55">Carregando…</p>}
            {erro && <p className="text-[13px] font-normal text-white/55">{erro}</p>}
            {dados?.indisponivel && <p className="text-[13px] font-normal text-white/55">{dados.indisponivel}</p>}
            {! carregando && ! erro && ! dados?.indisponivel && meus.length === 0 && (
                <p className="text-[13px] font-normal text-white/55">Nenhum grupo de anúncios nesta janela.</p>
            )}
            {! carregando && meus.length > 0 && (
                <table className="w-full text-left text-[13px] font-normal text-white/70">
                    <thead>
                        <tr className="text-white/55">
                            {['Grupo', 'Situação', 'Investimento', 'Vendas', 'ACOS', 'Cliques'].map((c) => <th key={c} scope="col" className="px-3 py-2 font-bold">{c}</th>)}
                        </tr>
                    </thead>
                    <tbody>
                        {meus.map((g) => (
                            <tr key={g.id} className="border-t border-white/[0.06]">
                                <td className="px-3 py-2">{g.titulo ?? g.id}</td>
                                <td className="px-3 py-2">{g.status ?? '—'}</td>
                                <td className="px-3 py-2">{fmtBRL(g.metricas?.investimento)}</td>
                                <td className="px-3 py-2">{fmtBRL(g.metricas?.vendas)}</td>
                                <td className="px-3 py-2">{fmtPct(g.metricas?.acos)}</td>
                                <td className="px-3 py-2">{fmtInt(g.metricas?.cliques)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    );
}

/** Publicidade (Product Ads) da conta: campanhas, grupos de anúncios e bonificações — só leitura. */
export default function AbaPublicidade({ conta }) {
    const [dias, setDias] = useState(30);
    const [aberta, setAberta] = useState(null);
    const { de, ate } = useMemo(() => janelaDeDias(dias), [dias]);

    const { dados, erro, carregando } = useLeitura('publicidade', conta, { de, ate });
    const campanhas = dados?.campanhas ?? [];
    const bonif = dados?.bonificacoes ?? { saldo_total: 0, itens: [] };

    const alternar = (id) => setAberta((atual) => (atual === id ? null : id));

    return (
        <section className="space-y-6">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <label className="block w-56">
                    <span className="mb-1.5 block text-[13px] font-bold text-white/90">Período</span>
                    <select value={dias} onChange={(ev) => { setDias(Number(ev.target.value)); setAberta(null); }} className={SELECT}>
                        {PERIODOS.map((p) => <option key={p.dias} value={p.dias}>{p.rotulo}</option>)}
                    </select>
                </label>
                <p className="max-w-[56ch] text-[13px] font-normal text-white/55">
                    Por enquanto só leitura: criar, pausar e mudar orçamento de campanha ainda não estão liberados aqui.
                </p>
            </div>

            {/* Trocou o período: os números do período antigo saem de cena até a nova leitura chegar. */}
            {carregando && <p className="text-[13px] font-normal text-white/55">Carregando…</p>}
            {erro && <p className="text-[13px] font-normal text-white/55">{erro}</p>}

            {! carregando && dados?.indisponivel && (
                <p className="rounded-xl bg-ecf-card p-4 text-[13px] font-normal text-white/55">{dados.indisponivel}</p>
            )}

            {! carregando && dados && ! dados.indisponivel && (
                <>
                    <div className="overflow-x-auto rounded-xl bg-ecf-card p-4">
                        {campanhas.length === 0 ? (
                            <p className="py-6 text-center text-[13px] font-normal text-white/55">Nenhuma campanha de publicidade nesta janela.</p>
                        ) : (
                            <table className="w-full text-left text-[13px] font-normal text-white/70">
                                <thead>
                                    <tr className="border-b border-white/[0.08] text-white/55">
                                        {COLUNAS.map((c) => <th key={c} scope="col" className="px-3 py-2 font-bold">{c}</th>)}
                                    </tr>
                                </thead>
                                <tbody>
                                    {campanhas.map((c) => (
                                        <LinhaDaCampanha key={c.id} campanha={c} aberta={aberta === String(c.id)} aoAlternar={() => alternar(String(c.id))} conta={conta} de={de} ate={ate} />
                                    ))}
                                    <tr className="border-b border-white/[0.06]">
                                        <td colSpan={COLUNAS.length} className="px-3 py-2">
                                            <button
                                                type="button"
                                                aria-expanded={aberta === FORA}
                                                onClick={() => alternar(FORA)}
                                                className="rounded text-[13px] font-bold text-white/70 underline-offset-4 hover:text-ecf-yellow hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                                            >
                                                Ver anúncios fora de campanha
                                            </button>
                                        </td>
                                    </tr>
                                    {aberta === FORA && (
                                        <tr><td colSpan={COLUNAS.length}><AdGroups conta={conta} de={de} ate={ate} campanha={FORA} /></td></tr>
                                    )}
                                </tbody>
                            </table>
                        )}
                    </div>

                    <div className="rounded-xl bg-ecf-card p-4">
                        <h3 className="text-[15px] font-bold text-white">Bonificações</h3>
                        {bonif.indisponivel ? (
                            <p className="mt-2 text-[13px] font-normal text-white/55">{bonif.indisponivel}</p>
                        ) : (
                            <>
                                <p className="mt-2 text-[13px] font-normal text-white/70">{`Saldo total ${fmtBRL(bonif.saldo_total)}`}</p>
                                {(bonif.itens ?? []).length === 0 && <p className="mt-1 text-[13px] font-normal text-white/55">Nenhuma bonificação ativa.</p>}
                                <ul className="mt-2 space-y-1">
                                    {(bonif.itens ?? []).map((b, i) => (
                                        <li key={`${b.beneficio ?? 'b'}-${i}`} className="text-[13px] font-normal text-white/70">
                                            {`${b.beneficio ?? b.campanha ?? 'Bonificação'} — saldo ${fmtBRL(b.saldo)}`}
                                            {b.fim ? ` · vale até ${fmtData(b.fim)}` : ''}
                                            {b.dias_restantes !== null && b.dias_restantes !== undefined ? ` (${num(b.dias_restantes)} dias)` : ''}
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                    </div>
                </>
            )}
        </section>
    );
}

function LinhaDaCampanha({ campanha: c, aberta, aoAlternar, conta, de, ate }) {
    const m = c.metricas ?? {};

    return (
        <>
            <tr
                tabIndex={0}
                aria-expanded={aberta}
                onClick={aoAlternar}
                onKeyDown={(ev) => { if (ev.key === 'Enter') aoAlternar(); }}
                className={cn('cursor-pointer border-b border-white/[0.06] hover:bg-white/[0.03] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow', aberta && 'bg-white/[0.03]')}
            >
                <td className="px-3 py-3 font-bold text-white/90">{c.nome}</td>
                <td className="px-3 py-3">{c.status ?? '—'}</td>
                <td className="px-3 py-3">{fmtBRL(c.orcamento_diario)}</td>
                <td className="px-3 py-3">{c.estrategia ?? '—'}</td>
                <td className="px-3 py-3">{fmtPct(c.acos_alvo)}</td>
                <td className="px-3 py-3">{fmtBRL(m.investimento)}</td>
                <td className="px-3 py-3">{fmtBRL(m.vendas)}</td>
                <td className="px-3 py-3">{fmtPct(m.acos)}</td>
                <td className="px-3 py-3">{num(m.roas)}</td>
                <td className="px-3 py-3">{fmtInt(m.cliques)}</td>
            </tr>
            {aberta && (
                <tr className="border-b border-white/[0.06]">
                    <td colSpan={COLUNAS.length}><AdGroups conta={conta} de={de} ate={ate} campanha={String(c.id)} /></td>
                </tr>
            )}
        </>
    );
}
