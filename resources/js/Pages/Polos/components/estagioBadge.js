// Mapa canônico de cor do ESTÁGIO (progressão de SKUs/listagem da empresa).
// Extraído de Mlb/Implementacao.jsx para padronizar Onboarding + Painel Polos num
// único módulo (UX-3). Mantém as strings EXATAS do domínio — são chaves de
// comparação no código/banco; 'Concluido' é SEM acento de propósito.
export const ESTAGIO_COLORS = {
    'Não Listado': 'text-white/30 bg-white/[0.04]',
    'Estágio 1':   'text-blue-300 bg-blue-500/10',
    'Estágio 2':   'text-violet-300 bg-violet-500/10',
    'Estágio 3':   'text-amber-300 bg-amber-500/10',
    'Concluido':   'text-emerald-300 bg-emerald-500/10',
    'Churn':       'text-red-300 bg-red-500/10',
    'Finalizado':  'text-white/40 bg-white/[0.04]',
    'Problema':    'text-red-400 bg-red-500/10',
};

// Classe do badge de estágio (fallback neutro p/ valores fora do mapa).
export const corEstagio = (estagio) => ESTAGIO_COLORS[estagio] ?? 'text-white/30 bg-white/[0.04]';
