import { cn } from '@/lib/utils';
import { FAIXA_DE_ANDAMENTO, andamentoDe } from './andamentoDoPortao';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 04 — A FAIXA DE ANDAMENTO: os 9 cards filtráveis
// (AND-09, FILA-03, COL-03).
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas, sha256 `68624883ce35547e`). Linhas MEDIDAS com `grep -n` nesta
// sessão — ⚠️ **as QUATRO do plano estavam deslocadas em 1**:
//
//   · grid           → :44  (`display:grid;`
//                            `grid-template-columns:repeat(9,minmax(0,1fr));gap:6px`)
//                            ⚠️ o plano cita :43
//   · `<button>`     → :46  (`display:flex;flex-direction:column;`
//                            `align-items:flex-start;gap:8px;padding:11px 12px;`
//                            `border:1px solid {{ s.border }};border-radius:10px;`
//                            `background:{{ s.bg }};color:#F4F4F5;cursor:pointer;`
//                            `text-align:left;min-width:0`
//                            + `style-hover="border-color:#3F3F46"`)
//                            ⚠️ o plano cita :45
//   · rótulo + dot   → :47  (`display:flex;align-items:center;gap:6px;`
//                            `font-size:11.5px;font-weight:500;`
//                            `color:{{ s.labelColor }};min-height:28px;`
//                            `line-height:1.2` e o dot
//                            `width:7px;height:7px;border-radius:50%;flex:none`)
//                            ⚠️ o plano cita :46
//   · número         → :48  (`font-size:22px;line-height:1;font-weight:600;`
//                            `letter-spacing:-0.02em;font-variant-numeric:tabular-nums;`
//                            `color:{{ s.countColor }}`)
//                            ⚠️ o plano cita :47
//   · as 5 cores     → :1217 (`stages`): `bg: on ? '#181818' : '#111111'`,
//                            `border: on ? '#3F3F46' : '#1F1F1F'`,
//                            `labelColor: on ? '#F4F4F5' : '#A1A1AA'`,
//                            `countColor: cnt[k] ? '#F4F4F5' : '#52525B'`,
//                            `dot: k === 'all' ? '#F4F4F5' : m[4]`
//
// ⚠️ O `color:#F4F4F5` do `<button>` (`:46`) ESTÁ aqui como `text-pub-txt`,
// embora o rótulo e o número declarem a própria cor por cima. A tabela de
// fidelidade deste projeto erra por OMISSÃO **e por excesso** — e omitir uma
// propriedade "porque nada a usa" é como o `cursor:pointer` foi perdido onze
// vezes numa fase só.
//
// ═══ ⚠️⚠️ OS 9 RÓTULOS E A ORDEM SÃO **LIDOS**, NUNCA RECOPIADOS ════════════
//
// `FAIXA_DE_ANDAMENTO` vem de `andamentoDoPortao.jsx` (Fase 186, SOMENTE
// LEITURA), que é a fonte única dos 9 recortes e dos rótulos. Copiar a lista
// aqui criaria uma SEGUNDA VERDADE, livre para divergir num rótulo sem nenhum
// teste de render acusar — e há gate de AUSÊNCIA dos 9 literais neste arquivo,
// com controle positivo naquele módulo.
//
// ⚠️ A mesma regra vale para o DOT: a classe de cor sai de
// `andamentoDe(chave).dot`. ⚠️ **A única exceção é `todas`**, que NÃO é estado e
// por isso não está em `ANDAMENTOS`: o dot dela é `bg-pub-txt`, medido em
// `:1217` (`k === 'all' ? '#F4F4F5'`). Essa linha está escrita porque é a única
// cor que este arquivo pode legitimamente afirmar.
//
// ⚠️ `andamentoDe()` NUNCA devolve `null` (é a correção do bug S3 da Fase 186),
// mas o gate adverso exige **TEXTO NÃO-VAZIO dentro do card**: card vazio passa
// em render e DESAPARECE na tela.
//
// ═══ ⚠️⚠️ A ESTRUTURA VEM DO MÓDULO; O DADO VEM DO SERVIDOR ════════════════
//
// Os 9 cards existem SEMPRE — eles são a navegação. O que vem do servidor é o
// `total` de cada um, casado por `chave`. ➡️ Com `faixa` nula, adversa ou
// incompleta a faixa continua desenhada e o total aparece como **"—"**: zero
// inventado seria uma afirmação que ninguém mediu (EXMP-01), e esconder a faixa
// tiraria a navegação da pessoa.
//
// ⚠️ E as contagens são calculadas pelo SERVIDOR sobre os DEMAIS filtros, nunca
// sobre o recorte já filtrado por etapa (FILA-03). Recalcular aqui é impossível
// de propósito: este componente não recebe a fila, só os totais.
//
// ═══ ⚠️⚠️ O CLIQUE FILTRA PELA URL, NÃO POR ESTADO LOCAL (COL-03) ══════════
//
// O card chama o `onEtapa` que a PÁGINA passa, e a página faz `router.get` com a
// querystring. ⚠️ **NADA de `localStorage`**: ele é artefato do protótipo
// (`KEY='ecf-aprovacao-v1'`, `:914`) e o critério 13 o proíbe — filtro que mora
// no navegador não sobrevive a um link compartilhado nem a outra máquina.
//
// ⚠️ **Sem `onEtapa` função os cards NÃO são botões.** Botão sem destino é
// proibido (EXMP-01) — mas o NÚMERO é informação e continua na tela, porque
// esconder dado medido é outro erro.
//
// ⚠️ TODA flag nasce DENTRO do callback do `.map()`: o Rollup elimina variável
// de escopo do componente usada dentro de um `.map()` e o `ReferenceError` só
// aparece no bundle de PRODUÇÃO. São 9 cards mapeados.
//
// ⚠️ TODA classe mora NESTE `.jsx`: o `content` do `tailwind.config.js` varre
// `./resources/js/**/*.jsx` e **NÃO** `*.js`.
//
// ⚠️ NUNCA `objeto[chave]` com chave vinda do servidor: ela LANÇA com
// `{toString:null}` e, com chave do protótipo (`'toString'`, `'constructor'`),
// devolve uma FUNÇÃO — que renderizada derruba a página. O lookup do total é por
// `Map.get`, que não coage a chave e não tem protótipo para vazar.
// ═══════════════════════════════════════════════════════════════════════════

/** A chave de "sem filtro" — não é estado, e por isso não está em `ANDAMENTOS`. */
export const CHAVE_TODAS = 'todas';

/**
 * O dot de "Todas" (`:1217`: `dot: k === 'all' ? '#F4F4F5' : m[4]`).
 *
 * ⚠️ É a ÚNICA classe de cor escrita neste arquivo, e com a linha do protótipo
 * ao lado: `todas` não é estado, então `andamentoDe('todas')` cairia no NEUTRO
 * (`bg-pub-neutral-dot`, #71717A) — a cor errada, e sem nada acusando.
 */
const DOT_DE_TODAS = 'bg-pub-txt';

/** O total que não se pode afirmar. Nunca `0`: zero é um FATO, "—" é a ausência dele. */
const SEM_TOTAL = '—';

/**
 * Os totais do servidor, indexados por chave.
 *
 * ⚠️ `Map` e não objeto: `objeto[chaveAdversa]` LANÇA e vaza o protótipo (ver o
 * docblock do arquivo). ⚠️ E a guarda de `chave` é `typeof !== 'string'` ANTES
 * de qualquer uso — no molde de `entradaDe()` de `andamentoDoPortao.jsx`.
 *
 * @param {unknown} faixa a lista `[{chave, rotulo, total}]` do servidor
 * @returns {Map<string, number>} só com as entradas USÁVEIS
 */
export function totaisPorChave(faixa) {
    const mapa = new Map();
    if (!Array.isArray(faixa)) return mapa;

    for (const cru of faixa) {
        if (cru === null || typeof cru !== 'object' || Array.isArray(cru)) continue;
        if (typeof cru.chave !== 'string' || cru.chave === '') continue;
        // ⚠️ Inteiro NÃO-NEGATIVO: `NaN`, `Infinity`, `'7'`, `1.5` e `-5` não são
        // contagem de nada, e um deles na tela seria número inventado.
        if (typeof cru.total !== 'number' || !Number.isInteger(cru.total) || cru.total < 0) continue;
        mapa.set(cru.chave, cru.total);
    }

    return mapa;
}

// As classes, escritas uma vez e literalmente — é daqui que o Tailwind as lê.
const CLASSES_GRID = 'grid grid-cols-[repeat(9,minmax(0,1fr))] gap-[6px]';
const CLASSES_CARD = 'flex min-w-0 flex-col items-start gap-[8px] rounded-pub-sub border px-[12px] py-[11px] text-left text-pub-txt hover:border-pub-line-off';
const CLASSES_CARD_CLICAVEL = 'cursor-pointer';
const CLASSES_CARD_ATIVO = 'bg-pub-raised-2 border-pub-line-off';
const CLASSES_CARD_INATIVO = 'bg-pub-surface border-pub-line-subtle';
const CLASSES_ROTULO = 'flex min-h-[28px] items-center gap-[6px] text-[11.5px] font-medium leading-[1.2]';
const CLASSES_ROTULO_ATIVO = 'text-pub-txt';
const CLASSES_ROTULO_INATIVO = 'text-pub-txt-2';
const CLASSES_DOT = 'h-[7px] w-[7px] flex-none rounded-full';
const CLASSES_NUMERO = 'text-[22px] font-semibold leading-none tracking-[-0.02em] tabular-nums';
const CLASSES_NUMERO_ZERO = 'text-pub-txt-off';
const CLASSES_NUMERO_CHEIO = 'text-pub-txt';

/**
 * A faixa de 9 recortes da fila (AND-09): dot, rótulo de 11.5px e número de
 * 22px/600, com o card ativo em `#181818`.
 *
 * @param {object} props
 * @param {unknown} [props.faixa] a lista de 9 `{chave, rotulo, total}` do servidor
 * @param {unknown} [props.ativa] a chave da etapa filtrada (vem da URL, pelo servidor)
 * @param {unknown} [props.onEtapa] o handler da página; sem ele o card não é botão
 */
export default function FaixaDeAndamento({ faixa = null, ativa = CHAVE_TODAS, onEtapa = null }) {
    const totais = totaisPorChave(faixa);
    const clicavel = typeof onEtapa === 'function';

    return (
        <div className={CLASSES_GRID}>
            {FAIXA_DE_ANDAMENTO.map((entrada) => {
                // ⚠️ TODAS as flags nascem AQUI DENTRO — o Rollup elimina
                // variável de escopo do componente usada dentro do `.map()`.
                //
                // ⚠️ Igualdade ESTRITA contra a chave: nunca converte, nunca
                // indexa objeto, e por isso nunca lança — mesmo com
                // `ativa = {toString: null}`. Chave desconhecida (inclusive as
                // INGLESAS do protótipo, `all`/`awaiting`/…) não marca card
                // nenhum: afirmar um filtro que o servidor não disse seria
                // invenção.
                const ehAtiva = entrada.chave === ativa;
                const ehTodas = entrada.chave === CHAVE_TODAS;
                const corDoDot = ehTodas ? DOT_DE_TODAS : andamentoDe(entrada.chave).dot;
                const medido = totais.get(entrada.chave);
                const temTotal = medido !== undefined;
                const texto = temTotal ? medido : SEM_TOTAL;
                const corDoNumero = temTotal && medido > 0 ? CLASSES_NUMERO_CHEIO : CLASSES_NUMERO_ZERO;

                const miolo = (
                    <>
                        <span className={cn(CLASSES_ROTULO, ehAtiva ? CLASSES_ROTULO_ATIVO : CLASSES_ROTULO_INATIVO)}>
                            <span aria-hidden="true" className={cn(CLASSES_DOT, corDoDot)} />
                            {entrada.rotulo}
                        </span>
                        <span className={cn(CLASSES_NUMERO, corDoNumero)}>{texto}</span>
                    </>
                );

                const classes = cn(
                    CLASSES_CARD,
                    ehAtiva ? CLASSES_CARD_ATIVO : CLASSES_CARD_INATIVO,
                    clicavel ? CLASSES_CARD_CLICAVEL : null,
                );

                if (clicavel) {
                    return (
                        <button
                            key={entrada.chave}
                            type="button"
                            onClick={() => onEtapa(entrada.chave)}
                            className={classes}
                            {...(ehAtiva ? { 'aria-current': 'true' } : {})}
                        >
                            {miolo}
                        </button>
                    );
                }

                // ⚠️ Sem destino o card NÃO é botão (EXMP-01), e também não ganha
                // `cursor-pointer`: cursor de clique sobre coisa que não clica é
                // promessa falsa. O número CONTINUA, porque é informação medida.
                return (
                    <div key={entrada.chave} className={classes}>
                        {miolo}
                    </div>
                );
            })}
        </div>
    );
}
