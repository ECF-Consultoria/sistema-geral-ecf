import { Plus, RefreshCw } from 'lucide-react';
import { cn } from '@/lib/utils';
import { fmtDia, fmtInt, paraDia } from '@/lib/planejadorFull';
import {
    CampoNumero, CampoTexto, LinhaDoTempo, Painel, classeBotao, classeCampo, classeTabela, classeTd, classeTh,
} from './comum';

function CampoConfig({ chave, rotulo, unidade, dica, R, alterarConfig, min = 0, step = 1 }) {
    return (
        <label className="grid content-start gap-1">
            <span className="text-[11px] font-semibold uppercase tracking-wider text-white/45">{rotulo}</span>
            <span className="relative">
                <CampoNumero valor={R.C[chave]} min={min} step={step} className="pr-11" aoGravar={(v) => alterarConfig({ [chave]: v })} />
                <em className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-[12px] not-italic text-white/35">{unidade}</em>
            </span>
            {dica && <span className="text-[12px] leading-snug text-white/40">{dica}</span>}
        </label>
    );
}

const fmtDataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');

export default function Ajustes({ R, alterarConfig, fornecedores, alterarFornecedores, usoFornecedor, coleta, coletaOk, vendasPeriodo, atualizar, atualizando, temToken }) {
    const C = R.C;
    const props = { R, alterarConfig };

    const mudarFornecedor = (i, campos) => {
        const lista = fornecedores.map((f, j) => (j === i ? { ...f, ...campos } : f));
        alterarFornecedores(lista, campos.nome && campos.nome !== fornecedores[i].nome ? { de: fornecedores[i].nome, para: campos.nome } : null);
    };

    return (
        <div className="grid grid-cols-1 gap-4">
            <Painel titulo="Envio ao FULL" sub="Tudo em dias corridos, do momento em que você decide enviar até as unidades ficarem aptas para venda. Cada mudança recalcula a tela e fica salva para todo o time.">
                <div className="mt-3 grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-x-4 gap-y-3.5">
                    <CampoConfig chave="preparo" rotulo="Preparo interno" unidade="dias" dica="Separar, conferir, etiquetar e embalar no galpão." {...props} />
                    <CampoConfig chave="agenda" rotulo="Espera da agenda" unidade="dias" dica="Entre pedir o agendamento no ML e a data disponível de coleta ou entrega." {...props} />
                    <CampoConfig chave="transito" rotulo="Trânsito até o CD" unidade="dias" dica="Da coleta ou despacho até chegar no centro de distribuição." {...props} />
                    <CampoConfig chave="recebimento" rotulo="Recebimento no FULL" unidade="dias" dica="Da chegada no CD até ficar apto para venda. Perto da Black Friday costuma demorar mais." {...props} />
                    <label className="grid content-start gap-1">
                        <span className="text-[11px] font-semibold uppercase tracking-wider text-white/45">Data do próximo despacho</span>
                        <input type="date" value={C.proximoDespacho || ''} onChange={(e) => alterarConfig({ proximoDespacho: e.target.value })} className={classeCampo} />
                        <span className="text-[12px] leading-snug text-white/40">Em branco = mais cedo possível ({fmtDia(R.base + C.preparo + C.agenda, 'semana')}). Preencha quando já tiver agenda.</span>
                    </label>
                    <CampoConfig chave="intervaloEnvios" rotulo="Intervalo entre envios" unidade="dias" min={1} dica="De quanto em quanto tempo você envia ao FULL. 7 = semanal, 14 = quinzenal." {...props} />
                </div>
                <LinhaDoTempo R={R} compacta />
                <div className="mt-4 flex flex-wrap gap-x-6 gap-y-2 rounded-lg bg-white/[0.03] px-3.5 py-3 text-[13px] text-white/55">
                    <span>Lead time do envio <b className="tabular-nums text-white">{R.LTfull} dias</b></span>
                    <span>Despacho <b className="text-white">{fmtDia(R.ship, 'semana')}</b></span>
                    <span>Apto no FULL <b className="text-white">{fmtDia(R.arrival, 'semana')}</b></span>
                    <span>Próximo despacho <b className="text-white">{fmtDia(R.ship + C.intervaloEnvios, 'semana')}</b></span>
                </div>
            </Painel>

            <Painel titulo="Segurança e demanda" sub={`Dias extras de venda guardados como folga, por curva ABC de faturamento (A = primeiros ${Math.round(C.abcA * 100)}%, B até ${Math.round(C.abcB * 100)}%).`}>
                <div className="mt-3 grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-x-4 gap-y-3.5">
                    <CampoConfig chave="segA" rotulo="Segurança curva A" unidade="dias" dica="Os itens que mais faturam. Não podem faltar." {...props} />
                    <CampoConfig chave="segB" rotulo="Segurança curva B" unidade="dias" {...props} />
                    <CampoConfig chave="segC" rotulo="Segurança curva C" unidade="dias" {...props} />
                    <CampoConfig chave="limiteExcesso" rotulo="Limite de excesso" unidade="dias" min={1} dica="Cobertura acima disso aparece como Excesso." {...props} />
                    <CampoConfig chave="fatorSazonal" rotulo="Fator sazonal" unidade="×" step={0.05} dica="Multiplica a venda de todos os itens. 1,3 = +30% para a Black Friday." {...props} />
                </div>
                <p className="mt-3 text-[12px] text-white/40">
                    A venda/dia vem dos pedidos pagos de {fmtDia(paraDia(vendasPeriodo.de), 'ano')} a {fmtDia(paraDia(vendasPeriodo.ate), 'ano')} ({vendasPeriodo.dias} dias), somados por Código ML.
                    Para corrigir um item que ficou sem estoque parte do mês (a venda real foi maior) ou que vai entrar em promoção, use o ajuste de demanda no detalhe do produto.
                </p>
            </Painel>

            <Painel titulo="Fornecedores" sub="Lead time = do pedido até chegar no seu galpão. Em branco usa o padrão abaixo. Os nomes vêm da marca do anúncio: renomeie para o distribuidor real.">
                <div className="mb-4 mt-3 grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-x-4 gap-y-3.5">
                    <CampoConfig chave="leadTimeFornecedor" rotulo="Lead time padrão do fornecedor" unidade="dias" dica="Usado quando o fornecedor não tem prazo próprio." {...props} />
                    <CampoConfig chave="intervaloCompras" rotulo="Intervalo padrão entre compras" unidade="dias" min={1} dica="Define o tamanho do pedido." {...props} />
                </div>
                <div className="overflow-x-auto">
                    <table className={classeTabela}>
                        <thead>
                            <tr>
                                <th className={classeTh}>Fornecedor</th>
                                <th className={cn(classeTh, 'text-right')}>Lead time (dias)</th>
                                <th className={cn(classeTh, 'text-right')}>Intervalo entre compras (dias)</th>
                                <th className={classeTh}>Contato / observações</th>
                                <th className={cn(classeTh, 'text-right')}>SKUs</th>
                                <th className={classeTh} />
                            </tr>
                        </thead>
                        <tbody>
                            {fornecedores.map((f, i) => {
                                const n = usoFornecedor[f.nome] || 0;
                                return (
                                    <tr key={`${i}-${f.nome}`}>
                                        <td className={classeTd}>
                                            <CampoTexto valor={f.nome} aria-label="Nome do fornecedor" className="min-w-[160px]"
                                                aoGravar={(v) => { const nome = v.trim(); if (nome) mudarFornecedor(i, { nome }); }} />
                                        </td>
                                        <td className={cn(classeTd, 'text-right')}>
                                            <CampoNumero valor={f.leadTime} placeholder={String(C.leadTimeFornecedor)} className="w-[90px]" aria-label={`Lead time de ${f.nome}`}
                                                aoGravar={(v) => mudarFornecedor(i, { leadTime: v == null ? null : Math.max(0, Math.round(v)) })} />
                                        </td>
                                        <td className={cn(classeTd, 'text-right')}>
                                            <CampoNumero valor={f.ciclo} placeholder={String(C.intervaloCompras)} min={1} className="w-[90px]" aria-label={`Intervalo entre compras de ${f.nome}`}
                                                aoGravar={(v) => mudarFornecedor(i, { ciclo: v == null ? null : Math.max(1, Math.round(v)) })} />
                                        </td>
                                        <td className={classeTd}>
                                            <CampoTexto valor={f.observacoes || ''} aria-label={`Observações de ${f.nome}`} aoGravar={(v) => mudarFornecedor(i, { observacoes: v || null })} />
                                        </td>
                                        <td className={cn(classeTd, 'text-right tabular-nums text-white/70')}>{n}</td>
                                        <td className={cn(classeTd, 'text-right')}>
                                            <button
                                                type="button"
                                                disabled={n > 0}
                                                title={n > 0 ? 'Mude o fornecedor dos SKUs antes de remover' : undefined}
                                                onClick={() => alterarFornecedores(fornecedores.filter((_, j) => j !== i), null)}
                                                className="text-[12.5px] font-semibold text-red-300 disabled:cursor-default disabled:opacity-40"
                                            >
                                                Remover
                                            </button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                <button
                    type="button"
                    className={cn(classeBotao, 'mt-3')}
                    onClick={() => alterarFornecedores([...fornecedores, { nome: `Novo fornecedor ${fornecedores.length + 1}`, leadTime: null, ciclo: null, observacoes: null }], null)}
                >
                    <Plus size={14} /> Adicionar fornecedor
                </button>
            </Painel>

            <Painel titulo="Dados do Mercado Livre" sub="Coletados direto da API do ML todo dia às 06:30 — sem upload de planilha.">
                <dl className="mt-3 grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-x-4 gap-y-3 text-[13px]">
                    <Dado rotulo="Última coleta completa" valor={coletaOk ? fmtDataHora(coletaOk.iniciadoEm) : 'nenhuma ainda'} />
                    <Dado rotulo="Códigos ML no FULL" valor={coletaOk ? fmtInt(coletaOk.produtos) : '—'} />
                    <Dado rotulo={`Pedidos pagos em ${vendasPeriodo.dias} dias`} valor={coletaOk ? fmtInt(coletaOk.pedidos) : '—'} />
                    <Dado rotulo="Última tentativa" valor={coleta ? `${fmtDataHora(coleta.iniciadoEm)} · ${ROTULO_COLETA[coleta.status] || coleta.status}` : '—'} />
                </dl>
                {coleta?.status === 'erro' && (
                    <p className="mt-3 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-[12.5px] text-red-200">A última coleta falhou: {coleta.erro}</p>
                )}
                <div className="mt-4 flex flex-wrap items-center gap-3">
                    <button type="button" className={classeBotao} onClick={atualizar} disabled={atualizando || !temToken}>
                        <RefreshCw size={14} className={cn(atualizando && 'animate-spin')} /> {atualizando ? 'Atualizando…' : 'Atualizar agora'}
                    </button>
                    <span className="text-[12px] text-white/40">
                        Estoque: /inventories/…/stock/fulfillment (apto e em transferência). Vendas: pedidos pagos, por anúncio e variação. Chegadas: operações INBOUND_RECEPTION.
                        O estoque do galpão, os lead times e os envios registrados não vêm do ML — ficam salvos aqui.
                    </span>
                </div>
            </Painel>
        </div>
    );
}

const ROTULO_COLETA = { ok: 'concluída', erro: 'falhou', rodando: 'em andamento', sem_full: 'sem FULL' };

function Dado({ rotulo, valor }) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-[11px] font-semibold uppercase tracking-wider text-white/45">{rotulo}</dt>
            <dd className="font-semibold text-white">{valor}</dd>
        </div>
    );
}

