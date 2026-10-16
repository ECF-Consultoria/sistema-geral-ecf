import { cn } from '@/lib/utils';
import QuadradosDeFoto from './QuadradosDeFoto';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 07 — A SUB-LINHA DE CONJUNTO DE FOTOS (nível 2, FILA-08).
//
// A faixa de 38px que pendura as ofertas do MESMO conjunto embaixo da linha de
// produto: o rótulo ("Unidade" / "Combo ×N"), quantas fotos são e quantas são de
// IA, os mini-quadrados de 10px, o "x/y aprovadas", o pill da aprovação
// compartilhada e o atalho "Revisar fotos do conjunto".
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas, sha256 `68624883ce35547e`). ⚠️ **Os ponteiros abaixo foram
// MEDIDOS com `awk`/`sed` nesta sessão (16/10/2026)** — e a tabela do plano
// acerta esta faixa (do `:120` em diante ela está certa), mas **OMITE uma
// propriedade**:
//
//   · sub-linha            → `:124`  `display:flex;align-items:center;gap:10px;`
//                                    `height:38px;padding:0 16px 0 62px;`
//                                    `border-top:1px solid #1C1C1C;background:#0F0F0F;`
//                                    `font-size:12px;color:#A1A1AA`
//   · rótulo do conjunto   → `:125`  `font-weight:600;color:#D4D4D8`
//   · separador "·"        → `:126`  `color:#3F3F46`  (+ o texto das fotos, sem estilo próprio)
//   · quadrados (10px)     → `:127`  `display:flex;gap:3px;margin-left:4px`
//   · "x/y aprovadas"      → `:128`  `color:{{ st.progColor }};font-weight:500`
//   · pill compartilhado   → `:129`  `gap:6px;height:22px;padding:0 8px;border-radius:999px;`
//                                    `border:1px solid #2E2E2E;color:#D4D4D8;white-space:nowrap`
//   · "Revisar fotos…"     → `:130`  `white-space:nowrap;margin-left:auto;height:26px;`
//                                    `padding:0 10px;border:1px solid #2E2E2E;border-radius:7px;`
//                                    `background:#111111;color:#D4D4D8;font-size:12px;cursor:pointer`
//                                    + `style-hover="border-color:#3F3F46"`
//
// ⚠️⚠️ **OMISSÃO MEDIDA NA TABELA DO PLANO:** o botão de `:130` declara
// `white-space:nowrap` — e ela é a **PRIMEIRA** propriedade dele —, e a classe
// prescrita no plano **não a tem**. "Revisar fotos do conjunto" quebraria em duas
// linhas dentro de um botão de 26px fixos. A tabela de fidelidade deste projeto
// não erra VALOR: **erra por OMISSÃO e por REFERÊNCIA** (`white-space` aparece
// 12 vezes nos dois recortes desta fase, medido por script).
//
// ═══ ⚠️⚠️ `pub-sunken-2` ACORDA AQUI, NO LUGAR ONDE FOI MEDIDO ═════════════
//
// O token `pub-sunken-2` (`#0F0F0F`) nasceu no plano 187-01 e ficou em **skip
// nomeado** porque nenhum `.jsx` o usava. O comentário que o criou aponta
// **`Aprovacao.dc.html:124`** — que é exatamente esta faixa. ➡️ É esta wave que o
// acorda, **no lugar onde ele foi medido**.
// ⚠️ Ele fica ENTRE `pub-sunken` (#0D0D0D) e `pub-raised-0` (#131313), e o
// protótipo pinta os TRÊS distintos: com `sunken` a sub-linha some dentro do
// rodapé da tabela; com `raised-0` ela sobe acima da linha de grupo e INVERTE a
// hierarquia visual. "Usar o token parecido que já existe" é **FID-02**.
// ⚠️⚠️ E os tokens `pub-float`/`pub-float-line` **NÃO** acordam aqui: medido no
// `tailwind.config.js`, eles foram declarados para a **barra de lote** (`:897`) e
// o **toast** (`:905`), que são do plano **187-09**. Usar token fora do lugar
// medido só para zerar um `skip` é o que a wave 4 recusou — e estava certa.
//
// ═══ ⚠️ O PILL SÓ COM CONJUNTO COMPARTILHADO ══════════════════════════════
//
// *"Mesmas fotos em Clássico e Premium · aprovação compartilhada"* é **afirmação
// sobre o conjunto**: ele só aparece quando o conjunto tem **mais de uma** oferta
// (`shared: setOffers(sk).length > 1`, `:1262`). Num conjunto de uma oferta só a
// frase seria **FALSA** — e quem decide é o SERVIDOR, contando as ofertas DO
// RASCUNHO (nunca as filtradas: filtrar por "Premium" não desfaz o
// compartilhamento). Ver `GrupoDaFilaService`.
//
// ═══ ⚠️ A COR NUNCA É O ÚNICO SINAL (VOC-05) ═══════════════════════════════
//
// O "x/y aprovadas" é verde quando `x === y` e âmbar quando falta foto — mas o
// **TEXTO já diz os dois números**, então quem não distingue as duas cores lê a
// razão. ⚠️ E **sem texto o bloco não aparece**: um trecho colorido sem número
// seria cor como único sinal.
//
// ⚠️ TODA classe mora NESTE `.jsx` (o `content` varre `*.jsx` e **NÃO** `*.js`).
// ⚠️ **Nenhuma classe montada por concatenação** — o scanner do Tailwind não vê
// `pl-[${n}px]`, e a faixa sairia sem recuo na tela com o render verde.
// ⚠️ NUNCA `String(valor)`, `JSON.stringify(valor)` nem `obj[chaveDoServidor]`.
// ═══════════════════════════════════════════════════════════════════════════

/** `:129` — o literal do pill, byte a byte, com o separador U+00B7. */
export const PILL_COMPARTILHADO = 'Mesmas fotos em Clássico e Premium · aprovação compartilhada';

/** `:130` — o rótulo do atalho. */
export const ROTULO_REVISAR_FOTOS = 'Revisar fotos do conjunto';

/** `:126` — o separador entre o rótulo e o texto das fotos: U+00B7. */
export const SEPARADOR = '·';

// ⚠️ `:124` — 38px, recuo de 62px à esquerda e 16px à direita (`padding:0 16px 0 62px`),
// a borda superior `#1C1C1C` e o fundo `#0F0F0F`. O `pl-[62px]` é o que alinha a
// sub-linha embaixo do tile de 34px da linha de produto (16 + 34 + 12 = 62).
const CLASSES_FAIXA =
    'flex h-[38px] items-center gap-[10px] border-t border-pub-line-subtle-2 bg-pub-sunken-2 pl-[62px] pr-[16px] text-[12px] text-pub-txt-2';
const CLASSES_ROTULO = 'font-semibold text-pub-fase1';
const CLASSES_SEPARADOR = 'text-pub-line-off';
// `:127` — o `margin-left:4px` é da FILA de quadrados, não do quadrado.
const CLASSES_QUADRADOS = 'ml-[4px]';
const CLASSES_PROGRESSO = 'font-medium';
const CLASSES_PROGRESSO_OK = 'text-pub-ok-txt';
const CLASSES_PROGRESSO_PENDENTE = 'text-pub-warn-txt';
const CLASSES_PILL =
    'flex h-[22px] items-center gap-[6px] whitespace-nowrap rounded-full border border-pub-line-strong px-[8px] text-pub-fase1';
// ⚠️ `whitespace-nowrap` é a OMISSÃO medida na tabela do plano — ver o topo.
const CLASSES_BOTAO =
    'ml-auto h-[26px] cursor-pointer whitespace-nowrap rounded-pub-control-sm border border-pub-line-strong bg-pub-surface px-[10px] text-[12px] text-pub-fase1 hover:border-pub-line-off';

/** Objeto comum de verdade, ou `null`. `null` e array são os dois `typeof 'object'`. */
function objetoComum(valor) {
    return valor !== null && typeof valor === 'object' && !Array.isArray(valor) ? valor : null;
}

/** Texto não-vazio, ou `null`. ⚠️ Nunca `String(valor)`, que LANÇA com `{toString:null}`. */
function textoNaoVazio(valor) {
    if (typeof valor !== 'string') return null;

    return valor.trim() === '' ? null : valor;
}

/**
 * A sub-linha de UM conjunto de fotos.
 *
 * ⚠️ **Sem dado a faixa INTEIRA não aparece** (D4): nem casca, nem borda, nem
 * "nenhum item". Quem decide o que existe é o servidor.
 *
 * @param {object} props
 * @param {unknown} [props.conjunto] `{rotulo, fotos, quadrados, progresso, completo, compartilhado}`
 * @param {unknown} [props.onRevisarFotos] handler do atalho; sem função, o botão NÃO é desenhado
 */
export default function SubLinhaDeConjunto({ conjunto = null, onRevisarFotos = null }) {
    const dado = objetoComum(conjunto);

    if (dado === null) return null;

    const rotulo = textoNaoVazio(dado.rotulo);
    const fotos = textoNaoVazio(dado.fotos);
    const progresso = textoNaoVazio(dado.progresso);

    // ⚠️ Igualdade ESTRITA com `true` nas duas: `completo` chegando `'sim'`/`1`/`{}`
    // pintaria de verde um conjunto com foto pendente, e `compartilhado` por
    // truthiness afirmaria o compartilhamento onde ele não existe.
    const completo = dado.completo === true;
    const compartilhado = dado.compartilhado === true;

    const temAtalho = typeof onRevisarFotos === 'function';

    return (
        <div className={CLASSES_FAIXA}>
            {rotulo !== null && <span className={CLASSES_ROTULO}>{rotulo}</span>}

            {/* ⚠️ O separador só existe ENTRE duas coisas: sem o texto das fotos
                ele seria um "·" solto na faixa. */}
            {rotulo !== null && fotos !== null && (
                <span aria-hidden="true" className={CLASSES_SEPARADOR}>
                    {SEPARADOR}
                </span>
            )}
            {fotos !== null && <span>{fotos}</span>}

            <QuadradosDeFoto quadrados={dado.quadrados} tamanho={10} className={CLASSES_QUADRADOS} />

            {/* ⚠️ O progresso só com TEXTO: a cor não é o único sinal. */}
            {progresso !== null && (
                <span className={cn(CLASSES_PROGRESSO, completo ? CLASSES_PROGRESSO_OK : CLASSES_PROGRESSO_PENDENTE)}>
                    {progresso}
                </span>
            )}

            {/* ⚠️ `:1262` — só com MAIS DE UMA oferta no conjunto. Num conjunto de
                uma oferta só a frase seria FALSA. */}
            {compartilhado && <span className={CLASSES_PILL}>{PILL_COMPARTILHADO}</span>}

            {/* ⚠️ Botão sem destino é D4 violado: sem handler ele não é desenhado,
                em vez de um `onClick` vazio ou um `href="#"`. */}
            {temAtalho && (
                <button type="button" onClick={() => onRevisarFotos(dado)} className={CLASSES_BOTAO}>
                    {ROTULO_REVISAR_FOTOS}
                </button>
            )}
        </div>
    );
}
