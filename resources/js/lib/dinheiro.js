/**
 * resources/js/lib/dinheiro.js — fonte única de opções de máscara e
 * formatação de dinheiro (Fase 142 Plano 03, D-02).
 *
 * Por que a máscara é do `react-imask` (`^7.6.1`, já em `package.json` desde
 * a Fase 34, já usado em `Comercial/NovaEmpresa.jsx` e `Admin/ContratoDetalhe.jsx`)
 * e não um parser de string escrito à mão: os campos de faixa de faturamento
 * guardam valores como `499999.99` e `12000.00` — sem separador de milhar,
 * `1500000` e `150000` são quase indistinguíveis a olho, e um zero a mais
 * numa faixa muda a cobrança de uma empresa inteira (142-CONTEXT.md, D-02).
 * Escrever esse parser à mão é reabrir a porta para o mesmo erro de dígito
 * que a máscara existe para prevenir.
 */

/**
 * Opções do imask para um campo de dinheiro em reais: separador de milhar
 * `.`, vírgula decimal, sempre duas casas, sem negativo (faixa de
 * faturamento e mensalidade nunca são negativas).
 */
export const MASCARA_DINHEIRO = {
    mask: Number,
    scale: 2,
    thousandsSeparator: '.',
    radix: ',',
    mapToRadix: ['.'],
    padFractionalZeros: true,
    normalizeZeros: true,
    min: 0,
};

/**
 * Número → string formatada em BRL, para EXIBIÇÃO (não para alimentar o
 * `value` de um campo mascarado — para isso use `paraTextoDeCampo`).
 * `null`/`undefined` viram '—', nunca "R$ 0,00" (um valor ausente não é
 * zero — mesma disciplina do `fmtBRL` de `TabelaFaixasSection.jsx`).
 */
export function formatarDinheiro(n) {
    if (n == null) return '—';

    return Number(n).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

/**
 * Número → texto com vírgula decimal, para alimentar o `value` de um
 * `IMaskInput` com `MASCARA_DINHEIRO` (o imask espera o texto já no formato
 * que ele mesmo produziria digitando — vírgula, não ponto). `null`/`undefined`
 * viram string vazia (campo em branco, nunca "0,00" fingindo um valor).
 */
export function paraTextoDeCampo(n) {
    if (n == null) return '';

    return String(n).replace('.', ',');
}
