// ═══════════════════════════════════════════════════════════════════════
// Pareamento entre a Planilha de Produtos e a Precificação (Implementação).
//
// A Precificação NÃO tem lista própria de produtos: ela é derivada da Planilha
// de Produtos e guarda só os números (custo, fretes, imposto/MC/LL por produto).
// Toda vez que o modal abre, os dois lados são remesclados — e é aí que mora a
// armadilha que este módulo existe para evitar.
//
// ATENÇÃO — a chave do pareamento NÃO pode ser só o SKU. Cliente que digita o
// mesmo texto em todos os produtos ("Não tenho", "-", "sem código") fazia as N
// linhas caírem no MESMO registro salvo: todos os produtos apareciam com o nome
// e o preço de um só, e renomear não adiantava porque o merge desfazia na
// reabertura seguinte. Por isso o pareamento aqui é 1-para-1 — cada linha salva
// é consumida por no máximo um produto da planilha.
// ═══════════════════════════════════════════════════════════════════════

/** Linha de precificação em branco — só campos numéricos + identificação. */
export const PRECIF_LINHA_VAZIA = {
    sku: '', descricao: '', custo: '', frete_classico: '', frete_premium: '',
    imposto_individual: '', mc_individual: '', ll_individual: '',
};

const vazio   = (v) => String(v ?? '').trim() === '';
const normTxt = (v) => String(v ?? '').trim().toLowerCase();

/** Chave de identidade do produto. Vazia (SKU e nome em branco) não identifica ninguém. */
const chave = (sku, nome) => (vazio(sku) && vazio(nome)) ? '' : `${normTxt(sku)}|${normTxt(nome)}`;

/** Produtos da planilha que de fato existem (linha em branco do grid não conta). */
export const produtosPreenchidos = (planilhaProdutos) =>
    (planilhaProdutos ?? []).filter(p => !vazio(p?.sku) || !vazio(p?.produto));

/**
 * Mescla a Planilha de Produtos com a precificação já salva.
 *
 * @param {Array} planilhaProdutos linhas do item `planilha_produtos` (campo `produto` = nome)
 * @param {Array} salvos           linhas do item `precificacao` (campo `descricao` = nome)
 * @returns {Array} produtos da planilha (na ordem dela) + avulsos criados só no Simulador
 */
export function mesclarPrecificacaoComPlanilha(planilhaProdutos, salvos) {
    const planilha = produtosPreenchidos(planilhaProdutos);
    const linhas   = salvos ?? [];

    // Sem planilha, a precificação vive por conta própria (só produtos avulsos).
    if (planilha.length === 0) {
        return linhas.map(p => ({ ...PRECIF_LINHA_VAZIA, ...p }));
    }

    const usados = new Set();
    // Índice das linhas salvas por chave, em FILA: produtos de mesma chave (variações
    // do mesmo anúncio, ou SKU repetido) consomem linhas diferentes, em ordem.
    const indexar = (chaveDe) => {
        const m = new Map();
        linhas.forEach((p, i) => {
            const k = chaveDe(p);
            if (!k) return;
            if (!m.has(k)) m.set(k, []);
            m.get(k).push(i);
        });
        return m;
    };
    const porSkuNome = indexar(p => chave(p.sku, p.descricao));
    const porNome    = indexar(p => normTxt(p.descricao));

    const consome = (mapa, k) => {
        const fila = mapa.get(k);
        while (fila?.length) {
            const i = fila.shift();
            if (!usados.has(i)) { usados.add(i); return i; }
        }
        return null;
    };

    // 1ª passada: SKU + nome. 2ª: só o nome (cliente corrigiu o SKU depois de precificar).
    const par = planilha.map(p => consome(porSkuNome, chave(p.sku, p.produto)));
    planilha.forEach((p, i) => {
        if (par[i] === null) par[i] = consome(porNome, normTxt(p.produto));
    });
    // 3ª: pela ordem — e só entre linhas que carregam um SKU da planilha, para que o
    // produto avulso do Simulador nunca seja grudado num produto da planilha. É esta
    // passada que reancora quem foi renomeado na planilha depois de precificado.
    const skusPlanilha = new Set(planilha.map(p => normTxt(p.sku)).filter(Boolean));
    const sobra = linhas.reduce((acc, p, i) => {
        if (!usados.has(i) && skusPlanilha.has(normTxt(p.sku))) acc.push(i);
        return acc;
    }, []);
    planilha.forEach((p, i) => {
        if (par[i] === null && sobra.length > 0) {
            par[i] = sobra.shift();
            usados.add(par[i]);
        }
    });

    // 1) Produtos da planilha, na ordem dela. A identificação (SKU e nome) vem SEMPRE
    //    da Planilha de Produtos — da precificação salva vêm só os números.
    const daPlanilha = planilha.map((p, i) => ({
        ...PRECIF_LINHA_VAZIA,
        ...(par[i] !== null ? linhas[par[i]] : {}),
        sku:       p.sku ?? '',
        descricao: p.produto ?? '',
    }));
    // 2) Linhas salvas que nenhum produto da planilha reclamou — tipicamente adicionadas
    //    manualmente no Simulador. Sem isso, o produto avulso sumia ao reabrir o modal.
    const avulsos = linhas
        .filter((_, i) => !usados.has(i))
        .map(p => ({ ...PRECIF_LINHA_VAZIA, ...p }));

    return [...daPlanilha, ...avulsos];
}

/**
 * Testa se uma linha de precificação veio da Planilha de Produtos. Para essas, SKU e
 * nome são reflexo da planilha (o merge os re-deriva a cada abertura) — o Simulador
 * os mostra travados apontando onde editar, em vez de deixar renomear em vão.
 *
 * Produto novo do Simulador (SKU e nome em branco) nunca é "da planilha": senão
 * nasceria travado e o cliente não conseguiria batizá-lo.
 */
export function criarTesteDaPlanilha(planilhaProdutos) {
    const ids = new Set(produtosPreenchidos(planilhaProdutos).map(p => chave(p.sku, p.produto)));
    return (row) => {
        const k = chave(row?.sku, row?.descricao);
        return k !== '' && ids.has(k);
    };
}
