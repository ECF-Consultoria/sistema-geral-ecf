// Demandas Dev — rótulos e cores. Espelho manual das constantes de App\Models\DevDemanda.

export const STATUS_LABELS = {
    backlog:            'Backlog',
    a_fazer:            'A fazer',
    em_desenvolvimento: 'Em desenvolvimento',
    em_validacao:       'Em validação',
    bloqueado:          'Bloqueado',
    concluido:          'Concluído',
    cancelado:          'Cancelado',
};

// Cor do ponto ao lado do status.
export const STATUS_DOT = {
    backlog:            'bg-white/30',
    a_fazer:            'bg-sky-400',
    em_desenvolvimento: 'bg-violet-400',
    em_validacao:       'bg-amber-400',
    bloqueado:          'bg-orange-500',
    concluido:          'bg-emerald-400',
    cancelado:          'bg-white/20',
};

export const PRIORIDADE_LABELS = {
    0: 'P0 - Crítica',
    1: 'P1 - Alta',
    2: 'P2 - Normal',
    3: 'P3 - Backlog',
};

export const PRIORIDADE_CURTA = { 0: 'P0', 1: 'P1', 2: 'P2', 3: 'P3' };

export const PRIORIDADE_CLASSE = {
    0: 'bg-red-500/15 text-red-400 ring-1 ring-inset ring-red-500/30',
    1: 'bg-amber-500/15 text-amber-400',
    2: 'bg-white/[0.05] text-white/70',
    3: 'bg-white/[0.03] text-white/40',
};

export const SITUACAO_LABELS = {
    bloqueado:     'Bloqueada',
    atrasada:      'Atrasada',
    prazo_proximo: 'Prazo próximo',
    no_prazo:      'No prazo',
    sem_prazo:     'Sem prazo',
    concluido:     'Concluída',
    cancelado:     'Cancelada',
};

export const SITUACAO_CLASSE = {
    bloqueado:     'bg-orange-500/15 text-orange-400',
    atrasada:      'bg-red-500/15 text-red-400',
    prazo_proximo: 'bg-amber-500/15 text-amber-400',
    no_prazo:      'bg-emerald-500/10 text-emerald-400',
    sem_prazo:     'bg-white/[0.04] text-white/50',
    concluido:     'bg-sky-500/10 text-sky-400',
    cancelado:     'bg-white/[0.03] text-white/40 line-through',
};

// Ordem de exibição da situação no painel (do mais urgente ao encerrado).
export const SITUACAO_ORDEM = ['bloqueado', 'atrasada', 'prazo_proximo', 'no_prazo', 'sem_prazo', 'concluido', 'cancelado'];

// Status em que a demanda está sendo trabalhada — são as que pedem a linha diária.
export const STATUS_EM_ANDAMENTO = ['em_desenvolvimento', 'em_validacao', 'bloqueado'];

// 'YYYY-MM-DD' → 'dd/mm' (com o ano quando não é o do `hoje`). Parse manual: sem fuso.
export const fmtData = (iso, hoje = null) => {
    if (!iso) return '—';
    const [a, m, d] = iso.split('-');
    if (hoje && hoje.slice(0, 4) === a) return `${d}/${m}`;
    return `${d}/${m}/${a}`;
};

// Diferença em dias entre duas datas 'YYYY-MM-DD' (b - a).
export const diasEntre = (a, b) => {
    const ms = Date.UTC(...b.split('-').map((n, i) => (i === 1 ? n - 1 : +n))) - Date.UTC(...a.split('-').map((n, i) => (i === 1 ? n - 1 : +n)));
    return Math.round(ms / 86400000);
};

// Texto curto do prazo: "vence hoje", "em 3 dias", "2 dias atrasada".
export const textoPrazo = (demanda, hoje) => {
    if (!demanda.prazo) return 'sem prazo';
    if (demanda.encerrada) return fmtData(demanda.prazo, hoje);
    const d = diasEntre(hoje, demanda.prazo);
    if (d === 0) return 'vence hoje';
    if (d === 1) return 'vence amanhã';
    if (d > 1) return `em ${d} dias`;
    return `${-d} ${-d === 1 ? 'dia' : 'dias'} atrasada`;
};

// Ordenação natural de código: DEV-2 antes de DEV-10.
export const compararCodigo = (a, b) => a.localeCompare(b, 'pt-BR', { numeric: true });

// Mesma ordem da fila no servidor: faixa → prazo (sem prazo por último) → código.
export const ordenarFila = (lista) =>
    [...lista].sort((a, b) =>
        a.faixa_fila - b.faixa_fila
        || (a.prazo ?? '9999-12-31').localeCompare(b.prazo ?? '9999-12-31')
        || compararCodigo(a.codigo, b.codigo));
