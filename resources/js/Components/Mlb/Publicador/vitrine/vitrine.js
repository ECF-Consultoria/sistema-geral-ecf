// ─── Vitrine de produtos (20/10/2026, protótipo atrás de `publicador.vitrine`) ───
//
// ESM puro (sem JSX) para o `node --test` importar. O servidor
// (`VitrineDeProdutosService`) manda um cartão por produto já com o grupo
// (prontos | pendencia | publicados), o chip, as cores com a foto de cada uma,
// os preços mín–máx por tipo e se dá para marcar. Aqui moram só as contas da
// tela: o filtro, o preço escrito, o "há quanto tempo" e o resumo da seleção.

export const FILTROS_DA_VITRINE = [
    { chave: 'prontos', rotulo: 'Prontos para revisar' },
    { chave: 'pendencia', rotulo: 'Com pendência' },
    { chave: 'publicados', rotulo: 'Publicados' },
    { chave: 'todos', rotulo: 'Todos' },
];

const CHAVES = FILTROS_DA_VITRINE.map((f) => f.chave);

/** O filtro de entrada: os prontos, se houver; senão todos. Chave desconhecida também cai em "todos". */
export function filtroInicial(contagens, pedido = null) {
    if (CHAVES.includes(pedido)) return pedido;

    return Number(contagens?.prontos) > 0 ? 'prontos' : 'todos';
}

export const listaSegura = (cartoes) => (Array.isArray(cartoes) ? cartoes.filter((c) => c && typeof c === 'object' && Number.isFinite(Number(c.produto_id))) : []);

export function filtrar(cartoes, filtro) {
    const lista = listaSegura(cartoes);

    return filtro === 'todos' || ! CHAVES.includes(filtro) ? lista : lista.filter((c) => c.grupo === filtro);
}

const numero = (v) => (v === null || v === undefined || v === '' || ! Number.isFinite(Number(v)) ? null : Number(v));

/**
 * As FAMÍLIAS da vitrine (20/10/2026, pedido do usuário): o produto base e os kits dele (Fase 2, 3…) são anúncios
 * diferentes do MESMO produto, então vão num cartão só, com as fases lado a lado. Com filtro, entra a família que
 * tiver alguma fase nele, e `inicial` aponta a 1ª dessas fases (o cartão abre nela). A ordem é a do servidor.
 *
 * @return {Array<{id: number, fases: Array<object>, inicial: number}>}
 */
export function familiasDaVitrine(cartoes, filtro = 'todos') {
    const porFamilia = new Map();
    for (const c of listaSegura(cartoes)) {
        const id = numero(c.familia_id) ?? Number(c.produto_id);
        if (! porFamilia.has(id)) porFamilia.set(id, []);
        porFamilia.get(id).push(c);
    }

    const saida = [];
    for (const [id, fases] of porFamilia) {
        fases.sort((a, b) => ((numero(a.fase_numero) ?? 1) - (numero(b.fase_numero) ?? 1)) || (Number(a.produto_id) - Number(b.produto_id)));
        const inicial = filtro === 'todos' || ! CHAVES.includes(filtro) ? 0 : fases.findIndex((f) => f.grupo === filtro);
        if (inicial !== -1) saida.push({ id, fases, inicial });
    }

    return saida;
}

/** O rótulo curto da fase na pílula do cartão: "Fase 1", "Kit 2"… (o que vem depois do "·" do selo). */
export function rotuloDaFase(cartao) {
    const selo = String(cartao?.fase ?? '');
    const partes = selo.split('·').map((p) => p.trim()).filter(Boolean);

    return partes.length > 1 ? partes[partes.length - 1] : (selo || 'Fase 1');
}

/** "R$ 1.234,56" */
export function reais(valor) {
    const n = numero(valor);
    if (n === null) return '';
    const [inteiro, centavos] = n.toFixed(2).split('.');

    return `R$ ${inteiro.replace(/\B(?=(\d{3})+(?!\d))/g, '.')},${centavos}`;
}

/** O preço de um tipo entre as cores: um valor, ou "mín – máx" quando as cores diferem. Nulo = sem preço. */
export function faixaDePreco(faixa) {
    const min = numero(faixa?.min);
    const max = numero(faixa?.max);
    if (min === null && max === null) return null;
    if (min === null || max === null || Math.abs(min - max) < 0.005) return reais(min ?? max);

    return `${reais(min)} – ${reais(max)}`;
}

/** "há 8 min", "há 2 h", "ontem", "há 3 dias". Nulo = sem data. */
export function tempoDesde(iso, agora = Date.now()) {
    const t = Date.parse(iso ?? '');
    if (! Number.isFinite(t)) return null;
    const min = Math.max(0, Math.floor((agora - t) / 60000));
    if (min < 1) return 'agora há pouco';
    if (min < 60) return `há ${min} min`;
    const h = Math.floor(min / 60);
    if (h < 24) return `há ${h} h`;
    const dias = Math.floor(h / 24);

    return dias === 1 ? 'ontem' : `há ${dias} dias`;
}

/** Quantos produtos e quantos anúncios a seleção leva (só os que ainda dá para marcar). */
export function resumoDaSelecao(cartoes, selecao) {
    const marcados = listaSegura(cartoes).filter((c) => c.selecionavel && selecao?.has?.(Number(c.produto_id)));

    return { produtos: marcados.length, anuncios: marcados.reduce((soma, c) => soma + (numero(c.anuncios) ?? 0), 0), ids: marcados.map((c) => Number(c.produto_id)) };
}

/**
 * A foto grande do cartão: a da cor sob o mouse (quando ela tem foto), senão a capa do produto.
 * Também diz qual cor está em foco (nula = nenhuma) — o rótulo que aparece embaixo do nome.
 */
export function fotoDoCartao(cartao, chaveEmFoco) {
    const cores = Array.isArray(cartao?.cores) ? cartao.cores : [];
    const emFoco = chaveEmFoco === null || chaveEmFoco === undefined ? null : (cores.find((c) => c?.chave === chaveEmFoco) ?? null);

    return { emFoco, url: emFoco?.foto ?? cartao?.foto ?? null };
}

/** "3 cores · 6 anúncios", "2 anúncios", "1 anúncio". */
export function textoDosAnuncios(cartao) {
    const n = numero(cartao?.anuncios) ?? 0;
    const anuncios = n === 1 ? '1 anúncio' : `${n} anúncios`;
    const cores = Array.isArray(cartao?.cores) ? cartao.cores.length : 0;

    return cores > 1 ? `${cores} cores · ${anuncios}` : anuncios;
}
