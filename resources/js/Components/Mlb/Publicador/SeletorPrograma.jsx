import { useRef } from 'react';
import { cn } from '@/lib/utils';

const OPCOES = [
    { chave: 'polos', rotulo: 'Polos' },
    { chave: 'incubadora', rotulo: 'Incubadora' },
    { chave: 'gestao', rotulo: 'Gestão' },
];

/**
 * Controle segmentado de programa (radiogroup). Setas esquerda/direita trocam
 * a opção. `programas` = { polos, incubadora, gestao } com a contagem.
 */
export default function SeletorPrograma({ programa, programas = {}, onTrocar }) {
    const refs = useRef({});

    function aoTeclar(ev, indice) {
        if (ev.key !== 'ArrowRight' && ev.key !== 'ArrowLeft') return;
        ev.preventDefault();
        const passo = ev.key === 'ArrowRight' ? 1 : -1;
        const prox = OPCOES[(indice + passo + OPCOES.length) % OPCOES.length];
        onTrocar?.(prox.chave);
        refs.current[prox.chave]?.focus();
    }

    return (
        <div
            role="radiogroup"
            aria-label="Programa"
            className="inline-flex h-10 items-center gap-1 rounded-lg border border-white/[0.08] bg-ecf-card p-1"
        >
            {OPCOES.map((o, i) => {
                const ativo = programa === o.chave;
                return (
                    <button
                        key={o.chave}
                        ref={(el) => { refs.current[o.chave] = el; }}
                        type="button"
                        role="radio"
                        aria-checked={ativo}
                        tabIndex={ativo ? 0 : -1}
                        onClick={() => onTrocar?.(o.chave)}
                        onKeyDown={(ev) => aoTeclar(ev, i)}
                        className={cn(
                            'inline-flex h-8 items-center gap-2 rounded-md border px-3 text-[13px] font-normal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                            ativo
                                ? 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow'
                                : 'border-transparent text-white/70 hover:bg-white/[0.04]',
                        )}
                    >
                        {o.rotulo}
                        <span className={cn('tabular-nums', ativo ? 'text-ecf-yellow/70' : 'text-white/40')}>
                            {programas[o.chave] ?? 0}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}
