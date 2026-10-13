import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, ChevronDown } from 'lucide-react';
import { cn } from '@/lib/utils';
import TileDoProduto from '../identidade/TileDoProduto';
import {
    ABRIR,
    ADIAR,
    EXPANDIR,
    RECOLHER,
    RECOMENDADO,
    SUBTITULO,
    TITULO,
    VAZIO_TITULO,
    abasComContagem,
    acoesParaTela,
    escondidasDaLista,
    normalizarAcoes,
    textoDaVolta,
} from './acoes';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 179, plano 07 — "O que fazer agora" (VG-09, VG-10, VG-11).
//
// Protótipo: `new_layout_publicador/Visao Geral.dc.html` — linhas MEDIDAS no
// arquivo (as do `<action>` do plano estão deslocadas; ver a SUMMARY):
//   · card                   → :165  (cabeçalho :166, coluna :167, H2 :168, sub :169)
//   · trilho do segmented    → :170  (opção :172, dot + contagem :173)
//   · linha da ação          → :176  (grid :177, tile do ícone :178)
//   · ícones de severidade   → :179 (crítico) · :180 (atenção) · :181 (oportunidade)
//   · coluna de texto        → :182  (linha de título :183, título :184)
//   · badges                 → :185 (severidade) · :186 (RECOMENDADO AGORA)
//   · descrição              → :188
//   · pilha de afetados      → :189  (tile :191)
//   · CTA                    → :193
//   · chevron                → :194  (svg :195)
//   · sub-lista              → :198  (item :200, iniciais :202, nome :203,
//                                     meta :204, motivo :206, "Abrir →" :207)
//   · "Adiar até amanhã"     → :209 (contêiner) / :210 (botão)
//   · vazio                  → :216  (tile :217, check :218, textos :219)
//   · a aritmética           → :423 (`SEV`) · :516-:526
//
// ⚠️ TODA classe do Tailwind deste bloco mora NESTE arquivo, e não em
// `acoes.js`: o `content` do `tailwind.config.js:12` varre
// `./resources/js/**/*.jsx` e **não** `*.js`. Classe escrita num `.js` não é
// varrida, não gera CSS e não existe para a tela — com os testes de render
// passando (achado medido no plano 179-06).
//
// ⚠️ OS TRÊS ÍCONES DE SEVERIDADE SÃO DESENHADOS À MÃO, com o `path` do
// protótipo e `viewBox="0 0 16 16"`. O `<action>` do plano prescreve
// `<AlertTriangle className="fill-pub-danger text-pub-page" />`, e o Lucide
// desenha o triângulo em CONTORNO: pedir `fill` + `currentColor` escuro dá um
// triângulo vermelho com borda escura de 2,7 (o protótipo não tem borda) — numa
// peça de 17px isso é a metade do ícone. O plano previu esta saída
// ("se o Lucide não der o preenchido … desenhe o `<svg>` à mão"). O mesmo vale
// para o círculo de `atenção` (r 6.5 cheio, sem contorno).
//
// ⚠️ REND-04 (`feedback_rollup_map_scope_bug.md`): este arquivo tem TRÊS
// `.map()` (abas, ações, sub-itens). Nenhuma flag derivada nasce no corpo do
// componente para ser lida dentro de um callback — tudo o que o callback usa
// vem do próprio parâmetro, dos mapas de módulo abaixo, das funções de
// `acoes.js` e dos `setState`. O Rollup já eliminou uma declaração de escopo de
// componente neste projeto e deixou o uso, com `ReferenceError` em produção e
// os testes passando.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Tinta do tile do ícone e do badge de severidade (`:423`).
 *
 * ⚠️ São TRÊS números: **0.13 / 0.13 / 0.12**. O protótipo escreve
 * `rgba(239,68,68,0.13)`, `rgba(245,158,11,0.13)` e `rgba(250,204,21,0.12)`.
 * Não uniformizar — são medidas distintas.
 */
const TINTA_DA_SEVERIDADE = {
    critico: 'bg-pub-danger/[0.13]',
    atencao: 'bg-pub-warn/[0.13]',
    oportunidade: 'bg-pub-accent/[0.12]',
};

/** Cor do texto do badge e do motivo do sub-item (`s[1]`, `:423`). */
const TEXTO_DA_SEVERIDADE = {
    critico: 'text-pub-danger-txt',
    atencao: 'text-pub-warn-txt',
    oportunidade: 'text-pub-accent',
};

/**
 * Cor do dot da opção do segmented (`:525`).
 *
 * ⚠️ MEDIDO: `dot: this.SEV[k][1]` — o dot usa a cor do TEXTO (`#F87171`,
 * `#FBBF24`, `#FACC15`), **não** a tinta sólida (`#EF4444`, `#F59E0B`) que o
 * `<action>` do plano prescreveu. Aqui o protótipo ganha. É o oposto da pílula
 * de status da Saúde (`:250`), onde o dot é a sólida e o texto é a clara — duas
 * medidas diferentes, que não se uniformizam.
 */
const DOT_DA_SEVERIDADE = {
    critico: 'bg-pub-danger-txt',
    atencao: 'bg-pub-warn-txt',
    oportunidade: 'bg-pub-accent',
};

/**
 * A URL do POST de adiar. Função de MÓDULO (não derivada no corpo do
 * componente) para poder ser chamada dentro do `.map()` sem violar REND-04.
 * O `try/catch` existe porque o Ziggy lança quando o nome não está no manifest.
 */
function urlDeAdiar(conta) {
    try {
        return typeof route === 'function'
            ? route('mlb.anuncios.publicador.acoes.adiar', { conta })
            : null;
    } catch {
        return null;
    }
}

/**
 * Os três ícones de severidade, com o `path` do protótipo (`:179-:181`).
 * `viewBox="0 0 16 16"` e `stroke-width: 1.8` são os do arquivo — não há
 * conversão porque não há Lucide aqui.
 */
function IconeDaSeveridade({ severidade }) {
    if (severidade === 'critico') {
        return (
            <svg width="17" height="17" viewBox="0 0 16 16" aria-hidden="true">
                <path d="M8 1.5L15 14H1z" className="fill-pub-danger" />
                <path
                    d="M8 6v3.5M8 11.6v.1"
                    strokeWidth={1.8}
                    strokeLinecap="round"
                    className="fill-none stroke-pub-page"
                />
            </svg>
        );
    }

    if (severidade === 'oportunidade') {
        return (
            <svg
                width="17"
                height="17"
                viewBox="0 0 16 16"
                strokeWidth={1.8}
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
                className="fill-none stroke-pub-accent"
            >
                <path d="M2.5 11.5l4-4 2.5 2.5 4.5-5.5" />
                <path d="M10 4.5h3.5V8" />
            </svg>
        );
    }

    return (
        <svg width="17" height="17" viewBox="0 0 16 16" aria-hidden="true">
            <circle cx="8" cy="8" r="6.5" className="fill-pub-warn" />
            <path
                d="M8 4.8v3.6M8 10.9v.1"
                strokeWidth={1.8}
                strokeLinecap="round"
                className="fill-none stroke-pub-page"
            />
        </svg>
    );
}

/**
 * "O que fazer agora": o segmented por severidade, a lista de ações com tile de
 * ícone, badges, pilha de afetados e CTA, a sub-lista que o chevron abre e o
 * "Adiar até amanhã" que posta no servidor.
 *
 * @param {object} props
 * @param {unknown} props.acoes              o payload de `AcoesDaContaService::acoes()`
 * @param {string|null} [props.contaChave]   escopo da conta (parâmetro da rota de adiar)
 * @param {object} [props.expandidasIniciais]  quais chaves nascem com a gaveta aberta.
 *   ⚠️ O padrão é `{}` — TUDO fechado, como o protótipo (`:390`, `open: {}`).
 *   A prop existe porque "aberta" é a única coisa deste bloco que só um CLIQUE
 *   produz: sem ela nenhum gate alcançaria uma medida da sub-lista, e a
 *   asserção negativa "ação sem item não abre gaveta" ficaria **vazia por
 *   construção** (tudo fechado reprova nada). É a lacuna que a mutação M22 do
 *   plano 179-06 expôs. Padrão `defaultOpen` do React; o 179-09 não a passa.
 * @param {string} [props.filtroInicial]  severidade pré-selecionada; o padrão é
 *   `'todas'` (`:390`, `sev: 'all'`). Mesmo motivo: o filtro só muda por clique.
 */
export default function OQueFazerAgora({
    acoes,
    contaChave = null,
    expandidasIniciais = {},
    filtroInicial = 'todas',
}) {
    const dados = normalizarAcoes(acoes);
    const [filtro, setFiltro] = useState(filtroInicial);
    const [expandidas, setExpandidas] = useState(expandidasIniciais);
    // O esconder OTIMISTA: a ação sai da tela na hora, e o servidor (que é a
    // fonte da verdade) devolve as props sem ela no `preserveState: false`.
    const [escondidas, setEscondidas] = useState({});

    const visiveis = acoesParaTela(dados, filtro, expandidas, escondidas, contaChave);
    const fora = escondidasDaLista(dados, escondidas);

    return (
        <section className="overflow-hidden rounded-pub-card border border-pub-line bg-pub-surface">
            {/* ─── Cabeçalho: título + subtítulo + segmented (:166) ─── */}
            <div className="flex items-center justify-between gap-[16px] px-[20px] pb-[14px] pt-[18px]">
                {/* ⚠️ Esta coluna (`:167`, `gap:4px`) NÃO está na tabela FID-01 do
                    plano; está no protótipo. Sem ela o título e o subtítulo
                    herdariam o `gap:16px` do cabeçalho. */}
                <div className="flex flex-col gap-[4px]">
                    <h2 className="m-0 text-[17px] font-semibold tracking-[-0.01em]">{TITULO}</h2>
                    <span className="text-[13px] text-pub-txt-3">
                        {SUBTITULO(dados.acoes.length - fora.length)}
                    </span>
                </div>

                {/* ─── Trilho do segmented (:170) ─── */}
                <div className="flex items-center gap-[2px] rounded-pub-sub border border-pub-line-subtle bg-pub-hover-2 p-[3px]">
                    {abasComContagem(dados.contagens, fora, filtro).map((aba) => (
                        <button
                            key={aba.chave}
                            type="button"
                            onClick={() => setFiltro(aba.chave)}
                            className={cn(
                                'flex h-[28px] cursor-pointer items-center gap-[6px] whitespace-nowrap rounded-pub-control-sm border-none px-[10px] text-[12.5px] font-medium',
                                aba.ativa
                                    ? 'bg-pub-seg text-pub-txt shadow-pub-seg-ativo'
                                    : 'bg-transparent text-pub-txt-2',
                            )}
                        >
                            {aba.tem_dot && (
                                <span
                                    aria-hidden="true"
                                    className={cn('h-[6px] w-[6px] rounded-full', DOT_DA_SEVERIDADE[aba.chave])}
                                />
                            )}
                            {aba.rotulo}
                            <span className="tabular-nums text-pub-txt-4">{aba.total}</span>
                        </button>
                    ))}
                </div>
            </div>

            {/* ─── As ações (:176) ─── */}
            {visiveis.map((acao, indice) => (
                <div
                    key={acao.chave ?? `acao-${indice}`}
                    className={cn(
                        'border-t border-pub-line-subtle',
                        acao.recomendada ? 'bg-pub-raised-0' : 'bg-pub-surface',
                    )}
                >
                    {/* O cabeçalho inteiro é o toggle (`:177`, `a.onToggle`). O
                        chevron é um <button> SEM handler próprio: o clique dele
                        (inclusive o gerado por Enter/Espaço) sobe para cá, então
                        o teclado alcança a gaveta sem alternar duas vezes. */}
                    <div
                        onClick={() =>
                            setExpandidas((atual) => ({ ...atual, [acao.chave]: atual[acao.chave] !== true }))
                        }
                        className="grid cursor-pointer grid-cols-[38px_minmax(0,1fr)_auto_auto_28px] items-center gap-[14px] px-[20px] py-[14px] hover:bg-pub-hover"
                    >
                        <span
                            className={cn(
                                'grid h-[38px] w-[38px] place-items-center rounded-pub-sub',
                                TINTA_DA_SEVERIDADE[acao.severidade],
                            )}
                        >
                            <IconeDaSeveridade severidade={acao.severidade} />
                        </span>

                        {/* ⚠️ Esta coluna (`:182`, `gap:4px; min-width:0`) NÃO está na
                            tabela FID-01; está no protótipo, e é o `min-width:0`
                            dela que faz o `truncate` da descrição funcionar. */}
                        <span className="flex min-w-0 flex-col gap-[4px]">
                            <span className="flex flex-wrap items-center gap-[8px]">
                                {/* ⚠️ O protótipo concatena o número DENTRO do título
                                    ("7 publicações pausadas"); o servidor manda
                                    `titulo` e `numero` em campos separados (179-02),
                                    então o número é um elemento irmão no MESMO corpo
                                    de 14px/600 — com `tabular-nums`, que todo número
                                    desta camada tem. */}
                                {acao.numero !== null && (
                                    <span className="text-[14px] font-semibold tabular-nums">{acao.numero}</span>
                                )}
                                {acao.titulo !== null && (
                                    <span className="text-[14px] font-semibold">{acao.titulo}</span>
                                )}
                                <span
                                    className={cn(
                                        'rounded-pub-badge px-[6px] py-[2px] text-[10.5px] font-bold tracking-[0.06em]',
                                        TINTA_DA_SEVERIDADE[acao.severidade],
                                        TEXTO_DA_SEVERIDADE[acao.severidade],
                                    )}
                                >
                                    {acao.rotulo_severidade}
                                </span>
                                {acao.recomendada && (
                                    <span className="rounded-pub-badge border border-pub-line-off px-[6px] py-[2px] text-[10.5px] font-semibold tracking-[0.06em] text-pub-fase1">
                                        {RECOMENDADO}
                                    </span>
                                )}
                            </span>
                            {acao.descricao !== null && (
                                <span className="truncate text-[12.5px] text-pub-txt-2">{acao.descricao}</span>
                            )}
                        </span>

                        {/* Pilha de afetados (`:189`), até 3 (`:191`) */}
                        <span className="flex items-center">
                            {acao.tiles.map((item, posicao) => (
                                <TileDoProduto
                                    key={`${acao.chave}-tile-${posicao}`}
                                    id={item.nome}
                                    nome={item.nome}
                                    tamanho={26}
                                    raio={7}
                                    letra="text-[8.5px]"
                                    className="-ml-[7px] border-2 border-pub-surface font-bold"
                                />
                            ))}
                        </span>

                        {/* CTA (`:193`). ⚠️ `stopPropagation` é do protótipo (`:519`,
                            `onCta: (e) => e.stopPropagation()`): sem ele o clique no
                            CTA também abriria a gaveta. ⚠️ Sem `href` o CTA NÃO
                            nasce — botão sem destino é proibido (EXMP-01). */}
                        {acao.href !== null ? (
                            <a
                                href={acao.href}
                                onClick={(evento) => evento.stopPropagation()}
                                className={cn(
                                    'flex h-[32px] items-center whitespace-nowrap rounded-[8px] border px-[14px] text-[12.5px] font-semibold',
                                    acao.recomendada
                                        ? 'border-pub-accent bg-pub-accent text-pub-page'
                                        : 'border-pub-line-strong bg-pub-hover-2 text-pub-txt',
                                )}
                            >
                                {acao.cta}
                            </a>
                        ) : (
                            <span />
                        )}

                        {/* Chevron (`:194`/`:195`). `disabled` quando não há sub-item:
                            `open && a.items.length > 0` (`:520`) — chevron não abre
                            gaveta vazia, e um botão que não faz nada é mudo. */}
                        <button
                            type="button"
                            disabled={!acao.tem_itens}
                            aria-expanded={acao.aberta}
                            title={acao.aberta ? RECOLHER : EXPANDIR}
                            className="grid h-[28px] w-[28px] cursor-pointer place-items-center border-none bg-transparent text-pub-txt-4"
                        >
                            <ChevronDown
                                size={14}
                                strokeWidth={2.7}
                                className={cn('transition-transform duration-200', acao.aberta && 'rotate-180')}
                            />
                        </button>
                    </div>

                    {/* ─── Sub-lista (:198) ─── */}
                    {acao.aberta && (
                        <div className="flex flex-col gap-[2px] pb-[14px] pl-[72px] pr-[20px] pt-0">
                            {acao.itens.map((item, posicao) => (
                                <div
                                    key={`${acao.chave}-item-${posicao}`}
                                    className="grid h-[40px] grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-[14px] rounded-[8px] border border-pub-line-subtle-2 bg-pub-sunken px-[12px]"
                                >
                                    <span className="flex min-w-0 items-center gap-[10px]">
                                        <TileDoProduto
                                            id={item.nome}
                                            nome={item.nome}
                                            tamanho={22}
                                            raio={6}
                                            letra="text-[8.5px]"
                                            className="font-bold"
                                        />
                                        {item.nome !== null && (
                                            <span className="truncate text-[13px] font-medium">{item.nome}</span>
                                        )}
                                        {item.meta !== null && (
                                            <span className="whitespace-nowrap text-[12px] text-pub-txt-3">
                                                {item.meta}
                                            </span>
                                        )}
                                    </span>
                                    <span
                                        className={cn(
                                            'whitespace-nowrap text-[12px]',
                                            TEXTO_DA_SEVERIDADE[acao.severidade],
                                        )}
                                    >
                                        {item.motivo}
                                    </span>
                                    {/* ⚠️ O sub-item NÃO tem destino próprio no contrato
                                        (179-02: `{nome, meta, motivo}`), então "Abrir →"
                                        aponta para o destino da AÇÃO — e sem destino ele
                                        não nasce, em vez de virar `href="#"` como no
                                        protótipo (`:207`). */}
                                    {acao.href !== null ? (
                                        <a
                                            href={acao.href}
                                            className="whitespace-nowrap text-[12px] font-medium text-pub-txt-2"
                                        >
                                            {ABRIR}
                                        </a>
                                    ) : (
                                        <span />
                                    )}
                                </div>
                            ))}

                            {/* "Adiar até amanhã" (`:209`/`:210`). ⚠️ Sem `contaChave`
                                não há rota, e o botão não nasce (EXMP-03). */}
                            {acao.pode_adiar && (
                                <div className="flex justify-end pt-[6px]">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setEscondidas((atual) => ({ ...atual, [acao.chave]: true }));
                                            router.post(
                                                urlDeAdiar(acao.conta),
                                                { chave: acao.chave },
                                                // `preserveScroll` porque a ação pode estar
                                                // no fim de uma lista longa;
                                                // `preserveState: false` para as props
                                                // voltarem do servidor, que é a fonte da
                                                // verdade — o esconder acima é conforto.
                                                { preserveScroll: true, preserveState: false },
                                            );
                                        }}
                                        className="h-[26px] cursor-pointer whitespace-nowrap rounded-pub-chip border-none bg-transparent px-[10px] text-[12px] text-pub-txt-4 hover:bg-pub-hover-3 hover:text-pub-fase1"
                                    >
                                        {ADIAR}
                                    </button>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            ))}

            {/* ─── Vazio (:216). `noActions: shown.length === 0` (:603) ─── */}
            {visiveis.length === 0 && (
                <div className="flex items-center gap-[14px] border-t border-pub-line-subtle px-[20px] py-[22px]">
                    <span className="grid h-[38px] w-[38px] place-items-center rounded-pub-sub bg-pub-ok/[0.12]">
                        <Check size={16} strokeWidth={3} className="text-pub-ok-txt" />
                    </span>
                    <span className="flex flex-col gap-[2px]">
                        <span className="text-[14px] font-semibold">{VAZIO_TITULO}</span>
                        <span className="text-[12.5px] text-pub-txt-3">{textoDaVolta(dados.volta_em_iso)}</span>
                    </span>
                </div>
            )}
        </section>
    );
}
