import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { useState } from 'react';
import AvisoAlavancasTravadas from '@/Components/Mlb/Alavancas/AvisoAlavancasTravadas';
import Panorama from '@/Components/Mlb/Alavancas/Panorama';
import AbaPromocoes from '@/Components/Mlb/Alavancas/AbaPromocoes';
import AbaCupons from '@/Components/Mlb/Alavancas/AbaCupons';
import AbaPublicidade from '@/Components/Mlb/Alavancas/AbaPublicidade';
import AbaAtacado from '@/Components/Mlb/Alavancas/AbaAtacado';
import Historico from '@/Components/Mlb/Alavancas/Historico';
import PainelDoAnuncio from '@/Components/Mlb/Alavancas/PainelDoAnuncio';
import PublicadorHeader from '@/Components/Mlb/Publicador/header/PublicadorHeader';
import LinkReconexao from '@/Components/Mlb/Publicador/LinkReconexao';
import { LINK } from '@/Components/Publicador/Mesa/comum';

// Ordem final: Promoções | Cupons | Publicidade | Atacado (Cupons e Atacado entram nos planos 166-14 e 166-15).
const ABAS = [
    { chave: 'promocoes', rotulo: 'Promoções' },
    { chave: 'cupons', rotulo: 'Cupons' },
    { chave: 'publicidade', rotulo: 'Publicidade' },
    { chave: 'atacado', rotulo: 'Atacado' },
];

const ABA_INICIAL = () => {
    const pedida = new URLSearchParams(window.location.search).get('aba');

    return ABAS.some((a) => a.chave === pedida) ? pedida : (ABAS[0]?.chave ?? null);
};

/**
 * A área Alavancas da empresa: promoções, cupons, publicidade e atacado da conta do Mercado Livre.
 * Conta sem token não monta nenhum leitor; conta não liberada só vê e analisa.
 * Vinda da fila de publicados (`?item=MLB…`, 09/10/2026), o anúncio aparece num painel no topo.
 */
/**
 * ⚠️ Fase 177, plano 07: `BarraDaConta` + `AbasDaConta` → `PublicadorHeader`,
 * primeiro filho do `AppLayout` e FORA do container do corpo (é o header que
 * carrega o `-mx-6 -mt-6` que neutraliza o `p-6` do `<main>`, HEAD-14).
 *
 * ⚠️ O contador da aba Alavancas NÃO se perdeu: ele deixou de ser passado à mão
 * (`contagemAlavancas={alavancas.tarefas_abertas}`) e passou a vir dentro de
 * `cabecalho.contadores.alavancas`, que o servidor calcula com a MESMA chamada
 * (`PubTarefa::abertasDaConta($mlbEmpresa, $company)`, ver
 * `CabecalhoPublicadorService::para()` e `MlbAlavancasController::index()`).
 * Mesmo número, uma fonte só.
 *
 * ⚠️ `cabecalho ?? null`: o default de parâmetro só cobre `undefined`, e
 * `cabecalho: null` numa recarga parcial do Inertia já derrubou tela aqui.
 */
export default function Alavancas({ empresa, alavancas, cabecalho = null }) {
    const [aba, setAba] = useState(ABA_INICIAL);
    const [verHistorico, setVerHistorico] = useState(false);
    // O servidor só devolve `item` no formato MLB…; fechar o painel tira o anúncio da URL.
    const [item, setItem] = useState(typeof alavancas.item === 'string' ? alavancas.item : null);

    const conta = empresa.chave;

    function trocarAba(chave) {
        setAba(chave);
        setVerHistorico(false);
        const url = new URL(window.location.href);
        url.searchParams.set('aba', chave);
        window.history.replaceState(window.history.state, '', url);
    }

    function fecharAnuncio() {
        setItem(null);
        const url = new URL(window.location.href);
        url.searchParams.delete('item');
        window.history.replaceState(window.history.state, '', url);
    }

    return (
        <AppLayout title={`Alavancas — ${empresa.nome}`}>
            <PublicadorHeader aba="alavancas" cabecalho={cabecalho ?? null} liberada={alavancas.liberada} />

            <div className="mx-auto max-w-[1240px] px-8 py-8">

                {! alavancas.tem_conta ? (
                    <section className="rounded-xl bg-ecf-card p-6">
                        <p className="text-[15px] font-bold text-white">Conecte a conta do Mercado Livre desta empresa para ver as alavancas.</p>
                        <p className="mt-1 text-[13px] font-normal text-white/55">Sem a conexão não há o que ler da conta.</p>
                        <div className="mt-4">
                            <LinkReconexao link={empresa.link_reconexao} />
                        </div>
                    </section>
                ) : (
                    <>
                        {! alavancas.liberada && (
                            <AvisoAlavancasTravadas variante="faixa" className="mb-6" />
                        )}

                        {item && (
                            <div className="mb-6">
                                <PainelDoAnuncio key={item} conta={conta} item={item} onAbrirAba={trocarAba} onFechar={fecharAnuncio} />
                            </div>
                        )}

                        <div className="mb-6">
                            <Panorama conta={conta} />
                        </div>

                        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <div className="flex flex-wrap gap-2" role="group" aria-label="Alavanca">
                                {ABAS.map((a) => {
                                    const ativa = ! verHistorico && aba === a.chave;

                                    return (
                                        <button
                                            key={a.chave}
                                            type="button"
                                            aria-pressed={ativa}
                                            onClick={() => trocarAba(a.chave)}
                                            className={cn(
                                                'inline-flex h-10 items-center rounded-lg border px-4 text-[13px] font-normal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                                                ativa
                                                    ? 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow'
                                                    : 'border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.06]',
                                            )}
                                        >
                                            {a.rotulo}
                                        </button>
                                    );
                                })}
                            </div>
                            <button type="button" onClick={() => setVerHistorico((v) => ! v)} className={LINK}>
                                {verHistorico ? 'Voltar às alavancas' : 'Histórico de alterações'}
                            </button>
                        </div>

                        {verHistorico && <Historico conta={conta} />}
                        {! verHistorico && aba === 'promocoes' && (
                            <AbaPromocoes conta={conta} liberada={alavancas.liberada} motivo={alavancas.motivo} limites={alavancas.limites} />
                        )}
                        {! verHistorico && aba === 'cupons' && (
                            <AbaCupons conta={conta} liberada={alavancas.liberada} motivo={alavancas.motivo} limites={alavancas.limites} />
                        )}
                        {! verHistorico && aba === 'publicidade' && <AbaPublicidade conta={conta} />}
                        {! verHistorico && aba === 'atacado' && (
                            <AbaAtacado conta={conta} liberada={alavancas.liberada} motivo={alavancas.motivo} />
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
