import { useLayoutEffect, useSyncExternalStore } from 'react';
import { assinarModoTv, ligarModoTv, modoTvLigado } from '@/lib/modoTv';

/**
 * Anuncia que esta tela está em Modo TV enquanto `ativo` for verdadeiro e o componente
 * estiver montado. Layout effect para o sinal ligar antes da pintura — o aviso não
 * chega a piscar na parede.
 */
export function useAnunciarModoTv(ativo = true) {
    useLayoutEffect(() => (ativo ? ligarModoTv() : undefined), [ativo]);
}

/** Verdadeiro enquanto alguma tela estiver em Modo TV. */
export function useModoTvLigado() {
    return useSyncExternalStore(assinarModoTv, modoTvLigado, () => false);
}
