import { etapaDoProblema } from './apoio.js';

// ─── "Corrigir em…": do problema ao campo que acende (10/10/2026) ───────────
//
// Pedido do usuário: ao apertar para corrigir, "acende o que é para corrigir", como o Portal
// faz com a luz amarela nas bordas, e "se é mais de uma coisa, mostra mais de uma coisa".
// Antes o link só abria a etapa: aviso não pinta campo de vermelho (não havia o que ver) e,
// clicado de dentro da própria etapa, não fazia nada.
//
// Cada problema traz o `alvo` do servidor (etapa, campo, atributo, variante, grupo…). Aqui ele
// vira uma lista de seletores, do mais exato ao mais largo (o campo, o cartão, a seção); acende
// o primeiro que existir na tela. O que é da conta ou da conferência (E0, E11, E13) não tem
// campo: fica só na lista do topo (`Mesa/OQueCorrigir`).
//
// Acender é pôr o atributo `data-aceso` no elemento; o desenho está em `app.css`. O React não
// conhece o atributo, então não o tira ao redesenhar, e nenhum campo precisou mudar.

export const ACESO = 'data-aceso';

const aspas = (v) => String(v).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
const com = (atributo, valor) => `[${atributo}="${aspas(valor)}"]`;
const comeca = (atributo, valor) => `[${atributo}^="${aspas(valor)}"]`;
const secao = (id) => com('data-secao', id);
const varios = (lista) => lista.join(', ');
const ate = (...seletores) => seletores.filter(Boolean);

const CODIGO_UNIVERSAL = ['GTIN', 'EMPTY_GTIN_REASON'];

/** O mesmo problema entre um desenho e outro da tela (a lista é refeita a cada um). */
export const chaveDoProblema = (p) => JSON.stringify([p?.regra ?? null, p?.mensagem ?? null, p?.alvo ?? null]);

/** O que acende numa etapa: bloqueios e avisos dela (nunca INFO), os bloqueios primeiro. */
export const problemasParaCorrigir = (problemas, etapa) => (problemas ?? [])
    .filter((p) => p.severidade !== 'INFO' && etapaDoProblema(p) === etapa)
    .sort((x, y) => (x.severidade === 'BLOCKER' ? 0 : 1) - (y.severidade === 'BLOCKER' ? 0 : 1));

/**
 * Onde o problema se corrige, do mais exato ao mais largo. Lista vazia = não é de um campo
 * (conta, conferência, publicação).
 */
export function seletoresDoProblema(p) {
    const a = p?.alvo ?? {};
    const e = a.etapa ?? null;
    // O tipo de anúncio vem como `alvo` (validação local) ou `listing_type` (resposta do ML).
    const tipo = a.listing_type ?? (typeof a.alvo === 'string' ? a.alvo : null);
    const v = a.variante ?? null;
    const cartao = v ? com('data-cartao-dados-variante', v) : null;

    // A foto exata (a miniatura de `FotosDoPar`), em todos os grupos onde ela está; depois o grupo; depois a seção.
    if (a.grupo || a.imagem || e === 'E6') {
        return ate(a.imagem && com('data-foto', a.imagem), a.grupo && com('data-grupo-foto', a.grupo), secao('fotos-variacoes'));
    }
    if (a.campo === 'preco') {
        return ate(tipo && v && com('id', `preco-${tipo}-${v}`), tipo && comeca('id', `preco-${tipo}-`), v && comeca('data-preco', `${v}|`), '[data-preco]', secao('preco'));
    }
    // E10 sem campo nem atributo = nenhum tipo de anúncio ligado.
    if (a.campo === 'tipo' || (e === 'E10' && ! a.campo && ! a.atributo)) return ate(tipo && com('data-alvo-ativo', tipo), '[data-alvo-ativo]', secao('preco'));
    if (a.campo === 'titulo' || e === 'E7') return ate(tipo && com('data-titulo', tipo), tipo && com('data-alvo', tipo), '[data-titulo]', secao('titulo'));
    if (a.campo === 'envio') return ate(com('data-campo', 'envio'), secao('envio'));
    if (a.campo === 'embalagem') return ate(com('data-medidas-pacote', 'pacote'), secao('envio'));
    if (a.campo === 'garantia') return ate(comeca('data-campo', 'garantia-t'), secao('garantia'));
    if (a.campo === 'descricao' || e === 'E9') return ate(com('data-campo', 'descricao'), secao('descricao'));
    if (a.campo === 'condicao') return ate('[data-condicao]', secao('produto'));
    if (e === 'E2') return ate('[data-categoria]', com('id', 'campo-categoria'), secao('produto'));
    if (['estoque', 'sku'].includes(a.campo)) return ate(v ? com('id', `${a.campo}-${v}`) : comeca('id', `${a.campo}-`), cartao, secao('variacoes'));

    if (a.atributo) {
        const ids = Array.isArray(a.atributos) && a.atributos.length > 0 ? a.atributos : [a.atributo];
        if (ids.some((id) => CODIGO_UNIVERSAL.includes(id))) return ate(v ? com('id', `gtin-${v}`) : comeca('id', 'gtin-'), cartao, secao('variacoes'));
        const daSecao = ['E4', 'E5'].includes(e) ? 'variacoes' : (e === 'E10' ? 'envio' : 'ficha');
        if (v) return ate(varios(ids.map((id) => com('id', `extra-${id}-${v}`))), cartao, secao(daSecao));

        // "Não se aplica" troca o campo por um texto: aí acende a caixa do atributo.
        return ate(varios(ids.map((id) => com('data-atributo', id))), varios(ids.map((id) => com('data-campo-atributo', id))), secao(daSecao));
    }

    if (a.eixo) return ate(com('data-eixo', a.eixo), secao('variacoes'));
    if (v) return ate(cartao, secao('variacoes'));
    if (['E4', 'E5'].includes(e)) return [secao('variacoes')];
    if (['E3', 'E8'].includes(e)) return [secao('ficha')];

    return [];
}

/** Os elementos do problema na tela: os do primeiro seletor que achar algum. */
export function elementosDoProblema(raiz, p) {
    for (const seletor of seletoresDoProblema(p)) {
        const achados = [...raiz.querySelectorAll(seletor)];
        if (achados.length > 0) return achados;
    }

    return [];
}

/** Acende o que os `problemas` apontam e apaga o que deixou de ser alvo. Devolve `[{ problema, elementos }]`. */
export function acender(raiz, problemas) {
    const alvos = new Set();
    const pares = (problemas ?? []).map((problema) => {
        const elementos = elementosDoProblema(raiz, problema);
        elementos.forEach((el) => alvos.add(el));

        return { problema, elementos };
    });

    [...raiz.querySelectorAll(`[${ACESO}]`)].forEach((el) => { if (! alvos.has(el)) el.removeAttribute(ACESO); });
    alvos.forEach((el) => { if (! el.hasAttribute(ACESO)) el.setAttribute(ACESO, ''); });

    return pares;
}

export const apagar = (raiz) => { [...raiz.querySelectorAll(`[${ACESO}]`)].forEach((el) => el.removeAttribute(ACESO)); };

/** Refaz o pulso: a animação só recomeça se o atributo sair e voltar. */
export function piscar(el) {
    el.removeAttribute(ACESO);
    void el.offsetWidth;
    el.setAttribute(ACESO, '');
}

/**
 * Rola até o elemento e, se for campo de digitar ou escolher, põe o cursor nele. Rolagem direta, de propósito:
 * a suave depende dos quadros de animação e não anda com a aba fora de vista (medido em produção, 10/10/2026)
 * — e "apertei e não aconteceu nada" era justamente a queixa. Quem chama a atenção é o pulso do campo.
 */
export function levarAte(el) {
    el.scrollIntoView({ block: 'center' });
    if (['INPUT', 'SELECT', 'TEXTAREA'].includes(el.tagName) && ! el.disabled) el.focus?.({ preventScroll: true });
}

/** "Mostrar" de uma linha da lista: pisca e leva ao campo daquele problema. Falso = não está na tela. */
export function mostrarNaTela(raiz, p) {
    const elementos = raiz ? elementosDoProblema(raiz, p) : [];
    elementos.forEach(piscar);
    if (elementos[0]) levarAte(elementos[0]);

    return elementos.length > 0;
}
