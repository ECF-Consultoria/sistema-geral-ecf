import { isValidElement } from 'react';
import { cn } from '@/lib/utils';
import { contagemMedida } from './LinhaDeGrupoDeProduto';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 180, plano 09 — o RODAPÉ da tabela de Publicações (LIST-16).
//
// Protótipo: `new_layout_publicador/Publicacoes v2.dc.html`. Linhas MEDIDAS com
// `grep -n` do literal e confirmadas pela SEMÂNTICA:
//   · o rodapé    → :293 (`display:flex;align-items:center;`
//                         `justify-content:space-between;height:44px;`
//                         `padding:0 16px;border-top:1px solid #262626;`
//                         `background:#0D0D0D;font-size:12px;color:#8A8A90`)
//   · a frase     → :294 (`<span>{{ footerLabel }}</span>`)
//   · a legenda   → :295 (`display:flex;align-items:center;gap:14px`)
//   · cada atalho → :296-:299 (`display:flex;align-items:center;gap:5px`, com a
//                         tecla em `font-family:'Geist Mono',monospace;`
//                         `padding:1px 6px;border:1px solid #2E2E2E;`
//                         `border-radius:4px;background:#111111;color:#A1A1AA`)
//   · as 4 teclas → `/ buscar` · `↑↓ navegar` · `Enter detalhes` · `Esc fechar`
//
// ⚠️ A PAGINAÇÃO DE 50 É A DE HOJE E FICA (critério 13). Ela chega como NÓ
// PRONTO (`paginacao`), montado na página (plano 180-10) com o
// `decodificarRotuloPaginacao()` que já existe em `MeusAnuncios.jsx:267` —
// aquela função existe para EVITAR `dangerouslySetInnerHTML` (T-134-20 do threat
// model: os rótulos do paginator do Laravel chegam com entidades HTML). Aqui o
// nó é renderizado como veio: nenhum `String()`, nenhum parser, nenhum
// `dangerouslySetInnerHTML`.
//
// ⚠️ O protótipo NÃO tem paginador (o mock tem 3 grupos e nada a paginar), então
// o lugar dele é DECISÃO DECLARADA: vai entre a frase e a legenda, no meio do
// `justify-between`. Está na 180-09-SUMMARY.
//
// ⚠️ "nunca medimos" ≠ "medimos e é zero": cada pedaço da frase só existe se o
// servidor mandou NÚMERO FINITO. O rodapé nunca escreve "— de — publicações".
//
// ⚠️ TODA classe do Tailwind mora neste `.jsx` (o `content` não varre `*.js`).
// ═══════════════════════════════════════════════════════════════════════════

/**
 * As quatro teclas da legenda, na ORDEM do protótipo (`:296`-`:299`).
 *
 * ⚠️ `↑↓` é U+2191 + U+2193 (duas setas num mesmo quadrinho, como no
 * protótipo), e não a palavra "setas" nem um ícone. O gate asserta o CODE POINT.
 */
export const ATALHOS_DO_RODAPE = [
    { tecla: '/', acao: 'buscar' },
    { tecla: '↑↓', acao: 'navegar' },
    { tecla: 'Enter', acao: 'detalhes' },
    { tecla: 'Esc', acao: 'fechar' },
];

/**
 * Nó que pode ser RENDERIZADO, ou `null`.
 *
 * ⚠️⚠️ EXISTE PORQUE `{paginacao}` COM UM OBJETO COMUM DERRUBA A PÁGINA:
 * o React lança `Objects are not valid as a React child` e a tela inteira vai
 * embora — é a tela preta de 07/10/2026 pela porta da prop de NÓ, e não pela
 * porta do campo de texto. Prop de nó chega de quem chama, e quem chama erra.
 *
 * ⚠️ `boolean` e `number` são recusados de propósito: `{false}` não desenha nada
 * (e aí o lugar fica mudo sem ninguém saber por quê) e um número solto no lugar
 * do paginador seria um "7" perdido no rodapé.
 *
 * @param {unknown} no
 * @returns {import('react').ReactNode|null}
 */
export function noRenderizavel(no) {
    if (isValidElement(no)) return no;
    if (Array.isArray(no)) {
        return no.length > 0 && no.every((item) => isValidElement(item)) ? no : null;
    }
    if (typeof no === 'string') return no.trim() === '' ? null : no;
    return null;
}

/**
 * A frase do rodapé, ou `null` quando não há número nenhum para afirmar.
 *
 *   · `{visiveis: 50, total: 248, produtos: 9}` → `"50 de 248 publicações · 9 produtos"`
 *   · `{visiveis: 1, total: 1, produtos: 1}`    → `"1 de 1 publicação · 1 produto"`
 *   · `produtos` não medido                     → `"50 de 248 publicações"` (sem separador solto)
 *   · `visiveis` não medido                     → `"248 publicações"` (o total é a verdade do recorte)
 *   · `total` não medido                        → a parte das publicações NÃO aparece
 *
 * ⚠️ A assimetria do penúltimo caso é de propósito: `total` sozinho é verdade
 * ("o recorte tem 248"), mas `visiveis` sozinho seria LIDO como total — "50
 * publicações" quando existem 248 é meia-verdade, e meia-verdade numérica nesta
 * tela é o que o critério de honestidade proíbe.
 *
 * @param {unknown} rodape `{visiveis, total, produtos}` do plano 180-04
 * @returns {string|null}
 */
export function fraseDoRodape(rodape) {
    const dados = rodape !== null && typeof rodape === 'object' && !Array.isArray(rodape) ? rodape : {};

    const visiveis = contagemMedida(dados.visiveis);
    const total = contagemMedida(dados.total);
    const produtos = contagemMedida(dados.produtos);

    const pedacos = [];

    if (total !== null) {
        const palavra = total === 1 ? 'publicação' : 'publicações';
        const totalPt = total.toLocaleString('pt-BR');
        pedacos.push(
            visiveis === null
                ? `${totalPt} ${palavra}`
                : `${visiveis.toLocaleString('pt-BR')} de ${totalPt} ${palavra}`,
        );
    }

    if (produtos !== null) {
        pedacos.push(`${produtos.toLocaleString('pt-BR')} ${produtos === 1 ? 'produto' : 'produtos'}`);
    }

    return pedacos.length === 0 ? null : pedacos.join(' · ');
}

/**
 * O rodapé da tabela.
 *
 * @param {object} props
 * @param {object|null} [props.rodape]        `{visiveis, total, produtos}`; nulo → só a legenda
 * @param {import('react').ReactNode} [props.paginacao]  os links do paginator, montados pela página
 * @param {string} [props.className]
 */
export default function RodapeDaTabela({ rodape = null, paginacao = null, className }) {
    const frase = fraseDoRodape(rodape);

    return (
        <div
            className={cn(
                'flex h-[44px] items-center justify-between border-t border-pub-line bg-pub-sunken px-[16px] text-[12px] text-pub-txt-3',
                className,
            )}
        >
            <span>{frase}</span>
            {noRenderizavel(paginacao)}
            <span className="flex items-center gap-[14px]">
                {ATALHOS_DO_RODAPE.map((atalho) => {
                    // ⚠️ REND-04: a classe da tecla nasce AQUI DENTRO, no
                    // callback. Variável de escopo do componente usada só dentro
                    // de um `.map()` já virou ReferenceError no bundle de
                    // produção deste projeto (o Rollup elimina a declaração e
                    // mantém o uso).
                    const classeDaTecla = 'font-pub-mono rounded-pub-badge border border-pub-line-strong bg-pub-surface px-[6px] py-[1px] text-pub-neutral-txt';

                    return (
                        <span key={atalho.tecla} className="flex items-center gap-[5px]">
                            <span className={classeDaTecla}>{atalho.tecla}</span>
                            {atalho.acao}
                        </span>
                    );
                })}
            </span>
        </div>
    );
}
