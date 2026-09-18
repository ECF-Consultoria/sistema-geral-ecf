import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, FileSignature } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import CardChecklistAdministrativo from '@/Components/ChecklistAdministrativo/CardChecklistAdministrativo';
import { cn } from '@/lib/utils';

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
 * ### O cabeçalho trabalha (18/09, segunda passada)
 * Na primeira versão do redesenho o cabeçalho tinha o nome da empresa à
 * esquerda e um link solitário à direita — metade da largura ocupada por nada,
 * enquanto progresso e FINALIZAR disputavam a coluna lateral com a mensagem de
 * boas-vindas. Agora o cabeçalho carrega o estado da entrada inteiro
 * (fração, medidor, botão e o que falta), e a lateral fica só com a mensagem.
 *
 * O ganho é de leitura, não de enfeite: o cabeçalho é a única faixa que
 * aparece em QUALQUER largura sem rolagem, então é dele o número que resume a
 * tela e a ação que a encerra.
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
    const form = useForm({});

    const etapa = company.etapa ? (ETAPA_LABELS[company.etapa] ?? company.etapa) : null;

    // `percentual` vem PRONTO do servidor e é usado verbatim — nunca
    // recalculado a partir de feitos/total. Duas contas da mesma fração
    // eventualmente arredondam diferente, e aí "44% aqui, 45% ali" vira
    // chamado que ninguém reproduz.
    const progresso = checklist?.progresso ?? { feitos: 0, total: 0, percentual: 0 };
    const completo = progresso.total > 0 && progresso.percentual >= 100;
    const permitido = Boolean(pode_finalizar?.permitido);

    const finalizar = () => {
        form.post(route('admin.contratos.finalizar-entrada', company.id), { preserveScroll: true });
    };

    return (
        <AppLayout title={`Entrada — ${company.name}`}>
            <Head title={`Entrada — ${company.name}`} />

            <main className="mx-auto max-w-[95rem] px-5 py-6 sm:px-8">
                <Link
                    href={route('comercial.entrada.index')}
                    className="inline-flex items-center gap-1.5 text-[12px] text-white/40 transition-colors hover:text-ecf-yellow"
                >
                    <ArrowLeft size={13} /> Voltar à Entrada
                </Link>

                <header className="mt-4 grid gap-x-10 gap-y-6 border-b border-white/[0.07] pb-7 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                    <div className="min-w-0">
                        {/* `break-words`, nunca `truncate`: no celular o nome da
                            empresa virava "DEMO · Entrada com c…" e a ficha
                            deixava de dizer de quem ela é. */}
                        <h1 className="break-words font-display text-[1.7rem] font-extrabold leading-tight tracking-tight text-white sm:text-[2.1rem]">
                            {company.name}
                        </h1>

                        <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-[12.5px]">
                            {company.cnpj && <span className="tabular-nums text-white/40">{company.cnpj}</span>}
                            {etapa && (
                                <span className="rounded-full border border-white/[0.09] bg-white/[0.03] px-2.5 py-0.5 text-white/55">
                                    {etapa}
                                </span>
                            )}

                            {/* O contrato é GERADO na outra ficha — o checklist
                                não gera nada. O link existe para quem marca
                                "Contrato enviado" não ter de procurar onde fica. */}
                            {ficha_contrato_url && (
                                <Link
                                    href={ficha_contrato_url}
                                    className="inline-flex items-center gap-1.5 text-white/45 transition-colors hover:text-ecf-yellow"
                                >
                                    <FileSignature size={13} /> Ficha de contrato
                                </Link>
                            )}
                        </div>
                    </div>

                    {/* Deitado, não empilhado: empilhado este bloco ficava três
                        vezes mais alto que o nome da empresa ao lado, e a
                        diferença virava um vão vazio de ~150px à esquerda —
                        exatamente o desperdício que o redesenho existe para
                        remover. */}
                    {checklist && (
                        <div className="w-full lg:w-[34rem]">
                            <div className="flex flex-wrap items-center gap-x-5 gap-y-3">
                                <span
                                    className={cn(
                                        'font-display text-[2rem] font-extrabold leading-none tabular-nums tracking-tight',
                                        completo ? 'text-emerald-300' : 'text-white'
                                    )}
                                >
                                    {progresso.feitos}
                                    <span className="text-white/25">/{progresso.total}</span>
                                </span>

                                <div className="min-w-[8rem] flex-1">
                                    <div className="mb-1.5 flex items-baseline justify-between gap-2 text-[11.5px]">
                                        <span className="text-white/35">
                                            {completo ? 'tudo concluído' : 'itens concluídos'}
                                        </span>
                                        <span className="tabular-nums text-white/45">{progresso.percentual}%</span>
                                    </div>

                                    <div
                                        className="h-1.5 w-full overflow-hidden rounded-full bg-white/[0.07]"
                                        role="progressbar"
                                        aria-valuenow={progresso.percentual}
                                        aria-valuemin={0}
                                        aria-valuemax={100}
                                        aria-label={`${progresso.feitos} de ${progresso.total} itens concluídos`}
                                    >
                                        <div
                                            className={cn(
                                                'h-full rounded-full transition-[width] duration-500',
                                                completo ? 'bg-emerald-400/80' : 'bg-ecf-yellow'
                                            )}
                                            style={{ width: `${Math.min(100, Math.max(0, progresso.percentual))}%` }}
                                        />
                                    </div>
                                </div>

                                {/* ADMIN-05 — o `disabled` espelha a régua do
                                    SERVIDOR (`pode_finalizar.permitido`). Nunca
                                    recalcular a condição aqui a partir de
                                    `checklist.progresso`: duas implementações da
                                    mesma trava é exatamente o que o ADMIN-05
                                    proíbe, e a que vale é a do servidor, que
                                    reavalia no POST. */}
                                <Button onClick={finalizar} disabled={!permitido || form.processing}>
                                    Finalizar entrada administrativa
                                </Button>
                            </div>

                            {/* Botão desabilitado nunca fica sozinho sem dizer o
                                que falta — o texto vem pronto do servidor. */}
                            {!permitido && pode_finalizar?.requisito_faltante && (
                                <p className="mt-2.5 text-[12px] leading-relaxed text-amber-300/85">
                                    {pode_finalizar.requisito_faltante}
                                </p>
                            )}
                        </div>
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
