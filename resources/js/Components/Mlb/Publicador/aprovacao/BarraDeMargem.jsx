import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 08 — A BARRA DE MARGEM: a célula 8 da linha da oferta.
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas). ⚠️ **CITE O DIRETÓRIO:** há DUAS cópias do pacote de handoff
// com conteúdo DIFERENTE e `new_layout_publicador/` é a ANTIGA.
//
// ⚠️⚠️ **OS PONTEIROS DO PLANO ESTAVAM DESLOCADOS EM TODOS OS TRÊS DESTA
// CÉLULA** — os certos foram MEDIDOS com `sed` nesta sessão (16/10/2026):
//
//   elemento        plano    MEDIDO
//   coluna          :162     **:161**  `display:flex;flex-direction:column;`
//                                      `gap:6px;padding-right:12px`
//   percentual      :163     **:162**  `font-size:13px;font-weight:600;`
//                                      `color:{{marginColor}};`
//                                      `font-variant-numeric:tabular-nums`
//   trilha + barra  :164     **:163**  trilha `position:relative;height:4px;`
//                                      `border-radius:2px;background:#1F1F1F`
//                                      preenchimento `position:absolute;left:0;`
//                                      `top:0;bottom:0;width:{{marginW}};`
//                                      `border-radius:2px;background:{{marginBar}}`
//                                      traço `position:absolute;left:30%;`
//                                      `top:-3px;width:1.5px;height:10px;`
//                                      `background:#71717A`
//
// ═══ ⚠️⚠️ DIVERGÊNCIA D10 — É PRESCRIÇÃO DE FÓRMULA, NÃO DE NÚMERO ════════
//
// O protótipo escreve `left:30%` **porque `MIN = 12`** (`:914`) **e
// `12/40 = 30%`**. Cravar `left-[30%]` aqui amarraria a tela a um mínimo que
// vem do parâmetro da Precificação (FIN-09) e pode ser outro por empresa: a
// barra passaria a discordar do próprio traço, e a discordância seria INVISÍVEL
// (ninguém olha um traço de 1,5px e desconfia).
// ➡️ O traço vai em `minimo/40`, calculado **no servidor** pela MESMA função
// pública que calcula a largura do preenchimento
// (`LinhaDaOfertaService::percentualDaBarra()`, estática e pública exatamente
// para não haver duas cópias). Com `minimo = 12` o valor é **30%**, e há gate
// provando as DUAS leituras. (D10 do `187-BASELINES.md`; o `left:30%` aparece
// TRÊS vezes no protótipo — `:163` no E1, `:417` no E4 e `:645` no E2.)
//
// ═══ ⚠️⚠️ A LARGURA VAI POR `style`, NUNCA POR CLASSE ═════════════════════
//
// Classe montada por concatenação (`w-[${pct}]`) **o scanner do Tailwind não
// vê**: ela fica no HTML, todo teste de render passa e **a barra não aparece na
// tela**. É a mesma família do `*.js` que o `content` não varre (GATE-08) e do
// `duration-[Nms]` que não compila neste projeto.
// ➡️ `style={{width}}` e `style={{left}}`, com o percentual vindo FORMATADO do
// servidor e conferido aqui por FORMA FECHADA.
//
// ═══ ⚠️ A COR NUNCA É O ÚNICO SINAL (regra permanente) ════════════════════
//
// O percentual vai ESCRITO ao lado da barra, e margem desconhecida sai como
// `—` (nunca `0%`, que AFIRMARIA margem zero — a mesma razão por que o
// resultado sai `Incompleto` em vez de `R$ 0,00`).
//
// ⚠️ TODA classe mora NESTE `.jsx` (o `content` varre `*.jsx`, não `*.js`).
// ⚠️ NUNCA `String(valor)`, `JSON.stringify(valor)` nem `obj[chaveDoServidor]`.
// ═══════════════════════════════════════════════════════════════════════════

/** `:161` — a coluna da célula. */
const CLASSES_COLUNA = 'flex flex-col gap-[6px] pr-[12px]';
/** `:162` — o percentual escrito. */
const CLASSES_NUMERO = 'text-[13px] font-semibold tabular-nums';
/** `:163` — a trilha (`#1F1F1F` = `pub-line-subtle`). */
const CLASSES_TRILHA = 'relative h-[4px] rounded-[2px] bg-pub-line-subtle';
/** `:163` — o preenchimento; a largura vem por `style`. */
const CLASSES_PREENCHIMENTO = 'absolute inset-y-0 left-0 rounded-[2px]';
/** `:163` — o traço do mínimo (`#71717A` = `pub-txt-4`); a posição vem por `style`. */
const CLASSES_TRACO = 'absolute top-[-3px] h-[10px] w-[1.5px] bg-pub-txt-4';

/**
 * `:1246` — a cor do NÚMERO, por chave semântica do serviço.
 *
 * `null → #71717A` · `<0 → #F87171` · `<MIN → #FBBF24` · senão `#F4F4F5`.
 */
export const CORES_DO_NUMERO = {
    neutro: 'text-pub-txt-4',
    vermelho: 'text-pub-danger-txt',
    ambar: 'text-pub-warn-txt',
    texto: 'text-pub-txt',
};

/**
 * `:1246` — a cor do PREENCHIMENTO.
 *
 * ⚠️⚠️ **São hex DIFERENTES dos do número, e isso é do protótipo, não descuido:**
 * `#3F3F46`/`#EF4444`/`#F59E0B`/`#A1A1AA` contra
 * `#71717A`/`#F87171`/`#FBBF24`/`#F4F4F5`. As barras são tinta sólida e os
 * textos são a versão clara — unificar os dois mapas mudaria as duas coisas.
 */
export const CORES_DA_BARRA = {
    trilha: 'bg-pub-line-off',
    barra_baixa: 'bg-pub-danger',
    barra_alerta: 'bg-pub-warn',
    suave: 'bg-pub-txt-2',
};

/** `:1246` — a margem sem número. ⚠️ Travessão, nunca `0%`. */
export const SEM_NUMERO = '—';

/**
 * O percentual, em FORMA FECHADA.
 *
 * ⚠️⚠️ **Por que uma forma fechada e não `parseFloat` + template:** o valor vem
 * do servidor e pode chegar objeto, nulo, ausente, `NaN`, array ou string
 * qualquer. `width: "NaN%"` é CSS inválido (a barra desaparece sem erro) e
 * `width: "95%"` numa barra de teto 40 ESTOURARIA a trilha. O servidor já
 * clampa em `min(40, max(0, m))/40` (`percentualDaBarra()`); esta guarda é a
 * SEGUNDA porta, e ela recusa qualquer coisa que não seja
 * `<dígitos>[.<dígitos>]%` entre 0 e 100.
 *
 * ⚠️ Nunca `String(valor)`: ele LANÇA com `{toString: null}`.
 *
 * @param {unknown} valor  o percentual formatado pelo servidor (`'30%'`, `'0%'`)
 * @returns {string} o percentual aceito, ou `'0%'`
 */
export function percentualSeguro(valor) {
    if (typeof valor !== 'string') return '0%';
    if (!/^\d+(\.\d+)?%$/.test(valor)) return '0%';

    const numero = Number.parseFloat(valor);

    if (!Number.isFinite(numero) || numero < 0 || numero > 100) return '0%';

    return valor;
}

/**
 * A classe de um mapa fechado, com a guarda de tipo e `hasOwnProperty.call`.
 *
 * ⚠️ `obj[chaveDoServidor]` e `obj.hasOwnProperty(...)` LANÇAM com
 * `{toString: null}` / `{hasOwnProperty: null}` — os dois derrubaram a página
 * inteira neste projeto.
 */
function classeDe(mapa, chave, padrao) {
    if (typeof chave === 'string' && Object.prototype.hasOwnProperty.call(mapa, chave)) {
        return mapa[chave];
    }

    return padrao;
}

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
 * A célula de margem: o percentual escrito, a barra de 0 a 40% e o traço do
 * mínimo.
 *
 * ⚠️ **A célula INTEIRA aparece sempre**, inclusive sem dado — e isso é
 * deliberado: a coluna existe no grid de 11, e sumir com ela desalinharia a
 * linha do cabeçalho. Sem dado, o número é `—` (que não afirma nada) e a barra
 * fica com largura zero na trilha neutra.
 *
 * @param {object} props
 * @param {unknown} [props.margem] a célula 8 de `LinhaDaOfertaService::para()`:
 *   `{valor, cor, largura, barra, minimo, minimo_valor, rotulo_longo, fonte}`.
 *   ⚠️ Qualquer campo pode chegar objeto/nulo/NaN/array.
 * @param {unknown} [props.className] classe extra da raiz; só vale como string
 */
export default function BarraDeMargem({ margem = null, className = null }) {
    const dado = objetoComum(margem);
    const classeExtra = typeof className === 'string' && className.trim() !== '' ? className : null;

    // ⚠️ `textoNaoVazio` e não `?? SEM_NUMERO`: `valor` chegando `''` é um número
    // AUSENTE, e um span vazio deixaria a cor como único sinal.
    const valor = dado === null ? null : textoNaoVazio(dado.valor);
    const corDoNumero = classeDe(CORES_DO_NUMERO, dado?.cor, CORES_DO_NUMERO.neutro);
    const corDaBarra = classeDe(CORES_DA_BARRA, dado?.barra, CORES_DA_BARRA.trilha);
    const largura = percentualSeguro(dado?.largura);
    const minimo = percentualSeguro(dado?.minimo);

    // ⚠️ `title` só com texto, e `?? undefined` nunca `String(...)`: `title=""` é
    // um `title` AUSENTE. A fonte do número ("com a tarifa real do Mercado
    // Livre", D4/decisão 9) é o que distingue esta margem da do resumo rápido do
    // lote — que é diferente DE PROPÓSITO e não deve ser unificada.
    const fonte = dado === null ? null : textoNaoVazio(dado.fonte);
    const rotuloLongo = dado === null ? null : textoNaoVazio(dado.rotulo_longo);
    const balao = rotuloLongo !== null && fonte !== null ? `${rotuloLongo} · ${fonte}` : (fonte ?? rotuloLongo);

    return (
        <div className={cn(CLASSES_COLUNA, classeExtra)}>
            <span title={balao ?? undefined} className={cn(CLASSES_NUMERO, corDoNumero)}>
                {valor ?? SEM_NUMERO}
            </span>

            <span className={CLASSES_TRILHA}>
                {/* ⚠️ A largura por `style`, nunca por classe concatenada. */}
                <span
                    aria-hidden="true"
                    className={cn(CLASSES_PREENCHIMENTO, corDaBarra)}
                    style={{ width: largura }}
                />
                {/* ⚠️ D10 — `minimo/40`, calculado no servidor. Nunca `left-[30%]`. */}
                <span aria-hidden="true" className={CLASSES_TRACO} style={{ left: minimo }} />
            </span>
        </div>
    );
}
