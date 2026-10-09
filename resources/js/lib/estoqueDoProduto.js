// ─── Estoque do produto, no topo da ficha (09/10/2026) ───────────────────────
//
// O estoque mora em cada variação (`estrutura_produto_variacoes.estoque`); o topo
// da ficha só o mostra para o produto inteiro, sem coluna nova:
//  - UMA variação: o campo do topo É o estoque dela (editável, mesmo dado);
//  - várias: a SOMA das variações que informaram, só leitura. Ao criar a 2ª
//    variação, o valor fica na 1ª e o topo vira a soma — sai sozinho daqui.
// Vazio ("não informado") é diferente de 0 ("sem estoque"): 0 conta como
// informado, vazio não. Texto que o servidor recusaria (letra, negativo,
// "1.000") também não entra na soma — o erro aparece no cartão da variação.

const INTEIRO = /^\d{1,12}$/;

/** O estoque de uma variação como número, ou null quando não informado (ou inválido). */
export function estoqueInformado(valor) {
    if (valor === null || valor === undefined) return null;
    const texto = String(valor).trim();

    return INTEIRO.test(texto) ? Number(texto) : null;
}

/**
 * O que o topo da ficha mostra.
 *
 * @returns {{ editavel: boolean, chave: ?string, valor: string, total: number, informadas: number, parcial: ?string }}
 *   `editavel` + `chave`: o campo grava no estoque da única variação (`chave` = `_k` dela);
 *   `valor`: o texto do campo (a soma, ou vazio se nenhuma informou);
 *   `parcial`: "N de M variações informaram" quando só algumas informaram.
 */
export function estoqueDoProduto(vars) {
    const lista = Array.isArray(vars) ? vars : [];
    const total = lista.length;

    if (total <= 1) {
        const unica = lista[0] ?? null;
        const valor = unica?.estoque === null || unica?.estoque === undefined ? '' : String(unica.estoque);

        return { editavel: unica !== null, chave: unica?._k ?? null, valor, total, informadas: estoqueInformado(valor) === null ? 0 : 1, parcial: null };
    }

    let soma = 0;
    let informadas = 0;
    for (const v of lista) {
        const n = estoqueInformado(v?.estoque);
        if (n === null) continue;
        soma += n;
        informadas += 1;
    }

    return {
        editavel: false,
        chave: null,
        valor: informadas === 0 ? '' : String(soma),
        total,
        informadas,
        parcial: informadas > 0 && informadas < total ? `${informadas} de ${total} variações informaram` : null,
    };
}
