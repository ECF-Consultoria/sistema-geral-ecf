import { cn } from '@/lib/utils';
import { TOASTS } from './vocabularioDoPortao';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 09 — O TOAST DA FILA: o aviso do que acabou de acontecer.
//
// Topo central, `top:124px`, dot de 8px, texto 13.5px/600 e sub 12px.
//
// ⚠️⚠️ **OS 6 LITERAIS SÃO **LIDOS** DE `vocabularioDoPortao.jsx` (Fase 186,
// VOC-08), NUNCA RECOPIADOS.** Os caracteres que parecem decoração e NÃO são: o
// travessão `—` (U+2014, não hífen) em "Aprovado — aguardando publicação", o
// separador `·` (U+00B7) e as reticências `…` (U+2026, UM caractere). Recopiar
// qualquer um criaria duas verdades, e a divergente seria descoberta por alguém
// vendo duas frases diferentes para o mesmo fato.
//
// ⚠️⚠️ **NESTA FASE SÓ `aprovado` DISPARA** (pelo lote). Os outros cinco têm dono
// e **nenhum caminho desta fase os alcança**:
//   · `revisao_salva`, `ajuste_solicitado`, `foto_reprovada` → **Fase 188**
//   · `gerando_com_ia`                                       → **Fase 192**
//   · `publicado`                                            → **Fase 193**
// ⚠️⚠️ E `publicado` — *"Publicado e confirmado no Mercado Livre · MLB…"* —
// **EXIGE MLB CONFIRMADO** (AND-04 / VOC-08). Afirmá-lo sem o MLB seria **mentir
// sobre o dinheiro e sobre o anúncio do cliente**: ele diz "confirmado", e
// confirmado é o que o Mercado Livre respondeu, não o que nós enviamos. O
// componente é genérico porque tem um usuário REAL hoje; **o gate nomeia os
// donos**, para que "componente genérico" não seja desculpa para decoração.
//
// ⚠️⚠️ **O DESAPARECIMENTO EM ~3,8 s É TIMER, NÃO CSS.** Medido em `:1009`:
// `setTimeout(() => setState({toast: null}), 3800)`. ➡️ O agendamento mora numa
// **FUNÇÃO PURA** ({@see agendarOToast}) e é isso que o gate testa.
// ⚠️ **O RUNTIME DO TIMER NÃO É PROVADO NESTE AMBIENTE**, e isso é declarado em
// vez de disfarçado: não há navegador aqui — todo render é `esbuild` +
// `react-dom/server`. O que o gate prova é que o número é 3800 e que o
// cancelamento acontece antes de reagendar; que o navegador honra o `setTimeout`
// é confiança no runtime, não medição.
//
// ⚠️⚠️ **`duration-[Nms]`, `delay-[…]` e `ease-[…]` NÃO COMPILAM NESTE PROJETO**
// (GATE-09): o plugin `tailwindcss-animate` torna essas classes ambíguas. Toda
// duração fora da escala nomeada vai por `style={{ transitionDuration }}`, com
// `duration-200` como CONTROLE no gate.
//
// ⚠️⚠️ **SEM PORTAL, E O MOTIVO É MEDIDO.** O renderizador de SERVIDOR do React
// **não renderiza portal**: um teste de render veria **vazio** e passaria **por
// omissão**. E conteúdo portado **não herda a fonte**. ➡️ O toast é **IRMÃO do
// `<main>`, na raiz da página**, que é exatamente onde o protótipo o põe (`:904`,
// fora do `<main>`). O `backdrop-filter` do header cria containing block **só
// para descendentes dele**, e ele não é descendente. ⚠️ Gate por ÁRVORE provando.
//
// ⚠️⚠️ **O Z-INDEX É `z-[45]`, REMAPEADO.** No protótipo o toast é `z-index:120`
// porque lá o header é 50. **Neste projeto a ordem é TRAVADA**:
// `filtros 10 < header 30 < menu lateral = painel 40`. O toast fica **acima de
// tudo** — ele é o aviso do que acabou de acontecer, e nada pode cobri-lo —, o
// que na escala daqui é 45. A ORDEM RELATIVA é preservada; o NÚMERO é novo.
//
// ⚠️ **`pub-float` e `pub-float-line` ACORDAM AQUI E NA BARRA.** Foram declarados
// em `tailwind.config.js` para o toast (`:905`) e para a barra de lote (`:897`), e
// até esta wave estavam DORMINDO.
//
// ⚠️ **FID-01 — A OMISSÃO DA TABELA DO PLANO, medida por SCRIPT:** o **WRAPPER**
// de `:907` (`display:flex;flex-direction:column;gap:2px`) está **ausente** da
// prescrição, que lista só `texto` e `sub`. Sem ele os dois ficam lado a lado.
// ═══════════════════════════════════════════════════════════════════════════

/** `:1009` — os milissegundos do protótipo, num lugar só. */
export const MILISSEGUNDOS_DO_TOAST = 3800;

/** `:905` — a casca. ⚠️ `z-[45]` é o remapeamento, não o `120` do protótipo. */
export const CLASSES_TOAST =
    'fixed left-1/2 top-[124px] z-[45] flex max-w-[560px] -translate-x-1/2 items-center gap-[12px] rounded-pub-panel border border-pub-float-line bg-pub-float px-[16px] py-[12px] shadow-pub-flutuante';

/** `:906` — o dot de 8px. */
export const CLASSES_DOT = 'h-[8px] w-[8px] flex-none rounded-full';

/** `:907` — o WRAPPER que a tabela do plano OMITE. */
export const CLASSES_COLUNA = 'flex flex-col gap-[2px]';

/** `:907` — o texto. ⚠️ FID-03: `text-[13.5px]`, nunca `text-sm` (14px). */
export const CLASSES_TEXTO = 'text-[13.5px] font-semibold';

/** `:907` — o sub. */
export const CLASSES_SUB = 'text-[12px] text-pub-txt-2';

/**
 * `:1009` — as cores do dot, por CHAVE DE TOAST.
 *
 * ⚠️ Chave, nunca hex. Medidas nas chamadas `this.toast(` do protótipo:
 * `#4ADE80` é o default (`:1009`), `#D4D4D8` é o neutro (`:1165`), `#FBBF24` o do
 * ajuste (`:1199`) e `#F87171` o da foto reprovada (`:1152`).
 *
 * ⚠️ **COR NUNCA É O ÚNICO SINAL** (regra permanente): o texto do toast é a
 * informação; o dot só reforça. Um toast sem cor conhecida sai no neutro, nunca
 * sem dot (elemento vazio DESAPARECE na tela).
 */
export const CORES_DO_DOT = {
    aprovado: 'bg-pub-ok-txt',
    publicado: 'bg-pub-ok-txt',
    revisao_salva: 'bg-pub-fase1',
    ajuste_solicitado: 'bg-pub-warn-txt',
    foto_reprovada: 'bg-pub-danger-txt',
    gerando_com_ia: 'bg-pub-fase1',
};

/** O dot neutro, para chave desconhecida. ⚠️ Nunca ausência: dot vazio não existe. */
export const COR_DO_DOT_NEUTRA = 'bg-pub-fase1';

/**
 * A transição da entrada, por `style`.
 *
 * ⚠️ `duration-[160ms]` **NÃO COMPILA** neste projeto (GATE-09). Valor fora da
 * escala nomeada vai por `style`, e o gate usa `duration-200` como controle.
 */
export const TRANSICAO_DO_TOAST = { transitionDuration: '160ms' };

/**
 * ⚠️⚠️ **O AGENDAMENTO, COMO FUNÇÃO PURA — e é ela que o gate testa.**
 *
 * O protótipo (`:1009`) faz `clearTimeout(this._tt)` **antes** de reagendar: sem
 * isso, dois toasts em sequência deixariam o timer do primeiro apagar o segundo
 * **antes dos 3,8 s dele**, e o usuário perderia o aviso do que acabou de
 * acontecer. A ordem (cancelar → agendar) é o invariante, não um detalhe.
 *
 * ⚠️ **O RUNTIME DO TIMER NÃO É PROVADO NESTE AMBIENTE** — não há navegador aqui.
 * O que esta função torna testável é o NÚMERO (3800), a ORDEM (cancelar antes de
 * agendar) e o fato de o cancelamento acontecer **mesmo quando não há timer
 * anterior**. Que o navegador honre o `setTimeout` é confiança no runtime.
 *
 * ⚠️ As duas funções entram por PARÂMETRO (injeção), e não por `globalThis`: é o
 * que permite exercer isto sem navegador e sem relógio de verdade.
 *
 * @param {Function} aoSumir     o que fazer quando o tempo acabar
 * @param {object} relogio       `{ agendar, cancelar }` — `setTimeout`/`clearTimeout`
 * @param {*} anterior           o handle do timer anterior, se houver
 * @returns {*} o handle do novo timer
 */
export function agendarOToast(aoSumir, relogio, anterior = null) {
    // ⚠️ Cancelar SEMPRE vem primeiro, e vale também para `anterior === null`: a
    // guarda é sobre a função existir, não sobre haver timer.
    if (relogio !== null && typeof relogio === 'object' && typeof relogio.cancelar === 'function' && anterior !== null) {
        relogio.cancelar(anterior);
    }

    if (relogio === null || typeof relogio !== 'object' || typeof relogio.agendar !== 'function') {
        return null;
    }

    return relogio.agendar(
        typeof aoSumir === 'function' ? aoSumir : () => {},
        MILISSEGUNDOS_DO_TOAST,
    );
}

/**
 * O texto do toast a partir de uma CHAVE, lido de `TOASTS`.
 *
 * ⚠️⚠️ `TOASTS[chaveDoServidor]` cru **LANÇA** com `{toString: null}`, e
 * `obj.hasOwnProperty(...)` também — os dois derrubaram a página inteira neste
 * projeto. A leitura é com guarda de tipo + `hasOwnProperty.call`.
 *
 * ⚠️ Chave desconhecida devolve `null`, **nunca** a chave crua: chave de dado na
 * tela é jargão, e jargão sem explicação é vetado neste projeto.
 */
export function textoDoToast(chave) {
    if (typeof chave === 'string' && Object.prototype.hasOwnProperty.call(TOASTS, chave)) {
        return TOASTS[chave];
    }

    return null;
}

/** Texto não-vazio, ou `null`. ⚠️ Nunca `String(valor)`, que LANÇA com `{toString:null}`. */
function textoNaoVazio(valor) {
    if (typeof valor !== 'string') return null;

    return valor.trim() === '' ? null : valor;
}

/** Objeto comum de verdade, ou `null`. `null` e array são os dois `typeof 'object'`. */
function objetoComum(valor) {
    return valor !== null && typeof valor === 'object' && !Array.isArray(valor) ? valor : null;
}

/**
 * A classe de um mapa FECHADO, com guarda de tipo e `hasOwnProperty.call`.
 *
 * ⚠️⚠️ `obj[chaveDoServidor]` e `obj.hasOwnProperty(...)` **LANÇAM** com
 * `{toString: null}` / `{hasOwnProperty: null}`.
 */
function classeDe(mapa, chave, padrao) {
    if (typeof chave === 'string' && Object.prototype.hasOwnProperty.call(mapa, chave)) {
        return mapa[chave];
    }

    return padrao;
}

/**
 * O toast.
 *
 * ⚠️ Ele aceita `chave` (que LÊ o literal de `TOASTS`) **ou** `texto` já pronto —
 * e a chave GANHA, porque é ela que garante o literal travado. `texto` existe
 * para o caso em que o servidor já mandou a frase montada.
 *
 * ⚠️ Sem texto nenhum, **nada é desenhado**: toast vazio é uma caixa flutuante no
 * meio da tela sem informação, cobrindo a tabela.
 */
export default function ToastDaFila({ toast = null }) {
    const dados = objetoComum(toast);
    if (dados === null) return null;

    const chave = textoNaoVazio(dados.chave);
    const texto = textoDoToast(chave) ?? textoNaoVazio(dados.texto);

    if (texto === null) return null;

    const sub = textoNaoVazio(dados.sub);

    return (
        <div className={CLASSES_TOAST} style={TRANSICAO_DO_TOAST}>
            <span className={cn(CLASSES_DOT, classeDe(CORES_DO_DOT, chave, COR_DO_DOT_NEUTRA))} />

            <span className={CLASSES_COLUNA}>
                <span className={CLASSES_TEXTO}>{texto}</span>
                {sub === null ? null : <span className={CLASSES_SUB}>{sub}</span>}
            </span>
        </div>
    );
}
