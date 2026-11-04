// ─── Trilha de produtos — as fotos que o CLIENTE envia (4ª entrega, fatia 4f) ─
//
// É a 1ª escrita da porta do cliente (§19.15 do desenho). No painel do produto
// (`p:`), a caixa "Fotos" mostra as fotos de cada cor e, enquanto o produto está
// na Lista (`produto.editavel`), deixa enviar e excluir. Depois disso, só leitura
// com o motivo que o servidor manda ("fale com a equipe").
//
// Aqui mora tudo o que a caixa decide SEM tocar na rede, para ser testado de
// verdade: montar as URLs a partir da base `cliente_escrita.fotos` (com o
// marcador `__PRODUTO__`), ler o quadro do servidor, conferir os arquivos antes
// de enviar (pelos `limites` do quadro), as contas do que falta e os textos de
// cada erro (409 = só leitura; 422/413 = os avisos do envio).
//
// Sem import nenhum, de propósito: o pedaço que o cliente baixa leva este
// arquivo, e nada da gaveta da equipe pode ir junto. Sem classe do Tailwind
// (arquivo `.js`: o Tailwind só varre `.jsx`). Os textos são do CLIENTE: nada de
// marketplace, Portal, Publicador nem "agente" (D59).

/** O marcador do id do produto na base `cliente_escrita.fotos` (`/portal/{pe}/trilha/produtos/__PRODUTO__/fotos`). */
export const MARCADOR_DO_PRODUTO = '__PRODUTO__';

/** O que a caixa diz quando o produto não aceita mais fotos e o servidor não mandou o porquê. */
export const MOTIVO_PADRAO = 'Para trocar as fotos, fale com a equipe.';

/** Os limites quando o quadro não traz algum (os mesmos do servidor hoje: `RegrasDoCadastro::imagens`). */
export const LIMITES_PADRAO = { tamanhoMb: 10, tipos: ['jpg', 'png', 'webp'], porEnvio: 12 };

export const MSG_SEM_CONEXAO = 'Não consegui falar com o servidor. Confira a conexão e tente de novo.';
export const MSG_GRANDE_DEMAIS = 'As fotos passam do tamanho que dá para enviar de uma vez. Envie menos fotos por vez, ou fotos menores.';
export const MSG_SUMIU = 'Não achei o que você pediu: talvez já tenha sido apagado. As fotos abaixo foram atualizadas.';
export const MSG_SEM_QUADRO = 'As fotos deste produto não estão disponíveis agora. Recarregue a página.';
export const MSG_CONFIRA = 'Confira as fotos escolhidas e tente de novo.';
export const MSG_PADRAO = 'Não deu certo. Tente de novo.';

const texto = (v) => (typeof v === 'string' ? v.trim() : '');

/** O id como vai na URL: número ou texto não vazio; o resto, null. */
function idDaUrl(id) {
    if (typeof id === 'number') return Number.isFinite(id) ? String(id) : null;
    const t = texto(id);

    return t || null;
}

// ─── As URLs (§19.15) ───────────────────────────────────────────────────────

/**
 * `GET` do quadro: a base com o id do produto no lugar do marcador. Sem base, sem o marcador (uma vez só), sem o
 * `/fotos` no fim ou sem id → null (a caixa não aparece: é só leitura do que já existe).
 */
export function urlDoQuadro(base, produtoId) {
    const limpa = texto(base).replace(/\/+$/, '');
    const id = idDaUrl(produtoId);
    if (!limpa || !id || limpa.split(MARCADOR_DO_PRODUTO).length !== 2 || !/\/fotos$/.test(limpa)) return null;

    return limpa.replace(MARCADOR_DO_PRODUTO, () => encodeURIComponent(id));
}

/** `POST {base sem /fotos}/variacoes/{variacao}/fotos` (multipart `imagens[]`). */
export function urlDoEnvio(base, produtoId, variacaoId) {
    const quadro = urlDoQuadro(base, produtoId);
    const variacao = idDaUrl(variacaoId);
    if (!quadro || !variacao) return null;

    return `${quadro.slice(0, -'/fotos'.length)}/variacoes/${encodeURIComponent(variacao)}/fotos`;
}

/** `DELETE {base sem /fotos}/variacoes/{variacao}/fotos/{imagem}`. */
export function urlDaExclusao(base, produtoId, variacaoId, imagemId) {
    const envio = urlDoEnvio(base, produtoId, variacaoId);
    const imagem = idDaUrl(imagemId);

    return envio && imagem ? `${envio}/${encodeURIComponent(imagem)}` : null;
}

/**
 * A foto GRANDE (o "abrir em outra aba"): a mesma rota da miniatura com `?original=1` (como a da equipe); sem ele, o
 * servidor manda a miniatura.
 */
export function urlDaFotoGrande(url) {
    const limpa = texto(url);
    if (!limpa) return null;
    if (/[?&]original=1(?:&|$)/.test(limpa)) return limpa;

    return `${limpa}${limpa.includes('?') ? '&' : '?'}original=1`;
}

// ─── O quadro ───────────────────────────────────────────────────────────────

const inteiro = (v, padrao) => (Number.isFinite(Number(v)) && v !== null && v !== '' ? Math.max(0, Math.floor(Number(v))) : padrao);
const positivo = (v, padrao) => (Number(v) > 0 ? Number(v) : padrao);

/** As extensões aceitas, em minúsculas e sem ponto (`jpeg` conta como `jpg`); vazio = as do padrão. */
function tiposDe(lista) {
    const tipos = (Array.isArray(lista) ? lista : [])
        .map((t) => texto(t).toLowerCase().replace(/^\./, ''))
        .map((t) => (t === 'jpeg' ? 'jpg' : t))
        .filter((t) => /^[a-z0-9]+$/.test(t));

    return tipos.length ? [...new Set(tipos)] : [...LIMITES_PADRAO.tipos];
}

/**
 * O quadro do servidor (§19.15) no formato da caixa — ou null quando a resposta não é um quadro (sem `cores`).
 *
 * `faltam` é o do SERVIDOR (a régua da Lista, `CompletudeDoProduto`): só sem ele a conta é `minimo - fotos`.
 * `maximo` nulo = sem teto conhecido (vale só o `por_envio`).
 *
 * @returns {?{produto: {id: *, nome: ?string, editavel: boolean, motivo: ?string},
 *   cores: Array<{variacaoId: *, rotulo: ?string, minimo: number, maximo: ?number, fotos: Array<{id: *, url: string}>, faltam: number}>,
 *   limites: {tamanhoMb: number, tipos: string[], porEnvio: number}}}
 */
export function lerQuadro(dados) {
    if (!dados || typeof dados !== 'object' || !Array.isArray(dados.cores)) return null;
    const produto = dados.produto && typeof dados.produto === 'object' ? dados.produto : {};
    const limites = dados.limites && typeof dados.limites === 'object' ? dados.limites : {};

    return {
        produto: {
            id: produto.id ?? null,
            nome: texto(produto.nome) || null,
            editavel: produto.editavel === true,
            motivo: texto(produto.motivo) || null,
        },
        cores: dados.cores
            .filter((c) => c && typeof c === 'object' && idDaUrl(c.variacao_id))
            .map((c) => {
                const fotos = (Array.isArray(c.fotos) ? c.fotos : [])
                    .filter((f) => f && idDaUrl(f.id) && texto(f.url))
                    .map((f) => ({ id: f.id, url: texto(f.url) }));
                const minimo = inteiro(c.minimo, 0);
                const maximo = Number(c.maximo) > 0 ? Math.floor(Number(c.maximo)) : null;

                return {
                    variacaoId: c.variacao_id,
                    rotulo: texto(c.rotulo) || null,
                    minimo,
                    maximo,
                    fotos,
                    faltam: inteiro(c.faltam, Math.max(0, minimo - fotos.length)),
                };
            }),
        limites: {
            tamanhoMb: positivo(limites.tamanho_mb, LIMITES_PADRAO.tamanhoMb),
            tipos: tiposDe(limites.tipos),
            porEnvio: Math.floor(positivo(limites.por_envio, LIMITES_PADRAO.porEnvio)),
        },
    };
}

/** O mesmo quadro, só para leitura, com o motivo (o do 409, o que o quadro já tinha ou o padrão). */
export function quadroSoLeitura(quadro, motivo = null) {
    if (!quadro) return quadro;

    return { ...quadro, produto: { ...quadro.produto, editavel: false, motivo: texto(motivo) || quadro.produto.motivo || MOTIVO_PADRAO } };
}

/** Quantas fotos ainda cabem na cor (sem teto conhecido: infinitas — o `por_envio` segura cada envio). */
export const cabemNaCor = (cor) => (cor?.maximo == null ? Number.POSITIVE_INFINITY : Math.max(0, cor.maximo - (cor.fotos?.length ?? 0)));

/** Quantas faltam no produto inteiro (a soma das cores). */
export const totalQueFalta = (quadro) => (quadro?.cores ?? []).reduce((soma, cor) => soma + (cor.faltam || 0), 0);

/** Como os textos chamam o lugar das fotos: a cor; sem cor, o produto (uma galeria só) ou a variação. */
export const ondeDaCor = (cor, quantasCores = 1) => (cor?.rotulo ? 'nesta cor' : quantasCores > 1 ? 'nesta variação' : 'neste produto');

/** O título da galeria: a cor; sem ela, "Fotos do produto" (uma só) ou "Sem cor". */
export const tituloDaCor = (cor, quantasCores = 1) => cor?.rotulo || (quantasCores > 1 ? 'Sem cor' : 'Fotos do produto');

/** "Faltam 2 fotos nesta cor" / "Falta 1 foto nesta cor" / "Fotos completas". */
export function textoDaFalta(cor, quantasCores = 1) {
    const n = cor?.faltam || 0;
    if (n <= 0) return 'Fotos completas';

    return `${n === 1 ? 'Falta 1 foto' : `Faltam ${n} fotos`} ${ondeDaCor(cor, quantasCores)}`;
}

/** A pílula da caixa: "completas" (verde) ou quantas faltam no produto (âmbar). */
export function resumoDasFotos(quadro) {
    const n = totalQueFalta(quadro);
    if (n <= 0) return { texto: 'completas', tom: 'ok' };

    return { texto: n === 1 ? 'falta 1' : `faltam ${n}`, tom: 'warn' };
}

/** "foto 2 de Natural" (sem cor: "foto 2") — o nome da foto para o leitor de tela e a confirmação. */
export const nomeDaFoto = (indice, rotulo = null) => `foto ${indice + 1}${rotulo ? ` de ${rotulo}` : ''}`;

/** A pergunta da confirmação no rodapé. A capa avisa que a 2ª passa a ser a capa. */
export function textoDaConfirmacao(indice, total, rotulo = null) {
    if (indice === 0 && total > 1) return `Excluir a capa${rotulo ? ` de ${rotulo}` : ''}? Ela sai na hora, e a 2ª foto passa a ser a capa.`;

    return `Excluir a ${nomeDaFoto(indice, rotulo)}? Ela sai na hora.`;
}

// ─── Os arquivos escolhidos, conferidos ANTES de enviar ─────────────────────

const NOME_DO_TIPO = { jpg: 'JPG', png: 'PNG', webp: 'WebP', gif: 'GIF', heic: 'HEIC', avif: 'AVIF' };
const TIPO_DO_MIME = { 'image/jpeg': 'jpg', 'image/jpg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'image/gif': 'gif', 'image/heic': 'heic', 'image/avif': 'avif' };
const MIME_DO_TIPO = { jpg: ['image/jpeg'], png: ['image/png'], webp: ['image/webp'], gif: ['image/gif'], heic: ['image/heic'], avif: ['image/avif'] };

/** "JPG, PNG ou WebP". */
export function rotuloDosTipos(tipos) {
    const nomes = tiposDe(tipos).map((t) => NOME_DO_TIPO[t] ?? t.toUpperCase());

    return nomes.length > 1 ? `${nomes.slice(0, -1).join(', ')} ou ${nomes[nomes.length - 1]}` : nomes[0];
}

/** O `accept` do seletor de arquivos: a extensão (e o `.jpeg` do `jpg`) e o tipo de cada formato. */
export function aceitaNoSeletor(tipos) {
    const partes = tiposDe(tipos).flatMap((t) => [`.${t}`, ...(t === 'jpg' ? ['.jpeg'] : []), ...(MIME_DO_TIPO[t] ?? [])]);

    return [...new Set(partes)].join(',');
}

/** A regra que a caixa escreve embaixo das fotos. */
export const textoDasRegras = (limites) => {
    const l = limites ?? LIMITES_PADRAO;

    return `${rotuloDosTipos(l.tipos)}, até ${l.tamanhoMb} MB cada, até ${l.porEnvio} fotos por envio. A 1ª foto é a capa.`;
};

/** "12,4 MB" (sem ",0"). */
export function emMb(bytes) {
    const mb = Math.round((Number(bytes) || 0) / 1024 / 1024 * 10) / 10;

    return `${String(mb).replace('.', ',')} MB`;
}

/** O formato do arquivo: pela extensão do nome; sem ela, pelo tipo que o navegador disse. */
function tipoDoArquivo(arquivo) {
    const nome = texto(arquivo?.name);
    const ponto = nome.lastIndexOf('.');
    const extensao = ponto > 0 ? nome.slice(ponto + 1).toLowerCase() : '';
    if (extensao) return extensao === 'jpeg' ? 'jpg' : extensao;

    return TIPO_DO_MIME[texto(arquivo?.type).toLowerCase()] ?? '';
}

const entreAspas = (arquivos) => arquivos.map((a) => `“${texto(a?.name) || 'sem nome'}”`).join(', ');

/**
 * Confere os arquivos escolhidos para UMA cor, pelos `limites` do quadro, antes de enviar (o servidor confere de
 * novo, pelo conteúdo, e é tudo ou nada):
 * - formato fora dos `tipos` ou acima de `tamanhoMb` não vai — com um aviso para cada caso;
 * - mais do que cabe na cor (`maximo`) ou mais do que o `porEnvio`: NADA vai, e o aviso diz quantas cabem.
 * Os que servem vão juntos, numa remessa só, com os avisos dos que ficaram de fora.
 *
 * @param {Array<{name: string, size?: number, type?: string}>|FileList} escolhidos
 * @returns {{arquivos: Array, avisos: string[]}}  `arquivos` vazio = não envia
 */
export function conferirArquivos(escolhidos, cor, limites = LIMITES_PADRAO, quantasCores = 1) {
    const lista = Array.from(escolhidos ?? []);
    if (!lista.length) return { arquivos: [], avisos: [] };
    const tipos = tiposDe(limites?.tipos);
    const mb = positivo(limites?.tamanhoMb, LIMITES_PADRAO.tamanhoMb);
    const porEnvio = Math.floor(positivo(limites?.porEnvio, LIMITES_PADRAO.porEnvio));
    const nomeDosTipos = rotuloDosTipos(tipos);
    const avisos = [];

    const foraDoFormato = lista.filter((a) => !tipos.includes(tipoDoArquivo(a)));
    const noFormato = lista.filter((a) => tipos.includes(tipoDoArquivo(a)));
    // Sem tamanho conhecido segue (o servidor confere).
    const grandes = noFormato.filter((a) => Number(a?.size) > mb * 1024 * 1024);
    const servem = noFormato.filter((a) => !(Number(a?.size) > mb * 1024 * 1024));

    if (foraDoFormato.length === 1) avisos.push(`${entreAspas(foraDoFormato)} não é ${nomeDosTipos}.`);
    else if (foraDoFormato.length > 1) avisos.push(`${foraDoFormato.length} arquivos não são ${nomeDosTipos} (${entreAspas(foraDoFormato)}).`);
    if (grandes.length === 1) avisos.push(`${entreAspas(grandes)} tem ${emMb(grandes[0].size)}; o limite é ${mb} MB por foto.`);
    else if (grandes.length > 1) avisos.push(`${grandes.length} fotos passam de ${mb} MB (${entreAspas(grandes)}). Escolha fotos menores.`);
    if (!servem.length) return { arquivos: [], avisos };

    const onde = ondeDaCor(cor, quantasCores);
    const cabem = cabemNaCor(cor);
    if (servem.length > cabem) {
        avisos.push(cabem === 0
            ? `Já há ${cor.maximo} fotos ${onde}, o máximo. Exclua alguma para enviar outra.`
            : `Cabem mais ${cabem} ${cabem === 1 ? 'foto' : 'fotos'} ${onde}. Escolha menos e envie de novo.`);

        return { arquivos: [], avisos };
    }
    if (servem.length > porEnvio) {
        avisos.push(`Envie até ${porEnvio} fotos por vez.`);

        return { arquivos: [], avisos };
    }

    return { arquivos: servem, avisos };
}

// ─── O envio e as respostas ─────────────────────────────────────────────────

/** "3 fotos enviadas." / "1 foto enviada." */
export const textoDoEnvio = (n) => (Number(n) === 1 ? '1 foto enviada.' : `${Number(n) || 0} fotos enviadas.`);

/** O andamento: "Enviando 3 fotos…" enquanto sobe; "Guardando 3 fotos…" quando os bytes já foram (o servidor confere). */
export function textoDoAndamento(n, percentual = null) {
    const quantas = Number(n) === 1 ? '1 foto' : `${Number(n) || 0} fotos`;

    return percentual != null && percentual >= 100 ? `Guardando ${quantas}…` : `Enviando ${quantas}…`;
}

/** O percentual enviado (0 a 100) do evento de progresso do axios; sem o total, null (andamento sem número). */
export function percentualDoProgresso(evento) {
    const total = Number(evento?.total);
    let fracao = null;
    if (total > 0) fracao = Number(evento?.loaded) / total;
    else if (Number.isFinite(Number(evento?.progress)) && evento?.progress !== null) fracao = Number(evento.progress);
    if (fracao == null || !Number.isFinite(fracao)) return null;

    return Math.min(100, Math.max(0, Math.round(fracao * 100)));
}

/** Os avisos que vêm com o quadro novo do envio (`avisos`: texto, ou `{mensagem}`), sem repetir. */
export function avisosDaResposta(dados) {
    const lista = Array.isArray(dados?.avisos) ? dados.avisos : [];

    return [...new Set(lista.map((a) => texto(typeof a === 'string' ? a : a?.mensagem ?? a?.texto)).filter(Boolean))];
}

// O idioma do Laravel nesta instalação é o inglês: as mensagens padrão do framework chegam em inglês e não vão para a
// tela. CÓPIA da régua de `lib/trilhaEscrita.js` (esta lib não importa nada) — um teste confere que as duas são iguais.
const DO_FRAMEWORK = /^(?:not found|forbidden|server error|unauthenticated|page expired|too many (?:attempts|requests)|this action is unauthorized|csrf token mismatch|the given data was invalid|no query results)\b|^the .+ (?:field|must|may|is)\b/i;

const doServidor = (m) => (typeof m === 'string' && m.trim() && !DO_FRAMEWORK.test(m.trim()) ? m.trim() : '');

/** `imagens` primeiro, depois `imagens.0`, `imagens.1`… na ordem dos números, depois o resto. */
function ordemDosCampos(a, b) {
    const peso = (c) => (c === 'imagens' ? -1 : 0);
    if (peso(a) !== peso(b)) return peso(a) - peso(b);

    return a.localeCompare(b, 'pt-BR', { numeric: true });
}

/**
 * O que a caixa faz com o erro de um pedido (envio, exclusão ou a leitura do quadro):
 * - `leitura` (409): o produto andou ou saiu da Lista — a caixa vira só leitura com a mensagem do servidor;
 * - `sumiu` (404): a foto ou a cor já não existe — a caixa relê o quadro;
 * - `erro`: as mensagens do 422 (`errors.imagens` e `errors["imagens.N"]`, o 413 do servidor já vem assim), o 413 cru
 *   (o corte antes do PHP), e os textos de sempre por código.
 *
 * @param {{carga?: boolean}} [opcoes]  `carga`: o erro foi ao ler o quadro (o 404 aí é "não disponível").
 * @returns {{tipo: 'leitura'|'sumiu'|'erro', mensagens: string[]}}
 */
export function erroDasFotos(erro, { carga = false } = {}) {
    const resposta = erro?.response;
    if (!resposta) return { tipo: 'erro', mensagens: [erro?.isAxiosError || erro?.request ? MSG_SEM_CONEXAO : MSG_PADRAO] };
    const { status, data } = resposta;

    if (status === 409) return { tipo: 'leitura', mensagens: [doServidor(data?.message) || MOTIVO_PADRAO] };
    if (status === 404) return carga ? { tipo: 'erro', mensagens: [MSG_SEM_QUADRO] } : { tipo: 'sumiu', mensagens: [MSG_SUMIU] };
    if (status === 413) return { tipo: 'erro', mensagens: [MSG_GRANDE_DEMAIS] };
    if (status === 422) {
        const erros = data?.errors && typeof data.errors === 'object' ? data.errors : {};
        const mensagens = Object.keys(erros).sort(ordemDosCampos).flatMap((campo) => [erros[campo]].flat()).map(doServidor).filter(Boolean);
        const unicas = [...new Set(mensagens)];

        return { tipo: 'erro', mensagens: unicas.length ? unicas : [doServidor(data?.message) || MSG_CONFIRA] };
    }
    if (status === 419) return { tipo: 'erro', mensagens: ['A sessão expirou. Recarregue a página e tente de novo.'] };
    if (status === 401) return { tipo: 'erro', mensagens: ['Sua sessão terminou. Entre de novo para continuar.'] };
    if (status === 403) return { tipo: 'erro', mensagens: [doServidor(data?.message) || 'Você não tem acesso a esta ação.'] };
    if (status === 429) return { tipo: 'erro', mensagens: ['Muitos envios seguidos. Espere um minuto e tente de novo.'] };
    if (status >= 500) return { tipo: 'erro', mensagens: ['O servidor falhou. Tente de novo em instantes.'] };

    return { tipo: 'erro', mensagens: [doServidor(data?.message) || MSG_PADRAO] };
}

// ─── Depois de cada resposta (o que o hook da caixa aplica) ─────────────────

export const MSG_EXCLUIDA = 'Foto excluída.';

/**
 * O envio deu certo: o quadro novo (null = a resposta não é um quadro, a caixa relê) e o que a cor diz — os recusados
 * na conferência de antes, os `avisos` do servidor e "N fotos enviadas.".
 */
export function depoisDoEnvio(dados, quantas, recusados = []) {
    return { quadro: lerQuadro(dados), mensagens: { erros: [...recusados], avisos: avisosDaResposta(dados), status: textoDoEnvio(quantas) } };
}

/** A exclusão deu certo: o quadro novo (null = relê) e "Foto excluída.". */
export function depoisDaExclusao(dados) {
    return { quadro: lerQuadro(dados), mensagens: { erros: [], avisos: [], status: MSG_EXCLUIDA } };
}

/**
 * O envio ou a exclusão deu errado. → o quadro que vale (o 409 o põe só leitura com a mensagem do servidor), o que a
 * cor diz, se a caixa relê o quadro (404: a foto ou a cor sumiu) e se a trilha recarrega (409: o produto andou).
 */
export function depoisDaFalha(quadro, erro, recusados = []) {
    const falha = erroDasFotos(erro);
    if (falha.tipo === 'leitura') {
        return { falha, quadro: quadroSoLeitura(quadro, falha.mensagens[0]), mensagens: { erros: [...recusados] }, reler: false, recarregarTrilha: true };
    }

    return { falha, quadro, mensagens: { erros: [...recusados, ...falha.mensagens] }, reler: falha.tipo === 'sumiu', recarregarTrilha: false };
}
