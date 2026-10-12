import { useEffect, useRef } from 'react';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 04 — os atalhos de teclado do PublicadorHeader (HEAD-13).
//
// O protótipo (`new_layout_publicador/PublicadorHeader.dc.html`:194-203) resolve
// isso num `componentDidMount` com `window.addEventListener('keydown', …)` e um
// `componentWillUnmount` que remove o ouvinte. Aqui vira hook, com o MESMO
// cuidado de remover no unmount: o header vive em 9 páginas e uma troca de
// página que deixasse o ouvinte para trás empilharia um atalho por navegação.
//
// ⚠️ A guarda `ignoraAtalho()` é mais larga que a do protótipo DE PROPÓSITO.
// O protótipo só olha INPUT/TEXTAREA. Aqui, `AnunciarMassa` é uma das 9 páginas
// e usa `@glideapps/glide-data-grid`: a grade desenha num <canvas> e recebe o
// foco SEM ser um <input>. Com a guarda do protótipo, apertar "n" no meio da
// edição da grade navegaria para fora da tela e o usuário perderia o que estava
// digitando. Por isso a guarda cobre também `canvas`, `[role="grid"]` e
// `[contenteditable="true"]` — e, na dúvida (alvo nulo ou que não parece um
// elemento), devolve `true`: não dispara.
//
// ⚠️ Hoje `aoAbrirBusca` NAVEGA (vai para a seleção de produtos da conta). A
// Fase 178 troca o corpo dessa função pelo modal "Nova publicação" — sem mexer
// neste módulo: o contrato (`aoAbrirBusca`, `aoFechar`) continua o mesmo.
// ═══════════════════════════════════════════════════════════════════════════

/** Campos de digitação em que nenhuma tecla solta pode virar atalho. */
const CAMPOS_DE_DIGITACAO = ['INPUT', 'TEXTAREA', 'SELECT'];

/**
 * Áreas que recebem foco e consomem o teclado sem serem campos de formulário.
 * `canvas` e `[role="grid"]` são a grade do AnunciarMassa; `contenteditable`
 * cobre editores de texto ricos que venham a existir no módulo.
 */
const AREAS_QUE_CONSOMEM_O_TECLADO = 'canvas, [role="grid"], [contenteditable="true"]';

/**
 * `true` quando a tecla NÃO deve virar atalho.
 *
 * Entrada nula, de outro tipo ou sem `tagName` devolve `true`: na dúvida, não
 * dispara. Perder o que estava sendo digitado é muito pior do que um atalho que
 * não funcionou numa situação de borda.
 *
 * @param {unknown} alvo normalmente `evento.target`
 * @returns {boolean}
 */
export function ignoraAtalho(alvo) {
    if (alvo === null || typeof alvo !== 'object') return true;

    const etiqueta = typeof alvo.tagName === 'string' ? alvo.tagName.toUpperCase() : null;
    if (etiqueta === null) return true;
    if (CAMPOS_DE_DIGITACAO.includes(etiqueta)) return true;
    if (alvo.isContentEditable === true) return true;

    // `closest` pode não existir (nó de texto, alvo forjado) ou lançar se o
    // seletor for recusado por um DOM parcial — os dois casos caem no "não
    // dispara".
    try {
        if (typeof alvo.closest === 'function' && alvo.closest(AREAS_QUE_CONSOMEM_O_TECLADO)) return true;
    } catch {
        return true;
    }

    return false;
}

/**
 * Monta o ouvinte de `keydown` do header.
 *
 * Export NOMEADO para o teste poder exercitar as três teclas sem DOM nenhum
 * (o `node --test` deste projeto roda sem jsdom, e efeito de React não roda em
 * render estático). Em produção o único consumidor é `useAtalhosDoHeader`.
 *
 * - ⌘K / Ctrl+K → abre a busca, valendo MESMO dentro de um campo de digitação
 *   (é o atalho de busca global, como no protótipo);
 * - N, sem modificador e fora de campo/área de edição → abre a busca;
 * - Esc → avisa o chamador para fechar o que estiver aberto (quem decide O QUE
 *   fechar é quem chama; o plano 177-06 liga os dois menus aqui).
 *
 * Callbacks ausentes não fazem nada e nunca lançam.
 *
 * @param {{aoAbrirBusca?: Function, aoFechar?: Function}} chamadas
 * @returns {(evento: KeyboardEvent) => void}
 */
export function criarOuvinteDeAtalhos(chamadas) {
    return function aoTeclar(evento) {
        if (evento === null || typeof evento !== 'object') return;

        const abrir = chamadas?.aoAbrirBusca;
        const fechar = chamadas?.aoFechar;
        const tecla = typeof evento.key === 'string' ? evento.key.toLowerCase() : '';

        if ((evento.metaKey === true || evento.ctrlKey === true) && tecla === 'k') {
            if (typeof evento.preventDefault === 'function') evento.preventDefault();
            if (typeof abrir === 'function') abrir();
            return;
        }

        if (
            evento.metaKey !== true
            && evento.ctrlKey !== true
            && evento.altKey !== true
            && tecla === 'n'
            && !ignoraAtalho(evento.target)
        ) {
            if (typeof evento.preventDefault === 'function') evento.preventDefault();
            if (typeof abrir === 'function') abrir();
            return;
        }

        if (evento.key === 'Escape') {
            if (typeof fechar === 'function') fechar();
        }
    };
}

/**
 * Registra o ouvinte em `window` e o remove no unmount.
 *
 * As funções chegam por `ref` e não pelas dependências do efeito: o header
 * recria `abrirNovaPublicacao` a cada render (ela depende da conta da prop) e,
 * nas dependências, o ouvinte seria removido e registrado de novo em todo
 * render. Com a `ref`, o ouvinte é registrado UMA vez e sempre chama a versão
 * mais recente das funções.
 *
 * @param {{aoAbrirBusca?: Function, aoFechar?: Function}} [chamadas]
 */
export function useAtalhosDoHeader(chamadas = {}) {
    const atuais = useRef(chamadas);
    atuais.current = chamadas;

    useEffect(() => {
        if (typeof window === 'undefined') return undefined;

        const ouvinte = criarOuvinteDeAtalhos({
            aoAbrirBusca: (...argumentos) => atuais.current?.aoAbrirBusca?.(...argumentos),
            aoFechar: (...argumentos) => atuais.current?.aoFechar?.(...argumentos),
        });

        window.addEventListener('keydown', ouvinte);
        return () => window.removeEventListener('keydown', ouvinte);
    }, []);
}
