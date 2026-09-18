import { useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import LinhaChecklistItem from '@/Components/ChecklistAdministrativo/LinhaChecklistItem';
import { cn } from '@/lib/utils';

/**
 * CardChecklistAdministrativo — os 9 itens do §5 na ficha da Entrada
 * (Fase 152, ADMIN-01/ADMIN-03/ADMIN-04/ADMIN-05; redesenho de 2026-09-18).
 *
 * Componente REAL, nunca re-export puro (ver `LinhaChecklistItem.jsx`).
 *
 * ### O que mudou no redesenho
 * Era um cartão único, estreito, com nove sub-cartões empilhados e a barra de
 * progresso espremida ao lado do título. Virou duas colunas: à esquerda a
 * SEQUÊNCIA (uma espinha vertical numerada, sem cartão por item), à direita um
 * painel grudento com o estado da entrada — progresso, o passo da vez e o
 * FINALIZAR. Em telas estreitas a segunda coluna volta para cima da primeira,
 * que é onde ela é útil no celular.
 *
 * O ganho não é estética solta: a ficha ocupava um terço da largura disponível
 * e obrigava a rolar até o fim para achar o botão que fecha a entrada. Agora o
 * botão está sempre à vista, e a espinha mostra de relance onde o fluxo parou.
 *
 * ### `ProgressoBarra` não é reusada aqui de propósito
 * Aquele componente é um indicador de LISTA (92px, número de 13px) e existe
 * para a mesma fração não arredondar diferente entre a listagem e o detalhe.
 * O medidor deste painel é um elemento de destaque, com outra escala — mas
 * consome `progresso.percentual` **verbatim do servidor**, sem recalcular nada
 * a partir de `feitos/total`. É exatamente o risco que o docblock daquele
 * componente descreve, e ele continua coberto.
 */

/** Ordem de exibição dos grupos — Contrato antes de Entrada (D-03). */
const ORDEM_GRUPOS = ['contrato', 'entrada'];

export default function CardChecklistAdministrativo({
    checklist,
    companyId,
    podeVerContrato = false,
    podeFinalizar = { permitido: false, requisito_faltante: null },
    admanRegisterUrl = null,
    portalClienteUrl = null,
    mensagemBoasVindas = null,
    contratoAcesso = null,
    emailColaborador = null,
}) {
    const form = useForm({});

    if (!checklist) return null;

    const finalizar = () => {
        form.post(route('admin.contratos.finalizar-entrada', companyId), { preserveScroll: true });
    };

    const grupos = ORDEM_GRUPOS
        .map((chave) => checklist.grupos?.[chave])
        .filter(Boolean)
        // O grupo Contrato depende de DUAS condições, por razões diferentes:
        // ele não existe para empresa isenta (D-07) e não é enviado para quem
        // não tem `admin.contratos` (D-17). A segunda é defesa em
        // profundidade — o servidor já não manda os itens; isto evita bloco
        // vazio confuso caso um dia mande.
        .filter((grupo) => grupo.chave !== 'contrato' || podeVerContrato);

    // A sequência achatada, para numerar os degraus de forma contínua entre os
    // grupos e descobrir qual é o da vez. Nada aqui decide regra de negócio: a
    // trava vem pronta do servidor em `item.bloqueio`, e o "da vez" é só o
    // primeiro item aberto que não está travado.
    const sequencia = grupos.flatMap((grupo) => grupo.itens);
    const chaveAtual = sequencia.find((item) => item.status !== 'concluido' && !item.bloqueio)?.chave ?? null;
    const numeroPorChave = new Map(sequencia.map((item, indice) => [item.chave, indice + 1]));

    // A espinha termina no último item DE CADA GRUPO, não só no último de
    // todos: senão ela descia do "Contrato assinado" por um vão vazio até o
    // título do grupo seguinte, ligando duas coisas que não se ligam.
    const ultimaDoGrupo = new Set(
        grupos.map((grupo) => grupo.itens[grupo.itens.length - 1]?.chave).filter(Boolean)
    );

    const permitido = Boolean(podeFinalizar?.permitido);
    const progresso = checklist.progresso ?? { feitos: 0, total: 0, percentual: 0 };
    const completo = progresso.percentual >= 100;
    const tituloAtual = sequencia.find((item) => item.chave === chaveAtual)?.titulo ?? null;

    return (
        <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_19rem] lg:gap-10">
            {/* ─── A sequência ──────────────────────────────────────────── */}
            <div className="space-y-9">
                {grupos.map((grupo) => (
                    <section key={grupo.chave}>
                        <div className="mb-5 flex items-center gap-4">
                            <h3 className="shrink-0 text-[13px] font-semibold text-white/45">{grupo.titulo}</h3>
                            <span aria-hidden className="h-px flex-1 bg-white/[0.06]" />
                        </div>

                        <ol>
                            {grupo.itens.map((item) => (
                                <LinhaChecklistItem
                                    key={item.chave}
                                    item={item}
                                    numero={numeroPorChave.get(item.chave)}
                                    atual={item.chave === chaveAtual}
                                    ultimo={ultimaDoGrupo.has(item.chave)}
                                    companyId={companyId}
                                    admanRegisterUrl={admanRegisterUrl}
                                    portalClienteUrl={portalClienteUrl}
                                    mensagemBoasVindas={mensagemBoasVindas}
                                    contratoAcesso={contratoAcesso}
                                    emailColaborador={emailColaborador}
                                />
                            ))}
                        </ol>
                    </section>
                ))}
            </div>

            {/* ─── Estado da entrada ────────────────────────────────────── */}
            <aside className="lg:sticky lg:top-6 lg:self-start">
                <div className="rounded-2xl border border-white/[0.07] bg-white/[0.02] p-5">
                    <div className="flex items-end justify-between gap-3">
                        <span
                            className={cn(
                                'font-display text-[2.6rem] font-extrabold leading-none tabular-nums tracking-tight',
                                completo ? 'text-emerald-300' : 'text-white'
                            )}
                        >
                            {progresso.feitos}
                            <span className="text-white/25">/{progresso.total}</span>
                        </span>
                        <span className="pb-1 text-[12px] text-white/35">
                            {completo ? 'tudo concluído' : 'itens concluídos'}
                        </span>
                    </div>

                    <div
                        className="mt-3.5 h-1.5 w-full overflow-hidden rounded-full bg-white/[0.07]"
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

                    {tituloAtual && (
                        <p className="mt-4 border-t border-white/[0.06] pt-4 text-[13px] leading-relaxed text-white/55">
                            Agora: <span className="font-semibold text-white/85">{tituloAtual}</span>
                        </p>
                    )}

                    <div className="mt-5 space-y-2">
                        {/* ADMIN-05 — o `disabled` espelha a régua do SERVIDOR
                            (`pode_finalizar.permitido`). Nunca recalcular a
                            condição aqui a partir de `checklist.progresso`: duas
                            implementações da mesma trava é exatamente o que o
                            ADMIN-05 proíbe, e a que vale é a do servidor, que
                            reavalia no POST. */}
                        <Button className="w-full" onClick={finalizar} disabled={!permitido || form.processing}>
                            Finalizar entrada administrativa
                        </Button>

                        {/* Botão desabilitado nunca fica sozinho sem dizer o que
                            falta — o texto vem pronto do servidor, não é
                            remontado aqui. */}
                        {!permitido && podeFinalizar?.requisito_faltante && (
                            <p className="text-[12px] leading-relaxed text-amber-300/90">
                                {podeFinalizar.requisito_faltante}
                            </p>
                        )}
                    </div>
                </div>
            </aside>
        </div>
    );
}
