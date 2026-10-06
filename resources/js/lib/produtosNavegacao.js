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

// Validades (revisão FE-IN-04): o retorno vale enquanto a pessoa edita a ficha; a volta é
// consumida logo que a lista monta, então só vale por instantes.
const RETORNO_VALE_MS = 2 * 60 * 60 * 1000;
const VOLTA_VALE_MS = 2 * 60 * 1000;

const recente = (registro, validade) => typeof registro?.em === 'number' && Date.now() - registro.em <= validade;

/** Id de produto aceito para montar seletor: inteiro positivo, nada cru. */
const idValido = (id) => Number.isInteger(id) && id > 0;

/** Chamada pela lista antes de abrir a ficha: guarda onde estava e qual ficha está abrindo. */
export function guardarRetorno(destino = null) {
    let abriu = null;
    try {
        abriu = destino ? new URL(destino, window.location.origin).pathname : null;
    } catch (e) {
        abriu = null;
    }
    gravar(CHAVE_RETORNO, { url: window.location.pathname + window.location.search, scrollY: window.scrollY, em: Date.now(), abriu });
}

/** Para onde "← Produtos" leva: a lista com a busca/página de antes (se recente), ou a lista pura. */
export function urlDeVolta() {
    const lista = caminhoDaLista();
    const retorno = ler(CHAVE_RETORNO);
    const url = recente(retorno, RETORNO_VALE_MS) ? retorno.url : null;

    if (typeof url === 'string' && (url === lista || url.startsWith(`${lista}?`))) return url;

    return lista;
}

/**
 * Volta para a lista. A "volta" só é gravada em onStart: se a pessoa desistir na confirmação, nada
 * fica; se a visita começar e não chegar (rede, cancelada), ela é apagada no fim.
 */
export function voltarParaLista({ aviso = null, produtoId = null, replace = false } = {}) {
    const retorno = ler(CHAVE_RETORNO);
    let chegou = false;

    router.visit(urlDeVolta(), {
        replace,
        onStart: () => gravar(CHAVE_VOLTA, { aviso, produtoId, scrollY: retorno?.scrollY ?? 0, em: Date.now() }),
        onSuccess: () => { chegou = true; },
        onFinish: () => { if (! chegou) apagar(CHAVE_VOLTA); },
    });
}

// ─── Sair da ficha pelo histórico (revisão FE-WR-05) ────────────────────────
//
// Visitar a lista ao sair da ficha deixava a pilha [lista velha, lista nova]
// (salvar e excluir trocavam a entrada da ficha) ou [lista, ficha, lista]
// (Cancelar e "← Produtos" empilhavam), e o voltar do navegador mostrava a
// lista velha: produto excluído ainda na tela, clicar nele dava 404. Quando a
// entrada anterior é a lista, a ficha sai com `history.back()`: fica UMA
// entrada da lista, e a lista se recarrega ao montar — também quando a pessoa
// usa o voltar do navegador. Sem como saber o que há atrás, a visita comum.

const ABERTURA_VALE_MS = 60 * 1000;

/** A ficha, ao montar: a lista acabou de abri-la, nesta aba? (Só lê; `esquecerAbertura` consome.) */
export function fichaAbertaPelaLista() {
    const retorno = ler(CHAVE_RETORNO);

    return typeof retorno?.abriu === 'string' && retorno.abriu === window.location.pathname && recente(retorno, ABERTURA_VALE_MS);
}

/** Consome a marca da abertura: outra aba com o storage copiado ou uma visita depois não a herdam. */
export function esquecerAbertura() {
    const retorno = ler(CHAVE_RETORNO);
    if (retorno?.abriu) gravar(CHAVE_RETORNO, { ...retorno, abriu: null });
}

/** A entrada anterior do histórico é a lista? Com a Navigation API, confere; sem ela, vale a marca da abertura. */
export function podeVoltarNoHistorico(abertaPelaLista = false) {
    try {
        const nav = window.navigation;
        if (nav?.currentEntry && typeof nav.entries === 'function') {
            const anterior = nav.entries()[nav.currentEntry.index - 1];

            return !! anterior?.url && new URL(anterior.url).pathname === caminhoDaLista();
        }
    } catch (e) {
        // sem Navigation API confiável: fica a marca
    }

    return !! abertaPelaLista;
}

/** Sai da ficha voltando no histórico. A rolagem fica com o Inertia (a da própria entrada da lista). */
export function voltarPeloHistorico({ aviso = null, produtoId = null } = {}) {
    gravar(CHAVE_VOLTA, { aviso, produtoId, scrollY: null, historico: true, em: Date.now() });
    window.history.back();
}

/** A lista chama ao montar: lê e APAGA o que a ficha deixou (só vale se for de agora). */
export function pegarVolta() {
    const volta = ler(CHAVE_VOLTA);
    apagar(CHAVE_VOLTA);
    if (! volta || ! recente(volta, VOLTA_VALE_MS)) return null;

    return { ...volta, produtoId: idValido(volta.produtoId) ? volta.produtoId : null };
}

/** Rola até onde a pessoa estava (ou até o produto novo). O Inertia reseta a rolagem depois de trocar a página: rAF duplo. */
export function rolarParaVolta(volta) {
    if (! volta) return;
    requestAnimationFrame(() => requestAnimationFrame(() => {
        if (idValido(volta.produtoId)) {
            document.querySelector(`[data-produto-id="${volta.produtoId}"]`)?.scrollIntoView({ block: 'center' });

            return;
        }
        // Volta pelo histórico não traz rolagem: o Inertia restaura a da própria entrada da lista.
        if (typeof volta.scrollY === 'number') window.scrollTo(0, volta.scrollY);
    }));
}

// ─── Produto de onde a pessoa acabou de voltar (D-32) ───────────────────────
//
// Ao sair da ficha — salvando, cancelando, por "← Produtos" ou pelo voltar do
// navegador — a lista destaca o cartão daquele produto. A ficha grava o id ao
// desmontar; a lista lê e APAGA ao montar. Vale só logo depois de sair: quem
// passou por outra tela e voltou bem depois não vê destaque velho.

const CHAVE_ULTIMO = 'ecf.produtos.ultimo';
const ULTIMO_VALE_MS = 2 * 60 * 1000;

/** A ficha chama ao desmontar com o produto que representava (null = nada a destacar, ex.: produto excluído). */
export function marcarUltimoProduto(produtoId) {
    if (! produtoId) {
        apagar(CHAVE_ULTIMO);

        return;
    }
    gravar(CHAVE_ULTIMO, { id: Number(produtoId), em: Date.now() });
}

/** A lista chama ao montar: o id do produto de onde a pessoa voltou, ou null. */
export function pegarUltimoProduto() {
    const ultimo = ler(CHAVE_ULTIMO);
    apagar(CHAVE_ULTIMO);
    if (! Number.isInteger(ultimo?.id) || typeof ultimo.em !== 'number' || Date.now() - ultimo.em > ULTIMO_VALE_MS) return null;

    return ultimo.id;
}

/**
 * Traz o cartão para a tela só se ele estiver INTEIRAMENTE fora dela — depois de `rolarParaVolta`
 * (rAF triplo). Cartão cortado na borda ou mais alto que a janela já está à vista: rolar desfaria a
 * rolagem restaurada do D-27 (revisão FE-WR-06).
 */
export function mostrarCartao(produtoId) {
    if (! idValido(produtoId)) return;
    requestAnimationFrame(() => requestAnimationFrame(() => requestAnimationFrame(() => {
        const cartao = document.querySelector(`[data-produto-id="${produtoId}"]`);
        if (! cartao) return;
        const { top, bottom } = cartao.getBoundingClientRect();
        if (bottom <= 0 || top >= window.innerHeight) cartao.scrollIntoView({ block: 'center', behavior: 'smooth' });
    })));
}

// ─── Rascunho da ficha (revisão FE-CR-02) ──────────────────────────────────
//
// O voltar do navegador (botão, Alt+←, gesto do celular) troca a página sem
// passar pela guarda do Inertia, e a ficha desmonta. Para o que foi digitado
// não sumir, a ficha grava um rascunho a cada alteração — um por produto, ou
// "novo" — e, ao abrir de novo, oferece recuperar. Some ao salvar com sucesso,
// ao excluir o produto e ao sair confirmando "Sair sem salvar?". Vale por
// algumas horas: rascunho de ontem não reaparece por cima do produto.

const PREFIXO_RASCUNHO = 'ecf.produtos.rascunho.';
const RASCUNHO_VALE_MS = 6 * 60 * 60 * 1000;

const chaveRascunho = (produtoId) => {
    const id = Number(produtoId);

    return PREFIXO_RASCUNHO + (produtoId && Number.isInteger(id) && id > 0 ? id : 'novo');
};

/** Grava o rascunho das variações da ficha (produtoId null = produto novo). */
export function gravarRascunho(produtoId, vars) {
    gravar(chaveRascunho(produtoId), { em: Date.now(), vars });
}

/** O rascunho guardado e ainda válido ({ em, vars }), ou null. Vencido ou estranho é apagado. */
export function lerRascunho(produtoId) {
    const chave = chaveRascunho(produtoId);
    const r = ler(chave);
    if (! r) return null;
    if (typeof r.em !== 'number' || Date.now() - r.em > RASCUNHO_VALE_MS || ! Array.isArray(r.vars) || r.vars.length === 0) {
        apagar(chave);

        return null;
    }

    return r;
}

export function apagarRascunho(produtoId) {
    apagar(chaveRascunho(produtoId));
}

// ─── Voltar do navegador com a ficha alterada (revisão FE-CR-02) ────────────
//
// Quem fica na ficha depois do "Sair sem salvar?" precisa que o histórico volte
// para a entrada dela. Com a Navigation API dá para saber quantos passos são
// (a pessoa pode ter ido para trás ou para a frente); sem ela, o caso comum é o
// voltar, e o caminho de volta é 1 passo para a frente.

/** Chave da entrada do histórico em que a página está, ou null sem Navigation API. */
export function entradaAtual() {
    try {
        return window.navigation?.currentEntry?.key ?? null;
    } catch (e) {
        return null;
    }
}

/** Passos de `history.go` para voltar à entrada `chave` (1 quando não dá para saber). */
export function passosAte(chave) {
    try {
        const nav = window.navigation;
        if (chave && nav?.currentEntry && typeof nav.entries === 'function') {
            const alvo = nav.entries().findIndex((e) => e.key === chave);
            const passos = alvo - nav.currentEntry.index;
            if (alvo >= 0 && passos !== 0) return passos;
        }
    } catch (e) {
        // sem Navigation API: cai no caso comum
    }

    return 1;
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
