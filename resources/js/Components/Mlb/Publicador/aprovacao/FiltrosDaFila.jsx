import { cn } from '@/lib/utils';
import PopoverDeFiltro from './PopoverDeFiltro';
import { ROTULO_DO_BLOQUEIO } from './vocabularioDoPortao';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 06 — A LINHA DE FILTROS DA FILA (FILA-05, FILA-06, COL-02,
// COL-03, FID-01..04, REND-01..03, REND-05).
//
// É o que faz a fila ser usável com mais de dez ofertas: a busca, os dois
// segmentados, os dois popovers com contagem, "Limpar filtros" e "N de M".
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas, sha256 `68624883ce35547e`). A linha de filtros é `:66`-`:105`.
// Linhas MEDIDAS com `grep -n` e a varredura de propriedades rodada por SCRIPT
// nesta sessão (16/10/2026):
//
//   elemento                plano         MEDIDO
//   linha                   :66           :66   ✓
//   lupa (SVG inline)       :68           :68   ✓
//   input                   :69           :69   ✓
//   segmentado              :71 / :75     :71 / :75   ✓
//   rótulo do segmentado    :72           :72 (+ :76, que o plano OMITE)  ⚠️
//   opção ATIVA/inativa     :1280         :1267  ⚠️
//   "Limpar filtros"        :101          :104   ⚠️
//   "N de M ofertas"        :102          :105   ⚠️
//   `countLabel`            :1275         :1276  ⚠️
//   `anyFilter`             —             :1270
//
// ⚠️⚠️ **OMISSÃO MEDIDA NA TABELA DO PLANO:** a opção do segmentado (`:73`/`:77`)
// e o "Limpar filtros" (`:104`) declaram `white-space:nowrap`, e as classes
// prescritas **não a têm**. "Limpar filtros" quebraria em duas linhas quando a
// linha de filtros envolve (`flex-wrap:wrap`).
//
// ═══ ⚠️⚠️ OS DOIS TINTS SÃO DIFERENTES, E O PROTÓTIPO OS PINTA DIFERENTES ══
//
//   · opção ATIVA do segmentado (`seg()`, `:1267`): fundo
//     `rgba(250,204,21,0.16)`, peso 600, **borda nenhuma**;
//   · botão de popover ATIVO (`act()`, `:1268`): borda `rgba(250,204,21,0.55)`
//     + fundo `rgba(250,204,21,0.12)`.
// ⚠️ "Deixei igual ao outro" é **FID-02**, justificativa VETADA. O tint do
// popover mora em `PopoverDeFiltro.jsx`, e há gate declarando os dois lado a
// lado no mesmo caso.
//
// ═══ ⚠️ A LUPA É SVG INLINE, NUNCA LUCIDE (divergência D5) ═════════════════
//
// `:68` é `<svg width=14 height=14 viewBox="0 0 16 16">` com `stroke-width:1.6`,
// um `<circle cx=7 cy=7 r=4.5>` e um `<path d="M10.5 10.5L14 14">`. O Lucide é
// `0 0 24 24` e exigiria `strokeWidth × 1,5` para render igual — e nem assim os
// dois paths seriam os mesmos. ⚠️ `pointer-events:none` para o clique atravessar
// a lupa e cair no input.
//
// ═══ ⚠️⚠️ NÃO HÁ STICKY NESTA LINHA, E ISSO É MEDIDO ══════════════════════
//
// O docblock da página (`Pages/Mlb/Publicador/Aprovacao.jsx`) antecipava
// "sticky de filtros, quando chegar (187-06), é `top-[108px]`". ⚠️ **MEDIDO por
// script: a linha `:66` do protótipo NÃO tem `position:sticky` nem `top:` nem
// `z-index:`** — a varredura de propriedades do recorte `:66`-`:105` devolve
// `position: absolute|relative` (a lupa e as âncoras dos popovers) e nada mais.
// ➡️ **Não há sticky aqui**, porque inventá-lo seria efeito que o protótipo não
// pede e que ninguém pode conferir nesta máquina (o banco local não tem empresa
// com `ml_token`; a rota dá 404 aqui).
// ⚠️ Se um dia ele for pedido, o valor é `sticky top-[108px] z-10` — `top-0`
// está ERRADO porque o header global ocupa 108px do scrollport (medido na Fase
// 177) —, e o `z-10` é o MESMO nível do overlay do popover, abaixo do popover
// `z-20` e do header `z-30`.
//
// ═══ ⚠️⚠️ O "N DE M" É A DIVERGÊNCIA D8, E ELA É REPRODUZIDA ═══════════════
//
// `countLabel` é `list.length + ' de ' + all.length + ' ofertas'` (`:1276`): o
// **M** é o total de **TODAS** as ofertas da conta — **inclusive `preparando` e
// `publicado`** —, não o das ao vivo. ⚠️ **Reproduzido, e NÃO "consertado"**:
// mudar para o total da fila tornaria o número incoerente com o card "Todas" da
// faixa de andamento.
//
// ═══ ⚠️ NADA DE `localStorage` (COL-02 / critério 13) ══════════════════════
//
// O `KEY='ecf-aprovacao-v1'` do protótipo (`:914`) é artefato dele. **Todo
// filtro vive na URL** (COL-03): este componente é SEM ESTADO — recebe os
// valores por prop e devolve os handlers que a página traduz em `router.get`.
// ⚠️ Nem `useState`: a busca é um input CONTROLADO pela prop, e debounce/estado
// local aqui faria a URL e a tela divergirem por um instante.
//
// ⚠️ TODA classe mora NESTE `.jsx`: o `content` do `tailwind.config.js` varre
// `./resources/js/**/*.jsx` e **NÃO** `*.js`.
//
// ⚠️ `duration-[Nms]` / `delay-[…]` / `ease-[…]` **NÃO COMPILAM** neste projeto
// (o `tailwindcss-animate` torna a classe ambígua). Esta linha não tem transição
// nenhuma — o protótipo só declara `transition:background .15s` na LINHA DA
// TABELA (`:133`), que é do plano 187-08, e lá `duration-150` existe na escala
// nomeada.
//
// ⚠️ NUNCA `String(valor)` nem `JSON.stringify(valor)` sobre prop: o
// `String({toString: null})` LANÇA e derruba a página inteira (a TELA PRETA de
// 07/10/2026, que voltou QUATRO vezes na Fase 180).
//
// ⚠️ **Toda flag DENTRO do callback do `.map()`**: são 3+3 opções de segmentado
// aqui e até 8 itens por popover, e o Rollup elimina variável de escopo do
// componente usada dentro do `.map()`.
// ═══════════════════════════════════════════════════════════════════════════

/** Os literais de tela, byte a byte como o protótipo os escreve. */
export const PLACEHOLDER_DA_BUSCA = 'Produto, título ou SKU';
export const ROTULO_MODALIDADE = 'Modalidade';
export const ROTULO_FORMATO = 'Formato';
export const ROTULO_RESPONSAVEL = 'Responsável';
export const ROTULO_MOTIVO = 'Motivo de bloqueio';
export const ROTULO_LIMPAR = 'Limpar filtros';
/** `countLabel`, `:1276`: `list.length + ' de ' + all.length + ' ofertas'`. */
export const MEIO_DA_CONTAGEM = ' de ';
export const FIM_DA_CONTAGEM = ' ofertas';

/** Objeto comum de verdade, ou `null`. `null` e array são os dois `typeof 'object'`. */
function objetoComum(valor) {
    return valor !== null && typeof valor === 'object' && !Array.isArray(valor) ? valor : null;
}

/** Texto não-vazio, ou `null`. Nunca `String(valor)`, que LANÇA com `{toString:null}`. */
function textoNaoVazio(valor) {
    if (typeof valor !== 'string') return null;

    return valor.trim() === '' ? null : valor;
}

/** Texto, mesmo vazio, ou `''` — é o que um input CONTROLADO precisa. */
function textoDoInput(valor) {
    return typeof valor === 'string' ? valor : '';
}

/** Inteiro finito NÃO-NEGATIVO, ou `null`. Nunca `Number(valor)`. */
function inteiroNaoNegativo(valor) {
    return typeof valor === 'number' && Number.isInteger(valor) && valor >= 0 ? valor : null;
}

/** A lista de verdade, ou `[]`. */
function listaSegura(valor) {
    return Array.isArray(valor) ? valor : [];
}

// As classes, escritas uma vez e literalmente.

/** `:66` — `display:flex;align-items:center;gap:8px;flex-wrap:wrap`. Sem sticky, sem z. */
const CLASSES_LINHA = 'flex flex-wrap items-center gap-[8px]';

/** `:67` — o pai `relative` que ancora a lupa `absolute`. */
const CLASSES_CAMPO = 'relative flex items-center';

/** `:68` — `position:absolute;left:11px`, traço `#8A8A90`, sem preencher, sem capturar clique. */
const CLASSES_LUPA = 'pointer-events-none absolute left-[11px] fill-none stroke-pub-txt-3';

/** `:69` — 240×32, `padding:0 10px 0 32px`, raio 8px, foco amarelo. */
const CLASSES_INPUT = 'h-[32px] w-[240px] rounded-[8px] border border-pub-line-strong bg-pub-surface pl-[32px] pr-[10px] text-[12.5px] text-pub-txt outline-none focus:border-pub-accent';

/** `:71`/`:75` — 32px, raio 8px, `overflow:hidden` (é ele que recorta o tint da opção). */
const CLASSES_SEGMENTADO = 'flex h-[32px] items-center overflow-hidden rounded-[8px] border border-pub-line-strong bg-pub-surface';

/** `:72`/`:76` — `padding:0 10px`, `#8A8A90`, divisor `#1F1F1F` à direita, altura cheia. */
const CLASSES_ROTULO_SEG = 'flex h-full items-center border-r border-pub-line-subtle px-[10px] text-[12.5px] text-pub-txt-3';

/** `:73`/`:77` — `white-space:nowrap` é a PRIMEIRA propriedade, e o plano a OMITIA. */
const CLASSES_OPCAO = 'h-full cursor-pointer whitespace-nowrap px-[11px] text-[12.5px] text-pub-txt';
/** `seg()`, `:1267` — fundo `rgba(250,204,21,0.16)` e peso 600. ⚠️ SEM borda. */
const CLASSES_OPCAO_ATIVA = 'bg-pub-accent/[0.16] font-semibold';
const CLASSES_OPCAO_INATIVA = 'bg-transparent font-normal';

/** `:104` — `white-space:nowrap` (OMITIDA no plano), sublinhado com 3px de offset. */
const CLASSES_LIMPAR = 'h-[32px] cursor-pointer whitespace-nowrap px-[10px] text-[12.5px] text-pub-txt-2 underline underline-offset-[3px]';

/** `:105` — `margin-left:auto`, `#8A8A90`, 12.5px, sem quebrar. */
const CLASSES_CONTAGEM = 'ml-auto whitespace-nowrap text-[12.5px] text-pub-txt-3';

/**
 * O segmentado de 3 opções, com o rótulo à esquerda.
 *
 * ⚠️ Local a este arquivo, como `Sugadores/Index.jsx` faz com os sub-componentes
 * dele: os dois segmentados desta tela são os ÚNICOS consumidores, e um módulo
 * compartilhado criaria uma peça sem segundo uso.
 */
function Segmentado({ rotulo, opcoes, ativo, onEscolher }) {
    const lista = listaSegura(opcoes);

    // Segmentado sem opção não desenha casca: uma moldura de 32px vazia com o
    // rótulo dentro é D4 violado.
    if (lista.length === 0) return null;

    const podeEscolher = typeof onEscolher === 'function';

    return (
        <div className={CLASSES_SEGMENTADO}>
            <span className={CLASSES_ROTULO_SEG}>{rotulo}</span>
            {lista.map((cru) => {
                // ⚠️⚠️ TODA flag DENTRO do callback: o Rollup elimina variável de
                // escopo do componente usada aqui dentro.
                const opcao = objetoComum(cru);
                const valor = opcao === null ? null : textoNaoVazio(opcao.valor);
                const texto = opcao === null ? null : textoNaoVazio(opcao.rotulo);

                if (valor === null || texto === null) return null;

                const estaAtiva = valor === ativo;

                return (
                    <button
                        key={valor}
                        type="button"
                        onClick={podeEscolher ? () => onEscolher(valor) : undefined}
                        aria-pressed={estaAtiva}
                        className={cn(CLASSES_OPCAO, estaAtiva ? CLASSES_OPCAO_ATIVA : CLASSES_OPCAO_INATIVA)}
                    >
                        {texto}
                    </button>
                );
            })}
        </div>
    );
}

/**
 * A linha de filtros da fila.
 *
 * ⚠️ **Componente SEM ESTADO** (COL-03): todo valor vem por prop e todo clique
 * sai por handler. A URL é a fonte.
 *
 * @param {object} props
 * @param {unknown} [props.filtros] `{busca, modalidade, formato, responsavel, motivo}`
 * @param {unknown} [props.opcoes] `{modalidades, formatos, responsaveis, motivos}`
 * @param {unknown} [props.contagem] `{exibidas, total}` — o "N de M"
 * @param {unknown} [props.algumFiltro] se "Limpar filtros" aparece
 * @param {unknown} [props.menuAberto] `'responsavel'`, `'motivo'` ou `null`
 * @param {unknown} [props.onMenu] `(qual) => void`
 * @param {unknown} [props.onBusca] `(termo) => void`
 * @param {unknown} [props.onModalidade] `(valor) => void`
 * @param {unknown} [props.onFormato] `(valor) => void`
 * @param {unknown} [props.onResponsavel] `(id) => void`
 * @param {unknown} [props.onMotivo] `(chave) => void`
 * @param {unknown} [props.onLimpar] `() => void`
 */
export default function FiltrosDaFila({
    filtros = null,
    opcoes = null,
    contagem = null,
    algumFiltro = false,
    menuAberto = null,
    onMenu = null,
    onBusca = null,
    onModalidade = null,
    onFormato = null,
    onResponsavel = null,
    onMotivo = null,
    onLimpar = null,
}) {
    const escolhidos = objetoComum(filtros);
    const listas = objetoComum(opcoes);
    const numeros = objetoComum(contagem);

    const busca = textoDoInput(escolhidos?.busca);
    const modalidade = textoNaoVazio(escolhidos?.modalidade);
    const formato = textoNaoVazio(escolhidos?.formato);

    // ⚠️ Os ids de responsável viajam como NÚMERO do servidor e como TEXTO na
    // URL. O popover fala TEXTO (a chave é string), então a conversão acontece
    // aqui, UMA vez, e nunca dentro do `.map()`.
    const responsaveisAtivos = listaSegura(escolhidos?.responsavel)
        .map((id) => inteiroNaoNegativo(id))
        .filter((id) => id !== null)
        .map((id) => String(id));

    const motivosAtivos = listaSegura(escolhidos?.motivo).filter((c) => typeof c === 'string');

    const opcoesDeResponsavel = listaSegura(listas?.responsaveis).map((cru) => {
        // ⚠️ Flag DENTRO do callback. E o `nome` vira o `rotulo` do item: as
        // INICIAIS do avatar são derivadas pelo `PopoverDeFiltro`, com a
        // `iniciais()` de `identidade/identidade.js` — fonte ÚNICA por IDENT-08.
        const pessoa = objetoComum(cru);
        const id = pessoa === null ? null : inteiroNaoNegativo(pessoa.id);
        const nome = pessoa === null ? null : textoNaoVazio(pessoa.nome);

        if (id === null || nome === null) return null;

        return { chave: String(id), rotulo: nome, total: inteiroNaoNegativo(pessoa.total) };
    }).filter((o) => o !== null);

    const opcoesDeMotivo = listaSegura(listas?.motivos).map((cru) => {
        // ⚠️⚠️ Flag DENTRO do callback. E o RÓTULO é LIDO de
        // `vocabularioDoPortao.jsx` (`ROTULO_DO_BLOQUEIO`) — o servidor manda a
        // CHAVE, a tradução tem UMA casa, e escrever os 8 rótulos aqui criaria
        // uma segunda verdade livre para divergir.
        //
        // ⚠️ `Object.prototype.hasOwnProperty.call` e NUNCA
        // `ROTULO_DO_BLOQUEIO[chave]` cru nem `obj.hasOwnProperty(...)`: chave
        // vinda do servidor indexando mapa é o caminho da TELA PRETA de
        // 07/10/2026, e `hasOwnProperty` próprio LANÇA com `{hasOwnProperty:null}`.
        const motivo = objetoComum(cru);
        const chave = motivo === null ? null : textoNaoVazio(motivo.chave);

        if (chave === null) return null;

        const conhecido = Object.prototype.hasOwnProperty.call(ROTULO_DO_BLOQUEIO, chave);

        // Chave desconhecida NÃO vira item com a própria chave na tela: um
        // `frete_ausente` cru seria jargão, que a régua do projeto proíbe.
        if (!conhecido) return null;

        return { chave, rotulo: ROTULO_DO_BLOQUEIO[chave], total: inteiroNaoNegativo(motivo.total) };
    }).filter((o) => o !== null);

    const exibidas = inteiroNaoNegativo(numeros?.exibidas);
    const total = inteiroNaoNegativo(numeros?.total);

    const podeMudarBusca = typeof onBusca === 'function';
    const podeLimpar = typeof onLimpar === 'function';
    const podeAbrirMenu = typeof onMenu === 'function';

    return (
        <div className={CLASSES_LINHA}>
            <div className={CLASSES_CAMPO}>
                {/* ⚠️ SVG INLINE com `viewBox="0 0 16 16"` e `strokeWidth="1.6"`
                    (divergência D5) — NUNCA um ícone do Lucide, que é
                    `0 0 24 24`. `aria-hidden` porque o placeholder já diz o que
                    o campo faz. */}
                <svg
                    aria-hidden="true"
                    width="14"
                    height="14"
                    viewBox="0 0 16 16"
                    strokeWidth="1.6"
                    strokeLinecap="round"
                    className={CLASSES_LUPA}
                >
                    <circle cx="7" cy="7" r="4.5" />
                    <path d="M10.5 10.5L14 14" />
                </svg>
                <input
                    type="search"
                    value={busca}
                    onChange={podeMudarBusca ? (evento) => onBusca(evento.target.value) : undefined}
                    readOnly={!podeMudarBusca}
                    placeholder={PLACEHOLDER_DA_BUSCA}
                    aria-label={PLACEHOLDER_DA_BUSCA}
                    className={CLASSES_INPUT}
                />
            </div>

            <Segmentado
                rotulo={ROTULO_MODALIDADE}
                opcoes={listas?.modalidades}
                ativo={modalidade}
                onEscolher={onModalidade}
            />

            <Segmentado
                rotulo={ROTULO_FORMATO}
                opcoes={listas?.formatos}
                ativo={formato}
                onEscolher={onFormato}
            />

            {/* ⚠️ `w-[220px]` no de Responsável e `w-[260px]` no de Motivo
                (`:82` × `:95`) — e o de Motivo é o que leva `comAvatar={false}`:
                o avatar de 22px existe só no de Responsável (`:86`). */}
            <PopoverDeFiltro
                rotulo={ROTULO_RESPONSAVEL}
                opcoes={opcoesDeResponsavel}
                ativos={responsaveisAtivos}
                aberto={menuAberto === 'responsavel'}
                onAbrir={podeAbrirMenu ? () => onMenu(menuAberto === 'responsavel' ? null : 'responsavel') : null}
                onToggle={typeof onResponsavel === 'function' ? (chave) => onResponsavel(chave) : null}
                largo={false}
                comAvatar
            />

            <PopoverDeFiltro
                rotulo={ROTULO_MOTIVO}
                opcoes={opcoesDeMotivo}
                ativos={motivosAtivos}
                aberto={menuAberto === 'motivo'}
                onAbrir={podeAbrirMenu ? () => onMenu(menuAberto === 'motivo' ? null : 'motivo') : null}
                onToggle={typeof onMotivo === 'function' ? (chave) => onMotivo(chave) : null}
                largo
                comAvatar={false}
            />

            {/* ⚠️ "Limpar filtros" SÓ com algum filtro ativo (`:104` é um
                `<sc-if value="{{ Q.anyFilter }}">`), e `anyFilter` (`:1270`)
                inclui a ETAPA. ⚠️ Igualdade ESTRITA com `true`: acendê-lo por
                truthiness faria o botão aparecer sem filtro nenhum. */}
            {algumFiltro === true && podeLimpar && (
                <button type="button" onClick={() => onLimpar()} className={CLASSES_LIMPAR}>
                    {ROTULO_LIMPAR}
                </button>
            )}

            {/* ⚠️⚠️ O "N de M ofertas" APARECE SEMPRE (`:105` não tem `sc-if`), e
                o **M** é o total de TODAS as ofertas da conta, inclusive
                `preparando` e `publicado` (divergência D8, `:1276`).
                ⚠️ Com um dos dois números AUSENTE o bloco não aparece: "— de 12
                ofertas" seria número inventado. */}
            {exibidas !== null && total !== null && (
                <span className={CLASSES_CONTAGEM}>
                    {exibidas + MEIO_DA_CONTAGEM + total + FIM_DA_CONTAGEM}
                </span>
            )}
        </div>
    );
}
