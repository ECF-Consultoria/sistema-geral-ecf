// ─── Trilha de produtos — o estado da tela na URL e a visão do cliente ──────
//
// Funções PURAS (sem React, sem Tailwind) que a página `Trilha/Empresa` usa:
//
// 1. O estado da tela vive na URL (`?etapa=lista&vista=mapa&abrir=p:12`), gravado
//    com `history.replaceState` — sem ir ao servidor. Assim um F5, um link
//    colado no WhatsApp ou o "voltar" de outra tela reabrem no mesmo lugar.
// 2. O "Ver como: Cliente" da equipe tira, no navegador, o mesmo que o servidor
//    tira na porta do cliente (`app/Http/Controllers/Trilha/`): link do
//    Publicador, preparo do agente, código MLB e a lista de sugestões. O
//    `url_ficha` e os links do Portal do cliente não existem na porta da equipe,
//    então a simulação mostra o cliente sem esses botões.

import { ORDEM, acharItem } from '@/lib/trilha';

/**
 * Lê o estado da tela da busca da URL. Valor desconhecido vira o padrão; item
 * que não existe mais (apagado, ou link velho) é ignorado.
 *
 * @param {string} busca  `window.location.search`
 * @param {{produtos: Array, ofertas: Array}} dados
 * @returns {{etapa: ?string, vista: 'trilha'|'mapa', abrir: ?string}}
 */
export function lerEstadoDaUrl(busca, dados) {
    let parametros;
    try {
        parametros = new URLSearchParams(busca ?? '');
    } catch {
        parametros = new URLSearchParams();
    }
    const etapa = parametros.get('etapa');
    const abrir = parametros.get('abrir');

    return {
        etapa: ORDEM.includes(etapa) ? etapa : null,
        vista: parametros.get('vista') === 'mapa' ? 'mapa' : 'trilha',
        abrir: abrir && acharItem(dados, abrir) ? abrir : null,
    };
}

/**
 * A busca da URL com o estado da tela. Os valores padrão (Ver tudo, aba Trilha,
 * nada aberto) saem da URL, e os outros parâmetros de quem chegou ficam.
 *
 * @returns {string}  "" ou "?etapa=…"
 */
export function buscaComEstado(buscaAtual, { etapa, vista, abrir }) {
    const parametros = new URLSearchParams(buscaAtual ?? '');
    const definir = (chave, valor) => (valor ? parametros.set(chave, valor) : parametros.delete(chave));
    definir('etapa', etapa);
    definir('vista', vista === 'mapa' ? 'mapa' : null);
    definir('abrir', abrir);
    // O ":" de "p:12" é válido na busca; sem isto a URL ficaria "p%3A12".
    const texto = parametros.toString().replace(/%3A/gi, ':');

    return texto ? `?${texto}` : '';
}

/**
 * O `history.state` para o `replaceState` da URL nova, com a mesma URL também na
 * página que o Inertia guardou ali (`state.page.url`).
 *
 * Por quê: quando o "voltar" do navegador RECARREGA o documento (sem o bfcache),
 * o Inertia reabre a página guardada no histórico e compara a URL dela com a da
 * barra; se diferem, ele EMPILHA a guardada — a trilha reabriria no estado de
 * quando a página carregou e o histórico ganharia uma entrada a mais. Com o
 * histórico criptografado (`page` não é um objeto com `url`), devolve como veio.
 */
export function estadoComUrl(estado, url) {
    if (!estado || typeof estado.page?.url !== 'string') return estado;

    return { ...estado, page: { ...estado.page, url } };
}

/**
 * Para "Abrir na trilha" (Mapa da loja): a etapa em que o item aparece como linha.
 * O produto já mapeado (etapa null) só tem linha na planilha da Lista, que mostra todos.
 */
export function etapaDoItem(dados, chave) {
    const achado = acharItem(dados, chave);
    if (!achado) return null;

    return achado.etapa ?? 'lista';
}

/** Os dados como a porta do cliente os recebe: o mesmo corte do servidor, feito no navegador. */
export function paraVisaoCliente(dados) {
    return {
        ...dados,
        produtos: (dados.produtos ?? []).map(({ url_publicador, ...produto }) => ({
            ...produto,
            sugestoes_pendentes: [],
        })),
        ofertas: (dados.ofertas ?? []).map(({ url_publicador, ...oferta }) => ({
            ...oferta,
            preparo: null,
            anuncios: (oferta.anuncios ?? []).map((anuncio) => ({ ...anuncio, mlb: null })),
        })),
    };
}

/** Os links da porta da equipe que o cliente não tem. */
export const linksParaVisaoCliente = (links) => ({ ...(links ?? {}), portal_abrir: null, publicador: null });
