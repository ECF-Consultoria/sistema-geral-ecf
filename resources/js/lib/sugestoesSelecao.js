// ═══════════════════════════════════════════════════════════════════════
// Lógica de navegador da tela "Sugestões de ofertas" (Fase 168).
//
// Funções puras e imutáveis: marcar, editar, montar o pedido de aceite, aplicar o
// resultado e decidir a guarda de saída. Nenhuma regra de combinação, logística ou
// frete mora aqui; o navegador só manda {chave, nome, sku} e o servidor regera e
// revalida tudo. Os limites (título, SKU, lote) chegam por parâmetro.
//
// Sem React, axios nem Inertia: quem chama injeta `enviar` (o POST, que devolve o
// `data` do servidor), como em produtosGravacao.js. Assim o teste roda de verdade
// (tests/js/estrutura-sugestoes-selecao.test.js).
//
// Estado: { marcadas: string[], edicoes: { [chave]: { nome?: string, sku?: string } } }
// ═══════════════════════════════════════════════════════════════════════

export const estadoInicial = () => ({ marcadas: [], edicoes: {} });

// ─── Marcação ───────────────────────────────────────────────────────────

/** Marca/desmarca uma chave; recusa a marca que passaria do limite do lote. */
export function alternarMarca(estado, chave, limite) {
    if (estado.marcadas.includes(chave)) {
        return { estado: { ...estado, marcadas: estado.marcadas.filter((c) => c !== chave) }, recusou: false };
    }
    if (estado.marcadas.length >= limite) return { estado, recusou: true };

    return { estado: { ...estado, marcadas: [...estado.marcadas, chave] }, recusou: false };
}

/** Marca várias, em ordem, sem duplicar e sem passar do limite. */
export function marcarVarias(estado, novas, limite) {
    const marcadas = [...estado.marcadas];
    const recusadas = [];
    for (const chave of novas) {
        if (marcadas.includes(chave)) continue;
        if (marcadas.length >= limite) { recusadas.push(chave); continue; }
        marcadas.push(chave);
    }

    return { estado: { ...estado, marcadas }, recusadas };
}

export function desmarcarVarias(estado, lista) {
    return { ...estado, marcadas: estado.marcadas.filter((c) => ! lista.includes(c)) };
}

export const limparMarcacao = (estado) => ({ ...estado, marcadas: [] });

// ─── Edição ─────────────────────────────────────────────────────────────

/** Valor igual ao sugerido apaga a edição do campo; sem campos, a chave sai. */
export function editarCampo(estado, chave, campo, valor, sugerido) {
    const atual = { ...(estado.edicoes[chave] ?? {}) };
    if (valor === sugerido) delete atual[campo]; else atual[campo] = valor;

    const edicoes = { ...estado.edicoes };
    if (Object.keys(atual).length === 0) delete edicoes[chave]; else edicoes[chave] = atual;

    return { ...estado, edicoes };
}

export function desfazerEdicao(estado, chave) {
    const edicoes = { ...estado.edicoes };
    delete edicoes[chave];

    return { ...estado, edicoes };
}

export const valorDoCampo = (estado, sugestao, campo) => estado.edicoes[sugestao.chave]?.[campo] ?? sugestao[campo];

export const foiEditada = (estado, chave) => Object.keys(estado.edicoes[chave] ?? {}).length > 0;

export const haEdicaoPendente = (estado) => Object.keys(estado.edicoes).length > 0;

// ─── Avisos do cartão ───────────────────────────────────────────────────

/** Avisos sobre o valor ATUAL (editado ou sugerido). `skuRepetido` só vale sem edição do SKU. */
export function avisosDoCartao(sugestao, estado, limites) {
    const nome = String(valorDoCampo(estado, sugestao, 'nome') ?? '');
    const sku = String(valorDoCampo(estado, sugestao, 'sku') ?? '');
    const skuEditado = estado.edicoes[sugestao.chave]?.sku !== undefined;

    return {
        tituloLongo: nome.length > limites.max_titulo ? nome.length : null,
        skuLongo: sku.length > limites.max_sku,
        skuRepetido: Boolean(sugestao.sku_repetido) && ! skuEditado,
    };
}

/** Título longo só avisa; código longo ou campo vazio bloqueia o aceite. */
export function podeAceitar(sugestao, estado, limites) {
    const nome = String(valorDoCampo(estado, sugestao, 'nome') ?? '').trim();
    const sku = String(valorDoCampo(estado, sugestao, 'sku') ?? '').trim();

    return nome !== '' && sku !== '' && ! avisosDoCartao(sugestao, estado, limites).skuLongo;
}

// ─── Aceite e descarte ──────────────────────────────────────────────────

/** O que sai do navegador: só {chave, nome, sku}; null = o servidor usa o sugerido. */
export const pedidosDeAceite = (estado, lista) => lista.map((chave) => ({
    chave,
    nome: estado.edicoes[chave]?.nome ?? null,
    sku: estado.edicoes[chave]?.sku ?? null,
}));

/** Falha de rede: sem resposta do servidor ou 5xx. 4xx é resposta de validação. */
const ehFalhaDeRede = (e) => ! e?.response || e.response.status >= 500;

const tirarChaves = (estado, saem) => {
    const edicoes = { ...estado.edicoes };
    for (const c of saem) delete edicoes[c];

    return { marcadas: estado.marcadas.filter((c) => ! saem.includes(c)), edicoes };
};

/**
 * Aceita as marcadas (até `limite` por chamada, UMA chamada).
 * Criadas e já existentes saem da marcação e das edições; erros continuam marcados.
 *
 * @returns {Promise<{ estado: object, resultado: ?object, errosPorChave: object, falhaDeRede: boolean }>}
 */
export async function aceitarMarcadas(estado, lista, { enviar, limite }) {
    const pedidos = pedidosDeAceite(estado, lista.slice(0, limite));

    let data;
    try {
        data = await enviar(pedidos);
    } catch (e) {
        if (ehFalhaDeRede(e)) return { estado, resultado: null, errosPorChave: {}, falhaDeRede: true };

        const errosPorChave = {};
        for (const erro of e.response?.data?.erros ?? []) {
            if (erro?.chave) errosPorChave[erro.chave] = erro.mensagem;
        }

        return { estado, resultado: null, errosPorChave, falhaDeRede: false };
    }

    const resultado = {
        criadas: data?.criadas ?? [],
        ja_existiam: data?.ja_existiam ?? [],
        erros: data?.erros ?? [],
    };
    const errosPorChave = {};
    for (const erro of resultado.erros) errosPorChave[erro.chave] = erro.mensagem;

    const saem = [...resultado.criadas.map((c) => c.chave), ...resultado.ja_existiam];

    return { estado: tirarChaves(estado, saem), resultado, errosPorChave, falhaDeRede: false };
}

/** Descarta as chaves; só em sucesso saem da marcação e das edições. */
export async function descartarChaves(estado, lista, { enviar }) {
    try {
        const resultado = await enviar(lista);

        return { estado: tirarChaves(estado, lista), resultado, falhaDeRede: false };
    } catch (e) {
        return { estado, resultado: null, falhaDeRede: ehFalhaDeRede(e) };
    }
}

// ─── Guarda de saída ────────────────────────────────────────────────────

const caminhoDe = (url) => {
    try {
        return new URL(String(url), 'http://local.invalid').pathname;
    } catch {
        return String(url).split('?')[0];
    }
};

/**
 * Só segura quem SAI da tela com edição pendente: trocar filtro, página ou aba
 * (mesmo caminho) e visitas parciais não disparam a guarda.
 */
export function deveSegurarVisita({ haEdicao, liberado, destino, telaAtual, parcial }) {
    if (! haEdicao || liberado || parcial) return false;

    return caminhoDe(destino) !== caminhoDe(telaAtual);
}
