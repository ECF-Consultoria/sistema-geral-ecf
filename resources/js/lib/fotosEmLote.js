// ═══════════════════════════════════════════════════════════════════════
// Fotos em lote pelo nome do arquivo (09/10/2026): `Ref_número.jpg`.
//
// Quem decide em que variação cada foto entra é o SERVIDOR (a prévia recebe só
// os nomes e devolve o plano; o envio confere de novo). Aqui mora só o que a
// janela faz sem tocar na rede, para ser testado de verdade: tirar nome
// repetido, montar a fila na ordem do plano, cortar em remessas pequenas (o
// `post_max_size` do servidor) e enviar remessa por remessa, esperando quando o
// servidor pede calma (429).
//
// Sem React e sem axios: a janela injeta `enviar` (o POST de uma remessa,
// devolve `data`), `esperar` e `aoProgresso`; o teste roda o laço de verdade.
// ═══════════════════════════════════════════════════════════════════════

/** Fotos por remessa e tamanho da remessa: abaixo do `post_max_size` comum (o servidor aceita até 20). */
export const MAX_ARQUIVOS_POR_REMESSA = 8;
export const MAX_BYTES_POR_REMESSA = 8 * 1024 * 1024;

/** Quando o servidor responde 429: quanto esperar (se ele não disser) e quantas vezes tentar a mesma remessa. */
export const ESPERA_429_MS = 20000;
export const TENTATIVAS_429 = 3;

const chave = (nome) => String(nome ?? '').trim().toLowerCase();

/** Tira nome repetido da escolha (vale o primeiro): o servidor casa a foto pelo nome. */
export function semRepetidos(arquivos) {
    const unicos = [];
    const repetidos = [];
    const vistos = new Set();
    Array.from(arquivos ?? []).forEach((a) => {
        const k = chave(a?.name);
        if (! k) return;
        if (vistos.has(k)) { repetidos.push(a); return; }
        vistos.add(k);
        unicos.push(a);
    });

    return { unicos, repetidos };
}

/**
 * A fila de envio na ordem da prévia (variação por variação, pelo número da foto), só com o que
 * entra. O que a prévia mandou e a pessoa não escolheu (não deveria acontecer) é ignorado.
 */
export function filaDeEnvio(previa, arquivos) {
    const porNome = new Map(Array.from(arquivos ?? []).map((a) => [chave(a.name), a]));
    const fila = [];
    (previa?.variacoes ?? []).forEach((v) => {
        (v.arquivos ?? []).filter((a) => a.entra).forEach((a) => {
            const arquivo = porNome.get(chave(a.nome));
            if (arquivo) fila.push(arquivo);
        });
    });

    return fila;
}

/** Remessas em sequência: até `maxArquivos` e até `maxBytes` cada; um arquivo maior que o teto vai sozinho. */
export function remessas(arquivos, { maxArquivos = MAX_ARQUIVOS_POR_REMESSA, maxBytes = MAX_BYTES_POR_REMESSA } = {}) {
    const saida = [];
    let atual = [];
    let bytes = 0;
    Array.from(arquivos ?? []).forEach((a) => {
        const tamanho = Number(a?.size) || 0;
        if (atual.length > 0 && (atual.length >= maxArquivos || bytes + tamanho > maxBytes)) {
            saida.push(atual);
            atual = [];
            bytes = 0;
        }
        atual.push(a);
        bytes += tamanho;
    });
    if (atual.length > 0) saida.push(atual);

    return saida;
}

/** Quanto esperar num 429: o `Retry-After` do servidor (segundos), ou o padrão. */
export function esperaDo429(erro, padrao = ESPERA_429_MS) {
    const segundos = Number(erro?.response?.headers?.['retry-after']);

    return Number.isFinite(segundos) && segundos > 0 ? Math.min(segundos, 120) * 1000 : padrao;
}

/**
 * Envia as remessas uma a uma. 429: espera e tenta a MESMA remessa de novo (até `tentativas`).
 * Outro erro: os arquivos da remessa voltam como "fora", com o aviso do erro, e o laço segue.
 * `vivo()` falso (a pessoa parou) encerra antes da remessa seguinte.
 *
 * @returns {Promise<{ resultados: Array<{ nome: string, situacao: string, motivo: ?string, ref: ?string }>, enviadas: number, parou: boolean }>}
 */
export async function enviarEmRemessas(lista, { enviar, avisoDoErro = () => 'Não foi possível enviar agora. Tente de novo.', aoProgresso = () => {},
    esperar = (ms) => new Promise((r) => setTimeout(r, ms)), vivo = () => true, tentativas = TENTATIVAS_429 }) {
    const resultados = [];
    let enviadas = 0;
    let feitas = 0;
    const total = lista.reduce((s, r) => s + r.length, 0);

    for (const remessa of lista) {
        if (! vivo()) return { resultados, enviadas, parou: true };
        let tentativa = 0;
        for (;;) {
            try {
                const data = await enviar(remessa);
                resultados.push(...(data?.resultados ?? []));
                enviadas += Number(data?.enviadas) || 0;
                break;
            } catch (e) {
                if (e?.response?.status === 429 && tentativa < tentativas) {
                    tentativa++;
                    aoProgresso({ feitas, total, esperando: true });
                    await esperar(esperaDo429(e));
                    if (! vivo()) return { resultados, enviadas, parou: true };
                    continue;
                }
                const motivo = avisoDoErro(e);
                remessa.forEach((a) => resultados.push({ nome: a.name, situacao: 'fora', motivo, ref: null }));
                break;
            }
        }
        feitas += remessa.length;
        aoProgresso({ feitas, total, esperando: false });
    }

    return { resultados, enviadas, parou: false };
}

/** O resumo do fim: quantas entraram, em quantas variações, e as que ficaram de fora. */
export function resumoDoEnvio(resultados = []) {
    const enviadas = resultados.filter((r) => r.situacao === 'enviada');
    const fora = resultados.filter((r) => r.situacao !== 'enviada');

    return { enviadas: enviadas.length, variacoes: new Set(enviadas.map((r) => chave(r.ref))).size, fora };
}

const plural = (n, um, varios) => (n === 1 ? `1 ${um}` : `${n} ${varios}`);

/** "24 fotos enviadas para 8 variações. 3 não entraram." */
export function textoDoResumo({ enviadas, variacoes, fora }) {
    const partes = [enviadas === 0
        ? 'Nenhuma foto foi enviada.'
        : `${plural(enviadas, 'foto enviada', 'fotos enviadas')} para ${plural(variacoes, 'variação', 'variações')}.`];
    if (fora.length > 0) partes.push(fora.length === 1 ? '1 não entrou.' : `${fora.length} não entraram.`);

    return partes.join(' ');
}
