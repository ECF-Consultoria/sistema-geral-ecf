import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, X, MessageSquare } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Resposta Sim / Não de um item de confirmação (§17 e §18 do fluxo de 19/08).
 *
 * Três estados na tela, não dois: **Sim**, **Não** e *sem resposta*. O terceiro
 * é o "Pendente" do documento — não é um botão, é a ausência dos outros dois.
 *
 * "Não" é resposta GRAVADA, e o item continua aberto de propósito: dizer que a
 * publicidade não foi explicada é informação, não silêncio. É por isso que a
 * resposta mora em tabela própria e não no status do passo — o status
 * `nao_aplicavel` conta como resolvido e faria o onboarding se dar por
 * concluído com a publicidade nunca explicada.
 */
export default function RespostaConfirmacao({ onboardingId, chave, resposta, observacoes }) {
    const [abrindoObs, setAbrindoObs] = useState(false);
    const [texto, setTexto] = useState(observacoes || '');
    const [enviando, setEnviando] = useState(false);

    const responder = (valor, obs = texto) => {
        setEnviando(true);
        router.post(
            route('onboarding.confirmacao.responder', onboardingId),
            { chave, resposta: valor, observacoes: obs || null },
            {
                preserveScroll: true,
                onFinish: () => {
                    setEnviando(false);
                    setAbrindoObs(false);
                },
            }
        );
    };

    const botao = (valor, Icone, rotulo, tomAtivo) => (
        <button
            type="button"
            disabled={enviando}
            onClick={() => responder(valor)}
            className={cn(
                'inline-flex items-center gap-1 rounded-md border px-2 py-1 text-[11px] font-semibold transition-colors disabled:opacity-50',
                resposta === valor
                    ? tomAtivo
                    : 'border-white/[0.08] bg-white/[0.02] text-white/45 hover:text-white/80 hover:border-white/20'
            )}
        >
            <Icone size={11} />
            {rotulo}
        </button>
    );

    return (
        <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
            {botao('sim', Check, 'Sim', 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300')}
            {botao('nao', X, 'Não', 'border-red-500/30 bg-red-500/10 text-red-300')}

            <button
                type="button"
                onClick={() => setAbrindoObs((v) => !v)}
                title="Observação"
                className={cn(
                    'inline-flex items-center gap-1 rounded-md border px-2 py-1 text-[11px] transition-colors',
                    observacoes
                        ? 'border-white/20 bg-white/[0.05] text-white/70'
                        : 'border-white/[0.08] bg-white/[0.02] text-white/35 hover:text-white/70'
                )}
            >
                <MessageSquare size={11} />
                {observacoes ? 'Com observação' : 'Observação'}
            </button>

            {!resposta && (
                <span className="text-[11px] text-white/30">Pendente</span>
            )}

            {abrindoObs && (
                <div className="w-full mt-1.5 flex flex-col gap-1.5">
                    <textarea
                        value={texto}
                        onChange={(e) => setTexto(e.target.value)}
                        rows={2}
                        placeholder="O que ficou combinado, o que o cliente questionou..."
                        className="w-full rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[12px] text-white/80 placeholder:text-white/25 focus:outline-none focus:border-ecf-yellow/40"
                    />
                    <div className="flex gap-1.5">
                        <button
                            type="button"
                            disabled={enviando || !resposta}
                            onClick={() => responder(resposta, texto)}
                            title={resposta ? '' : 'Responda Sim ou Não antes de salvar a observação'}
                            className="rounded-md border border-ecf-yellow/40 bg-ecf-yellow/15 px-2 py-1 text-[11px] font-semibold text-ecf-yellow disabled:opacity-40"
                        >
                            Salvar observação
                        </button>
                        <button
                            type="button"
                            onClick={() => { setTexto(observacoes || ''); setAbrindoObs(false); }}
                            className="rounded-md border border-white/[0.08] px-2 py-1 text-[11px] text-white/50"
                        >
                            Cancelar
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
