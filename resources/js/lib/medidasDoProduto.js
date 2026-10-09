// ═══════════════════════════════════════════════════════════════════════
// Medidas do produto fora da caixa × volume (09/10/2026): funções puras.
//
// A ficha pede DUAS medidas: a do produto sozinho (bloco próprio, campos da
// categoria — comprimento, largura, altura, profundidade, diâmetro, peso) e a do
// volume (a caixa com o produto dentro, em cm e kg). O servidor manda as do
// produto num grupo marcado `medidas_do_produto`; a tela as tira da Ficha técnica
// e as mostra perto das Variações. Continuam sendo campos da ficha técnica:
// mesmo estado, mesmo PUT.
//
// "Usar as mesmas medidas do produto fora da caixa" (no cartão do volume): sem
// coluna nova. A caixa aparece só com UM volume e com comprimento, largura e
// altura do produto preenchidos; vem MARCADA quando as medidas do volume já são
// iguais às do produto. Marcada, ela copia as medidas (e o peso do produto, se
// houver) e acompanha o que se muda nelas; desmarcada, o volume é livre.
// ═══════════════════════════════════════════════════════════════════════

/** Os ids das medidas do produto, na ordem do bloco (a mesma do volume). */
export const IDS_MEDIDAS_DO_PRODUTO = ['LENGTH', 'WIDTH', 'HEIGHT', 'DEPTH', 'DIAMETER', 'WEIGHT'];

/** O grupo das medidas do produto (o servidor o marca). */
export const ehGrupoDeMedidas = (grupo) => !! grupo?.medidas_do_produto;

/** Os grupos da Ficha técnica, sem o das medidas do produto (que tem bloco próprio). */
export const gruposSemMedidas = (grupos) => (Array.isArray(grupos) ? grupos : []).filter((g) => ! ehGrupoDeMedidas(g));

/** Os campos das medidas do produto que a categoria tem, na ordem do bloco. */
export function camposDeMedidas(grupos) {
    const campos = (Array.isArray(grupos) ? grupos : []).filter(ehGrupoDeMedidas).flatMap((g) => (Array.isArray(g.campos) ? g.campos : []));

    return IDS_MEDIDAS_DO_PRODUTO.map((id) => campos.find((c) => c?.id === id)).filter(Boolean);
}

const PARA_CM = { mm: 0.1, cm: 1, m: 100, '"': 2.54, in: 2.54, ft: 30.48 };
const PARA_KG = { mg: 0.000001, g: 0.001, kg: 1, lb: 0.453592, oz: 0.0283495 };

/** "12,5" / "12.5" / " 60 " → número; vazio ou texto → null. */
export function numeroDigitado(texto) {
    let t = String(texto ?? '').trim();
    if (t === '') return null;
    if (t.includes(',')) t = t.replace(/\./g, '').replace(',', '.');
    const n = Number(t);

    return Number.isFinite(n) && n >= 0 ? n : null;
}

/** Número → texto do volume (vírgula, sem zeros sobrando): medidas com 2 casas, peso com 3 (como o servidor guarda). */
export const textoDoVolume = (n, casas = 2) => String(Math.round(Number(n) * 10 ** casas) / 10 ** casas).replace('.', ',');

/** Um campo de medida do produto na unidade pedida (tabela), ou null (vazio, "Não se aplica", unidade desconhecida). */
function convertido(campos, valores, id, tabela) {
    const campo = campos.find((c) => c.id === id);
    const atual = valores?.[id];
    if (! campo || ! atual || atual.naoSeAplica) return null;
    const n = numeroDigitado(atual.valor);
    if (n === null) return null;
    const unidade = String(atual.unidade || campo.unidade_padrao || '').trim().toLowerCase();
    const fator = tabela[unidade];

    return fator === undefined ? null : n * fator;
}

/**
 * As medidas do produto na unidade do volume: { c, l, a } em cm e kg em quilos (null o que falta).
 * O comprimento é o LENGTH; sem ele, a profundidade (DEPTH), como o editor interno compara.
 */
export function medidasDoProdutoNoVolume(campos, valores) {
    const lista = Array.isArray(campos) ? campos : [];
    const c = convertido(lista, valores, 'LENGTH', PARA_CM) ?? convertido(lista, valores, 'DEPTH', PARA_CM);

    return {
        c,
        l: convertido(lista, valores, 'WIDTH', PARA_CM),
        a: convertido(lista, valores, 'HEIGHT', PARA_CM),
        kg: convertido(lista, valores, 'WEIGHT', PARA_KG),
    };
}

/** Comprimento, largura e altura do produto preenchidos? (condição para oferecer a caixa) */
export const medidasCompletas = (m) => m?.c != null && m?.l != null && m?.a != null;

const igual = (texto, n, casas = 2) => {
    const v = numeroDigitado(texto);
    const arredondado = n == null ? null : Math.round(n * 10 ** casas) / 10 ** casas;

    return v !== null && arredondado !== null && Math.abs(v - arredondado) < 0.6 / 10 ** casas;
};

/** As medidas (comprimento, largura e altura) do volume são as do produto? O peso não entra. */
export const volumeIgualAoProduto = (caixa, m) => medidasCompletas(m) && igual(caixa?.c, m.c) && igual(caixa?.l, m.l) && igual(caixa?.a, m.a);

/**
 * O estado da caixa "Usar as mesmas medidas…" de uma variação.
 * `vinculo`: a escolha feita nesta tela (true/false), ou undefined (vale o derivado).
 *
 * @returns {{ disponivel: boolean, marcada: boolean }}
 */
export function estadoDasMesmasMedidas(caixas, m, vinculo) {
    const disponivel = Array.isArray(caixas) && caixas.length === 1 && medidasCompletas(m);
    if (! disponivel) return { disponivel: false, marcada: false };

    return { disponivel, marcada: vinculo ?? volumeIgualAoProduto(caixas[0], m) };
}

/**
 * A caixa com as medidas do produto. O peso do produto entra quando ele existe e:
 * - `forcarPeso` (a pessoa acabou de marcar a caixa), ou
 * - o peso do volume está vazio ou ainda é a cópia do peso anterior do produto (`pesoAnterior`).
 * Senão o peso que a pessoa digitou no volume fica (ele continua obrigatório).
 */
export function caixaComMedidasDoProduto(caixa, m, { forcarPeso = false, pesoAnterior = null } = {}) {
    const base = { c: '', l: '', a: '', kg: '', ...(caixa ?? {}) };
    if (! medidasCompletas(m)) return base;
    const novo = { ...base, c: textoDoVolume(m.c), l: textoDoVolume(m.l), a: textoDoVolume(m.a) };
    if (m.kg != null) {
        const copia = String(base.kg ?? '').trim() === '' || (pesoAnterior != null && igual(base.kg, pesoAnterior, 3));
        if (forcarPeso || copia) novo.kg = textoDoVolume(m.kg, 3);
    }

    return novo;
}

/**
 * O que muda quando a pessoa mexe numa medida do produto: os valores novos da ficha e, para cada
 * variação com a caixa marcada (escolhida nesta tela ou derivada) e UM volume, o volume novo.
 * Medida apagada no meio da digitação (comprimento, largura ou altura vazios): nenhum volume muda.
 *
 * @param {{ variacoes: Array<{ _k: string, caixas: Array<object> }>, campos: Array<object>, valores: object,
 *           id: string, parte: object, vinculos: Record<string, boolean> }} entrada
 * @returns {{ valores: object, volumes: Record<string, object>, vinculos: Record<string, boolean> }}
 */
export function seguirMedidasDoProduto({ variacoes, campos, valores, id, parte, vinculos = {} }) {
    const antes = valores ?? {};
    const depois = { ...antes, [id]: { valor: '', unidade: '', ...antes[id], ...parte } };
    const mAntes = medidasDoProdutoNoVolume(campos, antes);
    const mDepois = medidasDoProdutoNoVolume(campos, depois);
    const volumes = {};
    const marcadas = {};
    if (! medidasCompletas(mDepois)) return { valores: depois, volumes, vinculos };

    (Array.isArray(variacoes) ? variacoes : []).forEach((v) => {
        const caixas = Array.isArray(v?.caixas) ? v.caixas : [];
        if (caixas.length !== 1) return;
        const segue = vinculos[v._k] === true || estadoDasMesmasMedidas(caixas, mAntes, vinculos[v._k]).marcada;
        if (! segue) return;
        volumes[v._k] = caixaComMedidasDoProduto(caixas[0], mDepois, { pesoAnterior: mAntes.kg });
        marcadas[v._k] = true;
    });

    return { valores: depois, volumes, vinculos: { ...vinculos, ...marcadas } };
}
