// ─── Trilha de produtos — a página do anúncio "como o comprador vê" (05/11/2026) ──
//
// As contas PURAS da tela cheia (`Components/Trilha/PaginaDoAnuncio.jsx`), aberta
// pelo [Ver como o comprador vê] das duas portas: a gaveta da prévia da EQUIPE
// (`Revisao/PreviaDoAnuncio.jsx`) e a de aprovar do CLIENTE
// (`Cliente/AprovarAnuncio.jsx`). O usuário pediu a prévia "igualzinha" à página do
// item no canal de venda, como a do Publicador — e o cliente também a vê (decisão
// dele, 05/11/2026).
//
// O desenho é CÓPIA da página do Publicador (etapa 5 do editor): a trilha não
// importa nada de lá (ela vai para outro sistema). Os dados são os da prévia da
// trilha (`PreviaNaTrilha`, o MESMO plano da publicação), nas duas portas: a do
// cliente tem os mesmos campos que a página usa.
//
// Sem import nenhum: o pedaço do cliente a leva (como `trilhaAprovacao.js`).

const lista = (v) => (Array.isArray(v) ? v : []);

const numero = (v) => (v === null || v === undefined || v === '' || typeof v === 'boolean' || !Number.isFinite(Number(v)) ? null : Number(v));

const texto = (v) => (typeof v === 'string' && v.trim() !== '' ? v.trim() : null);

/** Quantos itens da ficha aparecem em "O que você precisa saber sobre este produto" (o mesmo da página do Publicador). */
export const DESTAQUES_A_VISTA = 5;

/** O nome da variação quando o servidor não manda `eixos` (a trilha chama a variação de "Cor"). */
export const NOME_DA_VARIACAO = 'Cor';

/** "1234.5" → { inteiro: '1.234', centavos: '50' }: os centavos ficam pequenos, no alto. Nulo = sem preço. */
export function partesDoPreco(valor) {
    const n = numero(valor);
    if (n === null || n < 0) return null;
    const [inteiro, centavos] = n.toFixed(2).split('.');

    return { inteiro: inteiro.replace(/\B(?=(\d{3})+(?!\d))/g, '.'), centavos };
}

/** "R$ 1.234,56" (vazio sem preço). */
export function reais(valor) {
    const p = partesDoPreco(valor);

    return p === null ? '' : `R$ ${p.inteiro},${p.centavos}`;
}

/** Os tipos (Clássico, Premium) que têm anúncio, na ordem do servidor. */
export function tiposDaPagina(previa) {
    const anuncios = lista(previa?.anuncios);

    return lista(previa?.tipos).filter((t) => texto(t?.chave) && anuncios.some((a) => a?.tipo === t.chave));
}

/** As variações que o tipo tem, na ordem do servidor. */
export function variacoesDaPagina(previa, tipo) {
    const doTipo = lista(previa?.anuncios).filter((a) => a?.tipo === tipo);

    return lista(previa?.variantes).filter((v) => texto(v?.chave) && doTipo.some((a) => a?.variante === v.chave));
}

/** A escolha que existe: o tipo e a variação pedidos; sem eles, o 1º tipo e a 1ª variação dele. */
export function escolhaDaPagina(previa, escolha = {}) {
    const tipos = tiposDaPagina(previa);
    const tipo = tipos.find((t) => t.chave === escolha?.tipo)?.chave ?? tipos[0]?.chave ?? null;
    const variacoes = variacoesDaPagina(previa, tipo);
    const variante = variacoes.find((v) => v.chave === escolha?.variante)?.chave ?? variacoes[0]?.chave ?? null;

    return { tipo, variante };
}

/** O anúncio da escolha (nulo: a prévia não tem anúncio). */
export function anuncioDaPagina(previa, escolha = {}) {
    const e = escolhaDaPagina(previa, escolha);

    return lista(previa?.anuncios).find((a) => a?.tipo === e.tipo && a?.variante === e.variante) ?? null;
}

/** As fotos na ordem do anúncio (a 1ª é a capa), só as que têm endereço. */
export const fotosDaPagina = (anuncio) => lista(anuncio?.fotos).filter((f) => f && texto(f.url));

/** A miniatura de uma foto: a versão pequena quando o servidor a manda (só a equipe), senão a própria foto. */
export const miniaturaDaFoto = (foto) => texto(foto?.miniatura) ?? texto(foto?.url);

/** A capa da variação no tipo escolhido — a bolinha do seletor de variação. */
export const capaDaVariacao = (previa, tipo, variante) => fotosDaPagina(anuncioDaPagina(previa, { tipo, variante }))[0] ?? null;

/** O título como o comprador lê: o completo (com a variação no fim) quando o servidor o manda; senão o do anúncio. */
export const tituloDaPagina = (anuncio) => texto(anuncio?.titulo_completo) ?? texto(anuncio?.titulo);

/** "Novo" quando não vem (o plano só publica produto novo por padrão). */
export const condicaoDaPagina = (anuncio) => texto(anuncio?.condicao) ?? 'Novo';

/**
 * O preço da página: o do anúncio, cheio. A promoção da Central é só uma TENTATIVA depois de publicar (o canal costuma
 * recusar a de anúncio novo), então a página não a mostra como certa. As parcelas sem juros (o Premium) acompanham.
 *
 * @return {{ atual: ?number, parcelas: ?{vezes: number, valor: number} }}
 */
export function precoDaPagina(anuncio) {
    const p = anuncio?.parcelas?.sem_promocao;
    const vezes = numero(p?.vezes);
    const valor = numero(p?.valor);

    return { atual: numero(anuncio?.preco), parcelas: vezes !== null && vezes > 1 && valor !== null ? { vezes, valor } : null };
}

/** "em 10x R$ 98,99 sem juros" (nulo sem parcelas). */
export const textoDasParcelasDaPagina = (parcelas) => (parcelas ? `em ${parcelas.vezes}x ${reais(parcelas.valor)} sem juros` : null);

/** A ficha agrupada, só com os itens que têm nome e valor (grupo sem item some). */
export function gruposDaPagina(anuncio) {
    return lista(anuncio?.caracteristicas)
        .map((g) => ({
            grupo: texto(g?.grupo) ?? 'Características',
            itens: lista(g?.itens)
                .map((i) => ({ nome: texto(i?.nome), valor: texto(i?.valor === null || i?.valor === undefined ? null : String(i.valor)) }))
                .filter((i) => i.nome && i.valor),
        }))
        .filter((g) => g.itens.length > 0);
}

/** "O que você precisa saber sobre este produto": os primeiros itens do 1º grupo da ficha. */
export const destaquesDaPagina = (grupos) => (lista(grupos)[0]?.itens ?? []).slice(0, DESTAQUES_A_VISTA);

/**
 * O estoque da caixa de compra. Nulo quando o plano não diz (a página não afirma "sem estoque" do que não sabe).
 *
 * @return {?{ disponivel: boolean, quantidade: number }}
 */
export function estoqueDaPagina(anuncio) {
    const n = numero(anuncio?.estoque);
    if (n === null || n < 0) return null;

    return { disponivel: n > 0, quantidade: Math.floor(n) };
}

/** "(12 disponíveis)" / "(1 disponível)". */
export const textoDoEstoque = (estoque) => (estoque?.disponivel ? `(${estoque.quantidade} ${estoque.quantidade === 1 ? 'disponível' : 'disponíveis'})` : null);

/**
 * O frete da caixa de compra, pelo modo do plano (prazo e valor só o canal sabe, pelo CEP). Frete grátis é o
 * comprador não pagar a entrega (o vendedor paga).
 *
 * @return {{ chave: string, titulo: string, detalhe: string, gratis: boolean }}
 */
export function freteDaPagina(envio) {
    const modo = texto(envio?.modo) ?? 'me2';
    if (envio?.frete_gratis === true) {
        return { chave: 'gratis', titulo: 'Frete grátis', detalhe: 'Saiba os prazos de entrega e as formas de envio.', gratis: true };
    }
    if (modo === 'me2') {
        return { chave: 'comprador', titulo: 'Calcular o frete', detalhe: 'O comprador paga o frete, calculado pelo CEP.', gratis: false };
    }
    if (modo === 'me1' || modo === 'custom') {
        return {
            chave: modo,
            titulo: 'Envio pelo vendedor',
            detalhe: modo === 'me1' ? 'Pela transportadora do vendedor, com o custo que ele define.' : 'O vendedor define o custo do frete.',
            gratis: false,
        };
    }

    return { chave: modo, titulo: 'Entrega a combinar com o vendedor', detalhe: 'O valor e a entrega são combinados depois da compra.', gratis: false };
}

/** "Cor", "Cor / Tamanho" — o nome da variação no seletor da página. */
export const nomeDaVariacao = (previa) => lista(previa?.eixos).map(texto).filter(Boolean).join(' / ') || NOME_DA_VARIACAO;

/** O caminho da categoria, do mais largo ao mais estreito. */
export const caminhoDaPagina = (previa) => lista(previa?.categoria?.caminho).map(texto).filter(Boolean);

export const descricaoDaPagina = (previa) => texto(previa?.descricao);

/** O apelido da loja no "Vendido por" (nulo: a conta ainda não foi lida — a linha não aparece). */
export const vendedorDaPagina = (previa) => texto(previa?.vendedor);

export const garantiaDaPagina = (anuncio) => texto(anuncio?.garantia);

/** "Anúncio Premium · Natural" — o nome da escolha (o título da tela cheia e o rótulo dela). */
export function nomeDaEscolha(previa, escolha = {}) {
    const e = escolhaDaPagina(previa, escolha);
    const tipo = tiposDaPagina(previa).find((t) => t.chave === e.tipo)?.nome ?? null;
    const variacoes = variacoesDaPagina(previa, e.tipo);
    const cor = variacoes.length > 1 ? variacoes.find((v) => v.chave === e.variante)?.rotulo ?? null : null;

    return [tipo ? `Anúncio ${tipo}` : 'Anúncio', texto(cor)].filter(Boolean).join(' · ');
}
