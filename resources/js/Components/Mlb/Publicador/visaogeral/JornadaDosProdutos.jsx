import { useState } from 'react';
import { ArrowRight, ChevronDown, ChevronRight, Clock, Plus, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { textoSeguro } from '../BarraDaConta';
import MapaDeCobertura from '../identidade/MapaDeCobertura';
import TileDoProduto from '../identidade/TileDoProduto';
import {
    FECHAR,
    NOTA_PERIODO,
    PROXIMO_PASSO,
    PUBLICADOS,
    SUBTITULO,
    TITLE_NOTA_PERIODO,
    TITULO,
    VAZIO_CTA_1,
    VAZIO_CTA_2,
    VAZIO_TEXTO,
    VAZIO_TITULO,
    VER_PRODUTOS,
    cabecalhoDoPainel,
    cartoesDosEstagios,
    faixasDaBarra,
    linhasDoPainel,
    normalizarJornada,
    percentualSeguro,
    segmentosDoAnel,
    textoDoAnel,
} from './jornada';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 179, plano 06 — a "Jornada dos produtos" (VG-03..VG-08, VG-19).
//
// Protótipo: `new_layout_publicador/Visao Geral.dc.html` — linhas MEDIDAS no
// arquivo (as do `<action>` do plano estão deslocadas ~7 a partir do anel):
//   · card                  → :40   (cabeçalho :41, coluna :42, H2 :43, sub :44)
//   · grupo da direita      → :46   (nota de período :47, relógio :48)
//   · "Ver produtos"        → :49   (seta :50)
//   · empty state           → :55-:73
//   · grid anel + estágios  → :77   (caixa :78, svg :79, trilho :80, segmento :82)
//   · centro do anel        → :85   (% :86, "publicados" :87, "N de M" :88)
//   · coluna direita        → :91   (grid dos estágios :92)
//   · botão do estágio      → :94   (overline :96, badge :97, número :99,
//                                    label/sub :100, rodapé :101, avatar :104,
//                                    "+N" :106, CTA :108, chevron :109, seta :112)
//   · barra segmentada      → :119  (faixa :121)
//   · painel do estágio     → :128  (cabeçalho :129, linha :137, célula :148, CTA :155)
//   · a aritmética          → :465-:490
//
// ⚠️ CONVERSÃO DE `viewBox`: o protótipo desenha em `viewBox="0 0 16 16"` e o
// Lucide em `0 0 24 24`, então o `stroke-width` é multiplicado por **1,5**:
//   1.5 → 2.25 · 1.8 → 2.7 · 2 → 3 · 2.2 → 3.3
// (mesma conta documentada em `header/PublicadorHeader.jsx:52`.)
//
// ⚠️ TODA classe do Tailwind deste bloco mora NESTE arquivo, e não em
// `jornada.js`: o `content` do `tailwind.config.js` varre
// `./resources/js/**/*.jsx` e **não** `*.js`. Classe escrita num `.js` não é
// varrida, não gera CSS e portanto não existe para a tela.
//
// ⚠️ REND-04 (`feedback_rollup_map_scope_bug.md`): este arquivo tem CINCO
// `.map()` (segmentos do anel, estágios, tiles, faixas, linhas). Nenhuma flag
// derivada nasce no corpo do componente para ser lida dentro de um callback —
// cada uma é calculada DENTRO do callback, a partir dos mapas de módulo acima,
// das funções de `jornada.js` e dos parâmetros do próprio callback. O Rollup já
// eliminou uma declaração de escopo de componente neste projeto e deixou o uso,
// com `ReferenceError` em produção e os testes passando.
// ═══════════════════════════════════════════════════════════════════════════

/** Cor do segmento do anel por estágio (`:465-:468` + `:483`). */
const COR_DO_ANEL = {
    none: 'stroke-pub-line-off',
    draft: 'stroke-pub-txt-4',
    ready: 'stroke-pub-fase1-2',
    done: 'stroke-pub-accent',
};

/** Cor da amostra de 10×10 do overline do cartão (`:96`). */
const COR_DA_AMOSTRA = {
    none: 'bg-pub-line-off',
    draft: 'bg-pub-txt-4',
    ready: 'bg-pub-fase1-2',
    done: 'bg-pub-accent',
};

/**
 * Cor da faixa da barra de 8px (`:479`).
 * ⚠️ O `none` é a HACHURA (VG-07), não uma cor sólida — e ela é um token
 * (`bg-pub-hachura`) porque escrita na tela seria um `bg-[repeating-…]` com dois
 * hex literais dentro, o que o gate de vocabulário reprova.
 */
const COR_DA_BARRA = {
    none: 'bg-pub-hachura',
    draft: 'bg-pub-txt-4',
    ready: 'bg-pub-fase1-2',
    done: 'bg-pub-accent',
};

/**
 * Os três tons do CTA da linha do painel (`:490`):
 *   `ready` → amarelo rebaixado (tinta + borda translúcidas)
 *   `none`  → amarelo sólido, texto escuro
 *   `draft`/`done` → secundário
 */
const TOM_DO_CTA_DA_LINHA = {
    none: 'border-pub-accent bg-pub-accent text-pub-page',
    draft: 'border-pub-line-strong bg-pub-surface text-pub-fase1',
    ready: 'border-pub-accent/[0.4] bg-pub-accent/[0.14] text-pub-accent',
    done: 'border-pub-line-strong bg-pub-surface text-pub-fase1',
};

/**
 * A Jornada dos produtos: o anel de 176px, os quatro estágios clicáveis, a barra
 * segmentada e o painel de até 6 produtos do estágio aberto.
 *
 * @param {object} props
 * @param {unknown} props.jornada       o payload de `JornadaDosProdutosService::jornada()`
 * @param {string|null} [props.urlProdutos]  destino da tela de Produtos desta conta
 */
export default function JornadaDosProdutos({ jornada, urlProdutos = null }) {
    const dados = normalizarJornada(jornada);
    const produtos = typeof urlProdutos === 'string' && urlProdutos.trim() !== '' ? urlProdutos : null;

    // ⚠️ VG-08: "Prontos para Fase 2" nasce ABERTO. O protótipo (`:390`, `:472`)
    // começa com `stage: 'ready'` FIXO e só o derruba quando o estágio está
    // vazio — ele não consulta o `hot`. Painel vazio aberto seria pior que
    // fechado, e painel fechado quando há 13 produtos prontos contraria VG-08.
    const [estagioAberto, setEstagioAberto] = useState(
        dados.contagens.ready > 0 ? 'ready' : null,
    );

    // `sel = S.stage && cnt[S.stage] ? S.stage : null` (`:472`): o estágio só
    // conta como aberto se tiver produto. É o que faz clicar num estágio de
    // total 0 NÃO abrir painel nenhum.
    const selecionado = estagioAberto && dados.contagens[estagioAberto] > 0 ? estagioAberto : null;
    const cabecalho = cabecalhoDoPainel(dados, selecionado);

    return (
        <section className="rounded-pub-card border border-pub-line bg-pub-surface px-[24px] pb-[24px] pt-[22px]">
            {/* ─── Cabeçalho do card (:41-:51) ─── */}
            <div className="mb-[22px] flex items-start justify-between gap-[20px]">
                <div className="flex flex-col gap-[4px]">
                    <h2 className="m-0 text-[17px] font-semibold tracking-[-0.01em]">{TITULO}</h2>
                    <span className="text-[13px] text-pub-txt-3">{SUBTITULO}</span>
                </div>
                {/* ⚠️ Este agrupador (`:46`, `gap:12px`) NÃO está na tabela FID-01 do
                    plano; está no protótipo. Sem ele a nota e o botão herdariam o
                    `gap:20px` do cabeçalho. */}
                <div className="flex items-center gap-[12px]">
                    {/* VG-03: a tela DIZ que a Jornada é estado atual (:47) */}
                    <span
                        title={TITLE_NOTA_PERIODO}
                        className="flex items-center gap-[6px] text-[12px] text-pub-txt-4"
                    >
                        {/* 13×13, stroke 1.5 × 1,5 = 2.25 (:48) */}
                        <Clock size={13} strokeWidth={2.25} />
                        {NOTA_PERIODO}
                    </span>
                    {produtos !== null && (
                        <a
                            href={produtos}
                            className="flex h-[30px] items-center gap-[6px] whitespace-nowrap rounded-[8px] border border-pub-line-strong px-[12px] text-[12.5px] font-medium"
                        >
                            {VER_PRODUTOS}
                            {/* 11×11, stroke 1.8 × 1,5 = 2.7 (:50) */}
                            <ArrowRight size={11} strokeWidth={2.7} />
                        </a>
                    )}
                </div>
            </div>

            {/* ─── VG-19: conta sem produto (:55-:73). Exclusivo do bloco de baixo. ─── */}
            {!dados.tem_produtos && (
                <div className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-[28px] px-[8px] pb-[8px] pt-[20px]">
                    <div aria-hidden="true" className="relative h-[110px] w-[132px]">
                        <div className="absolute left-[10px] top-[18px] h-[86px] w-[78px] rounded-pub-panel border-[1.5px] border-dashed border-pub-line-off bg-pub-sunken" />
                        <div className="absolute left-[34px] top-[6px] flex h-[86px] w-[78px] flex-col gap-[6px] rounded-pub-panel border-[1.5px] border-pub-line-strong bg-pub-hover-2 p-[12px]">
                            <span className="h-[34px] w-full rounded-[6px] bg-pub-line-subtle" />
                            <span className="h-[6px] w-[70%] rounded-[3px] bg-pub-line-strong" />
                            <span className="h-[6px] w-[45%] rounded-[3px] bg-pub-line" />
                        </div>
                        <div className="absolute bottom-[4px] right-[4px] grid h-[30px] w-[30px] place-items-center rounded-full bg-pub-accent">
                            {/* 14×14, stroke 2 × 1,5 = 3 (:62) */}
                            <Plus size={14} strokeWidth={3} className="text-pub-page" />
                        </div>
                    </div>
                    <div className="flex max-w-[560px] flex-col gap-[8px]">
                        <span className="text-[16px] font-semibold">{VAZIO_TITULO}</span>
                        <span className="text-[13.5px] leading-[1.5] text-pub-txt-2 [text-wrap:pretty]">
                            {VAZIO_TEXTO}
                        </span>
                        {/* ⚠️ EXMP-01: os dois CTA só existem com destino. O protótipo
                            desenha `<button>`, mas cadastrar produto e enviar o link do
                            Portal vivem na tela de Produtos — e abrir o
                            `ModalNovoProduto` daqui exigiria o contexto de programa que
                            este bloco não recebe. Sem `urlProdutos` seriam dois botões
                            que não fazem nada, e botão mudo é pior que botão ausente. */}
                        {produtos !== null && (
                            <div className="mt-[6px] flex gap-[8px]">
                                <a
                                    href={produtos}
                                    className="flex h-[34px] items-center whitespace-nowrap rounded-[8px] bg-pub-accent px-[14px] text-[13px] font-semibold text-pub-page"
                                >
                                    {VAZIO_CTA_1}
                                </a>
                                <a
                                    href={produtos}
                                    className="flex h-[34px] items-center whitespace-nowrap rounded-[8px] border border-pub-line-strong bg-pub-surface px-[14px] text-[13px] font-medium text-pub-txt"
                                >
                                    {VAZIO_CTA_2}
                                </a>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* ─── O anel, os estágios e a barra (:77-:125) ─── */}
            {dados.tem_produtos && (
                <div className="grid grid-cols-[184px_minmax(0,1fr)] items-center gap-[32px]">
                    <div className="relative h-[176px] w-[176px]">
                        <svg width="176" height="176" viewBox="0 0 176 176">
                            {/* o trilho (:80) */}
                            <circle
                                cx="88"
                                cy="88"
                                r="74"
                                className="fill-none stroke-pub-line-subtle-2 stroke-[14]"
                            />
                            {segmentosDoAnel(dados.contagens, dados.total, selecionado).map((segmento) => (
                                <circle
                                    key={segmento.chave}
                                    cx="88"
                                    cy="88"
                                    r="74"
                                    transform="rotate(-90 88 88)"
                                    style={{
                                        strokeDasharray: segmento.strokeDasharray,
                                        strokeDashoffset: segmento.strokeDashoffset,
                                        opacity: segmento.opacity,
                                    }}
                                    className={cn(
                                        'fill-none stroke-[14] transition-opacity duration-200',
                                        COR_DO_ANEL[segmento.chave],
                                    )}
                                />
                            ))}
                        </svg>
                        <div className="absolute inset-0 flex flex-col items-center justify-center gap-[2px]">
                            <span className="text-[34px] font-semibold tracking-[-0.03em] tabular-nums">
                                {percentualSeguro(dados.pct)}
                            </span>
                            <span className="text-[12px] text-pub-txt-3">{PUBLICADOS}</span>
                            <span className="mt-[2px] text-[12px] tabular-nums text-pub-fase1">
                                {textoDoAnel(dados.publicados, dados.total)}
                            </span>
                        </div>
                    </div>

                    <div className="flex min-w-0 flex-col gap-[14px]">
                        {/* `repeat(4,minmax(0,1fr))` (:92) — valor computado idêntico */}
                        <div className="grid grid-cols-4 gap-[12px]">
                            {cartoesDosEstagios(dados, selecionado).map((cartao) => (
                                <button
                                    key={cartao.chave}
                                    type="button"
                                    onClick={() =>
                                        setEstagioAberto((atual) =>
                                            atual === cartao.chave ? null : cartao.chave,
                                        )
                                    }
                                    className={cn(
                                        'relative flex cursor-pointer flex-col items-stretch gap-[10px] whitespace-nowrap rounded-pub-panel border px-[16px] py-[14px] text-left text-pub-txt transition-[background-color,border-color] duration-150',
                                        cartao.aberto
                                            ? 'border-pub-line-off bg-pub-raised-2'
                                            : 'border-pub-line bg-pub-surface',
                                        // ⚠️ o `hover:` vem por ÚLTIMO na lista do `cn()`:
                                        // é a posição que o `tailwind-merge` respeita
                                        // quando duas regras disputam a mesma propriedade.
                                        'hover:border-pub-line-off',
                                    )}
                                >
                                    <span className="flex items-center justify-between">
                                        <span className="flex items-center gap-[8px] text-[11px] font-semibold tracking-[0.08em] text-pub-txt-4">
                                            <span
                                                className={cn(
                                                    'h-[10px] w-[10px] rounded-[3px]',
                                                    COR_DA_AMOSTRA[cartao.chave],
                                                    // só o "Sem publicação" leva borda
                                                    // (`swBorder`, :475)
                                                    cartao.chave === 'none' && 'border border-pub-txt-off',
                                                )}
                                            />
                                            {cartao.passo}
                                        </span>
                                        {cartao.ehProximoPasso && (
                                            <span className="rounded-pub-badge bg-pub-accent px-[6px] py-[2px] text-[10.5px] font-bold tracking-[0.06em] text-pub-page">
                                                {PROXIMO_PASSO}
                                            </span>
                                        )}
                                    </span>

                                    <span className="flex items-baseline gap-[8px]">
                                        <span className="text-[32px] font-semibold leading-none tracking-[-0.03em] tabular-nums">
                                            {cartao.total}
                                        </span>
                                        <span className="text-[12px] text-pub-txt-4">{cartao.share}</span>
                                    </span>

                                    <span className="flex flex-col gap-[2px]">
                                        <span className="text-[13.5px] font-semibold">{cartao.rotulo}</span>
                                        <span className="text-[12px] text-pub-txt-3">{cartao.sub}</span>
                                    </span>

                                    <span className="flex items-center justify-between gap-[8px] border-t border-pub-line-subtle pt-[10px]">
                                        <span className="flex">
                                            {cartao.tiles.map((item, posicao) => (
                                                <TileDoProduto
                                                    key={item.produto_id ?? `tile-${posicao}`}
                                                    id={item.produto_id}
                                                    nome={item.nome}
                                                    foto={item.capa}
                                                    tamanho={24}
                                                    raio={7}
                                                    letra="text-[8.5px]"
                                                    className={cn(
                                                        '-mr-[6px] border-2 font-bold',
                                                        // a borda do avatar é da cor do FUNDO
                                                        // do cartão (`bgSolid`, :104)
                                                        cartao.aberto ? 'border-pub-raised-2' : 'border-pub-surface',
                                                    )}
                                                />
                                            ))}
                                            {/* ⚠️ o "+N" sai do TOTAL do estágio, nunca de
                                                `itens.length`: `itens` chega cortado em 6 */}
                                            {cartao.restante > 0 && (
                                                <span className="grid h-[24px] place-items-center pl-[10px] pr-[6px] text-[11px] text-pub-txt-3">
                                                    +{cartao.restante}
                                                </span>
                                            )}
                                        </span>
                                        <span
                                            className={cn(
                                                'flex items-center gap-[4px] whitespace-nowrap text-[12px] font-semibold',
                                                cartao.ehProximoPasso ? 'text-pub-accent' : 'text-pub-txt-2',
                                            )}
                                        >
                                            {cartao.cta}
                                            {/* 10×10, stroke 2 × 1,5 = 3 (:109) */}
                                            <ChevronDown
                                                size={10}
                                                strokeWidth={3}
                                                className={cn(
                                                    'transition-transform duration-200',
                                                    cartao.aberto && 'rotate-180',
                                                )}
                                            />
                                        </span>
                                    </span>

                                    {/* a seta entre estágios só nos TRÊS primeiros (:111) */}
                                    {cartao.temSeta && (
                                        <span className="absolute right-[-13px] top-1/2 z-[2] -mt-[7px] grid h-[14px] w-[14px] place-items-center text-pub-txt-off">
                                            {/* 10×10, stroke 2.2 × 1,5 = 3.3 (:113) */}
                                            <ChevronRight size={10} strokeWidth={3.3} />
                                        </span>
                                    )}
                                </button>
                            ))}
                        </div>

                        {/* VG-07 — a barra de 8px (:119-:123) */}
                        <div className="flex h-[8px] gap-[3px]">
                            {faixasDaBarra(dados.contagens, selecionado).map((faixa) => (
                                <span
                                    key={faixa.chave}
                                    title={faixa.titulo}
                                    style={{ flexGrow: faixa.flexGrow, opacity: faixa.opacity }}
                                    className={cn(
                                        'basis-0 rounded-[3px] transition-opacity duration-200',
                                        COR_DA_BARRA[faixa.chave],
                                    )}
                                />
                            ))}
                        </div>
                    </div>
                </div>
            )}

            {/* ─── VG-08 — o painel do estágio aberto (:127-:160) ─── */}
            {dados.tem_produtos && cabecalho !== null && (
                <div className="mt-[20px] overflow-hidden rounded-pub-panel border border-pub-line-subtle bg-pub-sunken">
                    <div className="flex items-center gap-[12px] border-b border-pub-line-subtle px-[16px] py-[12px]">
                        <span className="text-[13.5px] font-semibold">{cabecalho.titulo}</span>
                        <span className="text-[12.5px] text-pub-txt-3">{cabecalho.sub}</span>
                        {cabecalho.ver_todos_url !== null && (
                            <a
                                href={cabecalho.ver_todos_url}
                                className="ml-auto whitespace-nowrap text-[12.5px] font-medium text-pub-txt-2"
                            >
                                {cabecalho.link}
                            </a>
                        )}
                        <button
                            type="button"
                            title={FECHAR}
                            aria-label={FECHAR}
                            onClick={() => setEstagioAberto(null)}
                            className={cn(
                                'grid h-[26px] w-[26px] cursor-pointer place-items-center rounded-pub-chip border-none bg-transparent text-pub-txt-3',
                                // ⚠️ sem o link de "Ver todos" o `ml-auto` passa para
                                // cá, senão o X cola no subtítulo em vez de ir para a
                                // direita (D23: conta sem chave não tem destino).
                                cabecalho.ver_todos_url === null && 'ml-auto',
                                'hover:bg-pub-line-subtle',
                            )}
                        >
                            {/* 12×12, stroke 1.8 × 1,5 = 2.7 (:134) */}
                            <X size={12} strokeWidth={2.7} />
                        </button>
                    </div>

                    {linhasDoPainel(dados, selecionado).map((linha) => (
                        <div
                            key={linha.produto_id ?? `linha-${linha.posicao}`}
                            className={cn(
                                'grid h-[52px] grid-cols-[minmax(0,1.6fr)_130px_150px_minmax(0,1.2fr)_150px] items-center gap-[16px] px-[16px]',
                                // `border: i ? '1px solid #1A1A1A' : 'none'` (:489)
                                !linha.primeira && 'border-t border-pub-hover-3',
                                'hover:bg-pub-hover-0',
                            )}
                        >
                            <span className="flex min-w-0 items-center gap-[10px]">
                                <TileDoProduto
                                    id={linha.produto_id}
                                    nome={linha.nome}
                                    foto={linha.capa}
                                    tamanho={30}
                                    raio={7}
                                    letra="text-[10px]"
                                />
                                <span className="truncate text-[13.5px] font-medium">
                                    {textoSeguro(linha.nome)}
                                </span>
                            </span>
                            <span className="font-pub-mono text-[11.5px] text-pub-txt-3">
                                {textoSeguro(linha.sku)}
                            </span>
                            <span className="flex items-center gap-[5px]">
                                {/* ⚠️ DIVERGÊNCIA DECLARADA: o protótipo (:145-:148) separa
                                    `F1 [C][P]` de `F2 [C][P]` com rótulo próprio e 6px de
                                    respiro, e `MapaDeCobertura` desenha as QUATRO células
                                    seguidas. Partir o componente em dois é impossível (as
                                    4 células são constantes nele) e editá-lo está vedado
                                    neste plano (IDENT-08 + gate do diretório `identidade`).
                                    A fase fica no nome acessível de cada célula
                                    ("Fase 1 · Clássico — publicada"), que ele já emite. */}
                                <MapaDeCobertura cobertura={linha.cobertura} tamanho={18} />
                            </span>
                            <span className="truncate text-[12.5px] text-pub-txt-2">
                                {textoSeguro(linha.nota)}
                            </span>
                            <span className="flex justify-end">
                                {/* ⚠️ EXMP-01: item sem `url` NÃO vira clique mudo. O
                                    179-01 já descarta item sem destino, mas o render
                                    não confia nisso. */}
                                {typeof linha.url === 'string' && linha.url !== '' && (
                                    <a
                                        href={linha.url}
                                        className={cn(
                                            'flex h-[30px] items-center whitespace-nowrap rounded-pub-control-sm border px-[12px] text-[12.5px] font-semibold',
                                            TOM_DO_CTA_DA_LINHA[linha.chave],
                                        )}
                                    >
                                        {linha.rotuloCta}
                                    </a>
                                )}
                            </span>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}
