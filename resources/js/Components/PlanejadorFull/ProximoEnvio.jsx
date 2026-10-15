import { useState } from 'react';
import { Copy, FileDown, PackageCheck } from 'lucide-react';
import { cn } from '@/lib/utils';
import { deDia, fmtDec, fmtDia, fmtInt, porPrioridade } from '@/lib/planejadorFull';
import {
    BarraCobertura, CampoNumero, Chip, Curva, STATUS, Vazio, classeBotao, classeBotaoPrimario, classeCampo, classeTabela, classeTd, classeTh,
} from './comum';

export default function ProximoEnvio({ R, alterarConfig, salvarItem, exportar, copiar, registrar, abrirProduto }) {
    const [todos, setTodos] = useState(false);
    const C = R.C;

    const lista = R.rows
        .filter((r) => (todos ? r.d > 0 : r.sugerido > 0 || r.qtdManual != null || r.fora))
        .sort(porPrioridade);
    const aEnviar = R.rows.filter((r) => r.qtdFinal > 0);
    const unidades = aEnviar.reduce((a, r) => a + r.qtdFinal, 0);
    const falta = R.rows.reduce((a, r) => a + (r.falta || 0), 0);
    const temGalpao = R.rows.some((r) => r.galpaoInformado);

    return (
        <section className="rounded-xl border border-white/[0.08] bg-ecf-card">
            <div className="flex flex-wrap items-end gap-x-6 gap-y-3 border-b border-white/[0.06] px-5 py-4">
                <label className="grid gap-1">
                    <span className="text-[11px] font-semibold uppercase tracking-wider text-white/45">Data do despacho</span>
                    <input
                        type="date"
                        value={C.proximoDespacho || ''}
                        onChange={(e) => alterarConfig({ proximoDespacho: e.target.value })}
                        className={cn(classeCampo, 'w-[150px]')}
                        placeholder={deDia(R.ship)}
                    />
                </label>
                <Resumo rotulo="Despacho" valor={fmtDia(R.ship, 'semana')} />
                <Resumo rotulo="Apto no FULL" valor={fmtDia(R.arrival, 'semana')} />
                <Resumo rotulo="Itens" valor={fmtInt(aEnviar.length)} />
                <Resumo rotulo="Unidades" valor={fmtInt(unidades)} />
                {temGalpao && <Resumo rotulo="Falta no galpão" valor={fmtInt(falta)} alerta={falta > 0} />}
                <div className="ml-auto flex flex-wrap gap-2">
                    <button type="button" className={classeBotao} onClick={copiar}><Copy size={14} /> Copiar lista</button>
                    <button type="button" className={classeBotao} onClick={exportar}><FileDown size={14} /> Exportar .xlsx</button>
                    <button type="button" className={classeBotaoPrimario} onClick={registrar} disabled={!aEnviar.length} title="Grava este envio como &quot;a caminho&quot;: as unidades passam a contar como entrada pendente até chegarem ao FULL">
                        <PackageCheck size={14} /> Registrar envio
                    </button>
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-3 border-b border-white/[0.06] px-5 py-3 text-[12.5px] text-white/45">
                <label className="inline-flex items-center gap-2 text-[13px] text-white/80">
                    <input type="checkbox" checked={todos} onChange={(e) => setTodos(e.target.checked)} className="h-4 w-4 accent-[#ffe600]" />
                    Mostrar todos os itens com venda
                </label>
                <span>
                    {C.proximoDespacho ? '' : 'Sem data informada: usa a mais cedo possível (data base + preparo + agenda). '}
                    {temGalpao ? '' : 'Informe o estoque do galpão em Compras para ver o que dá para enviar e o que falta.'}
                </span>
            </div>

            {lista.length ? (
                <>
                    <div className="max-h-[calc(100vh-260px)] overflow-auto">
                        <table className={classeTabela}>
                            <thead>
                                <tr>
                                    <th className={classeTh}>Status</th>
                                    <th className={classeTh}>Produto</th>
                                    <th className={cn(classeTh, 'text-center')}>Curva</th>
                                    <th className={cn(classeTh, 'text-right')}>Venda/dia</th>
                                    <th className={cn(classeTh, 'text-right')}>Apto</th>
                                    <th className={cn(classeTh, 'text-right')}>A caminho</th>
                                    <th className={classeTh}>Cobertura</th>
                                    <th className={cn(classeTh, 'text-right')}>Ruptura</th>
                                    <th className={cn(classeTh, 'text-right')}>Iniciar até</th>
                                    <th className={cn(classeTh, 'text-right')}>Sugerido</th>
                                    <th className={cn(classeTh, 'text-right max-md:hidden')}>Galpão</th>
                                    <th className={cn(classeTh, 'text-right')}>Qtd final</th>
                                    <th className={cn(classeTh, 'text-center')}>Fora</th>
                                </tr>
                            </thead>
                            <tbody>
                                {lista.map((r) => {
                                    const manual = r.qtdManual != null;
                                    const vencido = r.iniciarAte != null && r.iniciarAte < R.today;
                                    return (
                                        <tr
                                            key={r.cod}
                                            onClick={(e) => { if (!e.target.closest('input,button,label')) abrirProduto(r.cod); }}
                                            className={cn('cursor-pointer hover:bg-white/[0.03]', r.fora && 'opacity-50')}
                                        >
                                            <td className={classeTd}><Chip meta={STATUS[r.status]} /></td>
                                            <td className={cn(classeTd, 'max-w-[280px]')}>
                                                <span className="block truncate font-medium text-white">{r.titulo}</span>
                                                <span className="block truncate font-mono text-[11px] text-white/40">{r.cod} · {r.sku || 'sem SKU'}</span>
                                            </td>
                                            <td className={cn(classeTd, 'text-center')}><Curva abc={r.abc} /></td>
                                            <td className={cn(classeTd, 'text-right tabular-nums text-white/80')}>{fmtDec(r.d)}</td>
                                            <td className={cn(classeTd, 'text-right tabular-nums text-white/80')}>{fmtInt(r.aptas)}</td>
                                            <td className={cn(classeTd, 'text-right tabular-nums text-white/80')} title={`Pendente ${r.pendente} · em transferência ${r.transferencia}`}>{fmtInt(r.aCaminho)}</td>
                                            <td className={classeTd}><BarraCobertura row={r} R={R} /></td>
                                            <td className={cn(classeTd, 'text-right tabular-nums text-white/80')}>{fmtDia(r.ruptura)}</td>
                                            <td className={cn(classeTd, 'text-right tabular-nums', vencido ? 'font-bold text-red-300' : 'text-white/80')} title={vencido ? 'Prazo vencido: o item já vai chegar abaixo da segurança' : undefined}>{fmtDia(r.iniciarAte)}</td>
                                            <td className={cn(classeTd, 'text-right tabular-nums text-white/80')}>{fmtInt(r.sugerido)}</td>
                                            <td className={cn(classeTd, 'text-right tabular-nums text-white/60 max-md:hidden')}>{r.galpaoInformado ? `${fmtInt(r.galpaoSku)}${r.compartilhado ? '*' : ''}` : '—'}</td>
                                            <td className={cn(classeTd, 'text-right')}>
                                                <CampoNumero
                                                    valor={r.qtdManual}
                                                    placeholder={String(r.podeEnviar)}
                                                    destaque={manual}
                                                    aria-label={`Quantidade final de ${r.cod}`}
                                                    className="w-[84px]"
                                                    aoGravar={(v) => salvarItem(r.cod, { qtdManual: v == null ? null : Math.max(0, Math.round(v)) })}
                                                />
                                            </td>
                                            <td className={cn(classeTd, 'text-center')}>
                                                <input
                                                    type="checkbox"
                                                    checked={r.fora}
                                                    aria-label={`Deixar ${r.cod} fora do envio`}
                                                    onChange={(e) => salvarItem(r.cod, { fora: e.target.checked })}
                                                    className="h-4 w-4 accent-[#ffe600]"
                                                />
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                            <tfoot>
                                <tr className="bg-white/[0.03] font-bold text-white">
                                    <td className="px-2.5 py-2" colSpan={9}>Total do envio</td>
                                    <td className="px-2.5 py-2 text-right tabular-nums">{fmtInt(R.rows.reduce((a, r) => a + r.sugerido, 0))}</td>
                                    <td className="max-md:hidden" />
                                    <td className="px-2.5 py-2 text-right tabular-nums">{fmtInt(unidades)}</td>
                                    <td />
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p className="px-5 py-3 text-[12px] text-white/40">
                        Qtd final em branco = sugerido{temGalpao ? ', limitado ao estoque do galpão' : ''}. Digite um número para fixar.
                        {' '}* SKU dividido entre mais de um Código ML. "Fora" tira o item deste envio. "A caminho" soma a entrada pendente dos envios registrados e o que está em transferência no ML.
                    </p>
                </>
            ) : (
                <Vazio titulo="Nada a enviar neste ciclo">Nenhum item precisa de reposição com os lead times atuais.</Vazio>
            )}
        </section>
    );
}

function Resumo({ rotulo, valor, alerta = false }) {
    return (
        <div className="grid gap-0.5">
            <span className="text-[11px] font-semibold uppercase tracking-wider text-white/45">{rotulo}</span>
            <strong className={cn('text-[22px] font-extrabold leading-tight tabular-nums', alerta ? 'text-red-300' : 'text-white')}>{valor}</strong>
        </div>
    );
}
