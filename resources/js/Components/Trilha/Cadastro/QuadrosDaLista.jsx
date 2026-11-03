import { CHAVE_PLANILHA } from '@/lib/trilha';
import { Botao } from '@/Components/Trilha/Pecas';

// ─── Trilha de produtos — os quadros "Pelo sistema" e "Pela planilha" da Lista (equipe) ──
//
// Com a trilha de trabalho a equipe cadastra AQUI (3ª entrega). O quadro mora na
// pasta `Cadastro/` e chega por `lazy`: só quem escreve baixa este JS. O cliente
// e o "Ver como: Cliente" leem a nota neutra do `PainelDaLista`.
//
// - "Pelo sistema": um produto por vez ("+ Produto na lista", a gaveta `e:novo`).
// - "Pela planilha" (F7): várias de uma vez, pela planilha por categoria — "Usar
//   planilha" abre a gaveta `e:planilha`. Só aparece com o endereço da planilha
//   nas props (`comPlanilha`, de `escrita.planilha`).

/**
 * @param {{onAbrir?: ?(chave: string, origem: ?HTMLElement) => void, comPlanilha?: boolean}} props
 */
export default function QuadrosDaLista({ onAbrir = null, comPlanilha = false }) {
    const planilha = comPlanilha && typeof onAbrir === 'function';

    return (
        <div className="grid grid-cols-[repeat(auto-fit,minmax(260px,1fr))] gap-3 border-b border-trilha-line px-[18px] py-4" data-quadros-da-equipe="">
            <div className="flex flex-col gap-1.5 rounded-xl border border-trilha-line px-[14px] py-3">
                <b className="text-[14px] text-trilha-fg">Pelo sistema</b>
                <span className="text-[12.5px] text-trilha-muted">
                    Um produto por vez: “+ Produto na lista” pede o nome e o SKU e cria o produto na hora. O resto do cadastro se completa na gaveta dele, e o
                    produto completo segue sozinho para o mapeamento.
                </span>
            </div>
            {planilha && (
                <div className="flex flex-col gap-1.5 rounded-xl border border-trilha-line px-[14px] py-3" data-quadro="planilha">
                    <b className="text-[14px] text-trilha-fg">Pela planilha</b>
                    <span className="text-[12.5px] text-trilha-muted">
                        1. Escolha as categorias dos produtos. 2. Baixe a planilha já com os campos da ficha técnica de cada uma. 3. Preencha e suba de volta.
                    </span>
                    <span className="mt-auto pt-1">
                        <Botao data-chave={CHAVE_PLANILHA} data-acao="usar-planilha" onClick={(evento) => onAbrir(CHAVE_PLANILHA, evento.currentTarget)}>
                            Usar planilha
                        </Botao>
                    </span>
                </div>
            )}
        </div>
    );
}
