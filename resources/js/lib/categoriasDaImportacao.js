// ═══════════════════════════════════════════════════════════════════════
// Categorias a confirmar na prévia da importação de produtos (09/10/2026).
//
// A prévia agrupa os produtos pelo NOME de categoria digitado na planilha
// (`categorias_a_confirmar.nomes`: chave, texto, produtos, exemplos). A tela
// pede a sugestão de cada nome em blocos (o servidor aceita até 10 por vez),
// enquanto a pessoa já olha a prévia, e guarda a ESCOLHA dela por chave. Nada é
// aceito sozinho: só o que ela confirmou vai junto com o arquivo na confirmação,
// como `categorias: [{ texto, id }]` — o servidor confere de novo cada uma.
//
// Sem React e sem axios: a janela injeta `enviar` (o POST, devolve `data`) e
// `aoReceber` (aplica as sugestões no estado); o teste roda o laço de verdade.
// ═══════════════════════════════════════════════════════════════════════

/** Nomes por pedido: o máximo que a rota aceita (`SugestaoDeCategoriaPorNome::MAX_POR_PEDIDO`). */
export const LOTE_NOMES = 10;

/** Quantos nomes ganham sugestão automática por prévia (os mais usados vêm primeiro). O resto, a pessoa busca. */
export const MAXIMO_SUGERIDOS = 50;

/** O tamanho que a busca de categoria aceita (2 a 120 letras). */
export const MINIMO_TEXTO = 2;
export const MAXIMO_TEXTO = 120;

/**
 * O texto que vai ao servidor para um nome: aparado, cortado no máximo da busca e aparado
 * de novo (o servidor apara antes de cortar; assim o `texto` da resposta é este mesmo).
 */
export function textoDeBusca(texto) {
    return String(texto ?? '').trim().slice(0, MAXIMO_TEXTO).trim();
}

/**
 * Os nomes que pedem sugestão, na ordem da prévia, até `limite`.
 * @returns {Array<{ chave: string, texto: string }>} `texto` já no formato de busca
 */
export function nomesParaSugerir(nomes = [], limite = MAXIMO_SUGERIDOS) {
    return nomes
        .map((n) => ({ chave: n.chave, texto: textoDeBusca(n.texto) }))
        .filter((n) => n.chave && n.texto.length >= MINIMO_TEXTO)
        .slice(0, limite);
}

/**
 * Pede as sugestões em blocos de `LOTE_NOMES`, um bloco por vez, e entrega cada resposta a
 * `aoReceber(porChave, indisponivel)`: `porChave` é `{ [chave]: sugestão | null }`. Bloco que
 * falha marca os nomes dele como sem sugestão e `indisponivel`; o laço segue. `vivo()` falso
 * (a janela fechou ou outra prévia chegou) para tudo sem chamar `aoReceber` de novo.
 *
 * @returns {Promise<{ blocos: number, indisponivel: boolean }>}
 */
export async function buscarSugestoesEmLotes(nomes, { enviar, aoReceber, vivo = () => true, lote = LOTE_NOMES }) {
    let blocos = 0;
    let indisponivel = false;

    for (let i = 0; i < nomes.length; i += lote) {
        if (! vivo()) break;
        const bloco = nomes.slice(i, i + lote);
        const chaveDoTexto = Object.fromEntries(bloco.map((n) => [n.texto, n.chave]));
        const porChave = Object.fromEntries(bloco.map((n) => [n.chave, null]));
        let falhou = false;

        try {
            const data = await enviar(bloco.map((n) => n.texto));
            (data?.sugestoes ?? []).forEach((s) => {
                const chave = chaveDoTexto[s.texto];
                if (chave) porChave[chave] = s.sugestao ?? null;
            });
            falhou = !! data?.indisponivel;
        } catch (e) {
            falhou = true;
        }

        blocos++;
        if (! vivo()) break;
        if (falhou) indisponivel = true;
        aoReceber(porChave, falhou);
    }

    return { blocos, indisponivel };
}

/**
 * O que vai na confirmação: só as escolhas da pessoa, com o nome como ela digitou.
 * @param {Array<{ chave: string, texto: string }>} nomes os da prévia
 * @param {Object<string, { id: string }>} escolhas por chave
 * @returns {Array<{ texto: string, id: string }>}
 */
export function confirmadasParaEnvio(nomes = [], escolhas = {}) {
    return nomes
        .filter((n) => escolhas[n.chave]?.id)
        .map((n) => ({ texto: n.texto, id: escolhas[n.chave].id }));
}

/** Os nomes com sugestão que a pessoa ainda não confirmou (o "Confirmar as N sugestões"). */
export function sugestoesPendentes(nomes = [], sugestoes = {}, escolhas = {}) {
    return nomes.filter((n) => ! escolhas[n.chave] && sugestoes[n.chave]?.estado === 'pronta' && sugestoes[n.chave]?.sugestao);
}

/** Quantos nomes e produtos já têm categoria escolhida. */
export function resumoDasEscolhas(nomes = [], escolhas = {}) {
    const escolhidos = nomes.filter((n) => escolhas[n.chave]);

    return {
        nomes: escolhidos.length,
        produtos: escolhidos.reduce((soma, n) => soma + (Number(n.produtos) || 0), 0),
    };
}

/** "1 produto" / "12 produtos". */
export function textoDeProdutos(n) {
    return Number(n) === 1 ? '1 produto' : `${Number(n) || 0} produtos`;
}
