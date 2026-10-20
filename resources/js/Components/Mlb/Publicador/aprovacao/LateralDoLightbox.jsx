import { Fragment } from 'react';
import { ImageOff } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
    CLASSES_IMAGEM,
    CLASSES_PLACEHOLDER,
    TEXTO_DA_COR,
    classeDaCor,
    objetoDoPainel,
    textoDoPainel,
} from './ImagemGrandeDoPainel';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 189, plano 05 — A LATERAL DE 320px DO LIGHTBOX (`:744`-`:770` do
// protótipo `design_handoff_publicador/Aprovacao.dc.html`, §C da
// `189-FID01.md`): o "Fechar · Esc", os metadados, a ORIGINAL DE REFERÊNCIA (ou
// o estado declarado de IMG-13), as verificações numeradas, o botão de zoom e
// Reprovar / Aprovar foto.
//
// ⚠️ Ela **não** usa portal: é a coluna 2 do grid `minmax(0,1fr) 320px` da
// `RaizDoLightbox` (189-03), que já está no portal. Chega lá pelo slot `lateral`,
// que aceita **`isValidElement` e nada mais** — então quem a monta (o 189-06)
// passa um NÓ PRONTO, não uma função.
// ⚠️ Mesmo assim **todas as peças são named exports**: é por elas que os gates
// medem, sem depender de o portal portar.
//
// ⚠️⚠️ O QUE ESTE ARQUIVO **NÃO PROVA**, E A RESSALVA É PARTE DO ENTREGÁVEL:
// não há navegador nesta máquina, o `mysqld` local está parado, o banco não tem
// empresa com `ml_token` (a rota dá 404) e `pub_aprovacao_ofertas` está vazia.
// Que o clique DISPARE, que a imagem CARREGUE e que a rolagem da lateral
// (`overflow-y-auto`) funcione **não é provado aqui** — é a conferência visual da
// conta **459** (plano 189-08). *"A tela mostra X"* significa SEMPRE *"o HTML
// gerado contém X"*.
//
// ═══ O QUE NÃO É DESTA FASE (D-189-3, decisão do usuário de 19/10/2026) ═════
//
// ⚠️⚠️ **"Comparar lado a lado na revisão detalhada" (`:756`) NÃO É DESENHADO** —
// dono: **Fase 190**. Nem desabilitado, nem com `onClick` vazio, nem "em breve":
// botão sem destino **não nasce** (D4), e *"desabilitado com explicação"* foi
// OFERECIDO ao usuário e RECUSADO. ➡️ **O critério 4 sai com RESSALVA, dono Fase
// 190 — decisão do usuário de 19/10/2026 (precedente B3 da 188).**
// ⚠️⚠️ **"✦ Gerar novamente com IA" (`:762`) NÃO É DESENHADO** — dono: **Fase
// 192**. ➡️ **O trecho de AMPL-07 que fala dele sai com RESSALVA, dono Fase 192.**
// ⚠️ O gate de ausência dos dois lê esta fonte **SEM COMENTÁRIO**: é por isso que
// este docblock pode CITAR os dois literais (isca do leitor) e o gate fica verde.
//
// ⚠️ **O "Ampliar 2,5×" DESTE arquivo é OUTRA coisa** (D-189-3a): é o liga/desliga
// do zoom (`:763`, `{{ LB.zoomLabel }}` com `onClick={{ LB.onZoom }}`), **tem
// destino** e **é entregue**. Os dois rótulos vêm de `rotuloDoZoom`, IMPORTADO de
// `LightboxDaImagem.jsx` — uma fonte só para os dois literais.
//
// ═══ A REGRA ÚNICA DE REUSO (`189-REGUA.md` §2): O QUE É IMPORTADO ══════════
//
// Peça que a 188 já desenha é **IMPORTADA**, nunca redesenhada — duas verdades
// sobre o mesmo estado divergem, e aqui a divergência é *"a pessoa reprova a foto
// errada"* ou *"vê verde numa foto que não está aprovada"*:
//   · `TEXTO_DA_COR` + `classeDaCor` (`ImagemGrandeDoPainel`, INV-08) — a cor do
//     Estado, a MESMA do selo do 188-08. Chave desconhecida cai em `neutra` e
//     **nunca** no verde: *"afirmar aprovação que não existe é o pior lado do
//     erro"*, porque **aprovar JÁ ENFILEIRA e não há desfazer**.
//   · `CLASSES_PLACEHOLDER` (`ImagemGrandeDoPainel`, INV-11) — o estado de
//     `url_arquivo` nula. **NUNCA `<img src="">`**: string vazia num `src` faz o
//     navegador recarregar a própria página.
//   · `CLASSES_IMAGEM` — a `<img>` `object-contain` de `fit="contain"` (§H1).
//   · `textoDoPainel` / `objetoDoPainel` — os saneadores. ⚠️ **Nenhum
//     `String(valor)`** (há gate da 187-04 proibindo nesta região) e **nenhum
//     `obj[chave]` com chave do servidor**: os dois **LANÇAM** com
//     `{toString: null}`, e a TELA PRETA voltou SEIS vezes nesta milestone.
//
// ⚠️⚠️ **A LINHA "Variação" (`:749`, §C7) NÃO É DESENHADA**, e a razão é MEDIDA:
// o 188-08 **não desenha variação nenhuma** (`189-CONTRATO-188.md` INV-10 /
// ACHADO A3), porque o payload só traz a **CHAVE CANÔNICA** do grupo
// (`ChaveCanonica::combinacao`, na prática `COLOR=id:52049`) e o rótulo humano
// vive em `ResolvedorGruposImagem::rotuloDoGrupo`, `private static` em
// `App\Support\Publicador` — território **VETADO** nesta fase. A régua §2 manda
// *"a 189 USA O QUE O 188-08 USAR"*, e o usuário decidiu em 19/10 **seguir sem o
// nome e anotar a lacuna** (PERGUNTA-188-E devolvida).
// ➡️ **Inventar "Cor · Azul" seria tradução minha; escrever `COLOR=id:52049` na
// tela é jargão indo para a tela, que a régua proíbe (VOC-06).** A grade sai com
// **TRÊS** pares: Origem · Posição · Estado. **Isto NÃO é um quinto conflito
// protótipo × regra do projeto: é lacuna de DADO já decidida pelo usuário.**
//
// ⚠️ **O estado IMG-13 e o ORDINAL não têm medida no protótipo** (`189-FID01.md`
// §H3/§H4): o protótipo **esconde** o bloco (`:752`, `LB.hasRef`) e o item de
// `:760` só desenha `a.glyph`. Os valores de geometria do estado IMG-13 são
// **escolhidos, sem medida no protótipo** — e declarados aqui e na SUMMARY. Os
// tamanhos (`12`, `11.5`) saíram DENTRO de `TAMANHOS_PERMITIDOS`: nada a liberar.
//
// ⚠️⚠️ **O TEXTO DO ESTADO IMG-13 VEM DO SERVIDOR.** `rotulo`, `apoio` e `icone`
// são **LIDOS da prop** `lightbox.originais[<id da revisão>]`, que o 189-02 tira
// de `PubImagemReferencia::SEM_ORIGINAL_ROTULO` / `_APOIO` / `_ICONE`. A tela
// **NÃO reescreve** o texto — é por isso que o literal
// *"Original de referência não disponível"* aparece neste comentário e **não** no
// código: uma segunda cópia divergiria da do modelo.
//
// ⚠️ **Imagem Original não tem o bloco da original.** IMG-13 fala de **criativo
// gerado sem vínculo**; mostrar "original não disponível" sobre uma foto que **É**
// a original seria MENTIRA. O protótipo faz o mesmo por outro caminho (`:752`).
//
// ═══ O EFEITO COLATERAL DA URL DA ORIGINAL, DECLARADO ═══════════════════════
//
// ⚠️⚠️ A `url_arquivo` da original (montada pelo 189-02) reusa a rota de foto do
// Publicador, e **aquela rota passa por `EditorEmUso::marcar`**: abrir a original
// no lightbox **SUSPENDE o agendamento e o preparo por IA daquele produto por 3
// minutos** (`config/publicador.php`, chave `publicador.preparo_ia.editor_em_uso_min`).
// É efeito que o usuário **ACEITOU** em 19/10/2026 (*"é o que o portão quer"* —
// revisar é trabalhar no produto); *"servir a foto sem marcar"* foi oferecido e
// RECUSADO.
// ⚠️ **Os leitores do sinal, por NOME de arquivo e de MÉTODO — nunca por linha**
// (a redação por linha da 188-03 REPROVOU trabalho correto quando um commit de
// outra sessão deslocou as chamadas): **cinco arquivos, seis pontos** —
// `AgendadorDaFila::checar`, `PreparoIaDoRascunhoService::preparar`,
// `PreparoIaDoRascunhoService::sincronizarAgora`,
// `PreparoIaDoRascunhoService::executarEtapa`, `GarantiaPadrao::aplicarNaConta`,
// `ExcluirProdutoService::impedimento`.
// ⚠️ **REUSAR NÃO É EDITAR:** nada em `App\Support\Publicador`,
// `Acervo*`/`ImagemAssetService` é tocado aqui.
//
// ⚠️ **APROVAR ENFILEIRA, NÃO PUBLICA** — a escrita no Mercado Livre é da **Fase
// 193**. E **este arquivo não escreve nada**: Aprovar/Reprovar chegam como PROPS
// e a escrita vive no painel, pelo helper único `escreverNoPainel` (188-07).
// ═══════════════════════════════════════════════════════════════════════════

// ─── As classes, LITERAIS da §C (o scanner do Tailwind lê a FONTE) ─────────

/** `:744` — §C1. A lateral de 320px: a coluna 2 do grid da `RaizDoLightbox`. */
export const CLASSES_LATERAL = 'flex flex-col gap-[14px] overflow-y-auto border-l border-pub-line-subtle '
    + 'bg-pub-sunken p-[18px]';

/** `:745#1` — §C2. */
export const CLASSES_WRAPPER_DO_FECHAR = 'flex justify-end';

/**
 * `:745#2` — §C3.
 *
 * ⚠️ `whitespace-nowrap` é a **PRIMEIRA propriedade** da linha no protótipo, e é
 * a classe que a Fase 187 omitiu SETE vezes. Sem ela "Fechar · Esc" quebra em
 * duas linhas dentro de um botão de 30px.
 */
export const CLASSES_FECHAR = 'h-[30px] cursor-pointer whitespace-nowrap rounded-pub-control-sm border '
    + 'border-pub-line-strong bg-transparent px-[10px] text-[12px] text-pub-fase1';

/** `:746` — §C4. ⚠️ `110px_minmax(0,1fr)`: o `_` é o espaço do valor arbitrário. */
export const CLASSES_GRADE_DE_METADADOS = 'grid grid-cols-[110px_minmax(0,1fr)] gap-y-[9px] text-[12.5px]';

/** `:747`/`:748`/`:750#1` — a coluna dos RÓTULOS. */
export const CLASSES_ROTULO_DE_METADADO = 'text-pub-txt-4';

/** `:747#2`/`:750#2` — o valor de Origem e o de Estado levam peso. */
export const CLASSES_VALOR_FORTE = 'font-semibold';

/** `:753` — §C9. */
export const CLASSES_BLOCO_DA_ORIGINAL = 'flex flex-col gap-[8px]';

/** `:754` — §C10. */
export const CLASSES_TITULO_DA_ORIGINAL = 'text-[12px] text-pub-txt-4';

/** `:755` — §C11. A MESMA caixa 1:1 nos três casos: a lateral não "pula". */
export const CLASSES_CAIXA_DA_ORIGINAL = 'relative aspect-square overflow-hidden rounded-[8px] '
    + 'bg-pub-hover-2 text-pub-txt-off';

/**
 * §H4 — o estado declarado de IMG-13, **DENTRO** da caixa da §C11.
 *
 * ⚠️ **SEM MEDIDA NO PROTÓTIPO** (ele esconde o bloco, `:752`): valores
 * escolhidos e declarados. O `absolute inset-0` é o que o faz ocupar a caixa 1:1
 * inteira, como o `CLASSES_PLACEHOLDER` do painel faz na caixa dele.
 */
export const CLASSES_SEM_ORIGINAL = 'absolute inset-0 flex flex-col items-center justify-center '
    + 'gap-[6px] p-[12px] text-center';

/** §H4 — o ícone. ⚠️ VOC-05 pede ícone **E** texto; o texto carrega o sentido. */
export const CLASSES_ICONE_SEM_ORIGINAL = 'h-[16px] w-[16px] text-pub-txt-4';

/** §H4 — o rótulo do estado, LIDO da prop. */
export const CLASSES_ROTULO_SEM_ORIGINAL = 'text-[12px] font-semibold text-pub-txt-2';

/** §H4 — o apoio do estado, LIDO da prop. */
export const CLASSES_APOIO_SEM_ORIGINAL = 'text-[11.5px] text-pub-txt-4';

// ─── Os literais da §C ────────────────────────────────────────────────────

/** `:745` — ⚠️ o `·` é **U+00B7**, não um ponto, e o `Esc` é maiúsculo só no E. */
export const ROTULO_FECHAR = 'Fechar · Esc';

/** `:747` — §C5. */
export const ROTULO_ORIGEM = 'Origem';

/** `:748` — §C6. */
export const ROTULO_POSICAO = 'Posição';

/** `:750` — §C8. */
export const ROTULO_ESTADO = 'Estado';

/** `:748` — ⚠️ MINÚSCULO, e com ` · ` (U+00B7) na frente. É o que o protótipo diz. */
export const SUFIXO_PRINCIPAL = ' · principal';

/** `:754` — §C10. */
export const TITULO_DA_ORIGINAL = 'Original de referência';

/**
 * §H4 — o mapa de ícones do estado declarado, por chave SEMÂNTICA do servidor.
 *
 * ⚠️ `image-off` é `PubImagemReferencia::SEM_ORIGINAL_ICONE`. O mapa existe para
 * que a chave do servidor **nunca** indexe nada diretamente.
 */
export const ICONES_DA_ORIGINAL = {
    'image-off': ImageOff,
};

/**
 * O componente de ícone da chave, ou `null`.
 *
 * ⚠️⚠️ **A GUARDA DE TIPO VEM ANTES DO ACESSO.** `mapa[chave]` cru **LANÇA** com
 * `{toString: null}`, e `hasOwnProperty` sobre chave não-string também — por isso
 * o `typeof === 'string'` é o PRIMEIRO teste, e o `hasOwnProperty.call` vem
 * depois (ele também barra `constructor` e `__proto__`).
 * ⚠️ Chave desconhecida → **sem ícone, COM o texto**: o texto é o que carrega o
 * sentido, e o render adverso prova que ele nunca some.
 *
 * @param {unknown} icone a chave vinda do servidor
 * @returns {Function|null} o componente de ícone, ou `null`
 */
export function iconeDaOriginal(icone) {
    if (typeof icone !== 'string') return null;
    if (!Object.prototype.hasOwnProperty.call(ICONES_DA_ORIGINAL, icone)) return null;

    return ICONES_DA_ORIGINAL[icone];
}

// ─── Os handlers PUROS (o render não dispara evento) ──────────────────────

/**
 * O clique em "Fechar · Esc".
 *
 * ⚠️ `{a = null} = {}` só cobre `undefined`, **NÃO `null`** — foi assim que uma
 * função passou a LANÇAR no clique na 187-06. Aqui a guarda é `typeof`.
 *
 * @param {unknown} onFechar o handler do painel
 * @returns {boolean} `true` se chamou
 */
export function aoClicarEmFechar(onFechar) {
    if (typeof onFechar !== 'function') return false;

    onFechar();
    return true;
}

// ─── As peças, todas NAMED EXPORTS ───────────────────────────────────────

/**
 * `:746`-`:750` — §C4 a §C8. Os metadados, em TRÊS pares NESTA ordem:
 * **Origem · Posição · Estado**.
 *
 * @param {object} props
 * @param {unknown} [props.imagem] a entrada de `painel.fotos.imagens`
 *
 * ⚠️ A linha **Variação** (`:749`) não entra — ver a razão MEDIDA no cabeçalho.
 * ⚠️ Par cujo VALOR não existe **não nasce**: rótulo sozinho é casca vazia (D4), e
 * um "Estado" sem estado não informa nada. O servidor garante `estado_rotulo`
 * NUNCA vazio (`SELO_NEUTRO`), então aqui o vazio é defesa, não caminho normal.
 */
export function MetadadosDaImagem({ imagem = null }) {
    const dados = objetoDoPainel(imagem);
    if (dados === null) return null;

    const origem = textoDoPainel(dados.tipo_rotulo);
    const posicao = textoDoPainel(dados.posicao_rotulo);
    const estado = textoDoPainel(dados.estado_rotulo);

    // ⚠️⚠️ `principal` por comparação ESTRITA: `'1'`, `1`, `{}` e `[]` são truthy
    // e escreveriam " · principal" numa foto que **não é** a principal.
    // ⚠️ E o texto é CONCATENADO em JS, não dois filhos de JSX: dois filhos
    // produziriam dois nós de texto, e o gate que lê o `<span>` folha perderia o
    // sufixo sem que nada na tela mudasse.
    const comPosicao = posicao === null
        ? null
        : (dados.principal === true ? `${posicao}${SUFIXO_PRINCIPAL}` : posicao);

    const pares = [
        [ROTULO_ORIGEM, origem, CLASSES_VALOR_FORTE],
        [ROTULO_POSICAO, comPosicao, null],
        [ROTULO_ESTADO, estado, cn(CLASSES_VALOR_FORTE, classeDaCor(TEXTO_DA_COR, dados.estado_cor))],
    ].filter((par) => par[1] !== null);

    if (pares.length === 0) return null;

    return (
        <div className={CLASSES_GRADE_DE_METADADOS}>
            {pares.map(([rotulo, valor, classeDoValor]) => (
                // ⚠️⚠️ **`Fragment` com `key`, nunca um `div` no meio.** A grade é
                // `grid-cols-[110px_minmax(0,1fr)]`: os dois `<span>` têm de ser
                // filhos DIRETOS dela. Um wrapper (mesmo `display:contents`) muda a
                // PROFUNDIDADE da árvore — e profundidade é o que prova estrutura.
                <Fragment key={`metadado-${rotulo}`}>
                    <span className={CLASSES_ROTULO_DE_METADADO}>{rotulo}</span>
                    {/* ⚠️ §H2 — o valor de Posição é SEM estilo próprio: ele herda
                        o `text-[12.5px]` da grade. `className` nulo vira ausente. */}
                    <span className={classeDoValor === null ? undefined : classeDoValor}>{valor}</span>
                </Fragment>
            ))}
        </div>
    );
}

/**
 * `:752`-`:755` — §C9 a §C11 e §H1/§H4. A ORIGINAL DE REFERÊNCIA.
 *
 * @param {object} props
 * @param {unknown} [props.imagem] a entrada de `painel.fotos.imagens`
 * @param {unknown} [props.original] `lightbox.originais[<id da revisão>]` — as
 *   TRÊS formas do 189-02: `null` · `{disponivel: true, url_arquivo, quantas}` ·
 *   `{disponivel: false, estado, rotulo, apoio, icone, url_arquivo: null}`
 *
 * ⚠️ `de_ia === true` ESTRITO: `'1'` é truthy, e uma foto de upload com o bloco
 * seria MENTIRA (ver o cabeçalho).
 * ⚠️ O `quantas` do servidor **não vira texto de tela**: ele existe para a Fase
 * 190 (a comparação), e um "2" solto aqui é jargão.
 */
export function OriginalDeReferencia({ imagem = null, original = null }) {
    const dados = objetoDoPainel(imagem);
    if (dados === null || dados.de_ia !== true) return null;

    const bloco = objetoDoPainel(original);
    if (bloco === null) return null;

    const url = bloco.disponivel === true ? textoDoPainel(bloco.url_arquivo) : null;

    return (
        <div className={CLASSES_BLOCO_DA_ORIGINAL}>
            <span className={CLASSES_TITULO_DA_ORIGINAL}>{TITULO_DA_ORIGINAL}</span>
            <div className={CLASSES_CAIXA_DA_ORIGINAL}>
                {url === null
                    ? <EstadoDaOriginal bloco={bloco} />
                    : <img className={CLASSES_IMAGEM} src={url} alt={TITULO_DA_ORIGINAL} />}
            </div>
        </div>
    );
}

/**
 * O que a caixa da §C11 mostra quando **não há imagem para mostrar**.
 *
 * Dois caminhos, e os dois são casos REAIS do servidor:
 *   · `disponivel: true` com `url_arquivo: null` — há vínculo, falta o arquivo.
 *     Resposta: o MESMO estado declarado do painel (`CLASSES_PLACEHOLDER`,
 *     INV-11, IMPORTADO). **Nunca** `<img src="">`.
 *   · `disponivel: false` — não há vínculo (IMG-13). Resposta: o ícone + o
 *     `rotulo` + o `apoio`, **LIDOS da prop** (§H4).
 *
 * ⚠️ **Nunca caixa vazia**: sem `rotulo` nem `apoio` utilizáveis (payload
 * adverso), cai no placeholder com o título do bloco — texto sempre há.
 */
function EstadoDaOriginal({ bloco = null }) {
    const dados = objetoDoPainel(bloco);
    const rotulo = dados === null ? null : textoDoPainel(dados.rotulo);
    const apoio = dados === null ? null : textoDoPainel(dados.apoio);
    const semVinculo = dados !== null && dados.disponivel !== true;

    if (!semVinculo || (rotulo === null && apoio === null)) {
        return <span className={CLASSES_PLACEHOLDER}>{TITULO_DA_ORIGINAL}</span>;
    }

    const Icone = iconeDaOriginal(dados.icone);

    return (
        <span className={CLASSES_SEM_ORIGINAL}>
            {/* ⚠️ Chave de ícone desconhecida → sem ícone, COM o texto. */}
            {Icone === null ? null : <Icone className={CLASSES_ICONE_SEM_ORIGINAL} aria-hidden="true" />}
            {rotulo === null ? null : <span className={CLASSES_ROTULO_SEM_ORIGINAL}>{rotulo}</span>}
            {apoio === null ? null : <span className={CLASSES_APOIO_SEM_ORIGINAL}>{apoio}</span>}
        </span>
    );
}

/**
 * A LATERAL de 320px do lightbox.
 *
 * @param {object} props
 * @param {unknown} [props.imagem] a imagem ABERTA no lightbox
 * @param {unknown} [props.original] `lightbox.originais[<id da revisão>]`
 * @param {unknown} [props.aoVivo] `ao_vivo` do CONTEXTO DO LIGHTBOX
 *   (PERGUNTA-189-B) — a lateral só recebe o booleano; ela **não** sabe de onde
 *   ele vem
 * @param {unknown} [props.zoom] `1` ou `2.5`
 * @param {unknown} [props.onFechar] `() => void`, ou `null`
 * @param {unknown} [props.onAlternarZoom] `(zoomNovo) => void`, ou `null`
 * @param {unknown} [props.onAprovarFoto] `(imagem) => void`, ou `null`
 * @param {unknown} [props.onReprovar] `(imagem) => void`, ou `null`
 *
 * ⚠️ `imagem` ausente → **nada**. Uma lateral de 320px com rótulos e nenhum valor
 * é casca vazia (D4), e quem diz o vazio é o painel.
 */
export default function LateralDoLightbox({
    imagem = null,
    original = null,
    onFechar = null,
}) {
    const dados = objetoDoPainel(imagem);
    if (dados === null) return null;

    const fechar = typeof onFechar !== 'function' ? null : () => aoClicarEmFechar(onFechar);

    return (
        <aside className={CLASSES_LATERAL}>
            {/* ⚠️ D4 — BOTÃO SEM DESTINO NÃO NASCE. `onClick` vazio e `disabled`
                decorativo são a mesma dívida. */}
            {fechar === null ? null : (
                <div className={CLASSES_WRAPPER_DO_FECHAR}>
                    <button type="button" className={CLASSES_FECHAR} onClick={fechar}>
                        {ROTULO_FECHAR}
                    </button>
                </div>
            )}

            <MetadadosDaImagem imagem={dados} />
            <OriginalDeReferencia imagem={dados} original={original} />
        </aside>
    );
}
