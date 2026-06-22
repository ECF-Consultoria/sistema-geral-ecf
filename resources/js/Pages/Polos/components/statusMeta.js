// Identidade de status dos polos/empresas — compartilhada entre os componentes
// do Cockpit (RankingProgresso, StatusDonut) e o drawer. Extraída de Index.jsx
// para evitar divergência de cores/labels entre as visões.

// Cor + label por status. Mesmas cores históricas (réplica do "Gráfico Junho").
export const STATUS_META = {
    'Sim':          { cor: '#22c55e', label: 'No alvo' },
    'Em progresso': { cor: '#ffe600', label: 'Em progresso' },
    'Não':          { cor: '#ef4444', label: 'Não' },
    'Problema':     { cor: '#a855f7', label: 'Problema' },
};

// Ordem fixa de exibição (legendas e anel do donut).
export const STATUS_ORDEM = ['Sim', 'Em progresso', 'Não', 'Problema'];
