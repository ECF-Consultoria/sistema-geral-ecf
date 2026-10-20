import { cn } from '@/lib/utils';
import { ColunaDoPainel, escreverNoPainel } from './PainelDeRevisao';
import { ABREVIACAO_DO_BLOQUEIO, BLOQUEADO_PREFIXO } from './vocabularioDoPortao';
import ImagemGrandeDoPainel, {
    TEXTO_DA_COR,
    classeDaCor,
    inteiroDoPainel,
    listaDoPainel,
    objetoDoPainel,
    textoDoPainel,
} from './ImagemGrandeDoPainel';
import GradeDeMiniaturas from './GradeDeMiniaturas';
import BlocoDaImagemSelecionada from './BlocoDaImagemSelecionada';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 188, plano 08 — A COLUNA DE FOTOS DO PAINEL (`:587`-`:636`): o progresso,
// "Aprovar N sem alerta", a imagem grande, a grade de 6 e o bloco da selecionada.
//
// ⚠️⚠️ APROVAR **ENFILEIRA**, NÃO PUBLICA — e não há desfazer. A escrita no
// Mercado Livre é da **Fase 193** (critério 18): `AprovacaoDaOfertaService` move
// o andamento pela única porta e congela a composição, tudo no NOSSO banco.
// Mesmo assim, para quem revisa, **a decisão é final**: é aqui que a pessoa diz
// se a imagem vai ao ar.
//
// ⚠️⚠️ TODA ESCRITA DESTA WAVE VAI PELO HELPER ÚNICO `escreverNoPainel` ═══════
//
// **Por que é o critério 17 e não estilo:** `versao_esperada` é **OPCIONAL no
// corpo** (plano 188-05), e omiti-la faz
// `AprovacaoDaOfertaService::exigirVersao()` receber `null` e **LIBERAR** a
// escrita (o ramo de uso interno/console) — que é literalmente *"sobrescrever em
// silêncio"*. O helper (1) acrescenta `versao_esperada` **sempre**, (2) e **não
// engole o 422** de versão divergente, acendendo o aviso do plano 188-12.
// ⚠️ `router.post` direto **não existe** em nenhum dos quatro `.jsx` deste plano;
// o gate com LISTA CONSTANTE mora no 188-12, porque o do 188-07 rodou **antes de
// estes arquivos existirem** e seria gate sobre zero casos.
//
// ⚠️⚠️ CRITÉRIO 5 — "Aprovar N sem alerta" aprova **SÓ as pendentes SEM alerta**,
// foto com alerta **continua** para conferência individual, **e a tela diz isso**.
// O N vem do servidor (`fotos.sem_alerta.quantidade`, plano 188-03) e é o MESMO
// número que a ação aprova: a wave 188-05 mediu `quantidade = 3 → aprovadas = 3,
// grupos_percorridos = 2, com_alerta_restantes = 1`. ⚠️ **Decisão 8 do usuário:**
// aprovar silenciosamente só as que passam foi **RECUSADO** — *"tudo de uma vez, e
// só libera com todas conferidas"*.
//
// ⚠️⚠️ PERGUNTA-188-D — RESPONDIDA PELO USUÁRIO EM 19/10/2026: **texto FIXO na
// coluna, SEM sétimo toast.** É a QUARTA derrota do protótipo nesta milestone.
//
// Os DOIS lados, com `arquivo:linha`:
//   · `design_handoff_publicador/Aprovacao.dc.html:1151` — `approveSafe(set)`
//     dispara `this.toast('Fotos sem alerta aprovadas', 'Fotos com alerta
//     automático continuam para conferência individual')`;
//   · aqui — `AVISO_RESTANTES_*`, um texto que **PERMANECE** enquanto houver foto
//     com alerta.
//
// As razões do usuário:
//   1. ⚠️ **A informação é ESTADO, não EVENTO.** "Sobrou 1 com alerta" é verdade
//      até alguém agir; toast some em ~3,8 s. A decisão 8 pede que **a tela diga
//      quem ficou de fora e por quê**, e aviso que desaparece não cumpre isso.
//   2. ⚠️ **"Toast + texto fixo" foi OFERECIDO e RECUSADO**, porque poria o MESMO
//      número em DUAS fontes — e número repetido em dois lugares já divergiu
//      neste projeto (é a razão da decisão 9, as duas margens, e das decisões
//      9/16 da fila, em que o usuário recusou conta divergente da lista DUAS
//      vezes).
//   3. Não criar a 7ª frase mantém o vocabulário travado fechado (VOC-08, os
//      SEIS de `vocabularioDoPortao.jsx:213`-`:220`) — o mesmo motivo pelo qual o
//      toast do lote venceu o protótipo na Fase 187.
// ⚠️ O número é `fotos.com_alerta_restantes`, **do servidor**, e **não** é
// recontado na tela. ⚠️ Com `0` o aviso **não nasce** (nada é decorativo). ⚠️ Cor
// não é o único sinal: o aviso leva **glifo e texto**.
// ⚠️ O aviso é LINHA PRÓPRIA, no mesmo idioma da nota de conjunto (`:592`,
// `margin-top:-6px`), logo abaixo do "x de y aprovadas". **Nada medido foi
// encolhido para caber**: a faixa de `:588` continua `justify-between` com o
// progresso à esquerda e o botão à direita.
//
// ⚠️⚠️ A BORDA DE SELEÇÃO SEGUE A IMAGEM SELECIONADA, NÃO A FOTO 0.
// `PainelDeRevisaoService.php:310` chama `FotosDoPainelService::para($oferta)`
// **sem índice**, e o default é `0` — então `borda`, `anel`, `selecionada` e
// `selecionada_indice` do payload estão **presos no índice 0**. A seleção viva
// chega por prop (`selecionada`) e a borda é resolvida em `GradeDeMiniaturas`,
// espelhando `FotosDoPainelService::borda()`. **Divergência DECLARADA do
// protótipo, que é estático por construção.**
//
// ⚠️ Galeria vazia é **VAZIO HONESTO**: o texto sai da composição de dois
// literais TRAVADOS que já existem (`BLOQUEADO_PREFIXO` +
// `ABREVIACAO_DO_BLOQUEIO.sem_fotos`, `vocabularioDoPortao.jsx:253` e `:121`) —
// **nenhuma frase nova é cunhada**. ⚠️ E **nenhum botão**: "Adicionar fotos" leva
// ao EDITOR, que é território proibido nesta fase. A pendência do rodapé (plano
// 188-10) faz o resto do trabalho.
//
// ⚠️⚠️ "⤢ Ampliar" é **DESTA FASE desde o plano 189-06** (decisão **D-189-3b**):
// esta coluna **REPASSA** `onAmpliar` e `refDoAmpliar` à imagem grande, que é
// quem desenha o botão de `:607` — e **só** quando o handler é FUNÇÃO. A frase
// que estava aqui (*"não são desenhados"*) foi EDITADA, não apagada em silêncio,
// e o gate de ausência do 188-08 foi REAPONTADO COM VETO (sem handler, proibido;
// com handler, exigido).
// ⚠️ "✦ Gerar novamente com IA" (Fase 192) **continua ausente**, com gate de
// ausência e isca sintética. A coluna de PREÇO é da wave 188-09, as pendências e
// o rodapé da 188-10, os modais da 188-11/12 — **nada de provisório de nenhuma
// delas**.
// ⚠️⚠️ **A COLUNA NÃO GUARDA ESTADO DE LIGHTBOX.** Ela não sabe se ele está
// aberto, em que foto nem com que zoom: o estado mora na PÁGINA (que é quem já
// guarda a foto selecionada) e o único `keydown` mora no `PainelDeRevisao`. Aqui
// só passam DOIS fios opacos.
// ⚠️ Nenhum `localStorage` e nenhum *"Restaurar dados de exemplo"*: são artefatos
// do protótipo (`:914`).
// ═══════════════════════════════════════════════════════════════════════════

/** `:589` — "Fotos", byte a byte. */
export const TITULO = 'Fotos';

/**
 * ⚠️ PERGUNTA-188-D, singular e plural. O `·` é U+00B7.
 *
 * O número é `fotos.com_alerta_restantes` — do SERVIDOR.
 */
export const AVISO_RESTANTES_SINGULAR = ' foto ainda com alerta · confira uma a uma';

export const AVISO_RESTANTES_PLURAL = ' fotos ainda com alerta · confira uma a uma';

/** O glifo do aviso: **cor não é o único sinal**. */
export const GLIFO_AVISO = '!';

/** As rotas do plano 188-05, por NOME (Ziggy). */
export const ROTA_SEM_ALERTA = 'mlb.anuncios.meus.aprovacao.painel.fotos.sem-alerta';

export const ROTA_APROVAR_IMAGEM = 'mlb.anuncios.meus.aprovacao.painel.imagem.aprovar';

// ─── As classes, uma casa só (`:588`-`:592`) ───────────────────────────────

/** `:588` — a faixa do cabeçalho da coluna. */
export const CLASSES_FAIXA = 'flex items-center justify-between gap-[10px]';

/** `:589` — "Fotos" + o progresso, alinhados pela BASELINE. */
export const CLASSES_PROGRESSO = 'flex items-baseline gap-[8px]';

/** `:589` — "Fotos". */
export const CLASSES_TITULO = 'text-[15px] font-semibold';

/** `:589` — `x de y aprovadas`, com a cor vinda de `progresso_cor`. */
export const CLASSES_PROGRESSO_TEXTO = 'text-[12.5px] font-medium';

/** `:590` — "Aprovar N sem alerta". */
export const CLASSES_SEM_ALERTA = 'h-[28px] cursor-pointer whitespace-nowrap rounded-pub-control-sm border '
    + 'border-pub-line-strong bg-pub-hover-2 px-[10px] text-[12px] text-pub-txt hover:border-pub-line-off';

/** ⚠️ PERGUNTA-188-D — o aviso que PERMANECE, no idioma de `:592`. */
export const CLASSES_AVISO = '-mt-[6px] flex items-center gap-[6px] text-[12px] text-pub-warn-txt';

/** O glifo redondo do aviso (o mesmo desenho do badge `!` da miniatura). */
export const CLASSES_GLIFO_AVISO = 'grid h-[16px] w-[16px] flex-none place-items-center rounded-full '
    + 'bg-pub-warn text-[10px] font-[800] text-pub-page';

/** `:592` — a nota de conjunto. ⚠️ `margin-top:-6px` é da medida, não do olho. */
export const CLASSES_NOTA = '-mt-[6px] text-[12px] text-pub-txt-2';

/** O vazio honesto da galeria. */
export const CLASSES_VAZIO = 'rounded-pub-sub border border-pub-line-subtle bg-pub-sunken px-[14px] py-[12px] text-[12.5px] text-pub-txt-2';

/**
 * ⚠️ O texto do vazio: COMPOSIÇÃO de dois literais travados, nenhuma frase nova.
 *
 * `BLOQUEADO_PREFIXO` (`vocabularioDoPortao.jsx:253`) + a abreviação de
 * `sem_fotos` (`:121`) — a MESMA composição que a linha da fila já usa.
 */
export const VAZIO = BLOQUEADO_PREFIXO + ABREVIACAO_DO_BLOQUEIO.sem_fotos;

/**
 * ⚠️⚠️ O ÍNDICE SELECIONADO, clampado nas DUAS pontas.
 *
 * ⚠️ Índice fora da faixa cai no `0`, e **não** em `null`: `null` é reservado para
 * *"galeria vazia"*, que é um estado que a coluna DECLARA — usá-lo também para um
 * índice inválido faria a coluna dizer o vazio **com fotos na grade**, a tela
 * afirmando o que não é. (É a mesma divergência que a wave 188-03 declarou contra
 * o `:1293`, que clampa só o teto.)
 */
export function indiceSelecionado(pedido, quantas) {
    const total = inteiroDoPainel(quantas) ?? 0;
    if (total <= 0) return null;

    const indice = inteiroDoPainel(pedido);

    return indice !== null && indice >= 0 && indice < total ? indice : 0;
}

/**
 * ⚠️⚠️ Um ID de verdade: inteiro **POSITIVO**, ou `null`.
 *
 * ⚠️ **Conserto MEDIDO pelo gate desta wave:** `inteiroDoPainel` aceita `0`, e
 * `0` é um id **plausível** para quem lê — com ele a escrita saía para
 * `{imagem: 0}`, uma rota que não existe. Numa tela em que **aprovar enfileira e
 * não há desfazer**, escrita disparada sobre id inválido é pior que nenhuma: a
 * pessoa vê a ação acontecer e nada muda.
 */
export function idDoPainel(valor) {
    const inteiro = inteiroDoPainel(valor);

    return inteiro !== null && inteiro > 0 ? inteiro : null;
}

/** ⚠️ PERGUNTA-188-D — o texto do aviso, ou `null` com `0`/dado adverso. */
export function avisoDasRestantes(quantidade) {
    const quantas = inteiroDoPainel(quantidade);

    if (quantas === null || quantas <= 0) return null;

    return quantas + (quantas === 1 ? AVISO_RESTANTES_SINGULAR : AVISO_RESTANTES_PLURAL);
}

/**
 * ⚠️⚠️ As DUAS escritas desta wave, pelo helper único.
 *
 * NAMED EXPORT para o gate exercer o CLIQUE direto: `renderToStaticMarkup` **não
 * dispara `onClick`**, e a TELA PRETA da 187-06 foi **no clique**.
 *
 * ⚠️ Sem empresa, sem oferta ou sem versão a função devolve `null` — e sem
 * handler o botão **não é desenhado**. Nunca uma escrita sobre versão que a tela
 * não viu.
 */
export function escritasDaColuna(pedido = null) {
    // ⚠️⚠️ **Conserto MEDIDO pelo gate desta wave:** desestruturação com default
    // (`{ a = null } = {}`) só cobre `undefined` — com `null` ela **LANÇA**
    // (*"Cannot read properties of null"*), e a TELA PRETA voltou SEIS vezes neste
    // projeto, uma delas **no clique**. A guarda é de OBJETO, antes de tudo.
    const alvo = objetoDoPainel(pedido);
    const empresa = alvo === null ? null : idDoPainel(alvo.empresaId);
    const oferta = alvo === null ? null : idDoPainel(alvo.ofertaId);
    const versaoVista = alvo === null ? null : idDoPainel(alvo.versao);
    const onConflito = alvo !== null && typeof alvo.onConflito === 'function' ? alvo.onConflito : null;

    if (empresa === null || oferta === null || versaoVista === null) {
        return { aprovarSemAlerta: null, aprovarImagem: null };
    }

    return {
        aprovarSemAlerta: () => escreverNoPainel({
            rota: ROTA_SEM_ALERTA,
            parametros: { company: empresa, oferta },
            versao: versaoVista,
            onConflito,
        }),
        // ⚠️ `{imagem}` é `pub_aprovacao_imagens.id` (a linha de REVISÃO), nunca
        // `pub_imagens.id` — são tabelas diferentes, e confundi-las escreveria na
        // foto errada (`routes/mlb_anuncios.php:556`-`:558`).
        aprovarImagem: (imagemId) => {
            const imagem = idDoPainel(imagemId);
            if (imagem === null) return false;

            return escreverNoPainel({
                rota: ROTA_APROVAR_IMAGEM,
                parametros: { company: empresa, oferta, imagem },
                versao: versaoVista,
                onConflito,
            });
        },
    };
}

/**
 * A coluna de fotos do painel de revisão.
 *
 * @param {object} props
 * @param {unknown} [props.fotos] `painel.fotos` (plano 188-03)
 * @param {unknown} [props.live] se a oferta está **ao vivo**. ⚠️ O payload não tem
 *   um `live` de topo; a página o lê de `rodape.botoes.salvar`, que **É** o
 *   `$aoVivo` byte a byte (`PainelDeRevisaoService.php:647`) — uma definição só,
 *   nunca uma segunda "ao vivo" inventada na tela
 * @param {unknown} [props.selecionada] o índice VIVO da miniatura selecionada
 * @param {unknown} [props.onSelecionar] `(indice) => void`, ou `null`
 * @param {unknown} [props.empresaId] `companies.id`, para as rotas
 * @param {unknown} [props.ofertaId] `painel.oferta_id`
 * @param {unknown} [props.versao] `painel.versao` — a versão que a pessoa VIU
 * @param {unknown} [props.onConflito] `(mensagem) => void` — o 422 de COL-04
 *   (plano 188-12 é o dono do aviso)
 * @param {unknown} [props.onReprovar] `(imagemId) => void`, ou `null` — o plano
 *   **188-12** é quem o fornece, junto com o modal (B2)
 * @param {unknown} [props.onAmpliar] `(indice) => void`, ou `null` — o plano
 *   **189-06** (D-189-3b). ⚠️ Ele recebe o ÍNDICE da foto selecionada, não o id:
 *   é o índice que o lightbox abre (`:1319`)
 * @param {unknown} [props.refDoAmpliar] a `ref` que o painel pendura no
 *   "⤢ Ampliar" para devolver o foco ao fechar o lightbox (critério 7)
 */
export default function ColunaDeFotos({
    fotos = null,
    live = false,
    selecionada = null,
    onSelecionar = null,
    empresaId = null,
    ofertaId = null,
    versao = null,
    onConflito = null,
    onReprovar = null,
    onAmpliar = null,
    refDoAmpliar = null,
}) {
    const dados = objetoDoPainel(fotos);

    // ⚠️ Slot sem dado **não desenha casca**: borda de 14px vazia é D4 violado.
    if (dados === null) return null;

    const imagens = listaDoPainel(dados.imagens);
    const indice = indiceSelecionado(selecionada, imagens.length);
    const imagemAtual = indice === null ? null : objetoDoPainel(imagens[indice]);

    const progresso = textoDoPainel(dados.progresso);
    const nota = textoDoPainel(dados.nota_de_conjunto);
    const aviso = avisoDasRestantes(dados.com_alerta_restantes);

    // ⚠️ `sem_alerta` é `null` com N = 0 **ou** fora do `live` (o `hasSafe` de
    // `:1305`). O rótulo é o do servidor, com o N real.
    const semAlerta = objetoDoPainel(dados.sem_alerta);
    const rotuloSemAlerta = semAlerta === null ? null : textoDoPainel(semAlerta.rotulo);

    const escritas = escritasDaColuna({ empresaId, ofertaId, versao, onConflito });

    // ⚠️ Botão só com handler de VERDADE: botão sem destino é D4 violado.
    const aprovarSemAlerta = rotuloSemAlerta !== null && escritas.aprovarSemAlerta !== null
        ? escritas.aprovarSemAlerta
        : null;

    const idDaImagem = imagemAtual === null ? null : idDoPainel(imagemAtual.id);

    const aprovarImagem = idDaImagem !== null && escritas.aprovarImagem !== null
        ? () => escritas.aprovarImagem(idDaImagem)
        : null;

    const reprovarImagem = idDaImagem !== null && typeof onReprovar === 'function'
        ? () => onReprovar(idDaImagem)
        : null;

    return (
        <ColunaDoPainel>
            <div className={CLASSES_FAIXA}>
                <span className={CLASSES_PROGRESSO}>
                    <span className={CLASSES_TITULO}>{TITULO}</span>
                    {progresso === null ? null : (
                        <span className={cn(CLASSES_PROGRESSO_TEXTO, classeDaCor(TEXTO_DA_COR, dados.progresso_cor))}>
                            {progresso}
                        </span>
                    )}
                </span>

                {aprovarSemAlerta === null ? null : (
                    <button type="button" className={CLASSES_SEM_ALERTA} onClick={() => aprovarSemAlerta()}>
                        {rotuloSemAlerta}
                    </button>
                )}
            </div>

            {/* ⚠️ PERGUNTA-188-D — o aviso que PERMANECE. Glifo **e** texto. */}
            {aviso === null ? null : (
                <span className={CLASSES_AVISO}>
                    <span className={CLASSES_GLIFO_AVISO}>{GLIFO_AVISO}</span>
                    {aviso}
                </span>
            )}

            {nota === null ? null : <span className={CLASSES_NOTA}>{nota}</span>}

            {/* ⚠️ Galeria vazia: o vazio HONESTO, nunca blocos com buracos. */}
            {imagemAtual === null ? <span className={CLASSES_VAZIO}>{VAZIO}</span> : (
                <>
                    {/* ⚠️ `indice` aqui é o VIVO e já CLAMPADO por
                        `indiceSelecionado` — o `selecionada_indice` do payload
                        está preso no 0 (AVISO-189-1), e usá-lo abriria o
                        lightbox sempre na primeira foto. */}
                    <ImagemGrandeDoPainel
                        imagem={imagemAtual}
                        indice={indice}
                        onAmpliar={onAmpliar}
                        refDoAmpliar={refDoAmpliar}
                    />
                    <GradeDeMiniaturas imagens={imagens} selecionada={indice} onSelecionar={onSelecionar} />
                    <BlocoDaImagemSelecionada
                        imagem={imagemAtual}
                        live={live}
                        onAprovar={aprovarImagem}
                        onReprovar={reprovarImagem}
                    />
                </>
            )}
        </ColunaDoPainel>
    );
}
