import { cn } from '@/lib/utils';
import { TITULO_DESEMPENHO, kpisParaTela, subtituloDoDesempenho } from './recentes';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 179, plano 08 — a faixa de Desempenho (VG-15, VG-18, EXMP-01, EXMP-03).
//
// Protótipo: `new_layout_publicador/Visao Geral.dc.html`:286-:310 (os `kpis` da
// aritmética em `:554`). ⚠️ O `<action>` do plano cita `:284-:301`.
//
// ⚠️ TRÊS divergências declaradas, todas com restrição concreta:
//
// 1. A FAIXA NÃO É LINK. O protótipo é um `<a href="Desempenho.dc.html">`
//    (`:286`) e tem uma coluna final "Ver desempenho →" (`:308`). A tela
//    Desempenho é a **Fase 182** — não existe. `desempenho.url` vem `null` do
//    servidor de propósito (179-03, mutação M11 reprova quem o preencher), e um
//    destino inventado é pior que destino nenhum (EXMP-03). ✅ CONFIRMADO PELO
//    USUÁRIO em 12/10/2026: *"faixa com os 3 KPIs reais, sem link"*. Vira link
//    na Fase 182.
//    ⚠️ E por isso o `style-hover` do protótipo (`border-color:#3F3F46`) também
//    NÃO nasce: hover de borda num elemento que não é clicável promete clique.
//
// 2. SÃO TRÊS KPIs, não quatro. O protótipo tem `Publicações criadas`,
//    **`Vendas`**, `Publicações com venda` e `Criativos com IA` (`:554`).
//    "Vendas" (e "Visitas" e "Conversão") NÃO TEM FONTE: `sold_quantity` do
//    acervo é ACUMULADO DA VIDA DO ANÚNCIO (não do período), `visitas` é NULL
//    em 1.374.527 linhas e não existe tabela de pedidos. Número sem fonte é
//    número inventado (VG-18, EXMP-01).
//
// 3. A GRADE É ESCRITA POR EXTENSO, por um mapa fechado. O protótipo declara
//    `auto repeat(4,minmax(0,1fr)) auto`; sem a coluna final e com 3 KPIs, a
//    nossa é `auto` + três trilhas. ⚠️ O mapa é fechado porque classe montada
//    por concatenação de string NÃO É VARRIDA pelo Tailwind e não gera CSS
//    (avisado no 179-04, medido no 179-06).
//
// ⚠️ A faixa NÃO leva pilha `SeloExemplo`: tudo nela é real (EXMP-02 — bloco
// com dado real não leva pilha de exemplo; VG-20 — bloco real não regride a
// exemplo).
//
// ⚠️ O ÍCONE É DESENHADO À MÃO, com os dois `path` do protótipo (`:289`) em
// `viewBox="0 0 16 16"` e `stroke-width:1.7`. O `<action>` prescreve
// `<BarChart3 size={16} strokeWidth={2.55} />`, e a geometria do Lucide é OUTRA
// (medida em `node_modules/lucide-react/dist/esm/icons/chart-column.mjs`, de
// onde `BarChart3` é apelido): o eixo do Lucide é um "L" com canto arredondado
// (`M3 3v16a2 2 0 0 0 2 2h16` — eixo ESQUERDO mais a base) e as barras têm
// outras alturas. O protótipo desenha **só a base** (`M2.5 13.5h11`) e três
// barras de 3 / 6,5 / 4,5. Mesma saída do D-1 do 179-07.
// ═══════════════════════════════════════════════════════════════════════════

/** As barras sobre a base, byte a byte do protótipo (`:289`). */
function IconeDeDesempenho() {
    return (
        <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" className="fill-none stroke-pub-accent">
            <path d="M2.5 13.5h11" strokeWidth={1.7} strokeLinecap="round" strokeLinejoin="round" />
            <path d="M4.5 11V8M8 11V4.5M11.5 11V6.5" strokeWidth={1.7} strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

/**
 * A grade por número de KPIs — `auto` para o bloco do título, uma trilha
 * `minmax(0,1fr)` por KPI. Mapa FECHADO: `grid-cols-[auto_${'minmax…'.repeat(n)}]`
 * seria string montada em tempo de execução, invisível para o `content` do
 * Tailwind, e a faixa sairia **sem grade nenhuma** com todos os testes de
 * render passando.
 *
 * Acima de 4 a última entrada é reusada: as trilhas que faltam viram colunas
 * implícitas (`auto`), que encolhem mas aparecem — o contrato do 179-03 manda
 * exatamente 3, e perder um KPI em silêncio seria pior.
 */
const GRADE_POR_KPI = {
    1: 'grid-cols-[auto_minmax(0,1fr)]',
    2: 'grid-cols-[auto_minmax(0,1fr)_minmax(0,1fr)]',
    3: 'grid-cols-[auto_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]',
    4: 'grid-cols-[auto_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]',
};

/**
 * A faixa horizontal com os mini-KPIs do período.
 *
 * @param {object} props
 * @param {unknown} props.desempenho  o retorno de
 *   `CockpitDaContaService::desempenho()`: `{kpis: [...], dias: 30, url: null}`.
 */
export default function FaixaDeDesempenho({ desempenho }) {
    const kpis = kpisParaTela(desempenho);
    const dias = (desempenho !== null && typeof desempenho === 'object' && !Array.isArray(desempenho))
        ? desempenho.dias
        : null;

    // Sem nenhum KPI a faixa não nasce: um cabeçalho "Desempenho · Últimos 30
    // dias" sobre nada afirmaria que a medição existe e deu vazio (VG-18, e o
    // mesmo critério do D-10 do 179-07 para a barra sem faixa medida).
    if (kpis.length === 0) return null;

    const grade = GRADE_POR_KPI[kpis.length] ?? GRADE_POR_KPI[4];

    return (
        <section
            className={cn(
                'grid items-center whitespace-nowrap rounded-pub-card border border-pub-line bg-pub-surface text-pub-txt',
                grade,
            )}
        >
            {/* ─── Bloco do título (:287) ─── */}
            <div className="flex items-center gap-[12px] px-[22px] py-[16px]">
                <span className="grid h-[36px] w-[36px] place-items-center rounded-pub-sub bg-pub-accent/[0.12]">
                    <IconeDeDesempenho />
                </span>
                {/* ⚠️ Esta coluna (`:291`, `gap:2px`) não está na tabela FID-01
                    do plano; está no protótipo.
                    ⚠️ O protótipo escreve o título num `<span>` aqui, mas usa
                    `<h2 style="margin:0">` nos outros quatro cards desta mesma
                    página (`:43`, `:168`, `:237`, `:260`). Com `m-0` e o corpo
                    declarado o pixel é o mesmo, e a `<section>` ganha nome
                    acessível — um `<span>` deixaria a região sem rótulo. */}
                <span className="flex flex-col gap-[2px]">
                    <h2 className="m-0 text-[14px] font-semibold">{TITULO_DESEMPENHO}</h2>
                    <span className="text-[12px] text-pub-txt-3">{subtituloDoDesempenho(dias)}</span>
                </span>
            </div>

            {/* ─── Os KPIs (:294) ───
                ⚠️ Toda flag de render (`subiu`, `caiu`, `delta`, `title`) nasce
                em `kpisParaTela`, função PURA: `kpi.delta > 0` dentro deste
                `.map()` leria variável de escopo do componente, que é a forma
                que o Rollup elimina neste projeto (REND-04). */}
            {kpis.map((kpi, i) => (
                <div
                    key={`${kpi.chave}-${i}`}
                    title={kpi.title ?? undefined}
                    className="flex min-w-0 items-center justify-between gap-[12px] border-l border-pub-line-subtle px-[20px] py-[12px]"
                >
                    {/* ⚠️ Esta coluna (`:295`, `gap:4px; min-width:0`) também
                        não está na tabela do plano — e é o `min-width:0` dela
                        que faz o `truncate` do rótulo funcionar. */}
                    <span className="flex min-w-0 flex-col gap-[4px]">
                        <span className="truncate text-[12px] text-pub-txt-3">{kpi.rotulo}</span>
                        {/* ⚠️ E esta linha (`:297`, `items-baseline; gap:6px`)
                            tampouco: sem o `baseline` o "↑ +16" de 11,5px
                            centraliza no meio do valor de 20px. */}
                        <span className="flex items-baseline gap-[6px]">
                            <span className="text-[20px] font-semibold leading-none tracking-[-0.02em] tabular-nums">
                                {kpi.valor}
                            </span>
                            {kpi.delta !== null && (
                                <span
                                    className={cn(
                                        'text-[11.5px] font-medium',
                                        kpi.subiu ? 'text-pub-ok-txt' : 'text-pub-danger-txt',
                                    )}
                                >
                                    {kpi.delta}
                                </span>
                            )}
                        </span>
                    </span>

                    {/* ─── Sparkline (:303-:305) ───
                        ⚠️ `serie` nula ⇒ NENHUM `<svg>`, e isso é o resultado
                        CERTO: `publicacoes_com_venda` chega com `serie: null`
                        por desenho do servidor (179-03). Um trilho vazio de
                        64×26 afirmaria uma curva que ninguém mediu. */}
                    {kpi.linha !== null && (
                        <svg
                            width={64}
                            height={26}
                            viewBox="0 0 100 34"
                            preserveAspectRatio="none"
                            aria-hidden="true"
                            className="flex-none overflow-visible"
                        >
                            <path d={kpi.area} className="fill-pub-accent/[0.10]" />
                            <path
                                d={kpi.linha}
                                strokeLinejoin="round"
                                strokeLinecap="round"
                                className="fill-none stroke-pub-accent stroke-2"
                            />
                        </svg>
                    )}
                </div>
            ))}
        </section>
    );
}
