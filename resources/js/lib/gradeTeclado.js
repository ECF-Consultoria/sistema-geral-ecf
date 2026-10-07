// ═══════════════════════════════════════════════════════════════════════
// Lógica PURA do teclado do SpreadsheetGrid (sem React, sem DOM).
//
// POR QUE EXISTE: colar 70 linhas do Excel gravava 10 (o excedente era
// descartado em silêncio) e o Tab não pulava coluna calculada nem criava linha.
// Separar a regra da tela deixa as duas coisas testáveis por comportamento.
// ═══════════════════════════════════════════════════════════════════════

/**
 * Converte o texto colado do Excel (TSV) em matriz de células.
 * Respeita aspas do Excel: célula entre aspas pode ter tab e quebra de linha, e
 * `""` dentro dela vira uma aspa. CRLF e LF dão o mesmo resultado. O Excel
 * termina o texto com uma quebra de linha — a última linha vazia some.
 *
 * @param {string} texto
 * @returns {string[][]}
 */
export function lerTsvDoExcel(texto) {
    const s = String(texto ?? '');
    const linhas = [];
    let linha = [];
    let cel = '';
    let entreAspas = false;
    let celComAspas = false;

    const fecharCelula = () => { linha.push(cel); cel = ''; celComAspas = false; };
    const fecharLinha = () => { fecharCelula(); linhas.push(linha); linha = []; };

    for (let i = 0; i < s.length; i++) {
        const ch = s[i];
        if (entreAspas) {
            if (ch === '"') {
                if (s[i + 1] === '"') { cel += '"'; i++; } else entreAspas = false;
            } else {
                cel += ch;
            }
            continue;
        }
        if (ch === '"' && cel === '' && !celComAspas) { entreAspas = true; celComAspas = true; continue; }
        if (ch === '\t') { fecharCelula(); continue; }
        if (ch === '\r') { if (s[i + 1] === '\n') i++; fecharLinha(); continue; }
        if (ch === '\n') { fecharLinha(); continue; }
        cel += ch;
    }
    // Resto sem quebra no fim: é a última linha.
    if (cel !== '' || celComAspas || linha.length > 0) fecharLinha();

    // O Excel manda uma quebra no fim — a "linha" vazia resultante não existe.
    const ultima = linhas[linhas.length - 1];
    if (ultima && ultima.length === 1 && ultima[0] === '') linhas.pop();
    return linhas;
}

const editavel = (col) => !!col && col.type !== 'readonly' && !col.compute;

/**
 * Próxima célula editável para o Tab.
 * Anda só pelas colunas editáveis (pula `readonly` e com `compute`), passa para a
 * linha de baixo no fim da linha e, depois da última linha, pede linha nova.
 * Com passo -1 (Shift+Tab) volta; no começo da 1ª linha fica parado.
 *
 * @param {Array<{type?: string, compute?: Function}>} colunas
 * @param {number} r linha atual
 * @param {number} c coluna atual
 * @param {1|-1} passo
 * @param {number} totalLinhas
 * @returns {{r: number, c: number} | {criarLinha: true}}
 */
export function proximaEditavel(colunas, r, c, passo, totalLinhas) {
    const idx = [];
    colunas.forEach((col, i) => { if (editavel(col)) idx.push(i); });
    if (!idx.length) return { r, c };

    if (passo >= 0) {
        const prox = idx.find((i) => i > c);
        if (prox !== undefined) return { r, c: prox };
        if (r + 1 >= totalLinhas) return { criarLinha: true };
        return { r: r + 1, c: idx[0] };
    }

    const ant = [...idx].reverse().find((i) => i < c);
    if (ant !== undefined) return { r, c: ant };
    if (r - 1 < 0) return { r, c };
    return { r: r - 1, c: idx[idx.length - 1] };
}
