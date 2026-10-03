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
