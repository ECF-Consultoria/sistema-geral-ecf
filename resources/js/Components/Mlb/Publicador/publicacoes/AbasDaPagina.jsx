import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 03 — AS DUAS ABAS do cabeçalho de Publicações (seção A1).
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Publicacoes v2.dc.html`.
// A outra cópia que existe no disco (`new_layout_publicador/Publicacoes
// v2.dc.html`, 656 linhas contra 659) é a versão ANTIGA — a que está NO AR
// desde a Fase 180 — e é a ERRADA para esta seção: ela tem o SUBTÍTULO e não
// tem aba nenhuma. Medido por `diff` no plano 187-01: as duas diferem em 4
// hunks, dois deles aqui na A1.
//
// Linhas MEDIDAS POR SCRIPT (varredura de propriedades do `187-BASELINES.md`
// §5 rodada sobre a faixa `:31`-`:37`), 15/10/2026, sha256 `56e6c194b896151c`:
//   · trilho das abas → :34 (`display:flex;gap:20px;margin-bottom:-1px`)
//   · aba ATIVA       → :35 (`<span>`; `white-space:nowrap;display:flex;`
//                            `align-items:center;gap:8px;height:40px;`
//                            `border-bottom:2px solid #FACC15;font-size:14px;`
//                            `font-weight:600`)
//   · contador simples→ :35 (`font-size:12px;color:#71717A`)
//   · aba INATIVA     → :36 (`<a>`; as mesmas da base +
//                            `border-bottom:2px solid transparent;`
//                            `font-weight:500;color:#8A8A90`)
//   · pill do contador→ :36 (`min-width:22px;height:20px;padding:0 7px;`
//                            `border-radius:999px;background:#FACC15;`
//                            `color:#0A0A0A;display:grid;place-items:center;`
//                            `font-size:11.5px;font-weight:700`)
//
// ⚠️ A aba ATIVA **não declara `color`**: ela HERDA o texto da página. Medido —
// as três cores da A1 são a da aba inativa (`#8A8A90`), a do contador simples
// (`#71717A`) e a do texto da pill (`#0A0A0A`). Pôr uma cor na ativa seria
// excesso, e a tabela de fidelidade deste projeto erra por OMISSÃO **e por
// EXCESSO**.
//
// ⚠️⚠️ `cursor` aparece **ZERO vezes** na seção A1 (medido: `cursor:pointer
// ocorrencias 0`). A aba ativa é `<span>` e a inativa é `<a>`, que já tem o
// cursor por padrão do navegador. **Nada de `cursor-pointer` aqui** —
// "deixei igual aos outros botões" é justificativa VETADA (FID-02). Há gate de
// ausência, com controle positivo no `CabecalhoDePublicacoes.jsx` vizinho (que
// TEM o cursor, porque o protótipo o declara em `:39`).
//
// ⚠️ O `margin-bottom:-1px` do trilho NÃO é detalhe: é ele que faz a borda
// amarela de 2px da aba ativa COBRIR a linha de 1px do `<header>` (ABA-01).
// Sem ele a tela ganha duas linhas empilhadas.
//
// ⚠️ A ABA ATIVA NÃO NAVEGA PARA SI MESMA — ela é `<span>`, como no protótipo.
// Link que recarrega a própria página é destino falso (D4).
//
// ⚠️ A NAVEGAÇÃO É POR `<Link>` do `@inertiajs/react`, nunca por `router.visit`:
// o D-11 proíbe `router.visit(` na fonte de `MeusAnuncios.jsx` (ele aceita
// `method:'post'`, é porta lateral de escrita), e o `<Link>` renderiza um
// `<a href>` — que é exatamente o que o protótipo tem.
//
// ⚠️ TODA classe mora NESTE `.jsx`: o `content` do `tailwind.config.js` varre
// `./resources/js/**/*.jsx` e **NÃO** `*.js`. Mapa de classe num módulo `.js`
// não gera CSS e NÃO EXISTE PARA A TELA, com todo teste de render passando.
//
// ⚠️ As flags booleanas nascem DENTRO do callback do `.map()`: o Rollup elimina
// variável de escopo do componente usada dentro de um `.map()` e o
// `ReferenceError` só aparece no bundle de produção (já aconteceu neste
// projeto; é a mesma razão do `separador` de `AbasDeStatus.jsx`).
//
// ⚠️ NUNCA `String(valor)` nem `JSON.stringify(valor)` sobre prop: o
// `String({toString: null})` LANÇA e derruba a página inteira (a TELA PRETA de
// 07/10/2026, que voltou QUATRO vezes na Fase 180), e qualquer `obj[chave]`
// com a chave vinda do servidor LANÇA do mesmo jeito. Por isso a comparação de
// `ativa` é por igualdade estrita contra literal — ela nunca converte nada.
// ═══════════════════════════════════════════════════════════════════════════

/** Os literais de tela, byte a byte como o protótipo de autoridade os escreve. */
export const PUBLICADAS = 'Publicadas no Mercado Livre';
export const AGUARDANDO = 'Aguardando aprovação';

/** As duas chaves legais de `ativa`. */
export const CHAVE_PUBLICADAS = 'publicadas';
export const CHAVE_APROVACAO = 'aprovacao';

// As classes, escritas uma vez e literalmente — é daqui que o Tailwind as lê.
const CLASSES_TRILHO = 'flex gap-[20px] mb-[-1px]';
const CLASSES_ABA = 'flex h-[40px] items-center gap-[8px] whitespace-nowrap text-[14px]';
const CLASSES_ATIVA = 'border-b-[2px] border-pub-accent font-semibold';
const CLASSES_INATIVA = 'border-b-[2px] border-transparent font-medium text-pub-txt-3';
const CLASSES_CONTADOR = 'text-[12px] text-pub-txt-4';
const CLASSES_PILL = 'grid h-[20px] min-w-[22px] place-items-center rounded-full bg-pub-accent px-[7px] text-[11.5px] font-bold text-pub-page';

/**
 * O contador MEDIDO, ou `null` quando não há número nenhum para afirmar.
 *
 * ⚠️ `0` é um FATO e passa: a fila nasce vazia (nada cria oferta antes da Fase
 * 194) e esconder a aba ou o número foi **recusado** pelo usuário. O que não
 * passa é inventar: prop ausente, nula, objeto, array, string, `NaN`,
 * `Infinity` e fracionado devolvem `null`, e aí NENHUMA pílula é desenhada —
 * em vez de um "0" que ninguém mediu (EXMP-01).
 *
 * @param {unknown} valor o que o servidor mandou
 * @returns {number|null}
 */
export function contadorMedido(valor) {
    return typeof valor === 'number' && Number.isInteger(valor) && valor >= 0 ? valor : null;
}

/**
 * O destino de uma aba, ou `null` quando não há destino conhecido.
 *
 * ⚠️ Sem destino a aba deixa de ser link — ela NÃO ganha `href="#"`. `#` é
 * destino FALSO (D4): ele parece clicável, recarrega a própria página e é
 * exatamente o que esta fase existe para não entregar.
 *
 * @param {unknown} valor
 * @returns {string|null}
 */
function destinoSeguro(valor) {
    return typeof valor === 'string' && valor !== '' && valor !== '#' ? valor : null;
}

/**
 * As duas abas da seção A1.
 *
 * O componente é de DUAS páginas: a lista de Publicações (`ativa="publicadas"`,
 * plano 187-03) e a fila "Aguardando aprovação" (`ativa="aprovacao"`, plano
 * 187-04). Por isso qual é a ativa vem por PROP e não está cravado aqui.
 *
 * ⚠️ Os dois contadores vêm do SERVIDOR (ABA-05) — nada é somado no cliente.
 * Somar aqui exigiria mandar a fila INTEIRA para uma tela que não a mostra, e o
 * número passaria a depender do que a página carregou, não do que existe.
 *
 * @param {object} props
 * @param {unknown} [props.ativa] `'publicadas'` ou `'aprovacao'`
 * @param {unknown} [props.publicadas] o contador da aba das publicadas (`subTotais.publicados`)
 * @param {unknown} [props.aguardando] o contador da fila (`aguardando_aprovacao`)
 * @param {unknown} [props.hrefAprovacao] o destino da fila (`route('mlb.anuncios.meus.aprovacao', …)`)
 * @param {unknown} [props.hrefLista] o destino da lista (`route('mlb.anuncios.meus', …)`)
 */
export default function AbasDaPagina({
    ativa = CHAVE_PUBLICADAS,
    publicadas = null,
    aguardando = null,
    hrefAprovacao = null,
    hrefLista = null,
}) {
    // ⚠️ Igualdade ESTRITA contra literal: nunca converte, nunca indexa objeto
    // e por isso nunca lança — mesmo com `ativa = {toString: null}`. Chave
    // desconhecida não marca aba nenhuma (é o que `AbasDeStatus.jsx` já faz):
    // afirmar uma aba ativa que o servidor não disse seria invenção.
    const chaveAtiva = ativa === CHAVE_APROVACAO ? CHAVE_APROVACAO : (ativa === CHAVE_PUBLICADAS ? CHAVE_PUBLICADAS : null);

    const abas = [
        {
            chave: CHAVE_PUBLICADAS,
            rotulo: PUBLICADAS,
            contador: contadorMedido(publicadas),
            destino: destinoSeguro(hrefLista),
            // A pílula amarela é da fila, não do estado: ela é o sinal de
            // atenção daquela espera, e o protótipo a pinta só lá (`:36`).
            comPill: false,
        },
        {
            chave: CHAVE_APROVACAO,
            rotulo: AGUARDANDO,
            contador: contadorMedido(aguardando),
            destino: destinoSeguro(hrefAprovacao),
            comPill: true,
        },
    ];

    return (
        <div className={CLASSES_TRILHO}>
            {abas.map((aba) => {
                // ⚠️ TODAS as flags nascem AQUI DENTRO — o Rollup elimina
                // variável de escopo do componente usada dentro do `.map()`.
                const ehAtiva = aba.chave === chaveAtiva;
                const classes = cn(CLASSES_ABA, ehAtiva ? CLASSES_ATIVA : CLASSES_INATIVA);
                const temContador = aba.contador !== null;
                const navega = !ehAtiva && aba.destino !== null;

                const miolo = (
                    <>
                        {aba.rotulo}
                        {temContador && (
                            <span className={aba.comPill ? CLASSES_PILL : CLASSES_CONTADOR}>{aba.contador}</span>
                        )}
                    </>
                );

                if (navega) {
                    return (
                        <Link key={aba.chave} href={aba.destino} className={classes}>
                            {miolo}
                        </Link>
                    );
                }

                return (
                    <span
                        key={aba.chave}
                        className={classes}
                        {...(ehAtiva ? { 'aria-current': 'page' } : {})}
                    >
                        {miolo}
                    </span>
                );
            })}
        </div>
    );
}
