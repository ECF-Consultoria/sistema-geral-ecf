// ─── Trilha de produtos — o estado da tela na URL e a visão do cliente ──────
//
// Funções PURAS (sem React, sem Tailwind) que a página `Trilha/Empresa` usa:
//
// 1. O estado da tela vive na URL (`?abrir=p%3A12&etapa=lista&vista=mapa`), gravado
//    com `history.replaceState` — sem ir ao servidor. Assim um F5, um link
//    colado no WhatsApp ou o "voltar" de outra tela reabrem no mesmo lugar. O
//    `abrir` aceita o produto (`p:12`), a oferta (`o:s-12`), a onda (`w:7`, e
//    `w:nova` para criar) e o painel de mapear (`m:12`).
// 2. O "Ver como: Cliente" da equipe tira, no navegador, o mesmo que o servidor
//    tira na porta do cliente (`app/Http/Controllers/Trilha/`): link do
//    Publicador, preparo do agente, código MLB, a lista de sugestões, quem
//    mapeou e, das ondas, quem criou. O `url_ficha` e os links do Portal do
//    cliente não existem na porta da equipe, então a simulação mostra o cliente
//    sem esses botões — e sem nenhuma escrita (a página tira o `podeEditar`).

import { ORDEM, acharAberto, acharItem, chaveDoProduto } from '@/lib/trilha';

/**
 * Quem escreve nesta tela (2ª entrega): só a porta da equipe, com a trilha de
 * trabalho ligada, com `pode_editar` e com os endereços de `escrita` — e fora do
 * "Ver como: Cliente", que mostra a tela do cliente (ele só acompanha).
 *
 * @param {{visao?: string, trabalho?: boolean, pode_editar?: boolean, escrita?: ?object}} props
 * @param {'equipe'|'cliente'} verComo
 */
export const podeEscrever = (props, verComo = 'equipe') => props?.visao !== 'cliente'
    && verComo !== 'cliente'
    && !!props?.trabalho
    && !!props?.pode_editar
    && !!props?.escrita;

/**
 * O painel que a chave aberta mostra, para quem está olhando — null quando não
 * mostra nada (item que sumiu, ou painel de escrita para quem não escreve):
 *
 * - `p:`/`o:` → o painel do item;
 * - `w:7` → a onda (só leitura sem `podeEditar`); `w:nova` → só com `podeEditar`;
 * - `m:12` → o painel de mapear, só com `podeEditar` e com o produto fora da
 *   Lista; senão, o painel do produto (o cliente, o "Ver como: Cliente" e o
 *   link velho de um produto que voltou para a Lista caem nele).
 *
 * @returns {?{painel: 'item'|'onda'|'mapear', chave: string}}
 */
export function painelDaChave(dados, chave, { podeEditar = false } = {}) {
    const achado = chave ? acharAberto(dados, chave) : null;
    if (!achado) return null;
    if (achado.painel === 'onda') return achado.onda || podeEditar ? achado : null;
    if (achado.painel === 'mapear') {
        if (podeEditar && achado.produto.etapa !== 'lista') return achado;
        const produto = acharItem(dados, chaveDoProduto(achado.produto));

        return produto ? { painel: 'item', ...produto } : null;
    }

    return achado;
}

/**
 * Lê o estado da tela da busca da URL. Valor desconhecido vira o padrão; item
 * que não existe mais (apagado, ou link velho) é ignorado — e também o painel de
 * escrita para quem não escreve (`podeEditar`).
 *
 * @param {string} busca  `window.location.search`
 * @param {{produtos: Array, ofertas: Array, ondas?: Array}} dados
 * @param {{podeEditar?: boolean}} [quem]
 * @returns {{etapa: ?string, vista: 'trilha'|'mapa', abrir: ?string}}
 */
export function lerEstadoDaUrl(busca, dados, quem = {}) {
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
        abrir: abrir && painelDaChave(dados, abrir, quem) ? abrir : null,
    };
}

/** O `rawurlencode` do PHP (RFC 3986): o `encodeURIComponent` deixa `!'()*` como estão, o PHP os codifica. */
const rfc3986 = (texto) => encodeURIComponent(texto).replace(/[!'()*]/g, (c) => `%${c.charCodeAt(0).toString(16).toUpperCase()}`);

/**
 * A busca da URL com o estado da tela. Os valores padrão (Ver tudo, aba Trilha,
 * nada aberto) saem da URL, e os outros parâmetros de quem chegou ficam.
 *
 * Sai escrita como o LARAVEL escreve a URL da página (`page.url`): chaves em
 * ordem e cada parte em RFC 3986 (o ":" vira "%3A", o espaço "%20"). Por quê: na
 * carga nova (F5, link colado, aba nova) o Inertia compara o `page.url` com a
 * barra e, se diferem, EMPILHA uma entrada — o 1º "voltar" não saía da trilha e,
 * no link colado, a barra e a tela ficavam diferentes (revisão de 31/10/2026). O
 * servidor monta a busca com `Request::fullUrl()`, que passa pelo
 * `normalizeQueryString` do Symfony (ksort + `http_build_query` em RFC 3986), nas
 * duas portas. Só chave repetida ou com colchetes ("a[]=1", lista do PHP) ainda
 * sairia diferente — a trilha não usa nenhuma das duas.
 *
 * @returns {string}  "" ou "?abrir=p%3A12&etapa=lista"
 */
export function buscaComEstado(buscaAtual, { etapa, vista, abrir }) {
    const parametros = new URLSearchParams(buscaAtual ?? '');
    const definir = (chave, valor) => (valor ? parametros.set(chave, valor) : parametros.delete(chave));
    definir('etapa', etapa);
    definir('vista', vista === 'mapa' ? 'mapa' : null);
    definir('abrir', abrir);
    parametros.sort();
    // Não o `toString()`: ele escreve o espaço como "+", e o PHP como "%20".
    const texto = [...parametros].map(([chave, valor]) => `${rfc3986(chave)}=${rfc3986(valor)}`).join('&');

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
 * O produto já mapeado (etapa null) só tem linha na planilha da Lista, que mostra
 * todos. A onda tem cartão no Planejamento.
 */
export function etapaDoItem(dados, chave) {
    const achado = acharAberto(dados, chave);
    if (!achado) return null;
    if (achado.painel === 'onda') return 'plano';
    if (achado.painel === 'mapear') return achado.produto.etapa ?? 'lista';

    return achado.etapa ?? 'lista';
}

/** Os campos da onda que a porta do cliente recebe (`ApresentacaoDaTrilha::ONDA_CLIENTE`): sem quem criou. */
const daOndaParaCliente = ({ id, nome, data, comecou }) => ({ id, nome, data, comecou: !!comecou });

/** Os dados como a porta do cliente os recebe: o mesmo corte do servidor, feito no navegador. */
export function paraVisaoCliente(dados) {
    return {
        ...dados,
        // O cliente vê as ondas só com nome, data e se começou (decisão de 02/11/2026, como no artefato).
        ondas: (dados.ondas ?? []).map(daOndaParaCliente),
        produtos: (dados.produtos ?? []).map(({ url_publicador, ...produto }) => ({
            ...produto,
            sugestoes_pendentes: [],
            // Quem mapeou é da equipe: o cliente lê só a data.
            mapeado_por: null,
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
