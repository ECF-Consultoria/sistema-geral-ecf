// Phase 21 — Catálogo central do Manual do Sistema.
// Fonte única de verdade. Adicionar novo artigo = (1) criar Manual/Artigos/Foo.jsx + (2) adicionar entry aqui.
//
// Phase 74 D-23 · adiciona entry 'desempenho-bonificacao' — artigo dinâmico
// sincronizado com a tabela `bonus_faixas` via ManualController::show().

import Cronograma from './Artigos/Cronograma';
import DesempenhoBonificacao from './Artigos/DesempenhoBonificacao';

export const ARTIGOS = {
    cronograma: {
        slug: 'cronograma',
        titulo: 'Cronograma de horários',
        categoria: 'Operação do sistema',
        descricao: 'Veja o que o sistema faz automaticamente em cada horário do dia — sem termos técnicos.',
        Component: Cronograma,
    },
    'desempenho-bonificacao': {
        slug: 'desempenho-bonificacao',
        titulo: 'Régua de Bonificação — Desempenho',
        categoria: 'Módulo Desempenho',
        descricao: 'Como calculamos a nota final e a faixa de bônus mensal do time Performance.',
        Component: DesempenhoBonificacao,
    },
};

export function listarArtigos() {
    return Object.values(ARTIGOS);
}

export function buscarArtigo(slug) {
    return ARTIGOS[slug] ?? null;
}
