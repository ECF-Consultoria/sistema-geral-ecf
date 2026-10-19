import { cn } from '@/lib/utils';
import {
    BORDA_DA_ORIGEM,
    TEXTO_DA_COR,
    classeDaCorDoPreco,
    listaDoPainel,
    objetoDoPainel,
    textoDoPainel,
} from './KpisDoPainel';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 188, plano 09 — A COMPOSIÇÃO COMPLETA DA CONTA (`:654`-`:667`): o
// cabeçalho com o preço, as 7 LINHAS com SELO DE ORIGEM, o rodapé do resultado,
// a nota do frete do comprador, a frase do PIOR CASO e "Informar frete
// confirmado".
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas, sha256 68624883ce35547e…). ⚠️ **CITE O DIRETÓRIO:** há DUAS
// cópias do pacote de handoff com conteúdo DIFERENTE.
//
// ═══ ⚠️⚠️ DADO-13 / VOC-04 — A NOTA NOMEIA A FONTE, E NUNCA É VAZIA ═════════
//
// Cada linha carrega uma `nota` que diz **de onde o número veio** (*"ERP Bling ·
// atualizado 11 out"*, *"tarifa real do Mercado Livre"*, *"parâmetro da
// empresa"*), e ela vai no `title` do selo de origem (`:659`). ⚠️ Nota vazia é
// **ERRO**, não decoração: `LinhaFinanceira::REGRA_NOTA_VAZIA` prova isso no
// servidor. ➡️ Aqui o `title` **só nasce com texto**: `title=""` é um `title`
// AUSENTE, e elemento sem nome acessível **passa em render e desaparece na
// tela** (conserto medido na wave 187-08).
//
// ═══ ⚠️⚠️ AS TRÊS ORIGENS TÊM SEIS CORES, DISTINTAS AOS PARES ══════════════
//
// `:1125` — `conf → ['#4ADE80','rgba(34,197,94,0.35)']` ·
// `est → ['#D4D4D8','#3F3F46']` · `miss → ['#F87171','rgba(239,68,68,0.45)']`.
// ⚠️ Duas iguais passariam num teste de EXISTÊNCIA e apagariam o sinal: o que
// separa *Confirmado* de *Pendente* é que o primeiro diz que o número foi
// conferido e o segundo diz que **ele não existe**.
// ⚠️ As chaves semânticas vêm do servidor (`origem_cor`/`origem_borda`,
// TOK-15: nenhum hex sai do PHP) e as classes resolvidas têm de bater, origem
// por origem, com `SELOS_DE_ORIGEM` de `vocabularioDoPortao.jsx` — que é a casa
// do vocabulário. **Há gate provando a igualdade das duas**, para que nunca
// existam duas paletas para a mesma coisa.
//
// ═══ ⚠️⚠️ O VALOR TEM TRÊS FORMAS, E ELAS SÃO DO MESMO CAMPO ═══════════════
//
// `:1126` — `r.v == null ? 'Pendente' : brl(r.v === 0 ? 0 : -r.v)`:
//   · custo positivo é **EXIBIDO NEGATIVO** (é o que sai do preço);
//   · **zero sai SEM sinal** e em neutro (*"Outros custos"* nasce R$ 0,00
//     `Confirmado`, e `− R$ 0,00` seria a tela inventando um sinal);
//   · `null` sai como **o TEXTO** `Pendente`, em vermelho — e esse texto é o
//     MESMO que o selo mostra, porque os dois saem de
//     `LinhaFinanceira::rotuloDaOrigem()`. Duas redações divergiriam.
// ⚠️ **A formatação é TODA do servidor.** O `brl()` dele imprime **U+2212 MINUS
// SIGN seguido de espaço** (`E2 88 92 20`), e não o hífen-menos — trocar passa
// desapercebido na tela. ➡️ Não existe um segundo formatador aqui.
//
// ═══ ⚠️⚠️ A ORDEM DAS 7 LINHAS É DO SERVIDOR, E A TELA NÃO REORDENA ════════
//
// `PrecoDoPainelService::ORDEM_DAS_LINHAS` é `array_keys(
// PubAprovacaoComposicao::LINHAS)` — custo · comissão · tarifa fixa · frete ·
// imposto · embalagem · outros. Uma segunda ordenação aqui seria a terceira
// lista do mesmo fato, e a divergente seria a que a pessoa lê.
//
// ═══ ⚠️⚠️ AS LINHAS INFORMATIVAS APARECEM E NÃO ENTRAM NA SOMA (FIN-08) ════
//
// O frete pago pelo **comprador** não entra no resultado, e por isso ele vem em
// `informativas`, separado de `linhas`. ⚠️ Misturar os dois faria o rodapé
// discordar da própria lista que está acima dele.
//
// ═══ ⚠️⚠️ DIVERGÊNCIA DECLARADA (1): `:665` TEM DUAS FRASES, E A SEGUNDA É
//     FALSA NESTE PRODUTO ═══════════════════════════════════════════════════
//
// O literal do protótipo é *"Frete pago pelo comprador não entra no resultado.
// **Taxas de comissão são exemplos configuráveis.**"* — e a segunda frase é
// **disclaimer da ficção do próprio protótipo** (`:1064`, *"taxa de exemplo"*,
// `src:'est'`). Aqui a comissão é a **tarifa REAL do Mercado Livre** (decisão 9),
// então reproduzi-la seria a tela **afirmando o que não é**.
// ➡️ **Só a primeira frase sai, byte a byte**, e ela já vem assim de
// `PrecoDoPainelService::NOTA_DO_FRETE_DO_COMPRADOR`. ⚠️ Não é decisão nova: a
// `188-FID01.md` §3 governa (*"o protótipo ganha na FÓRMULA, nos RÓTULOS e na
// ORDEM, NUNCA nos NÚMEROS"*) e a Fase 186 já fez a mesma tradução, em produção,
// em `ComposicaoFinanceiraService::linhaInformativaDoFreteDoComprador()`.
//
// ═══ ⚠️⚠️ DIVERGÊNCIA DECLARADA (2): O `freightMissing` DO PROTÓTIPO É O
//     `missing` INTEIRO ═════════════════════════════════════════════════════
//
// `:1143` acende *"Informar frete confirmado"* quando **qualquer** dado falta —
// inclusive quando o que falta é o **custo do produto**, mandando a pessoa ao
// lugar errado. A Fase 186 já consertou essa classe em
// `BloqueiosDaOfertaService::freteOuCusto()` (*"dizer 'frete' quando o que falta
// é o custo do produto seria a tela afirmando o que não é"*).
// ➡️ Aqui chegam **DOIS dados separados**: `incompleto` e `frete_ausente`. O
// botão só nasce com `frete_ausente`, e **o destino NÃO é cravado aqui**:
// `frete_ausente` tem DOIS destinos (modal de frete ou modal de ajustes), já
// resolvidos por `PainelDeRevisaoService::destino()`.
//
// ═══ ⚠️⚠️ B2 — O BOTÃO DE FRETE É DESENHADO AQUI, COM PROP, E O 188-12 O
//     RELIGA ═══════════════════════════════════════════════════════════════
//
// A primeira redação do plano dizia *"se o 188-12 ainda não rodou, o botão não é
// desenhado"* — e como o 188-12 **não listava este arquivo**, o botão **nunca**
// seria ligado e o critério 14 ficaria inalcançável. ➡️ A forma certa, no
// contrato de nó já usado neste módulo: `onInformarFrete` chega **função ou
// `null`**, e o botão nasce **só** quando ele é função **E** o frete está
// faltando. São QUATRO combinações e **só uma** desenha o botão.
// ⚠️ **Nada de `onClick` vazio, nada de `disabled` decorativo** (D4).
//
// ═══ ⚠️ DECISÃO 2 + DECISÃO P1 DO USUÁRIO (15/10) ══════════════════════════
//
// A frase do **pior caso entre N variantes** é **VISÍVEL no painel**. Na fila
// (187-08) ela é só `title`, porque a coluna de preço tem 92px e UMA linha —
// *"acrescentar segunda linha na coluna de preço"* foi **oferecido e RECUSADO**.
// ➡️ Aqui ela é TEXTO, lido de `preco.nota` (que é
// `ComposicaoDaOferta::$notaDoPreco`). ⚠️ **Com um preço só a nota é `null` e
// NÃO há frase** — nunca *"pior caso entre 1 variante"*.
// ⚠️ **DIVERGÊNCIA (3), DECLARADA: o protótipo NÃO TEM este elemento** (a
// `188-FID01.md` §D o registra como *"não existe no protótipo"*). Ele nasce no
// IDIOMA MEDIDO de `:665` — nota solta embaixo da caixa, `font-size:11.5px`,
// `color:#71717A`, `margin-top:-4px` —, porque é o único idioma de "nota da
// composição" que o protótipo define. **Nada medido foi encolhido para caber.**
//
// ═══ ⚠️ FIN-05 / FIN-06 / D4 — CUSTO OU FRETE AUSENTE NUNCA VIRA ZERO ══════
//
// O resultado sai `Incompleto` e a margem sai `—`. ⚠️ **Zero afirmaria que é de
// graça**, e é por isso que `Pendente` é TEXTO e não `R$ 0,00`.
//
// ═══ ⚠️ A EXCEÇÃO DELIBERADA DA EMBALAGEM (decisão 3 do usuário) ════════════
//
// *"Embalagem"* nasce `Pendente` e **NÃO anula** o resultado — sem essa exceção
// **100%** das ofertas diriam "Incompleto" e nada seria aprovável. O resultado
// sai marcado **estimado por baixo**, e prejuízo/margem **DISPARAM** sobre ele.
// ⚠️ O **FRETE** ausente, no MESMO cenário, **ANULA** — é a isca que separa as
// duas, e ela está no gate.
// ⚠️⚠️ **E o sinal do "estimado por baixo" viaja por UMA fonte só:**
// `nota_da_margem` já termina em `· sem o custo de embalagem`
// (`ComposicaoDaOferta:271`-`:275`). Desenhá-lo DE NOVO aqui poria o MESMO fato
// em DUAS fontes — e fato repetido em dois lugares já divergiu neste projeto (é
// a razão da decisão 9, das duas margens, e da PERGUNTA-188-D, em que *"toast +
// texto fixo"* foi OFERECIDO e RECUSADO).
//
// ⚠️ Nenhum `String(valor)`, nenhum `hasOwnProperty` solto e nenhum
// `obj[chaveDoServidor]` cru: os três **LANÇAM** com `{toString: null}`.
// ⚠️⚠️ E **nenhuma aritmética de taxa** mora aqui — a tela EXIBE, não calcula.
// ═══════════════════════════════════════════════════════════════════════════

// ─── As classes, uma casa só (`:654`-`:667`) ───────────────────────────────

/** `:654` — a caixa da composição, com `overflow:hidden` por causa dos cantos. */
export const CLASSES_CAIXA = 'flex flex-col overflow-hidden rounded-pub-sub border border-pub-line-subtle';

/** `:655` — o cabeçalho com o preço de venda. */
export const CLASSES_CABECALHO = 'flex justify-between bg-pub-sunken px-[12px] py-[8px] text-[12.5px]';

/** `:655` — o rótulo do cabeçalho. */
export const CLASSES_CABECALHO_ROTULO = 'text-pub-txt-2';

/** `:655` — o valor do cabeçalho. */
export const CLASSES_CABECALHO_VALOR = 'font-semibold tabular-nums';

/**
 * ⚠️⚠️ `:657` — A LINHA. Igualdade de STRING com isca do quase-igual.
 *
 * ⚠️ **DIVERGÊNCIA DO `<behavior>` DO PLANO, MEDIDA POR `sed`:** o plano escreve
 * `border-pub-line-subtle-2`, que é **#1C1C1C** — e `:657` mede
 * `border-top:1px solid #1A1A1A`. O token de #1A1A1A nesta camada é
 * `pub-hover-3`, e usá-lo como BORDA é o precedente já em produção
 * (`LinhaDaOferta.jsx:205`, `ModalDeLote.jsx:40`,
 * `visaogeral/JornadaDosProdutos.jsx:427`). ➡️ O protótipo ganha no VALOR
 * (`188-FID01.md` §3), e a §D deste documento concorda com ele.
 */
export const CLASSES_LINHA = 'grid grid-cols-[minmax(0,1fr)_auto_92px] items-center gap-[10px] '
    + 'border-t border-pub-hover-3 px-[12px] py-[7px] text-[12.5px]';

/**
 * `:658` — o wrapper do rótulo + detalhe.
 *
 * ⚠️⚠️ **`min-w-0` É LOAD-BEARING, e a tabela FID-01 o OMITIA** (é a família de
 * omissão que a Fase 187 cometeu onze vezes): sem ele o `truncate` do detalhe
 * **não trunca** — o item de grade tem `min-width:auto` por default e estoura a
 * coluna de `minmax(0,1fr)` em vez de cortar o texto.
 * ⚠️ E o `gap:1px` também estava fora da tabela. Medido em `:658`.
 */
export const CLASSES_LINHA_TEXTO = 'flex min-w-0 flex-col gap-[1px]';

/** `:658` — o rótulo da linha. */
export const CLASSES_LINHA_ROTULO = 'text-pub-fase1';

/**
 * `:658` — o detalhe, com os TRÊS de `truncate`.
 *
 * ⚠️ `truncate` é EXATAMENTE `overflow:hidden` + `text-overflow:ellipsis` +
 * `white-space:nowrap`, e `white-space:nowrap` foi omitido SETE vezes na Fase
 * 187. Aqui ele é medido em `:658`, junto com o `text-overflow:ellipsis`.
 */
export const CLASSES_LINHA_DETALHE = 'truncate text-[11px] text-pub-txt-4';

/** `:659` — o selo de origem. ⚠️ `white-space:nowrap` é a ÚLTIMA propriedade da linha. */
export const CLASSES_SELO = 'whitespace-nowrap rounded-pub-badge border px-[6px] py-[2px] text-[10.5px] font-semibold';

/** `:660` — o valor da linha, alinhado à direita na coluna de 92px. */
export const CLASSES_LINHA_VALOR = 'text-right font-medium tabular-nums';

/** `:663` — o rodapé do resultado. ⚠️ O `justify-between` e o `items-center` a tabela OMITIA. */
export const CLASSES_RODAPE = 'flex items-center justify-between border-t border-pub-line '
    + 'bg-pub-sunken px-[12px] py-[10px] text-[13px]';

/** `:663` — o rótulo do rodapé. */
export const CLASSES_RODAPE_ROTULO = 'font-semibold';

/** `:663` — o valor do rodapé, mais pesado que o das linhas. */
export const CLASSES_RODAPE_VALOR = 'font-bold tabular-nums';

/** `:665` — a nota solta embaixo da caixa. ⚠️ `margin-top:-4px` é da medida. */
export const CLASSES_NOTA = '-mt-[4px] text-[11.5px] text-pub-txt-4';

/** `:667` — "Informar frete confirmado". ⚠️ `white-space:nowrap` é a 1ª propriedade. */
export const CLASSES_FRETE = 'h-[30px] cursor-pointer self-start whitespace-nowrap rounded-pub-control-sm '
    + 'border border-pub-danger/[0.5] bg-pub-danger/[0.08] px-[12px] text-[12.5px] font-semibold text-pub-danger-txt';

/**
 * ⚠️ A ORDEM das 7 linhas, para o gate provar que a tela **não reordena**.
 *
 * Ela é `PrecoDoPainelService::ORDEM_DAS_LINHAS`, que por sua vez é
 * `array_keys(PubAprovacaoComposicao::LINHAS)`. **Esta lista não monta nada** —
 * se montasse, seria a terceira cópia do mesmo fato.
 */
export const ORDEM_DAS_LINHAS = ['custo', 'comissao', 'tarifa_fixa', 'frete', 'imposto', 'embalagem', 'outros'];

/**
 * UMA linha da composição: rótulo + detalhe · selo de origem · valor.
 *
 * ⚠️ NAMED EXPORT para o gate exercer o caso adverso sem montar a árvore toda.
 *
 * @param {object} props
 * @param {unknown} [props.linha] uma entrada de `composicao.linhas` ou de
 *   `composicao.informativas` (plano 188-04)
 */
export function LinhaDaComposicao({ linha = null }) {
    // ⚠️ Flag DENTRO do componente — o Rollup elimina variável de escopo do
    // componente usada dentro do `.map()` do pai.
    const dado = objetoDoPainel(linha);
    if (dado === null) return null;

    const rotulo = textoDoPainel(dado.rotulo);
    const detalhe = textoDoPainel(dado.detalhe);
    const valor = textoDoPainel(dado.valor);
    const selo = textoDoPainel(dado.origem_rotulo);

    // ⚠️ Linha sem rótulo E sem valor não nasce: no grid de três colunas ela
    // apareceria como uma faixa de 7px com uma borda em cima (D4).
    if (rotulo === null && valor === null) return null;

    // ⚠️ `title` só com TEXTO: `title=""` é um `title` AUSENTE, e a nota NUNCA é
    // vazia por regra do servidor (DADO-13).
    const nota = textoDoPainel(dado.nota);
    const corDoValor = classeDaCorDoPreco(TEXTO_DA_COR, dado.valor_cor, TEXTO_DA_COR.clara);
    const corDoSelo = classeDaCorDoPreco(TEXTO_DA_COR, dado.origem_cor, TEXTO_DA_COR.clara);
    const bordaDoSelo = classeDaCorDoPreco(BORDA_DA_ORIGEM, dado.origem_borda, BORDA_DA_ORIGEM.trilha);

    return (
        <div className={CLASSES_LINHA}>
            <span className={CLASSES_LINHA_TEXTO}>
                {rotulo === null ? null : <span className={CLASSES_LINHA_ROTULO}>{rotulo}</span>}
                {detalhe === null ? null : <span className={CLASSES_LINHA_DETALHE}>{detalhe}</span>}
            </span>

            {/* ⚠️ DADO-13 — a nota NOMEIA a fonte e vai no `title` do selo. */}
            {selo === null ? null : (
                <span title={nota ?? undefined} className={cn(CLASSES_SELO, corDoSelo, bordaDoSelo)}>
                    {selo}
                </span>
            )}

            {valor === null ? null : <span className={cn(CLASSES_LINHA_VALOR, corDoValor)}>{valor}</span>}
        </div>
    );
}

/**
 * ⚠️⚠️ A DECISÃO DO BOTÃO DE FRETE, EM UMA CASA SÓ.
 *
 * **AS QUATRO COMBINAÇÕES, E SÓ UMA DESENHA:** frete faltando × não faltando,
 * handler função × `null`. Botão sem destino é D4 violado, e desenhá-lo com o
 * frete presente mandaria a pessoa a um modal que não tem o que resolver.
 *
 * ⚠️ Ela é FUNÇÃO PURA e `export` de propósito: o componente **e** o pai (que
 * precisa saber se há algo a desenhar antes de montar a casca) leem a MESMA
 * decisão. Duas condições iguais escritas em dois lugares divergiriam no
 * primeiro ajuste — foi assim que o botão de `:667` ficou inalcançável na
 * primeira redação do plano.
 *
 * @returns {?string} o rótulo do botão, ou `null` quando ele não nasce
 */
export function rotuloDoBotaoDeFrete(frete, freteAusente, onInformarFrete) {
    if (freteAusente !== true || typeof onInformarFrete !== 'function') return null;

    const dado = objetoDoPainel(frete);

    return dado === null ? null : textoDoPainel(dado.rotulo_acao);
}

/**
 * `:667` — o botão de frete.
 *
 * ⚠️ O rótulo é `frete.rotulo_acao`, do servidor — a tela não o escreve.
 * ⚠️ E o DESTINO **não é cravado aqui**: `frete_ausente` tem DOIS destinos
 * (modal de frete ou modal de ajustes), resolvidos por
 * `PainelDeRevisaoService::destino()`. Quem fornece o handler é o 188-12.
 */
export function BotaoDeFrete({ frete = null, freteAusente = false, onInformarFrete = null }) {
    const rotulo = rotuloDoBotaoDeFrete(frete, freteAusente, onInformarFrete);
    if (rotulo === null) return null;

    return (
        <button type="button" className={CLASSES_FRETE} onClick={() => onInformarFrete()}>
            {rotulo}
        </button>
    );
}

/**
 * A composição completa da conta.
 *
 * @param {object} props
 * @param {unknown} [props.composicao] `preco.composicao` (plano 188-04)
 * @param {unknown} [props.preco] `preco.preco` — de onde sai a frase do PIOR
 *   CASO (`nota`), decisão 2 + decisão P1 do usuário
 * @param {unknown} [props.frete] `preco.frete` — `{rotulo_acao}` ou `null`
 * @param {unknown} [props.freteAusente] `preco.frete_ausente` — ⚠️ o dado
 *   PRECISO, não o `missing` inteiro do protótipo (divergência 2)
 * @param {unknown} [props.onInformarFrete] `() => void`, ou `null` — o modal é
 *   do plano **188-12**, que RELIGA este handler (B2)
 */
export default function ComposicaoFinanceiraDoPainel({
    composicao = null,
    preco = null,
    frete = null,
    freteAusente = false,
    onInformarFrete = null,
}) {
    const dados = objetoDoPainel(composicao);
    const doPreco = objetoDoPainel(preco);

    // ⚠️ A frase do pior caso vive FORA da caixa e sobrevive a ela: a nota é do
    // PREÇO, não da composição.
    const notaDoPreco = doPreco === null ? null : textoDoPainel(doPreco.nota);

    const cabecalho = dados === null ? null : objetoDoPainel(dados.cabecalho);
    const rodape = dados === null ? null : objetoDoPainel(dados.rodape);
    const linhas = dados === null ? [] : listaDoPainel(dados.linhas);
    const informativas = dados === null ? [] : listaDoPainel(dados.informativas);
    const nota = dados === null ? null : textoDoPainel(dados.nota);

    const temCaixa = cabecalho !== null || rodape !== null || linhas.length > 0 || informativas.length > 0;
    // ⚠️ A MESMA decisão que o componente do botão usa — uma casa só.
    const temBotao = rotuloDoBotaoDeFrete(frete, freteAusente, onInformarFrete) !== null;

    // ⚠️ Sem caixa, sem notas e sem botão não se desenha nada: a decisão vem
    // ANTES da casca, porque componente que devolve `null` por dentro **já
    // desenhou a casca por fora** (D4).
    if (!temCaixa && nota === null && notaDoPreco === null && !temBotao) return null;

    const corDoRodape = rodape === null
        ? TEXTO_DA_COR.texto
        : classeDaCorDoPreco(TEXTO_DA_COR, rodape.cor, TEXTO_DA_COR.texto);

    return (
        <>
            {!temCaixa ? null : (
                <div className={CLASSES_CAIXA}>
                    {cabecalho === null ? null : (
                        <div className={CLASSES_CABECALHO}>
                            <span className={CLASSES_CABECALHO_ROTULO}>{textoDoPainel(cabecalho.rotulo)}</span>
                            <span className={CLASSES_CABECALHO_VALOR}>{textoDoPainel(cabecalho.valor)}</span>
                        </div>
                    )}

                    {/* ⚠️ AS LINHAS DA CONTA, na ordem do servidor. */}
                    {linhas.map((linha, indice) => {
                        // ⚠️⚠️ Flag DENTRO do callback: o que ele lê é PARÂMETRO
                        // (`linha`, `indice`) e nome de MÓDULO (`LinhaDaComposicao`,
                        // `objetoDoPainel`), que o Rollup nunca elide.
                        const chave = objetoDoPainel(linha)?.chave;

                        return (
                            <LinhaDaComposicao
                                key={typeof chave === 'string' && chave !== '' ? chave : `linha-${indice}`}
                                linha={linha}
                            />
                        );
                    })}

                    {/* ⚠️ O RODAPÉ mostra o RESULTADO, não o total — e ele vem do
                        MESMO KPI que a grade de cima exibe. Recalculá-lo criaria
                        dois números para o mesmo fato. */}
                    {rodape === null ? null : (
                        <div className={CLASSES_RODAPE}>
                            <span className={CLASSES_RODAPE_ROTULO}>{textoDoPainel(rodape.rotulo)}</span>
                            <span className={cn(CLASSES_RODAPE_VALOR, corDoRodape)}>{textoDoPainel(rodape.valor)}</span>
                        </div>
                    )}

                    {/* ⚠️ FIN-08 — as INFORMATIVAS aparecem e NÃO entram na soma, e
                        é por isso que elas vêm DEPOIS do rodapé: antes dele, a
                        pessoa somaria o frete do comprador junto. */}
                    {informativas.map((linha, indice) => {
                        const chave = objetoDoPainel(linha)?.chave;

                        return (
                            <LinhaDaComposicao
                                key={typeof chave === 'string' && chave !== '' ? chave : `informativa-${indice}`}
                                linha={linha}
                            />
                        );
                    })}
                </div>
            )}

            {/* ⚠️ Decisão 2 + P1 — o PIOR CASO é VISÍVEL, e com um preço só NÃO
                existe. A frase vem de `ComposicaoDaOferta::$notaDoPreco`. */}
            {notaDoPreco === null ? null : <span className={CLASSES_NOTA}>{notaDoPreco}</span>}

            {/* ⚠️ Divergência (1) — SÓ a primeira frase de `:665`. */}
            {nota === null ? null : <span className={CLASSES_NOTA}>{nota}</span>}

            <BotaoDeFrete frete={frete} freteAusente={freteAusente} onInformarFrete={onInformarFrete} />
        </>
    );
}
