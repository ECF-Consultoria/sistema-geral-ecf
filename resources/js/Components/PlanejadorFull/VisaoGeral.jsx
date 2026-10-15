import { cn } from '@/lib/utils';
import { fmtBRL, fmtDia, fmtInt } from '@/lib/planejadorFull';
import {
    BarraCobertura, Chip, LinhaDoTempo, ORDEM_STATUS, Painel, STATUS, Vazio, classeBotao, classeTabela, classeTd, classeTh,
} from './comum';

function Kpi({ cor, rotulo, valor, unidade, detalhe, onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="relative grid content-start gap-0.5 rounded-xl border border-white/[0.08] bg-ecf-card py-3.5 pl-5 pr-4 text-left transition hover:border-white/20"
        >
            <span className={cn('absolute bottom-3.5 left-0 top-3.5 w-1 rounded-r', cor)} />
            <span className="text-[11px] font-semibold uppercase tracking-wider text-white/45">{rotulo}</span>
            <span className="text-[32px] font-extrabold leading-tight tracking-tight tabular-nums text-white">
                {valor}
                {unidade && <small className="ml-1 text-[15px] font-semibold text-white/55">{unidade}</small>}
            </span>
            <span className="text-[12.5px] text-white/60">{detalhe}</span>
        </button>
    );
}

export default function VisaoGeral({ R, ir, abrirProduto }) {
    const T = R.totais;
    const rows = R.rows;
    const total = rows.length || 1;
    const dist = ORDEM_STATUS.map((k) => ({ k, n: rows.filter((r) => r.status === k).length }));
    const topo = rows
        .filter((r) => r.emRisco > 0 || r.status === 'ruptura')
        .sort((a, b) => b.emRisco - a.emRisco || b.receitaDia - a.receitaDia)
        .slice(0, 12);
    const vencidos = rows.filter((r) => r.iniciarAte != null && r.iniciarAte < R.today && r.d > 0).length;
    const parados = rows.filter((r) => r.status === 'excesso' || r.status === 'semgiro').length;
    const comprasTopo = R.compras
        .filter((b) => b.status === 'ja' || b.status === 'semana')
        .sort((a, b) => b.faturamento - a.faturamento)
        .slice(0, 7);

    return (
        <div className="grid grid-cols-1 gap-4">
            <Painel titulo="Próximo envio ao FULL" sub="Da data do estoque até o envio ficar apto para venda, e o período que ele precisa cobrir.">
                <LinhaDoTempo R={R} />
            </Painel>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Kpi
                    cor="bg-red-500"
                    rotulo="Em ruptura agora"
                    valor={fmtInt(T.ruptura)}
                    unidade="itens"
                    detalhe={`${fmtBRL(T.receitaDiaRuptura)} por dia sem vender`}
                    onClick={() => ir('produtos', { status: 'ruptura' })}
                />
                <Kpi
                    cor="bg-orange-500"
                    rotulo="Vão faltar antes do envio"
                    valor={fmtInt(T.critico)}
                    unidade="críticos"
                    detalhe={`+ ${fmtInt(T.atencao)} chegam abaixo da segurança`}
                    onClick={() => ir('produtos', { status: 'critico' })}
                />
                <Kpi
                    cor="bg-ecf-yellow"
                    rotulo={`Próximo envio · ${fmtDia(R.ship, 'semana')}`}
                    valor={fmtInt(T.finalUnidades)}
                    unidade="un."
                    detalhe={`${fmtInt(T.finalItens)} itens · ${vencidos ? `${vencidos} com prazo de envio vencido` : 'nenhum prazo vencido'}`}
                    onClick={() => ir('envio')}
                />
                <Kpi
                    cor="bg-amber-400"
                    rotulo="Pedir ao fornecedor"
                    valor={fmtInt(T.comprarJa)}
                    unidade="já"
                    detalhe={`+ ${fmtInt(T.comprarSemana)} esta semana${T.semGalpao ? ` · ${fmtInt(T.semGalpao)} SKUs sem galpão informado` : ''}`}
                    onClick={() => ir('compras', { status: 'ja' })}
                />
            </div>

            <div className="grid grid-cols-1 items-start gap-4 xl:grid-cols-[minmax(0,1.7fr)_minmax(0,1fr)]">
                <Painel
                    semPadding
                    titulo="Maior faturamento em risco"
                    sub={`Itens que ficam sem estoque antes do envio de ${fmtDia(R.arrival)} ficar apto. Clique para ver o detalhe.`}
                >
                    {topo.length ? (
                        <div className="overflow-x-auto">
                            <table className={classeTabela}>
                                <thead>
                                    <tr>
                                        <th className={classeTh}>Status</th>
                                        <th className={classeTh}>Produto</th>
                                        <th className={classeTh}>Cobertura × chegada</th>
                                        <th className={cn(classeTh, 'text-right')}>Em risco</th>
                                        <th className={cn(classeTh, 'text-right')}>Enviar</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {topo.map((r) => (
                                        <tr key={r.cod} tabIndex={0} onClick={() => abrirProduto(r.cod)} onKeyDown={(e) => e.key === 'Enter' && abrirProduto(r.cod)} className="cursor-pointer hover:bg-white/[0.03]">
                                            <td className={classeTd}><Chip meta={STATUS[r.status]} /></td>
                                            <td className={cn(classeTd, 'max-w-[280px]')}>
                                                <span className="block truncate font-medium text-white">{r.titulo}</span>
                                                <span className="block truncate font-mono text-[11px] text-white/40">{r.cod} · {r.sku || 'sem SKU'}</span>
                                            </td>
                                            <td className={classeTd}><BarraCobertura row={r} R={R} /></td>
                                            <td className={cn(classeTd, 'text-right font-bold tabular-nums text-white')}>{fmtBRL(r.emRisco)}</td>
                                            <td className={cn(classeTd, 'text-right tabular-nums text-white/80')}>{fmtInt(r.qtdFinal)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <Vazio titulo="Nenhum item em risco">Todos chegam ao próximo envio com estoque.</Vazio>
                    )}
                </Painel>

                <div className="grid min-w-0 gap-4">
                    <Painel titulo="Saúde do estoque FULL" sub={`${fmtInt(T.itens)} produtos por situação. Clique para filtrar.`}>
                        <div className="my-3 flex h-[18px] gap-0.5" role="img" aria-label="Distribuição por status">
                            {dist.filter((x) => x.n).map((x) => (
                                <button
                                    key={x.k}
                                    type="button"
                                    title={`${STATUS[x.k].label}: ${x.n} itens`}
                                    onClick={() => ir('produtos', { status: x.k })}
                                    className={cn('min-w-[4px] rounded', STATUS[x.k].barra)}
                                    style={{ flexGrow: x.n }}
                                />
                            ))}
                        </div>
                        <div className="flex flex-wrap gap-x-4 gap-y-1.5 text-[12.5px] text-white/60">
                            {dist.map((x) => (
                                <button key={x.k} type="button" onClick={() => ir('produtos', { status: x.k })} className="inline-flex items-center gap-1.5">
                                    <span className={cn('h-2.5 w-2.5 rounded-sm', STATUS[x.k].barra)} />
                                    {STATUS[x.k].label} <b className="tabular-nums text-white">{x.n}</b>
                                    <span className="text-white/35">{Math.round((x.n / total) * 100)}%</span>
                                </button>
                            ))}
                        </div>
                        <div className="mt-3.5 flex flex-wrap gap-x-6 gap-y-2 border-t border-white/[0.06] pt-3 text-[13px] text-white/60">
                            <div>Faturamento em risco até a chegada<br /><b className="text-[15px] tabular-nums text-white">{fmtBRL(T.emRisco)}</b></div>
                            <div>Estoque parado (excesso + sem giro)<br /><b className="text-[15px] tabular-nums text-white">{fmtInt(T.excessoUnidades)} un.</b> <span className="text-white/35">em {fmtInt(parados)} itens</span></div>
                        </div>
                        <p className="mt-2 text-[12px] text-white/35">Excesso = cobertura acima de {fmtInt(R.C.limiteExcesso)} dias. Estoque parado no FULL pesa em armazenagem.</p>
                    </Painel>

                    <Painel
                        semPadding
                        titulo="Pedir ao fornecedor primeiro"
                        sub={`SKUs com prazo de pedido vencido ou nesta semana, por faturamento.${T.semGalpao ? ' Sem o estoque do galpão informado, a conta considera galpão = 0.' : ''}`}
                    >
                        {comprasTopo.length ? (
                            <>
                                <div className="overflow-x-auto">
                                    <table className={classeTabela}>
                                        <thead>
                                            <tr>
                                                <th className={classeTh}>Produto</th>
                                                <th className={cn(classeTh, 'text-right')}>Pedir até</th>
                                                <th className={cn(classeTh, 'text-right')}>Comprar</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {comprasTopo.map((b) => (
                                                <tr key={b.chaveSku}>
                                                    <td className={cn(classeTd, 'max-w-[220px]')}>
                                                        <span className="block truncate font-medium text-white">{b.titulo}</span>
                                                        <span className="block truncate font-mono text-[11px] text-white/40">{b.sku || 'sem SKU'} · {b.fornecedor}</span>
                                                    </td>
                                                    <td className={cn(classeTd, 'text-right tabular-nums', b.pedirAte < R.today ? 'font-bold text-red-300' : 'text-white/80')}>{fmtDia(b.pedirAte)}</td>
                                                    <td className={cn(classeTd, 'text-right font-bold tabular-nums text-white')}>{fmtInt(b.comprar)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <div className="px-5 py-3">
                                    <button type="button" className={classeBotao} onClick={() => ir('compras', { status: 'ja' })}>Ver todas as compras</button>
                                </div>
                            </>
                        ) : (
                            <Vazio titulo="Nenhum pedido urgente">Os prazos de compra estão em dia.</Vazio>
                        )}
                    </Painel>
                </div>
            </div>
        </div>
    );
}
