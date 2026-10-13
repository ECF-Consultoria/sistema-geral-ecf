import { cn } from '@/lib/utils';
import BadgeNova from '@/Components/Mlb/Publicador/identidade/BadgeNova';
import GlyphDaFase, { chaveDaFase } from '@/Components/Mlb/Publicador/identidade/GlyphDaFase';
import SeloDeStatusDaPublicacao from '@/Components/Mlb/Publicador/identidade/SeloDeStatusDaPublicacao';
import TileDoProduto from '@/Components/Mlb/Publicador/identidade/TileDoProduto';
import {
    SUB_RECENTES,
    TITULO_RECENTES,
    VAZIO_RECENTES_TEXTO,
    VAZIO_RECENTES_TITULO,
    VER_PUBLICACOES,
    recentesParaTela,
} from './recentes';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 179, plano 08 — "Publicações recentes" (VG-16, VG-18, EXMP-03).
//
// Protótipo: `new_layout_publicador/Visao Geral.dc.html`:313-:338 (os `recents`
// da aritmética em `:572`, o `ST` em `:562`). ⚠️ O `<action>` do plano cita
// `:305-:327`.
//
// ⚠️ TRÊS divergências declaradas, todas com restrição concreta:
//
// 1. O TIPO NÃO USA `SeloDoTipo`. O protótipo (`:326`) desenha o tipo como um
//    `<span>` de PESO 600/400 na cor `#D4D4D8`, no corpo de 11,5px herdado da
//    meta, SEM losango e SEM a tag "Catálogo". O `SeloDoTipo` é 13px em
//    `text-pub-txt`, com losango amarelo de 7px e layout em COLUNA
//    (`inline-flex flex-col`) — três divergências de uma vez, e a coluna
//    quebraria a linha única de `gap:6px` com os `·` entre as partes. O canal
//    de informação é o MESMO que o componente nomeia como essencial ("o peso É
//    a informação aqui, 400 contra 600"): aqui ele é reproduzido, o enfeite
//    não. Nenhuma geometria de glyph é redesenhada neste arquivo.
//
// 2. O GLYPH E O STATUS GANHARAM PROP ADITIVA (não foram redesenhados). O
//    protótipo mede o glyph em 14×13 com retângulos 8×10 (`:324`/`:325`) e o
//    status em 12,5px (`:328`), contra 18×18 / 10×13 e 13px da escada medida na
//    tabela de Publicações. A saída é a da Fase 178 com o `TileDoProduto`:
//    `GlyphDaFase tamanho="recentes"` e `SeloDeStatusDaPublicacao corpo=…`,
//    com o default = comportamento de hoje byte a byte.
//
// 3. A FORMA DO STATUS NÃO VIRA DOT. O protótipo pinta um dot redondo de 7px
//    em três cores (`:328`, `ST` em `:562`); o `SeloDeStatusDaPublicacao`
//    desenha forma + cor + texto, porque IDENT-04 ("nunca depender só de cor")
//    é requisito do design system aprovado na Fase 177 e vale para as 4 telas
//    que o usam. O TEXTO do status — que é a informação — é idêntico nos dois.
//
// ⚠️ `haQuanto` vem de `../tempo` (via `recentes.js`): JÁ EXISTE e não se
// reescreve (IDENT-08). Por isso o tempo sai relativo ("há 12 min", "há 2 d") e
// não no formato absoluto do mock ("Ontem, 17:48", "8 out").
// ═══════════════════════════════════════════════════════════════════════════

/**
 * As 5 publicações que entraram no ar mais recentemente.
 *
 * @param {object} props
 * @param {unknown} props.recentes             `CockpitDaContaService::recentes()`
 * @param {string|null} [props.urlPublicacoes] destino da lista completa; sem
 *   ela a linha e o "Ver todas" deixam de ser `<a>` (EXMP-03: nada de clique
 *   mudo). ⚠️ NÃO existe no payload das 23 props — o 179-09 tem de montá-la.
 * @param {number} [props.agora]               injetável para o teste fixar a data
 */
export default function PublicacoesRecentes({ recentes, urlPublicacoes = null, agora = undefined }) {
    const dados = recentesParaTela(recentes, urlPublicacoes, agora);

    return (
        <section className="overflow-hidden rounded-pub-card border border-pub-line bg-pub-surface">
            {/* ─── Cabeçalho (:314) ─── */}
            <div className="flex items-center justify-between px-[20px] pb-[12px] pt-[18px]">
                {/* ⚠️ Esta coluna (`:315`) não está na tabela FID-01 do plano, e
                    o `gap` dela é 3px — não 4px como nos outros cabeçalhos. */}
                <div className="flex flex-col gap-[3px]">
                    {/* ⚠️ O protótipo escreve este título num `<span>`, mas usa
                        `<h2 style="margin:0">` nos outros quatro cards da mesma
                        página (`:43`, `:168`, `:237`, `:260`). Com `m-0` e o
                        corpo declarado o pixel é idêntico, e a `<section>`
                        ganha nome acessível. */}
                    <h2 className="m-0 text-[15px] font-semibold">{TITULO_RECENTES}</h2>
                    <span className="text-[12.5px] text-pub-txt-3">{SUB_RECENTES}</span>
                </div>

                {dados.href !== null && (
                    <a href={dados.href} className="whitespace-nowrap text-[12.5px] font-medium text-pub-txt-2">
                        {VER_PUBLICACOES}
                    </a>
                )}
            </div>

            {/* ─── As linhas (:319) ───
                ⚠️ Toda flag de render (`nova`, `quando`, `autor`) nasce em
                `recentesParaTela`, função PURA: ler `dados.href` ou calcular
                `item.nova === true` dentro deste `.map()` seria variável de
                escopo do componente lida no callback, que é a forma que o
                Rollup elimina neste projeto (REND-04). */}
            {dados.itens.map((item, i) => {
                // ⚠️ As duas flags saem do PRÓPRIO parâmetro do callback
                // (`item.href`, `item.fase`) e de um import de módulo
                // (`chaveDaFase`) — nunca de variável do corpo do componente.
                const Linha = item.href !== null ? 'a' : 'div';
                const chaveFase = chaveDaFase(item.fase);
                const faseConhecida = chaveFase === 'f1' || chaveFase === 'f2';

                return (
                    <Linha
                        key={`${item.mlb}-${i}`}
                        href={item.href !== null ? item.href : undefined}
                        className={cn(
                            'grid grid-cols-[40px_minmax(0,1fr)_112px_104px] items-center gap-[14px] whitespace-nowrap border-t border-pub-line-subtle-2 px-[20px] py-[11px]',
                            // `#14120A` já tem nome na camada de tokens: `pub-row-new`.
                            item.nova ? 'bg-pub-row-new' : 'bg-transparent',
                            'hover:bg-pub-hover-2',
                        )}
                    >
                        {/* ─── Tile (:320) — a escada já devolve raio 9 e
                            `text-[11.5px]` para lado 40; nenhuma prop extra ─── */}
                        <TileDoProduto tamanho={40} id={item.produto_id} nome={item.produto_nome} foto={item.capa} />

                        {/* ⚠️ Esta coluna (`:321`) não está na tabela do plano. */}
                        <span className="flex min-w-0 flex-col gap-[4px]">
                            {/* ⚠️ Nem esta linha de título (`:322`). */}
                            <span className="flex min-w-0 items-center gap-[8px]">
                                <span className="truncate text-[13.5px] font-medium">{item.titulo}</span>
                                {item.nova && <BadgeNova />}
                            </span>

                            {/* ─── Meta: glyph · fase · tipo · MLB (:323-:326) ───
                                ⚠️ Os `·` são nós de texto nus entre os spans,
                                sem espaço em volta — como no protótipo.
                                ⚠️ O glyph só nasce em Fase 1 ou Fase 2: o
                                protótipo desenha `isP1`/`isP2` e NADA quando a
                                fase não é nenhuma das duas (`:572`). Um
                                retângulo tracejado ali afirmaria "Legado". */}
                            <span className="flex items-center gap-[6px] overflow-hidden whitespace-nowrap text-[11.5px] text-pub-txt-3">
                                {faseConhecida && <GlyphDaFase fase={item.fase} tamanho="recentes" />}
                                <span className="text-pub-fase1">{item.rotulo_fase}</span>
                                {'·'}
                                <span className={cn('text-pub-fase1', item.premium ? 'font-semibold' : 'font-normal')}>
                                    {item.tipo}
                                </span>
                                {'·'}
                                <span className="font-pub-mono text-[11px]">{item.mlb}</span>
                            </span>
                        </span>

                        {/* ─── Status (:328) ─── */}
                        <SeloDeStatusDaPublicacao status={item.situacao} corpo="text-[12.5px]" />

                        {/* ─── Quem e quando (:329) ───
                            ⚠️ `quando` nulo (ISO ausente ou impossível) ⇒ a
                            linha sai SEM a hora, nunca com "Invalid Date". */}
                        <span className="flex flex-col items-end gap-[2px]">
                            {item.quando !== null && (
                                <span className="text-[12.5px] text-pub-fase1">{item.quando}</span>
                            )}
                            {item.autor !== null && (
                                <span className="text-[11.5px] text-pub-txt-4">{item.autor}</span>
                            )}
                        </span>
                    </Linha>
                );
            })}

            {/* ─── Vazio (:333-:336) ───
                ⚠️ `disponivel === false` e `itens: []` são A MESMA TELA (o texto
                do protótipo), e o `title` é que explica a diferença: sem empresa
                ligada não há o que mostrar; com empresa e sem item, o período
                está vazio. VG-18 em dois níveis. */}
            {dados.vazio && (
                <div
                    title={dados.motivo_vazio}
                    className="flex flex-col items-center gap-[6px] border-t border-pub-line-subtle-2 px-[20px] py-[40px] text-center"
                >
                    <span className="text-[14px] font-semibold">{VAZIO_RECENTES_TITULO}</span>
                    <span className="text-[12.5px] text-pub-txt-3">{VAZIO_RECENTES_TEXTO}</span>
                </div>
            )}
        </section>
    );
}
