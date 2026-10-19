import { cn } from '@/lib/utils';
import {
    TEXTO_DA_COR,
    classeDaCorDoPreco,
    listaDoPainel,
    objetoDoPainel,
    textoDoPainel,
} from './KpisDoPainel';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 188, plano 09 — A CAIXA DE SIMULAÇÃO (`:669`-`:685`): o selo
// "SIMULAÇÃO · NÃO SALVA", o input de 120×32 com o `R$` absoluto, o resultado
// simulado com o delta, "Descartar"/"Salvar no anúncio", os DOIS atalhos
// (equilíbrio e margem mínima) e a frase do equilíbrio indisponível.
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas, sha256 68624883ce35547e…). ⚠️ **CITE O DIRETÓRIO.**
// ⚠️ Medido por `sed`: `:669` abre a caixa TRACEJADA e `:686` a fecha — então os
// dois atalhos (`:680`-`:683`) **e** a frase do indisponível (`:685`) ficam
// **DENTRO** dela. A tabela §D não dizia isso.
//
// ═══ ⚠️⚠️ CRITÉRIO 7 — NADA AQUI É PERSISTIDO NEM ENVIADO AO MERCADO LIVRE ══
//
// A simulação **nunca** é gravada e **nunca** sai para o ML. Ela só pode ser
// SALVA com a oferta `live`, e salvar incrementa a versão, limpa o aviso de
// alteração e registra no histórico — tudo no **servidor**
// (`AprovacaoDaOfertaService::registrarPrecoSalvo` + `registrarMudanca`).
//
// ⚠️⚠️ **E A TELA NÃO CALCULA.** Ela manda o valor digitado e **EXIBE** o que o
// servidor devolve (`PrecoDoPainelService::simular()`, plano 188-04). Não existe
// aqui nenhum `* 0.12`, nenhum `* 0.17`, nenhum `6.75`, nenhum `/ (1 - margem)`.
// ⚠️ Conta feita no cliente divergiria da conta do servidor na primeira edição,
// e a divergente seria a que a pessoa lê — num campo em que **salvar troca o
// preço de verdade e não há desfazer**.
//
// ═══ ⚠️⚠️ O TRANSPORTE É DA WAVE 188-06, E ISSO ESTÁ DECLARADO ═════════════
//
// **MEDIDO no HEAD de partida desta wave (`091340b6`, 19/10/2026):**
// `routes/mlb_anuncios.php` **não tinha** rota de preço nenhuma — as que
// existiam eram `lote`, `imagem.aprovar`, `fotos.sem-alerta`,
// `imagem.reprovar`, `salvar-revisao`, `aprovar` e `tentar-de-novo`.
// ➡️ **Esta peça nasce PRONTA PARA RECEBER:** `onSimular`, `onDescartar` e
// `onSalvar` chegam **função ou `null`**, e **sem `onSimular` a caixa INTEIRA
// não é desenhada**.
//
// ⚠️ Por que a caixa inteira, e não só o input: um campo de digitação sem
// transporte é **controle morto** — a pessoa digita e nada acontece, que é pior
// que a ausência. E uma caixa tracejada com só o título *"Simular preço"* dentro
// é casca decorativa (D4). ⚠️ *"Desenhar desabilitado com explicação"* é a saída
// que o usuário já **RECUSOU** na PERGUNTA-188-B3, e *"`onClick` vazio"* é a
// mesma dívida com outra roupa.
// ⚠️ **Quando a rota chegar, a escrita vai pelo helper único `escreverNoPainel`**
// (W11 / COL-04): `versao_esperada` é OPCIONAL no corpo, e omiti-la faz
// `exigirVersao()` receber `null` e **LIBERAR** a escrita — literalmente
// *"sobrescrever em silêncio"*, o que o critério 17 proíbe. É por isso que o
// payload já traz `simulacao.versao`.
//
// ═══ ⚠️⚠️ O SELO SÓ COM `simOn`, E OS BOTÕES COM OS DOIS EIXOS ═════════════
//
// `:1129` — `simOn = !!fs && Math.abs(sp - o.price) > 0.001`: o selo
// "SIMULAÇÃO · NÃO SALVA" só existe quando o valor digitado **DIFERE** do salvo.
// ⚠️ Com `>= 0` ele apareceria com o preço IGUAL ao salvo, e `pode_salvar`
// ficaria verdadeiro para salvar o que já está salvo (versão++ por nada).
// `:1142` — `canApply = simOn && inf.live`: **DOIS eixos**, e o servidor já os
// resolve em `simulacao.pode_salvar`. A tela **lê**; não recombina.
//
// ═══ ⚠️⚠️ O `N%` DO ATALHO VEM DO MÍNIMO REAL ══════════════════════════════
//
// O protótipo escreve *"Margem mínima 12%"* **porque `MIN = 12`** (`:914`).
// ⚠️ **FIN-09: a margem mínima é a ÚNICA configuração nova da milestone, e ela JÁ
// existe** — cravar `12` faria a tela mentir para a empresa que configurou
// outro. ➡️ O rótulo inteiro vem montado do servidor
// (`ATALHO_MINIMO_PREFIXO` + o mínimo RESOLVIDO + o preço-alvo).
//
// ⚠️⚠️ **E `onSimTarget` PREENCHE `ceil(alvo) − 0,1`** (`:1140`, ponteiro
// CORRIGIDO pela `188-FID01.md` §L — o plano dizia `:1139`, que é o `onSimBE`, o
// atalho de EQUILÍBRIO, que **não** leva o ajuste de 10 centavos). É
// contraintuitivo: R$ 118,43 preenche **118,90**. ➡️ **O cálculo é do SERVIDOR**
// (`PrecoDoPainelService::atalhos()`), e a tela só repassa `atalho.preenche`.
// **Declarado aqui para que ninguém "arredonde" na tela.**
//
// ⚠️ Nenhum `String(valor)`, nenhum `hasOwnProperty` solto, nenhum
// `obj[chaveDoServidor]` cru. ⚠️ **NADA de `localStorage`** (`:914`).
// ═══════════════════════════════════════════════════════════════════════════

// ─── As classes, uma casa só (`:669`-`:685`) ───────────────────────────────

/** ⚠️ `:669` — a caixa TRACEJADA. `border-dashed` é o que a distingue das outras. */
export const CLASSES_CAIXA = 'flex flex-col gap-[8px] rounded-pub-sub border border-dashed border-pub-line-strong p-[12px]';

/** `:670` — o wrapper que põe o título e o selo na MESMA linha (D20w). */
export const CLASSES_LINHA_1 = 'flex items-center gap-[10px]';

/** `:671` — "Simular preço". */
export const CLASSES_TITULO = 'text-[12.5px] font-semibold';

/** `:672` — o selo "SIMULAÇÃO · NÃO SALVA" (fundo `#262626` = `pub-seg`). */
export const CLASSES_SELO = 'rounded-pub-badge bg-pub-seg px-[6px] py-[2px] text-[10.5px] font-bold tracking-[0.05em] text-pub-fase1';

/**
 * `:674` — o wrapper da linha 2 (D22w).
 *
 * ⚠️ É DENTRO dele que o `margin-left:auto` de `:677` empurra os botões para a
 * direita — a tabela §D citava os dois botões mas **não** o wrapper.
 */
export const CLASSES_LINHA_2 = 'flex items-center gap-[10px]';

/** `:675` — o wrapper relativo do input, que ancora o `R$` absoluto. */
export const CLASSES_CAMPO = 'relative flex items-center';

/** `:675` — o `R$` absoluto à esquerda. */
export const CLASSES_PREFIXO = 'absolute left-[10px] text-[12.5px] text-pub-txt-4';

/** `:675` — o input de 120×32, com o `padding-left:32px` que abre espaço para o `R$`. */
export const CLASSES_INPUT = 'h-[32px] w-[120px] rounded-[8px] border border-pub-line-strong bg-pub-sunken '
    + 'pl-[32px] pr-[10px] text-[13px] tabular-nums text-pub-txt outline-none focus:border-pub-accent';

/** `:676` — o wrapper INTERNO que empilha o resultado e o delta (gap de 1px). */
export const CLASSES_RESULTADO = 'flex flex-col gap-[1px] tabular-nums';

/** `:676` — a linha do resultado simulado. */
export const CLASSES_RESULTADO_VALOR = 'text-[13px] font-semibold';

/** `:676` — a margem simulada, emendada depois do `· `. */
export const CLASSES_RESULTADO_MARGEM = 'font-medium';

/** `:676` — o delta, com o sufixo ` vs. salvo`. */
export const CLASSES_DELTA = 'text-[11px]';

/** `:677` — o wrapper dos dois botões, com o `margin-left:auto`. */
export const CLASSES_ACOES = 'ml-auto flex gap-[6px]';

/** `:677` — "Descartar", **sem borda** (`border:none`). */
export const CLASSES_DESCARTAR = 'h-[28px] cursor-pointer whitespace-nowrap rounded-pub-control-sm '
    + 'border-none bg-transparent px-[10px] text-[12px] text-pub-txt-2';

/** `:677` — "Salvar no anúncio". */
export const CLASSES_SALVAR = 'h-[28px] cursor-pointer whitespace-nowrap rounded-pub-control-sm border '
    + 'border-pub-line-off bg-pub-hover-3 px-[10px] text-[12px] font-semibold text-pub-txt';

/** `:680` — o wrapper dos atalhos. ⚠️ `flex-wrap` é load-bearing: os dois rótulos são longos. */
export const CLASSES_ATALHOS = 'flex flex-wrap gap-[6px]';

/** `:681`/`:682` — o atalho em pílula (`border-radius:999px` = `rounded-full`). */
export const CLASSES_ATALHO = 'h-[26px] cursor-pointer whitespace-nowrap rounded-full border '
    + 'border-pub-line-strong bg-pub-surface px-[9px] text-[11.5px] text-pub-fase1';

/** `:685` — "Preço de equilíbrio indisponível até o frete ser confirmado." */
export const CLASSES_INDISPONIVEL = 'text-[11.5px] text-pub-warn-txt';

/** `:676` — o separador entre o resultado e a margem simulados. ⚠️ `·` é U+00B7. */
export const SEPARADOR = '· ';

/**
 * A caixa de simulação.
 *
 * @param {object} props
 * @param {unknown} [props.simulacao] `preco.simulacao` (plano 188-04)
 * @param {unknown} [props.atalhos] `preco.atalhos` — os DOIS, ou `[]`
 * @param {unknown} [props.atalhosIndisponivel] `preco.atalhos_indisponivel`
 * @param {unknown} [props.onSimular] `(valorDigitado) => void`, ou `null` — a
 *   rota é da wave **188-06**. ⚠️ **Sem ela a caixa não é desenhada.**
 * @param {unknown} [props.onDescartar] `() => void`, ou `null`
 * @param {unknown} [props.onSalvar] `() => void`, ou `null`
 */
export default function SimulacaoDePreco({
    simulacao = null,
    atalhos = null,
    atalhosIndisponivel = null,
    onSimular = null,
    onDescartar = null,
    onSalvar = null,
}) {
    const dados = objetoDoPainel(simulacao);
    const simular = typeof onSimular === 'function' ? onSimular : null;

    // ⚠️⚠️ Sem dado OU sem transporte a caixa inteira não nasce — ver o bloco "O
    // TRANSPORTE É DA WAVE 188-06" no docblock. Campo de digitação sem destino é
    // controle MORTO, e caixa tracejada com só o título é casca decorativa (D4).
    if (dados === null || simular === null) return null;

    const titulo = textoDoPainel(dados.titulo);
    const selo = dados.simulando === true ? textoDoPainel(dados.selo) : null;
    const resultado = textoDoPainel(dados.resultado);
    const margem = textoDoPainel(dados.margem);
    const delta = textoDoPainel(dados.delta);
    const indisponivel = textoDoPainel(atalhosIndisponivel);

    const corDoResultado = classeDaCorDoPreco(TEXTO_DA_COR, dados.resultado_cor, TEXTO_DA_COR.texto);
    const corDaMargem = classeDaCorDoPreco(TEXTO_DA_COR, dados.margem_cor, TEXTO_DA_COR.neutro);
    const corDoDelta = classeDaCorDoPreco(TEXTO_DA_COR, dados.delta_cor, TEXTO_DA_COR.neutro);

    // ⚠️⚠️ OS DOIS EIXOS, já resolvidos pelo servidor em `pode_salvar` (simulação
    // ativa **E** oferta `live`). ⚠️ E cada botão exige o SEU handler: botão sem
    // destino é D4 violado.
    const podeSalvar = dados.pode_salvar === true;
    const descartar = podeSalvar && typeof onDescartar === 'function' ? onDescartar : null;
    const salvar = podeSalvar && typeof onSalvar === 'function' ? onSalvar : null;

    const lista = listaDoPainel(atalhos);

    return (
        <div className={CLASSES_CAIXA}>
            <div className={CLASSES_LINHA_1}>
                {titulo === null ? null : <span className={CLASSES_TITULO}>{titulo}</span>}
                {/* ⚠️ `:1129` — o selo SÓ quando o digitado DIFERE do salvo. */}
                {selo === null ? null : <span className={CLASSES_SELO}>{selo}</span>}
            </div>

            <div className={CLASSES_LINHA_2}>
                <span className={CLASSES_CAMPO}>
                    <span className={CLASSES_PREFIXO}>R$</span>
                    {/* ⚠️ O valor vem do SERVIDOR (`preenche`): a tela não guarda
                        número nem o reformata. Digitar manda; o payload volta. */}
                    <input
                        type="text"
                        inputMode="decimal"
                        className={CLASSES_INPUT}
                        value={textoDoPainel(dados.preenche) ?? ''}
                        placeholder={textoDoPainel(dados.placeholder) ?? undefined}
                        onChange={(evento) => simular(evento?.target?.value)}
                    />
                </span>

                <span className={CLASSES_RESULTADO}>
                    <span className={cn(CLASSES_RESULTADO_VALOR, corDoResultado)}>
                        {resultado}
                        {margem === null ? null : (
                            <span className={cn(CLASSES_RESULTADO_MARGEM, corDaMargem)}>{` ${SEPARADOR}${margem}`}</span>
                        )}
                    </span>
                    {/* ⚠️ `:1130` — o delta é vazio quando a composição SALVA está
                        incompleta: não há de que subtrair, e a tela não desenha
                        texto vazio. */}
                    {delta === null ? null : <span className={cn(CLASSES_DELTA, corDoDelta)}>{delta}</span>}
                </span>

                {descartar === null && salvar === null ? null : (
                    <span className={CLASSES_ACOES}>
                        {descartar === null ? null : (
                            <button type="button" className={CLASSES_DESCARTAR} onClick={() => descartar()}>
                                {textoDoPainel(dados.rotulo_descartar)}
                            </button>
                        )}
                        {salvar === null ? null : (
                            <button type="button" className={CLASSES_SALVAR} onClick={() => salvar()}>
                                {textoDoPainel(dados.rotulo_salvar)}
                            </button>
                        )}
                    </span>
                )}
            </div>

            {/* ⚠️ Os DOIS atalhos. O `N%` do segundo vem do mínimo REAL, e o valor
                que ele preenche é `ceil(alvo) − 0,1`, calculado no SERVIDOR. */}
            {lista.length === 0 ? null : (
                <div className={CLASSES_ATALHOS}>
                    {lista.map((atalho, indice) => {
                        // ⚠️⚠️ Flag DENTRO do callback: o Rollup elimina variável de
                        // escopo do componente usada dentro de `.map()`. O que este
                        // callback lê é PARÂMETRO e nome de MÓDULO.
                        const dado = objetoDoPainel(atalho);
                        const rotulo = dado === null ? null : textoDoPainel(dado.rotulo);
                        const preenche = dado === null ? null : textoDoPainel(dado.preenche);
                        const chave = dado === null ? null : textoDoPainel(dado.chave);

                        if (rotulo === null || preenche === null) return null;

                        return (
                            <button
                                key={chave ?? `atalho-${indice}`}
                                type="button"
                                className={CLASSES_ATALHO}
                                onClick={() => simular(preenche)}
                            >
                                {rotulo}
                            </button>
                        );
                    })}
                </div>
            )}

            {/* ⚠️ Sem os atalhos, a tela DIZ POR QUÊ — e o texto é do servidor. */}
            {indisponivel === null ? null : <span className={CLASSES_INDISPONIVEL}>{indisponivel}</span>}
        </div>
    );
}
