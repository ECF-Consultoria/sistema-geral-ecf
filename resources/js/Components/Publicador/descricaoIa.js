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
