// Phase 21 — Catálogo central do Manual do Sistema.
// Fonte única de verdade. Adicionar novo artigo = (1) criar Manual/Artigos/Foo.jsx + (2) adicionar entry aqui.

import Cronograma from './Artigos/Cronograma';

export const ARTIGOS = {
    cronograma: {
        slug: 'cronograma',
        titulo: 'Cronograma de horários',
        categoria: 'Operação do sistema',
        descricao: 'Veja o que o sistema faz automaticamente em cada horário do dia — sem termos técnicos.',
        Component: Cronograma,
    },
};

export function listarArtigos() {
    return Object.values(ARTIGOS);
}

export function buscarArtigo(slug) {
    return ARTIGOS[slug] ?? null;
}
