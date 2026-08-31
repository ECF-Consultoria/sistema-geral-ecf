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

// ═══════════════════════════════════════════════════════════════════════
// NÍVEL EMPRESA — simulador detalhado (2026-08-31)
//
// A régua de 1 a 5 é aplicada LOJA A LOJA pelo `CompanyScoreService`; o que
// o `DesempenhoScoreService` faz depois é só promediar. O simulador detalhado
// entra justamente nessa camada de baixo: edita o ponto da loja e deixa a
// média subir sozinha para o colaborador.
//
// A carteira por empresa vem do SNAPSHOT congelado da competência, então só
// existe em mês FECHADO — que é onde o bônus é decidido, e portanto onde a
// simulação importa. No mês em curso não há linhas para editar.
// ═══════════════════════════════════════════════════════════════════════

/** Nomes dos campos de ponto na linha por empresa (diferem do nível carteira). */
const CAMPO_EMPRESA = {
    nps:         'nps_pontos',
    faturamento: 'faturamento_pontos',
    margem:      'margem_pontos',
};

/**
 * Quantos componentes esta loja DEVE ter para fechar nota própria.
 *
 * Réplica de `CompanyScoreService`: Shopee sem margem espera 2 (a plataforma
 * não fornece CMV, e cobrar dela um componente inexistente rebaixaria o
 * profissional sem culpa); com margem presente — inclusive a lançada
 * manualmente — espera 3, como qualquer outra fonte.
 *
 * Derivado, e não lido do `componentes_esperados` da linha, porque o
 * simulador PODE preencher a margem de uma loja Shopee: nesse caso ela passa
 * a esperar 3, exatamente como a margem manual faz no motor. Usar o campo
 * congelado deixaria a loja com 3 presentes contra 2 esperados e ela cairia
 * em `partial` por um preenchimento que deveria completá-la.
 */
export function componentesEsperados(linha, pontos) {
    const fonte = linha?.fonte_financeira ?? null;
    return fonte === 'shopee' && ehVazio(pontos?.margem) ? 2 : 3;
}

/** Pontos efetivos de UMA loja: o editado quando editado, senão o do snapshot. */
export function pontosEfetivosEmpresa(linha, edicao = {}) {
    const ed = edicao ?? {};
    const out = {};
    for (const k of INDICADORES) {
        const base = linha?.[CAMPO_EMPRESA[k]];
        out[k] = Object.prototype.hasOwnProperty.call(ed, k)
            ? ed[k]
            : (ehVazio(base) ? null : Number(base));
    }
    return out;
}

/**
 * Simula UMA loja: pontos, nota estrita, nota parcial e status.
 *
 * Réplica de `CompanyScoreService` (D-01): `nota_empresa` é ESTRITA — só
 * existe quando todos os componentes esperados estão presentes. A parcial é
 * a média dos presentes e serve de leitura auxiliar, nunca de nota.
 */
export function simularEmpresa(linha, edicao = {}) {
    const pontos = pontosEfetivosEmpresa(linha, edicao);
    const presentes = INDICADORES.map((k) => pontos[k]).filter((v) => !ehVazio(v));
    const esperados = componentesEsperados(linha, pontos);

    const parcial = presentes.length === 0
        ? null
        : presentes.reduce((s, v) => s + Number(v), 0) / presentes.length;

    const status = presentes.length === esperados
        ? 'complete'
        : (presentes.length === 0 ? 'sem_dados' : 'partial');

    return {
        ...linha,
        pontos_simulados: pontos,
        componentes_presentes: presentes.length,
        componentes_esperados: esperados,
        nota_empresa_simulada: presentes.length === esperados ? parcial : null,
        nota_empresa_parcial_simulada: parcial,
        status_simulado: status,
        editada: INDICADORES.some((k) => {
            const ed = edicao ?? {};
            if (!Object.prototype.hasOwnProperty.call(ed, k)) return false;
            const base = linha?.[CAMPO_EMPRESA[k]];
            return ed[k] !== (ehVazio(base) ? null : Number(base));
        }),
    };
}

/**
 * Média por indicador sobre as lojas — réplica de
 * `computeNotaFinalPorIndicador()`.
 *
 * DENOMINADOR INDEPENDENTE por indicador, como o `AVERAGE` do Excel ignora
 * célula vazia: loja sem margem continua contando no faturamento e no NPS,
 * em vez de sair da conta inteira. Não confundir com
 * `computeNotaFinalPorEmpresa()`, que exige a loja `complete` e descarta a
 * linha toda — são métodos diferentes, e o oficial desde 2026-08-05 é este.
 *
 * Entram TODAS as lojas incluídas, inclusive `partial` e `sem_fonte`: quem
 * decide participação por indicador é a presença do valor, não o status.
 */
export function mediasPorIndicador(empresasSimuladas = []) {
    const medias = {};
    for (const k of INDICADORES) {
        const presentes = (empresasSimuladas ?? [])
            .map((e) => e.pontos_simulados?.[k])
            .filter((v) => !ehVazio(v))
            .map(Number);
        medias[k] = presentes.length === 0
            ? null
            : presentes.reduce((s, v) => s + v, 0) / presentes.length;
    }
    return medias;
}

/**
 * Simula a carteira inteira de um profissional.
 *
 * `excluidas` remove a loja das médias — simula o efeito de uma
 * `BonusInvalidacao` na competência, sem gravar nada. A loja continua na
 * tela, riscada, para não sumir da conferência.
 *
 * @param  {Array}  empresas  linhas do snapshot por empresa
 * @param  {object} edicoes   `{ [companyId]: {nps?, faturamento?, margem?} }`
 * @param  {Array}  excluidas ids de empresa fora da conta
 */
export function simularCarteira(empresas = [], edicoes = {}, excluidas = []) {
    const fora = new Set((excluidas ?? []).map(Number));

    const linhas = (empresas ?? []).map((e) => {
        const sim = simularEmpresa(e, edicoes?.[e.company_id] ?? {});
        return { ...sim, incluida: !fora.has(Number(e.company_id)) };
    });

    const incluidas = linhas.filter((l) => l.incluida);
    const medias = mediasPorIndicador(incluidas);

    return {
        linhas,
        medias,
        nota: notaSimulada(medias),
        qtd_incluidas: incluidas.length,
        qtd_excluidas: linhas.length - incluidas.length,
        // "Tocada" = algum ponto editado OU alguma loja fora da conta. É o que
        // decide se os pontos do colaborador passam a vir daqui (ver
        // `simularRanking`) — sem isso, abrir o detalhe já sequestraria o
        // número de cima sem o usuário ter mudado nada.
        tocada: linhas.some((l) => l.editada) || fora.size > 0,
    };
}

/**
 * Roda a simulação no ranking inteiro e reordena.
 *
 * Ordenação idêntica à do backend (`sortByDesc(nota ?? -1)`): nota mais alta
 * primeiro, `null` sempre no fim, empate preservando a ordem que já estava na
 * tela (ordenação estável). `posicao_simulada` é 1-based; `delta_posicao` é
 * positivo quando a pessoa SOBE no ranking simulado.
 *
 * ─── Precedência entre os dois níveis de edição ───────────────────────────
 * Quando a carteira do profissional foi TOCADA no detalhe (ponto de loja
 * editado ou loja tirada da conta), os três pontos dele passam a ser as
 * médias das lojas, e a edição direta de cima é ignorada. É proposital: dois
 * donos para o mesmo número produziria uma tela em que o valor exibido não
 * corresponde a nenhuma das duas edições. A UI desabilita os campos de cima
 * nesse estado e diz de onde o número está vindo.
 *
 * Abrir o detalhe sem mexer em nada NÃO muda nada — `tocada` só vira true
 * com edição de fato.
 *
 * @param  {Array}  ranking    já filtrado pela tela (contexto/cargo)
 * @param  {object} edicoes    `{ [userId]: {nps?, faturamento?, margem?} }`
 * @param  {Array}  faixas     régua ativa vinda do backend
 * @param  {object} carteiras  `{ [userId]: {empresas, edicoes, excluidas} }`
 *                             detalhe por empresa já carregado do servidor
 */
export function simularRanking(ranking = [], edicoes = {}, faixas = FAIXAS_FALLBACK, carteiras = {}) {
    const simuladas = (ranking ?? []).map((linha, idx) => {
        const edicao = edicoes?.[linha.id] ?? {};
        const carteiraBruta = carteiras?.[linha.id] ?? null;

        const carteira = carteiraBruta
            ? simularCarteira(carteiraBruta.empresas, carteiraBruta.edicoes, carteiraBruta.excluidas)
            : null;

        // O detalhe só assume o comando depois de tocado (ver bloco acima).
        const derivado = !!carteira?.tocada;

        const sim = derivado
            ? (() => {
                const nota = carteira.nota;
                const promocao = aplicarPromocao(
                    classificarFaixa(nota, faixas),
                    nota,
                    linha?.promovivel_historico === true,
                );
                return {
                    pontos: carteira.medias,
                    nota,
                    faixa: promocao.faixa,
                    promovida: promocao.promovida,
                    calculando: linha?.calculando === true,
                };
            })()
            : simularLinha(linha, edicao, faixas);

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
            editada: derivado || linhaEditada(linha, edicao),
            calculando: sim.calculando,
            // Nível empresa — `null` quando o detalhe não foi carregado.
            carteira,
            derivado_das_lojas: derivado,
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
