import { isValidElement } from 'react';
import { cn } from '@/lib/utils';
import { textoUtil } from '@/Components/Mlb/Publicador/BarraDaConta';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 180, plano 05 — o cabeçalho da tela de Publicações (LIST-01).
//
// Protótipo: `new_layout_publicador/Publicacoes v2.dc.html`. Linhas MEDIDAS com
// `grep -n` (não copiadas da tabela do plano — e, desta vez, a tabela bateu):
//   · `<header>` do bloco  → :31  (`align-items:flex-end;justify-content:space-between;gap:24px`)
//   · coluna do título     → :32  (`gap:6px`)
//   · H1 "Publicações"     → :33  (`font-size:28px;line-height:1;font-weight:600;letter-spacing:-0.025em;margin:0`)
//   · subtítulo            → :34  (`font-size:13.5px;color:#A1A1AA`)
//   · bloco da direita     → :36  (`gap:10px`)
//   · "Sincronizado {X}"   → :37  (`gap:6px;font-size:12.5px;color:#A1A1AA;padding-right:4px`)
//   · dot verde            → :38  (`6×6;border-radius:50%;background:#22C55E`)
//   · botão "Atualizar"    → :39  (`36px;0 14px;1px #2E2E2E;raio 9px;#111111;13px/500;#F4F4F5;gap:7px`,
//                                  hover `#161616`)
//   · spinner              → :41  (`13×13;border:1.6px #3F3F46;border-top-color:#FACC15;spin .7s`)
//   · ícone de refresh     → :44  (`14×14 viewBox 0 0 16 16 stroke-width:1.6`)
//   · rótulos do botão     → :619 (`S.syncing ? 'Atualizando…' : 'Atualizar'`)
//
// ⚠️ O container do `<main>` (`:30`, `padding:28px 36px 110px;max-width:1600px;
// margin:0 auto;gap:18px`) NÃO mora aqui: compor a página é do plano 180-10.
// A medida está registrada na 180-05-SUMMARY para ele.
//
// ⚠️ "nunca medimos" ≠ "medimos e é zero": sem coleta não existe "Sincronizado
// há —" nem "Sincronizado nunca" — o bloco inteiro NÃO aparece, e quem resolve
// é o botão "Atualizar", que continua. Afirmar sincronização que ninguém mediu
// é exatamente o que EXMP-01 proíbe.
//
// ⚠️ DATA POR REGEX, NUNCA `new Date()`. O `rotulo` chega PRONTO do servidor
// ("há 25 min" / "agora"); montar aqui traria `Invalid Date` e o deslocamento
// de fuso de data-only em BRT, que já morderam este projeto duas vezes.
//
// ⚠️ D-11: nenhum `router.*` neste arquivo. O único `router.post` da tela
// (`meus.atualizar`, que ENFILEIRA) segue na página, como hoje — o botão aqui
// recebe o `onClick` de lá e nada mais.
//
// ⚠️ TODA classe do Tailwind mora NESTE `.jsx`: o `content` do
// `tailwind.config.js:12` varre `*.jsx` e **não** `*.js`. Mapa de classe em
// módulo `.js` não gera CSS e não existe para a tela, com os testes de render
// passando.
// ═══════════════════════════════════════════════════════════════════════════

/** Os literais de tela, byte a byte como o protótipo e o README os escrevem. */
export const TITULO = 'Publicações';
export const SUBTITULO = 'Ofertas ativas e históricas desta empresa no Mercado Livre';
export const PREFIXO_SINCRONIZADO = 'Sincronizado';
export const ATUALIZAR = 'Atualizar';
export const ATUALIZANDO = 'Atualizando…';

/**
 * Qualquer coisa com cara de data crua (`2026-10-13`, `2026-10-13T12:00:00Z`).
 *
 * ⚠️ É a guarda da regra "data por REGEX, nunca `new Date()`": se o servidor
 * mandar o ISO no lugar do rótulo humano, a tela diria "Sincronizado
 * 2026-10-13T12:00:00Z" — jargão cru, que a regra permanente do projeto proíbe.
 * Preferimos NÃO mostrar nada a mostrar o carimbo de máquina.
 */
const RE_DATA_CRUA = /\d{4}-\d{2}-\d{2}/;

/**
 * O rótulo pronto de sincronização, ou `null` quando não há o que afirmar.
 *
 * @param {unknown} sincronizacao o bloco do servidor — pode ser NULO inteiro
 * @returns {string|null}
 */
export function rotuloDeSincronizacao(sincronizacao) {
    if (sincronizacao === null || typeof sincronizacao !== 'object' || Array.isArray(sincronizacao)) {
        return null;
    }
    // `nunca_coletado` é a diferença entre "nunca medimos" e "medimos e é zero".
    if (sincronizacao.nunca_coletado === true) return null;

    const rotulo = typeof sincronizacao.rotulo === 'string' ? textoUtil(sincronizacao.rotulo) : null;
    if (rotulo === null) return null;
    if (RE_DATA_CRUA.test(rotulo)) return null;

    return rotulo;
}

/**
 * Ícone de refresh do protótipo (`:44`).
 *
 * ⚠️ SVG inline com o `viewBox` do protótipo (`0 0 16 16`) em vez do
 * `RefreshCw` do Lucide: o desenho do Lucide não é este e o `viewBox` dele é
 * `0 0 24 24`, o que exigiria `strokeWidth` × 1,5. Foi a escolha da Fase 179
 * nos mesmos casos.
 */
function IconeDeRefresh() {
    return (
        <svg
            aria-hidden="true"
            width="14"
            height="14"
            viewBox="0 0 16 16"
            strokeWidth="1.6"
            strokeLinecap="round"
            strokeLinejoin="round"
            className="flex-none fill-none stroke-current"
        >
            <path d="M13.5 8A5.5 5.5 0 1 1 11.8 4" />
            <path d="M13.5 2.5V5H11" />
        </svg>
    );
}

/**
 * O botão "Atualizar" do cabeçalho, com a geometria do protótipo (`:39`-`:47`).
 *
 * Export NOMEADO e não embutido: o contrato do cabeçalho é um SLOT
 * (`acaoAtualizar`), porque quem sabe para onde o clique vai é a página. Mas a
 * geometria tem de morar num `.jsx` desta fase para (a) gerar CSS e (b) ser
 * alcançável pelo gate — por isso o botão nasce aqui e a página só o monta.
 *
 * ⚠️ Sem `onClick` função o botão NÃO é desenhado (EXMP-01: botão sem destino
 * é proibido). Desenhá-lo desabilitado afirmaria "existe um Atualizar, só está
 * ocupado", que é outra coisa.
 *
 * @param {object} props
 * @param {boolean} [props.atualizando] liga o spinner e troca o rótulo
 * @param {boolean} [props.cooldown] janela de espera depois de enfileirar
 * @param {unknown} [props.onClick] o handler da página
 */
export function BotaoAtualizarDoCabecalho({ atualizando = false, cooldown = false, onClick }) {
    if (typeof onClick !== 'function') return null;

    const girando = atualizando === true;
    const bloqueado = girando || cooldown === true;

    return (
        <button
            type="button"
            onClick={onClick}
            disabled={bloqueado}
            className={cn(
                'flex h-[36px] flex-none items-center gap-[7px] rounded-pub-control border border-pub-line-strong bg-pub-surface px-[14px] text-[13px] font-medium text-pub-txt hover:bg-pub-hover-2',
                bloqueado ? 'cursor-wait' : 'cursor-pointer',
            )}
        >
            {girando ? (
                <span
                    aria-hidden="true"
                    className="h-[13px] w-[13px] flex-none animate-pub-spin rounded-full border-[1.6px] border-pub-line-off border-t-pub-accent"
                />
            ) : (
                <IconeDeRefresh />
            )}
            {girando ? ATUALIZANDO : ATUALIZAR}
        </button>
    );
}

/**
 * O cabeçalho da tela de Publicações: H1, subtítulo, "● Sincronizado há X" e o
 * slot do botão "Atualizar".
 *
 * @param {object} props
 * @param {unknown} [props.sincronizacao] `{coletado_em, horas, rotulo, nunca_coletado}` ou `null`
 * @param {unknown} [props.acaoAtualizar] o nó do botão, montado pela página
 */
export default function CabecalhoDePublicacoes({ sincronizacao = null, acaoAtualizar = null }) {
    const rotulo = rotuloDeSincronizacao(sincronizacao);

    // ⚠️ NUNCA `String(acaoAtualizar)`: campo-objeto renderizado como texto
    // derrubou a página inteira em 07/10/2026 (`[object Object]` na tela). Nó
    // inválido simplesmente não é desenhado.
    const acao = isValidElement(acaoAtualizar) ? acaoAtualizar : null;

    return (
        <header className="flex items-end justify-between gap-[24px]">
            <div className="flex flex-col gap-[6px]">
                <h1 className="m-0 text-[28px] font-semibold leading-none tracking-[-0.025em]">{TITULO}</h1>
                <span className="text-[13.5px] text-pub-neutral-txt">{SUBTITULO}</span>
            </div>

            <div className="flex items-center gap-[10px]">
                {rotulo !== null && (
                    <span className="flex items-center gap-[6px] pr-[4px] text-[12.5px] text-pub-neutral-txt">
                        <span aria-hidden="true" className="h-[6px] w-[6px] flex-none rounded-full bg-pub-ok" />
                        {`${PREFIXO_SINCRONIZADO} ${rotulo}`}
                    </span>
                )}
                {acao}
            </div>
        </header>
    );
}
