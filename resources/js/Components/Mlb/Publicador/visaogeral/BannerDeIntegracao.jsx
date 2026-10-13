import { bannerParaTela } from './recentes';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 179, plano 08 — o banner de integração (VG-02).
//
// Protótipo: `new_layout_publicador/Visao Geral.dc.html`:28-:36 (o `banner` da
// aritmética em `:594`). ⚠️ O `<action>` do plano cita `:29-:36`; o wrapper
// está em `:28`.
//
// ⚠️ O banner SÓ APARECE COM PROBLEMA (VG-02): `banner` nulo devolve `null`,
// não um quadro vazio nem um banner verde dizendo "tudo bem". O protótipo o
// envolve num `<sc-if value="{{ hasBanner }}">` (`:27`) pelo mesmo motivo.
//
// ⚠️ O ÍCONE É DESENHADO À MÃO, com os dois `path` do protótipo (`:30`) em
// `viewBox="0 0 16 16"` e `stroke-width:1.7` — SEM conversão ×1,5, porque não
// há Lucide aqui. O `<action>` prescreve `<Unlink size={16} strokeWidth={2.55} />`,
// e a geometria do Lucide é OUTRA (medida em
// `node_modules/lucide-react/dist/esm/icons/unlink.mjs`): dois arcos de raio 5
// com quatro marcas de tique (`M8 2v3`, `M2 8h3`, …) e **nenhum traço
// diagonal**. O protótipo desenha a corrente partida MAIS a diagonal
// `M2 2l12 12`, que é o que diz "desconectado". Mesma saída do D-1 do 179-07.
// ═══════════════════════════════════════════════════════════════════════════

/** A corrente partida com a diagonal, byte a byte do protótipo (`:30`). */
function IconeDesconectado() {
    return (
        <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" className="fill-none stroke-pub-danger-txt">
            <path
                d="M6.5 9.5l3-3M5 7L3.5 8.5a2.5 2.5 0 0 0 3.5 3.5L8.5 10.5M11 9l1.5-1.5A2.5 2.5 0 0 0 9 4L7.5 5.5"
                strokeWidth={1.7}
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <path d="M2 2l12 12" strokeWidth={1.7} strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

/**
 * O aviso de integração desconectada, no topo da Visão Geral.
 *
 * @param {object} props
 * @param {unknown} props.banner  o retorno de `CockpitDaContaService::banner()`:
 *   `null` (token ativo) ou `{chave: 'ml', link_reconexao: ?string}`.
 */
export default function BannerDeIntegracao({ banner }) {
    const dados = bannerParaTela(banner);

    // VG-02: sem problema não há banner. Chave fora do mapa também não ganha
    // banner — o texto é que carrega a informação, e um banner genérico
    // afirmaria um problema que ninguém sabe descrever.
    if (dados === null) return null;

    return (
        <div className="flex items-center gap-[14px] rounded-pub-panel border border-pub-danger/[0.4] bg-pub-danger/[0.07] px-[16px] py-[14px]">
            {/* ─── Tile do ícone (:29) — 34×34, raio 9, tinta 0.14 ─── */}
            <div className="grid h-[34px] w-[34px] flex-none place-items-center rounded-[9px] bg-pub-danger/[0.14]">
                <IconeDesconectado />
            </div>

            {/* ─── Coluna de texto (:32) ─── */}
            <div className="flex flex-1 flex-col gap-[2px]">
                <span className="text-[14px] font-semibold text-pub-danger-txt">{dados.titulo}</span>
                <span className="text-[12.5px] text-pub-txt-2">{dados.descricao}</span>
            </div>

            {/* ─── CTA (:36) ───
                ⚠️ É um `<a href>`, não um `<button>`, e SÓ EXISTE quando o
                servidor manda `link_reconexao` (EXMP-03: nada de botão sem
                destino). Sem o link, o banner continua explicando o problema —
                o que já é o essencial do aviso.
                ⚠️ `flex items-center` é do `<a>`: o `<button>` do protótipo
                centraliza o texto por padrão do UA, um `<a>` de 34px não (D-9
                do 179-06). `cursor-pointer` é implícito em `a[href]`. */}
            {dados.href !== null && (
                <a
                    href={dados.href}
                    className="flex h-[34px] items-center whitespace-nowrap rounded-[8px] bg-pub-danger-txt px-[14px] text-[13px] font-semibold text-pub-page"
                >
                    {dados.cta}
                </a>
            )}
        </div>
    );
}
