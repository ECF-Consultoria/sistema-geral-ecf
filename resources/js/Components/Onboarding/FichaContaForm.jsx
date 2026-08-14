import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Formulário da ficha da conta — as 7 informações de "Métricas e situação da
 * conta". Um componente só para as DUAS portas:
 *   - portal público  → POST onboarding.publico.ficha-conta (token)
 *   - painel interno  → POST onboarding.ficha-conta.salvar (empresa)
 * O que muda é a `submitUrl`; o formulário e a validação são os mesmos, senão
 * o declarado pelo cliente e o declarado pela equipe deixariam de ser
 * comparáveis.
 *
 * A decisão de UI que carrega a regra: as perguntas de sim/não têm TRÊS
 * opções. "Não sei" precisa existir e ser diferente de "Não" — no banco vira
 * `null` contra `false`, e é isso que impede o sistema de afirmar que a conta
 * não tem Full quando na verdade ninguém respondeu.
 */

const TRI_ESTADO = [
    { valor: true, rotulo: 'Sim' },
    { valor: false, rotulo: 'Não' },
    { valor: null, rotulo: 'Não sei' },
];

function Rotulo({ children, dica }) {
    return (
        <div className="mb-1.5">
            <label className="text-white/80 text-[13px] font-semibold">{children}</label>
            {dica && <p className="text-white/35 text-[11px] mt-0.5">{dica}</p>}
        </div>
    );
}

function CampoTexto({ label, dica, valor, onChange, erro, ...props }) {
    return (
        <div>
            <Rotulo dica={dica}>{label}</Rotulo>
            <input
                value={valor ?? ''}
                onChange={(e) => onChange(e.target.value === '' ? null : e.target.value)}
                className="w-full rounded-lg bg-white/[0.04] border border-white/[0.08] px-3 py-2 text-white text-[13px] placeholder:text-white/25 focus:outline-none focus:border-ecf-yellow/50"
                {...props}
            />
            {erro && <p className="text-red-400 text-[11px] mt-1">{erro}</p>}
        </div>
    );
}

function CampoTriEstado({ label, dica, valor, onChange, erro }) {
    return (
        <div>
            <Rotulo dica={dica}>{label}</Rotulo>
            <div className="flex gap-1.5">
                {TRI_ESTADO.map((opcao) => {
                    const ativo = valor === opcao.valor;
                    return (
                        <button
                            key={String(opcao.valor)}
                            type="button"
                            onClick={() => onChange(opcao.valor)}
                            className={cn(
                                'flex-1 rounded-lg border px-3 py-2 text-[12px] font-semibold transition-all',
                                ativo
                                    ? 'bg-ecf-yellow text-ecf-bg border-ecf-yellow'
                                    : 'bg-white/[0.03] text-white/60 border-white/[0.08] hover:border-white/20',
                            )}
                        >
                            {opcao.rotulo}
                        </button>
                    );
                })}
            </div>
            {erro && <p className="text-red-400 text-[11px] mt-1">{erro}</p>}
        </div>
    );
}

export default function FichaContaForm({ submitUrl, respostas = {}, respondidas = 0, totalPerguntas = 7, preenchidaEm = null, titulo = 'Ficha da conta', descricao = null }) {
    const { errors } = usePage().props;
    const [enviando, setEnviando] = useState(false);
    const [dados, setDados] = useState({
        faturamento_3_meses: respostas.faturamento_3_meses ?? null,
        marketplace: respostas.marketplace ?? null,
        full_ativo: respostas.full_ativo ?? null,
        full_pontuacao: respostas.full_pontuacao ?? null,
        reputacao_verde: respostas.reputacao_verde ?? null,
        medalha_atual: respostas.medalha_atual ?? null,
        objetivos_proxima_medalha: respostas.objetivos_proxima_medalha ?? null,
    });

    const set = (campo) => (valor) => setDados((d) => ({ ...d, [campo]: valor }));

    function enviar(e) {
        e.preventDefault();
        setEnviando(true);
        router.post(submitUrl, dados, {
            preserveScroll: true,
            onFinish: () => setEnviando(false),
        });
    }

    return (
        <form onSubmit={enviar} className="rounded-2xl border border-white/[0.08] bg-ecf-card p-4 space-y-4">
            <div className="flex items-start justify-between gap-4 flex-wrap">
                <div className="min-w-0">
                    <p className="text-white font-semibold text-[14px]">{titulo}</p>
                    <p className="text-white/40 text-[12px] mt-0.5">
                        {descricao ?? 'Responda o que souber. Deixar "Não sei" é melhor que chutar — a equipe confere depois.'}
                    </p>
                </div>
                {preenchidaEm && (
                    <span className="shrink-0 inline-flex items-center gap-1.5 text-[11px] text-white/45">
                        <Check size={13} className="text-emerald-400" />
                        {respondidas} de {totalPerguntas} respondidas
                    </span>
                )}
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <CampoTexto
                    label="Faturamento dos últimos 3 meses"
                    dica="Valor total em reais, somando os 3 meses."
                    type="number"
                    step="0.01"
                    min="0"
                    placeholder="Ex.: 150000"
                    valor={dados.faturamento_3_meses}
                    onChange={set('faturamento_3_meses')}
                    erro={errors?.faturamento_3_meses}
                />

                <CampoTexto
                    label="Marketplace da conta"
                    dica="Onde a conta vende hoje."
                    placeholder="Ex.: Mercado Livre"
                    valor={dados.marketplace}
                    onChange={set('marketplace')}
                    erro={errors?.marketplace}
                />

                <CampoTriEstado
                    label="Possui Full ativo?"
                    valor={dados.full_ativo}
                    onChange={set('full_ativo')}
                    erro={errors?.full_ativo}
                />

                <CampoTexto
                    label="Pontuação atual do Full"
                    dica="Se não usa Full ou não sabe, deixe em branco."
                    type="number"
                    min="0"
                    placeholder="Ex.: 87"
                    valor={dados.full_pontuacao}
                    onChange={set('full_pontuacao')}
                    erro={errors?.full_pontuacao}
                />

                <CampoTriEstado
                    label="Possui reputação verde?"
                    valor={dados.reputacao_verde}
                    onChange={set('reputacao_verde')}
                    erro={errors?.reputacao_verde}
                />

                <CampoTexto
                    label="Medalha atual da conta"
                    placeholder="Ex.: Prata"
                    valor={dados.medalha_atual}
                    onChange={set('medalha_atual')}
                    erro={errors?.medalha_atual}
                />
            </div>

            <div>
                <Rotulo dica="O que falta para a conta subir de medalha.">
                    Objetivos para alcançar a próxima medalha
                </Rotulo>
                <textarea
                    rows={3}
                    value={dados.objetivos_proxima_medalha ?? ''}
                    onChange={(e) => set('objetivos_proxima_medalha')(e.target.value === '' ? null : e.target.value)}
                    placeholder="Ex.: aumentar faturamento e reduzir cancelamentos."
                    className="w-full rounded-lg bg-white/[0.04] border border-white/[0.08] px-3 py-2 text-white text-[13px] placeholder:text-white/25 focus:outline-none focus:border-ecf-yellow/50 resize-y"
                />
                {errors?.objetivos_proxima_medalha && (
                    <p className="text-red-400 text-[11px] mt-1">{errors.objetivos_proxima_medalha}</p>
                )}
            </div>

            <div className="flex justify-end">
                <button
                    type="submit"
                    disabled={enviando}
                    className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-ecf-yellow text-ecf-bg hover:bg-ecf-yellow/90 disabled:opacity-50 text-[12px] font-semibold transition-all"
                >
                    {enviando ? 'Salvando…' : preenchidaEm ? 'Atualizar ficha' : 'Enviar ficha'}
                </button>
            </div>
        </form>
    );
}
