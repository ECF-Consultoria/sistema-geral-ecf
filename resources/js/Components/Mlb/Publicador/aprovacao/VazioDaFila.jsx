import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 09 — OS **DOIS** VAZIOS HONESTOS DA FILA (decisão 16 do
// usuário, 15/10/2026).
//
// ⚠️⚠️ **O PROTÓTIPO TEM UM SÓ, E ISSO É LIMITAÇÃO DELE, NÃO DESENHO.** Em
// `:186`-`:188` há *"Nenhuma oferta neste filtro"* + "Limpar filtros", porque **no
// protótipo sempre existem ofertas**: a semente do navegador cria três. ➡️ No
// produto, **zero oferta sem filtro nenhum é um estado REAL**, e nele o literal do
// protótipo **MENTIRIA** (não há filtro) e o botão "Limpar filtros" **não teria o
// que limpar** — botão sem destino é a definição de enfeite, e este projeto já
// recusou isso três vezes.
//
// ⚠️⚠️ **DECIDIDO PELO USUÁRIO: OS DOIS VAZIOS, COM A RAZÃO DELE.** São **DOIS
// TEXTOS DIFERENTES**, não um com variação cosmética:
//   · **com filtro, zero resultado** → o literal do protótipo + "Limpar filtros";
//   · **sem filtro, zero oferta**    → a frase honesta, **SEM botão**.
//
// ⚠️ **E ISSO É AMPLIAÇÃO COM PRECEDENTE MEDIDO NO PRÓPRIO MÓDULO**, não invenção:
// `resources/js/Components/Mlb/Publicador/publicacoes/VaziosDaLista.jsx` (Fase
// 180) resolveu exatamente este dilema na tela vizinha, e o comentário dele diz
// textualmente: *"cair no 'Esta empresa não tem anúncios ativos' seria MENTIR: o
// servidor disse que o RECORTE está vazio, não que a empresa não tem anúncio."*
//
// ⚠️⚠️ **E O SEGUNDO VAZIO VALE A PARTIR DO DIA 1.** **Nada nesta fase CRIA
// oferta** — quem cria é a **Fase 194** (`AUTO-01`: a oferta nasce em
// `preparando`). Até lá, a fila mostra o que existir em `pub_aprovacao_ofertas`, e
// **fila honestamente vazia NÃO é aba apontando para o vazio**: a aba leva a uma
// tela que funciona, com contador real (que será 0 enquanto for 0). Isso é
// DECLARADO, não pendência.
//
// ⚠️ **O estado vazio NÃO foi escrito pelo plano 187-04 de propósito**, e aquele
// executor estava certo em recusar um provisório: escrever "Nenhuma oferta neste
// filtro" sem filtro nenhum é exatamente a mentira que a decisão 16 proíbe.
//
// ⚠️ **GEOMETRIA MEDIDA POR SCRIPT** em `design_handoff_publicador/
// Aprovacao.dc.html` (1432 linhas, sha256 `68624883ce35547e`).
// ⚠️ **DIVERGÊNCIA DE PONTEIRO DO PLANO:** ele aponta o vazio em `:189`-`:194`;
// os números REAIS são `:185` (o `sc-if`), `:186` (a caixa), `:187` (o título) e
// `:188` (o botão) — **3 a 6 linhas acima**. Os VALORES do plano estão certos.
// ═══════════════════════════════════════════════════════════════════════════

/** `:186` — a caixa. */
export const CLASSES_CAIXA = 'flex flex-col items-center gap-[8px] px-[24px] py-[56px] text-center';

/** `:187` — o título. */
export const CLASSES_TITULO = 'text-[15px] font-semibold';

/** O corpo explicativo — só o segundo vazio o tem (o protótipo não tem corpo nenhum). */
export const CLASSES_CORPO = 'max-w-[460px] text-[12.5px] text-pub-txt-3';

/**
 * `:188` — o botão "Limpar filtros".
 *
 * ⚠️ `cursor-pointer` **está** no protótipo e é fácil de perder (`cursor:pointer` já foi
 * omitido ONZE vezes numa fase deste projeto).
 * ⚠⚠ E `whitespace-nowrap` **NÃO** está em `:188`, ao contrário dos outros seis botões
 * desta wave (`:779`, `:889`, `:890`, `:899`, `:900` — e o `:200`, que não existe no
 * produto). **MEDIDO por script nesta sessão**, e a primeira redação o tinha posto aqui por
 * simetria com os vizinhos. ➡️ **O PROTÓTIPO GANHA**: ele saiu. Simetria que ninguém mediu
 * é exatamente o tipo de "ficou mais parecido com o resto" que FID-02 não aceita.
 */
export const CLASSES_BOTAO =
    'h-[32px] cursor-pointer rounded-[8px] border border-pub-line-strong bg-pub-surface px-[14px] text-[12.5px] text-pub-txt';

/**
 * ⚠️⚠️ **OS DOIS VAZIOS, E ELES SÃO TEXTOS DIFERENTES.**
 *
 * O primeiro é o literal do protótipo (`:187`). O segundo é novo, e existe porque
 * o primeiro **mentiria** num estado que o protótipo não tem.
 */
export const TEXTOS_DOS_VAZIOS = {
    // ─── COM filtro ativo e zero resultado: o literal do protótipo (`:187`) ───
    //
    // ⚠️ "neste filtro" é verdade aqui: existe recorte, e ele não casou nada. O
    // botão TEM destino (limpar o recorte), então ele existe.
    com_filtro: {
        titulo: 'Nenhuma oferta neste filtro',
        corpo: null,
        rotuloDoBotao: 'Limpar filtros',
    },

    // ─── SEM filtro nenhum e zero oferta: o vazio HONESTO ───
    //
    // ⚠️⚠️ **SEM BOTÃO, E ISSO É O PONTO.** Não há filtro para limpar: um botão
    // "Limpar filtros" aqui não faria nada, e um botão que não faz nada ensina o
    // usuário a desconfiar dos outros.
    // ⚠️ E o corpo diz **por que** está vazio e que **vazio não é erro** — é a
    // diferença entre "a tela quebrou" e "ainda não há o que aprovar". Enquanto a
    // Fase 194 não existir, este é o estado NORMAL da tela.
    sem_filtro: {
        titulo: 'Nenhuma oferta aguardando aprovação.',
        corpo: 'As ofertas entram nesta fila quando o sistema termina de preparar o anúncio. Enquanto isso, nada aqui é um erro.',
        rotuloDoBotao: null,
    },
};

/**
 * O vazio da fila, nos DOIS casos.
 *
 * ⚠️ `algumFiltro` é o MESMO booleano que o servidor já calcula para a linha de
 * filtros (`FiltrosDaFilaService::algumFiltro()`), e ele é **lido**, nunca
 * recalculado aqui: duas respostas para "há filtro?" produziriam o texto errado
 * exatamente no caso que esta peça existe para acertar.
 *
 * @param {boolean} algumFiltro    há recorte ativo?
 * @param {Function|null} onLimpar limpa os filtros (só usado no 1º caso)
 */
export default function VazioDaFila({ algumFiltro = false, onLimpar = null }) {
    // ⚠️ A comparação é ESTRITA: `algumFiltro` chegando `'false'` (string), `0` ou
    // objeto não pode acender o texto do filtro. Default é o vazio HONESTO —
    // quando em dúvida, a tela diz a verdade mais fraca, não a mais específica.
    const comFiltro = algumFiltro === true;

    const textos = comFiltro ? TEXTOS_DOS_VAZIOS.com_filtro : TEXTOS_DOS_VAZIOS.sem_filtro;

    // ⚠️⚠️ O botão só existe se (a) o caso o prevê **e** (b) há handler. Sem
    // handler ele seria um botão sem destino — o que esta peça existe para evitar.
    const desenhaBotao = textos.rotuloDoBotao !== null && typeof onLimpar === 'function';

    return (
        <div className={CLASSES_CAIXA}>
            <span className={CLASSES_TITULO}>{textos.titulo}</span>

            {textos.corpo === null ? null : <span className={CLASSES_CORPO}>{textos.corpo}</span>}

            {!desenhaBotao ? null : (
                <button type="button" onClick={() => onLimpar()} className={cn(CLASSES_BOTAO)}>
                    {textos.rotuloDoBotao}
                </button>
            )}
        </div>
    );
}
