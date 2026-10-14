import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 180, plano 08 — o cabeçalho do grid da tabela de Publicações (LIST-08).
//
// Protótipo: `new_layout_publicador/Publicacoes v2.dc.html`. Linhas MEDIDAS com
// `grep -n` neste arquivo (não copiadas da tabela do plano — esta rodada achou
// 11 omissões da tabela, todas a favor do protótipo; ver a 180-08-SUMMARY):
//   · card da tabela      → :162 (`background:#111111;border:1px solid #262626;`
//                                 `border-radius:14px;overflow:hidden`)
//   · o cabeçalho         → :163 (`display:grid;grid-template-columns:40px`
//                                 ` minmax(300px,1fr) 136px 104px 136px 112px`
//                                 ` 68px 150px 64px;align-items:center;`
//                                 `height:40px;padding:0 16px;background:#0D0D0D;`
//                                 `border-bottom:1px solid #262626;font-size:12px;`
//                                 `font-weight:500;color:#8A8A90`)
//   · checkbox "todas"    → :164 (`width:16px;height:16px;border-radius:4px;`
//                                 `border:1px solid {ON #FACC15 / OFF #3F3F46};`
//                                 `background:{ON #FACC15 / OFF #141414};padding:0;`
//                                 `display:grid;place-items:center;cursor:pointer`,
//                                 com `title="Selecionar todas"`)
//   · check do checkbox   → :165 (`10×10 viewBox "0 0 16 16"`, `fill:none;`
//                                 `stroke:#0A0A0A;stroke-width:2.4;`
//                                 `stroke-linecap:round;stroke-linejoin:round`,
//                                 path `M3.5 8.5l3 3 6-7`)
//   · os 9 rótulos        → :166 (`Publicação` · `Metodologia` · `Tipo` ·
//                                 `Status` · `Preço` com `text-align:right` ·
//                                 `Vendas` com `text-align:right` · `Publicada`
//                                 com `padding-left:18px` · a 9ª VAZIA)
//
// ⚠️ A STRING DO GRID MORA AQUI, UMA VEZ — `LinhaDePublicacao` a IMPORTA.
// Duas strings divergindo é o bug clássico de tabela em grid: o cabeçalho
// desalinha das linhas e nenhum teste de render acusa. O gate prova que o
// literal existe em UM arquivo só e que os dois usam o MESMO identificador.
//
// ⚠️ E ela mora num `.jsx` de propósito: `tailwind.config.js` varre
// `./resources/js/**/*.jsx` e NÃO `*.js`. A mesma classe escrita num módulo
// `.js` de `resources/js` não gera CSS e NÃO EXISTE PARA A TELA — com todos os
// testes de render passando, porque a classe está no HTML. ⚠️ E nada de
// concatenação: o scanner do Tailwind não vê classe montada por string.
//
// ⚠️ D-11: zero `router.*`. `onMarcarTodas` é callback LOCAL — a barra
// flutuante de ação em massa é a Fase 181, e a escrita no ML também.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * O `grid-template-columns` das 9 colunas, LITERAL e idêntico no cabeçalho e na
 * linha (protótipo `:163` e `:202`, byte a byte iguais entre si).
 *
 *   1 checkbox `40px` · 2 Publicação `minmax(300px,1fr)` · 3 Metodologia `136px`
 *   4 Tipo `104px` · 5 Status `136px` · 6 Preço `112px` · 7 Vendas `68px`
 *   8 Publicada `150px` · 9 ações `64px`
 *
 * ⚠️ FID-03: nenhum valor arredondado para o passo do Tailwind, e a soma das
 * fixas (40+136+104+136+112+68+150+64 = 810px) mais o `minmax(300px,1fr)` é a
 * largura mínima da tabela. Mexer num número desalinha a coluna inteira.
 */
export const GRID_DA_TABELA =
    'grid-cols-[40px_minmax(300px,1fr)_136px_104px_136px_112px_68px_150px_64px]';

/** O card da tabela (`:162`) — raio 14, borda `pub-line`, fundo `pub-surface`. */
export const CLASSE_DO_CARD = 'overflow-hidden rounded-pub-table border border-pub-line bg-pub-surface';

/**
 * O checkbox de 16px — o do cabeçalho (`:164`) e o da linha (`:203`) têm as
 * MESMAS medidas no protótipo, então há uma casa só e `LinhaDePublicacao`
 * importa daqui.
 *
 * ⚠️ `padding:0` do protótipo NÃO vira classe: o preflight do Tailwind já emite
 * `button { padding: 0 }`. Classe seria redundante — mesma disciplina que o
 * plano 180-07 aplicou ao `border:none`.
 */
export const CLASSE_DO_CHECKBOX = 'grid h-[16px] w-[16px] cursor-pointer place-items-center rounded-pub-badge border';

/** Borda + fundo do checkbox LIGADO (`:605`/`:164`: `#FACC15` nos dois). */
export const CHECKBOX_LIGADO = 'border-pub-accent bg-pub-accent';

/** Borda + fundo do checkbox DESLIGADO (`#3F3F46` e `#141414`). */
export const CHECKBOX_DESLIGADO = 'border-pub-line-off bg-pub-hover';

/**
 * O "check" de dentro do checkbox (`:165`).
 *
 * ⚠️ O protótipo desenha este SVG SEMPRE, ligado ou não — desligado ele fica
 * `#0A0A0A` sobre `#141414` (contraste ~1,05:1, invisível na prática). Medi e
 * mantive a fidelidade: o estado quem diz é o `aria-checked` do botão, não a
 * presença do glifo. Renderizar condicionalmente seria "adaptar o layout novo
 * ao sistema atual", que é exatamente o que esta milestone proíbe.
 */
export function CheckDoCheckbox() {
    return (
        <svg
            aria-hidden="true"
            width="10"
            height="10"
            viewBox="0 0 16 16"
            fill="none"
            strokeWidth={2.4}
            strokeLinecap="round"
            strokeLinejoin="round"
            className="stroke-pub-page"
        >
            <path d="M3.5 8.5l3 3 6-7" />
        </svg>
    );
}

/**
 * O checkbox quadrado de 16px, do cabeçalho e da linha.
 *
 * ⚠️ `role="checkbox"` + `aria-checked` + `aria-label`: o botão não tem texto
 * nenhum, então `aria-label` é o mecanismo CERTO aqui (não há conteúdo para ele
 * suprimir — ao contrário do caso da célula Status, ver `CelulasDaLinha.jsx`).
 * O `title` do protótipo fica, para o mouse.
 *
 * @param {object} props
 * @param {boolean} props.marcado
 * @param {(evento: object) => void | null} props.onAlternar
 * @param {string} props.rotulo  o que o leitor de tela ouve
 */
export function CheckboxDaTabela({ marcado, onAlternar, rotulo }) {
    const ligado = marcado === true;

    return (
        <button
            type="button"
            role="checkbox"
            aria-checked={ligado}
            aria-label={rotulo}
            title={rotulo}
            onClick={onAlternar ?? undefined}
            className={cn(CLASSE_DO_CHECKBOX, ligado ? CHECKBOX_LIGADO : CHECKBOX_DESLIGADO)}
        >
            <CheckDoCheckbox />
        </button>
    );
}

/**
 * O cabeçalho do grid.
 *
 * @param {object} props
 * @param {boolean} props.todasMarcadas
 * @param {(evento: object) => void} [props.onMarcarTodas]
 * @param {string} [props.className]
 */
export default function CabecalhoDaTabela({ todasMarcadas = false, onMarcarTodas = null, className }) {
    return (
        <div
            role="row"
            className={cn(
                'grid',
                GRID_DA_TABELA,
                'h-[40px] items-center border-b border-pub-line bg-pub-sunken px-[16px] text-[12px] font-medium text-pub-txt-3',
                className,
            )}
        >
            <CheckboxDaTabela
                marcado={todasMarcadas}
                onAlternar={onMarcarTodas}
                rotulo="Selecionar todas"
            />
            <span>Publicação</span>
            <span>Metodologia</span>
            <span>Tipo</span>
            <span>Status</span>
            <span className="text-right">Preço</span>
            <span className="text-right">Vendas</span>
            <span className="pl-[18px]">Publicada</span>
            <span />
        </div>
    );
}
