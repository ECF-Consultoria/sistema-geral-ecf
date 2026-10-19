import { isValidElement, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import TopoDoPainel from './TopoDoPainel';
import IdentificacaoDaOferta, { temIdentificacao } from './IdentificacaoDaOferta';

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

/** Objeto comum de verdade, ou `null`. ⚠️ `null` e array são os dois `typeof 'object'`. */
export function objetoDoPainel(valor) {
    return valor !== null && typeof valor === 'object' && !Array.isArray(valor) ? valor : null;
}

/** Inteiro finito, ou `null`. ⚠️ Nunca `Number(valor)`: `Number('')` é **0**. */
export function inteiroDoPainel(valor) {
    return typeof valor === 'number' && Number.isInteger(valor) ? valor : null;
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
 * @param {unknown} [pedido.onConflito] `(mensagem) => void`, chamado no 422 de versão
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

    const corpo = objetoDoPainel(alvo.corpo) ?? {};
    const parametros = objetoDoPainel(alvo.parametros) ?? {};
    const aoConflitar = typeof alvo.onConflito === 'function' ? alvo.onConflito : null;

    router.post(
        route(nomeDaRota, parametros),
        // ⚠️⚠️ A versão entra DEPOIS do espalhamento, de propósito: um `corpo` que
        // trouxesse `versao_esperada` por engano **não pode** vencer a versão que a
        // tela viu. É a única fonte dela.
        { ...corpo, [CHAVE_DA_VERSAO]: versaoVista },
        {
            preserveScroll: true,
            onError: (erros) => {
                if (aoConflitar === null) return;

                // ⚠️ A mensagem é a do SERVIDOR (ela diz as DUAS versões). Aqui só
                // se procura a regra; o texto nunca é remontado no cliente.
                const sacola = objetoDoPainel(erros) ?? {};
                const mensagens = Object.keys(sacola)
                    .map((chave) => sacola[chave])
                    .filter((texto) => typeof texto === 'string' && texto.trim() !== '');

                const conflito = mensagens.find((texto) => texto.includes(REGRA_VERSAO_DIVERGENTE));

                // ⚠️ `onConflito` é chamado com a mensagem do conflito quando ela
                // vier, e com a PRIMEIRA mensagem quando não vier — **nunca em
                // silêncio**, porque o pior resultado aqui é a pessoa achar que
                // aprovou.
                aoConflitar(conflito ?? mensagens[0] ?? null);
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
// ⚠️ A ORDEM do `Escape` é LITERAL do protótipo (`:1014`, medido por `sed`):
// `modal → lightbox → menu → painel`. ➡️ **O lightbox é da Fase 189 e o menu é da
// 187**, então aqui a cadeia é `modal → painel` — **com o degrau da 189 PREVISTO
// e DECLARADO**, nunca "esquecido". **Não antecipar o lightbox.**
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
};

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
 * @returns {{acao: string, oferta: ?number, imagem: ?number}|null} `null` = a
 *   tecla não é desta peça e **não é consumida** (nada de `preventDefault`).
 */
export function resolverAtalhoDoPainel(evento = null) {
    const e = objetoDoPainel(evento);
    if (e === null) return null;

    const tecla = typeof e.tecla === 'string' ? e.tecla : null;
    if (tecla === null) return null;

    const temModal = e.temModal === true;

    // ⚠️⚠️ `Escape` PRIMEIRO, e **ANTES da guarda de campo** — é a ordem medida em
    // `:1014`/`:1015`. A cadeia é `modal → painel`, com o degrau do lightbox da
    // Fase 189 declarado no bloco acima e NÃO antecipado.
    if (tecla === TECLAS.FECHAR) {
        return { acao: temModal ? ACOES.FECHAR_MODAL : ACOES.FECHAR_PAINEL, oferta: null, imagem: null };
    }

    // ⚠️ Daqui para baixo, campo de texto engole a tecla (`:1015`). Sem isso,
    // digitar "a seta" numa busca navegaria a fila por baixo de quem digita.
    if (e.alvoEhCampo === true) return null;

    // ⚠️ Com modal aberto, `↑ ↓ ← →` não fazem nada (`:1017`/`:1018` exigem
    // `!S.modal`): navegar a fila por trás de um modal aberto trocaria a oferta
    // que o modal está confirmando.
    if (temModal) return null;

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
    children = null,
}) {
    // ⚠️⚠️ O corpo rolável de `:562` só existe COM CONTEÚDO, e a decisão é tomada
    // **ANTES** de desenhar a casca: um componente que devolve `null` por dentro
    // **já desenhou a casca por fora**, e bloco de 18px de padding vazio é D4
    // violado. É por isso que `temIdentificacao()` é named export.
    const temIdentidade = temIdentificacao(identificacao, falha);
    const temColuna = filhosSeguros(colunaDeFotos) !== null || filhosSeguros(colunaDePreco) !== null;
    const extra = filhosSeguros(children);

    const corpo = temIdentidade || temColuna || extra !== null
        ? (
            <CorpoDoPainel>
                {[
                    temIdentidade
                        ? <IdentificacaoDaOferta key="identificacao" identificacao={identificacao} falha={falha} onTentarDeNovo={onTentarDeNovo} />
                        : null,
                    temColuna
                        ? <ColunasDoPainel key="colunas" colunaDeFotos={colunaDeFotos} colunaDePreco={colunaDePreco} />
                        : null,
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
            </aside>
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
 * @param {unknown} [props.onTentarDeNovo] `() => void`, ou `null` (o normal hoje:
 *   a rota de `tentarDeNovo` **não existe** — ver `IdentificacaoDaOferta.jsx`)
 * @param {unknown} [props.colunaDeFotos] SLOT de NÓ — a coluna de fotos (plano 188-08)
 * @param {unknown} [props.colunaDePreco] SLOT de NÓ — a coluna de preço (plano 188-09)
 * @param {unknown} [props.children] o resto do corpo (pendências e rodapé, plano 188-10)
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
    onIr = null,
    onFechar = null,
    onFecharModal = null,
    onTrocarImagem = null,
    onTentarDeNovo = null,
    colunaDeFotos = null,
    colunaDePreco = null,
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
            });

            // ⚠️ `null` = a tecla **não é desta peça**: nada de `preventDefault`,
            // que tiraria a rolagem da pessoa sem motivo.
            if (decisao === null) return;

            evento.preventDefault?.();

            if (decisao.acao === ACOES.FECHAR_MODAL && typeof onFecharModal === 'function') onFecharModal();
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
        >
            {children}
        </RaizDoPainel>,
        document.body,
    );
}
