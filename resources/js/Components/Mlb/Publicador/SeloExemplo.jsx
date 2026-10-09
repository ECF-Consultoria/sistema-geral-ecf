import { cn } from '@/lib/utils';

/**
 * A pilha discreta "exemplo" (quick 261010-t02b).
 *
 * Marca o bloco que mostra dado fictício — o que vem de `dadosDeExemplo.js`.
 * O layout fica igual ao mockup do Stitch; quem olhar de perto sabe o que
 * ainda não é real. Some sozinha no dia em que o dado passar a existir, porque
 * a constante correspondente sai do `dadosDeExemplo.js` junto.
 *
 * REGRAS (decisão do usuário em 10/10):
 *   - Bloco com dado REAL não leva a pilha.
 *   - Bloco com dado de EXEMPLO leva UMA pilha — por bloco, nunca por número.
 *   - Nunca misturar real e exemplo dentro do MESMO número.
 *
 * No vocabulário visual do módulo: 11px, `uppercase`, `tracking-[0.05em]`,
 * borda e fundo translúcidos. Discreta de propósito — ela informa, não grita.
 */

/** O motivo padrão, quando o bloco não tem um mais específico para contar. */
export const TITULO_EXEMPLO = 'Dado de exemplo: este bloco mostra valores fictícios até a integração existir. Nada aqui vem desta conta.';

export default function SeloExemplo({ title = null, className = null }) {
    // ⚠️ Prop NULA ≠ ausente: o default de desestruturação só cobre `undefined`,
    // e `title={algo?.motivo}` chega como null. Já foi bug real em duas telas.
    const titulo = (typeof title === 'string' && title.trim() !== '') ? title : TITULO_EXEMPLO;
    const extra = typeof className === 'string' ? className : null;

    return (
        <span
            title={titulo}
            className={cn(
                'inline-flex shrink-0 items-center whitespace-nowrap rounded-md border border-white/[0.10] bg-white/[0.04] px-2 py-0.5 text-[11px] font-normal uppercase tracking-[0.05em] text-white/40',
                extra,
            )}
        >
            exemplo
        </span>
    );
}
