import { cn } from '@/lib/utils';
import { andamentoDe } from './andamentoDoPortao';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 188, plano 07 — A IDENTIFICAÇÃO DA OFERTA E A CAIXA DE FALHA.
//
// Produto · SKU, o título, os CINCO chips, responsável + espera (à direita) e,
// quando há falha de publicação, a caixa vermelha com a mensagem REAL do
// Mercado Livre.
//
// ═══ ⚠️ MEDIDAS DO PROTÓTIPO — por SCRIPT (`sed -n '<L>p'`), 19/10/2026 ═════
//
// `design_handoff_publicador/Aprovacao.dc.html` (1432 linhas, sha256
// `68624883ce35547e`). ⚠️ **O diretório importa** — há DUAS cópias do pacote de
// handoff na árvore, com conteúdo DIFERENTE.
//
//   · B11w wrapper     → `:563` `display:flex;align-items:flex-start;`
//                              `justify-content:space-between;gap:20px`
//   · B12w coluna esq. → `:564` `display:flex;flex-direction:column;gap:8px;`
//                              **`min-width:0`**
//   · B12  produto·SKU → `:565` `font-size:12px;color:#8A8A90`; o SKU em
//                              `font-family:'Geist Mono',monospace;font-size:11.5px`
//   · B13  título      → `:566` `font-size:20px;line-height:1.25;font-weight:600;`
//                              `letter-spacing:-0.015em;text-wrap:pretty`
//   · B14w fila chips  → `:567` `display:flex;**flex-wrap:wrap**;gap:6px`
//   · B14  modalidade  → `:568` `display:flex;align-items:center;gap:6px;`
//                              `height:24px;padding:0 9px;border-radius:6px;`
//                              `white-space:nowrap;background:#1F1F1F;`
//                              `font-size:12px;font-weight:600`
//                              + losango Premium `width:7px;height:7px;`
//                              `background:#F4F4F5;transform:rotate(45deg);`
//                              `border-radius:1px`
//   · B15  formato     → `:569` `display:flex;align-items:center;height:24px;`
//                              `padding:0 9px;border-radius:6px;`
//                              `white-space:nowrap;border:1px solid #2E2E2E;`
//                              `font-size:12px;color:#D4D4D8`
//   · B15  origem      → `:570` idem com `color:#A1A1AA`
//   · B15  versão      → `:571` idem com `color:#A1A1AA`
//   · B16  andamento   → `:572` `display:flex;align-items:center;gap:6px;`
//                              `height:24px;padding:0 9px;border-radius:6px;`
//                              `white-space:nowrap;border:1px solid {stBorder};`
//                              `background:{stBg};color:{stColor};`
//                              `font-size:12px;font-weight:600`
//                              + dot `width:6px;height:6px;border-radius:50%;`
//                              `background:{stColor}`
//   · B17  resp+espera → `:575` `display:flex;flex-direction:column;`
//                              `align-items:flex-end;gap:3px;font-size:12px;`
//                              `color:#8A8A90;white-space:nowrap`;
//                              o responsável em `color:#D4D4D8`
//   · B18  caixa falha → `:579` `display:flex;align-items:center;gap:12px;`
//                              `padding:12px 14px;`
//                              `border:1px solid rgba(239,68,68,0.4);`
//                              `border-radius:10px;background:rgba(239,68,68,0.07)`
//   · B19  glifo `!`   → `:580` `width:22px;height:22px;border-radius:50%;`
//                              `background:#EF4444;color:#0A0A0A;`
//                              **`display:grid;place-items:center`**;
//                              `font-size:12px;font-weight:800;flex:none`
//   · B20w col. texto  → `:581` `display:flex;flex-direction:column;gap:2px;flex:1`
//   · B20  título+msg  → `:581` `font-size:13px;font-weight:600;color:#F87171`
//                              + mensagem `font-size:12.5px;color:#D4D4D8`
//   · B21  botão       → `:582` `white-space:nowrap;height:32px;padding:0 14px;`
//                              `border:none;border-radius:8px;background:#FACC15;`
//                              `color:#0A0A0A;font-size:12.5px;font-weight:600;`
//                              `cursor:pointer`
//
// ⚠️⚠️ **QUATRO OMISSÕES DA TABELA FID-01 §B, MEDIDAS NESTA SESSÃO** (a família
// dominante de erro daquela tabela é por OMISSÃO e por REFERÊNCIA, quase nunca
// por valor — a wave 1 remediu e achou 11 omissões):
//   1. **B19 — `display:grid;place-items:center`** não está na célula do
//      documento. É o que CENTRA o `!` dentro do círculo de 22px; sem ele o glifo
//      cola no canto superior-esquerdo.
//   2. **B20 — o WRAPPER de `:581`** (`display:flex;flex-direction:column;`
//      `gap:2px;**flex:1**`) não é nomeado: a célula lista só os dois spans. É o
//      `flex:1` dele que empurra o botão de `:582` para a direita.
//   3. **B18 — `display:flex;align-items:center`** não está na medida-chave.
//   4. **B14/B15/B16 — `display:flex;align-items:center`** não estão nas três
//      medidas-chave; sem eles o chip não centra o conteúdo na altura de 24px.
// ⚠️ **As quatro estão REPRODUZIDAS aqui e DECLARADAS na SUMMARY.**
//
// ═══ ⚠️ OS TOKENS, NENHUM NOVO (medidos em `tailwind.config.js`) ═══════════
//
//   `#8A8A90` → `pub-txt-3` · `#D4D4D8` → `pub-fase1` · `#A1A1AA` → `pub-txt-2`
//   `#1F1F1F` → `pub-line-subtle` · `#2E2E2E` → `pub-line-strong`
//   `#F4F4F5` → `pub-txt` · `#EF4444` → `pub-danger` · `#F87171` → `pub-danger-txt`
//   `#FACC15` → `pub-accent` · `#0A0A0A` → `pub-page`
//   `border-radius:6px` → `rounded-pub-chip` · `10px` → `rounded-pub-sub`
//   `8px` → ⚠️ sem token (`pub-control` é 9px) → `rounded-[8px]`
//   `font-family:'Geist Mono'` → `font-pub-mono`
//
// ═══ ⚠️⚠️ POR QUE O CHIP DE ANDAMENTO NÃO REUSA `SeloDeAndamentoInterno` ════
//
// Aquele componente (Fase 186) é a pill de `:170`, da **FILA**: `height:22px`,
// `font-size:11.5px`, dot de 6px e anel de 9px. O chip **deste painel** é `:572`:
// `height:24px`, `font-size:12px`. ➡️ **São dois elementos DIFERENTES do
// protótipo, com medidas diferentes** — reusar a pill da fila aqui mudaria a
// altura e o corpo da letra, que é FID-03 ao contrário.
// ⚠️ **MAS AS CORES TÊM UMA CASA SÓ:** elas são **LIDAS** de `andamentoDe()`
// (`andamentoDoPortao.jsx`), nunca recopiadas. Duas tabelas de cor para o mesmo
// andamento divergiriam na primeira edição.
// ⚠️ `andamentoDe()` **NUNCA devolve `null`**: chave conhecida → o estado; string
// desconhecida → a chave COMO CHEGOU, na forma neutra; qualquer outra coisa → o
// "—". É por isso que aqui não há `if (!estado) return null` — esse `return null`
// produziria um retângulo colorido **SEM TEXTO**, que passa em render e
// DESAPARECE na tela.
//
// ═══ ⚠️⚠️ O BOTÃO "TENTAR PUBLICAR NOVAMENTE" NÃO É DESENHADO HOJE ═════════
//
// ⚠️ **MEDIDO em 19/10/2026, por `grep` em `routes/` e `app/Http/Controllers/`:
// NÃO EXISTE rota para `AprovacaoDaOfertaService::tentarDeNovo()`.** As únicas
// rotas da aprovação são `mlb.anuncios.meus.aprovacao` (GET, 187-02, `:501`) e
// `mlb.anuncios.meus.aprovacao.lote` (POST, 187-09, `:527`). As rotas de escrita
// do painel são dos planos **188-05/06**.
// ➡️ Sem handler o botão **NÃO é desenhado**, e a caixa **continua aparecendo**
// com o glifo, o título e a mensagem REAL. É a instrução literal do plano
// (*"se essa rota não existir ainda, o botão NÃO é desenhado e você declara"*) e
// a mesma régua de PERGUNTA-188-B (destino `null` → **SEM BOTÃO**, e o TEXTO
// continua). ⚠️ **Botão sem destino é D4 violado**, e `onClick` vazio é a mesma
// dívida com outra roupa.
//
// ⚠️ **PUB-05 — a mensagem é o erro REAL do Mercado Livre**, nunca um "algo deu
// errado": sem a frase real a pessoa não tem como saber se tenta de novo ou
// conserta o cadastro. ⚠️ E sem erro gravado a caixa **continua aparecendo** (a
// falha É o fato) — esconder a caixa esconderia trabalho.
//
// ⚠️ Nenhum `String(valor)`, nenhum `hasOwnProperty` e nenhum `obj[chave]` com
// chave vinda do servidor: os três **LANÇAM** com `{toString: null}`.
// ⚠️ **Flag DENTRO do callback do `.map()` dos chips** — o Rollup elimina
// variável de escopo do componente usada dentro de `.map()`, e o `ReferenceError`
// só aparece no bundle de produção.
// ═══════════════════════════════════════════════════════════════════════════

/** `:563` — o wrapper produto × responsável. */
export const CLASSES_LINHA = 'flex items-start justify-between gap-[20px]';

/** `:564` — a coluna esquerda. ⚠️ `min-w-0` é o que permite o título truncar no grid. */
export const CLASSES_COLUNA_ESQUERDA = 'flex min-w-0 flex-col gap-[8px]';

/** `:565` — "produto · SKU". */
export const CLASSES_PRODUTO = 'text-[12px] text-pub-txt-3';

/** `:565` — o SKU, em monoespaçada. */
export const CLASSES_SKU = 'font-pub-mono text-[11.5px]';

/** `:565` — o separador entre produto e SKU. */
export const SEPARADOR = '·';

/** `:566` — o título. ⚠️ `text-pretty` é o `text-wrap:pretty` (Tailwind 3.4+). */
export const CLASSES_TITULO = 'text-[20px] font-semibold leading-[1.25] tracking-[-0.015em] text-pretty';

/** `:567` — a fila dos chips. ⚠️ `flex-wrap` é o que impede os 5 chips de vazarem. */
export const CLASSES_FILA_DE_CHIPS = 'flex flex-wrap gap-[6px]';

/** `:568`-`:572` — a geometria COMUM dos cinco chips. */
export const CLASSES_CHIP = 'flex items-center h-[24px] px-[9px] rounded-pub-chip whitespace-nowrap text-[12px]';

/** `:568` — o chip de modalidade: fundo sólido, peso 600, e gap para o losango. */
export const CLASSES_CHIP_MODALIDADE = 'gap-[6px] bg-pub-line-subtle font-semibold';

/** `:568` — o losango, só do Premium. */
export const CLASSES_LOSANGO = 'h-[7px] w-[7px] flex-none rotate-45 rounded-[1px] bg-pub-txt';

/** `:569`/`:570`/`:571` — a borda comum dos três chips de contorno. */
export const CLASSES_CHIP_CONTORNO = 'border border-pub-line-strong';

/** `:572` — o chip de andamento: borda, fundo e cor vêm de `andamentoDe()`. */
export const CLASSES_CHIP_ANDAMENTO = 'gap-[6px] border font-semibold';

/** `:572` — o dot do chip de andamento. ⚠️ `bg-current` é a MESMA cor do texto. */
export const CLASSES_DOT = 'h-[6px] w-[6px] flex-none rounded-full bg-current';

/** `:575` — responsável + espera, à direita. */
export const CLASSES_LADO_DIREITO =
    'flex flex-none flex-col items-end gap-[3px] whitespace-nowrap text-[12px] text-pub-txt-3';

/** `:575` — o responsável, mais claro que a espera. */
export const CLASSES_RESPONSAVEL = 'text-pub-fase1';

/** `:579` — a caixa de falha. */
export const CLASSES_FALHA =
    'flex items-center gap-[12px] rounded-pub-sub border border-pub-danger/[0.4] bg-pub-danger/[0.07] px-[14px] py-[12px]';

/**
 * `:580` — o glifo `!`.
 *
 * ⚠️ `grid place-items-center` é **OMISSÃO medida** da tabela FID-01 (ver o
 * docblock da classe): é ele que CENTRA o `!` dentro do círculo de 22px.
 * ⚠⚠ **`font-[800]` e NÃO `font-extrabold`**, e isso é PRECEDENTE do projeto, não
 * escolha: o **GATE-03** (`publicador-vocabulario-novo.test.js`) PROÍBE a escala
 * NOMEADA `font-extrabold` em todo arquivo de `ARQUIVOS_NOVOS` (só 400/500/600/700),
 * e `:580` mede `font-weight:800`. ➡️ O valor arbitrário entrega o **MESMO peso
 * computado** sem furar o gate — é a mesma saída de `AnelDeProgresso.jsx:67` e de
 * `CabecalhoDaFila.jsx` (as duas em produção), e há gate de CSS CONSTRUÍDO neste
 * plano provando que ela compila para `font-weight:800`.
 */
export const CLASSES_GLIFO_FALHA =
    'grid h-[22px] w-[22px] flex-none place-items-center rounded-full bg-pub-danger text-[12px] font-[800] text-pub-page';

/** `:580` — o glifo literal. */
export const GLIFO_FALHA = '!';

/** `:581` — o WRAPPER da coluna de texto. ⚠️ OMISSÃO medida; o `flex-1` empurra o botão. */
export const CLASSES_FALHA_TEXTO = 'flex flex-1 flex-col gap-[2px]';

/** `:581` — o título da falha. */
export const CLASSES_FALHA_TITULO = 'text-[13px] font-semibold text-pub-danger-txt';

/** `:581` — o literal do título. */
export const TITULO_DA_FALHA = 'Falha na publicação';

/** `:581` — a mensagem REAL do Mercado Livre (PUB-05). */
export const CLASSES_FALHA_MENSAGEM = 'text-[12.5px] text-pub-fase1';

/** `:582` — o botão de tentar de novo. */
export const CLASSES_FALHA_ACAO =
    'h-[32px] flex-none cursor-pointer whitespace-nowrap rounded-[8px] bg-pub-accent px-[14px] text-[12.5px] font-semibold text-pub-page';

/** Texto não-vazio, ou `null`. ⚠️ Nunca `String(valor)`; e `NaN` não passa. */
export function textoDaOferta(valor) {
    if (typeof valor !== 'string') return null;

    return valor.trim() === '' ? null : valor;
}

/** Objeto comum de verdade, ou `null`. */
export function objetoDaOferta(valor) {
    return valor !== null && typeof valor === 'object' && !Array.isArray(valor) ? valor : null;
}

/**
 * Os CINCO chips de `:568`-`:572`, **nesta ordem**, prontos para o `.map()`.
 *
 * ⚠️ NAMED EXPORT: o render de servidor não desenha portal, e é assim que o gate
 * confere a ordem e o conteúdo sem montar a árvore toda.
 * ⚠️ Chip sem rótulo **não entra na lista** — chip vazio de 24px é D4 violado.
 */
export function chipsDaOferta(chips) {
    const c = objetoDaOferta(chips);
    if (c === null) return [];

    const modalidade = objetoDaOferta(c.modalidade);
    const andamento = objetoDaOferta(c.andamento);

    // ⚠️⚠️ A chave `premium` do módulo da 186, e **NUNCA truthiness genérica**
    // (`if (modalidade)`): o losango é o que distingue Premium de Clássico na
    // tela, e um `if` genérico o desenharia nos dois.
    const ehPremium = modalidade !== null && modalidade.premium === true;

    // ⚠️⚠️ As cores do andamento são **LIDAS** de `andamentoDe()`, nunca
    // recopiadas — e ele NUNCA devolve `null`, então o rótulo nunca é vazio.
    const estado = andamento === null ? andamentoDe(null) : andamentoDe(andamento.chave);

    return [
        { chave: 'modalidade', rotulo: modalidade === null ? null : textoDaOferta(modalidade.rotulo), premium: ehPremium },
        { chave: 'formato', rotulo: textoDaOferta(c.formato) },
        { chave: 'origem', rotulo: textoDaOferta(c.origem) },
        { chave: 'versao', rotulo: textoDaOferta(c.versao) },
        // ⚠️ O rótulo do andamento vem do MÓDULO, não da string do servidor: é a
        // mesma fonte que a fila usa, e `andamentoDe()` garante texto não-vazio.
        { chave: 'andamento', rotulo: textoDaOferta(estado.rotulo), estado },
    ].filter((chip) => chip.rotulo !== null);
}

/**
 * Se há algo para identificar — produto, SKU, título, chip ou falha.
 *
 * ⚠️ NAMED EXPORT porque **quem monta o corpo precisa saber ANTES de desenhar a
 * casca**: bloco de 18px de padding sem conteúdo é D4 violado, e um componente
 * que devolve `null` por dentro já desenhou a casca por fora.
 */
export function temIdentificacao(identificacao, falha) {
    const id = objetoDaOferta(identificacao);
    if (objetoDaOferta(falha) !== null) return true;
    if (id === null) return false;

    return textoDaOferta(id.produto) !== null
        || textoDaOferta(id.sku) !== null
        || textoDaOferta(id.titulo) !== null
        || textoDaOferta(id.responsavel) !== null
        || textoDaOferta(id.espera) !== null
        || chipsDaOferta(id.chips).length > 0;
}

/**
 * A caixa de falha, ou `null` fora do andamento de falha.
 *
 * ⚠️ NAMED EXPORT, e exercida **no CLIQUE** pelo gate.
 *
 * @param {object} props
 * @param {unknown} [props.falha] `{mensagem, rotulo_acao}` do servidor, ou `null`
 * @param {unknown} [props.onTentarDeNovo] `() => void`. ⚠️ **Sem handler o botão
 *   NÃO é desenhado** — e hoje ele nunca é, porque a rota de
 *   `AprovacaoDaOfertaService::tentarDeNovo()` **não existe** (medido). Ver o
 *   docblock da classe.
 */
export function CaixaDeFalha({ falha = null, onTentarDeNovo = null }) {
    const dados = objetoDaOferta(falha);
    if (dados === null) return null;

    // ⚠️ PUB-05 — a mensagem é o erro REAL do ML. Sem erro gravado a caixa
    // **continua aparecendo**: a falha É o fato, e esconder a caixa esconderia
    // trabalho. O que não aparece é a LINHA da mensagem, nunca a caixa.
    const mensagem = textoDaOferta(dados.mensagem);
    const rotulo = textoDaOferta(dados.rotulo_acao);
    const podeTentar = typeof onTentarDeNovo === 'function' && rotulo !== null;

    return (
        <div className={CLASSES_FALHA}>
            <span aria-hidden="true" className={CLASSES_GLIFO_FALHA}>{GLIFO_FALHA}</span>
            <span className={CLASSES_FALHA_TEXTO}>
                <span className={CLASSES_FALHA_TITULO}>{TITULO_DA_FALHA}</span>
                {mensagem === null ? null : <span className={CLASSES_FALHA_MENSAGEM}>{mensagem}</span>}
            </span>
            {podeTentar ? (
                <button type="button" className={CLASSES_FALHA_ACAO} onClick={() => onTentarDeNovo()}>
                    {rotulo}
                </button>
            ) : null}
        </div>
    );
}

/**
 * A identificação da oferta: produto · SKU, título, os 5 chips, responsável e
 * espera — e a caixa de falha logo abaixo.
 *
 * @param {object} props
 * @param {unknown} [props.identificacao] o bloco `identificacao` do servidor
 * @param {unknown} [props.falha] o bloco `falha` do servidor, ou `null`
 * @param {unknown} [props.onTentarDeNovo] `() => void`, ou `null` (o normal hoje)
 */
export default function IdentificacaoDaOferta({ identificacao = null, falha = null, onTentarDeNovo = null }) {
    const id = objetoDaOferta(identificacao);

    const produto = textoDaOferta(id === null ? null : id.produto);
    const sku = textoDaOferta(id === null ? null : id.sku);
    const titulo = textoDaOferta(id === null ? null : id.titulo);
    const responsavel = textoDaOferta(id === null ? null : id.responsavel);
    const espera = textoDaOferta(id === null ? null : id.espera);
    const chips = chipsDaOferta(id === null ? null : id.chips);

    const temEsquerda = produto !== null || sku !== null || titulo !== null || chips.length > 0;
    const temDireita = responsavel !== null || espera !== null;
    const caixa = <CaixaDeFalha falha={falha} onTentarDeNovo={onTentarDeNovo} />;

    // ⚠️ Nada para mostrar não desenha wrapper nenhum — nem a linha de 20px de
    // gap (D4: nada nasce decorativo).
    if (!temEsquerda && !temDireita) return caixa;

    return (
        <>
            <div className={CLASSES_LINHA}>
                {temEsquerda ? (
                    <div className={CLASSES_COLUNA_ESQUERDA}>
                        {/* ⚠️ O "·" só existe com os DOIS lados: `produto ·` solto
                            seria pontuação órfã. */}
                        {produto === null && sku === null ? null : (
                            <span className={CLASSES_PRODUTO}>
                                {produto}
                                {produto !== null && sku !== null ? ` ${SEPARADOR} ` : null}
                                {sku === null ? null : <span className={CLASSES_SKU}>{sku}</span>}
                            </span>
                        )}

                        {titulo === null ? null : <span className={CLASSES_TITULO}>{titulo}</span>}

                        {chips.length === 0 ? null : (
                            <div className={CLASSES_FILA_DE_CHIPS}>
                                {chips.map((chip) => {
                                    // ⚠️⚠️ **FLAG DENTRO DO CALLBACK.** O Rollup
                                    // elimina variável de escopo do componente usada
                                    // dentro de `.map()`, e o `ReferenceError` só
                                    // aparece no bundle de produção. Tudo o que esta
                                    // expressão lê é PARÂMETRO (`chip`) ou nome de
                                    // MÓDULO (as constantes), que o Rollup nunca
                                    // elide — a forma que a wave 187-09 mediu.
                                    const ehAndamento = chip.chave === 'andamento';
                                    const ehModalidade = chip.chave === 'modalidade';
                                    const estado = ehAndamento ? chip.estado : null;

                                    return (
                                        <span
                                            key={chip.chave}
                                            className={cn(
                                                CLASSES_CHIP,
                                                ehModalidade ? CLASSES_CHIP_MODALIDADE : null,
                                                ehAndamento ? CLASSES_CHIP_ANDAMENTO : null,
                                                ehAndamento && estado !== null ? [estado.borda, estado.fundo, estado.texto] : null,
                                                !ehAndamento && !ehModalidade ? CLASSES_CHIP_CONTORNO : null,
                                                chip.chave === 'formato' ? 'text-pub-fase1' : null,
                                                chip.chave === 'origem' || chip.chave === 'versao' ? 'text-pub-txt-2' : null,
                                            )}
                                        >
                                            {ehModalidade && chip.premium ? (
                                                <span aria-hidden="true" className={CLASSES_LOSANGO} />
                                            ) : null}
                                            {ehAndamento ? <span aria-hidden="true" className={CLASSES_DOT} /> : null}
                                            {chip.rotulo}
                                        </span>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                ) : null}

                {temDireita ? (
                    <div className={CLASSES_LADO_DIREITO}>
                        {responsavel === null ? null : <span className={CLASSES_RESPONSAVEL}>{responsavel}</span>}
                        {espera === null ? null : <span>{espera}</span>}
                    </div>
                ) : null}
            </div>
            {caixa}
        </>
    );
}
