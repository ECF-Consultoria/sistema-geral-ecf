import AppLayout from '@/Layouts/AppLayout';
import BarraDaConta, { textoSeguro } from '@/Components/Mlb/Publicador/BarraDaConta';
import AbasDaConta from '@/Components/Mlb/Publicador/AbasDaConta';
import AvisoContaTravada from '@/Components/Mlb/Publicador/AvisoContaTravada';
import PainelDoProduto from '@/Components/Mlb/Publicador/PainelDoProduto';

/**
 * Tela do Produto (§3 da ETAPA-3, Fase 175 plano 04) — o detalhe de um produto
 * e das fases dele, destino de "Abrir produto" na lista.
 *
 * A aba ativa continua `produtos`: esta tela é um DETALHE da aba Produtos, não
 * uma aba nova — a barra da conta e as abas da Etapa 1 não mudam aqui.
 *
 * O conteúdo de verdade (os 6 blocos do contrato fechado por
 * `FamiliaDeFasesService`/`MlbPublicadorFaseController::mostrar()`) mora em
 * `Components/Mlb/Publicador/PainelDoProduto.jsx` — esta página só soma
 * `AppLayout` + `BarraDaConta` + `AbasDaConta` em volta dele. Mesma decisão
 * documentada na SUMMARY da 173-06: isolar o painel evita que o teste de
 * render real precise arrastar a árvore inteira do `AppLayout` (sino de
 * notificações, aviso de chamados, tema, Modo TV) só pra cobrir um bloco.
 */
export default function Produto(props) {
    const { empresa = {}, liberada = true, produto = {}, abas = { company_id: null } } = props;
    const nome = textoSeguro(produto?.nome, textoSeguro(empresa?.nome, 'produto'));

    return (
        <AppLayout title={`Publicador — ${nome}`}>
            <div className="mx-auto max-w-[1240px] px-8 py-8">
                <BarraDaConta empresa={empresa} liberada={liberada} />

                <div className="mb-6">
                    <AbasDaConta aba="produtos" conta={empresa?.chave} companyId={abas?.company_id ?? null} />
                </div>

                {!liberada && <AvisoContaTravada variante="faixa" className="mb-6" />}

                <PainelDoProduto {...props} />
            </div>
        </AppLayout>
    );
}
