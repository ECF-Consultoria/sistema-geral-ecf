import { Secao } from '@/Components/Publicador/Mesa/comum';
import Convites from './Promocoes/Convites';

/** Aba Promoções: convites do Mercado Livre (descontos e campanhas do vendedor entram no 166-14). */
export default function AbaPromocoes({ conta, liberada, motivo, limites }) {
    return (
        <section className="space-y-6">
            <p className="text-[13px] font-normal text-white/55">
                Alterar o preço do anúncio depois pode derrubar o desconto ou tirar o produto da promoção.
            </p>
            <Secao id="convites-ml" titulo="Convites do Mercado Livre">
                <Convites conta={conta} liberada={liberada} motivo={motivo} limites={limites} />
            </Secao>
        </section>
    );
}
