// ═══════════════════════════════════════════════════════════════════════════
// Fase 186, plano 02 — O RESTO DO VOCABULÁRIO DO PORTÃO: os 3 selos de origem
// financeira (VOC-04), as abreviações e os rótulos de bloqueio (BLOQ-03/
// BLOQ-12), os 6 toasts (VOC-08), os 7 critérios de fidelidade e os 6 defeitos.
//
// ⚠️⚠️ EXTENSÃO `.jsx` É OBRIGATÓRIA, e por motivo medido: este arquivo carrega
// as CLASSES dos selos de origem, e `tailwind.config.js` varre
// `./resources/js/**/*.jsx` e **NÃO** `*.js`. Classe escrita num `.js` não gera
// CSS e NÃO EXISTE PARA A TELA, com todo teste de render passando (GATE-08).
//
// MEDIDO POR SCRIPT (`grep -n`, 14/10/2026) em
// `design_handoff_publicador/Aprovacao.dc.html`:
//   · `SRC`   → :1125  (os 3 selos de origem: rótulo, cor do texto, borda)
//   · `SHORT` → :974   (a abreviação do PRIMEIRO bloqueio, na linha da fila)
//   · `BLK`   → :973   (os rótulos do filtro "Motivo de bloqueio")
//   · `CRIT`  → :961   (os 7 critérios de fidelidade)
//   · `DEF`   → :962   (os 6 defeitos)
// E os 6 toasts em `design_handoff_publicador/README-v2.md`:294-:300, conferidos
// um a um contra as chamadas `this.toast(` do protótipo (`:1152`, `:1162`,
// `:1165`, `:1166`, `:1199`).
//
// ⚠️⚠️ VOC-03 — "LUCRO LÍQUIDO" É PROIBIDO. O vocabulário travado é
// **"Resultado estimado por venda"** ou **"Margem de contribuição estimada"**.
// O motivo não é estilo: o número da tela não desconta imposto, taxa de cartão,
// despesa fixa nem devolução, então chamá-lo de lucro líquido seria uma
// afirmação FALSA sobre o dinheiro do cliente. Há gate de ausência sobre os
// arquivos novos, nas DUAS formas (`lucro líquido` e `lucro_liquido`).
//
// ⚠️ "Evitar jargão sem explicação na tela" é regra permanente deste projeto.
// As chaves daqui (`frete_ausente`, `margem_abaixo_do_minimo`…) são CHAVES DE
// DADO; o que a tela mostra é o rótulo em português.
//
// ⚠️ NÃO escrever `duration-[Nms]`, `delay-[…]` nem `ease-[…]`: o plugin
// `tailwindcss-animate` torna essas classes ambíguas e elas **NÃO COMPILAM**
// neste projeto (GATE-09). Spinner é a animação NOMEADA `animate-pub-spin`, que
// já existe em `tailwind.config.js`; duração fora da escala é `style`.
// ═══════════════════════════════════════════════════════════════════════════

// ───────────────────────────────────────────────────────────────────────────
// VOC-04 — ORIGEM DO NÚMERO FINANCEIRO
//
// Os três selos dizem DE ONDE veio o valor, e existem porque "custo ou frete
// ausente não vira zero" (README §D): um número sem origem declarada é um
// número que o usuário não tem como auditar.
// ───────────────────────────────────────────────────────────────────────────

/**
 * Os 3 selos de origem, pelas chaves de dado em pt-BR.
 *
 * `SRC` do protótipo dá `[rótulo, corDoTexto, corDaBorda]`; aqui a cor vira
 * NOME de token (`pub-*`) e a tinta `rgba()` sai do modificador de opacidade do
 * próprio token — mesmo valor computado, um nome só. Tradução conferida na
 * camada: `#4ADE80` = `pub-ok-txt` · `rgba(34,197,94,0.35)` = `pub-ok/[0.35]` ·
 * `#D4D4D8` = `pub-fase1` · `#3F3F46` = `pub-line-off` · `#F87171` =
 * `pub-danger-txt` · `rgba(239,68,68,0.45)` = `pub-danger/[0.45]`.
 */
export const SELOS_DE_ORIGEM = {
    confirmado: {
        rotulo: 'Confirmado',
        texto: 'text-pub-ok-txt',
        borda: 'border-pub-ok/[0.35]',
    },
    estimado: {
        rotulo: 'Estimado',
        texto: 'text-pub-fase1',
        borda: 'border-pub-line-off',
    },
    pendente: {
        rotulo: 'Pendente',
        texto: 'text-pub-danger-txt',
        borda: 'border-pub-danger/[0.45]',
    },
};

/**
 * ⚠️ MAPA DE CONFERÊNCIA das origens — as chaves do protótipo (`conf`/`est`/
 * `miss`) para as nossas. Existe pelo MESMO motivo de `DO_PROTOTIPO` em
 * `andamentoDoPortao.jsx`: a tabela FID-01 é medida contra `SRC`, e sem o
 * tradutor a medição seria feita de cabeça.
 *
 * ⚠️ `miss` → `pendente` e NÃO "ausente"/"faltando": o rótulo medido é
 * "Pendente". Chave inglesa aqui não é chave de dado em lugar nenhum.
 */
export const ORIGEM_DO_PROTOTIPO = {
    conf: 'confirmado',
    est: 'estimado',
    miss: 'pendente',
};

// ───────────────────────────────────────────────────────────────────────────
// BLOQ-03 / BLOQ-12 — OS BLOQUEIOS
//
// ⚠️ UM NAMESPACE DE CHAVE, DUAS RENDERIZAÇÕES. `SHORT` (9 entradas) e `BLK`
// (8 entradas) do protótipo descrevem os MESMOS conceitos com palavras
// diferentes: `SHORT` é a abreviação que entra na linha da fila depois de
// "Bloqueado: ", `BLK` é o rótulo do filtro "Motivo de bloqueio". Seis conceitos
// aparecem nos dois (fotos, resultado negativo, margem, frete, ajuste,
// alteração), três só em `SHORT` (sem fotos, título longo, em preparação) e
// dois só em `BLK` (alerta de IA, falha na publicação) — união de ONZE.
//
// Dar nome DIFERENTE ao mesmo conceito nos dois mapas (um `frete_pendente` e um
// `frete_ou_custo_ausente`) recriaria o bug S3 num lugar novo: duas chaves para
// a mesma coisa, sem tradutor. Por isso a chave é uma só e o que varia é o
// rótulo.
//
// ⚠️ A chave de bloqueio `falha_na_publicacao` e a chave de ANDAMENTO `falha`
// são namespaces diferentes de propósito: uma é motivo de a oferta não poder
// ser aprovada, a outra é o estado da oferta. Unificá-las faria o filtro de
// bloqueio esconder/mostrar linhas pelo estado.
// ───────────────────────────────────────────────────────────────────────────

/**
 * A abreviação do PRIMEIRO bloqueio (BLOQ-12) — `SHORT` (`:974`), 9 entradas.
 *
 * ⚠️ Minúscula no começo de propósito: no protótipo ela é emendada depois do
 * prefixo "Bloqueado: " (`:1238`), formando "Bloqueado: fotos a conferir".
 * Capitalizar aqui produziria "Bloqueado: Fotos a conferir" na tela.
 */
export const ABREVIACAO_DO_BLOQUEIO = {
    fotos_a_conferir: 'fotos a conferir',
    sem_fotos: 'sem fotos',
    frete_ausente: 'frete pendente',
    resultado_negativo: 'resultado negativo',
    margem_abaixo_do_minimo: 'margem abaixo do mínimo',
    alteracao_nao_conferida: 'alteração de preço não conferida',
    ajuste_em_aberto: 'ajuste em aberto',
    titulo_longo: 'título longo',
    em_preparacao: 'em preparação',
};

/**
 * Os rótulos do filtro "Motivo de bloqueio" (BLOQ-03) — `BLK` (`:973`), 8.
 *
 * ⚠️ São os MESMOS conceitos de `ABREVIACAO_DO_BLOQUEIO` com as palavras do
 * filtro, e por isso dividem a chave. "Frete ou custo ausente" é mais largo que
 * "frete pendente" porque o filtro agrupa os dois casos do README §D.
 */
export const ROTULO_DO_BLOQUEIO = {
    fotos_a_conferir: 'Fotos a conferir',
    alerta_em_foto_de_ia: 'Alerta em foto de IA',
    resultado_negativo: 'Resultado negativo',
    margem_abaixo_do_minimo: 'Margem abaixo do mínimo',
    frete_ausente: 'Frete ou custo ausente',
    ajuste_em_aberto: 'Ajuste solicitado',
    alteracao_nao_conferida: 'Alteração não conferida',
    falha_na_publicacao: 'Falha na publicação',
};

/**
 * ⚠️ MAPA DE CONFERÊNCIA dos bloqueios — as 11 chaves do protótipo
 * (`SHORT` ∪ `BLK`) para as nossas. BIJETIVO com a união das chaves dos dois
 * mapas acima, e há teste provando: mapa incompleto deixaria um bloqueio sem
 * medição contra o protótipo, que é a definição de gate que passa por omissão.
 */
export const BLOQUEIO_DO_PROTOTIPO = {
    photos: 'fotos_a_conferir',
    nophoto: 'sem_fotos',
    freight: 'frete_ausente',
    loss: 'resultado_negativo',
    margin: 'margem_abaixo_do_minimo',
    change: 'alteracao_nao_conferida',
    adjust: 'ajuste_em_aberto',
    title: 'titulo_longo',
    prep: 'em_preparacao',
    alert: 'alerta_em_foto_de_ia',
    failed: 'falha_na_publicacao',
};

// ───────────────────────────────────────────────────────────────────────────
// OS 7 CRITÉRIOS E OS 6 DEFEITOS
//
// ⚠️ MEDIÇÃO DECLARADA: NENHUMA das 13 chaves de `CRIT`/`DEF` é inglesa — são
// palavras pt-BR (`cor`, `formato`, `material`, `textura`, `proporcoes`,
// `quantidade`, `acessorios`, `falta`, `texto`, `recorte`, `inventado`) ou
// abreviações de palavra pt-BR (`deform` de deformação, `dup` de duplicada).
// Por isso elas ficam COMO ESTÃO e não existe mapa de tradução para elas:
// inventar chave nova aqui seria churn sem nenhum bug para resolver, e mais uma
// chance de divergir do PHP.
// ───────────────────────────────────────────────────────────────────────────

/** Os 7 critérios de fidelidade da foto de IA — `CRIT` (`:961`). */
export const CRITERIOS = {
    cor: 'Cor',
    formato: 'Formato',
    material: 'Material',
    textura: 'Textura',
    proporcoes: 'Proporções',
    quantidade: 'Quantidade do kit',
    acessorios: 'Acessórios e componentes',
};

/** Os 6 defeitos de foto de IA — `DEF` (`:962`). */
export const DEFEITOS = {
    deform: 'Deformação',
    dup: 'Peça duplicada',
    falta: 'Peça ausente',
    texto: 'Texto ilegível',
    recorte: 'Recorte ruim',
    inventado: 'Elemento inventado pela IA',
};

// ───────────────────────────────────────────────────────────────────────────
// VOC-08 — OS 6 TOASTS
//
// ⚠️ LITERAIS BYTE A BYTE do README §Toasts (:294-:300). Os caracteres que
// parecem decoração e NÃO são: o travessão `—` (U+2014, não hífen) em "Aprovado
// — aguardando publicação", o separador `·` (U+00B7) e as reticências `…`
// (U+2026, um caractere, não três pontos). Trocar qualquer um deles faz o gate
// de literal reprovar — de propósito: é assim que paráfrase é pega.
// ───────────────────────────────────────────────────────────────────────────

/** Os 6 toasts, pelas chaves de dado em pt-BR. */
export const TOASTS = {
    aprovado: 'Aprovado — aguardando publicação',
    publicado: 'Publicado e confirmado no Mercado Livre · MLB…',
    revisao_salva: 'Revisão salva',
    ajuste_solicitado: 'Ajuste solicitado · Encaminhado para: …',
    foto_reprovada: 'Foto reprovada e removida do conjunto final',
    gerando_com_ia: 'Gerando nova versão com IA',
};

// ───────────────────────────────────────────────────────────────────────────
// OS LITERAIS FIXOS DO PORTÃO
// ───────────────────────────────────────────────────────────────────────────

/**
 * ⚠️ VOC-03 — O NOME DO NÚMERO. "Resultado estimado por venda", nunca "lucro
 * líquido": o valor não desconta imposto, taxa de cartão, despesa fixa nem
 * devolução.
 */
export const RESULTADO_ESTIMADO = 'Resultado estimado por venda';

/** O outro nome aceito para o mesmo número, quando a tela mostra o percentual. */
export const MARGEM_DE_CONTRIBUICAO = 'Margem de contribuição estimada';

/**
 * O que aparece NO LUGAR do número quando falta custo ou frete.
 *
 * ⚠️ README §D: "Custo ou frete ausente não vira zero." Zero afirmaria que o
 * frete é de graça; "Incompleto" afirma que não se sabe — e é a verdade.
 */
export const INCOMPLETO = 'Incompleto';

/** A linha de "nada bloqueia" na fila (o ramo final do `reason`, `:1297`). */
export const TUDO_CONFERIDO = 'Fotos, preço e conteúdo conferidos';

/**
 * O prefixo da linha de bloqueio (`:1238`).
 *
 * ⚠️ Carrega o espaço no fim de propósito: ele é emendado com a abreviação em
 * minúscula de `ABREVIACAO_DO_BLOQUEIO`, formando "Bloqueado: frete pendente".
 */
export const BLOQUEADO_PREFIXO = 'Bloqueado: ';
