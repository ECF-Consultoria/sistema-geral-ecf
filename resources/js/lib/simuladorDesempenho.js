/**
 * Simulador de nota do ranking de Desempenho (/performance).
 *
 * POR QUE ISTO EXISTE COMO MÓDULO SEPARADO: o simulador precisa reproduzir,
 * no navegador, exatamente a conta que o `DesempenhoScoreService` faz no PHP.
 * Se a réplica divergir, a tela mostra "e se" que nunca aconteceria de
 * verdade — e o projeto não tem harness de render de React, então lógica
 * dentro do JSX só seria verificável no olho. Aqui fica travada por
 * `node --test` (`tests/js/simuladorDesempenho.test.js`).
 *
 * NADA AQUI É PERSISTIDO. O simulador é view-only: recebe o `ranking` já
 * calculado pelo backend, aplica os pontos que o admin digitou e devolve
 * nota/faixa/posição hipotéticas. Nenhuma chamada ao servidor, nenhuma
 * gravação em `desempenho_score_snapshots` nem em `bonus_faixas`.
 *
 * ─── O que está sendo espelhado do PHP ────────────────────────────────────
 *  1. `DesempenhoScoreService::computeNotaFinalPorIndicador()` — a nota é
 *     `(NPS + Faturamento + Margem) / 3`, com DIVISOR FIXO 3 (quick 260810-mt8)
 *     e indicador ausente entrando como ZERO no numerador. Carteira sem
 *     NENHUM indicador continua `null`, jamais 0 — ausência total de dado não
 *     é desempenho zero.
 *  2. `DesempenhoScoreService::classificarFaixa()` — primeira faixa ATIVA, em
 *     ordem de `ordem` ASC, cujo intervalo `[nota_min, nota_max]` contém a
 *     nota (inclusivo nos dois lados).
 *  3. `DesempenhoScoreService::promoverPor2MesesConsecutivos()` — regra
 *     DESEMP-08: `intermediario` vira `maximo` quando a nota é >= 5,00 OU
 *     quando o snapshot mensal do mês anterior já era `intermediario`. O
 *     "mês anterior" não é dedutível no navegador: chega pronto do backend
 *     na flag `promovivel_historico` de cada linha do ranking.
 *
 * SEM ARREDONDAMENTO INTERMEDIÁRIO — igual ao PHP. A régua de bônus tem
 * fronteira dura (4,00 separa `sem_bonus` de `basico`) e arredondar antes de
 * classificar desloca gente de faixa. Quem arredonda é a exibição.
 */

// Espelha `DesempenhoScoreService::DIVISOR_NOTA_FINAL`.
export const DIVISOR_NOTA_FINAL = 3;

// Os 3 indicadores que compõem a nota, na ordem em que aparecem na tela.
export const INDICADORES = ['nps', 'faturamento', 'margem'];

// Escala da régua por indicador — o `CompanyScoreService` pontua loja a loja
// de 1 a 5, mas a média da carteira pode cair abaixo de 1 quando parte das
// lojas não tem o indicador. O input aceita de 0 a 5 para permitir simular
// "e se esse indicador zerasse".
export const PONTO_MIN = 0;
export const PONTO_MAX = 5;

/**
 * Régua de emergência — usada só se o backend não mandar `faixas_bonus`
 * (deploy parcial, prop ausente). São os valores semeados pela migration
 * `2026_07_09_140003_seed_bonus_faixas_iniciais.php`. A régua REAL é
 * editável pelo admin em /desempenho/configuracao, então este fallback
 * jamais deve virar fonte de verdade.
 */
export const FAIXAS_FALLBACK = [
    { slug: 'sem_bonus',     nome: 'Sem bônus',     nota_min: 0.00, nota_max: 3.99, ordem: 1 },
    { slug: 'basico',        nome: 'Básico',        nota_min: 4.00, nota_max: 4.49, ordem: 2 },
    { slug: 'intermediario', nome: 'Intermediário', nota_min: 4.50, nota_max: 4.99, ordem: 3 },
    { slug: 'maximo',        nome: 'Máximo',        nota_min: 5.00, nota_max: 5.00, ordem: 4 },
];

/** `null`, `''`, `undefined` e `NaN` são todos "sem valor". */
function ehVazio(v) {
    return v === null || v === undefined || v === '' || Number.isNaN(Number(v));
}

/** Régua utilizável, ordenada por `ordem` ASC — nunca vazia. */
function reguaOrdenada(faixas) {
    const base = Array.isArray(faixas) && faixas.length ? faixas : FAIXAS_FALLBACK;
    return base.slice().sort((a, b) => (a.ordem ?? 0) - (b.ordem ?? 0));
}

/**
 * Converte o que foi digitado no input em ponto numérico.
 *
 * Aceita vírgula (o teclado brasileiro digita "4,5") e ponto. Campo vazio
 * devolve `null` — que NÃO é zero: é "esta carteira não tem o indicador",
 * exatamente a distinção que o payload do backend preserva. A diferença
 * aparece na nota (ausente soma 0 e o divisor continua 3), mas some da
 * coluna, que mostra "—".
 *
 * Fora de [0, 5] é grampeado na borda em vez de recusado — digitar "50" por
 * engano vira 5, nunca uma nota final de 16,7.
 *
 * @param  {string|number|null} texto
 * @returns {?number}
 */
export function parsePonto(texto) {
    if (texto === null || texto === undefined) return null;
    const limpo = String(texto).trim().replace(',', '.');
    if (limpo === '') return null;
    const n = Number(limpo);
    if (Number.isNaN(n)) return null;
    return Math.min(PONTO_MAX, Math.max(PONTO_MIN, n));
}

/**
 * Nota final a partir dos 3 pontos — réplica de
 * `computeNotaFinalPorIndicador()`.
 *
 * @param  {{nps: ?number, faturamento: ?number, margem: ?number}} pontos
 * @returns {?number} `null` quando os TRÊS estão ausentes.
 */
export function notaSimulada(pontos) {
    if (!pontos) return null;
    const valores = INDICADORES.map((k) => (ehVazio(pontos[k]) ? null : Number(pontos[k])));
    if (valores.every((v) => v === null)) return null;
    return valores.reduce((soma, v) => soma + (v ?? 0), 0) / DIVISOR_NOTA_FINAL;
}

/**
 * Classifica a nota na régua ativa — réplica de `classificarFaixa()`.
 * Devolve o slug ou `null` quando nenhuma faixa cobre a nota.
 */
export function classificarFaixa(nota, faixas = FAIXAS_FALLBACK) {
    if (ehVazio(nota)) return null;
    const n = Number(nota);
    for (const f of reguaOrdenada(faixas)) {
        if (n >= Number(f.nota_min) && n <= Number(f.nota_max)) return f.slug;
    }
    return null;
}

/**
 * Aplica a promoção DESEMP-08 sobre a faixa já classificada.
 *
 * @param  {?string} faixa   slug vindo de `classificarFaixa`
 * @param  {?number} nota
 * @param  {boolean} promovivelHistorico  mês anterior fechou em `intermediario`
 * @returns {{faixa: ?string, promovida: boolean}}
 */
export function aplicarPromocao(faixa, nota, promovivelHistorico = false) {
    if (faixa !== 'intermediario') return { faixa, promovida: false };
    if (!ehVazio(nota) && Number(nota) >= 5.0) return { faixa: 'maximo', promovida: true };
    if (promovivelHistorico) return { faixa: 'maximo', promovida: true };
    return { faixa: 'intermediario', promovida: false };
}

/**
 * Pontos EFETIVOS de uma linha: o que o admin digitou quando digitou, senão o
 * valor cru do backend.
 *
 * O valor cru importa: `pontos_componentes` chega fracionário (ex.: 3,8261) e
 * a coluna exibe 3,83. Usar o exibido como base faria a nota simulada de uma
 * linha INTOCADA divergir da nota real na segunda casa — e o simulador
 * pareceria estar mentindo antes de qualquer edição.
 *
 * @param  {object} linha    item do `ranking`
 * @param  {object} edicao   `{nps?, faturamento?, margem?}` já parseado; chave
 *                           ausente = não editado (≠ chave com `null`, que é
 *                           "o admin apagou o campo")
 */
export function pontosEfetivos(linha, edicao = {}) {
    const base = linha?.pontos_componentes ?? {};
    const ed = edicao ?? {};
    const out = {};
    for (const k of INDICADORES) {
        out[k] = Object.prototype.hasOwnProperty.call(ed, k)
            ? ed[k]
            : (ehVazio(base[k]) ? null : Number(base[k]));
    }
    return out;
}

/** Uma linha do ranking foi tocada pelo admin? */
export function linhaEditada(linha, edicao = {}) {
    const ed = edicao ?? {};
    const base = linha?.pontos_componentes ?? {};
    return INDICADORES.some((k) => {
        if (!Object.prototype.hasOwnProperty.call(ed, k)) return false;
        const original = ehVazio(base[k]) ? null : Number(base[k]);
        return ed[k] !== original;
    });
}

/**
 * Aplica a simulação em UMA linha do ranking.
 *
 * Linha `calculando` (cache frio, warm rodando em background) não é
 * simulável — não há pontos para editar. Sai marcada e sem nota.
 *
 * `score_status === 'blocked'` (carteira sem nenhuma fonte financeira) NÃO
 * zera a nota simulada como o backend faz com a oficial: aqui o objetivo é
 * justamente ver quanto daria. O badge de status continua na tela para que
 * ninguém confunda a simulação com nota oficial.
 */
export function simularLinha(linha, edicao = {}, faixas = FAIXAS_FALLBACK) {
    const calculando = linha?.calculando === true;
    const pontos = pontosEfetivos(linha, edicao);
    const nota = calculando ? null : notaSimulada(pontos);
    const promocao = aplicarPromocao(
        classificarFaixa(nota, faixas),
        nota,
        linha?.promovivel_historico === true,
    );

    return { linha, pontos, nota, faixa: promocao.faixa, promovida: promocao.promovida, calculando };
}

/**
 * Roda a simulação no ranking inteiro e reordena.
 *
 * Ordenação idêntica à do backend (`sortByDesc(nota ?? -1)`): nota mais alta
 * primeiro, `null` sempre no fim, empate preservando a ordem que já estava na
 * tela (ordenação estável). `posicao_simulada` é 1-based; `delta_posicao` é
 * positivo quando a pessoa SOBE no ranking simulado.
 *
 * @param  {Array}  ranking  já filtrado pela tela (contexto/cargo)
 * @param  {object} edicoes  `{ [userId]: {nps?, faturamento?, margem?} }`
 * @param  {Array}  faixas   régua ativa vinda do backend
 */
export function simularRanking(ranking = [], edicoes = {}, faixas = FAIXAS_FALLBACK) {
    const simuladas = (ranking ?? []).map((linha, idx) => {
        const edicao = edicoes?.[linha.id] ?? {};
        const sim = simularLinha(linha, edicao, faixas);
        const notaReal = ehVazio(linha.nota_final) ? null : Number(linha.nota_final);

        return {
            ...linha,
            posicao_original: linha.posicao ?? idx + 1,
            ordem_original: idx,
            pontos_simulados: sim.pontos,
            nota_simulada: sim.nota,
            nota_real: notaReal,
            delta_nota: sim.nota !== null && notaReal !== null ? sim.nota - notaReal : null,
            faixa_simulada: sim.faixa,
            faixa_promovida_simulada: sim.promovida,
            faixa_mudou: (sim.faixa ?? null) !== (linha.faixa_bonus ?? null),
            editada: linhaEditada(linha, edicao),
            calculando: sim.calculando,
        };
    });

    return simuladas
        .slice()
        .sort((a, b) => {
            const na = a.nota_simulada ?? -1;
            const nb = b.nota_simulada ?? -1;
            if (nb !== na) return nb - na;
            return a.ordem_original - b.ordem_original;
        })
        .map((r, idx) => ({
            ...r,
            posicao_simulada: idx + 1,
            delta_posicao: r.posicao_original - (idx + 1),
        }));
}

/**
 * Conta quantas pessoas ficam em cada faixa, antes e depois da simulação.
 * Alimenta o rodapé "distribuição de faixas" — é o número que interessa a
 * quem simula custo de bônus.
 *
 * @returns {Array<{slug, nome, antes, depois, delta}>} na ordem da régua.
 */
export function resumoFaixas(linhasSimuladas = [], faixas = FAIXAS_FALLBACK) {
    return reguaOrdenada(faixas).map((f) => {
        const antes = (linhasSimuladas ?? []).filter((r) => (r.faixa_bonus ?? null) === f.slug).length;
        const depois = (linhasSimuladas ?? []).filter((r) => (r.faixa_simulada ?? null) === f.slug).length;
        return { slug: f.slug, nome: f.nome, antes, depois, delta: depois - antes };
    });
}
