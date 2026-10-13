import { isValidElement, useEffect } from 'react';
import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 180, plano 07 — a CASCA dos dois popovers de filtro da tela de
// Publicações: "Responsável" e "Publicada" (LIST-07).
//
// Protótipo: `new_layout_publicador/Publicacoes v2.dc.html`. Linhas MEDIDAS com
// `grep -n` neste arquivo (não copiadas da tabela do plano):
//   · popover de Responsável → :127 (`position:absolute;top:38px;left:0;z-index:40;`
//                                    `width:220px;padding:6px;background:#111111;`
//                                    `border:1px solid #2E2E2E;border-radius:10px;`
//                                    `box-shadow:0 16px 40px rgba(0,0,0,0.6)`)
//   · popover de Publicada   → :146 (IDÊNTICO, só `width:190px`)
//   · backdrop de fechar     → :307 (`position:fixed;inset:0;z-index:30`)
//   · estado do menu         → :636 (`menuResp`/`menuPeriod`) e :638 (`onCloseMenu`)
//
// ⚠️ O WRAPPER `position:relative` NÃO é daqui: no protótipo ele é a `<div>` do
// próprio chip (`:121` e `:139`), e é de lá que o `top:38px` se mede. Este
// componente é só o popover + o backdrop.
//
// ⚠️ Z-INDEX — A ORDEM É TRANSPOSTA, NÃO O NÚMERO.
// `tests/js/publicador-header-zindex-ordem.test.js` prova a ordem
// **filtros < header < menu lateral = painel do produto**. Aqui o header é
// `z-30` e o menu lateral do `AppLayout` é `z-40`. No protótipo o header é 50,
// o popover 40 e o backdrop 30 — escalas DIFERENTES. Transpor o 40 cru poria o
// popover EMPATADO com o menu lateral do sistema e ACIMA do header, invertendo
// a ordem que aquele gate trava. ➡️ Os dois ficam no slot "filtros": `z-10`.
// ⚠️ Nenhum z-index novo acima de 10 nesta fase. O painel de inspeção de 460px
// (que no protótipo é `z-index:70`, ACIMA do header) é a Fase 181, e é lá que a
// ordem pode mudar.
//
// ⚠️ O backdrop vem ANTES do popover no DOM de propósito: os dois estão no
// MESMO `z-10` e no mesmo contexto de empilhamento (o wrapper do chip é
// `relative` sem `z-index`, logo não cria contexto novo), então quem pinta por
// cima é quem vem DEPOIS na ordem do documento. Invertido, o backdrop cobriria
// o próprio popover e nenhum item seria clicável.
//
// ⚠️ NÃO é portal, de propósito: o renderizador de servidor do React não
// suporta portal e o corpo de um modal portado NÃO APARECE no HTML — o gate
// mediria nada. O protótipo também faz `absolute` dentro do `relative` do chip.
// Se algum dia virar portal, exporte as peças internas como named exports (foi
// o que a Fase 178 fez) e declare `font-pub` por conta própria, porque conteúdo
// portado não herda a fonte.
//
// ⚠️ Nenhum pacote novo: é `div` + `button`. `@radix-ui/react-popover` arrasta
// o Dialog e o `react-remove-scroll` (CJS), que estoura o harness de esbuild com
// `Dynamic require of "react"`. E `@/Components/ui/select` é vetado pelo
// GATE-03.
//
// ⚠️ O listener de `Esc` TEM cleanup. Vazar listener entre navegações do
// Inertia é bug silencioso: a página seguinte continuaria com um handler
// chamando o `onFechar` de um componente que já morreu (T-180-07-D2).
//
// ⚠️ TODA classe do Tailwind mora NESTE `.jsx` — o `content` do
// `tailwind.config.js` varre `*.jsx` e NÃO `*.js`.
// ═══════════════════════════════════════════════════════════════════════════

/** A largura do popover de "Responsável" (`:127`). */
export const LARGURA_RESPONSAVEL = 220;

/** A largura do popover de "Publicada" (`:146`). */
export const LARGURA_PUBLICADA = 190;

/**
 * As DUAS larguras medidas, como classe LITERAL.
 *
 * ⚠️ Lista fechada e literal porque o scanner do Tailwind não vê classe
 * montada por concatenação: `w-[${largura}px]` não geraria CSS nenhum, e o
 * popover nasceria sem largura com todos os testes de render passando.
 */
export const CLASSE_DA_LARGURA = {
    [LARGURA_RESPONSAVEL]: 'w-[220px]',
    [LARGURA_PUBLICADA]: 'w-[190px]',
};

/** A classe do popover (`:127`/`:146`), sem a largura. */
export const CLASSE_DO_POPOVER =
    'absolute left-0 top-[38px] z-10 rounded-pub-sub border border-pub-line-strong'
    + ' bg-pub-surface p-[6px] shadow-[0_16px_40px_rgba(0,0,0,0.6)]';

/** A classe do backdrop de clique-fora (`:307`), com a ORDEM transposta. */
export const CLASSE_DO_BACKDROP = 'fixed inset-0 z-10';

/** A tecla que fecha o menu. */
export const TECLA_DE_FECHAR = 'Escape';

/**
 * Filhos que o React pode renderizar sem LANÇAR.
 *
 * ⚠️ Objeto cru como `children` lança "Objects are not valid as a React
 * child" e derruba a página inteira — é a família da tela preta de 07/10/2026.
 * Número também é recusado: `NaN` escreveria "NaN" dentro do popover
 * (`typeof NaN` é `'number'`).
 */
function filhosSeguros(filhos) {
    if (filhos === null || filhos === undefined || typeof filhos === 'boolean') return null;
    if (typeof filhos === 'string') return filhos.trim() === '' ? null : filhos;
    if (Array.isArray(filhos)) {
        const limpos = filhos.map((filho) => filhosSeguros(filho)).filter((filho) => filho !== null);
        return limpos.length === 0 ? null : limpos;
    }
    if (isValidElement(filhos)) return filhos;
    return null;
}

/**
 * A casca dos dois popovers de filtro.
 *
 * Fechado (`aberto !== true`) devolve `null`: nada no HTML, nem moldura nem
 * backdrop invisível capturando clique da página.
 *
 * @param {object} props
 * @param {unknown} [props.aberto] só `true` abre
 * @param {unknown} [props.largura] `220` (Responsável) ou `190` (Publicada)
 * @param {unknown} [props.onFechar] chamado pelo clique no backdrop e pelo `Esc`
 * @param {unknown} [props.children] os itens do menu
 */
export default function MenuDeFiltro({
    aberto = false,
    largura = LARGURA_RESPONSAVEL,
    onFechar,
    children,
}) {
    const estaAberto = aberto === true;
    const fechar = typeof onFechar === 'function' ? onFechar : null;

    // ⚠️ O hook roda SEMPRE (antes de qualquer `return`), senão a ordem dos
    // hooks mudaria entre os dois estados e o React quebraria.
    useEffect(() => {
        if (!estaAberto || fechar === null) return undefined;

        function aoTeclar(evento) {
            if (evento.key === TECLA_DE_FECHAR) fechar();
        }

        document.addEventListener('keydown', aoTeclar);
        return () => document.removeEventListener('keydown', aoTeclar);
    }, [estaAberto, fechar]);

    if (!estaAberto) return null;

    const filhos = filhosSeguros(children);

    // ⚠️ Popover sem conteúdo NÃO nasce (EXMP-01): moldura vazia é um botão sem
    // destino — o usuário abre e não há nada para escolher.
    if (filhos === null) return null;

    const classeDaLargura =
        Object.prototype.hasOwnProperty.call(CLASSE_DA_LARGURA, largura)
            ? CLASSE_DA_LARGURA[largura]
            : CLASSE_DA_LARGURA[LARGURA_RESPONSAVEL];

    return (
        <>
            {/* ⚠️ O backdrop VEM PRIMEIRO: mesmo `z-10`, quem pinta por cima é
                quem vem depois no documento. Sem handler ele não nasce — um
                véu que cobre a tela e não fecha nada travaria a página. */}
            {fechar !== null && (
                <div aria-hidden="true" onClick={fechar} className={CLASSE_DO_BACKDROP} />
            )}

            <div role="menu" className={cn(CLASSE_DO_POPOVER, classeDaLargura)}>
                {filhos}
            </div>
        </>
    );
}
