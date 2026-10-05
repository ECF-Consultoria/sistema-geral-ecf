// ─── Alavancas: formatadores (ESM puro) ─────────────────────────────────────

const FUSO = 'America/Sao_Paulo';

const vazio = (n) => n === null || n === undefined || n === '' || Number.isNaN(Number(n));

export const fmtInt = (n) => (vazio(n) ? '—' : Number(n).toLocaleString('pt-BR'));

export const fmtBRL = (n) => (vazio(n) ? '—' : Number(n).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }));

/** Porcentagem já em pontos (12,5 = 12,5%). */
export const fmtPct = (n) => (vazio(n) ? '—' : `${Number(n).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}%`);

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
