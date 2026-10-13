import { isValidElement } from 'react';
import { cn } from '@/lib/utils';
import { CLASSE_ATIVA, CLASSE_DO_TRILHO, CLASSE_INATIVA } from './AbasDeStatus';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 180, plano 06 — a linha de controles da tela de Publicações: as abas de
// status à esquerda, o rótulo da ordenação e o toggle de visão à direita
// (LIST-06/LIST-23).
//
// Protótipo: `new_layout_publicador/Publicacoes v2.dc.html`. Linhas MEDIDAS com
// `grep -n` neste arquivo:
//   · a linha                → :82 (`align-items:center;justify-content:space-between;gap:16px`)
//   · bloco da direita       → :90 (`display:flex;align-items:center;gap:10px`)
//                                   ⚠️ a tabela FID-01 do plano OMITIU este bloco
//   · rótulo da ordenação    → :93 (`font-size:12.5px;color:#8A8A90`)
//   · trilho do toggle       → :92 (idêntico ao das abas, `:83`)
//   · opção de visão         → :96 (`height:30px;padding:0 12px;border:none;`
//                                   `border-radius:7px;font-size:13px;font-weight:500;`
//                                   `cursor:pointer`) — ⚠️ SEM `display:flex`
//   · ativa / inativa        → :536 (`#262626` + `inset 0 0 0 1px #333333` + `#F4F4F5`
//                                    × `transparent` + `none` + `#A1A1AA`)
//   · os 2 rótulos e a ordem → :536 (`[['group','Por produto'],['list','Lista']]`)
//
// ⚠️ A opção de visão NÃO leva `display:flex;align-items:center`, ao contrário
// do que a tabela FID-01 diz ("igual à aba, sem o gap"): o protótipo declara
// `display:flex` no botão de ABA (`:85`) e NÃO no de visão (`:96`) — ali o
// `<button>` centraliza o próprio texto. O protótipo ganha. Pelo mesmo motivo
// a opção de visão também não tem `gap`.
//
// ⚠️ `font-weight` é FIXO em 500 nas duas opções (`:96`), ativa ou não. A
// instrução de briefing dizia "`font-weight:600` quando ativa" — isso é dos
// CHIPS de triagem (`seg()`, `:535`, com `weight: 600 : 400` e fundo
// `rgba(250,204,21,0.16)`), que são do plano 180-07, não deste. Medido, não
// presumido.
//
// ⚠️ A COLUNA EXTERNA de `gap:12px` (`:82`, entre esta linha e a linha de
// chips) NÃO mora aqui: ela envolve um bloco que este plano não desenha (os
// chips são do 180-07) e compor a página é do 180-10. A medida completa fica
// registrada na 180-06-SUMMARY para ele, como o 180-05 fez com o `<main>`.
//
// ⚠️ `role="group"` + `aria-pressed`, NUNCA um segundo `role="radiogroup"`:
// `publicador-paginas-anuncios-render.test.js:354` assere
// `(html.match(/role="radiogroup"/g) ?? []).length === 1` na página de Meus
// Anúncios, contando o radiogroup das SUB-ABAS. Um segundo radiogroup
// reprovaria aquele gate, que não é desta fase. A decisão está escrita na
// 180-06-SUMMARY para o 180-10 não descobri-la no meio da composição.
//
// ⚠️ O RÓTULO DA ORDENAÇÃO VEM DE FORA, e isto é uma DIVERGÊNCIA REAL entre o
// protótipo e o sistema, resolvida a favor da VERDADE:
//   · o protótipo ordena por data (`:529`: `sort((a,b) => b.d.localeCompare(a.d))`)
//     e por isso escreve "Mais recentes primeiro" (`:93`);
//   · o sistema ordena por GRAVIDADE (`MlbAnuncioController::meus()`:
//     `orderByDesc('severidade')` → `nota_ecf IS NULL ASC` → `nota_ecf` →
//     `ml_item_id`), que é o D-12 e que o critério 13 manda preservar. A
//     própria tela de hoje chama isso de "ordenação fixa por gravidade"
//     (`MeusAnuncios.jsx:21` e `:526`).
// ➡️ O default é "Mais graves primeiro", que é o que o servidor faz. Rótulo que
// mente é pior que rótulo diferente do protótipo. Quando o servidor passar a
// mandar o rótulo, ele ganha — por isso é prop, não literal cravado no JSX.
//
// ⚠️ REND-04: o `.map()` das 2 opções chama um helper declarado DENTRO do
// componente e todas as flags nascem lá (o Rollup já eliminou variável de
// escopo do componente lida só dentro de um `.map()` neste projeto).
//
// ⚠️ D-11: nenhum `router.*` aqui. O componente devolve a chave da visão ao
// `onTrocarVisao` e nada mais.
//
// ⚠️ TODA classe do Tailwind mora NESTE `.jsx` (o `content` não varre `*.js`).
// ═══════════════════════════════════════════════════════════════════════════

/** A visão "Por produto" — o DEFAULT da tela (`:536`, primeira do par). */
export const VISAO_POR_PRODUTO = 'grupo';

/** A visão "Lista". */
export const VISAO_LISTA = 'lista';

/**
 * As duas opções do toggle, na ordem do protótipo (`:536`).
 *
 * ⚠️ As chaves são as do SERVIDOR (`filtros.visao`), não as do protótipo
 * (`group`/`list`): o controller já sanea `visao` como `grupo|lista`.
 */
export const OPCOES_DE_VISAO = [
    { chave: VISAO_POR_PRODUTO, rotulo: 'Por produto' },
    { chave: VISAO_LISTA, rotulo: 'Lista' },
];

/** O nome acessível do toggle — "Visão" sozinho não diria de quê. */
export const ARIA_DO_TOGGLE = 'Como a lista é agrupada';

/**
 * O rótulo da ordenação quando o servidor não manda nenhum.
 *
 * ⚠️ NÃO é o "Mais recentes primeiro" do protótipo: a ordenação real é por
 * gravidade (ver o cabeçalho deste arquivo). Rótulo que mente é pior que
 * rótulo diferente do protótipo.
 */
export const ORDENACAO_PADRAO = 'Mais graves primeiro';

/** String útil ou `null` — nunca `String(x)`, que LANÇA com `{toString:null}`. */
function soTexto(valor) {
    if (typeof valor !== 'string') return null;
    const limpo = valor.trim();
    return limpo === '' ? null : limpo;
}

/**
 * A linha `abas | ordenação + toggle de visão`.
 *
 * @param {object} props
 * @param {unknown} [props.abas] o `<AbasDeStatus>` já montado pela página (ReactNode)
 * @param {unknown} [props.visao] `'grupo'` ou `'lista'`
 * @param {unknown} [props.onTrocarVisao] recebe a chave da visão clicada
 * @param {unknown} [props.ordenacaoRotulo] o rótulo que descreve a ordenação do servidor
 */
export default function LinhaDeControles({
    abas = null,
    visao = VISAO_POR_PRODUTO,
    onTrocarVisao,
    ordenacaoRotulo = null,
}) {
    // ⚠️ NUNCA `String(abas)`: campo-objeto renderizado como texto derrubou a
    // página inteira em 07/10/2026. Nó inválido simplesmente não é desenhado.
    const blocoDasAbas = isValidElement(abas) ? abas : null;

    const rotuloDaOrdenacao = soTexto(ordenacaoRotulo) ?? ORDENACAO_PADRAO;

    /**
     * UMA opção do toggle. Chamada dentro do `.map()`; todas as flags nascem
     * aqui dentro (REND-04).
     */
    function opcaoDeVisao(item) {
        // ⚠️ Visão desconhecida (ou objeto/número) NÃO deixa o trilho sem opção
        // marcada: cai no default "Por produto", que é o que o servidor também
        // faz ao sanear `visao`.
        const escolhida = soTexto(visao) ?? VISAO_POR_PRODUTO;
        const conhecida = OPCOES_DE_VISAO.some((o) => o.chave === escolhida)
            ? escolhida
            : VISAO_POR_PRODUTO;
        const ehAtiva = item.chave === conhecida;

        return (
            <button
                key={item.chave}
                type="button"
                aria-pressed={ehAtiva}
                onClick={
                    typeof onTrocarVisao === 'function' ? () => onTrocarVisao(item.chave) : undefined
                }
                className={cn(
                    'h-[30px] cursor-pointer rounded-pub-control-sm px-[12px] text-[13px] font-medium',
                    ehAtiva ? CLASSE_ATIVA : CLASSE_INATIVA,
                )}
            >
                {item.rotulo}
            </button>
        );
    }

    return (
        <div className="flex items-center justify-between gap-[16px]">
            {blocoDasAbas}

            <div className="flex items-center gap-[10px]">
                <span className="text-[12.5px] text-pub-txt-3">{rotuloDaOrdenacao}</span>

                <div role="group" aria-label={ARIA_DO_TOGGLE} className={CLASSE_DO_TRILHO}>
                    {OPCOES_DE_VISAO.map((item) => opcaoDeVisao(item))}
                </div>
            </div>
        </div>
    );
}
