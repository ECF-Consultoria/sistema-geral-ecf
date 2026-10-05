import { useState } from 'react';
import { cn } from '@/lib/utils';
import { CAMPO } from '@/Components/Publicador/Mesa/comum';
import { BotaoAcao } from '@/Components/Publicador/Mesa/botoes';
import SeletorDeProdutos from '../SeletorDeProdutos';
import ModalConfirmacao from '../ModalConfirmacao';

/** Aceita "12,50" e "12.5"; vazio ou inválido vira null. */
const numero = (texto) => {
    const bruto = String(texto ?? '').trim();
    if (bruto === '') return null;
    const n = Number(bruto.includes(',') ? bruto.split('.').join('').replace(',', '.') : bruto);

    return Number.isFinite(n) && n > 0 ? n : null;
};

/**
 * Põe produtos escolhidos na conta numa promoção que o vendedor criou (campanha ou cupom).
 * `comPreco` mostra o campo de preço por produto (campanha do vendedor); sem ele o ML define o preço.
 */
export default function AdicionarProdutos({ conta, promocao, comPreco = false, liberada, motivo, limites, onConcluido }) {
    const [produtos, setProdutos] = useState([]);
    const [precos, setPrecos] = useState({});
    const [alvo, setAlvo] = useState(null);

    const porLote = limites?.itens_por_lote ?? 50;
    const faltaPreco = comPreco && produtos.some((p) => numero(precos[p.id]) === null);

    function itemDe(p) {
        const item = { item_id: p.id, promotion_id: promocao.id, promotion_type: promocao.tipo };
        if (comPreco) item.deal_price = numero(precos[p.id]);

        return item;
    }

    function aoConcluir(resultado) {
        setProdutos([]);
        setPrecos({});
        onConcluido?.(resultado);
    }

    return (
        <div className="space-y-3 border-t border-white/[0.06] px-3 py-4">
            <p className="text-[13px] font-bold text-white/70">Incluir produtos na promoção</p>
            <SeletorDeProdutos conta={conta} soElegiveis maximo={porLote} selecionados={produtos} onMudar={setProdutos} />

            {comPreco && produtos.length > 0 && (
                <ul className="space-y-2">
                    {produtos.map((p) => (
                        <li key={p.id} className="flex flex-wrap items-end gap-3 text-[13px] font-normal text-white/70">
                            <span className="min-w-[200px] flex-1">{p.titulo ?? p.id}</span>
                            <label className="block w-40">
                                <span className="mb-1 block text-[11px] font-normal text-white/55">Preço na campanha</span>
                                <input
                                    type="text"
                                    inputMode="decimal"
                                    value={precos[p.id] ?? ''}
                                    onChange={(ev) => setPrecos((antes) => ({ ...antes, [p.id]: ev.target.value }))}
                                    className={cn(CAMPO, 'h-10')}
                                />
                            </label>
                        </li>
                    ))}
                </ul>
            )}

            {produtos.length > 0 && (
                <BotaoAcao
                    primario
                    disabled={! liberada || faltaPreco}
                    title={liberada ? undefined : motivo}
                    onClick={() => setAlvo({ acao: 'convite.inscrever', titulo: 'Incluir produtos na promoção', itens: produtos.map(itemDe) })}
                >
                    Revisar e incluir
                </BotaoAcao>
            )}

            {alvo && (
                <ModalConfirmacao
                    aberto
                    conta={conta}
                    acao={alvo.acao}
                    itens={alvo.itens}
                    titulo={alvo.titulo}
                    onFechar={() => setAlvo(null)}
                    onConcluido={aoConcluir}
                />
            )}
        </div>
    );
}
