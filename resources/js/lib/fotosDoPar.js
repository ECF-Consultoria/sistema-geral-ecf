// ─── A ordem das fotos do par (Anunciar) ────────────────────────────────────
//
// A ordem da lista É a do anúncio: a 1ª é a capa e o Mercado Livre recebe
// `pictures` nesta sequência (no Publicador, `ResolvedorGruposImagem`).
// Funções puras, sem React, para o arraste, os botões ◀ ▶, o "tornar capa" e
// o teste em node (`tests/js/fotos-do-par.test.js`).
//
// Nunca mutam a lista recebida; quando nada muda (índice fora da lista, mesma
// posição), devolvem a MESMA referência — assim o autosave não dispara à toa.

/** Tira a foto da posição `de` e a põe na posição `para`; as outras abrem espaço. */
export function moverFoto(fotos, de, para) {
    const n = fotos.length;
    if (de < 0 || de >= n || para < 0 || para >= n || de === para) return fotos;

    const lista = fotos.slice();
    const [foto] = lista.splice(de, 1);
    lista.splice(para, 0, foto);

    return lista;
}

/** ◀ (−1) e ▶ (+1): um passo, parando nas pontas. */
export const moverUmPasso = (fotos, indice, direcao) => moverFoto(fotos, indice, indice + direcao);

/** A foto vira a 1ª — a capa. */
export const tornarCapa = (fotos, indice) => moverFoto(fotos, indice, 0);
