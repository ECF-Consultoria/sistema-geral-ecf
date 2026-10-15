import { useMemo, useRef, useState } from 'react';
import { FileDown, Search, Upload } from 'lucide-react';
import { cn } from '@/lib/utils';
import { fmtCobertura, fmtDec, fmtDia, fmtInt, normalizar } from '@/lib/planejadorFull';
import {
    CampoNumero, Chip, STATUS_COMPRA, Vazio, classeBotao, classeBotaoPrimario, classeCampo, classeTabela, classeTd, classeTh,
} from './comum';
import { FiltroChip } from './Produtos';

export default function Compras({ R, skus, fornecedoresLista, filtro, setFiltro, salvarSku, importarGalpao, exportar }) {
    const [busca, setBusca] = useState('');
    const [fornecedor, setFornecedor] = useState('todos');
    const arquivo = useRef(null);

    const contagem = useMemo(() => {
        const c = {};
        R.compras.forEach((b) => { c[b.status] = (c[b.status] || 0) + 1; });
        return c;
    }, [R.compras]);

    const lista = useMemo(() => {
        const q = normalizar(busca);
        return R.compras
            .filter((b) => (filtro === 'todos' || b.status === filtro)
                && (fornecedor === 'todos' || b.fornecedor === fornecedor)
                && (!q || normalizar(`${b.sku || ''} ${b.titulo} ${b.cods.join(' ')}`).includes(q)))
            .sort((a, b) => STATUS_COMPRA[a.status].rank - STATUS_COMPRA[b.status].rank
                || (a.pedirAte ?? 1e9) - (b.pedirAte ?? 1e9)
                || b.faturamento - a.faturamento);
    }, [R.compras, busca, filtro, fornecedor]);

    const comprarAgora = lista.reduce((a, b) => a + (b.status === 'ja' || b.status === 'semana' ? b.comprar : 0), 0);
    const semGalpao = R.compras.filter((b) => !b.informado && b.d > 0).length;

    return (
        <div className="grid grid-cols-1 gap-3">
            {semGalpao > 0 && (
                <div className="flex items-start gap-3 rounded-xl border border-amber-400/25 bg-amber-400/10 px-4 py-3 text-[13px] text-amber-100">
                    <span className="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full bg-amber-400" />
                    <div>
                        <b className="block">{fmtInt(semGalpao)} SKUs com venda estão sem estoque do galpão informado.</b>
                        Enquanto estiver em branco, a compra considera galpão = 0 e pode sair maior que o necessário. Preencha na coluna Galpão ou importe uma planilha com SKU e quantidade.
                    </div>
                </div>
            )}

            <section className="rounded-xl border border-white/[0.08] bg-ecf-card">
                <div className="flex flex-wrap items-center gap-2.5 border-b border-white/[0.06] px-5 py-3">
                    <div className="relative min-w-[200px] max-w-[320px] flex-1">
                        <Search size={15} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-white/30" />
                        <input type="search" value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Buscar SKU ou produto" aria-label="Buscar" className={cn(classeCampo, 'pl-8')} />
                    </div>
                    <select value={fornecedor} onChange={(e) => setFornecedor(e.target.value)} aria-label="Fornecedor" className={cn(classeCampo, 'w-auto')}>
                        <option value="todos">Todos os fornecedores</option>
                        {fornecedoresLista.map((f) => <option key={f} value={f}>{f}</option>)}
                    </select>
                    <span className="text-[12.5px] text-white/45">Comprar agora (já + esta semana): <b className="text-white">{fmtInt(comprarAgora)} un.</b></span>
                    <div className="ml-auto flex flex-wrap gap-2">
                        <input
                            ref={arquivo}
                            type="file"
                            accept=".xlsx,.xls,.csv,.txt"
                            hidden
                            onChange={(e) => { if (e.target.files[0]) importarGalpao(e.target.files[0]); e.target.value = ''; }}
                        />
                        <button type="button" className={classeBotao} onClick={() => arquivo.current?.click()}><Upload size={14} /> Importar estoque do galpão</button>
                        <button type="button" className={classeBotaoPrimario} onClick={() => exportar(lista)}><FileDown size={14} /> Exportar compras .xlsx</button>
                    </div>
                </div>

                <div className="flex flex-wrap gap-1.5 border-b border-white/[0.06] px-5 py-3">
                    <FiltroChip ativo={filtro === 'todos'} onClick={() => setFiltro('todos')} rotulo="Todos" n={R.compras.length} />
                    {Object.keys(STATUS_COMPRA).map((k) => (
                        <FiltroChip key={k} ativo={filtro === k} onClick={() => setFiltro(k)} rotulo={STATUS_COMPRA[k].label} n={contagem[k] || 0} ponto={STATUS_COMPRA[k].ponto} />
                    ))}
                </div>

                {lista.length ? (
                    <>
                        <div className="max-h-[calc(100vh-260px)] overflow-auto">
                            <table className={classeTabela}>
                                <thead>
                                    <tr>
                                        <th className={classeTh}>Status</th>
                                        <th className={classeTh}>SKU / produto</th>
                                        <th className={cn(classeTh, 'text-right')}>Pedir até</th>
                                        <th className={cn(classeTh, 'text-right')}>Comprar</th>
                                        <th className={cn(classeTh, 'text-right')}>Galpão</th>
                                        <th className={cn(classeTh, 'text-right')}>Em aberto</th>
                                        <th className={classeTh}>Fornecedor</th>
                                        <th className={cn(classeTh, 'text-right')}>Venda/dia</th>
                                        <th className={cn(classeTh, 'text-right')}>Posição total</th>
                                        <th className={cn(classeTh, 'text-right')}>Cobertura</th>
                                        <th className={cn(classeTh, 'text-right')}>Lead time</th>
                                        <th className={cn(classeTh, 'text-right')}>Múltiplo</th>
                                        <th className={classeTh}>Chegada do pedido</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {lista.map((b) => {
                                        const sd = skus[b.chaveSku] || {};
                                        const vencido = b.pedirAte != null && b.pedirAte < R.today;
                                        return (
                                            <tr key={b.chaveSku} className="hover:bg-white/[0.03]">
                                                <td className={classeTd}><Chip meta={STATUS_COMPRA[b.status]} /></td>
                                                <td className={cn(classeTd, 'max-w-[260px]')}>
                                                    <span className="block truncate font-medium text-white">{b.titulo}</span>
                                                    <span className="block truncate font-mono text-[11px] text-white/40">{b.sku || 'sem SKU'} · {b.cods.length} cód. ML</span>
                                                </td>
                                                <td className={cn(classeTd, 'text-right tabular-nums', vencido ? 'font-bold text-red-300' : 'text-white/80')}>{fmtDia(b.pedirAte)}</td>
                                                <td className={cn(classeTd, 'text-right font-bold tabular-nums text-white')}>{b.d > 0 ? fmtInt(b.comprar) : '—'}</td>
                                                <td className={cn(classeTd, 'text-right')}>
                                                    <CampoNumero valor={sd.galpao} placeholder="—" destaque={b.informado} className="w-[92px]" aria-label={`Estoque no galpão de ${b.chaveSku}`}
                                                        aoGravar={(v) => salvarSku(b.chaveSku, { galpao: v == null ? null : Math.max(0, Math.round(v)) })} />
                                                </td>
                                                <td className={cn(classeTd, 'text-right')}>
                                                    <CampoNumero valor={sd.aberto} placeholder="0" className="w-[92px]" aria-label={`Pedido em aberto de ${b.chaveSku}`}
                                                        aoGravar={(v) => salvarSku(b.chaveSku, { aberto: v == null ? null : Math.max(0, Math.round(v)) })} />
                                                </td>
                                                <td className={classeTd}>
                                                    <select value={b.fornecedor} onChange={(e) => salvarSku(b.chaveSku, { fornecedor: e.target.value })} aria-label={`Fornecedor de ${b.chaveSku}`} className={cn(classeCampo, 'w-[140px]')}>
                                                        {[...new Set([...fornecedoresLista, b.fornecedor])].map((f) => <option key={f} value={f}>{f}</option>)}
                                                    </select>
                                                </td>
                                                <td className={cn(classeTd, 'text-right tabular-nums text-white/80')}>{fmtDec(b.d)}</td>
                                                <td className={cn(classeTd, 'text-right tabular-nums text-white/80')}>{fmtInt(b.posTotal)}</td>
                                                <td className={cn(classeTd, 'text-right tabular-nums text-white/80')}>{fmtCobertura(b.cobertura)}</td>
                                                <td className={cn(classeTd, 'text-right')}>
                                                    <CampoNumero valor={sd.leadTime} placeholder={String(b.lt)} className="w-[64px]" title={`Em branco usa o do fornecedor (${b.lt} dias)`} aria-label={`Lead time específico de ${b.chaveSku}`}
                                                        aoGravar={(v) => salvarSku(b.chaveSku, { leadTime: v == null ? null : Math.max(0, Math.round(v)) })} />
                                                </td>
                                                <td className={cn(classeTd, 'text-right')}>
                                                    <CampoNumero valor={sd.multiplo} placeholder="1" min={1} className="w-[64px]" aria-label={`Múltiplo de compra de ${b.chaveSku}`}
                                                        aoGravar={(v) => salvarSku(b.chaveSku, { multiplo: v == null ? null : Math.max(1, Math.round(v)) })} />
                                                </td>
                                                <td className={classeTd}>
                                                    <input type="date" value={sd.abertoData || ''} onChange={(e) => salvarSku(b.chaveSku, { abertoData: e.target.value || null })}
                                                        aria-label={`Chegada do pedido de ${b.chaveSku}`} className={cn(classeCampo, 'w-[140px]')} />
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        <p className="px-5 py-3 text-[12px] text-white/40">
                            Lead time em branco usa o do fornecedor (aba Lead times e ajustes) ou o padrão. Comprar = cobre lead time do fornecedor + envio ao FULL + intervalo entre compras + segurança, descontando FULL, galpão e pedido em aberto.
                        </p>
                    </>
                ) : (
                    <Vazio titulo="Nenhum SKU com esses filtros">Limpe a busca ou escolha outro status.</Vazio>
                )}
            </section>
        </div>
    );
}
