import { useId } from 'react';
import { Info } from 'lucide-react';

// ─── O "o que é isto?" de um campo (pedido do usuário, 08/10/2026) ──────────
//
// "Pode até deixar, mas ao colocar o cursor do mouse em cima pelo menos
// explicar o que é — isso para tudo, não apenas para siglas." Um ícone de
// informação ao lado do rótulo; a explicação aparece ao passar o mouse E ao
// focar pelo teclado (o botão é focável). Leitor de tela: o botão é descrito
// pelo balão (`aria-describedby`), que existe sempre no DOM, só invisível.
//
// Balão próprio (CSS) em vez do `title` nativo: o nativo demora a abrir, não
// abre no foco do teclado e somaria um segundo balão por cima deste.
//
// Fica FORA do <label> (no `Campo`): botão dentro do rótulo focaria o campo a
// cada clique no ícone.
//
// O texto vem do servidor (`ExplicacaoDeAtributos`): glossário, ML ou IA.

export default function Explicacao({ texto, nome = null }) {
    const id = useId();
    if (typeof texto !== 'string' || texto.trim() === '') return null;

    return (
        <span className="group/explicacao relative inline-flex shrink-0 align-middle" data-explicacao>
            <button type="button" aria-label={nome ? `O que é ${nome}?` : 'O que é este campo?'} aria-describedby={id}
                className="grid h-5 w-5 place-items-center rounded-full text-white/40 hover:text-white/85 focus-visible:text-white/85 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow">
                <Info size={14} aria-hidden="true" />
            </button>
            <span role="tooltip" id={id} data-explicacao-texto
                className="pointer-events-none invisible absolute left-0 top-full z-30 mt-1.5 w-64 max-w-[80vw] rounded-lg border border-white/15 bg-ecf-card px-3 py-2 text-[13px] font-normal leading-snug text-white/85 opacity-0 shadow-lg transition-opacity group-hover/explicacao:visible group-hover/explicacao:opacity-100 group-focus-within/explicacao:visible group-focus-within/explicacao:opacity-100">
                {texto}
            </span>
        </span>
    );
}
