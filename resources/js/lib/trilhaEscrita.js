// ─── Trilha de produtos — as escritas da porta da equipe (2ª entrega) ───────
//
// O Mapeamento e o Planejamento (ondas) passaram a ser feitos DENTRO da trilha.
// As escritas são JSON (como o Planejamento do Portal), pelos endereços que o
// servidor manda em `escrita` — só para a equipe com escrita (`pode_editar`); o
// cliente nunca recebe nenhum, e a porta dele não tem rota de escrita.
//
// O axios já vai com o `X-Requested-With` e o `X-CSRF-TOKEN` (resources/js/bootstrap.js,
// renovado a cada resposta do Inertia em app.jsx). Depois de gravar, a tela
// recarrega SÓ os dados da trilha (`recarregar`), sem perder o painel aberto.
//
// Sem classe do Tailwind aqui (arquivo `.js`: o Tailwind só varre `.jsx`).

import axios from 'axios';
import { router } from '@inertiajs/react';

/** Erro da própria tela (endereço que não veio, p. ex.): o texto dele pode ir para a pessoa. */
export class ErroDaTrilha extends Error {}

const semBarraNoFim = (url) => String(url).replace(/\/+$/, '');

function endereco(escrita, campo) {
    const url = escrita?.[campo];
    if (!url) throw new ErroDaTrilha('Esta tela não pode gravar: recarregue a página.');

    return semBarraNoFim(url);
}

/** `{escrita.ondas}/{id}`. */
export const urlDaOnda = (escrita, ondaId) => `${endereco(escrita, 'ondas')}/${encodeURIComponent(ondaId)}`;

/** `{escrita.produtos}/{id}/mapeamento`. */
export const urlDoMapeamento = (escrita, produtoId) => `${endereco(escrita, 'produtos')}/${encodeURIComponent(produtoId)}/mapeamento`;

const corpo = (resposta) => resposta?.data ?? {};

// ─── Ondas ──────────────────────────────────────────────────────────────────

/** Cria a onda. → `{onda: {id, nome, data, comecou}, mensagem}` (201). */
export async function criarOnda(escrita, { nome, data }) {
    return corpo(await axios.post(endereco(escrita, 'ondas'), { nome, data }));
}

/** Renomeia e/ou muda a data (a data só muda se a onda não começou). → `{onda, mensagem}`. */
export async function editarOnda(escrita, ondaId, campos) {
    return corpo(await axios.put(urlDaOnda(escrita, ondaId), campos));
}

/** Apaga a onda (só a que não começou): as ofertas dela voltam para "Sem onda". → `{mensagem}`. */
export async function apagarOnda(escrita, ondaId) {
    return corpo(await axios.delete(urlDaOnda(escrita, ondaId)));
}

/**
 * Põe as ofertas da trilha (`s-12`, `c-12-2`, `o-55`) na onda, ou tira (`ondaId` null).
 * → `{mudados: [], avisos: [], mensagem}`. Pôr um combo sem a simples traz a simples junto (vem em `avisos`).
 */
export async function planejarItens(escrita, itens, ondaId) {
    return corpo(await axios.put(endereco(escrita, 'planejamento'), { itens, onda_id: ondaId ?? null }));
}

// ─── Mapeamento ─────────────────────────────────────────────────────────────

/** As opções do painel de mapear: o produto, os combos, os outros produtos e a marca de mapeado. */
export async function carregarMapeamento(escrita, produtoId) {
    return corpo(await axios.get(urlDoMapeamento(escrita, produtoId)));
}

/** O que vai ser criado com esta escolha — o servidor só calcula, não grava. */
export async function previaDoMapeamento(escrita, produtoId, escolha) {
    return corpo(await axios.post(`${urlDoMapeamento(escrita, produtoId)}/previa`, escolha));
}

/** "Concluir mapeamento": grava tudo ou nada. → `{criadas: [{chave, tipo, nome, skus}], descartadas, mensagem}`. */
export async function concluirMapeamento(escrita, produtoId, escolha) {
    return corpo(await axios.post(urlDoMapeamento(escrita, produtoId), escolha));
}

// ─── Erros ──────────────────────────────────────────────────────────────────

const SEM_CONEXAO = 'Não consegui falar com o servidor. Confira a conexão e tente de novo.';
const PADRAO = 'Não deu certo. Tente de novo.';

// O idioma do Laravel nesta instalação é o inglês: as mensagens padrão do framework (e as das regras de validação
// sem texto próprio) chegam em inglês. Essas não vão para a tela; as do serviço da trilha já vêm em português.
const DO_FRAMEWORK = /^(?:not found|forbidden|server error|unauthenticated|page expired|too many (?:attempts|requests)|this action is unauthorized|csrf token mismatch|the given data was invalid|no query results)\b|^the .+ (?:field|must|may|is)\b/i;

const texto = (m) => (typeof m === 'string' && m.trim() && !DO_FRAMEWORK.test(m.trim()) ? m.trim() : '');

/**
 * O texto do erro para a pessoa. O 422 traz a primeira mensagem de `errors`
 * (a regra do servidor, em português); os outros, o `message` — ou, quando ele
 * é o padrão do framework, um texto nosso pelo código.
 */
export function mensagemDoErro(erro) {
    if (erro instanceof ErroDaTrilha) return erro.message;
    const resposta = erro?.response;
    if (!resposta) return erro?.isAxiosError || erro?.request ? SEM_CONEXAO : PADRAO;

    const { status, data } = resposta;
    if (status === 422) {
        const primeira = Object.values(data?.errors ?? {}).flat().map(texto).find(Boolean);

        return primeira || texto(data?.message) || 'Confira o que foi preenchido e tente de novo.';
    }
    if (status === 419) return 'A sessão expirou. Recarregue a página e tente de novo.';
    if (status === 401) return 'Sua sessão terminou. Entre de novo para continuar.';
    if (status === 403) return texto(data?.message) || 'Você não tem permissão para mudar esta trilha.';
    if (status === 404) return 'Não achei o que você pediu: talvez tenha sido apagado. Recarregue a página.';
    if (status === 429) return 'Muitos pedidos seguidos. Espere um minuto e tente de novo.';
    if (status >= 500) return 'O servidor falhou. Tente de novo em instantes.';

    return texto(data?.message) || PADRAO;
}

// ─── Recarga ────────────────────────────────────────────────────────────────

/** Os dados da trilha que mudam com uma escrita (o `gerado_em` vai junto, para o "Atualizado em" não mentir). */
export const CAMPOS_DA_RECARGA = ['produtos', 'ofertas', 'ondas', 'gerado_em'];

// A recarga em voo (só a última vale) e quem está esperando por ela.
let geracaoDaRecarga = 0;
let cancelarRecargaEmVoo = null;
let esperandoARecarga = [];

function terminarARecarga() {
    const todos = esperandoARecarga;
    esperandoARecarga = [];
    todos.forEach((resolver) => resolver());
}

/**
 * Recarrega os dados da trilha sem sair do lugar (o painel aberto e a etapa
 * escolhida ficam: o Inertia preserva o estado na recarga). Termina quando a
 * resposta chegou — quem precisa da onda nova nas props espera por ela.
 *
 * `replace: true`: a recarga é assíncrona e pede a URL de agora. Se a pessoa
 * trocar de painel antes da resposta (a tela grava a busca com `replaceState`),
 * a URL da resposta fica diferente da barra e o Inertia EMPILHARIA uma entrada
 * no histórico com a URL velha. Com `replace` ele só a substitui — e quem chama
 * regrava a URL de agora depois (`Trilha/Empresa`).
 *
 * Só a última vale: duas escritas seguidas pedem duas recargas, e o Inertia
 * aplica as respostas na ordem em que CHEGAM — a mais velha, chegando depois,
 * apagaria a mais nova. Por isso a recarga anterior ainda no ar é cancelada (o
 * Inertia entrega o `cancel` no `onCancelToken`, na hora do pedido), e quem
 * esperava por ela passa a esperar pela nova: todas as promessas terminam juntas,
 * quando a última resposta chega.
 *
 * @returns {Promise<void>}
 */
export function recarregar() {
    return new Promise((resolve) => {
        const minha = ++geracaoDaRecarga;
        // Avança a geração ANTES de cancelar: o `onFinish` que o cancelamento dispara já se sabe vencido.
        const anterior = cancelarRecargaEmVoo;
        cancelarRecargaEmVoo = null;
        anterior?.();
        esperandoARecarga.push(resolve);

        let saiu = false;
        router.reload({
            only: CAMPOS_DA_RECARGA,
            preserveScroll: true,
            replace: true,
            onCancelToken: ({ cancel }) => {
                saiu = true;
                // Uma recarga mais nova já saiu (não deveria: o Inertia chama isto na hora): esta nem vale.
                if (minha === geracaoDaRecarga) cancelarRecargaEmVoo = cancel;
                else cancel();
            },
            onFinish: () => {
                if (minha !== geracaoDaRecarga) return;
                cancelarRecargaEmVoo = null;
                terminarARecarga();
            },
        });

        // Nenhum pedido saiu (fora do navegador, ou uma visita barrada no `before`): ninguém fica esperando à toa.
        if (!saiu && minha === geracaoDaRecarga) terminarARecarga();
    });
}
