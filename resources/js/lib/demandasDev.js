// Demandas Dev — rótulos e regras. Espelho manual das constantes de App\Models\DevDemanda.
// Classes de cor ficam em Components/DemandasDev/Selos.jsx: o Tailwind só varre .jsx.

export const STATUS_LABELS = {
    backlog:            'Backlog',
    a_fazer:            'A fazer',
    em_desenvolvimento: 'Em desenvolvimento',
    em_validacao:       'Em validação',
    bloqueado:          'Bloqueado',
    concluido:          'Concluído',
    cancelado:          'Cancelado',
};

export const PRIORIDADE_LABELS = {
    0: 'P0 - Crítica',
    1: 'P1 - Alta',
    2: 'P2 - Normal',
    3: 'P3 - Backlog',
};

export const PRIORIDADE_CURTA = { 0: 'P0', 1: 'P1', 2: 'P2', 3: 'P3' };

export const SITUACAO_LABELS = {
    bloqueado:     'Bloqueada',
    atrasada:      'Atrasada',
    prazo_proximo: 'Prazo próximo',
    no_prazo:      'No prazo',
    sem_prazo:     'Sem prazo',
    concluido:     'Concluída',
    cancelado:     'Cancelada',
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
