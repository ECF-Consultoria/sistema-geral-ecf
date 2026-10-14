import { Fragment } from 'react';
import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 180, plano 06 — o trilho segmentado de status da tela de Publicações
// (LIST-05/LIST-25).
//
// Protótipo: `new_layout_publicador/Publicacoes v2.dc.html`. Linhas MEDIDAS com
// `grep -n` neste arquivo:
//   · trilho do segmentado → :83 (`gap:2px;padding:3px;background:#161616;border-radius:10px`)
//   · botão de aba         → :85 (`display:flex;align-items:center;gap:7px;height:30px;`
//                                 `padding:0 12px;border:none;border-radius:7px;`
//                                 `font-size:13px;font-weight:500;cursor:pointer`)
//   · aba ativa / inativa  → :532 (`#262626` + `inset 0 0 0 1px #333333` + `#F4F4F5`
//                                  × `transparent` + `none` + `#A1A1AA`)
//   · dot de "Com erro"    → :86 (`6×6;border-radius:50%`) + `:532` (`#EF4444`,
//                                 `hasDot: k === 'error' && counts.error > 0`)
//   · contagem na aba      → :88 (`font-size:12px;color:#8A8A90;font-variant-numeric:tabular-nums`)
//   · os 5 rótulos         → :531 (`Todas · Ativas · Pausadas · Com erro · <o 5º, abaixo>`)
//
// ⚠️ Fase 186, plano 01 (ABA-03 / VOC-01) — o 5º rótulo mudou. MEDIDO por
// `diff` entre as DUAS versões do protótipo (elas coexistem no disco e têm
// conteúdo diferente — 656 × 659 linhas):
//   `new_layout_publicador/Publicacoes v2.dc.html`:531      → 'Em revisão'
//   `design_handoff_publicador/Publicacoes v2.dc.html`:534  → 'Em análise pelo ML'
// As linhas MEDIDAS acima (:83/:85/:86/:88/:531/:532) são as da versão
// `new_layout_`, a da Fase 180; na versão nova elas estão +3 por causa dos dois
// hunks do header (que são da Fase 187, não desta).
//
// ⚠️⚠️ **O RÓTULO NÃO MORA NESTE ARQUIVO** — nem o antigo morava. O trilho só
// renderiza `abas[].rotulo`, que vem do servidor
// (`MlbAnuncioController::meus()`, onde o rename de fato aconteceu). Este
// comentário é a razão pela qual o briefing da fase apontou este arquivo como o
// lugar do rename: o `grep` casou com a PROSA, não com o código. É a armadilha
// do próprio learnings (gate/busca que lê comentário), e fica registrada aqui.
//
// ⚠️ `cursor-pointer` ESTÁ aqui porque o protótipo o declara (`:85`) — a tabela
// FID-01 do plano o omitiu. O protótipo ganha.
//
// ⚠️ A ORDEM e os RÓTULOS vêm do servidor (`MlbAnuncioController::meus()`,
// prop `abas`), nunca de uma lista local: a tela não decide quais status
// existem. ⚠️ `comDot` chega em camelCase e JÁ EMBUTE o "> 0" — não é
// recalculado aqui de propósito. Duplicar a regra deixaria as duas livres para
// divergir, e o dot é a única coisa vermelha da tela.
//
// ⚠️ LIST-25 — "Rascunhos" é a 6ª opção DESTE trilho, não um segmentado à
// parte. O dado e a query continuam os de hoje (`sub=publicados|rascunhos`,
// `subTotais`, D-14): o que mudou é o LUGAR do atalho. Ela ganha um separador
// antes porque troca a FONTE da lista, enquanto as cinco de status só a
// filtram — e isso é um DESVIO DECLARADO de fidelidade (o protótipo não tem
// essa opção; ver a 180-06-SUMMARY).
//
// ⚠️ `filtros.status` pode valer `acionaveis` (o DEFAULT da tela) ou
// `encerrados`, e NENHUM dos dois tem aba no layout novo — mas os dois são
// destinos vivos da Visão Geral (Fase 179) e têm contagem em `contagens`.
// Nesses dois casos o trilho ganha uma opção À FRENTE de "Todas", com o rótulo
// que a tela de HOJE já usa (`MeusAnuncios.jsx:47`/`:51`: "Acionáveis" e
// "Encerrados") e o número de `contagens`. Não é aba inventada: é a chave que o
// servidor está aplicando, com o nome que ele já tem na tela. A alternativa —
// trilho sem nenhuma opção marcada — diria que a lista não está filtrada quando
// ela está.
//
// ⚠️ Evitar jargão é regra permanente: `under_review`, `gold_pro`, `active` e
// afins NUNCA aparecem. Os rótulos são os do servidor, em pt-BR.
//
// ⚠️ REND-04: os 6 botões saem do MESMO helper interno (`opcao()`), declarado
// DENTRO do componente, e TODAS as flags nascem lá. Variável de escopo do
// componente lida só dentro de um `.map()` já foi eliminada pelo Rollup no
// bundle de produção deste projeto (ReferenceError em tela) — é a mesma forma
// de `AbasDoHeader.pilula()` e `AbasDaConta.botaoAba()`.
//
// ⚠️ D-11: nenhum `router.*` aqui. O componente só devolve a `chave` ao
// `onTrocar`; quem valida contra lista fechada é o servidor (`escopo()`).
//
// ⚠️ TODA classe do Tailwind mora NESTE `.jsx` (o `content` não varre `*.js`).
// ═══════════════════════════════════════════════════════════════════════════

/**
 * A chave que troca a FONTE da lista (e não o filtro) — D-14.
 *
 * ⚠️ Literal do servidor, não rótulo: `sub=rascunhos` é o que vai para a URL.
 */
export const CHAVE_DA_FONTE = 'rascunhos';

/** O nome acessível da 6ª opção: ela não filtra, ela troca a fonte. */
export const ARIA_DA_FONTE = 'Rascunhos — troca a lista: mostra o que ainda não foi publicado';

/**
 * Os dois valores de `status` que o servidor aceita e que o layout novo NÃO
 * desenha como aba, com o rótulo que a tela de HOJE já usa.
 *
 * ⚠️ NÃO são invenção da tela: `acionaveis` é o default de `meus()` e os dois
 * são destinos que `CockpitDaContaService::resumoDePublicacoes()` monta. Os
 * rótulos saem de `STATUS_OPCOES` em `MeusAnuncios.jsx:47`/`:51`, em pt-BR —
 * `acionaveis`/`encerrados` nunca aparecem crus na tela.
 */
export const ROTULOS_FORA_DO_TRILHO = {
    acionaveis: 'Acionáveis',
    encerrados: 'Encerrados',
};

/** A classe do trilho (`:83`). Exportada porque o toggle de visão usa o MESMO. */
export const CLASSE_DO_TRILHO = 'flex items-center gap-[2px] rounded-pub-sub bg-pub-hover-2 p-[3px]';

/** Opção ATIVA do segmentado (`:532`/`:536`) — a mesma nos dois trilhos. */
export const CLASSE_ATIVA = 'bg-pub-seg shadow-pub-seg-ativo text-pub-txt';

/** Opção INATIVA do segmentado (`:532`/`:536`). */
export const CLASSE_INATIVA = 'bg-transparent text-pub-neutral-txt';

/** String útil ou `null` — nunca `String(x)`, que LANÇA com `{toString:null}`. */
function soTexto(valor) {
    if (typeof valor !== 'string') return null;
    const limpo = valor.trim();
    return limpo === '' ? null : limpo;
}

/**
 * O número medido, ou `null`.
 *
 * ⚠️ `null` é "não medido" e `0` é "medido e é zero" — a distinção é regra
 * permanente do projeto, e por isso `0` PASSA. `NaN`/`Infinity`/`'5'`/`2.5`
 * reprovam: `typeof NaN` é `'number'` e escreveria "NaN" na tela.
 */
function numeroMedido(valor) {
    return Number.isInteger(valor) && valor >= 0 ? valor : null;
}

/**
 * As opções do trilho, na ORDEM do servidor, já com as flags resolvidas.
 *
 * Pura e exportada para o gate poder medi-la sem render.
 *
 * ⚠️ `separador` nasce aqui (e não no `.map()` do JSX) lendo só o vizinho
 * anterior da própria lista: o separador marca a troca de NATUREZA da opção
 * (fora do trilho → status → fonte da lista).
 *
 * @param {unknown} abas `[{chave, rotulo, total, comDot}]` do servidor
 * @param {unknown} ativa o `filtros.status` em vigor
 * @param {unknown} contagens os 8 eixos do servidor — só para o número da opção fora do trilho
 * @returns {Array<object>}
 */
export function montarOpcoes(abas, ativa, contagens) {
    const chaveAtiva = soTexto(ativa);
    const recebidas = Array.isArray(abas) ? abas : [];
    const vistas = new Set();
    const opcoes = [];

    for (const aba of recebidas) {
        if (aba === null || typeof aba !== 'object' || Array.isArray(aba)) continue;

        const chave = soTexto(aba.chave);
        const rotulo = soTexto(aba.rotulo);
        // ⚠️ Aba sem chave não tem destino e aba sem rótulo legível não tem
        // nome: nenhuma das duas é desenhada. Rótulo objeto cairia como
        // "[object Object]" — a tela preta de 07/10/2026.
        if (chave === null || rotulo === null || vistas.has(chave)) continue;

        vistas.add(chave);
        opcoes.push({
            chave,
            rotulo,
            total: numeroMedido(aba.total),
            comDot: aba.comDot === true,
            natureza: chave === CHAVE_DA_FONTE ? 'fonte' : 'status',
        });
    }

    const foraDoTrilho =
        chaveAtiva !== null
        && !vistas.has(chaveAtiva)
        && Object.prototype.hasOwnProperty.call(ROTULOS_FORA_DO_TRILHO, chaveAtiva);

    if (foraDoTrilho) {
        const eixos =
            contagens !== null && typeof contagens === 'object' && !Array.isArray(contagens)
                ? contagens
                : {};
        opcoes.unshift({
            chave: chaveAtiva,
            rotulo: ROTULOS_FORA_DO_TRILHO[chaveAtiva],
            total: numeroMedido(eixos[chaveAtiva]),
            comDot: false,
            natureza: 'fora',
        });
    }

    return opcoes.map((opcao, indice, todas) => ({
        ...opcao,
        ativa: opcao.chave === chaveAtiva,
        separador: indice > 0 && todas[indice - 1].natureza !== opcao.natureza,
    }));
}

/**
 * O trilho de abas de status, com "Rascunhos" integrada (LIST-05/LIST-25).
 *
 * @param {object} props
 * @param {unknown} [props.abas] `[{chave, rotulo, total, comDot}]`, na ordem do servidor
 * @param {unknown} [props.ativa] o `filtros.status` em vigor
 * @param {unknown} [props.onTrocar] recebe a `chave` da opção clicada
 * @param {unknown} [props.contagens] os eixos do servidor — só para a opção fora do trilho
 */
export default function AbasDeStatus({ abas = null, ativa = null, onTrocar, contagens = null }) {
    const opcoes = montarOpcoes(abas, ativa, contagens);

    /**
     * UM botão do trilho. Chamado dentro do `.map()`; todas as flags nascem
     * aqui dentro (REND-04).
     */
    function opcao(item) {
        const ehAtiva = item.ativa === true;
        const ehFonte = item.natureza === 'fonte';
        const temDot = item.comDot === true;
        const temTotal = item.total !== null;

        const botao = (
            <button
                key={item.chave}
                type="button"
                role="tab"
                aria-selected={ehAtiva}
                aria-label={ehFonte ? ARIA_DA_FONTE : undefined}
                onClick={typeof onTrocar === 'function' ? () => onTrocar(item.chave) : undefined}
                className={cn(
                    'flex h-[30px] cursor-pointer items-center gap-[7px] rounded-pub-control-sm px-[12px] text-[13px] font-medium',
                    ehAtiva ? CLASSE_ATIVA : CLASSE_INATIVA,
                )}
            >
                {temDot && (
                    <span aria-hidden="true" className="h-[6px] w-[6px] rounded-full bg-pub-danger" />
                )}
                {item.rotulo}
                {temTotal && (
                    <span className="text-[12px] tabular-nums text-pub-txt-3">{item.total}</span>
                )}
            </button>
        );

        if (!item.separador) return botao;

        // ⚠️ `Fragment` com key e NÃO um `<span>` embrulhando: envelope extra
        // viraria o item flex do trilho e mudaria o `gap:2px` do protótipo.
        return (
            <Fragment key={item.chave}>
                {/* ⚠️ DESVIO DECLARADO: o protótipo não tem divisória dentro do
                    trilho, porque não tem nem a opção "Rascunhos". A divisória
                    existe porque essa opção troca a FONTE da lista e as de
                    status só a filtram — e o único traço vertical que o
                    protótipo usa dentro de um controle é `1px solid #262626`
                    (`:98`), que é `pub-line`. */}
                <span aria-hidden="true" className="mx-[2px] h-[18px] w-[1px] bg-pub-line" />
                {botao}
            </Fragment>
        );
    }

    // ⚠️ Trilho vazio NÃO nasce: um segmentado sem opção nenhuma seria moldura
    // afirmando um filtro que ninguém mandou (EXMP-01).
    if (opcoes.length === 0) return null;

    return (
        <div role="tablist" className={CLASSE_DO_TRILHO}>
            {opcoes.map((item) => opcao(item))}
        </div>
    );
}
