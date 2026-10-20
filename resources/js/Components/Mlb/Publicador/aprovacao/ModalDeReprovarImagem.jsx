import { cn } from '@/lib/utils';
import {
    CLASSES_BACKDROP,
    CLASSES_BOTAO,
    CLASSES_CAIXA,
    CLASSES_CANCELAR,
    CLASSES_CORPO,
    CLASSES_RODAPE,
    CLASSES_SUBMETER,
    ROTULO_CANCELAR,
    TopoDoModal,
} from './CascaDeModal';
import {
    listaDoPainel,
    objetoDoPainel,
    textoDoPainel,
} from './ImagemGrandeDoPainel';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 188, plano 12 — MOD-04: "Reprovar imagem" (`:815`-`:821`).
//
// ═══ ⚠️⚠️ CRITÉRIO 13 — OS CINCO EFEITOS SÃO DO SERVIDOR, NÃO DAQUI ══════
//
// Reprovar imagem **exige motivo**, e então:
//   1. tira a foto do conjunto final **NA HORA**;
//   2. manda para **"Fora da publicação"**;
//   3. registra como **reprovada**, com o motivo;
//   4. **PROMOVE A PRÓXIMA A PRINCIPAL** se era a principal;
//   5. **REABRE a aprovação de TODAS as ofertas do conjunto**.
//
// ⚠️⚠️ **Os CINCO são de `RevisaoDeImagemService::reprovar()` (Fase 186), numa
// TRANSAÇÃO ÚNICA, e a rota é do plano 188-05.** ➡️ **A tela só colhe o motivo e
// CHAMA.** Reimplementar qualquer um deles criaria a segunda verdade, e a
// divergente só apareceria **quando alguém reprovasse a PRINCIPAL** — o caso mais
// raro e o mais caro.
// ⚠️ **Mas a tela DIZ o que vai acontecer:** é a nota literal de `:821`, e é ela
// que faz a pessoa entender que **não há desfazer**. **Não omitir.**
//
// ═══ ⚠️⚠️ A CASCA É IMPORTADA — E AQUI O BOTÃO É VERMELHO ════════════════
//
// `CascaDeModal.jsx` é da **Fase 187, plano 09** e está **EM PRODUÇÃO**: este
// arquivo a IMPORTA e sai dela com `numstat` ZERO.
// ⚠️⚠️ **`:1403`: `btnBg: ok ? '#EF4444' : '#262626'` — o ativo é VERMELHO, NÃO o
// `#FACC15` dos outros três.** É o **único** dos quatro modais com cor de
// submissão diferente, e é **fácil de omitir** porque a casca traz
// `CLASSES_SUBMETER_ATIVO = 'bg-pub-accent …'`.
// ⚠️ **MEDIDO: o ponteiro de `:1404` que a `188-FID01.md` §F.4 e o `<interfaces>`
// do `188-12-PLAN.md` citam está DESLOCADO — `:1404` é `if (k === 'regen')`, que é
// assunto da Fase 192.** O real é **`:1403`**, e é a OITAVA vez nesta fase que a
// prosa divergiu do protótipo medido. Ver a §Q do FID01.
// ➡️ ⚠️⚠️ **A CASCA PERMITE SOBREPOR SEM EDITÁ-LA, E ISSO FOI MEDIDO antes de
// decidir — por isso NÃO foi preciso PARAR e devolver a pergunta.**
//
// O que foi medido, e o que foi DESCARTADO:
//   · **(a) passar `rotuloDoBotao` ao default dela** — **não serve**: a cor ativa
//     do rodapé dela é a CONSTANTE `CLASSES_SUBMETER_ATIVO = 'bg-pub-accent …'`, e
//     o `:1403` quer `#EF4444`. Mudá-la seria **editar arquivo em produção**;
//   · **(b) pôr o botão vermelho no `children` do default dela** — **não serve**:
//     `children` vai para dentro do `CLASSES_CORPO` (`padding:16px 20px`), e o
//     botão perderia o `border-top`, o fundo `pub-sunken` e a sangria do rodapé de
//     `:888`. Geometria errada com render verde;
//   · **(c) COMPOR a partir dos NAMED EXPORTS dela** — **é a saída**, e é
//     exatamente para isso que eles existem (o docblock dela diz *"E AS PEÇAS
//     INTERNAS SAEM COMO NAMED EXPORTS (REND-05)"*).
// ➡️ **Este arquivo importa `CLASSES_BACKDROP`, `CLASSES_CAIXA`, `TopoDoModal`,
// `CLASSES_CORPO`, `CLASSES_RODAPE`, `CLASSES_BOTAO`, `CLASSES_CANCELAR`,
// `CLASSES_SUBMETER` e `ROTULO_CANCELAR` — TODA a geometria continua numa casa só,
// em `CascaDeModal.jsx`, e NENHUMA medida é redeclarada aqui.** O único acréscimo
// é o **par de cores** do submeter. ⚠️ `CascaDeModal.jsx` sai com `numstat` ZERO, e
// o gate de "ausência de segunda casca" do plano 188-11 continua VERDE — ele
// procura as MEDIDAS (`pt-[96px]`, `max-h-[calc(100vh-140px)]`, `shadow-pub-modal`,
// `w-[560px]` ancorado), e aqui não existe nenhuma delas: só identificadores
// IMPORTADOS. ⚠️ **Isto NÃO é uma segunda casca: é a MESMA casca, composta.**
//
// ═══ ⚠️⚠️ A TRAVA É `> 2`, E É DIFERENTE DO `>= 10` DA EXCEÇÃO ═══════════
//
// `:1402`: `ok = (F.reason || '').trim().length > 2` — **`>`, e 2**.
// `:1398` (exceção): `>= 10`. ⚠️ **Os dois são DE PROPÓSITO**, e *"deixei igual ao
// outro modal"* é **FID-02 com outra roupa**. ⚠️ **Não harmonizar.**
// ⚠️ A trava real é `REGRA_SEM_MOTIVO` no servidor (plano 188-05), cujo piso é
// **NÃO-VAZIO** — a 188-05 declarou que `min:3` criaria **duas regras sobre o
// mesmo campo**, mais estrita que a do serviço que está em produção. ➡️ O `> 2` é
// o **`disabled=` DESTE modal**, não a regra do servidor.
// ⚠️ E `TrimStrings` apara **ANTES** da validação: motivo só-de-espaços **não
// chega** ao serviço como vazio — ele chega como `''` e é recusado por `required`.
//
// ═══ ⚠️⚠️ OS CHIPS CONCATENAM COM `'; '` — TRÊS MODAIS, TRÊS FORMAS ══════
//
// | modal | o que o chip faz | medido em |
// |---|---|---|
// | MOD-01 ajuste | `(texto ? texto + ' ' : '') + rotulo + '.'` — espaço **e PONTO** | `:1397` |
// | MOD-04 reprovar | `(motivo ? motivo + '; ' : '') + rotulo` — **ponto-e-vírgula**, sem ponto | `:1403` |
// | MOD-02 exceção | **não tem chip** | — |
//
// ⚠️ **Reproduzidas cada uma, e declaradas**: "deixei igual ao outro" seria a
// quarta forma, e a pessoa que clica é quem descobre.
// ⚠️ **Os chips vêm do SERVIDOR** (`modais.reprovar.defeitos`, lido de
// `PubAprovacaoDefeito::DEFEITOS`, a lista canônica da Fase 186). **Não cravar a
// lista aqui.** ⚠️ **Lista vazia → NENHUM chip, e o campo de motivo continua
// funcionando** (D4: não inventar chip).
//
// ═══ ⚠️ A MINIATURA: a MESMA fonte de URL da coluna de fotos ═════════════
//
// `url_arquivo` vem de `FotosDoPainelService::imagem()` (`:585`), que monta
// `route('mlb.anuncios.publicador.fotos.arquivo', …)` — **a rota serve BYTES**, e é
// isso que o `<img src>` precisa. ⚠️ **O acervo é território PROIBIDO**
// (`Acervo*`, `ImagemAssetService`): a v28 virou o acervo para o **R2 privado** em
// produção em 16/10, e **o bucket não serve URL pública**. **Nenhuma coluna nova,
// nenhum caminho de arquivo montado à mão, nenhum toque no acervo.**
// ⚠️ **`url_arquivo === null` é caso REAL** (`PubImagem.caminho` nulo = veio do
// Anunciar; `arquivoFoto` faz `abort(404)`): a tela mostra o **RÓTULO** como
// placeholder — **nunca `<img src="">`** (string vazia num `src` faz o navegador
// recarregar a página), nunca ícone quebrado, nunca quadrado vazio (IMG-13).
//
// ═══ ⚠️ O QUE ESTE ARQUIVO NÃO FAZ ══════════════════════════════════════
//
// ⚠️ **Ele não escreve.** `onSubmeter` é chamado com `{motivo}`, e a escrita vai
// pelo helper único `escreverNoPainel` (W11 / COL-04 / critério 17).
// ⚠️ **Ele não guarda estado.** `motivo` é prop: o pai (a página) é o dono.
// ⚠️ **`font-pub` é declarado no corpo** (critério 15 / REND-05): conteúdo portado
// **não herda fonte**, e o painel desta fase é PORTAL. **A casca é compartilhada e
// está em produção; a fonte é declarada AQUI para o modal continuar correto tanto
// dentro do portal do painel quanto fora dele.**
// ═══════════════════════════════════════════════════════════════════════════

/**
 * A chave deste modal no estado da página.
 *
 * ⚠️ Ela **NÃO** entra em `MODAIS` de `PendenciasDoPainel.jsx` (a lista FECHADA
 * dos destinos de pendência), e isso é de propósito: **nenhum bloqueio leva ao
 * MOD-04**. Ele é aberto pelo BLOCO DA IMAGEM SELECIONADA (plano 188-08).
 */
export const MODAL_REPROVAR = 'reprovar';

/** `:1403`/`:1395` — o título do modal e o rótulo do botão, LITERAIS. */
export const TITULO = 'Reprovar imagem';

export const ROTULO_DO_BOTAO = 'Reprovar imagem';

/** `:818` — o rótulo literal do campo. */
export const ROTULO_DO_MOTIVO = 'Motivo';

/** `:819` — o placeholder do `<textarea>`, byte a byte. */
export const PLACEHOLDER_DO_MOTIVO = 'O que está errado nesta imagem';

/** `:821` — a nota literal. ⚠️ É ela que diz que NÃO HÁ DESFAZER. */
export const NOTA = 'A imagem sai do conjunto final e fica registrada como reprovada.';

/** `:817` — o `'+ ' + t` do chip de defeito. */
export const PREFIXO_DO_CHIP = '+ ';

/** `:1403` — o que o chip acrescenta ao motivo: **ponto-e-vírgula + espaço**. */
export const SEPARADOR_DO_CHIP = '; ';

/** `:1403` — o separador entre o rótulo da imagem e o tipo (`Gerada por IA`/`Original`). */
export const SEPARADOR_DO_SUB = ' · ';

/**
 * ⚠️ `:1402` — o piso do `disabled=` DESTE modal: **`> 2`**, e **não** o `>= 10` da
 * exceção. **Os dois são de propósito** (ver o docblock).
 */
export const MINIMO_DO_MOTIVO = 2;

/** ⚠️ O corpo do modal, e é ele que DECLARA A FONTE DO MÓDULO (ver o docblock). */
export const CLASSES_CORPO_DO_MODAL = 'flex flex-col gap-[14px] font-pub text-pub-txt';

/** `:816` — a miniatura de 120px. */
export const CLASSES_MINIATURA =
    'relative aspect-square w-[120px] overflow-hidden rounded-[8px] bg-pub-hover-2 text-pub-txt-off';

/** `:816` — a imagem dentro da miniatura. */
export const CLASSES_IMAGEM = 'h-full w-full object-cover';

/** `:816` — o placeholder quando `url_arquivo` é `null` (o RÓTULO, como no protótipo). */
export const CLASSES_PLACEHOLDER = 'grid h-full w-full place-items-center px-[8px] text-center text-[11.5px]';

/** `:817` — o wrapper dos chips, com o `flex-wrap:wrap` que a tabela omitia. */
export const CLASSES_FAIXA_DE_CHIPS = 'flex flex-wrap gap-[6px]';

/** `:817` — o chip de defeito. ⚠️ `cursor-pointer` e `whitespace-nowrap` são as omissões da tabela. */
export const CLASSES_CHIP =
    'h-[26px] cursor-pointer whitespace-nowrap rounded-full border border-pub-line-strong bg-pub-sunken px-[9px] text-[11.5px] text-pub-fase1';

/** `:818` — o `<label>` em coluna, com o texto discreto. */
export const CLASSES_ROTULO = 'flex flex-col gap-[6px] text-[12.5px] text-pub-txt-2';

/** `:819` — o `<textarea>` do motivo. */
export const CLASSES_TEXTAREA =
    'resize-y rounded-[8px] border border-pub-line-strong bg-pub-sunken px-[12px] py-[10px] text-[13px] leading-[1.45] text-pub-txt outline-none';

/** `:819` — `rows="3"`, do protótipo. */
export const LINHAS_DO_TEXTAREA = 3;

/** `:821` — a nota. */
export const CLASSES_NOTA = 'text-[12px] text-pub-txt-2';

/**
 * ⚠️⚠️ `:1403` — o submeter ATIVO é **VERMELHO**, não o amarelo da casca.
 *
 * ⚠️ `#EF4444` é `pub-danger`, e o texto sobre ele é `pub-page` (`#0A0A0A`), como
 * no protótipo. A geometria vem IMPORTADA da casca (`CLASSES_BOTAO`,
 * `CLASSES_SUBMETER`): uma casa só para `height:36px`, `padding:0 16px`,
 * `border-radius:9px` e `font-weight:600`.
 */
export const CLASSES_SUBMETER_VERMELHO = 'bg-pub-danger text-pub-page';

/** `:1403` — o submeter APAGADO, igual ao da casca (`#262626` sobre `#71717A`). */
export const CLASSES_SUBMETER_APAGADO = 'bg-pub-seg text-pub-txt-4';

/**
 * ⚠️ `:1402` — o motivo vale? **`trim().length > 2`.**
 *
 * Named export porque é ela que o botão e o clique consultam — uma casa só, nunca
 * duas condições parecidas.
 */
export function motivoValido(texto) {
    const limpo = textoDoPainel(texto);

    return limpo !== null && limpo.trim().length > MINIMO_DO_MOTIVO;
}

/**
 * ⚠️ `:1403` — o motivo DEPOIS de clicar num chip. CONCATENA com `'; '`.
 *
 * ⚠️ **Diferente do MOD-01**, que concatena com espaço **e acrescenta um PONTO**.
 * Três modais, três formas — reproduzidas e declaradas.
 *
 * @returns {?string} o motivo novo, ou `null` quando o rótulo não é texto
 */
export function motivoComChip(motivo, rotulo) {
    const novo = textoDoPainel(rotulo);
    if (novo === null) return null;

    const antes = typeof motivo === 'string' && motivo !== '' ? motivo + SEPARADOR_DO_CHIP : '';

    return antes + novo;
}

/**
 * ⚠️ `:1403` — o `sub` do modal: `{rótulo da imagem} · {Gerada por IA|Original}`.
 *
 * ⚠️ O tipo vem do SERVIDOR em `tipo_rotulo` (`FotosDoPainelService:569`, que já
 * resolve `de_ia ? 'Gerada por IA' : 'Original'`) — remontá-lo aqui criaria a
 * segunda redação das duas frases.
 */
export function subDaImagem(imagem) {
    const dados = objetoDoPainel(imagem);
    if (dados === null) return null;

    const rotulo = textoDoPainel(dados.rotulo) ?? textoDoPainel(dados.titulo);
    const tipo = textoDoPainel(dados.tipo_rotulo);

    if (rotulo === null) return tipo;
    if (tipo === null) return rotulo;

    return rotulo + SEPARADOR_DO_SUB + tipo;
}

/**
 * O corpo do modal de reprovar — ⚠️ NAMED EXPORT, exercido direto pelos gates.
 *
 * @param {object} props
 * @param {unknown} [props.imagem] a entrada de `painel.fotos.imagens` que vai ser reprovada
 * @param {unknown} [props.defeitos] `modais.reprovar.defeitos` do servidor
 * @param {unknown} [props.motivo] o que foi digitado
 * @param {unknown} [props.onMotivo] `(texto) => void`
 */
export function CorpoDaReprovacao({
    imagem = null,
    defeitos = null,
    motivo = '',
    onMotivo = null,
}) {
    const escreverMotivo = typeof onMotivo === 'function' ? onMotivo : null;
    const dados = objetoDoPainel(imagem);
    const url = dados === null ? null : textoDoPainel(dados.url_arquivo);
    const rotulo = dados === null ? null : (textoDoPainel(dados.rotulo) ?? textoDoPainel(dados.titulo));

    return (
        <div className={CLASSES_CORPO_DO_MODAL}>
            {/* ⚠️ Sem imagem a miniatura NÃO é desenhada: quadrado de 120px vazio é
                casca decorativa (D4). */}
            {dados === null ? null : (
                <div className={CLASSES_MINIATURA}>
                    {/* ⚠️ `url_arquivo === null` → o RÓTULO como placeholder. NUNCA
                        `<img src="">`: string vazia num `src` faz o navegador
                        recarregar a página (IMG-13). */}
                    {url === null
                        ? <span className={CLASSES_PLACEHOLDER}>{rotulo ?? ''}</span>
                        : <img className={CLASSES_IMAGEM} src={url} alt={rotulo ?? ''} />}
                </div>
            )}

            {/* ⚠️ Lista vazia → NENHUM chip, e o campo de motivo continua
                funcionando. Chip inventado é D4 violado. */}
            {listaDoPainel(defeitos).length === 0 ? null : (
                <div className={CLASSES_FAIXA_DE_CHIPS}>
                    {listaDoPainel(defeitos).map((cru, posicao) => {
                        // ⚠️ Flag DENTRO do callback (o Rollup elide variável de
                        // escopo do componente lida de dentro do `.map()`).
                        const defeito = objetoDoPainel(cru);
                        const texto = defeito === null ? null : textoDoPainel(defeito.rotulo);
                        if (texto === null) return null;

                        return (
                            <button
                                key={`defeito-${posicao}`}
                                type="button"
                                className={CLASSES_CHIP}
                                onClick={escreverMotivo === null ? undefined : () => {
                                    const novo = motivoComChip(motivo, texto);
                                    if (novo === null) return;

                                    escreverMotivo(novo);
                                }}
                            >
                                {PREFIXO_DO_CHIP + texto}
                            </button>
                        );
                    })}
                </div>
            )}

            <label className={CLASSES_ROTULO}>
                {ROTULO_DO_MOTIVO}
                <textarea
                    className={CLASSES_TEXTAREA}
                    rows={LINHAS_DO_TEXTAREA}
                    placeholder={PLACEHOLDER_DO_MOTIVO}
                    {...(escreverMotivo === null
                        ? { defaultValue: typeof motivo === 'string' ? motivo : '' }
                        : { value: typeof motivo === 'string' ? motivo : '' })}
                    onChange={escreverMotivo === null ? undefined : (evento) => {
                        const cru = evento?.target?.value;

                        escreverMotivo(typeof cru === 'string' ? cru : '');
                    }}
                />
            </label>

            {/* ⚠️ A nota de `:821` — é ela que diz que NÃO HÁ DESFAZER. */}
            <span className={CLASSES_NOTA}>{NOTA}</span>
        </div>
    );
}

/**
 * ⚠️⚠️ O botão de submeter VERMELHO — NAMED EXPORT, e ele existe para **não
 * editar a casca**.
 *
 * `CascaDeModal` traz `CLASSES_SUBMETER_ATIVO = 'bg-pub-accent …'`, e `:1403` quer
 * `#EF4444`. ➡️ A geometria vem IMPORTADA dela; só o par de cores é daqui.
 * ⚠️ Ele sai `disabled` quando não pode: **cor não é o único sinal**.
 */
export function BotaoDeReprovar({ podeSubmeter = false, onSubmeter = null }) {
    const liberado = podeSubmeter === true && typeof onSubmeter === 'function';

    return (
        <button
            type="button"
            disabled={!liberado}
            onClick={liberado ? () => onSubmeter() : undefined}
            className={cn(
                CLASSES_BOTAO,
                CLASSES_SUBMETER,
                liberado ? CLASSES_SUBMETER_VERMELHO : CLASSES_SUBMETER_APAGADO,
            )}
        >
            {ROTULO_DO_BOTAO}
        </button>
    );
}

/**
 * O modal "Reprovar imagem" (MOD-04), **dentro da casca da Fase 187**.
 *
 * @param {object} props
 * @param {unknown} [props.aberto] só `true` desenha
 * @param {unknown} [props.imagem] a entrada de `painel.fotos.imagens`
 * @param {unknown} [props.defeitos] `modais.reprovar.defeitos`
 * @param {unknown} [props.motivo] o que foi digitado
 * @param {unknown} [props.onMotivo] `(texto) => void`
 * @param {unknown} [props.onFechar] `() => void`
 * @param {unknown} [props.onSubmeter] `({motivo}) => void` — ⚠️ a escrita é do
 *   CHAMADOR, pelo helper único `escreverNoPainel`
 */
export default function ModalDeReprovarImagem({
    aberto = false,
    imagem = null,
    defeitos = null,
    motivo = '',
    onMotivo = null,
    onFechar = null,
    onSubmeter = null,
}) {
    // ⚠️ Sem imagem não há o que reprovar: o modal **não abre**, nem com casca
    // vazia. Reprovar "a imagem nenhuma" é um clique morto.
    if (aberto !== true || objetoDoPainel(imagem) === null) return null;

    const pode = motivoValido(motivo);
    const enviar = typeof onSubmeter === 'function' ? onSubmeter : null;
    const fechar = typeof onFechar === 'function' ? onFechar : null;

    return (
        // ⚠️⚠️ A casca é COMPOSTA a partir dos named exports dela (ver o docblock):
        // toda a geometria vem IMPORTADA, e só o par de cores do submeter é daqui.
        // ⚠️ O clique no BACKDROP fecha (`:775`); o clique no CORPO **não**
        // (`:776`, `M.stop`) — sem o `stopPropagation`, selecionar texto dentro do
        // modal o fecharia.
        <div className={CLASSES_BACKDROP} onClick={fechar === null ? undefined : () => fechar()}>
            <div className={CLASSES_CAIXA} onClick={(evento) => evento.stopPropagation()}>
                <TopoDoModal titulo={TITULO} sub={subDaImagem(imagem) ?? null} onFechar={onFechar} />

                <div className={CLASSES_CORPO}>
                    <CorpoDaReprovacao
                        imagem={imagem}
                        defeitos={defeitos}
                        motivo={motivo}
                        onMotivo={onMotivo}
                    />
                </div>

                <div className={CLASSES_RODAPE}>
                    {fechar === null ? null : (
                        <button type="button" onClick={() => fechar()} className={cn(CLASSES_BOTAO, CLASSES_CANCELAR)}>
                            {ROTULO_CANCELAR}
                        </button>
                    )}

                    <BotaoDeReprovar
                        podeSubmeter={pode}
                        onSubmeter={enviar === null ? null : () => {
                            // ⚠️ A guarda é REPETIDA de propósito: a régua é "o
                            // clique NÃO chama a rota", não "o botão está cinza".
                            if (!motivoValido(motivo)) return;

                            enviar({ motivo });
                        }}
                    />
                </div>
            </div>
        </div>
    );
}
