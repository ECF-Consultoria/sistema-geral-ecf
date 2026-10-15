import AbasDaPagina from '@/Components/Mlb/Publicador/publicacoes/AbasDaPagina';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 04 — O CABEÇALHO DA FILA "Aguardando aprovação"
// (FILA-01, FILA-02, ABA-02, FIN-09).
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas, sha256 `68624883ce35547e`). Linhas MEDIDAS com `grep -n` nesta
// sessão (15/10/2026) — ⚠️ **sete dos doze ponteiros do plano estavam
// deslocados**, e as medidas abaixo são as certas:
//
//   · `<header>` do bloco  → :30  (`display:flex;align-items:flex-end;`
//                                  `justify-content:space-between;gap:24px;`
//                                  `border-bottom:1px solid #1F1F1F`)
//   · coluna do H1         → :31  (`flex-direction:column;gap:14px`)
//   · H1 "Publicações"     → :32  (`margin:0;font-size:28px;line-height:1;`
//                                  `font-weight:600;letter-spacing:-0.025em`)
//   · trilho das ABAS      → :33-:35 — ele NÃO mora aqui: vem de
//                                  `publicacoes/AbasDaPagina.jsx` (plano 187-03)
//   · bloco da DIREITA     → :38  (`gap:8px;padding-bottom:12px;`
//                                  `font-size:12.5px;color:#8A8A90;`
//                                  `white-space:nowrap`)  ⚠️ o plano cita :37
//   · o ⓘ                  → :39  (`16×16;border-radius:50%;background:#1F1F1F;`
//                                  `color:#D4D4D8;font-size:10px;`
//                                  `font-weight:800`) + a letra `i`
//                                  ⚠️ o plano cita :38
//   · valor e separador    → :40  (`color:#F4F4F5;font-weight:600` e
//                                  `color:#3F3F46`)  ⚠️ o plano cita :39
//
// ═══ ⚠️⚠️ POR QUE ESTE É ARQUIVO PRÓPRIO, E NÃO `CabecalhoDePublicacoes` ═════
//
// A estrutura reusa o padrão da lista, mas a DIREITA é DIFERENTE e isso é
// MEDIDO (divergência **D2** do `187-BASELINES.md`):
//   · FILA  → `gap:8px`  **com** `padding-bottom:12px` (`Aprovacao.dc.html:38`);
//   · LISTA → `gap:10px` **sem** `padding-bottom`
//             (`design_handoff_publicador/Publicacoes v2.dc.html:39`).
// Importar o cabeçalho da lista e "parametrizar" obrigaria a harmonizar as
// duas, e "deixei igual à outra tela" é justificativa VETADA (FID-02). ⚠️ Há
// gate exigindo o `pb-[12px]` e PROIBINDO o `gap-[10px]` aqui.
//
// ═══ ⚠️⚠️ AS ABAS VÊM DO 187-03 — MESMO COMPONENTE, OUTRA PROP ══════════════
//
// `AbasDaPagina` é o componente das DUAS telas: a lista monta
// `ativa="publicadas"` e a fila monta `ativa="aprovacao"`. Recriar as abas aqui
// criaria DUAS verdades, livres para divergir num rótulo sem ninguém ver — e é
// por isso que esta wave vem DEPOIS da 187-03. ⚠️ Os dois rótulos **não estão
// escritos neste arquivo**, de propósito, e há gate de ausência com controle
// positivo no arquivo do 187-03.
//
// ═══ ⚠️⚠️ DIVERGÊNCIA DO SEPARADOR, MEDIDA (`:40`) ══════════════════════════
//
// O protótipo **não tem espaço em volta do `·`**. O literal é
// `Margem mínima: <span>12%</span><span>·</span>Toda oferta precisa …`, e o
// espaçamento de 8px vem do `gap:8px` do bloco PAI (`:38`), que é
// `display:flex` e transforma cada nó de texto em item anônimo — são CINCO
// itens flex. O `<behavior>` do plano pede `" · "` COM espaços; escrevê-los
// daria 8px de gap **mais** dois espaços tipográficos. ➡️ O protótipo ganha.
//
// ═══ ⚠️⚠️ DIVERGÊNCIA DO PESO 800, MEDIDA (`:39`) ═══════════════════════════
//
// O ⓘ é `font-weight:800` e o E1 tem o peso 800 MEDIDO
// (`187-BASELINES.md` §5: `font-weight | 500 600 700 800`). O plano prescreve
// `font-extrabold`, mas `publicador-vocabulario-novo.test.js` (GATE-03, Fase
// 177) PROÍBE a escala NOMEADA `font-extrabold` em todo arquivo de
// `ARQUIVOS_NOVOS`. ➡️ A classe é **`font-[800]`**: o MESMO peso computado, por
// valor arbitrário, sem a escala nomeada que o GATE-03 veta e sem arredondar
// para `font-bold` (700), que seria FID-03 literal. ⚠️ Há asserção no CSS
// CONSTRUÍDO provando que ela compila para `font-weight:800` — valor arbitrário
// que não compila é o precedente medido de `duration-[Nms]`.
//
// ═══ ⚠️⚠️ O ⓘ NÃO É ÍCONE (divergência D4) ═════════════════════════════════
//
// É um `<span>` redondo de 16px com a **letra `i`**. Trocar por `Info` do
// Lucide mudaria o desenho E o `viewBox` (`0 0 24 24` contra os `0 0 16 16` do
// protótipo, o que exigiria `strokeWidth × 1,5`). Nada de `lucide-react` aqui.
//
// ═══ ⚠️⚠️ O NÚMERO NUNCA É LITERAL (FIN-09 / EXMP-01) ══════════════════════
//
// O `12` do protótipo é dado de EXEMPLO (`MIN = 12`, `:914`). O valor vem de
// `margem_minima.valor`, resolvido em UM lugar só
// (`ComposicaoFinanceiraService::margemMinimaComOrigem`): o parâmetro da
// empresa manda quando é **maior que zero**, a config manda no resto, e **o
// zero NÃO desliga o bloqueio**. ➡️ Zero na prop é dado que não deveria
// existir, e a linha inteira **não aparece** em vez de afirmar "0%" — uma
// mentira sobre o dinheiro do cliente. Há gate de ausência do literal `12` com
// controle positivo e negativo.
//
// ⚠️ E a **origem viaja com o número** (FIN-09): cada número diz de onde vem.
// Origem fora do conjunto fechado aparece **COMO CHEGOU** no texto auxiliar —
// é o precedente literal de `SeloDeStatusDaPublicacao` ("não se apaga a linha
// nem se inventa um rótulo"). Engoli-la deixaria um defeito do servidor
// invisível.
//
// ⚠️ TODA classe mora NESTE `.jsx`: o `content` do `tailwind.config.js` varre
// `./resources/js/**/*.jsx` e **NÃO** `*.js`. Classe escrita num `.js` não gera
// CSS e NÃO EXISTE PARA A TELA, com todo teste de render passando.
//
// ⚠️ NUNCA `String(valor)` nem `JSON.stringify(valor)` sobre prop: o
// `String({toString: null})` LANÇA e derruba a página inteira (a TELA PRETA de
// 07/10/2026, que voltou QUATRO vezes na Fase 180), e qualquer `obj[chave]` com
// a chave vinda do servidor LANÇA do mesmo jeito. Por isso o lookup da origem é
// por `Map.get` (que nunca coage) e nunca por `objeto[chave]`.
// ═══════════════════════════════════════════════════════════════════════════

/** Os literais de tela, byte a byte como o protótipo os escreve. */
export const TITULO = 'Publicações';
export const PREFIXO_MARGEM = 'Margem mínima:';
export const SEPARADOR = '·';
export const FRASE_DA_APROVACAO = 'Toda oferta precisa de aprovação humana antes de publicar';

/** A letra do ⓘ (`:39`) — é LETRA, não ícone. */
const LETRA_INFO = 'i';

/**
 * As duas origens do resolvedor único → a frase legível de onde o número veio.
 *
 * ⚠️ `Map` e não objeto literal: `objeto[chaveAdversa]` LANÇA com
 * `{toString:null}` e, com chave do protótipo (`'toString'`,
 * `'constructor'`), devolve uma FUNÇÃO — que renderizada derruba a página.
 * `Map.get` não coage a chave e não tem protótipo para vazar.
 *
 * ⚠️ As duas chaves são as de `PubAprovacaoComposicao::MINIMO_PARAMETRO` e
 * `MINIMO_CONFIG`, e as frases dizem COISAS DIFERENTES de propósito: um texto
 * auxiliar único para as duas não diria de onde o número veio — seria
 * decoração, e nada nasce decorativo (D4).
 */
const FRASE_DA_ORIGEM = new Map([
    ['parametro_da_empresa', 'Mínimo definido no parâmetro de precificação desta empresa'],
    ['config', 'Mínimo padrão do sistema — esta empresa não tem parâmetro próprio'],
]);

/** O prefixo da origem DESCONHECIDA, que aparece como chegou. */
const PREFIXO_ORIGEM_CRUA = 'Origem do mínimo: ';

/**
 * O percentual MEDIDO, formatado em pt-BR, ou `null` quando não há o que afirmar.
 *
 * ⚠️ `valor <= 0` devolve `null`: o resolvedor único cai na config quando o
 * parâmetro é zero, então zero aqui é dado que não deveria existir — e "0%"
 * seria uma afirmação FALSA sobre a margem mínima.
 *
 * ⚠️ `Number.isFinite` recusa `NaN` e `Infinity` de uma vez. `typeof` ANTES de
 * qualquer coisa: `Number('12')` aceitaria a string e `textoSeguro(NaN)`
 * devolveria a STRING `'NaN'`, que iria para a tela.
 *
 * @param {unknown} valor o que o servidor mandou em `margem_minima.valor`
 * @returns {string|null} ex: `'12%'`, `'8,5%'`
 */
export function percentualMedido(valor) {
    if (typeof valor !== 'number' || !Number.isFinite(valor) || valor <= 0) return null;

    // Decimal em pt-BR: vírgula, nunca ponto. Inteiro não ganha casa decimal.
    return Number.isInteger(valor) ? `${valor}%` : `${valor.toFixed(1).replace('.', ',')}%`;
}

/**
 * O texto auxiliar da origem, ou `null` quando não há origem usável.
 *
 * @param {unknown} origem
 * @returns {string|null}
 */
export function textoDaOrigem(origem) {
    if (typeof origem !== 'string' || origem.trim() === '') return null;

    const conhecida = FRASE_DA_ORIGEM.get(origem);

    return conhecida === undefined ? `${PREFIXO_ORIGEM_CRUA}${origem}` : conhecida;
}

/**
 * O objeto do servidor, só quando é objeto comum de verdade.
 *
 * ⚠️ Array é `typeof 'object'` e `null` também — os dois precisam de guarda
 * própria. Sem isso `margem_minima` chegando `[]` passaria por `typeof` e
 * `.valor` daria `undefined`, que é o caminho do `undefined` na tela.
 */
function objetoComum(valor) {
    return valor !== null && typeof valor === 'object' && !Array.isArray(valor) ? valor : null;
}

// As classes, escritas uma vez e literalmente — é daqui que o Tailwind as lê.
const CLASSES_HEADER = 'flex items-end justify-between gap-[24px] border-b border-pub-line-subtle';
const CLASSES_COLUNA = 'flex flex-col gap-[14px]';
const CLASSES_H1 = 'm-0 text-[28px] font-semibold leading-none tracking-[-0.025em]';
const CLASSES_DIREITA = 'flex items-center gap-[8px] whitespace-nowrap pb-[12px] text-[12.5px] text-pub-txt-3';
const CLASSES_INFO = 'grid h-[16px] w-[16px] place-items-center rounded-full bg-pub-line-subtle text-[10px] font-[800] text-pub-fase1';
const CLASSES_VALOR = 'font-semibold text-pub-txt';
const CLASSES_SEPARADOR = 'text-pub-line-off';

/**
 * O cabeçalho da fila: o H1, as duas abas com a da FILA ativa e a linha
 * "ⓘ Margem mínima: N% · Toda oferta precisa de aprovação humana antes de
 * publicar".
 *
 * @param {object} props
 * @param {unknown} [props.contador] o contador da fila (`contador_da_aba`, do servidor)
 * @param {unknown} [props.publicadas] o contador da aba da lista, quando a tela o tiver
 * @param {unknown} [props.margemMinima] `{valor, origem}` de `margem_minima`, ou nulo
 * @param {unknown} [props.hrefAprovacao] o destino da fila
 * @param {unknown} [props.hrefLista] o destino da lista (`mlb.anuncios.meus`)
 */
export default function CabecalhoDaFila({
    contador = null,
    publicadas = null,
    margemMinima = null,
    hrefAprovacao = null,
    hrefLista = null,
}) {
    const minimo = objetoComum(margemMinima);
    const percentual = percentualMedido(minimo === null ? null : minimo.valor);
    const auxiliar = percentual === null ? null : textoDaOrigem(minimo.origem);

    return (
        <header className={CLASSES_HEADER}>
            <div className={CLASSES_COLUNA}>
                <h1 className={CLASSES_H1}>{TITULO}</h1>
                {/* ⚠️ O MESMO componente do 187-03, com a prop da aba ativa
                    apontando para a fila. Nada de segunda cópia das abas. */}
                <AbasDaPagina
                    ativa="aprovacao"
                    publicadas={publicadas}
                    aguardando={contador}
                    hrefAprovacao={hrefAprovacao}
                    hrefLista={hrefLista}
                />
            </div>

            {/* ⚠️ Sem valor medido a linha INTEIRA não aparece — nem o ⓘ, nem o
                prefixo, nem o `·`, nem a frase. Nada nasce decorativo (D4), e
                "0%" seria zero inventado (EXMP-01). */}
            {percentual !== null && (
                <div className={CLASSES_DIREITA}>
                    <span aria-hidden="true" className={CLASSES_INFO}>{LETRA_INFO}</span>
                    {PREFIXO_MARGEM}
                    <span className={CLASSES_VALOR} {...(auxiliar === null ? {} : { title: auxiliar })}>
                        {percentual}
                    </span>
                    <span aria-hidden="true" className={CLASSES_SEPARADOR}>{SEPARADOR}</span>
                    {FRASE_DA_APROVACAO}
                </div>
            )}
        </header>
    );
}
