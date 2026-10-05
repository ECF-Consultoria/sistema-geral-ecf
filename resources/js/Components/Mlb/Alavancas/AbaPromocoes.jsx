import { useState } from 'react';
import { Secao } from '@/Components/Publicador/Mesa/comum';
import { BotaoAcao } from '@/Components/Publicador/Mesa/botoes';
import Convites from './Promocoes/Convites';
import DescontoIndividual from './Promocoes/DescontoIndividual';
import CampanhasDoVendedor from './Promocoes/CampanhasDoVendedor';
import CampanhasAutomaticas from './Promocoes/CampanhasAutomaticas';
import TirarDeTodas from './Promocoes/TirarDeTodas';
import SeletorDeProdutos from './SeletorDeProdutos';

/** Aba Promoções (Central de Promoções = convites do ML, Desconto individual, Campanhas do vendedor, Campanhas automáticas, Produtos da conta): cada seção só monta quando aberta, e uma só fica aberta por vez. */
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
            <Secao id="convites-ml" titulo="Central de Promoções" acao={botao('convites-ml')}>
                {aberta === 'convites-ml' && <Convites conta={conta} liberada={liberada} motivo={motivo} limites={limites} />}
            </Secao>
            <Secao id="desconto-individual" titulo="Desconto individual" acao={botao('desconto-individual')}>
                {aberta === 'desconto-individual' && <DescontoIndividual conta={conta} liberada={liberada} motivo={motivo} limites={limites} />}
            </Secao>
            <Secao id="campanhas-vendedor" titulo="Campanhas do vendedor" acao={botao('campanhas-vendedor')}>
                {aberta === 'campanhas-vendedor' && <CampanhasDoVendedor conta={conta} liberada={liberada} motivo={motivo} limites={limites} />}
            </Secao>
            <Secao id="campanhas-automaticas" titulo="Campanhas automáticas" acao={botao('campanhas-automaticas')}>
                {aberta === 'campanhas-automaticas' && <CampanhasAutomaticas conta={conta} liberada={liberada} motivo={motivo} />}
            </Secao>
            <Secao id="produtos-da-conta" titulo="Produtos da conta" acao={botao('produtos-da-conta')}>
                {aberta === 'produtos-da-conta' && (
                    <div className="border-t border-white/[0.06] px-3 py-4">
                        <SeletorDeProdutos
                            conta={conta}
                            selecionavel={false}
                            acaoDaLinha={(p) => <TirarDeTodas conta={conta} produto={p} liberada={liberada} motivo={motivo} />}
                        />
                    </div>
                )}
            </Secao>
        </section>
    );
}
