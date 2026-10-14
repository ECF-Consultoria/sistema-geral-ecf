import { ignoraAtalho } from '../header/atalhosDoHeader';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 180, plano 11 — a MÁQUINA DE TECLADO da tela de Publicações (LIST-18).
//
// Protótipo: `new_layout_publicador/Publicacoes v2.dc.html`. Medido pela
// SEMÂNTICA (nunca por número de linha, que envelhece): a função anônima
// instalada em `componentDidMount`, o método `move(dir)` e o handler
// `onSearchKey` do objeto de props da busca.
//
// ⚠️⚠️ POR QUE AS TRÊS FUNÇÕES SÃO PURAS, E POR QUE ISSO NÃO É LUXO:
// teclado, foco e `preventDefault` NÃO SÃO OBSERVÁVEIS em
// `renderToStaticMarkup` — o render de servidor não dispara efeito de React e
// não tem `window`. Um gate que lesse só a fonte da página provaria que o
// `addEventListener` existe, e NADA sobre o que ele faz. Com a decisão de cada
// tecla numa função pura, a tabela de comportamento inteira ganha caso de teste
// de verdade. Foi o que salvou o plano 180-10, quando a mutação que invertia a
// regra dos grupos SOBREVIVEU por não ser observável em render nenhum.
//
// ⚠️⚠️ ZERO CLASSE DO TAILWIND, ZERO `className`, ZERO JSX NESTE ARQUIVO.
// O `content` do `tailwind.config.js` varre `./resources/js/**/*.jsx` e **não**
// `*.js`: classe escrita aqui não é varrida, não gera CSS e NÃO EXISTE PARA A
// TELA — com todos os testes de render passando, porque a classe está no HTML.
// O gate do plano 180-05 proíbe, e aqui ele passa a ter alvo real.
//
// ⚠️ D-11: nenhum `router.*`, nenhuma escrita. Teclado desta tela só NAVEGA,
// SELECIONA e FECHA — nenhum atalho altera anúncio no Mercado Livre.
//
// ⚠️ O MÓDULO NÃO EXECUTA EFEITO: ele DECIDE. Cada função devolve uma AÇÃO
// DECLARATIVA (`{tipo, …}`) e quem a traduz em `focus()`/`blur()`/`setState` é
// a página. Sem isso o teste precisaria de DOM, e este projeto roda `node
// --test` sem jsdom.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Os tipos de ação, numa casa só.
 *
 * ⚠️ Constante em vez de string solta nos dois lados: um `'focarBusca'`
 * escrito com typo na página faria o despachante cair no `default` em silêncio
 * — a tecla pararia de funcionar sem nenhum teste reprovar.
 */
export const ACOES = Object.freeze({
    NADA: 'nada',
    FOCAR_BUSCA: 'focarBusca',
    FECHAR_MENU: 'fecharMenu',
    SAIR_DA_BUSCA: 'sairDaBusca',
    LIMPAR_SELECAO: 'limparSelecao',
    MOVER: 'mover',
    SELECIONAR_FOCO: 'selecionarFoco',
    FOCAR_PRIMEIRO: 'focarPrimeiro',
    SELECIONAR_PRIMEIRO: 'selecionarPrimeiro',
});

/** As teclas que descem a lista, exatamente as do protótipo. */
const TECLAS_PARA_BAIXO = ['ArrowDown', 'j'];

/** As teclas que sobem a lista, exatamente as do protótipo. */
const TECLAS_PARA_CIMA = ['ArrowUp', 'k'];

/** A tecla que foca a busca (o protótipo desenha essa dica dentro do campo). */
export const TECLA_DA_BUSCA = '/';

/** A tecla da cascata de fechamento. */
export const TECLA_DE_FECHAR = 'Escape';

/** Nenhuma ação — o retorno mais comum, e o default de todo caso de borda. */
function nada() {
    return { tipo: ACOES.NADA, preventDefault: false };
}

/**
 * A tecla do evento como string, ou `''`.
 *
 * ⚠️ Nunca `String(evento.key)`: `String({toString: null})` LANÇA, e esse é um
 * dos formatos do render adverso deste projeto (a tela preta de 07/10/2026).
 *
 * ⚠️ Comparação SENSÍVEL A CAIXA, como no protótipo
 * (`['ArrowDown','j','ArrowUp','k'].includes(e.key)`): `Shift+J` escreve um
 * `J` maiúsculo e **não** navega a lista. Baixar a caixa aqui faria
 * `Shift+J`/`Shift+K` mexerem na lista, o que o protótipo não faz.
 */
function teclaDe(evento) {
    if (evento === null || typeof evento !== 'object') return '';
    return typeof evento.key === 'string' ? evento.key : '';
}

/**
 * `true` quando a tecla vem com modificador (Ctrl/⌘/Alt).
 *
 * ⚠️⚠️ ESTA GUARDA É DIVERGÊNCIA DELIBERADA DO PROTÓTIPO, e existe por COLISÃO
 * MEDIDA: o protótipo não tem header, e esta tela tem. O `PublicadorHeader` da
 * Fase 177 já usa **⌘K / Ctrl+K** (busca global) e a tecla **N** (nova
 * publicação), em produção e aprovados pelo usuário
 * (`header/atalhosDoHeader.js`, `criarOuvinteDeAtalhos`). Sem esta guarda,
 * `Ctrl+K` abriria a busca do header **e** subiria uma linha na lista, porque
 * `k` está na tabela desta tela.
 *
 * ⚠️ `Escape` NÃO passa por aqui: a cascata de fechamento vale com qualquer
 * modificador, como no protótipo. E `N` não existe nesta tabela — não há
 * segunda colisão a resolver.
 */
function comModificador(evento) {
    if (evento === null || typeof evento !== 'object') return false;
    return evento.metaKey === true || evento.ctrlKey === true || evento.altKey === true;
}

/**
 * `true` quando o usuário está DIGITANDO e nenhuma tecla solta pode virar
 * atalho.
 *
 * ⚠️ REUSA `ignoraAtalho()` da Fase 177 em vez de reimplementar: a regra "não
 * dispare atalho dentro de campo de digitação" já tem UMA casa neste módulo, e
 * duas casas divergiriam. A guarda da Fase 177 é mais LARGA que a do protótipo
 * (que olha só `e.target.tagName === 'INPUT'`): ela cobre também `TEXTAREA`,
 * `SELECT`, `[contenteditable]`, `canvas` e `[role="grid"]`.
 *
 * ⚠️ PARA ESTA TELA AS DUAS SÃO EQUIVALENTES, e isso foi medido: a tela de
 * Publicações tem exatamente UM campo de digitação (o `<input>` de 54px de
 * `BuscaDePublicacoes`) e nenhum `textarea`, `select`, `canvas` nem
 * `contenteditable`. A guarda larga não muda nada hoje e protege de graça o dia
 * em que a tela ganhar um desses — enquanto a estreita precisaria crescer.
 *
 * ⚠️ ALVO AUSENTE/FORJADO DEVOLVE `false`, e aqui o contrato DIVERGE do
 * `ignoraAtalho()` de propósito. Lá, "na dúvida não dispara" (`true`) é o certo,
 * porque a dúvida protege o que está sendo digitado. Aqui o valor alimenta a
 * CASCATA do `Escape`, e um `true` por dúvida devolveria `sairDaBusca` para um
 * evento SEM alvo — a página tentaria `blur()` num `null` e, pior, a seleção da
 * linha NÃO seria limpa. Sem alvo não há campo em foco: não está digitando.
 *
 * @param {unknown} evento
 * @returns {boolean}
 */
export function estaDigitando(evento) {
    if (evento === null || typeof evento !== 'object') return false;

    const alvo = evento.target;
    if (alvo === null || typeof alvo !== 'object') return false;
    if (typeof alvo.tagName !== 'string') return false;

    return ignoraAtalho(alvo);
}

/**
 * O índice do próximo item da lista, TRAVADO nos limites.
 *
 * Medido no `move(dir)` do protótipo:
 * ```
 * const ids = this._ids || []; if (!ids.length) return;
 * const cur = this.state.selId || this.state.focusId;
 * let i = ids.indexOf(cur);
 * i = i < 0 ? 0 : Math.max(0, Math.min(ids.length - 1, i + dir));
 * ```
 *
 * ⚠️ DOIS DETALHES QUE PARECEM BUG E SÃO O COMPORTAMENTO MEDIDO:
 *  1. `atual` ausente ou fora da lista vai para o índice **0** — e NÃO para
 *     `0 + direcao`. A primeira seta depois de uma troca de página põe o foco na
 *     PRIMEIRA linha, em vez de pular a segunda (com `↓`) ou de não ter para
 *     onde ir (com `↑`).
 *  2. A lista **NÃO CIRCULA**: no último item o `↓` fica no último, e no
 *     primeiro o `↑` fica no primeiro. Circular faria o usuário que segura `↓`
 *     voltar ao topo sem perceber que varreu a página inteira.
 *
 * ⚠️ `indexOf` e não `[chave]`: a busca de chave em objeto COAGE para string e
 * `{toString: null}` faz essa coerção LANÇAR (derrubou dois componentes no
 * plano 180-07). `Array.prototype.indexOf` compara por igualdade estrita e não
 * coage nada.
 *
 * ⚠️ `direcao` `0`, `NaN` ou string devolve `null` (sem efeito). O protótipo só
 * chama `move(+1)` e `move(-1)`, então o caso não existe lá; inventar um
 * movimento a partir de `0` seria comportamento que ninguém mediu.
 *
 * @param {unknown} ids      as chaves na ORDEM VISUAL da lista
 * @param {unknown} atual    a chave com seleção/foco hoje
 * @param {unknown} direcao  `+1` desce, `-1` sobe
 * @returns {number|null} o índice, ou `null` quando não há o que mover
 */
export function proximoIndice(ids, atual, direcao) {
    if (!Array.isArray(ids) || ids.length === 0) return null;
    if (typeof direcao !== 'number' || !Number.isFinite(direcao)) return null;

    const passo = direcao > 0 ? 1 : (direcao < 0 ? -1 : 0);
    if (passo === 0) return null;

    // Só string é chave aqui: `chaveSegura()` da página devolve string ou
    // `null`, e um `atual` de outro tipo nunca estaria na lista.
    const encontrado = typeof atual === 'string' ? ids.indexOf(atual) : -1;
    if (encontrado < 0) return 0;

    return Math.max(0, Math.min(ids.length - 1, encontrado + passo));
}

/**
 * A ação da tecla no corpo da tela (fora da busca).
 *
 * A ORDEM DE PRECEDÊNCIA É A DO PROTÓTIPO, e é ela que o critério 11 mede
 * ("Esc fecha menu → sai da busca → fecha o painel"). Trocar a ordem muda o que
 * o usuário sente: com um menu de filtro aberto e uma linha selecionada, um
 * `Escape` tem de fechar SÓ o menu e deixar a seleção de pé.
 *
 * | gatilho            | condição                              | ação                  |
 * |--------------------|---------------------------------------|-----------------------|
 * | `/`                | não está digitando                    | `focarBusca` (+ pD)   |
 * | `Escape`           | há menu aberto                        | `fecharMenu`          |
 * | `Escape`           | está digitando                        | `sairDaBusca`         |
 * | `Escape`           | há publicação selecionada             | `limparSelecao`       |
 * | `Escape`           | nada disso                            | `nada`                |
 * | `ArrowDown` / `j`  | não está digitando                    | `mover +1` (+ pD)     |
 * | `ArrowUp` / `k`    | não está digitando                    | `mover -1` (+ pD)     |
 * | `Enter`            | não está digitando E há foco          | `selecionarFoco`      |
 *
 * ⚠️ `Enter` NÃO pede `preventDefault` — medido: o protótipo chama
 * `preventDefault()` nas setas/`j`/`k` e **não** no `Enter`. Fora de campo de
 * formulário o `Enter` não tem ação default a cancelar.
 *
 * ⚠️ `fecharMenu` É A RUNG QUE INTERROMPE A CASCATA, e não uma tecla muda — o
 * motivo está escrito por extenso no despachante da página.
 *
 * @param {unknown} evento
 * @param {unknown} estado `{menuAberto, temSelecao, temFoco}`
 * @returns {{tipo: string, preventDefault: boolean, direcao?: number}}
 */
export function interpretarTecla(evento, estado) {
    if (evento === null || typeof evento !== 'object') return nada();

    const tecla = teclaDe(evento);
    if (tecla === '') return nada();

    const atual = estado !== null && typeof estado === 'object' && !Array.isArray(estado) ? estado : {};
    const digitando = estaDigitando(evento);

    // `/` — foca a busca. ⚠️ A guarda de digitação é o que impede que uma barra
    // digitada DENTRO da busca roube o foco do próprio campo (e, com
    // `preventDefault`, impeça o caractere de ser escrito).
    if (tecla === TECLA_DA_BUSCA) {
        if (digitando || comModificador(evento)) return nada();
        return { tipo: ACOES.FOCAR_BUSCA, preventDefault: true };
    }

    // `Escape` — a cascata, na ordem literal do protótipo.
    if (tecla === TECLA_DE_FECHAR) {
        if (atual.menuAberto === true) return { tipo: ACOES.FECHAR_MENU, preventDefault: false };
        if (digitando) return { tipo: ACOES.SAIR_DA_BUSCA, preventDefault: false };
        if (atual.temSelecao === true) return { tipo: ACOES.LIMPAR_SELECAO, preventDefault: false };
        return nada();
    }

    // Daqui para baixo, digitar nunca navega: sem esta linha, escrever
    // "jaqueta" na busca desceria 1 linha no `j` e subiria 1 no `k`.
    if (digitando || comModificador(evento)) return nada();

    if (TECLAS_PARA_BAIXO.includes(tecla)) return { tipo: ACOES.MOVER, direcao: 1, preventDefault: true };
    if (TECLAS_PARA_CIMA.includes(tecla)) return { tipo: ACOES.MOVER, direcao: -1, preventDefault: true };

    // ⚠️ `temFoco` é obrigatório: `Enter` sem nenhuma linha em foco não tem o
    // que abrir, e abrir "a primeira" seria inventar a intenção do usuário.
    if (tecla === 'Enter' && atual.temFoco === true) {
        return { tipo: ACOES.SELECIONAR_FOCO, preventDefault: false };
    }

    return nada();
}

/**
 * A ação da tecla DENTRO do campo de busca.
 *
 * Medido no `onSearchKey` do protótipo: `(ArrowDown || Enter) && ids.length` →
 * `preventDefault()` + `blur()` + foco (ou seleção) no PRIMEIRO resultado.
 *
 * ⚠️ `temResultado !== true` devolve `nada`: `↓` numa busca que não achou nada
 * não pode tirar o foco do campo. O usuário ainda está corrigindo o termo.
 *
 * ⚠️ O `blur()` do protótipo é efeito das DUAS ações e a página o faz em
 * qualquer uma — por isso ele não é um tipo de ação próprio.
 *
 * @param {unknown} evento
 * @param {unknown} temResultado só `true` libera
 * @returns {{tipo: string, preventDefault: boolean}}
 */
export function interpretarTeclaDaBusca(evento, temResultado) {
    if (evento === null || typeof evento !== 'object') return nada();
    if (temResultado !== true) return nada();
    if (comModificador(evento)) return nada();

    const tecla = teclaDe(evento);

    if (tecla === 'ArrowDown') return { tipo: ACOES.FOCAR_PRIMEIRO, preventDefault: true };
    if (tecla === 'Enter') return { tipo: ACOES.SELECIONAR_PRIMEIRO, preventDefault: true };

    return nada();
}

/**
 * As chaves da lista na ORDEM VISUAL, derivadas dos MESMOS blocos que a tabela
 * desenha.
 *
 * ⚠️ POR QUE ELA MORA AQUI E RECEBE OS BLOCOS JÁ MONTADOS: o protótipo empilha
 * `ids.push(p.id)` DENTRO do `mkRow`, que só é chamado para as linhas que
 * entram na tela (`rows: open ? pubs.map(mkRow) : []`). Então a lista do
 * teclado é a ordem RENDERIZADA — não a ordem de `anuncios.data` — e grupo
 * recolhido NÃO contribui. Montá-la por um segundo caminho criaria duas casas
 * para a mesma verdade, e `↓` pularia para uma linha que o usuário não vê.
 *
 * @param {unknown} blocos `[{itens: [...]}, …]` como a página os monta
 * @param {unknown} chaveDoItem função que devolve a chave de um item, ou `null`
 * @returns {string[]}
 */
export function idsNaOrdemVisual(blocos, chaveDoItem) {
    if (!Array.isArray(blocos) || typeof chaveDoItem !== 'function') return [];

    const ids = [];
    for (const bloco of blocos) {
        if (bloco === null || typeof bloco !== 'object') continue;
        const itens = Array.isArray(bloco.itens) ? bloco.itens : [];
        for (const item of itens) {
            const chave = chaveDoItem(item);
            if (typeof chave === 'string' && chave !== '') ids.push(chave);
        }
    }

    return ids;
}
