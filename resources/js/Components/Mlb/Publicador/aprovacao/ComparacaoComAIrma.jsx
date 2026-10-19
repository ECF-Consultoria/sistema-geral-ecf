import { cn } from '@/lib/utils';
import { andamentoDe } from './andamentoDoPortao';
import {
    TEXTO_DA_COR,
    classeDaCorDoPreco,
    inteiroDoPainel,
    listaDoPainel,
    objetoDoPainel,
    textoDoPainel,
} from './KpisDoPainel';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 188, plano 09 — A COMPARAÇÃO COM A OFERTA IRMÃ (`:688`-`:692`): a grade
// de 2 colunas com as DUAS modalidades do mesmo alvo-par, cada célula com
// rótulo, status, preço, resultado e margem — e a da irmã **CLICÁVEL**
// (REVS-11 / FIN-13).
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas, sha256 68624883ce35547e…). ⚠️ **CITE O DIRETÓRIO.**
//
// ═══ ⚠️⚠️ A ORDEM É CLÁSSICO ANTES DE PREMIUM, E ELA VEM DO SERVIDOR ═══════
//
// `:1294` (ponteiro CORRIGIDO pela `188-FID01.md` §L — o plano dizia `:1292`,
// que é o `.map()` das IMAGENS, assunto de OUTRA coluna) ordena por modalidade.
// No produto quem ordena é `PrecoDoPainelService::comparacao()`, por
// `array_keys(EstruturaPublicacao::LISTING_TYPES)` — **a constante**, nunca um
// literal `'gold_pro'` (seria a terceira cópia daquele valor) e nunca
// `orderBy('id')`, que depende de qual oferta foi criada primeiro.
// ➡️ **A tela NÃO reordena.** Uma segunda ordenação aqui seria a terceira lista
// do mesmo fato, e a divergente seria a que a pessoa lê.
//
// ═══ ⚠️⚠️ AS DUAS APARÊNCIAS SÃO DIFERENTES, E ISSO É SINAL ════════════════
//
// `:1294` — a ATUAL tem fundo `#1A1A1A` (`pub-hover-3`) e borda `#3F3F46`
// (`pub-line-off`); a OUTRA tem fundo `#111111` (`pub-surface`) e borda
// `#1F1F1F` (`pub-line-subtle`). ⚠️ **Uma aparência só** faria a pessoa perder
// de vista em qual das duas ela está — e as duas têm preço e resultado
// diferentes, então confundi-las é confundir o número que ela vai aprovar.
//
// ═══ ⚠️⚠️ A CÉLULA ATUAL NÃO NAVEGA PARA SI MESMA ═════════════════════════
//
// `:1294` — `isCur: x.id === o.id`, e o `href` do servidor é **`null`** na
// atual. ➡️ Ela sai como elemento NÃO clicável; só a irmã é link.
// ⚠️ **Botão sem destino é D4 violado**, e um link que recarrega a mesma tela é
// a mesma dívida com outra roupa.
//
// ═══ ⚠️⚠️ SEM IRMÃ, A GRADE NÃO É DESENHADA ═══════════════════════════════
//
// O servidor sempre manda a célula ATUAL em `comparacao` (é ela o espelho do que
// está aberto) e manda `irma` como **`null`** quando não há a outra modalidade.
// ⚠️ **Uma grade de 2 colunas com um item só é casca vazia** — meia tela de
// borda sem nada ao lado. ➡️ A grade nasce **só com as duas**, e a decisão lê
// `irma`, que é **DERIVADA** de `comparacao` no servidor justamente para que as
// duas chaves nunca possam discordar.
//
// ═══ ⚠️ O `href` É DO SERVIDOR, E O CLIQUE É DA TELA ══════════════════════
//
// `PrecoDoPainelService::celula()` devolve `href = '?oferta=<id>'`, e ele parte
// do **mesmo `rascunho_id`** (`ComposicaoFinanceiraService::comparacaoComAIrma`)
// — é assim que o link não pode apontar para outra conta; o `?oferta=` ainda
// passa pelo recorte de conta do plano 188-02.
// ⚠️ A célula é um `<a href>` de verdade **e** chama `onAbrir` no clique: o link
// dá destino real (abrir em nova aba funciona) e o handler faz a navegação
// INERTIA preservando o recorte em que a pessoa está. ⚠️ **Nenhum `router.` mora
// aqui**: quem navega é a página, pelo `abrirOferta` que ela já tem — o helper
// único de ESCRITA é `escreverNoPainel`, e esta peça **não escreve nada**.
//
// ⚠️ `andamentoDe()` **NUNCA devolve `null`** e o rótulo dele nunca é vazio: uma
// guarda que devolvesse `null` produziria elemento vazio, que **passa em render
// e desaparece na tela**. O rótulo preferido é o do SERVIDOR
// (`rotulo_do_andamento`), e o do módulo é o fallback — **um vocabulário só**,
// chaves pt-BR nos dois lados.
// ⚠️ Nenhum `String(valor)`, nenhum `hasOwnProperty` solto, nenhum
// `obj[chaveDoServidor]` cru.
// ═══════════════════════════════════════════════════════════════════════════

/** `:688` — a grade de 2 colunas. ⚠️ Igualdade de STRING: `grid-cols-2` NÃO serve. */
export const CLASSES_GRADE = 'grid grid-cols-[repeat(2,minmax(0,1fr))] gap-[6px]';

/** `:690` — a célula, sem a cor (que é das DUAS aparências abaixo). */
export const CLASSES_CELULA = 'flex flex-col gap-[4px] rounded-pub-control border px-[12px] py-[10px] text-left text-pub-txt';

/** `:690` — a célula ATUAL: fundo `#1A1A1A` e borda `#3F3F46`. */
export const CLASSES_ATUAL = 'bg-pub-hover-3 border-pub-line-off';

/** `:690` — a OUTRA: fundo `#111111` e borda `#1F1F1F`. ⚠️ São DUAS aparências. */
export const CLASSES_OUTRA = 'bg-pub-surface border-pub-line-subtle';

/** `:690` — só a irmã é clicável (`cursor:pointer`). */
export const CLASSES_CLICAVEL = 'cursor-pointer';

/** `:691` — a linha 1: losango (só Premium) + rótulo + status à direita. */
export const CLASSES_LINHA_1 = 'flex items-center gap-[6px] text-[12px] font-semibold';

/**
 * `:691` — o losango do Premium.
 *
 * ⚠️ MEDIDO: `width:6px;height:6px;background:#F4F4F5;transform:rotate(45deg)` —
 * **sem `border-radius`**, e 6px. Os outros dois losangos deste módulo
 * (`IdentificacaoDaOferta.jsx:176` e `LinhaDaOferta.jsx:240`) são **7px com
 * `rounded-[1px]`**: são elementos DIFERENTES do protótipo, e igualá-los
 * mudaria os três. ⚠️ A tabela §D omitia o `background`.
 */
export const CLASSES_LOSANGO = 'h-[6px] w-[6px] flex-none rotate-45 bg-pub-txt';

/** `:691` — o status, empurrado para a direita pelo `margin-left:auto`. */
export const CLASSES_STATUS = 'ml-auto text-[11px] font-medium';

/** `:692` — a linha 2: preço · resultado · margem, com `tabular-nums`. */
export const CLASSES_LINHA_2 = 'flex gap-[10px] text-[12px] tabular-nums';

/** `:692` — o preço da célula (`#D4D4D8` = `pub-fase1`). */
export const CLASSES_PRECO = 'text-pub-fase1';

/**
 * UMA célula da comparação.
 *
 * ⚠️ NAMED EXPORT para o gate exercer o CLIQUE direto:
 * `renderToStaticMarkup` **não dispara `onClick`**, e a TELA PRETA deste projeto
 * voltou SEIS vezes — a última **no clique**.
 *
 * @param {object} props
 * @param {unknown} [props.celula] uma entrada de `preco.comparacao`
 * @param {unknown} [props.onAbrir] `(ofertaId) => void`, ou `null`
 */
export function CelulaDaComparacao({ celula = null, onAbrir = null }) {
    // ⚠️ Flag DENTRO do componente — nunca içada para fora do `.map()` do pai.
    const dado = objetoDoPainel(celula);
    if (dado === null) return null;

    const atual = dado.atual === true;
    const rotulo = textoDoPainel(dado.rotulo);
    const preco = textoDoPainel(dado.preco);
    const resultado = textoDoPainel(dado.resultado);
    const margem = textoDoPainel(dado.margem);

    // ⚠️ Célula sem rótulo E sem preço não nasce: no grid de duas colunas ela
    // apareceria como meia tela de borda sem nada dentro (D4).
    if (rotulo === null && preco === null) return null;

    // ⚠️ O rótulo do andamento: o do SERVIDOR primeiro, o do MÓDULO como
    // fallback. ⚠️ `andamentoDe()` nunca devolve `null`, e é dele que sai a COR —
    // ela não viaja no payload (TOK-15: nenhum hex sai do PHP).
    const doAndamento = andamentoDe(dado.andamento_da_irma);
    const status = textoDoPainel(dado.rotulo_do_andamento) ?? textoDoPainel(doAndamento.rotulo);

    const corDoResultado = classeDaCorDoPreco(TEXTO_DA_COR, dado.resultado_cor, TEXTO_DA_COR.texto);
    const corDaMargem = classeDaCorDoPreco(TEXTO_DA_COR, dado.margem_cor, TEXTO_DA_COR.neutro);

    const href = textoDoPainel(dado.href);
    const ofertaId = inteiroDoPainel(dado.oferta_id);
    const abrir = !atual && ofertaId !== null && ofertaId > 0 && typeof onAbrir === 'function'
        ? () => onAbrir(ofertaId)
        : null;

    const corpo = (
        <>
            <span className={CLASSES_LINHA_1}>
                {/* ⚠️ O losango SÓ no Premium — é ele que distingue as duas. */}
                {dado.premium === true ? <span aria-hidden="true" className={CLASSES_LOSANGO} /> : null}
                {rotulo}
                {status === null ? null : (
                    <span className={cn(CLASSES_STATUS, doAndamento.texto)}>{status}</span>
                )}
            </span>

            <span className={CLASSES_LINHA_2}>
                {preco === null ? null : <span className={CLASSES_PRECO}>{preco}</span>}
                {resultado === null ? null : <span className={corDoResultado}>{resultado}</span>}
                {margem === null ? null : <span className={corDaMargem}>{margem}</span>}
            </span>
        </>
    );

    // ⚠️⚠️ A ATUAL não navega para si mesma (`href` é `null` no servidor), então
    // ela sai como `<span>`: elemento clicável que não leva a lugar nenhum é D4
    // violado.
    if (atual || href === null) {
        return <span className={cn(CLASSES_CELULA, atual ? CLASSES_ATUAL : CLASSES_OUTRA)}>{corpo}</span>;
    }

    return (
        <a
            href={href}
            className={cn(CLASSES_CELULA, CLASSES_OUTRA, CLASSES_CLICAVEL)}
            onClick={abrir === null ? undefined : (evento) => {
                // ⚠️ `preventDefault` só quando há navegação INERTIA para fazer:
                // sem o handler o `href` continua sendo um destino de verdade.
                evento?.preventDefault?.();
                abrir();
            }}
        >
            {corpo}
        </a>
    );
}

/**
 * A comparação com a oferta irmã.
 *
 * @param {object} props
 * @param {unknown} [props.comparacao] `preco.comparacao` — a atual e (quando há)
 *   a irmã, já ORDENADAS pelo servidor
 * @param {unknown} [props.irma] `preco.irma` — `null` quando não há irmã. ⚠️ Ela
 *   é **DERIVADA** de `comparacao` no servidor, para que as duas nunca discordem
 * @param {unknown} [props.onAbrir] `(ofertaId) => void`, ou `null` — a navegação
 *   é da página (`abrirOferta`), que preserva o recorte em que a pessoa está
 */
export default function ComparacaoComAIrma({ comparacao = null, irma = null, onAbrir = null }) {
    const lista = listaDoPainel(comparacao);

    // ⚠️⚠️ SEM IRMÃ, SEM GRADE: 2 colunas com um item só é casca vazia. A decisão
    // lê `irma` **e** confere que há as DUAS células — as duas chaves vêm do
    // mesmo lugar no servidor, e exigir as duas é o que impede a grade de nascer
    // torta se uma delas vier adversa.
    if (objetoDoPainel(irma) === null || lista.length < 2) return null;

    return (
        <div className={CLASSES_GRADE}>
            {lista.map((celula, indice) => {
                // ⚠️⚠️ Flag DENTRO do callback. O que ele lê é PARÂMETRO e nome de
                // MÓDULO (`CelulaDaComparacao`, `objetoDoPainel`), que o Rollup
                // nunca elide — a forma que a wave 187-09 mediu como segura.
                const id = objetoDoPainel(celula)?.oferta_id;

                return (
                    <CelulaDaComparacao
                        key={typeof id === 'number' ? `oferta-${id}` : `celula-${indice}`}
                        celula={celula}
                        onAbrir={onAbrir}
                    />
                );
            })}
        </div>
    );
}
