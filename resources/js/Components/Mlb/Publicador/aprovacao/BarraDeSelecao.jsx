import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 09 — A BARRA FLUTUANTE DA SELEÇÃO EM LOTE.
//
// "N selecionadas · Aprovar K para publicação · ×", colada ao rodapé da janela.
//
// ⚠️⚠️ **APROVAR JÁ PUBLICA, SEM SEGUNDO CLIQUE** (decisão 3 do usuário,
// 14/10/2026). O rótulo do botão é **"Aprovar K para publicação"**, inalterado, e
// clicar nele abre o MODAL DE CONFERÊNCIA — não um "passo 2 de 2". A diferença é
// de propósito: a conferência acontece ANTES, porque depois não há desfazer.
//
// ⚠️⚠️ **OS DOIS `·` DO RÓTULO NÃO SÃO CARACTERES.** Medido em `:898`: o separador
// entre "N selecionadas" e o botão é `border-right: 1px solid #333333` + o par
// `padding-right:10px` / `margin-right:4px`. A prosa do plano escreve
// "N selecionadas · Aprovar … · ×" para descrever o que se VÊ; quem puser um
// `·` literal ali vai desenhar dois pontos médios ao lado de duas bordas.
//
// ⚠️⚠️ **SEM PORTAL, E O MOTIVO É MEDIDO.** O renderizador de SERVIDOR do React
// (`react-dom/server`) **não renderiza portal**: um teste de render veria vazio e
// passaria **por omissão**. E conteúdo portado **não herda a fonte** — os dois
// `Popover.Content` e o modal da Fase 178 tiveram de declarar `font-pub` por
// conta própria. ➡️ Esta barra é **IRMÃ do `<main>`, na raiz da página**, que é
// exatamente onde o protótipo a põe (`:896`, fora do `<main>`). Como o
// `backdrop-filter` do header cria containing block **só para descendentes
// dele**, e ela não é descendente, **não há problema de posicionamento**.
// ⚠️ Há gate provando, por ÁRVORE, que ela não está dentro do `<main>`.
//
// ⚠️ **E AS PEÇAS INTERNAS SAEM COMO NAMED EXPORTS** (REND-05): é a solução da
// Fase 178 para o que o render de servidor não alcança, e é o que permite exercer
// cada peça — e o CLIQUE — diretamente. A tela preta da wave 6 foi **no clique**,
// e `renderToStaticMarkup` não dispara `onClick`.
//
// ⚠️⚠️ **O Z-INDEX É `z-[35]`, REMAPEADO — E NÃO É O DO PROTÓTIPO.** Lá a barra é
// `z-index:60` porque o header é 50. **Neste projeto a ordem é TRAVADA**:
// `filtros 10 < header 30 < menu lateral = painel 40`
// (`tests/js/publicador-header-zindex-ordem.test.js`, invariante do bug que o
// usuário relatou em 12/10/2026). A ORDEM RELATIVA é preservada — acima do
// header, abaixo do menu — e os NÚMEROS são novos. Copiar o 60 do protótipo faria
// a barra cobrir o menu lateral expandido: o bug de 12/10 com outra roupa.
//
// ⚠️ **`pub-float` e `pub-float-line` ACORDAM AQUI.** Eles foram declarados em
// `tailwind.config.js` para a barra (`:897`) e para o toast (`:905`), e até esta
// wave estavam DORMINDO — a wave 8 recusou acordá-los fora do lugar medido, e
// estava certa.
//
// ⚠️ **FID-01 — as OMISSÕES DA TABELA DO PLANO, medidas por SCRIPT nesta sessão**
// em `design_handoff_publicador/Aprovacao.dc.html` (1432 linhas,
// sha256 `68624883ce35547e`):
//   · `:898` → `white-space:nowrap` **omitido** na prescrição;
//   · `:899` → `white-space:nowrap` e `cursor:pointer` **omitidos**;
//   · `:900` → `white-space:nowrap` e `cursor:pointer` **omitidos**.
// `cursor:pointer` já foi omitido ONZE vezes numa fase deste projeto.
// ═══════════════════════════════════════════════════════════════════════════

/** `:897` — a casca flutuante. ⚠️ `z-[35]` é o remapeamento, não o `60` do protótipo. */
export const CLASSES_BARRA =
    'fixed bottom-[24px] left-1/2 z-[35] flex -translate-x-1/2 items-center gap-[6px] rounded-pub-panel border border-pub-float-line bg-pub-float py-[6px] pl-[16px] pr-[6px] shadow-pub-flutuante';

/** `:898` — o rótulo e o divisor vertical. ⚠️ `whitespace-nowrap` é omissão do plano. */
export const CLASSES_ROTULO =
    'mr-[4px] whitespace-nowrap border-r border-pub-float-line pr-[10px] text-[13px] font-medium';

/** `:899` — a geometria comum do botão de aprovar. */
export const CLASSES_APROVAR =
    'h-[32px] cursor-pointer whitespace-nowrap rounded-[8px] px-[14px] text-[13px] font-semibold';

/** `:899` — o botão ATIVO (`#FACC15` sobre `#0A0A0A`). */
export const CLASSES_APROVAR_ATIVO = 'bg-pub-accent text-pub-page';

/**
 * `:899` — o botão APAGADO (`#262626` sobre `#71717A`), quando `K = 0`.
 *
 * ⚠️ **COR NÃO É O ÚNICO SINAL** (regra permanente): o próprio rótulo diz
 * "Aprovar 0 para publicação", e o botão fica `disabled`. Quem só apagasse a cor
 * deixaria um botão que parece clicável e não é.
 */
export const CLASSES_APROVAR_APAGADO = 'bg-pub-seg text-pub-txt-4';

/** `:900` — o × que limpa a seleção. */
export const CLASSES_LIMPAR =
    'h-[32px] w-[32px] cursor-pointer whitespace-nowrap rounded-[8px] bg-pub-line-strong text-pub-txt';

/** `:900` — o glifo do botão de limpar. É U+00D7, não a letra x. */
export const GLIFO_LIMPAR = '×';

/** O `title` do × — a tela nunca depende só do glifo. */
export const TITULO_LIMPAR = 'Limpar a seleção';

/** Texto não-vazio, ou `null`. ⚠️ Nunca `String(valor)`, que LANÇA com `{toString:null}`. */
function textoNaoVazio(valor) {
    if (typeof valor !== 'string') return null;

    return valor.trim() === '' ? null : valor;
}

/** Objeto comum de verdade, ou `null`. `null` e array são os dois `typeof 'object'`. */
function objetoComum(valor) {
    return valor !== null && typeof valor === 'object' && !Array.isArray(valor) ? valor : null;
}

/** Inteiro finito, ou `null`. ⚠️ Nunca `Number(valor)`: `Number('')` é 0. */
function inteiroSeguro(valor) {
    return typeof valor === 'number' && Number.isInteger(valor) ? valor : null;
}

/**
 * A barra flutuante da seleção em lote.
 *
 * ⚠️⚠️ **ELA SÓ APARECE COM SELEÇÃO > 0, e com rótulo.** Barra vazia é pior que
 * barra ausente: ela ocupa o rodapé da janela, cobre a última linha da tabela e
 * não diz nada. ⚠️ E o teste da ausência é sobre o **rótulo**, não sobre a
 * contagem: `lote` chegando objeto adverso (sem `rotulo_da_selecao`) também não
 * desenha.
 *
 * @param {object|null} lote      o painel do servidor (`LoteDaFilaService::painel()`)
 * @param {Function|null} onAbrir abre o modal de conferência
 * @param {Function|null} onLimpar limpa a seleção
 */
export default function BarraDeSelecao({ lote = null, onAbrir = null, onLimpar = null }) {
    const dados = objetoComum(lote);

    const rotulo = textoNaoVazio(dados?.rotulo_da_selecao);
    const selecionadas = inteiroSeguro(dados?.selecionadas) ?? 0;

    // ⚠️ Sem rótulo ou sem seleção, NADA é desenhado — nem casca, nem borda.
    if (rotulo === null || selecionadas <= 0) return null;

    const rotuloDeAprovar = textoNaoVazio(dados?.rotulo_de_aprovar);

    // ⚠⚠ **O BOTÃO DE APROVAR EXIGE O HANDLER, e isso é conserto MEDIDO nesta
    // sessão.** A primeira redação o desenhava `disabled` quando `onAbrir` faltava —
    // e um botão com rótulo, sem destino nenhum, é exatamente o que este projeto
    // proibiu três vezes (D4). ⚠️ `disabled` por **K=0** é outra coisa, e continua:
    // ali o destino EXISTE e a tela está dizendo *"nenhuma das selecionadas pode ser
    // aprovada"*, que é informação. Sem handler não há nem isso a dizer.
    const temDestino = typeof onAbrir === 'function';
    const podeAprovar = dados?.pode_aprovar === true && temDestino;

    return (
        <div className={CLASSES_BARRA}>
            <span className={CLASSES_ROTULO}>{rotulo}</span>

            {/* ⚠️ Sem rótulo o botão não é desenhado (botão sem texto é o elemento
                vazio que passa em render e DESAPARECE na tela), e sem HANDLER
                também não (botão sem destino é proibido neste projeto). */}
            {rotuloDeAprovar === null || !temDestino ? null : (
                <button
                    type="button"
                    disabled={!podeAprovar}
                    onClick={podeAprovar ? () => onAbrir() : undefined}
                    className={cn(CLASSES_APROVAR, podeAprovar ? CLASSES_APROVAR_ATIVO : CLASSES_APROVAR_APAGADO)}
                >
                    {rotuloDeAprovar}
                </button>
            )}

            {typeof onLimpar !== 'function' ? null : (
                <button type="button" title={TITULO_LIMPAR} onClick={() => onLimpar()} className={CLASSES_LIMPAR}>
                    {GLIFO_LIMPAR}
                </button>
            )}
        </div>
    );
}
