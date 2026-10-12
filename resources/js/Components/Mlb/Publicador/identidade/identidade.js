// ═══════════════════════════════════════════════════════════════════════════
// Fase 177, plano 02 — helpers de identidade do Publicador (layout novo).
//
// Fonte ÚNICA de iniciais, tom do tile e normalização de busca (IDENT-08:
// "nenhuma tela reimplementa"). Três funções puras, sem JSX e sem import de
// React — os gates importam este módulo direto, sem bundle.
//
// Cada função é a tradução literal de uma função do protótipo:
//   `ini(n)`   → new_layout_publicador/Visao Geral.dc.html:427 (idêntica nos 4 .dc.html)
//   `tone(h)`  → new_layout_publicador/Visao Geral.dc.html:426
//   `norm(s)`  → new_layout_publicador/Publicacoes v2.dc.html:487
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Iniciais do produto, exatamente como a `ini()` do protótipo: só palavras com
 * MAIS de 2 letras entram (tira "de", "da", "ml", "kg"), as 2 primeiras valem,
 * a primeira letra de cada, em maiúscula.
 *
 * ⚠️ Entrada que não é texto (objeto, nulo, ausente) devolve string VAZIA — e
 * NÃO um "—". Quem renderiza decide o que fazer com o vazio: o `TileDoProduto`
 * mostra o ícone de caixa em vez de letra nenhuma. Devolver "—" aqui obrigaria
 * toda tela a comparar com "—" para saber se tem inicial, o que é pior.
 *
 * @param {unknown} nome
 * @returns {string} ex.: "Poltrona Beny Veludo" → "PB"; "" ou entrada inválida → ""
 */
export function iniciais(nome) {
    if (typeof nome !== 'string' && typeof nome !== 'number') return '';

    return String(nome)
        .split(' ')
        .filter((palavra) => palavra.length > 2)
        .slice(0, 2)
        .map((palavra) => palavra[0])
        .join('')
        .toUpperCase();
}

/**
 * Hue ESTÁVEL por produto, de 0 a 359.
 *
 * O protótipo traz o hue escrito à mão em cada produto de exemplo (`p[3]`), e o
 * README (§Design Tokens, "Tiles de produto") diz só "um hue estável por
 * produto (ex.: hash do id)". Esta é a parte que o protótipo não resolve: um
 * hash determinístico e barato do identificador.
 *
 * ⚠️ Determinismo é requisito, não detalhe: o MESMO produto tem de receber o
 * MESMO tom na Visão geral, em Produtos, em Publicações e no painel lateral.
 * Nada de `Math.random()`, nada de índice da lista (que muda com a ordenação).
 *
 * `soma` fica sempre < 360 antes de cada passo, então `soma * 31 + código` nunca
 * passa de ~81 mil: não há estouro nem perda de precisão.
 */
function hueDoProduto(id) {
    if (typeof id !== 'string' && typeof id !== 'number') return 0;

    const chave = String(id);
    let soma = 0;
    for (let i = 0; i < chave.length; i += 1) {
        soma = (soma * 31 + chave.charCodeAt(i)) % 360;
    }
    return soma;
}

/**
 * Tom do tile de produto sem foto (TOK-13) — `oklch` calculado, por isso vai
 * por `style` e não por classe do Tailwind.
 *
 * Os dois literais são a `tone(h)` do protótipo, byte a byte:
 *   fundo `oklch(0.27 0.035 H)` · texto `oklch(0.86 0.07 H)`
 *
 * ⚠️ Esta é a ÚNICA cor computada do módulo, e por isso o único lugar onde
 * `oklch(` pode aparecer (o gate GATE-03 prova que nenhum componente repete).
 *
 * @param {unknown} id  nulo/objeto cai no hue 0, sem lançar
 * @returns {{fundo: string, texto: string}}
 */
export function tomDoProduto(id) {
    const hue = hueDoProduto(id);

    return {
        fundo: 'oklch(0.27 0.035 ' + hue + ')',
        texto: 'oklch(0.86 0.07 ' + hue + ')',
    };
}

/**
 * Normaliza texto para busca: tira acento e caixa. É a `norm()` do protótipo e
 * a base da busca que "ignora acentos e maiúsculas" (HEAD-05 e as Fases
 * 178/180): `normalizar('Poltrona Única') === 'poltrona unica'`.
 *
 * ⚠️ Entrada que não é texto devolve string vazia em vez de `"[object Object]"`.
 * O protótipo faz `String(s)` cru porque lá o dado é fixo; aqui o dado vem do
 * servidor, e um objeto virando "[object object]" dentro de um `includes()`
 * produziria resultado de busca fantasma.
 *
 * @param {unknown} texto
 * @returns {string}
 */
export function normalizar(texto) {
    if (typeof texto !== 'string' && typeof texto !== 'number') return '';

    return String(texto)
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}
