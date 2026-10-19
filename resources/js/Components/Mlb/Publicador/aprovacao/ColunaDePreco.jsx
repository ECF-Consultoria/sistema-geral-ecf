import { ColunaDoPainel, filhosSeguros } from './PainelDeRevisao';
import KpisDoPainel, {
    AvisoDeAlteracao,
    CLASSES_TITULO,
    objetoDoPainel,
    textoDoPainel,
} from './KpisDoPainel';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 188, plano 09 — A COLUNA DE PREÇO E RESULTADO DO PAINEL (`:639`-`:693`).
//
// Ela é a METADE DIREITA do corpo do painel, e tem o MESMO PESO da coluna de
// fotos: `minmax(0,1fr) minmax(0,1fr)` (`:586`) é **decisão de desenho
// declarada** (README E2: *"Duas colunas de mesmo peso, para dar o mesmo
// destaque a fotos e preço"*; REVS-06: *"Não reequilibrar"*). ⚠️ A casca vem de
// `ColunaDoPainel` (plano 188-07), que é `:639` byte a byte — **não se reescreve
// a casca aqui**.
//
// A ORDEM, medida por `sed` no protótipo:
//   `:640`       título "Preço e resultado"
//   `:641`-`:646` os 3 KPIs (preço · resultado · margem com barra e nota)
//   `:649`-`:651` o aviso de alteração não conferida + "Conferir alteração"
//   `:654`-`:663` a composição completa com selo de origem por linha
//   `:665`-`:667` a nota do frete do comprador + "Informar frete confirmado"
//   `:669`-`:685` a simulação (selo, input, resultado, atalhos, indisponível)
//   `:687`       a linha da exceção autorizada
//   `:688`-`:692` a comparação com a oferta irmã
//
// ═══ ⚠️⚠️ A TELA EXIBE; ELA NÃO CALCULA ════════════════════════════════════
//
// Tudo aqui vem de `PrecoDoPainelService::para()` (plano 188-04): os três KPIs
// com as cores, a barra por fórmula, as 7 linhas com origem e nota, a frase do
// pior caso, os atalhos com o mínimo resolvido, a exceção e a irmã.
// ⚠️ **Nenhuma aritmética de taxa mora nesta coluna.**
//
// ═══ ⚠️⚠️ A CASCA SÓ NASCE COM CONTEÚDO, E A DECISÃO VEM ANTES ══════════════
//
// ⚠️ Um componente que devolve `null` **por dentro** já desenhou a casca **por
// fora** — e bloco de 14px de padding vazio é D4 violado. É a mesma régua que
// `RaizDoPainel` (plano 188-07) aplica ao corpo rolável: monta-se a lista de
// filhos, filtra-se, e só então se decide desenhar.
// ⚠️ `filhosSeguros` é named export da 188-07 e está em produção: ele filtra os
// filhos **um a um**, porque **prop de NÓ chegando objeto comum faz o React
// LANÇAR** (*"Objects are not valid as a React child"*) e mata a página inteira.
//
// ═══ ⚠️ A FRONTEIRA DESTA PEÇA ═════════════════════════════════════════════
//
// ⚠️ **O painel APROVA (e aprovar ENFILEIRA); ele NÃO publica.** A escrita no
// Mercado Livre é da **Fase 193** (critério 18).
// ⚠️ Pendências e rodapé são da **188-10**; os modais da **188-11/12**; as Fases
// **189** (*"⤢ Ampliar"*) e **192** (*"✦ Gerar novamente com IA"*) **não** são
// antecipadas. **Nada de provisório de nenhuma delas.**
// ⚠️ **NADA de `localStorage`** nem *"Restaurar dados de exemplo"*: são
// artefatos do protótipo (`:914`).
// ═══════════════════════════════════════════════════════════════════════════

/**
 * A coluna de preço e resultado do painel de revisão.
 *
 * @param {object} props
 * @param {unknown} [props.preco] `painel.preco` (plano 188-04)
 * @param {unknown} [props.onConferirAlteracao] `() => void`, ou `null` — a rota
 *   é da wave **188-06** e **não existe** no HEAD de partida (`091340b6`,
 *   medido em `routes/mlb_anuncios.php`). Sem handler o botão não é desenhado,
 *   exatamente como o `onTentarDeNovo` da 188-07 e o `onReprovar` da 188-08.
 */
export default function ColunaDePreco({ preco = null, onConferirAlteracao = null }) {
    const dados = objetoDoPainel(preco);

    // ⚠️ Slot sem dado **não desenha casca**.
    if (dados === null) return null;

    const titulo = textoDoPainel(dados.titulo);

    // ⚠️⚠️ A lista é montada ANTES da casca, e `filhosSeguros` devolve `null`
    // quando nada sobrou: payload sem nenhuma chave útil (um `{}`, ou um objeto
    // adverso) não pode produzir uma borda de 14px vazia.
    const conteudo = filhosSeguros([
        titulo === null ? null : <span key="titulo" className={CLASSES_TITULO}>{titulo}</span>,
        <KpisDoPainel key="kpis" kpis={dados.kpis} />,
        <AvisoDeAlteracao key="alteracao" alteracao={dados.alteracao} onConferir={onConferirAlteracao} />,
    ]);

    // ⚠️ `filhosSeguros` não sabe que um elemento VAI renderizar vazio — ele só
    // tira o que não é nó. Então a decisão de desenhar olha os DADOS: sem
    // título, sem KPI e sem aviso, a coluna inteira some.
    const temKpis = Array.isArray(dados.kpis) && dados.kpis.length > 0;
    const temAviso = objetoDoPainel(dados.alteracao) !== null;

    if (titulo === null && !temKpis && !temAviso) return null;

    return <ColunaDoPainel>{conteudo}</ColunaDoPainel>;
}
