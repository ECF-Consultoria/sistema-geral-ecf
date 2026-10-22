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

/**
 * O filtro de entrada: "Todos", ou o pedido na URL. Demo 23/10 (N16): abrir em "Prontos para revisar"
 * escondia o produto que acabou de chegar do Portal (ele nasce "Com pendência" enquanto a IA prepara).
 * `contagens` fica na assinatura para quem já chama assim.
 */
export function filtroInicial(contagens, pedido = null) {
    return CHAVES.includes(pedido) ? pedido : 'todos';
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

/** Quem a face é: o produto do Publicador, ou a chave da face de espera (o combo planejado ainda não tem produto). */
const chaveDaFace = (face) => (ehPlanejada(face) ? `espera:${face?.chave ?? ''}` : `produto:${numero(face?.produto_id) ?? ''}`);

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
 * dois. `planejados` são os combos ACEITOS no Planejamento que ainda não são fase: entram como face de espera só em família que está
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

    // Dois cartões nunca abrem na MESMA face (22/10/2026, o usuário: "por que veio dois?"). Com o filtro "Prontos", a
    // mesa e a cadeira sozinhas não estavam prontas, e os dois cartões abriam no mesmo Combit da Mesa + 4 Cadeiras.
    // O cartão abre na 1ª face que serve e que nenhum cartão anterior abriu; sem nenhuma, ele não entra (a face já
    // está à vista no outro). Sem filtro, a 1ª face é o próprio produto — muda só a família que não o tem.
    const abertas = new Set();
    const saida = [];
    for (const [id, fases] of porFamilia) {
        fases.sort(ordemDasFaces);
        const serve = (f) => filtro === 'todos' || ! CHAVES.includes(filtro) || (! ehPlanejada(f) && f.grupo === filtro);
        const inicial = fases.findIndex((f) => serve(f) && ! abertas.has(chaveDaFace(f)));
        if (inicial !== -1) {
            abertas.add(chaveDaFace(fases[inicial]));
            saida.push({ id, fases, inicial });
        }
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

/**
 * O aviso das imagens da IA que o juiz reprovou (21/10/2026, o usuário: "tem que ter o aviso quando for reprovado, e o
 * motivo"). `valor` é o `ia_reprovada` do servidor; nulo quando não há o que avisar ou o dado veio estragado.
 */
export function avisoDaIa(valor) {
    if (! valor || typeof valor !== 'object' || Array.isArray(valor)) return null;
    const reprovadas = numero(valor.reprovadas) ?? 0;
    if (reprovadas <= 0) return null;
    const todas = valor.todas === true;
    const motivos = (Array.isArray(valor.motivos) ? valor.motivos : []).filter((m) => typeof m === 'string' && m.trim() !== '').map((m) => m.trim());

    return {
        todas,
        motivos,
        titulo: todas ? 'Imagens da IA reprovadas' : (reprovadas === 1 ? '1 imagem da IA reprovada' : `${reprovadas} imagens da IA reprovadas`),
        resumo: todas ? 'O anúncio ficou com a foto do cliente.' : 'As outras entraram no anúncio.',
    };
}

/** O que falta resolver no cartão (`resolver` do servidor): só itens com texto e link; no máximo 5. */
export function itensParaResolver(cartao) {
    const lista = Array.isArray(cartao?.resolver) ? cartao.resolver : [];

    return lista
        .filter((r) => r && typeof r.texto === 'string' && r.texto.trim() !== '' && typeof r.url === 'string' && r.url !== '')
        .slice(0, 5)
        .map((r) => ({ texto: r.texto.trim(), url: r.url }));
}

// 22/10/2026 (demo, R4): o produto que acabou de chegar do Portal só aparecia com F5 — a vitrine só se relia
// enquanto algum cartão estava "Atualizando…". Fora isso ela passa a se reler a cada 25 s, e não mais rápido: o
// servidor refaz até 40 resumos por visita.
export const RELEITURA_OCIOSA_MS = 25 * 1000;

/**
 * Releitura da vitrine enquanto a aba está VISÍVEL: liga o relógio, pausa quando a aba fica oculta e, ao voltar,
 * relê na hora e religa. `documento` = o `document` do navegador (injetado para o teste). Devolve o desligar.
 */
export function releituraEnquantoVisivel({ reler, documento, intervaloMs = RELEITURA_OCIOSA_MS, agendar = setInterval, cancelar = clearInterval }) {
    if (typeof reler !== 'function' || ! documento) return () => {};
    let relogio = null;
    const oculta = () => documento.visibilityState === 'hidden';
    const ligar = () => {
        if (relogio === null && ! oculta()) relogio = agendar(reler, intervaloMs);
    };
    const parar = () => {
        if (relogio !== null) cancelar(relogio);
        relogio = null;
    };
    const aoMudar = () => {
        if (oculta()) {
            parar();
        } else if (relogio === null) {
            reler();
            ligar();
        }
    };

    ligar();
    documento.addEventListener('visibilitychange', aoMudar);

    return () => {
        parar();
        documento.removeEventListener('visibilitychange', aoMudar);
    };
}
