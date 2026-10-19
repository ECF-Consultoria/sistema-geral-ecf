import { cn } from '@/lib/utils';
import {
    TEXTO_DA_COR,
    classeDaCor,
    inteiroDoPainel,
    listaDoPainel,
    objetoDoPainel,
    textoDoPainel,
} from './ImagemGrandeDoPainel';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 188, plano 08 — O BLOCO DA IMAGEM SELECIONADA (`:621`-`:634`): a
// identificação, as duas ações da foto e as VERIFICAÇÕES AUTOMÁTICAS.
//
// ⚠️⚠️ PERGUNTA-188-A — RESPONDIDA PELO USUÁRIO EM 18/10/2026, OPÇÃO (c),
// HÍBRIDO. É a TERCEIRA derrota do protótipo nesta milestone, e as três foram
// conflito entre DUAS REGRAS DO PROJETO resolvido pelo usuário, nunca erro dele.
//
// O que entra: o cabeçalho `VERIFICAÇÃO AUTOMÁTICA · APOIO` **uma vez**, **e**
// cada item mantendo **o número que o liga ao marcador x/y na foto**.
//
// A RAZÃO, escrita pelo usuário: *o cabeçalho é o que diz **APOIO** — sem ele a
// verificação automática pode ser lida como **VEREDICTO**, que é o que REVS-10 e
// a D7 querem impedir; e o ordinal é **funcional** (liga o item ao marcador), não
// enfeite.*
//
// Os DOIS lados da decisão, com `arquivo:linha`:
//   · `design_handoff_publicador/Aprovacao.dc.html:369` — o literal
//     `VERIFICAÇÃO AUTOMÁTICA · APOIO` **existe**, mas é da **revisão detalhada
//     (E4)**, que é a Fase 190/191;
//   · `:633` — o que o PAINEL escreve é `'Verificação automática' + a.n + ' · '`,
//     **minúsculo e sem cabeçalho**.
//
// ⚠️⚠️ E A FORMA COLADA NÃO É REPRODUZIDA. O `a.n` do protótipo (`:1108`, e
// **não** `:1111` como a prosa do plano dizia — `:1111` é o `ref`/`refSlot`) é
// concatenado DENTRO da frase, produzindo `"Verificação automática2 · "`. ➡️ Aqui
// o ordinal é **elemento próprio** ao lado do item: é o NÚMERO DO MARCADOR, não
// sufixo de palavra. Há gate de ausência da forma colada, com isca.
//
// ⚠️⚠️ O NÚMERO VEM DA PROP E **NÃO É RECONTADO**. `verificacoes[*].ordinal` sai
// de `FotosDoPainelService::verificacoes()`, que o acha por identidade no MESMO
// array que `marcadores()` numera. Duas numerações da mesma verdade divergiriam,
// e a divergência apareceria como o texto dizendo "2" com o marcador 2 em OUTRO
// defeito — **o pior erro possível nesta coluna, porque a pessoa reprova a foto
// errada**.
// ⚠️ Item de severidade `ok` tem `ordinal: null` (ele não está na lista de
// alertas) — e `null`, nunca `0`: `0` é um número de marcador PLAUSÍVEL para quem
// lê.
//
// ⚠️ Cabeçalho + caixa **só** com `verificacoes` não-vazia. Cabeçalho sozinho é
// casca vazia, e D4 proíbe peça que não diz nada.
//
// ⚠️⚠️ B2 — O "Reprovar" É DESENHADO AQUI, COM PROP, E O PLANO 188-12 O RELIGA.
// Ele recebe `onReprovar` (função **ou** `null`) e só desenha o botão quando é
// função. **Nada de `onClick` vazio e nada de `disabled` decorativo** — botão sem
// destino é D4 violado, e as duas formas são a mesma dívida com outra roupa.
//
// ⚠️⚠️ `✓ {quem}` (`:630`) **NÃO É DESENHADO, e isto é OMISSÃO DE DADO
// DECLARADA.** O valor do protótipo é `by` (`:1110`), *"Aprovada por X · data"* —
// e `FotosDoPainelService::imagem()` **não expõe** nem `aprovada_por` nem
// `aprovada_em` (as 23 chaves estão em
// `app/Services/Publicador/Aprovacao/Painel/FotosDoPainelService.php:561`-`:588`).
// Acrescentá-las exigiria **alterar o serviço da wave 188-03**, que é território
// SOMENTE LEITURA. ➡️ Nada é inventado no lugar: um `✓ Aprovada` sem o nome só
// repetiria o selo de estado que já está na imagem grande.
//
// ⚠️ `tipo · variação` (`:623`) sai **só com o tipo**: ver a divergência escrita
// em `VARIACAO` abaixo.
// ⚠️ Toda flag computada DENTRO do callback do `.map()`.
// ═══════════════════════════════════════════════════════════════════════════

/** `:369` — o cabeçalho, byte a byte. O `·` é U+00B7, não um ponto. */
export const CABECALHO_DAS_VERIFICACOES = 'VERIFICAÇÃO AUTOMÁTICA · APOIO';

/** `:623` — o separador entre `#n` e o rótulo, e entre tipo e variação. */
export const SEPARADOR = ' · ';

/** `:626`/`:627` — os dois rótulos de ação da foto, byte a byte. */
export const REPROVAR = 'Reprovar';

export const APROVAR_FOTO = 'Aprovar foto';

/** `pending` de `PubAprovacaoImagem` — a única condição das duas ações. */
export const ESTADO_PENDENTE = 'pending';

// ─── As classes, uma casa só (`:621`-`:633` e `:369`) ──────────────────────

/** `:621` — a caixa do bloco. */
export const CLASSES_BLOCO = 'flex flex-col gap-[8px] rounded-pub-sub border border-pub-line-subtle bg-pub-sunken px-[12px] py-[10px]';

/** `:622` — o cabeçalho do bloco (C21w, wrapper que a tabela OMITIA). */
export const CLASSES_CABECALHO = 'flex items-center justify-between gap-[10px]';

/**
 * `:623` — o wrapper INTERNO com `min-width:0`.
 *
 * ⚠️ É ELE que permite o `text-overflow:ellipsis` do título funcionar. A
 * `188-FID01.md` o omitia (achado da re-medição do 188-01): sem o `min-w-0` o
 * título **não trunca** e vaza a coluna.
 */
export const CLASSES_IDENTIFICACAO = 'flex min-w-0 flex-col gap-[2px]';

/**
 * `:623` — `#n · rótulo`.
 *
 * ⚠️ `truncate` é exatamente os TRÊS da medida: `overflow:hidden`,
 * `text-overflow:ellipsis` e `white-space:nowrap`. O `nowrap` foi omitido SETE
 * vezes na Fase 187, três delas sendo a PRIMEIRA propriedade da linha.
 */
export const CLASSES_TITULO = 'truncate text-[13px] font-semibold';

/** `:623` — `tipo · variação`. */
export const CLASSES_SUBTITULO = 'text-[11.5px] text-pub-txt-3';

/** `:625` — o wrapper dos dois botões (C24w, omitido pela tabela). */
export const CLASSES_ACOES = 'flex gap-[6px]';

/** `:626` — "Reprovar". */
export const CLASSES_REPROVAR = 'h-[30px] cursor-pointer whitespace-nowrap rounded-pub-control-sm border '
    + 'border-pub-danger/[0.45] bg-transparent px-[11px] text-[12.5px] font-medium text-pub-danger-txt';

/** `:627` — "Aprovar foto". */
export const CLASSES_APROVAR = 'h-[30px] cursor-pointer whitespace-nowrap rounded-pub-control-sm border-none '
    + 'bg-pub-accent px-[12px] text-[12.5px] font-semibold text-pub-page';

/** `:369` — o cabeçalho das verificações. ⚠️ FID-03: `text-[11px]`, nunca `text-xs`. */
export const CLASSES_CABECALHO_VERIFICACOES = 'text-[11px] font-semibold tracking-[0.08em] text-pub-txt-4';

/** `:633` — o item de verificação. */
export const CLASSES_ITEM = 'flex items-start gap-[8px] text-[12px] leading-[1.4] text-pub-fase1';

/** `:633` — o glifo redondo de 16px do item. */
export const CLASSES_GLIFO = 'grid h-[16px] w-[16px] flex-none place-items-center rounded-full text-[9.5px] font-[800]';

/**
 * ⚠️ O ORDINAL, **elemento próprio** ao lado do item — nunca colado na frase.
 *
 * Ele é o número do marcador que está sobre a foto, e a cor da severidade é o que
 * o liga visualmente ao marcador correspondente.
 */
export const CLASSES_ORDINAL = 'flex-none text-[12px] font-bold';

/** A lista de verificações, com gap de 8px entre os itens (`:632`). */
export const CLASSES_VERIFICACOES = 'flex flex-col gap-[8px]';

/** As chaves semânticas de cor → o FUNDO do glifo (`:1108`). ⚠️ Nenhum hex: TOK-15. */
export const FUNDO_DO_GLIFO = {
    verde: 'bg-pub-ok/[0.14]',
    ambar: 'bg-pub-warn/[0.16]',
    vermelho: 'bg-pub-danger/[0.16]',
    neutra: 'bg-pub-neutral-dot/[0.16]',
    neutra_fraca: 'bg-pub-neutral-dot/[0.16]',
};

/**
 * ⚠️⚠️ `tipo · variação` (`:623`) — A VARIAÇÃO **NÃO É DESENHADA**, e a razão é
 * MEDIDA, não preferência.
 *
 * O protótipo lê `variation` do PRODUTO (`:1112`:
 * `this.PROD[this.SETS[set].prod].variation`). No produto **não existe rótulo
 * legível de variação no payload**: a única chave que chega é `grupo`
 * (`FotosDoPainelService.php:571`), e ela é a **CHAVE CANÔNICA** de
 * `pub_aprovacao_conjuntos.grupo_chave`, não um nome:
 *   · `'GENERAL'` (`PubAprovacaoConjunto::GERAL`, `app/Models/
 *     PubAprovacaoConjunto.php:42`), **ou**
 *   · `ChaveCanonica::combinacao()` (`app/Support/Publicador/Variacao/
 *     ChaveCanonica.php:39`-`:48`), que produz `EIXO=valor` juntados por `|`,
 *     com o valor em `id:<id do ML>` ou `txt:<minúsculo, sem acento>`
 *     (`ChaveCanonica.php:24`-`:35`).
 * ➡️ Na prática a chave é `COLOR=id:52049`, **não** `COR:Azul`. O nome legível
 * vive em `ResolvedorGruposImagem::rotuloDoGrupo()`
 * (`app/Support/Publicador/Imagem/ResolvedorGruposImagem.php:166`), e
 * **`App\Support\Publicador` é território VETADO** nesta fase.
 * ⚠️ Derivar *"Cor · Azul"* daquela chave seria **inventar**: traduzir `COLOR`
 * para `Cor` é tradução minha, e `id:52049` para `Azul` é impossível. E escrever
 * `COLOR=id:52049` na tela é **jargão indo para a tela**, que a régua proíbe.
 * ➡️ A linha sai **só com o tipo**, sem separador pendurado, e a pergunta foi
 * DEVOLVIDA (ver a SUMMARY do plano 188-08).
 */

/** A classe de fundo do glifo, com NEUTRO para chave desconhecida. */
export function fundoDoGlifo(chave) {
    const nome = typeof chave === 'string' && Object.prototype.hasOwnProperty.call(FUNDO_DO_GLIFO, chave)
        ? chave
        : 'neutra';

    return FUNDO_DO_GLIFO[nome];
}

/**
 * As verificações DESENHÁVEIS: as que têm texto. ⚠️ O `ordinal` é PRESERVADO.
 */
export function verificacoesDesenhaveis(verificacoes) {
    return listaDoPainel(verificacoes)
        .map((bruta) => {
            // ⚠️ Flag DENTRO do callback.
            const item = objetoDoPainel(bruta);
            if (item === null) return null;

            const texto = textoDoPainel(item.texto);
            if (texto === null) return null;

            return {
                texto,
                glifo: textoDoPainel(item.glifo),
                cor: item.cor,
                // ⚠️ LIDO, nunca recontado.
                ordinal: inteiroDoPainel(item.ordinal),
            };
        })
        .filter((item) => item !== null);
}

/**
 * ⚠️⚠️ `:369` + `:632`-`:633` — o cabeçalho **UMA vez** e os itens com o ordinal.
 *
 * NAMED EXPORT para o gate medir a lista sem montar o bloco todo.
 */
export function VerificacoesAutomaticas({ verificacoes = null }) {
    const itens = verificacoesDesenhaveis(verificacoes);

    // ⚠️ Sem verificação: **nem cabeçalho nem caixa**. Cabeçalho sozinho é casca
    // vazia (D4), e um cabeçalho que diz "APOIO" sem nada embaixo não apoia nada.
    if (itens.length === 0) return null;

    return (
        <div className={CLASSES_VERIFICACOES}>
            <span className={CLASSES_CABECALHO_VERIFICACOES}>{CABECALHO_DAS_VERIFICACOES}</span>
            {itens.map((item, indice) => {
                // ⚠️⚠️ TODAS as flags DENTRO do callback.
                const corDoTexto = classeDaCor(TEXTO_DA_COR, item.cor);
                const temOrdinal = item.ordinal !== null;

                return (
                    <span key={`verificacao-${indice}`} className={CLASSES_ITEM}>
                        {item.glifo === null ? null : (
                            <span className={cn(CLASSES_GLIFO, fundoDoGlifo(item.cor), corDoTexto)}>
                                {item.glifo}
                            </span>
                        )}
                        {/* ⚠️ O ordinal é o NÚMERO DO MARCADOR, separado da frase.
                            `ok` não tem número (`ordinal: null`). */}
                        {temOrdinal ? <span className={cn(CLASSES_ORDINAL, corDoTexto)}>{item.ordinal}</span> : null}
                        <span>{item.texto}</span>
                    </span>
                );
            })}
        </div>
    );
}

/**
 * O bloco da imagem selecionada.
 *
 * @param {object} props
 * @param {unknown} [props.imagem] a entrada selecionada de `painel.fotos.imagens`
 * @param {unknown} [props.live] se a oferta aceita escrita (`painel.fotos` vem de
 *   uma oferta `live`); só com ela as duas ações existem
 * @param {unknown} [props.onAprovar] `() => void`, ou `null` (a escrita vive em
 *   `ColunaDeFotos`, pelo helper único `escreverNoPainel`)
 * @param {unknown} [props.onReprovar] `() => void`, ou `null` — o plano **188-12**
 *   é quem o fornece, junto com o modal (B2)
 */
export default function BlocoDaImagemSelecionada({
    imagem = null,
    live = false,
    onAprovar = null,
    onReprovar = null,
}) {
    const dados = objetoDoPainel(imagem);
    if (dados === null) return null;

    const posicao = textoDoPainel(dados.posicao_rotulo);
    const rotulo = textoDoPainel(dados.rotulo) ?? textoDoPainel(dados.titulo);
    const tipo = textoDoPainel(dados.tipo_rotulo);

    // ⚠️ `#n · rótulo` com os dois degraus: sem um deles a linha não ganha
    // separador pendurado.
    const titulo = posicao !== null && rotulo !== null
        ? `${posicao}${SEPARADOR}${rotulo}`
        : posicao ?? rotulo;

    // ⚠️⚠️ `:1311` (e **não** `:1314`, que é `const S = this.state` — ponteiro
    // deslocado em −3 na prosa do plano): `isPending: sel.isPending && inf.live`.
    const pendente = dados.estado === ESTADO_PENDENTE && live === true;

    // ⚠️ Botão só com handler de VERDADE. `onClick` vazio é a mesma dívida que
    // botão sem destino, com outra roupa.
    const aprovar = pendente && typeof onAprovar === 'function' ? onAprovar : null;
    const reprovar = pendente && typeof onReprovar === 'function' ? onReprovar : null;

    return (
        <div className={CLASSES_BLOCO}>
            <div className={CLASSES_CABECALHO}>
                <span className={CLASSES_IDENTIFICACAO}>
                    {titulo === null ? null : <span className={CLASSES_TITULO}>{titulo}</span>}
                    {/* ⚠️ Só o TIPO: ver a divergência escrita em `VARIACAO`. */}
                    {tipo === null ? null : <span className={CLASSES_SUBTITULO}>{tipo}</span>}
                </span>

                {/* ⚠️ O wrapper dos botões só existe com ALGUM botão: `flex gap-6px`
                    vazio ao lado do título é casca decorativa. */}
                {reprovar === null && aprovar === null ? null : (
                    <span className={CLASSES_ACOES}>
                        {reprovar === null ? null : (
                            <button type="button" className={CLASSES_REPROVAR} onClick={() => reprovar()}>
                                {REPROVAR}
                            </button>
                        )}
                        {aprovar === null ? null : (
                            <button type="button" className={CLASSES_APROVAR} onClick={() => aprovar()}>
                                {APROVAR_FOTO}
                            </button>
                        )}
                    </span>
                )}
            </div>

            <VerificacoesAutomaticas verificacoes={dados.verificacoes} />
        </div>
    );
}
