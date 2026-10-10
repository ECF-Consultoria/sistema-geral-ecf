import AppLayout from '@/Layouts/AppLayout';
import BarraDaConta, { textoSeguro } from '@/Components/Mlb/Publicador/BarraDaConta';
import AbasDaConta from '@/Components/Mlb/Publicador/AbasDaConta';
import PainelVisaoGeral from '@/Components/Mlb/Publicador/PainelVisaoGeral';

/**
 * Página "Visão geral" do Publicador (Fase 173, plano 06) — primeira aba da
 * barra da conta, destino ao abrir uma empresa (ver `AnunciosEmpresas.jsx`).
 *
 * O conteúdo de verdade (os 7 blocos do contrato fechado por
 * `PainelVisaoGeralService`/`MlbPublicadorEntradaController::visaoGeral()`,
 * plan 04) mora em `Components/Mlb/Publicador/PainelVisaoGeral.jsx` — esta
 * página só soma `AppLayout` + `BarraDaConta` + `AbasDaConta` em volta dele.
 * Decisão documentada na SUMMARY da plan 06: isolar o painel evita que o
 * teste de render real precise arrastar a árvore inteira do `AppLayout`
 * (sino de notificações, aviso de chamados, tema, Modo TV) só pra cobrir os
 * blocos da Visão geral.
 */
export default function VisaoGeral(props) {
    const { empresa = {}, liberada = true, abas = { company_id: null } } = props;
    const nome = textoSeguro(empresa?.nome, 'conta');

    return (
        <AppLayout title={`Publicador — ${nome}`}>
            <div className="mx-auto max-w-[1240px] px-8 py-8">
                <BarraDaConta empresa={empresa} liberada={liberada} />

                <div className="mb-6">
                    <AbasDaConta aba="visao-geral" conta={empresa?.chave} companyId={abas?.company_id ?? null} contagemAlavancas={abas?.alavancas_pendentes ?? null} />
                </div>

                <PainelVisaoGeral {...props} />
            </div>
        </AppLayout>
    );
}
