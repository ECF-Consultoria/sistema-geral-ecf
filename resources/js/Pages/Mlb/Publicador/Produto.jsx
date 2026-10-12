import AppLayout from '@/Layouts/AppLayout';
import { textoSeguro } from '@/Components/Mlb/Publicador/BarraDaConta';
import PublicadorHeader from '@/Components/Mlb/Publicador/header/PublicadorHeader';
import AvisoContaTravada from '@/Components/Mlb/Publicador/AvisoContaTravada';
import PainelDoProduto from '@/Components/Mlb/Publicador/PainelDoProduto';

/**
 * Tela do Produto (§3 da ETAPA-3, Fase 175 plano 04) — o detalhe de um produto
 * e das fases dele, destino de "Abrir produto" na lista.
 *
 * A aba ativa continua `produtos`: esta tela é um DETALHE da aba Produtos, não
 * uma aba nova — o header da conta e as abas da Etapa 1 não mudam aqui.
 *
 * O conteúdo de verdade (os 6 blocos do contrato fechado por
 * `FamiliaDeFasesService`/`MlbPublicadorFaseController::mostrar()`) mora em
 * `Components/Mlb/Publicador/PainelDoProduto.jsx` — esta página só soma
 * `AppLayout` + `PublicadorHeader` em volta dele. Mesma decisão
 * documentada na SUMMARY da 173-06: isolar o painel evita que o teste de
 * render real precise arrastar a árvore inteira do `AppLayout` (sino de
 * notificações, aviso de chamados, tema, Modo TV) só pra cobrir um bloco.
 *
 * ⚠️ Fase 177, plano 07: `BarraDaConta` + `AbasDaConta` → `PublicadorHeader`,
 * primeiro filho do `AppLayout` e FORA do container do corpo (é o header que
 * carrega o `-mx-6 -mt-6` que neutraliza o `p-6` do `<main>`, HEAD-14).
 * A faixa de `AvisoContaTravada` FICA no corpo: o selo do header é outro
 * elemento, e as duas aparições já conviviam antes.
 *
 * ⚠️ `cabecalho ?? null`: o default de parâmetro só cobre `undefined`, e
 * `cabecalho: null` numa recarga parcial do Inertia já derrubou tela aqui.
 */
export default function Produto(props) {
    const { empresa = {}, liberada = true, produto = {}, cabecalho = null } = props;
    const nome = textoSeguro(produto?.nome, textoSeguro(empresa?.nome, 'produto'));

    return (
        <AppLayout title={`Publicador — ${nome}`}>
            <PublicadorHeader aba="produtos" cabecalho={cabecalho ?? null} liberada={liberada} />

            <div className="mx-auto max-w-[1240px] px-8 py-8">
                {!liberada && <AvisoContaTravada variante="faixa" className="mb-6" />}

                <PainelDoProduto {...props} />
            </div>
        </AppLayout>
    );
}
