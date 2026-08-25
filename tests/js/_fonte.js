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
    //
    // ATENÇÃO ao split: ele PRECISA comer o \r do CRLF. Sem isso o \r sobra no
    // fim de cada linha e, como `.` não casa terminador de linha e o `$` aqui é
    // fim-de-STRING (não há flag `m`), o replace abaixo não remove absolutamente
    // nada em arquivo CRLF — o gate volta a ler comentário e passa pelo motivo
    // errado, que é exatamente o que este helper existe para impedir. Só apareceu
    // quando AppLayout.jsx (único CRLF entre os arquivos com gate) ganhou o seu;
    // os demais são LF e por isso nunca acusaram.
    return semBloco
        .split(/\r?\n/)
        .map((linha) => linha.replace(/(^|[^:])\/\/.*$/, '$1'))
        .join('\n');
}
