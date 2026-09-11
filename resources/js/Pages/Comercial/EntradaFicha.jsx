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
 * ### Deliberadamente enxuta
 * Cabeçalho e checklist, nada mais. É a mesma disciplina que o onboarding e o
 * portal do cliente receberam no mesmo dia: uma coluna, sem blocos disputando
 * espaço com o trabalho.
 */
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
    return (
        <AppLayout title={`Entrada — ${company.name}`}>
            <Head title={`Entrada — ${company.name}`} />

            <main className="p-6">
                <div className="space-y-5 max-w-4xl">
                    <Link
                        href={route('comercial.entrada.index')}
                        className="inline-flex items-center gap-1.5 text-[12px] text-white/40 hover:text-ecf-yellow transition-colors"
                    >
                        <ArrowLeft size={13} /> Voltar à Entrada
                    </Link>

                    <div className="flex items-start justify-between gap-4 flex-wrap">
                        <div className="min-w-0">
                            <h1 className="text-white font-display font-bold text-2xl tracking-tight truncate">
                                {company.name}
                            </h1>
                            {company.cnpj && (
                                <p className="text-white/40 text-[13px] mt-0.5 tabular-nums">{company.cnpj}</p>
                            )}
                        </div>

                        {/* O contrato é GERADO na outra ficha — o checklist não
                            gera nada. O link existe para quem marca "Contrato
                            enviado" não ter de procurar onde fica. */}
                        {ficha_contrato_url && (
                            <Link
                                href={ficha_contrato_url}
                                className="inline-flex items-center gap-1.5 rounded-xl border border-white/[0.08] bg-white/[0.03] px-3 py-1.5 text-[12px] text-white/60 hover:text-white hover:border-white/20 transition-colors shrink-0"
                            >
                                <FileSignature size={13} /> Ficha de contrato
                            </Link>
                        )}
                    </div>

                    {/* ⚠️ A guarda condicional é OBRIGATÓRIA e já foi perdida uma
                        vez num reorder da ficha antiga. Sem ela o fechamento
                        abaixo vira TEXTO na tela e o build passa — JSX aceita
                        esses caracteres como conteúdo. Se aparecer um `)}` solto
                        na tela, é isto. */}
                    {checklist && (
                        <CardChecklistAdministrativo
                            checklist={checklist}
                            companyId={company.id}
                            podeVerContrato={pode_ver_contrato}
                            podeFinalizar={pode_finalizar}
                            admanRegisterUrl={adman_register_url}
                            portalClienteUrl={portal_cliente_url}
                            mensagemBoasVindas={mensagem_boas_vindas}
                            contratoAcesso={contrato_acesso}
                        />
                    )}

                    {!checklist && (
                        <div className="rounded-2xl border border-white/[0.08] bg-white/[0.02] text-center py-12 px-6">
                            <p className="text-white/45 text-[13px]">
                                Esta empresa ainda não tem checklist administrativo.
                            </p>
                        </div>
                    )}
                </div>
            </main>
        </AppLayout>
    );
}
