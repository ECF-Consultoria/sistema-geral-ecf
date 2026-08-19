import { useState } from 'react';
import { ChevronDown, ChevronRight, Quote } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * O que o Comercial já coletou na venda — SPIN e contexto — dentro da tela do
 * onboarding.
 *
 * Existe por causa do §3 do documento de 19/08: "não deverá existir
 * necessidade de preencher novamente informações que já foram coletadas
 * durante a venda". Dois dos itens do checklist novo são "revisar SPIN" e
 * "revisar contexto" — pedir isso com o SPIN em outra tela é o mesmo que pedir
 * de novo.
 *
 * Não guarda nada: lê `hubspot_snapshot` por accessor. Empresa sem deal no
 * HubSpot não renderiza o bloco em vez de mostrar quatro traços.
 */
const CAMPOS_SPIN = [
    ['situacao', 'Situação', 'Como o cliente está hoje'],
    ['problema', 'Problema', 'O que ele identificou como dor'],
    ['implicacao', 'Implicação', 'O que essa dor está custando'],
    ['necessidade', 'Necessidade', 'O que ele espera da solução'],
];

export default function ContextoDaVenda({ spin, contexto }) {
    const [aberto, setAberto] = useState(false);

    const preenchidos = CAMPOS_SPIN.filter(([chave]) => spin?.[chave]);
    const temContexto = Boolean(contexto);

    // Nada veio do Comercial: some, em vez de virar um card de traços.
    if (preenchidos.length === 0 && !temContexto) return null;

    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02]">
            <button
                onClick={() => setAberto((v) => !v)}
                className="w-full flex items-center gap-2 px-4 py-3 text-left"
            >
                {aberto ? (
                    <ChevronDown size={15} className="text-white/40 shrink-0" />
                ) : (
                    <ChevronRight size={15} className="text-white/40 shrink-0" />
                )}
                <Quote size={14} className="text-ecf-yellow/60 shrink-0" />
                <span className="text-[13px] font-semibold text-white/80">
                    O que veio da venda
                </span>
                <span className="text-[11px] text-white/35">
                    {preenchidos.length > 0 && `SPIN (${preenchidos.length}/4)`}
                    {preenchidos.length > 0 && temContexto && ' · '}
                    {temContexto && 'contexto'}
                </span>
            </button>

            {aberto && (
                <div className="px-4 pb-4 space-y-3 border-t border-white/[0.06] pt-3">
                    {temContexto && (
                        <div>
                            <div className="text-[11px] uppercase tracking-wide text-white/35 mb-1">
                                Contexto
                            </div>
                            <p className="text-[13px] text-white/70 whitespace-pre-line">{contexto}</p>
                        </div>
                    )}

                    {preenchidos.length > 0 && (
                        <div className="grid gap-3 sm:grid-cols-2">
                            {preenchidos.map(([chave, rotulo, ajuda]) => (
                                <div key={chave}>
                                    <div
                                        className="text-[11px] uppercase tracking-wide text-white/35 mb-1"
                                        title={ajuda}
                                    >
                                        {rotulo}
                                    </div>
                                    <p className={cn('text-[13px] text-white/70 whitespace-pre-line')}>
                                        {spin[chave]}
                                    </p>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
