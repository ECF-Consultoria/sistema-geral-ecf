// Sinal global "tem painel de parede na tela".
//
// Modo TV fica exposto o dia inteiro sem ninguém na frente para fechar aviso. O cartão
// de ticket respondido e o toast de flash moram no AppLayout, numa pilha fixa por cima
// de tudo — inclusive do overlay do Modo TV quando ele é renderizado DENTRO do layout
// (Painel Polos). Quem liga um Modo TV anuncia aqui; o AppLayout lê e deixa de montar
// os avisos enquanto houver algum ligado. O aviso não é perdido: continua não lido e
// aparece no computador de quem abriu o ticket (ou quando a TV sai do modo).
//
// Contador, e não booleano: dois overlays ligados não se desligam um ao outro.
// JS puro (sem React) para ser testado com node:test; o hook fica em hooks/useModoTv.js.

let ligados = 0;
const ouvintes = new Set();

const avisar = () => ouvintes.forEach((fn) => fn());

/**
 * Liga o sinal e devolve a função que o desliga. Chamar a devolvida duas vezes não
 * desconta em dobro (cleanup de efeito pode rodar mais de uma vez no StrictMode).
 */
export function ligarModoTv() {
    ligados += 1;
    avisar();
    let desligado = false;
    return () => {
        if (desligado) return;
        desligado = true;
        ligados -= 1;
        avisar();
    };
}

/** Verdadeiro enquanto alguma tela estiver em Modo TV. */
export const modoTvLigado = () => ligados > 0;

/** Assina mudanças do sinal (formato do useSyncExternalStore). Devolve o cancelamento. */
export function assinarModoTv(fn) {
    ouvintes.add(fn);
    return () => { ouvintes.delete(fn); };
}
