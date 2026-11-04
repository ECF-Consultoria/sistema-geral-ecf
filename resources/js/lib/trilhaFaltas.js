// ─── Trilha de produtos — o que falta, e para onde cada falta leva (R11) ─────
//
// R11 do 1º teste do usuário (04/11/2026): clicar no que falta e ser guiado. Cada
// falta da Lista ("custo", "título", "fotos (1 de 3)", "ficha: marca"…) — o texto
// que o SERVIDOR escreve (`PendenciasDoProduto::ROTULOS` e `CompletudeDoProduto`)
// — vira um ALVO na gaveta de cadastro do produto (`e:{id}`): o campo ou a caixa
// onde ela se resolve. A gaveta já marca os campos com `data-campo` (os do
// `Campo` de `Cadastro/PecasDoCadastro.jsx`), as galerias com `data-galeria` e a
// ficha com `data-campo-tecnico`; este arquivo só diz qual procurar, rola até lá,
// põe o foco e destaca por um instante.
//
// O alvo NÃO vai para a URL (`?abrir=e:12` continua igual): é um empurrão de
// navegação, não um lugar. Um F5 reabre a gaveta no topo, e o "voltar" do
// navegador não ganha entrada nova. A página o passa à gaveta num estado à parte
// (`Pages/Trilha/Empresa.jsx`).
//
// Funções PURAS ou que só tocam o DOM que recebem (o teste passa um DOM de
// mentira). Sem React e sem classe do Tailwind (arquivo `.js`: o Tailwind só varre
// `.jsx` — o destaque vai por `style`, com as cores `rgb(var(--t-…))`). Sem
// nome de marketplace nem de outro módulo: o cliente baixa este arquivo (a Lista
// o importa) — para ele, a falta continua texto.

import { chaveDeCadastro } from './trilha.js';

/** Os alvos da gaveta e o seletor de cada um (o 1º que existir vale). */
export const SELETORES_DO_ALVO = {
    titulo: ['[data-campo="titulo"]'],
    familia: ['[data-campo="familia"]'],
    ambientes: ['[data-campo="ambientes"]'],
    categoria: ['[data-campo="categoria"]'],
    descricao: ['[data-campo="descricao"]'],
    custo: ['[data-campo="custo"]'],
    // "medidas" e "peso" da Lista são os da EMBALAGEM (learnings §13): a caixa de cada variação.
    volumes: ['[data-campo="volumes"]'],
    frete: ['[data-campo="frete"]', '[data-campo="volumes"]'],
    fotos: ['[data-galeria]', '[data-campo="fotos"]'],
    ficha: ['[data-caixa="ficha"]'],
};

/** Os rótulos fixos do servidor → o alvo. "medidas" e "peso" são os da caixa (embalagem). */
const POR_ROTULO = {
    custo: 'custo',
    'título': 'titulo',
    'família': 'familia',
    ambiente: 'ambientes',
    categoria: 'categoria',
    'descrição': 'descricao',
    medidas: 'volumes',
    peso: 'volumes',
    'frete ME1': 'frete',
};

/** O nome de um campo como o servidor o escreve na falta ("ficha: " + nome em minúsculas), para comparar com o rótulo. */
export const nomeComparavel = (texto) => String(texto ?? '')
    .replace(/\*/g, '')
    .replace(/\s+/g, ' ')
    .trim()
    .toLocaleLowerCase('pt-BR');

/**
 * O alvo de uma falta do servidor; null quando a falta não tem lugar conhecido na gaveta (ela continua só texto).
 *
 * @param {string} falta  "custo", "fotos (1 de 3)", "ficha: marca"…
 * @returns {?{campo: string, nome?: string}}
 */
export function alvoDaFalta(falta) {
    const texto = String(falta ?? '').trim();
    if (!texto) return null;
    if (POR_ROTULO[texto]) return { campo: POR_ROTULO[texto] };
    if (/^fotos\b/.test(texto)) return { campo: 'fotos' };
    const ficha = /^ficha:\s*(.+)$/.exec(texto);
    if (ficha) return { campo: 'ficha', nome: nomeComparavel(ficha[1]) };

    return null;
}

/**
 * O alvo de uma coluna "falta" da planilha da Lista — a mesma gaveta, pela coluna: Custo, Embalagem, Descrição, Fotos
 * e Ficha técnica (esta, no 1º campo que o servidor diz faltar).
 *
 * @param {'custo'|'embalagem'|'descricao'|'fotos'|'ficha'} coluna
 * @param {{faltas?: string[]}} produto
 * @returns {?{campo: string, nome?: string}}
 */
export function alvoDaColuna(coluna, produto) {
    if (coluna === 'ficha') {
        const primeira = (produto?.faltas ?? []).find((f) => /^ficha:/.test(String(f)));

        return primeira ? alvoDaFalta(primeira) : { campo: 'ficha' };
    }

    return { custo: { campo: 'custo' }, embalagem: { campo: 'volumes' }, descricao: { campo: 'descricao' }, fotos: { campo: 'fotos' } }[coluna] ?? null;
}

/**
 * As faltas como pílulas da coluna "Situação" da Lista: cada uma com o texto e o alvo; as da ficha viram UMA pílula
 * ("ficha: marca" ou "ficha (3 campos)"), que leva ao 1º campo que falta — dez campos da ficha não cabem numa célula.
 *
 * @param {string[]} faltas
 * @returns {Array<{texto: string, falta: string, alvo: ?{campo: string, nome?: string}}>}
 */
export function pilulasDasFaltas(faltas) {
    const lista = (Array.isArray(faltas) ? faltas : []).map((f) => String(f ?? '').trim()).filter(Boolean);
    const daFicha = lista.filter((f) => /^ficha:/.test(f));
    const saida = [];
    let fichaPosta = false;
    for (const falta of lista) {
        if (/^ficha:/.test(falta)) {
            if (fichaPosta) continue;
            fichaPosta = true;
            saida.push({ texto: daFicha.length === 1 ? falta : `ficha (${daFicha.length} campos)`, falta, alvo: alvoDaFalta(falta) });
            continue;
        }
        saida.push({ texto: falta, falta, alvo: alvoDaFalta(falta) });
    }

    return saida;
}

/** O texto acessível do botão de uma falta. */
export const rotuloDoBotaoDaFalta = (texto) => `Completar no cadastro: ${texto}`;

/**
 * O "ir para a falta" de um produto, para a Lista e o painel do produto: abre a gaveta de cadastro (`e:{id}`) com o
 * alvo — o 3º argumento do `onAbrir` da página. Só para quem escreve e com o produto que não andou (o travado só se
 * lê): para os outros, null (a falta fica texto).
 *
 * @param {(chave: string, origem: ?HTMLElement, opcoes?: {alvo: object}) => void} onAbrir
 * @param {{id: number, cadastro_travado?: boolean}} produto
 * @returns {?((alvo: {campo: string, nome?: string}, origem: ?HTMLElement) => void)}
 */
export function irParaOCadastro(onAbrir, produto, podeEditar) {
    if (!podeEditar || !produto || produto.cadastro_travado || typeof onAbrir !== 'function') return null;

    return (alvo, origem = null) => onAbrir(chaveDeCadastro(produto), origem, { alvo });
}

// ─── Na gaveta: achar, rolar, focar, destacar ─────────────────────────────────

const FOCAVEIS = [
    'input:not([type="hidden"]):not([disabled]):not([readonly])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    'button:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
];

const vazio = (el) => el && typeof el.value === 'string' && el.value.trim() === '';

/** Entre vários do mesmo campo (o custo e a caixa de cada cor), o 1º que ainda tem campo vazio; senão, o 1º. */
function primeiroComVazio(lista) {
    return lista.find((el) => [...(el.querySelectorAll?.('input, select, textarea') ?? [])].some(vazio)) ?? lista[0] ?? null;
}

/**
 * Onde a falta se resolve dentro da gaveta (`raiz`): `{elemento, foco, exato}` — `exato` false quando só a caixa foi
 * achada (o campo da ficha que ainda não carregou, p. ex.); null quando nada foi achado.
 *
 * @param {?Element} raiz  o diálogo da gaveta (ou qualquer contêiner dela)
 * @param {?{campo: string, nome?: string}} alvo
 */
export function acharAlvo(raiz, alvo) {
    if (!raiz?.querySelectorAll || !alvo?.campo) return null;
    const todos = (seletor) => [...raiz.querySelectorAll(seletor)];

    if (alvo.campo === 'ficha' && alvo.nome) {
        const campo = todos('[data-campo-tecnico]').find((el) => nomeComparavel(el.querySelector?.('label')?.textContent) === alvo.nome);
        if (campo) return { elemento: campo, foco: focavelEm(campo), exato: true };
        const caixa = todos('[data-caixa="ficha"]')[0];

        return caixa ? { elemento: caixa, foco: null, exato: false } : null;
    }
    if (alvo.campo === 'fotos') {
        const galerias = todos('[data-galeria]');
        // A cor que ainda não tem o mínimo (o contador âmbar) primeiro.
        const galeria = galerias.find((g) => g.querySelector?.('[data-contador-de-fotos="warn"]')) ?? galerias[0];
        if (galeria) return { elemento: galeria, foco: galeria.querySelector?.('[data-acao="enviar-fotos"]') ?? focavelEm(galeria), exato: true };
    }

    for (const seletor of SELETORES_DO_ALVO[alvo.campo] ?? []) {
        const achados = todos(seletor);
        if (!achados.length) continue;
        const elemento = alvo.campo === 'custo' || alvo.campo === 'volumes' ? primeiroComVazio(achados) : achados[0];

        return { elemento, foco: focavelEm(elemento), exato: true };
    }

    return null;
}

/** O 1º controle do campo que recebe foco: os de digitar antes dos botões (o "x" de um chip não é o campo). */
export function focavelEm(elemento) {
    if (!elemento?.querySelector) return null;
    for (const seletor of FOCAVEIS) {
        const el = elemento.querySelector(seletor);
        if (el) return el;
    }

    return null;
}

/** Quanto tempo o destaque fica (ms). */
export const DURACAO_DO_DESTAQUE = 2400;

/**
 * Destaca o elemento por um instante — por `style` (classe escrita num `.js` não é gerada), com a cor de "falta" da
 * trilha, e a marca `data-destaque-da-falta` (o teste a lê). Devolve o "desfazer" (o relógio também desfaz sozinho).
 */
export function destacar(elemento, { agendar = (f, ms) => setTimeout(f, ms) } = {}) {
    if (!elemento?.style) return () => {};
    const antes = { outline: elemento.style.outline, outlineOffset: elemento.style.outlineOffset, borderRadius: elemento.style.borderRadius };
    elemento.style.outline = '2px solid rgb(var(--t-warn))';
    elemento.style.outlineOffset = '4px';
    if (!antes.borderRadius) elemento.style.borderRadius = '10px';
    elemento.setAttribute?.('data-destaque-da-falta', '');
    let feito = false;
    const desfazer = () => {
        if (feito) return;
        feito = true;
        Object.assign(elemento.style, antes);
        elemento.removeAttribute?.('data-destaque-da-falta');
    };
    agendar(desfazer, DURACAO_DO_DESTAQUE);

    return desfazer;
}

const semMovimento = () => {
    try {
        return typeof window !== 'undefined' && !!window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches;
    } catch {
        return false;
    }
};

/**
 * Leva a pessoa até a falta: abre o `<details>` fechado que a esconde, rola até o meio da gaveta, põe o foco no
 * controle (sem rolar de novo) e destaca. Devolve `'exato'`, `'aproximado'` (só a caixa) ou null (nada achado).
 */
export function irParaOAlvo(raiz, alvo, opcoes = {}) {
    const achado = acharAlvo(raiz, alvo);
    if (!achado) return null;
    const { elemento, foco, exato } = achado;
    for (let pai = elemento.parentElement; pai && pai !== raiz; pai = pai.parentElement) {
        if (pai.tagName === 'DETAILS' && !pai.open) pai.open = true;
    }
    elemento.scrollIntoView?.({ block: 'center', behavior: semMovimento() ? 'auto' : 'smooth' });
    foco?.focus?.({ preventScroll: true });
    destacar(elemento, opcoes);

    return exato ? 'exato' : 'aproximado';
}

/**
 * Espera o alvo aparecer (a gaveta abre carregando, e a ficha chega depois da categoria) e vai até ele. Tenta agora e
 * de `intervalo` em `intervalo` ms, até achar o EXATO; esgotadas as tentativas, vale o aproximado (a caixa). Devolve o
 * "cancelar" (a gaveta fechou antes).
 *
 * @param {() => ?Element} obterRaiz
 * @param {?{campo: string, nome?: string}} alvo
 */
export function seguirAteOAlvo(obterRaiz, alvo, { tentativas = 20, intervalo = 150, agendar = (f, ms) => setTimeout(f, ms), desagendar = (t) => clearTimeout(t) } = {}) {
    if (!alvo?.campo) return () => {};
    let relogio = null;
    let cancelado = false;
    const tentar = (resta) => {
        if (cancelado) return;
        const raiz = obterRaiz();
        const achado = raiz ? acharAlvo(raiz, alvo) : null;
        if (achado?.exato || (achado && resta <= 0)) {
            irParaOAlvo(raiz, alvo, { agendar });

            return;
        }
        if (resta <= 0) return;
        relogio = agendar(() => tentar(resta - 1), intervalo);
    };
    tentar(tentativas);

    return () => {
        cancelado = true;
        if (relogio !== null) desagendar(relogio);
    };
}
