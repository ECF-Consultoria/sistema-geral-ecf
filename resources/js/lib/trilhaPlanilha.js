// ─── Trilha de produtos — a planilha por categoria (F7, 3ª entrega) ─────────
//
// Funções PURAS (sem React, sem axios, sem classe do Tailwind) da gaveta
// `e:planilha` (`Components/Trilha/Cadastro/PlanilhaPorCategoria.jsx`). O desenho
// fechado é o §17 de `.planning/trilha/ENTREGA-3-DESENHO.md`:
//
// 1. A equipe escolhe de 1 a 10 categorias e baixa o modelo vazio (uma aba por
//    categoria, já com os campos da ficha técnica de cada uma).
// 2. Preenche e envia: a PRÉVIA é o gravar desfeito (D24) — o servidor roda as
//    mesmas transações por produto e as desfaz. Nada grava, nada enfileira.
// 3. "Gravar N produtos (M ficam de fora)" manda o MESMO arquivo, com a versão de
//    cada produto que já existia (`versoes[produto_id]`, a da prévia — F6): o que
//    mudou no meio do caminho volta recusado, os outros gravam.
//
// A prévia e o gravar respondem a MESMA forma (§17.4):
//   {gravado, abas: [{categoria_id, categoria_nome, linhas}], produtos: [{chave, aba, linhas, produto_id, nome,
//    codigo, situacao, variacoes: {novas, atualizadas}, erros, avisos, faltas, versao}], totais, mensagem}
// com `erros` não vazio ⇔ `situacao = recusado` (o produto com erro fica INTEIRO de fora).

import { erroDoDownload } from './modeloCompleto.js';

/** O teto do arquivo (§17.7): o servidor recusa acima disso; aqui a pessoa sabe antes de enviar. */
export const LIMITE_DO_ARQUIVO_MB = 2;
export const LIMITE_DO_ARQUIVO = LIMITE_DO_ARQUIVO_MB * 1024 * 1024;

/** O nome do download quando a resposta não diz qual é. */
export const ARQUIVO_DA_TRILHA = 'planilha-da-trilha.xlsx';

/** O que o seletor de arquivo aceita (a extensão e o tipo do .xlsx). */
export const TIPOS_ACEITOS = '.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

const MB = (bytes) => (bytes / (1024 * 1024)).toLocaleString('pt-BR', { maximumFractionDigits: 1 });

/**
 * Confere o arquivo escolhido ANTES de enviar (o servidor confere de novo: mimes + extensão, 2 MB). → a frase do
 * problema, ou null quando pode ir.
 */
export function conferirArquivo(arquivo) {
    if (!arquivo) return 'Escolha a planilha preenchida.';
    const nome = String(arquivo.name ?? '');
    if (!/\.xlsx$/i.test(nome)) return 'Envie a planilha em .xlsx — a mesma que foi baixada aqui, preenchida.';
    const tamanho = Number(arquivo.size) || 0;
    if (tamanho === 0) return 'O arquivo está vazio.';
    if (tamanho > LIMITE_DO_ARQUIVO) return `A planilha tem ${MB(tamanho)} MB e o limite é ${LIMITE_DO_ARQUIVO_MB} MB. Divida em duas.`;

    return null;
}

/**
 * Uma cópia do arquivo em memória, tirada na hora da escolha: o gravar manda EXATAMENTE os bytes que a prévia viu
 * (se a pessoa mexer no arquivo no disco depois de enviar, o navegador não relê o novo — e nem falha ao reler).
 */
export async function copiaDoArquivo(arquivo) {
    if (!arquivo || typeof arquivo.arrayBuffer !== 'function' || typeof File === 'undefined') return arquivo;
    const bytes = await arquivo.arrayBuffer();

    return new File([bytes], arquivo.name, { type: arquivo.type || '', lastModified: arquivo.lastModified ?? Date.now() });
}

/** O corpo da prévia: só o arquivo (multipart `arquivo`). */
export function formularioDaPrevia(arquivo) {
    const dados = new FormData();
    dados.append('arquivo', arquivo);

    return dados;
}

/** As versões dos produtos que JÁ EXISTIAM na prévia: `{produto_id: versao}` (o novo não tem). */
export function versoesDaPrevia(previa) {
    const saida = {};
    for (const p of produtosDa(previa)) {
        const id = p?.produto_id;
        if (id === null || id === undefined || id === '' || !p?.versao) continue;
        saida[String(id)] = String(p.versao);
    }

    return saida;
}

/** O corpo do gravar: o MESMO arquivo da prévia e `versoes[{produto_id}]=versao` de cada existente. */
export function formularioDoGravar(arquivo, previa) {
    const dados = formularioDaPrevia(arquivo);
    for (const [id, versao] of Object.entries(versoesDaPrevia(previa))) dados.append(`versoes[${id}]`, versao);

    return dados;
}

// ─── A situação de cada produto ─────────────────────────────────────────────

/** As quatro situações (§17.4), com o rótulo da prévia, o do resultado e o tom da pílula. */
export const SITUACOES = {
    novo: { rotulo: 'Novo', gravado: 'Criado', tom: 'ok' },
    atualizado: { rotulo: 'Atualizado', gravado: 'Atualizado', tom: 'run' },
    sem_mudanca: { rotulo: 'Sem mudança', gravado: 'Sem mudança', tom: 'neutro' },
    recusado: { rotulo: 'Fica de fora', gravado: 'Ficou de fora', tom: 'bad' },
};

const listaDe = (v) => (Array.isArray(v) ? v : []);
const produtosDa = (previa) => listaDe(previa?.produtos);

/**
 * A situação do produto. Erro ⇔ recusado (contrato): com erro, ele é recusado mesmo que a situação diga outra
 * coisa; situação desconhecida sem erro conta como "sem mudança" (nada a gravar, nada a esconder).
 */
export function situacaoDoProduto(produto) {
    if (listaDe(produto?.erros).length > 0) return 'recusado';

    return Object.hasOwn(SITUACOES, produto?.situacao) ? produto.situacao : 'sem_mudanca';
}

/** O rótulo e o tom da pílula da situação (o resultado diz no passado: "Criado", "Ficou de fora"). */
export function rotuloDaSituacao(produto, gravado = false) {
    const chave = situacaoDoProduto(produto);
    const s = SITUACOES[chave];

    return { chave, rotulo: gravado ? s.gravado : s.rotulo, tom: s.tom };
}

/**
 * As contas da prévia (ou do resultado), pelos PRODUTOS — a lista é a verdade da tela; sem lista, os `totais`.
 * `gravaveis` = os que mudam algo (novos + atualizados); `deFora` = os recusados.
 */
export function contarPrevia(previa) {
    const conta = { novos: 0, atualizados: 0, sem_mudanca: 0, recusados: 0 };
    if (Array.isArray(previa?.produtos)) {
        const campo = { novo: 'novos', atualizado: 'atualizados', sem_mudanca: 'sem_mudanca', recusado: 'recusados' };
        for (const p of previa.produtos) conta[campo[situacaoDoProduto(p)]] += 1;
    } else {
        for (const k of Object.keys(conta)) conta[k] = Math.max(0, Number(previa?.totais?.[k]) || 0);
    }
    const total = conta.novos + conta.atualizados + conta.sem_mudanca + conta.recusados;

    return { ...conta, total, gravaveis: conta.novos + conta.atualizados, deFora: conta.recusados };
}

const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;

/** Dá para gravar: a prévia ainda não foi gravada e algum produto muda. */
export const podeGravar = (previa) => !!previa && !previa.gravado && contarPrevia(previa).gravaveis > 0;

/** "Gravar 5 produtos (2 ficam de fora)" — o botão do rodapé. Nada a gravar: "Nada para gravar". */
export function textoDoGravar(previa) {
    const { gravaveis, deFora } = contarPrevia(previa);
    if (gravaveis === 0) return 'Nada para gravar';
    const base = `Gravar ${plural(gravaveis, 'produto', 'produtos')}`;
    if (deFora === 0) return base;

    return `${base} (${deFora} ${deFora === 1 ? 'fica' : 'ficam'} de fora)`;
}

/** As pílulas do resumo, na ordem da tela; só as que têm alguém. */
export function pilulasDoResumo(previa) {
    const c = contarPrevia(previa);
    const gravado = !!previa?.gravado;
    const fora = (n) => (gravado ? (n === 1 ? 'ficou' : 'ficaram') : (n === 1 ? 'fica' : 'ficam'));

    return [
        c.novos && { chave: 'novo', tom: 'ok', texto: gravado ? plural(c.novos, 'criado', 'criados') : plural(c.novos, 'novo', 'novos') },
        c.atualizados && { chave: 'atualizado', tom: 'run', texto: plural(c.atualizados, 'atualizado', 'atualizados') },
        c.sem_mudanca && { chave: 'sem_mudanca', tom: 'neutro', texto: `${c.sem_mudanca} sem mudança` },
        c.recusados && { chave: 'recusado', tom: 'bad', texto: `${c.recusados} ${fora(c.recusados)} de fora` },
    ].filter(Boolean);
}

/**
 * A frase do resumo: a do servidor (`mensagem`) quando vem; senão, montada das contas. Vai também para o aviso
 * depois de gravar.
 */
export function resumoDaPrevia(previa) {
    const m = typeof previa?.mensagem === 'string' ? previa.mensagem.trim() : '';
    if (m) return m;
    const c = contarPrevia(previa);
    if (c.total === 0) return 'Nenhum produto na planilha.';
    const partes = pilulasDoResumo(previa).map((p) => p.texto);

    return `${partes.length > 1 ? `${partes.slice(0, -1).join(', ')} e ${partes[partes.length - 1]}` : partes[0]}.`;
}

// ─── Onde está na planilha ──────────────────────────────────────────────────

/** "linha 4", "linhas 4 a 6", "linhas 4 a 6 e 9" — as linhas do produto, com as seguidas juntas. */
export function linhasEmTexto(linhas) {
    const nums = [...new Set(listaDe(linhas).map(Number).filter((n) => Number.isInteger(n) && n > 0))].sort((a, b) => a - b);
    if (nums.length === 0) return '';
    if (nums.length === 1) return `linha ${nums[0]}`;
    const faixas = [];
    for (const n of nums) {
        const ultima = faixas[faixas.length - 1];
        if (ultima && n === ultima[1] + 1) ultima[1] = n;
        else faixas.push([n, n]);
    }
    const partes = faixas.map(([a, b]) => (a === b ? `${a}` : b === a + 1 ? `${a}, ${b}` : `${a} a ${b}`));

    return `linhas ${partes.length > 1 ? `${partes.slice(0, -1).join(', ')} e ${partes[partes.length - 1]}` : partes[0]}`;
}

/** "Linha 5 · Variação" (o erro do produto inteiro vem sem coluna: "Linha 4"). */
export function ondeEmTexto(item) {
    const linha = Number(item?.linha);
    const coluna = typeof item?.coluna === 'string' ? item.coluna.trim() : '';
    const partes = [Number.isInteger(linha) && linha > 0 ? `Linha ${linha}` : '', coluna].filter(Boolean);

    return partes.join(' · ');
}

/** "6 variações novas", "1 variação nova e 2 atualizadas" — vazio quando nenhuma muda. */
export function variacoesEmTexto(variacoes) {
    const novas = Math.max(0, Number(variacoes?.novas) || 0);
    const atualizadas = Math.max(0, Number(variacoes?.atualizadas) || 0);
    const partes = [];
    if (novas) partes.push(novas === 1 ? '1 variação nova' : `${novas} variações novas`);
    if (atualizadas) partes.push(novas ? `${atualizadas} ${atualizadas === 1 ? 'atualizada' : 'atualizadas'}` : (atualizadas === 1 ? '1 variação atualizada' : `${atualizadas} variações atualizadas`));

    return partes.join(' e ');
}

/**
 * Os produtos agrupados pela aba (a categoria), na ordem das `abas`; o produto de uma aba que não veio na lista vai
 * num grupo no fim, com o id dela como nome. → `[{id, nome, linhas, produtos}]`.
 */
export function produtosPorAba(previa, produtos = null) {
    const lista = produtos ?? produtosDa(previa);
    const grupos = new Map();
    for (const a of listaDe(previa?.abas)) {
        const id = String(a?.categoria_id ?? '');
        if (!grupos.has(id)) grupos.set(id, { id, nome: String(a?.categoria_nome || id || 'Sem aba'), linhas: Number(a?.linhas) || 0, produtos: [] });
    }
    for (const p of lista) {
        const id = String(p?.aba ?? '');
        if (!grupos.has(id)) grupos.set(id, { id, nome: id || 'Sem aba', linhas: 0, produtos: [] });
        grupos.get(id).produtos.push(p);
    }

    return [...grupos.values()].filter((g) => g.produtos.length > 0);
}

/** Os filtros da lista da prévia (só aparecem quando separam alguma coisa). */
export const FILTROS = [
    { valor: 'todos', rotulo: 'Todos' },
    { valor: 'de_fora', rotulo: 'Ficam de fora' },
    { valor: 'com_aviso', rotulo: 'Com aviso' },
];

/** A lista pelo filtro: `de_fora` = recusados; `com_aviso` = com aviso (e não recusados). */
export function filtrarProdutos(produtos, filtro = 'todos') {
    const lista = listaDe(produtos);
    if (filtro === 'de_fora') return lista.filter((p) => situacaoDoProduto(p) === 'recusado');
    if (filtro === 'com_aviso') return lista.filter((p) => situacaoDoProduto(p) !== 'recusado' && listaDe(p?.avisos).length > 0);

    return lista;
}

/** Quantos em cada filtro (o rótulo diz "Ficam de fora (2)"). */
export function contagemDosFiltros(produtos) {
    return Object.fromEntries(FILTROS.map((f) => [f.valor, filtrarProdutos(produtos, f.valor).length]));
}

// ─── Erros ──────────────────────────────────────────────────────────────────

/** O 413 que não passou pelo Laravel (o servidor web corta antes): a frase do limite. */
export const MSG_ARQUIVO_GRANDE = `A planilha passou do tamanho que o servidor aceita (${LIMITE_DO_ARQUIVO_MB} MB). Divida em duas.`;

/**
 * O erro do ARQUIVO no envio (prévia ou gravar): 422 `errors.arquivo` (não é .xlsx, > 2 MB, sem aba da trilha,
 * > 500 linhas, marca de outro modelo — §17.3) ou o 413 cru. → a frase, ou null (os outros erros são os de sempre,
 * `mensagemDoErro`).
 */
export function erroDoArquivo(erro) {
    const resposta = erro?.response;
    if (resposta?.status === 413) return MSG_ARQUIVO_GRANDE;
    if (resposta?.status !== 422) return null;
    const erros = resposta.data?.errors ?? {};
    const doArquivo = Object.entries(erros).find(([campo]) => campo === 'arquivo' || campo.startsWith('arquivo.'));
    const primeira = doArquivo ? [doArquivo[1]].flat().find((m) => typeof m === 'string' && m.trim()) : null;

    return primeira ? primeira.trim() : null;
}

/** O JSON de um corpo em texto (o erro do download chega dentro de um blob); null quando não é JSON. */
export function jsonDoCorpo(corpo) {
    if (corpo && typeof corpo === 'object') return corpo;
    try {
        const dados = JSON.parse(String(corpo ?? ''));

        return dados && typeof dados === 'object' ? dados : null;
    } catch {
        return null;
    }
}

/**
 * O erro do download: o 422 (`errors.categorias`, a categoria recusada) chega como JSON DENTRO do blob — `corpo` é
 * o texto dele. → a frase do servidor, ou null (os outros códigos são os de sempre, `mensagemDoErro`).
 */
export function erroDoBaixar(status, corpo) {
    if (status !== 422) return null;
    const m = erroDoDownload(corpo);

    return typeof m === 'string' && m.trim() ? m.trim() : 'Não deu para montar a planilha com essas categorias. Confira e tente de novo.';
}

// ─── A guarda ───────────────────────────────────────────────────────────────

/**
 * Há o que perder ao fechar: uma prévia conferida, com algo a gravar, ainda não gravada (e nenhum envio no ar — o
 * gravar em andamento termina sozinho e avisa).
 */
export const planilhaPendente = ({ previa = null, gravando = false } = {}) => !gravando && podeGravar(previa);
