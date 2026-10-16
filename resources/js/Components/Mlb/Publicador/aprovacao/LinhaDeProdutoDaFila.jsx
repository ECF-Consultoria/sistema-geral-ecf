import TileDoProduto from '../identidade/TileDoProduto';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 07 — A LINHA DE PRODUTO DA FILA (nível 1, FILA-07).
//
// A faixa de 52px que abre cada grupo: o tile do produto, o nome, a tag
// PRODUTO, o SKU, "N ofertas" e — **só quando é verdade** — a frase do cadastro.
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas, sha256 `68624883ce35547e`). ⚠️⚠️ **OS PONTEIROS DO PLANO
// ESTAVAM DESLOCADOS EM 6 DOS 7 DESTA LINHA** — os certos foram MEDIDOS com
// `awk`/`sed` nesta sessão (16/10/2026):
//
//   elemento              plano   MEDIDO
//   linha de produto      :110    **:115**   `display:flex;align-items:center;gap:12px;`
//                                            `height:52px;padding:0 16px;background:#151515`
//   tile                  :111    **:116**   `34×34;border-radius:8px;font-size:11px;`
//                                            `font-weight:600;flex:none`
//   nome (+ wrapper)      :112    **:117**   `display:flex;align-items:center;gap:8px;`
//                                            `font-size:14px;font-weight:600`
//   tag PRODUTO           :112    **:117**   `font-size:10px;font-weight:600;`
//                                            `letter-spacing:0.06em;color:#8A8A90;`
//                                            `padding:2px 6px;border:1px solid #2E2E2E;`
//                                            `border-radius:4px`
//   SKU                   :113    **:118**   `font-family:'Geist Mono';font-size:11.5px;color:#8A8A90`
//   "N ofertas"           :114    **:119**   `font-size:12px;color:#71717A`
//   frase do cadastro     :120      :120 ✓   `margin-left:auto;font-size:12px;color:#8A8A90`
//
// ⚠️⚠️ **OMISSÃO MEDIDA NA TABELA DO PLANO:** ele prescreve o nome como
// `text-[14px] font-semibold` e **esquece o WRAPPER** — `:117` é um `<span>`
// `display:flex;align-items:center;gap:8px` que embrulha o nome **e** a tag
// PRODUTO. Sem ele a tag cairia no `gap:12px` da linha, não no `gap:8px` dela, e
// ficaria 4px longe do nome. A tabela de fidelidade deste projeto **erra por
// OMISSÃO e por REFERÊNCIA**, quase nunca por valor.
//
// ═══ ⚠️ IDENT-08 — AS INICIAIS E O TOM NÃO NASCEM AQUI ════════════════════
//
// O tile é `identidade/TileDoProduto.jsx`, que **já** tem `iniciais()` e
// `tomDoProduto()` (fonte ÚNICA). ⚠️ Cunhar as iniciais de novo foi **medido como
// segunda implementação divergente** na wave 6 (*"Ana de Souza Lima"* dava **AL**
// num lado e **AS** no outro) e a chave `iniciais` **SAIU** do payload do
// servidor. ➡️ Aqui chegam `produto_id` (o hue estável) e `nome`.
//
// ⚠️ **`letra="text-[11px]"` é OBRIGATÓRIO e NÃO é capricho:** a escada de
// `TileDoProduto` devolve `text-[10.5px]` para lado ≤ 34, e o protótipo mede
// **11px** neste tile (`:116`). A prop `letra` existe exatamente para a tela
// dizer o corpo quando ela sai da escada (foi acrescentada no plano 178-02 pelo
// mesmo motivo, no tile de 34px do modal "Nova publicação"). Mexer na escada
// mudaria silenciosamente quatro telas já conferidas.
//
// ═══ ⚠️⚠️ A FRASE DO CADASTRO SÓ APARECE QUANDO É VERDADE ═════════════════
//
// *"Cadastro completo · categoria e ficha técnica organizadas"* (`:120`) é
// **afirmação sobre o cadastro**, e D4 / EXMP-01 proíbem imprimi-la como
// decoração (decisão do usuário de 15/10/2026). Quem mede é o SERVIDOR
// (`GrupoDaFilaService::fraseDoCadastro()`: categoria preenchida **E** ficha com
// atributo **E** nenhum marcado para revisar); **não sendo verdade, `cadastro`
// chega `null` e a frase NÃO é desenhada** — nunca o texto fixo, nunca um
// "pendente" ocupando o lugar dela.
//
// ═══ ⚠️ A BORDA SUPERIOR ENTRE GRUPOS ═════════════════════════════════════
//
// `:114` é `border-top:{{ g.border }}`, e `:1260` define: o **PRIMEIRO** grupo
// **não** tem borda (`'none'`), os seguintes têm `1px solid #262626`. É o detalhe
// que a tabela de fidelidade omite com mais facilidade — e quem o desenha é
// `TabelaDaFila`, porque a borda é do EMBRULHO do grupo, não desta faixa.
//
// ⚠️ TODA classe mora NESTE `.jsx` (o `content` varre `*.jsx` e **NÃO** `*.js`).
// ⚠️ NUNCA `String(valor)`, `JSON.stringify(valor)` nem `obj[chaveDoServidor]`:
// os três LANÇAM com `{toString: null}` (a TELA PRETA de 07/10/2026).
// ═══════════════════════════════════════════════════════════════════════════

/** `:117` — a tag, em caixa alta, literal. */
export const TAG_PRODUTO = 'PRODUTO';

// `:115` — 52px, `gap:12px`, `padding:0 16px`, fundo `#151515`.
const CLASSES_FAIXA = 'flex h-[52px] items-center gap-[12px] bg-pub-raised px-[16px]';
// `:117` — ⚠️ O WRAPPER que o plano OMITE: `gap:8px` entre o nome e a tag.
const CLASSES_NOME_E_TAG = 'flex min-w-0 items-center gap-[8px] text-[14px] font-semibold';
const CLASSES_NOME = 'truncate';
const CLASSES_TAG =
    'flex-none rounded-pub-badge border border-pub-line-strong px-[6px] py-[2px] text-[10px] font-semibold tracking-[0.06em] text-pub-txt-3';
const CLASSES_SKU = 'flex-none font-pub-mono text-[11.5px] text-pub-txt-3';
const CLASSES_CONTAGEM = 'flex-none text-[12px] text-pub-txt-4';
// `:120` — `margin-left:auto`, e o `truncate` existe porque a frase é longa e a
// linha de produto não pode crescer além dos 52px.
const CLASSES_CADASTRO = 'ml-auto truncate text-[12px] text-pub-txt-3';

/** Objeto comum de verdade, ou `null`. `null` e array são os dois `typeof 'object'`. */
function objetoComum(valor) {
    return valor !== null && typeof valor === 'object' && !Array.isArray(valor) ? valor : null;
}

/** Texto não-vazio, ou `null`. ⚠️ Nunca `String(valor)`. */
function textoNaoVazio(valor) {
    if (typeof valor !== 'string') return null;

    return valor.trim() === '' ? null : valor;
}

/**
 * A linha de produto (nível 1) de UM grupo da fila.
 *
 * ⚠️ **Sem dado a faixa INTEIRA não aparece** (D4).
 *
 * @param {object} props
 * @param {unknown} [props.grupo] `{produto_id, nome, sku, contagem, cadastro}`
 */
export default function LinhaDeProdutoDaFila({ grupo = null }) {
    const dado = objetoComum(grupo);

    if (dado === null) return null;

    const nome = textoNaoVazio(dado.nome);
    const sku = textoNaoVazio(dado.sku);
    const contagem = textoNaoVazio(dado.contagem);
    const cadastro = textoNaoVazio(dado.cadastro);

    // ⚠️ O id do tile só vale como inteiro ou texto: objeto/NaN/array viram
    // `null` e o `tomDoProduto(null)` cai no hue 0 sem lançar. Nunca
    // `String(dado.produto_id)`.
    const idDoTile = typeof dado.produto_id === 'number' && Number.isInteger(dado.produto_id)
        ? dado.produto_id
        : textoNaoVazio(dado.produto_id);

    return (
        <div className={CLASSES_FAIXA}>
            {/* ⚠️ `letra` sai da escada de propósito: o protótipo mede 11px aqui
                (`:116`) e a escada devolveria 10.5px. Ver o topo. */}
            <TileDoProduto id={idDoTile} nome={nome} tamanho={34} raio={8} letra="text-[11px]" />

            {/* ⚠️ O wrapper de `gap:8px` existe mesmo sem nome: é ele que carrega
                a tag PRODUTO, que é do GRUPO e não do nome. */}
            <span className={CLASSES_NOME_E_TAG}>
                {nome !== null && <span className={CLASSES_NOME}>{nome}</span>}
                <span className={CLASSES_TAG}>{TAG_PRODUTO}</span>
            </span>

            {/* ⚠️ Linha sem dado NÃO aparece — nada de "—" ocupando o lugar de uma
                informação que não existe. */}
            {sku !== null && <span className={CLASSES_SKU}>{sku}</span>}
            {contagem !== null && <span className={CLASSES_CONTAGEM}>{contagem}</span>}

            {/* ⚠️⚠️ Só quando é VERDADE. Ver o topo. */}
            {cadastro !== null && <span className={CLASSES_CADASTRO}>{cadastro}</span>}
        </div>
    );
}
