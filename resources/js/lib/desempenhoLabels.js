/**
 * Vocabulário pt-BR e formatadores da Fase 123 (telas e relatórios v21.0).
 *
 * Fonte única do texto das três telas que passam a mostrar o detalhe por
 * empresa (Performance/Show, Relatório de Bonificação, Auditoria de Bônus):
 * os mesmos 6 motivos, o mesmo selo de Shopee e o mesmo formato de margem
 * `14,1% → 12,0%  −2,1` aparecem nas três — copiar o texto três vezes
 * garante que uma delas fica desatualizada quando o texto mudar.
 *
 * Também concentra as três regras puras de particionamento da lista (quem
 * entra na conta, carteira toda-Shopee, colapso da segunda seção): o projeto
 * não tem harness de renderização de React, então lógica de ramificação
 * dentro do JSX só é verificável por grep/olho humano — extraída para cá,
 * fica travada por teste (`node --test`).
 *
 * Nenhuma régua, nenhuma agregação e nenhum cálculo de negócio mora aqui —
 * só tradução, formatação e particionamento de dado já pronto.
 */

// ─── Motivos de `quality.motivos` (enumeração fechada — CompanyScoreService) ─
// Os 6 slugs são um conjunto fechado hoje. `motivoLabel()` nunca quebra nem
// devolve `undefined` diante de um 7º slug futuro — cai no fallback legível.
export const MOTIVO_LABEL = {
    sem_fonte_financeira:     'Sem fonte financeira vinculada',
    faturamento_sem_baseline: 'Sem mês anterior para comparar o faturamento',
    margem_pp_indisponivel:   'Sem dado de margem da Adman neste mês',
    nps_nao_elegivel:         'Empresa não elegível para NPS neste mês',
    nps_janela_aberta:        'Janela de coleta de NPS ainda aberta',
    nps_indisponivel:         'Sem dado de NPS disponível',
};

/**
 * Traduz um slug de `quality.motivos`. Slug desconhecido (um 7º motivo
 * criado no futuro) devolve o próprio slug com underscores trocados por
 * espaço — nunca `undefined`, nunca crash, nunca `snake_case` cru na tela.
 */
export function motivoLabel(slug) {
    if (Object.prototype.hasOwnProperty.call(MOTIVO_LABEL, slug)) {
        return MOTIVO_LABEL[slug];
    }
    return String(slug ?? '').replace(/_/g, ' ');
}

// ─── Status da linha por empresa ──────────────────────────────────────────
// `status` é string, NUNCA enum — o serviço pode ganhar valores novos.
export const STATUS_EMPRESA_LABEL = {
    complete:  'Completa',
    partial:   'Parcial',
    sem_dados: 'Sem dados',
};

/** Fallback ao próprio slug quando o serviço ganhar um status novo. */
export function statusEmpresaLabel(slug) {
    return STATUS_EMPRESA_LABEL[slug] ?? slug;
}

// ─── Formatadores numéricos pt-BR ─────────────────────────────────────────
// Todos aceitam null/undefined/NaN e devolvem '—'.

function ehVazio(v) {
    return v == null || Number.isNaN(Number(v));
}

/** Percentual absoluto com 1 casa e vírgula decimal: 14.1 → '14,1%'. */
export function fmtPctAbs(v) {
    if (ehVazio(v)) return '—';
    return `${Number(v).toFixed(1).replace('.', ',')}%`;
}

/**
 * Variação em pontos percentuais, SEM '%' — somar '%' aqui reintroduz
 * exatamente a confusão que a D-05 existe para evitar. Sinal '+' quando
 * positivo, MINUS SIGN (U+2212) quando negativo, sem sinal quando zero.
 * -2.1 → '−2,1'; 3.2 → '+3,2'; 0 → '0,0'.
 */
export function fmtPp(v) {
    if (ehVazio(v)) return '—';
    const n = Number(v);
    const abs = Math.abs(n).toFixed(1).replace('.', ',');
    if (n > 0) return `+${abs}`;
    if (n < 0) return `−${abs}`;
    return abs;
}

/**
 * Variação relativa COM '%' e sinal, mesmo padrão de sinal do `fmtPp`:
 * 12.34 → '+12,3%'.
 */
export function fmtVarPct(v) {
    if (ehVazio(v)) return '—';
    const n = Number(v);
    const abs = Math.abs(n).toFixed(1).replace('.', ',');
    if (n > 0) return `+${abs}%`;
    if (n < 0) return `−${abs}%`;
    return `${abs}%`;
}

/** Nota com 2 casas e vírgula: 4.25 → '4,25'. */
export function fmtNotaEmpresa(v) {
    if (ehVazio(v)) return '—';
    return Number(v).toFixed(2).replace('.', ',');
}

/**
 * Formato travado pela D-05 — margem por empresa como antes → depois +
 * variação, nesta ordem (anterior primeiro, atual depois):
 * `fmtMargemAntesDepois(14.1, 12.0, -2.1) === '14,1% → 12,0%  −2,1'`.
 *
 * Quando os dois absolutos são nulos mas `pp` existe, devolve só `fmtPp(pp)`.
 * Quando tudo é nulo, devolve '—'.
 */
export function fmtMargemAntesDepois(anterior, atual, pp) {
    const temAnterior = !ehVazio(anterior);
    const temAtual = !ehVazio(atual);

    if (temAnterior && temAtual) {
        return `${fmtPctAbs(anterior)} → ${fmtPctAbs(atual)}  ${fmtPp(pp)}`;
    }

    if (!ehVazio(pp)) {
        return fmtPp(pp);
    }

    return '—';
}

/**
 * Frase de UIEM-01 em linguagem simples, para o sublabel do card agregado.
 * Devolve `null` quando `v` é nulo/indefinido.
 */
export function fraseVarMargemPp(v) {
    if (v == null) return null;
    const n = Number(v);
    if (Number.isNaN(n)) return null;

    const abs = Math.abs(n).toFixed(1).replace('.', ',');

    if (abs === '0,0') {
        return 'A margem ficou estável na média da carteira.';
    }

    const unidade = abs === '1,0' ? 'ponto percentual' : 'pontos percentuais';
    const verbo = n > 0 ? 'subiu' : 'caiu';

    return `A margem ${verbo} ${abs} ${unidade} na média da carteira.`;
}

/**
 * Gatilho do selo da D-07 — o campo semântico criado exatamente para isso
 * em `CompanyScoreService`, NUNCA `fonte_financeira === 'shopee'` direto.
 * Aceita `undefined` sem quebrar.
 */
export function ehPlaceholderShopee(linha) {
    return linha?.quality?.margin_source === 'placeholder_shopee';
}

// ─── Constantes de texto (pt-BR, sem jargão — texto final) ────────────────

export const MARGEM_CARD_TITULO = 'Margem';

export const MARGEM_CARD_SUBLABEL = 'Variação relativa da margem contra a janela anterior, na média das empresas da carteira. Ex.: de 10% para 11% aparece como +10%.';

export const SELO_SHOPEE_TEXTO = 'Shopee: sem dado de margem';

export const SELO_SHOPEE_TITULO = 'A Shopee não fornece margem. A empresa entra na conta com 1,00 ponto fixo — é limitação da fonte, não desempenho ruim.';

export const AVISO_CARTEIRA_SO_SHOPEE = 'Carteira só de Shopee — a Shopee não fornece margem. Todas as empresas entram na conta com 1,00 ponto fixo de margem, então a nota reflete essa limitação da fonte, não o desempenho.';

export const MARGEM_SEM_DADO_TEXTO = 'sem dado de margem';

export const AVISO_SEM_DETALHE_TITULO = 'Detalhe por empresa indisponível';

export const AVISO_SEM_DETALHE_EM_CURSO = 'A lista por empresa aparece só depois que o mês é fechado. Este é o mês em curso — os números ainda mudam até o fechamento.';

/** Aviso da D-03 para competência fechada sem detalhe gravado. */
export function avisoSemDetalheFechado(mesLabel) {
    return `Ainda não há detalhe por empresa para ${mesLabel}. O detalhe passou a ser gravado no fechamento a partir de agosto/2026 — competências consolidadas antes disso não têm esse registro.`;
}

/** Título da seção "entraram na conta" com o denominador explícito (D-06). */
export function tituloEntraram(n) {
    return `Entraram na conta (${n})`;
}

/** Título da seção "não entraram" com o denominador explícito (D-06). */
export function tituloNaoEntraram(m) {
    return `Não entraram (${m})`;
}

export const SECAO_ENTRARAM_SUBTITULO = 'Empresas com os três componentes disponíveis (NPS, faturamento e margem). São elas que fecham nota individual nesta competência.';

export const SECAO_NAO_ENTRARAM_SUBTITULO = 'Empresas sem os três componentes — não fecham nota individual. O motivo de cada uma aparece ao lado.';

/**
 * Obrigatória e não é decoração: a flag `metrics.performance_company_first_score`
 * está `false`, então a `nota_final` do topo NÃO é a média das `nota_empresa`
 * listadas abaixo. Sem esta frase a tela põe dois números contraditórios
 * lado a lado — exatamente o que a D-04 proíbe.
 */
export const NOTA_TOPO_RESSALVA = 'A nota do topo continua vindo do cálculo por carteira. As notas por empresa abaixo mostram como cada empresa foi avaliada no fechamento.';

export const LIMIAR_COLAPSO_NAO_ENTRARAM = 8;

/**
 * Selo de safra da Auditoria de Bônus (CR-02/gap 2 do 123-VERIFICATION.md,
 * fechado no 123-08-PLAN.md). Aparece ao lado da nota do profissional
 * SOMENTE quando ela veio de recomputo ao vivo (`nota_congelada === false`)
 * — nunca quando o campo está ausente, para não gerar alarme falso num
 * payload antigo sem essa chave.
 */
export const NOTA_RECALCULADA_TEXTO = 'recalculada agora';

export const NOTA_RECALCULADA_TITULO = 'Esta nota foi recalculada ao vivo nesta consulta e pode divergir das notas por empresa abaixo, que são as congeladas no fechamento do mês. Isso não significa erro de cálculo — são duas leituras de momentos diferentes.';

// ─── Regras puras de particionamento da lista ─────────────────────────────
// Moram aqui, não dentro do JSX — o projeto não tem harness de renderização
// de React, então extraídas ficam travadas por teste desde a wave 1.

/**
 * Particiona as linhas em `{ entraram, naoEntraram }` PRESERVANDO a ordem
 * canônica já aplicada pelo reader — particiona, nunca reordena. Critério é
 * `status === 'complete'`, o MESMO usado por `CompanyScoreSnapshotReader::resumo()`
 * e por `DesempenhoScoreService::computeNotaFinalPorEmpresa()`.
 *
 * Aceita null/undefined/[] e devolve `{ entraram: [], naoEntraram: [] }` — a
 * tela nunca pode receber `undefined.length`.
 */
export function dividirPorEntrada(linhas) {
    const lista = Array.isArray(linhas) ? linhas : [];
    const entraram = [];
    const naoEntraram = [];

    for (const linha of lista) {
        if (linha?.status === 'complete') {
            entraram.push(linha);
        } else {
            naoEntraram.push(linha);
        }
    }

    return { entraram, naoEntraram };
}

/**
 * Gatilho do caso Matheus Estrela (carteira só-Shopee): `true` somente
 * quando `entraram` tem ao menos 1 item e TODOS satisfazem
 * `ehPlaceholderShopee()`. Array vazio devolve `false` — não existe "toda"
 * carteira quando não há carteira.
 */
export function carteiraTodaShopeeNaEntrada(entraram) {
    const lista = Array.isArray(entraram) ? entraram : [];
    if (lista.length === 0) return false;
    return lista.every((linha) => ehPlaceholderShopee(linha));
}

/**
 * Estado inicial do colapso da segunda seção: `true` quando
 * `qtdNaoEntraram > LIMIAR_COLAPSO_NAO_ENTRARAM`. Aceita null/undefined
 * como 0 → `false`.
 */
export function deveColapsarNaoEntraram(qtdNaoEntraram) {
    const qtd = qtdNaoEntraram == null ? 0 : Number(qtdNaoEntraram);
    return qtd > LIMIAR_COLAPSO_NAO_ENTRARAM;
}
