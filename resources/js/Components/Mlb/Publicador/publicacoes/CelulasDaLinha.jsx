import { Pencil, UserRound } from 'lucide-react';
import { cn } from '@/lib/utils';
import { textoUtil } from '../BarraDaConta';
import { iniciais, tomDoProduto } from '../identidade/identidade';
import BadgeNova from '../identidade/BadgeNova';
import RotuloDaFase from '../identidade/RotuloDaFase';
import SeloDeStatusDaPublicacao from '../identidade/SeloDeStatusDaPublicacao';
import SeloDoTipo from '../identidade/SeloDoTipo';
import TileDoProduto from '../identidade/TileDoProduto';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 180, plano 08 — as SETE células especiais da linha de publicação.
//
// Protótipo: `new_layout_publicador/Publicacoes v2.dc.html`. Linhas MEDIDAS com
// `grep -n` neste arquivo (não copiadas da tabela do plano):
//   · célula Publicação  → :206 (`display:flex;align-items:center;gap:12px;`
//                                `min-width:0;padding-left:{{r.indent}};`
//                                `padding-right:16px`)
//   · tile 36px          → :208 (`36×36;border-radius:8px;font-size:11.5px;`
//                                `font-weight:600;flex:none`)
//   · coluna do título   → :210 (`display:flex;flex-direction:column;gap:3px;`
//                                `min-width:0`) ⚠️ a tabela OMITIU este wrapper
//   · título             → :211 (`13.5px/500` + ellipsis)
//   · meta               → :212 (`gap:6px;11.5px;#8A8A90;min-width:0;overflow:hidden`)
//   · MLB                → :213 (`font-family:'Geist Mono';flex:none`)
//   · botão de copiar    → :214 (`18×18;border-radius:4px;`**`background:transparent`**`;`
//                                `display:grid;place-items:center;color:#71717A;`
//                                **`cursor:pointer`**, hover `#262626`/`#F4F4F5`)
//   · ícone COPIADO      → :215 (`11×11 viewBox 16;`**`fill:none`**`;stroke:#4ADE80;`
//                                `stroke-width:2.2;`**`stroke-linecap:round`**`;`
//                                **`stroke-linejoin:round`**)
//   · ícone de copiar    → :216 (`11×11 viewBox 16;`**`fill:none`**`;`
//                                `stroke:currentColor;stroke-width:1.6;`
//                                **`stroke-linejoin:round`** — ⚠️ SEM `linecap`,
//                                ao contrário do de cima: medido, não suposto)
//   · badge NOVA         → :217   · nome do produto → :218 (`·` em `#52525B`
//                                 com `flex:none` + nome com ellipsis e `min-width:0`)
//   · célula Metodologia → :222 (`display:flex;align-items:center;gap:10px`)
//   · glyphs             → :224-:232   · rótulo da fase → :233-:235
//   · célula Tipo        → :237 (`column;gap:3px;align-items:flex-start`)
//   · losango Premium    → :239   · tag "Catálogo" → :242
//   · célula Status      → :244 (`column;gap:2px;`**`min-width:0`**)
//   · linha do status    → :245   · formas → :246-:250   · motivo → :252
//   · célula Preço       → :254 (`column;gap:2px;align-items:flex-end;`
//                                `font-variant-numeric:tabular-nums`)
//   · valor              → :255 (`13.5px/500`)
//   · "Prejuízo"         → :256 (`gap:4px;11.5px/600;#EF4444` + triângulo
//                                `10×10` com `fill:#EF4444`)
//   · "Margem x%"        → :257 (`11.5px;#8A8A90`)
//   · célula Vendas      → :260-:262 (valor `13.5px/500`, `#52525B` quando 0;
//                                2ª linha `"{N} vis."`)
//   · célula Publicada   → :264 (`gap:8px;padding-left:18px;min-width:0`)
//   · avatar             → :265 (`24×24;border-radius:50%;10px/600;flex:none`,
//                                com `title`) ⚠️ a tabela OMITIU o `title`
//   · coluna do texto    → :266 (`column;gap:1px;min-width:0`) ⚠️ OMITIDO
//   · data               → :267 (`12.5px/500` + ellipsis)
//   · responsável        → :268 (`11.5px;#8A8A90` + ellipsis)
//   · célula ações       → :271 (`justify-content:flex-end;gap:2px`)
//   · "abrir no ML"      → :272 (`28×28;border-radius:7px;display:grid;`
//                                `place-items:center;color:#8A8A90`, hover
//                                `#262626`/`#F4F4F5`, `target="_blank"`)
//   · ícone do ML        → :273 (`14×14 viewBox 16;`**`fill:none`**`;`
//                                `stroke:currentColor;stroke-width:1.6;`
//                                **`linecap/linejoin round`**)
//   · chevron            → :274/:275 (um `<span>` NÃO interativo de `28×28` com
//                                `14×14 viewBox 16;stroke-width:1.8`)
//   · `fmtDate`/`phaseInfo`/`brl` → :492/:509/:488
//
// ═══ AS TRÊS REGRAS EM QUE ESTA TELA PARA DE MENTIR ═══════════════════════
//
// ⚠️ LIST-12 — a célula Preço mostra **"—"** enquanto a margem não chega.
// NUNCA "Margem 0,0%", NUNCA "R$ 0,00" no lugar de ausência, NUNCA uma tarifa
// estimada por percentual. A tarifa do Mercado Livre não está no banco e a
// margem é assíncrona (Fase 181). `0` é preço MEDIDO e aparece como
// `R$ 0,00`; `null` é "não medido" e aparece como `"—"`.
//
// ⚠️ LIST-13 — a célula Publicada DIZ quando o autor é desconhecido. Os quatro
// literais (`Equipe`, `Cliente`, `Origem antiga`, `Autor não registrado`) vêm
// do servidor (`PainelVisaoGeralService::QUEM_*`) e são TEXTO NORMAL, não
// placeholder. E com `autor_tipo === 'desconhecido'` o avatar mostra o ícone
// genérico em vez de `iniciais('Autor não registrado')`, que daria `'AN'` e
// PARECERIA um nome de pessoa.
//
// ⚠️ LIST-20 — a metodologia vem do DADO REAL. `fase` ausente/nula é
// **Legado**; nada é inferido do título. E o tipo sai de `listing_type_id`
// (traduzido pelo servidor em `classico`/`premium`), nunca caindo no Clássico
// "porque é o mais comum".
//
// ⚠️ REND-01 / a tela preta de 07/10/2026: NENHUMA chave vinda do servidor é
// usada como índice de objeto sem guarda de tipo ANTES. `obj[chave]` COAGE a
// chave para string, exatamente como `String(valor)` e
// `hasOwnProperty.call(obj, chave)` — e com `{toString: null}` isso LANÇA
// `TypeError: Cannot convert object to primitive value` e derruba a PÁGINA
// INTEIRA. Os três lugares onde isso acontece aqui (`tipo`, `status` e a chave
// do motivo) têm `typeof === 'string'` antes.
//
// ⚠️ Data por REGEX, nunca `new Date()`: `new Date('2026-10-10')` desloca o
// fuso em BRT e vira o dia anterior, e entrada suja vira `Invalid Date`. O
// `publicada_em` chega de `Carbon::toIso8601String()` com o offset LOCAL
// (`APP_TIMEZONE=America/Sao_Paulo`), então os componentes da string JÁ SÃO o
// relógio de parede — reconvertê-los é que estragaria.
//
// ⚠️ Nenhum `.map()` neste arquivo nem nos outros dois do plano: o Rollup
// elimina variável de escopo do componente usada dentro de `.map()`
// (`ReferenceError` só no bundle de produção) e a forma de não cair nisso é
// não ter o laço. Quem mapeia as linhas é a página (plano 180-10).
//
// ⚠️ D-11: zero `router.*`. Nada daqui escreve no Mercado Livre.
// ⚠️ TODA classe do Tailwind mora neste `.jsx` — o `content` varre `*.jsx`.
// ═══════════════════════════════════════════════════════════════════════════

// ─────────────────────────────────────────────────────────────────────────
// Números, moeda e percentual
// ─────────────────────────────────────────────────────────────────────────

/** Só dígitos, com sinal e uma casa decimal opcional. */
const RE_NUMERO = /^-?\d+(?:[.,]\d+)?$/;

/**
 * O número MEDIDO, ou `null` para "não medido".
 *
 * ⚠️ Aceita string numérica porque o cast `decimal:2` do Eloquent serializa em
 * JSON como STRING (`"1234.56"`), não como número — então recusar string faria
 * o preço real virar `"—"`. ⚠️ Mas a aceitação é por FORMA FECHADA: `'abacaxi'`,
 * `''`, `NaN`, `Infinity`, objeto, booleano e array devolvem `null`. Nunca
 * `Number(valor)` cru: `Number('')` é 0 e `Number([])` é 0, e zero inventado
 * numa tela financeira é pior que ausência.
 *
 * @param {unknown} valor
 * @returns {number|null}
 */
export function numeroMedido(valor) {
    if (typeof valor === 'number') {
        return Number.isFinite(valor) ? valor : null;
    }
    if (typeof valor === 'string') {
        const aparado = valor.trim();
        if (!RE_NUMERO.test(aparado)) return null;
        const numero = Number(aparado.replace(',', '.'));
        return Number.isFinite(numero) ? numero : null;
    }
    return null;
}

/**
 * Moeda em pt-BR, no formato da `brl()` do protótipo (`:488`): o negativo leva
 * o sinal de MENOS (U+2212) e um espaço ANTES do `R$`.
 *
 * @param {unknown} valor
 * @returns {string|null} `null` quando não há valor medido
 */
export function moedaBRL(valor) {
    const numero = numeroMedido(valor);
    if (numero === null) return null;

    const corpo = Math.abs(numero).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    return `${numero < 0 ? '− ' : ''}R$ ${corpo}`;
}

/**
 * Texto para a TELA, ou `null`.
 *
 * ⚠️⚠️ EXISTE PORQUE `textoSeguro(NaN)` DEVOLVE A STRING `'NaN'` —
 * `typeof NaN === 'number'`, então o fallback não dispara e a tela escreve
 * "NaN" no título, no MLB, no nome do produto e no nome do autor. Foi o render
 * adverso deste plano que pegou os CINCO lugares (nenhum era hipotético: os
 * quatro campos vêm do servidor e `urlDoRascunho` vem do chamador).
 * `Infinity` cai no mesmo buraco.
 *
 * ⚠️ Número finito é aceito de propósito (um título que chega `7` é esquisito
 * mas é dado), e a interpolação de um número JÁ VALIDADO não pode lançar — ao
 * contrário de `String(valor)` cru, que LANÇA com `{toString: null}`.
 *
 * @param {unknown} valor
 * @returns {string|null}
 */
export function textoDaTela(valor) {
    if (typeof valor === 'number') {
        const numero = numeroMedido(valor);
        return numero === null ? null : `${numero}`;
    }
    return textoUtil(valor);
}

/**
 * Caminho/URL vindo do chamador: **só string** vale.
 *
 * ⚠️ Mais estrito que `textoDaTela` de propósito: `textoUtil(7)` devolve `'7'`
 * e isso já montou rota inválida TRÊS vezes nesta milestone. Campo que vira
 * destino exige string de forma fechada.
 */
export function caminhoSeguro(valor) {
    if (typeof valor !== 'string') return null;
    const aparado = valor.trim();
    return aparado === '' ? null : aparado;
}

/** Inteiro em pt-BR (separador de milhar), ou `null`. */
export function inteiroPtBr(valor) {
    const numero = numeroMedido(valor);
    return numero === null ? null : numero.toLocaleString('pt-BR');
}

/**
 * A margem como percentual pt-BR, UMA casa decimal e VÍRGULA.
 *
 * ⚠️ A UNIDADE É FRAÇÃO, não percentual: `0.1234` → `"12,3%"`. É o contrato
 * declarado pelo plano 180-08 (os casos de teste dele são `0.1234 → 12,3%` e
 * `-0.0512 → Prejuízo`) e é o que a Fase 181 tem de mandar. Mandar `12.34`
 * produziria `"1234,0%"` — por isso a unidade está escrita aqui e na SUMMARY.
 *
 * @param {unknown} margem
 * @returns {string|null}
 */
export function percentualDaMargem(margem) {
    const numero = numeroMedido(margem);
    return numero === null ? null : `${(numero * 100).toFixed(1).replace('.', ',')}%`;
}

// ─────────────────────────────────────────────────────────────────────────
// Data por REGEX — nenhum `new Date(`
// ─────────────────────────────────────────────────────────────────────────

const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

/** `YYYY-MM-DD` com hora opcional (`T` ou espaço). O offset do fim é ignorado. */
const RE_DATA = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/;

/**
 * Os componentes da data, lidos da string como ESTÃO.
 *
 * @param {unknown} iso
 * @returns {{ano: number, mes: number, dia: number, horario: string|null}|null}
 */
export function partesDaData(iso) {
    if (typeof iso !== 'string') return null;

    const casado = RE_DATA.exec(iso);
    if (casado === null) return null;

    const ano = Number(casado[1]);
    const mes = Number(casado[2]);
    const dia = Number(casado[3]);
    if (mes < 1 || mes > 12 || dia < 1 || dia > 31) return null;

    const hora = casado[4];
    const minuto = casado[5];
    const temHorario = hora !== undefined && Number(hora) <= 23 && Number(minuto) <= 59;

    return { ano, mes, dia, horario: temHorario ? `${hora}:${minuto}` : null };
}

/**
 * Dias desde 1970-01-01 por aritmética de inteiros (algoritmo `days_from_civil`).
 *
 * Existe para responder "é hoje? é ontem?" sem `new Date`, que é o que desloca
 * o fuso em data-only e produz `Invalid Date` com entrada suja.
 */
function diasCivis(ano, mes, dia) {
    const a = ano - (mes <= 2 ? 1 : 0);
    const era = Math.floor(a / 400);
    const anoDaEra = a - era * 400;
    const diaDoAno = Math.floor((153 * (mes + (mes > 2 ? -3 : 9)) + 2) / 5) + dia - 1;
    const diaDaEra = anoDaEra * 365 + Math.floor(anoDaEra / 4) - Math.floor(anoDaEra / 100) + diaDoAno;

    return era * 146097 + diaDaEra - 719468;
}

/**
 * A data de HOJE em `America/Sao_Paulo`, no formato `YYYY-MM-DD`.
 *
 * ⚠️ `Intl.DateTimeFormat.formatToParts` aceita um TIMESTAMP (número) — então
 * não há `new Date(` em nenhum lugar, e o fuso é declarado em vez de herdado do
 * navegador. `agora` é injetável para o teste não depender do relógio (teste
 * dependente de `now()` deste projeto já reprovou por horário).
 *
 * @param {number} [agora]
 * @returns {string|null}
 */
export function hojeEmSaoPaulo(agora = Date.now()) {
    if (typeof agora !== 'number' || !Number.isFinite(agora)) return null;

    try {
        const partes = new Intl.DateTimeFormat('pt-BR', {
            timeZone: 'America/Sao_Paulo',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).formatToParts(agora);

        const pegar = (tipo) => {
            const achado = partes.find((parte) => parte.type === tipo);
            return achado === undefined ? '' : achado.value;
        };

        const ano = pegar('year');
        const mes = pegar('month');
        const dia = pegar('day');

        return /^\d{4}$/.test(ano) && /^\d{2}$/.test(mes) && /^\d{2}$/.test(dia)
            ? `${ano}-${mes}-${dia}`
            : null;
    } catch {
        return null;
    }
}

/**
 * A data relativa da `fmtDate()` do protótipo (`:492`-`:501`): "Hoje, 14:32",
 * "Ontem, 09:10", "28 set" — e com o ano quando o ano é outro.
 *
 * ⚠️ Sem referência de hoje (`hojeIso` nulo/ilegível) ela NÃO diz "Hoje": cai
 * na data absoluta com ano. Dizer "Hoje" sem saber que dia é hoje seria
 * exatamente o tipo de invenção que esta tela existe para não fazer.
 *
 * @param {unknown} iso
 * @param {unknown} hojeIso  `YYYY-MM-DD`
 * @returns {string|null} `null` quando a data não é legível — e aí a tela
 *   simplesmente NÃO mostra data (não "—", não "Invalid Date")
 */
export function dataRelativa(iso, hojeIso) {
    const data = partesDaData(iso);
    if (data === null) return null;

    const referencia = partesDaData(hojeIso);
    const absoluta = (comAno) => `${data.dia} ${MESES[data.mes - 1]}${comAno ? ` ${data.ano}` : ''}`;

    if (referencia === null) return absoluta(true);

    const dias = diasCivis(referencia.ano, referencia.mes, referencia.dia)
        - diasCivis(data.ano, data.mes, data.dia);

    if (dias === 0) return data.horario === null ? 'Hoje' : `Hoje, ${data.horario}`;
    if (dias === 1) return data.horario === null ? 'Ontem' : `Ontem, ${data.horario}`;

    return absoluta(data.ano !== referencia.ano);
}

// ─────────────────────────────────────────────────────────────────────────
// Mapas FECHADOS — e a guarda de tipo ANTES de cada indexação
// ─────────────────────────────────────────────────────────────────────────

/**
 * ⚠️ A GUARDA. `objeto[chave]` com `chave = {toString: null}` LANÇA
 * `TypeError: Cannot convert object to primitive value`, porque a busca de
 * chave coage para string. Foi assim que dois componentes derrubaram a página
 * inteira no plano 180-07 (lá via `hasOwnProperty.call`), e é a mesma família da
 * tela preta de 07/10/2026.
 */
function valorDoMapa(mapa, chave) {
    if (typeof chave !== 'string') return null;
    const valor = mapa[chave];
    return typeof valor === 'string' ? valor : null;
}

/**
 * O rótulo do tipo da oferta.
 *
 * ⚠️ O servidor manda o SLUG (`classico`/`premium`,
 * `MlbAnuncioController.php:801`-`:803`), não o rótulo — e `SeloDoTipo` recebe
 * "o rótulo já decidido". A tradução tem de acontecer em algum lugar, e aqui ela
 * é um mapa FECHADO: valor fora dos dois NÃO vira "Clássico" (LIST-20 proíbe
 * cair no mais comum) nem ganha o losango de Premium.
 */
const ROTULO_DO_TIPO = { classico: 'Clássico', premium: 'Premium' };

/**
 * `MlAcervoItem.status` → a chave de `SeloDeStatusDaPublicacao`.
 *
 * ⚠️ `closed` entra como RÓTULO ("Encerrada") e não como chave, porque o
 * componente de identidade só conhece QUATRO (`ativa`/`pausada`/`com_erro`/
 * `em_revisao`) e acrescentar uma quinta exige tocar `identidade/`, que este
 * plano não pode. O componente foi feito para isso: status fora das quatro
 * aparece COMO CHEGOU, com o anel aberto e o cinza neutro. Dívida registrada na
 * 180-08-SUMMARY para a fase que possa tocar `identidade/`.
 *
 * ⚠️ E `com_erro` NÃO está aqui de propósito: ele não é um valor de `status`, é
 * o eixo `sub_status` do Mercado Livre (`AcervoTriagemService::SUB_STATUS_COM_ERRO`)
 * — e o item do servidor NÃO manda `sub_status`. Derivar "Com erro" de
 * `severidade` seria INFERIR: um anúncio moderado pode ter `severidade = 0`
 * (medido pelo plano 180-04). A linha mostra o status real do ML e o motivo
 * embaixo; o recorte "Com erro" vive na aba e nos chips. Lacuna na SUMMARY.
 */
const STATUS_DA_PUBLICACAO = {
    active: 'ativa',
    paused: 'pausada',
    under_review: 'em_revisao',
    closed: 'Encerrada',
};

/**
 * A metodologia, do dado real (LIST-20).
 *
 * ⚠️ `null`/ausente é **Legado** — é a AUSÊNCIA de entrada no mapa de
 * metodologia do servidor, e não existe `fase = 0` nem a string `'Legado'`
 * saindo do PHP (plano 180-04). ⚠️ Mas um valor que NÃO é 1, 2 nem ausência
 * (um `7`, um objeto) devolve `null` e o rótulo vira "—": seria um defeito do
 * servidor, e carimbá-lo como "Legado" esconderia o defeito.
 *
 * @param {unknown} fase
 * @returns {1|2|'legado'|null}
 */
export function faseDaLinha(fase) {
    if (fase === 1 || fase === '1') return 1;
    if (fase === 2 || fase === '2') return 2;
    if (fase === null || fase === undefined) return 'legado';
    return null;
}

/**
 * As unidades do combo (Fase 2).
 *
 * ⚠️ Só número inteiro **≥ 2** vale: um "combo" de 1 unidade não é combo, e
 * `RotuloDaFase` escreveria "Combo · 1 un.". Fora disso o complemento fica só
 * "Combo" — nunca "Combo · NaN un.", nunca "Combo · undefined un.".
 */
export function quantidadeDeCombo(quantidade) {
    const numero = numeroMedido(quantidade);
    return numero !== null && Number.isInteger(numero) && numero >= 2 ? numero : null;
}

/** `permalink` só vira `href` quando é uma URL http(s) de verdade (EXMP-01 / T-180-08-T). */
export function urlSegura(valor) {
    return typeof valor === 'string' && /^https?:\/\//.test(valor.trim()) ? valor.trim() : null;
}

// ─────────────────────────────────────────────────────────────────────────
// Classes compartilhadas
// ─────────────────────────────────────────────────────────────────────────

/** O botão de 28px da célula de ações (`:272`). */
export const CLASSE_DO_BOTAO_DE_ACAO =
    'grid h-[28px] w-[28px] place-items-center rounded-pub-control-sm text-pub-txt-3 hover:bg-pub-seg hover:text-pub-txt';

/** O avatar de 24px da célula Publicada (`:265`). */
export const CLASSE_DO_AVATAR = 'grid h-[24px] w-[24px] flex-none place-items-center rounded-full text-[10px] font-semibold';

// ─────────────────────────────────────────────────────────────────────────
// 1. Publicação — tile, título, MLB, copiar, NOVA e nome do produto
// ─────────────────────────────────────────────────────────────────────────

/** O check verde de "copiado" (`:215`) — 1,3 s é temporizador da PÁGINA. */
function IconeCopiado() {
    return (
        <svg
            aria-hidden="true"
            width="11"
            height="11"
            viewBox="0 0 16 16"
            fill="none"
            strokeWidth={2.2}
            strokeLinecap="round"
            strokeLinejoin="round"
            className="stroke-pub-ok-txt"
        >
            <path d="M3.5 8.5l3 3 6-7" />
        </svg>
    );
}

/**
 * O ícone de copiar (`:216`).
 *
 * ⚠️ Este tem `stroke-linejoin:round` e **NÃO** tem `stroke-linecap` — ao
 * contrário do check de cima, que tem os dois. Medido no protótipo, não
 * suposto: é um retângulo (só junta) contra um traço aberto (junta e ponta).
 */
function IconeDeCopiar() {
    return (
        <svg
            aria-hidden="true"
            width="11"
            height="11"
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.6}
            strokeLinejoin="round"
        >
            <rect x="5" y="5" width="8.5" height="8.5" rx="1.5" />
            <path d="M3 10.5V3.5A1 1 0 0 1 4 2.5h6.5" />
        </svg>
    );
}

/**
 * A célula Publicação.
 *
 * @param {object} props
 * @param {object} props.item
 * @param {boolean} [props.agrupada]  visão "Por produto": recuo de 30px, sem tile e sem nome do produto
 * @param {boolean} [props.copiada]
 * @param {(evento: object) => void} [props.onCopiar]
 */
export function CelulaPublicacao({ item, agrupada = false, copiada = false, onCopiar = null }) {
    const dados = item !== null && typeof item === 'object' ? item : {};
    const mlItemId = textoDaTela(dados.ml_item_id);
    const nomeDoProduto = textoDaTela(dados.produto_nome);
    const titulo = textoDaTela(dados.titulo);
    const emGrupo = agrupada === true;

    return (
        <div
            className={cn(
                'flex min-w-0 items-center gap-[12px] pr-[16px]',
                emGrupo ? 'pl-[30px]' : 'pl-0',
            )}
        >
            {!emGrupo && (
                <TileDoProduto
                    id={dados.produto_id}
                    nome={dados.produto_nome}
                    foto={dados.thumbnail}
                    tamanho={36}
                    raio={8}
                />
            )}
            <div className="flex min-w-0 flex-col gap-[3px]">
                <span className="truncate text-[13.5px] font-medium">{titulo === null ? '—' : titulo}</span>
                <span className="flex min-w-0 items-center gap-[6px] overflow-hidden text-[11.5px] text-pub-txt-3">
                    {mlItemId !== null && <span className="font-pub-mono flex-none">{mlItemId}</span>}
                    {mlItemId !== null && (
                        <button
                            type="button"
                            title="Copiar MLB"
                            aria-label={copiada === true ? 'MLB copiado' : 'Copiar MLB'}
                            onClick={onCopiar === null ? undefined : (evento) => {
                                evento.stopPropagation();
                                onCopiar(evento);
                            }}
                            className="grid h-[18px] w-[18px] flex-none cursor-pointer place-items-center rounded-pub-badge bg-transparent text-pub-txt-4 hover:bg-pub-seg hover:text-pub-txt"
                        >
                            {copiada === true ? <IconeCopiado /> : <IconeDeCopiar />}
                        </button>
                    )}
                    {dados.nova === true && <BadgeNova />}
                    {!emGrupo && nomeDoProduto !== null && (
                        <span aria-hidden="true" className="flex-none text-pub-txt-off">·</span>
                    )}
                    {!emGrupo && nomeDoProduto !== null && (
                        <span className="min-w-0 truncate">{nomeDoProduto}</span>
                    )}
                </span>
            </div>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────
// 2. Metodologia
// ─────────────────────────────────────────────────────────────────────────

/**
 * A célula Metodologia (LIST-20).
 *
 * ⚠️ É `<RotuloDaFase>` SOZINHO, e não `<GlyphDaFase>` + `<RotuloDaFase>` como
 * a tabela do plano prescrevia: `RotuloDaFase` JÁ compõe o glyph internamente
 * (`RotuloDaFase.jsx`), e desenhar os dois renderizaria DOIS glyphs na coluna.
 * Medido no protótipo: `:222` é um `flex gap:10px` com UM glyph (`:224`-`:232`)
 * e a coluna de texto (`:233`-`:235`) — exatamente o que `RotuloDaFase` emite,
 * com `gap-[10px]` no tamanho `lista`.
 */
export function CelulaMetodologia({ item }) {
    const dados = item !== null && typeof item === 'object' ? item : {};

    return (
        <RotuloDaFase
            fase={faseDaLinha(dados.fase)}
            quantidade={quantidadeDeCombo(dados.quantidade_kit)}
            tamanho="lista"
            className="flex"
        />
    );
}

// ─────────────────────────────────────────────────────────────────────────
// 3. Tipo
// ─────────────────────────────────────────────────────────────────────────

/**
 * A célula Tipo (LIST-20).
 *
 * ⚠️ Tipo fora da lista fechada cai no "—" do `textoSeguro` e **não** ganha o
 * losango: o losango SIGNIFICA Premium, e pôr um num tipo que não chegou seria
 * informação inventada. "—" é o vocabulário de "não medido" deste módulo — não
 * é um rótulo de tipo, que é o que LIST-20 proíbe inventar.
 */
export function CelulaTipo({ item }) {
    const dados = item !== null && typeof item === 'object' ? item : {};

    return (
        <SeloDoTipo
            tipo={valorDoMapa(ROTULO_DO_TIPO, dados.tipo)}
            catalogo={dados.catalogo === true}
            className="flex"
        />
    );
}

// ─────────────────────────────────────────────────────────────────────────
// 4. Status — e a casa da Nota ECF "de 86" nesta fase
// ─────────────────────────────────────────────────────────────────────────

/**
 * O texto da Nota ECF, com o literal **"de 86"** (D-22 / LIST-24 / critério 15).
 *
 * ⚠️ A base 86 é LITERAL de exibição e a nota NUNCA é renormalizada para 100 —
 * é o mesmo literal de `MeusAnuncios.jsx:211`, que o gate
 * `estrutura-meus-anuncios.test.js` cobra. Não existe `/ 100` nem `* 100 / 86`
 * neste arquivo.
 */
export function textoDaNotaEcf(notaEcf) {
    const nota = numeroMedido(notaEcf);
    return nota === null ? 'Nota ECF ainda não avaliada' : `Nota ECF ${nota} de 86`;
}

/**
 * A célula Status.
 *
 * ⚠️ ONDE A NOTA ECF FICA NESTA FASE: a coluna "Nota ECF" sai do grid (LIST-24)
 * e migra para o painel de inspeção da Fase 181 — que ainda não existe. Até lá
 * ela mora AQUI, no `title` da célula (para o mouse) **e** num `sr-only`
 * (para teclado e leitor de tela).
 *
 * ⚠️ DIVERGÊNCIA DECLARADA da prescrição do plano, que pedia `title` **e**
 * `aria-label` com o mesmo texto: `aria-label` SUBSTITUI o nome acessível
 * calculado a partir do conteúdo, então ele APAGARIA "Ativa" e o motivo para
 * quem usa leitor de tela — ficaria pior do que o problema que IDENT-07 quer
 * evitar. E em elemento genérico (`<div>`/`<span>` sem papel) o suporte a
 * `aria-label` não é garantido pela própria ARIA. O `sr-only` é TEXTO de
 * verdade no DOM: é lido em ACRÉSCIMO ao status, não no lugar dele.
 *
 * @param {object} props
 * @param {object} props.item
 * @param {Record<string, string>|null} [props.rotulosDeMotivo]  `{chave: label}`
 *   de `triagem.chips`. ⚠️ Sem ele a 2ª linha NÃO aparece: `motivos` chega do
 *   servidor como SLUG (`sem_estoque`), e slug na tela é jargão — é exatamente
 *   o que `MeusAnuncios.jsx:596` já faz hoje (`if (!info) return null`).
 */
export function CelulaStatus({ item, rotulosDeMotivo = null }) {
    const dados = item !== null && typeof item === 'object' ? item : {};
    const motivos = Array.isArray(dados.motivos) ? dados.motivos : [];
    const mapa = rotulosDeMotivo !== null && typeof rotulosDeMotivo === 'object' ? rotulosDeMotivo : {};
    const nota = textoDaNotaEcf(dados.nota_ecf);

    return (
        <div className="min-w-0" title={nota}>
            <SeloDeStatusDaPublicacao
                status={valorDoMapa(STATUS_DA_PUBLICACAO, dados.status)}
                motivo={motivos.length > 0 ? valorDoMapa(mapa, motivos[0]) : null}
                className="flex"
            />
            <span className="sr-only">{nota}</span>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────
// 5. Preço — o "—" que impede a tela de inventar número (LIST-12)
// ─────────────────────────────────────────────────────────────────────────

/** O triângulo cheio do "Prejuízo" (`:256`) — forma + cor + texto, nunca só cor. */
function TrianguloDePrejuizo() {
    return (
        <svg aria-hidden="true" width="10" height="10" viewBox="0 0 16 16" className="fill-pub-danger">
            <path d="M8 1.5L15 14H1z" />
        </svg>
    );
}

/**
 * A célula Preço (LIST-12 / critério 10).
 *
 * ⚠️ `margem` ausente → a 2ª linha é **"—"**. É o estado NORMAL desta fase: o
 * item do servidor não tem `margem` por decisão explícita do plano 180-04 (a
 * tarifa do ML não está no banco). "Margem 0,0%" seria um número inventado numa
 * tela financeira, que é pior que ausência.
 *
 * ⚠️ `preco` ausente também é **"—"**, e `0` é `R$ 0,00`: zero é medida, nulo
 * não é.
 */
export function CelulaPreco({ item }) {
    const dados = item !== null && typeof item === 'object' ? item : {};
    const preco = moedaBRL(dados.preco);
    const margem = numeroMedido(dados.margem);
    const prejuizo = margem !== null && margem < 0;
    const percentual = prejuizo ? null : percentualDaMargem(dados.margem);

    return (
        <div className="flex flex-col items-end gap-[2px] tabular-nums">
            <span className="text-[13.5px] font-medium">{preco === null ? '—' : preco}</span>
            {prejuizo && (
                <span className="flex items-center gap-[4px] text-[11.5px] font-semibold text-pub-danger">
                    <TrianguloDePrejuizo />
                    Prejuízo
                </span>
            )}
            {!prejuizo && (
                <span className="text-[11.5px] text-pub-txt-3">
                    {percentual === null ? '—' : `Margem ${percentual}`}
                </span>
            )}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────
// 6. Vendas
// ─────────────────────────────────────────────────────────────────────────

/**
 * A célula Vendas.
 *
 * ⚠️ `vendas` nulo → NADA (não "0"). `vendas: 0` → `0` em `pub-txt-off`, que é
 * zero MEDIDO. `visitas` nulo → a 2ª linha não aparece.
 *
 * ⚠️ O selo "Não avaliado" que a coluna Visitas de HOJE mostra nesse caso NÃO
 * desapareceu do sistema: ele vive nos chips de triagem (plano 180-07, já na
 * tela) e no `ModalDetalheAnuncio`, que segue montado. Registrado na SUMMARY.
 *
 * ⚠️ O número de VENDAS sai sem separador de milhar e o de VISITAS sai com —
 * é o protótipo (`:261` interpola `p.sales` cru e `:262` usa
 * `toLocaleString('pt-BR')`). Medido, não uniformizado: FID-02 veta "ficou mais
 * parecido com o resto".
 */
export function CelulaVendas({ item }) {
    const dados = item !== null && typeof item === 'object' ? item : {};
    const vendas = numeroMedido(dados.vendas);
    const visitas = inteiroPtBr(dados.visitas);

    return (
        <div className="flex flex-col items-end gap-[2px] tabular-nums">
            {vendas !== null && (
                <span className={cn('text-[13.5px] font-medium', vendas === 0 && 'text-pub-txt-off')}>
                    {vendas}
                </span>
            )}
            {visitas !== null && <span className="text-[11.5px] text-pub-txt-3">{visitas} vis.</span>}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────
// 7. Publicada — o autor que a tela DIZ não conhecer (LIST-13)
// ─────────────────────────────────────────────────────────────────────────

/**
 * A célula Publicada (LIST-13 / critério 9).
 *
 * ⚠️ `autor_nome` é renderizado COMO VEIO. Os quatro literais de autor
 * desconhecido (`Equipe`, `Cliente`, `Origem antiga`, `Autor não registrado`,
 * de `PainelVisaoGeralService::QUEM_*`) são texto normal — nunca "—", nunca um
 * nome inventado, nunca o nome de outra publicação.
 *
 * ⚠️ `autor_tipo === 'desconhecido'` → o avatar mostra o ícone genérico. Com
 * iniciais, `Autor não registrado` daria `'AN'`, que PARECE um nome de pessoa.
 *
 * ⚠️ O avatar é `aria-hidden` e o nome completo é TEXTO visível ao lado dele —
 * então não há informação só no `title` (IDENT-07). O `title` fica porque o
 * protótipo o tem (`:265`) e porque o nome pode truncar.
 *
 * ⚠️ IDENT-08: tom e iniciais vêm de `tomDoProduto()`/`iniciais()`. Nenhuma tela
 * reimplementa — é o mesmo caminho que `ChipsDeFiltro.jsx` usa no avatar do
 * popover de Responsável.
 */
export function CelulaPublicada({ item, hoje = null }) {
    const dados = item !== null && typeof item === 'object' ? item : {};
    const autor = textoDaTela(dados.autor_nome);
    const desconhecido = dados.autor_tipo === 'desconhecido';
    const letras = desconhecido ? '' : iniciais(dados.autor_nome);
    const tom = tomDoProduto(autor === null ? '' : autor);
    const data = dataRelativa(dados.publicada_em, hoje);

    return (
        <div className="flex min-w-0 items-center gap-[8px] pl-[18px]">
            <span
                aria-hidden="true"
                title={autor === null ? undefined : autor}
                style={{ background: tom.fundo, color: tom.texto }}
                className={CLASSE_DO_AVATAR}
            >
                {letras !== '' ? letras : <UserRound strokeWidth={2.4} className="h-[12px] w-[12px]" />}
            </span>
            <div className="flex min-w-0 flex-col gap-[1px]">
                {data !== null && <span className="truncate text-[12.5px] font-medium">{data}</span>}
                {autor !== null && <span className="truncate text-[11.5px] text-pub-txt-3">{autor}</span>}
            </div>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────
// 8. Ações — nenhum botão sem destino (EXMP-01)
// ─────────────────────────────────────────────────────────────────────────

/** O ícone de link externo (`:273`). */
function IconeAbrirNoMl() {
    return (
        <svg
            aria-hidden="true"
            width="14"
            height="14"
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.6}
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M9 2.5h4.5V7M13.5 2.5L7.5 8.5M11.5 9.5v3a1 1 0 0 1-1 1h-7a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1h3" />
        </svg>
    );
}

/** O chevron decorativo (`:275`) — num `<span>`, como no protótipo. */
function ChevronDaLinha() {
    return (
        <svg
            aria-hidden="true"
            width="14"
            height="14"
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.8}
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M6 4l4 4-4 4" />
        </svg>
    );
}

/**
 * A célula de ações (LIST-14 / LIST-23 / EXMP-01).
 *
 * ⚠️ `permalink` ausente ou não-URL → **o botão não é desenhado**. Nenhum botão
 * sem destino, nenhum `href="#"`: `textoUtil(7)` devolvendo `'7'` já montou rota
 * inválida três vezes nesta milestone, e aqui a guarda é mais estrita ainda
 * (tem de começar com `http`). `rel="noreferrer"` é obrigatório (T-180-08-T:
 * impede `window.opener`) — o protótipo não o tem, e esta é uma adição
 * DECLARADA de segurança.
 *
 * ⚠️ A DECISÃO DOS 64px: três botões de 28px com `gap:2px` dariam 88px e não
 * cabem. "Abrir rascunho" é atalho VIVO e não pode sumir (LIST-23), então
 * **o chevron é que cede o lugar** quando o rascunho existe — ele é decoração
 * de "abre o painel", que é a Fase 181, e a linha inteira já é clicável. Com
 * dois elementos são 28+2+28 = 58px, dentro dos 64.
 *
 * @param {object} props
 * @param {object} props.item
 * @param {string|null} [props.urlDoRascunho]  montado por quem chama (`route('mlb.anuncios.wizard', …)`)
 */
export function CelulaAcoes({ item, urlDoRascunho = null }) {
    const dados = item !== null && typeof item === 'object' ? item : {};
    const linkDoMl = urlSegura(dados.permalink);
    const temRascunho = dados.origem === 'ecf'
        && dados.rascunho_id !== null
        && dados.rascunho_id !== undefined
        && caminhoSeguro(urlDoRascunho) !== null;

    return (
        <div className="flex items-center justify-end gap-[2px]">
            {linkDoMl !== null && (
                <a
                    href={linkDoMl}
                    target="_blank"
                    rel="noreferrer"
                    title="Abrir no Mercado Livre"
                    aria-label="Abrir no Mercado Livre"
                    onClick={(evento) => evento.stopPropagation()}
                    className={CLASSE_DO_BOTAO_DE_ACAO}
                >
                    <IconeAbrirNoMl />
                </a>
            )}
            {temRascunho && (
                <a
                    href={caminhoSeguro(urlDoRascunho)}
                    title="Abrir rascunho"
                    aria-label="Abrir rascunho"
                    onClick={(evento) => evento.stopPropagation()}
                    className={CLASSE_DO_BOTAO_DE_ACAO}
                >
                    <Pencil strokeWidth={2.4} className="h-[14px] w-[14px]" />
                </a>
            )}
            {!temRascunho && (
                <span aria-hidden="true" className={CLASSE_DO_BOTAO_DE_ACAO}>
                    <ChevronDaLinha />
                </span>
            )}
        </div>
    );
}
