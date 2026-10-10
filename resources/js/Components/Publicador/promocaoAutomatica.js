// ─── Promoção automática pós-publicação (10/10/2026) ───────────────────────
//
// ESM puro (sem JSX) para o `node --test` importar. É a MESMA conta do PHP
// (`App\Support\Publicador\PrecoDaPromocao`); o teste confere os dois com os
// mesmos números. Na Precificação do Portal, `anunciado` é o preço de publicar
// e `minimo` o da Central de Promoções: publicado pelo anunciado, a promoção é
// o mínimo; publicado por outro preço, o MESMO percentual sobre ele, nunca
// abaixo do mínimo. Desconto fora de 5% ≤ d < 80% ou preço do Portal sem frete:
// sem promoção.

import { paraTexto } from './apoio.js';

export const DIAS_DA_PROMOCAO = 14;
export const DESCONTO_MINIMO = 5;
export const DESCONTO_LIMITE = 80;

export const MOTIVOS = {
    sem_preco: 'o anúncio está sem preço',
    sem_portal: 'a Precificação do Portal não tem preço de promoção para este produto',
    sem_frete: 'o preço do Portal foi calculado sem frete',
    no_minimo: 'o preço publicado já está no preço mínimo do Portal (ou abaixo)',
    desconto_pequeno: 'o desconto ficaria abaixo de 5%',
    desconto_grande: 'o desconto passaria de 80%',
};

const numero = (v) => (v === null || v === undefined || v === '' || ! Number.isFinite(Number(v)) ? null : Number(v));

/** `round(n, 2)` do PHP: meio para longe do zero, sem o erro de 1,005 × 100 = 100,4999… do ponto flutuante. */
export const arredondar = (n) => Math.sign(n) * (Math.round(Number((Math.abs(n) * 100).toPrecision(15))) / 100);

/** "16,67" */
export const pct = (n) => Number(n).toFixed(2).replace('.', ',');

/**
 * @param {number|null} preco o preço que vai ao ML (digitado ou o do Portal)
 * @param {{anunciado?: number, minimo?: number, sem_frete?: boolean}|null} portal o da variante naquele tipo
 */
export function promocaoDoPreco(preco, portal) {
    const p = numero(preco);
    const anunciado = numero(portal?.anunciado);
    const minimo = numero(portal?.minimo);
    const nao = (motivo) => ({ calculavel: false, motivo, preco: null, percentual: null, minimo, ajustadaAoMinimo: false });

    if (p === null || p <= 0) return nao('sem_preco');
    if (anunciado === null || minimo === null || anunciado <= 0 || minimo <= 0) return nao('sem_portal');
    if (portal?.sem_frete) return nao('sem_frete');

    let promocao = Math.abs(p - anunciado) < 0.005 ? minimo : arredondar(p * (minimo / anunciado));
    let ajustadaAoMinimo = false;
    if (promocao < minimo) {
        promocao = minimo;
        ajustadaAoMinimo = true;
    }
    if (promocao >= p) return nao('no_minimo');

    const percentual = arredondar((1 - promocao / p) * 100);
    if (percentual < DESCONTO_MINIMO) return nao('desconto_pequeno');
    if (percentual >= DESCONTO_LIMITE) return nao('desconto_grande');

    return { calculavel: true, motivo: null, preco: promocao, percentual, minimo, ajustadaAoMinimo };
}

/**
 * A frase embaixo do preço (13px, sem amarelo). `automatica` = a conta tem as Alavancas liberadas
 * (o sistema cria sozinho); senão a tarefa pós-publicação orienta a criar à mão. Nulo = nada a dizer:
 * produto sem Portal, sem preço, ou preço do Portal sem frete (o V-SAL-08 já fala embaixo do campo).
 */
export function textoDaPromocao(preco, portal, automatica) {
    if (! portal || typeof automatica !== 'boolean') return null;
    const r = promocaoDoPreco(preco, portal);
    const outroPreco = numero(preco) !== null && numero(portal.anunciado) !== null && Math.abs(numero(preco) - numero(portal.anunciado)) >= 0.005;

    if (! r.calculavel) {
        if (r.motivo === 'sem_portal' || r.motivo === 'sem_preco') return null;
        if (r.motivo === 'sem_frete' && ! outroPreco) return null;

        return `Sem promoção automática: ${MOTIVOS[r.motivo]}.`;
    }

    const valor = `R$ ${paraTexto(r.preco)} (−${pct(r.percentual)}%)`;
    const base = automatica
        ? `Promoção automática: ${valor} por ${DIAS_DA_PROMOCAO} dias depois de publicar.`
        : `Promoção da Central: ${valor}. Nesta conta ela não é criada sozinha: a tarefa pós-publicação orienta a criar no Seller Center.`;
    if (r.ajustadaAoMinimo) return `${base} Fica no mínimo do Portal (R$ ${paraTexto(r.minimo)}).`;
    if (outroPreco) return `${base} Mesmo desconto do Portal sobre este preço, nunca abaixo de R$ ${paraTexto(r.minimo)}.`;

    return base;
}
