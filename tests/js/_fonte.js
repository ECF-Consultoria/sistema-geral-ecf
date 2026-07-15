import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// ═══════════════════════════════════════════════════════════════════════
// Helper dos gates estruturais dos planos 83-02/03/04.
//
// POR QUE EXISTE: os comentários deste projeto são prosa em pt-BR que cita os
// próprios identificadores do código ("o merge chama mesclarStatusRascunhos...").
// Um gate por `grep -c` contaria o comentário e passaria pelo motivo errado —
// exatamente o tipo de gate falso que queimou a Fase 82. Este helper devolve a
// fonte SEM comentários, para os gates lerem só o que o runtime enxerga.
// ═══════════════════════════════════════════════════════════════════════

/**
 * Lê um arquivo do repo e devolve a fonte sem comentários.
 * @param {string} caminhoRelativoAoRepo ex: 'resources/js/Pages/Mlb/AnunciarMassa.jsx'
 * @returns {string} fonte sem blocos /* *\/ e sem // até o fim da linha
 */
export function lerSemComentarios(caminhoRelativoAoRepo) {
    const raiz = resolve(import.meta.dirname, '../..');
    const bruto = readFileSync(resolve(raiz, caminhoRelativoAoRepo), 'utf8');

    // Remove blocos /* ... */ (inclui JSDoc)
    const semBloco = bruto.replace(/\/\*[\s\S]*?\*\//g, '');

    // Remove // até o fim da linha — mas não quando precedido de ':' (poupa https://)
    return semBloco
        .split('\n')
        .map((linha) => linha.replace(/(^|[^:])\/\/.*$/, '$1'))
        .join('\n');
}
