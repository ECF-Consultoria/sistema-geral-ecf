// ─── Trilha de produtos — o quadro "Pelo sistema" da Lista (equipe) ─────────
//
// Com a trilha de trabalho a equipe cadastra AQUI, um produto por vez (3ª
// entrega). O quadro mora na pasta `Cadastro/` e chega por `lazy`: só quem
// escreve baixa este JS. O cliente e o "Ver como: Cliente" leem a nota neutra
// do `PainelDaLista`. O "Pela planilha" não entra com a trilha de trabalho (a
// planilha por categoria é uma fatia própria, depois).

export default function QuadrosDaLista() {
    return (
        <div className="grid grid-cols-[repeat(auto-fit,minmax(260px,1fr))] gap-3 border-b border-trilha-line px-[18px] py-4" data-quadros-da-equipe="">
            <div className="flex flex-col gap-1.5 rounded-xl border border-trilha-line px-[14px] py-3">
                <b className="text-[14px] text-trilha-fg">Pelo sistema</b>
                <span className="text-[12.5px] text-trilha-muted">
                    Um produto por vez: “+ Produto na lista” pede o nome e o SKU e cria o produto na hora. O resto do cadastro se completa na gaveta dele, e o
                    produto completo segue sozinho para o mapeamento.
                </span>
            </div>
        </div>
    );
}
