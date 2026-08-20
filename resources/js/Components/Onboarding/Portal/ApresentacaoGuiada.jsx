import { useState } from 'react';
import { router } from '@inertiajs/react';
import { CheckCircle2, Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * ApresentacaoGuiada — publicidade e ADMAN explicados ao cliente, com um único
 * "Entendi" no fim.
 *
 * ### Por que não é uma lista de checkboxes
 * Estes itens viraram `dono=cliente` na v15, mas continuam sendo EXPLICAÇÃO,
 * não tarefa. Renderizá-los como quatro cards com "marcar como feito" pediria
 * ao cliente que confirmasse quatro vezes ter entendido — e transformaria uma
 * leitura de dois minutos num formulário. O negócio pediu o contrário: uma
 * apresentação curta e um botão só.
 *
 * ### Um POST, N chaves
 * O botão manda todas as chaves da seção de uma vez
 * (`onboarding.publico.confirmacao`). O backend fecha só o que for
 * `dono=cliente` + `auto_fonte=confirmacao_respondida` desta empresa — a lista
 * que sai daqui é sugestão, nunca autorização.
 *
 * ### O estado depois do clique é parte da entrega
 * "Será que foi enviado?" é a dúvida que faz o cliente clicar de novo, ou
 * ligar. Por isso a seção mostra `enviando` → `sincronizado`, e o estado final
 * fica na tela em vez de a seção simplesmente sumir.
 */
export default function ApresentacaoGuiada({ titulo, ajuda, itens, token, ctaLabel = 'Entendi como funciona' }) {
    const [enviando, setEnviando] = useState(false);
    // `true` só depois de um envio feito NESTA sessão — é o que autoriza a
    // frase "acabou de ser enviado". Quem já tinha confirmado antes cai no
    // estado `concluido`, que não promete recência nenhuma.
    const [acabouDeEnviar, setAcabouDeEnviar] = useState(false);

    const pendentes = itens.filter((i) => i.status !== 'concluido');
    const concluido = pendentes.length === 0;

    const confirmar = () => {
        if (enviando || concluido) return;

        setEnviando(true);
        router.post(
            route('onboarding.publico.confirmacao', token),
            { chaves: pendentes.map((i) => i.chave) },
            {
                preserveScroll: true,
                onSuccess: () => setAcabouDeEnviar(true),
                onFinish: () => setEnviando(false),
            }
        );
    };

    return (
        <div
            className={cn(
                'rounded-2xl border p-5',
                concluido ? 'border-emerald-500/20 bg-emerald-500/[0.05]' : 'border-white/[0.10] bg-white/[0.03]'
            )}
        >
            <h3 className="text-white font-display font-bold text-[15px]">{titulo}</h3>
            {ajuda && <p className="text-white/45 text-[13px] mt-1">{ajuda}</p>}

            <ol className="mt-4 space-y-4">
                {itens.map((item, i) => (
                    <li key={item.chave} className="flex gap-3">
                        <span
                            aria-hidden="true"
                            className={cn(
                                'grid place-items-center h-6 w-6 shrink-0 rounded-lg text-[11px] font-bold',
                                concluido
                                    ? 'bg-emerald-500/15 text-emerald-300'
                                    : 'border border-white/[0.08] bg-white/[0.03] text-white/50'
                            )}
                        >
                            {i + 1}
                        </span>
                        <div className="min-w-0">
                            <p className="text-white text-[13px] font-semibold">{item.titulo_cliente ?? item.titulo}</p>
                            {item.instrucao && (
                                <p className="text-white/50 text-[13px] mt-1 leading-relaxed">{item.instrucao}</p>
                            )}
                        </div>
                    </li>
                ))}
            </ol>

            <div className="mt-5 flex items-center gap-3 flex-wrap">
                {concluido ? (
                    <span className="inline-flex items-center gap-2 text-[13px] font-semibold text-emerald-300">
                        <CheckCircle2 size={16} />
                        {acabouDeEnviar ? 'Informação sincronizada com a ECF' : 'Você já confirmou esta explicação'}
                    </span>
                ) : (
                    <button
                        type="button"
                        onClick={confirmar}
                        disabled={enviando}
                        className="inline-flex items-center gap-2 rounded-xl bg-ecf-yellow px-4 py-2.5 text-[13px] font-bold text-ecf-bg transition-opacity hover:opacity-90 disabled:opacity-50"
                    >
                        {enviando && <Loader2 size={15} className="animate-spin" />}
                        {enviando ? 'Enviando…' : ctaLabel}
                    </button>
                )}
            </div>

            {!concluido && (
                <p className="text-white/25 text-[11px] mt-2">
                    Ao confirmar, avisamos a equipe de que você já viu esta explicação.
                </p>
            )}
        </div>
    );
}
