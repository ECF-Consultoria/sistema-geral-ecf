// ─── Número digitado em pt-BR → texto com ponto decimal ─────────────────────
//
// 23/10/2026 (P-02): "1.299" digitado no preço do Publicador virava R$ 1,30 — as
// cópias do leitor só tratavam o ponto como milhar quando havia vírgula. Esta é a
// regra única, a mesma do `NumeroBr::DINHEIRO` do servidor:
// - com vírgula, ela é o decimal e os pontos são milhar ("1.299,90" → "1299.90");
// - sem vírgula, ponto seguido de grupos de EXATAMENTE 3 dígitos é milhar
//   ("1.299" → "1299", "2.500" → "2500", "1.299.000" → "1299000"), desde que o
//   número não comece com 0 ("0.500" é 0,5, como no servidor);
// - o resto é decimal ("12.5" → "12.5", "1.29" → "1.29").
// `milhar: false` é para medida (peso e dimensões da caixa): o servidor lê "1.500"
// kg como 1,5 (`NumeroBr::MEDIDA`), e a tela não pode calcular com outro número.
// Só troca separadores: quem chama tira "R$", espaço e "%", e valida o resultado.

export function normalizarNumeroBr(texto, { milhar = true } = {}) {
    const t = String(texto ?? '').trim();
    if (t.includes(',')) return t.replace(/\./g, '').replace(',', '.');
    if (milhar && /^-?[1-9]\d{0,2}(\.\d{3})+$/.test(t)) return t.replace(/\./g, '');

    return t;
}
