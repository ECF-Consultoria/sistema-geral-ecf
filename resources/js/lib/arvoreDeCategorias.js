// ═══════════════════════════════════════════════════════════════════════
// Navegar pelas categorias, nível a nível: funções puras (sem React e sem axios).
//
// A busca por texto devolve poucos palpites e, com texto curto, erra. Aqui a pessoa
// desce a árvore: escolhe o 1º nível, vê as categorias dentro dele, e segue até o
// último — que é o único que pode ser escolhido.
//
// O servidor entrega UM nível por vez:
//   { atual: { id, nome, folha, anunciavel } | null, caminho: [{ id, nome }], filhas: [{ id, nome, folha, anunciavel }], indisponivel }
// `atual` nulo é o 1º nível. `folha` nula numa filha = ainda não se sabe se ela é a
// última: entra-se nela para descobrir.
// ═══════════════════════════════════════════════════════════════════════

export const NIVEL_VAZIO = { atual: null, caminho: [], filhas: [], indisponivel: false };

/** O nome do começo da trilha (o nível acima de todos). */
export const INICIO_DA_TRILHA = 'Todas as categorias';

/** A trilha clicável: o começo e cada nível do caminho. O último item é onde a pessoa está. */
export function trilha(nivel) {
    const caminho = Array.isArray(nivel?.caminho) ? nivel.caminho : [];

    return [
        { id: null, nome: INICIO_DA_TRILHA },
        ...caminho.filter((c) => c && c.id != null).map((c) => ({ id: String(c.id), nome: String(c.nome ?? '') })),
    ];
}

/** A categoria é a última do ramo? Só `true` conta: nula é "não se sabe". */
export const ehFinal = (categoria) => categoria?.folha === true;

/** A última do ramo pode ser escolhida? (o servidor só diz não quando sabe que não pode). */
export const podeEscolher = (categoria) => ehFinal(categoria) && categoria.anunciavel !== false;

/** O nível em que a pessoa está É a última categoria do ramo (não há mais para onde descer)? */
export const nivelEhFinal = (nivel) => ehFinal(nivel?.atual);

/**
 * O que a escolha de uma categoria vira: id, nome, o caminho em lista e escrito.
 * `nivel` é onde ela foi vista — como filha dele, ou como o próprio nível (`atual`).
 */
export function escolhaDaCategoria(nivel, categoria) {
    const niveis = (Array.isArray(nivel?.caminho) ? nivel.caminho : []).map((c) => String(c.nome ?? ''));
    const ehOProprioNivel = nivel?.atual && String(nivel.atual.id) === String(categoria.id);
    const caminho = ehOProprioNivel ? niveis : [...niveis, String(categoria.nome ?? '')];

    return { id: String(categoria.id), nome: String(categoria.nome ?? ''), caminho, caminho_texto: caminho.join(' > '), folha: true };
}

const semAcento = (t) => String(t ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

/** As filhas cujo nome tem o que foi digitado (sem acento nem caixa). Texto vazio devolve todas. */
export function filtrarFilhas(filhas, texto) {
    const lista = Array.isArray(filhas) ? filhas : [];
    const alvo = semAcento(texto);

    return alvo === '' ? lista : lista.filter((f) => semAcento(f?.nome).includes(alvo));
}

/**
 * Para onde ir quando a navegação abre numa categoria já escolhida: se ela é a última do ramo, o
 * nível de cima (a pessoa vê as vizinhas dela); senão, fica onde está. `undefined` = não há o que mudar.
 */
export function nivelDeAbertura(nivel) {
    if (! nivelEhFinal(nivel)) return undefined;
    const caminho = Array.isArray(nivel?.caminho) ? nivel.caminho : [];

    return caminho.length >= 2 ? String(caminho[caminho.length - 2].id) : null;
}
