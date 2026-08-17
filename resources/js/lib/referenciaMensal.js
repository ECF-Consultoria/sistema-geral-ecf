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

/**
 * A competência ainda está EM CURSO (é o mês corrente)?
 *
 * Corrigido em 2026-08-14, no mesmo dia: a primeira versão deslocava TODA
 * competência em +1 e o seletor passou a oferecer "setembro de 2026 · Ref.
 * agosto de 2026" — um mês que nem começou. O deslocamento existe porque o
 * resultado de um mês fechado só é acompanhado no mês seguinte; enquanto a
 * competência corre, quem a acompanha é ela mesma.
 *
 * @param {string} competenciaIso
 * @param {Date}   [hoje] injetável para teste; por padrão, agora.
 */
export function competenciaEmCurso(competenciaIso, hoje = new Date()) {
    const d = primeiroDia(competenciaIso);
    if (!d) return false;
    return d.getFullYear() === hoje.getFullYear() && d.getMonth() === hoje.getMonth();
}

/**
 * Mês que a tela deve ANUNCIAR: o de acompanhamento quando a competência já
 * fechou, e a própria competência enquanto ela corre.
 */
export function mesExibicao(competenciaIso, hoje = new Date()) {
    return competenciaEmCurso(competenciaIso, hoje)
        ? primeiroDia(competenciaIso)
        : mesAcompanhamento(competenciaIso);
}

/** 'Agosto/2026' — o mês que a tela deve anunciar como título. */
export function rotuloAcompanhamento(competenciaIso, hoje = new Date()) {
    return extenso(mesExibicao(competenciaIso, hoje));
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
    const { curto: usarCurto = false, separador = ' · ', hoje = new Date() } = opts;
    const comp = primeiroDia(competenciaIso);
    if (!comp) return '—';

    const fmt = usarCurto ? curto : extenso;

    // Competência EM CURSO não ganha "Ref.": ela é o próprio mês que está
    // sendo acompanhado, e anunciar o mês seguinte ofereceria um mês que ainda
    // não começou ("setembro · Ref. agosto", estando em agosto).
    if (competenciaEmCurso(competenciaIso, hoje)) {
        return fmt(comp);
    }

    return `${fmt(mesAcompanhamento(competenciaIso))}${separador}Ref. ${fmt(comp)}`;
}

/** 'ago/26 · Ref. jul/26' — versão compacta, para chip, opção de select e eixo. */
export function rotuloMesReferenciaCurto(competenciaIso) {
    return rotuloMesReferencia(competenciaIso, { curto: true });
}
