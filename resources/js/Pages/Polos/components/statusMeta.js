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

/**
 * Distribuição de status DENTRO de um polo — o mesmo recorte do donut "Distribuição de
 * status", só que por região.
 *
 * Por que fecha com o donut: `PolosController::agregarPorPolo()` e `distribuicaoStatus()`
 * varrem a MESMA lista de ativos e chamam o MESMO `calcularStatus()`; cada ativo cai em
 * exatamente um polo (localidade do CSV, com fallback em `MlbEmpresa.polo`). Logo somar
 * `contagem[k]` de todos os polos devolve o `statusDist[k]` do donut — desde que os polos
 * não estejam filtrados na tela (em `Polos/Index` os chips podem esconder polos; aí a soma
 * é do subconjunto visível, não do donut).
 *
 * Status desconhecido (shape inesperado do backend) é contado no total mas em nenhum balde:
 * some da barra em vez de derrubar a tela.
 *
 * @param {Array<{status?: string}>} empresas empresas do polo (`polo.empresas`)
 * @returns {{contagem: Record<string, number>, total: number, noAlvo: number, pctNoAlvo: number}}
 */
export function distribuirStatus(empresas = []) {
    const contagem = { 'Sim': 0, 'Em progresso': 0, 'Não': 0, 'Problema': 0 };
    const lista = Array.isArray(empresas) ? empresas : [];

    for (const e of lista) {
        const s = e?.status;
        // hasOwnProperty, não `in`: `in` acha 'toString'/'constructor' na cadeia de protótipo
        // e um status assim viraria uma chave lixo no objeto de contagem.
        if (Object.prototype.hasOwnProperty.call(contagem, s)) contagem[s] += 1;
    }

    const total  = lista.length;
    const noAlvo = contagem['Sim'];

    return {
        contagem,
        total,
        noAlvo,
        // "% no alvo" = fatia verde do donut recortada neste polo. 0 quando o polo não tem ativo.
        pctNoAlvo: total > 0 ? Math.round((noAlvo / total) * 100) : 0,
    };
}
