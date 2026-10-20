// ─── Vitrine de produtos (20/10/2026, protótipo atrás de `publicador.vitrine`) ───
//
// ESM puro (sem JSX) para o `node --test` importar. O servidor
// (`VitrineDeProdutosService`) manda um cartão por produto já com o grupo
// (prontos | pendencia | publicados), o chip, as cores com a foto de cada uma,
// os preços mín–máx por tipo, a fase e se dá para marcar. Aqui moram só as
// contas da tela: o filtro, as famílias, o preço escrito, o "há quanto tempo"
// e o resumo da seleção.

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

/** O número da fase (1 Simples, 2 Combo, 3 Kit, 4 Combit): o do servidor, ou o que o selo "Fase N · …" diz. */
export function numeroDaFase(cartao) {
    const n = numero(cartao?.fase_numero);
    if (n !== null && n >= 1) return Math.floor(n);
    const doSelo = /^Fase\s+(\d+)/.exec(String(cartao?.fase ?? ''));

    return doSelo ? Number(doSelo[1]) : 1;
}

/** O rótulo da pílula: "Fase 1", "Fase 2"… */
export const rotuloDaFase = (cartao) => `Fase ${numeroDaFase(cartao)}`;

const ehPlanejada = (face) => face?.planejado === true;

/** A face aparece em quais cartões: `familias` do servidor (o Kit e o Combit, em cada componente), senão o próprio. */
function familiasDaFace(face) {
    const ids = (Array.isArray(face.familias) ? face.familias : [face.familia_id]).map(numero).filter((n) => n !== null);

    return ids.length > 0 ? [...new Set(ids)] : [Number(face.produto_id)];
}

/** Fase 1, 2, 3, 4; no combo, o de menos unidades primeiro; a face de espera depois da real de mesmo N. */
const ordemDasFaces = (a, b) => (numeroDaFase(a) - numeroDaFase(b))
    || ((numero(a.quantidade) ?? 0) - (numero(b.quantidade) ?? 0))
    || (Number(ehPlanejada(a)) - Number(ehPlanejada(b)))
    || ((numero(a.produto_id) ?? 0) - (numero(b.produto_id) ?? 0));

/**
 * As FAMÍLIAS da vitrine (20/10/2026, decisões do usuário): um cartão por produto, com as fases dele lado a lado —
 * Fase 1 (o produto), Fase 2 (cada combo: o mesmo produto × N), Fase 3 (cada kit que o leva) e Fase 4 (cada
 * combit). O Kit e o Combit chegam com o cartão de CADA componente em `familias`, então a mesma face aparece nos
 * dois. `planejados` são os combos do Portal ainda sem anúncio: entram como face de espera só em família que está
 * na tela. Com filtro, entra a família que tiver alguma fase REAL nele, e `inicial` aponta a 1ª dessas fases (o
 * cartão abre nela). A ordem das famílias é a do servidor.
 *
 * @return {Array<{id: number, fases: Array<object>, inicial: number}>}
 */
export function familiasDaVitrine(cartoes, filtro = 'todos', planejados = []) {
    const porFamilia = new Map();
    const juntar = (id, face) => {
        if (! porFamilia.has(id)) porFamilia.set(id, []);
        porFamilia.get(id).push(face);
    };
    for (const c of listaSegura(cartoes)) {
        for (const id of familiasDaFace(c)) juntar(id, c);
    }
    for (const p of Array.isArray(planejados) ? planejados : []) {
        const id = p && typeof p === 'object' ? numero(p.familia_id) : null;
        if (id !== null && porFamilia.has(id)) juntar(id, { ...p, planejado: true });
    }

    const saida = [];
    for (const [id, fases] of porFamilia) {
        fases.sort(ordemDasFaces);
        const inicial = filtro === 'todos' || ! CHAVES.includes(filtro) ? 0 : fases.findIndex((f) => ! ehPlanejada(f) && f.grupo === filtro);
        if (inicial !== -1) saida.push({ id, fases, inicial });
    }

    return saida;
}

/**
 * As pílulas do cartão: uma por fase (Fase 1, Fase 2…), na ordem das faces, com a 1ª face de cada uma, quantas faces
 * ela tem (o Combo de 2 e o de 4 são duas faces da Fase 2) e se alguma está com pendência.
 *
 * @return {Array<{numero: number, rotulo: string, primeira: number, indices: number[], pendencia: boolean}>}
 */
export function pilulasDasFases(faces) {
    const saida = [];
    (Array.isArray(faces) ? faces : []).forEach((f, i) => {
        const n = numeroDaFase(f);
        const ultima = saida[saida.length - 1];
        if (ultima && ultima.numero === n) {
            ultima.indices.push(i);
            ultima.pendencia = ultima.pendencia || f?.grupo === 'pendencia';
        } else {
            saida.push({ numero: n, rotulo: `Fase ${n}`, primeira: i, indices: [i], pendencia: f?.grupo === 'pendencia' });
        }
    });

    return saida;
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
