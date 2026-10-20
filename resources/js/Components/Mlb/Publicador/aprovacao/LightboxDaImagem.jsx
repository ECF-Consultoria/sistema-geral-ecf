import {
    PONTO_MAXIMO,
    PONTO_MINIMO,
    inteiroDoPainel,
    noQuadro,
    objetoDoPainel,
} from './ImagemGrandeDoPainel';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 189, plano 03 — O LIGHTBOX DA IMAGEM (`:726`-`:743` do protótipo
// `design_handoff_publicador/Aprovacao.dc.html`): a casca de tela cheia POR
// PORTAL, o palco com a imagem 1:1 de até 78vh, a camada do zoom de 2,5×, os
// marcadores numerados, a faixa de miniaturas e as setas ← →.
//
// ⚠️⚠️ O QUE ESTE ARQUIVO **NÃO PROVA**, E A RESSALVA É PARTE DO ENTREGÁVEL:
//
// o zoom que segue o cursor é provado pela FUNÇÃO `origemDoZoom` (as coordenadas
// do evento → a origem do `transform`); que o `onMouseMove` DISPARA, que o
// navegador ANIMA a transição de 150 ms e que a imagem de fato acompanha o
// cursor **não é provado nesta fase** — não há navegador nesta máquina, o
// `mysqld` local está parado, o banco não tem empresa com `ml_token` (a rota dá
// 404) e `pub_aprovacao_ofertas` está vazia. ➡️ É a conferência visual da conta
// **459** (plano 189-08). Afirmar *"o zoom segue o cursor"* sem esta ressalva é
// o que esta fase não pode fazer.
// ⚠️ Pela mesma razão, *"a tela mostra X"* aqui significa SEMPRE *"o HTML gerado
// contém X"*.
//
// ⚠️⚠️ AS REGRAS SÃO DIFERENTES DAS DO PAINEL — **NÃO UNIFIQUE**:
//   · `moverNoLightbox` **DÁ A VOLTA** (`(i + d + n) % n`, `:1321`); o painel
//     **clampa** (`:1018`, `Math.max(0, …)`, plano 188-07). O protótipo ganha nos
//     dois, e são dois comportamentos de propósito.
//   · trocar de foto (seta ou miniatura) **zera o zoom** (`lbZoom: 1` em `:1321`,
//     `:1327` e `:1319`), mas **NÃO zera a origem** (`lbX`/`lbY` persistem). É por
//     isso que o pedido devolvido pelos handlers tem a chave `zoom` e **não tem**
//     a chave `origem`.
//   · galeria vazia **FECHA** o lightbox (`:1324`: `if (i < 0) return { open:
//     false }`) — `indiceNoLightbox` devolve `null` e é o `null` que fecha.
//
// ⚠️⚠️ ACRÉSCIMOS DEFENSIVOS, DECLARADOS (o protótipo é estático e não precisa
// deles; aqui o dado vem do servidor e cada campo pode chegar adverso):
//   (a) `origemDoZoom` **CLAMPA em 0..100** e devolve `null` com caixa inválida.
//       O protótipo não clampa porque o cursor está dentro da caixa por
//       construção. Aqui `NaN%` num `transformOrigin` **não lança**, mas é o
//       caminho da TELA PRETA (`textoSeguro(NaN)` → `'NaN'`), que voltou SEIS
//       vezes nesta milestone — a última **no clique**.
//   (b) `moverNoLightbox` usa `((i + d) % n + n) % n` e não `(i + d + n) % n`: a
//       forma do protótipo quebra para `d < -n`. Para `d = ±1` as duas dão o
//       MESMO resultado, e os casos do gate medem `±1`.
//   (c) `estiloDoZoom` **trava o `scale` nas DUAS constantes** (`1` / `2.5`) em
//       vez de interpolar o valor cru de `:1326` (`'scale(' + S.lbZoom + ')'`):
//       zoom adverso viraria `scale(NaN)`.
//
// ⚠️ **GATE-09 — a transição vai INTEIRA por `style`.** `duration-[150ms]`,
// `delay-[…]` e `ease-[…]` **NÃO COMPILAM** neste projeto (o
// `tailwindcss-animate` torna a classe ambígua). ⚠️ E **não** se usa a classe
// `transition-transform` do Tailwind: ela traz `cubic-bezier(0.4,0,0.2,1)`, e o
// protótipo (`:731`) é `transition:transform .15s` — curva padrão **`ease`**.
// Trocar a curva é FID-02 (*"o Tailwind não tem esse passo"*).
//
// ⚠️ **O CURSOR É DINÂMICO** (`:730`, `cursor:{{ LB.cursor }}`): **duas classes
// LITERAIS** num mapa (`CURSOR_DO_ZOOM`), **nunca** concatenadas — o scanner do
// Tailwind não vê classe montada por template literal, e o cursor sairia o do
// navegador com todo teste de render passando.
// ⚠️ **Nenhum `String(valor)`** (há gate da 187-04 proibindo `String(` nesta
// região) e **nenhum `obj[chave]` com chave do servidor**: os dois **LANÇAM** com
// `{toString: null}`.
//
// ⚠️ x/y são **PERCENTUAL** (0,00–100,00), já saneados pelo servidor
// (`FotosDoPainelService::noQuadro()`) — é o que permite desenhar em qualquer
// tamanho de tela. ⚠️ **E O ORDINAL VEM DO SERVIDOR: NUNCA É RENUMERADO AQUI.**
// ═══════════════════════════════════════════════════════════════════════════

// ─── O zoom: as duas únicas escalas (`:1328`) ──────────────────────────────

/** `:1328` — a escala de repouso. */
export const ZOOM_NORMAL = 1;

/** `:1328` — a escala ampliada. O `2,5×` do objetivo da fase. */
export const ZOOM_AMPLIADO = 2.5;

/**
 * `:1326` — os dois rótulos do botão de liga/desliga do zoom (D-189-3a).
 *
 * ⚠️ Exportados para o **189-05** IMPORTAR: duas cópias do mesmo rótulo
 * divergem, e a divergente é descoberta por alguém vendo dois textos diferentes
 * na mesma tela.
 */
export const ROTULO_AMPLIAR = 'Ampliar 2,5×';

export const ROTULO_REDUZIR = 'Reduzir';

/**
 * `:1326` — as DUAS classes de cursor, LITERAIS.
 *
 * ⚠️⚠️ Literais num mapa de chave SEMÂNTICA (`ampliado`/`normal`), e não
 * indexado pelo número do zoom: chave numérica exigiria `String(zoom)` ou
 * `mapa[zoom]`, e os dois são proibidos nesta região.
 */
export const CURSOR_DO_ZOOM = {
    ampliado: 'cursor-zoom-out',
    normal: 'cursor-crosshair',
};

/**
 * `:731` — a transição do zoom, INTEIRA por `style` (GATE-09).
 *
 * ⚠️ `ease` é a curva PADRÃO do CSS para `transition` sem curva declarada — é o
 * que `transition:transform .15s` significa. A classe `transition-transform` do
 * Tailwind entregaria `cubic-bezier(0.4,0,0.2,1)`, que é OUTRA curva.
 */
export const TRANSICAO_DO_ZOOM = {
    transitionProperty: 'transform',
    transitionDuration: '150ms',
    transitionTimingFunction: 'ease',
};

/** A origem neutra, usada quando `x`/`y` chegam adversos. */
export const ORIGEM_PADRAO = '50% 50%';

/** O literal da contagem (`:1326`, `(i + 1) + ' de ' + ord.length`). */
export const SEPARADOR_DA_CONTAGEM = ' de ';

/**
 * `:1326` — o zoom está AMPLIADO?
 *
 * ⚠️ O teste é `> ZOOM_NORMAL` e **não** `=== ZOOM_AMPLIADO`: é o que o protótipo
 * faz (`S.lbZoom > 1`), e qualquer escala intermediária futura continua valendo.
 * ⚠️ `typeof NaN === 'number'`, então a guarda é `Number.isFinite`.
 */
export function zoomAmpliado(zoom) {
    return typeof zoom === 'number' && Number.isFinite(zoom) && zoom > ZOOM_NORMAL;
}

/**
 * `:1326` — o rótulo do botão de zoom.
 *
 * @param {unknown} zoom a escala atual
 * @returns {string} `'Reduzir'` com 2,5× · `'Ampliar 2,5×'` com 1×
 */
export function rotuloDoZoom(zoom) {
    return zoomAmpliado(zoom) ? ROTULO_REDUZIR : ROTULO_AMPLIAR;
}

/**
 * `:1326` — a classe de cursor da caixa da imagem.
 *
 * @param {unknown} zoom a escala atual
 * @returns {string} `'cursor-zoom-out'` com 2,5× · `'cursor-crosshair'` com 1×
 */
export function classeDoCursorDoZoom(zoom) {
    return zoomAmpliado(zoom) ? CURSOR_DO_ZOOM.ampliado : CURSOR_DO_ZOOM.normal;
}

/**
 * `:1328` — o liga/desliga do zoom.
 *
 * @param {unknown} zoom a escala atual
 * @returns {number} `2.5` saindo de 1× · `1` saindo de 2,5×
 */
export function alternarZoom(zoom) {
    return zoomAmpliado(zoom) ? ZOOM_NORMAL : ZOOM_AMPLIADO;
}

/**
 * `:1326` — o `style` da camada do zoom.
 *
 * @param {unknown} estado `{ zoom, x, y }`
 * @returns {object} `transform` + `transformOrigin` + os TRÊS campos da transição
 *
 * ⚠️ O `scale` é um dos DOIS literais (acréscimo (c) do docblock): zoom adverso
 * nunca virou `scale(NaN)`.
 * ⚠️ `x`/`y` fora de 0..100 ou não finitos caem em `ORIGEM_PADRAO` — nunca
 * `NaN%`.
 */
export function estiloDoZoom(estado) {
    const dados = objetoDoPainel(estado);
    const ampliado = dados !== null && zoomAmpliado(dados.zoom);
    const x = dados !== null && noQuadro(dados.x) ? dados.x : null;
    const y = dados !== null && noQuadro(dados.y) ? dados.y : null;

    return {
        transform: ampliado ? `scale(${ZOOM_AMPLIADO})` : `scale(${ZOOM_NORMAL})`,
        transformOrigin: x === null || y === null ? ORIGEM_PADRAO : `${x}% ${y}%`,
        ...TRANSICAO_DO_ZOOM,
    };
}

/**
 * ⚠️⚠️ `:1329` — **A FUNÇÃO QUE O ZOOM "QUE SEGUE O CURSOR" É.**
 *
 * As coordenadas do cursor e a caixa da imagem → a origem do `transform`, em
 * percentual inteiro.
 *
 * @param {unknown} movimento `{ clientX, clientY, caixa: { left, top, width, height } }`
 * @returns {{x: number, y: number}|null} a origem, ou `null` (a origem anterior fica)
 *
 * ⚠️ `Math.round(… * 100)` é o arredondamento do protótipo, não escolha daqui.
 * ⚠️ `width`/`height` `0`, negativos, `NaN`, string, objeto, ou `caixa` nula →
 * `null`: dividir por zero daria `Infinity`, e `Infinity%` é o caminho da tela
 * preta. ⚠️ **Nunca lança.**
 * ⚠️ O clamp em 0..100 é o acréscimo (a) do docblock do arquivo.
 */
export function origemDoZoom(movimento) {
    const dados = objetoDoPainel(movimento);
    if (dados === null) return null;

    const caixa = objetoDoPainel(dados.caixa);
    if (caixa === null) return null;

    if (!medidaPositiva(caixa.width) || !medidaPositiva(caixa.height)) return null;
    if (!numeroFinito(caixa.left) || !numeroFinito(caixa.top)) return null;
    if (!numeroFinito(dados.clientX) || !numeroFinito(dados.clientY)) return null;

    return {
        x: percentualNoQuadro((dados.clientX - caixa.left) / caixa.width),
        y: percentualNoQuadro((dados.clientY - caixa.top) / caixa.height),
    };
}

/**
 * `:1321` — mover a foto DANDO A VOLTA.
 *
 * @param {unknown} indice o índice atual
 * @param {unknown} delta `+1` (→) ou `-1` (←)
 * @returns {number|null} o índice novo, ou `null` (galeria vazia / entrada adversa)
 *
 * ⚠️ **DÁ A VOLTA de propósito** — o painel clampa, e são regras diferentes.
 */
export function moverNoLightbox(indice, delta, total) {
    const n = inteiroDoPainel(total);
    if (n === null || n <= 0) return null;

    const d = inteiroDoPainel(delta);
    if (d === null) return null;

    const i = indiceNoLightbox(indice, n);
    if (i === null) return null;

    return ((i + d) % n + n) % n;
}

/**
 * `:1324` — o índice CLAMPADO no total, ou `null` quando a galeria fechou.
 *
 * @param {unknown} indice
 * @param {unknown} total
 * @returns {number|null} `null` SÓ quando não há foto nenhuma (o lightbox FECHA)
 *
 * ⚠️ Índice adverso com total > 0 cai em **0** (a primeira foto), e não em
 * `null`: fechar o lightbox por causa de um campo torto seria pior que mostrar
 * a primeira foto.
 */
export function indiceNoLightbox(indice, total) {
    const n = inteiroDoPainel(total);
    if (n === null || n <= 0) return null;

    const bruto = inteiroDoPainel(indice);
    const i = bruto === null || bruto < 0 ? 0 : bruto;

    return Math.min(i, n - 1);
}

/**
 * `:1326` — `"2 de 6"`.
 *
 * @returns {string|null} `null` com galeria vazia (nada a contar)
 *
 * ⚠️ O template literal recebe SÓ inteiros já saneados — nunca o valor cru.
 */
export function contagemDoLightbox(indice, total) {
    const n = inteiroDoPainel(total);
    if (n === null || n <= 0) return null;

    const i = indiceNoLightbox(indice, n);
    if (i === null) return null;

    return `${i + 1}${SEPARADOR_DA_CONTAGEM}${n}`;
}

// ─── As guardas de tipo, uma casa só ──────────────────────────────────────

/** Número finito de verdade. ⚠️ `typeof NaN === 'number'`. */
function numeroFinito(valor) {
    return typeof valor === 'number' && Number.isFinite(valor);
}

/** Medida de caixa usável: finita **e > 0** (dividir por 0 dá `Infinity`). */
function medidaPositiva(valor) {
    return numeroFinito(valor) && valor > 0;
}

/** A fração → percentual inteiro CLAMPADO em 0..100. */
function percentualNoQuadro(fracao) {
    const bruto = Math.round(fracao * 100);
    if (!numeroFinito(bruto) || bruto < PONTO_MINIMO) return PONTO_MINIMO;
    if (bruto > PONTO_MAXIMO) return PONTO_MAXIMO;

    return bruto;
}
