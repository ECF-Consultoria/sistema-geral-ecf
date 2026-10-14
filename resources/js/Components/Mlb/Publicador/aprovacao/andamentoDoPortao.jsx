// ═══════════════════════════════════════════════════════════════════════════
// Fase 186, plano 02 — O VOCABULÁRIO DO PORTÃO: os 8 estados de andamento
// INTERNO da aprovação (VOC-02/VOC-05/AND-02/AND-08/AND-10).
//
// ⚠️⚠️ EXTENSÃO `.jsx` É OBRIGATÓRIA, e não é preferência de estilo.
// `tailwind.config.js` varre `./resources/js/**/*.jsx` e **NÃO** `*.js`. Classe
// do Tailwind escrita num `.js` NÃO GERA CSS e NÃO EXISTE PARA A TELA — com
// todo teste de render passando (GATE-08). Este arquivo carrega as classes dos
// 8 estados, então um dia em que ele virasse `.js` apagaria a cor da fila
// inteira em silêncio.
//
// ⚠️⚠️ UM VOCABULÁRIO SÓ, EM pt-BR — e esta é a decisão central do plano.
// As 8 chaves de dado são AS MESMAS no PHP (`PubAprovacaoOferta`, plano 186-04),
// no servidor e aqui. As chaves INGLESAS do protótipo (`awaiting`, `ecf_review`,
// `changes`…) existem apenas em `DO_PROTOTIPO`, abaixo, como mapa de
// CONFERÊNCIA contra o protótipo — nunca como chave de dado, nunca em prop,
// nunca na URL.
//
// POR QUE A DECISÃO PRECISOU SER ESCRITA: na primeira redação deste plano o
// vocabulário daqui era em inglês e o do plano 186-04 em pt-BR, sem nenhum
// tradutor entre os dois. O selo renderizaria VAZIO para os 8 estados em
// produção, com toda a suíte verde — porque o fallback devolvia `null`. Por
// isso `andamentoDe()` NUNCA devolve `null` (ver o fim deste arquivo).
//
// MEDIDO POR SCRIPT (`grep -n`, 14/10/2026) em
// `design_handoff_publicador/Aprovacao.dc.html`:
//   · `STM`  → :963-971  (rótulo, cor do texto, fundo, borda, dot)
//   · `ORD`  → :1215     (a ordenação da fila)
//   · `ST`   → :1212     (os 9 cards da faixa; o último é "Publicado hoje")
//   · `cta`  → :1240     (o rótulo da ação por estado)
// E a coluna "Observação" da tabela §C de `design_handoff_publicador/README-v2.md`
// (:94-:103), que é a frase de apoio de cada estado.
//
// ⚠️ NENHUM HEX LITERAL (FID-06/FID-08): os 10 hex de `STM` JÁ TÊM NOME na
// camada `pub-*` do `tailwind.config.js`, e as tintas `rgba(…)` saem do
// modificador de opacidade do próprio token — é o mesmo valor computado, com um
// nome só. A tradução hex → token, conferida um a um na camada:
//   #A1A1AA `pub-neutral-txt` · #71717A `pub-neutral-dot` · #161616 `pub-hover-2`
//   #2E2E2E `pub-line-strong` · #FACC15 `pub-accent`      · #D4D4D8 `pub-fase1`
//   #1A1A1A `pub-hover-3`     · #3F3F46 `pub-line-off`    · #FBBF24 `pub-warn-txt`
//   #F59E0B `pub-warn`        · #4ADE80 `pub-ok-txt`      · #22C55E `pub-ok`
//   #F87171 `pub-danger-txt`  · #EF4444 `pub-danger`
//
// ⚠️ VOC-05 — COR NUNCA É O ÚNICO SINAL. Todo estado tem `rotulo` (texto) E
// classe de cor E forma (dot cheio ou anel girando). Quem enxerga mal cor
// distingue pela forma; quem usa leitor de tela ouve o texto.
//
// ⚠️ VOC-06 / "evitar jargão" é regra permanente deste projeto: `aguardando_
// aprovacao`, `em_revisao_ecf` e `ajustes_solicitados` são CHAVES DE DADO e
// nunca aparecem crus na tela. O que a tela mostra é `rotulo`, em português.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Os 8 estados de andamento INTERNO, pelas chaves do BANCO (pt-BR).
 *
 * Cada entrada tem:
 *  · `rotulo`      — o literal de VOC-02, o que vai para a tela;
 *  · `dot`         — classe de FUNDO da bolinha/anel (a forma);
 *  · `texto`       — classe de COR do texto da pill;
 *  · `fundo`       — classe de fundo da pill;
 *  · `borda`       — classe de borda da pill;
 *  · `spinner`     — `true` quando a forma é um anel GIRANDO em vez de bolinha;
 *  · `abreRevisao` — se clicar leva à revisão editável (só os três "ao vivo");
 *  · `cta`         — o rótulo da ação da linha (AND-10, `cta` do protótipo);
 *  · `observacao`  — a frase de apoio da tabela §C do README.
 *
 * ⚠️ A ORDEM das chaves aqui é a do protótipo (`STM`), de propósito: a tabela
 * FID-01 da SUMMARY é lida linha a linha contra `STM`, e reordenar faria a
 * conferência medir pares trocados.
 */
export const ANDAMENTOS = {
    preparando: {
        rotulo: 'Preparando',
        dot: 'bg-pub-neutral-dot',
        texto: 'text-pub-neutral-txt',
        fundo: 'bg-pub-hover-2',
        borda: 'border-pub-line-strong',
        spinner: true,
        abreRevisao: false,
        cta: 'Aguarde',
        observacao: 'spinner; não abre revisão',
    },
    aguardando_aprovacao: {
        rotulo: 'Aguardando aprovação',
        dot: 'bg-pub-accent',
        texto: 'text-pub-accent',
        fundo: 'bg-pub-accent/[0.10]',
        borda: 'border-pub-accent/[0.35]',
        spinner: false,
        abreRevisao: true,
        cta: 'Revisar',
        observacao: 'ninguém conferiu ainda',
    },
    em_revisao_ecf: {
        // ⚠️ "Em revisão pela ECF" diz DE QUEM é a revisão, e é por isso que ele
        // é legítimo: o literal CRU "Em revisão" foi aposentado pelo plano
        // 186-01 justamente porque não dizia. O gate daquele plano tem este
        // rótulo na lista de QUALIFICADOS — não encurtar.
        rotulo: 'Em revisão pela ECF',
        dot: 'bg-pub-fase1',
        texto: 'text-pub-fase1',
        fundo: 'bg-pub-hover-3',
        borda: 'border-pub-line-off',
        spinner: false,
        abreRevisao: true,
        cta: 'Revisar',
        observacao: 'revisão parcial salva',
    },
    ajustes_solicitados: {
        rotulo: 'Ajustes solicitados',
        dot: 'bg-pub-warn',
        texto: 'text-pub-warn-txt',
        fundo: 'bg-pub-warn/[0.10]',
        borda: 'border-pub-warn/[0.4]',
        spinner: false,
        abreRevisao: true,
        cta: 'Revisar',
        observacao: 'existe ajuste em aberto',
    },
    aprovado: {
        rotulo: 'Aprovado',
        dot: 'bg-pub-ok',
        texto: 'text-pub-ok-txt',
        fundo: 'bg-pub-ok/[0.10]',
        borda: 'border-pub-ok/[0.35]',
        spinner: false,
        abreRevisao: false,
        cta: 'Ver',
        observacao: 'aguardando publicação',
    },
    publicando: {
        rotulo: 'Publicando',
        dot: 'bg-pub-ok',
        texto: 'text-pub-ok-txt',
        fundo: 'bg-pub-ok/[0.10]',
        borda: 'border-pub-ok/[0.35]',
        spinner: true,
        abreRevisao: false,
        cta: 'Ver',
        observacao: 'spinner; edição bloqueada',
    },
    falha: {
        rotulo: 'Falha na publicação',
        dot: 'bg-pub-danger',
        texto: 'text-pub-danger-txt',
        fundo: 'bg-pub-danger/[0.10]',
        borda: 'border-pub-danger/[0.4]',
        spinner: false,
        abreRevisao: false,
        cta: 'Tentar de novo',
        observacao: 'revisão preservada; Tentar de novo',
    },
    publicado: {
        rotulo: 'Publicado',
        dot: 'bg-pub-ok',
        texto: 'text-pub-ok-txt',
        fundo: 'bg-pub-ok/[0.10]',
        borda: 'border-pub-ok/[0.35]',
        spinner: false,
        abreRevisao: false,
        cta: 'Ver',
        observacao: 'só com MLB confirmado pelo ML',
    },
};

/**
 * As 8 chaves de `ANDAMENTOS`, na ORDEM do protótipo.
 *
 * ⚠️ `Object.keys` preserva a ordem de inserção para chaves de string, e é essa
 * ordem que a conferência FID-01 usa. Exportada para o gate não ter de
 * reconstruir a lista (lista reconstruída no gate é lista que pode divergir
 * do código sem ninguém ver).
 */
export const CHAVES_DE_ANDAMENTO = Object.keys(ANDAMENTOS);

/**
 * ⚠️⚠️ MAPA DE CONFERÊNCIA, NÃO VOCABULÁRIO.
 *
 * Traduz as 8 chaves INGLESAS do protótipo (`STM`/`ORD`/`ST`/`cta` de
 * `Aprovacao.dc.html`) para as nossas, em pt-BR. Ele existe por UM motivo só:
 * a tabela FID-01 precisa ser medida contra o protótipo, e sem o tradutor a
 * medição seria feita de cabeça.
 *
 * ⚠️ NENHUMA destas 8 chaves inglesas é chave de dado, prop, valor de URL ou
 * texto de tela. O gate de jargão do plano 186-09 prova isso; aqui fica escrito
 * para quem for consumir o módulo não se enganar com a tentação de aceitar "as
 * duas formas" num `||`.
 *
 * ⚠️ Um teste prova que ele é BIJETIVO com `ANDAMENTOS` (8 ↔ 8): mapa
 * incompleto ou com destino repetido faria a conferência FID-01 medir um estado
 * duas vezes e deixar outro sem medir — a definição de gate que passa por
 * omissão.
 */
export const DO_PROTOTIPO = {
    preparing: 'preparando',
    awaiting: 'aguardando_aprovacao',
    ecf_review: 'em_revisao_ecf',
    changes: 'ajustes_solicitados',
    approved: 'aprovado',
    publishing: 'publicando',
    failed: 'falha',
    published: 'publicado',
};

/**
 * A ordenação da fila (AND-08) — `ORD` do protótipo, traduzido.
 *
 * ⚠️ `aguardando_aprovacao` e `em_revisao_ecf` EMPATAM em 1, de propósito: o
 * protótipo os trata como o mesmo degrau de urgência ("ninguém terminou de
 * conferir"). São 8 chaves e 7 valores distintos (0 a 6) — se alguém
 * "arrumasse" o empate, a fila mudaria de ordem sem nenhum teste de render
 * acusar.
 */
export const ORDEM_DA_FILA = {
    falha: 0,
    aguardando_aprovacao: 1,
    em_revisao_ecf: 1,
    ajustes_solicitados: 2,
    aprovado: 3,
    publicando: 4,
    publicado: 5,
    preparando: 6,
};

/**
 * Os três estados "ao vivo" (AND-05): a oferta ainda está sendo decidida e a
 * revisão é editável. É o `inf.live` do protótipo.
 *
 * ⚠️ Em pt-BR, como todo o resto. O `<behavior>` do plano trazia
 * `['awaiting', 'ecf_review', 'changes']` — as chaves do PROTÓTIPO —, e isso é
 * exatamente o bug S3 que o `<objective>` deste plano resolveu. A lista aqui é
 * a de DADO; a tradução para as do protótipo é `DO_PROTOTIPO`.
 */
export const LIVE = ['aguardando_aprovacao', 'em_revisao_ecf', 'ajustes_solicitados'];

/**
 * Os 9 cards da faixa de andamento (AND-09) — `ST` do protótipo (`:1212`).
 *
 * ⚠️ O último NÃO é "Publicado": é **"Publicado hoje"**, um recorte DO DIA, e
 * não o estado inteiro. Trocar o rótulo pelo de `ANDAMENTOS.publicado` faria a
 * faixa prometer o histórico completo e entregar o do dia.
 *
 * ⚠️ `todas` é a chave de "sem filtro", não um estado — por isso ela não está
 * em `ANDAMENTOS` nem em `ORDEM_DA_FILA`.
 */
export const FAIXA_DE_ANDAMENTO = [
    { chave: 'todas', rotulo: 'Todas' },
    { chave: 'preparando', rotulo: 'Preparando' },
    { chave: 'aguardando_aprovacao', rotulo: 'Aguardando aprovação' },
    { chave: 'em_revisao_ecf', rotulo: 'Em revisão pela ECF' },
    { chave: 'ajustes_solicitados', rotulo: 'Ajustes solicitados' },
    { chave: 'aprovado', rotulo: 'Aprovado' },
    { chave: 'publicando', rotulo: 'Publicando' },
    { chave: 'falha', rotulo: 'Falha na publicação' },
    { chave: 'publicado', rotulo: 'Publicado hoje' },
];

/**
 * A forma e a cor NEUTRAS — a aparência de "chegou algo que eu não conheço".
 *
 * ⚠️ Ela existe como constante (e não inline no fallback) porque é ela que
 * garante que o selo de uma chave desconhecida continue SENDO UM SELO: com
 * forma, com cor e com texto. O precedente é `SeloDeStatusDaPublicacao`, que
 * manda o status fora das quatro chaves aparecer COMO CHEGOU.
 */
const NEUTRO = {
    dot: 'bg-pub-neutral-dot',
    texto: 'text-pub-neutral-txt',
    fundo: 'bg-pub-hover-2',
    borda: 'border-pub-line-strong',
    spinner: false,
    abreRevisao: false,
    cta: 'Revisar',
    observacao: '',
};

/** O rótulo de um valor que não é chave nenhuma. Mesmo fallback do projeto. */
const SEM_ROTULO = '—';

/**
 * Lookup seguro numa das tabelas deste módulo.
 *
 * ⚠️ REND-03 — guarda de tipo ANTES de qualquer acesso por chave, e a guarda é
 * `typeof !== 'string'`, no molde de `AbasDeStatus.jsx`. Os três modos de
 * falhar que isto evita, todos medidos neste projeto:
 *   · `String(valor)` LANÇA com `{ toString: null }`;
 *   · `Object.prototype.hasOwnProperty.call(obj, chaveAdversa)` LANÇA pelo
 *     mesmo motivo (a chave é coagida para string);
 *   · `obj[chaveAdversa]` LANÇA, e com chave do PROTÓTIPO (`'toString'`,
 *     `'constructor'`) devolve uma FUNÇÃO — que renderizada derruba a página.
 *
 * O `hasOwnProperty.call` abaixo só roda DEPOIS da guarda, então a chave já é
 * string de verdade e não há como coagir nada.
 */
function entradaDe(tabela, chave) {
    if (typeof chave !== 'string' || chave.trim() === '') return null;
    if (!Object.prototype.hasOwnProperty.call(tabela, chave)) return null;
    return tabela[chave];
}

/** `true` só para os três estados ao vivo. Qualquer outro valor é `false`. */
export function ehLive(chave) {
    if (typeof chave !== 'string') return false;
    return LIVE.includes(chave);
}

/**
 * Se clicar na linha leva à revisão EDITÁVEL.
 *
 * ⚠️ É exatamente `ehLive`: só nos três estados ao vivo a revisão pode ser
 * mexida. `preparando` é `false` (a oferta ainda está sendo montada),
 * `publicando` é `false` (edição bloqueada) e `publicado`/`aprovado`/`falha`
 * abrem em leitura. A duplicação da regra em dois lugares deixaria as duas
 * livres para divergir — e a divergência silenciosa aqui liberaria edição de
 * oferta já publicada.
 */
export function abreRevisao(chave) {
    return ehLive(chave);
}

/**
 * O rótulo da ação da linha (AND-10) — `cta` do protótipo (`:1240`).
 *
 * `falha` → "Tentar de novo" · `aprovado`/`publicado`/`publicando` → "Ver" ·
 * `preparando` → "Aguarde" · qualquer outro → "Revisar".
 *
 * ⚠️ Chave desconhecida cai em "Revisar", e isso é deliberado: é o mesmo ramo
 * `senão` do protótipo. "Aguarde" seria pior (prometeria que o sistema está
 * trabalhando) e botão sem rótulo seria o pior de todos.
 */
export function ctaDoAndamento(chave) {
    const entrada = entradaDe(ANDAMENTOS, chave);
    return entrada ? entrada.cta : NEUTRO.cta;
}

/**
 * ⚠️⚠️ O FALLBACK QUE NUNCA É `null` — a correção do bug S3.
 *
 * Devolve SEMPRE um objeto desenhável:
 *  · chave conhecida            → a entrada de `ANDAMENTOS`;
 *  · string que não é chave     → **a chave COMO CHEGOU** no rótulo, com forma
 *                                 e cor neutras e `abreRevisao: false`;
 *  · qualquer outra coisa       → rótulo "—".
 *
 * POR QUE NÃO `null`: quem desenha faria `andamento?.rotulo` e renderizaria um
 * selo VAZIO — um retângulo com cor e sem texto. Isso passa em teste de render
 * ("não quebrou") e DESAPARECE na tela. A diferença é entre "o selo diz algo
 * errado" (ruim, mas visível e corrigível) e "o selo não diz nada" (invisível,
 * e foi assim que o S3 quase chegou em produção).
 *
 * É o precedente literal de `SeloDeStatusDaPublicacao`: "Status fora das quatro
 * chaves aparece COMO CHEGOU (não se apaga a linha nem se inventa um rótulo) —
 * e em formato inesperado vira '—'".
 *
 * @param {string|unknown} chave
 * @returns {{rotulo: string, dot: string, texto: string, fundo: string,
 *            borda: string, spinner: boolean, abreRevisao: boolean,
 *            cta: string, observacao: string, conhecido: boolean}}
 */
export function andamentoDe(chave) {
    const entrada = entradaDe(ANDAMENTOS, chave);
    if (entrada) return { ...entrada, conhecido: true };

    // ⚠️ `typeof === 'string'` e não `textoSeguro`: `textoSeguro(NaN)` devolve a
    // STRING 'NaN', que viraria o rótulo do selo. Número, objeto, nulo, ausente
    // e string vazia caem todos no "—".
    const rotulo = typeof chave === 'string' && chave.trim() !== '' ? chave : SEM_ROTULO;

    return { ...NEUTRO, rotulo, conhecido: false };
}
