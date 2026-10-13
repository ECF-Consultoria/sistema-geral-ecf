// ═══════════════════════════════════════════════════════════════════════════
// Fase 180, plano 06 — a busca central de 54px da tela de Publicações
// (LIST-03/LIST-04).
//
// Protótipo: `new_layout_publicador/Publicacoes v2.dc.html`. Linhas MEDIDAS com
// `grep -n` neste arquivo (não copiadas da tabela do plano — e esta rodada
// achou 4 omissões da tabela, todas a favor do protótipo; ver a 180-06-SUMMARY):
//   · wrapper            → :68  (`position:relative;display:flex;align-items:center`)
//   · ícone de lupa      → :69  (`18×18 viewBox "0 0 16 16"`, `position:absolute;left:18px`,
//                                `fill:none;stroke:#A1A1AA;stroke-width:1.6;stroke-linecap:round`,
//                                `pointer-events:none`)
//   · o `<input>`        → :70  (`width:100%;height:54px;padding:0 220px 0 50px;`
//                                `border:1px solid #2E2E2E;border-radius:14px;background:#111111;`
//                                `font-family:inherit;font-size:15.5px;color:#F4F4F5;outline:none;`
//                                `transition:border-color .15s, box-shadow .15s`)
//   · foco do input      → :70  (`border-color:#FACC15;box-shadow:0 0 0 3px rgba(250,204,21,0.18)`)
//   · bloco da direita   → :71  (`position:absolute;right:12px;gap:10px`)
//   · resumo (com busca) → :73  (`font-size:12.5px;color:#A1A1AA`)
//   · botão "Limpar"     → :74  (`28px;0 10px;1px #262626;raio 7px;#0D0D0D;12px;#A1A1AA;cursor:pointer`)
//   · dica (sem busca)   → :77  (`gap:6px;font-size:12px;color:#8A8A90`)
//   · a tecla "/"        → :77  (`'Geist Mono';11.5px;2px 7px;1px #2E2E2E;`
//                                `border-bottom-width:2px;raio 5px;#A1A1AA;#0D0D0D`)
//   · a frase do resumo  → :626 (`N publicação|publicações · M produto|produtos`)
//
// ⚠️ `font-family:inherit` do `:70` NÃO ganha classe: o preflight do Tailwind já
// emite `input { font-family: inherit }`. A medida fica escrita aqui para a
// próxima pessoa não achar que foi esquecida.
//
// ⚠️ `cursor-pointer` no "Limpar" (`:74`) ESTÁ aqui porque o protótipo o
// declara — a tabela FID-01 do plano o omitiu. É a 4ª vez na fase que a tabela
// esquece `cursor:pointer` (as 3 primeiras foram `:39`, `:61` e `:62`, no plano
// 180-05): o protótipo o declara 31 vezes no arquivo.
//
// ⚠️ A CONTAGEM NUNCA É FEITA NO CLIENTE, e isto é uma DIVERGÊNCIA DECLARADA
// com o protótipo. Lá o resumo sai de `list.length` (`:626`), porque o mock tem
// 24 linhas na memória. Aqui a página é PAGINADA de 50 em 50 sobre dezenas de
// milhares de linhas de acervo: contar o que chegou mentiria sobre o resto
// (D-05, que a tela de hoje já respeita). Os dois números vêm de `resumo`, do
// servidor — e quando não vierem, a frase simplesmente não aparece.
//
// ⚠️ "nunca medimos" ≠ "medimos e é zero": `publicacoes`/`produtos` só entram na
// frase quando são número FINITO (`Number.isFinite`), nunca por
// `typeof === 'number'` — `typeof NaN` é `'number'` e `textoSeguro(NaN)`
// devolve a string `'NaN'`, que já sujou tela neste projeto. `0` medido APARECE.
//
// ⚠️ O estado dos dois blocos da direita é decidido por `valor.trim() !== ''`, e
// NÃO por `resumo`: com busca ativa e resumo nulo aparece o "Limpar" SEM o
// resumo. É honesto — a contagem é do servidor e pode não ter chegado.
//
// ⚠️ ZERO `window.addEventListener` neste arquivo. O atalho "/" é do plano
// 180-11; aqui só ficam os GANCHOS (`refDoInput`, `onTecla`), repassados ao
// `<input>`. D-11: nenhum `router.*`.
//
// ⚠️ TODA classe do Tailwind mora NESTE `.jsx`: o `content` do
// `tailwind.config.js:12` varre `*.jsx` e **não** `*.js`. Mapa de classe em
// módulo `.js` não gera CSS e não existe para a tela, com os testes de render
// passando.
// ═══════════════════════════════════════════════════════════════════════════

/** Os literais de tela, byte a byte como o protótipo os escreve. */
export const PLACEHOLDER = 'Buscar por produto, título, SKU ou MLB…';
export const LIMPAR = 'Limpar';
export const DICA_PREFIXO = 'Pressione';
export const DICA_SUFIXO = 'para buscar';
export const TECLA_DA_BUSCA = '/';

/** O separador da frase do resumo (`:626`), `·` com espaço nos dois lados. */
const SEPARADOR = ' · ';

/**
 * Um pedaço da frase do resumo, ou `null` quando o número não foi medido.
 *
 * ⚠️ `Number.isFinite` e não `typeof === 'number'`: `NaN` e `Infinity` são
 * `'number'` e sairiam na tela como "NaN publicações". Fracionário também sai
 * fora — contagem com casa decimal é sinal de bug no servidor, não de medida.
 *
 * @param {unknown} quantidade
 * @param {string} singular
 * @param {string} plural
 * @returns {string|null}
 */
function pedacoDoResumo(quantidade, singular, plural) {
    if (!Number.isFinite(quantidade)) return null;
    if (!Number.isInteger(quantidade) || quantidade < 0) return null;
    return `${quantidade} ${quantidade === 1 ? singular : plural}`;
}

/**
 * A frase "N publicações · N produtos", ou `null` quando não há nada medido.
 *
 * ⚠️ Pedaço ausente NÃO é preenchido com "—" nem deixa separador sobrando: se
 * só um dos dois foi medido, a frase é só ele.
 *
 * @param {unknown} resumo `{publicacoes, produtos}` ou `null`
 * @returns {string|null}
 */
export function fraseDoResumo(resumo) {
    if (resumo === null || typeof resumo !== 'object' || Array.isArray(resumo)) return null;

    const pedacos = [
        pedacoDoResumo(resumo.publicacoes, 'publicação', 'publicações'),
        pedacoDoResumo(resumo.produtos, 'produto', 'produtos'),
    ].filter((pedaco) => pedaco !== null);

    return pedacos.length === 0 ? null : pedacos.join(SEPARADOR);
}

/**
 * `true` quando há busca digitada — a única coisa que decide qual dos dois
 * blocos da direita aparece.
 *
 * @param {unknown} valor
 */
export function temBusca(valor) {
    return typeof valor === 'string' && valor.trim() !== '';
}

/**
 * O ícone de lupa do protótipo (`:69`).
 *
 * ⚠️ SVG inline com o `viewBox` do protótipo (`0 0 16 16`) em vez do `Search`
 * do Lucide: o `viewBox` do Lucide é `0 0 24 24`, o que exigiria
 * `strokeWidth` × 1,5 e um desenho diferente. Foi a escolha das Fases 177-179
 * nos mesmos casos.
 */
function IconeDeLupa() {
    return (
        <svg
            aria-hidden="true"
            width="18"
            height="18"
            viewBox="0 0 16 16"
            strokeWidth="1.6"
            strokeLinecap="round"
            className="pointer-events-none absolute left-[18px] fill-none stroke-pub-neutral-txt"
        >
            <circle cx="7" cy="7" r="4.5" />
            <path d="M10.5 10.5L14 14" />
        </svg>
    );
}

/**
 * A busca central de 54px (LIST-03/LIST-04).
 *
 * @param {object} props
 * @param {unknown} [props.valor] o texto da busca — CONTROLADO pela página
 * @param {unknown} [props.onMudar] handler do `onChange`
 * @param {unknown} [props.onLimpar] handler do botão "Limpar"
 * @param {unknown} [props.onTecla] `↓`/`Enter` dentro da busca — o 180-11 liga
 * @param {unknown} [props.refDoInput] o `ref` para o atalho "/" — o 180-11 liga
 * @param {unknown} [props.resumo] `{publicacoes, produtos}` do servidor, ou `null`
 */
export default function BuscaDePublicacoes({
    valor = '',
    onMudar,
    onLimpar,
    onTecla,
    refDoInput,
    resumo = null,
}) {
    // ⚠️ Nada de `String(valor)`: campo-objeto renderizado como texto derrubou a
    // página inteira em 07/10/2026. `value` de `<input>` exige string fechada.
    const texto = typeof valor === 'string' ? valor : '';
    const buscando = temBusca(texto);
    const frase = buscando ? fraseDoResumo(resumo) : null;

    // ⚠️ `ref` de string foi REMOVIDO no React 18 e LANÇA. Só função ou objeto.
    const ref =
        typeof refDoInput === 'function' || (refDoInput !== null && typeof refDoInput === 'object')
            ? refDoInput
            : undefined;

    return (
        <div className="relative flex items-center">
            <IconeDeLupa />

            <input
                type="text"
                ref={ref}
                value={texto}
                onChange={typeof onMudar === 'function' ? onMudar : undefined}
                onKeyDown={typeof onTecla === 'function' ? onTecla : undefined}
                placeholder={PLACEHOLDER}
                aria-label={PLACEHOLDER}
                className="h-[54px] w-full rounded-pub-table border border-pub-line-strong bg-pub-surface pl-[50px] pr-[220px] text-[15.5px] text-pub-txt outline-none transition-[border-color,box-shadow] duration-150 placeholder:text-pub-txt-4 focus:border-pub-accent focus:ring-[3px] focus:ring-pub-accent/[0.18]"
            />

            <div className="absolute right-[12px] flex items-center gap-[10px]">
                {buscando ? (
                    <>
                        {frase !== null && (
                            <span className="text-[12.5px] text-pub-neutral-txt">{frase}</span>
                        )}
                        {/* ⚠️ EXMP-01: sem handler o botão NÃO é desenhado. Um
                            "Limpar" que não limpa é botão sem destino, e
                            desenhá-lo desabilitado afirmaria outra coisa
                            ("existe, só está ocupado"). */}
                        {typeof onLimpar === 'function' && (
                            <button
                                type="button"
                                onClick={onLimpar}
                                className="h-[28px] cursor-pointer rounded-pub-control-sm border border-pub-line bg-pub-sunken px-[10px] text-[12px] text-pub-neutral-txt"
                            >
                                {LIMPAR}
                            </button>
                        )}
                    </>
                ) : (
                    <span className="flex items-center gap-[6px] text-[12px] text-pub-txt-3">
                        {DICA_PREFIXO}{' '}
                        <span className="rounded-[5px] border border-b-2 border-pub-line-strong bg-pub-sunken px-[7px] py-[2px] font-pub-mono text-[11.5px] text-pub-neutral-txt">
                            {TECLA_DA_BUSCA}
                        </span>{' '}
                        {DICA_SUFIXO}
                    </span>
                )}
            </div>
        </div>
    );
}
