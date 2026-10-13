import { useCallback, useState } from 'react';
import { cn } from '@/lib/utils';
import { iniciais, tomDoProduto } from '../identidade/identidade';
import MenuDeFiltro, { LARGURA_PUBLICADA, LARGURA_RESPONSAVEL } from './MenuDeFiltro';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 180, plano 07 — a linha de chips de filtro da tela de Publicações
// (LIST-07): as SEIS famílias do protótipo + "Limpar filtros".
//
// Protótipo: `new_layout_publicador/Publicacoes v2.dc.html`. Linhas MEDIDAS com
// `grep -n` neste arquivo (não copiadas da tabela do plano — e esta rodada achou
// 7 omissões da tabela, todas a favor do protótipo; ver a 180-07-SUMMARY):
//   · a linha de chips       → :102 (`display:flex;align-items:center;gap:8px;flex-wrap:wrap`)
//   · grupo "Fase"           → :103 (`display:flex;align-items:center;height:32px;`
//                                    `border:1px solid #2E2E2E;border-radius:8px;`
//                                    `background:#111111;overflow:hidden`)
//   · rótulo do grupo        → :104 (`padding:0 10px;font-size:12.5px;color:#8A8A90;`
//                                    `border-right:1px solid #262626;height:100%;`
//                                    `display:flex;align-items:center`)
//   · opção do grupo         → :106 (`height:100%;padding:0 11px;border:none;`
//                                    `background:{{o.bg}};font-size:12.5px;`
//                                    `font-weight:{{o.weight}};color:#F4F4F5;cursor:pointer`)
//   · grupo "Tipo"           → :109 / :110 / :112 (IDÊNTICOS aos de "Fase")
//   · chip "Em catálogo"     → :114 (`display:flex;align-items:center;gap:6px;`
//                                    `height:32px;padding:0 11px;border:1px solid …;`
//                                    `border-radius:8px;background:…;font-size:12.5px;`
//                                    `color:#F4F4F5;cursor:pointer`)
//   · ícone do chip          → :116 (`13×13 viewBox "0 0 16 16"`, `fill:none;`
//                                    `stroke:currentColor;stroke-width:1.6;`
//                                    `stroke-linecap:round;stroke-linejoin:round`)
//   · chip "Com vendas"      → :118 / :119
//   · wrapper do chip-menu   → :121 e :139 (`position:relative`)
//   · chip "Responsável"     → :122 · sufixo → :123 · chevron → :124
//   · chip "Publicada"       → :140 · sufixo → :142 · chevron → :143
//   · item de Responsável    → :129 (`gap:10px;width:100%;height:34px;padding:0 8px;`
//                                    `border-radius:7px;background:transparent;`
//                                    `font-size:13px;cursor:pointer;text-align:left`,
//                                    hover `#0A0A0A`)
//   · checkbox do item       → :130 (`15×15;border-radius:4px`) + :605
//                                    (`ON #FACC15/#FACC15` × `OFF #3F3F46/#141414`)
//   · check dentro           → :131 (`10×10 viewBox 16`, `stroke:#0A0A0A;stroke-width:2.4`)
//   · avatar do item         → :133 (`22×22;border-radius:50%;font-size:10px;`
//                                    `font-weight:600`, fundo/texto por `tone()`)
//   · item de Publicada      → :148 (`justify-content:space-between;padding:0 10px`)
//   · check do item ativo    → :150 (`12×12 viewBox 16`, `stroke:#FACC15;stroke-width:2`)
//   · "Limpar filtros"       → :157 (`height:32px;padding:0 10px;border:none;`
//                                    `background:transparent;font-size:12.5px;`
//                                    `color:#A1A1AA;text-decoration:underline;`
//                                    `text-underline-offset:3px;cursor:pointer`)
//   · os literais de "Fase"  → :631 (`Todas · 1 · Simples · 2 · Combo`)
//   · os literais de "Tipo"  → :632 (`Todos · Clássico · Premium`)
//   · os de "Publicada"      → :606 (`Qualquer data · Hoje · Últimos 7 dias · Últimos 30 dias`)
//   · ON/OFF do chip-toggle  → :608 (`on(b) = [b ? 'rgba(250,204,21,0.55)' : '#2A2A2A',`
//                                    `b ? 'rgba(250,204,21,0.12)' : '#111111']`)
//   · ON/OFF da opção segm.  → :535 (`seg()`: `rgba(250,204,21,0.16)` × `transparent`,
//                                    `weight 600` × `400`, e SEM borda)
//   · os dois sufixos        → :635 (`'· ' + S.resp.length` e `'· ' + rótulo`)
//
// ⚠️ ⚠️ SÃO DUAS REGRAS DE "ATIVO", E ELAS NÃO SE UNIFORMIZAM — foi o erro que
// o plano 180-06 mediu e endereçou a este plano:
//   · OPÇÃO SEGMENTADA (Fase/Tipo) → `rgba(250,204,21,0.16)`, SEM borda, e com
//     `font-weight` virando 600 (`seg()`, `:535`);
//   · CHIP-TOGGLE (catálogo/vendas/responsável/publicada) → fundo
//     `rgba(250,204,21,0.12)` E borda `rgba(250,204,21,0.55)`, com o peso da
//     fonte INALTERADO (`on(b)`, `:608` — o `:114` não declara `font-weight`).
// Uniformizar as duas mudaria a cor de 5 controles. A fase já tem precedente de
// três regras de dot no mesmo cockpit que não se uniformizam.
//
// ⚠️ A borda OFF do chip-toggle é `pub-line-off-2` (#2A2A2A), NÃO
// `pub-line-strong` (#2E2E2E): são 4 pontos de diferença e `line-strong` é a
// borda de input/botão secundário, outro uso. Encostar no parecido aqui é
// FID-02 literal — o token existe justamente por isso.
//
// ⚠️ `cursor-pointer` está em TODOS os botões porque o protótipo o declara em
// todos (`:106`, `:114`, `:118`, `:122`, `:129`, `:140`, `:148`, `:157`). A
// tabela FID-01 do plano o omitiu — é a QUINTA omissão de `cursor:pointer` na
// fase, e o protótipo ganha.
//
// ⚠️ O SPAN DO SUFIXO EXISTE SEMPRE, inclusive vazio. Medido: no protótipo
// (`:123`/`:142`) ele é um `<span>` fixo cujo CONTEÚDO é `''` quando não há
// seleção. Como ele é item flex do botão, o `gap:6px` conta com ele: omiti-lo
// no estado sem seleção estreitaria o chip em 6px (dois gaps viram um). A
// tabela dizia só "**vazio** quando não há seleção", o que é ambíguo; o
// medido é "existe e está vazio".
//
// ⚠️ `algumAtivo` vem de FORA e NÃO é recalculado aqui a partir das 6 props.
// Duplicar a regra deixaria as duas livres para divergir — é a mesma disciplina
// do `comDot` do plano 180-06. ⚠️ E o servidor NÃO manda essa prop hoje (medido
// em `MlbAnuncioController.php:1052`-`:1065`: a prop `filtros` tem as 11 chaves
// de filtro e NENHUM `filtros_ativos`); a lacuna está escrita na SUMMARY com a
// derivação recomendada para o plano 180-10.
//
// ⚠️ As contagens NÃO são recalculadas no cliente (D-05): esta linha não mostra
// número nenhum além do `· N` de responsáveis SELECIONADOS, que é cardinalidade
// da própria seleção, não contagem de resultados.
//
// ⚠️ T-180-07-I: o sufixo de "Responsável" é CONTAGEM (`· 3`), nunca nomes. A
// tela não vaza a carteira de ninguém num chip — os nomes aparecem só DENTRO do
// menu que o usuário abriu. E `opcoes` só carrega `{id, nome}`: nada de e-mail,
// telefone nem papel.
//
// ⚠️ IDENT-08: as iniciais e o tom do avatar vêm de `iniciais()`/`tomDoProduto()`
// de `identidade.js`. Nenhuma tela reimplementa — `oklch(` só pode aparecer
// lá, e o GATE-03 prova.
//
// ⚠️ REND-04: TODA flag nasce dentro do helper que o `.map()` chama, e os
// objetos mapeados já chegam RESOLVIDOS pelos construtores puros abaixo. O
// Rollup elimina variável de escopo do componente usada dentro de `.map()`
// (`ReferenceError` só no bundle de produção) — esta linha tem 12+ botões
// mapeados, é o pior caso da fase.
//
// ⚠️ D-11: nenhum `router.*` aqui. O componente devolve o valor cru ao
// callback; quem valida contra lista fechada é o servidor (plano 180-04:
// `fase` em `[1,2]`, `tipo` em `[classico,premium]`, `periodo` em
// `[hoje,7d,30d]`, `responsavel` saneado a inteiros positivos).
//
// ⚠️ TODA classe do Tailwind mora NESTE `.jsx` — o `content` varre `*.jsx` e
// NÃO `*.js`.
// ═══════════════════════════════════════════════════════════════════════════

// ─────────────────────────────────────────────────────────────────────────
// OS LITERAIS (pt-BR, byte a byte do protótipo)
// ─────────────────────────────────────────────────────────────────────────

/** O valor que diz "nenhum filtro de fase" (`:631`, o `all` do protótipo). */
export const FASE_TODAS = 'todas';

/** O valor que diz "nenhum filtro de tipo" (`:632`). */
export const TIPO_TODOS = 'todos';

/** O valor que diz "nenhum filtro de data" (`:606`, o `all` do protótipo). */
export const PERIODO_QUALQUER = 'qualquer';

/**
 * As 3 opções de "Fase" (`:631`).
 *
 * ⚠️ Os valores `'1'`/`'2'` são os do SERVIDOR (`fase` em `[1, 2]`, plano
 * 180-04) e os rótulos são os do protótipo. Nada de `fase = 0` nem da string
 * `'Legado'`: o servidor não manda nenhum dos dois.
 */
export const OPCOES_DE_FASE = [
    { valor: FASE_TODAS, rotulo: 'Todas' },
    { valor: '1', rotulo: '1 · Simples' },
    { valor: '2', rotulo: '2 · Combo' },
];

/** As 3 opções de "Tipo" (`:632`). Os valores são os do servidor. */
export const OPCOES_DE_TIPO = [
    { valor: TIPO_TODOS, rotulo: 'Todos' },
    { valor: 'classico', rotulo: 'Clássico' },
    { valor: 'premium', rotulo: 'Premium' },
];

/** As 4 opções de "Publicada" (`:606`). Os valores são os do servidor. */
export const OPCOES_DE_PERIODO = [
    { valor: PERIODO_QUALQUER, rotulo: 'Qualquer data' },
    { valor: 'hoje', rotulo: 'Hoje' },
    { valor: '7d', rotulo: 'Últimos 7 dias' },
    { valor: '30d', rotulo: 'Últimos 30 dias' },
];

export const ROTULO_FASE = 'Fase';
export const ROTULO_TIPO = 'Tipo';
export const ROTULO_CATALOGO = 'Em catálogo';
export const ROTULO_COM_VENDAS = 'Com vendas';
export const ROTULO_RESPONSAVEL = 'Responsável';
export const ROTULO_PUBLICADA = 'Publicada';
export const LIMPAR_FILTROS = 'Limpar filtros';

/** As duas chaves de menu — lista fechada do estado interno. */
export const MENU_RESPONSAVEL = 'responsavel';
export const MENU_PUBLICADA = 'publicada';

// ─────────────────────────────────────────────────────────────────────────
// AS CLASSES MEDIDAS
// ─────────────────────────────────────────────────────────────────────────

/** A linha de chips (`:102`). */
export const CLASSE_DA_LINHA = 'flex flex-wrap items-center gap-[8px]';

/** O grupo segmentado de "Fase"/"Tipo" (`:103` = `:109`). */
export const CLASSE_DO_GRUPO =
    'flex h-[32px] items-center overflow-hidden rounded-[8px] border border-pub-line-strong bg-pub-surface';

/** O rótulo "Fase"/"Tipo" à esquerda do grupo (`:104` = `:110`). */
export const CLASSE_DO_ROTULO_DO_GRUPO =
    'flex h-full items-center border-r border-pub-line px-[10px] text-[12.5px] text-pub-txt-3';

/** Uma opção do grupo segmentado (`:106` = `:112`), sem o estado. */
export const CLASSE_DA_OPCAO = 'h-full cursor-pointer px-[11px] text-[12.5px] text-pub-txt';

/** Opção segmentada ATIVA (`seg()`, `:535`) — tint 0,16 e peso 600, SEM borda. */
export const CLASSE_OPCAO_ATIVA = 'bg-pub-accent/[0.16] font-semibold';

/** Opção segmentada INATIVA (`:535`). */
export const CLASSE_OPCAO_INATIVA = 'bg-transparent font-normal';

/** O chip-toggle (`:114` = `:118` = `:122` = `:140`), sem o estado. */
export const CLASSE_DO_CHIP =
    'flex h-[32px] cursor-pointer items-center gap-[6px] rounded-[8px] border px-[11px] text-[12.5px] text-pub-txt';

/** Chip-toggle LIGADO (`on(true)`, `:608`) — tint 0,12 E borda 0,55. */
export const CLASSE_CHIP_ON = 'border-pub-accent/[0.55] bg-pub-accent/[0.12]';

/** Chip-toggle DESLIGADO (`on(false)`, `:608`) — #2A2A2A e #111111. */
export const CLASSE_CHIP_OFF = 'border-pub-line-off-2 bg-pub-surface';

/** "Limpar filtros" (`:157`). */
export const CLASSE_DE_LIMPAR =
    'h-[32px] cursor-pointer px-[10px] text-[12.5px] text-pub-neutral-txt underline underline-offset-[3px]';

/** Um item do popover de "Responsável" (`:129`), sem o estado. */
export const CLASSE_ITEM_RESPONSAVEL =
    'flex h-[34px] w-full cursor-pointer items-center gap-[10px] rounded-pub-control-sm bg-transparent'
    + ' px-[8px] text-left text-[13px] text-pub-txt hover:bg-pub-page';

/** Um item do popover de "Publicada" (`:148`), sem o estado. */
export const CLASSE_ITEM_PERIODO =
    'flex h-[34px] w-full cursor-pointer items-center justify-between rounded-pub-control-sm'
    + ' px-[10px] text-left text-[13px] text-pub-txt hover:bg-pub-page';

// ─────────────────────────────────────────────────────────────────────────
// LEITURA SEGURA DAS PROPS
// ─────────────────────────────────────────────────────────────────────────

/** O objeto da família, ou `{}` — nunca lança, nunca vira "[object Object]". */
function familia(valor) {
    return valor !== null && typeof valor === 'object' && !Array.isArray(valor) ? valor : {};
}

/** A função do callback, ou `null`. */
function handler(valor) {
    return typeof valor === 'function' ? valor : null;
}

/**
 * O valor da família, resolvido contra a LISTA FECHADA das opções.
 *
 * ⚠️ Aceita o inteiro além da string de propósito: o servidor manda
 * `fase` como `?int` (`MlbAnuncioController.php:596`) e `periodo`/`tipo` como
 * `?string` com `null` para "sem filtro". Sem esta normalização, `fase: 1`
 * (int) nunca casaria com a opção `'1'` e o chip ficaria MUDO com o filtro
 * aplicado — é a armadilha do `comDot` em snake_case, ao contrário.
 *
 * Valor fora da lista (inclusive `null`, objeto e `NaN`) cai no "sem filtro",
 * SEM lançar.
 */
function valorDaLista(bruto, opcoes, semFiltro) {
    const candidato =
        typeof bruto === 'string'
            ? bruto.trim()
            : (Number.isInteger(bruto) ? String(bruto) : null);

    if (candidato === null) return semFiltro;
    return opcoes.some((opcao) => opcao.valor === candidato) ? candidato : semFiltro;
}

/** `true` só com o booleano `true` — a string `'false'` NÃO liga nada. */
function ligado(bruto) {
    return bruto === true;
}

/**
 * Os ids selecionados de responsável, saneados a inteiros positivos.
 *
 * ⚠️ A string `'3'` NÃO conta como o id `3`: o servidor manda
 * `array<int,int>` (plano 180-04) e aceitar a string aqui faria a contagem do
 * sufixo divergir do que a lista está realmente filtrando.
 */
export function idsSelecionados(bruto) {
    if (!Array.isArray(bruto)) return [];
    return bruto.filter((id) => Number.isInteger(id) && id > 0);
}

// ─────────────────────────────────────────────────────────────────────────
// OS CONSTRUTORES PUROS — as flags nascem AQUI, não no `.map()`
// ─────────────────────────────────────────────────────────────────────────

/**
 * As opções de um grupo segmentado, já com `ativa` e `onClick` resolvidos.
 *
 * Puro e exportado para o gate medi-lo sem render.
 */
export function montarSegmentado(opcoes, valorAtual, onTrocar) {
    const trocar = handler(onTrocar);

    return opcoes.map((opcao) => ({
        valor: opcao.valor,
        rotulo: opcao.rotulo,
        ativa: opcao.valor === valorAtual,
        onClick: trocar === null ? null : () => trocar(opcao.valor),
    }));
}

/**
 * As opções do popover de "Responsável", já resolvidas.
 *
 * ⚠️ Opção sem `id` inteiro positivo ou sem `nome` legível NÃO entra: a
 * primeira seria um botão sem destino e a segunda um botão sem nome (EXMP-01).
 * `nome` objeto cairia como "[object Object]" — a tela preta de 07/10/2026.
 */
export function montarResponsaveis(opcoes, selecionados, onAlternar) {
    if (!Array.isArray(opcoes)) return [];

    const ligados = idsSelecionados(selecionados);
    const alternar = handler(onAlternar);
    const vistos = new Set();
    const prontas = [];

    for (const opcao of opcoes) {
        if (opcao === null || typeof opcao !== 'object' || Array.isArray(opcao)) continue;

        const id = opcao.id;
        if (!Number.isInteger(id) || id <= 0 || vistos.has(id)) continue;

        const nome = typeof opcao.nome === 'string' ? opcao.nome.trim() : '';
        if (nome === '') continue;

        vistos.add(id);
        prontas.push({
            id,
            nome,
            // ⚠️ IDENT-08: as letras saem de `iniciais()`, nunca de um campo
            // `iniciais` do servidor — duas fontes para as mesmas 2 letras é
            // o que o gate de identidade existe para impedir.
            letras: iniciais(nome),
            tom: tomDoProduto(id),
            marcada: ligados.includes(id),
            onClick: alternar === null ? null : () => alternar(id),
        });
    }

    return prontas;
}

/** As 4 opções de "Publicada", já com `ativa` e `onClick`. */
export function montarPeriodos(valorAtual, onTrocar) {
    const trocar = handler(onTrocar);

    return OPCOES_DE_PERIODO.map((opcao) => ({
        valor: opcao.valor,
        rotulo: opcao.rotulo,
        ativa: opcao.valor === valorAtual,
        onClick: trocar === null ? null : () => trocar(opcao.valor),
    }));
}

/**
 * O sufixo do chip de "Responsável" (`:635`).
 *
 * ⚠️ CONTAGEM, nunca nomes (T-180-07-I). Sem seleção devolve `''` — e o span
 * continua existindo, ver o cabeçalho.
 */
export function sufixoDeResponsavel(selecionados) {
    const ligados = idsSelecionados(selecionados);
    return ligados.length === 0 ? '' : `· ${ligados.length}`;
}

/** O sufixo do chip de "Publicada" (`:635`) — `· {rótulo}`, ou `''`. */
export function sufixoDePeriodo(valorAtual) {
    if (valorAtual === PERIODO_QUALQUER) return '';
    const opcao = OPCOES_DE_PERIODO.find((item) => item.valor === valorAtual);
    return opcao === undefined ? '' : `· ${opcao.rotulo}`;
}

// ─────────────────────────────────────────────────────────────────────────
// OS ÍCONES — SVG inline com o viewBox DO PROTÓTIPO
//
// ⚠️ `viewBox="0 0 16 16"` e não o `0 0 24 24` do Lucide: trocar exigiria
// `strokeWidth` × 1,5 e um desenho diferente. Foi a escolha das Fases 177-179
// nos mesmos casos.
// ─────────────────────────────────────────────────────────────────────────

/** A grade 2×2 de "Em catálogo" (`:116`). */
function IconeDeCatalogo() {
    return (
        <svg
            aria-hidden="true"
            width="13"
            height="13"
            viewBox="0 0 16 16"
            strokeWidth="1.6"
            strokeLinecap="round"
            strokeLinejoin="round"
            className="fill-none stroke-current"
        >
            <path d="M3 3h4v4H3zM9 3h4v4H9zM3 9h4v4H3zM9 9h4v4H9z" />
        </svg>
    );
}

/** A curva ascendente de "Com vendas" (`:119`). */
function IconeDeVendas() {
    return (
        <svg
            aria-hidden="true"
            width="13"
            height="13"
            viewBox="0 0 16 16"
            strokeWidth="1.6"
            strokeLinecap="round"
            strokeLinejoin="round"
            className="fill-none stroke-current"
        >
            <path d="M2.5 12.5l4-4 2.5 2.5 4.5-5.5" />
            <path d="M10 5.5h3.5V9" />
        </svg>
    );
}

/** O chevron dos dois chips com menu (`:124` = `:143`). */
function ChevronDoChip() {
    return (
        <svg
            aria-hidden="true"
            width="12"
            height="12"
            viewBox="0 0 16 16"
            strokeWidth="1.7"
            strokeLinecap="round"
            strokeLinejoin="round"
            className="fill-none stroke-pub-txt-3"
        >
            <path d="M4 6l4 4 4-4" />
        </svg>
    );
}

/** O check DENTRO do checkbox de "Responsável" (`:131`) — escuro sobre amarelo. */
function CheckDoCheckbox() {
    return (
        <svg
            aria-hidden="true"
            width="10"
            height="10"
            viewBox="0 0 16 16"
            strokeWidth="2.4"
            strokeLinecap="round"
            strokeLinejoin="round"
            className="fill-none stroke-pub-page"
        >
            <path d="M3.5 8.5l3 3 6-7" />
        </svg>
    );
}

/** O check amarelo do item ativo de "Publicada" (`:150`). */
function CheckDoPeriodo() {
    return (
        <svg
            aria-hidden="true"
            width="12"
            height="12"
            viewBox="0 0 16 16"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            className="fill-none stroke-pub-accent"
        >
            <path d="M3.5 8.5l3 3 6-7" />
        </svg>
    );
}

// ─────────────────────────────────────────────────────────────────────────
// OS ITENS DOS POPOVERS — em escopo de MÓDULO e EXPORTADOS
//
// ⚠️ POR QUE SAÍRAM DE DENTRO DO COMPONENTE: o menu abre por estado LOCAL
// (`menuAberto`), e `renderToStaticMarkup` renderiza o estado INICIAL — logo os
// itens NUNCA aparecem no HTML de `ChipsDeFiltro` e o gate mediria NADA da
// geometria deles. É a mesma limitação do portal, e a saída é a que a Fase 178
// já usou: exportar as peças internas como named exports para poder conferi-las.
//
// ⚠️ Continuam sendo helpers que o `.map()` CHAMA (e não JSX inline no
// callback): todas as flags nascem aqui dentro, que é a proteção contra a
// eliminação de variável de escopo pelo Rollup.
// ─────────────────────────────────────────────────────────────────────────

/** UM item do popover de "Responsável" (`:129`-`:134`). */
export function itemDeResponsavel(item) {
    const estaMarcada = item.marcada === true;

    return (
        <button
            key={item.id}
            type="button"
            role="menuitemcheckbox"
            aria-checked={estaMarcada}
            onClick={item.onClick ?? undefined}
            className={CLASSE_ITEM_RESPONSAVEL}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'grid h-[15px] w-[15px] place-items-center rounded-pub-badge border',
                    estaMarcada ? 'border-pub-accent bg-pub-accent' : 'border-pub-line-off bg-pub-hover',
                )}
            >
                {estaMarcada && <CheckDoCheckbox />}
            </span>
            <span
                aria-hidden="true"
                style={{ background: item.tom.fundo, color: item.tom.texto }}
                className="grid h-[22px] w-[22px] place-items-center rounded-full text-[10px] font-semibold"
            >
                {item.letras}
            </span>
            {item.nome}
        </button>
    );
}

/** UM item do popover de "Publicada" (`:148`-`:150`). */
export function itemDePeriodo(item) {
    const estaAtiva = item.ativa === true;

    return (
        <button
            key={item.valor}
            type="button"
            role="menuitemradio"
            aria-checked={estaAtiva}
            onClick={item.onClick ?? undefined}
            className={cn(CLASSE_ITEM_PERIODO, estaAtiva ? 'bg-pub-page' : 'bg-transparent')}
        >
            {item.rotulo}
            {estaAtiva && <CheckDoPeriodo />}
        </button>
    );
}

/**
 * A linha de chips de filtro (LIST-07).
 *
 * @param {object} props
 * @param {unknown} [props.fase] `{valor, onTrocar}` — `valor` em `1|2|null` ou `'todas'`
 * @param {unknown} [props.tipo] `{valor, onTrocar}` — `'classico'|'premium'|null`
 * @param {unknown} [props.catalogo] `{ligado, onAlternar}`
 * @param {unknown} [props.comVendas] `{ligado, onAlternar}` (o `com_venda` do servidor)
 * @param {unknown} [props.responsavel] `{selecionados, opcoes: [{id, nome}], onAlternar}`
 * @param {unknown} [props.periodo] `{valor, onTrocar}` — `'hoje'|'7d'|'30d'|null`
 * @param {unknown} [props.algumAtivo] só `true` desenha "Limpar filtros"
 * @param {unknown} [props.onLimparFiltros] handler de "Limpar filtros"
 */
export default function ChipsDeFiltro({
    fase = null,
    tipo = null,
    catalogo = null,
    comVendas = null,
    responsavel = null,
    periodo = null,
    algumAtivo = false,
    onLimparFiltros,
}) {
    // ⚠️ Estado LOCAL: qual dos dois menus está aberto. Não é filtro, é
    // interação — não vai para a URL e o servidor não precisa saber.
    const [menuAberto, setMenuAberto] = useState(null);

    const daFase = familia(fase);
    const doTipo = familia(tipo);
    const doCatalogo = familia(catalogo);
    const dasVendas = familia(comVendas);
    const doResponsavel = familia(responsavel);
    const doPeriodo = familia(periodo);

    const valorDaFase = valorDaLista(daFase.valor, OPCOES_DE_FASE, FASE_TODAS);
    const valorDoTipo = valorDaLista(doTipo.valor, OPCOES_DE_TIPO, TIPO_TODOS);
    const valorDoPeriodo = valorDaLista(doPeriodo.valor, OPCOES_DE_PERIODO, PERIODO_QUALQUER);

    const opcoesDeFase = montarSegmentado(OPCOES_DE_FASE, valorDaFase, daFase.onTrocar);
    const opcoesDeTipo = montarSegmentado(OPCOES_DE_TIPO, valorDoTipo, doTipo.onTrocar);
    const opcoesDePeriodo = montarPeriodos(valorDoPeriodo, doPeriodo.onTrocar);
    const opcoesDeResponsavel = montarResponsaveis(
        doResponsavel.opcoes,
        doResponsavel.selecionados,
        doResponsavel.onAlternar,
    );

    const limpar = handler(onLimparFiltros);

    // ⚠️ `useCallback` com lista vazia porque `setMenuAberto` é estável: sem
    // isto o `onFechar` nasceria novo a cada render e o `useEffect` do
    // `MenuDeFiltro` removeria e recolocaria o listener de `Esc` toda vez.
    const fecharMenu = useCallback(() => setMenuAberto(null), []);

    /** UMA opção do grupo segmentado. Todas as flags nascem aqui (REND-04). */
    function opcaoSegmentada(item) {
        const estaAtiva = item.ativa === true;

        return (
            <button
                key={item.valor}
                type="button"
                aria-pressed={estaAtiva}
                onClick={item.onClick ?? undefined}
                className={cn(CLASSE_DA_OPCAO, estaAtiva ? CLASSE_OPCAO_ATIVA : CLASSE_OPCAO_INATIVA)}
            >
                {item.rotulo}
            </button>
        );
    }

    /** UM grupo segmentado inteiro (rótulo + opções). */
    function grupoSegmentado(rotulo, opcoes) {
        return (
            <div className={CLASSE_DO_GRUPO}>
                <span className={CLASSE_DO_ROTULO_DO_GRUPO}>{rotulo}</span>
                {opcoes.map((item) => opcaoSegmentada(item))}
            </div>
        );
    }

    /**
     * UM chip-toggle. Os quatro saem daqui para nunca divergirem de aparência.
     *
     * ⚠️ `sufixo` e `comMenu` chegam resolvidos; `ligado` é o estado do chip,
     * não o `algumAtivo` da linha.
     */
    function chipToggle({ chave, rotulo, estaLigado, icone, sufixo, comMenu, onClick }) {
        const temMenu = comMenu === true;
        const abreEste = temMenu && menuAberto === chave;
        const clicar = handler(onClick);

        return (
            <button
                type="button"
                aria-pressed={estaLigado}
                aria-expanded={temMenu ? abreEste : undefined}
                aria-haspopup={temMenu ? 'true' : undefined}
                onClick={clicar ?? undefined}
                className={cn(CLASSE_DO_CHIP, estaLigado ? CLASSE_CHIP_ON : CLASSE_CHIP_OFF)}
            >
                {icone}
                {rotulo}
                {/* ⚠️ O span existe SEMPRE, inclusive vazio: ele é item flex e o
                    `gap:6px` do protótipo conta com ele (`:123`/`:142`). */}
                {temMenu && <span className="text-pub-neutral-txt">{sufixo}</span>}
                {temMenu && <ChevronDoChip />}
            </button>
        );
    }

    return (
        <div className={CLASSE_DA_LINHA}>
            {grupoSegmentado(ROTULO_FASE, opcoesDeFase)}
            {grupoSegmentado(ROTULO_TIPO, opcoesDeTipo)}

            {chipToggle({
                chave: 'catalogo',
                rotulo: ROTULO_CATALOGO,
                estaLigado: ligado(doCatalogo.ligado),
                icone: <IconeDeCatalogo />,
                sufixo: '',
                comMenu: false,
                onClick: doCatalogo.onAlternar,
            })}

            {chipToggle({
                chave: 'comVendas',
                rotulo: ROTULO_COM_VENDAS,
                estaLigado: ligado(dasVendas.ligado),
                icone: <IconeDeVendas />,
                sufixo: '',
                comMenu: false,
                onClick: dasVendas.onAlternar,
            })}

            {/* ⚠️ EXMP-01: sem opção nenhuma o chip "Responsável" NÃO é
                desenhado. Um popover vazio é um botão sem destino — o usuário
                abre e não há ninguém para escolher. */}
            {opcoesDeResponsavel.length > 0 && (
                <div className="relative">
                    {chipToggle({
                        chave: MENU_RESPONSAVEL,
                        rotulo: ROTULO_RESPONSAVEL,
                        estaLigado: idsSelecionados(doResponsavel.selecionados).length > 0,
                        icone: null,
                        sufixo: sufixoDeResponsavel(doResponsavel.selecionados),
                        comMenu: true,
                        onClick: () =>
                            setMenuAberto((atual) => (atual === MENU_RESPONSAVEL ? null : MENU_RESPONSAVEL)),
                    })}
                    <MenuDeFiltro
                        aberto={menuAberto === MENU_RESPONSAVEL}
                        largura={LARGURA_RESPONSAVEL}
                        onFechar={fecharMenu}
                    >
                        {opcoesDeResponsavel.map((item) => itemDeResponsavel(item))}
                    </MenuDeFiltro>
                </div>
            )}

            <div className="relative">
                {chipToggle({
                    chave: MENU_PUBLICADA,
                    rotulo: ROTULO_PUBLICADA,
                    estaLigado: valorDoPeriodo !== PERIODO_QUALQUER,
                    icone: null,
                    sufixo: sufixoDePeriodo(valorDoPeriodo),
                    comMenu: true,
                    onClick: () =>
                        setMenuAberto((atual) => (atual === MENU_PUBLICADA ? null : MENU_PUBLICADA)),
                })}
                <MenuDeFiltro
                    aberto={menuAberto === MENU_PUBLICADA}
                    largura={LARGURA_PUBLICADA}
                    onFechar={fecharMenu}
                >
                    {opcoesDePeriodo.map((item) => itemDePeriodo(item))}
                </MenuDeFiltro>
            </div>

            {/* ⚠️ "Limpar filtros" só nasce com `algumAtivo === true` (`:156`) E
                com handler: sem destino ele não é desenhado, nem desabilitado
                (desabilitado afirmaria "existe, só está ocupado"). */}
            {algumAtivo === true && limpar !== null && (
                <button type="button" onClick={limpar} className={CLASSE_DE_LIMPAR}>
                    {LIMPAR_FILTROS}
                </button>
            )}
        </div>
    );
}
