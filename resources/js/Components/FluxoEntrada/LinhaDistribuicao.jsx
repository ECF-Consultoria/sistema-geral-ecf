import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Info } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * LinhaDistribuicao — uma empresa da fila, com os dois seletores e o confirmar
 * (Fase 154, movida para `/companies` na Fase 157).
 *
 * Componente COMPARTILHADO de propósito: a mesma linha serve a aba Distribuição
 * de `/companies` (o líder) e a tela `/coordenacao/distribuicao`. Duas cópias
 * divergiriam — e é exatamente a duplicação que a D-D quis evitar ao tirar a
 * segunda tela do menu.
 *
 * Componente REAL, nunca re-export puro.
 */

/**
 * Select nativo, não Radix: `value=""` em Radix Select deixa a tela PRETA
 * (`project_radix_select_empty_value`), e o estado inicial aqui é justamente
 * "nada escolhido".
 */
function SelectPessoa({ label, valor, aoMudar, opcoes, disabled }) {
    return (
        <label className="block">
            <span className="text-[11px] uppercase tracking-wide text-white/40">{label}</span>
            <select
                value={valor}
                onChange={(e) => aoMudar(e.target.value)}
                disabled={disabled || opcoes.length === 0}
                className={cn(
                    'mt-1 w-full h-9 px-3 rounded-lg border border-white/10 bg-white/[0.03]',
                    'text-[13px] text-white focus:outline-none focus:border-ecf-yellow/40',
                    'disabled:opacity-50'
                )}
            >
                <option value="" className="bg-[#0f1116]">
                    {opcoes.length === 0 ? 'Ninguém habilitado' : 'Selecione…'}
                </option>
                {opcoes.map((o) => (
                    <option key={o.id} value={o.id} className="bg-[#0f1116]">
                        {o.name}
                    </option>
                ))}
            </select>
        </label>
    );
}

export default function LinhaDistribuicao({ empresa }) {
    const [analista, setAnalista] = useState('');
    const [estrategista, setEstrategista] = useState('');
    const [enviando, setEnviando] = useState(false);

    const podeConfirmar = analista !== '' && estrategista !== '' && !enviando;

    const confirmar = () => {
        setEnviando(true);
        router.post(
            route('coordenacao.distribuicao.distribuir', empresa.id),
            { analista_id: analista, estrategista_id: estrategista },
            { preserveScroll: true, onFinish: () => setEnviando(false) }
        );
    };

    return (
        <div className="rounded-xl border border-white/[0.06] bg-white/[0.015] p-4 space-y-3">
            <div>
                <h3 className="text-white font-semibold text-[14px]">{empresa.name}</h3>
                <p className="text-[12px] text-white/40">
                    {empresa.cnpj ?? 'sem CNPJ'}
                    {empresa.servicos?.length > 0 && ` · ${empresa.servicos.join(', ')}`}
                </p>
            </div>

            {/* A lista foi ABERTA para todos os setores — dizer por quê é
                obrigatório: select que muda de universo em silêncio faz o líder
                achar que aquele é o time daquele serviço. */}
            {empresa.motivo_abertura && (
                <div className="flex items-start gap-2 rounded-lg border border-amber-500/20 bg-amber-500/[0.07] px-3 py-2">
                    <Info size={13} className="text-amber-300 shrink-0 mt-0.5" />
                    <p className="text-[12px] text-amber-300/90">{empresa.motivo_abertura}</p>
                </div>
            )}

            <div className="grid gap-3 sm:grid-cols-2">
                <SelectPessoa
                    label="Analista"
                    valor={analista}
                    aoMudar={setAnalista}
                    opcoes={empresa.analistas ?? []}
                    disabled={enviando}
                />
                <SelectPessoa
                    label="Estrategista"
                    valor={estrategista}
                    aoMudar={setEstrategista}
                    opcoes={empresa.estrategistas ?? []}
                    disabled={enviando}
                />
            </div>

            <div className="flex items-center justify-between gap-3 flex-wrap pt-1">
                <p className="text-[11px] text-white/30">
                    Confirmar vincula os dois em todos os serviços ativos e move a empresa para
                    Aguardando Onboarding.
                </p>
                <Button size="sm" onClick={confirmar} disabled={!podeConfirmar}>
                    {enviando ? 'Distribuindo…' : 'Confirmar distribuição'}
                </Button>
            </div>
        </div>
    );
}
