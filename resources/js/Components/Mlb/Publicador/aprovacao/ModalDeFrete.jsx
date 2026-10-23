import { cn } from '@/lib/utils';
import CascaDeModal from './CascaDeModal';
import { AvisoDeRecusa } from './AvisoDeVersaoMudou';
import { classeDaCorDoPreco, TEXTO_DA_COR } from './KpisDoPainel';
import { objetoDoPainel, textoDoPainel } from './ImagemGrandeDoPainel';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 188, plano 12 — MOD-03: "Informar frete do vendedor" (`:806`-`:813`).
//
// Critério 14: grava valor + **origem**, mostra a **PRÉVIA do resultado e da
// margem ANTES de confirmar**, e **INCREMENTA A VERSÃO**.
//
// ═══ ⚠️⚠️ A CASCA É IMPORTADA, NUNCA RECRIADA (critério 15 / MOD-05) ══════
//
// `CascaDeModal.jsx` nasceu na **Fase 187, plano 09** e está **EM PRODUÇÃO**.
// ⚠️ Duas cascas divergiriam. ➡️ Este arquivo **não** declara backdrop, caixa de
// 560px, topo nem rodapé, e `CascaDeModal.jsx` sai deste plano com `numstat` ZERO.
//
// ═══ ⚠️⚠️ `font-pub` NO CORPO (critério 15 / REND-05) ════════════════════
//
// *"Conteúdo portado não herda fonte"* — medido na Fase 178. O painel desta fase
// é PORTAL, e um modal aberto de dentro dele sai da subárvore do módulo e volta a
// herdar do `document.body` (Inter). ➡️ **A casca é compartilhada e está em
// produção; a fonte é declarada AQUI para o modal continuar correto tanto dentro
// do portal do painel quanto fora dele.**
//
// ═══ ⚠️⚠️ A PRÉVIA VEM DO SERVIDOR. ESTE ARQUIVO NÃO CALCULA NADA ════════
//
// `PrecoDoPainelService::previaDoFrete()` (plano 188-06) devolve
// `{preenchido, valor, texto, cor, resultado, margem, incompleto}` — e ela é
// `ComposicaoFinanceiraService::para()` **com a LINHA de frete trocada**, somada
// como o DTO soma, **sem persistir**.
// ⚠️⚠️ **A tela só EXIBE.** Conta no cliente divergiria da conta do servidor na
// primeira edição — num campo em que **confirmar INCREMENTA A VERSÃO** e reabre a
// aprovação. ➡️ **Gate de ausência de qualquer aritmética de taxa neste arquivo**
// (`* 0.12`, `6.75`, `/ (1 -`, `toFixed`, `Intl`), lido **SEM comentário**.
// ⚠️ **D8/FIN-02: as taxas do README são FICTÍCIAS** (`src:'est'`, *"taxa de
// exemplo"*) e **não** entram como literal nem como config. O único número novo da
// milestone é a margem mínima, e ela **já existe** (FIN-09).
// ⚠️ **`equilibrio` e `precoAlvo` NÃO são calculados na prévia** — está declarado
// no docblock de `previaDoFrete()`: os dois percentuais são privados de
// `ComposicaoFinanceiraService` e **não são recuperáveis sem perda**. *"Número
// plausível e errado é pior que número ausente"*, e a prévia do protótipo não os
// mostra.
//
// ═══ ⚠️ COMO A PRÉVIA CHEGA — e por que NÃO há `axios` aqui ══════════════
//
// A action `previaDoFrete` é **DUAL-PATH** por construção (plano 188-06):
// `$request->expectsJson() ? response()->json($previa) : back()->with('painel_frete_previa', $previa)`.
// ⚠️⚠️ **MEDIDO em `@inertiajs/core` 2.3.21:** o Inertia manda
// `Accept: text/html, application/xhtml+xml` **e** `X-Requested-With: XMLHttpRequest`
// (`dist/index.esm.js:2287`-`:2289`), e `Request::expectsJson()` exige
// `acceptsAnyContentType()` **ou** `wantsJson()` — os dois **falsos** com aquele
// `Accept`. ➡️ **Pelo Inertia o ramo é o `back()->with(...)`**, e a prévia chega
// pelo **FLASH** `painel_frete_previa`, exatamente como `flash.lote_aprovado` da
// Fase 187. **Nenhum `axios`, nenhum `fetch`, nenhum estado novo.**
// ⚠️ **E MEDIDO também:** `router.post` tem `preserveState: true` por DEFAULT
// (`dist/index.esm.js:2382`) — então o estado local do modal **sobrevive** ao
// recarregamento parcial, e o campo digitado não é zerado a cada tecla.
//
// ═══ ⚠️⚠️ AS TRÊS ORIGENS: CHAVE no corpo, RÓTULO na tela ════════════════
//
// `:1401` crava as três FRASES. ⚠️ **No produto elas são lista FECHADA do modelo**
// (`PubAprovacaoFrete::ORIGENS`, `app/Models/PubAprovacaoFrete.php:80`), e o que
// viaja no corpo é a **CHAVE** (`cotacao_transportadora`, `tabela_cliente`,
// `simulador`): mandar a FRASE do protótipo dá **422**
// (`REGRA_ORIGEM_DESCONHECIDA`). ➡️ `ORIGENS_DO_FRETE` abaixo é o **espelho
// manual** daquele mapa, na MESMA ordem, e **há gate lendo o PHP e comparando os
// dois** — mesma disciplina do `DEFEITOS` de `vocabularioDoPortao.jsx`.
// ⚠️ **MOD-03 escreve "cotação, tabela do cliente, simulador" — e o PROTÓTIPO
// GANHA nos RÓTULOS** (§3 do FID01: a FÓRMULA, os RÓTULOS e a ORDEM são dele).
//
// ═══ ⚠️ VALOR INVÁLIDO: NEUTRO, NUNCA "R$ 0,00" ═════════════════════════
//
// `num()` (`:1048`) só aceita **> 0**: `0`, negativo, vazio, texto, array e objeto
// caem no estado "sem valor". ⚠️ **A prévia volta ao TEXTO NEUTRO** (*"Informe o
// valor para ver o resultado"*), nunca *"Resultado R$ 0,00"* — **zero afirmaria
// que o frete é de graça** (D4). ⚠️ E o botão fica **apagado** (cor não é o único
// sinal: ele sai `disabled`) **e** o clique **não** chama a rota. A trava real é o
// **422** do servidor (`REGRA_FRETE_INVALIDO`).
//
// ═══ ⚠️ O QUE ESTE ARQUIVO NÃO FAZ ══════════════════════════════════════
//
// ⚠️ **Ele não escreve.** `onSubmeter` é chamado com `{valor, origem}`, e a escrita
// vai pelo helper único `escreverNoPainel` (W11 / COL-04 / critério 17), que
// acrescenta `versao_esperada` SEMPRE e não engole o 422.
// ⚠️ **Ele não formata data.** A nota do frete nomeia a FONTE e a DATA
// (`:1204`: `origem + ' · ' + agora`), e ela é montada no **SERVIDOR**
// (`PubAprovacaoFrete::notaDaOrigem()`, COL-06) — **nunca `new Date()` no
// cliente**: `Invalid Date` e o deslocamento de fuso de data-only em BRT já
// morderam este projeto.
// ⚠️ **Ele não guarda estado.** `valor` e `origem` são props: o pai (a página) é o
// dono, pelo mesmo contrato de `SimulacaoDePreco.jsx`.
// ═══════════════════════════════════════════════════════════════════════════

/** `:1401`/`:1395` — o título do modal e o rótulo do botão, LITERAIS. */
export const TITULO = 'Informar frete do vendedor';

export const ROTULO_DO_BOTAO = 'Confirmar frete';

/** `:807` — o rótulo literal do campo de valor. */
export const ROTULO_DO_VALOR = 'Frete pago pelo vendedor (por venda)';

/** `:808` — o prefixo absoluto e o placeholder do input. */
export const PREFIXO_DO_VALOR = 'R$';

export const PLACEHOLDER_DO_VALOR = '0,00';

/** `:810` — o rótulo do `<select>` de origem. */
export const ROTULO_DA_ORIGEM = 'Origem do valor';

/**
 * ⚠️⚠️ As TRÊS origens — **espelho manual** de `PubAprovacaoFrete::ORIGENS`
 * (`app/Models/PubAprovacaoFrete.php:80`), na MESMA ORDEM.
 *
 * ⚠️ **A CHAVE viaja no corpo; o RÓTULO fica na tela** (VOC-06). Mandar a FRASE
 * do protótipo em vez da chave dá **422** (`REGRA_ORIGEM_DESCONHECIDA`).
 * ⚠️ Lista **FECHADA**: origem fora das três faria a nota deixar de nomear a fonte.
 * ⚠️ Há gate lendo o PHP e comparando chave por chave, rótulo por rótulo, **na
 * ordem** — mesma disciplina do `DEFEITOS` de `vocabularioDoPortao.jsx`.
 */
export const ORIGENS_DO_FRETE = [
    { chave: 'cotacao_transportadora', rotulo: 'Cotação da transportadora' },
    { chave: 'tabela_cliente', rotulo: 'Tabela do cliente' },
    { chave: 'simulador', rotulo: 'Simulador de frete' },
];

/** ⚠️ O corpo do modal, e é ele que DECLARA A FONTE DO MÓDULO (ver o docblock). */
export const CLASSES_CORPO_DO_MODAL = 'flex flex-col gap-[14px] font-pub text-pub-txt';

/** `:807`/`:810` — o `<label>` em coluna, com o texto discreto. */
export const CLASSES_ROTULO = 'flex flex-col gap-[6px] text-[12.5px] text-pub-txt-2';

/** `:808` — o wrapper RELATIVO do campo, que ancora o `R$` absoluto. */
export const CLASSES_CAMPO = 'relative flex items-center';

/** `:808` — o `R$` absoluto à esquerda. */
export const CLASSES_PREFIXO = 'absolute left-[12px] text-[13px] text-pub-txt-4';

/**
 * `:808` — o input. ⚠️ `pl-[36px]` é o que abre espaço para o `R$`; sem ele o
 * prefixo fica EM CIMA do número digitado.
 */
export const CLASSES_INPUT =
    'h-[38px] w-full rounded-[8px] border border-pub-line-strong bg-pub-sunken pl-[36px] pr-[12px] text-[14px] text-pub-txt outline-none focus:border-pub-accent';

/** `:811` — o `<select>` de origem. ⚠️ `rounded-[8px]`: 8px não existe na escala. */
export const CLASSES_SELECT =
    'h-[36px] rounded-[8px] border border-pub-line-strong bg-pub-sunken px-[10px] text-[13px] text-pub-txt outline-none';

/** `:813` — a prévia. ⚠️ A COR vem do servidor; o TEXTO é o sinal principal (D7). */
export const CLASSES_PREVIA = 'text-[13px] font-semibold';

/**
 * A origem escolhida, saneada contra a lista FECHADA.
 *
 * ⚠️ Named export porque é ela que decide o que vai no corpo, e é ela que o gate
 * exercita com o quase-igual e com a FRASE do protótipo (que o servidor recusa).
 * ⚠️ Sem escolha válida devolve a PRIMEIRA — nunca `undefined`, que faria o
 * `<select>` desenhar sem seleção e o corpo viajar sem origem.
 */
export function origemEscolhida(origem) {
    const achada = typeof origem === 'string'
        ? ORIGENS_DO_FRETE.find((opcao) => opcao.chave === origem)
        : null;

    return achada ?? ORIGENS_DO_FRETE[0];
}

/**
 * ⚠️ `:1048`/`num()` — o valor digitado vale? **Só > 0.**
 *
 * ⚠️ A trava REAL é o 422 do servidor (`PrecoDoPainelService::sanear()` +
 * `REGRA_FRETE_INVALIDO`); aqui a decisão existe para o botão **apagar** e para o
 * clique **não** disparar. ⚠️ Mesma leitura do protótipo: vírgula decimal e
 * separador de milhar são aceitos, porque é isso que a pessoa digita.
 *
 * ⚠️⚠️ **E ELA É O ESPELHO DE `sanear()`, PASSO POR PASSO — a 1ª redação divergia,
 * e o gate pegou.** Medido em `PrecoDoPainelService:728`-`:753`:
 *   · **o SINAL é recusado ANTES de tirar os não-dígitos** (`:741`: `str_contains('-')`
 *     e o `−` U+2212). ⚠️⚠️ **Sem essa ordem `-5` virava `5`**: o `replace` de
 *     não-dígitos **come o menos** e o valor negativo entrava como POSITIVO. O
 *     docblock do servidor chama isso de *"divergência (3)"* — o `num()` do
 *     protótipo (`:1048`) **tem** esse defeito, e o produto o conserta;
 *   · a vírgula vira ponto **só na PRIMEIRA ocorrência** (`:748`, `limit = 1`);
 *   · e o resultado tem de ser **inteiramente numérico** (`:750`, `is_numeric`) —
 *     `parseFloat` sozinho aceitaria `'1.2.3'` como `1.2`, e aí a tela habilitaria
 *     o botão para um valor que o servidor **recusa com 422**.
 * ⚠️ Duas leituras do mesmo campo é a classe de defeito mais caro desta tela: a
 * divergente é a que decide se o botão acende.
 */
export function valorDoFrete(bruto) {
    if (typeof bruto === 'number') {
        return Number.isFinite(bruto) && bruto > 0 ? bruto : null;
    }

    if (typeof bruto !== 'string') return null;

    // ⚠️⚠️ O SINAL, ANTES de qualquer limpeza (`sanear():741`).
    if (bruto.includes('-') || bruto.includes('\u2212')) return null;

    const limpo = bruto.replace(/[^\d,.]/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.');

    // ⚠️ Inteiramente numérico (`sanear():750`), não "começa com número".
    if (limpo === '' || !/^\d+(\.\d+)?$/.test(limpo)) return null;

    const numero = Number.parseFloat(limpo);

    return Number.isFinite(numero) && numero > 0 ? numero : null;
}

/**
 * O corpo do modal de frete — ⚠️ NAMED EXPORT, exercido direto pelos gates.
 *
 * @param {object} props
 * @param {unknown} [props.valor] o que foi digitado (texto)
 * @param {unknown} [props.origem] a CHAVE da origem escolhida
 * @param {unknown} [props.previa] `painel_frete_previa` do servidor
 * @param {unknown} [props.onValor] `(texto) => void`
 * @param {unknown} [props.onOrigem] `(chave) => void`
 */
export function CorpoDoFrete({
    valor = '',
    origem = null,
    previa = null,
    onValor = null,
    onOrigem = null,
}) {
    const escreverValor = typeof onValor === 'function' ? onValor : null;
    const trocarOrigem = typeof onOrigem === 'function' ? onOrigem : null;
    const escolhida = origemEscolhida(origem);

    // ⚠️⚠️ **DUAS COISAS DIFERENTES, e a 1ª redação as confundia (o gate pegou):**
    //   · a prévia **NEUTRA** (`preenchido !== true`) é o texto *"Informe o valor
    //     para ver o resultado"* que o SERVIDOR devolve, e ela **TEM de aparecer** —
    //     é ela o estado "sem valor" do critério 14;
    //   · a prévia **PREENCHIDA** só vale enquanto o valor digitado **AINDA é o que
    //     ela mediu**: o payload chega de uma volta ao servidor, e exibir o
    //     resultado de um valor antigo seria a tela **afirmando um número que não é
    //     mais o da tela**.
    // ⚠️ E **nunca** "Resultado R$ 0,00": zero afirmaria que o frete é de graça (D4).
    const dados = objetoDoPainel(previa);
    const valida = dados !== null && (dados.preenchido !== true || valorDoFrete(valor) !== null);
    const textoDaPrevia = valida ? textoDoPainel(dados.texto) : null;
    const corDaPrevia = classeDaCorDoPreco(TEXTO_DA_COR, valida ? dados.cor : null, TEXTO_DA_COR.neutro);

    return (
        <div className={CLASSES_CORPO_DO_MODAL}>
            <label className={CLASSES_ROTULO}>
                {ROTULO_DO_VALOR}
                <span className={CLASSES_CAMPO}>
                    <span className={CLASSES_PREFIXO}>{PREFIXO_DO_VALOR}</span>
                    {/* ⚠️ `value` só com `onChange`: campo controlado sem handler é
                        controle MORTO. ⚠️ E NUNCA `String(valor)`, que LANÇA com
                        `{toString:null}`. */}
                    <input
                        type="text"
                        inputMode="decimal"
                        className={CLASSES_INPUT}
                        placeholder={PLACEHOLDER_DO_VALOR}
                        {...(escreverValor === null
                            ? { defaultValue: typeof valor === 'string' ? valor : '' }
                            : { value: typeof valor === 'string' ? valor : '' })}
                        onChange={escreverValor === null ? undefined : (evento) => {
                            // ⚠️ Flag DENTRO do callback.
                            const cru = evento?.target?.value;

                            escreverValor(typeof cru === 'string' ? cru : '');
                        }}
                    />
                </span>
            </label>

            <label className={CLASSES_ROTULO}>
                {ROTULO_DA_ORIGEM}
                <select
                    className={CLASSES_SELECT}
                    {...(trocarOrigem === null
                        ? { defaultValue: escolhida.chave }
                        : { value: escolhida.chave })}
                    onChange={trocarOrigem === null ? undefined : (evento) => {
                        const cru = evento?.target?.value;
                        // ⚠️ Lista FECHADA: chave fora das três não troca nada.
                        if (typeof cru !== 'string' || !ORIGENS_DO_FRETE.some((o) => o.chave === cru)) return;

                        trocarOrigem(cru);
                    }}
                >
                    {ORIGENS_DO_FRETE.map((opcao) => (
                        // ⚠️ Tudo que esta expressão lê é PARÂMETRO ou nome de
                        // MÓDULO, que o Rollup nunca elide.
                        <option key={opcao.chave} value={opcao.chave}>{opcao.rotulo}</option>
                    ))}
                </select>
            </label>

            {/* ⚠️ O TEXTO é o sinal principal; a cor acompanha (D7: cor nunca é o
                único sinal). Sem texto a linha não é desenhada. */}
            {textoDaPrevia === null ? null : (
                <span className={cn(CLASSES_PREVIA, corDaPrevia)}>{textoDaPrevia}</span>
            )}
        </div>
    );
}

/**
 * O modal "Informar frete do vendedor" (MOD-03), **dentro da casca da Fase 187**.
 *
 * @param {object} props
 * @param {unknown} [props.aberto] só `true` desenha
 * @param {unknown} [props.sub] o título da oferta (`:1401`)
 * @param {unknown} [props.valor] o que foi digitado
 * @param {unknown} [props.origem] a CHAVE da origem
 * @param {unknown} [props.previa] `painel_frete_previa` do servidor
 * @param {unknown} [props.onValor] `(texto) => void`
 * @param {unknown} [props.onOrigem] `(chave) => void`
 * @param {unknown} [props.onFechar] `() => void`
 * @param {unknown} [props.onSubmeter] `({valor, origem}) => void` — ⚠️ a escrita é
 *   do CHAMADOR, pelo helper único `escreverNoPainel`
 * @param {unknown} [props.erro] a recusa do servidor ao último envio (23/10/2026,
 *   P-18e): o modal fica aberto, com o que foi digitado, e diz o porquê
 * @param {unknown} [props.enviando] `true` enquanto o envio não voltou: o botão
 *   de enviar trava (23/10/2026, P-18e — o duplo clique mandava dois pedidos)
 */
export default function ModalDeFrete({
    aberto = false,
    sub = null,
    valor = '',
    origem = null,
    previa = null,
    onValor = null,
    onOrigem = null,
    onFechar = null,
    onSubmeter = null,
    erro = null,
    enviando = false,
}) {
    if (aberto !== true) return null;

    const numero = valorDoFrete(valor);
    const emVoo = enviando === true;
    const enviar = typeof onSubmeter === 'function' ? onSubmeter : null;

    return (
        <CascaDeModal
            aberto
            titulo={TITULO}
            sub={textoDoPainel(sub) ?? undefined}
            rotuloDoBotao={ROTULO_DO_BOTAO}
            podeSubmeter={numero !== null && !emVoo}
            onFechar={onFechar}
            onSubmeter={enviar === null ? null : () => {
                // ⚠️ A guarda é REPETIDA aqui de propósito: a casca já desabilita o
                // botão, mas a régua é "o clique NÃO chama a rota", não "o botão
                // está cinza".
                if (emVoo || valorDoFrete(valor) === null) return;

                // ⚠️ O que viaja é o TEXTO digitado e a CHAVE da origem. O
                // saneamento canônico é do servidor (`PrecoDoPainelService::sanear`):
                // sanear aqui e mandar o número criaria a SEGUNDA leitura do mesmo
                // campo, e a divergente seria a que grava.
                enviar({ valor, origem: origemEscolhida(origem).chave });
            }}
        >
            {[
                <CorpoDoFrete
                    key="corpo"
                    valor={valor}
                    origem={origem}
                    previa={previa}
                    onValor={onValor}
                    onOrigem={onOrigem}
                />,
                <AvisoDeRecusa key="erro" mensagem={erro} />,
            ]}
        </CascaDeModal>
    );
}
