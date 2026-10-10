// ═══════════════════════════════════════════════════════════════════════
// Excluir produtos inteiros no Produtos (10/10/2026) — a seleção da lista e os textos da
// confirmação. SÓ apresentação.
//
// O que sai junto (variações, ofertas montadas que usam o produto, o que a equipe já usa) vem
// pronto do servidor (rota exclusao/previa, `ExclusaoDeProdutos`). Aqui só se guarda o que a
// pessoa marcou e se diz, em português, o que a prévia trouxe.
// ═══════════════════════════════════════════════════════════════════════

export const ROTULO_FASE_MONTADA = { combo: 'Combo', kit: 'Kit', combit: 'Combit' };

/** Quantos produtos o servidor aceita por pedido (`ExclusaoDeProdutos::MAXIMO`). */
export const MAXIMO_POR_PEDIDO = 200;

/** Quantos nomes a janela lista antes de resumir em "e mais N". */
export const NOMES_A_MOSTRAR = 6;

// ─── Seleção (um Set de ids de produto, nunca mudado no lugar) ──────────

export function alternarSelecao(selecionados, id) {
    const s = new Set(selecionados);
    if (s.has(id)) s.delete(id);
    else s.add(id);

    return s;
}

export const somarASelecao = (selecionados, ids) => new Set([...selecionados, ...ids]);

export function tirarDaSelecao(selecionados, ids) {
    const s = new Set(selecionados);
    ids.forEach((id) => s.delete(id));

    return s;
}

/** A página inteira já está marcada? (página vazia nunca está) */
export const paginaToda = (selecionados, idsDaPagina) => idsDaPagina.length > 0 && idsDaPagina.every((id) => selecionados.has(id));

/** Os ids de produto das linhas gravadas, na ordem da tela e sem repetir. */
export function idsDosProdutos(linhas) {
    const ids = new Set();
    (linhas ?? []).forEach((l) => { if (l.id && l.produto_id) ids.add(l.produto_id); });

    return [...ids];
}

// ─── Textos ─────────────────────────────────────────────────────────────

const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;

/** "3 produtos selecionados" */
export const textoDaSelecao = (n) => `${plural(n, 'produto selecionado', 'produtos selecionados')}`;

/** O título da janela: o nome quando é um só. */
export function tituloDaExclusao(previa) {
    const produtos = previa?.produtos ?? [];
    if (produtos.length === 1) return `Excluir ${produtos[0].nome}?`;

    return `Excluir ${plural(produtos.length, 'produto', 'produtos')}?`;
}

/** O botão que confirma. */
export const textoDoBotaoExcluir = (previa) => {
    const n = previa?.totais?.produtos ?? 0;

    return n === 1 ? 'Excluir produto' : `Excluir ${n} produtos`;
};

/**
 * Os nomes para listar na janela, com o resto resumido.
 *
 * @returns {{ nomes: string[], resto: number }}
 */
export function nomesParaMostrar(previa, limite = NOMES_A_MOSTRAR) {
    const nomes = (previa?.produtos ?? []).map((p) => p.nome);

    return { nomes: nomes.slice(0, limite), resto: Math.max(0, nomes.length - limite) };
}

/**
 * As frases da confirmação, na ordem em que a pessoa lê: o que some com o produto, o que sai junto
 * e o aviso de uso pela equipe.
 *
 * @returns {{ variacoes: string, montadas: string|null, emUso: string|null, naoEncontrados: string|null }}
 */
export function frasesDaExclusao(previa) {
    const t = previa?.totais ?? { produtos: 0, variacoes: 0, montadas: 0, em_uso: 0 };
    const um = t.produtos === 1;
    const dele = um ? 'dele' : 'deles';

    return {
        variacoes: `${um ? 'O produto sai' : 'Os produtos saem'} com ${plural(t.variacoes, 'variação', 'variações')}, as fotos, a ficha técnica e os preços ${dele}. Não dá para desfazer.`,
        montadas: t.montadas > 0
            ? `${plural(t.montadas, 'oferta montada usa', 'ofertas montadas usam')} ${um ? 'este produto' : 'estes produtos'} e ${t.montadas === 1 ? 'sai' : 'saem'} junto:`
            : null,
        emUso: t.em_uso > 0
            ? `Parte disto já está em uso pela equipe da ECF. Se excluir, a equipe precisa revisar o que estava ligado.`
            : null,
        naoEncontrados: (previa?.nao_encontrados ?? 0) > 0
            ? `${plural(previa.nao_encontrados, 'produto marcado não existe mais e ficou', 'produtos marcados não existem mais e ficaram')} de fora.`
            : null,
    };
}

/** A mensagem que a pessoa lê quando a prévia ou a exclusão não passam. */
export function erroDaExclusao(e) {
    const status = e?.response?.status;
    if (status === 422) {
        const erros = e.response.data?.errors;
        const primeiro = erros ? Object.values(erros).flat()[0] : null;

        return primeiro ?? e.response.data?.message ?? 'Confira os produtos escolhidos.';
    }
    if (status === 404) return 'Estes produtos não existem mais. Atualize a página.';
    if (status === 429) return 'Muitas tentativas seguidas. Espere um minuto e tente de novo.';

    return 'Não foi possível excluir agora. Tente de novo.';
}

/** A lista de montadas mudou entre a prévia e a confirmação (422 em `montadas`): a janela mostra de novo. */
export const listaMudou = (e) => e?.response?.status === 422 && Array.isArray(e.response.data?.errors?.montadas);
