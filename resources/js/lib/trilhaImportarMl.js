// ─── Trilha de produtos — importar os anúncios da loja (F8, 3ª entrega) ─────
//
// Funções PURAS (sem React, sem axios, sem classe do Tailwind) da gaveta `e:ml`
// (`Components/Trilha/Cadastro/ImportarDoMercadoLivre.jsx`). O desenho fechado é
// o §18 de `.planning/trilha/ENTREGA-3-DESENHO.md`:
//
// 1. A lista é o acervo da loja (lido uma vez por dia; listar não chama o ML).
//    Cada anúncio diz se o produto dele JÁ está na Lista (`na_lista`: importado
//    antes, publicado a partir de um produto, ligado a um, ou com o SKU de um) —
//    esse não se marca: "Já está na Lista · Abrir". Não há prévia separada (D38).
// 2. "Importar N" (até 20 por clique) manda os MLBs; cada um vira um Job na fila
//    da IA (D36). A tela pergunta o estado a cada 2 s enquanto há algo andando;
//    cada linha mostra o passo e, no fim, "novo" (com o título que o agente
//    escreveu e o que ainda falta na Lista), "já estava na Lista", ou o motivo
//    de ter ficado de fora (`recusado`/`erro`: nada foi gravado).
// 3. Ao terminar, a Lista recarrega.
//
// Os rótulos de ordem e situação são os do importador de 28/10 (`produtosDoMl.js`)
// — o mesmo texto nas duas portas. O "já está no …" de lá NÃO vem: aqui o lugar
// é a Lista da trilha.
//
// Sem nenhum texto que cite o Portal (a trilha o substitui) nem o nome do
// marketplace: este arquivo não está na pasta `Cadastro/`, e o cliente baixa o
// resto do JS da trilha (o nome mora só na gaveta, que é `lazy`).
//
// Contrato (§18.4):
//   lista  {conectado, atualizado_em, total, pagina, paginas, limite, itens: [{mlb, titulo, thumbnail, permalink, preco,
//           tipo, ativo, status, catalogo, estoque, vendas_total, visitas_30d, fotos, saude_nivel, feito_pela_ecf,
//           na_lista: ?{produto_id, nome, como}, importacao: ?{situacao}}]}
//   estado {em_andamento, totais: {novos, na_lista, recusados, erros}, itens: [{mlb, titulo, situacao, passo,
//           produto_id, nome, codigo, variacoes, fotos: {trazidas, falharam}, ficha: {gravados, recusados, pela_ia},
//           titulo, avisos, faltas, mensagem}]}

import { FILTROS_SITUACAO, ORDENS, alternar, fmtInt, qualidadeNoMl } from './produtosDoMl.js';

export { FILTROS_SITUACAO, ORDENS, alternar, fmtInt, qualidadeNoMl };

/** O teto por clique (§18.6): o servidor recusa acima disso (422 em `mlbs`). */
export const MAX_POR_IMPORTACAO = 20;

/** De quanto em quanto tempo a gaveta pergunta o estado enquanto há algo andando (§18.5). */
export const CONSULTA_DO_ESTADO_MS = 2000;

/**
 * Quantas perguntas seguidas, no máximo (30 min a cada 2 s): a aba esquecida aberta não consulta o servidor para
 * sempre. Passou disso, a gaveta para de acompanhar e oferece "Acompanhar de novo" — a importação segue no servidor.
 */
export const MAX_VOLTAS_DO_ESTADO = 900;

/** A espera depois da última letra digitada na busca, antes de pedir a lista. */
export const ESPERA_DA_BUSCA_MS = 350;

/** Os filtros com que a gaveta abre: todos os anúncios, da maior oportunidade, sem os que já estão na Lista. */
export const FILTROS_INICIAIS = Object.freeze({ q: '', ordem: 'oportunidade', situacao: 'todos', mostrarNaLista: false, pagina: 1 });

const listaDe = (v) => (Array.isArray(v) ? v : []);
const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;

/** "a, b e c". */
const juntar = (partes) => (partes.length > 1 ? `${partes.slice(0, -1).join(', ')} e ${partes[partes.length - 1]}` : partes[0] ?? '');

// ─── A lista ────────────────────────────────────────────────────────────────

/**
 * A busca da lista (§18.3: `?q=&ordem=&situacao=&pagina=&mostrar_na_lista=`), na ordem do contrato. O que não vale
 * nada sai como null (quem monta a URL o tira): busca vazia e "mostrar os que já estão na Lista" desligado. Ordem e
 * situação desconhecidas voltam ao padrão; a página é inteira e começa em 1.
 */
export function parametrosDaLista(filtros = {}) {
    const f = { ...FILTROS_INICIAIS, ...(filtros ?? {}) };
    const q = String(f.q ?? '').trim();
    const ordem = ORDENS.some((o) => o.valor === f.ordem) ? f.ordem : FILTROS_INICIAIS.ordem;
    const situacao = FILTROS_SITUACAO.some((s) => s.valor === f.situacao) ? f.situacao : FILTROS_INICIAIS.situacao;
    const pagina = Math.max(1, Math.trunc(Number(f.pagina)) || 1);

    return { q: q || null, ordem, situacao, pagina, mostrar_na_lista: f.mostrarNaLista ? 1 : null };
}

/**
 * Quantos anúncios cabem num clique: o `limite` da lista (§18.4 — 20, o mesmo teto do servidor; a página tem 30), só
 * para BAIXO do teto da tela. Sem ele (ou estranho), 20.
 */
export function tetoDaImportacao(lista) {
    const limite = Math.trunc(Number(lista?.limite));

    return Number.isInteger(limite) && limite > 0 ? Math.min(limite, MAX_POR_IMPORTACAO) : MAX_POR_IMPORTACAO;
}

/** A paginação da lista: `{pagina, paginas, anterior, seguinte}` (anterior/seguinte = o número, ou null). */
export function paginacaoDa(lista) {
    const paginas = Math.max(1, Math.trunc(Number(lista?.paginas)) || 1);
    const pagina = Math.min(paginas, Math.max(1, Math.trunc(Number(lista?.pagina)) || 1));

    return { pagina, paginas, anterior: pagina > 1 ? pagina - 1 : null, seguinte: pagina < paginas ? pagina + 1 : null };
}

/** O endereço da miniatura em https (o acervo pode guardar `http://`, e a página é https). */
export function emHttps(url) {
    if (typeof url !== 'string' || !url.trim()) return null;

    return url.trim().replace(/^http:\/\//i, 'https://');
}

/** Como o produto do anúncio já está na Lista (`na_lista.como`, §18.4 / D38). */
export const COMO_ESTA_NA_LISTA = {
    importado: 'Importado antes',
    publicado: 'O anúncio saiu de um produto da Lista',
    ligado: 'Ligado a um produto da Lista',
    sku: 'O SKU já é de um produto da Lista',
};

/** "Importado antes: Poltrona Decorativa" — o porquê de o anúncio não se marcar. */
export function textoDoNaLista(naLista) {
    if (!naLista) return '';
    const como = COMO_ESTA_NA_LISTA[naLista.como] ?? 'Já é um produto da Lista';
    const nome = typeof naLista.nome === 'string' ? naLista.nome.trim() : '';

    return nome ? `${como}: ${nome}` : como;
}

/** "MLB1 · Clássico · 12 em estoque" (o preço vai à parte, formatado pela tela). */
export function linhaDoAnuncio(anuncio) {
    const partes = [anuncio?.mlb, anuncio?.tipo].filter((p) => typeof p === 'string' && p.trim());
    if (anuncio?.estoque !== null && anuncio?.estoque !== undefined && anuncio?.estoque !== '') partes.push(`${fmtInt(anuncio.estoque)} em estoque`);

    return partes.join(' · ');
}

/** "30 vendas · 410 visitas em 30 dias · 8 fotos" — os números do anúncio, só os que vieram. */
export function numerosDoAnuncio(anuncio) {
    const partes = [];
    const n = (v) => (v === null || v === undefined || v === '' || Number.isNaN(Number(v)) ? null : Number(v));
    const vendas = n(anuncio?.vendas_total);
    const visitas = n(anuncio?.visitas_30d);
    const fotos = n(anuncio?.fotos);
    if (vendas !== null) partes.push(`${fmtInt(vendas)} ${vendas === 1 ? 'venda' : 'vendas'}`);
    if (visitas !== null) partes.push(`${fmtInt(visitas)} ${visitas === 1 ? 'visita' : 'visitas'} em 30 dias`);
    if (fotos !== null) partes.push(plural(fotos, 'foto', 'fotos'));

    return partes;
}

// ─── A situação de cada anúncio na importação ───────────────────────────────

/** As seis situações do estado (§18.4), com o rótulo da pílula e o tom. */
export const SITUACOES_DA_IMPORTACAO = {
    fila: { rotulo: 'Na fila', tom: 'neutro' },
    importando: { rotulo: 'Importando', tom: 'run' },
    novo: { rotulo: 'Novo', tom: 'ok' },
    na_lista: { rotulo: 'Já estava na Lista', tom: 'neutro' },
    recusado: { rotulo: 'Ficou de fora', tom: 'bad' },
    erro: { rotulo: 'Falhou', tom: 'bad' },
};

const ANDANDO = new Set(['fila', 'importando']);

/** A situação do item (a desconhecida conta como "na fila": nada terminou que a tela saiba). */
export const situacaoDoItem = (item) => (Object.hasOwn(SITUACOES_DA_IMPORTACAO, item?.situacao) ? item.situacao : 'fila');

/** O item ainda anda (na fila ou importando). */
export const itemAndando = (item) => ANDANDO.has(situacaoDoItem(item));

/** O rótulo e o tom da pílula do item. */
export function rotuloDoItem(item) {
    const chave = situacaoDoItem(item);

    return { chave, ...SITUACOES_DA_IMPORTACAO[chave] };
}

/** Os itens do estado por MLB (a lista de anúncios pergunta "este está andando?"). */
export function estadoPorMlb(estado) {
    return Object.fromEntries(listaDe(estado?.itens).filter((i) => i?.mlb).map((i) => [i.mlb, i]));
}

/** O que impede marcar: já está na Lista, ou está na fila/importando/acabou de virar produto (no estado ou na lista). */
const SEM_MARCAR = new Set(['fila', 'importando', 'novo', 'na_lista']);

/**
 * O anúncio pode ser marcado: o produto dele ainda não está na Lista e ele não está sendo importado (nem acabou de
 * ser). O que ficou de fora ou falhou pode ser marcado de novo.
 */
export function podeMarcar(anuncio, porMlb = {}) {
    if (!anuncio?.mlb || anuncio.na_lista) return false;
    if (SEM_MARCAR.has(anuncio.importacao?.situacao)) return false;

    return !SEM_MARCAR.has(porMlb?.[anuncio.mlb]?.situacao);
}

/**
 * O "Marcar a página": se todos os marcáveis da página já estão marcados, desmarca-os; senão, marca os que cabem
 * (até o teto). Devolve um Set novo.
 */
export function alternarPagina(selecionados, anuncios, porMlb = {}, teto = MAX_POR_IMPORTACAO) {
    const marcaveis = listaDe(anuncios).filter((a) => podeMarcar(a, porMlb)).map((a) => a.mlb);
    const novo = new Set(selecionados);
    if (marcaveis.length > 0 && marcaveis.every((m) => novo.has(m))) {
        marcaveis.forEach((m) => novo.delete(m));

        return novo;
    }
    for (const m of marcaveis) {
        if (novo.size >= teto) break;
        novo.add(m);
    }

    return novo;
}

/** Todos os marcáveis da página estão marcados (a caixa "Marcar a página" aparece marcada). */
export function paginaMarcada(selecionados, anuncios, porMlb = {}) {
    const marcaveis = listaDe(anuncios).filter((a) => podeMarcar(a, porMlb));

    return marcaveis.length > 0 && marcaveis.every((a) => selecionados?.has?.(a.mlb));
}

/** "Importar 3 anúncios" — o botão do rodapé. Nada marcado: "Importar". */
export function textoDoImportar(n) {
    const quantos = Math.max(0, Number(n) || 0);

    return quantos === 0 ? 'Importar' : `Importar ${plural(quantos, 'anúncio', 'anúncios')}`;
}

// ─── O texto de cada linha da importação ────────────────────────────────────

/** "Trazendo as fotos…" (o passo do servidor, com as reticências uma vez só). */
const comReticencias = (texto) => `${String(texto).trim().replace(/(?:…|\.{3})$/, '')}…`;

/** A frase da linha: o passo enquanto anda; depois, o que aconteceu (a mensagem do servidor quando vem). */
export function textoDoItem(item) {
    const s = situacaoDoItem(item);
    const mensagem = typeof item?.mensagem === 'string' ? item.mensagem.trim() : '';
    const passo = typeof item?.passo === 'string' ? item.passo.trim() : '';
    if (s === 'fila') return 'Esperando a vez…';
    if (s === 'importando') return comReticencias(passo || 'Começando');
    if (s === 'novo') return mensagem || 'Produto criado.';
    if (s === 'na_lista') return mensagem || 'Já estava na Lista: nada mudou.';
    if (s === 'recusado') return mensagem || 'Ficou de fora: nada foi gravado.';

    return mensagem || 'Não deu certo, e nada foi gravado. Tente de novo.';
}

/**
 * O resumo do produto criado: `['POLT-01', '6 variações', '9 fotos (1 falhou)', 'ficha com 14 campos — 5 pela IA,
 * 2 ficaram de fora']`, só com o que veio.
 */
export function detalhesDoNovo(item) {
    const partes = [];
    if (typeof item?.codigo === 'string' && item.codigo.trim()) partes.push(item.codigo.trim());
    const variacoes = Number(item?.variacoes);
    if (Number.isInteger(variacoes) && variacoes > 0) partes.push(plural(variacoes, 'variação', 'variações'));

    if (item?.fotos && typeof item.fotos === 'object') {
        const trazidas = Math.max(0, Number(item.fotos.trazidas) || 0);
        const falharam = Math.max(0, Number(item.fotos.falharam) || 0);
        let fotos = trazidas > 0 ? plural(trazidas, 'foto', 'fotos') : 'sem fotos';
        if (falharam > 0) fotos += ` (${falharam} ${falharam === 1 ? 'falhou' : 'falharam'})`;
        partes.push(fotos);
    }

    if (item?.ficha && typeof item.ficha === 'object') {
        const gravados = Math.max(0, Number(item.ficha.gravados) || 0);
        const pelaIa = Math.max(0, Number(item.ficha.pela_ia) || 0);
        const recusados = Math.max(0, Number(item.ficha.recusados) || 0);
        const extras = [
            pelaIa > 0 && `${pelaIa} pela IA`,
            recusados > 0 && `${recusados} ${recusados === 1 ? 'ficou' : 'ficaram'} de fora`,
        ].filter(Boolean);
        let ficha = gravados > 0 ? `ficha com ${plural(gravados, 'campo', 'campos')}` : 'ficha vazia';
        if (extras.length) ficha += ` — ${extras.join(', ')}`;
        partes.push(ficha);
    }

    return partes;
}

/** Os avisos do item como texto (o servidor manda frases; um `{mensagem}` também serve). */
export function avisosDoItem(item) {
    return listaDe(item?.avisos)
        .map((a) => (typeof a === 'string' ? a : a?.mensagem))
        .filter((a) => typeof a === 'string' && a.trim())
        .map((a) => a.trim());
}

/** As faltas do produto na Lista (só as não vazias). */
export const faltasDoItem = (item) => listaDe(item?.faltas).filter((f) => typeof f === 'string' && f.trim());

// ─── O estado inteiro ───────────────────────────────────────────────────────

/**
 * As contas do estado, pelos ITENS (a lista é a verdade da tela, como na planilha); sem lista, os `totais`.
 * `terminados` = os que não andam mais; `andando` = fila + importando.
 */
export function contarEstado(estado) {
    const conta = { fila: 0, importando: 0, novos: 0, na_lista: 0, recusados: 0, erros: 0 };
    if (Array.isArray(estado?.itens)) {
        const campo = { fila: 'fila', importando: 'importando', novo: 'novos', na_lista: 'na_lista', recusado: 'recusados', erro: 'erros' };
        for (const item of estado.itens) conta[campo[situacaoDoItem(item)]] += 1;
    } else {
        for (const k of ['novos', 'na_lista', 'recusados', 'erros']) conta[k] = Math.max(0, Number(estado?.totais?.[k]) || 0);
    }
    const andando = conta.fila + conta.importando;
    const terminados = conta.novos + conta.na_lista + conta.recusados + conta.erros;

    return { ...conta, andando, terminados, total: andando + terminados };
}

/**
 * A frase do estado. Andando: "Importando 5 anúncios: 2 de 5 já terminaram." Terminado: "Importação terminada:
 * 3 produtos novos, 1 já estava na Lista e 1 ficou de fora." Vai também para o aviso da página no fim.
 */
export function resumoDoEstado(estado) {
    const c = contarEstado(estado);
    if (c.total === 0) return 'Nenhum anúncio sendo importado agora.';
    if (estado?.em_andamento || c.andando > 0) {
        return `Importando ${plural(c.total, 'anúncio', 'anúncios')}: ${c.terminados} de ${c.total} já ${c.terminados === 1 ? 'terminou' : 'terminaram'}.`;
    }
    const partes = [
        c.novos && plural(c.novos, 'produto novo', 'produtos novos'),
        c.na_lista && `${c.na_lista} já ${c.na_lista === 1 ? 'estava' : 'estavam'} na Lista`,
        c.recusados && `${c.recusados} ${c.recusados === 1 ? 'ficou' : 'ficaram'} de fora`,
        c.erros && `${c.erros} ${c.erros === 1 ? 'falhou' : 'falharam'}`,
    ].filter(Boolean);

    return `Importação terminada: ${juntar(partes)}.`;
}

/** O tom do aviso da página no fim: sucesso com produto novo (ou sem nada de errado); erro quando só deu errado. */
export function tipoDoAvisoFinal(estado) {
    const c = contarEstado(estado);

    return c.novos === 0 && c.recusados + c.erros > 0 ? 'erro' : 'sucesso';
}

/** A importação acabou de terminar (andava e não anda mais): hora de recarregar a Lista. */
export const terminou = (antes, depois) => !!antes?.em_andamento && !depois?.em_andamento;

/**
 * Os produtos que a importação já criou (ou achou na Lista) e que os dados da página ainda não têm: a Lista precisa
 * recarregar para o [Abrir] achar a gaveta deles. `jaPedidos`: os que já pediram uma recarga (não pede de novo).
 */
export function produtosQueFaltamNaLista(estado, idsNaLista = new Set(), jaPedidos = new Set()) {
    const faltam = [];
    for (const item of listaDe(estado?.itens)) {
        const s = situacaoDoItem(item);
        const id = Number(item?.produto_id);
        if ((s !== 'novo' && s !== 'na_lista') || !Number.isInteger(id) || id <= 0) continue;
        if (idsNaLista.has(id) || jaPedidos.has(id) || faltam.includes(id)) continue;
        faltam.push(id);
    }

    return faltam;
}

// ─── Erros e a guarda ───────────────────────────────────────────────────────

/**
 * O 422 do "Importar" (§18.3: nenhum, mais de 20, fora do acervo da empresa, conta desconectada) vem em
 * `errors.mlbs` (ou `mlbs.N`): a frase. → null para os outros (os de sempre, `mensagemDoErro`).
 */
export function erroDosMlbs(erro) {
    const resposta = erro?.response;
    if (resposta?.status !== 422) return null;
    const erros = resposta.data?.errors ?? {};
    const doCampo = Object.entries(erros).find(([campo]) => campo === 'mlbs' || campo.startsWith('mlbs.'));
    const primeira = doCampo ? [doCampo[1]].flat().find((m) => typeof m === 'string' && m.trim()) : null;

    return primeira ? primeira.trim() : null;
}

/**
 * Há o que avisar ao sair: uma importação andando. Fechar a gaveta não a para (ela roda no servidor), mas a gaveta
 * para de acompanhar — e a Lista só recarrega sozinha com ela aberta.
 */
export const importacaoPendente = (estado) => !!estado?.em_andamento;
