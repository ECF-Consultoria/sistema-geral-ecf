import { isValidElement } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@/lib/utils';
import {
    CLASSES_IMAGEM,
    CLASSES_PLACEHOLDER,
    FUNDO_DA_COR,
    PONTO_MAXIMO,
    PONTO_MINIMO,
    classeDaCor,
    inteiroDoPainel,
    listaDoPainel,
    marcadoresDesenhaveis,
    noQuadro,
    objetoDoPainel,
    textoDoPainel,
} from './ImagemGrandeDoPainel';
import {
    CLASSES_ANEL,
    CLASSES_BOTAO,
    CLASSES_FOTO,
    TITULO_PADRAO,
    chaveDaBorda,
    classeDaBorda,
} from './GradeDeMiniaturas';

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

// ═══════════════════════════════════════════════════════════════════════════
// ⚠️⚠️ O PORTAL — OS TRÊS MOTIVOS, E O REMAPEAMENTO DO `z-index`
// ═══════════════════════════════════════════════════════════════════════════
//
// 1. ⚠️⚠️ **POR QUE PORTAL.** O `PublicadorHeader` declara
//    `backdrop-blur-[14px] backdrop-saturate-[1.4]`
//    (`resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx:174`,
//    RE-MEDIDO em 20/10/2026 depois do commit `31b73ed9` de outra sessão, que o
//    mexeu) — e **`backdrop-filter` CRIA CONTAINING BLOCK para
//    `position: fixed`**. O docblock daquele arquivo (`:36`-`:37`) declara isso
//    textualmente. ➡️ Um `fixed inset-0` escrito dentro da subárvore do header
//    não cobriria a tela: ele se mediria sobre a casca do header.
//    ⚠️ E o critério 1 desta fase pede *"por portal, **sem alterar o header**"* —
//    o `PublicadorHeader.jsx` é território de **LEITURA** aqui. Pedir que a peça
//    seja "irmã do `<main>`" é IMPOSSÍVEL sem tocar o `AppLayout.jsx`
//    (**INTOCÁVEL**): o destino é o `document.body`, e é o portal que resolve.
//
// 2. ⚠️⚠️ **POR QUE A GUARDA DE AMBIENTE, E POR QUE AS PEÇAS SÃO NAMED EXPORTS.**
//    *"Portals are not currently supported by the server renderer"* — o
//    renderizador de SERVIDOR do React **NÃO RENDERIZA PORTAL**. ➡️ Um teste de
//    render do composto veria **HTML VAZIO** e **PASSARIA POR OMISSÃO**: o gate
//    ficaria VERDE provando NADA, que é o furo mais perigoso desta fase porque
//    não deixa sinal. ⚠️ E sem a guarda `typeof document === 'undefined'` o
//    render de servidor **LANÇA** (`document is not defined`) em vez de devolver
//    `null` — `!document` LANÇA no mesmo lugar que se quer evitar.
//    ➡️ **A solução é a da Fase 178, repetida pela 187-09 e pela 188-07: TODAS as
//    peças internas saem como NAMED EXPORTS** (`RaizDoLightbox`,
//    `PalcoDoLightbox`, `MarcadoresDoLightbox`, `MiniaturasDoLightbox`,
//    `SetasDoLightbox`) **e os handlers também** (`aoMoverOCursor`,
//    `aoClicarNaSeta`, `aoClicarNaMiniatura`), porque
//    `renderToStaticMarkup` **não dispara `onClick`** — a tela preta da 187-06
//    foi **no clique**.
//    ⚠️ O gate exige a CONTRAPROVA em DUAS partes: `render(composto) === ''`
//    **E** a raiz exercida direto saindo CHEIA. Sem a segunda, o `equal('')`
//    seria **vazio por construção**.
//
// 3. ⚠️⚠️ **POR QUE `font-pub` NA RAIZ DO PORTAL (REND-05).** Conteúdo portado
//    **NÃO HERDA A FONTE**: ele sai da subárvore do módulo e volta a herdar do
//    `document.body`, que é `font-sans` (Inter). `ModalNovaPublicacao.jsx`
//    declara isso textualmente — *"Sem `font-pub` declarado AQUI, o modal inteiro
//    perde a Geist em silêncio"* —, e foi MEDIDO na Fase 178. ⚠️ **É exatamente
//    no limite do portal que a herança quebra**, então a classe tem de estar na
//    RAIZ do que é portado, não num filho. ⚠️ `CascaDeModal.jsx` **não** declara
//    `font-pub` e está CERTO: ela não é portada, e herda.
//
// ⚠️⚠️ **O `z-[41]` É REMAPEAMENTO, E A PROVA É PELA ORDEM — NUNCA PELO NÚMERO.**
// O protótipo deu `z-index:90` A ESTA PEÇA (`:727`), entre o painel (`70`) e o
// modal (`100`). Os números EM USO no disco, medidos em 20/10/2026:
//   header `z-30` (`header/PublicadorHeader.jsx:174`) · `BarraDeSelecao` `z-[35]`
//   · backdrop do painel `z-[39]` e painel `z-[40]`
//   (`PainelDeRevisao.jsx:154`/`:164`) · casca de modal `z-[42]`
//   (`CascaDeModal.jsx:54`) · toast `z-[45]` (`ToastDaFila.jsx:69`) · popovers do
//   header `z-[61]` · `ModalNovaPublicacao` `z-[80]`.
// ➡️ O lightbox fica em **`z-[41]`**: ACIMA do painel (`z-[40]`), porque ele o
// cobre, e ABAIXO da casca de modal (`z-[42]`), porque o "Reprovar" do lightbox
// abre o modal **POR CIMA** dele (`:1330`) e o `Escape` fecha
// **modal → lightbox → painel** (`:1014`).
// ⚠️ **NUNCA copie o `90`.** ⚠️ E gate com LISTA LARGA de números do protótipo
// REPROVA CÓDIGO CERTO: a 187-09 mediu que `45` é o número do POPOVER e o toast
// legitimamente ficou em `z-[45]`. O que o gate recusa é o número que o protótipo
// deu **A ESTA PEÇA**.
// ⚠️ `z-[41]` **já existe** em `resources/js/Components/PlanejadorFull/Gaveta.jsx`
// — outro módulo, outra tela. **Não é colisão**, e isca de *"z-[41] inexistente
// no projeto"* seria FALSA. Não a escreva.
//
// ⚠️⚠️ **A PROFUNDIDADE É O QUE IMPORTA, NÃO A APARÊNCIA** — medida no protótipo
// por contagem de aberturas/fechamentos de `:726` a `:746`:
//   `:727` raiz (grid) → `:728` palco → `:729` título · `:730` caixa →
//   `:731` CAMADA DO ZOOM → `:732` imagem + `:733` MARCADORES ;
//   `:736` faixa de miniaturas e `:741`/`:742` setas são **IRMÃS da caixa**,
//   dentro do PALCO ; `:744` a lateral é **IRMÃ do palco**.
//   · os MARCADORES são filhos da CAMADA DO ZOOM — é o que os faz **ESCALAREM**
//     com o `transform`. Irmãos da camada ficariam PARADOS enquanto a imagem
//     amplia, apontando para o lugar ERRADO **com todo render passando**;
//   · as SETAS são filhas do PALCO (`position:relative`). Na RAIZ — que é
//     `position:fixed`, logo ancestral posicionado — o `right-[16px]` se mediria
//     sobre a viewport INTEIRA e a seta → sairia **debaixo da lateral de 320px**.
//
// ⚠️⚠️ **A REGRA ÚNICA DE REUSO (`189-REGUA.md` §2): peça que a 188 já desenha é
// IMPORTADA.** Deste arquivo, por inventário medido (`189-CONTRATO-188.md`):
//   · `chaveDaBorda`/`classeDaBorda`/`CLASSES_ANEL` (INV-09) — a precedência de
//     **cinco** ramos com a seleção do CLIENTE e o anel da selecionada;
//   · `CLASSES_PLACEHOLDER` (INV-11) — o estado declarado de `url_arquivo` nula;
//   · `CLASSES_IMAGEM`/`CLASSES_FOTO`/`CLASSES_BOTAO`/`TITULO_PADRAO`;
//   · `marcadoresDesenhaveis`/`classeDaCor`/`FUNDO_DA_COR` e as guardas de tipo.
// ⚠️ **NUNCA ler `borda`/`anel` CRUS do servidor para a seleção:**
// `FotosDoPainelService::para($oferta)` é chamado **sem índice**
// (`PainelDeRevisaoService:301`), então `selecionado = 0` e a borda sai
// `selecionada` **sempre na imagem 0** (AVISO-189-1). O anel amarelo ficaria na
// primeira miniatura para sempre, qualquer que fosse a foto aberta.
// ⚠️ **O que NÃO é importado, e por quê:** a classe do MARCADOR
// (`:733` 26px/11,5px **sem** halo × `:596` 24px/11px **com** `box-shadow`) e a
// classe de CAIXA da miniatura (aqui `56×56 rounded-pub-control-sm` em `flex`;
// no painel `aspect-square rounded-[8px]` em grade de 6 colunas). **A REGRA se
// importa, a classe de caixa não** (INV-09).
// ═══════════════════════════════════════════════════════════════════════════

// ─── As classes, uma casa só (`189-FID01.md` §B) ──────────────────────────

/**
 * `:727` — §B1. ⚠️ `z-[41]` é o REMAPEAMENTO; o `90` do protótipo nunca vem.
 * ⚠️ `bg-[rgba(5,5,5,0.94)]` é o ÚNICO valor do lightbox **sem token** (§T): o
 * gate de vocabulário proíbe **hex cru**, não `rgba(…)`.
 * ⚠️ `font-pub text-pub-txt` são **REND-05**, não decoração.
 */
export const CLASSES_RAIZ = 'fixed inset-0 z-[41] grid grid-cols-[minmax(0,1fr)_320px] '
    + 'bg-[rgba(5,5,5,0.94)] font-pub text-pub-txt';

/** `:728` — §B2. `position:relative` é o que ancora as setas `absolute`. */
export const CLASSES_PALCO = 'relative flex flex-col items-center justify-center gap-[14px] px-[70px] py-[24px]';

/** `:729#1` — §B3. */
export const CLASSES_LINHA_DO_TITULO = 'flex items-center gap-[10px] text-[13px] text-pub-txt-2';

/** `:729#2` — §B4. */
export const CLASSES_TITULO = 'font-semibold text-pub-txt';

/**
 * `:730` — §B5. A caixa 1:1 de até 78vh.
 *
 * ⚠️ `w-[min(78vh,100%)]` **sem espaço** depois da vírgula: o protótipo escreve
 * `min(78vh, 100%)`, e espaço dentro de valor arbitrário do Tailwind **quebra a
 * classe**. O CSS aceita as duas formas.
 * ⚠️ A classe de CURSOR entra por cima, e é **UMA** por estado do zoom.
 */
export const CLASSES_CAIXA_DA_IMAGEM = 'relative aspect-square w-[min(78vh,100%)] overflow-hidden '
    + 'rounded-pub-panel bg-pub-hover text-pub-txt-off';

/** `:731` — §B6. Só o que é FIXO; a transição e o `transform` vão por `style`. */
export const CLASSES_CAMADA_DO_ZOOM = 'absolute inset-0';

/**
 * `:733` — §B7. ⚠️ `left`/`top` vão por `style`; aqui só o que é FIXO.
 *
 * ⚠️⚠️ **DIFERENTE do marcador do PAINEL** (`:596`: 24px, 11px, **com**
 * `box-shadow` de halo). Não reusar a classe do painel.
 * ⚠️ `font-[800]` e **não** `font-extrabold`: o GATE-03 fecha a escala nomeada
 * em 400/500/600/700, e o arbitrário entrega o MESMO peso computado (precedente
 * de `ImagemGrandeDoPainel.jsx:170` e de `AnelDeProgresso.jsx`, em produção).
 * ⚠️ Isto é DIVERGÊNCIA da prescrição da `189-FID01.md` §B7 (que escreve
 * `font-extrabold`) **por VALOR IDÊNTICO**: o protótipo pede `font-weight:800` e
 * as duas formas o entregam; só uma passa o gate.
 */
export const CLASSES_MARCADOR = 'pointer-events-none absolute grid h-[26px] w-[26px] -translate-x-1/2 '
    + '-translate-y-1/2 place-items-center rounded-full border-2 border-pub-page text-[11.5px] '
    + 'font-[800] text-pub-page';

/** `:736` — §B8. */
export const CLASSES_FAIXA_DE_MINIATURAS = 'flex gap-[6px]';

/** `:738#1` — §B9. 56×56 com raio de 7px. A borda e o anel entram por cima. */
export const CLASSES_MINIATURA = 'relative h-[56px] w-[56px] overflow-hidden rounded-pub-control-sm '
    + 'border-[1.5px] bg-pub-hover-2 text-pub-txt-off';

/**
 * `:741`/`:742` — §B11/§B12, a parte comum das duas setas.
 *
 * ⚠️ `text-[18px]` é o ÚNICO tamanho NOVO desta fase, liberado pela via canônica
 * na wave **189-01** (`LIBERADOS_DEPOIS_DA_187`). Arredondar para 17 ou 20 é a
 * aproximação que FID-02/FID-03 VETAM.
 * ⚠️ `-mt-[22px]` é o `margin-top:-22px` do protótipo, que centra o círculo de
 * 44px no `top:50%`.
 */
export const CLASSES_SETA = 'absolute top-1/2 -mt-[22px] h-[44px] w-[44px] cursor-pointer rounded-full '
    + 'border border-pub-line-strong bg-pub-surface text-[18px] text-pub-txt';

/** `:741` — a seta ←. ⚠️ `left-[16px]` escrito LITERAL (o scanner lê a fonte). */
export const CLASSES_SETA_ANTERIOR = `${CLASSES_SETA} left-[16px]`;

/** `:742` — a seta →. */
export const CLASSES_SETA_PROXIMA = `${CLASSES_SETA} right-[16px]`;

/** `:741`/`:742` — os glifos, do protótipo. */
export const GLIFO_ANTERIOR = '←';

export const GLIFO_PROXIMO = '→';

/**
 * Os nomes acessíveis das duas setas.
 *
 * ⚠️ **ACRÉSCIMO INVISÍVEL, declarado na §B11**: botão cujo conteúdo é só um
 * glifo **não tem nome acessível** — ele passa em render e vira um botão sem
 * nome na tela. O protótipo não os tem porque é estático.
 */
export const ROTULO_SETA_ANTERIOR = 'Foto anterior';

export const ROTULO_SETA_PROXIMA = 'Próxima foto';

/** `:729#2` — o `·` entre a posição e o rótulo. ⚠️ É **U+00B7**, não um ponto. */
export const SEPARADOR_DO_TITULO = ' · ';

// ─── Os handlers PUROS (exportados porque o render não dispara evento) ────

/**
 * ⚠️⚠️ `:1329` — o cursor move a origem, **e SÓ com zoom > 1**.
 *
 * @param {unknown} evento o evento de mouse (ou qualquer coisa)
 * @param {unknown} zoom a escala atual
 * @returns {{x: number, y: number}|null} a origem nova, ou `null`
 *
 * ⚠️ A guarda `zoom <= 1` é do protótipo (`if (S.lbZoom <= 1) return;`), e o que
 * ela evita é a imagem "saltar" quando a pessoa passa o mouse sem ter ampliado.
 * ⚠️ **Nunca lança**: `currentTarget` ausente, não-objeto, sem
 * `getBoundingClientRect`, ou com uma caixa inútil, tudo cai em `null`.
 * ⚠️⚠️ Que o `onMouseMove` DISPARE no navegador **não é provado** — ver o
 * docblock do arquivo.
 */
export function aoMoverOCursor(evento, zoom) {
    if (!zoomAmpliado(zoom)) return null;

    const dados = objetoDoPainel(evento);
    if (dados === null) return null;

    const alvo = objetoDoPainel(dados.currentTarget);
    if (alvo === null) return null;
    if (typeof alvo.getBoundingClientRect !== 'function') return null;

    return origemDoZoom({
        clientX: dados.clientX,
        clientY: dados.clientY,
        caixa: alvo.getBoundingClientRect(),
    });
}

/**
 * `:1321` — o clique numa seta.
 *
 * @returns {{indice: number, zoom: number}|null} o PEDIDO, ou `null`
 *
 * ⚠️⚠️ O pedido tem a chave `zoom` e **NÃO TEM** a chave `origem`, e isso é a
 * prova ESTRUTURAL de *"trocar de foto zera o zoom mas PRESERVA a origem"*
 * (`:1321`/`:1327` mandam `lbZoom: 1` e não mexem em `lbX`/`lbY`).
 */
export function aoClicarNaSeta(indice, delta, total) {
    const alvo = moverNoLightbox(indice, delta, total);

    return alvo === null ? null : { indice: alvo, zoom: ZOOM_NORMAL };
}

/** `:1327` — o clique numa miniatura. Mesma forma de pedido da seta. */
export function aoClicarNaMiniatura(indice, total) {
    const alvo = indiceNoLightbox(indice, total);

    return alvo === null ? null : { indice: alvo, zoom: ZOOM_NORMAL };
}

// ─── As peças, todas NAMED EXPORTS (o portal não renderiza no servidor) ───

/**
 * O slot de NÓ.
 *
 * ⚠️⚠️ **Prop de NÓ chegando objeto comum MATA A PÁGINA** (`{a: 1}` como filho
 * de JSX lança *"Objects are not valid as a React child"*). `isValidElement` e
 * **nada mais** — nem `String(valor)`, nem `typeof === 'object'`.
 */
function noDoLightbox(valor) {
    return isValidElement(valor) ? valor : null;
}

/**
 * `:727` — a RAIZ do que é portado: tela cheia, o grid de duas colunas e a fonte.
 *
 * @param {object} props
 * @param {unknown} [props.children] o palco (coluna 1)
 * @param {unknown} [props.lateral] a lateral de 320px (coluna 2) — plano **189-05**
 *
 * ⚠️ NAMED EXPORT para o gate medir a raiz sem passar pelo portal.
 */
export function RaizDoLightbox({ children = null, lateral = null }) {
    return (
        <div className={CLASSES_RAIZ}>
            {noDoLightbox(children)}
            {noDoLightbox(lateral)}
        </div>
    );
}

/**
 * `:733` — os marcadores numerados nas coordenadas x/y REAIS do servidor.
 *
 * ⚠️⚠️ **O ORDINAL É LIDO, NUNCA RECONTADO.** O número do marcador e o do item de
 * verificação saem do MESMO array no servidor; duas numerações divergiriam e o
 * texto diria "2" com o marcador 2 em OUTRO defeito — **a pessoa reprova a foto
 * errada**.
 * ⚠️ `marcadoresDesenhaveis` é IMPORTADO do 188-08: ele filtra coordenada adversa
 * **preservando o ordinal** dos que ficam. A assimetria (item de texto com número
 * cujo marcador não existe) é DECLARADA e intencional.
 * ⚠️⚠️ **`left`/`top` por `style`, NUNCA por classe**: `left-[37.5%]` montado por
 * template literal o scanner do Tailwind **não vê** — a classe fica no HTML, todo
 * render passa, e **o marcador não sai do canto**.
 */
export function MarcadoresDoLightbox({ marcadores = null }) {
    const desenhaveis = marcadoresDesenhaveis(marcadores);

    // ⚠️ Lista vazia → **nenhum** `span`. Casca de marcador vazia é D4 violado.
    if (desenhaveis.length === 0) return null;

    return desenhaveis.map((marcador) => (
        <span
            key={`marcador-do-lightbox-${marcador.ordinal}`}
            // ⚠️ A cor vem da severidade, e `vermelho` × `ambar` dão cores
            // DIFERENTES. O mapa é o do 188-08 — uma segunda cópia divergiria.
            className={cn(CLASSES_MARCADOR, classeDaCor(FUNDO_DA_COR, marcador.cor))}
            style={{ left: `${marcador.x}%`, top: `${marcador.y}%` }}
        >
            {marcador.ordinal}
        </span>
    ));
}

/**
 * `:736`-`:740` — a faixa de miniaturas, UMA por imagem.
 *
 * @param {unknown} [props.indice] o índice selecionado **DO LIGHTBOX**
 *
 * ⚠️⚠️ A borda vem da seleção do LIGHTBOX, **nunca** do `borda`/`anel` crus do
 * servidor (AVISO-189-1: eles estão presos na imagem 0). A precedência de cinco
 * ramos é a de `FotosDoPainelService::borda()`, IMPORTADA do 188-08.
 * ⚠️ `title` NÃO-VAZIO no botão invisível, com três degraus de fallback: elemento
 * sem nome acessível **passa em render e desaparece na tela**.
 * ⚠️ Toda flag computada DENTRO do callback do `.map()`: o Rollup elimina
 * variável de escopo do componente usada dentro do mapa.
 */
export function MiniaturasDoLightbox({ imagens = null, indice = null, onSelecionar = null }) {
    const lista = listaDoPainel(imagens);

    // ⚠️ Faixa vazia **não desenha casca** (D4).
    if (lista.length === 0) return null;

    const selecionado = indiceNoLightbox(indice, lista.length);

    return (
        <div className={CLASSES_FAIXA_DE_MINIATURAS}>
            {lista.map((bruta, posicao) => {
                // ⚠️⚠️ TODAS as flags DENTRO do callback.
                const imagem = objetoDoPainel(bruta);
                if (imagem === null) return null;

                const estaSelecionada = selecionado === posicao;
                const comAlerta = imagem.com_alerta === true;
                const estado = typeof imagem.estado === 'string' ? imagem.estado : null;
                const url = textoDoPainel(imagem.url_arquivo);
                const rotulo = textoDoPainel(imagem.rotulo);
                // ⚠️ Três degraus, para o `title` NUNCA sair vazio.
                const titulo = textoDoPainel(imagem.titulo) ?? rotulo ?? TITULO_PADRAO;
                const trocar = typeof onSelecionar === 'function'
                    ? () => onSelecionar(aoClicarNaMiniatura(posicao, lista.length))
                    : undefined;

                return (
                    <div
                        key={`miniatura-do-lightbox-${inteiroDoPainel(imagem.id) ?? posicao}`}
                        className={cn(
                            CLASSES_MINIATURA,
                            classeDaBorda(chaveDaBorda(estado, estaSelecionada, comAlerta)),
                            // ⚠️ O anel é ADICIONAL à borda, e **só** na selecionada.
                            estaSelecionada ? CLASSES_ANEL : null,
                        )}
                    >
                        {/* ⚠️ `url_arquivo` nula → **nada**, NUNCA `<img src="">`:
                            string vazia num `src` faz o navegador recarregar a
                            própria página. */}
                        {url === null ? null : <img className={CLASSES_FOTO} src={url} alt={rotulo ?? ''} />}

                        <button type="button" className={CLASSES_BOTAO} title={titulo} onClick={trocar} />
                    </div>
                );
            })}
        </div>
    );
}

/**
 * `:741`/`:742` — as setas ← →, IRMÃS da caixa dentro do palco.
 *
 * ⚠️⚠️ **DIVERGÊNCIA DECLARADA do protótipo, por CONDIÇÃO (não por valor, rótulo
 * nem ordem):** com **UMA** foto (ou nenhuma) as setas **não nascem**, e sem
 * handler também não. O protótipo é estático e desenha sempre 6 miniaturas —
 * ele **nunca expressa** o caso de uma foto só, então isto não é conflito com
 * ele, é o silêncio dele. A regra do projeto é D4 (*"nada nasce decorativo"*), e
 * `onClick` vazio é a mesma dívida. **Precedente em produção:**
 * `GradeDeMiniaturas` (188-08) não desenha a casca da grade vazia, e
 * `ImagemGrandeDoPainel` devolve `null` sem imagem.
 * ⚠️ Com 1 foto o `% n` devolveria a própria foto: a seta existiria e **não faria
 * nada visível** — exatamente o botão sem destino que o usuário recusou cinco
 * vezes nesta milestone.
 */
export function SetasDoLightbox({ indice = null, total = null, onMover = null }) {
    const quantas = inteiroDoPainel(total);
    if (quantas === null || quantas <= 1) return null;
    if (typeof onMover !== 'function') return null;

    const atual = indiceNoLightbox(indice, quantas);
    if (atual === null) return null;

    return (
        <>
            <button
                type="button"
                className={CLASSES_SETA_ANTERIOR}
                aria-label={ROTULO_SETA_ANTERIOR}
                onClick={() => onMover(aoClicarNaSeta(atual, -1, quantas))}
            >
                {GLIFO_ANTERIOR}
            </button>
            <button
                type="button"
                className={CLASSES_SETA_PROXIMA}
                aria-label={ROTULO_SETA_PROXIMA}
                onClick={() => onMover(aoClicarNaSeta(atual, 1, quantas))}
            >
                {GLIFO_PROXIMO}
            </button>
        </>
    );
}

/**
 * `:728`-`:743` — o PALCO: o título, a caixa 1:1 com a camada do zoom e os
 * marcadores, a faixa de miniaturas e as setas.
 *
 * @param {unknown} [props.imagens] `painel.fotos.imagens`
 * @param {unknown} [props.indice] o índice aberto no lightbox
 * @param {unknown} [props.zoom] `1` ou `2.5`
 * @param {unknown} [props.origem] `{x, y}` em percentual
 *
 * ⚠️ Galeria vazia → `null` (`:1324`): casca de tela cheia sobre nada é D4
 * violado, e reprovar uma foto TIRA ela do conjunto final.
 */
export function PalcoDoLightbox({
    imagens = null,
    indice = null,
    zoom = null,
    origem = null,
    onMover = null,
    onSelecionar = null,
    onMoverOrigem = null,
}) {
    const lista = listaDoPainel(imagens);
    const atual = indiceNoLightbox(indice, lista.length);
    if (atual === null) return null;

    const imagem = objetoDoPainel(lista[atual]);
    if (imagem === null) return null;

    const posicao = textoDoPainel(imagem.posicao_rotulo);
    const rotulo = textoDoPainel(imagem.rotulo);
    // ⚠️ Os dois nulos → **nenhum** `span` de título, nunca `"undefined · "`.
    const partesDoTitulo = [posicao, rotulo].filter((parte) => parte !== null);
    const contagem = contagemDoLightbox(atual, lista.length);
    const url = textoDoPainel(imagem.url_arquivo);
    const noPlaceholder = rotulo ?? textoDoPainel(imagem.titulo);

    const ponto = objetoDoPainel(origem);
    const estilo = estiloDoZoom({
        zoom,
        x: ponto === null ? null : ponto.x,
        y: ponto === null ? null : ponto.y,
    });

    // ⚠️ Sem handler não há `onMouseMove`: handler que não existe não vira
    // `undefined()` no clique.
    const moverOrigem = typeof onMoverOrigem !== 'function'
        ? undefined
        : (evento) => {
            const nova = aoMoverOCursor(evento, zoom);
            if (nova !== null) onMoverOrigem(nova);
        };

    return (
        <div className={CLASSES_PALCO}>
            <div className={CLASSES_LINHA_DO_TITULO}>
                {partesDoTitulo.length === 0
                    ? null
                    : <span className={CLASSES_TITULO}>{partesDoTitulo.join(SEPARADOR_DO_TITULO)}</span>}
                {contagem === null ? null : <span>{contagem}</span>}
            </div>

            {/* ⚠️ `:730` — a caixa, com UMA classe de cursor por estado do zoom.
                O `onMouseMove` vive AQUI, e não na camada: é a caixa que tem a
                geometria estável (a camada é a que se move). */}
            <div
                className={cn(CLASSES_CAIXA_DA_IMAGEM, classeDoCursorDoZoom(zoom))}
                onMouseMove={moverOrigem}
            >
                {/* ⚠️ `:731` — a camada do zoom. A transição vai INTEIRA por
                    `style` (GATE-09): `duration-[150ms]`/`ease-[…]` NÃO COMPILAM. */}
                <div className={CLASSES_CAMADA_DO_ZOOM} style={estilo}>
                    {/* ⚠️ `:732` — `fit="contain"`. `url_arquivo === null` → o
                        MESMO estado declarado do painel (INV-11, IMPORTADO). */}
                    {url === null
                        ? <span className={CLASSES_PLACEHOLDER}>{noPlaceholder}</span>
                        : <img className={CLASSES_IMAGEM} src={url} alt={rotulo ?? ''} />}

                    {/* ⚠️⚠️ Os marcadores são filhos da CAMADA: é o que os faz
                        escalarem com o zoom. */}
                    <MarcadoresDoLightbox marcadores={imagem.marcadores} />
                </div>
            </div>

            <MiniaturasDoLightbox imagens={lista} indice={atual} onSelecionar={onSelecionar} />

            {/* ⚠️ As setas são IRMÃS da caixa, dentro do palco `relative`. */}
            <SetasDoLightbox indice={atual} total={lista.length} onMover={onMover} />
        </div>
    );
}

/**
 * O LIGHTBOX — a casca de tela cheia, montada POR PORTAL no `document.body`.
 *
 * @param {object} props
 * @param {unknown} [props.aberto] tem de ser **exatamente** `true`
 * @param {unknown} [props.imagens] `painel.fotos.imagens`
 * @param {unknown} [props.indice] o índice aberto
 * @param {unknown} [props.zoom] `1` ou `2.5`
 * @param {unknown} [props.origem] `{x, y}` em percentual
 * @param {unknown} [props.onMover] `(pedido) => void` — as setas
 * @param {unknown} [props.onSelecionar] `(pedido) => void` — as miniaturas
 * @param {unknown} [props.onMoverOrigem] `({x, y}) => void` — o cursor
 * @param {unknown} [props.lateral] o NÓ da lateral de 320px (plano **189-05**)
 *
 * ⚠️⚠️ **ESTE COMPONENTE É CONTROLADO.** `aberto`, `indice`, `zoom` e `origem`
 * são do PAINEL (plano **189-06**): ele recebe e devolve por callback. É o que
 * permite ao `Escape` do painel fechar o lightbox **antes** do painel (o degrau
 * que o 188-07 deixou previsto e declarado, ACHADO A7 do inventário).
 *
 * ⚠️ `aberto !== true` é intencional: `'1'`, `1` e `{}` são **truthy** e abririam
 * o lightbox por acidente sobre um payload que não é o dele.
 *
 * ⚠️ **`onAlternarZoom` NÃO é prop deste componente**, e isso é DIVERGÊNCIA
 * DECLARADA da assinatura do plano: o botão "Ampliar 2,5×"/"Reduzir" (D-189-3a)
 * mora na LATERAL, que chega aqui como **NÓ pronto** — quem a constrói (189-06)
 * passa o handler direto ao `LateralDoLightbox` (189-05). Declará-la aqui seria
 * **entrada morta**, e entrada morta é linha morta. Os rótulos do botão saem
 * daqui por `ROTULO_AMPLIAR`/`ROTULO_REDUZIR`, para não haver segunda cópia.
 */
export default function LightboxDaImagem({
    aberto = null,
    imagens = null,
    indice = null,
    zoom = null,
    origem = null,
    onMover = null,
    onSelecionar = null,
    onMoverOrigem = null,
    lateral = null,
}) {
    if (aberto !== true) return null;

    // ⚠️⚠️ A GUARDA DE AMBIENTE. Sem ela o render de SERVIDOR **LANÇA**
    // (`document is not defined`); com ela devolve `null`, e é por isso que
    // **todas as peças internas são named exports** — é por elas que os gates
    // medem. ⚠️ `typeof document === 'undefined'` e não `!document`: a segunda
    // forma LANÇA no mesmo lugar que se quer evitar.
    if (typeof document === 'undefined') return null;

    return createPortal(
        <RaizDoLightbox lateral={lateral}>
            <PalcoDoLightbox
                imagens={imagens}
                indice={indice}
                zoom={zoom}
                origem={origem}
                onMover={onMover}
                onSelecionar={onSelecionar}
                onMoverOrigem={onMoverOrigem}
            />
        </RaizDoLightbox>,
        document.body,
    );
}
