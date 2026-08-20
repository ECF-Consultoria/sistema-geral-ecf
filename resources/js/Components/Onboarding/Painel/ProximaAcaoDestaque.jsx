import { ArrowRight, BellRing, CheckCircle2, Clock, Eye, EyeOff } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import DonoBadge from './DonoBadge';
import NaturezaBadge from './NaturezaBadge';
import { ACOES } from './ProximaAcao';
import { tempoRelativo } from './tempoRelativo';

/**
 * ProximaAcaoDestaque — "o que eu faço agora?", no alto da tela.
 *
 * ### Por que ele existe
 * O passo que trava já era calculado e já aparecia — diluído como mais uma
 * linha dentro da etapa corrente. Para achá-lo era preciso varrer o fluxo e
 * comparar estados, que é justamente o trabalho que a tela deveria poupar.
 *
 * ### Ele reusa os rótulos da listagem de propósito
 * `ACOES` vem de `ProximaAcao` (a coluna do cockpit de /companies). Se aqui
 * dissesse "Pendente com o cliente" e lá "Aguardando cliente", seriam duas
 * linguagens para o mesmo estado, e a pessoa que navega da lista para o
 * detalhe precisaria traduzir mentalmente a cada clique.
 *
 * ### O MOTIVO, não o rótulo
 * "Não enviado" não ajuda ninguém. O que ajuda é por que não foi: de qual
 * passo este depende, sob qual condição ele se aplica, há quantos dias está
 * parado e — quando a bola é do cliente — se ele chegou a abrir o portal.
 * "Aberto há 3 dias e não respondeu" e "nunca abriu o link" pedem ações
 * opostas, e sem essa distinção o time cobra os dois do mesmo jeito.
 */
function motivoReal(passo, ultimoAcessoCliente) {
    if (passo.depende_de?.length > 0) {
        return `Depende de: ${passo.depende_de.join(', ')}.`;
    }

    if (passo.condicao) {
        return `Só se aplica quando: ${passo.condicao}.`;
    }

    if (passo.dono === 'cliente') {
        const visto = tempoRelativo(ultimoAcessoCliente);

        return visto
            ? `Pedido publicado no portal do cliente. Ele abriu ${visto} e ainda não respondeu.`
            : 'Pedido publicado no portal do cliente — que ainda não foi aberto nenhuma vez.';
    }

    if (passo.dono === 'sistema') {
        return 'O sistema busca este dado sozinho assim que os acessos permitirem.';
    }

    return 'A bola está com a ECF — nada trava do lado do cliente.';
}

/** Estado de "acabou": ocupa o mesmo lugar para a tela não perder o eixo. */
function Concluido() {
    return (
        <section className="rounded-2xl border border-emerald-500/20 bg-emerald-500/[0.05] p-5 flex items-start gap-4">
            <span className="grid place-items-center h-11 w-11 rounded-xl border border-emerald-500/25 bg-emerald-500/10 shrink-0">
                <CheckCircle2 size={20} className="text-emerald-300" />
            </span>
            <div className="min-w-0">
                <h3 className="text-emerald-300 font-display font-bold text-[15px]">Onboarding concluído</h3>
                <p className="text-white/50 text-[13px] mt-1">
                    Todos os passos foram fechados. A empresa está pronta para operar.
                </p>
            </div>
        </section>
    );
}

/** Sem passo aberto e sem conclusão: coleta rodando ou tudo em espera. */
function SemPendenciaHumana({ situacao, situacaoLabel }) {
    const acao = ACOES[situacao] ?? ACOES.coletando;

    return (
        <section className="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5 flex items-start gap-4">
            <span className="grid place-items-center h-11 w-11 rounded-xl border border-white/[0.08] bg-white/[0.03] shrink-0">
                <Clock size={20} className="text-white/40" />
            </span>
            <div className="min-w-0">
                <h3 className="text-white font-display font-bold text-[15px]">{situacaoLabel ?? acao.rotulo}</h3>
                <p className="text-white/50 text-[13px] mt-1">
                    Nenhuma pendência humana agora — não há o que cobrar de ninguém neste momento.
                </p>
            </div>
        </section>
    );
}

export default function ProximaAcaoDestaque({
    situacao,
    situacaoLabel,
    passo = null,
    ultimoAcessoCliente = null,
    aoVerPendencia,
}) {
    if (situacao === 'concluido') return <Concluido />;
    if (!passo) return <SemPendenciaHumana situacao={situacao} situacaoLabel={situacaoLabel} />;

    const acao = ACOES[situacao] ?? ACOES.aguardando_interno;
    const vencido = passo.vencido;
    const clienteNuncaAbriu = passo.dono === 'cliente' && !ultimoAcessoCliente;

    return (
        <section
            className={cn(
                'rounded-2xl border p-5 flex items-start gap-4',
                vencido
                    ? 'border-red-500/25 bg-red-500/[0.05]'
                    : 'border-ecf-yellow/25 bg-ecf-yellow/[0.04]'
            )}
        >
            <span
                className={cn(
                    'grid place-items-center h-11 w-11 rounded-xl border shrink-0',
                    vencido
                        ? 'border-red-500/25 bg-red-500/10'
                        : 'border-ecf-yellow/25 bg-ecf-yellow/10'
                )}
            >
                <BellRing size={20} className={vencido ? 'text-red-300' : 'text-ecf-yellow'} />
            </span>

            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-[11px] font-semibold uppercase tracking-wider text-white/35">
                        Próxima ação
                    </span>
                    {/* Rótulo do estado em TEXTO, não só pela cor da borda. */}
                    <span className={cn('text-[12px] font-semibold', acao.texto)}>{acao.rotulo}</span>
                </div>

                <h3 className="text-white font-display font-bold text-[16px] mt-1.5">{passo.titulo}</h3>

                <p className="text-white/55 text-[13px] mt-1.5 leading-relaxed">
                    {motivoReal(passo, ultimoAcessoCliente)}
                </p>

                <div className="flex items-center gap-2 flex-wrap mt-3">
                    <DonoBadge dono={passo.dono} setor={passo.setor} />
                    <NaturezaBadge natureza={passo.natureza} />

                    {passo.dias_parado !== null && passo.dias_parado !== undefined && (
                        <span
                            className={cn(
                                'inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[11px] font-semibold',
                                vencido
                                    ? 'text-red-300 border-red-500/25 bg-red-500/10'
                                    : 'text-white/45 border-white/[0.08] bg-white/[0.03]'
                            )}
                        >
                            <Clock size={11} />
                            parado há {passo.dias_parado}d
                            {passo.sla_dias ? ` · prazo ${passo.sla_dias}d` : ''}
                        </span>
                    )}

                    {/* Sinal operacional forte: cobrar quem nunca recebeu o
                        link é o erro que este selo evita. */}
                    {passo.dono === 'cliente' && (
                        <span
                            className={cn(
                                'inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[11px] font-semibold',
                                clienteNuncaAbriu
                                    ? 'text-amber-300 border-amber-500/25 bg-amber-500/10'
                                    : 'text-white/45 border-white/[0.08] bg-white/[0.03]'
                            )}
                        >
                            {clienteNuncaAbriu ? <EyeOff size={11} /> : <Eye size={11} />}
                            {clienteNuncaAbriu ? 'portal nunca aberto' : `portal visto ${tempoRelativo(ultimoAcessoCliente)}`}
                        </span>
                    )}
                </div>
            </div>

            <Button
                size="sm"
                className="shrink-0 mt-1"
                onClick={() => aoVerPendencia?.(passo)}
            >
                Ver pendência <ArrowRight size={14} className="ml-1.5" />
            </Button>
        </section>
    );
}
