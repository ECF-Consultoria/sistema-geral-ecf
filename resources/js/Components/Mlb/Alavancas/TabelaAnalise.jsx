import { useState } from 'react';
import axios from 'axios';
import { Loader2 } from 'lucide-react';
import { BotaoAcao } from '@/Components/Publicador/Mesa/botoes';
import { mensagemDe } from '@/Components/Publicador/apoio';
import { rota } from './useAlavancas';
import { fmtBRL, fmtPct } from './formato';

const COLUNAS = ['Produto', 'Preço atual', 'Na promoção', 'Desconto', 'ML banca (estimativa)', 'Recebe no preço normal', 'Recebe na promoção', 'Margem', 'Alertas'];

/** "R$ N" com o aviso de frete quando o frete não entrou na conta. */
function Recebe({ r }) {
    if (! r) return <>—</>;

    return <>{fmtBRL(r.voce_recebe)}{r.frete_conhecido === false ? ' (sem frete)' : ''}</>;
}

/**
 * Análise sob demanda: quanto a loja recebe por produto, no preço normal e na promoção.
 * Mostra na ORDEM recebida (nunca ordena nem recomenda) e nunca pede custo ao usuário.
 * `pedidos`: lista de `{ item_id, preco_promocao, promotion_type, meli_percentage, seller_percentage, boost, estoque_minimo }`.
 */
export default function TabelaAnalise({ conta, pedidos, limite }) {
    const [estado, setEstado] = useState({ carregando: false, dados: null, erro: null });
    const excede = limite > 0 && pedidos.length > limite;

    async function analisar() {
        setEstado({ carregando: true, dados: null, erro: null });
        try {
            const r = await axios.post(rota('analise', conta), { itens: pedidos.slice(0, limite > 0 ? limite : pedidos.length) });
            setEstado({ carregando: false, dados: r.data, erro: null });
        } catch (e) {
            setEstado({ carregando: false, dados: null, erro: mensagemDe(e) });
        }
    }

    const itens = estado.dados?.itens ?? [];

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-3">
                <BotaoAcao onClick={analisar} disabled={pedidos.length === 0 || estado.carregando}>
                    {estado.carregando && <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />}
                    Analisar
                </BotaoAcao>
                {excede && <p className="text-[13px] font-normal text-white/55">Analise até {limite} produtos por vez.</p>}
            </div>

            {estado.erro && <p className="text-[13px] font-normal text-white/55">{estado.erro}</p>}
            {estado.dados?.parcial && (
                <p className="text-[13px] font-normal text-white/55">O limite de consultas ao Mercado Livre foi atingido: parte dos produtos ficou sem números. Tente de novo em instantes.</p>
            )}

            {itens.length > 0 && (
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-[13px] font-normal text-white/70">
                        <thead>
                            <tr className="text-white/55">
                                {COLUNAS.map((c) => <th key={c} scope="col" className="px-3 py-2 font-bold">{c}</th>)}
                            </tr>
                        </thead>
                        <tbody>
                            {itens.map((i) => (
                                <tr key={i.item_id} className="border-t border-white/[0.06] align-top">
                                    <td className="px-3 py-2">{i.titulo ?? i.item_id}<span className="block text-white/55">{i.item_id}</span></td>
                                    {i.erro ? (
                                        <td className="px-3 py-2 text-white/55" colSpan={COLUNAS.length - 1}>{i.erro}</td>
                                    ) : (
                                        <>
                                            <td className="px-3 py-2">{fmtBRL(i.preco_atual)}</td>
                                            <td className="px-3 py-2">{i.depende_do_carrinho ? 'depende do carrinho' : fmtBRL(i.preco_promocao)}</td>
                                            <td className="px-3 py-2">{fmtPct(i.desconto_percentual)}</td>
                                            <td className="px-3 py-2">{i.ml_banca === null || i.ml_banca === undefined ? '—' : fmtBRL(i.ml_banca)}</td>
                                            <td className="px-3 py-2">{i.calculado === false ? '—' : <Recebe r={i.recebe_normal} />}</td>
                                            <td className="px-3 py-2">{i.calculado === false ? '—' : (i.depende_do_carrinho ? 'depende do carrinho' : <Recebe r={i.recebe_promocao} />)}</td>
                                            <td className="px-3 py-2">{i.margem ? `${fmtBRL(i.margem.valor)} (${fmtPct(i.margem.percentual)})` : '—'}</td>
                                            <td className="px-3 py-2 text-amber-300">{(i.alertas ?? []).map((a) => a.texto).join(' ')}</td>
                                        </>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
