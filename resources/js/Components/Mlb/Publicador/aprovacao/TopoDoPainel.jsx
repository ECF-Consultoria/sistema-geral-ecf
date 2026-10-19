import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 188, plano 07 — O TOPO DE 54px DO PAINEL DE REVISÃO SIMPLES (critério 2).
//
// ⚠️⚠️ **O TOPO TEM QUATRO ELEMENTOS, E ISSO É DECISÃO DO USUÁRIO, NÃO FALTA.**
// O protótipo monta CINCO em `:551`-`:559`: "REVISÃO SIMPLES", "x de N na fila",
// o par ↑↓, o botão "Abrir revisão detalhada ↗" (`:558`) e o ×. **O quarto não é
// desenhado nesta fase** — PERGUNTA-188-B3, respondida pelo usuário em
// 18/10/2026: ele nasce na **Fase 190/191**, junto com a tela de destino, e a
// razão dele é *"é a mesma régua do aviso de tela vazia — sem botão, porque não
// há o que fazer com ele"*.
// ⚠️ **"Desenhar desabilitado com explicação" foi OFERECIDO e RECUSADO.** Por
// isso aqui não existe o botão, não existe `onClick` vazio e não existe
// `disabled` decorativo — as duas últimas são a mesma dívida com outra roupa.
// ⚠️ A rota de destino **não existe**: `routes/mlb_anuncios.php:491` diz que a
// revisão detalhada é da Fase 190/191. Botão sem destino é D4 violado.
// ⚠️ **O CRITÉRIO 2 FICA COM RESSALVA DECLARADA** no fechamento (plano 188-13).
//
// ═══ ⚠️⚠️ DIVERGÊNCIA MEDIDA, E ELA É CONSEQUÊNCIA DA AUSÊNCIA ACIMA ═══════
//
// No protótipo o `margin-left:auto` mora no botão de `:558` — é ELE que empurra
// o resto para a direita, e o × (`:559`) vem logo depois, encostado na borda.
// Sem `:558`, o `margin-left:auto` **não tem mais dono**, e o × ficaria colado
// no ↓, a 10px dele, com os 14px de `padding-right` do topo vazios — posição
// visivelmente diferente da do protótipo.
// ➡️ O `margin-left:auto` passa para o **×**, que é o elemento que ocupa, agora,
// o lugar que `:558` ancorava. ⚠️ É mudança de DONO da propriedade, não de
// VALOR: o × continua a 14px da borda direita, como em `:559`. **Declarado na
// SUMMARY, com `arquivo:linha`.**
//
// ═══ ⚠️ MEDIDAS DO PROTÓTIPO — por SCRIPT (`sed -n '<L>p'`), 19/10/2026 ═════
//
// Protótipo de autoridade: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas, sha256 `68624883ce35547e`). ⚠️ **O diretório importa** — há DUAS
// cópias do pacote de handoff na árvore, com conteúdo DIFERENTE.
//
//   · B3  topo       → `:551` `display:flex;align-items:center;gap:10px;`
//                            `height:54px;padding:0 14px 0 22px;`
//                            `border-bottom:1px solid #1F1F1F;flex:none`
//   · B4  "REVISÃO   → `:552` `font-size:11px;font-weight:700;`
//         SIMPLES"           `letter-spacing:0.08em;color:#8A8A90`
//   · B5  "x de N"   → `:553` `font-size:12px;color:#71717A`
//   · B6  wrapper ↑↓ → `:554` `display:flex;gap:2px;margin-left:6px`
//   · B7  botão ↑    → `:555` `white-space:nowrap;width:30px;height:30px;`
//                            `border:none;border-radius:7px;background:transparent;`
//                            `color:#A1A1AA;cursor:pointer;font-size:14px`
//                            + `title="Anterior (↑)"` + hover `background:#1A1A1A`
//   · B8  botão ↓    → `:556` idem + `title="Próximo (↓)"`
//   · B10 botão ×    → `:559` `white-space:nowrap;width:32px;height:32px;`
//                            `border:none;border-radius:8px;background:transparent;`
//                            `color:#A1A1AA;cursor:pointer;font-size:16px`
//                            + `title="Fechar (Esc)"` + hover `background:#1A1A1A`
//
// ⚠️ **`white-space:nowrap` é a PRIMEIRA propriedade das três linhas de botão** —
// a família de omissão que a Fase 187 mediu SETE vezes, TRÊS delas exatamente
// assim. E `cursor:pointer` foi omitido ONZE vezes numa fase. Os dois estão aqui.
//
// ═══ ⚠️ OS TOKENS, NENHUM NOVO (medidos em `tailwind.config.js`) ═══════════
//
//   `#1F1F1F` → `pub-line-subtle` · `#8A8A90` → `pub-txt-3` ·
//   `#71717A` → `pub-txt-4` · `#A1A1AA` → `pub-txt-2` · `#1A1A1A` → `pub-hover-3`
//   `border-radius:7px` → `rounded-pub-control-sm` ·
//   `border-radius:8px` → ⚠️ **não tem token** (`pub-sub` é 10px e `pub-control`
//   é 9px) — vai como `rounded-[8px]`, a forma já usada 7 vezes em `aprovacao/`.
// ⚠️ FID-03: `text-[11px]`/`text-[12px]`/`text-[14px]`/`text-[16px]`, nunca
// `text-xs`/`text-sm`/`text-base`.
//
// ═══ ⚠️⚠️ A SETA SEM DESTINO NÃO É DESENHADA ═══════════════════════════════
//
// O protótipo CLAMPA (`:1018` usa `Math.max(0, …)`), então lá os dois botões
// existem sempre e, na ponta, não fazem nada. ➡️ Aqui a seta só é desenhada
// quando o DESTINO existe (`anterior_id`/`proxima_id` não-nulos, o dado que o
// plano 188-02 entrega). ⚠️ **Isto NÃO é escolha nova:** é a aplicação da MESMA
// régua que o usuário já exerceu em PERGUNTA-188-B (destino `null` → **SEM
// BOTÃO**, e o texto continua) e em PERGUNTA-188-B3 (*"sem botão, porque não há
// o que fazer com ele"*; *"desabilitado com explicação"* foi RECUSADO).
// ⚠️ E o **wrapper** de `:554` também não é desenhado quando NENHUMA das duas
// existe — casca vazia de 6px de margem é D4 violado.
// ⚠️ **Declarado na SUMMARY como divergência do protótipo, com o precedente
// nomeado.**
//
// ═══ ⚠️ `title` NUNCA VAZIO ════════════════════════════════════════════════
//
// Os três botões são GLIFO puro (↑ ↓ ×). Guarda que devolve `null`/`''` no
// `title` produz **elemento sem nome acessível**, que passa em render e
// DESAPARECE para quem usa leitor de tela — foi o conserto do anel na 187-08.
// Por isso os três `title` são CONSTANTES deste módulo, nunca props.
//
// ⚠️ Nenhum `String(valor)` e nenhum `obj[chave]` com chave do servidor neste
// arquivo: os dois LANÇAM com `{toString: null}`, e a tela preta voltou SEIS
// vezes neste projeto — uma delas **no clique**.
// ⚠️ **Nada de `localStorage`**: a oferta aberta vive na URL (COL-02/COL-03).
// ═══════════════════════════════════════════════════════════════════════════

/** `:551` — o topo de 54px. ⚠️ `padding:0 14px 0 22px` são DOIS lados diferentes. */
export const CLASSES_TOPO =
    'flex flex-none items-center gap-[10px] h-[54px] pl-[22px] pr-[14px] border-b border-pub-line-subtle';

/** `:552` — o rótulo da peça. */
export const CLASSES_ROTULO = 'text-[11px] font-bold tracking-[0.08em] text-pub-txt-3';

/** `:552` — o literal, em maiúsculas no próprio texto (não por `uppercase`). */
export const ROTULO_DO_PAINEL = 'REVISÃO SIMPLES';

/** `:553` — "x de N na fila". ⚠️ O TEXTO vem do servidor; aqui só a forma. */
export const CLASSES_POSICAO = 'text-[12px] text-pub-txt-4';

/** `:554` — o wrapper do par ↑↓. */
export const CLASSES_WRAPPER_SETAS = 'flex gap-[2px] ml-[6px]';

/** `:555`/`:556` — a geometria comum das duas setas. */
export const CLASSES_SETA =
    'w-[30px] h-[30px] cursor-pointer whitespace-nowrap rounded-pub-control-sm text-[14px] text-pub-txt-2 hover:bg-pub-hover-3';

/** `:559` — o ×. ⚠️ `ml-auto` é o `margin-left:auto` herdado de `:558`, que não é desenhado. */
export const CLASSES_FECHAR =
    'ml-auto w-[32px] h-[32px] cursor-pointer whitespace-nowrap rounded-[8px] text-[16px] text-pub-txt-2 hover:bg-pub-hover-3';

/** `:555` — o glifo da seta de cima. É U+2191, não a letra. */
export const GLIFO_ANTERIOR = '↑';

/** `:556` — o glifo da seta de baixo. É U+2193. */
export const GLIFO_PROXIMO = '↓';

/** `:559` — o glifo de fechar. É U+00D7, não a letra x. */
export const GLIFO_FECHAR = '×';

/** `:555` — o `title` da seta de cima. ⚠️ CONSTANTE: `title` vazio é elemento sem nome. */
export const TITULO_ANTERIOR = 'Anterior (↑)';

/** `:556` — o `title` da seta de baixo. */
export const TITULO_PROXIMO = 'Próximo (↓)';

/** `:559` — o `title` do ×, que NOMEIA o atalho. */
export const TITULO_FECHAR = 'Fechar (Esc)';

/**
 * Texto não-vazio, ou `null`.
 *
 * ⚠️ `typeof !== 'string'` ANTES de tudo, e **nunca** `String(valor)`: ele LANÇA
 * com `{toString: null}`. ⚠️ E número NÃO passa: `textoSeguro(NaN)` devolveria a
 * string `'NaN'`, que iria para a tela.
 */
export function textoDoTopo(valor) {
    if (typeof valor !== 'string') return null;

    return valor.trim() === '' ? null : valor;
}

/**
 * Inteiro finito, ou `null`.
 *
 * ⚠️ **NUNCA `Number(valor)`**: `Number('')` e `Number(null)` são **0**, e um id
 * de oferta igual a 0 montaria uma navegação inventada.
 */
export function idDoTopo(valor) {
    return typeof valor === 'number' && Number.isInteger(valor) ? valor : null;
}

/**
 * Uma seta de navegação, ou `null` quando não há destino.
 *
 * ⚠️ NAMED EXPORT de propósito: o renderizador de SERVIDOR do React **não
 * renderiza portal**, então é assim que as peças internas do painel são
 * conferidas e **exercidas no clique** — a solução da Fase 178, repetida pela
 * 187-09. Ver o docblock de `PainelDeRevisao.jsx`.
 *
 * @param {object} props
 * @param {unknown} [props.destino] o id da oferta de destino (`anterior_id`/`proxima_id`)
 * @param {unknown} [props.glifo] `GLIFO_ANTERIOR` ou `GLIFO_PROXIMO`
 * @param {unknown} [props.titulo] `TITULO_ANTERIOR` ou `TITULO_PROXIMO`
 * @param {unknown} [props.onIr] `(id) => void`
 */
export function SetaDoPainel({ destino = null, glifo = null, titulo = null, onIr = null }) {
    const id = idDoTopo(destino);
    const rotulo = textoDoTopo(titulo);
    const forma = textoDoTopo(glifo);

    // ⚠️ Sem destino, sem handler, sem glifo OU sem `title`: a seta NÃO é
    // desenhada. Nenhum `onClick` vazio, nenhum `disabled` decorativo, e nunca um
    // botão sem nome acessível.
    if (id === null || typeof onIr !== 'function' || forma === null || rotulo === null) return null;

    return (
        <button type="button" title={rotulo} className={CLASSES_SETA} onClick={() => onIr(id)}>
            {forma}
        </button>
    );
}

/**
 * O topo de 54px: o rótulo, a posição na fila, o par ↑↓ e o ×.
 *
 * @param {object} props
 * @param {unknown} [props.navegacao] o bloco `navegacao` do servidor (plano 188-02):
 *   `{ids, anterior_id, proxima_id, posicao, total, pos}`.
 *   ⚠️ **LIDO, nunca remontado**: a fila ordenada SEM `preparando` é do servidor,
 *   e filtrar no cliente daria uma segunda verdade sobre a ordem da fila.
 * @param {unknown} [props.onIr] `(id) => void` — a navegação ↑/↓
 * @param {unknown} [props.onFechar] `() => void` — o ×
 * @param {unknown} [props.className] classe extra da RAIZ, só como string
 */
export default function TopoDoPainel({ navegacao = null, onIr = null, onFechar = null, className = null }) {
    // ⚠️ `null` e array são os dois `typeof 'object'` que precisam de guarda
    // própria — e a guarda vem ANTES de qualquer acesso por chave.
    const nav = navegacao !== null && typeof navegacao === 'object' && !Array.isArray(navegacao) ? navegacao : null;

    // ⚠️⚠️ `pos` é a FRASE PRONTA do servidor, e `null` fora da navegação (a
    // oferta em `preparando`). Com `pos = null` o bloco **não** é desenhado:
    // *"0 de N na fila"* é a tela afirmando uma posição que não existe, e o
    // critério 2 manda que *"a tela não inventa posição"*. ⚠️ O protótipo PERDE
    // aqui — `:1318` deixa o `indexOf` devolver −1 e escreve "0 de N" —, e essa
    // divergência foi medida e declarada pelo plano 188-02, não por este.
    const posicao = textoDoTopo(nav === null ? null : nav.pos);

    const anterior = idDoTopo(nav === null ? null : nav.anterior_id);
    const proxima = idDoTopo(nav === null ? null : nav.proxima_id);
    const podeIr = typeof onIr === 'function';

    // ⚠️ O wrapper de `:554` só existe quando ALGUMA seta existe — wrapper sozinho
    // seria 6px de margem sem conteúdo nenhum (D4).
    const temSeta = podeIr && (anterior !== null || proxima !== null);

    const classeExtra = typeof className === 'string' && className.trim() !== '' ? className : null;

    return (
        <div className={cn(CLASSES_TOPO, classeExtra)}>
            <span className={CLASSES_ROTULO}>{ROTULO_DO_PAINEL}</span>

            {posicao === null ? null : <span className={CLASSES_POSICAO}>{posicao}</span>}

            {temSeta ? (
                <div className={CLASSES_WRAPPER_SETAS}>
                    <SetaDoPainel destino={anterior} glifo={GLIFO_ANTERIOR} titulo={TITULO_ANTERIOR} onIr={onIr} />
                    <SetaDoPainel destino={proxima} glifo={GLIFO_PROXIMO} titulo={TITULO_PROXIMO} onIr={onIr} />
                </div>
            ) : null}

            {/* ⚠️ O × é o ÚNICO que existe sempre: fechar é sempre possível, e sem
                ele o painel só sairia pela tecla Esc — que não se vê.
                ⚠️ A forma `typeof onFechar === 'function' ? … : undefined` é o
                PRECEDENTE MEDIDO de `CascaDeModal.jsx:216`, que está em produção:
                `undefined` não é `onClick` vazio — o React não registra listener
                nenhum —, e some o × seria deixar o painel sem saída visível. */}
            <button
                type="button"
                title={TITULO_FECHAR}
                className={CLASSES_FECHAR}
                onClick={typeof onFechar === 'function' ? () => onFechar() : undefined}
            >
                {GLIFO_FECHAR}
            </button>
        </div>
    );
}
