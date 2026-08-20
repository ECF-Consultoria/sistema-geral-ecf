import { ArrowRight, CheckCircle2, Clock } from 'lucide-react';

/**
 * ProximaAcaoCliente — "o que eu preciso fazer agora?", no alto do portal.
 *
 * ### O recorte do cliente é diferente do interno
 * O cockpit interno mostra o passo que TRAVA o processo, seja de quem for —
 * cliente, ECF ou sistema. Aqui só entra o que depende do cliente: se a bola
 * está com a ECF, o portal não pode pedir nada, porque não há nada que ele
 * possa fazer. A lista que chega já vem filtrada por `dono=cliente`
 * (`OnboardingLinkService::passosDoCliente()`) — este componente não filtra
 * nada, só escolhe o primeiro acionável.
 *
 * ### "Acionável" exclui bloqueado de propósito
 * Um passo `bloqueado` espera outro passo. Colocá-lo aqui mandaria o cliente
 * para um card que ele não consegue mexer — o oposto do que este bloco existe
 * para fazer.
 */
export default function ProximaAcaoCliente({ passo = null, totalPendentes = 0, aoIr }) {
    if (!passo) {
        return (
            <section className="rounded-2xl border border-emerald-500/20 bg-emerald-500/[0.05] p-5 flex items-start gap-4">
                <span className="grid place-items-center h-11 w-11 rounded-xl border border-emerald-500/25 bg-emerald-500/10 shrink-0">
                    <CheckCircle2 size={20} className="text-emerald-300" />
                </span>
                <div className="min-w-0">
                    <h2 className="text-emerald-300 font-display font-bold text-[15px]">
                        Nada pendente da sua parte
                    </h2>
                    <p className="text-white/50 text-[13px] mt-1">
                        Recebemos tudo o que precisávamos. Nossa equipe segue com as próximas etapas
                        e avisa você quando houver novidade.
                    </p>
                </div>
            </section>
        );
    }

    return (
        <section className="rounded-2xl border border-ecf-yellow/25 bg-ecf-yellow/[0.04] p-5">
            <div className="flex items-start gap-4">
                <span className="grid place-items-center h-11 w-11 rounded-xl border border-ecf-yellow/25 bg-ecf-yellow/10 shrink-0">
                    <Clock size={20} className="text-ecf-yellow" />
                </span>

                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                        <span className="text-[11px] font-semibold uppercase tracking-wider text-white/35">
                            Próxima ação
                        </span>
                        {/* Rótulo em TEXTO, não só a cor da borda. */}
                        <span className="inline-flex items-center rounded-full border border-ecf-yellow/30 bg-ecf-yellow/10 px-2 py-0.5 text-[11px] font-semibold text-ecf-yellow">
                            Aguardando você
                        </span>
                    </div>

                    <h2 className="text-white font-display font-bold text-[16px] mt-1.5">
                        {passo.titulo}
                    </h2>

                    {passo.instrucao && (
                        <p className="text-white/55 text-[13px] mt-1.5 leading-relaxed">
                            {passo.instrucao}
                        </p>
                    )}

                    {/* O total contextualiza sem expor a divisão interna (§10):
                        o cliente não precisa saber quantas pendências são da
                        ECF, do sistema ou da reunião — só quantas são dele. */}
                    {totalPendentes > 1 && (
                        <p className="text-white/30 text-[12px] mt-2">
                            Você tem {totalPendentes} ações para concluir.
                        </p>
                    )}
                </div>

                <button
                    type="button"
                    onClick={() => aoIr?.(passo)}
                    className="shrink-0 inline-flex items-center gap-1.5 rounded-xl bg-ecf-yellow px-4 py-2.5 text-[13px] font-bold text-ecf-bg transition-opacity hover:opacity-90"
                >
                    Preencher agora <ArrowRight size={15} />
                </button>
            </div>
        </section>
    );
}
