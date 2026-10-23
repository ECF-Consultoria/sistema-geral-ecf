import { isValidElement, useEffect, useSyncExternalStore } from 'react';
import { createPortal } from 'react-dom';
import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import TopoDoPainel from './TopoDoPainel';
import IdentificacaoDaOferta, { temIdentificacao } from './IdentificacaoDaOferta';
// ⚠️⚠️ Fase 189, plano 06 — AS DUAS REGRAS DE ÍNDICE DO LIGHTBOX VÊM DO 189-03,
// IMPORTADAS: `moverNoLightbox` DÁ A VOLTA (`:1321`, `(lb.i + d + n) % n`) e
// `indiceNoLightbox` CLAMPA o teto (`:1324`). ⚠️ Reimplementá-las aqui criaria
// uma segunda regra de volta, e a divergente leva a pessoa à FOTO ERRADA — numa
// tela em que aprovar ENFILEIRA e não há desfazer. ⚠️ Não há ciclo de import:
// `LightboxDaImagem` só importa de `ImagemGrandeDoPainel` e `GradeDeMiniaturas`,
// e nenhum dos dois importa este arquivo (medido em 20/10/2026).
import { indiceNoLightbox, moverNoLightbox } from './LightboxDaImagem';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 188, plano 07 — A CASCA DO PAINEL DE REVISÃO SIMPLES (critérios 1, 2, 3).
//
// ⚠️⚠️⚠️ OS TRÊS MOTIVOS DO PORTAL, E NENHUM DOS TRÊS É ESTILO ════════════════
//
// 1. **POR QUE PORTAL (critério 1 / REVS-01).** O `PublicadorHeader` declara
//    `backdrop-blur-[14px] backdrop-saturate-[1.4]` em
//    `header/PublicadorHeader.jsx:171`, e
//    **`backdrop-filter` cria containing block para `position:fixed`**
//    (medido na Fase 178). ⚠️ A frase acima fica numa LINHA SÓ de propósito: o
//    gate deste plano a procura inteira, e com ela quebrada em duas linhas quem
//    fizer `grep "containing block"` **não a acha** — foi o primeiro achado da
//    própria sonda deste plano, em 19/10/2026.
//    Um descendente dele com
//    `fixed` resolve contra o HEADER, não contra o viewport — o painel de 1140px
//    ficaria preso dentro de uma barra de 108px de altura. O critério 1 exige o
//    painel **acima do header e SEM alterar o header**: `header/PublicadorHeader.jsx`
//    sai com `numstat` ZERO deste plano, e há gate cobrando.
//    ⚠️ **Os popovers da Fase 187 NÃO precisam de portal** — eles são `absolute`
//    dentro de pai `relative`, medido. **São casos diferentes; não "consertar"
//    os popovers.**
//    ⚠️ E *"irmã do `<main>`"* é **IMPOSSÍVEL** sem tocar `AppLayout.jsx`
//    (**INTOCÁVEL**, `AppLayout.jsx:1055`-`:1057` renderiza `{children}` dentro
//    do `<main>`) — medido na 187-09. O portal resolve isso de vez, porque o
//    destino é o `document.body`.
//
// 2. ⚠️⚠️ **POR QUE A GUARDA DE AMBIENTE, E POR QUE AS PEÇAS SÃO NAMED EXPORTS.**
//    *"Portals are not currently supported by the server renderer"* — o
//    renderizador de SERVIDOR do React **NÃO RENDERIZA PORTAL**. ➡️ Um teste de
//    render do composto veria **HTML VAZIO** e **PASSARIA POR OMISSÃO**: o gate
//    ficaria VERDE provando NADA, que é o furo mais perigoso desta fase porque
//    não deixa sinal. ⚠️ Sem a guarda `typeof document === 'undefined'` o render
//    de servidor **LANÇA** (`document is not defined`) em vez de devolver `null`.
//    ➡️ **A solução é a da Fase 178, repetida pela 187-09: TODAS as peças
//    internas saem como NAMED EXPORTS** (`RaizDoPainel`, `BackdropDoPainel`,
//    `CorpoDoPainel`, `ColunasDoPainel`, `ColunaDoPainel`, e as sete de
//    `TopoDoPainel.jsx`) e são conferidas **e exercidas no CLIQUE**
//    diretamente, sem passar pelo portal.
//    ⚠️ `renderToStaticMarkup` **não dispara `onClick`** — a tela preta da
//    187-06 foi **no clique** —, e é por isso que os handlers também são
//    exportados.
//
// 3. ⚠️⚠️ **POR QUE `font-pub` NA RAIZ DO PORTAL (REND-05).** Conteúdo portado
//    **NÃO HERDA A FONTE**: ele sai da subárvore do módulo e volta a herdar do
//    `document.body`, que é `font-sans` (Inter). `ModalNovaPublicacao.jsx:95`-`:97`
//    declara isso textualmente — *"Sem `font-pub` declarado AQUI, o modal inteiro
//    perde a Geist em silêncio"* —, e foi MEDIDO na Fase 178. ⚠️ **É exatamente no
//    limite do portal que a herança quebra**, então a classe tem de estar na
//    RAIZ do que é portado, não num filho. ⚠️ `CascaDeModal.jsx` **não** declara
//    `font-pub` e está CERTO: ela não é portada, e herda.
//
// ═══ ⚠️⚠️ Z-INDEX: `z-[39]` E `z-[40]`, PROVADOS PELA ORDEM ════════════════
//
// ⚠️ **NUNCA copie os números do protótipo** (lá o backdrop é 69 e o painel 70,
// **porque lá o header é 50**). O que se preserva é a **ORDEM RELATIVA**:
//
//   projeto:   header(30) < backdrop(39) < painel(40) < modal(42) < toast(45)
//   protótipo: header(50) < backdrop(69) < painel(70) < modal(100) < toast(120)
//
// Os números JÁ EM USO, medidos no disco: filtros `10` · header `z-30`
// (`header/PublicadorHeader.jsx:171`) · menu lateral e painel do produto `40` ·
// `BarraDeSelecao.jsx:58` `z-[35]` · `CascaDeModal.jsx:54` `z-[42]` ·
// `ToastDaFila.jsx:69` `z-[45]` · popovers do header `z-[61]` ·
// `ModalNovaPublicacao.jsx` `z-[80]`.
//
// ⚠️⚠️ **O GATE É DE ORDEM, NÃO DE LISTA DE NÚMEROS.** A 187-09 mediu que **`45`
// é o número do POPOVER** do protótipo e o toast legitimamente ficou em `z-[45]`:
// um gate com lista larga de números proibidos **reprova código certo**.
// ➡️ A sonda compara as DESIGUALDADES entre os arquivos, e tem isca que
// **distingue os dois casos**: trocar `39` por `41` **INVERTE** a ordem com o
// painel e REPROVA; trocar `40` por `41` **não inverte nada** (41 continua entre
// 39 e 42) e **NÃO pode reprovar por si** — só a ordem importa.
//
// ⚠️ **A ordem travada `filtros 10 < header 30 < menu lateral = painel 40` é
// invariante MEDIDO de um bug que o usuário relatou em 12/10/2026**
// (`tests/js/publicador-header-zindex-ordem.test.js`). ⚠️ Aquele arquivo sai deste
// plano **INTACTO** (`numstat` vazio) e **VERDE** — ele lê outro painel (o
// `PainelDoProdutoLateral`, Fase 178/180), e `z-[40]` aqui não disputa com ele.
//
// ═══ ⚠️ A GEOMETRIA LITERAL — medida por SCRIPT (`sed`), 19/10/2026 ═════════
//
// `design_handoff_publicador/Aprovacao.dc.html` (1432 linhas, sha256
// `68624883ce35547e`). ⚠️ **O diretório importa**: há DUAS cópias do pacote de
// handoff na árvore, com conteúdo DIFERENTE.
//
//   · B1  backdrop → `:549` `position:fixed;inset:0;z-index:69;`
//                          `background:rgba(0,0,0,0.55)` + `onClick` fecha
//   · B2  `<aside>` → `:550` `position:fixed;top:0;right:0;bottom:0;`
//                          `width:min(1140px, calc(100vw - 100px));z-index:70;`
//                          `background:#0D0D0D;border-left:1px solid #2E2E2E;`
//                          `box-shadow:-24px 0 64px rgba(0,0,0,0.6);`
//                          `display:flex;flex-direction:column`
//   · B11 corpo    → `:562` `flex:1;overflow-y:auto;padding:18px 22px 24px;`
//                          `display:flex;flex-direction:column;gap:16px`
//   · B22 colunas  → `:586` `display:grid;`
//                          `grid-template-columns:minmax(0,1fr) minmax(0,1fr);`
//                          `gap:16px;align-items:start`
//   · B23 coluna   → `:587` e `:639` `background:#111111;border:1px solid #262626;`
//                          `border-radius:14px;padding:14px;display:flex;`
//                          `flex-direction:column;gap:12px`
//
// ⚠️ **OS TOKENS JÁ EXISTEM — nenhum é novo** (medidos em `tailwind.config.js`):
//   `shadow-pub-painel` **É EXATAMENTE** `-24px 0 64px rgba(0,0,0,0.6)` (`:265`).
//   Escrever `shadow-[-24px_0_64px_rgba(0,0,0,0.6)]` criaria a SEGUNDA cópia do
//   mesmo valor. · `pub-sunken` = `#0D0D0D` (`:114`) — **não** `pub-page`
//   (`#0A0A0A`) nem `pub-surface` (`#111111`): *"usar o token parecido que já
//   existe"* é justificativa **VETADA** (FID-02). · `pub-line-strong` = `#2E2E2E`
//   · `pub-line` = `#262626` · `rounded-pub-table` = `14px`.
//
// ⚠️ `min(1140px, calc(100vw - 100px))` vai como valor arbitrário:
// `w-[min(1140px,calc(100vw-100px))]`. ⚠️ O CSS construído **escapa TODOS os
// metacaracteres** — parênteses, vírgulas, colchetes, o `-` —, e **escapar só a
// barra NÃO basta** (foi a 7ª mentira do medidor nesta milestone:
// `shadow-[0_16px_40px_rgba(0,0,0,0.6)]` saía AUSENTE com a regra presente e
// correta). A sonda usa um `seletorDe()` que escapa **tudo**.
//
// ═══ ⚠️⚠️ AS DUAS COLUNAS TÊM O MESMO PESO — E NÃO SE REEQUILIBRA ══════════
//
// `minmax(0,1fr) minmax(0,1fr)` (`:586`) é **decisão de desenho declarada**: o
// README E2 diz *"Duas colunas de mesmo peso, para dar o mesmo destaque a fotos e
// preço"*, e **REVS-06 diz "Não reequilibrar"**. ⚠️ *"Deixei a de fotos mais
// estreita porque caberia melhor"* é **FID-02 literal**. Há gate por IGUALDADE de
// string, com isca do quase-igual (`minmax(0,2fr)` e `grid-cols-2` REPROVAM).
//
// ═══ ⚠️ A FRONTEIRA DESTA PEÇA ═════════════════════════════════════════════
//
// ⚠️ **O painel APROVA (e aprovar ENFILEIRA); ele NÃO publica.** A escrita no
// Mercado Livre é da **Fase 193** (critério 18).
// ⚠️ **A coluna de FOTOS é do plano 188-08 e a de PREÇO do 188-09**; pendências e
// rodapé são do 188-10; os modais do 188-11/12. ➡️ Aqui os dois slots chegam como
// **NÓ**, e **slot sem nó NÃO desenha casca** — nem borda, nem "nenhum item" (D4).
// ⚠️ **`painel.preco` pode chegar `null`** enquanto o plano 188-04 (que roda em
// paralelo) não fecha: **isso é esperado e não é defeito desta peça.**
// ⚠️ A Fase **189** é o lightbox (*"⤢ Ampliar"*) e a **192** o *"✦ Gerar novamente
// com IA"*: **NÃO são antecipadas**, e há gate de ausência.
// ⚠️ **NADA de `localStorage`** nem *"Restaurar dados de exemplo"* — são artefatos
// do protótipo (`KEY = 'ecf-aprovacao-v1'`, `:914`). A oferta aberta vive na **URL**
// (`?oferta=`, COL-02/COL-03) e a revisão parcial no **SERVIDOR** (critério 16).
//
// ⚠️ Nenhum `String(valor)`, nenhum `hasOwnProperty` e nenhum `obj[chave]` com
// chave vinda do servidor: os três **LANÇAM** com `{toString: null}`, e a TELA
// PRETA voltou SEIS vezes neste projeto — quatro na Fase 180 e uma na 187-06,
// essa **no clique**.
// ═══════════════════════════════════════════════════════════════════════════

/** `:549` — o backdrop. ⚠️ `z-[39]` é o remapeamento; o `69` do protótipo nunca vem. */
export const CLASSES_BACKDROP = 'fixed inset-0 z-[39] bg-black/[0.55]';

/**
 * `:550` — o `<aside>` de 1140px.
 *
 * ⚠️ `font-pub text-pub-txt` são **REND-05**, não decoração: conteúdo portado não
 * herda a fonte (ver o motivo 3 do docblock da classe).
 * ⚠️ `shadow-pub-painel` é o TOKEN com o valor exato de `:550`; não duplicar.
 */
export const CLASSES_PAINEL =
    'fixed inset-y-0 right-0 z-[40] flex w-[min(1140px,calc(100vw-100px))] flex-col '
    + 'border-l border-pub-line-strong bg-pub-sunken font-pub text-pub-txt shadow-pub-painel';

/** `:562` — o corpo rolável. ⚠️ `padding:18px 22px 24px` são TRÊS valores. */
export const CLASSES_CORPO =
    'flex flex-1 flex-col gap-[16px] overflow-y-auto pl-[22px] pr-[22px] pt-[18px] pb-[24px]';

/** ⚠️⚠️ `:586` — as DUAS COLUNAS DE MESMO PESO (critério 3 / REVS-06). NÃO reequilibrar. */
export const CLASSES_COLUNAS = 'grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)] items-start gap-[16px]';

/** `:587`/`:639` — a casca de UMA coluna. */
export const CLASSES_COLUNA =
    'flex flex-col gap-[12px] rounded-pub-table border border-pub-line bg-pub-surface p-[14px]';

/** COL-04 — a chave que viaja em TODA escrita do painel. */
export const CHAVE_DA_VERSAO = 'versao_esperada';

/**
 * COL-04 — a regra que o servidor devolve quando a oferta mudou debaixo de quem
 * revisa. ⚠️ **LIDA de `AprovacaoDaOfertaService::REGRA_VERSAO_DIVERGENTE`**
 * (`app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php:100`), não
 * inventada: dois literais para a mesma regra divergiriam na primeira edição.
 */
export const REGRA_VERSAO_DIVERGENTE = 'COL-04-VERSAO-DIVERGENTE';

/**
 * COL-04 — a CHAVE em que o servidor manda o **id estável da regra**.
 *
 * ⚠️⚠️ **MEDIDO na wave 188-12, e é o conserto de um DESCASAMENTO DE FORMATO que a
 * wave 188-06 declarou e passou adiante.** A recusa do painel é
 * `MlbPublicadorAprovacaoController::recusaDoPainel()`:
 *
 * ```php
 * return response()->json(['message' => $e->getMessage(), 'regra' => $e->regra], 422);
 * ```
 *
 * ⚠️ **O id da regra NÃO está dentro da mensagem** — ele está num CAMPO PRÓPRIO, e
 * o docblock daquele método diz por quê: *"é por ele que a tela decide onde marcar
 * o erro… Devolver só a mensagem obrigaria a tela a casar texto em português"*.
 * ➡️ **Ler o CAMPO é o certo; procurar substring na mensagem quebra no dia em que
 * alguém reescrever o texto** — e este projeto já tem cinco casos de `includes`
 * casando prefixo errado. A busca por substring continua como **fallback
 * declarado**, para a sacola `errors` do Inertia (que não tem `regra`).
 */
export const CHAVE_DA_REGRA = 'regra';

/** Objeto comum de verdade, ou `null`. ⚠️ `null` e array são os dois `typeof 'object'`. */
export function objetoDoPainel(valor) {
    return valor !== null && typeof valor === 'object' && !Array.isArray(valor) ? valor : null;
}

/** Inteiro finito, ou `null`. ⚠️ Nunca `Number(valor)`: `Number('')` é **0**. */
export function inteiroDoPainel(valor) {
    return typeof valor === 'number' && Number.isInteger(valor) ? valor : null;
}

/**
 * ⚠️⚠️ Um ID/versão de verdade: inteiro **POSITIVO**, ou `null` (Fase 189, plano 06).
 *
 * ⚠️ `inteiroDoPainel` aceita `0`, e `0` é um id **plausível** para quem lê — com
 * ele o contexto do lightbox sairia com `{oferta_id: 0}` e a escrita iria para
 * uma rota que não existe. Numa tela em que **aprovar enfileira e não há
 * desfazer**, escrita disparada sobre id inválido é pior que nenhuma: a pessoa vê
 * a ação acontecer e nada muda. É a MESMA disciplina do `idDoPainel` que a
 * `ColunaDeFotos` já aplica (conserto medido pelo gate do 188-08).
 */
export function idPositivoDoPainel(valor) {
    const inteiro = inteiroDoPainel(valor);

    return inteiro !== null && inteiro > 0 ? inteiro : null;
}

/**
 * Texto não-vazio de uma recusa do servidor, ou `null`.
 *
 * ⚠️ **Nunca `String(valor)`:** ele **LANÇA** com `{toString: null}`, e o corpo de
 * uma recusa vem da rede. ⚠️ E `typeof NaN === 'number'`, então número também não
 * passa: a mensagem que a tela mostra é SEMPRE string do servidor.
 */
export function textoDaRecusa(valor) {
    return typeof valor === 'string' && valor.trim() !== '' ? valor : null;
}

// ═══ ⚠️⚠️ 23/10/2026 (P-18e) — UMA ESCRITA DO PAINEL POR VEZ ═══════════════
//
// O duplo clique em "Aprovar para publicação" mandava DOIS POSTs: o segundo
// interrompia o primeiro no navegador (o Inertia cancela a visita em voo), o
// servidor já tinha aprovado o primeiro e o segundo voltava 422 — a tela
// mostrava erro numa oferta que FOI aprovada (e aprovar já publica).
// ➡️ A trava mora AQUI, no helper único, e não em cada botão: toda escrita do
// painel passa por ele. Ela liga no `onStart` (síncrono dentro do `router.post`,
// então o segundo clique já a encontra ligada) e desliga no `onFinish`, que o
// Inertia chama em TODO desfecho (sucesso, erro, 422 cru, interrupção).
// Leitura (`leitura: true`: simulação e prévia do frete) não liga a trava, mas
// também não sai com uma escrita em voo — ela interromperia a escrita.
// A tela lê a trava por `useEscritaDoPainelEmVoo()` para desabilitar os botões.
let escritaEmVoo = false;
const ouvintesDaEscrita = new Set();

function marcarEscritaEmVoo(valor) {
    if (escritaEmVoo === valor) return;

    escritaEmVoo = valor;
    ouvintesDaEscrita.forEach((ouvinte) => ouvinte());
}

/** Se há uma escrita do painel em voo agora (23/10/2026, P-18e). */
export function escritaDoPainelEmVoo() {
    return escritaEmVoo;
}

/** Assina a trava de escrita — o formato que `useSyncExternalStore` pede. */
export function assinarEscritaDoPainel(ouvinte) {
    if (typeof ouvinte !== 'function') return () => {};

    ouvintesDaEscrita.add(ouvinte);

    return () => {
        ouvintesDaEscrita.delete(ouvinte);
    };
}

/** A trava como valor de render; no render de servidor é sempre `false`. */
export function useEscritaDoPainelEmVoo() {
    return useSyncExternalStore(assinarEscritaDoPainel, escritaDoPainelEmVoo, () => false);
}

/**
 * ⚠️⚠️ **O HELPER ÚNICO DE ESCRITA DO PAINEL (W11 / critério 17 / COL-04).**
 *
 * **O defeito que ele conserta:** o envio de `versao_esperada` estava atribuído
 * ao plano 188-12, mas as escritas vivem em componentes dos planos **08 a 11**,
 * **fora do `files` do 12** — e **nenhum plano tinha o helper**. Sem dono, a
 * concorrência ficava sem mecanismo.
 *
 * O que ele faz, nesta ordem:
 *   1. acrescenta `versao_esperada: versao` ao corpo **SEMPRE** — nunca "em
 *      algumas" chamadas. Sem a versão, `AprovacaoDaOfertaService::exigirVersao()`
 *      recebe `null` e **libera a escrita** (o ramo de uso interno/console), que é
 *      literalmente *"sobrescrever em silêncio"*;
 *   2. dispara o `router.post`;
 *   3. ⚠️⚠️ no erro de versão divergente chama `onConflito`, **NUNCA ENGOLE**. Um
 *      `catch` silencioso aqui é pior que o bug: deixaria a pessoa achando que
 *      aprovou — e **aprovar JÁ PUBLICA, sem desfazer**.
 *
 * ═══ ⚠️⚠️ OS DOIS CAMINHOS DO 422, E POR QUE O `onError` NÃO BASTA ═══════
 *
 * **MEDIDO na wave 188-12, em `@inertiajs/core` 2.3.21:**
 *   · `Response.process()` (`dist/index.esm.js:1912`-`:1913`) desvia para
 *     `handleNonInertiaResponse()` quando a resposta **não tem o header
 *     `x-inertia`**;
 *   · `handleNonInertiaResponse()` (`:1947`-`:1959`) dispara o evento
 *     **`inertia:invalid`** e, se ninguém o cancelar, **mostra o MODAL de erro**;
 *   · `onError` só é chamado **dentro** do ramo Inertia, a partir de
 *     `page.props.errors` (`:1916`-`:1922`).
 *
 * ⚠️⚠️ **E as recusas deste painel são `response()->json([...], 422)` CRUAS** —
 * não são resposta Inertia. ➡️ **Pelo `onError` elas NUNCA chegariam**, e a pessoa
 * veria o modal de erro bruto do Inertia em cima do painel. Isso é *"engolir"* com
 * outra roupa: o aviso de concorrência nunca acenderia.
 *
 * ➡️ **A saída, e ela é 100% do lado do CLIENTE — o servidor da wave 188-05 NÃO
 * foi tocado:** o helper registra um ouvinte de `invalid` **só pela duração da
 * requisição** (removido no `onFinish`), lê o campo **`regra`** do corpo,
 * chama `onConflito(message)` e **cancela o modal** devolvendo `false`. ⚠️ Quando
 * a recusa **não** traz `regra`, o modal **continua aparecendo**: erro inesperado
 * não pode virar silêncio.
 * ⚠️ **As duas saídas de SERVIDOR foram consideradas e NÃO aplicadas:** trocar a
 * recusa por `back()->withErrors([...])` faria o `onError` funcionar, mas é
 * **editar um controller da wave 188-05 que já está commitado** — e o formato
 * `{message, regra}` é o que o módulo inteiro já usa (`recusaDoPainel` é chamado
 * por **nove** actions). **Fica DECLARADO como pergunta no fechamento, não
 * decidido aqui.**
 * ⚠️ `typeof router.on !== 'function'` é tolerado (devolve `undefined`): o dublê de
 * `@inertiajs/react` de gates mais antigos não tem `on`, e o helper não pode
 * LANÇAR por causa disso.
 *
 * ⚠️ **Sem `versao` inteira, NADA é enviado** e a função devolve `false`: é a
 * mesma régua do botão sem destino — não se escreve sobre uma oferta cuja versão
 * a tela não viu.
 * ⚠️ **A metade SERVIDOR já existe** (`REGRA_VERSAO_DIVERGENTE`, planos 188-05/06)
 * e o **AVISO na tela** é o plano **188-12**. Os três declaram a fronteira.
 * ⚠️ **Gate: nenhum componente de `aprovacao/` criado pelos planos 08-12 chama
 * `router.post` direto** — a lista é CONSTANTE, com isca nas duas direções.
 *
 * @param {object} [pedido]
 * @param {unknown} [pedido.rota] o NOME da rota (Ziggy)
 * @param {unknown} [pedido.parametros] os parâmetros da rota
 * @param {unknown} [pedido.corpo] o corpo, SEM a versão
 * @param {unknown} [pedido.versao] a `versao` que a pessoa VIU (`painel.versao`)
 * @param {unknown} [pedido.onConflito] `(mensagem, ehConflito) => void`, chamado em
 *   TODA recusa do painel. ⚠️ 23/10/2026 (P-18d): `ehConflito` é `true` SÓ no 422
 *   de VERSÃO divergente (COL-04); nas outras recusas é `false`, e a tela mostra
 *   a mensagem do servidor SEM dizer que a oferta mudou
 * @param {unknown} [pedido.onSucesso] `() => void`, chamado só quando o servidor
 *   ACEITOU (23/10/2026, P-18e: o modal fecha aqui, não quando o envio sai)
 * @param {unknown} [pedido.leitura] `true` para a simulação e a prévia do frete:
 *   não liga a trava de escrita (23/10/2026, P-18e)
 * @returns {boolean} se a escrita foi disparada
 */
export function escreverNoPainel(pedido = null) {
    const alvo = objetoDoPainel(pedido);
    if (alvo === null) return false;

    const nomeDaRota = typeof alvo.rota === 'string' && alvo.rota.trim() !== '' ? alvo.rota : null;
    const versaoVista = inteiroDoPainel(alvo.versao);

    // ⚠️ Sem rota ou sem versão não se escreve. Devolver `false` (e não lançar) é
    // o que permite ao chamador NÃO desenhar o botão — a régua do D4.
    if (nomeDaRota === null || versaoVista === null) return false;

    // 23/10/2026 (P-18e): com uma escrita em voo nada sai — nem a segunda (o duplo
    // clique), nem uma leitura, que interromperia a escrita no navegador.
    if (escritaEmVoo) return false;

    const ehLeitura = alvo.leitura === true;
    const corpo = objetoDoPainel(alvo.corpo) ?? {};
    const parametros = objetoDoPainel(alvo.parametros) ?? {};
    const aoConflitar = typeof alvo.onConflito === 'function' ? alvo.onConflito : null;
    const aoTerSucesso = typeof alvo.onSucesso === 'function' ? alvo.onSucesso : null;

    // ⚠️⚠️ O CAMINHO REAL do 422 deste painel (ver o docblock): a recusa é
    // `response()->json([...], 422)` CRUA, então o Inertia desvia para
    // `handleNonInertiaResponse()` e dispara `inertia:invalid`. **Sem este ouvinte
    // o `onConflito` nunca seria chamado**, e a pessoa veria o modal de erro bruto
    // em cima do painel — *"engolir"* com outra roupa.
    // ⚠️ `typeof router.on !== 'function'` é tolerado: dublê de gate antigo não o
    // tem, e o helper não pode LANÇAR por isso.
    const desinscrever = aoConflitar === null || typeof router.on !== 'function'
        ? null
        : router.on('invalid', (evento) => {
            // ⚠️ Flag DENTRO do callback. E guarda de tipo ANTES de qualquer
            // acesso: `evento.detail.response.data` vem da rede.
            const dados = objetoDoPainel(objetoDoPainel(objetoDoPainel(evento?.detail)?.response)?.data);
            const regra = dados === null ? null : textoDaRecusa(dados[CHAVE_DA_REGRA]);

            // ⚠️ Recusa SEM `regra` NÃO é cancelada: erro inesperado não pode
            // virar silêncio, e o modal do Inertia é o sinal certo para ele.
            if (regra === null) return undefined;

            // 23/10/2026 (P-18d): toda recusa com `regra` continua chegando, mas
            // só a de VERSÃO é conflito — antes um frete recusado (MOD-03) virava
            // "a oferta mudou, recarregue".
            aoConflitar(textoDaRecusa(dados.message) ?? regra, regra === REGRA_VERSAO_DIVERGENTE);

            // ⚠️ `false` CANCELA o modal de erro do Inertia (`onGlobalEvent`,
            // `dist/index.esm.js:1373`-`:1381`): o aviso da tela é o sinal, e dois
            // sinais para o mesmo fato treinam a pessoa a ignorar os dois.
            return false;
        });

    router.post(
        route(nomeDaRota, parametros),
        // ⚠️⚠️ A versão entra DEPOIS do espalhamento, de propósito: um `corpo` que
        // trouxesse `versao_esperada` por engano **não pode** vencer a versão que a
        // tela viu. É a única fonte dela.
        { ...corpo, [CHAVE_DA_VERSAO]: versaoVista },
        {
            preserveScroll: true,
            // 23/10/2026 (P-18e): a trava liga aqui — o `onStart` roda DENTRO do
            // `router.post`, antes do pedido sair.
            onStart: () => {
                if (!ehLeitura) marcarEscritaEmVoo(true);
            },
            // 23/10/2026 (P-18e): só o servidor ACEITANDO conta como sucesso (o
            // `onSuccess` não roda no 422 cru nem com a sacola `errors` cheia).
            onSuccess: () => {
                if (aoTerSucesso !== null) aoTerSucesso();
            },
            onError: (erros) => {
                if (aoConflitar === null) return;

                // ⚠️ A mensagem é a do SERVIDOR (ela diz as DUAS versões). Aqui só
                // se procura a regra; o texto nunca é remontado no cliente.
                const sacola = objetoDoPainel(erros) ?? {};

                // ⚠️⚠️ **O CAMPO `regra` PRIMEIRO** (é o formato que o servidor
                // devolve, medido), e a busca por SUBSTRING só como **fallback** —
                // a sacola `errors` do Inertia não tem `regra`, e ali a mensagem é
                // o único sinal. ⚠️ Substring em mensagem de erro quebra quando
                // alguém reescreve o texto.
                const regra = textoDaRecusa(sacola[CHAVE_DA_REGRA]);

                const mensagens = Object.keys(sacola)
                    .filter((chave) => chave !== CHAVE_DA_REGRA)
                    .map((chave) => sacola[chave])
                    .filter((texto) => typeof texto === 'string' && texto.trim() !== '');

                const conflito = regra === REGRA_VERSAO_DIVERGENTE
                    ? (textoDaRecusa(sacola.message) ?? mensagens[0] ?? null)
                    : (mensagens.find((texto) => texto.includes(REGRA_VERSAO_DIVERGENTE)) ?? null);

                // ⚠️ `onConflito` é chamado com a mensagem do conflito quando ela
                // vier, e com a PRIMEIRA mensagem quando não vier — **nunca em
                // silêncio**, porque o pior resultado aqui é a pessoa achar que
                // aprovou. 23/10/2026 (P-18d): o 2º argumento diz se é conflito de
                // VERSÃO; validação e regra de negócio chegam com `false`.
                aoConflitar(conflito ?? mensagens[0] ?? null, regra === REGRA_VERSAO_DIVERGENTE || conflito !== null);
            },
            // ⚠️⚠️ O ouvinte vive só pela DURAÇÃO da requisição. Ouvinte global
            // permanente pegaria o `invalid` de QUALQUER outra tela.
            // 23/10/2026 (P-18e): e a trava de escrita desliga aqui, em todo desfecho.
            onFinish: () => {
                if (!ehLeitura) marcarEscritaEmVoo(false);
                if (typeof desinscrever === 'function') desinscrever();
            },
        },
    );

    return true;
}

/**
 * `:549` — o backdrop, cujo clique fecha o painel.
 *
 * ⚠️ NAMED EXPORT: o render de servidor não desenha portal (motivo 2 do docblock).
 */
export function BackdropDoPainel({ onFechar = null }) {
    return (
        <div
            className={CLASSES_BACKDROP}
            onClick={typeof onFechar === 'function' ? () => onFechar() : undefined}
        />
    );
}

/**
 * `:562` — o corpo rolável do painel.
 *
 * ⚠️ Os filhos são filtrados **um a um**, e isso é conserto MEDIDO em
 * `CascaDeModal.jsx:197`-`:207`: um array de filhos pode ter **objeto comum** no
 * meio, e **prop de NÓ chegando objeto comum faz o React LANÇAR** e mata a página
 * inteira (*"Objects are not valid as a React child"*). ⚠️ `null`, string, número
 * e booleano são filhos LEGÍTIMOS e passam; objeto comum, não.
 */
export function CorpoDoPainel({ children = null, className = null }) {
    const classeExtra = typeof className === 'string' && className.trim() !== '' ? className : null;

    return <div className={cn(CLASSES_CORPO, classeExtra)}>{filhosSeguros(children)}</div>;
}

/**
 * Os filhos que o React aceita, filtrados um a um.
 *
 * ⚠️ NAMED EXPORT para o gate exercer o caso adverso sem montar a árvore toda.
 */
export function filhosSeguros(children) {
    // ⚠️ `isValidElement` é o PRECEDENTE MEDIDO de `CascaDeModal.jsx:203`-`:205`,
    // que está em produção — não uma segunda regra escrita de cabeça. ⚠️ E ele é
    // **nome de MÓDULO**, que o Rollup nunca elide: a flag computada aqui dentro
    // só lê o parâmetro e esse nome, que é a forma que a 187-09 mediu como segura
    // dentro de `.filter()`/`.map()`.
    const valido = (filho) => filho === null || filho === undefined
        || typeof filho === 'string' || typeof filho === 'number' || typeof filho === 'boolean'
        || isValidElement(filho);

    if (Array.isArray(children)) {
        const limpos = children.filter((filho) => valido(filho));

        return limpos.length > 0 ? limpos : null;
    }

    return valido(children) ? children : null;
}

/**
 * ⚠️⚠️ `:586` — as DUAS COLUNAS DE MESMO PESO.
 *
 * Os dois slots chegam como **NÓ** (fotos → plano 188-08, preço → 188-09), e
 * **slot sem nó NÃO desenha casca**: a coluna inteira some em vez de aparecer uma
 * borda vazia de 14px (D4 — nada nasce decorativo).
 */
export function ColunasDoPainel({ colunaDeFotos = null, colunaDePreco = null }) {
    const fotos = filhosSeguros(colunaDeFotos);
    const preco = filhosSeguros(colunaDePreco);

    if (fotos === null && preco === null) return null;

    return (
        <div className={CLASSES_COLUNAS}>
            {fotos}
            {preco}
        </div>
    );
}

/** `:587`/`:639` — a casca de UMA coluna, para os planos 188-08/09 montarem dentro. */
export function ColunaDoPainel({ children = null, className = null }) {
    const classeExtra = typeof className === 'string' && className.trim() !== '' ? className : null;

    return <section className={cn(CLASSES_COLUNA, classeExtra)}>{filhosSeguros(children)}</section>;
}

// ═══════════════════════════════════════════════════════════════════════════
// ⚠️⚠️ OS ATALHOS DE TECLADO — FUNÇÃO PURA, E O RUNTIME **NÃO É PROVADO**
// ═══════════════════════════════════════════════════════════════════════════
//
// ⚠️⚠️ **DECLARAÇÃO OBRIGATÓRIA: não há navegador nesta máquina.** Todo render
// desta fase é `esbuild` + `react-dom/server`, e `renderToStaticMarkup` **não
// dispara evento nenhum**. ➡️ O que está PROVADO é a **FUNÇÃO** que decide o que
// cada tecla faz; o `addEventListener` do navegador **NÃO está provado**, e esta
// fase não pode afirmar *"a tecla funciona"*.
//
// ⚠️ A ORDEM do `Escape` é LITERAL do protótipo (`:1014`, medido por `sed` na
// wave 188-07 e RECONFIRMADO em 20/10/2026): `modal → lightbox → menu → painel`.
// ➡️ ⚠️⚠️ **O DEGRAU DO LIGHTBOX, que esta wave deixou "PREVISTO e DECLARADO",
// ENTROU NO CÓDIGO pela Fase 189, plano 06** (ACHADO A7 do
// `189-CONTRATO-188.md`): a cadeia agora é `modal → lightbox → painel`, pelo
// parâmetro `temLightbox` do resolvedor (padrão `false`, então **todos** os casos
// desta wave continuam valendo sem alteração). O `menu` é da Fase 187 e não vive
// neste painel.
// ⚠️⚠️ E É ESTE PAINEL QUE TEM O **ÚNICO** `keydown` DA TELA — dois ouvintes
// fechariam lightbox **e** painel no MESMO `Escape`, e a pessoa perderia a
// revisão inteira num toque. O lightbox é **CONTROLADO**: o estado dele mora na
// página (ver o bloco do CONTEXTO, abaixo do resolvedor).
//
// ⚠️⚠️ **DIVERGÊNCIA DECLARADA DO PLANO, e o PROTÓTIPO GANHA.** O `<behavior>` do
// plano diz *"os QUATRO atalhos não disparam quando o foco está em INPUT,
// TEXTAREA ou SELECT"*. **Medido por `sed` em 19/10/2026:** no protótipo o
// `if (typing) return;` está em **`:1015`**, ou seja **DEPOIS** do bloco do
// `Escape` (`:1014`) — então **o `Escape` dispara TAMBÉM enquanto se digita**, e
// só `↑ ↓ ← →` são barrados. ➡️ Reproduzido como no protótipo, porque o
// contrário TRANCARIA a pessoa dentro de um campo de texto de modal: Esc é
// exatamente a saída de que ela precisa ali. **São TRÊS atalhos barrados, não
// quatro.**
//
// ⚠️ `e.target.tagName` pode vir `undefined` — o protótipo já usa
// `(e.target.tagName || '')` em `:1013`. **Guarda de tipo.**
//
// ⚠️⚠️ `←`/`→` são CLAMPADAS nas DUAS pontas, e o protótipo clampa em DOIS
// lugares diferentes: `:1018` clampa só o PISO (`Math.max(0, S.pImg + delta)`) e
// o TETO é clampado na LEITURA (`:1293`: `imgs[Math.min(selIdx, imgs.length - 1)]
// || null`, medido por `sed`). ➡️ Aqui as duas pontas são resolvidas na própria
// função: índice fora da lista **não pode dar bloco vazio**.

/** As teclas que este painel escuta. ⚠️ Lista FECHADA: o resto passa adiante. */
export const TECLAS = {
    FECHAR: 'Escape',
    ANTERIOR: 'ArrowUp',
    PROXIMA: 'ArrowDown',
    IMAGEM_ANTERIOR: 'ArrowLeft',
    IMAGEM_PROXIMA: 'ArrowRight',
};

/** As tags em que `↑ ↓ ← →` **não** disparam (`:1013`). ⚠️ `Escape` dispara. */
export const TAGS_DE_CAMPO = ['INPUT', 'TEXTAREA', 'SELECT'];

/** As ações que o resolvedor pode devolver. */
export const ACOES = {
    FECHAR_MODAL: 'fechar-modal',
    FECHAR_PAINEL: 'fechar-painel',
    IR_PARA_OFERTA: 'ir-para-oferta',
    TROCAR_IMAGEM: 'trocar-imagem',
    // ⚠️ Fase 189, plano 06 — O DEGRAU que a 188-07 deixou "previsto e
    // DECLARADO" (ACHADO A7 do `189-CONTRATO-188.md`), agora no código.
    FECHAR_LIGHTBOX: 'fechar-lightbox',
    MOVER_LIGHTBOX: 'mover-lightbox',
};

/**
 * ⚠️⚠️ O DESTINO DO FOCO ao fechar o lightbox (critério 7, PERGUNTA-189-C).
 *
 * ⚠️⚠️ **POR QUE O TOKEN NÃO CITA O NOME DO BOTÃO.** A FONTE deste arquivo é
 * varrida SEM COMENTÁRIO por `/Ampliar/` e `/⤢/` em
 * `tests/js/publicador-painel-casca-188.test.js:1034`-`:1035` (ARMADILHA A2 do
 * inventário). Um token `'ampliar'` **minúsculo** passaria — mas passaria **POR
 * SORTE**, dependendo da CAIXA de uma única letra, e *"silêncio que depende de
 * UMA LETRA é sorte"*. ➡️ Divergência DECLARADA do `focar: 'ampliar'` escrito na
 * prosa do plano: o token é descritivo e não depende de caixa nenhuma.
 */
export const FOCO_NO_BOTAO_DE_ABRIR = 'botao-que-abriu';

/**
 * ⚠️⚠️ **O RESOLVEDOR DE ATALHO — a FUNÇÃO PURA que os gates provam.**
 *
 * ⚠️⚠️ **DESVIO DECLARADO DA ASSINATURA DO PLANO, e ele é o conserto que a
 * própria prosa do plano manda fazer.** A assinatura prescrita era
 * `({tecla, temModal, alvoEhCampo, indiceAtual, total, imagemAtual,
 * totalDeImagens})` — com `indiceAtual`/`total`, que obrigariam a tela a
 * **recalcular a fila no cliente**. ⚠️ O próprio plano corrige isso: *"O
 * resolvedor devolve o ID de destino lido de `anterior_id`/`proxima_id` — nunca
 * recalcula a fila, nunca filtra `preparando` no cliente"*. ➡️ Ele recebe os
 * **IDs** que o plano 188-02 entrega. Filtrar `preparando` aqui seria a segunda
 * verdade que aquele plano existe para impedir.
 *
 * ⚠️ `preparando` **sai da NAVEGAÇÃO mas CONTINUA nos `ids` do LOTE**
 * (divergência **D9** da Fase 187, já no ar): **são listas diferentes, e não se
 * unificam.** Este resolvedor lê a da NAVEGAÇÃO.
 *
 * @param {object} [evento]
 * @param {unknown} [evento.tecla] `event.key`
 * @param {unknown} [evento.temModal] se algum modal está aberto (planos 188-11/12)
 * @param {unknown} [evento.alvoEhCampo] se o foco está em INPUT/TEXTAREA/SELECT
 * @param {unknown} [evento.anteriorId] `navegacao.anterior_id` — `null` na primeira
 * @param {unknown} [evento.proximaId] `navegacao.proxima_id` — `null` na última
 * @param {unknown} [evento.imagemAtual] o índice da imagem selecionada
 * @param {unknown} [evento.totalDeImagens] quantas imagens há na galeria
 * @param {unknown} [evento.temLightbox] se o LIGHTBOX está aberto (Fase 189,
 *   plano 06). ⚠️ Padrão `false`: **todos** os casos do 188-07 chamam o
 *   resolvedor sem ele e continuam valendo sem alteração
 * @param {unknown} [evento.imagemDoLightbox] o índice aberto NO LIGHTBOX — que é
 *   do CONTEXTO DELE (PERGUNTA-189-B (b)), e **não** o do painel
 * @param {unknown} [evento.totalDoLightbox] quantas imagens há na galeria DO
 *   CONTEXTO DO LIGHTBOX (pode ser outra oferta que a do painel por baixo)
 * @returns {{acao: string, oferta: ?number, imagem: ?number}|null} `null` = a
 *   tecla não é desta peça e **não é consumida** (nada de `preventDefault`).
 */
export function resolverAtalhoDoPainel(evento = null) {
    const e = objetoDoPainel(evento);
    if (e === null) return null;

    const tecla = typeof e.tecla === 'string' ? e.tecla : null;
    if (tecla === null) return null;

    const temModal = e.temModal === true;

    // ⚠️ `=== true` ESTRITO: `1`, `'1'` e `{}` são **truthy** e fechariam o
    // lightbox sobre um estado que não é o dele. É a mesma guarda do `aberto` do
    // `LightboxDaImagem` (189-03).
    const temLightbox = e.temLightbox === true;

    // ⚠️⚠️ `Escape` PRIMEIRO, e **ANTES da guarda de campo** — é a ordem medida em
    // `:1014`/`:1015`. ⚠️⚠️ A cadeia COMPLETA do protótipo é
    // `modal → lightbox → menu → painel`, e o DEGRAU DO LIGHTBOX entrou aqui no
    // plano 189-06 (ele estava "previsto e declarado" desde o 188-07 — ACHADO A7).
    // O `menu` é da Fase 187 e não vive neste painel.
    // ⚠️ Sem o degrau, um `Escape` com o lightbox aberto fecharia o PAINEL
    // INTEIRO, e a pessoa perderia a revisão num toque.
    if (tecla === TECLAS.FECHAR) {
        const acao = temModal
            ? ACOES.FECHAR_MODAL
            : (temLightbox ? ACOES.FECHAR_LIGHTBOX : ACOES.FECHAR_PAINEL);

        return { acao, oferta: null, imagem: null };
    }

    // ⚠️ Daqui para baixo, campo de texto engole a tecla (`:1015`). Sem isso,
    // digitar "a seta" numa busca navegaria a fila por baixo de quem digita.
    if (e.alvoEhCampo === true) return null;

    // ⚠️ Com modal aberto, `↑ ↓ ← →` não fazem nada (`:1017`/`:1018` exigem
    // `!S.modal`): navegar a fila por trás de um modal aberto trocaria a oferta
    // que o modal está confirmando.
    if (temModal) return null;

    // ═══ ⚠️⚠️ O DEGRAU DE ←/→: O LIGHTBOX GANHA, E ELE DÁ A VOLTA ══════════
    //
    // ⚠️ Medido em `:1016`: o bloco do lightbox vem ANTES do `:1018` do painel e
    // dá `return`, então com ele aberto as setas **não** chegam ao painel.
    // ⚠️⚠️ **O LIGHTBOX DÁ A VOLTA e o PAINEL CLAMPA — são regras DIFERENTES de
    // propósito, e o protótipo ganha. NÃO unifique.** A volta é
    // `moverNoLightbox` (189-03), IMPORTADA: uma fonte só.
    // ⚠️ O índice do PAINEL **não se mexe** aqui: trocar a foto por baixo
    // deixaria a pessoa aprovando no lightbox uma foto e vendo outra no painel.
    if (temLightbox && (tecla === TECLAS.IMAGEM_ANTERIOR || tecla === TECLAS.IMAGEM_PROXIMA)) {
        const passo = tecla === TECLAS.IMAGEM_PROXIMA ? 1 : -1;
        const destino = moverNoLightbox(e.imagemDoLightbox, passo, e.totalDoLightbox);

        // ⚠️ Galeria vazia / entrada adversa → a tecla **não** é consumida.
        return destino === null ? null : { acao: ACOES.MOVER_LIGHTBOX, oferta: null, imagem: destino };
    }

    // ⚠️⚠️ ↑/↓ CONTINUAM navegando a fila por baixo, COM o lightbox aberto —
    // PERGUNTA-189-B (b), decisão do usuário de 19/10/2026, e é o que `:1017` faz
    // (medido: ele **não** testa `!S.lb`). Quem guarda a oferta certa é o
    // CONTEXTO do lightbox (`contextoDoLightbox`), não esta tecla.
    if (tecla === TECLAS.ANTERIOR || tecla === TECLAS.PROXIMA) {
        // ⚠️ O destino é **LIDO** do servidor. `null` na ponta → a tecla **não
        // navega** (e devolve `null`, então nem consome o evento).
        const destino = inteiroDoPainel(tecla === TECLAS.ANTERIOR ? e.anteriorId : e.proximaId);

        return destino === null ? null : { acao: ACOES.IR_PARA_OFERTA, oferta: destino, imagem: null };
    }

    if (tecla === TECLAS.IMAGEM_ANTERIOR || tecla === TECLAS.IMAGEM_PROXIMA) {
        const total = inteiroDoPainel(e.totalDeImagens);

        // ⚠️ Sem galeria não há o que trocar — e índice numa lista vazia daria
        // bloco vazio na tela.
        if (total === null || total <= 0) return null;

        const atual = inteiroDoPainel(e.imagemAtual) ?? 0;
        const passo = tecla === TECLAS.IMAGEM_PROXIMA ? 1 : -1;

        // ⚠️⚠️ AS DUAS PONTAS. `Math.max(0, …)` é o piso de `:1018`;
        // `Math.min(…, total - 1)` é o teto que o protótipo clampa só na LEITURA
        // (`:1293`). Resolver os dois aqui é o que impede índice fora da lista.
        const destino = Math.min(Math.max(0, atual + passo), total - 1);

        // ⚠️ Já na ponta, a tecla **não** é consumida: `preventDefault` numa tecla
        // que não faz nada tira a rolagem da pessoa sem motivo.
        return destino === atual ? null : { acao: ACOES.TROCAR_IMAGEM, oferta: null, imagem: destino };
    }

    return null;
}

// ═══════════════════════════════════════════════════════════════════════════
// ⚠️⚠️ O CONTEXTO PRÓPRIO DO LIGHTBOX — PERGUNTA-189-B (b), decisão do usuário
// ═══════════════════════════════════════════════════════════════════════════
//
// ⚠️⚠️ **POR QUE ISTO EXISTE, e é T-189-42.** Com o lightbox aberto, ↑/↓
// continuam navegando a fila por baixo (`:1017`). Se as ações do lightbox
// lessem a oferta e a versão do PAINEL, uma pessoa que abrisse na oferta A e
// descesse para a B aprovaria a foto da A **com a versão da B** — e
// `AprovacaoDaOfertaService::exigirVersao()` ou recusaria por 422, ou (pior)
// escreveria sobre uma composição que ela não viu. ➡️ O lightbox CAPTURA o
// próprio contexto na ABERTURA e só o refresca enquanto o painel mostrar A
// MESMA oferta.
//
// ⚠️ **ONDE O ESTADO MORA, e a divergência DECLARADA da prosa do plano.** O
// plano dizia *"o estado do lightbox vive NO PAINEL"*. Medido: a coluna de
// fotos — que é quem hospeda o "⤢ Ampliar" — chega a este painel como **NÓ
// PRONTO**, pelo slot `colunaDeFotos` (`ColunasDoPainel`, e a página monta o nó
// em `Pages/Mlb/Publicador/Aprovacao.jsx`). Um estado vivendo aqui **nunca
// alcançaria o botão**, porque o nó já foi construído lá fora. ➡️ O estado mora
// na PÁGINA (que já é a casa da foto selecionada, do modal aberto e do popover),
// e o `LightboxDaImagem` é **CONTROLADO** — exatamente como o docblock dele
// prescreve. ⚠️⚠️ **A RAZÃO que o plano deu para o estado morar aqui está
// PRESERVADA INTEIRA: este painel continua tendo o ÚNICO `keydown`** (dois
// ouvintes fechariam lightbox E painel no mesmo `Escape`), e as REGRAS PURAS
// moram neste módulo, ao lado do resolvedor.

/**
 * ⚠️⚠️ O contexto do lightbox: capturado na ABERTURA, refrescado SÓ na MESMA oferta.
 *
 * @param {unknown} guardado o contexto já capturado, ou `null` (abertura)
 * @param {unknown} painelAtual o `painel` do payload de AGORA
 * @param {unknown} lightboxAtual a prop `lightbox` do payload de AGORA (189-02)
 * @returns {?object} `{oferta_id, versao, ao_vivo, imagens, originais}`, ou `null`
 *
 * ⚠️⚠️ **COMPARAÇÃO ESTRITA de `oferta_id`** (inteiro com inteiro): `'501'` é
 * OUTRA oferta, nunca "a mesma". Com `==` a string seria lida como o inteiro e o
 * contexto se refrescaria com dado alheio.
 * ⚠️ Sem oferta ou sem versão **não há contexto** (`null`): um contexto com
 * `oferta_id` nula escreveria em `{oferta: null}`, uma rota que não existe. É a
 * mesma disciplina do `idDoPainel` da coluna — inteiro **POSITIVO**, porque `0`
 * é um id plausível para quem lê.
 * ⚠️ `ao_vivo` sai de `rodape.botoes.salvar`, que **É** o `$aoVivo` byte a byte
 * (`PainelDeRevisaoService.php:647`) — **uma definição só** de "ao vivo", nunca
 * uma segunda inventada na tela.
 * ⚠️ Nunca lança: `imagens` que não é lista cai em `[]` (`.map` em string LANÇA)
 * e `originais` que não é objeto comum cai em `{}`.
 */
export function contextoDoLightbox(guardado, painelAtual, lightboxAtual) {
    const atual = objetoDoPainel(painelAtual);
    const antigo = objetoDoPainel(guardado);

    const oferta = atual === null ? null : idPositivoDoPainel(atual.oferta_id);
    const versao = atual === null ? null : idPositivoDoPainel(atual.versao);

    // ⚠️ Payload sem oferta/versão: fica o GUARDADO (se houver) — nunca um
    // contexto meio-feito, que é o que escreveria na oferta errada.
    if (oferta === null || versao === null) return antigo;

    // ⚠️⚠️ OUTRA oferta no painel → o GUARDADO, INTACTO. É aqui que a
    // PERGUNTA-189-B (b) vive.
    if (antigo !== null && antigo.oferta_id !== oferta) return antigo;

    const fotos = objetoDoPainel(atual.fotos);
    const rodape = objetoDoPainel(atual.rodape);
    const botoes = rodape === null ? null : objetoDoPainel(rodape.botoes);
    const doLightbox = objetoDoPainel(lightboxAtual);
    const originais = doLightbox === null ? null : objetoDoPainel(doLightbox.originais);

    return {
        oferta_id: oferta,
        versao,
        ao_vivo: botoes !== null && botoes.salvar === true,
        imagens: fotos === null || !Array.isArray(fotos.imagens) ? [] : fotos.imagens,
        originais: originais === null ? {} : originais,
    };
}

/**
 * O índice válido DENTRO do contexto do lightbox — ou `null`, que é FECHAR.
 *
 * ⚠️ O clamp é `indiceNoLightbox` (189-03), IMPORTADO: `:1324` clampa o teto
 * (`Math.min(lb.i, ord.length - 1)`) e `i < 0` devolve `{open: false}`.
 * ⚠️ Reprovar tira a foto do conjunto final, então o payload seguinte pode vir
 * com MENOS imagens — sem o clamp o lightbox apontaria para uma foto que já não
 * existe.
 */
export function indiceNoContexto(indice, contexto) {
    const dados = objetoDoPainel(contexto);
    if (dados === null) return null;

    const total = Array.isArray(dados.imagens) ? dados.imagens.length : 0;

    return indiceNoLightbox(indice, total);
}

/**
 * ⚠️ Fecha o lightbox e DEVOLVE O FOCO ao botão que o abriu (critério 7).
 *
 * @param {unknown} pedido `{ref, fechar}`
 * @returns {boolean} `true` se fechou
 *
 * ⚠️⚠️ **FECHAR ACONTECE SEMPRE, mesmo sem foco a devolver.** Um fechamento que
 * dependesse da `ref` deixaria o lightbox PRESO na tela quando o botão não
 * existisse (galeria vazia, oferta sem imagem) — e aí a pessoa não tem como sair.
 * ⚠️ `ref` nula, sem `current`, ou com `current.focus` que não é função **não
 * lança**: `ref.current.focus()` cru é a mesma família da TELA PRETA.
 * ⚠️ O que roda no NAVEGADOR não é provado por esta fase: o gate exercita um
 * STUB que conta chamadas. **Não há navegador nesta máquina.**
 */
export function fecharLightboxDevolvendoFoco(pedido = null) {
    const alvo = objetoDoPainel(pedido);
    if (alvo === null || typeof alvo.fechar !== 'function') return false;

    alvo.fechar();

    const ref = objetoDoPainel(alvo.ref);
    const no = ref === null ? null : objetoDoPainel(ref.current);
    if (no !== null && typeof no.focus === 'function') no.focus();

    return true;
}

/**
 * ⚠️⚠️ O que acontece AO FECHAR — PERGUNTA-189-C e o DEFAULT da PERGUNTA-189-D1.
 *
 * @param {unknown} pedido `{contexto, painelAtual}`
 * @returns {{mudarFotoDoPainel: boolean, focar: ?string, voltarParaAOferta: ?number}}
 *
 * ➡️ **189-C (a oferta por baixo NÃO mudou) — RESPONDIDA pelo usuário:** o painel
 * volta à foto de onde o botão foi clicado (ou seja: **não se mexe**, porque ela
 * nunca deixou de ser a selecionada) e o foco vai ao botão que abriu.
 *
 * ➡️ ⚠️⚠️ **189-D1 (a oferta MUDOU) — PERGUNTA ABERTA, DEFAULT APLICADO:** o
 * painel **FICA na oferta nova**, porque a navegação ↑/↓ foi ação explícita da
 * pessoa, na foto que aquela oferta já tinha selecionada; o foco vai ao botão do
 * painel dela — ou a **nada**, sem lançar, se ela não tem imagem (a `ref` fica
 * nula). ⚠️ A ALTERNATIVA (um `router.get` com `?oferta=A`, voltando o painel) é
 * **local** e **NÃO** é o default: `voltarParaAOferta` sai `null` sempre, e é essa
 * a asserção que distingue o default da alternativa.
 * ⚠️ A foto "de onde se abriu" **não existe mais na tela**: era da oferta A.
 */
export function aoFecharOLightbox(pedido = null) {
    const alvo = objetoDoPainel(pedido);
    const painelAtual = alvo === null ? null : objetoDoPainel(alvo.painelAtual);
    const fotos = painelAtual === null ? null : objetoDoPainel(painelAtual.fotos);
    const temImagem = fotos !== null && Array.isArray(fotos.imagens) && fotos.imagens.length > 0;

    return {
        // ⚠️ Em NENHUM dos dois ramos o fechamento mexe na foto selecionada.
        mudarFotoDoPainel: false,
        focar: temImagem ? FOCO_NO_BOTAO_DE_ABRIR : null,
        // ⚠️ O default NUNCA volta o painel para a oferta do lightbox.
        voltarParaAOferta: null,
    };
}

// ═══════════════════════════════════════════════════════════════════════════
// ⚠️⚠️ Fase 189, plano 07 — AS AÇÕES DA FOTO **COM O CONTEXTO DO LIGHTBOX**
// ═══════════════════════════════════════════════════════════════════════════
//
// ⚠️⚠️ **PERGUNTA-189-B (b), decisão do usuário de 19/10/2026.** Com o lightbox
// aberto, ↑/↓ continuam navegando a fila por baixo. ➡️ Aprovar a foto e reprovar
// usam a oferta, a VERSÃO e a foto **que o lightbox capturou na abertura**, nunca
// as do painel por baixo. Sem isso, quem abre na oferta A e desce para a B
// aprovaria a foto da A **com a versão da B**: no melhor caso o servidor recusa
// (422 de `versao_esperada`); no pior, uma regra de versão mal casada passa — e
// **aprovar ENFILEIRA, sem desfazer** (T-189-50/T-189-51).
//
// ⚠️⚠️ **DIVERGÊNCIA DECLARADA DA PROSA DO PLANO 189-07, e a MEDIÇÃO É A RAZÃO.**
// O plano prescrevia que `acaoDoLightbox` montasse ela mesma
// `{rota, parametros, corpo, versao}` e entregasse a `escreverNoPainel`. ⚠️ Medido
// (`189-CONTRATO-188.md` INV-03 / ACHADO A6): `escritasDaColuna({empresaId,
// ofertaId, versao, onConflito})` — named export de `ColunaDeFotos.jsx:209` — já
// recebe os três **POR PARÂMETRO** e já monta a rota `ROTA_APROVAR_IMAGEM` do
// 188-05 com `{company, oferta, imagem}`. ➡️ Remontar a rota aqui seria a
// **SEGUNDA CÓPIA** da mesma verdade, que a régua §2 proíbe sem teste de
// igualdade — e o plano autoriza literalmente o caminho medido: *"Se o INV-03
// mediu que o handler do painel JÁ recebe `{oferta, versao, imagem}` por
// parâmetro, use-o passando o contexto — e declare"*. ⚠️ A escrita continua
// passando pelo **helper único** `escreverNoPainel` (é ele que põe
// `versao_esperada` SEMPRE e não engole o 422), e **nenhuma rota nova nasce**.
// ➡️ O que esta camada faz é o que só ela pode fazer: **LER a oferta, a versão e
// a foto DO CONTEXTO** e entregá-las ao caminho de escrita que já existe.
//
// ⚠️ **ONDE A LATERAL É MONTADA, e a segunda divergência declarada.** O plano diz
// *"o JSX do painel faz `<LateralDoLightbox {...propsDaLateralDoLightbox(…)} />`"*.
// Medido pelo 189-06: o lightbox (e a lateral dele) são montados **na PÁGINA**,
// porque a coluna que hospeda o botão de abrir chega a este painel como **NÓ
// PRONTO** pelo slot `colunaDeFotos`. ➡️ A FUNÇÃO vive aqui (a casa das regras
// puras, ao lado do resolvedor de atalho, e este painel segue com o ÚNICO
// `keydown`); o JSX que a espalha vive em `Pages/Mlb/Publicador/Aprovacao.jsx`.

/** Os DOIS tipos de ação do lightbox. ⚠️ Lista FECHADA: o resto não escreve. */
export const TIPOS_DA_ACAO_DO_LIGHTBOX = {
    APROVAR: 'aprovar',
    REPROVAR: 'reprovar',
};

/**
 * ⚠️⚠️ A FONTE DA OFERTA, DA VERSÃO E DA FOTO É O CONTEXTO — PERGUNTA-189-B (b).
 *
 * @param {object} [pedido]
 * @param {unknown} [pedido.tipo] `'aprovar'` ou `'reprovar'` (lista FECHADA)
 * @param {unknown} [pedido.contexto] o contexto do lightbox (`contextoDoLightbox`)
 * @param {unknown} [pedido.indice] o índice da foto ABERTA no lightbox
 * @param {unknown} [pedido.escrever] `({tipo, oferta, versao, imagem, imagemId}) =>
 *   boolean` — no componente é o adaptador que chama `escritasDaColuna` com a
 *   oferta e a versão **recebidas aqui**, e daí o helper único `escreverNoPainel`
 * @returns {boolean} se a ação foi disparada
 *
 * ⚠️⚠️ **NENHUMA ESCRITA COM `undefined`.** Contexto nulo, contexto sem oferta ou
 * sem versão, `indice` fora da lista, imagem que não é objeto comum e imagem sem
 * `id` positivo → **nada é chamado** e a função devolve `false`. Numa tela em que
 * aprovar enfileira e não há como voltar atrás, escrita disparada sobre id
 * inválido é **pior** que nenhuma: a pessoa vê a ação acontecer e nada muda.
 * ⚠️ `idPositivoDoPainel` e não `inteiroDoPainel`: `0` é um id plausível para
 * quem lê e sairia numa rota que não existe.
 * ⚠️ A função recebe **UM** parâmetro e o saneia, em vez de desestruturar:
 * `{a = null} = {}` só cobre `undefined`, **NÃO `null`** — e foi assim que uma
 * função passou a LANÇAR NO CLIQUE na wave 187-06.
 * ⚠️ `=== true` ESTRITO no retorno de `escrever`: um adaptador que devolvesse
 * `undefined` faria o degrau seguinte (o D2) rodar sobre uma escrita que não
 * aconteceu.
 */
export function acaoDoLightbox(pedido = null) {
    const alvo = objetoDoPainel(pedido);
    if (alvo === null) return false;

    const tipo = typeof alvo.tipo === 'string' ? alvo.tipo : null;
    if (tipo !== TIPOS_DA_ACAO_DO_LIGHTBOX.APROVAR && tipo !== TIPOS_DA_ACAO_DO_LIGHTBOX.REPROVAR) return false;

    const escrever = typeof alvo.escrever === 'function' ? alvo.escrever : null;
    if (escrever === null) return false;

    const contexto = objetoDoPainel(alvo.contexto);
    if (contexto === null) return false;

    // ⚠️⚠️ AQUI está a decisão inteira: os dois saem do CONTEXTO, nunca do painel.
    const oferta = idPositivoDoPainel(contexto.oferta_id);
    const versao = idPositivoDoPainel(contexto.versao);
    if (oferta === null || versao === null) return false;

    // ⚠️ `imagens` que não é lista cai em `[]` — `.length` em string mentiria e o
    // índice passaria pela guarda.
    const imagens = Array.isArray(contexto.imagens) ? contexto.imagens : [];
    const indice = inteiroDoPainel(alvo.indice);
    if (indice === null || indice < 0 || indice >= imagens.length) return false;

    const imagem = objetoDoPainel(imagens[indice]);
    if (imagem === null) return false;

    const imagemId = idPositivoDoPainel(imagem.id);
    if (imagemId === null) return false;

    return escrever({ tipo, oferta, versao, imagem, imagemId }) === true;
}

/**
 * ⚠️⚠️ O **D2 da PERGUNTA-189-D** — ABERTA, DEFAULT APLICADO E DECLARADO.
 *
 * @param {unknown} pedido `{contexto, painelAtual}`
 * @returns {{fechar: boolean, recarregarNaOferta: ?number}}
 *
 * ➡️ **O caso:** a pessoa abriu o lightbox na oferta **A**, navegou ↓ para a **B**
 * e agiu na foto da A. O recarregamento parcial do Inertia traz o painel **da B**,
 * e o contexto da A no lightbox fica **VELHO**: a foto que ela acabou de aprovar
 * continuaria aparecendo "A conferir", **com os botões**, e um segundo clique
 * tentaria aprovar o que já está aprovado.
 *
 * ➡️ **DEFAULT APLICADO:** o lightbox **FECHA** depois da ação quando a oferta do
 * contexto difere da do painel (o foco segue a regra do D1, `aoFecharOLightbox`).
 * Com a **MESMA** oferta ele continua aberto, e o contexto se refresca sozinho
 * (`contextoDoLightbox`) — é assim que a foto aprovada aparece aprovada.
 *
 * ⚠️⚠️ **A ALTERNATIVA NÃO FOI IMPLEMENTADA, de propósito:** recarregar o painel
 * na oferta A (um `router.get` com `?oferta=A`) e manter o lightbox aberto,
 * atualizado. Ela **move o painel por baixo da pessoa** sem ela ter pedido, e
 * desfaz a navegação ↑/↓ que foi ação explícita dela. ➡️ `recarregarNaOferta` sai
 * **`null` sempre**, e é essa a asserção que distingue o default da alternativa —
 * o mesmo contrato do `voltarParaAOferta` do D1.
 *
 * ⚠️ Sem oferta no contexto ou sem oferta no payload **não há o que comparar** e o
 * lightbox **não fecha**: fechar por dado faltando tiraria a pessoa da tela sem
 * motivo, e o clamp do índice já resolve a galeria que esvaziou.
 * ⚠️ Comparação ESTRITA de inteiros: `'501'` é OUTRA oferta, nunca "a mesma".
 */
export function depoisDaAcaoNoLightbox(pedido = null) {
    const alvo = objetoDoPainel(pedido);
    const contexto = alvo === null ? null : objetoDoPainel(alvo.contexto);
    const painelAtual = alvo === null ? null : objetoDoPainel(alvo.painelAtual);

    const doLightbox = contexto === null ? null : idPositivoDoPainel(contexto.oferta_id);
    const doPainel = painelAtual === null ? null : idPositivoDoPainel(painelAtual.oferta_id);

    return {
        fechar: doLightbox !== null && doPainel !== null && doLightbox !== doPainel,
        recarregarNaOferta: null,
    };
}

/**
 * ⚠️⚠️ **A ÚNICA FONTE DAS PROPS DA LATERAL DO LIGHTBOX — e por que ela existe.**
 *
 * @param {object} [pedido]
 * @param {unknown} [pedido.contexto] o contexto do lightbox
 * @param {unknown} [pedido.indice] o índice da foto aberta
 * @param {unknown} [pedido.zoom] o zoom atual (a lateral o usa no rótulo)
 * @param {unknown} [pedido.onFechar] `() => void`
 * @param {unknown} [pedido.onAlternarZoom] `(zoomNovo) => void`
 * @param {unknown} [pedido.escrever] o adaptador de escrita (ver `acaoDoLightbox`)
 * @returns {object} as props de `<LateralDoLightbox>`, prontas
 *
 * ⚠️⚠️ **O ACHADO DO PLAN-CHECKER DE 19/10/2026 QUE ESTA FUNÇÃO FECHA.** O 189-06
 * montou a lateral com os dois handlers de ação em `null`, e a `podeAgirNaFoto`
 * (189-05) **só desenha com handler FUNÇÃO**. Se este plano apenas provasse as
 * funções puras, **todo teste passaria e o botão nunca nasceria**. ⚠️ E o lightbox
 * é **portal**: o renderizador de SERVIDOR do React **não o desenha**, então um
 * teste do composto passaria **por OMISSÃO**. ➡️ Por isso o fio atravessa uma
 * função **exportada**, que o gate renderiza direto com a lateral de verdade.
 *
 * ⚠️⚠️ **ESTA FUNÇÃO NÃO REPETE A REGRA DE "PODE AGIR".** Quem decide se o botão
 * nasce é `podeAgirNaFoto` (`ao_vivo` **e** `pending` **e** `aceita_acao` **e** os
 * dois handlers função) — **uma regra só**, no arquivo do 189-05. Aqui se decide
 * outra coisa: **se existe DESTINO** (contexto com oferta e versão, foto com id,
 * adaptador de escrita). Duplicar `estado`/`aceita_acao` aqui seria a segunda
 * verdade que a régua §2 proíbe, e a divergente deixaria a tela oferecendo o que
 * o servidor recusa.
 *
 * ⚠️ Os handlers **IGNORAM o argumento** que a lateral lhes passa e leem a foto do
 * CONTEXTO pelo índice. A lateral passa a imagem que ESTÁ desenhando — que é a
 * mesma —, mas confiar no argumento tornaria o nó desenhado a fonte da verdade, e
 * é justamente a fonte da verdade que a PERGUNTA-189-B decide.
 * ⚠️ `originais` é indexado pelo **`id` da revisão** (`pub_aprovacao_imagens.id`),
 * **não** por `imagem_id`: são tabelas diferentes e confundi-las mostraria a
 * original de OUTRA foto.
 */
export function propsDaLateralDoLightbox(pedido = null) {
    const alvo = objetoDoPainel(pedido);
    const contexto = alvo === null ? null : objetoDoPainel(alvo.contexto);

    const imagens = contexto !== null && Array.isArray(contexto.imagens) ? contexto.imagens : [];
    const indice = alvo === null ? null : inteiroDoPainel(alvo.indice);
    const imagem = indice === null || indice < 0 || indice >= imagens.length
        ? null
        : objetoDoPainel(imagens[indice]);

    const originais = contexto === null ? null : objetoDoPainel(contexto.originais);
    const idDaRevisao = imagem === null ? null : idPositivoDoPainel(imagem.id);
    const original = originais === null || idDaRevisao === null
        ? null
        : objetoDoPainel(originais[idDaRevisao]);

    const escrever = alvo !== null && typeof alvo.escrever === 'function' ? alvo.escrever : null;
    const oferta = contexto === null ? null : idPositivoDoPainel(contexto.oferta_id);
    const versao = contexto === null ? null : idPositivoDoPainel(contexto.versao);

    // ⚠️ TEM DESTINO = há para onde escrever e o que escrever. O resto é da
    // `podeAgirNaFoto`, e é lá que fica.
    const temDestino = escrever !== null && oferta !== null && versao !== null && idDaRevisao !== null;

    return {
        imagem,
        original,
        // ⚠️ `ao_vivo` é o do CONTEXTO (capturado na abertura, refrescado só na
        // mesma oferta) — nunca o `rodape.botoes.salvar` do painel de baixo.
        aoVivo: contexto === null ? null : contexto.ao_vivo,
        zoom: alvo === null ? null : alvo.zoom,
        onFechar: alvo !== null && typeof alvo.onFechar === 'function' ? alvo.onFechar : null,
        onAlternarZoom: alvo !== null && typeof alvo.onAlternarZoom === 'function' ? alvo.onAlternarZoom : null,
        onAprovarFoto: !temDestino
            ? null
            : () => acaoDoLightbox({ tipo: TIPOS_DA_ACAO_DO_LIGHTBOX.APROVAR, contexto, indice, escrever }),
        onReprovar: !temDestino
            ? null
            : () => acaoDoLightbox({ tipo: TIPOS_DA_ACAO_DO_LIGHTBOX.REPROVAR, contexto, indice, escrever }),
    };
}

/**
 * ⚠️⚠️ **A RAIZ DO QUE É PORTADO — e é ELA que os gates conferem.**
 *
 * O renderizador de SERVIDOR do React não desenha portal, então um teste do
 * composto `PainelDeRevisao` veria HTML vazio e passaria **por omissão**. ➡️ Esta
 * peça é **named export** e é renderizada **direto** pelos gates.
 *
 * ⚠️ `font-pub` vive em `CLASSES_PAINEL`, que é a raiz do conteúdo portado
 * (REND-05). O backdrop não tem texto nenhum, então não precisa dela.
 */
export function RaizDoPainel({
    navegacao = null,
    identificacao = null,
    falha = null,
    onIr = null,
    onFechar = null,
    onTentarDeNovo = null,
    colunaDeFotos = null,
    colunaDePreco = null,
    aviso = null,
    pendencias = null,
    rodape = null,
    modais = null,
    children = null,
}) {
    // ⚠️⚠️ O corpo rolável de `:562` só existe COM CONTEÚDO, e a decisão é tomada
    // **ANTES** de desenhar a casca: um componente que devolve `null` por dentro
    // **já desenhou a casca por fora**, e bloco de 18px de padding vazio é D4
    // violado. É por isso que `temIdentificacao()` é named export.
    const temIdentidade = temIdentificacao(identificacao, falha);
    const temColuna = filhosSeguros(colunaDeFotos) !== null || filhosSeguros(colunaDePreco) !== null;
    const extra = filhosSeguros(children);

    // ═══ ⚠️⚠️ Fase 188, plano 10 — DOIS SLOTS, E ELES NÃO SÃO IRMÃOS ══════
    //
    // ⚠️⚠️ **DIVERGÊNCIA DECLARADA DO `@param children` ESCRITO NA 188-07**, que
    // dizia *"o resto do corpo (pendências e rodapé, plano 188-10)"*. **MEDIDO
    // por script no protótipo:** `:562` (o corpo rolável) abre na profundidade
    // 2→3 e **`:710` o FECHA**; `:699` (as Pendências) abre em 3→4, ou seja
    // **DENTRO** do corpo, irmão do wrapper das colunas (`:586`, fechado por
    // `:697`); e **`:712` (o rodapé) abre em 2→3 de novo**, ou seja **IRMÃO do
    // corpo**, filho direto do `<aside>` que `:723` fecha.
    //
    // ➡️ **O rodapé NÃO pode entrar no `children`:** o corpo é
    // `flex-1 overflow-y-auto`, e lá dentro o rodapé **ROLARIA PARA FORA DA
    // TELA** — `flex:none` não o salvaria, porque ele seria item de um contêiner
    // que rola, não do `<aside>`. É o oposto do critério 10, que o quer FIXO no
    // pé dos 1140px. ⚠️ Com todo teste de render passando.
    const noPendencias = filhosSeguros(pendencias);
    const noRodape = filhosSeguros(rodape);

    // ═══ ⚠️⚠️ Fase 188, plano 11 — O TERCEIRO SLOT: `modais` ═════════════
    //
    // ⚠️⚠️ **ELE É IRMÃO DO `<aside>`, NÃO FILHO — e isso é a MESMA classe de erro
    // que fez o rodapé ganhar slot próprio na 188-10.** Medido na mesma contagem
    // de profundidade de tag do protótipo: `:775` (o backdrop do modal) abre **na
    // raiz da página**, FORA do `<aside>` de `:550`-`:723`, e com `position:fixed`
    // + `z-index` acima do painel.
    // ➡️ Dentro do `children` ou do slot `pendencias` o modal seria item do
    // contêiner `overflow-y-auto` de `:562` e **ROLARIA COM O CORPO**; dentro do
    // `<aside>` ele ficaria limitado aos 1140px do painel em vez de cobrir a tela.
    // ⚠️ **E as duas coisas aconteceriam com TODO teste de render passando** —
    // exatamente como o rodapé na wave anterior.
    // ⚠️ Aviso de outra sessão, CONFIRMADO aqui: o `ModalDeReprovarImagem` (plano
    // 188-12) tem de ser **IRMÃO** do `<aside>`, não filho. Este slot é a casa dos
    // quatro modais do painel.
    //
    // ⚠️ O Z-INDEX continua sendo o da casca (`CascaDeModal.jsx:54`, `z-[42]`), e
    // a ORDEM TRAVADA segue preservada:
    //   header(30) < backdrop do painel(39) < painel(40) < modal(42) < toast(45).
    // **Nada é remapeado aqui.**
    const noModais = filhosSeguros(modais);

    // ═══ ⚠️⚠️ Fase 188, plano 12 — O QUARTO SLOT: `aviso` (COL-04) ════════
    //
    // ⚠️ Ele é o **PRIMEIRO filho do corpo rolável**, ANTES da identificação: um
    // aviso de concorrência que aparece depois de duas colunas e de uma seção de
    // pendências é um aviso que a pessoa lê **depois de clicar**.
    // ⚠️ **Dentro do corpo, e não irmão do `<aside>`:** ele rola com o conteúdo de
    // propósito — ele não é modal, não cobre a tela e não bloqueia o resto da
    // revisão; o que ele bloqueia é **a ação que falhou** (quem recusa é o
    // servidor, pelo `versao_esperada`).
    const noAviso = filhosSeguros(aviso);

    const corpo = temIdentidade || temColuna || noAviso !== null || noPendencias !== null || extra !== null
        ? (
            <CorpoDoPainel>
                {[
                    noAviso,
                    temIdentidade
                        ? <IdentificacaoDaOferta key="identificacao" identificacao={identificacao} falha={falha} onTentarDeNovo={onTentarDeNovo} />
                        : null,
                    temColuna
                        ? <ColunasDoPainel key="colunas" colunaDeFotos={colunaDeFotos} colunaDePreco={colunaDePreco} />
                        : null,
                    noPendencias,
                    extra,
                ]}
            </CorpoDoPainel>
        )
        : null;

    return (
        <>
            <BackdropDoPainel onFechar={onFechar} />
            <aside className={CLASSES_PAINEL}>
                <TopoDoPainel navegacao={navegacao} onIr={onIr} onFechar={onFechar} />
                {corpo}
                {noRodape}
            </aside>
            {/* ⚠️⚠️ IRMÃO do `<aside>`, e o motivo está no bloco acima. */}
            {noModais}
        </>
    );
}

/**
 * O painel de revisão simples, **POR PORTAL** para o `document.body`.
 *
 * @param {object} props
 * @param {unknown} [props.aberto] só `true` desenha — ver abaixo
 * @param {unknown} [props.painel] o payload de `PainelDeRevisaoService::para()`
 * @param {unknown} [props.onIr] `(id) => void` — a navegação ↑/↓
 * @param {unknown} [props.onFechar] `() => void` — Esc, clique fora e o ×
 * @param {unknown} [props.temModal] se algum modal está aberto (planos 188-11/12)
 * @param {unknown} [props.imagemAtual] o índice da imagem selecionada (coluna de fotos, 188-08)
 * @param {unknown} [props.totalDeImagens] quantas imagens há na galeria
 * @param {unknown} [props.onFecharModal] `() => void` — o `Esc` com modal aberto
 * @param {unknown} [props.onTrocarImagem] `(indice) => void` — as setas ←/→
 * @param {unknown} [props.temLightbox] se o LIGHTBOX está aberto (Fase 189, plano
 *   06). ⚠️⚠️ Este painel tem o **ÚNICO** `keydown` da tela: dois ouvintes
 *   fechariam lightbox **e** painel no mesmo `Escape`
 * @param {unknown} [props.imagemDoLightbox] o índice aberto no lightbox — do
 *   CONTEXTO dele (PERGUNTA-189-B (b)), **não** o do painel
 * @param {unknown} [props.totalDoLightbox] quantas imagens há no contexto do
 *   lightbox (pode ser outra oferta que a do painel por baixo)
 * @param {unknown} [props.onFecharLightbox] `() => void` — o degrau do `Escape`
 * @param {unknown} [props.onMoverLightbox] `(indice) => void` — as setas ←/→ do
 *   lightbox, que **DÃO A VOLTA** (e as do painel CLAMPAM: são regras diferentes
 *   de propósito, e o protótipo ganha)
 * @param {unknown} [props.onTentarDeNovo] `() => void`, ou `null` (o normal hoje:
 *   a rota de `tentarDeNovo` **não existe** — ver `IdentificacaoDaOferta.jsx`)
 * @param {unknown} [props.colunaDeFotos] SLOT de NÓ — a coluna de fotos (plano 188-08)
 * @param {unknown} [props.colunaDePreco] SLOT de NÓ — a coluna de preço (plano 188-09)
 * @param {unknown} [props.aviso] SLOT de NÓ — o aviso de concorrência (COL-04,
 *   plano 188-12). ⚠️ Ele é o **PRIMEIRO filho do corpo rolável**: aviso que
 *   aparece depois de duas colunas é aviso lido DEPOIS do clique
 * @param {unknown} [props.pendencias] SLOT de NÓ — a seção de Pendências de
 *   `:699` (plano 188-10). ⚠️ Ela vive **DENTRO** do corpo rolável, irmã do
 *   wrapper das colunas — medido: `:699` abre dentro de `:562`
 * @param {unknown} [props.rodape] SLOT de NÓ — o rodapé FIXO de 66px de `:712`
 *   (plano 188-10). ⚠️⚠️ Ele é **IRMÃO do corpo**, não filho: dentro do corpo
 *   (`overflow-y-auto`) ele ROLARIA PARA FORA DA TELA, e `flex:none` não o
 *   salvaria. Medido por script no protótipo — ver o bloco em `RaizDoPainel`
 * @param {unknown} [props.modais] SLOT de NÓ — os modais do painel (planos
 *   188-11 e 188-12). ⚠️⚠️ Eles são **IRMÃOS do `<aside>`**, não filhos: no
 *   protótipo o backdrop do modal (`:775`) abre **na raiz da página**, fora do
 *   `<aside>` de `:550`. Dentro do corpo eles ROLARIAM; dentro do `<aside>`
 *   ficariam limitados aos 1140px. **As duas coisas com todo teste de render
 *   passando** — é a mesma classe de erro do rodapé na 188-10
 * @param {unknown} [props.children] o resto do corpo rolável, depois das
 *   pendências. ⚠️ A redação da 188-07 dizia *"pendências e rodapé"* aqui, e o
 *   rodapé **saiu** para o slot próprio pela razão de geometria acima
 *
 * ⚠️ `aberto !== true` devolve `null`: **nada no DOM, nenhum backdrop fantasma**.
 * Backdrop invisível capturaria o clique da tela inteira sem ninguém ver por quê.
 * ⚠️ E `painel` ausente também não desenha: painel sem oferta não tem o que
 * revisar, e casca vazia de 1140px é D4 violado.
 */
export default function PainelDeRevisao({
    aberto = false,
    painel = null,
    temModal = false,
    imagemAtual = 0,
    totalDeImagens = 0,
    temLightbox = false,
    imagemDoLightbox = 0,
    totalDoLightbox = 0,
    onIr = null,
    onFechar = null,
    onFecharModal = null,
    onTrocarImagem = null,
    onFecharLightbox = null,
    onMoverLightbox = null,
    onTentarDeNovo = null,
    colunaDeFotos = null,
    colunaDePreco = null,
    aviso = null,
    pendencias = null,
    rodape = null,
    modais = null,
    children = null,
}) {
    const dados = objetoDoPainel(painel);
    const navegacao = objetoDoPainel(dados === null ? null : dados.navegacao);
    const estaAberto = aberto === true && dados !== null;

    // ═══ ⚠️⚠️ OS ATALHOS — e o que está PROVADO deles ═══════════════
    //
    // ⚠️⚠️ **O `addEventListener` abaixo NÃO ESTÁ PROVADO: não há navegador
    // nesta máquina.** O que os gates provam é `resolverAtalhoDoPainel()`, que é
    // **função pura** e decide o que cada tecla faz. Esta fase **não pode afirmar
    // "a tecla funciona"** — só "a função que decide está certa".
    //
    // ⚠️ O `useEffect` roda antes das guardas de `aberto`/`document` de propósito:
    // hook atrás de `return` condicional viola as regras dos hooks. A guarda de
    // "está aberto?" mora DENTRO do efeito.
    useEffect(() => {
        if (!estaAberto || typeof document === 'undefined') return undefined;

        const aoTeclar = (evento) => {
            // ⚠️ Flag DENTRO do callback.
            const alvo = evento === null || typeof evento !== 'object' ? null : evento.target;
            const tag = alvo === null || typeof alvo !== 'object' ? '' : alvo.tagName;

            const decisao = resolverAtalhoDoPainel({
                tecla: evento?.key,
                temModal: temModal === true,
                // ⚠️ `e.target.tagName` pode vir `undefined` — o próprio protótipo
                // usa `(e.target.tagName || '')` em `:1013`.
                alvoEhCampo: TAGS_DE_CAMPO.includes(typeof tag === 'string' ? tag : ''),
                anteriorId: navegacao === null ? null : navegacao.anterior_id,
                proximaId: navegacao === null ? null : navegacao.proxima_id,
                imagemAtual,
                totalDeImagens,
                // ⚠️ Fase 189, plano 06 — o degrau. O estado mora na PÁGINA (ver
                // o bloco do contexto acima); aqui só chega o booleano e os dois
                // números do CONTEXTO do lightbox.
                temLightbox: temLightbox === true,
                imagemDoLightbox,
                totalDoLightbox,
            });

            // ⚠️ `null` = a tecla **não é desta peça**: nada de `preventDefault`,
            // que tiraria a rolagem da pessoa sem motivo.
            if (decisao === null) return;

            evento.preventDefault?.();

            if (decisao.acao === ACOES.FECHAR_MODAL && typeof onFecharModal === 'function') onFecharModal();
            else if (decisao.acao === ACOES.FECHAR_LIGHTBOX && typeof onFecharLightbox === 'function') onFecharLightbox();
            else if (decisao.acao === ACOES.MOVER_LIGHTBOX && typeof onMoverLightbox === 'function') onMoverLightbox(decisao.imagem);
            else if (decisao.acao === ACOES.FECHAR_PAINEL && typeof onFechar === 'function') onFechar();
            else if (decisao.acao === ACOES.IR_PARA_OFERTA && typeof onIr === 'function') onIr(decisao.oferta);
            else if (decisao.acao === ACOES.TROCAR_IMAGEM && typeof onTrocarImagem === 'function') onTrocarImagem(decisao.imagem);
        };

        window.addEventListener('keydown', aoTeclar);

        return () => window.removeEventListener('keydown', aoTeclar);
    });

    if (!estaAberto) return null;

    // ⚠️⚠️ A GUARDA DE AMBIENTE. Sem ela o render de SERVIDOR **LANÇA**
    // (`document is not defined`); com ela devolve `null`, e é por isso que **todas
    // as peças internas são named exports** — é por elas que os gates medem.
    // ⚠️ `typeof document === 'undefined'` e não `!document`: a segunda forma
    // LANÇA no mesmo lugar que se quer evitar.
    if (typeof document === 'undefined') return null;

    return createPortal(
        <RaizDoPainel
            navegacao={navegacao}
            identificacao={dados.identificacao ?? null}
            falha={dados.falha ?? null}
            onIr={onIr}
            onFechar={onFechar}
            onTentarDeNovo={onTentarDeNovo}
            colunaDeFotos={colunaDeFotos}
            colunaDePreco={colunaDePreco}
            aviso={aviso}
            pendencias={pendencias}
            rodape={rodape}
            modais={modais}
        >
            {children}
        </RaizDoPainel>,
        document.body,
    );
}
