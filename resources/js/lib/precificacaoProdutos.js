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

/**
 * Campos que a família compartilha. São os números que produzem o preço — SKU e
 * descrição ficam de fora de propósito: identificam a variação, não a família.
 */
export const CAMPOS_PRECIFICACAO = [
    'custo', 'frete_classico', 'frete_premium',
    'imposto_individual', 'mc_individual', 'll_individual',
];

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
    //    O vínculo de variação (grupo/eixo/valor) também é reflexo da planilha: é o que
    //    permite ao Simulador agrupar os SKUs por família em vez de listar tudo plano.
    const daPlanilha = planilha.map((p, i) => ({
        ...PRECIF_LINHA_VAZIA,
        ...(par[i] !== null ? linhas[par[i]] : {}),
        sku:       p.sku ?? '',
        descricao: p.produto ?? '',
        variacao_grupo: p.variacao_grupo ?? '',
        variacao_tipo:  p.variacao_tipo ?? '',
        variacao_valor: p.variacao_valor ?? '',
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

// ═══════════════════════════════════════════════════════════════════════
// Famílias — agrupamento dos SKUs para o Simulador.
//
// Um cliente de 6 produtos em 8 cores tem 48 linhas na planilha, e listar as 48
// planas não mostra 48 informações: mostra o mesmo nome 8 vezes e esconde no
// sufixo do SKU a única coisa que difere. A família traz de volta a estrutura
// que a planilha já conhece.
// ═══════════════════════════════════════════════════════════════════════

/**
 * Agrupa as linhas em famílias, preservando a ordem de entrada.
 *
 * Chave: `variacao_grupo` quando o cliente o preencheu; senão o nome do produto,
 * que é o que salva o caso comum — quem cadastrou as cores como linhas soltas,
 * sem nunca tocar no campo de grupo. Linha sem grupo e sem nome (avulso recém
 * criado no Simulador) é família de si mesma: fundir os anônimos num só faria
 * um esconder o outro.
 *
 * @returns {Array<{chave: string, nome: string, eixo: string, idxs: number[]}>}
 */
export function agruparFamilias(rows) {
    const familias = [];
    const pos = new Map();
    (rows ?? []).forEach((r, i) => {
        const grupo = normTxt(r?.variacao_grupo);
        const nome  = normTxt(r?.descricao);
        // Prefixo separa os espaços de chave: grupo "azul" e nome "azul" não se misturam.
        const k = grupo ? `g:${grupo}` : (nome ? `n:${nome}` : `i:${i}`);
        if (pos.has(k)) { familias[pos.get(k)].idxs.push(i); return; }
        pos.set(k, familias.length);
        familias.push({
            chave: k,
            nome:  String(r?.descricao ?? '').trim(),
            eixo:  String(r?.variacao_tipo ?? '').trim(),
            idxs:  [i],
        });
    });
    return familias;
}

/**
 * Contagem de variações em português correto.
 *
 * Concatenar 's' no eixo produzia "8 variaçãos" e "8 cors" — nenhuma das duas
 * pluraliza por sufixo simples. O eixo entra como qualificador, sem flexão: é o
 * único jeito de não precisar de uma regra de plural por palavra que o cliente
 * possa digitar no campo livre da planilha.
 */
export function contarVariacoes(n, eixo) {
    const base = `${n} ${n === 1 ? 'variação' : 'variações'}`;
    const e = String(eixo ?? '').trim();
    return e ? `${base} de ${e.toLowerCase()}` : base;
}

/** Rótulo curto da variação dentro da família: o valor da variação, o SKU, ou a posição. */
export function rotuloVariacao(row, idx) {
    const valor = String(row?.variacao_valor ?? '').trim();
    if (valor) return valor;
    const sku = String(row?.sku ?? '').trim();
    if (sku) return sku;
    return `#${idx + 1}`;
}

/** Vazio só é igual a vazio; preenchido compara por número quando os dois são numéricos. */
const mesmoValor = (a, b) => {
    const va = String(a ?? '').trim();
    const vb = String(b ?? '').trim();
    if (va === '' || vb === '') return va === vb;
    const na = parseFloat(va);
    const nb = parseFloat(vb);
    return (Number.isNaN(na) || Number.isNaN(nb)) ? va === vb : na === nb;
};

/**
 * A família toda tem os mesmos números? É o que decide se a edição abre em modo
 * "toda a família" (o caso comum: 8 cores do mesmo custo) ou "só esta variação"
 * (alguém já diferenciou à mão, e o modo massa apagaria esse trabalho).
 */
export function familiaUniforme(rows, idxs, campos = CAMPOS_PRECIFICACAO) {
    if (!idxs || idxs.length < 2) return true;
    const base = (rows ?? [])[idxs[0]] ?? {};
    return idxs.every(i => campos.every(c => mesmoValor((rows ?? [])[i]?.[c], base[c])));
}

/** Escreve um campo em todas as linhas indicadas. Devolve array novo (não muta). */
export function aplicarNaFamilia(rows, idxs, campo, valor) {
    const alvo = new Set(idxs ?? []);
    return (rows ?? []).map((r, i) => alvo.has(i) ? { ...r, [campo]: valor } : r);
}

/** Copia os números de `origemIdx` para as outras linhas da família. Devolve array novo. */
export function replicarPrecificacao(rows, idxs, origemIdx, campos = CAMPOS_PRECIFICACAO) {
    const lista  = rows ?? [];
    const origem = lista[origemIdx];
    if (!origem) return lista;
    const alvo = new Set(idxs ?? []);
    return lista.map((r, i) => {
        if (i === origemIdx || !alvo.has(i)) return r;
        const novo = { ...r };
        campos.forEach(c => { novo[c] = origem[c] ?? ''; });
        return novo;
    });
}
