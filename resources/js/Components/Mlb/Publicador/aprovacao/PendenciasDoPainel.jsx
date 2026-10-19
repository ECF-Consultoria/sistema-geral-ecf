import { cn } from '@/lib/utils';
import { escreverNoPainel } from './PainelDeRevisao';
import {
    classeDaCorDoPreco,
    objetoDoPainel,
    textoDoPainel,
} from './KpisDoPainel';
import { idDoPainel } from './ColunaDeFotos';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 188, plano 10 — AS PENDÊNCIAS, CADA UMA COM O SEU BOTÃO DE CORREÇÃO
// (`:699`-`:709`). É o critério 9.
//
// ═══ ⚠️⚠️ A TELA **OBEDECE** O DESTINO; ELA NÃO O DECIDE ═══════════════════
//
// Os NOVE destinos chegam RESOLVIDOS do servidor, em
// `painel.pendencias[*].destino` (`PainelDeRevisaoService::destino()`, plano
// 188-02). ⚠️⚠️ **Não há `switch` por chave de bloqueio nesta tela, e isso é
// desenho, não preguiça:** escrever esse `switch` aqui criaria a **SEGUNDA
// VERDADE** sobre *"para onde vai cada correção"*, e a divergente só apareceria
// quando alguém CLICASSE — ou seja, depois de a pessoa já ter sido mandada ao
// lugar errado. ➡️ A tela lê `destino.tipo` e obedece.
//
// Os quatro tipos que o servidor emite, e o `null`:
//   `{tipo:'foto',  imagem_id}`                → seleciona AQUELA imagem
//   `{tipo:'modal', modal:'frete'|'ajustes'}`  → abre o modal correspondente
//   `{tipo:'modal', modal:'excecao', excecao}` → abre a exceção, com o TIPO
//   `{tipo:'acao',  acao:'conferir_alteracao'} → confere SEM alterar o preço
//   `null`                                      → **SEM BOTÃO** (ver abaixo)
//
// ⚠️ **`frete_ausente` tem DOIS destinos, e é DESVIO DECLARADO da 188-02/04:**
// quando o que falta não é o frete e sim o CUSTO do produto, a Fase 186 já troca
// a correção para *"Solicitar ajuste"*, e o destino acompanha
// (`MODAL_AJUSTES`). ➡️ **Não se crava o modal de frete nessa chave** — cravá-lo
// faria o rótulo apontar para um lugar e o destino para outro, que é exatamente
// o que VOC-04 existe para impedir.
//
// ═══ ⚠️⚠️ PERGUNTA-188-B: DESTINO `null` É DESENHO, NÃO OMISSÃO ════════════
//
// Dois dos nove (`ajuste_em_aberto` e `titulo_longo`) levariam à **revisão
// detalhada**, cuja rota é da **Fase 190/191** e **não existe**
// (`routes/mlb_anuncios.php:491` diz isso). ➡️ `destino` sai `null`, **nenhum
// botão é desenhado**, e **o TEXTO da pendência CONTINUA APARECENDO** — a pessoa
// precisa saber o que trava a oferta mesmo sem um lugar para onde ir.
// ⚠️ **Botão sem destino é D4 violado**, e o usuário exerceu essa régua CINCO
// vezes nesta milestone. ⚠️ **`onClick` vazio é a mesma dívida com outra roupa, e
// `disabled` decorativo também** — as duas formas foram OFERECIDAS e RECUSADAS
// (PERGUNTA-188-B3). A decisão está registrada em `188-REGUA.md` §12: ela é
// **declarada**, não inventada aqui.
//
// ═══ ⚠️ D7 — A COR NUNCA É O ÚNICO SINAL ═══════════════════════════════════
//
// Cada item tem **TEXTO** (`:704`), e é ele o sinal; o glifo `!` (`:703`) e a cor
// **acompanham**. ➡️ Item sem texto **não é desenhado**: um círculo colorido sem
// palavra ao lado não diz nada a quem não distingue âmbar de vermelho.
// ⚠️ Os textos vêm de `VocabularioDoPortao::ROTULOS` pelo servidor — **lidos**,
// nunca reescritos aqui. ⚠️ **Jargão não vai para a tela.**
//
// ⚠️ **O ramo `fotos_a_conferir` COM ALERTA é VERMELHO** (`:1295`:
// `(b.k === 'photos' && inf.alerts.length)`), e é o mais fácil de omitir da
// cadeia de cores porque os outros três (`loss`, `freight`, `nophoto`) são por
// chave FIXA. ⚠️ A severidade é **LIDA** do payload, onde
// `VocabularioDoPortao::severidade()` já a resolveu com o contexto do alerta —
// recalculá-la aqui seria a segunda verdade da MESMA cor.
//
// ═══ ⚠️ A GEOMETRIA LITERAL — medida por SCRIPT (`sed`), 19/10/2026 ═════════
//
// `design_handoff_publicador/Aprovacao.dc.html` (1432 linhas, sha256
// `68624883ce35547e`). ⚠️ **O diretório importa**: há DUAS cópias do pacote de
// handoff na árvore, com conteúdo DIFERENTE.
//
//   · E1 `<section>`  → `:699` `background:#111111;border:1px solid #262626;`
//                             `border-radius:14px;padding:14px;display:flex;`
//                             `flex-direction:column;gap:8px`
//   · E2 título       → `:700` `font-size:15px;font-weight:600` · `Pendências`
//   · E3 item         → `:702` `display:flex;align-items:center;gap:10px;`
//                             `min-height:34px`
//   · E4 glifo        → `:703` `width:18px;height:18px;border-radius:50%;`
//                             `background:{b.bg};color:{b.color};`
//                             `display:grid;place-items:center;`
//                             `font-size:10.5px;font-weight:800;flex:none`
//   · E5 texto        → `:704` `flex:1;font-size:13px;color:#E4E4E7`
//   · E6 botão        → `:705` `white-space:nowrap;height:28px;padding:0 10px;`
//                             `border:1px solid #2E2E2E;border-radius:7px;`
//                             `background:#161616;color:#F4F4F5;font-size:12px;`
//                             `cursor:pointer` + hover `border-color:#3F3F46`
//   · E7 verde        → `:708` `display:flex;align-items:center;gap:10px;`
//                             `font-size:13px;color:#4ADE80` + o glifo `✓`
//
// ⚠️⚠️ **DUAS OMISSÕES MEDIDAS NESTA WAVE, e as duas são LOAD-BEARING:** a
// tabela FID-01 §E **não cita** `display:grid;place-items:center` em **E4
// (`:703`)** nem no glifo de **E7 (`:708`)** — é ele que CENTRA o `!` e o `✓`
// dentro do círculo de 18px. Sem ele os dois glifos encostam no canto superior
// esquerdo. É a família de omissão que a régua §3 prevê (`cursor`,
// `white-space` e os wrappers), agora em `place-items`.
//
// ⚠️⚠️ **`font-[800]` e NÃO `font-extrabold`**, e isso é PRECEDENTE do projeto:
// o **GATE-03** (`publicador-vocabulario-novo.test.js:870`) PROÍBE a escala
// NOMEADA em todo arquivo de `ARQUIVOS_NOVOS` (só 400/500/600/700), e `:703` e
// `:708` medem `font-weight:800`. ➡️ O valor arbitrário entrega o **MESMO peso
// computado** sem furar o gate — é a saída de `AnelDeProgresso.jsx:67`,
// `CabecalhoDaFila.jsx:186`, `TabelaDaFila.jsx:139`, `GradeDeMiniaturas.jsx:132`
// e `IdentificacaoDaOferta.jsx:212`, **todas em produção**.
//
// ⚠️ **`min-h-[34px]` CONTÉM a substring `h-[34px]`** — sonda de classe tem de
// ancorar no COMEÇO da classe. A 187-08 caiu nisso com `min-h-[64px]`, e a
// primeira redação daquele gate **reprovou código certo**.
//
// ═══ ⚠️ A FRONTEIRA DESTA PEÇA ═════════════════════════════════════════════
//
// ⚠️⚠️ **TODA ESCRITA VAI PELO HELPER ÚNICO `escreverNoPainel`** (W11 / COL-04 /
// critério 17). `versao_esperada` é **OPCIONAL no corpo** da action, então
// OMITI-LA faz `AprovacaoDaOfertaService::exigirVersao()` receber `null` e
// **LIBERAR** a escrita — que é literalmente *"sobrescrever em silêncio"*, o que
// o critério 17 proíbe. ⚠️ **Nunca `router.post` direto** (há gate com lista
// CONSTANTE).
// ⚠️ **O painel APROVA (e aprovar ENFILEIRA); ele NÃO publica.** A escrita no
// Mercado Livre é da **Fase 193** (critério 18).
// ⚠️ Os modais são das waves **188-11/12** — sem handler, **nenhum botão**, e
// **nada de provisório** deles aqui. As Fases **189** (*"⤢ Ampliar"*) e **192**
// (*"✦ Gerar novamente com IA"*) **não** são antecipadas.
// ⚠️ **NADA de `localStorage`** nem *"Restaurar dados de exemplo"*: são
// artefatos do protótipo (`:914`).
// ⚠️ Nenhum `String(valor)`, nenhum `hasOwnProperty` solto e nenhum
// `obj[chaveDoServidor]` cru: os três **LANÇAM** com `{toString: null}`, e a
// TELA PRETA voltou SEIS vezes neste projeto — a última **NO CLIQUE**.
// ⚠️ **Flag DENTRO do callback do `.map()`**: o Rollup ELIMINA variável de
// escopo do componente usada dentro de `.map()`, e o bundle sai com
// `ReferenceError` (`feedback_rollup_map_scope_bug`).
// ═══════════════════════════════════════════════════════════════════════════

// ─── Os tipos de destino, ESPELHADOS do servidor ───────────────────────────
//
// ⚠️ São as constantes de `PainelDeRevisaoService` (`DESTINO_FOTO`,
// `DESTINO_MODAL`, `DESTINO_ACAO`, `MODAL_*`, `ACAO_CONFERIR_ALTERACAO`), em
// chave pt-BR nos DOIS lados. ⚠️ **NUNCA um `||` aceitando as duas formas**: é
// assim que um vocabulário vira dois.

/** `{tipo:'foto'}` — abre a imagem resolvida pelo servidor na coluna de fotos. */
export const DESTINO_FOTO = 'foto';

/** `{tipo:'modal'}` — abre um dos três modais. */
export const DESTINO_MODAL = 'modal';

/** `{tipo:'acao'}` — dispara uma escrita direta, sem modal. */
export const DESTINO_ACAO = 'acao';

/** `MODAL_FRETE` — "Informar frete do vendedor" (MOD-03, wave 188-12). */
export const MODAL_FRETE = 'frete';

/** `MODAL_EXCECAO` — "Solicitar exceção autorizada" (MOD-02, wave 188-11). */
export const MODAL_EXCECAO = 'excecao';

/** `MODAL_AJUSTES` — "Solicitar ajustes" (MOD-01, wave 188-11). */
export const MODAL_AJUSTES = 'ajustes';

/**
 * Os TRÊS modais que um destino pode nomear — lista **FECHADA**.
 *
 * ⚠️ `ajustes` está aqui por causa do SEGUNDO destino de `frete_ausente` (ver o
 * docblock da classe): sem ele a pendência de *"Solicitar ajuste"* ficaria sem
 * botão, e a pessoa não teria por onde desbloquear a oferta.
 */
export const MODAIS = [MODAL_FRETE, MODAL_EXCECAO, MODAL_AJUSTES];

/** `ACAO_CONFERIR_ALTERACAO` — ⚠️ confere **SEM alterar o preço** (`:1176`). */
export const ACAO_CONFERIR_ALTERACAO = 'conferir_alteracao';

/**
 * ⚠️ A rota de BLOQ-07, commitada pela wave **188-06** (`1f2dbe44`) e MEDIDA
 * nesta wave em `routes/mlb_anuncios.php:644`-`:646`.
 *
 * ⚠️ Quem limpa o aviso é `AprovacaoDaOfertaService::conferirAlteracao()`: ela
 * **registra que alguém olhou** e **não toca no preço**. Trocá-la por
 * `registrarPrecoSalvo()` mudaria o preço de verdade, **sem desfazer**.
 */
export const ROTA_CONFERIR_ALTERACAO = 'mlb.anuncios.meus.aprovacao.painel.conferir-alteracao';

// ─── A severidade (`:1295`) ────────────────────────────────────────────────

/**
 * ⚠️ O padrão é **âmbar**, e ele ESPELHA o servidor:
 * `VocabularioDoPortao::severidade()` devolve `SEVERIDADE_AMBAR` para toda chave
 * que não está em `VERMELHOS` e não é `fotos_a_conferir` com alerta — inclusive
 * chave desconhecida. ➡️ Uma segunda regra aqui divergiria da dele.
 * ⚠️ **O padrão NUNCA é "sem cor"**: círculo sem tinta ao lado de um texto que
 * diz que a oferta está travada pareceria enfeite.
 */
export const SEVERIDADE_PADRAO = 'ambar';

/** `:1295` — a cor do GLIFO. `#F87171` (vermelho) · `#FBBF24` (âmbar). */
export const TEXTO_DA_SEVERIDADE = {
    vermelho: 'text-pub-danger-txt',
    ambar: 'text-pub-warn-txt',
};

/**
 * `:1295` — o FUNDO do glifo. `rgba(239,68,68,0.14)` · `rgba(245,158,11,0.14)`.
 *
 * ⚠️ As tintas saem do modificador de opacidade do próprio token (`pub-danger` é
 * `#EF4444` = `rgb(239,68,68)`; `pub-warn` é `#F59E0B` = `rgb(245,158,11)`) —
 * mesmo valor computado, com um nome só. TOK-15: nenhum hex no `.jsx`.
 */
export const FUNDO_DA_SEVERIDADE = {
    vermelho: 'bg-pub-danger/[0.14]',
    ambar: 'bg-pub-warn/[0.14]',
};

// ─── As classes, uma casa só (`:699`-`:708`) ───────────────────────────────

/** `:699` — a `<section>`. ⚠️ `rounded-pub-table` é `14px`; não duplicar o valor. */
export const CLASSES_SECAO =
    'flex flex-col gap-[8px] rounded-pub-table border border-pub-line bg-pub-surface p-[14px]';

/** `:700` — o título da seção. */
export const CLASSES_TITULO = 'text-[15px] font-semibold';

/** `:700` — o literal. ⚠️ Não há dono no servidor: é cabeçalho de seção, não rótulo de dado. */
export const TITULO_DA_SECAO = 'Pendências';

/** ⚠️ `:702` — `min-h-[34px]` CONTÉM `h-[34px]`: a sonda ancora no COMEÇO da classe. */
export const CLASSES_ITEM = 'flex min-h-[34px] items-center gap-[10px]';

/** `:703` — o glifo. ⚠️ `grid place-items-center` é OMISSÃO medida da §E. */
export const CLASSES_GLIFO = 'grid h-[18px] w-[18px] flex-none place-items-center '
    + 'rounded-full text-[10.5px] font-[800]';

/** `:704` — o texto, que OCUPA o resto da linha. `#E4E4E7` = `pub-fase1-2`. */
export const CLASSES_TEXTO = 'flex-1 text-[13px] text-pub-fase1-2';

/**
 * `:705` — o botão de correção.
 *
 * ⚠️⚠️ **MEDIDO IDÊNTICO a `:590`** (o "Aprovar N sem alerta" de
 * `ColunaDeFotos.jsx:132`), byte a byte, nas DUAS linhas do protótipo — e a
 * constante fica **PRÓPRIA** de propósito: são elementos de linhas diferentes, e
 * importar a do vizinho faria uma mudança em `:590` reescrever `:705` **em
 * silêncio**. ➡️ Há asserção no gate desta wave provando que as duas continuam
 * IDÊNTICAS: divergência futura fica VISÍVEL em vez de automática.
 * ⚠️ *"O componente que já existe faz parecido"* é justificativa VETADA
 * (FID-02) — e forçar o reuso é a outra metade do mesmo erro.
 */
export const CLASSES_CORRECAO = 'h-[28px] cursor-pointer whitespace-nowrap rounded-pub-control-sm border '
    + 'border-pub-line-strong bg-pub-hover-2 px-[10px] text-[12px] text-pub-txt hover:border-pub-line-off';

/** `:708` — a mensagem verde. */
export const CLASSES_SEM_PENDENCIA = 'flex items-center gap-[10px] text-[13px] text-pub-ok-txt';

/** `:708` — o glifo `✓`. ⚠️ `grid place-items-center` é OMISSÃO medida da §E. */
export const CLASSES_GLIFO_OK = 'grid h-[18px] w-[18px] place-items-center rounded-full '
    + 'bg-pub-ok text-[10.5px] font-[800] text-pub-page';

/** `:708` — o glifo literal. ⚠️ `✓` é U+2713, não a letra `v`. */
export const GLIFO_OK = '✓';

/**
 * `:708` — a frase de "nada bloqueia" DESTA seção, literal byte a byte.
 *
 * ⚠️⚠️ **ELA NÃO É A DO RODAPÉ.** O rodapé diz
 * `VocabularioDoPortao::TUDO_CONFERIDO` (*"Fotos, preço e conteúdo
 * conferidos"*), que vem do SERVIDOR em `rodape.motivo` e é LIDO do módulo de
 * vocabulário por `RodapeDoPainel.jsx`. São **duas frases diferentes, em dois
 * lugares diferentes da mesma tela**, e o protótipo as escreve distintas de
 * propósito: esta explica O QUE foi conferido, a do rodapé é o veredicto.
 * ⚠️ Esta não tem dono no servidor (não há chave de payload para ela), e é por
 * isso que ela mora aqui — e **só aqui**.
 */
export const SEM_PENDENCIA_TEXTO =
    'Nenhuma pendência · fotos aprovadas, composição financeira completa e margem dentro da regra';

/**
 * ⚠️⚠️ **A ESCRITA DESTA SEÇÃO, pelo helper único** (W11 / COL-04 / critério 17).
 *
 * NAMED EXPORT para o gate exercer o CLIQUE direto: `renderToStaticMarkup`
 * **não dispara `onClick`**, e a TELA PRETA da 187-06 foi **no clique**.
 *
 * ⚠️ **Conserto MEDIDO em outras waves desta fase:** desestruturação com default
 * (`{ a = null } = {}`) só cobre `undefined` — com `null` ela **LANÇA**. A
 * guarda é de OBJETO, antes de tudo.
 * ⚠️ E os ids passam por `idDoPainel` (inteiro **POSITIVO**): `inteiroDoPainel`
 * aceita `0`, e `0` é id **plausível** — com ele a escrita sairia para uma rota
 * que não existe, e a pessoa veria a ação acontecer sem nada mudar.
 *
 * @param {object} [pedido]
 * @param {unknown} [pedido.empresaId] `companies.id`
 * @param {unknown} [pedido.ofertaId] `painel.oferta_id`
 * @param {unknown} [pedido.versao] `painel.versao` — a versão que a pessoa VIU
 * @param {unknown} [pedido.onConflito] `(mensagem) => void` — o 422 de COL-04
 * @returns {{conferirAlteracao: ?Function}} `null` = **sem botão**
 */
export function escritaDaPendencia(pedido = null) {
    const alvo = objetoDoPainel(pedido);
    const empresa = alvo === null ? null : idDoPainel(alvo.empresaId);
    const oferta = alvo === null ? null : idDoPainel(alvo.ofertaId);
    const versaoVista = alvo === null ? null : idDoPainel(alvo.versao);
    const onConflito = alvo !== null && typeof alvo.onConflito === 'function' ? alvo.onConflito : null;

    if (empresa === null || oferta === null || versaoVista === null) {
        return { conferirAlteracao: null };
    }

    return {
        conferirAlteracao: () => escreverNoPainel({
            rota: ROTA_CONFERIR_ALTERACAO,
            parametros: { company: empresa, oferta },
            versao: versaoVista,
            onConflito,
        }),
    };
}

/**
 * ⚠️⚠️ **O DESTINO → A AÇÃO.** A função pura que os gates provam.
 *
 * ⚠️ `tipo` fora dos TRÊS conhecidos devolve `null` → **nenhum botão**. Nunca um
 * botão que chama `undefined`: ele pareceria funcionar e não faria nada, que é
 * pior que não existir.
 * ⚠️ `imagem_id` **pode ser `null` de propósito** (`sem_fotos`: não há imagem
 * nenhuma). O destino continua sendo a coluna de fotos, e ela **diz que está
 * vazia** (IMG-12) — nunca painel vazio.
 *
 * @param {unknown} destino `pendencias[*].destino` (plano 188-02)
 * @param {unknown} [acoes] `{focarFoto, abrirModal, conferirAlteracao}`
 * @returns {?Function} a ação, ou `null` = **sem botão**
 */
export function acaoDoDestino(destino, acoes = null) {
    const alvo = objetoDoPainel(destino);
    if (alvo === null) return null;

    const mapa = objetoDoPainel(acoes) ?? {};
    const tipo = textoDoPainel(alvo.tipo);
    if (tipo === null) return null;

    if (tipo === DESTINO_FOTO) {
        // ⚠️ Flag DENTRO do ramo: o Rollup elide variável de escopo do
        // componente lida dentro de `.map()`, e esta função é chamada de lá.
        const focar = typeof mapa.focarFoto === 'function' ? mapa.focarFoto : null;
        if (focar === null) return null;

        const imagem = idDoPainel(alvo.imagem_id);

        return () => focar(imagem);
    }

    if (tipo === DESTINO_MODAL) {
        const abrir = typeof mapa.abrirModal === 'function' ? mapa.abrirModal : null;
        const modal = textoDoPainel(alvo.modal);

        // ⚠️ Lista FECHADA: modal que ninguém mapeou **não abre nada**.
        if (abrir === null || modal === null || !MODAIS.includes(modal)) return null;

        // ⚠️ O TIPO da exceção viaja junto (`loss`/`margin`): sem ele o modal não
        // sabe o que está sendo autorizado. ⚠️ A exceção libera **SÓ** esses dois
        // (critério 11) — nunca fotos, frete, alteração, ajuste ou título.
        const excecao = textoDoPainel(alvo.excecao);

        return () => abrir(modal, excecao);
    }

    if (tipo === DESTINO_ACAO) {
        const acao = textoDoPainel(alvo.acao);
        if (acao !== ACAO_CONFERIR_ALTERACAO) return null;

        const conferir = typeof mapa.conferirAlteracao === 'function' ? mapa.conferirAlteracao : null;

        return conferir === null ? null : () => conferir();
    }

    return null;
}

/**
 * `:702`-`:706` — UM item de pendência.
 *
 * ⚠️⚠️ **NAMED EXPORT, e não é zelo:** o painel inteiro é portado e o
 * renderizador de SERVIDOR do React **não renderiza portal** — medir pelo
 * composto veria HTML vazio e passaria **POR OMISSÃO**. É a solução que a Fase
 * 178 mediu e que a 187-09 e as 188-07/08/09 repetiram.
 *
 * ⚠️ **D7: sem TEXTO o item não é desenhado.** Um círculo colorido sozinho não
 * diz nada a quem não distingue âmbar de vermelho — e cor não é o único sinal.
 *
 * @param {object} props
 * @param {unknown} [props.pendencia] uma entrada de `painel.pendencias`
 * @param {unknown} [props.acoes] `{focarFoto, abrirModal, conferirAlteracao}`
 */
export function ItemDePendencia({ pendencia = null, acoes = null }) {
    const dado = objetoDoPainel(pendencia);
    if (dado === null) return null;

    const texto = textoDoPainel(dado.texto);
    if (texto === null) return null;

    const glifo = textoDoPainel(dado.glifo);
    const severidade = textoDoPainel(dado.severidade) ?? SEVERIDADE_PADRAO;
    const rotulo = textoDoPainel(dado.correcao);
    const aoCorrigir = acaoDoDestino(dado.destino, acoes);

    // ⚠️ As DUAS metades do botão: rótulo (do servidor) **e** ação (do destino).
    // Faltando qualquer uma, **nenhum botão** — D4.
    const temBotao = rotulo !== null && aoCorrigir !== null;

    // ⚠️ `classeDaCorDoPreco` é named export da wave 188-09 e está em produção:
    // ele faz a guarda de tipo ANTES do `mapa[chave]` (que **LANÇA** com
    // `{toString: null}`) e exige o padrão como **PARÂMETRO EXPLÍCITO**. Recopiar
    // a guarda aqui seria a terceira cópia da mesma linha.
    const fundo = classeDaCorDoPreco(FUNDO_DA_SEVERIDADE, severidade, FUNDO_DA_SEVERIDADE[SEVERIDADE_PADRAO]);
    const cor = classeDaCorDoPreco(TEXTO_DA_SEVERIDADE, severidade, TEXTO_DA_SEVERIDADE[SEVERIDADE_PADRAO]);

    return (
        <div className={CLASSES_ITEM}>
            {glifo === null ? null : (
                <span className={cn(CLASSES_GLIFO, fundo, cor)}>{glifo}</span>
            )}
            <span className={CLASSES_TEXTO}>{texto}</span>
            {temBotao ? (
                <button type="button" className={CLASSES_CORRECAO} onClick={() => aoCorrigir()}>
                    {rotulo}
                </button>
            ) : null}
        </div>
    );
}

/**
 * `:708` — a mensagem verde de "nenhuma pendência".
 *
 * ⚠️ NAMED EXPORT pelo mesmo motivo do `ItemDePendencia` (o portal).
 */
export function SemPendencia() {
    return (
        <span className={CLASSES_SEM_PENDENCIA}>
            <span className={CLASSES_GLIFO_OK}>{GLIFO_OK}</span>
            {SEM_PENDENCIA_TEXTO}
        </span>
    );
}

/**
 * A seção de pendências do painel de revisão.
 *
 * @param {object} props
 * @param {unknown} [props.pendencias] `painel.pendencias` (plano 188-02).
 *   ⚠️ **Chave AUSENTE e lista VAZIA são casos DIFERENTES:** sem a lista a
 *   seção **não é desenhada** (não se sabe), com a lista vazia ela diz
 *   **"nenhuma pendência"** (sabe-se que não há). Afirmar "nada bloqueia" sem
 *   dado seria a tela mentindo numa tela em que aprovar JÁ PUBLICA.
 * @param {unknown} [props.live] se a oferta está **ao vivo**. ⚠️ A página o lê de
 *   `rodape.botoes.salvar`, que **É** o `$aoVivo` byte a byte
 *   (`PainelDeRevisaoService.php:647`) — uma definição só de "ao vivo"
 * @param {unknown} [props.onFocarFoto] `(imagemId) => void`, ou `null` — a
 *   coluna de fotos (plano 188-08); a página resolve o índice pelo id
 * @param {unknown} [props.onAbrirModal] `(modal, excecao) => void`, ou `null` —
 *   os modais são das waves **188-11/12**. Sem handler, **nenhum botão**
 * @param {unknown} [props.empresaId] `companies.id`, para a rota de BLOQ-07
 * @param {unknown} [props.ofertaId] `painel.oferta_id`
 * @param {unknown} [props.versao] `painel.versao` — a versão que a pessoa VIU
 * @param {unknown} [props.onConflito] `(mensagem) => void` — o 422 de COL-04
 *   (plano **188-12** é o dono do AVISO na tela)
 */
export default function PendenciasDoPainel({
    pendencias = null,
    live = false,
    onFocarFoto = null,
    onAbrirModal = null,
    empresaId = null,
    ofertaId = null,
    versao = null,
    onConflito = null,
}) {
    // ⚠️⚠️ `Array.isArray` e **não** `listaDoPainel`: aqui a distinção entre
    // chave ausente e lista vazia é LOAD-BEARING (ver o `@param` acima), e
    // `listaDoPainel` apaga exatamente essa diferença devolvendo `[]`.
    if (!Array.isArray(pendencias)) return null;

    const aoVivo = live === true;

    // ⚠️⚠️ **FORA DO `live`, NENHUMA AÇÃO** — `hasFix = !!b.fix && inf.live`
    // (`:1295`). O servidor já zera `correcao` e `destino` fora do `live`, e isto
    // aqui **não é a segunda verdade**: é a recusa de acreditar num payload
    // CONTRADITÓRIO (destino preenchido com a oferta já publicada). É a lição da
    // *fixture que discrimina* da wave 188-09, aplicada ao desenho.
    const acoes = aoVivo
        ? {
            focarFoto: typeof onFocarFoto === 'function' ? onFocarFoto : null,
            abrirModal: typeof onAbrirModal === 'function' ? onAbrirModal : null,
            conferirAlteracao: escritaDaPendencia({ empresaId, ofertaId, versao, onConflito }).conferirAlteracao,
        }
        : null;

    // ⚠️ Flag DENTRO do callback do `.map()`: tudo que ele lê é **parâmetro** ou
    // **nome de MÓDULO**, que o Rollup nunca elide (correção medida na 187-09).
    const itens = pendencias
        .map((pendencia, indice) => (
            <ItemDePendencia key={`pendencia-${indice}`} pendencia={pendencia} acoes={acoes} />
        ));

    // ⚠️ A mensagem verde entra quando **nenhuma pendência de verdade** sobrou —
    // e `pendencias` com um objeto adverso no meio **não** conta como pendência,
    // então a decisão olha os DADOS, não o tamanho do array cru.
    const reais = pendencias.filter((p) => textoDoPainel(objetoDoPainel(p)?.texto) !== null);

    return (
        <section className={CLASSES_SECAO}>
            <span className={CLASSES_TITULO}>{TITULO_DA_SECAO}</span>
            {itens}
            {reais.length === 0 ? <SemPendencia /> : null}
        </section>
    );
}
