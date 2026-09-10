/**
 * resources/js/lib/faixasFaturamento.js — conversão entre o teto GRAVADO
 * (quick 260910-faixa-mostra-valor-redondo).
 *
 * O motor de cobrança (`FechamentoFaixaResolver::classificar()`) classifica
 * com `limite_superior >= faturamento` — por isso todo teto gravado termina
 * em ",99" (conferido em produção em 2026-09-10: 1.060 tetos, todos ",99",
 * zero exceções). R$ 499.999,99 na faixa 1 já significa "R$ 500.000,00 cai
 * na faixa 2", exatamente o que "a partir de R$ 500.000" quer dizer — as
 * duas formas são a MESMA regra.
 *
 * ⚠️ Isto NÃO muda cobrança. `limite_superior` continua gravado com ",99" —
 * a conversão para o valor redondo do contrato ("até 500.000" / "a partir
 * de 500.000") acontece só na BORDA (exibição e digitação), nunca dentro do
 * resolver. Por isso estas funções são puras e vivem fora de qualquer
 * componente — testadas isoladas (`tests/js/faixasFaturamento.test.js`),
 * porque um centavo errado aqui move empresa de faixa.
 */

/** Teto gravado (499999.99) -> teto redondo mostrado (500000). */
export function tetoRedondo(limiteSuperiorGravado) {
    if (limiteSuperiorGravado == null) return null;
    return Math.round((Number(limiteSuperiorGravado) + 0.01) * 100) / 100;
}

/** Valor redondo digitado (500000) -> teto gravado (499999.99). */
export function tetoGravado(valorRedondo) {
    if (valorRedondo == null) return null;
    return Math.round((Number(valorRedondo) - 0.01) * 100) / 100;
}

/**
 * Índice, dentro do array `linhas`/`faixas` (já ordenado por `ordem`), cujo
 * `limite_superior` guarda o faturamento mostrado na linha `idx`.
 *
 * A primeira linha (idx 0) é a ÚNICA que aponta para ela mesma — mostra
 * "até" o próprio teto. Todas as outras (intermediárias E a última, sem
 * teto) mostram "a partir de" o PISO, que por definição
 * (`piso(n) = teto(n-1) + 0,01`) É o teto da linha anterior — por isso
 * apontam para `idx - 1`.
 */
export function indiceDeGravacao(idx) {
    return idx === 0 ? 0 : idx - 1;
}

/**
 * Devolve `{ tipo, valor }` para a linha `idx` de um array de faixas — o
 * tipo ("ate" só na primeira linha, "a_partir_de" em todas as outras) e o
 * valor redondo já convertido (`null` quando a linha-alvo não tem teto
 * gravado, caso apenas da própria primeira linha quando ela também é a
 * única/última — tabela de uma faixa só, sem teto).
 */
export function faturamentoDaLinha(faixas, idx) {
    const alvo = indiceDeGravacao(idx);
    const valor = tetoRedondo(faixas?.[alvo]?.limite_superior ?? null);
    return { tipo: idx === 0 ? 'ate' : 'a_partir_de', valor };
}

/** Número redondo -> string BRL sem centavos ("R$ 500.000"), ou `null`. */
export function formatarDinheiroRedondo(n) {
    if (n == null) return null;
    return Number(n).toLocaleString('pt-BR', {
        style: 'currency', currency: 'BRL',
        minimumFractionDigits: 0, maximumFractionDigits: 0,
    });
}

/**
 * Rótulo pronto para exibição na grade de leitura (`TabelaProgressivaFaixas`,
 * `TabelasContrato`) — "até R$ 500.000" na primeira linha, "a partir de
 * R$ X" em todas as outras (inclusive a última, sem teto). Cai em "acima"
 * só no caso degenerado de uma tabela de uma faixa só, sem teto nenhum.
 */
export function rotuloFaturamento(faixas, idx) {
    const { tipo, valor } = faturamentoDaLinha(faixas, idx);
    const texto = formatarDinheiroRedondo(valor);
    if (texto == null) return 'acima';
    return tipo === 'ate' ? `até ${texto}` : `a partir de ${texto}`;
}

/**
 * Valor redondo a mostrar/editar no campo de Faturamento da linha `idx`, no
 * FORMULÁRIO de edição — mesma fonte da grade de leitura (`faturamentoDaLinha`),
 * só que devolvendo o NÚMERO cru (para alimentar `CampoDinheiro`), não o texto
 * com prefixo.
 */
export function valorExibidoNoCampo(linhas, idx) {
    return faturamentoDaLinha(linhas, idx).valor;
}

/**
 * Aplica a digitação de um valor redondo (o que a pessoa vê e digita no
 * campo da linha `idx`) — devolve um NOVO array (imutável), com
 * `limite_superior` da linha-alvo (`indiceDeGravacao(idx)`) trocado por
 * `valorRedondo - 0,01`. As demais chaves de cada linha (`ordem`, `valor`,
 * `valor_e_piso`) ficam intactas.
 */
export function aplicarValorDigitado(linhas, idx, valorRedondo) {
    const alvo = indiceDeGravacao(idx);
    return linhas.map((l, i) => (i === alvo ? { ...l, limite_superior: tetoGravado(valorRedondo) } : l));
}
