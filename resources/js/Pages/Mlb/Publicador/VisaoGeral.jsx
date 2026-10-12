import AppLayout from '@/Layouts/AppLayout';
import { textoSeguro } from '@/Components/Mlb/Publicador/BarraDaConta';
import PublicadorHeader from '@/Components/Mlb/Publicador/header/PublicadorHeader';
import PainelVisaoGeral from '@/Components/Mlb/Publicador/PainelVisaoGeral';

/**
 * Página "Visão geral" do Publicador (Fase 173, plano 06) — primeira aba da
 * barra da conta, destino ao abrir uma empresa (ver `AnunciosEmpresas.jsx`).
 *
 * O conteúdo de verdade (os 7 blocos do contrato fechado por
 * `PainelVisaoGeralService`/`MlbPublicadorEntradaController::visaoGeral()`,
 * plan 04) mora em `Components/Mlb/Publicador/PainelVisaoGeral.jsx` — esta
 * página só soma `AppLayout` + `PublicadorHeader` em volta dele.
 * Decisão documentada na SUMMARY da plan 06: isolar o painel evita que o
 * teste de render real precise arrastar a árvore inteira do `AppLayout`
 * (sino de notificações, aviso de chamados, tema, Modo TV) só pra cobrir os
 * blocos da Visão geral.
 *
 * ⚠️ Fase 177, plano 07 — `BarraDaConta` + `AbasDaConta` saíram e o
 * `PublicadorHeader` global entrou no lugar das duas. Ele é o PRIMEIRO filho do
 * `AppLayout`, FORA do container do corpo: é o header que carrega o
 * `-mx-6 -mt-6` que neutraliza o `p-6` do `<main>` (HEAD-14), e por isso ele
 * não pode nascer dentro de um container com `px-8`.
 *
 * O que o header resolve por dentro, a partir da prop `cabecalho` do servidor
 * (`CabecalhoPublicadorService::para()`, plano 177-03): nome e chave da conta,
 * selos de conexão, "trocar empresa" (agora dropdown), as abas com contadores e
 * o D23. A página não passa nada disso à mão.
 *
 * ⚠️ `cabecalho ?? null`: o default de parâmetro só cobre `undefined`, e
 * `cabecalho: null` numa recarga parcial do Inertia já derrubou tela aqui.
 */
export default function VisaoGeral(props) {
    const { empresa = {}, liberada = true, cabecalho = null } = props;
    const nome = textoSeguro(empresa?.nome, 'conta');

    return (
        <AppLayout title={`Publicador — ${nome}`}>
            <PublicadorHeader aba="visao-geral" cabecalho={cabecalho ?? null} liberada={liberada} />

            <div className="mx-auto max-w-[1240px] px-8 py-8">
                <PainelVisaoGeral {...props} />
            </div>
        </AppLayout>
    );
}
