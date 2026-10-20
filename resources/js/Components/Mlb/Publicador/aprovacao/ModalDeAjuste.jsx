import { cn } from '@/lib/utils';
import CascaDeModal from './CascaDeModal';
import {
    inteiroDoPainel,
    listaDoPainel,
    objetoDoPainel,
    textoDoPainel,
} from './ImagemGrandeDoPainel';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 188, plano 11 — MOD-01: "Solicitar ajustes" (`:782`-`:794`).
//
// Critério 12: pedir ajuste move as ofertas afetadas para `changes` conforme o
// ESCOPO do item, e a caixa "Encaminhar para" DIZ o destino antes do clique.
//
// ═══ ⚠️⚠️ A CASCA É IMPORTADA, NUNCA RECRIADA (critério 15 / MOD-05) ══════
//
// `CascaDeModal.jsx` nasceu na **Fase 187, plano 09** (o modal de lote precisava
// dela) e está **EM PRODUÇÃO**. O Coverage Map atribui a "casca comum" à Fase 188,
// mas ela já tinha um usuário REAL — então a 188 **importa**. ⚠️ Duas cascas
// divergiriam, e a divergente seria descoberta por alguém vendo dois modais com
// raios diferentes na mesma tela. ➡️ Este arquivo **não** declara backdrop, caixa
// de 560px, topo nem rodapé: eles vêm de lá, e há gate provando que nenhum outro
// arquivo de `aprovacao/` declara `w-[560px]`, `pt-[96px]`,
// `max-h-[calc(100vh-140px)]` ou `shadow-pub-modal`.
// ⚠️ `CascaDeModal.jsx` sai deste plano com `numstat` ZERO.
//
// ═══ ⚠️⚠️ POR QUE `font-pub` É DECLARADO AQUI (critério 15 / REND-05) ═════
//
// *"Conteúdo portado não herda fonte"* — medido na Fase 178 (`ModalNovaPublicacao.jsx:95`-`:97`:
// *"Sem `font-pub` declarado AQUI, o modal inteiro perde a Geist em silêncio"*).
// ⚠️ **MEDIDO nesta sessão:** `CascaDeModal.jsx` **não** declara `font-pub`, e está
// CERTO — ela é renderizada na árvore da página e HERDA. **Mas o painel desta fase
// é PORTAL** (`PainelDeRevisao.jsx`, `createPortal` para o `document.body`), e um
// modal aberto de dentro dele sai da subárvore do módulo e volta a herdar do
// `document.body`, que é `font-sans` (Inter).
// ➡️ **A casca é compartilhada e está em produção; a fonte é declarada AQUI para o
// modal continuar correto tanto dentro do portal do painel quanto fora dele.**
// ⚠️ Acrescentar `font-pub` à casca da 187 seria mudar arquivo em produção para
// resolver um problema que é do CHAMADOR.
//
// ═══ ⚠️⚠️ OS ITENS E OS DESTINOS VÊM DO SERVIDOR ═════════════════════════
//
// `PainelDeRevisaoService::itensDeAjuste()` (plano 188-06) devolve, por item:
// `rotulo`, `escopo`, `destino`, `nota_do_escopo` e `sugestoes`. ⚠️ **A tela NÃO
// tem a tabela de escopos.** Escrevê-la aqui criaria a SEGUNDA VERDADE sobre "o
// que cada item afeta", e a divergente moveria ofertas erradas para `changes`.
// ⚠️ A ORDEM é a do servidor: uma entrada por imagem da galeria (`:1181`) e depois
// os **SETE** fixos de `:1183`-`:1189`, com o **sétimo** alternando por
// `units > 1` (`Composição do kit` × `Categoria`).
// ⚠️⚠️ **SÃO SETE, NÃO OITO** — medido por `sed` nesta sessão (`:1181` é o `.map()`
// das imagens, `:1191` é um `}`), e o `AJUSTE_ITENS_FIXOS` do serviço declara o
// mesmo. A prosa do `188-11-PLAN.md` diz "8 fixos + N imagens, e o 8º ALTERNA":
// está ERRADA, e a `188-FID01.md` §F.1 está certa. Esta é a oitava vez nesta fase
// que a prosa de um plano divergiu do FID01.
//
// ═══ ⚠️ O ÂMBAR DO DESTINO, E POR QUE SÓ NO PRODUTO ══════════════════════
//
// `:1397` (medido; a §F.1 aponta `:1396`, que é a ABERTURA `if (k === 'adjust')`):
// `destColor: it.scope === 'prod' ? '#FBBF24' : '#D4D4D8'`. ⚠️ O produto é o único
// escopo que **afeta todas as ofertas do produto** — por isso ele, e só ele, sai
// em âmbar. ⚠️ E `#FBBF24` é `pub-warn-txt`, `#D4D4D8` é `pub-fase1`: TOK-15, nada
// de hex no código.
// ⚠️⚠️ **A CHAVE DO ESCOPO É `product`, EM INGLÊS, e isso NÃO é descuido:** ela é
// lida de `PubAprovacaoAjuste::ESCOPO_PRODUTO` (`app/Models/PubAprovacaoAjuste.php:59`),
// que está **EM PRODUÇÃO** desde a Fase 186. O `prod` do protótipo é **RECUSADO**
// pelo servidor de propósito (`ESCOPO_DO_PROTOTIPO` é o mapa de conferência, não a
// chave de dado). ➡️ **A CHAVE viaja, o RÓTULO fica no servidor.**
//
// ═══ ⚠️⚠️ A SUGESTÃO CONCATENA — E ACRESCENTA UM PONTO ═══════════════════
//
// `:1397`: `(F.text ? F.text + ' ' : '') + t + '.'`. ⚠️ **Contraintuitivo e fácil de
// omitir:** ela NÃO substitui o que já estava escrito, e o **ponto final** entra.
// Reproduzido, e declarado aqui porque "clicar apagou o que eu tinha escrito" é o
// defeito que a pessoa nunca reporta — ela só para de usar o chip.
//
// ═══ ⚠️ TEXTO VAZIO NÃO ENVIA, E HÁ DUAS PROVAS ══════════════════════════
//
// `:1193`: `if (!(F.text || '').trim()) return;`. ➡️ O botão sai **APAGADO** (a casca
// já o põe `disabled` quando `podeSubmeter !== true`: **cor não é o único sinal**) e
// o clique **não** chama a rota. ⚠️ Só a primeira é cosmética — **a trava real é
// `REGRA_TEXTO_VAZIO` no servidor** (plano 188-06).
// ⚠️ `trim()` e não `!texto`: `'   '` (só espaços) tem de ser recusado, e um
// `if (!texto)` o aceitaria.
//
// ═══ ⚠️ O QUE ESTE ARQUIVO NÃO FAZ ══════════════════════════════════════
//
// ⚠️ **Ele não escreve.** `onSubmeter` é chamado com o pedido pronto, e a escrita
// vai pelo helper único `escreverNoPainel` (W11 / COL-04 / critério 17), que
// acrescenta `versao_esperada` SEMPRE e não engole o 422. ⚠️ **Nunca `router.post`
// direto** — há gate com LISTA CONSTANTE (plano 188-12).
// ⚠️ **Ele não guarda estado.** `item` e `texto` são props: o pai (a página) é o
// dono, pelo mesmo contrato de `SimulacaoDePreco.jsx`. ⚠️ **"O modal está aberto" é
// GESTO e NÃO vai para a URL** — pôr isso na URL faria um link compartilhado abrir
// um modal na cara de outra pessoa.
// ═══════════════════════════════════════════════════════════════════════════

/** `:1395` — o título do modal e o rótulo do botão de submeter, LITERAIS. */
export const TITULO = 'Solicitar ajustes';

export const ROTULO_DO_BOTAO = 'Solicitar ajustes';

/** `:783` — o rótulo do `<select>` de item. */
export const ROTULO_DO_ITEM = 'Item';

/** `:788` — o rótulo da caixa de destino. */
export const ROTULO_DO_DESTINO = 'Encaminhar para';

/** `:791` — o rótulo do `<textarea>`. */
export const ROTULO_DO_COMENTARIO = 'Comentário';

/** `:792` — o placeholder do `<textarea>`, byte a byte. */
export const PLACEHOLDER_DO_COMENTARIO = 'Descreva o que precisa ser corrigido';

/** `:794` — o `'+ ' + t` do chip de sugestão. */
export const PREFIXO_DA_SUGESTAO = '+ ';

/**
 * `:1397` — o que a sugestão acrescenta ao comentário: um ESPAÇO antes (quando já
 * há texto) e um PONTO depois. ⚠️ Separadas em constantes para que a mudança de
 * qualquer uma das duas seja diff VISÍVEL.
 */
export const SEPARADOR_DA_SUGESTAO = ' ';

export const SUFIXO_DA_SUGESTAO = '.';

/**
 * ⚠️ A chave do escopo de PRODUTO, LIDA de `PubAprovacaoAjuste::ESCOPO_PRODUTO`.
 *
 * Ela é inglesa porque o servidor da Fase 186 está **em produção** com ela; o
 * `prod` do protótipo é **recusado** pelo serviço de escrita de propósito.
 */
export const ESCOPO_DO_PRODUTO = 'product';

/** ⚠️ O corpo do modal, e é ele que DECLARA A FONTE DO MÓDULO (ver o docblock). */
export const CLASSES_CORPO_DO_MODAL = 'flex flex-col gap-[14px] font-pub text-pub-txt';

/** `:783`/`:791` — o `<label>` em coluna, com o texto discreto. */
export const CLASSES_ROTULO = 'flex flex-col gap-[6px] text-[12.5px] text-pub-txt-2';

/** `:784` — o `<select>` de item. ⚠️ `rounded-[8px]` não tem token: 8px não existe na escala. */
export const CLASSES_SELECT =
    'h-[36px] rounded-[8px] border border-pub-line-strong bg-pub-sunken px-[10px] text-[13px] text-pub-txt outline-none';

/** `:788` — a caixa "Encaminhar para". */
export const CLASSES_CAIXA_DO_DESTINO =
    'flex items-center gap-[10px] rounded-pub-control border border-pub-line-subtle bg-pub-sunken px-[12px] py-[10px] text-[12.5px]';

/** `:788` — o rótulo dentro da caixa. */
export const CLASSES_ROTULO_DO_DESTINO = 'text-pub-txt-4';

/** `:788` — o destino. ⚠️ A cor é a do escopo (ver `CORES_DO_DESTINO`). */
export const CLASSES_DESTINO = 'font-semibold';

/** `:788` — a nota do escopo, empurrada para a direita. */
export const CLASSES_NOTA_DO_DESTINO = 'ml-auto text-[11.5px] text-pub-txt-3';

/**
 * `:1397` — as DUAS cores do destino, por escopo.
 *
 * ⚠️ Mapa, e não ternário solto, para que o gate possa provar os DOIS ramos: um
 * gate que só olhasse um passaria com a cor FIXA.
 */
export const CORES_DO_DESTINO = {
    produto: 'text-pub-warn-txt',
    outros: 'text-pub-fase1',
};

/** `:792` — o `<textarea>` de comentário. */
export const CLASSES_TEXTAREA =
    'resize-y rounded-[8px] border border-pub-line-strong bg-pub-sunken px-[12px] py-[10px] text-[13px] leading-[1.45] text-pub-txt outline-none';

/** `:792` — `rows="3"`, do protótipo. */
export const LINHAS_DO_TEXTAREA = 3;

/** `:794` — o wrapper dos chips, com o `flex-wrap:wrap` que a tabela omitia. */
export const CLASSES_FAIXA_DE_SUGESTOES = 'flex flex-wrap gap-[6px]';

/** `:794` — o chip de sugestão. ⚠️ `cursor-pointer` e `whitespace-nowrap` são as omissões da tabela. */
export const CLASSES_SUGESTAO =
    'h-[26px] cursor-pointer whitespace-nowrap rounded-full border border-pub-line-strong bg-pub-sunken px-[9px] text-[11.5px] text-pub-fase1';

/**
 * O item escolhido, saneado.
 *
 * ⚠️ `indice` chega da pessoa (um `<select>`) e pode vir qualquer coisa. Índice
 * fora da lista cai no PRIMEIRO item, que é o que o protótipo faz (`:1396`:
 * `items[F.item || 0] || items[0]`) — nunca `undefined`, que faria a caixa de
 * destino desenhar vazia.
 */
export function itemEscolhido(itens, indice) {
    const lista = listaDoPainel(itens).map((item) => objetoDoPainel(item)).filter((item) => item !== null);
    if (lista.length === 0) return null;

    const pedido = inteiroDoPainel(indice);
    const posicao = pedido !== null && pedido >= 0 && pedido < lista.length ? pedido : 0;

    return { lista, posicao, item: lista[posicao] };
}

/**
 * ⚠️ `:1193` — texto vazio ou só espaços NÃO ENVIA.
 *
 * Named export porque é ela que o gate exercita nas duas pontas, e porque é a
 * MESMA decisão que o botão (apagado) e o clique (não dispara) consultam — uma
 * casa só, nunca duas condições parecidas.
 */
export function comentarioValido(texto) {
    return textoDoPainel(texto) !== null;
}

/**
 * ⚠️ `:1397` — o comentário DEPOIS de clicar numa sugestão. CONCATENA, e põe ponto.
 *
 * @param {unknown} texto o que já estava escrito
 * @param {unknown} sugestao o rótulo do chip
 * @returns {?string} o texto novo, ou `null` quando a sugestão não é texto
 */
export function comentarioComSugestao(texto, sugestao) {
    const nova = textoDoPainel(sugestao);
    if (nova === null) return null;

    const antes = typeof texto === 'string' && texto !== '' ? texto + SEPARADOR_DA_SUGESTAO : '';

    return antes + nova + SUFIXO_DA_SUGESTAO;
}

/**
 * O corpo do modal de ajuste — ⚠️ NAMED EXPORT, exercido direto pelos gates.
 *
 * @param {object} props
 * @param {unknown} [props.itens] `modais.ajuste.itens` do servidor
 * @param {unknown} [props.indice] qual item está escolhido
 * @param {unknown} [props.texto] o comentário digitado
 * @param {unknown} [props.onItem] `(indice) => void`
 * @param {unknown} [props.onTexto] `(texto) => void`
 */
export function CorpoDoAjuste({
    itens = null,
    indice = 0,
    texto = '',
    onItem = null,
    onTexto = null,
}) {
    const escolha = itemEscolhido(itens, indice);
    if (escolha === null) return null;

    const trocarItem = typeof onItem === 'function' ? onItem : null;
    const escreverTexto = typeof onTexto === 'function' ? onTexto : null;

    const destino = textoDoPainel(escolha.item.destino);
    const nota = textoDoPainel(escolha.item.nota_do_escopo);

    // ⚠️ `:1397` — o âmbar é SÓ do escopo de produto. A decisão é tomada aqui, UMA
    // vez, e não dentro do `.map()`: ela não depende do item iterado.
    const corDoDestino = escolha.item.escopo === ESCOPO_DO_PRODUTO
        ? CORES_DO_DESTINO.produto
        : CORES_DO_DESTINO.outros;

    return (
        <div className={CLASSES_CORPO_DO_MODAL}>
            <label className={CLASSES_ROTULO}>
                {ROTULO_DO_ITEM}
                {/* ⚠️ `value` só com `onChange`: campo controlado sem handler é
                    controle MORTO, e o React avisa. Sem transporte ele vira
                    `defaultValue` — ⚠️ e NUNCA `String(valor)`, que LANÇA com
                    `{toString:null}` (há gate da 187-04 proibindo a forma). */}
                <select
                    className={CLASSES_SELECT}
                    {...(trocarItem === null
                        ? { defaultValue: escolha.posicao }
                        : { value: escolha.posicao })}
                    onChange={trocarItem === null ? undefined : (evento) => {
                        // ⚠️ Flag DENTRO do callback (o Rollup elide variável de
                        // escopo do componente lida de dentro de closure de `.map`).
                        const cru = evento?.target?.value;
                        const numero = typeof cru === 'string' ? Number.parseInt(cru, 10) : null;

                        trocarItem(Number.isInteger(numero) ? numero : 0);
                    }}
                >
                    {escolha.lista.map((item, posicao) => {
                        // ⚠️ Tudo que esta expressão lê é PARÂMETRO ou nome de
                        // MÓDULO, que o Rollup nunca elide.
                        const rotulo = textoDoPainel(item.rotulo);

                        return (
                            <option key={`item-${posicao}`} value={posicao}>
                                {rotulo ?? ''}
                            </option>
                        );
                    })}
                </select>
            </label>

            {/* ⚠️ Sem destino a caixa NÃO é desenhada: caixa que diz "Encaminhar
                para" e não diz para onde é D4 violado. */}
            {destino === null ? null : (
                <div className={CLASSES_CAIXA_DO_DESTINO}>
                    <span className={CLASSES_ROTULO_DO_DESTINO}>{ROTULO_DO_DESTINO}</span>
                    <span className={cn(CLASSES_DESTINO, corDoDestino)}>{destino}</span>
                    {nota === null ? null : <span className={CLASSES_NOTA_DO_DESTINO}>{nota}</span>}
                </div>
            )}

            <label className={CLASSES_ROTULO}>
                {ROTULO_DO_COMENTARIO}
                <textarea
                    className={CLASSES_TEXTAREA}
                    rows={LINHAS_DO_TEXTAREA}
                    placeholder={PLACEHOLDER_DO_COMENTARIO}
                    {...(escreverTexto === null
                        ? { defaultValue: typeof texto === 'string' ? texto : '' }
                        : { value: typeof texto === 'string' ? texto : '' })}
                    onChange={escreverTexto === null ? undefined : (evento) => {
                        const cru = evento?.target?.value;

                        escreverTexto(typeof cru === 'string' ? cru : '');
                    }}
                />
            </label>

            {/* ⚠️ Sem sugestão a faixa não nasce — faixa vazia é casca decorativa. */}
            {listaDoPainel(escolha.item.sugestoes).length === 0 ? null : (
                <div className={CLASSES_FAIXA_DE_SUGESTOES}>
                    {listaDoPainel(escolha.item.sugestoes).map((sugestao, posicao) => {
                        // ⚠️ Flag DENTRO do callback.
                        const rotulo = textoDoPainel(sugestao);
                        if (rotulo === null) return null;

                        return (
                            <button
                                key={`sugestao-${posicao}`}
                                type="button"
                                className={CLASSES_SUGESTAO}
                                onClick={escreverTexto === null ? undefined : () => {
                                    const novo = comentarioComSugestao(texto, rotulo);
                                    if (novo === null) return;

                                    escreverTexto(novo);
                                }}
                            >
                                {PREFIXO_DA_SUGESTAO + rotulo}
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

/**
 * O modal "Solicitar ajustes" (MOD-01), **dentro da casca da Fase 187**.
 *
 * @param {object} props
 * @param {unknown} [props.aberto] só `true` desenha
 * @param {unknown} [props.sub] o título da oferta (`:1397`, `sub: this.titleOf(o.id)`)
 * @param {unknown} [props.itens] `modais.ajuste.itens`
 * @param {unknown} [props.indice] o item escolhido
 * @param {unknown} [props.texto] o comentário digitado
 * @param {unknown} [props.onItem] `(indice) => void`
 * @param {unknown} [props.onTexto] `(texto) => void`
 * @param {unknown} [props.onFechar] `() => void`
 * @param {unknown} [props.onSubmeter] `({escopo, item, texto}) => void` — ⚠️ a
 *   escrita é do CHAMADOR, pelo helper único `escreverNoPainel`
 */
export default function ModalDeAjuste({
    aberto = false,
    sub = null,
    itens = null,
    indice = 0,
    texto = '',
    onItem = null,
    onTexto = null,
    onFechar = null,
    onSubmeter = null,
}) {
    const escolha = itemEscolhido(itens, indice);

    // ⚠️ Sem item não há o que pedir: o modal **não abre**, nem com casca vazia.
    // Um `<select>` sem opção é um botão sem destino disfarçado.
    if (aberto !== true || escolha === null) return null;

    const pode = comentarioValido(texto);
    const enviar = typeof onSubmeter === 'function' ? onSubmeter : null;

    return (
        <CascaDeModal
            aberto
            titulo={TITULO}
            sub={textoDoPainel(sub) ?? undefined}
            rotuloDoBotao={ROTULO_DO_BOTAO}
            podeSubmeter={pode}
            onFechar={onFechar}
            onSubmeter={enviar === null ? null : () => {
                // ⚠️ A guarda é REPETIDA aqui de propósito: a casca já desabilita o
                // botão, mas `onSubmeter` é named export e pode ser chamado direto —
                // e a régua é "o clique NÃO chama a rota", não "o botão está cinza".
                if (!comentarioValido(texto)) return;

                enviar({
                    escopo: escolha.item.escopo,
                    item: textoDoPainel(escolha.item.rotulo),
                    texto,
                });
            }}
        >
            <CorpoDoAjuste
                itens={itens}
                indice={indice}
                texto={texto}
                onItem={onItem}
                onTexto={onTexto}
            />
        </CascaDeModal>
    );
}
