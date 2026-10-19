import { cn } from '@/lib/utils';
import {
    inteiroDoPainel,
    listaDoPainel,
    objetoDoPainel,
    textoDoPainel,
} from './ImagemGrandeDoPainel';
import { percentualSeguro } from './BarraDeMargem';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 188, plano 09 — OS 3 KPIs DA COLUNA DE PREÇO (`:641`-`:646`), a BARRA DA
// MARGEM com o traço do mínimo, e o AVISO DE ALTERAÇÃO NÃO CONFERIDA
// (`:649`-`:651`).
//
// ⚠️⚠️ PROTÓTIPO DE AUTORIDADE: `design_handoff_publicador/Aprovacao.dc.html`
// (1432 linhas, sha256 68624883ce35547e…). ⚠️ **CITE O DIRETÓRIO:** há DUAS
// cópias do pacote de handoff com conteúdo DIFERENTE, e `new_layout_publicador/`
// é a ANTIGA.
//
// ═══ ⚠️⚠️ A TELA EXIBE; ELA NÃO CALCULA ════════════════════════════════════
//
// Os três KPIs, a largura da barra, a posição do traço, as cores e a nota vêm
// TODOS de `PrecoDoPainelService::para()` (plano 188-04). ⚠️ **Nenhuma
// aritmética de taxa mora aqui** — nem `* 0.12`, nem `/ (1 - margem)`, nem
// percentual recalculado. Conta feita na tela divergiria da conta do servidor na
// primeira edição, e a divergente seria a que a pessoa lê.
//
// ═══ ⚠️⚠️ VOC-03 / D6 — O NOME DO NÚMERO ═══════════════════════════════════
//
// O rótulo do KPI 2 é **"Resultado estimado por venda"**, e ele NÃO é escrito
// aqui: vem do servidor, que o lê de `ComposicaoDaOferta::rotuloDoResultado()`,
// que por sua vez espelha `VocabularioDoPortao::RESULTADO_ESTIMADO`. Escrevê-lo
// nesta tela seria a QUARTA redação do mesmo nome.
// ⚠️ **NUNCA "lucro líquido"**: o valor não desconta imposto sobre o lucro, taxa
// de cartão, despesa fixa nem devolução. Há gate de ausência recortado à lista
// CONSTANTE dos arquivos deste plano — `lucro_liquido` é campo **legítimo** do
// Portal, e varrer `app/` barrou um deploy na Fase 186.
//
// ═══ ⚠️⚠️ DECISÃO 9 DO USUÁRIO — CADA MARGEM DIZ DE ONDE VEM ════════════════
//
// A nota da margem contém `· com a tarifa real do Mercado Livre`, e ela já chega
// FECHADA de `ComposicaoDaOferta:271` (medido por `sed` nesta wave). ⚠️ **A tela
// LÊ; não remonta.** Deixar a margem sem texto foi **RECUSADO** pelo usuário.
// ⚠️ E esta margem NÃO se unifica com a da visão rápida do lote
// (`app/Services/Publicador/Fila/ResumoRapidoService:384`-`:391` — ⚠️ o caminho
// **NÃO** é `.../Aprovacao/Fila/`): o docblock de `EditorRascunhoService:370`-
// `:372` diz *"diferente DE PROPÓSITO… não unificar"*. São DUAS de propósito, e
// é por isso que cada uma nomeia a sua fonte.
//
// ═══ ⚠️⚠️ A BARRA É FÓRMULA, NUNCA O `30%` LITERAL ═════════════════════════
//
// `:645` escreve `left:30%` **porque `MIN = 12`** (`:914`) e `12/40 = 30%`.
// Cravar a posição amarraria a tela a um mínimo que vem do parâmetro da
// Precificação (FIN-09) e é outro por empresa — a barra passaria a discordar do
// próprio traço, e a discordância seria INVISÍVEL (ninguém olha um traço de
// 1,5px e desconfia). ➡️ Largura e posição vêm os dois de
// `LinhaDaOfertaService::percentualDaBarra()` (`min(40, max(0, m))/40`), uma
// função só, e a tela só os repassa. É a divergência **D10** da Fase 187,
// resolvida do mesmo jeito lá.
//
// ⚠️⚠️ **E ELES VÃO POR `style`, NUNCA POR CLASSE CONCATENADA.** Classe montada
// por template literal o scanner do Tailwind **não vê**: ela fica no HTML, todo
// teste de render passa, e **a barra não desenha na tela**. Mesma família do
// `.js` que o `content` não varre e do `duration-[…]` arbitrário que não compila
// neste projeto.
//
// ═══ ⚠️⚠️ A DECISÃO SOBRE `BarraDeMargem.jsx`, MEDIDA E DECLARADA ═══════════
//
// `aprovacao/BarraDeMargem.jsx` (Fase 187, plano 08) é a barra da LINHA DA FILA.
// Medi as duas geometrias antes de decidir, por `sed` no protótipo:
//
//   · a TRILHA e o PREENCHIMENTO são IDÊNTICOS (`height:4px`,
//     `border-radius:2px`, fundo `#1F1F1F`; preenchimento absoluto à esquerda) —
//     `:163` (fila) e `:645` (painel);
//   · ⚠️ **o TRAÇO DO MÍNIMO tem COR DIFERENTE:** `:163` usa `#71717A`
//     (`pub-txt-4`) e `:645` usa `#A1A1AA` (`pub-txt-2`). Medido nos dois
//     pontos;
//   · ⚠️ **o PREENCHIMENTO tem COR DIFERENTE no ramo normal:** `:1246` (fila)
//     usa `#A1A1AA` e `:1132` (painel) usa `#D4D4D8` (`pub-fase1`). A `188-FID01`
//     §L já media isso e cunhou a chave semântica `clara` só para o painel —
//     `CORES_DA_BARRA` da fila **não tem** essa chave;
//   · ⚠️ **o WRAPPER é outro:** a célula da fila é `flex flex-col gap-[6px]
//     pr-[12px]` com o percentual de 13px ACIMA; a célula do painel é
//     `gap:6px;padding:12px` com o valor de **21px** acima e a NOTA abaixo.
//
// ➡️ **Decisão: reuso a função PURA e construo a barra do painel.** O que se
// reusa é `percentualSeguro()` — a forma fechada que recusa `NaN%`, CSS inválido
// e percentual acima de 100 —, que é `export` daquele arquivo e **não é
// alterada**. O desenho é próprio, porque três das quatro medidas divergem.
// ⚠️ *"O componente que já existe faz parecido"* é justificativa **VETADA**
// (FID-02) — e forçar o reuso de uma geometria diferente é a outra metade do
// MESMO erro. ⚠️ Um gate que exigisse a MESMA chave de cor nos dois reprovaria
// código certo (está escrito na `188-FID01.md` §L).
//
// ═══ ⚠️ D7 — A COR NUNCA É O ÚNICO SINAL ═══════════════════════════════════
//
// Cada estado leva TEXTO: resultado sem dado sai `Incompleto` (palavra, não
// cinza), margem sem dado sai `—` (nunca `0%`, que AFIRMARIA margem zero), e a
// nota escreve `prejuízo por venda` / `abaixo do mínimo de N%` / `dados
// incompletos`. ⚠️ **Jargão não vai para a tela.**
//
// ═══ ⚠️ A FRONTEIRA DESTA PEÇA ═════════════════════════════════════════════
//
// ⚠️ **O painel APROVA (e aprovar ENFILEIRA); ele NÃO publica.** A escrita no
// Mercado Livre é da **Fase 193** (critério 18).
// ⚠️ **O "Conferir alteração" é DESENHADO AQUI, com prop, e a ROTA É DA WAVE
// 188-06.** Medido no HEAD de partida (`091340b6`): `routes/mlb_anuncios.php`
// **não tem** nenhuma rota de conferir alteração nem de preço — as que existem
// são `lote`, `imagem.aprovar`, `fotos.sem-alerta`, `imagem.reprovar`,
// `salvar-revisao`, `aprovar` e `tentar-de-novo`. ➡️ Sem handler o botão **não é
// desenhado**, exatamente como o "Tentar publicar novamente" nasceu na 188-07.
// ⚠️ **Nada de `onClick` vazio, nada de `disabled` decorativo** (D4).
// ⚠️ E quando a rota chegar, a escrita vai pelo helper único
// `escreverNoPainel` (W11 / COL-04): `versao_esperada` é OPCIONAL no corpo, e
// omiti-la faz `exigirVersao()` receber `null` e **LIBERAR** a escrita — que é
// literalmente *"sobrescrever em silêncio"*, o que o critério 17 proíbe.
// ⚠️ As Fases **189** (*"⤢ Ampliar"*) e **192** (*"✦ Gerar novamente com IA"*)
// **não** são antecipadas. Pendências e rodapé são da **188-10**, os modais da
// **188-11/12** — nada de provisório de nenhuma delas.
// ⚠️ **NADA de `localStorage`** nem *"Restaurar dados de exemplo"*: são
// artefatos do protótipo (`:914`).
// ⚠️ Nenhum `String(valor)`, nenhum `hasOwnProperty` solto e nenhum
// `obj[chaveDoServidor]` cru: os três **LANÇAM** com `{toString: null}`, e a
// TELA PRETA voltou SEIS vezes neste projeto — uma delas **no clique**.
// ═══════════════════════════════════════════════════════════════════════════

// ─── Os guardas, REUSADOS e não recopiados ─────────────────────────────────
//
// ⚠️ Eles são `export` de `ImagemGrandeDoPainel.jsx` (plano 188-08) e estão em
// produção; `ColunaDeFotos.jsx` já os importa dali. Re-exportá-los daqui dá UMA
// porta de entrada para os outros três `.jsx` desta coluna, sem criar a segunda
// cópia de `typeof valor === 'string' && valor.trim() !== ''` — que é
// exatamente o tipo de duplicata que divergiu neste projeto antes.
export { inteiroDoPainel, listaDoPainel, objetoDoPainel, textoDoPainel };

/**
 * ⚠️⚠️ A CHAVE NEUTRA DESTA COLUNA É `neutro`, E A DA COLUNA DE FOTOS É
 * `neutra` — MEDIDO, e é por isso que `classeDaCor()` daquele arquivo NÃO serve
 * aqui.
 *
 * `FotosDoPainelService` emite `neutra`/`neutra_fraca`;
 * `PrecoDoPainelService::COR_NEUTRA` é **`'neutro'`**. O `classeDaCor()` da
 * coluna de fotos cai em `COR_NEUTRA = 'neutra'` quando a chave é desconhecida —
 * e nos mapas desta coluna essa chave **não existe**, então o fallback
 * devolveria `undefined` e a cor sairia do nada.
 * ➡️ Aqui o padrão é PARÂMETRO EXPLÍCITO, como no `classeDe()` privado de
 * `BarraDeMargem.jsx`. **Não é duplicata de comportamento: é outro contrato.**
 */
export const CHAVE_NEUTRA = 'neutro';

/**
 * A cor do TEXTO, por chave semântica de `PrecoDoPainelService` (TOK-15: nenhum
 * hex sai do PHP).
 *
 * `texto` #F4F4F5 · `clara` #D4D4D8 · `apagada` #8A8A90 · `neutro` #71717A ·
 * `ambar` #FBBF24 · `vermelho` #F87171 · `verde` #4ADE80.
 *
 * ⚠️ `apagada` é o ramo final de `:1133` (a nota da margem quando está acima do
 * mínimo) e **não** é `neutro`: são dois cinzas diferentes no protótipo.
 */
export const TEXTO_DA_COR = {
    texto: 'text-pub-txt',
    clara: 'text-pub-fase1',
    apagada: 'text-pub-txt-3',
    neutro: 'text-pub-txt-4',
    ambar: 'text-pub-warn-txt',
    vermelho: 'text-pub-danger-txt',
    verde: 'text-pub-ok-txt',
};

/**
 * A cor do PREENCHIMENTO da barra (`:1132`).
 *
 * ⚠️⚠️ **São hex DIFERENTES dos do texto, e isso é do protótipo, não descuido:**
 * `#3F3F46`/`#EF4444`/`#F59E0B`/`#D4D4D8` contra
 * `#71717A`/`#F87171`/`#FBBF24`/`#F4F4F5`. Barra é tinta sólida; texto é a
 * versão clara. Unificar os dois mapas mudaria as duas coisas.
 * ⚠️ `clara` é a chave do PAINEL. A da FILA no ramo normal é `suave` (#A1A1AA,
 * `:1246`) — e são diferentes DE PROPÓSITO (`188-FID01.md` §L).
 */
export const FUNDO_DA_BARRA = {
    trilha: 'bg-pub-line-off',
    barra_baixa: 'bg-pub-danger',
    barra_alerta: 'bg-pub-warn',
    clara: 'bg-pub-fase1',
};

/**
 * A BORDA translúcida do selo de origem (`:1125`), por chave semântica.
 *
 * ⚠️ As três origens têm texto E borda diferentes entre si, e isso é sinal: duas
 * iguais apagariam a distinção entre *Confirmado* e *Pendente* — o primeiro diz
 * que o número foi conferido, o segundo que ele **não existe**.
 */
export const BORDA_DA_ORIGEM = {
    verde_fraca: 'border-pub-ok/[0.35]',
    trilha: 'border-pub-line-off',
    vermelha_fraca: 'border-pub-danger/[0.45]',
};

/**
 * A classe de um mapa FECHADO, com guarda de tipo e padrão EXPLÍCITO.
 *
 * ⚠️ `mapa[chave]` cru **LANÇA** com `{toString: null}` (a busca de chave coage
 * para string), e `obj.hasOwnProperty(...)` também — os dois derrubaram a página
 * inteira neste projeto. A guarda de tipo vem ANTES, e o `hasOwnProperty` é o do
 * `Object.prototype`, chamado com `.call`.
 * ⚠️ O padrão **nunca é verde**: afirmar que um número foi confirmado quando a
 * chave é desconhecida é o pior lado do erro nesta coluna.
 */
export function classeDaCorDoPreco(mapa, chave, padrao) {
    if (typeof chave === 'string' && Object.prototype.hasOwnProperty.call(mapa, chave)) {
        return mapa[chave];
    }

    return padrao;
}

// ─── As classes, uma casa só (`:640`-`:651`) ───────────────────────────────

/** `:640` — o título da seção. */
export const CLASSES_TITULO = 'text-[15px] font-semibold';

/** ⚠️ `:641` — a grade dos TRÊS KPIs. Igualdade de STRING: `grid-cols-3` NÃO serve. */
export const CLASSES_GRADE = 'grid grid-cols-[repeat(3,minmax(0,1fr))] rounded-pub-sub border border-pub-line-subtle';

/** `:642`/`:643` — a célula dos KPIs 1 e 2 (`gap:4px`). */
export const CLASSES_CELULA = 'flex flex-col gap-[4px] p-[12px]';

/** `:644` — a célula do KPI 3 tem `gap:6px`, e é por causa da barra e da nota. */
export const CLASSES_CELULA_MARGEM = 'flex flex-col gap-[6px] p-[12px]';

/** `:643`/`:644` — a divisória das células 2 e 3. ⚠️ A célula 1 **não** a tem. */
export const CLASSES_DIVISORIA = 'border-l border-pub-line-subtle';

/** `:642` — o rótulo do KPI. */
export const CLASSES_ROTULO = 'text-[11.5px] text-pub-txt-3';

/** ⚠️ `:642` — o valor de 21px. **FID-03: `text-[21px]`, nunca `text-xl`** (20px). */
export const CLASSES_VALOR = 'text-[21px] font-semibold tracking-[-0.02em] whitespace-nowrap tabular-nums';

/** `:645` — a trilha de 4px (`#1F1F1F` = `pub-line-subtle`). */
export const CLASSES_TRILHA = 'relative h-[4px] rounded-[2px] bg-pub-line-subtle';

/** `:645` — o preenchimento; a LARGURA vem por `style`. */
export const CLASSES_PREENCHIMENTO = 'absolute inset-y-0 left-0 rounded-[2px]';

/**
 * `:645` — o traço do mínimo; a POSIÇÃO vem por `style`.
 *
 * ⚠️ `pub-txt-2` (#A1A1AA) e **não** `pub-txt-4` (#71717A), que é o da fila
 * (`:163`). Medido nos dois pontos.
 */
export const CLASSES_TRACO = 'absolute top-[-3px] h-[10px] w-[1.5px] bg-pub-txt-2';

/** `:646` — a nota da margem. ⚠️ A cor dela NÃO é a cor do número (`:1133`). */
export const CLASSES_NOTA = 'text-[11px]';

/** `:649` — a caixa do aviso de alteração (fundo `#14120A` = `pub-row-new`). */
export const CLASSES_AVISO = 'flex items-center gap-[10px] rounded-pub-control border '
    + 'border-pub-accent/[0.35] bg-pub-row-new px-[12px] py-[9px] text-[12.5px]';

/** `:650` — o glifo `↻`. ⚠️ Cor não é o único sinal: o texto vem ao lado. */
export const CLASSES_GLIFO = 'font-bold text-pub-accent';

/** `:650` — o texto do aviso, que OCUPA o resto da linha. */
export const CLASSES_TEXTO_DO_AVISO = 'flex-1 text-pub-fase1';

/** `:651` — "Conferir alteração". ⚠️ `white-space:nowrap` é a 1ª propriedade da linha. */
export const CLASSES_CONFERIR = 'h-[26px] cursor-pointer whitespace-nowrap rounded-pub-chip border '
    + 'border-pub-accent/[0.5] bg-transparent px-[10px] text-[12px] font-semibold text-pub-accent';

/**
 * ⚠️ A ORDEM dos 3 KPIs é CONTRATO, e ela é do servidor
 * (`PrecoDoPainelService::KPIS_EM_ORDEM`). Esta lista existe para o gate provar
 * que a tela **não reordena** — ela não é usada para montar nada.
 */
export const CHAVES_DOS_KPIS = ['preco_de_venda', 'resultado', 'margem'];

/**
 * UM KPI — rótulo em cima, valor de 21px embaixo, e (no terceiro) a barra e a
 * nota.
 *
 * ⚠️⚠️ **NAMED EXPORT, e não é zelo:** `renderToStaticMarkup` **não dispara
 * `onClick`**, e o render de SERVIDOR do React **não renderiza portal** — o
 * painel inteiro é portado, então medir pelo composto veria HTML vazio e
 * passaria **POR OMISSÃO**. É a solução que a Fase 178 mediu e que a 187-09 e a
 * 188-07/08 repetiram.
 *
 * @param {object} props
 * @param {unknown} [props.kpi] uma entrada de `preco.kpis` (plano 188-04)
 * @param {unknown} [props.comDivisoria] se a célula leva o `border-left`
 */
export function CelulaDeKpi({ kpi = null, comDivisoria = false }) {
    // ⚠️ Flag DENTRO do componente, nunca içada para fora do `.map()` do pai: o
    // Rollup ELIMINA variável de escopo do componente usada dentro de `.map()`,
    // e o bundle sai com `ReferenceError`.
    const dado = objetoDoPainel(kpi);
    if (dado === null) return null;

    const rotulo = textoDoPainel(dado.rotulo);
    const valor = textoDoPainel(dado.valor);
    const barra = objetoDoPainel(dado.barra);
    const nota = textoDoPainel(dado.nota_da_margem);

    // ⚠️ Sem rótulo E sem valor a célula não nasce: casca de 12px vazia é D4
    // violado, e no grid de três ela empurraria as outras duas.
    if (rotulo === null && valor === null) return null;

    const corDoValor = classeDaCorDoPreco(TEXTO_DA_COR, dado.cor, TEXTO_DA_COR.texto);
    const corDaNota = classeDaCorDoPreco(TEXTO_DA_COR, dado.nota_cor, TEXTO_DA_COR.neutro);

    return (
        <div className={cn(barra === null ? CLASSES_CELULA : CLASSES_CELULA_MARGEM, comDivisoria === true && CLASSES_DIVISORIA)}>
            {rotulo === null ? null : <span className={CLASSES_ROTULO}>{rotulo}</span>}
            {valor === null ? null : <span className={cn(CLASSES_VALOR, corDoValor)}>{valor}</span>}
            {barra === null ? null : <BarraDoPainel barra={barra} />}
            {/* ⚠️ Decisão 9 — a frase que NOMEIA a tarifa real, LIDA do servidor. */}
            {nota === null ? null : <span className={cn(CLASSES_NOTA, corDaNota)}>{nota}</span>}
        </div>
    );
}

/**
 * `:645` — a barra de 0 a 40% com o traço do mínimo.
 *
 * ⚠️ A barra INTEIRA aparece sempre que o KPI traz a chave `barra`, inclusive
 * sem margem: com largura zero sobre a trilha neutra. Sumir com ela encolheria a
 * célula e desalinharia o grid de três.
 * ⚠️ `percentualSeguro()` é a SEGUNDA porta (o servidor já clampa): ela recusa
 * qualquer coisa que não seja `<dígitos>[.<dígitos>]%` entre 0 e 100. `NaN%` é
 * CSS inválido — a barra desapareceria **sem erro nenhum**.
 */
export function BarraDoPainel({ barra = null }) {
    const dado = objetoDoPainel(barra);
    if (dado === null) return null;

    const largura = percentualSeguro(dado.largura);
    const minimo = percentualSeguro(dado.minimo);
    const corDaBarra = classeDaCorDoPreco(FUNDO_DA_BARRA, dado.cor, FUNDO_DA_BARRA.trilha);

    return (
        <span className={CLASSES_TRILHA}>
            {/* ⚠️ A largura por `style`, NUNCA por classe concatenada. */}
            <span aria-hidden="true" className={cn(CLASSES_PREENCHIMENTO, corDaBarra)} style={{ width: largura }} />
            {/* ⚠️ D10 — `minimo/40`, calculado no servidor pela MESMA fórmula. */}
            <span aria-hidden="true" className={CLASSES_TRACO} style={{ left: minimo }} />
        </span>
    );
}

/**
 * `:649`-`:651` — o aviso de alteração não conferida.
 *
 * ⚠️ Ele só existe quando o servidor manda `alteracao` (ou seja, quando
 * `preco_alterado` tem `de` e `para`). O texto traz **quem e quando**, e já vem
 * montado de `PrecoDoPainelService::alteracao()` — sem autor ele diz *"autor não
 * registrado"* em vez de mentir.
 * ⚠️ O rótulo do botão é `alteracao.correcao`, que o servidor lê de
 * `VocabularioDoPortao::CORRECOES` — a tela não o escreve.
 * ⚠️ **Sem `onConferir` o botão NÃO É DESENHADO** (a rota é da wave 188-06), mas
 * o TEXTO do aviso continua aparecendo: é a mesma régua da PERGUNTA-188-B, em
 * que o destino fica `null` e o texto do bloqueio permanece.
 */
export function AvisoDeAlteracao({ alteracao = null, onConferir = null }) {
    const dado = objetoDoPainel(alteracao);
    if (dado === null) return null;

    const texto = textoDoPainel(dado.texto);
    if (texto === null) return null;

    const glifo = textoDoPainel(dado.glifo);
    const rotulo = textoDoPainel(dado.correcao);
    const conferir = typeof onConferir === 'function' ? onConferir : null;

    return (
        <div className={CLASSES_AVISO}>
            {glifo === null ? null : <span className={CLASSES_GLIFO}>{glifo}</span>}
            <span className={CLASSES_TEXTO_DO_AVISO}>{texto}</span>
            {rotulo === null || conferir === null ? null : (
                <button type="button" className={CLASSES_CONFERIR} onClick={() => conferir()}>
                    {rotulo}
                </button>
            )}
        </div>
    );
}

/**
 * Os 3 KPIs, na ordem em que o servidor os manda.
 *
 * ⚠️⚠️ **A TELA NÃO REORDENA.** `PrecoDoPainelService::KPIS_EM_ORDEM` é o
 * contrato (preço → resultado → margem), e uma segunda ordenação aqui seria a
 * terceira lista do mesmo fato — a divergente seria a que a pessoa lê.
 * ⚠️ As células 2 e 3 levam `border-left`; a 1 **não**. A divisória é POSIÇÃO,
 * não propriedade do KPI, e é por isso que ela é decidida pelo índice.
 *
 * @param {object} props
 * @param {unknown} [props.kpis] `preco.kpis` — lista de 3 (plano 188-04)
 */
export default function KpisDoPainel({ kpis = null }) {
    const lista = listaDoPainel(kpis);

    // ⚠️ Sem KPI nenhum a grade não nasce: três células vazias com borda de 10px
    // é casca que não informa (D4).
    if (lista.length === 0) return null;

    return (
        <div className={CLASSES_GRADE}>
            {lista.map((kpi, indice) => {
                // ⚠️⚠️ Flag DENTRO do callback. O Rollup elimina variável de escopo
                // do componente usada dentro de `.map()` — e o que este callback lê
                // é PARÂMETRO (`kpi`, `indice`) e nome de MÓDULO (`CelulaDeKpi`),
                // que é a forma que a wave 187-09 mediu como segura.
                const chave = objetoDoPainel(kpi)?.chave;

                return (
                    <CelulaDeKpi
                        key={typeof chave === 'string' && chave !== '' ? chave : `kpi-${indice}`}
                        kpi={kpi}
                        comDivisoria={indice > 0}
                    />
                );
            })}
        </div>
    );
}
