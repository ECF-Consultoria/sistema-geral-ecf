import { useState } from 'react';
import { Secao } from '@/Components/Publicador/Mesa/comum';
import { BotaoAcao } from '@/Components/Publicador/Mesa/botoes';
import Convites from './Promocoes/Convites';
import DescontoIndividual from './Promocoes/DescontoIndividual';

/** Aba Promoções: cada seção só monta quando aberta, e uma só fica aberta por vez. */
export default function AbaPromocoes({ conta, liberada, motivo, limites }) {
    const [aberta, setAberta] = useState('convites-ml');
    const alternar = (id) => setAberta((atual) => (atual === id ? null : id));
    const botao = (id) => (
        <BotaoAcao aria-expanded={aberta === id} onClick={() => alternar(id)}>{aberta === id ? 'Fechar' : 'Abrir'}</BotaoAcao>
    );

    return (
        <section className="space-y-6">
            <p className="text-[13px] font-normal text-white/55">
                Alterar o preço do anúncio depois pode derrubar o desconto ou tirar o produto da promoção.
            </p>
            <Secao id="convites-ml" titulo="Convites do Mercado Livre" acao={botao('convites-ml')}>
                {aberta === 'convites-ml' && <Convites conta={conta} liberada={liberada} motivo={motivo} limites={limites} />}
            </Secao>
            <Secao id="desconto-individual" titulo="Desconto individual" acao={botao('desconto-individual')}>
                {aberta === 'desconto-individual' && <DescontoIndividual conta={conta} liberada={liberada} motivo={motivo} limites={limites} />}
            </Secao>
        </section>
    );
}
