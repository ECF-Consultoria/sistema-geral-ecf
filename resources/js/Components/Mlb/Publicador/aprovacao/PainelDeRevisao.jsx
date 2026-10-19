import { isValidElement } from 'react';
import { createPortal } from 'react-dom';
import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import TopoDoPainel from './TopoDoPainel';

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
    onIr = null,
    onFechar = null,
    children = null,
}) {
    return (
        <>
            <BackdropDoPainel onFechar={onFechar} />
            <aside className={CLASSES_PAINEL}>
                <TopoDoPainel navegacao={navegacao} onIr={onIr} onFechar={onFechar} />
                {filhosSeguros(children)}
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
 * @param {unknown} [props.children] o corpo (identificação, colunas, rodapé)
 *
 * ⚠️ `aberto !== true` devolve `null`: **nada no DOM, nenhum backdrop fantasma**.
 * Backdrop invisível capturaria o clique da tela inteira sem ninguém ver por quê.
 * ⚠️ E `painel` ausente também não desenha: painel sem oferta não tem o que
 * revisar, e casca vazia de 1140px é D4 violado.
 */
export default function PainelDeRevisao({
    aberto = false,
    painel = null,
    onIr = null,
    onFechar = null,
    children = null,
}) {
    const dados = objetoDoPainel(painel);

    if (aberto !== true || dados === null) return null;

    // ⚠️⚠️ A GUARDA DE AMBIENTE. Sem ela o render de SERVIDOR **LANÇA**
    // (`document is not defined`); com ela devolve `null`, e é por isso que **todas
    // as peças internas são named exports** — é por elas que os gates medem.
    // ⚠️ `typeof document === 'undefined'` e não `!document`: a segunda forma
    // LANÇA no mesmo lugar que se quer evitar.
    if (typeof document === 'undefined') return null;

    return createPortal(
        <RaizDoPainel
            navegacao={dados.navegacao ?? null}
            onIr={onIr}
            onFechar={onFechar}
        >
            {children}
        </RaizDoPainel>,
        document.body,
    );
}
