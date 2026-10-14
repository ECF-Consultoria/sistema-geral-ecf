import { cn } from '@/lib/utils';
import MapaDeCobertura from '../identidade/MapaDeCobertura';
import TileDoProduto from '../identidade/TileDoProduto';
import { caminhoSeguro, textoDaTela } from './CelulasDaLinha';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 180, plano 09 — a LINHA DE GRUPO da visão "Por produto" (LIST-09).
//
// Protótipo: `new_layout_publicador/Publicacoes v2.dc.html`. Linhas MEDIDAS com
// `grep -n` e confirmadas pela SEMÂNTICA (nunca pelo número de linha — as
// referências envelhecem e deslocam; o plano 180-08 errou duas por 3 e por 9):
//   · a linha de grupo → :173 (`display:flex;align-items:center;gap:14px;`
//                              `height:58px;padding:0 16px 0 14px;`
//                              `border-top:{{g.borderTop}};background:#151515;`
//                              **`cursor:pointer`**, `style-hover` `#1A1A1A`)
//   · chevron          → :174 (`14×14 viewBox "0 0 16 16"`, `flex:none;`
//                              `fill:none;stroke:#8A8A90;stroke-width:1.8;`
//                              `stroke-linecap:round;stroke-linejoin:round;`
//                              `transform:{{g.chev}};transition:transform .18s`,
//                              path `M6 4l4 4-4 4`)
//   · tile             → :175 (`38×38`, `border-radius:9px`, `font-size:12px`,
//                              `font-weight:600`, `flex:none`,
//                              **`margin-left:-4px`**)
//   · coluna nome+sku  → :176 (`display:flex;flex-direction:column;gap:2px;`
//                              `min-width:0`)
//   · nome + tag       → :177 (nome `14px/600`, `letter-spacing:-0.01em`,
//                              `gap:8px`; a tag "PRODUTO" em `10.5px/600`,
//                              `letter-spacing:0.06em`, `color:#8A8A90`,
//                              `padding:2px 6px`, `border:1px solid #262626`,
//                              `border-radius:4px`)
//   · "SKU · Estoque"  → :178 (`12px #8A8A90`, o SKU em `'Geist Mono'` a
//                              `11.5px`)
//   · bloco cobertura  → :180 (`gap:14px;margin-left:22px;padding-left:22px;`
//                              `border-left:1px solid #262626;height:30px`)
//   · par de fase      → :182/:183 (`gap:5px` + rótulo `F1`/`F2` em
//                              `11.5px/600 #A1A1AA`, `margin-right:2px`)
//   · célula do mapa   → :185 (`20×20`, raio 5, `10.5px/600`)
//   · "Criar combo"    → :190/:191 (`gap:5px;font-size:12px;font-weight:500;`
//                              `color:#FACC15;padding:4px 9px;`
//                              `border-radius:6px;`
//                              `background:rgba(250,204,21,0.12)` + seta
//                              `11×11 viewBox 16`, `stroke:currentColor`,
//                              `stroke-width:1.8`, path `M4 8h8M9 5l3 3-3 3`)
//   · contagem         → :194 (`margin-left:auto;font-size:12.5px;#A1A1AA`)
//   · "Ver produto"    → :195/:196 (`gap:5px;font-size:12.5px;`
//                              `font-weight:500;padding:6px 10px;`
//                              `border:1px solid #262626;border-radius:7px;`
//                              `background:#111111` + a MESMA seta de 11px)
//
// ⚠️⚠️ ESTA LINHA NÃO HERDA O GRID DA TABELA. O protótipo `:173` é `flex`, e as
// 9 colunas de `GRID_DA_TABELA` (`:163`/`:202`) são só do cabeçalho e das linhas
// de publicação. Obedecer o grid aqui alinharia colunas que esta linha não tem.
// Por isso `GRID_DA_TABELA` NÃO é importado — e não há literal redeclarado:
// nenhuma string de `grid-cols-[…]` nasce neste arquivo.
//
// ⚠️ DESVIO DECLARADO (não é omissão escondida): o protótipo separa
// **`F1 [C][P]`** de **`F2 [C][P]`**, cada par com rótulo próprio (`:182`/`:183`)
// e `gap:14px` entre os pares; `MapaDeCobertura` (Fase 177) desenha as QUATRO
// células seguidas com `gap:3px` e sem rótulo de fase. O conserto é uma prop
// aditiva em `identidade/MapaDeCobertura.jsx` — território que esta fase NÃO
// pode tocar. A dívida já está registrada em
// `.planning/learnings/gates-do-redesign-e-o-medidor-que-mente.md` §10 e a
// decisão está escrita na 180-09-SUMMARY, com a medida em `arquivo:linha`.
// ⚠️ O componente é IMPORTADO, não redesenhado: as 4 células continuam numa
// casa só (IDENT-08).
//
// ⚠️ EXMP-01 — nenhum botão/link sem destino: `ver_produto_url` e
// `criar_combo_url` só viram `<a>` quando são STRING. `textoUtil(7)` devolvendo
// `'7'` já montou rota inválida três vezes nesta milestone.
//
// ⚠️ D-11: zero `router.*`. `onAlternar` é callback LOCAL — colapsar grupo é
// estado da página (plano 180-10), e é lá que mora a regra "a busca ativa FORÇA
// os grupos abertos" (LIST-09). Aqui `aberto` chega pronto e NÃO é recalculado:
// a regra tem uma casa só.
//
// ⚠️ TODA classe do Tailwind mora neste `.jsx` — o `content` do
// `tailwind.config.js` varre `./resources/js/**/*.jsx` e NÃO `*.js`.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Contagem que o SERVIDOR mediu, ou `null`.
 *
 * ⚠️ Mais estrita que `numeroMedido` de propósito: lá a string `'7'` vira `7`,
 * e aqui o número entra numa FRASE que afirma quantas publicações existem.
 * Lavar uma string de um servidor quebrado em afirmação numérica é exatamente o
 * "nunca medimos ≠ medimos e é zero" ao contrário. O contrato do plano 180-04
 * manda `int` nos dois campos (`MlbAnuncioController.php:1456`-`:1457`).
 *
 * ⚠️ `Number.isFinite`, nunca `typeof === 'number'`: `typeof NaN` é `'number'` e
 * `NaN` escreveria "NaN publicações" na tela.
 *
 * @param {unknown} valor
 * @returns {number|null}
 */
export function contagemMedida(valor) {
    return typeof valor === 'number' && Number.isFinite(valor) ? valor : null;
}

/**
 * O rótulo da contagem do grupo, ou `null` quando não há o que afirmar.
 *
 *   · `3` de `3`  → `"3 publicações"`
 *   · `1` de `1`  → `"1 publicação"`   (singular)
 *   · `2` de `7`  → `"2 de 7 publicações"`
 *   · qualquer um dos dois não medido → `null` (a contagem não aparece)
 *
 * ⚠️ O plural acompanha o ÚLTIMO número da frase, que é o que o leitor associa
 * à palavra: "1 de 7 publicações" e "1 publicação".
 *
 * @param {unknown} totalVisivel
 * @param {unknown} totalNoProduto
 * @returns {string|null}
 */
export function rotuloDaContagem(totalVisivel, totalNoProduto) {
    const visivel = contagemMedida(totalVisivel);
    const total = contagemMedida(totalNoProduto);
    if (visivel === null || total === null) return null;

    const palavra = total === 1 ? 'publicação' : 'publicações';
    const totalPt = total.toLocaleString('pt-BR');
    if (visivel === total) return `${totalPt} ${palavra}`;

    return `${visivel.toLocaleString('pt-BR')} de ${totalPt} ${palavra}`;
}

/** O travessão da ausência (U+2014) — "não medido", nunca um zero inventado. */
const TRAVESSAO = '—';

/**
 * A seta de 11px dos dois links (`:191` e `:196`, byte a byte iguais).
 *
 * ⚠️ `viewBox "0 0 16 16"` e `stroke-width:1.8` são os do protótipo, LITERAIS —
 * não há conversão de escala aqui porque não é ícone do Lucide (a regra
 * `strokeWidth × 1,5` existe para quando se substitui um Lucide de viewBox 24).
 * ⚠️ `stroke:currentColor` é do protótipo e é o que faz a seta herdar o amarelo
 * no "Criar combo" e o texto claro no "Ver produto" — sem ele os dois ícones
 * sairiam preto sobre fundo escuro.
 */
function SetaDoLink() {
    return (
        <svg
            aria-hidden="true"
            width="11"
            height="11"
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.8}
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M4 8h8M9 5l3 3-3 3" />
        </svg>
    );
}

/**
 * O chevron que gira (`:174`).
 *
 * ⚠️ `rotate-90` aberto / `rotate-0` fechado — e a classe é escrita INTEIRA nos
 * dois ramos, nunca montada por concatenação: o scanner do Tailwind não vê
 * classe construída por string.
 */
function ChevronDoGrupo({ aberto }) {
    return (
        <svg
            aria-hidden="true"
            width="14"
            height="14"
            viewBox="0 0 16 16"
            fill="none"
            strokeWidth={1.8}
            strokeLinecap="round"
            strokeLinejoin="round"
            className={cn(
                'flex-none stroke-pub-txt-3 transition-transform duration-[180ms]',
                aberto === true ? 'rotate-90' : 'rotate-0',
            )}
        >
            <path d="M6 4l4 4-4 4" />
        </svg>
    );
}

/**
 * A linha de grupo de um produto.
 *
 * @param {object} props
 * @param {object|null} props.grupo          uma entrada de `grupos` (plano 180-04)
 * @param {boolean} [props.aberto]           `!!busca || !colapsado[produto_id]` — vem RESOLVIDO da página
 * @param {boolean} [props.primeiro]         o primeiro grupo da tabela não tem `border-top`
 * @param {(evento: object) => void} [props.onAlternar]
 * @param {string} [props.className]
 */
export default function LinhaDeGrupoDeProduto({
    grupo,
    aberto = false,
    primeiro = false,
    onAlternar = null,
    className,
}) {
    // ⚠️ REND-01: `grupo` chegando objeto errado, string, número, array ou nulo
    // não pode derrubar a tela. Na Fase 178 GRUPO NULO derrubou o painel
    // inteiro, e só o render adverso pegou.
    const dados = grupo !== null && typeof grupo === 'object' && !Array.isArray(grupo) ? grupo : {};

    // ⚠️ O grupo LEGADO (`produto_id: null`, plano 180-04) não é um produto:
    // nada que afirme produto nasce nele — nem tile de iniciais, nem SKU, nem
    // os dois links. O NOME vem do servidor (`GRUPO_SEM_PRODUTO`,
    // `MlbAnuncioController.php:1488`).
    const temProduto = typeof dados.produto_id === 'number' && Number.isFinite(dados.produto_id);

    // ⚠️ Dois fallbacks, cada um dizendo O QUE é — nunca um nome inventado e
    // nunca "—" no lugar de nome. O servidor manda nome nos dois casos hoje;
    // isto é cinto de segurança, não caminho normal.
    const nome = textoDaTela(dados.produto_nome)
        ?? (temProduto ? 'Produto sem nome' : 'Publicações sem produto');

    const sku = temProduto ? textoDaTela(dados.produto_sku) : null;

    // ⚠️ `estoque` nulo é "NÃO MEDIMOS", e aparece como "Estoque —". `estoque: 0`
    // é "medido e é zero" e aparece como "Estoque 0". Os dois são verdades
    // diferentes e a tela não pode confundi-las — é o mesmo contrato que o
    // controller já escreveu em `MlbAnuncioController.php:1446`-`:1450`
    // ("pode ser `null`, e então a tela mostra 'Estoque —', que é honesto;
    // nunca um zero inventado").
    const estoque = temProduto ? contagemMedida(dados.estoque) : null;
    const textoDoEstoque = temProduto
        ? `Estoque ${estoque === null ? TRAVESSAO : estoque.toLocaleString('pt-BR')}`
        : null;

    const contagem = rotuloDaContagem(dados.total_visivel, dados.total_no_produto);

    // ⚠️ EXMP-01, as duas condições em `E`: "Criar combo" exige `sem_fase2`
    // VERDADEIRO **e** destino real. `sem_fase2: true` com `criar_combo_url:
    // null` (que é o que o servidor manda quando a conta não resolveu) → sem
    // link. `sem_fase2: false` com URL → sem link, porque o produto já tem
    // Fase 2 e o atalho não tem o que oferecer.
    const urlDoProduto = temProduto ? caminhoSeguro(dados.ver_produto_url) : null;
    const urlDoCombo = temProduto && dados.sem_fase2 === true ? caminhoSeguro(dados.criar_combo_url) : null;

    // ⚠️ O `margin-left:auto` do protótipo mora na CONTAGEM (`:190`). Quando ela
    // não é medida, quem empurra o bloco da direita é o "Ver produto" — sem
    // isso o botão encostaria na cobertura e a linha pareceria outra.
    const empurraODaDireita = contagem === null;

    const rotuloAcessivel = contagem === null ? nome : `${nome} — ${contagem}`;

    return (
        <div
            role="button"
            tabIndex={0}
            aria-expanded={aberto === true}
            aria-label={rotuloAcessivel}
            onClick={onAlternar ?? undefined}
            onKeyDown={onAlternar === null ? undefined : (evento) => {
                // `role="button"` sem teclado é meia acessibilidade: quem navega
                // por Tab precisa de Enter/Espaço. O atalho GLOBAL (↑↓/Enter) é
                // do plano 180-11 e não mora aqui — este handler é do elemento,
                // não do documento.
                if (evento.key === 'Enter' || evento.key === ' ') {
                    evento.preventDefault();
                    onAlternar(evento);
                }
            }}
            className={cn(
                'flex h-[58px] cursor-pointer items-center gap-[14px] bg-pub-raised pl-[14px] pr-[16px] hover:bg-pub-hover-3',
                primeiro === true ? null : 'border-t border-pub-line',
                className,
            )}
        >
            <ChevronDoGrupo aberto={aberto} />

            {/* ⚠️ `letra="text-[12px]"` porque o protótipo `:175` diz 12px e a
                escada de `TileDoProduto` devolve `text-[11.5px]` para o lado 38.
                A prop existe desde a Fase 178 exatamente para a tela sair da
                escada sem mexer na escada (que serve 4 telas já conferidas). O
                RAIO de 9px vem da própria escada e bate com o protótipo. */}
            {temProduto && (
                <TileDoProduto
                    id={dados.produto_id}
                    nome={dados.produto_nome}
                    tamanho={38}
                    letra="text-[12px]"
                    className="-ml-[4px]"
                />
            )}

            <div className="flex min-w-0 flex-col gap-[2px]">
                <span className="flex items-center gap-[8px] text-[14px] font-semibold tracking-[-0.01em]">
                    {nome}
                    {temProduto && (
                        <span className="rounded-pub-badge border border-pub-line px-[6px] py-[2px] text-[10.5px] font-semibold tracking-[0.06em] text-pub-txt-3">
                            PRODUTO
                        </span>
                    )}
                </span>
                {(sku !== null || textoDoEstoque !== null) && (
                    <span className="text-[12px] text-pub-txt-3">
                        {sku !== null && <span className="font-pub-mono text-[11.5px]">{sku}</span>}
                        {sku !== null && textoDoEstoque !== null && ' · '}
                        {textoDoEstoque}
                    </span>
                )}
            </div>

            <div className="ml-[22px] flex h-[30px] items-center gap-[14px] border-l border-pub-line pl-[22px]">
                <MapaDeCobertura cobertura={dados.cobertura} />
                {urlDoCombo !== null && (
                    <a
                        href={urlDoCombo}
                        // ⚠️ O destino é REAL (é o mesmo que a linha "Prontos
                        // para a Fase 2" da Visão geral já usa em produção), mas
                        // ele NÃO pré-seleciona o produto: não existe rota GET
                        // que crie o combo de um produto específico, e
                        // `Produtos.jsx` lê `?filtro=`/`?fase=` da URL mas a
                        // busca é estado local (`:463`). Medido pelo plano
                        // 180-04. O texto visível é o do protótipo, byte a byte;
                        // a limitação vai no nome acessível, que é onde ela pode
                        // ser dita sem quebrar a fidelidade.
                        title="Fase 2 não criada — abre a lista de produtos sem Fase 2; o produto não vem pré-selecionado"
                        aria-label="Fase 2 não criada — abre a lista de produtos sem Fase 2; o produto não vem pré-selecionado"
                        onClick={(evento) => evento.stopPropagation()}
                        className="flex items-center gap-[5px] rounded-pub-chip bg-pub-accent/[0.12] px-[9px] py-[4px] text-[12px] font-medium text-pub-accent"
                    >
                        Fase 2 não criada · Criar combo
                        <SetaDoLink />
                    </a>
                )}
            </div>

            {contagem !== null && (
                <span className="ml-auto text-[12.5px] text-pub-neutral-txt">{contagem}</span>
            )}

            {urlDoProduto !== null && (
                <a
                    href={urlDoProduto}
                    onClick={(evento) => evento.stopPropagation()}
                    className={cn(
                        'flex items-center gap-[5px] rounded-pub-control-sm border border-pub-line bg-pub-surface px-[10px] py-[6px] text-[12.5px] font-medium',
                        empurraODaDireita ? 'ml-auto' : null,
                    )}
                >
                    Ver produto
                    <SetaDoLink />
                </a>
            )}
        </div>
    );
}
