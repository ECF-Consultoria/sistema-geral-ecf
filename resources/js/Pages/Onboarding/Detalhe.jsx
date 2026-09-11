import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Activity, Handshake, KeyRound, Link2, Quote } from 'lucide-react';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import CabecalhoOnboarding from '@/Components/Onboarding/Painel/CabecalhoOnboarding';
import ProximaAcaoDestaque from '@/Components/Onboarding/Painel/ProximaAcaoDestaque';
import Responsabilidades from '@/Components/Onboarding/Painel/Responsabilidades';
import AtividadeRecente from '@/Components/Onboarding/Painel/AtividadeRecente';
import FluxoOnboarding from '@/Components/Onboarding/Painel/FluxoOnboarding';
import RelatorioInicial from '@/Components/Onboarding/RelatorioInicial';
import ReuniaoBloco from '@/Components/Onboarding/Painel/ReuniaoBloco';
import AcessoDoClienteAoPortal from '@/Components/Onboarding/Painel/AcessoDoClienteAoPortal';
import BlocoAcessos from '@/Components/Onboarding/Painel/BlocoAcessos';
import ContextoDaVenda from '@/Components/Onboarding/Painel/ContextoDaVenda';
import BlocoInvestimento from '@/Components/Onboarding/Painel/BlocoInvestimento';
import BlocoContatos from '@/Components/Onboarding/Painel/BlocoContatos';
import BlocoAgenda from '@/Components/Onboarding/Painel/BlocoAgenda';
import MapeamentoInicial from '@/Components/Onboarding/MapeamentoInicial';

/**
 * Onboarding/Detalhe — a página de UM onboarding.
 *
 * ### Por que ela foi remontada (19/08)
 * Antes, esta página empilhava nove blocos soltos e, no fim, a lista dos 27
 * passos agrupada por etapa. O efeito era o que o negócio descreveu como
 * "tudo jogado lá": o formulário de um assunto ficava longe do item de
 * checklist que ele fecha, e nada na tela dizia por onde começar.
 *
 * Cada bloco é ENTREGUE À SUA ETAPA (`extras`), e `FluxoOnboarding` monta as
 * etapas numeradas na ordem do processo — abrindo só a que tem trabalho
 * possível agora.
 *
 * ### O que mudou em 20/08 — de página para COCKPIT
 * A ordem das etapas, as regras e os blocos continuam os mesmos. O que faltava
 * era a camada de cima: quem abria a tela ainda tinha de varrer o fluxo para
 * descobrir o que travava e de quem era a bola. Entraram, nesta ordem de
 * leitura:
 *
 *  1. `CabecalhoOnboarding` — quem é a empresa, analista, produto, progresso e
 *     os três marcos reais da vida do onboarding;
 *  2. `ProximaAcaoDestaque` — a pergunta que a tela existe para responder,
 *     com o MOTIVO real e um botão que leva até a linha do passo;
 *  3. `Responsabilidades` — de quem é a bola, sem abrir etapa nenhuma;
 *  4. o fluxo (inalterado) e, ao lado, portal do cliente e atividade recente.
 *
 * Nenhuma regra nova: os quatro leem o que o backend já persistia.
 *
 * ### O que mudou em 11/09 — de cockpit para LISTA
 * O negócio pediu o desenho do portal de Polos: "simples e muito funcional".
 * O fluxo virou uma coluna única numerada (ver `FluxoOnboarding`) e a coluna
 * lateral deixou de existir.
 *
 * Os dois blocos do topo ficaram, porque respondem a pergunta pela qual a
 * tela existe e foram a correção de uma reclamação real ("tudo jogado lá"):
 * `CabecalhoOnboarding` (quem é a empresa, em que pé está) e
 * `ProximaAcaoDestaque` (o que trava agora, com o motivo).
 *
 * Tudo que respondia "como está indo" — portal do cliente, acessos que o
 * cliente vê, atividade recente, contexto da venda e responsabilidades —
 * virou MODAL numa barra logo abaixo do topo. São blocos de consulta: lidos
 * uma vez, e empilhados na tela custavam rolagem em toda visita. Um clique
 * continua sendo a mesma tela; o que se ganhou foi a lista sem concorrência.
 *
 * `ContextoDaVenda` saiu de `extras.informacoes_cliente` por isso. A regra de
 * 19/08 — "revisar SPIN exige tê-lo na MESMA tela, senão vira procurar em
 * outra" — continua valendo: o modal abre por cima da lista, não navega.
 *
 * ### O que fica FORA das etapas
 * `AcessoDoClienteAoPortal` — é ferramenta, não passo. A pergunta que ele
 * responde ("o
 * cliente já viu o que pedimos?") vale para a tela inteira, e enfiá-lo numa
 * etapa o esconderia justamente quando aquela etapa estivesse fechada. Ele foi
 * para a coluna lateral, junto da atividade recente, que responde a mesma
 * classe de pergunta.
 */
export default function Detalhe({
    onboarding,
    passos,
    relatorio = null,
    reuniao = null,
    link = null,
    mapeamento = null,
    respostas = null,
    acessos = null,
    proxima_acao = null,
    responsabilidades = null,
    linha_do_tempo = [],
    atividade = [],
}) {
    // `nonce` faz o mesmo passo poder ser focado duas vezes seguidas — sem ele
    // o segundo clique em "Ver pendência" não rolaria a tela.
    const [foco, setFoco] = useState(null);

    const verPendencia = (passo) =>
        setFoco({ etapa: passo.etapa ?? 'outros', passoId: passo.id, nonce: Date.now() });

    // Qual consulta está aberta (`null` = nenhuma). Um estado só: duas dessas
    // caixas abertas ao mesmo tempo não faria sentido nenhum.
    const [consulta, setConsulta] = useState(null);

    // Cada assunto entregue à etapa a que pertence. Chave = `etapa` do passo,
    // exatamente como o backend a grava — é o que garante que o formulário e
    // os itens que ele fecha apareçam juntos.
    const extras = {
        // A reunião ABRE o processo: nós marcamos a data e cobramos o cliente
        // para ela. O relatório inicial mora aqui porque é o documento que a
        // reunião existe para apresentar.
        agendamento: (
            <>
                {reuniao && <ReuniaoBloco onboardingId={onboarding.id} reuniao={reuniao} />}
                <BlocoAgenda onboardingId={onboarding.id} agenda={respostas?.agenda} />
                {relatorio && <RelatorioInicial onboardingId={onboarding.id} relatorio={relatorio} />}
            </>
        ),

        responsaveis: (
            <BlocoContatos
                onboardingId={onboarding.id}
                contatos={respostas?.contatos ?? []}
            />
        ),

        mapeamento: mapeamento ? (
            <MapeamentoInicial
                mapeamento={mapeamento}
                contexto="interno"
                rotaSincronizar={route('onboarding.mapeamento.sincronizar', onboarding.id)}
                rotaConfirmar={route('onboarding.mapeamento.confirmar', onboarding.id)}
            />
        ) : null,

        investimento: (
            <BlocoInvestimento
                onboardingId={onboarding.id}
                investimento={respostas?.investimento}
            />
        ),
    };

    return (
        <AppLayout title="Detalhe do onboarding">
            <Head title={`Onboarding — ${onboarding.empresa.nome}`} />

            <div className="space-y-5 max-w-3xl">
                {/* Volta para o COCKPIT (aba Onboarding de /companies), que é a
                    lista de onde se chega aqui desde 20/08. O painel antigo em
                    `/onboarding` continua existindo, mas não é mais o caminho
                    de ida — mandar a volta para lá deixaria o usuário numa
                    tela diferente da que ele veio. */}
                <Link
                    href={route('companies.index', { tab: 'onboarding' })}
                    className="inline-flex items-center gap-1.5 text-[12px] text-white/40 hover:text-ecf-yellow transition-colors"
                >
                    <ArrowLeft size={13} /> Voltar aos onboardings
                </Link>

                <CabecalhoOnboarding onboarding={onboarding} linhaDoTempo={linha_do_tempo} />

                <ProximaAcaoDestaque
                    situacao={onboarding.situacao}
                    situacaoLabel={onboarding.situacao_label}
                    passo={proxima_acao}
                    ultimoAcessoCliente={link?.ultimo_acesso ?? null}
                    aoVerPendencia={verPendencia}
                />

                {/* As consultas. Ficam numa barra de uma linha só para não
                    disputar espaço com a lista — que é onde se trabalha. */}
                <div className="flex flex-wrap gap-2">
                    {[
                        { chave: 'portal',      rotulo: 'Portal do cliente',  icone: Link2 },
                        { chave: 'acessos',     rotulo: 'Acessos do cliente', icone: KeyRound,  oculto: !acessos },
                        { chave: 'contexto',    rotulo: 'Contexto da venda',  icone: Quote },
                        { chave: 'responsaveis', rotulo: 'De quem é a bola',  icone: Handshake },
                        { chave: 'atividade',   rotulo: 'Atividade recente',  icone: Activity },
                    ].filter((b) => !b.oculto).map(({ chave, rotulo, icone: Icone }) => (
                        <button
                            key={chave}
                            type="button"
                            onClick={() => setConsulta(chave)}
                            className="inline-flex items-center gap-1.5 rounded-xl border border-white/[0.08] bg-white/[0.03] px-3 py-1.5 text-[12px] text-white/60 hover:text-white hover:border-white/20 transition-colors"
                        >
                            <Icone size={13} /> {rotulo}
                        </button>
                    ))}
                </div>

                <FluxoOnboarding
                    passos={passos}
                    onboardingId={onboarding.id}
                    confirmacoes={respostas?.confirmacoes ?? {}}
                    extras={extras}
                    foco={foco}
                />
            </div>

            <Dialog open={consulta !== null} onOpenChange={(aberto) => !aberto && setConsulta(null)}>
                <DialogContent className="max-w-2xl max-h-[85vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>
                            {{
                                portal:       'Portal do cliente',
                                acessos:      'Acessos que o cliente vê',
                                contexto:     'Contexto da venda',
                                responsaveis: 'De quem é a bola',
                                atividade:    'Atividade recente',
                            }[consulta] ?? ''}
                        </DialogTitle>
                    </DialogHeader>

                    {consulta === 'portal' && (
                        <AcessoDoClienteAoPortal companyId={onboarding.empresa.id} link={link} />
                    )}

                    {consulta === 'acessos' && acessos && (
                        <BlocoAcessos
                            rota={route('onboarding.acessos.empresa', onboarding.id)}
                            valores={acessos}
                            titulo="Acessos que o cliente vê"
                            ajuda="Link do App ECF e e-mail para o convite, desta empresa."
                        />
                    )}

                    {consulta === 'contexto' && (
                        <ContextoDaVenda spin={onboarding.spin} contexto={onboarding.contexto} />
                    )}

                    {consulta === 'responsaveis' && (
                        <Responsabilidades responsabilidades={responsabilidades} />
                    )}

                    {consulta === 'atividade' && <AtividadeRecente atividade={atividade} />}
                </DialogContent>
            </Dialog>

        </AppLayout>
    );
}
