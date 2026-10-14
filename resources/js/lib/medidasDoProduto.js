// ═══════════════════════════════════════════════════════════════════════
// Medidas do produto fora da caixa (09/10/2026): funções puras.
//
// A ficha pede DUAS medidas: a do produto sozinho (bloco próprio, campos da
// categoria — comprimento, largura, altura, profundidade, diâmetro, peso) e a do
// volume (a caixa com o produto dentro, em cm e kg). O servidor manda as do
// produto num grupo marcado `medidas_do_produto`; a tela as tira da Ficha técnica
// e as mostra perto das Variações. Continuam sendo campos da ficha técnica:
// mesmo estado, mesmo PUT.
//
// A caixa "Usar as mesmas medidas do produto fora da caixa", que copiava as
// medidas do produto para o volume e as fazia acompanhar, saiu em 14/10/2026 a
// pedido do usuário — com ela, as funções que convertiam e comparavam as medidas.
// ═══════════════════════════════════════════════════════════════════════

/** Os ids das medidas do produto, na ordem do bloco (a mesma do volume). */
export const IDS_MEDIDAS_DO_PRODUTO = ['LENGTH', 'WIDTH', 'HEIGHT', 'DEPTH', 'DIAMETER', 'WEIGHT'];

/** O grupo das medidas do produto (o servidor o marca). */
export const ehGrupoDeMedidas = (grupo) => !! grupo?.medidas_do_produto;

/** Os grupos da Ficha técnica, sem o das medidas do produto (que tem bloco próprio). */
export const gruposSemMedidas = (grupos) => (Array.isArray(grupos) ? grupos : []).filter((g) => ! ehGrupoDeMedidas(g));

/** Os campos das medidas do produto que a categoria tem, na ordem do bloco. */
export function camposDeMedidas(grupos) {
    const campos = (Array.isArray(grupos) ? grupos : []).filter(ehGrupoDeMedidas).flatMap((g) => (Array.isArray(g.campos) ? g.campos : []));

    return IDS_MEDIDAS_DO_PRODUTO.map((id) => campos.find((c) => c?.id === id)).filter(Boolean);
}
