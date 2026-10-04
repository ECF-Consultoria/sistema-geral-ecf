// ─── Ferramentas puras da mesa do Publicador (melhoria de 03/10/2026) ───────
//
// Sem React e sem rota: EAN-13, conversão de unidades do pacote e montagem do
// título com os termos mais buscados. Testadas em tests/js/publicador-ferramentas.test.js.

// ── Código universal (EAN-13) ──
// Mesmo gerador do assistente antigo (AnunciarML.jsx) e do RascunhoAnuncioIaService:
// prefixo 789 (Brasil) + 9 dígitos + verificador (posição ímpar ×1, par ×3).

export const digitoEan13 = (base12) => {
    let soma = 0;
    for (let i = 0; i < 12; i++) soma += Number(base12[i]) * ((i + 1) % 2 === 0 ? 3 : 1);

    return (10 - (soma % 10)) % 10;
};

export const eanValido = (codigo) => /^\d{13}$/.test(String(codigo ?? '')) && digitoEan13(String(codigo)) === Number(String(codigo)[12]);

/** Um EAN-13 válido que não está em `existentes` (Set). `sorteio` existe para o teste. */
export function gerarEan13(existentes = new Set(), sorteio = Math.random) {
    let ean = '';
    for (let t = 0; t < 50; t++) {
        let base = '789';
        for (let i = 0; i < 9; i++) base += Math.floor(sorteio() * 10);
        ean = base + digitoEan13(base);
        if (! existentes.has(ean)) return ean;
    }

    return ean;
}

/** GTINs já usados nas variações (para não gerar repetido). */
export const gtinsEmUso = (variantes) => new Set((variantes ?? []).map((v) => String(v.atributos?.GTIN?.value_name ?? '').trim()).filter(Boolean));

/**
 * Variações que ganham código automático: a categoria pede GTIN, a variação está
 * ativa, não foi publicada, está sem código e sem o motivo de "não tem código".
 */
export const variantesSemGtin = (variantes, schema) => {
    if (! schema?.atributos?.GTIN) return [];

    return (variantes ?? []).filter((v) => ! v.orfa && v.ativa && ! v.publicada
        && String(v.atributos?.GTIN?.value_name ?? '').trim() === ''
        && ! v.atributos?.EMPTY_GTIN_REASON?.value_id);
};

// ── Unidades do pacote ──
// O ML só aceita g no peso e cm nas medidas (SELLER_PACKAGE_*, conferido nas 4 categorias
// da sondagem). A tela deixa digitar em kg/mm/m e grava convertido.

export const UNIDADES_PESO = { g: 1, kg: 1000 };
export const UNIDADES_MEDIDA = { cm: 1, mm: 0.1, m: 100 };

const numero = (texto) => {
    const s = String(texto ?? '').trim().replace(/\s/g, '');
    if (s === '') return null;
    const n = Number(s.includes(',') ? s.replace(/\./g, '').replace(',', '.') : s);

    return Number.isFinite(n) ? n : null;
};

/** O número digitado na unidade da tela → o número na unidade do ML (g sem casas; cm com 1). */
export const paraUnidadeMl = (texto, fator, casas = 0) => {
    const n = numero(texto);
    if (n === null || n <= 0) return null;
    const m = 10 ** casas;

    return Math.round(n * fator * m) / m;
};

/** O valor gravado (na unidade do ML) → texto na unidade da tela, sem zeros sobrando. */
export const daUnidadeMl = (valorMl, fator) => {
    if (valorMl === null || valorMl === undefined || valorMl === '') return '';
    const n = Number(valorMl) / fator;

    return Number.isFinite(n) ? String(Math.round(n * 1000) / 1000).replace('.', ',') : '';
};

// ── Medidas do produto × medidas do pacote (análise do Publicador, 04/10/2026) ──
// O ML calcula o frete pelo pacote FECHADO (SELLER_PACKAGE_*, sempre cm e g). As medidas do
// produto fora da caixa (HEIGHT, WIDTH…) chegam em várias unidades e só aparecem na ficha.

/** Medidas genéricas do produto sozinho, com o rótulo que deixa claro que não são do pacote. */
export const MEDIDAS_DO_PRODUTO = {
    HEIGHT: 'Altura do produto', WIDTH: 'Largura do produto', LENGTH: 'Comprimento do produto', DEPTH: 'Profundidade do produto', WEIGHT: 'Peso do produto',
};
const PARA_CM = { mm: 0.1, cm: 1, m: 100, '"': 2.54, in: 2.54, ft: 30.48 };
const PARA_G = { mg: 0.001, g: 1, kg: 1000, lb: 453.592, oz: 28.3495 };

const converter = (valor, tabela) => {
    const m = String(valor?.value_name ?? '').trim().match(/^(\d+(?:[.,]\d+)?)\s*(.*)$/);
    if (! m) return null;
    const fator = tabela[m[2].trim().toLowerCase()];

    return fator === undefined ? null : Number(m[1].replace(',', '.')) * fator;
};
/** "12,5 cm", "120 mm", '5 "' → cm. Nulo sem número ou com unidade desconhecida. */
export const medidaEmCm = (valor) => converter(valor, PARA_CM);
/** "2 kg", "500 g", "1 lb" → g. */
export const pesoEmG = (valor) => converter(valor, PARA_G);

/**
 * O pacote fechado contém o produto: se ele sai MENOR (em alguma medida, comparando da maior
 * para a menor, ou no peso), quase sempre foram digitadas as medidas do produto fora da caixa.
 * Igual em tudo também é suspeito (a embalagem soma). Nulo quando não há como comparar ou está certo.
 *
 * @return {null|'menor'|'igual'}
 */
export function conferirPacote(atributos) {
    const a = atributos ?? {};
    const produto = ['HEIGHT', 'WIDTH', a.LENGTH ? 'LENGTH' : 'DEPTH'].map((id) => medidaEmCm(a[id]));
    const pacote = ['SELLER_PACKAGE_HEIGHT', 'SELLER_PACKAGE_WIDTH', 'SELLER_PACKAGE_LENGTH'].map((id) => medidaEmCm(a[id]));
    const pesoProduto = pesoEmG(a.WEIGHT);
    const pesoPacote = pesoEmG(a.SELLER_PACKAGE_WEIGHT);
    const medidas = ! produto.includes(null) && ! pacote.includes(null);
    const pesos = pesoProduto !== null && pesoPacote !== null;
    if (! medidas && ! pesos) return null;

    const desc = (l) => [...l].sort((x, y) => y - x);
    const [p, k] = medidas ? [desc(produto), desc(pacote)] : [[], []];
    // Meio milímetro de folga: o pacote vai com uma casa, o produto pode vir em mm.
    if ((medidas && k.some((x, i) => x < p[i] - 0.05)) || (pesos && pesoPacote < pesoProduto - 0.5)) return 'menor';
    const igual = (! medidas || k.every((x, i) => Math.abs(x - p[i]) <= 0.05)) && (! pesos || Math.abs(pesoPacote - pesoProduto) <= 0.5);

    return igual ? 'igual' : null;
}

// ── Cor e cor principal (análise do Publicador, 04/10/2026) ──
// No ML a "Cor" (COLOR) aceita nome próprio (`allow_custom_value: true`) e a "Cor principal"
// (MAIN_COLOR) é lista FECHADA (`allow_custom_value: false`, valor livre = erro 3510): é o tom
// dos filtros. O nome é da pessoa; o tom sai do nome quando dá, e ela pode trocar.

// Nomes comuns que não estão na lista de tons → o tom mais próximo (só vale se o tom existir na categoria).
const SINONIMOS_DE_TOM = {
    bordo: 'vermelho', vinho: 'vermelho', marsala: 'vermelho', grafite: 'cinza', chumbo: 'cinza', prata: 'prateado',
    ouro: 'dourado', lilas: 'violeta', lavanda: 'violeta', roxo: 'violeta', indigo: 'violeta', fucsia: 'rosa', pink: 'rosa',
    marinho: 'azul', turquesa: 'azul', petroleo: 'azul', anil: 'azul', celeste: 'azul celeste', creme: 'bege', nude: 'bege',
    palha: 'bege', areia: 'bege', caqui: 'bege', chocolate: 'marrom', cafe: 'marrom', caramelo: 'marrom', tabaco: 'marrom',
    terracota: 'laranja', coral: 'laranja', salmao: 'laranja', musgo: 'verde', oliva: 'verde', militar: 'verde', limao: 'verde',
    agua: 'azul celeste', ciano: 'azul celeste', ocre: 'amarelo', gelo: 'branco', 'off white': 'branco',
};
const nomeDeCor = (s) => normalizar(s).replace(/[-_/]+/g, ' ').replace(/\s+/g, ' ').trim();

/**
 * O tom (valor de MAIN_COLOR) para um nome de cor: igual a um tom; começa por um tom
 * ("Azul-petróleo" → Azul, o mais longo primeiro: "Azul celeste" antes de "Azul"); ou por
 * sinônimo ("Grafite" → Cinza). Nulo quando não dá para saber — a pessoa escolhe.
 *
 * @param {Array<{id: string, name: string}>} tons
 */
export function tomDaCor(nome, tons) {
    const alvo = nomeDeCor(nome);
    const lista = (tons ?? []).map((t) => ({ t, n: nomeDeCor(t.name) })).sort((x, y) => y.n.length - x.n.length);
    if (! alvo || lista.length === 0) return null;
    const achar = (n) => lista.find((x) => x.n === n)?.t ?? null;

    const exato = achar(alvo);
    if (exato) return exato;
    const prefixo = lista.find((x) => alvo.startsWith(`${x.n} `));
    if (prefixo) return prefixo.t;
    for (const [chave, tom] of Object.entries(SINONIMOS_DE_TOM)) {
        if (alvo === chave || alvo.startsWith(`${chave} `) || alvo.endsWith(` ${chave}`)) {
            const t = achar(tom) ?? (tom.includes(' ') ? achar(tom.split(' ')[0]) : null);
            if (t) return t;
        }
    }
    const palavra = alvo.split(' ').map(achar).find(Boolean);

    return palavra ?? null;
}

/** O nome da cor de uma variação: o valor do eixo Cor; sem eixo de cor, a Cor do produto. */
export const nomeDaCor = (v, atributosDoProduto) => String(v?.valores?.COLOR?.nome ?? atributosDoProduto?.COLOR?.value_name ?? '').trim();

/** O número de um atributo `number_unit` gravado ("1500 g" → 1500). */
export const numeroDoAtributo = (valor) => {
    if (valor?.value_number !== undefined && valor?.value_number !== null && valor.value_number !== '') return Number(valor.value_number);
    const m = String(valor?.value_name ?? '').match(/^\s*(\d+(?:[.,]\d+)?)/);

    return m ? Number(m[1].replace(',', '.')) : null;
};

/** A unidade de tela mais natural para um valor já gravado (1500 g → kg; 80 g → g). */
export const unidadeInicial = (valorMl, tipo) => {
    if (tipo === 'peso') return valorMl !== null && valorMl >= 1000 ? 'kg' : 'g';

    return 'cm';
};

// ── Termos mais buscados no título ──

const normalizar = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/** As palavras do termo que o título ainda não tem, acrescentadas no fim (sem repetir palavra). */
export function juntarTermo(titulo, termo) {
    const atual = String(titulo ?? '').trim();
    const tem = new Set(atual.split(/\s+/).filter(Boolean).map(normalizar));
    const novas = String(termo ?? '').trim().split(/\s+/).filter((p) => p && ! tem.has(normalizar(p)));
    if (novas.length === 0) return atual;

    return [atual, ...novas].filter(Boolean).join(' ');
}

/** O termo já está inteiro no título? (para o chip aparecer marcado) */
export const termoNoTitulo = (titulo, termo) => {
    const tem = new Set(String(titulo ?? '').split(/\s+/).filter(Boolean).map(normalizar));
    const palavras = String(termo ?? '').split(/\s+/).filter(Boolean);

    return palavras.length > 0 && palavras.every((p) => tem.has(normalizar(p)));
};

// ── Variações "como no Mercado Livre" (03/10/2026) ──
// Cada variação é um cartão com o valor dela em cada eixo. Por baixo continua o modelo
// de eixos do servidor (`RegeneradorVariantes`): criar variação = acrescentar o valor ao
// eixo; tirar = remover o valor (a variação vira órfã com os dados e pode voltar).

const mesmoNome = (a, b) => normalizar(a).trim() === normalizar(b).trim();

/** Os eixos do estado no formato do PUT /eixos. */
export const eixosParaEnvio = (eixos) => (eixos ?? []).map(({ chave, nome, defines_picture, valores }) => ({
    chave, nome, defines_picture: !! defines_picture, valores: (valores ?? []).map(({ id, nome: n }) => ({ id: id ?? null, nome: n })),
}));

/** Acrescenta a cada eixo o valor pedido, se ainda não estiver lá. `pedido` = `{ [chaveDoEixo]: { id, nome } }`. */
export const eixosComValores = (eixos, pedido) => eixosParaEnvio(eixos).map((e) => {
    const v = pedido?.[e.chave];
    const nome = String(v?.nome ?? '').trim();
    if (! nome || e.valores.some((x) => mesmoNome(x.nome, nome))) return e;

    return { ...e, valores: [...e.valores, { id: v.id ?? null, nome }] };
});

/** Tira um valor do eixo; o eixo que fica sem valor sai (a última variação volta a ser o produto único). */
export const eixosSemValor = (eixos, chaveDoEixo, nome) => eixosParaEnvio(eixos)
    .map((e) => (e.chave === chaveDoEixo ? { ...e, valores: e.valores.filter((x) => ! mesmoNome(x.nome, nome)) } : e))
    .filter((e) => e.valores.length > 0);

/** A variação cujos valores batem com o pedido em todos os eixos pedidos. */
export const varianteDoPedido = (variantes, pedido) => {
    const pares = Object.entries(pedido ?? {});
    if (pares.length === 0) return null;

    return (variantes ?? []).find((v) => pares.every(([eixo, val]) => v.valores?.[eixo] && mesmoNome(v.valores[eixo].nome, val.nome))) ?? null;
};

/** O pedido está completo? (um valor não vazio para cada eixo) */
export const pedidoCompleto = (chaves, pedido) => chaves.length > 0 && chaves.every((c) => String(pedido?.[c]?.nome ?? '').trim() !== '');
