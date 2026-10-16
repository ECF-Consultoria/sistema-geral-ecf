import { useRef, useState } from 'react';
import { ChevronDown, ChevronRight, FileSpreadsheet, Truck } from 'lucide-react';
import { cn } from '@/lib/utils';
import { fmtDia, fmtInt, hojeDia, paraDia } from '@/lib/planejadorFull';
import { CampoTexto, Vazio, classeBotao, classeTabela, classeTd, classeTh } from './comum';

const STATUS_ENVIO = {
    a_caminho: { label: 'A caminho', cls: 'bg-ecf-yellow/15 text-ecf-yellow border-ecf-yellow/30' },
    recebido: { label: 'Recebido', cls: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30' },
    cancelado: { label: 'Cancelado', cls: 'bg-white/[0.06] text-white/50 border-white/10' },
};

// Como o envio nasceu: pela tela da equipe, pela planilha do ML ou pelo cliente no link aberto.
const ORIGEM_ENVIO = { planejador: 'Planejador', planilha: 'Planilha do ML', cliente: 'Cliente (link)' };

const fmtDataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—');

export default function Envios({ envios, atualizarEnvio, excluirEnvio, importarPlanilha }) {
    const arquivo = useRef(null);
    const abertos = envios.filter((e) => e.status === 'a_caminho');
    const fechados = envios.filter((e) => e.status !== 'a_caminho');
    const pendente = abertos.reduce((a, e) => a + (e.quantidade - e.recebido), 0);

    return (
        <div className="grid grid-cols-1 gap-4">
            <section className="rounded-xl border border-white/[0.08] bg-ecf-card px-5 py-4">
                <div className="flex flex-wrap items-start gap-4">
                    <div className="min-w-0 flex-1">
                        <h2 className="flex items-center gap-2 text-[15px] font-semibold text-white"><Truck size={16} className="text-ecf-yellow" /> Entrada pendente</h2>
                        <p className="mt-1 max-w-[78ch] text-[12.5px] leading-relaxed text-white/55">
                            A API do Mercado Livre não informa o que você mandou para o FULL e ainda não chegou. Por isso cada envio é registrado aqui
                            (botão <b className="text-white/80">Registrar envio</b> na aba Próximo envio) e, todo dia, a coleta abate as unidades que o ML recebeu.
                            Informe o <b className="text-white/80">número do envio no ML</b> para a baixa contar só as chegadas daquele envio; sem ele, as chegadas vão para o envio mais antigo.
                        </p>
                    </div>
                    <div className="grid gap-0.5 text-right">
                        <span className="text-[11px] font-semibold uppercase tracking-wider text-white/45">A caminho</span>
                        <strong className="text-[22px] font-extrabold tabular-nums text-white">{fmtInt(pendente)} <small className="text-[13px] font-semibold text-white/50">un. em {abertos.length} envio(s)</small></strong>
                    </div>
                </div>
                <div className="mt-3 flex flex-wrap items-center gap-3 border-t border-white/[0.06] pt-3">
                    <input ref={arquivo} type="file" accept=".xlsx" hidden onChange={(e) => { if (e.target.files[0]) importarPlanilha(e.target.files[0]); e.target.value = ''; }} />
                    <button type="button" className={classeBotao} onClick={() => arquivo.current?.click()}>
                        <FileSpreadsheet size={14} /> Importar a planilha de estoque do ML
                    </button>
                    <span className="text-[12px] text-white/40">
                        Uma vez só, para os envios que já estavam a caminho antes do módulo: use o "Relatório geral de estoque" do FULL (arquivo stock_general_full_….xlsx). A coluna "Entrada pendente" vira um envio a caminho.
                    </span>
                </div>
            </section>

            <ListaEnvios titulo="A caminho" envios={abertos} atualizarEnvio={atualizarEnvio} excluirEnvio={excluirEnvio} vazio="Nenhum envio a caminho." />
            {fechados.length > 0 && <ListaEnvios titulo="Encerrados (últimos 20)" envios={fechados} atualizarEnvio={atualizarEnvio} excluirEnvio={excluirEnvio} />}
        </div>
    );
}

function ListaEnvios({ titulo, envios, atualizarEnvio, excluirEnvio, vazio }) {
    return (
        <section className="rounded-xl border border-white/[0.08] bg-ecf-card">
            <div className="flex items-center gap-2 border-b border-white/[0.06] px-5 py-3">
                <h3 className="text-[14px] font-semibold text-white">{titulo}</h3>
                <span className="rounded-full bg-white/5 px-2 py-0.5 text-[11px] tabular-nums text-white/45">{envios.length}</span>
            </div>
            {envios.length ? (
                <ul className="divide-y divide-white/[0.05]">
                    {envios.map((e) => <LinhaEnvio key={e.id} envio={e} atualizarEnvio={atualizarEnvio} excluirEnvio={excluirEnvio} />)}
                </ul>
            ) : (
                <Vazio titulo={vazio} />
            )}
        </section>
    );
}

function LinhaEnvio({ envio: e, atualizarEnvio, excluirEnvio }) {
    const [aberto, setAberto] = useState(false);
    const [confirmar, setConfirmar] = useState(false);
    const pct = e.quantidade ? Math.round((e.recebido / e.quantidade) * 100) : 0;
    const dias = hojeDia() - paraDia(e.dataDespacho);
    const meta = STATUS_ENVIO[e.status] || STATUS_ENVIO.a_caminho;

    return (
        <li className="px-5 py-3.5">
            <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
                <button type="button" onClick={() => setAberto(!aberto)} className="inline-flex items-center gap-1.5 text-left" aria-expanded={aberto}>
                    {aberto ? <ChevronDown size={15} className="text-white/50" /> : <ChevronRight size={15} className="text-white/50" />}
                    <span>
                        <span className="block text-[14px] font-semibold text-white">Despacho {fmtDia(paraDia(e.dataDespacho), 'semana')}</span>
                        <span className="block text-[11.5px] text-white/40">
                            {ORIGEM_ENVIO[e.origem] || 'Planejador'}{e.criadoPor ? ` · ${e.criadoPor}` : ''}
                            {e.status === 'a_caminho' && dias > 0 ? ` · há ${dias} dia(s)` : ''}
                        </span>
                    </span>
                </button>
                <span className={cn('inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold', meta.cls)}>{meta.label}</span>

                <div className="min-w-[180px] flex-1">
                    <div className="flex justify-between text-[12px] text-white/55">
                        <span>Chegou <b className="tabular-nums text-white">{fmtInt(e.recebido)}</b> de <b className="tabular-nums text-white">{fmtInt(e.quantidade)}</b> un.</span>
                        <span className="tabular-nums">{pct}%</span>
                    </div>
                    <div className="mt-1 h-1.5 overflow-hidden rounded bg-white/[0.06]">
                        <div className="h-full rounded bg-emerald-500" style={{ width: `${Math.min(100, pct)}%` }} />
                    </div>
                </div>

                <label className="grid gap-0.5">
                    <span className="text-[10.5px] font-semibold uppercase tracking-wider text-white/40">Nº do envio no ML</span>
                    <CampoTexto
                        valor={e.inboundMl || ''}
                        placeholder="ex.: 75714964"
                        inputMode="numeric"
                        className="w-[130px] font-mono"
                        aoGravar={(v) => atualizarEnvio(e.id, { inboundMl: v.trim() || null })}
                    />
                </label>

                <div className="ml-auto flex flex-wrap gap-2">
                    {e.status === 'a_caminho' ? (
                        <>
                            <button type="button" className={classeBotao} onClick={() => atualizarEnvio(e.id, { status: 'recebido' })}>Marcar como recebido</button>
                            <button type="button" className={classeBotao} onClick={() => atualizarEnvio(e.id, { status: 'cancelado' })}>Cancelar</button>
                        </>
                    ) : (
                        <button type="button" className={classeBotao} onClick={() => atualizarEnvio(e.id, { status: 'a_caminho' })}>Reabrir</button>
                    )}
                    {confirmar ? (
                        <>
                            <button type="button" className={cn(classeBotao, 'border-red-500/40 text-red-300')} onClick={() => excluirEnvio(e.id)}>Confirmar exclusão</button>
                            <button type="button" className={classeBotao} onClick={() => setConfirmar(false)}>Manter</button>
                        </>
                    ) : (
                        <button type="button" className={cn(classeBotao, 'text-red-300')} onClick={() => setConfirmar(true)}>Excluir</button>
                    )}
                </div>
            </div>

            {aberto && (
                <div className="mt-3 overflow-x-auto rounded-lg border border-white/[0.06]">
                    <table className={classeTabela}>
                        <thead>
                            <tr>
                                <th className={classeTh}>Código ML</th>
                                <th className={classeTh}>Produto</th>
                                <th className={cn(classeTh, 'text-right')}>Enviado</th>
                                <th className={cn(classeTh, 'text-right')}>Chegou</th>
                                <th className={cn(classeTh, 'text-right')}>Falta</th>
                            </tr>
                        </thead>
                        <tbody>
                            {e.itens.map((i) => (
                                <tr key={i.cod}>
                                    <td className={cn(classeTd, 'font-mono text-[12px] text-white/70')}>{i.cod}</td>
                                    <td className={cn(classeTd, 'max-w-[360px] truncate text-white')}>{i.titulo || '—'}</td>
                                    <td className={cn(classeTd, 'text-right tabular-nums text-white/80')}>{fmtInt(i.quantidade)}</td>
                                    <td className={cn(classeTd, 'text-right tabular-nums text-emerald-300')}>{fmtInt(i.recebido)}</td>
                                    <td className={cn(classeTd, 'text-right font-bold tabular-nums text-white')}>{fmtInt(i.quantidade - i.recebido)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <p className="px-3 py-2 text-[11.5px] text-white/40">
                        {e.observacao ? `${e.observacao} · ` : ''}Última conferência das chegadas: {fmtDataHora(e.conferidoEm)}
                        {e.recebidoEm ? ` · encerrado em ${fmtDataHora(e.recebidoEm)}` : ''}
                    </p>
                </div>
            )}
        </li>
    );
}
