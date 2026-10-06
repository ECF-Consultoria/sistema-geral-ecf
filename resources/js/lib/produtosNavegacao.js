import { router } from '@inertiajs/react';

// ─── Ida e volta lista ↔ ficha de Produtos (167-19, D-27) ───────────────────
//
// Ao voltar da ficha a lista reaparece como estava: busca, página, modo e
// rolagem. Tudo em sessionStorage dentro de try/catch — navegador que bloqueia
// o storage só perde a conveniência, nunca a navegação.
//
// A URL guardada vem do navegador, então NUNCA vira redirecionamento aberto:
// `urlDeVolta` só aceita o caminho da lista (exato ou seguido de `?`); qualquer
// outra coisa cai na própria lista.

const CHAVE_RETORNO = 'ecf.produtos.retorno';
const CHAVE_VOLTA = 'ecf.produtos.volta';

const ler = (chave) => {
    try {
        const bruto = window.sessionStorage.getItem(chave);

        return bruto ? JSON.parse(bruto) : null;
    } catch (e) {
        return null;
    }
};

const gravar = (chave, valor) => {
    try {
        window.sessionStorage.setItem(chave, JSON.stringify(valor));
    } catch (e) {
        // sem storage: perde só a conveniência
    }
};

const apagar = (chave) => {
    try {
        window.sessionStorage.removeItem(chave);
    } catch (e) {
        // idem
    }
};

const caminhoDaLista = () => new URL(route('portal.auth.estrutura.produtos'), window.location.origin).pathname;

/** Chamada pela lista antes de abrir a ficha: guarda onde estava. */
export function guardarRetorno() {
    gravar(CHAVE_RETORNO, { url: window.location.pathname + window.location.search, scrollY: window.scrollY });
}

/** Para onde "← Produtos" leva: a lista com a busca/página de antes, ou a lista pura. */
export function urlDeVolta() {
    const lista = caminhoDaLista();
    const url = ler(CHAVE_RETORNO)?.url;

    if (typeof url === 'string' && (url === lista || url.startsWith(`${lista}?`))) return url;

    return lista;
}

/** Volta para a lista. A "volta" só é gravada em onStart: se a pessoa desistir na confirmação, nada fica. */
export function voltarParaLista({ aviso = null, produtoId = null, replace = false } = {}) {
    const retorno = ler(CHAVE_RETORNO);

    router.visit(urlDeVolta(), {
        replace,
        onStart: () => gravar(CHAVE_VOLTA, { aviso, produtoId, scrollY: retorno?.scrollY ?? 0 }),
    });
}

/** A lista chama ao montar: lê e APAGA o que a ficha deixou. */
export function pegarVolta() {
    const volta = ler(CHAVE_VOLTA);
    apagar(CHAVE_VOLTA);

    return volta;
}

/** Rola até onde a pessoa estava (ou até o produto novo). O Inertia reseta a rolagem depois de trocar a página: rAF duplo. */
export function rolarParaVolta(volta) {
    if (! volta) return;
    requestAnimationFrame(() => requestAnimationFrame(() => {
        if (volta.produtoId) {
            document.querySelector(`[data-produto-id="${volta.produtoId}"]`)?.scrollIntoView({ block: 'center' });

            return;
        }
        window.scrollTo(0, volta.scrollY ?? 0);
    }));
}

// ─── Modo de visualização (167-20, D-26) ────────────────────────────────────
//
// "Visual grande" ou "Lista": a escolha fica no navegador (localStorage, dura
// além da aba) e volta igual depois da ficha ou de recarregar. Qualquer valor
// estranho vira 'grande'; sem storage a tela funciona do mesmo jeito.

const CHAVE_MODO = 'ecf.produtos.modo';
const MODOS_VALIDOS = ['grande', 'lista'];

export function lerModo() {
    try {
        const m = window.localStorage.getItem(CHAVE_MODO);

        return MODOS_VALIDOS.includes(m) ? m : 'grande';
    } catch (e) {
        return 'grande';
    }
}

export function gravarModo(modo) {
    if (! MODOS_VALIDOS.includes(modo)) return;
    try {
        window.localStorage.setItem(CHAVE_MODO, modo);
    } catch (e) {
        // sem storage: perde só a conveniência
    }
}
