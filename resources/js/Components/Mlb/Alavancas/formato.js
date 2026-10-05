// ─── Alavancas: formatadores (ESM puro) ─────────────────────────────────────

const FUSO = 'America/Sao_Paulo';

const vazio = (n) => n === null || n === undefined || n === '' || Number.isNaN(Number(n));

export const fmtInt = (n) => (vazio(n) ? '—' : Number(n).toLocaleString('pt-BR'));

export const fmtBRL = (n) => (vazio(n) ? '—' : Number(n).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }));

/** Porcentagem já em pontos (12,5 = 12,5%). */
export const fmtPct = (n) => (vazio(n) ? '—' : `${Number(n).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}%`);

/** Foto do ML sempre por https (o `thumbnail` do multiget costuma vir em http e a página é https). Vazio → null. */
export function fotoDoMl(url) {
    const texto = String(url ?? '').trim();
    if (texto === '') return null;

    return texto.replace(/^http:\/\//i, 'https://');
}

const SO_DATA = /^\d{4}-\d{2}-\d{2}$/;
const SEM_FUSO = /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/;

/** Data pura (aaaa-mm-dd) → Date com o instante certo; ISO sem fuso vale horário de São Paulo (UTC-3, sem horário de verão). */
function paraDate(valor) {
    const texto = String(valor).trim();
    const d = new Date(SEM_FUSO.test(texto) ? `${texto.replace(' ', 'T')}-03:00` : texto);

    return Number.isNaN(d.getTime()) ? null : d;
}

/**
 * dd/mm/aaaa no fuso de São Paulo; com `hora`, acrescenta hh:mm.
 * Data pura (aaaa-mm-dd) não é instante: sai como veio, sem conversão de fuso (senão 05/10 viraria 04/10).
 */
export function fmtData(valor, { hora = false } = {}) {
    if (! valor) return '—';
    const texto = String(valor).trim();
    if (SO_DATA.test(texto)) {
        const [a, m, d] = texto.split('-');

        return `${d}/${m}/${a}`;
    }
    const d = paraDate(texto);
    if (! d) return '—';
    const opcoes = hora
        ? { timeZone: FUSO, day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }
        : { timeZone: FUSO, day: '2-digit', month: '2-digit', year: 'numeric' };

    return d.toLocaleString('pt-BR', opcoes).replace(',', '');
}

/**
 * Dia (aaaa-mm-dd) de uma data do ML no fuso de São Paulo. Sem fuso, vale o próprio dia escrito;
 * com `Z`/offset, converte (2026-10-05T02:00:00Z já é 04/10 em São Paulo). Vazio ou inválido: ''.
 */
export function diaSP(valor) {
    if (! valor) return '';
    const texto = String(valor).trim();
    if (SO_DATA.test(texto) || SEM_FUSO.test(texto)) return texto.slice(0, 10);
    const d = paraDate(texto);

    return d ? d.toLocaleDateString('en-CA', { timeZone: FUSO }) : '';
}

/** Hoje (aaaa-mm-dd) no fuso de São Paulo. */
export const hojeSP = () => new Date().toLocaleDateString('en-CA', { timeZone: FUSO });

/**
 * Janela de `dias` dias terminando em `hoje` (inclusive): `{ de, ate }` em aaaa-mm-dd.
 * `hoje` entra por parâmetro para o teste não depender do relógio.
 */
export function janelaDeDias(dias, hoje = hojeSP()) {
    const fim = new Date(`${hoje}T12:00:00Z`);
    const inicio = new Date(fim.getTime() - (dias - 1) * 86400000);

    return { de: inicio.toISOString().slice(0, 10), ate: hoje };
}

/** aaaa-mm-dd mais `dias` dias (conta em UTC ao meio-dia, sem depender do fuso da máquina). */
export function somarDias(ymd, dias) {
    const d = new Date(`${ymd}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + dias);

    return d.toISOString().slice(0, 10);
}

/**
 * Lê número digitado em pt-BR. Com vírgula, `.` é milhar e `,` é decimal ("1.500,50" → 1500,5).
 * Sem vírgula, ponto seguido de exatamente 3 dígitos (grupos a partir do 1º dígito não nulo) é milhar
 * ("1.500" → 1500, "1.500.000" → 1500000); os demais casos são decimal ("1.5" → 1,5; "85.90" → 85,9).
 * Vazio ou inválido vira null; `positivo` também recusa zero e negativo.
 */
export function lerNumero(texto, { positivo = false } = {}) {
    const bruto = String(texto ?? '').trim();
    if (bruto === '') return null;
    let normal = bruto;
    if (bruto.includes(',')) normal = bruto.split('.').join('').replace(',', '.');
    else if (/^-?[1-9]\d{0,2}(\.\d{3})+$/.test(bruto)) normal = bruto.split('.').join('');
    if (! /^-?\d+(\.\d+)?$/.test(normal)) return null;
    const n = Number(normal);

    return Number.isFinite(n) && (! positivo || n > 0) ? n : null;
}

/** Dias contados nas duas pontas, como `DatasDoMl::diasInclusivos` do servidor (07 a 07 = 1). null se faltar/for inválida. */
export function diasInclusivos(de, ate) {
    if (! SO_DATA.test(String(de ?? '')) || ! SO_DATA.test(String(ate ?? ''))) return null;
    const a = new Date(`${de}T12:00:00Z`);
    const b = new Date(`${ate}T12:00:00Z`);
    if (Number.isNaN(a.getTime()) || Number.isNaN(b.getTime())) return null;

    return Math.round((b - a) / 86400000) + 1;
}

/** Período do cupom: o fim não vem antes do início e vale de 1 a 31 dias inclusivos (ALAV-CUP-02). */
export function periodoDeCupomOk(inicio, fim) {
    const dias = diasInclusivos(inicio, fim);

    return dias !== null && fim >= inicio && dias >= 1 && dias <= 31;
}
