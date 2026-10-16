import { useEffect, useMemo, useState } from 'react';
import { X } from 'lucide-react';
import {
    Bar, BarChart, CartesianGrid, Line, LineChart, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts';
import { cn } from '@/lib/utils';
import { fmtBRL, fmtCobertura, fmtDec, fmtDia, fmtInt, paraDia, projecaoEstoque } from '@/lib/planejadorFull';
import { CampoNumero, CampoTexto, Chip, STATUS, STATUS_COMPRA, classeCampo } from './comum';

const linkAnuncio = (mlb) => `https://produto.mercadolivre.com.br/${String(mlb).replace(/^MLB/i, 'MLB-')}`;

function DicaGrafico({ active, payload, label, formatar }) {
    if (!active || !payload?.length) return null;
    return (
        <div className="rounded-lg border border-white/10 bg-[#0b0d12] px-3 py-2 text-[12px] text-white shadow-xl">
            <p className="mb-1 font-semibold">{formatar(label)}</p>
            {payload.map((p) => (
                <p key={p.dataKey} className="tabular-nums" style={{ color: p.color }}>{p.name}: {fmtInt(p.value)} un.</p>
            ))}
        </div>
    );
}

export default function Gaveta({ row, R, rotaVendas, dataBase, skus, fornecedoresLista, salvarItem, salvarSku, fechar }) {
    const [serie, setSerie] = useState(null);
    const sd = skus[row.chaveSku] || {};
    const compra = R.compras.find((b) => b.chaveSku === row.chaveSku);
    const vencido = row.iniciarAte != null && row.iniciarAte < R.today;
    const projecao = useMemo(() => projecaoEstoque(row, R), [row, R]);
    const seguranca = row.d * row.seg;

    useEffect(() => {
        const fechaNoEsc = (e) => { if (e.key === 'Escape') fechar(); };
        window.addEventListener('keydown', fechaNoEsc);
        return () => window.removeEventListener('keydown', fechaNoEsc);
    }, [fechar]);

    useEffect(() => {
        let vivo = true;
        setSerie(null);
        window.axios.get(rotaVendas.replace('__COD__', row.cod), { params: { base: dataBase } })
            .then((r) => { if (vivo) setSerie(r.data.serie || []); })
            .catch(() => { if (vivo) setSerie([]); });
        return () => { vivo = false; };
    }, [rotaVendas, row.cod, dataBase]);

    const celulas = [
        ['Venda/dia usada', fmtDec(row.d)],
        ['Vendas em 30 dias', fmtInt(row.vendas)],
        ['Faturamento 30d', fmtBRL(row.faturamento)],
        ['Estoque apto', fmtInt(row.aptas)],
        ['Entrada pendente', fmtInt(row.pendente)],
        ['Em transferência', fmtInt(row.transferencia)],
        ['Cobertura', fmtCobertura(row.cobertura)],
        ['Ruptura prevista', fmtDia(row.ruptura, 'semana')],
        ['Iniciar envio até', <span key="i" className={cn(vencido && 'text-red-300')}>{fmtDia(row.iniciarAte, 'semana')}</span>],
        ['Na chegada do envio', row.projecao < 0 ? <span key="p">0 <span className="text-[12px] font-normal text-red-300">faltam {fmtInt(-row.projecao)}</span></span> : fmtInt(row.projecao)],
        ['Sugerido', fmtInt(row.sugerido)],
        ['Em risco', fmtBRL(row.emRisco)],
        ['Segurança', `${row.seg} dias (${row.abc})`],
    ];

    return (
        <>
            <div className="fixed inset-0 z-40 bg-black/50" onClick={fechar} aria-hidden="true" />
            <aside role="dialog" aria-modal="true" aria-labelledby="gaveta-titulo" className="fixed inset-y-0 right-0 z-[41] w-full max-w-[580px] overflow-y-auto border-l border-white/[0.08] bg-[#0f1116] px-5 pb-8 pt-5 shadow-2xl">
                <button type="button" onClick={fechar} className="float-right rounded-lg border border-white/[0.12] p-1.5 text-white/70 hover:text-white" aria-label="Fechar">
                    <X size={16} />
                </button>
                <div className="flex flex-wrap items-center gap-2">
                    <Chip meta={STATUS[row.status]} />
                    <span className="text-[12px] text-white/45">{STATUS[row.status].desc}</span>
                </div>
                <h3 id="gaveta-titulo" className="mt-2 text-[18px] font-bold leading-snug text-white">{row.titulo}</h3>
                <p className="mt-1 break-all font-mono text-[11.5px] text-white/40">
                    {row.cod} · SKU {row.sku || '—'}{row.marca ? ` · ${row.marca}` : ''} · anúncios{' '}
                    {row.anuncios.length ? row.anuncios.map((a, i) => (
                        <span key={a}>{i > 0 && ', '}<a href={linkAnuncio(a)} target="_blank" rel="noreferrer" className="underline decoration-white/20 hover:text-white">{a}</a></span>
                    )) : '—'}
                </p>

                <div className="my-4 grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-white/[0.08] bg-white/[0.08] sm:grid-cols-3">
                    {celulas.map(([rotulo, valor]) => (
                        <div key={rotulo} className="grid gap-0.5 bg-[#0f1116] px-2.5 py-2">
                            <span className="text-[10.5px] font-semibold uppercase tracking-wider text-white/40">{rotulo}</span>
                            <strong className="text-[15px] tabular-nums text-white">{valor}</strong>
                        </div>
                    ))}
                </div>

                <Secao titulo="Projeção do estoque no FULL">
                    {row.d <= 0 ? (
                        <p className="text-[13px] text-white/50">Sem venda nos últimos 30 dias: o estoque fica parado ({fmtInt(row.pos)} un.).</p>
                    ) : (
                        <>
                            <div className="h-[200px]">
                                <ResponsiveContainer width="100%" height="100%">
                                    <LineChart data={projecao} margin={{ top: 8, right: 8, bottom: 0, left: -12 }}>
                                        <CartesianGrid strokeDasharray="3 3" stroke="#ffffff10" />
                                        <XAxis dataKey="dia" tickFormatter={(d) => fmtDia(d)} stroke="#ffffff30" tick={{ fontSize: 10, fill: '#ffffff70' }} interval="preserveStartEnd" minTickGap={24} />
                                        <YAxis stroke="#ffffff30" tick={{ fontSize: 10, fill: '#ffffff70' }} tickFormatter={(v) => fmtInt(v)} />
                                        <Tooltip content={<DicaGrafico formatar={(d) => fmtDia(d, 'semana')} />} />
                                        <ReferenceLine y={seguranca} stroke="#fbbf24" strokeDasharray="4 3" />
                                        <ReferenceLine x={R.arrival} stroke="#ffffff60" strokeDasharray="2 3" label={{ value: `apto ${fmtDia(R.arrival)}`, fill: '#ffffffa0', fontSize: 10, position: 'insideTopLeft' }} />
                                        <Line type="linear" dataKey="sem" name="Sem envio" stroke="#ef4444" strokeDasharray="5 4" dot={false} strokeWidth={2} isAnimationActive={false} />
                                        <Line type="linear" dataKey="com" name={`Com este envio (${fmtInt(row.qtdFinal)} un.)`} stroke="#ffe600" dot={false} strokeWidth={2} isAnimationActive={false} />
                                    </LineChart>
                                </ResponsiveContainer>
                            </div>
                            <div className="mt-1.5 flex flex-wrap gap-4 text-[12px] text-white/55">
                                <Legenda cor="border-[#ffe600]">Com este envio ({fmtInt(row.qtdFinal)} un.)</Legenda>
                                <Legenda cor="border-red-500 border-dashed">Sem envio</Legenda>
                                <Legenda cor="border-amber-400 border-dashed">Segurança ({fmtInt(seguranca)} un.)</Legenda>
                            </div>
                        </>
                    )}
                </Secao>

                <Secao titulo="Venda por dia — últimos 30 dias">
                    {serie === null ? (
                        <p className="text-[13px] text-white/40">Carregando…</p>
                    ) : serie.length === 0 ? (
                        <p className="text-[13px] text-white/40">Sem dados de venda.</p>
                    ) : (
                        <div className="h-[130px]">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={serie.map((s) => ({ ...s, dia: paraDia(s.data) }))} margin={{ top: 4, right: 8, bottom: 0, left: -18 }}>
                                    <CartesianGrid strokeDasharray="3 3" stroke="#ffffff10" vertical={false} />
                                    <XAxis dataKey="dia" tickFormatter={(d) => fmtDia(d)} stroke="#ffffff30" tick={{ fontSize: 10, fill: '#ffffff70' }} minTickGap={20} />
                                    <YAxis stroke="#ffffff30" tick={{ fontSize: 10, fill: '#ffffff70' }} allowDecimals={false} />
                                    <Tooltip cursor={{ fill: '#ffffff08' }} content={<DicaGrafico formatar={(d) => fmtDia(d, 'semana')} />} />
                                    <Bar dataKey="unidades" name="Vendidas" fill="#ffe600" radius={[2, 2, 0, 0]} isAnimationActive={false} />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    )}
                    <p className="mt-1.5 text-[12px] text-white/40">Dia com zero no meio de dias com venda costuma ser ruptura: use o ajuste de demanda para compensar.</p>
                </Secao>

                <Secao titulo="Este Código ML">
                    <div className="grid grid-cols-2 gap-3">
                        <Campo rotulo="Ajuste de demanda (%)">
                            <CampoNumero valor={row.ajuste ? Math.round(row.ajuste * 100) : null} placeholder="0" step={5} min={-95}
                                aoGravar={(v) => salvarItem(row.cod, { ajuste: v == null ? null : v / 100 })} />
                        </Campo>
                        <Campo rotulo="Qtd final do envio">
                            <CampoNumero valor={row.qtdManual} placeholder={String(row.podeEnviar)} destaque={row.qtdManual != null}
                                aoGravar={(v) => salvarItem(row.cod, { qtdManual: v == null ? null : Math.max(0, Math.round(v)) })} />
                        </Campo>
                        <label className="col-span-2 flex items-center gap-2 text-[13px] text-white/80">
                            <input type="checkbox" checked={row.fora} onChange={(e) => salvarItem(row.cod, { fora: e.target.checked })} className="h-4 w-4 accent-[#ffe600]" />
                            Deixar fora do plano de envio (ex.: produto saindo de linha)
                        </label>
                        <Campo rotulo="Observação" className="col-span-2">
                            <CampoTexto valor={row.observacao} placeholder="Ex.: fornecedor sem estoque até novembro" aoGravar={(v) => salvarItem(row.cod, { observacao: v || null })} />
                        </Campo>
                    </div>
                </Secao>

                <Secao titulo={`SKU ${row.sku || '(sem SKU)'} — galpão e compra`}>
                    <div className="grid grid-cols-2 gap-3">
                        <Campo rotulo="Estoque no galpão">
                            <CampoNumero valor={sd.galpao} placeholder="não informado" aoGravar={(v) => salvarSku(row.chaveSku, { galpao: v == null ? null : Math.max(0, Math.round(v)) })} />
                        </Campo>
                        <Campo rotulo="Pedido em aberto">
                            <CampoNumero valor={sd.aberto} placeholder="0" aoGravar={(v) => salvarSku(row.chaveSku, { aberto: v == null ? null : Math.max(0, Math.round(v)) })} />
                        </Campo>
                        <Campo rotulo="Fornecedor">
                            <select value={compra?.fornecedor || ''} onChange={(e) => salvarSku(row.chaveSku, { fornecedor: e.target.value })} className={classeCampo}>
                                {[...new Set([...fornecedoresLista, compra?.fornecedor].filter(Boolean))].map((f) => <option key={f} value={f}>{f}</option>)}
                            </select>
                        </Campo>
                        <Campo rotulo="Múltiplo de caixa">
                            <CampoNumero valor={sd.multiplo} placeholder="1" min={1} aoGravar={(v) => salvarSku(row.chaveSku, { multiplo: v == null ? null : Math.max(1, Math.round(v)) })} />
                        </Campo>
                        {compra && (
                            <p className="col-span-2 flex flex-wrap items-center gap-1.5 text-[12.5px] text-white/55">
                                Compra: <Chip meta={STATUS_COMPRA[compra.status]} /> pedir até <b className="text-white">{fmtDia(compra.pedirAte, 'semana')}</b>
                                · {compra.d > 0 ? `${fmtInt(compra.comprar)} un.` : 'sem giro'} · lead time {compra.lt} dias
                                {row.compartilhado ? ' · este SKU está em mais de um Código ML' : ''}
                            </p>
                        )}
                    </div>
                </Secao>
            </aside>
        </>
    );
}

function Secao({ titulo, children }) {
    return (
        <div className="mt-5">
            <h4 className="mb-2 text-[11.5px] font-semibold uppercase tracking-wider text-white/45">{titulo}</h4>
            {children}
        </div>
    );
}

function Campo({ rotulo, children, className }) {
    return (
        <label className={cn('grid gap-1', className)}>
            <span className="text-[11px] font-semibold uppercase tracking-wider text-white/45">{rotulo}</span>
            {children}
        </label>
    );
}

function Legenda({ cor, children }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <i className={cn('inline-block w-4 border-t-2', cor)} />
            {children}
        </span>
    );
}
