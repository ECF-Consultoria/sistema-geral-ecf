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

/**
 * Dia em que a META DE ENTRANTES FECHA — o dia 27 é o ÚLTIMO da competência.
 *
 * Regra do time (2026-08-28), e a única coisa aqui que não é dedutível do código:
 * o ciclo de entrantes não é o mês do calendário. A competência de agosto vai de
 * **28/07 a 27/08**; quem entra do dia 28 em diante já é do mês seguinte. Só a meta
 * de entrantes segue essa régua — faturamento e M1 continuam no mês do calendário.
 *
 * Mudar este número reescreve a competência de TODO o histórico (o gráfico de
 * evolução recalcula os meses passados), porque a atribuição é derivada de
 * `data_solicitacao` na hora de renderizar — não há snapshot congelado.
 */
export const DIA_CORTE_ENTRANTES = 27;

/** Núcleo do corte: (ano, mês 1-12, dia) → 'YYYY-MM' da competência. */
function competencia(ano, mes, dia) {
    if (dia > DIA_CORTE_ENTRANTES) {
        mes += 1;
        if (mes > 12) { mes = 1; ano += 1; }
    }
    return `${ano}-${String(mes).padStart(2, '0')}`;
}

/**
 * Competência de entrantes de uma data (default: hoje) — mesma chave de
 * `polos_meta_entrada.mes`. Aplica o corte do dia 27: em 27/08 devolve '2026-08';
 * em 28/08 já devolve '2026-09'.
 */
export function competenciaDe(data = new Date()) {
    return competencia(data.getFullYear(), data.getMonth() + 1, data.getDate());
}

/**
 * Mesma competência, mas a partir da string 'YYYY-MM-DD' que vem do backend
 * (`data_solicitacao`). Lê os dígitos direto em vez de construir um `Date`:
 * `new Date('2026-08-28')` é meia-noite **UTC**, que no fuso de Brasília volta
 * para o dia 27 e jogaria a empresa na competência errada exatamente na virada.
 *
 * @param {string|null|undefined} dataISO
 * @returns {string} 'YYYY-MM', ou '' quando não há data
 */
export function competenciaDeISO(dataISO) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(dataISO ?? '').trim());
    if (!m) return '';
    return competencia(Number(m[1]), Number(m[2]), Number(m[3]));
}

/**
 * Janela de datas de uma competência: de 28 do mês anterior a 27 do próprio mês.
 *
 * @param {string} ym 'YYYY-MM'
 * @returns {{inicio: Date, fim: Date, dias: number}|null}
 */
export function janelaDaCompetencia(ym) {
    const m = /^(\d{4})-(\d{2})$/.exec(String(ym ?? '').trim());
    if (!m) return null;
    const ano = Number(m[1]);
    const mes = Number(m[2]);
    // `mes - 2` com mes = 1 vira índice -1: o próprio Date rola para dezembro do ano anterior.
    const inicio = new Date(ano, mes - 2, DIA_CORTE_ENTRANTES + 1);
    const fim    = new Date(ano, mes - 1, DIA_CORTE_ENTRANTES);
    const dias   = Math.round((fim - inicio) / 86400000) + 1;
    return { inicio, fim, dias };
}

const diaMes = (d) => `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`;

/** Rótulo curto da janela — "28/07 a 27/08". Vazio se o ym for inválido. */
export function rotuloJanelaCompetencia(ym) {
    const j = janelaDaCompetencia(ym);
    return j ? `${diaMes(j.inicio)} a ${diaMes(j.fim)}` : '';
}

/**
 * Quantos dias da competência já correram até `hoje` (1..dias).
 * Devolve `null` quando hoje está fora da janela — aí não há ritmo a projetar.
 */
export function diasCorridosNaCompetencia(ym, hoje = new Date()) {
    const j = janelaDaCompetencia(ym);
    if (!j) return null;
    const h = new Date(hoje.getFullYear(), hoje.getMonth(), hoje.getDate());
    if (h < j.inicio || h > j.fim) return null;
    return Math.round((h - j.inicio) / 86400000) + 1;
}

/**
 * Fases TERMINAIS — a empresa saiu da operação. Strings exatas da coluna "Fase" da
 * planilha. Vive aqui, e não em cada tela, porque cada cópia da régua já custou uma
 * divergência entre abas: a "Visão geral" contava churn na meta de entrantes enquanto
 * a aba "Entrantes (M0)" e o Modo TV não contavam.
 */
export const FASES_TERMINAIS = ['Encerrado', 'Protocolo Churn', 'Churn'];

/** true quando a empresa está numa fase terminal (churn/encerrado). */
export function ehFaseTerminal(empresa) {
    return FASES_TERMINAIS.includes(empresa?.fase);
}

/** Remove churn/encerrado — recorte padrão de qualquer contagem de META. */
export function semTerminais(empresas) {
    return (Array.isArray(empresas) ? empresas : []).filter((e) => !ehFaseTerminal(e));
}
