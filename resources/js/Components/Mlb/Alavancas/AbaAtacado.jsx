import { useState } from 'react';
import { useLeitura } from './useAlavancas';
import SeletorDeProdutos from './SeletorDeProdutos';
import FaixasDoAnuncio from './Atacado/FaixasDoAnuncio';

/** Atacado em percentual para empresas: só existe em conta com a tag business (o ML libera por convite). */
export default function AbaAtacado({ conta, liberada, motivo }) {
    const { dados, erro, carregando, recarregar } = useLeitura('atacado', conta);
    const [escolhidos, setEscolhidos] = useState([]);
    const item = escolhidos[0]?.id ?? null;

    if (carregando) return <p className="text-[13px] font-normal text-white/55">Carregando…</p>;
    if (erro) {
        return (
            <p className="text-[13px] font-normal text-white/55">
                {erro} <button type="button" onClick={() => recarregar()} className="font-bold text-white/70 hover:text-ecf-yellow">Tentar de novo</button>
            </p>
        );
    }
    if (! dados?.business) {
        return <p className="text-[13px] font-normal text-white/55">{dados?.explicacao}</p>;
    }

    return (
        <div className="space-y-4">
            <p className="text-[13px] font-normal text-white/55">Escolha o anúncio para ver e editar as faixas de desconto para empresas.</p>
            <SeletorDeProdutos conta={conta} maximo={1} selecionados={escolhidos} onMudar={setEscolhidos} />
            {item && <FaixasDoAnuncio key={item} conta={conta} item={item} liberada={liberada} motivo={motivo} />}
        </div>
    );
}
