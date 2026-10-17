import { isValidElement } from 'react';
import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 09 — A CASCA COMUM DOS MODAIS DO PORTÃO.
//
// Backdrop `rgba(0,0,0,0.65)` a partir de `top:96px`, caixa de 560px com
// `max-height:calc(100vh - 140px)`, topo com título/sub e ×, corpo e rodapé.
//
// ⚠️⚠️ **ELA NASCE AQUI E A FASE 188 A REUSA — DECLARADO.** O Coverage Map
// atribui a "casca comum" à Fase 188 (MOD-01..05), mas o **modal de lote** é da
// 187 (MOD-11) e precisa dela. ➡️ Ela é desta fase, com um usuário **REAL** (o
// lote), e a 188 **importa** em vez de recriar. Sem essa decisão haveria duas
// cascas divergindo — e a divergente seria descoberta por alguém vendo dois
// modais com raios diferentes na mesma tela.
//
// ⚠️⚠️ **SEM PORTAL, E O MOTIVO É MEDIDO.** O renderizador de SERVIDOR do React
// **não renderiza portal**: um teste de render veria **vazio** e passaria **por
// omissão** — o gate ficaria verde provando nada. E conteúdo portado **não herda
// a fonte**: os dois `Popover.Content` e o modal da Fase 178 tiveram de declarar
// `font-pub` por conta própria.
// ➡️ A casca é **IRMÃ do `<main>`, na raiz da página**, que é exatamente onde o
// protótipo a põe (`:775`, fora do `<main>`). Como o `backdrop-filter` do header
// cria containing block **só para descendentes dele**, e ela não é descendente,
// **não há problema de posicionamento**. ⚠️ Há gate provando por ÁRVORE que ela
// não está dentro do `<main>`.
//
// ⚠️ **E AS PEÇAS INTERNAS SAEM COMO NAMED EXPORTS** (REND-05) — é a solução da
// Fase 178 para o que o render de servidor não alcança, e é o que permite conferir
// cada peça e exercer os handlers diretamente.
//
// ⚠️⚠️ **O Z-INDEX É `z-[42]`, REMAPEADO.** No protótipo o backdrop é
// `z-index:100` porque lá o header é 50. **Neste projeto a ordem é TRAVADA**:
// `filtros 10 < header 30 < menu lateral = painel 40`. O modal fica **acima do
// menu/painel** (ele é modal: nada pode ficar na frente dele a não ser o aviso do
// que acabou de acontecer) e **abaixo do toast** (`z-[45]`). A ORDEM RELATIVA do
// protótipo é preservada; os NÚMEROS são novos.
//
// ⚠️ **O CLIQUE NO BACKDROP FECHA; O CLIQUE NO CORPO NÃO.** É o par
// `M.onClose`/`M.stop` de `:775`/`:776` — `stopPropagation` no corpo. Sem ele,
// selecionar texto dentro do modal o fecharia.
//
// ⚠️ **FID-01 — as OMISSÕES DA TABELA DO PLANO, medidas por SCRIPT nesta sessão:**
//   · `:778` → o **WRAPPER** `display:flex;flex-direction:column;gap:4px` do
//     par título/sub está **omitido** na prescrição (ela lista só os dois spans);
//   · `:779` → `white-space:nowrap` e `cursor:pointer` **omitidos**;
//   · `:889`/`:890` → `white-space:nowrap` e `cursor:pointer` **omitidos** nos
//     dois, e `font-weight:600` **omitido** no botão de submeter.
// ⚠️ **DIVERGÊNCIA DE PONTEIRO:** o plano aponta a nota do lote em `:884`; ela
// está em **`:885`**. Medido, não presumido.
// ═══════════════════════════════════════════════════════════════════════════

/** `:775` — o backdrop. ⚠️ `z-[42]` é o remapeamento, não o `100` do protótipo. */
export const CLASSES_BACKDROP = 'fixed inset-0 z-[42] flex items-start justify-center bg-black/[0.65] pt-[96px]';

/** `:776` — a caixa de 560px. */
export const CLASSES_CAIXA =
    'flex max-h-[calc(100vh-140px)] w-[560px] flex-col overflow-y-auto rounded-pub-card border border-pub-line-strong bg-pub-surface shadow-pub-modal';

/** `:777` — o topo. */
export const CLASSES_TOPO =
    'flex items-start justify-between gap-[12px] border-b border-pub-line-subtle px-[20px] pb-[14px] pt-[18px]';

/** `:778` — o WRAPPER do par título/sub, que a tabela do plano OMITE. */
export const CLASSES_COLUNA_DO_TITULO = 'flex flex-col gap-[4px]';

/** `:778` — o título. */
export const CLASSES_TITULO = 'text-[16px] font-semibold';

/** `:778` — o sub. */
export const CLASSES_SUB = 'text-[12.5px] text-pub-txt-3';

/** `:779` — o × de fechar. ⚠️ `whitespace-nowrap` e `cursor-pointer` são omissões do plano. */
export const CLASSES_FECHAR =
    'h-[30px] w-[30px] cursor-pointer whitespace-nowrap rounded-pub-control-sm text-[16px] text-pub-txt-2';

/** `:779` — o glifo do ×. É U+00D7, não a letra x. */
export const GLIFO_FECHAR = '×';

/** O `title` do × — a tela nunca depende só do glifo. */
export const TITULO_FECHAR = 'Fechar';

/** `:781` — o corpo. */
export const CLASSES_CORPO = 'flex flex-col gap-[14px] px-[20px] py-[16px]';

/** `:888` — o rodapé. */
export const CLASSES_RODAPE =
    'flex justify-end gap-[8px] border-t border-pub-line-subtle bg-pub-sunken px-[20px] py-[14px]';

/** `:889`/`:890` — a geometria comum dos dois botões do rodapé. */
export const CLASSES_BOTAO = 'h-[36px] cursor-pointer whitespace-nowrap rounded-pub-control text-[13px]';

/** `:889` — "Cancelar": `padding:0 14px`, borda e fundo transparente. */
export const CLASSES_CANCELAR = 'border border-pub-line-strong px-[14px] text-pub-txt-2';

/** `:890` — o botão de submeter: `padding:0 16px` e peso 600 (omitido no plano). */
export const CLASSES_SUBMETER = 'px-[16px] font-semibold';

/** `:890` — o submeter ATIVO (`#FACC15` sobre `#0A0A0A`). */
export const CLASSES_SUBMETER_ATIVO = 'bg-pub-accent text-pub-page';

/** `:890` — o submeter APAGADO (`#262626` sobre `#71717A`). ⚠️ Mais `disabled`: cor não basta. */
export const CLASSES_SUBMETER_APAGADO = 'bg-pub-seg text-pub-txt-4';

/** `:889` — o rótulo literal do botão de cancelar. */
export const ROTULO_CANCELAR = 'Cancelar';

/** Texto não-vazio, ou `null`. ⚠️ Nunca `String(valor)`, que LANÇA com `{toString:null}`. */
function textoNaoVazio(valor) {
    if (typeof valor !== 'string') return null;

    return valor.trim() === '' ? null : valor;
}

/**
 * O topo do modal — título, sub e ×.
 *
 * ⚠️ NAMED EXPORT de propósito: é assim que o gate confere a peça sem depender do
 * composto inteiro.
 */
export function TopoDoModal({ titulo = null, sub = null, onFechar = null }) {
    const t = textoNaoVazio(titulo);
    const s = textoNaoVazio(sub);

    return (
        <div className={CLASSES_TOPO}>
            <div className={CLASSES_COLUNA_DO_TITULO}>
                {/* ⚠️ Sem título o span NÃO é desenhado: elemento vazio passa em
                    render e DESAPARECE na tela. */}
                {t === null ? null : <span className={CLASSES_TITULO}>{t}</span>}
                {s === null ? null : <span className={CLASSES_SUB}>{s}</span>}
            </div>

            {typeof onFechar !== 'function' ? null : (
                <button type="button" title={TITULO_FECHAR} onClick={() => onFechar()} className={CLASSES_FECHAR}>
                    {GLIFO_FECHAR}
                </button>
            )}
        </div>
    );
}

/**
 * O rodapé do modal — "Cancelar" e o botão de submeter.
 *
 * ⚠️ O submeter sai `disabled` quando não pode: **cor não é o único sinal**.
 */
export function RodapeDoModal({ rotuloDoBotao = null, podeSubmeter = false, onFechar = null, onSubmeter = null }) {
    const rotulo = textoNaoVazio(rotuloDoBotao);
    const liberado = podeSubmeter === true && typeof onSubmeter === 'function';

    return (
        <div className={CLASSES_RODAPE}>
            {typeof onFechar !== 'function' ? null : (
                <button type="button" onClick={() => onFechar()} className={cn(CLASSES_BOTAO, CLASSES_CANCELAR)}>
                    {ROTULO_CANCELAR}
                </button>
            )}

            {rotulo === null ? null : (
                <button
                    type="button"
                    disabled={!liberado}
                    onClick={liberado ? () => onSubmeter() : undefined}
                    className={cn(
                        CLASSES_BOTAO,
                        CLASSES_SUBMETER,
                        liberado ? CLASSES_SUBMETER_ATIVO : CLASSES_SUBMETER_APAGADO,
                    )}
                >
                    {rotulo}
                </button>
            )}
        </div>
    );
}

/**
 * A casca: backdrop + caixa + topo + corpo + rodapé.
 *
 * ⚠️ `aberto !== true` não desenha NADA — nem backdrop invisível, que capturaria
 * o clique da tela inteira sem ninguém ver por quê.
 */
export default function CascaDeModal({
    aberto = false,
    titulo = null,
    sub = null,
    rotuloDoBotao = null,
    podeSubmeter = false,
    onFechar = null,
    onSubmeter = null,
    children = null,
}) {
    if (aberto !== true) return null;

    // ⚠⚠ **O CORPO É FILTRADO ELEMENTO A ELEMENTO, e isso é conserto MEDIDO nesta
    // sessão.** A primeira redação aceitava qualquer ARRAY (`Array.isArray(children)`)
    // e o render adverso DERRUBOU com `[{}]`: *"Objects are not valid as a React
    // child"*. Um array de filhos pode ter um objeto comum no meio — e **prop de NÓ
    // chegando objeto comum MATA a página**, que é a tela preta que voltou quatro
    // vezes na Fase 180.
    // ⚠️ `null` e string são filhos LEGÍTIMOS do React e passam; objeto comum, não.
    const filhoValido = (filho) => filho === null || filho === undefined
        || typeof filho === 'string' || typeof filho === 'number' || typeof filho === 'boolean'
        || isValidElement(filho);

    let corpo = null;
    if (Array.isArray(children)) {
        const limpos = children.filter((filho) => filhoValido(filho));
        corpo = limpos.length > 0 ? limpos : null;
    } else if (isValidElement(children)) {
        corpo = children;
    }

    return (
        // ⚠️ O clique no BACKDROP fecha (`:775`).
        <div className={CLASSES_BACKDROP} onClick={typeof onFechar === 'function' ? () => onFechar() : undefined}>
            {/* ⚠️ E o clique no CORPO **NÃO** fecha (`:776`, `M.stop`): sem o
                `stopPropagation`, selecionar texto dentro do modal o fecharia. */}
            <div className={CLASSES_CAIXA} onClick={(evento) => evento.stopPropagation()}>
                <TopoDoModal titulo={titulo} sub={sub} onFechar={onFechar} />

                {corpo === null ? null : <div className={CLASSES_CORPO}>{corpo}</div>}

                <RodapeDoModal
                    rotuloDoBotao={rotuloDoBotao}
                    podeSubmeter={podeSubmeter}
                    onFechar={onFechar}
                    onSubmeter={onSubmeter}
                />
            </div>
        </div>
    );
}
