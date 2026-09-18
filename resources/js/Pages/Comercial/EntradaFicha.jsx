import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, FileSignature } from 'lucide-react';
import CardChecklistAdministrativo from '@/Components/ChecklistAdministrativo/CardChecklistAdministrativo';

/**
 * A ficha da ENTRADA de uma empresa — a casa do checklist administrativo.
 *
 * ### Por que ela existe (11/09)
 * O checklist morava dentro da ficha de Contrato, que era a "ficha única" da
 * Fase 152. Dos 9 itens só 3 são de contrato; os outros 6 são o que o PDF do
 * fluxo chama de "Estrutura e Comunicação" — grupo de WhatsApp, e-mail
 * colaborador, link Adman, grant do Mercado Livre e Portal do Cliente. Ler
 * tudo isso sob o título "Contrato" confundia, e a separação foi pedida.
 *
 * A ficha de Contrato voltou a ser só o contrato: gerar, cadastro e envelopes.
 * As duas continuam ligadas por um link em cada sentido — quem está marcando
 * "Contrato assinado" aqui chega ao documento num clique.
 *
 * ### Enxuta, mas não estreita (18/09)
 * A versão anterior era uma coluna só, presa em `max-w-4xl`, com nove cartões
 * iguais empilhados e o botão de finalizar lá no fim da rolagem. Enxuta virou
 * apertada. Agora a largura é usada: um cabeçalho que diz de quem é a ficha e
 * em que etapa ela está, e abaixo dele a sequência à esquerda com o estado da
 * entrada grudento à direita. Continua sendo cabeçalho e checklist, nada mais
 * — a disciplina de não encher a tela de blocos disputando espaço com o
 * trabalho segue valendo.
 */

/** Espelho do vocabulário travado de `Company::ETAPAS` (§10). */
const ETAPA_LABELS = {
    aguardando_administrativo: 'Aguardando Administrativo',
    administrativo_andamento: 'Administrativo em Andamento',
    aguardando_assinatura: 'Aguardando Assinatura',
    administrativo_concluido: 'Administrativo Concluído',
    aguardando_distribuicao: 'Aguardando Distribuição',
    aguardando_onboarding: 'Aguardando Onboarding',
    onboarding_andamento: 'Onboarding em Andamento',
    onboarding_concluido: 'Onboarding Concluído',
    em_operacao: 'Em Operação',
};

export default function EntradaFicha({
    company,
    // Defaults defensivos: a página não pode quebrar se for renderizada por um
    // caminho que ainda não envie estas chaves.
    checklist = null,
    pode_ver_contrato = false,
    pode_finalizar = { permitido: false, requisito_faltante: null },
    adman_register_url = null,
    portal_cliente_url = null,
    mensagem_boas_vindas = null,
    contrato_acesso = null,
    ficha_contrato_url = null,
}) {
    const etapa = company.etapa ? (ETAPA_LABELS[company.etapa] ?? company.etapa) : null;

    return (
        <AppLayout title={`Entrada — ${company.name}`}>
            <Head title={`Entrada — ${company.name}`} />

            <main className="mx-auto max-w-6xl px-5 py-6 sm:px-8 sm:py-8">
                <Link
                    href={route('comercial.entrada.index')}
                    className="inline-flex items-center gap-1.5 text-[12px] text-white/40 transition-colors hover:text-ecf-yellow"
                >
                    <ArrowLeft size={13} /> Voltar à Entrada
                </Link>

                <header className="mt-5 flex flex-wrap items-end justify-between gap-x-6 gap-y-4 border-b border-white/[0.07] pb-7">
                    <div className="min-w-0">
                        {/* `break-words`, nunca `truncate`: no celular o nome da
                            empresa virava "DEMO · Entrada com c…" e a ficha
                            deixava de dizer de quem ela é. */}
                        <h1 className="break-words font-display text-[1.7rem] font-extrabold leading-tight tracking-tight text-white sm:text-[1.9rem]">
                            {company.name}
                        </h1>
                        <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12.5px]">
                            {company.cnpj && <span className="tabular-nums text-white/40">{company.cnpj}</span>}
                            {etapa && (
                                <span className="rounded-full border border-white/[0.09] bg-white/[0.03] px-2.5 py-0.5 text-white/55">
                                    {etapa}
                                </span>
                            )}
                        </div>
                    </div>

                    {/* O contrato é GERADO na outra ficha — o checklist não gera
                        nada. O link existe para quem marca "Contrato enviado"
                        não ter de procurar onde fica. */}
                    {ficha_contrato_url && (
                        <Link
                            href={ficha_contrato_url}
                            className="inline-flex shrink-0 items-center gap-1.5 rounded-xl border border-white/[0.08] bg-white/[0.03] px-3.5 py-2 text-[12.5px] text-white/60 transition-colors hover:border-white/20 hover:text-white"
                        >
                            <FileSignature size={13} /> Ficha de contrato
                        </Link>
                    )}
                </header>

                {/* ⚠️ A guarda condicional é OBRIGATÓRIA e já foi perdida uma
                    vez num reorder da ficha antiga. Sem ela o fechamento abaixo
                    vira TEXTO na tela e o build passa — JSX aceita esses
                    caracteres como conteúdo. Se aparecer um `)}` solto na tela,
                    é isto. */}
                {checklist && (
                    <div className="mt-8">
                        <CardChecklistAdministrativo
                            checklist={checklist}
                            companyId={company.id}
                            podeVerContrato={pode_ver_contrato}
                            podeFinalizar={pode_finalizar}
                            admanRegisterUrl={adman_register_url}
                            portalClienteUrl={portal_cliente_url}
                            mensagemBoasVindas={mensagem_boas_vindas}
                            contratoAcesso={contrato_acesso}
                            emailColaborador={company.email_colaborador ?? null}
                        />
                    </div>
                )}

                {!checklist && (
                    <div className="mt-8 rounded-2xl border border-white/[0.07] bg-white/[0.02] px-6 py-14 text-center">
                        <p className="text-[13px] text-white/45">
                            Esta empresa ainda não tem checklist administrativo.
                        </p>
                    </div>
                )}
            </main>
        </AppLayout>
    );
}
