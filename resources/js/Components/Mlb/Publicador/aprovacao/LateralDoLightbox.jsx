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
import {
    APROVAR_FOTO,
    CABECALHO_DAS_VERIFICACOES,
    CLASSES_CABECALHO_VERIFICACOES,
    CLASSES_GLIFO,
    CLASSES_ORDINAL,
    CLASSES_VERIFICACOES,
    ESTADO_PENDENTE,
    REPROVAR,
    fundoDoGlifo,
    verificacoesDesenhaveis,
} from './BlocoDaImagemSelecionada';
import { alternarZoom, rotuloDoZoom } from './LightboxDaImagem';

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

/**
 * `:760#1` — §C12. O item de verificação.
 *
 * ⚠️⚠️ **SEM `items-start`, e isso foi MEDIDO nos dois lados.** O item do PAINEL
 * (`:633`) **tem** `align-items:flex-start`; o do LIGHTBOX (`:760#1`) **não**.
 * ➡️ Reusar `CLASSES_ITEM` do 188-08 cru traria o `items-start` de volta — é por
 * isso que esta é a ÚNICA classe da lista que não vem importada, e há gate
 * provando que as duas continuam DIFERENTES.
 */
export const CLASSES_ITEM_DA_VERIFICACAO = 'flex gap-[8px] text-[12px] leading-[1.4] text-pub-fase1';

/**
 * `:763` — §C14. O liga/desliga do zoom (D-189-3a).
 *
 * ⚠️ `whitespace-nowrap` é a **PRIMEIRA propriedade** da linha no protótipo.
 */
export const CLASSES_BOTAO_DE_ZOOM = 'h-[32px] cursor-pointer whitespace-nowrap rounded-[8px] border '
    + 'border-pub-line-strong bg-pub-hover-2 text-[12.5px] text-pub-txt';

/** `:765` — §C15. ⚠️ `mt-auto` empurra as ações para o PÉ da lateral rolável. */
export const CLASSES_LINHA_DE_ACOES = 'mt-auto flex gap-[6px]';

/** `:766` — §C16. ⚠️ `border-pub-danger/[0.45]`: token com opacidade, nunca hex. */
export const CLASSES_REPROVAR = 'h-[36px] flex-1 cursor-pointer whitespace-nowrap rounded-[8px] border '
    + 'border-pub-danger/[0.45] bg-transparent text-[13px] font-medium text-pub-danger-txt';

/** `:767` — §C17. */
export const CLASSES_APROVAR = 'h-[36px] flex-1 cursor-pointer whitespace-nowrap rounded-[8px] border-none '
    + 'bg-pub-accent text-[13px] font-semibold text-pub-page';

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

/**
 * `:1328` — o clique no botão de zoom.
 *
 * ⚠️⚠️ Ele entrega o **ZOOM NOVO**, calculado por `alternarZoom` **IMPORTADO do
 * 189-03**: a lateral NÃO reimplementa o liga/desliga. Duas regras de alternância
 * divergiriam, e a divergente deixaria o rótulo dizendo "Reduzir" com a imagem em
 * 1×. É a mesma forma dos handlers do palco (189-03), que devolvem um PEDIDO.
 * ⚠️ Isto é ACRÉSCIMO declarado sobre o `onAlternarZoom()` sem argumento do
 * `<interfaces>` do plano: quem liga (189-06) recebe o valor pronto e não precisa
 * de uma segunda cópia da regra.
 *
 * @param {unknown} zoom a escala atual
 * @param {unknown} onAlternarZoom `(zoomNovo) => void`
 * @returns {boolean} `true` se chamou
 */
export function aoClicarNoZoom(zoom, onAlternarZoom) {
    if (typeof onAlternarZoom !== 'function') return false;

    onAlternarZoom(alternarZoom(zoom));
    return true;
}

/**
 * `:766` — o clique em "Reprovar".
 *
 * ⚠️⚠️ O handler recebe a imagem **ATUAL DO LIGHTBOX** — não a selecionada no
 * painel por baixo (PERGUNTA-189-B (b)). Imagem adversa **não chama** o handler:
 * uma escrita com a imagem errada reprovaria a foto errada.
 *
 * @param {unknown} imagem a imagem aberta no lightbox
 * @param {unknown} onReprovar `(imagem) => void`
 * @returns {boolean} `true` se chamou
 */
export function aoClicarEmReprovar(imagem, onReprovar) {
    const dados = objetoDoPainel(imagem);
    if (dados === null || typeof onReprovar !== 'function') return false;

    onReprovar(dados);
    return true;
}

/**
 * `:767` — o clique em "Aprovar foto".
 *
 * ⚠️ **APROVAR ENFILEIRA, NÃO PUBLICA** (a escrita no ML é da Fase 193) — e **não
 * há desfazer**. É por isso que a trava é estrita e que a imagem vai pelo
 * parâmetro, nunca lida de um estado por fora.
 *
 * @param {unknown} imagem a imagem aberta no lightbox
 * @param {unknown} onAprovarFoto `(imagem) => void`
 * @returns {boolean} `true` se chamou
 */
export function aoClicarEmAprovar(imagem, onAprovarFoto) {
    const dados = objetoDoPainel(imagem);
    if (dados === null || typeof onAprovarFoto !== 'function') return false;

    onAprovarFoto(dados);
    return true;
}

/**
 * `:764`/`:1330` + IMG-09 — **A TRAVA DAS DUAS AÇÕES**, função pura.
 *
 * O `canAct` do protótipo é `!!liveO && im.isPending`. Aqui ele ganha **duas
 * condições a mais**, e as duas são prescritas:
 *   · `aceita_acao === true` — **IMG-09**: o servidor já diz que `regenerating`
 *     não aceita (`PubAprovacaoImagem::aceitaAcao()`, Fase 186), e o 188-05
 *     devolve **422**. Esta é a tela **não oferecendo** o que o servidor
 *     recusaria — a trava REAL é do servidor.
 *   · os dois handlers serem **função** — botão sem destino é D4 violado, e
 *     `onClick` vazio e `disabled` decorativo são a mesma dívida.
 *
 * ⚠️⚠️ **COMPARAÇÃO ESTRITA nos três campos.** `'1'`, `1`, `{}` e `[]` são
 * truthy e liberariam a ação numa foto que o servidor recusa — e **aprovar JÁ
 * ENFILEIRA, sem desfazer**.
 * ⚠️ A função recebe **UM** parâmetro e o saneia, em vez de desestruturar:
 * `{a = null} = {}` só cobre `undefined`, **NÃO `null`** — e foi assim que uma
 * função passou a LANÇAR no clique na 187-06.
 *
 * @param {unknown} contexto `{aoVivo, imagem, onAprovarFoto, onReprovar}`
 * @returns {boolean}
 */
export function podeAgirNaFoto(contexto) {
    const campos = objetoDoPainel(contexto);
    if (campos === null) return false;

    const dados = objetoDoPainel(campos.imagem);
    if (dados === null) return false;

    return campos.aoVivo === true
        && dados.estado === ESTADO_PENDENTE
        && dados.aceita_acao === true
        && typeof campos.onAprovarFoto === 'function'
        && typeof campos.onReprovar === 'function';
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
 * `:759`-`:761` + `:369` — §C12/§C13/§H3/§H5. AS VERIFICAÇÕES AUTOMÁTICAS.
 *
 * @param {object} props
 * @param {unknown} [props.verificacoes] `imagem.verificacoes` do servidor
 *
 * ⚠️⚠️ **O CABEÇALHO `VERIFICAÇÃO AUTOMÁTICA · APOIO` ENTRA UMA VEZ**, acima dos
 * itens — **PERGUNTA-189-A RESPONDIDA (a) SIM pelo usuário em 19/10/2026**, com
 * as MESMAS classes do cabeçalho do 188-08 (`CLASSES_CABECALHO_VERIFICACOES`,
 * INV-07, **IMPORTADO**; medida de origem `:369`).
 * **Razão do usuário:** *"sem o cabeçalho a verificação pode ser lida como
 * VEREDICTO"*.
 * ⚠️ **É A QUARTA VEZ QUE O PROTÓTIPO PERDE NA MILESTONE, por decisão do
 * usuário:** `:759`-`:761` **não tem** cabeçalho × `:369` **tem** o literal (que
 * é o E4, a revisão detalhada). O literal em maiúsculas só existe em `:369`; o
 * painel escreve minúsculo em `:633`.
 *
 * ⚠️⚠️ **O ORDINAL É O NÚMERO DO MARCADOR, LIDO DO SERVIDOR, NUNCA RECONTADO** —
 * e o protótipo do lightbox **não o tem** (`:760` só desenha `a.glyph`). É
 * **DIVERGÊNCIA PRESCRITA PELO REQUISITO** (AMPL-06 e o critério 2, §H3):
 * requisito explícito ganha do protótipo. Se a lista contasse 1,2,3 enquanto o
 * marcador diz 4, **a pessoa reprovaria a foto errada** — e aprovar JÁ ENFILEIRA.
 * ⚠️ Ele é **ELEMENTO PRÓPRIO**, com as classes do ordinal do 188-08
 * (`CLASSES_ORDINAL`, IMPORTADO), **nunca colado no texto**: `'…automática' +
 * ordinal` é forma proibida, com gate.
 * ⚠️ `ok` (ordinal `null`) **não traz número**. E `ordinal: 0` também **não**:
 * `0` passa por `Number.isInteger` e viraria um "0" apontando para um marcador
 * que não existe — a régua é inteiro **POSITIVO**.
 *
 * ⚠️ `verificacoes` vazia → **nem itens nem cabeçalho**. Cabeçalho sozinho é
 * casca vazia (D4), e um "APOIO" sem nada embaixo não apoia nada.
 * ⚠️ Toda flag computada **DENTRO do callback** do `.map()`: o Rollup elimina
 * variável de escopo do componente usada dentro do mapa.
 */
export function VerificacoesDoLightbox({ verificacoes = null }) {
    const itens = verificacoesDesenhaveis(verificacoes);

    if (itens.length === 0) return null;

    return (
        <div className={CLASSES_VERIFICACOES}>
            <span className={CLASSES_CABECALHO_VERIFICACOES}>{CABECALHO_DAS_VERIFICACOES}</span>
            {itens.map((item, indice) => {
                // ⚠️⚠️ TODAS as flags DENTRO do callback.
                const corDoTexto = classeDaCor(TEXTO_DA_COR, item.cor);
                const temOrdinal = typeof item.ordinal === 'number' && item.ordinal > 0;

                return (
                    <span key={`verificacao-do-lightbox-${indice}`} className={CLASSES_ITEM_DA_VERIFICACAO}>
                        {item.glifo === null ? null : (
                            <span className={cn(CLASSES_GLIFO, fundoDoGlifo(item.cor), corDoTexto)}>
                                {item.glifo}
                            </span>
                        )}
                        {temOrdinal ? <span className={cn(CLASSES_ORDINAL, corDoTexto)}>{item.ordinal}</span> : null}
                        <span>{item.texto}</span>
                    </span>
                );
            })}
        </div>
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
    aoVivo = null,
    zoom = null,
    onFechar = null,
    onAlternarZoom = null,
    onAprovarFoto = null,
    onReprovar = null,
}) {
    const dados = objetoDoPainel(imagem);
    if (dados === null) return null;

    const fechar = typeof onFechar !== 'function' ? null : () => aoClicarEmFechar(onFechar);
    const alternar = typeof onAlternarZoom !== 'function' ? null : () => aoClicarNoZoom(zoom, onAlternarZoom);

    // ⚠️⚠️ A TRAVA, pela função pura — e ela exige os DOIS handlers função, então
    // a linha de ações nasce inteira ou não nasce.
    const podeAgir = podeAgirNaFoto({ aoVivo, imagem: dados, onAprovarFoto, onReprovar });

    return (
        <aside className={CLASSES_LATERAL}>
            {/* ⚠️ D4 — BOTÃO SEM DESTINO NÃO NASCE. `onClick` vazio e `disabled`
                decorativo são a mesma dívida, e *"desabilitado com explicação"*
                foi OFERECIDO ao usuário e RECUSADO. */}
            {fechar === null ? null : (
                <div className={CLASSES_WRAPPER_DO_FECHAR}>
                    <button type="button" className={CLASSES_FECHAR} onClick={fechar}>
                        {ROTULO_FECHAR}
                    </button>
                </div>
            )}

            <MetadadosDaImagem imagem={dados} />
            <OriginalDeReferencia imagem={dados} original={original} />
            <VerificacoesDoLightbox verificacoes={dados.verificacoes} />

            {/* ⚠️ `:763` — o liga/desliga do zoom (D-189-3a). Os dois rótulos saem
                de `rotuloDoZoom`, IMPORTADO do 189-03: uma fonte só. */}
            {alternar === null ? null : (
                <button type="button" className={CLASSES_BOTAO_DE_ZOOM} onClick={alternar}>
                    {rotuloDoZoom(zoom)}
                </button>
            )}

            {/* ⚠️ `:764`-`:769` — as duas ações, só com a trava liberada (IMG-09).
                Nada de "confirmar publicação", "desfazer" nem "segundo passo": o
                vocabulário está vetado das Fases 187 a 194. */}
            {podeAgir ? (
                <div className={CLASSES_LINHA_DE_ACOES}>
                    <button
                        type="button"
                        className={CLASSES_REPROVAR}
                        onClick={() => aoClicarEmReprovar(dados, onReprovar)}
                    >
                        {REPROVAR}
                    </button>
                    <button
                        type="button"
                        className={CLASSES_APROVAR}
                        onClick={() => aoClicarEmAprovar(dados, onAprovarFoto)}
                    >
                        {APROVAR_FOTO}
                    </button>
                </div>
            ) : null}
        </aside>
    );
}
