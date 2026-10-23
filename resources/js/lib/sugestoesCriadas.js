// ═══════════════════════════════════════════════════════════════════════
// Planejamento, aba "Criadas": as palavras da lista (funções puras).
//
// Cada item vem do servidor com `pendencia` (a do preço, a mesma da tela de
// preços: sem custo, sem frete, impossível; nula = pronto) e `sem_calculo`
// quando o servidor não conseguiu calcular aquela oferta.
//
// 16/10/2026 — filtros, ordem, páginas e modo Lista ("deveria ter filtros, modo
// lista também"). Os filtros vão ao servidor (learnings §25/§27); aqui ficam os
// rótulos, os parâmetros da URL, o modo guardado no navegador, a vitrine e o que
// cada pendência pede para a pessoa fazer — e ONDE.
// ═══════════════════════════════════════════════════════════════════════

const PENDENCIAS = {
    sem_custo: 'Falta o custo',
    sem_frete: 'Falta o frete',
    impossivel: 'Preço impossível',
};

/** O selo do preço de uma oferta criada: `{ chave, texto, tom }`. */
export function rotuloDaPendencia(item) {
    if (! item || item.sem_calculo) return { chave: 'sem_calculo', texto: 'Sem preço calculado', tom: 'neutro' };
    if (! item.pendencia) return { chave: 'ok', texto: 'Com preço', tom: 'ok' };

    return { chave: item.pendencia, texto: PENDENCIAS[item.pendencia] ?? 'Falta o preço', tom: 'falta' };
}

/**
 * O nome que a tela mostra. Sem nome gravado, o título repetia o código (que já aparece embaixo):
 * monta-se pela composição, "2× Cadeira Zeta + 1× Mesa Beta".
 */
export function nomeDoItem(item) {
    if (item?.nome) return item.nome;
    const partes = (item?.composicao ?? []).map((c) => `${c.quantidade}× ${c.nome || c.sku || 'produto removido'}`);

    return partes.length ? partes.join(' + ') : (item?.sku ?? '');
}

const lista = (skus) => skus.filter(Boolean).join(', ');

/**
 * O que a pendência pede e ONDE se resolve: `{ texto, detalhe, acao, destino, q, destaque }`.
 * Custo e medidas são do cadastro do produto (a Precificação só repetia "falta o custo de um
 * componente"); frete e percentuais são da Precificação. `destino` é 'produtos' ou 'precificacao',
 * e `q` é o que a tela de destino já abre buscando.
 */
export function acaoDaPendencia(item) {
    const p = rotuloDaPendencia(item);
    const semMedida = item?.envio?.chave === 'pendente' ? (item.envio.sem_medida ?? []) : [];

    if (p.chave === 'ok') {
        return { texto: p.texto, detalhe: null, acao: 'Preço', destino: 'precificacao', q: item.sku, destaque: false };
    }
    if (p.chave === 'sem_custo') {
        const quem = item.faltam_custo ?? [];

        return {
            texto: p.texto,
            detalhe: quem.length ? `de ${lista(quem)} — o custo vem do cadastro do produto` : 'de um dos produtos — o custo vem do cadastro do produto',
            acao: 'Cadastrar custo', destino: 'produtos', q: quem[0] ?? null, destaque: true,
        };
    }
    if (p.chave === 'sem_frete' && semMedida.length) {
        return {
            texto: 'Faltam medidas', detalhe: `de ${lista(semMedida)} — sem elas não há frete`,
            acao: 'Cadastrar medidas', destino: 'produtos', q: semMedida[0], destaque: true,
        };
    }
    if (p.chave === 'sem_frete') {
        return {
            texto: p.texto, detalhe: 'fora do Mercado Envios: digite o frete da sua transportadora',
            acao: 'Informar frete', destino: 'precificacao', q: item.sku, destaque: true,
        };
    }
    if (p.chave === 'impossivel') {
        return {
            texto: p.texto, detalhe: 'comissão, imposto e margens somam 100% ou mais',
            acao: 'Revisar percentuais', destino: 'precificacao', q: item.sku, destaque: true,
        };
    }

    return { texto: p.texto, detalhe: null, acao: 'Ver preço', destino: 'precificacao', q: item?.sku ?? null, destaque: false };
}

// ─── Vitrine do cartão ───────────────────────────────────────────────────────

/**
 * Como as fotos se arrumam no cartão: em LINHA até 3 produtos (uma grande no Combo, duas no Kit, três
 * menores) e em MOSAICO 2×2 a partir de 4 — em linha, 4 fotos de 56 px passavam da borda do cartão em
 * 1280 e 1536 px de tela (medido) e eram pequenas demais para reconhecer. No mosaico vão até 3 fotos e o
 * "+N" no quarto quadro quando passa de 4.
 */
export function layoutDaVitrine(composicao) {
    const itens = Array.isArray(composicao) ? composicao : [];
    if (itens.length >= 4) {
        return itens.length === 4
            ? { modo: 'mosaico', pecas: itens, resto: 0, tamanho: 72 }
            : { modo: 'mosaico', pecas: itens.slice(0, 3), resto: itens.length - 3, tamanho: 72 };
    }

    return { modo: 'linha', pecas: itens, resto: 0, tamanho: [132, 132, 108, 72][itens.length] };
}

// ─── Filtros, ordem e página (servidor) ──────────────────────────────────────

export const ROTULO_FASE = { combo: 'Combo', kit: 'Kit', combit: 'Combit' };

export const ROTULO_SITUACAO = {
    com_preco: 'Com preço', pendente: 'Com pendência', sem_custo: 'Falta custo', sem_frete: 'Falta frete', impossivel: 'Preço impossível',
};

export const ROTULO_ENVIO = {
    me2: 'Mercado Envios', me2_full: 'Full', me1: 'ME1 (transportadora)', pendente: 'Faltam medidas', declarada: 'Transportadora ou a combinar',
};

export const ROTULO_ORDEM = {
    recentes: 'Mais recentes', pendentes: 'Com pendência primeiro', nome: 'Nome (A–Z)', preco_maior: 'Maior preço', preco_menor: 'Menor preço',
};

/** Há algum filtro (além da ordem) ligado? Decide o "Limpar filtros" e o estado vazio. */
export function filtroLigado(f) {
    return Boolean(f?.fase || f?.situacao || f?.envio || (f?.q ?? '').trim());
}

/**
 * Os parâmetros das criadas na URL: só o que difere do padrão (sem `ordem=recentes`, sem `criadas_pagina=1`),
 * para "sem filtro" não deixar nada na URL. Mudar qualquer filtro volta à página 1.
 */
export function paramsDaCriadas(atuais, mudancas = {}) {
    const juntos = { ...(atuais ?? {}), ...mudancas };
    if (! ('pagina' in mudancas)) juntos.pagina = 1;

    // 21/10/2026: as criadas dividem a tela (e a URL) com as sugestões. Tipo, busca e página levam o
    // prefixo `criadas_`; situação, envio e ordem só existem aqui.
    const params = {};
    for (const [chave, nome] of [['fase', 'criadas_fase'], ['situacao', 'situacao'], ['envio', 'envio'], ['q', 'criadas_q']]) {
        const v = typeof juntos[chave] === 'string' ? juntos[chave].trim() : juntos[chave];
        if (v) params[nome] = v;
    }
    if (juntos.ordem && juntos.ordem !== 'recentes') params.ordem = juntos.ordem;
    if (Number(juntos.pagina) > 1) params.criadas_pagina = Number(juntos.pagina);

    return params;
}

/** "18 criadas" / "1 criada" / com filtro: "5 de 18 criadas". */
export function textoDoTotal(criadas, f) {
    const total = Number(criadas?.total) || 0;
    const filtrado = Number(criadas?.filtrado ?? total) || 0;
    const palavra = total === 1 ? 'criada' : 'criadas';

    return filtroLigado(f) ? `${filtrado} de ${total} ${palavra}` : `${total} ${palavra}`;
}

// ─── Modo Visual grande / Lista (no navegador, como em Produtos) ─────────────

const CHAVE_MODO = 'ecf.criadas.modo';
const MODOS = ['grande', 'lista'];

export function lerModoCriadas() {
    try {
        const m = window.localStorage.getItem(CHAVE_MODO);

        return MODOS.includes(m) ? m : 'grande';
    } catch (e) {
        return 'grande';
    }
}

export function gravarModoCriadas(modo) {
    if (! MODOS.includes(modo)) return;
    try {
        window.localStorage.setItem(CHAVE_MODO, modo);
    } catch (e) {
        // sem storage: perde só a conveniência
    }
}
