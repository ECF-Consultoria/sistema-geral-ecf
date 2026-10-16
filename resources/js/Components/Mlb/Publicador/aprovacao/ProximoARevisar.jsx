import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 05 — A FAIXA "PRÓXIMO A REVISAR" (FILA-04, AND-05, AND-10).
//
// É a única peça da fila que **decide por quem está olhando**: ela escolhe (no
// servidor) qual oferta o operador abre primeiro e oferece o atalho para ela.
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas, sha256 `68624883ce35547e`). Linhas MEDIDAS com `grep -n` nesta
// sessão (16/10/2026) — ⚠️ **os OITO ponteiros do plano estavam deslocados em
// +1**, e as medidas abaixo são as certas:
//
//   elemento        plano   MEDIDO
//   `<sc-if>`       —       :53   (`{{ Q.hasNext }}` — a faixa INTEIRA sai)
//   faixa           :53     :54   ⚠️ deslocado
//   miniatura       :54     :55   ⚠️ deslocado
//   coluna          :55     :56   ⚠️ deslocado
//   eyebrow         :56     :57   ⚠️ deslocado
//   nome            :57     :58   ⚠️ deslocado
//   sub             :58     :59   ⚠️ deslocado
//   estado + dot    :59     :61   ⚠️ deslocado em +2 (`:60` fecha a coluna)
//   botão           :60     :62   ⚠️ deslocado em +2
//
// ⚠️⚠️ **OMISSÃO NA TABELA DO PLANO, MEDIDA:** o botão declara
// `white-space:nowrap` — é a **PRIMEIRA** propriedade dele em `:62` — e a classe
// prescrita no plano não a tem. "Revisar agora" quebraria em duas linhas dentro
// de um botão de 36px fixos. A tabela de fidelidade deste projeto não erra
// VALOR: erra por OMISSÃO e por REFERÊNCIA.
//
// ═══ ⚠️ A MINIATURA É 46px AQUI — e a da LINHA é 44px ══════════════════════
//
// `:55` é `46×46` com raio `9px`; a miniatura da linha da oferta é `44×44`
// (`:138`). São dois blocos diferentes e o protótipo os pinta diferentes —
// "deixei igual" é **FID-02**, justificativa VETADA.
//
// ═══ ⚠️⚠️ A COR NUNCA É O ÚNICO SINAL ══════════════════════════════════════
//
// O protótipo pinta o estado (`:61`) com `stateColor` (`#4ADE80` aprovável, `#FBBF24`
// com pendência, `:1222`) e desenha um dot da MESMA cor. ➡️ Aqui o **texto diz
// o estado** ("Pronto para aprovar" / "N pendências") e o dot acompanha: quem
// não distingue as duas cores lê a frase. E **sem texto o bloco inteiro não
// aparece** — um dot colorido sozinho seria cor como único sinal.
//
// ═══ ⚠️⚠️ O BOTÃO NUNCA NASCE SEM DESTINO ═════════════════════════════════
//
// `onRevisar` TEM de ser função **e** `proximo.id` TEM de ser inteiro: sem os
// dois o botão **não é desenhado**. Botão decorativo é D4 violado, e
// `href="#"`/`onClick` vazio é a mesma dívida com outra roupa. O resto da faixa
// continua, porque nome, descrição e estado são informação MEDIDA.
//
// ⚠️ **Para onde ele vai HOJE:** a página monta o destino como a própria fila
// com a oferta na URL (`?oferta=<id>`, COL-03) — uma rota que EXISTE e responde
// 200. O painel de revisão é a **Fase 188** e a revisão detalhada a **190/191**;
// quando o painel existir, é a página que troca o destino, não este componente.
// ⚠️ O realce da linha correspondente é do plano **187-08** (a tabela ainda não
// existe nesta tela).
//
// ═══ ⚠️ A MINIATURA: O LUGAR VAZIO É O PROTÓTIPO ═══════════════════════════
//
// O quadrado de 46px com `background:#1A1A1A;color:#52525B` É o que o protótipo
// desenha (`:55`, com `<image-slot placeholder=" ">` dentro) — não é um
// placeholder inventado aqui. ⚠️ E o **servidor desta wave não manda
// `miniatura`**: a foto de capa vem do acervo, e `ImagemAssetService`/`Acervo*`
// são território PROIBIDO (a v28 acabou de virar o acervo para o R2 em
// produção). O campo é ponto de extensão DECLARADO, com os dois ramos testados:
// endereço `https://` desenha a foto, qualquer outra coisa desenha o lugar
// vazio. ⚠️ **Nunca `<img src="undefined">`.**
//
// ⚠️ O filtro `https://` é o mesmo de `MiniaturaDoProduto.jsx:21`
// (`capaSegura`), e não é zelo: `javascript:` e `data:` num `src` vindo do
// servidor são execução de conteúdo de terceiro na nossa página. Aquele
// componente **não** é importado aqui porque ele fala o vocabulário ANTIGO
// (`border-white/[0.08]`, `rounded-lg`), que FID-04 veta nesta tela.
//
// ═══ ⚠️ A ESPERA JÁ CHEGA PRONTA ══════════════════════════════════════════
//
// `sub` é a frase montada no SERVIDOR (`ProximoARevisarService::frase()`), com o
// rótulo de espera já formatado. ⚠️ **Zero `new Date()` aqui**: `Invalid Date` e
// o deslocamento de fuso de data-only em BRT já morderam este projeto duas
// vezes, e o cabeçalho da Fase 180 RECUSA rótulo com cara de data crua.
//
// ⚠️ TODA classe mora NESTE `.jsx`: o `content` do `tailwind.config.js` varre
// `./resources/js/**/*.jsx` e **NÃO** `*.js`. Classe escrita num `.js` não gera
// CSS e NÃO EXISTE PARA A TELA, com todo teste de render passando.
//
// ⚠️ NUNCA `String(valor)` nem `JSON.stringify(valor)` sobre prop: o
// `String({toString: null})` LANÇA e derruba a página inteira (a TELA PRETA de
// 07/10/2026, que voltou QUATRO vezes na Fase 180). Todo campo passa por guarda
// de TIPO antes de chegar ao JSX.
// ═══════════════════════════════════════════════════════════════════════════

/** Os literais de tela, byte a byte como o protótipo os escreve. */
export const EYEBROW = 'PRÓXIMO A REVISAR';
export const ROTULO_BOTAO = 'Revisar agora';

/**
 * Objeto comum de verdade, ou `null`.
 *
 * ⚠️ `null` e array são os dois `typeof 'object'` que precisam de guarda
 * própria: sem elas, `proximo` chegando `[]` passaria por `typeof` e cada
 * `.campo` daria `undefined`, que é o caminho do `undefined` na tela.
 */
function objetoComum(valor) {
    return valor !== null && typeof valor === 'object' && !Array.isArray(valor) ? valor : null;
}

/**
 * Texto não-vazio, ou `null`.
 *
 * ⚠️ `typeof` ANTES de qualquer coisa, e nunca `String(valor)`: `textoSeguro(NaN)`
 * devolveria a STRING `'NaN'`, que iria para a tela.
 */
function textoNaoVazio(valor) {
    if (typeof valor !== 'string') return null;

    return valor.trim() === '' ? null : valor;
}

/**
 * Inteiro finito NÃO-NEGATIVO, ou `null`.
 *
 * ⚠️ NUNCA `Number(valor)`: `Number('')` é 0 e `Number(null)` é 0, e um id de
 * oferta igual a 0 montaria uma rota inventada. `Number.isInteger` já recusa
 * `NaN`, `Infinity` e `1.5`.
 */
function inteiroNaoNegativo(valor) {
    return typeof valor === 'number' && Number.isInteger(valor) && valor >= 0 ? valor : null;
}

/** O endereço de imagem em forma segura: só `https://` em texto. */
function imagemSegura(valor) {
    return typeof valor === 'string' && valor.startsWith('https://') ? valor : null;
}

// As classes, escritas uma vez e literalmente — é daqui que o Tailwind as lê.
const CLASSES_FAIXA = 'flex items-center gap-[14px] rounded-pub-panel border border-pub-accent/[0.35] bg-pub-row-new px-[12px] py-[10px]';
const CLASSES_MINIATURA = 'relative h-[46px] w-[46px] flex-none overflow-hidden rounded-pub-control bg-pub-hover-3 text-pub-txt-off';
// ⚠️ `h-full w-full object-cover` é TRADUÇÃO declarada, não medida: o
// protótipo usa `<image-slot>`, um elemento da ferramenta de desenho que não
// tem CSS próprio. O recorte por `object-cover` dentro do `overflow-hidden` de
// 46px é o que `MiniaturaDoProduto.jsx:50` já faz neste módulo.
const CLASSES_IMAGEM = 'h-full w-full object-cover';
const CLASSES_COLUNA = 'flex min-w-0 flex-col gap-[3px]';
const CLASSES_EYEBROW = 'text-[10.5px] font-bold tracking-[0.08em] text-pub-accent';
const CLASSES_NOME = 'text-[14px] font-semibold';
const CLASSES_SUB = 'text-[12.5px] text-pub-txt-2';
const CLASSES_ESTADO = 'ml-auto flex items-center gap-[7px] whitespace-nowrap text-[12.5px] font-medium';
const CLASSES_ESTADO_OK = 'text-pub-ok-txt';
const CLASSES_ESTADO_PENDENTE = 'text-pub-warn-txt';
const CLASSES_DOT = 'h-[7px] w-[7px] rounded-full';
const CLASSES_DOT_OK = 'bg-pub-ok-txt';
const CLASSES_DOT_PENDENTE = 'bg-pub-warn-txt';
const CLASSES_BOTAO = 'h-[36px] cursor-pointer whitespace-nowrap rounded-pub-control bg-pub-accent px-[16px] text-[13px] font-semibold text-pub-page hover:bg-pub-accent-hover';

/**
 * A faixa "PRÓXIMO A REVISAR".
 *
 * ⚠️ **Sem `proximo` a faixa INTEIRA não aparece** — nem borda, nem eyebrow,
 * nem casca (D4). É o `<sc-if value="{{ Q.hasNext }}">` do protótipo (`:53`), e
 * é o servidor que decide: `ProximoARevisarService::para()` devolve `null`
 * quando não há oferta ao vivo.
 *
 * @param {object} props
 * @param {unknown} [props.proximo] `{id, nome, sub, estado, aprovavel, pendencias, espera_minutos, miniatura}`
 * @param {unknown} [props.onRevisar] o handler da página; sem ele o botão NÃO é desenhado
 */
export default function ProximoARevisar({ proximo = null, onRevisar = null }) {
    const dado = objetoComum(proximo);

    if (dado === null) return null;

    const id = inteiroNaoNegativo(dado.id);
    const nome = textoNaoVazio(dado.nome);
    const sub = textoNaoVazio(dado.sub);
    const estado = textoNaoVazio(dado.estado);
    const foto = imagemSegura(dado.miniatura);

    // ⚠️ Igualdade ESTRITA com `true`: `aprovavel` chegando `'sim'`, `1` ou `{}`
    // não é "aprovável". Dizer "Pronto para aprovar" por truthiness seria uma
    // afirmação FALSA sobre uma oferta que ninguém conferiu.
    const aprovavel = dado.aprovavel === true;

    // ⚠️ Botão só com função E destino: `onRevisar(id)` precisa do id de verdade.
    const temDestino = typeof onRevisar === 'function' && id !== null;

    return (
        <div className={CLASSES_FAIXA}>
            {/* ⚠️ O quadrado de 46px É o protótipo (`:55`), e `aria-hidden`
                porque o nome do produto vem escrito ao lado. */}
            <span aria-hidden="true" className={CLASSES_MINIATURA}>
                {foto !== null && <img src={foto} alt="" loading="lazy" decoding="async" className={CLASSES_IMAGEM} />}
            </span>

            <div className={CLASSES_COLUNA}>
                <span className={CLASSES_EYEBROW}>{EYEBROW}</span>
                {/* ⚠️ Linha sem dado NÃO aparece — nada de "—" nem de rótulo
                    genérico ocupando o lugar de uma informação que não existe. */}
                {nome !== null && <span className={CLASSES_NOME}>{nome}</span>}
                {sub !== null && <span className={CLASSES_SUB}>{sub}</span>}
            </div>

            {/* ⚠️ O bloco do estado só existe COM TEXTO: dot colorido sozinho
                seria cor como único sinal. */}
            {estado !== null && (
                <span className={cn(CLASSES_ESTADO, aprovavel ? CLASSES_ESTADO_OK : CLASSES_ESTADO_PENDENTE)}>
                    <span aria-hidden="true" className={cn(CLASSES_DOT, aprovavel ? CLASSES_DOT_OK : CLASSES_DOT_PENDENTE)} />
                    {estado}
                </span>
            )}

            {temDestino && (
                <button type="button" onClick={() => onRevisar(id)} className={CLASSES_BOTAO}>
                    {ROTULO_BOTAO}
                </button>
            )}
        </div>
    );
}
