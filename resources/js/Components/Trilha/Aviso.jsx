import { useEffect, useRef } from 'react';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Trilha de produtos — o aviso curto depois de uma escrita ───────────────
//
// O `toast` do protótipo: "Na Onda 2. O robô começa em 05/11.", "Onda 3
// criada…", o erro que o servidor devolveu. O TrilhaLayout não mostra o flash
// do sistema, e as escritas da trilha são JSON: o aviso é daqui.
//
// A região `role="status"` existe sempre (vazia quando não há aviso): o leitor
// de tela só anuncia a mudança de uma região que já estava na página.
//
// Com o painel lateral aberto, o aviso vai para o lado esquerdo dele (no
// computador) e some no celular — lá o painel ocupa a tela inteira, e o próprio
// painel já mostra o resultado (o aviso cobriria os botões do rodapé dele).
// O de sucesso sai sozinho; o de erro também, mais devagar. Os dois fecham no ×.

const TEMPO = { sucesso: 6000, erro: 12000 };

/**
 * @param {{
 *   aviso: ?{id: number|string, tipo: 'sucesso'|'erro', texto: string, extras?: string[]},
 *   onFechar: () => void,
 *   comPainel?: boolean,
 * }} props
 */
export default function Aviso({ aviso, onFechar, comPainel = false }) {
    // O fechar mais novo, sem reiniciar o relógio a cada render da página.
    const fechar = useRef(onFechar);
    fechar.current = onFechar;

    useEffect(() => {
        if (!aviso) return undefined;
        const relogio = setTimeout(() => fechar.current?.(), TEMPO[aviso.tipo] ?? TEMPO.sucesso);

        return () => clearTimeout(relogio);
    }, [aviso?.id]);

    const erro = aviso?.tipo === 'erro';
    const extras = (aviso?.extras ?? []).filter(Boolean);

    return (
        <div
            role="status"
            aria-live="polite"
            data-aviso-regiao=""
            className={cn(
                'pointer-events-none fixed bottom-[calc(20px+env(safe-area-inset-bottom,0px))] left-0 z-[60] flex justify-center px-4',
                comPainel ? 'right-[min(560px,100vw)] max-[900px]:hidden' : 'right-0',
            )}
        >
            {aviso && (
                <div
                    data-aviso={aviso.tipo}
                    className={cn(
                        'pointer-events-auto flex w-fit max-w-[min(560px,100%)] items-start gap-2.5 rounded-[10px] px-4 py-2.5 text-[13px] shadow-lg',
                        erro ? 'border border-trilha-bad/40 bg-trilha-bad-bg text-trilha-bad' : 'bg-trilha-navy text-trilha-on-navy',
                    )}
                >
                    <div className="min-w-0 flex-1">
                        <p className="m-0 font-semibold [overflow-wrap:anywhere]">{aviso.texto}</p>
                        {extras.length > 0 && (
                            <ul className="m-0 mt-1 flex list-none flex-col gap-0.5 p-0">
                                {extras.map((extra) => (
                                    <li key={extra} className="[overflow-wrap:anywhere]">
                                        {extra}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={onFechar}
                        aria-label="Fechar o aviso"
                        className="-mr-1 shrink-0 rounded p-0.5 opacity-80 hover:opacity-100 focus-visible:[outline-style:solid] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current"
                    >
                        <X size={15} aria-hidden="true" />
                    </button>
                </div>
            )}
        </div>
    );
}
