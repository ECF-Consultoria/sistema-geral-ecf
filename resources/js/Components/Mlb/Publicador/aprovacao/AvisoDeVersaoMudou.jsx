import { cn } from '@/lib/utils';
import {
    CLASSES_AVISO,
    CLASSES_CONFERIR,
    CLASSES_GLIFO,
    CLASSES_TEXTO_DO_AVISO,
} from './KpisDoPainel';
import { inteiroDoPainel, textoDoPainel } from './ImagemGrandeDoPainel';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 188, plano 12 — COL-04 / critério 17: O AVISO DE CONCORRÊNCIA.
//
// **Se a `version` mudar enquanto alguém revisa, a tela AVISA e REABRE as
// verificações afetadas. NUNCA sobrescreve em silêncio.**
//
// ═══ ⚠️⚠️ ESTE ARQUIVO NÃO REABRE NADA — E ISSO É O PONTO ════════════════
//
// *"REABRE as verificações afetadas"* é efeito que a **Fase 186 já tem**:
// `AprovacaoDaOfertaService::registrarMudanca()` devolve a oferta `aprovado` para
// `aguardando_aprovacao` pela única porta, e as 7 mudanças relevantes são
// BLOQ-05/BLOQ-06. ➡️ **A tela não reabre nada: ela recarrega e mostra o estado
// REAL.** ⚠️ Escrever lógica de reabertura aqui criaria a **segunda verdade** sobre
// o que reabre a aprovação, e a divergente seria descoberta por alguém vendo o
// painel dizer "reaberto" com o servidor dizendo "aprovado".
//
// ═══ ⚠️⚠️ OS DOIS GATILHOS, E POR QUE O SEGUNDO É O QUE IMPORTA ══════════
//
// 1. **o payload trouxe versão diferente** da que a tela tinha — outra pessoa
//    mexeu enquanto esta lia. O painel vem do servidor a cada abertura e a cada
//    ↑/↓ (recarregamento parcial do Inertia, plano 188-07), então a versão volta
//    fresca **por construção**;
// 2. ⚠️⚠️ **uma escrita voltou 422 de versão divergente** — a corrida aconteceu
//    **entre ler e clicar**. ➡️ **O 422 NÃO pode ser engolido:** um `catch`
//    silencioso ali é literalmente *"sobrescreve em silêncio"* com outra roupa, e
//    — pior — **deixaria a pessoa achando que aprovou**. E **aprovar JÁ PUBLICA,
//    sem desfazer.**
//
// ⚠️ O caminho do 422 é do helper `escreverNoPainel` (`PainelDeRevisao.jsx`), que
// na wave 188-12 passou a ouvir o evento `inertia:invalid` — porque as recusas
// deste painel são `response()->json([...], 422)` **CRUAS** e o `onError` do
// Inertia **nunca** seria chamado para elas (medido em `@inertiajs/core` 2.3.21).
// Ver o docblock do helper.
//
// ═══ ⚠️⚠️ É CAIXA BLOQUEANTE, NÃO TOAST ═════════════════════════════════
//
// ⚠️ **Toast tem vocabulário TRAVADO com gate (VOC-08, SEIS frases fechadas) e
// SOME em ~3,8 s** — *"um aviso de concorrência que desaparece é um aviso que não
// houve"*. ⚠️ E **os seis toasts são FECHADOS: NÃO se cunha o sétimo** (o usuário
// recusou em 19/10). ➡️ **Caixa visível no painel**, que fica até a pessoa
// recarregar.
//
// ⚠️ **O MOLDE VISUAL, declarado:** COL-04 **não tem elemento no protótipo** —
// medido. ➡️ Foi **reusado o molde do aviso de alteração de preço** (`:649`-`:651`:
// `background:#14120A` = `pub-row-new`, `border:1px solid rgba(250,204,21,0.35)` =
// `pub-accent/[0.35]`, `border-radius:9px`, `font-size:12.5px`, com glifo `↻`,
// texto em `flex:1` e um botão de ação à direita), **por SEMELHANÇA DE FUNÇÃO**: os
// dois dizem *"algo mudou, confira antes de gravar"*. ⚠️ **As classes são
// IMPORTADAS de `KpisDoPainel.jsx`**, não copiadas — copiá-las criaria a segunda
// cópia das mesmas medidas, e a divergente seria vista como duas caixas âmbares
// diferentes na mesma tela. ⚠️ **O que DISTINGUE os dois avisos é o TEXTO e o
// RÓTULO DO BOTÃO** — e é por isso que o texto é o sinal principal (D7: cor **e
// glifo** nunca são o único sinal).
//
// ═══ ⚠️⚠️ COMPARAR VERSÃO EXIGE GUARDA DE TIPO ══════════════════════════
//
// `versao` pode chegar objeto, `NaN`, string, decimal ou ausente. ⚠️ **`typeof NaN
// === 'number'`**, então a guarda é `Number.isInteger` **mais** `> 0`
// (`inteiroDoPainel` + positivo): `0` é id/versão plausível e passaria pela guarda
// de "inteiro". ⚠️ **E NUNCA `String(versao)` na mensagem:** `String({toString:
// null})` **LANÇA**, e foi assim que 5 blocos de teste morreram **dentro da
// mensagem de asserção** no plano 180-05.
// ⚠️⚠️ **Com versão adversa o aviso NÃO ACENDE por engano** — acender sem motivo
// treina a pessoa a ignorá-lo, e aí ele deixa de servir exatamente quando precisa.
// ═══════════════════════════════════════════════════════════════════════════

/** `:650` — o glifo, o MESMO do aviso de alteração (molde reusado por função). */
export const GLIFO = '↻';

/**
 * O que a tela DIZ quando o **payload** trouxe versão diferente.
 *
 * ⚠️ Em DUAS partes porque o número entra no meio — e ele é **inteiro provado**,
 * concatenado com `+`. **Nunca `String(valor)`.**
 */
export const TEXTO_PREFIXO = 'Esta oferta mudou enquanto você revisava. A versão em vigor agora é a ';

export const TEXTO_SUFIXO = '. Recarregue a revisão antes de gravar.';

/** O rótulo do botão. ⚠️ É ele que distingue este aviso do de alteração de preço. */
export const ROTULO_DA_ACAO = 'Recarregar a revisão';

/** O `title` do botão — a tela nunca depende só do rótulo curto. */
export const TITULO_DA_ACAO = 'Recarregar a revisão para ver o estado atual da oferta';

/**
 * ⚠️⚠️ A DECISÃO, numa casa só: o aviso acende?
 *
 * Named export porque é ela que os gates exercitam com as formas adversas, e
 * porque os DOIS gatilhos têm de consultar a MESMA decisão — duas condições
 * parecidas divergiriam.
 *
 * @param {unknown} versaoVista a `versao` que a tela carregou
 * @param {unknown} versaoAtual a `versao` que o servidor acabou de devolver
 * @param {unknown} mensagem a mensagem do 422 de versão divergente, se houve
 * @returns {?{texto: string, mensagemDoServidor: ?string}} `null` = não acende
 */
export function avisoDeVersao(versaoVista, versaoAtual, mensagem) {
    // ⚠️ GATILHO 2 primeiro: quando a escrita voltou 422, a mensagem do SERVIDOR é
    // a melhor que existe (ela diz as DUAS versões) e **não é remontada aqui**.
    const doServidor = textoDoPainel(mensagem);
    if (doServidor !== null) {
        return { texto: doServidor, mensagemDoServidor: doServidor };
    }

    // ⚠️ GATILHO 1: o payload divergiu. ⚠️ `Number.isInteger` **mais** `> 0`:
    // `typeof NaN === 'number'`, e `0` é versão plausível que passaria pela guarda
    // de "inteiro".
    const vista = inteiroDoPainel(versaoVista);
    const atual = inteiroDoPainel(versaoAtual);

    if (vista === null || atual === null || vista <= 0 || atual <= 0 || vista === atual) {
        return null;
    }

    // ⚠️ O número é INTEIRO PROVADO e entra por concatenação. **Nunca `String()`.**
    return { texto: TEXTO_PREFIXO + atual + TEXTO_SUFIXO, mensagemDoServidor: null };
}

/**
 * O aviso de concorrência (COL-04) — ⚠️ NAMED EXPORT por default, exercido direto
 * pelos gates (o painel é PORTAL, e o render de servidor não desenha portal).
 *
 * @param {object} props
 * @param {unknown} [props.versaoVista] a `versao` que a tela carregou
 * @param {unknown} [props.versaoAtual] a `versao` que o servidor devolveu
 * @param {unknown} [props.mensagem] a mensagem do 422 de versão divergente
 * @param {unknown} [props.onRecarregar] `() => void`, ou `null` — sem ela o botão
 *   **não é desenhado** (D4: botão sem destino), e o TEXTO continua aparecendo
 */
export default function AvisoDeVersaoMudou({
    versaoVista = null,
    versaoAtual = null,
    mensagem = null,
    onRecarregar = null,
}) {
    const aviso = avisoDeVersao(versaoVista, versaoAtual, mensagem);

    // ⚠️ Sem divergência o aviso **NÃO é desenhado** — nem casca vazia.
    if (aviso === null) return null;

    const recarregar = typeof onRecarregar === 'function' ? onRecarregar : null;

    return (
        <div className={CLASSES_AVISO}>
            {/* ⚠️ O glifo acompanha; o TEXTO é o sinal (D7). */}
            <span className={CLASSES_GLIFO}>{GLIFO}</span>
            <span className={CLASSES_TEXTO_DO_AVISO}>{aviso.texto}</span>

            {/* ⚠️ Sem handler o botão não nasce, e o TEXTO continua aparecendo:
                `onClick` vazio e `disabled` decorativo foram RECUSADOS (D4). */}
            {recarregar === null ? null : (
                <button
                    type="button"
                    title={TITULO_DA_ACAO}
                    onClick={() => recarregar()}
                    className={cn(CLASSES_CONFERIR)}
                >
                    {ROTULO_DA_ACAO}
                </button>
            )}
        </div>
    );
}
