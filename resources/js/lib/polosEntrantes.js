// ═══════════════════════════════════════════════════════════════════════
// Regras puras do funil de entrada dos Polos (aba "Entrantes (M0)").
//
// Moram fora do JSX porque são as duas contas que o time confere contra a
// planilha — dá para travar em teste sem montar React.
// ═══════════════════════════════════════════════════════════════════════

/** Valor canônico da coluna "Status entrada" (espelha MlbImplementacao::ONB_STATUS_ENTRADA_OPCOES). */
export const STATUS_ENTRADA_RESERVA = 'Reserva - entrada prox mês';

/**
 * Empresa reservada para entrar no MÊS QUE VEM.
 *
 * `status_entrada` é TEXTO LIVRE: `SyncPolosPlanilha` copia a célula da planilha sem
 * normalizar contra o enum, então variação de caixa e de espaçamento chega ao painel.
 * Comparar pelo prefixo "reserva" absorve isso — e nenhuma outra opção do funil
 * (Feito · em contato · Não tem CNPJ · Não tem conta ML · Não responde · Abandonou o
 * projeto) começa assim, então não há falso positivo. A palavra não tem acento, logo
 * caixa baixa basta: não é preciso remover diacrítico.
 *
 * @param {{status_entrada?: string|null}} empresa
 * @returns {boolean}
 */
export function ehReservaProximoMes(empresa) {
    return String(empresa?.status_entrada ?? '').trim().toLowerCase().startsWith('reserva');
}

/**
 * Meta de entrantes de um mês = soma das metas cadastradas de TODOS os polos naquele mês
 * (aba "Visão geral" → card "Meta de entrantes"; tabela `polos_meta_entrada`).
 *
 * Devolve 0 quando o mês não tem meta cadastrada — o painel usa isso para voltar a
 * mostrar só o número absoluto em vez de uma fração com denominador zero.
 *
 * @param {Array<{polo?: string, mes?: string, meta?: number|null}>} metasEntrada
 * @param {string} mes 'YYYY-MM'
 * @returns {number}
 */
export function somaMetaDoMes(metasEntrada, mes) {
    if (!Array.isArray(metasEntrada)) return 0;
    return metasEntrada
        .filter((m) => m && m.mes === mes)
        .reduce((soma, m) => soma + (Number(m.meta) || 0), 0);
}

/** 'YYYY-MM' de uma data (default: hoje) — mesma chave usada em `polos_meta_entrada.mes`. */
export function competenciaDe(data = new Date()) {
    return `${data.getFullYear()}-${String(data.getMonth() + 1).padStart(2, '0')}`;
}
