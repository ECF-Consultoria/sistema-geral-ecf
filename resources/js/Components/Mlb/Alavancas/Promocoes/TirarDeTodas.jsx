import { useState } from 'react';
import { BotaoAcao } from '@/Components/Publicador/Mesa/botoes';
import ModalConfirmacao from '../ModalConfirmacao';

/**
 * "Tirar de todas as promoções" de um produto da conta. A janela de confirmação mostra o que o
 * servidor leu: as promoções afetadas e o aviso de que oferta do dia e relâmpago não saem por aqui.
 */
export default function TirarDeTodas({ conta, produto, liberada, motivo, onConcluido }) {
    const [aberto, setAberto] = useState(false);

    return (
        <>
            <BotaoAcao
                disabled={! liberada}
                title={liberada ? undefined : motivo}
                onClick={() => setAberto(true)}
            >
                Tirar de todas as promoções
            </BotaoAcao>
            {aberto && (
                <ModalConfirmacao
                    aberto
                    conta={conta}
                    acao="convite.remover_todas"
                    itens={[{ item_id: produto.id }]}
                    titulo="Tirar de todas as promoções"
                    onFechar={() => setAberto(false)}
                    onConcluido={onConcluido}
                />
            )}
        </>
    );
}
