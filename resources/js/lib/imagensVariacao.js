// ─── Fotos de cada variação do produto: as contas da galeria ─────────────────
//
// Tudo que a galeria decide SEM tocar na rede mora aqui, para ser testado de
// verdade: se pode enviar, quais arquivos servem, como o envio é montado, o que
// responder a cada erro do servidor e como a lista muda ao reordenar. O
// componente `GaleriaVariacao` só liga isto aos botões e ao axios.

/** Teto do servidor por variação (config `estrutura_produtos.imagens.max_por_variacao`). */
export const LIMITE_IMAGENS = 12;

/** Formatos aceitos (o servidor confere de novo). */
export const EXTENSOES_ACEITAS = ['jpg', 'jpeg', 'png', 'webp'];
export const ACEITA_NO_INPUT = '.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp';

export const AVISO_SEM_SALVAR = 'Salve o produto para enviar as imagens desta variação.';
export const AVISO_GRANDE_DEMAIS = 'A imagem é grande demais para enviar; tente uma menor.';
export const AVISO_FALHA_GERAL = 'Não foi possível concluir agora. Tente de novo.';

const extensaoDe = (nome) => {
    const t = String(nome ?? '');
    const i = t.lastIndexOf('.');

    return i < 0 ? '' : t.slice(i + 1).toLowerCase();
};

/** Quantas imagens ainda cabem na variação (nunca negativo). */
export function quantasCabem(total, limite = LIMITE_IMAGENS) {
    return Math.max(0, limite - (Number(total) || 0));
}

/**
 * Pode enviar agora? Devolve { pode, motivo }: o motivo é o texto que a tela mostra quando não pode.
 * Variação sem id (produto novo ou "Nova variação" ainda não gravada) não envia: o servidor
 * só conhece a variação depois do "Salvar produto".
 */
export function decidirEnvio({ variacaoId, total = 0, enviando = false, ocupado = false, limite = LIMITE_IMAGENS }) {
    if (! variacaoId) return { pode: false, motivo: AVISO_SEM_SALVAR };
    if (enviando || ocupado) return { pode: false, motivo: null };
    if (quantasCabem(total, limite) === 0) return { pode: false, motivo: `Esta variação já tem as ${limite} imagens possíveis. Exclua alguma para enviar outra.` };

    return { pode: true, motivo: null };
}

/**
 * Separa o que a pessoa escolheu: formatos aceitos × recusados (pelo nome; o servidor confere o conteúdo).
 * @param {Array<{name: string}>|FileList} arquivos
 */
export function separarArquivos(arquivos) {
    const aceitos = [];
    const recusados = [];
    Array.from(arquivos ?? []).forEach((a) => {
        (EXTENSOES_ACEITAS.includes(extensaoDe(a?.name)) ? aceitos : recusados).push(a);
    });

    return { aceitos, recusados };
}

/**
 * Confere a remessa antes de enviar. O servidor é "tudo ou nada": se não cabe, nada entra;
 * a tela avisa antes, com quantas ainda cabem. Devolve { arquivos, avisos } — `arquivos` vazio = não envia.
 */
export function prepararRemessa(escolhidos, total, limite = LIMITE_IMAGENS) {
    const { aceitos, recusados } = separarArquivos(escolhidos);
    const avisos = [];
    if (recusados.length) {
        avisos.push(recusados.length === 1
            ? `"${recusados[0].name}" não está num formato aceito. Envie JPG, PNG ou WebP.`
            : `${recusados.length} arquivos não estão num formato aceito. Envie JPG, PNG ou WebP.`);
    }
    if (aceitos.length === 0) return { arquivos: [], avisos };

    const cabem = quantasCabem(total, limite);
    if (aceitos.length > cabem) {
        avisos.push(cabem === 0
            ? `Esta variação já tem as ${limite} imagens possíveis.`
            : `Cabem mais ${cabem} ${cabem === 1 ? 'imagem' : 'imagens'} nesta variação. Escolha menos e envie de novo.`);

        return { arquivos: [], avisos };
    }

    return { arquivos: aceitos, avisos };
}

/** O corpo multipart do envio: um campo `imagens[]` por arquivo, na ordem escolhida. */
export function montarEnvio(arquivos) {
    const corpo = new FormData();
    Array.from(arquivos ?? []).forEach((a) => corpo.append('imagens[]', a));

    return corpo;
}

/** A galeria que o servidor devolveu (ou null se a resposta não trouxe uma lista). */
export function imagensDaResposta(data) {
    return Array.isArray(data?.imagens) ? data.imagens : null;
}

const comoLista = (v) => (Array.isArray(v) ? v : (v == null ? [] : [v])).map(String).filter((t) => t.trim() !== '');

/**
 * Erro de rede/servidor → lista de avisos legíveis, sem repetição. Entende o 422 do servidor
 * (`errors.imagens` e `errors['imagens.N']`), o 413 (corpo grande demais, quando o servidor corta antes),
 * o 429 (muitas tentativas) e a queda de conexão.
 */
export function avisosDoErro(erro) {
    const r = erro?.response;
    if (! r) return ['Sem conexão com o servidor. Confira a internet e tente de novo.'];
    if (r.status === 413) return [AVISO_GRANDE_DEMAIS];
    if (r.status === 429) return ['Muitas tentativas seguidas. Espere um instante e tente de novo.'];
    if (r.status === 419) return ['Sua sessão expirou. Atualize a página e entre de novo.'];
    if (r.status === 401 || r.status === 403) return ['Você não tem acesso a esta ação.'];
    if (r.status === 404) return ['Esta imagem não existe mais. Atualize a página.'];

    const erros = r.data?.errors;
    if (r.status === 422 && erros && typeof erros === 'object') {
        const chaves = Object.keys(erros).sort((a, b) => (a === 'imagens' ? -1 : (b === 'imagens' ? 1 : a.localeCompare(b, 'pt-BR', { numeric: true }))));
        const todas = chaves.flatMap((c) => comoLista(erros[c]));
        const unicas = [...new Set(todas)];
        if (unicas.length) return unicas;
    }
    if (typeof r.data?.message === 'string' && r.data.message.trim() !== '' && r.status === 422) return [r.data.message];

    return [AVISO_FALHA_GERAL];
}

// ─── Ordem ───────────────────────────────────────────────────────────────────

/** Os ids na ordem em que aparecem: é o corpo do PUT de ordem. */
export const ordemDeIds = (imagens) => (imagens ?? []).map((i) => i.id);

/** Reenumera `ordem` e `capa` (a 1ª é a capa) — o mesmo que o servidor faz ao responder. */
export const renumerar = (imagens) => (imagens ?? []).map((img, i) => ({ ...img, ordem: i, capa: i === 0 }));

/** Move a imagem `id` para a posição `para` (0 = capa). Id ou posição inválidos devolvem a lista como está. */
export function moverImagem(imagens, id, para) {
    const lista = imagens ?? [];
    const de = lista.findIndex((i) => i.id === id);
    if (de < 0 || ! Number.isInteger(para)) return lista;
    const alvo = Math.min(Math.max(para, 0), lista.length - 1);
    if (alvo === de) return lista;
    const copia = lista.slice();
    const [tirada] = copia.splice(de, 1);
    copia.splice(alvo, 0, tirada);

    return renumerar(copia);
}

/** Aplica a nova ordem arrastando `idArrastado` para a posição em que está `idAlvo`. */
export function soltarSobre(imagens, idArrastado, idAlvo) {
    const para = (imagens ?? []).findIndex((i) => i.id === idAlvo);

    return para < 0 ? (imagens ?? []) : moverImagem(imagens, idArrastado, para);
}

/** Houve mudança de ordem? (evita PUT à toa quando se solta no mesmo lugar). */
export const mudouAOrdem = (antes, depois) => JSON.stringify(ordemDeIds(antes)) !== JSON.stringify(ordemDeIds(depois));

/** Troca as imagens de UMA variação (pela chave da tela) sem tocar nas outras. Não marca nada como alterado. */
export function aplicarImagens(variacoes, chave, imagens) {
    return (variacoes ?? []).map((v) => (v._k === chave ? { ...v, imagens } : v));
}

/** Rascunho recuperado: as imagens valem as de agora (o servidor é a verdade), não as do dia em que o rascunho foi gravado. */
export function manterImagensAtuais(doRascunho, atuais) {
    const porId = new Map((atuais ?? []).filter((v) => v.id).map((v) => [v.id, v.imagens ?? []]));

    return (doRascunho ?? []).map((v) => ({ ...v, imagens: v.id ? (porId.get(v.id) ?? []) : [] }));
}
