import { textoUtil } from '../BarraDaConta';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 179, plano 06 — a aritmética e os literais da "Jornada dos produtos".
//
// Protótipo: `new_layout_publicador/Visao Geral.dc.html`
//   · os 4 estágios (`SM`)            → :465-:468
//   · a ordem dos cartões (`keys`)    → :470
//   · o "próximo passo" (`hot`)       → :471
//   · a seleção (`sel`)               → :472
//   · a pilha de 4 tiles e o "+N"     → :478
//   · a barra (`grow`/`barOpacity`)   → :479
//   · o anel (`C`, `ring`)            → :482-:483
//   · o painel (`panel`)              → :487-:490
//   · o centro do anel (`pubOf`)      → :601
//
// ⚠️ MÓDULO PURO: nenhum React, nenhum JSX e — isto é o importante —
// **NENHUMA CLASSE DO TAILWIND**. `tailwind.config.js` varre
// `./resources/js/**/*.jsx` e NÃO `*.js`: classe escrita aqui não é varrida,
// não gera CSS e portanto não existe para a tela. É a mesma armadilha da classe
// montada por concatenação de string que o plano 179-04 avisou, por outro
// caminho. Os mapas de classe deste bloco moram em `JornadaDosProdutos.jsx`.
//
// ⚠️ O segundo motivo de este módulo existir não é de teste, é
// `feedback_rollup_map_scope_bug.md`: variável de escopo do componente lida
// dentro de um `.map()` já foi ELIMINADA pelo Rollup no bundle de produção
// DESTE projeto (ReferenceError em produção com os testes passando). A Jornada
// mapeia estágios, tiles, faixas da barra e linhas do painel — quatro `.map()`
// —, então toda a aritmética recebe tudo por argumento e devolve valor pronto.
// ═══════════════════════════════════════════════════════════════════════════

// ─── Geometria do anel (VG-04), medida em :79/:80/:82 e :482-:483 ───

/** Lado da caixa e do `<svg>` do anel (`:78`, `:79`). */
export const LADO_DO_ANEL = 176;
/** Raio dos 5 círculos — o trilho e os 4 segmentos (`:80`, `:82`). */
export const RAIO_DO_ANEL = 74;
/** `stroke-width` do trilho e dos segmentos (`:80`, `:82`). */
export const LARGURA_DO_TRACO = 14;
/** O respiro entre segmentos do anel (`:483`, `gap = len > 4 ? 3 : 0`). */
export const GAP_DO_ANEL = 3;
/** `C = 2 * Math.PI * 74` (`:482`) — 464,96 com 2 casas. */
export const CIRCUNFERENCIA = 2 * Math.PI * RAIO_DO_ANEL;
/** Quantos tiles o cartão do estágio empilha antes do "+N" (`:478`). */
export const MAX_TILES = 4;

// ─── As duas ordens, que são DIFERENTES de propósito ───

/**
 * A ordem dos CARTÕES, de "nada feito" a "metodologia completa" (`:470`).
 * É também a ordem da barra segmentada (`:120` itera `stages`).
 */
export const ORDEM_ESTAGIOS = ['none', 'draft', 'ready', 'done'];

/**
 * ⚠️ A ordem do ANEL é a INVERSA, e isso é do protótipo (`:483`): o anel começa
 * a desenhar pelo que já está COMPLETO, no topo (`rotate(-90 88 88)`), e vai
 * descendo até o que não começou. Trocar por `ORDEM_ESTAGIOS` deixa o anel com
 * a mesma aparência geral e as cores todas no lugar errado.
 */
export const ORDEM_ANEL = ['done', 'ready', 'draft', 'none'];

// ─── Os literais dos quatro estágios (`:465-:468`), byte a byte ───

/**
 * ⚠️ NADA disto vem do servidor. O payload do 179-01 traz só `chave`, números,
 * itens e URLs; todo rótulo de tela mora aqui (179-01-SUMMARY, "TODO rótulo de
 * tela é do 179-06").
 */
export const ESTAGIOS = {
    none: { passo: '01', rotulo: 'Sem publicação', sub: 'Aguardando a Fase 1', cta: 'Publicar' },
    draft: { passo: '02', rotulo: 'Em andamento', sub: 'Rascunhos no Publicador', cta: 'Continuar' },
    ready: { passo: '03', rotulo: 'Fase 1 publicada', sub: 'Prontos para a Fase 2', cta: 'Criar combos' },
    done: { passo: '04', rotulo: 'Fase 1 + Fase 2', sub: 'Metodologia completa', cta: 'Ver' },
};

/** Cabeçalho do painel de cada estágio (`title` e `psub` de `:465-:468`). */
export const PAINEL = {
    none: {
        titulo: 'produtos sem publicação',
        sub: 'Comece pela Fase 1: uma unidade por oferta, Clássico e Premium',
    },
    draft: { titulo: 'rascunhos no Publicador', sub: 'Retome de onde parou' },
    ready: {
        titulo: 'produtos prontos para a Fase 2',
        sub: 'O combo reaproveita categoria, atributos e ficha técnica da Fase 1',
    },
    done: { titulo: 'produtos com metodologia completa', sub: 'Simples e combo publicados' },
};

/**
 * `cta_chave` do item → rótulo do botão da linha do painel (`rowCta` de
 * `:465-:468`, com as chaves do contrato do 179-01).
 */
export const CTAS = {
    publicar_fase1: 'Publicar Fase 1',
    continuar: 'Continuar',
    criar_combo: 'Criar combo',
    ver_publicacoes: 'Ver publicações',
};

/**
 * De qual `cta_chave` é o botão de cada estágio — a tabela do 179-01-SUMMARY.
 *
 * Existe para que o rótulo da linha tenha fallback SEM repetir os 4 literais de
 * `CTAS`: o protótipo tira o rótulo do ESTÁGIO (`m.rowCta`, `:489`), e o nosso
 * servidor manda `cta_chave` por ITEM. Quando o item vem sem a chave (ou com
 * uma chave que esta versão da tela não conhece), o rótulo do estágio responde.
 */
const CTA_CHAVE_DO_ESTAGIO = {
    none: 'publicar_fase1',
    draft: 'continuar',
    ready: 'criar_combo',
    done: 'ver_publicacoes',
};

// ─── Os literais do bloco (`:43`, `:44`, `:47`, `:49`, `:66-:70`, `:97`) ───

export const TITULO = 'Jornada dos produtos';
export const SUBTITULO = 'Onde cada produto desta empresa está na metodologia de publicação';

/**
 * VG-03 — a tela DIZ que a Jornada é estado atual e não depende do período
 * (`:47`), em vez de o usuário descobrir trocando o período e não vendo nada
 * mudar.
 */
export const NOTA_PERIODO = 'Estado atual · independe do período';
export const TITLE_NOTA_PERIODO =
    'Produtos e fases mostram o estado atual. O período só afeta desempenho e atividade.';

export const VER_PRODUTOS = 'Ver produtos';
/** ⚠️ Caixa alta no TEXTO, não `text-transform` (o protótipo `:97` não tem). */
export const PROXIMO_PASSO = 'PRÓXIMO PASSO';
export const PUBLICADOS = 'publicados';
export const FECHAR = 'Fechar';

export const VAZIO_TITULO = 'Nenhum produto cadastrado ainda';
export const VAZIO_TEXTO =
    'Os produtos chegam pelo Portal do Cliente (Mapeamento Estrutural), pelo ERP conectado ou por cadastro manual. Assim que o primeiro chegar, ele aparece aqui pronto para a Fase 1.';
export const VAZIO_CTA_1 = 'Cadastrar produto';
export const VAZIO_CTA_2 = 'Enviar link do Portal ao cliente';

// ─── Guardas: o servidor pode mandar qualquer coisa ───

/** Objeto simples (nem nulo, nem array) ou `{}` — nunca lança. */
function objeto(valor) {
    return valor !== null && typeof valor === 'object' && !Array.isArray(valor) ? valor : {};
}

/**
 * Número utilizável, ou o padrão.
 *
 * ⚠️ `Number.isFinite` e **nunca** `typeof === 'number'`: `NaN` é `number`, e um
 * `NaN` atravessando até o JSX escreve literalmente "NaN" na tela. Foi a classe
 * de bug da tela preta de 07/10 em produção.
 */
export function numeroSeguro(valor, padrao = 0) {
    return Number.isFinite(valor) ? valor : padrao;
}

/**
 * O percentual do centro do anel e o de cada cartão.
 * ⚠️ VG-18: conta sem produto mostra `0%`, **nunca** `NaN%`.
 */
export function percentualSeguro(valor) {
    return `${numeroSeguro(valor, 0)}%`;
}

/** `"{publicados} de {total} produtos"` — o `pubOf` de `:601`. */
export function textoDoAnel(publicados, total) {
    return `${numeroSeguro(publicados, 0)} de ${numeroSeguro(total, 0)} produtos`;
}

/**
 * O "+N" da pilha de tiles do cartão.
 *
 * ⚠️ ARMADILHA MEDIDA pela wave 1 (179-01-SUMMARY): o protótipo calcula
 * `list.length - 4` sobre a lista INTEIRA do estágio (`:478`), mas o nosso
 * `itens` chega cortado em **6** pelo servidor. Por isso o argumento é o
 * `estagio.total`, e nunca `itens.length`: um estágio de 13 produtos mostraria
 * "+2" em vez de "+9".
 */
export function restanteDaPilha(total) {
    return Math.max(numeroSeguro(total, 0) - MAX_TILES, 0);
}

/** `"{n} {titulo}"` do cabeçalho do painel (`:487`); chave desconhecida → nulo. */
export function tituloDoPainel(chave, total) {
    const painel = PAINEL[chave];
    return painel ? `${numeroSeguro(total, 0)} ${painel.titulo}` : null;
}

/**
 * O link do canto do painel (`:487`): acima de 6 produtos ele promete o número
 * exato; até 6 o painel já mostra todos e o link só leva para Produtos.
 */
export function linkDoPainel(total) {
    const n = numeroSeguro(total, 0);
    return n > 6 ? `Ver todos os ${n} →` : 'Ver em Produtos →';
}

/** Rótulo do botão da linha: a chave do item, com o estágio como fallback. */
export function rotuloDoCta(chave, ctaChave) {
    const doItem = typeof ctaChave === 'string' ? CTAS[ctaChave] : undefined;
    return doItem ?? CTAS[CTA_CHAVE_DO_ESTAGIO[chave]] ?? null;
}

// ─── A geometria, em números ───

/**
 * Os 4 segmentos do anel, na ordem de `ORDEM_ANEL` — a tradução literal de
 * `:483`.
 *
 * `comprimento = contagem / total * C`; `gap = comprimento > 4 ? 3 : 0`
 * (⚠️ sem esse limiar um arco de 2px com 3px de gap sairia NEGATIVO e o
 * segmento desapareceria); o `strokeDashoffset` acumula o comprimento dos
 * anteriores **sem** o gap, negativo.
 *
 * ⚠️ `opacity` 0.3 aqui e 0.35 na barra (`faixasDaBarra`). São dois números
 * distintos do protótipo (`:483` × `:479`) e **não** se uniformizam.
 *
 * Devolve também a `chave`, para o componente escolher a classe de cor DENTRO
 * do `.map()` (REND-04).
 *
 * @param {unknown} contagens  mapa chave→total; qualquer outra forma vira zeros
 * @param {unknown} total      o universo; 0, negativo ou não-número devolve arcos de 0
 * @param {string|null} [selecionado]
 */
export function segmentosDoAnel(contagens, total, selecionado = null) {
    const fonte = objeto(contagens);
    const universo = Math.max(numeroSeguro(total, 0), 0);
    let acumulado = 0;

    return ORDEM_ANEL.map((chave) => {
        const contagem = Math.max(numeroSeguro(fonte[chave], 0), 0);
        const comprimento = universo > 0 ? (contagem / universo) * CIRCUNFERENCIA : 0;
        const gap = comprimento > 4 ? GAP_DO_ANEL : 0;
        const segmento = {
            chave,
            strokeDasharray: `${Math.max(comprimento - gap, 0).toFixed(2)} ${CIRCUNFERENCIA.toFixed(2)}`,
            strokeDashoffset: (-acumulado).toFixed(2),
            opacity: selecionado && selecionado !== chave ? 0.3 : 1,
        };
        acumulado += comprimento;
        return segmento;
    });
}

/**
 * As 4 faixas da barra de 8px, na ordem dos CARTÕES — `:479`.
 *
 * ⚠️ `flexGrow` tem piso `0.0001`, não 0: sobre `flex-basis: 0` um
 * `flex-grow: 0` colapsa a faixa, e os `gap` de 3px ficam tortos. O piso deixa
 * a faixa de contagem zero FININHA, não ausente — "medimos e é zero" continua
 * visível (VG-18).
 */
export function faixasDaBarra(contagens, selecionado = null) {
    const fonte = objeto(contagens);

    return ORDEM_ESTAGIOS.map((chave) => ({
        chave,
        flexGrow: Math.max(numeroSeguro(fonte[chave], 0), 0.0001),
        opacity: selecionado && selecionado !== chave ? 0.35 : 1,
    }));
}

// ─── A fronteira entre o servidor e o JSX (REND-01/02) ───

/** As 4 células da cobertura, sempre booleanas. */
function coberturaSegura(valor) {
    const fonte = objeto(valor);
    const marcada = (celula) => fonte[celula] === true || fonte[celula] === 1;

    return {
        f1_classico: marcada('f1_classico'),
        f1_premium: marcada('f1_premium'),
        f2_classico: marcada('f2_classico'),
        f2_premium: marcada('f2_premium'),
    };
}

/** `produto_id` só vale como string ou número — é `key` e é hue do tile. */
function identificadorSeguro(valor) {
    return typeof valor === 'string' || typeof valor === 'number' ? valor : null;
}

/**
 * Destino utilizável, ou nulo.
 *
 * ⚠️ MAIS ESTRITA que `textoUtil`, e de propósito: `textoUtil(7)` devolve `'7'`,
 * porque `7` é um NOME de produto plausível — mas `href="7"` é uma URL relativa
 * que não leva a lugar nenhum, e `src="7"` dispara um request inútil. Destino é
 * o único campo em que número não vale (EXMP-01: nenhum botão sem destino).
 */
function urlSegura(valor) {
    return typeof valor === 'string' ? textoUtil(valor) : null;
}

/**
 * Normaliza o payload `jornada` do 179-01 para o shape que o JSX consome.
 *
 * ⚠️ Os quatro estágios SEMPRE saem, na ordem de `ORDEM_ESTAGIOS`, mesmo que o
 * servidor mande `estagios` nulo, fora de ordem, incompleto ou com uma chave que
 * esta tela não conhece (que é **ignorada**, não promovida a 5º estágio). O anel
 * do protótipo tem 4 segmentos e 4 cartões; fazer o número de cartões depender
 * do payload abriria a porta para a tela desmontar por dado torto — foi
 * exatamente um grupo nulo dentro de `grupos` que derrubou o painel inteiro na
 * Fase 178, e só o render adverso pegou.
 *
 * @param {unknown} bruta
 */
export function normalizarJornada(bruta) {
    const fonte = objeto(bruta);
    const brutos = Array.isArray(fonte.estagios) ? fonte.estagios : [];

    const estagios = ORDEM_ESTAGIOS.map((chave) => {
        const achado = brutos.find((estagio) => objeto(estagio).chave === chave);
        const estagio = objeto(achado);
        const itens = Array.isArray(estagio.itens) ? estagio.itens : [];

        return {
            chave,
            total: numeroSeguro(estagio.total, 0),
            share_pct: numeroSeguro(estagio.share_pct, 0),
            ver_todos_url: urlSegura(estagio.ver_todos_url),
            itens: itens
                .filter((item) => item !== null && typeof item === 'object' && !Array.isArray(item))
                .map((item) => ({
                    produto_id: identificadorSeguro(item.produto_id),
                    nome: textoUtil(item.nome),
                    sku: textoUtil(item.sku),
                    nota: textoUtil(item.nota),
                    capa: urlSegura(item.capa),
                    cobertura: coberturaSegura(item.cobertura),
                    cta_chave: textoUtil(item.cta_chave),
                    url: urlSegura(item.url),
                })),
        };
    });

    const contagens = {};
    for (const estagio of estagios) contagens[estagio.chave] = estagio.total;

    return {
        total: numeroSeguro(fonte.total, 0),
        publicados: numeroSeguro(fonte.publicados, 0),
        pct: numeroSeguro(fonte.pct, 0),
        // ⚠️ Só booleano `true` liga o anel: `'false'`, `0` e `{}` são todos
        // "truthy ou falsy por acidente" vindos de JSON mal formado.
        tem_produtos: fonte.tem_produtos === true,
        proximo_passo: ORDEM_ESTAGIOS.includes(fonte.proximo_passo) ? fonte.proximo_passo : 'none',
        estagios,
        contagens,
    };
}
