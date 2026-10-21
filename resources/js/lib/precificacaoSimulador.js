// ═══════════════════════════════════════════════════════════════════════
// Simulador da Precificação do Portal (20/10/2026).
//
// O usuário pediu a Precificação "igual à do onboarding de Polos" (o
// SimuladorPreco de `ImplementacaoPublica.jsx`): catálogo de cartões em cima,
// e embaixo UMA oferta por vez, com o que se gasta de um lado e o preço em
// destaque do outro. E pediu prioridade: o que falta preço na frente, o que
// já tem preço mais escondido.
//
// Aqui só organização e rótulo. O preço, o frete sugerido e a repartição do
// preço ("para onde vai cada real") chegam PRONTOS do servidor
// (`PrecificacaoEstrutura`): recalcular aqui é como o onboarding chegou a
// publicar preço 43% errado (`precificacao-onboarding-duas-telas.md` §1).
// ═══════════════════════════════════════════════════════════════════════

import { rotuloDaFase } from './precificacaoPorFamilia.js';

const lista = (x) => (Array.isArray(x) ? x : []);

/** As exceções por oferta que o servidor aceita (`EstruturaPrecificacao::EXCECOES`). */
export const EXCECOES = ['comissao_classico', 'comissao_premium', 'imposto', 'margem_contribuicao', 'lucro_liquido'];

/** O cliente digita "12,50" — o servidor recebe "12.50". Vazio é "não informado". */
export function paraNumero(texto) {
    if (typeof texto === 'number') return Number.isFinite(texto) ? String(texto) : '';
    const t = String(texto ?? '').trim().replace(/\s|R\$|%/g, '');
    if (t === '') return '';

    return t.includes(',') ? t.replace(/\./g, '').replace(',', '.') : t;
}

export const paraTexto = (n) => (n === null || n === undefined || n === '' ? '' : String(n).replace('.', ','));

/** Reais no campo: sempre com os centavos ("24,90", não "24,9"). Vazio continua vazio. */
export const reaisParaTexto = (n) => (n === null || n === undefined || n === '' || ! Number.isFinite(Number(n)) ? '' : Number(n).toFixed(2).replace('.', ','));

export const fmtPct = (n) => `${Number(n ?? 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}%`;

/**
 * O que se manda para gravar uma oferta — a linha inteira, como a tela da Precificação faz (o servidor grava
 * custo, fretes e exceções juntos). Oferta ligada a produto não manda custo: ele mora na variação.
 */
export function dadosParaGravar({ doProduto, custo, freteClassico, fretePremium, excecoes }) {
    return {
        ...(doProduto ? {} : { custo: paraNumero(custo) }),
        frete_classico: paraNumero(freteClassico),
        frete_premium: paraNumero(fretePremium),
        ...Object.fromEntries(EXCECOES.map((k) => [k, paraNumero(excecoes?.[k])])),
    };
}

/** Mudou alguma coisa em relação ao que o servidor tem? Compara número com número ("12,5" = 12.5). */
export function mudouDoGravado(dados, gravado) {
    const norma = (v) => (v === '' || v === null || v === undefined ? '' : String(Number(v)));

    return Object.keys(dados).some((k) => norma(dados[k]) !== norma(gravado?.[k]));
}

/** A situação de uma oferta, no texto do simulador. */
export const PENDENCIAS = {
    sem_custo: 'Sem custo',
    sem_frete: 'Sem frete',
    impossivel: 'Conta impossível',
};

const ROTULO_DO_CAMPO = {
    custo: 'custo', frete_classico: 'frete', frete_premium: 'frete', comissao_classico: 'comissão', comissao_premium: 'comissão',
    imposto: 'imposto', margem_contribuicao: 'margem', lucro_liquido: 'lucro',
};

/**
 * O que cada variação tem de diferente da REFERÊNCIA (a primeira do produto), para o "Todas" dizer por que
 * o preço não sai igual (21/10: "coloco o custo para todas e não precifica igual" — a Verde tinha imposto
 * próprio). Compara o custo (do formulário, por `custoDe`), os dois fretes e as exceções gravadas.
 *
 * @param {Array<{oferta: object, calculo: object}>} itens
 * @param {{oferta: object, calculo: object}} referencia
 * @param {(item: object) => string|number|null} custoDe
 * @returns {Array<{item: object, campos: string[]}>}  só as que diferem; `campos` sem repetição
 */
export function diferencasDaReferencia(itens, referencia, custoDe) {
    if (! referencia) return [];
    const valores = (i) => ({
        custo: custoDe(i),
        frete_classico: i.calculo?.frete_classico, frete_premium: i.calculo?.frete_premium,
        ...Object.fromEntries(EXCECOES.map((k) => [k, i.calculo?.excecoes?.[k]])),
    });
    const ref = valores(referencia);

    return lista(itens).filter((i) => i !== referencia).flatMap((i) => {
        const v = valores(i);
        const campos = [...new Set(Object.keys(ref).filter((k) => valorComum([ref[k], v[k]]) === null).map((k) => ROTULO_DO_CAMPO[k]))];

        return campos.length > 0 ? [{ item: i, campos }] : [];
    });
}

/** As fatias do "Para onde vai cada real", nas cores do simulador do onboarding. */
export const PARTES = [
    ['custo', 'Custo do produto', '#64748b'],
    ['frete', 'Frete', '#0ea5e9'],
    ['comissao', 'Comissão do site', '#a855f7'],
    ['imposto', 'Impostos', '#f97316'],
    ['margem_contribuicao', 'Margem de contribuição', '#38bdf8'],
    ['lucro_liquido', 'Lucro líquido', '#22c55e'],
];

export const pendente = (membro) => !! membro?.calculo?.pendencia;

/** O rótulo curto de uma oferta dentro do cartão: "Unidade", "Combo 2", "Kit". */
export function rotuloDoMembro(oferta) {
    if (oferta?.fase === 'simples') return 'Unidade';

    return rotuloDaFase(oferta) || 'Oferta';
}

/** A lista da ficha (uma linha por variação): sem preço primeiro, na ordem em que vieram; depois as prontas. */
export function pendentesPrimeiro(itens) {
    const l = lista(itens);

    return [...l.filter(pendente), ...l.filter((m) => ! pendente(m))];
}

/**
 * O valor em comum de uma lista de valores (texto digitado ou número do servidor), ou null quando divergem.
 * "180", "180,00" e 180 são o mesmo; vazio só é igual a vazio — `''` e `'0'` são estados diferentes.
 */
export function valorComum(valores) {
    const l = lista(valores);
    if (l.length === 0) return null;
    const norma = (v) => {
        const t = paraNumero(v);
        if (t === '') return '';
        const n = Number(t);

        return Number.isFinite(n) ? String(n) : t;
    };
    const primeiro = norma(l[0]);

    return l.every((v) => norma(v) === primeiro) ? primeiro : null;
}

/**
 * Os cartões da página da Precificação, a partir das famílias que ela já monta (`cartoesDaFamilia`): o
 * mesmo cartão (a unidade e os combos dela; cada conjunto no seu), com a conta de cada oferta e a família.
 */
export function cartoesDasFamilias(familias, porOferta, cartoesDaFamilia) {
    const cartoes = [];
    for (const f of lista(familias)) {
        for (const c of cartoesDaFamilia(f)) {
            if (c.tipo !== 'cartao') continue;
            const membros = c.linhas
                .filter((l) => porOferta?.[l.oferta.id])
                .map((l) => ({ oferta: l.oferta, calculo: porOferta[l.oferta.id] }));
            if (membros.length > 0) cartoes.push({ chave: `${f.chave}:${c.chave}`, membros, familia: f.semFamilia ? null : f.nome });
        }
    }

    return cartoes;
}

/** Prioridade: os cartões com alguma oferta sem preço, na ordem em que vieram, e depois os prontos. */
export function porPrioridade(cartoes) {
    const faltam = [];
    const prontos = [];
    for (const c of lista(cartoes)) (c.membros.some(pendente) ? faltam : prontos).push(c);

    return { faltam, prontos };
}

/** A oferta que o simulador abre: a primeira que falta; sem nenhuma, a primeira de todas. */
export function primeiraParaAbrir(cartoes) {
    const { faltam, prontos } = porPrioridade(cartoes);
    const membro = faltam[0]?.membros.find(pendente) ?? prontos[0]?.membros[0] ?? null;

    return membro?.oferta?.id ?? null;
}

/** A próxima oferta sem preço depois de `atual`, dando a volta; null quando não sobra nenhuma além dela. */
export function proximaPendente(cartoes, atual) {
    const todas = lista(cartoes).flatMap((c) => c.membros);
    const i = todas.findIndex((m) => m.oferta.id === atual);
    for (let passo = 1; passo <= todas.length; passo++) {
        const m = todas[(Math.max(i, 0) + passo) % todas.length];
        if (m.oferta.id !== atual && pendente(m)) return m.oferta.id;
    }

    return null;
}

/** Onde está uma oferta: `{ cartao, membro }` ou null. */
export function localizar(cartoes, id) {
    for (const c of lista(cartoes)) {
        const membro = c.membros.find((m) => m.oferta.id === id);
        if (membro) return { cartao: c, membro };
    }

    return null;
}

/** O preço da promoção do cartão no tipo aberto: `{ min, max }`, ou null quando nenhuma oferta tem preço. */
export function faixaDoCartao(cartao, tipo) {
    const precos = lista(cartao?.membros)
        .filter((m) => ! pendente(m))
        .map((m) => m.calculo?.[tipo]?.minimo)
        .filter((v) => v !== null && v !== undefined && Number.isFinite(Number(v)))
        .map(Number);
    if (precos.length === 0) return null;

    return { min: Math.min(...precos), max: Math.max(...precos) };
}

/** As fatias da barra, com rótulo e cor, a partir das `partes` que o servidor mandou. Sem partes, vazio. */
export function composicao(partes) {
    if (! partes) return [];

    return PARTES.map(([chave, rotulo, cor]) => ({ chave, rotulo, cor, valor: Number(partes[chave] ?? 0) }));
}

/** Quantas ofertas (não cartões) faltam e quantas estão prontas. */
export function contagem(cartoes) {
    const membros = lista(cartoes).flatMap((c) => c.membros);
    const faltam = membros.filter(pendente).length;

    return { total: membros.length, faltam, prontas: membros.length - faltam };
}
