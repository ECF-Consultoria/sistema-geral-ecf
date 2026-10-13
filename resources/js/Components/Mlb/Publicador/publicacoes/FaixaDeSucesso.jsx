import { textoUtil } from '@/Components/Mlb/Publicador/BarraDaConta';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 180, plano 05 — a faixa de sucesso pós-publicação (LIST-02).
//
// Protótipo: `new_layout_publicador/Publicacoes v2.dc.html`, bloco
// `<sc-if value="{{ showToast }}">`. Linhas MEDIDAS com `grep -n`:
//   · a faixa            → :53 (`gap:14px;padding:12px 14px 12px 16px;
//                                background:#14120A;border:1px solid
//                                rgba(250,204,21,0.35);border-radius:12px`)
//   · círculo do check   → :54 (`26×26;border-radius:50%;background:#FACC15;
//                                display:grid;place-items:center;flex:none`)
//   · o check            → :55 (`13×13 viewBox 0 0 16 16;stroke:#0A0A0A;
//                                stroke-width:2.2`)
//   · coluna do texto    → :57 (`gap:2px;flex:1;min-width:0`)
//   · linha 1            → :58 (`font-size:13.5px;font-weight:600`)
//   · linha 2            → :59 (`font-size:12.5px;color:#A1A1AA`)
//   · "Localizar…"       → :61 (`32px;0 14px;sem borda;raio 8px;#FACC15;
//                                #0A0A0A;12.5px/500`)
//   · o "×"              → :62 (`32×32;raio 8px;transparente;#8A8A90`,
//                                hover `#26220F`, `title="Dispensar"`)
//   · ícone do "×"       → :63 (`14×14 viewBox 16;stroke-width:1.7`)
//
// ⚠️ `flex-none` SÓ no círculo do check: varri `:30`-`:66` e `flex:none`
// aparece UMA vez em todo o bloco, em `:54`. O primeiro rascunho desta faixa
// tinha posto `flex-none` nos dois botões também — adaptação, e desnecessária,
// porque a coluna do texto é `flex:1;min-width:0` e é ela que absorve a sobra.
//
// ⚠️ `cursor-pointer` nos dois botões porque o protótipo o declara (`:61`,
// `:62`) — a tabela FID-01 do plano o omitiu. O protótipo ganha.
//
// ⚠️ SEM `truncate` na linha 2: o protótipo não declara `text-overflow` em
// nenhuma das duas linhas (só `min-width:0` na coluna, `:57`). Acrescentar
// elipse seria melhoria não pedida numa fase de fidelidade.
//
// ⚠️ `rounded-[8px]` literal, e NÃO `rounded-pub-control-sm` (7px) nem
// `rounded-pub-sub` (10px): o protótipo diz 8 e não existe token de 8px.
// Medida avulsa vai literal (FID-03). Arredondar para o token mais perto é
// exatamente a "adaptação ao sistema atual" que o usuário recusou.
//
// ⚠️ O texto do protótipo ("2 publicações criadas agora · Poltrona Beny" /
// "Fase 2 · Combo 2 unidades · Clássico e Premium · publicadas por você") é
// dado MOCK. O que fica é a FORMA da frase; **nenhum nome de produto fixo no
// código** e nenhum número inventado (EXMP-01).
//
// ⚠️ A FONTE desta faixa NÃO EXISTE HOJE — medido em 13/10/2026 e registrado na
// 180-05-SUMMARY: zero `session()->put/flash` com resumo de publicação em
// `app/`, zero chave desse tipo na lista branca de `flash` do
// `HandleInertiaRequests` (chave fora dela morre no caminho, em silêncio), e a
// publicação é ASSÍNCRONA (`MlbPublicadorController::publicar()` devolve 202 e
// enfileira). Logo `resumo` chega `null` SEMPRE e a faixa nunca aparece. O
// layout fica pronto para quando a fonte existir — e isso é pendência nomeada,
// não um resumo de exemplo.
//
// ⚠️ D-11 nesta fase: `onLocalizar` e `onDispensar` são callbacks LOCAIS; este
// arquivo não tem `router.*` nenhum. Quem decide o que o clique faz é a página
// (planos 180-10 / 180-11).
//
// ⚠️ TODA classe do Tailwind mora NESTE `.jsx` (`tailwind.config.js:12` varre
// `*.jsx` e não `*.js`).
// ═══════════════════════════════════════════════════════════════════════════

/** Os literais de tela, byte a byte. */
export const LOCALIZAR = 'Localizar na lista';
export const DISPENSAR = 'Dispensar';
export const UMA_PUBLICACAO = 'publicação criada agora';
export const VARIAS_PUBLICACOES = 'publicações criadas agora';

/** O separador das duas linhas da faixa (`:58`/`:59` do protótipo). */
const SEPARADOR = ' · ';

/**
 * Só número inteiro ≥ 1 vale como contagem.
 *
 * ⚠️ String numérica NÃO é aceita de propósito: `textoUtil(7)` devolve `'7'` e
 * `textoSeguro(NaN)` devolve a string `'NaN'` (porque `typeof NaN` é
 * `'number'`) — as duas já montaram rótulo e rota inválidos nesta milestone.
 * Contagem é number, de forma fechada, ou não é contagem.
 *
 * @param {unknown} valor
 * @returns {number|null}
 */
function contagem(valor) {
    if (typeof valor !== 'number' || !Number.isInteger(valor) || valor < 1) return null;
    return valor;
}

/**
 * Irmã ESTRITA de `textoUtil`: aqui `7` não pode virar `'7'` e `NaN` não pode
 * virar `'NaN'`.
 *
 * ⚠️ É por isso que ela existe, e vale para os DOIS usos desta faixa:
 *   · o que vira parâmetro de URL / chave de seleção (`busca_sugerida`,
 *     `ml_item_id`) — `textoUtil(7)` devolvendo `'7'` já montou rota inválida
 *     três vezes nesta milestone;
 *   · o que vira pedaço de FRASE (`produto_nome`, `*_rotulo`) — todos são
 *     `?string` no contrato, e `textoSeguro(NaN)` devolve a string `'NaN'`
 *     (porque `typeof NaN` é `'number'`), que apareceria na tela como
 *     "2 publicações criadas agora · NaN".
 *
 * Construída sobre a função da casa (`textoUtil` de `BarraDaConta`, onde ela
 * mora desde a Fase 172) — uma regra, uma casa; esta só fecha a porta do
 * número.
 *
 * @param {unknown} valor
 * @returns {string|null}
 */
function soString(valor) {
    return typeof valor === 'string' ? textoUtil(valor) : null;
}

/**
 * A frase da faixa, montada das props.
 *
 * @param {unknown} resumo
 * @returns {{linha1: string, linha2: string|null, busca: string|null, item: string|null}|null}
 */
export function fraseDaFaixa(resumo) {
    if (resumo === null || typeof resumo !== 'object' || Array.isArray(resumo)) return null;

    const total = contagem(resumo.total);
    // Sem contagem confiável a faixa não tem o que anunciar: a razão dela
    // existir É o "N publicações criadas agora". Inventar o N é EXMP-01.
    if (total === null) return null;

    const produto = soString(resumo.produto_nome);
    const base = `${total} ${total === 1 ? UMA_PUBLICACAO : VARIAS_PUBLICACOES}`;

    // ⚠️ Pedaço ausente é OMITIDO, nunca substituído por "—": a linha 2 junta
    // só os rótulos que vieram como texto útil.
    const pedacos = [
        soString(resumo.fase_rotulo),
        soString(resumo.tipos_rotulo),
        soString(resumo.autor_rotulo),
    ].filter((pedaco) => pedaco !== null);

    return {
        linha1: produto === null ? base : `${base}${SEPARADOR}${produto}`,
        linha2: pedacos.length === 0 ? null : pedacos.join(SEPARADOR),
        busca: soString(resumo.busca_sugerida),
        item: soString(resumo.ml_item_id),
    };
}

/** O check do círculo amarelo (`:55`). SVG inline com o `viewBox` do protótipo. */
function IconeDeCheck() {
    return (
        <svg
            aria-hidden="true"
            width="13"
            height="13"
            viewBox="0 0 16 16"
            strokeWidth="2.2"
            strokeLinecap="round"
            strokeLinejoin="round"
            className="fill-none stroke-pub-page"
        >
            <path d="M3.5 8.5l3 3 6-7" />
        </svg>
    );
}

/** O "×" de dispensar (`:63`). */
function IconeDeFechar() {
    return (
        <svg
            aria-hidden="true"
            width="14"
            height="14"
            viewBox="0 0 16 16"
            strokeWidth="1.7"
            strokeLinecap="round"
            className="fill-none stroke-current"
        >
            <path d="M4 4l8 8M12 4l-8 8" />
        </svg>
    );
}

/**
 * A faixa "publiquei no Publicador → voltei para a lista → achei o que acabei
 * de criar".
 *
 * @param {object} props
 * @param {unknown} [props.resumo] o resumo da publicação que acabou de ocorrer;
 *   `null` (o caso de hoje) = a faixa não existe no HTML
 * @param {unknown} [props.onLocalizar] handler da página; sem ele, e sem
 *   `busca_sugerida`/`ml_item_id` em forma de string, o botão NÃO é desenhado
 * @param {unknown} [props.onDispensar] handler da página; sem ele o "×" não é
 *   desenhado
 */
export default function FaixaDeSucesso({ resumo = null, onLocalizar = null, onDispensar = null }) {
    const frase = fraseDaFaixa(resumo);
    if (frase === null) return null;

    // ⚠️ EXMP-01 — nenhum botão sem destino. "Localizar na lista" só nasce com
    // handler E com o que preencher na busca E com qual publicação selecionar
    // (o contrato que o plano 180-11 liga). Aqui NÃO desenhamos desabilitado:
    // a faixa inteira é opcional, então um botão a menos não deixa o usuário
    // sem caminho — já um botão morto mente.
    const podeLocalizar = typeof onLocalizar === 'function' && frase.busca !== null && frase.item !== null;
    const podeDispensar = typeof onDispensar === 'function';

    return (
        <div className="flex items-center gap-[14px] rounded-pub-panel border border-pub-accent/[0.35] bg-pub-row-new py-[12px] pl-[16px] pr-[14px]">
            <span
                aria-hidden="true"
                className="grid h-[26px] w-[26px] flex-none place-items-center rounded-full bg-pub-accent"
            >
                <IconeDeCheck />
            </span>

            <div className="flex min-w-0 flex-1 flex-col gap-[2px]">
                <span className="text-[13.5px] font-semibold">{frase.linha1}</span>
                {frase.linha2 !== null && (
                    <span className="text-[12.5px] text-pub-neutral-txt">{frase.linha2}</span>
                )}
            </div>

            {podeLocalizar && (
                <button
                    type="button"
                    onClick={() => onLocalizar(frase.busca, frase.item)}
                    className="h-[32px] cursor-pointer rounded-[8px] bg-pub-accent px-[14px] text-[12.5px] font-medium text-pub-page"
                >
                    {LOCALIZAR}
                </button>
            )}

            {podeDispensar && (
                <button
                    type="button"
                    title={DISPENSAR}
                    aria-label={DISPENSAR}
                    onClick={onDispensar}
                    className="grid h-[32px] w-[32px] cursor-pointer place-items-center rounded-[8px] text-pub-txt-3 hover:bg-pub-hover-toast"
                >
                    <IconeDeFechar />
                </button>
            )}
        </div>
    );
}
