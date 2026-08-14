/**
 * Referência mensal — regra ÚNICA do sistema (spec 2026-08-14, item 1).
 *
 * Os módulos de NPS e Desempenho tratavam o mês cada um de um jeito, e a mesma
 * nota aparecia ora como "julho" ora como "agosto" dependendo da tela. A regra
 * agora é uma só:
 *
 *   mês de EXIBIÇÃO  = mês em que o resultado está sendo acompanhado (M+1)
 *   Ref.             = mês de competência dos dados (M)
 *
 * Exemplo — estando em agosto:
 *   NPS        — Agosto/2026 | Ref. Julho/2026
 *   Desempenho — Agosto/2026 | Ref. Julho/2026
 *
 * O deslocamento é o MESMO que o bônus já pratica no backend
 * (`NpsJanelaResolver::mesDeColeta()`: competência M → coleta em M+1). Aqui ele
 * é só apresentação: nenhuma chave, filtro, snapshot ou `mes_referencia`
 * gravado muda de valor — o que muda é o nome que a tela dá ao mês.
 *
 * ATENÇÃO ao ponto de entrada: estas funções recebem SEMPRE a COMPETÊNCIA
 * (o mês do dado, que é o que o backend usa em `?mes=` do /performance, em
 * `mes_referencia` dos snapshots e em `bonus.competence_month`). Passar o mês
 * de acompanhamento por engano desloca tudo mais um mês.
 */

/** 'YYYY-MM' ou 'YYYY-MM-DD' → Date do dia 1º, ou null se não der pra ler. */
function primeiroDia(iso) {
    if (!iso) return null;
    const [y, m] = String(iso).split('-');
    if (!y || !m) return null;
    const d = new Date(Number(y), Number(m) - 1, 1);
    return Number.isNaN(d.getTime()) ? null : d;
}

/** Date → 'agosto/2026'. */
function extenso(d) {
    return d ? d.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' }) : '—';
}

/** Date → 'ago/26'. */
function curto(d) {
    return d ? d.toLocaleDateString('pt-BR', { month: 'short', year: '2-digit' }) : '—';
}

/**
 * Mês em que a competência é acompanhada (competência + 1 mês).
 * @param {string} competenciaIso 'YYYY-MM' ou 'YYYY-MM-DD'
 */
export function mesAcompanhamento(competenciaIso) {
    const d = primeiroDia(competenciaIso);
    if (!d) return null;
    // Dia 1º em todo mês: soma de mês nunca transborda (não existe "31 de fev").
    return new Date(d.getFullYear(), d.getMonth() + 1, 1);
}

/** 'Agosto/2026' — o mês que a tela deve anunciar como título. */
export function rotuloAcompanhamento(competenciaIso) {
    return extenso(mesAcompanhamento(competenciaIso));
}

/** 'Julho/2026' — a competência dos dados. */
export function rotuloReferencia(competenciaIso) {
    return extenso(primeiroDia(competenciaIso));
}

/**
 * Rótulo completo no formato da spec: 'Agosto/2026 · Ref. Julho/2026'.
 * @param {string} competenciaIso competência (mês do dado)
 * @param {{curto?: boolean, separador?: string}} [opts]
 */
export function rotuloMesReferencia(competenciaIso, opts = {}) {
    const { curto: usarCurto = false, separador = ' · ' } = opts;
    const comp = primeiroDia(competenciaIso);
    if (!comp) return '—';

    const fmt = usarCurto ? curto : extenso;
    return `${fmt(mesAcompanhamento(competenciaIso))}${separador}Ref. ${fmt(comp)}`;
}

/** 'ago/26 · Ref. jul/26' — versão compacta, para chip, opção de select e eixo. */
export function rotuloMesReferenciaCurto(competenciaIso) {
    return rotuloMesReferencia(competenciaIso, { curto: true });
}
