// Regras puras da descrição por IA (Fase 172, D-11). Sem React: testáveis em node.

/** Dispara sozinho só com a descrição do anúncio vazia, a do cliente disponível e a mesa editável. */
export function deveDispararAuto({ descricao, descricaoCliente, disabled }) {
    if (disabled) return false;
    if (! String(descricaoCliente ?? '').trim()) return false;

    return String(descricao ?? '').trim() === '';
}

/**
 * O texto gerado só entra se a pessoa não mexeu no campo desde o pedido.
 * Automático: só com o campo ainda vazio. Manual: só se o texto é o mesmo de quando pediu.
 */
export function podeAplicarDescricao({ automatico, textoNoPedido, textoAgora }) {
    if (automatico) return String(textoAgora ?? '').trim() === '';

    return (textoAgora ?? '') === (textoNoPedido ?? '');
}

/**
 * O que fazer com uma leitura do estado do pedido que acabou de voltar (review 172 WR-01). Decide com o
 * estado de AGORA, relido depois do `await` — nunca com o de antes da leitura: no meio do caminho o
 * tempo pode ter esgotado, outro pedido pode ter começado, a página pode ter saído ou a mesa travado.
 *
 * @returns {'ignorar'|'continuar'|'aplicar'|'guardar'|'erro'}
 *   `guardar` = mostra "Usar a descrição gerada" sem aplicar (a pessoa mexeu no campo ou a mesa está travada)
 */
export function decidirLeitura({ vivo, atual, data, disabled, textoAgora }) {
    if (! vivo || atual?.status !== 'rodando' || ! atual?.pedido || data?.pedido !== atual.pedido) return 'ignorar';
    if (data.status === 'erro') return 'erro';
    if (data.status !== 'pronto') return 'continuar';
    if (disabled) return 'guardar';

    return podeAplicarDescricao({ automatico: atual.automatico, textoNoPedido: atual.textoNoPedido, textoAgora }) ? 'aplicar' : 'guardar';
}
