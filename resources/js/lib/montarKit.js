// ═══════════════════════════════════════════════════════════════════════
// "Montar kit" do Planejamento (09/10/2026) — estado da escolha e textos. SÓ apresentação.
//
// A fase (Combo/Kit/Combit), o nome e o SKU sugeridos, o "já existe", a logística, o frete
// estimado, o custo e o "Terá estoque?" vêm prontos do servidor (rota montar/previa,
// `MontagemManualDeOferta`). Aqui só se guarda o que a pessoa escolheu e se monta o pedido.
// ═══════════════════════════════════════════════════════════════════════

/** Teto de itens, o mesmo do servidor (`ChaveDeComposicao::MAXIMO_COMPONENTES`); a prévia o devolve em `limites`. */
export const MAX_COMPONENTES_PADRAO = 6;

/** Quantos itens a lista de escolha mostra de uma vez; o resto se acha refinando a busca. */
export const LIMITE_DA_LISTA = 120;

export const ROTULO_FASE_MONTAGEM = { combo: 'Combo', kit: 'Kit', combit: 'Combit' };

/**
 * A metodologia das ofertas montadas, sempre à vista na janela (10/10/2026): os três tipos, com as
 * MESMAS palavras do "Como funciona" do Planejamento, e como se monta cada um. Quem decide o tipo é
 * o servidor, pela composição (`RegrasDaMontagem::fase`, a regra da Lista SKUs); aqui só se explica.
 */
export const METODOLOGIA = [
    { fase: 'combo', numero: 2, oQueE: 'Mesmo produto, mais unidades', comoMontar: '1 produto, com quantidade 2 ou mais' },
    { fase: 'kit', numero: 3, oQueE: 'Produtos diferentes juntos', comoMontar: '2 ou mais produtos, 1 unidade de cada' },
    { fase: 'combit', numero: 4, oQueE: 'Kit com mais unidades de um item', comoMontar: '2 ou mais produtos, algum com 2 ou mais unidades' },
];

/** O botão de criar diz o que nasce: "Criar Combit"; sem tipo ainda, "Criar oferta". */
export const textoDoCriar = (fase) => (ROTULO_FASE_MONTAGEM[fase] ? `Criar ${ROTULO_FASE_MONTAGEM[fase]}` : 'Criar oferta');

/** "Combit criado"; sem o tipo na resposta, "Oferta criada". */
export const textoDaCriada = (fase) => (ROTULO_FASE_MONTAGEM[fase] ? `${ROTULO_FASE_MONTAGEM[fase]} criado` : 'Oferta criada');

/** Chave estável de um item escolhido: a variação ('v12') ou a oferta sem produto ('o55'). */
export const chaveDoItem = (item) => (item.variacao_id ? `v${item.variacao_id}` : `o${item.oferta_id}`);

const limitar = (n) => Math.min(999, Math.max(1, Math.trunc(Number(n) || 1)));

/**
 * Acrescenta um item. Quem já está ganha +1 na quantidade (nunca duas linhas do mesmo item);
 * passar do teto é recusado e a lista fica como estava.
 *
 * @returns {{ itens: Array, recusou: boolean }}
 */
export function adicionarItem(itens, item, max = MAX_COMPONENTES_PADRAO) {
    const chave = chaveDoItem(item);
    if (itens.some((i) => chaveDoItem(i) === chave)) {
        return { itens: itens.map((i) => (chaveDoItem(i) === chave ? { ...i, quantidade: limitar(i.quantidade + 1) } : i)), recusou: false };
    }
    if (itens.length >= max) return { itens, recusou: true };

    return { itens: [...itens, { ...item, quantidade: limitar(item.quantidade ?? 1) }], recusou: false };
}

export const mudarQuantidade = (itens, chave, quantidade) => itens.map((i) => (chaveDoItem(i) === chave ? { ...i, quantidade: limitar(quantidade) } : i));

export const removerItem = (itens, chave) => itens.filter((i) => chaveDoItem(i) !== chave);

/**
 * O corpo da prévia e da gravação: de cada item, só a variação (ou a oferta sem produto) e a
 * quantidade. Nome e SKU vão só quando a pessoa os editou — sem eles, valem os sugeridos.
 */
export function corpoDaMontagem(itens, { nome = null, sku = null } = {}) {
    const corpo = {
        componentes: itens.map((i) => (i.variacao_id
            ? { variacao_id: i.variacao_id, quantidade: i.quantidade }
            : { oferta_id: i.oferta_id, quantidade: i.quantidade })),
    };
    if (nome !== null && String(nome).trim() !== '') corpo.nome = String(nome).trim();
    if (sku !== null && String(sku).trim() !== '') corpo.sku = String(sku).trim();

    return corpo;
}

const unidades = (n) => `${n} ${n === 1 ? 'unidade' : 'unidades'}`;

/**
 * "Terá estoque?" em português, do resumo do servidor (`estoque.unidades`, `limitante`, `sem_informacao`).
 *
 * @returns {{ tom: 'ok'|'alerta'|'neutro', titulo: string, detalhe: string|null }}
 */
export function textoDoEstoque(estoque) {
    if (! estoque) return { tom: 'neutro', titulo: 'Escolha os produtos para ver o estoque.', detalhe: null };

    const { unidades: n, limitante, sem_informacao: sem = [] } = estoque;
    if (n === null || n === undefined) {
        return {
            tom: 'neutro',
            titulo: 'Estoque não informado',
            detalhe: sem.length > 0 ? `Falta o estoque de ${sem.join(', ')}. Informe na ficha do produto.` : null,
        };
    }
    if (n === 0) {
        return {
            tom: 'alerta',
            titulo: 'Sem estoque para montar hoje',
            detalhe: limitante ? `${limitante.nome} tem ${limitante.estoque} em estoque e esta oferta pede ${limitante.por_unidade}.` : null,
        };
    }

    return {
        tom: 'ok',
        titulo: `Terá estoque: dá para montar ${unidades(n)}`,
        detalhe: limitante ? `Quem limita: ${limitante.nome} (${limitante.estoque} em estoque, ${limitante.por_unidade} por unidade).` : null,
    };
}

const normalizar = (t) => String(t ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

/**
 * O catálogo da janela filtrado pela busca (nome, SKU, cor), sem os já escolhidos, até `limite`
 * linhas somando produtos e ofertas sem produto.
 *
 * @returns {{ produtos: Array, avulsas: Array, cortados: number }}
 */
export function filtrarCatalogo(catalogo, busca, escolhidos = [], limite = LIMITE_DA_LISTA) {
    const termo = normalizar(busca);
    const ja = new Set(escolhidos.map(chaveDoItem));
    const casa = (...textos) => termo === '' || textos.some((t) => normalizar(t).includes(termo));

    const produtos = [];
    for (const p of catalogo?.produtos ?? []) {
        const variacoes = (p.variacoes ?? []).filter((v) => ! ja.has(`v${v.variacao_id}`)
            && (casa(p.nome, p.familia, p.tipo_nome) || casa(v.sku, v.valor)));
        if (variacoes.length > 0) produtos.push({ ...p, variacoes });
    }
    const avulsas = (catalogo?.avulsas ?? []).filter((o) => ! ja.has(`o${o.oferta_id}`) && casa(o.sku, o.nome));

    let restante = limite;
    const produtosCortados = [];
    for (const p of produtos) {
        if (restante <= 0) break;
        const variacoes = p.variacoes.slice(0, restante);
        restante -= variacoes.length;
        produtosCortados.push({ ...p, variacoes });
    }
    const avulsasCortadas = avulsas.slice(0, Math.max(0, restante));
    const total = produtos.reduce((s, p) => s + p.variacoes.length, 0) + avulsas.length;
    const mostrados = produtosCortados.reduce((s, p) => s + p.variacoes.length, 0) + avulsasCortadas.length;

    return { produtos: produtosCortados, avulsas: avulsasCortadas, cortados: total - mostrados };
}

/** O primeiro item que um produto oferece (o "Montar kit" aberto pela ficha ou pela lista de Produtos). */
export function itemInicialDoProduto(catalogo, produtoId) {
    const p = (catalogo?.produtos ?? []).find((x) => String(x.produto_id) === String(produtoId));
    const v = p?.variacoes?.[0];

    return v ? { variacao_id: v.variacao_id, nome: rotuloDaVariacao(p, v), sku: v.sku, quantidade: 1 } : null;
}

/** "Cadeira Polo — Natural" (o mesmo separador do nome da oferta simples). */
export const rotuloDaVariacao = (produto, variacao) => (variacao.valor ? `${produto.nome} — ${variacao.valor}` : produto.nome);
