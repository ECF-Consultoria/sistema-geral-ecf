import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Wallet } from 'lucide-react';

/**
 * Investimento previsto pelo cliente (§13.1 do fluxo de 19/08).
 *
 * Três campos separados porque são três perspectivas diferentes, que costumam
 * ter respostas diferentes: o que o cliente tem disponível, o objetivo de
 * investimento e o que ele já investiu nos últimos 90 dias.
 *
 * As duas últimas eram "mensal previsto" e "em publicidade" até 23/09/2026. As
 * colunas mantiveram o nome (`investimento_mensal_previsto`,
 * `investimento_publicidade`) — mudou a pergunta, não o lugar onde a resposta
 * mora. Espelho de `CAMPOS_INVESTIMENTO` em `Pages/Onboarding/Publico.jsx`.
 *
 * **Zero é resposta.** Campo vazio significa "ninguém perguntou ainda"; `0`
 * significa "o cliente disse que não vai investir agora". Por isso o input
 * manda string vazia como `null` e nunca converte vazio em zero — os dois
 * estados precisam sobreviver até o banco.
 */
const CAMPOS = [
    ['investimento_disponivel', 'Disponível para investir', 'Quanto o cliente tem hoje para investir'],
    ['investimento_mensal_previsto', 'Objetivo de investimento', 'Quanto o cliente tem como objetivo investir'],
    ['investimento_publicidade', 'Investido nos últimos 90 dias', 'Quanto o cliente investiu nos últimos 90 dias'],
];

const paraNumero = (v) => (v === '' || v === null || v === undefined ? null : Number(v));

export default function BlocoInvestimento({ onboardingId, investimento }) {
    const [dados, setDados] = useState(() => ({
        investimento_disponivel: investimento?.investimento_disponivel ?? '',
        investimento_mensal_previsto: investimento?.investimento_mensal_previsto ?? '',
        investimento_publicidade: investimento?.investimento_publicidade ?? '',
        observacoes: investimento?.observacoes ?? '',
    }));
    const [enviando, setEnviando] = useState(false);

    const salvar = () => {
        setEnviando(true);
        router.put(
            route('onboarding.investimento.salvar', onboardingId),
            {
                investimento_disponivel: paraNumero(dados.investimento_disponivel),
                investimento_mensal_previsto: paraNumero(dados.investimento_mensal_previsto),
                investimento_publicidade: paraNumero(dados.investimento_publicidade),
                observacoes: dados.observacoes || null,
            },
            { preserveScroll: true, onFinish: () => setEnviando(false) }
        );
    };

    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4 space-y-3">
            <div className="flex items-center gap-2">
                <Wallet size={15} className="text-ecf-yellow/70" />
                <h3 className="text-[13px] font-semibold text-white/80">Investimento do cliente</h3>
            </div>

            <div className="grid gap-3 sm:grid-cols-3">
                {CAMPOS.map(([chave, rotulo, ajuda]) => (
                    <div key={chave}>
                        <label className="block text-[11px] text-white/50 mb-1" title={ajuda}>
                            {rotulo}
                        </label>
                        <div className="flex items-center gap-1.5">
                            <span className="text-[12px] text-white/35">R$</span>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                value={dados[chave]}
                                onChange={(e) => setDados((d) => ({ ...d, [chave]: e.target.value }))}
                                placeholder="—"
                                className="w-full rounded-lg border border-white/[0.08] bg-white/[0.03] px-2.5 py-1.5 text-[13px] text-white/85 placeholder:text-white/20 focus:outline-none focus:border-ecf-yellow/40"
                            />
                        </div>
                    </div>
                ))}
            </div>

            <textarea
                value={dados.observacoes}
                onChange={(e) => setDados((d) => ({ ...d, observacoes: e.target.value }))}
                rows={2}
                placeholder="Observações — condições, sazonalidade, o que o cliente condicionou..."
                className="w-full rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-2 text-[12px] text-white/80 placeholder:text-white/25 focus:outline-none focus:border-ecf-yellow/40"
            />

            <div className="flex items-center gap-2">
                <button
                    type="button"
                    onClick={salvar}
                    disabled={enviando}
                    className="rounded-lg border border-ecf-yellow/40 bg-ecf-yellow/15 px-3 py-1.5 text-[12px] font-semibold text-ecf-yellow disabled:opacity-50"
                >
                    {enviando ? 'Salvando...' : 'Salvar investimento'}
                </button>
                <span className="text-[11px] text-white/30">
                    Deixe vazio o que ainda não foi perguntado. Zero é uma resposta válida.
                </span>
            </div>
        </div>
    );
}
