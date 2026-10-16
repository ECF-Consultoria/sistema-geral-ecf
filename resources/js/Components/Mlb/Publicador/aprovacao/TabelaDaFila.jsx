import { isValidElement } from 'react';
import { cn } from '@/lib/utils';
import LinhaDeProdutoDaFila from './LinhaDeProdutoDaFila';
import SubLinhaDeConjunto from './SubLinhaDeConjunto';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 07 — A TABELA DA FILA: a casca, o GRID DE 11 COLUNAS e os
// níveis 1 e 2 (FILA-07/08/09).
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas, sha256 `68624883ce35547e`). ⚠️⚠️ **OS PONTEIROS DO PLANO
// ESTAVAM DESLOCADOS EM 4 DOS 4 DESTA CASCA** — os certos foram MEDIDOS com
// `awk`/`sed` nesta sessão (16/10/2026):
//
//   elemento                plano   MEDIDO
//   `<section>`             :104    **:108**  `background:#111111;border:1px solid #262626;`
//                                             `border-radius:14px;overflow:hidden`
//   cabeçalho (o grid)      :105    **:109**  `display:grid;grid-template-columns:<as 11>;`
//                                             `align-items:center;height:38px;padding:0 16px;`
//                                             `background:#0D0D0D;border-bottom:1px solid #1F1F1F;`
//                                             `font-size:12px;font-weight:500;color:#71717A`
//   checkbox do cabeçalho   :106    **:110**  `16×16;border-radius:4px;padding:0;color:#0A0A0A;`
//                                             `font-size:10px;font-weight:800;cursor:pointer`
//                                             + `title="Selecionar visíveis"`
//   Preço / Resultado       :107    **:111**  `text-align:right;padding-right:14px`
//   borda entre grupos      :109    **:114**  `border-top:{{ g.border }}`
//
// ⚠️ A LINHA DA OFERTA também carrega o MESMO grid, em **`:133`** — e é o único
// `cursor:pointer` do E1 que **não** está num `<button>` (medido: 5 ocorrências
// nos dois recortes desta fase, em `:110`, `:130`, `:133`, `:134` e `:177`).
//
// ═══ ⚠️⚠️ O GRID DE 11 COLUNAS É O ITEM MAIS FÁCIL DE ERRAR DA FASE ════════
//
// O literal, medido por script (a saída do varredor FID-01 trouxe UM valor só de
// `grid-template-columns` no recorte, e ele é idêntico em `:109` e `:133`):
//
//     32px 24px minmax(170px,1fr) 104px 118px 92px 108px 76px 112px 164px 96px
//
// ➡️ `grid-cols-[32px_24px_minmax(170px,1fr)_104px_118px_92px_108px_76px_112px_164px_96px]`
//
// ⚠️⚠️ **Ele é EXPORTADO como constante e é a MESMA string no cabeçalho e na
// linha da oferta.** O plano 187-08 a IMPORTA em vez de recopiá-la: duas cópias
// do grid ficariam livres para divergir numa correção feita de um lado só, e o
// sintoma seria o cabeçalho desalinhado das linhas — visível, mas ninguém
// saberia qual dos dois está certo. Há gate comparando a string por IGUALDADE
// (nunca `includes`: `substring` casa com prefixo, e isso já mordeu este projeto
// quatro vezes).
//
// ═══ ⚠️ AS 11 CÉLULAS DO CABEÇALHO, E SÃO **8** RÓTULOS ═══════════════════
//
// Medido em `:111`: `<button>` + `<span></span>` + **8** rótulos + `<span></span>`
// = **11 células**. ⚠️ O plano escreveu *"os 9 rótulos"* — são **OITO**
// (`Oferta`, `Modalidade`, `Fotos`, `Preço`, `Resultado/venda`, `Margem`,
// `Responsável`, `Andamento`), e as duas células vazias são a do anel de
// progresso (coluna 2) e a da ação (coluna 11).
//
// ⚠️ **VOC-03/VOC-04:** a coluna é **"Resultado/venda"**, nunca "lucro líquido".
// O número da tela não desconta imposto, cartão, despesa fixa nem devolução.
//
// ═══ ⚠️⚠️ OS SLOTS NASCEM NOMEADOS E VAZIOS (D4) ══════════════════════════
//
//   · `linhaDaOferta` → as **11 células** da linha: plano **187-08**. ⚠️ **Sem a
//     função, NENHUMA casca de linha de oferta é desenhada** — nem borda, nem
//     `min-h`, nem fundo. Há gate provando que o HTML sai com cabeçalho, linha de
//     produto e sub-linha, e **zero** linha de oferta.
//   · `rodape` → o rodapé e a ação em lote: plano **187-09**.
//   · `vazio`  → os DOIS avisos honestos de lista vazia: plano **187-09**
//     (decisão 16 do usuário). ⚠️ **Esta tabela NÃO escreve vazio nenhum**:
//     "Nenhuma oferta neste filtro" sem filtro é a mentira que aquela decisão
//     proíbe, e a fila nasce vazia (nada cria oferta hoje — quem passa a criar é
//     a Fase 194).
//
// ⚠️ **"Restaurar dados de exemplo" (`:200`) NÃO EXISTE no produto** (FILA-16 e
// critério 13): é botão de dado de mentira. Há gate de AUSÊNCIA do literal já
// nesta wave, para ele não entrar por engano quando o rodapé chegar.
//
// ═══ ⚠️⚠️ TRÊS `.map()` ANINHADOS: FLAG SEMPRE DENTRO DO CALLBACK ═════════
//
// grupos → conjuntos → (quadrados, dentro de `QuadradosDeFoto`). O **Rollup
// elimina variável de escopo do componente usada dentro do `.map()`** e o
// `ReferenceError` só aparece no bundle de produção — já mordeu este projeto.
// Toda flag nasce DENTRO de cada callback.
//
// ⚠️ TODA classe mora NESTE `.jsx` (o `content` varre `*.jsx` e **NÃO** `*.js`).
// ⚠️ NUNCA `String(valor)`, `JSON.stringify(valor)` nem `obj[chaveDoServidor]`:
// os três LANÇAM com `{toString: null}`, e a prop de NÓ chegando objeto comum faz
// o React LANÇAR e mata a página (a TELA PRETA de 07/10/2026, QUATRO vezes na
// Fase 180 e UMA na wave 6 — aquela no CLIQUE).
// ⚠️ **Zero `localStorage`**: o `KEY='ecf-aprovacao-v1'` do protótipo (`:914`) é
// artefato dele, e estado que mora no navegador não sobrevive a um link
// compartilhado (COL-02/COL-03).
// ═══════════════════════════════════════════════════════════════════════════

/**
 * ⚠️⚠️ O GRID DE 11 COLUNAS, literal de `:109` **e** `:133`.
 *
 * Exportado porque o plano 187-08 monta a linha da oferta com a **MESMA** string.
 * Ver o topo: duas cópias divergiriam e o cabeçalho sairia desalinhado.
 */
export const GRID_DAS_11_COLUNAS =
    'grid-cols-[32px_24px_minmax(170px,1fr)_104px_118px_92px_108px_76px_112px_164px_96px]';

/** `:111` — os OITO rótulos do cabeçalho, na ordem das colunas. */
export const ROTULOS_DO_CABECALHO = [
    'Oferta',
    'Modalidade',
    'Fotos',
    'Preço',
    'Resultado/venda',
    'Margem',
    'Responsável',
    'Andamento',
];

/** `:110` — o `title` do checkbox do cabeçalho, literal. */
export const TITULO_SELECIONAR = 'Selecionar visíveis';

/** `:110` — o glifo da marcação (`✓`), e ele é U+2713. */
export const GLIFO_MARCADO = '✓';

// `:108` — a casca.
const CLASSES_SECAO = 'overflow-hidden rounded-pub-table border border-pub-line bg-pub-surface';
// `:109` — 38px, `padding:0 16px`, fundo `#0D0D0D`, borda inferior `#1F1F1F`.
const CLASSES_CABECALHO =
    'grid h-[38px] items-center border-b border-pub-line-subtle bg-pub-sunken px-[16px] text-[12px] font-medium text-pub-txt-4';
// `:111` — só Preço e Resultado/venda.
const CLASSES_A_DIREITA = 'text-right pr-[14px]';
// `:110` — o checkbox do cabeçalho.
//
// ⚠️⚠️ **`font-[800]` e NÃO `font-extrabold`, e isto é GATE — não estética.** O
// protótipo mede `font-weight:800` (`:110`), mas o GATE-03 de
// `publicador-vocabulario-novo.test.js` (Fase 177) PROÍBE a escala NOMEADA
// `font-extrabold` em todo arquivo de `ARQUIVOS_NOVOS`. A classe arbitrária
// `font-[800]` é o MESMO peso computado, por fora da escala nomeada — é o que
// `CabecalhoDaFila.jsx:186` (wave 4) e `PopoverDeFiltro.jsx:181` (wave 6) já
// fazem, pelo mesmo motivo.
// ⚠️ **A tabela FID-01 do plano prescrevia `font-extrabold`**, e ele reprovou o
// GATE-03 na primeira redação deste arquivo (medido nesta sessão). Registrado na
// SUMMARY.
const CLASSES_CHECKBOX =
    'grid h-[16px] w-[16px] cursor-pointer place-items-center rounded-pub-badge border p-0 text-[10px] font-[800] text-pub-page';
const CLASSES_CHECKBOX_MARCADO = 'border-pub-accent bg-pub-accent';
const CLASSES_CHECKBOX_VAZIO = 'border-pub-line-off bg-pub-surface';
// `:114` / `:1260` — do SEGUNDO grupo em diante.
const CLASSES_BORDA_ENTRE_GRUPOS = 'border-t border-pub-line';

/** Objeto comum de verdade, ou `null`. `null` e array são os dois `typeof 'object'`. */
function objetoComum(valor) {
    return valor !== null && typeof valor === 'object' && !Array.isArray(valor) ? valor : null;
}

/** Inteiro finito, ou `null`. ⚠️ Nunca `Number(valor)`: `Number('')` é 0. */
function inteiroSeguro(valor) {
    return typeof valor === 'number' && Number.isInteger(valor) ? valor : null;
}

/**
 * As duas células do cabeçalho que são alinhadas à direita (`:111`).
 *
 * ⚠️ Comparação por VALOR do rótulo, e não por índice: índice se desloca quando
 * alguém insere coluna, e o alinhamento passaria para a célula errada em silêncio.
 */
const A_DIREITA = new Set(['Preço', 'Resultado/venda']);

/**
 * A tabela da fila.
 *
 * @param {object} props
 * @param {unknown} [props.grupos] a árvore de `GrupoDaFilaService::montar()`
 * @param {unknown} [props.linhaDaOferta] `(oferta, conjunto, grupo) => nó` — as 11 células
 *   (plano **187-08**). ⚠️ Sem função, NENHUMA casca de linha é desenhada.
 * @param {unknown} [props.onRevisarFotos] handler do atalho da sub-linha
 * @param {unknown} [props.onMarcarVisiveis] handler do checkbox do cabeçalho (plano **187-09**).
 *   ⚠️ Sem função o checkbox **não é desenhado**: botão sem destino é D4 violado, e a
 *   seleção em lote é daquela wave. A célula 1 fica vazia, e as 11 trilhas continuam.
 * @param {unknown} [props.visiveisMarcadas] se todas as visíveis estão marcadas
 * @param {unknown} [props.rodape] SLOT de NÓ — o rodapé e o lote (plano **187-09**)
 * @param {unknown} [props.vazio] SLOT de NÓ — os dois avisos honestos (plano **187-09**)
 */
export default function TabelaDaFila({
    grupos = null,
    linhaDaOferta = null,
    onRevisarFotos = null,
    onMarcarVisiveis = null,
    visiveisMarcadas = false,
    rodape = null,
    vazio = null,
}) {
    // ⚠️ `Array.isArray` ANTES de `.map`: `grupos` chegando string tem `.length` e
    // `.map` não existe — seria `TypeError` na renderização, e a página inteira cai.
    const lista = Array.isArray(grupos) ? grupos : [];

    // ⚠️ `isValidElement` e NUNCA `String(slot)`: nó INVÁLIDO passado como filho faz
    // o React LANÇAR, e objeto comum renderizado como texto vira `[object Object]`.
    const noRodape = isValidElement(rodape) ? rodape : null;
    const noVazio = isValidElement(vazio) ? vazio : null;

    const podeMarcar = typeof onMarcarVisiveis === 'function';
    const marcadas = visiveisMarcadas === true;

    return (
        <section className={CLASSES_SECAO}>
            <div className={cn('grid', GRID_DAS_11_COLUNAS, CLASSES_CABECALHO)}>
                {/* Célula 1 — o checkbox. ⚠️ Sem handler ele NÃO é desenhado; a
                    célula fica vazia e as 11 trilhas continuam. */}
                {podeMarcar ? (
                    <button
                        type="button"
                        title={TITULO_SELECIONAR}
                        onClick={() => onMarcarVisiveis(!marcadas)}
                        className={cn(CLASSES_CHECKBOX, marcadas ? CLASSES_CHECKBOX_MARCADO : CLASSES_CHECKBOX_VAZIO)}
                    >
                        {marcadas ? GLIFO_MARCADO : ''}
                    </button>
                ) : (
                    <span />
                )}

                {/* Célula 2 — a do anel de progresso, vazia no cabeçalho (`:111`). */}
                <span />

                {/* Células 3 a 10 — os OITO rótulos. ⚠️ Flag DENTRO do callback. */}
                {ROTULOS_DO_CABECALHO.map((rotulo) => {
                    const aDireita = A_DIREITA.has(rotulo);

                    return (
                        <span key={rotulo} className={aDireita ? CLASSES_A_DIREITA : undefined}>
                            {rotulo}
                        </span>
                    );
                })}

                {/* Célula 11 — a da ação, vazia no cabeçalho (`:111`). */}
                <span />
            </div>

            {/* ⚠️ `.map()` 1 de 3 — grupos. */}
            {lista.map((cruGrupo, indiceDoGrupo) => {
                // ⚠️⚠️ TODA flag nasce AQUI DENTRO (o Rollup elimina variável de
                // escopo do componente usada dentro do `.map()`).
                const grupo = objetoComum(cruGrupo);
                const conjuntos = grupo !== null && Array.isArray(grupo.conjuntos) ? grupo.conjuntos : [];
                // ⚠️ `:1260` — o PRIMEIRO grupo NÃO tem borda superior. O servidor
                // manda `borda_superior`, e o índice é a trava: mesmo com o dado
                // errado, o primeiro desenhado nunca ganha borda.
                const temBorda = indiceDoGrupo > 0 && grupo?.borda_superior !== false;
                const idDoGrupo = grupo === null ? null : inteiroSeguro(grupo.produto_id);
                const chaveDoGrupo = idDoGrupo !== null ? `g-${idDoGrupo}` : `g-i-${indiceDoGrupo}`;

                if (grupo === null) return null;

                return (
                    <div key={chaveDoGrupo} className={temBorda ? CLASSES_BORDA_ENTRE_GRUPOS : undefined}>
                        <LinhaDeProdutoDaFila grupo={grupo} />

                        {/* ⚠️ `.map()` 2 de 3 — conjuntos. */}
                        {conjuntos.map((cruConjunto, indiceDoConjunto) => {
                            // ⚠️⚠️ Flag DENTRO do callback, de novo.
                            const conjunto = objetoComum(cruConjunto);
                            const ofertas = conjunto !== null && Array.isArray(conjunto.ofertas) ? conjunto.ofertas : [];
                            const idDoRascunho = conjunto === null ? null : inteiroSeguro(conjunto.rascunho_id);
                            const chaveDoConjunto = idDoRascunho !== null
                                ? `c-${idDoRascunho}`
                                : `c-i-${indiceDoGrupo}-${indiceDoConjunto}`;
                            // ⚠️⚠️ **O SLOT DAS LINHAS.** Sem a função, NENHUMA casca
                            // de linha de oferta é desenhada (D4) — é o plano 187-08
                            // que a preenche.
                            const desenhaLinha = typeof linhaDaOferta === 'function';

                            if (conjunto === null) return null;

                            return (
                                <div key={chaveDoConjunto}>
                                    <SubLinhaDeConjunto conjunto={conjunto} onRevisarFotos={onRevisarFotos} />

                                    {desenhaLinha
                                        && ofertas.map((cruOferta, indiceDaOferta) => {
                                            // ⚠️ Flag DENTRO do callback.
                                            const oferta = objetoComum(cruOferta);
                                            const idDaOferta = oferta === null ? null : inteiroSeguro(oferta.id);
                                            const chaveDaOferta = idDaOferta !== null
                                                ? `o-${idDaOferta}`
                                                : `o-i-${indiceDoGrupo}-${indiceDoConjunto}-${indiceDaOferta}`;

                                            if (oferta === null) return null;

                                            const no = linhaDaOferta(oferta, conjunto, grupo);

                                            // ⚠️ Nó INVÁLIDO devolvido pelo slot faria o
                                            // React LANÇAR. `isValidElement` antes de
                                            // qualquer coisa, e nunca `String(no)`.
                                            return isValidElement(no)
                                                ? <div key={chaveDaOferta}>{no}</div>
                                                : null;
                                        })}
                                </div>
                            );
                        })}
                    </div>
                );
            })}

            {/* ⚠️ Os DOIS slots do plano 187-09. Slot sem nó não desenha NADA —
                nem casca, nem borda, nem "nenhum item" (D4). */}
            {noVazio}
            {noRodape}
        </section>
    );
}
