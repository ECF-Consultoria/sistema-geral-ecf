// ═══════════════════════════════════════════════════════════════════════
// Planilha: o modelo completo (funções puras, sem React e sem axios).
//
// O modelo completo traz o produto e a ficha técnica na mesma linha. Como a ficha
// muda com a categoria, a pessoa escolhe a categoria (uma ou mais) ANTES de baixar,
// e o arquivo vem com uma aba para cada uma. Na volta, a prévia mostra os produtos
// como sempre e, junto, o que a ficha de cada um ganharia.
// ═══════════════════════════════════════════════════════════════════════

/** Quantas categorias cabem num arquivo (o servidor recusa acima disso). */
export const MAX_CATEGORIAS = 10;

/** Soma a categoria escolhida à lista, sem repetir e sem passar do teto. `c` = { id, nome, caminho }. */
export function adicionarCategoria(lista, c, max = MAX_CATEGORIAS) {
    const atual = Array.isArray(lista) ? lista : [];
    const id = String(c?.id ?? '').trim();
    if (id === '' || atual.length >= max || atual.some((x) => String(x.id) === id)) return atual;

    return [...atual, { id, nome: String(c.nome ?? ''), caminho: String(c.caminho ?? '') }];
}

export const removerCategoria = (lista, id) => (Array.isArray(lista) ? lista : []).filter((x) => String(x.id) !== String(id));

/** Dá para somar mais uma? */
export const cabeMaisCategoria = (lista, max = MAX_CATEGORIAS) => (Array.isArray(lista) ? lista.length : 0) < max;

/** O que vai na consulta do download. */
export const parametrosDoModelo = (lista) => ({ categorias: (Array.isArray(lista) ? lista : []).map((x) => String(x.id)) });

/** O texto do botão de baixar: diz quantas abas o arquivo terá. */
export function textoDoBaixar(lista) {
    const n = Array.isArray(lista) ? lista.length : 0;
    if (n === 0) return 'Escolha a categoria para baixar';

    return n === 1 ? 'Baixar a planilha desta categoria' : `Baixar a planilha (${n} categorias, uma aba cada)`;
}

/** A mensagem de erro do download, que chega como JSON dentro de um blob. */
export function erroDoDownload(corpo) {
    try {
        const dados = typeof corpo === 'string' ? JSON.parse(corpo) : corpo;
        const erros = dados?.errors ?? {};
        const primeira = Object.values(erros)[0];

        return (Array.isArray(primeira) ? primeira[0] : primeira) ?? dados?.message ?? null;
    } catch (e) {
        return null;
    }
}

/** "Ficha técnica: 6 campos em 2 produtos." — o resumo da prévia da ficha. */
export function resumoDaFicha(ficha) {
    const t = ficha?.totais ?? {};
    const campos = Number(t.campos) || 0;
    const produtos = Number(t.produtos) || 0;
    if (campos === 0) return 'Ficha técnica: nenhum campo preenchido na planilha.';

    return `Ficha técnica: ${campos} ${campos === 1 ? 'campo' : 'campos'} em ${produtos} ${produtos === 1 ? 'produto' : 'produtos'}.`;
}

/**
 * Os produtos da ficha que pedem atenção, cada um com o que dizer:
 * campo recusado (não entra), produto que não recebe a ficha, ou obrigatório ainda em branco.
 */
export function pendenciasDaFicha(ficha) {
    const saida = [];
    (Array.isArray(ficha?.produtos) ? ficha.produtos : []).forEach((p) => {
        const erros = Array.isArray(p.erros) ? p.erros : [];
        const faltam = Array.isArray(p.faltam) ? p.faltam : [];
        const onde = [p.aba, p.linha ? `linha ${p.linha}` : null].filter(Boolean).join(', ');
        if (p.motivo) saida.push({ grupo: p.grupo, onde, tom: 'erro', texto: p.motivo });
        erros.forEach((e) => saida.push({ grupo: p.grupo, onde, tom: 'erro', texto: e }));
        if (faltam.length > 0) saida.push({ grupo: p.grupo, onde, tom: 'aviso', texto: `Ainda em branco: ${faltam.join(', ')}.` });
    });

    return saida;
}
