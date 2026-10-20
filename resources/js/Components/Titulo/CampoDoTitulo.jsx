import { useLayoutEffect, useRef } from 'react';
import { capitalizarTitulo, limiteDoTitulo } from '@/lib/tituloDoProduto';
import { cn } from '@/lib/utils';

// ─── O campo do título, comum ao cadastro do Portal e ao editor do Publicador ──
//
// Digitar passa pela regra da 1ª letra maiúscula (lib/tituloDoProduto). Trocar a 1ª
// letra num campo controlado mandaria o cursor para o fim; por isso a posição é
// guardada antes e reposta depois (a troca não muda o tamanho do texto).
// O campo não aceita passar do limite da categoria (`maxLength`): o que já veio
// maior continua editável para baixo, e o contador fica vermelho.

export function CampoDoTitulo({ id, valor, onChange, limite, className, ...resto }) {
    const campo = useRef(null);
    const cursor = useRef(null);

    useLayoutEffect(() => {
        if (cursor.current === null || ! campo.current) return;
        const [inicio, fim] = cursor.current;
        cursor.current = null;
        if (document.activeElement === campo.current) campo.current.setSelectionRange(inicio, fim);
    });

    const aoDigitar = (e) => {
        const digitado = e.target.value;
        const novo = capitalizarTitulo(digitado);
        if (novo !== digitado) cursor.current = [e.target.selectionStart, e.target.selectionEnd];
        onChange(novo);
    };

    return (
        <input ref={campo} id={id} value={valor ?? ''} onChange={aoDigitar} maxLength={limiteDoTitulo(limite)}
            autoComplete="off" className={className} {...resto} />
    );
}

/** "38/60", vermelho acima do limite. */
export function ContadorDoTitulo({ tamanho, limite, className, ...resto }) {
    const maximo = limiteDoTitulo(limite);

    return (
        <span className={cn('font-mono text-[13px] tabular-nums', tamanho > maximo ? 'text-red-300' : 'text-white/45', className)} {...resto}>
            {tamanho}/{maximo}
        </span>
    );
}
