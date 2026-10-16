import { cn } from '@/lib/utils';
import { iniciais, tomDoProduto } from '../identidade/identidade';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 06 — O POPOVER DE FILTRO: "Responsável" e "Motivo de
// bloqueio" (FILA-05, FILA-06, COL-02, COL-03).
//
// Um componente para os DOIS, porque o protótipo os desenha com a MESMA casca
// e diferença em três coisas só: a largura (220 × 260), o avatar (só no de
// Responsável) e a lista de opções. Dois arquivos criariam duas verdades para a
// mesma geometria.
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas, sha256 `68624883ce35547e`). Linhas MEDIDAS com `grep -n` nesta
// sessão (16/10/2026) — ⚠️ **TREZE dos vinte ponteiros do plano estavam
// deslocados**, e as medidas abaixo são as certas:
//
//   elemento                      plano         MEDIDO
//   botão (Responsável/Motivo)    :79 / :90     :80 / :93     ⚠️
//   popover                       :81 / :92     :82 / :95     ⚠️
//   item                          :83           :84 / :97     ⚠️
//   checkbox                      :84           :85 / :98     ⚠️
//   avatar                        :85           :86           ⚠️
//   contagem do item              :85 / :93     :86 / :98     ⚠️
//   `hint-placeholder-count="6"`  :98           :96           ⚠️
//   `act()` (tint do botão)       :1281         :1268         ⚠️
//   `respOpts` / `blockOpts`      :1277/:1278   :1265/:1266   ⚠️
//
// ⚠️⚠️ **OMISSÃO MEDIDA NA TABELA DO PLANO, e é a classe de erro que mais
// aparece neste projeto:** o botão (`:80`/`:93`) e o item (`:84`/`:97`) declaram
// `white-space:nowrap` — é a **PRIMEIRA** propriedade dos dois —, e as classes
// prescritas no plano **não a têm**. "Motivo de bloqueio · 2" quebraria em duas
// linhas dentro de um botão de 32px fixos. A tabela de fidelidade deste projeto
// não erra VALOR: erra por OMISSÃO e por REFERÊNCIA.
//
// ═══ ⚠️⚠️ O TINT DO BOTÃO É DIFERENTE DO TINT DO SEGMENTADO ════════════════
//
// São dois amarelos rebaixados DIFERENTES, e o protótipo os pinta diferentes:
//   · botão de popover ATIVO (`act()`, `:1268`): borda `rgba(250,204,21,0.55)`
//     + fundo `rgba(250,204,21,0.12)`;
//   · opção ATIVA do segmentado (`seg()`, `:1267`): fundo
//     `rgba(250,204,21,0.16)` e **borda nenhuma**.
// ⚠️ "Deixei igual ao outro" é **FID-02**, justificativa VETADA — e há gate
// declarando os dois LADO A LADO, no mesmo caso.
//
// ═══ ⚠️⚠️ ESTES POPOVERS NÃO PRECISAM DE PORTAL, E ISSO FOI MEDIDO ═════════
//
// Eles são `position:absolute` dentro de um pai `position:relative` da própria
// linha de filtros (`:79` e `:92`), **não** `position:fixed`. O
// `backdrop-filter` do header global cria containing block **só para `fixed`**,
// então ele **não os afeta**. ➡️ Nada de portal aqui, e o
// `renderToStaticMarkup` dos gates enxerga o conteúdo do popover.
// ⚠️ A barra flutuante de lote e os toasts (plano 187-09) **são** `fixed`, e lá
// a análise é outra.
//
// ═══ ⚠️⚠️ O Z-INDEX É REMAPEADO, NUNCA COPIADO (divergência D1) ════════════
//
// O protótipo monta o popover em `z-index:45` (`:82`/`:95`) porque lá o header
// é `z-index:50` (`:26`) — uma escala INCOMPATÍVEL com a deste projeto, onde a
// ordem é **TRAVADA** por `tests/js/publicador-header-zindex-ordem.test.js`
// (`filtros 10 < header 30 < menu lateral = painel 40`), invariante medido do
// bug de 12/10/2026 que o usuário relatou.
//
// ➡️ A ORDEM RELATIVA é preservada e os NÚMEROS são novos:
//   · overlay que fecha o popover  → `z-10` (abaixo do popover, acima das linhas)
//   · popover                      → `z-20` (abaixo do header `z-30`)
// ⚠️ `tests/js/publicador-fila-zindex-187.test.js` LÊ os quatro pontos travados
// dos arquivos deles e prova a ordem completa — sem tocar no arquivo travado.
//
// ═══ ⚠️ NADA DE `localStorage` (COL-02) ════════════════════════════════════
//
// O `KEY='ecf-aprovacao-v1'` do protótipo (`:914`) é artefato dele, e o critério
// 13 o proíbe. **Todo filtro vive na URL** (COL-03): este componente recebe os
// `ativos` por prop e devolve `onToggle`, e quem traduz em `router.get` é a
// página. Nenhum estado de filtro nasce aqui.
//
// ⚠️ TODA classe mora NESTE `.jsx`: o `content` do `tailwind.config.js` varre
// `./resources/js/**/*.jsx` e **NÃO** `*.js`. Classe escrita num `.js` não gera
// CSS e NÃO EXISTE PARA A TELA, com todo teste de render passando.
//
// ⚠️ NUNCA `String(valor)` nem `JSON.stringify(valor)` sobre prop: o
// `String({toString: null})` LANÇA e derruba a página inteira (a TELA PRETA de
// 07/10/2026, que voltou QUATRO vezes na Fase 180). Todo campo passa por guarda
// de TIPO antes de chegar ao JSX.
//
// ⚠️ **Toda flag DENTRO do callback do `.map()`**: o Rollup elimina variável de
// escopo do componente usada dentro do `.map()` (medido neste projeto), e aqui
// são até 8 itens mapeados por popover.
// ═══════════════════════════════════════════════════════════════════════════

/** O glifo do checkbox marcado, byte a byte como `:1265`/`:1266` o escrevem. */
export const GLIFO_MARCADO = '✓';

/** O caret do botão, byte a byte como `:80`/`:93` o escrevem. */
export const CARET = '▾';

/** O prefixo do sufixo de contagem do botão (`respLabel`/`blockLabel`, `:1275`). */
export const PREFIXO_DO_SUFIXO = '· ';

/**
 * Objeto comum de verdade, ou `null`.
 *
 * ⚠️ `null` e array são os dois `typeof 'object'` que precisam de guarda
 * própria: sem elas, um item chegando `[]` passaria por `typeof` e cada
 * `.campo` daria `undefined`, que é o caminho do `undefined` na tela.
 */
function objetoComum(valor) {
    return valor !== null && typeof valor === 'object' && !Array.isArray(valor) ? valor : null;
}

/**
 * Texto não-vazio, ou `null`.
 *
 * ⚠️ `typeof` ANTES de qualquer coisa, e nunca `String(valor)`: `String(NaN)`
 * devolveria a STRING `'NaN'`, que iria para a tela.
 */
function textoNaoVazio(valor) {
    if (typeof valor !== 'string') return null;

    return valor.trim() === '' ? null : valor;
}

/**
 * Inteiro finito NÃO-NEGATIVO, ou `null`.
 *
 * ⚠️ NUNCA `Number(valor)`: `Number('')` e `Number(null)` são 0, e uma contagem
 * zero INVENTADA é pior que a ausência dela — zero MEDIDO é fato, zero inventado
 * é mentira. `Number.isInteger` já recusa `NaN`, `Infinity` e `1.5`.
 */
function inteiroNaoNegativo(valor) {
    return typeof valor === 'number' && Number.isInteger(valor) && valor >= 0 ? valor : null;
}

/** A lista de verdade, ou `[]`. Prop não-array nunca chega ao `.map()`. */
function listaSegura(valor) {
    return Array.isArray(valor) ? valor : [];
}

// As classes, escritas uma vez e literalmente — é daqui que o Tailwind as lê.

/** `:79`/`:92` — o pai `position:relative` que ancora o popover `absolute`. */
const CLASSES_ANCORA = 'relative';

/** `:80`/`:93` — `white-space:nowrap` é a PRIMEIRA propriedade, e o plano a OMITIA. */
const CLASSES_BOTAO = 'flex h-[32px] cursor-pointer items-center gap-[6px] whitespace-nowrap rounded-[8px] border px-[11px] text-[12.5px] text-pub-txt';
/** `act(true)`, `:1268` — borda 0.55 e fundo 0.12. ⚠️ NÃO é o 0.16 do segmentado. */
const CLASSES_BOTAO_ATIVO = 'border-pub-accent/[0.55] bg-pub-accent/[0.12]';
const CLASSES_BOTAO_INATIVO = 'border-pub-line-strong bg-pub-surface';
/** `:80`/`:93` — o sufixo "· N" é `#A1A1AA` e o caret é `#71717A`. */
const CLASSES_SUFIXO = 'text-pub-txt-2';
const CLASSES_CARET = 'text-pub-txt-4';

/**
 * `:546` do protótipo é `z-44`; aqui é `z-10` — ABAIXO do popover (`z-20`) e
 * acima das linhas do corpo. É o clique-fora que fecha o menu.
 *
 * ⚠️ `fixed inset-0` e **sem cor**: ele não escurece nada. O protótipo também
 * não (`:546` é um `<div>` de captura), e escurecer a tela por trás de um menu
 * de filtro seria efeito inventado.
 */
const CLASSES_OVERLAY = 'fixed inset-0 z-10 cursor-default';

/** `:82`/`:95` — `top:38px`, `left:0`, raio 10px, padding 6px, sombra dos flutuantes. */
const CLASSES_POPOVER = 'absolute left-0 top-[38px] z-20 rounded-pub-sub border border-pub-line-strong bg-pub-surface p-[6px] shadow-pub-flutuante';
const CLASSES_LARGURA_RESPONSAVEL = 'w-[220px]';
const CLASSES_LARGURA_MOTIVO = 'w-[260px]';

/** `:84`/`:97` — `white-space:nowrap` é a PRIMEIRA propriedade, e o plano a OMITIA. */
const CLASSES_ITEM = 'flex h-[34px] w-full cursor-pointer items-center gap-[10px] whitespace-nowrap rounded-pub-control-sm px-[8px] text-left text-[13px] text-pub-txt hover:bg-pub-hover-3';

/**
 * `:85`/`:98` — 15×15, raio 4px, glifo `#0A0A0A` de 10px/**800**.
 *
 * ⚠️ **`font-[800]` e NÃO `font-extrabold`**, e isso é gate: o GATE-03 de
 * `publicador-vocabulario-novo.test.js` aceita só os **4 pesos do README**
 * (400/500/600/700) e reprova `font-(thin|extralight|light|extrabold|black)`.
 * Medido nesta sessão: a primeira redação usava `font-extrabold` e o GATE-03
 * reprovou — é o MESMO peso computado, com o nome que a régua permite. O
 * precedente está ao lado, em `CabecalhoDaFila.jsx:186` (o ⓘ da margem mínima).
 */
const CLASSES_CAIXA = 'grid h-[15px] w-[15px] flex-none place-items-center rounded-pub-badge border text-[10px] font-[800] text-pub-page';
const CLASSES_CAIXA_MARCADA = 'border-pub-accent bg-pub-accent';
const CLASSES_CAIXA_VAZIA = 'border-pub-line-off bg-pub-surface';

/** `:86` — 22×22 redondo, 9.5px/600. Só no popover de Responsável. */
const CLASSES_AVATAR = 'grid h-[22px] w-[22px] flex-none place-items-center rounded-full text-[9.5px] font-semibold';

/** `:86`/`:98` — a contagem do item: `margin-left:auto`, `#71717A`, 12px. */
const CLASSES_CONTAGEM = 'ml-auto text-[12px] text-pub-txt-4';

/**
 * O disparador + o popover de um filtro de lista múltipla.
 *
 * ⚠️ **Sem `opcoes` o componente desenha o BOTÃO e nada mais** — e isso é
 * decisão, não descuido: o botão é o rótulo do filtro e ele existe mesmo quando
 * a conta não tem nenhum responsável. O que NÃO acontece é desenhar um popover
 * VAZIO (casca sem conteúdo é D4 violado).
 *
 * ⚠️ **Sem `onToggle` função o botão não é oferecido:** botão sem destino é a
 * mesma dívida que `onClick` vazio, e aqui ele apareceria clicável sem filtrar
 * nada.
 *
 * @param {object} props
 * @param {unknown} [props.rotulo] "Responsável" ou "Motivo de bloqueio"
 * @param {unknown} [props.opcoes] `[{chave, rotulo, total, avatarDe}]`
 * @param {unknown} [props.ativos] as chaves marcadas
 * @param {unknown} [props.aberto] se o popover está aberto (a página decide)
 * @param {unknown} [props.onAbrir] alterna o popover
 * @param {unknown} [props.onToggle] `(chave) => void`
 * @param {unknown} [props.largo] `true` usa 260px (Motivo); `false` usa 220px (Responsável)
 * @param {unknown} [props.comAvatar] `true` desenha o avatar de 22px (só Responsável)
 */
export default function PopoverDeFiltro({
    rotulo = null,
    opcoes = null,
    ativos = null,
    aberto = false,
    onAbrir = null,
    onToggle = null,
    largo = false,
    comAvatar = false,
}) {
    const nome = textoNaoVazio(rotulo);

    if (nome === null) return null;

    const lista = listaSegura(opcoes);
    const marcadas = listaSegura(ativos).filter((c) => typeof c === 'string');

    // ⚠️ Igualdade ESTRITA com `true`: `aberto` chegando `'sim'`, `1` ou `{}` não
    // é "aberto". Abrir o menu por truthiness faria o popover nascer aberto com
    // dado torto do servidor.
    const estaAberto = aberto === true && typeof onAbrir === 'function' && lista.length > 0;
    const podeAbrir = typeof onAbrir === 'function';
    const temToggle = typeof onToggle === 'function';

    return (
        <div className={CLASSES_ANCORA}>
            <button
                type="button"
                onClick={podeAbrir ? () => onAbrir() : undefined}
                aria-expanded={estaAberto}
                className={cn(CLASSES_BOTAO, marcadas.length > 0 ? CLASSES_BOTAO_ATIVO : CLASSES_BOTAO_INATIVO)}
            >
                {nome}
                {/* ⚠️ O sufixo "· N" só existe COM filtro ativo (`respLabel`,
                    `:1275`: `S.resp.length ? '· ' + S.resp.length : ''`) — nunca
                    um "· 0" ocupando o lugar. */}
                {marcadas.length > 0 && (
                    <span className={CLASSES_SUFIXO}>{PREFIXO_DO_SUFIXO + marcadas.length}</span>
                )}
                <span aria-hidden="true" className={CLASSES_CARET}>{CARET}</span>
            </button>

            {estaAberto && (
                <>
                    {/* ⚠️ O overlay vem ANTES do popover na árvore e é `z-10`,
                        abaixo do popover `z-20`: na ordem inversa ele cobriria os
                        itens e o clique nunca chegaria neles. */}
                    <button type="button" aria-label="Fechar" onClick={() => onAbrir()} className={CLASSES_OVERLAY} />

                    <div className={cn(CLASSES_POPOVER, largo === true ? CLASSES_LARGURA_MOTIVO : CLASSES_LARGURA_RESPONSAVEL)}>
                        {lista.map((cru) => {
                            // ⚠️⚠️ TODA flag DENTRO do callback: o Rollup elimina
                            // variável de escopo do componente usada aqui dentro.
                            const item = objetoComum(cru);
                            const chave = item === null ? null : textoNaoVazio(item.chave);
                            const texto = item === null ? null : textoNaoVazio(item.rotulo);

                            // Item sem chave ou sem rótulo NÃO desenha linha: um item
                            // em branco clicável é pior que a ausência dele.
                            if (chave === null || texto === null) return null;

                            const marcado = marcadas.includes(chave);
                            const total = inteiroNaoNegativo(item.total);
                            const tom = comAvatar === true ? tomDoProduto(chave) : null;
                            const letras = comAvatar === true ? iniciais(texto) : '';

                            return (
                                <button
                                    key={chave}
                                    type="button"
                                    onClick={temToggle ? () => onToggle(chave) : undefined}
                                    aria-pressed={marcado}
                                    className={CLASSES_ITEM}
                                >
                                    <span
                                        aria-hidden="true"
                                        className={cn(CLASSES_CAIXA, marcado ? CLASSES_CAIXA_MARCADA : CLASSES_CAIXA_VAZIA)}
                                    >
                                        {marcado ? GLIFO_MARCADO : ''}
                                    </span>

                                    {/* ⚠️ O avatar é só do popover de Responsável
                                        (`:86`), e o tom vem de `tomDoProduto()` —
                                        a `tone(h)` do protótipo (`:1050`), fonte
                                        ÚNICA por IDENT-08. ⚠️ É por isso que
                                        `oklch(` não aparece neste arquivo. */}
                                    {tom !== null && letras !== '' && (
                                        <span
                                            aria-hidden="true"
                                            className={CLASSES_AVATAR}
                                            style={{ background: tom.fundo, color: tom.texto }}
                                        >
                                            {letras}
                                        </span>
                                    )}

                                    {texto}

                                    {/* ⚠️ Contagem AUSENTE não desenha número
                                        nenhum; contagem `0` APARECE, porque zero
                                        MEDIDO é fato e esconder a opção faria a
                                        pessoa achar que o motivo não existe. */}
                                    {total !== null && <span className={CLASSES_CONTAGEM}>{total}</span>}
                                </button>
                            );
                        })}
                    </div>
                </>
            )}
        </div>
    );
}
