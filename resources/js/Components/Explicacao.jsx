import { useId, useRef, useState } from 'react';
import { Info } from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── O "o que é isto?" de um campo (pedido do usuário, 08/10/2026) ──────────
//
// "Pode até deixar, mas ao colocar o cursor do mouse em cima pelo menos
// explicar o que é — isso para tudo, não apenas para siglas." Um ícone de
// informação ao lado do rótulo; a explicação aparece ao passar o mouse E ao
// focar pelo teclado (o botão é focável). Leitor de tela: o botão é descrito
// pelo balão (`aria-describedby`), que existe sempre no DOM.
//
// Balão próprio (CSS) em vez do `title` nativo: o nativo demora a abrir, não
// abre no foco do teclado e somaria um segundo balão por cima deste. Por isso
// quem usa este componente tira o `title` do rótulo.
//
// Fechado, o balão sai do layout (`hidden`, não só invisível): invisível ele
// ainda ocupava a área rolável e a página ganhava rolagem horizontal em tela
// estreita. Aberto, fica dentro da largura da tela e abre para o lado que tem
// espaço. Esc fecha; o ponteiro pode passar do ícone para o balão sem ele
// sumir (sem vão entre os dois); o toque no ícone abre e fecha (celular).
//
// Fica FORA do <label>: botão dentro do rótulo focaria o campo a cada clique.
//
// Componente compartilhado (editor interno e ficha do produto do Portal do
// Cliente). Não busca nada: o texto vem pronto do servidor. Também é lido pelo
// gate de sigilo do Portal, então o comentário fica neutro.

export default function Explicacao({ texto, nome = null }) {
    const id = useId();
    const caixa = useRef(null);
    const [dispensado, setDispensado] = useState(false); // Esc
    const [fixado, setFixado] = useState(false); // toque/clique
    const [direita, setDireita] = useState(false);
    if (typeof texto !== 'string' || texto.trim() === '') return null;

    const medir = () => {
        const r = caixa.current?.getBoundingClientRect?.();
        if (r && typeof window !== 'undefined') setDireita(r.left > window.innerWidth / 2);
    };
    const reabrir = () => {
        setDispensado(false);
        medir();
    };

    return (
        <span
            ref={caixa}
            className="group/explicacao relative inline-flex shrink-0 align-middle"
            data-explicacao
            onMouseEnter={reabrir}
            onKeyDown={(e) => {
                if (e.key === 'Escape') {
                    setDispensado(true);
                    setFixado(false);
                }
            }}
            onBlur={(e) => {
                if (! e.currentTarget.contains(e.relatedTarget)) setFixado(false);
            }}
        >
            <button type="button" aria-label={nome ? `O que é ${nome}?` : 'O que é este campo?'} aria-describedby={id}
                onFocus={reabrir}
                onClick={() => {
                    reabrir();
                    setFixado((f) => ! f);
                }}
                className="grid h-5 w-5 place-items-center rounded-full text-white/40 hover:text-white/85 focus-visible:text-white/85 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                <Info size={14} aria-hidden="true" />
            </button>
            <span role="tooltip" id={id} data-explicacao-texto
                className={cn(
                    'absolute top-full z-30 hidden w-64 max-w-[calc(100vw-2rem)] pt-1.5',
                    direita ? 'right-0' : 'left-0',
                    ! dispensado && 'group-hover/explicacao:block group-focus-within/explicacao:block',
                    fixado && ! dispensado && 'block',
                )}>
                <span className="block rounded-lg border border-white/15 bg-ecf-card px-3 py-2 text-[13px] font-normal leading-snug text-white/85 shadow-lg">
                    {texto}
                </span>
            </span>
        </span>
    );
}
