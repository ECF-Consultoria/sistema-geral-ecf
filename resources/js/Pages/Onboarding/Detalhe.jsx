import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import CabecalhoOnboarding from '@/Components/Onboarding/Painel/CabecalhoOnboarding';
import ProximaAcaoDestaque from '@/Components/Onboarding/Painel/ProximaAcaoDestaque';
import Responsabilidades from '@/Components/Onboarding/Painel/Responsabilidades';
import AtividadeRecente from '@/Components/Onboarding/Painel/AtividadeRecente';
import FluxoOnboarding from '@/Components/Onboarding/Painel/FluxoOnboarding';
import RelatorioInicial from '@/Components/Onboarding/RelatorioInicial';
import ReuniaoBloco from '@/Components/Onboarding/Painel/ReuniaoBloco';
import LinkDoCliente from '@/Components/Onboarding/Painel/LinkDoCliente';
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
 * ### O que fica FORA das etapas
 * `LinkDoCliente` — é ferramenta, não passo. A pergunta que ele responde ("o
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

        // O que o Comercial já coletou. Revisar SPIN e contexto exige tê-los na
        // MESMA tela — senão "revisar" vira "procurar em outra" (§3).
        informacoes_cliente: (
            <ContextoDaVenda spin={onboarding.spin} contexto={onboarding.contexto} />
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

            <div className="space-y-5 max-w-[1500px]">
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

                <Responsabilidades responsabilidades={responsabilidades} />

                {/* Fluxo à esquerda porque é onde se TRABALHA; a coluna da
                    direita responde "como está indo", que se consulta menos e
                    pode descer no empilhamento em telas estreitas. */}
                <div className="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">
                    <div className="xl:col-span-2 min-w-0">
                        <FluxoOnboarding
                            passos={passos}
                            onboardingId={onboarding.id}
                            confirmacoes={respostas?.confirmacoes ?? {}}
                            extras={extras}
                            foco={foco}
                        />
                    </div>

                    <aside className="space-y-5 min-w-0 xl:sticky xl:top-4">
                        <LinkDoCliente companyId={onboarding.empresa.id} link={link} />
                        <AtividadeRecente atividade={atividade} />
                    </aside>
                </div>
            </div>
        </AppLayout>
    );
}
