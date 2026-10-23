// ─── Montar o título com os termos da categoria (19/10/2026) ────────────────
//
// Uma regra só para o cadastro do produto no Portal e para o editor do Publicador.
// Sem React e sem rota: testadas em tests/js/titulo-do-produto.test.js.
//
// - TODA palavra começa com maiúscula, inclusive acentuada e as de ligação
//   ("puff pera com enchimento" → "Puff Pera Com Enchimento"; correção do usuário em
//   20/10/2026 — antes era só a 1ª do título). Só a 1ª letra de cada palavra muda; o
//   resto fica como a pessoa escreveu. Quem garante é o servidor (`TituloDoProduto`);
//   a tela só espelha, para o que se vê ser o que se grava.
// - O termo está "no título" quando todas as palavras dele estão lá, sem caixa,
//   sem acento e sem pontuação nas pontas. É isso que marca o chip: o estado vem do
//   TEXTO, então apagar o termo digitando desmarca o chip sozinho.
// - Clicar de novo num termo marcado tira só aquele termo e junta os espaços.

/** O limite de quando a categoria ainda não disse o dela (o mais comum entre as categorias). */
export const LIMITE_TITULO_PADRAO = 60;

const semAcento = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/** A forma de comparar uma palavra: sem caixa, sem acento e sem pontuação nas pontas ("gamer," = "Gamer"). */
const comparavel = (p) => semAcento(p).replace(/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/gu, '');

const palavrasDe = (s) => String(s ?? '').split(/\s+/).filter(Boolean);

/** O limite que vale: o da categoria, ou o padrão enquanto ela não disse. */
export const limiteDoTitulo = (limite) => (Number(limite) > 0 ? Math.trunc(Number(limite)) : LIMITE_TITULO_PADRAO);

/** A 1ª letra de cada palavra em maiúscula; nada mais muda (nem os espaços). Palavra que começa por número fica. */
export const capitalizarTitulo = (texto) => String(texto ?? '').replace(/(^|\s)(\p{Ll})/gu, (_, antes, letra) => `${antes}${letra.toLocaleUpperCase('pt-BR')}`);

/** O termo já está inteiro no título? (é o que deixa o chip marcado) */
export function termoNoTitulo(titulo, termo) {
    const tem = new Set(palavrasDe(titulo).map(comparavel).filter(Boolean));
    const doTermo = palavrasDe(termo).map(comparavel).filter(Boolean);

    return doTermo.length > 0 && doTermo.every((p) => tem.has(p));
}

/** As palavras do termo que o título ainda não tem, acrescentadas no fim (sem repetir palavra). */
export function juntarTermo(titulo, termo) {
    const atual = String(titulo ?? '').trim();
    const tem = new Set(palavrasDe(atual).map(comparavel));
    const novas = palavrasDe(termo).filter((p) => ! tem.has(comparavel(p)));
    if (novas.length === 0) return capitalizarTitulo(atual);

    return capitalizarTitulo([atual, ...novas].filter(Boolean).join(' '));
}

/**
 * Tira o termo do título. Junto e na ordem, sai a sequência; espalhado, sai a 1ª ocorrência de cada
 * palavra dele. Os espaços repetidos somem e a nova 1ª palavra ganha a maiúscula.
 */
export function tirarTermo(titulo, termo) {
    const lista = palavrasDe(titulo);
    const chaves = lista.map(comparavel);
    const doTermo = palavrasDe(termo).map(comparavel).filter(Boolean);
    const sair = new Set();

    if (doTermo.length > 0) {
        const inicio = chaves.findIndex((_, i) => i + doTermo.length <= chaves.length && doTermo.every((p, j) => chaves[i + j] === p));
        if (inicio >= 0) {
            doTermo.forEach((_, j) => sair.add(inicio + j));
        } else {
            doTermo.forEach((p) => {
                const i = chaves.findIndex((c, k) => c === p && ! sair.has(k));
                if (i >= 0) sair.add(i);
            });
        }
    }

    return capitalizarTitulo(lista.filter((_, i) => ! sair.has(i)).join(' '));
}

/** O clique no chip: tira o termo que está no título, senão acrescenta. */
export const alternarTermo = (titulo, termo) => (termoNoTitulo(titulo, termo) ? tirarTermo(titulo, termo) : juntarTermo(titulo, termo));

/** Acrescentar o termo ainda cabe no limite? (o termo marcado sempre pode sair) */
export const termoCabe = (titulo, termo, limite) => termoNoTitulo(titulo, termo) || juntarTermo(titulo, termo).length <= limiteDoTitulo(limite);
